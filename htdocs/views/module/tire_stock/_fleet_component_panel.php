<?php
/**
 * Panoul unei categorii de componente in randul extins (exploratorul din Stoc Anvelope).
 * Separa: ce e montat / configurat pe vehicul, ce e in stoc (inventar) si istoricul.
 * Randat de ModuleController::tireFleetComponentAction din VehicleComponentPanelService::build.
 *
 * Intrari: $panel, $vehicle (randul din vehicule), $slotLabel (eticheta locului ales in schema),
 *          $canManage (dreptul mentenanta.components_manage: montare, demontare, inspectie),
 *          $focusKey (optional: subcomponenta aleasa in detalierea ansamblului).
 */
$category = (string) $panel['category'];
$definition = VehicleComponentRegistry::CATEGORIES[$category];
$vehicleId = (int) $vehicle['id'];
$vehicleType = (string) ($vehicle['tip_vehicul'] ?? '');
$autoVehicleType = $vehicleType === 'cap_tractor' ? 'cap_tractor' : (str_starts_with($vehicleType, 'semiremorca') ? 'semiremorca' : 'camion');
$catalogCategoryId = (int) ($panel['components'][0]['catalog_category_id'] ?? 1);
$autoUrl = build_query_url([
    'page' => 'mentenanta',
    'action' => 'auto',
    'vehicle_type' => $autoVehicleType,
    'vehicle_id' => $vehicleId,
    'primary_category' => $catalogCategoryId >= 11 ? 'rezervor' : 'sasiu',
    'subcategory' => $catalogCategoryId >= 11 ? 'livrare_gaz' : ($catalogCategoryId === 10 ? 'hidraulic' : 'sasiu'),
]);
$formatDate = static function ($value): string {
    $time = strtotime((string) $value);
    return $value !== '' && $value !== null && $time !== false ? date('d.m.Y', $time) : '—';
};
$formatQuantity = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
$isLocal = in_array($definition['scope'], ['wheel_end', 'axle'], true);
$configured = array_values(array_filter($panel['components'], static fn (array $component): bool => $component['configured']));
?>
<div class="fx-panel-head">
    <div class="fx-panel-title">
        <span class="fx-panel-icon" style="--fx-accent: <?= e($definition['color']) ?>"><i class="bi <?= e($definition['icon']) ?>" aria-hidden="true"></i></span>
        <div>
            <div class="fw-bold"><?= e($slotLabel !== '' ? $slotLabel : $definition['label']) ?></div>
            <div class="small text-muted"><?= e((string) $vehicle['nr_inmatriculare']) ?> · <?= e(trim((string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? ''))) ?><?= $isLocal ? ' · datele sunt la nivel de vehicul; poziția apare din lucrările de intervenție' : '' ?></div>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-sm btn-outline-primary" href="<?= e($autoUrl) ?>"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Configurează în Reparații Auto</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'interventions', 'vehicle_id' => $vehicleId])) ?>"><i class="bi bi-tools me-1" aria-hidden="true"></i>Intervenții vehicul</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'stock'])) ?>"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Stoc piese</a>
        <button type="button" class="btn btn-sm btn-light border" data-fx-back><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Înapoi la vehicul</button>
    </div>
</div>

<?php if ($panel['components'] === []): ?>
    <div class="fx-empty">
        <i class="bi bi-journal-plus" aria-hidden="true"></i>
        <div>
            <div class="fw-semibold">Catalogul Reparații Auto nu are încă componente pentru „<?= e($definition['label']) ?>”.</div>
            <div class="small text-muted">Adaugă componentele în Reparații Auto; configurarea pe vehicul, montările și istoricul lor apar apoi aici.</div>
        </div>
    </div>
