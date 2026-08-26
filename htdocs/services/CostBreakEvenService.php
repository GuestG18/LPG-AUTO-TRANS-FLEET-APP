<?php
declare(strict_types=1);

/**
 * Break-even + simulare pentru "Cost operațional / km".
 *
 * Matematică pură — fără acces la baza de date. Simularea NU modifică
 * niciodată date reale: primește agregatele de bază și parametrii scenariului
 * și întoarce un scenariu recalculat în memorie.
 *
 * Principiul §21 din specificație:
 *   - costurile FIXE rămân fixe pe perioadă => mai mulți km împart aceeași
 *     povară fixă (fix/km scade);
 *   - costurile VARIABILE se scalează cu activitatea prin rata lor lei/km.
 */
class CostBreakEvenService
{
    /**
     * @param float $fixedTotal    costuri fixe totale în perioadă (lei)
     * @param float $variableTotal costuri variabile totale în perioadă (lei)
     * @param int   $km            km reali în perioadă
     * @param int   $trips         curse în perioadă
     * @param float $revenue       venit total în perioadă (lei)
     */
    public static function compute(float $fixedTotal, float $variableTotal, int $km, int $trips, float $revenue): array
    {
        $total = $fixedTotal + $variableTotal;
        $costPerKm = $km > 0 ? $total / $km : null;
        $revenuePerKm = $km > 0 ? $revenue / $km : null;
        $variablePerKm = $km > 0 ? $variableTotal / $km : null;
        $kmPerTrip = $trips > 0 && $km > 0 ? $km / $trips : null;
        $result = $revenue - $total;

        $breakEvenKm = null;
        $reachable = false;
        $reason = '';
        if ($km <= 0) {
            $reason = '0 km în perioada selectată — break-even indisponibil';
        } elseif ($revenuePerKm === null || $revenuePerKm <= 0) {
            $reason = 'Fără venit înregistrat în perioadă — nu se poate estima recuperarea';
        } elseif ($revenuePerKm <= $variablePerKm) {
            $reason = 'Venitul/km nu acoperă nici costul variabil/km — break-even imposibil la tarifele actuale';
        } else {
            // marja de contribuție: fiecare km aduce (venit/km − variabil/km) pentru acoperirea fixelor
            $breakEvenKm = $fixedTotal / ($revenuePerKm - $variablePerKm);
            $reachable = true;
        }

        $kmMissing = $reachable ? max(0.0, $breakEvenKm - $km) : null;
        $tripsNeeded = $reachable && $kmPerTrip !== null && $kmPerTrip > 0 ? (int) ceil($breakEvenKm / $kmPerTrip) : null;
        $revenueNeeded = $reachable ? $breakEvenKm * $revenuePerKm : null;
        $revenueAdditional = $revenueNeeded !== null ? max(0.0, $revenueNeeded - $revenue) : null;
        // la break-even, cost/km = venit/km (fixele împărțite pe km-ii de break-even + variabilul)
        $costPerKmAtBreakEven = $reachable && $breakEvenKm > 0 ? ($fixedTotal / $breakEvenKm) + $variablePerKm : null;
        $recoveryPct = $total > 0 ? min(999.9, $revenue / $total * 100.0) : null;

        return [
            'fixed_total' => $fixedTotal,
            'variable_total' => $variableTotal,
            'cost_total' => $total,
            'km_current' => $km,
            'trips_current' => $trips,
            'revenue' => $revenue,
            'cost_per_km' => $costPerKm,
            'revenue_per_km' => $revenuePerKm,
            'variable_per_km' => $variablePerKm,
            'result' => $result,
            'result_per_km' => $km > 0 ? $result / $km : null,
            'reachable' => $reachable,
            'reason' => $reason,
            'break_even_km' => $breakEvenKm,
            'km_missing' => $kmMissing,
            'trips_needed' => $tripsNeeded,
            'trips_missing' => $tripsNeeded !== null ? max(0, $tripsNeeded - $trips) : null,
            'revenue_needed' => $revenueNeeded,
            'revenue_additional' => $revenueAdditional,
            'cost_per_km_at_breakeven' => $costPerKmAtBreakEven,
            'recovery_pct' => $recoveryPct,
        ];
    }

    /**
     * Scenariu de simulare — recalculat integral în memorie.
     *
     * @param array $base rezultatul compute() pe datele reale (baseline)
     * @param array{km?:float,trips?:int,revenue_per_km?:float} $params valorile simulate
     */
    public static function simulate(array $base, array $params): array
    {
        $simKm = isset($params['km']) && (float) $params['km'] > 0 ? (float) $params['km'] : (float) $base['km_current'];
        $simTrips = isset($params['trips']) && (int) $params['trips'] > 0 ? (int) $params['trips'] : (int) $base['trips_current'];
        $simRevKm = isset($params['revenue_per_km']) && (float) $params['revenue_per_km'] > 0
            ? (float) $params['revenue_per_km']
            : (float) ($base['revenue_per_km'] ?? 0);

        $fixed = (float) $base['fixed_total'];              // fix rămâne fix (§21)
        $varPerKm = (float) ($base['variable_per_km'] ?? 0); // variabilul urmează rata lei/km
        $variable = $varPerKm * $simKm;
        $cost = $fixed + $variable;
        $revenue = $simRevKm * $simKm;
        $result = $revenue - $cost;

        $breakEven = self::compute($fixed, $variable, (int) round($simKm), $simTrips, $revenue);

        return [
            'params' => ['km' => $simKm, 'trips' => $simTrips, 'revenue_per_km' => $simRevKm],
            'fixed_total' => $fixed,
            'variable_total' => $variable,
            'cost_total' => $cost,
            'fixed_per_km' => $simKm > 0 ? $fixed / $simKm : null,
            'variable_per_km' => $simKm > 0 ? $varPerKm : null,
            'cost_per_km' => $simKm > 0 ? $cost / $simKm : null,
            'revenue' => $revenue,
            'revenue_per_km' => $simKm > 0 ? $simRevKm : null,
            'result' => $result,
            'result_per_km' => $simKm > 0 ? $result / $simKm : null,
            'recovery_pct' => $cost > 0 ? min(999.9, $revenue / $cost * 100.0) : null,
            'break_even_km' => $breakEven['break_even_km'],
            'km_missing' => $breakEven['km_missing'],
            'reachable' => $breakEven['reachable'],
            'reason' => $breakEven['reason'],
            'delta' => [
                'km' => $simKm - (float) $base['km_current'],
                'cost_per_km' => ($simKm > 0 && ($base['cost_per_km'] ?? null) !== null) ? ($cost / $simKm) - (float) $base['cost_per_km'] : null,
                'revenue' => $revenue - (float) $base['revenue'],
                'result' => $result - (float) $base['result'],
            ],
        ];
    }
}
