<?php
declare(strict_types=1);

/**
 * Teste de acceptanta pentru Centru Kilometraj (?page=centru_kilometraj).
 *
 * Partea 1: regulile pure (OdometerCalculator, parsarea foii de parcurs SAS).
 * Partea 2: fluxul complet pe baza de date, cu furnizori SIMULATI (fara apeluri
 * SAS / CardOil reale): sincronizare idempotenta, cadere API, ansamblu, T0
 * versionat, totaluri fara dubla numarare, grupare pe categorii, ACL.
 *
 * SIGURANTA: fixture-urile sunt vehicule sintetice cu numarul KMCTEST-* si o
 * categorie "KMCTEST ..."; scriptul sterge DOAR aceste randuri (si ce depinde
 * de ele prin FK). Setarea politica_total se restaureaza la final.
 * Fara tranzactie de test: ensureSchema face DDL (COMMIT implicit).
 *
 * Rulare:  php scripts/test_centru_kilometraj.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/config/database.php';
require_once $projectRoot . '/htdocs/includes/helpers.php';
require_once $projectRoot . '/htdocs/includes/auth.php';
require_once $projectRoot . '/htdocs/includes/access.php';
require_once $projectRoot . '/htdocs/models/BaseModel.php';
require_once $projectRoot . '/htdocs/models/VehicleCapacityCategoryModel.php';
require_once $projectRoot . '/htdocs/models/OdometerCenterModel.php';
require_once $projectRoot . '/htdocs/services/SasFleetClient.php';
require_once $projectRoot . '/htdocs/services/FleetLivePositionService.php';
require_once $projectRoot . '/htdocs/services/OdometerCenterService.php';

const KMC_PREFIX = 'KMCTEST';

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, mixed $observed = null): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS  {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL  {$name}\n        observat: " . json_encode($observed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

/** SAS simulat: harta carId -> segmente; poate simula o cadere. */
final class FakeSas implements SasOdometerSource
{
    public array $cars = [];
    /** @var array<int, list<array<string, mixed>>> */
    public array $segments = [];
    public bool $down = false;
    public int $calls = 0;

    public function credentialsAvailable(): bool
    {
        return true;
    }

    public function fleetCars(): array
    {
        return $this->cars;
    }

    public function fetchReadings(int $carId, string $from, string $to): array
    {
        $this->calls++;
        if ($this->down) {
            throw new RuntimeException('SAS indisponibil (simulat)');
        }
        $inWindow = array_values(array_filter(
            $this->segments[$carId] ?? [],
            static fn (array $s): bool => $s['dateEnd'] >= str_replace(' ', 'T', $from) && $s['dateStart'] <= str_replace(' ', 'T', $to)
        ));

        return SasFleetOdometerSource::parseTravelSheet($carId, ['segments' => $inWindow]);
    }
}

/** CardOil simulat: alimentarile per cheie de numar. */
final class FakeCardOil implements CardOilOdometerSource
{
    public array $fillups = [];
    public ?string $lastSuccess = null;

    public function fillupsByPlate(?array $plateKeys = null): array
    {
        if ($plateKeys === null) {
            return $this->fillups;
        }
        return array_intersect_key($this->fillups, array_flip($plateKeys));
    }

    public function lastSync(): ?array
    {
        return ['last_success_at' => $this->lastSuccess, 'status' => 'success', 'error_message' => null];
    }
}

function seg(string $start, string $end, float $km, ?float $kmIndex): array
{
    return ['dateStart' => str_replace(' ', 'T', $start), 'dateEnd' => str_replace(' ', 'T', $end), 'distance' => $km, 'kmIndex' => $kmIndex];
}

function fill(int $id, string $at, float $km): array
{
    return ['id' => 'fillup:' . $id, 'fillup_id' => $id, 'measured_at' => $at, 'km' => $km, 'original_value' => $km,
        'corrected' => false, 'vehicle_corrected' => false, 'fuel_type' => 'motorina', 'liters' => 300.0, 'station' => null, 'driver' => null];
}

