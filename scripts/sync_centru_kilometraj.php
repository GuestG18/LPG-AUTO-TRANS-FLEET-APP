<?php
declare(strict_types=1);

/**
 * Sincronizarea citirilor de odometru SAS Fleet pentru Centru Kilometraj.
 *
 *   php scripts/sync_centru_kilometraj.php                   # rulare programata (recomandat la 5 min)
 *   php scripts/sync_centru_kilometraj.php --max-calls=20    # buget redus de cereri SAS
 *   php scripts/sync_centru_kilometraj.php --max-seconds=240 # se opreste inainte de urmatoarea rulare
 *
 * Fiecare vehicul mapat in SAS primeste o cerere travelsheet de la ultima verificare
 * (−2 h) pana acum; citirile (kmIndex) au identitate stabila, deci rularile repetate nu
 * dubleaza nimic. CardOil nu se cere aici: alimentarile vin din sync_cardoil_alimentari.php.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/VehicleCapacityCategoryModel.php';
require_once $root . '/htdocs/models/OdometerCenterModel.php';
require_once $root . '/htdocs/services/SasFleetClient.php';
require_once $root . '/htdocs/services/FleetLivePositionService.php';
require_once $root . '/htdocs/services/OdometerCenterService.php';

$maxCalls = 200;
$maxSeconds = 240;
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--max-calls=')) {
        $maxCalls = max(1, (int) substr((string) $arg, 12));
    } elseif (str_starts_with((string) $arg, '--max-seconds=')) {
        $maxSeconds = max(0, (int) substr((string) $arg, 14));
    }
}

$log = static function (string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
};

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$log('Pornire sincronizare Centru Kilometraj (max ' . $maxCalls . ' cereri, ' . $maxSeconds . ' s).');
$stats = (new OdometerCenterService($db, null, null, $log))->syncSas($maxCalls, $maxSeconds);
$log($stats['message'] . sprintf(' [cereri=%d, erori=%d]', $stats['calls'], $stats['errors']));

exit($stats['aborted'] ? 2 : 0);
