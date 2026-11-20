<?php
declare(strict_types=1);

/**
 * Teste pentru categoriile financiare ierarhice (Istoric Financiar Vehicul → Costuri):
 * Categorie principala → Subcategorie → Tranzactii → Total calculat automat.
 *
 *   DB_NAME=fleet_fin_test php scripts/test_categorii_financiare.php
 *
 * Scrie date REALE (comise) — ruleaza DOAR pe o baza al carei nume contine „test”.
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

require_once $root . '/htdocs/services/FinancialFoundationSchema.php';
require_once $root . '/htdocs/services/FinancialClassificationService.php';
require_once $root . '/htdocs/services/VehicleFinancialRegisterService.php';

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
FinancialFoundationSchema::ensure($db);
$userId = (int) $db->query('SELECT id FROM utilizatori ORDER BY id LIMIT 1')->fetchColumn();
$day = date('Y-m-d');
$acq = new VehicleAcquisitionService($db);
$register = new VehicleFinancialRegisterService($db);
$tree = new FinancialCategoryTree($db);

$rootId = $tree->idByCode('achizitie_exploatare');
$leaf = static fn (string $code): int => (int) $tree->idByCode($code);
/** Totalurile unui nod din detaliul dosarului (calculate pe server). */
$node = static function (array $detail, int $id): array {
    foreach ($detail['categorii_financiare'] as $n) {
        if ($n['id'] === $id) {
            return $n['totaluri'];
        }
    }
    return FinancialCategoryTree::emptyTotals();
};
$sumChildren = static function (array $detail, int $parent): float {
    $sum = 0.0;
    foreach ($detail['categorii_financiare'] as $n) {
        if ($n['parent_id'] === $parent) {
            $sum += $n['totaluri']['total'];
        }
    }
    return round($sum, 2);
};

echo "Structura\n";
check('radacina „Achiziție și punere în exploatare” exista', $rootId !== null);
foreach (['ach_pret_cumparare', 'ach_transport_aducere', 'ach_taxe_formalitati', 'ach_pregatire_initiala', 'ach_echipare_accesorii'] as $code) {
    check("subcategoria $code este frunza sub radacina", $leaf($code) > 0 && $tree->isLeaf($leaf($code)) && $tree->path($leaf($code))[0] === $rootId);
}
check('radacinile viitoare exista (inactive, fara mutari)', $tree->idByCode('investitii_gpl') !== null && $tree->idByCode('reparatii_mentenanta') !== null
    && $tree->idByCode('taxe_obligatii') !== null && $tree->idByCode('alte_cheltuieli') !== null);
check('nicio linie fara subcategorie dupa migrare', (int) $db->query('SELECT COUNT(*) FROM achizitii_vehicule_cheltuieli WHERE financiar_categorie_id IS NULL')->fetchColumn() === 0);
check('fiecare tip de cost are subcategorie implicita', (int) $db->query('SELECT COUNT(*) FROM achizitii_categorii_cheltuieli WHERE financiar_categorie_id IS NULL')->fetchColumn() === 0);

