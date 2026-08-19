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

/*
 * Moduri de rulare:
 *  (fara argumente)      incremental dupa ID (id_minim = MAX(api_id) din DB),
 *                        cu paginare automata la limita de 1000/cerere.
 *                        Recomandat pentru rularea programata (VPS / cron).
 *                        Fallback: daca baza e goala, fereastra ultimelor 31 zile.
 *  --days=N              fereastra rulanta: ultimele N zile pana azi (chunked).
 *  --from=... --to=...   interval explicit, oricat de lung: se sparge automat in
 *                        ferestre de max 31 zile, injumatatite cand raspunsul
 *                        atinge 1000 de inregistrari. Pentru backfill.
 *  --clear[=all]         sterge inainte de import (intervalul / tot).
 */
$today = new DateTimeImmutable('today');
$fromArg = cardoil_cli_arg('from', $argv);
$toArg = cardoil_cli_arg('to', $argv);
$daysArg = cardoil_cli_arg('days', $argv);
$incrementalMode = $fromArg === null && $toArg === null && $daysArg === null;

if ($daysArg !== null) {
    $days = max(1, (int) $daysArg);
    $dateFrom = $today->modify('-' . ($days - 1) . ' days');
    $dateTo = $today;
} else {
    $dateFrom = cardoil_cli_date($fromArg, $today->modify('-30 days'));
    $dateTo = cardoil_cli_date($toArg, $today);
}
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
echo '[' . $startedAt . '] CardOil sync start: '
    . ($incrementalMode ? 'incremental (dupa ultimul ID din DB)' : $dateFrom->format('Y-m-d') . ' - ' . $dateTo->format('Y-m-d'))
    . PHP_EOL;

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

    $client = new CardOilApiClient();
    $result = $incrementalMode
        ? $model->syncLatestFromApi($client)
        : $model->syncRangeFromApi($dateFrom, $dateTo, $client);

    echo sprintf(
        "[%s] CardOil sync %s (%s): primite=%d inserate=%d actualizate=%d cereri=%d\n",
        date('Y-m-d H:i:s'),
        (string) ($result['status'] ?? 'success'),
        (string) ($result['mode'] ?? 'range'),
        (int) ($result['records_received'] ?? 0),
        (int) ($result['records_inserted'] ?? 0),
        (int) ($result['records_updated'] ?? 0),
        (int) ($result['requests'] ?? 1)
    );

    foreach ((array) ($result['warnings'] ?? []) as $warning) {
        echo '[' . date('Y-m-d H:i:s') . '] Atentie: ' . $warning . PHP_EOL;
    }
    if (!empty($result['error_message'])) {
        echo '[' . date('Y-m-d H:i:s') . '] Info: ' . (string) $result['error_message'] . PHP_EOL;
    }

    exit(((string) ($result['status'] ?? 'success')) === 'error' ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] CardOil sync error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
