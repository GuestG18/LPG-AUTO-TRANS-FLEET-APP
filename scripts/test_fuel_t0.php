<?php
declare(strict_types=1);

/**
 * Suita de teste pentru mecanismul FULL / T0 (Etapa 1).
 *
 * SIGURANTA: toate fixture-urile folosesc vehicule sintetice cu prefixul
 * T0TEST-* si api_id-uri cu prefixul "t0test-". Scriptul sterge exclusiv
 * randurile cu aceste prefixe, niciodata date reale. Nu ruleaza niciun
 * TRUNCATE / DROP si nu atinge fuel_fillups pentru alte vehicule.
 *
 * Rulare:  php scripts/test_fuel_t0.php
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

const T0_TEST_PREFIX = 't0test-';
const T0_TEST_VEHICLE_PREFIX = 'T0TEST';

$db = get_pdo();
$model = new FuelModel($db);
$model->ensureSchema();

$passed = 0;
$failed = 0;
$results = [];

function check(string $name, bool $ok, string $observed): void
{
    global $passed, $failed, $results;
    if ($ok) {
        $passed++;
    } else {
        $failed++;
    }
    $results[] = ['name' => $name, 'ok' => $ok, 'observed' => $observed];
    printf("%-58s %s\n    %s\n", $name, $ok ? 'PASS' : 'FAIL', $observed);
}

/** Sterge STRICT fixture-urile de test. */
function cleanupFixtures(PDO $db): void
{
    $db->prepare("DELETE FROM fuel_month_t0 WHERE vehicle_key LIKE :p")
       ->execute([':p' => T0_TEST_VEHICLE_PREFIX . '%']);
    $db->prepare("DELETE FROM fuel_fillups WHERE api_id LIKE :p")
       ->execute([':p' => T0_TEST_PREFIX . '%']);
}

