/**
 * Dispecer curse: cardurile "Km pierduti" (SAS) si "Alimentari neasociate" (CardOil) din dreapta
 * formularului Adauga cursa. Click pe card = cardul se extinde (morph) intr-un panou de lucru:
 *  - Km pierduti: vehiculele cu km GPS neacoperiti; perioadele rulate fara cursa se aloca pe
 *    Km service (formularul de pe pagina, precompletat) sau pe o cursa noua (Adauga cursa precompletat).
 *  - Alimentari: de ce a ramas neasociata (acelasi diagnostic ca in Carburanti) + asociere manuala.
 * Doar citire, cu exceptia asocierii manuale a alimentarii (POST insights_fuel_link).
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-dins]');
    if (!root) { return; }
    var cfg = {};
    try { cfg = JSON.parse(root.getAttribute('data-dins') || '{}'); } catch (e) { return; }

    var NF = new Intl.NumberFormat('ro-RO');
    var NF1 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 1 });
    var CONCURRENCY = 3;
    var FUEL_LABELS = { motorina: 'Motorină', adblue: 'AdBlue', benzina: 'Benzină', gpl: 'GPL' };

    function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }
    function km(n) { return n == null ? '–' : NF.format(Math.round(n)); }
    function km1(n) { return n == null ? '–' : NF1.format(n); }
    function roDate(ymd) { var p = String(ymd || '').split('-'); return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : String(ymd || ''); }
    function roDt(value) { var p = String(value || '').split(' '); return roDate(p[0]) + (p[1] ? ' ' + p[1].slice(0, 5) : ''); }
    function splitDt(value) { var p = String(value || '').split(' '); return { date: p[0] || '', time: (p[1] || '').slice(0, 5) }; }
    function getJson(url) {
        return fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function tripHref(id) {
        return cfg.dispatcherUrl + '&ids=' + id + '&ids_label=' + encodeURIComponent('cursa #' + id);
    }

    // ---------------------------------------------------------------- alocare
    // Km service: formularul de pe aceeasi pagina (fara reincarcare); cursa: Adauga cursa precompletat.
    function allocateService(vehicleId, from, to) {
        var a = splitDt(from), b = splitDt(to);
        var values = { vehicle_id: String(vehicleId), data_plecare: a.date, ora_plecare: a.time, data_intoarcere: b.date, ora_intoarcere: b.time };
        if (window.DispatcherServiceKm && typeof window.DispatcherServiceKm.prefill === 'function') {
            closeMorph();
            setTimeout(function () { window.DispatcherServiceKm.prefill(values); }, 380);
            return;
        }
        window.location.href = cfg.dispatcherUrl + '&' + new URLSearchParams(Object.assign({ prefill: 'service' }, values)).toString() + '#service-km-panel';
    }
    function allocateRace(vehicleId, from, to) {
        var a = splitDt(from), b = splitDt(to);
        window.location.href = cfg.dispatcherUrl + '&' + new URLSearchParams({
            prefill: 'race', vehicle_id: String(vehicleId), data_inceput: a.date, ora_inceput: a.time, data_sfarsit: b.date, ora_sfarsit: b.time
        }).toString() + '#add-race-form';
    }
    function allocateButtons(vehicleId, from, to, small) {
        if (!vehicleId) { return '<span class="dins-muted small">vehicul necunoscut în flotă</span>'; }
        var data = ' data-vehicle="' + esc(vehicleId) + '" data-from="' + esc(from) + '" data-to="' + esc(to) + '"';
        var size = small ? ' btn-sm py-0' : ' btn-sm';
        var html = '';
        if (cfg.canService) { html += '<button type="button" class="btn btn-outline-warning text-dark' + size + '" data-alloc="service"' + data + '><i class="bi bi-tools me-1"></i>Km service</button>'; }
        if (cfg.canRace) { html += '<button type="button" class="btn btn-outline-primary' + size + '" data-alloc="race"' + data + '><i class="bi bi-plus-lg me-1"></i>Cursă</button>'; }
        return '<div class="dins-actions">' + html + '</div>';
    }

    // ---------------------------------------------------------------- morph
    var morph = null;
    function openMorph(card, title, subtitle, accentClass, renderContent) {
        if (morph) { return; }
        var rect = card.getBoundingClientRect();
        var backdrop = document.createElement('div');
        backdrop.className = 'dins-backdrop';
        var panel = document.createElement('div');
        panel.className = 'dins-morph ' + accentClass;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.style.cssText = 'top:' + rect.top + 'px;left:' + rect.left + 'px;width:' + rect.width + 'px;height:' + rect.height + 'px';
        panel.innerHTML = '<div class="dins-morph-head">' + card.querySelector('.dins-chip').outerHTML
            + '<div class="dins-card-title">' + esc(title) + '<small>' + esc(subtitle) + '</small></div>'
            + '<button type="button" class="btn-close" aria-label="Închide"></button></div>'
            + '<div class="dins-morph-content"></div>';
        document.body.appendChild(backdrop);
        document.body.appendChild(panel);
        card.classList.add('is-morphing');
        document.body.style.overflow = 'hidden';
        morph = { card: card, panel: panel, backdrop: backdrop };

        // Fortam layout-ul pozitiei de start, apoi animam spre centrul ecranului.
        void panel.offsetWidth;
        var width = Math.min(1180, window.innerWidth - 32);
        var height = Math.min(window.innerHeight - 48, 820);
        panel.style.top = Math.round((window.innerHeight - height) / 2) + 'px';
        panel.style.left = Math.round((window.innerWidth - width) / 2) + 'px';
        panel.style.width = width + 'px';
        panel.style.height = height + 'px';
        backdrop.classList.add('is-open');
        panel.classList.add('is-open');

        backdrop.addEventListener('click', closeMorph);
        panel.querySelector('.btn-close').addEventListener('click', closeMorph);
        renderContent(panel.querySelector('.dins-morph-content'));
        panel.querySelector('.btn-close').focus();
    }
    function closeMorph() {
        if (!morph || morph.closing) { return; }
        var m = morph;
        m.closing = true;
        var rect = m.card.getBoundingClientRect();
        m.panel.classList.add('is-closing');
        m.panel.style.top = rect.top + 'px';
        m.panel.style.left = rect.left + 'px';
        m.panel.style.width = rect.width + 'px';
        m.panel.style.height = rect.height + 'px';
        m.backdrop.classList.remove('is-open');
        setTimeout(function () {
            m.panel.remove();
            m.backdrop.remove();
            m.card.classList.remove('is-morphing');
            document.body.style.overflow = '';
            morph = null;
        }, 380);
    }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && morph) { closeMorph(); } });

    // ---------------------------------------------------------------- Km pierduti
    var kmState = { vehicles: [], loaded: 0, total: 0, start: '', end: '', error: '', ready: false, selected: null, details: {} };
    var kmBody = root.querySelector('[data-dins-body="km"]');

    function kmLost(v) { return v.gps == null ? null : v.gps - (v.km_efectuati || 0) - (v.km_service || 0); }
    function kmRanked() {
        return kmState.vehicles.filter(function (v) { return v.gps != null && kmLost(v) > 0.5; })
            .sort(function (a, b) { return kmLost(b) - kmLost(a); });
    }
    function renderKmCard() {
        if (!cfg.canKm) { return; }
        if (kmState.error) { kmBody.innerHTML = '<span class="dins-muted">' + esc(kmState.error) + '</span>'; return; }
        if (!kmState.ready) { return; }
        var ranked = kmRanked();
        var total = ranked.reduce(function (s, v) { return s + kmLost(v); }, 0);
        var pct = kmState.total ? Math.round(kmState.loaded / kmState.total * 100) : 100;
        var max = ranked.length ? kmLost(ranked[0]) : 1;
        var html = '<span class="dins-big">' + km(total) + ' <small>km fără cursă</small></span>'
            + '<span class="dins-sub">' + ranked.length + ' vehicule · ' + roDate(kmState.start) + ' – ' + roDate(kmState.end)
            + (pct < 100 ? ' · GPS ' + kmState.loaded + '/' + kmState.total : '') + '</span>'
            + (pct < 100 ? '<span class="dins-progress"><span style="width:' + pct + '%"></span></span>' : '');
        // Pana la 6 randuri; CSS-ul arata cate incap in inaltimea cardului (data-dins-rows).
        html += '<ul class="dins-top">' + ranked.slice(0, 6).map(function (v) {
            return '<li><span class="text-truncate fw-semibold">' + esc(v.plate) + '</span><span class="dins-bar"><span style="width:'
                + Math.max(4, kmLost(v) / max * 100).toFixed(1) + '%"></span></span><b>' + km(kmLost(v)) + '</b></li>';
        }).join('') + '</ul>';
        html += '<span class="dins-hint"><i class="bi bi-hand-index me-1"></i>Click pentru detaliere și alocare pe cursă sau Km service</span>';
        kmBody.innerHTML = html;
        if (morph && morph.kind === 'km') { renderKmList(); }
    }

    function loadKm() {
        if (!cfg.canKm) { return; }
        getJson(cfg.kmUrl + '&action=vehicles').then(function (data) {
            if (!data || !data.success) { throw new Error(data && data.error ? data.error : 'Datele SAS nu au putut fi încărcate.'); }
            kmState.start = data.start;
            kmState.end = data.end;
            kmState.vehicles = (data.vehicles || []).map(function (v) { v.gps = null; return v; });
            var tracked = kmState.vehicles.filter(function (v) { return (v.sas_vehicle_id || 0) > 0; });
            kmState.total = tracked.length;
            kmState.ready = true;
            renderKmCard();
            var period = '&start=' + data.start + '&end=' + data.end;
            // Intai tot ce serverul are deja pe disc (o cerere, fara SAS); din SAS doar ce lipseste.
            getJson(cfg.kmUrl + '&action=gps_cached' + period + '&car_ids=' + tracked.map(function (v) { return v.sas_vehicle_id; }).join(','))
                .catch(function () { return { cached: {}, missing: null }; })
                .then(function (batch) {
                    var cached = (batch && batch.cached) || {};
                    var queue = [];
                    tracked.forEach(function (v) {
                        var value = cached[String(v.sas_vehicle_id)];
                        if (value !== undefined) { v.gps = typeof value === 'number' ? value : 0; kmState.loaded++; } else { queue.push(v); }
                    });
                    kmState.fromCache = kmState.loaded;
                    renderKmCard();
                    var gpsUrl = cfg.kmUrl + '&action=gps' + period + '&car_id=';
                    function worker() {
                        var v = queue.shift();
                        if (!v) { return; }
                        getJson(gpsUrl + v.sas_vehicle_id)
                            .then(function (d) { v.gps = d && typeof d.total_km === 'number' ? d.total_km : 0; })
                            .catch(function () { v.gps = 0; })
                            .finally(function () { kmState.loaded++; renderKmCard(); worker(); });
                    }
                    for (var i = 0; i < CONCURRENCY; i++) { worker(); }
                });
        }).catch(function (err) {
            kmState.error = err.message || 'Datele SAS nu au putut fi încărcate.';
            renderKmCard();
        });
    }

    function renderKmList() {
        var list = morph.panel.querySelector('.dins-list');
        if (!list) { return; }
        var ranked = kmRanked();
        var pending = kmState.total - kmState.loaded;
        list.innerHTML = (pending > 0 ? '<div class="dins-muted small px-3 py-2"><span class="spinner-border spinner-border-sm me-1"></span>GPS încărcat ' + kmState.loaded + '/' + kmState.total + '</div>' : '')
            + (ranked.length ? ranked.map(function (v) {
                return '<button type="button" class="dins-list-item' + (kmState.selected === v.sas_vehicle_id ? ' is-active' : '') + '" data-km-vehicle="' + v.sas_vehicle_id + '">'
                    + '<span class="fw-semibold">' + esc(v.plate) + '</span><b class="dins-lost">' + km(kmLost(v)) + ' km</b>'
                    + '<span class="dins-li-meta">GPS ' + km(v.gps) + ' · curse ' + km(v.km_efectuati) + ' · service ' + km(v.km_service) + '</span></button>';
            }).join('') : '<div class="dins-empty">' + (pending > 0 ? 'Se încarcă…' : 'Niciun vehicul cu km neacoperiți.') + '</div>');
    }

    function renderKmDetail(v, d) {
        var pane = morph.panel.querySelector('.dins-pane');
        var html = '<div class="d-flex align-items-center justify-content-between gap-2 mb-2"><h5 class="mb-0">' + esc(v.plate) + '</h5>'
            + '<a class="small" href="' + esc(cfg.kmUrl + '&start=' + kmState.start + '&end=' + kmState.end) + '" target="_blank" rel="noopener">Raport Km pierduți <i class="bi bi-box-arrow-up-right"></i></a></div>'
            + '<div class="dins-kpis mb-3"><div><b>' + km1(d.gps_km) + '</b><span>Km GPS</span></div><div><b>' + km1(d.declared_trips_km) + '</b><span>Km pe curse</span></div>'
            + '<div><b>' + km1(d.declared_service_km) + '</b><span>Km service</span></div><div><b class="dins-lost">' + km1(d.lost_km) + '</b><span>Km pierduți</span></div></div>';

        html += '<h6><i class="bi bi-exclamation-circle text-danger me-1"></i>Perioade rulate fără cursă — alocă-le</h6>';
        var gaps = (d.gaps || []).slice().sort(function (a, b) { return b.km - a.km; });
        if (!gaps.length) {
            html += '<div class="dins-muted small">Nicio deplasare GPS în afara curselor / drumurilor la service.</div>';
        } else {
            html += '<div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead class="table-light"><tr><th>Interval</th><th>Între</th><th class="text-end">Km GPS</th><th class="text-end">Alocă</th></tr></thead><tbody>';
            gaps.forEach(function (g, i) {
                var between = (g.after ? 'după ' + esc(g.after) : 'început perioadă') + ' → ' + (g.before ? 'înainte de ' + esc(g.before) : 'sfârșit perioadă');
                html += '<tr><td class="text-nowrap"><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-km-gap-toggle="' + i + '"><i class="bi bi-chevron-right"></i></button> '
                    + esc(roDt(g.from)) + ' → ' + esc(roDt(g.to)) + '<div class="dins-muted small">' + g.segments.length + ' deplasări</div></td>'
                    + '<td class="small">' + between + '</td><td class="text-end dins-lost">' + km1(g.km) + '</td>'
                    + '<td>' + allocateButtons(v.vehicle_id, g.from, g.to) + '</td></tr>';
                g.segments.forEach(function (s) {
                    html += '<tr class="dins-seg-row d-none" data-km-gap="' + i + '"><td class="ps-4 text-nowrap">' + esc(roDt(s.start)) + ' – ' + esc(splitDt(s.end).time) + '</td>'
                        + '<td>' + esc(s.from || '?') + ' <i class="bi bi-arrow-right"></i> ' + esc(s.to || '?') + (s.partial ? ' <span class="badge text-bg-light">parțial</span>' : '') + '</td>'
                        + '<td class="text-end">' + km1(s.km) + '</td><td>' + allocateButtons(v.vehicle_id, s.start, s.end, true) + '</td></tr>';
                });
            });
            html += '</tbody></table></div>';
        }

        var over = (d.trips || []).filter(function (t) { return t.counted && !t.starts_before && t.gps_km - t.declared_km > 0.5; });
        if (over.length) {
            html += '<h6><i class="bi bi-signpost-2 text-warning me-1"></i>Curse cu mai mulți km GPS decât declarați</h6>'
                + '<table class="table table-sm mb-2"><tbody>' + over.map(function (t) {
                    return '<tr><td><a href="' + esc(tripHref(t.id)) + '" target="_blank" rel="noopener">#' + t.id + '</a> ' + esc(t.beneficiar || '') + '</td>'
                        + '<td class="text-nowrap">' + esc(roDt(t.from)) + ' → ' + esc(roDt(t.to)) + '</td>'
                        + '<td class="text-end">declarat ' + km1(t.declared_km) + ' · GPS ' + km1(t.gps_km) + ' · <span class="dins-lost">+' + km1(t.gps_km - t.declared_km) + '</span></td></tr>';
                }).join('') + '</tbody></table>';
        }
        html += '<div class="dins-muted small mt-2"><i class="bi bi-info-circle me-1"></i>„Km service” completează formularul Km service de pe această pagină; „Cursă” deschide Adaugă cursă cu vehiculul și intervalul precompletate. Nimic nu se salvează fără confirmarea ta.</div>';
        pane.innerHTML = html;
    }

    function selectKmVehicle(carId) {
        var v = kmState.vehicles.filter(function (x) { return String(x.sas_vehicle_id) === String(carId); })[0];
        if (!v || !morph) { return; }
        kmState.selected = v.sas_vehicle_id;
        renderKmList();
        var pane = morph.panel.querySelector('.dins-pane');
        if (kmState.details[carId]) { renderKmDetail(v, kmState.details[carId]); return; }
        pane.innerHTML = '<div class="dins-empty"><span class="spinner-border spinner-border-sm me-2"></span>Se încarcă deplasările GPS din SAS…</div>';
        getJson(cfg.kmUrl + '&action=detail&start=' + kmState.start + '&end=' + kmState.end + '&car_id=' + v.sas_vehicle_id + '&vehicle_id=' + (v.vehicle_id || 0))
            .then(function (d) {
                if (!d || d.error) { throw new Error(d && d.error ? d.error : 'Răspuns invalid'); }
                kmState.details[carId] = d;
                if (morph && kmState.selected === v.sas_vehicle_id) { renderKmDetail(v, d); }
            })
            .catch(function (err) { if (morph) { pane.innerHTML = '<div class="alert alert-danger m-0">' + esc(err.message) + '</div>'; } });
    }

    function openKm(card) {
        if (!cfg.canKm || kmState.error) { return; }
        openMorph(card, 'Km pierduți', 'Km GPS (SAS) rulați fără cursă sau service · ' + roDate(kmState.start) + ' – ' + roDate(kmState.end), 'dins-card--km', function (content) {
            content.innerHTML = '<div class="dins-split"><div class="dins-list"></div><div class="dins-pane"><div class="dins-empty">Alege un vehicul din stânga.</div></div></div>';
        });
        morph.kind = 'km';
        renderKmList();
        var first = kmRanked()[0];
        if (first) { selectKmVehicle(first.sas_vehicle_id); }
    }

    // ---------------------------------------------------------------- Alimentari neasociate
    var fuelState = { items: [], loaded: false, error: '', canLink: false, selected: null };
    var fuelBody = root.querySelector('[data-dins-body="fuel"]');

    function renderFuelCard() {
        if (fuelState.error) { fuelBody.innerHTML = '<span class="dins-muted">' + esc(fuelState.error) + '</span>'; return; }
        var items = fuelState.items;
        if (!items.length) {
            fuelBody.innerHTML = '<span class="dins-big">0</span><span class="dins-sub">Toate alimentările CardOil din ultimele 30 de zile au cursă sau Km service.</span>';
            return;
        }
        var liters = items.reduce(function (s, i) { return s + (i.fuel_type === 'adblue' ? 0 : i.liters); }, 0);
        var value = items.reduce(function (s, i) { return s + i.value; }, 0);
        var byPlate = {};
        items.forEach(function (i) { byPlate[i.plate] = (byPlate[i.plate] || 0) + 1; });
        var plates = Object.keys(byPlate).sort(function (a, b) { return byPlate[b] - byPlate[a]; });
        var max = byPlate[plates[0]];
        fuelBody.innerHTML = '<span class="dins-big">' + items.length + ' <small>alimentări fără cursă</small></span>'
            + '<span class="dins-sub">' + km(liters) + ' L carburant · ' + NF.format(Math.round(value)) + ' lei · ' + plates.length + ' vehicule</span>'
            + '<ul class="dins-top">' + plates.slice(0, 6).map(function (p) {
                return '<li><span class="text-truncate fw-semibold">' + esc(p) + '</span><span class="dins-bar"><span style="width:' + (byPlate[p] / max * 100).toFixed(1) + '%"></span></span><b>' + byPlate[p] + '</b></li>';
            }).join('') + '</ul>'
            + '<span class="dins-hint"><i class="bi bi-hand-index me-1"></i>Click pentru motiv și asociere cu o cursă / Km service</span>';
    }

    function loadFuel() {
        getJson(cfg.fuelUrl).then(function (data) {
            if (!data || !data.success) { throw new Error(data && data.error ? data.error : 'Alimentările nu au putut fi încărcate.'); }
            fuelState.items = data.items || [];
            fuelState.canLink = !!data.can_link;
            fuelState.loaded = true;
            renderFuelCard();
            if (morph && morph.kind === 'fuel') { renderFuelList(); }
        }).catch(function (err) { fuelState.error = err.message; renderFuelCard(); });
    }

    function renderFuelList() {
        var list = morph.panel.querySelector('.dins-list');
        list.innerHTML = fuelState.items.length ? fuelState.items.map(function (i) {
            return '<button type="button" class="dins-list-item' + (fuelState.selected === i.id ? ' is-active' : '') + '" data-fuel-id="' + i.id + '">'
                + '<span class="fw-semibold">' + esc(i.plate) + '</span><b>' + km1(i.liters) + ' L</b>'
                + '<span class="dins-li-meta">' + esc(roDt(i.datetime)) + ' · ' + esc(FUEL_LABELS[i.fuel_type] || i.fuel_type) + (i.driver ? ' · ' + esc(i.driver) : '') + '</span></button>';
        }).join('') : '<div class="dins-empty">Nicio alimentare neasociată.</div>';
    }

    function tripLine(label, trip, suffix) {
        if (!trip) { return ''; }
        return '<tr><td class="text-muted">' + label + '</td><td><a href="' + esc(tripHref(trip.id || trip.trip_id)) + '" target="_blank" rel="noopener">#' + esc(trip.id || trip.trip_id) + '</a>'
            + (trip.driver_name ? ' · ' + esc(trip.driver_name) : '') + '</td><td class="text-nowrap">' + esc(roDt(trip.trip_start)) + ' → ' + esc(roDt(trip.trip_end)) + '</td><td class="text-end">' + (suffix || '') + '</td></tr>';
    }

    function renderFuelDetail(item, d) {
        var pane = morph.panel.querySelector('.dins-pane');
        var diag = d.diagnosis || {};
        var opts = d.options || { trips: [], service_km: [] };
        var html = '<div class="d-flex align-items-center justify-content-between gap-2 mb-2"><h5 class="mb-0">' + esc(item.plate) + ' <span class="text-muted fw-normal fs-6">' + esc(roDt(item.datetime)) + '</span></h5>'
            + '<a class="small" href="index.php?page=carburanti" target="_blank" rel="noopener">Carburanți <i class="bi bi-box-arrow-up-right"></i></a></div>'
            + '<div class="dins-kpis mb-3"><div><b>' + km1(item.liters) + ' L</b><span>' + esc(FUEL_LABELS[item.fuel_type] || item.fuel_type) + '</span></div>'
            + '<div><b>' + NF.format(Math.round(item.value)) + '</b><span>lei</span></div>'
            + '<div><b class="text-truncate">' + esc(item.driver || '–') + '</b><span>Șofer (card)</span></div>'
            + '<div><b class="text-truncate">' + esc(item.station || '–') + '</b><span>Stație</span></div></div>';

        html += '<h6><i class="bi bi-question-circle text-warning me-1"></i>De ce nu s-a asociat</h6>'
            + (diag.reasons || []).map(function (r) { return '<div class="dins-reason">' + esc(r) + '</div>'; }).join('');
        var near = tripLine('Cursa dinainte', diag.previous_trip, diag.previous_trip ? 's-a încheiat cu ' + km1(diag.previous_trip.gap_hours) + ' h înainte' : '')
            + tripLine('Cursa de după', diag.next_trip, diag.next_trip ? 'începe la ' + km1(diag.next_trip.gap_hours) + ' h după' : '');
        if (near) { html += '<table class="table table-sm mt-2 mb-0"><tbody>' + near + '</tbody></table>'; }

        html += '<h6>Asociază manual</h6>';
        if (!fuelState.canLink) {
            html += '<div class="dins-muted small">Asocierea manuală cere dreptul Carburanți → „Asociere manuală alimentare ↔ cursă”.</div>';
        } else if (!opts.trips.length && !opts.service_km.length) {
            html += '<div class="dins-muted small">Vehiculul nu are curse sau Km service în apropiere.</div>';
        } else {
            html += '<div class="d-flex gap-2 align-items-center"><select class="form-select form-select-sm" data-fuel-target>'
                + opts.trips.map(function (t) { return '<option value="' + t.id + '">' + esc(t.label) + (t.contains ? ' ✓ conține alimentarea' : '') + '</option>'; }).join('')
                + opts.service_km.map(function (s) { return '<option value="service:' + s.id + '">' + esc(s.label) + '</option>'; }).join('')
                + '</select><button type="button" class="btn btn-sm btn-primary text-nowrap" data-fuel-link="' + item.id + '"><i class="bi bi-link-45deg me-1"></i>Asociază</button></div>'
                + '<div class="small mt-1" data-fuel-link-msg></div>';
        }

        if (item.vehicle_id && (cfg.canRace || cfg.canService)) {
            html += '<h6>Sau creează ce lipsește</h6><div class="d-flex align-items-center gap-2 flex-wrap"><span class="dins-muted small">Interval pornind de la alimentare:</span>'
                + allocateButtons(item.vehicle_id, item.datetime, item.datetime) + '</div>';
        }
        pane.innerHTML = html;
    }

    function selectFuel(id) {
        var item = fuelState.items.filter(function (i) { return i.id === id; })[0];
        if (!item || !morph) { return; }
        fuelState.selected = id;
        renderFuelList();
        var pane = morph.panel.querySelector('.dins-pane');
        pane.innerHTML = '<div class="dins-empty"><span class="spinner-border spinner-border-sm me-2"></span>Se caută cursele din jurul alimentării…</div>';
        getJson(cfg.fuelDetailUrl + '&fillup_id=' + id).then(function (d) {
            if (!d || !d.success) { throw new Error(d && d.error ? d.error : 'Răspuns invalid'); }
            if (morph && fuelState.selected === id) { renderFuelDetail(item, d); }
        }).catch(function (err) { if (morph) { pane.innerHTML = '<div class="alert alert-danger m-0">' + esc(err.message) + '</div>'; } });
    }

    function linkFuel(button) {
        var pane = button.closest('.dins-pane');
        var select = pane.querySelector('[data-fuel-target]');
        var msg = pane.querySelector('[data-fuel-link-msg]');
        var id = parseInt(button.getAttribute('data-fuel-link'), 10);
        var body = new URLSearchParams({ _token: cfg.csrf, fillup_id: String(id), target: select.value });
        button.disabled = true;
        fetch(cfg.fuelLinkUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.success) { throw new Error(d && d.error ? d.error : 'Asocierea a eșuat.'); }
                fuelState.items = fuelState.items.filter(function (i) { return i.id !== id; });
                renderFuelCard();
                renderFuelList();
                pane.innerHTML = '<div class="dins-empty text-success"><i class="bi bi-check-circle fs-3 d-block mb-2"></i>' + esc(d.message) + '</div>';
                var next = fuelState.items[0];
                if (next) { setTimeout(function () { if (morph && fuelState.selected === id) { selectFuel(next.id); } }, 1200); }
            })
            .catch(function (err) { button.disabled = false; msg.className = 'small mt-1 text-danger'; msg.textContent = err.message; });
    }

    function openFuel(card) {
        if (fuelState.error) { return; }
        openMorph(card, 'Alimentări neasociate', 'CardOil · alimentări din API fără cursă sau Km service, ultimele 30 de zile', 'dins-card--fuel', function (content) {
            content.innerHTML = '<div class="dins-split"><div class="dins-list"></div><div class="dins-pane"><div class="dins-empty">Alege o alimentare din stânga.</div></div></div>';
        });
        morph.kind = 'fuel';
        if (!fuelState.loaded) {
            morph.panel.querySelector('.dins-list').innerHTML = '<div class="dins-empty"><span class="spinner-border spinner-border-sm"></span></div>';
            return;
        }
        renderFuelList();
        if (fuelState.items[0]) { selectFuel(fuelState.items[0].id); }
    }

    // ---------------------------------------------------------------- evenimente
    root.addEventListener('click', function (e) {
        var card = e.target.closest('[data-dins-card]');
        if (!card) { return; }
        if (card.getAttribute('data-dins-card') === 'km') { openKm(card); } else { openFuel(card); }
    });
    document.addEventListener('click', function (e) {
        if (!morph || !morph.panel.contains(e.target)) { return; }
        var t;
        if ((t = e.target.closest('[data-km-vehicle]'))) { selectKmVehicle(t.getAttribute('data-km-vehicle')); return; }
        if ((t = e.target.closest('[data-fuel-id]'))) { selectFuel(parseInt(t.getAttribute('data-fuel-id'), 10)); return; }
        if ((t = e.target.closest('[data-fuel-link]'))) { linkFuel(t); return; }
        if ((t = e.target.closest('[data-km-gap-toggle]'))) {
            var i = t.getAttribute('data-km-gap-toggle');
            morph.panel.querySelectorAll('[data-km-gap="' + i + '"]').forEach(function (row) { row.classList.toggle('d-none'); });
            var icon = t.querySelector('.bi');
            icon.classList.toggle('bi-chevron-right');
            icon.classList.toggle('bi-chevron-down');
            return;
        }
        if ((t = e.target.closest('[data-alloc]'))) {
            var args = [t.getAttribute('data-vehicle'), t.getAttribute('data-from'), t.getAttribute('data-to')];
            if (t.getAttribute('data-alloc') === 'service') { allocateService.apply(null, args); } else { allocateRace.apply(null, args); }
        }
    });

    // Datele vin dupa randarea paginii, ca Desfasuratorul sa nu astepte SAS / CardOil.
    function start() { setTimeout(function () { loadFuel(); loadKm(); }, 600); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();

/**
 * Layout redimensionabil: splitter vertical formular | carduri si splitter orizontal intre cele doua
 * carduri. Pozitiile se salveaza in localStorage (dublu-click / Enter = implicit). Cardurile isi
 * adapteaza continutul la dimensiune: latimea prin container queries (CSS), inaltimea prin
 * ResizeObserver (data-dins-size / data-dins-rows). Doar UI, fara legatura cu datele.
 */
