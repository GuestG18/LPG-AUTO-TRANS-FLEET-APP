<?php
declare(strict_types=1);

/**
 * Teste pentru cardul „Valoare initiala (total)” din Istoric Financiar Vehicul (navigare in card):
 * categorii → subcategorii → tipuri de cost → tranzactii, reconcilierea cu totalul si restrangerea tabelului.
 *
 *   DB_NAME=fleet_fin_test php scripts/test_istoric_valoare_initiala.php
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
require_once $root . '/htdocs/services/FinancialFoundationSchema.php';
require_once $root . '/htdocs/services/VehicleFinancialRegisterService.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
}
function near(float $a, float $b): bool
{
    return abs($a - $b) < 0.015;
}

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$svc = new VehicleFinancialRegisterService($db);
$page = [];

echo "Nivelul 0 si categoriile principale\n";
$list = $svc->list($page + ['per_page' => 50]);
$top = $svc->initialValueBreakdown($page);
check('valoarea de la nivelul 0 = KPI-ul paginii (valoare_initiala)', near($top['valoare_initiala'], $list['kpis']['valoare_initiala']), $top['valoare_initiala'] . ' vs ' . $list['kpis']['valoare_initiala']);
check('suma categoriilor se reconciliaza cu valoarea initiala (diferenta 0)', abs($top['reconciliere']['diferenta']) < 0.015, json_encode($top['reconciliere']));
$sumRows = array_sum(array_column($top['randuri'], 'total'));
check('suma randurilor de la nivelul 1 = totalul nivelului', near($sumRows, $top['total']));
check('categoriile vin din arbore (nu sunt hardcodate) si au costuri', $top['randuri'] !== [] && array_filter($top['randuri'], static fn (array $r): bool => $r['count'] <= 0) === []);
check('ponderile categoriilor insumeaza 100%', abs(array_sum(array_column($top['randuri'], 'share')) - 100) < 0.05);

echo "\nSubcategorii / tipuri / tranzactii\n";
$okLevels = true;
$detail = '';
foreach ($top['randuri'] as $cat) {
    $b = $svc->initialValueBreakdown($page, $cat['id']);
    if (!near($b['total'], $cat['total'])) {
        $okLevels = false;
        $detail .= $cat['label'] . ': total ' . $b['total'] . ' vs ' . $cat['total'] . '; ';
    }
    if ($b['nivel'] === 'tipuri' || $b['nivel'] === 'categorii') {
        if (!near(array_sum(array_column($b['randuri'], 'total')), $b['total'])) {
            $okLevels = false;
            $detail .= $cat['label'] . ': copiii nu insumeaza parintele; ';
        }
        if ($b['nivel'] === 'tipuri') {
            foreach ($b['randuri'] as $t) {
                $tx = $svc->initialValueBreakdown($page, $cat['id'], $t['id']);
                $in = array_sum(array_map(static fn (array $l): float => $l['in_valoare'] ? (float) $l['suma'] : 0.0, $tx['randuri']));
                if ($tx['nivel'] !== 'tranzactii' || !near($in, $t['total']) || count($tx['randuri']) !== $t['count']) {
                    $okLevels = false;
                    $detail .= $cat['label'] . ' / ' . $t['label'] . ': tranzactiile nu dau totalul tipului; ';
                }
            }
        }
    } else {
        $in = array_sum(array_map(static fn (array $l): float => $l['in_valoare'] ? (float) $l['suma'] : 0.0, $b['randuri']));
        if (!near($in, $b['total']) || empty($b['tip']['unic'])) {
            $okLevels = false;
            $detail .= $cat['label'] . ': categoria cu un singur tip nu duce direct la tranzactii; ';
        }
    }
}
check('fiecare nivel: copiii insumeaza parintele, tranzactiile dau totalul tipului', $okLevels, $detail);
$withTypes = null;
foreach ($top['randuri'] as $cat) {
    $b = $svc->initialValueBreakdown($page, $cat['id']);
    if ($b['nivel'] === 'tipuri') {
        $withTypes = [$cat, $b];
        break;
    }
}
if ($withTypes !== null) {
    [$cat, $b] = $withTypes;
    $tx = $svc->initialValueBreakdown($page, $cat['id'], $b['randuri'][0]['id']);
    $l = $tx['randuri'][0];
    check('tranzactia pastreaza descriere, furnizor, data, suma, moneda, tratament, document, dosar',
        array_key_exists('descriere', $l) && array_key_exists('furnizor', $l) && array_key_exists('data', $l) && array_key_exists('suma_originala', $l)
        && array_key_exists('moneda', $l) && array_key_exists('tratament_label', $l) && array_key_exists('document', $l) && $l['dosar'] !== '' && $l['id'] > 0);
}

echo "\nRestrangerea tabelului la categorie\n";
$okTable = true;
foreach ($top['randuri'] as $cat) {
    $f = $svc->list($page + ['per_page' => 50, 'fin_node' => $cat['id']]);
    $expected = $svc->initialValueBreakdown($page, $cat['id']);
    $dossiers = [];
    $rows = $expected['nivel'] === 'tranzactii' ? $expected['randuri'] : [];
    if ($expected['nivel'] === 'tipuri') {
        foreach ($expected['randuri'] as $t) {
            $rows = array_merge($rows, $svc->initialValueBreakdown($page, $cat['id'], $t['id'])['randuri']);
        }
    }
    foreach ($rows as $l) {
        if ($l['in_valoare']) {
            $dossiers[$l['dosar_key']] = true;
        }
    }
    $keys = array_column($f['rows'], 'key');
    sort($keys);
    $exp = array_keys($dossiers);
    sort($exp);
    if ($keys !== $exp || !near($f['categorie']['total'], $cat['total'])) {
        $okTable = false;
    }
}
check('categoria aleasa → tabelul arata exact dosarele cu costuri in ea (si totalul ei)', $okTable);
check('KPI-urile paginii raman pe scopul paginii (nu pe categorie)', near($svc->list($page + ['fin_node' => $top['randuri'][0]['id']])['kpis']['valoare_initiala'], $list['kpis']['valoare_initiala']));
$all = $svc->list($page + ['per_page' => 50]);
check('fara categorie (resetare): tabelul complet', $all['total'] === $list['total'] && $all['categorie'] === null);
$fin = $svc->list(['status' => 'pus_in_functiune', 'per_page' => 50, 'fin_node' => $top['randuri'][0]['id']]);
check('celelalte filtre ale paginii raman active (status + categorie)', array_filter($fin['rows'], static fn (array $r): bool => $r['status'] !== 'pus_in_functiune') === []);
$refused = false;
try {
    $svc->initialValueBreakdown($page, 999999);
} catch (InvalidArgumentException) {
    $refused = true;
}
check('categorie inexistenta refuzata', $refused);

echo "\nCelelalte carduri: capitalizat / necapitalizabil / vehicule / dosare in derulare\n";
foreach (['capitalizabil' => 'capitalizat', 'necapitalizabil' => 'necapitalizabile'] as $measure => $kpiKey) {
    $m = $svc->initialValueBreakdown($page, null, null, $measure);
    check("$kpiKey: nivelul 0 = KPI-ul paginii", near($m['kpi'], $list['kpis'][$kpiKey]), $m['kpi'] . ' vs ' . $list['kpis'][$kpiKey]);
    check("$kpiKey: categoriile se reconciliaza (diferenta 0) si insumeaza totalul", abs($m['reconciliere']['diferenta']) < 0.015 && near(array_sum(array_column($m['randuri'], 'total')), $m['total']));
    $okM = true;
    foreach ($m['randuri'] as $cat) {
        $b = $svc->initialValueBreakdown($page, $cat['id'], null, $measure);
        $leafRows = $b['nivel'] === 'tranzactii' ? $b['randuri'] : [];
        if ($b['nivel'] === 'tipuri') {
            foreach ($b['randuri'] as $t) {
                $leafRows = array_merge($leafRows, $svc->initialValueBreakdown($page, $cat['id'], $t['id'], $measure)['randuri']);
            }
        }
        $sum = array_sum(array_map(static fn (array $l): float => $l['in_valoare'] ? (float) $l['suma'] : 0.0, $leafRows));
        $wrongTreatment = array_filter($leafRows, static fn (array $l): bool => $l['tratament'] !== ($measure === 'capitalizabil' ? 'capitalizabil' : 'cheltuiala'));
        if (!near($sum, $cat['total']) || $wrongTreatment !== []) {
            $okM = false;
        }
        $f = $svc->list($page + ['per_page' => 50, 'fin_node' => $cat['id'], 'fin_masura' => $measure]);
        if (!near($f['categorie']['total'], $cat['total'])) {
            $okM = false;
        }
    }
    check("$kpiKey: tranzactiile au doar tratamentul cardului, dau totalul categoriei; tabelul pe categorie", $okM);
}
$veh = $svc->vehicleBreakdown($page);
check('vehicule: tipurile insumeaza KPI-ul „Total vehicule”', array_sum(array_column($veh['randuri'], 'value')) === $list['kpis']['vehicule'] && $veh['kpi'] === $list['kpis']['vehicule'],
    array_sum(array_column($veh['randuri'], 'value')) . ' vs ' . $list['kpis']['vehicule']);
$g = $veh['randuri'][0];
$vg = $svc->vehicleBreakdown($page, $g['id']);
check('vehicule: tipul ales → vehiculele lui (o data fiecare), titlul nivelului', $vg['nivel'] === 'randuri' && count($vg['randuri']) === $g['value'] && $vg['titlu'] === $g['label']
    && count(array_unique(array_column($vg['randuri'], 'label'))) === count($vg['randuri']));
$tv = $svc->list($page + ['per_page' => 50, 'tip_vehicul' => $g['id']]);
check('vehicule: tipul ales restrange tabelul la acel tip', $tv['rows'] !== [] && array_filter($tv['rows'], static fn (array $r): bool => $r['tip'] !== $g['id']) === []
    && near($tv['kpis']['valoare_initiala'], $list['kpis']['valoare_initiala']));
$der = $svc->dossierBreakdown($page);
check('dosare in derulare: etapele insumeaza KPI-ul', array_sum(array_column($der['randuri'], 'value')) === $list['kpis']['derulare'] && $der['kpi'] === $list['kpis']['derulare']);
$st = $der['randuri'][0];
$dg = $svc->dossierBreakdown($page, $st['id']);
$ds = $svc->list(['tab' => 'derulare', 'status' => $st['id'], 'per_page' => 50]);
check('dosare in derulare: etapa aleasa → dosarele ei = tabelul cu fila In derulare + status', count($dg['randuri']) === $st['value'] && $dg['titlu'] === $st['label']
    && array_column($dg['randuri'], 'row_key') == array_column($ds['rows'], 'key'));
$refused = false;
try {
    $svc->dossierBreakdown($page, 'nimic');
} catch (InvalidArgumentException) {
    $refused = true;
}
check('etapa necunoscuta refuzata', $refused);

echo "\n" . ($failed === 0 ? 'TOATE TESTELE AU TRECUT' : "ESUATE: $failed") . " ($passed ok)\n";
exit($failed === 0 ? 0 : 1);
