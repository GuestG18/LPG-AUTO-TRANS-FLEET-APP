<?php
$approval = is_array($approval ?? null) ? $approval : [];
$canReviewApprovals = !empty($canReviewApprovals);
$documents = is_array($approval['documents'] ?? null) ? $approval['documents'] : [];
$status = (string) ($approval['status'] ?? 'pending');
$statusLabels = [
    'pending' => 'In asteptare',
    'approved' => 'Aprobata',
    'rejected' => 'Respinsa',
];
$resourceTypeLabels = [
    'vehicle' => 'Vehicul',
    'driver' => 'Sofer',
];
$formatDate = static fn(mixed $value): string => trim((string) $value) !== '' ? format_date_ro((string) $value) : '-';
$formatDateTime = static fn(mixed $value): string => trim((string) $value) !== '' ? format_datetime_ro((string) $value) : '-';
$snapshot = json_decode((string) ($approval['snapshot_json'] ?? ''), true);
$snapshot = is_array($snapshot) ? $snapshot : [];
$detail = trim((string) ($snapshot['detail'] ?? ''));
$returnUrl = build_query_url(['page' => 'inactive_approvals']);
?>

<div class="inactive-approval-detail-page">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h2 class="h4 mb-1">Detalii solicitare aprobare</h2>
            <p class="text-muted mb-0">Contextul complet pastrat pentru audit.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?= e($returnUrl) ?>">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Inapoi
        </a>
    </div>

    <div class="inactive-approval-detail-grid">
        <section class="card border-0 shadow-sm">
            <div class="card-body">
                <h3 class="h6 mb-3">Resursa</h3>
                <dl class="inactive-approval-detail-list">
                    <dt>Tip</dt>
                    <dd><?= e($resourceTypeLabels[(string) ($approval['resource_type'] ?? '')] ?? '-') ?></dd>
                    <dt><?= (string) ($approval['resource_type'] ?? '') === 'driver' ? 'Sofer' : 'Vehicul' ?></dt>
                    <dd class="fw-semibold"><?= e((string) ($approval['resource_label'] ?? '-')) ?></dd>
                    <dt>Status</dt>
                    <dd><span class="inactive-approval-status is-<?= e($status) ?>"><?= e($statusLabels[$status] ?? $status) ?></span></dd>
                    <dt>Motiv</dt>
                    <dd><?= e((string) ($approval['inactive_reason_label'] ?? 'Alt motiv')) ?></dd>
                    <?php if ($documents !== []): ?>
                        <dt>Documente afectate</dt>
                        <dd>
                            <ul class="inactive-approval-document-list">
                                <?php foreach ($documents as $document): ?>
                                    <?php
                                    $docStatus = (string) ($document['document_status'] ?? '');
                                    $docStatusLabel = $docStatus === 'missing' ? 'lipsa' : 'expirat';
                                    $expiry = trim((string) ($document['expiry_date'] ?? ''));
                                    ?>
                                    <li>
                                        <strong><?= e((string) ($document['document_name'] ?? '-')) ?></strong>
                                        <span><?= e($docStatusLabel) ?><?= $expiry !== '' ? ' la ' . e($formatDate($expiry)) : '' ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </dd>
                    <?php endif; ?>
                    <?php if ($detail !== ''): ?>
                        <dt>Detaliu</dt>
                        <dd><?= e($detail) ?></dd>
                    <?php endif; ?>
                    <dt>Inactiv din</dt>
                    <dd><?= e($formatDate($approval['inactive_since'] ?? '')) ?></dd>
                    <dt>Utilizat in</dt>
                    <dd><?= e((string) ($approval['usage_context'] ?? 'Dispecer curse')) ?></dd>
                    <dt>Cursa</dt>
                    <dd><?= !empty($approval['trip_id']) ? ('#' . e((string) $approval['trip_id'])) : '-' ?></dd>
                </dl>
            </div>
        </section>

        <section class="card border-0 shadow-sm">
            <div class="card-body">
                <h3 class="h6 mb-3">Audit</h3>
                <dl class="inactive-approval-detail-list">
                    <dt>Solicitat de</dt>
                    <dd><?= e((string) ($approval['requested_by_name'] ?? '-')) ?></dd>
                    <dt>Solicitat la</dt>
                    <dd><?= e($formatDateTime($approval['requested_at'] ?? '')) ?></dd>
                    <dt>Status aprobare</dt>
                    <dd><span class="inactive-approval-status is-<?= e($status) ?>"><?= e($statusLabels[$status] ?? $status) ?></span></dd>
                    <?php if ($status !== 'pending'): ?>
                        <dt><?= $status === 'approved' ? 'Aprobat de' : 'Respins de' ?></dt>
                        <dd><?= e((string) ($approval['reviewed_by_name'] ?? '-')) ?></dd>
                        <dt><?= $status === 'approved' ? 'Aprobat la' : 'Respins la' ?></dt>
                        <dd><?= e($formatDateTime($approval['reviewed_at'] ?? '')) ?></dd>
                        <?php if (trim((string) ($approval['review_note'] ?? '')) !== ''): ?>
                            <dt>Nota</dt>
                            <dd><?= e((string) ($approval['review_note'] ?? '')) ?></dd>
                        <?php endif; ?>
                    <?php endif; ?>
                </dl>

                <?php if ($status === 'pending' && $canReviewApprovals): ?>
                    <div class="inactive-approval-detail-actions">
                        <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'reject'])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= e((string) ((int) ($approval['id'] ?? 0))) ?>">
                            <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                            <button class="btn btn-outline-danger" type="submit">Respinge</button>
                        </form>
                        <form method="post" action="<?= e(build_query_url(['page' => 'inactive_approvals', 'action' => 'approve'])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= e((string) ((int) ($approval['id'] ?? 0))) ?>">
                            <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                            <button class="btn btn-success" type="submit">Aproba</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
