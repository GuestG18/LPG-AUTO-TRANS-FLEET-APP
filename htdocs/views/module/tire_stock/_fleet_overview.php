<?php
/**
 * Pagina Anvelope, vederea pe vehicule: indicatori, bara de filtre si cate un rand per vehicul /
 * ansamblu, cu schema anvelopelor (desenata de assets/js/tire-fleet.js din datele randului).
 * Detaliile randului (tab-uri + tabelul anvelopelor cu actiunile existente) se incarca la extindere
 * din action=tire_fleet_unit (_fleet_unit_detail.php).
 *
 * Intrari: $fleetTireOverview (TireModel::buildFleetTireOverview) sau null daca n-a putut fi citit.
 */
$fleetUnits = is_array($fleetTireOverview['units'] ?? null) ? $fleetTireOverview['units'] : [];
$fleetKpi = is_array($fleetTireOverview['kpi'] ?? null) ? $fleetTireOverview['kpi'] : [];
$fleetKindOptions = is_array($fleetTireOverview['kind_options'] ?? null) ? $fleetTireOverview['kind_options'] : [];
$fleetStatusCounts = ['ok' => 0, 'warn' => 0, 'crit' => 0, 'none' => 0];
foreach ($fleetUnits as $fleetUnit) {
    $fleetStatusCounts[$fleetUnit['status']] = ($fleetStatusCounts[$fleetUnit['status']] ?? 0) + 1;
}
$fleetTotalTires = (int) ($fleetKpi['total_tires'] ?? 0);
$fleetMountedTires = (int) ($fleetKpi['mounted_tires'] ?? 0);
$fleetMountedShare = $fleetTotalTires > 0 ? number_format($fleetMountedTires / $fleetTotalTires * 100, 1, ',', '.') : '0';
$fleetStatusMeta = [
    'ok' => ['label' => 'OK', 'icon' => 'bi-check-circle-fill'],
    'warn' => ['label' => 'Atenție', 'icon' => 'bi-exclamation-triangle'],
    'crit' => ['label' => 'Critic', 'icon' => 'bi-exclamation-circle-fill'],
    'none' => ['label' => 'Fără anvelope', 'icon' => 'bi-dash-circle'],
];
$fleetUnitData = is_array($fleetTireOverview['client_units'] ?? null) ? $fleetTireOverview['client_units'] : [];
$fleetTireIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor"/><circle cx="12" cy="12" r="6.2" fill="#fff" opacity=".9"/><circle cx="12" cy="12" r="3.2" fill="currentColor"/><g stroke="#fff" stroke-width="1.2" opacity=".75"><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/></g></svg>';
// Pictograme laterale mici (doar decor; tipul e scris si in text).
// Roata conturata (icon-urile noi): anvelopa albă cu contur navy, butuc plin.
$fleetIconWheel = static fn (float $x, float $r): string => '<circle cx="' . $x . '" cy="' . (35.6 - $r) . '" r="' . $r . '" fill="#fff"/><circle cx="' . $x . '" cy="' . (35.6 - $r) . '" r="' . round($r * 0.38, 2) . '" fill="currentColor" stroke="none"/>';
// Cap tractor fără semiremorcă: cabină, șasiu scurt, toba verticală, șaua de cuplare (placă în
// perspectivă cu fanta în V spre spate și locașul pivotului), axa de direcție + axa motoare.
$fleetTractorIcon = '<svg viewBox="0 0 64 40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><rect x="22" y="24" width="38" height="3.6" rx="1" fill="#fff"/><path d="M27 8.5V24" stroke-width="2.1"/><path d="M4 29V14.2c0-1.8 1-3.4 2.7-4.1L15.5 6.5H24V29z" fill="#fff"/><path d="M6.8 14.6l2.9-5.4H21v5.4z" fill="currentColor" stroke="none"/><path d="M2.8 26.4H7.6" stroke-width="2.2"/><path d="M37.6 21.6l1.5 2.4M49.4 21.6l-1.5 2.4"/><ellipse cx="43.5" cy="20.9" rx="9.6" ry="3.4" fill="#fff"/><ellipse cx="43.5" cy="19.4" rx="9.6" ry="3.4" fill="#b7d0ff"/><path d="M53.1 18.2L45 19.1v.6l8.1.9" fill="#fff"/><circle cx="44.6" cy="19.4" r="1.3" fill="currentColor" stroke="none"/>' . $fleetIconWheel(14, 5.2) . $fleetIconWheel(45.5, 5.2) . '</svg>';
// Semiremorcă singură: caroserie, pivotul (kingpin) cu placa lui în față, picioarele de sprijin, 3 axe.
$fleetTrailerIcon = '<svg viewBox="0 0 64 40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><rect x="3" y="6.5" width="58" height="16.5" rx="1.6" fill="#fff"/><path d="M17 9.5v10.5M31 9.5v10.5M45 9.5v10.5" opacity=".35"/><rect x="3" y="23" width="58" height="3" rx="1" fill="#fff"/><rect x="5.5" y="26" width="10" height="1.8" rx=".7" fill="#cfe0ff"/><path d="M10.5 27.8v2.8" stroke-width="2.4"/><path d="M23 26v6.8M20.6 32.8h4.8"/>' . $fleetIconWheel(41.5, 3.9) . $fleetIconWheel(49.5, 3.9) . $fleetIconWheel(57.5, 3.9) . '</svg>';
$fleetVehicleIcon = static function (string $kind) use ($fleetIconWheel, $fleetTractorIcon, $fleetTrailerIcon): string {
    $wheel = static fn (float $x): string => '<circle cx="' . $x . '" cy="33" r="4.6" fill="#1f2937"/><circle cx="' . $x . '" cy="33" r="1.9" fill="#d0d5dd"/>';
    return match ($kind) {
        // Cap tractor / semiremorcă / ansamblu: contur navy (currentColor), șaua de cuplare în albastru deschis.
        'ansamblu' => '<svg viewBox="0 0 64 40" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" aria-hidden="true"><rect x="14" y="6" width="48" height="15.5" rx="1.4" fill="#fff"/><path d="M26 8.8v9.8M38 8.8v9.8M50 8.8v9.8" opacity=".35"/><rect x="14" y="21.5" width="48" height="2.6" rx=".9" fill="#fff"/><path d="M33.5 24.1v5.2M31.6 29.3h3.8"/><rect x="15" y="25.4" width="16" height="2.8" rx=".8" fill="#fff"/><ellipse cx="22.5" cy="24.6" rx="5.6" ry="1.4" fill="#cfe0ff"/><path d="M2.5 29V16c0-1.5.8-2.8 2.2-3.4L10.5 10H15v19z" fill="#fff"/><path d="M4.6 16.3l2.3-4.4H13v4.4z" fill="currentColor" stroke="none"/>' . $fleetIconWheel(8.5, 3.9) . $fleetIconWheel(24.5, 3.9) . $fleetIconWheel(44, 3.4) . $fleetIconWheel(51, 3.4) . $fleetIconWheel(58, 3.4) . '</svg>',
        'cap_tractor' => $fleetTractorIcon,
        'semiremorca' => $fleetTrailerIcon,
        'camion' => '<svg viewBox="0 0 64 40" aria-hidden="true"><rect x="22" y="6" width="39" height="22" rx="1.5" fill="#f9fafb" stroke="#475467" stroke-width="1.4"/><path d="M3 29V15c0-1.7 1-3.3 2.7-3.9L12 9h7.5v20z" fill="#fff" stroke="#344054" stroke-width="1.4"/><path d="M5.3 15l2.7-3.3h8v5.6H5.3z" fill="#344054"/><rect x="3" y="27.5" width="58" height="2.5" fill="#344054"/>' . $wheel(11) . $wheel(46) . $wheel(54) . '</svg>',
        default => '<svg viewBox="0 0 64 40" aria-hidden="true"><path d="M5 29v-7.5c0-1.4.8-2.6 2.1-3.1L16 15l7-6h21c1.6 0 3 .8 3.8 2.2L52 18l5 1.2c1.2.3 2 1.4 2 2.6V29z" fill="#fff" stroke="#344054" stroke-width="1.4"/><path d="M19 15.5l5.4-4.7h8.2v4.7zM35 10.8h8.4c.9 0 1.7.5 2.1 1.2l2 3.5H35z" fill="#344054"/>' . $wheel(15) . $wheel(48) . '</svg>',
    };
};
$fleetFormatPercent = static fn ($value): string => $value === null ? '' : number_format((float) $value, 0, ',', '.') . '%';
?>
<link rel="stylesheet" href="<?= e(url('assets/css/tire-fleet.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/tire-fleet.css'))) ?>">

