<?php
declare(strict_types=1);

/**
 * Test SAS Fleet API - foaia de parcurs (travelsheet) si evenimente.
 * Doar citire: exploram ce date ofera API-ul pentru automatizarea curselor.
 *
 * Utilizare:
 *   php scripts/test_sas_travelsheet.php                          - travelsheet pentru prima masina, ieri
 *   php scripts/test_sas_travelsheet.php --plate=B123ABC          - masina dupa numar de inmatriculare
 *   php scripts/test_sas_travelsheet.php --start=2026-08-20 --end=2026-08-22
 *   php scripts/test_sas_travelsheet.php --events                 - include si reports/events (brut)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/services/SasFleetClient.php';

function arg(string $name, array $argv): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $a) {
        if (str_starts_with((string) $a, $prefix)) {
            return substr((string) $a, strlen($prefix));
        }
    }
    return null;
}

function flag(string $name, array $argv): bool
{
    return in_array('--' . $name, array_map('strval', $argv), true);
}

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

function outJson(mixed $data): void
{
    out((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

$plateFilter = arg('plate', $argv);
$start = arg('start', $argv) ?? date('Y-m-d', strtotime('-1 day'));
$end = arg('end', $argv) ?? date('Y-m-d');
$withEvents = flag('events', $argv);

$startTime = $start . 'T00:00:00';
$endTime = $end . 'T23:59:59';

$client = new SasFleetClient();
if (!$client->credentialsAvailable()) {
    out('[EROARE] Credentiale SAS lipsa in .env.');
    exit(1);
}

$client->login();
out('Autentificare OK. Interval: ' . $startTime . ' -> ' . $endTime);

$cars = $client->getCars();
if ($cars === []) {
    out('[EROARE] Nicio masina vizibila.');
    exit(1);
}

$normalize = static fn (string $p) => strtoupper(preg_replace('/[^A-Z0-9]/i', '', $p) ?? $p);
$car = null;
if ($plateFilter !== null) {
    foreach ($cars as $c) {
        if ($normalize((string) ($c['licensePlate'] ?? '')) === $normalize($plateFilter)) {
            $car = $c;
            break;
        }
    }
    if ($car === null) {
        out('[EROARE] Nu am gasit masina cu numarul ' . $plateFilter . '. Masini disponibile:');
        foreach ($cars as $c) {
            out('   - ' . (string) ($c['licensePlate'] ?? '?') . ' (carId=' . (string) ($c['carId'] ?? '?') . ')');
        }
        exit(1);
    }
} else {
    // Prima masina activa (nedezactivata)
    foreach ($cars as $c) {
        if (empty($c['disabled'])) {
            $car = $c;
            break;
        }
    }
    $car = $car ?? $cars[0];
}

$carId = (int) ($car['carId'] ?? 0);
out('Masina testata: ' . (string) ($car['licensePlate'] ?? '?') . ' (carId=' . $carId . ', driver=' . (string) ($car['driver'] ?? '-') . ')');
out();

out('=== TRAVELSHEET (foaia de parcurs) - raspuns brut ===');
try {
    $sheet = $client->getTravelSheet($carId, $startTime, $endTime);
    outJson($sheet);
} catch (Throwable $e) {
    out('[EROARE] travelsheet: ' . $e->getMessage());
}
out();

if ($withEvents) {
    out('=== REPORTS/EVENTS - raspuns brut (primele 20 evenimente) ===');
    try {
        $events = $client->getCarEvents($carId, $startTime, $endTime);
        out('Total evenimente: ' . count($events));
        outJson(array_slice($events, 0, 20));
    } catch (Throwable $e) {
        out('[EROARE] events: ' . $e->getMessage());
    }
}

out('=== Gata ===');
