<?php
declare(strict_types=1);

/**
 * Teste Revizii V2 (scenariile A–L din specificatie).
 *  - partea 1: logica pura (ReviziiV2Engine) — fara BD;
 *  - partea 2: fluxul complet pe BD reala, intr-o tranzactie ANULATA la final (vehicule,
 *    citiri de odometru, planuri, lucrari, stoc de test — nimic nu ramane).
 *
 * Rulare: php scripts/test_revizii_v2.php
 */
$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/includes/audit_context.php';
require_once $root . '/htdocs/services/ReviziiV2Service.php';
require_once $root . '/htdocs/services/VehicleFluidService.php';
require_once $root . '/htdocs/services/ConsumabileStockService.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/services/ReviziiService.php';

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
function expectError(callable $fn, string $contains = ''): string
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $contains === '' || str_contains($e->getMessage(), $contains) ? 'ok' : 'msg: ' . $e->getMessage();
    }
    return 'no error';
}

$now = time();
echo "== Partea 1: logica pura\n";

// Secventa X / Y pe km (fara ciclu fix)
$seqLabels = static fn (array $s): string => implode(',', array_map(static fn (array $e): string => $e['label'] . '@' . (int) ($e['km'] / 1000), $s['sequence']));
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 0, 'y_interval_km' => 250000, 'y_base_km' => 0, 'tolerance_km' => 6000]);
check('X 60k / Y 250k: 4 X apoi Y (Y între X4 și X5)', $seqLabels($s) === 'X1@60,X2@120,X3@180,X4@240,Y@250' && $s['x_before_y'] === 4, $seqLabels($s));
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 0, 'y_interval_km' => 300000, 'y_base_km' => 0, 'tolerance_km' => 6000]);
check('X 60k / Y 300k: Y coincide cu a 5-a X → o singură Y', $seqLabels($s) === 'X1@60,X2@120,X3@180,X4@240,Y@300' && end($s['sequence'])['merged'] === true, $seqLabels($s));
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 0, 'y_interval_km' => 330000, 'y_base_km' => 0, 'tolerance_km' => 6000]);
check('X 60k / Y 330k: 5 X apoi Y (numărul de X rezultă din km)', $s['x_before_y'] === 5, $seqLabels($s));
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 240000, 'y_interval_km' => 250000, 'y_base_km' => 0, 'tolerance_km' => 6000, 'x_done_since_y' => 4]);
check('Y înaintea următoarei X → urmează Y la ținta ei', $s['type'] === 'Y' && $s['km_target'] == 250000 && $s['y_between'] && !$s['merged']);
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 240000, 'y_interval_km' => 296000, 'y_base_km' => 0, 'tolerance_km' => 6000]);
check('Y cu 4.000 km înaintea X (în toleranță) → coincid, o singură Y', $s['type'] === 'Y' && $s['merged'] && $s['km_target'] == 296000);
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 240000, 'y_interval_km' => 305000, 'y_base_km' => 0, 'tolerance_km' => 6000]);
check('Y cu 5.000 km după X (în toleranță) → Y adusă la ținta X', $s['type'] === 'Y' && $s['merged'] && $s['km_target'] == 300000);
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 250000, 'x_origin_km' => 0, 'y_interval_km' => 250000, 'y_base_km' => 250000, 'policy' => 'reset']);
check('după Y la 250k, politica reset: X1 la 310k, următoarea Y la 500k', $s['type'] === 'X' && $s['label'] === 'X1' && $s['km_target'] == 310000 && $s['y_target'] == 500000);
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 250000, 'x_origin_km' => 0, 'y_interval_km' => 250000, 'y_base_km' => 250000, 'policy' => 'grila']);
check('după Y la 250k, politica grilă: X1 la 300k (grila inițială)', $s['km_target'] == 300000);
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 290000, 'x_origin_km' => 0, 'y_interval_km' => 250000, 'y_base_km' => 290000, 'policy' => 'grila']);
check('grilă: punctul prea apropiat (300k după Y la 290k) se sare → 360k', $s['km_target'] == 360000);
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 100000, 'y_threshold_km' => 300000, 'y_interval_km' => 300000]);
check('prag absolut pentru prima Y (fără Y anterioară)', $s['y_target'] == 300000);
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 100000]);
check('fără Y configurată: doar X, cu notă', $s['type'] === 'X' && $s['x_before_y'] === null && $s['notes'] !== []);
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 0, 'y_interval_km' => 250000, 'y_base_km' => 0]);
check('toleranța implicită = 10% din intervalul X', $s['tolerance_km'] === 6000);
$s = ReviziiV2Engine::schedule(['x_interval_km' => 60000, 'x_base_km' => 120000, 'y_interval_km' => 250000, 'y_base_km' => 0, 'x_done_since_y' => 2,
    'done' => [['type' => 'Y', 'label' => 'Y', 'km' => 0, 'date' => null], ['type' => 'X', 'label' => 'X1', 'km' => 60000, 'date' => null], ['type' => 'X', 'label' => 'X2', 'km' => 120000, 'date' => null]]]);
