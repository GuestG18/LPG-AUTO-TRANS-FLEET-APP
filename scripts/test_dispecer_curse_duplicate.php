<?php
declare(strict_types=1);

/**
 * Test harness pentru regulile de cursa dubla din Dispecer curse.
 *
 *   php scripts/test_dispecer_curse_duplicate.php
 *
 * Acopera regula noua, care inlocuieste in practica amprenta exacta
 * (duplicate_key): suprapunerea de interval pe acelasi vehicul (blocanta) si
 * cursele asemanatoare din aceeasi zi (doar avertisment). Cazul care a motivat
 * regula — aceeasi cursa reintrodusa cu ora de inceput mutata cu un minut —
 * este primul test.
 *
 * Randurile de test sunt marcate cu observatii "TEST_DUP_*" si sterse la final.
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

function cleanup(PDO $db): void
{
    $db->exec("DELETE FROM curse_dispecer WHERE observatii LIKE 'TEST_DUP_%'");
}

/** Perechea de vehicule / beneficiar / loc folosita de toate cazurile. */
function pickFixtures(PDO $db): array
{
    $vehicles = $db->query('SELECT id FROM vehicule ORDER BY id ASC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    if (count($vehicles) < 2) {
        fwrite(STDERR, "Sunt necesare cel putin 2 vehicule in baza pentru test.\n");
        exit(1);
    }

    $beneficiaryId = (int) $db->query('SELECT id FROM configurare_beneficiari_transport ORDER BY id ASC LIMIT 1')->fetchColumn();
    $loadLocations = $db->query('SELECT id FROM configurare_locuri_incarcare ORDER BY id ASC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    if ($beneficiaryId <= 0 || count($loadLocations) < 2) {
        fwrite(STDERR, "Sunt necesare un beneficiar si 2 locuri de incarcare in baza pentru test.\n");
        exit(1);
    }

    return [
        'vehicle_id' => (int) $vehicles[0],
        'other_vehicle_id' => (int) $vehicles[1],
        'beneficiar_id' => $beneficiaryId,
        'loc_incarcare_id' => (int) $loadLocations[0],
        'other_loc_incarcare_id' => (int) $loadLocations[1],
    ];
}

