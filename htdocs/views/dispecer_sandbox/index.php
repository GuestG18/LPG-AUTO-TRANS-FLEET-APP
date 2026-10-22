<?php
$credentialsAvailable = !empty($credentialsAvailable);
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$loadError = $loadError ?? null;
$pois = is_array($pois ?? null) ? $pois : [];
$deliveryParkDefaultMinutes = (int) ($deliveryParkDefaultMinutes ?? 60);
$prefillUrl = build_query_url(['page' => 'dispecer_sandbox', 'action' => 'prefill']);
$tripsRangeUrl = build_query_url(['page' => 'dispecer_sandbox', 'action' => 'trips_range']);
$raceEditUrl = build_query_url(['page' => 'dispecer_curse', 'action' => 'edit']);
?>

<div class="d-flex justify-content-between align-items-center mb-2">
    <div>
        <h1 class="h5 mb-0"><i class="bi bi-broadcast me-1"></i>Sandbox GPS curse</h1>
        <div class="text-muted small">Test precompletare cursa din SAS - nu modifica nicio cursa si nu atinge formularul real Dispecer curse.</div>
    </div>
    <span class="badge text-bg-warning">SANDBOX</span>
</div>

<?php if (!$credentialsAvailable): ?>
    <div class="alert alert-warning">
        Credentialele SAS nu sunt configurate. Completeaza <code>SAS_API_USERNAME</code> si
        <code>SAS_API_PASSWORD</code> in fisierul <code>.env</code>, apoi reincarca pagina.
    </div>
<?php endif; ?>

