<?php
declare(strict_types=1);

/**
 * Test pentru grupurile de facturare pe vehicul din "Activitate pe vehicule - Detaliat"
 * (Categorie -> Vehicul -> Rezumat calcul facturare -> Curse). Doar citire, pe datele reale.
 *
 *   php scripts/test_centralizator_vehicule_facturare.php [YYYY-MM ...]
 *
 * Verifica, pe fiecare luna si pe mai multe filtre (toate, beneficiar 33, fiecare tip):
 *   - fiecare cursa (parte) din tabelul vehiculului apare in exact un grup al lui;
 *   - grupurile au doar cursele vehiculului; suma valorilor grupurilor = valoarea vehiculului;
 *   - suma grupurilor pe toate vehiculele = valoarea din "Activitati pe tipuri de transport";
 *   - refacturarile pe grupurile vehiculelor = refacturarile pe grupurile tipurilor.
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

$months = array_slice($argv, 1) ?: ['2026-08', '2026-09', '2026-10'];
$failures = 0;
$checks = 0;
$fail = static function (string $message) use (&$failures): void {
    $failures++;
    echo "  FAIL: {$message}\n";
};
$near = static fn (float $a, float $b, float $eps = 0.011): bool => abs($a - $b) <= $eps;

$variants = [
    'toate' => [],
    'beneficiar 33' => ['beneficiar_id' => [33]],
    'primar' => ['tip_activitate' => 'primar'],
    'primar_tona' => ['tip_activitate' => 'primar_tona'],
    'distributie' => ['tip_activitate' => 'distributie'],
    'compresor' => ['tip_activitate' => 'compresor'],
];

foreach ($months as $month) {
    foreach ($variants as $variantLabel => $extra) {
        $report = $service->getReport(['month' => $month] + $extra);
        $scope = $month . ' / ' . $variantLabel;
        $rows = (array) ($report['vehicles']['detail']['rows'] ?? []);
        $stats = ['vehicles' => 0, 'groups' => 0, 'multi' => 0, 'no_tariff' => 0];
        $groupsValue = 0.0;
        $groupsRefunds = 0.0;

        foreach ($rows as $row) {
            $plate = (string) ($row['vehicle'] ?? '?');
            $groups = (array) ($row['billing_groups'] ?? []);
            $stats['vehicles']++;
            $stats['groups'] += count($groups);
            $stats['multi'] += count($groups) > 1 ? 1 : 0;

            $detailKeys = [];
            foreach ((array) ($row['detail_rows'] ?? []) as $trip) {
                $detailKeys[] = (string) $trip['type'] . ':' . (int) $trip['trip_id'];
            }
            $seen = [];
            $value = 0.0;
            foreach ($groups as $group) {
                $value += (float) $group['value'];
                $stats['no_tariff'] += ($group['rate'] ?? null) === null && (array) $group['components'] === [] ? 1 : 0;
                foreach ((array) $group['trip_keys'] as $tripKey) {
                    $seen[$tripKey] = ($seen[$tripKey] ?? 0) + 1;
                }
                foreach ((array) $group['trip_rows'] as $trip) {
                    $checks++;
                    if ((int) ($trip['vehicle_id'] ?? 0) !== (int) ($row['vehicle_id'] ?? 0)) {
                        $fail("{$scope}: {$plate} - cursa #{$trip['trip_id']} din alt vehicul");
                    }
                }
                foreach ((array) $group['refunds'] as $line) {
                    $groupsRefunds += (float) $line['amount'];
                }
            }
            $groupsValue += $value;

            $checks++;
            $detailSet = array_count_values($detailKeys);
            ksort($detailSet);
            ksort($seen);
            if ($detailSet !== $seen) {
                $fail("{$scope}: {$plate} - cursele grupurilor nu corespund curselor vehiculului");
            }
            $checks++;
            if (!$near($value, (float) ($row['value'] ?? 0))) {
                $fail("{$scope}: {$plate} - grupuri {$value} != vehicul " . (float) $row['value']);
            }
        }

        $activityValue = 0.0;
        $activityRefunds = 0.0;
        foreach ((array) ($report['activity']['rows'] ?? []) as $typeRow) {
            $activityValue += (float) ($typeRow['value'] ?? 0);
            foreach ((array) ($typeRow['routes'] ?? []) as $route) {
                foreach ((array) ($route['refunds'] ?? []) as $line) {
                    $activityRefunds += (float) $line['amount'];
                }
            }
        }
        $checks += 2;
        if (!$near($groupsValue, $activityValue, 0.05)) {
            $fail("{$scope}: grupuri pe vehicule {$groupsValue} != tipuri de transport {$activityValue}");
        }
        if (!$near($groupsRefunds, $activityRefunds, 0.05)) {
            $fail("{$scope}: refacturari pe vehicule {$groupsRefunds} != pe tipuri {$activityRefunds}");
        }

        printf("%s: %d vehicule, %d grupuri (%d vehicule cu mai multe grupuri, %d grupuri fara tarif), valoare %.2f\n",
            $scope, $stats['vehicles'], $stats['groups'], $stats['multi'], $stats['no_tariff'], $groupsValue);
    }
}

echo "\n{$checks} verificari, {$failures} esecuri\n";
exit($failures > 0 ? 1 : 0);
