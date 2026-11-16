<?php
declare(strict_types=1);

/**
 * Sumarul alertelor de documente (Expirate / 7 zile / 30 zile / Fără fișier) + lista
 * „Notificări documente”. Folosit de lista Documente (views/module/list.php) și de
 * Tabloul de bord (cardul „Alerte documente”). Stare curentă față de CURDATE().
 *
 * @var array  $documentSummary          DocumentModel::getNotificationSummary()
 * @var array  $urgentDocuments          DocumentModel::getUrgentDocuments()
 * @var array  $documentQuickBase        parametrii de bază ai linkurilor spre lista filtrată
 * @var string $documentSummaryColClass  (opțional) clasele coloanei unui card
 */
$documentSummary = is_array($documentSummary ?? null) ? $documentSummary : [];
$urgentDocuments = is_array($urgentDocuments ?? null) ? $urgentDocuments : [];
$documentQuickBase = is_array($documentQuickBase ?? null) ? $documentQuickBase : ['page' => 'documente', 'action' => 'index'];
$documentSummaryColClass = (string) ($documentSummaryColClass ?? 'col-12 col-md-6 col-xl-3');
?>
<div class="row g-3 mb-3">
    <div class="<?= e($documentSummaryColClass) ?>">
        <div class="card border-0 shadow-sm document-alert-card alert-expired h-100">
            <div class="card-body">
                <div class="small text-uppercase fw-semibold mb-2">Expirate</div>
                <div class="display-6 fw-bold mb-2"><?= e((string) ($documentSummary['expirate'] ?? 0)) ?></div>
                <a class="btn btn-sm btn-outline-danger" href="<?= e(build_query_url(array_merge($documentQuickBase, ['stare_expirare' => 'expirate']))) ?>">Vezi documentele</a>
            </div>
        </div>
    </div>
    <div class="<?= e($documentSummaryColClass) ?>">
        <div class="card border-0 shadow-sm document-alert-card alert-7days h-100">
            <div class="card-body">
                <div class="small text-uppercase fw-semibold mb-2">Expiră în 7 zile</div>
                <div class="display-6 fw-bold mb-2"><?= e((string) ($documentSummary['expira_7_zile'] ?? 0)) ?></div>
                <a class="btn btn-sm btn-outline-warning" href="<?= e(build_query_url(array_merge($documentQuickBase, ['stare_expirare' => 'expira_7_zile']))) ?>">Filtru rapid</a>
            </div>
        </div>
    </div>
    <div class="<?= e($documentSummaryColClass) ?>">
        <div class="card border-0 shadow-sm document-alert-card alert-30days h-100">
            <div class="card-body">
                <div class="small text-uppercase fw-semibold mb-2">Expiră în 30 zile</div>
                <div class="display-6 fw-bold mb-2"><?= e((string) ($documentSummary['expira_30_zile'] ?? 0)) ?></div>
                <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(array_merge($documentQuickBase, ['stare_expirare' => 'expira_30_zile']))) ?>">Filtru rapid</a>
            </div>
        </div>
    </div>
    <div class="<?= e($documentSummaryColClass) ?>">
        <div class="card border-0 shadow-sm document-alert-card alert-missing-file h-100">
            <div class="card-body">
                <div class="small text-uppercase fw-semibold mb-2">Fără fișier</div>
                <div class="display-6 fw-bold mb-2"><?= e((string) ($documentSummary['fara_fisier'] ?? 0)) ?></div>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(array_merge($documentQuickBase, ['are_fisier' => 'nu']))) ?>">Vezi lipsurile</a>
            </div>
        </div>
    </div>
</div>

<?php if ($urgentDocuments !== []): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white">
            <h3 class="h6 mb-0">Notificări documente</h3>
        </div>
        <div class="card-body">
            <div class="list-group list-group-flush">
                <?php foreach ($urgentDocuments as $document): ?>
                    <div class="list-group-item px-0">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="fw-semibold"><?= e($document['vehicul_label']) ?> - <?= e($document['tip_document']) ?></div>
                                <div class="small text-muted">
                                    <?php if (!empty($document['numar_document'])): ?>
                                        <?= e($document['numar_document']) ?> |
                                    <?php endif; ?>
                                    <?= expiry_badge_html($document['data_expirare']) ?>
                                </div>
                            </div>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'show', 'id' => (int) $document['id']])) ?>">Detalii</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
