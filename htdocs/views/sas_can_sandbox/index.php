<?php
$envAvailable = !empty($envAvailable);
$fetchUrl = build_query_url(['page' => 'sas_can_sandbox', 'action' => 'fetch']);
$today = date('Y-m-d');
?>
<style>
    .cansb-card { border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
    .cansb-kpi { padding: .75rem 1rem; }
    .cansb-kpi .label { font-size: .75rem; color: var(--bs-secondary-color); text-transform: uppercase; letter-spacing: .03em; }
    .cansb-kpi .value { font-size: 1.35rem; font-weight: 600; font-variant-numeric: tabular-nums; }
    .cansb-table td, .cansb-table th { white-space: nowrap; font-variant-numeric: tabular-nums; font-size: .85rem; }
    .cansb-table td.loc { white-space: normal; min-width: 12rem; }
    .cansb-json { max-height: 28rem; overflow: auto; font-size: .78rem; background: var(--bs-tertiary-bg); border-radius: .5rem; padding: .75rem; margin: 0; }
    .cansb-badge { font-size: .7rem; letter-spacing: .05em; }
</style>

<div class="d-flex align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-cpu me-2"></i>Km din CAN</h1>
    <span class="badge text-bg-warning cansb-badge">SANDBOX</span>
</div>
<p class="text-secondary small mb-3">
    Citește raportul web SAS „Foaie de parcurs cu distanțe din CAN” (odometrul de bord) și îl întoarce ca JSON.
    Credențialele se folosesc doar pentru cererea curentă: nu se salvează și nu se scriu în jurnale.
</p>

<form id="cansb-form" class="cansb-card p-3 mb-3" autocomplete="off">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label" for="cansb-user">Utilizator SAS</label>
            <input type="text" class="form-control" id="cansb-user" name="username" autocomplete="off"
                   placeholder="<?= $envAvailable ? 'gol = contul din .env' : 'utilizator' ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="cansb-pass">Parolă</label>
            <input type="password" class="form-control" id="cansb-pass" name="password" autocomplete="new-password">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="cansb-host">Server</label>
            <input type="text" class="form-control" id="cansb-host" name="host" placeholder="ex. www33.alarma.ro (gol = automat)">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="cansb-vehicle">Vehicul</label>
            <input type="text" class="form-control" id="cansb-vehicle" name="vehicle" list="cansb-cars" required
                   placeholder="B 105 NET sau carId">
            <datalist id="cansb-cars"></datalist>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="cansb-from">De la</label>
            <input type="datetime-local" class="form-control" id="cansb-from" name="from" value="<?= e($today) ?>T00:00" required>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="cansb-to">Până la</label>
            <input type="datetime-local" class="form-control" id="cansb-to" name="to" value="<?= e($today) ?>T23:59" required>
        </div>
        <div class="col-md-6 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-primary" id="cansb-submit">
                <i class="bi bi-cloud-download me-1"></i>Cere raportul
            </button>
            <span class="text-secondary small" id="cansb-status">Maxim 31 de zile pe cerere.</span>
        </div>
    </div>
</form>

<div id="cansb-error" class="alert alert-danger d-none"></div>

<div id="cansb-result" class="d-none">
    <div class="row g-2 mb-3" id="cansb-kpis"></div>

    <div class="cansb-card mb-3">
        <div class="p-3 pb-0 d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0" id="cansb-title">Tronsoane</h2>
            <span class="text-secondary small" id="cansb-meta"></span>
        </div>
        <div class="table-responsive p-2">
            <table class="table table-sm table-hover cansb-table mb-0">
                <thead>
                <tr>
                    <th>#</th><th>Pornire</th><th>Locație pornire</th><th>Oprire</th><th>Locație oprire</th>
                    <th class="text-end">Km GPS</th><th class="text-end">Index CAN pornire</th>
                    <th class="text-end">Index CAN oprire</th><th class="text-end">Km CAN</th><th class="text-end">CAN − GPS</th>
                </tr>
                </thead>
                <tbody id="cansb-rows"></tbody>
            </table>
        </div>
    </div>

    <div class="cansb-card p-3">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h2 class="h6 mb-0">JSON</h2>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="cansb-copy"><i class="bi bi-clipboard me-1"></i>Copiază</button>
        </div>
        <pre class="cansb-json" id="cansb-json"></pre>
    </div>
</div>

<script>
(() => {
    const form = document.getElementById('cansb-form');
    const submit = document.getElementById('cansb-submit');
    const status = document.getElementById('cansb-status');
    const errorBox = document.getElementById('cansb-error');
    const result = document.getElementById('cansb-result');
    const fetchUrl = <?= json_encode($fetchUrl) ?>;
    let lastJson = '';

    const km = (v, digits = 1) => v === null || v === undefined ? '—'
        : Number(v).toLocaleString('ro-RO', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmtDate = (s) => s ? s.slice(8, 10) + '.' + s.slice(5, 7) + ' ' + s.slice(11, 16) : '—';

    function fillCars(cars) {
        if (!Array.isArray(cars)) return;
        document.getElementById('cansb-cars').innerHTML = cars
            .map((c) => `<option value="${esc(c.plate)}">carId ${c.car_id}</option>`).join('');
    }

    function kpi(label, value) {
        return `<div class="col-6 col-md-3 col-xl"><div class="cansb-card cansb-kpi"><div class="label">${label}</div><div class="value">${value}</div></div></div>`;
    }

    function render(data) {
        const r = data.report, t = r.totals;
        const diff = t.can_km !== null && t.gps_km ? ((t.can_km - t.gps_km) / t.gps_km * 100) : null;
        document.getElementById('cansb-kpis').innerHTML = [
            kpi('Km CAN', km(t.can_km)),
            kpi('Km GPS', km(t.gps_km)),
            kpi('CAN față de GPS', diff === null ? '—' : (diff > 0 ? '+' : '') + diff.toFixed(1) + '%'),
            kpi('Index CAN început', km(t.can_index_start_km)),
            kpi('Index CAN sfârșit', km(t.can_index_end_km)),
            kpi('Motorină (L)', km(t.fuel_l, 2)),
            kpi('L/100 km', km(t.fuel_l_per_100km, 2)),
        ].join('');

        document.getElementById('cansb-title').textContent =
            `${r.plate ?? 'carId ' + r.car_id}${r.model ? ' · ' + r.model : ''} — ${t.segments} tronsoane` + (r.has_can ? '' : ' · fără date CAN');
        document.getElementById('cansb-meta').textContent =
            `${data.host} · cont ${data.source === 'env' ? '.env' : 'din formular'} · ${data.seconds} s`;

        document.getElementById('cansb-rows').innerHTML = r.segments.length ? r.segments.map((s) => {
            const d = s.can_km !== null && s.gps_km !== null ? s.can_km - s.gps_km : null;
            return `<tr>
                <td>${s.segment}</td><td>${fmtDate(s.start)}</td><td class="loc">${esc(s.start_location)}</td>
                <td>${fmtDate(s.end)}</td><td class="loc">${esc(s.end_location)}</td>
                <td class="text-end">${km(s.gps_km)}</td><td class="text-end">${km(s.can_index_start_km)}</td>
                <td class="text-end">${km(s.can_index_end_km)}</td><td class="text-end fw-semibold">${km(s.can_km)}</td>
                <td class="text-end ${d !== null && Math.abs(d) >= 1 ? 'text-danger' : 'text-secondary'}">${d === null ? '—' : (d > 0 ? '+' : '') + km(d)}</td>
            </tr>`;
        }).join('') : '<tr><td colspan="10" class="text-center text-secondary py-3">Nicio deplasare în interval.</td></tr>';

        lastJson = JSON.stringify(r, null, 2);
        document.getElementById('cansb-json').textContent = lastJson;
        result.classList.remove('d-none');
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        event.stopPropagation(); // garda globala anti-dublu-submit din app.js
        errorBox.classList.add('d-none');
        submit.disabled = true;
        status.textContent = 'Se cere raportul din SAS…';
        try {
            const response = await fetch(fetchUrl, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } });
            const data = await response.json().catch(() => ({ ok: false, message: 'Răspuns invalid (HTTP ' + response.status + ').' }));
            fillCars(data.cars);
            if (!data.ok) throw new Error(data.message || 'Cererea a eșuat.');
            render(data);
            status.textContent = `Gata în ${data.seconds} s.`;
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.classList.remove('d-none');
            status.textContent = 'Maxim 31 de zile pe cerere.';
        } finally {
            submit.disabled = false;
        }
    });

    document.getElementById('cansb-copy').addEventListener('click', () => {
        navigator.clipboard?.writeText(lastJson);
    });
})();
</script>
