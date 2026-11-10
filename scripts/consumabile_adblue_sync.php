<?php
declare(strict_types=1);

/**
 * Actualizeaza motorina CAN (SAS) pe intervalele dintre alimentarile AdBlue (tab Consumabile → AdBlue).
 * Cere din SAS doar intervalele care lipsesc; intervalele inchise raman salvate.
 *
 *   php scripts/consumabile_adblue_sync.php [max_cereri=60]
 *
 * Potrivit pentru cron / Task Scheduler (o data pe zi e suficient). Ruleaza cu lacat: doua rulari
 * simultane nu se suprapun.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/services/SasFleetClient.php';
require_once $root . '/htdocs/services/FleetLivePositionService.php';
require_once $root . '/htdocs/services/SasDashboardService.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/services/ConsumabileStockService.php';
require_once $root . '/htdocs/services/VehicleFluidService.php';
require_once $root . '/htdocs/services/AdBlueService.php';

$maxCalls = max(1, (int) ($argv[1] ?? 60));
$stats = (new AdBlueService(get_pdo()))->syncCanDiesel([], $maxCalls);
echo date('Y-m-d H:i:s') . ' ' . $stats['message'] . PHP_EOL;
exit($stats['errors'] > 0 || $stats['locked'] ? 1 : 0);
