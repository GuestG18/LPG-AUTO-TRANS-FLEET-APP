<?php
/**
 * Continutul unui rand extins din vederea pe vehicule (pagina Anvelope): tab-urile Detalii anvelope /
 * Detalii vehicul / Istoric / Interventii / Documente. Tabelul are cate un rand per pozitie din
 * configuratie (si anvelopele montate fara pozitie activa), cu meniul si modalele existente ale
 * anvelopei (_tire_actions.php). Randat de ModuleController::tireFleetUnitAction.
 *
 * Intrari: $unit (TireModel::buildFleetTireOverview, un element din units), $history
 *          (TireModel::getFleetAllocationHistory), variabilele cerute de _row_context.php.
 */
include __DIR__ . '/_row_context.php';

$unitKey = (string) $unit['key'];
$isAssembly = count($unit['members']) > 1;
$statusBadges = [
    'ok' => ['OK', 'bi-check-circle-fill'],
    'warn' => ['Atenție', 'bi-exclamation-triangle'],
    'crit' => ['Critic', 'bi-exclamation-circle-fill'],
    'empty' => ['Liberă', 'bi-circle'],
];
$tireDot = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10.5" fill="currentColor"/><circle cx="12" cy="12" r="6.5" fill="#fff"/><circle cx="12" cy="12" r="4" fill="currentColor" opacity=".9"/><g stroke="#fff" stroke-width="1.4"><path d="M12 1.8v3M12 19.2v3M1.8 12h3M19.2 12h3M4.8 4.8l2 2M17.2 17.2l2 2M4.8 19.2l2-2M17.2 6.8l2-2"/></g></svg>';
$formatDate = static function ($value): string {
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000')) {
        return '—';
    }
    $time = strtotime($value);
    return $time === false ? '—' : date('d.m.Y', $time);
};
$formatNumber = static fn ($value): string => $value === null || $value === '' ? '—' : number_format((float) $value, 0, ',', '.');
$wearClass = static fn (string $status): string => in_array($status, ['ok', 'warn', 'crit'], true) ? $status : 'ok';
$tireModalPrefix = 'ftTire';

// Coloanele tabelului (si colspan-ul randurilor de grup).
$columnCount = 14;
$memberIds = array_map(static fn (array $member): int => (int) $member['vehicle_id'], $unit['members']);
$historyClient = array_map(static fn (array $row): array => [
    'tire_id' => (int) ($row['tire_id'] ?? 0),
    'vehicle_id' => (int) ($row['vehicle_id'] ?? 0),
    'plate' => (string) ($row['nr_inmatriculare'] ?? ''),
    'position' => (string) ($row['position_code'] ?? ''),
    'start' => (string) ($row['data_start'] ?? ''),
    'end' => (string) ($row['data_end'] ?? ''),
    'km' => $row['km_end'] !== null ? max(0, (int) $row['km_end'] - (int) ($row['km_start'] ?? 0)) : null,
], $history);

