<?php
/**
 * Banda "Curse in desfasurare (GPS live)" de pe lista Dispecer curse.
 *
 * Se alimenteaza singura din ?page=dispecer_curse&action=live_gps (SAS, prin
 * cache-ul partajat) si se reimprospateaza la 60s cat timp tab-ul e vizibil.
 * Fiecare vehicul activ arata soferul, viteza si locatia; daca exista o cursa
 * deschisa recenta in aplicatie, cardul trimite direct la editarea ei, altfel
 * marcheaza "fara cursa inregistrata" si ofera scurtatura spre formularul de
 * adaugare cu vehiculul preselectat.
 */
$liveGpsUrl = build_query_url(['page' => 'dispecer_curse', 'action' => 'live_gps']);
?>
<div class="card border-0 shadow-sm mb-3 d-none" id="dlg-panel">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
        <button type="button" class="btn btn-link text-decoration-none text-dark p-0 d-flex align-items-center gap-2 fw-semibold"
                id="dlg-toggle" aria-expanded="true" aria-controls="dlg-body">
            <span class="dlg-live-dot" aria-hidden="true"></span>
            <span>Curse în desfășurare <span class="text-muted fw-normal">(GPS live)</span></span>
            <span class="badge text-bg-success" id="dlg-count">0</span>
            <i class="bi bi-chevron-up small" id="dlg-toggle-icon" aria-hidden="true"></i>
        </button>
        <span class="text-muted small" id="dlg-updated"></span>
    </div>
    <div class="card-body py-2" id="dlg-body">
        <div class="text-muted small text-center py-2" id="dlg-empty">Se încarcă pozițiile din SAS...</div>
        <div class="dlg-grid" id="dlg-grid"></div>
    </div>
</div>

