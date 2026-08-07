<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this script from CLI.\n");
}

$root = dirname(__DIR__);

require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/InactiveResourceApprovalModel.php';
require_once $root . '/htdocs/services/InactiveResourceStatusService.php';

$db = get_pdo();
$approvalModel = new InactiveResourceApprovalModel($db);
$statusService = new InactiveResourceStatusService($db);
$approvalModel->ensureSchema();

$marker = 'DEMO_SEED: inactive_resource_approval_demo';
$clear = in_array('--clear', $argv, true);

if ($clear) {
    $db->beginTransaction();
    try {
        $ids = demoApprovalIds($db, $marker);
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $docStmt = $db->prepare("DELETE FROM inactive_resource_approval_documents WHERE approval_id IN ({$placeholders})");
            $docStmt->execute(array_values($ids));

            $approvalStmt = $db->prepare("DELETE FROM inactive_resource_approvals WHERE id IN ({$placeholders})");
            $approvalStmt->execute(array_values($ids));
        }
        $db->commit();
        echo 'Removed demo inactive approval rows: ' . count($ids) . PHP_EOL;
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
    exit;
}

$userId = firstAdminUserId($db);
$vehicleResources = collectInactiveResources($db, $statusService, 'vehicle', 3);
$driverResources = collectInactiveResources($db, $statusService, 'driver', 2);
$resources = array_merge($vehicleResources, $driverResources);

if ($resources === []) {
    echo "No inactive vehicles or drivers were found for demo approvals.\n";
    exit(1);
}

$created = [];
$skipped = [];

foreach ($resources as $resource) {
    $resourceType = (string) ($resource['resource_type'] ?? '');
    $resourceId = (int) ($resource['resource_id'] ?? 0);
    $tripId = findLatestTripIdForResource($db, $resourceType, $resourceId);
    $existingId = findOpenApprovalId($db, $resourceType, $resourceId, $tripId);

    if ($existingId !== null) {
        $skipped[] = sprintf('%s %s already has open approval #%d', $resourceType, (string) ($resource['resource_label'] ?? $resourceId), $existingId);
        continue;
    }

    $resource['demo_seed'] = 'inactive_resource_approval_demo';
    $approvalId = $approvalModel->createOrReuseFromSnapshot($resource, $tripId, 'pending', $userId);
    if ($approvalId <= 0) {
        continue;
    }

    markDemoApproval($db, $approvalId, $marker);
    $created[] = sprintf(
        '#%d %s %s%s',
        $approvalId,
        $resourceType,
        (string) ($resource['resource_label'] ?? $resourceId),
        $tripId !== null ? ' on trip #' . $tripId : ''
    );
}

echo 'Created demo pending approvals: ' . count($created) . PHP_EOL;
foreach ($created as $line) {
    echo ' - ' . $line . PHP_EOL;
}

if ($skipped !== []) {
    echo 'Skipped existing open approvals: ' . count($skipped) . PHP_EOL;
    foreach ($skipped as $line) {
        echo ' - ' . $line . PHP_EOL;
    }
}

echo "Open Dashboard and refresh. Use --clear to remove only these demo rows.\n";

function collectInactiveResources(PDO $db, InactiveResourceStatusService $statusService, string $type, int $limit): array
{
    $resources = [];
    $rows = $type === 'vehicle' ? demoVehicleRows($db) : demoDriverRows($db);

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $status = $type === 'vehicle'
            ? $statusService->getVehicleStatus($id)
            : $statusService->getDriverStatus($id);

        if ($status !== null && !empty($status['is_inactive'])) {
            $resources[] = $status;
        }

        if (count($resources) >= $limit) {
            break;
        }
    }

    return $resources;
}

