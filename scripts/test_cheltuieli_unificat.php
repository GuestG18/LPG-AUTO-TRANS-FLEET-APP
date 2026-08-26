<?php
declare(strict_types=1);

/**
 * Test harness pentru pagina unificata "Cheltuieli".
 *
 *   php scripts/test_cheltuieli_unificat.php
 *
 * Acopera: crearea schemei + seed tipuri, importul idempotent al datelor
 * legacy (Cheltuieli Birou / Administrative), CRUD cu alocare egala / manuala /
 * mixta, validarea totalurilor, filtrele, KPI-urile (fara dubla numarare) si
 * integrarea cu Cost operational / km.
 *
 * Randurile de test sunt marcate cu furnizor "TEST_CHX_*" si sterse la final.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/ExpenseModel.php';
require_once $root . '/htdocs/models/OperationalCostModel.php';

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
        echo "\033[31m  FAIL\033[0m  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    }
}

function approx(float $a, float $b, float $eps = 0.005): bool
{
    return abs($a - $b) < $eps;
}

function cleanup(PDO $db): void
{
    $db->exec("DELETE FROM cheltuieli WHERE furnizor LIKE 'TEST_CHX_%'");
    try {
        $db->exec("DELETE FROM office_expenses WHERE supplier LIKE 'TEST_CHX_%'");
    } catch (Throwable) {
    }
}

echo "\n== 1. Schema + seed ==\n";

$model = new ExpenseModel($db);

$tables = ['cheltuieli', 'cheltuieli_tipuri', 'cheltuieli_alocari', 'cheltuieli_documente'];
foreach ($tables as $table) {
    $exists = true;
    try {
        $db->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
    } catch (Throwable) {
        $exists = false;
    }
    check("tabela $table exista", $exists);
}

$opTypes = $model->getTypes('operationala');
check('tipuri operationale seed-uite (>= 10)', count($opTypes) >= 10, 'gasite: ' . count($opTypes));

$adminTypes = $model->getTypes('administrativa');
check('tipuri administrative importate din legacy (> 0)', count($adminTypes) > 0, 'gasite: ' . count($adminTypes));

$salarii = $db->query("SELECT status FROM cheltuieli_tipuri WHERE slug = 'birou-salarii-birou'")->fetchColumn();
check('categoria automata Salarii birou este inactiva', $salarii === 'inactiv', 'status: ' . var_export($salarii, true));

echo "\n== 2. Import legacy idempotent ==\n";

cleanup($db);

$legacySupported = true;
try {
    $categoryId = (int) $db->query('SELECT id FROM office_expense_categories WHERE is_automatic = 0 ORDER BY id LIMIT 1')->fetchColumn();
} catch (Throwable) {
    $legacySupported = false;
    $categoryId = 0;
}

if ($legacySupported && $categoryId > 0) {
    $db->prepare("
        INSERT INTO office_expenses (category_id, expense_date, description, supplier, amount_net, vat_amount, amount_total,
                                     payment_method, invoice_number, notes, added_by, updated_by, created_at, updated_at)
        VALUES (:cat, '2026-08-05', 'Chirie test', 'TEST_CHX_LEGACY', 1000.00, 190.00, 1190.00,
                'transfer_bancar', 'TST-1', 'nota test', NULL, NULL, NOW(), NOW())
    ")->execute([':cat' => $categoryId]);

    // Reinstantierea modelului declanseaza importul legacy.
    $model = new ExpenseModel($db);

    $migrated = $db->query("SELECT * FROM cheltuieli WHERE furnizor = 'TEST_CHX_LEGACY'")->fetchAll();
    check('randul legacy a fost migrat', count($migrated) === 1, 'gasite: ' . count($migrated));

    if (count($migrated) === 1) {
        $row = $migrated[0];
        check('valoarea migrata = suma neta', approx((float) $row['valoare'], 1000.00));
        check('categoria migrata = administrativa', $row['categorie'] === 'administrativa');
        check('alocarea migrata = companie', $row['alocare_tip'] === 'companie');
        check('observatiile pastreaza detaliile legacy', str_contains((string) $row['observatii'], 'Migrat din Cheltuieli Birou'));

        $allocs = $model->getAllocationsForRows($migrated);
        $rowAllocs = $allocs[(int) $row['id']] ?? [];
        check('alocarea companie acopera 100% din valoare', count($rowAllocs) === 1 && approx((float) $rowAllocs[0]['suma'], 1000.00));
    }

    // Rulare repetata -> fara duplicate.
    $model = new ExpenseModel($db);
    $countAfter = (int) $db->query("SELECT COUNT(*) FROM cheltuieli WHERE furnizor = 'TEST_CHX_LEGACY'")->fetchColumn();
    check('importul repetat nu creeaza duplicate', $countAfter === 1, 'gasite: ' . $countAfter);
} else {
    echo "  SKIP  tabelele legacy nu exista pe acest mediu\n";
}

echo "\n== 3. CRUD + alocari ==\n";

$vehicles = $model->getVehicles();
$drivers = $model->getDrivers();
$opTypeId = (int) $opTypes[0]['id'];
$adminTypeId = count($adminTypes) > 0 ? (int) $adminTypes[0]['id'] : $opTypeId;

if (count($vehicles) < 3 || count($drivers) < 1) {
    echo "  SKIP  sunt necesare minim 3 vehicule si 1 sofer activi\n";
} else {
    $v1 = (int) $vehicles[0]['id'];
    $v2 = (int) $vehicles[1]['id'];
    $v3 = (int) $vehicles[2]['id'];
    $d1 = (int) $drivers[0]['id'];

    // 3a. Egal: 2.500 lei / 3 vehicule -> 833,33 + 833,33 + 833,34.
    $egalId = $model->createExpense(
        [
            'categorie' => 'operationala', 'tip_id' => $opTypeId, 'data_cheltuiala' => '2026-08-10',
            'furnizor' => 'TEST_CHX_EGAL', 'valoare' => 2500.00, 'numar_document' => 'TST-EGAL',
            'observatii' => '', 'beneficiar_id' => 0, 'alocare_tip' => 'vehicul', 'distribuire' => 'egal',
        ],
        [
            ['tip_alocare' => 'vehicul', 'vehicul_id' => $v1, 'eticheta' => 'V1', 'suma' => 833.33],
            ['tip_alocare' => 'vehicul', 'vehicul_id' => $v2, 'eticheta' => 'V2', 'suma' => 833.33],
            ['tip_alocare' => 'vehicul', 'vehicul_id' => $v3, 'eticheta' => 'V3', 'suma' => 833.34],
        ],
        null,
        null
    );
    $allocs = $model->getAllocationsForRows([['id' => $egalId]])[$egalId] ?? [];
    $sum = array_sum(array_map(static fn(array $a): float => (float) $a['suma'], $allocs));
    check('egal: 3 alocari salvate', count($allocs) === 3);
    check('egal: totalul alocat este exact 2500', approx($sum, 2500.00), 'suma: ' . $sum);

    // 3b. Manual pe soferi.
    $manualId = $model->createExpense(
        [
            'categorie' => 'operationala', 'tip_id' => $opTypeId, 'data_cheltuiala' => '2026-08-11',
            'furnizor' => 'TEST_CHX_MANUAL', 'valoare' => 500.00, 'numar_document' => '',
            'observatii' => '', 'beneficiar_id' => 0, 'alocare_tip' => 'sofer', 'distribuire' => 'manual',
        ],
        [
            ['tip_alocare' => 'sofer', 'sofer_id' => $d1, 'eticheta' => 'D1', 'suma' => 500.00],
        ],
        null,
        null
    );
    $found = $model->findExpense($manualId);
    check('manual: cheltuiala pe sofer salvata', $found !== null && $found['alocare_tip'] === 'sofer');

    // 3c. Mixt: vehicul 1200 + vehicul 800 + sofer 300 + companie 200 = 2500.
    $beneficiaries = $model->getBeneficiaries();
    $benefId = count($beneficiaries) > 0 ? (int) $beneficiaries[0]['id'] : 0;
    $mixtId = $model->createExpense(
        [
            'categorie' => 'operationala', 'tip_id' => $opTypeId, 'data_cheltuiala' => '2026-08-12',
            'furnizor' => 'TEST_CHX_MIXT', 'valoare' => 2500.00, 'numar_document' => 'TST-MIXT',
            'observatii' => '', 'beneficiar_id' => $benefId, 'alocare_tip' => 'mixt', 'distribuire' => 'manual',
        ],
        [
            ['tip_alocare' => 'vehicul', 'vehicul_id' => $v1, 'eticheta' => 'V1', 'suma' => 1200.00],
            ['tip_alocare' => 'vehicul', 'vehicul_id' => $v2, 'eticheta' => 'V2', 'suma' => 800.00],
            ['tip_alocare' => 'sofer', 'sofer_id' => $d1, 'eticheta' => 'D1', 'suma' => 300.00],
            ['tip_alocare' => 'companie', 'eticheta' => 'Companie', 'suma' => 200.00],
        ],
        null,
        null
    );
    $mixtAllocs = $model->getAllocationsForRows([['id' => $mixtId]])[$mixtId] ?? [];
    check('mixt: 4 alocari salvate pe o singura cheltuiala', count($mixtAllocs) === 4);
    check('mixt: beneficiarul este optional si atasat', $benefId === 0 || (int) ($model->findExpense($mixtId)['beneficiar_id'] ?? 0) === $benefId);

    // 3d. O factura mixta ramane O singura inregistrare.
    $countMixt = (int) $db->query("SELECT COUNT(*) FROM cheltuieli WHERE furnizor = 'TEST_CHX_MIXT'")->fetchColumn();
    check('mixt: factura nu este duplicata', $countMixt === 1);

    echo "\n== 4. Filtre + KPI ==\n";

    $filters = ['date_start' => '2026-08-10', 'date_end' => '2026-08-12', 'furnizor' => 'TEST_CHX_'];

    $summary = $model->getSummary($filters);
    check('KPI total = 2500 + 500 + 2500', approx((float) $summary['total'], 5500.00), 'total: ' . $summary['total']);
    check('KPI operationale = 5500', approx((float) $summary['operationala'], 5500.00));

    // Distributia pe alocare NU dubleaza cheltuiala multi-vehicul.
    check('alocare vehicule = 2500 (egal) + 1200 + 800 (mixt)', approx((float) $summary['alocare']['vehicul'], 4500.00), 'v: ' . $summary['alocare']['vehicul']);
    check('alocare soferi = 500+300', approx((float) $summary['alocare']['sofer'], 800.00));
    check('alocare companie = 200', approx((float) $summary['alocare']['companie'], 200.00));
    check('suma distributiei = totalul cheltuielilor', approx((float) $summary['alocare_total'], 5500.00));

    // Filtru vehicul: v3 apare doar in cheltuiala egala.
    $resV3 = $model->getPaginatedExpenses(array_merge($filters, ['vehicul_id' => $v3]), 1, 50);
    check('filtrul pe vehicul intoarce doar cheltuielile alocate lui', (int) $resV3['total_rows'] === 1, 'rows: ' . $resV3['total_rows']);

    // Filtru alocare=sofer: manuala + mixta.
    $resSofer = $model->getPaginatedExpenses(array_merge($filters, ['alocare' => 'sofer']), 1, 50);
    check('filtrul alocare=sofer gaseste cheltuielile cu alocari pe sofer', (int) $resSofer['total_rows'] === 2, 'rows: ' . $resSofer['total_rows']);

    // Filtru categorie.
    $resAdmin = $model->getPaginatedExpenses(array_merge($filters, ['categorie' => 'administrativa']), 1, 50);
    check('filtrul categorie=administrativa exclude testele operationale', (int) $resAdmin['total_rows'] === 0, 'rows: ' . $resAdmin['total_rows']);

    echo "\n== 5. Update + delete ==\n";

    $updated = $model->updateExpense(
        $egalId,
        [
            'categorie' => 'operationala', 'tip_id' => $opTypeId, 'data_cheltuiala' => '2026-08-10',
            'furnizor' => 'TEST_CHX_EGAL', 'valoare' => 1000.00, 'numar_document' => 'TST-EGAL',
            'observatii' => '', 'beneficiar_id' => 0, 'alocare_tip' => 'vehicul', 'distribuire' => 'egal',
        ],
        [
            ['tip_alocare' => 'vehicul', 'vehicul_id' => $v1, 'eticheta' => 'V1', 'suma' => 500.00],
            ['tip_alocare' => 'vehicul', 'vehicul_id' => $v2, 'eticheta' => 'V2', 'suma' => 500.00],
        ],
        null,
        null
    );
    $newAllocs = $model->getAllocationsForRows([['id' => $egalId]])[$egalId] ?? [];
    check('update: alocarile sunt inlocuite (3 -> 2)', $updated && count($newAllocs) === 2);

    $model->deleteExpense($manualId);
    $orphanCount = (int) $db->query('SELECT COUNT(*) FROM cheltuieli_alocari WHERE cheltuiala_id = ' . $manualId)->fetchColumn();
    check('delete: alocarile sunt sterse in cascada', $model->findExpense($manualId) === null && $orphanCount === 0);

    echo "\n== 6. Integrare Cost operational / km ==\n";

    $adminId = $model->createExpense(
        [
            'categorie' => 'administrativa', 'tip_id' => $adminTypeId, 'data_cheltuiala' => '2026-07-15',
            'furnizor' => 'TEST_CHX_MGMT', 'valoare' => 777.00, 'numar_document' => '',
            'observatii' => '', 'beneficiar_id' => 0, 'alocare_tip' => 'companie', 'distribuire' => 'egal',
        ],
        [['tip_alocare' => 'companie', 'eticheta' => 'Companie', 'suma' => 777.00]],
        null,
        null
    );

    $costModel = new OperationalCostModel($db);
    $baseline = $costModel->getManagementMonthlyCost('2026-07-01', '2026-07-31');
    check('cost/km citeste cheltuielile administrative din modulul unificat', approx((float) $baseline['admin_net'], (float) $db->query("SELECT COALESCE(SUM(valoare),0) FROM cheltuieli WHERE categorie='administrativa' AND data_cheltuiala BETWEEN '2026-07-01' AND '2026-07-31'")->fetchColumn()), 'admin_net: ' . $baseline['admin_net']);
    check('cost/km nu mai aduna separat tabelele legacy (fara dublare)', (float) $baseline['office_net'] === 0.0, 'office_net: ' . $baseline['office_net']);

    $model->deleteExpense($adminId);
    $model->deleteExpense($egalId);
    $model->deleteExpense($mixtId);
}

cleanup($db);

echo "\n=================================\n";
echo "PASS: $passed  FAIL: $failed\n";
exit($failed > 0 ? 1 : 0);
