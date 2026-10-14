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
    if (str_starts_with($event, 'revizie_')) {
        return (string) ($rule['document_type'] ?? '') === 'necesita' ? 'Depășit (Revizii)' : 'Prag din Revizii';
    }

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

$channelIcon = static function (string $channel): string {
    return match ($channel) {
        'sms' => 'bi-phone',
        'email_sms' => 'bi-envelope-paper',
        default => 'bi-envelope',
    };
};

// Livrarile SMS au numarul in recipient_phone; cele email in recipient_email.
$deliveryRecipient = static function (array $row): string {
    if ((string) ($row['channel'] ?? 'email') === 'sms') {
        $name = trim((string) ($row['recipient_name'] ?? ''));
        $phone = (string) ($row['recipient_phone'] ?? '');
        return $name !== '' ? $name . ' · ' . $phone : $phone;
    }

    return (string) ($row['recipient_email'] ?? '');
};
?>

<div class="notifications-page">
    <div class="notifications-header">
        <div>
            <h2 class="notifications-title">Configurare notificari</h2>
            <p class="notifications-subtitle">Configureaza regulile automate (email si SMS) pentru documente, soferi, anvelope, concedii, dotari si revizii.</p>
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
                            <td class="notification-name-cell">
                                <?= e((string) ($rule['name'] ?? '')) ?>
                                <?php if ((int) ($rule['group_size'] ?? 1) > 1): ?>
                                    <div class="notification-muted-line" title="Salvate împreună; „Editează” deschide tot grupul">
                                        <i class="bi bi-collection" aria-hidden="true"></i> Grup de <?= e((string) (int) $rule['group_size']) ?> reguli
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($entityLabels[$entityType] ?? $entityType)) ?></td>
                            <td><?= e((string) ($eventLabels[$eventType] ?? $eventType)) ?></td>
                            <td><?= e((string) (($rule['document_type'] ?? '') !== '' ? $rule['document_type'] : '-')) ?></td>
                            <td><?= e($ruleThreshold($rule)) ?></td>
                            <?php
                            $ruleChannel = (string) ($rule['channel'] ?? 'email');
                            $ruleMetadata = json_decode((string) ($rule['metadata_json'] ?? ''), true);
                            $ruleNotifiesDriver = is_array($ruleMetadata) && !empty($ruleMetadata['notify_driver']);
                            ?>
                            <td><i class="bi <?= e($channelIcon($ruleChannel)) ?> me-1" aria-hidden="true"></i><?= e((string) ($channelLabels[$ruleChannel] ?? 'Email')) ?></td>
                            <td>
                                <?= e((string) ($recipientLabels[$recipientMode] ?? $recipientMode)) ?>
                                <?php if ($ruleNotifiesDriver): ?>
                                    <div class="notification-muted-line">+ soferul (SMS)</div>
                                <?php endif; ?>
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
                            <td><i class="bi <?= e($channelIcon((string) ($row['channel'] ?? 'email'))) ?> me-1" aria-hidden="true"></i><?= e($deliveryRecipient($row)) ?></td>
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
                            <td><i class="bi <?= e($channelIcon((string) ($row['channel'] ?? 'email'))) ?> me-1" aria-hidden="true"></i><?= e($deliveryRecipient($row)) ?></td>
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
                <?php
                $smsEnabled = !empty($serviceStatus['sms_enabled']);
                $smsConfigured = !empty($serviceStatus['sms_configured']);
                $smsForceTo = (string) ($serviceStatus['sms_force_to'] ?? '');
                ?>
                <div class="notification-status-card">
                    <div class="notification-status-card-title">SMS (SMSAlert)</div>
                    <strong><?= !$smsConfigured ? 'Neconfigurat' : ($smsEnabled ? 'Activ' : 'Oprit') ?></strong>
                    <span>
                        <?php if (!$smsConfigured): ?>
                            Completeaza SMSALERT_USERNAME si SMSALERT_API_KEY in .env.
                        <?php elseif (!$smsEnabled): ?>
                            SMSALERT_ENABLED=false: regulile cu SMS nu trimit nimic.
                        <?php else: ?>
                            Cont <?= e((string) ($serviceStatus['sms_username'] ?? '')) ?>
                        <?php endif; ?>
                    </span>
                    <?php if ($smsForceTo !== ''): ?>
                        <small>Mod test: toate SMS-urile merg la <?= e($smsForceTo) ?></small>
                    <?php endif; ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'notificari', 'action' => 'send_test_sms'])) ?>" class="d-flex gap-2 mt-2">
                        <?= csrf_field() ?>
                        <input class="form-control form-control-sm" name="test_phone" value="<?= e((string) ($currentUser['telefon'] ?? '')) ?>" placeholder="07xx xxx xxx" required aria-label="Telefon pentru SMS de test">
                        <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap" <?= $smsConfigured && $smsEnabled ? '' : 'disabled' ?>>
                            <i class="bi bi-phone" aria-hidden="true"></i> SMS test
                        </button>
                    </form>
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
    <?php
    $eventConditions = is_array($eventConditions ?? null) ? $eventConditions : [];
    $eventHints = is_array($eventHints ?? null) ? $eventHints : [];
    $hiddenEvents = is_array($hiddenEvents ?? null) ? $hiddenEvents : [];
    $hiddenRecipientModes = is_array($hiddenRecipientModes ?? null) ? $hiddenRecipientModes : [];
    $editingGroupSize = (int) ($editingGroupSize ?? 0);
    $approvalEvent = (string) ($approvalEvent ?? 'inactive_approval_pending');
    $selectedEvents = array_map('strval', (array) ($formData['event_types'] ?? []));
    $selectedDocTypes = is_array($formData['doc_types'] ?? null) ? $formData['doc_types'] : [];
    $docAll = is_array($formData['doc_all'] ?? null) ? $formData['doc_all'] : [];
    $selectedApprovalTypes = array_map('strval', (array) ($formData['approval_resource_types'] ?? []));
    $selectedApprovalReasons = array_map('strval', (array) ($formData['approval_reasons'] ?? []));
    $currentChannel = (string) ($formData['channel'] ?? 'email');
    $currentRecipientMode = (string) ($formData['recipient_mode'] ?? 'admins');

    $entityIcons = [
        'vehicle' => 'bi-truck',
        'driver' => 'bi-person-badge',
        'tire' => 'bi-record-circle',
        'leave' => 'bi-calendar2-week',
        'equipment' => 'bi-tools',
        'approval' => 'bi-check2-square',
        'revizie' => 'bi-wrench-adjustable',
    ];

    // Evenimentele pe entitati, in ordinea entitatilor din filtru.
    $eventsByEntity = [];
    foreach ($eventLabels as $eventValue => $eventLabel) {
        if (in_array($eventValue, $hiddenEvents, true) && !in_array($eventValue, $selectedEvents, true)) {
            continue;
        }
        $eventsByEntity[(string) ($eventEntityMap[$eventValue] ?? 'vehicle')][(string) $eventValue] = (string) $eventLabel;
    }

    $docFamilies = [
        'vehicle' => ['label' => 'Documente vehicul', 'options' => $vehicleDocumentTypes],
        'driver' => ['label' => 'Documente șofer', 'options' => $driverDocumentTypes],
        'equipment' => ['label' => 'Dotări', 'options' => $equipmentTypes],
    ];
    foreach ($docFamilies as $family => $config) {
        $options = array_map('strval', $config['options']);
        foreach ((array) ($selectedDocTypes[$family] ?? []) as $selectedType) {
            if (!in_array((string) $selectedType, $options, true)) {
                $options[] = (string) $selectedType;
            }
        }
        $docFamilies[$family]['options'] = $options;
    }

    $recipientDescriptions = [
        'admins' => 'Toți administratorii activi',
        'vehicle_responsible' => 'Momentan se trimite administratorilor',
        'drivers' => 'Doar șoferul vizat, prin SMS',
        'specific_users' => 'Alegi tu persoanele din listă',
    ];
    $recipientIcons = [
        'admins' => 'bi-shield-check',
        'vehicle_responsible' => 'bi-person-gear',
        'drivers' => 'bi-person-vcard',
        'specific_users' => 'bi-people',
    ];
    $fieldError = static function (string $key) use ($formErrors): string {
        return isset($formErrors[$key]) ? '<div class="nrf-error"><i class="bi bi-exclamation-circle"></i> ' . e((string) $formErrors[$key]) . '</div>' : '';
    };

    $nrfConfig = [
        'eventLabels' => $eventLabels,
        'entityLabels' => $entityLabels,
        'eventEntity' => $eventEntityMap,
        'conditions' => $eventConditions,
        'smsChannels' => array_values($smsChannels ?? ['sms', 'email_sms']),
        'approvalEvent' => $approvalEvent,
        'maxRules' => (int) ($maxRulesPerGroup ?? 60),
        'isEditing' => $isEditing,
        'groupSize' => $editingGroupSize,
        'closeUrl' => build_query_url(['page' => 'notificari']),
    ];
    ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/notificari-regula.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/notificari-regula.css'))) ?>">
    <div class="nrf-backdrop" data-nrf-close></div>
    <aside class="nrf-modal" role="dialog" aria-modal="true" aria-labelledby="nrfTitle">
        <header class="nrf-header">
            <div>
                <h3 id="nrfTitle"><?= $isEditing ? 'Editează regula de notificare' : 'Regulă nouă de notificare' ?></h3>
                <p>
                    <?php if ($isEditing && $editingGroupSize > 1): ?>
                        Grup de <?= e((string) $editingGroupSize) ?> reguli salvate împreună — modificările se aplică tuturor.
                    <?php else: ?>
                        Bifează unul sau mai multe evenimente; se creează câte o regulă pentru fiecare.
                    <?php endif; ?>
                </p>
            </div>
            <a class="nrf-close" href="<?= e(build_query_url(['page' => 'notificari'])) ?>" aria-label="Închide"><i class="bi bi-x-lg" aria-hidden="true"></i></a>
        </header>

        <form method="post" action="<?= e(build_query_url(['page' => 'notificari', 'action' => $isEditing ? 'update' : 'store'])) ?>" class="nrf-form" data-nrf-form novalidate>
            <?= csrf_field() ?>
            <?php if ($isEditing): ?>
                <input type="hidden" name="id" value="<?= e((string) $editingId) ?>">
            <?php endif; ?>
            <script type="application/json" data-nrf-config><?= json_encode($nrfConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

            <div class="nrf-body">
                <div class="nrf-main">
                    <?php if ($formErrors !== []): ?>
                        <div class="nrf-alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Verifică câmpurile marcate mai jos.</div>
                    <?php endif; ?>

                    <label class="nrf-name">
                        <span class="nrf-label">Nume regulă <small>(opțional — gol = generat din evenimente)</small></span>
                        <input class="form-control" name="name" value="<?= e((string) ($formData['name'] ?? '')) ?>" maxlength="190" placeholder="Ex: Documente vehicul" data-nrf-name>
                        <?= $fieldError('name') ?>
                    </label>

                    <section class="nrf-section">
                        <h4><span class="nrf-step">1</span> Ce urmărești?</h4>
                        <p class="nrf-section-hint">Poți bifa evenimente din mai multe categorii.</p>
                        <?= $fieldError('event_types') ?>
                        <div class="nrf-entities">
                            <?php foreach ($entityLabels as $entityValue => $entityLabel): ?>
                                <?php if (empty($eventsByEntity[$entityValue])) { continue; } ?>
                                <div class="nrf-entity" data-nrf-entity="<?= e((string) $entityValue) ?>">
                                    <div class="nrf-entity-head">
                                        <span><i class="bi <?= e($entityIcons[$entityValue] ?? 'bi-bell') ?>" aria-hidden="true"></i> <?= e((string) $entityLabel) ?></span>
                                        <span class="nrf-entity-count" data-nrf-entity-count></span>
                                    </div>
                                    <?php foreach ($eventsByEntity[$entityValue] as $eventValue => $eventLabel): ?>
                                        <label class="nrf-event">
                                            <input type="checkbox" name="event_types[]" value="<?= e($eventValue) ?>" data-nrf-event <?= in_array($eventValue, $selectedEvents, true) ? 'checked' : '' ?>>
                                            <span class="nrf-event-box" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
                                            <span class="nrf-event-text">
                                                <strong><?= e($eventLabel) ?></strong>
                                                <small><?= e((string) ($eventHints[$eventValue] ?? '')) ?></small>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="nrf-section">
                        <h4><span class="nrf-step">2</span> Când se trimite?</h4>
                        <p class="nrf-empty" data-nrf-conditions-empty>Bifează un eveniment mai sus ca să vezi condițiile lui.</p>

                        <div class="nrf-cond" data-nrf-cond="days" hidden>
                            <span class="nrf-label">Cu câte zile înainte</span>
                            <div class="nrf-inline">
                                <div class="input-group nrf-number">
                                    <input class="form-control" name="days_before" type="number" min="0" max="365" step="1" value="<?= e((string) ($formData['days_before'] ?? 30)) ?>" data-nrf-days>
                                    <span class="input-group-text">zile</span>
                                </div>
                                <div class="nrf-presets">
                                    <?php foreach ([7, 15, 30, 60, 90] as $preset): ?>
                                        <button type="button" class="nrf-chip" data-nrf-days-preset="<?= $preset ?>"><?= $preset ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <small class="nrf-help" data-nrf-days-for></small>
                            <?= $fieldError('days_before') ?>
                        </div>

                        <?php foreach ($docFamilies as $family => $config): ?>
                            <?php
                            $familyAll = (int) ($docAll[$family] ?? 1) === 1;
                            $familySelected = array_map('strval', (array) ($selectedDocTypes[$family] ?? []));
                            ?>
                            <div class="nrf-cond" data-nrf-cond="docs-<?= e($family) ?>" hidden>
                                <span class="nrf-label"><?= e($config['label']) ?></span>
                                <div class="nrf-segmented" role="radiogroup">
                                    <label><input type="radio" name="doc_all[<?= e($family) ?>]" value="1" data-nrf-doc-all="<?= e($family) ?>" <?= $familyAll ? 'checked' : '' ?>><span>Toate tipurile</span></label>
                                    <label><input type="radio" name="doc_all[<?= e($family) ?>]" value="0" data-nrf-doc-all="<?= e($family) ?>" <?= $familyAll ? '' : 'checked' ?>><span>Doar anumite</span></label>
                                </div>
                                <div class="nrf-doc-picker" data-nrf-doc-picker="<?= e($family) ?>" <?= $familyAll ? 'hidden' : '' ?>>
                                    <div class="nrf-chips" data-nrf-doc-list="<?= e($family) ?>">
                                        <?php foreach ($config['options'] as $docType): ?>
                                            <label class="nrf-chip-check">
                                                <input type="checkbox" name="doc_types[<?= e($family) ?>][]" value="<?= e($docType) ?>" <?= in_array($docType, $familySelected, true) ? 'checked' : '' ?>>
                                                <span><?= e($docType) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="nrf-add-doc">
                                        <input class="form-control form-control-sm" placeholder="Alt tip…" data-nrf-doc-new="<?= e($family) ?>" maxlength="120">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-nrf-doc-add="<?= e($family) ?>"><i class="bi bi-plus-lg"></i> Adaugă</button>
                                    </div>
                                    <small class="nrf-help">Fiecare tip bifat devine o regulă separată.</small>
                                </div>
                                <?= $fieldError('doc_types_' . $family) ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="nrf-cond-row">
                            <div class="nrf-cond" data-nrf-cond="km" hidden>
                                <span class="nrf-label">Anvelope: prag kilometri</span>
                                <div class="input-group nrf-number">
                                    <input class="form-control" name="threshold_km" type="number" min="0" step="1" value="<?= e((string) ($formData['threshold_km'] ?? '')) ?>" placeholder="15000">
                                    <span class="input-group-text">km</span>
                                </div>
                                <?= $fieldError('threshold_km') ?>
                            </div>
                            <div class="nrf-cond" data-nrf-cond="tread" hidden>
                                <span class="nrf-label">Anvelope: prag profil</span>
                                <div class="input-group nrf-number">
                                    <input class="form-control" name="threshold_tread_depth" type="number" min="0" step="0.1" value="<?= e((string) ($formData['threshold_tread_depth'] ?? '')) ?>" placeholder="3.0">
                                    <span class="input-group-text">mm</span>
                                </div>
                                <?= $fieldError('threshold_tread_depth') ?>
                            </div>
                        </div>

                        <div class="nrf-cond" data-nrf-cond="revizie" hidden>
                            <span class="nrf-label">Revizii: notifică la</span>
                            <div class="nrf-segmented">
                                <?php foreach (($revizieStatusFilters ?? []) as $value => $label): ?>
                                    <label><input type="radio" name="revizie_status" value="<?= e((string) $value) ?>" <?= (string) ($formData['revizie_status'] ?? '') === (string) $value ? 'checked' : '' ?>><span><?= e((string) $label) ?></span></label>
                                <?php endforeach; ?>
                            </div>
                            <small class="nrf-help">
                                Km rămași și pragul se iau din <a href="<?= e(build_query_url(['page' => 'revizii', 'tip' => 'prezentare'])) ?>" target="_blank" rel="noopener">Revizii</a>, per componentă.
                                Fiecare ciclu nou și trecerea în „Necesită” trimit o notificare nouă.
                            </small>
                        </div>

                        <div class="nrf-cond" data-nrf-cond="approval" hidden>
                            <span class="nrf-label">Cereri de aprobare: tip resursă <small>(nimic bifat = toate)</small></span>
                            <div class="nrf-chips">
                                <?php foreach (($approvalResourceLabels ?? []) as $value => $label): ?>
                                    <label class="nrf-chip-check">
                                        <input type="checkbox" name="approval_resource_types[]" value="<?= e((string) $value) ?>" <?= in_array((string) $value, $selectedApprovalTypes, true) ? 'checked' : '' ?>>
                                        <span><?= e((string) $label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php if (($approvalReasonOptions ?? []) !== []): ?>
                                <span class="nrf-label mt-2">Motiv <small>(nimic bifat = toate)</small></span>
                                <div class="nrf-chips">
                                    <?php foreach ($approvalReasonOptions as $value => $label): ?>
                                        <label class="nrf-chip-check">
                                            <input type="checkbox" name="approval_reasons[]" value="<?= e((string) $value) ?>" <?= in_array((string) $value, $selectedApprovalReasons, true) ? 'checked' : '' ?>>
                                            <span><?= e((string) $label) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <span class="nrf-label mt-2">Reamintește la</span>
                            <div class="input-group nrf-number">
                                <input class="form-control" name="approval_repeat_days" type="number" min="1" max="30" step="1" value="<?= e((string) ($formData['approval_repeat_days'] ?? 1)) ?>">
                                <span class="input-group-text">zile</span>
                            </div>
                            <small class="nrf-help">Are efect doar cu „Repetă până la rezolvare”.</small>
                        </div>
                    </section>

                    <section class="nrf-section">
                        <h4><span class="nrf-step">3</span> Cine primește și cum?</h4>
                        <span class="nrf-label">Canal</span>
                        <div class="nrf-channel">
                            <?php foreach ($channelLabels as $value => $label): ?>
                                <label>
                                    <input type="radio" name="channel" value="<?= e((string) $value) ?>" data-nrf-channel <?= $currentChannel === (string) $value ? 'checked' : '' ?>>
                                    <span><i class="bi <?= e($channelIcon((string) $value)) ?>" aria-hidden="true"></i> <?= e((string) $label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?= $fieldError('channel') ?>

                        <label class="nrf-switch" data-nrf-driver-option hidden>
                            <input class="form-check-input" type="checkbox" role="switch" name="notify_driver" value="1" <?= (int) ($formData['notify_driver'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <span>
                                <strong>Trimite SMS și șoferului</strong>
                                <small>Șoferii alocați vehiculului (la documente de șofer, concedii și zile de naștere: șoferul respectiv), pe numărul din fișa lui. Se adaugă la destinatarii de mai jos.</small>
                            </span>
                        </label>

                        <span class="nrf-label mt-3">Destinatari</span>
                        <div class="nrf-recipients">
                            <?php foreach ($recipientLabels as $value => $label): ?>
                                <?php if (in_array($value, $hiddenRecipientModes, true) && $currentRecipientMode !== $value) { continue; } ?>
                                <label class="nrf-recipient">
                                    <input type="radio" name="recipient_mode" value="<?= e((string) $value) ?>" data-nrf-recipient <?= $currentRecipientMode === (string) $value ? 'checked' : '' ?>>
                                    <span>
                                        <i class="bi <?= e($recipientIcons[$value] ?? 'bi-person') ?>" aria-hidden="true"></i>
                                        <strong><?= e((string) $label) ?></strong>
                                        <small><?= e($recipientDescriptions[$value] ?? '') ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?= $fieldError('recipient_mode') ?>

                        <div class="nrf-users" data-nrf-users hidden>
                            <div class="nrf-users-toolbar">
                                <div class="nrf-users-search">
                                    <i class="bi bi-search" aria-hidden="true"></i>
                                    <input class="form-control form-control-sm" type="search" placeholder="Caută utilizator…" data-nrf-user-search>
                                </div>
                                <span class="nrf-users-count" data-nrf-user-count></span>
                                <button type="button" class="btn btn-sm btn-link" data-nrf-users-all>Toți</button>
                                <button type="button" class="btn btn-sm btn-link" data-nrf-users-none>Niciunul</button>
                            </div>
                            <div class="nrf-users-list">
                                <?php foreach ($users as $user): ?>
                                    <?php
                                    $userId = (int) ($user['id'] ?? 0);
                                    $userEmail = trim((string) ($user['email'] ?? ''));
                                    $userPhone = trim((string) ($user['telefon'] ?? ''));
                                    ?>
                                    <label class="nrf-user" data-nrf-user data-search="<?= e(mb_strtolower(($user['nume'] ?? '') . ' ' . $userEmail . ' ' . $userPhone, 'UTF-8')) ?>" data-has-email="<?= $userEmail !== '' ? '1' : '0' ?>" data-has-phone="<?= $userPhone !== '' ? '1' : '0' ?>">
                                        <input class="form-check-input" type="checkbox" name="recipient_user_ids[]" value="<?= e((string) $userId) ?>" <?= in_array($userId, $selectedRecipientIds, true) ? 'checked' : '' ?>>
                                        <span class="nrf-user-avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['nume'] ?? '?'), 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                                        <span class="nrf-user-text">
                                            <strong><?= e((string) ($user['nume'] ?? '')) ?><?php if ((string) ($user['rol'] ?? '') === 'admin'): ?> <em>admin</em><?php endif; ?></strong>
                                            <small>
                                                <span class="<?= $userEmail === '' ? 'is-missing' : '' ?>" data-nrf-user-email><i class="bi bi-envelope"></i> <?= e($userEmail !== '' ? $userEmail : 'fără email') ?></span>
                                                <span class="<?= $userPhone === '' ? 'is-missing' : '' ?>" data-nrf-user-phone><i class="bi bi-phone"></i> <?= e($userPhone !== '' ? $userPhone : 'fără telefon') ?></span>
                                            </small>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?= $fieldError('recipient_user_ids') ?>
                        </div>
                    </section>

                    <section class="nrf-section">
                        <h4><span class="nrf-step">4</span> Opțiuni</h4>
                        <div class="nrf-options">
                            <label class="nrf-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1" <?= (int) ($formData['enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                                <span><strong>Activă</strong><small>Worker-ul o verifică la fiecare rulare.</small></span>
                            </label>
                            <label class="nrf-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="repeat_until_resolved" value="1" <?= (int) ($formData['repeat_until_resolved'] ?? 1) === 1 ? 'checked' : '' ?>>
                                <span><strong>Repetă până la rezolvare</strong><small>Trimite din nou cât timp situația persistă.</small></span>
                            </label>
                            <label class="nrf-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="daily_limit_enabled" value="1" <?= (int) ($formData['daily_limit_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
                                <span><strong>Maxim o notificare pe zi</strong><small>Pentru același document / vehicul și destinatar.</small></span>
                            </label>
                        </div>
                    </section>
                </div>

                <aside class="nrf-summary">
                    <div class="nrf-summary-card">
                        <div class="nrf-summary-title">Rezumat</div>
                        <p class="nrf-summary-sentence" data-nrf-sentence></p>
                        <div class="nrf-summary-sub" data-nrf-count></div>
                        <ul class="nrf-summary-list" data-nrf-preview></ul>
                        <div class="nrf-summary-warnings" data-nrf-warnings></div>
                    </div>
                </aside>
            </div>

            <footer class="nrf-footer">
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'notificari'])) ?>">Anulează</a>
                <button type="submit" class="btn btn-primary" data-nrf-submit><i class="bi bi-check2" aria-hidden="true"></i> <span data-nrf-submit-label>Salvează</span></button>
            </footer>
        </form>
    </aside>
    <script src="<?= e(url('assets/js/notificari-regula.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/notificari-regula.js'))) ?>"></script>
<?php endif; ?>
