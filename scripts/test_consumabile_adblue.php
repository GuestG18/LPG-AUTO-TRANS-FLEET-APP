<?php
declare(strict_types=1);

/**
 * Teste Consumabile — etapa 3: AdBlue din CardOil + motorina CAN din SAS.
 *
 *   php scripts/test_consumabile_adblue.php
 *
 * Calculele sunt testate pur; sincronizarea foloseste un SAS simulat (nicio cerere reala),
 * intr-o tranzactie ANULATA la final.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/services/ConsumabileStockService.php';
require_once $root . '/htdocs/services/VehicleFluidService.php';
require_once $root . '/htdocs/services/AdBlueService.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$fill = static fn (int $id, string $at, float $liters, int $full = 0, int $odo = 0): array => [
    'id' => $id, 'fillup_datetime' => $at, 'quantity_liters' => (string) $liters, 'is_full' => $full, 'odometer_km' => $odo,
    'vehicle_registration' => 'X', 'station_name' => '', 'driver_name' => '',
];
$cacheRow = static fn (string $from, string $to, ?float $diesel, float $km = 0, int $closed = 1): array => [
    'de_la' => $from, 'pana_la' => $to, 'can_fuel_l' => $diesel, 'km_gps' => $km, 'has_can' => $diesel !== null ? 1 : 0,
    'status' => $diesel !== null ? 'ok' : 'fara_can', 'inchis' => $closed, 'fetched_at' => '2026-11-10 08:00:00',
];

echo "[Zile și intervale]\n";
$fills = [
    $fill(1, '2026-09-01 08:00:00', 20, 1), $fill(2, '2026-09-01 18:00:00', 5, 1),
    $fill(3, '2026-09-11 08:00:00', 30), $fill(4, '2026-09-21 08:00:00', 30), $fill(5, '2026-10-01 08:00:00', 40, 1),
];
$days = AdBlueService::fillDays($fills);
check('două alimentări în aceeași zi = o singură zi de referință', count($days) === 4 && $days[0]['liters'] == 25.0);
$intervals = AdBlueService::intervals($days, '2026-10-10');
check('intervalul deschis lipsește când ultima alimentare e mai veche de 92 de zile', count(AdBlueService::intervals($days, '2027-03-01')) === 3);
check('intervale fără suprapunere: [ziua i, ziua i+1 − 1] + intervalul deschis până azi',
    $intervals[0] === ['from' => '2026-09-01', 'to' => '2026-09-10', 'open' => false]
    && $intervals[2]['to'] === '2026-09-30' && $intervals[3] === ['from' => '2026-10-01', 'to' => '2026-10-10', 'open' => true]);

echo "\n[Consum, raport, rest, autonomie]\n";
$cache = [
    '2026-09-01|2026-09-10' => $cacheRow('2026-09-01', '2026-09-10', 2000, 3300),
    '2026-09-11|2026-09-20' => $cacheRow('2026-09-11', '2026-09-20', 2000, 3300),
    '2026-09-21|2026-09-30' => $cacheRow('2026-09-21', '2026-09-30', 1000, 3400),
    '2026-10-01|2026-10-10' => $cacheRow('2026-10-01', '2026-10-10', 1000, 3000, 0),
];
$ctx = ['capacity' => 60.0, 'fills_to_full' => false, 'today' => '2026-10-10', 'current_can' => null,
    'odometer' => [2 => ['can' => '100000', 'gps' => null], 5 => ['can' => '110000', 'gps' => null]]];
$r = AdBlueService::analyzeVehicle($fills, $cache, $ctx);
check('AdBlue consumat = alimentările de după prima zi (30+30+40 = 100 L)', $r['consumed'] == 100.0);
check('km din odometrul CAN la alimentări: 10.000 km', $r['km'] == 10000 && $r['km_source'] === 'KM CAN');
check('consum estimat 1 L/100 km', $r['l100'] == 1.0);
check('motorina = consumul CAN pe aceleași zile (5.000 L), nu alimentările', $r['diesel'] == 5000.0);
check('raport AdBlue/motorină 2% din istoricul vehiculului', $r['ratio'] == 2.0);
check('prima și ultima alimentare la plin → încredere ridicată', $r['confidence'] === 'ridicat');
check('rest estimat = 60 − 2% × 1.000 L = 40 L', $r['remaining'] == 40.0);
check('autonomie estimată = 40 L / 1 L/100 km = 4.000 km', $r['autonomy_km'] === 4000);
check('nivelul AdBlue CAN rămâne „indisponibil”', $r['level_can'] === 'indisponibil');

$notFull = $fills;
$notFull[4]['is_full'] = 0;
$r = AdBlueService::analyzeVehicle($notFull, $cache, $ctx);
check('ultima alimentare nu e la plin → rest necunoscut, cu motiv', $r['remaining'] === null && str_contains($r['remaining_note'], 'plin'));
check('fără plin la capete → încredere din capacitate (60/100 → scăzută)', $r['confidence'] === 'scazut');
$r = AdBlueService::analyzeVehicle($fills, $cache, ['capacity' => null] + $ctx);
check('capacitate neconfigurată → fără rest inventat', $r['remaining'] === null && str_contains($r['remaining_note'], 'Capacitatea'));
$partial = $cache;
unset($partial['2026-09-11|2026-09-20']);
$r = AdBlueService::analyzeVehicle($fills, $partial, $ctx);
check('un interval fără motorină CAN → fără raport (nu se completează cu un procent generic)', $r['ratio'] === null && $r['can_missing'] === 1);
$r = AdBlueService::analyzeVehicle($fills, [], ['odometer' => []] + $ctx);
check('fără CAN și fără GPS → km din odometrul pompei sau necunoscut', $r['km'] === null && $r['l100'] === null);
$r = AdBlueService::analyzeVehicle([$fill(9, '2026-10-01 08:00:00', 20)], [], $ctx);
check('o singură zi cu alimentare → date insuficiente', $r['consumed'] === null && $r['confidence'] === null);

echo "\n[Anomalii]\n";
$big = $fills;
$big[3]['quantity_liters'] = '95';
$r = AdBlueService::analyzeVehicle($big, $cache, $ctx);
check('alimentare peste capacitatea rezervorului → semnalată (vehicul greșit?)', count(array_filter($r['anomalies'], static fn ($a) => str_contains($a, 'peste capacitatea'))) === 1);
$series = [];
$seriesCache = [];
$date = new DateTimeImmutable('2026-05-01');
for ($i = 0; $i < 10; $i++) {
    $series[] = $fill(100 + $i, $date->format('Y-m-d') . ' 08:00:00', $i >= 7 ? 10 : 40);
    $next = $date->modify('+10 days');
    $seriesCache[$date->format('Y-m-d') . '|' . $next->modify('-1 day')->format('Y-m-d')] = $cacheRow($date->format('Y-m-d'), $next->modify('-1 day')->format('Y-m-d'), 1000, 3000);
    $date = $next;
}
$r = AdBlueService::analyzeVehicle($series, $seriesCache, ['capacity' => 60.0, 'fills_to_full' => false, 'today' => '2026-08-20', 'current_can' => null, 'odometer' => []]);
check('raport recent mult sub istoricul vehiculului → anomalie (SCR / card)', count(array_filter($r['anomalies'], static fn ($a) => str_contains($a, 'Ultimele 3 intervale'))) === 1, json_encode($r['anomalies']));
$steady = array_map(static fn ($f) => ['quantity_liters' => '40'] + $f, $series);
$r = AdBlueService::analyzeVehicle($steady, $seriesCache, ['capacity' => 60.0, 'fills_to_full' => false, 'today' => '2026-08-20', 'current_can' => null, 'odometer' => []]);
check('consum constant → fără anomalii', $r['anomalies'] === []);

// -------------------------------------------------------------------------------------------
echo "\n[Sincronizare motorină CAN — SAS simulat, tranzacție anulată]\n";
$db = get_pdo();
$service = new AdBlueService($db);
$service->ensureSchema();
$vehicle = $db->query("SELECT v.id, v.nr_inmatriculare FROM vehicule v JOIN vehicle_odometer_sources s ON s.vehicle_id = v.id AND s.provider_code = 'sas' AND s.enabled = 1
    WHERE v.status = 'activ' ORDER BY v.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($vehicle === false) {
    echo "  (sărit: niciun vehicul legat la SAS)\n";
} else {
    $db->beginTransaction();
    try {
        $insert = $db->prepare("INSERT INTO fuel_fillups (api_id, vehicle_registration, fuel_type, quantity_liters, fillup_datetime, is_full, total_value, created_at, updated_at)
            VALUES (:api, :plate, 'adblue', :q, :at, 0, 0, NOW(), NOW())");
        foreach ([['2020-03-01 08:00:00', 30], ['2020-03-11 08:00:00', 35], ['2020-03-21 08:00:00', 40], ['2020-03-21 09:00:00', 5]] as $index => [$at, $q]) {
            $insert->execute([':api' => 'testadblue-' . bin2hex(random_bytes(6)) . $index, ':plate' => $vehicle['nr_inmatriculare'], ':q' => $q, ':at' => $at]);
        }
        $calls = [];
        $fake = static function (int $carId, string $from, string $to) use (&$calls): array {
            $calls[] = $from . '|' . $to;
            return ['has_can' => true, 'can_fuel_l' => 1500.0, 'total_km' => 4500.0];
        };
        $filters = ['vehicle_ids' => [(int) $vehicle['id']], 'from' => '2020-02-01', 'to' => '2020-03-31'];
        $first = $service->syncCanDiesel($filters, 30, $fake);
        check('intervalele lipsă se cer din SAS (2 închise + 1 deschis până la sfârșitul perioadei)', $first['stored'] === 3 && count($calls) === 3, json_encode([$first, $calls]));
        $before = count($calls);
        $second = $service->syncCanDiesel($filters, 30, $fake);
        check('a doua rulare nu mai cere intervalele închise (fără cereri redundante)', count($calls) - $before <= 1 && $second['stored'] <= 1, json_encode($second));
        $limited = $service->syncCanDiesel(['vehicle_ids' => [(int) $vehicle['id']], 'from' => '2020-02-01', 'to' => '2020-03-31'], 0, $fake);
        check('limita de cereri pe rulare e respectată', $limited['calls'] === 0);
        $analysis = $service->analysis($filters);
        $row = $analysis['rows'][0] ?? null;
        check('analiza folosește intervalele salvate: 80 L AdBlue / 3.000 L motorină', $row !== null && $row['consumed'] == 80.0 && $row['diesel'] == 3000.0 && abs($row['ratio'] - 2.67) < 0.01,
            json_encode($row ? [$row['consumed'], $row['diesel'], $row['ratio']] : null));
        check('km din foile de parcurs SAS când lipsește CAN la alimentări', $row !== null && in_array($row['km_source'], ['KM GPS (SAS)', 'KM CAN'], true));
    } catch (Throwable $exception) {
        check('fără excepții neașteptate', false, $exception->getMessage() . ' @ ' . $exception->getLine());
    } finally {
        $db->rollBack();
    }
}

echo "\n$passed ok, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
