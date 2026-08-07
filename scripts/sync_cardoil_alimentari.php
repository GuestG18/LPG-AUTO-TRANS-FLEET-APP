<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/config/database.php';
require_once $projectRoot . '/htdocs/models/BaseModel.php';
require_once $projectRoot . '/htdocs/models/FuelModel.php';
require_once $projectRoot . '/htdocs/services/CardOilApiClient.php';

function cardoil_cli_arg(string $name, array $argv): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }

    return null;
}

function cardoil_cli_date(?string $value, DateTimeImmutable $fallback): DateTimeImmutable
{
    if ($value === null || trim($value) === '') {
        return $fallback;
    }

    try {
        return new DateTimeImmutable(trim($value));
    } catch (Throwable) {
        return $fallback;
    }
}

function cardoil_cli_flag(string $name, array $argv): bool
{
    $flag = '--' . $name;
    foreach ($argv as $arg) {
        $arg = (string) $arg;
        if ($arg === $flag || str_starts_with($arg, $flag . '=')) {
            return true;
        }
    }

    return false;
}

$today = new DateTimeImmutable('today');
$monthFrom = $today->modify('first day of this month');
$monthTo = $today->modify('last day of this month');
$dateFrom = cardoil_cli_date(cardoil_cli_arg('from', $argv), $monthFrom);
$dateTo = cardoil_cli_date(cardoil_cli_arg('to', $argv), $monthTo);
$clearMode = strtolower(trim((string) (cardoil_cli_arg('clear', $argv) ?? '')));
$clearRequested = cardoil_cli_flag('clear', $argv);
$noDemo = cardoil_cli_flag('no-demo', $argv);

if ($noDemo) {
    putenv('CARDOIL_DEMO_MODE=off');
    $_ENV['CARDOIL_DEMO_MODE'] = 'off';
    $_SERVER['CARDOIL_DEMO_MODE'] = 'off';
}

if ($dateTo < $dateFrom) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$startedAt = date('Y-m-d H:i:s');
echo '[' . $startedAt . '] CardOil sync start: ' . $dateFrom->format('Y-m-d') . ' - ' . $dateTo->format('Y-m-d') . PHP_EOL;

try {
    $model = new FuelModel(get_pdo());
    if ($clearRequested) {
        $deleted = $clearMode === 'all'
            ? $model->deleteFillups()
            : $model->deleteFillups($dateFrom, $dateTo);

        echo sprintf(
            "[%s] CardOil clear %s: sterse=%d\n",
            date('Y-m-d H:i:s'),
            $clearMode === 'all' ? 'toate' : ($dateFrom->format('Y-m-d') . ' - ' . $dateTo->format('Y-m-d')),
            $deleted
        );
    }

    $result = $model->syncFromApi($dateFrom, $dateTo, new CardOilApiClient());

    echo sprintf(
        "[%s] CardOil sync %s: primite=%d inserate=%d actualizate=%d\n",
        date('Y-m-d H:i:s'),
        (string) ($result['status'] ?? 'success'),
        (int) ($result['records_received'] ?? 0),
        (int) ($result['records_inserted'] ?? 0),
        (int) ($result['records_updated'] ?? 0)
    );

    if (!empty($result['error_message'])) {
        echo '[' . date('Y-m-d H:i:s') . '] Info: ' . (string) $result['error_message'] . PHP_EOL;
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] CardOil sync error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