check('secvența reală: efectuate + proiectate până la Y', implode(',', array_column($s['sequence'], 'label')) === 'Y,X1,X2,X3,X4,Y' && $s['sequence'][3]['state'] === 'next');
check('motorul nu mai are noțiunea de ciclu fix', !method_exists('ReviziiV2Engine', 'cycleState') && !str_contains((string) file_get_contents($root . '/htdocs/services/ReviziiV2Engine.php'), '5 + 1'));

// Scenariul C — calendar
$e = ReviziiV2Engine::evaluate(['interval_luni' => 12], ['baseline_date' => '2026-01-15'], ['prag_zile' => 30], strtotime('2026-11-13 10:00'));
check('C: 15.01.2026 + 12 luni = 15.01.2027', $e['scadenta_data'] === '2027-01-15', (string) $e['scadenta_data']);
check('C: fără telemetrie, calendarul se evaluează', $e['status'] === ReviziiV2Engine::ST_OK && $e['zile_ramase'] === 63, $e['status'] . ' ' . $e['zile_ramase']);
$e = ReviziiV2Engine::evaluate(['interval_luni' => 12], ['baseline_date' => '2026-01-15'], ['prag_zile' => 90], strtotime('2026-11-13 10:00'));
check('C: avertizare înainte (prag 90 zile)', $e['status'] === ReviziiV2Engine::ST_SOON);
check('addMonths 31.01 + 1 = 28.02', ReviziiV2Engine::addMonths(new DateTimeImmutable('2026-01-31'), 1)->format('Y-m-d') === '2026-02-28');

// Scenariul D — limite multiple SAU
$rule = ['interval_km' => 30000, 'interval_ore' => 500, 'interval_luni' => 12, 'logica' => 'or'];
$e = ReviziiV2Engine::evaluate($rule, ['km_since' => 10000, 'ore_since' => 505, 'baseline_date' => date('Y-m-d', $now - 30 * 86400)], [], $now);
check('D: doar orele atinse → scadent (SAU)', $e['status'] === ReviziiV2Engine::ST_DUE && $e['dominant'] === 'ore', $e['status'] . '/' . $e['dominant']);
$e = ReviziiV2Engine::evaluate($rule, ['km_since' => 10000, 'ore_since' => 100, 'baseline_date' => date('Y-m-d', $now - 500 * 86400)], [], $now);
check('D: doar calendarul depășit → depășit', $e['status'] === ReviziiV2Engine::ST_OVERDUE && $e['dominant'] === 'calendar');
$e = ReviziiV2Engine::evaluate(['interval_km' => 30000, 'interval_ore' => 500, 'logica' => 'and'], ['km_since' => 31000, 'ore_since' => 100], [], $now);
check('ȘI: o singură limită atinsă → nu e scadent', $e['status'] === ReviziiV2Engine::ST_OK, $e['status']);
$e = ReviziiV2Engine::evaluate(['interval_km' => 30000, 'interval_ore' => 500, 'logica' => 'and'], ['km_since' => 31000, 'ore_since' => null], [], $now);
check('ȘI: criteriu nemăsurat → nu se confirmă scadența', $e['status'] === ReviziiV2Engine::ST_NO_DATA, $e['status']);
$e = ReviziiV2Engine::evaluate(['interval_km' => 30000], ['km_since' => null], [], $now);
check('criteriu fără date = „date insuficiente”, nu 0 km', $e['status'] === ReviziiV2Engine::ST_NO_DATA && $e['km_ramasi'] === null);
check('fără criterii = neconfigurat', ReviziiV2Engine::evaluate([], [], [], $now)['status'] === ReviziiV2Engine::ST_NOT_CONFIGURED);
check('prag: 0 < rest ≤ prag = se apropie', ReviziiV2Engine::stateFor(500, 1000) === ReviziiV2Engine::ST_SOON);
check('prag: depășit ≤ prag = scadent; mai mult = depășit', ReviziiV2Engine::stateFor(-500, 1000) === ReviziiV2Engine::ST_DUE && ReviziiV2Engine::stateFor(-1500, 1000) === ReviziiV2Engine::ST_OVERDUE);
check('prag plafonat la jumătate din interval', ReviziiV2Engine::capWarn(30, 30) === 15);
$e = ReviziiV2Engine::evaluate(['interval_km' => 30000, 'coef_ralanti_km' => 20], ['km_since' => 10000, 'idle_since' => 100], [], $now);
check('km echivalenți doar când regula îi activează (10.000 + 100 h × 20)', abs($e['criteria'][0]['used'] - 12000) < 0.01);
$e = ReviziiV2Engine::evaluate(['interval_km' => 30000], ['km_since' => 10000, 'idle_since' => 100], [], $now);
check('fără coeficient: ralantiul NU se adaugă la km', abs($e['criteria'][0]['used'] - 10000) < 0.01);

