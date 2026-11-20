<?php
declare(strict_types=1);

// Proces lucrator pentru testul de concurenta din test_fundatie_financiara.php: consuma 1 bucata.
$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
if (!str_contains(DB_NAME, 'test')) {
    exit(1);
}
require_once $root . '/htdocs/services/PartsInventoryService.php';

[$partId, $vehicleId, $tag] = [(int) $argv[1], (int) $argv[2], (string) $argv[3]];
try {
    (new PartsInventoryService(get_pdo()))->consume($partId, 1, ['vehicle_id' => $vehicleId, 'data_montare' => date('Y-m-d')], 'conc-' . $tag);
    echo 'ok';
} catch (Throwable $e) {
    echo 'blocat';
}
