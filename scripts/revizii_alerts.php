<?php
declare(strict_types=1);

/**
 * Alertele Revizii (componente „Se apropie” / „Necesită”) ca JSON, pentru workerul de
 * notificari (notification_service/worker.py).
 *
 *   php scripts/revizii_alerts.php
 *
 * Calculul km ramasi exista doar in PHP (ReviziiService: segmente SAS, ore motor, ansamblu),
 * deci workerul il cere de aici in loc sa-l refaca in SQL. Pragul de notificare este cel
 * configurat per componenta in Revizii (prag_avertizare_km, gol = setarea globala).
 * Citeste doar din BD, fara apeluri SasFleet.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/services/ReviziiService.php';

try {
    $db = get_pdo();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $alerts = [];
    foreach ((new ReviziiService($db))->buildOverview()['alerte'] as $alert) {
        $alerts[] = [
            'plan_id' => $alert['plan_id'],
            'baseline_at' => $alert['baseline_at'],
            'vehicle_id' => $alert['vehicle_id'],
            'nr' => $alert['nr'],
            'marca' => $alert['marca'],
            'vehicul_tip' => $alert['vehicul_tip'],
            'categorie' => $alert['categorie'],
            'categorie_label' => $alert['categorie_label'],
            'tip' => $alert['tip'],
            'componenta' => $alert['componenta'],
            'status' => $alert['status'],
            'km_ramasi' => $alert['km_ramasi'],
            'interval_km' => $alert['interval_km'],
            'prag_km' => $alert['prag_km'],
            'complet' => $alert['complet'],
        ];
    }

    echo json_encode(['generated_at' => date('c'), 'alerts' => $alerts], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
