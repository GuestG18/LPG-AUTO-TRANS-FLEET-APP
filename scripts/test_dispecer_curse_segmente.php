<?php
declare(strict_types=1);

/**
 * Test harness pentru reluarea cursei prin segmente.
 *
 *   php scripts/test_dispecer_curse_segmente.php
 *
 * CONTEXT
 *   Vechiul "Reia cursa (segment nou)" crea o CURSA NOUA: cursa oprita si reluata
 *   aparea de doua ori si era facturata de doua ori. Acum reluarea adauga un
 *   segment la aceeasi cursa. Testele verifica exact asta:
 *     - numarul de curse si valoarea facturata nu se schimba la reluare;
 *     - primul segment se materializeaza din cursa;
 *     - cursa se prelungeste pana la sfarsitul ultimului segment (diurna);
 *     - km-ii cursei se impart pe vehiculele segmentelor (bord / rapoarte);
 *     - stergerea segmentului readuce cursa la starea initiala.
 *
 * SAFETY
 *   Totul ruleaza intr-o tranzactie anulata la final (rollback). Niciun rand de
 *   productie nu ramane modificat.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/DispecerCurseModel.php';
require_once $root . '/htdocs/models/OperationalCostModel.php';
require_once $root . '/htdocs/models/DashboardAnaliticV2Model.php';
require_once $root . '/htdocs/includes/helpers.php';

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$model = new DispecerCurseModel($db);

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

$vehicles = $db->query("
    SELECT id FROM vehicule
    WHERE tip_vehicul NOT IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
    ORDER BY id ASC LIMIT 2
")->fetchAll(PDO::FETCH_COLUMN);
$drivers = $db->query('SELECT id FROM soferi ORDER BY id ASC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
$beneficiaryId = (int) $db->query('SELECT id FROM configurare_beneficiari_transport ORDER BY id ASC LIMIT 1')->fetchColumn();
$loadLocationId = (int) $db->query('SELECT id FROM configurare_locuri_incarcare ORDER BY id ASC LIMIT 1')->fetchColumn();

if (count($vehicles) < 2 || count($drivers) < 2 || $beneficiaryId <= 0 || $loadLocationId <= 0) {
    fwrite(STDERR, "Sunt necesare 2 vehicule, 2 soferi, un beneficiar si un loc de incarcare in baza.\n");
    exit(1);
}

$vehicleA = (int) $vehicles[0];
$vehicleB = (int) $vehicles[1];
$driverA = (int) $drivers[0];
$driverB = (int) $drivers[1];

$day = '2031-05-20';
$nextDay = '2031-05-21';
$rangeStart = '2031-05-01';
$rangeEnd = '2031-05-31';

// Orice CREATE TABLE / ALTER face commit implicit in MySQL, iar randurile de test
// ar ramane in baza dupa rollback. De aceea toate schemele "self-healing" atinse
// de test se creeaza inainte de a deschide tranzactia.
$model->getRaceSegments(1);
$model->getRaceById(1);
$model->updateRaceBillingStatus(0, 'in_curs_facturare', date('Y-m-d H:i:s'));
// updateRace verifica la prima apelare coloanele curse_dispecer (duplicate_key,
// soft delete, cost/km): aceleasi verificari ar face commit implicit in mijlocul
// testului. Rulam o actualizare pe id inexistent, care nu atinge niciun rand.
$model->updateRace(0, [
    'vehicle_id' => 0,
    'tip_transport' => '',
    'data_cursa' => null,
    'data_inceput' => null,
    'data_sfarsit' => null,
    'pret_tarifare' => 0,
    'total_facturare' => 0,
    'updated_at' => date('Y-m-d H:i:s'),
]);

// Dashboard-ul ajunge, pentru consumul de motorina, la FuelModel::ensureSchema()
// — iar CREATE/ALTER inseamna commit implicit. Il incalzim pe acelasi obiect pe
// care il folosim mai jos, impreuna cu schema de carburant.
require_once $root . '/htdocs/models/FuelModel.php';
(new FuelModel($db))->ensureSchema();
$dashboard = new DashboardAnaliticV2Model($db);
$dashboard->getData(['date_start' => '1990-01-01', 'date_end' => '1990-01-02']);
$dashboard->getFilterOptions();

// Plasa de siguranta: km-ii vehiculelor de test se citesc inainte si dupa rulare.
// Daca o modificare scapa din tranzactie, testul o semnaleaza in loc sa lase
// flota cu km falsi.
$readVehicleKm = static function (PDO $db, int $first, int $second): array {
    $stmt = $db->prepare('SELECT id, km_bord, km_revizie FROM vehicule WHERE id IN (:first, :second) ORDER BY id');
    $stmt->execute(['first' => $first, 'second' => $second]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
};
$vehicleKmBefore = $readVehicleKm($db, $vehicleA, $vehicleB);

$db->beginTransaction();

try {
    $now = date('Y-m-d H:i:s');
    $insert = $db->prepare("
        INSERT INTO curse_dispecer (
            vehicle_id, driver_id, tip_transport, data_cursa, data_inceput, data_sfarsit,
            ora_inceput, ora_sfarsit, durata_cursa_minute, loc_incarcare_id, beneficiar_id,
            cantitate_incarcata, km_cursa, pret_tarifare, total_facturare,
            observatii, created_at, updated_at
        ) VALUES (
            :vehicle_id, :driver_id, 'primar_tona', :data_cursa, :data_inceput, :data_sfarsit,
            :ora_inceput, :ora_sfarsit, 480, :loc_incarcare_id, :beneficiar_id,
            10.00, 1000, 70.00, 700.00,
            'TEST_SEGMENT_cursa', :created_at, :updated_at
        )
    ");
    $insert->execute([
        'vehicle_id' => $vehicleA,
        'driver_id' => $driverA,
        'data_cursa' => $day,
        'data_inceput' => $day,
        'data_sfarsit' => $day,
        'ora_inceput' => '06:00:00',
        'ora_sfarsit' => '14:00:00',
        'loc_incarcare_id' => $loadLocationId,
        'beneficiar_id' => $beneficiaryId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $raceId = (int) $db->lastInsertId();

    $countRaces = static function (PDO $db): int {
        return (int) $db->query(
            "SELECT COUNT(*) FROM curse_dispecer WHERE observatii LIKE 'TEST_SEGMENT_%' AND deleted_at IS NULL"
        )->fetchColumn();
    };
    $sumBilled = static function (PDO $db): float {
        return (float) $db->query(
            "SELECT COALESCE(SUM(total_facturare), 0) FROM curse_dispecer
              WHERE observatii LIKE 'TEST_SEGMENT_%' AND deleted_at IS NULL"
        )->fetchColumn();
    };

    echo "\n== Reluarea cursei nu creeaza o cursa noua ==\n";

    $racesBefore = $countRaces($db);
    $billedBefore = $sumBilled($db);

    $model->addRaceSegment($raceId, [
        'vehicle_id' => $vehicleB,
        'driver_id' => $driverB,
        'data_inceput' => $day,
        'ora_inceput' => '14:00:00',
        'data_sfarsit' => $nextDay,
        'ora_sfarsit' => '10:00:00',
        'km' => 600,
        'tona_livrata' => 6.00,
        'nr_clienti' => 4,
        'loc_livrare' => 'Zona test',
        'observatii' => 'TEST_SEGMENT_reluare',
    ], null);

    check(
        '1. Numarul de curse ramane acelasi dupa reluare',
        $countRaces($db) === $racesBefore,
        'inainte=' . $racesBefore . ' dupa=' . $countRaces($db)
    );
    check(
        '2. Valoarea facturata nu se dubleaza',
        abs($sumBilled($db) - $billedBefore) < 0.001,
        'inainte=' . $billedBefore . ' dupa=' . $sumBilled($db)
    );

    $segments = $model->getRaceSegments($raceId);
    check('3. Cursa are exact 2 segmente', count($segments) === 2, 'segmente=' . count($segments));
    check(
        '4. Primul segment pastreaza soferul si vehiculul initial',
        count($segments) === 2
            && (int) $segments[0]['vehicle_id'] === $vehicleA
            && (int) $segments[0]['driver_id'] === $driverA,
        'vehicul=' . ($segments[0]['vehicle_id'] ?? '-') . ' sofer=' . ($segments[0]['driver_id'] ?? '-')
    );
    check(
        '5. Primul segment preia km-ii cursei de pana la oprire',
        count($segments) === 2 && (int) $segments[0]['km'] === 1000,
        'km=' . ($segments[0]['km'] ?? '-')
    );

    echo "\n== Cursa acopera acum tot intervalul (diurna) ==\n";

    $race = $model->getRaceById($raceId);
    check(
        '6. Sfarsitul cursei este sfarsitul ultimului segment',
        (string) ($race['data_sfarsit'] ?? '') === $nextDay
            && substr((string) ($race['ora_sfarsit'] ?? ''), 0, 5) === '10:00',
        'sfarsit=' . ($race['data_sfarsit'] ?? '-') . ' ' . ($race['ora_sfarsit'] ?? '-')
    );
    check(
        '7. Durata cursei se recalculeaza pe tot intervalul (06:00 -> 10:00 a doua zi = 1680 min)',
        (int) ($race['durata_cursa_minute'] ?? 0) === 1680,
        'durata=' . ($race['durata_cursa_minute'] ?? '-')
    );
    check(
        '8. Vehiculul si soferul cursei raman cele din primul segment',
        (int) ($race['vehicle_id'] ?? 0) === $vehicleA && (int) ($race['driver_id'] ?? 0) === $driverA
    );
    check(
        '9. Km-ii cursei sunt suma fazelor (1000 + 600)',
        (int) ($race['km_cursa'] ?? 0) === 1600,
        'km=' . ($race['km_cursa'] ?? '-')
    );
    check(
        '9b. Tariful ramane al cursei intregi, o singura data (primar tone: 10 t x 70)',
        abs((float) ($race['total_facturare'] ?? 0) - 700.00) < 0.001,
        'total=' . ($race['total_facturare'] ?? '-')
    );
    check(
        '9c. Clientii cursei sunt suma fazelor',
        (int) ($race['nr_clienti'] ?? 0) === 4,
        'clienti=' . ($race['nr_clienti'] ?? '-')
    );
    $segmentTotals = DispecerCurseModel::sumSegmentTotals($model->getRaceSegments($raceId));
    check(
        '9d. Tonele livrate se aduna din faze (raman pe faze la tipurile fara camp pe cursa)',
        abs((float) ($segmentTotals['tona_livrata'] ?? 0) - 6.00) < 0.001,
        'livrat=' . ($segmentTotals['tona_livrata'] ?? '-')
    );
    check(
        '9e. Tipul fazei se deduce din ce s-a completat',
        DispecerCurseModel::segmentPhaseType($model->getRaceSegments($raceId)[1]) === 'livrare'
            && DispecerCurseModel::segmentPhaseType($model->getRaceSegments($raceId)[0]) === 'incarcare',
        'faza2=' . DispecerCurseModel::segmentPhaseType($model->getRaceSegments($raceId)[1])
    );

    echo "\n== Km-ii cursei se impart intre vehiculele segmentelor ==\n";

    $costModel = new OperationalCostModel($db);
    $activity = $costModel->getActivityByVehicle($rangeStart, $rangeEnd);
    $kmA = (int) ($activity[$vehicleA]['km_real'] ?? 0);
    $kmB = (int) ($activity[$vehicleB]['km_real'] ?? 0);
    $venitA = (float) ($activity[$vehicleA]['venit'] ?? 0);
    $venitB = (float) ($activity[$vehicleB]['venit'] ?? 0);
    $curseA = (int) ($activity[$vehicleA]['curse'] ?? 0);
    $curseB = (int) ($activity[$vehicleB]['curse'] ?? 0);

    check(
        '10. Fiecare vehicul primeste km-ii fazei lui (1000 / 600)',
        $kmA === 1000 && $kmB === 600,
        'A=' . $kmA . ' B=' . $kmB
    );
    check(
        '11. Suma km-ilor pe vehicule = km-ii cursei (nimic inventat, nimic pierdut)',
        ($kmA + $kmB) === 1600,
        'suma=' . ($kmA + $kmB)
    );
    check(
        '12. Venitul se imparte proportional, fara dublare (437.50 + 262.50 = 700)',
        abs(($venitA + $venitB) - 700.00) < 0.01 && abs($venitA - 437.50) < 0.01,
        'A=' . $venitA . ' B=' . $venitB
    );
    check(
        '13. Cursa se numara o singura data, pe vehiculul principal',
        $curseA === 1 && $curseB === 0,
        'A=' . $curseA . ' B=' . $curseB
    );

    echo "\n== Editarea cursei se reflecta in segmentele de capat ==\n";

    // Ca la salvarea din formular: datele cursei, fara coloanele care apartin
    // doar inserarii (created_by / created_at).
    $editData = $model->getRaceById($raceId);
    unset($editData['created_by'], $editData['created_at']);
    $editData['data_sfarsit'] = $nextDay;
    $editData['ora_sfarsit'] = '12:30:00';
    $model->updateRaceAndSyncVehicleKm($raceId, $editData, null);

    $segmentsAfterEdit = $model->getRaceSegments($raceId);
    check(
        '13b. Sfarsitul editat pe cursa ajunge pe ultimul segment',
        count($segmentsAfterEdit) === 2
            && substr((string) ($segmentsAfterEdit[1]['ora_sfarsit'] ?? ''), 0, 5) === '12:30',
        'ultimul segment=' . ($segmentsAfterEdit[1]['ora_sfarsit'] ?? '-')
    );
    check(
        '13c. Km-ii segmentelor raman ai segmentelor (editarea cursei nu ii rescrie)',
        count($segmentsAfterEdit) === 2
            && (int) $segmentsAfterEdit[0]['km'] === 1000
            && (int) $segmentsAfterEdit[1]['km'] === 600
    );

    echo "\n== Diurna se imparte pe soferii care au condus cursa ==\n";

    // Dupa editare: 06:00 -> 12:30 a doua zi = 1830 min => 2 diurne pe cursa,
    // din care segmentul 1 acopera 480 min si segmentul 2 acopera 1350 min.
    $raceForDiurna = $model->getRaceById($raceId);
    $diurnaDays = intdiv((int) ($raceForDiurna['durata_cursa_minute'] ?? 0), 12 * 60);
    $diurnaSplit = dispatcher_diurna_split($diurnaDays, $segmentsAfterEdit);
    $diurnaByDriver = [];
    foreach ($diurnaSplit as $diurnaRow) {
        $diurnaByDriver[(int) $diurnaRow['driver_id']] = (int) $diurnaRow['zile'];
    }

    check('17. Cursa are 2 diurne pe tot intervalul', $diurnaDays === 2, 'diurne=' . $diurnaDays);
    check(
        '18. Suma diurnelor pe soferi == diurnele cursei (nimic pierdut la rotunjire)',
        array_sum($diurnaByDriver) === $diurnaDays,
        'suma=' . array_sum($diurnaByDriver)
    );
    check(
        '19. Fiecare sofer primeste o diurna (480 min vs 1350 min din 1830)',
        ($diurnaByDriver[$driverA] ?? 0) === 1 && ($diurnaByDriver[$driverB] ?? 0) === 1,
        'A=' . ($diurnaByDriver[$driverA] ?? 0) . ' B=' . ($diurnaByDriver[$driverB] ?? 0)
    );

    // Banii de diurna urmeaza aceeasi impartire (dupa timp), iar celelalte
    // cheltuieli de cursa se impart dupa km, ca restul costurilor pe vehicul.
    $expenseStmt = $db->prepare("
        INSERT INTO curse_cheltuieli (cursa_id, tip_cheltuiala, suma, data_cheltuiala, created_at, updated_at)
        VALUES (:cursa_id, :tip, :suma, :data_cheltuiala, :created_at, :updated_at)
    ");
    $expenseStmt->execute([
        'cursa_id' => $raceId,
        'tip' => 'diurna',
        'suma' => 366.00,
        'data_cheltuiala' => $day,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $expenseStmt->execute([
        'cursa_id' => $raceId,
        'tip' => 'taxe_drum',
        'suma' => 160.00,
        'data_cheltuiala' => $day,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $diurnaExpenses = [];
    foreach ($costModel->getCourseExpensesByType($rangeStart, $rangeEnd, 'diurna') as $expenseRow) {
        $diurnaExpenses[(int) $expenseRow['driver_id']] = (float) $expenseRow['total'];
    }
    // 366 lei x 480/1830 = 96, respectiv x 1350/1830 = 270.
    check(
        '20. Banii de diurna se impart dupa timpul fiecarui sofer (96 / 270)',
        abs(($diurnaExpenses[$driverA] ?? 0) - 96.00) < 0.01
            && abs(($diurnaExpenses[$driverB] ?? 0) - 270.00) < 0.01,
        'A=' . ($diurnaExpenses[$driverA] ?? 0) . ' B=' . ($diurnaExpenses[$driverB] ?? 0)
    );
    check(
        '21. Suma diurnei impartite == suma inregistrata pe cursa',
        abs(array_sum($diurnaExpenses) - 366.00) < 0.01,
        'suma=' . array_sum($diurnaExpenses)
    );

    $tollExpenses = [];
    foreach ($costModel->getCourseExpensesByType($rangeStart, $rangeEnd, 'taxe_drum') as $expenseRow) {
        $tollExpenses[(int) $expenseRow['driver_id']] = (float) $expenseRow['total'];
    }
    // 160 lei x 1000/1600 = 100, respectiv x 600/1600 = 60 (dupa km, nu dupa timp).
    check(
        '22. Celelalte cheltuieli de cursa raman impartite dupa km (100 / 60)',
        abs(($tollExpenses[$driverA] ?? 0) - 100.00) < 0.01
            && abs(($tollExpenses[$driverB] ?? 0) - 60.00) < 0.01,
        'A=' . ($tollExpenses[$driverA] ?? 0) . ' B=' . ($tollExpenses[$driverB] ?? 0)
    );

    echo "\n== Dashboard Analitic V2: vederile pe sofer urmeaza segmentele ==\n";

    $dashboardFilters = ['date_start' => $rangeStart, 'date_end' => $rangeEnd];
    $dashboardData = $dashboard->getData($dashboardFilters);

    $dashboardDrivers = [];
    foreach ($dashboardData['drivers'] as $dashboardRow) {
        $dashboardDrivers[(int) $dashboardRow['id']] = $dashboardRow;
    }

    check(
        '27. Ambii soferi apar in vederea pe sofer',
        isset($dashboardDrivers[$driverA], $dashboardDrivers[$driverB]),
        'soferi=' . implode(',', array_keys($dashboardDrivers))
    );
    check(
        '28. Fiecare sofer primeste km-ii fazei lui (1000 / 600)',
        (int) round((float) ($dashboardDrivers[$driverA]['km_totali'] ?? 0)) === 1000
            && (int) round((float) ($dashboardDrivers[$driverB]['km_totali'] ?? 0)) === 600,
        'A=' . ($dashboardDrivers[$driverA]['km_totali'] ?? '-') . ' B=' . ($dashboardDrivers[$driverB]['km_totali'] ?? '-')
    );
    check(
        '29. Facturarea se imparte, fara dublare (437.50 + 262.50)',
        abs((float) ($dashboardDrivers[$driverA]['facturare'] ?? 0) - 437.50) < 0.01
            && abs((float) ($dashboardDrivers[$driverB]['facturare'] ?? 0) - 262.50) < 0.01,
        'A=' . ($dashboardDrivers[$driverA]['facturare'] ?? '-') . ' B=' . ($dashboardDrivers[$driverB]['facturare'] ?? '-')
    );
    check(
        '30. Suma pe soferi == totalul flotei (cursa nu se dubleaza)',
        abs(
            (float) ($dashboardDrivers[$driverA]['facturare'] ?? 0)
            + (float) ($dashboardDrivers[$driverB]['facturare'] ?? 0)
            - (float) ($dashboardData['fleet']['facturare'] ?? 0)
        ) < 0.01,
        'soferi=' . ((float) ($dashboardDrivers[$driverA]['facturare'] ?? 0) + (float) ($dashboardDrivers[$driverB]['facturare'] ?? 0))
            . ' flota=' . ($dashboardData['fleet']['facturare'] ?? '-')
    );
    check(
        '31. Cursa se numara o data la fiecare sofer care a condus-o',
        (int) ($dashboardDrivers[$driverA]['curse'] ?? 0) === 1
            && (int) ($dashboardDrivers[$driverB]['curse'] ?? 0) === 1,
        'A=' . ($dashboardDrivers[$driverA]['curse'] ?? '-') . ' B=' . ($dashboardDrivers[$driverB]['curse'] ?? '-')
    );
    check(
        '32. Fiecare sofer are vehiculul lui in vederea pe sofer',
        (int) ($dashboardDrivers[$driverA]['nr_vehicule'] ?? 0) === 1
            && (int) ($dashboardDrivers[$driverB]['nr_vehicule'] ?? 0) === 1,
        'A=' . ($dashboardDrivers[$driverA]['nr_vehicule'] ?? '-') . ' B=' . ($dashboardDrivers[$driverB]['nr_vehicule'] ?? '-')
    );

    // Filtrul pe sofer trebuie sa gaseasca si soferul care a condus doar segmentul.
    $filteredData = $dashboard->getData($dashboardFilters + ['driver_ids' => [$driverB]]);
    check(
        '33. Filtrul pe al doilea sofer gaseste cursa',
        (int) ($filteredData['fleet']['curse'] ?? 0) === 1,
        'curse=' . ($filteredData['fleet']['curse'] ?? '-')
    );

    $profile = $dashboard->getEntityProfile('sofer', $driverB, $dashboardFilters);
    check(
        '34. Profilul soferului arata partea lui din cursa, nu toata cursa',
        abs((float) ($profile['entity']['facturare'] ?? 0) - 262.50) < 0.01
            && (int) round((float) ($profile['entity']['km_totali'] ?? 0)) === 600,
        'facturare=' . ($profile['entity']['facturare'] ?? '-') . ' km=' . ($profile['entity']['km_totali'] ?? '-')
    );
    $profileTripKm = 0.0;
    foreach ($profile['trips'] as $profileTrip) {
        $profileTripKm += (float) $profileTrip['km'];
    }
    check(
        '35. Lista de curse din profil insumeaza exact totalul afisat',
        abs($profileTripKm - (float) ($profile['entity']['km_totali'] ?? 0)) < 0.01,
        'lista=' . $profileTripKm . ' total=' . ($profile['entity']['km_totali'] ?? '-')
    );
    $profileVehicles = [];
    foreach ($profile['by_partner'] as $partnerGroup) {
        if (($partnerGroup['key'] ?? '') === 'vehicule') {
            foreach ($partnerGroup['rows'] as $partnerRow) {
                $profileVehicles[] = (string) $partnerRow['key'];
            }
        }
    }
    $vehicleBPlate = (string) $db->query('SELECT nr_inmatriculare FROM vehicule WHERE id = ' . $vehicleB)->fetchColumn();
    check(
        '36. In profil apare vehiculul cu care a condus segmentul',
        $profileVehicles === [$vehicleBPlate],
        'vehicule=' . implode(',', $profileVehicles) . ' asteptat=' . $vehicleBPlate
    );

    echo "\n== Dashboard Analitic V2: vederile pe vehicul urmeaza segmentele ==\n";

    $dashboardVehicles = [];
    foreach ($dashboardData['vehicles'] as $dashboardRow) {
        $dashboardVehicles[(int) $dashboardRow['id']] = $dashboardRow;
    }

    check(
        '37. Ambele vehicule apar in vederea pe vehicul',
        isset($dashboardVehicles[$vehicleA], $dashboardVehicles[$vehicleB]),
        'vehicule=' . implode(',', array_keys($dashboardVehicles))
    );
    check(
        '38. Fiecare vehicul primeste km-ii fazei lui (1000 / 600)',
        (int) round((float) ($dashboardVehicles[$vehicleA]['km_totali'] ?? 0)) === 1000
            && (int) round((float) ($dashboardVehicles[$vehicleB]['km_totali'] ?? 0)) === 600,
        'A=' . ($dashboardVehicles[$vehicleA]['km_totali'] ?? '-') . ' B=' . ($dashboardVehicles[$vehicleB]['km_totali'] ?? '-')
    );
    check(
        '39. Suma pe vehicule == totalul flotei',
        abs(
            (float) ($dashboardVehicles[$vehicleA]['facturare'] ?? 0)
            + (float) ($dashboardVehicles[$vehicleB]['facturare'] ?? 0)
            - (float) ($dashboardData['fleet']['facturare'] ?? 0)
        ) < 0.01,
        'vehicule=' . ((float) ($dashboardVehicles[$vehicleA]['facturare'] ?? 0) + (float) ($dashboardVehicles[$vehicleB]['facturare'] ?? 0))
            . ' flota=' . ($dashboardData['fleet']['facturare'] ?? '-')
    );
    check(
        '40. Flota numara ambele masini si ambii soferi ai cursei',
        (int) ($dashboardData['fleet']['nr_vehicule'] ?? 0) === 2
            && (int) ($dashboardData['fleet']['nr_soferi'] ?? 0) === 2,
        'vehicule=' . ($dashboardData['fleet']['nr_vehicule'] ?? '-') . ' soferi=' . ($dashboardData['fleet']['nr_soferi'] ?? '-')
    );
    check(
        '41. Fiecare vehicul arata soferul lui',
        (int) ($dashboardVehicles[$vehicleA]['nr_soferi'] ?? 0) === 1
            && (int) ($dashboardVehicles[$vehicleB]['nr_soferi'] ?? 0) === 1,
        'A=' . ($dashboardVehicles[$vehicleA]['nr_soferi'] ?? '-') . ' B=' . ($dashboardVehicles[$vehicleB]['nr_soferi'] ?? '-')
    );

    $filteredVehicleData = $dashboard->getData($dashboardFilters + ['vehicle_ids' => [$vehicleB]]);
    check(
        '42. Filtrul pe al doilea vehicul gaseste cursa',
        (int) ($filteredVehicleData['fleet']['curse'] ?? 0) === 1,
        'curse=' . ($filteredVehicleData['fleet']['curse'] ?? '-')
    );

    $vehicleProfile = $dashboard->getEntityProfile('vehicul', $vehicleB, $dashboardFilters);
    check(
        '43. Profilul vehiculului arata partea lui din cursa',
        abs((float) ($vehicleProfile['entity']['facturare'] ?? 0) - 262.50) < 0.01
            && (int) round((float) ($vehicleProfile['entity']['km_totali'] ?? 0)) === 600,
        'facturare=' . ($vehicleProfile['entity']['facturare'] ?? '-') . ' km=' . ($vehicleProfile['entity']['km_totali'] ?? '-')
    );
    $vehicleProfileDrivers = [];
    foreach ($vehicleProfile['by_partner'] as $partnerGroup) {
        if (($partnerGroup['key'] ?? '') === 'soferi') {
            foreach ($partnerGroup['rows'] as $partnerRow) {
                $vehicleProfileDrivers[] = (string) $partnerRow['key'];
            }
        }
    }
    $driverBName = (string) $db->query('SELECT nume FROM soferi WHERE id = ' . $driverB)->fetchColumn();
    check(
        '44. In profilul vehiculului apare soferul care l-a condus pe segment',
        $vehicleProfileDrivers === [$driverBName],
        'soferi=' . implode(',', $vehicleProfileDrivers) . ' asteptat=' . $driverBName
    );

    // Zilele active: fiecare masina este activa doar in zilele segmentului ei.
    check(
        '45. Zilele active se impart intre vehicule (2 zile pentru al doilea)',
        (int) ($dashboardVehicles[$vehicleB]['zile_active'] ?? 0) === 2,
        'zile=' . ($dashboardVehicles[$vehicleB]['zile_active'] ?? '-')
    );

    echo "\n== Stergerea si restaurarea unei curse cu faze ==\n";

    // Regresie: pregatirea tabelei de faze facea CREATE/ALTER in mijlocul tranzactiei
    // de stergere, MySQL comitea implicit, iar commit-ul urmator crapa cu
    // "There is no active transaction" — cursa parea nestearsa desi disparea.
    $kmBeforeDelete = (int) $db->query('SELECT km_bord FROM vehicule WHERE id = ' . $vehicleA)->fetchColumn();
    $deleteOk = true;
    try {
        $model->deleteRaceAndSyncVehicleKm($raceId, null);
    } catch (Throwable $exception) {
        $deleteOk = false;
        echo '   eroare: ' . $exception->getMessage() . "\n";
    }
    check('17b. Cursa cu faze se sterge fara eroare', $deleteOk);
    $deletedRace = (array) $db->query('SELECT deleted_at FROM curse_dispecer WHERE id = ' . $raceId)->fetch(PDO::FETCH_ASSOC);
    check(
        '17c. Cursa este marcata ca stearsa',
        ($deletedRace['deleted_at'] ?? null) !== null,
        'deleted_at=' . ($deletedRace['deleted_at'] ?? 'NULL')
    );
    check(
        '17d. Fazele raman legate de cursa, pentru restaurare',
        count($model->getRaceSegments($raceId)) === 2,
        'faze=' . count($model->getRaceSegments($raceId))
    );

    $restoreOk = true;
    try {
        $model->restoreDeletedRaceAndSyncVehicleKm($raceId, null);
    } catch (Throwable $exception) {
        $restoreOk = false;
        echo '   eroare: ' . $exception->getMessage() . "\n";
    }
    check('17e. Cursa cu faze se restaureaza fara eroare', $restoreOk);
    $restoredRace = $model->getRaceById($raceId);
    check(
        '17f. Dupa restaurare cursa are aceiasi km si acelasi tarif',
        (int) ($restoredRace['km_cursa'] ?? 0) === 1600
            && abs((float) ($restoredRace['total_facturare'] ?? 0) - 700.00) < 0.001,
        'km=' . ($restoredRace['km_cursa'] ?? '-') . ' total=' . ($restoredRace['total_facturare'] ?? '-')
    );
    check(
        '17g. Km-ii vehiculului revin dupa stergere + restaurare',
        (int) $db->query('SELECT km_bord FROM vehicule WHERE id = ' . $vehicleA)->fetchColumn() === $kmBeforeDelete,
        'inainte=' . $kmBeforeDelete . ' acum=' . (int) $db->query('SELECT km_bord FROM vehicule WHERE id = ' . $vehicleA)->fetchColumn()
    );

    echo "\n== Stergerea segmentului readuce cursa la starea de cursa simpla ==\n";

    $segmentId = (int) $segments[1]['id'];
    $model->deleteRaceSegment($segmentId, null);

    check('23. Nu mai raman segmente', $model->getRaceSegments($raceId) === []);

    $raceAfterDelete = $model->getRaceById($raceId);
    check(
        '24. Cursa revine la intervalul ei initial',
        (string) ($raceAfterDelete['data_sfarsit'] ?? '') === $day
            && substr((string) ($raceAfterDelete['ora_sfarsit'] ?? ''), 0, 5) === '14:00'
            && (int) ($raceAfterDelete['durata_cursa_minute'] ?? 0) === 480,
        'sfarsit=' . ($raceAfterDelete['data_sfarsit'] ?? '-') . ' ' . ($raceAfterDelete['ora_sfarsit'] ?? '-')
            . ' durata=' . ($raceAfterDelete['durata_cursa_minute'] ?? '-')
    );

    $activityAfter = $costModel->getActivityByVehicle($rangeStart, $rangeEnd);
    check(
        '25. Km-ii revin integral pe vehiculul cursei',
        (int) ($activityAfter[$vehicleA]['km_real'] ?? 0) === 1000
            && (int) ($activityAfter[$vehicleB]['km_real'] ?? 0) === 0,
        'A=' . ($activityAfter[$vehicleA]['km_real'] ?? '-') . ' B=' . ($activityAfter[$vehicleB]['km_real'] ?? '-')
    );
    check(
        '25b. Km-ii cursei revin la valoarea fazei ramase',
        (int) ($raceAfterDelete['km_cursa'] ?? 0) === 1000,
        'km=' . ($raceAfterDelete['km_cursa'] ?? '-')
    );
    check(
        '26. Cursa ramane una singura si facturata o singura data',
        $countRaces($db) === $racesBefore && abs($sumBilled($db) - $billedBefore) < 0.001
    );

    echo "\n==============================\n";
    echo "PASSED: $passed   FAILED: $failed\n";
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "(tranzactie anulata - nicio modificare persistata)\n";

    $vehicleKmAfter = $readVehicleKm($db, $vehicleA, $vehicleB);
    if ($vehicleKmAfter !== $vehicleKmBefore) {
        $failed++;
        echo "\033[31m  ATENTIE\033[0m  km-ii vehiculelor de test s-au modificat in afara tranzactiei!\n";
        echo "  inainte: " . json_encode($vehicleKmBefore) . "\n";
        echo "  dupa:    " . json_encode($vehicleKmAfter) . "\n";
    }
}

exit($failed === 0 ? 0 : 1);