// =====================================================================
echo "\n1. Reguli pure\n";
// =====================================================================

$readings = [
    ['id' => 'a', 'measured_at' => '2026-10-01 06:00:00', 'segment_started_at' => '2026-10-01 05:00:00', 'km' => 1000.0],
    ['id' => 'b', 'measured_at' => '2026-10-01 10:00:00', 'segment_started_at' => '2026-10-01 09:00:00', 'km' => 1080.0],
];
$r = OdometerCalculator::sasReadingAt($readings, '2026-10-01 08:00:00', 15, '2026-10-01 12:00:00');
check('SAS: parcat intre citiri -> citirea dinainte, exacta', $r['status'] === 'ok' && $r['reading']['id'] === 'a' && $r['precision'] === 'parcat', $r);
$r = OdometerCalculator::sasReadingAt($readings, '2026-10-01 09:30:00', 15, '2026-10-01 12:00:00');
check('SAS: in mers la T0, citire veche -> in afara tolerantei (nu se inventeaza)', $r['status'] === 'in_afara_tolerantei', $r);
$r = OdometerCalculator::sasReadingAt($readings, '2026-10-01 10:10:00', 15, '2026-10-01 10:05:00');
check('SAS: dupa ultima citire, fara acoperire, in toleranta 15 min', $r['status'] === 'ok' && $r['precision'] === 'toleranta', $r);
$r = OdometerCalculator::sasReadingAt($readings, '2026-10-01 05:00:00', 15, '2026-10-01 12:00:00');
check('SAS: nicio citire inainte de T0 -> indisponibil', $r['status'] === 'indisponibil' && $r['reading'] === null, $r);
$r = OdometerCalculator::sasReadingAt($readings, '2026-10-01 08:00:00', 15, '2026-10-01 12:00:00', [
    ['start' => '2026-10-01 07:00:00', 'end' => '2026-10-01 07:40:00', 'km' => 30.0],
]);
check('SAS: deplasare fara kmIndex intre citire si T0 -> nu e considerat parcat', $r['status'] === 'in_afara_tolerantei', $r);

$fills = [fill(1, '2026-09-29 10:00:00', 5000), fill(2, '2026-10-02 10:00:00', 5600)];
$r = OdometerCalculator::cardOilReadingAt($fills, '2026-10-01 08:00:00', 72);
check('CardOil: ultima alimentare <= T0, in varsta maxima', $r['status'] === 'ok' && $r['reading']['id'] === 'fillup:1', $r);
$r = OdometerCalculator::cardOilReadingAt($fills, '2026-10-01 08:00:00', 24);
check('CardOil: alimentare prea veche -> nu stabileste T0 (fara 15 min fortat)', $r['status'] === 'in_afara_tolerantei', $r);
$r = OdometerCalculator::cardOilReadingAt($fills, '2026-09-01 08:00:00', 72);
check('CardOil: fara istoric inainte de T0 -> fara referinta inventata', $r['status'] === 'indisponibil' && $r['reading'] === null, $r);

$series = OdometerCalculator::flagAnomalies([
    fill(1, '2026-10-01 08:00:00', 680850), fill(2, '2026-10-03 08:00:00', 689930),
    fill(3, '2026-10-05 08:00:00', 682690), fill(4, '2026-10-06 08:00:00', 667178),
    fill(5, '2026-10-07 08:00:00', 683420), fill(6, '2026-10-08 08:00:00', 16777215),
    fill(7, '2026-10-09 08:00:00', 683816),
]);
$flags = array_map(static fn (array $x): bool => $x['anomaly'] !== null, $series);
check('Anomalii: tastare prea mare, prea mica si santinela 16777215 marcate; restul valide',
    $flags === [false, true, false, true, false, true, false], $flags);
$clean = OdometerCalculator::flagAnomalies([fill(1, '2026-10-01 08:00:00', 100), fill(2, '2026-10-01 08:00:00', 100), fill(3, '2026-10-02 08:00:00', 900)]);
check('Anomalii: citiri duplicate / normale nu sunt anomalii', array_filter(array_column($clean, 'anomaly')) === [], $clean);

