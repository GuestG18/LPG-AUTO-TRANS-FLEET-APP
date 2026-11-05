<?php
declare(strict_types=1);

/**
 * Km semiremorcilor din SAS: contorul fiecarei semiremorci (vehicule.km_bord / km_revizie)
 * creste cu km SAS ai capului tractor cuplat, pe zilele inchise de la ultima rulare.
 *
 *   php scripts/sync_km_semiremorci.php                 # rulare programata (recomandat zilnic, dupa 00:30)
 *   php scripts/sync_km_semiremorci.php --max-calls=20  # buget redus de cereri SAS
 *
 * Idempotent: fiecare zi se adauga o singura data (semiremorci_km_sas.synced_until).
 * Prima rulare fixeaza data de comutare (km_center_setari.semiremorci_sas_de_la) la
 * urmatorul 00:00; de atunci cursele din Dispecer nu mai adauga km semiremorcilor.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/services/SasFleetClient.php';
require_once $root . '/htdocs/services/FleetLivePositionService.php';
require_once $root . '/htdocs/services/TrailerSasKmService.php';

$maxCalls = 60;
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--max-calls=')) {
        $maxCalls = max(1, (int) substr((string) $arg, 12));
    }
}

$log = static function (string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
};

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$service = new TrailerSasKmService($db, null, $log);
$log('Km semiremorci din SAS începând cu ' . $service->ensureCutover() . '.');
$stats = $service->run($maxCalls);
$log($stats['message'] . sprintf(' [cereri=%d, zile=%d, erori=%d]', $stats['calls'], $stats['days'], $stats['errors']));

exit($stats['errors'] > 0 ? 2 : 0);
