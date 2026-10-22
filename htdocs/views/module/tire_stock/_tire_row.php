<?php
/**
 * Randul unei anvelope (o inregistrare din `anvelope`) in Stoc anvelope, cu meniul de actiuni si
 * modalele lui (detalii, mutare, editare). Folosit in grupurile extinse (_group_children.php).
 * Bifa nu se trimite direct: selectia se tine dupa id in bara de actiuni (store-ul din list.php).
 *
 * Intrari: $tireRow (TireModel::prepareInventoryRow), $tireRowParent (cheia grupului),
 *          $tireRowSub (cheia subgrupului: locatie / axa) si variabilele din _row_context.php.
 */
$tireRowParent = (string) ($tireRowParent ?? '');
$tireRowSub = (string) ($tireRowSub ?? '');
$tireId = (int) ($tireRow['id'] ?? 0);
$statusMeta = is_array($tireRow['status_meta'] ?? null) ? $tireRow['status_meta'] : ['label' => tire_status_label((string) ($tireRow['status'] ?? '')), 'badge_class' => 'tire-status-badge tire-status-stock'];
$conditionMeta = is_array($tireRow['condition_meta'] ?? null) ? $tireRow['condition_meta'] : ['label' => '-', 'dot_class' => 'tire-dot', 'progress_class' => ''];
$wearPercent = $tireRow['wear_percent'] ?? null;
$wearWidth = $wearPercent !== null ? max(2, min(100, (float) $wearPercent)) : 0;
$detailsModalId = 'tireDetails' . $tireId;
$editModalId = 'tireEdit' . $tireId;
$moveModalId = 'tireMove' . $tireId;
$purchasePriceDisplay = ($tireRow['purchase_price'] ?? null) !== null && $tireRow['purchase_price'] !== ''
    ? number_format((float) $tireRow['purchase_price'], 2, ',', '.') . ' lei'
    : '-';
