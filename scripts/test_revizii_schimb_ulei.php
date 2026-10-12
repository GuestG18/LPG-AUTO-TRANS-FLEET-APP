<?php
declare(strict_types=1);

/**
 * Teste Revizii → Schimb ulei (calcul km echivalenti din SasFleet).
 *
 *   php scripts/test_revizii_schimb_ulei.php
 *
 * Partea 1: motorul pur (ReviziiCalculator).
 * Partea 2: integrare BD intr-o tranzactie ANULATA la final (vehicule, cuplaje,
 * segmente si planuri de test — nimic nu ramane in baza). Schema se creeaza
 * inainte de tranzactie (DDL face COMMIT implicit).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/services/SasFleetClient.php';
require_once $root . '/htdocs/services/FleetLivePositionService.php';
require_once $root . '/htdocs/services/ReviziiService.php';
require_once $root . '/htdocs/services/ReviziiSasSyncService.php';

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
function near(float $a, float $b, float $eps = 0.05): bool
{
    return abs($a - $b) <= $eps;
}
$ts = static fn (string $s): int => (int) strtotime($s);

echo "Motor de calcul\n";

$r = ReviziiCalculator::evaluate(10000, 0, 7000, 10, 40, 1500, true);
check('10 h motor × 40 = 400 km echivalenți', near($r['km_din_ore'], 400));
check('Km total = km efectuați + km din ore', near($r['km_total'], 7400));
check('Km rămași = interval − total', near($r['km_ramasi'], 2600));
check('Status în regulă', $r['status'] === 'in_regula');

// Segment real SAS (B 165 NET, 06.08.2025): workSeconds 30650 include condusul, idle 3470.
$segment = ['start' => $ts('2025-08-06 11:47:32'), 'end' => $ts('2025-08-06 20:18:22'), 'km' => 486.617, 'idle' => 3470.0];
$norm = ReviziiCalculator::normalizeSegments([$segment]);
$u = ReviziiCalculator::usage($norm['segments'], [[$ts('2025-08-06 00:00'), $ts('2025-08-07 00:00')]], true);
$e = ReviziiCalculator::evaluate(10000, 0, $u['km'], $u['idle_seconds'] / 3600, 40, 1500, true);
check('Condusul nu e dublat: doar idle (0,96 h) se convertește', near($e['km_din_ore'], 3470 / 3600 * 40, 0.1), (string) $e['km_din_ore']);
check('Condusul nu e dublat: workSeconds (8,5 h) nu intră în calcul', $e['km_din_ore'] < 40);

$dup = ReviziiCalculator::normalizeSegments([$segment, $segment]);
check('Segment duplicat numărat o singură dată', count($dup['segments']) === 1);

$a = ['start' => $ts('2026-01-01 10:00'), 'end' => $ts('2026-01-01 12:00'), 'km' => 100.0, 'idle' => 0.0];
$b = ['start' => $ts('2026-01-01 11:00'), 'end' => $ts('2026-01-01 13:00'), 'km' => 100.0, 'idle' => 0.0];
$ov = ReviziiCalculator::normalizeSegments([$a, $b]);
$ovUsage = ReviziiCalculator::usage($ov['segments'], [[$ts('2026-01-01'), $ts('2026-01-02')]], true);
check('Suprapunere parțială tăiată proporțional (100 + 50)', near($ovUsage['km'], 150));

$bad = ReviziiCalculator::normalizeSegments([['start' => $ts('2026-01-01 10:00'), 'end' => $ts('2026-01-01 10:10'), 'km' => 900.0, 'idle' => 0.0]]);
check('Segment implauzibil (900 km în 10 min) respins', $bad['rejected'] === 1 && $bad['segments'] === []);

$half = ReviziiCalculator::usage([$a + ['idle' => 3600.0]], [[$ts('2026-01-01 11:00'), $ts('2026-01-02')]], true);
check('Fereastra taie segmentul proporțional (50 km)', near($half['km'], 50));
$trailer = ReviziiCalculator::usage([['start' => $a['start'], 'end' => $a['end'], 'km' => 100.0, 'idle' => 3600.0]], [[$ts('2026-01-01'), $ts('2026-01-02')]], false);
check('Semi-remorca nu primește ore motor', $trailer['idle_seconds'] == 0.0 && near($trailer['km'], 100));

$missing = ReviziiCalculator::missingDays([[$ts('2026-01-01 10:00'), $ts('2026-01-03 09:00')]], ['2026-01-02' => true]);
check('Zile lipsă detectate (01 și 03)', $missing === ['2026-01-01', '2026-01-03'], implode(',', $missing));

check('Caz 1: 1.500 rămași, prag 1.500 → în regulă', ReviziiCalculator::statusFor(10000, 1500, 1500) === 'in_regula');
check('Caz 2: 1.200 rămași, prag 1.500 → se apropie', ReviziiCalculator::statusFor(10000, 1200, 1500) === 'se_apropie');
check('Caz 2b: pragul e per vehicul (1.800 rămași, prag 2.000 → se apropie)', ReviziiCalculator::statusFor(10000, 1800, 2000) === 'se_apropie');
check('Caz 3: 0 → necesită revizie', ReviziiCalculator::statusFor(10000, 0, 1500) === 'necesita');
check('Caz 3b: depășit (−500) → necesită revizie', ReviziiCalculator::statusFor(10000, -500, 1500) === 'necesita');
check('Fără sursă de telemetrie → date insuficiente (nu „în regulă”)', ReviziiCalculator::evaluate(10000, 0, 0, 0, 40, 1500, false)['status'] === 'fara_date');
$hours = ReviziiCalculator::eventHours([['start' => $ts('2026-10-09 08:00'), 'end' => $ts('2026-10-09 18:00'), 'hours' => 3.0]], [[$ts('2026-10-09 13:00'), $ts('2026-10-10')]]);
check('Ore aspirare proporționale cu fereastra (3 h × 5/10 = 1,5 h)', near($hours, 1.5));
$p = ReviziiCalculator::evaluate(10000, 0, 2500, 0, 40, 1500, true);
check('Progres = km echivalenți / interval × 100 (25%)', near($p['progres'], 25));

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
new ReviziiModel($db); // DDL inainte de tranzactie
$countRevizii = static function () use ($db): string {
    $parts = [];
    foreach (['revizii_planuri', 'revizii_istoric', 'revizii_sas_segmente', 'revizii_sas_acoperire', 'revizii_setari'] as $t) {
        $parts[] = $t . '=' . $db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }
    return implode(',', $parts);
};
$before = $countRevizii();

echo "\nSimulare pe date istorice reale (răspunsuri travelsheet SasFleet salvate în storage/cache)\n";
$cacheFile = $root . '/storage/cache/sas_trip_fuel/9110_20250806114732.json';
$sheet = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
$mappedVehicle = (int) $db->query('SELECT vehicle_id FROM revizii_sas_vehicule WHERE sas_car_id = 9110')->fetchColumn();
if (!is_array($sheet) || $mappedVehicle <= 0) {
    check('Fișierul travelsheet salvat și maparea carId 9110 există', false, 'rulează întâi sync_revizii_sas.php pentru mapare');
} else {
    // Fetcher offline: raspunsul real SAS salvat, trecut prin acelasi parser ca sincronizarea.
    $offline = static function (int $carId, string $from, string $to) use ($sheet): array {
        return array_map(static fn (array $s): array => [
            'start' => (int) strtotime($s['start']), 'end' => (int) strtotime($s['end']), 'km' => (float) $s['km'], 'idle' => (float) $s['idle'],
        ], ReviziiSasSyncService::parseTravelSheet($sheet));
    };
    $sim = (new ReviziiService($db))->simulate($mappedVehicle, '2025-08-06 00:00:00', '2025-08-08 00:00:00',
        ['interval' => 10000, 'warn_km' => 1500, 'factor' => 40.0, 'source' => 'sas_pe_loc'], $offline);
    // Verificare independenta (Python pe acelasi JSON): distance 486,617 km, idle 0,99833 h, work 8,548 h.
    check('Caz 5: km GPS = Σ distance = 486,6', near($sim['calc']['km_efectuati'], 486.6, 0.06), (string) $sim['calc']['km_efectuati']);
    check('Caz 5: ore eligibile = Σ idleEngineSeconds = 1,00 h (nu workSeconds 8,55 h)', near($sim['calc']['ore_motor'], 1.0, 0.01), (string) $sim['calc']['ore_motor']);
    check('Caz 5: km din ore = 0,998 × 40 = 39,9', near($sim['calc']['km_din_ore'], 39.9, 0.06));
    check('Caz 5: km echivalenți 526,5, rămași 9.473,5, în regulă', near($sim['calc']['km_total'], 526.55, 0.1) && near($sim['calc']['km_ramasi'], 9473.45, 0.1) && $sim['calc']['status'] === 'in_regula');
    check('Caz 5: acoperire completă, zilele citite din „API”', $sim['complet'] && $sim['detaliu']['zile_api'] === 2, json_encode($sim['detaliu']));
    check('Caz 5: pașii de calcul generați', count($sim['pasi']) >= 7);

    $down = static function (): array { throw new RuntimeException('HTTP 503'); };
    $sim = (new ReviziiService($db))->simulate($mappedVehicle, '2025-08-06 00:00:00', '2025-08-08 00:00:00',
        ['interval' => 10000, 'warn_km' => 1500, 'factor' => 40.0, 'source' => 'sas_pe_loc'], $down);
    check('Caz 6: API indisponibil → incomplet, zilele raportate lipsă', !$sim['complet'] && count($sim['zile_lipsa']) === 2);
    check('Caz 6: fără telemetrie → marcat „fără date”, nu rezultat valid', $sim['fara_date'] === true);
    check('Caz 6: eroarea SAS e explicată în note', str_contains(implode(' ', $sim['note']), 'HTTP 503'));
}
$aspVehicle = (int) $db->query("SELECT vehicle_id FROM curse_dispecer WHERE id = 811 AND deleted_at IS NULL AND ore_aspirare = 3")->fetchColumn();
if ($aspVehicle > 0) {
    $none = static fn (): array => [];
    $sim = (new ReviziiService($db))->simulate($aspVehicle, '2026-10-09 00:00:00', '2026-10-10 00:00:00',
        ['interval' => 10000, 'warn_km' => 1500, 'factor' => 40.0, 'source' => 'ore_aspirare'], $none);
    check('Regula existentă: cursa compresor #811 (3 h aspirare) → 120 km echivalenți', near($sim['calc']['ore_motor'], 3.0) && near($sim['calc']['km_din_ore'], 120));
}
$historyStart = (string) $db->query('SELECT MIN(data_start) FROM vehicule_cuplaje')->fetchColumn();
$trailerId = (int) $db->query("SELECT semiremorca_id FROM vehicule_cuplaje c JOIN vehicule v ON v.id = c.semiremorca_id AND v.status = 'activ' LIMIT 1")->fetchColumn();
if ($historyStart !== '' && $trailerId > 0) {
    $end = date('Y-m-d H:i:s', strtotime($historyStart) - 86400);
    $sim = (new ReviziiService($db))->simulate($trailerId, date('Y-m-d H:i:s', strtotime($end) - 20 * 86400), $end,
        ['interval' => 10000, 'warn_km' => 1500, 'factor' => 40.0, 'source' => 'ore_aspirare'], null);
    check('Caz 6b: semi-remorcă înainte de istoricul de cuplare → date insuficiente, nu „în regulă” cu 0 km', $sim['calc']['status'] === 'fara_date' && $sim['fara_date'], $sim['calc']['status']);
}
check('Simularea nu a scris nimic în tabelele Revizii', $countRevizii() === $before, $countRevizii());

echo "\nIntegrare BD (tranzacție anulată)\n";
$db->beginTransaction();

try {
    // Testele de mai jos verifica orele SAS pe loc; regula implicita (aspirare) are testul ei.
    (new ReviziiModel($db))->saveSetting('sursa_ore_motor', 'sas_pe_loc', null);
    $insertVehicle = $db->prepare(
        "INSERT INTO vehicule (nr_inmatriculare, marca, model, tip_vehicul, an_fabricatie, km_bord, km_revizie, serie_sasiu, status, created_at, updated_at)
         VALUES (:nr, 'Test', 'RV', :tip, 2020, :km, 777, 'TESTRV', 'activ', NOW(), NOW())"
    );
    $vehicle = static function (string $nr, string $tip, int $km) use ($insertVehicle, $db): int {
        $insertVehicle->execute([':nr' => $nr, ':tip' => $tip, ':km' => $km]);
        return (int) $db->lastInsertId();
    };
    $tractor = $vehicle('TESTRV-T1', 'cap_tractor', 120000);
    $tractor2 = $vehicle('TESTRV-T2', 'cap_tractor', 80000);
    $semi = $vehicle('TESTRV-S1', 'semiremorca_primar', 50000);
    $carId = 990001;
    $db->prepare('INSERT INTO revizii_sas_vehicule (vehicle_id, sas_car_id, plate) VALUES (?, ?, ?)')->execute([$tractor, $carId, 'TESTRV-T1']);

    $couple = $db->prepare('INSERT INTO vehicule_cuplaje (tractor_id, semiremorca_id, activ, data_start, data_end, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    $couple->execute([$tractor, $semi, 0, '2026-09-01 08:00:00', '2026-09-03 08:00:00']);
    $couple->execute([$tractor2, $semi, 1, '2026-09-03 09:00:00', null]);

    $model = new ReviziiModel($db);
    $model->savePlan($tractor, 10000, null, 120000, null);
    $model->savePlan($semi, 20000, null, 50000, null, null, 'placute_semi');
    $db->prepare("UPDATE revizii_planuri SET baseline_at = '2026-09-01 00:00:00' WHERE vehicle_id IN (?, ?)")->execute([$tractor, $semi]);

    $segments = [
        ['start' => '2026-09-01 10:00:00', 'end' => '2026-09-01 12:00:00', 'km' => 100.0, 'work' => 7200, 'idle' => 1800],
        ['start' => '2026-09-02 10:00:00', 'end' => '2026-09-02 11:00:00', 'km' => 50.0, 'work' => 3600, 'idle' => 3600],
        ['start' => '2026-09-03 10:00:00', 'end' => '2026-09-03 11:00:00', 'km' => 40.0, 'work' => 3600, 'idle' => 0],
    ];
    $model->storeWindow($carId, '2026-09-01', '2026-09-03', $segments, true);
    $model->storeWindow($carId, '2026-09-01', '2026-09-03', $segments, true); // resincronizare
    $count = (int) $db->query("SELECT COUNT(*) FROM revizii_sas_segmente WHERE sas_car_id = $carId")->fetchColumn();
    check('Resincronizarea aceleiași ferestre nu dublează segmentele', $count === 3, (string) $count);
    check('Tranzacția de test e încă activă', $db->inTransaction());

    $service = new ReviziiService($db);
    $t = $service->computeComponent($tractor, time());
    check('Cap tractor: km efectuați 190', near($t['calc']['km_efectuati'], 190), (string) $t['calc']['km_efectuati']);
    check('Cap tractor: 1,5 h motor → 60 km', near($t['calc']['km_din_ore'], 60));
    check('Cap tractor: total 250, rămași 9.750', near($t['calc']['km_total'], 250) && near($t['calc']['km_ramasi'], 9750));
    check('Cap tractor: zilele nesincronizate (după 03.09) → date incomplete', !$t['complet'] && in_array(date('Y-m-d'), $t['zile_lipsa'], true));

    $model->storeWindow($carId, '2026-09-04', date('Y-m-d'), [], false);
    $t = (new ReviziiService($db))->computeComponent($tractor, time());
    check('Cap tractor: după acoperirea tuturor zilelor → complet', $t['complet'], implode(',', $t['zile_lipsa']));

    $s = (new ReviziiService($db))->computeComponent($semi, time(), 'placute_semi', 'Set');
    check('Semi: km doar pe intervalul de cuplare cu T1 (150)', near($s['calc']['km_efectuati'], 150), (string) $s['calc']['km_efectuati']);
    check('Semi: fără ore motor', $s['calc']['ore_motor'] == 0.0);
    check('Semi: cuplarea cu un tractor fără SasFleet → date incomplete, nu 0', !$s['complet'] && $s['zile_lipsa'] !== []);

    $oil = (new ReviziiService($db))->buildDashboard();
    $oilKinds = array_unique(array_column($oil['rows'], 'kind'));
    check('Ulei: fără ansambluri și fără semi-remorci (doar cap tractor / camion)', !in_array('ansamblu', $oilKinds, true) && !in_array('semiremorca', $oilKinds, true), implode(',', $oilKinds));
    $semiInOil = false;
    foreach ($oil['rows'] as $row) {
        $semiInOil = $semiInOil || $row['items']['semi'] !== [];
    }
    check('Ulei: niciun rând nu are componente de semi-remorcă', !$semiInOil);
    $err = null;
    try { (new ReviziiService($db))->recordService([['vehicle_id' => $semi, 'tip' => 'schimb_ulei', 'at' => '2026-09-02 10:00', 'km' => null]], null, null); }
    catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('Ulei pe semi-remorcă respins', $err !== null);

    $dashboard = (new ReviziiService($db))->buildDashboard('placute_frana');
    $kinds = [];
    foreach ($dashboard['rows'] as $row) {
        $ids = [$row['tractor']['vehicle_id'] ?? null, $row['semi']['vehicle_id'] ?? null];
        if (in_array($semi, $ids, true)) {
            $kinds['semi'] = [$row['kind'], $row['tractor']['vehicle_id'] ?? null];
        }
        if (in_array($tractor, $ids, true)) {
            $kinds['tractor'] = $row['kind'];
        }
    }
    check('Plăcuțe: rândul ansamblu urmează cuplarea activă (T2 + S1)', ($kinds['semi'] ?? null) === ['ansamblu', $tractor2]);
    check('T1 necuplat apare ca cap tractor individual', ($kinds['tractor'] ?? null) === 'cap_tractor');

    $service = new ReviziiService($db);
    $service->recordService([['vehicle_id' => $tractor, 'tip' => 'schimb_ulei', 'at' => '2026-09-02 10:30:00', 'km' => 119000]], 'test', null);
    $history = $model->getHistory([$tractor]);
    $first = $history[$tractor][0] ?? null;
    check('Istoric: instantaneul la revizie = 100 + 25 km + 1 h × 40 = 165', $first !== null && near((float) $first['km_echivalenti'], 165), (string) ($first['km_echivalenti'] ?? 'null'));
    $kmBord = (int) $db->query("SELECT km_bord FROM vehicule WHERE id = $tractor")->fetchColumn();
    $kmRevizie = (int) $db->query("SELECT km_revizie FROM vehicule WHERE id = $tractor")->fetchColumn();
    check('Odometrul (km_bord) și km_revizie vechi rămân neatinse', $kmBord === 120000 && $kmRevizie === 777);
    $t = (new ReviziiService($db))->computeComponent($tractor, time());
    check('După revizie uzura se reia de la momentul ei (25 + 20 + 40 = 85)', near($t['calc']['km_total'], 85), (string) $t['calc']['km_total']);

    $rejected = false;
    try {
        (new ReviziiService($db))->recordService([['vehicle_id' => $tractor, 'tip' => 'schimb_ulei', 'at' => '2026-09-01 12:00:00', 'km' => 118000]], null, null);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    check('Revizie înaintea ultimei revizii respinsă', $rejected);

    (new ReviziiService($db))->recordService([['vehicle_id' => $tractor, 'tip' => 'schimb_ulei', 'at' => '2026-09-03 12:00:00', 'km' => 119500]], null, null);

    // Ansamblu: cap si semi revizuite la momente diferite, intr-o singura inregistrare.
    $rejectedNoTime = false;
    try {
        (new ReviziiService($db))->recordService([['vehicle_id' => $semi, 'tip' => 'placute_semi', 'at' => '2026-09-02', 'km' => null]], null, null);
    } catch (InvalidArgumentException) {
        $rejectedNoTime = true;
    }
    check('T0 fără oră respins pe componentă', $rejectedNoTime);
    $model->savePlan($tractor2, 80000, null, 80000, null, 5000, 'placute_fata');
    (new ReviziiService($db))->recordService([
        ['vehicle_id' => $tractor2, 'tip' => 'schimb_ulei', 'at' => '2026-09-03 09:30', 'km' => null],
        ['vehicle_id' => $tractor2, 'tip' => 'placute_fata', 'at' => '2026-09-03 11:00', 'km' => null],
        ['vehicle_id' => $semi, 'tip' => 'placute_semi', 'at' => '2026-09-04 16:45', 'km' => null],
    ], 'ansamblu', null);
    $all = $model->getAllPlans();
    check('Fiecare componentă are propriul T0 (ulei cap 03.09 09:30, plăcuțe cap 03.09 11:00, plăcuțe semi 04.09 16:45)',
        (string) $all['schimb_ulei'][$tractor2]['baseline_at'] === '2026-09-03 09:30:00'
        && (string) $all['placute_fata'][$tractor2]['baseline_at'] === '2026-09-03 11:00:00'
        && (string) $all['placute_semi'][$semi]['baseline_at'] === '2026-09-04 16:45:00');
    $history = $model->getHistory([$tractor]);
    check('Istoricul anterior rămâne intact (2 rânduri, primul neschimbat)', count($history[$tractor]) === 2 && near((float) $history[$tractor][1]['km_echivalenti'], 165));

    $model->savePlan($tractor, 12000, null, 120000, null);
    $plan = $model->getPlans()[$tractor];
    check('Schimbarea intervalului nu mută baseline-ul', (string) $plan['baseline_at'] === '2026-09-03 12:00:00' && (int) $plan['interval_km'] === 12000);
    $model->savePlan($tractor, 12000, 115000, 120000, null);
    $plan = $model->getPlans()[$tractor];
    check('Km la ultima revizie → consum inițial din odometru (5.000)', near((float) $plan['consum_initial_km'], 5000) && $plan['consum_initial_sursa'] === 'odometru');
    $model->savePlan($tractor2, 10000, null, 80000, null, 2000);
    check('Prag per componentă salvat (2.000)', (int) $model->getPlans()[$tractor2]['prag_avertizare_km'] === 2000);
    $c = (new ReviziiService($db))->computeComponent($tractor2, time());
    check('Tractor configurat dar fără SasFleet → date insuficiente', $c['status'] === 'fara_date');
    $model->savePlan($tractor, 12000, null, 120000, null, 2000);
    $db->exec("UPDATE revizii_planuri SET consum_initial_km = 10200 WHERE vehicle_id = $tractor");
    $c = (new ReviziiService($db))->computeComponent($tractor, time());
    check('Caz 2 (BD): 1.800 rămași sub pragul propriu de 2.000 → se apropie', $c['status'] === 'se_apropie', $c['status'] . ' ' . json_encode($c['calc']));
    $db->exec("UPDATE revizii_planuri SET consum_initial_km = 12400 WHERE vehicle_id = $tractor");
    $c = (new ReviziiService($db))->computeComponent($tractor, time());
    check('Caz 3 (BD): interval depășit → necesită revizie, km rămași negativi', $c['status'] === 'necesita' && $c['calc']['km_ramasi'] < 0);
    $unconfigured = $vehicle('TESTRV-T3', 'camion', 1000);
    $c = (new ReviziiService($db))->computeComponent($unconfigured, time());
    check('Caz 4: vehicul fără T0 → neconfigurat, fără calcul', $c['status'] === 'neconfigurat' && $c['calc'] === null);
    $d = (new ReviziiService($db))->buildDashboard();
    $order = array_column($d['rows'], 'severity');
    $sorted = $order;
    rsort($sorted);
    check('Rândurile critice și cele care se apropie sunt primele', $order === $sorted);
    // ---- Plăcuțe frână / anvelope: doar km, fără ore motor; componente separate.
    $model->savePlan($tractor, 80000, null, 120000, null, 5000, 'placute_fata');
    $db->exec("UPDATE revizii_planuri SET baseline_at = '2026-09-01 00:00:00' WHERE vehicle_id = $tractor AND tip_revizie = 'placute_fata'");
    $b = (new ReviziiService($db))->computeComponent($tractor, time(), 'placute_fata', 'Față');
    check('Plăcuțe: doar km (190), fără ore motor', near($b['calc']['km_efectuati'], 190) && $b['calc']['km_din_ore'] == 0.0 && near($b['calc']['km_total'], 190), json_encode($b['calc']));
    check('Plăcuțe: ore motor marcate ca neaplicabile', $b['ore_aplicabile'] === false && $b['detaliu']['ore_aplicate'] === false);
    $dash = (new ReviziiService($db))->buildDashboard('placute_frana');
    $found = null;
    foreach ($dash['rows'] as $r) {
        if (($r['tractor']['vehicle_id'] ?? null) === $tractor2) { $found = $r; }
    }
    check('Tab plăcuțe: ansamblul are Față + Spate pe cap și Set pe semi',
        $found !== null && array_column($found['items']['tractor'], 'tip_revizie') === ['placute_fata', 'placute_spate'] && array_column($found['items']['semi'], 'tip_revizie') === ['placute_semi']);
    $err = null;
    try { (new ReviziiService($db))->recordService([['vehicle_id' => $semi, 'tip' => 'placute_fata', 'at' => '2026-09-05 10:00', 'km' => null]], null, null); }
    catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('Semi-remorca nu are plăcuțe „Față” (componentă respinsă)', $err !== null);
    $err = null;
    try { (new ReviziiService($db))->recordService([['vehicle_id' => $tractor2, 'tip' => 'anvelope', 'at' => '2026-09-05 10:00', 'km' => null]], null, null); }
    catch (InvalidArgumentException $e) { $err = $e->getMessage(); }
    check('Anvelope fără interval configurat → cere configurarea întâi', $err !== null && str_contains($err, 'Configurează'));
    $model->savePlan($tractor2, 150000, null, 80000, null, 10000, 'anvelope');
    (new ReviziiService($db))->recordService([['vehicle_id' => $tractor2, 'tip' => 'anvelope', 'at' => '2026-09-02 08:15', 'km' => null]], 'presupus montat', null);
    $tire = $model->getPlans('anvelope')[$tractor2];
    check('Anvelope: T0 „presupus montat” înaintea configurării e acceptat (fără istoric anterior)', (string) $tire['baseline_at'] === '2026-09-02 08:15:00' && (int) $tire['interval_km'] === 150000);
    check('Uleiul nu e afectat de T0-ul anvelopelor', (string) $model->getPlans()[$tractor2]['baseline_at'] === '2026-09-03 09:30:00');
    // ---- Prezentare generală: agregare pe vehicule distincte, cea mai critică stare.
    $ov = (new ReviziiService($db))->buildOverview();
    $k = $ov['kpi'];
    $vehiclesAll = $model->getVehicles();
    $motorCount = count(array_filter($vehiclesAll, static fn (array $v): bool => !is_trailer_vehicle_type((string) $v['tip_vehicul'])));
    check('KPI: stările însumate = total vehicule', $k['in_regula'] + $k['se_apropie'] + $k['necesita'] + $k['fara_date'] + $k['neconfigurat'] === $k['total']);
    check('KPI: fiecare vehicul activ numărat o singură dată', $k['total'] === count($vehiclesAll), $k['total'] . ' vs ' . count($vehiclesAll));
    check('Ulei: populația = doar cap tractor / camion', $ov['categorii']['schimb_ulei']['counts']['total'] === $motorCount);
    check('Plăcuțe / anvelope: populația = toate vehiculele', $ov['categorii']['placute_frana']['counts']['total'] === count($vehiclesAll) && $ov['categorii']['anvelope']['counts']['total'] === count($vehiclesAll));
    $dueVehicles = array_unique(array_column(array_filter($ov['alerte'], static fn (array $a): bool => $a['status'] === 'necesita'), 'vehicle_id'));
    $soonVehicles = array_diff(array_unique(array_column(array_filter($ov['alerte'], static fn (array $a): bool => $a['status'] === 'se_apropie'), 'vehicle_id')), $dueVehicles);
    check('KPI „Necesită intervenție” = vehicule distincte cu cel puțin o depășire', $k['necesita'] === count($dueVehicles), $k['necesita'] . ' vs ' . count($dueVehicles));
    check('KPI „Necesită atenție” = vehicule distincte care se apropie, fără depășiri', $k['se_apropie'] === count($soonVehicles));
    $order = array_map(static fn (array $a): array => [$a['status'] === 'necesita' ? 0 : 1, $a['km_ramasi']], $ov['alerte']);
    $sortedOrder = $order;
    sort($sortedOrder);
    check('Alerte: depășirile primele, apoi cele mai puțini km rămași', $order === $sortedOrder);
    $testDue = array_values(array_filter($ov['alerte'], static fn (array $a): bool => $a['vehicle_id'] === $tractor));
    check('Alertă T1 ulei depășit (interval 12.000, consum 12.400) cu categoria și componenta', $testDue !== [] && $testDue[0]['categorie'] === 'schimb_ulei' && $testDue[0]['componenta'] === 'Ulei motor' && $testDue[0]['status'] === 'necesita');
    $histCount = (int) $db->query('SELECT COUNT(*) FROM revizii_istoric')->fetchColumn();
    check('Istoric unificat: un rând per înregistrare, cele mai noi primele', count($ov['istoric']) === min(5000, $histCount)
        && ($ov['istoric'] === [] || $ov['istoric'][0]['data'] >= end($ov['istoric'])['data']));
    $tireRow = array_values(array_filter($ov['istoric'], static fn (array $h): bool => $h['vehicle_id'] === $tractor2 && $h['categorie'] === 'anvelope'));
    check('Istoric: anvelope cap tractor, acțiune și operator lipsă explicit', $tireRow !== [] && $tireRow[0]['componenta'] === 'Anvelope cap tractor' && $tireRow[0]['actiune'] === 'Înlocuire anvelope' && $tireRow[0]['operator'] === null);
    check('Tranzacția de test e încă activă la final', $db->inTransaction());
} catch (Throwable $exception) {
    check('Fără excepții', false, $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo "\n$passed ok, $failed eșuate\n";
exit($failed > 0 ? 1 : 0);
