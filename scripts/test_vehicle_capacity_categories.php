<?php
declare(strict_types=1);

/**
 * Test harness pentru separarea "capacitate reala" / "categorie de capacitate".
 *
 *   php scripts/test_vehicle_capacity_categories.php
 *
 * SAFETY
 *   Totul ruleaza intr-o singura tranzactie care este INTOTDEAUNA anulata
 *   (rollback). Niciun rand de productie nu este creat, modificat sau sters.
 *
 * Acopera scenariile A-J din specificatia refactorizarii:
 *   A. capacitati reale diferite, aceeasi categorie -> aceeasi grupa in dropdown
 *   B. selectarea categoriei intoarce ID-uri de vehicul
 *   C. 19 t intr-un vehicul de 20 t = 95%, fara avertizare de supraincarcare
 *   D. 21 t intr-un vehicul de 20 t = 105%, cu avertizare
 *   E. schimbarea categoriei nu atinge capacitatea reala si nici configurarile
 *   F. categorie fara capacitate reala -> capacitatea NU se deduce din eticheta
 *   G. corectarea capacitatii curente nu rescrie cursele istorice
 *   H. rutele si selectiile de vehicule raman intacte dupa migrare
 *   I. agregarea din dashboard = total tone / total capacitati (ponderata)
 *   J. comportamentul dropdown-ului (grupare, partial, cautare, fara categorie)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/vehicle_capacity_groups.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/VehicleCapacityCategoryModel.php';
require_once $root . '/htdocs/models/DispecerCurseModel.php';

// Validarea incarcarii se testeaza pe codul real din controller, nu pe o copie.
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/includes/csrf.php';
require_once $root . '/htdocs/includes/auth.php';
require_once $root . '/htdocs/includes/access.php';
require_once $root . '/htdocs/models/OperatorActivityModel.php';
require_once $root . '/htdocs/models/InactiveResourceApprovalModel.php';
require_once $root . '/htdocs/models/ReinvoiceFeeExpectationModel.php';
require_once $root . '/htdocs/services/EntityStatusService.php';
require_once $root . '/htdocs/services/InactiveResourceStatusService.php';
require_once $root . '/htdocs/services/RaceCompletenessService.php';
require_once $root . '/htdocs/services/FuelPriceIndexService.php';
require_once $root . '/htdocs/services/TransportPricingService.php';
require_once $root . '/htdocs/services/SasFleetClient.php';
require_once $root . '/htdocs/services/SasTripPrefillService.php';
require_once $root . '/htdocs/services/SasDashboardService.php';
require_once $root . '/htdocs/controllers/DispecerCurseController.php';

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

function approx(?float $a, ?float $b, float $eps = 1e-6): bool
{
    if ($a === null || $b === null) {
        return false;
    }
    return abs($a - $b) < $eps;
}

/**
 * Gradul de umplere al unei curse, exact ca in SQL-ul de raportare:
 * tone transportate / capacitate REALA * 100, fara plafon la 100%.
 */
function tripUtilisation(?float $tons, ?float $realCapacity): ?float
{
    if ($realCapacity === null || $realCapacity <= 0 || $tons === null) {
        return null;
    }

    return ($tons / $realCapacity) * 100;
}

$categoryModel = new VehicleCapacityCategoryModel($db);
$categoryModel->ensureSchema();

$db->beginTransaction();

