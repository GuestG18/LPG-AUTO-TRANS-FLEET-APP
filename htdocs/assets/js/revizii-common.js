/* Revizii: simulatorul de calcul pe date istorice si sincronizarea SasFleet (comune paginii
   Prezentare generală si paginilor pe categorii). */
(function () {
    'use strict';

    var data = {};
    try {
        data = JSON.parse((document.getElementById('rvPageData') || {}).textContent || '{}');
    } catch (e) {
        data = {};
    }
    var defaults = data.defaults || {};

    function fmt(value, digits) {
        if (value === null || value === undefined || value === '') { return '—'; }
        return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: digits || 0, maximumFractionDigits: digits || 0 }).format(Number(value));
    }
    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function localInput(date) {
        var d = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return d.toISOString().slice(0, 16);
    }

    var simForm = document.getElementById('rvSimForm');
    if (simForm) {
        var simResult = document.getElementById('rvSimResult');
        var simVehicle = document.getElementById('rvSimVehicle');
        var simType = document.getElementById('rvSimType');
        // simPlans: tab => vehicle_id => {interval, warn, t0, is_trailer} (planul primei componente).
        var simPlans = data.simPlans || {};
        var trailers = data.trailers || {};
        var now = new Date();
        document.getElementById('rvSimTo').value = localInput(now);
        document.getElementById('rvSimTo').max = localInput(now);
        document.getElementById('rvSimFrom').value = localInput(new Date(now.getTime() - 30 * 86400000));

        var syncSimHours = function () {
            var hours = simType.selectedOptions[0].getAttribute('data-hours') === '1' && !trailers[simVehicle.value];
            Array.prototype.forEach.call(simForm.querySelectorAll('[data-sim-hours]'), function (box) {
                box.hidden = !hours;
                box.querySelectorAll('input, select').forEach(function (input) { input.disabled = !hours; });
            });
        };
        var prefill = function () {
            var plan = (simPlans[simType.value] || {})[simVehicle.value] || null;
            document.getElementById('rvSimInterval').value = plan && plan.interval ? plan.interval : (defaults.interval || 10000);
            document.getElementById('rvSimWarn').value = plan ? plan.warn : defaults.warn;
            if (plan && plan.t0) {
                var t0 = new Date(plan.t0.replace(' ', 'T'));
                if (!isNaN(t0) && t0 < now) { document.getElementById('rvSimFrom').value = localInput(t0); }
            }
            syncSimHours();
        };
        simVehicle.addEventListener('change', prefill);
        simType.addEventListener('change', prefill);
        prefill();

        var statusLabels = {
            in_regula: ['ok', 'În regulă', 'bi-check-circle-fill'],
            se_apropie: ['soon', 'Se apropie', 'bi-clock-fill'],
            necesita: ['due', 'Necesită revizie', 'bi-exclamation-triangle-fill'],
            fara_date: ['nodata', 'Date insuficiente', 'bi-question-circle-fill'],
            neconfigurat: ['none', 'Neconfigurat', 'bi-dash-circle']
        };

        var renderResult = function (r, sources) {
            var c = r.calc;
            var d = r.detaliu;
            var st = statusLabels[c.status] || statusLabels.fara_date;
            var html = '';
            if (r.fara_date) {
                html += '<div class="alert alert-warning mt-3 mb-0"><strong>Date istorice indisponibile.</strong> Nu există telemetrie utilizabilă pentru perioada aleasă' +
                    (r.note.length ? ': ' + esc(r.note[0]) : '') +
                    ' Rezultatul de mai jos nu poate fi folosit ca validare.</div>';
            } else if (!r.complet) {
                html += '<div class="alert alert-warning mt-3 mb-0"><strong>Acoperire parțială:</strong> ' +
                    (r.zile_lipsa.length === 1 ? '1 zi' : r.zile_lipsa.length + ' zile') + ' fără telemetrie' + (r.ore_incomplete ? ' și ore eligibile incomplete' : '') + '. Valorile sunt limite inferioare, nu 0 pe zilele lipsă.</div>';
            }
            html += '<div class="rv-sim-grid">' +
                '<div><span>Perioadă</span><strong>' + esc(r.perioada.de_la.slice(0, 16)) + ' → ' + esc(r.perioada.pana_la.slice(0, 16)) + '</strong></div>' +
                '<div><span>Km GPS efectuați</span><strong>' + fmt(c.km_efectuati, 1) + '</strong></div>' +
                '<div><span>Ore motor eligibile</span><strong>' + (r.vehicul.is_trailer ? '— (semi-remorcă)' : (d.ore_aplicate ? fmt(c.ore_motor, 2) + ' h' : 'nu se aplică')) + '</strong><small>' + esc(d.sursa_ore ? sources[d.sursa_ore] : (r.vehicul.is_trailer ? '' : 'plăcuțe / anvelope: doar km')) + '</small></div>' +
                '<div><span>Motor pornit (SasFleet)</span><strong>' + (r.vehicul.is_trailer ? '—' : fmt(d.ore_motor_pornit, 1) + ' h') + '</strong><small>' + (r.vehicul.is_trailer ? '' : 'pe loc ' + fmt(d.ore_sas_pe_loc, 1) + ' h · în mers ' + fmt(Math.max(0, d.ore_motor_pornit - d.ore_sas_pe_loc), 1) + ' h') + '</small></div>' +
                '<div><span>Km din ore motor</span><strong>' + fmt(c.km_din_ore, 1) + '</strong></div>' +
                '<div class="rv-sim-total"><span>Km echivalenți</span><strong>' + fmt(c.km_total, 1) + '</strong></div>' +
                '<div><span>Interval revizie</span><strong>' + fmt(c.interval_km) + '</strong></div>' +
                '<div class="rv-sim-total"><span>Km rămași</span><strong class="rv-text-' + st[0] + '">' + fmt(c.km_ramasi, 1) + '</strong></div>' +
                '<div><span>Progres / status</span><strong>' + fmt(c.progres, 1) + '%</strong> <span class="rv-badge rv-badge-' + st[0] + '"><i class="bi ' + st[2] + '"></i>' + st[1] + '</span></div>' +
                '</div>';
            html += '<div class="rv-sim-cols"><div><h6>Pașii calculului</h6><ol class="rv-sim-steps">' +
                r.pasi.map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ol></div>';
            html += '<div><h6>Sursa și prospețimea datelor</h6><ul class="rv-meta">' +
                r.surse.map(function (s) { return '<li><i class="bi bi-database"></i> ' + esc(s) + '</li>'; }).join('') +
                '<li><i class="bi bi-hdd"></i> ' + d.zile_locale + ' zile din telemetria sincronizată local (ultima sincronizare: ' + esc(r.ultima_sincronizare || '—') + ')</li>' +
                '<li><i class="bi bi-cloud-download"></i> ' + d.zile_api + ' zile citite acum din SasFleet (' + d.cereri_api + ' cereri, nesalvate)</li>' +
                '<li><i class="bi bi-list-ol"></i> ' + d.segmente + ' segmente numărate' + (d.segmente_respinse ? ', ' + d.segmente_respinse + ' respinse' : '') + '</li>' +
                (r.zile_lipsa.length ? '<li class="rv-warn"><i class="bi bi-exclamation-diamond"></i> Zile fără telemetrie: ' + esc(r.zile_lipsa.slice(0, 12).join(', ')) + (r.zile_lipsa.length > 12 ? '…' : '') + '</li>' : '') +
                r.note.map(function (n) { return '<li class="text-muted"><i class="bi bi-dot"></i>' + esc(n) + '</li>'; }).join('') +
                '</ul><p class="small text-muted mb-0">Km provin din distanța segmentelor (delte), nu din diferența contorului absolut kmIndex — o resetare de contor nu afectează rezultatul.</p></div></div>';
            simResult.innerHTML = html;
        };

        simForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var interval = Number(document.getElementById('rvSimInterval').value);
            var warn = Number(document.getElementById('rvSimWarn').value);
            var from = document.getElementById('rvSimFrom').value;
            var to = document.getElementById('rvSimTo').value;
            if (!from || !to || to <= from) {
                simResult.innerHTML = '<div class="alert alert-danger mt-3 mb-0">Sfârșitul trebuie să fie după T0.</div>';
                return;
            }
            if (!interval || warn >= interval) {
                simResult.innerHTML = '<div class="alert alert-danger mt-3 mb-0">Pragul trebuie să fie mai mic decât intervalul.</div>';
                return;
            }
            var button = simForm.querySelector('button[type="submit"]');
            button.disabled = true;
            simResult.innerHTML = '<p class="text-muted mt-3 mb-0"><span class="spinner-border spinner-border-sm"></span> Se calculează (zilele nesincronizate se citesc din SasFleet)…</p>';
            var body = new FormData(simForm);
            if (document.getElementById('rvSimSource').disabled) { body.set('sursa_ore', defaults.source); }
            if (document.getElementById('rvSimFactor').disabled) { body.set('factor', String(defaults.factor)); }
            fetch(simForm.getAttribute('data-url'), { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    if (!json.ok) {
                        simResult.innerHTML = '<div class="alert alert-danger mt-3 mb-0">' + esc(json.message || 'Simularea a eșuat.') + '</div>';
                        return;
                    }
                    renderResult(json.result, json.sources || {});
                })
                .catch(function () {
                    simResult.innerHTML = '<div class="alert alert-danger mt-3 mb-0">Simularea a eșuat — serverul nu a răspuns.</div>';
                })
                .then(function () { button.disabled = false; });
        });
    }

    // ------------------------------------------------------------ sincronizare
    var syncBtn = document.getElementById('rvSyncBtn');
    if (syncBtn) {
        var syncMsg = document.getElementById('rvSyncMsg');
        // Sincronizare in transe scurte, repetate cat timp au ramas ferestre (max 20 transe).
        var runSync = function () {
            syncBtn.disabled = true;
            var synced = 0;
            var round = 0;
            var step = function () {
                round++;
                syncMsg.textContent = 'Se sincronizează cu SasFleet… (' + synced + ' ferestre aduse)';
                var body = new FormData();
                body.append('_token', syncBtn.getAttribute('data-token'));
                fetch(syncBtn.getAttribute('data-url'), { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (response) { return response.json(); })
                    .then(function (json) {
                        var stats = json.stats || {};
                        synced += stats.windows_ok || 0;
                        if (stats.pending > 0 && !stats.aborted && !stats.locked && round < 20) {
                            step();
                            return;
                        }
                        syncMsg.textContent = json.message || '';
                        if (synced > 0) {
                            setTimeout(function () { window.location.reload(); }, 900);
                        } else {
                            syncBtn.disabled = false;
                        }
                    })
                    .catch(function () {
                        syncMsg.textContent = 'Sincronizarea a eșuat — datele afișate rămân cele salvate.';
                        syncBtn.disabled = false;
                    });
            };
            step();
        };
        syncBtn.addEventListener('click', runSync);
        if (syncBtn.getAttribute('data-autosync') === '1') {
            runSync();
        }
    }

})();
