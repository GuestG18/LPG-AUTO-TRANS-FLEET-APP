<?php
/**
 * Raport "Km pierduti": km reali GPS vs km acoperiti de curse, per vehicul.
 * Km GPS se incarca esalonat din frontend (o cerere per vehicul, cache pe disc).
 *
 * Asteapta: $credentialsAvailable, $vehicles, $periodStart, $periodEnd, $loadError.
 */
$credentialsAvailable = !empty($credentialsAvailable);
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$periodStart = (string) ($periodStart ?? date('Y-m-01'));
$periodEnd = (string) ($periodEnd ?? date('Y-m-d'));
$loadError = $loadError ?? null;
// Perioada trebuie trimisa explicit: fara ea, endpointul GPS cade pe luna curenta.
$gpsUrl = build_query_url(['page' => 'km_pierduti', 'action' => 'gps', 'start' => $periodStart, 'end' => $periodEnd]);
$detailUrl = build_query_url(['page' => 'km_pierduti', 'action' => 'detail', 'start' => $periodStart, 'end' => $periodEnd]);
// Coloana "Curse" deschide exact cursele numarate in Desfasurator curse (filtru ids).
$racesUrl = (!function_exists('can') || can('dispecer_curse')) ? build_query_url(['page' => 'dispecer_curse']) : '';
// Km neacoperiti -> "Adauga km service" / "Adauga cursa" in Dispecer, cu vehiculul si intervalul precompletate.
$canAddRace = $racesUrl !== '' && (!function_exists('can') || can('dispecer_curse', 'create'));
$canAddService = $racesUrl !== '' && (!function_exists('can') || can('dispecer_curse', 'service_km'));
// Km pierduti -> faza noua / km adaugati la o faza (sau cursa) existenta: fisa cursei precompletata.
$canAddPhase = $racesUrl !== '' && (!function_exists('can') || can('dispecer_curse', 'edit'));
$kmTripsUrl = $canAddPhase ? build_query_url(['page' => 'dispecer_curse', 'action' => 'insights_km_trips']) : '';
$vehiclesJson = json_encode($vehicles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($vehiclesJson)) {
    $vehiclesJson = '[]';
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h4 mb-0"><i class="bi bi-graph-down-arrow me-1"></i>Km pierduți</h2>
        <div class="text-muted small">Km reali (GPS) rulați dar neacoperiți de curse înregistrate — mașină exploatată fără venit.</div>
    </div>
</div>

<?php if (!$credentialsAvailable): ?>
    <div class="alert alert-warning">
        Credențialele SAS nu sunt configurate. Completează <code>SAS_API_USERNAME</code> și
        <code>SAS_API_PASSWORD</code> în <code>.env</code>, apoi reîncarcă pagina.
    </div>
<?php else: ?>

<?php if ($loadError !== null): ?>
    <div class="alert alert-danger py-2"><?= e((string) $loadError) ?></div>
<?php endif; ?>

<?php
// Eticheta campului Perioada, ca in Carburanti: luna intreaga = "Octombrie 2026".
$monthNamesRo = [1 => 'Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];
$periodStartObj = new DateTimeImmutable($periodStart);
$periodEndObj = new DateTimeImmutable($periodEnd);
$periodLabel = $periodStartObj->format('j') === '1'
    && $periodEndObj->format('Y-m-d') === $periodStartObj->modify('last day of this month')->format('Y-m-d')
    ? $monthNamesRo[(int) $periodStartObj->format('n')] . ' ' . $periodStartObj->format('Y')
    : $periodStartObj->format('d.m.Y') . ' – ' . $periodEndObj->format('d.m.Y');
?>
<form method="get" class="card border-0 shadow-sm mb-3 fuel-filter-card" id="kmp-period-form">
    <input type="hidden" name="page" value="km_pierduti">
    <div class="card-body py-2 d-flex flex-wrap align-items-end gap-2">
        <div style="min-width: 260px;">
            <label class="form-label" for="kmp-period-display">Perioadă</label>
            <div class="fuel-date-input">
                <input type="text" class="form-control" id="kmp-period-display"
                       value="<?= e($periodLabel) ?>" placeholder="Alege perioada" readonly>
                <i class="bi bi-calendar3" aria-hidden="true"></i>
            </div>
            <input type="hidden" name="start" id="kmp-start" value="<?= e($periodStart) ?>">
            <input type="hidden" name="end" id="kmp-end" value="<?= e($periodEnd) ?>">
        </div>
        <div class="ms-auto text-muted small align-self-center" id="kmp-progress"></div>
    </div>
</form>

<div class="row g-2 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 text-center">
            <div class="h4 mb-0" id="kmp-kpi-gps">–</div>
            <div class="text-muted small"><i class="bi bi-broadcast me-1"></i>Km GPS (real)</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 text-center">
            <div class="h4 mb-0" id="kmp-kpi-cursa">–</div>
            <div class="text-muted small"><i class="bi bi-signpost-2 me-1"></i>Km efectuați (curse + service)</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 border-start border-danger border-4"><div class="card-body py-2 text-center">
            <div class="h4 mb-0 text-danger" id="kmp-kpi-lost">–</div>
            <div class="text-muted small"><i class="bi bi-exclamation-triangle me-1"></i>Km pierduți (GPS − curse − service)</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-2 text-center">
            <div class="h4 mb-0" id="kmp-kpi-pct">–</div>
            <div class="text-muted small"><i class="bi bi-percent me-1"></i>Procent pierdut</div>
        </div></div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold">Vehicule <span class="text-muted" id="kmp-count"><?= count($vehicles) ?></span></span>
        <input type="search" class="form-control form-control-sm" id="kmp-search" placeholder="Caută nr. / model..." style="width: 200px;">
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" id="kmp-table">
            <thead class="table-light">
                <tr>
                    <th class="kmp-sort" data-sort="plate" role="button">Vehicul</th>
                    <th class="text-end kmp-sort" data-sort="nr" role="button">Curse</th>
                    <th class="text-end kmp-sort" data-sort="gps" role="button">Km GPS</th>
                    <th class="text-end kmp-sort" data-sort="efect" role="button" title="Km rulați pe curse: Km efectuați (Km totali când e completat, altfel Km efectuați / Km cursă)">Km efectuați</th>
                    <th class="text-end kmp-sort" data-sort="cursa" role="button" title="Km agreați / facturați (câmpul Km cursă)">Km agreați</th>
                    <th class="text-end kmp-sort" data-sort="service" role="button" title="Km la / de la service (Dispecer curse → Km service)">Km service</th>
                    <th class="text-end kmp-sort" data-sort="lostc" role="button" title="Km GPS − Km efectuați − Km service">Km pierduți</th>
                    <th class="text-end kmp-sort" data-sort="lostt" role="button" title="Km GPS − Km agreați (facturați) − Km service">GPS − agreați</th>
                </tr>
            </thead>
            <tbody id="kmp-rows"></tbody>
        </table>
    </div>
    <div class="card-footer bg-white text-muted small">
        <i class="bi bi-info-circle me-1"></i>Click pe valoarea din „Km pierduți” arată de unde vin: deplasările GPS din SAS puse pe cursele și drumurile la service din Dispecer.
        Km pierduți =Km GPS reali − Km efectuați pe curse (Km totali / „Km efectuați” când e completat, altfel Km cursă) − Km service (drumuri la reparat, din Dispecer curse → Km service). „GPS − agreați” compară cu km facturați. O cursă intră în perioada în care s-a închis (data de sfârșit), ca în Dashboard Analitic V2. Valorile negative (curse înregistrate cu mai mulți km decât s-au rulat) pot apărea când o cursă închisă în interval a fost rulată parțial în perioada anterioară.
    </div>
</div>

<div class="modal fade" id="kmp-detail-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="kmp-detail-title">Km pierduți</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body" id="kmp-detail-body"></div>
        </div>
    </div>
</div>

<style>
    .kmp-sort { cursor: pointer; white-space: nowrap; }
    .kmp-sort:hover { background: #eef2f7; }
    .kmp-sort.asc::after { content: ' \25B2'; font-size: .7em; }
    .kmp-sort.desc::after { content: ' \25BC'; font-size: .7em; }
    .kmp-lost-pos { color: #dc3545; font-weight: 600; }
    .kmp-lost-neg { color: #6c757d; }
    .kmp-pending { color: #adb5bd; }
    .kmp-races-link { display: inline-block; min-width: 2.2em; padding: 0 .4em; border-radius: .35rem; font-weight: 600; text-decoration: none; text-align: center; }
    .kmp-races-link:hover, .kmp-races-link:focus { background: #e7f1ff; text-decoration: underline; }
    #kmp-table td.text-end, #kmp-table th.text-end { font-variant-numeric: tabular-nums; }
    .kmp-lost-link { cursor: pointer; border-bottom: 1px dashed currentColor; }
    .kmp-lost-link:hover { background: #fdecee; }
    .kmp-split { display: flex; height: 10px; border-radius: 5px; overflow: hidden; background: #e9ecef; }
    .kmp-split > span { display: block; height: 100%; }
    .kmp-dot { display: inline-block; width: .7em; height: .7em; border-radius: 50%; margin-right: .35em; vertical-align: baseline; }
    .kmp-c-outside { background: #dc3545; }
    .kmp-c-trips { background: #fd7e14; }
    .kmp-c-service { background: #ffc107; }
    .kmp-c-other { background: #6c757d; }
    #kmp-detail-body table td, #kmp-detail-body table th { font-variant-numeric: tabular-nums; }
    .kmp-gap-toggle { cursor: pointer; }
    .kmp-gap-toggle:hover { background: #f8f9fa; }
    .kmp-gap-segments td { background: #fafbfc; font-size: .85em; }
    .kmp-assign-menu { position: absolute; z-index: 1060; width: 340px; max-width: calc(100% - 16px); }
    .kmp-assign-menu .list-group-item { font-size: .9rem; }
</style>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/ro.js"></script>
<script>
(function () {
    'use strict';

    var GPS_URL = <?= json_encode($gpsUrl, JSON_UNESCAPED_SLASHES) ?>;
    var VEHICLES = <?= $vehiclesJson ?>;
    var RACES_URL = <?= json_encode($racesUrl, JSON_UNESCAPED_SLASHES) ?>;
    var DETAIL_URL = <?= json_encode($detailUrl, JSON_UNESCAPED_SLASHES) ?>;
    var PERIOD_START = <?= json_encode($periodStart) ?>;
    var PERIOD_END = <?= json_encode($periodEnd) ?>;
    var CAN_ADD_RACE = <?= $canAddRace ? 'true' : 'false' ?>;
    var CAN_ADD_SERVICE = <?= $canAddService ? 'true' : 'false' ?>;
    var CAN_ADD_PHASE = <?= $canAddPhase ? 'true' : 'false' ?>;
    var KM_TRIPS_URL = <?= json_encode($kmTripsUrl, JSON_UNESCAPED_SLASHES) ?>;
    var CONCURRENCY = 3;
    var NF = new Intl.NumberFormat('ro-RO');

    var state = { sortKey: 'lostc', sortDir: 'desc', search: '' };

    function $(id) { return document.getElementById(id); }
    function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
    function fmt(n) { return n == null ? '–' : NF.format(Math.round(n)); }

    // Fiecare vehicul: gps null = neincarcat inca; are_gps false = fara carId (nu e pe SAS).
    VEHICLES.forEach(function (v) {
        v.gps = null;
        v.has_gps = (v.sas_vehicle_id || 0) > 0;
        v.lostc = null;
        v.lostt = null;
    });

    function computeLost(v) {
        if (v.gps == null) { v.lostc = null; v.lostt = null; return; }
        // Pierdut = km GPS neacoperiti de km efectuati pe curse; al doilea: fata de km agreati (facturati).
        v.lostc = v.gps - (v.km_efectuati || 0) - (v.km_service || 0);
        v.lostt = v.gps - (v.km_cursa || 0) - (v.km_service || 0);
    }

    function lostCell(value, pending) {
        if (pending) { return '<span class="kmp-pending">…</span>'; }
        if (value == null) { return '<span class="kmp-pending">-</span>'; }
        var cls = value > 0 ? 'kmp-lost-pos' : 'kmp-lost-neg';
        return '<span class="' + cls + '">' + fmt(value) + '</span>';
    }

    // Km pierduti ca link spre detaliere (de unde vin), doar cand avem GPS.
    function lostDetailCell(v, pending) {
        var html = lostCell(v.lostc, pending);
        if (pending || v.gps == null || !v.has_gps || Math.abs(v.lostc || 0) < 0.5) { return html; }
        return '<span class="kmp-lost-link" role="button" tabindex="0" data-detail="' + v.sas_vehicle_id
            + '" title="De unde vin acești km (deplasări GPS vs curse)">' + html + '</span>';
    }

    function roDate(value) {
        var p = String(value || '').split('-');
        return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : value;
    }

    // Numarul de curse ca link spre Desfasurator curse, filtrat exact pe cursele numarate.
    function racesCell(v) {
        var nr = v.nr_curse || 0;
        if (!RACES_URL || !v.curse_ids) { return String(nr); }
        var period = roDate(PERIOD_START) + ' – ' + roDate(PERIOD_END);
        var label = 'cursele ' + v.plate + ' din Km pierduți, închise în ' + period;
        var href = RACES_URL + '&ids=' + encodeURIComponent(v.curse_ids) + '&ids_label=' + encodeURIComponent(label);
        var title = 'Deschide cele ' + nr + ' curse ale ' + v.plate + ' închise în ' + period + ' în Desfășurător curse';
        return '<a class="kmp-races-link" href="' + esc(href) + '" title="' + esc(title) + '">' + nr + '</a>';
    }

    // Km service: vehiculul a stat la reparat in perioada (zilele apar in tooltip).
    function serviceCell(v) {
        if (!v.km_service && !v.zile_service) { return '<span class="kmp-pending">-</span>'; }
        var title = 'În service ' + (v.zile_service || 0) + ' zile în perioadă';
        return '<span title="' + esc(title) + '"><i class="bi bi-tools text-warning me-1"></i>' + fmt(v.km_service) + '</span>';
    }

    function matches(v) {
        if (state.search === '') { return true; }
        return ((v.plate || '') + ' ' + (v.label || '')).toLowerCase().indexOf(state.search) !== -1;
    }

    function sortValue(v, key) {
        switch (key) {
            case 'plate': return v.plate || '';
            case 'nr': return v.nr_curse || 0;
            case 'gps': return v.gps == null ? -1 : v.gps;
            case 'cursa': return v.km_cursa || 0;
            case 'efect': return v.km_efectuati || 0;
            case 'service': return v.km_service || 0;
            case 'lostc': return v.lostc == null ? -Infinity : v.lostc;
            case 'lostt': return v.lostt == null ? -Infinity : v.lostt;
            default: return 0;
        }
    }

    function render() {
        var rows = VEHICLES.filter(matches);
        var key = state.sortKey, dir = state.sortDir === 'asc' ? 1 : -1;
        rows.sort(function (a, b) {
            var av = sortValue(a, key), bv = sortValue(b, key);
            if (typeof av === 'string') { return dir * av.localeCompare(bv); }
            return dir * (av - bv);
        });

        $('kmp-count').textContent = rows.length + ' / ' + VEHICLES.length;
        $('kmp-rows').innerHTML = rows.map(function (v) {
            var pending = v.gps == null && v.has_gps;
            var gpsCell = !v.has_gps
                ? '<span class="kmp-pending" title="Vehicul fără GPS pe SAS">n/a</span>'
                : (pending ? '<span class="kmp-pending">…</span>' : fmt(v.gps) + ' <span class="text-muted small">km</span>');
            return '<tr>'
                + '<td class="fw-semibold">' + esc(v.plate) + (v.label ? ' <span class="text-muted small fw-normal">' + esc(v.label) + '</span>' : '') + '</td>'
                + '<td class="text-end">' + racesCell(v) + '</td>'
                + '<td class="text-end">' + gpsCell + '</td>'
                + '<td class="text-end">' + fmt(v.km_efectuati) + '</td>'
                + '<td class="text-end text-muted">' + fmt(v.km_cursa) + '</td>'
                + '<td class="text-end">' + serviceCell(v) + '</td>'
                + '<td class="text-end">' + lostDetailCell(v, pending) + '</td>'
                + '<td class="text-end">' + lostCell(v.lostt, pending) + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderKpis() {
        var gps = 0, cursa = 0, loaded = 0, tracked = 0;
        VEHICLES.forEach(function (v) {
            if (!v.has_gps) { return; }
            tracked++;
            cursa += (v.km_efectuati || 0) + (v.km_service || 0);
            if (v.gps != null) { gps += v.gps; loaded++; }
        });
        var lost = gps - cursa;
        $('kmp-kpi-gps').textContent = fmt(gps);
        $('kmp-kpi-cursa').textContent = fmt(cursa);
        $('kmp-kpi-lost').textContent = fmt(lost);
        $('kmp-kpi-pct').textContent = gps > 0 ? Math.round(lost / gps * 100) + '%' : '–';
        var progress = $('kmp-progress');
        progress.textContent = loaded < tracked
            ? 'se încarcă km GPS ' + loaded + ' / ' + tracked + '...'
            : (tracked > 0 ? 'km GPS încărcați complet' : '');
    }

    // Incarcare esalonata a km GPS (o cerere per vehicul cu carId), CONCURRENCY in paralel.
    // Intai, intr-o singura cerere, tot ce serverul are deja pe disc (fara SAS); apoi doar ce lipseste.
    function loadGps() {
        var tracked = VEHICLES.filter(function (v) { return v.has_gps; });
        fetch(GPS_URL.replace('action=gps', 'action=gps_cached') + '&car_ids=' + tracked.map(function (v) { return v.sas_vehicle_id; }).join(','), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .catch(function () { return {}; })
            .then(function (batch) {
                var cached = (batch && batch.cached) || {};
                var missing = [];
                tracked.forEach(function (v) {
                    var value = cached[String(v.sas_vehicle_id)];
                    if (value !== undefined) { v.gps = typeof value === 'number' ? value : 0; computeLost(v); } else { missing.push(v); }
                });
                render();
                renderKpis();
                loadMissingGps(missing);
            });
    }
    function loadMissingGps(queue) {
        function worker() {
            var v = queue.shift();
            if (!v) { return; }
            fetch(GPS_URL + '&car_id=' + v.sas_vehicle_id, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) { v.gps = (data && typeof data.total_km === 'number') ? data.total_km : 0; })
                .catch(function () { v.gps = 0; })
                .finally(function () { computeLost(v); render(); renderKpis(); worker(); });
        }
        for (var i = 0; i < CONCURRENCY; i++) { worker(); }
    }

    // Campul Perioada, ca in Carburanti: calendar de interval (flatpickr) cu
    // scurtaturi de luna; selectarea completa a intervalului aplica imediat.
    function ymd(d) {
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    (function initPeriodPicker() {
        var display = $('kmp-period-display');
        var startInput = $('kmp-start');
        var endInput = $('kmp-end');
        var form = $('kmp-period-form');
        var apply = function (start, end) {
            if (ymd(start) === startInput.value && ymd(end) === endInput.value) { return; }
            startInput.value = ymd(start);
            endInput.value = ymd(end);
            form.classList.add('is-reloading');
            form.submit();
        };

        if (!window.flatpickr) {
            // Fallback fara CDN: cele doua campuri devin calendare native vizibile.
            display.closest('.fuel-date-input').hidden = true;
            [startInput, endInput].forEach(function (input) {
                input.type = 'date';
                input.classList.add('form-control', 'mt-1');
                input.addEventListener('change', function () {
                    if (startInput.value && endInput.value && startInput.value <= endInput.value) { form.submit(); }
                });
            });
            return;
        }

        flatpickr(display, {
            mode: 'range',
            locale: window.flatpickr.l10ns && window.flatpickr.l10ns.ro ? 'ro' : 'default',
            dateFormat: 'Y-m-d',
            defaultDate: [startInput.value, endInput.value],
            onReady: function (selectedDates, dateStr, fp) {
                var presets = document.createElement('div');
                presets.className = 'fuel-fp-presets';
                [['Luna aceasta', 0], ['Luna trecută', 1]].forEach(function (preset) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = preset[0];
                    button.addEventListener('click', function () {
                        var now = new Date();
                        fp.setDate([
                            new Date(now.getFullYear(), now.getMonth() - preset[1], 1),
                            new Date(now.getFullYear(), now.getMonth() - preset[1] + 1, 0)
                        ], false);
                        fp.close();
                    });
                    presets.appendChild(button);
                });
                fp.calendarContainer.appendChild(presets);
            },
            onClose: function (selectedDates) {
                if (selectedDates.length === 2) {
                    apply(selectedDates[0], selectedDates[1]);
                }
                // flatpickr scrie "Y-m-d to Y-m-d"; pastram eticheta prietenoasa pana la reincarcare.
                display.value = display.defaultValue;
            }
        });
        display.value = display.defaultValue;
    })();

    document.querySelectorAll('.kmp-sort').forEach(function (th) {
        th.addEventListener('click', function () {
            var key = th.getAttribute('data-sort');
            if (state.sortKey === key) { state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc'; }
            else { state.sortKey = key; state.sortDir = (key === 'plate') ? 'asc' : 'desc'; }
            document.querySelectorAll('.kmp-sort').forEach(function (el) { el.classList.remove('asc', 'desc'); });
            th.classList.add(state.sortDir);
            render();
        });
    });

    $('kmp-search').addEventListener('input', function () { state.search = this.value.trim().toLowerCase(); render(); });

    // ---- Detaliere km pierduti (de unde vin) ----
    var TIP_LABELS = { primar: 'Primar', primar_tona: 'Primar / tonă', distributie: 'Distribuție', primar_distributie: 'Primar + Distribuție', compresor: 'Compresor' };

    function roDateTime(value) {
        var parts = String(value || '').split(' ');
        return roDate(parts[0]) + (parts[1] ? ' ' + parts[1] : '');
    }
    function km1(n) { return n == null ? '–' : new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 1 }).format(n); }
    function duration(from, to) {
        var ms = new Date(to.replace(' ', 'T')) - new Date(from.replace(' ', 'T'));
        if (!(ms > 0)) { return ''; }
        var h = Math.round(ms / 3600000);
        return h >= 48 ? Math.round(h / 24) + ' zile' : h + ' h';
    }
    function tripUrl(id) {
        return RACES_URL + '&ids=' + id + '&ids_label=' + encodeURIComponent('cursa #' + id + ' din Km pierduți');
    }
    function tripLink(id) {
        if (!RACES_URL) { return '#' + id; }
        return '<a href="' + esc(tripUrl(id)) + '" target="_blank" rel="noopener" data-assign-trip="' + id + '" title="Deschide cursa sau atribuie km neacoperiți">#' + id + '</a>';
    }
    function diffCell(value) {
        if (Math.abs(value) < 0.5) { return '<span class="text-muted">0</span>'; }
        return '<span class="' + (value > 0 ? 'kmp-lost-pos' : 'kmp-lost-neg') + '">' + (value > 0 ? '+' : '') + km1(value) + '</span>';
    }

    // Deplasarile sub 1 km (repozitionari, zgomot GPS) nu apar separat: km-ii lor se adauga la
    // deplasarea cea mai apropiata in timp din aceeasi perioada, care isi extinde intervalul.
    // Perioadele cu sub 1 km in total nu se listeaza. Doar afisare: totalurile raman cele din server.
    // Aceeasi regula ca in panoul Km pierduti din Dispecer curse (dispatcher-insights.js).
    var MIN_SEGMENT_KM = 1;
    function dtMs(value) { return Date.parse(String(value || '').replace(' ', 'T')) || 0; }
    function consolidateSegments(segments) {
        var list = segments.map(function (s) { return Object.assign({}, s, { km: Number(s.km) || 0, absorbed: 0, absorbedKm: 0 }); });
        while (list.length > 1) {
            var i = -1;
            list.forEach(function (s, k) { if (s.km < MIN_SEGMENT_KM && (i === -1 || s.km < list[i].km)) { i = k; } });
            if (i === -1) { break; }
            var tiny = list[i], prev = list[i - 1], next = list[i + 1];
            var toPrev = prev ? dtMs(tiny.start) - dtMs(prev.end) : Infinity;
            var toNext = next ? dtMs(next.start) - dtMs(tiny.end) : Infinity;
            var host = toPrev <= toNext ? prev : next;
            var first = host === prev ? prev : tiny, last = host === prev ? tiny : next;
            host.absorbed += 1 + tiny.absorbed;
            host.absorbedKm += tiny.km + tiny.absorbedKm;
            host.km += tiny.km;
            host.from = first.from;
            host.to = last.to;
            host.start = first.start;
            host.end = last.end;
            host.partial = host.partial || tiny.partial;
            list.splice(i, 1);
        }
        return list;
    }
    function consolidateDetail(d) {
        var all = (d.gaps || []).map(function (g) { return Object.assign({}, g, { segments: consolidateSegments(g.segments || []) }); });
        d.gaps = all.filter(function (g) { return Number(g.km) >= MIN_SEGMENT_KM; });
        d.hidden_gaps = all.length - d.gaps.length;
        d.hidden_gaps_km = all.reduce(function (s, g) { return s + (Number(g.km) >= MIN_SEGMENT_KM ? 0 : Number(g.km) || 0); }, 0);
        return d;
    }

    function renderDetail(v, d) {
        var b = d.breakdown;
        var parts = [
            { key: 'outside', cls: 'kmp-c-outside', label: 'Rulat fără cursă (între curse)', km: b.outside_km },
            { key: 'trips', cls: 'kmp-c-trips', label: 'Pe curse, peste km declarați', km: b.trips_over_km },
            { key: 'service', cls: 'kmp-c-service', label: 'La service, peste km declarați', km: b.service_over_km },
            { key: 'other', cls: 'kmp-c-other', label: 'Curse la marginea perioadei', km: b.other_km }
        ];
        var positive = parts.reduce(function (s, p) { return s + Math.max(0, p.km); }, 0) || 1;

        var html = '<div class="row g-2 mb-3 text-center">'
            + '<div class="col-6 col-md-3"><div class="border rounded py-2"><div class="h5 mb-0">' + km1(d.gps_km) + '</div><div class="small text-muted">Km GPS (SAS)</div></div></div>'
            + '<div class="col-6 col-md-3"><div class="border rounded py-2"><div class="h5 mb-0">' + km1(d.declared_trips_km) + '</div><div class="small text-muted">Km efectuați pe curse</div></div></div>'
            + '<div class="col-6 col-md-3"><div class="border rounded py-2"><div class="h5 mb-0">' + km1(d.declared_service_km) + '</div><div class="small text-muted">Km service</div></div></div>'
            + '<div class="col-6 col-md-3"><div class="border rounded py-2 border-danger"><div class="h5 mb-0 text-danger">' + km1(d.lost_km) + '</div><div class="small text-muted">Km pierduți</div></div></div>'
            + '</div>';

        html += '<div class="kmp-split mb-2">' + parts.map(function (p) {
            return p.km > 0 ? '<span class="' + p.cls + '" style="width:' + (p.km / positive * 100).toFixed(2) + '%" title="' + esc(p.label + ': ' + km1(p.km) + ' km') + '"></span>' : '';
        }).join('') + '</div>';
        html += '<div class="d-flex flex-wrap gap-3 small mb-3">' + parts.map(function (p) {
            return '<span><span class="kmp-dot ' + p.cls + '"></span>' + esc(p.label) + ': <strong>' + km1(p.km) + '</strong> km</span>';
        }).join('') + '</div>';

        // 1. Perioadele fara cursa.
        html += '<h6 class="mt-3"><span class="kmp-dot kmp-c-outside"></span>Perioade fără cursă în Dispecer, în care mașina a rulat</h6>';
        if (!d.gaps.length) {
            html += '<div class="text-muted small mb-3">Nicio deplasare GPS de peste 1 km în afara curselor / drumurilor la service.</div>';
        } else {
            html += '<div class="table-responsive"><table class="table table-sm align-middle mb-3"><thead class="table-light"><tr>'
                + '<th>De la</th><th>Până la</th><th>Între</th><th class="text-end">Deplasări</th><th class="text-end">Km GPS</th><th></th></tr></thead><tbody>';
            d.gaps.slice().sort(function (a, c) { return c.km - a.km; }).forEach(function (g, i) {
                var between = (g.after ? 'după ' + esc(g.after) : 'început perioadă') + ' → ' + (g.before ? 'înainte de ' + esc(g.before) : 'sfârșit perioadă');
                html += '<tr class="kmp-gap-toggle" data-gap="' + i + '" title="Arată deplasările">'
                    + '<td class="text-nowrap"><i class="bi bi-chevron-right small me-1"></i>' + esc(roDateTime(g.from)) + '</td>'
                    + '<td class="text-nowrap">' + esc(roDateTime(g.to)) + ' <span class="text-muted small">(' + duration(g.from, g.to) + ')</span></td>'
                    + '<td class="small">' + between + '</td>'
                    + '<td class="text-end">' + g.segments.length + '</td>'
                    + '<td class="text-end kmp-lost-pos">' + km1(g.km) + '</td>'
                    + '<td class="text-end">' + assignButton('data-assign-gap="' + i + '"', 'Atribuie') + '</td></tr>';
                html += '<tr class="kmp-gap-segments d-none" data-gap-rows="' + i + '"><td colspan="6"><table class="table table-sm mb-0"><tbody>'
                    + g.segments.map(function (s, j) {
                        var sameDay = String(s.start).split(' ')[0] === String(s.end).split(' ')[0];
                        return '<tr><td class="text-nowrap">' + esc(roDateTime(s.start)) + ' – ' + esc(sameDay ? (String(s.end).split(' ')[1] || '') : roDateTime(s.end)) + '</td>'
                            + '<td>' + esc(s.from || '?') + ' <i class="bi bi-arrow-right"></i> ' + esc(s.to || '?') + (s.partial ? ' <span class="badge text-bg-light">parțial</span>' : '') + '</td>'
                            + '<td class="text-end text-nowrap">' + km1(s.km) + ' km'
                            + (s.absorbed ? '<div class="small text-muted" title="Deplasări sub 1 km adăugate la aceasta: ' + km1(s.absorbedKm) + ' km">+' + s.absorbed + ' sub 1 km</div>' : '') + '</td>'
                            + '<td class="text-end">' + assignButton('data-assign-gap="' + i + '" data-assign-seg="' + j + '"', '') + '</td></tr>';
                    }).join('') + '</tbody></table></td></tr>';
            });
            html += '</tbody></table></div>';
        }
        if (d.hidden_gaps > 0) {
            html += '<div class="text-muted small mb-3"><i class="bi bi-eye-slash me-1"></i>' + d.hidden_gaps + (d.hidden_gaps === 1 ? ' perioadă' : ' perioade')
                + ' cu sub 1 km în total (' + km1(d.hidden_gaps_km) + ' km) nu ' + (d.hidden_gaps === 1 ? 'este afișată' : 'sunt afișate')
                + '. Deplasările sub 1 km sunt adăugate la cea mai apropiată deplasare.</div>';
        }

        // 2. Cursele: km GPS in fereastra cursei vs km declarati.
        html += '<h6 class="mt-3"><span class="kmp-dot kmp-c-trips"></span>Curse din Dispecer în perioadă — km GPS în timpul cursei vs km declarați</h6>';
        if (!d.trips.length) {
            html += '<div class="text-muted small mb-3">Nicio cursă înregistrată pe acest vehicul în perioadă.</div>';
        } else {
            html += '<div class="table-responsive"><table class="table table-sm align-middle mb-3"><thead class="table-light"><tr>'
                + '<th>Cursa</th><th>Tip / beneficiar</th><th>Plecare</th><th>Sosire</th><th class="text-end">Km declarați</th><th class="text-end">Km GPS</th><th class="text-end">GPS − declarați</th></tr></thead><tbody>';
            d.trips.forEach(function (t) {
                var notes = [];
                if (t.segment) { notes.push('segment ' + t.segment); }
                if (!t.counted) { notes.push('închisă după perioadă — nu intră în tabel'); }
                if (t.starts_before) { notes.push('începută înainte de perioadă — GPS doar din perioadă'); }
                html += '<tr' + (t.counted ? '' : ' class="text-muted"') + '>'
                    + '<td>' + tripLink(t.id) + '</td>'
                    + '<td class="small">' + esc(TIP_LABELS[t.tip] || t.tip) + (t.beneficiar ? '<br><span class="text-muted">' + esc(t.beneficiar) + '</span>' : '')
                    + (notes.length ? '<br><span class="badge text-bg-light fw-normal">' + esc(notes.join(' · ')) + '</span>' : '') + '</td>'
                    + '<td class="text-nowrap">' + esc(roDateTime(t.from)) + '</td>'
                    + '<td class="text-nowrap">' + esc(roDateTime(t.to)) + '</td>'
                    + '<td class="text-end">' + km1(t.declared_km) + '</td>'
                    + '<td class="text-end">' + km1(t.gps_km) + '</td>'
                    + '<td class="text-end">' + (t.counted && !t.starts_before ? diffCell(t.gps_km - t.declared_km) : '<span class="text-muted" title="Cursă la marginea perioadei">–</span>') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }

        // 3. Drumurile la service.
        if (d.services.length) {
            html += '<h6 class="mt-3"><span class="kmp-dot kmp-c-service"></span>Drumuri la service (Km service)</h6>'
                + '<div class="table-responsive"><table class="table table-sm align-middle mb-3"><thead class="table-light"><tr>'
                + '<th>Service</th><th>Plecare</th><th>Întoarcere</th><th class="text-end">Km declarați</th><th class="text-end">Km GPS</th><th class="text-end">GPS − declarați</th></tr></thead><tbody>';
            d.services.forEach(function (s) {
                html += '<tr' + (s.counted ? '' : ' class="text-muted"') + '>'
                    + '<td>' + esc(s.service_nume || 'service') + (s.open ? ' <span class="badge text-bg-warning">încă în service</span>' : '') + '</td>'
                    + '<td class="text-nowrap">' + esc(roDateTime(s.from)) + '</td>'
                    + '<td class="text-nowrap">' + (s.open ? '–' : esc(roDateTime(s.to))) + '</td>'
                    + '<td class="text-end">' + km1(s.declared_km) + '</td>'
                    + '<td class="text-end">' + km1(s.gps_km) + '</td>'
                    + '<td class="text-end">' + (s.counted && !s.starts_before ? diffCell(s.gps_km - s.declared_km) : '–') + '</td></tr>';
            });
            html += '</tbody></table></div>';
        }

        html += '<div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Fiecare deplasare GPS din SAS e pusă pe intervalul cursei (data + ora de plecare / sosire din Dispecer) sau al drumului la service în care a avut loc; '
            + 'ce cade în afara lor e rulat fără cursă. O cursă fără oră acoperă toată ziua. Deplasările care trec peste ora de plecare / sosire se împart proporțional cu timpul („parțial”). '
            + '„Curse la marginea perioadei”: cursele începute înainte de perioadă (km declarați includ și zilele dinainte, fără GPS aici — valoare negativă) sau închise după ea (au rulat în perioadă, dar în tabel intră în luna în care s-au închis — valoare pozitivă).</div>';

        $('kmp-detail-body').innerHTML = html;
        current = { v: v, d: d };
    }

    // ---- Km neacoperiti -> Km service sau cursa noua in Dispecer (formular precompletat) ----
    var current = null;
    function assignButton(attrs, label) {
        if (!CAN_ADD_RACE && !CAN_ADD_SERVICE && !CAN_ADD_PHASE) { return ''; }
        return '<button type="button" class="btn btn-sm btn-outline-primary py-0" ' + attrs + ' title="Adaugă ca Km service sau cursă în Dispecer">'
            + '<i class="bi bi-plus-lg"></i>' + (label ? ' ' + label : '') + '</button>';
    }
    function sortedGaps() { return current ? current.d.gaps.slice().sort(function (a, c) { return c.km - a.km; }) : []; }
    function splitDt(value) {
        var parts = String(value || '').split(' ');
        return { date: parts[0] || '', time: (parts[1] || '').slice(0, 5) };
    }
    function prefillUrl(kind, interval) {
        var from = splitDt(interval.from), to = splitDt(interval.to);
        var params = kind === 'service'
            ? { prefill: 'service', vehicle_id: current.v.vehicle_id, data_plecare: from.date, ora_plecare: from.time, data_intoarcere: to.date, ora_intoarcere: to.time }
            : { prefill: 'race', vehicle_id: current.v.vehicle_id, data_inceput: from.date, ora_inceput: from.time, data_sfarsit: to.date, ora_sfarsit: to.time };
        return RACES_URL + '&' + new URLSearchParams(params).toString() + (kind === 'service' ? '#service-km-panel' : '#add-race-form');
    }
    function closeAssignMenu() {
        var old = document.querySelector('.kmp-assign-menu');
        if (old) { old.remove(); }
    }
    // Faza: perioada sta intre doua curse; km-ii ei se pot pune pe cursa dinainte sau pe cea de dupa
    // (faza noua, adaugati la o faza existenta sau la cursa fara faze). Lista se incarca la cerere.
    function phaseItem(gap, interval) {
        if (!CAN_ADD_PHASE || !KM_TRIPS_URL || !gap || !(gap.after_trip_id || gap.before_trip_id)) { return ''; }
        return '<button type="button" class="list-group-item list-group-item-action" data-phase-load'
            + ' data-ids="' + esc([gap.after_trip_id, gap.before_trip_id].filter(Boolean).join(',')) + '" data-after="' + esc(gap.after_trip_id || '') + '"'
            + ' data-from="' + esc(interval.from) + '" data-to="' + esc(interval.to) + '" data-km="' + Math.max(1, Math.round(interval.km)) + '">'
            + '<i class="bi bi-signpost-split me-2 text-success"></i>Pune pe o <strong>fază</strong> a cursei…</button>';
    }
    function phaseHref(trip, faza, from, to, value) {
        var params = { kmp_from: String(from).slice(0, 16), kmp_to: String(to).slice(0, 16), kmp_km: String(value) };
        if (faza) { params.faza = String(faza); }
        if (faza === 'noua' && current && current.v.vehicle_id) { params.kmp_vehicle = String(current.v.vehicle_id); }
        return trip.edit_url + '&' + new URLSearchParams(params).toString() + '#race-form';
    }
    function loadPhaseItems(button) {
        var from = button.getAttribute('data-from'), to = button.getAttribute('data-to'), value = parseInt(button.getAttribute('data-km'), 10);
        var afterId = parseInt(button.getAttribute('data-after'), 10) || 0;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Se încarcă cursele…';
        fetch(KM_TRIPS_URL + '&ids=' + encodeURIComponent(button.getAttribute('data-ids')), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) { throw new Error(d && d.error ? d.error : 'Cursele nu au putut fi încărcate.'); }
                var link = function (href, label) { return '<a class="list-group-item list-group-item-action small ps-4" target="_blank" rel="noopener" href="' + esc(href) + '">' + label + '</a>'; };
                button.outerHTML = d.trips.map(function (t) {
                    var html = '<div class="list-group-item bg-light small"><i class="bi bi-signpost-split me-1 text-success"></i>Cursa <strong>#' + t.id + '</strong> ' + esc(t.beneficiar)
                        + ' · ' + (t.id === afterId ? 'dinaintea perioadei' : 'de după perioadă') + '<br>' + esc(roDateTime(t.from)) + ' → ' + esc(roDateTime(t.to)) + ' · ' + km1(t.km) + ' km'
                        + (t.billed ? ' · <span class="badge text-bg-secondary">facturată</span>' : '') + '</div>';
                    if (t.billed) { return html; }
                    html += link(phaseHref(t, 'noua', from, to, value), '<i class="bi bi-plus-lg me-2 text-success"></i>Fază nouă cu ' + value + ' km');
                    if (t.phases.length) {
                        html += t.phases.map(function (p) {
                            return link(phaseHref(t, p.id, from, to, value), '+' + value + ' km la <strong>Faza ' + p.nr + '</strong> <span class="text-muted">(' + esc(roDateTime(p.from)) + ' → ' + esc(roDateTime(p.to)) + ', ' + km1(p.km) + ' km)</span>');
                        }).join('');
                    } else {
                        html += link(phaseHref(t, '', from, to, value), '+' + value + ' km la cursă <span class="text-muted">(' + km1(t.km) + ' → ' + km1(t.km + value) + ' km)</span>');
                    }
                    return html;
                }).join('') || '<div class="list-group-item small text-muted">Cursele de lângă perioadă nu mai există.</div>';
            })
            .catch(function (err) {
                button.disabled = false;
                button.innerHTML = '<i class="bi bi-exclamation-triangle me-2 text-danger"></i>' + esc(err.message) + ' Reîncearcă.';
            });
    }
    function intervalBlock(interval, title, gap) {
        var html = '<div class="list-group-item bg-light small">' + esc(title) + '<br><strong>' + esc(roDateTime(interval.from)) + ' → ' + esc(roDateTime(interval.to)) + '</strong>'
            + ' · <span class="kmp-lost-pos">' + km1(interval.km) + ' km GPS</span></div>';
        if (CAN_ADD_SERVICE) {
            html += '<a class="list-group-item list-group-item-action" target="_blank" rel="noopener" href="' + esc(prefillUrl('service', interval)) + '">'
                + '<i class="bi bi-tools me-2 text-warning"></i>Adaugă în <strong>Km service</strong></a>';
        }
        if (CAN_ADD_RACE) {
            html += '<a class="list-group-item list-group-item-action" target="_blank" rel="noopener" href="' + esc(prefillUrl('race', interval)) + '">'
                + '<i class="bi bi-signpost-2 me-2 text-primary"></i><strong>Adaugă cursă</strong> în Dispecer curse</a>';
        }
        return html + phaseItem(gap, interval);
    }
    function openAssignMenu(anchor, content) {
        closeAssignMenu();
        var host = $('kmp-detail-modal').querySelector('.modal-content');
        var menu = document.createElement('div');
        menu.className = 'kmp-assign-menu list-group shadow';
        menu.innerHTML = content;
        host.appendChild(menu);
        var a = anchor.getBoundingClientRect(), h = host.getBoundingClientRect();
        menu.style.left = Math.max(8, Math.min(a.left - h.left, h.width - menu.offsetWidth - 8)) + 'px';
        // Fara loc dedesubt (randurile de jos ale modalului): meniul se deschide deasupra.
        var top = a.bottom - h.top + 4;
        if (top + menu.offsetHeight > h.height - 8) { top = Math.max(8, a.top - h.top - menu.offsetHeight - 4); }
        menu.style.top = top + 'px';
    }
    function tripMenu(anchor, tripId) {
        var html = '<a class="list-group-item list-group-item-action" target="_blank" rel="noopener" href="' + esc(tripUrl(tripId)) + '">'
            + '<i class="bi bi-box-arrow-up-right me-2"></i>Deschide cursa #' + tripId + '</a>';
        if (CAN_ADD_RACE || CAN_ADD_SERVICE || CAN_ADD_PHASE) {
            // Km neacoperiti de langa cursa: golul de dupa ea si cel dinaintea ei.
            var gaps = current.d.gaps.filter(function (g) { return g.after_trip_id === tripId || g.before_trip_id === tripId; });
            if (!gaps.length) {
                html += '<div class="list-group-item small text-muted">Nicio perioadă rulată fără cursă lângă această cursă.</div>';
            }
            gaps.forEach(function (g) {
                html += intervalBlock(g, g.after_trip_id === tripId ? 'Rulat fără cursă după #' + tripId + ':' : 'Rulat fără cursă înainte de #' + tripId + ':', g);
            });
        }
        openAssignMenu(anchor, html);
    }

    function openDetail(v) {
        $('kmp-detail-title').innerHTML = '<i class="bi bi-graph-down-arrow me-1"></i>Km pierduți — ' + esc(v.plate)
            + ' <span class="text-muted small fw-normal">' + esc(roDate(PERIOD_START)) + ' – ' + esc(roDate(PERIOD_END)) + '</span>';
        $('kmp-detail-body').innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2"></div>Se încarcă deplasările GPS din SAS...</div>';
        bootstrap.Modal.getOrCreateInstance($('kmp-detail-modal')).show();

        fetch(DETAIL_URL + '&car_id=' + v.sas_vehicle_id + '&vehicle_id=' + (v.vehicle_id || 0), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || d.error) { throw new Error(d && d.error ? d.error : 'Răspuns invalid'); }
                renderDetail(v, consolidateDetail(d));
            })
            .catch(function (err) {
                $('kmp-detail-body').innerHTML = '<div class="alert alert-danger mb-0">' + esc(err.message || 'Eroare la încărcare.') + '</div>';
            });
    }

    function findVehicle(carId) {
        for (var i = 0; i < VEHICLES.length; i++) { if (String(VEHICLES[i].sas_vehicle_id) === String(carId)) { return VEHICLES[i]; } }
        return null;
    }
    $('kmp-rows').addEventListener('click', function (e) {
        var link = e.target.closest('[data-detail]');
        if (link) { var v = findVehicle(link.getAttribute('data-detail')); if (v) { openDetail(v); } }
    });
    $('kmp-rows').addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') { return; }
        var link = e.target.closest('[data-detail]');
        if (link) { var v = findVehicle(link.getAttribute('data-detail')); if (v) { openDetail(v); } }
    });
    $('kmp-detail-modal').addEventListener('click', function (e) {
        if (!e.target.closest('.kmp-assign-menu, [data-assign-trip], [data-assign-gap]')) { closeAssignMenu(); }
        else if (e.target.closest('.kmp-assign-menu a')) { setTimeout(closeAssignMenu, 0); }
    });
    $('kmp-detail-modal').addEventListener('click', function (e) {
        var load = e.target.closest('.kmp-assign-menu [data-phase-load]');
        if (load && !load.disabled) { loadPhaseItems(load); }
    });
    $('kmp-detail-modal').addEventListener('hide.bs.modal', closeAssignMenu);
    $('kmp-detail-body').addEventListener('scroll', closeAssignMenu);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAssignMenu(); } });
    $('kmp-detail-body').addEventListener('click', function (e) {
        var trip = e.target.closest('[data-assign-trip]');
        if (trip && current) {
            // Ctrl/Shift-click deschide direct cursa, ca inainte.
            if (e.ctrlKey || e.metaKey || e.shiftKey) { return; }
            e.preventDefault();
            tripMenu(trip, parseInt(trip.getAttribute('data-assign-trip'), 10));
            return;
        }
        var assign = e.target.closest('[data-assign-gap]');
        if (assign && current) {
            var gap = sortedGaps()[parseInt(assign.getAttribute('data-assign-gap'), 10)];
            if (!gap) { return; }
            var segIndex = assign.getAttribute('data-assign-seg');
            if (segIndex !== null) {
                var s = gap.segments[parseInt(segIndex, 10)];
                openAssignMenu(assign, intervalBlock({ from: s.start, to: s.end, km: s.km }, 'Deplasare ' + (s.from || '?') + ' → ' + (s.to || '?') + ':', gap));
            } else {
                openAssignMenu(assign, intervalBlock(gap, 'Perioadă rulată fără cursă:', gap));
            }
            return;
        }
        var row = e.target.closest('.kmp-gap-toggle');
        if (!row) { return; }
        var seg = $('kmp-detail-body').querySelector('[data-gap-rows="' + row.getAttribute('data-gap') + '"]');
        if (seg) { seg.classList.toggle('d-none'); }
        var icon = row.querySelector('.bi');
        if (icon) { icon.classList.toggle('bi-chevron-right'); icon.classList.toggle('bi-chevron-down'); }
    });

    var initialTh = document.querySelector('.kmp-sort[data-sort="lostc"]');
    if (initialTh) { initialTh.classList.add('desc'); }
    render();
    renderKpis();
    loadGps();
})();
</script>

<?php endif; ?>
