<?php
declare(strict_types=1);

/**
 * Situatia eToll (?page=etoll): registrul portofelului, documentele sesiunilor (anexa TA_10 +
 * desfasuratorul), facturile alimentarilor si soldul. Doar citeste din eToll.
 *
 *   php scripts/sync_etoll_situatie.php                       ultimele 3 zile
 *   php scripts/sync_etoll_situatie.php --days=60 --max-requests=4000
 *                                                             prima rulare: tot istoricul (eToll are
 *                                                             date de la 01.10.2026, ~3 cereri / sesiune);
 *                                                             daca plafonul se atinge, rularea urmatoare continua
 *
 * Detalii in htdocs/services/EtollStatementSyncService.php. Aceleasi chei ca importul eToll:
 * ETOLL_USER, ETOLL_PASS, ETOLL_TOLL_ID. Documentul CC-P al fiecarei sesiuni vine din importul
 * eToll -> Facturi (scripts/import_etoll.php), care trebuie sa ruleze si el.
 *
 * Crontab sugerat (VPS): scripts/run_sync_etoll_situatie.sh la fiecare ora.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/services/EtollImportService.php';
require_once $root . '/htdocs/services/EtollStatementSyncService.php';

$days = 3;
$maxRequests = 400;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--days=(\d+)$/', $argument, $match) === 1) {
        $days = max(1, min(400, (int) $match[1]));
    } elseif (preg_match('/^--max-requests=(\d+)$/', $argument, $match) === 1) {
        $maxRequests = max(20, (int) $match[1]);
    } else {
        fwrite(STDERR, "Argument necunoscut: $argument\nFolosire: php scripts/sync_etoll_situatie.php [--days=N] [--max-requests=N]\n");
        exit(2);
    }
}

$log = static fn(string $message) => print('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");

try {
    $db = get_pdo();
    // etoll_sesiuni (legatura sesiune -> document CC-P) e tabela importului; o cream daca lipseste.
    EtollImportService::ensureSchema($db);
    EtollStatementModel::ensureSchema($db);

    $service = new EtollStatementSyncService($db, EtollApiClient::fromEnv($maxRequests), $root, $log);
    $stats = $service->run($days);
} catch (Throwable $exception) {
    $log('EROARE: ' . $exception->getMessage());
    exit(1);
}

$log(sprintf(
    'Gata (%d zile): tranzactii %d, facturi alimentare noi %d, documente completate %d, sold %s, erori %d, cereri eToll %d.',
    $days,
    $stats['tranzactii'],
    $stats['facturi_alimentare'],
    $stats['documente'],
    $stats['sold'] === null ? '-' : number_format($stats['sold'], 2, '.', ''),
    $stats['erori'],
    $stats['cereri']
));
exit($stats['erori'] > 0 ? 1 : 0);
