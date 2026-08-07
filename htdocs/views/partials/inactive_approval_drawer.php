<?php
$approvalSummary = is_array($approvalSummary ?? null) ? $approvalSummary : [];
$approvalCounts = is_array($approvalSummary['counts'] ?? null) ? $approvalSummary['counts'] : ['vehicle' => 0, 'driver' => 0];
$approvalVehicleRows = is_array($approvalSummary['vehicles'] ?? null) ? $approvalSummary['vehicles'] : [];
$approvalDriverRows = is_array($approvalSummary['drivers'] ?? null) ? $approvalSummary['drivers'] : [];
$approvalTotal = (int) ($approvalSummary['total'] ?? ((int) ($approvalCounts['vehicle'] ?? 0) + (int) ($approvalCounts['driver'] ?? 0)));
$drawerReturnUrl = (string) ($_SERVER['REQUEST_URI'] ?? build_query_url(['page' => 'dashboard']));

$formatDrawerDate = static fn(mixed $value): string => trim((string) $value) !== '' ? format_date_ro((string) $value) : '-';
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
$approvalTabs = [
    'vehicle' => [
        'label' => 'Vehicule',
        'count' => (int) ($approvalCounts['vehicle'] ?? 0),
        'rows' => $approvalVehicleRows,
    ],
    'driver' => [
        'label' => 'Soferi',
        'count' => (int) ($approvalCounts['driver'] ?? 0),
        'rows' => $approvalDriverRows,
    ],
];
$activeApprovalTab = (int) ($approvalCounts['vehicle'] ?? 0) > 0 ? 'vehicle' : 'driver';
?>

<div class="fleet-approval-drawer" data-global-approval-drawer>
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
            <span class="fleet-approval-drawer-count"><?= e((string) $approvalTotal) ?></span>
        <?php endif; ?>
    </button>

    <aside class="dashboard-approval-panel fleet-approval-drawer-panel" id="globalApprovalDrawerPanel" aria-labelledby="global-approval-title">
        <header class="dashboard-approval-header">
            <div>
                <h2 id="global-approval-title">Solicitari aprobare in asteptare</h2>
                <span><?= e((string) $approvalTotal) ?> total</span>
            </div>
            <button class="dashboard-approval-close" type="button" aria-label="Inchide panoul" data-global-approval-close>
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
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
                    <?= e((string) $tab['label']) ?> (<?= e((string) ((int) $tab['count'])) ?>)
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
                <?php if ($rowsForTab === []): ?>
                    <div class="dashboard-approval-empty">
                        Nu exista solicitari in asteptare.
                    </div>
                <?php else: ?>
                    <?php foreach ($rowsForTab as $approval): ?>
                        <?php
                        $approvalId = (int) ($approval['id'] ?? 0);
                        $reasonKey = (string) ($approval['inactive_reason'] ?? 'other');
                        $documents = is_array($approval['documents'] ?? null) ? $approval['documents'] : [];
                        $documentNames = is_array($approval['affected_document_names'] ?? null) ? $approval['affected_document_names'] : [];
                        $snapshot = json_decode((string) ($approval['snapshot_json'] ?? ''), true);
                        $snapshot = is_array($snapshot) ? $snapshot : [];
                        $detail = trim((string) ($snapshot['detail'] ?? ''));
                        $affectedDocuments = $documentNames !== []
                            ? implode(', ', array_map('strval', $documentNames))
                            : implode(', ', array_map(static fn($doc): string => is_array($doc) ? (string) ($doc['document_name'] ?? '') : '', $documents));
                        $affectedDocuments = trim($affectedDocuments, " \t\n\r\0\x0B,");
                        ?>
                        <article class="dashboard-approval-card">
                            <div class="dashboard-approval-card-meta">
                                <span class="dashboard-approval-reason tone-<?= e($drawerTone($reasonKey)) ?>">
                                    <i class="bi <?= e($drawerIcon($reasonKey)) ?>" aria-hidden="true"></i>
                                    <?= e((string) ($approval['inactive_reason_label'] ?? 'Alt motiv')) ?>
                                </span>
                                <span><?= e($formatDrawerTime($approval['requested_at'] ?? '')) ?></span>
                            </div>

                            <h3><?= e((string) ($approval['resource_label'] ?? '-')) ?></h3>

                            <dl class="dashboard-approval-card-list">
                                <dt>Motiv:</dt>
                                <dd><?= e((string) ($approval['inactive_reason_label'] ?? 'Alt motiv')) ?></dd>
                                <?php if ($affectedDocuments !== ''): ?>
                                    <dt>Documente afectate:</dt>
                                    <dd><?= e($affectedDocuments) ?></dd>
                                <?php endif; ?>
                                <?php if ($detail !== ''): ?>
                                    <dt>Detaliu:</dt>
                                    <dd><?= e($detail) ?></dd>
                                <?php endif; ?>
                                <dt>Inactiv din:</dt>
                                <dd><?= e($formatDrawerDate($approval['inactive_since'] ?? '')) ?></dd>
                                <dt>Utilizat in:</dt>
                                <dd><?= e($drawerContext($approval['usage_context'] ?? '')) ?></dd>
                            </dl>

                            <div class="dashboard-approval-actions">
                                <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'reject'])) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e((string) $approvalId) ?>">
                                    <input type="hidden" name="return_url" value="<?= e($drawerReturnUrl) ?>">
                                    <button class="dashboard-approval-action is-reject" type="submit">Respinge</button>
                                </form>
                                <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'approve'])) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e((string) $approvalId) ?>">
                                    <input type="hidden" name="return_url" value="<?= e($drawerReturnUrl) ?>">
                                    <button class="dashboard-approval-action is-approve" type="submit">Aproba</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <a class="dashboard-approval-all-link" href="<?= e(build_query_url(['page' => 'inactive_approvals'])) ?>">
            <span>Vezi toate solicitarile</span>
            <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </a>
    </aside>
</div>
