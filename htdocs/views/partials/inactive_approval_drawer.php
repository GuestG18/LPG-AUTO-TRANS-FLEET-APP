<?php
$approvalSummary = is_array($approvalSummary ?? null) ? $approvalSummary : [];
$drawerMode = (string) ($approvalDrawerMode ?? 'admin');
$drawerIsAdmin = $drawerMode === 'admin' || !empty($canReviewInactiveApprovals);
$drawerReturnUrl = (string) ($_SERVER['REQUEST_URI'] ?? build_query_url(['page' => 'dashboard']));

$formatDrawerDate = static fn(mixed $value): string => trim((string) $value) !== '' ? format_date_ro((string) $value) : '-';
$formatDrawerDateTime = static fn(mixed $value): string => trim((string) $value) !== '' ? format_datetime_ro((string) $value) : '-';
$formatDrawerTime = static function (mixed $value): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '-';
    }

    try {
        $date = new DateTimeImmutable($raw);
        $now = new DateTimeImmutable('now');
        $seconds = max(0, $now->getTimestamp() - $date->getTimestamp());
        if ($seconds < 60) {
            return 'Acum cateva secunde';
        }

        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return 'Acum ' . $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24 && $date->format('Y-m-d') === $now->format('Y-m-d')) {
            return 'Acum ' . $hours . ' ore';
        }

        if ($date->format('Y-m-d') === $now->modify('-1 day')->format('Y-m-d')) {
            return 'Ieri, ' . $date->format('H:i');
        }

        return format_datetime_ro($raw);
    } catch (Throwable) {
        return format_datetime_ro($raw);
    }
};
$drawerTone = static function (string $reasonKey): string {
    if (in_array($reasonKey, ['expired_documents', 'missing_documents', 'documents_mixed'], true)) {
        return 'danger';
    }
    if (in_array($reasonKey, ['repair', 'leave', 'medical_leave'], true)) {
        return 'warning';
    }

    return 'muted';
};
$drawerIcon = static function (string $reasonKey): string {
    return match ($reasonKey) {
        'repair' => 'bi-tools',
        'leave', 'medical_leave' => 'bi-calendar2-check',
        'manual_inactive' => 'bi-slash-circle',
        'missing_documents' => 'bi-file-earmark-excel',
        default => 'bi-file-earmark-x',
    };
};
$drawerContext = static function (mixed $value): string {
    $context = trim((string) $value);
    if ($context === '' || $context === 'dispecer_curse') {
        return 'Dispecer curse';
    }

    return ucfirst(str_replace('_', ' ', $context));
};
$drawerDocumentsLabel = static function (array $approval): string {
    $documentNames = is_array($approval['affected_document_names'] ?? null) ? $approval['affected_document_names'] : [];
    $documentNames = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $documentNames)));
    if ($documentNames !== []) {
        return implode(', ', $documentNames);
    }

    $documents = is_array($approval['documents'] ?? null) ? $approval['documents'] : [];
    $names = [];
    foreach ($documents as $document) {
        if (!is_array($document)) {
            continue;
        }
        $name = trim((string) ($document['document_name'] ?? ''));
        if ($name !== '') {
            $names[$name] = $name;
        }
    }

    return implode(', ', array_values($names));
};
$drawerSnapshot = static function (array $approval): array {
    $snapshot = json_decode((string) ($approval['snapshot_json'] ?? ''), true);

    return is_array($snapshot) ? $snapshot : [];
};

