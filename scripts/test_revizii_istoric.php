<?php
declare(strict_types=1);

/**
 * Teste Revizii → Istoric revizii (ReviziiIstoricModel + exporturi).
 *
 *   php scripts/test_revizii_istoric.php
 *
 * Partea 1: pe datele reale (doar citire) — totalurile modelului = interogari directe.
 * Partea 2: interventii de test intr-o tranzactie ANULATA la final (statusuri, documente
 * multiple fara dublare, filtre combinate, grafic lunar, exporturi complete).
 * Schema se creeaza inainte de tranzactie (DDL face COMMIT implicit).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/ReviziiIstoricModel.php';
require_once $root . '/htdocs/services/ReviziiIstoricExportService.php';
require_once $root . '/vendor/autoload.php';

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

$db = get_pdo();
$model = new ReviziiIstoricModel($db); // DDL inainte de orice tranzactie
$all = ReviziiIstoricModel::normalizeFilters([]);

echo "Date reale (doar citire)\n";
$rawTotal = (int) $db->query('SELECT COUNT(*) FROM revizii_istoric h JOIN vehicule v ON v.id = h.vehicle_id')->fetchColumn();
$rawVehicles = (int) $db->query('SELECT COUNT(DISTINCT h.vehicle_id) FROM revizii_istoric h JOIN vehicule v ON v.id = h.vehicle_id')->fetchColumn();
$kpi = $model->kpis($all);
check("Intervenții totale = COUNT(*) din revizii_istoric ($rawTotal)", $kpi['total'] === $rawTotal, (string) $kpi['total']);
check("Vehicule cu istoric = COUNT(DISTINCT vehicle_id) ($rawVehicles)", $kpi['vehicule'] === $rawVehicles, (string) $kpi['vehicule']);
check('Distribuția pe categorii însumează totalul', array_sum($model->byCategory($all)) === $rawTotal);
check('Exportul parcurge toate intervențiile', iterator_count($model->iterate($all)) === $rawTotal);

echo "Interventii de test (tranzactie anulata)\n";
$tractor = $db->query("SELECT id, nr_inmatriculare FROM vehicule WHERE tip_vehicul = 'cap_tractor' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$trailer = $db->query("SELECT id, nr_inmatriculare FROM vehicule WHERE tip_vehicul LIKE 'semiremorca%' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$userId = (int) $db->query('SELECT id FROM utilizatori ORDER BY id LIMIT 1')->fetchColumn();
if (!$tractor || !$trailer || $userId <= 0) {
    echo "  (lipsesc vehicule / utilizatori pentru testele de integrare)\n";
    exit($failed > 0 ? 1 : 0);
}

$db->beginTransaction();
try {
    $insert = $db->prepare(
        'INSERT INTO revizii_istoric (vehicle_id, tip_revizie, data_revizie, km_bord, interval_km, km_efectuati, ore_motor, km_echivalenti, factor_km_ora, sursa_ore, date_complete, observatii, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 40, ?, ?, ?, ?)'
    );
    $rows = [
        // vehicul, tip, data, km, km_echiv, complet, observatii, operator
        [(int) $tractor['id'], 'schimb_ulei', '2031-03-05 10:15:00', 120000, null, 1, 'TEST-IST ulei', $userId],
        [(int) $tractor['id'], 'schimb_ulei', '2031-06-12 08:00:00', 131000, 10450.5, 0, 'TEST-IST ulei 2', $userId],
        [(int) $tractor['id'], 'placute_fata', '2031-06-20 14:30:00', 132500, null, 1, 'TEST-IST frana', null],
        [(int) $trailer['id'], 'placute_semi', '2031-06-21 09:00:00', null, null, 1, 'TEST-IST semi', $userId],
        [(int) $trailer['id'], 'anvelope', '2031-11-02 16:45:00', null, 70000, 1, 'TEST-IST anvelope', $userId],
    ];
    $ids = [];
    foreach ($rows as [$vehicle, $tip, $at, $km, $equiv, $complete, $notes, $operator]) {
        $insert->execute([$vehicle, $tip, $at, $km, 10000, $equiv, null, $equiv, 'fara_ore', $complete, $notes, $operator]);
        $ids[] = (int) $db->lastInsertId();
    }
    $year = ReviziiIstoricModel::normalizeFilters(['de_la' => '2031-01-01', 'pana_la' => '2031-12-31']);

    $k = $model->kpis($year);
    check('5 intervenții în 2031', $k['total'] === 5, (string) $k['total']);
    check('2 vehicule distincte', $k['vehicule'] === 2, (string) $k['vehicule']);
    check('4 finalizate (una cu date incomplete)', $k['finalizate'] === 4, (string) $k['finalizate']);

    // Documente: 3 pe aceeasi interventie (nu trebuie sa dubleze nimic) + 1 eliminat.
    $file = ['document_path' => 'storage/revizii/documente/test.pdf', 'document_original_name' => 'factura_test.pdf', 'document_mime' => 'application/pdf', 'document_size' => 1000, 'document_sha256' => str_repeat('a', 64)];
    $model->addDocument($ids[0], $file, $userId);
    $model->addDocument($ids[0], ['document_original_name' => 'poze_inainte.jpg'] + $file, $userId);
    $docId = $model->addDocument($ids[0], ['document_original_name' => 'poze_dupa.jpg'] + $file, $userId);
    $removed = $model->addDocument($ids[2], $file, $userId);
    $model->removeDocument($model->findDocument($removed), $userId);
    $k = $model->kpis($year);
    check('Total neschimbat cu 3 documente pe un rând (fără dublare din join)', $k['total'] === 5, (string) $k['total']);
    check('Cu documente atașate = 1 (documentul eliminat nu contează)', $k['cu_documente'] === 1, (string) $k['cu_documente']);
    $page = $model->page($year, 'data', 'asc', 1, 10);
    check('Rândul are 3 documente', $page['rows'][0]['documente'] === 3);
    check('Documentul eliminat nu mai e găsit', $model->findDocument($removed) === null);
    check('Documentul activ e găsit', $model->findDocument($docId) !== null);

    // Statusuri.
    check('Al doilea schimb de ulei = Date incomplete', $page['rows'][1]['status'] === ReviziiIstoricModel::STATUS_INCOMPLET);
    $model->setVerified($ids[1], true, $userId);
    check('După verificare: Verificat', $model->find($ids[1])['status'] === ReviziiIstoricModel::STATUS_VERIFICAT);
    check('Finalizate include verificatele (5)', $model->kpis($year)['finalizate'] === 5);
    $model->setVerified($ids[1], false, $userId);
    check('Verificare anulată: revine la Date incomplete', $model->find($ids[1])['status'] === ReviziiIstoricModel::STATUS_INCOMPLET);
    $before = $db->query('SELECT data_revizie, km_bord, created_by FROM revizii_istoric WHERE id = ' . $ids[1])->fetch(PDO::FETCH_ASSOC);
    $model->setReference($ids[1], 'FR-2031-087', $userId);
    $after = $db->query('SELECT data_revizie, km_bord, created_by FROM revizii_istoric WHERE id = ' . $ids[1])->fetch(PDO::FETCH_ASSOC);
    check('Referința salvată', $model->find($ids[1])['referinta'] === 'FR-2031-087');
    check('Rândul original din revizii_istoric rămâne neschimbat', $before === $after);
    $audit = (int) $db->query("SELECT COUNT(*) FROM audit_log WHERE modul = 'revizii_istoric' AND record_id = " . $ids[1])->fetchColumn();
    check('Modificările au intrat în audit_log (verificare, anulare, referință)', $audit === 3, (string) $audit);

    // Filtre.
    $f = static fn (array $extra): array => ReviziiIstoricModel::normalizeFilters(['de_la' => '2031-01-01', 'pana_la' => '2031-12-31'] + $extra);
    check('Categorie Plăcuțe frână = 2 (față + semi)', $model->kpis($f(['categorie' => 'placute_frana']))['total'] === 2);
    check('Vehicul semi-remorcă = 2', $model->kpis($f(['vehicul' => $trailer['id']]))['total'] === 2);
    check('Operator nespecificat = 1', $model->kpis($f(['operator' => (string) ReviziiIstoricModel::OPERATOR_NONE]))['total'] === 1);
    check('Status Date incomplete = 1', $model->kpis($f(['status' => 'date_incomplete']))['total'] === 1);
    check('Combinat: tractor + plăcuțe = 1', $model->kpis($f(['vehicul' => $tractor['id'], 'categorie' => 'placute_frana']))['total'] === 1);
    check('Căutare după componentă („placute fata”, fără diacritice) = 1', $model->kpis($f(['q' => 'placute fata']))['total'] === 1);
    check('Căutare după referință = 1', $model->kpis($f(['q' => 'FR-2031']))['total'] === 1);
    check('Căutare după număr fără spații', $model->kpis($f(['q' => str_replace(' ', '', (string) $tractor['nr_inmatriculare'])]))['total'] >= 3);
    check('Perioadă iunie = 3', $model->kpis(ReviziiIstoricModel::normalizeFilters(['de_la' => '2031-06-01', 'pana_la' => '2031-06-30']))['total'] === 3);
    check('Ziua de final e inclusă integral (20.06 14:30)', $model->kpis(ReviziiIstoricModel::normalizeFilters(['de_la' => '2031-06-20', 'pana_la' => '2031-06-20']))['total'] === 1);
    check('Date inversate se corectează', $model->kpis(ReviziiIstoricModel::normalizeFilters(['de_la' => '2031-06-30', 'pana_la' => '2031-06-01']))['total'] === 3);

    // Grafic lunar: anul ales, celelalte filtre aplicate.
    $monthly = $model->monthly($f([]), 2031);
    check('Iunie: 1 ulei, 2 plăcuțe', $monthly['schimb_ulei'][5] === 1 && $monthly['placute_frana'][5] === 2);
    check('Noiembrie: 1 anvelope; luni goale = 0', $monthly['anvelope'][10] === 1 && $monthly['anvelope'][0] === 0);
    check('Grafic: suma lunilor = 5', array_sum(array_map('array_sum', $monthly)) === 5);
    check('Grafic ignoră perioada din filtre (doar anul)', array_sum(array_map('array_sum', $model->monthly(ReviziiIstoricModel::normalizeFilters(['de_la' => '2031-06-01', 'pana_la' => '2031-06-02']), 2031))) === 5);

    // Paginare / sortare.
    $p1 = $model->page($year, 'data', 'desc', 1, 5);
    check('Paginare: 5 pe pagină, 1 pagină', count($p1['rows']) === 5 && $p1['pages'] === 1);
    check('Sortare desc pe dată: anvelopele primele', $p1['rows'][0]['tip'] === 'anvelope');
    $byKm = $model->page($year, 'km', 'asc', 1, 5)['rows'];
    check('Sortare pe km: valorile lipsă la final', $byKm[0]['km'] === 120000 && $byKm[4]['km'] === null);

    // Detalii.
    $detail = $model->find($ids[0]);
    check('Detalii: 3 documente listate', count($detail['documente_lista']) === 3);
    check('Detalii: km la intervenție = instantaneul (120.000)', $detail['km'] === 120000);

    // Exporturi: acelasi set, complet.
    $service = new ReviziiIstoricExportService($model);
    $tmp = tempnam(sys_get_temp_dir(), 'rvt');
    $n = $service->writeXlsx($year, $tmp, 'Test istoric revizii');
    $zip = new ZipArchive();
    $zip->open($tmp);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
    $workbook = (string) $zip->getFromName('xl/workbook.xml');
    $zip->close();
    check('Excel: 5 intervenții exportate', $n === 5);
    check('Excel: filtru automat pe tabel', str_contains($sheet, '<autoFilter ref="A3:'));
    check('Excel: diacritice păstrate', str_contains($sheet, 'Plăcuțe față') && str_contains($sheet, 'Înlocuire'));
    check('Excel: km numerici', str_contains($sheet, '<v>120000</v>'));
    check('Excel: nume definit pentru filtru', str_contains($workbook, '_xlnm._FilterDatabase'));
    $n = $service->writePdf($year, $tmp, 'Test istoric revizii');
    check('PDF: 5 intervenții exportate', $n === 5);
    check('PDF: fișier PDF valid', str_starts_with((string) file_get_contents($tmp, false, null, 0, 5), '%PDF'));
    @unlink($tmp);
} finally {
    $db->rollBack();
}
$left = (int) $db->query("SELECT COUNT(*) FROM revizii_istoric WHERE observatii LIKE 'TEST-IST%'")->fetchColumn();
check('Datele de test au fost anulate', $left === 0);

echo "\n$passed ok, $failed eșuate\n";
exit($failed > 0 ? 1 : 0);
