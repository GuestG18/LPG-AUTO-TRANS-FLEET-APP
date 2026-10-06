<?php
declare(strict_types=1);

/**
 * Teste pentru citirea facturilor de piese (registrul ?page=ocr_piese), FARA apeluri la Claude.
 *
 *   php scripts/test_parts_invoice_ocr.php
 *
 * Acopera: schema (reguli structured outputs), normalizarea raspunsului, rutarea dupa
 * subiect, potrivirea numerelor de inmatriculare si fluxul in baza (scanare preluata,
 * rezultat aplicat, scanare cu doua facturi, esecuri, verificare, stergere).
 * Partea de baza ruleaza intr-o tranzactie anulata la final.
 *
 * Citirea reala (cu cost):
 *   php scripts/process_invoice_inbox.php --piese --file=test_facturi_ocr/03_multi_vehicul_3_camioane.pdf
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/InvoiceModel.php';
require_once $root . '/htdocs/models/OcrPartsModel.php';
require_once $root . '/htdocs/services/InvoiceOcrService.php';
require_once $root . '/htdocs/services/PartsInvoiceOcrService.php';
require_once $root . '/htdocs/services/OcrPartsScanService.php';
require_once $root . '/htdocs/models/MaintenanceModel.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/services/OcrPartsMaintenanceSyncService.php';

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

function schema_problems(array $schema, string $path = '$'): array
{
    $problems = [];
    if (($schema['type'] ?? null) === 'object') {
        if (($schema['additionalProperties'] ?? null) !== false) {
            $problems[] = "$path: additionalProperties trebuie sa fie false";
        }
        $keys = array_keys($schema['properties'] ?? []);
        $required = $schema['required'] ?? [];
        sort($keys);
        sort($required);
        if ($keys !== $required) {
            $problems[] = "$path: required != properties";
        }
        foreach ($schema['properties'] ?? [] as $key => $child) {
            $problems = array_merge($problems, schema_problems($child, "$path.$key"));
        }
    }
    if (isset($schema['items'])) {
        $problems = array_merge($problems, schema_problems($schema['items'], $path . "[]"));
    }
    foreach ($schema['anyOf'] ?? [] as $i => $child) {
        $problems = array_merge($problems, schema_problems($child, "$path|$i"));
    }

    return $problems;
}

echo "Schema si prompt\n";
$problems = schema_problems(PartsInvoiceOcrService::schema());
check('schema respecta regulile structured outputs', $problems === [], implode('; ', $problems));
check('Facturi isi pastreaza schema proprie', isset(InvoiceOcrService::schema()['properties']['facturi']['items']['properties']['tip']));
check('cel mult 16 campuri nullable (limita API)', substr_count((string) json_encode(PartsInvoiceOcrService::schema()), 'anyOf') <= 16);
check('schema piese are articole', isset(PartsInvoiceOcrService::schema()['properties']['facturi']['items']['properties']['articole']));
$prompt = PartsInvoiceOcrService::userPrompt(['flota' => ['B 400 NET', 'CT 12 ABC'], 'email_subiect' => 'piese B400NET']);
check('promptul include flota', str_contains($prompt, 'B 400 NET, CT 12 ABC'));
check('promptul include subiectul liber', str_contains($prompt, 'piese B400NET'));

echo "\nRutare dupa subiect\n";
foreach (['piese' => true, 'Reparatii' => true, 'service B 400 NET' => true, 'revizie' => true,
          'cazare' => false, 'Send data from MFP07552300' => false, '' => false, 'piese cazare' => false] as $subject => $expected) {
    check("subiect \"$subject\" -> " . ($expected ? 'piese' : 'Facturi'), OcrPartsScanService::isPartsSubject($subject) === $expected);
}

echo "\nNormalizare\n";
$normalized = PartsInvoiceOcrService::normalize(['facturi' => [[
    'pagini' => '1', 'furnizor' => ' Augsburg  SRL ', 'cui_furnizor' => 'RO123', 'numar_document' => 'AUG 001',
    'data_document' => '15.09.2026', 'valoare_fara_tva' => 300, 'valoare_cu_tva' => '357,00', 'moneda' => 'lei',
    'nr_inmatriculare' => ['B 400 NET', 'B 400 NET', ' '], 'km_bord' => 412350,
    'articole' => [
        ['tip' => 'piesa', 'denumire' => 'Filtru ulei', 'cod_piesa' => 'W712', 'unitate_masura' => 'buc', 'cantitate' => 2,
         'pret_unitar' => 50, 'valoare' => 100, 'tip_lucrare' => 'intretinere', 'garantie_luni' => 12,
         'nr_inmatriculare' => null, 'km_bord' => null, 'pentru_stoc' => false],
        ['tip' => 'manopera', 'denumire' => 'Manopera', 'cod_piesa' => null, 'unitate_masura' => 'ora', 'cantitate' => null,
         'pret_unitar' => null, 'valoare' => 200, 'tip_lucrare' => 'bogus', 'garantie_luni' => null,
         'nr_inmatriculare' => null, 'km_bord' => null, 'pentru_stoc' => true],
        ['tip' => 'piesa', 'denumire' => 'Discount', 'cod_piesa' => null, 'unitate_masura' => null, 'cantitate' => 1,
         'pret_unitar' => -20, 'valoare' => -20, 'tip_lucrare' => 'inlocuire', 'garantie_luni' => null,
         'nr_inmatriculare' => null, 'km_bord' => null, 'pentru_stoc' => false],
        ['tip' => 'piesa', 'denumire' => 'Placute', 'cod_piesa' => null, 'unitate_masura' => null, 'cantitate' => 1,
         'pret_unitar' => 10, 'valoare' => 99, 'tip_lucrare' => 'inlocuire', 'garantie_luni' => 999,
         'nr_inmatriculare' => null, 'km_bord' => null, 'pentru_stoc' => false],
        ['tip' => 'piesa', 'denumire' => '  ', 'cod_piesa' => null, 'unitate_masura' => null, 'cantitate' => 1,
         'pret_unitar' => 1, 'valoare' => 1, 'tip_lucrare' => 'inlocuire', 'garantie_luni' => null,
         'nr_inmatriculare' => null, 'km_bord' => null, 'pentru_stoc' => false],
    ],
    'incredere' => 'mare', 'observatii' => null,
]]]);
$doc = $normalized[0];
$items = $doc['articole'];
check('o factura', count($normalized) === 1);
check('furnizor curatat', $doc['furnizor'] === 'Augsburg SRL');
check('data din format RO', $doc['data_document'] === '2026-09-15');
check('lei -> RON', $doc['moneda'] === 'RON');
check('total cu virgula', $doc['valoare_cu_tva'] === 357.0);
check('numere unice, fara goale', $doc['nr_inmatriculare'] === ['B 400 NET']);
check('3 articole (negativ si fara denumire scoase)', count($items) === 3, (string) count($items));
check('reducerea negativa ajunge in observatii', str_contains((string) $doc['observatii'], 'Discount'));
check('manopera: pret din valoare / cantitate implicita 1', $items[1]['cantitate'] === 1.0 && $items[1]['pret_unitar'] === 200.0);
check('manopera: tip lucrare invalid -> reparatie', $items[1]['tip_lucrare'] === 'reparatie');
check('manopera nu merge in stoc', $items[1]['pentru_stoc'] === false);
check('garantie 12 pastrata, 999 scoasa', $items[0]['garantie_luni'] === 12 && $items[2]['garantie_luni'] === null);
check('linie verificata (2 x 50 = 100)', $items[0]['verificat'] === true);
check('linie neverificata (1 x 10 != 99)', $items[2]['verificat'] === false);
check('plateKey', PartsInvoiceOcrService::plateKey('b-400 net') === 'B400NET');

echo "\nFlux in baza (tranzactie anulata)\n";
$db = get_pdo();
$model = new OcrPartsModel($db);
$model->ensureScanSchema(); // DDL inainte de tranzactie (COMMIT implicit)
$vehicles = $db->query('SELECT id, nr_inmatriculare FROM vehicule ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
if (count($vehicles) < 2) {
    echo "  (sarit: trebuie cel putin 2 vehicule)\n";
} else {
    $db->beginTransaction();
    try {
        $sha = hash('sha256', 'test-' . microtime(true));
        $scanId = $model->createScanEntry([
            'sursa_key' => 'scan:' . $sha, 'fisier_original' => 'scan.pdf', 'fisier_stocat' => 'test_' . substr($sha, 0, 12) . '.pdf',
            'document_mime' => 'application/pdf', 'email_subiect' => 'piese', 'email_primit_la' => date('Y-m-d H:i:s'),
        ]);
        check('scanarea e in coada', in_array($scanId, array_map(static fn ($r) => (int) $r['id'], $model->getPendingScans(1000)), true));
        check('cheia scanarii o gaseste', $model->findScanBySourceKey('scan:' . $sha) !== null);

        // Doua documente: primul pe un vehicul (km pe factura), al doilea multi-vehicul cu un nr. necunoscut.
        $plateA = $vehicles[0]['nr_inmatriculare'];
        $plateB = $vehicles[1]['nr_inmatriculare'];
        $docs = $normalized;
        $docs[0]['nr_inmatriculare'] = [strtolower(str_replace(' ', '-', $plateA))];
        $docs[1] = $normalized[0];
        $docs[1]['pagini'] = '2';
        $docs[1]['nr_inmatriculare'] = [$plateA, $plateB, 'ZZ 999 XXX'];
        $docs[1]['articole'][0]['nr_inmatriculare'] = $plateB;
        $docs[1]['articole'][0]['km_bord'] = 99000;

        $events = $model->applyScanResult($scanId, $docs, ['model' => 'test']);
        check('doua randuri in registru', count($events) === 2 && $events[0] !== $events[1]);

        $scan = $model->findScanBySourceKey('scan:' . $sha);
        check('status De verificat', $scan['status'] === 'de_verificat');
        check('antet completat', $scan['numar_factura'] === 'AUG 001' && $scan['data_facturii'] === '2026-09-15' && (float) $scan['total_factura'] === 357.0);
        check('al doilea document are cheia :2 si acelasi fisier',
            ($second = $model->findScanBySourceKey('scan:' . $sha . ':2')) !== null && $second['fisier_stocat'] === $scan['fisier_stocat']);

        $rows = static function (int $eventId) use ($db): array {
            $stmt = $db->prepare('SELECT * FROM ocr_reparatii_articole WHERE reparatie_id = ? ORDER BY id');
            $stmt->execute([$eventId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
        $first = $rows($events[0]);
        check('doc 1: 3 articole', count($first) === 3);
        check('doc 1: piesa pe vehiculul recunoscut (nr. scris altfel)', (int) $first[0]['vehicle_id'] === (int) $vehicles[0]['id']);
        check('doc 1: km de pe factura pe piesa', (int) $first[0]['km_bord'] === 412350);
        check('doc 1: garantie 12 luni de la data facturii', $first[0]['garantie_pana_la'] === '2027-09-15');
        check('doc 1: manopera pe vehicul', $first[1]['destinatie'] === 'vehicul' && (int) $first[1]['vehicle_id'] === (int) $vehicles[0]['id']);

        $secondRows = $rows($events[1]);
        check('doc 2: articol alocat dupa nr. de pe linie', (int) $secondRows[0]['vehicle_id'] === (int) $vehicles[1]['id']);
        check('doc 2: km de pe linie', (int) $secondRows[0]['km_bord'] === 99000);
        check('doc 2: fara nr. pe linie -> nealocat (mai multe vehicule)', $secondRows[1]['vehicle_id'] === null);
        $obs = $db->prepare('SELECT observatii FROM ocr_reparatii WHERE id = ?');
        $obs->execute([$events[1]]);
        $note = (string) $obs->fetchColumn();
        check('doc 2: nr. necunoscut semnalat', str_contains($note, 'ZZ 999 XXX'));
        check('doc 2: articole fara vehicul semnalate', str_contains($note, 'nu au vehicul'));
        $assoc = $db->prepare('SELECT COUNT(*) FROM ocr_reparatii_vehicule WHERE reparatie_id = ?');
        $assoc->execute([$events[1]]);
        check('doc 2: ambele vehicule asociate facturii', (int) $assoc->fetchColumn() === 2);

        // Rerulare: nu dubleaza.
        $again = $model->applyScanResult($scanId, $docs, ['model' => 'test']);
        check('rerularea nu dubleaza', $again === $events && count($rows($events[0])) === 3);

        check('marcare verificata', $model->markScanVerified($events[0]) && $model->findScanBySourceKey('scan:' . $sha)['status'] === 'verificata');
        check('a doua marcare nu mai schimba nimic', $model->markScanVerified($events[0]) === false);

        // Esecuri: trecator de 2 ori, apoi definitiv.
        $sha2 = hash('sha256', 'fail-' . microtime(true));
        $failId = $model->createScanEntry([
            'sursa_key' => 'scan:' . $sha2, 'fisier_original' => 'x.pdf', 'fisier_stocat' => 'x_' . substr($sha2, 0, 8) . '.pdf',
            'document_mime' => 'application/pdf', 'email_subiect' => 'piese', 'email_primit_la' => null,
        ]);
        $model->recordScanFailure($failId, 'limita', false);
        check('esec trecator -> ramane in coada', $model->findScanBySourceKey('scan:' . $sha2)['status'] === 'in_procesare');
        $model->recordScanFailure($failId, 'limita', false);
        $model->recordScanFailure($failId, 'limita', false);
        check('dupa 3 incercari -> Citire eșuată', $model->findScanBySourceKey('scan:' . $sha2)['status'] === 'eroare');

        $emptyId = $model->createScanEntry([
            'sursa_key' => 'scan:' . $sha2 . 'e', 'fisier_original' => 'y.pdf', 'fisier_stocat' => 'y.pdf',
            'document_mime' => 'application/pdf', 'email_subiect' => 'piese', 'email_primit_la' => null,
        ]);
        $model->applyScanResult($emptyId, []);
        check('scanare fara factura -> Citire eșuată', $model->findScanBySourceKey('scan:' . $sha2 . 'e')['status'] === 'eroare');

        // Stergere: factura scanata pleaca; fisierul se sterge doar cand nu-l mai foloseste nimic.
        $orphan = $model->deleteEvent($events[1]);
        check('stergerea doc 2 pastreaza fisierul (il foloseste doc 1)', $orphan === null && $model->findScanBySourceKey('scan:' . $sha . ':2') === null);
        $orphan = $model->deleteEvent($events[0]);
        check('stergerea ultimului document elibereaza fisierul si cheia', $orphan === $scan['fisier_stocat'] && $model->findScanBySourceKey('scan:' . $sha) === null);

        check('tranzactia de test e inca deschisa', $db->inTransaction());
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
}

echo "\nClasificare pe componente si trimitere in Reparatii (tranzactie anulata)\n";
$catalog = new AutoComponentCatalogService();
$components = $catalog->components();
check('catalogul Reparatii Auto are componente', count($components) > 100, (string) count($components));
check('1-1 = Suspensie / Amortizoare', ($components['1-1']['category'] ?? '') === 'Suspensie' && ($components['1-1']['name'] ?? '') === 'Amortizoare');
check('ramura implicita: 1-9 Sasiu, 10 Hidraulic, 11-17 Rezervor/Livrare Gaz',
    AutoComponentCatalogService::defaultPlacement(1) === ['sasiu', 'sasiu']
    && AutoComponentCatalogService::defaultPlacement(10) === ['sasiu', 'hidraulic']
    && AutoComponentCatalogService::defaultPlacement(12) === ['rezervor', 'livrare_gaz']);
check('Livrare Gaz nu exista sub Sasiu', !AutoComponentCatalogService::isValidPlacement('sasiu', 'livrare_gaz')
    && AutoComponentCatalogService::isValidPlacement('rezervor', 'sasiu'));
// AI-ul doar citeste factura (decizia 2026-10-05, tokeni): fara catalog in prompt / schema.
check('promptul NU include catalogul de componente', !str_contains(PartsInvoiceOcrService::userPrompt(['componente' => $components]), 'Amortizoare')
    && !str_contains(PartsInvoiceOcrService::systemPrompt(), 'componenta'));
$itemSchema = PartsInvoiceOcrService::schema()['properties']['facturi']['items']['properties']['articole']['items']['properties'];
check('schema NU cere componenta', !isset($itemSchema['componenta']) && !isset($itemSchema['componenta_exacta']));
check('inca sub limita de 16 campuri nullable', substr_count((string) json_encode(PartsInvoiceOcrService::schema()), 'anyOf') <= 16);
check('cheile de invatare: cod + denumire normalizate',
    OcrPartsModel::learningKeys('Pompă apă  Scania', ' 15-08532 ') === ['cod:1508532', 'nume:pompa apa scania']
    && OcrPartsModel::learningKeys('Ax', '12') === []);

$motorKey = null;
$gasKey = null;
foreach ($components as $key => $component) {
    if ($component['category_id'] === 6 && $motorKey === null) {
        $motorKey = $key;
    }
    if ($component['category_id'] === 12 && $gasKey === null) {
        $gasKey = $key;
    }
}

$line = static fn (string $tip, string $name, float $qty, float $price, string $component, bool $stock = false): array => [
    'tip' => $tip, 'denumire' => $name, 'cod_piesa' => '', 'unitate_masura' => '', 'cantitate' => $qty,
    'pret_unitar' => $price, 'valoare' => $qty * $price, 'tip_lucrare' => $tip === 'manopera' ? 'reparatie' : 'inlocuire',
    'garantie_luni' => null, 'nr_inmatriculare' => '', 'km_bord' => null, 'pentru_stoc' => $stock, 'componenta' => $component,
];
$vehicle = $db->query('SELECT id, nr_inmatriculare FROM vehicule ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$classified = PartsInvoiceOcrService::normalize(['facturi' => [[
    'pagini' => '1', 'furnizor' => 'Service Test SRL', 'cui_furnizor' => '', 'numar_document' => 'ST 77',
    'data_document' => '2026-09-20', 'valoare_fara_tva' => null, 'valoare_cu_tva' => null, 'moneda' => 'RON',
    'nr_inmatriculare' => [$vehicle['nr_inmatriculare']], 'km_bord' => 412350,
    'articole' => [
        $line('piesa', 'Amortizor spate', 2, 500, '1-1'),
        $line('manopera', 'Manopera amortizoare', 2, 150, '1-1'),
        $line('piesa', 'Filtru pentru stoc', 3, 40, (string) $motorKey, true),
        $line('piesa', 'Surub diverse', 10, 2, 'nu-e-cheie'),
        $line('piesa', 'Consumabil stoc', 1, 9, '', true),
        $line('piesa', 'Kit ambreiaj test', 1, 2000, ''),
    ],
    'incredere' => 'mare', 'observatii' => '',
]]]);
check('normalize ignora o eventuala componenta din raspuns', !array_key_exists('componenta', $classified[0]['articole'][0]));

$maintenance = new MaintenanceModel($db); // DDL inainte de tranzactie (COMMIT implicit)
$maintenance->syncAutoComponentsToStock($catalog->categories());
$db->beginTransaction();
try {
    $sha3 = hash('sha256', 'clasificare-' . microtime(true));
    $scan3 = $model->createScanEntry([
        'sursa_key' => 'scan:' . $sha3, 'fisier_original' => 'c.pdf', 'fisier_stocat' => 'c_' . substr($sha3, 0, 8) . '.pdf',
        'document_mime' => 'application/pdf', 'email_subiect' => 'piese', 'email_primit_la' => null,
    ]);
    // Locuri confirmate pe facturi anterioare: se completeaza automat, fara AI.
    $model->learnPlacement('Amortizor spate', null, '1-1', 'sasiu');
    $model->learnPlacement('Manopera amortizoare', null, '1-1', 'sasiu');
    $model->learnPlacement('Filtru pentru stoc', null, (string) $motorKey, 'sasiu');
    [$eventId] = $model->applyScanResult($scan3, $classified, ['model' => 'test']);
    $itemsOf = static function () use ($db, $eventId): array {
        $stmt = $db->prepare('SELECT * FROM ocr_reparatii_articole WHERE reparatie_id = ? ORDER BY id');
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $items = $itemsOf();
    check('articol clasificat: Sasiu > Sasiu > 1 > Amortizoare',
        $items[0]['auto_primary'] === 'sasiu' && $items[0]['auto_subcategory'] === 'sasiu'
        && (int) $items[0]['auto_category_id'] === 1 && $items[0]['auto_component_name'] === 'Amortizoare');
    check('piesa necunoscuta -> neclasificata', $items[3]['auto_component_key'] === null);
    check('completat din invatare, restul gol', $items[0]['auto_sursa'] === 'invatat' && $items[5]['auto_component_key'] === null);
    $model->updateItemField((int) $items[5]['id'], 'vehicle_id', (string) $vehicle['id']);

    $model->updateItemField((int) $items[0]['id'], 'auto_primary', 'rezervor');
    check('ramura schimbata pe Rezervor', $itemsOf()[0]['auto_primary'] === 'rezervor');
    $model->updateItemField((int) $items[3]['id'], 'auto_component_key', (string) $gasKey);
    check('componenta de Livrare Gaz -> ramura Rezervor automat', $itemsOf()[3]['auto_primary'] === 'rezervor');
    try {
        $model->updateItemField((int) $items[3]['id'], 'auto_primary', 'sasiu');
        check('Livrare Gaz sub Sasiu respinsa', false);
    } catch (InvalidArgumentException) {
        check('Livrare Gaz sub Sasiu respinsa', true);
    }
    $model->updateItemField((int) $items[3]['id'], 'auto_component_key', '');
    check('componenta golita', $itemsOf()[3]['auto_component_key'] === null);

    // Invatare: corectura operatorului se aplica automat pe factura urmatoare, peste AI.
    $model->updateItemField((int) $items[1]['id'], 'auto_component_key', '1-2');
    check('corectura manuala -> sursa manual', $itemsOf()[1]['auto_sursa'] === 'manual');
    $learned = $model->suggestPlacement('Manopera  amortizoare', null);
    check('corectura se aplica pe factura urmatoare', $learned['auto_component_key'] === '1-2' && $learned['auto_sursa'] === 'invatat');
    $model->learnPlacement('Filtru oarecare', 'FX-9001', '6-2', 'sasiu');
    check('codul piesei castiga chiar cu alta denumire', $model->suggestPlacement('Alt nume total', 'fx 9001')['auto_component_key'] === '6-2');
    check('piesa noua -> nimic propus', $model->suggestPlacement('Piesa noua necunoscuta xyz', null) === ['auto_component_key' => null, 'auto_primary' => null, 'auto_sursa' => null]);
    $model->updateItemField((int) $items[1]['id'], 'auto_component_key', '1-1');

    $partIndex = $maintenance->getAutoComponentPartIndex();
    $amortizorPart = $partIndex['by_category_name']['suspensie|amortizoare'] ?? null;
    $motorComponent = $components[$motorKey];
    $lookup = static fn (string $v): string => preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower($v, 'UTF-8')) ?? '';
    $motorPart = $partIndex['by_category_name'][$lookup($motorComponent['category']) . '|' . $lookup($motorComponent['name'])] ?? null;
    check('piesele-componenta exista in stoc', $amortizorPart !== null && $motorPart !== null);
    $stockBefore = (float) $db->query('SELECT stoc_curent FROM mentenanta_piese WHERE id = ' . (int) $motorPart['id'])->fetchColumn();

    $sync = new OcrPartsMaintenanceSyncService($db, $maintenance);
    $summary = $sync->send($eventId);
    check('o interventie, o montare, o intrare in stoc', $summary['interventii'] === 1 && $summary['montari'] === 1 && $summary['stoc'] === 1, json_encode($summary));
    check('5 trimise, 1 sarit (stoc fara componenta)', $summary['trimise'] === 5 && count($summary['sarite']) === 1, json_encode($summary['sarite'], JSON_UNESCAPED_UNICODE));
    $approxRow = $itemsOf()[5];
    check('piesa fara componenta: doar cost in interventie, fara montare', $approxRow['mentenanta_utilizare_id'] === null && $approxRow['mentenanta_id'] !== null);

    $items = $itemsOf();
    $record = $db->query('SELECT * FROM mentenanta WHERE id = ' . (int) $items[0]['mentenanta_id'])->fetch(PDO::FETCH_ASSOC);
    check('interventia e pe vehicul, cu data facturii si km', (int) $record['vehicle_id'] === (int) $vehicle['id']
        && $record['data_interventie'] === '2026-09-20' && (int) $record['km_interventie'] === 412350);
    check('cost piese 1020 + 2000 (fara componenta) + manopera 300', (float) $record['cost_piese'] === 3020.0 && (float) $record['cost_manopera'] === 300.0 && (float) $record['cost'] === 3320.0,
        $record['cost_piese'] . ' / ' . $record['cost_manopera']);
    check('reparatie, centru de cost Suspensie', $record['record_type'] === 'reparatie' && $record['centru_cost'] === 'Suspensie');
    $usage = $maintenance->getAutoPartUsageForVehicle((int) $vehicle['id'])[(int) $amortizorPart['id']] ?? null;
    check('montarea pe Amortizoare: km si data pentru uzura', $usage !== null && (int) $usage['km_montare'] === 412350
        && $usage['data_montare'] === '2026-09-20' && (float) $usage['cantitate'] === 2.0);
    $stockAfter = (float) $db->query('SELECT stoc_curent FROM mentenanta_piese WHERE id = ' . (int) $motorPart['id'])->fetchColumn();
    check('stocul piesei-componenta creste cu 3', abs($stockAfter - $stockBefore - 3) < 0.001, "$stockBefore -> $stockAfter");
    check('articolul din stoc fara componenta ramane netrimis', $items[4]['mentenanta_trimis_la'] === null);

    try {
        $model->updateItemField((int) $items[0]['id'], 'cantitate', '5');
        check('articol trimis nu se mai editeaza', false);
    } catch (InvalidArgumentException) {
        check('articol trimis nu se mai editeaza', true);
    }
    try {
        $model->deleteItem((int) $items[0]['id']);
        check('articol trimis nu se sterge', false);
    } catch (InvalidArgumentException) {
        check('articol trimis nu se sterge', true);
    }
    check('getSentItemIds', count($model->getSentItemIds($eventId)) === 5);
    $model->learnFromEvent($eventId);
    check('trimiterea invata locurile pieselor', $model->suggestPlacement('Amortizor spate', null)['auto_component_key'] === '1-1');
    check('articol fara componenta nu se invata', $model->suggestPlacement('Kit ambreiaj test', null)['auto_sursa'] === null);

    $again = $sync->send($eventId);
    check('a doua trimitere nu dubleaza', $again['interventii'] === 0 && $again['trimise'] === 0 && $again['stoc'] === 0);

    check('tranzactia de test e inca deschisa (clasificare)', $db->inTransaction());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo "\nGestionarea componentelor (tranzactie anulata)\n";
$db->beginTransaction();
try {
    $cat = new AutoComponentCatalogService($db);
    $before = count($cat->categories()[6]['components']);
    $maxNr = (int) $db->query('SELECT MAX(nr) FROM mentenanta_auto_componente WHERE category_id = 6')->fetchColumn();
    $added = $cat->addComponent(6, '  Ambreiaj   test ', 'kit complet');
    check('componenta noua: cheie urmatoare in categorie', $added['key'] === '6-' . ($maxNr + 1), $added['key']);
    check('apare in categorie si in lista pentru AI / OCR', count($cat->categories()[6]['components']) === $before + 1
        && ($cat->components()[$added['key']]['name'] ?? '') === 'Ambreiaj test');
    try {
        $cat->addComponent(6, 'ambreiaj TEST');
        check('nume duplicat in categorie respins', false);
    } catch (InvalidArgumentException) {
        check('nume duplicat in categorie respins', true);
    }
    $maintenance->syncAutoComponentsToStock($cat->categories());
    $part = $db->prepare('SELECT id, denumire, categorie FROM mentenanta_piese WHERE cod_piesa = ?');
    $part->execute([$added['code']]);
    $partRow = $part->fetch(PDO::FETCH_ASSOC);
    check('piesa din stoc creata cu codul componentei', $partRow !== false && $partRow['denumire'] === 'Ambreiaj test' && $partRow['categorie'] === 'Motor');

    $cat->renameComponent($added['key'], 'Ambreiaj complet');
    $part->execute([$added['code']]);
    check('redenumire: si piesa din stoc', $part->fetch(PDO::FETCH_ASSOC)['denumire'] === 'Ambreiaj complet'
        && $cat->components()[$added['key']]['name'] === 'Ambreiaj complet');
    try {
        $cat->renameComponent($added['key'], $components['6-1']['name']);
        check('redenumire peste un nume existent respinsa', false);
    } catch (InvalidArgumentException) {
        check('redenumire peste un nume existent respinsa', true);
    }

    $cat->setActive($added['key'], false);
    check('eliminata: dispare din liste, ramane pentru istoric', !isset($cat->components()[$added['key']])
        && isset($cat->components(true)[$added['key']])
        && in_array($added['key'], array_column($cat->removedComponents(6), 'key'), true));
    $second = $cat->addComponent(6, 'Filtru ulei motor');
    check('cheia eliminata nu se refoloseste', $second['key'] === '6-' . ($maxNr + 2) && $second['code'] !== $added['code']);
    $cat->setActive($added['key'], true);
    check('restaurata', isset($cat->components()[$added['key']]));
    check('cheie inexistenta respinsa', (static function () use ($cat): bool {
        try { $cat->renameComponent('99-1', 'X y'); return false; } catch (InvalidArgumentException) { return true; }
    })());
    check('tranzactia de test e inca deschisa (componente)', $db->inTransaction());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    AutoComponentCatalogService::resetCache();
}

echo "\n$passed trecute, $failed picate\n";
exit($failed === 0 ? 0 : 1);
