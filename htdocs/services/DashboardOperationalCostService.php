<?php
declare(strict_types=1);

/**
 * Cardul "Cost total operațional" din Dashboard, calculat cu ACELAȘI motor ca pagina
 * Cost operațional / km (OperationalCostService): valori fără TVA, elementele din
 * cost_operational_elemente, împărțite pe fixe / variabile și pe categoriile de vehicul
 * ale motorului (CT+SR, 7 / 10 / 13 TO ...).
 *
 * Motorul lucrează pe luni calendaristice: perioada din Dashboard se descompune în bucăți
 * lunare, iar fiecare se calculează EXACT pe zilele ei (compute cu date_start/date_end):
 * costurile pe evenimente (carburant, mentenanță, diurnă, taxe, km) sunt cele din acele zile,
 * iar cele normalizate lunar (salarii, documente, dotări, valori lunare) se proratează pe
 * zile. Așa, și o singură zi arată costul real al zilei respective.
 *
 * Filtrele Dashboard-ului:
 *  - categorie "grele"  -> unitățile motorului + costurile nealocate ale flotei;
 *  - categorie "usoare" -> motorul nu modelează flota ușoară: rămâne doar carburantul
 *    alimentat pe numere din afara flotei grele (rând "Alte vehicule");
 *  - vehicul            -> doar unitatea care îl poartă (semiremorca cuplată -> tractorul).
 */
class DashboardOperationalCostService
{
    private const UNMATCHED_FUEL_NOTE = 'Alimentări pe numere neasociate flotei grele active';

    private const EXTRA_CATEGORIES = [
        'alte_vehicule' => 'Vehicule ușoare / neasociate',
        'nealocat' => 'Nealocat pe vehicul',
    ];

    private const CATEGORY_ICONS = [
        'ct_sr' => 'bi-truck-flatbed',
        'c7' => 'bi-truck',
        'c10' => 'bi-truck',
        'c13' => 'bi-truck',
        'camion_necunoscut' => 'bi-truck',
        'semi_necuplata' => 'bi-truck-flatbed',
        'alte_vehicule' => 'bi-car-front',
        'nealocat' => 'bi-building',
    ];

    private const CATEGORY_ORDER = ['ct_sr' => 1, 'c7' => 2, 'c10' => 3, 'c13' => 4, 'camion_necunoscut' => 5, 'semi_necuplata' => 6, 'alte_vehicule' => 7, 'nealocat' => 8];

    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array{vehicle_category?:string,vehicle_id?:int|null} $filters
     * @param array{date_start:string,date_end:string} $periodRange
     */
    public function build(array $filters, array $periodRange): array
    {
        $category = (string) ($filters['vehicle_category'] ?? 'toate');
        $vehicleId = isset($filters['vehicle_id']) && (int) $filters['vehicle_id'] > 0 ? (int) $filters['vehicle_id'] : null;

        $months = $this->monthsInRange((string) $periodRange['date_start'], (string) $periodRange['date_end']);

        $result = [
            'available' => false,
            'fixed_total' => 0.0,
            'variable_total' => 0.0,
            'total' => 0.0,
            'elements' => [],          // cod => {label, tip, value}
            'tips' => ['fix' => [], 'variabil' => []],
            'missing' => ['fix' => [], 'variabil' => []],
            'months' => $months,
            'prorated' => false,
            'light_only' => $category === 'usoare',
        ];

        $categories = ['fix' => [], 'variabil' => []];
        $elementStatus = [];

        foreach ($months as $month) {
            if ($month['factor'] < 1.0) {
                $result['prorated'] = true;
            }

            // Serviciul ține stare internă după compute(): o instanță nouă pe lună.
            $engine = new OperationalCostService($this->db);
            if (!$engine->model()->schemaReady()) {
                return $result;
            }
            $computed = $engine->compute(['period' => $month['key'], 'date_start' => $month['start'], 'date_end' => $month['end']]);
            $result['available'] = true;
            foreach ((array) ($computed['elements'] ?? []) as $status) {
                $elementStatus[(string) $status['cod']] = $status;
            }

            foreach ($engine->internalUnits() as $unit) {
                if (!$this->unitInScope($unit, $category, $vehicleId)) {
                    continue;
                }
                foreach ((array) ($unit['elements'] ?? []) as $code => $info) {
                    $value = (float) ($info['value'] ?? 0);
                    if ($value == 0.0) {
                        continue;
                    }
                    $this->addValue($result, $categories, (string) $info['tip'], (string) $unit['category'], (string) $unit['category_label'],
                        (string) $code, (string) $info['label'], $value, (int) $unit['vehicle_id']);
                }
                // unitatea se numără în categorie chiar dacă nu are încă nicio valoare
                foreach (['fix', 'variabil'] as $tip) {
                    $this->ensureCategory($categories, $tip, (string) $unit['category'], (string) $unit['category_label']);
                    $categories[$tip][(string) $unit['category']]['units'][(int) $unit['vehicle_id']] = true;
                }
            }

            if ($vehicleId !== null) {
                continue; // costurile nealocate nu aparțin unui vehicul anume
            }
            foreach ((array) ($computed['company_rows'] ?? []) as $row) {
                $isOtherVehicleFuel = (string) ($row['note'] ?? '') === self::UNMATCHED_FUEL_NOTE;
                if ($category === 'usoare' && !$isOtherVehicleFuel) {
                    continue;
                }
                if ($category === 'grele' && $isOtherVehicleFuel) {
                    continue;
                }
                $bucket = $isOtherVehicleFuel ? 'alte_vehicule' : 'nealocat';
                $value = (float) ($row['value'] ?? 0);
                if ($value == 0.0) {
                    continue;
                }
                $this->addValue($result, $categories, (string) $row['tip'], $bucket, self::EXTRA_CATEGORIES[$bucket],
                    (string) $row['cod'], (string) $row['nume'], $value, null);
            }
        }

        // Elementele active fără sursă / valoare: LIPSĂ nu înseamnă 0 (regula motorului).
        foreach ($elementStatus as $status) {
            if (empty($status['activ']) || (string) ($status['quality'] ?? '') !== 'lipsa') {
                continue;
            }
            $tip = (string) $status['tip'] === 'fix' ? 'fix' : 'variabil';
            $result['missing'][$tip][] = (string) $status['nume'];
        }

        foreach (['fix', 'variabil'] as $tip) {
            $tipTotal = $tip === 'fix' ? $result['fixed_total'] : $result['variable_total'];
            $rows = [];
            foreach ($categories[$tip] as $code => $cat) {
                if ($cat['total'] == 0.0 && $cat['units'] === []) {
                    continue;
                }
                $elements = array_values($cat['elements']);
                usort($elements, static fn(array $a, array $b): int => $b['value'] <=> $a['value']);
                $rows[] = [
                    'code' => $code,
                    'label' => $cat['label'],
                    'icon' => self::CATEGORY_ICONS[$code] ?? 'bi-truck',
                    'vehicles' => count($cat['units']),
                    'total' => $cat['total'],
                    'share' => $tipTotal > 0 ? $cat['total'] / $tipTotal * 100.0 : 0.0,
                    'elements' => $elements,
                ];
            }
            usort($rows, static fn(array $a, array $b): int =>
                (self::CATEGORY_ORDER[$a['code']] ?? 9) <=> (self::CATEGORY_ORDER[$b['code']] ?? 9));
            $result['tips'][$tip] = $rows;
        }

        $result['total'] = $result['fixed_total'] + $result['variable_total'];

        return $result;
    }

