<?php
declare(strict_types=1);

/**
 * Sincronizarea telemetriei SasFleet pentru Revizii → Schimb ulei.
 *
 *   php scripts/sync_revizii_sas.php                 # rulare programata (recomandat orar)
 *   php scripts/sync_revizii_sas.php --max-calls=50  # buget redus de cereri SAS
 *
 * Aduce doar zilele lipsa / neinchise (azi, ieri, ferestrele cu eroare) de la cel mai
 * vechi baseline de revizie, limitat la backfill_max_zile. Rularile repetate nu
 * dubleaza km sau orele motor (fiecare fereastra isi inlocuieste segmentele).
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
require_once $root . '/htdocs/services/ReviziiSasSyncService.php';

$maxCalls = 500;
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

$log('Pornire sincronizare Revizii SasFleet (max ' . $maxCalls . ' cereri).');
$stats = (new ReviziiSasSyncService($db, $log))->run($maxCalls);
$log($stats['message'] . sprintf(' [cereri=%d, vehicule=%d]', $stats['calls'], $stats['cars']));

exit($stats['aborted'] ? 2 : 0);
