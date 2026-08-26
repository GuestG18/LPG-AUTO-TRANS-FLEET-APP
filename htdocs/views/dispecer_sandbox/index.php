<?php
$credentialsAvailable = !empty($credentialsAvailable);
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$loadError = $loadError ?? null;
$prefillUrl = build_query_url(['page' => 'dispecer_sandbox', 'action' => 'prefill']);
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
                    <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?= (int) $vehicle['id'] ?>" data-driver="<?= e((string) ($vehicle['driver'] ?? '')) ?>">
                            <?= e((string) $vehicle['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
                <div class="col-12 col-md-4">
                    <label class="form-label text-muted small mb-1">Loc incarcare sugerat</label>
                    <input type="text" class="form-control" id="sf-loc-incarcare" readonly>
                </div>
                <div class="col-12 col-md-8">
                    <label class="form-label text-muted small mb-1">Raman de completat manual</label>
                    <input type="text" class="form-control" value="Cantitate incarcata, Tip transport, Tip marfa, Nr. clienti" readonly>
                </div>
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
                        <th class="text-end">Parcare inainte</th>
                    </tr>
                </thead>
                <tbody id="sandbox-segments"></tbody>
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
        el('sandbox-driver-hint').textContent = driver !== '' ? 'Sofer SAS: ' + driver : '';
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
            + (time !== '' ? '&time=' + encodeURIComponent(time) : '');

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

    function renderResult(data) {
        var s = data.suggestions || {};

        el('sf-ora-inceput').value = s.ora_inceput || '-';
        el('sf-data-incarcare').value = formatDate(s.data_incarcare);
        el('sf-sfarsit').value = s.data_sfarsit ? (formatDate(s.data_sfarsit) + ' ' + (s.ora_sfarsit || '')) : '-';
        el('sf-km-cursa').value = s.km_cursa !== null && s.km_cursa !== undefined ? s.km_cursa : '-';
        el('sf-km-totali').value = s.km_totali !== null && s.km_totali !== undefined ? s.km_totali : '-';
        el('sf-loc-incarcare').value = s.loc_incarcare ? s.loc_incarcare.nume : '-';

        var status = el('sandbox-trip-status');
        if (s.trip_finished && data.trip_close_reason === 'return_to_start') {
            status.className = 'badge text-bg-success';
            status.textContent = 'Cursa incheiata - revenire la plecare';
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
                        + '<i class="bi bi-flag-fill me-1"></i>Sfarsitul cursei - vehiculul a revenit in punctul de plecare. '
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
