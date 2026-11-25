<?php
declare(strict_types=1);

/**
 * Teste pentru cardurile KPI sincronizate din Rentabilitate Operationala: un singur set filtrat de randuri
 * (list()) da tabelul, totalurile si indicatorii; filtrele Tip / Beneficiar / Vehicul / Sofer / Status se combina,
 * selectiile incompatibile se scot, cardul din Tablou de bord are filtre proprii.
 *
 *   DB_NAME=fleet_fin_test php scripts/test_rentabilitate_kpi.php [de_la] [pana_la]
 *
 * Doar citire (nu scrie nimic); ruleaza pe datele reale ale copiei de test.
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
require_once $root . '/htdocs/models/DriverActivityHistoryModel.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/services/CostNormalizationService.php';
require_once $root . '/htdocs/services/ProfitabilityRegisterService.php';
// registrul cardurilor citeste constante din modelele paginilor-sursa
foreach (['DashboardModel', 'DocumentModel', 'EtollStatementModel', 'ExpenseModel', 'InterventionWorkflowModel', 'InvoiceModel'] as $model) {
    require_once $root . '/htdocs/models/' . $model . '.php';
}
require_once $root . '/htdocs/services/DashboardWidgetRegistry.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
}
function near(?float $a, ?float $b, float $eps = 0.05): bool
{
    return $a !== null && $b !== null && abs($a - $b) <= $eps * max(1.0, abs($a) / 1000);
}

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$from = $argv[1] ?? '2026-09-01';
$to = $argv[2] ?? '2026-11-18';
$svc = new ProfitabilityRegisterService($db);
$base = ['from' => $from, 'to' => $to];

/** Toate randurile (toate paginile) pentru filtre date — ca tabelul paginii. */
$allRows = static function (array $f) use ($svc): array {
    $rows = [];
    $page = 1;
    do {
        $out = $svc->list($f + ['per_page' => 100, 'page' => $page]);
        $rows = array_merge($rows, $out['rows']);
        $page++;
    } while ($page <= $out['pages']);
    return [$rows, $out];
};
/** Costul eligibil al randurilor, ca totalurile tabelului (semiremorci: doar restul nealocat). */
$rowsCost = static function (array $rows): float {
    $sum = 0.0;
    foreach ($rows as $r) {
        $sum += !empty($r['is_semi']) ? (float) $r['unallocated'] : (float) $r['cost_vehicle'] + (float) $r['cost_driver'];
    }
    return $sum;
};
$rowsKm = static fn (array $rows): float => array_sum(array_map(static fn (array $r): float => empty($r['is_semi']) ? (float) $r['km'] : 0.0, $rows));
$rowsRevenue = static fn (array $rows): float => array_sum(array_map(static fn (array $r): float => (float) $r['revenue'], $rows));
$kpiOk = static function (array $k): bool {
    return ($k['km'] <= 0 || (near($k['cost_km'], $k['cost'] / $k['km']) && near($k['revenue_km'], $k['revenue'] / $k['km'])
        && near($k['result_km'], $k['revenue_km'] - $k['cost_km'])))
        && ($k['cost'] <= 0 || near($k['coverage'], $k['revenue'] / $k['cost'] * 100));
};

echo "Perioada $from – $to\n\nAgregare ponderata, din aceleasi randuri ca tabelul\n";
[$rows, $out] = $allRows($base);
$k = $out['kpi'];
check('cost eligibil KPI = suma randurilor tabelului', near($k['cost'], $rowsCost($rows)), $k['cost'] . ' vs ' . $rowsCost($rows));
check('km KPI = suma km a randurilor (fara dublarea semiremorcilor)', near($k['km'], $rowsKm($rows)));
check('venit KPI = suma veniturilor randurilor', near($k['revenue'], $rowsRevenue($rows)));
check('vehicule: KPI = totalurile tabelului (cost, km, venit)', near($k['cost'], $out['totals']['cost']) && near($k['km'], $out['totals']['km']) && near($k['revenue'], $out['totals']['revenue']));
check('formule: cost/km, venit/km, rezultat/km = venit/km − cost/km, acoperire', $kpiOk($k));
$ratios = array_values(array_filter(array_map(static fn (array $r): ?float => $r['cost_total_km'], array_filter($rows, static fn (array $r): bool => empty($r['is_semi']) && $r['km'] > 0))));
$simpleAvg = $ratios !== [] ? array_sum($ratios) / count($ratios) : null;
check('nu e media simpla a rapoartelor pe vehicul', $simpleAvg === null || !near($simpleAvg, $k['cost_km'], 0.001), 'medie simpla ' . $simpleAvg . ' vs ponderat ' . $k['cost_km']);
check('datele incomplete raman vizibile (numar de unitati / elemente)', isset($k['unitati_incomplete'], $k['elemente_lipsa']) && $k['unitati_incomplete'] === count(array_filter($rows, static fn (array $r): bool => empty($r['is_semi']) && $r['incomplet'] !== [])));