<?php else: ?>
    <?php
    $records = $panel['location_records'];
    $location = (string) $panel['location'];
    $locationLabel = VehicleComponentPanelService::locationLabel($location);
    $nameOf = [];
    $stockOf = [];
    foreach ($panel['components'] as $component) {
        $nameOf[$component['key']] = $component['name'];
        $stockOf[$component['key']] = $component['stock'];
    }
    $lastInspection = $records['inspections'][0] ?? null;
    $inspectionTone = ['ok' => 'ok', 'atentie' => 'warn', 'inlocuire' => 'crit'];
    $vehicleKm = $panel['vehicle_km'];
    $today = date('Y-m-d');
    $axleAttrs = $panel['axle_attrs'];
    $flagText = static fn ($value): string => $value === null ? 'nedefinit' : ($value ? 'Da' : 'Nu');
    $locationEvents = [];
    foreach ($records['mounts'] as $mount) {
        $locationEvents[] = ['date' => (string) $mount['data_montare'], 'text' => 'Montat ' . ($nameOf[$mount['component_key']] ?? $mount['part_name']) . ((int) $mount['direct_mount'] === 1 ? ' (montaj direct)' : ' (din stoc)'), 'detail' => trim(($mount['km_montare'] !== null ? number_format((float) $mount['km_montare'], 0, ',', '.') . ' km' : '') . ((string) ($mount['observatii'] ?? '') !== '' ? ' · ' . $mount['observatii'] : ''))];
        if ($mount['demontat_la'] !== null) {
            $locationEvents[] = ['date' => (string) $mount['demontat_la'], 'text' => ($mount['motiv_demontare'] === 'inlocuire' ? 'Înlocuit ' : 'Demontat ') . ($nameOf[$mount['component_key']] ?? $mount['part_name']), 'detail' => trim((VehicleComponentModel::UNMOUNT_REASONS[(string) $mount['motiv_demontare']] ?? '') . ($mount['km_demontare'] !== null ? ' · ' . number_format((float) $mount['km_demontare'], 0, ',', '.') . ' km' : '') . ((string) ($mount['observatii_demontare'] ?? '') !== '' ? ' · ' . $mount['observatii_demontare'] : ''))];
        }
    }
    foreach ($records['inspections'] as $inspection) {
        $locationEvents[] = ['date' => (string) $inspection['data_inspectie'], 'text' => 'Inspecție: ' . VehicleComponentModel::INSPECTION_RESULTS[$inspection['rezultat']] . ((string) ($inspection['component_key'] ?? '') !== '' ? ' · ' . ($nameOf[$inspection['component_key']] ?? '') : ''), 'detail' => trim(($inspection['km'] !== null ? number_format((float) $inspection['km'], 0, ',', '.') . ' km' : '') . ((string) ($inspection['observatii'] ?? '') !== '' ? ' · ' . $inspection['observatii'] : '')), 'tone' => $inspectionTone[$inspection['rezultat']]];
    }
    usort($locationEvents, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
    $componentOptions = static function (string $selected = '') use ($panel): string {
        $html = '';
        foreach ($panel['components'] as $component) {
            $stock = $component['stock'];
            $html .= '<option value="' . e($component['key']) . '"' . ($component['key'] === $selected ? ' selected' : '') . ' data-stock="' . e($stock !== null ? (string) $stock['quantity'] : '') . '" data-unit="' . e($stock['unit'] ?? 'buc') . '">'
                . e($component['name'] . ($stock !== null ? ' · stoc ' . rtrim(rtrim(number_format($stock['quantity'], 2, ',', '.'), '0'), ',') . ' ' . $stock['unit'] : ' · fără piesă în stoc')) . '</option>';
        }
        return $html;
    };
    ?>
    <section class="fx-section fx-location" aria-labelledby="fxLocation<?= e($category) ?>">
        <h3 class="fx-section-title" id="fxLocation<?= e($category) ?>"><i class="bi bi-geo-alt" aria-hidden="true"></i>Pe această poziție · <?= e($locationLabel) ?>
            <?php if ($lastInspection !== null): ?>
                <span class="fx-state fx-state-<?= e($inspectionTone[$lastInspection['rezultat']]) ?>">Ultima inspecție: <?= e(VehicleComponentModel::INSPECTION_RESULTS[$lastInspection['rezultat']]) ?> · <?= e($formatDate($lastInspection['data_inspectie'])) ?></span>
            <?php else: ?>
                <span>Nicio inspecție</span>
            <?php endif; ?>
        </h3>

        <?php if ($axleAttrs !== null || $panel['axle_no'] !== null): ?>
            <div class="fx-axle-attrs">
                <span><strong>Axa <?= e((string) $panel['axle_no']) ?>:</strong></span>
                <span>Ridicabilă: <?= e($flagText($axleAttrs['liftable'] ?? null)) ?></span>
                <span>Portantă: <?= e($flagText($axleAttrs['load_bearing'] ?? null)) ?></span>
                <span>Suspensie: <?= e(VehicleComponentModel::SUSPENSION_TYPES[$axleAttrs['suspension'] ?? ''] ?? 'nedefinită') ?></span>
                <span>Frâne: <?= e(VehicleComponentModel::BRAKE_TYPES[$axleAttrs['brakes'] ?? ''] ?? 'nedefinite') ?></span>
                <span class="text-muted small">(se editează din explorator: clic pe axă)</span>
            </div>
        <?php endif; ?>

        <?php if ($records['active'] === []): ?>
            <div class="text-muted small py-1">Nicio componentă înregistrată ca montată pe această poziție.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table fx-table align-middle mb-1">
                    <thead><tr><th>Componentă montată</th><th>Montată la</th><th>Sursă</th><th class="text-end">Km de la montare</th><th class="text-end">Acțiuni</th></tr></thead>
                    <tbody>
                    <?php foreach ($records['active'] as $mount): ?>
                        <tr>
                            <td><div class="fw-semibold"><?= e($nameOf[$mount['component_key']] ?? (string) $mount['part_name']) ?></div><div class="small text-muted"><?= e((string) ($mount['cod_piesa'] ?? '')) ?><?= (float) $mount['cantitate'] !== 1.0 ? ' · ' . e($formatQuantity((float) $mount['cantitate']) . ' ' . ($mount['unitate_masura'] ?? 'buc')) : '' ?></div></td>
                            <td class="text-nowrap"><?= e($formatDate($mount['data_montare'])) ?><?= $mount['km_montare'] !== null ? '<div class="small text-muted">' . e(number_format((float) $mount['km_montare'], 0, ',', '.') . ' km') . '</div>' : '' ?></td>
                            <td><?= (int) $mount['direct_mount'] === 1 ? 'Montaj direct' : 'Din stoc' ?></td>
                            <td class="text-end text-nowrap" title="Km bord (orientativ) minus km la montare"><?= $vehicleKm !== null && $mount['km_montare'] !== null && $vehicleKm >= (int) $mount['km_montare'] ? e(number_format($vehicleKm - (int) $mount['km_montare'], 0, ',', '.') . ' km') : '—' ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($canManage): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-fx-open-form="mount" data-fx-replace="<?= e((string) $mount['id']) ?>" data-fx-component="<?= e((string) $mount['component_key']) ?>"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Înlocuiește</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-fx-open-form="unmount" data-fx-usage="<?= e((string) $mount['id']) ?>" data-fx-usage-label="<?= e($nameOf[$mount['component_key']] ?? (string) $mount['part_name']) ?>" data-fx-usage-date="<?= e((string) $mount['data_montare']) ?>"><i class="bi bi-box-arrow-down me-1" aria-hidden="true"></i>Demontează</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <div class="d-flex flex-wrap gap-2 mt-1">
                <button type="button" class="btn btn-sm btn-primary" data-fx-open-form="mount"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Montează componentă</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-fx-open-form="inspect"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Înregistrează inspecție</button>
            </div>

            <form class="fx-form" data-fx-form="mount" hidden novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="vehicle_id" value="<?= e((string) $vehicleId) ?>">
                <input type="hidden" name="category" value="<?= e($category) ?>">
                <input type="hidden" name="location" value="<?= e($location) ?>">
                <input type="hidden" name="replace_usage_id" value="">
                <div class="fx-form-title" data-fx-form-title>Montează componentă · <?= e($locationLabel) ?></div>
                <div class="row g-2">
                    <div class="col-md-5"><label class="form-label">Componentă</label><select class="form-select form-select-sm" name="component_key" required><option value="">Alege componenta</option><?= $componentOptions() ?></select></div>
                    <div class="col-md-3"><label class="form-label">Sursă</label>
                        <select class="form-select form-select-sm" name="source"><option value="stoc">Din stoc (scade stocul)</option><option value="direct">Montaj direct (piesă adusă de service)</option></select>
                    </div>
                    <div class="col-md-2"><label class="form-label">Cantitate</label><input type="number" class="form-control form-control-sm" name="quantity" value="1" min="0.01" step="0.01" required></div>
                    <div class="col-md-2"><label class="form-label">Data</label><input type="date" class="form-control form-control-sm" name="date" value="<?= e($today) ?>" max="<?= e($today) ?>" required></div>
                    <div class="col-md-3"><label class="form-label">Km bord la montare</label><input type="number" class="form-control form-control-sm" name="km" value="<?= e((string) ($vehicleKm ?? '')) ?>" min="0"></div>
                    <div class="col-md-9"><label class="form-label">Observații</label><input type="text" class="form-control form-control-sm" name="notes" maxlength="500"></div>
                </div>
                <div class="fx-form-hint small text-muted" data-fx-stock-hint></div>
                <div class="fx-form-actions"><button type="button" class="btn btn-sm btn-light border" data-fx-close-form>Anulează</button><button type="submit" class="btn btn-sm btn-primary" data-fx-submit>Salvează montarea</button></div>
            </form>

            <form class="fx-form" data-fx-form="unmount" hidden novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="vehicle_id" value="<?= e((string) $vehicleId) ?>">
                <input type="hidden" name="usage_id" value="">
                <div class="fx-form-title" data-fx-form-title>Demontează</div>
                <div class="row g-2">
                    <div class="col-md-3"><label class="form-label">Data</label><input type="date" class="form-control form-control-sm" name="date" value="<?= e($today) ?>" max="<?= e($today) ?>" required></div>
                    <div class="col-md-3"><label class="form-label">Km bord</label><input type="number" class="form-control form-control-sm" name="km" value="<?= e((string) ($vehicleKm ?? '')) ?>" min="0"></div>
                    <div class="col-md-3"><label class="form-label">Motiv</label><select class="form-select form-select-sm" name="reason"><?php foreach (VehicleComponentModel::UNMOUNT_REASONS as $value => $label): if ($value === 'inlocuire') { continue; } ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label">Observații</label><input type="text" class="form-control form-control-sm" name="notes" maxlength="500"></div>
                </div>
                <div class="small text-muted mt-1">„Retur în stoc” readuce cantitatea în stocul piesei. Demontarea nu șterge montarea: rămâne în istoric.</div>
                <div class="fx-form-actions"><button type="button" class="btn btn-sm btn-light border" data-fx-close-form>Anulează</button><button type="submit" class="btn btn-sm btn-danger" data-fx-submit>Confirmă demontarea</button></div>
            </form>

            <form class="fx-form" data-fx-form="inspect" hidden novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="vehicle_id" value="<?= e((string) $vehicleId) ?>">
                <input type="hidden" name="category" value="<?= e($category) ?>">
                <input type="hidden" name="location" value="<?= e($location) ?>">
                <div class="fx-form-title">Inspecție · <?= e($locationLabel) ?></div>
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label">Componentă (opțional)</label><select class="form-select form-select-sm" name="component_key"><option value="">Toată categoria</option><?= $componentOptions() ?></select></div>
                    <div class="col-md-2"><label class="form-label">Data</label><input type="date" class="form-control form-control-sm" name="date" value="<?= e($today) ?>" max="<?= e($today) ?>" required></div>
                    <div class="col-md-2"><label class="form-label">Km bord</label><input type="number" class="form-control form-control-sm" name="km" value="<?= e((string) ($vehicleKm ?? '')) ?>" min="0"></div>
                    <div class="col-md-4"><label class="form-label">Rezultat</label>
                        <div class="fx-result" role="radiogroup" aria-label="Rezultat inspecție">
                            <?php foreach (VehicleComponentModel::INSPECTION_RESULTS as $value => $label): ?>
                                <label class="fx-result-<?= e($inspectionTone[$value]) ?>"><input type="radio" name="result" value="<?= e($value) ?>" <?= $value === 'ok' ? 'checked' : '' ?>> <?= e($label) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-12"><label class="form-label">Observații</label><input type="text" class="form-control form-control-sm" name="notes" maxlength="500"></div>
                </div>
                <div class="fx-form-actions"><button type="button" class="btn btn-sm btn-light border" data-fx-close-form>Anulează</button><button type="submit" class="btn btn-sm btn-primary" data-fx-submit>Salvează inspecția</button></div>
            </form>
        <?php endif; ?>

        <?php if ($locationEvents !== []): ?>
            <ul class="fx-history fx-history-compact mt-2">
                <?php foreach (array_slice($locationEvents, 0, 20) as $event): ?>
                    <li><span class="fx-history-date"><?= e($formatDate($event['date'])) ?></span><div><div class="fw-semibold<?= isset($event['tone']) ? ' fx-tone-' . e($event['tone']) : '' ?>"><?= e($event['text']) ?></div><?php if ($event['detail'] !== ''): ?><div class="small text-muted"><?= e($event['detail']) ?></div><?php endif; ?></div></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <div class="fx-sections">
        <section class="fx-section" aria-labelledby="fxMounted<?= e($category) ?>">
            <h3 class="fx-section-title" id="fxMounted<?= e($category) ?>"><i class="bi bi-truck" aria-hidden="true"></i>Montat / configurat pe vehicul <span><?= e(count($configured) . ' din ' . count($panel['components'])) ?> configurate</span></h3>
            <div class="table-responsive">
                <table class="table fx-table align-middle mb-0">
                    <thead><tr><th>Componentă</th><th>Cod</th><th>Monitorizare</th><th>Interval</th><th>Uzură</th><th>Ultima montare</th></tr></thead>
                    <tbody>
                    <?php foreach ($panel['components'] as $component): ?>
                        <tr class="<?= $component['configured'] ? '' : 'is-muted' ?><?= ($focusKey ?? '') === $component['key'] ? ' is-focus' : '' ?>"<?= ($focusKey ?? '') === $component['key'] ? ' data-fx-focus' : '' ?>>
                            <td><div class="fw-semibold"><?= e($component['name']) ?></div><div class="small text-muted"><?= e($component['catalog_category']) ?></div></td>
                            <td class="text-nowrap"><?= e($component['code'] !== '' ? $component['code'] : '—') ?></td>
                            <td><?= $component['configured'] ? e($component['monitoring'] !== '' ? $component['monitoring'] : 'Configurată') : '<span class="text-muted">Neconfigurată</span>' ?></td>
                            <td class="text-nowrap"><?= $component['interval'] !== '' ? e($component['interval'] . ' ' . $component['unit']) : '—' ?></td>
                            <td>
                                <?php if ($component['wear'] !== null): ?>
                                    <div class="fx-wear"><span class="fx-wear-<?= e($component['wear_tone']) ?>"><?= e((string) $component['wear']) ?>%</span><span class="fx-bar"><span class="fx-wear-bg-<?= e($component['wear_tone']) ?>" style="width: <?= e((string) max(2, $component['wear'])) ?>%"></span></span></div>
                                <?php else: ?>
                                    <span class="text-muted small"><?= $component['configured'] ? 'Fără date de montare / km' : '—' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap"><?= e($formatDate($component['last_mount_date'])) ?><?= $component['last_mount_km'] !== null ? '<div class="small text-muted">' . e(number_format($component['last_mount_km'], 0, ',', '.') . ' km') . '</div>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="fx-section" aria-labelledby="fxStock<?= e($category) ?>">
            <h3 class="fx-section-title" id="fxStock<?= e($category) ?>"><i class="bi bi-box-seam" aria-hidden="true"></i>Inventar (stoc piese)</h3>
            <div class="table-responsive">
                <table class="table fx-table align-middle mb-0">
                    <thead><tr><th>Piesă</th><th>Cod</th><th class="text-end">Stoc</th><th>Furnizor</th><th>Locație</th><th>Garanție</th></tr></thead>
                    <tbody>
                    <?php foreach ($panel['components'] as $component): ?>
                        <?php $stock = $component['stock']; ?>
                        <tr>
                            <td class="fw-semibold"><?= e($stock['name'] ?? $component['name']) ?></td>
                            <?php if ($stock === null): ?>
                                <td colspan="5" class="text-muted small">Fără piesă în stoc pentru această componentă.</td>
                            <?php else: ?>
                                <td class="text-nowrap"><?= e($stock['code'] !== '' ? $stock['code'] : '—') ?></td>
                                <td class="text-end text-nowrap <?= $stock['minimum'] > 0 && $stock['quantity'] < $stock['minimum'] ? 'text-danger fw-bold' : '' ?>" title="<?= $stock['minimum'] > 0 ? e('Stoc minim: ' . $formatQuantity($stock['minimum'])) : '' ?>"><?= e($formatQuantity($stock['quantity']) . ' ' . $stock['unit']) ?></td>
                                <td><?= e($stock['supplier'] !== '' ? $stock['supplier'] : '—') ?></td>
                                <td><?= e($stock['location'] !== '' ? $stock['location'] : '—') ?></td>
                                <td><?= e($stock['warranty'] !== '' ? $stock['warranty'] : '—') ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="fx-section" aria-labelledby="fxHistory<?= e($category) ?>">
            <h3 class="fx-section-title" id="fxHistory<?= e($category) ?>"><i class="bi bi-clock-history" aria-hidden="true"></i>Istoric pe vehicul <span><?= e((string) count($panel['history'])) ?> înregistrări</span></h3>
            <?php if ($panel['history'] === []): ?>
                <div class="text-muted small px-1 py-2">Nicio montare, lucrare de intervenție sau factură înregistrată pentru aceste componente pe vehicul.</div>
            <?php else: ?>
                <ul class="fx-history">
                    <?php foreach ($panel['history'] as $event): ?>
                        <?php
                        $link = null;
                        if (($event['link'][0] ?? '') === 'intervention') {
                            $link = build_query_url(['page' => 'mentenanta', 'action' => 'interventions', 'intervention_id' => $event['link'][1]]);
                        } elseif (($event['link'][0] ?? '') === 'ocr' && function_exists('is_admin') && is_admin()) {
                            $link = build_query_url(['page' => 'ocr_piese', 'deschide' => $event['link'][1]]);
                        }
                        ?>
                        <li class="<?= $event['here'] ? 'is-here' : '' ?>">
                            <span class="fx-history-date"><?= e($formatDate($event['date'])) ?></span>
                            <div>
                                <div class="fw-semibold"><?= e($event['kind']) ?> · <?= e($event['component']) ?><?= $event['here'] ? ' <span class="badge text-bg-primary">această poziție</span>' : '' ?></div>
                                <?php if ($event['detail'] !== ''): ?><div class="small text-muted"><?= e($event['detail']) ?></div><?php endif; ?>
                            </div>
                            <?php if ($link !== null): ?><a class="small ms-auto text-nowrap" href="<?= e($link) ?>">Deschide <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
<?php endif; ?>
