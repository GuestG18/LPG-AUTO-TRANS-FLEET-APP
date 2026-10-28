<?php
declare(strict_types=1);

/**
 * Formularul Adauga / Editeaza interventie (modalul #maintenanceScheduleModal),
 * pe sectiuni: Date generale, Componente vizate, Problema raportata, Organizare.
 * Componentele vin din Registru piese & lucrari (catalogul Reparatii Auto); sunt optionale.
 *
 * @var array      $scheduleForm
 * @var array|null $editIntervention
 * @var array      $vehicles
 * @var array      $drivers
 * @var array      $costCenterOptions
 * @var callable   $renderCostCenterOptions
 * @var array|null $interventionBoot
 */
$formBoot = is_array($interventionBoot ?? null) ? $interventionBoot : [];
$formCoordinators = is_array($formBoot['coordinators'] ?? null) ? $formBoot['coordinators'] : [];
$formCan = is_array($formBoot['can'] ?? null) ? $formBoot['can'] : ['costs' => true];
$isEdit = $editIntervention !== null;
$formStatusKey = $isEdit
    ? InterventionWorkflowModel::statusKey((string) ($scheduleForm['status_interventie'] ?? ''))
    : 'de_verificat';
$existingItems = $isEdit && is_array($editIntervention['lucrari'] ?? null) ? $editIntervention['lucrari'] : [];
$formVehicles = [];
foreach ($vehicles as $vehicle) {
    $formVehicles[(int) $vehicle['id']] = [
        'plate' => (string) $vehicle['nr_inmatriculare'],
        'model' => trim((string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? '')),
        'type' => (string) ($vehicle['tip_vehicul'] ?? ''),
    ];
}
$originalReport = trim((string) ($scheduleForm['sesizare_initiala'] ?? ''));
?>
<div class="modal fade iv2-modal" id="maintenanceScheduleModal" tabindex="-1" data-auto-open="<?= $isEdit ? '1' : '0' ?>" aria-labelledby="maintenanceScheduleModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form class="modal-content iv2 iv2-form" method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'save_intervention'])) ?>" data-iv2-intervention-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e((string) ($scheduleForm['id'] ?? '')) ?>">
            <input type="hidden" name="components_json" value="[]" data-iv2-components-json>
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5" id="maintenanceScheduleModalTitle"><?= $isEdit ? 'Editează intervenția #' . e((string) $scheduleForm['id']) : 'Adaugă intervenție' ?></h2>
                    <p class="text-muted small mb-0">Dispecerul descrie problema; responsabilul tehnic stabilește apoi diagnosticul și lucrările.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <section class="iv2-form-section" aria-labelledby="iv2-fs-1">
                    <h3 id="iv2-fs-1"><span class="num">1</span>Date generale</h3>
                    <div class="row g-3">
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label" for="iv2-form-vehicle">Vehicul <span>*</span></label>
                            <select class="form-select" id="iv2-form-vehicle" name="vehicle_id" data-maintenance-vehicle-select data-iv2-form-vehicle required>
                                <option value="">Selectează vehiculul</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?= e((string) $vehicle['id']) ?>" data-vehicle-type="<?= e((string) ($vehicle['tip_vehicul'] ?? '')) ?>" <?= (int) ($scheduleForm['vehicle_id'] ?? 0) === (int) $vehicle['id'] ? 'selected' : '' ?>><?= e((string) $vehicle['nr_inmatriculare'] . ' - ' . (string) $vehicle['marca'] . ' ' . (string) $vehicle['model']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label" for="iv2-form-date">Data programată <span>*</span></label>
                            <input class="form-control" id="iv2-form-date" type="date" name="data_programata" value="<?= e((string) $scheduleForm['data_programata']) ?>" required>
                        </div>
                        <div class="col-6 col-md-3 col-lg-2">
                            <label class="form-label" for="iv2-form-time">Ora (opțional)</label>
                            <input class="form-control" id="iv2-form-time" type="time" name="ora_programata" value="<?= e(substr((string) ($scheduleForm['ora_programata'] ?? ''), 0, 5)) ?>">
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label" for="iv2-form-type">Tip intervenție <span>*</span></label>
                            <select class="form-select" id="iv2-form-type" name="tip_interventie">
                                <option value="intretinere" <?= ($scheduleForm['tip_interventie'] ?? '') === 'intretinere' ? 'selected' : '' ?>>Întreținere</option>
                                <option value="reparatie" <?= ($scheduleForm['tip_interventie'] ?? '') === 'reparatie' ? 'selected' : '' ?>>Reparație</option>
                            </select>
                        </div>
                        <div class="col-6 col-lg-2">
                            <label class="form-label" for="iv2-form-priority">Prioritate</label>
                            <select class="form-select" id="iv2-form-priority" name="prioritate">
                                <?php foreach (InterventionWorkflowModel::PRIORITY_LABELS as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= ($scheduleForm['prioritate'] ?? 'normala') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="iv2-form-section" aria-labelledby="iv2-fs-2">
                    <h3 id="iv2-fs-2"><span class="num">2</span>Componente vizate <small>Opțional — o cerere de diagnoză poate rămâne fără piesă</small></h3>
                    <?php if ($existingItems !== []): ?>
                        <div class="iv2-note is-info mb-2">
                            Deja asociate: <?= e(implode(', ', array_map(static fn (array $item): string => $item['nume'] . ($item['pozitie'] !== '' ? ' (' . $item['pozitie'] . ')' : ''), $existingItems))) ?>.
                            Evaluarea și lucrările lor se gestionează din panoul intervenției; aici poți adăuga altele.
                        </div>
                    <?php endif; ?>
                    <div data-iv2-form-picker></div>
                </section>

                <section class="iv2-form-section" aria-labelledby="iv2-fs-3">
                    <h3 id="iv2-fs-3"><span class="num">3</span>Problemă raportată</h3>
                    <label class="form-label" for="iv2-form-description">Problemă raportată / Observații <span>*</span></label>
                    <textarea class="form-control" id="iv2-form-description" name="descriere" rows="4" required
                              placeholder="Simptome, ce a observat șoferul, când apare, piese bănuite, alte informații utile..."><?= e((string) $scheduleForm['descriere']) ?></textarea>
                    <?php if ($isEdit && $originalReport !== '' && $originalReport !== trim((string) $scheduleForm['descriere'])): ?>
                        <div class="form-text"><i class="bi bi-lock me-1"></i>Sesizarea inițială rămâne păstrată separat: „<?= e(mb_strimwidth($originalReport, 0, 160, '…', 'UTF-8')) ?>”</div>
                    <?php else: ?>
                        <div class="form-text">La salvarea unei intervenții noi, textul se păstrează și ca sesizare inițială (nu se suprascrie de diagnostic).</div>
                    <?php endif; ?>
                </section>

                <section class="iv2-form-section" aria-labelledby="iv2-fs-4">
                    <h3 id="iv2-fs-4"><span class="num">4</span>Organizare</h3>
                    <div class="row g-3">
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label" for="iv2-form-resp">Responsabil tehnic</label>
                            <select class="form-select" id="iv2-form-resp" name="responsabil_user_id">
                                <option value="">Nealocat</option>
                                <?php foreach ($formCoordinators as $coordinator): ?>
                                    <option value="<?= e((string) $coordinator['id']) ?>" <?= (int) ($scheduleForm['responsabil_user_id'] ?? 0) === (int) $coordinator['id'] ? 'selected' : '' ?>><?= e((string) $coordinator['nume']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Obligatoriu înainte de programare, service sau finalizare.</div>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label" for="iv2-form-supplier">Furnizor / Service</label>
                            <input class="form-control" id="iv2-form-supplier" name="furnizor" maxlength="190" value="<?= e((string) ($scheduleForm['furnizor'] ?? '')) ?>" placeholder="Ex: Service intern, Interactiv Bragadiru">
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label" for="iv2-form-driver">Șofer</label>
                            <select class="form-select" id="iv2-form-driver" name="driver_id">
                                <option value="">Fără șofer</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?= e((string) $driver['id']) ?>" <?= (int) ($scheduleForm['driver_id'] ?? 0) === (int) $driver['id'] ? 'selected' : '' ?>><?= e((string) $driver['nume']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label" for="iv2-form-center">Centru de cost</label>
                            <select class="form-select" id="iv2-form-center" name="centru_cost" data-maintenance-cost-center-select><?php $renderCostCenterOptions($costCenterOptions, (string) ($scheduleForm['centru_cost'] ?? ''), true, 'Fără centru de cost'); ?></select>
                        </div>
                        <?php if (!empty($formCan['costs'])): ?>
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="iv2-form-cost">Cost estimat (lei)</label>
                                <input class="form-control" id="iv2-form-cost" type="number" name="cost_estimat" min="0" step="0.01" value="<?= e((string) $scheduleForm['cost_estimat']) ?>">
                            </div>
                        <?php endif; ?>
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label" for="iv2-form-status">Status</label>
                            <select class="form-select" id="iv2-form-status" name="status">
                                <?php foreach (InterventionWorkflowModel::STATUS_LABELS as $value => $label): ?>
                                    <?php if ($value === 'finalizata' && $formStatusKey !== 'finalizata') { continue; } ?>
                                    <option value="<?= e($value) ?>" <?= $formStatusKey === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Finalizarea se face din panou, cu revizuirea fiecărei lucrări.</div>
                        </div>
                    </div>
                    <details class="iv2-more mt-3" <?= trim((string) ($scheduleForm['client'] ?? '')) !== '' ? 'open' : '' ?>>
                        <summary>Detalii suplimentare</summary>
                        <div class="row g-3">
                            <div class="col-md-6 col-lg-4">
                                <label class="form-label" for="iv2-form-client">Client (opțional)</label>
                                <input class="form-control" id="iv2-form-client" name="client" maxlength="190" value="<?= e((string) ($scheduleForm['client'] ?? '')) ?>">
                            </div>
                        </div>
                    </details>
                </section>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Anulează</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Salvează intervenția</button>
            </div>
        </form>
    </div>
</div>
<script type="application/json" id="iv2-form-boot"><?= json_encode([
    'catalog' => $formBoot['catalog'] ?? [],
    'vehicles' => $formVehicles,
    'labels' => ['operation' => InterventionWorkflowModel::OPERATION_LABELS],
    'endpoint' => url('index.php'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
