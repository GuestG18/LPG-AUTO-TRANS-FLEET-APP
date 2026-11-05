<?php
declare(strict_types=1);

/**
 * Km din CAN (odometrul de bord) pentru un vehicul, din raportul web SAS
 * „Foaie de parcurs cu distanțe din CAN”, afisat ca JSON.
 *
 *   php scripts/sas_can_km.php "B 105 NET" 2026-10-30 2026-11-01
 *   php scripts/sas_can_km.php 9118 "2026-10-30 06:00" "2026-10-30 18:00"   (carId SAS)
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/services/SasFleetClient.php';
require_once $projectRoot . '/htdocs/services/SasCanKmService.php';

[$vehicle, $from, $to] = array_pad(array_slice($argv, 1), 3, null);
if ($vehicle === null || $from === null) {
    fwrite(STDERR, "Utilizare: php scripts/sas_can_km.php <nr. inmatriculare | carId SAS> <de la> [pana la]\n");
    exit(2);
}

try {
    $client = new SasFleetClient();
    $carId = ctype_digit($vehicle) ? (int) $vehicle : 0;
    if ($carId === 0) {
        $wanted = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $vehicle));
        foreach ($client->getCars() as $car) {
            if (strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($car['licensePlate'] ?? ''))) === $wanted) {
                $carId = (int) $car['carId'];
                break;
            }
        }
    }
    if ($carId === 0) {
        throw new InvalidArgumentException("Vehiculul „{$vehicle}” nu există în SAS Fleet.");
    }

    $report = (new SasCanKmService($client))->travelSheet($carId, $from, $to ?? $from);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Eroare: ' . $exception->getMessage() . "\n");
    exit(1);
}