echo "\nFiltru tip transport (din oricare card sau din bara de sus — aceeasi cerere)\n";
$types = array_keys($out['optiuni']['tipuri']);
$type = in_array('distributie', $types, true) ? 'distributie' : ($types[0] ?? '');
[$tRows, $tOut] = $allRows($base + ['tip' => $type]);
check("tip „{$type}”: toate randurile au activitate de acest tip", $tRows !== [] && array_filter($tRows, static fn (array $r): bool => !in_array($r['transport'], [$type, 'mixt'], true)) === []);
check("tip „{$type}”: KPI recalculat din randurile filtrate", near($tOut['kpi']['cost'], $rowsCost($tRows)) && near($tOut['kpi']['km'], $rowsKm($tRows)) && $kpiOk($tOut['kpi']));
check("tip „{$type}”: optiunile Vehicul / Sofer doar cu activitate de acest tip", count($tOut['optiuni']['vehicule']) <= count($out['optiuni']['vehicule']) && count($tOut['optiuni']['vehicule']) === count(array_filter($tRows, static fn (array $r): bool => $r['km'] > 0)));
check('aplicat = filtrul cerut (sursa de adevar pentru bara de sus)', $tOut['aplicat']['tip'] === $type);

echo "\nFiltru vehicul / sofer\n";
$tractor = null;
foreach ($rows as $r) {
    if (empty($r['is_semi']) && $r['km'] > 0 && $r['driver_count'] >= 1) {
        $tractor = $r;
        break;
    }
}
[$vRows, $vOut] = $allRows($base + ['vehicle_id' => $tractor['id']]);
check('vehicul ales: doar randul lui, cu aceleasi cifre ca in tabel', count($vRows) === 1 && $vRows[0]['id'] === $tractor['id'] && near($vRows[0]['km'], $tractor['km']) && near($vRows[0]['cost_total'], $tractor['cost_total']));
check('vehicul ales: KPI = randul vehiculului', near($vOut['kpi']['km'], $tractor['km']) && near($vOut['kpi']['cost'], $tractor['cost_total']));
$driverId = (int) $vOut['optiuni']['soferi'][0]['id'];
check('vehicul ales: optiunile Sofer = soferii lui', count($vOut['optiuni']['soferi']) === $tractor['driver_count']);

// Perspective coerente: km-ii soferului pe vehicule (mod Vehicule, filtru sofer) = randul soferului (mod Soferi)
[$dvRows, $dvOut] = $allRows($base + ['driver_id' => $driverId]);
[$dRows] = $allRows($base + ['driver_id' => $driverId, 'mode' => 'soferi']);
check('sofer ales (Vehicule): doar vehiculele lui', $dvRows !== [] && count($dvRows) === count(array_filter($rows, static fn (array $r): bool => in_array($r['id'], array_column($dvOut['optiuni']['vehicule'], 'id'), true))));
check('sofer ales: km pe vehicule = km-ii soferului din modul Soferi', count($dRows) === 1 && near($rowsKm($dvRows), $dRows[0]['km']), $rowsKm($dvRows) . ' vs ' . ($dRows[0]['km'] ?? 'n/a'));
check('sofer ales: venit pe vehicule = venitul soferului', near($rowsRevenue($dvRows), $dRows[0]['revenue']));
check('sofer ales: KPI corect calculat', $kpiOk($dvOut['kpi']));
[$sdRows] = $allRows($base + ['mode' => 'soferi', 'vehicle_id' => $tractor['id']]);
check('Soferi + vehicul ales: km-ii soferilor pe vehicul = km vehicul', near($rowsKm($sdRows), $tractor['km']), $rowsKm($sdRows) . ' vs ' . $tractor['km']);

