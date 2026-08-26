<?php
declare(strict_types=1);

/**
 * Scan read-only: cauta o masina cu segmente in travelsheet in ultimele zile
 * si afiseaza primul raspuns complet, ca sa vedem structura reala a datelor.
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

$client = new SasFleetClient();
$client->login();
$cars = array_values(array_filter($client->getCars(), static fn (array $c) => empty($c['disabled'])));
out('Masini active: ' . count($cars));

$startTime = date('Y-m-d', strtotime('-4 days')) . 'T00:00:00';
$endTime = date('Y-m-d') . 'T23:59:59';
out('Interval: ' . $startTime . ' -> ' . $endTime);
out();

foreach (array_slice($cars, 0, 48) as $car) {
    $carId = (int) ($car['carId'] ?? 0);
    $plate = (string) ($car['licensePlate'] ?? '?');
    try {
        $sheet = $client->getTravelSheet($carId, $startTime, $endTime);
    } catch (Throwable $e) {
        out($plate . ': EROARE ' . $e->getMessage());
        // Pauza scurta ca sa nu lovim rate-limit-ul SAS
        sleep(2);
        continue;
    }

    $segments = is_array($sheet['segments'] ?? null) ? $sheet['segments'] : [];
    out($plate . ' (carId=' . $carId . '): totalDistance=' . (string) ($sheet['totalDistance'] ?? '?')
        . ', segmente=' . count($segments));

    if ($segments !== [] && (float) ($sheet['totalDistance'] ?? 0) > 50) {
        out();
        out('=== Raspuns complet pentru ' . $plate . ' ===');
        out((string) json_encode($sheet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        exit(0);
    }
    sleep(1);
}

out('Nicio masina cu segmente gasita in primele 15.');