/** Insereaza o alimentare de test si intoarce id-ul. */
function fixture(
    PDO $db,
    string $vehicle,
    string $datetime,
    float $liters,
    ?int $odometer,
    bool $isFull,
    string $tag,
    string $fuelType = 'motorina'
): int {
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("
        INSERT INTO fuel_fillups
            (api_id, vehicle_registration, fuel_type, quantity_liters, odometer_km,
             total_value, fillup_datetime, is_full, is_full_manual, full_source,
             source_type, created_at, updated_at)
        VALUES
            (:api_id, :vehicle, :fuel_type, :liters, :odometer,
             :total_value, :dt, :is_full, NULL, 'api',
             'test', :created_at, :updated_at)
    ");
    $stmt->execute([
        ':api_id' => T0_TEST_PREFIX . $tag,
        ':vehicle' => $vehicle,
        ':fuel_type' => $fuelType,
        ':liters' => $liters,
        ':odometer' => $odometer,
        ':total_value' => $liters * 8.5,
        ':dt' => $datetime,
        ':is_full' => $isFull ? 1 : 0,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int) $db->lastInsertId();
}

function t0DateOf(array $t0): string
{
    return isset($t0['fillup']['fillup_datetime']) ? (string) $t0['fillup']['fillup_datetime'] : '-';
}

echo "=== Teste mecanism FULL / T0 ===\n\n";
cleanupFixtures($db);

// ---------------------------------------------------------------------
// Test 1 — FULL in luna precedenta (31 iulie), luna analizata august
// ---------------------------------------------------------------------
$v = T0_TEST_VEHICLE_PREFIX . '01';
fixture($db, $v, '2026-07-31 09:00:00', 400, 100000, true, 't1-a');
fixture($db, $v, '2026-08-12 09:00:00', 300, 101500, true, 't1-b');
$t0 = $model->resolveT0($v, $model->monthStartFor('2026-08-01'));
check(
    'Test 1 — FULL pe 31 iulie devine T0 pentru august',
    $t0['mode'] === 'auto' && str_starts_with(t0DateOf($t0), '2026-07-31'),
    'mode=' . $t0['mode'] . ' T0=' . t0DateOf($t0) . ' fereastra=' . $t0['window_start'] . ' .. ' . $t0['window_end']
);

// ---------------------------------------------------------------------
// Test 2 — FULL pe 4 ale lunii este eligibil
// ---------------------------------------------------------------------
$v = T0_TEST_VEHICLE_PREFIX . '02';
fixture($db, $v, '2026-08-04 14:00:00', 400, 200000, true, 't2-a');
$t0 = $model->resolveT0($v, $model->monthStartFor('2026-08-01'));
check(
    'Test 2 — FULL pe 4 august este eligibil ca T0',
    $t0['mode'] === 'auto' && str_starts_with(t0DateOf($t0), '2026-08-04'),
    'mode=' . $t0['mode'] . ' T0=' . t0DateOf($t0)
);

// ---------------------------------------------------------------------
// Test 3 — FULL pe 5 ale lunii NU trebuie selectat automat
// ---------------------------------------------------------------------
$v = T0_TEST_VEHICLE_PREFIX . '03';
fixture($db, $v, '2026-08-05 08:00:00', 400, 300000, true, 't3-a');
$t0 = $model->resolveT0($v, $model->monthStartFor('2026-08-01'));
check(
    'Test 3 — FULL pe 5 august NU e selectat; T0 lipsa',
    $t0['mode'] === 'missing' && $t0['fillup'] === null,
    'mode=' . $t0['mode'] . ' mesaj="' . $t0['message'] . '" candidati=' . $t0['candidate_count']
);

// ---------------------------------------------------------------------
// Test 4 — FULL inaintea ferestrei (27 iulie): nu automat, dar manual da
// ---------------------------------------------------------------------
$v = T0_TEST_VEHICLE_PREFIX . '04';
$early = fixture($db, $v, '2026-07-27 08:00:00', 400, 400000, true, 't4-a');
fixture($db, $v, '2026-08-20 08:00:00', 320, 402000, true, 't4-b');
$monthAug = $model->monthStartFor('2026-08-01');
$t0 = $model->resolveT0($v, $monthAug);
$autoRejected = $t0['mode'] === 'missing';

$set = $model->setManualT0($early, $v, $monthAug, false);
$t0after = $model->resolveT0($v, $monthAug);
check(
    'Test 4 — FULL pe 27 iulie: respins automat, acceptat manual',
    $autoRejected && $set['ok'] && $t0after['mode'] === 'manual' && str_starts_with(t0DateOf($t0after), '2026-07-27'),
    'auto=' . $t0['mode'] . ' | manual_ok=' . var_export($set['ok'], true) . ' T0=' . t0DateOf($t0after)
);

// ---------------------------------------------------------------------
// Test 5 — doua FULL-uri (30 iulie / 2 august): cel mai apropiat
// ---------------------------------------------------------------------
$v = T0_TEST_VEHICLE_PREFIX . '05';
fixture($db, $v, '2026-07-30 10:00:00', 400, 500000, true, 't5-a');
fixture($db, $v, '2026-08-02 10:00:00', 400, 501200, true, 't5-b');
$monthStart5 = $model->monthStartFor('2026-08-01');
$cands = $model->getT0WindowCandidates($v, $monthStart5);
$t0 = $model->resolveT0($v, $monthStart5);
$calc = [];
foreach ($cands as $c) {
    $calc[] = substr((string) $c['fillup_datetime'], 0, 16) . ' -> ' . (int) $c['t0_distance_seconds'] . 's';
}
check(
    'Test 5 — doua FULL-uri: se alege cel mai apropiat de 01.08',
    $t0['mode'] === 'auto' && str_starts_with(t0DateOf($t0), '2026-08-02'),
    'ales=' . t0DateOf($t0) . ' | distante fata de 2026-08-01 00:00: ' . implode(', ', $calc)
);

// ---------------------------------------------------------------------
// Test 6 — egalitate exacta: se prefera FULL-ul ANTERIOR inceputului lunii
// ---------------------------------------------------------------------
$v = T0_TEST_VEHICLE_PREFIX . '06';
fixture($db, $v, '2026-07-31 22:00:00', 400, 600000, true, 't6-a');
fixture($db, $v, '2026-08-01 02:00:00', 400, 600100, true, 't6-b');
$monthStart6 = $model->monthStartFor('2026-08-01');
$cands6 = $model->getT0WindowCandidates($v, $monthStart6);
$t0 = $model->resolveT0($v, $monthStart6);
$calc6 = [];
foreach ($cands6 as $c) {
    $calc6[] = substr((string) $c['fillup_datetime'], 0, 16) . ' -> ' . (int) $c['t0_distance_seconds'] . 's';
}
check(
    'Test 6 — egalitate 7200s: castiga FULL-ul dinainte de 01.08',
    $t0['mode'] === 'auto' && str_starts_with(t0DateOf($t0), '2026-07-31'),
    'ales=' . t0DateOf($t0) . ' | ' . implode(', ', $calc6)
);

// ---------------------------------------------------------------------
// Test 7 — T0 manual persista peste doua sincronizari + reload
// ---------------------------------------------------------------------
$v = T0_TEST_VEHICLE_PREFIX . '07';
$manualTarget = fixture($db, $v, '2026-07-20 08:00:00', 400, 700000, false, 't7-a');
fixture($db, $v, '2026-07-31 08:00:00', 380, 701000, true, 't7-b');
fixture($db, $v, '2026-08-15 08:00:00', 360, 702500, true, 't7-c');
$monthAug7 = $model->monthStartFor('2026-08-01');

// Alimentarea aleasa e Partiala -> fara confirmare trebuie sa fie refuzata.
$refused = $model->setManualT0($manualTarget, $v, $monthAug7, false);
$setOk = $model->setManualT0($manualTarget, $v, $monthAug7, true);

// Simuleaza doua sincronizari CardOil care reimporta acelasi rand cu is_full = 0.
$apiRecord = [
    'api_id' => T0_TEST_PREFIX . 't7-a',
    'vehicle_registration' => $v,
    'fuel_type' => 'motorina',
    'quantity_liters' => 400,
    'odometer_km' => 700000,
    'total_value' => 3400,
    'fillup_datetime' => '2026-07-20 08:00:00',
    'is_full' => 0,
];
$model->upsertFillups([$apiRecord]);
$model->upsertFillups([$apiRecord]);

$t0 = $model->resolveT0($v, $monthAug7);
$row = $db->query('SELECT is_full, is_full_manual, full_source FROM fuel_fillups WHERE id = ' . $manualTarget)->fetch();
check(
    'Test 7 — T0 manual persista dupa 2x sync + reload',
    $refused['ok'] === false
        && $setOk['ok'] === true
        && $t0['mode'] === 'manual'
        && (int) $t0['fillup']['id'] === $manualTarget
        && (int) $row['is_full'] === 1,
    'refuz_fara_confirmare=' . var_export($refused['ok'], true)
        . ' | mode=' . $t0['mode'] . ' T0=' . t0DateOf($t0)
        . ' | is_full=' . $row['is_full'] . ' is_full_manual=' . var_export($row['is_full_manual'], true)
        . ' full_source=' . $row['full_source']
);

// ---------------------------------------------------------------------
// Test 8 — FULL manual pe un rand API persista dupa sync
// ---------------------------------------------------------------------
$v = T0_TEST_VEHICLE_PREFIX . '08';
$apiId = T0_TEST_PREFIX . 't8-a';
$fid = fixture($db, $v, '2026-08-06 09:00:00', 410, 800000, false, 't8-a');
$model->setFillupFull($fid, true);
$beforeSync = $db->query('SELECT is_full FROM fuel_fillups WHERE id = ' . $fid)->fetchColumn();

$model->upsertFillups([[
    'api_id' => $apiId,
    'vehicle_registration' => $v,
    'fuel_type' => 'motorina',
    'quantity_liters' => 415.5,      // API actualizeaza cantitatea
    'odometer_km' => 800010,         // si odometrul
    'total_value' => 3531.75,
    'fillup_datetime' => '2026-08-06 09:00:00',
    'is_full' => 0,                  // API nu stie de FULL
]]);

$after = $db->query("SELECT is_full, is_full_manual, full_source, quantity_liters, odometer_km, source_type
                     FROM fuel_fillups WHERE id = {$fid}")->fetch();
check(
    'Test 8 — FULL manual ramane 1 dupa sync, datele API se actualizeaza',
    (int) $beforeSync === 1
        && (int) $after['is_full'] === 1
        && (int) $after['is_full_manual'] === 1
        && (string) $after['full_source'] === 'manual'
        && abs((float) $after['quantity_liters'] - 415.5) < 0.01
        && (int) $after['odometer_km'] === 800010,
    'is_full=' . $after['is_full'] . ' is_full_manual=' . $after['is_full_manual']
        . ' full_source=' . $after['full_source']
        . ' | litri=' . $after['quantity_liters'] . ' odo=' . $after['odometer_km']
        . ' source_type=' . $after['source_type']
);

// ---------------------------------------------------------------------
// Test 9 — schimbare de an: ianuarie 2027 <- decembrie 2026
// ---------------------------------------------------------------------
$v = T0_TEST_VEHICLE_PREFIX . '09';
fixture($db, $v, '2026-12-30 18:00:00', 400, 900000, true, 't9-a');
$monthJan = $model->monthStartFor('2027-01-01');
$win9 = $model->t0Window($monthJan);
$t0 = $model->resolveT0($v, $monthJan);
check(
    'Test 9 — trecerea an: 30.12.2026 e T0 pentru ianuarie 2027',
    $t0['mode'] === 'auto'
        && str_starts_with(t0DateOf($t0), '2026-12-30')
        && $win9['start']->format('Y-m-d') === '2026-12-28'
        && $win9['end']->format('Y-m-d') === '2027-01-04',
    'T0=' . t0DateOf($t0) . ' | fereastra=' . $win9['start']->format('Y-m-d') . ' .. ' . $win9['end']->format('Y-m-d')
);

// ---------------------------------------------------------------------
// Test 10 — februarie: an nebisect vs. an bisect
// ---------------------------------------------------------------------
$winMar2027 = $model->t0Window($model->monthStartFor('2027-03-01')); // 2027 nebisect
$winMar2028 = $model->t0Window($model->monthStartFor('2028-03-01')); // 2028 bisect
$winFeb2028 = $model->t0Window($model->monthStartFor('2028-02-01'));

$v = T0_TEST_VEHICLE_PREFIX . '10';
fixture($db, $v, '2028-02-29 12:00:00', 400, 950000, true, 't10-a');
$t0Leap = $model->resolveT0($v, $model->monthStartFor('2028-03-01'));

check(
    'Test 10 — februarie 28/29 zile calculat dinamic din calendar',
    $winMar2027['start']->format('Y-m-d') === '2027-02-25'
        && $winMar2027['end']->format('Y-m-d') === '2027-03-04'
        && $winMar2028['start']->format('Y-m-d') === '2028-02-26'
        && $winMar2028['end']->format('Y-m-d') === '2028-03-04'
        && $winFeb2028['start']->format('Y-m-d') === '2028-01-28'
        && $winFeb2028['end']->format('Y-m-d') === '2028-02-04'
        && $t0Leap['mode'] === 'auto'
        && str_starts_with(t0DateOf($t0Leap), '2028-02-29'),
    'mar2027=' . $winMar2027['start']->format('d.m') . '-' . $winMar2027['end']->format('d.m')
        . ' | mar2028=' . $winMar2028['start']->format('d.m') . '-' . $winMar2028['end']->format('d.m')
        . ' | feb2028=' . $winFeb2028['start']->format('d.m') . '-' . $winFeb2028['end']->format('d.m')
        . ' | T0 bisect=' . t0DateOf($t0Leap)
);

// ---------------------------------------------------------------------
// Verificari suplimentare de comportament
// ---------------------------------------------------------------------

// A. Un FULL nou importat NU schimba un T0 manual deja aprobat.
$v = T0_TEST_VEHICLE_PREFIX . '11';
$manual11 = fixture($db, $v, '2026-07-18 08:00:00', 400, 960000, true, 't11-a');
$month11 = $model->monthStartFor('2026-08-01');
$model->setManualT0($manual11, $v, $month11, true);
fixture($db, $v, '2026-07-31 23:30:00', 400, 961000, true, 't11-b'); // FULL "perfect" aparut ulterior
$t0 = $model->resolveT0($v, $month11);
check(
    'Extra — un FULL importat ulterior nu suprascrie T0 manual',
    $t0['mode'] === 'manual' && (int) $t0['fillup']['id'] === $manual11,
    'mode=' . $t0['mode'] . ' T0=' . t0DateOf($t0)
);

// B. Revenirea explicita la automat.
$model->clearManualT0($v, $month11);
$t0 = $model->resolveT0($v, $month11);
check(
    'Extra — clear_t0 readuce luna la selectia automata',
    $t0['mode'] === 'auto' && str_starts_with(t0DateOf($t0), '2026-07-31'),
    'mode=' . $t0['mode'] . ' T0=' . t0DateOf($t0)
);

// C. FULL este proprietate a alimentarii, T0 este rol pe luna.
$v = T0_TEST_VEHICLE_PREFIX . '12';
$shared = fixture($db, $v, '2026-04-30 20:00:00', 400, 970000, true, 't12-a');
$t0May = $model->resolveT0($v, $model->monthStartFor('2026-05-01'));
$t0Jun = $model->resolveT0($v, $model->monthStartFor('2026-06-01'));
check(
    'Extra — acelasi FULL e T0 pentru mai, dar nu pentru iunie',
    $t0May['mode'] === 'auto' && (int) $t0May['fillup']['id'] === $shared && $t0Jun['mode'] === 'missing',
    'mai=' . $t0May['mode'] . '/' . t0DateOf($t0May) . ' | iunie=' . $t0Jun['mode']
);

// D. Validari de integritate pentru T0 manual.
$vA = T0_TEST_VEHICLE_PREFIX . '13A';
$vB = T0_TEST_VEHICLE_PREFIX . '13B';
$wrongVehicle = fixture($db, $vB, '2026-07-30 08:00:00', 400, 980000, true, 't13-a');
$adblue = fixture($db, $vA, '2026-07-30 09:00:00', 40, 980500, true, 't13-b', 'adblue');
$future = fixture($db, $vA, '2026-09-15 09:00:00', 400, 981000, true, 't13-c');
$noOdo = fixture($db, $vA, '2026-07-30 10:00:00', 400, null, true, 't13-d');
$month13 = $model->monthStartFor('2026-08-01');

$rWrongVehicle = $model->validateT0Candidate($wrongVehicle, $vA, $month13);
$rAdblue = $model->validateT0Candidate($adblue, $vA, $month13);
$rFuture = $model->validateT0Candidate($future, $vA, $month13);
$rNoOdo = $model->validateT0Candidate($noOdo, $vA, $month13);
$rGhost = $model->validateT0Candidate(999999999, $vA, $month13);

check(
    'Extra — validari: vehicul gresit / AdBlue / dupa luna / inexistent',
    !$rWrongVehicle['ok'] && !$rAdblue['ok'] && !$rFuture['ok'] && !$rGhost['ok']
        && $rNoOdo['ok'] && $rNoOdo['warnings'] !== [],
    'vehicul_gresit=' . var_export($rWrongVehicle['ok'], true)
        . ' adblue=' . var_export($rAdblue['ok'], true)
        . ' dupa_luna=' . var_export($rFuture['ok'], true)
        . ' inexistent=' . var_export($rGhost['ok'], true)
        . ' | fara_odometru: ok=' . var_export($rNoOdo['ok'], true)
        . ' avertisment="' . implode(' ', $rNoOdo['warnings']) . '"'
);

// E. Regresie: sync-ul nu creeaza duplicate si pastreaza api_id unic.
$v = T0_TEST_VEHICLE_PREFIX . '14';
$rec = [
    'api_id' => T0_TEST_PREFIX . 't14-a',
    'vehicle_registration' => $v,
    'fuel_type' => 'motorina',
    'quantity_liters' => 300,
    'odometer_km' => 990000,
    'total_value' => 2550,
    'fillup_datetime' => '2026-08-10 07:00:00',
    'is_full' => 0,
];
$r1 = $model->upsertFillups([$rec]);
$r2 = $model->upsertFillups([$rec]);
$r3 = $model->upsertFillups([$rec]);
$countStmt = $db->prepare('SELECT COUNT(*) FROM fuel_fillups WHERE api_id = :a');
$countStmt->execute([':a' => T0_TEST_PREFIX . 't14-a']);
$dupes = (int) $countStmt->fetchColumn();
check(
    'Regresie — sync repetat: 1 insert, apoi update-uri, fara duplicate',
    $dupes === 1 && $r1['inserted'] === 1 && $r2['updated'] === 1 && $r3['updated'] === 1,
    'randuri=' . $dupes . ' | run1=' . json_encode($r1) . ' run2=' . json_encode($r2) . ' run3=' . json_encode($r3)
);

// F. Regresie: formula FULL->FULL neschimbata (T0 exclus, T1 inclus).
$v = T0_TEST_VEHICLE_PREFIX . '15';
fixture($db, $v, '2026-07-31 08:00:00', 100, 1000000, true, 't15-t0');  // T0
fixture($db, $v, '2026-08-05 08:00:00', 40, 1000400, false, 't15-i1');  // intermediara
fixture($db, $v, '2026-08-10 08:00:00', 35, 1000700, false, 't15-i2');  // intermediara
fixture($db, $v, '2026-08-15 08:00:00', 50, 1001000, true, 't15-t1');   // T1
$reflection = new ReflectionMethod(FuelModel::class, 'getNormativeInterval');
$reflection->setAccessible(true);
$norm = $reflection->invoke($model, [
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-31',
    'vehicle' => $v,
    'vehicles' => [$v],
    'transport_group' => '',
    'fuel_type' => '',
]);
// Asteptat: litri = 40 + 35 + 50 = 125; km = 1001000 - 1000000 = 1000; 12.50 L/100km
check(
    'Regresie — formula FULL->FULL: 125 L / 1000 km = 12,50 L/100 km',
    abs((float) $norm['motorina_liters'] - 125.0) < 0.01
        && abs((float) $norm['km'] - 1000.0) < 0.01
        && abs((float) $norm['norm_l100'] - 12.50) < 0.01
        && $norm['t0_mode'] === 'auto',
    'litri=' . $norm['motorina_liters'] . ' km=' . $norm['km']
        . ' consum=' . $norm['norm_l100'] . ' L/100km | t0_mode=' . $norm['t0_mode']
        . ' status=' . $norm['status']
);

// G. Regresie: status "T0 lipsa" ajunge corect in payload-ul de UI.
$v = T0_TEST_VEHICLE_PREFIX . '16';
fixture($db, $v, '2026-08-09 08:00:00', 400, 1100000, false, 't16-a');
$norm = $reflection->invoke($model, [
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-31',
    'vehicle' => $v,
    'vehicles' => [$v],
    'transport_group' => '',
    'fuel_type' => '',
]);
check(
    'Regresie — fara FULL: status missing_t0 + flag pentru UI',
    $norm['status'] === 'missing_t0'
        && $norm['t0_mode'] === 'missing'
        && $norm['t0_requires_manual'] === true
        && $norm['candidates'] !== [],
    'status=' . $norm['status'] . ' t0_mode=' . $norm['t0_mode']
        . ' requires_manual=' . var_export($norm['t0_requires_manual'], true)
        . ' candidati_pentru_modal=' . count($norm['candidates'])
);

// ---------------------------------------------------------------------
cleanupFixtures($db);

echo "\n=== Rezumat ===\n";
printf("PASS: %d   FAIL: %d\n", $passed, $failed);
foreach ($results as $r) {
    printf("  [%s] %s\n", $r['ok'] ? 'PASS' : 'FAIL', $r['name']);
}

exit($failed > 0 ? 1 : 0);
