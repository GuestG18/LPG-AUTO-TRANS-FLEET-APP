<?php
declare(strict_types=1);

/**
 * Test pentru cursele care asteapta aprobarea si cererile "adauga permanent pe ruta".
 *
 *   php scripts/test_approval_live.php
 *
 * Totul ruleaza intr-o tranzactie anulata la final: nu raman curse, cereri sau
 * modificari in Configurare Transport. Schema se asigura INAINTE de tranzactie
 * (DDL face COMMIT implicit).
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

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$approvals = new InactiveResourceApprovalModel($db);
$approvals->ensureSchema();
$races = new DispecerCurseModel($db);
$controller = new DispecerCurseController($db);

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  OK   {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$tripId = (int) $db->query('SELECT id FROM curse_dispecer WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
$userId = (int) $db->query("SELECT id FROM utilizatori ORDER BY id ASC LIMIT 1")->fetchColumn();
$driverId = (int) $db->query('SELECT id FROM soferi ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($tripId <= 0 || $userId <= 0 || $driverId <= 0) {
    fwrite(STDERR, "Lipsesc date (cursa / utilizator / sofer) pentru test.\n");
    exit(1);
}

$driverResource = [
    'resource_type' => 'driver',
    'resource_id' => $driverId,
    'resource_label' => 'Sofer test',
    'is_inactive' => true,
    'reason_key' => 'medical_leave',
    'reason_label' => 'Concediu medical',
    'usage_context' => 'Dispecer curse',
    'documents' => [],
];
$vehicleResource = [
    'resource_type' => 'vehicle',
    'resource_id' => 999999,
    'resource_label' => 'Vehicul test',
    'is_inactive' => true,
    'reason_key' => 'manual_inactive',
    'reason_label' => 'Dezactivat manual',
    'usage_context' => 'Dispecer curse',
    'documents' => [],
];

// Verificarile de ruta asigura tabelele de configurare (CREATE TABLE = COMMIT implicit) o
// data per instanta de model: le incalzim pe modelul controllerului inainte de tranzactie.
$controllerModel = (new ReflectionProperty(DispecerCurseController::class, 'model'))->getValue($controller);
$controllerModel->getDistributionRouteRules(true, 0);
$controllerModel->getPrimaryRouteRules(true, 0);
(new ReflectionMethod(DispecerCurseController::class, 'isVehicleAllowedForBeneficiaryAndTransport'))
    ->invoke($controller, 0, 0, 'distributie');

function requireTransaction(PDO $db, string $step): void
{
    if (!$db->inTransaction()) {
        fwrite(STDERR, "Tranzactia s-a inchis implicit dupa: {$step}. Opresc testul; verifica datele scrise.\n");
        exit(2);
    }
}

$db->beginTransaction();
try {
    echo "Curse care asteapta aprobarea (cursa #{$tripId}):\n";
    $wasAwaiting = $races->isRaceAwaitingApproval($tripId);
    check('cursa de test nu astepta inainte', !$wasAwaiting);

    // Cerere fara cursa a operatorului (fluxul vechi) — trebuie preluata, nu dublata.
    $tripless = $approvals->createPendingForRequesterResource($driverResource, $userId);
    $ids = $approvals->requestTripApprovals([$driverResource, $vehicleResource], $tripId, $userId);
    check('doua cereri blocante (sofer + vehicul)', count($ids) === 2, json_encode($ids));
    check('cererea fara cursa a fost preluata pe cursa', $tripless !== null && in_array((int) $tripless['id'], $ids, true));
    check('cursa asteapta aprobarea', $races->isRaceAwaitingApproval($tripId));

    $awaiting = $approvals->getTripsAwaitingApproval($userId);
    $row = array_values(array_filter($awaiting, static fn(array $t): bool => $t['trip_id'] === $tripId))[0] ?? null;
    check('apare in "Curse in asteptare aprobare"', $row !== null && count($row['approvals']) === 2);

    $approvals->approve($ids[0], $userId, 'test');
    check('aprobata doar o resursa: cursa inca asteapta', $races->isRaceAwaitingApproval($tripId));
    $approvals->reject($ids[1], $userId, 'vehicul in service');
    check('respinsa: cursa ramane in afara Desfasuratorului', $races->isRaceAwaitingApproval($tripId));
    $awaiting = $approvals->getTripsAwaitingApproval(null);
    $row = array_values(array_filter($awaiting, static fn(array $t): bool => $t['trip_id'] === $tripId))[0] ?? null;
    check('marcata Respinsa pentru operator', $row !== null && $row['status'] === 'rejected');

    // Operatorul retrimite cu acelasi vehicul: cerere noua, cea respinsa nu mai blocheaza.
    $retryIds = $approvals->requestTripApprovals([$vehicleResource], $tripId, $userId);
    $approvals->syncTripBlockingApprovals($tripId, [$driverResource, $vehicleResource], $userId);
    $blocking = $db->query("SELECT id, status FROM inactive_resource_approvals WHERE trip_id = {$tripId} AND blocks_trip = 1 AND status IN ('pending','rejected')")->fetchAll();
    check('retrimisa: blocheaza doar cererea noua', count($blocking) === 1 && (int) $blocking[0]['id'] === ($retryIds[0] ?? 0), json_encode($blocking));

    // Operatorul corecteaza: vehiculul nu mai e inactiv -> cererea in asteptare se respinge, cursa intra.
    $approvals->syncTripBlockingApprovals($tripId, [$driverResource], $userId);
    check('corectata: cursa intra in Desfasurator', !$races->isRaceAwaitingApproval($tripId));
    requireTransaction($db, 'curse in asteptare');

    echo "Adaugare permanenta pe ruta:\n";
    $rule = $db->query("
        SELECT id, beneficiar_id, loc_incarcare_id, zona_distributie_id, vehicle_ids
        FROM configurare_rute_distributie
        WHERE beneficiar_id IS NOT NULL AND loc_incarcare_id IS NOT NULL AND zona_distributie_id IS NOT NULL
        ORDER BY id ASC LIMIT 1
    ")->fetch();
    if ($rule === false) {
        check('exista o regula de distributie pentru test', false);
    } else {
        $ruleVehicles = array_filter(array_map('intval', explode(',', (string) $rule['vehicle_ids'])));
        $vehicleId = (int) $db->query('SELECT id FROM vehicule WHERE id NOT IN (' . (implode(',', $ruleVehicles) ?: '0') . ') ORDER BY id ASC LIMIT 1')->fetchColumn();
        $requestId = $approvals->createRouteConfigRequest([
            'vehicle_id' => $vehicleId,
            'beneficiar_id' => (int) $rule['beneficiar_id'],
            'tip_transport' => 'distributie',
            'loc_incarcare_id' => (int) $rule['loc_incarcare_id'],
            'zona_distributie_id' => (int) $rule['zona_distributie_id'],
        ], $tripId, $userId, 'Vehicul test', 'Ruta test', 'Beneficiar test');
        check('cererea route_config a fost creata', $requestId > 0);
        check('nu se aplica inainte de aprobare', $controller->applyApprovedRouteConfigRequests() === 0);
        requireTransaction($db, 'applyApprovedRouteConfigRequests (fara cereri aprobate)');
        check('nu blocheaza cursa', !$races->isRaceAwaitingApproval($tripId));

        // Aprobare "din afara aplicatiei" (ca workerul de email): doar statusul se schimba.
        $db->prepare("UPDATE inactive_resource_approvals SET status = 'approved', reviewed_at = NOW() WHERE id = :id")->execute([':id' => $requestId]);
        $applied = $controller->applyApprovedRouteConfigRequests();
        requireTransaction($db, 'applyApprovedRouteConfigRequests');
        $after =(string) $db->query('SELECT vehicle_ids FROM configurare_rute_distributie WHERE id = ' . (int) $rule['id'])->fetchColumn();
        check('aplicata dupa aprobare', $applied === 1 && in_array($vehicleId, array_map('intval', explode(',', $after)), true), $after);
        check('aplicata o singura data', $controller->applyApprovedRouteConfigRequests() === 0);
        $note = (string) $db->query('SELECT review_note FROM inactive_resource_approvals WHERE id = ' . $requestId)->fetchColumn();
        check('rezultatul aplicarii e in nota', str_contains($note, 'Aplicare:'), $note);
    }
} finally {
    $db->rollBack();
}

echo "\n{$passed} OK, {$failed} FAIL\n";
exit($failed > 0 ? 1 : 0);
