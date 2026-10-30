<?php
declare(strict_types=1);

/**
 * Importul eToll: sesiunile de taxare per km inchise -> facturi Toll in pagina "Facturi",
 * cu PDF-ul CNAIR, asociate automat la cursa camionului (nr. auto + ora inchiderii).
 *
 *   php scripts/import_etoll.php                 ultimele 3 zile
 *   php scripts/import_etoll.php --days=30 --max-requests=1500
 *                                                recuperare la prima rulare: eToll are istoric doar
 *                                                de la 01.10.2026 (~25 sesiuni/zi, ~2 cereri/sesiune);
 *                                                daca plafonul se atinge, rularea urmatoare continua
 *   php scripts/import_etoll.php --max-requests=500
 *
 * Detalii in htdocs/services/EtollImportService.php. Cheile din .env:
 * ETOLL_USER, ETOLL_PASS, ETOLL_TOLL_ID (id-ul contului de taxare per km din portal).
 *
 * Crontab sugerat (VPS): scripts/run_import_etoll.sh la fiecare 30 de minute.
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
require_once $root . '/htdocs/services/EtollImportService.php';

$days = 3;
$maxRequests = 300;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--days=(\d+)$/', $argument, $match) === 1) {
        $days = max(1, min(60, (int) $match[1]));
    } elseif (preg_match('/^--max-requests=(\d+)$/', $argument, $match) === 1) {
        $maxRequests = max(10, (int) $match[1]);
    } else {
        fwrite(STDERR, "Argument necunoscut: $argument\nFolosire: php scripts/import_etoll.php [--days=N] [--max-requests=N]\n");
        exit(2);
    }
}

$log = static fn(string $message) => print('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");

try {
    $db = get_pdo();
    $invoices = new InvoiceModel($db);
    $invoices->ensureFacturiSchema();
    EtollImportService::ensureSchema($db);

    $service = new EtollImportService($db, EtollApiClient::fromEnv($maxRequests), $invoices, new InvoiceStorageService($root), $log);
    $stats = $service->run($days);
} catch (Throwable $exception) {
    $log('EROARE: ' . $exception->getMessage());
    exit(1);
}

$log(sprintf(
    'Gata (%d zile): sesiuni noi %d, documente legate %d, facturi create %d, asociate %d, reverificate %d, erori %d, cereri eToll %d.',
    $days,
    $stats['sesiuni_noi'],
    $stats['documente_legate'],
    $stats['facturi_create'],
    $stats['asociate'],
    $stats['reverificate'],
    $stats['erori'],
    $stats['cereri']
));
exit($stats['erori'] > 0 ? 1 : 0);