$renderTireRow = static function (array $member, ?array $position, ?array $tireRow = null) use ($statusBadges, $tireDot, $formatDate, $formatKm, $wearClass, $tireModalPrefix, $unit, $tireStatusOptions, $tireAxleTypeFormOptions, $targetVehicleTypeOptions, $renderVehicleCompatibilityPicker, $initialConditionOptions) {
    $status = $position !== null ? (string) $position['status'] : 'warn';
    $tireRow = $tireRow ?? ($position['tire_row'] ?? null);
    $positionId = $position !== null ? (int) $position['id'] : 0;
    $tireId = $tireRow !== null ? (int) $tireRow['id'] : 0;
    $badge = $statusBadges[$status] ?? $statusBadges['empty'];
    $axleKey = $member['vehicle_id'] . '-' . ($position['axle_no'] ?? 0);
    $code = $position !== null ? (string) $position['display_code'] : '—';
    ?>
    <tr class="ft-tire-row" data-ft-position="<?= e((string) $positionId) ?>" data-ft-tire="<?= e((string) $tireId) ?>" data-ft-axle-of="<?= e($axleKey) ?>" data-ft-vehicle="<?= e((string) $member['vehicle_id']) ?>" tabindex="-1">
        <td class="ft-col-check">
            <?php if ($tireRow !== null): ?>
                <input type="checkbox" class="form-check-input" value="<?= e((string) $tireId) ?>" data-tire-bulk-item data-mounted="1" data-mountable="0" data-tire-label="<?= e(trim((string) (($tireRow['brand'] ?? '') . ' ' . ($tireRow['model'] ?? ''))) ?: 'Anvelopa #' . $tireId) ?>" data-tire-size="<?= e((string) ($tireRow['tire_size'] ?? '')) ?>" data-tire-serial="<?= e((string) ($tireRow['serial_number'] ?? '')) ?>" data-tire-status="<?= e((string) ($tireRow['status_meta']['label'] ?? '')) ?>" data-tire-type="<?= e((string) ($tireRow['tire_type_label'] ?? '')) ?>" aria-label="Selectează anvelopa <?= e($code) ?>">
            <?php endif; ?>
        </td>
        <td><span class="ft-tire-dot is-<?= e($tireRow !== null ? $status : 'empty') ?>"><?= $tireDot ?></span></td>
        <td class="ft-pos"><?= e($code) ?></td>
        <td><?= $position !== null ? e('Axă ' . $position['axle_no'] . ' – ' . $position['axle_label']) : '—' ?></td>
        <td><?= $position !== null ? e((string) $position['side_label']) : '—' ?></td>
        <?php if ($tireRow === null): ?>
            <td colspan="7" class="text-muted">
                <?php if ($positionId > 0): ?>
                    Poziție liberă — nicio anvelopă montată
                <?php else: ?>
                    Poziție nesincronizată — deschide Configurație Axe pentru a activa montajul
                <?php endif; ?>
            </td>
            <td><span class="ft-badge ft-badge-empty"><i class="bi bi-circle" aria-hidden="true"></i>Liberă</span></td>
            <td class="text-end">
                <?php if ($positionId > 0): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary py-0" data-ft-mount="<?= e((string) $positionId) ?>"><i class="bi bi-plus-lg me-1"></i>Montează</button>
                <?php endif; ?>
            </td>
        <?php else: ?>
            <?php
            $wear = $tireRow['wear_percent'] ?? null;
            $tread = is_numeric((string) ($tireRow['tread_depth_mm'] ?? null)) ? (float) $tireRow['tread_depth_mm'] : null;
            $numClass = 'ft-num-' . $wearClass($status);
            $editModalId = $tireModalPrefix . 'Edit' . $tireId;
            ?>
            <td>
                <div class="fw-semibold"><?= e(trim((string) (($tireRow['brand'] ?? '') . ' ' . ($tireRow['model'] ?? ''))) ?: '—') ?></div>
                <?php if ((string) ($tireRow['serial_number'] ?? '') !== ''): ?><div class="small text-muted"><?= e((string) $tireRow['serial_number']) ?><?= $position === null ? ' · fără poziție activă în schemă' : '' ?></div><?php endif; ?>
            </td>
            <td><?= e((string) ($tireRow['tire_size'] ?? '') ?: '—') ?></td>
            <td class="<?= $tread !== null ? e($numClass) : 'text-muted' ?>" title="Adâncimea profilului (din fișa anvelopei)"><?= $tread !== null ? e(number_format($tread, 1, ',', '.') . ' mm') : '—' ?></td>
            <td>
                <?php if ($wear !== null): ?>
                    <div class="ft-wear-cell"><span class="<?= e($numClass) ?>"><?= e(number_format((float) $wear, 0, ',', '.')) ?>%</span><span class="ft-bar" aria-hidden="true"><span class="is-<?= e($wearClass($status)) ?>" style="width: <?= e(number_format(max(2.0, min(100.0, (float) $wear)), 1, '.', '')) ?>%"></span></span></div>
                <?php else: ?>
                    <span class="text-muted" title="Anvelopa nu are durată estimată (km)">Necunoscută</span>
                <?php endif; ?>
            </td>
            <td><?= e($formatKm($tireRow['remaining_km'] ?? null)) ?></td>
            <td><?= e($formatDate($tireRow['active_data_start'] ?? null)) ?></td>
            <td title="Km totali ai anvelopei: km la introducere + montări anterioare + montarea curentă"><?= e($formatKm($tireRow['used_km'] ?? null)) ?></td>
            <td><span class="ft-badge ft-badge-<?= e($status) ?>"><i class="bi <?= e($badge[1]) ?>" aria-hidden="true"></i><?= e($badge[0]) ?></span></td>
            <td class="text-end">
                <div class="ft-row-actions">
                    <button type="button" class="ft-row-btn" data-bs-toggle="modal" data-bs-target="#<?= e($editModalId) ?>" title="Editează anvelopa" aria-label="Editează anvelopa <?= e($code) ?>"><i class="bi bi-pencil"></i></button>
                    <?php $tireActionAnchor = $positionId > 0 ? '#anvelopa-p' . $positionId : '#vehicul-' . $unit['key']; include __DIR__ . '/_tire_actions.php'; ?>
                </div>
            </td>
        <?php endif; ?>
    </tr>
    <?php
};
?>
<div class="ft-tabs" role="tablist" aria-label="Detalii <?= e((string) $unit['plate']) ?>">
    <button type="button" class="ft-tab is-active" role="tab" aria-selected="true" data-ft-tab="tires"><i class="bi bi-life-preserver" aria-hidden="true"></i>Detalii anvelope</button>
    <button type="button" class="ft-tab" role="tab" aria-selected="false" data-ft-tab="vehicle"><i class="bi bi-truck" aria-hidden="true"></i>Detalii vehicul</button>
    <button type="button" class="ft-tab" role="tab" aria-selected="false" data-ft-tab="history"><i class="bi bi-clock-history" aria-hidden="true"></i>Istoric</button>
    <button type="button" class="ft-tab" role="tab" aria-selected="false" data-ft-tab="interventions"><i class="bi bi-tools" aria-hidden="true"></i>Intervenții</button>
    <button type="button" class="ft-tab" role="tab" aria-selected="false" data-ft-tab="documents"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>Documente</button>
