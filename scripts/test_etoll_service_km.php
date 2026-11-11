<?php
declare(strict_types=1);

/**
 * Taxele eToll pe drumul la / de la service: o factura eToll a carei sesiune se inchide in
 * perioada unei intrari Km service (si in nicio cursa) ajunge pe intrarea respectiva, cu
 * rand in service_km_cheltuieli; mutarea manuala pe o cursa / inapoi, stergerea logica si
 * reasocierea dupa stergerea intrarii.
 *
 * Totul ruleaza intr-o tranzactie cu ROLLBACK; schema (DDL) se asigura INAINTE de tranzactie,
 * altfel MySQL face COMMIT implicit.
 *
 *   php scripts/test_etoll_service_km.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
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
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

$invoices = new InvoiceModel($db);
$invoices->ensureFacturiSchema(); // DDL (inclusiv service_km_cheltuieli) inainte de tranzactie
$serviceKm = new VehicleServiceKmModel($db);
$db->exec("CREATE TABLE IF NOT EXISTS etoll_sesiuni (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, session_id VARCHAR(64) NOT NULL UNIQUE, nr_inmatriculare VARCHAR(20) NOT NULL, inchisa_la DATETIME NOT NULL, suma DECIMAL(12,2) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");

$countsBefore = [
    'facturi' => (int) $db->query('SELECT COUNT(*) FROM facturi')->fetchColumn(),
    'vehicule_service_km' => (int) $db->query('SELECT COUNT(*) FROM vehicule_service_km')->fetchColumn(),
    'service_km_cheltuieli' => (int) $db->query('SELECT COUNT(*) FROM service_km_cheltuieli')->fetchColumn(),
    'curse_cheltuieli' => (int) $db->query('SELECT COUNT(*) FROM curse_cheltuieli')->fetchColumn(),
];

$vehicle = one($db, "SELECT id, nr_inmatriculare FROM vehicule WHERE tip_vehicul = 'cap_tractor' ORDER BY id LIMIT 1")
    ?? one($db, 'SELECT id, nr_inmatriculare FROM vehicule ORDER BY id LIMIT 1');
$trip = one($db, 'SELECT id FROM curse_dispecer WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
if ($vehicle === null || $trip === null) {
    fwrite(STDERR, "Trebuie macar un vehicul si o cursa in baza locala.\n");
    exit(1);
}
$vehicleId = (int) $vehicle['id'];
$plate = str_replace(' ', '', strtoupper((string) $vehicle['nr_inmatriculare']));
$tag = 'TEST_ESK_' . bin2hex(random_bytes(4));

$db->beginTransaction();

try {
    // Perioada de service in anul 2001 (nicio cursa reala), sesiunea eToll inchisa la mijloc.
    $serviceId = $serviceKm->save([
        'vehicle_id' => $vehicleId, 'driver_id' => null,
        'data_plecare' => '2001-03-10', 'ora_plecare' => '08:00:00',
        'data_intoarcere' => '2001-03-12', 'ora_intoarcere' => '17:00:00',
        'km' => '120', 'service_nume' => $tag . ' Service', 'motiv' => 'test', 'observatii' => null, 'prioritate' => 'normala',
    ], null);
    $now = date('Y-m-d H:i:s');
    $db->prepare('INSERT INTO etoll_sesiuni (session_id, nr_inmatriculare, inchisa_la, suma, created_at, updated_at) VALUES (:s, :p, :i, :suma, :c, :u)')
        ->execute([':s' => $tag, ':p' => $plate, ':i' => '2001-03-11 10:30:00', ':suma' => '77.71', ':c' => $now, ':u' => $now]);

    echo "\nAsociere automata eToll -> Km service\n";
    $id = $invoices->create([
        'tip' => 'toll', 'furnizor' => 'CNAIR (eToll)', 'numar_document' => $tag,
        'data_document' => '2001-03-11', 'valoare_cu_tva' => '77.71', 'valoare_fara_tva' => '64.22', 'moneda' => 'RON',
        'nr_inmatriculare_extras' => $plate, 'vehicle_id' => $vehicleId,
        'sursa' => 'etoll', 'sursa_key' => 'etoll:' . $tag,
        'document_path' => 'storage/invoices/test/' . $tag . '.pdf', 'document_original_name' => $tag . '.pdf',
        'document_mime' => 'application/pdf', 'document_size' => 100, 'document_sha256' => hash('sha256', $tag),
    ]);
    $row = $invoices->getById($id);
    check('asociata_auto pe Km service, fara cursa', $row['status'] === 'asociata_auto' && $row['cursa_id'] === null && (int) $row['service_km_id'] === $serviceId, $row['status'] . ' / ' . $row['match_reason']);
    check('fara rand in curse_cheltuieli', $row['curse_cheltuiala_id'] === null);
    $expense = one($db, 'SELECT * FROM service_km_cheltuieli WHERE factura_id = :id', [':id' => $id]);
    check('rand in service_km_cheltuieli: toll, 77.71, data sesiunii, document facturi:<id>',
        $expense !== null && (int) $expense['service_km_id'] === $serviceId && $expense['tip'] === 'toll'
        && (float) $expense['suma'] === 77.71 && $expense['data_cheltuiala'] === '2001-03-11' && $expense['document_path'] === 'facturi:' . $id,
        json_encode($expense));
    check('cheltuiala apare in fisa intrarii', count(array_filter($serviceKm->getExpenses($serviceId), static fn (array $e): bool => (int) $e['factura_id'] === $id)) === 1);

    echo "\nMutare manuala pe o cursa si inapoi\n";
    $error = $invoices->linkManually($id, (int) $trip['id']);
    $row = $invoices->getById($id);
    check('pe cursa: service_km_id golit, rand de cursa creat', $error === null && (int) $row['cursa_id'] === (int) $trip['id'] && $row['service_km_id'] === null && $row['curse_cheltuiala_id'] !== null, (string) $error);
    check('randul de pe Km service a disparut', one($db, 'SELECT id FROM service_km_cheltuieli WHERE factura_id = :id', [':id' => $id]) === null);

    $error = $invoices->linkServiceKmManually($id, $serviceId);
    $row = $invoices->getById($id);
    check('inapoi pe Km service manual: cursa golita, randul de cursa sters', $error === null && $row['status'] === 'asociata_manual' && $row['cursa_id'] === null && (int) $row['service_km_id'] === $serviceId && $row['curse_cheltuiala_id'] === null, (string) $error);
    check('un singur rand pe Km service', (int) one($db, 'SELECT COUNT(*) n FROM service_km_cheltuieli WHERE factura_id = :id', [':id' => $id])['n'] === 1);
    $invoices->runMatching($id);
    $row = $invoices->getById($id);
    check('reverificarea nu muta asocierea manuala pe service', $row['status'] === 'asociata_manual' && (int) $row['service_km_id'] === $serviceId);

    $other = one($db, 'SELECT id FROM vehicule WHERE id <> :id ORDER BY id LIMIT 1', [':id' => $vehicleId]);
    if ($other !== null) {
        $otherService = $serviceKm->save([
            'vehicle_id' => (int) $other['id'], 'driver_id' => null, 'data_plecare' => '2001-03-10', 'ora_plecare' => null,
            'data_intoarcere' => '2001-03-12', 'ora_intoarcere' => null, 'km' => '0', 'service_nume' => null, 'motiv' => null, 'observatii' => null,
        ], null);
        check('Km service al altui vehicul e refuzat', $invoices->linkServiceKmManually($id, $otherService) !== null);
    }

    echo "\nStergerea intrarii Km service -> factura se reasociaza\n";
    $invoices->unlink($id);
    $db->prepare("UPDATE facturi SET fara_asociere_auto = 0, status = 'neasociata' WHERE id = :id")->execute([':id' => $id]);
    $invoices->runMatching($id);
    check('reasociata automat pe Km service', (int) $invoices->getById($id)['service_km_id'] === $serviceId);
    $serviceKm->delete($serviceId);
    $row = $invoices->getById($id);
    check('FK SET NULL: factura fara service, randul a plecat (CASCADE)', $row['service_km_id'] === null && one($db, 'SELECT id FROM service_km_cheltuieli WHERE factura_id = :id', [':id' => $id]) === null);
    $invoices->rematchEtollForVehicle($vehicleId);
    $row = $invoices->getById($id);
    check('rematch dupa stergere: neasociata (nicio cursa / service in 2001)', $row['status'] === 'neasociata' && $row['service_km_id'] === null && $row['cursa_id'] === null, $row['status'] . ' / ' . $row['match_reason']);

    echo "\nCheltuiala manuala pe Km service\n";
    $serviceId2 = $serviceKm->save([
        'vehicle_id' => $vehicleId, 'driver_id' => null, 'data_plecare' => '2001-04-01', 'ora_plecare' => null,
        'data_intoarcere' => null, 'ora_intoarcere' => null, 'km' => '0', 'service_nume' => null, 'motiv' => null, 'observatii' => null,
    ], null);
    $manualId = $serviceKm->addExpense($serviceId2, ['tip' => 'service', 'suma' => '1500.00', 'data_cheltuiala' => '2001-04-02', 'locatie' => 'Service X', 'observatii' => $tag], null, null);
    $entries = array_filter($serviceKm->getRecentEntries(36500), static fn (array $e): bool => (int) $e['id'] === $serviceId2);
    $entry = reset($entries);
    check('lista: totalul cheltuielilor pe intrare', $entry !== false && ($entry['cheltuieli']['nr'] ?? 0) === 1 && (float) ($entry['cheltuieli']['suma'] ?? 0) === 1500.0);
    check('stergerea cheltuielii manuale', $serviceKm->deleteExpense($manualId) && $serviceKm->findExpense($manualId) === null);

    echo "\nStergere logica a facturii\n";
    $invoices->linkServiceKmManually($id, $serviceId2);
    check('pe a doua intrare', one($db, 'SELECT service_km_id FROM service_km_cheltuieli WHERE factura_id = :id', [':id' => $id])['service_km_id'] == $serviceId2);
    $invoices->softDelete($id);
    check('stergerea logica scoate randul de pe Km service', one($db, 'SELECT id FROM service_km_cheltuieli WHERE factura_id = :id', [':id' => $id]) === null);

    check('tranzactia a ramas deschisa pana la final (fara commit implicit)', $db->inTransaction());
} catch (Throwable $exception) {
    $failed++;
    echo "\033[31m  EROARE\033[0m  " . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine() . "\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo "\nDupa ROLLBACK\n";
foreach ($countsBefore as $table => $count) {
    $now = (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    check($table . ' neschimbat (' . $count . ')', $now === $count, (string) $now);
}

echo "\n" . ($failed > 0 ? "\033[31m" : "\033[32m") . "Rezultat: $passed trecute, $failed picate\033[0m\n";
exit($failed > 0 ? 1 : 0);
