<?php
/**
 * "Km service": drumul unui vehicul la service si perioada in care a stat la reparat.
 * Formular separat de "Adauga Cursa": nu trece prin tarifare, diurne sau km-ii
 * vehiculului, scrie doar in vehicule_service_km (VehicleServiceKmModel).
 *
 * Asteapta: $serviceKmEntries, $serviceKmFlash, $raceVehicles, $allActiveDrivers, $driversByVehicle.
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
?>
<div class="card border-0 shadow-sm mt-3 service-km-card" id="service-km-panel" data-service-km-panel data-driver-map="<?= e((string) json_encode($serviceKmDriversByVehicle)) ?>">
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
            <div class="card-body border-bottom">
                <p class="text-muted small mb-3">
                    Km parcurși până la / de la service și perioada în care vehiculul a stat la reparat.
                    Nu sunt curse: nu se tarifează, nu se facturează și nu modifică km-ii vehiculului.
                    Lasă <strong>Data întoarcerii</strong> goală cât timp vehiculul este încă în service.
                </p>
                <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'service_km_store'])) ?>" data-service-km-form novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($serviceKmValue('id')) ?>" data-service-km-field="id">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label" for="service_km_vehicle">Vehicul <span class="text-danger">*</span></label>
                            <select class="form-select<?= isset($serviceKmErrors['vehicle_id']) ? ' is-invalid' : '' ?>" id="service_km_vehicle" name="vehicle_id" required data-service-km-field="vehicle_id">
                                <option value="">Alege vehiculul</option>
                                <?php foreach (($raceVehicles ?? []) as $vehicle): ?>
                                    <option value="<?= e((string) $vehicle['id']) ?>" <?= $serviceKmValue('vehicle_id') === (string) $vehicle['id'] ? 'selected' : '' ?>>
                                        <?= e(trim((string) $vehicle['nr_inmatriculare'] . ' ' . (string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? ''))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="service_km_driver">Șofer</label>
                            <select class="form-select" id="service_km_driver" name="driver_id" data-service-km-field="driver_id">
                                <option value="">— fără șofer —</option>
                                <?php foreach (($allActiveDrivers ?? []) as $driver): ?>
                                    <option value="<?= e((string) $driver['id']) ?>" <?= $serviceKmValue('driver_id') === (string) $driver['id'] ? 'selected' : '' ?>><?= e((string) $driver['nume']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="service_km_service">Service</label>
                            <input type="text" class="form-control" id="service_km_service" name="service_nume" maxlength="150" placeholder="ex. Service Iveco Ploiești" value="<?= e($serviceKmValue('service_nume')) ?>" data-service-km-field="service_nume">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="service_km_km">Km service</label>
                            <div class="input-group">
                                <input type="number" class="form-control<?= isset($serviceKmErrors['km']) ? ' is-invalid' : '' ?>" id="service_km_km" name="km" min="0" step="0.01" inputmode="decimal" value="<?= e($serviceKmValue('km')) ?>" data-service-km-field="km">
                                <span class="input-group-text">km</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="service_km_departure">Data plecării la service <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="date" class="form-control<?= isset($serviceKmErrors['data_plecare']) ? ' is-invalid' : '' ?>" id="service_km_departure" name="data_plecare" required value="<?= e($serviceKmValue('data_plecare') !== '' ? $serviceKmValue('data_plecare') : date('Y-m-d')) ?>" data-service-km-field="data_plecare">
                                <input type="time" class="form-control" name="ora_plecare" aria-label="Ora plecării" value="<?= e($serviceKmValue('ora_plecare')) ?>" data-service-km-field="ora_plecare">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="service_km_return">Data întoarcerii</label>
                            <div class="input-group">
                                <input type="date" class="form-control<?= isset($serviceKmErrors['data_intoarcere']) ? ' is-invalid' : '' ?>" id="service_km_return" name="data_intoarcere" value="<?= e($serviceKmValue('data_intoarcere')) ?>" data-service-km-field="data_intoarcere">
                                <input type="time" class="form-control" name="ora_intoarcere" aria-label="Ora întoarcerii" value="<?= e($serviceKmValue('ora_intoarcere')) ?>" data-service-km-field="ora_intoarcere">
                            </div>
                            <div class="form-text">Goală = vehiculul este încă în service.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="service_km_reason">Motiv / reparație</label>
                            <input type="text" class="form-control" id="service_km_reason" name="motiv" maxlength="255" placeholder="ex. schimb ambreiaj" value="<?= e($serviceKmValue('motiv')) ?>" data-service-km-field="motiv">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="service_km_notes">Observații</label>
                            <input type="text" class="form-control" id="service_km_notes" name="observatii" value="<?= e($serviceKmValue('observatii')) ?>" data-service-km-field="observatii">
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary" data-service-km-submit><?= $serviceKmValue('id') !== '' ? 'Salvează modificarea' : 'Adaugă km service' ?></button>
                        <button type="button" class="btn btn-outline-secondary" data-service-km-reset>Renunță</button>
                    </div>
                </form>
            </div>
        </div>
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
                        <th scope="col">Service / motiv</th>
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
                        ], JSON_UNESCAPED_UNICODE);
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
                            <td>
                                <?= e((string) ($entry['service_nume'] ?? '')) ?>
                                <?php if (!empty($entry['motiv'])): ?>
                                    <div class="text-muted small"><?= e((string) $entry['motiv']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($entry['observatii'])): ?>
                                    <div class="text-muted small fst-italic"><?= e((string) $entry['observatii']) ?></div>
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
                var now = new Date();
                values.data_intoarcere = now.getFullYear() + '-' + ('0' + (now.getMonth() + 1)).slice(-2) + '-' + ('0' + now.getDate()).slice(-2);
            }
            fill(values);
            openForm();
            var focusTarget = btn.getAttribute('data-service-km-return') === '1' ? field('km') : field('vehicle_id');
            if (focusTarget) { focusTarget.focus(); }
        });
    });

    form.querySelector('[data-service-km-reset]').addEventListener('click', function () {
        form.reset();
        fill({ id: '', vehicle_id: '', driver_id: '', data_intoarcere: '', ora_plecare: '', ora_intoarcere: '', km: '', service_nume: '', motiv: '', observatii: '' });
        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
    });

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
