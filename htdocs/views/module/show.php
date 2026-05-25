<?php
$detailFields = $module['detail_fields'] ?? $module['form_fields'];
$backUrl = $backUrl ?? build_query_url(['page' => $moduleKey]);
$driverDocuments = $driverDocuments ?? [];
$vehicleDocuments = $vehicleDocuments ?? [];
$statusContext = $statusContext ?? null;
$vehicleCouplingContext = $vehicleCouplingContext ?? null;
$vehicleTireContext = $vehicleTireContext ?? null;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h4 mb-0">Detalii <?= e($module['singular']) ?></h2>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'edit', 'id' => (int) $record['id']])) ?>">Editează</a>
        <a class="btn btn-outline-secondary" href="<?= e($backUrl) ?>">Înapoi</a>
    </div>
</div>

<?php if (in_array($moduleKey, ['vehicule', 'soferi'], true) && is_array($statusContext)): ?>
    <div class="alert <?= ($statusContext['status'] ?? 'inactiv') === 'activ' ? 'alert-success' : 'alert-warning' ?> mb-3">
        <div class="fw-semibold mb-2">
            Status calculat automat:
            <?= ($statusContext['status'] ?? 'inactiv') === 'activ' ? 'Activ' : 'Inactiv' ?>
        </div>
        <p class="mb-2">
            Statusul nu mai este setat manual. El se actualizeaza automat in functie de documentele obligatorii si de valabilitatea lor.
        </p>
        <?php if (($statusContext['checks'] ?? []) !== []): ?>
            <ul class="mb-0 ps-3">
                <?php foreach ($statusContext['checks'] as $check): ?>
                    <li>
                        <strong><?= e((string) ($check['label'] ?? '-')) ?>:</strong>
                        <?= e((string) ($check['message'] ?? '')) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <tbody>
                <?php foreach ($detailFields as $field => $meta): ?>
                    <?php
                    if (($meta['store'] ?? true) === false) {
                        continue;
                    }
                    ?>
                    <tr>
                        <th class="w-25 bg-light"><?= e($meta['label']) ?></th>
                        <td><?= format_value_html($record[$field] ?? null, $meta, $record) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (in_array($moduleKey, ['documente', 'documente_soferi'], true)): ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="h6 mb-1">Fișier atașat și previzualizare</h3>
                <p class="text-muted small mb-0">Verifici rapid documentul încărcat și poți intra imediat în editare dacă observi o informație greșită.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'preview', 'id' => (int) $record['id']])) ?>">Vezi în aplicație</a>
                <?php if (!empty($record['fisier_stocat'])): ?>
                    <a class="btn btn-sm btn-outline-dark" href="<?= e(document_file_url((string) $record['fisier_stocat']) ?? '#') ?>" target="_blank" rel="noopener">Deschide fișierul</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-primary" href="<?= e(build_query_url(['page' => $moduleKey, 'action' => 'edit', 'id' => (int) $record['id']])) ?>">Corectează datele</a>
            </div>
        </div>
        <div class="card-body">
            <?= document_preview_html((string) ($record['fisier_original'] ?? ''), (string) ($record['fisier_stocat'] ?? '')) ?>
            <div class="alert alert-light border mt-3 mb-0">
                <strong>Serie / număr document:</strong>
                câmp opțional folosit doar când documentul are un identificator util, de exemplu seria permisului, seria atestatului sau un număr intern de referință.
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($moduleKey === 'mentenanta'): ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="h6 mb-1">Factura atasata</h3>
                <p class="text-muted small mb-0">Deschizi rapid factura din interventie si o poti inlocui din editare daca ai incarcat varianta gresita.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'preview', 'id' => (int) $record['id']])) ?>">Vezi factura</a>
                <?php if (!empty($record['fisier_stocat'])): ?>
                    <a class="btn btn-sm btn-outline-dark" href="<?= e(document_file_url((string) $record['fisier_stocat']) ?? '#') ?>" target="_blank" rel="noopener">Deschide fisierul</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-primary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'edit', 'id' => (int) $record['id']])) ?>">Editeaza interventia</a>
            </div>
        </div>
        <div class="card-body">
            <?= document_preview_html((string) ($record['fisier_original'] ?? ''), (string) ($record['fisier_stocat'] ?? '')) ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($moduleKey === 'vehicule'): ?>
    <?php
    $coupling = is_array($vehicleCouplingContext) ? $vehicleCouplingContext : [];
    $vehicleType = (string) ($coupling['vehicle_type'] ?? ($record['tip_vehicul'] ?? 'autovehicul'));
    $activeCoupling = $coupling['active_coupling'] ?? null;
    $tractorOptions = is_array($coupling['tractor_options'] ?? null) ? $coupling['tractor_options'] : [];
    $trailerOptions = is_array($coupling['trailer_options'] ?? null) ? $coupling['trailer_options'] : [];
    $hasTrailerOptions = $trailerOptions !== [];
    $hasTractorOptions = $tractorOptions !== [];
    $couplingHistory = is_array($coupling['history'] ?? null) ? $coupling['history'] : [];
    $tireContext = is_array($vehicleTireContext) ? $vehicleTireContext : [];
    $tireLayout = is_array($tireContext['layout'] ?? null) ? $tireContext['layout'] : [];
    $tirePositions = is_array($tireContext['positions'] ?? null) ? $tireContext['positions'] : [];
    $tireAlerts = is_array($tireContext['alerts'] ?? null) ? $tireContext['alerts'] : [];
    $availableTires = is_array($tireContext['available_tires'] ?? null) ? $tireContext['available_tires'] : [];
    $tireHistory = is_array($tireContext['history'] ?? null) ? $tireContext['history'] : [];
    $layoutOptions = is_array($tireContext['layout_options'] ?? null) ? $tireContext['layout_options'] : [];
    $layoutCurrentValue = (string) ($tireContext['layout_current_value'] ?? ($record['formula_axelor'] ?? ''));
    $layoutCurrentValue = $layoutCurrentValue !== '' ? $layoutCurrentValue : (string) ($record['formula_axelor'] ?? '');
    $todayDate = (string) ($tireContext['today'] ?? date('Y-m-d'));
    $mountedTires = (int) ($tireLayout['mounted_tires'] ?? 0);
    $expectedTires = (int) ($tireLayout['expected_tires'] ?? count($tirePositions));
    $unmountedPositions = (int) ($tireLayout['unmounted_positions'] ?? max(0, $expectedTires - $mountedTires));
    ?>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="h6 mb-1">Anvelope vehicul</h3>
                <p class="text-muted small mb-0">Structura este dinamica pe tip vehicul, configuratie axe si pozitii de roti.</p>
            </div>
            <span class="badge text-bg-light border">
                <?= e((string) ($tireLayout['axle_count'] ?? 0)) ?> axe | <?= e((string) $expectedTires) ?> pozitii
            </span>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4">
                    <div class="small text-muted">Tip vehicul</div>
                    <div class="fw-semibold"><?= e(vehicle_type_label((string) ($tireLayout['vehicle_type'] ?? $vehicleType))) ?></div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="small text-muted">Anvelope montate</div>
                    <div class="fw-semibold"><?= e((string) $mountedTires) ?> / <?= e((string) $expectedTires) ?></div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="small text-muted">Pozitii libere</div>
                    <div class="fw-semibold <?= $unmountedPositions > 0 ? 'text-warning' : 'text-success' ?>"><?= e((string) $unmountedPositions) ?></div>
                </div>
            </div>

            <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'update_tire_layout'])) ?>" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">

                <div class="col-12 col-md-7 col-xl-8">
                    <label class="form-label" for="tire_layout_value">Configuratie axe</label>
                    <?php if ($layoutOptions !== []): ?>
                        <select class="form-select" id="tire_layout_value" name="tire_layout_value">
                            <?php foreach ($layoutOptions as $layoutValue => $layoutLabel): ?>
                                <option value="<?= e((string) $layoutValue) ?>" <?= (string) $layoutCurrentValue === (string) $layoutValue ? 'selected' : '' ?>>
                                    <?= e((string) $layoutLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" id="tire_layout_value" name="tire_layout_value" value="<?= e($layoutCurrentValue) ?>" maxlength="20">
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-5 col-xl-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Aplica configuratia</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($tireAlerts !== []): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white">
                <h3 class="h6 mb-0">Alerte anvelope</h3>
            </div>
            <div class="card-body">
                <?php foreach ($tireAlerts as $alert): ?>
                    <?php $alertType = in_array((string) ($alert['type'] ?? ''), ['danger', 'warning', 'info', 'success'], true) ? (string) $alert['type'] : 'warning'; ?>
                    <div class="alert alert-<?= e($alertType) ?> mb-2">
                        <strong><?= e((string) ($alert['title'] ?? 'Alerta')) ?>:</strong>
                        <?= e((string) ($alert['message'] ?? '')) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white">
            <h3 class="h6 mb-0">Pozitii anvelope si uzura</h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Pozitie</th>
                        <th>Anvelopa</th>
                        <th>Status</th>
                        <th>Uzura km</th>
                        <th>DOT</th>
                        <th class="text-end pe-3">Actiuni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($tirePositions === []): ?>
                        <tr>
                            <td colspan="6" class="text-muted text-center py-3">Nu exista pozitii generate inca pentru acest vehicul.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tirePositions as $position): ?>
                            <?php $tire = is_array($position['tire'] ?? null) ? $position['tire'] : null; ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e((string) ($position['position_code'] ?? '-')) ?></div>
                                    <div class="small text-muted"><?= e((string) ($position['position_label'] ?? '-')) ?></div>
                                </td>
                                <td>
                                    <?php if ($tire !== null): ?>
                                        <div class="fw-semibold"><?= e(trim((string) (($tire['brand'] ?? '') . ' ' . ($tire['model'] ?? '')))) ?></div>
                                        <div class="small text-muted">
                                            SN: <?= e((string) ($tire['serial_number'] ?? '-')) ?>
                                            <?php if (!empty($tire['tire_size'])): ?> | <?= e((string) $tire['tire_size']) ?><?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">Pozitie libera</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $tire !== null ? tire_status_badge_html((string) ($tire['tire_status'] ?? '')) : '<span class="badge text-bg-light border">Gol</span>' ?>
                                </td>
                                <td>
                                    <?php if ($tire !== null): ?>
                                        <div><?= e(number_format((float) ($tire['km_total_used'] ?? 0), 0, ',', '.')) ?> km folositi</div>
                                        <?php if (isset($tire['estimated_life_km']) && $tire['estimated_life_km'] !== null): ?>
                                            <div class="small text-muted">
                                                <?php if ((int) ($tire['km_over'] ?? 0) > 0): ?>
                                                    Depasit cu <?= e(number_format((float) ($tire['km_over'] ?? 0), 0, ',', '.')) ?> km
                                                <?php else: ?>
                                                    Ramasi <?= e(number_format((float) ($tire['km_remaining'] ?? 0), 0, ',', '.')) ?> km
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="small text-muted">Durata km necompletata</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($tire !== null): ?>
                                        <div>DOT: <?= e((string) (($tire['dot_code'] ?? '') !== '' ? $tire['dot_code'] : '-')) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <?php if ($tire !== null && isset($position['allocation_id']) && $position['allocation_id'] !== null): ?>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'unmount_tire'])) ?>" class="d-inline-flex align-items-center gap-2">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">
                                            <input type="hidden" name="allocation_id" value="<?= e((string) ((int) $position['allocation_id'])) ?>">
                                            <input type="hidden" name="unmount_date" value="<?= e($todayDate) ?>">
                                            <select class="form-select form-select-sm" name="status_end">
                                                <option value="spare">Rezerva</option>
                                                <option value="removed">Scoasa din uz</option>
                                                <option value="damaged">Deteriorata</option>
                                                <option value="retreaded">Resapata</option>
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Sigur doresti demontarea anvelopei de pe aceasta pozitie?">Demonteaza</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h3 class="h6 mb-0">Monteaza anvelopa existenta</h3>
                </div>
                <div class="card-body">
                    <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'mount_tire'])) ?>" class="row g-3 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">

                        <div class="col-12">
                            <label class="form-label" for="mount_tire_id">Anvelopa disponibila</label>
                            <select class="form-select" id="mount_tire_id" name="tire_id" required>
                                <option value="">-- Selecteaza --</option>
                                <?php foreach ($availableTires as $availableTire): ?>
                                    <?php
                                    $availableTireId = (int) ($availableTire['id'] ?? 0);
                                    $availableLabel = trim((string) ($availableTire['brand'] ?? '') . ' ' . (string) ($availableTire['model'] ?? ''));
                                    $availableSerial = trim((string) ($availableTire['serial_number'] ?? ''));
                                    $availableTargetType = vehicle_type_label((string) ($availableTire['target_vehicle_type'] ?? 'universal'));
                                    ?>
                                    <option value="<?= e((string) $availableTireId) ?>">
                                        <?= e($availableLabel !== '' ? $availableLabel : 'Anvelopa #' . $availableTireId) ?> | SN: <?= e($availableSerial !== '' ? $availableSerial : '-') ?> | Tip: <?= e($availableTargetType) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="mount_position_id">Pozitie montaj</label>
                            <select class="form-select" id="mount_position_id" name="position_id" required>
                                <option value="">-- Selecteaza --</option>
                                <?php foreach ($tirePositions as $position): ?>
                                    <option value="<?= e((string) ((int) ($position['position_id'] ?? 0))) ?>">
                                        <?= e((string) ($position['position_code'] ?? '-')) ?> - <?= e((string) ($position['position_label'] ?? '-')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="mount_date">Data montaj</label>
                            <input type="date" class="form-control" id="mount_date" name="mount_date" value="<?= e($todayDate) ?>" required>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-primary" <?= $availableTires === [] ? 'disabled' : '' ?>>Monteaza anvelopa</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h3 class="h6 mb-0">Adauga anvelopa noua</h3>
                </div>
                <div class="card-body">
                    <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'add_tire'])) ?>" class="row g-3 align-items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="tire_brand">Brand *</label>
                            <input type="text" class="form-control" id="tire_brand" name="tire_brand" maxlength="100" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="tire_model">Model</label>
                            <input type="text" class="form-control" id="tire_model" name="tire_model" maxlength="120">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="tire_size">Dimensiune</label>
                            <input type="text" class="form-control" id="tire_size" name="tire_size" maxlength="50" placeholder="Ex: 315/80 R22.5">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="tire_dot_code">DOT</label>
                            <input type="text" class="form-control" id="tire_dot_code" name="tire_dot_code" maxlength="20" placeholder="Ex: 3423">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="tire_serial_number">Numar serie *</label>
                            <input type="text" class="form-control" id="tire_serial_number" name="tire_serial_number" maxlength="120" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="tire_mount_date">Data montaj</label>
                            <input type="date" class="form-control" id="tire_mount_date" name="tire_mount_date" value="<?= e($todayDate) ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="tire_km_initial">Km curenti initiali</label>
                            <input
                                type="number"
                                min="0"
                                step="1"
                                class="form-control"
                                id="tire_km_initial"
                                name="tire_km_initial"
                                value="<?= e((string) max(0, (int) ($record['km_bord'] ?? 0))) ?>"
                            >
                            <div class="form-text">Preluat automat din Km bord al vehiculului si folosit ca reper la montaj (nu ca uzura initiala).</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="tire_estimated_life_km">Durata estimata (km)</label>
                            <input type="number" min="0" step="1" class="form-control" id="tire_estimated_life_km" name="tire_estimated_life_km" placeholder="Ex: 180000">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="mount_position_new_tire">Monteaza direct pe pozitie (optional)</label>
                            <select class="form-select" id="mount_position_new_tire" name="mount_position_id">
                                <option value="">Stoc (fara montaj acum)</option>
                                <?php foreach ($tirePositions as $position): ?>
                                    <option value="<?= e((string) ((int) ($position['position_id'] ?? 0))) ?>">
                                        <?= e((string) ($position['position_code'] ?? '-')) ?> - <?= e((string) ($position['position_label'] ?? '-')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="tire_notes">Observatii</label>
                            <textarea class="form-control" id="tire_notes" name="tire_notes" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Adauga anvelopa</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white">
            <h3 class="h6 mb-0">Istoric miscari anvelope</h3>
        </div>
        <div class="card-body p-0">
            <?php if ($tireHistory === []): ?>
                <div class="p-3 text-muted">Nu exista istoric de miscari pentru acest vehicul.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Anvelopa</th>
                            <th>Pozitie</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Km start</th>
                            <th>Km end</th>
                            <th>Status final</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tireHistory as $historyRow): ?>
                            <tr>
                                <td>
                                    <?= e(trim((string) (($historyRow['brand'] ?? '') . ' ' . ($historyRow['model'] ?? '')))) ?><br>
                                    <small class="text-muted">SN: <?= e((string) ($historyRow['serial_number'] ?? '-')) ?></small>
                                </td>
                                <td><?= e((string) (($historyRow['position_code'] ?? '') !== '' ? $historyRow['position_code'] : '-')) ?></td>
                                <td><?= e(format_date_ro((string) ($historyRow['data_start'] ?? ''))) ?></td>
                                <td><?= e(format_date_ro((string) ($historyRow['data_end'] ?? ''))) ?></td>
                                <td><?= $historyRow['km_start'] !== null ? e(number_format((float) ((int) $historyRow['km_start']), 0, ',', '.')) : '-' ?></td>
                                <td><?= $historyRow['km_end'] !== null ? e(number_format((float) ((int) $historyRow['km_end']), 0, ',', '.')) : '-' ?></td>
                                <td>
                                    <?php
                                    $historyStatus = (string) ($historyRow['status_end'] ?? '');
                                    if ($historyStatus === '') {
                                        echo '<span class="badge text-bg-success">In utilizare</span>';
                                    } else {
                                        echo tire_status_badge_html($historyStatus);
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="h6 mb-1">Cuplaj tractor - semiremorca</h3>
                <p class="text-muted small mb-0">Pentru cap tractor si semiremorca poti muta alocarea direct din aceasta pagina.</p>
            </div>
            <span class="badge text-bg-light border">Tip vehicul: <?= e(vehicle_type_label($vehicleType)) ?></span>
        </div>
        <div class="card-body">
            <?php if (in_array($vehicleType, ['autovehicul', 'camion'], true)): ?>
                <div class="alert alert-light border mb-0">
                    Acest vehicul este de tip <strong><?= e(vehicle_type_label($vehicleType)) ?></strong>. Cuplajul este disponibil doar pentru <strong>Cap tractor</strong> si <strong>Semi-remorca</strong>.
                </div>
            <?php elseif ($vehicleType === 'cap_tractor'): ?>
                <div class="mb-3">
                    <div class="small text-muted">Semiremorca curenta</div>
                    <div class="fw-semibold">
                        <?= $activeCoupling ? e((string) ($activeCoupling['semiremorca_nr'] ?? '-')) : 'Nicio semiremorca cuplata' ?>
                    </div>
                </div>

                <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'cupleaza'])) ?>" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="tractor_id" value="<?= e((string) ((int) $record['id'])) ?>">
                    <input type="hidden" name="redirect_vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">

                    <div class="col-12 col-md-8">
                        <label class="form-label mb-1" for="semiremorca_id">Selecteaza semiremorca</label>
                        <select class="form-select" id="semiremorca_id" name="semiremorca_id" <?= $hasTrailerOptions ? 'required' : 'disabled' ?>>
                            <?php if ($hasTrailerOptions): ?>
                                <option value="">-- Selecteaza --</option>
                            <?php else: ?>
                                <option value="">Nu exista semiremorci active si necuplate.</option>
                            <?php endif; ?>
                            <?php foreach ($trailerOptions as $optionId => $optionLabel): ?>
                                <option value="<?= e((string) $optionId) ?>" <?= $activeCoupling && (int) ($activeCoupling['semiremorca_id'] ?? 0) === (int) $optionId ? 'selected' : '' ?>>
                                    <?= e((string) $optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary" <?= $hasTrailerOptions ? '' : 'disabled' ?>>Salveaza cuplaj</button>
                    </div>
                </form>

                <?php if ($activeCoupling): ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'decupleaza'])) ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="tractor_id" value="<?= e((string) ((int) $record['id'])) ?>">
                        <input type="hidden" name="redirect_vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Sigur doresti sa decuplezi semiremorca de pe acest tractor?">
                            Decupleaza semiremorca
                        </button>
                    </form>
                <?php endif; ?>
            <?php elseif ($vehicleType === 'semiremorca'): ?>
                <div class="mb-3">
                    <div class="small text-muted">Tractor curent</div>
                    <div class="fw-semibold">
                        <?= $activeCoupling ? e((string) ($activeCoupling['tractor_nr'] ?? '-')) : 'Niciun tractor cuplat' ?>
                    </div>
                </div>

                <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'cupleaza'])) ?>" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="semiremorca_id" value="<?= e((string) ((int) $record['id'])) ?>">
                    <input type="hidden" name="redirect_vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">

                    <div class="col-12 col-md-8">
                        <label class="form-label mb-1" for="tractor_id">Selecteaza cap tractor</label>
                        <select class="form-select" id="tractor_id" name="tractor_id" <?= $hasTractorOptions ? 'required' : 'disabled' ?>>
                            <option value="">-- Selecteaza --</option>
                            <?php foreach ($tractorOptions as $optionId => $optionLabel): ?>
                                <option value="<?= e((string) $optionId) ?>" <?= $activeCoupling && (int) ($activeCoupling['tractor_id'] ?? 0) === (int) $optionId ? 'selected' : '' ?>>
                                    <?= e((string) $optionLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary" <?= $hasTractorOptions ? '' : 'disabled' ?>>Muta pe tractor</button>
                    </div>
                </form>

                <?php if ($activeCoupling): ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'decupleaza'])) ?>" class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="semiremorca_id" value="<?= e((string) ((int) $record['id'])) ?>">
                        <input type="hidden" name="redirect_vehicle_id" value="<?= e((string) ((int) $record['id'])) ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Sigur doresti sa decuplezi aceasta semiremorca?">
                            Decupleaza de pe tractor
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($couplingHistory !== []): ?>
                <div class="table-responsive mt-4">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Cap tractor</th>
                                <th>Semiremorca</th>
                                <th>Start</th>
                                <th>Sfarsit</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($couplingHistory as $history): ?>
                                <tr>
                                    <td><?= e((string) ($history['tractor_nr'] ?? '-')) ?></td>
                                    <td><?= e((string) ($history['semiremorca_nr'] ?? '-')) ?></td>
                                    <td><?= e(format_datetime_ro((string) ($history['data_start'] ?? ''))) ?></td>
                                    <td><?= e(format_datetime_ro((string) ($history['data_end'] ?? ''))) ?></td>
                                    <td><?= !empty($history['activ']) ? '<span class="badge text-bg-success">Activ</span>' : '<span class="badge text-bg-secondary">Incheiat</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="h6 mb-1">Documente asociate vehiculului</h3>
                <p class="text-muted small mb-0">Vezi toate documentele atașate acestui vehicul și intră direct în previzualizare.</p>
            </div>
            <a class="btn btn-sm btn-primary" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'create', 'vehicle_id' => (int) $record['id']])) ?>">Adaugă document</a>
        </div>
        <div class="card-body p-0">
            <?php if ($vehicleDocuments === []): ?>
                <div class="p-3 text-muted">Nu există documente asociate acestui vehicul.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Tip document</th>
                            <th>Serie / număr</th>
                            <th>Fișier</th>
                            <th>Expirare</th>
                            <th class="text-end pe-3">Acțiuni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($vehicleDocuments as $document): ?>
                            <tr>
                                <td><?= e((string) ($document['tip_document'] ?? '-')) ?></td>
                                <td><?= e((string) (($document['numar_document'] ?? '') !== '' ? $document['numar_document'] : '-')) ?></td>
                                <td><?= document_file_link_html((string) ($document['fisier_original'] ?? ''), (string) ($document['fisier_stocat'] ?? '')) ?></td>
                                <td><?= expiry_badge_html((string) ($document['data_expirare'] ?? '')) ?></td>
                                <td class="text-end pe-3">
                                    <div class="d-inline-flex gap-1">
                                        <?php if (!empty($document['fisier_stocat'])): ?>
                                            <a class="btn btn-sm btn-outline-dark" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'preview', 'id' => (int) $document['id']])) ?>">Vezi în aplicație</a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'show', 'id' => (int) $document['id']])) ?>">Detalii</a>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'edit', 'id' => (int) $document['id']])) ?>">Editează</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($moduleKey === 'soferi'): ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="h6 mb-1">Documente asociate șoferului</h3>
                <p class="text-muted small mb-0">Vezi documentele încărcate pentru acest șofer și deschide rapid fișierele atașate.</p>
            </div>
            <a class="btn btn-sm btn-primary" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'create', 'driver_id' => (int) $record['id']])) ?>">Adaugă document</a>
        </div>
        <div class="card-body p-0">
            <?php if ($driverDocuments === []): ?>
                <div class="p-3 text-muted">Nu există documente asociate acestui șofer.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Tip document</th>
                            <th>Serie / număr</th>
                            <th>Fișier</th>
                            <th>Expirare</th>
                            <th class="text-end pe-3">Acțiuni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($driverDocuments as $document): ?>
                            <tr>
                                <td><?= e((string) ($document['tip_document'] ?? '-')) ?></td>
                                <td><?= e((string) (($document['numar_document'] ?? '') !== '' ? $document['numar_document'] : '-')) ?></td>
                                <td><?= document_file_link_html((string) ($document['fisier_original'] ?? ''), (string) ($document['fisier_stocat'] ?? '')) ?></td>
                                <td><?= expiry_badge_html((string) ($document['data_expirare'] ?? '')) ?></td>
                                <td class="text-end pe-3">
                                    <div class="d-inline-flex gap-1">
                                        <?php if (!empty($document['fisier_stocat'])): ?>
                                            <a class="btn btn-sm btn-outline-dark" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'preview', 'id' => (int) $document['id']])) ?>">Vezi în aplicație</a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'show', 'id' => (int) $document['id']])) ?>">Detalii</a>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'documente_soferi', 'action' => 'edit', 'id' => (int) $document['id']])) ?>">Editează</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($moduleKey === 'documente' && !empty($documentAuditLogs)): ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white">
            <h3 class="h6 mb-0">Audit log document</h3>
        </div>
        <div class="card-body">
            <div class="list-group list-group-flush">
                <?php foreach ($documentAuditLogs as $auditLog): ?>
                    <div class="list-group-item px-0">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <?= audit_action_badge_html((string) ($auditLog['actiune'] ?? '')) ?>
                                    <span class="fw-semibold"><?= e((string) ($auditLog['descriere'] ?? 'Acțiune fără descriere')) ?></span>
                                </div>
                                <div class="small text-muted">
                                    Utilizator: <?= e((string) ($auditLog['utilizator_nume'] ?? 'Necunoscut')) ?>
                                </div>
                            </div>
                            <small class="text-muted text-nowrap"><?= e(format_datetime_ro((string) ($auditLog['created_at'] ?? ''))) ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
