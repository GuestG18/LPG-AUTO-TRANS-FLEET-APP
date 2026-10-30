<?php
declare(strict_types=1);

/**
 * Teste pentru asocierea curselor in banda "Curse in desfasurare (GPS live)".
 *
 *   php scripts/test_live_gps_association.php
 *
 * Partea 1 (fara baza de date): DispecerCurseController::liveVehicleState —
 * cursa deschisa, miscare noua dupa ultima cursa, pozitie veche, inainte de sfarsit.
 * Partea 2 (baza de date, intr-o tranzactie anulata la final, pe vehicule fara curse):
 * curse deschise fara limita de 3 zile, inchise, sterse/restaurate, mai multe deschise,
 * faze pe alt vehicul, reperul "ultima cursa incheiata". Nu atinge SAS si nu ramane nimic in BD.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/InactiveResourceApprovalModel.php';
require_once $root . '/htdocs/models/DispecerCurseModel.php';
require_once $root . '/htdocs/services/InactiveResourceStatusService.php';
require_once $root . '/htdocs/controllers/DispecerCurseController.php';

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
        echo "\033[31m  FAIL\033[0m  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

echo "Partea 1: starea vehiculului\n";
$now = time();
$state = [DispecerCurseController::class, 'liveVehicleState'];
check('cursa deschisa + in miscare -> trip', $state(true, true, $now, null) === 'trip');
check('cursa deschisa + oprit -> stale (asocierea ramane)', $state(true, false, $now, null) === 'stale');
check('fara cursa deschisa, miscare dupa ultima cursa -> no_trip', $state(false, true, $now, $now - 3600) === 'no_trip');
check('fara curse deloc, miscare proaspata -> no_trip', $state(false, true, $now, null) === 'no_trip');
check('miscare inainte de sfarsitul ultimei curse -> before_last_end', $state(false, true, $now, $now + 3600) === 'before_last_end');
check('pozitie veche / oprit fara cursa -> stale (fara alerta)', $state(false, false, $now - 7200, $now - 86400) === 'stale');

echo "\nPartea 2: interogarile pe baza de date\n";
$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Constructorul ruleaza DDL (ensureSchema) -> inaintea tranzactiei, altfel COMMIT implicit.
$controller = new DispecerCurseController($db);
$call = static function (string $method, ...$args) use ($controller) {
    return (new ReflectionMethod(DispecerCurseController::class, $method))->invoke($controller, ...$args);
};

check('formularul nou porneste cu data de sfarsit goala', ($call('defaultRaceFormData')['data_sfarsit'] ?? null) === '');

$vehicles = $db->query(
    'SELECT v.id FROM vehicule v
     WHERE NOT EXISTS (SELECT 1 FROM curse_dispecer c WHERE c.vehicle_id = v.id)
       AND NOT EXISTS (SELECT 1 FROM curse_segmente s WHERE s.vehicle_id = v.id)
     ORDER BY v.id LIMIT 2'
)->fetchAll(PDO::FETCH_COLUMN);
if (count($vehicles) < 2) {
    fwrite(STDERR, "Lipsesc doua vehicule fara curse pentru test.\n");
    exit(1);
}
[$vehicleA, $vehicleB] = array_map('intval', $vehicles);

$db->beginTransaction();
try {
    $insert = $db->prepare(
        "INSERT INTO curse_dispecer (vehicle_id, tip_transport, data_cursa, data_inceput, data_sfarsit,
            ora_inceput, ora_sfarsit, pret_tarifare, total_facturare, observatii, created_at, updated_at)
         VALUES (:vehicle_id, 'primar', :d0, :d1, :d2, :t1, :t2, 0, 0, 'TEST_LIVE_GPS', NOW(), NOW())"
    );
    $addRace = static function (int $vehicleId, string $startDate, ?string $startTime, string $endDate, ?string $endTime) use ($insert, $db): int {
        $insert->execute(['vehicle_id' => $vehicleId, 'd0' => $startDate, 'd1' => $startDate, 'd2' => $endDate, 't1' => $startTime, 't2' => $endTime]);
        return (int) $db->lastInsertId();
    };
    $ids = static fn (array $races): array => array_map(static fn (array $race): int => (int) $race['id'], $races);
    $inTx = static fn (): bool => $db->inTransaction();

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $tenDaysAgo = date('Y-m-d', strtotime('-10 days'));

    // Fara curse: nimic deschis, niciun reper.
    check('vehicul fara curse: nicio cursa deschisa', ($call('openRacesByVehicleId', [$vehicleA])[$vehicleA] ?? []) === []);
    check('vehicul fara curse: fara sfarsit de referinta', !isset($call('lastClosedEndByVehicleId', [$vehicleA])[$vehicleA]));

    // Cursa inchisa ieri 18:00 -> nu e deschisa, dar e reperul.
    $closed = $addRace($vehicleA, $yesterday, '08:00:00', $yesterday, '18:00:00');
    $open = $call('openRacesByVehicleId', [$vehicleA])[$vehicleA] ?? [];
    check('cursa cu data + ora de sfarsit nu e deschisa', !in_array($closed, $ids($open), true));
    $lastEnd = $call('lastClosedEndByVehicleId', [$vehicleA])[$vehicleA]['ts'] ?? null;
    check('reperul = sfarsitul ultimei curse incheiate', $lastEnd === strtotime($yesterday . ' 18:00:00'));

    // Cursa inchisa azi la o ora trecuta NU mai ramane asociata pana la miezul noptii.
    $closedToday = $addRace($vehicleA, $today, '00:01:00', $today, '00:02:00');
    check('cursa terminata azi nu ramane deschisa', !in_array($closedToday, $ids($call('openRacesByVehicleId', [$vehicleA])[$vehicleA] ?? []), true));
    $db->prepare('DELETE FROM curse_dispecer WHERE id = ?')->execute([$closedToday]);

    // Cursa deschisa (fara ora de sfarsit; data de sfarsit = inceputul, ca la salvare) de acum 10 zile.
    $oldOpen = $addRace($vehicleA, $tenDaysAgo, '07:00:00', $tenDaysAgo, null);
    $open = $call('openRacesByVehicleId', [$vehicleA])[$vehicleA] ?? [];
    check('cursa deschisa de 10 zile e asociata (fara limita de 3 zile)', $ids($open) === [$oldOpen], json_encode($ids($open)));
    check('cursa deschisa nu muta reperul ultimei curse incheiate', ($call('lastClosedEndByVehicleId', [$vehicleA])[$vehicleA]['ts'] ?? null) === $lastEnd);

    // A doua cursa deschisa -> ambele, cea mai recenta prima (dispecerul alege).
    $secondOpen = $addRace($vehicleA, $yesterday, '09:00:00', $yesterday, null);
    $open = $call('openRacesByVehicleId', [$vehicleA])[$vehicleA] ?? [];
    check('doua curse deschise -> ambele returnate', $ids($open) === [$secondOpen, $oldOpen], json_encode($ids($open)));

    // Stergere logica / restaurare.
    $db->prepare('UPDATE curse_dispecer SET deleted_at = NOW() WHERE id = ?')->execute([$secondOpen]);
    check('cursa stearsa dispare din asociere', $ids($call('openRacesByVehicleId', [$vehicleA])[$vehicleA] ?? []) === [$oldOpen]);
    $db->prepare('UPDATE curse_dispecer SET deleted_at = NULL WHERE id = ?')->execute([$secondOpen]);
    check('cursa restaurata revine in asociere', in_array($secondOpen, $ids($call('openRacesByVehicleId', [$vehicleA])[$vehicleA] ?? []), true));

    // Editare: completarea orei de sfarsit inchide cursa; golirea o redeschide.
    $db->prepare("UPDATE curse_dispecer SET ora_sfarsit = '20:00:00' WHERE id = ?")->execute([$secondOpen]);
    check('ora de sfarsit completata -> nu mai e deschisa', !in_array($secondOpen, $ids($call('openRacesByVehicleId', [$vehicleA])[$vehicleA] ?? []), true));
    check('si devine reperul ultimei curse incheiate', ($call('lastClosedEndByVehicleId', [$vehicleA])[$vehicleA]['ts'] ?? null) === strtotime($yesterday . ' 20:00:00'));
    $db->prepare('UPDATE curse_dispecer SET ora_sfarsit = NULL WHERE id = ?')->execute([$secondOpen]);
    check('ora de sfarsit golita -> din nou deschisa', in_array($secondOpen, $ids($call('openRacesByVehicleId', [$vehicleA])[$vehicleA] ?? []), true));

    // Cursa deschisa fara ora de inceput: ramane deschisa, dar marcata incompleta.
    $noStart = $addRace($vehicleB, $today, null, $today, null);
    $openB = $call('openRacesByVehicleId', [$vehicleB])[$vehicleB] ?? [];
    check('cursa fara ora de inceput e deschisa', $ids($openB) === [$noStart]);
    check('si e marcata "fara ora de inceput"', ($call('liveRacePayload', $openB[0])['start_time_missing'] ?? false) === true);
    $db->prepare('DELETE FROM curse_dispecer WHERE id = ?')->execute([$noStart]);

    // Cursa cu faze: faza 1 pe A (incheiata), faza 2 pe B (deschisa).
    $phased = $addRace($vehicleA, $today, '01:00:00', $today, null);
    $segment = $db->prepare(
        "INSERT INTO curse_segmente (cursa_id, ordine, vehicle_id, data_inceput, ora_inceput, data_sfarsit, ora_sfarsit, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );
    $segment->execute([$phased, 1, $vehicleA, $today, '01:00:00', $today, '02:00:00']);
    $segment->execute([$phased, 2, $vehicleB, $today, '03:00:00', null, null]);
    check('cursa cu faze: asociata vehiculului fazei deschise', in_array($phased, $ids($call('openRacesByVehicleId', [$vehicleB])[$vehicleB] ?? []), true));
    check('cursa cu faze: nu mai e asociata vehiculului primei faze', !in_array($phased, $ids($call('openRacesByVehicleId', [$vehicleA])[$vehicleA] ?? []), true));
    check('faza incheiata pe A muta reperul lui A', ($call('lastClosedEndByVehicleId', [$vehicleA])[$vehicleA]['ts'] ?? null) === strtotime($today . ' 02:00:00'));

    check('tranzactia a ramas deschisa pe tot testul', $inTx());
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

$left = (int) $db->query("SELECT COUNT(*) FROM curse_dispecer WHERE observatii = 'TEST_LIVE_GPS'")->fetchColumn();
check('nu a ramas nicio cursa de test in BD', $left === 0);

echo "\n$passed trecute, $failed esuate\n";
exit($failed > 0 ? 1 : 0);
