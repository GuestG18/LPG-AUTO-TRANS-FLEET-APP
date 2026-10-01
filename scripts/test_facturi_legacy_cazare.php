<?php
declare(strict_types=1);

/**
 * Test pentru aducerea cazarilor in Facturi (InvoiceModel::syncLegacyCazare) si
 * pentru asocierea lor din Facturi, delegata modulului Cazare.
 *
 *   php scripts/test_facturi_legacy_cazare.php
 *
 * Ruleaza intr-o tranzactie anulata la final (ROLLBACK); schemele se pregatesc
 * inainte de BEGIN (DDL-ul face commit implicit).
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

function scalar(PDO $db, string $sql, array $params = []): mixed
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

$invoices = new InvoiceModel($db);
$invoices->ensureFacturiSchema();
$accommodation = new AccommodationExpenseModel($db);
$accommodation->ensureSchema();

$countsBefore = [];
foreach (['facturi', 'cheltuieli_cazare', 'curse_cheltuieli', 'curse_cheltuieli_documente'] as $table) {
    $countsBefore[$table] = (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

// Doua curse ale aceluiasi sofer care se suprapun -> ambiguu; o cursa a altui sofer -> asociat.
$trip = $db->query("
    SELECT c.id, c.driver_id, c.data_inceput, c.vehicle_id
    FROM curse_dispecer c
    WHERE c.deleted_at IS NULL AND c.driver_id IS NOT NULL
    ORDER BY c.data_inceput DESC, c.id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$otherTrip = $db->query("
    SELECT c.id FROM curse_dispecer c WHERE c.deleted_at IS NULL ORDER BY c.id ASC LIMIT 1
")->fetchColumn();
$driverId = (int) $trip['driver_id'];
$tag = 'TEST_LEGACY_' . bin2hex(random_bytes(3));

$db->beginTransaction();

try {
    echo "\nProiectie\n";
    // O cazare legata automat, cu factura atasata inainte de asociere.
    $linkedId = $accommodation->create(['data' => (string) $trip['data_inceput'], 'sofer_id' => $driverId, 'total' => 100, 'total_cu_tva' => 109, 'observatii' => $tag, 'created_by' => null]);
    $accommodation->addDocument($linkedId, ['file_path' => $tag . '_a.pdf', 'original_name' => $tag . '_a.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10]);
    $accommodation->addDocument($linkedId, ['file_path' => $tag . '_b.pdf', 'original_name' => $tag . '_b.pdf', 'mime_type' => 'application/pdf', 'file_size' => 11]);
    // O cazare fara cursa (data veche).
    $pendingId = $accommodation->create(['data' => '2001-01-01', 'sofer_id' => $driverId, 'total' => 50, 'total_cu_tva' => 59.5, 'observatii' => $tag, 'created_by' => null]);

    $mirrorsBefore = (int) $db->query('SELECT COUNT(*) FROM curse_cheltuieli WHERE cazare_id IS NOT NULL')->fetchColumn();
    $dry = $invoices->syncLegacyCazare(false);
    check('dry-run nu scrie nimic in facturi', (int) $db->query("SELECT COUNT(*) FROM facturi WHERE sursa = 'legacy_cazare'")->fetchColumn() === (int) scalar($db, "SELECT COUNT(*) FROM facturi WHERE sursa = 'legacy_cazare' AND legacy_cazare_id IS NOT NULL"));
    check('dry-run numara cazarile noi (inclusiv cele 2 de test)', $dry['noi'] >= 2, json_encode($dry));

    $first = $invoices->syncLegacyCazare(true);
    check('aplicare fara erori', $first['erori'] === [], implode(' | ', $first['erori']));
    $mirrorsAfter = (int) $db->query('SELECT COUNT(*) FROM curse_cheltuieli WHERE cazare_id IS NOT NULL')->fetchColumn();
    check('nu se creeaza randuri noi in curse_cheltuieli', $mirrorsAfter === $mirrorsBefore, "$mirrorsBefore -> $mirrorsAfter");

    $linked = $invoices->findBySourceKey('legacy_cazare:' . $linkedId);
    $mirrorId = (int) scalar($db, 'SELECT id FROM curse_cheltuieli WHERE cazare_id = :id', [':id' => $linkedId]);
    $cazare = $accommodation->getById($linkedId);
    check('cazarea asociata -> factura cazare cu acelasi status si cursa', $linked !== null && $linked['tip'] === 'cazare'
        && $linked['status'] === ($cazare['status'] === 'asociat' ? 'asociata_auto' : ($cazare['status'] === 'ambiguu' ? 'de_verificat' : 'neasociata'))
        && (int) ($linked['cursa_id'] ?? 0) === (int) ($cazare['cursa_id'] ?? 0), json_encode([$linked['status'] ?? null, $cazare['status']]));
    check('sume, data, sofer copiate', $linked !== null && (float) $linked['valoare_fara_tva'] === 100.0 && (float) $linked['valoare_cu_tva'] === 109.0 && $linked['data_document'] === $trip['data_inceput'] && (int) $linked['driver_id'] === $driverId);
    if ($cazare['status'] === 'asociat') {
        check('legata de randul existent din curse_cheltuieli', (int) $linked['curse_cheltuiala_id'] === $mirrorId);
    }
    check('primul document, in uploads/curse_cheltuieli', $linked !== null && $linked['document_path'] === 'htdocs/uploads/curse_cheltuieli/' . $tag . '_a.pdf' && $linked['document_original_name'] === $tag . '_a.pdf');
    check('legacy_cazare_id si sursa', $linked !== null && (int) $linked['legacy_cazare_id'] === $linkedId && $linked['sursa'] === 'legacy_cazare');

    $pending = $invoices->findBySourceKey('legacy_cazare:' . $pendingId);
    check('cazarea fara cursa -> neasociata, fara rand de cheltuiala', $pending !== null && $pending['status'] === 'neasociata' && $pending['curse_cheltuiala_id'] === null);

    echo "\nRulare repetata\n";
    $second = $invoices->syncLegacyCazare(true);
    check('a doua rulare: nimic nou, nimic actualizat', $second['noi'] === 0 && $second['actualizate'] === 0, json_encode($second));
    // Cazarile in asteptare pot fi reverificate (Cazare le atinge updated_at la fiecare
    // reasociere), dar fara sa se schimbe ceva.
    $incremental = $invoices->syncLegacyCazare(true, true);
    check('sincronizarea incrementala nu are nimic de scris', $incremental['noi'] === 0 && $incremental['actualizate'] === 0, json_encode($incremental));

    echo "\nModificare in Cazare -> se vede in Facturi\n";
    sleep(1); // updated_at are rezolutie de o secunda
    $accommodation->update($pendingId, ['data' => '2001-01-02', 'sofer_id' => $driverId, 'total' => 60, 'total_cu_tva' => 71.4, 'observatii' => $tag]);
    $incremental = $invoices->syncLegacyCazare(true, true);
    $pending = $invoices->findBySourceKey('legacy_cazare:' . $pendingId);
    check('sincronizarea incrementala prinde modificarea', $incremental['actualizate'] === 1 && (float) $pending['valoare_cu_tva'] === 71.4 && $pending['data_document'] === '2001-01-02', json_encode($incremental));

    echo "\nAsociere din Facturi (delegata modulului Cazare)\n";
    $error = $invoices->linkManually((int) $pending['id'], (int) $otherTrip);
    $pending = $invoices->findBySourceKey('legacy_cazare:' . $pendingId);
    $cazarePending = $accommodation->getById($pendingId);
    $pendingMirror = scalar($db, 'SELECT id FROM curse_cheltuieli WHERE cazare_id = :id', [':id' => $pendingId]);
    check('linkManually fara eroare', $error === null, (string) $error);
    check('cazarea e asociata manual in modulul Cazare', $cazarePending['status'] === 'asociat' && (int) $cazarePending['asociere_manuala'] === 1 && (int) $cazarePending['cursa_id'] === (int) $otherTrip);
    check('randul de cheltuiala e al modulului Cazare (cazare_id) si legat in Facturi', $pendingMirror !== false && (int) $pending['curse_cheltuiala_id'] === (int) $pendingMirror);
    check('factura: asociata_manual pe aceeasi cursa', $pending['status'] === 'asociata_manual' && (int) $pending['cursa_id'] === (int) $otherTrip);
    $mirrorCount = (int) scalar($db, 'SELECT COUNT(*) FROM curse_cheltuieli WHERE cazare_id = :id OR observatii LIKE :tag', [':id' => $pendingId, ':tag' => 'Factura #' . (int) $pending['id'] . ' |%']);
    check('un singur rand de cheltuiala (nu si unul al Facturi)', $mirrorCount === 1, (string) $mirrorCount);

    $invoices->unlink((int) $pending['id']);
    $pending = $invoices->findBySourceKey('legacy_cazare:' . $pendingId);
    check('dezasocierea: data din 2001 nu are cursa -> neasociata, rand sters', $pending['status'] === 'neasociata' && $pending['curse_cheltuiala_id'] === null
        && scalar($db, 'SELECT id FROM curse_cheltuieli WHERE cazare_id = :id', [':id' => $pendingId]) === false);

    check('respingerea / stergerea / editarea nu sunt permise pe cazari', !$invoices->reject((int) $pending['id']) && !$invoices->softDelete((int) $pending['id']) && !$invoices->update((int) $pending['id'], ['furnizor' => 'x']));

    echo "\nCazare stearsa -> scoasa din Facturi\n";
    $accommodation->delete($pendingId);
    $third = $invoices->syncLegacyCazare(true);
    $pending = $invoices->findBySourceKey('legacy_cazare:' . $pendingId);
    check('proiectia e stearsa logic', $third['sterse'] === 1 && $pending['deleted_at'] !== null, json_encode($third));

    check('tranzactia a ramas deschisa (fara commit implicit)', $db->inTransaction());
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
