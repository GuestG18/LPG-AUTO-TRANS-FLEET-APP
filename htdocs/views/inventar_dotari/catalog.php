<?php
$catalogItems = is_array($catalogItems ?? null) ? $catalogItems : [];
$equipmentTypeOptions = is_array($equipmentTypeOptions ?? null)
    ? $equipmentTypeOptions
    : VehicleEquipmentInventoryModel::EQUIPMENT_TYPES;

$imageThumb = static function (?string $storedFile, ?string $originalFile = null): string {
    $url = inventory_equipment_image_url($storedFile);
    if ($url === null) {
        return '<span class="inventory-equipment-thumb inventory-equipment-thumb-empty">Fără poză</span>';
    }

    $safeUrl = e($url);
    $safeAlt = e($originalFile !== null && trim($originalFile) !== '' ? $originalFile : 'Poză produs');

    return '<a href="' . $safeUrl . '" target="_blank" rel="noopener" class="inventory-equipment-thumb">'
        . '<img src="' . $safeUrl . '" alt="' . $safeAlt . '" loading="lazy">'
        . '</a>';
};

$equipmentTypeBadge = static function (?string $type) use ($equipmentTypeOptions): string {
    $type = array_key_exists((string) $type, $equipmentTypeOptions) ? (string) $type : 'mandatory';
    $class = $type === 'optional' ? 'text-bg-secondary' : 'text-bg-primary';

    return '<span class="badge ' . $class . '">' . e((string) $equipmentTypeOptions[$type]) . '</span>';
};

