<?php
declare(strict_types=1);

/**
 * Test pentru corectia manuala a vehiculului unei alimentari (card folosit
 * pe alta masina): persistenta la sync, revenire la card, T0 / asocieri.
 *
 * SIGURANTA: doar randuri cu api_id "vehtest-" si vehicule VEHTEST-*.
 * Rulare:  php scripts/test_fuel_set_vehicle.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/config/database.php';
require_once $projectRoot . '/htdocs/models/BaseModel.php';
require_once $projectRoot . '/htdocs/models/FuelModel.php';

$db = get_pdo();
$model = new FuelModel($db);
$model->ensureSchema();

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $observed = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $name . ($observed !== '' ? ' — ' . $observed : '') . PHP_EOL;
}

$cleanup = static function () use ($db): void {
    $db->exec("DELETE FROM fuel_month_t0 WHERE vehicle_key LIKE 'VEHTEST%'");
    $db->exec("DELETE FROM fuel_fillups WHERE api_id LIKE 'vehtest-%'");
};
$cleanup();

$record = [
    'api_id' => 'vehtest-1',
    'vehicle_registration' => 'VEHTEST 01 AAA',
    'driver_name' => 'TEST',
    'fuel_type' => 'motorina',
    'quantity_liters' => 100,
    'odometer_km' => 500000,
    'total_value' => 700,
    'station_name' => 'Test',
    'fillup_datetime' => '2020-01-15 10:00:00',
];
$fetch = static function () use ($db): array {
    return $db->query("SELECT * FROM fuel_fillups WHERE api_id = 'vehtest-1'")->fetch() ?: [];
};

try {
    $model->upsertFillups([$record]);
    $row = $fetch();
    $id = (int) $row['id'];
    check('insert initial', $row['vehicle_registration'] === 'VEHTEST 01 AAA', (string) $row['vehicle_registration']);

    $db->prepare("INSERT INTO fuel_month_t0 (vehicle_key, month_start, fillup_id, mode, created_at, updated_at) VALUES ('VEHTEST01AAA', '2020-01-01', :id, 'manual', NOW(), NOW())")
        ->execute([':id' => $id]);

    check('set vehicle', $model->setFillupVehicle($id, 'vehtest 02 bbb'));
    $row = $fetch();
    check('vehicul efectiv mutat', $row['vehicle_registration'] === 'VEHTEST 02 BBB', (string) $row['vehicle_registration']);
    check('manual marcat', $row['vehicle_registration_manual'] === 'VEHTEST 02 BBB');
    check('card pastrat', $row['vehicle_registration_api'] === 'VEHTEST 01 AAA', (string) $row['vehicle_registration_api']);
    $t0 = (int) $db->query("SELECT COUNT(*) FROM fuel_month_t0 WHERE vehicle_key = 'VEHTEST01AAA'")->fetchColumn();
    check('T0 vechiul vehicul scos', $t0 === 0, (string) $t0);

    $model->upsertFillups([$record]);
    $row = $fetch();
    check('sync nu suprascrie corectia', $row['vehicle_registration'] === 'VEHTEST 02 BBB', (string) $row['vehicle_registration']);
    check('sync pastreaza numarul de pe card', $row['vehicle_registration_api'] === 'VEHTEST 01 AAA');

    check('a doua corectie', $model->setFillupVehicle($id, 'VEHTEST 03 CCC'));
    $row = $fetch();
    check('card ramane cel original', $row['vehicle_registration_api'] === 'VEHTEST 01 AAA', (string) $row['vehicle_registration_api']);

    check('revenire la card', $model->setFillupVehicle($id, null));
    $row = $fetch();
    check('vehicul = card', $row['vehicle_registration'] === 'VEHTEST 01 AAA', (string) $row['vehicle_registration']);
    check('manual sters', $row['vehicle_registration_manual'] === null);

    $model->setFillupVehicle($id, 'VEHTEST01AAA');
    $row = $fetch();
    check('alegerea numarului de pe card = fara corectie', $row['vehicle_registration_manual'] === null);

    check('id inexistent', $model->setFillupVehicle(999999999, 'X') === false);
} finally {
    $cleanup();
}

echo PHP_EOL . "Trecute: {$passed}, esuate: {$failed}" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