function demoVehicleRows(PDO $db): array
{
    return $db->query("
        SELECT id, nr_inmatriculare
        FROM vehicule
        WHERE tip_vehicul NOT IN ('semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
        ORDER BY
            CASE nr_inmatriculare
                WHEN 'B 325 NET' THEN 0
                WHEN 'B 189 NET' THEN 1
                WHEN 'B 400 NET' THEN 2
                ELSE 3
            END,
            CASE WHEN status = 'inactiv' THEN 0 ELSE 1 END,
            nr_inmatriculare ASC
    ")->fetchAll();
}

function demoDriverRows(PDO $db): array
{
    return $db->query("
        SELECT id, nume
        FROM soferi
        ORDER BY
            CASE nume
                WHEN 'Macovei Ion' THEN 0
                WHEN 'Andreas Iulian' THEN 1
                ELSE 2
            END,
            CASE WHEN status = 'inactiv' OR employment_status IN ('temporarily_inactive', 'suspended', 'leave') THEN 0 ELSE 1 END,
            nume ASC
    ")->fetchAll();
}

function firstAdminUserId(PDO $db): ?int
{
    $stmt = $db->query("
        SELECT id
        FROM utilizatori
        ORDER BY CASE WHEN rol = 'admin' THEN 0 ELSE 1 END, id ASC
        LIMIT 1
    ");
    $id = (int) ($stmt->fetchColumn() ?: 0);

    return $id > 0 ? $id : null;
}

function findLatestTripIdForResource(PDO $db, string $resourceType, int $resourceId): ?int
{
    if ($resourceId <= 0 || !in_array($resourceType, ['vehicle', 'driver'], true)) {
        return null;
    }

    $column = $resourceType === 'vehicle' ? 'vehicle_id' : 'driver_id';
    $deletedFilter = columnExists($db, 'curse_dispecer', 'deleted_at') ? 'AND deleted_at IS NULL' : '';
    $stmt = $db->prepare("
        SELECT id
        FROM curse_dispecer
        WHERE {$column} = :resource_id
          {$deletedFilter}
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bindValue(':resource_id', $resourceId, PDO::PARAM_INT);
    $stmt->execute();
    $id = (int) ($stmt->fetchColumn() ?: 0);

    return $id > 0 ? $id : null;
}

function findOpenApprovalId(PDO $db, string $resourceType, int $resourceId, ?int $tripId): ?int
{
    $tripSql = $tripId !== null ? 'trip_id = :trip_id' : 'trip_id IS NULL';
    $stmt = $db->prepare("
        SELECT id
        FROM inactive_resource_approvals
        WHERE resource_type = :resource_type
          AND resource_id = :resource_id
          AND {$tripSql}
          AND status IN ('pending', 'approved')
        ORDER BY FIELD(status, 'pending', 'approved'), id DESC
        LIMIT 1
    ");
    $stmt->bindValue(':resource_type', $resourceType);
    $stmt->bindValue(':resource_id', $resourceId, PDO::PARAM_INT);
    if ($tripId !== null) {
        $stmt->bindValue(':trip_id', $tripId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $id = (int) ($stmt->fetchColumn() ?: 0);

    return $id > 0 ? $id : null;
}

function markDemoApproval(PDO $db, int $approvalId, string $marker): void
{
    $stmt = $db->prepare("
        UPDATE inactive_resource_approvals
        SET review_note = :marker,
            updated_at = NOW()
        WHERE id = :id
          AND status = 'pending'
    ");
    $stmt->bindValue(':marker', $marker);
    $stmt->bindValue(':id', $approvalId, PDO::PARAM_INT);
    $stmt->execute();
}

function demoApprovalIds(PDO $db, string $marker): array
{
    $stmt = $db->prepare("
        SELECT id
        FROM inactive_resource_approvals
        WHERE review_note = :marker
    ");
    $stmt->bindValue(':marker', $marker);
    $stmt->execute();

    return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->bindValue(':table_name', $table);
    $stmt->bindValue(':column_name', $column);
    $stmt->execute();

    return (int) $stmt->fetchColumn() > 0;
}