$d = OdometerCalculator::distanceSinceT0(['km' => 1500.0, 'measured_at' => '2026-10-10 00:00:00'], ['km' => 1000.0, 'measured_at' => '2026-10-01 00:00:00']);
check('Distanta: curent − referinta', $d['km'] === 500.0, $d);
$d = OdometerCalculator::distanceSinceT0(['km' => 900.0, 'measured_at' => '2026-10-10 00:00:00'], ['km' => 1000.0, 'measured_at' => '2026-10-01 00:00:00']);
check('Distanta negativa -> anomalie, exclusa', $d['km'] === null && $d['anomaly'] !== null, $d);

check('Total oficial: fara politica nu se alege nicio sursa', OdometerCalculator::officialDistance('', 500.0, 480.0)['km'] === null);
check('Total oficial: politica SAS nu aduna CardOil', OdometerCalculator::officialDistance('sas', 500.0, 480.0)['km'] === 500.0);
check('Total oficial: SAS altfel CardOil', OdometerCalculator::officialDistance('sas_cardoil', null, 480.0) === ['km' => 480.0, 'source' => 'cardoil', 'reason' => null]);

$diff = OdometerCalculator::sourceDifference(1000.0, 980.0);
check('Diferenta surse: diagnostic, sub prag fara alerta', $diff['comparable'] && $diff['diff_km'] === 20.0 && !$diff['alert'], $diff);
$diff = OdometerCalculator::sourceDifference(1000.0, 700.0);
check('Diferenta surse: peste prag -> alerta', $diff['alert'], $diff);

$parsed = SasFleetOdometerSource::parseTravelSheet(77, ['segments' => [
    seg('2026-10-01 05:00:00', '2026-10-01 06:00:00', 80, 1080.4),
    seg('2026-10-01 07:00:00', '2026-10-01 07:30:00', 20, null),
]]);
check('SAS parse: doar segmentele cu kmIndex devin citiri; toate deplasarile raman',
    count($parsed['readings']) === 1 && count($parsed['movements']) === 2 && $parsed['readings'][0]['source_reading_id'] === '77@2026-10-01 06:00:00', $parsed);

// =====================================================================
echo "\n2. Flux pe baza de date (furnizori simulati)\n";
// =====================================================================

$db = get_pdo();
$model = new OdometerCenterModel($db);
$model->ensureSchema();
(new VehicleCapacityCategoryModel($db))->ensureSchema();

function cleanup(PDO $db, ?string $since = null): void
{
    if ($since !== null) {
        // Randurile de audit ale schimbarilor de politica facute de test (fara vehicul, fara utilizator).
        $db->prepare("DELETE FROM vehicle_odometer_audit WHERE vehicle_id IS NULL AND user_id IS NULL AND action = 'set_policy' AND created_at >= :s")
            ->execute([':s' => $since]);
    }
    $ids = $db->query("SELECT id FROM vehicule WHERE nr_inmatriculare LIKE 'KMCTEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids !== []) {
        $in = implode(',', array_map('intval', $ids));
        $db->exec("DELETE FROM vehicle_odometer_audit WHERE vehicle_id IN ({$in})");
        $db->exec("DELETE FROM vehicle_odometer_baselines WHERE vehicle_id IN ({$in})");
        $db->exec("DELETE FROM vehicle_odometer_readings WHERE vehicle_id IN ({$in})");
        $db->exec("DELETE FROM vehicle_odometer_sources WHERE vehicle_id IN ({$in})");
        $db->exec("DELETE FROM vehicule_cuplaje WHERE tractor_id IN ({$in}) OR semiremorca_id IN ({$in})");
        $db->exec("DELETE FROM vehicule WHERE id IN ({$in})");
    }
    $db->exec("DELETE FROM vehicule_categorii_capacitate WHERE nume LIKE 'KMCTEST%'");
}

$originalPolicy = $model->getSettings()['politica_total'];
$testStartedAt = date('Y-m-d H:i:s');
$realSourcesBefore = $db->query("SELECT COUNT(*) FROM vehicle_odometer_sources WHERE enabled = 1")->fetchColumn();
cleanup($db);

