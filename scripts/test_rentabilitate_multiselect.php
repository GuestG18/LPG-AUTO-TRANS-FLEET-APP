<?php
declare(strict_types=1);

/**
 * Teste pentru filtrele cu selectie multipla din Rentabilitate Operationala: beneficiar, vehicle_id, driver_id,
 * origine (Loc incarcare), destinatie (Zona distributie), depozit accepta liste ("3,5,8" sau [3,5,8]).
 * O selectie de N valori = suma celor N selectii individuale (km, venit, cost), in toate modurile.
 *
 *   DB_NAME=fleet_fin_test php scripts/test_rentabilitate_multiselect.php [de_la] [pana_la]
 *
 * Doar citire.
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

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
}
function near(float $a, float $b, float $tol = 1.0): bool
{
    return abs($a - $b) <= $tol;
}

$svc = new ProfitabilityRegisterService(get_pdo());
$from = $argv[1] ?? date('Y-m-01', strtotime(date('Y-m-01') . ' -2 months'));
$to = $argv[2] ?? date('Y-m-d');
$base = ['from' => $from, 'to' => $to, 'per_page' => 100];
$all = $svc->list($base + ['mode' => 'vehicule']);
$sum = static function (array $kpis, string $k): float {
    return array_sum(array_map(static fn (array $x): float => (float) $x[$k], $kpis));
};
// suma a N selectii individuale vs selectia multipla (km, venit, cost)
$same = static function (string $label, array $multi, array $singles) use ($sum): void {
    check("$label: km = suma", near($multi['km'], $sum($singles, 'km')), sprintf('%.1f vs %.1f', $multi['km'], $sum($singles, 'km')));
    check("$label: venit = suma", near($multi['revenue'], $sum($singles, 'revenue')));
    check("$label: cost = suma", near($multi['cost'], $sum($singles, 'cost'), 2.0), sprintf('%.2f vs %.2f', $multi['cost'], $sum($singles, 'cost')));
};

echo "Vehicule ($from – $to)\n";
$veh = array_slice(array_column($all['optiuni']['vehicule'], 'id'), 0, 3);
if (count($veh) >= 2) {
    $multi = $svc->list($base + ['mode' => 'vehicule', 'vehicle_id' => implode(',', $veh)]);
    $same('3 vehicule', $multi['kpi'], array_map(static fn (int $id): array => $svc->list($base + ['mode' => 'vehicule', 'vehicle_id' => $id])['kpi'], $veh));
    check('3 vehicule: tabelul are exact randurile alese', count($multi['rows']) === count($veh) && array_diff(array_column($multi['rows'], 'id'), $veh) === []);
    check('aplicat = lista ("a,b,c")', $multi['aplicat']['vehicle_id'] === implode(',', $veh));
    check('lista ca tablou = lista ca text', near($svc->list($base + ['mode' => 'vehicule', 'vehicle_id' => $veh])['kpi']['km'], $multi['kpi']['km']));
    check('duplicatele si valorile invalide se ignora', $svc->list($base + ['mode' => 'vehicule', 'vehicle_id' => $veh[0] . ',' . $veh[0] . ',abc,0'])['aplicat']['vehicle_id'] === (string) $veh[0]);
    $mixed = $svc->list($base + ['mode' => 'vehicule', 'vehicle_id' => $veh[0] . ',999999']);
    check('un vehicul fara activitate din lista se scoate, restul ramane', $mixed['aplicat']['vehicle_id'] === (string) $veh[0] && $mixed['anulate'] === ['vehicle_id']);
    $drv = $svc->list($base + ['mode' => 'soferi', 'vehicle_id' => implode(',', $veh)]);
    check('modul Soferi pe 3 vehicule: aceiasi km', near($drv['kpi']['km'], $multi['kpi']['km']), sprintf('%.1f vs %.1f', $drv['kpi']['km'], $multi['kpi']['km']));
}

echo "Soferi\n";
$drivers = array_slice(array_column($all['optiuni']['soferi'], 'id'), 0, 3);
if (count($drivers) >= 2) {
    $multi = $svc->list($base + ['mode' => 'soferi', 'driver_id' => implode(',', $drivers)]);
    $same('3 soferi (modul Soferi)', $multi['kpi'], array_map(static fn (int $id): array => $svc->list($base + ['mode' => 'soferi', 'driver_id' => $id])['kpi'], $drivers));
    $vm = $svc->list($base + ['mode' => 'vehicule', 'driver_id' => implode(',', $drivers)]);
    check('3 soferi in modul Vehicule: km = suma soferilor', near($vm['kpi']['km'], $sum(array_map(static fn (int $id): array => $svc->list($base + ['mode' => 'vehicule', 'driver_id' => $id])['kpi'], $drivers), 'km')));
    $lm = $svc->list($base + ['mode' => 'locatii', 'dim' => 'depozit', 'driver_id' => implode(',', $drivers)]);
    check('3 soferi in modul Locatii: aceiasi km ca in Vehicule', near($lm['kpi']['km'], $vm['kpi']['km']));
}

echo "Beneficiari\n";
$benefs = [];
foreach ($svc->kpiBreakdown($base + ['mode' => 'vehicule'], 'beneficiar')['rows'] as $r) {
    $benefs[] = (int) $r['value'];
}
$benefs = array_slice($benefs, 0, 3);
if (count($benefs) >= 2) {
    $multi = $svc->list($base + ['mode' => 'vehicule', 'beneficiar' => implode(',', $benefs)]);
    $same(count($benefs) . ' beneficiari', $multi['kpi'], array_map(static fn (int $id): array => $svc->list($base + ['mode' => 'vehicule', 'beneficiar' => $id])['kpi'], $benefs));
    $bd = $svc->kpiBreakdown($base + ['mode' => 'vehicule', 'beneficiar' => implode(',', $benefs)], 'beneficiar');
    check('cardul KPI pe beneficiari arata doar beneficiarii alesi', array_diff(array_map('intval', array_column($bd['rows'], 'value')), $benefs) === [] && count($bd['rows']) === count($benefs));
}

echo "Locatii (Loc incarcare / Zona distributie / Depozit)\n";
foreach (['origine', 'destinatie', 'depozit'] as $dim) {
    $opts = array_values(array_filter($all['optiuni']['locatii_filtru'][$dim], static fn (array $o): bool => $o['id'] > 0));
    $ids = array_slice(array_column($opts, 'id'), 0, 3);
    if (count($ids) < 2) {
        continue;
    }
    $multi = $svc->list($base + ['mode' => 'vehicule', $dim => implode(',', $ids)]);
    $same("$dim x" . count($ids), $multi['kpi'], array_map(static fn (int $id): array => $svc->list($base + ['mode' => 'vehicule', $dim => $id])['kpi'], $ids));
    $lm = $svc->list($base + ['mode' => 'locatii', 'dim' => $dim, $dim => implode(',', $ids)]);
    check("$dim: modul Locatii arata exact locurile alese", count($lm['rows']) === count($ids) && array_diff(array_column($lm['rows'], 'id'), $ids) === []);
    check("$dim: optiunile raman complete cu filtrul activ (se pot adauga locuri)", count($lm['optiuni']['locatii_filtru'][$dim]) === count($all['optiuni']['locatii_filtru'][$dim]));
}
$cross = $svc->list($base + ['mode' => 'vehicule', 'origine' => implode(',', array_slice(array_column($all['optiuni']['locatii_filtru']['origine'], 'id'), 0, 2)),
    'destinatie' => implode(',', array_slice(array_column($all['optiuni']['locatii_filtru']['destinatie'], 'id'), 0, 2))]);
check('Loc incarcare + Zona distributie se combina (SI intre dimensiuni)', $cross['kpi']['km'] <= $all['kpi']['km'] + 0.5);

echo "Rute exacte (Loc incarcare → Zona distributie)\n";
$routes = array_values(array_filter($all['optiuni']['locatii_filtru']['ruta'], static fn (array $r): bool => !str_contains($r['id'], '-1')));
$routes = array_slice($routes, 0, 3);
if (count($routes) >= 2) {
    $ids = array_column($routes, 'id');
    $multi = $svc->list($base + ['mode' => 'vehicule', 'ruta' => implode(',', $ids)]);
    $same(count($ids) . ' rute', $multi['kpi'], array_map(static fn (string $id): array => $svc->list($base + ['mode' => 'vehicule', 'ruta' => $id])['kpi'], $ids));
    check('aplicat ruta = lista aleasa', $multi['aplicat']['ruta'] === implode(',', $ids));
    // o ruta = aceeasi activitate ca Loc incarcare + Zona distributie fixate impreuna
    [$o, $d] = explode(':', $ids[0]);
    check('o ruta = Loc incarcare + Zona distributie', near($svc->list($base + ['mode' => 'vehicule', 'ruta' => $ids[0]])['kpi']['km'], $svc->list($base + ['mode' => 'vehicule', 'origine' => $o, 'destinatie' => $d])['kpi']['km']));
    // rutele exacte nu includ combinatiile incrucisate (spre deosebire de liste separate de locuri si zone)
    $os = array_values(array_unique(array_map(static fn (string $r): string => explode(':', $r)[0], $ids)));
    $ds = array_values(array_unique(array_map(static fn (string $r): string => explode(':', $r)[1], $ids)));
    $crossKm = $svc->list($base + ['mode' => 'vehicule', 'origine' => implode(',', $os), 'destinatie' => implode(',', $ds)])['kpi']['km'];
    check('rutele exacte ≤ combinatia locuri × zone', $multi['kpi']['km'] <= $crossKm + 0.5, sprintf('%.1f vs %.1f', $multi['kpi']['km'], $crossKm));
    $drv = $svc->list($base + ['mode' => 'soferi', 'ruta' => implode(',', $ids)]);
    check('rute in modul Soferi: aceiasi km', near($drv['kpi']['km'], $multi['kpi']['km']));
    $lm = $svc->list($base + ['mode' => 'locatii', 'dim' => 'origine', 'ruta' => implode(',', $ids)]);
    check('rute in modul Locatii: aceiasi km', near($lm['kpi']['km'], $multi['kpi']['km']));
    check('rutele invalide se ignora', $svc->list($base + ['mode' => 'vehicule', 'ruta' => 'abc,1:,0:5,' . $ids[0]])['aplicat']['ruta'] === $ids[0]);
    $br = $svc->kpiBreakdown($base + ['mode' => 'vehicule', 'ruta' => implode(',', $ids)], 'ruta');
    check('cardul KPI pe ruta arata doar rutele alese', count($br['rows']) === count($ids) && array_diff(array_column($br['rows'], 'value'), $ids) === []);
}

echo "Export\n";
if (count($veh) >= 2) {
    $csv = $svc->exportCsv($base + ['mode' => 'vehicule', 'vehicle_id' => implode(',', $veh)]);
    check('exportul contine exact vehiculele alese', substr_count(trim($csv), "\n") === count($veh));
}

echo "\n" . ($failed === 0 ? "TOATE TESTELE AU TRECUT ($passed ok)" : "$failed ESUATE, $passed ok") . "\n";
exit($failed === 0 ? 0 : 1);
