<?php
declare(strict_types=1);

/**
 * Prima miscare a zilei (> DRIVER_WA_MIN_KM, implicit 1 km) -> notificare WhatsApp catre sofer.
 *
 *   php scripts/driver_first_movement.php                       rulare programata (cron, la 2-5 minute)
 *   php scripts/driver_first_movement.php --status              configuratia + notificarile de azi
 *
 * Test (doar pentru soferii din DRIVER_WA_TEST_DRIVER_IDS; nu apeleaza SAS si nu atinge
 * datele GPS existente):
 *   php scripts/driver_first_movement.php --simulate-vehicle=48 --driver=48 --km=3.2
 *   php scripts/driver_first_movement.php --reset-simulation --driver=48
 *
 * Notificarea pleaca prin BaileyBot (whatsapp-agent-lab), care citeste whatsapp_outbox.
 * Doua rulari simultane nu se calca: GET_LOCK in MySQL + UNIQUE pe sofer / vehicul / zi.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/services/SasFleetClient.php';
require_once $root . '/htdocs/services/FleetLivePositionService.php';
require_once $root . '/htdocs/services/SasDashboardService.php';
require_once $root . '/htdocs/services/DriverFirstMovementService.php';

$options = ['status' => false, 'simulate_vehicle' => null, 'driver' => null, 'km' => 3.0, 'date' => date('Y-m-d'), 'reset' => false];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--status') {
        $options['status'] = true;
    } elseif ($argument === '--reset-simulation') {
        $options['reset'] = true;
    } elseif (preg_match('/^--simulate-vehicle=(\d+)$/', $argument, $m) === 1) {
        $options['simulate_vehicle'] = (int) $m[1];
    } elseif (preg_match('/^--driver=(\d+)$/', $argument, $m) === 1) {
        $options['driver'] = (int) $m[1];
    } elseif (preg_match('/^--km=(\d+(?:\.\d+)?)$/', $argument, $m) === 1) {
        $options['km'] = (float) $m[1];
    } elseif (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $argument, $m) === 1) {
        $options['date'] = $m[1];
    } else {
        fwrite(STDERR, "Argument necunoscut: $argument\n");
        exit(2);
    }
}

$log = static function (string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
};

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$model = new DriverWhatsAppModel($db);
$model->ensureSchema();
$config = DriverFirstMovementService::config();

if ($options['status']) {
    $log(sprintf('Activ: %s | mod test: %s | soferi test: %s | prag: %.1f km | reverificare: %d min',
        $config['enabled'] ? 'da' : 'NU', $config['test_mode'] ? 'da' : 'nu',
        $config['test_driver_ids'] === [] ? '-' : implode(',', $config['test_driver_ids']), $config['min_km'], $config['recheck_minutes']));
    $stmt = $db->prepare('SELECT id, driver_id, vehicle_id, status, response_action, confirmed_at, sent_at, is_simulation FROM driver_movement_notifications WHERE local_date = :d ORDER BY id');
    $stmt->execute([':d' => $options['date']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $log(json_encode($row, JSON_UNESCAPED_UNICODE));
    }
    exit(0);
}

// Testele: doar soferii din lista, explicit.
if ($options['simulate_vehicle'] !== null || $options['reset']) {
    $driverId = (int) ($options['driver'] ?? 0);
    if (!$config['test_mode'] || !in_array($driverId, $config['test_driver_ids'], true)) {
        fwrite(STDERR, "Simularea cere DRIVER_WA_TEST_MODE=1 si --driver=<id> din DRIVER_WA_TEST_DRIVER_IDS.\n");
        exit(2);
    }

    if ($options['reset']) {
        // Doar randurile marcate ca simulare, ale acestui sofer, din ziua data.
        $db->beginTransaction();
        $ids = $db->prepare('SELECT id, vehicle_id FROM driver_movement_notifications WHERE driver_id = :d AND local_date = :day AND is_simulation = 1');
        $ids->execute([':d' => $driverId, ':day' => $options['date']]);
        $rows = $ids->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $db->prepare("DELETE FROM whatsapp_outbox WHERE related_type = 'notification' AND related_id = :id AND status <> 'sending'")->execute([':id' => $row['id']]);
            $db->prepare('DELETE FROM driver_movement_notifications WHERE id = :id')->execute([':id' => $row['id']]);
            $db->prepare('DELETE FROM vehicle_movement_days WHERE vehicle_id = :v AND local_date = :day AND is_simulation = 1')->execute([':v' => $row['vehicle_id'], ':day' => $options['date']]);
        }
        $db->prepare("UPDATE driver_whatsapp_conversations SET state = 'idle', notification_id = NULL, expires_at = NULL WHERE driver_id = :d")->execute([':d' => $driverId]);
        $db->commit();
        $log('Simulari sterse: ' . count($rows) . ' notificare(i) pentru soferul #' . $driverId . ' din ' . $options['date'] . '.');
        exit(0);
    }

    // Un segment simulat de 15 minute care s-a terminat acum 5 minute.
    $end = time() - 300;
    $segments = [['start' => date('Y-m-d H:i:s', $end - 900), 'end' => date('Y-m-d H:i:s', $end), 'km' => $options['km']]];
    $service = new DriverFirstMovementService($db, $model, null, null, $config, $log);
    $result = $service->processVehicle((int) $options['simulate_vehicle'], null, $options['date'], $segments, true, $driverId);
    $log(sprintf('Simulare vehicul #%d: %.2f km, prag %s, notificari noi: %d%s',
        $options['simulate_vehicle'], $result['movement']['km'], $result['movement']['reached'] ? 'atins' : 'neatins', $result['notifications'],
        $result['skipped'] !== [] ? ' | ' . implode('; ', $result['skipped']) : ''));
    if ($result['notifications'] === 0 && $result['movement']['reached'] && $result['skipped'] === []) {
        $log('Notificarea pentru aceasta zi exista deja (idempotenta): nu se trimite nimic in plus.');
    }
    exit(0);
}

// Rularea programata
$lock = (int) $db->query("SELECT GET_LOCK('fleet_driver_first_movement', 0)")->fetchColumn();
if ($lock !== 1) {
    $log('Alta rulare este in curs; ies.');
    exit(0);
}
try {
    $service = new DriverFirstMovementService($db, $model, new SasDashboardService($db), new FleetLivePositionService($db), $config, $log);
    $summary = $service->run($options['date']);
    $log(json_encode($summary, JSON_UNESCAPED_UNICODE));
    exit($summary['errors'] === [] ? 0 : 1);
} finally {
    $db->query("SELECT RELEASE_LOCK('fleet_driver_first_movement')");
}
