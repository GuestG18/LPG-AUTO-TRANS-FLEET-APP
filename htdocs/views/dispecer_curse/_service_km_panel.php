<?php
/**
 * "Km service": drumul unui vehicul la service si perioada in care a stat la reparat.
 * Formular separat de "Adauga Cursa": nu trece prin tarifare, diurne sau km-ii
 * vehiculului, scrie doar in vehicule_service_km (VehicleServiceKmModel).
 *
 * Km service V2: sectiunea "Solicitare interventie" trimite componentele suspectate (Registru
 * piese & lucrari) si prioritatea catre Interventii V2 (sursa de adevar pentru partea tehnica).
 *
 * Asteapta: $serviceKmEntries, $serviceKmFlash, $raceVehicles, $allActiveDrivers, $driversByVehicle, $serviceKmContext.
 */
$serviceKmEntries = is_array($serviceKmEntries ?? null) ? $serviceKmEntries : [];
$serviceKmOld = (array) ($serviceKmFlash['old'] ?? []);
$serviceKmErrors = (array) ($serviceKmFlash['errors'] ?? []);
$serviceKmCanManage = !function_exists('can') || can('dispecer_curse', 'service_km');
$serviceKmOpen = $serviceKmErrors !== [];
$serviceKmActive = array_values(array_filter($serviceKmEntries, static fn (array $row): bool => !empty($row['in_service'])));
$serviceKmDriversByVehicle = [];
foreach (($driversByVehicle ?? []) as $vehicleKey => $vehicleDrivers) {
    $first = is_array($vehicleDrivers) ? reset($vehicleDrivers) : null;
    if (is_array($first) && (int) ($first['id'] ?? 0) > 0) {
        $serviceKmDriversByVehicle[(int) $vehicleKey] = (int) $first['id'];
    }
}
$serviceKmValue = static fn (string $key): string => (string) ($serviceKmOld[$key] ?? '');
$serviceKmDate = static fn (?string $value): string => $value ? date('d.m.Y', (int) strtotime($value)) : '';
$serviceKmTime = static fn (?string $value): string => $value ? substr($value, 0, 5) : '';
$serviceKmContext = is_array($serviceKmContext ?? null) ? $serviceKmContext : [];
$serviceKmInterventionUrl = static fn (int $id): string => build_query_url(['page' => 'mentenanta', 'action' => 'interventions', 'intervention_id' => $id]);
// Lucrarile interventiei -> randurile formularului (cele atinse de coordonator raman blocate).
$serviceKmComponents = static function (?array $intervention): array {
    $rows = [];
    foreach ((array) ($intervention['items'] ?? []) as $item) {
        $rows[] = [
            'item_id' => (int) $item['id'],
            'key' => (string) ($item['component_key'] ?? ''),
            'primary' => (string) ($item['ramura'] ?? ''),
            'position' => (string) ($item['pozitie'] ?? ''),
            'operation' => (string) $item['operatiune'],
            'vehicle_id' => $item['vehicle_id'] ?? null,
            'locked' => empty($item['dispecer_editabila']),
            'stare' => (string) $item['stare_evaluare'],
            'name' => (string) $item['nume'],
            'category' => (string) ($item['categorie'] ?? ''),
            'code' => (string) ($item['cod'] ?? ''),
            'vehicle_plate' => (string) ($item['vehicul_componenta'] ?? ''),
        ];
    }
    return $rows;
};
?>
<link rel="stylesheet" href="<?= e(url('assets/css/interventii-v2.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/interventii-v2.css'))) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/km-service-v2.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/km-service-v2.css'))) ?>">
<div class="card border-0 shadow-sm <?= e((string) ($serviceKmCardSpacing ?? 'mt-3')) ?> service-km-card" id="service-km-panel" data-service-km-panel data-driver-map="<?= e((string) json_encode($serviceKmDriversByVehicle)) ?>" data-gps-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'service_km_gps'])) ?>">
    <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <h3 class="h6 mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-tools" aria-hidden="true"></i>
            <span>Km service</span>
            <?php if ($serviceKmActive !== []): ?>
                <span class="badge text-bg-warning" title="Vehicule aflate acum în service"><?= count($serviceKmActive) ?> în service acum</span>
            <?php endif; ?>
        </h3>
        <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if ($serviceKmActive !== []): ?>
            <span class="small text-muted d-none d-md-inline">
                <?= e(implode(', ', array_map(static fn (array $row): string => (string) $row['nr_inmatriculare'], array_slice($serviceKmActive, 0, 4)))) ?><?= count($serviceKmActive) > 4 ? '…' : '' ?>
            </span>
        <?php endif; ?>
        <?php if ($serviceKmEntries !== []): ?>
            <button type="button" class="btn btn-sm btn-outline-primary dispatcher-filter-toggle" data-service-km-list-toggle aria-expanded="false" aria-controls="service-km-list-wrap">
                <i class="bi bi-list-ul" aria-hidden="true"></i>
                <span data-service-km-list-label>Afișează lista (<?= count($serviceKmEntries) ?>)</span>
                <i class="bi bi-chevron-down" data-service-km-list-chevron aria-hidden="true"></i>
            </button>
        <?php endif; ?>
        <?php if ($serviceKmCanManage): ?>
            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#service-km-form-wrap" aria-expanded="<?= $serviceKmOpen ? 'true' : 'false' ?>" aria-controls="service-km-form-wrap" data-service-km-new>
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă km service
            </button>
        <?php endif; ?>
        </div>
    </div>

    <?php if ($serviceKmCanManage): ?>
        <div class="collapse<?= $serviceKmOpen ? ' show' : '' ?>" id="service-km-form-wrap">
            <div class="ksv2-body">
                <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'service_km_store'])) ?>" class="ksv2-form" data-service-km-form novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($serviceKmValue('id')) ?>" data-service-km-field="id">
                    <input type="hidden" name="components_json" value="<?= e($serviceKmValue('components_json') !== '' ? $serviceKmValue('components_json') : '[]') ?>" data-ksv2-components-json>
                    <div class="ksv2-grid">
                        <section class="ksv2-section" aria-labelledby="ksv2-s1">
                            <header class="ksv2-head">
                                <span class="ksv2-num" aria-hidden="true">01</span>
                                <div>
                                    <h4 id="ksv2-s1">Deplasare la service</h4>
                                    <p>Informații despre deplasarea vehiculului la service și perioada în care este în service.</p>
                                </div>
                            </header>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label" for="service_km_vehicle">Vehicul <span class="text-danger">*</span></label>
                                    <select class="form-select<?= isset($serviceKmErrors['vehicle_id']) ? ' is-invalid' : '' ?>" id="service_km_vehicle" name="vehicle_id" required data-service-km-field="vehicle_id">
                                        <option value="">Alege vehiculul</option>
                                        <?php foreach (($raceVehicles ?? []) as $vehicle): ?>
                                            <option value="<?= e((string) $vehicle['id']) ?>" <?= $serviceKmValue('vehicle_id') === (string) $vehicle['id'] ? 'selected' : '' ?>>
                                                <?= e(trim((string) $vehicle['nr_inmatriculare'] . ' - ' . trim((string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? '')), ' -')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($serviceKmErrors['vehicle_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $serviceKmErrors['vehicle_id']) ?></div><?php endif; ?>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="service_km_driver">Șofer</label>
                                    <select class="form-select" id="service_km_driver" name="driver_id" data-service-km-field="driver_id">
                                        <option value="">— fără șofer —</option>
                                        <?php foreach (($allActiveDrivers ?? []) as $driver): ?>
                                            <option value="<?= e((string) $driver['id']) ?>" <?= $serviceKmValue('driver_id') === (string) $driver['id'] ? 'selected' : '' ?>><?= e((string) $driver['nume']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="service_km_service">Service</label>
                                    <input type="text" class="form-control ksv2-select-like" id="service_km_service" name="service_nume" maxlength="150" list="service_km_services" placeholder="Alege sau scrie service-ul (ex. Interactiv Bragadiru)" value="<?= e($serviceKmValue('service_nume')) ?>" data-service-km-field="service_nume" autocomplete="off">
                                    <datalist id="service_km_services">
                                        <?php foreach ((array) ($serviceKmContext['services'] ?? []) as $serviceName): ?>
                                            <option value="<?= e((string) $serviceName) ?>"></option>
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="service_km_km">Km service</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control<?= isset($serviceKmErrors['km']) ? ' is-invalid' : '' ?>" id="service_km_km" name="km" min="0" step="0.01" inputmode="decimal" value="<?= e($serviceKmValue('km')) ?>" data-service-km-field="km">
                                        <span class="input-group-text">km</span>
                                    </div>
                                    <div class="form-text" data-service-km-gps-hint>Se completează din GPS (SAS) după vehicul, plecare și întoarcere.</div>
                                </div>
                                <?php /* Acelasi selector data + ora ca in "Adauga cursa" (assets/js/datetime-picker-field.js);
                                         valorile trimise raman Y-m-d / HH:MM in campurile ascunse. */ ?>
                                <div class="col-sm-6">
                                    <label class="form-label" for="service_km_departure">Data plecării la service <span class="text-danger">*</span></label>
                                    <div class="dispatcher-datetime-field" data-datetime-picker<?= isset($serviceKmErrors['data_plecare']) ? ' data-dtp-server-error' : '' ?>>
                                        <div class="input-group dispatcher-datetime-input-group">
                                            <input type="text" class="form-control<?= isset($serviceKmErrors['data_plecare']) ? ' is-invalid' : '' ?>" id="service_km_departure" placeholder="dd/mm/yyyy HH:mm" inputmode="numeric" autocomplete="off" maxlength="16" aria-label="Data și ora plecării la service" data-dtp-display>
                                            <button type="button" class="btn btn-outline-secondary" aria-label="Deschide calendarul pentru plecarea la service" aria-expanded="false" data-dtp-toggle>
                                                <i class="bi bi-calendar3" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                        <div class="dispatcher-datetime-popover" hidden data-dtp-popover></div>
                                        <input type="hidden" name="data_plecare" value="<?= e($serviceKmValue('data_plecare') !== '' ? $serviceKmValue('data_plecare') : date('Y-m-d')) ?>" data-dtp-date data-service-km-field="data_plecare">
                                        <input type="hidden" name="ora_plecare" value="<?= e($serviceKmValue('ora_plecare')) ?>" data-dtp-time data-service-km-field="ora_plecare">
                                    </div>
                                    <?php if (isset($serviceKmErrors['data_plecare'])): ?><div class="invalid-feedback d-block"><?= e((string) $serviceKmErrors['data_plecare']) ?></div><?php endif; ?>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="service_km_return">Data întoarcerii</label>
                                    <div class="dispatcher-datetime-field" data-datetime-picker<?= isset($serviceKmErrors['data_intoarcere']) ? ' data-dtp-server-error' : '' ?>>
                                        <div class="input-group dispatcher-datetime-input-group">
                                            <input type="text" class="form-control<?= isset($serviceKmErrors['data_intoarcere']) ? ' is-invalid' : '' ?>" id="service_km_return" placeholder="dd/mm/yyyy HH:mm" inputmode="numeric" autocomplete="off" maxlength="16" aria-label="Data și ora întoarcerii" data-dtp-display>
                                            <button type="button" class="btn btn-outline-secondary" aria-label="Deschide calendarul pentru întoarcere" aria-expanded="false" data-dtp-toggle>
                                                <i class="bi bi-calendar3" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                        <div class="dispatcher-datetime-popover" hidden data-dtp-popover></div>
                                        <input type="hidden" name="data_intoarcere" value="<?= e($serviceKmValue('data_intoarcere')) ?>" data-dtp-date data-service-km-field="data_intoarcere">
                                        <input type="hidden" name="ora_intoarcere" value="<?= e($serviceKmValue('ora_intoarcere')) ?>" data-dtp-time data-service-km-field="ora_intoarcere">
                                    </div>
                                    <?php if (isset($serviceKmErrors['data_intoarcere'])): ?><div class="invalid-feedback d-block"><?= e((string) $serviceKmErrors['data_intoarcere']) ?></div><?php else: ?><div class="form-text">Goală = vehiculul este încă în service.</div><?php endif; ?>
                                </div>
                            </div>
                            <div class="text-danger small mt-2" hidden data-service-km-overlap></div>
                        </section>

                        <section class="ksv2-section" aria-labelledby="ksv2-s2">
                            <header class="ksv2-head">
                                <span class="ksv2-num" aria-hidden="true">02</span>
                                <div>
                                    <h4 id="ksv2-s2">Solicitare intervenție</h4>
                                    <p>Detalii despre motivul intervenției și componentele suspectate.</p>
                                </div>
                            </header>
                            <div class="mb-3">
                                <label class="form-label" for="service_km_reason">Motiv / reparație</label>
                                <div class="ksv2-clearable">
                                    <input type="text" class="form-control" id="service_km_reason" name="motiv" maxlength="255" placeholder="ex. Reglat pivoți și bară direcție axa 4" value="<?= e($serviceKmValue('motiv')) ?>" data-service-km-field="motiv">
                                    <button type="button" class="ksv2-clear" data-ksv2-clear="motiv" aria-label="Golește motivul"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                </div>
                            </div>
                            <div class="mb-3" data-ksv2-components>
                                <label class="form-label d-flex align-items-center gap-1" for="ksv2-search">Componente suspectate
                                    <i class="bi bi-info-circle text-primary" tabindex="0" data-bs-toggle="tooltip" title="Opțional. Dispecerul indică piesele bănuite; coordonatorul tehnic le confirmă sau le exclude în Mentenanță → Intervenții." aria-label="Componentele sunt opționale; coordonatorul tehnic le confirmă sau le exclude."></i>
                                </label>
                                <div class="ksv2-target" data-ksv2-target hidden role="group" aria-label="Vehiculul componentei"></div>
                                <div class="ksv2-search">
                                    <i class="bi bi-search" aria-hidden="true"></i>
                                    <input type="search" class="form-control" id="ksv2-search" placeholder="Caută piesă după nume, cod sau categorie..." autocomplete="off" role="combobox" aria-expanded="false" aria-controls="ksv2-results" data-ksv2-search>
                                    <div class="ksv2-results" id="ksv2-results" role="listbox" hidden data-ksv2-results></div>
                                </div>
                                <div class="ksv2-rows" data-ksv2-rows aria-live="polite"></div>
                                <div class="ksv2-notice" hidden data-ksv2-notice></div>
                                <?php if (isset($serviceKmErrors['components'])): ?><div class="invalid-feedback d-block"><?= e((string) $serviceKmErrors['components']) ?></div><?php endif; ?>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm ksv2-btn" data-ksv2-add><i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă componentă</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm ksv2-btn" data-ksv2-registry><i class="bi bi-database" aria-hidden="true"></i> Selectează din registru</button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="service_km_priority">Prioritate</label>
                                <select class="form-select" id="service_km_priority" name="prioritate" data-service-km-field="prioritate">
                                    <?php foreach (InterventionWorkflowModel::PRIORITY_LABELS as $priorityKey => $priorityLabel): ?>
                                        <option value="<?= e($priorityKey) ?>" <?= ($serviceKmValue('prioritate') !== '' ? $serviceKmValue('prioritate') : 'normala') === $priorityKey ? 'selected' : '' ?>><?= e($priorityLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ksv2-info" data-ksv2-link-wrap>
                                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                                <div>
                                    <span data-ksv2-link-text>Se va genera o intervenție în Mentenanță pe baza acestor informații, sau se va lega la o intervenție existentă.</span>
                                    <select class="form-select form-select-sm mt-2" name="link_intervention_id" hidden data-ksv2-link data-ksv2-initial="<?= e($serviceKmValue('link_intervention_id')) ?>" aria-label="Leagă de o intervenție existentă">
                                        <option value="">Creează o intervenție nouă</option>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="ksv2-section" aria-labelledby="ksv2-s3">
                            <header class="ksv2-head">
                                <span class="ksv2-num" aria-hidden="true">03</span>
                                <div>
                                    <h4 id="ksv2-s3">Observații</h4>
                                    <p>Observații și detalii suplimentare din partea dispecerului.</p>
                                </div>
                            </header>
                            <label class="form-label" for="service_km_notes">Observații</label>
                            <textarea class="form-control ksv2-notes" id="service_km_notes" name="observatii" rows="4" maxlength="2000" data-service-km-field="observatii" placeholder="Ce a observat șoferul, când apare problema, alte detalii utile pentru service..."><?= e($serviceKmValue('observatii')) ?></textarea>
                            <div class="ksv2-counter" aria-live="polite"><span data-ksv2-count>0</span>/2000</div>
                            <div class="ksv2-info ksv2-info-doc">
                                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                <span>Aceste observații se păstrează ca raportul original al dispecerului și vor fi incluse în intervenția din Mentenanță.</span>
                            </div>
                            <div class="ksv2-linked" hidden data-ksv2-linked>
                                <i class="bi bi-link-45deg" aria-hidden="true"></i>
                                <span>Intervenție asociată: <a href="#" data-ksv2-linked-link></a></span>
                                <a class="btn btn-outline-primary btn-sm ms-auto" href="#" data-ksv2-linked-open><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Deschide intervenția</a>
                            </div>
                        </section>
                    </div>
                    <div class="ksv2-actions">
                        <button type="submit" class="btn btn-primary" data-service-km-submit><?= $serviceKmValue('id') !== '' ? 'Salvează modificarea' : 'Adaugă km service' ?></button>
                        <button type="button" class="btn btn-outline-secondary" data-service-km-reset>Renunță</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="ksv2RegistryModal" tabindex="-1" aria-labelledby="ksv2RegistryTitle" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content iv2-modal">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title fs-5" id="ksv2RegistryTitle">Selectează din registru</h2>
                            <p class="text-muted small mb-0">Registru piese &amp; lucrări: Tip vehicul → Vehicul → Categorie principală → Subcategorie → Componentă.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                    </div>
                    <div class="modal-body iv2" data-ksv2-registry-host></div>
                    <div class="modal-footer">
                        <span class="me-auto text-muted small" data-ksv2-registry-count></span>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                        <button type="button" class="btn btn-primary" data-ksv2-registry-add><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Adaugă componentele</button>
                    </div>
                </div>
            </div>
        </div>
        <script type="application/json" id="km-service-boot"><?= json_encode([
            'catalog' => $serviceKmContext['catalog'] ?? null,
            'vehicles' => $serviceKmContext['vehicles'] ?? [],
            'operations' => InterventionWorkflowModel::OPERATION_LABELS,
            'assessment' => InterventionWorkflowModel::ASSESSMENT_LABELS,
            'info_url' => build_query_url(['page' => 'dispecer_curse', 'action' => 'service_km_vehicle_info']),
            'intervention_url' => build_query_url(['page' => 'mentenanta', 'action' => 'interventions']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
    <?php endif; ?>

    <?php if ($serviceKmEntries === []): ?>
        <div class="card-body text-muted small">
            Nicio perioadă de service în ultimele 90 de zile.
        </div>
    <?php else: ?>
        <?php /* Lista sta ascunsa implicit, ca sa nu lungeasca pagina; antetul arata
                 cate vehicule sunt acum in service. */ ?>
        <div id="service-km-list-wrap" hidden data-service-km-list>
        <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light position-sticky top-0">
                    <tr>
                        <th scope="col">Status</th>
                        <th scope="col">Vehicul</th>
                        <th scope="col">Șofer</th>
                        <th scope="col">Plecare</th>
                        <th scope="col">Întoarcere</th>
                        <th scope="col" class="text-end" title="Zile în care vehiculul a fost inactiv (inclusiv ziua plecării și a întoarcerii)">Zile inactiv</th>
                        <th scope="col" class="text-end">Km service</th>
                        <th scope="col" class="text-end" title="Alimentări CardOil asociate în Carburanți cu această perioadă (aceeași regulă ca la curse: între plecare și întoarcere, ±12 h)">Alimentări</th>
                        <th scope="col">Service / motiv</th>
                        <th scope="col" title="Componente suspectate trimise intervenției (fără cele excluse de coordonator)">Componente</th>
                        <th scope="col" title="Intervenția din Mentenanță → Intervenții (statusul tehnic, separat de întoarcerea din service)">Intervenție</th>
                        <?php if ($serviceKmCanManage): ?><th scope="col" class="text-end">Acțiuni</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($serviceKmEntries as $entry): ?>
                        <?php
                        $entryJson = json_encode([
                            'id' => (string) $entry['id'],
                            'vehicle_id' => (string) $entry['vehicle_id'],
                            'driver_id' => (string) ($entry['driver_id'] ?? ''),
                            'data_plecare' => (string) $entry['data_plecare'],
                            'ora_plecare' => $serviceKmTime($entry['ora_plecare'] ?? null),
                            'data_intoarcere' => (string) ($entry['data_intoarcere'] ?? ''),
                            'ora_intoarcere' => $serviceKmTime($entry['ora_intoarcere'] ?? null),
                            'km' => (string) (float) $entry['km'],
                            'service_nume' => (string) ($entry['service_nume'] ?? ''),
                            'motiv' => (string) ($entry['motiv'] ?? ''),
                            'observatii' => (string) ($entry['observatii'] ?? ''),
                            'prioritate' => (string) ($entry['prioritate'] ?? 'normala'),
                            'interventie' => !empty($entry['interventie']) ? [
                                'id' => (int) $entry['interventie']['id'],
                                'status_key' => (string) $entry['interventie']['status_key'],
                                'status_label' => (string) $entry['interventie']['status_label'],
                            ] : null,
                            'components' => $serviceKmComponents($entry['interventie'] ?? null),
                        ], JSON_UNESCAPED_UNICODE);
                        $entryIntervention = is_array($entry['interventie'] ?? null) ? $entry['interventie'] : null;
                        ?>
                        <tr class="<?= !empty($entry['in_service']) ? 'table-warning' : '' ?>">
                            <td>
                                <?php if (!empty($entry['in_service'])): ?>
                                    <span class="badge text-bg-warning"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i> În service</span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">Revenit</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold text-nowrap"><?= e((string) $entry['nr_inmatriculare']) ?></td>
                            <td><?= e((string) ($entry['sofer_nume'] ?? '—')) ?></td>
                            <td class="text-nowrap"><?= e(trim($serviceKmDate($entry['data_plecare']) . ' ' . $serviceKmTime($entry['ora_plecare'] ?? null))) ?></td>
                            <td class="text-nowrap">
                                <?= !empty($entry['in_service']) ? '<span class="text-muted">—</span>' : e(trim($serviceKmDate($entry['data_intoarcere']) . ' ' . $serviceKmTime($entry['ora_intoarcere'] ?? null))) ?>
                            </td>
                            <td class="text-end">
                                <?= e((string) ($entry['zile_service'] ?? '')) ?>
                                <?php if (!empty($entry['in_service'])): ?><span class="text-muted small">(până azi)</span><?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap"><?= e(format_number_ro((float) $entry['km'], 0)) ?> km</td>
                            <td class="text-end text-nowrap">
                                <?php if (!empty($entry['alimentari'])): ?>
                                    <a href="<?= e(build_query_url(['page' => 'carburanti', 'date_from' => date('Y-m-d', (int) strtotime($entry['data_plecare'] . ' -1 day')), 'date_to' => date('Y-m-d', (int) strtotime(($entry['data_intoarcere'] ?? date('Y-m-d')) . ' +1 day')), 'vehicle' => (string) $entry['nr_inmatriculare']])) ?>" title="<?= e(format_number_ro((float) $entry['alimentari']['valoare'], 2)) ?> lei · deschide în Carburanți">
                                        <?= e(format_number_ro((float) $entry['alimentari']['litri'], 0)) ?> L
                                    </a>
                                    <div class="text-muted small"><?= (int) $entry['alimentari']['nr'] ?> alim.</div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e((string) ($entry['service_nume'] ?? '')) ?>
                                <?php if (!empty($entry['motiv'])): ?>
                                    <div class="text-muted small"><?= e((string) $entry['motiv']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($entry['observatii'])): ?>
                                    <div class="text-muted small fst-italic"><?= e((string) $entry['observatii']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($entryIntervention !== null && $entryIntervention['componente'] > 0): ?>
                                    <span class="ksv2-pill" title="<?= e(implode(', ', array_map(static fn (array $item): string => $item['nume'] . ($item['pozitie'] !== '' ? ' (' . $item['pozitie'] . ')' : ''), array_filter($entryIntervention['items'], static fn (array $item): bool => $item['stare_evaluare'] !== 'exclusa')))) ?>"><i class="bi bi-gear" aria-hidden="true"></i> <?= (int) $entryIntervention['componente'] ?> comp.</span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <?php if ($entryIntervention !== null): ?>
                                    <a class="fw-semibold" href="<?= e($serviceKmInterventionUrl((int) $entryIntervention['id'])) ?>" title="Deschide intervenția în Mentenanță → Intervenții">#<?= (int) $entryIntervention['id'] ?></a>
                                    <span class="iv2-badge st-<?= e((string) $entryIntervention['status_key']) ?>"><?= e((string) $entryIntervention['status_label']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($serviceKmCanManage): ?>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-service-km-edit="<?= e((string) $entryJson) ?>" data-service-km-return="<?= !empty($entry['in_service']) ? '1' : '' ?>" title="<?= !empty($entry['in_service']) ? 'Completează întoarcerea din service' : 'Modifică' ?>">
                                        <i class="bi <?= !empty($entry['in_service']) ? 'bi-box-arrow-in-left' : 'bi-pencil' ?>" aria-hidden="true"></i>
                                        <?= !empty($entry['in_service']) ? 'Întoarcere' : '' ?>
                                    </button>
                                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'service_km_delete'])) ?>" class="d-inline" data-service-km-delete-form>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $entry['id']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Șterge"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white text-muted small">
            Se afișează vehiculele aflate acum în service și perioadele încheiate în ultimele 90 de zile.
            Km service apar separat și în raportul Km pierduți, ca justificare a km GPS rulați fără cursă.
        </div>
        </div>
    <?php endif; ?>
</div>

<script src="<?= e(url('assets/js/datetime-picker-field.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/datetime-picker-field.js'))) ?>"></script>
<script src="<?= e(url('assets/js/interventii-v2.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/interventii-v2.js'))) ?>"></script>
<script src="<?= e(url('assets/js/km-service-v2.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/km-service-v2.js'))) ?>"></script>
<script>
(function () {
    'use strict';
    var panel = document.querySelector('[data-service-km-panel]');
    if (!panel) { return; }

    // Lista ascunsa implicit; se deschide din buton sau cand se ajunge la panou
    // prin #service-km-panel (butonul "Km service" din antet, revenirea dupa salvare).
    var list = panel.querySelector('[data-service-km-list]');
    var listToggle = panel.querySelector('[data-service-km-list-toggle]');
    function setListOpen(open) {
        if (!list || !listToggle) { return; }
        list.hidden = !open;
        listToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        listToggle.classList.toggle('is-open', open);
        var label = listToggle.querySelector('[data-service-km-list-label]');
        if (label) { label.textContent = (open ? 'Ascunde lista' : 'Afișează lista') + ' (' + list.querySelectorAll('tbody tr').length + ')'; }
        var chevron = listToggle.querySelector('[data-service-km-list-chevron]');
        if (chevron) { chevron.classList.toggle('bi-chevron-up', open); chevron.classList.toggle('bi-chevron-down', !open); }
    }
    if (listToggle) {
        listToggle.addEventListener('click', function () { setListOpen(list.hidden); });
    }
    function openFromHash() {
        if (window.location.hash === '#service-km-panel') {
            setListOpen(true);
            panel.scrollIntoView({ block: 'start' });
        }
    }
    openFromHash();
    window.addEventListener('hashchange', openFromHash);

    var form = panel.querySelector('[data-service-km-form]');
    if (!form) { return; }

    var driverMap = {};
    try { driverMap = JSON.parse(panel.getAttribute('data-driver-map') || '{}') || {}; } catch (e) { driverMap = {}; }
    var submitBtn = form.querySelector('[data-service-km-submit]');
    function field(name) { return form.querySelector('[data-service-km-field="' + name + '"]'); }

    function openForm() {
        var wrap = document.getElementById('service-km-form-wrap');
        // bootstrap se incarca in footer: il folosim doar la click.
        if (wrap && window.bootstrap && window.bootstrap.Collapse) {
            window.bootstrap.Collapse.getOrCreateInstance(wrap, { toggle: false }).show();
        } else if (wrap) {
            wrap.classList.add('show');
        }
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function fill(values) {
        Object.keys(values).forEach(function (key) {
            var input = field(key);
            if (input) { input.value = values[key] == null ? '' : values[key]; }
        });
        submitBtn.textContent = values.id ? 'Salvează modificarea' : 'Adaugă km service';
        if (window.KmServiceV2) { window.KmServiceV2.load(values); }
        // Datele stau in campuri ascunse: refacem textul afisat in selectoarele de data.
        if (window.FleetDateTimePicker) { window.FleetDateTimePicker.sync(form); }
        // Un calcul GPS pornit inainte nu mai are voie sa scrie peste valorile incarcate acum.
        window.clearTimeout(gpsTimer);
        gpsRequest++;
        setGpsHint('');
        showOverlap([]);
    }

    function todayIso() {
        var now = new Date();
        return now.getFullYear() + '-' + ('0' + (now.getMonth() + 1)).slice(-2) + '-' + ('0' + now.getDate()).slice(-2);
    }

    // Soferul asignat vehiculului se propune automat, doar daca nu a fost ales altul.
    field('vehicle_id').addEventListener('change', function () {
        var driver = field('driver_id');
        var suggested = driverMap[this.value];
        if (suggested && driver.value === '') { driver.value = String(suggested); }
    });

    panel.querySelectorAll('[data-service-km-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var values = {};
            try { values = JSON.parse(btn.getAttribute('data-service-km-edit') || '{}'); } catch (e) { return; }
            if (btn.getAttribute('data-service-km-return') === '1' && !values.data_intoarcere) {
                values.data_intoarcere = todayIso();
            }
            fill(values);
            openForm();
            var focusTarget = btn.getAttribute('data-service-km-return') === '1' ? field('km') : field('vehicle_id');
            if (focusTarget) { focusTarget.focus(); }
        });
    });

    form.querySelector('[data-service-km-reset]').addEventListener('click', function () {
        form.reset();
        // form.reset() nu reface campurile ascunse, deci data plecarii revine explicit la azi.
        fill({ id: '', vehicle_id: '', driver_id: '', data_plecare: todayIso(), data_intoarcere: '', ora_plecare: '', ora_intoarcere: '', km: '', service_nume: '', motiv: '', observatii: '', prioritate: 'normala', interventie: null, components: [] });
        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
    });

    // Km din GPS (SAS): cand vehiculul, plecarea si intoarcerea sunt completate, "Km service"
    // se calculeaza automat (totalul dintre plecare si intoarcere + deplasarile gasite).
    // Se declanseaza doar la modificarile operatorului; fill() de la Editeaza nu emite evenimente,
    // deci valoarea salvata nu se suprascrie doar prin deschiderea formularului.
    // Fara panou cu deplasarile: doar textul de sub camp arata calculul in curs sau o eroare.
    var gpsHint = form.querySelector('[data-service-km-gps-hint]');
    var gpsHintDefault = gpsHint.textContent;
    var gpsTimer = null;
    var gpsRequest = 0;
    // Km alesi in Km pierduti (doar deplasarile bifate): GPS-ul intervalului nu ii suprascrie,
    // pana cand operatorul schimba vehiculul sau perioada.
    var lockedKm = null;
    function setGpsHint(text, isWarning) {
        gpsHint.textContent = text || gpsHintDefault;
        gpsHint.classList.toggle('text-warning-emphasis', !!isWarning);
    }
    // Cursele din Desfasurator care cad in perioada de service: km-ii lor s-ar numara de doua ori.
    var overlapBox = form.querySelector('[data-service-km-overlap]');
    function showOverlap(trips) {
        overlapBox.textContent = '';
        trips = Array.isArray(trips) ? trips : [];
        overlapBox.hidden = trips.length === 0;
        if (!trips.length) { return; }
        var icon = document.createElement('i');
        icon.className = 'bi bi-exclamation-triangle-fill me-1';
        icon.setAttribute('aria-hidden', 'true');
        overlapBox.appendChild(icon);
        overlapBox.appendChild(document.createTextNode('Perioada de service se suprapune cu ' + (trips.length === 1 ? 'cursa ' : 'cursele ')));
        trips.forEach(function (trip, index) {
            if (index > 0) { overlapBox.appendChild(document.createTextNode(', ')); }
            var link = document.createElement('a');
            link.href = trip.url;
            link.target = '_blank';
            link.className = 'link-danger fw-semibold';
            link.textContent = trip.label;
            overlapBox.appendChild(link);
        });
        overlapBox.appendChild(document.createTextNode('. Modifică plecarea / întoarcerea din service ca să nu acopere cursa — altfel km se numără de două ori.'));
    }
    function fetchGpsKm() {
        var params = new URLSearchParams();
        ['vehicle_id', 'data_plecare', 'ora_plecare', 'data_intoarcere', 'ora_intoarcere'].forEach(function (name) {
            params.set(name, field(name).value);
        });
        if (!params.get('vehicle_id') || !params.get('data_plecare') || !params.get('data_intoarcere')) {
            setGpsHint('');
            showOverlap([]);
            return;
        }
        var url = panel.getAttribute('data-gps-url');
        var requestId = ++gpsRequest;
        setGpsHint('Se calculează km din GPS (SAS)…');
        fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + params.toString(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                // Operatorul a schimbat intre timp vehiculul/datele: raspunsul vechi se ignora.
                if (requestId !== gpsRequest) { return; }
                showOverlap(data && data.overlapping_trips);
                if (!data || !data.success) {
                    setGpsHint((data && data.error) || 'Datele SAS nu au putut fi citite; completează km manual.', true);
                    return;
                }
                if (lockedKm !== null) {
                    field('km').value = lockedKm;
                    setGpsHint('Km aleși din Km pierduți (deplasările bifate): ' + lockedKm + ' km. GPS pe tot intervalul: ' + String(Math.round(Number(data.total_km) || 0)) + ' km.');
                    return;
                }
                field('km').value = String(Math.round(data.total_km == null ? 0 : Number(data.total_km)));
                setGpsHint('');
            })
            .catch(function () {
                if (requestId !== gpsRequest) { return; }
                setGpsHint('Datele SAS nu au putut fi citite; completează km manual.', true);
            });
    }

    function scheduleGpsKm() {
        lockedKm = null;
        window.clearTimeout(gpsTimer);
        gpsTimer = window.setTimeout(fetchGpsKm, 500);
    }
    // Selectorul de data emite "change" pe campul ascuns de data si cand se schimba doar ora.
    ['vehicle_id', 'data_plecare', 'data_intoarcere'].forEach(function (name) {
        field(name).addEventListener('change', scheduleGpsKm);
    });

    // Din Km pierduti (prefill=service): vehiculul si perioada rulata fara cursa vin in URL;
    // formularul se deschide precompletat, iar km se calculeaza din GPS ca la o modificare manuala.
    // Acelasi lucru din cardul "Km pierduti" de pe pagina (dispatcher-insights.js), fara reincarcare.
    function prefillFrom(source) {
        var vehicleId = String(source.vehicle_id || '');
        if (!vehicleId) { return false; }
        var prefill = { id: '', vehicle_id: vehicleId, driver_id: driverMap[vehicleId] ? String(driverMap[vehicleId]) : '', km: '', service_nume: '', motiv: '', observatii: '', prioritate: 'normala', interventie: null, components: [] };
        ['data_plecare', 'ora_plecare', 'data_intoarcere', 'ora_intoarcere'].forEach(function (name) {
            prefill[name] = /^[\d:-]+$/.test(source[name] || '') ? source[name] : '';
        });
        fill(prefill);
        if (field('vehicle_id').value !== vehicleId) { return false; }
        var chosenKm = parseInt(String(source.km || ''), 10);
        lockedKm = chosenKm > 0 ? String(chosenKm) : null;
        if (lockedKm !== null) { field('km').value = lockedKm; }
        openForm();
        fetchGpsKm();
        return true;
    }
    window.DispatcherServiceKm = { prefill: prefillFrom };
    var query = new URLSearchParams(window.location.search);
    if (query.get('prefill') === 'service') {
        prefillFrom(Object.fromEntries(query.entries()));
    }

    panel.querySelectorAll('[data-service-km-delete-form]').forEach(function (deleteForm) {
        var armed = false;
        // confirm() e blocat in browserul integrat: al doilea click confirma stergerea.
        deleteForm.addEventListener('submit', function (event) {
            if (armed) { return; }
            event.preventDefault();
            armed = true;
            var button = deleteForm.querySelector('button');
            button.classList.replace('btn-outline-danger', 'btn-danger');
            button.innerHTML = 'Confirmă ștergerea';
            setTimeout(function () {
                armed = false;
                button.classList.replace('btn-danger', 'btn-outline-danger');
                button.innerHTML = '<i class="bi bi-trash" aria-hidden="true"></i>';
            }, 4000);
        });
    });
})();
</script>
