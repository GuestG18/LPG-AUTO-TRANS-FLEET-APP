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

echo "\n$passed trecute, $failed picate\n";
exit($failed === 0 ? 0 : 1);
