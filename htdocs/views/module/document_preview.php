<?php
$documentUrl = document_file_url((string) ($record['fisier_stocat'] ?? ''));
$vehicleId = (int) ($record['vehicle_id'] ?? 0);
$vehicleLabel = (string) ($record['vehicul_label'] ?? '');
$driverId = (int) ($record['driver_id'] ?? 0);
$driverLabel = (string) ($record['sofer_label'] ?? '');

$isMaintenance = $moduleKey === 'mentenanta';
$isDriverDocument = $moduleKey === 'documente_soferi';

$pageHeading = $isMaintenance ? 'Previzualizare factura' : 'Previzualizare document';
$itemTypeLabel = $isMaintenance ? 'Factura interventie' : (string) ($record['tip_document'] ?? 'Document');
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h4 mb-1"><?= e($pageHeading) ?></h2>
        <p class="text-muted mb-0">
            <?= e($itemTypeLabel) ?>
            <?php if ($vehicleLabel !== ''): ?>
                pentru vehiculul <?= e($vehicleLabel) ?>
            <?php endif; ?>
            <?php if ($isDriverDocument && $driverLabel !== ''): ?>
                | sofer: <?= e($driverLabel) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'show', 'id' => (int) $record['id']])) ?>">Detalii</a>
        <?php if ($moduleKey === 'documente' && $vehicleId > 0): ?>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $vehicleId])) ?>">Vehiculul asociat</a>
        <?php endif; ?>
        <?php if ($moduleKey === 'documente_soferi' && $driverId > 0): ?>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'soferi', 'action' => 'show', 'id' => $driverId])) ?>">Soferul asociat</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'edit', 'id' => (int) $record['id']])) ?>">
            <?= $isMaintenance ? 'Editeaza interventia' : 'Editeaza documentul' ?>
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-xl-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h3 class="h6 mb-0">Vizualizare in aplicatie</h3>
                <?php if ($documentUrl !== null): ?>
                    <a class="btn btn-sm btn-outline-dark" href="<?= e($documentUrl) ?>" target="_blank" rel="noopener">Deschide fisierul</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?= document_preview_html((string) ($record['fisier_original'] ?? ''), (string) ($record['fisier_stocat'] ?? '')) ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white">
                <h3 class="h6 mb-0">Detalii rapide</h3>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <?php if ($vehicleLabel !== ''): ?>
                        <dt class="col-sm-5">Vehicul</dt>
                        <dd class="col-sm-7"><?= e($vehicleLabel) ?></dd>
                    <?php endif; ?>

                    <?php if ($isDriverDocument && $driverLabel !== ''): ?>
                        <dt class="col-sm-5">Sofer</dt>
                        <dd class="col-sm-7"><?= e($driverLabel) ?></dd>
                    <?php endif; ?>

                    <?php if ($isMaintenance): ?>
                        <dt class="col-sm-5">Tip</dt>
                        <dd class="col-sm-7"><?= e((string) ($record['tip_interventie'] ?? '-')) ?></dd>

                        <dt class="col-sm-5">Data</dt>
                        <dd class="col-sm-7"><?= e(format_date_ro((string) ($record['data_interventie'] ?? ''))) ?></dd>

                        <dt class="col-sm-5">Cost</dt>
                        <dd class="col-sm-7"><?= e(format_number_ro((string) ($record['cost'] ?? 0), 2)) ?> lei</dd>
                    <?php else: ?>
                        <dt class="col-sm-5">Tip</dt>
                        <dd class="col-sm-7"><?= e((string) ($record['tip_document'] ?? '-')) ?></dd>

                        <dt class="col-sm-5">Serie / numar</dt>
                        <dd class="col-sm-7"><?= e((string) (($record['numar_document'] ?? '') !== '' ? $record['numar_document'] : '-')) ?></dd>

                        <dt class="col-sm-5">Expirare</dt>
                        <dd class="col-sm-7"><?= expiry_badge_html((string) ($record['data_expirare'] ?? '')) ?></dd>
                    <?php endif; ?>

                    <dt class="col-sm-5">Fisier</dt>
                    <dd class="col-sm-7"><?= e((string) (($record['fisier_original'] ?? '') !== '' ? $record['fisier_original'] : 'Niciun fisier incarcat')) ?></dd>
                </dl>

                <div class="alert alert-light border mt-3 mb-0">
                    PDF-urile si imaginile se vad direct in aplicatie. Pentru DOC sau DOCX foloseste butonul de deschidere.
                </div>
            </div>
        </div>
    </div>
</div>
