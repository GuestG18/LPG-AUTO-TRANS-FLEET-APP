<?php
declare(strict_types=1);

/**
 * Test pentru legatura factura -> randul din curse_cheltuieli (pagina Facturi)
 * si pentru randul-oglinda al cazarilor (serviciul comun TripExpenseMirrorService).
 *
 *   php scripts/test_facturi_association.php
 *
 * Totul ruleaza intr-o tranzactie anulata la final (ROLLBACK), pe curse reale din
 * baza locala. Schemele se pregatesc INAINTE de BEGIN: DDL-ul face commit implicit.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/AccommodationExpenseModel.php';
require_once $root . '/htdocs/models/InvoiceModel.php';

$db = get_pdo();
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

function one(PDO $db, string $sql, array $params = []): mixed
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Schemele inainte de tranzactie.
$invoices = new InvoiceModel($db);
$invoices->ensureFacturiSchema();
$accommodation = new AccommodationExpenseModel($db);
$accommodation->ensureSchema();

$countsBefore = [
    'facturi' => (int) $db->query('SELECT COUNT(*) FROM facturi')->fetchColumn(),
    'curse_cheltuieli' => (int) $db->query('SELECT COUNT(*) FROM curse_cheltuieli')->fetchColumn(),
    'cheltuieli_cazare' => (int) $db->query('SELECT COUNT(*) FROM cheltuieli_cazare')->fetchColumn(),
];

$trips = $db->query("
    SELECT c.id, c.vehicle_id, c.driver_id, c.data_inceput
    FROM curse_dispecer c
    WHERE c.deleted_at IS NULL AND c.vehicle_id IS NOT NULL
    ORDER BY c.data_inceput DESC, c.id DESC
    LIMIT 2
")->fetchAll(PDO::FETCH_ASSOC);
if (count($trips) < 2) {
    fwrite(STDERR, "Trebuie macar doua curse in baza locala.\n");
    exit(1);
}
[$tripA, $tripB] = $trips;
$tripA['id'] = (int) $tripA['id'];
$tripB['id'] = (int) $tripB['id'];
$driverId = (int) $db->query("SELECT id FROM soferi WHERE employment_status <> 'terminated' ORDER BY id LIMIT 1")->fetchColumn();

$tag = 'TEST_FACT_' . bin2hex(random_bytes(4));
$document = [
    'document_path' => 'storage/invoices/test/' . $tag . '.pdf',
    'document_original_name' => $tag . '.pdf',
    'document_mime' => 'application/pdf',
    'document_size' => 1234,
    'document_sha256' => hash('sha256', $tag),
];

$db->beginTransaction();

try {
    echo "\nAsociere automata (Trecere pe vehiculul cursei A)\n";
    $id = $invoices->create([
        'tip' => 'trece', 'furnizor' => $tag . ' Vama', 'numar_document' => 'T-1',
        'data_document' => $tripA['data_inceput'], 'valoare_fara_tva' => 100, 'valoare_cu_tva' => 119,
        'vehicle_id' => (int) $tripA['vehicle_id'], 'sursa' => 'manual', 'sursa_key' => 'manual:' . $tag . ':1',
    ] + $document);
    $row = $invoices->getById($id);
    check('statusul e asociata_auto sau de_verificat', in_array($row['status'], ['asociata_auto', 'de_verificat'], true), $row['status'] . ' / ' . $row['match_reason']);
    if ($row['status'] === 'asociata_auto') {
        $expense = one($db, 'SELECT * FROM curse_cheltuieli WHERE id = :id', [':id' => (int) $row['curse_cheltuiala_id']]);
        check('randul de cheltuiala exista pe cursa gasita', $expense !== null && (int) $expense['cursa_id'] === (int) $row['cursa_id']);
        check('tip trece, suma 119 = 1 x 119, locatie = furnizor', $expense !== null && $expense['tip_cheltuiala'] === 'trece' && (float) $expense['suma'] === 119.0 && (float) $expense['bucati'] === 1.0 && (float) $expense['pret_unitar'] === 119.0 && str_contains((string) $expense['locatie'], 'Vama'));
        check('refacturarea ramane goala', $expense !== null && $expense['refacturare_suma'] === null && $expense['refacturare_tip_cheltuiala'] === null);
    } else {
        echo "  (cursa A are mai multe curse pe vehicul in ziua respectiva; verificarile de mai jos merg pe asociere manuala)\n";
    }

    echo "\nAsociere manuala si mutare pe alta cursa\n";
    $error = $invoices->linkManually($id, $tripB['id']);
    $row = $invoices->getById($id);
    $expenseId = (int) $row['curse_cheltuiala_id'];
    $expense = one($db, 'SELECT * FROM curse_cheltuieli WHERE id = :id', [':id' => $expenseId]);
    check('linkManually fara eroare', $error === null, (string) $error);
    check('status asociata_manual pe cursa B', $row['status'] === 'asociata_manual' && (int) $row['cursa_id'] === $tripB['id']);
    check('randul de cheltuiala e pe cursa B', $expense !== null && (int) $expense['cursa_id'] === $tripB['id']);
    $categoryId = $invoices->getCategoryIdForType('trece');
    check('categoria = Trecere', $expense !== null && (int) $expense['categorie_id'] === $categoryId);
    $docs = $db->query('SELECT * FROM curse_cheltuieli_documente WHERE cheltuiala_id = ' . $expenseId)->fetchAll(PDO::FETCH_ASSOC);
    check('documentul e oglindit ca facturi:<id>', count($docs) === 1 && $docs[0]['file_path'] === 'facturi:' . $id);
    $countForInvoice = (int) one($db, 'SELECT COUNT(*) AS n FROM curse_cheltuieli WHERE observatii LIKE :tag', [':tag' => 'Factura #' . $id . ' |%'])['n'];
    check('un singur rand de cheltuiala pentru factura', $countForInvoice === 1, (string) $countForInvoice);

    $invoices->runMatching($id);
    $row = $invoices->getById($id);
    check('reverificarea nu muta asocierea manuala', $row['status'] === 'asociata_manual' && (int) $row['cursa_id'] === $tripB['id'] && (int) $row['curse_cheltuiala_id'] === $expenseId);

    echo "\nRandul sters de operator in Dispecer curse se reface\n";
    $db->prepare('DELETE FROM curse_cheltuieli WHERE id = :id')->execute([':id' => $expenseId]);
    $row = $invoices->getById($id);
    check('FK-ul a golit pointerul', $row['curse_cheltuiala_id'] === null);
    $invoices->syncMirror($id);
    $row = $invoices->getById($id);
    $expense = $row['curse_cheltuiala_id'] !== null ? one($db, 'SELECT * FROM curse_cheltuieli WHERE id = :id', [':id' => (int) $row['curse_cheltuiala_id']]) : null;
    check('sincronizarea a refacut randul pe cursa B', $expense !== null && (int) $expense['cursa_id'] === $tripB['id']);

    echo "\nDezasociere\n";
    $expenseId = (int) $row['curse_cheltuiala_id'];
    $invoices->unlink($id);
    $row = $invoices->getById($id);
    check('status neasociata, fara cursa, fara rand', $row['status'] === 'neasociata' && $row['cursa_id'] === null && $row['curse_cheltuiala_id'] === null);
    check('randul de cheltuiala a fost sters', one($db, 'SELECT id FROM curse_cheltuieli WHERE id = :id', [':id' => $expenseId]) === null);
    $invoices->runMatching($id);
    $row = $invoices->getById($id);
    check('reverificarea automata o ocoleste', $row['status'] === 'neasociata' && (int) $row['fara_asociere_auto'] === 1);
    $invoices->runMatching($id, true);
    $row = $invoices->getById($id);
    check('rerularea explicita o readuce in fluxul automat', (int) $row['fara_asociere_auto'] === 0 && !str_starts_with((string) $row['match_reason'], 'Dezasociata'), $row['status'] . ' / ' . $row['match_reason']);

    echo "\nRespingere\n";
    $invoices->linkManually($id, $tripA['id']);
    $invoices->reject($id, 'duplicat');
    $row = $invoices->getById($id);
    check('status respinsa, fara rand de cheltuiala', $row['status'] === 'respinsa' && $row['curse_cheltuiala_id'] === null && $row['cursa_id'] === null);
    $invoices->runMatching($id, true);
    check('o factura respinsa nu se mai asociaza', $invoices->getById($id)['status'] === 'respinsa');

    echo "\nMoneda straina si valoare lipsa\n";
    $eurId = $invoices->create([
        'tip' => 'port', 'data_document' => $tripA['data_inceput'], 'valoare_cu_tva' => 50, 'moneda' => 'EUR',
        'vehicle_id' => (int) $tripA['vehicle_id'], 'sursa' => 'manual', 'sursa_key' => 'manual:' . $tag . ':eur',
    ]);
    $row = $invoices->getById($eurId);
    check('EUR nu se asociaza automat', $row['status'] !== 'asociata_auto' && $row['curse_cheltuiala_id'] === null, $row['status'] . ' / ' . $row['match_reason']);
    check('EUR nu se poate lega manual', $invoices->linkManually($eurId, $tripA['id']) !== null);
    $noValueId = $invoices->create([
        'tip' => 'port', 'data_document' => $tripA['data_inceput'],
        'vehicle_id' => (int) $tripA['vehicle_id'], 'sursa' => 'manual', 'sursa_key' => 'manual:' . $tag . ':noval',
    ]);
    check('fara valoare nu se poate lega', $invoices->linkManually($noValueId, $tripA['id']) !== null);

    echo "\nTip nou (Spalatorie) si stergere logica\n";
    $washId = $invoices->create([
        'tip' => 'spalatorie', 'furnizor' => $tag . ' Wash', 'data_document' => $tripA['data_inceput'], 'valoare_cu_tva' => 60,
        'sursa' => 'manual', 'sursa_key' => 'manual:' . $tag . ':wash',
    ]);
    $row = $invoices->getById($washId);
    check('Spalatorie fara numar -> de_verificat', $row['status'] === 'de_verificat', $row['status'] . ' / ' . $row['match_reason']);
    $invoices->linkManually($washId, $tripA['id']);
    $row = $invoices->getById($washId);
    $expense = one($db, 'SELECT * FROM curse_cheltuieli WHERE id = :id', [':id' => (int) $row['curse_cheltuiala_id']]);
    check('Spalatorie -> tip alte + categoria Spalatorie, fara locatie', $expense !== null && $expense['tip_cheltuiala'] === 'alte' && (int) $expense['categorie_id'] === $invoices->getCategoryIdForType('spalatorie') && $expense['locatie'] === null && $expense['bucati'] === null);
    check('fara document -> fara document oglindit', (int) $db->query('SELECT COUNT(*) FROM curse_cheltuieli_documente WHERE cheltuiala_id = ' . (int) $row['curse_cheltuiala_id'])->fetchColumn() === 0);
    $washExpenseId = (int) $row['curse_cheltuiala_id'];
    $invoices->softDelete($washId);
    $row = $invoices->getById($washId);
    check('stergerea logica scoate randul de cheltuiala', $row['deleted_at'] !== null && $row['curse_cheltuiala_id'] === null && one($db, 'SELECT id FROM curse_cheltuieli WHERE id = :id', [':id' => $washExpenseId]) === null);

    echo "\nCazare: documentele ajung pe cursa si la prima asociere (bug reparat)\n";
    $cazareId = $accommodation->create([
        'data' => '2001-01-01', 'sofer_id' => $driverId, 'total' => 100, 'total_cu_tva' => 109,
        'observatii' => $tag, 'created_by' => null,
    ]);
    $accommodation->addDocument($cazareId, ['file_path' => $tag . '.pdf', 'original_name' => $tag . '.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10]);
    $before = one($db, 'SELECT id FROM curse_cheltuieli WHERE cazare_id = :id', [':id' => $cazareId]);
    check('cazarea din 2001 nu are cursa (deci nici rand)', $before === null);
    $accommodation->linkManually($cazareId, $tripA['id']);
    $mirror = one($db, 'SELECT * FROM curse_cheltuieli WHERE cazare_id = :id', [':id' => $cazareId]);
    check('randul-oglinda cazare creat: alte + Cazare + 109', $mirror !== null && $mirror['tip_cheltuiala'] === 'alte' && (int) $mirror['categorie_id'] === $accommodation->getCategoryId() && (float) $mirror['suma'] === 109.0);
    $mirrorDocs = $mirror !== null ? (int) $db->query('SELECT COUNT(*) FROM curse_cheltuieli_documente WHERE cheltuiala_id = ' . (int) $mirror['id'])->fetchColumn() : 0;
    check('documentul cazarii e pe randul-oglinda din prima', $mirrorDocs === 1, (string) $mirrorDocs);
    $accommodation->linkManually($cazareId, $tripB['id']);
    $moved = one($db, 'SELECT * FROM curse_cheltuieli WHERE cazare_id = :id', [':id' => $cazareId]);
    check('mutarea pe alta cursa pastreaza acelasi rand', $moved !== null && (int) $moved['id'] === (int) $mirror['id'] && (int) $moved['cursa_id'] === $tripB['id']);
    $accommodation->unlink($cazareId);
    check('dezasocierea cazarii sterge randul', one($db, 'SELECT id FROM curse_cheltuieli WHERE cazare_id = :id', [':id' => $cazareId]) === null);

    check('tranzactia a ramas deschisa pana la final (fara commit implicit)', $db->inTransaction());
} catch (Throwable $exception) {
    $failed++;
    echo "\033[31m  EROARE\033[0m  " . $exception->getMessage() . ' @ ' . basename($exception->getFile()) . ':' . $exception->getLine() . "\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo "\nDupa ROLLBACK\n";
foreach ($countsBefore as $table => $count) {
    $after = (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    check("$table neschimbat ($count)", $after === $count, (string) $after);
}

echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m") . "Rezultat: $passed trecute, $failed picate\033[0m\n";
exit($failed === 0 ? 0 : 1);
