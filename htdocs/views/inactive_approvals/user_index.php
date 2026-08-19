<?php
$summary = is_array($summary ?? null) ? $summary : [];
$counts = is_array($summary['counts'] ?? null) ? $summary['counts'] : ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$selectedStatus = (string) ($selectedStatus ?? 'pending');
if (!in_array($selectedStatus, ['pending', 'approved', 'rejected'], true)) {
    $selectedStatus = 'pending';
}

$statusLabels = [
    'pending' => 'In asteptare',
    'approved' => 'Aprobate',
    'rejected' => 'Respinse',
];
$statusCardLabels = [
    'pending' => 'In asteptare',
    'approved' => 'Aprobat',
    'rejected' => 'Respins',
];
$statusIcons = [
    'pending' => 'bi-hourglass-split',
    'approved' => 'bi-check-circle',
    'rejected' => 'bi-x-circle',
];
$resourceTypeLabels = [
    'vehicle' => 'Vehicul',
    'driver' => 'Sofer',
    'repair' => 'Reparatie',
];
$formatDate = static fn(mixed $value): string => trim((string) $value) !== '' ? format_date_ro((string) $value) : '-';
$formatDateTime = static fn(mixed $value): string => trim((string) $value) !== '' ? format_datetime_ro((string) $value) : '-';
$contextLabel = static function (mixed $value): string {
    $context = trim((string) $value);
    if ($context === '' || $context === 'dispecer_curse') {
        return 'Dispecer curse';
    }

    return ucfirst(str_replace('_', ' ', $context));
};
$reasonTone = static function (string $reasonKey): string {
    if (in_array($reasonKey, ['expired_documents', 'missing_documents', 'documents_mixed'], true)) {
        return 'danger';
    }
    if (in_array($reasonKey, ['repair', 'leave', 'medical_leave'], true)) {
        return 'warning';
    }

    return 'muted';
};
$reasonIcon = static function (string $reasonKey): string {
    return match ($reasonKey) {
        'repair' => 'bi-tools',
        'leave', 'medical_leave' => 'bi-calendar2-check',
        'manual_inactive' => 'bi-slash-circle',
        'missing_documents' => 'bi-file-earmark-excel',
        default => 'bi-file-earmark-x',
    };
};
$documentsLabel = static function (array $row): string {
    $names = is_array($row['affected_document_names'] ?? null) ? $row['affected_document_names'] : [];
    $names = array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $names)));

    return $names !== [] ? implode(', ', $names) : '';
};
$snapshotFor = static function (array $row): array {
    $snapshot = json_decode((string) ($row['snapshot_json'] ?? ''), true);

    return is_array($snapshot) ? $snapshot : [];
};
$approvalRows = [
    'pending' => is_array($summary['pending'] ?? null) ? $summary['pending'] : [],
    'approved' => is_array($summary['approved'] ?? null) ? $summary['approved'] : [],
    'rejected' => is_array($summary['rejected'] ?? null) ? $summary['rejected'] : [],
];
?>

