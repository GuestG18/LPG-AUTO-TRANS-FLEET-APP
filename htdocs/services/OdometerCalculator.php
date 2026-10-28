<?php
declare(strict_types=1);

/**
 * Centru Kilometraj — regulile de calcul, fara baza de date si fara API
 * (testate in scripts/test_centru_kilometraj.php cu date simulate).
 *
 * Cele doua surse NU sunt interschimbabile si nu se aduna niciodata:
 *   - SAS Fleet  = odometrul GPS (kmIndex) la sfarsitul fiecarui segment de mers.
 *                  Nu e calibrat pe bord (valoarea absoluta difera de bord), dar
 *                  diferentele intre doua citiri sunt km reali GPS.
 *   - CardOil    = odometrul tastat de sofer la pompa, la momentul alimentarii.
 *                  Citire pe eveniment: intre alimentari nu exista nicio valoare.
 *
 * Fiecare citire se pastreaza cu momentul ei real. Nu se interpoleaza si nu se
 * inventeaza nicio valoare intermediara.
 */
final class OdometerCalculator
{
    public const PROVIDERS = ['sas', 'cardoil'];

    public const STATUS_OK = 'ok';
    public const STATUS_UNAVAILABLE = 'indisponibil';
    public const STATUS_OUT_OF_TOLERANCE = 'in_afara_tolerantei';
    public const STATUS_NO_SOURCE = 'fara_sursa';

    /** Viteza medie peste care doua citiri consecutive nu mai sunt plauzibile (km/h). */
    private const MAX_PLAUSIBLE_KMH = 95.0;
    /** Marja fixa adaugata la plafon (rotunjiri, citiri la cateva minute distanta). */
    private const PLAUSIBLE_MARGIN_KM = 60.0;

    /** Diferenta SAS − CardOil peste care se ridica o anomalie: max(prag km, prag %). */
    private const DIFF_ALERT_KM = 100.0;
    private const DIFF_ALERT_PCT = 5.0;

    /**
     * Citirea SAS valabila la momentul $at.
     *
     * Ultima citire cu measured_at <= $at. Ea este EXACTA daca vehiculul a stat
     * parcat de la citire pana la $at (urmatorul segment a pornit dupa $at, sau
     * datele SAS acopera $at fara alt segment); altfel vehiculul era in mers si
     * citirea se accepta doar in toleranta (implicit 15 min).
     *
     * @param list<array{id:string, measured_at:string, segment_started_at:?string, km:float}> $readings sortate crescator, doar valide
     * @param string|null $coverageEnd pana unde au fost cerute datele SAS (fara segmente ulterioare = parcat)
     * @param list<array{start:string, end:string, km:float}>|null $movements toate deplasarile din foaia de parcurs
     *        (si cele fara kmIndex); cand lipsesc, „parcat” se deduce din inceputul citirii urmatoare
     * @return array{status:string, reading:?array, precision:?string, note:string}
     */
    public static function sasReadingAt(array $readings, string $at, int $toleranceMinutes, ?string $coverageEnd, ?array $movements = null): array
    {
        $atTs = self::ts($at);
        $previous = null;
        $next = null;
        foreach ($readings as $reading) {
            if (self::ts($reading['measured_at']) <= $atTs) {
                $previous = $reading;
            } elseif ($next === null) {
                $next = $reading;
            }
        }

        if ($previous === null) {
            return [
                'status' => self::STATUS_UNAVAILABLE,
                'reading' => null,
                'precision' => null,
                'note' => 'SAS nu are nicio citire de odometru înainte de T0 în perioada interogată.',
            ];
        }

        $ageMinutes = (int) floor(($atTs - self::ts($previous['measured_at'])) / 60);
        $covered = $coverageEnd !== null && self::ts($coverageEnd) >= $atTs;
        if ($movements !== null) {
            // Parcat = nicio deplasare care sa se suprapuna cu (citire, T0].
            $readTs = self::ts($previous['measured_at']);
            $parked = $covered;
            foreach ($movements as $movement) {
                if (self::ts($movement['start']) < $atTs && self::ts($movement['end']) > $readTs) {
                    $parked = false;
                    break;
                }
            }
        } else {
            $parked = $next !== null
                ? ($next['segment_started_at'] !== null && self::ts($next['segment_started_at']) >= $atTs)
                : $covered;
        }

        if ($parked) {
            return [
                'status' => self::STATUS_OK,
                'reading' => $previous,
                'precision' => 'parcat',
                'note' => 'Vehiculul a stat parcat de la citire până la T0: odometrul nu s-a schimbat.',
            ];
        }
        if ($ageMinutes <= $toleranceMinutes) {
            return [
                'status' => self::STATUS_OK,
                'reading' => $previous,
                'precision' => 'toleranta',
                'note' => sprintf('Vehiculul era în mers la T0; citire cu %d min înainte (toleranță %d min).', $ageMinutes, $toleranceMinutes),
            ];
        }

        return [
            'status' => self::STATUS_OUT_OF_TOLERANCE,
            'reading' => $previous,
            'precision' => null,
            'note' => sprintf('Vehiculul era în mers la T0, iar ultima citire SAS e cu %s înainte (toleranță %d min). Alege un T0 în care vehiculul stătea parcat.', self::humanMinutes($ageMinutes), $toleranceMinutes),
        ];
    }

