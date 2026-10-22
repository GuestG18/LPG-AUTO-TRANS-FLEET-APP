<?php
declare(strict_types=1);

/**
 * Motorul de calcul pur (fără BD) pentru „Ajustare tarife combustibil & curs BNR”.
 *
 * Notații (ca în specificație):
 *   F0 / F1 = motorina de referință / curentă, lei/l
 *   E0 / E1 = cursul EUR/RON de referință / curent (medii pe fereastră)
 *   P0 = F0 / E0, P1 = F1 / E1 = motorina în EUR/l
 *
 * Toate procentele se întorc NEROTUNJITE; rotunjirea se face doar la afișare.
 * Comparația cu pragul folosește o toleranță de 1e-9 puncte procentuale, ca un
 * prag exact de ±2,00% să nu fie ratat din cauza reprezentării binare
 * (ex. 9,80/10,00 − 1 = −1,9999999999999973%).
 */
final class FuelFxCalculator
{
    public const THRESHOLD_EPSILON = 1e-9;

    /**
     * Media aritmetică a observațiilor publicate (fără valori inventate pentru
     * weekend / sărbători). Null când nu există observații.
     *
     * @param array<int,float|int|string> $values
     */
    public static function average(array $values): ?float
    {
        $clean = [];
        foreach ($values as $value) {
            if ($value === null || $value === '' || !is_numeric($value)) {
                continue;
            }
            $clean[] = (float) $value;
        }
        if ($clean === []) {
            return null;
        }

        return array_sum($clean) / count($clean);
    }

    /**
     * Fereastra glisantă de N luni calendaristice, inclusiv capetele:
     * 21.10.2026, 3 luni → 21.07.2026 … 21.10.2026. Ziua se limitează la
     * ultima zi a lunii (31.05 − 3 luni → 28/29.02), fără depășire în luna următoare.
     *
     * @return array{start:string, end:string}
     */
    public static function rollingWindow(string $endDate, int $months = 3): array
    {
        $end = new DateTimeImmutable($endDate);
        $months = max(1, $months);
        $firstOfTarget = $end->modify('first day of this month')->modify('-' . $months . ' months');
        $day = min((int) $end->format('d'), (int) $firstOfTarget->format('t'));
        $start = $firstOfTarget->setDate((int) $firstOfTarget->format('Y'), (int) $firstOfTarget->format('m'), $day);

        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
    }

    /** Motorina în EUR/l = motorina lei/l ÷ cursul EUR/RON. */
    public static function dieselEur(?float $dieselRon, ?float $eurRon): ?float
    {
        if ($dieselRon === null || $eurRon === null || $eurRon <= 0.0 || $dieselRon < 0.0) {
            return null;
        }

        return $dieselRon / $eurRon;
    }

    /** Variație % = (actual / referință − 1) × 100. Null fără referință validă. */
    public static function percentChange(?float $current, ?float $reference): ?float
    {
        if ($current === null || $reference === null || $reference <= 0.0) {
            return null;
        }

        return ($current / $reference - 1.0) * 100.0;
    }

    /**
     * Descompunerea efectelor motorină / curs.
     *
     *   diesel_ron_pct  = (F1/F0 − 1)×100           – variația motorinei în lei
     *   fx_rate_pct     = (E1/E0 − 1)×100           – variația cursului EUR/RON
     *   fx_only_pct     = (E0/E1 − 1)×100           – efectul cursului asupra motorinei în EUR
     *                                                 (EUR/RON crește ⇒ motorina în EUR scade)
     *   combined_pct    = ((F1/E1)/(F0/E0) − 1)×100 – efectul combinat (multiplicativ)
     *
     * Atribuirea aditivă (Shapley, 2 factori) care se reconciliază exact:
     *   contrib_diesel = d + d·f/2,  contrib_fx = f + d·f/2,  d + f + d·f = combinat
     * unde d = diesel_ron_pct/100, f = fx_only_pct/100.
     *
     * @return array<string,?float>|null
     */
    public static function decompose(?float $f0, ?float $f1, ?float $e0, ?float $e1): ?array
    {
        if ($f0 === null || $f1 === null || $e0 === null || $e1 === null
            || $f0 <= 0.0 || $f1 <= 0.0 || $e0 <= 0.0 || $e1 <= 0.0) {
            return null;
        }

        $d = $f1 / $f0 - 1.0;
        $fx = $e1 / $e0 - 1.0;
        $f = $e0 / $e1 - 1.0;
        $combined = ($f1 / $e1) / ($f0 / $e0) - 1.0;
        $interaction = $d * $f;

        return [
            'p0' => $f0 / $e0,
            'p1' => $f1 / $e1,
            'diesel_ron_pct' => $d * 100.0,
            'fx_rate_pct' => $fx * 100.0,
            'fx_only_pct' => $f * 100.0,
            'combined_pct' => $combined * 100.0,
            'interaction_pct' => $interaction * 100.0,
            'contrib_diesel_pct' => ($d + $interaction / 2.0) * 100.0,
            'contrib_fx_pct' => ($f + $interaction / 2.0) * 100.0,
        ];
    }

    /**
     * Pragul: 'up' (≥ +prag), 'down' (≤ −prag), 'within' sau null fără date.
     * Comparație pe valoarea nerotunjită, cu toleranța THRESHOLD_EPSILON.
     */
    public static function thresholdState(?float $variationPct, ?float $thresholdPct): ?string
    {
        if ($variationPct === null || $thresholdPct === null || $thresholdPct <= 0.0) {
            return null;
        }
        if ($variationPct >= $thresholdPct - self::THRESHOLD_EPSILON) {
            return 'up';
        }
        if ($variationPct <= -$thresholdPct + self::THRESHOLD_EPSILON) {
            return 'down';
        }

        return 'within';
    }

    /**
     * Tariful simulat: tarif × (1 + variație/100 × pondere motorină).
     * Ponderea (0…1) este partea din tarif sensibilă la combustibil — NU se
     * presupune 100%. Fără pondere nu există simulare (null).
     * Rezultatul are 4 zecimale, precizia transport_tariff_versions.value.
     */
    public static function adjustTariff(float $currentValue, ?float $variationPct, ?float $fuelWeight): ?float
    {
        if ($variationPct === null || $fuelWeight === null || $fuelWeight < 0.0 || $fuelWeight > 1.0) {
            return null;
        }

        return round($currentValue * (1.0 + ($variationPct / 100.0) * $fuelWeight), 4);
    }

    /**
     * Clasificarea Distribuție preț mic / mare a unui beneficiar — aceeași regulă
     * ca în Centralizator Facturare: tarifele distincte sortate crescător; unul
     * singur = „Preț unic”, cel mai mic = „Preț mic”, cel mai mare = „Preț mare”,
     * cele intermediare își păstrează propria valoare.
     *
     * @param array<int,float> $rates
     * @return array<string,string> cheie = rata formatată (4 zecimale) → 'unic'|'mic'|'mare'|'intermediar'
     */
    public static function priceClasses(array $rates): array
    {
        $distinct = [];
        foreach ($rates as $rate) {
            $distinct[number_format((float) $rate, 4, '.', '')] = (float) $rate;
        }
        asort($distinct);
        $keys = array_keys($distinct);
        $count = count($keys);

        $classes = [];
        foreach ($keys as $index => $key) {
            $classes[(string) $key] = match (true) {
                $count === 1 => 'unic',
                $index === 0 => 'mic',
                $index === $count - 1 => 'mare',
                default => 'intermediar',
            };
        }

        return $classes;
    }
}
