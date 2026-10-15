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
    if ($event === ReviziiWeeklyReportService::EVENT) {
        $ruleMetadata = json_decode((string) ($rule['metadata_json'] ?? ''), true);
        $reportConfig = ReviziiWeeklyReportService::normalizeConfig(is_array($ruleMetadata['report'] ?? null) ? $ruleMetadata['report'] : []);
        return 'Săptămânal: ' . ReviziiWeeklyReportService::scheduleLabel($reportConfig);
    }
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

    // Doar afisare: ordinea si etichetele cu diacritice din macheta. Valorile salvate raman cele din controller.
    $categoryOrder = ['vehicle', 'driver', 'tire', 'revizie', 'leave', 'equipment', 'approval'];
    $categoryDisplay = [
        'vehicle' => ['label' => 'Vehicul', 'icon' => 'bi-truck', 'events' => 'Evenimente vehicul'],
        'driver' => ['label' => 'Șofer', 'icon' => 'bi-person', 'events' => 'Evenimente șofer'],
        'tire' => ['label' => 'Anvelopă', 'icon' => 'bi-life-preserver', 'events' => 'Evenimente anvelopă'],
        'revizie' => ['label' => 'Revizii', 'icon' => 'bi-wrench-adjustable', 'events' => 'Evenimente revizii (km)'],
        'leave' => ['label' => 'Concediu', 'icon' => 'bi-calendar-week', 'events' => 'Evenimente concediu'],
        'equipment' => ['label' => 'Dotare / Inventar', 'icon' => 'bi-box-seam', 'events' => 'Evenimente dotare / inventar'],
        'approval' => ['label' => 'Solicitare aprobare', 'icon' => 'bi-file-earmark-text', 'events' => 'Evenimente solicitare aprobare'],
    ];
    $eventDisplayLabels = [
        'vehicle_document_expiry' => 'Expirare document',
        'driver_document_expiry' => 'Expirare document',
        'driver_birthday' => 'Zi de naștere',
        'leave_starts_soon' => 'Concediu începe în curând',
        'tire_km_limit' => 'Limită kilometri',
        'tire_tread_depth' => 'Adâncime profil',
        'tire_dot_expiry' => 'DOT expiră',
        'equipment_expiry' => 'Expirare dotare',
        'equipment_inspection' => 'Inspecție dotare',
        'document_missing' => 'Document lipsă',
        'inactive_approval_pending' => 'Cerere aprobare în așteptare',
        'revizie_schimb_ulei' => 'Schimb ulei',
        'revizie_placute_frana' => 'Plăcuțe frână',
        'revizie_anvelope' => 'Anvelope (revizie km)',
        'revizie_raport_saptamanal' => 'Raport săptămânal (email)',
    ];

    // Evenimentele pe categorii (evenimentele ascunse apar doar daca regula editata le foloseste).
    $eventsByEntity = [];
    foreach ($eventLabels as $eventValue => $eventLabel) {
        if (in_array($eventValue, $hiddenEvents, true) && !in_array($eventValue, $selectedEvents, true)) {
            continue;
        }
        $eventsByEntity[(string) ($eventEntityMap[$eventValue] ?? 'vehicle')][(string) $eventValue] = (string) ($eventDisplayLabels[$eventValue] ?? $eventLabel);
    }
    foreach (array_keys($eventsByEntity) as $entityKey) {
        if (!in_array($entityKey, $categoryOrder, true)) {
            $categoryOrder[] = $entityKey;
            $categoryDisplay[$entityKey] = ['label' => (string) ($entityLabels[$entityKey] ?? $entityKey), 'icon' => 'bi-bell', 'events' => 'Evenimente'];
        }
    }

    // Categoria deschisa initial: prima cu evenimente bifate, altfel Vehicul.
    $activeCategory = '';
    foreach ($categoryOrder as $entityKey) {
        foreach (array_keys($eventsByEntity[$entityKey] ?? []) as $eventValue) {
            if ($activeCategory === '' && in_array($eventValue, $selectedEvents, true)) {
                $activeCategory = $entityKey;
            }
        }
    }
    if ($activeCategory === '') {
        foreach ($categoryOrder as $entityKey) {
            if (!empty($eventsByEntity[$entityKey])) {
                $activeCategory = $entityKey;
                break;
            }
        }
    }

    $docFamilies = [
        'vehicle' => ['label' => 'Tipuri de document (vehicul)', 'options' => $vehicleDocumentTypes, 'active' => 'Doar vehicule active', 'activeHelp' => 'Se vor include doar vehiculele cu status activ.'],
        'driver' => ['label' => 'Tipuri de document (șofer)', 'options' => $driverDocumentTypes, 'active' => 'Doar șoferi activi', 'activeHelp' => 'Se vor include doar șoferii cu status activ.'],
        'equipment' => ['label' => 'Dotări', 'options' => $equipmentTypes, 'active' => 'Doar vehicule active', 'activeHelp' => 'Se vor include doar dotările vehiculelor active.'],
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

    $recipientDisplay = [
        'admins' => ['label' => 'Administratori', 'icon' => 'bi-shield-check', 'help' => 'Toți administratorii activi'],
        'vehicle_responsible' => ['label' => 'Responsabil vehicul', 'icon' => 'bi-person-gear', 'help' => 'Momentan se trimite administratorilor'],
        'drivers' => ['label' => 'Doar șoferul (SMS)', 'icon' => 'bi-person-vcard', 'help' => 'Șoferul vizat sau șoferii aleși, prin SMS'],
        'specific_users' => ['label' => 'Utilizatori selectați', 'icon' => 'bi-people', 'help' => 'Alegi tu persoanele din listă'],
    ];
    $visibleRecipientModes = array_values(array_filter(
        array_keys($recipientLabels),
        static fn ($mode): bool => !in_array($mode, $hiddenRecipientModes, true) || $currentRecipientMode === $mode
    ));

    $fieldError = static function (string $key) use ($formErrors): string {
        return isset($formErrors[$key]) ? '<div class="nrf-error"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> ' . e((string) $formErrors[$key]) . '</div>' : '';
    };

    $nrfConfig = [
        // eventLabels / entityLabels = etichetele serverului (pentru numele generat, identic cu cel salvat).
        'eventLabels' => $eventLabels,
        'entityLabels' => $entityLabels,
        'eventDisplay' => $eventDisplayLabels,
        'entityDisplay' => array_map(static fn (array $item): string => $item['label'], $categoryDisplay),
        'eventEntity' => $eventEntityMap,
        'conditions' => $eventConditions,
        'revizieStatus' => $revizieStatusFilters ?? [],
        'smsChannels' => array_values($smsChannels ?? ['sms', 'email_sms']),
        'approvalEvent' => $approvalEvent,
        'maxRules' => (int) ($maxRulesPerGroup ?? 60),
        'isEditing' => $isEditing,
        'groupSize' => $editingGroupSize,
        'hasErrors' => $formErrors !== [],
        'closeUrl' => build_query_url(['page' => 'notificari']),
    ];
    ?>
    <link rel="stylesheet" href="<?= e(url('assets/css/notificari-regula.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/notificari-regula.css'))) ?>">
    <div class="nrf-backdrop" data-nrf-backdrop></div>
    <div class="nrf-modal" role="dialog" aria-modal="true" aria-labelledby="nrfTitle" aria-describedby="nrfSubtitle" data-nrf-modal>
        <header class="nrf-header">
            <span class="nrf-header-icon" aria-hidden="true"><i class="bi bi-bell"></i></span>
            <div class="nrf-header-text">
                <h3 id="nrfTitle" tabindex="-1"><?= $isEditing ? 'Editează regula de notificare' : 'Regulă nouă de notificare' ?></h3>
                <p id="nrfSubtitle">
                    <?php if ($isEditing && $editingGroupSize > 1): ?>
                        Grup de <?= e((string) $editingGroupSize) ?> reguli salvate împreună — modificările se aplică tuturor.
                    <?php else: ?>
                        Configurează evenimentele, condițiile și destinatarii pentru această regulă.
                    <?php endif; ?>
                </p>
            </div>
            <a class="nrf-close" href="<?= e(build_query_url(['page' => 'notificari'])) ?>" aria-label="Închide" data-nrf-close><i class="bi bi-x-lg" aria-hidden="true"></i></a>
        </header>

        <form method="post" action="<?= e(build_query_url(['page' => 'notificari', 'action' => $isEditing ? 'update' : 'store'])) ?>" class="nrf-form" data-nrf-form novalidate>
            <?= csrf_field() ?>
            <?php if ($isEditing): ?>
                <input type="hidden" name="id" value="<?= e((string) $editingId) ?>">
            <?php endif; ?>
            <script type="application/json" data-nrf-config><?= json_encode($nrfConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

            <div class="nrf-scroll" data-nrf-scroll>
                <?php if ($formErrors !== []): ?>
                    <div class="nrf-alert" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Verifică câmpurile marcate mai jos.</div>
                <?php endif; ?>

                <div class="nrf-name">
                    <label class="nrf-label" for="nrfName">Nume regulă</label>
                    <div class="nrf-name-row">
                        <input class="form-control" id="nrfName" name="name" value="<?= e((string) ($formData['name'] ?? '')) ?>" maxlength="190" placeholder="Ex: Documente vehicul" data-nrf-name>
                        <button type="button" class="btn nrf-btn-ghost" data-nrf-generate-name><i class="bi bi-magic" aria-hidden="true"></i> Generează automat</button>
                    </div>
                    <small class="nrf-help">Lasă gol pentru generare automată în funcție de selecțiile făcute.</small>
                    <?= $fieldError('name') ?>
                </div>

                <section class="nrf-section" aria-labelledby="nrfStep1">
                    <div class="nrf-section-head">
                        <span class="nrf-step" aria-hidden="true">1</span>
                        <div>
                            <h4 id="nrfStep1">Ce urmărești?</h4>
                            <p>Selectează categoria și evenimentul (poți selecta mai multe).</p>
                        </div>
                    </div>

                    <div class="nrf-categories" role="tablist" aria-label="Categorii de evenimente">
                        <?php foreach ($categoryOrder as $entityKey): ?>
                            <?php if (empty($eventsByEntity[$entityKey])) { continue; } ?>
                            <?php $display = $categoryDisplay[$entityKey]; $eventCount = count($eventsByEntity[$entityKey]); ?>
                            <button type="button" class="nrf-category <?= $entityKey === $activeCategory ? 'is-current' : '' ?>" role="tab"
                                    id="nrfCat-<?= e($entityKey) ?>" aria-controls="nrfPanel-<?= e($entityKey) ?>"
                                    aria-selected="<?= $entityKey === $activeCategory ? 'true' : 'false' ?>"
                                    data-nrf-category="<?= e($entityKey) ?>" data-total="<?= $eventCount ?>">
                                <span class="nrf-category-check" aria-hidden="true"><i class="bi bi-check"></i></span>
                                <i class="bi <?= e($display['icon']) ?> nrf-category-icon" aria-hidden="true"></i>
                                <strong><?= e($display['label']) ?></strong>
                                <small data-nrf-entity-count><?= $eventCount ?> <?= $eventCount === 1 ? 'eveniment' : 'evenimente' ?></small>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <?php foreach ($categoryOrder as $entityKey): ?>
                        <?php if (empty($eventsByEntity[$entityKey])) { continue; } ?>
                        <div class="nrf-events-panel" role="tabpanel" id="nrfPanel-<?= e($entityKey) ?>" aria-labelledby="nrfCat-<?= e($entityKey) ?>"
                             data-nrf-entity="<?= e($entityKey) ?>" <?= $entityKey === $activeCategory ? '' : 'hidden' ?>>
                            <div class="nrf-subhead">
                                <strong><?= e($categoryDisplay[$entityKey]['events']) ?></strong>
                                <small>Selectează unul sau mai multe evenimente:</small>
                            </div>
                            <div class="nrf-event-grid">
                                <?php foreach ($eventsByEntity[$entityKey] as $eventValue => $eventLabel): ?>
                                    <label class="nrf-event" title="<?= e((string) ($eventHints[$eventValue] ?? '')) ?>">
                                        <input class="form-check-input" type="checkbox" name="event_types[]" value="<?= e($eventValue) ?>" data-nrf-event <?= in_array($eventValue, $selectedEvents, true) ? 'checked' : '' ?>>
                                        <span class="nrf-event-text">
                                            <span><?= e($eventLabel) ?></span>
                                            <?php if (($eventHints[$eventValue] ?? '') !== ''): ?>
                                                <small><?= e((string) $eventHints[$eventValue]) ?></small>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?= $fieldError('event_types') ?>
                </section>

                <section class="nrf-section" aria-labelledby="nrfStep2">
                    <div class="nrf-section-head">
                        <span class="nrf-step" aria-hidden="true">2</span>
                        <div>
                            <h4 id="nrfStep2">Când se trimite?</h4>
                            <p>Configurează condițiile de declanșare pentru evenimentele selectate.</p>
                        </div>
                    </div>

                    <div class="nrf-cond-card" data-nrf-cond-card>
                        <div class="nrf-cond-card-head">
                            <strong data-nrf-cond-title>Condiții</strong>
                            <span class="nrf-pill" data-nrf-cond-badge></span>
                            <button type="button" class="nrf-collapse" aria-expanded="true" aria-controls="nrfCondBody" aria-label="Restrânge condițiile" data-nrf-cond-toggle><i class="bi bi-chevron-up" aria-hidden="true"></i></button>
                        </div>
                        <div class="nrf-cond-body" id="nrfCondBody" data-nrf-cond-body>
                            <p class="nrf-empty" data-nrf-conditions-empty>Bifează un eveniment mai sus ca să vezi condițiile lui.</p>

                            <div class="nrf-cond-grid">
                                <div class="nrf-field" data-nrf-cond="days" hidden>
                                    <label class="nrf-label" for="nrfDays">Cu câte zile înainte</label>
                                    <div class="nrf-days-row">
                                        <input class="form-control" id="nrfDays" name="days_before" type="number" min="0" max="365" step="1" value="<?= e((string) ($formData['days_before'] ?? 30)) ?>" data-nrf-days>
                                        <span class="nrf-unit">zile</span>
                                    </div>
                                    <div class="nrf-presets" aria-label="Valori rapide">
                                        <?php foreach ([7, 15, 30, 60, 90] as $preset): ?>
                                            <button type="button" class="nrf-preset" data-nrf-days-preset="<?= $preset ?>"><?= $preset ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                    <small class="nrf-help" data-nrf-days-for></small>
                                    <?= $fieldError('days_before') ?>
                                </div>

                                <?php foreach ($docFamilies as $family => $config): ?>
                                    <?php
                                    $familyAll = (int) ($docAll[$family] ?? 1) === 1;
                                    $familySelected = array_map('strval', (array) ($selectedDocTypes[$family] ?? []));
                                    ?>
                                    <div class="nrf-field" data-nrf-cond="docs-<?= e($family) ?>" hidden>
                                        <label class="nrf-label" for="nrfDocAll-<?= e($family) ?>"><?= e($config['label']) ?></label>
                                        <div class="nrf-select-icon">
                                            <i class="bi bi-files" aria-hidden="true"></i>
                                            <select class="form-select" id="nrfDocAll-<?= e($family) ?>" name="doc_all[<?= e($family) ?>]" data-nrf-doc-all="<?= e($family) ?>">
                                                <option value="1" <?= $familyAll ? 'selected' : '' ?>>Toate tipurile</option>
                                                <option value="0" <?= $familyAll ? '' : 'selected' ?>>Doar anumite tipuri</option>
                                            </select>
                                        </div>
                                        <label class="nrf-fixed-check">
                                            <input class="form-check-input" type="checkbox" checked disabled>
                                            <span><strong><?= e($config['active']) ?></strong><small><?= e($config['activeHelp']) ?></small></span>
                                        </label>
                                    </div>
                                    <div class="nrf-field nrf-field-wide" data-nrf-doc-picker="<?= e($family) ?>" hidden>
                                        <span class="nrf-label">Alege tipurile <small>— fiecare tip bifat devine o regulă separată</small></span>
                                        <div class="nrf-chips" data-nrf-doc-list="<?= e($family) ?>">
                                            <?php foreach ($config['options'] as $docType): ?>
                                                <label class="nrf-chip">
                                                    <input type="checkbox" name="doc_types[<?= e($family) ?>][]" value="<?= e($docType) ?>" <?= in_array($docType, $familySelected, true) ? 'checked' : '' ?>>
                                                    <span><?= e($docType) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="nrf-add-doc">
                                            <input class="form-control form-control-sm" placeholder="Alt tip…" aria-label="Alt tip de document" data-nrf-doc-new="<?= e($family) ?>" maxlength="120">
                                            <button type="button" class="btn btn-sm nrf-btn-ghost" data-nrf-doc-add="<?= e($family) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă</button>
                                        </div>
                                        <?= $fieldError('doc_types_' . $family) ?>
                                    </div>
                                <?php endforeach; ?>

                                <div class="nrf-field" data-nrf-cond="km" hidden>
                                    <label class="nrf-label" for="nrfKm">Anvelope: prag kilometri</label>
                                    <div class="nrf-days-row">
                                        <input class="form-control" id="nrfKm" name="threshold_km" type="number" min="0" step="1" value="<?= e((string) ($formData['threshold_km'] ?? '')) ?>" placeholder="15000">
                                        <span class="nrf-unit">km</span>
                                    </div>
                                    <?= $fieldError('threshold_km') ?>
                                </div>
                                <div class="nrf-field" data-nrf-cond="tread" hidden>
                                    <label class="nrf-label" for="nrfTread">Anvelope: prag profil</label>
                                    <div class="nrf-days-row">
                                        <input class="form-control" id="nrfTread" name="threshold_tread_depth" type="number" min="0" step="0.1" value="<?= e((string) ($formData['threshold_tread_depth'] ?? '')) ?>" placeholder="3.0">
                                        <span class="nrf-unit">mm</span>
                                    </div>
                                    <?= $fieldError('threshold_tread_depth') ?>
                                </div>

                                <div class="nrf-field" data-nrf-cond="revizie" hidden>
                                    <label class="nrf-label" for="nrfRevizie">Revizii: notifică la</label>
                                    <div class="nrf-select-icon">
                                        <i class="bi bi-speedometer2" aria-hidden="true"></i>
                                        <select class="form-select" id="nrfRevizie" name="revizie_status" data-nrf-revizie>
                                            <?php foreach (($revizieStatusFilters ?? []) as $value => $label): ?>
                                                <option value="<?= e((string) $value) ?>" <?= (string) ($formData['revizie_status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <small class="nrf-help">
                                        Km rămași și pragul se iau din <a href="<?= e(build_query_url(['page' => 'revizii', 'tip' => 'prezentare'])) ?>" target="_blank" rel="noopener">Revizii</a>, per componentă.
                                    </small>
                                </div>

                                <?php
                                $reportDays = is_array($reportDays ?? null) ? $reportDays : [];
                                $reportCategories = is_array($reportCategories ?? null) ? $reportCategories : [];
                                $selectedReportCategories = array_map('strval', (array) ($formData['report_categories'] ?? []));
                                ?>
                                <div class="nrf-field nrf-field-wide nrf-report" data-nrf-cond="report" hidden>
                                    <div class="nrf-report-head">
                                        <i class="bi bi-envelope-paper" aria-hidden="true"></i>
                                        <div>
                                            <strong>Raport săptămânal pe email</strong>
                                            <small>Situația reviziilor (Necesită / Se apropie, pe categorii) trimisă automat o dată pe săptămână destinatarilor de la pasul 3. Raportul pleacă pe email; opțional, șoferii primesc un SMS.</small>
                                        </div>
                                    </div>
                                    <div class="nrf-report-grid">
                                        <div>
                                            <label class="nrf-label" for="nrfReportDay">Ziua</label>
                                            <div class="nrf-select-icon">
                                                <i class="bi bi-calendar-week" aria-hidden="true"></i>
                                                <select class="form-select" id="nrfReportDay" name="report_day" data-nrf-report-day>
                                                    <?php foreach ($reportDays as $value => $label): ?>
                                                        <option value="<?= (int) $value ?>" <?= (int) ($formData['report_day'] ?? 1) === (int) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="nrf-label" for="nrfReportHour">Ora</label>
                                            <div class="nrf-select-icon">
                                                <i class="bi bi-clock" aria-hidden="true"></i>
                                                <select class="form-select" id="nrfReportHour" name="report_hour" data-nrf-report-hour>
                                                    <?php for ($hour = 0; $hour <= 23; $hour++): ?>
                                                        <option value="<?= $hour ?>" <?= (int) ($formData['report_hour'] ?? 8) === $hour ? 'selected' : '' ?>><?= sprintf('%02d:00', $hour) ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="nrf-report-cats">
                                            <span class="nrf-label">Categorii <small>(nimic bifat = toate)</small></span>
                                            <div class="nrf-chips">
                                                <?php foreach ($reportCategories as $value => $label): ?>
                                                    <label class="nrf-chip">
                                                        <input type="checkbox" name="report_categories[]" value="<?= e((string) $value) ?>" <?= in_array((string) $value, $selectedReportCategories, true) ? 'checked' : '' ?>>
                                                        <span><?= e((string) $label) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                    $reportVehicles = is_array($reportVehicles ?? null) ? $reportVehicles : [];
                                    $selectedReportVehicles = array_map('intval', (array) ($formData['report_vehicles'] ?? []));
                                    ?>
                                    <div class="nrf-report-vehicles">
                                        <div class="nrf-report-vehicles-head">
                                            <span class="nrf-label">Vehicule <small>(nimic bifat = toată flota; cu vehicule alese apar și componentele în regulă)</small></span>
                                            <div class="nrf-users-search">
                                                <i class="bi bi-search" aria-hidden="true"></i>
                                                <input class="form-control form-control-sm" type="search" placeholder="Caută nr. auto…" aria-label="Caută vehicul" data-nrf-vehicle-search>
                                            </div>
                                        </div>
                                        <div class="nrf-chips nrf-chips-scroll">
                                            <?php foreach ($reportVehicles as $vehicle): ?>
                                                <label class="nrf-chip" data-nrf-vehicle data-search="<?= e(strtolower((string) $vehicle['nr_inmatriculare'] . ' ' . str_replace(' ', '', (string) $vehicle['nr_inmatriculare']) . ' ' . (string) $vehicle['marca'])) ?>">
                                                    <input type="checkbox" name="report_vehicles[]" value="<?= (int) $vehicle['id'] ?>" <?= in_array((int) $vehicle['id'], $selectedReportVehicles, true) ? 'checked' : '' ?>>
                                                    <span><?= e((string) $vehicle['nr_inmatriculare']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="form-check form-switch nrf-switch nrf-switch-inline">
                                        <input class="form-check-input" type="checkbox" role="switch" id="nrfReportHistory" name="report_history" value="1" <?= (int) ($formData['report_history'] ?? 1) === 1 ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="nrfReportHistory">
                                            <strong>Include reviziile efectuate în ultimele 7 zile</strong>
                                            <small>Lista înlocuirilor înregistrate în Revizii în ultima săptămână.</small>
                                        </label>
                                    </div>
                                    <div class="form-check form-switch nrf-switch nrf-switch-inline">
                                        <input class="form-check-input" type="checkbox" role="switch" id="nrfReportNotifyDriver" name="report_notify_driver" value="1" <?= (int) ($formData['report_notify_driver'] ?? 0) === 1 ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="nrfReportNotifyDriver">
                                            <strong>Trimite SMS șoferilor vehiculelor din raport</strong>
                                            <small>O dată pe săptămână, odată cu raportul: șoferul e rugat să-l sune pe destinatarul raportului (primul cu telefon în cont) și să-i comunice km de la bord.</small>
                                        </label>
                                    </div>
                                    <div class="nrf-report-test">
                                        <button type="submit" class="btn btn-sm nrf-btn-ghost" formaction="<?= e(build_query_url(['page' => 'notificari', 'action' => 'report_preview'])) ?>" formtarget="_blank" formnovalidate data-nrf-report-preview>
                                            <i class="bi bi-eye" aria-hidden="true"></i> Previzualizează raportul
                                        </button>
                                        <button type="button" class="btn btn-sm nrf-btn-ghost nrf-btn-excel" data-nrf-report-excel data-url="<?= e(build_query_url(['page' => 'revizii', 'action' => 'raport_export'])) ?>">
                                            <i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Exportă Excel
                                        </button>
                                        <div class="nrf-report-send">
                                            <select class="form-select form-select-sm" name="report_test_target" aria-label="Destinatar test" data-nrf-report-target>
                                                <option value="me">către mine (<?= e((string) ($currentUser['email'] ?? '')) ?>)</option>
                                                <option value="rule">către destinatarii de la pasul 3</option>
                                            </select>
                                            <button type="button" class="btn btn-sm nrf-btn-ghost" data-nrf-report-send data-url="<?= e(build_query_url(['page' => 'notificari', 'action' => 'report_test'])) ?>">
                                                <i class="bi bi-send" aria-hidden="true"></i> Trimite test acum
                                            </button>
                                        </div>
                                        <div class="nrf-report-result" data-nrf-report-result role="status" hidden></div>
                                    </div>
                                    <small class="nrf-help">Programarea rulează pe server din cron (<code>scripts/revizii_raport_saptamanal.php</code>); testul nu consumă trimiterea săptămânii.</small>
                                </div>

                                <div class="nrf-field nrf-field-wide" data-nrf-cond="approval" hidden>
                                    <span class="nrf-label">Cereri de aprobare: tip resursă <small>(nimic bifat = toate)</small></span>
                                    <div class="nrf-chips">
                                        <?php foreach (($approvalResourceLabels ?? []) as $value => $label): ?>
                                            <label class="nrf-chip">
                                                <input type="checkbox" name="approval_resource_types[]" value="<?= e((string) $value) ?>" <?= in_array((string) $value, $selectedApprovalTypes, true) ? 'checked' : '' ?>>
                                                <span><?= e((string) $label) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (($approvalReasonOptions ?? []) !== []): ?>
                                        <span class="nrf-label nrf-label-gap">Motiv <small>(nimic bifat = toate)</small></span>
                                        <div class="nrf-chips">
                                            <?php foreach ($approvalReasonOptions as $value => $label): ?>
                                                <label class="nrf-chip">
                                                    <input type="checkbox" name="approval_reasons[]" value="<?= e((string) $value) ?>" <?= in_array((string) $value, $selectedApprovalReasons, true) ? 'checked' : '' ?>>
                                                    <span><?= e((string) $label) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <label class="nrf-label nrf-label-gap" for="nrfApprovalDays">Reamintește la</label>
                                    <div class="nrf-days-row nrf-days-row-sm">
                                        <input class="form-control" id="nrfApprovalDays" name="approval_repeat_days" type="number" min="1" max="30" step="1" value="<?= e((string) ($formData['approval_repeat_days'] ?? 1)) ?>">
                                        <span class="nrf-unit">zile</span>
                                    </div>
                                    <small class="nrf-help">Are efect doar cu „Repetă până la rezolvare”.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="nrf-section" aria-labelledby="nrfStep3">
                    <div class="nrf-section-head">
                        <span class="nrf-step" aria-hidden="true">3</span>
                        <div>
                            <h4 id="nrfStep3">Cine primește și cum?</h4>
                            <p>Selectează canalul de trimitere și destinatarii.</p>
                        </div>
                    </div>

                    <div class="nrf-delivery" style="--nrf-recipient-count: <?= max(1, count($visibleRecipientModes)) ?>">
                        <div class="nrf-delivery-col" role="radiogroup" aria-labelledby="nrfChannelLabel">
                            <span class="nrf-label" id="nrfChannelLabel">Canal</span>
                            <div class="nrf-channels">
                                <?php foreach ($channelLabels as $value => $label): ?>
                                    <label class="nrf-channel">
                                        <input type="radio" name="channel" value="<?= e((string) $value) ?>" data-nrf-channel <?= $currentChannel === (string) $value ? 'checked' : '' ?>>
                                        <span><i class="bi <?= e($channelIcon((string) $value)) ?>" aria-hidden="true"></i> <?= e((string) $label) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?= $fieldError('channel') ?>
                        </div>
                        <div class="nrf-delivery-col" role="radiogroup" aria-labelledby="nrfRecipientLabel">
                            <span class="nrf-label" id="nrfRecipientLabel">Destinatari</span>
                            <div class="nrf-recipients">
                                <?php foreach ($visibleRecipientModes as $value): ?>
                                    <?php $display = $recipientDisplay[$value] ?? ['label' => (string) $recipientLabels[$value], 'icon' => 'bi-person', 'help' => '']; ?>
                                    <label class="nrf-recipient">
                                        <input type="radio" name="recipient_mode" value="<?= e((string) $value) ?>" data-nrf-recipient <?= $currentRecipientMode === (string) $value ? 'checked' : '' ?>>
                                        <span class="nrf-recipient-card">
                                            <span class="nrf-radio-dot" aria-hidden="true"></span>
                                            <i class="bi <?= e($display['icon']) ?>" aria-hidden="true"></i>
                                            <strong><?= e($display['label']) ?></strong>
                                            <small><?= e($display['help']) ?></small>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?= $fieldError('recipient_mode') ?>
                        </div>
                    </div>

                    <div class="form-check form-switch nrf-switch nrf-switch-inline" data-nrf-driver-option hidden>
                        <input class="form-check-input" type="checkbox" role="switch" id="nrfNotifyDriver" name="notify_driver" value="1" <?= (int) ($formData['notify_driver'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="nrfNotifyDriver">
                            <strong>Trimite SMS și șoferului</strong>
                            <small>Șoferii alocați vehiculului (la documente de șofer, concedii, zile de naștere: șoferul respectiv), pe numărul din fișă. Se adaugă la destinatarii aleși.</small>
                        </label>
                    </div>

                    <div class="nrf-users" data-nrf-users hidden>
                        <div class="nrf-users-toolbar">
                            <div class="nrf-users-search">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input class="form-control form-control-sm" type="search" placeholder="Caută utilizator…" aria-label="Caută utilizator" data-nrf-user-search>
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
                                <label class="nrf-user" data-nrf-user data-name="<?= e((string) ($user['nume'] ?? '')) ?>" data-search="<?= e(mb_strtolower(($user['nume'] ?? '') . ' ' . $userEmail . ' ' . $userPhone, 'UTF-8')) ?>" data-has-email="<?= $userEmail !== '' ? '1' : '0' ?>" data-has-phone="<?= $userPhone !== '' ? '1' : '0' ?>">
                                    <input class="form-check-input" type="checkbox" name="recipient_user_ids[]" value="<?= e((string) $userId) ?>" <?= in_array($userId, $selectedRecipientIds, true) ? 'checked' : '' ?>>
                                    <span class="nrf-user-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($user['nume'] ?? '?'), 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                                    <span class="nrf-user-text">
                                        <strong><?= e((string) ($user['nume'] ?? '')) ?><?php if ((string) ($user['rol'] ?? '') === 'admin'): ?> <em>admin</em><?php endif; ?></strong>
                                        <small>
                                            <span class="<?= $userEmail === '' ? 'is-missing' : '' ?>" data-nrf-user-email><i class="bi bi-envelope" aria-hidden="true"></i> <?= e($userEmail !== '' ? $userEmail : 'fără email') ?></span>
                                            <span class="<?= $userPhone === '' ? 'is-missing' : '' ?>" data-nrf-user-phone><i class="bi bi-phone" aria-hidden="true"></i> <?= e($userPhone !== '' ? $userPhone : 'fără telefon') ?></span>
                                        </small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?= $fieldError('recipient_user_ids') ?>
                    </div>

                    <?php
                    $drivers = is_array($drivers ?? null) ? $drivers : [];
                    $selectedDriverIds = array_map('intval', (array) ($formData['recipient_driver_ids'] ?? []));
                    ?>
                    <div class="nrf-users nrf-drivers needs-phone" data-nrf-drivers hidden>
                        <div class="nrf-drivers-intro">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            <span data-nrf-driver-intro>Niciun șofer ales: SMS-ul merge la șoferii alocați vehiculului (sau șoferului vizat). Alege șoferi ca să primească ei mereu mesajul, indiferent de vehicul.</span>
                        </div>
                        <div class="nrf-users-toolbar">
                            <div class="nrf-users-search">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input class="form-control form-control-sm" type="search" placeholder="Caută șofer sau nr. auto…" aria-label="Caută șofer" data-nrf-driver-search>
                            </div>
                            <span class="nrf-users-count" data-nrf-driver-count></span>
                            <button type="button" class="btn btn-sm btn-link" data-nrf-drivers-all>Toți</button>
                            <button type="button" class="btn btn-sm btn-link" data-nrf-drivers-none>Niciunul</button>
                        </div>
                        <div class="nrf-users-list">
                            <?php foreach ($drivers as $driver): ?>
                                <?php
                                $driverId = (int) ($driver['id'] ?? 0);
                                $driverPhone = trim((string) ($driver['telefon'] ?? ''));
                                $driverPlate = trim((string) ($driver['nr_inmatriculare'] ?? ''));
                                ?>
                                <label class="nrf-user" data-nrf-driver data-name="<?= e((string) ($driver['nume'] ?? '')) ?>" data-search="<?= e(mb_strtolower(($driver['nume'] ?? '') . ' ' . $driverPlate . ' ' . $driverPhone, 'UTF-8')) ?>" data-has-phone="<?= $driverPhone !== '' ? '1' : '0' ?>">
                                    <input class="form-check-input" type="checkbox" name="recipient_driver_ids[]" value="<?= e((string) $driverId) ?>" <?= in_array($driverId, $selectedDriverIds, true) ? 'checked' : '' ?>>
                                    <span class="nrf-user-avatar is-driver" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($driver['nume'] ?? '?'), 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                                    <span class="nrf-user-text">
                                        <strong><?= e((string) ($driver['nume'] ?? '')) ?></strong>
                                        <small>
                                            <span class="<?= $driverPhone === '' ? 'is-missing' : '' ?>" data-nrf-user-phone><i class="bi bi-phone" aria-hidden="true"></i> <?= e($driverPhone !== '' ? $driverPhone : 'fără telefon') ?></span>
                                            <span><i class="bi bi-truck" aria-hidden="true"></i> <?= e($driverPlate !== '' ? $driverPlate : 'fără vehicul') ?></span>
                                        </small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                            <?php if ($drivers === []): ?>
                                <p class="nrf-empty nrf-drivers-empty">Nu există șoferi activi.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="nrf-section" aria-labelledby="nrfStep4">
                    <div class="nrf-section-head">
                        <span class="nrf-step" aria-hidden="true">4</span>
                        <div>
                            <h4 id="nrfStep4">Opțiuni</h4>
                            <p>Setări suplimentare pentru această regulă.</p>
                        </div>
                    </div>
                    <div class="nrf-options">
                        <div class="form-check form-switch nrf-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="nrfEnabled" name="enabled" value="1" data-nrf-option="Activă" <?= (int) ($formData['enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="nrfEnabled"><strong>Activă</strong><small>Worker-ul o verifică la fiecare rulare.</small></label>
                        </div>
                        <div class="form-check form-switch nrf-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="nrfRepeat" name="repeat_until_resolved" value="1" data-nrf-option="Repetă până la rezolvare" <?= (int) ($formData['repeat_until_resolved'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="nrfRepeat"><strong>Repetă până la rezolvare</strong><small>Trimite din nou cât timp situația persistă.</small></label>
                        </div>
                        <div class="form-check form-switch nrf-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="nrfDaily" name="daily_limit_enabled" value="1" data-nrf-option="Maxim o notificare pe zi" <?= (int) ($formData['daily_limit_enabled'] ?? 1) === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="nrfDaily"><strong>Maxim o notificare pe zi</strong><small>Pentru același document / vehicul și destinatar.</small></label>
                        </div>
                    </div>
                </section>
            </div>

            <footer class="nrf-footer">
                <div class="nrf-summary" data-nrf-summary>
                    <div class="nrf-summary-main">
                        <span class="nrf-summary-icon" aria-hidden="true"><i class="bi bi-card-checklist"></i></span>
                        <div class="nrf-summary-body">
                            <div class="nrf-summary-head">
                                <strong>Rezumat</strong>
                                <span class="nrf-pill" data-nrf-count-badge></span>
                                <button type="button" class="nrf-details-toggle" aria-expanded="false" data-nrf-details-toggle>Detalii <i class="bi bi-chevron-up" aria-hidden="true"></i></button>
                            </div>
                            <ul class="nrf-checklist" data-nrf-checklist aria-live="polite"></ul>
                        </div>
                    </div>
                    <div class="nrf-summary-side">
                        <div class="nrf-status" data-nrf-status aria-live="polite">
                            <i class="bi" data-nrf-status-icon aria-hidden="true"></i>
                            <div>
                                <strong data-nrf-status-title></strong>
                                <span data-nrf-count></span>
                                <ul class="nrf-status-warnings" data-nrf-warnings></ul>
                            </div>
                        </div>
                        <div class="nrf-name-preview">
                            <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                            <div>
                                <strong>Previzualizare nume</strong>
                                <span class="nrf-name-preview-value" data-nrf-name-preview></span>
                                <small>Poate fi modificat manual în câmpul de sus.</small>
                                <button type="button" class="nrf-link" aria-expanded="false" aria-controls="nrfPreviewList" data-nrf-preview-toggle hidden></button>
                            </div>
                        </div>
                        <div class="nrf-preview-pop" id="nrfPreviewList" data-nrf-preview-pop hidden>
                            <div class="nrf-preview-pop-head">Regulile care se vor salva</div>
                            <ul data-nrf-preview></ul>
                        </div>
                        <div class="nrf-actions">
                            <a class="btn nrf-btn-cancel" href="<?= e(build_query_url(['page' => 'notificari'])) ?>" data-nrf-cancel>Anulează</a>
                            <button type="submit" class="btn btn-primary nrf-btn-save" data-nrf-submit><i class="bi bi-check2" aria-hidden="true"></i> <span data-nrf-submit-label>Salvează</span></button>
                        </div>
                    </div>
                </div>
            </footer>
        </form>
    </div>
    <script src="<?= e(url('assets/js/notificari-regula.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/notificari-regula.js'))) ?>"></script>
<?php endif; ?>