<div class="ft-kpis" aria-label="Indicatori anvelope">
    <div class="ft-kpi ft-kpi-blue" title="Vehiculele active din flotă, cu pozițiile de anvelope din configurația axelor">
        <div class="ft-kpi-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="6" rx="8" ry="3" fill="currentColor"/><path d="M4 6v4c0 1.7 3.6 3 8 3s8-1.3 8-3V6c0 1.7-3.6 3-8 3S4 7.7 4 6z" fill="currentColor" opacity=".75"/><path d="M4 11v4c0 1.7 3.6 3 8 3s8-1.3 8-3v-4c0 1.7-3.6 3-8 3s-8-1.3-8-3z" fill="currentColor" opacity=".55"/><path d="M4 16v2c0 1.7 3.6 3 8 3s8-1.3 8-3v-2c0 1.7-3.6 3-8 3s-8-1.3-8-3z" fill="currentColor" opacity=".4"/></svg></div>
        <div><div class="ft-kpi-main"><span class="ft-kpi-value"><?= e((string) ((int) ($fleetKpi['vehicles'] ?? 0))) ?></span><span class="ft-kpi-label">Vehicule</span></div><div class="ft-kpi-sub">cu anvelope gestionate</div></div>
    </div>
    <div class="ft-kpi ft-kpi-green" title="<?= e($fleetMountedTires . ' anvelope montate din ' . $fleetTotalTires . ' înregistrate · ' . (int) ($fleetKpi['free_positions'] ?? 0) . ' poziții libere din ' . (int) ($fleetKpi['expected_positions'] ?? 0)) ?>">
        <div class="ft-kpi-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10.5" fill="currentColor"/><circle cx="12" cy="12" r="6.8" fill="#fff"/><circle cx="12" cy="12" r="4.4" fill="currentColor" opacity=".85"/><circle cx="12" cy="12" r="1.6" fill="#fff"/><g stroke="#fff" stroke-width="1.3"><path d="M12 1.8v2.6M12 19.6v2.6M1.8 12h2.6M19.6 12h2.6M4.8 4.8l1.8 1.8M17.4 17.4l1.8 1.8M4.8 19.2l1.8-1.8M17.4 6.6l1.8-1.8"/></g></svg></div>
        <div><div class="ft-kpi-main"><span class="ft-kpi-value"><?= e((string) $fleetMountedTires) ?></span><span class="ft-kpi-label">Montate</span></div><div class="ft-kpi-sub"><?= e($fleetMountedShare) ?>% din total</div></div>
    </div>
    <div class="ft-kpi ft-kpi-yellow" title="Anvelope cu uzură acceptabilă (galben), încă sub pragul de înlocuire">
        <div class="ft-kpi-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
        <div><div class="ft-kpi-main"><span class="ft-kpi-value"><?= e((string) ((int) ($fleetKpi['warn_tires'] ?? 0))) ?></span><span class="ft-kpi-label">Uzură</span></div><div class="ft-kpi-sub">sub prag</div></div>
    </div>
    <div class="ft-kpi ft-kpi-red" title="Aceeași regulă ca „Înlocuire necesară”: uzură mare / critică, deteriorate, lipsă sau casate">
        <div class="ft-kpi-icon"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></div>
        <div><div class="ft-kpi-main"><span class="ft-kpi-value"><?= e((string) ((int) ($fleetKpi['crit_tires'] ?? 0))) ?></span><span class="ft-kpi-label">Critice</span></div><div class="ft-kpi-sub">necesită înlocuire</div></div>
    </div>
    <button class="btn btn-primary ft-add-btn" type="button" data-bs-toggle="modal" data-bs-target="#addTireModal"><i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă anvelopă</button>
