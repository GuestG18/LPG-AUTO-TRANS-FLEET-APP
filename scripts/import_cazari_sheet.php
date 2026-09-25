<?php
declare(strict_types=1);

/*
 * Import automat cazari din Google Sheet — aceeasi logica precum butonul
 * "Importa din Sheet" din pagina Cazare (CazariSheetImportService).
 *
 * Rulat de cron prin scripts/run_import_cazari.sh. Sigur la rulari repetate:
 * randurile deja importate sunt sarite, cele sterse din aplicatie nu se readuc.
 *
 * Coduri de iesire: 0 = import rulat (chiar daca unele randuri au probleme,
 * acestea apar in log), 1 = importul nu a putut rula deloc.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/config/database.php';
require_once $projectRoot . '/htdocs/models/BaseModel.php';
require_once $projectRoot . '/htdocs/models/AccommodationExpenseModel.php';
require_once $projectRoot . '/htdocs/services/CazariSheetImportService.php';

$log = static function (string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
};

try {
    $pdo = get_pdo();
    $model = new AccommodationExpenseModel($pdo);
    $model->ensureSchema();

    $result = (new CazariSheetImportService($pdo, $model))->import(null);

    $log(CazariSheetImportService::summarize($result));
    foreach ($result['errors'] as $error) {
        $log('  ' . $error);
    }

    exit(0);
} catch (Throwable $exception) {
    $log('EROARE: ' . $exception->getMessage());
    exit(1);
}
