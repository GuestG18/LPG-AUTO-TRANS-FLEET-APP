<?php
declare(strict_types=1);

/**
 * Teste pentru rapoartele pe locatie din Rentabilitate Operationala (modul Locatii: Origine / Destinatie / Depozit)
 * si pentru stratul de locuri fizice (LocationDimensionService).
 *
 *   DB_NAME=fleet_fin_test php scripts/test_rentabilitate_locatii.php [de_la] [pana_la]
 *
 * Verifica: rolurile pe tip de transport, totalurile pe locatii = totalurile pe vehicule (aceleasi filtre),
 * filtrul de locatie in modul Vehicule = randul locatiei, detaliul (vehicule / rute) = randul, operatiile
 * update / move / merge. Tabelele locatii_fizice* sunt salvate la inceput si refacute la final.
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
function near(float $a, float $b, float $tol = 0.5): bool
{
    return abs($a - $b) <= $tol;
}

$db = get_pdo();
LocationDimensionService::ensureSchema($db); // DDL in afara oricarei tranzactii
$backup = [
    'places' => $db->query('SELECT * FROM locatii_fizice')->fetchAll(PDO::FETCH_ASSOC),
    'sources' => $db->query('SELECT * FROM locatii_fizice_surse')->fetchAll(PDO::FETCH_ASSOC),
];
$from = $argv[1] ?? date('Y-m-01', strtotime(date('Y-m-01') . ' -2 months'));
$to = $argv[2] ?? date('Y-m-d');

try {
    echo "Normalizare\n";
    check('diacritice, punctuatie si ordinea cuvintelor nu conteaza', LocationDimensionService::normalize('Contești') === LocationDimensionService::normalize('contesti')
        && LocationDimensionService::normalize('ALL-RO') === LocationDimensionService::normalize('All RO')
        && LocationDimensionService::normalize('Galati Unicom') === LocationDimensionService::normalize('Unicom  Galati'));
    check('nume diferite raman diferite', LocationDimensionService::normalize('Tileagd') !== LocationDimensionService::normalize('MOL Tileagd'));

    $loc = new LocationDimensionService($db);
    $loc->sync();
    $unmapped = (int) $db->query("SELECT (SELECT COUNT(*) FROM configurare_locuri_incarcare l WHERE NOT EXISTS (SELECT 1 FROM locatii_fizice_surse s WHERE s.sursa = 'loc' AND s.sursa_id = l.id))
        + (SELECT COUNT(*) FROM configurare_zone_distributie z WHERE NOT EXISTS (SELECT 1 FROM locatii_fizice_surse s WHERE s.sursa = 'zona' AND s.sursa_id = z.id))")->fetchColumn();
    check('fiecare loc de incarcare si fiecare zona are un loc fizic dupa sync', $unmapped === 0, $unmapped . ' nelegate');
    check('sync e idempotent', (new LocationDimensionService($db))->sync() === 0);

    echo "Roluri pe tip de transport\n";
    $pair = $db->query('SELECT l.id AS loc, z.id AS zona FROM configurare_locuri_incarcare l, configurare_zone_distributie z LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($pair) {
        $r = $loc->roles('primar', (int) $pair['loc'], (int) $pair['zona']);
        check('Primar: depozit = destinatia (zona)', $r['depozit'] === $r['destinatie'] && $r['origine'] !== null);
        $r = $loc->roles('primar_tona', (int) $pair['loc'], (int) $pair['zona']);
        check('Primar tone: depozit = destinatia', $r['depozit'] === $r['destinatie']);
        $r = $loc->roles('distributie', (int) $pair['loc'], (int) $pair['zona']);
        check('Distributie: depozit = locul de incarcare', $r['depozit'] === $r['origine'] && $r['destinatie'] !== null);
        $r = $loc->roles('primar_distributie', (int) $pair['loc'], (int) $pair['zona']);
        check('Primar + Distributie: fara depozit', $r['depozit'] === null);
        $r = $loc->roles('compresor', 0, 0);
        check('Compresor fara loc: totul nespecificat', $r === ['origine' => null, 'destinatie' => null, 'depozit' => null]);
    }

    echo "Totaluri ($from – $to)\n";
    $svc = new ProfitabilityRegisterService($db);
    $types = array_keys($svc->list(['from' => $from, 'to' => $to])['optiuni']['tipuri']);
    $scopes = [[]];
    foreach (array_slice($types, 0, 3) as $t) {
        $scopes[] = ['tip' => $t];
    }
    foreach ($scopes as $f) {
        $f += ['from' => $from, 'to' => $to];
        $veh = $svc->list($f + ['mode' => 'vehicule'])['kpi'];
        foreach (array_keys(LocationDimensionService::DIMENSIONS) as $dim) {
            $l = $svc->list($f + ['mode' => 'locatii', 'dim' => $dim, 'per_page' => 100]);
            $label = ($f['tip'] ?? 'toate tipurile') . ' / ' . $dim;
            check("$label: km si venit = modul Vehicule", near($l['kpi']['km'], $veh['km']) && near($l['kpi']['revenue'], $veh['revenue']),
                sprintf('km %.1f vs %.1f, venit %.2f vs %.2f', $l['kpi']['km'], $veh['km'], $l['kpi']['revenue'], $veh['revenue']));
            // fara filtru, vehiculele fara curse si semiremorcile necuplate au cost care nu tine de nicio locatie
            check("$label: costul pe locatii " . (isset($f['tip']) ? '=' : '≤') . ' modul Vehicule',
                isset($f['tip']) ? near($l['kpi']['cost'], $veh['cost'], 1.0) : $l['kpi']['cost'] <= $veh['cost'] + 0.5,
                sprintf('%.2f vs %.2f', $l['kpi']['cost'], $veh['cost']));
            $sumKm = array_sum(array_column($l['rows'], 'km'));
            check("$label: suma randurilor = totalul", $l['total'] > 100 || near($sumKm, $l['kpi']['km']));
        }
    }

    echo "Filtru de locatie si detaliu\n";
    $l = $svc->list(['from' => $from, 'to' => $to, 'mode' => 'locatii', 'dim' => 'depozit', 'per_page' => 100]);
    $top = null;
    foreach ($l['rows'] as $row) {
        if ($row['id'] > 0) {
            $top = $row;
            break;
        }
    }
    if ($top === null) {
        check('exista cel putin un depozit cu activitate in perioada', false);
    } else {
        $v = $svc->list(['from' => $from, 'to' => $to, 'mode' => 'vehicule', 'depozit' => $top['id'], 'per_page' => 100]);
        check('modul Vehicule cu depozit = randul depozitului (km, cost, venit)', near($v['kpi']['km'], $top['km']) && near($v['kpi']['cost'], $top['cost_total'], 1.0) && near($v['kpi']['revenue'], $top['revenue']),
            sprintf('km %.1f/%.1f cost %.2f/%.2f', $v['kpi']['km'], $top['km'], $v['kpi']['cost'], $top['cost_total']));
        check('filtrul de locatie apare in aplicat', (int) $v['aplicat']['depozit'] === $top['id'] && (int) $v['aplicat']['origine'] === 0);
        $s = $svc->list(['from' => $from, 'to' => $to, 'mode' => 'soferi', 'depozit' => $top['id'], 'per_page' => 100]);
        check('modul Soferi cu depozit: aceiasi km', near($s['kpi']['km'], $top['km'], 1.0), sprintf('%.1f vs %.1f', $s['kpi']['km'], $top['km']));
        $d = $svc->locationDetail(['from' => $from, 'to' => $to, 'dim' => 'depozit'], $top['id']);
        check('detaliu: suma pe vehicule = randul', near(array_sum(array_column($d['vehicles'], 'km')), $top['km']) && near(array_sum(array_column($d['vehicles'], 'cost_total')), $top['cost_total'], 1.0));
        check('detaliu: suma pe rute = randul', near(array_sum(array_column($d['routes'], 'km')), $top['km']) && near(array_sum(array_column($d['routes'], 'revenue')), $top['revenue']));
        check('detaliu: numarul de vehicule = coloana Vehicule', count($d['vehicles']) === $top['vehicle_count']);
        // incarcarea pe vehicul (Pe vehicule): tone, clienti, tone lipsa
        $sumV = static fn (string $k): float => array_sum(array_column($d['vehicles'], $k));
        check('detaliu: tone incarcate / livrate / clienti / lipsa pe vehicule = randul', near($sumV('tone_inc'), $top['tone_inc'], 0.01) && near($sumV('tone_liv'), $top['tone_liv'], 0.01)
            && abs($sumV('clienti') - $top['clienti']) <= count($d['vehicles']) && near($sumV('tone_lipsa'), $top['tone_lipsa'], 0.01));
        $badFill = array_filter($d['vehicles'], static fn (array $x): bool => $x['capacitate'] > 0
            && !near($x['grad_umplere'], ($x['capacitate'] - $x['tone_lipsa'] + $x['tone_peste']) / $x['capacitate'] * 100, 0.01));
        check('detaliu: umplere = (capacitate − lipsa + peste) / capacitate', $badFill === []);
        check('detaliu: tone lipsa / peste nu sunt negative', array_filter($d['vehicles'], static fn (array $x): bool => $x['tone_lipsa'] < -0.001 || $x['tone_peste'] < -0.001) === []);
        $all = $svc->list(['from' => $from, 'to' => $to, 'mode' => 'locatii', 'dim' => 'depozit', 'per_page' => 100]);
        $sql = $db->query("SELECT SUM(COALESCE(c.cantitate_incarcata, 0)) inc, SUM(COALESCE(c.tona_livrata, c.cantitate_incarcata, 0)) liv, SUM(COALESCE(c.nr_clienti, 0)) cl,
              SUM(CASE WHEN c.capacitate_transport > 0 AND c.cantitate_incarcata > 0 THEN GREATEST(c.capacitate_transport - c.cantitate_incarcata, 0) ELSE 0 END) lipsa
              FROM curse_dispecer c JOIN vehicule v ON v.id = c.vehicle_id
             WHERE c.deleted_at IS NULL AND c.data_inceput BETWEEN " . $db->quote($from) . ' AND ' . $db->quote($to) . "
               AND v.tip_vehicul IN ('cap_tractor', 'camion')")->fetch(PDO::FETCH_ASSOC);
        $sumA = static fn (string $k): float => array_sum(array_column($all['rows'], $k));
        check('flota: tone incarcate / livrate / clienti / lipsa = curse_dispecer', near($sumA('tone_inc'), (float) $sql['inc'], 0.5) && near($sumA('tone_liv'), (float) $sql['liv'], 0.5)
            && abs($sumA('clienti') - (int) $sql['cl']) <= count($all['rows']) && near($sumA('tone_lipsa'), (float) $sql['lipsa'], 0.5),
            sprintf('inc %.2f/%.2f lipsa %.2f/%.2f', $sumA('tone_inc'), $sql['inc'], $sumA('tone_lipsa'), $sql['lipsa']));
        $bd = $svc->kpiBreakdown(['from' => $from, 'to' => $to, 'mode' => 'vehicule', 'depozit' => $top['id']], 'beneficiar');
        check('defalcarea cardului KPI ramane in locatie', near(array_sum(array_map(static fn (array $r): float => $r['kpi']['km'], $bd['rows'])), $top['km'], 1.0));
        // cardurile KPI: nivelul Locatii (Loc incarcare / Zona distributie / Depozit)
        foreach (array_keys(LocationDimensionService::DIMENSIONS) as $dim) {
            $bl = $svc->kpiBreakdown(['from' => $from, 'to' => $to, 'mode' => 'vehicule'], $dim);
            check("card KPI pe $dim: suma km = toata flota", near(array_sum(array_map(static fn (array $r): float => $r['kpi']['km'], $bl['rows'])), $bl['total']['km'], 1.0));
            if ($bl['rows'] !== []) {
                $first = $bl['rows'][0];
                $pickList = $svc->list(['from' => $from, 'to' => $to, 'mode' => 'vehicule', $dim => (int) $first['value']]);
                check("card KPI pe $dim: alegerea = filtrul paginii (Vehicule)", near($pickList['kpi']['km'], $first['kpi']['km']) && near($pickList['kpi']['cost'], $first['kpi']['cost'], 1.0)
                    && (int) $pickList['aplicat'][$dim] === (int) $first['value']);
                $pickDrv = $svc->list(['from' => $from, 'to' => $to, 'mode' => 'soferi', $dim => (int) $first['value']]);
                check("card KPI pe $dim: aceeasi locatie in modul Soferi (km)", near($pickDrv['kpi']['km'], $first['kpi']['km'], 1.0));
            }
        }
        // cardurile KPI: nivelul Rută (Loc incarcare → Zona distributie), fara tip de transport ales
        $br = $svc->kpiBreakdown(['from' => $from, 'to' => $to, 'mode' => 'vehicule'], 'ruta');
        check('card KPI pe ruta: suma km = toata flota', near(array_sum(array_map(static fn (array $r): float => $r['kpi']['km'], $br['rows'])), $br['total']['km'], 1.0));
        check('card KPI pe ruta: valoare „origine:destinatie” si eticheta cu sageata', $br['rows'] !== [] && preg_match('/^-?\d+:-?\d+$/', $br['rows'][0]['value']) === 1 && str_contains($br['rows'][0]['label'], '→'));
        if ($br['rows'] !== []) {
            [$ro, $rd] = array_map('intval', explode(':', $br['rows'][0]['value']));
            $rl = $svc->list(['from' => $from, 'to' => $to, 'mode' => 'vehicule', 'origine' => $ro, 'destinatie' => $rd]);
            check('card KPI pe ruta: alegerea = filtrele Loc incarcare + Zona distributie', near($rl['kpi']['km'], $br['rows'][0]['kpi']['km']) && near($rl['kpi']['cost'], $br['rows'][0]['kpi']['cost'], 1.0)
                && $rl['aplicat']['origine'] === (string) $ro && $rl['aplicat']['destinatie'] === (string) $rd);
            $rs = $svc->list(['from' => $from, 'to' => $to, 'mode' => 'soferi', 'origine' => $ro, 'destinatie' => $rd]);
            check('card KPI pe ruta: aceeasi ruta in modul Soferi (km)', near($rs['kpi']['km'], $br['rows'][0]['kpi']['km'], 1.0));
        }
        $blTip = $svc->kpiBreakdown(['from' => $from, 'to' => $to, 'mode' => 'vehicule', 'tip' => 'distributie'], 'destinatie');
        $tipKm = $svc->list(['from' => $from, 'to' => $to, 'mode' => 'vehicule', 'tip' => 'distributie'])['kpi']['km'];
        check('card KPI: zonele de distributie respecta tipul ales', near(array_sum(array_map(static fn (array $r): float => $r['kpi']['km'], $blTip['rows'])), $tipKm, 1.0));
        $none = $svc->list(['from' => $from, 'to' => $to, 'mode' => 'vehicule', 'depozit' => -1, 'per_page' => 100]);
        $noneRow = array_values(array_filter($l['rows'], static fn (array $r): bool => $r['id'] === -1))[0] ?? null;
        check('depozit = -1 (fara depozit) = randul „Fără depozit”', $noneRow === null ? $none['kpi']['km'] <= 0 : near($none['kpi']['km'], $noneRow['km']));
        $csv = $svc->exportCsv(['from' => $from, 'to' => $to, 'mode' => 'locatii', 'dim' => 'depozit']);
        check('export CSV pe locatii: antet + un rand pe locatie', str_contains($csv, '"Depozit / Bază";Vehicule') && substr_count(trim($csv), "\n") === $l['total']);
    }

    echo "Gestionare (update / move / merge)\n";
    $places = $loc->overview()['places'];
    $multi = array_values(array_filter($places, static fn (array $p): bool => count($p['surse']) >= 2));
    if (count($places) < 2 || $multi === []) {
        check('date suficiente pentru testele de gestionare', false);
    } else {
        $p = $multi[0];
        $loc->updatePlace($p['id'], 'Test redenumit', 'regiune');
        $after = (new LocationDimensionService($db))->places()[$p['id']];
        check('update: nume si tip salvate', $after['nume'] === 'Test redenumit' && $after['tip'] === 'regiune');
        try {
            $loc->updatePlace($p['id'], '  ', 'punct');
            check('update: numele gol e refuzat', false);
        } catch (InvalidArgumentException) {
            check('update: numele gol e refuzat', true);
        }
        $src = $p['surse'][0];
        $newId = $loc->moveSource($src['sursa'], $src['sursa_id'], 0);
        $fresh = new LocationDimensionService($db);
        check('move la loc nou: sursa are locul nou, separat', $newId !== $p['id'] && isset($fresh->places()[$newId]) && $fresh->places()[$newId]['nume'] === trim($src['nume']));
        $roleKey = $src['sursa'] === 'loc' ? 'origine' : 'destinatie';
        $r = $fresh->roles('primar', $src['sursa'] === 'loc' ? $src['sursa_id'] : 0, $src['sursa'] === 'zona' ? $src['sursa_id'] : 0);
        check('move: rolul cursei urmeaza noul loc', $r[$roleKey] === $newId);
        $loc->merge($newId, $p['id']);
        $fresh = new LocationDimensionService($db);
        check('merge: locul unit dispare, sursa revine', !isset($fresh->places()[$newId])
            && $fresh->roles('primar', $src['sursa'] === 'loc' ? $src['sursa_id'] : 0, $src['sursa'] === 'zona' ? $src['sursa_id'] : 0)[$roleKey] === $p['id']);
        $manual = (int) $db->query("SELECT manual FROM locatii_fizice_surse WHERE sursa = " . $db->quote($src['sursa']) . ' AND sursa_id = ' . (int) $src['sursa_id'])->fetchColumn();
        check('merge / move marcheaza legatura ca manuala', $manual === 1);
        // o singura sursa: mutarea ei goleste locul vechi, care se sterge
        $single = array_values(array_filter($loc->overview()['places'], static fn (array $x): bool => count($x['surse']) === 1 && $x['id'] !== $p['id']));
        if ($single !== []) {
            $s1 = $single[0]['surse'][0];
            $loc->moveSource($s1['sursa'], $s1['sursa_id'], $p['id']);
            check('move: locul ramas fara surse se sterge', !isset((new LocationDimensionService($db))->places()[$single[0]['id']]));
        }
        try {
            $loc->merge($p['id'], $p['id']);
            check('merge cu el insusi refuzat', false);
        } catch (InvalidArgumentException) {
            check('merge cu el insusi refuzat', true);
        }
    }
} finally {
    // refacerea starii initiale a locurilor fizice
    $db->exec('DELETE FROM locatii_fizice_surse');
    $db->exec('DELETE FROM locatii_fizice');
    foreach (['locatii_fizice' => $backup['places'], 'locatii_fizice_surse' => $backup['sources']] as $table => $rows) {
        foreach ($rows as $row) {
            $cols = array_keys($row);
            $st = $db->prepare("INSERT INTO $table (`" . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols)) . ')');
            $st->execute(array_combine(array_map(static fn (string $c): string => ':' . $c, $cols), array_values($row)));
        }
    }
}

echo "\n" . ($failed === 0 ? "TOATE TESTELE AU TRECUT ($passed ok)" : "$failed ESUATE, $passed ok") . "\n";
exit($failed === 0 ? 0 : 1);
