<?php
/**
 * Inspectorul unei componente, in dreapta exploratorului din Stoc Anvelope (in locul legendei).
 * Antet (componenta, context, stare, inchidere), 4 indicatori, tab-uri (Detalii, Mentenanta,
 * Istoric interventii, Documente, Piese asociate) si bara fixa de actiuni. Doar date reale:
 * ce lipseste apare „Nedisponibil”. Randat de ModuleController::tireFleetComponentInspectorAction.
 *
 * Intrari: $inspector (VehicleComponentPanelService::inspector), $vehicle (randul din vehicule),
 *          $canManage (mentenanta.components_manage), $canInterventions (mentenanta.interventions).
 */
$category = (string) $inspector['category'];
$definition = VehicleComponentRegistry::CATEGORIES[$category];
$component = $inspector['component'];
$stock = $component['stock'];
$vehicleId = (int) $vehicle['id'];
$location = (string) $inspector['location'];
$locationLabel = VehicleComponentPanelService::locationLabel($location);
$make = trim((string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? ''));
$na = '<span class="fi-na">Nedisponibil</span>';
$formatDate = static function ($value): string {
    $time = $value !== null && $value !== '' ? strtotime((string) $value) : false;
    return $time !== false ? date('d.m.Y', $time) : '';
};
$formatKm = static fn ($value): string => $value === null ? '' : number_format((float) $value, 0, ',', '.') . ' km';
$statusIcon = ['ok' => 'bi-check-circle-fill', 'warn' => 'bi-exclamation-triangle-fill', 'crit' => 'bi-x-octagon-fill', 'unknown' => 'bi-question-circle'][$inspector['status']];
$remaining = $inspector['remaining_percent'];
$remainingTone = $remaining === null ? 'unknown' : ($remaining <= 0 ? 'crit' : ($remaining <= 100 - AutoComponentStateService::WEAR_ATTENTION_PERCENT ? 'warn' : 'ok'));
$next = $inspector['next_service'];
$nextText = $next === null ? '' : ($next['type'] === 'km' ? $formatKm($next['value']) : $formatDate($next['value']));
$inspectionLabels = VehicleComponentModel::INSPECTION_RESULTS;
$inspectionTone = ['ok' => 'ok', 'atentie' => 'warn', 'inlocuire' => 'crit'];
$today = date('Y-m-d');
$vehicleKm = $inspector['vehicle_km'];
$notes = trim((string) (($inspector['active_here']['observatii'] ?? '') ?: ''));
$interventionParams = ['page' => 'mentenanta', 'action' => 'interventions', 'nou' => 1, 'vehicle_id' => $vehicleId, 'component_key' => $component['key'], 'pozitie' => $locationLabel !== 'vehicul' ? $locationLabel : ''];
$historyLink = static function (array $event): ?string {
    if (($event['link'][0] ?? '') === 'intervention') {
        return build_query_url(['page' => 'mentenanta', 'action' => 'interventions', 'intervention_id' => $event['link'][1]]);
    }
    if (($event['link'][0] ?? '') === 'ocr' && function_exists('is_admin') && is_admin()) {
        return build_query_url(['page' => 'ocr_piese', 'deschide' => $event['link'][1]]);
    }
    return null;
};
?>
<div class="fi" data-fi data-fi-key="<?= e($component['key']) ?>" style="--fx-accent: <?= e($definition['color']) ?>">
    <header class="fi-head">
        <span class="fi-icon" aria-hidden="true"><i class="bi <?= e($definition['icon']) ?>"></i></span>
        <div class="fi-title">
            <h3 class="fi-name" id="fiTitle<?= e(str_replace('-', '_', $component['key'])) ?>"><?= e($component['name']) ?></h3>
            <div class="fi-ctx"><?= e($definition['short'] ?? $definition['label']) ?> – <?= e($make !== '' ? $make : (string) $vehicle['nr_inmatriculare']) ?><?= $locationLabel !== 'vehicul' ? ' · ' . e($locationLabel) : '' ?></div>
        </div>
        <span class="fi-status is-<?= e($inspector['status']) ?>" title="<?= e($inspector['status_source']) ?>"><i class="bi <?= e($statusIcon) ?>" aria-hidden="true"></i><?= e($inspector['status_label']) ?></span>
        <button type="button" class="fi-close" data-fi-close aria-label="Închide inspectorul"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </header>

    <div class="fi-metrics">
        <div class="fi-metric"><i class="bi bi-upc" aria-hidden="true"></i><div><span>Cod piesă</span><strong><?= $component['code'] !== '' ? e($component['code']) : $na ?></strong></div></div>
        <div class="fi-metric"><i class="bi bi-speedometer2" aria-hidden="true"></i><div><span>Km la înlocuire</span><strong><?= $inspector['mount_km'] !== null ? e($formatKm($inspector['mount_km'])) : $na ?></strong></div></div>
        <div class="fi-metric"><i class="bi bi-calendar-event" aria-hidden="true"></i><div><span>Ultima înlocuire</span><strong><?= $inspector['mount_date'] !== null ? e($formatDate($inspector['mount_date'])) : $na ?></strong></div></div>
        <div class="fi-metric"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div><span>Durată de viață</span>
            <?php if ($remaining !== null): ?>
                <strong class="fi-tone-<?= e($remainingTone) ?>"><?= e((string) $remaining) ?>%</strong>
                <span class="fi-bar" role="progressbar" aria-valuenow="<?= e((string) $remaining) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Durată de viață rămasă"><span class="is-<?= e($remainingTone) ?>" style="width: <?= e((string) max(2, $remaining)) ?>%"></span></span>
            <?php else: ?>
                <strong><?= $na ?></strong>
            <?php endif; ?>
        </div></div>
    </div>

    <nav class="fi-tabs" role="tablist" aria-label="Detalii componentă">
        <button type="button" role="tab" class="fi-tab is-active" aria-selected="true" data-fi-tab="details"><i class="bi bi-list-ul" aria-hidden="true"></i>Detalii</button>
        <button type="button" role="tab" class="fi-tab" aria-selected="false" data-fi-tab="maintenance"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i>Mentenanță</button>
        <button type="button" role="tab" class="fi-tab" aria-selected="false" data-fi-tab="history"><i class="bi bi-clock-history" aria-hidden="true"></i>Istoric intervenții</button>
        <button type="button" role="tab" class="fi-tab" aria-selected="false" data-fi-tab="documents"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>Documente</button>
        <button type="button" role="tab" class="fi-tab" aria-selected="false" data-fi-tab="associated"><i class="bi bi-tools" aria-hidden="true"></i>Piese asociate</button>
    </nav>

    <div class="fi-body">
        <section role="tabpanel" data-fi-panel="details">
            <h4 class="fi-section-title">Detalii componentă</h4>
            <table class="fi-table">
                <tbody>
                <tr><th>Denumire</th><td><?= e($component['name']) ?> <span class="text-muted">(<?= e($component['catalog_category']) ?>)</span></td></tr>
                <tr><th>Cod piesă</th><td><?= $component['code'] !== '' ? e($component['code']) : $na ?></td></tr>
                <tr><th>Producător</th><td><?= $stock !== null && $stock['producer'] !== '' ? e($stock['producer']) : $na ?></td></tr>
                <tr><th>Furnizor</th><td><?= $stock !== null && $stock['supplier'] !== '' ? e($stock['supplier']) : $na ?></td></tr>
                <tr><th>Status</th><td><span class="fi-status is-<?= e($inspector['status']) ?> is-small"><i class="bi <?= e($statusIcon) ?>" aria-hidden="true"></i><?= e($inspector['status_label']) ?></span> <span class="fi-note"><?= e($inspector['status_source']) ?></span></td></tr>
                <tr><th>Durată de viață</th><td>
                    <?php if ($remaining !== null): ?>
                        <div class="fi-life"><span><?= e((string) $remaining) ?>%<?= $inspector['remaining_km'] !== null ? ' (rămas ~ ' . e($formatKm(max(0, $inspector['remaining_km']))) . ')' : '' ?></span><span class="fi-bar is-wide"><span class="is-<?= e($remainingTone) ?>" style="width: <?= e((string) max(2, $remaining)) ?>%"></span></span></div>
                    <?php else: ?>
                        <?= $na ?> <span class="fi-note"><?= $component['configured'] ? 'lipsesc km / data montării' : 'intervalul nu e configurat în Reparații Auto' ?></span>
                    <?php endif; ?>
                </td></tr>
                <tr><th>Următoarea revizie</th><td><?= $nextText !== '' ? '<i class="bi bi-wrench text-muted me-1" aria-hidden="true"></i>' . e($nextText) : $na ?></td></tr>
                <tr><th>Poziție</th><td><?= e(ucfirst($locationLabel)) ?><?= $inspector['active_here'] !== null ? ' · montată din ' . e($formatDate($inspector['active_here']['data_montare'])) : '' ?></td></tr>
                <tr><th>Stoc disponibil</th><td><?= $stock !== null ? e(rtrim(rtrim(number_format($stock['quantity'], 2, ',', '.'), '0'), ',') . ' ' . $stock['unit']) . ($stock['location'] !== '' ? ' · ' . e($stock['location']) : '') : $na ?></td></tr>
                <tr><th>Observații</th><td><?= $notes !== '' ? e($notes) : '<span class="fi-na">Fără observații</span>' ?></td></tr>
                </tbody>
            </table>
        </section>

        <section role="tabpanel" data-fi-panel="maintenance" hidden>
            <h4 class="fi-section-title">Mentenanță</h4>
            <table class="fi-table">
                <tbody>
                <tr><th>Ultima montare / înlocuire</th><td><?= $inspector['mount_date'] !== null ? e($formatDate($inspector['mount_date'])) . ($inspector['mount_km'] !== null ? ' · ' . e($formatKm($inspector['mount_km'])) : '') : $na ?></td></tr>
                <tr><th>Următoarea revizie</th><td><?= $nextText !== '' ? e($nextText) : $na ?></td></tr>
                <tr><th>Monitorizare</th><td><?= $component['configured'] ? e(($component['monitoring'] !== '' ? $component['monitoring'] : 'Configurată') . ' · interval ' . $component['interval'] . ' ' . $component['unit']) : '<span class="fi-na">Neconfigurată în Reparații Auto</span>' ?></td></tr>
                <tr><th>Prag avertizare / critic</th><td><?= $component['warning'] !== '' || $component['critical'] !== '' ? e(($component['warning'] !== '' ? $component['warning'] : '—') . ' / ' . ($component['critical'] !== '' ? $component['critical'] : '—') . ' ' . $component['unit']) : $na ?></td></tr>
                <tr><th>Ultima inspecție</th><td><?php if ($inspector['last_inspection'] !== null): $li = $inspector['last_inspection']; ?><span class="fi-status is-<?= e($inspectionTone[$li['rezultat']]) ?> is-small"><?= e($inspectionLabels[$li['rezultat']]) ?></span> <?= e($formatDate($li['data_inspectie'])) ?><?= $li['km'] !== null ? ' · ' . e($formatKm($li['km'])) : '' ?><?= (string) ($li['observatii'] ?? '') !== '' ? '<div class="fi-note">' . e($li['observatii']) . '</div>' : '' ?><?php else: ?><?= $na ?><?php endif; ?></td></tr>
                </tbody>
            </table>
            <?php if ($canManage): ?>
                <div class="fi-inline-actions">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-fi-open-form="mount"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Montează / înlocuiește</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-fi-open-form="inspect"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Înregistrează inspecție</button>
                </div>
                <form class="fx-form" data-fi-form="mount" hidden novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="vehicle_id" value="<?= e((string) $vehicleId) ?>">
                    <input type="hidden" name="category" value="<?= e($category) ?>">
                    <input type="hidden" name="location" value="<?= e($location) ?>">
                    <input type="hidden" name="component_key" value="<?= e($component['key']) ?>">
                    <input type="hidden" name="replace_usage_id" value="<?= e((string) ($inspector['active_here']['id'] ?? '')) ?>">
                    <div class="fx-form-title"><?= $inspector['active_here'] !== null ? 'Înlocuiește ' . e($component['name']) . ' (montarea actuală se închide cu motivul „înlocuire”)' : 'Montează ' . e($component['name']) . ' · ' . e($locationLabel) ?></div>
                    <div class="row g-2">
                        <div class="col-sm-4"><label class="form-label">Sursă</label><select class="form-select form-select-sm" name="source"><option value="stoc">Din stoc (scade stocul)</option><option value="direct">Montaj direct (piesă adusă de service)</option></select></div>
                        <div class="col-sm-2"><label class="form-label">Cantitate</label><input type="number" class="form-control form-control-sm" name="quantity" value="1" min="0.01" step="0.01" required></div>
                        <div class="col-sm-4" data-km-lookup><?php $dtpLabel = 'Data & ora montării'; $dtpDateName = 'date'; $dtpTimeName = 'time'; include __DIR__ . '/_datetime_field.php'; ?></div>
                        <div class="col-sm-2"><label class="form-label">Km bord</label><input type="number" class="form-control form-control-sm" name="km" value="<?= e((string) ($vehicleKm ?? '')) ?>" min="0"></div>
                        <div class="col-12 small text-muted" data-km-lookup-hint></div>
                        <div class="col-12"><label class="form-label">Observații</label><input type="text" class="form-control form-control-sm" name="notes" maxlength="500"></div>
                    </div>
                    <div class="small text-muted mt-1"><?= $stock !== null ? 'În stoc: ' . e(rtrim(rtrim(number_format($stock['quantity'], 2, ',', '.'), '0'), ',') . ' ' . $stock['unit']) . '.' : 'Componenta nu are piesă în stoc; folosește montajul direct.' ?></div>
                    <div class="fx-form-actions"><button type="button" class="btn btn-sm btn-light border" data-fi-close-form>Anulează</button><button type="submit" class="btn btn-sm btn-primary" data-fi-submit>Salvează</button></div>
                </form>
                <form class="fx-form" data-fi-form="inspect" hidden novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="vehicle_id" value="<?= e((string) $vehicleId) ?>">
                    <input type="hidden" name="category" value="<?= e($category) ?>">
                    <input type="hidden" name="location" value="<?= e($location) ?>">
                    <input type="hidden" name="component_key" value="<?= e($component['key']) ?>">
                    <div class="fx-form-title">Inspecție · <?= e($component['name']) ?></div>
                    <div class="row g-2">
                        <div class="col-sm-3"><label class="form-label">Data</label><input type="date" class="form-control form-control-sm" name="date" value="<?= e($today) ?>" max="<?= e($today) ?>" required></div>
                        <div class="col-sm-3"><label class="form-label">Km bord</label><input type="number" class="form-control form-control-sm" name="km" value="<?= e((string) ($vehicleKm ?? '')) ?>" min="0"></div>
                        <div class="col-sm-6"><label class="form-label">Rezultat</label><div class="fx-result" role="radiogroup" aria-label="Rezultat inspecție">
                            <?php foreach ($inspectionLabels as $value => $label): ?><label class="fx-result-<?= e($inspectionTone[$value]) ?>"><input type="radio" name="result" value="<?= e($value) ?>" <?= $value === 'ok' ? 'checked' : '' ?>> <?= e($label) ?></label><?php endforeach; ?>
                        </div></div>
                        <div class="col-12"><label class="form-label">Observații</label><input type="text" class="form-control form-control-sm" name="notes" maxlength="500"></div>
                    </div>
                    <div class="fx-form-actions"><button type="button" class="btn btn-sm btn-light border" data-fi-close-form>Anulează</button><button type="submit" class="btn btn-sm btn-primary" data-fi-submit>Salvează inspecția</button></div>
                </form>
            <?php endif; ?>
            <?php if ($inspector['inspections'] !== []): ?>
                <h4 class="fi-section-title mt-3">Inspecții pe această poziție</h4>
                <ul class="fi-list">
                    <?php foreach ($inspector['inspections'] as $row): ?>
                        <li><span class="fi-date"><?= e($formatDate($row['data_inspectie'])) ?></span><div><span class="fi-status is-<?= e($inspectionTone[$row['rezultat']]) ?> is-small"><?= e($inspectionLabels[$row['rezultat']]) ?></span><?= $row['km'] !== null ? ' <span class="fi-note">' . e($formatKm($row['km'])) . '</span>' : '' ?><?= (string) ($row['observatii'] ?? '') !== '' ? '<div class="fi-note">' . e($row['observatii']) . '</div>' : '' ?></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section role="tabpanel" data-fi-panel="history" hidden>
            <h4 class="fi-section-title">Istoric intervenții</h4>
            <?php
            $events = [];
            foreach ($inspector['location_mounts'] as $row) {
                $events[] = ['date' => (string) $row['data_montare'], 'title' => ((int) $row['direct_mount'] === 1 ? 'Montaj direct' : 'Montare din stoc') . ' · ' . $locationLabel, 'detail' => trim(($row['km_montare'] !== null ? $formatKm($row['km_montare']) : '') . ((string) ($row['montata_de'] ?? '') !== '' ? ' · ' . $row['montata_de'] : '') . ((string) ($row['observatii'] ?? '') !== '' ? ' · ' . $row['observatii'] : ''), ' ·'), 'link' => null];
                if ($row['demontat_la'] !== null) {
                    $events[] = ['date' => (string) $row['demontat_la'], 'title' => ($row['motiv_demontare'] === 'inlocuire' ? 'Înlocuire' : 'Demontare') . ' · ' . $locationLabel, 'detail' => trim((VehicleComponentModel::UNMOUNT_REASONS[(string) $row['motiv_demontare']] ?? '') . ($row['km_demontare'] !== null ? ' · ' . $formatKm($row['km_demontare']) : '') . ((string) ($row['observatii_demontare'] ?? '') !== '' ? ' · ' . $row['observatii_demontare'] : '')), 'link' => null];
                }
            }
            foreach ($inspector['history'] as $event) {
                if (str_starts_with($event['kind'], 'Montare piesă') && $event['here']) {
                    continue; // deja in montarile acestei pozitii
                }
                $events[] = ['date' => $event['date'], 'title' => $event['kind'], 'detail' => $event['detail'], 'link' => $historyLink($event)];
            }
            usort($events, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
            ?>
            <?php if ($events === []): ?>
                <div class="fi-empty">Nicio montare, lucrare de intervenție sau factură pentru această componentă pe vehicul.</div>
            <?php else: ?>
                <ul class="fi-list">
                    <?php foreach ($events as $event): ?>
                        <li><span class="fi-date"><?= e($formatDate($event['date'])) ?></span><div><div class="fw-semibold"><?= e($event['title']) ?></div><?php if ($event['detail'] !== ''): ?><div class="fi-note"><?= e($event['detail']) ?></div><?php endif; ?></div><?php if ($event['link'] !== null): ?><a class="ms-auto small text-nowrap" href="<?= e($event['link']) ?>">Deschide <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section role="tabpanel" data-fi-panel="documents" hidden>
            <h4 class="fi-section-title">Documente</h4>
            <?php if ($inspector['documents'] === []): ?>
                <div class="fi-empty">Nu există documente legate: fișierele piesei (factură, fișă tehnică) se adaugă din Stoc piese, iar devizele / facturile din panoul intervenției.</div>
            <?php else: ?>
                <ul class="fi-list">
                    <?php foreach ($inspector['documents'] as $document): ?>
                        <li><i class="bi bi-file-earmark-text fi-doc-icon" aria-hidden="true"></i><div><div class="fw-semibold"><?= e($document['type']) ?></div><div class="fi-note"><?= e($document['name']) ?><?= $document['date'] ? ' · ' . e($formatDate($document['date'])) : '' ?></div></div><a class="ms-auto small text-nowrap" href="<?= e($document['url']) ?>" target="_blank" rel="noopener">Deschide <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section role="tabpanel" data-fi-panel="associated" hidden>
            <h4 class="fi-section-title">Piese asociate · <?= e($definition['label']) ?></h4>
            <?php if ($inspector['associated'] === []): ?>
                <div class="fi-empty">Nicio altă componentă în această categorie a catalogului.</div>
            <?php else: ?>
                <div class="fi-assoc">
                    <?php foreach ($inspector['associated'] as $item): ?>
                        <button type="button" class="fi-assoc-item" data-fi-sub="<?= e($item['key']) ?>">
                            <span class="fw-semibold"><?= e($item['name']) ?></span>
                            <span class="fi-note"><?= e($item['code']) ?><?= $item['configured'] ? ' · configurată' : '' ?><?= $item['wear'] !== null ? ' · uzură ' . e((string) $item['wear']) . '%' : '' ?><?= $item['last_mount_date'] !== '' ? ' · montată ' . e($formatDate($item['last_mount_date'])) : '' ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <footer class="fi-actions">
        <?php if ($canInterventions): ?>
            <a class="fi-action is-primary" href="<?= e(build_query_url($interventionParams + ['operatiune' => 'reparatie', 'tip' => 'reparatie'])) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i>Adaugă intervenție</a>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <button type="button" class="fi-action" data-fi-open-form="mount"><i class="bi bi-cart-plus" aria-hidden="true"></i>Adaugă piesă</button>
        <?php endif; ?>
        <?php if ($canInterventions): ?>
            <a class="fi-action" href="<?= e(build_query_url($interventionParams + ['operatiune' => 'verificare', 'tip' => 'intretinere', 'motiv' => 'revizie'])) ?>"><i class="bi bi-calendar-check" aria-hidden="true"></i>Programează revizie</a>
        <?php endif; ?>
        <button type="button" class="fi-action" data-fi-report><i class="bi bi-printer" aria-hidden="true"></i>Generează raport</button>
    </footer>
</div>