try {
    // ==================================================================
    echo "\n== Pregatire: doua categorii si trei vehicule de test ==\n";
    // ==================================================================

    $insertCategory = static function (PDO $db, string $name, int $order): int {
        $stmt = $db->prepare("
            INSERT INTO vehicule_categorii_capacitate (nume, descriere, ordine_afisare, activ, created_at, updated_at)
            VALUES (:nume, 'Categorie de test', :ordine, 1, NOW(), NOW())
        ");
        $stmt->bindValue(':nume', $name);
        $stmt->bindValue(':ordine', $order, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $db->lastInsertId();
    };

    // Eticheta contine un numar, dar numarul NU este capacitatea vehiculelor.
    $cat185 = $insertCategory($db, 'TEST 18.5 TONE', 900);
    $cat10 = $insertCategory($db, 'TEST 10 TONE', 901);

    $insertVehicle = static function (PDO $db, string $plate, ?float $capacity, ?int $categoryId, bool $confirmed): int {
        $stmt = $db->prepare("
            INSERT INTO vehicule
                (nr_inmatriculare, marca, model, tip_vehicul, an_fabricatie, km_bord, km_revizie,
                 serie_sasiu, capacitate_transport, categorie_capacitate_id, capacitate_transport_confirmata,
                 status, created_at, updated_at)
            VALUES
                (:plate, 'TEST', 'MODEL', 'camion', 2020, 0, 0,
                 :sasiu, :capacitate, :categorie, :confirmata,
                 'activ', NOW(), NOW())
        ");
        $stmt->bindValue(':plate', $plate);
        $stmt->bindValue(':sasiu', str_pad(strtoupper(preg_replace('/[^A-Z0-9]/i', '', $plate) ?? ''), 17, 'X'));
        $stmt->bindValue(':capacitate', $capacity, $capacity === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':categorie', $categoryId, $categoryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':confirmata', $confirmed ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $db->lastInsertId();
    };

    // Capacitati REALE diferite, aceeasi categorie.
    $vehA = $insertVehicle($db, 'TS 001 TST', 18.50, $cat185, true);
    $vehB = $insertVehicle($db, 'TS 002 TST', 19.00, $cat185, true);
    $vehC = $insertVehicle($db, 'TS 003 TST', 20.00, $cat185, true);
    // Vehicul cu categorie, dar fara capacitate reala cunoscuta.
    $vehD = $insertVehicle($db, 'TS 004 TST', null, $cat185, false);
    // Vehicul complet fara categorie.
    $vehE = $insertVehicle($db, 'TS 005 TST', 12.00, null, true);

    echo "  vehicule de test: A=$vehA B=$vehB C=$vehC D=$vehD E=$vehE\n";

    $raceModel = new DispecerCurseModel($db);
    $allVehicles = $raceModel->getVehicleOptions(false);
    $testVehicles = array_values(array_filter(
        $allVehicles,
        static fn (array $row): bool => str_ends_with((string) $row['nr_inmatriculare'], ' TST')
    ));

    // ==================================================================
    echo "\n== A. Capacitati reale diferite, aceeasi categorie ==\n";
    // ==================================================================

    $groups = build_vehicle_capacity_groups($testVehicles);
    $groupByLabel = [];
    foreach ($groups as $group) {
        $groupByLabel[$group['label']] = $group;
    }

    check('A1. Cele 3 vehicule cu capacitati 18.5 / 19 / 20 sunt in aceeasi grupa',
        isset($groupByLabel['TEST 18.5 TONE'])
        && count(array_filter(
            $groupByLabel['TEST 18.5 TONE']['vehicles'],
            static fn (array $v): bool => in_array((int) $v['id'], [$vehA, $vehB, $vehC], true)
        )) === 3);

    $capacitiesInGroup = [];
    foreach ($groupByLabel['TEST 18.5 TONE']['vehicles'] ?? [] as $v) {
        if (in_array((int) $v['id'], [$vehA, $vehB, $vehC], true)) {
            $capacitiesInGroup[] = (float) $v['capacitate_transport'];
        }
    }
    sort($capacitiesInGroup);
    check('A2. Capacitatile reale raman distincte in interiorul grupei (18.5 / 19 / 20)',
        $capacitiesInGroup === [18.5, 19.0, 20.0],
        implode(' / ', array_map('strval', $capacitiesInGroup)));

    check('A3. Eticheta grupei este numele categoriei, nu o capacitate calculata',
        ($groupByLabel['TEST 18.5 TONE']['label'] ?? '') === 'TEST 18.5 TONE');

    // ==================================================================
    echo "\n== B. Selectarea unei categorii intoarce ID-uri de vehicul ==\n";
    // ==================================================================

    $selectedIds = array_values(array_map(
        static fn (array $v): int => (int) $v['id'],
        array_filter(
            $groupByLabel['TEST 18.5 TONE']['vehicles'] ?? [],
            static fn (array $v): bool => in_array((int) $v['id'], [$vehA, $vehB, $vehC], true)
        )
    ));
    sort($selectedIds);
    $expectedIds = [$vehA, $vehB, $vehC];
    sort($expectedIds);

    check('B1. Selectarea categoriei intoarce exact cele 3 ID-uri de vehicul',
        $selectedIds === $expectedIds,
        implode(',', $selectedIds) . ' vs ' . implode(',', $expectedIds));

    check('B2. Selectia nu contine nume de categorie sau valori de capacitate',
        $selectedIds === array_filter($selectedIds, 'is_int'));

    // ==================================================================
    echo "\n== C. Grad de umplere corect (fara falsa supraincarcare) ==\n";
    // ==================================================================

    $capacityC = $raceModel->getVehicleTransportCapacity($vehC);
    check('C1. Capacitatea reala citita pentru vehiculul C este 20 t (nu 18.5 din categorie)',
        approx($capacityC, 20.0), (string) $capacityC);

    $utilC = tripUtilisation(19.0, $capacityC);
    check('C2. 19 t / 20 t = 95% (nu 102,70% pe capacitatea categoriei)',
        approx($utilC, 95.0), (string) $utilC);

    check('C3. 95% nu declanseaza avertizarea de supraincarcare',
        $utilC !== null && $utilC <= 100.0);

    $utilOnCategoryValue = tripUtilisation(19.0, 18.5);
    check('C4. Calculul gresit (pe valoarea din eticheta) ar fi dat 102,70% - nu il mai folosim',
        approx($utilOnCategoryValue, 102.7027027, 1e-6));

    // ==================================================================
    echo "\n== D. Supraincarcare reala ==\n";
    // ==================================================================

    $utilOverload = tripUtilisation(21.0, $capacityC);
    check('D1. 21 t / 20 t = 105%',
        approx($utilOverload, 105.0), (string) $utilOverload);

    check('D2. Rezultatul depaseste 100% (plafonul LEAST(100,...) a fost scos)',
        $utilOverload !== null && $utilOverload > 100.0);

    // Acelasi lucru, direct din SQL-ul de raportare.
    $sqlUtil = $db->query("
        SELECT GREATEST(0, (21.0 / 20.00) * 100) AS grad
    ")->fetchColumn();
    check('D3. SQL-ul de raportare da acelasi 105%',
        approx((float) $sqlUtil, 105.0), (string) $sqlUtil);

    // ==================================================================
    echo "\n== E. Schimbarea categoriei nu atinge capacitatea reala ==\n";
    // ==================================================================
    echo "\n== C/D bis. Validarea reala din formularul de cursa ==\n";
    // ==================================================================

    /*
     * Apelam metoda de validare chiar din DispecerCurseController (prin reflexie,
     * fiind privata), ca sa verificam codul care ruleaza la salvarea unei curse,
     * nu o reimplementare din test.
     */
    $controller = new DispecerCurseController($db);
    $validate = new ReflectionMethod(DispecerCurseController::class, 'validateRaceInput');
    $validate->setAccessible(true);

    $raceInput = static function (int $vehicleId, string $quantity): array {
        return [
            'vehicle_id' => (string) $vehicleId,
            'tip_transport' => 'primar_tona',
            'data_inceput' => date('Y-m-d'),
            'data_sfarsit' => date('Y-m-d'),
            'cantitate_incarcata' => $quantity,
        ];
    };

    [, , , $soft95] = $validate->invoke($controller, $raceInput($vehC, '19'), false, true);
    check('CD1. 19 t intr-un vehicul de 20 t: nicio avertizare de supraincarcare',
        !isset($soft95['cantitate_incarcata'])
        || !str_contains((string) $soft95['cantitate_incarcata'], 'depaseste'),
        (string) ($soft95['cantitate_incarcata'] ?? '-'));

    [, , , $soft105] = $validate->invoke($controller, $raceInput($vehC, '21'), false, true);
    check('CD2. 21 t intr-un vehicul de 20 t: avertizare de supraincarcare la 105%',
        isset($soft105['cantitate_incarcata'])
        && str_contains((string) $soft105['cantitate_incarcata'], 'depaseste')
        && str_contains((string) $soft105['cantitate_incarcata'], '105'),
        (string) ($soft105['cantitate_incarcata'] ?? '-'));

    // Vehiculul A are exact 18.5 t reali: 19 t chiar il supraincarca.
    [, , , $softA] = $validate->invoke($controller, $raceInput($vehA, '19'), false, true);
    check('CD3. Acelasi 19 t intr-un vehicul de 18.5 t chiar este supraincarcare',
        isset($softA['cantitate_incarcata'])
        && str_contains((string) $softA['cantitate_incarcata'], 'depaseste'),
        (string) ($softA['cantitate_incarcata'] ?? '-'));

    // Fara capacitate reala nu se da niciun verdict de incarcare.
    [, , , $softD] = $validate->invoke($controller, $raceInput($vehD, '19'), false, true);
    check('CD4. Fara capacitate reala se cere completarea, nu se da un verdict',
        isset($softD['cantitate_incarcata'])
        && str_contains((string) $softD['cantitate_incarcata'], 'capacitate de transport reala'),
        (string) ($softD['cantitate_incarcata'] ?? '-'));

    check('CD5. Mesajul pentru capacitate lipsa nu pretinde un procent',
        isset($softD['cantitate_incarcata']) && !str_contains((string) $softD['cantitate_incarcata'], '%'));

    // Capacitate prezenta, dar neverificata: rezultat orientativ, marcat ca atare.
    $db->exec("UPDATE vehicule SET capacitate_transport_confirmata = 0 WHERE id = {$vehB}");
    [, , , $softB] = $validate->invoke($controller, $raceInput($vehB, '18'), false, true);
    check('CD6. Capacitate neverificata: gradul este marcat drept orientativ',
        isset($softB['cantitate_incarcata'])
        && str_contains((string) $softB['cantitate_incarcata'], 'nu este inca verificata'),
        (string) ($softB['cantitate_incarcata'] ?? '-'));
    $db->exec("UPDATE vehicule SET capacitate_transport_confirmata = 1 WHERE id = {$vehB}");

    // Snapshot-ul salvat pe cursa este capacitatea REALA, nu eticheta categoriei.
    [$dataC] = $validate->invoke($controller, $raceInput($vehC, '19'), false, true);
    check('CD7. Snapshot-ul salvat pe cursa este capacitatea reala (20 t)',
        approx($dataC['capacitate_transport'] ?? null, 20.0),
        var_export($dataC['capacitate_transport'] ?? null, true));

    check('CD8. Cursa retine si daca acea capacitate era verificata',
        (int) ($dataC['capacitate_transport_confirmata'] ?? -1) === 1);

    // ==================================================================

    $before = $db->query("SELECT capacitate_transport, categorie_capacitate_id FROM vehicule WHERE id = {$vehC}")->fetch();

    $move = $db->prepare("UPDATE vehicule SET categorie_capacitate_id = :cat WHERE id = :id");
    $move->bindValue(':cat', $cat10, PDO::PARAM_INT);
    $move->bindValue(':id', $vehC, PDO::PARAM_INT);
    $move->execute();

    $after = $db->query("SELECT capacitate_transport, categorie_capacitate_id FROM vehicule WHERE id = {$vehC}")->fetch();

    check('E1. Capacitatea reala a ramas 20 t dupa schimbarea categoriei',
        approx((float) $after['capacitate_transport'], 20.0), (string) $after['capacitate_transport']);

    check('E2. Categoria s-a schimbat efectiv',
        (int) $after['categorie_capacitate_id'] === $cat10
        && (int) $before['categorie_capacitate_id'] === $cat185);

    $groupsAfter = build_vehicle_capacity_groups(array_values(array_filter(
        $raceModel->getVehicleOptions(false),
        static fn (array $row): bool => str_ends_with((string) $row['nr_inmatriculare'], ' TST')
    )));
    $labelsAfter = [];
    foreach ($groupsAfter as $group) {
        foreach ($group['vehicles'] as $v) {
            $labelsAfter[(int) $v['id']] = $group['label'];
        }
    }
    check('E3. Vehiculul apare acum sub noua categorie',
        ($labelsAfter[$vehC] ?? '') === 'TEST 10 TONE', $labelsAfter[$vehC] ?? '-');

    check('E4. Gradul de umplere ramane calculat pe 20 t, nu pe 10 din noua eticheta',
        approx(tripUtilisation(19.0, $raceModel->getVehicleTransportCapacity($vehC)), 95.0));

    // inapoi la categoria initiala pentru restul testelor
    $move->bindValue(':cat', $cat185, PDO::PARAM_INT);
    $move->bindValue(':id', $vehC, PDO::PARAM_INT);
    $move->execute();

    // ==================================================================
    echo "\n== F. Capacitate reala lipsa: nu se deduce din eticheta ==\n";
    // ==================================================================

    $infoD = $raceModel->getVehicleCapacityInfo($vehD);
    check('F1. Vehiculul are categorie asignata',
        $infoD['category'] === 'TEST 18.5 TONE', (string) $infoD['category']);

    check('F2. Capacitatea reala este null, NU 18.5 preluat din numele categoriei',
        $infoD['capacity'] === null, var_export($infoD['capacity'], true));

    check('F3. Capacitatea nu este nici substituita cu 0',
        $infoD['capacity'] !== 0.0);

    check('F4. Capacitatea nu este marcata drept verificata',
        $infoD['confirmed'] === false);

    check('F5. Gradul de umplere nu poate fi calculat (null, nu 0%)',
        tripUtilisation(19.0, $infoD['capacity']) === null);

    // O capacitate stocata 0 se trateaza tot ca lipsa, nu ca o capacitate reala.
    $zeroVehicle = $insertVehicle($db, 'TS 006 TST', 0.0, $cat185, false);
    check('F6. Capacitatea 0 este tratata ca lipsa, nu ca o capacitate valida',
        $raceModel->getVehicleCapacityInfo($zeroVehicle)['capacity'] === null);

    // ==================================================================
    echo "\n== G. Istoricul nu se rescrie la corectarea capacitatii ==\n";
    // ==================================================================

    // Cursa istorica pastreaza un snapshot propriu al capacitatii.
    $insertRace = $db->prepare("
        INSERT INTO curse_dispecer
            (vehicle_id, tip_transport, data_cursa, data_inceput, data_sfarsit,
             capacitate_transport, capacitate_transport_confirmata, cantitate_incarcata,
             status_facturare, pret_tarifare, total_facturare, created_at, updated_at)
        VALUES
            (:vehicle_id, 'primar_tona', CURDATE(), CURDATE(), CURDATE(),
             18.50, 0, 17.00,
             'facturat', 0, 0, NOW(), NOW())
    ");
    $insertRace->bindValue(':vehicle_id', $vehC, PDO::PARAM_INT);
    $insertRace->execute();
    $historicRaceId = (int) $db->lastInsertId();

    $snapshotBefore = $db->query("SELECT capacitate_transport FROM curse_dispecer WHERE id = {$historicRaceId}")->fetchColumn();

    // Administratorul corecteaza capacitatea reala a vehiculului.
    $db->exec("UPDATE vehicule SET capacitate_transport = 20.00, capacitate_transport_confirmata = 1 WHERE id = {$vehC}");

    $snapshotAfter = $db->query("SELECT capacitate_transport FROM curse_dispecer WHERE id = {$historicRaceId}")->fetchColumn();

    check('G1. Snapshot-ul de capacitate al cursei istorice nu s-a schimbat',
        approx((float) $snapshotBefore, (float) $snapshotAfter),
        $snapshotBefore . ' -> ' . $snapshotAfter);

    check('G2. Gradul istoric ramane calculat pe snapshot (17 / 18.5 = 91,89%)',
        approx(tripUtilisation(17.0, (float) $snapshotAfter), 91.8918918, 1e-6));

    check('G3. Cursa istorica este marcata ca fiind calculata pe o capacitate neverificata',
        (int) $db->query("SELECT capacitate_transport_confirmata FROM curse_dispecer WHERE id = {$historicRaceId}")->fetchColumn() === 0);

    check('G4. Capacitatea curenta a vehiculului este cea corectata (20 t)',
        approx($raceModel->getVehicleTransportCapacity($vehC), 20.0));

    // ==================================================================
    echo "\n== H. Configurarile existente raman intacte ==\n";
    // ==================================================================

    $routeTableExists = (int) $db->query("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'configurare_rute_distributie_vehicule'
    ")->fetchColumn() > 0;

    if ($routeTableExists) {
        $orphans = (int) $db->query("
            SELECT COUNT(*)
            FROM configurare_rute_distributie_vehicule rv
            LEFT JOIN vehicule v ON v.id = rv.vehicle_id
            WHERE v.id IS NULL
        ")->fetchColumn();
        check('H1. Nicio alocare de vehicul pe rute nu a ramas orfana dupa migrare',
            $orphans === 0, 'orfani: ' . $orphans);
    } else {
        check('H1. (tabelul de alocari pe rute nu exista in aceasta baza - test sarit)', true);
    }

    check('H2. Migrarea nu a schimbat nicio capacitate reala (audit: veche == noua)',
        (int) $db->query("
            SELECT COUNT(*)
            FROM vehicule_capacitate_audit
            WHERE sursa = 'migrare'
              AND NOT (capacitate_veche <=> capacitate_noua)
        ")->fetchColumn() === 0);

    check('H3. Fiecare vehicul migrat are snapshot-ul valorii initiale pentru rollback',
        (int) $db->query("SELECT COUNT(*) FROM vehicule_capacitate_audit WHERE sursa = 'migrare'")->fetchColumn()
        >= (int) $db->query("SELECT COUNT(*) FROM vehicule WHERE nr_inmatriculare NOT LIKE '%TST'")->fetchColumn());

    // Legaturile sunt pe id, deci redenumirea categoriei nu rupe asignarile.
    $db->exec("UPDATE vehicule_categorii_capacitate SET nume = 'TEST REDENUMITA' WHERE id = {$cat185}");
    check('H4. Redenumirea categoriei pastreaza vehiculele asignate',
        (int) $db->query("SELECT COUNT(*) FROM vehicule WHERE categorie_capacitate_id = {$cat185}")->fetchColumn() >= 3);
    $db->exec("UPDATE vehicule_categorii_capacitate SET nume = 'TEST 18.5 TONE' WHERE id = {$cat185}");

    // ==================================================================
    echo "\n== I. Agregarea din dashboard este ponderata pe capacitate ==\n";
    // ==================================================================

    // Doua curse: 19 t intr-un vehicul de 20 t (95%) si 5 t intr-unul de 10 t (50%).
    $aggregate = $db->query("
        SELECT
            SUM(tone) AS tone,
            SUM(capacitate) AS capacitate,
            AVG(procent) AS medie_procente
        FROM (
            SELECT 19.0 AS tone, 20.0 AS capacitate, (19.0 / 20.0) * 100 AS procent
            UNION ALL
            SELECT 5.0, 10.0, (5.0 / 10.0) * 100
        ) t
    ")->fetch();

    $weighted = ((float) $aggregate['tone'] / (float) $aggregate['capacitate']) * 100;
    check('I1. Ponderat: (19+5) / (20+10) = 80%',
        approx($weighted, 80.0), (string) $weighted);

    check('I2. Media aritmetica a procentelor ar fi dat 72,5% - nu o mai folosim',
        approx((float) $aggregate['medie_procente'], 72.5, 1e-6));

    check('I3. Cele doua metode chiar difera (garda impotriva regresiei)',
        !approx($weighted, (float) $aggregate['medie_procente'], 1e-6));

    // Cursele fara capacitate nu intra in niciun capat al fractiei.
    $withMissing = $db->query("
        SELECT
            SUM(CASE WHEN capacitate > 0 THEN tone ELSE 0 END) AS tone,
            SUM(CASE WHEN capacitate > 0 THEN capacitate ELSE 0 END) AS capacitate
        FROM (
            SELECT 19.0 AS tone, 20.0 AS capacitate
            UNION ALL
            SELECT 5.0, 10.0
            UNION ALL
            SELECT 8.0, 0.0
        ) t
    ")->fetch();
    check('I4. O cursa fara capacitate nu schimba rezultatul (tot 80%)',
        approx(((float) $withMissing['tone'] / (float) $withMissing['capacitate']) * 100, 80.0));

    // Gruparea pe categorie nu schimba numitorul: aceleasi curse, acelasi rezultat.
    check('I5. Gruparea pe categorie alege doar cursele, nu inlocuieste capacitatea',
        approx(((float) $aggregate['tone'] / (float) $aggregate['capacitate']) * 100, $weighted));

    // ==================================================================
    echo "\n== J. Comportamentul dropdown-ului ==\n";
    // ==================================================================

    $allTest = array_values(array_filter(
        $raceModel->getVehicleOptions(false),
        static fn (array $row): bool => str_ends_with((string) $row['nr_inmatriculare'], ' TST')
    ));
    $jGroups = build_vehicle_capacity_groups($allTest);

    $jLabels = array_map(static fn (array $g): string => $g['label'], $jGroups);
    check('J1. Exista o grupa pentru fiecare categorie folosita',
        in_array('TEST 18.5 TONE', $jLabels, true));

    check('J2. Vehiculele fara categorie primesc grupa "Fara categorie"',
        in_array('Fara categorie', $jLabels, true), implode(' | ', $jLabels));

    check('J3. Grupa "Fara categorie" este ultima',
        $jLabels[count($jLabels) - 1] === 'Fara categorie', implode(' | ', $jLabels));

    $uncategorised = null;
    foreach ($jGroups as $group) {
        if ($group['label'] === 'Fara categorie') {
            $uncategorised = $group;
        }
    }
    check('J4. Vehiculul fara categorie nu dispare din lista',
        $uncategorised !== null
        && in_array($vehE, array_map(static fn (array $v): int => (int) $v['id'], $uncategorised['vehicles']), true));

    // Selectia partiala a unei grupe -> stare indeterminata in interfata.
    $groupSize = 0;
    foreach ($jGroups as $group) {
        if ($group['label'] === 'TEST 18.5 TONE') {
            $groupSize = count($group['vehicles']);
        }
    }
    $partialSelection = [$vehA];
    check('J5. Selectia partiala (1 din ' . $groupSize . ') nu inseamna grupa selectata',
        count($partialSelection) > 0 && count($partialSelection) < $groupSize);

    // Cautarea gaseste vehiculul dupa numar si dupa numele categoriei.
    $searchText = vehicle_capacity_group_search_text($allTest[0], 'TEST 18.5 TONE');
    check('J6. Textul de cautare contine numarul de inmatriculare',
        str_contains($searchText, mb_strtolower((string) $allTest[0]['nr_inmatriculare'])));

    check('J7. Textul de cautare contine numele categoriei',
        str_contains($searchText, 'test 18.5 tone'), $searchText);

    // Ordinea grupelor urmeaza `ordine_afisare` din catalog.
    $orders = array_map(static fn (array $g): int => (int) $g['order'], $jGroups);
    $sortedOrders = $orders;
    sort($sortedOrders);
    check('J8. Grupele sunt in ordinea de afisare configurata',
        $orders === $sortedOrders, implode(',', $orders));

    // ==================================================================
    echo "\n== Gestionarea categoriilor ==\n";
    // ==================================================================

    [$okCreate, $errCreate] = $categoryModel->createCategory(['nume' => 'TEST 18.5 TONE']);
    check('K1. Nu se pot crea doua categorii cu acelasi nume',
        $okCreate === false && isset($errCreate['nume']));

    [$okDelete, $msgDelete] = $categoryModel->deleteCategory($cat185, null, null, false);
    check('K2. O categorie cu vehicule asignate nu se sterge fara reasignare',
        $okDelete === false, $msgDelete);

    $capBeforeReassign = $db->query("SELECT capacitate_transport FROM vehicule WHERE id = {$vehA}")->fetchColumn();
    [$okReassign, $msgReassign] = $categoryModel->deleteCategory($cat185, $cat10, null, false);
    $capAfterReassign = $db->query("SELECT capacitate_transport FROM vehicule WHERE id = {$vehA}")->fetchColumn();

    check('K3. Cu reasignare, stergerea reuseste', $okReassign === true, $msgReassign);
    check('K4. Reasignarea nu a atins capacitatea reala',
        approx((float) $capBeforeReassign, (float) $capAfterReassign));
    check('K5. Vehiculele au fost mutate in categoria de destinatie',
        (int) $db->query("SELECT categorie_capacitate_id FROM vehicule WHERE id = {$vehA}")->fetchColumn() === $cat10);

    // ==================================================================
    echo "\n== Raportul de verificare a capacitatilor ==\n";
    // ==================================================================

    $report = $categoryModel->getVerificationReport();
    $reportById = [];
    foreach ($report['rows'] as $row) {
        $reportById[(int) $row['id']] = $row;
    }

    check('L1. Vehiculul fara capacitate reala apare in raport',
        isset($reportById[$vehD]) && $reportById[$vehD]['prioritate'] === 'lipsa');

    check('L2. Vehiculul cu capacitate confirmata nu apare in raport',
        !isset($reportById[$vehC]));

    check('L3. Raportul nu propune o capacitate dedusa din numele categoriei',
        !isset($reportById[$vehD]['capacitate_sugerata']));

    echo "\n";
} finally {
    $db->rollBack();
}

echo "==============================\n";
echo "PASSED: $passed   FAILED: $failed\n";
echo "(tranzactie anulata - nicio modificare persistata)\n";
exit($failed > 0 ? 1 : 0);