function insertRace(PDO $db, array $race): int
{
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("
        INSERT INTO curse_dispecer (
            vehicle_id, tip_transport, data_cursa, data_inceput, data_sfarsit,
            ora_inceput, ora_sfarsit, loc_incarcare_id, beneficiar_id,
            cantitate_incarcata, km_cursa, pret_tarifare, total_facturare,
            observatii, deleted_at, created_at, updated_at
        ) VALUES (
            :vehicle_id, 'primar_tona', :data_cursa, :data_inceput, :data_sfarsit,
            :ora_inceput, :ora_sfarsit, :loc_incarcare_id, :beneficiar_id,
            23.00, 180, 60.00, 1380.00,
            :observatii, :deleted_at, :created_at, :updated_at
        )
    ");
    // PDO ruleaza cu EMULATE_PREPARES=false: fiecare placeholder apare o singura data.
    $stmt->execute([
        'vehicle_id' => $race['vehicle_id'],
        'data_cursa' => $race['data_inceput'],
        'data_inceput' => $race['data_inceput'],
        'data_sfarsit' => $race['data_sfarsit'],
        'ora_inceput' => $race['ora_inceput'],
        'ora_sfarsit' => $race['ora_sfarsit'],
        'loc_incarcare_id' => $race['loc_incarcare_id'],
        'beneficiar_id' => $race['beneficiar_id'],
        'observatii' => $race['observatii'],
        'deleted_at' => $race['deleted_at'] ?? null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $db->lastInsertId();
}

cleanup($db);
$fixtures = pickFixtures($db);
$day = '2031-03-11';
$nextDay = '2031-03-12';

$baseline = [
    'vehicle_id' => $fixtures['vehicle_id'],
    'beneficiar_id' => $fixtures['beneficiar_id'],
    'loc_incarcare_id' => $fixtures['loc_incarcare_id'],
    'data_inceput' => $day,
    'data_sfarsit' => $day,
    'ora_inceput' => '00:16:00',
    'ora_sfarsit' => '08:00:00',
    'observatii' => 'TEST_DUP_baseline',
];

echo "\n== Suprapunere de interval pe acelasi vehicul (blocanta) ==\n";

$baselineId = insertRace($db, $baseline);

/** Datele asa cum ajung din formular: ora fara secunde, data ISO. */
$probe = static function (array $overrides = []) use ($fixtures, $day): array {
    return array_merge([
        'vehicle_id' => $fixtures['vehicle_id'],
        'beneficiar_id' => $fixtures['beneficiar_id'],
        'loc_incarcare_id' => $fixtures['loc_incarcare_id'],
        'data_inceput' => $day,
        'data_sfarsit' => $day,
        'ora_inceput' => '00:16',
        'ora_sfarsit' => '08:00',
    ], $overrides);
};

$overlap = $model->findOverlappingRace($probe(['ora_inceput' => '00:17']));
check(
    'Cursa reintrodusa cu ora mutata cu un minut este prinsa',
    $overlap !== null && (int) $overlap['id'] === $baselineId,
    $overlap === null ? 'nu a fost detectata' : 'id=' . $overlap['id']
);

$overlap = $model->findOverlappingRace($probe());
check('Interval identic este prins', $overlap !== null && (int) $overlap['id'] === $baselineId);

$overlap = $model->findOverlappingRace($probe(['ora_inceput' => '07:59', 'ora_sfarsit' => '15:00']));
check('Suprapunere partiala este prinsa', $overlap !== null && (int) $overlap['id'] === $baselineId);

$overlap = $model->findOverlappingRace($probe(['ora_inceput' => '02:00', 'ora_sfarsit' => '03:00']));
check('Interval continut integral este prins', $overlap !== null && (int) $overlap['id'] === $baselineId);

echo "\n== Cazuri care NU trebuie blocate ==\n";

$overlap = $model->findOverlappingRace($probe(['ora_inceput' => '08:00', 'ora_sfarsit' => '16:00']));
check(
    'Segment de reluare care porneste exact la ora de sfarsit trece',
    $overlap === null,
    $overlap === null ? '' : 'id=' . $overlap['id']
);

$overlap = $model->findOverlappingRace($probe(['ora_inceput' => '09:00', 'ora_sfarsit' => '17:00']));
check('Al doilea drum din aceeasi zi, fara suprapunere, trece', $overlap === null);

$overlap = $model->findOverlappingRace($probe(['vehicle_id' => $fixtures['other_vehicle_id']]));
check('Alt vehicul in acelasi interval trece', $overlap === null);

$overlap = $model->findOverlappingRace($probe(['data_inceput' => $nextDay, 'data_sfarsit' => $nextDay]));
check('Aceleasi ore in alta zi trec', $overlap === null);

$overlap = $model->findOverlappingRace($probe(['ora_inceput' => '', 'ora_sfarsit' => '']));
check('Cursa fara ora de inceput nu este blocata (intervalul ar acoperi toata ziua)', $overlap === null);

$overlap = $model->findOverlappingRace($probe(['ora_inceput' => '00:17']), $baselineId);
check('Editarea propriei curse nu se auto-blocheaza', $overlap === null);

echo "\n== Curse in desfasurare si curse sterse ==\n";

$openId = insertRace($db, array_merge($baseline, [
    'data_inceput' => $nextDay,
    'data_sfarsit' => $nextDay,
    'ora_inceput' => '10:00:00',
    'ora_sfarsit' => null,
    'observatii' => 'TEST_DUP_open',
]));
$overlap = $model->findOverlappingRace($probe([
    'data_inceput' => $nextDay,
    'data_sfarsit' => $nextDay,
    'ora_inceput' => '12:00',
    'ora_sfarsit' => '13:00',
]));
check(
    'Cursa fara ora de sfarsit ocupa vehiculul pana la finalul zilei ei',
    $overlap !== null && (int) $overlap['id'] === $openId
);

$overlap = $model->findOverlappingRace($probe([
    'data_inceput' => $nextDay,
    'data_sfarsit' => $nextDay,
    'ora_inceput' => '08:00',
    'ora_sfarsit' => '09:30',
]));
check('Interval inaintea cursei deschise trece', $overlap === null);

$deletedId = insertRace($db, array_merge($baseline, [
    'data_inceput' => '2031-03-13',
    'data_sfarsit' => '2031-03-13',
    'observatii' => 'TEST_DUP_deleted',
    'deleted_at' => date('Y-m-d H:i:s'),
]));
$overlap = $model->findOverlappingRace($probe([
    'data_inceput' => '2031-03-13',
    'data_sfarsit' => '2031-03-13',
]));
check(
    'Cursa stearsa nu blocheaza reintroducerea',
    $overlap === null,
    $overlap === null ? '' : 'id=' . $overlap['id'] . ' (sters=' . $deletedId . ')'
);

echo "\n== Curse asemanatoare (avertisment, nu blocaj) ==\n";

$similar = $model->findSimilarRaces($probe(['ora_inceput' => '18:00', 'ora_sfarsit' => '22:00']));
$similarIds = array_map(static fn(array $row): int => (int) $row['id'], $similar);
check(
    'Al doilea drum pe aceeasi ruta in aceeasi zi este semnalat',
    in_array($baselineId, $similarIds, true),
    'ids=' . implode(',', $similarIds)
);

$similar = $model->findSimilarRaces($probe([
    'ora_inceput' => '18:00',
    'ora_sfarsit' => '22:00',
    'loc_incarcare_id' => $fixtures['other_loc_incarcare_id'],
]));
check('Alt loc de incarcare nu este semnalat', $similar === []);

$similar = $model->findSimilarRaces($probe([
    'ora_inceput' => '18:00',
    'ora_sfarsit' => '22:00',
    'vehicle_id' => $fixtures['other_vehicle_id'],
]));
check('Alt vehicul nu este semnalat', $similar === []);

$similar = $model->findSimilarRaces($probe([
    'ora_inceput' => '18:00',
    'ora_sfarsit' => '22:00',
    'data_inceput' => $nextDay,
    'data_sfarsit' => $nextDay,
]));
$similarIds = array_map(static fn(array $row): int => (int) $row['id'], $similar);
check('Alta zi nu aduce cursa de referinta', !in_array($baselineId, $similarIds, true));

$similar = $model->findSimilarRaces($probe(['ora_inceput' => '18:00', 'ora_sfarsit' => '22:00']), $baselineId);
check('Cursa editata este exclusa din propria lista de asemanari', $similar === []);

echo "\n== Editarea unei curse care se suprapunea deja ==\n";

// Doua curse suprapuse existente in baza: asa arata datele istorice, dinainte
// de introducerea regulii. Corectarea lor nu trebuie sa devina imposibila.
$legacyA = insertRace($db, array_merge($baseline, [
    'data_inceput' => '2031-03-14',
    'data_sfarsit' => '2031-03-14',
    'observatii' => 'TEST_DUP_legacy_a',
]));
$legacyB = insertRace($db, array_merge($baseline, [
    'data_inceput' => '2031-03-14',
    'data_sfarsit' => '2031-03-14',
    'ora_inceput' => '00:17:00',
    'observatii' => 'TEST_DUP_legacy_b',
]));

$storedB = $model->getRaceById($legacyB);
check('Cursa istorica suprapusa exista in baza', is_array($storedB), 'id=' . $legacyB);

$unchangedInterval = [
    'vehicle_id' => $fixtures['vehicle_id'],
    'data_inceput' => '2031-03-14',
    'data_sfarsit' => '2031-03-14',
    'ora_inceput' => '00:17:00',
    'ora_sfarsit' => '08:00:00',
];
check(
    'Editarea altui camp decat intervalul nu declanseaza regula',
    $model->raceIntervalChanged($storedB, $unchangedInterval) === false
);
check(
    'Mutarea orei de inceput declanseaza regula',
    $model->raceIntervalChanged($storedB, array_merge($unchangedInterval, ['ora_inceput' => '00:25:00'])) === true
);
check(
    'Schimbarea vehiculului declanseaza regula',
    $model->raceIntervalChanged($storedB, array_merge($unchangedInterval, ['vehicle_id' => $fixtures['other_vehicle_id']])) === true
);
check(
    'Cursa noua (fara cursa existenta) declanseaza intotdeauna regula',
    $model->raceIntervalChanged(null, $unchangedInterval) === true
);

// Confirmarea ca suprapunerea chiar exista, deci scutirea de mai sus conteaza.
$overlap = $model->findOverlappingRace($unchangedInterval, $legacyB);
check(
    'Cursa istorica chiar se suprapune cu perechea ei',
    $overlap !== null && (int) $overlap['id'] === $legacyA,
    $overlap === null ? 'nu s-a gasit suprapunere' : 'id=' . $overlap['id']
);

echo "\n== Panourile din Desfasurator ==\n";

// "Curse deja inregistrate": tot istoricul vehiculului, fara fereastra de timp.
// Orice limita (ziua, luna) lasa afara exact cursa dubla introdusa peste marginea
// ei, asa ca panoul primeste toate cursele, cele recente primele.
$vehicleRaces = $model->getVehicleRaces($fixtures['vehicle_id']);
$vehicleIds = array_map(static fn(array $row): int => (int) $row['id'], $vehicleRaces);
check(
    'Istoricul vehiculului include cursa de referinta',
    in_array($baselineId, $vehicleIds, true),
    'ids=' . implode(',', $vehicleIds)
);
check(
    'Istoricul include curse din alte zile si alte luni',
    in_array($openId, $vehicleIds, true) && in_array($legacyA, $vehicleIds, true)
);
check('Cursele sterse raman excluse', !in_array($deletedId, $vehicleIds, true));
check(
    'Istoricul este ordonat descrescator dupa data',
    $vehicleRaces !== [] && $vehicleRaces[0]['data_inceput'] >= $vehicleRaces[count($vehicleRaces) - 1]['data_inceput']
);
check(
    'Randurile poarta beneficiarul si locul, pentru marcajul "Seamana"',
    $vehicleRaces !== []
        && array_key_exists('beneficiar_id', $vehicleRaces[0])
        && array_key_exists('loc_incarcare_id', $vehicleRaces[0])
);
check(
    'Cursa editata este exclusa din propriul istoric',
    !in_array($baselineId, array_map(
        static fn(array $row): int => (int) $row['id'],
        $model->getVehicleRaces($fixtures['vehicle_id'], $baselineId)
    ), true)
);
check('Istoricul cere un vehicul valid', $model->getVehicleRaces(0) === []);

// Contorul spune cate curse are vehiculul in total, ca panoul sa poata semnala
// cand lista afisata este trunchiata.
check(
    'Contorul de curse se potriveste cu lista returnata',
    $model->countVehicleRaces($fixtures['vehicle_id']) === count($vehicleRaces),
    'count=' . $model->countVehicleRaces($fixtures['vehicle_id']) . ' rows=' . count($vehicleRaces)
);
check(
    'Contorul respecta excluderea cursei editate',
    $model->countVehicleRaces($fixtures['vehicle_id'], $baselineId) === count($vehicleRaces) - 1
);
check('Contorul cere un vehicul valid', $model->countVehicleRaces(0) === 0);
check(
    'Limita taie lista, dar nu si contorul',
    count($model->getVehicleRaces($fixtures['vehicle_id'], null, 1)) === 1
        && $model->countVehicleRaces($fixtures['vehicle_id']) > 1
);

// "Curse noi": ce s-a salvat de la un moment dat incoace.
$beforeInsert = $db->query('SELECT DATE_SUB(NOW(), INTERVAL 1 SECOND)')->fetchColumn();
$freshId = insertRace($db, array_merge($baseline, [
    'data_inceput' => '2031-03-15',
    'data_sfarsit' => '2031-03-15',
    'observatii' => 'TEST_DUP_fresh',
]));
$newIds = array_map(static fn(array $row): int => (int) $row['id'], $model->getRacesCreatedAfter((string) $beforeInsert));
check('Cursa salvata dupa reper apare in lista de curse noi', in_array($freshId, $newIds, true));

$afterInsert = $db->query('SELECT DATE_ADD(NOW(), INTERVAL 5 MINUTE)')->fetchColumn();
check(
    'Un reper din viitor nu aduce curse',
    $model->getRacesCreatedAfter((string) $afterInsert) === []
);
check('Un reper invalid nu aduce curse', $model->getRacesCreatedAfter('ieri') === []);

cleanup($db);

echo "\n" . str_repeat('-', 60) . "\n";
echo "Rezultat: \033[32m$passed PASS\033[0m, " . ($failed > 0 ? "\033[31m$failed FAIL\033[0m" : "0 FAIL") . "\n";

exit($failed > 0 ? 1 : 0);
