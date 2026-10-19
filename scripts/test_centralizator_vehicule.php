<?php
declare(strict_types=1);

/**
 * Test pentru sumarul pe vehicul din Centralizator facturare (Activitati pe tipuri de
 * transport -> grup de facturare -> vehicul -> curse). Doar citire, pe datele reale.
 *
 *   php scripts/test_centralizator_vehicule.php [YYYY-MM ...]
 *
 * Verifica, pe fiecare luna si fiecare grup de facturare:
 *   - un singur rand per vehicul in grup; cursele vehiculelor = cursele grupului (fara dubluri);
 *   - suma valorilor pe vehicul = valoarea grupului; suma grupurilor = valoarea tipului;
 *   - fiecare vehicul are doar cursele lui;
 *   - Distributie: tonele pe clase de pret = tonele curselor, fiecare cursa intr-o clasa existenta;
 *   - Primar: km din calculul pe vehicul = km din calculul grupului, pe acelasi tarif.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/services/BillingMonthRule.php';
require_once $root . '/htdocs/models/TransportTariffModel.php';
require_once $root . '/htdocs/services/FuelPriceIndexService.php';
require_once $root . '/htdocs/services/TransportPricingService.php';
require_once $root . '/htdocs/services/CentralizatorFacturareService.php';

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$service = new CentralizatorFacturareService($db);

$months = array_slice($argv, 1) ?: ['2026-07', '2026-08', '2026-09', '2026-10'];
$failures = 0;
$checks = 0;
$fail = static function (string $message) use (&$failures): void {
    $failures++;
    echo "  FAIL: {$message}\n";
};
$near = static fn (float $a, float $b, float $eps = 0.011): bool => abs($a - $b) <= $eps;

foreach ($months as $month) {
    foreach ([[], ['beneficiar_id' => [33]]] as $extra) {
        $report = $service->getReport(['month' => $month] + $extra);
        $scope = $month . ($extra !== [] ? ' / beneficiar 33' : '');
        $stats = ['groups' => 0, 'vehicles' => 0, 'multi' => 0, 'shared' => 0];
        foreach ((array) ($report['activity']['rows'] ?? []) as $typeRow) {
            $type = (string) $typeRow['key'];
            $routes = (array) ($typeRow['routes'] ?? []);
            $typeValue = 0.0;
            $vehicleGroups = [];
            foreach ($routes as $route) {
                $stats['groups']++;
                $checks++;
                $typeValue += (float) $route['value'];
                $vehicles = (array) ($route['vehicles'] ?? []);
                $label = "{$scope} {$type} [{$route['label']}]";
                $stats['vehicles'] += count($vehicles);
                $stats['multi'] += count($vehicles) > 1 ? 1 : 0;

                $keys = array_column($vehicles, 'key');
                if (count($keys) !== count(array_unique($keys))) {
                    $fail("{$label}: vehicul repetat in grup");
                }
                $vehicleValue = 0.0;
                $vehicleTrips = 0;
                $seen = [];
                foreach ($vehicles as $vehicle) {
                    $vehicleGroups[$vehicle['key']][] = $route['key'];
                    $vehicleValue += (float) $vehicle['value'];
                    $vehicleTrips += (int) $vehicle['trips'];
                    if ((int) $vehicle['trips'] !== count((array) $vehicle['trip_rows'])) {
                        $fail("{$label} {$vehicle['vehicle_label']}: numar curse diferit de randuri");
                    }
                    $tripValue = 0.0;
                    $tripTone = 0.0;
                    foreach ((array) $vehicle['trip_rows'] as $trip) {
                        $tripValue += (float) $trip['value'];
                        $tripTone += (float) $trip['tone'];
                        $seen[] = $trip['trip_id'] . '|' . $trip['race_no'];
                        if ((string) $trip['vehicle_label'] !== (string) $vehicle['vehicle_label']) {
                            $fail("{$label}: cursa {$trip['trip_id']} sub alt vehicul");
                        }
                        if ($type === 'distributie' && ($trip['price_class'] ?? null) === null) {
                            $fail("{$label}: cursa {$trip['trip_id']} fara clasa de pret");
                        }
                    }
                    if (!$near($tripValue, (float) $vehicle['value'])) {
                        $fail("{$label} {$vehicle['vehicle_label']}: valoare {$vehicle['value']} != suma curselor {$tripValue}");
                    }
                    if ($type === 'distributie') {
                        $classTone = array_sum(array_column((array) $vehicle['classes'], 'tone'));
                        if (!$near($classTone, $tripTone, 0.001)) {
                            $fail("{$label} {$vehicle['vehicle_label']}: tone pe clase {$classTone} != tone curse {$tripTone}");
                        }
                        $split = (array) ($vehicle['price_split'] ?? []);
                        $splitTone = (float) ($split['low_tone'] ?? 0) + (float) ($split['high_tone'] ?? 0) + (float) ($split['other_tone'] ?? 0);
                        if ($splitTone > $classTone + 0.001) {
                            $fail("{$label} {$vehicle['vehicle_label']}: tone mic/mare/alte {$splitTone} > tone curse {$classTone}");
                        }
                    }
                }
                if (!$near($vehicleValue, (float) $route['value'], 0.011 * max(1, count($vehicles)))) {
                    $fail("{$label}: suma vehicule {$vehicleValue} != grup {$route['value']}");
                }
                if ($vehicleTrips !== (int) $route['trips'] || count($seen) !== count(array_unique($seen)) || count($seen) !== count((array) $route['trip_rows'])) {
                    $fail("{$label}: cursele vehiculelor nu acopera exact cursele grupului");
                }
                /* Cantitatile pe unitate + tarif: vehiculele insumeaza calculul grupului. */
                $groupParts = [];
                foreach ((array) $route['components'] as $component) {
                    $groupParts[$component['quantity_unit'] . '@' . round((float) $component['rate'], 4)] = (float) $component['quantity'];
                }
                $vehicleParts = [];
                foreach ($vehicles as $vehicle) {
                    foreach ((array) $vehicle['components'] as $component) {
                        $partKey = $component['quantity_unit'] . '@' . round((float) $component['rate'], 4);
                        $vehicleParts[$partKey] = ($vehicleParts[$partKey] ?? 0.0) + (float) $component['quantity'];
                    }
                }
                foreach ($groupParts as $partKey => $quantity) {
                    if (!$near($quantity, (float) ($vehicleParts[$partKey] ?? 0), 0.01)) {
                        $fail("{$label}: {$partKey} grup {$quantity} != vehicule " . ($vehicleParts[$partKey] ?? 0));
                    }
                }
            }
            foreach ($vehicleGroups as $groups) {
                $stats['shared'] += count($groups) > 1 ? 1 : 0;
            }
            if (!$near($typeValue, (float) $typeRow['value'], 0.011 * max(1, count($routes)))) {
                $fail("{$scope} {$type}: suma grupurilor {$typeValue} != tip {$typeRow['value']}");
            }
        }
        echo sprintf("%s: %d grupuri, %d randuri vehicul, %d grupuri cu >1 vehicul, %d vehicule in mai multe grupuri\n",
            $scope, $stats['groups'], $stats['vehicles'], $stats['multi'], $stats['shared']);
    }
}

echo $failures === 0 ? "OK ({$checks} grupuri verificate)\n" : "{$failures} erori\n";
exit($failures === 0 ? 0 : 1);
