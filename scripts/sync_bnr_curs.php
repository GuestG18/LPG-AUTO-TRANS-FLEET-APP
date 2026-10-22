<?php
declare(strict_types=1);

/**
 * Rularea zilnică „Ajustare tarife combustibil & curs BNR”:
 *   1. sincronizează cursul EUR/RON din XML-ul BNR (idempotent, fără dubluri);
 *   2. recalculează indicatorii (medii glisante, motorină lei/EUR, prag);
 *   3. salvează instantaneul zilnic al simulărilor (unul per set de intrări).
 *
 * Nu modifică niciodată tarifele contractuale.
 *
 * Rulare:  php scripts/sync_bnr_curs.php [--years]   (--years reîncarcă anii întregi)
 * Cron VPS: vezi scripts/run_sync_bnr.sh
 * Ieșire: 0 = succes, 1 = eroare BNR (datele existente rămân neatinse).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/config/database.php';
require_once $projectRoot . '/htdocs/models/BaseModel.php';
require_once $projectRoot . '/htdocs/models/TransportTariffModel.php';
require_once $projectRoot . '/htdocs/models/FuelFxAdjustmentModel.php';
require_once $projectRoot . '/htdocs/services/FuelPriceIndexService.php';
require_once $projectRoot . '/htdocs/services/TariffReviewService.php';
require_once $projectRoot . '/htdocs/services/BnrExchangeRateService.php';
require_once $projectRoot . '/htdocs/services/FuelFxAdjustmentService.php';

$db = get_pdo();
$stamp = static fn (): string => '[' . date('Y-m-d H:i:s') . '] ';

$sync = (new BnrExchangeRateService($db))->sync('cron', null, in_array('--years', $argv, true));
echo $stamp() . 'BNR: ' . $sync['status'] . ' — ' . $sync['message']
    . ($sync['latest_rate_date'] ? ' Ultimul curs: ' . $sync['latest_rate_date'] . '.' : '') . PHP_EOL;

try {
    $snapshot = (new FuelFxAdjustmentService($db))->recordAutoSnapshot();
    echo $stamp() . 'Simulare: instantaneu #' . $snapshot['id'] . ($snapshot['created'] ? ' creat' : ' neschimbat (aceleași date)')
        . ($snapshot['purged'] > 0 ? ', ' . $snapshot['purged'] . ' instantanee vechi șterse' : '') . '.' . PHP_EOL;
} catch (Throwable $exception) {
    echo $stamp() . 'Simulare eșuată: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}

exit($sync['status'] === 'error' ? 1 : 0);
