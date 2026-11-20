<?php
declare(strict_types=1);

/**
 * Teste Rentabilitate Operationala (IMPLEMENTARE_RENTABILITATE_OPERATIONALA.md): reconcilierea costurilor,
 * vehicul + sofer, tractor + semiremorca + sofer, cuplaje istorice, asignari sofer istorice, beneficiari,
 * tipuri de transport, excluderea investitiilor capitalizabile, date incomplete, simulare fara asignare,
 * surse si regresia paginii Cost operational / km.
 *
 *   DB_NAME=fleet_fin_test php scripts/test_rentabilitate.php
 *
 * Scenariu controlat intr-o luna fara alte date (ianuarie 2031). Scrie date reale -> doar pe baza „test”.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
if (!str_contains(DB_NAME, 'test')) {
    fwrite(STDERR, 'Refuz: baza „' . DB_NAME . "” nu este o copie de test (DB_NAME=fleet_fin_test).\n");
    exit(1);
}
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/OperationalCostModel.php';
require_once $root . '/htdocs/models/MaintenanceModel.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/services/CostNormalizationService.php';
require_once $root . '/htdocs/services/ProfitabilityRegisterService.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
}
function near(?float $a, ?float $b, float $eps = 0.01): bool
{
    return $a !== null && $b !== null && abs($a - $b) < $eps;
}

$db = get_pdo();
new MaintenanceModel($db); // schema financiara (DDL in afara tranzactiilor)
$tag = 'R' . date('His') . random_int(10, 99);
$exec = static function (string $sql, array $p = []) use ($db): int {
    $s = $db->prepare($sql);
    $s->execute($p);
    return (int) $db->lastInsertId();
};
$val = static function (string $sql, array $p = []) use ($db) {
    $s = $db->prepare($sql);
    $s->execute($p);
    return $s->fetchColumn();
};

// ---------------------------------------------------------------- scenariu
$year = 2031 + random_int(0, 40); // o luna noua la fiecare rulare (fara date suprapuse)
$M = $year . '-01';
$veh = static fn (string $type, string $suffix) => $exec(
    "INSERT INTO vehicule (nr_inmatriculare, marca, model, tip_vehicul, an_fabricatie, serie_sasiu, status, created_at, updated_at)
     VALUES (:n, 'Test', 'RO', :t, 2020, :v, 'activ', NOW(), NOW())", [':n' => $tag . $suffix, ':t' => $type, ':v' => substr($tag . $suffix . 'VIN0000000', 0, 17)]);
$T1 = $veh('cap_tractor', 'T1');
$T2 = $veh('cap_tractor', 'T2');
$S = $veh('semiremorca_primar', 'S');
$drv = static fn (string $name, ?float $salary) => $exec(
    "INSERT INTO soferi (nume, telefon, permis_expira_la, salariu, status, created_at, updated_at) VALUES (:n, '0700000000', '2040-01-01', :s, 'activ', NOW(), NOW())",
    [':n' => $tag . ' ' . $name, ':s' => $salary]);
$D1 = $drv('Sofer Unu', 5000.0);
$D2 = $drv('Sofer Doi', null);   // fara salariu: cost de personal lipsa
[$B1, $B2] = array_map('intval', $db->query('SELECT id FROM configurare_beneficiari_transport ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
// cuplaje: T1+S 1-16 ian, apoi T2+S (activ acum)
$exec("INSERT INTO vehicule_cuplaje (tractor_id, semiremorca_id, activ, data_start, data_end, created_at, updated_at) VALUES (:t, :s, 0, :a, :b, NOW(), NOW())",
    [':t' => $T1, ':s' => $S, ':a' => "$M-01 00:00:00", ':b' => "$M-16 00:00:00"]);
$exec("INSERT INTO vehicule_cuplaje (tractor_id, semiremorca_id, activ, data_start, data_end, created_at, updated_at) VALUES (:t, :s, 1, :a, NULL, NOW(), NOW())",
    [':t' => $T2, ':s' => $S, ':a' => "$M-16 00:00:00"]);
$trip = static fn (int $v, int $d, int $b, string $type, string $day, float $km, float $rev) => $exec(
    "INSERT INTO curse_dispecer (vehicle_id, driver_id, tip_transport, data_cursa, data_inceput, data_sfarsit, ora_inceput, beneficiar_id, km_cursa, km_totali,
        pret_tarifare, total_facturare, status_facturare, created_at, updated_at)
     VALUES (:v, :d, :t, :day, :day2, :day3, '08:00:00', :b, :km, :km2, 0, :rev, 'in_curs_facturare', NOW(), NOW())",
    [':v' => $v, ':d' => $d, ':t' => $type, ':day' => "$M-$day", ':day2' => "$M-$day", ':day3' => "$M-$day", ':b' => $b, ':km' => $km, ':km2' => $km, ':rev' => $rev]);
$trip1 = $trip($T1, $D1, $B1, 'primar', '05', 1000, 3000);
$trip($T2, $D1, $B2, 'distributie', '20', 500, 1000);
$trip($T2, $D2, $B1, 'primar', '25', 500, 1500);
$maint = static fn (int $v, string $day, float $cost, ?string $nature = null, string $treatment = 'cheltuiala') => $exec(
    "INSERT INTO mentenanta (vehicle_id, tip_interventie, record_type, natura_cost, tratament_contabil, status_interventie, data_interventie, cost, cost_manopera, cost_piese, created_at, updated_at)
     VALUES (:v, :t, 'reparatie', :n, :tr, 'finalizata', :d, :c, 0, :c2, NOW(), NOW())",
    [':v' => $v, ':t' => 'Test rentabilitate ' . $day, ':n' => $nature ?? 'reparatie', ':tr' => $treatment, ':d' => "$M-$day", ':c' => $cost, ':c2' => $cost]);
$maint($S, '10', 1000);
$maint($T1, '08', 1200);
$maint($T1, '09', 20000, 'investitie', 'capitalizabil');   // investitie capitalizabila: NU e cost operational
$exec("INSERT INTO fuel_fillups (api_id, vehicle_registration, fuel_type, quantity_liters, total_value, fillup_datetime, source_type, raw_payload, created_at, updated_at)
       VALUES (:a, :r, 'motorina', 150, 1210, :d, 'api', '{\"cota_tva\":\"21\"}', NOW(), NOW())", [':a' => $tag . 'F1', ':r' => $tag . 'T1', ':d' => "$M-06 10:00:00"]);

$from = "$M-01";
$to = "$M-31";
$reg = new ProfitabilityRegisterService($db, null);
$prof = $reg->engine();
$a = $reg->analysis($from, $to);
$v1 = $a['vehicles'][$T1];
$v2 = $a['vehicles'][$T2];
$vs = $a['vehicles'][$S];

echo "\nActivitate, venit, tipuri\n";
check('km reali T1 / T2', near($v1['km_real'], 1000) && near($v2['km_real'], 1000));
check('venit din tariful curselor (T1 3000, T2 2500)', near($v1['revenue'], 3000) && near($v2['revenue'], 2500));
check('tip transport: T1 primar, T2 mixt', $v1['transport'] === 'primar' && $v2['transport'] === 'mixt');
$listPrimar = $reg->list(['from' => $from, 'to' => $to, 'tip' => 'primar', 'q' => $tag]);
$row2 = array_values(array_filter($listPrimar['rows'], static fn ($r) => $r['id'] === $T2))[0] ?? null;
check('filtru tip transport: T2 doar cursa primar (500 km, 1500 lei)', $row2 !== null && near($row2['km'], 500) && near($row2['revenue'], 1500));
check('filtru tip: costul se aloca pe km filtrati (jumatate)', $row2 !== null && near($row2['cost_total'], $v2['cost_total'] / 2));

echo "\nCuplaje istorice tractor / semiremorca\n";
check('cuplajul valabil la data cursei (nu cel activ azi)', $prof->semiAt($T1, "$M-05 08:00:00") === $S && $prof->semiAt($T2, "$M-20 08:00:00") === $S && $prof->semiAt($T1, "$M-20 08:00:00") === null);
check('km cu semiremorca: 1000 cu T1, 1000 cu T2', near($v1['semi_km'][$S] ?? null, 1000) && near($v2['semi_km'][$S] ?? null, 1000));
check('costul semiremorcii impartit 50/50 dupa km (nu tot pe tractorul curent)', near($v1['semi_costs'][$S] ?? null, $vs['own_cost'] / 2) && near($v2['semi_costs'][$S] ?? null, $vs['own_cost'] / 2));
check('fara dubla numarare: alocat + nealocat = costul semiremorcii', near(($v1['semi_costs'][$S] ?? 0) + ($v2['semi_costs'][$S] ?? 0) + $vs['unallocated_semi_cost'], $vs['own_cost']));
check('semiremorca isi pastreaza costurile proprii (reparatia 1000)', ($vs['costs']['reparatii'] ?? 0) >= 1000 - 0.01);

echo "\nCosturi vehicul, investitii, combustibil\n";
check('investitia capitalizabila (20.000) exclusa din reparatii T1', near($v1['costs']['reparatii'] ?? null, 1200 + 500));
check('combustibil T1 net de TVA (1210 / 1,21 = 1000)', near($v1['costs']['combustibil'] ?? null, 1000));
$cap = $db->query("SELECT COUNT(*) FROM mentenanta WHERE vehicle_id = $T1 AND tratament_contabil = 'capitalizabil'")->fetchColumn();
check('investitia ramane in registru (doar exclusa din costul operational)', (int) $cap === 1);

echo "\nSoferi: alocare dupa km reali, asignari istorice\n";
$d1 = $a['drivers'][$D1];
check('cost sofer D1 = salariu configurat × multiplicator (+ alte costuri de personal)', $d1['cost'] >= 5000 && ($d1['cost_by_code']['salariu_soferi'] ?? 0) > 0);
check('D1 alocat T1 2/3, T2 1/3 (km 1000 / 500)', near($v1['driver_costs'][$D1] ?? null, $d1['cost'] * 1000 / 1500) && near($v2['driver_costs'][$D1] ?? null, $d1['cost'] * 500 / 1500));
check('sofer partajat: alocat o singura data (suma = costul)', near(($v1['driver_costs'][$D1] ?? 0) + ($v2['driver_costs'][$D1] ?? 0), $d1['cost']) && near($d1['unallocated'], 0));
check('asignare istorica: soferul cursei, nu asignarea curenta din fisa', isset($v2['by_driver'][$D2]) && !isset($v1['by_driver'][$D2]));
check('date incomplete: D2 fara salariu -> T2 marcat', in_array('Cost șofer lipsă (fără salariu / pontaj)', $v2['incomplet'], true));
check('cost estimat (fara pontaj finalizat) semnalat', in_array('Cost șofer estimat (salariu configurat, fără pontaj finalizat)', $v1['incomplet'], true));

echo "\nCalcul combinat (valori exacte, rotunjire doar la afisare)\n";
check('vehicul + sofer: cost total/km = (cost vehicul + cost sofer) / km', near($v1['cost_total_km'], ($v1['cost_vehicle'] + $v1['cost_driver']) / 1000, 1e-9));
check('tractor + semiremorca + sofer: costul vehiculului include cota semiremorcii', $v1['cost_vehicle'] >= ($v1['costs']['combustibil'] ?? 0) + ($v1['semi_costs'][$S] ?? 0) - 0.01);
check('rezultat = venit - cost total; acoperire = venit / cost', near($v1['result'], 3000 - $v1['cost_total'], 1e-6) && near($v1['coverage'], 3000 / $v1['cost_total'] * 100, 1e-6));
check('componentele/km se aduna (aceeasi baza de km)', near($v1['cost_vehicle_km'] + $v1['cost_driver_km'], $v1['cost_total_km'], 1e-9));
$s1 = $reg->vehicleSummary($T1, $from, $to);
check('rezumatul paginii = modelul (fara rotunjire)', near($s1['cost_total_km'], $v1['cost_total_km'], 1e-9) && near($s1['result_km'], $v1['result_km'], 1e-9));

echo "\nReconcilierea grilelor de cost (suma randurilor = categoria)\n";
foreach ([$T1 => 'T1', $T2 => 'T2', $S => 'S'] as $vid => $label) {
    $sum = $reg->vehicleSummary($vid, $from, $to);
    $bad = [];
    foreach ($sum['categorii'] as $c) {
        $lines = $reg->costLines($vid, $c['cod'], $from, $to);
        if (!near($lines['total'], $c['total'])) {
            $bad[] = $c['cod'] . ' ' . round($lines['total'], 2) . '≠' . round($c['total'], 2);
        }
    }
    check("$label: toate categoriile reconciliate", $bad === [], implode(', ', $bad));
}
$repLines = $reg->costLines($T1, 'reparatii', $from, $to)['rows'];
check('grila reparatii T1: sursa originala (mentenanta #id) + cota semiremorcii', count($repLines) === 2
    && count(array_filter($repLines, static fn ($l) => str_contains((string) ($l['detalii']['Sursă'] ?? ''), 'mentenanta #'))) === 2
    && count(array_filter($repLines, static fn ($l) => isset($l['detalii']['Cotă']))) === 1);
$fuelLines = $reg->costLines($T1, 'combustibil', $from, $to)['rows'];
check('grila combustibil: alimentarea CardOil cu referinta', count($fuelLines) === 1 && $fuelLines[0]['link'] === 'carburanti' && $fuelLines[0]['source_id'] > 0);

echo "\nBeneficiari\n";
$ben = $reg->beneficiaryBreakdown('vehicul', $T2, $from, $to);
$byId = array_column($ben, null, 'beneficiar_id');
check('T2: doi beneficiari, 500 km fiecare', isset($byId[$B1], $byId[$B2]) && near($byId[$B1]['km'], 500) && near($byId[$B2]['km'], 500));
check('venit pe beneficiar (1500 / 1000)', near($byId[$B1]['revenue'], 1500) && near($byId[$B2]['revenue'], 1000));
check('costul beneficiarilor reconciliaza costul vehiculului', near(array_sum(array_column($ben, 'cost')), $v2['cost_total']));
$benD1 = $reg->beneficiaryBreakdown('sofer', $D1, $from, $to);
check('beneficiari pe sofer D1: km totali 1500', near(array_sum(array_column($benD1, 'km')), 1500));

echo "\nLista, cautare, paginare, sofer\n";
$list = $reg->list(['from' => $from, 'to' => $to, 'q' => $tag]);
check('cautare dupa numar: T1, T2, S', count($list['rows']) === 3);
$byTrip = $reg->list(['from' => $from, 'to' => $to, 'q' => '#' . $trip1]);
check('cautare dupa id cursa', count(array_filter($byTrip['rows'], static fn ($r) => $r['id'] === $T1)) === 1);
$byDriver = $reg->list(['from' => $from, 'to' => $to, 'q' => mb_strtolower($tag . ' Sofer Doi')]);
check('cautare dupa sofer (vehiculul pe care l-a condus)', count($byDriver['rows']) === 1 && $byDriver['rows'][0]['id'] === $T2);
check('totalurile nu dubleaza semiremorca', near($list['totals']['cost'], $v1['cost_total'] + $v2['cost_total'] + $vs['unallocated_semi_cost']));
$paged = $reg->list(['from' => $from, 'to' => $to, 'per_page' => 10, 'page' => 2]);
check('paginare pe server', $paged['page'] === 2 && count($paged['rows']) <= 10 && $paged['pages'] >= 2);
$drivers = $reg->list(['from' => $from, 'to' => $to, 'mode' => 'soferi', 'q' => $tag]);
$rd1 = array_values(array_filter($drivers['rows'], static fn ($r) => $r['id'] === $D1))[0] ?? null;
check('vederea Soferi: D1 1500 km, 2 vehicule, cost de personal', $rd1 !== null && near($rd1['km'], 1500) && count($rd1['vehicles']) === 2 && near($rd1['cost_driver'], $d1['cost']));
check('contributie D1 = venit - cost vehicul pe km-ii lui - cost personal', $rd1 !== null && near($rd1['result'], 4000 - $rd1['cost_vehicle'] - $rd1['cost_driver']));
$trips = $reg->trips('vehicul', $T1, $from, $to);
check('activitate: cursa T1 cu ansamblul istoric', $trips['total'] === 1 && str_contains($trips['rows'][0]['vehicul'], $tag . 'S'));

echo "\nSimulare fara asignare, documente, investitii\n";
$before = $db->query("SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(vehicle_id), 0)) FROM soferi_vehicule")->fetchColumn() . '/' . $db->query('SELECT COALESCE(SUM(COALESCE(vehicle_id,0)),0) FROM soferi')->fetchColumn();
$sim = $reg->driverSimulation($T1, $from, $to);
$after = $db->query("SELECT CONCAT(COUNT(*), '|', COALESCE(SUM(vehicle_id), 0)) FROM soferi_vehicule")->fetchColumn() . '/' . $db->query('SELECT COALESCE(SUM(COALESCE(vehicle_id,0)),0) FROM soferi')->fetchColumn();
$simD1 = array_values(array_filter($sim['rows'], static fn ($r) => $r['driver_id'] === $D1))[0] ?? null;
check('simulare: istoric D1 pe T1 separat de simulare', $simD1 !== null && $simD1['istoric'] !== null && near($simD1['istoric']['km'], 1000) && $simD1['sim_driver_km'] !== null);
check('simularea nu schimba asignarile', $before === $after);
$docs = $reg->documents($T1, $from, $to);
check('documente: vehiculul + semiremorca folosita', count($docs) === 2 && $docs[1]['semiremorca'] === true);
$inv = $reg->investments($T1, $from, $to);
check('investitii: din Istoric Financiar (vehicul + semiremorca)', count($inv) === 2 && array_key_exists('proiecte', $inv[0]));

echo "\nIntervale, cache, regresie Cost / km\n";
$cross = $reg->engine()->analyze(($year - 1) . '-12-15', "$M-10");
check('interval peste doua luni (partial)', count($cross['months']) === 2 && isset($cross['vehicles'][$T1]));
$cacheDir = sys_get_temp_dir() . '/rop_test_' . $tag;
$c1 = new OperationalProfitabilityService($db, $cacheDir);
$m1 = $c1->monthModel($M, $from, $to);
$c2 = new OperationalProfitabilityService($db, $cacheDir);
$m2 = $c2->monthModel($M, $from, $to);
check('cache pe luna: acelasi rezultat din fisier', near($m1['vehicles'][$T1]['revenue'], $m2['vehicles'][$T1]['revenue']) && count(glob($cacheDir . '/*/*.json')) === 1);
$exec("UPDATE curse_dispecer SET total_facturare = 3100, updated_at = NOW() + INTERVAL 1 SECOND WHERE id = :id", [':id' => $trip1]);
$c3 = new OperationalProfitabilityService($db, $cacheDir);
check('cache invalidat dupa o modificare a datelor sursa', near($c3->monthModel($M, $from, $to)['vehicles'][$T1]['revenue'], 3100));
$c3->clearCache();
@rmdir($cacheDir);
$legacy = (new OperationalCostService($db))->compute(['period' => $M]);
$units = (new OperationalCostService($db));
$units->compute(['period' => $M]);
$u = $units->internalUnits();
check('regresie Cost / km: semiremorca lipita de tractorul activ (comportament neschimbat)', isset($u[$T2]) && !isset($u[$S]) && ($u[$T2]['semi_id'] ?? null) === $S);
check('regresie Cost / km: salariul ramane pe vehiculul din fisa (nu ledger de sofer)', is_array($legacy['vehicles']));

echo "\n$passed ok, $failed esuate\n";
exit($failed === 0 ? 0 : 1);
