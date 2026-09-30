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
// Coloana "Curse" deschide exact cursele numarate in Desfasurator curse (filtru ids).
$racesUrl = (!function_exists('can') || can('dispecer_curse')) ? build_query_url(['page' => 'dispecer_curse']) : '';
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

<form method="get" class="card border-0 shadow-sm mb-3" id="kmp-period-form">
    <input type="hidden" name="page" value="km_pierduti">
    <div class="card-body py-2 d-flex flex-wrap align-items-end gap-2">
        <div class="btn-group btn-group-sm" role="group" id="kmp-presets">
            <button type="button" class="btn btn-outline-secondary" data-preset="this-month">Luna aceasta</button>
            <button type="button" class="btn btn-outline-secondary" data-preset="prev-month">Luna trecută</button>
            <button type="button" class="btn btn-outline-secondary" data-preset="30d">Ultimele 30 zile</button>
        </div>
        <div>
            <label class="form-label small mb-0" for="kmp-start">De la</label>
            <input type="date" class="form-control form-control-sm" id="kmp-start" name="start" value="<?= e($periodStart) ?>" max="<?= e(date('Y-m-d')) ?>">
        </div>
        <div>
            <label class="form-label small mb-0" for="kmp-end">Până la</label>
            <input type="date" class="form-control form-control-sm" id="kmp-end" name="end" value="<?= e($periodEnd) ?>" max="<?= e(date('Y-m-d')) ?>">
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Aplică</button>
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
        <i class="bi bi-info-circle me-1"></i>Km pierduți = Km GPS reali − Km efectuați pe curse (Km totali / „Km efectuați” când e completat, altfel Km cursă) − Km service (drumuri la reparat, din Dispecer curse → Km service). „GPS − agreați” compară cu km facturați. O cursă intră în perioada în care s-a închis (data de sfârșit), ca în Dashboard Analitic V2. Valorile negative (curse înregistrate cu mai mulți km decât s-au rulat) pot apărea când o cursă închisă în interval a fost rulată parțial în perioada anterioară.
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
</style>

<script>
(function () {
    'use strict';

    var GPS_URL = <?= json_encode($gpsUrl, JSON_UNESCAPED_SLASHES) ?>;
    var VEHICLES = <?= $vehiclesJson ?>;
    var RACES_URL = <?= json_encode($racesUrl, JSON_UNESCAPED_SLASHES) ?>;
    var PERIOD_START = <?= json_encode($periodStart) ?>;
    var PERIOD_END = <?= json_encode($periodEnd) ?>;
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
                + '<td class="text-end">' + lostCell(v.lostc, pending) + '</td>'
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
    function loadGps() {
        var queue = VEHICLES.filter(function (v) { return v.has_gps; }).slice();
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

    // Presets perioada: seteaza inputurile si trimite formularul.
    function ymd(d) {
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }
    document.querySelectorAll('#kmp-presets [data-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var now = new Date(), start, end = now;
            var preset = btn.getAttribute('data-preset');
            if (preset === 'this-month') { start = new Date(now.getFullYear(), now.getMonth(), 1); }
            else if (preset === 'prev-month') { start = new Date(now.getFullYear(), now.getMonth() - 1, 1); end = new Date(now.getFullYear(), now.getMonth(), 0); }
            else { start = new Date(now.getTime() - 29 * 86400000); }
            $('kmp-start').value = ymd(start);
            $('kmp-end').value = ymd(end);
            $('kmp-period-form').submit();
        });
    });

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

    var initialTh = document.querySelector('.kmp-sort[data-sort="lostc"]');
    if (initialTh) { initialTh.classList.add('desc'); }
    render();
    renderKpis();
    loadGps();
})();
</script>

<?php endif; ?>
