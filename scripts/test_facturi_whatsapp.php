<?php
declare(strict_types=1);

/**
 * Test pentru facturile venite pe WhatsApp (agentul whatsapp-agent-lab scrie in coada
 * storage/invoices/inbox, ca Gmail):
 *   - proveniența din .json (canal, Message ID, grup, expeditor) si deduplicarea pe mesaj;
 *   - increderea citirii (ocr_incredere) si copierea proveniențe pe facturile din aceeasi scanare;
 *   - InvoiceTripMatcher: basis / identity la potrivirea unica (decizia nu se schimba);
 *   - verificarea WhatsApp din runMatching: asociere automata doar fara dubii;
 *   - facturile Gmail (canal NULL) se asociaza exact ca inainte.
 *
 *   php scripts/test_facturi_whatsapp.php
 *   DB_NAME=fleet_wa_test php scripts/test_facturi_whatsapp.php   (recomandat: copie de test)
 *
 * Partea cu baza de date ruleaza intr-o tranzactie anulata la final (ROLLBACK), pe curse
 * reale. Schema se pregateste INAINTE de BEGIN: DDL-ul face commit implicit.
 * Nu apeleaza Claude si nu scrie fisiere.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/InvoiceModel.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "\033[32m  PASS\033[0m  $name\n";
    } else {
        $failed++;
        echo "\033[31m  FAIL\033[0m  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n";
    }
}

// -----------------------------------------------------------------------------
// 1. Fara baza de date
// -----------------------------------------------------------------------------

echo "\nProveniența din .json-ul cozii\n";
$gmailMeta = ['fisier' => 'x.pdf', 'email_subiect' => 'cazare', 'email_message_id' => '<abc@mail>', 'email_primit_la' => '2026-10-16T10:00:00+03:00'];
check('pereche Gmail -> fara canal', InvoiceModel::channelFromInboxMeta($gmailMeta) === null);
check('canal necunoscut -> fara canal', InvoiceModel::channelFromInboxMeta(['canal' => 'telegram']) === null);
$waMeta = [
    'schema' => 'fleet-inbox/1', 'canal' => 'whatsapp', 'fisier' => 'wa_3EB0X.pdf', 'email_subiect' => 'Cazare',
    'whatsapp' => ['message_id' => ' 3EB0X ', 'group_id' => '120363000000000000@g.us', 'sender_id' => '40700000000@s.whatsapp.net', 'caption' => 'Cazare'],
];
check('pereche WhatsApp -> canal + Message ID + grup + expeditor', InvoiceModel::channelFromInboxMeta($waMeta) === [
    'canal' => 'whatsapp', 'canal_mesaj_id' => '3EB0X', 'canal_grup' => '120363000000000000@g.us', 'canal_expeditor' => '40700000000@s.whatsapp.net',
], json_encode(InvoiceModel::channelFromInboxMeta($waMeta)));
$partial = InvoiceModel::channelFromInboxMeta(['canal' => 'whatsapp', 'whatsapp' => ['message_id' => str_repeat('A', 300), 'group_id' => ['nu', 'text']]]);
check('Message ID lung trunchiat la 255, valori non-text ignorate', $partial !== null && mb_strlen((string) $partial['canal_mesaj_id']) === 255 && $partial['canal_grup'] === null);
check('fara bloc whatsapp -> tot canal whatsapp, fara Message ID', InvoiceModel::channelFromInboxMeta(['canal' => 'whatsapp'])['canal_mesaj_id'] === null);

echo "\nInvoiceTripMatcher: basis / identity (decizia ramane aceeasi)\n";
$matcher = new InvoiceTripMatcher();
$trip = static fn(int $id, string $from, string $to, int $vehicleId, string $plate, int $driverId, string $driver): array => [
    'id' => $id, 'data_inceput' => $from, 'data_sfarsit' => $to,
    'vehicle_id' => $vehicleId, 'driver_id' => $driverId, 'nr_inmatriculare' => $plate, 'sofer_nume' => $driver, 'segments' => [],
];
$single = [$trip(1, '2026-09-01', '2026-09-03', 10, 'B 100 AAA', 100, 'Popescu Ion')];
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-02', 'nr_inmatriculare_extras' => 'b-100-aaa'], $single);
check('o cursa, nr. auto potrivit -> asociata_auto, identity nr_auto = true', $r['status'] === 'asociata_auto' && $r['basis'] === 'data' && $r['identity'] === ['nr_auto' => true, 'sofer' => null], json_encode($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-02', 'nr_inmatriculare_extras' => 'XX99ZZZ'], $single);
check('o cursa, nr. auto strain -> tot asociata_auto (ca inainte), identity nr_auto = false', $r['status'] === 'asociata_auto' && $r['identity']['nr_auto'] === false, json_encode($r));
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-02'], $single);
check('o cursa, nimic pe factura -> identity null / null', $r['status'] === 'asociata_auto' && $r['identity'] === ['nr_auto' => null, 'sofer' => null]);
$r = $matcher->decide(['tip' => 'cazare', 'data_document' => '2026-09-02', 'sofer_extras' => 'ion popescu'], $single);
check('o cursa, soferul potrivit -> identity sofer = true', $r['identity']['sofer'] === true);
$two = [$single[0], $trip(2, '2026-09-02', '2026-09-02', 20, 'B 200 BBB', 200, 'Ionescu Maria')];
$r = $matcher->decide(['tip' => 'service', 'data_document' => '2026-09-02', 'nr_inmatriculare_extras' => 'B200BBB'], $two);
check('doua curse, nr. auto restrange -> basis data+nr_auto, cursa 2', $r['status'] === 'asociata_auto' && $r['cursa_id'] === 2 && $r['basis'] === 'data+nr_auto' && $r['identity']['nr_auto'] === true, json_encode($r));
$r = $matcher->decide(['tip' => 'service', 'data_document' => '2026-09-02'], $two);
check('doua curse fara identitate -> de_verificat, fara basis', $r['status'] === 'de_verificat' && !isset($r['basis']));

echo "\nDubiile WhatsApp (InvoiceModel::whatsAppDoubts)\n";
$sure = ['email_subiect' => 'Cazare', 'ocr_incredere' => 'mare'];
$byPlate = ['identity' => ['nr_auto' => true, 'sofer' => null]];
check('categorie cunoscuta + incredere mare + nr. auto confirmat -> fara dubii', InvoiceModel::whatsAppDoubts($sure, $byPlate) === []);
check('soferul confirmat ajunge', InvoiceModel::whatsAppDoubts($sure, ['identity' => ['nr_auto' => null, 'sofer' => true]]) === []);
check('"Carburant" -> categoria nerecunoscuta', count(InvoiceModel::whatsAppDoubts(['email_subiect' => 'Carburant'] + $sure, $byPlate)) === 1);
check('descriere goala -> categoria nerecunoscuta', count(InvoiceModel::whatsAppDoubts(['email_subiect' => null] + $sure, $byPlate)) === 1);
check('incredere medie -> dubiu', InvoiceModel::whatsAppDoubts(['ocr_incredere' => 'medie'] + $sure, $byPlate) === ['citire cu incredere medie']);
check('incredere lipsa -> dubiu', InvoiceModel::whatsAppDoubts(['ocr_incredere' => null] + $sure, $byPlate) === ['citire cu incredere necunoscuta']);
check('doar dupa data -> dubiu', InvoiceModel::whatsAppDoubts($sure, ['identity' => ['nr_auto' => null, 'sofer' => null]]) === ['cursa gasita doar dupa data (fara nr. auto / sofer)']);
check('nr. auto contrazice cursa -> dubiu, chiar daca soferul se potriveste',
    InvoiceModel::whatsAppDoubts($sure, ['identity' => ['nr_auto' => false, 'sofer' => true]]) === ['nr. auto de pe factura nu e pe cursa propusa']);
check('rezultat fara identity -> tratat ca "doar dupa data"', count(InvoiceModel::whatsAppDoubts($sure, [])) === 1);

// -----------------------------------------------------------------------------
// 2. Cu baza de date (tranzactie anulata)
// -----------------------------------------------------------------------------

$db = get_pdo();
$model = new InvoiceModel($db);
$model->ensureFacturiSchema();

$columns = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturi'")->fetchAll(PDO::FETCH_COLUMN);
echo "\nSchema (" . $db->query('SELECT DATABASE()')->fetchColumn() . ")\n";
foreach (['canal', 'canal_mesaj_id', 'canal_grup', 'canal_expeditor', 'ocr_incredere'] as $column) {
    check("coloana facturi.$column exista", in_array($column, $columns, true));
}

$countsBefore = [
    'facturi' => (int) $db->query('SELECT COUNT(*) FROM facturi')->fetchColumn(),
    'curse_cheltuieli' => (int) $db->query('SELECT COUNT(*) FROM curse_cheltuieli')->fetchColumn(),
];

// O cursa pe care nr. auto o alege singur in ziua ei (potrivire unica pe data + nr. auto).
$dbMatcher = new InvoiceTripMatcher($db);
$target = null;
$candidates = $db->query("
    SELECT c.id, c.data_inceput, v.nr_inmatriculare
    FROM curse_dispecer c
    JOIN vehicule v ON v.id = c.vehicle_id
    WHERE c.deleted_at IS NULL AND v.nr_inmatriculare IS NOT NULL AND v.nr_inmatriculare <> ''
    ORDER BY c.data_inceput DESC, c.id DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($candidates as $candidate) {
    $probe = $dbMatcher->match(['tip' => 'cazare', 'data_document' => (string) $candidate['data_inceput'], 'nr_inmatriculare_extras' => (string) $candidate['nr_inmatriculare']]);
    if ($probe['status'] === 'asociata_auto' && (int) $probe['cursa_id'] === (int) $candidate['id'] && ($probe['identity']['nr_auto'] ?? null) === true) {
        $target = $candidate;
        break;
    }
}
if ($target === null) {
    fwrite(STDERR, "Nu am gasit o cursa potrivita unic dupa data + nr. auto in baza locala.\n");
    exit(1);
}
$tripId = (int) $target['id'];
$tripDate = (string) $target['data_inceput'];
$plate = (string) $target['nr_inmatriculare'];
echo "  (cursa de test #$tripId, $tripDate)\n";

$tag = 'TEST_WA_' . bin2hex(random_bytes(4));
$amount = 100.00;

/** O scanare "in procesare" ca dupa preluarea din coada; $channel = null -> Gmail. */
$newScan = static function (string $suffix, ?string $caption, ?array $channel) use ($model, $tag): int {
    $sha = hash('sha256', $tag . $suffix);

    return $model->create([
        'tip' => 'alte',
        'status' => 'in_procesare',
        'sursa' => 'scan',
        'sursa_key' => 'scan:' . $sha,
        'document_path' => 'storage/invoices/test/' . $tag . $suffix . '.pdf',
        'document_original_name' => $tag . $suffix . '.pdf',
        'document_mime' => 'application/pdf',
        'document_size' => 1234,
        'document_sha256' => $sha,
        'email_subiect' => $caption,
        'email_primit_la' => date('Y-m-d H:i:s'),
    ] + ($channel ?? []));
};
$waChannel = static fn(string $suffix): array => [
    'canal' => 'whatsapp', 'canal_mesaj_id' => '3EB0' . $tag . $suffix, 'canal_grup' => '120363000000000000@g.us', 'canal_expeditor' => '40700000000@s.whatsapp.net',
];
/** Un document citit (forma InvoiceOcrService::normalize()); suma diferita la fiecare caz, ca sa nu fie "posibil duplicat". */
$ocrDoc = static function (array $overrides = []) use ($tripDate, $plate, &$amount): array {
    $amount += 1.37;

    return $overrides + [
        'pagini' => '1', 'tip' => 'cazare', 'furnizor' => 'Hotel Test', 'cui_furnizor' => null, 'numar_document' => null,
        'data_document' => $tripDate, 'valoare_fara_tva' => null, 'valoare_cu_tva' => round($amount, 2), 'moneda' => 'RON',
        'nr_inmatriculare' => $plate, 'sofer' => null, 'incredere' => 'mare', 'observatii' => null,
    ];
};
$apply = static function (int $id, array $docs) use ($model): array {
    return $model->applyOcrResult($id, $docs, ['model' => 'test', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]]);
};