    /** Valoarea unui element pe tot filtrul (ex. carburant, revizii). */
    public static function elementValue(array $costs, string ...$codes): float
    {
        $sum = 0.0;
        foreach ($codes as $code) {
            $sum += (float) ($costs['elements'][$code]['value'] ?? 0);
        }

        return $sum;
    }

    private function unitInScope(array $unit, string $category, ?int $vehicleId): bool
    {
        if ($category === 'usoare') {
            return false; // motorul modelează doar flota grea
        }
        if ($vehicleId !== null) {
            return (int) $unit['vehicle_id'] === $vehicleId || (int) ($unit['semi_id'] ?? 0) === $vehicleId;
        }

        return true;
    }

    private function ensureCategory(array &$categories, string $tip, string $code, string $label): void
    {
        $categories[$tip][$code] ??= ['label' => $label, 'total' => 0.0, 'units' => [], 'elements' => []];
    }

    private function addValue(array &$result, array &$categories, string $tip, string $code, string $label,
        string $elementCode, string $elementLabel, float $value, ?int $unitId): void
    {
        $tip = $tip === 'fix' ? 'fix' : 'variabil';
        $this->ensureCategory($categories, $tip, $code, $label);
        $categories[$tip][$code]['total'] += $value;
        $categories[$tip][$code]['elements'][$elementCode] ??= ['cod' => $elementCode, 'label' => $elementLabel, 'value' => 0.0];
        $categories[$tip][$code]['elements'][$elementCode]['value'] += $value;
        if ($unitId !== null) {
            $categories[$tip][$code]['units'][$unitId] = true;
        }

        $result['elements'][$elementCode] ??= ['label' => $elementLabel, 'tip' => $tip, 'value' => 0.0];
        $result['elements'][$elementCode]['value'] += $value;
        if ($tip === 'fix') {
            $result['fixed_total'] += $value;
        } else {
            $result['variable_total'] += $value;
        }
    }

    /**
     * Bucățile lunare ale intervalului (până azi inclusiv), fiecare cu zilele ei exacte.
     *
     * @return array<int, array{key:string, start:string, end:string, factor:float}>
     */
    private function monthsInRange(string $start, string $end): array
    {
        $startDate = new DateTimeImmutable($start);
        $endDate = new DateTimeImmutable($end);
        $today = new DateTimeImmutable('today');
        if ($endDate > $today) {
            $endDate = $today;
        }

        $months = [];
        $cursor = $startDate->modify('first day of this month');
        while ($cursor <= $endDate) {
            $monthEnd = $cursor->modify('last day of this month');
            $segmentStart = $startDate > $cursor ? $startDate : $cursor;
            $segmentEnd = $endDate < $monthEnd ? $endDate : $monthEnd;
            $days = (int) $segmentStart->diff($segmentEnd)->format('%a') + 1;
            $months[] = [
                'key' => $cursor->format('Y-m'),
                'start' => $segmentStart->format('Y-m-d'),
                'end' => $segmentEnd->format('Y-m-d'),
                'factor' => min(1.0, $days / (int) $cursor->format('t')),
            ];
            $cursor = $cursor->modify('first day of next month');
        }

        return $months;
    }
}