</div>

<div class="ft-panel" role="tabpanel" data-ft-panel="tires">
    <div class="ft-selected m-2" data-ft-selected hidden></div>
    <div class="table-responsive">
        <table class="table ft-tire-table align-middle">
            <thead>
            <tr>
                <th class="ft-col-check">
                    <?php
                    $unitMembersMeta = [];
                    foreach ($unit['members'] as $member) {
                        foreach ($member['positions'] as $position) {
                            if ($position['tire_row'] !== null) {
                                $unitMembersMeta[] = $tireModelSelectionMeta($position['tire_row']);
                            }
                        }
                    }
                    ?>
                    <input type="checkbox" class="form-check-input" data-tire-group-select data-members="<?= e(json_encode($unitMembersMeta, JSON_UNESCAPED_UNICODE)) ?>" aria-label="Selectează toate anvelopele vehiculului" <?= $unitMembersMeta === [] ? 'disabled' : '' ?>>
                </th>
                <th aria-label="Stare"></th>
                <th>Poz.</th>
                <th>Axă</th>
                <th>Parte</th>
                <th>Anvelopă (model)</th>
                <th>Dimensiune</th>
                <th>Profil</th>
                <th>Uzură</th>
                <th>Km rămași</th>
                <th>Montată la</th>
                <th>Km parcurși</th>
                <th>Status</th>
                <th class="text-end">Acțiuni</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($unit['members'] as $member): ?>
                <?php if ($isAssembly): ?>
                    <tr class="ft-member-row"><td colspan="<?= $columnCount ?>"><i class="bi <?= $member['role'] === 'tractor' ? 'bi-truck-front' : 'bi-box' ?> me-2" aria-hidden="true"></i><?= e($member['role_label'] . ' · ' . $member['plate']) ?><?= $member['make'] !== '' ? ' <span class="fw-normal text-muted">· ' . e($member['make']) . '</span>' : '' ?></td></tr>
                <?php endif; ?>
                <?php
                $byAxle = [];
                foreach ($member['positions'] as $position) {
                    $byAxle[(int) $position['axle_no']][] = $position;
                }
                ?>
                <?php foreach ($byAxle as $axleNo => $axlePositions): ?>
                    <?php
                    $axleKey = $member['vehicle_id'] . '-' . $axleNo;
                    $axleMeta = [];
                    foreach ($axlePositions as $position) {
                        if ($position['tire_row'] !== null) {
                            $axleMeta[] = $tireModelSelectionMeta($position['tire_row']);
                        }
                    }
                    $axleMounted = count($axleMeta);
                    ?>
                    <tr class="ft-axle-row">
                        <td class="ft-col-check"><input type="checkbox" class="form-check-input" data-tire-group-select data-members="<?= e(json_encode($axleMeta, JSON_UNESCAPED_UNICODE)) ?>" aria-label="Selectează anvelopele axei <?= e((string) $axleNo) ?>" <?= $axleMeta === [] ? 'disabled' : '' ?>></td>
                        <td colspan="<?= $columnCount - 1 ?>">
                            <button type="button" class="ft-axle-toggle" data-ft-axle-toggle="<?= e($axleKey) ?>" aria-expanded="true"><i class="bi bi-chevron-down me-1" aria-hidden="true"></i>Axa <?= e((string) $axleNo) ?> — <?= e((string) $axlePositions[0]['axle_label']) ?></button>
                            <span class="ms-2"><?= e($axleMounted . ' / ' . count($axlePositions)) ?> montate<?= $axlePositions[0]['wheel_kind'] === 'dual' ? ' · roți duble' : '' ?></span>
                        </td>
                    </tr>
                    <?php foreach ($axlePositions as $position) { $renderTireRow($member, $position); } ?>
                <?php endforeach; ?>
                <?php foreach ($member['orphan_tires'] as $orphanRow) { $renderTireRow($member, null, $orphanRow); } ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="ft-panel ft-panel-pad" role="tabpanel" data-ft-panel="vehicle" hidden>
    <?php foreach ($unit['members'] as $member): ?>
        <div class="ft-member-block">
            <div class="fw-bold mb-2"><?= e($member['role_label'] . ' · ' . $member['plate']) ?></div>
            <div class="ft-info-grid">
                <div><span>Număr înmatriculare</span><strong><?= e((string) $member['plate']) ?></strong></div>
                <div><span>Tip vehicul</span><strong><?= e((string) $member['vehicle_type_label']) ?></strong></div>
                <div><span>Marcă / model</span><strong><?= e($member['make'] !== '' ? $member['make'] : '—') ?></strong></div>
                <div><span>Formula axelor</span><strong><?= e($member['layout'] !== '' ? $member['layout'] : '—') ?></strong></div>
                <div><span>Număr axe</span><strong><?= e((string) $member['axle_count']) ?></strong></div>
                <div><span>Poziții anvelope</span><strong><?= e((string) count($member['positions'])) ?></strong></div>
                <div><span>Km bord (orientativ)</span><strong><?= $member['km_bord'] !== null ? e($formatNumber($member['km_bord']) . ' km') : '—' ?></strong></div>
                <div><span>Poziții sincronizate</span><strong><?= $member['configured'] ? 'Da' : 'Nu — din Configurație Axe' ?></strong></div>
            </div>
            <div class="ft-link-list">
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) $member['vehicle_id']])) ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Fișa vehiculului</a>
                <?php if (in_array($unit['kind'], ['camion', 'ansamblu'], true)): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'axis_config', 'vehicle_view' => $unit['kind'] === 'ansamblu' ? 'ansamblu' : 'camion', 'vehicle_id' => (int) $member['vehicle_id']])) ?>"><i class="bi bi-diagram-3 me-1"></i>Configurație axe</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="ft-panel" role="tabpanel" data-ft-panel="history" hidden>
    <?php if ($history === []): ?>
        <div class="ft-panel-pad text-muted">Nu există montări înregistrate pentru acest vehicul.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table ft-tire-table align-middle">
                <thead><tr><th>Montată la</th><th>Demontată la</th><th>Vehicul</th><th>Poziție</th><th>Anvelopă</th><th>Serie</th><th class="text-end">Km start</th><th class="text-end">Km final</th><th class="text-end">Km parcurși</th><th>Status la demontare</th></tr></thead>
                <tbody>
                <?php foreach ($history as $historyRow): ?>
                    <?php $onUnit = in_array((int) ($historyRow['vehicle_id'] ?? 0), $memberIds, true); ?>
                    <tr<?= $onUnit ? '' : ' class="text-muted"' ?>>
                        <td><?= e($formatDate($historyRow['data_start'] ?? null)) ?></td>
                        <td><?= ($historyRow['data_end'] ?? null) === null ? '<span class="ft-badge ft-badge-ok">Montată</span>' : e($formatDate($historyRow['data_end'])) ?></td>
                        <td><?= e((string) ($historyRow['nr_inmatriculare'] ?? '—')) ?><?= $onUnit ? '' : ' <span class="small">(alt vehicul)</span>' ?></td>
                        <td class="fw-semibold"><?= e((string) ($historyRow['position_code'] ?? '—')) ?></td>
                        <td><?= e(trim((string) (($historyRow['brand'] ?? '') . ' ' . ($historyRow['model'] ?? ''))) ?: '—') ?></td>
                        <td><?= e((string) ($historyRow['serial_number'] ?? '—')) ?></td>
                        <td class="text-end"><?= e($formatNumber($historyRow['km_start'] ?? null)) ?></td>
                        <td class="text-end"><?= e($formatNumber($historyRow['km_end'] ?? null)) ?></td>
                        <td class="text-end"><?= ($historyRow['km_end'] ?? null) !== null ? e($formatNumber(max(0, (int) $historyRow['km_end'] - (int) ($historyRow['km_start'] ?? 0)))) : '—' ?></td>
                        <td><?= ($historyRow['status_end'] ?? null) !== null ? e(tire_status_label((string) $historyRow['status_end'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="ft-panel ft-panel-pad" role="tabpanel" data-ft-panel="interventions" hidden>
    <p class="mb-2 text-muted">Intervențiile (inclusiv cele generate de montajele de anvelope) se gestionează în modulul Intervenții, filtrate pe vehicul.</p>
    <div class="ft-link-list mt-0">
        <?php foreach ($unit['members'] as $member): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'interventions', 'vehicle_id' => (int) $member['vehicle_id']])) ?>"><i class="bi bi-tools me-1"></i>Intervenții <?= e((string) $member['plate']) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="ft-panel ft-panel-pad" role="tabpanel" data-ft-panel="documents" hidden>
    <p class="mb-2 text-muted">Documentele vehiculelor (ITP, asigurări, facturi) sunt în modulul Documente.</p>
    <div class="ft-link-list mt-0">
        <?php foreach ($unit['members'] as $member): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'documente', 'vehicle_id' => (int) $member['vehicle_id']])) ?>"><i class="bi bi-file-earmark-text me-1"></i>Documente <?= e((string) $member['plate']) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<script type="application/json" data-ft-history><?= json_encode($historyClient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