    /**
     * Citirea CardOil (alimentarea) valabila ca referinta la $at: ultima alimentare
     * valida (odometru > 0, fara anomalie) la sau inainte de $at. CardOil nu are
     * citiri intre alimentari, deci nu se aplica toleranta de 15 minute: se accepta
     * alimentarea daca nu e mai veche decat $maxAgeHours, si se afiseaza momentul ei real.
     *
     * @param list<array{id:string, measured_at:string, km:float, anomaly?:?string}> $fillups sortate crescator
     * @return array{status:string, reading:?array, precision:?string, note:string}
     */
    public static function cardOilReadingAt(array $fillups, string $at, int $maxAgeHours): array
    {
        $atTs = self::ts($at);
        $previous = null;
        foreach ($fillups as $fillup) {
            if (!empty($fillup['anomaly'])) {
                continue;
            }
            if (self::ts($fillup['measured_at']) <= $atTs) {
                $previous = $fillup;
            }
        }

        if ($previous === null) {
            return [
                'status' => self::STATUS_UNAVAILABLE,
                'reading' => null,
                'precision' => null,
                'note' => 'Nicio alimentare CardOil cu odometru valid înainte de T0.',
            ];
        }

        $ageMinutes = (int) floor(($atTs - self::ts($previous['measured_at'])) / 60);
        if ($ageMinutes > $maxAgeHours * 60) {
            return [
                'status' => self::STATUS_OUT_OF_TOLERANCE,
                'reading' => $previous,
                'precision' => null,
                'note' => sprintf('Ultima alimentare înainte de T0 e cu %s înainte (maxim %d ore): nu stabilește kilometrajul la T0.', self::humanMinutes($ageMinutes), $maxAgeHours),
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'reading' => $previous,
            'precision' => 'eveniment',
            'note' => $ageMinutes <= 1
                ? 'Alimentare chiar la T0.'
                : sprintf('Ultima alimentare înainte de T0, cu %s înainte. Kilometrajul CardOil se măsoară de la această alimentare.', self::humanMinutes($ageMinutes)),
        ];
    }

    /**
     * Citirea manuala (hubodometrul semiremorcii) valabila ca referinta la $at: cea mai
     * apropiata citire valida, inainte SAU dupa $at, la cel mult $maxAgeHours. Operatorul
     * citeste odometrul cand ajunge la semiremorca, nu exact la T0; momentul real ramane afisat.
     *
     * @param list<array{id:string, measured_at:string, km:float, anomaly?:?string}> $readings
     * @return array{status:string, reading:?array, precision:?string, note:string}
     */
    public static function manualReadingAt(array $readings, string $at, int $maxAgeHours): array
    {
        $atTs = self::ts($at);
        $best = null;
        $bestGap = null;
        foreach ($readings as $reading) {
            if (!empty($reading['anomaly'])) {
                continue;
            }
            $gap = abs(self::ts($reading['measured_at']) - $atTs);
            if ($bestGap === null || $gap < $bestGap) {
                $best = $reading;
                $bestGap = $gap;
            }
        }
        if ($best === null) {
            return ['status' => self::STATUS_UNAVAILABLE, 'reading' => null, 'precision' => null,
                'note' => 'Nicio citire manuală validă.'];
        }

        $minutes = (int) floor($bestGap / 60);
        $side = self::ts($best['measured_at']) <= $atTs ? 'înainte de' : 'după';
        if ($minutes > $maxAgeHours * 60) {
            return ['status' => self::STATUS_OUT_OF_TOLERANCE, 'reading' => $best, 'precision' => null,
                'note' => sprintf('Cea mai apropiată citire manuală e cu %s %s T0 (maxim %d ore). Adaugă o citire făcută aproape de T0.', self::humanMinutes($minutes), $side, $maxAgeHours)];
        }

        return ['status' => self::STATUS_OK, 'reading' => $best, 'precision' => 'eveniment',
            'note' => $minutes <= 1 ? 'Citire manuală chiar la T0.' : sprintf('Citire manuală cu %s %s T0.', self::humanMinutes($minutes), $side)];
    }

    /**
     * Marcheaza citirile neplauzibile dintr-o serie (o singura sursa, un singur vehicul).
     *
     * Seria valida = cel mai lung lant de citiri in care odometrul nu scade si fiecare
     * pas se poate parcurge in timpul scurs (MAX_PLAUSIBLE_KMH + marja). Restul sunt
     * anomalii: greseli de tastare la pompa (prea mari SAU prea mici), valori-santinela
     * (ex. 16777215), resetari. La egalitate se prefera lantul care se termina cel mai
     * recent. Primeste si intoarce randuri cu cheile measured_at / km; adauga 'anomaly'
     * (null sau motivul, raportat la citirea valida dinainte).
     *
     * @param list<array<string, mixed>> $series sortate crescator dupa measured_at
     * @return list<array<string, mixed>>
     */
    public static function flagAnomalies(array $series): array
    {
        $series = array_values($series);
        $count = count($series);
        if ($count === 0) {
            return [];
        }
        $km = [];
        $ts = [];
        foreach ($series as $i => $row) {
            $km[$i] = (float) $row['km'];
            $ts[$i] = self::ts((string) $row['measured_at']);
        }

        $length = array_fill(0, $count, 1);
        $previous = array_fill(0, $count, -1);
        $best = 0;
        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $i; $j++) {
                if ($length[$j] + 1 >= $length[$i] && self::plausibleStep($km[$j], $ts[$j], $km[$i], $ts[$i])) {
                    $length[$i] = $length[$j] + 1;
                    $previous[$i] = $j;
                }
            }
            if ($length[$i] >= $length[$best]) {
                $best = $i;
            }
        }