if ($drawerIsAdmin) {
    $approvalCounts = is_array($approvalSummary['counts'] ?? null) ? $approvalSummary['counts'] : ['vehicle' => 0, 'driver' => 0, 'repair' => 0];
    $approvalTabs = [
        'vehicle' => [
            'label' => 'Vehicule',
            'count' => (int) ($approvalCounts['vehicle'] ?? 0),
            'rows' => is_array($approvalSummary['vehicles'] ?? null) ? $approvalSummary['vehicles'] : [],
        ],
        'driver' => [
            'label' => 'Soferi',
            'count' => (int) ($approvalCounts['driver'] ?? 0),
            'rows' => is_array($approvalSummary['drivers'] ?? null) ? $approvalSummary['drivers'] : [],
        ],
        'repair' => [
            'label' => 'Reparatii',
            'count' => (int) ($approvalCounts['repair'] ?? 0),
            'rows' => is_array($approvalSummary['repairs'] ?? null) ? $approvalSummary['repairs'] : [],
        ],
    ];
    $approvalTotal = (int) ($approvalSummary['total'] ?? ((int) ($approvalCounts['vehicle'] ?? 0) + (int) ($approvalCounts['driver'] ?? 0) + (int) ($approvalCounts['repair'] ?? 0)));
    $drawerTitle = 'Solicitari aprobare in asteptare';
    $drawerAllLabel = 'Vezi toate solicitarile';
} else {
    $approvalCounts = is_array($approvalSummary['counts'] ?? null) ? $approvalSummary['counts'] : ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    $approvalTabs = [
        'pending' => [
            'label' => 'In asteptare',
            'count' => (int) ($approvalCounts['pending'] ?? 0),
            'rows' => is_array($approvalSummary['pending'] ?? null) ? $approvalSummary['pending'] : [],
        ],
        'approved' => [
            'label' => 'Aprobate',
            'count' => (int) ($approvalCounts['approved'] ?? 0),
            'rows' => is_array($approvalSummary['approved'] ?? null) ? $approvalSummary['approved'] : [],
        ],
        'rejected' => [
            'label' => 'Respinse',
            'count' => (int) ($approvalCounts['rejected'] ?? 0),
            'rows' => is_array($approvalSummary['rejected'] ?? null) ? $approvalSummary['rejected'] : [],
        ],
    ];
    $approvalTotal = (int) ($approvalSummary['total'] ?? ((int) ($approvalCounts['pending'] ?? 0) + (int) ($approvalCounts['approved'] ?? 0) + (int) ($approvalCounts['rejected'] ?? 0)));
    $drawerTitle = 'Solicitarile mele de aprobare';
    $drawerAllLabel = 'Vezi toate solicitarile mele';
}

$activeApprovalTab = array_key_first($approvalTabs) ?: '';
foreach ($approvalTabs as $tabKey => $tab) {
    if ((int) ($tab['count'] ?? 0) > 0) {
        $activeApprovalTab = $tabKey;
        break;
    }
}

$resourceTypeLabels = [
    'vehicle' => 'Vehicul',
    'driver' => 'Sofer',
    'repair' => 'Reparatie',
];
$statusLabels = [
    'pending' => 'In asteptare',
    'approved' => 'Aprobata',
    'rejected' => 'Respinsa',
];
$statusIcons = [
    'pending' => 'bi-hourglass-split',
    'approved' => 'bi-check-circle',
    'rejected' => 'bi-x-circle',
];
?>

