<?php
declare(strict_types=1);

/**
 * Studiu concept "km pierduti": diferenta dintre km reali masurati de GPS (SAS
 * travelsheet totalDistance pe interval) si km inregistrati in curse
 * (SUM km_cursa din curse_dispecer) pentru acelasi vehicul si interval.
 * Km pierduti = GPS - inregistrati => masina exploatata fara venit asociat.
 *
 * Doar citire. Utilizare: php scripts/study_lost_km.php [--start=Y-m-d] [--end=Y-m-d]
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/services/SasFleetClient.php';
require_once $projectRoot . '/htdocs/services/FleetLivePositionService.php';
require_once $projectRoot . '/htdocs/services/SasDashboardService.php';

function argv_val(string $name, array $argv, string $default): string
{
    foreach ($argv as $a) {
        if (str_starts_with((string) $a, '--' . $name . '=')) {
            return substr((string) $a, strlen($name) + 3);
        }
    }
    return $default;
}

$start = argv_val('start', $argv, date('Y-m-d', strtotime('-30 days')));
$end = argv_val('end', $argv, date('Y-m-d'));

$db = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME), DB_USER, DB_PASS);
$client = new SasFleetClient();
$state = json_decode((string) @file_get_contents($projectRoot . '/storage/cache/sas_session.json'), true);
if (is_array($state)) {
    $client->restoreState($state);
}
if (!$client->isAuthenticated()) {
    $client->login();
}

// Mapare numar normalizat -> carId SAS
$carIdByPlate = [];
foreach ($client->getCars() as $car) {
    $key = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $car['licensePlate']) ?? '');
    $carIdByPlate[$key] = (int) $car['carId'];
}
@file_put_contents($projectRoot . '/storage/cache/sas_session.json', json_encode($client->exportState()));

// Vehicule cu curse in interval (candidate)
$sql = "SELECT v.id, v.nr_inmatriculare, COUNT(*) nr, COALESCE(SUM(c.km_cursa),0) km_reg
        FROM curse_dispecer c JOIN vehicule v ON v.id = c.vehicle_id
        WHERE c.deleted_at IS NULL AND c.data_inceput BETWEEN ? AND ?
        GROUP BY v.id ORDER BY km_reg DESC";
$stmt = $db->prepare($sql);
$stmt->execute([$start, $end]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$svc = new SasDashboardService($db);
echo "Interval: $start -> $end\n";
printf("%-12s %7s %7s %8s %6s\n", 'Vehicul', 'GPS', 'Reg', 'Pierdut', '%');
echo str_repeat('-', 48) . "\n";

$totGps = 0.0;
$totReg = 0.0;
foreach ($rows as $row) {
    $plate = (string) $row['nr_inmatriculare'];
    $key = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $plate) ?? '');
    $carId = $carIdByPlate[$key] ?? 0;
    $reg = (float) $row['km_reg'];
    if ($carId === 0) {
        printf("%-12s %7s %7.0f %8s %6s\n", $plate, 'n/a', $reg, '-', '-');
        continue;
    }
    try {
        $r = $svc->getVehicleRange($carId, $start, $end);
        $gps = (float) ($r['total_km'] ?? 0);
    } catch (Throwable $e) {
        printf("%-12s  ERR %s\n", $plate, $e->getMessage());
        continue;
    }
    $lost = $gps - $reg;
    $pct = $gps > 0 ? round($lost / $gps * 100) : 0;
    printf("%-12s %7.0f %7.0f %8.0f %5d%%\n", $plate, $gps, $reg, $lost, $pct);
    $totGps += $gps;
    $totReg += $reg;
    usleep(400000);
}
echo str_repeat('-', 48) . "\n";
$totLost = $totGps - $totReg;
printf("%-12s %7.0f %7.0f %8.0f %5d%%\n", 'TOTAL', $totGps, $totReg, $totLost, $totGps > 0 ? round($totLost / $totGps * 100) : 0);