echo "\nFiltre combinate si selectii incompatibile\n";
[$cRows, $cOut] = $allRows($base + ['vehicle_id' => $tractor['id'], 'driver_id' => $driverId]);
check('vehicul + sofer: un rand, doar activitatea soferului pe vehicul', count($cRows) === 1 && $cRows[0]['km'] <= $tractor['km'] + 0.01 && $cOut['anulate'] === []);
$lonely = null;
foreach ($out['optiuni']['vehicule'] as $v) {
    if (!in_array($v['id'], array_column($tOut['optiuni']['vehicule'], 'id'), true)) {
        $lonely = (int) $v['id'];
        break;
    }
}
if ($lonely !== null) {
    $x = $svc->list($base + ['tip' => $type, 'vehicle_id' => $lonely]);
    check("vehicul fara activitate „{$type}”: scos din filtru si raportat (nu tabel gol)", $x['aplicat']['vehicle_id'] === '' && $x['anulate'] === ['vehicle_id'] && $x['total'] === $tOut['total']);
} else {
    check('vehicul incompatibil cu tipul (nu exista in date — sarit)', true);
}
$otherDriver = null;
foreach ($out['optiuni']['soferi'] as $s) {
    if (!in_array($s['id'], array_column($vOut['optiuni']['soferi'], 'id'), true)) {
        $otherDriver = (int) $s['id'];
        break;
    }
}
if ($otherDriver !== null) {
    $kd = $svc->list($base + ['vehicle_id' => $tractor['id'], 'driver_id' => $otherDriver, 'keep' => 'driver_id']);
    $kv = $svc->list($base + ['vehicle_id' => $tractor['id'], 'driver_id' => $otherDriver, 'keep' => 'vehicle_id']);
    check('pereche vehicul + sofer fara activitate comuna: ramane filtrul ales ultimul (sofer)', $kd['aplicat']['driver_id'] === (string) $otherDriver && $kd['aplicat']['vehicle_id'] === '' && $kd['anulate'] === ['vehicle_id']);
    check('pereche vehicul + sofer fara activitate comuna: ramane filtrul ales ultimul (vehicul)', $kv['aplicat']['vehicle_id'] === (string) $tractor['id'] && $kv['aplicat']['driver_id'] === '' && $kv['anulate'] === ['driver_id']);
}
[$lRows, $lOut] = $allRows($base + ['status' => 'pierdere']);
check('status „pe pierdere” + KPI din aceleasi randuri', $lRows !== [] && near($lOut['kpi']['cost'], $rowsCost($lRows)) && $lOut['kpi']['result'] < 0);
[$tlRows, $tlOut] = $allRows($base + ['status' => 'pierdere', 'tip' => $type]);
check('tip + status combinate: intersectia filtrelor', count($tlRows) <= min(count($lRows), count($tRows)) && near($tlOut['kpi']['cost'], $rowsCost($tlRows)));

echo "\nResetare si Tablou de bord\n";
$reset = $svc->list($base + ['tip' => '', 'beneficiar' => 0, 'vehicle_id' => 0, 'driver_id' => 0]);
check('resetarea revine la totalul nefiltrat', near($reset['kpi']['cost'], $k['cost']) && $reset['total'] === $out['total']);
$dash = $svc->kpiSummary($base + ['tip' => $type]);
check('cardul din Tablou de bord = aceeasi sursa ca pagina (aceleasi cifre)', near($dash['kpi']['cost'], $tOut['kpi']['cost']) && near($dash['kpi']['cost_km'], $tOut['kpi']['cost_km']));
$widget = DashboardWidgetRegistry::get('rentabilitate.kpis');
check('widget inregistrat (lazy, filtre proprii, doar perioada din panou)', $widget !== null && $widget['render'] === 'lazy' && $widget['filters'] === ['period']);
$cfg = DashboardWidgetRegistry::normalizeConfig($widget, ['tip' => 'necunoscut', 'mode' => 'soferi', 'vehicle_id' => 'abc', 'driver_id' => '12']);
check('config-ul cardului se valideaza (tip / vehicul invalid ignorate)', !isset($cfg['tip']) && ($cfg['mode'] ?? '') === 'soferi' && !isset($cfg['vehicle_id']) && ($cfg['driver_id'] ?? 0) === 12, json_encode($cfg));
$dashOwn = $svc->kpiSummary($base + ['vehicle_id' => $tractor['id']]);
$page = $svc->list($base);
check('filtrul unui card din panou nu schimba rezultatul paginii (stare independenta)', near($page['kpi']['cost'], $k['cost']) && !near($dashOwn['kpi']['cost'], $k['cost']));