<?php if ($loadError !== null): ?>
    <div class="alert alert-danger py-2"><?= e((string) $loadError) ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header py-2 fw-semibold"><i class="bi bi-truck me-1"></i>Simulare inceput de cursa</div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label" for="sandbox-vehicle">Vehicul <span class="text-danger">*</span></label>
                <select class="form-select" id="sandbox-vehicle">
                    <option value="">-- Selecteaza --</option>
                    <?php
                    $vehiclesByGarage = [];
                    foreach ($vehicles as $vehicle) {
                        $vehiclesByGarage[mb_strtoupper((string) ($vehicle['garaj'] ?? ''))][] = $vehicle;
                    }
                    ?>
                    <?php foreach ($vehiclesByGarage as $garage => $garageVehicles): ?>
                        <optgroup label="Garaj <?= e((string) $garage) ?>">
                            <?php foreach ($garageVehicles as $vehicle): ?>
                                <option value="<?= (int) $vehicle['id'] ?>" data-driver="<?= e((string) ($vehicle['driver'] ?? '')) ?>" data-garage="<?= e((string) ($vehicle['garaj'] ?? '')) ?>" data-garage-trailer="<?= e((string) ($vehicle['garaj_din_semiremorca'] ?? '')) ?>">
                                    <?= e((string) $vehicle['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Doar vehiculele cu garaj in Vehicule grele; capul tractor cuplat ia garajul semiremorcii.</div>
                <div class="form-text" id="sandbox-driver-hint"></div>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="sandbox-date">Data inceput <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="sandbox-date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="sandbox-time">Ora inceput</label>
                <input type="time" class="form-control" id="sandbox-time">
                <div class="form-text">Gol = detectata din GPS</div>
            </div>
            <div class="col-12 col-md-3">
                <button type="button" class="btn btn-primary w-100" id="sandbox-run">
                    <i class="bi bi-magic me-1"></i>Completeaza din GPS
                </button>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-flag me-1"></i>Reguli de inchidere a cursei</span>
        <button type="button" class="btn btn-sm btn-link p-0" data-bs-toggle="collapse" data-bs-target="#sandbox-rules-body">Parcari beneficiar</button>
    </div>
    <div class="card-body py-2">
        <div class="small text-muted mb-2">
            Cursa se inchide la prima regula indeplinita:
            <strong>1.</strong> revenire in punctul de plecare (min. 10 km);
            <strong>2.</strong> dupa incarcare, sosire intr-o parcare de beneficiar bifata mai jos (min. 10 km de la incarcare),
            cu stationare de minim
            <input type="number" class="form-control form-control-sm d-inline-block" style="width:5rem" id="sandbox-park-minutes" min="0" step="15" value="<?= $deliveryParkDefaultMinutes ?>"> min;
            <strong>3.</strong> dupa incarcare si livrare (min. 50 km), sosire la un punct de incarcare (POI cu Cantar / Incarcare / Terminal in nume) - vine sa incarce pentru cursa urmatoare;
            <strong>4.</strong> altfel, ultima miscare urmata de stationare &ge; 2 h (estimare).
        </div>
        <div class="collapse" id="sandbox-rules-body">
            <div class="row row-cols-2 row-cols-md-4 g-1" id="sandbox-parking-pois">
                <?php foreach ($pois as $poi): ?>
                    <div class="col">
                        <div class="form-check small">
                            <input class="form-check-input" type="checkbox" value="<?= (int) $poi['id'] ?>" id="poi-<?= (int) $poi['id'] ?>" data-default="<?= !empty($poi['default_parking']) ? '1' : '0' ?>"<?= !empty($poi['default_parking']) ? ' checked' : '' ?>>
                            <label class="form-check-label" for="poi-<?= (int) $poi['id'] ?>"><?= e((string) $poi['name']) ?></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-text">Selectia se pastreaza doar in acest browser (sandbox).</div>
        </div>
    </div>
</div>

<div class="alert alert-danger d-none py-2" id="sandbox-error"></div>
<div class="d-none text-center py-4" id="sandbox-loading">
    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Se incarca...</span></div>
    <div class="text-muted small mt-2">Se interogheaza SAS...</div>
</div>

<div class="d-none" id="sandbox-results">
    <div class="d-none" id="sandbox-warnings"></div>

    <div class="card mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-ui-checks me-1"></i>Ce ar fi precompletat in formular</span>
            <span class="badge" id="sandbox-trip-status"></span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-2">
                    <label class="form-label text-muted small mb-1">Ora inceput</label>
                    <input type="text" class="form-control" id="sf-ora-inceput" readonly>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label text-muted small mb-1">Data incarcare</label>
                    <input type="text" class="form-control" id="sf-data-incarcare" readonly>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label text-muted small mb-1">Data + ora sfarsit</label>
                    <input type="text" class="form-control" id="sf-sfarsit" readonly>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label text-muted small mb-1">Km cursa</label>
                    <input type="text" class="form-control" id="sf-km-cursa" readonly>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label text-muted small mb-1">Km totali (odometru)</label>
                    <input type="text" class="form-control" id="sf-km-totali" readonly>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label text-muted small mb-1">Loc incarcare sugerat</label>
                    <input type="text" class="form-control" id="sf-loc-incarcare" readonly>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label text-muted small mb-1">Loc sfarsit (stationare)</label>
                    <input type="text" class="form-control" id="sf-loc-sfarsit" readonly>
                </div>
                <div class="col-12 col-md-5">
                    <label class="form-label text-muted small mb-1">Raman de completat manual</label>
                    <input type="text" class="form-control" value="Cantitate incarcata, Tip transport, Tip marfa, Nr. clienti" readonly>
                </div>
            </div>
            <div class="mt-3 d-none" id="sandbox-next-wrap">
                <button type="button" class="btn btn-outline-primary btn-sm" id="sandbox-next">
                    <i class="bi bi-skip-forward me-1"></i>Cursa urmatoare (pleaca de la sfarsitul acesteia)
                </button>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2 fw-semibold"><i class="bi bi-speedometer2 me-1"></i>Sumar SAS pentru fereastra interogata</div>
        <div class="card-body py-2">
            <div class="row text-center" id="sandbox-summary"></div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-signpost-2 me-1"></i>Segmente GPS (foaia de parcurs)</span>
            <span class="text-muted small" id="sandbox-window"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Plecare</th>
                        <th>Sosire</th>
                        <th>De la</th>
                        <th>Pana la</th>
                        <th class="text-end">Km</th>
                        <th class="text-end" title="Stationarea dupa segment, pana la urmatoarea pornire (parkTimeSeconds din SAS)">Stationare dupa</th>
                    </tr>
                </thead>
                <tbody id="sandbox-segments"></tbody>
            </table>
        </div>
    </div>
</div>

<?php // Test API pe perioada: cursele detectate din GPS, inlantuite, in stilul Desfasurator curse. ?>
<div class="card border-0 shadow-sm mt-3 dispatcher-races-card" id="range-card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap dispatcher-races-card-header">
        <h3 class="h6 mb-0 d-flex align-items-center gap-2">
            <span class="dispatcher-races-tabs" role="tablist" aria-label="Curse detectate">
                <button type="button" class="dispatcher-races-tab is-active" role="tab" aria-selected="true" data-range-tab="gps">
                    Desfasurator GPS
                    <span class="badge rounded-pill text-bg-light" id="range-gps-count">0</span>
                </button>
                <button type="button" class="dispatcher-races-tab" role="tab" aria-selected="false" data-range-tab="unmatched" title="Curse din Dispecer curse fara cursa GPS corespunzatoare">
                    Curse reale fara pereche GPS
                    <span class="badge rounded-pill text-bg-light" id="range-unmatched-count">0</span>
                </button>
            </span>
        </h3>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <label class="small text-muted mb-0" for="range-from">De la</label>
            <input type="date" class="form-control form-control-sm" style="width:auto" id="range-from" value="<?= e(date('Y-m-01', strtotime('first day of -3 months'))) ?>" max="<?= e(date('Y-m-d')) ?>">
            <label class="small text-muted mb-0" for="range-to">Pana la</label>
            <input type="date" class="form-control form-control-sm" style="width:auto" id="range-to" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>">
            <div class="form-check form-check-inline small mb-0 ms-1">
                <input class="form-check-input" type="checkbox" id="range-show-manoeuvres">
                <label class="form-check-label" for="range-show-manoeuvres">Arata manevrele (&lt; 10 km)</label>
            </div>
            <button type="button" class="btn btn-sm btn-primary" id="range-run">
                <i class="bi bi-search me-1"></i>Detecteaza curse
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="px-3 py-2 small text-muted border-bottom" id="range-hint">
            Alege vehiculul din „Simulare inceput de cursa” si perioada, apoi „Detecteaza curse”.
            Fiecare cursa pleaca de unde s-a inchis precedenta, cu regulile de inchidere de mai sus.
        </div>
        <div class="alert alert-danger border-0 rounded-0 mb-0 d-none py-2" id="range-error"></div>
        <div class="d-none text-center py-4" id="range-loading">
            <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Se incarca...</span></div>
            <div class="text-muted small mt-2">Se citeste foaia de parcurs SAS luna cu luna...</div>
        </div>
        <div class="d-none row text-center g-0 border-bottom py-2" id="range-summary"></div>

        <div class="table-responsive dispatcher-races-table-wrap table-container" data-range-pane="gps">
            <table class="table table-hover align-middle mb-0 dispatcher-races-table curse-table table-dispecer">
                <thead>
                <tr>
                    <th>Nr. Înmatriculare</th>
                    <th>Sofer</th>
                    <th>Inchisa prin</th>
                    <th class="text-center">Data incarcare</th>
                    <th>Interval</th>
                    <th class="text-end">Durata cursa</th>
                    <th>Traseu</th>
                    <th>Loc incarcare</th>
                    <th class="text-end">Km GPS</th>
                    <th>Cursa reala</th>
                    <th class="text-end">Km reali</th>
                    <th class="text-end">Diferenta</th>
                </tr>
                </thead>
                <tbody id="range-trips">
                    <tr><td colspan="12" class="text-center text-muted py-3">Nicio interogare inca.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="table-responsive d-none" data-range-pane="unmatched">
            <table class="table table-hover align-middle mb-0 dispatcher-races-table curse-table table-dispecer">
                <thead>
                <tr>
                    <th>Cursa</th>
                    <th>Sofer</th>
                    <th>Tip transport</th>
                    <th class="text-center">Data incarcare</th>
                    <th>Plecare</th>
                    <th>Sosire</th>
                    <th>Beneficiar</th>
                    <th>Loc incarcare</th>
                    <th class="text-end">Km cursa</th>
                    <th class="text-end">Km totali</th>
                </tr>
                </thead>
                <tbody id="range-unmatched"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var prefillUrl = <?= json_encode($prefillUrl, JSON_UNESCAPED_SLASHES) ?>;
    var el = function (id) { return document.getElementById(id); };

    el('sandbox-vehicle').addEventListener('change', function () {
        var option = this.options[this.selectedIndex];
        var driver = option ? (option.getAttribute('data-driver') || '') : '';
        var garage = option ? (option.getAttribute('data-garage') || '') : '';
        var garageTrailer = option ? (option.getAttribute('data-garage-trailer') || '') : '';
        el('sandbox-driver-hint').textContent = [
            garage !== '' ? 'Garaj: ' + garage + (garageTrailer !== '' ? ' (din semiremorca ' + garageTrailer + ')' : '') : '',
            driver !== '' ? 'Sofer SAS: ' + driver : ''
        ].filter(Boolean).join(' · ');
    });

    var storageKey = 'dispecer_sandbox_closing_rules';
    var parkingBoxes = Array.prototype.slice.call(document.querySelectorAll('#sandbox-parking-pois input[type=checkbox]'));
    var lastResult = null;

    try {
        var saved = JSON.parse(window.localStorage.getItem(storageKey) || 'null');
        if (saved && Array.isArray(saved.parking)) {
            parkingBoxes.forEach(function (box) { box.checked = saved.parking.indexOf(Number(box.value)) !== -1; });
        }
        if (saved && saved.minutes !== undefined) { el('sandbox-park-minutes').value = saved.minutes; }
    } catch (e) { /* fara localStorage: raman implicitele */ }

    function saveRules() {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify({
                parking: selectedParkingIds().map(Number),
                minutes: el('sandbox-park-minutes').value
            }));
        } catch (e) { /* ignorat */ }
    }
    parkingBoxes.forEach(function (box) { box.addEventListener('change', saveRules); });
    el('sandbox-park-minutes').addEventListener('change', saveRules);

    function selectedParkingIds() {
        return parkingBoxes.filter(function (box) { return box.checked; }).map(function (box) { return box.value; });
    }

    el('sandbox-next').addEventListener('click', function () {
        var s = lastResult ? (lastResult.suggestions || {}) : {};
        if (!s.data_sfarsit || !s.ora_sfarsit) { return; }
        el('sandbox-date').value = s.data_sfarsit;
        el('sandbox-time').value = s.ora_sfarsit;
        el('sandbox-run').click();
    });

    el('sandbox-run').addEventListener('click', function () {
        var vehicleId = el('sandbox-vehicle').value;
        var date = el('sandbox-date').value;
        var time = el('sandbox-time').value;

        hide('sandbox-error');
        hide('sandbox-results');

        if (vehicleId === '' || date === '') {
            showError('Selecteaza vehiculul si data de inceput.');
            return;
        }

        show('sandbox-loading');
        el('sandbox-run').disabled = true;

        var url = prefillUrl + '&vehicle_id=' + encodeURIComponent(vehicleId)
            + '&date=' + encodeURIComponent(date)
            + (time !== '' ? '&time=' + encodeURIComponent(time) : '')
            + (parkingBoxes.length > 0 ? '&parking_poi_ids=' + encodeURIComponent(selectedParkingIds().join(',')) : '')
            + '&park_minutes=' + encodeURIComponent(el('sandbox-park-minutes').value || '0');

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) { throw new Error(data.error || ('Eroare HTTP ' + response.status)); }
                    return data;
                });
            })
            .then(renderResult)
            .catch(function (error) { showError(error.message || 'Interogarea a esuat.'); })
            .finally(function () {
                hide('sandbox-loading');
                el('sandbox-run').disabled = false;
            });
    });

    // ---- Test API pe perioada (Desfasurator GPS) ----
    var tripsRangeUrl = <?= json_encode($tripsRangeUrl, JSON_UNESCAPED_SLASHES) ?>;
    var raceEditUrl = <?= json_encode($raceEditUrl, JSON_UNESCAPED_SLASHES) ?>;
    var reasonBadges = {
        return_to_start: ['text-bg-success', 'Revenire la plecare'],
        delivery_parking: ['text-bg-primary', 'Parcare beneficiar'],
        next_loading: ['text-bg-info', 'Sosire la urmatoarea incarcare'],
        long_park: ['text-bg-warning', 'Stationare lunga (estimat)'],
        window_cut: ['text-bg-danger', 'Neinchisa in 7 zile'],
        in_progress: ['text-bg-secondary', 'In desfasurare']
    };
    var lastRange = null;

    Array.prototype.forEach.call(document.querySelectorAll('[data-range-tab]'), function (tab) {
        tab.addEventListener('click', function () {
            var name = tab.getAttribute('data-range-tab');
            Array.prototype.forEach.call(document.querySelectorAll('[data-range-tab]'), function (other) {
                var active = other === tab;
                other.classList.toggle('is-active', active);
                other.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            Array.prototype.forEach.call(document.querySelectorAll('[data-range-pane]'), function (pane) {
                pane.classList.toggle('d-none', pane.getAttribute('data-range-pane') !== name);
            });
        });
    });

    el('range-show-manoeuvres').addEventListener('change', function () {
        if (lastRange) { renderRange(lastRange); }
    });

    el('range-run').addEventListener('click', function () {
        var vehicleId = el('sandbox-vehicle').value;
        var from = el('range-from').value;
        var to = el('range-to').value;
        hide('range-error');
        if (vehicleId === '' || from === '' || to === '') {
            showRangeError('Selecteaza vehiculul (sus, in „Simulare inceput de cursa”) si perioada.');
            return;
        }

        show('range-loading');
        hide('range-summary');
        el('range-run').disabled = true;
        var url = tripsRangeUrl + '&vehicle_id=' + encodeURIComponent(vehicleId)
            + '&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to)
            + (parkingBoxes.length > 0 ? '&parking_poi_ids=' + encodeURIComponent(selectedParkingIds().join(',')) : '')
            + '&park_minutes=' + encodeURIComponent(el('sandbox-park-minutes').value || '0');

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok) { throw new Error(data.error || ('Eroare HTTP ' + response.status)); }
                    return data;
                });
            })
            .then(function (data) { lastRange = data; renderRange(data); })
            .catch(function (error) { showRangeError(error.message || 'Interogarea a esuat.'); })
            .finally(function () {
                hide('range-loading');
                el('range-run').disabled = false;
            });
    });

    function renderRange(data) {
        var showManoeuvres = el('range-show-manoeuvres').checked;
        var plate = data.vehicle ? data.vehicle.nr_inmatriculare : '';
        var t = data.totals || {};

        el('range-summary').innerHTML = [
            summaryCell('Curse GPS', t.gps_trips, ''),
            summaryCell('Km GPS (curse)', t.gps_km, 'km'),
            summaryCell('Curse reale', t.real_trips, ''),
            summaryCell('Km totali reali', t.real_km_totali, 'km'),
            summaryCell('Curse potrivite', (t.matched || 0) + ' / ' + (t.real_trips || 0), '')
        ].join('');
        show('range-summary');
        el('range-hint').textContent = plate + ' · ' + formatDate(data.range.from) + ' – ' + formatDateTime(data.range.to)
            + ' · regulile de inchidere de mai sus · click pe „detalii” pentru opriri.';
        el('range-gps-count').textContent = t.gps_trips || 0;
        el('range-unmatched-count').textContent = (data.unmatched_real || []).length;
        el('range-unmatched-count').className = 'badge rounded-pill ' + ((data.unmatched_real || []).length > 0 ? 'text-bg-warning' : 'text-bg-light');

        var trips = (data.trips || []).filter(function (trip) { return showManoeuvres || !trip.is_manoeuvre; });
        // Cel mai recent sus, ca in Desfasurator curse.
        trips = trips.slice().reverse();
        el('range-trips').innerHTML = trips.length === 0
            ? '<tr><td colspan="12" class="text-center text-muted py-3">Nicio cursa detectata in perioada.</td></tr>'
            : trips.map(function (trip, index) { return rangeRow(trip, index, plate); }).join('');

        Array.prototype.forEach.call(el('range-trips').querySelectorAll('[data-range-toggle]'), function (button) {
            button.addEventListener('click', function () {
                var detail = document.getElementById(button.getAttribute('data-range-toggle'));
                if (!detail) { return; }
                var open = detail.classList.toggle('d-none') === false;
                Array.prototype.forEach.call(el('range-trips').querySelectorAll('[data-range-toggle="' + detail.id + '"]'), function (b) {
                    b.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            });
        });

        var unmatched = data.unmatched_real || [];
        el('range-unmatched').innerHTML = unmatched.length === 0
            ? '<tr><td colspan="10" class="text-center text-muted py-3">Toate cursele reale au o cursa GPS corespunzatoare.</td></tr>'
            : unmatched.map(function (real) {
                return '<tr>'
                    + '<td>' + realLink(real) + '</td>'
                    + '<td>' + escapeHtml(real.sofer || '-') + '</td>'
                    + '<td>' + escapeHtml(real.tip_transport || '-') + '</td>'
                    + '<td class="text-center">' + formatDate(real.data_incarcare) + '</td>'
                    + '<td class="text-nowrap">' + formatDateTime(real.start) + '</td>'
                    + '<td class="text-nowrap">' + (real.end ? formatDateTime(real.end) : '-') + '</td>'
                    + '<td>' + escapeHtml(real.beneficiar || '-') + '</td>'
                    + '<td>' + escapeHtml(real.loc_incarcare || '-') + '</td>'
                    + '<td class="text-end">' + numberOrDash(real.km_cursa) + '</td>'
                    + '<td class="text-end">' + numberOrDash(real.km_totali) + '</td>'
                    + '</tr>';
            }).join('');
    }

    function rangeRow(trip, index, plate) {
        var real = trip.real;
        var detailId = 'range-detail-' + index;
        var reason = reasonBadges[trip.close_reason || 'in_progress'] || reasonBadges.in_progress;
        var diff = (real && real.km_totali !== null && real.km_totali !== undefined) ? trip.km - real.km_totali : null;
        var diffClass = diff === null ? 'text-muted' : (Math.abs(diff) <= 10 ? 'text-success' : 'text-danger fw-semibold');
        var rowClass = trip.is_manoeuvre ? 'text-muted opacity-75' : '';

        var html = '<tr class="' + rowClass + '">'
            + '<td class="fw-semibold text-nowrap">' + escapeHtml(plate) + '</td>'
            + '<td>' + escapeHtml(real && real.sofer ? real.sofer : '-') + '</td>'
            + '<td><span class="badge ' + reason[0] + '">' + (trip.is_manoeuvre ? 'Manevra' : reason[1]) + '</span></td>'
            + '<td class="text-center">' + formatDate(trip.data_incarcare) + '</td>'
            + '<td>' + detailsButton(detailId, '2 detalii', 'interval') + '</td>'
            + '<td class="text-end text-nowrap">' + formatMinutesLong(trip.duration_minutes) + '</td>'
            + '<td>' + detailsButton(detailId, trip.stops.length + (trip.stops.length === 1 ? ' oprire' : ' opriri'), 'traseu') + '</td>'
            + '<td>' + escapeHtml(trip.loc_incarcare || '-') + '</td>'
            + '<td class="text-end fw-semibold">' + trip.km + '</td>'
            + '<td>' + (real ? realLink(real) + (trip.real_repeated ? ' <span class="badge text-bg-warning" title="Aceeasi cursa reala ca la alta cursa GPS">repetata</span>' : '') : '<span class="text-muted">-</span>') + '</td>'
            + '<td class="text-end">' + (real ? numberOrDash(real.km_totali) : '-') + '</td>'
            + '<td class="text-end ' + diffClass + '">' + (diff === null ? '-' : (diff > 0 ? '+' : '') + diff) + '</td>'
            + '</tr>';

        var stops = trip.stops.map(function (stop) {
            return '<li class="d-flex gap-3"><span class="text-nowrap text-muted">' + formatDateTime(stop.at) + '</span>'
                + '<span class="flex-grow-1">' + (stop.poi ? '<i class="bi bi-geo-alt text-primary me-1"></i>' : '') + escapeHtml(stop.place) + '</span>'
                + '<span class="text-nowrap">' + stop.km.toFixed(1) + ' km</span></li>';
        }).join('');
        html += '<tr class="d-none table-light" id="' + detailId + '"><td colspan="12">'
            + '<div class="row g-3 small">'
            + '<div class="col-12 col-md-4"><div class="fw-semibold mb-1">Interval</div>'
            + '<div>Plecare: <strong>' + formatDateTime(trip.start) + '</strong> din ' + escapeHtml(trip.from) + '</div>'
            + '<div>Sfarsit (sosire): <strong>' + (trip.end ? formatDateTime(trip.end) : 'in desfasurare') + '</strong>'
            + (trip.end_place ? ' la ' + escapeHtml(trip.end_place) : '') + '</div>'
            + (trip.yard_end ? '<div class="text-muted">Ultima miscare in curte (asteptare / cantar / descarcare): ' + formatDateTime(trip.yard_end) + '</div>' : '')
            + (trip.end_park_minutes !== null && trip.end_park_minutes !== undefined ? '<div>Stationare dupa sosire: ' + formatMinutes(trip.end_park_minutes) + '</div>' : '')
            + (real ? '<div class="mt-2 text-muted">Cursa reala #' + real.id + ': ' + formatDateTime(real.start) + ' – ' + (real.end ? formatDateTime(real.end) : '?')
                + ' · ' + escapeHtml(real.beneficiar || '-') + ' · ' + escapeHtml(real.tip_marfa || '-') + '</div>' : '')
            + '</div>'
            + '<div class="col-12 col-md-8"><div class="fw-semibold mb-1">Traseu (opriri dupa fiecare segment &ge; 0,5 km)</div>'
            + '<ul class="list-unstyled mb-0">' + (stops || '<li class="text-muted">-</li>') + '</ul></div>'
            + '</div></td></tr>';

        return html;
    }

    function detailsButton(detailId, label, what) {
        return '<button type="button" class="dispatcher-summary-count-btn" data-range-toggle="' + detailId + '" aria-expanded="false" aria-controls="' + detailId + '" aria-label="Afiseaza ' + what + '">'
            + '<span>' + escapeHtml(label) + '</span><i class="bi bi-chevron-down" aria-hidden="true"></i></button>';
    }

    function realLink(real) {
        return '<a href="' + raceEditUrl + '&id=' + encodeURIComponent(real.id) + '" target="_blank" rel="noopener">#' + real.id + '</a>'
            + ' <span class="text-muted small text-nowrap">' + formatDateTime(real.start) + '</span>';
    }

    function numberOrDash(value) {
        return value === null || value === undefined ? '-' : String(value);
    }

    function formatMinutesLong(minutes) {
        if (minutes === null || minutes === undefined) { return '-'; }
        return Math.floor(minutes / 60) + 'h ' + String(minutes % 60).padStart(2, '0') + 'm';
    }

    function showRangeError(message) {
        var box = el('range-error');
        box.textContent = message;
        box.classList.remove('d-none');
    }

    function renderResult(data) {
        var s = data.suggestions || {};
        lastResult = data;

        el('sf-loc-sfarsit').value = s.loc_sfarsit
            ? s.loc_sfarsit + (s.stationare_sfarsit_min !== null && s.stationare_sfarsit_min !== undefined ? ' (' + formatMinutes(s.stationare_sfarsit_min) + ')' : '')
            : '-';
        el('sandbox-next-wrap').classList.toggle('d-none', !(s.trip_finished && s.data_sfarsit && s.ora_sfarsit));

        el('sf-ora-inceput').value = s.ora_inceput || '-';
        el('sf-data-incarcare').value = formatDate(s.data_incarcare);
        el('sf-sfarsit').value = s.data_sfarsit
            ? (formatDate(s.data_sfarsit) + ' ' + (s.ora_sfarsit || '')
                + (s.ultima_miscare_curte ? ' (curte pana la ' + s.ultima_miscare_curte.substring(11, 16) + ')' : ''))
            : '-';
        el('sf-km-cursa').value = s.km_cursa !== null && s.km_cursa !== undefined ? s.km_cursa : '-';
        el('sf-km-totali').value = s.km_totali !== null && s.km_totali !== undefined ? s.km_totali : '-';
        el('sf-loc-incarcare').value = s.loc_incarcare ? s.loc_incarcare.nume : '-';

        var status = el('sandbox-trip-status');
        if (s.trip_finished && data.trip_close_reason === 'return_to_start') {
            status.className = 'badge text-bg-success';
            status.textContent = 'Cursa incheiata - revenire la plecare';
        } else if (s.trip_finished && data.trip_close_reason === 'delivery_parking') {
            status.className = 'badge text-bg-primary';
            status.textContent = 'Cursa incheiata - parcare beneficiar';
        } else if (s.trip_finished && data.trip_close_reason === 'next_loading') {
            status.className = 'badge text-bg-info';
            status.textContent = 'Cursa incheiata - sosire la urmatoarea incarcare';
        } else if (s.trip_finished) {
            status.className = 'badge text-bg-success';
            status.textContent = 'Cursa incheiata - stationare lunga';
        } else {
            status.className = 'badge text-bg-warning';
            status.textContent = 'In desfasurare / fara miscare';
        }

        var summary = data.sas_summary || {};
        el('sandbox-summary').innerHTML = [
            summaryCell('Distanta totala', summary.total_distance_km, 'km'),
            summaryCell('Viteza medie', summary.average_speed, 'km/h'),
            summaryCell('Ore functionare', summary.work_hours, 'h'),
            summaryCell('Ore stationare motor pornit', summary.idle_hours, 'h'),
            summaryCell('Combustibil CAN', summary.can_fuel_used_l, 'L')
        ].join('');

        el('sandbox-window').textContent = data.window ? ('Fereastra: ' + data.window.start + ' - ' + data.window.end) : '';

        var separatorShown = false;
        var rows = (data.segments || []).map(function (segment) {
            var isMoving = segment.distance_km >= 0.5;
            var rowClass = isMoving ? '' : 'text-muted';
            var prefix = '';
            if (segment.after_trip) {
                rowClass = 'table-secondary opacity-50';
                if (!separatorShown) {
                    separatorShown = true;
                    prefix = '<tr class="table-success"><td colspan="7" class="text-center small fw-semibold">'
                        + '<i class="bi bi-flag-fill me-1"></i>Sfarsitul cursei - '
                        + (data.trip_close_reason === 'delivery_parking' ? 'vehiculul a stationat in parcarea beneficiarului. ' : (data.trip_close_reason === 'next_loading' ? 'vehiculul a ajuns la urmatorul punct de incarcare. ' : 'vehiculul a revenit in punctul de plecare. '))
                        + 'Segmentele de mai jos apartin cursei urmatoare.</td></tr>';
                }
            }
            return prefix + '<tr class="' + rowClass + '">'
                + '<td>' + segment.index + '</td>'
                + '<td class="text-nowrap">' + formatDateTime(segment.date_start) + '</td>'
                + '<td class="text-nowrap">' + formatDateTime(segment.date_end) + '</td>'
                + '<td>' + placeCell(segment.from, segment.start_poi) + '</td>'
                + '<td>' + placeCell(segment.to, segment.end_poi) + '</td>'
                + '<td class="text-end">' + (isMoving ? '<strong>' + segment.distance_km.toFixed(1) + '</strong>' : segment.distance_km.toFixed(1)) + '</td>'
                + '<td class="text-end">' + formatMinutes(segment.park_before_minutes) + '</td>'
                + '</tr>';
        });
        el('sandbox-segments').innerHTML = rows.length > 0
            ? rows.join('')
            : '<tr><td colspan="7" class="text-center text-muted py-3">Niciun segment GPS in fereastra selectata.</td></tr>';

        var warningsBox = el('sandbox-warnings');
        if ((data.warnings || []).length > 0) {
            warningsBox.innerHTML = data.warnings.map(function (warning) {
                return '<div class="alert alert-warning py-2 mb-2"><i class="bi bi-exclamation-triangle me-1"></i>' + escapeHtml(warning) + '</div>';
            }).join('');
            warningsBox.classList.remove('d-none');
        } else {
            warningsBox.classList.add('d-none');
        }

        show('sandbox-results');
    }

    function placeCell(label, poi) {
        var text = escapeHtml(label || 'necunoscut');
        return poi ? '<span class="badge text-bg-info-subtle text-dark border me-1"><i class="bi bi-geo-alt"></i></span>' + text : text;
    }

    function summaryCell(label, value, unit) {
        var display = (value === null || value === undefined) ? '-' : (value + ' ' + unit);
        return '<div class="col-6 col-md"><div class="fw-semibold">' + display + '</div><div class="text-muted small">' + label + '</div></div>';
    }

    function formatDate(value) {
        if (!value) { return '-'; }
        var parts = value.split('-');
        return parts.length === 3 ? parts[2] + '.' + parts[1] + '.' + parts[0] : value;
    }

    function formatDateTime(value) {
        if (!value) { return '-'; }
        var normalized = value.replace('T', ' ');
        return formatDate(normalized.substring(0, 10)) + ' ' + normalized.substring(11, 16);
    }

    function formatMinutes(minutes) {
        if (minutes === null || minutes === undefined) { return '-'; }
        if (minutes < 60) { return minutes + ' min'; }
        return Math.floor(minutes / 60) + 'h' + String(minutes % 60).padStart(2, '0');
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function show(id) { el(id).classList.remove('d-none'); }
    function hide(id) { el(id).classList.add('d-none'); }
    function showError(message) {
        var box = el('sandbox-error');
        box.textContent = message;
        box.classList.remove('d-none');
    }
})();
</script>
