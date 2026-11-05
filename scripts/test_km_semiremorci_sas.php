<?php
declare(strict_types=1);

/**
 * Teste: km semiremorcilor din SAS-ul capului tractor (TrailerSasKmService) si
 * oprirea km din curse pentru semiremorci dupa comutare (DispecerCurseModel).
 *
 *   php scripts/test_km_semiremorci_sas.php
 *
 * Fixturi KMSEMI-* (sterse la final); data de comutare reala e salvata si refacuta.
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/config/database.php';
require_once $projectRoot . '/htdocs/includes/helpers.php';
require_once $projectRoot . '/htdocs/models/BaseModel.php';
require_once $projectRoot . '/htdocs/services/SasFleetClient.php';
require_once $projectRoot . '/htdocs/services/FleetLivePositionService.php';
require_once $projectRoot . '/htdocs/services/TrailerSasKmService.php';
require_once $projectRoot . '/htdocs/models/DispecerCurseModel.php';

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
    echo "  FAIL  {$name}\n        observat: " . json_encode($observed, JSON_UNESCAPED_UNICODE) . "\n";
}

final class FakeTrailerSas implements SasOdometerSource
{
    /** @var array<int, list<array{start:string, end:string, km:float}>> */
    public array $movements = [];
    public bool $down = false;
    public int $calls = 0;

    public function credentialsAvailable(): bool
    {
        return true;
    }

    public function fleetCars(): array
    {
        return [];
    }

    public function fetchReadings(int $carId, string $from, string $to): array
    {
        $this->calls++;
        if ($this->down) {
            throw new RuntimeException('SAS indisponibil (simulat)');
        }
        $in = array_values(array_filter($this->movements[$carId] ?? [], static fn (array $m): bool => $m['end'] > $from && $m['start'] < $to));

        return ['readings' => [], 'movements' => $in];
    }
}

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sas = new FakeTrailerSas();
// Comutare la 01.11 00:00; „acum” = 04.11 10:00 -> zilele inchise 01, 02, 03.11.
$service = new TrailerSasKmService($db, $sas, null, strtotime('2026-11-04 10:00:00'));
$savedCutover = TrailerSasKmService::cutover($db);

$cleanup = static function () use ($db): void {
    $ids = $db->query("SELECT id FROM vehicule WHERE nr_inmatriculare LIKE 'KMSEMI-%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids !== []) {
        $in = implode(',', array_map('intval', $ids));
        $db->exec("DELETE FROM semiremorci_km_sas_log WHERE vehicle_id IN ({$in})");
        $db->exec("DELETE FROM semiremorci_km_sas WHERE vehicle_id IN ({$in})");
        $db->exec("DELETE FROM vehicle_odometer_sources WHERE vehicle_id IN ({$in})");
        $db->exec("DELETE FROM vehicule_cuplaje WHERE tractor_id IN ({$in}) OR semiremorca_id IN ({$in})");
        $db->exec("DELETE FROM vehicule WHERE id IN ({$in})");
    }
};
$cleanup();

