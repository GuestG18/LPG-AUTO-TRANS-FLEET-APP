<?php
$filters = is_array($filters ?? null) ? $filters : [];
$result = is_array($result ?? null) ? $result : [];
$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
$reasonOptions = is_array($reasonOptions ?? null) ? $reasonOptions : [];
$canReviewApprovals = !empty($canReviewApprovals);

$statusLabels = [
    'pending' => 'In asteptare',
    'approved' => 'Aprobata',
    'rejected' => 'Respinsa',
];
$resourceTypeLabels = [
    'vehicle' => 'Vehicul',
    'driver' => 'Sofer',
    'repair' => 'Reparatie',
];
$formatDate = static fn(mixed $value): string => trim((string) $value) !== '' ? format_date_ro((string) $value) : '-';
$formatDateTime = static fn(mixed $value): string => trim((string) $value) !== '' ? format_datetime_ro((string) $value) : '-';
$documentsLabel = static function (array $row): string {
    $names = is_array($row['affected_document_names'] ?? null) ? $row['affected_document_names'] : [];
    $names = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $names)));

    return $names !== [] ? implode(', ', $names) : '-';
};
$selectedStatus = (string) ($filters['status'] ?? 'pending');
$selectedResourceType = (string) ($filters['resource_type'] ?? '');
$baseQuery = [
    'page' => 'inactive_approvals',
    'status' => $selectedStatus !== '' ? $selectedStatus : 'all',
    'resource_type' => $selectedResourceType !== '' ? $selectedResourceType : 'all',
    'reason' => (string) ($filters['reason'] ?? ''),
    'date_start' => (string) ($filters['date_start'] ?? ''),
    'date_end' => (string) ($filters['date_end'] ?? ''),
    'q' => (string) ($filters['q'] ?? ''),
];
$currentUrl = build_query_url(array_merge($baseQuery, ['p' => (int) ($result['page'] ?? 1)]));
?>

