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
$profilePhotoPath = trim((string) ($tireRow['profile_photo_path'] ?? ''));
$profilePhotoName = trim((string) ($tireRow['profile_photo_original_name'] ?? ''));
$profilePhotoUrl = $profilePhotoPath !== '' ? url('uploads/anvelope_profil/' . rawurlencode(basename($profilePhotoPath))) : '';
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
        <?php include __DIR__ . '/_tire_actions.php'; ?>
    </td>
</tr>
