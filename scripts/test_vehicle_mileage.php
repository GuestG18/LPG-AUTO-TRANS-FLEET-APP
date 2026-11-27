<?php
declare(strict_types=1);

/**
 * Teste pentru VehicleMileageService (caruselul de kilometraj din Detalii vehicul).
 *
 *   php scripts/test_vehicle_mileage.php
 *
 * Partea 1: reguli pure (serii simulate, fără BD): capete de interval, CardOil pe eveniment,
 *           cuplaje multiple / suprapuse / invalide, intersecția cu perioada, totaluri parțiale.
 * Partea 2: pe baza locală, DOAR CITIRE: valorile „Până în prezent” = regula Centru Kilometraj
 *           pentru toate vehiculele, fiecare perioadă rulează, iar sumele de control ale tabelelor
 *           (vehicule.km_bord / km_revizie, citiri, T0, cuplaje, setări) nu se schimbă.
 */

date_default_timezone_set('Europe/Bucharest');
require_once dirname(__DIR__) . '/htdocs/services/VehicleMileageService.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   {$name}\n";
    } else {
        $failed++;
        echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Serie telemetrică: [segment_start, measured_at (sfârșit segment), km]. */
function series(array $rows): array
{
    return OdometerCalculator::flagAnomalies(array_map(static fn (array $r): array => [
        'id' => 'r' . $r[1], 'segment_started_at' => $r[0], 'measured_at' => $r[1], 'km' => (float) $r[2],
    ], $rows));
}

function fillups(array $rows): array
{
    return OdometerCalculator::flagAnomalies(array_map(static fn (array $r): array => [
        'id' => 'f' . $r[0], 'measured_at' => $r[0], 'km' => (float) $r[1],
    ], $rows));
}

/** Compune calculul pe semiremorcă exact ca VehicleMileageService::trailerSources (o sursă telemetrică). */
function trailerTotal(array $couplings, array $seriesByTractor, ?string $from, string $to, array $conflicts = []): array
{
    $rows = [];
    foreach (VehicleMileageService::buildCouplingIntervals($couplings, $from, $to, $conflicts) as $interval) {
        $row = $interval + ['km' => null, 'calc_status' => 'unavailable', 'reason' => null, 'start_reading' => null, 'end_reading' => null, 'notes' => []];
        if ($interval['status'] !== 'ok') {
            $row['calc_status'] = $interval['status'];
            $rows[] = $row;
            continue;
        }
        $calc = VehicleMileageService::telemetryDistance($seriesByTractor[$interval['tractor_id']] ?? [], $interval['effective_start'], $interval['effective_end'], 15, '2030-01-01 00:00:00');
        $row['km'] = $calc['km'];
        $row['calc_status'] = $calc['status'];
        $row['start_reading'] = $calc['start'];
        $row['end_reading'] = $calc['end'];
        $rows[] = $row;
    }

    return VehicleMileageService::trailerSummary($rows, 'sas');
}

echo "== Perioade\n";
$now = strtotime('2026-11-27 13:15:00');
$p = VehicleMileageService::resolvePeriod('month', null, null, $now);
check('luna curentă', $p['start'] === '2026-11-01 00:00:00' && $p['end'] === '2026-11-27 13:15:00');
$p = VehicleMileageService::resolvePeriod('prev_month', null, null, $now);
check('luna precedentă completă', $p['start'] === '2026-10-01 00:00:00' && $p['end'] === '2026-10-31 23:59:59');
$p = VehicleMileageService::resolvePeriod('year', null, null, $now);
check('anul curent', $p['start'] === '2026-01-01 00:00:00' && $p['end'] === '2026-11-27 13:15:00');
$p = VehicleMileageService::resolvePeriod('custom', '2026-11-20', '2026-11-30', $now);
check('interval personalizat: ziua finală inclusă, dar nu după acum', $p['start'] === '2026-11-20 00:00:00' && $p['end'] === '2026-11-27 13:15:00');
$p = VehicleMileageService::resolvePeriod('custom', '01.10.2026', '21.10.2026', $now);
check('interval personalizat dd.mm.yyyy', $p['end'] === '2026-10-21 23:59:59');
foreach ([['2026-10-21', '2026-10-01', 'invers'], ['2026-12-01', '2026-12-05', 'viitor'], ['', '2026-10-01', 'lipsă'], ['2026-02-30', '2026-03-01', 'dată imposibilă']] as [$f, $t, $why]) {
    $thrown = false;
    try {
        VehicleMileageService::resolvePeriod('custom', $f, $t, $now);
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    check("interval respins ({$why})", $thrown);
}
$p = VehicleMileageService::resolvePeriod('custom', '2026-10-05 03:00', '2026-10-20 18:30', $now);
check('interval cu oră: exact minutul ales (inclusiv)', $p['start'] === '2026-10-05 03:00:00' && $p['end'] === '2026-10-20 18:30:59' && $p['short'] === '05.10.2026 03:00 – 20.10.2026 18:30');
$p = VehicleMileageService::resolvePeriod('custom', '05/10/2026 03:00', '2026-10-05T18:30', $now);
check('interval cu oră: formatele dd/mm/yyyy HH:mm și ISO', $p['start'] === '2026-10-05 03:00:00' && $p['end'] === '2026-10-05 18:30:59');
foreach ([['2026-10-05 18:30', '2026-10-05 03:00', 'aceeași zi, oră inversă'], ['2026-10-05 25:00', '2026-10-06', 'oră invalidă']] as [$f, $t, $why]) {
    $thrown = false;
    try {
        VehicleMileageService::resolvePeriod('custom', $f, $t, $now);
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    check("interval respins ({$why})", $thrown);
}
$r = VehicleMileageService::telemetryDistance(series([['2026-10-27 16:00:00', '2026-10-27 17:00:00', 5000]]), '2026-09-11 00:00:00', '2026-10-04 23:59:59', 15, '2026-11-27 00:00:00', '2026-10-27 17:00:00');
check('interval înaintea istoricului salvat: motivul spune de când există citiri', $r['km'] === null && str_contains((string) $r['reason'], 'începe la 27.10.2026'));
check('perioadă necunoscută = până în prezent', VehicleMileageService::resolvePeriod('xyz', null, null, $now)['absolute'] === true);

echo "== Vehicul direct: SAS / CAN pe interval\n";
$s = series([
    ['2026-09-30 08:00:00', '2026-09-30 10:00:00', 1000],
    ['2026-10-02 08:00:00', '2026-10-02 12:00:00', 1300],
    ['2026-10-20 08:00:00', '2026-10-20 18:00:00', 2000],
    ['2026-11-03 08:00:00', '2026-11-03 10:00:00', 2100],
]);
$r = VehicleMileageService::telemetryDistance($s, '2026-10-01 00:00:00', '2026-10-31 23:59:59', 15, '2026-11-27 00:00:00');
check('parcat la ambele capete: km = final − inițial, complet', $r['km'] === 1000.0 && $r['status'] === 'complete', json_encode($r['km']) . ' ' . $r['status']);
check('capetele folosite sunt raportate', $r['start']['at'] === '2026-09-30 10:00:00' && $r['end']['at'] === '2026-10-20 18:00:00' && $r['start']['precision'] === 'parcat');

$r = VehicleMileageService::telemetryDistance($s, '2026-10-02 10:00:00', '2026-10-31 23:59:59', 15, '2026-11-27 00:00:00');
check('în mers la început: pornește de la prima citire din interval, parțial', $r['km'] === 700.0 && $r['status'] === 'partial');

$r = VehicleMileageService::telemetryDistance($s, '2026-10-21 00:00:00', '2026-10-31 23:59:59', 15, '2026-11-27 00:00:00');
check('fără deplasare în interval: 0 km confirmat', $r['km'] === 0.0 && $r['status'] === 'complete');

$r = VehicleMileageService::telemetryDistance($s, '2026-11-10 00:00:00', '2026-11-27 13:00:00', 15, '2026-11-05 00:00:00');
check('date neacoperite (ultima verificare înainte de interval): indisponibil, nu 0', $r['km'] === null && $r['status'] === 'unavailable');

$r = VehicleMileageService::telemetryDistance($s, '2026-01-01 00:00:00', '2026-10-31 23:59:59', 15, '2026-11-27 00:00:00');
check('fără citire înainte de început: parțial de la prima citire', $r['km'] === 1000.0 && $r['status'] === 'partial');

$r = VehicleMileageService::telemetryDistance([], '2026-10-01 00:00:00', '2026-10-31 23:59:59', 15, null);
check('fără citiri: indisponibil', $r['km'] === null && $r['status'] === 'unavailable');

$reset = series([
    ['2026-10-01 08:00:00', '2026-10-01 10:00:00', 500000],
    ['2026-10-02 08:00:00', '2026-10-02 10:00:00', 500300],
    ['2026-10-03 08:00:00', '2026-10-03 10:00:00', 500600],
    ['2026-10-04 08:00:00', '2026-10-04 10:00:00', 120],
    ['2026-10-05 08:00:00', '2026-10-05 10:00:00', 400],
]);
$r = VehicleMileageService::telemetryDistance($reset, '2026-10-01 11:00:00', '2026-10-06 00:00:00', 15, '2026-11-27 00:00:00');
check('resetare de odometru: nicio diferență negativă, rezultat parțial cu discontinuitate',
    $r['km'] !== null && $r['km'] >= 0 && $r['status'] === 'partial' && str_contains(implode(' ', $r['notes']), 'Discontinuitate'), json_encode($r));
$typo = series([
    ['2026-10-01 08:00:00', '2026-10-01 10:00:00', 1000],
    ['2026-10-02 08:00:00', '2026-10-02 10:00:00', 16777215],
    ['2026-10-03 08:00:00', '2026-10-03 10:00:00', 1400],
]);
$r = VehicleMileageService::telemetryDistance($typo, '2026-10-01 11:00:00', '2026-10-04 00:00:00', 15, '2026-11-27 00:00:00');
check('citire anomală (santinelă) exclusă', $r['km'] === 400.0 && $r['anomalies'] === 1);

echo "== CardOil (eveniment)\n";
$f = fillups([['2026-09-29 12:00:00', 9000], ['2026-10-01 17:00:00', 10000], ['2026-10-15 10:00:00', 11500], ['2026-10-30 12:00:00', 13000], ['2026-11-02 08:00:00', 13300]]);
$r = VehicleMileageService::eventDistance($f, '2026-10-01 00:00:00', '2026-10-31 23:59:59', 72);
check('doar alimentări din interval (nu 29.09 / 02.11)', $r['km'] === 3000.0 && $r['start']['at'] === '2026-10-01 17:00:00' && $r['end']['at'] === '2026-10-30 12:00:00');
check('capete în toleranța de 72 h: complet', $r['status'] === 'complete');
$r = VehicleMileageService::eventDistance($f, '2026-10-01 00:00:00', '2026-10-20 23:59:59', 72);
check('ultima alimentare la >72 h de sfârșit: parțial', $r['km'] === 1500.0 && $r['status'] === 'partial');
$r = VehicleMileageService::eventDistance($f, '2026-10-16 00:00:00', '2026-10-20 00:00:00', 72);
check('nicio alimentare în interval: indisponibil (nu 0)', $r['km'] === null && $r['status'] === 'unavailable');
$r = VehicleMileageService::eventDistance($f, '2026-10-14 00:00:00', '2026-10-20 00:00:00', 72);
check('o singură alimentare: indisponibil', $r['km'] === null);
$ft = fillups([['2026-10-01 17:00:00', 10000], ['2026-10-05 10:00:00', 689930], ['2026-10-10 10:00:00', 10800]]);
$r = VehicleMileageService::eventDistance($ft, '2026-10-01 00:00:00', '2026-10-11 00:00:00', 72);
check('greșeală de tastare la pompă exclusă', $r['km'] === 800.0 && $r['anomalies'] === 1);

echo "== Semiremorcă: istoric de cuplare\n";
// Exemplul din cerință: A 450.000→454.200, B 320.000→325.800, C 610.000→612.850 = 12.850 km.
$tractors = [
    1 => series([['2026-03-01 08:00:00', '2026-03-01 09:00:00', 449000], ['2026-03-02 08:00:00', '2026-03-02 09:00:00', 450000],
        ['2026-03-05 08:00:00', '2026-03-05 18:00:00', 454200], ['2026-03-07 08:00:00', '2026-03-07 18:00:00', 455000]]),
    2 => series([['2026-03-08 08:00:00', '2026-03-08 09:00:00', 320000], ['2026-03-12 08:00:00', '2026-03-12 18:00:00', 325800],
        ['2026-03-14 08:00:00', '2026-03-14 09:00:00', 326100]]),
    3 => series([['2026-03-15 08:00:00', '2026-03-15 09:00:00', 610000], ['2026-03-18 08:00:00', '2026-03-18 18:00:00', 612850],
        ['2026-03-25 08:00:00', '2026-03-25 18:00:00', 613500]]),
];
$couplings = [
    ['id' => 1, 'tractor_id' => 1, 'tractor_plate' => 'A', 'data_start' => '2026-03-03 10:00:00', 'data_end' => '2026-03-06 10:00:00', 'activ' => 0],
    ['id' => 2, 'tractor_id' => 2, 'tractor_plate' => 'B', 'data_start' => '2026-03-08 12:00:00', 'data_end' => '2026-03-13 10:00:00', 'activ' => 0],
    ['id' => 3, 'tractor_id' => 3, 'tractor_plate' => 'C', 'data_start' => '2026-03-16 10:00:00', 'data_end' => '2026-03-20 10:00:00', 'activ' => 0],
];
$sum = trailerTotal($couplings, $tractors, null, '2026-11-27 13:00:00');
check('trei tractoare: total 12.850 km, complet', $sum['total_km'] === 12850.0 && $sum['coverage'] === 'complete', json_encode([$sum['total_km'], $sum['coverage']]));
check('km tractorului înainte / după cuplare excluși (A: 449.000 și 455.000 nu intră)', $sum['intervals'][0]['km'] === 4200.0);
check('decuplată acum: istoricul rămâne (nu se resetează la 0)', $sum['total_km'] > 0 && $sum['analysed'] === 3);

$sum = trailerTotal($couplings, $tractors, '2026-03-21 00:00:00', '2026-03-31 23:59:59');
check('perioadă fără cuplaj: niciun interval, nimic atribuit', $sum['analysed'] === 0 && $sum['total_km'] === null);

$sum = trailerTotal($couplings, $tractors, '2026-03-04 00:00:00', '2026-03-10 23:59:59');
check('perioadă peste două cuplaje: doar porțiunile suprapuse', $sum['analysed'] === 2, (string) $sum['analysed']);

// Cuplaj repetat la același tractor + cuplaj activ.
$rep = [
    ['id' => 10, 'tractor_id' => 1, 'tractor_plate' => 'A', 'data_start' => '2026-03-01 10:00:00', 'data_end' => '2026-03-03 10:00:00', 'activ' => 0],
    ['id' => 11, 'tractor_id' => 1, 'tractor_plate' => 'A', 'data_start' => '2026-03-06 10:00:00', 'data_end' => null, 'activ' => 1],
];
$sum = trailerTotal($rep, $tractors, null, '2026-11-27 13:00:00');
check('cuplaj repetat la același tractor: fiecare interval separat',
    $sum['intervals'][0]['km'] === 1000.0 && $sum['intervals'][1]['km'] === 800.0 && $sum['total_km'] === 1800.0, json_encode(array_column($sum['intervals'], 'km')));
check('cuplaj activ: fără km presupuși după ultima citire', $sum['intervals'][1]['end']['at'] === '2026-03-07 18:00:00');

// Înregistrări suprapuse / duplicate / invalide.
$bad = [
    ['id' => 20, 'tractor_id' => 1, 'tractor_plate' => 'A', 'data_start' => '2026-03-03 10:00:00', 'data_end' => '2026-03-06 10:00:00', 'activ' => 0],
    ['id' => 21, 'tractor_id' => 1, 'tractor_plate' => 'A', 'data_start' => '2026-03-03 10:00:00', 'data_end' => '2026-03-06 10:00:00', 'activ' => 0],
    ['id' => 22, 'tractor_id' => 2, 'tractor_plate' => 'B', 'data_start' => '2026-03-13 10:00:00', 'data_end' => '2026-03-08 12:00:00', 'activ' => 0],
    ['id' => 23, 'tractor_id' => 3, 'tractor_plate' => 'C', 'data_start' => '2026-03-16 10:00:00', 'data_end' => null, 'activ' => 0],
];
$sum = trailerTotal($bad, $tractors, null, '2026-11-27 13:00:00');
$statuses = array_column($sum['intervals'], 'status', 'coupling_id');
check('duplicat: nu se numără de două ori', $sum['total_km'] === 4200.0 && $statuses[21] === 'duplicate', json_encode($statuses));
check('decuplare înainte de cuplare: invalid', $statuses[22] === 'invalid');
check('inactiv fără dată de decuplare: invalid', $statuses[23] === 'invalid');
check('cu înregistrări nerezolvate totalul e PARȚIAL, nu complet', $sum['coverage'] === 'partial' && $sum['conflicts'] === 3);

$overlap = [
    ['id' => 30, 'tractor_id' => 1, 'tractor_plate' => 'A', 'data_start' => '2026-03-03 10:00:00', 'data_end' => '2026-03-06 10:00:00', 'activ' => 0],
    ['id' => 31, 'tractor_id' => 2, 'tractor_plate' => 'B', 'data_start' => '2026-03-05 10:00:00', 'data_end' => '2026-03-13 10:00:00', 'activ' => 0],
];
$intervals = VehicleMileageService::buildCouplingIntervals($overlap, null, '2026-11-27 13:00:00');
check('suprapunere parțială: al doilea pornește de la sfârșitul primului', $intervals[1]['effective_start'] === '2026-03-06 10:00:00');

$sum = trailerTotal($couplings, $tractors, null, '2026-11-27 13:00:00', [2 => 'Atribuire conflictuală']);
check('tractor cuplat simultan la altă semiremorcă: interval exclus, total parțial',
    $sum['total_km'] === 7050.0 && $sum['coverage'] === 'partial', json_encode([$sum['total_km'], $sum['coverage']]));

$sum = trailerTotal($couplings, [1 => $tractors[1]], null, '2026-11-27 13:00:00');
check('lipsă citiri pentru un tractor: subtotal validat, marcat parțial', $sum['total_km'] === 4200.0 && $sum['coverage'] === 'partial' && $sum['calculated'] === 1);

echo "== Baza locală (doar citire)\n";
$env = [];
foreach (file(dirname(__DIR__) . '/.env') ?: [] as $line) {
    if (preg_match('/^(DB_[A-Z]+)=(.*)$/', trim($line), $m)) {
        $env[$m[1]] = $m[2];
    }
}
try {
    $db = new PDO('mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['DB_PORT'] ?? '3306') . ';dbname=' . ($env['DB_NAME'] ?? '') . ';charset=utf8mb4',
        $env['DB_USER'] ?? 'root', $env['DB_PASS'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) {
    echo "  (BD indisponibilă: {$e->getMessage()})\n";
    $db = null;
}

if ($db !== null) {
    $checksum = static function (PDO $db): array {
        $tables = [
            'vehicule (km_bord, km_revizie)' => 'SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS("|", id, km_bord, km_revizie, updated_at))), 0) FROM vehicule',
            'vehicle_odometer_readings' => 'SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS("|", id, odometer_km, validation_status, validation_note))), 0) FROM vehicle_odometer_readings',
            'vehicle_odometer_baselines' => 'SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS("|", id, baseline_value_km, is_active))), 0) FROM vehicle_odometer_baselines',
            'vehicle_odometer_sources' => 'SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS("|", vehicle_id, provider_code, enabled, last_checked_at, updated_at))), 0) FROM vehicle_odometer_sources',
            'vehicule_cuplaje' => 'SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS("|", id, data_start, data_end, activ, updated_at))), 0) FROM vehicule_cuplaje',
            'km_center_setari' => 'SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS("|", setting_key, setting_value))), 0) FROM km_center_setari',
            'fuel_fillups' => 'SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS("|", id, odometer_km, odometer_km_manual))), 0) FROM fuel_fillups',
        ];
        $out = [];
        foreach ($tables as $name => $sql) {
            $out[$name] = implode('/', $db->query($sql)->fetch(PDO::FETCH_NUM));
        }
        return $out;
    };
    $before = $checksum($db);

    // „Până în prezent” = regula Centru Kilometraj (ultimele 80 de citiri, ultima validă) pentru fiecare vehicul.
    $recent = [];
    foreach (['sas', 'can'] as $provider) {
        $stmt = $db->prepare('SELECT vehicle_id, source_reading_id, segment_started_at, measured_at, odometer_km FROM (
            SELECT r.*, ROW_NUMBER() OVER (PARTITION BY vehicle_id ORDER BY measured_at DESC, id DESC) rn FROM vehicle_odometer_readings r WHERE provider_code = :p) x
            WHERE rn <= 80 ORDER BY vehicle_id, measured_at ASC');
        $stmt->execute([':p' => $provider]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recent[$provider][(int) $row['vehicle_id']][] = $row;
        }
    }
    $service = new VehicleMileageService($db);
    $vehicles = $db->query("SELECT id, nr_inmatriculare, tip_vehicul FROM vehicule WHERE nr_inmatriculare <> 'STOC-ANVELOPE' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $mismatch = [];
    $compared = 0;
    $maxQueries = 0;
    $maxMs = 0.0;
    foreach ($vehicles as $vehicle) {
        foreach (array_keys(VehicleMileageService::PERIODS) as $period) {
            $t = microtime(true);
            $service->queries = 0;
            try {
                $result = $period === 'custom'
                    ? $service->forVehicle((int) $vehicle['id'], 'custom', '2026-10-01', '2026-10-21')
                    : $service->forVehicle((int) $vehicle['id'], $period);
            } catch (Throwable $e) {
                $mismatch[] = $vehicle['nr_inmatriculare'] . " {$period}: " . $e->getMessage();
                continue;
            }
            $maxQueries = max($maxQueries, $service->queries);
            $maxMs = max($maxMs, (microtime(true) - $t) * 1000);
            foreach ($result['sources'] as $key => $src) {
                if ($src['value'] !== null && $src['value'] < 0) {
                    $mismatch[] = $vehicle['nr_inmatriculare'] . " {$period} {$key}: negativ";
                }
                if ($src['status'] === 'unavailable' && $src['value'] !== null) {
                    $mismatch[] = $vehicle['nr_inmatriculare'] . " {$period} {$key}: indisponibil cu valoare";
                }
            }
            if ($period !== 'all' || $result['vehicle']['is_trailer']) {
                continue;
            }
            foreach (['sas', 'can'] as $provider) {
                $rows = $recent[$provider][(int) $vehicle['id']] ?? [];
                if ($rows === [] || $result['sources'][$provider]['status'] === 'unavailable' && str_starts_with((string) $result['sources'][$provider]['reason'], 'Sursă')) {
                    continue;
                }
                $current = null;
                foreach (OdometerCalculator::flagAnomalies(array_map(static fn (array $r): array => ['id' => $r['source_reading_id'], 'measured_at' => $r['measured_at'], 'km' => (float) $r['odometer_km']], $rows)) as $r) {
                    if ($r['anomaly'] === null) {
                        $current = $r;
                    }
                }
                $compared++;
                if (($current['km'] ?? null) !== $result['sources'][$provider]['value']) {
                    $mismatch[] = $vehicle['nr_inmatriculare'] . " {$provider}: centru=" . json_encode($current['km'] ?? null) . ' carusel=' . json_encode($result['sources'][$provider]['value']);
                }
            }
        }
    }
    check(sprintf('toate vehiculele × toate perioadele rulează, fără valori negative (%d vehicule)', count($vehicles)), $mismatch === [], implode('; ', array_slice($mismatch, 0, 5)));
    check(sprintf('„Până în prezent” = citirea curentă din Centru Kilometraj (%d comparații)', $compared), $compared > 0 && $mismatch === []);
    check(sprintf('interogări pe cerere ≤ 20 (max %d, cel mai lent %.0f ms)', $maxQueries, $maxMs), $maxQueries <= 20);

    $r79 = $service->forVehicle(79);
    if ($r79['vehicle']['plate'] === 'B 265 NET') {
        echo sprintf("  info B 265 NET: GPS %s (%s), CAN %s, CardOil %s (%s)\n",
            json_encode($r79['sources']['sas']['value']), $r79['sources']['sas']['measured_at'],
            json_encode($r79['sources']['can']['value']), json_encode($r79['sources']['cardoil']['value']), $r79['sources']['cardoil']['measured_at']);
    }

    $after = $checksum($db);
    foreach ($before as $name => $value) {
        check("nimic scris în {$name}", $after[$name] === $value, $value . ' → ' . $after[$name]);
    }
}

echo "\n{$passed} trecute, {$failed} eșuate\n";
exit($failed > 0 ? 1 : 0);