try {
    $now = date('Y-m-d H:i:s');
    $db->prepare("INSERT INTO vehicule_categorii_capacitate (nume, ordine_afisare, activ, created_at, updated_at) VALUES ('KMCTEST CAT', 9990, 1, :n, :n2)")
        ->execute([':n' => $now, ':n2' => $now]);
    $categoryId = (int) $db->lastInsertId();

    $insertVehicle = $db->prepare(
        "INSERT INTO vehicule (nr_inmatriculare, marca, model, an_fabricatie, serie_sasiu, tip_vehicul, status, capacitate_transport, categorie_capacitate_id, poza_stocata, created_at, updated_at)
         VALUES (:p, :m, :mo, 2020, :s, :t, 'activ', :c, :cat, :poza, :n, :n2)"
    );
    $vehicle = static function (string $plate, string $type, ?float $capacity, ?int $category, ?string $photo = null) use ($insertVehicle, $db, $now): int {
        $insertVehicle->execute([':p' => $plate, ':m' => 'TEST', ':mo' => $type, ':s' => substr(str_pad($plate, 17, '0'), 0, 17),
            ':t' => $type, ':c' => $capacity, ':cat' => $category, ':poza' => $photo, ':n' => $now, ':n2' => $now]);
        return (int) $db->lastInsertId();
    };
    $tractor = $vehicle('KMCTEST-T1', 'cap_tractor', null, null, 'kmctest-nu-exista.jpg');
    $trailer = $vehicle('KMCTEST-S1', 'semiremorca_primar', 24.0, $categoryId);
    $truck = $vehicle('KMCTEST-C1', 'camion', 9.5, $categoryId);
    $db->prepare('INSERT INTO vehicule_cuplaje (tractor_id, semiremorca_id, activ, data_start, created_at, updated_at) VALUES (:t, :s, 1, :n, :n2, :n3)')
        ->execute([':t' => $tractor, ':s' => $trailer, ':n' => $now, ':n2' => $now, ':n3' => $now]);

    $sas = new FakeSas();
    $sas->cars = [
        ['sas_vehicle_id' => 990001, 'registration' => 'KMCTEST-T1', 'local_vehicle_id' => $tractor, 'disabled' => false],
        ['sas_vehicle_id' => 990002, 'registration' => 'KMCTEST-C1', 'local_vehicle_id' => $truck, 'disabled' => false],
    ];
    $sas->segments[990001] = [
        seg('2026-09-29 10:00:00', '2026-09-29 11:00:00', 60, 100000),
        seg('2026-10-01 05:00:00', '2026-10-01 06:00:00', 80, 100080),
        seg('2026-10-05 07:00:00', '2026-10-05 12:00:00', 400, 100480),
    ];
    $sas->segments[990002] = [
        seg('2026-10-01 05:00:00', '2026-10-01 06:00:00', 50, 50050),
        seg('2026-10-06 09:00:00', '2026-10-06 11:00:00', 150, 50200),
    ];
    $card = new FakeCardOil();
    $card->lastSuccess = '2026-10-07 23:00:00';
    $card->fillups['KMCTEST' . 'T1'] = [fill(9001, '2026-09-30 20:00:00', 99500), fill(9002, '2026-10-05 13:00:00', 99890)];
    $card->fillups['KMCTESTC1'] = [];
    unset($card->fillups['KMCTESTC1']);

    $nowTs = strtotime('2026-10-08 08:00:00');
    // Sincronizarea se restrange la vehiculele de test: maparile reale raman neatinse.
    $testIds = [$tractor, $trailer, $truck];
    $service = new OdometerCenterService($db, $sas, $card, null, $nowTs);

    // --- sincronizare idempotenta
    $first = $service->syncSas(50, 0, $testIds);
    $second = $service->syncSas(50, 0, $testIds);
    $count = (int) $db->query("SELECT COUNT(*) FROM vehicle_odometer_readings WHERE vehicle_id IN ({$tractor}, {$truck})")->fetchColumn();
    check('Sync SAS: citirile intra o singura data (rulare repetata = 0 noi)', $first['new_readings'] === 5 && $second['new_readings'] === 0 && $count === 5, [$first, $second, $count]);
    check('Sync SAS: maparea CardOil creata dupa numar', (bool) $db->query("SELECT 1 FROM vehicle_odometer_sources WHERE vehicle_id = {$tractor} AND provider_code = 'cardoil'")->fetchColumn());

    $findUnit = static function (array $dashboard, int $vehicleId): ?array {
        foreach ($dashboard['units'] as $unit) {
            foreach ($unit['components'] as $component) {
                if ($component['id'] === $vehicleId) {
                    return $unit;
                }
            }
        }
        return null;
    };
    $countUnits = static function (array $dashboard, int $vehicleId): int {
        $n = 0;
        foreach ($dashboard['units'] as $unit) {
            foreach ($unit['components'] as $component) {
                $n += $component['id'] === $vehicleId ? 1 : 0;
            }
        }
        return $n;
    };

    $dash = $service->buildDashboard();
    $assembly = $findUnit($dash, $tractor);
    [$t, $s] = $assembly['components'];
    check('Ansamblu: un card cu doua componente, identitati distincte', $assembly['kind'] === 'ansamblu' && $t['id'] === $tractor && $s['id'] === $trailer, $assembly['kind']);
    check('Ansamblu: semiremorca nu apare si separat (fara dublare)', $countUnits($dash, $trailer) === 1 && $countUnits($dash, $tractor) === 1);
    check('Ansamblu: patru sloturi sursa/componenta', isset($t['sources']['sas'], $t['sources']['cardoil'], $s['sources']['sas'], $s['sources']['cardoil']));
    check('Semiremorca: fara citiri fabricate (nici SAS, nici CardOil)', !$s['sources']['sas']['available'] && !$s['sources']['cardoil']['available']
        && $s['sources']['sas']['current'] === null && $s['sources']['cardoil']['current'] === null, $s['sources']);
    check('Tractor: SAS curent = ultimul kmIndex, cu ora masurarii', $t['sources']['sas']['current']['km'] === 100480.0 && $t['sources']['sas']['current']['measured_at'] === '2026-10-05 12:00:00', $t['sources']['sas']['current']);
    check('Tractor: CardOil curent = ultima alimentare, cu ora alimentarii', $t['sources']['cardoil']['current']['km'] === 99890.0 && $t['sources']['cardoil']['current']['measured_at'] === '2026-10-05 13:00:00');
    check('CardOil e prezentat pe eveniment, nu "actual"', $t['sources']['cardoil']['freshness'] === 'eveniment', $t['sources']['cardoil']['freshness']);
    check('Categoria ansamblului = a semiremorcii; capacitatea reala = a semiremorcii', $assembly['category_id'] === $categoryId && $assembly['capacity_t'] === 24.0, [$assembly['category_id'], $assembly['capacity_t']]);
    check('Capacitatea tractorului nu e luata din categorie', $t['capacity_t'] === null && $t['category_id'] === null);
    check('Poza lipsa pe disc -> placeholder (null), fara imagini inventate', $t['photo'] === null);
    $truckUnit = $findUnit($dash, $truck);
    check('Camion: categoria si capacitatea lui (9,5 t), distincte de numele categoriei', $truckUnit['category_id'] === $categoryId && $truckUnit['capacity_t'] === 9.5);
    $orders = array_column($dash['categories'], 'order');
    $sorted = $orders;
    sort($sorted);
    check('Categoriile vin in ordinea configurata (ordine_afisare), inclusiv cea de test', $orders === $sorted && in_array($categoryId, array_column($dash['categories'], 'id'), true), $orders);

    // --- T0: cautare independenta pe surse si salvare versionata
    $lookup = $service->lookupT0([$tractor, $trailer], '2026-10-01 08:00');
    check('T0: SAS gaseste citirea de la 06:00 (parcat pana la T0)', $lookup[$tractor]['sas']['status'] === 'ok' && $lookup[$tractor]['sas']['reading']['km'] === 100080.0, $lookup[$tractor]['sas']);
    check('T0: CardOil gaseste alimentarea de la 30.09 20:00, cu ora ei reala', $lookup[$tractor]['cardoil']['status'] === 'ok' && $lookup[$tractor]['cardoil']['reading']['measured_at'] === '2026-09-30 20:00:00', $lookup[$tractor]['cardoil']);
    check('T0: semiremorca -> fara sursa pentru ambele, fara valori', $lookup[$trailer]['sas']['status'] === 'fara_sursa' && $lookup[$trailer]['cardoil']['status'] === 'fara_sursa');

    $saved = $service->saveT0([$tractor, $trailer], '2026-10-01 08:00', null, 'test');
    check('T0: tractorul primeste versiunea 1; semiremorca fara surse e sarita', $saved[$tractor]['version'] === 1 && $saved[$trailer]['version'] === null, $saved);
    $truckLookup = $service->lookupT0([$truck], '2026-10-01 08:00');
    check('T0 camion: CardOil lipsa nu blocheaza SAS', $truckLookup[$truck]['sas']['status'] === 'ok' && $truckLookup[$truck]['cardoil']['status'] === 'fara_sursa', $truckLookup[$truck]);
    $service->saveT0([$truck], '2026-10-01 08:00', null, null);
    $truckBaselines = $db->query("SELECT provider_code FROM vehicle_odometer_baselines WHERE vehicle_id = {$truck}")->fetchAll(PDO::FETCH_COLUMN);
    check('T0 camion: nu se salveaza referinta CardOil inventata', $truckBaselines === ['sas'], $truckBaselines);

    $dash = $service->buildDashboard();
    [$t] = $findUnit($dash, $tractor)['components'];
    check('Distanta SAS de la T0 = 100480 − 100080', $t['sources']['sas']['distance']['km'] === 400.0, $t['sources']['sas']['distance']);
    check('Distanta CardOil de la T0 = 99890 − 99500', $t['sources']['cardoil']['distance']['km'] === 390.0, $t['sources']['cardoil']['distance']);
    // SAS intre aceleasi doua momente: 100000 (parcat 29.09 11:00 -> 30.09 20:00) -> 100480 = 480 km; CardOil 390 km.
    check('Diferenta SAS − CardOil pe aceeasi fereastra (30.09 20:00 -> 05.10 13:00)', $t['comparison']['comparable'] && $t['comparison']['diff_km'] === 90.0 && !$t['comparison']['alert'], $t['comparison']);

    // --- totaluri fara dubla numarare
    $service->savePolicy('', null);
    $dash = $service->buildDashboard();
    check('Fara politica: total oficial null, chiar daca exista km pe surse', $dash['kpis']['official_km'] === null);
    $service->savePolicy('sas', null);
    $dash = $service->buildDashboard();
    $expected = 0.0;
    $counted = [];
    foreach ($dash['units'] as $unit) {
        foreach ($unit['components'] as $component) {
            if ($component['status'] === 'activ' && $component['monitored'] && $component['official']['km'] !== null) {
                $expected += $component['official']['km'];
                $counted[] = $component['id'];
            }
        }
    }
    check('Politica SAS: totalul = suma per vehicul fizic, o singura data', abs((float) $dash['kpis']['official_km'] - round($expected, 1)) < 0.01 && count($counted) === count(array_unique($counted)), [$dash['kpis']['official_km'], $expected]);
    [$t, $s] = $findUnit($dash, $tractor)['components'];
    check('Politica SAS: tractorul contribuie 400 (nu 400 + 390), semiremorca nimic', $t['official']['km'] === 400.0 && $s['official']['km'] === null);

    // --- T0 schimbat: revizia veche ramane
    $service->saveT0([$tractor], '2026-10-05 12:30', null, 'mutare T0');
    $versions = $db->query("SELECT baseline_version, provider_code, is_active, baseline_value_km FROM vehicle_odometer_baselines WHERE vehicle_id = {$tractor} ORDER BY baseline_version, provider_code")->fetchAll(PDO::FETCH_ASSOC);
    $active = array_values(array_filter($versions, static fn (array $v): bool => (int) $v['is_active'] === 1));
    check('T0 schimbat: v1 pastrata inactiva, v2 activa', count($versions) === 4 && count($active) === 2 && (int) $active[0]['baseline_version'] === 2, $versions);
    $audit = (int) $db->query("SELECT COUNT(*) FROM vehicle_odometer_audit WHERE vehicle_id = {$tractor} AND action = 'set_t0'")->fetchColumn();
    check('Audit: fiecare salvare T0 are un rand', $audit === 2, $audit);

    // --- SAS cade: ultima citire pastrata, fara "actual"
    $sas->down = true;
    $down = $service->syncSas(50, 0, $testIds);
    $dash = $service->buildDashboard();
    [$t] = $findUnit($dash, $tractor)['components'];
    check('Cadere SAS: rularea se opreste curat dupa erori', $down['errors'] >= 1 && $down['updated'] === 0, $down);
    check('Cadere SAS: ultima citire pastrata, stare "eroare"', $t['sources']['sas']['current']['km'] === 100480.0 && $t['sources']['sas']['freshness'] === 'eroare', $t['sources']['sas']['freshness']);
    $sas->down = false;

    // --- anomalie SAS
    $sas->segments[990002][] = seg('2026-10-08 07:00:00', '2026-10-08 07:30:00', 10, 49000);
    $service->syncSas(50, 0, $testIds);
    $dash = $service->buildDashboard();
    [$c] = $findUnit($dash, $truck)['components'];
    check('Anomalie SAS (odometru care scade) marcata, citirea curenta ramane cea valida', $c['sources']['sas']['current']['km'] === 50200.0 && count($c['sources']['sas']['anomalies']) === 1, $c['sources']['sas']);
    $flagged = $db->query("SELECT validation_status FROM vehicle_odometer_readings WHERE vehicle_id = {$truck} AND odometer_km = 49000")->fetchColumn();
    check('Anomalie SAS salvata pe citire (validation_status)', $flagged === 'anomaly', $flagged);

    // --- T0 in viitor respins
    $rejected = false;
    try {
        $service->lookupT0([$tractor], '2026-12-01 08:00');
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    check('T0 in viitor respins', $rejected);

    // --- ACL
    $registry = permission_registry();
    $module = $registry->module('centru_kilometraj');
    check('ACL: modulul e in registru cu actiunile view/history/t0/settings/audit', $module !== null && array_keys($module['actions']) === ['view', 'history', 't0', 'settings', 'audit'], $module['actions'] ?? null);
    check('ACL: t0_save cere dreptul t0, save_policy cere settings', $registry->endpointAction('centru_kilometraj', 't0_save') === 't0' && $registry->endpointAction('centru_kilometraj', 'save_policy') === 'settings');
    check('ACL: operator neconfigurat vede pagina, dar nu poate seta T0', access_evaluate('operator', false, [], 'centru_kilometraj', 'view') && !access_evaluate('operator', false, [], 'centru_kilometraj', 't0'));
    check('ACL: drept configurat fara t0 -> refuzat', !access_evaluate('operator', true, ['centru_kilometraj' => ['view' => true]], 'centru_kilometraj', 't0'));
    check('ACL: drept t0 acordat explicit -> permis', access_evaluate('operator', true, ['centru_kilometraj' => ['view' => true, 't0' => true]], 'centru_kilometraj', 't0'));
} finally {
    cleanup($db, $testStartedAt);
    $model->saveSetting('politica_total', $originalPolicy, null);
}
$realSourcesAfter = $db->query("SELECT COUNT(*) FROM vehicle_odometer_sources WHERE enabled = 1")->fetchColumn();
check('Siguranta: maparile reale active au ramas neschimbate', $realSourcesBefore === $realSourcesAfter, [$realSourcesBefore, $realSourcesAfter]);

echo "\n{$passed} trecute, {$failed} esuate\n";
exit($failed > 0 ? 1 : 0);
