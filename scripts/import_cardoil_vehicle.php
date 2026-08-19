<?php
declare(strict_types=1);

/**
 * Import CardOil TINTIT pe un singur vehicul.
 *
 * API-ul CardOil nu accepta filtru pe vehicul: raspunsul vine pe interval de
 * date, pentru toata flota. Scriptul cere intervalul, apoi pastreaza local
 * DOAR randurile vehiculului solicitat si le trimite la upsert. Restul
 * raspunsului este ignorat, deci nu ajunge niciodata in baza.
 *
 * Intervalul este spart automat in transe de maximum 31 de zile, pentru ca
 * documentatia CardOil limiteaza o cerere la o luna.
 *
 * Rulare:
 *   php scripts/import_cardoil_vehicle.php --vehicle="B 165 NET" --from=2026-07-01 --to=2027-01-31
 *   php scripts/import_cardoil_vehicle.php --vehicle="B 165 NET" --from=... --to=... --dry-run
 *
 * --dry-run  interogheaza API-ul si raporteaza ce ar importa, fara sa scrie.
 */

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

function cli_arg(string $name, array $argv): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, '--' . $name . '=')) {
            return substr((string) $arg, strlen($name) + 3);
        }
    }

    return null;
}

function cli_flag(string $name, array $argv): bool
{
    foreach ($argv as $arg) {
        if ((string) $arg === '--' . $name) {
            return true;
        }
    }

    return false;
}

function reg_key(string $registration): string
{
    return str_replace(' ', '', strtoupper(trim($registration)));
}

$vehicle = trim((string) (cli_arg('vehicle', $argv) ?? ''));
$fromRaw = trim((string) (cli_arg('from', $argv) ?? ''));
$toRaw = trim((string) (cli_arg('to', $argv) ?? ''));
$dryRun = cli_flag('dry-run', $argv);

if ($vehicle === '' || $fromRaw === '' || $toRaw === '') {
    fwrite(STDERR, "Utilizare: --vehicle=\"B 165 NET\" --from=YYYY-MM-DD --to=YYYY-MM-DD [--dry-run]\n");
    exit(1);
}

try {
    $dateFrom = new DateTimeImmutable($fromRaw);
    $dateTo = new DateTimeImmutable($toRaw);
} catch (Throwable $exception) {
    fwrite(STDERR, "Date invalide: " . $exception->getMessage() . "\n");
    exit(1);
}

if ($dateTo < $dateFrom) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$targetKey = reg_key($vehicle);
$db = get_pdo();
$model = new FuelModel($db);
$model->ensureSchema();
$client = new CardOilApiClient();

if (!$client->credentialsAvailable()) {
    fwrite(STDERR, "Credentialele CardOil lipsesc din .env.\n");
    exit(1);
}

printf(
    "Import CardOil tintit\n  vehicul : %s (cheie %s)\n  perioada: %s -> %s\n  mod     : %s\n\n",
    $vehicle,
    $targetKey,
    $dateFrom->format('d.m.Y'),
    $dateTo->format('d.m.Y'),
    $dryRun ? 'DRY-RUN (nu se scrie nimic)' : 'import real'
);

// ---------------------------------------------------------------------
// Transe de maximum o luna calendaristica.
// ---------------------------------------------------------------------
$chunks = [];
$cursor = $dateFrom;
while ($cursor <= $dateTo) {
    $chunkEnd = $cursor->modify('last day of this month');
    if ($chunkEnd > $dateTo) {
        $chunkEnd = $dateTo;
    }
    $chunks[] = [$cursor, $chunkEnd];
    $cursor = $chunkEnd->modify('+1 day');
}

$totalReceived = 0;
$totalMatched = 0;
$totalInserted = 0;
$totalUpdated = 0;
$allMatched = [];
$otherVehicles = [];
$errors = [];

