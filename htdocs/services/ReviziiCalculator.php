<?php
declare(strict_types=1);

/**
 * Motorul de calcul pentru Revizii → Schimb ulei (pur: fara BD, fara API).
 *
 * Sursa: segmentele travelsheet SasFleet persistate (revizii_sas_segmente).
 * Un segment SAS = o perioada cu contactul pornit:
 *   - distance           km parcursi in segment;
 *   - workSeconds        durata segmentului (dateEnd - dateStart), INCLUDE condusul;
 *   - idleEngineSeconds  timpul cu motorul pornit si vehiculul pe loc.
 *
 * Regula de business: 1 ora motor eligibila = factor km (implicit 40). Sursa orelor
 * eligibile e o setare explicita (revizii_setari.sursa_ore_motor):
 *   - ore_aspirare (implicit): regula existenta din Dispecer curse — orele de aspirare
 *     (compresor / PTO) introduse pe cursa sau pe fazele ei;
 *   - sas_pe_loc: idleEngineSeconds din SasFleet (motor pornit, vehicul pe loc).
 * In niciun caz workSeconds (include condusul, deja reprezentat de km parcursi).

 * Telemetria lipsa NU inseamna 0 km: zilele fara acoperire sunt raportate separat,
 * iar valorile calculate devin "minime" (date incomplete).
 */
final class ReviziiCalculator
{
    public const STATUS_OK = 'in_regula';
    public const STATUS_SOON = 'se_apropie';
    public const STATUS_DUE = 'necesita';
    public const STATUS_NO_DATA = 'fara_date';
    public const STATUS_NOT_CONFIGURED = 'neconfigurat';

    /** Viteza medie peste care un segment e considerat eroare GPS (km/h). */
    private const MAX_PLAUSIBLE_SPEED_KMH = 150.0;
    /** Un singur segment peste aceasta distanta e considerat eroare de contor. */
    private const MAX_SEGMENT_KM = 2000.0;