$db->beginTransaction();

try {
    echo "\nWhatsApp: asociere automata doar fara dubii\n";
    $sureId = $newScan('sure', 'Cazare', $waChannel('sure'));
    $apply($sureId, [$ocrDoc()]);
    $row = $model->getById($sureId);
    check('Cazare + incredere mare + nr. auto -> asociata_auto la cursa de test',
        $row['status'] === 'asociata_auto' && (int) $row['cursa_id'] === $tripId && $row['curse_cheltuiala_id'] !== null, $row['status'] . ' / ' . $row['match_reason']);
    check('ocr_incredere salvata', $row['ocr_incredere'] === 'mare');
    check('proveniența pastrata (canal, Message ID, grup, expeditor)', $row['canal'] === 'whatsapp' && $row['canal_mesaj_id'] === '3EB0' . $tag . 'sure'
        && $row['canal_grup'] === '120363000000000000@g.us' && $row['canal_expeditor'] === '40700000000@s.whatsapp.net');
    check('tipul din descriere: cazare', $row['tip'] === 'cazare');

    $mediumId = $newScan('medium', 'Cazare', $waChannel('medium'));
    $apply($mediumId, [$ocrDoc(['incredere' => 'medie'])]);
    $row = $model->getById($mediumId);
    $candidateIds = array_map(static fn(array $c): int => (int) $c['id'], $model->decodeCandidates($row['match_candidates']));
    check('incredere medie -> de_verificat, fara cursa si fara rand de cheltuiala',
        $row['status'] === 'de_verificat' && $row['cursa_id'] === null && $row['curse_cheltuiala_id'] === null, $row['status'] . ' / ' . $row['match_reason']);
    check('motivul spune de ce (WhatsApp, incredere)', str_starts_with((string) $row['match_reason'], 'WhatsApp, de confirmat: citire cu incredere medie'), (string) $row['match_reason']);
    check('cursa propusa ramane in candidati (confirmare dintr-un clic)', $candidateIds === [$tripId], json_encode($candidateIds));

    $fuelId = $newScan('fuel', 'Carburant', $waChannel('fuel'));
    $apply($fuelId, [$ocrDoc(['tip' => 'alte'])]);
    $row = $model->getById($fuelId);
    check('"Carburant" -> de_verificat (categorie nerecunoscuta)', $row['status'] === 'de_verificat' && str_contains((string) $row['match_reason'], 'categoria din descriere'), $row['status'] . ' / ' . $row['match_reason']);
    check('"Carburant" -> tipul ramane cel citit (alte), fara tip nou', $row['tip'] === 'alte');

    $noIdId = $newScan('noid', 'Cazare', $waChannel('noid'));
    $apply($noIdId, [$ocrDoc(['nr_inmatriculare' => null])]);
    $row = $model->getById($noIdId);
    check('fara nr. auto / sofer -> nu se asociaza automat', $row['status'] !== 'asociata_auto' && $row['cursa_id'] === null, $row['status'] . ' / ' . $row['match_reason']);

    $wrongId = $newScan('wrong', 'Cazare', $waChannel('wrong'));
    $apply($wrongId, [$ocrDoc(['nr_inmatriculare' => 'ZZ 99 QQQ'])]);
    $row = $model->getById($wrongId);
    check('nr. auto care nu e pe nicio cursa -> nu se asociaza automat', $row['status'] !== 'asociata_auto' && $row['cursa_id'] === null, $row['status'] . ' / ' . $row['match_reason']);

    echo "\nGmail (canal NULL): exact ca inainte\n";
    $gmailMedium = $newScan('gmail-medium', 'cazare', null);
    $apply($gmailMedium, [$ocrDoc(['incredere' => 'medie'])]);
    $row = $model->getById($gmailMedium);
    check('Gmail, incredere medie -> asociata_auto (nicio verificare in plus)', $row['status'] === 'asociata_auto' && (int) $row['cursa_id'] === $tripId, $row['status'] . ' / ' . $row['match_reason']);
    check('Gmail: canal ramane NULL', $row['canal'] === null && $row['canal_mesaj_id'] === null);

    $gmailNoId = $newScan('gmail-noid', 'cazare', null);
    $doc = $ocrDoc(['nr_inmatriculare' => null]);
    $apply($gmailNoId, [$doc]);
    $row = $model->getById($gmailNoId);
    $expected = $dbMatcher->match(['tip' => 'cazare', 'data_document' => $tripDate]);
    $expectedStatus = $expected['status'];
    if ($expectedStatus === 'asociata_auto' && $model->findPossibleDuplicate($row, null, null) !== null) {
        $expectedStatus = 'de_verificat';
    }
    check('Gmail, fara nr. auto -> aceeasi decizie ca matcher-ul singur (' . $expectedStatus . ')', $row['status'] === $expectedStatus, $row['status'] . ' / ' . $row['match_reason']);

    echo "\nRerulari si operator\n";
    $model->runMatching($mediumId);
    check('rerularea automata nu ocoleste verificarea', $model->getById($mediumId)['status'] === 'de_verificat');
    $model->rematchPending(500);
    check('nici rematchPending (cron-ul) nu o asociaza', $model->getById($mediumId)['status'] === 'de_verificat');
    $error = $model->linkManually($mediumId, $tripId);
    $row = $model->getById($mediumId);
    check('confirmarea operatorului -> asociata_manual cu rand de cheltuiala',
        $error === null && $row['status'] === 'asociata_manual' && (int) $row['cursa_id'] === $tripId && $row['curse_cheltuiala_id'] !== null, (string) $error);
    $model->rematchPending(500);
    check('asocierea manuala ramane dupa rematchPending', $model->getById($mediumId)['status'] === 'asociata_manual');

    echo "\nScanare cu doua documente\n";
    $multiId = $newScan('multi', 'Cazare', $waChannel('multi'));
    $ids = $apply($multiId, [$ocrDoc(['pagini' => '1']), $ocrDoc(['pagini' => '2', 'incredere' => 'mica'])]);
    check('doua facturi create', count($ids) === 2);
    $second = $model->getById($ids[1] ?? 0) ?? [];
    check('a doua factura are aceeasi proveniența WhatsApp', ($second['canal'] ?? null) === 'whatsapp' && ($second['canal_mesaj_id'] ?? null) === '3EB0' . $tag . 'multi');
    check('fiecare factura are increderea ei', $model->getById($ids[0])['ocr_incredere'] === 'mare' && ($second['ocr_incredere'] ?? null) === 'mica');
    check('a doua (incredere mica) nu se asociaza automat', ($second['status'] ?? null) === 'de_verificat', ($second['status'] ?? '?') . ' / ' . ($second['match_reason'] ?? ''));

    echo "\nDeduplicare pe Message ID\n";
    $found = $model->findByChannelMessage('whatsapp', '3EB0' . $tag . 'sure');
    check('acelasi Message ID -> factura existenta', $found !== null && (int) $found['id'] === $sureId);
    check('Message ID necunoscut -> null', $model->findByChannelMessage('whatsapp', '3EB0' . $tag . 'nimic') === null);
    $model->softDelete($sureId);
    check('si dupa stergere logica blocheaza reimportul', $model->findByChannelMessage('whatsapp', '3EB0' . $tag . 'sure') !== null);
    check('canal email nu se amesteca', $model->findByChannelMessage('email', '3EB0' . $tag . 'multi') === null);

    echo "\nPosibil duplicat (regula existenta ramane prima)\n";
    $dupDoc = $ocrDoc();
    $firstDup = $newScan('dup1', 'Cazare', $waChannel('dup1'));
    $apply($firstDup, [$dupDoc]);
    $secondDup = $newScan('dup2', 'Cazare', $waChannel('dup2'));
    $apply($secondDup, [$dupDoc]);
    $row = $model->getById($secondDup);
    check('aceeasi data + suma -> de_verificat "Posibil duplicat"', $row['status'] === 'de_verificat' && str_contains((string) $row['match_reason'], 'Posibil duplicat'), (string) $row['match_reason']);
} finally {
    $db->rollBack();
}

$countsAfter = [
    'facturi' => (int) $db->query('SELECT COUNT(*) FROM facturi')->fetchColumn(),
    'curse_cheltuieli' => (int) $db->query('SELECT COUNT(*) FROM curse_cheltuieli')->fetchColumn(),
];
check('ROLLBACK: nicio factura / cheltuiala ramasa', $countsAfter === $countsBefore, json_encode([$countsBefore, $countsAfter]));

echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m") . "$passed trecute, $failed esuate\033[0m\n";
exit($failed === 0 ? 0 : 1);