try {
    $db->prepare("REPLACE INTO km_center_setari (setting_key, setting_value) VALUES ('semiremorci_sas_de_la', '2026-11-01 00:00:00')")->execute();
    $now = date('Y-m-d H:i:s');
    $insert = $db->prepare(
        "INSERT INTO vehicule (nr_inmatriculare, marca, model, an_fabricatie, serie_sasiu, tip_vehicul, status, km_bord, km_revizie, created_at, updated_at)
         VALUES (:p, 'TEST', :t, 2020, :s, :t2, 'activ', :km, :kr, :n, :n2)"
    );
    $vehicle = static function (string $plate, string $type, int $km, int $kmRevizie) use ($insert, $db, $now): int {
        $insert->execute([':p' => $plate, ':t' => $type, ':s' => substr(str_pad($plate, 17, '0'), 0, 17), ':t2' => $type,
            ':km' => $km, ':kr' => $kmRevizie, ':n' => $now, ':n2' => $now]);
        return (int) $db->lastInsertId();
    };
    $tractorA = $vehicle('KMSEMI-TA', 'cap_tractor', 500000, 30000);
    $tractorB = $vehicle('KMSEMI-TB', 'cap_tractor', 700000, 30000);
    $trailer = $vehicle('KMSEMI-S1', 'semiremorca_primar', 200000, 10000);
    $db->prepare("INSERT INTO vehicle_odometer_sources (vehicle_id, provider_code, external_provider_vehicle_id, enabled, created_at, updated_at) VALUES (:v, 'sas', :c, 1, :n, :n2)")
        ->execute([':v' => $tractorA, ':c' => '880001', ':n' => $now, ':n2' => $now]);
    $db->prepare("INSERT INTO vehicle_odometer_sources (vehicle_id, provider_code, external_provider_vehicle_id, enabled, created_at, updated_at) VALUES (:v, 'sas', :c, 1, :n, :n2)")
        ->execute([':v' => $tractorB, ':c' => '880002', ':n' => $now, ':n2' => $now]);
    // Cuplata cu A pana la 02.11 12:00, apoi cu B.
    $couple = $db->prepare('INSERT INTO vehicule_cuplaje (tractor_id, semiremorca_id, activ, data_start, data_end, created_at, updated_at) VALUES (:t, :s, :a, :ds, :de, :n, :n2)');
    $couple->execute([':t' => $tractorA, ':s' => $trailer, ':a' => 0, ':ds' => '2026-10-01 08:00:00', ':de' => '2026-11-02 12:00:00', ':n' => $now, ':n2' => $now]);
    $couple->execute([':t' => $tractorB, ':s' => $trailer, ':a' => 1, ':ds' => '2026-11-02 12:00:00', ':de' => null, ':n' => $now, ':n2' => $now]);

    $sas->movements[880001] = [
        ['start' => '2026-10-31 20:00:00', 'end' => '2026-11-01 02:00:00', 'km' => 300.0], // jumatate inainte de comutare
        ['start' => '2026-11-01 08:00:00', 'end' => '2026-11-01 12:00:00', 'km' => 350.4],
        ['start' => '2026-11-02 10:00:00', 'end' => '2026-11-02 14:00:00', 'km' => 200.0], // jumatate dupa decuplare
        ['start' => '2026-11-03 08:00:00', 'end' => '2026-11-03 10:00:00', 'km' => 999.0], // A, dar semiremorca e pe B
    ];
    $sas->movements[880002] = [
        ['start' => '2026-11-02 15:00:00', 'end' => '2026-11-02 18:00:00', 'km' => 250.3],
        ['start' => '2026-11-03 06:00:00', 'end' => '2026-11-03 09:00:00', 'km' => 260.0],
        ['start' => '2026-11-04 06:00:00', 'end' => '2026-11-04 09:00:00', 'km' => 111.0], // azi: zi neinchisa
    ];

    $kmOf = static function (int $id) use ($db): array {
        $row = $db->query('SELECT km_bord, km_revizie FROM vehicule WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        return ['km_bord' => (int) $row['km_bord'], 'km_revizie' => (int) $row['km_revizie']];
    };

    echo "\n1. Km din SAS pe ferestrele de cuplare\n";
    $stats = $service->run(60, [$trailer]);
    // A: 100 (2 din cele 6 ore sunt dupa 01.11 00:00) + 350,4 + 100 (pana la 12:00) = 550,4; B: 250,3 + 260 = 510,3 -> 1.060,7
    $state = $db->query('SELECT * FROM semiremorci_km_sas WHERE vehicle_id = ' . $trailer)->fetch(PDO::FETCH_ASSOC);
    check('Total = 1.060,7 km (A 550,4 + B 510,3), fara ziua de azi si fara km lui A dupa decuplare', abs((float) $state['km_total'] - 1060.7) < 0.05, $state);
    check('km_bord creste cu partea intreaga (1.060), restul 0,7 se pastreaza', $kmOf($trailer)['km_bord'] === 201060 && abs((float) $state['km_rest'] - 0.7) < 0.001, [$kmOf($trailer), $state['km_rest']]);
    check('km_revizie (km pana la revizie) scade cu aceiasi km', $kmOf($trailer)['km_revizie'] === 10000 - 1060, $kmOf($trailer));
    check('Avansat pana azi 00:00', (string) $state['synced_until'] === '2026-11-04 00:00:00', $state['synced_until']);
    check('Capetele tractor nu se ating', $kmOf($tractorA)['km_bord'] === 500000 && $kmOf($tractorB)['km_bord'] === 700000);

    echo "\n2. Idempotenta si erori\n";
    $before = $kmOf($trailer);
    $calls = $sas->calls;
    $service->run(60, [$trailer]);
    check('A doua rulare in aceeasi zi: nimic adaugat, nicio cerere SAS', $kmOf($trailer) === $before && $sas->calls === $calls, [$kmOf($trailer), $sas->calls - $calls]);

    $later = new TrailerSasKmService($db, $sas, null, strtotime('2026-11-05 03:00:00'));
    $sas->down = true;
    $stats = $later->run(60, [$trailer]);
    $state = $db->query('SELECT * FROM semiremorci_km_sas WHERE vehicle_id = ' . $trailer)->fetch(PDO::FETCH_ASSOC);
    check('SAS cazut: nimic adaugat, pozitia ramane, stare „eroare”', $kmOf($trailer) === $before && (string) $state['synced_until'] === '2026-11-04 00:00:00' && $state['last_status'] === 'eroare', $state);
    $sas->down = false;
    $later->run(60, [$trailer]);
    $state = $db->query('SELECT * FROM semiremorci_km_sas WHERE vehicle_id = ' . $trailer)->fetch(PDO::FETCH_ASSOC);
    check('Reluat dupa eroare: ziua de 04.11 adaugata o data (+111)', $kmOf($trailer)['km_bord'] === 201171 && (string) $state['synced_until'] === '2026-11-05 00:00:00', [$kmOf($trailer), $state]);

    echo "\n3. Dispecer: cursele dupa comutare nu mai adauga km semiremorcii\n";
    $model = new DispecerCurseModel($db);
    $method = new ReflectionMethod(DispecerCurseModel::class, 'withoutSasTrailers');
    $method->setAccessible(true);
    $distribution = [$tractorB => ['km_bord' => 500, 'km_revizie' => 500], $trailer => ['km_bord' => 500, 'km_revizie' => 500]];
    $after = $method->invoke($model, $distribution, ['data_inceput' => '2026-11-03', 'data_cursa' => '2026-11-03']);
    check('Cursa din 03.11: doar capul tractor primeste km', array_keys($after) === [$tractorB], array_keys($after));
    $model2 = new DispecerCurseModel($db);
    $before = $method->invoke($model2, $distribution, ['data_inceput' => '2026-10-30', 'data_cursa' => '2026-10-30']);
    check('Cursa din 30.10 (inainte de comutare): semiremorca primeste km ca pana acum', array_keys($before) === [$tractorB, $trailer], array_keys($before));
} finally {
    $cleanup();
    if ($savedCutover !== null) {
        $db->prepare("REPLACE INTO km_center_setari (setting_key, setting_value) VALUES ('semiremorci_sas_de_la', :v)")->execute([':v' => $savedCutover]);
    } else {
        $db->exec("DELETE FROM km_center_setari WHERE setting_key = 'semiremorci_sas_de_la'");
    }
}

echo "\n{$passed} trecute, {$failed} esuate\n";
exit($failed > 0 ? 1 : 0);