    /**
     * Valideaza si deduplica segmentele unui vehicul. Segmentele care se suprapun
     * in timp (ex. acelasi segment intors de doua ferestre de interogare) sunt
     * taiate proportional, astfel incat niciun interval de timp nu e numarat de doua ori.
     *
     * @param array<int, array{start:int, end:int, km:float, idle:float}> $segments
     * @return array{segments: array<int, array{start:int, end:int, km:float, idle:float}>, rejected:int}
     */
    public static function normalizeSegments(array $segments): array
    {
        usort($segments, static fn (array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);

        $clean = [];
        $rejected = 0;
        $cursor = PHP_INT_MIN;
        foreach ($segments as $segment) {
            $start = (int) $segment['start'];
            $end = max($start, (int) $segment['end']);
            $km = (float) $segment['km'];
            $duration = $end - $start;
            $idle = max(0.0, min((float) $segment['idle'], (float) $duration));

            if ($km < 0 || $km > self::MAX_SEGMENT_KM
                || ($duration > 0 && $km / ($duration / 3600) > self::MAX_PLAUSIBLE_SPEED_KMH && $km > 5)
                || ($duration === 0 && $km > 1)
            ) {
                $rejected++;
                continue;
            }

            if ($duration === 0) {
                if ($start >= $cursor) {
                    $clean[] = ['start' => $start, 'end' => $end, 'km' => $km, 'idle' => 0.0];
                }
                continue;
            }
            if ($end <= $cursor) {
                continue; // complet acoperit de un segment deja numarat
            }
            if ($start < $cursor) {
                $fraction = ($end - $cursor) / $duration;
                $km *= $fraction;
                $idle *= $fraction;
                $start = $cursor;
            }
            $clean[] = ['start' => $start, 'end' => $end, 'km' => $km, 'idle' => $idle];
            $cursor = max($cursor, $end);
        }

        return ['segments' => $clean, 'rejected' => $rejected];
    }

    /**
     * Km si secunde motor eligibile din segmente, in ferestrele date (proportional
     * cu suprapunerea in timp). Pentru semi-remorca $includeIdle = false: orele
     * motor apartin capului tractor si nu se transfera niciodata remorcii.
     *
     * @param array<int, array{start:int, end:int, km:float, idle:float}> $segments normalizate
     * @param array<int, array{0:int, 1:int}> $windows
     * engine_seconds = timpul total cu motorul pornit (durata segmentelor, INCLUSIV condusul) —
     * informativ; in conversie intra doar idle_seconds.
     *
     * @return array{km: float, idle_seconds: float, engine_seconds: float}
     */
    public static function usage(array $segments, array $windows, bool $includeIdle): array
    {
        $km = 0.0;
        $idle = 0.0;
        $engine = 0.0;
        foreach ($windows as [$from, $to]) {
            if ($to <= $from) {
                continue;
            }
            foreach ($segments as $segment) {
                $duration = $segment['end'] - $segment['start'];
                if ($duration <= 0) {
                    $fraction = ($segment['start'] >= $from && $segment['start'] < $to) ? 1.0 : 0.0;
                } else {
                    $overlap = min($segment['end'], $to) - max($segment['start'], $from);
                    $fraction = $overlap > 0 ? $overlap / $duration : 0.0;
                }
                if ($fraction <= 0) {
                    continue;
                }
                $km += $segment['km'] * $fraction;
                if ($includeIdle) {
                    $idle += $segment['idle'] * $fraction;
                    $engine += max(0, $duration) * $fraction;
                }
            }
        }

        return ['km' => $km, 'idle_seconds' => $idle, 'engine_seconds' => $engine];
    }

    /**
     * Orele eligibile din evenimente cu interval (ex. ore aspirare pe o cursa / faza),
     * proportional cu suprapunerea fiecarui eveniment cu ferestrele.
     *
     * @param array<int, array{start:int, end:int, hours:float}> $events
     * @param array<int, array{0:int, 1:int}> $windows
     */
    public static function eventHours(array $events, array $windows): float
    {
        $hours = 0.0;
        foreach ($windows as [$from, $to]) {
            foreach ($events as $event) {
                $duration = $event['end'] - $event['start'];
                if ($duration <= 0) {
                    $fraction = ($event['start'] >= $from && $event['start'] < $to) ? 1.0 : 0.0;
                } else {
                    $overlap = min($event['end'], $to) - max($event['start'], $from);
                    $fraction = $overlap > 0 ? $overlap / $duration : 0.0;
                }
                $hours += max(0.0, (float) $event['hours']) * $fraction;
            }
        }

        return $hours;
    }

    /**
     * Zilele (Y-m-d) din ferestre pentru care nu exista o sincronizare reusita.
     *
     * @param array<int, array{0:int, 1:int}> $windows
     * @param array<string, bool> $okDays
     * @return array<int, string>
     */
    public static function missingDays(array $windows, array $okDays): array
    {
        $missing = [];
        foreach ($windows as [$from, $to]) {
            if ($to <= $from) {
                continue;
            }
            $day = strtotime(date('Y-m-d', $from) . ' 00:00:00');
            $lastDay = date('Y-m-d', $to - 1);
            while ($day !== false) {
                $key = date('Y-m-d', $day);
                if ($key > $lastDay) {
                    break;
                }
                if (empty($okDays[$key])) {
                    $missing[$key] = true;
                }
                $day = strtotime($key . ' +1 day');
            }
        }
        ksort($missing);

        return array_keys($missing);
    }

    /**
     * Rezultatul final pentru o componenta.
     *
     * @return array<string, mixed>
     */
    public static function evaluate(
        int $intervalKm,
        float $initialKm,
        float $drivenKm,
        float $engineHours,
        float $factorKmPerHour,
        int $warnKm,
        bool $hasSource
    ): array {
        $kmFromHours = $engineHours * $factorKmPerHour;
        $total = $initialKm + $drivenKm + $kmFromHours;
        $remaining = $intervalKm - $total;
        $progress = $intervalKm > 0 ? ($total / $intervalKm) * 100 : 0.0;

        if ($intervalKm <= 0) {
            $status = self::STATUS_NOT_CONFIGURED;
        } elseif (!$hasSource) {
            // Fara sursa de telemetrie: doar o depasire deja certa se poate afirma.
            $status = $remaining <= 0 ? self::STATUS_DUE : self::STATUS_NO_DATA;
        } else {
            $status = self::statusFor($intervalKm, $remaining, $warnKm);
        }

        return [
            'interval_km' => $intervalKm,
            'consum_initial_km' => round($initialKm, 1),
            'km_efectuati' => round($drivenKm, 1),
            'ore_motor' => round($engineHours, 2),
            'km_din_ore' => round($kmFromHours, 1),
            'km_total' => round($total, 1),
            'km_ramasi' => round($remaining, 1),
            'progres' => round($progress, 1),
            'status' => $status,
        ];
    }

    /** "Se apropie" cand km ramasi scad sub pragul de notificare (km) al componentei. */
    public static function statusFor(int $intervalKm, float $remaining, int $warnKm): string
    {
        if ($intervalKm <= 0) {
            return self::STATUS_NOT_CONFIGURED;
        }
        if ($remaining <= 0) {
            return self::STATUS_DUE;
        }
        if ($remaining < $warnKm) {
            return self::STATUS_SOON;
        }

        return self::STATUS_OK;
    }
}