</div>

<div data-ft-pane="vehicule"<?= $tireFleetMode === 'vehicule' ? '' : ' hidden' ?>>
<?php if ($fleetTireOverview === null): ?>
    <div class="alert alert-warning">Vederea pe vehicule nu a putut fi încărcată. Inventarul anvelopelor rămâne disponibil în tab-ul „Inventar anvelope”.</div>
<?php else: ?>
    <div class="ft-toolbar" role="search" aria-label="Filtre vehicule">
        <div class="ft-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label class="visually-hidden" for="ftSearch">Caută vehicul</label>
            <input type="search" class="form-control" id="ftSearch" placeholder="Caută vehicul (nr., marcă, tip)..." autocomplete="off" data-ft-search>
            <button type="button" class="ft-search-clear" data-ft-search-clear aria-label="Șterge căutarea" hidden><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="ft-chips" role="group" aria-label="Status vehicul">
            <button type="button" class="ft-chip is-active" data-ft-status="" aria-pressed="true"><span>Toate (<span data-ft-count="all"><?= e((string) count($fleetUnits)) ?></span>)</span></button>
            <button type="button" class="ft-chip" data-ft-status="ok" aria-pressed="false"><span class="ft-chip-dot is-ok"></span><span>OK (<span data-ft-count="ok"><?= e((string) $fleetStatusCounts['ok']) ?></span>)</span></button>
            <button type="button" class="ft-chip" data-ft-status="warn" aria-pressed="false"><span class="ft-chip-dot is-warn"></span><span>Atenție (<span data-ft-count="warn"><?= e((string) $fleetStatusCounts['warn']) ?></span>)</span></button>
            <button type="button" class="ft-chip" data-ft-status="crit" aria-pressed="false"><span class="ft-chip-dot is-crit"></span><span>Critic (<span data-ft-count="crit"><?= e((string) $fleetStatusCounts['crit']) ?></span>)</span></button>
        </div>
        <div class="ft-field">
            <label for="ftKind">Tip vehicul</label>
            <select class="form-select" id="ftKind" data-ft-kind>
                <option value="">Toate</option>
                <?php foreach ($fleetKindOptions as $kindValue => $kindLabel): ?>
                    <option value="<?= e((string) $kindValue) ?>"><?= e((string) $kindLabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ft-field">
            <label for="ftFill">Stare</label>
            <select class="form-select" id="ftFill" data-ft-fill>
                <option value="">Toate</option>
                <option value="full">Complet echipate</option>
                <option value="partial">Cu poziții libere</option>
                <option value="none">Fără anvelope</option>
            </select>
        </div>
        <button type="button" class="btn ft-reset" data-ft-reset disabled><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Reset filtre</button>
        <div class="ft-view-toggle" role="group" aria-label="Mod afișare">
            <button type="button" class="is-active" data-ft-layout="list" aria-pressed="true" title="Listă"><i class="bi bi-list-ul" aria-hidden="true"></i><span class="visually-hidden">Listă</span></button>
            <button type="button" data-ft-layout="grid" aria-pressed="false" title="Grilă"><i class="bi bi-grid" aria-hidden="true"></i><span class="visually-hidden">Grilă</span></button>
        </div>
    </div>

    <div class="ft-board" data-ft-board>
        <div class="ft-head ft-grid-cols" aria-hidden="true">
            <div></div>
            <div>Vehicul</div>
            <div>Configurație</div>
            <div>Schema anvelope (cap tractor + semiremorcă)</div>
            <div>Montate / Total</div>
            <div>Uzură medie</div>
            <div>Status</div>
            <div>Acțiuni</div>
        </div>
        <div class="ft-list" data-ft-list>
            <?php foreach ($fleetUnits as $fleetUnit): ?>
                <?php
                $unitKey = (string) $fleetUnit['key'];
                $unitStatus = (string) $fleetUnit['status'];
                $unitMeta = $fleetStatusMeta[$unitStatus] ?? $fleetStatusMeta['none'];
                $unitFill = $fleetUnit['mounted_positions'] === 0 ? 'none' : ($fleetUnit['mounted_positions'] < $fleetUnit['expected'] ? 'partial' : 'full');
                $firstMember = $fleetUnit['members'][0];
                $partner = $fleetUnit['members'][1] ?? null;
                $mainVehicleId = (int) $firstMember['vehicle_id'];
                $configUrl = in_array($fleetUnit['kind'], ['camion', 'ansamblu'], true)
                    ? build_query_url(['page' => 'mentenanta', 'action' => 'axis_config', 'vehicle_view' => $fleetUnit['kind'] === 'ansamblu' ? 'ansamblu' : 'camion', 'vehicle_id' => $mainVehicleId])
                    : build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => $mainVehicleId]);
                $detailId = 'ftDetail' . str_replace('-', '_', $unitKey);
                ?>
                <article class="ft-unit" id="vehicul-<?= e($unitKey) ?>" data-ft-unit="<?= e($unitKey) ?>" data-kind="<?= e((string) $fleetUnit['kind']) ?>" data-status="<?= e($unitStatus) ?>" data-fill="<?= e($unitFill) ?>" data-search="<?= e((string) $fleetUnit['search']) ?>">
                    <div class="ft-unit-row ft-grid-cols">
                        <button type="button" class="ft-chevron" data-ft-toggle aria-expanded="false" aria-controls="<?= e($detailId) ?>" aria-label="Detalii anvelope <?= e((string) $fleetUnit['plate']) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                        <div class="ft-vehicle">
                            <span class="ft-vehicle-icon"><?= $fleetVehicleIcon((string) $fleetUnit['kind']) ?></span>
                            <div class="min-w-0">
                                <div class="ft-plate"><?= e((string) $fleetUnit['plate']) ?><?php if ($partner !== null): ?><span class="ft-plate-partner">+ <?= e((string) $partner['plate']) ?></span><?php endif; ?></div>
                                <?php if ($fleetUnit['kind'] === 'ansamblu'): ?>
                                    <div class="ft-sub"><strong>Ansamblu</strong> (cap + semiremorcă)</div>
                                    <div class="ft-sub"><?= e(trim($firstMember['make'] . ($partner !== null && $partner['make'] !== '' ? ' + ' . $partner['make'] : '')) ?: '-') ?></div>
                                <?php else: ?>
                                    <div class="ft-sub"><?= e((string) $fleetUnit['kind_label']) ?><?= $firstMember['make'] !== '' ? ' &nbsp;•&nbsp; ' . e($firstMember['make']) : '' ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="ft-config"><span class="ft-config-badge" title="Configurația axelor"><?= e((string) $fleetUnit['config_label']) ?></span></div>
                        <div class="ft-schema" data-ft-schema aria-label="Schema anvelopelor <?= e((string) $fleetUnit['plate']) ?>"></div>
                        <div class="ft-count"><span class="ft-count-pill<?= $unitFill === 'partial' ? ' is-partial' : ($unitFill === 'none' ? ' is-none' : '') ?>" title="Poziții ocupate / poziții din configurație"><?= $fleetTireIcon ?> <?= e($fleetUnit['mounted_positions'] . ' / ' . $fleetUnit['expected']) ?></span></div>
                        <div class="ft-wear"><?= $fleetUnit['wear_avg'] !== null ? e($fleetFormatPercent($fleetUnit['wear_avg'])) : '<span class="ft-muted" title="Nicio anvelopă cu durată estimată">—</span>' ?></div>
                        <div class="ft-status-cell"><span class="ft-status ft-status-<?= e($unitStatus) ?>"><i class="bi <?= e($unitMeta['icon']) ?>" aria-hidden="true"></i><?= e($unitMeta['label']) ?></span></div>
                        <div class="ft-actions">
                            <button type="button" class="ft-icon-btn" data-ft-view title="Vezi anvelopele" aria-label="Vezi anvelopele <?= e((string) $fleetUnit['plate']) ?>"><i class="bi bi-eye"></i></button>
                            <a class="ft-icon-btn" href="<?= e($configUrl) ?>" title="<?= in_array($fleetUnit['kind'], ['camion', 'ansamblu'], true) ? 'Configurație axe' : 'Fișa vehiculului' ?>" aria-label="Editează configurația <?= e((string) $fleetUnit['plate']) ?>"><i class="bi bi-pencil"></i></a>
                            <div class="dropdown">
                                <button type="button" class="ft-icon-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Alte acțiuni <?= e((string) $fleetUnit['plate']) ?>"><i class="bi bi-three-dots"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <?php foreach ($fleetUnit['members'] as $member): ?>
                                        <?php if (count($fleetUnit['members']) > 1): ?><li><h6 class="dropdown-header"><?= e($member['role_label'] . ' · ' . $member['plate']) ?></h6></li><?php endif; ?>
                                        <li><a class="dropdown-item" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) $member['vehicle_id']])) ?>"><i class="bi bi-truck me-2"></i>Fișa vehiculului</a></li>
                                        <li><a class="dropdown-item" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'interventions', 'vehicle_id' => (int) $member['vehicle_id']])) ?>"><i class="bi bi-tools me-2"></i>Intervenții</a></li>
                                    <?php endforeach; ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><button type="button" class="dropdown-item" data-ft-open-tab="history"><i class="bi bi-clock-history me-2"></i>Istoric montări</button></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="ft-detail" id="<?= e($detailId) ?>" data-ft-detail hidden></div>
                </article>
            <?php endforeach; ?>
            <div class="ft-empty-state" data-ft-empty <?= $fleetUnits === [] ? '' : 'hidden' ?>><i class="bi bi-search" aria-hidden="true"></i><?= $fleetUnits === [] ? 'Nu există vehicule active.' : 'Niciun vehicul nu corespunde filtrelor.' ?></div>
        </div>
        <div class="ft-footer">
            <div class="ft-footer-info" data-ft-range aria-live="polite"></div>
            <div class="ft-pager">
                <label class="visually-hidden" for="ftPerPage">Vehicule pe pagină</label>
                <select class="form-select" id="ftPerPage" data-ft-per-page>
                    <?php foreach ([10, 25, 50, 0] as $option): ?><option value="<?= e((string) $option) ?>"><?= $option === 0 ? 'Toate' : e((string) $option) . ' / pagină' ?></option><?php endforeach; ?>
                </select>
                <button type="button" class="ft-page-btn" data-ft-page="prev" aria-label="Pagina anterioară"><i class="bi bi-chevron-left"></i></button>
                <div class="d-flex gap-1" data-ft-pages></div>
                <button type="button" class="ft-page-btn" data-ft-page="next" aria-label="Pagina următoare"><i class="bi bi-chevron-right"></i></button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="ftMountModal" tabindex="-1" aria-labelledby="ftMountTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'mount_tire'])) ?>" data-ft-mount-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="vehicle_id" value="">
                    <input type="hidden" name="position_id" value="">
                    <input type="hidden" name="return_to" value="tire_stock">
                    <input type="hidden" name="return_query" value="">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="ftMountTitle">Montează anvelopă</h5>
                            <div class="small text-muted" data-ft-mount-target></div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="ftMountTire">Anvelopă din stoc / rezervă</label>
                            <select class="form-select" id="ftMountTire" name="tire_id" required disabled><option value="">Se încarcă...</option></select>
                            <div class="form-text" data-ft-mount-hint>Doar anvelopele compatibile cu această poziție (tip vehicul, axă, formulă).</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-sm-5"><label class="form-label" for="ftMountDate">Data montaj</label><input type="date" class="form-control" id="ftMountDate" name="mount_date" value="<?= e(date('Y-m-d')) ?>" required></div>
                            <div class="col-sm-7"><label class="form-label" for="ftMountObs">Observație</label><input type="text" class="form-control" id="ftMountObs" name="observation" maxlength="500"></div>
                        </div>
                        <div class="small text-muted mt-3">Km-ul de start se ia din kilometrajul de bord al vehiculului, ca la orice montaj; anvelopa își păstrează km parcurși anterior.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                        <button type="submit" class="btn btn-primary" data-ft-mount-submit disabled><i class="bi bi-check2 me-1"></i>Montează</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<script type="application/json" id="ftUnitsData"><?= json_encode($fleetUnitData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<div hidden data-ft-config
     data-unit-url="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_fleet_unit'])) ?>"
     data-candidates-url="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_position_candidates'])) ?>"
     data-mount-url="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'mount_tire'])) ?>"
     data-component-url="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'tire_fleet_component'])) ?>"
     data-component-write-url="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'component_mount'])) ?>"
     data-can-manage="<?= can('mentenanta', 'components_manage') ? '1' : '0' ?>"
     data-csrf="<?= e(csrf_token()) ?>"></div>
<script type="application/json" id="fxUnitIcons"><?= json_encode(['tractor' => $fleetTractorIcon, 'trailer' => $fleetTrailerIcon, 'assembly' => $fleetVehicleIcon('ansamblu')], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="application/json" id="fxCategories"><?= json_encode($fleetTireOverview['client_categories'] ?? VehicleComponentRegistry::clientCategories(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<link rel="stylesheet" href="<?= e(url('assets/css/vehicle-xray.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/vehicle-xray.css'))) ?>">
<script src="<?= e(url('assets/js/tire-fleet.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/tire-fleet.js'))) ?>"></script>
<script src="<?= e(url('assets/js/vehicle-xray-engine.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/vehicle-xray-engine.js'))) ?>"></script>
<script src="<?= e(url('assets/js/vehicle-xray-art.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/vehicle-xray-art.js'))) ?>"></script>
<script src="<?= e(url('assets/js/vehicle-xray.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/vehicle-xray.js'))) ?>"></script>