$catalogForm = function (?array $item = null) use ($equipmentTypeOptions): void {
    $isEdit = $item !== null;
    $modalId = $isEdit ? 'catalog-edit-' . (int) $item['id'] : 'catalog-add';
    $selectedEquipmentType = (string) ($item['equipment_type'] ?? 'mandatory');
    if (!array_key_exists($selectedEquipmentType, $equipmentTypeOptions)) {
        $selectedEquipmentType = 'mandatory';
    }
    ?>
    <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-labelledby="<?= e($modalId) ?>Label" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" action="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'save_catalog'])) ?>" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="catalog_id" value="<?= e((string) ((int) $item['id'])) ?>">
                    <?php endif; ?>
                    <div class="modal-header">
                        <h3 class="modal-title fs-5" id="<?= e($modalId) ?>Label"><?= $isEdit ? 'Editează dotare catalog' : 'Adaugă dotare catalog' ?></h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label">Nume Dotare *</label>
                                <input class="form-control" name="nume" maxlength="150" required value="<?= e((string) ($item['nume'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Categorie *</label>
                                <input class="form-control" name="categorie" maxlength="120" required value="<?= e((string) ($item['categorie'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Poză Produs</label>
                                <input class="form-control" type="file" name="poza_produs" accept=".jpg,.jpeg,.png,.webp">
                                <div class="form-text">JPG, PNG, WEBP. Maxim 5 MB.</div>
                                <?php if ($isEdit && trim((string) ($item['poza_stocata'] ?? '')) !== ''): ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="sterge_poza" value="1" id="<?= e($modalId) ?>_sterge_poza">
                                        <label class="form-check-label" for="<?= e($modalId) ?>_sterge_poza">Șterge poza curentă</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Tip dotare *</label>
                                <select class="form-select" name="equipment_type" required>
                                    <?php foreach ($equipmentTypeOptions as $value => $label): ?>
                                        <option value="<?= e((string) $value) ?>" <?= $selectedEquipmentType === (string) $value ? 'selected' : '' ?>>
                                            <?= e((string) $label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Cost Implicit</label>
                                <input class="form-control" type="number" name="cost_implicit" min="0" step="0.01" value="<?= e((string) ($item['cost_implicit'] ?? '0')) ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">Interval Implicit Inspecție (luni)</label>
                                <input class="form-control" type="number" name="interval_implicit_inspectie_luni" min="1" step="1" value="<?= e((string) ($item['interval_implicit_inspectie_luni'] ?? '')) ?>">
                            </div>
                            <div class="col-12">
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="necesita_data_fabricatie" value="1" id="<?= e($modalId) ?>_fabricatie" <?= !empty($item['necesita_data_fabricatie']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="<?= e($modalId) ?>_fabricatie">Necesită Data Fabricației</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="necesita_inspectie" value="1" id="<?= e($modalId) ?>_inspectie" <?= !empty($item['necesita_inspectie']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="<?= e($modalId) ?>_inspectie">Necesită Inspecție</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="necesita_data_expirarii" value="1" id="<?= e($modalId) ?>_expirare" <?= !empty($item['necesita_data_expirarii']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="<?= e($modalId) ?>_expirare">Necesită Data Expirării</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="activ" value="1" id="<?= e($modalId) ?>_activ" <?= $item === null || !empty($item['activ']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="<?= e($modalId) ?>_activ">Activ</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                        <button type="submit" class="btn btn-primary">Salvează</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <h2 class="h4 mb-1">Catalog Dotări</h2>
        <div class="text-muted">Definește produse reutilizabile pentru asignarea pe vehicule.</div>
    </div>
    <div class="inventory-toolbar">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#catalog-add">Adaugă dotare</button>
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'rules'])) ?>">Reguli Dotări</a>
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule'])) ?>">Înapoi la inventar</a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 module-list-table inventory-table">
                <thead>
                <tr>
                    <th>Poză</th>
                    <th>Nume Dotare</th>
                    <th>Categorie</th>
                    <th>Tip dotare</th>
                    <th>Cost Implicit</th>
                    <th>Fabric.</th>
                    <th>Inspecție</th>
                    <th>Interval</th>
                    <th>Expirare</th>
                    <th>Activ</th>
                    <th class="text-end pe-3">Acțiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($catalogItems === []): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">Nu există dotări în catalog.</td></tr>
                <?php else: ?>
                    <?php foreach ($catalogItems as $item): ?>
                        <tr>
                            <td><?= $imageThumb((string) ($item['poza_stocata'] ?? ''), (string) ($item['poza_original'] ?? '')) ?></td>
                            <td class="fw-semibold"><?= e((string) ($item['nume'] ?? '-')) ?></td>
                            <td><?= e((string) ($item['categorie'] ?? '-')) ?></td>
                            <td><?= $equipmentTypeBadge((string) ($item['equipment_type'] ?? 'mandatory')) ?></td>
                            <td><?= e(format_number_ro((float) ($item['cost_implicit'] ?? 0), 2)) ?> lei</td>
                            <td><?= yes_no_badge_html($item['necesita_data_fabricatie'] ?? 0) ?></td>
                            <td><?= yes_no_badge_html($item['necesita_inspectie'] ?? 0) ?></td>
                            <td><?= !empty($item['interval_implicit_inspectie_luni']) ? e((string) ((int) $item['interval_implicit_inspectie_luni'])) . ' luni' : '-' ?></td>
                            <td><?= yes_no_badge_html($item['necesita_data_expirarii'] ?? 0) ?></td>
                            <td><?= yes_no_badge_html($item['activ'] ?? 0) ?></td>
                            <td class="text-end pe-3">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#catalog-edit-<?= e((string) ((int) $item['id'])) ?>">Editează</button>
                                <form method="post" action="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'delete_catalog'])) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="catalog_id" value="<?= e((string) ((int) $item['id'])) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Ștergi această dotare din catalog?">Șterge</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white">
        <small class="text-muted">Total dotări catalog: <?= e((string) count($catalogItems)) ?></small>
    </div>
</div>

<?php $catalogForm(null); ?>
<?php foreach ($catalogItems as $item): ?>
    <?php $catalogForm($item); ?>
<?php endforeach; ?>
