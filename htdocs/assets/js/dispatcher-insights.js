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

    // ---------------------------------------------------------------- filtre garaj / categorie
    // Garajul si categoria de capacitate vin din Management capacitati (aceleasi reguli ca acolo).
    // Filtrul se aplica si pe card (totaluri, top), sortarea doar pe lista din panoul extins.
    var FILTERS_KEY = 'fleet.dispatcher.insights.filters';
    var SORTS = {
        km: [['lost', 'Km pierduți (desc.)'], ['category', 'Categorie capacitate'], ['garage', 'Garaj'], ['plate', 'Nr. înmatriculare']],
        fuel: [['date', 'Data (recente)'], ['category', 'Categorie capacitate'], ['garage', 'Garaj'], ['plate', 'Nr. înmatriculare'], ['liters', 'Litri (desc.)']]
    };
    var filters = { km: { garage: '', category: '', sort: 'lost' }, fuel: { garage: '', category: '', sort: 'date' } };
    try {
        var savedFilters = JSON.parse(localStorage.getItem(FILTERS_KEY) || '{}');
        ['km', 'fuel'].forEach(function (k) { if (savedFilters && savedFilters[k]) { Object.assign(filters[k], savedFilters[k]); } });
    } catch (e) { /* stocare indisponibila */ }
    function saveFilters() { try { localStorage.setItem(FILTERS_KEY, JSON.stringify(filters)); } catch (e) { /* ignorat */ } }

    function garageKey(g) { return g ? String(g).trim().toUpperCase() : ''; }
    // In fisele vehiculelor acelasi garaj e scris diferit ("LUGOJ" / "Lugoj"): se grupeaza pe cheie,
    // afisat mereu cu prima forma intalnita.
    var garageNames = {};
    function garageName(g) {
        var key = garageKey(g);
        if (key && !garageNames[key]) { garageNames[key] = String(g).trim(); }
        return key ? garageNames[key] : '';
    }
    // Cap tractor + semiremorca = o unitate: intra la garajul oricaruia dintre cele doua vehicule.
    function garagesOf(item) { return (item.garaje && item.garaje.length ? item.garaje : (item.garaj ? [item.garaj] : [])); }
    function matchesFilter(item, f) {
        var keys = garagesOf(item).map(garageKey);
        if (f.garage && (f.garage === '__none' ? keys.length > 0 : keys.indexOf(f.garage) === -1)) { return false; }
        if (f.category && (f.category === '__none' ? item.categorie_id != null : String(item.categorie_id) !== f.category)) { return false; }
        return true;
    }
    function categoryOrder(item) { return item.categorie_id == null ? Infinity : (item.categorie_ordine || 0); }
    // Sortare stabila: in cadrul aceluiasi garaj / aceleiasi categorii ramane ordinea primita (km sau data).
    function sortItems(list, sort, extra) {
        var copy = list.slice();
        if (sort === 'category') {
            copy.sort(function (a, b) { return (categoryOrder(a) - categoryOrder(b)) || String(a.categorie || '').localeCompare(String(b.categorie || ''), 'ro'); });
        } else if (sort === 'garage') {
            copy.sort(function (a, b) {
                var ga = garageKey(a.garaj), gb = garageKey(b.garaj);
                return (ga === '') - (gb === '') || ga.localeCompare(gb, 'ro');
            });
        } else if (sort === 'plate') {
            copy.sort(function (a, b) { return String(a.plate).localeCompare(String(b.plate), 'ro', { numeric: true }); });
        } else if (extra && extra[sort]) {
            copy.sort(extra[sort]);
        }
        return copy;
    }
    // Antet de grup cand lista e ordonata pe categorie sau garaj.
    function groupLabel(item, sort) {
        if (sort === 'category') { return item.categorie || 'Fără categorie'; }
        if (sort === 'garage') { return garageName(item.garaj) || 'Fără garaj'; }
        return null;
    }
    function filterOptions(items) {
        var garages = {}, categories = {};
        items.forEach(function (i) {
            garagesOf(i).forEach(function (name) {
                var g = garageKey(name);
                if (g && !garages[g]) { garages[g] = garageName(name); }
            });
            if (i.categorie_id != null && !categories[i.categorie_id]) { categories[i.categorie_id] = { id: i.categorie_id, name: i.categorie, order: i.categorie_ordine || 0 }; }
        });
        return {
            garages: Object.keys(garages).sort(function (a, b) { return a.localeCompare(b, 'ro'); }).map(function (k) { return [k, garages[k]]; }),
            categories: Object.keys(categories).map(function (k) { return categories[k]; })
                .sort(function (a, b) { return (a.order - b.order) || String(a.name).localeCompare(String(b.name), 'ro'); })
                .map(function (c) { return [String(c.id), c.name]; })
        };
    }
    function selectHtml(kind, field, label, options, value, allLabel) {
        var active = allLabel && value ? ' is-active' : '';
        var html = '<label class="dins-filter' + active + '"><span>' + label + '</span><select class="form-select form-select-sm" data-dins-filter="' + field + '" data-dins-kind="' + kind + '">';
        if (allLabel) { html += '<option value="">' + allLabel + '</option>'; }
        options.forEach(function (o) { html += '<option value="' + esc(o[0]) + '"' + (o[0] === value ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; });
        return html + '</select></label>';
    }
    function renderFilterBar(kind, items) {
        var bar = morph && morph.panel.querySelector('[data-dins-filters]');
        if (!bar) { return; }
        var f = filters[kind];
        var opts = filterOptions(items);
        // O valoare salvata care nu mai exista (garaj redenumit, categorie stearsa) nu ascunde tot.
        if (f.garage && f.garage !== '__none' && !opts.garages.some(function (o) { return o[0] === f.garage; })) { f.garage = ''; }
        if (f.category && f.category !== '__none' && !opts.categories.some(function (o) { return o[0] === f.category; })) { f.category = ''; }
        bar.innerHTML = '<div class="dins-filter-row">'
            + selectHtml(kind, 'garage', 'Filtrează garaj', opts.garages.concat([['__none', 'Fără garaj']]), f.garage, 'Toate garajele')
            + selectHtml(kind, 'category', 'Filtrează categorie', opts.categories.concat([['__none', 'Fără categorie']]), f.category, 'Toate categoriile')
            + selectHtml(kind, 'sort', 'Ordonează / grupează lista', SORTS[kind], f.sort, '')
            + '</div><div class="dins-filter-sum" data-dins-filter-sum></div>';
    }
    function filterNote(kind) {
        var f = filters[kind], parts = [];
        if (f.garage) { parts.push(f.garage === '__none' ? 'fără garaj' : (garageNames[f.garage] || f.garage)); }
        if (f.category) {
            var source = kind === 'km' ? kmState.vehicles : fuelState.items;
            var hit = source.filter(function (i) { return String(i.categorie_id) === f.category; })[0];
            parts.push(f.category === '__none' ? 'fără categorie' : (hit ? hit.categorie : 'categorie'));
        }
        return parts.length ? '<span class="dins-sub dins-filter-note"><i class="bi bi-funnel-fill me-1"></i>' + esc(parts.join(' · ')) + '</span>' : '';
    }
    function tagsHtml(item) {
        var tags = [];
        var garages = garagesOf(item).map(garageName);
        if (garages.length) { tags.push('<i class="bi bi-house-door"></i> ' + esc(garages.join(' / '))); }
        tags.push(item.categorie ? '<i class="bi bi-box-seam"></i> ' + esc(item.categorie) : '<span class="text-muted">fără categorie</span>');
        return '<span class="dins-li-tags">' + tags.join('<span class="dins-dot">·</span>') + '</span>';
    }
    // Numarul afisat in lista: la ansamblu, capul tractor + semiremorca cuplata.
    function plateHtml(item) {
        return '<span class="fw-semibold">' + esc(item.plate)
            + (item.combinatie && item.combinatie !== item.plate ? ' <span class="dins-combo">+ ' + esc(item.combinatie) + '</span>' : '') + '</span>';
    }

    // ---------------------------------------------------------------- alocare
    // Km service: formularul de pe aceeasi pagina (fara reincarcare); cursa: Adauga cursa precompletat.
    // km (optional) = km-ii alesi de operator din deplasarile bifate; fara km, formularul ii ia din GPS pe interval.
    function allocateService(vehicleId, from, to, kmValue) {
        var a = splitDt(from), b = splitDt(to);
        var values = { vehicle_id: String(vehicleId), data_plecare: a.date, ora_plecare: a.time, data_intoarcere: b.date, ora_intoarcere: b.time };
        if (kmValue) { values.km = String(kmValue); }
        if (window.DispatcherServiceKm && typeof window.DispatcherServiceKm.prefill === 'function') {
            closeMorph();
            setTimeout(function () { window.DispatcherServiceKm.prefill(values); }, 380);
            return;
        }
        window.location.href = cfg.dispatcherUrl + '&' + new URLSearchParams(Object.assign({ prefill: 'service' }, values)).toString() + '#service-km-panel';
    }
    function allocateRace(vehicleId, from, to, kmValue) {
        var a = splitDt(from), b = splitDt(to);
        var values = { prefill: 'race', vehicle_id: String(vehicleId), data_inceput: a.date, ora_inceput: a.time, data_sfarsit: b.date, ora_sfarsit: b.time };
        if (kmValue) { values.km = String(kmValue); }
        window.location.href = cfg.dispatcherUrl + '&' + new URLSearchParams(values).toString() + '#add-race-form';
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
        return kmState.vehicles.filter(function (v) { return v.gps != null && kmLost(v) >= MIN_SEGMENT_KM && matchesFilter(v, filters.km); })
            .sort(function (a, b) { return kmLost(b) - kmLost(a); });
    }
    function kmListed() { return sortItems(kmRanked(), filters.km.sort); }
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
            + filterNote('km')
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
            if (morph && morph.kind === 'km') { renderFilterBar('km', kmState.vehicles); }
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
        var ranked = kmListed();
        var pending = kmState.total - kmState.loaded;
        var sort = filters.km.sort;
        var totals = {};
        ranked.forEach(function (v) { var g = groupLabel(v, sort); if (g !== null) { totals[g] = (totals[g] || 0) + kmLost(v); } });
        var lastGroup = null;
        list.innerHTML = (pending > 0 ? '<div class="dins-muted small px-3 py-2"><span class="spinner-border spinner-border-sm me-1"></span>GPS încărcat ' + kmState.loaded + '/' + kmState.total + '</div>' : '')
            + (ranked.length ? ranked.map(function (v) {
                var g = groupLabel(v, sort), head = '';
                if (g !== null && g !== lastGroup) { lastGroup = g; head = '<div class="dins-group"><span>' + esc(g) + '</span><b>' + km(totals[g]) + ' km</b></div>'; }
                return head + '<button type="button" class="dins-list-item' + (kmState.selected === v.sas_vehicle_id ? ' is-active' : '') + '" data-km-vehicle="' + v.sas_vehicle_id + '">'
                    + plateHtml(v) + '<b class="dins-lost">' + km(kmLost(v)) + ' km</b>'
                    + '<span class="dins-li-meta">GPS ' + km(v.gps) + ' · curse ' + km(v.km_efectuati) + ' · service ' + km(v.km_service) + '</span>'
                    + tagsHtml(v) + '</button>';
            }).join('') : '<div class="dins-empty">' + (pending > 0 ? 'Se încarcă…' : (filters.km.garage || filters.km.category ? 'Niciun vehicul cu km neacoperiți pentru filtrul ales.' : 'Niciun vehicul cu km neacoperiți.')) + '</div>');
        var sum = morph.panel.querySelector('[data-dins-filter-sum]');
        if (sum) { sum.textContent = ranked.length + ' vehicule · ' + km(ranked.reduce(function (s, v) { return s + kmLost(v); }, 0)) + ' km pierduți'; }
    }

    // Deplasarile sub 1 km (repozitionari in parcare, zgomot GPS) nu se afiseaza separat: km-ii lor
    // se adauga la deplasarea cea mai apropiata in timp din aceeasi perioada, care isi extinde
    // intervalul. Perioadele care au in total sub 1 km nu se mai listeaza. Doar afisare: totalurile
    // vehiculului (Km pierduti din server) raman neschimbate.
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

    function renderKmDetail(v, d) {
        var pane = morph.panel.querySelector('.dins-pane');
        var html = '<div class="d-flex align-items-center justify-content-between gap-2 mb-2"><h5 class="mb-0">' + esc(v.plate) + '</h5>'
            + '<a class="small" href="' + esc(cfg.kmUrl + '&start=' + kmState.start + '&end=' + kmState.end) + '" target="_blank" rel="noopener">Raport Km pierduți <i class="bi bi-box-arrow-up-right"></i></a></div>'
            + '<div class="dins-kpis mb-3"><div><b>' + km1(d.gps_km) + '</b><span>Km GPS</span></div><div><b>' + km1(d.declared_trips_km) + '</b><span>Km pe curse</span></div>'
            + '<div><b>' + km1(d.declared_service_km) + '</b><span>Km service</span></div><div><b class="dins-lost">' + km1(d.lost_km) + '</b><span>Km pierduți</span></div></div>';

        html += '<h6><i class="bi bi-exclamation-circle text-danger me-1"></i>Perioade rulate fără cursă — alocă-le</h6>';
        var allGaps = (d.gaps || []).map(function (g) { return Object.assign({}, g, { segments: consolidateSegments(g.segments || []) }); });
        var gaps = allGaps.filter(function (g) { return Number(g.km) >= MIN_SEGMENT_KM; }).sort(function (a, b) { return b.km - a.km; });
        var hiddenGaps = allGaps.length - gaps.length;
        var hiddenKm = allGaps.reduce(function (s, g) { return s + (Number(g.km) >= MIN_SEGMENT_KM ? 0 : Number(g.km) || 0); }, 0);
        if (!gaps.length) {
            html += '<div class="dins-muted small">Nicio deplasare GPS de peste 1 km în afara curselor / drumurilor la service.</div>';
        } else {
            var canSelect = !!v.vehicle_id && (cfg.canService || cfg.canRace);
            if (canSelect) {
                html += '<div class="dins-muted small mb-2"><i class="bi bi-check2-square me-1"></i>Bifează deplasările dintr-o perioadă ca să aloci doar km-ii lor (sau toată perioada din bifa ei). '
                    + '<b>Ține apăsat și trage</b> peste rânduri ca să bifezi mai multe deodată (rotița derulează în timpul tragerii); <b>Shift+click</b> bifează un interval.</div>';
            }
            html += '<div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead class="table-light"><tr>' + (canSelect ? '<th class="dins-check-col"></th>' : '')
                + '<th>Interval</th><th>Între</th><th class="text-end">Km GPS</th><th class="text-end">Alocă</th></tr></thead><tbody>';
            gaps.forEach(function (g, i) {
                var between = (g.after ? 'după ' + esc(g.after) : 'început perioadă') + ' → ' + (g.before ? 'înainte de ' + esc(g.before) : 'sfârșit perioadă');
                html += '<tr>' + (canSelect ? '<td class="dins-check-col"><input class="form-check-input" type="checkbox" data-km-gap-all="' + i + '" aria-label="Toate deplasările din perioadă"></td>' : '')
                    + '<td class="text-nowrap"><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-km-gap-toggle="' + i + '"><i class="bi bi-chevron-right"></i></button> '
                    + esc(roDt(g.from)) + ' → ' + esc(roDt(g.to)) + '<div class="dins-muted small">' + g.segments.length + ' deplasări</div></td>'
                    + '<td class="small">' + between + '</td><td class="text-end dins-lost">' + km1(g.km) + '</td>'
                    + '<td>' + allocateButtons(v.vehicle_id, g.from, g.to)
                    + (phaseAllowed(g) ? '<div class="dins-actions mt-1"><button type="button" class="btn btn-sm btn-outline-success" data-km-phase-gap="' + i + '"><i class="bi bi-signpost-split me-1"></i>Fază</button></div>' : '')
                    + '</td></tr>';
                g.segments.forEach(function (s, j) {
                    html += '<tr class="dins-seg-row d-none" data-km-gap="' + i + '">' + (canSelect ? '<td class="dins-check-col"><input class="form-check-input" type="checkbox" data-km-seg="' + i + ':' + j + '" aria-label="Alege deplasarea"></td>' : '')
                        + '<td class="ps-4 text-nowrap">' + esc(roDt(s.start)) + ' – ' + esc(splitDt(s.start).date === splitDt(s.end).date ? splitDt(s.end).time : roDt(s.end)) + '</td>'
                        + '<td>' + esc(s.from || '?') + ' <i class="bi bi-arrow-right"></i> ' + esc(s.to || '?') + (s.partial ? ' <span class="badge text-bg-light">parțial</span>' : '') + '</td>'
                        + '<td class="text-end">' + km1(s.km) + (s.absorbed ? '<div class="dins-absorbed" title="Deplasări sub 1 km adăugate la aceasta: ' + km1(s.absorbedKm) + ' km">+' + s.absorbed + ' sub 1 km</div>' : '') + '</td>'
                        + '<td>' + allocateButtons(v.vehicle_id, s.start, s.end, true) + '</td></tr>';
                });
            });
            html += '</tbody></table></div>';
            if (canSelect) { html += '<div class="dins-alloc-bar" data-km-alloc-bar hidden></div>'; }
        }
        if (hiddenGaps > 0) {
            html += '<div class="dins-muted small mb-2"><i class="bi bi-eye-slash me-1"></i>' + hiddenGaps + (hiddenGaps === 1 ? ' perioadă' : ' perioade')
                + ' cu sub 1 km în total (' + km1(hiddenKm) + ' km) nu ' + (hiddenGaps === 1 ? 'este afișată' : 'sunt afișate') + '. Deplasările sub 1 km sunt adăugate la cea mai apropiată deplasare.</div>';
        }
        kmSelection = { vehicleId: v.vehicle_id, gaps: gaps, gap: null, segs: {} };

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

    // ---------------------------------------------------------------- alocare pe deplasari alese
    // Operatorul bifeaza deplasarile dintr-o singura perioada fara cursa: intervalul alocat merge de
    // la prima la ultima deplasare bifata, iar km = suma lor (editabila, ex. doar o parte din drum).
    // O perioada se intinde intre doua curse, deci deplasari din perioade diferite nu se amesteca.
    var kmSelection = null;
    function selectedSegments() {
        if (!kmSelection || kmSelection.gap === null) { return []; }
        var gap = kmSelection.gaps[kmSelection.gap];
        return gap.segments.filter(function (s, j) { return kmSelection.segs[j]; });
    }
    function renderAllocBar() {
        var bar = morph && morph.panel.querySelector('[data-km-alloc-bar]');
        if (!bar) { return; }
        var segs = selectedSegments();
        morph.panel.querySelectorAll('[data-km-seg]').forEach(function (box) {
            var p = box.getAttribute('data-km-seg').split(':');
            box.checked = kmSelection.gap === +p[0] && !!kmSelection.segs[+p[1]];
            box.closest('tr').classList.toggle('is-picked', box.checked);
        });
        morph.panel.querySelectorAll('[data-km-gap-all]').forEach(function (box) {
            var i = +box.getAttribute('data-km-gap-all');
            var total = kmSelection.gaps[i].segments.length;
            var picked = kmSelection.gap === i ? segs.length : 0;
            box.checked = picked > 0 && picked === total;
            box.indeterminate = picked > 0 && picked < total;
        });
        if (!segs.length) { bar.hidden = true; bar.innerHTML = ''; return; }
        var sum = segs.reduce(function (s, x) { return s + (Number(x.km) || 0); }, 0);
        var from = segs[0].start, to = segs[segs.length - 1].end;
        var gap = kmSelection.gaps[kmSelection.gap];
        var skipped = gap.segments.slice(gap.segments.indexOf(segs[0]), gap.segments.indexOf(segs[segs.length - 1]) + 1).length - segs.length;
        bar.hidden = false;
        bar.innerHTML = '<div class="dins-alloc-info"><b>' + segs.length + ' ' + (segs.length === 1 ? 'deplasare aleasă' : 'deplasări alese') + '</b>'
            + '<span>' + esc(roDt(from)) + ' → ' + esc(roDt(to)) + ' · GPS ' + km1(sum) + ' km</span>'
            + (skipped > 0 ? '<span class="text-warning-emphasis"><i class="bi bi-info-circle me-1"></i>Intervalul cuprinde și ' + skipped + ' deplasări nebifate; km alocați rămân doar cei de mai jos.</span>' : '')
            + '</div><label class="dins-alloc-km"><span>Km de alocat</span><input type="number" class="form-control form-control-sm" min="1" step="1" value="' + Math.max(1, Math.round(sum)) + '" data-km-alloc-value></label>'
            + '<div class="dins-actions">'
            + (cfg.canService ? '<button type="button" class="btn btn-sm btn-warning" data-km-alloc-go="service"><i class="bi bi-tools me-1"></i>Km service</button>' : '')
            + (cfg.canRace ? '<button type="button" class="btn btn-sm btn-primary" data-km-alloc-go="race"><i class="bi bi-plus-lg me-1"></i>Cursă</button>' : '')
            + (phaseAllowed(gap) ? '<button type="button" class="btn btn-sm btn-success" data-km-alloc-go="phase"><i class="bi bi-signpost-split me-1"></i>Fază</button>' : '')
            + '<button type="button" class="btn btn-sm btn-link text-secondary" data-km-alloc-clear>Golește</button></div>';
    }
    function toggleSegment(gapIndex, segIndex, on) {
        if (kmSelection.gap !== gapIndex) { kmSelection.gap = gapIndex; kmSelection.segs = {}; }
        if (on) { kmSelection.segs[segIndex] = true; } else { delete kmSelection.segs[segIndex]; }
        if (!Object.keys(kmSelection.segs).length) { kmSelection.gap = null; }
        renderAllocBar();
    }
    // ---------------------------------------------------------------- selectie cu mouse-ul
    // Apasa pe o deplasare si trage peste celelalte: toate primesc starea primei (bifat / debifat).
    // Shift+click bifeaza intervalul de la ultima deplasare atinsa. Panoul se deruleaza singur la
    // margini, iar rotita mouse-ului continua selectia in timpul tragerii.
    var drag = null;
    var anchor = null;
    function segFromRow(row) {
        var box = row && row.querySelector('[data-km-seg]');
        if (!box) { return null; }
        var p = box.getAttribute('data-km-seg').split(':');
        return { gap: +p[0], seg: +p[1] };
    }
    function paintRange(gapIndex, base, from, to, on) {
        var segs = Object.assign({}, base);
        for (var j = Math.min(from, to); j <= Math.max(from, to); j++) {
            if (on) { segs[j] = true; } else { delete segs[j]; }
        }
        kmSelection.gap = Object.keys(segs).length ? gapIndex : null;
        kmSelection.segs = segs;
        renderAllocBar();
    }
    function dragTo(clientX, clientY) {
        if (!drag) { return; }
        var el = document.elementFromPoint(clientX, clientY);
        var hit = segFromRow(el && el.closest('tr.dins-seg-row'));
        if (!hit || hit.gap !== drag.gap || hit.seg === drag.last) { return; }
        drag.last = hit.seg;
        paintRange(drag.gap, drag.base, drag.start, hit.seg, drag.on);
    }
    function autoScroll() {
        if (!drag) { return; }
        var pane = drag.pane, rect = pane.getBoundingClientRect(), edge = 48, speed = 0;
        // Bara de alocare acopera josul panoului: derularea porneste deasupra ei.
        var bar = pane.querySelector('[data-km-alloc-bar]');
        var bottom = bar && !bar.hidden ? bar.getBoundingClientRect().top : rect.bottom;
        if (drag.y < rect.top + edge) { speed = -Math.ceil((rect.top + edge - drag.y) / 4); }
        else if (drag.y > bottom - edge) { speed = Math.ceil((drag.y - bottom + edge) / 4); }
        if (speed) { pane.scrollTop += speed; dragTo(drag.x, drag.y); }
        drag.frame = requestAnimationFrame(autoScroll);
    }
    function endDrag() {
        if (!drag) { return; }
        cancelAnimationFrame(drag.frame);
        drag.pane.classList.remove('is-drag-selecting');
        drag = null;
    }
    document.addEventListener('pointerdown', function (e) {
        if (!morph || !kmSelection || e.button !== 0) { return; }
        var row = e.target.closest('tr.dins-seg-row');
        if (!row || !morph.panel.contains(row) || e.target.closest('button, a, [data-alloc]')) { return; }
        var hit = segFromRow(row);
        if (!hit) { return; }
        e.preventDefault();
        var sameGap = kmSelection.gap === hit.gap;
        var base = sameGap ? Object.assign({}, kmSelection.segs) : {};
        if (e.shiftKey && anchor && anchor.sel === kmSelection && anchor.gap === hit.gap) {
            paintRange(hit.gap, base, anchor.seg, hit.seg, true);
            anchor = { sel: kmSelection, gap: hit.gap, seg: hit.seg };
            return;
        }
        var on = !(sameGap && kmSelection.segs[hit.seg]);
        anchor = { sel: kmSelection, gap: hit.gap, seg: hit.seg };
        drag = { gap: hit.gap, start: hit.seg, last: hit.seg, base: base, on: on, pane: row.closest('.dins-pane'), x: e.clientX, y: e.clientY, frame: 0 };
        drag.pane.classList.add('is-drag-selecting');
        paintRange(hit.gap, base, hit.seg, hit.seg, on);
        drag.frame = requestAnimationFrame(autoScroll);
    });
    document.addEventListener('pointermove', function (e) {
        if (!drag) { return; }
        drag.x = e.clientX;
        drag.y = e.clientY;
        dragTo(e.clientX, e.clientY);
    });
    document.addEventListener('pointerup', endDrag);
    document.addEventListener('pointercancel', endDrag);
    // Rotita in timpul tragerii: randul de sub cursor se schimba fara pointermove.
    document.addEventListener('scroll', function () { if (drag) { dragTo(drag.x, drag.y); } }, true);
    // Bifa a fost deja schimbata la pointerdown; click-ul mouse-ului nu o mai inverseaza o data.
    // Din tastatura (Spatiu, detail = 0) bifa merge ca inainte, prin evenimentul change.
    document.addEventListener('click', function (e) {
        if (e.detail > 0 && e.target.matches && e.target.matches('[data-km-seg]')) { e.preventDefault(); }
    }, true);

    function toggleGap(gapIndex, on) {
        kmSelection.gap = on ? gapIndex : null;
        kmSelection.segs = {};
        if (on) {
            kmSelection.gaps[gapIndex].segments.forEach(function (s, j) { kmSelection.segs[j] = true; });
            // Deplasarile bifate trebuie sa se vada: perioada se deschide.
            var toggle = morph.panel.querySelector('[data-km-gap-toggle="' + gapIndex + '"]');
            var rows = morph.panel.querySelectorAll('[data-km-gap="' + gapIndex + '"]');
            if (toggle && rows.length && rows[0].classList.contains('d-none')) { toggle.click(); }
        }
        renderAllocBar();
    }
    function allocateSelection(kind) {
        var segs = selectedSegments();
        if (!segs.length) { return; }
        var input = morph.panel.querySelector('[data-km-alloc-value]');
        var value = Math.round(Number(input && input.value));
        if (!(value > 0)) { input.classList.add('is-invalid'); input.focus(); return; }
        var from = segs[0].start, to = segs[segs.length - 1].end;
        if (kind === 'phase') { openPhasePicker(kmSelection.gaps[kmSelection.gap], from, to, value); return; }
        if (kind === 'service') { allocateService(kmSelection.vehicleId, from, to, value); } else { allocateRace(kmSelection.vehicleId, from, to, value); }
    }

    // ---------------------------------------------------------------- km pe o faza
    // O perioada fara cursa sta intre doua curse: km-ii ei se pot pune pe cursa dinainte sau pe
    // cea de dupa — ca faza noua, adaugati la o faza existenta sau la cursa (cand nu are faze).
    // Alegerea deschide fisa cursei precompletata (kmp_*); se salveaza doar la Salveaza.
    function phaseAllowed(gap) { return !!(cfg.canPhase && cfg.kmTripsUrl && gap && (gap.after_trip_id || gap.before_trip_id)); }
    function phaseHref(trip, faza, from, to, value) {
        var params = { kmp_from: String(from).slice(0, 16), kmp_to: String(to).slice(0, 16), kmp_km: String(value) };
        if (faza) { params.faza = String(faza); }
        if (faza === 'noua' && kmSelection && kmSelection.vehicleId) { params.kmp_vehicle = String(kmSelection.vehicleId); }
        return trip.edit_url + '&' + new URLSearchParams(params).toString() + '#race-form';
    }
    function closePhasePicker() {
        var old = morph && morph.panel.querySelector('.dins-phase-picker');
        if (old) { old.remove(); }
    }
    function openPhasePicker(gap, from, to, value) {
        if (!phaseAllowed(gap) || !morph) { return; }
        closePhasePicker();
        var picker = document.createElement('div');
        picker.className = 'dins-phase-picker';
        picker.setAttribute('role', 'dialog');
        picker.setAttribute('aria-label', 'Alege cursa și faza');
        picker.innerHTML = '<div class="dins-phase-card"><div class="dins-phase-head"><div><b>Pune ' + esc(String(value)) + ' km pe o fază</b>'
            + '<span>' + esc(roDt(from)) + ' → ' + esc(roDt(to)) + '</span></div>'
            + '<button type="button" class="btn-close" data-km-phase-close aria-label="Închide"></button></div>'
            + '<div class="dins-phase-body"><div class="dins-empty"><span class="spinner-border spinner-border-sm me-2"></span>Se încarcă cursele…</div></div></div>';
        morph.panel.querySelector('.dins-morph-content').appendChild(picker);
        var ids = [gap.after_trip_id, gap.before_trip_id].filter(Boolean);
        getJson(cfg.kmTripsUrl + '&ids=' + ids.join(',')).then(function (d) {
            if (!d || !d.success) { throw new Error(d && d.error ? d.error : 'Cursele nu au putut fi încărcate.'); }
            var body = picker.querySelector('.dins-phase-body');
            if (!d.trips.length) { body.innerHTML = '<div class="dins-empty">Cursele de lângă această perioadă nu mai există.</div>'; return; }
            body.innerHTML = d.trips.map(function (t) {
                var where = t.id === gap.after_trip_id ? 'cursa dinaintea perioadei' : 'cursa de după perioadă';
                var html = '<div class="dins-phase-trip' + (t.billed ? ' is-billed' : '') + '"><div class="dins-phase-trip-head">'
                    + '<div><a href="' + esc(tripHref(t.id)) + '" target="_blank" rel="noopener" class="fw-semibold">#' + t.id + '</a> '
                    + esc(t.beneficiar) + ' · ' + esc(t.tip_label) + ' <span class="badge text-bg-light fw-normal">' + where + '</span>'
                    + (t.billed ? ' <span class="badge text-bg-secondary">facturată</span>' : '')
                    + '<div class="dins-muted small">' + esc(roDt(t.from)) + ' → ' + esc(roDt(t.to)) + ' · ' + km1(t.km) + ' km' + (t.phases.length ? ' · ' + t.phases.length + ' faze' : '') + '</div></div>';
                if (t.billed) { return html + '</div><div class="dins-muted small">Cursa e facturată: km-ii nu se mai pot pune pe ea.</div></div>'; }
                html += '<a class="btn btn-sm btn-success" href="' + esc(phaseHref(t, 'noua', from, to, value)) + '"><i class="bi bi-plus-lg me-1"></i>Fază nouă</a></div>';
                if (t.phases.length) {
                    html += '<div class="dins-phase-list">' + t.phases.map(function (p) {
                        return '<div class="dins-phase-row"><span><b>Faza ' + p.nr + '</b> · ' + esc(roDt(p.from)) + ' → ' + esc(roDt(p.to)) + ' · ' + km1(p.km) + ' km'
                            + (p.plate ? ' · ' + esc(p.plate) : '') + '</span>'
                            + '<a class="btn btn-sm btn-outline-success" href="' + esc(phaseHref(t, p.id, from, to, value)) + '">+' + esc(String(value)) + ' km aici</a></div>';
                    }).join('') + '</div>';
                } else {
                    html += '<div class="dins-phase-list"><div class="dins-phase-row"><span>Cursa nu are faze: adaugă km-ii direct pe cursă (' + km1(t.km) + ' → ' + km1(t.km + value) + ' km).</span>'
                        + '<a class="btn btn-sm btn-outline-success" href="' + esc(phaseHref(t, '', from, to, value)) + '">+' + esc(String(value)) + ' km la cursă</a></div></div>';
                }
                return html + '</div>';
            }).join('') + '<div class="dins-muted small mt-2"><i class="bi bi-info-circle me-1"></i>Se deschide fișa cursei precompletată; nimic nu se salvează până nu apeși Salvează.</div>';
        }).catch(function (err) {
            picker.querySelector('.dins-phase-body').innerHTML = '<div class="alert alert-danger m-0">' + esc(err.message) + '</div>';
        });
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
            content.innerHTML = '<div class="dins-split"><div class="dins-side-col"><div class="dins-filters" data-dins-filters></div><div class="dins-list"></div></div><div class="dins-pane"><div class="dins-empty">Alege un vehicul din stânga.</div></div></div>';
        });
        morph.kind = 'km';
        renderFilterBar('km', kmState.vehicles);
        renderKmList();
        var first = kmListed()[0];
        if (first) { selectKmVehicle(first.sas_vehicle_id); }
    }

    // ---------------------------------------------------------------- Alimentari neasociate
    var fuelState = { items: [], loaded: false, error: '', canLink: false, selected: null };
    var fuelBody = root.querySelector('[data-dins-body="fuel"]');

    function fuelFiltered() { return fuelState.items.filter(function (i) { return matchesFilter(i, filters.fuel); }); }
    function fuelListed() {
        return sortItems(fuelFiltered(), filters.fuel.sort, {
            date: function (a, b) { return String(b.datetime).localeCompare(String(a.datetime)); },
            liters: function (a, b) { return b.liters - a.liters; }
        });
    }

    function renderFuelCard() {
        if (fuelState.error) { fuelBody.innerHTML = '<span class="dins-muted">' + esc(fuelState.error) + '</span>'; return; }
        var items = fuelFiltered();
        if (!items.length) {
            fuelBody.innerHTML = '<span class="dins-big">0</span><span class="dins-sub">' + (fuelState.items.length
                ? 'Nicio alimentare neasociată pentru filtrul ales (' + fuelState.items.length + ' în total).'
                : 'Toate alimentările CardOil din ultimele 30 de zile au cursă sau Km service.') + '</span>' + filterNote('fuel');
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
            + filterNote('fuel')
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
            if (morph && morph.kind === 'fuel') { renderFilterBar('fuel', fuelState.items); renderFuelList(); }
        }).catch(function (err) { fuelState.error = err.message; renderFuelCard(); });
    }

    function renderFuelList() {
        var list = morph.panel.querySelector('.dins-list');
        var items = fuelListed();
        var sort = filters.fuel.sort;
        var counts = {};
        items.forEach(function (i) { var g = groupLabel(i, sort); if (g !== null) { counts[g] = (counts[g] || 0) + 1; } });
        var lastGroup = null;
        list.innerHTML = items.length ? items.map(function (i) {
            var g = groupLabel(i, sort), head = '';
            if (g !== null && g !== lastGroup) { lastGroup = g; head = '<div class="dins-group"><span>' + esc(g) + '</span><b>' + counts[g] + '</b></div>'; }
            return head + '<button type="button" class="dins-list-item' + (fuelState.selected === i.id ? ' is-active' : '') + '" data-fuel-id="' + i.id + '">'
                + plateHtml(i) + '<b>' + km1(i.liters) + ' L</b>'
                + '<span class="dins-li-meta">' + esc(roDt(i.datetime)) + ' · ' + esc(FUEL_LABELS[i.fuel_type] || i.fuel_type) + (i.driver ? ' · ' + esc(i.driver) : '') + '</span>'
                + tagsHtml(i) + '</button>';
        }).join('') : '<div class="dins-empty">' + (fuelState.items.length ? 'Nicio alimentare neasociată pentru filtrul ales.' : 'Nicio alimentare neasociată.') + '</div>';
        var sum = morph.panel.querySelector('[data-dins-filter-sum]');
        if (sum) {
            var liters = items.reduce(function (s, i) { return s + (i.fuel_type === 'adblue' ? 0 : i.liters); }, 0);
            sum.textContent = items.length + ' alimentări · ' + km(liters) + ' L';
        }
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
                var next = fuelListed()[0];
                if (next) { setTimeout(function () { if (morph && fuelState.selected === id) { selectFuel(next.id); } }, 1200); }
            })
            .catch(function (err) { button.disabled = false; msg.className = 'small mt-1 text-danger'; msg.textContent = err.message; });
    }

    function openFuel(card) {
        if (fuelState.error) { return; }
        openMorph(card, 'Alimentări neasociate', 'CardOil · alimentări din API fără cursă sau Km service, ultimele 30 de zile', 'dins-card--fuel', function (content) {
            content.innerHTML = '<div class="dins-split"><div class="dins-side-col"><div class="dins-filters" data-dins-filters></div><div class="dins-list"></div></div><div class="dins-pane"><div class="dins-empty">Alege o alimentare din stânga.</div></div></div>';
        });
        morph.kind = 'fuel';
        if (!fuelState.loaded) {
            morph.panel.querySelector('.dins-list').innerHTML = '<div class="dins-empty"><span class="spinner-border spinner-border-sm"></span></div>';
            return;
        }
        renderFilterBar('fuel', fuelState.items);
        renderFuelList();
        var first = fuelListed()[0];
        if (first) { selectFuel(first.id); }
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
        if ((t = e.target.closest('[data-km-alloc-go]'))) { allocateSelection(t.getAttribute('data-km-alloc-go')); return; }
        if ((t = e.target.closest('[data-km-phase-close]'))) { closePhasePicker(); return; }
        if ((t = e.target.closest('[data-km-phase-gap]'))) {
            var pg = kmSelection && kmSelection.gaps[+t.getAttribute('data-km-phase-gap')];
            if (pg) { openPhasePicker(pg, pg.from, pg.to, Math.max(1, Math.round(pg.km))); }
            return;
        }
        if ((t = e.target.closest('[data-km-alloc-clear]'))) { kmSelection.gap = null; kmSelection.segs = {}; renderAllocBar(); return; }
        if ((t = e.target.closest('[data-alloc]'))) {
            var args = [t.getAttribute('data-vehicle'), t.getAttribute('data-from'), t.getAttribute('data-to')];
            if (t.getAttribute('data-alloc') === 'service') { allocateService.apply(null, args); } else { allocateRace.apply(null, args); }
        }
    });

    document.addEventListener('change', function (e) {
        if (morph && kmSelection && morph.panel.contains(e.target)) {
            if (e.target.matches('[data-km-seg]')) {
                var p = e.target.getAttribute('data-km-seg').split(':');
                toggleSegment(+p[0], +p[1], e.target.checked);
                return;
            }
            if (e.target.matches('[data-km-gap-all]')) { toggleGap(+e.target.getAttribute('data-km-gap-all'), e.target.checked); return; }
            if (e.target.matches('[data-km-alloc-value]')) { e.target.classList.remove('is-invalid'); return; }
        }
        var select = e.target.closest('[data-dins-filter]');
        if (!select || !morph || !morph.panel.contains(select)) { return; }
        var kind = select.getAttribute('data-dins-kind');
        filters[kind][select.getAttribute('data-dins-filter')] = select.value;
        saveFilters();
        var wrap = select.closest('.dins-filter');
        if (select.getAttribute('data-dins-filter') !== 'sort') { wrap.classList.toggle('is-active', select.value !== ''); }
        // Daca vehiculul / alimentarea deschisa in dreapta a iesit din filtru, se deschide primul din lista.
        if (kind === 'km') {
            renderKmCard();
            renderKmList();
            var kmList = kmListed();
            if (kmList.length && !kmList.some(function (v) { return v.sas_vehicle_id === kmState.selected; })) { selectKmVehicle(kmList[0].sas_vehicle_id); }
        } else {
            renderFuelCard();
            renderFuelList();
            var fuelList = fuelListed();
            if (fuelList.length && !fuelList.some(function (i) { return i.id === fuelState.selected; })) { selectFuel(fuelList[0].id); }
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