// Scenariul I — completare
check('I: completarea / inspecția nu resetează referința', !ReviziiV2Engine::resetsBaseline('completare') && !ReviziiV2Engine::resetsBaseline('inspectie'));
check('înlocuirea / gresarea resetează referința', ReviziiV2Engine::resetsBaseline('inlocuire') && ReviziiV2Engine::resetsBaseline('gresare'));
$r = ReviziiV2Engine::topUpRate(3.0, 6000.0, 150.0);
check('rata de completare L / 1000 km și L / 100 h', $r['per_1000km'] === 0.5 && $r['per_100h'] === 2.0);


echo "\n== Partea 2: flux pe BD (tranzacție anulată)\n";
$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// DDL in afara tranzactiei (CREATE TABLE face COMMIT implicit).
// Toate serviciile care isi creeaza schema la runtime se incalzesc INAINTE de BEGIN.
new ReviziiV2Model($db);
new ReviziiService($db);
(new ConsumabileStockService($db))->ensureSchema();
(new ConsumabileStockService($db))->catalogComponents();
$db->beginTransaction();
try {
    $insV = $db->prepare("INSERT INTO vehicule (nr_inmatriculare, marca, model, tip_vehicul, an_fabricatie, km_bord, km_revizie, serie_sasiu, status, created_at, updated_at)
                          VALUES (:nr, 'TESTV2', :m, :t, 2020, 0, 0, :vin, 'activ', NOW(), NOW())");
    $vehicleIds = [];
    foreach ([['TST V2 A', 'MODEL-A', 'cap_tractor'], ['TST V2 B', 'MODEL-B', 'camion'], ['TST V2 C', 'MODEL-A', 'cap_tractor']] as $i => [$nr, $model, $type]) {
        $insV->execute([':nr' => $nr, ':m' => $model, ':t' => $type, ':vin' => 'TESTV2VIN000000' . $i]);
        $vehicleIds[$nr] = (int) $db->lastInsertId();
    }
    [$vA, $vB, $vC] = array_values($vehicleIds);
    $read = $db->prepare("INSERT INTO vehicle_odometer_readings (vehicle_id, provider_code, source_reading_id, measured_at, received_at, odometer_km, original_value, original_unit, measurement_type, validation_status, created_at)
                          VALUES (:v, :p, :s, :at, :at2, :km, :km2, 'km', 'test', 'valid', NOW())");
    $addReading = static function (int $v, string $p, string $at, float $km) use ($read): void {
        $read->execute([':v' => $v, ':p' => $p, ':s' => 'test-' . $v . '-' . $p . '-' . md5($at), ':at' => $at, ':at2' => $at, ':km' => $km, ':km2' => $km]);
    };
    $addReading($vA, 'can', date('Y-m-d H:i:s', $now - 3600), 500000);
    $addReading($vA, 'sas', date('Y-m-d H:i:s', $now - 3600), 500800); // GPS diferit: nu se aduna, CAN are prioritate
    $addReading($vC, 'can', date('Y-m-d H:i:s', $now - 10 * 86400), 300000); // vehicul C: CAN vechi (pana API)

    $svc = new ReviziiV2Service($db);
    $model = $svc->model();
    $ops = [];
    foreach ($model->operations() as $op) {
        $ops[$op['cod']] = (int) $op['id'];
    }
    $templates = $model->templates();
    $tx = null;
    $ty = null;
    foreach ($templates as $t) {
        if ($t['cod'] === 'x_standard') { $tx = (int) $t['id']; }
        if ($t['cod'] === 'y_standard') { $ty = (int) $t['id']; }
    }
    check('catalog inițial: 18 operațiuni X + 4 Y, fără intervale', count($templates[$tx]['operatii']) === 18 && count($templates[$ty]['operatii']) === 4);
    check('Y moștenește X prin părinte (nu copiază)', $templates[$ty]['parinte_id'] === $tx && array_intersect($templates[$ty]['operatii'], $templates[$tx]['operatii']) === []);

    $planA = $svc->savePlanConfig(['nume' => 'T-A', 'tip_vehicul' => 'cap_tractor', 'marca' => 'TESTV2', 'model' => 'MODEL-A', 'sablon_x_id' => $tx, 'sablon_y_id' => $ty,
        'interval_km' => '60000', 'interval_luni' => '12', 'y_interval_km' => '250000', 'prag_km' => '3000', 'reguli' => [
            ['operatie_id' => $ops['ulei_motor'], 'activ' => 1, 'interval_km' => '60000', 'interval_ore' => '500', 'interval_luni' => '12', 'cantitate' => '40', 'um' => 'L'],
            ['operatie_id' => $ops['gresare_pivoti'], 'activ' => 1, 'interval_luni' => '1'],
            ['operatie_id' => $ops['filtru_cabina'], 'activ' => 0],
        ]], null);
    $planB = $svc->savePlanConfig(['nume' => 'T-B', 'tip_vehicul' => 'camion', 'marca' => 'TESTV2', 'sablon_x_id' => $tx, 'sablon_y_id' => $ty,
        'interval_luni' => '6', 'y_interval_km' => '200000', 'reguli' => [
            ['operatie_id' => $ops['filtru_aer'], 'activ' => 0],
            ['operatie_id' => $ops['gresare_pivoti'], 'activ' => 1, 'interval_luni' => '12'],
        ]], null);
    check('planuri salvate', $planA > 0 && $planB > 0);
    check('validare: Y fără prag / interval km e refuzată', expectError(fn () => $svc->savePlanConfig(['nume' => 'x', 'sablon_x_id' => $tx, 'sablon_y_id' => $ty, 'interval_km' => '60000'], null), 'Revizia Y') === 'ok');
    check('validare: intervalul km sub minim e refuzat', expectError(fn () => $svc->savePlanConfig(['nume' => 'x', 'sablon_x_id' => $tx, 'interval_km' => '5'], null), 'km') === 'ok');

    // A: ultima Y la 300.000 km, apoi X1 si X2 (X2 la 450.000 km) → X la 60.000, Y la fiecare 250.000.
    $svc->saveVehicleConfig(['vehicle_id' => $vA, 'x_de_la_ultima_y' => 2, 'y_efectuate' => 1, 'referinta_data' => date('Y-m-d H:i', $now - 100 * 86400), 'referinta_km' => '450000',
        'ultima_y_data' => date('Y-m-d H:i', $now - 400 * 86400), 'ultima_y_km' => '300000'], null);
    $svc->saveVehicleConfig(['vehicle_id' => $vB, 'x_de_la_ultima_y' => 0, 'referinta_data' => '2025-11-20 08:00'], null);
    $svc->saveVehicleConfig(['vehicle_id' => $vC, 'x_de_la_ultima_y' => 2, 'referinta_data' => date('Y-m-d H:i', $now - 20 * 86400), 'referinta_km' => '280000', 'ultima_y_km' => '150000'], null);
    $svc->reset();

    $row = static function (int $id) use ($svc): array {
        $svc->reset();
        foreach ($svc->workspace()['vehicles'] as $v) {
            if ($v['id'] === $id) { return $v; }
        }
        throw new RuntimeException('vehicul lipsă');
    };
    $opOf = static function (array $v, string $code) use ($ops): ?array {
        foreach ($v['ops'] as $op) {
            if ($op['id'] === $ops[$code]) { return $op; }
        }
        return null;
    };

    // Scenariul J — configuratii diferite
    $a = $row($vA);
    $b = $row($vB);
    check('J: plan după tip / marcă / model', $a['plan']['id'] === $planA && $b['plan']['id'] === $planB);
    check('J: filtru cabină dezactivat în planul A', $opOf($a, 'filtru_cabina') === null && $opOf($b, 'filtru_cabina') !== null);
    check('J: filtru aer dezactivat în planul B', $opOf($b, 'filtru_aer') === null && $opOf($a, 'filtru_aer') !== null);
    check('J: placă cuplare doar la cap tractor, cui remorcă doar la camion', $opOf($a, 'gresare_placa') !== null && $opOf($a, 'gresare_cui') === null && $opOf($b, 'gresare_cui') !== null && $opOf($b, 'gresare_placa') === null);
    check('J: planul B fără interval km X → fără țintă km, cu notă', $b['next']['km_target'] === null && $b['secventa']['notes'] !== []);
    check('sursă km: CAN proaspăt are prioritate față de GPS (nu se adună)', $a['km']['source'] === 'can' && $a['km']['value'] === 500000.0);
    check('km rămași = țintă X3 (450.000 + 60.000) − 500.000', $a['next']['label'] === 'X3' && $a['next']['km_target'] == 510000 && (int) $a['next']['km_ramasi'] === 10000);
    check('secvența reală: Y, X1, X2 efectuate → X3, apoi Y la 550.000 (Y înaintea X4)', implode(',', array_column($a['secventa']['sequence'], 'label')) === 'Y,X1,X2,X3,Y'
        && end($a['secventa']['sequence'])['km'] == 550000 && $a['secventa']['x_before_y'] === 3, implode(',', array_column($a['secventa']['sequence'], 'label')));

    // Scenariul G — pana API
    $cRow = $row($vC);
    check('G: ultima citire validă afișată cu avertisment de vechime', $cRow['km']['value'] === 300000.0 && $cRow['km']['stale'] === true);
    $pivC = $opOf($cRow, 'gresare_pivoti');
    check('G: calendarul funcționează fără telemetrie proaspătă', $pivC !== null && in_array('calendar', $pivC['crit'], true) && $pivC['zile_ramase'] !== null);

    // Scenariul C — calendar pe B (fara odometru)
    $pivB = $opOf($b, 'gresare_pivoti');
    check('C: 20.11.2025 + 12 luni = 20.11.2026, fără date CAN/GPS', $pivB['criteria'][0]['due_date'] === '2026-11-20' && $b['km']['value'] === null);

    // Scenariul A — revizie X standard
    $finalize = static function (int $vehicle, string $at, array $override = [], array $extra = [], string $kmAt = '500000') use ($svc): array {
        $svc->reset();
        $id = $svc->saveWorkOrder(['vehicle_id' => $vehicle, 'status' => 'in_desfasurare'], 1);
        $order = $svc->model()->workOrder($id);
        $items = [];
        foreach ($order['items'] as $it) {
            $items[] = ['id' => $it['id'], 'status' => $override[(int) $it['operatie_id']]['status'] ?? 'completed', 'motiv' => $override[(int) $it['operatie_id']]['motiv'] ?? null]
                + ($extra[(int) $it['operatie_id']] ?? []);
        }
        $res = $svc->finalizeWorkOrder($id, ['data_executie' => $at, 'km_executie' => $kmAt, 'km_sursa' => 'can', 'tehnician' => 'Test', 'cost_manopera' => '100', 'items' => $items], 1);
        return $res + ['id' => $id];
    };
    $res = $finalize($vA, date('Y-m-d H:i', $now - 9 * 86400), [], [], '505000');
    $a = $row($vA);
    check('A: revizia X3 finalizată completă', $res['status'] === 'finalizata' && $res['completa']);
    check('B: Y devine scadentă la km ei după 3 X (nu după un număr fix)', $a['next']['type'] === 'Y' && $a['next']['between'] && $a['next']['km_target'] == 550000 && $a['secventa']['x_done'] === 3);
    check('A: referințele operațiunilor efectuate se resetează', ($opOf($a, 'ulei_motor')['baseline']['sursa'] ?? '') === 'executie');
    check('A: X următoare recalculată din km-ul execuției (505.000 + 60.000)', $a['last']['sursa'] === 'revizie' && $a['secventa']['x_target'] == 565000);
    check('A: operațiunile Y (la Y) nu intră în X', ($opOf($a, 'ulei_cutie')['baseline']['sursa'] ?? '') === 'preluare');
    check('O singură lucrare deschisă per vehicul', expectError(function () use ($svc, $vA): void {
        $svc->saveWorkOrder(['vehicle_id' => $vA, 'status' => 'programata', 'data_programata' => date('Y-m-d')], 1);
        $svc->reset();
        $svc->saveWorkOrder(['vehicle_id' => $vA, 'status' => 'programata', 'data_programata' => date('Y-m-d')], 1);
    }, 'deja') === 'ok');
    $open = $svc->model()->openWorkOrders()[$vA];
    check('Programarea nu mută referințele', $row($vA)['secventa']['x_done'] === 3);
    $svc->cancelWorkOrder((int) $open['id'], 'test', 1);

    // Scenariul B — Y pe km
    $svc->reset();
    $draft = $svc->draftWorkOrder($vA);
    $sections = array_count_values(array_column($draft['items'], 'sectiune'));
    check('B: Y = operațiunile X aplicabile + cele 4 Y, într-un singur eveniment', ($sections['X'] ?? 0) === 16 && ($sections['Y'] ?? 0) === 4, json_encode($sections));

    // Scenariul E — revizie parțială (in Y)
    $res = $finalize($vA, date('Y-m-d H:i', $now - 4 * 86400), [$ops['ulei_diferential'] => ['status' => 'deferred', 'motiv' => 'lipsă ulei']], [], '548000');
    $a = $row($vA);
    check('E: revizie parțială, referințele reviziei NU se mută', $res['status'] === 'partiala' && !$res['completa'] && $a['next']['type'] === 'Y' && $a['secventa']['x_target'] == 565000);
    check('E: doar operațiunile efectuate își resetează referința', ($opOf($a, 'ulei_cutie')['baseline']['sursa'] ?? '') === 'executie' && ($opOf($a, 'ulei_diferential')['baseline']['sursa'] ?? '') === 'preluare');
    check('E: amânarea fără motiv e refuzată', expectError(fn () => $finalize($vA, date('Y-m-d H:i', $now - 3 * 86400), [$ops['ulei_diferential'] => ['status' => 'deferred', 'motiv' => '']]), 'Motivul') === 'ok');
    $svc->reset();
    $open = $svc->model()->openWorkOrders()[$vA] ?? null;
    if ($open) { $svc->cancelWorkOrder((int) $open['id'], 'test', 1); }
    $svc->reset();
    $draft = $svc->draftWorkOrder($vA);
    $pending = array_values(array_filter($draft['items'], static fn (array $it): bool => $it['status'] === 'pending'));
    check('E: lucrarea următoare propune doar ce a rămas (restul „deja efectuat”)', count($pending) === 1 && $pending[0]['operatie_id'] === $ops['ulei_diferential']);
    $res = $finalize($vA, date('Y-m-d H:i', $now - 3 * 86400), [], [], '549000');
    $a = $row($vA);
    check('B: după Y completă → X1, următoarea Y = 549.000 + 250.000', $res['status'] === 'finalizata' && $a['next']['label'] === 'X1'
        && $a['secventa']['y_target'] == 799000 && $a['secventa']['x_target'] == 609000 && $a['secventa']['y_done'] === 2);

    // Scenariul F — gresare suprapusa
    $svc->reset();
    $orderId = $svc->saveWorkOrder(['vehicle_id' => $vA, 'status' => 'programata', 'data_programata' => date('Y-m-d')], 1);
    $execId = $svc->recordOperation(['vehicle_id' => $vA, 'operatie_id' => $ops['gresare_pivoti'], 'tip_executie' => 'gresare', 'data_executie' => date('Y-m-d H:i', $now - 2 * 86400), 'km' => '500000', 'idempotency_key' => 'test-f-1'], 1);
    $item = array_values(array_filter($svc->model()->workOrder($orderId)['items'], static fn (array $it): bool => (int) $it['operatie_id'] === $ops['gresare_pivoti']))[0];
    check('F: gresarea independentă marchează linia lucrării deschise', $item['status'] === 'completed' && (int) $item['executie_id'] === $execId);
    $countExec = static function (int $op) use ($db, $vA): int {
        $s = $db->prepare('SELECT COUNT(*) FROM revizii_v2_executii WHERE vehicle_id = :v AND operatie_id = :o AND anulat_la IS NULL');
        $s->execute([':v' => $vA, ':o' => $op]);
        return (int) $s->fetchColumn();
    };
    $before = $countExec($ops['gresare_pivoti']);
    $svc->reset();
    $order = $svc->model()->workOrder($orderId);
    $items = array_map(static fn (array $it): array => ['id' => $it['id'], 'status' => 'completed'], $order['items']);
    $svc->finalizeWorkOrder($orderId, ['data_executie' => date('Y-m-d H:i', $now - 86400), 'km_executie' => '500000', 'tehnician' => 'T', 'items' => $items], 1);
    check('F: finalizarea nu dublează execuția de gresare', $countExec($ops['gresare_pivoti']) === $before);
    check('idempotență: aceeași cheie → aceeași execuție', $svc->recordOperation(['vehicle_id' => $vA, 'operatie_id' => $ops['gresare_pivoti'], 'tip_executie' => 'gresare', 'data_executie' => date('Y-m-d H:i', $now - 2 * 86400), 'idempotency_key' => 'test-f-1'], 1) === $execId);

    // Scenariul I — completare ulei
    $a = $row($vA);
    $baseBefore = $opOf($a, 'ulei_motor')['baseline'];
    $svc->recordOperation(['vehicle_id' => $vA, 'operatie_id' => $ops['ulei_motor'], 'tip_executie' => 'completare', 'data_executie' => date('Y-m-d H:i', $now - 3600), 'km' => '500000', 'cantitate' => '2', 'um' => 'L', 'idempotency_key' => 'test-i-1'], 1);
    $a = $row($vA);
    check('I: completarea nu mută referința schimbului de ulei', $opOf($a, 'ulei_motor')['baseline'] == $baseBefore);
    $det = $svc->vehicleDetail($vA);
    check('detaliul vehiculului nu face COMMIT implicit (DDL)', $db->inTransaction());
    check('I: completarea apare în rata de completare', isset($det['rate_completare'][$ops['ulei_motor']]) && $det['rate_completare'][$ops['ulei_motor']]['cantitate'] === 2.0);

    // Scenariul H — schimb ulei cu stoc
    $stock = new ConsumabileStockService($db);
    $productId = $stock->saveProduct(['denumire' => 'TEST V2 Ulei 10W-40', 'um' => 'L', 'tip_articol' => 'fluid', 'tip_fluid' => 'ulei_motor', 'vascozitate' => '10W-40', 'specificatie' => 'ACEA E7', 'aprobari' => 'TEST'], null, 1);
    $containerId = $stock->saveContainer(['tip' => 'butoi', 'cod' => 'TSTV2-B1', 'capacitate' => '200', 'produs_id' => $productId, 'cantitate_initiala' => '200', 'idempotency_key' => 'tstv2-intrare'], null, 1);
    $qty = static function () use ($db, $containerId): float {
        $s = $db->prepare('SELECT cantitate FROM consumabile_recipiente WHERE id = :id');
        $s->execute([':id' => $containerId]);
        return (float) $s->fetchColumn();
    };
    $svc->reset();
    $options = $svc->stockOptions($vA);
    check('H: recipientul compatibil apare în opțiunile de stoc', in_array($containerId, array_map('intval', array_column($options['recipiente'], 'id')), true));
    $orderId = $svc->saveWorkOrder(['vehicle_id' => $vA, 'status' => 'in_desfasurare'], 1);
    check('H: programarea / lucrul în curs nu scade stocul', $qty() === 200.0);
    $order = $svc->model()->workOrder($orderId);
    $items = [];
    foreach ($order['items'] as $it) {
        $items[] = ['id' => $it['id'], 'status' => 'completed'] + ((int) $it['operatie_id'] === $ops['ulei_motor'] ? ['cantitate_reala' => '38', 'recipient_id' => $containerId, 'produs_id' => $productId, 'cost_material' => '950'] : []);
    }
    $svc->finalizeWorkOrder($orderId, ['data_executie' => date('Y-m-d H:i', $now - 1800), 'km_executie' => '500100', 'tehnician' => 'T', 'items' => $items], 1);
    check('H: stocul scade o singură dată la finalizare (200 − 38)', $qty() === 162.0, (string) $qty());
    check('H: refinalizarea e refuzată (fără a doua scădere)', expectError(fn () => $svc->finalizeWorkOrder($orderId, ['items' => []], 1), 'închisă') === 'ok' && $qty() === 162.0);
    $exec = $db->prepare('SELECT * FROM revizii_v2_executii WHERE idempotency_key = :k');
    $line = array_values(array_filter($svc->model()->workOrder($orderId)['items'], static fn (array $it): bool => (int) $it['operatie_id'] === $ops['ulei_motor']))[0];
    $exec->execute([':k' => 'rv2-item-' . $line['id']]);
    $e = $exec->fetch(PDO::FETCH_ASSOC);
    check('H: execuția păstrează produs, cantitate, cost, mișcare de stoc', (int) $e['produs_id'] === $productId && (float) $e['cantitate'] === 38.0 && (float) $e['cost'] === 950.0 && $e['miscare_id'] !== null);
    $dup = $stock->operate('schimb_complet', ['idempotency_key' => 'rv2-item-' . $line['id'], 'recipient_sursa_id' => $containerId, 'vehicul_id' => $vA, 'cantitate' => '38'], 1);
    check('H: reîncercarea mișcării de stoc e idempotentă', $dup['duplicate'] === true && $qty() === 162.0);

    // Scenariul L — integritatea istoricului
    $hist = $svc->historyDetail($orderId);
    $countBefore = count($hist['items']);
    $tpl = $svc->model()->templates()[$tx];
    $svc->saveTemplateConfig(['id' => $tx, 'nume' => $tpl['nume'], 'tip' => 'X', 'operatii' => array_slice($tpl['operatii'], 0, 5)], 1);
    $after = $svc->historyDetail($orderId);
    check('L: modificarea șablonului nu schimbă lucrarea finalizată', count($after['items']) === $countBefore && $after['snapshot']['sabloane'][0]['versiune'] === 1);
    check('L: versiunea șablonului crește', (int) $svc->model()->templates()[$tx]['versiune'] === 2);
    $svc->reset();
    check('L: lucrările noi folosesc noul șablon', count(array_filter($svc->draftWorkOrder($vA)['items'], static fn (array $it): bool => $it['sectiune'] === 'X')) <= 5 + 1);

    // Istoric + export
    $h = $svc->history(['vehicle_id' => $vA]);
    check('istoric: lucrări + operațiuni independente', count(array_filter($h, static fn (array $r): bool => $r['kind'] === 'lucrare')) >= 7 && count(array_filter($h, static fn (array $r): bool => $r['kind'] === 'operatie')) >= 1);
    $tmp = tempnam(sys_get_temp_dir(), 'rv2t');
    $svc->exportHistory(['vehicle_id' => $vA], $tmp);
    check('export Excel generat', filesize($tmp) > 1000);
    @unlink($tmp);

    // Corectia referintelor de preluare cu istoric: motiv obligatoriu
    check('corecția referințelor după revizii cere motiv', expectError(fn () => $svc->saveVehicleConfig(['vehicle_id' => $vA, 'x_de_la_ultima_y' => 4, 'y_efectuate' => 1, 'referinta_km' => '450000', 'ultima_y_km' => '300000'], 1), 'motiv') === 'ok');

    // Import cu previzualizare
    $preview = $svc->importPreview("Componentă\tTip operațiune\tOperațiune\tKm\tOre\tInterval\tModel\tTip revizie\nFiltru aer\tÎnlocuire\tÎnlocuire filtru aer\t60000\t\t12\tDAF XF\tX\nFiltru test\tCiudat\tTest op\t\t\t\t\tX\nFiltru nou\tÎnlocuire\tÎnlocuire filtru nou\tda\t\t\t\tY");
    check('import: existent / eroare / nou detectate', $preview['existing'] === 1 && $preview['errors'] === 1 && $preview['new'] === 1);
    check('import: „da” = criteriu candidat, fără valoare inventată', $preview['rows'][2]['criterii'] === 'km' && $preview['rows'][2]['valori'] === '');
    check('import cu erori nu se aplică', expectError(fn () => $svc->importApply("Componentă\tOperațiune\nX\t", $tx, $ty, 1)) === 'ok');

    // Explicatie
    $explain = $svc->explain($vA);
    check('simulare: pașii calculului pe datele reale', count($explain['steps']) >= 5);
} catch (Throwable $exception) {
    check('fără excepții neașteptate', false, get_class($exception) . ': ' . $exception->getMessage() . ' @ ' . basename($exception->getFile()) . ':' . $exception->getLine());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    } else {
        check('tranzacția de test a rămas deschisă (niciun COMMIT implicit)', false, 'ATENȚIE: datele de test pot fi rămas în BD — vezi vehiculele TESTV2.');
    }
}
$left = (int) $db->query("SELECT COUNT(*) FROM vehicule WHERE marca = 'TESTV2'")->fetchColumn();
check('curățenie: tranzacția anulată (nimic rămas)', $left === 0);

echo "\n$passed ok, $failed eșuate\n";
exit($failed > 0 ? 1 : 0);
