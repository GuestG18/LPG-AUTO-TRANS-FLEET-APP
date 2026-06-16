<?php
$rules = is_array($rules ?? null) ? $rules : [];
$catalogItems = is_array($catalogItems ?? null) ? $catalogItems : [];
$vehicleTypeOptions = is_array($vehicleTypeOptions ?? null) ? $vehicleTypeOptions : [];

$rulesByVehicleType = [];
foreach ($rules as $rule) {
    $vehicleType = (string) ($rule['vehicle_type'] ?? '');
    if (!isset($rulesByVehicleType[$vehicleType])) {
        $rulesByVehicleType[$vehicleType] = [];
    }
    $rulesByVehicleType[$vehicleType][] = $rule;
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <h2 class="h4 mb-1">Reguli Dotări</h2>
        <div class="text-muted">Definește dotările obligatorii pentru fiecare tip de vehicul.</div>
    </div>
    <div class="inventory-toolbar">
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'catalog'])) ?>">Catalog Dotări</a>
        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule'])) ?>">Înapoi la inventar</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="post" action="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'add_rule'])) ?>" class="row g-3 align-items-end">
            <?= csrf_field() ?>
            <div class="col-12 col-md-5">
                <label class="form-label" for="inventory_rule_vehicle_type">Tip vehicul *</label>
                <select class="form-select" id="inventory_rule_vehicle_type" name="vehicle_type" required>
                    <option value="">-- Selectează tipul --</option>
                    <?php foreach ($vehicleTypeOptions as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>"><?= e((string) $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label" for="inventory_rule_catalog_id">Dotare obligatorie *</label>
                <select class="form-select" id="inventory_rule_catalog_id" name="catalog_id" required>
                    <option value="">-- Selectează dotarea --</option>
                    <?php foreach ($catalogItems as $item): ?>
                        <option value="<?= e((string) ((int) ($item['id'] ?? 0))) ?>">
                            <?= e((string) ($item['categorie'] ?? '')) ?> — <?= e((string) ($item['nume'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto">
                <button type="submit" class="btn btn-primary" <?= $catalogItems === [] || $vehicleTypeOptions === [] ? 'disabled' : '' ?>>Adaugă regulă</button>
            </div>
        </form>
        <?php if ($catalogItems === []): ?>
            <div class="alert alert-warning mt-3 mb-0">Adaugă mai întâi produse active în Catalog Dotări.</div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 module-list-table inventory-table">
                <thead>
                <tr>
                    <th>Tip Vehicul</th>
                    <th>Dotări obligatorii</th>
                    <th>Total reguli</th>
                    <th class="text-end pe-3">Acțiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($vehicleTypeOptions === []): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">Nu există tipuri de vehicule configurate.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vehicleTypeOptions as $vehicleType => $vehicleLabel): ?>
                        <?php $typeRules = $rulesByVehicleType[(string) $vehicleType] ?? []; ?>
                        <tr>
                            <td class="fw-semibold"><?= e((string) $vehicleLabel) ?></td>
                            <td>
                                <?php if ($typeRules === []): ?>
                                    <span class="text-muted">Nu există reguli definite.</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($typeRules as $rule): ?>
                                            <span class="badge rounded-pill text-bg-light border text-dark">
                                                <?= e((string) ($rule['catalog_nume'] ?? '')) ?>
                                                <span class="text-muted">(<?= e((string) ($rule['catalog_categorie'] ?? '')) ?>)</span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) count($typeRules)) ?></td>
                            <td class="text-end pe-3">
                                <?php if ($typeRules === []): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                        <?php foreach ($typeRules as $rule): ?>
                                            <form method="post" action="<?= e(build_query_url(['page' => 'inventar_dotari_vehicule', 'action' => 'delete_rule'])) ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="rule_id" value="<?= e((string) ((int) ($rule['id'] ?? 0))) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Ștergi regula pentru <?= e((string) ($rule['catalog_nume'] ?? 'această dotare')) ?>?">
                                                    Șterge <?= e((string) ($rule['catalog_nume'] ?? '')) ?>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white">
        <small class="text-muted">Total reguli active: <?= e((string) count($rules)) ?></small>
    </div>
</div>
