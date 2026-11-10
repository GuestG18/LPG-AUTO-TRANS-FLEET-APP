<?php
declare(strict_types=1);

/**
 * Alertele Consumabile & Fluide Tehnice ca JSON, pentru workerul de notificari
 * (notification_service/worker.py).
 *
 *   php scripts/consumabile_alerts.php
 *
 * Recalculeaza intai conditiile (deschide alertele noi, pastreaza-le pe cele existente, inchide
 * automat ce s-a rezolvat), apoi intoarce alertele deschise. Citeste doar din BD: nicio cerere
 * SasFleet / CardOil (telemetria si alimentarile sunt cele deja sincronizate).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/ReviziiModel.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/services/ConsumabileStockService.php';
require_once $root . '/htdocs/services/VehicleFluidService.php';
require_once $root . '/htdocs/services/AdBlueService.php';
require_once $root . '/htdocs/services/ConsumabileAlertService.php';

try {
    $db = get_pdo();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    (new ConsumabileStockService($db))->ensureSchema();
    (new AdBlueService($db))->ensureSchema();
    $service = new ConsumabileAlertService($db);
    $stats = $service->evaluate();

    $alerts = [];
    foreach ($service->openAlerts() as $alert) {
        $alerts[] = [
            'id' => (int) $alert['id'],
            'tip' => (string) $alert['tip'],
            'severitate' => (string) $alert['severitate'],
            'vehicle_id' => $alert['vehicle_id'] !== null ? (int) $alert['vehicle_id'] : 0,
            'nr' => (string) ($alert['nr_inmatriculare'] ?? ''),
            'titlu' => (string) $alert['titlu'],
            'subiect' => (string) $alert['subiect'],
            'detalii' => (string) ($alert['detalii'] ?? ''),
            'recomandare' => (string) ($alert['recomandare'] ?? ''),
            'link_sub' => (string) $alert['link_sub'],
            'deschisa_la' => (string) $alert['deschisa_la'],
        ];
    }

    echo json_encode(['generated_at' => date('c'), 'stats' => $stats, 'alerts' => $alerts], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