echo "\nNavigarea din card: Toata flota → Beneficiari → Tip transport → Vehicule / Soferi\n";
$same = static fn (array $x, array $y): bool => near($x['cost'], $y['cost']) && near($x['km'], $y['km']) && near($x['revenue'], $y['revenue'])
    && (($x['cost_km'] === null && $y['cost_km'] === null) || near($x['cost_km'], $y['cost_km'], 0.0005));
$bb = $svc->kpiBreakdown($base, 'beneficiar');
$kms = array_map(static fn (array $r): float => (float) $r['kpi']['km'], $bb['rows']);
$sorted = $kms;
rsort($sorted);
check('beneficiari: doar cu activitate in scop, ordonati dupa km', $bb['rows'] !== [] && $kms === $sorted
    && array_filter($bb['rows'], static fn (array $r): bool => $r['kpi']['km'] <= 0 && $r['kpi']['cost'] <= 0) === []);
$okRows = true;
foreach ($bb['rows'] as $r) {
    $okRows = $okRows && $same($r['kpi'], $svc->list($base + ['beneficiar' => (int) $r['value']])['kpi']);
}
check('fiecare beneficiar = exact ce arata pagina filtrata pe el (aceleasi randuri, agregat ponderat)', $okRows);
check('totalul defalcarii = indicatorii scopului curent', $same($bb['total'], $k));
$top = $bb['rows'][0];
$bt = $svc->kpiBreakdown($base + ['beneficiar' => (int) $top['value']], 'tip');
$okTypes = $bt['rows'] !== [];
foreach ($bt['rows'] as $r) {
    $okTypes = $okTypes && isset(OperationalProfitabilityService::TRANSPORT_LABELS[$r['value']])
        && $same($r['kpi'], $svc->list($base + ['beneficiar' => (int) $top['value'], 'tip' => $r['value']])['kpi']);
}
check('tipuri pentru „' . $top['label'] . '”: tipurile configurate, fiecare = pagina filtrata (beneficiar + tip)', $okTypes);
$scope2 = $base + ['beneficiar' => (int) $top['value'], 'tip' => $bt['rows'][0]['value']];
$bv = $svc->kpiBreakdown($scope2, 'vehicul');
$okVeh = $bv['rows'] !== [] && count($bv['rows']) <= count($svc->list($scope2)['optiuni']['vehicule']);
foreach (array_slice($bv['rows'], 0, 5) as $r) {
    $okVeh = $okVeh && $same($r['kpi'], $svc->list($scope2 + ['vehicle_id' => (int) $r['value']])['kpi']);
}
check('vehicule in beneficiar + tip: fiecare = pagina filtrata pe vehicul', $okVeh);
$bs = $svc->kpiBreakdown($scope2, 'sofer');
check('soferi in beneficiar + tip: nivel oferit doar cu date', $bs['rows'] !== [] && array_filter($bs['rows'], static fn (array $r): bool => $r['kpi']['km'] <= 0) === []);
$share = array_sum(array_map(static fn (array $r): float => (float) $r['share_km'], $bt['rows']));
check('ponderile pe tip insumeaza ~100% din km-ii beneficiarului', abs($share - 100) < 0.5, (string) $share);
$bbType = $svc->kpiBreakdown($base + ['tip' => $type], 'beneficiar');
check('cu tipul deja ales, beneficiarii sunt doar cei cu acel tip', $bbType['rows'] !== [] && count($bbType['rows']) <= count($bb['rows'])
    && array_filter($bbType['rows'], static fn (array $r): bool => $r['kpi']['km'] <= 0) === []);
$refused = false;
try {
    $svc->kpiBreakdown($base, 'nimic');
} catch (InvalidArgumentException) {
    $refused = true;
}
check('defalcare necunoscuta refuzata', $refused);

echo "\n" . ($failed === 0 ? 'TOATE TESTELE AU TRECUT' : "ESUATE: $failed") . " ($passed ok)\n";
exit($failed === 0 ? 0 : 1);
