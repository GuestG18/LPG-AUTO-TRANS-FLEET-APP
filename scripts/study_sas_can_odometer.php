<?php
declare(strict_types=1);

/**
 * Studiu SAS #3: disponibilitatea odometrului (kmIndex) si a datelor CAN
 * (hasCANInfo, CANFuelUsed, fuelLevel) pe toata flota. Doar citire.
 *
 * Interogheaza travelsheet pe ziua de ieri (zi inchisa, date complete) pentru
 * fiecare masina, cu pauza intre cereri ca sa nu atinga rate-limitul SAS.
 *
 * Utilizare: php scripts/study_sas_can_odometer.php [--date=YYYY-MM-DD]
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/services/SasFleetClient.php';

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

$date = null;
foreach ($argv as $a) {
    if (str_starts_with((string) $a, '--date=')) {
        $date = substr((string) $a, 7);
    }
}
$date = $date ?: date('Y-m-d', strtotime('-1 day'));

$client = new SasFleetClient();
$sessionFile = $projectRoot . '/storage/cache/sas_session.json';
if (is_file($sessionFile)) {
    $state = json_decode((string) file_get_contents($sessionFile), true);
    if (is_array($state)) {
        $client->restoreState($state);
    }
}
if (!$client->isAuthenticated()) {
    $client->login();
}

$cars = $client->getCars();
out('Studiu odometru/CAN pe ' . count($cars) . ' masini, ziua ' . $date);
out(sprintf('%-12s %-10s %-9s %-8s %-9s %-11s %-10s %s', 'Numar', 'km azi', 'kmIndex', 'CANinfo', 'CANfuel', 'CANfuel(L)', 'L/100km', 'kmIndexMissing'));

$withKmIndex = 0;
$withCan = 0;
$withCanFuel = 0;
$errors = 0;

foreach ($cars as $car) {
    $carId = (int) ($car['carId'] ?? 0);
    $plate = (string) ($car['licensePlate'] ?? '?');
    try {
        $sheet = $client->getTravelSheet($carId, $date . 'T00:00:00', $date . 'T23:59:59', true);
    } catch (Throwable $e) {
        $errors++;
        out(sprintf('%-12s [EROARE] %s', $plate, $e->getMessage()));
        usleep(700000);
        continue;
    }

    $lastKmIndex = null;
    foreach ((array) ($sheet['segments'] ?? []) as $segment) {
        if (is_array($segment) && is_numeric($segment['kmIndex'] ?? null)) {
            $lastKmIndex = (float) $segment['kmIndex'];
        }
    }

    $hasCan = !empty($sheet['hasCANInfo']);
    $hasCanFuel = !empty($sheet['hasCANTotalFuel']);
    $canFuel = is_numeric($sheet['CANFuelUsed'] ?? null) ? round((float) $sheet['CANFuelUsed'], 1) : null;
    $canFuel100 = is_numeric($sheet['CANFuelUsedPer100Km'] ?? null) ? round((float) $sheet['CANFuelUsedPer100Km'], 1) : null;
    $totalKm = is_numeric($sheet['totalDistance'] ?? null) ? round((float) $sheet['totalDistance'], 1) : 0;

    if ($lastKmIndex !== null) { $withKmIndex++; }
    if ($hasCan) { $withCan++; }
    if ($hasCanFuel || $canFuel !== null) { $withCanFuel++; }

    out(sprintf(
        '%-12s %-10s %-9s %-8s %-9s %-11s %-10s %s',
        $plate,
        (string) $totalKm,
        $lastKmIndex !== null ? (string) round($lastKmIndex) : '-',
        $hasCan ? 'DA' : '-',
        $hasCanFuel ? 'DA' : '-',
        $canFuel !== null ? (string) $canFuel : '-',
        $canFuel100 !== null ? (string) $canFuel100 : '-',
        !empty($sheet['isKmIndexMissing']) ? 'lipsa' : 'ok'
    ));

    // Pauza ca sa nu atingem rate-limitul SAS (~60 cereri/min).
    usleep(700000);
}

out();
out('Sumar: kmIndex=' . $withKmIndex . ', hasCANInfo=' . $withCan . ', CAN fuel=' . $withCanFuel . ', erori=' . $errors . ' din ' . count($cars));

@file_put_contents($sessionFile, json_encode($client->exportState()));