foreach ($chunks as [$chunkFrom, $chunkTo]) {
    $label = $chunkFrom->format('d.m.Y') . ' - ' . $chunkTo->format('d.m.Y');

    try {
        $result = $client->fetchFillups($chunkFrom, $chunkTo);
    } catch (Throwable $exception) {
        $errors[] = $label . ': ' . $exception->getMessage();
        printf("  %-25s EROARE: %s\n", $label, $exception->getMessage());
        continue;
    }

    $records = (array) ($result['records'] ?? []);
    $source = (string) ($result['source'] ?? 'api');
    $totalReceived += count($records);

    if ($source !== 'api') {
        $errors[] = $label . ': sursa = ' . $source . ' (' . (string) ($result['error'] ?? '') . ')';
    }

    // Pastram STRICT vehiculul cerut.
    $matched = [];
    foreach ($records as $record) {
        $key = reg_key((string) ($record['vehicle_registration'] ?? ''));
        if ($key === $targetKey) {
            $matched[] = $record;
            continue;
        }
        $otherVehicles[$key] = ($otherVehicles[$key] ?? 0) + 1;
    }

    $totalMatched += count($matched);
    $allMatched = array_merge($allMatched, $matched);

    $upsert = ['inserted' => 0, 'updated' => 0];
    if (!$dryRun && $matched !== []) {
        $upsert = $model->upsertFillups($matched);
        $totalInserted += $upsert['inserted'];
        $totalUpdated += $upsert['updated'];
    }

    printf(
        "  %-25s primite=%-4d  %s=%-3d  inserate=%-3d actualizate=%d\n",
        $label,
        count($records),
        $vehicle,
        count($matched),
        $upsert['inserted'],
        $upsert['updated']
    );
}

// ---------------------------------------------------------------------
// Raport
// ---------------------------------------------------------------------
echo "\n=== Rezumat ===\n";
printf("Inregistrari primite de la API (toata flota): %d\n", $totalReceived);
printf("Potrivite pe %s: %d\n", $vehicle, $totalMatched);
if (!$dryRun) {
    printf("Inserate: %d   Actualizate: %d\n", $totalInserted, $totalUpdated);
}
if ($errors !== []) {
    echo "\nProbleme:\n";
    foreach ($errors as $error) {
        echo '  - ' . $error . "\n";
    }
}

if ($allMatched !== []) {
    usort($allMatched, static fn (array $a, array $b): int =>
        strcmp((string) $a['fillup_datetime'], (string) $b['fillup_datetime']));

    echo "\n=== Alimentari " . $vehicle . " ===\n";
    printf("%-19s %-9s %10s %10s %12s %-6s %s\n", 'Data/ora', 'Tip', 'Litri', 'Odometru', 'Valoare', 'Full', 'Statie');
    $sumMotorina = 0.0;
    $sumAdblue = 0.0;
    $sumValue = 0.0;
    $noOdo = 0;
    foreach ($allMatched as $record) {
        $liters = (float) ($record['quantity_liters'] ?? 0);
        $odo = (int) ($record['odometer_km'] ?? 0);
        if ($odo <= 0) {
            $noOdo++;
        }
        if ((string) $record['fuel_type'] === 'adblue') {
            $sumAdblue += $liters;
        } else {
            $sumMotorina += $liters;
        }
        $sumValue += (float) ($record['total_value'] ?? 0);

        printf(
            "%-19s %-9s %10.2f %10s %12.2f %-6s %s\n",
            (string) $record['fillup_datetime'],
            (string) $record['fuel_type'],
            $liters,
            $odo > 0 ? number_format($odo, 0, '.', '') : '-',
            (float) ($record['total_value'] ?? 0),
            !empty($record['is_full']) ? 'DA' : 'nu',
            (string) ($record['station_name'] ?? '-')
        );
    }
    printf(
        "\nTotal: motorina %.2f L | AdBlue %.2f L | valoare %.2f lei | fara odometru: %d\n",
        $sumMotorina,
        $sumAdblue,
        $sumValue,
        $noOdo
    );
}

if ($otherVehicles !== []) {
    arsort($otherVehicles);
    printf("\nIgnorate (alte %d vehicule, %d randuri) - NU au fost scrise in baza.\n",
        count($otherVehicles), array_sum($otherVehicles));
}

exit($errors !== [] ? 1 : 0);