<div class="fleet-approval-drawer <?= $drawerIsAdmin ? 'is-admin-mode' : 'is-user-mode' ?>" data-global-approval-drawer>
    <button
        class="fleet-approval-drawer-toggle"
        type="button"
        aria-label="Deschide solicitarile de aprobare"
        aria-expanded="false"
        aria-controls="globalApprovalDrawerPanel"
        data-global-approval-toggle
    >
        <i class="bi bi-chevron-left" aria-hidden="true"></i>
        <?php if ($approvalTotal > 0): ?>
            <span class="fleet-approval-drawer-count" data-approval-total-badge><?= e((string) $approvalTotal) ?></span>
        <?php endif; ?>
    </button>

    <aside class="dashboard-approval-panel fleet-approval-drawer-panel" id="globalApprovalDrawerPanel" aria-labelledby="global-approval-title">
        <?php if (!$drawerIsAdmin): ?>
            <button class="dashboard-approval-close fleet-approval-user-panel-close" type="button" aria-label="Inchide panoul" data-global-approval-close>
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        <?php endif; ?>
        <header class="dashboard-approval-header">
            <div>
                <h2 id="global-approval-title"><?= e($drawerTitle) ?></h2>
                <span><span data-approval-total-count><?= e((string) $approvalTotal) ?></span> total</span>
            </div>
            <?php if ($drawerIsAdmin): ?>
                <button class="dashboard-approval-close" type="button" aria-label="Inchide panoul" data-global-approval-close>
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            <?php endif; ?>
        </header>

        <div class="dashboard-approval-tabs" role="tablist" aria-label="Solicitari aprobare inactive">
            <?php foreach ($approvalTabs as $tabKey => $tab): ?>
                <?php $isActiveTab = $tabKey === $activeApprovalTab; ?>
                <button
                    class="dashboard-approval-tab<?= $isActiveTab ? ' is-active' : '' ?>"
                    type="button"
                    role="tab"
                    id="global-approval-tab-<?= e($tabKey) ?>"
                    aria-controls="global-approval-panel-<?= e($tabKey) ?>"
                    aria-selected="<?= $isActiveTab ? 'true' : 'false' ?>"
                    data-dashboard-approval-tab="<?= e($tabKey) ?>"
                >
                    <span data-approval-tab-label><?= e((string) $tab['label']) ?></span> (<span data-approval-tab-count="<?= e($tabKey) ?>"><?= e((string) ((int) $tab['count'])) ?></span>)
                </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($approvalTabs as $tabKey => $tab): ?>
            <?php
            $isActiveTab = $tabKey === $activeApprovalTab;
            $rowsForTab = is_array($tab['rows'] ?? null) ? $tab['rows'] : [];
            ?>
            <div
                class="dashboard-approval-tab-panel<?= $isActiveTab ? ' is-active' : '' ?>"
                role="tabpanel"
                id="global-approval-panel-<?= e($tabKey) ?>"
                aria-labelledby="global-approval-tab-<?= e($tabKey) ?>"
                data-dashboard-approval-panel="<?= e($tabKey) ?>"
                <?= $isActiveTab ? '' : 'hidden' ?>
            >
                <div class="dashboard-approval-empty" data-approval-empty="<?= e($tabKey) ?>" <?= $rowsForTab === [] ? '' : 'hidden' ?>>
                        <?= $drawerIsAdmin ? 'Nu exista solicitari in asteptare.' : 'Nu exista solicitari in acest status.' ?>
                </div>
                <?php if ($rowsForTab !== []): ?>
                    <?php foreach ($rowsForTab as $approval): ?>
                        <?php
                        $approvalId = (int) ($approval['id'] ?? 0);
                        $reasonKey = (string) ($approval['inactive_reason'] ?? 'other');
                        $snapshot = $drawerSnapshot($approval);
                        $detail = trim((string) ($snapshot['detail'] ?? ''));
                        $affectedDocuments = $drawerDocumentsLabel($approval);
                        $resourceType = (string) ($approval['resource_type'] ?? '');
                        $resourceLabel = trim((string) ($approval['resource_label'] ?? ''));
                        if ($resourceLabel === '') {
                            $resourceLabel = $resourceTypeLabels[$resourceType] ?? '-';
                        }
                        $status = (string) ($approval['status'] ?? $tabKey);
                        if (!isset($statusLabels[$status])) {
                            $status = $tabKey;
                        }
                        $context = is_array($approval['approval_context'] ?? null) ? $approval['approval_context'] : [];
                        $summaryRows = is_array($context['summary_rows'] ?? null) ? $context['summary_rows'] : [];
                        $detailRows = is_array($context['detail_rows'] ?? null) ? $context['detail_rows'] : [];
                        $requestTypeLabel = trim((string) ($context['request_type_label'] ?? ''));
                        if ($requestTypeLabel === '') {
                            $requestTypeLabel = (string) ($approval['inactive_reason_label'] ?? 'Alt motiv');
                        }
                        $primaryLabel = trim((string) ($context['primary_label'] ?? ''));
                        if ($primaryLabel === '') {
                            $primaryLabel = $resourceLabel;
                        }
                        $problemTitle = trim((string) ($context['problem_title'] ?? ''));
                        if ($problemTitle === '') {
                            $problemTitle = (string) ($approval['inactive_reason_label'] ?? 'Alt motiv');
                        }
                        $operationTitle = trim((string) ($context['operation_title'] ?? ''));
                        $operationUrl = trim((string) ($context['operation_url'] ?? ''));
                        $operationLinkLabel = trim((string) ($context['operation_link_label'] ?? ''));
                        $scopeMessage = trim((string) ($context['scope_message'] ?? ''));
                        $detailsId = 'global-approval-details-' . $tabKey . '-' . $approvalId;
                        ?>
                        <article class="dashboard-approval-card" data-approval-card data-approval-id="<?= e((string) $approvalId) ?>" data-approval-status="<?= e($status) ?>" data-approval-tab-key="<?= e($tabKey) ?>">
                            <div class="dashboard-approval-card-meta">
                                <span class="dashboard-approval-reason tone-<?= e($drawerTone($reasonKey)) ?>">
                                    <i class="bi <?= e($drawerIcon($reasonKey)) ?>" aria-hidden="true"></i>
                                    <?= e($requestTypeLabel) ?>
                                </span>
                                <span><?= e($formatDrawerTime($approval['requested_at'] ?? '')) ?></span>
                            </div>

                            <h3><?= e($primaryLabel) ?></h3>

                            <p class="dashboard-approval-problem"><?= e($problemTitle) ?></p>

                            <?php if ($operationTitle !== ''): ?>
                                <div class="dashboard-approval-operation">
                                    <span><?= e($operationTitle) ?></span>
                                    <?php if ($operationUrl !== '' && $operationLinkLabel !== ''): ?>
                                        <a href="<?= e($operationUrl) ?>"><?= e($operationLinkLabel) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($summaryRows !== []): ?>
                                <dl class="dashboard-approval-card-list dashboard-approval-summary-list">
                                    <?php foreach ($summaryRows as $row): ?>
                                        <?php
                                        $rowLabel = trim((string) ($row['label'] ?? ''));
                                        $rowValue = trim((string) ($row['value'] ?? ''));
                                        if ($rowLabel === '' || $rowValue === '') {
                                            continue;
                                        }
                                        ?>
                                        <dt><?= e($rowLabel) ?>:</dt>
                                        <dd><?= e($rowValue) ?></dd>
                                    <?php endforeach; ?>
                                </dl>
                            <?php else: ?>
                                <dl class="dashboard-approval-card-list dashboard-approval-summary-list">
                                    <?php if ($affectedDocuments !== ''): ?>
                                        <dt>Documente:</dt>
                                        <dd><?= e($affectedDocuments) ?></dd>
                                    <?php endif; ?>
                                    <?php if ($detail !== ''): ?>
                                        <dt>Detaliu:</dt>
                                        <dd><?= e($detail) ?></dd>
                                    <?php endif; ?>
                                    <dt>Inactiv din:</dt>
                                    <dd><?= e($formatDrawerDate($approval['inactive_since'] ?? '')) ?></dd>
                                </dl>
                            <?php endif; ?>

                            <?php if ($detailRows !== []): ?>
                                <details class="dashboard-approval-details" id="<?= e($detailsId) ?>">
                                    <summary>Vezi detalii</summary>
                                    <dl class="dashboard-approval-card-list">
                                        <?php foreach ($detailRows as $row): ?>
                                            <?php
                                            $rowLabel = trim((string) ($row['label'] ?? ''));
                                            $rowValue = trim((string) ($row['value'] ?? ''));
                                            if ($rowLabel === '' || $rowValue === '') {
                                                continue;
                                            }
                                            ?>
                                            <dt><?= e($rowLabel) ?>:</dt>
                                            <dd><?= e($rowValue) ?></dd>
                                        <?php endforeach; ?>
                                        <?php if (!$drawerIsAdmin): ?>
                                            <dt>Status:</dt>
                                            <dd><?= e($statusLabels[$status] ?? $status) ?></dd>
                                        <?php endif; ?>
                                    </dl>
                                </details>
                            <?php endif; ?>

                            <?php if ($scopeMessage !== ''): ?>
                                <p class="dashboard-approval-scope"><?= e($scopeMessage) ?></p>
                            <?php endif; ?>

                            <?php if ($drawerIsAdmin): ?>
                                <div class="dashboard-approval-actions">
                                    <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'reject'])) ?>" data-approval-review-form data-approval-decision="rejected">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $approvalId) ?>">
                                        <input type="hidden" name="return_url" value="<?= e($drawerReturnUrl) ?>">
                                        <input type="hidden" name="decision_source" value="popup">
                                        <button class="dashboard-approval-action is-reject" type="submit">Respinge</button>
                                    </form>
                                    <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'approve'])) ?>" data-approval-review-form data-approval-decision="approved">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $approvalId) ?>">
                                        <input type="hidden" name="return_url" value="<?= e($drawerReturnUrl) ?>">
                                        <input type="hidden" name="decision_source" value="popup">
                                        <button class="dashboard-approval-action is-approve" type="submit">Aproba</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="fleet-approval-user-actions">
                                    <span class="user-approval-status is-<?= e($status) ?>">
                                        <i class="bi <?= e($statusIcons[$status] ?? 'bi-hourglass-split') ?>" aria-hidden="true"></i>
                                        <?= e($statusLabels[$status] ?? $status) ?>
                                    </span>
                                    <a class="user-approval-detail-link" href="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'show', 'id' => $approvalId])) ?>">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                        <span>Vezi detalii</span>
                                    </a>
                                    <?php if ($status === 'pending'): ?>
                                        <button
                                            class="user-approval-cancel-link"
                                            type="button"
                                            data-user-approval-cancel
                                            data-approval-id="<?= e((string) $approvalId) ?>"
                                            data-cancel-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'cancel_inactive_vehicle_approval'])) ?>"
                                            data-csrf-token="<?= e(csrf_token()) ?>"
                                        >
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                            <span>Anuleaza solicitarea</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <a class="dashboard-approval-all-link" href="<?= e(build_query_url(['page' => 'inactive_approvals'])) ?>">
            <span><?= e($drawerAllLabel) ?></span>
            <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>
    </aside>
</div>