<div class="inactive-approvals-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h2 class="h4 mb-1">Solicitari aprobare</h2>
            <p class="text-muted mb-0">Aprobari si istoric pentru utilizarea vehiculelor, soferilor sau reparatiilor in Dispecer curse.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dashboard'])) ?>">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Dashboard
        </a>
    </div>

    <div class="card border-0 shadow-sm mb-3 inactive-approval-filter-card">
        <div class="card-body">
            <form class="row g-3 align-items-end" method="get">
                <input type="hidden" name="page" value="inactive_approvals">
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="approval_status">Status</label>
                    <select class="form-select" id="approval_status" name="status">
                        <option value="pending" <?= $selectedStatus === 'pending' ? 'selected' : '' ?>>In asteptare</option>
                        <option value="approved" <?= $selectedStatus === 'approved' ? 'selected' : '' ?>>Aprobate</option>
                        <option value="rejected" <?= $selectedStatus === 'rejected' ? 'selected' : '' ?>>Respinse</option>
                        <option value="all" <?= $selectedStatus === 'all' || $selectedStatus === '' ? 'selected' : '' ?>>Toate</option>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="approval_resource_type">Tip resursa</label>
                    <select class="form-select" id="approval_resource_type" name="resource_type">
                        <option value="all" <?= $selectedResourceType === '' ? 'selected' : '' ?>>Toate</option>
                        <option value="vehicle" <?= $selectedResourceType === 'vehicle' ? 'selected' : '' ?>>Vehicul</option>
                        <option value="driver" <?= $selectedResourceType === 'driver' ? 'selected' : '' ?>>Sofer</option>
                        <option value="repair" <?= $selectedResourceType === 'repair' ? 'selected' : '' ?>>Reparatie</option>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="approval_reason">Motiv</label>
                    <select class="form-select" id="approval_reason" name="reason">
                        <option value="">Toate</option>
                        <?php foreach ($reasonOptions as $reasonKey => $reasonLabel): ?>
                            <option value="<?= e((string) $reasonKey) ?>" <?= (string) ($filters['reason'] ?? '') === (string) $reasonKey ? 'selected' : '' ?>>
                                <?= e((string) $reasonLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="approval_date_start">Data de la</label>
                    <input class="form-control" type="date" id="approval_date_start" name="date_start" value="<?= e((string) ($filters['date_start'] ?? '')) ?>">
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="approval_date_end">Data pana la</label>
                    <input class="form-control" type="date" id="approval_date_end" name="date_end" value="<?= e((string) ($filters['date_end'] ?? '')) ?>">
                </div>
                <div class="col-12 col-md-6 col-xl-2">
                    <label class="form-label" for="approval_q">Cautare</label>
                    <input class="form-control" type="search" id="approval_q" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Nr auto, sofer, cursa">
                </div>
                <div class="col-12 d-flex flex-wrap justify-content-end gap-2">
                    <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'inactive_approvals'])) ?>">Reseteaza</a>
                    <button class="btn btn-primary" type="submit">Aplica filtre</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm inactive-approval-table-card">
        <div class="table-responsive">
            <table class="table align-middle mb-0 inactive-approval-table">
                <thead>
                <tr>
                    <th>Resursa</th>
                    <th>Tip</th>
                    <th>Motiv</th>
                    <th>Documente afectate</th>
                    <th>Inactiv din</th>
                    <th>Cursa</th>
                    <th>Solicitat de</th>
                    <th>Data solicitarii</th>
                    <th class="text-end">Actiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">Nu exista solicitari pentru filtrele selectate.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $approvalId = (int) ($row['id'] ?? 0);
                        $status = (string) ($row['status'] ?? 'pending');
                        $resourceType = (string) ($row['resource_type'] ?? '');
                        ?>
                        <?php // Statusul nu mai are coloana proprie: il arata culoarea intregului rand. ?>
                        <tr class="inactive-approval-row is-<?= e($status) ?>">
                            <td class="fw-semibold">
                                <?= e((string) ($row['resource_label'] ?? '-')) ?>
                                <?php // Culoarea singura nu e accesibila; statusul ramane citibil de cititoarele de ecran. ?>
                                <span class="visually-hidden">Status: <?= e($statusLabels[$status] ?? $status) ?></span>
                            </td>
                            <td><?= e($resourceTypeLabels[$resourceType] ?? '-') ?></td>
                            <td>
                                <span class="inactive-approval-reason-badge tone-<?= e((string) ($row['inactive_reason'] ?? 'other')) ?>">
                                    <?= e((string) ($row['inactive_reason_label'] ?? 'Alt motiv')) ?>
                                </span>
                            </td>
                            <td><?= e($documentsLabel($row)) ?></td>
                            <td><?= e($formatDate($row['inactive_since'] ?? '')) ?></td>
                            <td><?= !empty($row['trip_id']) ? ('#' . e((string) $row['trip_id'])) : '-' ?></td>
                            <td><?= e((string) ($row['requested_by_name'] ?? '-')) ?></td>
                            <td><?= e($formatDateTime($row['requested_at'] ?? '')) ?></td>
                            <td class="text-end">
                                <?php
                                // Meniu compact: coloana Actiuni ocupa o singura iconita, nu trei butoane.
                                // Deschiderea e gestionata din app.js, nu de dropdown-ul Bootstrap: tabelul
                                // are overflow-x, care taie orice meniu pozitionat absolut inauntrul lui.
                                ?>
                                <div class="inactive-approval-menu">
                                    <button
                                        class="btn btn-sm btn-outline-secondary inactive-approval-menu-toggle"
                                        type="button"
                                        data-approval-menu-toggle
                                        aria-haspopup="true"
                                        aria-expanded="false"
                                        aria-label="Actiuni pentru <?= e((string) ($row['resource_label'] ?? 'solicitare')) ?>"
                                    >
                                        <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end inactive-approval-menu-list">
                                        <li>
                                            <a class="dropdown-item" href="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'show', 'id' => $approvalId])) ?>">
                                                <i class="bi bi-eye" aria-hidden="true"></i> Vezi detalii
                                            </a>
                                        </li>
                                        <?php if ($status === 'pending' && $canReviewApprovals): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'approve'])) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= e((string) $approvalId) ?>">
                                                    <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
                                                    <button class="dropdown-item text-success" type="submit">
                                                        <i class="bi bi-check-lg" aria-hidden="true"></i> Aproba
                                                    </button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'reject'])) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= e((string) $approvalId) ?>">
                                                    <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
                                                    <button class="dropdown-item text-danger" type="submit">
                                                        <i class="bi bi-x-lg" aria-hidden="true"></i> Respinge
                                                    </button>
                                                </form>
                                            </li>
                                        <?php elseif ($status !== 'pending' && $canReviewApprovals): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'reopen'])) ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= e((string) $approvalId) ?>">
                                                    <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
                                                    <button class="dropdown-item text-warning-emphasis" type="submit">
                                                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Repune in asteptare
                                                    </button>
                                                </form>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ((int) ($result['total_pages'] ?? 1) > 1): ?>
            <div class="card-footer bg-white">
                <?php
                $currentPageIndex = (int) ($result['page'] ?? 1);
                $totalPages = (int) ($result['total_pages'] ?? 1);
                ?>
                <nav aria-label="Paginare solicitari aprobare">
                    <ul class="pagination pagination-sm mb-0">
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <li class="page-item <?= $p === $currentPageIndex ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $p]))) ?>"><?= e((string) $p) ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>
