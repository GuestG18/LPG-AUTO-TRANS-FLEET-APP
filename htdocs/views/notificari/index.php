<?php
$activeTab = (string) ($activeTab ?? 'rules');
$rulesResult = is_array($rulesResult ?? null) ? $rulesResult : ['rows' => [], 'page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 10];
$queueResult = is_array($queueResult ?? null) ? $queueResult : ['rows' => [], 'page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 10];
$historyResult = is_array($historyResult ?? null) ? $historyResult : ['rows' => [], 'page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 10];
$ruleFilters = is_array($ruleFilters ?? null) ? $ruleFilters : [];
$queueFilters = is_array($queueFilters ?? null) ? $queueFilters : [];
$historyFilters = is_array($historyFilters ?? null) ? $historyFilters : [];
$rules = is_array($rulesResult['rows'] ?? null) ? $rulesResult['rows'] : [];
$queueRows = is_array($queueResult['rows'] ?? null) ? $queueResult['rows'] : [];
$historyRows = is_array($historyResult['rows'] ?? null) ? $historyResult['rows'] : [];
$stats = is_array($stats ?? null) ? $stats : [];
$serviceStatus = is_array($serviceStatus ?? null) ? $serviceStatus : [];
$formData = is_array($formData ?? null) ? $formData : [];
$formErrors = is_array($formErrors ?? null) ? $formErrors : [];
$entityLabels = is_array($entityLabels ?? null) ? $entityLabels : [];
$eventLabels = is_array($eventLabels ?? null) ? $eventLabels : [];
$eventEntityMap = is_array($eventEntityMap ?? null) ? $eventEntityMap : [];
$recipientLabels = is_array($recipientLabels ?? null) ? $recipientLabels : [];
$channelLabels = is_array($channelLabels ?? null) ? $channelLabels : [];
$users = is_array($users ?? null) ? $users : [];
$vehicleDocumentTypes = is_array($vehicleDocumentTypes ?? null) ? $vehicleDocumentTypes : [];
$driverDocumentTypes = is_array($driverDocumentTypes ?? null) ? $driverDocumentTypes : [];
$equipmentTypes = is_array($equipmentTypes ?? null) ? $equipmentTypes : [];
$mailSummary = is_array($mailSummary ?? null) ? $mailSummary : [];
$showForm = (bool) ($showForm ?? false);
$isEditing = (bool) ($isEditing ?? false);
$editingId = (int) ($editingId ?? 0);
$currentUser = current_user() ?? [];
$selectedRecipientIds = array_map('intval', (array) ($formData['recipient_user_ids'] ?? []));

$documentTypes = array_values(array_unique(array_merge(
    array_map('strval', $vehicleDocumentTypes),
    array_map('strval', $driverDocumentTypes),
    array_map('strval', $equipmentTypes)
)));
sort($documentTypes);
$currentDocumentType = trim((string) ($formData['document_type'] ?? ''));
if ($currentDocumentType !== '' && !in_array($currentDocumentType, $documentTypes, true)) {
    $documentTypes[] = $currentDocumentType;
    sort($documentTypes);
}

$formatDateTime = static function (mixed $value, bool $withSeconds = false): string {
    $timestamp = strtotime((string) ($value ?? ''));
    if ($timestamp === false) {
        return '-';
    }

    return date($withSeconds ? 'd.m.Y H:i:s' : 'd.m.Y H:i', $timestamp);
};

$relativeTime = static function (mixed $value): string {
    $timestamp = strtotime((string) ($value ?? ''));
    if ($timestamp === false) {
        return 'Nu exista rulari';
    }

    $seconds = max(0, time() - $timestamp);
    if ($seconds < 60) {
        return 'Acum ' . $seconds . ' secunde';
    }

    $minutes = intdiv($seconds, 60);
    if ($minutes < 60) {
        return 'Acum ' . $minutes . ' minute';
    }

    $hours = intdiv($minutes, 60);
    if ($hours < 24) {
        return 'Acum ' . $hours . ' ore';
    }

    return 'Acum ' . intdiv($hours, 24) . ' zile';
};

$statusLabel = static function (string $status): string {
    return match ($status) {
        'sent' => 'Trimis',
        'failed' => 'Esuat',
        'pending' => 'In asteptare',
        'processing' => 'In lucru',
        'skipped' => 'Sarit',
        default => $status !== '' ? $status : '-',
    };
};

$statusClass = static function (string $status): string {
    return match ($status) {
        'sent', 'active' => 'is-success',
        'failed' => 'is-danger',
        'pending', 'processing' => 'is-warning',
        'inactive', 'skipped' => 'is-muted',
        default => 'is-muted',
    };
};

$eventEntity = static function (string $event) use ($eventEntityMap): string {
    return (string) ($eventEntityMap[$event] ?? 'vehicle');
};

$ruleThreshold = static function (array $rule): string {
    $event = (string) ($rule['event_type'] ?? '');
    if ($event === 'tire_km_limit' && (int) ($rule['threshold_km'] ?? 0) > 0) {
        return number_format((float) $rule['threshold_km'], 0, ',', '.') . ' km';
    }

    if ($event === 'tire_tread_depth' && (float) ($rule['threshold_tread_depth'] ?? 0) > 0) {
        return number_format((float) $rule['threshold_tread_depth'], 1, ',', '.') . ' mm';
    }

    $days = (int) ($rule['days_before'] ?? 0);
    return $days > 0 ? $days . ' zile' : '-';
};

$paginationRenderer = static function (array $pagination, array $query, string $pageKey): void {
    $page = max(1, (int) ($pagination['page'] ?? 1));
    $perPage = max(1, (int) ($pagination['per_page'] ?? 10));
    $totalRows = max(0, (int) ($pagination['total_rows'] ?? 0));
    $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
    $start = $totalRows === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $end = min($totalRows, $page * $perPage);
    ?>
    <div class="notification-table-footer">
        <div class="notification-count">
            Se afiseaza <?= e((string) $start) ?> - <?= e((string) $end) ?> din <?= e((string) $totalRows) ?> rezultate
        </div>
        <nav aria-label="Paginare">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(build_query_url(array_merge($query, [$pageKey => max(1, $page - 1)]))) ?>" aria-label="Anterior">
                        <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    </a>
                </li>
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= e(build_query_url(array_merge($query, [$pageKey => $i]))) ?>"><?= e((string) $i) ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e(build_query_url(array_merge($query, [$pageKey => min($totalPages, $page + 1)]))) ?>" aria-label="Urmator">
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <select class="form-select form-select-sm notification-page-size" aria-label="Rezultate pe pagina" disabled>
            <option>10 / pagina</option>
        </select>
    </div>
    <?php
};

$ruleQuery = [
    'page' => 'notificari',
    'tab' => 'rules',
    'rule_q' => (string) ($ruleFilters['q'] ?? ''),
    'entity_type' => (string) ($ruleFilters['entity_type'] ?? ''),
    'event_type' => (string) ($ruleFilters['event_type'] ?? ''),
    'rule_status' => (string) ($ruleFilters['status'] ?? ''),
];
$queueQuery = [
    'page' => 'notificari',
    'tab' => 'queue',
    'queue_q' => (string) ($queueFilters['q'] ?? ''),
    'queue_status' => (string) ($queueFilters['status'] ?? ''),
];
$historyQuery = [
    'page' => 'notificari',
    'tab' => 'history',
    'history_q' => (string) ($historyFilters['q'] ?? ''),
    'history_status' => (string) ($historyFilters['status'] ?? ''),
    'context' => (string) ($historyFilters['context'] ?? ''),
];
$lastWorkerAt = $stats['last_worker_at'] ?? null;
?>

<div class="notifications-page">
    <div class="notifications-header">
        <div>
            <h2 class="notifications-title">Configurare notificari</h2>
            <p class="notifications-subtitle">Configureaza regulile automate pentru documente, soferi, anvelope, concedii si dotari.</p>
        </div>
        <div class="notifications-actions">
            <a class="btn btn-primary notification-main-action" href="<?= e(build_query_url(['page' => 'notificari', 'action' => 'create'])) ?>">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                Adauga regula
            </a>
            <form method="post" action="<?= e(build_query_url(['page' => 'notificari', 'action' => 'run_test'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="test_email" value="<?= e((string) ($currentUser['email'] ?? '')) ?>">
                <button type="submit" class="btn btn-outline-secondary">
                    <i class="bi bi-send" aria-hidden="true"></i>
                    Ruleaza test notificari
                </button>
            </form>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'notificari', 'tab' => $activeTab])) ?>">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                Reimprospateaza status
            </a>
        </div>
    </div>

    <div class="notification-kpi-grid">
        <div class="notification-kpi">
            <span class="notification-kpi-icon is-green"><i class="bi bi-bell" aria-hidden="true"></i></span>
            <span>
                <span class="notification-kpi-label">Reguli active</span>
                <strong><?= e((string) (int) ($stats['active_rules'] ?? 0)) ?></strong>
                <small>din <?= e((string) (int) ($stats['total_rules'] ?? 0)) ?> in total</small>
            </span>
        </div>
        <a class="notification-kpi" href="<?= e(build_query_url(['page' => 'notificari', 'tab' => 'queue', 'queue_status' => 'pending'])) ?>">
            <span class="notification-kpi-icon is-amber"><i class="bi bi-clock" aria-hidden="true"></i></span>
            <span>
                <span class="notification-kpi-label">Notificari in asteptare</span>
                <strong><?= e((string) (int) ($stats['pending_queue'] ?? 0)) ?></strong>
                <small>Vezi coada</small>
            </span>
        </a>
        <a class="notification-kpi" href="<?= e(build_query_url(['page' => 'notificari', 'tab' => 'history', 'history_status' => 'sent'])) ?>">
            <span class="notification-kpi-icon is-green"><i class="bi bi-send" aria-hidden="true"></i></span>
            <span>
                <span class="notification-kpi-label">Trimise astazi</span>
                <strong><?= e((string) (int) ($stats['sent_today'] ?? 0)) ?></strong>
                <small>Vezi istoric</small>
            </span>
        </a>
        <a class="notification-kpi" href="<?= e(build_query_url(['page' => 'notificari', 'tab' => 'history', 'history_status' => 'failed'])) ?>">
            <span class="notification-kpi-icon is-red"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
            <span>
                <span class="notification-kpi-label">Esuate astazi</span>
                <strong><?= e((string) (int) ($stats['failed_today'] ?? 0)) ?></strong>
                <small>Vezi erori</small>
            </span>
        </a>
        <div class="notification-kpi">
            <span class="notification-kpi-icon is-blue"><i class="bi bi-calendar2-check" aria-hidden="true"></i></span>
            <span>
                <span class="notification-kpi-label">Ultima rulare worker</span>
                <strong class="notification-kpi-date"><?= e($formatDateTime($lastWorkerAt, true)) ?></strong>
                <small><?= e($relativeTime($lastWorkerAt)) ?></small>
            </span>
        </div>
    </div>

    <section class="notification-panel">
        <nav class="notification-tabs" aria-label="Sectiuni notificari">
            <a class="<?= $activeTab === 'rules' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'notificari', 'tab' => 'rules'])) ?>">
                <i class="bi bi-gear" aria-hidden="true"></i> Reguli
            </a>
            <a class="<?= $activeTab === 'queue' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'notificari', 'tab' => 'queue'])) ?>">
                <i class="bi bi-inboxes" aria-hidden="true"></i> Coada notificari
            </a>
            <a class="<?= $activeTab === 'history' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'notificari', 'tab' => 'history'])) ?>">
                <i class="bi bi-clock-history" aria-hidden="true"></i> Istoric notificari
            </a>
            <a class="<?= $activeTab === 'status' ? 'active' : '' ?>" href="<?= e(build_query_url(['page' => 'notificari', 'tab' => 'status'])) ?>">
                <i class="bi bi-cpu" aria-hidden="true"></i> Status serviciu
            </a>
        </nav>

        <?php if ($activeTab === 'rules'): ?>
            <form method="get" class="notification-filter-bar">
                <input type="hidden" name="page" value="notificari">
                <input type="hidden" name="tab" value="rules">
                <div class="notification-search">
                    <input class="form-control" name="rule_q" value="<?= e((string) ($ruleFilters['q'] ?? '')) ?>" placeholder="Cauta regula...">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </div>
                <label>
                    <span>Tip entitate</span>
                    <select class="form-select" name="entity_type">
                        <option value="">Toate</option>
                        <?php foreach ($entityLabels as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>" <?= (string) ($ruleFilters['entity_type'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Tip eveniment</span>
                    <select class="form-select" name="event_type">
                        <option value="">Toate</option>
                        <?php foreach ($eventLabels as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>" <?= (string) ($ruleFilters['event_type'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Status</span>
                    <select class="form-select" name="rule_status">
                        <option value="">Toate</option>
                        <option value="active" <?= (string) ($ruleFilters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (string) ($ruleFilters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </label>
                <button class="btn btn-primary" type="submit">Aplica filtre</button>
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'notificari', 'tab' => 'rules'])) ?>">Reseteaza</a>
            </form>

            <div class="notification-table-wrap">
                <table class="notification-table">
                    <thead>
                    <tr>
                        <th>Status</th>
                        <th>Nume regula</th>
                        <th>Entitate</th>
                        <th>Eveniment</th>
                        <th>Tip document</th>
                        <th>Prag</th>
                        <th>Canal</th>
                        <th>Destinatari</th>
                        <th>Repetare</th>
                        <th>Actualizat la</th>
                        <th class="text-end">Actiuni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rules === []): ?>
                        <tr><td colspan="11" class="notification-empty">Nu exista reguli pentru filtrele selectate.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rules as $rule): ?>
                        <?php
                        $ruleId = (int) ($rule['id'] ?? 0);
                        $enabled = (int) ($rule['enabled'] ?? 0) === 1;
                        $eventType = (string) ($rule['event_type'] ?? '');
                        $entityType = (string) ($rule['entity_type'] ?? $eventEntity($eventType));
                        $recipientMode = (string) ($rule['recipient_mode'] ?? 'admins');
                        ?>
                        <tr>
                            <td>
                                <span class="notification-status-badge <?= $enabled ? 'is-success' : 'is-muted' ?>"><?= $enabled ? 'Activ' : 'Inactiv' ?></span>
                            </td>
                            <td class="notification-name-cell"><?= e((string) ($rule['name'] ?? '')) ?></td>
                            <td><?= e((string) ($entityLabels[$entityType] ?? $entityType)) ?></td>
                            <td><?= e((string) ($eventLabels[$eventType] ?? $eventType)) ?></td>
                            <td><?= e((string) (($rule['document_type'] ?? '') !== '' ? $rule['document_type'] : '-')) ?></td>
                            <td><?= e($ruleThreshold($rule)) ?></td>
                            <td><i class="bi bi-envelope me-1" aria-hidden="true"></i><?= e((string) ($channelLabels[(string) ($rule['channel'] ?? 'email')] ?? 'Email')) ?></td>
                            <td>
                                <?= e((string) ($recipientLabels[$recipientMode] ?? $recipientMode)) ?>
                                <?php if ($recipientMode === 'specific_users' && (string) ($rule['recipient_names'] ?? '') !== ''): ?>
                                    <div class="notification-muted-line"><?= e((string) $rule['recipient_names']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="notification-yes"><?= (int) ($rule['repeat_until_resolved'] ?? 1) === 1 ? 'Da' : 'Nu' ?></span>
                            </td>
                            <td><?= e($formatDateTime($rule['updated_at'] ?? null)) ?></td>
                            <td class="text-end">
                                <div class="notification-row-actions">
                                    <a class="notification-icon-button is-primary" href="<?= e(build_query_url(['page' => 'notificari', 'action' => 'edit', 'id' => $ruleId])) ?>" title="Editeaza" aria-label="Editeaza regula">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </a>
                                    <form method="post" action="<?= e(build_query_url(['page' => 'notificari', 'action' => 'toggle'])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $ruleId) ?>">
                                        <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                                        <button class="notification-switch <?= $enabled ? 'is-on' : '' ?>" type="submit" title="<?= $enabled ? 'Opreste' : 'Porneste' ?>" aria-label="<?= $enabled ? 'Opreste regula' : 'Porneste regula' ?>">
                                            <span></span>
                                        </button>
                                    </form>
                                    <form method="post" action="<?= e(build_query_url(['page' => 'notificari', 'action' => 'delete'])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $ruleId) ?>">
                                        <button class="notification-icon-button is-danger" type="submit" title="Sterge" aria-label="Sterge regula" data-confirm="Stergi regula?">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php $paginationRenderer($rulesResult, $ruleQuery, 'rp'); ?>
        <?php elseif ($activeTab === 'queue'): ?>
            <form method="get" class="notification-filter-bar is-compact">
                <input type="hidden" name="page" value="notificari">
                <input type="hidden" name="tab" value="queue">
                <div class="notification-search">
                    <input class="form-control" name="queue_q" value="<?= e((string) ($queueFilters['q'] ?? '')) ?>" placeholder="Cauta in coada...">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </div>
                <label>
                    <span>Status</span>
                    <select class="form-select" name="queue_status">
                        <option value="">Toate</option>
                        <?php foreach (['pending' => 'In asteptare', 'processing' => 'In lucru', 'sent' => 'Trimise', 'failed' => 'Esuate'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= (string) ($queueFilters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-primary" type="submit">Aplica filtre</button>
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'notificari', 'tab' => 'queue'])) ?>">Reseteaza</a>
            </form>

            <div class="notification-table-wrap">
                <table class="notification-table">
                    <thead>
                    <tr>
                        <th>Status</th>
                        <th>Destinatar</th>
                        <th>Subiect</th>
                        <th>Programat la</th>
                        <th>Incercari</th>
                        <th>Actualizat la</th>
                        <th>Eroare</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($queueRows === []): ?>
                        <tr><td colspan="7" class="notification-empty">Nu exista notificari in coada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($queueRows as $row): ?>
                        <?php $status = (string) ($row['status'] ?? ''); ?>
                        <tr>
                            <td><span class="notification-status-badge <?= $statusClass($status) ?>"><?= e($statusLabel($status)) ?></span></td>
                            <td><?= e((string) ($row['recipient_email'] ?? '')) ?></td>
                            <td class="notification-subject-cell"><?= e((string) ($row['subject'] ?? '')) ?></td>
                            <td><?= e($formatDateTime($row['scheduled_for'] ?? null)) ?></td>
                            <td><?= e((string) (int) ($row['attempts'] ?? 0)) ?> / <?= e((string) (int) ($row['max_attempts'] ?? 0)) ?></td>
                            <td><?= e($formatDateTime($row['updated_at'] ?? null)) ?></td>
                            <td class="notification-error-cell"><?= e((string) (($row['last_error'] ?? '') ?: ($row['error_message'] ?? ''))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php $paginationRenderer($queueResult, $queueQuery, 'qp'); ?>
        <?php elseif ($activeTab === 'history'): ?>
            <form method="get" class="notification-filter-bar is-compact">
                <input type="hidden" name="page" value="notificari">
                <input type="hidden" name="tab" value="history">
                <div class="notification-search">
                    <input class="form-control" name="history_q" value="<?= e((string) ($historyFilters['q'] ?? '')) ?>" placeholder="Cauta istoric...">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </div>
                <label>
                    <span>Status</span>
                    <select class="form-select" name="history_status">
                        <option value="">Toate</option>
                        <?php foreach (['pending' => 'In asteptare', 'sent' => 'Trimise', 'failed' => 'Esuate', 'skipped' => 'Sarite'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= (string) ($historyFilters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="btn btn-primary" type="submit">Aplica filtre</button>
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'notificari', 'tab' => 'history'])) ?>">Reseteaza</a>
            </form>

            <div class="notification-table-wrap">
                <table class="notification-table">
                    <thead>
                    <tr>
                        <th>Data</th>
                        <th>Context</th>
                        <th>Destinatar</th>
                        <th>Subiect</th>
                        <th>Status</th>
                        <th>Eroare</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($historyRows === []): ?>
                        <tr><td colspan="6" class="notification-empty">Nu exista livrari.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($historyRows as $row): ?>
                        <?php $status = (string) ($row['status'] ?? ''); ?>
                        <tr>
                            <td><?= e($formatDateTime($row['created_at'] ?? null)) ?></td>
                            <td><?= e((string) ($row['context'] ?? '')) ?></td>
                            <td><?= e((string) ($row['recipient_email'] ?? '')) ?></td>
                            <td class="notification-subject-cell"><?= e((string) ($row['subject'] ?? '')) ?></td>
                            <td><span class="notification-status-badge <?= $statusClass($status) ?>"><?= e($statusLabel($status)) ?></span></td>
                            <td class="notification-error-cell"><?= e((string) ($row['error_message'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php $paginationRenderer($historyResult, $historyQuery, 'hp'); ?>
        <?php else: ?>
            <?php $queueCounts = is_array($serviceStatus['queue_counts'] ?? null) ? $serviceStatus['queue_counts'] : []; ?>
            <div class="notification-status-grid">
                <div class="notification-status-card">
                    <div class="notification-status-card-title">SMTP</div>
                    <strong><?= !empty($serviceStatus['smtp_configured']) ? 'Configurat' : 'Incomplet' ?></strong>
                    <span><?= e((string) ($mailSummary['host'] ?? '')) ?> <?= e((string) ($mailSummary['port'] ?? '')) ?>/<?= e((string) ($mailSummary['encryption'] ?? '')) ?></span>
                    <small><?= e((string) ($mailSummary['from'] ?? '')) ?></small>
                </div>
                <div class="notification-status-card">
                    <div class="notification-status-card-title">Coada</div>
                    <strong><?= e((string) array_sum(array_map('intval', $queueCounts))) ?></strong>
                    <span>Pending <?= e((string) (int) ($queueCounts['pending'] ?? 0)) ?>, in lucru <?= e((string) (int) ($queueCounts['processing'] ?? 0)) ?></span>
                    <small>Trimise <?= e((string) (int) ($queueCounts['sent'] ?? 0)) ?>, esuate <?= e((string) (int) ($queueCounts['failed'] ?? 0)) ?></small>
                </div>
                <div class="notification-status-card">
                    <div class="notification-status-card-title">Worker</div>
                    <strong><?= e($formatDateTime($serviceStatus['last_worker_at'] ?? null, true)) ?></strong>
                    <span><?= e($relativeTime($serviceStatus['last_worker_at'] ?? null)) ?></span>
                    <small>Ruleaza din serviciul Python / cron configurat pe server.</small>
                </div>
                <div class="notification-status-card">
                    <div class="notification-status-card-title">Ultima eroare</div>
                    <strong><?= (string) ($serviceStatus['last_error'] ?? '') !== '' ? 'Exista eroare' : 'Fara erori' ?></strong>
                    <span><?= e((string) (($serviceStatus['last_error'] ?? '') ?: 'Nu exista erori recente.')) ?></span>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($activeTab === 'rules'): ?>
        <div class="notification-help">
            <strong>Cum functioneaza?</strong>
            <ul>
                <li><i class="bi bi-check-circle" aria-hidden="true"></i> Worker-ul Python verifica regulile active conform programului.</li>
                <li><i class="bi bi-check-circle" aria-hidden="true"></i> Cand o regula este indeplinita, se creeaza o notificare in coada.</li>
                <li><i class="bi bi-check-circle" aria-hidden="true"></i> Notificarile sunt trimise pe canalul selectat.</li>
                <li><i class="bi bi-check-circle" aria-hidden="true"></i> Istoricul complet este disponibil in tabul Istoric notificari.</li>
            </ul>
        </div>
    <?php endif; ?>
</div>

<?php if ($showForm): ?>
    <div class="notification-drawer-backdrop"></div>
    <aside class="notification-rule-drawer" role="dialog" aria-modal="true" aria-labelledby="notificationRuleDrawerTitle">
        <div class="notification-drawer-header">
            <h3 id="notificationRuleDrawerTitle"><?= $isEditing ? 'Editeaza regula notificare' : 'Adauga regula notificare' ?></h3>
            <a href="<?= e(build_query_url(['page' => 'notificari'])) ?>" aria-label="Inchide">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </a>
        </div>
        <form method="post" action="<?= e(build_query_url(['page' => 'notificari', 'action' => $isEditing ? 'update' : 'store'])) ?>" class="notification-rule-form">
            <?= csrf_field() ?>
            <?php if ($isEditing): ?>
                <input type="hidden" name="id" value="<?= e((string) $editingId) ?>">
            <?php endif; ?>

            <div class="notification-rule-grid">
                <label>
                    <span>Nume regula <em>*</em></span>
                    <input class="form-control <?= isset($formErrors['name']) ? 'is-invalid' : '' ?>" name="name" value="<?= e((string) ($formData['name'] ?? '')) ?>" maxlength="190" placeholder="Ex: RCA expira in 30 zile" required>
                    <?php if (isset($formErrors['name'])): ?><small class="invalid-feedback d-block"><?= e((string) $formErrors['name']) ?></small><?php endif; ?>
                </label>
                <label>
                    <span>Tip entitate <em>*</em></span>
                    <select class="form-select <?= isset($formErrors['entity_type']) ? 'is-invalid' : '' ?>" name="entity_type" data-rule-entity-select required>
                        <?php foreach ($entityLabels as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>" <?= (string) ($formData['entity_type'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['entity_type'])): ?><small class="invalid-feedback d-block"><?= e((string) $formErrors['entity_type']) ?></small><?php endif; ?>
                </label>
                <label>
                    <span>Tip eveniment <em>*</em></span>
                    <select class="form-select <?= isset($formErrors['event_type']) ? 'is-invalid' : '' ?>" name="event_type" data-rule-event-select required>
                        <?php foreach ($eventLabels as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>" data-entity="<?= e((string) ($eventEntityMap[$value] ?? 'vehicle')) ?>" <?= (string) ($formData['event_type'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['event_type'])): ?><small class="invalid-feedback d-block"><?= e((string) $formErrors['event_type']) ?></small><?php endif; ?>
                </label>
                <label>
                    <span>Tip document</span>
                    <select class="form-select <?= isset($formErrors['document_type']) ? 'is-invalid' : '' ?>" name="document_type">
                        <option value="">-</option>
                        <?php foreach ($documentTypes as $documentType): ?>
                            <option value="<?= e((string) $documentType) ?>" <?= $currentDocumentType === (string) $documentType ? 'selected' : '' ?>><?= e((string) $documentType) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['document_type'])): ?><small class="invalid-feedback d-block"><?= e((string) $formErrors['document_type']) ?></small><?php endif; ?>
                </label>
                <label>
                    <span>Zile inainte</span>
                    <input class="form-control <?= isset($formErrors['days_before']) ? 'is-invalid' : '' ?>" name="days_before" type="number" min="0" max="365" step="1" value="<?= e((string) ($formData['days_before'] ?? 30)) ?>" placeholder="30">
                    <?php if (isset($formErrors['days_before'])): ?><small class="invalid-feedback d-block"><?= e((string) $formErrors['days_before']) ?></small><?php endif; ?>
                </label>
                <label>
                    <span>KM inainte</span>
                    <input class="form-control <?= isset($formErrors['threshold_km']) ? 'is-invalid' : '' ?>" name="threshold_km" type="number" min="0" step="1" value="<?= e((string) ($formData['threshold_km'] ?? '')) ?>" placeholder="Ex: 15000" data-threshold-km>
                    <?php if (isset($formErrors['threshold_km'])): ?><small class="invalid-feedback d-block"><?= e((string) $formErrors['threshold_km']) ?></small><?php endif; ?>
                </label>
                <label>
                    <span>Prag adancime profil (mm)</span>
                    <input class="form-control <?= isset($formErrors['threshold_tread_depth']) ? 'is-invalid' : '' ?>" name="threshold_tread_depth" type="number" min="0" step="0.1" value="<?= e((string) ($formData['threshold_tread_depth'] ?? '')) ?>" placeholder="Ex: 3.0" data-threshold-tread>
                    <?php if (isset($formErrors['threshold_tread_depth'])): ?><small class="invalid-feedback d-block"><?= e((string) $formErrors['threshold_tread_depth']) ?></small><?php endif; ?>
                </label>
                <label>
                    <span>Canal notificare <em>*</em></span>
                    <select class="form-select <?= isset($formErrors['channel']) ? 'is-invalid' : '' ?>" name="channel" required>
                        <?php foreach ($channelLabels as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>" <?= (string) ($formData['channel'] ?? 'email') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['channel'])): ?><small class="invalid-feedback d-block"><?= e((string) $formErrors['channel']) ?></small><?php endif; ?>
                </label>
                <label class="notification-wide">
                    <span>Destinatari <em>*</em></span>
                    <select class="form-select <?= isset($formErrors['recipient_mode']) ? 'is-invalid' : '' ?>" name="recipient_mode" data-notification-recipient-mode required>
                        <?php foreach ($recipientLabels as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>" <?= (string) ($formData['recipient_mode'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['recipient_mode'])): ?><small class="invalid-feedback d-block"><?= e((string) $formErrors['recipient_mode']) ?></small><?php endif; ?>
                </label>
                <label class="notification-wide" data-specific-users>
                    <span>Utilizatori selectati (daca este cazul)</span>
                    <select class="form-select <?= isset($formErrors['recipient_user_ids']) ? 'is-invalid' : '' ?>" name="recipient_user_ids[]" multiple size="4">
                        <?php foreach ($users as $user): ?>
                            <?php $userId = (int) ($user['id'] ?? 0); ?>
                            <option value="<?= e((string) $userId) ?>" <?= in_array($userId, $selectedRecipientIds, true) ? 'selected' : '' ?>>
                                <?= e((string) ($user['nume'] ?? '')) ?> &lt;<?= e((string) ($user['email'] ?? '')) ?>&gt;
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($formErrors['recipient_user_ids'])): ?><small class="invalid-feedback d-block"><?= e((string) $formErrors['recipient_user_ids']) ?></small><?php endif; ?>
                </label>
            </div>

            <div class="notification-rule-checks">
                <label><input class="form-check-input" type="checkbox" name="enabled" value="1" <?= (int) ($formData['enabled'] ?? 0) === 1 ? 'checked' : '' ?>> Activ</label>
                <label><input class="form-check-input" type="checkbox" name="repeat_until_resolved" value="1" <?= (int) ($formData['repeat_until_resolved'] ?? 1) === 1 ? 'checked' : '' ?>> Repeta pana la rezolvare</label>
                <label><input class="form-check-input" type="checkbox" name="daily_limit_enabled" value="1" <?= (int) ($formData['daily_limit_enabled'] ?? 1) === 1 ? 'checked' : '' ?>> Maxim o notificare pe zi</label>
            </div>

            <div class="notification-drawer-footer">
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'notificari'])) ?>">Anuleaza</a>
                <button type="submit" class="btn btn-primary">Salveaza</button>
            </div>
        </form>
    </aside>
<?php endif; ?>

<script>
(() => {
    const modeSelect = document.querySelector('[data-notification-recipient-mode]');
    const specificUsers = document.querySelector('[data-specific-users]');
    const entitySelect = document.querySelector('[data-rule-entity-select]');
    const eventSelect = document.querySelector('[data-rule-event-select]');
    const kmInput = document.querySelector('[data-threshold-km]');
    const treadInput = document.querySelector('[data-threshold-tread]');

    const syncRecipients = () => {
        if (!modeSelect || !specificUsers) {
            return;
        }
        const enabled = modeSelect.value === 'specific_users';
        specificUsers.classList.toggle('is-disabled', !enabled);
        specificUsers.querySelectorAll('select, input').forEach((field) => {
            field.disabled = !enabled;
        });
    };

    const syncEvents = () => {
        if (!entitySelect || !eventSelect) {
            return;
        }
        const entity = entitySelect.value;
        let selectedIsAvailable = false;
        let firstAvailable = '';
        eventSelect.querySelectorAll('option').forEach((option) => {
            const matches = option.dataset.entity === entity;
            option.hidden = !matches;
            option.disabled = !matches;
            if (matches && firstAvailable === '') {
                firstAvailable = option.value;
            }
            if (matches && option.selected) {
                selectedIsAvailable = true;
            }
        });
        if (!selectedIsAvailable && firstAvailable !== '') {
            eventSelect.value = firstAvailable;
        }
        syncThresholds();
    };

    const syncThresholds = () => {
        if (!eventSelect) {
            return;
        }
        if (kmInput) {
            kmInput.disabled = eventSelect.value !== 'tire_km_limit';
        }
        if (treadInput) {
            treadInput.disabled = eventSelect.value !== 'tire_tread_depth';
        }
    };

    if (modeSelect) {
        modeSelect.addEventListener('change', syncRecipients);
    }
    if (entitySelect) {
        entitySelect.addEventListener('change', syncEvents);
    }
    if (eventSelect) {
        eventSelect.addEventListener('change', syncThresholds);
    }

    syncRecipients();
    syncEvents();
})();
</script>