$invoiceDocumentPath = trim((string) ($tireRow['invoice_document_path'] ?? ''));
$invoiceDocumentName = trim((string) ($tireRow['invoice_document_original_name'] ?? ''));
$invoiceDocumentUrl = $invoiceDocumentPath !== '' ? url('uploads/anvelope_facturi/' . rawurlencode(basename($invoiceDocumentPath))) : '';
$profilePhotoPath = trim((string) ($tireRow['profile_photo_path'] ?? ''));
$profilePhotoName = trim((string) ($tireRow['profile_photo_original_name'] ?? ''));
$profilePhotoUrl = $profilePhotoPath !== '' ? url('uploads/anvelope_profil/' . rawurlencode(basename($profilePhotoPath))) : '';
$locationPhotoPath = trim((string) ($tireRow['location_photo_path'] ?? ''));
$locationPhotoName = trim((string) ($tireRow['location_photo_original_name'] ?? ''));
$locationPhotoUrl = $locationPhotoPath !== '' ? url('uploads/anvelope_locatii/' . rawurlencode(basename($locationPhotoPath))) : '';
?>
<tr class="tire-child-row<?= $tireRowSub !== '' ? ' tire-child-in-sub' : '' ?>" data-tire-child-of="<?= e($tireRowParent) ?>" data-tire-sub="<?= e($tireRowSub) ?>" data-tire-id="<?= e((string) $tireId) ?>">
    <td class="tire-cell-expander"></td>
    <td class="tire-cell-check">
        <?php $isBulkMountable = empty($tireRow['is_mounted']) && in_array((string) ($tireRow['status'] ?? ''), ['in_stock', 'spare', 'retreaded'], true); ?>
        <input type="checkbox" class="form-check-input" value="<?= e((string) $tireId) ?>" data-tire-bulk-item data-mounted="<?= !empty($tireRow['is_mounted']) ? '1' : '0' ?>" data-mountable="<?= $isBulkMountable ? '1' : '0' ?>" data-tire-label="<?= e(trim((string) (($tireRow['brand'] ?? '') . ' ' . ($tireRow['model'] ?? ''))) ?: 'Anvelopa #' . $tireId) ?>" data-tire-size="<?= e((string) ($tireRow['tire_size'] ?? '')) ?>" data-tire-serial="<?= e((string) ($tireRow['serial_number'] ?? '')) ?>" data-tire-status="<?= e((string) ($statusMeta['label'] ?? '')) ?>" data-tire-type="<?= e((string) ($tireRow['tire_type_label'] ?? '')) ?>" aria-label="Selecteaza anvelopa">
    </td>
    <td>
        <div class="d-flex align-items-center gap-3">
            <div class="tire-thumb<?= (string) ($tireRow['status'] ?? '') === 'missing' && $profilePhotoUrl === '' ? ' tire-thumb-missing' : '' ?>">
                <?php if ($profilePhotoUrl !== ''): ?><img src="<?= e($profilePhotoUrl) ?>" alt="<?= e($profilePhotoName !== '' ? $profilePhotoName : 'Poza profil anvelopa') ?>"><?php elseif ((string) ($tireRow['status'] ?? '') === 'missing'): ?><i class="bi bi-question-lg"></i><?php endif; ?>
            </div>
            <div>
                <div class="fw-semibold"><?= e(trim((string) (($tireRow['brand'] ?? '') . ' ' . ($tireRow['model'] ?? ''))) ?: '-') ?></div>
                <div class="small text-muted"><?= e((string) ($tireRow['tire_size'] ?? '-')) ?></div>
                <span class="badge text-bg-light border"><?= e((string) ($tireRow['season_label'] ?? 'All season')) ?></span>
            </div>
        </div>
    </td>
    <td>
        <div class="fw-semibold"><?= e((string) ($tireRow['location_display'] ?? '-')) ?></div>
        <div class="small text-muted"><?= e((string) ($tireRow['position_display'] ?? '-')) ?></div>
        <div class="small text-primary"><?= e((string) ($tireRow['axle_display'] ?? '-')) ?></div>
    </td>
    <td>
        <div class="fw-semibold"><span class="<?= e((string) ($conditionMeta['dot_class'] ?? '')) ?>"></span><?= e((string) ($tireRow['initial_condition_label'] ?? 'Noua')) ?></div>
        <div class="small text-muted"><?= $wearPercent !== null ? e(number_format((float) $wearPercent, 0, ',', '.')) . '% uzura' : 'Uzura necunoscuta' ?></div>
        <div class="tire-progress-track"><span class="<?= e((string) ($conditionMeta['progress_class'] ?? '')) ?>" style="width: <?= e(number_format($wearWidth, 2, '.', '')) ?>%"></span></div>
    </td>
    <td class="fw-semibold"><?= e($formatKm($tireRow['remaining_km'] ?? null)) ?></td>
    <td>
        <?php if (!empty($tireRow['is_mounted'])): ?>
            <div class="fw-semibold"><?= e((string) ($tireRow['nr_inmatriculare'] ?? '-')) ?></div>
            <div class="small text-muted"><?= e(trim((string) (($tireRow['vehicle_marca'] ?? '') . ' ' . ($tireRow['vehicle_model'] ?? ''))) ?: '-') ?></div>
        <?php else: ?>
            <span class="fw-semibold">Nealocat</span>
        <?php endif; ?>
    </td>
    <td>
        <div class="fw-semibold"><?= e((string) ($tireRow['tire_axle_type_label'] ?? '-')) ?></div>
    </td>
    <td>
        <div class="fw-semibold"><?= e((string) ($tireRow['tire_type_label'] ?? '-')) ?></div>
        <div class="small text-muted"><?= e((string) ($tireRow['compatibility_label'] ?? '-')) ?></div>
    </td>
    <td><span class="<?= e((string) ($statusMeta['badge_class'] ?? 'tire-status-badge')) ?>"><?= e((string) ($statusMeta['label'] ?? '-')) ?></span></td>
    <td class="text-end pe-3">
        <div class="dropdown">
            <button class="btn btn-outline-secondary tire-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#<?= e($detailsModalId) ?>"><i class="bi bi-eye me-2"></i>Vezi detalii</button></li>
                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#<?= e($editModalId) ?>"><i class="bi bi-pencil me-2"></i>Editeaza</button></li>
                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#<?= e($moveModalId) ?>"><i class="bi bi-truck me-2"></i>Monteaza pe vehicul</button></li>
                <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#<?= e($moveModalId) ?>"><i class="bi bi-arrow-left-right me-2"></i>Muta intre axe</button></li>
                <?php foreach (['spare' => 'Muta in rezerva', 'damaged' => 'Marcheaza deteriorata', 'missing' => 'Marcheaza lipsa', 'removed' => 'Scoate din uz'] as $statusValue => $statusLabel): ?>
                    <li>
                        <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'change_tire_status'])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="tire_id" value="<?= e((string) $tireId) ?>">
                            <input type="hidden" name="status" value="<?= e($statusValue) ?>">
                            <input type="hidden" name="reason" value="<?= e($statusLabel) ?>">
                            <button class="dropdown-item<?= $statusValue === 'missing' ? ' text-danger' : '' ?>" type="submit"><?= e($statusLabel) ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
                <li><hr class="dropdown-divider"></li>
                <?php if (!empty($tireRow['is_mounted']) && (int) ($tireRow['active_vehicle_id'] ?? 0) > 0): ?>
                    <li>
                        <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'unmount_tire'])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="vehicle_id" value="<?= e((string) ((int) $tireRow['active_vehicle_id'])) ?>">
                            <input type="hidden" name="allocation_id" value="<?= e((string) ((int) ($tireRow['active_allocation_id'] ?? 0))) ?>">
                            <input type="hidden" name="unmount_date" value="<?= e(date('Y-m-d')) ?>">
                            <input type="hidden" name="status_end" value="spare">
                            <input type="hidden" name="return_to" value="tire_stock">
                            <input type="hidden" name="return_query" value="<?= e((string) ($_SERVER['QUERY_STRING'] ?? '')) ?>">
                            <button type="submit" class="dropdown-item text-danger" data-confirm="Sigur doresti demontarea anvelopei de pe <?= e((string) ($tireRow['nr_inmatriculare'] ?? 'vehicul')) ?>?"><i class="bi bi-box-arrow-down me-2"></i>Demonteaza</button>
                        </form>
                    </li>
                <?php endif; ?>
                <li>
                    <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'delete_tire_stock'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="stock_tire_id" value="<?= e((string) $tireId) ?>">
                        <button type="submit" class="dropdown-item text-danger" data-confirm="Sigur vrei sa stergi anvelopa?"><i class="bi bi-trash me-2"></i>Sterge</button>
                    </form>
                </li>
            </ul>
        </div>

        <div class="modal fade text-start" id="<?= e($detailsModalId) ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Detalii anvelopa</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><strong>Serie:</strong> <?= e((string) ($tireRow['serial_number'] ?? '-')) ?></div>
                        <div class="col-md-6"><strong>DOT:</strong> <?= e((string) ($tireRow['dot_code'] ?? '-')) ?></div>
                        <div class="col-md-6"><strong>Furnizor:</strong> <?= e((string) ($tireRow['supplier'] ?? '-')) ?></div>
                        <div class="col-md-6"><strong>Cost:</strong> <?= e($purchasePriceDisplay) ?></div>
                        <div class="col-md-6"><strong>Factura atasata:</strong>
                            <?php if ($invoiceDocumentUrl !== ''): ?>
                                <a href="<?= e($invoiceDocumentUrl) ?>" target="_blank" rel="noopener"><?= e($invoiceDocumentName !== '' ? $invoiceDocumentName : 'Deschide factura') ?></a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6"><strong>Poza profil:</strong>
                            <?php if ($profilePhotoUrl !== ''): ?>
                                <a href="<?= e($profilePhotoUrl) ?>" target="_blank" rel="noopener"><?= e($profilePhotoName !== '' ? $profilePhotoName : 'Deschide poza') ?></a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6"><strong>Poza locatie:</strong>
                            <?php if ($locationPhotoUrl !== ''): ?>
                                <a href="<?= e($locationPhotoUrl) ?>" target="_blank" rel="noopener"><?= e($locationPhotoName !== '' ? $locationPhotoName : 'Deschide poza') ?></a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6"><strong>Km folositi:</strong> <?= e($formatKm($tireRow['used_km'] ?? null)) ?></div>
                        <div class="col-md-6"><strong>Tip axa:</strong> <?= e((string) ($tireRow['tire_axle_type_label'] ?? '-')) ?></div>
                        <div class="col-md-6"><strong>Tip anvelopa:</strong> <?= e((string) ($tireRow['tire_type_label'] ?? '-')) ?></div>
                        <div class="col-md-6"><strong>Compatibilitate:</strong> <?= e((string) ($tireRow['compatibility_label'] ?? '-')) ?></div>
                        <div class="col-md-6"><strong>Tip vehicul:</strong> <?= e((string) ($tireRow['target_vehicle_type_label'] ?? '-')) ?></div>
                        <div class="col-md-6"><strong>Formula axelor:</strong> <?= trim((string) ($tireRow['target_axle_config_label'] ?? '')) !== '' ? e((string) ($tireRow['target_axle_config_label'] ?? '')) : '<span class="text-muted">-</span>' ?></div>
                        <div class="col-12"><strong>Observatii:</strong><br><?= trim((string) ($tireRow['notes'] ?? '')) !== '' ? nl2br(e((string) $tireRow['notes'])) : '<span class="text-muted">-</span>' ?></div>
                    </div>
                </div>
            </div></div>
        </div>

        <div class="modal fade text-start" id="<?= e($moveModalId) ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg"><div class="modal-content">
                <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'move_tire'])) ?>" data-tire-move-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="tire_id" value="<?= e((string) $tireId) ?>">
                    <input type="hidden" name="source_vehicle_id" value="<?= e((string) ((int) ($tireRow['active_vehicle_id'] ?? 0))) ?>">
                    <div class="modal-header"><h5 class="modal-title">Muta anvelopa</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button></div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4"><div class="small text-muted">Vehicul curent</div><div class="fw-semibold"><?= e((string) ($tireRow['vehicle_display'] ?? 'Nealocat')) ?></div></div>
                            <div class="col-md-4"><div class="small text-muted">Axa curenta</div><div class="fw-semibold"><?= e((string) ($tireRow['axle_display'] ?? '-')) ?></div></div>
                            <div class="col-md-4"><div class="small text-muted">Pozitie curenta</div><div class="fw-semibold"><?= e((string) ($tireRow['position_display'] ?? '-')) ?></div></div>
                            <div class="col-12" data-tire-target-picker data-tire-id="<?= e((string) $tireId) ?>" data-tire-mounted="<?= !empty($tireRow['is_mounted']) ? '1' : '0' ?>" data-current-position-id="<?= e((string) ((int) ($tireRow['active_position_id'] ?? 0))) ?>">
                                <input type="hidden" name="target_vehicle_id" value="" data-picker-vehicle-input>
                                <input type="hidden" name="target_position_id" value="" data-picker-position-input>
                            </div>
                            <div class="col-md-4"><label class="form-label">Data</label><input type="date" class="form-control" name="move_date" value="<?= e(date('Y-m-d')) ?>" required></div>
                            <div class="col-md-8"><label class="form-label">Motiv</label><input type="text" class="form-control" name="move_reason" value="Mutare anvelopa"></div>
                            <div class="col-12"><label class="form-label">Observatie</label><textarea class="form-control" name="move_observation" rows="2"></textarea></div>
                            <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="allow_swap" value="1" data-picker-swap> <span class="form-check-label">Schimba pozitiile daca tinta este ocupata</span></label></div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuleaza</button><button type="submit" class="btn btn-primary">Salveaza mutarea</button></div>
                </form>
            </div></div>
        </div>

        <div class="modal fade text-start" id="<?= e($editModalId) ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
                <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'update_tire_stock'])) ?>" data-tire-compatibility-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="stock_tire_id" value="<?= e((string) $tireId) ?>">
                    <div class="modal-header"><h5 class="modal-title">Editeaza anvelopa</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button></div>
                    <div class="modal-body"><div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Brand *</label><input class="form-control" name="stock_edit_brand" value="<?= e((string) ($tireRow['brand'] ?? '')) ?>" required></div>
                        <div class="col-md-4"><label class="form-label">Model</label><input class="form-control" name="stock_edit_model" value="<?= e((string) ($tireRow['model'] ?? '')) ?>"></div>
                        <div class="col-md-4"><label class="form-label">Poza profil</label><input type="file" class="form-control" name="stock_edit_profile_photo_upload" accept=".jpg,.jpeg,.png,.webp"><div class="form-text">JPG, PNG, WEBP. Maxim 5 MB.<?php if ($profilePhotoUrl !== ''): ?> <a href="<?= e($profilePhotoUrl) ?>" target="_blank" rel="noopener">Poza curenta</a><?php endif; ?></div></div>
                        <div class="col-md-4"><label class="form-label">Dimensiune</label><input class="form-control" name="stock_edit_tire_size" value="<?= e((string) ($tireRow['tire_size'] ?? '')) ?>"></div>
                        <?php $currentTireAxleType = (string) ($tireRow['tire_axle_type'] ?? ''); ?>
                        <div class="col-md-4"><label class="form-label">Tip axa *</label><select class="form-select" name="stock_edit_axle_type" data-tire-axle-type required><option value="">Selecteaza tipul axei</option><?php foreach ($tireAxleTypeFormOptions as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= $currentTireAxleType === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-4"><label class="form-label">Tip anvelopa *</label><select class="form-select" name="stock_edit_tire_type" data-tire-type data-current="<?= e((string) ($tireRow['tire_type'] ?? '')) ?>" required><option value="">Selecteaza mai intai tipul axei</option></select></div>
                        <?php
                        $currentTargetVehicleType = (string) ($tireRow['target_vehicle_type'] ?? 'universal');
                        $currentTargetVehicleTypes = is_array($tireRow['target_vehicle_types_values'] ?? null) ? $tireRow['target_vehicle_types_values'] : [$currentTargetVehicleType];
                        $currentTargetVehicleTypeLabel = (string) ($tireRow['target_vehicle_type_label'] ?? $currentTargetVehicleType);
                        $currentTargetAxleConfig = (string) ($tireRow['target_axle_config'] ?? '');
                        ?>
                        <div class="col-md-4"><label class="form-label">Compatibilitate vehicul</label><?php $renderVehicleCompatibilityPicker('stock_edit_target_vehicle_types[]', $currentTargetVehicleTypes); ?><?php if ($currentTargetVehicleType !== 'universal' && !array_key_exists($currentTargetVehicleType, $targetVehicleTypeOptions)): ?><input type="hidden" name="stock_edit_target_vehicle_types[]" value="<?= e($currentTargetVehicleType) ?>"><?php endif; ?></div>
                        <div class="col-md-4"><label class="form-label">Formula axelor</label><select class="form-select" name="stock_edit_target_axle_config" data-tire-axle-config data-current="<?= e($currentTargetAxleConfig) ?>"></select></div>
                        <div class="col-md-4"><label class="form-label">Status</label><select class="form-select" name="stock_edit_status"><?php foreach ($tireStatusOptions as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= (string) ($tireRow['status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-4"><label class="form-label">Conditie</label><select class="form-select" name="stock_edit_initial_condition"><?php foreach ($initialConditionOptions as $value => $label): ?><option value="<?= e((string) $value) ?>" <?= (string) ($tireRow['initial_condition'] ?? 'new') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-4"><label class="form-label">DOT</label><input class="form-control" name="stock_edit_dot_code" value="<?= e((string) ($tireRow['dot_code'] ?? '')) ?>"></div>
                        <div class="col-md-4"><label class="form-label">Km curent</label><input type="number" class="form-control" name="stock_edit_current_mileage" value="<?= e((string) ((int) ($tireRow['current_mileage'] ?? 0))) ?>"></div>
                        <div class="col-md-4"><label class="form-label">Km ramasi</label><input type="number" class="form-control" name="stock_edit_estimated_remaining_km" value="<?= e((string) ($tireRow['estimated_remaining_km'] ?? '')) ?>"></div>
                        <div class="col-md-6"><label class="form-label">Compatibilitate</label><input class="form-control" name="stock_edit_usage_compatibility" value="<?= e((string) ($tireRow['usage_compatibility'] ?? '')) ?>"></div>
                        <div class="col-md-6"><label class="form-label">Locatie</label><input class="form-control" name="stock_edit_location_label" value="<?= e((string) ($tireRow['location_label'] ?? '')) ?>"></div>
                        <div class="col-md-6"><label class="form-label">Poza locatie</label><input type="file" class="form-control" name="stock_edit_location_photo_upload" accept=".jpg,.jpeg,.png,.webp"><div class="form-text">JPG, PNG, WEBP. Maxim 5 MB.<?php if ($locationPhotoUrl !== ''): ?> <a href="<?= e($locationPhotoUrl) ?>" target="_blank" rel="noopener">Poza curenta</a><?php endif; ?></div></div>
                        <div class="col-md-4"><label class="form-label">Cost</label><input class="form-control" name="stock_edit_purchase_price" value="<?= e((string) ($tireRow['purchase_price'] ?? '')) ?>"></div>
                        <div class="col-md-4"><label class="form-label">Furnizor</label><input class="form-control" name="stock_edit_supplier" value="<?= e((string) ($tireRow['supplier'] ?? '')) ?>"></div>
                        <div class="col-md-4"><label class="form-label">Factura (upload optional)</label><input type="file" class="form-control" name="stock_edit_invoice_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"><div class="form-text">PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB.<?php if ($invoiceDocumentUrl !== ''): ?> <a href="<?= e($invoiceDocumentUrl) ?>" target="_blank" rel="noopener">Factura curenta</a><?php endif; ?></div></div>
                        <div class="col-12"><label class="form-label">Observatii</label><textarea class="form-control" name="stock_edit_notes" rows="3"><?= e((string) ($tireRow['notes'] ?? '')) ?></textarea></div>
                    </div></div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuleaza</button><button type="submit" class="btn btn-primary">Salveaza</button></div>
                </form>
            </div></div>
        </div>
    </td>
</tr>