echo "\n7. Dosarele existente isi pastreaza totalurile\n";
$mismatch = [];
foreach ($db->query("SELECT id FROM achizitii_vehicule WHERE status <> 'anulat'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $d = $register->detail((int) $id, null);
    $s = $d['sumar'];
    $rootTotal = $node($d, $rootId)['total'];
    if (!near($rootTotal, round($s['pret_achizitie'] + $s['cheltuieli_asociate'], 2)) || !near($rootTotal, $sumChildren($d, $rootId))
        || !near($node($d, $rootId)['capitalizabil'], $s['cost_initial_capitalizabil']) || !near($node($d, $rootId)['necapitalizabil'], $s['necapitalizabile'])) {
        $mismatch[] = $id;
    }
}
check('radacina = cost initial = suma subcategoriilor, pe toate dosarele', $mismatch === [], 'dosare: ' . implode(',', $mismatch));

// Dosar nou de test (plata integrala, condus)
$dossierId = $acq->createDossier(['tip_vehicul' => 'cap_tractor', 'stare' => 'rulat', 'modalitate_achizitie' => 'plata_integrala',
    'vin' => substr('CAT' . strtoupper(bin2hex(random_bytes(7))), 0, 17), 'vanzator_nume' => 'Test categorii', 'regim_tva' => 'normal'], $userId);
$acq->saveTransport($dossierId, ['modalitate' => 'condus', 'sofer_nume' => 'Șofer test'], $userId);
$detail = static fn (): array => $register->detail($dossierId, null);

echo "\n1. Adaugarea actualizeaza subcategoria si parintele\n";
$price = $acq->addCost($dossierId, ['categorie' => 'pret_achizitie', 'suma_neta' => 90000, 'status_verificare' => 'confirmat', 'data_tranzactie' => $day], $userId);
$fuel = $acq->addCost($dossierId, ['categorie' => 'combustibil_preluare', 'suma_neta' => 1500, 'tva' => 285, 'data_tranzactie' => $day], $userId);
$road = $acq->addCost($dossierId, ['categorie' => 'taxe_drum', 'suma_neta' => 1000, 'data_tranzactie' => $day], $userId);
$rar = $acq->addCost($dossierId, ['categorie' => 'rar_verificare', 'suma_neta' => 800, 'status_verificare' => 'confirmat', 'data_tranzactie' => $day], $userId);
$prep = $acq->addCost($dossierId, ['categorie' => 'pregatire_tehnica', 'financiar_categorie_id' => $leaf('ach_pregatire_initiala'), 'suma_neta' => 700, 'data_tranzactie' => $day], $userId);
$equip = $acq->addCost($dossierId, ['categorie' => 'echipare_initiala', 'suma_neta' => 1500, 'data_tranzactie' => $day], $userId);
$d = $detail();
check('Preț cumpărare = 90.000', near($node($d, $leaf('ach_pret_cumparare'))['total'], 90000));
check('Transport și aducere = 2.500 (2 tranzactii)', near($node($d, $leaf('ach_transport_aducere'))['total'], 2500) && $node($d, $leaf('ach_transport_aducere'))['linii'] === 2);
check('Taxe și formalități = 800', near($node($d, $leaf('ach_taxe_formalitati'))['total'], 800));
check('Pregătire inițială = 700', near($node($d, $leaf('ach_pregatire_initiala'))['total'], 700));
check('Echipare și accesorii = 1.500', near($node($d, $leaf('ach_echipare_accesorii'))['total'], 1500));
check('parinte = 95.500 = suma subcategoriilor', near($node($d, $rootId)['total'], 95500) && near($sumChildren($d, $rootId), 95500));
check('parintele are 6 tranzactii (nicio tranzactie proprie)', $node($d, $rootId)['linii'] === 6);
check('dosarul: cost initial = 95.500', near($d['sumar']['pret_achizitie'] + $d['sumar']['cheltuieli_asociate'], 95500));
check('TVA-ul nu intra in totalul subcategoriei (net)', near($node($d, $leaf('ach_transport_aducere'))['total'], 2500));
check('nu se poate inregistra pe categoria principala', fails(fn () => $acq->addCost($dossierId, ['categorie' => 'alte_directe', 'financiar_categorie_id' => $rootId, 'suma_neta' => 1], $userId), 'subcategorie'));
check('nu se poate inregistra pe o categorie inactiva', fails(fn () => $acq->addCost($dossierId, ['categorie' => 'alte_directe', 'financiar_categorie_id' => $tree->idByCode('investitii_gpl'), 'suma_neta' => 1], $userId)));

echo "\n2. Editarea sumei recalculeaza\n";
$acq->updateCost($rar, ['suma_neta' => 1200], $userId);
$d = $detail();
check('Taxe și formalități = 1.200', near($node($d, $leaf('ach_taxe_formalitati'))['total'], 1200));
check('parinte = 95.900', near($node($d, $rootId)['total'], 95900) && near($sumChildren($d, $rootId), 95900));

echo "\n4. Mutarea in alta subcategorie actualizeaza ambele\n";
$acq->updateCost($road, ['financiar_categorie_id' => $leaf('ach_taxe_formalitati')], $userId);
$d = $detail();
check('Transport și aducere scade la 1.500', near($node($d, $leaf('ach_transport_aducere'))['total'], 1500));
check('Taxe și formalități creste la 2.200', near($node($d, $leaf('ach_taxe_formalitati'))['total'], 2200));
check('parintele ramane 95.900 (nicio dublare)', near($node($d, $rootId)['total'], 95900));
check('tipul si clasificarea liniei mutate raman', (function () use ($d, $road): bool {
    foreach ($d['linii'] as $l) {
        if ($l['id'] === $road) {
            return $l['categorie'] === 'taxe_drum' && $l['clasificare'] === 'cost_direct_atribuibil';
        }
    }
    return false;
})());
check('mutarea pe categoria principala e refuzata', fails(fn () => $acq->updateCost($road, ['financiar_categorie_id' => $rootId], $userId), 'subcategorie'));
check('mutarea e in auditul financiar', (int) $db->query("SELECT COUNT(*) FROM financiar_audit WHERE entitate = 'achizitie_cheltuiala' AND entitate_id = $road AND camp = 'financiar_categorie_id'")->fetchColumn() === 1);

echo "\n5/6. Filtrare: parinte → toate descendentele, subcategorie → doar ale ei\n";
$under = static fn (array $d, int $nodeId): array => array_values(array_map(static fn (array $l): int => $l['id'],
    array_filter($d['linii'], static fn (array $l): bool => in_array($nodeId, $l['fin_cale'], true))));
$all = $under($d, $rootId);
sort($all);
$expected = [$price, $fuel, $road, $rar, $prep, $equip];
sort($expected);
check('parintele intoarce toate cele 6 tranzactii', $all === $expected);
check('Taxe și formalități intoarce doar RAR + taxe de drum (mutata)', (function () use ($under, $d, $leaf, $rar, $road): bool {
    $ids = $under($d, $leaf('ach_taxe_formalitati'));
    sort($ids);
    $want = [$rar, $road];
    sort($want);
    return $ids === $want;
})());
check('descendants() include radacina + 6 subcategorii', count($tree->descendants($rootId)) === 7);

echo "\n8. Capitalizabil / necapitalizabil separate\n";
$classification = new FinancialClassificationService($db);
$classification->setTreatment('achizitie_cheltuiala', $price, 'capitalizabil', $userId, 'test: pret de achizitie');
$classification->setTreatment('achizitie_cheltuiala', $prep, 'cheltuiala', $userId, 'test: intretinere curenta');
$d = $detail();
$r = $node($d, $rootId);
check('radacina: capitalizabil 90.000, necapitalizabil 700, de confirmat 5.200', near($r['capitalizabil'], 90000) && near($r['necapitalizabil'], 700) && near($r['de_confirmat'], 5200));
check('capitalizabil + necapitalizabil + de confirmat = total', near($r['capitalizabil'] + $r['necapitalizabil'] + $r['de_confirmat'], $r['total']));
check('radacina = sumarul dosarului (capitalizat / necapitalizabil)', near($r['capitalizabil'], $d['sumar']['cost_initial_capitalizabil']) && near($r['necapitalizabil'], $d['sumar']['necapitalizabile']));
check('neverificat = liniile estimate (combustibil + taxe drum + pregatire + echipare = 4.700)', near($r['neverificat'], 4700));

echo "\n9. Nicio tranzactie numarata de doua ori\n";
$included = $acq->addCost($dossierId, ['categorie' => 'deplasare_sofer', 'suma_neta' => 400, 'inclus_in_linie_id' => $fuel], $userId);
$d = $detail();
check('linia „inclusa in alta linie” nu se aduna', near($node($d, $rootId)['total'], 95900));
$acq->addPayment($dossierId, ['cheltuiala_linie_id' => $price, 'suma' => 50000, 'data_plata' => $day], $userId);
$acq->addPayment($dossierId, ['cheltuiala_linie_id' => $price, 'suma' => 40000, 'data_plata' => $day], $userId);
$d = $detail();
check('platile nu se aduna ca cheltuieli (totalul ramane 95.900)', near($node($d, $rootId)['total'], 95900));
check('plătit pe subcategorie = 90.000 (separat)', near($node($d, $leaf('ach_pret_cumparare'))['platit'], 90000) && near($node($d, $rootId)['platit'], 90000));
$eur = $acq->addCost($dossierId, ['categorie' => 'alte_directe', 'suma_neta' => 100, 'moneda' => 'EUR'], $userId);
$d = $detail();
check('EUR fara curs nu se amesteca in total (raportat separat)', near($node($d, $rootId)['total'], 95900) && near((float) ($node($d, $rootId)['fara_curs']['EUR'] ?? 0), 100));
$acq->updateCost($eur, ['curs_schimb' => 5], $userId);
$d = $detail();
check('dupa curs: 100 EUR × 5 = 500 lei intra in total', near($node($d, $rootId)['total'], 96400) && ($node($d, $rootId)['fara_curs']['EUR'] ?? null) === null);
$db->prepare("INSERT INTO cheltuieli (categorie, tip_id, tip_document, data_cheltuiala, valoare, valoare_neta, alocare_tip, created_at, updated_at) VALUES ('administrativa', (SELECT MIN(id) FROM cheltuieli_tipuri), 'factura', :d, 300, 300, 'vehicul', NOW(), NOW())")->execute([':d' => $day]);
$expenseId = (int) $db->lastInsertId();
$acq->addCost($dossierId, ['categorie' => 'inmatriculare', 'cheltuiala_id' => $expenseId], $userId);
check('aceeasi factura din Cheltuieli nu se preia de doua ori', fails(fn () => $acq->addCost($dossierId, ['categorie' => 'inmatriculare', 'cheltuiala_id' => $expenseId], $userId), 'două ori'));
$d = $detail();
check('dupa factura preluata: 96.700 si parinte = suma copiilor', near($node($d, $rootId)['total'], 96700) && near($sumChildren($d, $rootId), 96700));
$tvaLine = $acq->addCost($dossierId, ['categorie' => 'alte_directe', 'clasificare' => 'tva', 'suma_neta' => 50], $userId);
$d = $detail();
check('linia de TVA nu intra in totalul categoriei', near($node($d, $rootId)['total'], 96700));

echo "\n3. Anularea (stornarea) recalculeaza\n";
check('anularea cere motiv', fails(fn () => $acq->cancelCost($equip, '  ', $userId), 'Motivul'));
check('linia cu plati nu se poate anula', fails(fn () => $acq->cancelCost($price, 'test', $userId), 'plăți'));
check('linia gazda a unei linii incluse nu se anuleaza', fails(fn () => $acq->cancelCost($fuel, 'test', $userId), 'incluse'));
$acq->cancelCost($equip, 'factura emisa gresit', $userId);
$d = $detail();
check('Echipare și accesorii = 0 dupa anulare', near($node($d, $leaf('ach_echipare_accesorii'))['total'], 0));
check('parinte = 95.200 = suma copiilor = cost initial dosar', near($node($d, $rootId)['total'], 95200) && near($sumChildren($d, $rootId), 95200)
    && near($d['sumar']['pret_achizitie'] + $d['sumar']['cheltuieli_asociate'], 95200));
check('linia anulata ramane vizibila, marcata', (function () use ($d, $equip): bool {
    foreach ($d['linii'] as $l) {
        if ($l['id'] === $equip) {
            return $l['anulat'] === true && $l['anulat_motiv'] === 'factura emisa gresit';
        }
    }
    return false;
})());
check('linia anulata nu mai poate fi editata', fails(fn () => $acq->updateCost($equip, ['suma_neta' => 1], $userId), 'anulată'));
check('linia anulata nu mai primeste plati', fails(fn () => $acq->addPayment($dossierId, ['cheltuiala_linie_id' => $equip, 'suma' => 1, 'data_plata' => $day], $userId), 'anulată'));
check('anularea e in auditul financiar', (int) $db->query("SELECT COUNT(*) FROM financiar_audit WHERE entitate = 'achizitie_cheltuiala' AND entitate_id = $equip AND actiune = 'anulare'")->fetchColumn() === 1);

echo "\nAgregare pura (fara baza): parinte = suma frunzelor, la 3 niveluri\n";
$ref = new ReflectionClass(FinancialCategoryTree::class);
$t3 = $ref->newInstanceWithoutConstructor();
$nodes = [
    1 => ['id' => 1, 'parent_id' => null, 'cod' => 'r', 'denumire' => 'R', 'descriere' => null, 'ordine' => 1, 'activ' => true],
    2 => ['id' => 2, 'parent_id' => 1, 'cod' => 'a', 'denumire' => 'A', 'descriere' => null, 'ordine' => 1, 'activ' => true],
    3 => ['id' => 3, 'parent_id' => 2, 'cod' => 'a1', 'denumire' => 'A1', 'descriere' => null, 'ordine' => 1, 'activ' => true],
    4 => ['id' => 4, 'parent_id' => 2, 'cod' => 'a2', 'denumire' => 'A2', 'descriere' => null, 'ordine' => 2, 'activ' => true],
    5 => ['id' => 5, 'parent_id' => 1, 'cod' => 'b', 'denumire' => 'B', 'descriere' => null, 'ordine' => 2, 'activ' => true],
];
$ref->getProperty('nodes')->setValue($t3, $nodes);
$ref->getProperty('children')->setValue($t3, [1 => [2, 5], 2 => [3, 4]]);
$line = static fn (int $id, int $leafId, ?float $net, string $tr = 'de_confirmat', array $extra = []): array => $extra + [
    'id' => $id, 'leaf_id' => $leafId, 'net_ron' => $net, 'moneda' => 'RON', 'suma_originala' => (float) $net, 'tratament' => $tr,
    'status_verificare' => 'confirmat', 'clasificare' => 'cost_direct_atribuibil', 'inclus' => false, 'anulat' => false, 'platit_ron' => 0.0, 'data' => '2026-05-01'];
$agg = $t3->aggregate([$line(1, 3, 100.0), $line(2, 4, 50.0, 'capitalizabil'), $line(3, 5, 25.0), $line(1, 3, 100.0), $line(9, 3, 7.0, 'cheltuiala', ['anulat' => true])]);
check('A = A1 + A2 = 150', near($agg[2]['total'], 150));
check('R = A + B = 175 (linia duplicata #1 numarata o singura data, anulata exclusa)', near($agg[1]['total'], 175) && $agg[1]['linii'] === 3);
check('frunza A1 = 100', near($agg[3]['total'], 100));
$aggPeriod = $t3->aggregate([$line(1, 3, 100.0), $line(2, 4, 50.0, 'de_confirmat', ['data' => '2025-01-01'])], ['2026-01-01', '2026-12-31']);
check('perioada de raportare filtreaza tranzactiile', near($aggPeriod[1]['total'], 100));

echo "\n" . ($failed === 0 ? "TOATE TESTELE AU TRECUT" : "ESUATE: $failed") . " ($passed ok)\n";
exit($failed === 0 ? 0 : 1);