<div class="user-approval-page">
    <header class="user-approval-page-header">
        <div>
            <h1>Solicitarile mele de aprobare</h1>
            <p>Urmareste statusul solicitarilor tale trimise pentru aprobare.</p>
        </div>
        <a class="user-approval-refresh" href="<?= e(build_query_url(['page' => 'inactive_approvals', 'status' => $selectedStatus])) ?>">
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            <span>Actualizeaza</span>
        </a>
    </header>

    <nav class="user-approval-status-tabs" aria-label="Status solicitari aprobare" id="user-approval-tabs">
        <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
            <?php $isActive = $statusKey === $selectedStatus; ?>
            <a
                class="user-approval-status-tab is-<?= e($statusKey) ?><?= $isActive ? ' is-active' : '' ?>"
                href="<?= e(build_query_url(['page' => 'inactive_approvals', 'status' => $statusKey])) ?>"
                aria-current="<?= $isActive ? 'page' : 'false' ?>"
            >
                <i class="bi <?= e($statusIcons[$statusKey]) ?>" aria-hidden="true"></i>
                <span><?= e($statusLabel) ?> (<span data-user-approval-tab-count="<?= e($statusKey) ?>"><?= e((string) ((int) ($counts[$statusKey] ?? 0))) ?></span>)</span>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="user-approval-card-stack" aria-label="<?= e($statusLabels[$selectedStatus] ?? 'Solicitari') ?>">
        <?php $rowsForStatus = $approvalRows[$selectedStatus] ?? []; ?>
        <div class="user-approval-empty" data-approval-empty="<?= e($selectedStatus) ?>" <?= $rowsForStatus === [] ? '' : 'hidden' ?>>
                Nu exista solicitari in acest status.
        </div>
        <?php if ($rowsForStatus !== []): ?>
            <?php foreach ($rowsForStatus as $row): ?>
                <?php
                $approvalId = (int) ($row['id'] ?? 0);
                $resourceType = (string) ($row['resource_type'] ?? '');
                $reasonKey = (string) ($row['inactive_reason'] ?? 'other');
                $snapshot = $snapshotFor($row);
                $detail = trim((string) ($snapshot['detail'] ?? ''));
                $observations = trim((string) ($snapshot['observatii'] ?? $snapshot['observations'] ?? ''));
                if ($observations === '' && $detail !== '') {
                    $observations = $detail;
                }
                $affectedDocuments = $documentsLabel($row);
                $resourceLabel = trim((string) ($row['resource_label'] ?? ''));
                if ($resourceLabel === '') {
                    $resourceLabel = $resourceTypeLabels[$resourceType] ?? 'Solicitare';
                }
                $context = is_array($row['approval_context'] ?? null) ? $row['approval_context'] : [];
                $summaryRows = is_array($context['summary_rows'] ?? null) ? $context['summary_rows'] : [];
                $primaryLabel = trim((string) ($context['primary_label'] ?? ''));
                if ($primaryLabel === '') {
                    $primaryLabel = $resourceLabel;
                }
                $problemTitle = trim((string) ($context['problem_title'] ?? ''));
                if ($problemTitle === '') {
                    $problemTitle = (string) ($row['inactive_reason_label'] ?? 'Alt motiv');
                }
                $operationTitle = trim((string) ($context['operation_title'] ?? ''));
                ?>
                <article class="user-approval-card" data-approval-card data-approval-id="<?= e((string) $approvalId) ?>" data-approval-status="<?= e($selectedStatus) ?>">
                    <div class="user-approval-card-main">
                        <span class="user-approval-card-icon tone-<?= e($reasonTone($reasonKey)) ?>" aria-hidden="true">
                            <i class="bi <?= e($reasonIcon($reasonKey)) ?>"></i>
                        </span>
                        <div class="user-approval-card-body">
                            <span class="user-approval-reason tone-<?= e($reasonTone($reasonKey)) ?>">
                                <?= e((string) ($context['request_type_label'] ?? $row['inactive_reason_label'] ?? 'Alt motiv')) ?>
                            </span>
                            <h2><?= e($primaryLabel) ?></h2>
                            <dl class="user-approval-card-list">
                                <dt>Motiv:</dt>
                                <dd><?= e($problemTitle) ?></dd>
                                <?php if ($operationTitle !== ''): ?>
                                    <dt>Solicitare pentru:</dt>
                                    <dd><?= e($operationTitle) ?></dd>
                                <?php endif; ?>
                                <?php foreach ($summaryRows as $contextRow): ?>
                                    <?php
                                    $contextLabelText = trim((string) ($contextRow['label'] ?? ''));
                                    $contextValueText = trim((string) ($contextRow['value'] ?? ''));
                                    if ($contextLabelText === '' || $contextValueText === '') {
                                        continue;
                                    }
                                    ?>
                                    <dt><?= e($contextLabelText) ?>:</dt>
                                    <dd><?= e($contextValueText) ?></dd>
                                <?php endforeach; ?>
                                <?php if ($affectedDocuments !== '' && $summaryRows === []): ?>
                                    <dt>Documente afectate:</dt>
                                    <dd><?= e($affectedDocuments) ?></dd>
                                <?php endif; ?>
                                <?php if ($resourceType === 'repair' && $detail !== ''): ?>
                                    <dt>Detalii reparatie:</dt>
                                    <dd><?= e($detail) ?></dd>
                                <?php endif; ?>
                                <?php if ($observations !== ''): ?>
                                    <dt>Observatii:</dt>
                                    <dd><?= e($observations) ?></dd>
                                <?php endif; ?>
                            </dl>
                        </div>
                    </div>

                    <aside class="user-approval-card-side">
                        <span class="user-approval-status is-<?= e($selectedStatus) ?>">
                            <i class="bi <?= e($statusIcons[$selectedStatus]) ?>" aria-hidden="true"></i>
                            <?= e($statusCardLabels[$selectedStatus] ?? $selectedStatus) ?>
                        </span>
                        <dl class="user-approval-meta-list">
                            <dt>Solicitat la:</dt>
                            <dd><?= e($formatDateTime($row['requested_at'] ?? '')) ?></dd>
                            <dt>Solicitat de:</dt>
                            <dd><i class="bi bi-person" aria-hidden="true"></i> Tu</dd>
                            <dt>Tip:</dt>
                            <dd>
                                <span class="user-approval-type is-<?= e($resourceType) ?>">
                                    <?= e($resourceTypeLabels[$resourceType] ?? '-') ?>
                                </span>
                            </dd>
                        </dl>
                        <a class="user-approval-detail-link" href="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'show', 'id' => $approvalId])) ?>">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                            <span>Vezi detalii</span>
                        </a>
                    </aside>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="user-approval-info-panel" aria-label="Cum functioneaza">
        <span class="user-approval-info-icon" aria-hidden="true">i</span>
        <div>
            <h2>Cum functioneaza?</h2>
            <p>Solicitarile tale sunt in curs de aprobare de catre un administrator.</p>
            <p>Vei fi notificat imediat ce cererea ta este aprobata sau respinsa.</p>
        </div>
        <button class="user-approval-info-close" type="button" aria-label="Inchide">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </section>

    <a class="user-approval-all-link" href="<?= e(build_query_url(['page' => 'inactive_approvals'])) ?>">
        <span><i class="bi bi-list-ul" aria-hidden="true"></i> Vezi toate solicitarile</span>
        <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </a>
</div>