(function () {
    'use strict';

    var layout = document.querySelector('[data-dins-layout]');
    if (!layout) { return; }
    var colSplit = layout.querySelector('[data-dins-split="col"]');
    var rowSplit = layout.querySelector('[data-dins-split="row"]');
    var side = layout.querySelector('.dins-layout-side');
    var stack = layout.querySelector('.dins-stack');
    if (!colSplit || !side || !stack) { return; }

    var KEY_SIDE = 'fleet.dispatcher.insights.sideWidth';
    var KEY_TOP = 'fleet.dispatcher.insights.topRatio';
    var wide = window.matchMedia('(min-width: 1200px)');

    function cssPx(name) { return parseFloat(getComputedStyle(layout).getPropertyValue(name)) || 0; }
    function store(key, value) {
        try { if (value == null) { localStorage.removeItem(key); } else { localStorage.setItem(key, String(value)); } } catch (e) { /* stocare indisponibila */ }
    }
    function load(key) {
        try { var v = parseFloat(localStorage.getItem(key)); return isFinite(v) ? v : null; } catch (e) { return null; }
    }
    function clamp(v, min, max) { return Math.min(max, Math.max(min, v)); }

    // ---------------------------------------------------------------- latime coloana carduri
    var sideWidth = load(KEY_SIDE);
    function sideBounds() {
        var free = layout.clientWidth - colSplit.offsetWidth;
        var min = cssPx('--dins-side-min');
        return { min: min, max: free - cssPx('--dins-form-min'), fits: free - cssPx('--dins-form-min') >= min };
    }
    function applySide(save) {
        var b = sideBounds();
        if (sideWidth == null || !b.fits) {
            // Fara preferinta sau ecran prea ingust: latimea implicita din CSS (o treime).
            layout.style.removeProperty('--dins-side');
        } else {
            // Preferinta ramane neatinsa la o micsorare temporara a ferestrei; doar ce se aplica e limitat.
            if (save) { sideWidth = clamp(sideWidth, b.min, b.max); }
            layout.style.setProperty('--dins-side', Math.round(clamp(sideWidth, b.min, b.max)) + 'px');
        }
        if (save) { store(KEY_SIDE, sideWidth == null ? null : Math.round(sideWidth)); }
        var now = Math.round(side.getBoundingClientRect().width);
        colSplit.setAttribute('aria-valuemin', String(Math.round(b.min)));
        colSplit.setAttribute('aria-valuemax', String(Math.round(Math.max(b.min, b.max))));
        colSplit.setAttribute('aria-valuenow', String(now));
        colSplit.setAttribute('aria-valuetext', 'Coloana cu carduri: ' + now + ' px');
    }

    // ---------------------------------------------------------------- impartire verticala carduri
    var topRatio = load(KEY_TOP);
    function rowBounds() {
        var avail = stack.clientHeight - (rowSplit ? rowSplit.offsetHeight : 0);
        var min = avail > 0 ? cssPx('--dins-card-min') / avail : 0.5;
        return { avail: avail, min: Math.min(0.5, min), max: Math.max(0.5, 1 - min) };
    }
    function applyRow(save) {
        if (!rowSplit) { return; }
        var b = rowBounds();
        if (topRatio == null) {
            stack.style.removeProperty('--dins-top-fr');
            stack.style.removeProperty('--dins-bottom-fr');
        } else {
            if (save) { topRatio = clamp(topRatio, b.min, b.max); }
            var applied = clamp(topRatio, b.min, b.max);
            stack.style.setProperty('--dins-top-fr', applied.toFixed(4) + 'fr');
            stack.style.setProperty('--dins-bottom-fr', (1 - applied).toFixed(4) + 'fr');
        }
        if (save) { store(KEY_TOP, topRatio == null ? null : topRatio.toFixed(4)); }
        var pct = Math.round((topRatio == null ? 0.5 : clamp(topRatio, b.min, b.max)) * 100);
        rowSplit.setAttribute('aria-valuemin', String(Math.round(b.min * 100)));
        rowSplit.setAttribute('aria-valuemax', String(Math.round(b.max * 100)));
        rowSplit.setAttribute('aria-valuenow', String(pct));
        rowSplit.setAttribute('aria-valuetext', 'Km pierduți ' + pct + '%, Alimentări ' + (100 - pct) + '%');
    }

    // ---------------------------------------------------------------- tragere (pointer events)
    // O singura scriere de stil pe cadru; restul (continut, animatii) urmeaza prin CSS / ResizeObserver.
    function bindDrag(handle, axis, onMove, onEnd) {
        var frame = 0, last = null;
        function move(e) {
            last = e;
            if (frame) { return; }
            frame = requestAnimationFrame(function () { frame = 0; if (last) { onMove(last); } });
        }
        function end(e) {
            if (!handle.hasPointerCapture(e.pointerId)) { return; }
            handle.releasePointerCapture(e.pointerId);
            if (frame) { cancelAnimationFrame(frame); frame = 0; }
            if (last) { onMove(last); }
            last = null;
            handle.classList.remove('is-dragging');
            layout.classList.remove('is-resizing');
            document.documentElement.classList.remove('dins-resizing-' + axis);
            handle.removeEventListener('pointermove', move);
            onEnd();
        }
        handle.addEventListener('pointerdown', function (e) {
            if (!wide.matches || (e.pointerType === 'mouse' && e.button !== 0)) { return; }
            e.preventDefault();
            e.stopPropagation();
            handle.setPointerCapture(e.pointerId);
            handle.classList.add('is-dragging');
            layout.classList.add('is-resizing');
            document.documentElement.classList.add('dins-resizing-' + axis);
            handle.addEventListener('pointermove', move);
        });
        handle.addEventListener('pointerup', end);
        handle.addEventListener('pointercancel', end);
        handle.addEventListener('lostpointercapture', function () {
            handle.classList.remove('is-dragging');
            layout.classList.remove('is-resizing');
            document.documentElement.classList.remove('dins-resizing-' + axis);
            handle.removeEventListener('pointermove', move);
        });
        // Splitterul nu e in card, dar nici click-ul de dupa tragere nu trebuie sa ajunga la carduri.
        handle.addEventListener('click', function (e) { e.stopPropagation(); });
    }

    bindDrag(colSplit, 'col', function (e) {
        var rect = layout.getBoundingClientRect();
        sideWidth = rect.right - e.clientX - colSplit.offsetWidth / 2;
        applySide(false);
    }, function () { applySide(true); });

    if (rowSplit) {
        bindDrag(rowSplit, 'row', function (e) {
            var rect = stack.getBoundingClientRect();
            var b = rowBounds();
            if (b.avail <= 0) { return; }
            topRatio = (e.clientY - rect.top - rowSplit.offsetHeight / 2) / b.avail;
            applyRow(false);
        }, function () { applyRow(true); });
    }

    // ---------------------------------------------------------------- tastatura + reset
    colSplit.addEventListener('keydown', function (e) {
        var step = e.shiftKey ? 64 : 16;
        var b = sideBounds();
        var current = side.getBoundingClientRect().width;
        if (e.key === 'ArrowLeft') { sideWidth = current + step; }
        else if (e.key === 'ArrowRight') { sideWidth = current - step; }
        else if (e.key === 'Home') { sideWidth = b.max; }
        else if (e.key === 'End') { sideWidth = b.min; }
        else if (e.key === 'Enter') { sideWidth = null; }
        else { return; }
        e.preventDefault();
        applySide(true);
    });
    colSplit.addEventListener('dblclick', function () { sideWidth = null; applySide(true); });

    if (rowSplit) {
        rowSplit.addEventListener('keydown', function (e) {
            var b = rowBounds();
            var step = (e.shiftKey ? 64 : 16) / Math.max(1, b.avail);
            var current = topRatio == null ? 0.5 : topRatio;
            if (e.key === 'ArrowUp') { topRatio = current - step; }
            else if (e.key === 'ArrowDown') { topRatio = current + step; }
            else if (e.key === 'Home') { topRatio = b.min; }
            else if (e.key === 'End') { topRatio = b.max; }
            else if (e.key === 'Enter') { topRatio = null; }
            else { return; }
            e.preventDefault();
            applyRow(true);
        });
        rowSplit.addEventListener('dblclick', function () { topRatio = null; applyRow(true); });
    }

    // ---------------------------------------------------------------- continut dupa inaltimea cardului
    // Doar praguri: atributul se rescrie numai cand se schimba pragul (fara reflow la fiecare pixel).
    function sizeCard(card, height) {
        // Sub xl cardurile au inaltimea data de continut (fara splittere): aspectul obisnuit.
        if (!wide.matches) { height = 260; }
        var size = height < 215 ? 'short' : (height >= 330 ? 'tall' : 'normal');
        var rows = height < 270 ? 3 : Math.min(6, 3 + Math.floor((height - 270) / 28));
        if (card.getAttribute('data-dins-size') !== size) { card.setAttribute('data-dins-size', size); }
        if (card.getAttribute('data-dins-rows') !== String(rows)) { card.setAttribute('data-dins-rows', String(rows)); }
    }
    var cards = stack.querySelectorAll('.dins-card');
    if (window.ResizeObserver) {
        var cardObserver = new ResizeObserver(function (entries) {
            entries.forEach(function (entry) {
                var box = entry.borderBoxSize && entry.borderBoxSize[0];
                sizeCard(entry.target, box ? box.blockSize : entry.target.getBoundingClientRect().height);
            });
        });
        cards.forEach(function (card) { cardObserver.observe(card); });
        // Latimea/inaltimea disponibila se schimba si fara tragere (fereastra, sidebar, campuri noi in formular).
        var layoutObserver = new ResizeObserver(function () { applySide(false); applyRow(false); });
        layoutObserver.observe(layout);
        layoutObserver.observe(stack);
    } else {
        cards.forEach(function (card) { sizeCard(card, card.getBoundingClientRect().height); });
        window.addEventListener('resize', function () { applySide(false); applyRow(false); });
    }

    applySide(false);
    applyRow(false);
})();