<style>
    .dlg-live-dot { width: .6rem; height: .6rem; border-radius: 50%; background: #198754; animation: dlg-pulse 2s infinite; flex: none; }
    @keyframes dlg-pulse {
        0% { box-shadow: 0 0 0 0 rgba(25, 135, 84, .45); }
        70% { box-shadow: 0 0 0 .4rem rgba(25, 135, 84, 0); }
        100% { box-shadow: 0 0 0 0 rgba(25, 135, 84, 0); }
    }
    .dlg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: .5rem; }
    .dlg-card { border: 1px solid #e4e8f0; border-radius: .5rem; padding: .5rem .65rem; display: flex; flex-direction: column; gap: .2rem; background: #fff; }
    .dlg-card-top { display: flex; align-items: center; gap: .45rem; }
    .dlg-dot { width: .55rem; height: .55rem; border-radius: 50%; flex: none; }
    .dlg-dot-moving { background: #198754; animation: dlg-pulse 2s infinite; }
    .dlg-dot-idle { background: #d97706; }
    .dlg-plate { font-weight: 700; font-size: .9rem; }
    .dlg-speed { margin-left: auto; font-weight: 700; font-size: .85rem; white-space: nowrap; }
    .dlg-meta { color: #64748b; font-size: .76rem; line-height: 1.3; }
    .dlg-meta i { margin-right: .2rem; }
    .dlg-race-link { font-size: .78rem; text-decoration: none; }
    .dlg-race-link:hover { text-decoration: underline; }
    .dlg-norace { font-size: .74rem; color: #92610a; background: #fdf3dd; border: 1px solid #f3dfb1; border-radius: .35rem; padding: .1rem .4rem; display: inline-flex; align-items: center; gap: .3rem; width: fit-content; }
    .dlg-norace a { color: inherit; font-weight: 600; }
    .dlg-form-flash { animation: dlg-form-flash 2s ease-out; }
    @keyframes dlg-form-flash {
        0% { box-shadow: 0 0 0 3px rgba(13, 110, 253, .45); }
        100% { box-shadow: 0 0 0 3px rgba(13, 110, 253, 0); }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var DLG_URL = <?= json_encode($liveGpsUrl, JSON_UNESCAPED_SLASHES) ?>;
    var REFRESH_MS = 60000;
    var panelEl = document.getElementById('dlg-panel');
    var gridEl = document.getElementById('dlg-grid');
    var emptyEl = document.getElementById('dlg-empty');
    var countEl = document.getElementById('dlg-count');
    var updatedEl = document.getElementById('dlg-updated');
    var toggleEl = document.getElementById('dlg-toggle');
    var toggleIconEl = document.getElementById('dlg-toggle-icon');
    var bodyEl = document.getElementById('dlg-body');
    if (!panelEl || !gridEl) { return; }

    var loading = false;
    var everShown = false;

    function esc(value) {
        var div = document.createElement('div');
        div.textContent = value === null || value === undefined ? '' : String(value);
        return div.innerHTML;
    }

    function agoLabel(seconds) {
        if (seconds === null || seconds === undefined) { return ''; }
        if (seconds < 90) { return 'acum ' + seconds + 's'; }
        return 'acum ' + Math.round(seconds / 60) + ' min';
    }

    function render(data) {
        var vehicles = data.vehicles || [];
        countEl.textContent = vehicles.length;

        // Panoul apare doar daca SAS e configurat; odata aparut, ramane (cu
        // mesaj gol) ca sa nu sara pagina la fiecare refresh.
        if (data.credentials === false) { panelEl.classList.add('d-none'); return; }
        panelEl.classList.remove('d-none');
        everShown = true;

        if (data.fetched_at) {
            updatedEl.textContent = 'actualizat ' + String(data.fetched_at).substring(11, 16);
        }

        if (!vehicles.length) {
            emptyEl.textContent = 'Niciun vehicul în mișcare acum.';
            emptyEl.classList.remove('d-none');
            gridEl.innerHTML = '';
            return;
        }
        emptyEl.classList.add('d-none');

        gridEl.innerHTML = vehicles.map(function (v) {
            var dot = v.status === 'moving' ? 'dlg-dot-moving' : 'dlg-dot-idle';
            var speed = v.status === 'moving' && v.speed !== null
                ? esc(v.speed) + ' km/h'
                : '<span class="text-muted fw-normal">' + esc(agoLabel(v.age_seconds) || 'oprit') + '</span>';
            var raceHtml;
            if (v.race) {
                raceHtml = '<a class="dlg-race-link" href="' + esc(v.race.url) + '">'
                    + '<i class="bi bi-box-arrow-up-right"></i> Cursa #' + esc(v.race.id)
                    + ' · ' + esc(v.race.tip_transport)
                    + (v.race.beneficiar ? ' · ' + esc(v.race.beneficiar) : '')
                    + '</a>';
            } else {
                var prefillAttrs = '';
                if (v.prefill) {
                    if (v.prefill.beneficiar_id) { prefillAttrs += ' data-dlg-beneficiar="' + esc(v.prefill.beneficiar_id) + '"'; }
                    if (v.prefill.tip_transport) { prefillAttrs += ' data-dlg-tip="' + esc(v.prefill.tip_transport) + '"'; }
                    if (v.prefill.driver_id) { prefillAttrs += ' data-dlg-driver="' + esc(v.prefill.driver_id) + '"'; }
                }
                raceHtml = '<span class="dlg-norace"><i class="bi bi-exclamation-triangle-fill"></i>'
                    + 'fără cursă înregistrată'
                    + (v.local_vehicle_id ? ' · <a href="#add-race-form" data-dlg-add="' + esc(v.local_vehicle_id) + '"' + prefillAttrs + '>adaugă</a>' : '')
                    + '</span>';
            }
            return '<div class="dlg-card">'
                + '<div class="dlg-card-top">'
                + '<span class="dlg-dot ' + dot + '" title="' + (v.status === 'moving' ? 'In miscare' : 'Oprit recent') + '"></span>'
                + '<span class="dlg-plate">' + esc(v.plate) + '</span>'
                + (v.vehicle_label ? '<span class="dlg-meta">' + esc(v.vehicle_label) + '</span>' : '')
                + '<span class="dlg-speed">' + speed + '</span>'
                + '</div>'
                + '<div class="dlg-meta"><i class="bi bi-person"></i>' + esc(v.driver || 'șofer necunoscut')
                + ' &nbsp;<i class="bi bi-geo-alt"></i>' + esc(v.place || '-') + '</div>'
                + raceHtml
                + '</div>';
        }).join('');
    }

    function refresh() {
        if (loading || document.hidden) { return; }
        loading = true;
        fetch(DLG_URL, { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.success) { render(data); }
            })
            .catch(function () {
                // Esec de retea: banda ramane cu ultimele date; reincearca la urmatorul ciclu.
                if (everShown && updatedEl) { updatedEl.textContent = 'actualizare eșuată, se reîncearcă...'; }
            })
            .finally(function () { loading = false; });
    }

    // "adauga" -> sare la formularul de adaugare si il precompleteaza dupa
    // sablonul ultimei curse a vehiculului: beneficiar -> tip transport ->
    // vehicul -> sofer, in aceasta ordine, pentru ca lista de vehicule se
    // construieste din beneficiar + tip, iar soferii din vehicul (cascada
    // existenta a formularului face restul: locatii implicite, capacitate etc.).
    function setSelectValue(selectEl, value) {
        if (!(selectEl instanceof HTMLSelectElement) || value === null || value === undefined || value === '') {
            return false;
        }
        var target = String(value);
        var hasOption = Array.prototype.some.call(selectEl.options, function (option) {
            return option.value === target && !option.disabled;
        });
        if (!hasOption) { return false; }
        if (selectEl.value !== target) {
            selectEl.value = target;
            selectEl.dispatchEvent(new Event('change', { bubbles: true }));
        }
        return true;
    }

    gridEl.addEventListener('click', function (event) {
        var addEl = event.target.closest('[data-dlg-add]');
        if (!addEl) { return; }

        var formEl = document.getElementById('add-race-form');
        var beneficiarEl = document.getElementById('race_beneficiar_id');
        var tipEl = document.getElementById('race_tip_transport');
        var vehicleEl = document.getElementById('race_vehicle_id');
        var driverEl = document.getElementById('race_driver_id');

        // Sursa principala: Configurare Transport. Combinatiile beneficiar+tip
        // in care vehiculul e configurat vin din aceeasi logica pe care o
        // foloseste formularul (window.dispecerVehicleCombos); ultima cursa
        // serveste doar ca departajare cand vehiculul e configurat in mai multe
        // combinatii, respectiv ca ultima solutie cand nu e configurat deloc.
        var vehicleId = addEl.getAttribute('data-dlg-add') || '';
        var lastBen = addEl.getAttribute('data-dlg-beneficiar') || '';
        var lastTip = addEl.getAttribute('data-dlg-tip') || '';
        var combos = typeof window.dispecerVehicleCombos === 'function'
            ? window.dispecerVehicleCombos(vehicleId)
            : [];

        var chosen = null;
        if (combos.length) {
            chosen = combos.find(function (c) { return c.beneficiar_id === lastBen && c.tip_transport === lastTip; })
                || combos.find(function (c) { return c.beneficiar_id === lastBen; })
                || combos.find(function (c) { return c.tip_transport === lastTip; })
                || combos[0];
        }

        var benValue = chosen ? chosen.beneficiar_id : lastBen;
        var tipValue = chosen ? chosen.tip_transport : lastTip;
        setSelectValue(beneficiarEl, benValue);
        setSelectValue(tipEl, tipValue);
        var vehicleSet = setSelectValue(vehicleEl, vehicleId);
        if (!vehicleSet) {
            // Vehiculul nu e in lista filtrata (combinatie neconfigurata):
            // optiunea speciala "Alt vehicul (arata toate)" extinde lista, iar
            // formularul isi afiseaza dialogul nativ "Vehicul neconfigurat pe ruta".
            if (setSelectValue(vehicleEl, '__show_all_vehicles__')) {
                vehicleSet = setSelectValue(vehicleEl, vehicleId);
            }
        }
        if (vehicleSet) {
            // Soferul din ultima cursa; daca nu mai e valabil dar vehiculul are
            // un singur sofer asociat, se alege acela.
            if (!setSelectValue(driverEl, addEl.getAttribute('data-dlg-driver'))
                && driverEl instanceof HTMLSelectElement) {
                // Optiunile speciale de tip "__show_all_...__" nu sunt soferi reali.
                var realOptions = Array.prototype.filter.call(driverEl.options, function (option) {
                    return option.value !== '' && !option.disabled && option.value.indexOf('__') !== 0;
                });
                if (realOptions.length === 1) {
                    setSelectValue(driverEl, realOptions[0].value);
                }
            }
        }

        // Feedback vizual: formularul clipeste scurt ca sa se vada ce s-a completat.
        if (formEl) {
            formEl.classList.remove('dlg-form-flash');
            void formEl.offsetWidth;
            formEl.classList.add('dlg-form-flash');
        }
    });

    if (toggleEl && bodyEl && toggleIconEl) {
        var applyCollapsed = function (collapsed) {
            bodyEl.classList.toggle('d-none', collapsed);
            toggleEl.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggleIconEl.className = collapsed ? 'bi bi-chevron-down small' : 'bi bi-chevron-up small';
        };
        // Starea pliat/depliat se pastreaza per browser, ca dispecerul sa nu
        // replieze banda la fiecare incarcare a paginii.
        try { applyCollapsed(localStorage.getItem('dlgCollapsed') === '1'); } catch (e) {}
        toggleEl.addEventListener('click', function () {
            var willCollapse = !bodyEl.classList.contains('d-none');
            applyCollapsed(willCollapse);
            try { localStorage.setItem('dlgCollapsed', willCollapse ? '1' : '0'); } catch (e) {}
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { refresh(); }
    });

    refresh();
    setInterval(refresh, REFRESH_MS);
});
</script>
