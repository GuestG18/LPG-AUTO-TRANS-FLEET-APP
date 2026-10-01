<?php
declare(strict_types=1);

/**
 * Reverifica asocierea facturilor in asteptare (pagina "Facturi") pentru cron.
 *
 *   php scripts/rematch_facturi.php              cel mult 500 de facturi
 *   php scripts/rematch_facturi.php --limit=100
 *
 * Aceeasi logica ca la deschiderea paginii:
 *   1. cazarile in asteptare se reasociaza de modulul Cazare si toate cazarile se aduc
 *      in Facturi (InvoiceModel::syncLegacyCazare) - inclusiv cele venite din Sheet;
 *   2. facturile neasociate / de verificat si cele asociate a caror cursa sau cheltuiala
 *      a disparut se reverifica (InvoiceModel::rematchPending). Sunt ocolite facturile
 *      dezasociate manual, respinse si sterse.
 *
 * Crontab sugerat (VPS), la fiecare ora:
 *   17 * * * * cd /srv/apps/LPG-AUTO-TRANS-FLEET-APP && php scripts/rematch_facturi.php >> storage/logs/facturi_rematch.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/InvoiceModel.php';

$limit = 500;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $match) === 1) {
        $limit = max(1, (int) $match[1]);
    } else {
        fwrite(STDERR, "Argument necunoscut: $argument\nFolosire: php scripts/rematch_facturi.php [--limit=N]\n");
        exit(2);
    }
}

try {
    $db = get_pdo();
    $model = new InvoiceModel($db);
    $model->ensureFacturiSchema();

    // Cazarile: le reasociaza modulul Cazare, apoi se aduc toate in Facturi.
    $accommodation = (new AccommodationExpenseModel($db))->rematchPending();
    $legacy = $model->syncLegacyCazare(true);
    printf("[%s] Cazari reverificate: %d (asociate: %d); in Facturi: %d noi, %d actualizate, %d scoase\n",
        date('Y-m-d H:i:s'), $accommodation['procesate'], $accommodation['asociate'], $legacy['noi'], $legacy['actualizate'], $legacy['sterse']);
    foreach ($legacy['erori'] as $error) {
        fwrite(STDERR, '  ' . $error . "\n");
    }

    $result = $model->rematchPending($limit);
    printf("[%s] Facturi reverificate: %d, asociate la cursa: %d\n", date('Y-m-d H:i:s'), $result['procesate'], $result['asociate']);
    exit($legacy['erori'] === [] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("[%s] Eroare: %s\n", date('Y-m-d H:i:s'), $exception->getMessage()));
    exit(1);
}
