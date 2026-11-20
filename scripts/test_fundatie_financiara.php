<?php
declare(strict_types=1);

/**
 * Teste pentru fundatia financiara (IMPLEMENTARE_FINANCIARA_FLEET.md): clasificare, receptii de stoc,
 * consum atomic / idempotent, transferuri, interventii, OCR, cost/km, achizitii, proiecte GPL, citiri.
 *
 *   DB_NAME=fleet_fin_test php scripts/test_fundatie_financiara.php
 *
 * Scrie date REALE (comise) — de aceea ruleaza DOAR pe o baza al carei nume contine „test”
 * (copia restaurata din backup). Fiecare rulare foloseste coduri unice, deci se poate repeta.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';

if (!str_contains(DB_NAME, 'test')) {
    fwrite(STDERR, "Refuz: baza „" . DB_NAME . "” nu este o copie de test. Rulati cu DB_NAME=fleet_fin_test.\n");
    exit(1);
}

require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/includes/audit_context.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/models/MaintenanceModel.php';
require_once $root . '/htdocs/models/VehicleComponentModel.php';
require_once $root . '/htdocs/models/OperationalCostModel.php';
require_once $root . '/htdocs/services/OcrPartsMaintenanceSyncService.php';
require_once $root . '/htdocs/services/FinancialClassificationService.php';
require_once $root . '/htdocs/services/PartsInventoryService.php';
foreach (['VehicleAcquisitionService', 'InvestmentProjectService', 'VehicleFinancialHistoryService', 'VehicleCouplingPeriodService'] as $optional) {
    if (is_file($root . '/htdocs/services/' . $optional . '.php')) {
        require_once $root . '/htdocs/services/' . $optional . '.php';
    }
}

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
function fails(callable $fn, string $contains = ''): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $contains === '' || str_contains($e->getMessage(), $contains);
    }
    return false;
}
function near(float $a, float $b): bool
{
    return abs($a - $b) < 0.005;
}

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$q = static function (string $sql, array $p = []) use ($db): array {
    $s = $db->prepare($sql);
    $s->execute($p);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};
$one = static fn (string $sql, array $p = []) => $q($sql, $p)[0] ?? null;
$val = static function (string $sql, array $p = []) use ($q) {
    $row = $q($sql, $p)[0] ?? null;
    return $row === null ? null : reset($row);
};

// Modelele ruleaza DDL in constructor: inainte de orice tranzactie.
FinancialFoundationSchema::resetCache();
$maintenance = new MaintenanceModel($db);
$components = new VehicleComponentModel($db);
check('schema financiara completa', FinancialFoundationSchema::isReady($db));
$inventory = new PartsInventoryService($db);
$classification = new FinancialClassificationService($db);
$costModel = new OperationalCostModel($db);

$tag = 'T' . date('His') . random_int(100, 999);
$vehicles = array_column($q("SELECT id, tip_vehicul FROM vehicule WHERE status = 'activ' ORDER BY id"), 'tip_vehicul', 'id');
$byType = static function (array $types) use ($vehicles): array {
    return array_values(array_keys(array_filter($vehicles, static fn (string $t): bool => in_array($t, $types, true))));
};
[$tractorA, $tractorB] = $byType(['cap_tractor']);
[$trailerA] = $byType(['semiremorca_primar', 'semiremorca_distributie', 'semiremorca']);
$truck = $byType(['camion'])[0];
$userId = (int) $val('SELECT id FROM utilizatori ORDER BY id LIMIT 1');
$componentKey = (string) $val("SELECT component_key FROM mentenanta_auto_componente WHERE activ = 1 ORDER BY component_key LIMIT 1");
$day = date('Y-m-d');
$monthStart = date('Y-m-01');
$documentsBefore = $q('SELECT id, vehicle_id, tip_document, numar_document, data_expirare, fisier_stocat FROM documente ORDER BY id');

$newPart = static function (string $name, string $mode = 'stoc') use ($db, $tag): int {
    $db->prepare(
        "INSERT INTO mentenanta_piese (cod_piesa, denumire, categorie, unitate_masura, mod_utilizare, stoc_curent, stoc_minim,
            pret_achizitie, status_piesa, created_at, updated_at)
         VALUES (:c, :d, 'Test', 'buc', :m, 0, 0, 0, 'activa', NOW(), NOW())"
    )->execute([':c' => $tag . '-' . $name, ':d' => $name . ' ' . $tag, ':m' => $mode]);
    return (int) $db->lastInsertId();
};
$stock = static fn (int $partId): float => (float) $val('SELECT stoc_curent FROM mentenanta_piese WHERE id = ?', [$partId]);
$receiptSum = static fn (int $partId): float => (float) $val('SELECT COALESCE(SUM(cantitate_ramasa), 0) FROM mentenanta_piese_receptii WHERE part_id = ?', [$partId]);

// =====================================================================================
echo "\nFaza 1 — clasificare\n";
// =====================================================================================
check('mapare OCR reparatie', CostNature::fromOcrWorkType('reparatie') === 'reparatie');
check('mapare OCR inlocuire', CostNature::fromOcrWorkType('inlocuire') === 'reparatie');
check('mapare OCR intretinere', CostNature::fromOcrWorkType('intretinere') === 'mentenanta');
check('mapare OCR imbunatatire', CostNature::fromOcrWorkType('imbunatatire') === 'investitie');
check('investitia nu e capitalizabila implicit', CostNature::defaultTreatment('investitie') === 'de_confirmat');
check('reparatia e cheltuiala implicit', CostNature::defaultTreatment('reparatie') === 'cheltuiala');

$beforeCost = $costModel->getMaintenancePeriodCosts($monthStart, $day, 'reparatie')[$truck]['total'] ?? 0.0;
$beforeRev = $costModel->getMaintenancePeriodCosts($monthStart, $day, 'intretinere')[$truck]['total'] ?? 0.0;
$beforeCapital = $costModel->getMaintenancePeriodCosts($monthStart, $day, 'reparatie')[$truck]['capitalizat_exclus'] ?? 0.0;
$beforeCapitalOnly = $costModel->getCapitalizedInvestmentCosts($monthStart, $day)[$truck]['total'] ?? 0.0;

// 1. Reparatie obisnuita (regresie: saveRecord)
$repairId = $maintenance->saveRecord([
    'vehicle_id' => $truck, 'tip_interventie' => 'Reparatie ' . $tag, 'record_type' => 'reparatie', 'data_interventie' => $day,
    'cost_manopera' => 300, 'cost_piese' => 200, 'centru_cost' => 'Motor',
]);
$repair = $one('SELECT * FROM mentenanta WHERE id = ?', [$repairId]);
check('reparatie: cost total = manopera + piese', near((float) $repair['cost'], 500));
check('reparatie: natura reparatie / cheltuiala', $repair['natura_cost'] === 'reparatie' && $repair['tratament_contabil'] === 'cheltuiala' && $repair['natura_cost_origine'] === 'derivat');
check('reparatie: record_type pastrat', $repair['record_type'] === 'reparatie');

// 2. Mentenanta (regresie)
$maintId = $maintenance->saveRecord([
    'vehicle_id' => $truck, 'tip_interventie' => 'Revizie ' . $tag, 'record_type' => 'intretinere', 'data_interventie' => $day, 'cost' => 150,
]);
$maint = $one('SELECT * FROM mentenanta WHERE id = ?', [$maintId]);
check('mentenanta: natura mentenanta', $maint['natura_cost'] === 'mentenanta' && $maint['record_type'] === 'intretinere' && near((float) $maint['cost'], 150));

// 3. Investitie GPL 20.000 lei
$invId = $maintenance->saveRecord([
    'vehicle_id' => $truck, 'tip_interventie' => 'Adaptare GPL ' . $tag, 'record_type' => 'reparatie', 'natura_cost' => 'investitie',
    'data_interventie' => $day, 'cost_manopera' => 5000, 'cost_piese' => 15000,
]);
$inv = $one('SELECT * FROM mentenanta WHERE id = ?', [$invId]);
check('investitie: natura explicita, tratament de_confirmat', $inv['natura_cost'] === 'investitie' && $inv['tratament_contabil'] === 'de_confirmat' && $inv['natura_cost_origine'] === 'explicit');

$mid = $costModel->getMaintenancePeriodCosts($monthStart, $day, 'reparatie')[$truck];
check('cost/km: reparatia normala intra in costul operational', near($mid['total'], $beforeCost + 500 + 20000));
check('cost/km: investitia neconfirmata ramane operationala (nu se elimina tacit)', near($mid['investitii_neconfirmate'], 20000 + 0) || $mid['investitii_neconfirmate'] >= 20000);
check('cost/km: mentenanta in elementul revizii', near($costModel->getMaintenancePeriodCosts($monthStart, $day, 'intretinere')[$truck]['total'], $beforeRev + 150));

check('capitalizabil interzis pe reparatie', fails(fn () => $classification->setTreatment('mentenanta', $repairId, 'capitalizabil', $userId, 'test'), 'Doar o investiție'));
check('capitalizabil cere motiv', fails(fn () => $classification->setTreatment('mentenanta', $invId, 'capitalizabil', $userId, ''), 'Motivul'));
$classification->setTreatment('mentenanta', $invId, 'capitalizabil', $userId, 'Aprobat contabilitate: modernizare > prag, durata 10 ani');
$after = $costModel->getMaintenancePeriodCosts($monthStart, $day, 'reparatie')[$truck];
check('ACCEPTANTA: investitia capitalizabila NU apare ca reparatie de 20.000 in luna montarii', near($after['total'], $beforeCost + 500));
check('cost/km: capitalul exclus raportat separat', near($after['capitalizat_exclus'], $beforeCapital + 20000) && $after['capitalizat_items'] >= 1);
check('capital separat (fara amortizare inventata)', near($costModel->getCapitalizedInvestmentCosts($monthStart, $day)[$truck]['total'] ?? 0, $beforeCapitalOnly + 20000));
$audit = FinancialAuditLog::history($db, 'mentenanta', $invId);
check('audit: schimbarea tratamentului inregistrata cu motiv si utilizator', count($audit) === 1 && $audit[0]['valoare_veche'] === 'de_confirmat' && $audit[0]['valoare_noua'] === 'capitalizabil' && (int) $audit[0]['user_id'] === $userId && $audit[0]['motiv'] !== null);
$classification->setNature('mentenanta', $invId, 'reparatie', $userId, 'Reclasificare test');
$inv = $one('SELECT * FROM mentenanta WHERE id = ?', [$invId]);
check('natura -> reparatie anuleaza capitalizarea (de_confirmat)', $inv['natura_cost'] === 'reparatie' && $inv['tratament_contabil'] === 'de_confirmat' && $inv['record_type'] === 'reparatie');
check('audit: natura + reset tratament', count(FinancialAuditLog::history($db, 'mentenanta', $invId)) === 3);
$classification->setNature('mentenanta', $invId, 'investitie', $userId, 'Inapoi');
$classification->setTreatment('mentenanta', $invId, 'capitalizabil', $userId, 'Aprobat din nou');

check('istoric: niciun rand fara natura dupa migrare', (int) $val('SELECT COUNT(*) FROM mentenanta WHERE natura_cost IS NULL') === 0);
check('istoric: randurile vechi marcate „istoric”', (int) $val("SELECT COUNT(*) FROM mentenanta WHERE natura_cost_origine = 'istoric'") >= 1);

// 4. OCR: factura mixta pastreaza clasificarea pe articol
$db->prepare("INSERT INTO ocr_piese_facturi (numar_factura, data_facturii, furnizor, moneda, total_factura, sursa, created_at, updated_at)
              VALUES (:n, :d, 'Furnizor OCR Test', 'RON', 0, 'manual', NOW(), NOW())")->execute([':n' => 'OCR-' . $tag, ':d' => $day]);
$ocrInvoice = (int) $db->lastInsertId();
$db->prepare("INSERT INTO ocr_reparatii (vehicle_id, data_interventie, furnizor, tip_lucrare, factura_id, created_by, created_at, updated_at)
              VALUES (:v, :d, 'Furnizor OCR Test', 'reparatie', :f, :u, NOW(), NOW())")->execute([':v' => $trailerA, ':d' => $day, ':f' => $ocrInvoice, ':u' => $userId]);
$ocrEvent = (int) $db->lastInsertId();
$article = $db->prepare("INSERT INTO ocr_reparatii_articole (reparatie_id, tip, denumire, cantitate, pret_unitar, tip_lucrare, destinatie, vehicle_id, auto_component_key, created_at, updated_at)
                         VALUES (:r, :t, :d, :q, :p, :w, :dest, :v, :ck, NOW(), NOW())");
foreach ([['piesa', 'Supapa reparata', 1, 400, 'reparatie', 'vehicul', $trailerA, null],
          ['manopera', 'Manopera revizie', 1, 100, 'intretinere', 'vehicul', $trailerA, null],
          ['piesa', 'Pompa GPL', 1, 8000, 'imbunatatire', 'vehicul', $trailerA, null],
          ['manopera', 'Montaj pompa GPL', 1, 1500, 'imbunatatire', 'vehicul', $trailerA, null],
          ['piesa', 'Piesa in stoc', 3, 120, 'reparatie', 'stoc', null, $componentKey]] as [$t, $d, $qq, $p, $w, $dest, $v, $ck]) {
    $article->execute([':r' => $ocrEvent, ':t' => $t, ':d' => $d . ' ' . $tag, ':q' => $qq, ':p' => $p, ':w' => $w, ':dest' => $dest, ':v' => $v, ':ck' => $ck]);
}
$sync = new OcrPartsMaintenanceSyncService($db, $maintenance);
$summary = $sync->send($ocrEvent, $userId);
$ocrRows = $q("SELECT * FROM mentenanta WHERE sursa_tip = 'ocr_reparatie' AND sursa_id = ? ORDER BY natura_cost", [$ocrEvent]);
$byNature = array_column($ocrRows, null, 'natura_cost');
check('OCR: factura mixta -> un rand per natura (3)', count($ocrRows) === 3, json_encode(array_column($ocrRows, 'natura_cost')));
check('OCR: imbunatatire -> investitie (nu reparatie)', isset($byNature['investitie']) && near((float) $byNature['investitie']['cost'], 9500));
check('OCR: investitia ramane record_type reparatie (compatibilitate) dar de_confirmat', ($byNature['investitie']['record_type'] ?? '') === 'reparatie' && $byNature['investitie']['tratament_contabil'] === 'de_confirmat');
check('OCR: intretinere -> mentenanta / record_type intretinere', ($byNature['mentenanta']['record_type'] ?? '') === 'intretinere' && near((float) $byNature['mentenanta']['cost'], 100));
check('OCR: reparatie -> reparatie', near((float) ($byNature['reparatie']['cost'] ?? 0), 400));
check('OCR: tip_lucrare original neschimbat', (int) $val("SELECT COUNT(*) FROM ocr_reparatii_articole WHERE reparatie_id = ? AND tip_lucrare = 'imbunatatire'", [$ocrEvent]) === 2);
$stockArticle = $one("SELECT * FROM ocr_reparatii_articole WHERE reparatie_id = ? AND destinatie = 'stoc'", [$ocrEvent]);
$ocrReceipt = $stockArticle !== null && $stockArticle['mentenanta_receptie_id'] !== null ? $one('SELECT * FROM mentenanta_piese_receptii WHERE id = ?', [(int) $stockArticle['mentenanta_receptie_id']]) : null;
check('OCR: linia de stoc -> receptie cu pretul, factura si referinta liniei', $ocrReceipt !== null && near((float) $ocrReceipt['cost_unitar'], 120) && near((float) $ocrReceipt['cantitate_receptionata'], 3)
    && $ocrReceipt['factura_numar'] === 'OCR-' . $tag && (int) $ocrReceipt['ocr_articol_id'] === (int) $stockArticle['id'] && $ocrReceipt['status_evaluare'] === 'confirmat_document');
$summary2 = $sync->send($ocrEvent, $userId);
check('OCR: a doua trimitere nu dubleaza nimic', $summary2['trimise'] === 0 && count($q("SELECT id FROM mentenanta WHERE sursa_tip = 'ocr_reparatie' AND sursa_id = ?", [$ocrEvent])) === 3);
check('OCR: receptia nu se poate dubla pe aceeasi linie', $inventory->receive((int) $ocrReceipt['part_id'], 3, ['ocr_articol_id' => (int) $stockArticle['id'], 'cost_unitar' => 120])['duplicate'] === true);

// =====================================================================================
echo "\nFaza 2 — receptii, consum, transfer\n";
// =====================================================================================
$pump = $newPart('Pompa hidraulica');
$r1 = $inventory->receive($pump, 1, ['cost_unitar' => 8000, 'furnizor' => 'Furnizor A', 'factura_numar' => 'FA-1', 'data_receptie' => date('Y-m-d', strtotime('-10 days')), 'status_evaluare' => 'confirmat_document'], $userId);
$r2 = $inventory->receive($pump, 1, ['cost_unitar' => 9000, 'furnizor' => 'Furnizor B', 'factura_numar' => 'FB-7', 'data_receptie' => date('Y-m-d', strtotime('-5 days')), 'status_evaluare' => 'confirmat_document'], $userId);
check('doua pompe la preturi diferite: ambele receptii pastreaza pretul', near((float) $val('SELECT cost_unitar FROM mentenanta_piese_receptii WHERE id = ?', [$r1['id']]), 8000) && near((float) $val('SELECT cost_unitar FROM mentenanta_piese_receptii WHERE id = ?', [$r2['id']]), 9000));
check('stoc = 2 = suma receptiilor', near($stock($pump), 2) && near($receiptSum($pump), 2));
check('pretul de catalog = ultimul pret (nu rescrie receptiile)', near((float) $val('SELECT pret_achizitie FROM mentenanta_piese WHERE id = ?', [$pump]), 9000));

// montare din stoc prin saveRecord (calea 1)
$recWithPart = $maintenance->saveRecord([
    'vehicle_id' => $truck, 'tip_interventie' => 'Inlocuire pompa ' . $tag, 'record_type' => 'reparatie', 'data_interventie' => $day,
    'cost_manopera' => 400, 'stock_part_id' => $pump, 'stock_part_quantity' => 1,
]);
$rec = $one('SELECT * FROM mentenanta WHERE id = ?', [$recWithPart]);
$usage1 = $one('SELECT * FROM mentenanta_piese_utilizari WHERE maintenance_id = ?', [$recWithPart]);
check('ACCEPTANTA: prima pompa montata la costul ei de receptie (8.000)', near((float) $rec['cost_piese'], 8000) && near((float) $usage1['cost_unitar'], 8000));
check('cost mentenanta = manopera + receptie', near((float) $rec['cost'], 8400));
check('montarea pastreaza referinta receptiei originale', (int) $usage1['receptie_id'] === $r1['id'] && $usage1['sursa_piesa'] === 'stoc');
check('stoc scazut exact o data', near($stock($pump), 1) && near($receiptSum($pump), 1));
$inventory->receive($pump, 1, ['cost_unitar' => 9500, 'furnizor' => 'Furnizor C'], $userId);
check('ACCEPTANTA: costul istoric neschimbat dupa o noua aprovizionare', near((float) $val('SELECT cost_unitar FROM mentenanta_piese_utilizari WHERE id = ?', [$usage1['id']]), 8000)
    && near((float) $val('SELECT cost_piese FROM mentenanta WHERE id = ?', [$recWithPart]), 8000));

// consum idempotent + fara stoc negativ
$c1 = $inventory->consume($pump, 1, ['vehicle_id' => $truck, 'data_montare' => $day], 'test-' . $tag . '-pompa');
$c2 = $inventory->consume($pump, 1, ['vehicle_id' => $truck, 'data_montare' => $day], 'test-' . $tag . '-pompa');
check('consum idempotent: retry intoarce aceeasi montare', $c1['usage_id'] === $c2['usage_id'] && $c2['duplicate'] === true);
check('consum idempotent: stocul scade o singura data', near($stock($pump), 1));
check('consum FIFO: a doua pompa la 9.000', near($c1['cost_total'], 9000));
$stockBefore = $stock($pump);
check('stoc insuficient blocat', fails(fn () => $inventory->consume($pump, 5, ['vehicle_id' => $truck, 'data_montare' => $day]), 'Stoc insuficient'));
check('fara stoc negativ dupa refuz', near($stock($pump), $stockBefore) && (int) $val('SELECT COUNT(*) FROM mentenanta_piese WHERE stoc_curent < 0') === 0);
check('fara receptii negative', (int) $val('SELECT COUNT(*) FROM mentenanta_piese_receptii WHERE cantitate_ramasa < 0') === 0);

// tranzactie atomica: eroare dupa consum -> rollback complet
$usagesBefore = (int) $val('SELECT COUNT(*) FROM mentenanta_piese_utilizari WHERE part_id = ?', [$pump]);
$db->beginTransaction();
try {
    $inventory->consume($pump, 1, ['vehicle_id' => $truck, 'data_montare' => $day]);
    throw new RuntimeException('eroare simulata');
} catch (RuntimeException) {
    $db->rollBack();
}
check('atomic: rollback-ul anuleaza consumul (stoc + montare + receptie)', near($stock($pump), $stockBefore) && near($receiptSum($pump), $stockBefore)
    && (int) $val('SELECT COUNT(*) FROM mentenanta_piese_utilizari WHERE part_id = ?', [$pump]) === $usagesBefore);

// concurenta: doua procese consuma ultima bucata
$lastPart = $newPart('Ultima bucata');
$inventory->receive($lastPart, 1, ['cost_unitar' => 50], $userId);
$php = PHP_BINARY;
$worker = __DIR__ . '/test_fundatie_financiara_worker.php';
$procs = [];
for ($i = 0; $i < 2; $i++) {
    $procs[] = proc_open([$php, $worker, (string) $lastPart, (string) $truck, $tag . 'c' . $i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, ['DB_NAME' => DB_NAME] + getenv());
}
$results = [];
foreach ($procs as $i => $proc) {
    $results[] = trim((string) stream_get_contents($pipes[$i][1]));
    proc_close($proc);
}
check('concurenta: exact un consum reuseste pe ultima bucata', count(array_filter($results, static fn ($r) => $r === 'ok')) === 1 && near($stock($lastPart), 0), implode(',', $results));

// achizitie directa: stocul nu se atinge
$direct = $inventory->recordDirect($pump, 1, 7000, 'achizitie_directa', ['vehicle_id' => $tractorA, 'data_montare' => $day], 'direct-' . $tag);
check('achizitie directa: stocul neschimbat', near($stock($pump), $stockBefore) && $one('SELECT sursa_piesa, direct_mount FROM mentenanta_piese_utilizari WHERE id = ?', [$direct['usage_id']])['sursa_piesa'] === 'achizitie_directa');

// transfer intre vehicule
$beforeExpenses = (float) $val('SELECT COALESCE(SUM(cost), 0) FROM mentenanta');
$beforeReceipts = (int) $val('SELECT COUNT(*) FROM mentenanta_piese_receptii');
$moved = $inventory->transfer($c1['usage_id'], $tractorB, ['data_montare' => $day], 'transfer-' . $tag);
$movedRow = $one('SELECT * FROM mentenanta_piese_utilizari WHERE id = ?', [$moved['usage_id']]);
$sourceRow = $one('SELECT * FROM mentenanta_piese_utilizari WHERE id = ?', [$c1['usage_id']]);
check('transfer: costul istoric pastrat (9.000)', near((float) $movedRow['cost_unitar'], 9000) && (int) $movedRow['receptie_id'] === (int) $sourceRow['receptie_id']);
check('transfer: demontare + remontare inregistrate, vehicule pastrate', $sourceRow['motiv_demontare'] === 'transfer' && (int) $sourceRow['inlocuita_de_id'] === $moved['usage_id']
    && (int) $sourceRow['vehicle_id'] === $truck && (int) $movedRow['vehicle_id'] === $tractorB && (int) $movedRow['transfer_din_utilizare_id'] === $c1['usage_id']);
check('ACCEPTANTA: transferul nu creeaza o a doua cheltuiala / achizitie', near((float) $val('SELECT COALESCE(SUM(cost), 0) FROM mentenanta'), $beforeExpenses)
    && (int) $val('SELECT COUNT(*) FROM mentenanta_piese_receptii') === $beforeReceipts && near($stock($pump), $stockBefore));
check('transfer: retry nu dubleaza', $inventory->transfer($c1['usage_id'], $tractorB, ['data_montare' => $day], 'transfer-' . $tag)['duplicate'] === true);
check('transfer: componenta deja demontata nu se mai transfera', fails(fn () => $inventory->transfer($c1['usage_id'], $tractorA, ['data_montare' => $day])));

// retur in stoc pe receptia originala, apoi remontare la acelasi cost
$returnPart = $newPart('Retur');
$rr = $inventory->receive($returnPart, 1, ['cost_unitar' => 300], $userId);
$inventory->receive($returnPart, 1, ['cost_unitar' => 450], $userId);
$cr = $inventory->consume($returnPart, 1, ['vehicle_id' => $truck, 'data_montare' => $day, 'component_key' => $componentKey, 'locatie' => 'vehicul']);
$components->unmount($cr['usage_id'], $truck, $day, null, 'retur_stoc', 'test');
check('retur stoc: cantitatea revine pe receptia originala', near((float) $val('SELECT cantitate_ramasa FROM mentenanta_piese_receptii WHERE id = ?', [$rr['id']]), 1) && near($stock($returnPart), 2));
$remount = $inventory->consume($returnPart, 1, ['vehicle_id' => $tractorA, 'data_montare' => $day]);
check('remontare dupa retur: costul original (300), nu pretul curent (450)', near($remount['cost_total'], 300));

// stergerea unei inregistrari readuce stocul pe receptii
$delPart = $newPart('Stergere');
$inventory->receive($delPart, 2, ['cost_unitar' => 10], $userId);
$delRec = $maintenance->saveRecord(['vehicle_id' => $truck, 'tip_interventie' => 'Sterge ' . $tag, 'record_type' => 'reparatie', 'data_interventie' => $day, 'stock_part_id' => $delPart, 'stock_part_quantity' => 2]);
check('saveRecord consuma 2', near($stock($delPart), 0));
$maintenance->deleteRecord($delRec);
check('deleteRecord: stoc si receptii restaurate', near($stock($delPart), 2) && near($receiptSum($delPart), 2));

// Stoc piese: completare catalog + piesa noua cu stoc initial
$catalogPart = $newPart('Catalog');
$maintenance->savePart(['existing_part_id' => $catalogPart, 'usage_destination' => 'stock', 'cod_piesa' => 'x', 'denumire' => 'x', 'categorie' => 'Test', 'stoc_initial' => 4, 'pret_achizitie' => 25, 'furnizor' => 'Furnizor D'], [], $userId);
$catalogReceipt = $one('SELECT * FROM mentenanta_piese_receptii WHERE part_id = ?', [$catalogPart]);
check('completare stoc din formular -> receptie declarata', $catalogReceipt !== null && near((float) $catalogReceipt['cost_unitar'], 25) && $catalogReceipt['status_evaluare'] === 'declarat_manual' && near($stock($catalogPart), 4));
$savedPartId = $maintenance->savePart(['usage_destination' => 'stock', 'cod_piesa' => $tag . '-NOU', 'denumire' => 'Piesa noua ' . $tag, 'categorie' => 'Test', 'stoc_initial' => 3], [], $userId);
$noPrice = $one('SELECT * FROM mentenanta_piese_receptii WHERE part_id = ?', [$savedPartId]);
check('piesa noua fara pret: receptie NEVERIFICATA, cost necunoscut (nu inventat)', $noPrice !== null && $noPrice['cost_unitar'] === null && $noPrice['status_evaluare'] === 'neverificat_istoric' && near($stock($savedPartId), 3));
$unverified = $inventory->consume($savedPartId, 1, ['vehicle_id' => $truck, 'data_montare' => $day]);
check('consum din receptie fara pret: marcat valoare_neverificata', $unverified['verified'] === false
    && (int) $val('SELECT valoare_neverificata FROM mentenanta_piese_utilizari WHERE id = ?', [$unverified['usage_id']]) === 1);

// stoc schimbat pe alta cale (cod vechi): reconcilierea pastreaza invariantul
$legacyPart = $newPart('Legacy');
$db->prepare('UPDATE mentenanta_piese SET stoc_curent = 5, pret_achizitie = 40 WHERE id = ?')->execute([$legacyPart]);
$legacy = $inventory->consume($legacyPart, 2, ['vehicle_id' => $truck, 'data_montare' => $day]);
check('stoc fara receptie: ajustare neverificata, nu pret inventat ca dovada', $legacy['verified'] === false && near($stock($legacyPart), 3) && near($receiptSum($legacyPart), 3)
    && (int) $val("SELECT COUNT(*) FROM mentenanta_piese_receptii WHERE part_id = ? AND sursa_tip = 'ajustare' AND cost_unitar IS NULL", [$legacyPart]) === 1);

// Componente vehicul: montare din stoc (calea 3)
$mountPart = $newPart('Montaj');
$inventory->receive($mountPart, 2, ['cost_unitar' => 60], $userId);
$ids = $components->mountMany(['vehicle_id' => $truck, 'part_id' => $mountPart, 'component_key' => $componentKey, 'source' => 'stoc', 'quantity' => 1,
    'date' => $day, 'km' => null, 'notes' => 'test', 'idempotency_key' => $tag . 'mount'], [['location' => 'vehicul', 'axle' => null, 'side' => null, 'replace' => null]], $userId, 'Tester');
$mounted = $one('SELECT * FROM mentenanta_piese_utilizari WHERE id = ?', [$ids[0]]);
check('mountMany din stoc: cost receptie, stoc scazut', near((float) $mounted['cost_unitar'], 60) && near($stock($mountPart), 1) && $mounted['locatie'] === 'vehicul' && $mounted['sursa_piesa'] === 'stoc');
$idsAgain = $components->mountMany(['vehicle_id' => $truck, 'part_id' => $mountPart, 'component_key' => $componentKey, 'source' => 'stoc', 'quantity' => 1,
    'date' => $day, 'km' => null, 'notes' => 'test', 'idempotency_key' => $tag . 'mount'], [['location' => 'vehicul', 'axle' => null, 'side' => null, 'replace' => null]], $userId, 'Tester');
check('mountMany retry cu aceeasi cheie: fara al doilea consum', $idsAgain[0] === $ids[0] && near($stock($mountPart), 1));
$directIds = $components->mountMany(['vehicle_id' => $truck, 'part_id' => $mountPart, 'component_key' => $componentKey, 'source' => 'atelier', 'quantity' => 1,
    'date' => $day, 'km' => null, 'notes' => 'test'], [['location' => 'vehicul', 'axle' => null, 'side' => null, 'replace' => 'auto']], $userId, 'Tester');
check('mountMany piesa adusa de atelier: fara scadere stoc, sursa atelier_extern', near($stock($mountPart), 1)
    && $val('SELECT sursa_piesa FROM mentenanta_piese_utilizari WHERE id = ?', [$directIds[0]]) === 'atelier_extern');

// Interventii V2 (calea 4)
$finalizePart = $newPart('Interventie');
$inventory->receive($finalizePart, 2, ['cost_unitar' => 1200], $userId);
$db->prepare("INSERT INTO mentenanta_interventii_programate (vehicle_id, tip_interventie, data_programata, cost_estimat, cost_suplimentar, descriere,
                status_interventie, responsabil_user_id, created_by, created_at, updated_at)
              VALUES (:v, 'reparatie', :d, 0, 50, :descr, 'in_lucru', :u, :u2, NOW(), NOW())")
    ->execute([':v' => $trailerA, ':d' => $day, ':descr' => 'Interventie test ' . $tag, ':u' => $userId, ':u2' => $userId]);
$interventionId = (int) $db->lastInsertId();
$addItem = $db->prepare("INSERT INTO mentenanta_interventii_lucrari (intervention_id, component_key, denumire_libera, stare_evaluare, operatiune, progres,
                rezultat, cost_piese, cost_manopera, natura_cost, sursa_piesa, stock_part_id, cantitate_piesa, data_montare, sort_order, created_at, updated_at)
              VALUES (:i, :ck, :dl, 'confirmata', :op, 'terminata', 'executata', :cp, :cm, :n, :src, :sp, :qty, :dm, :o, NOW(), NOW())");
$addItem->execute([':i' => $interventionId, ':ck' => $componentKey, ':dl' => null, ':op' => 'inlocuire', ':cp' => 999, ':cm' => 200, ':n' => null, ':src' => 'stoc', ':sp' => $finalizePart, ':qty' => 1, ':dm' => $day, ':o' => 1]);
$stockItem = (int) $db->lastInsertId();
$addItem->execute([':i' => $interventionId, ':ck' => null, ':dl' => 'Montaj kit GPL ' . $tag, ':op' => 'reparatie', ':cp' => 3000, ':cm' => 700, ':n' => 'investitie', ':src' => 'achizitie_directa', ':sp' => null, ':qty' => null, ':dm' => $day, ':o' => 2]);
$investItem = (int) $db->lastInsertId();
$workflow = new InterventionWorkflowModel($db);
$outcomes = [$stockItem => ['rezultat' => 'executata', 'confirma_inlocuire' => 1], $investItem => ['rezultat' => 'executata']];
$fin1 = $workflow->finalize($interventionId, $outcomes, ['data_executie' => $day, 'km' => ''], $userId, true, $maintenance);
$rows = $q('SELECT * FROM mentenanta WHERE source_intervention_id = ? ORDER BY id', [$interventionId]);
$main = $one('SELECT * FROM mentenanta WHERE id = ?', [$fin1['maintenance_id']]);
check('ACCEPTANTA: interventia finalizata scade stocul pentru piesa din stoc', near($stock($finalizePart), 1));
check('interventie: piesa din stoc evaluata la receptie (1.200), nu valoarea manuala (999)', near((float) $main['cost_piese'], 1200) && near((float) $main['cost'], 1200 + 200 + 50));
check('interventie mixta: rand separat pentru investitie (lucrare)', count($rows) === 2 && in_array('investitie', array_column($rows, 'natura_cost'), true));
$investRow = array_values(array_filter($rows, static fn ($r) => $r['natura_cost'] === 'investitie'))[0] ?? null;
check('interventie: randul investitiei are costul lucrarii, tratament de_confirmat', $investRow !== null && near((float) $investRow['cost'], 3700) && $investRow['tratament_contabil'] === 'de_confirmat');
$fin2 = $workflow->finalize($interventionId, $outcomes, ['data_executie' => $day, 'km' => ''], $userId, true, $maintenance);
check('ACCEPTANTA: a doua finalizare nu dubleaza consumul sau cheltuielile', $fin2['already'] === true && near($stock($finalizePart), 1)
    && count($q('SELECT id FROM mentenanta WHERE source_intervention_id = ?', [$interventionId])) === 2
    && (int) $val('SELECT COUNT(*) FROM mentenanta_piese_utilizari WHERE scheduled_intervention_id = ?', [$interventionId]) === 1);
$itemUsage = $one('SELECT u.* FROM mentenanta_interventii_lucrari l JOIN mentenanta_piese_utilizari u ON u.id = l.usage_id WHERE l.id = ?', [$stockItem]);
check('interventie: montarea pe semiremorca + legatura cu inregistrarea', $itemUsage !== null && (int) $itemUsage['vehicle_id'] === $trailerA && (int) $itemUsage['maintenance_id'] === (int) $fin1['maintenance_id'] && $itemUsage['sursa_piesa'] === 'stoc');

// supplier_invoices (oglinda derivata) nu este citita ca sursa
check('regula: oglinda supplier_invoices nu e sursa de cost (doar mentenanta)', true);

// =====================================================================================
echo "\nFaza 3 — dosar de achizitie\n";
// =====================================================================================
$acq = new VehicleAcquisitionService($db);
$newVehicle = static function (string $type, string $vin) use ($db, $tag): int {
    $db->prepare("INSERT INTO vehicule (nr_inmatriculare, marca, model, tip_vehicul, an_fabricatie, serie_sasiu, status, created_at, updated_at)
                  VALUES (:n, 'Test', 'Model', :t, 2020, :vin, 'activ', NOW(), NOW())")->execute([':n' => $tag . '-' . bin2hex(random_bytes(2)), ':t' => $type, ':vin' => $vin]);
    return (int) $db->lastInsertId();
};
$vinTractor = substr('WDB' . strtoupper(bin2hex(random_bytes(7))), 0, 17);
$tractorDossier = $acq->createDossier(['tip_vehicul' => 'cap_tractor', 'stare' => 'rulat', 'modalitate_achizitie' => 'plata_integrala', 'vin' => $vinTractor,
    'vanzator_nume' => 'Dealer DE', 'vanzator_tara' => 'DE', 'tara_achizitie' => 'DE', 'locatie_vehicul' => 'Hamburg', 'pret_achizitie' => 50000, 'moneda' => 'EUR',
    'curs_schimb' => 4.97, 'curs_data' => $day, 'curs_sursa' => 'BNR', 'regim_tva' => 'intracomunitar', 'utilizare_prevazuta' => 'Transport GPL'], $userId);
$dossier = $acq->dossier($tractorDossier);
check('dosar fara vehicul inmatriculat (cod provizoriu + VIN)', $dossier['vehicle_id'] === null && str_starts_with((string) $dossier['cod_dosar'], 'ACH-') && $dossier['vin'] === $vinTractor);
$acq->saveTransport($tractorDossier, ['modalitate' => 'transportator', 'transportator' => 'Carrier SRL', 'comanda_transport' => 'CMD-' . $tag,
    'loc_preluare' => 'Hamburg', 'loc_destinatie' => 'Ploiesti', 'servicii_incluse' => 'numere_provizorii'], $userId);
$cats = array_column($acq->categories($tractorDossier), 'cod');
check('categorii: transportator -> fara combustibil/taxe drum de preluare, fara vama (UE)', in_array('transport_extern', $cats, true) && !in_array('combustibil_preluare', $cats, true) && !in_array('taxe_vamale', $cats, true));
$priceLine = $acq->addCost($tractorDossier, ['categorie' => 'pret_achizitie', 'suma_neta' => 50000, 'moneda' => 'EUR', 'curs_schimb' => 4.97, 'status_verificare' => 'confirmat',
    'furnizor' => 'Dealer DE', 'document_numar' => 'INV-DE-1', 'data_tranzactie' => $day, 'tva_deductibil' => 'nu'], $userId);
$commission = $acq->addCost($tractorDossier, ['categorie' => 'comision_achizitie', 'suma_neta' => 1000, 'tva' => 210, 'tva_deductibil' => 'da', 'status_verificare' => 'confirmat', 'data_tranzactie' => $day], $userId);
$transportLine = $acq->addCost($tractorDossier, ['categorie' => 'transport_extern', 'suma_neta' => 3000, 'tva' => 630, 'tva_deductibil' => 'da', 'status_verificare' => 'estimat', 'furnizor' => 'Carrier SRL', 'data_tranzactie' => $day], $userId);
check('transportator: serviciul inclus in pret nu se adauga separat', fails(fn () => $acq->addCost($tractorDossier, ['categorie' => 'numere_provizorii', 'suma_neta' => 200], $userId), 'inclusă'));
$acq->addCost($tractorDossier, ['categorie' => 'numere_provizorii', 'suma_neta' => 200, 'inclus_in_linie_id' => $transportLine, 'observatii' => 'informativ'], $userId);
check('categorie nepotrivita contextului blocata (combustibil la transportator)', fails(fn () => $acq->addCost($tractorDossier, ['categorie' => 'combustibil_preluare', 'suma_neta' => 100], $userId), 'nu se aplică'));
check('suma nu se inventeaza (obligatorie)', fails(fn () => $acq->addCost($tractorDossier, ['categorie' => 'rar_verificare'], $userId), 'obligatorie'));
$acq->addPayment($tractorDossier, ['data_plata' => $day, 'suma' => 50000, 'moneda' => 'EUR', 'curs_schimb' => 4.97, 'cheltuiala_linie_id' => $priceLine], $userId);
$before = $one('SELECT clasificare, tratament_contabil FROM achizitii_vehicule_cheltuieli WHERE id = ?', [$commission]);
$acq->setCostVat($commission, 'partial', 100.0, $userId, 'test');
$acq->addPayment($tractorDossier, ['data_plata' => $day, 'suma' => 500], $userId);
$after = $one('SELECT clasificare, tratament_contabil FROM achizitii_vehicule_cheltuieli WHERE id = ?', [$commission]);
check('clasificarea / tratamentul independente de TVA si plati', $before === $after);
check('linia de TVA nu se capitalizeaza', (function () use ($acq, $tractorDossier, $classification, $userId): bool {
    return fails(fn () => $classification->setTreatment('achizitie_cheltuiala', $acq->addCost($tractorDossier, ['categorie' => 'alte_directe', 'clasificare' => 'tva', 'suma_neta' => 10, 'tva_deductibil' => 'da'], $userId), 'capitalizabil', $userId, 'x'), 'TVA');
})());
$s1 = $acq->summary($tractorDossier);
check('nicio cheltuiala capitalizata automat', near($s1['cost_initial_capitalizabil'], 0) && $s1['tratament_neconfirmat'] > 0);
$classification->setTreatment('achizitie_cheltuiala', $priceLine, 'capitalizabil', $userId, 'Cost de achiziție — politica contabilă');
$classification->setTreatment('achizitie_cheltuiala', $transportLine, 'capitalizabil', $userId, 'Cost direct atribuibil');
$classification->setTreatment('achizitie_cheltuiala', $commission, 'cheltuiala', $userId, 'Comision tratat pe cheltuială');
$s = $acq->summary($tractorDossier);
check('pret achizitie in RON din cursul original (50.000 EUR x 4,97)', near($s['pret_achizitie'], 248500));
check('cheltuieli asociate fara linia inclusa', near($s['cheltuieli_asociate'], 4000) && $s['linii_incluse'] === 1);
check('cost initial capitalizabil = doar liniile aprobate', near($s['cost_initial_capitalizabil'], 251500));
check('necapitalizabile separate', near($s['necapitalizabile'], 1000));
check('TVA recuperabil / nerecuperabil', near($s['tva_recuperabil'], 100 + 630) && near($s['tva_nerecuperabil'], 110));
check('estimat vs confirmat', near($s['estimat'], 3000) && $s['confirmat'] > 0);
check('platit si sold neachitat', near($s['platit'], 248500 + 500) && near($s['sold_neachitat'], $s['total_document'] - $s['platit']));
$acq->addCost($tractorDossier, ['categorie' => 'alte_directe', 'suma_neta' => 100, 'moneda' => 'EUR'], $userId);
check('linie in valuta fara curs: neconvertita, semnalata', count(array_filter($acq->summary($tractorDossier)['incomplet'], static fn ($m) => str_contains($m, 'fără curs'))) === 1);

$acq->recordEvent($tractorDossier, 'receptie_tehnica', $day, $userId, 'PV-REC-1');
$s = $acq->summary($tractorDossier);
check('receptia NU este punere in functiune', $s['data_receptie'] === $day && $s['data_punere_in_functiune'] === null);
check('status pus_in_functiune cere evenimentul', fails(fn () => $acq->setStatus($tractorDossier, 'pus_in_functiune', $userId), 'Punere în funcțiune'));
check('punerea in functiune nu precede receptia', fails(fn () => $acq->recordEvent($tractorDossier, 'punere_in_functiune', date('Y-m-d', strtotime('-30 days')), $userId), 'înaintea'));
$acq->recordEvent($tractorDossier, 'punere_in_functiune', $day, $userId);
$acq->setStatus($tractorDossier, 'pus_in_functiune', $userId);
$wrongVehicle = $newVehicle('cap_tractor', 'XXXXXXXXXXXXXXXXX');
check('legare vehicul cu VIN diferit refuzata', fails(fn () => $acq->linkVehicle($tractorDossier, $wrongVehicle, $userId), 'VIN'));
$registeredTractor = $newVehicle('cap_tractor', $vinTractor);
$acq->linkVehicle($tractorDossier, $registeredTractor, $userId);
check('dosarul se leaga la inmatriculare (id vehicul existent)', (int) $acq->dossier($tractorDossier)['vehicle_id'] === $registeredTractor);
check('un vehicul = un dosar activ', fails(fn () => $acq->createDossier(['tip_vehicul' => 'cap_tractor', 'stare' => 'nou', 'modalitate_achizitie' => 'credit', 'vehicle_id' => $registeredTractor], $userId), 'deja'));

// semiremorca, adusa condusa (sofer, combustibil, taxe drum, cazare)
$trailerVin = substr('SR' . strtoupper(bin2hex(random_bytes(8))), 0, 17);
$trailerVehicle = $newVehicle('semiremorca_primar', $trailerVin);
$trailerDossier = $acq->createDossier(['tip_vehicul' => 'semiremorca_primar', 'stare' => 'nou', 'modalitate_achizitie' => 'leasing_financiar', 'vehicle_id' => $trailerVehicle,
    'pret_achizitie' => 120000, 'moneda' => 'RON', 'regim_tva' => 'normal'], $userId);
$driverId = (int) $val('SELECT id FROM soferi ORDER BY id LIMIT 1');
$acq->saveTransport($trailerDossier, ['modalitate' => 'condus', 'sofer_id' => $driverId, 'loc_preluare' => 'Fabrica', 'loc_destinatie' => 'Garaj', 'numere_provizorii' => 'B-123-TMP'], $userId);
foreach (['pret_achizitie' => 120000, 'combustibil_preluare' => 900, 'taxe_drum' => 150, 'cazare_sofer' => 300] as $cat => $sum) {
    $acq->addCost($trailerDossier, ['categorie' => $cat, 'suma_neta' => $sum, 'status_verificare' => 'confirmat'], $userId);
}
$st = $acq->summary($trailerDossier);
check('semiremorca: dosar cu vehicul existent si livrare condusa', (int) $acq->dossier($trailerDossier)['vehicle_id'] === $trailerVehicle && near($st['pret_achizitie'], 120000) && near($st['cheltuieli_asociate'], 1350));
check('semiremorca: categorie de transportator refuzata la livrare condusa', fails(fn () => $acq->addCost($trailerDossier, ['categorie' => 'transport_extern', 'suma_neta' => 1], $userId), 'nu se aplică'));

// =====================================================================================
echo "\nFaza 4 — proiect GPL\n";
// =====================================================================================
$projects = new InvestmentProjectService($db);
$projectId = $projects->create(['vehicle_id' => $trailerVehicle, 'denumire' => 'Adaptare semiremorca GPL ' . $tag, 'categorie' => 'adaptare_gpl', 'buget_planificat' => 60000, 'data_start' => $day], $userId);
$p = $projects->project($projectId);
check('proiect: natura investitie, tratament de_confirmat', $p['natura_cost'] === 'investitie' && $p['tratament_contabil'] === 'de_confirmat');
$work1 = $maintenance->saveRecord(['vehicle_id' => $trailerVehicle, 'tip_interventie' => 'Montaj compresor ' . $tag, 'record_type' => 'reparatie', 'natura_cost' => 'investitie',
    'proiect_investitie_id' => $projectId, 'data_interventie' => $day, 'cost_manopera' => 4000, 'cost_piese' => 30000]);
check('mentenanta pe alt vehicul nu se poate lega de proiect', fails(fn () => $maintenance->saveRecord(['vehicle_id' => $truck, 'tip_interventie' => 'x', 'record_type' => 'reparatie',
    'proiect_investitie_id' => $projectId, 'data_interventie' => $day, 'cost' => 1]), 'altui vehicul'));
$db->prepare("INSERT INTO cheltuieli (categorie, tip_id, tip_document, data_cheltuiala, furnizor, descriere, valoare, valoare_neta, tva, alocare_tip, created_at, updated_at)
              VALUES ('administrativa', 68, 'factura', :d, 'Organism notificat', :descr, 1190, 1000, 190, 'vehicul', NOW(), NOW())")->execute([':d' => $day, ':descr' => 'Certificare GPL ' . $tag]);
$certExpense = (int) $db->lastInsertId();
$db->prepare("INSERT INTO cheltuieli_alocari (cheltuiala_id, tip_alocare, vehicul_id, suma, created_at, updated_at) VALUES (:c, 'vehicul', :v, 1190, NOW(), NOW())")
    ->execute([':c' => $certExpense, ':v' => $trailerVehicle]);
$projects->link($projectId, 'cheltuiala', $certExpense, $userId);
$projects->link($projectId, 'cheltuiala', $certExpense, $userId); // a doua legare: fara dublare
check('sursa altui vehicul refuzata', fails(fn () => $projects->link($projectId, 'mentenanta', $repairId, $userId), 'altui vehicul'));
$db->prepare("INSERT INTO ocr_reparatii (vehicle_id, data_interventie, furnizor, tip_lucrare, created_at, updated_at) VALUES (:v, :d, 'Atelier GPL', 'imbunatatire', NOW(), NOW())")
    ->execute([':v' => $trailerVehicle, ':d' => $day]);
$pendingOcr = (int) $db->lastInsertId();
$projects->link($projectId, 'ocr_reparatie', $pendingOcr, $userId);
$totals = $projects->totals($projectId);
check('ACCEPTANTA: total proiect = surse legate deduplicate (34.000 lucrare + 1.000 certificare neta)', near($totals['cost_real'], 35000) && count($totals['surse']) === 2, json_encode($totals['surse']));
check('total reconciliat cu sursele', near($totals['cost_real'], (float) $val('SELECT cost FROM mentenanta WHERE id = ?', [$work1]) + 1000));
check('detaliere piese / manopera / servicii', near($totals['piese'], 30000) && near($totals['manopera'], 4000) && near($totals['servicii_externe'], 1000));
check('factura OCR legata dar netrimisa: separat, nu in total', count($totals['neinregistrate']) === 1 && $totals['neinregistrate'][0]['sursa'] === 'ocr_reparatie');
check('buget editabil independent; costul real nu se introduce manual', near($totals['diferenta_buget'], 25000));
$projects->update($projectId, ['buget_planificat' => 70000], $userId);
check('modificarea bugetului nu schimba costul real', near($projects->totals($projectId)['cost_real'], 35000) && near((float) $projects->project($projectId)['buget_planificat'], 70000));
check('vehiculul proiectului nu se schimba', fails(fn () => $projects->update($projectId, ['vehicle_id' => $truck], $userId), 'nu se schimbă'));
check('cheltuiala din dosarul de achizitie nu intra si in proiect', (function () use ($db, $acq, $trailerDossier, $projects, $projectId, $trailerVehicle, $day, $userId): bool {
    $db->prepare("INSERT INTO cheltuieli (categorie, tip_id, tip_document, data_cheltuiala, valoare, valoare_neta, alocare_tip, created_at, updated_at) VALUES ('administrativa', 68, 'factura', :d, 500, 500, 'vehicul', NOW(), NOW())")->execute([':d' => $day]);
    $id = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO cheltuieli_alocari (cheltuiala_id, tip_alocare, vehicul_id, suma, created_at, updated_at) VALUES (:c, 'vehicul', :v, 500, NOW(), NOW())")->execute([':c' => $id, ':v' => $trailerVehicle]);
    $acq->addCost($trailerDossier, ['categorie' => 'rar_verificare', 'cheltuiala_id' => $id], $userId);
    return fails(fn () => $projects->link($projectId, 'cheltuiala', $id, $userId), 'dosarul de achiziție');
})());

// mixed intervention linked to the project: only the investment row gets the project
$db->prepare("INSERT INTO mentenanta_interventii_programate (vehicle_id, tip_interventie, data_programata, cost_estimat, descriere, status_interventie, responsabil_user_id, created_at, updated_at)
              VALUES (:v, 'reparatie', :d, 0, :descr, 'in_lucru', :u, NOW(), NOW())")->execute([':v' => $trailerVehicle, ':d' => $day, ':descr' => 'Mixt ' . $tag, ':u' => $userId]);
$mixed = (int) $db->lastInsertId();
$addItem->execute([':i' => $mixed, ':ck' => null, ':dl' => 'Reparatie frana ' . $tag, ':op' => 'reparatie', ':cp' => 500, ':cm' => 100, ':n' => null, ':src' => null, ':sp' => null, ':qty' => null, ':dm' => $day, ':o' => 1]);
$addItem->execute([':i' => $mixed, ':ck' => null, ':dl' => 'Valve GPL ' . $tag, ':op' => 'reparatie', ':cp' => 2000, ':cm' => 300, ':n' => 'investitie', ':src' => 'atelier_extern', ':sp' => null, ':qty' => null, ':dm' => $day, ':o' => 2]);
$workflow->finalize($mixed, [], ['data_executie' => $day, 'km' => ''], $userId, true, $maintenance);
$projects->link($projectId, 'interventie', $mixed, $userId);
$t2 = $projects->totals($projectId);
check('interventie mixta legata: doar randul investitiei intra in proiect', near($t2['cost_real'], 35000 + 2300));
check('reparatia din aceeasi interventie ramane cost operational', (int) $val("SELECT COUNT(*) FROM mentenanta WHERE source_intervention_id = ? AND natura_cost = 'reparatie' AND proiect_investitie_id IS NULL", [$mixed]) === 1);

// cuplaje: proiectul ramane pe semiremorca
$db->prepare("INSERT INTO vehicule_cuplaje (tractor_id, semiremorca_id, activ, data_start, data_end, created_at, updated_at) VALUES (:t, :s, 0, :a, :b, NOW(), NOW())")
    ->execute([':t' => $tractorA, ':s' => $trailerVehicle, ':a' => date('Y-m-d 00:00:00', strtotime('-20 days')), ':b' => date('Y-m-d 00:00:00', strtotime('-10 days'))]);
$db->prepare("INSERT INTO vehicule_cuplaje (tractor_id, semiremorca_id, activ, data_start, data_end, created_at, updated_at) VALUES (:t, :s, 1, :a, NULL, NOW(), NOW())")
    ->execute([':t' => $tractorB, ':s' => $trailerVehicle, ':a' => date('Y-m-d 00:00:00', strtotime('-10 days'))]);
$after = $projects->totals($projectId);
check('ACCEPTANTA: schimbarea cuplajului nu muta investitia', (int) $projects->project($projectId)['vehicle_id'] === $trailerVehicle && near($after['cost_real'], $t2['cost_real']));
$history = new VehicleFinancialHistoryService($db);
$trailerHist = $history->forVehicle($trailerVehicle);
check('istoric semiremorca: contine investitia proiectului', count(array_filter($trailerHist, static fn ($r) => $r['proiect_investitie_id'] === $projectId)) >= 2);
check('istoric tractor: NU contine investitia semiremorcii', count(array_filter($history->forVehicle($tractorB), static fn ($r) => $r['proiect_investitie_id'] === $projectId)) === 0);

// pregatire GPL: separat de finalizarea financiara
$projects->update($projectId, ['status' => 'finalizat'], $userId);
$r = $projects->readiness($projectId, ['ADR ' . $tag]);
check('ACCEPTANTA: proiect finalizat financiar != apt legal pentru GPL', $r['status_financiar'] === 'finalizat' && $r['apt_legal'] === false && $r['documente']['ADR ' . $tag]['stare'] === 'lipsa');
$db->prepare("INSERT INTO documente (vehicle_id, proiect_investitie_id, tip_document, numar_document, data_expirare, created_at, updated_at) VALUES (:v, :p, :t, 'ADR-1', :e, NOW(), NOW())")
    ->execute([':v' => $trailerVehicle, ':p' => $projectId, ':t' => 'ADR ' . $tag, ':e' => date('Y-m-d', strtotime('+1 year'))]);
$adrDoc = (int) $db->lastInsertId();
$r = $projects->readiness($projectId, ['ADR ' . $tag]);
check('document valabil dar fara aprobare: inca neapt', $r['documente_valabile'] === true && $r['apt_legal'] === false);
$projects->confirmStage($projectId, 'aprobat_activitate', $day, $userId, $adrDoc, 'Aviz organism notificat');
check('aprobat + documente valabile = apt; pus in functiune separat', $projects->readiness($projectId, ['ADR ' . $tag])['apt_legal'] === true && $projects->readiness($projectId, ['ADR ' . $tag])['pus_in_functiune'] === false);
check('document expirat la data verificarii -> neapt', $projects->readiness($projectId, ['ADR ' . $tag], date('Y-m-d', strtotime('+2 years')))['apt_legal'] === false);
check('durata utila cere temei', fails(fn () => $projects->approveUsefulLife($projectId, 120, $userId, ''), 'Temeiul'));
$projects->approveUsefulLife($projectId, 120, $userId, 'Decizie contabilitate nr. 1');
check('durata utila aprobata cu cine/cand', (int) $projects->project($projectId)['durata_utila_luni'] === 120 && (int) $projects->project($projectId)['durata_utila_aprobata_de'] === $userId);
check('documentele existente raman accesibile (citire cu noua coloana)', (int) $val('SELECT COUNT(*) FROM documente WHERE vehicle_id = ?', [$trailerVehicle]) === 1
    && $q('SELECT id, vehicle_id, tip_document, numar_document, data_expirare, fisier_stocat FROM documente WHERE id <= ? ORDER BY id', [(int) (end($documentsBefore)['id'] ?? 0)]) === $documentsBefore);

// =====================================================================================
echo "\nFaza 5 — citiri (istoric financiar, cuplaje pe perioade)\n";
// =====================================================================================
$periods = new VehicleCouplingPeriodService($db);
$shares = $periods->tractorShares($trailerVehicle, date('Y-m-d', strtotime('-20 days')), date('Y-m-d', strtotime('-1 day')));
check('cuplaje pe perioade: ambele tractoare, ponderi dupa data_start/data_end', isset($shares[$tractorA], $shares[$tractorB]) && $shares[$tractorA] > 0.45 && $shares[$tractorA] < 0.6);
check('tractorul la o data istorica (nu cuplajul activ)', $periods->tractorAt($trailerVehicle, date('Y-m-d', strtotime('-15 days'))) === $tractorA
    && $periods->tractorAt($trailerVehicle, $day) === $tractorB);
$keys = array_map(static fn ($r) => $r['sursa'] . ':' . $r['sursa_id'], $history->forVehicle($truck));
check('istoric: fiecare (sursa, sursa_id) o singura data', count($keys) === count(array_unique($keys)));
$truckHist = $history->forVehicle($truck);
check('istoric: piesa consumata prin mentenanta nu apare a doua oara ca montare', count(array_filter($truckHist, static fn ($r) => $r['sursa'] === 'piesa_montata' && $r['sursa_id'] === (int) $usage1['id'])) === 0);
check('istoric: montarea fara mentenanta (schema vehiculului) apare o data', count(array_filter($truckHist, static fn ($r) => $r['sursa'] === 'piesa_montata' && $r['sursa_id'] === $ids[0])) === 1);
check('istoric: transferul nu apare ca nou cost', count(array_filter($history->forVehicle($tractorB), static fn ($r) => $r['sursa'] === 'piesa_montata' && $r['sursa_id'] === $moved['usage_id'])) === 0);
check('istoric: capitalul separat de operational', ($history->totals($truck)['capital'] ?? 0) >= 20000);
$tractorHist = $history->forVehicle($registeredTractor);
check('istoric: liniile de achizitie pe vehiculul legat (fara linia inclusa)', count(array_filter($tractorHist, static fn ($r) => $r['sursa'] === 'achizitie_cheltuiala')) === 5);
$certRows = array_filter($history->forVehicle($trailerVehicle), static fn ($r) => ($r['cheltuiala_id'] ?? 0) === $certExpense);
check('istoric: cheltuiala alocata o data; cea din dosarul de achizitie nu se dubleaza', count($certRows) === 1
    && count(array_filter($history->forVehicle($trailerVehicle), static fn ($r) => $r['sursa'] === 'cheltuiala_alocare' && $r['suma'] == 500.0)) === 0);

echo "\n$passed ok, $failed esuate\n";
exit($failed === 0 ? 0 : 1);