        $valid = [];
        for ($i = $best; $i >= 0; $i = $previous[$i]) {
            $valid[$i] = true;
        }

        $lastValid = null;
        for ($i = 0; $i < $count; $i++) {
            if (isset($valid[$i])) {
                $series[$i]['anomaly'] = null;
                $lastValid = $i;
                continue;
            }
            if ($lastValid === null) {
                $series[$i]['anomaly'] = 'Citire care nu se potrivește cu seria validă care urmează.';
            } elseif ($km[$i] < $km[$lastValid] - 0.5) {
                $series[$i]['anomaly'] = sprintf('Odometrul scade cu %s km față de citirea validă anterioară.', self::km($km[$lastValid] - $km[$i]));
            } else {
                $series[$i]['anomaly'] = sprintf(
                    'Salt neplauzibil de %s km în %s.',
                    self::km($km[$i] - $km[$lastValid]),
                    self::humanMinutes((int) round(max(0, $ts[$i] - $ts[$lastValid]) / 60))
                );
            }
        }

        return $series;
    }

    public static function plausibleStep(float $fromKm, int $fromTs, float $toKm, int $toTs): bool
    {
        $hours = max(0.0, ($toTs - $fromTs) / 3600);

        return $toKm >= $fromKm - 0.5
            && $toKm <= $fromKm + $hours * self::MAX_PLAUSIBLE_KMH + self::PLAUSIBLE_MARGIN_KM;
    }

    /**
     * Km de la T0 pentru O sursa: citirea curenta − referinta. Doar cand ambele exista
     * si sunt aceeasi masura cumulativa (aceeasi sursa, acelasi vehicul).
     *
     * @return array{km:?float, note:?string, anomaly:?string}
     */
    public static function distanceSinceT0(?array $current, ?array $baseline): array
    {
        if ($baseline === null) {
            return ['km' => null, 'note' => 'Fără referință T0 pentru această sursă.', 'anomaly' => null];
        }
        if ($current === null) {
            return ['km' => null, 'note' => 'Nicio citire curentă pentru această sursă.', 'anomaly' => null];
        }
        if (self::ts($current['measured_at']) < self::ts($baseline['measured_at'])) {
            return ['km' => null, 'note' => 'Citirea curentă e mai veche decât referința T0.', 'anomaly' => null];
        }
        $km = round((float) $current['km'] - (float) $baseline['km'], 1);
        if ($km < 0) {
            return ['km' => null, 'note' => null, 'anomaly' => sprintf('Odometrul curent e cu %s km sub referința T0.', self::km(-$km))];
        }

        return ['km' => $km, 'note' => null, 'anomaly' => null];
    }

    /**
     * Comparatia SAS vs CardOil pe ACEEASI fereastra: CardOil masoara de la
     * alimentarea de referinta la ultima alimentare, deci SAS se ia intre exact
     * aceleasi doua momente. Diagnostic, niciodata km in plus.
     * Procentul are ca numitor distanta SAS (sursa continua).
     *
     * @return array{comparable:bool, diff_km:?float, diff_pct:?float, alert:bool, note:string}
     */
    public static function sourceDifference(?float $sasKmSameWindow, ?float $cardOilKm): array
    {
        if ($sasKmSameWindow === null || $cardOilKm === null) {
            return [
                'comparable' => false,
                'diff_km' => null,
                'diff_pct' => null,
                'alert' => false,
                'note' => 'Necomparabil: lipsește distanța uneia dintre surse pe aceeași fereastră.',
            ];
        }
        $diff = round($sasKmSameWindow - $cardOilKm, 1);
        $pct = $sasKmSameWindow > 0 ? round($diff / $sasKmSameWindow * 100, 1) : null;
        $alert = abs($diff) > self::DIFF_ALERT_KM && ($pct === null || abs($pct) > self::DIFF_ALERT_PCT);

        return [
            'comparable' => true,
            'diff_km' => $diff,
            'diff_pct' => $pct,
            'alert' => $alert,
            'note' => 'SAS − CardOil, între aceleași două alimentări.',
        ];
    }

    /**
     * Km oficiali ai unei componente, dupa politica configurata explicit.
     * Fara politica nu se alege nicio sursa: km raman doar ca diagnostic per sursa.
     *
     * @return array{km:?float, source:?string, reason:?string}
     */
    public static function officialDistance(string $policy, ?float $sasKm, ?float $cardOilKm): array
    {
        return match ($policy) {
            'sas' => $sasKm !== null
                ? ['km' => $sasKm, 'source' => 'sas', 'reason' => null]
                : ['km' => null, 'source' => null, 'reason' => 'Fără distanță SAS validă de la T0.'],
            'cardoil' => $cardOilKm !== null
                ? ['km' => $cardOilKm, 'source' => 'cardoil', 'reason' => null]
                : ['km' => null, 'source' => null, 'reason' => 'Fără distanță CardOil validă de la T0.'],
            'sas_cardoil' => $sasKm !== null
                ? ['km' => $sasKm, 'source' => 'sas', 'reason' => null]
                : ($cardOilKm !== null
                    ? ['km' => $cardOilKm, 'source' => 'cardoil', 'reason' => null]
                    : ['km' => null, 'source' => null, 'reason' => 'Nicio sursă nu are distanță validă de la T0.']),
            default => ['km' => null, 'source' => null, 'reason' => 'Politica sursei oficiale nu este configurată.'],
        };
    }

    /**
     * Starea datelor SAS: "actual" doar daca SAS a fost interogat recent (fara
     * segmente noi = odometrul e inca valabil). O sincronizare veche sau esuata
     * pastreaza ultima citire, dar nu o mai prezinta drept actuala.
     */
    public static function sasFreshness(?string $lastCheckedAt, ?string $lastStatus, int $nowTs, int $maxAgeHours): string
    {
        if ($lastCheckedAt === null) {
            return 'nesincronizat';
        }
        if ($lastStatus === 'eroare') {
            return 'eroare';
        }

        return ($nowTs - self::ts($lastCheckedAt)) <= $maxAgeHours * 3600 ? 'actual' : 'neactualizat';
    }

    public static function ts(string $datetime): int
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            throw new InvalidArgumentException('Dată invalidă: ' . $datetime);
        }

        return $ts;
    }

    public static function humanMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        if ($minutes < 48 * 60) {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;
            return $hours . ' h' . ($rest > 0 ? ' ' . $rest . ' min' : '');
        }

        return intdiv($minutes, 1440) . ' zile';
    }

    private static function km(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
