/*
 * Exploratorul de componente (vedere X-ray, de sus, fata la stanga) din randul extins al paginii
 * Stoc Anvelope. Desenul vine din vehicle-xray-art.js (sabloane construite din geometria reala:
 * window.FleetVehicleGeometry); aici: antetul cu navigarea pe categorii, etichetele rotilor cu
 * linii de legatura, punctele componentelor (registrul server VehicleComponentRegistry::memberSlots),
 * legenda, bara de context si detalierea unui ansamblu (zoom + subcomponentele din catalog).
 * Nu inventeaza stari: anvelopele au starea din server, componentele doar ultima inspectie.
 *
 * Flux: vedere generala -> categorie (restul se estompeaza) -> ansamblu (zoom, subcomponente) ->
 * componenta -> panoul ei in acelasi rand (action=tire_fleet_component), fara reincarcare.
 */
(function () {
    'use strict';

    var Geometry = window.FleetVehicleGeometry;
    var Art = window.FleetXrayArt;
    if (!Geometry || !Art) { return; }
    var NS = 'http://www.w3.org/2000/svg';
    var configEl = document.querySelector('[data-ft-config]');
    var componentUrl = configEl ? configEl.dataset.componentUrl : '';
    var writeUrl = configEl ? configEl.dataset.componentWriteUrl : '';
    var canManage = !!(configEl && configEl.dataset.canManage === '1');
    var csrfToken = configEl ? configEl.dataset.csrf : '';
    var WRITE_ACTIONS = { mount: 'component_mount', unmount: 'component_unmount', inspect: 'component_inspect', axle: 'axle_attributes_save' };
    var SUSPENSION_TEXT = { pneumatica: 'Pneumatică', arcuri: 'Arcuri (foi)', mixta: 'Mixtă' };
    var BRAKE_TEXT = { disc: 'Disc', tambur: 'Tambur' };
    var STATE_CLASS = { ok: 'ok', atentie: 'warn', inlocuire: 'crit' };
    var postWrite = function (operation, formData) {
        if (!formData.has('_token')) { formData.append('_token', csrfToken); }
        return window.fetch(writeUrl.replace('component_mount', WRITE_ACTIONS[operation]), { method: 'POST', body: formData, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json().catch(function () { return { ok: false, error: 'Răspuns invalid de la server (' + response.status + ').' }; }); })
            .then(function (data) { if (!data.ok) { throw new Error(data.error || 'Operația nu a putut fi salvată.'); } return data; });
    };
    var categories = [];
    try { categories = JSON.parse((document.getElementById('fxCategories') || {}).textContent || '[]'); } catch (error) { categories = []; }
    var categoryByKey = {};
    categories.forEach(function (category) { categoryByKey[category.key] = category; });
    // Pictogramele capului tractor (cu saua de cuplare) si ale semiremorcii: aceleasi ca in randul
    // listei (_fleet_overview.php), o singura sursa.
    var unitIcons = {};
    try { unitIcons = JSON.parse((document.getElementById('fxUnitIcons') || {}).textContent || '{}'); } catch (error) { unitIcons = {}; }
    // Vizualizarea unui ansamblu: intreg, doar capul tractor sau doar semiremorca. E doar o stare a
    // desenului (nu schimba cuplarea sau datele vehiculelor).
    var VIEW_MODES = { ASSEMBLY: 'assembly', TRACTOR: 'tractor', TRAILER: 'trailer' };
    // Raportul minim al scenei pentru un vehicul izolat: scena creste in inaltime (vehiculul se
    // mareste), fara sa devina mai inalta decat e util pe ecrane late.
    var ISOLATED_MIN_ASPECT = 2.4;
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var STATUS_TEXT = { ok: 'OK', warn: 'Atenție', crit: 'Critic', empty: 'Poziție liberă' };
    var AXLE_TYPE_TEXT = { steering: 'Direcție', traction: 'Tracțiune', trailer: 'Remorcă', universal_balloon: 'Universală / balon' };
    var MEMBER_TEXT = { cap_tractor: 'Cap tractor', camion: 'Camion', semiremorca: 'Semiremorcă', autovehicul: 'Autovehicul' };
    var KIND_TEXT = { ansamblu: 'Ansamblu (cap + semiremorcă)', cap_tractor: 'Cap tractor', camion: 'Camion', semiremorca: 'Semiremorcă', autovehicul: 'Autovehicul' };
    // Markerii cu forma „patrata” din macheta (ansambluri compacte); restul sunt rotunzi.
    var SQUARE_MARKERS = ['motor', 'transmisie', 'electrica', 'racire', 'hidraulic', 'alte'];
    // Markerii aripilor apar doar cand e aleasa categoria (altfel ar acoperi rotile).
    var FILTER_ONLY = ['plastice'];

    var escapeHtml = function (value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    };
    var el = function (name, attrs, parent) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
        if (parent) { parent.appendChild(node); }
        return node;
    };
    // Schimbarea panoului cu tranzitie (View Transitions API cand exista; altfel direct).
    var morph = function (root, update) {
        if (reducedMotion || typeof document.startViewTransition !== 'function') { update(); return; }
        root.style.viewTransitionName = 'fx-detail';
        var transition = document.startViewTransition(update);
        transition.finished.finally(function () { root.style.viewTransitionName = ''; });
    };
    var formatMma = function (kg) {
        if (!kg) { return ''; }
        return kg >= 1000 ? 'MMA ' + (Math.round(kg / 100) / 10).toLocaleString('ro-RO') + ' t' : 'MMA ' + Math.round(kg) + ' kg';
    };
    var vehicleInfo = function (member) {
        return [member.make || member.plate, member.year ? String(member.year) : '', formatMma(member.mma)].filter(Boolean).join(' · ');
    };

    /* ---------------------------------------------------------------- etichete roti */

    var PILL_H = 22;
    var PILL_SIZES = [{ font: 13, char: 7.6, pad: 22 }, { font: 11.5, char: 6.7, pad: 16 }, { font: 10, char: 5.9, pad: 12 }];
    var pillWidth = function (spec) { var size = PILL_SIZES[spec.size]; return size.pad + spec.label.length * size.char; };

    // Etichetele unui rand (sus / jos): se micsoreaza, apoi se decaleaza, doar unde s-ar suprapune.
    var layoutRow = function (pills) {
        pills.sort(function (a, b) { return a.x - b.x; });
        pills.forEach(function (pill) { pill.size = 0; pill.stagger = 0; });
        for (var pass = 0; pass < PILL_SIZES.length; pass++) {
            var changed = false;
            for (var i = 1; i < pills.length; i++) {
                if (pills[i].x - pills[i - 1].x < (pillWidth(pills[i]) + pillWidth(pills[i - 1])) / 2 + 6) {
                    [pills[i - 1], pills[i]].forEach(function (pill) { if (pill.size < PILL_SIZES.length - 1) { pill.size++; changed = true; } });
                }
            }
            if (!changed) { break; }
        }
        for (var j = 1; j < pills.length; j++) {
            if (pills[j].x - pills[j - 1].x < (pillWidth(pills[j]) + pillWidth(pills[j - 1])) / 2 + 6 && !pills[j - 1].stagger) { pills[j].stagger = 1; }
        }
    };

    var drawPill = function (layer, spec) {
        var size = PILL_SIZES[spec.size];
        var w = pillWidth(spec);
        var g = el('g', { class: 'fx-tire-label is-' + spec.status, 'data-fx-pos': String(spec.positionId) }, layer);
        el('path', { class: 'fx-connector', d: 'M' + spec.lineFrom.x.toFixed(1) + ' ' + spec.lineFrom.y.toFixed(1) + ' L' + spec.lineTo.x.toFixed(1) + ' ' + spec.lineTo.y.toFixed(1) }, g);
        el('circle', { class: 'fx-connector-dot', cx: spec.lineTo.x.toFixed(1), cy: spec.lineTo.y.toFixed(1), r: 2.4 }, g);
        el('rect', { class: 'fx-pill', x: (spec.cx - w / 2).toFixed(1), y: (spec.cy - PILL_H / 2).toFixed(1), width: w.toFixed(1), height: PILL_H, rx: PILL_H / 2 }, g);
        var text = el('text', { class: 'fx-pill-text', x: spec.cx.toFixed(1), y: (spec.cy + size.font * 0.36).toFixed(1), 'text-anchor': 'middle', 'font-size': size.font }, g);
        text.textContent = spec.label;
    };

    /* ---------------------------------------------------------------- explorator */

    var Explorer = function (detail, unit, hooks) {
        this.detail = detail;
        this.unit = unit;
        this.hooks = hooks || {};
        this.root = detail.querySelector('[data-fx-explorer]');
        this.panel = detail.querySelector('[data-fx-panel]');
        this.tireArea = detail.querySelector('[data-fx-tire-area]');
        this.unitEl = detail.closest('[data-ft-unit]');
        this.category = '';
        this.slotId = null;
        this.selection = null; // { type: 'axle'|'tire'|'slot', memberId, axleNo, positionId, slot }
        this.viewMode = VIEW_MODES.ASSEMBLY;
        this.tractor = unit.members.filter(function (member) { return member.type !== 'semiremorca'; })[0] || null;
        this.trailer = unit.members.filter(function (member) { return member.type === 'semiremorca'; })[0] || null;
        // Doar ansamblurile reale (cap + semiremorca) se pot desface; camioanele, capetele tractor
        // singure si autovehiculele raman exact ca inainte.
        this.canIsolate = unit.kind === 'ansamblu' && !!this.tractor && !!this.trailer;
        this.render();
    };

    /* ---------------------------------------------------------------- vizualizare: ansamblu / cap / semiremorca */

    // Membrii desenati in vizualizarea curenta (aceleasi obiecte de date, doar filtrate).
    Explorer.prototype.visibleMembers = function () {
        if (this.viewMode === VIEW_MODES.TRACTOR && this.tractor) { return [this.tractor]; }
        if (this.viewMode === VIEW_MODES.TRAILER && this.trailer) { return [this.trailer]; }
        return this.unit.members;
    };

    // Unitatea data desenului: ansamblul intreg sau o copie cu un singur membru (acelasi renderer).
    Explorer.prototype.visibleUnit = function () {
        if (this.viewMode === VIEW_MODES.ASSEMBLY || !this.canIsolate) { return this.unit; }
        var member = this.visibleMembers()[0];
        return Object.assign({}, this.unit, { kind: member.type, members: [member] });
    };

    Explorer.prototype.isVisibleMember = function (memberId) {
        return this.visibleMembers().some(function (member) { return member.vehicle_id === memberId; });
    };

    Explorer.prototype.modeForMember = function (memberId) {
        if (!this.canIsolate) { return VIEW_MODES.ASSEMBLY; }
        return this.trailer.vehicle_id === memberId ? VIEW_MODES.TRAILER : VIEW_MODES.TRACTOR;
    };

    // Schimbarea vizualizarii: antetul, legenda si desenul se refac din aceleasi date, filtrate;
    // selectia compatibila ramane (si inspectorul ei), cea a vehiculului ascuns se sterge.
    Explorer.prototype.setViewMode = function (mode, options) {
        options = options || {};
        if (!this.canIsolate || !VIEW_MODES[String(mode).toUpperCase()]) { mode = VIEW_MODES.ASSEMBLY; }
        if (mode === this.viewMode && !options.force) { return; }
        var previous = this.viewMode;
        this.viewMode = mode;
        var self = this;
        var selection = this.selection;
        var keep = selection && this.isVisibleMember(selection.memberId);
        var menu = this.root.querySelector('.fx-more-menu');
        if (menu) { menu.hidden = true; }
        var axleForm = this.root.querySelector('[data-fx-axle-form]');
        if (axleForm) { axleForm.hidden = true; }
        this.tip.hidden = true;
        this.clearCallouts();
        if (!keep) {
            this.closeInspector();
            if (selection && selection.type === 'tire' && !options.external && this.hooks.onLeaveTires) { this.hooks.onLeaveTires(); }
            this.selection = null;
            this.slotId = null;
            this.root.classList.remove('has-selection', 'has-tire-selection');
            if (selection && selection.type !== 'tire') { this.showTires(); }
        }
        // Categoria aleasa ramane doar daca are puncte in vehiculul afisat (ex. Motor nu exista pe semiremorca).
        if (this.category && this.category !== 'anvelope' && !this.present()[this.category]) { this.category = ''; }
        this.renderChrome();
        this.drawStage();
        this.applyFilter();
        this.renderFooter();
        // Selectia pastrata se reaplica pe desenul nou (marirea pe ansamblu, anvelopa, axa).
        if (keep && this.selection) {
            if (this.selection.type === 'slot') {
                var hot = this.findHot(this.selection.slot.id, this.selection.memberId);
                if (hot) {
                    hot.classList.add('is-selected');
                    this.root.classList.add('has-selection');
                    this.drillDown(hot);
                }
            } else if (this.selection.type === 'tire') {
                this.root.querySelectorAll('.fx-tire, .fx-tire-label').forEach(function (node) { node.classList.toggle('is-selected', node.dataset.fxPos === String(self.selection.positionId)); });
            }
            if (this.selection.axleNo) {
                this.root.querySelectorAll('.fx-axle').forEach(function (node) {
                    node.classList.toggle('is-selected', self.selection.type !== 'slot' && Number(node.dataset.fxMember) === self.selection.memberId && Number(node.dataset.fxAxle) === self.selection.axleNo);
                });
            }
        }
        this.animateFit(previous, mode);
        if (options.focus) {
            var target = this.root.querySelector(mode === VIEW_MODES.ASSEMBLY ? '[data-fx-view="' + previous + '"]' : '[data-fx-view="' + mode + '"]');
            if (target) { target.focus({ preventScroll: true }); }
        }
    };

    // Tranzitie usoara: desenul nou „creste” din locul unde era vehiculul in ansamblu (sau invers).
    Explorer.prototype.animateFit = function (from, to) {
        if (reducedMotion) { return; }
        var stage = this.stage;
        var side = function (mode) { return mode === VIEW_MODES.TRACTOR ? '18%' : (mode === VIEW_MODES.TRAILER ? '72%' : '50%'); };
        stage.style.setProperty('--fx-fit-origin', side(to === VIEW_MODES.ASSEMBLY ? from : to) + ' 50%');
        stage.style.setProperty('--fx-fit-scale', to === VIEW_MODES.ASSEMBLY ? '1.18' : '.72');
        stage.classList.remove('is-fitting');
        void stage.offsetWidth;
        stage.classList.add('is-fitting');
        window.clearTimeout(this.fitTimer);
        this.fitTimer = window.setTimeout(function () { stage.classList.remove('is-fitting'); }, 520);
    };

    Explorer.prototype.findHot = function (slotId, memberId) {
        var hot = null;
        this.root.querySelectorAll('.fx-hot').forEach(function (node) {
            if (!hot && node.dataset.fxSlot === slotId && (!memberId || Number(node.dataset.fxMember) === memberId)) { hot = node; }
        });
        return hot;
    };

    // Antetul si legenda (depind de vehiculele afisate); evenimentele sunt delegate pe radacina.
    Explorer.prototype.renderChrome = function () {
        var head = this.root.querySelector('[data-fx-head]');
        if (head) { head.outerHTML = this.headHtml(); }
        var legend = this.root.querySelector('[data-fx-legend]');
        if (legend) { legend.innerHTML = this.legendHtml(); }
        this.root.classList.toggle('is-isolated', this.viewMode !== VIEW_MODES.ASSEMBLY);
        this.root.dataset.fxMode = this.viewMode;
        var zoomBack = this.root.querySelector('[data-fx-zoom-back]');
        if (zoomBack) {
            zoomBack.lastChild.textContent = this.viewMode === VIEW_MODES.TRACTOR ? 'Capul tractor întreg' : (this.viewMode === VIEW_MODES.TRAILER ? 'Semiremorca întreagă' : 'Vehiculul întreg');
        }
    };

    Explorer.prototype.present = function () {
        var present = {};
        this.visibleMembers().forEach(function (member) {
            if (member.positions.length) { present.anvelope = (present.anvelope || 0) + member.positions.length; }
            (member.slots || []).forEach(function (slot) { present[slot.category] = (present[slot.category] || 0) + 1; });
        });
        return present;
    };

    // Comutatorul ansamblului: capul tractor si semiremorca, lipite (cuplate) in vizualizarea
    // completa; in vizualizarea izolata se desprind, cel ales e evidentiat. Click pe cel ales = inapoi.
    Explorer.prototype.unitSwitchHtml = function () {
        var mode = this.viewMode;
        var button = function (key, member, icon, text) {
            var current = mode === key;
            var label = current ? 'Înapoi la ansamblul complet' : 'Vezi doar ' + text + ' ' + member.plate;
            return '<button type="button" class="fx-unit fx-unit-' + key + (current ? ' is-current' : '') + '" data-fx-view="' + (current ? VIEW_MODES.ASSEMBLY : key) + '" data-fx-unit-key="' + key + '"'
                + ' aria-pressed="' + (current ? 'true' : 'false') + '" aria-label="' + escapeHtml(label) + '" title="' + escapeHtml(label) + '">' + icon + '</button>';
        };
        return '<div class="fx-units' + (mode === VIEW_MODES.ASSEMBLY ? ' is-coupled' : ' is-split') + '" role="group" aria-label="Vehiculul afișat în schemă">'
            + button(VIEW_MODES.TRACTOR, this.tractor, unitIcons.tractor || '', 'capul tractor')
            + '<span class="fx-unit-hitch" aria-hidden="true"></span>'
            + button(VIEW_MODES.TRAILER, this.trailer, unitIcons.trailer || '', 'semiremorca')
            + '</div>';
    };

    Explorer.prototype.headHtml = function () {
        var unit = this.unit;
        var mode = this.viewMode;
        var present = this.present();
        var visible = this.visibleMembers();
        var first = visible[0];
        var partner = visible[1] || null;
        var isolated = mode !== VIEW_MODES.ASSEMBLY;
        var config = unit.kind === 'ansamblu' && partner ? first.layout + ' + ' + partner.axles : (first.type === 'semiremorca' ? first.axles + ' axe' : first.layout);
        var kindText = isolated ? (MEMBER_TEXT[first.type] || '') : (KIND_TEXT[unit.kind] || '');
        var idVisual = this.canIsolate
            ? this.unitSwitchHtml()
            : '<span class="fx-id-icon" aria-hidden="true">' + (this.unitEl ? (this.unitEl.querySelector('.ft-vehicle-icon') || {}).innerHTML || '' : '') + '</span>';
        var toolbar = categories.filter(function (category) { return category.toolbar && present[category.key]; });
        var others = categories.filter(function (category) { return !category.toolbar && present[category.key]; });
        var navButton = function (category) {
            return '<button type="button" class="fx-cat" data-fx-cat="' + category.key + '" aria-pressed="false" style="--fx-accent:' + category.color + '"><span class="fx-cat-icon"><i class="bi ' + escapeHtml(category.icon) + '" aria-hidden="true"></i></span>' + escapeHtml(category.short) + '</button>';
        };
        return '<div class="fx-head" data-fx-head>'
            + '<div class="fx-id">' + idVisual + '<div class="fx-id-text">'
            + (isolated ? '<button type="button" class="fx-crumb" data-fx-view="assembly"><i class="bi bi-arrow-left" aria-hidden="true"></i>Ansamblu complet</button>' : '')
            + '<div class="fx-id-plate">' + escapeHtml(first.plate) + (partner ? '<span> + ' + escapeHtml(partner.plate) + '</span>' : '') + '</div>'
            + '<div class="fx-id-sub">' + escapeHtml(kindText) + (first.make ? ' • ' + escapeHtml(first.make + (partner && partner.make ? ' + ' + partner.make : '')) : '') + '</div></div>'
            + (config ? '<span class="fx-config">' + escapeHtml(config) + '</span>' : '') + '</div>'
            + '<nav class="fx-nav" aria-label="Categorii de componente">'
            + '<button type="button" class="fx-cat fx-cat-all is-active" data-fx-cat="" aria-pressed="true"><span class="fx-cat-icon"><i class="bi bi-layers" aria-hidden="true"></i></span>Vizualizare componente</button>'
            + toolbar.map(navButton).join('')
            + (others.length ? '<div class="fx-more"><button type="button" class="fx-cat" data-fx-more aria-expanded="false" aria-haspopup="true"><span class="fx-cat-icon"><i class="bi bi-grid" aria-hidden="true"></i></span>Alte sisteme</button>'
                + '<div class="fx-more-menu" role="menu" hidden>' + others.map(function (category) {
                    return '<button type="button" role="menuitemradio" class="fx-more-item" data-fx-cat="' + category.key + '" aria-checked="false" style="--fx-accent:' + category.color + '"><i class="bi ' + escapeHtml(category.icon) + '" aria-hidden="true"></i>' + escapeHtml(category.label) + '</button>';
                }).join('') + '</div></div>' : '')
            + '</nav></div>';
    };

    Explorer.prototype.legendHtml = function () {
        var present = this.present();
        var legendItems = categories.filter(function (category) { return present[category.key] && category.legend; });
        var legendMore = categories.filter(function (category) { return present[category.key] && !category.legend; });
        var legendRow = function (category) {
            return '<button type="button" class="fx-legend-item" data-fx-cat="' + category.key + '" style="--fx-accent:' + category.color + '"><span class="fx-legend-icon' + (SQUARE_MARKERS.indexOf(category.key) !== -1 ? ' is-square' : '') + '"><i class="bi ' + escapeHtml(category.icon) + '" aria-hidden="true"></i></span><span class="fx-legend-label">' + escapeHtml(category.label) + '</span></button>';
        };
        return '<div class="fx-legend-title">Legendă componente</div>'
            + legendItems.map(legendRow).join('')
            + (legendMore.length ? '<button type="button" class="fx-legend-more" data-fx-legend-more aria-expanded="false"><i class="bi bi-chevron-down" aria-hidden="true"></i>Alte sisteme (' + legendMore.length + ')</button><div class="fx-legend-extra" hidden>' + legendMore.map(legendRow).join('') + '</div>' : '');
    };

    Explorer.prototype.template = function () {
        return this.headHtml()
            + '<div class="fx-body" data-fx-body><div class="fx-left">'
            + '<div class="fx-stage" data-fx-stage><div class="fx-svg-host" data-fx-svg></div><svg class="fx-callout-lines" data-fx-lines viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"></svg>'
            + '<div class="fx-hots" data-fx-hots></div><div class="fx-callouts" data-fx-callouts></div>'
            + '<button type="button" class="fx-narrow-back" data-fx-narrow-back><i class="bi bi-layout-sidebar-reverse" aria-hidden="true"></i>Înapoi la componentă</button>'
            + '<button type="button" class="fx-zoom-back" data-fx-zoom-back hidden><i class="bi bi-arrow-left" aria-hidden="true"></i>Vehiculul întreg</button>'
            + '<div class="fx-tip" data-fx-tip role="tooltip" hidden></div></div></div>'
            + '<div class="fx-divider" data-fx-divider role="separator" aria-orientation="vertical" aria-label="Redimensionează schema și inspectorul" aria-valuemin="30" aria-valuemax="75" aria-valuenow="55" tabindex="0" hidden><span class="fx-divider-handle" aria-hidden="true"><i class="bi bi-chevron-left"></i><i class="bi bi-chevron-right"></i></span></div>'
            + '<div class="fx-right"><aside class="fx-legend" data-fx-legend aria-label="Legendă componente">' + this.legendHtml() + '</aside><section class="fx-inspector" data-fx-inspector aria-label="Inspector componentă" hidden></section></div></div>'
            + '<div class="fx-footer" data-fx-footer aria-live="polite"></div>';
    };

    Explorer.prototype.render = function () {
        var self = this;
        this.root.innerHTML = this.template();
        this.stage = this.root.querySelector('[data-fx-stage]');
        this.footer = this.root.querySelector('[data-fx-footer]');
        this.tip = this.root.querySelector('[data-fx-tip]');
        this.drawStage();
        this.renderFooter();
        this.applyFilter();
        this.body = this.root.querySelector('[data-fx-body]');
        this.inspector = this.root.querySelector('[data-fx-inspector]');
        this.divider = this.root.querySelector('[data-fx-divider]');
        this.initInspector();
        if (window.ResizeObserver) {
            new window.ResizeObserver(function () { self.applyView(); self.sizeMarkers(); self.drawCalloutLines(); }).observe(this.stage);
        }

        this.root.dataset.fxMode = this.viewMode;
        this.root.addEventListener('click', function (event) {
            // Comutatorul / insigna vehiculului / „← Ansamblu complet”.
            var viewButton = event.target.closest('[data-fx-view]');
            if (viewButton) { self.setViewMode(viewButton.dataset.fxView, { focus: event.detail === 0 }); return; }
            var more = event.target.closest('[data-fx-more]');
            var menu = self.root.querySelector('.fx-more-menu');
            if (more) {
                var open = menu.hidden;
                menu.hidden = !open;
                more.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) { var first = menu.querySelector('button'); if (first) { first.focus(); } }
                return;
            }
            if (menu && !event.target.closest('.fx-more')) { menu.hidden = true; }
            var legendMore = event.target.closest('[data-fx-legend-more]');
            if (legendMore) {
                var extra = self.root.querySelector('.fx-legend-extra');
                extra.hidden = !extra.hidden;
                legendMore.setAttribute('aria-expanded', extra.hidden ? 'false' : 'true');
                return;
            }
            if (event.target.closest('[data-fx-zoom-back]')) { self.overview(); return; }
            var cat = event.target.closest('[data-fx-cat]:not(.fx-hot)');
            if (cat) {
                if (menu) { menu.hidden = true; }
                self.setCategory(cat.dataset.fxCat === self.category && cat.dataset.fxCat !== '' && cat.classList.contains('fx-legend-item') ? '' : cat.dataset.fxCat);
                return;
            }
            var hot = event.target.closest('.fx-hot');
            if (hot) { self.selectSlot(hot.dataset.fxSlot, Number(hot.dataset.fxMember)); return; }
            var callout = event.target.closest('[data-fx-sub]');
            if (callout) { self.selectSubcomponent(callout.dataset.fxSub); return; }
            var chip = event.target.closest('[data-fx-slot-chip]');
            if (chip) { self.selectSlot(chip.dataset.fxSlotChip, Number(chip.dataset.fxChipMember)); return; }
            if (event.target.closest('[data-fx-axle-view]')) { self.showAxlePanel(); return; }
            var label = event.target.closest('.fx-tire-label');
            var tire = event.target.closest('.fx-tire');
            if (label || tire) { self.pickTire((label || tire).dataset.fxPos); return; }
            var axle = event.target.closest('.fx-axle');
            if (axle) { self.selectAxle(Number(axle.dataset.fxMember), Number(axle.dataset.fxAxle)); }
        });
        this.root.addEventListener('keydown', function (event) {
            var tire = event.target.closest && event.target.closest('.fx-tire');
            if (tire && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); self.pickTire(tire.dataset.fxPos); }
            if (event.key === 'Escape') {
                var menu = self.root.querySelector('.fx-more-menu');
                if (menu && !menu.hidden) { menu.hidden = true; return; }
                self.overview();
            }
        });
        // Numele componentei / anvelopei la trecere si la focus.
        var showTip = function (target) {
            var hot = target.closest && target.closest('.fx-hot');
            var tire = target.closest && target.closest('.fx-tire');
            if (!hot && !tire) { self.tip.hidden = true; return; }
            var text = hot ? hot.dataset.fxTip : tire.getAttribute('aria-label');
            var stageBox = self.stage.getBoundingClientRect();
            var box = (hot || tire).getBoundingClientRect();
            self.tip.textContent = text;
            self.tip.hidden = false;
            self.tip.style.left = (box.left + box.width / 2 - stageBox.left) + 'px';
            self.tip.style.top = (box.top - stageBox.top - 8) + 'px';
        };
        this.stage.addEventListener('mouseover', function (event) { showTip(event.target); });
        this.stage.addEventListener('focusin', function (event) { showTip(event.target); });
        this.stage.addEventListener('mouseleave', function () { self.tip.hidden = true; });
        this.stage.addEventListener('focusout', function () { self.tip.hidden = true; });
        this.detail.addEventListener('click', function (event) {
            if (event.target.closest('[data-fx-back]')) { self.overview(); return; }
            var axleEdit = event.target.closest('[data-fx-axle-edit]');
            if (axleEdit) {
                var parts = axleEdit.dataset.fxAxleEdit.split(':');
                self.editAxle(Number(parts[0]), Number(parts[1]));
                return;
            }
            var panelTire = event.target.closest('[data-fx-panel-tire]');
            if (panelTire) { self.pickTire(panelTire.dataset.fxPanelTire); return; }
            var panelSlot = event.target.closest('[data-fx-panel-slot]');
            if (panelSlot) { self.selectSlot(panelSlot.dataset.fxPanelSlot, Number(panelSlot.dataset.fxPanelMember)); return; }
            var open = event.target.closest('[data-fx-open-form]');
            if (open) { self.openForm(open); return; }
            var close = event.target.closest('[data-fx-close-form]');
            if (close) { close.closest('form').hidden = true; }
        });
        // Formularele panoului (montare / inlocuire, demontare, inspectie) se trimit fara reincarcare;
        // stopPropagation: garda globala anti-dublu-submit din app.js nu le atinge.
        this.detail.addEventListener('submit', function (event) {
            var form = event.target.closest('[data-fx-form]');
            if (!form) { return; }
            event.preventDefault();
            event.stopPropagation();
            self.submitForm(form);
        });
        this.detail.addEventListener('change', function (event) {
            var form = event.target.closest('[data-fx-form="mount"]');
            if (form) { stockHint(form); }
        });
        this.detail.addEventListener('input', function (event) {
            var form = event.target.closest('[data-fx-form="mount"]');
            if (form && event.target.name === 'quantity') { stockHint(form); }
        });
    };

    /* ---------------------------------------------------------------- desen + suprapuneri */

    Explorer.prototype.drawStage = function () {
        var self = this;
        Art.ensureDefs(categories);
        var art = Art.draw(this.stage, this.visibleUnit());
        var isolated = this.canIsolate && this.viewMode !== VIEW_MODES.ASSEMBLY;
        this.art = art;
        this.view = { x: art.view.x, y: art.view.y, w: art.view.w, h: art.view.h };
        this.fullView = { x: art.view.x, y: art.view.y, w: art.view.w, h: art.view.h };
        this.stage.querySelector('[data-fx-svg]').replaceChildren(art.svg);
        this.stage.style.setProperty('--fx-aspect', art.view.w + ' / ' + art.view.h);
        this.stage.classList.toggle('is-isolated', isolated);

        // Etichetele anvelopelor, cu linie de legatura spre roata lor.
        var rows = { top: [], bottom: [] };
        var inner = [];
        art.layout.members.forEach(function (member) {
            var placed = Geometry.tireRects(member.geo);
            placed.rects.forEach(function (item) {
                var ax = member.offset + item.ax;
                var position = item.position;
                var spec = { label: position.code, status: position.status, positionId: position.id };
                if (item.isInner) {
                    // Interioara: in dreapta anvelopei, la nivelul ei (ca in macheta); langa o axa
                    // tandem, eticheta trece spre axul vehiculului ca sa nu atinga roata vecina.
                    var next = member.geo.axles.filter(function (axle) { return axle.x > item.ax && axle.x - item.ax < 1.75; }).length > 0;
                    var mid = (item.y0 + item.y1) / 2;
                    spec.size = 1;
                    if (!next) {
                        spec.cx = art.X(ax + item.length / 2) + 16 + pillWidth(spec) / 2;
                        spec.cy = art.Y(mid);
                        spec.lineFrom = { x: spec.cx - pillWidth(spec) / 2, y: spec.cy };
                        spec.lineTo = { x: art.X(ax + item.length / 2) + 2, y: spec.cy };
                    } else {
                        var towardCenter = item.isTop ? 1 : -1;
                        spec.cx = art.X(ax);
                        spec.cy = art.Y(item.isTop ? item.y1 : item.y0) + towardCenter * (PILL_H / 2 + 12);
                        spec.lineFrom = { x: spec.cx, y: spec.cy - towardCenter * PILL_H / 2 };
                        spec.lineTo = { x: spec.cx, y: art.Y(item.isTop ? item.y1 : item.y0) };
                    }
                    inner.push(spec);
                } else {
                    spec.x = art.X(ax);
                    spec.edge = art.Y(item.isTop ? item.y0 : item.y1);
                    rows[item.isTop ? 'top' : 'bottom'].push(spec);
                }
            });
        });
        ['top', 'bottom'].forEach(function (key) {
            layoutRow(rows[key]);
            rows[key].forEach(function (spec) {
                var base = key === 'top' ? art.labelBand / 2 : art.view.h - art.pillBand - art.labelBand / 2;
                spec.cx = spec.x;
                spec.cy = base + (key === 'top' ? -1 : 1) * spec.stagger * (PILL_H + 4) * 0.55;
                spec.lineFrom = { x: spec.cx, y: spec.cy + (key === 'top' ? PILL_H / 2 : -PILL_H / 2) };
                spec.lineTo = { x: spec.cx, y: spec.edge + (key === 'top' ? -2 : 2) };
                drawPill(art.labels, spec);
            });
        });
        inner.forEach(function (spec) { drawPill(art.labels, spec); });

        // Punctele componentelor (butoane HTML, aliniate prin proiectie la orice scara / zoom).
        var hots = this.stage.querySelector('[data-fx-hots]');
        hots.innerHTML = '';
        this.anchors = [];
        art.layout.members.forEach(function (member) {
            (member.data.slots || []).forEach(function (slot) {
                var category = categoryByKey[slot.category];
                if (!category) { return; }
                var anchor = Geometry.slotAnchor(slot, member.geo);
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'fx-hot fx-hot-' + slot.category + (SQUARE_MARKERS.indexOf(slot.category) !== -1 ? ' is-square' : '') + (slot.records ? ' has-records' : '')
                    + (FILTER_ONLY.indexOf(slot.category) !== -1 ? ' is-filter-only' : '') + (slot.state ? ' fx-state-' + STATE_CLASS[slot.state] : '');
                button.dataset.fxSlot = slot.id;
                button.dataset.fxCat = slot.category;
                button.dataset.fxMember = String(member.data.vehicle_id);
                if (slot.axle) { button.dataset.fxAxle = String(slot.axle); }
                button.style.setProperty('--fx-accent', category.color);
                button.setAttribute('aria-label', member.data.plate + ': ' + slot.label + (slot.records ? ' (are înregistrări)' : ''));
                button.dataset.fxTip = slot.label + (slot.records ? ' · are înregistrări' : '');
                button.innerHTML = '<i class="bi ' + escapeHtml(category.icon) + '" aria-hidden="true"></i>';
                button._slot = slot;
                button._member = member.data;
                hots.appendChild(button);
                self.anchors.push({ node: button, x: art.X(member.offset + anchor.x), y: art.Y(anchor.y) });
            });
            // Numele vehiculului, sub el (albastru = cu motor, violet = semiremorca).
            var trailer = member.geo.type === 'semiremorca';
            // In ansamblu, insigna fiecarui vehicul il izoleaza (ca si comutatorul din antet).
            var switchable = self.canIsolate && !isolated;
            var pill = document.createElement(switchable ? 'button' : 'span');
            pill.className = 'fx-member ' + (trailer ? 'is-trailer' : 'is-motor') + (switchable ? ' is-switch' : '');
            pill.innerHTML = '<i class="bi ' + (trailer ? 'bi-truck-flatbed' : 'bi-truck-front') + '" aria-hidden="true"></i>'
                + escapeHtml((MEMBER_TEXT[member.data.type] || 'Vehicul') + ' (' + (trailer ? member.data.axles + ' axe' : member.data.layout) + ')')
                + (isolated ? '<span class="fx-member-plate">' + escapeHtml(member.data.plate) + '</span>' : '')
                + (switchable ? '<i class="bi bi-arrows-angle-expand fx-member-go" aria-hidden="true"></i>' : '');
            pill.title = member.data.plate + (member.data.make ? ' · ' + member.data.make : '');
            if (switchable) {
                pill.type = 'button';
                pill.dataset.fxView = trailer ? VIEW_MODES.TRAILER : VIEW_MODES.TRACTOR;
                pill.setAttribute('aria-label', 'Vezi doar ' + (trailer ? 'semiremorca ' : 'capul tractor ') + member.data.plate);
                pill.title = 'Vezi doar ' + (trailer ? 'semiremorca' : 'capul tractor') + ' · ' + pill.title;
            }
            hots.appendChild(pill);
            var visibleStart = Math.max(member.offset, art.layout.members.length > 1 && trailer ? art.layout.members[0].offset + art.layout.members[0].geo.length : member.offset);
            self.anchors.push({ node: pill, x: art.X((visibleStart + member.offset + member.geo.length) / 2), y: art.view.h - art.pillBand / 2, member: true });
        });
        if (isolated) { this.fitIsolated(); }
        this.applyView();
        this.sizeMarkers();
    };

    // Vehiculul izolat: vederea se strange pe continutul desenat (vehicul + etichete), pe toata
    // inaltimea; scena primeste un raport mai inalt, deci vehiculul se mareste, iar fitted() il
    // centreaza fara sa-l deformeze. Coordonatele raman cele ale desenului (markerii raman aliniati).
    Explorer.prototype.fitIsolated = function () {
        var art = this.art;
        var member = art.layout.members[0];
        var x0 = art.X(member.offset - 0.6);
        var x1 = art.X(member.offset + member.geo.length + 0.6);
        try {
            var box = art.svg.getBBox();
            if (box && box.width > 0) { x0 = Math.min(x0, box.x); x1 = Math.max(x1, box.x + box.width); }
        } catch (error) { /* desen inca neafisat: ramane caseta calculata din geometrie */ }
        x0 = Math.max(art.view.x, x0 - 24);
        x1 = Math.min(art.view.x + art.view.w, x1 + 24);
        var view = { x: x0, y: art.view.y, w: x1 - x0, h: art.view.h };
        this.view = { x: view.x, y: view.y, w: view.w, h: view.h };
        this.fullView = { x: view.x, y: view.y, w: view.w, h: view.h };
        this.stage.style.setProperty('--fx-aspect', Math.max(view.w / view.h, ISOLATED_MIN_ASPECT).toFixed(4));
    };

    // Vederea afisata: vederea dorita (this.view) extinsa la raportul real al scenei, ca desenul
    // sa nu se deformeze cand scena isi schimba latimea (inspectorul deschis, separatorul tras).
    Explorer.prototype.fitted = function () {
        var view = this.view;
        var width = this.stage.clientWidth;
        var height = this.stage.clientHeight;
        if (!width || !height) { return view; }
        var aspect = width / height;
        var current = view.w / view.h;
        if (Math.abs(aspect - current) < 0.002) { return view; }
        if (aspect > current) {
            var wide = view.h * aspect;
            return { x: view.x - (wide - view.w) / 2, y: view.y, w: wide, h: view.h };
        }
        var tall = view.w / aspect;
        return { x: view.x, y: view.y - (tall - view.h) / 2, w: view.w, h: tall };
    };

    Explorer.prototype.applyView = function () {
        if (!this.art) { return; }
        var shown = this.fitted();
        this.shown = shown;
        this.art.svg.setAttribute('viewBox', [shown.x, shown.y, shown.w, shown.h].map(function (v) { return v.toFixed(1); }).join(' '));
        this.placeOverlay();
    };

    // Pozitia procentuala a suprapunerilor pentru vederea afisata (completa sau marita).
    Explorer.prototype.placeOverlay = function () {
        var view = this.shown || this.view;
        this.anchors.forEach(function (anchor) {
            anchor.node.style.left = ((anchor.x - view.x) / view.w * 100).toFixed(3) + '%';
            anchor.node.style.top = ((anchor.y - view.y) / view.h * 100).toFixed(3) + '%';
        });
    };

    Explorer.prototype.sizeMarkers = function () {
        var width = this.stage.clientWidth || 1200;
        var zoom = this.fullView ? this.fullView.w / this.view.w : 1;
        this.stage.style.setProperty('--fx-m', Math.max(18, Math.min(36, width * 0.021 * Math.min(zoom, 1.6))).toFixed(1) + 'px');
    };

    // Vederea (viewBox) animata: marirea pe un ansamblu si revenirea la vehiculul intreg.
    Explorer.prototype.setView = function (target, done) {
        var self = this;
        var from = { x: this.view.x, y: this.view.y, w: this.view.w, h: this.view.h };
        var apply = function (view) {
            self.view = view;
            self.applyView();
        };
        if (reducedMotion) { apply(target); this.sizeMarkers(); if (done) { done(); } return; }
        var start = null;
        var step = function (time) {
            if (start === null) { start = time; }
            var t = Math.min(1, (time - start) / 420);
            var e = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
            apply({ x: from.x + (target.x - from.x) * e, y: from.y + (target.y - from.y) * e, w: from.w + (target.w - from.w) * e, h: from.h + (target.h - from.h) * e });
            if (t < 1) { window.requestAnimationFrame(step); } else { self.sizeMarkers(); if (done) { done(); } }
        };
        window.requestAnimationFrame(step);
    };

    // Ansamblul unui loc: caseta piesei desenate sau o zona in jurul punctului (capete de axa).
    Explorer.prototype.assemblyBox = function (hot) {
        var art = this.art;
        var anchor = this.anchors.filter(function (item) { return item.node === hot; })[0];
        var partNode = null;
        this.root.querySelectorAll('.fx-part[data-fx-cat="' + hot.dataset.fxCat + '"][data-fx-member="' + hot.dataset.fxMember + '"]').forEach(function (node) {
            if (!partNode && node.dataset.box) {
                var b = node.dataset.box.split(',').map(Number);
                if (anchor && anchor.x >= b[0] - 4 && anchor.x <= b[2] + 4 && anchor.y >= Math.min(b[1], b[3]) - 4 && anchor.y <= Math.max(b[1], b[3]) + 4) { partNode = b; }
            }
        });
        var cx = anchor ? anchor.x : art.view.w / 2;
        var cy = anchor ? anchor.y : art.view.h / 2;
        var halfW = partNode ? Math.max((partNode[2] - partNode[0]) / 2, art.S(0.8)) : art.S(0.9);
        var halfH = partNode ? Math.max(Math.abs(partNode[3] - partNode[1]) / 2, art.S(0.55)) : art.S(0.6);
        // Raportul vederii complete, cu loc in stanga si in dreapta pentru subcomponente.
        var w = Math.max(halfW * 2 * 3.4, halfH * 2 * 1.5 * (art.view.w / art.view.h), art.S(3.4));
        var h = w * art.view.h / art.view.w;
        return { x: cx - w / 2, y: cy - h / 2, w: w, h: h, cx: cx, cy: cy };
    };

    Explorer.prototype.clearCallouts = function () {
        this.drill = null;
        this.stage.querySelector('[data-fx-callouts]').innerHTML = '';
        this.stage.querySelector('[data-fx-lines]').innerHTML = '';
        this.root.classList.remove('is-zoomed');
        this.stage.querySelector('[data-fx-zoom-back]').hidden = true;
    };

    // Detalierea ansamblului: marire pe piesa si subcomponentele ei din catalogul Reparatii Auto.
    Explorer.prototype.drillDown = function (hot) {
        var self = this;
        var slot = hot._slot;
        var category = categoryByKey[slot.category];
        var components = (category && category.components) || [];
        var box = this.assemblyBox(hot);
        this.clearCallouts();
        this.drill = { box: box, color: category ? category.color : '#1d6ff2' };
        this.root.classList.add('is-zoomed');
        this.stage.querySelector('[data-fx-zoom-back]').hidden = false;
        this.setView({ x: box.x, y: box.y, w: box.w, h: box.h }, function () {
            if (self.slotId !== slot.id) { return; }
            var holder = self.stage.querySelector('[data-fx-callouts]');
            if (!components.length) {
                holder.innerHTML = '<div class="fx-callout fx-callout-note" style="left:50%;top:12%">Catalogul Reparații Auto nu are subcomponente pentru „' + escapeHtml(category.label) + '”.</div>';
                return;
            }
            var left = components.slice(0, Math.ceil(components.length / 2));
            var right = components.slice(Math.ceil(components.length / 2));
            var html = '';
            [[left, 13], [right, 87]].forEach(function (column) {
                column[0].forEach(function (component, index) {
                    var y = column[0].length === 1 ? 50 : 9 + index * (74 / (column[0].length - 1));
                    html += '<button type="button" class="fx-callout' + (column[1] < 50 ? ' is-left' : ' is-right') + '" data-fx-sub="' + escapeHtml(component.key) + '" style="left:' + column[1] + '%;top:' + y.toFixed(2) + '%;--fx-accent:' + category.color + '"><span class="fx-callout-dot"></span>' + escapeHtml(component.name) + '</button>';
                });
            });
            holder.innerHTML = html;
            if (self.subKey) {
                var selected = holder.querySelector('[data-fx-sub="' + self.subKey + '"]');
                if (selected) { selected.classList.add('is-selected'); }
            }
            self.drawCalloutLines();
        });
    };

    // Liniile pornesc din marginea fiecarei etichete (masurata) spre centrul ansamblului; se refac
    // la orice schimbare de dimensiune, ca sa ramana legate de piesa.
    Explorer.prototype.drawCalloutLines = function () {
        var lines = this.stage.querySelector('[data-fx-lines]');
        if (!this.drill) { lines.innerHTML = ''; return; }
        var view = this.shown || this.view;
        var cxp = (this.drill.box.cx - view.x) / view.w * 100;
        var cyp = (this.drill.box.cy - view.y) / view.h * 100;
        var color = this.drill.color;
        var stageRect = this.stage.getBoundingClientRect();
        if (!stageRect.width || !stageRect.height) { return; }
        lines.innerHTML = Array.prototype.map.call(this.stage.querySelectorAll('[data-fx-sub]'), function (button) {
            var rect = button.getBoundingClientRect();
            var x = ((button.classList.contains('is-left') ? rect.right : rect.left) - stageRect.left) / stageRect.width * 100;
            var y = (rect.top + rect.height / 2 - stageRect.top) / stageRect.height * 100;
            return '<line class="' + (button.classList.contains('is-selected') ? 'is-selected' : '') + '" x1="' + x.toFixed(2) + '" y1="' + y.toFixed(2) + '" x2="' + cxp.toFixed(2) + '" y2="' + cyp.toFixed(2) + '" style="--fx-accent:' + color + '"></line>';
        }).join('');
    };

    /* ---------------------------------------------------------------- filtre si selectii */

    // Filtrul: categoria activa se coloreaza, restul se estompeaza; vederea generala arata toate punctele.
    Explorer.prototype.applyFilter = function () {
        var self = this;
        var category = this.category;
        this.root.classList.toggle('is-filtered', category !== '');
        this.root.classList.toggle('is-tires', category === 'anvelope');
        this.root.querySelectorAll('[data-fx-cat]').forEach(function (node) {
            if (node.classList.contains('fx-hot')) {
                var visible = category === '' ? !node.classList.contains('is-filter-only') : node.dataset.fxCat === category;
                node.classList.toggle('is-hidden', !visible);
                node.tabIndex = visible ? 0 : -1;
                node.classList.toggle('is-selected', node.dataset.fxSlot === self.slotId && self.selection && self.selection.memberId === Number(node.dataset.fxMember));
                return;
            }
            var active = node.dataset.fxCat === category;
            node.classList.toggle('is-active', active);
            if (node.hasAttribute('aria-pressed')) { node.setAttribute('aria-pressed', active ? 'true' : 'false'); }
            if (node.hasAttribute('aria-checked')) { node.setAttribute('aria-checked', active ? 'true' : 'false'); }
        });
        var moreBtn = this.root.querySelector('[data-fx-more]');
        if (moreBtn) { moreBtn.classList.toggle('is-active', !!(category && !(categoryByKey[category] || {}).toolbar)); }
        this.root.querySelectorAll('.fx-part').forEach(function (node) {
            node.classList.toggle('is-active', category !== '' && node.dataset.fxCat === category);
            node.classList.toggle('is-dim', category !== '' && node.dataset.fxCat !== category);
        });
    };

    Explorer.prototype.setCategory = function (category) {
        this.closeInspector();
        var axleForm = this.root.querySelector('[data-fx-axle-form]');
        if (axleForm) { axleForm.hidden = true; }
        var wasZoomed = this.root.classList.contains('is-zoomed');
        this.category = category;
        this.slotId = null;
        if (this.selection && this.selection.type === 'slot') { this.selection = null; }
        this.root.classList.remove('has-selection');
        this.clearCallouts();
        if (wasZoomed) { this.setView(this.fullView); }
        this.applyFilter();
        if (category === 'anvelope' || category === '') { this.showTires(); }
        this.renderFooter();
    };

    // Bara de context: selectia (axa / anvelopa / componenta), tipul, functiile, vehiculul si actiunea.
    Explorer.prototype.renderFooter = function () {
        var unit = this.unit;
        var selection = this.selection;
        var member = selection ? unit.members.filter(function (item) { return item.vehicle_id === selection.memberId; })[0] : null;
        var owner = member || this.visibleMembers()[0];
        var cards = '';
        var axleNo = selection ? selection.axleNo : null;
        if (!selection) {
            cards = this.category && this.category !== 'anvelope'
                ? '<span class="fx-card is-strong"><i class="bi ' + escapeHtml(categoryByKey[this.category].icon) + '" style="color:' + categoryByKey[this.category].color + '" aria-hidden="true"></i>' + escapeHtml(categoryByKey[this.category].label) + '</span><span class="fx-card is-muted">Alege un punct din schemă pentru detalii</span>'
                : '<span class="fx-card is-strong"><i class="bi bi-info-circle-fill fx-ic-blue" aria-hidden="true"></i>Selectează o axă, o anvelopă sau o componentă</span>';
        } else {
            var positions = member.positions.filter(function (position) { return position.axle === axleNo; });
            var type = positions[0] ? positions[0].axle_type : '';
            var dual = positions.some(function (position) { return position.dual; });
            var functions = [];
            (member.slots || []).forEach(function (slot) {
                if (slot.axle === axleNo && categoryByKey[slot.category] && slot.category !== 'plastice' && functions.indexOf(categoryByKey[slot.category].short) === -1) { functions.push(categoryByKey[slot.category].short); }
            });
            var title = selection.type === 'tire' ? selection.code : (selection.type === 'slot' ? selection.slot.label : (unit.members.length > 1 ? member.plate + ' · ' : '') + 'A' + axleNo);
            var titleLabel = selection.type === 'slot' ? '' : 'Axă selectată: ';
            cards = '<span class="fx-card is-strong"><i class="bi ' + (selection.type === 'slot' ? escapeHtml(categoryByKey[selection.slot.category].icon) + '" style="color:' + categoryByKey[selection.slot.category].color : 'bi-info-circle-fill fx-ic-blue') + '" aria-hidden="true"></i>' + titleLabel + escapeHtml(title) + '</span>';
            if (axleNo) {
                cards += '<span class="fx-card"><i class="bi bi-sign-turn-right" aria-hidden="true"></i>Tip: ' + escapeHtml(AXLE_TYPE_TEXT[type] || 'nedefinit') + (dual ? ' · roți duble' : '') + '</span>'
                    + '<span class="fx-card"><i class="bi bi-gear" aria-hidden="true"></i>Funcții: ' + escapeHtml(functions.join(', ') || '—') + '</span>';
                var flags = axleFlagsText((member.axle_attrs || {})[axleNo]);
                if (flags) { cards += '<span class="fx-card"><i class="bi bi-sliders" aria-hidden="true"></i>' + escapeHtml(flags) + '</span>'; }
                if (canManage) { cards += '<button type="button" class="fx-card is-button" data-fx-axle-edit="' + member.vehicle_id + ':' + axleNo + '"><i class="bi bi-pencil" aria-hidden="true"></i>Atributele axei</button>'; }
            } else if (selection.type === 'slot') {
                cards += selection.slot.records ? '<span class="fx-card"><span class="fx-dot-records"></span>Are înregistrări în mentenanță</span>' : '<span class="fx-card is-muted">Fără înregistrări încă</span>';
            }
        }
        this.footer.innerHTML = '<div class="fx-foot-left">' + cards + '</div><span class="fx-foot-sep" aria-hidden="true"></span>'
            + '<span class="fx-card fx-card-vehicle"><i class="bi bi-truck" aria-hidden="true"></i><strong>Vehicul:</strong>&nbsp;' + escapeHtml(vehicleInfo(owner)) + '</span>'
            + '<button type="button" class="fx-axle-btn" data-fx-axle-view' + (axleNo ? '' : ' disabled') + '>Vezi componentele axei <i class="bi bi-arrow-right" aria-hidden="true"></i></button>';
    };

    Explorer.prototype.selectAxle = function (memberId, axleNo) {
        this.selection = { type: 'axle', memberId: memberId, axleNo: axleNo };
        this.root.querySelectorAll('.fx-axle').forEach(function (node) {
            node.classList.toggle('is-selected', Number(node.dataset.fxMember) === memberId && Number(node.dataset.fxAxle) === axleNo);
        });
        this.renderFooter();
    };

    // Componentele axei alese (anvelope + locuri de componente), in panoul randului.
    Explorer.prototype.showAxlePanel = function () {
        var self = this;
        var selection = this.selection;
        if (!selection || !selection.axleNo) { return; }
        var member = this.unit.members.filter(function (item) { return item.vehicle_id === selection.memberId; })[0];
        var axleNo = selection.axleNo;
        var positions = member.positions.filter(function (position) { return position.axle === axleNo; });
        var slots = (member.slots || []).filter(function (slot) { return slot.axle === axleNo; });
        var attrs = (member.axle_attrs || {})[axleNo];
        var html = '<div class="fx-panel-head"><div class="fx-panel-title"><span class="fx-panel-icon" style="--fx-accent:#1570ef"><i class="bi bi-diagram-2" aria-hidden="true"></i></span><div>'
            + '<div class="fx-panel-name">Componentele axei ' + axleNo + '</div><div class="small text-muted">' + escapeHtml(member.plate + ' · ' + (positions[0] ? positions[0].axle_label : '') + (positions.some(function (p) { return p.dual; }) ? ' · roți duble' : '') + (axleFlagsText(attrs) ? ' · ' + axleFlagsText(attrs) : '')) + '</div></div></div>'
            + '<div class="d-flex flex-wrap gap-2">' + (canManage ? '<button type="button" class="btn btn-sm btn-outline-primary" data-fx-axle-edit="' + member.vehicle_id + ':' + axleNo + '"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Atributele axei</button>' : '')
            + '<button type="button" class="btn btn-sm btn-light border" data-fx-back><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Înapoi la vehicul</button></div></div>'
            + '<div class="fx-axle-grid"><section><h3 class="fx-section-title"><i class="bi bi-life-preserver" aria-hidden="true"></i>Anvelope</h3><div class="fx-tiles">'
            + positions.map(function (position) {
                return '<button type="button" class="fx-tile is-' + position.status + '" data-fx-panel-tire="' + position.id + '"><span class="fx-tile-code">' + escapeHtml(position.code) + '</span><span class="fx-tile-name">' + escapeHtml(position.tire ? position.tire.label : 'Poziție liberă') + '</span>'
                    + '<span class="fx-tile-meta">' + escapeHtml(position.tire ? [position.tire.size, STATUS_TEXT[position.status], position.tire.wear !== null ? 'uzură ' + Math.round(position.tire.wear) + '%' : ''].filter(Boolean).join(' · ') : position.side_label) + '</span></button>';
            }).join('') + '</div></section>'
            + '<section><h3 class="fx-section-title"><i class="bi bi-gear" aria-hidden="true"></i>Componente pe axă</h3><div class="fx-tiles">'
            + (slots.length ? slots.map(function (slot) {
                var category = categoryByKey[slot.category];
                return '<button type="button" class="fx-tile is-component' + (slot.state ? ' fx-state-' + STATE_CLASS[slot.state] : '') + '" data-fx-panel-slot="' + escapeHtml(slot.id) + '" data-fx-panel-member="' + member.vehicle_id + '" style="--fx-accent:' + category.color + '"><span class="fx-tile-code"><i class="bi ' + escapeHtml(category.icon) + '" aria-hidden="true"></i></span><span class="fx-tile-name">' + escapeHtml(slot.label) + '</span>'
                    + '<span class="fx-tile-meta">' + (slot.state ? 'Ultima inspecție: ' + escapeHtml({ ok: 'OK', atentie: 'Atenție', inlocuire: 'Necesită înlocuire' }[slot.state]) : (slot.records ? 'Are înregistrări' : 'Fără înregistrări')) + '</span></button>';
            }).join('') : '<div class="text-muted small">Nicio componentă pe această axă.</div>')
            + '</div></section></div>';
        if (this.hooks.onLeaveTires) { this.hooks.onLeaveTires(); }
        morph(this.detail, function () {
            self.request = null;
            self.panel.hidden = false;
            self.tireArea.hidden = true;
            self.panel.innerHTML = html;
        });
    };

    // Editorul atributelor unei axe (ridicabila, portanta, suspensie, frane), sub bara de context.
    Explorer.prototype.editAxle = function (memberId, axleNo) {
        var self = this;
        var member = this.unit.members.filter(function (item) { return item.vehicle_id === memberId; })[0];
        if (!member) { return; }
        var attrs = (member.axle_attrs || {})[axleNo] || {};
        var flag = function (name, value, label) {
            var current = value === null || value === undefined ? '' : (value ? '1' : '0');
            return '<div class="col-sm-6 col-lg-2"><label class="form-label">' + label + '</label><select class="form-select form-select-sm" name="' + name + '">'
                + [['', 'Nedefinit'], ['1', 'Da'], ['0', 'Nu']].map(function (option) { return '<option value="' + option[0] + '"' + (current === option[0] ? ' selected' : '') + '>' + option[1] + '</option>'; }).join('') + '</select></div>';
        };
        var select = function (name, map, value, label) {
            return '<div class="col-sm-6 col-lg-2"><label class="form-label">' + label + '</label><select class="form-select form-select-sm" name="' + name + '"><option value="">Nedefinit</option>'
                + Object.keys(map).map(function (key) { return '<option value="' + key + '"' + (value === key ? ' selected' : '') + '>' + map[key] + '</option>'; }).join('') + '</select></div>';
        };
        var holder = this.root.querySelector('[data-fx-axle-form]');
        if (!holder) {
            holder = document.createElement('form');
            holder.setAttribute('data-fx-axle-form', '');
            holder.className = 'fx-form fx-axle-form';
            holder.noValidate = true;
            this.footer.insertAdjacentElement('afterend', holder);
        }
        holder.hidden = false;
        holder.innerHTML = '<div class="fx-form-title">Atributele axei ' + axleNo + ' · ' + escapeHtml(member.plate) + '</div>'
            + '<input type="hidden" name="vehicle_id" value="' + memberId + '"><input type="hidden" name="axle_no" value="' + axleNo + '">'
            + '<div class="row g-2">' + flag('ridicabila', attrs.liftable, 'Ridicabilă') + flag('portanta', attrs.load_bearing, 'Portantă')
            + select('tip_suspensie', SUSPENSION_TEXT, attrs.suspension, 'Suspensie') + select('tip_franare', BRAKE_TEXT, attrs.brakes, 'Frâne')
            + '<div class="col-lg-4"><label class="form-label">Observații</label><input type="text" class="form-control form-control-sm" name="observatii" maxlength="255" value="' + escapeHtml(attrs.notes || '') + '"></div></div>'
            + '<div class="small text-muted mt-1">Direcția, tracțiunea și roțile duble vin din configurația axelor (Configurație Axe).</div>'
            + '<div class="fx-form-actions"><button type="button" class="btn btn-sm btn-light border" data-fx-close-form>Anulează</button><button type="submit" class="btn btn-sm btn-primary" data-fx-submit>Salvează atributele</button></div>';
        holder.onsubmit = function (event) {
            event.preventDefault();
            event.stopPropagation();
            var button = holder.querySelector('[data-fx-submit]');
            button.disabled = true;
            postWrite('axle', new FormData(holder)).then(function (data) {
                member.axle_attrs = member.axle_attrs || {};
                member.axle_attrs[axleNo] = (data.axle_attrs || {})[axleNo] || null;
                holder.hidden = true;
                self.drawStage();
                self.applyFilter();
                self.selectAxle(memberId, axleNo);
            }).catch(function (error) {
                showMessage(holder, error.message, true);
                button.disabled = false;
            });
        };
        var first = holder.querySelector('select');
        if (first) { first.focus(); }
    };

    // Componenta aleasa: punctul ei iese in fata, ansamblul se mareste cu subcomponentele lui,
    // panoul categoriei se incarca in rand.
    Explorer.prototype.selectSlot = function (slotId, memberId, focusKey) {
        // Componenta vehiculului ascuns (ex. din schema randului): se afiseaza vehiculul ei.
        if (memberId && this.canIsolate && !this.isVisibleMember(memberId)) { this.setViewMode(this.modeForMember(memberId)); }
        var hot = this.findHot(slotId, memberId);
        if (!hot) { return; }
        if (this.slotId !== slotId || !this.selection || this.selection.memberId !== Number(hot.dataset.fxMember)) { this.closeInspector(); }
        var axleForm = this.root.querySelector('[data-fx-axle-form]');
        if (axleForm) { axleForm.hidden = true; }
        var slot = hot._slot;
        var member = hot._member;
        this.category = slot.category;
        this.slotId = slotId;
        this.selection = { type: 'slot', memberId: member.vehicle_id, axleNo: slot.axle || null, slot: slot };
        this.applyFilter();
        this.root.querySelectorAll('.fx-hot').forEach(function (node) { node.classList.toggle('is-selected', node === hot); });
        this.root.classList.add('has-selection');
        this.root.querySelectorAll('.fx-axle.is-selected').forEach(function (node) { node.classList.remove('is-selected'); });
        if (this.hooks.onLeaveTires) { this.hooks.onLeaveTires(); }
        this.renderFooter();
        this.drillDown(hot);
        this.loadPanel(slot, member, focusKey || '');
    };

    // Subcomponenta aleasa in detalierea ansamblului: panoul se reincarca, cu randul ei evidentiat.
    Explorer.prototype.selectSubcomponent = function (componentKey) {
        if (!this.selection || this.selection.type !== 'slot') { return; }
        this.openInspector(componentKey);
    };

    Explorer.prototype.loadPanel = function (slot, member, focusKey) {
        var self = this;
        var category = categoryByKey[slot.category];
        var url = componentUrl + (componentUrl.indexOf('?') === -1 ? '?' : '&') + 'vehicle_id=' + member.vehicle_id + '&category=' + encodeURIComponent(slot.category)
            + '&location=' + encodeURIComponent(slot.location) + '&label=' + encodeURIComponent(slot.label) + (focusKey ? '&component_key=' + encodeURIComponent(focusKey) : '');
        var request = (this.request = window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json(); }));
        if (this.panel.hidden || !this.panel.querySelector('.fx-panel-head')) {
            morph(this.detail, function () {
                self.panel.hidden = false;
                self.tireArea.hidden = true;
                self.panel.innerHTML = '<div class="fx-panel-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Se încarcă ' + escapeHtml(category.label.toLowerCase()) + '...</div>';
            });
        }
        request.then(function (data) {
            if (self.request !== request) { return; }
            if (!data.ok) { throw new Error(data.error || 'Eroare la încărcare.'); }
            morph(self.detail, function () {
                self.panel.hidden = false;
                self.tireArea.hidden = true;
                self.panel.innerHTML = data.html;
                if (self.flash) {
                    var note = document.createElement('div');
                    note.className = 'fx-flash';
                    note.setAttribute('role', 'status');
                    note.innerHTML = '<i class="bi bi-check-circle-fill" aria-hidden="true"></i> ' + escapeHtml(self.flash);
                    self.panel.insertBefore(note, self.panel.firstChild);
                    self.flash = null;
                }
            });
            var focused = self.panel.querySelector('[data-fx-focus]');
            if (focused) { focused.scrollIntoView({ block: 'nearest', behavior: reducedMotion ? 'auto' : 'smooth' }); }
        }).catch(function (error) {
            if (self.request !== request) { return; }
            self.panel.innerHTML = '<div class="fx-panel-loading text-danger"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' + escapeHtml(error.message) + '</div>';
        });
        if (window.history && /^#anvelopa-/.test(window.location.hash)) { window.history.replaceState(null, '', window.location.pathname + window.location.search); }
    };

    Explorer.prototype.pickTire = function (positionId) {
        if (this.hooks.onTire) { this.hooks.onTire(positionId); }
    };

    // Din exterior (selectia din tabel / schema randului): anvelopa marcata si in explorator.
    Explorer.prototype.markTire = function (positionId) {
        if (positionId !== null && this.canIsolate) {
            // Anvelopa vehiculului ascuns (aleasa din tabel / schema randului): se afiseaza vehiculul ei.
            var ownerId = null;
            this.unit.members.forEach(function (member) {
                member.positions.forEach(function (item) { if (String(item.id) === String(positionId)) { ownerId = member.vehicle_id; } });
            });
            if (ownerId !== null && !this.isVisibleMember(ownerId)) { this.setViewMode(this.modeForMember(ownerId), { external: true }); }
        }
        if (positionId !== null) {
            this.closeInspector();
            if (this.root.classList.contains('is-zoomed')) { this.clearCallouts(); this.setView(this.fullView); }
            this.showTires();
        }
        this.root.querySelectorAll('.fx-tire, .fx-tire-label').forEach(function (node) {
            node.classList.toggle('is-selected', positionId !== null && node.dataset.fxPos === String(positionId));
        });
        this.root.classList.toggle('has-tire-selection', positionId !== null);
        if (positionId === null) {
            if (this.selection && this.selection.type === 'tire') { this.selection = null; this.renderFooter(); }
            return;
        }
        var position = null;
        var owner = null;
        this.unit.members.forEach(function (member) {
            member.positions.forEach(function (item) { if (String(item.id) === String(positionId)) { position = item; owner = member; } });
        });
        if (!position) { return; }
        this.selection = { type: 'tire', memberId: owner.vehicle_id, axleNo: position.axle, positionId: position.id, code: position.code };
        this.root.querySelectorAll('.fx-axle').forEach(function (node) {
            node.classList.toggle('is-selected', Number(node.dataset.fxMember) === owner.vehicle_id && Number(node.dataset.fxAxle) === position.axle);
        });
        this.renderFooter();
    };

    Explorer.prototype.openForm = function (button) {
        var kind = button.dataset.fxOpenForm;
        this.panel.querySelectorAll('[data-fx-form]').forEach(function (form) { form.hidden = form.dataset.fxForm !== kind; });
        var form = this.panel.querySelector('[data-fx-form="' + kind + '"]');
        if (!form) { return; }
        var message = form.querySelector('[data-fx-form-message]');
        if (message) { message.hidden = true; }
        form.querySelector('[data-fx-submit]').disabled = false;
        var title = form.querySelector('[data-fx-form-title]');
        if (kind === 'mount') {
            var replace = button.dataset.fxReplace || '';
            form.querySelector('input[name="replace_usage_id"]').value = replace;
            if (button.dataset.fxComponent) { form.querySelector('select[name="component_key"]').value = button.dataset.fxComponent; }
            if (title) { title.textContent = replace ? 'Înlocuiește componenta (montarea veche se închide cu motivul „înlocuire”)' : 'Montează componentă'; }
            form.querySelector('[data-fx-submit]').textContent = replace ? 'Salvează înlocuirea' : 'Salvează montarea';
            stockHint(form);
        }
        if (kind === 'unmount') {
            form.querySelector('input[name="usage_id"]').value = button.dataset.fxUsage;
            form.querySelector('input[name="date"]').min = button.dataset.fxUsageDate || '';
            if (title) { title.textContent = 'Demontează: ' + (button.dataset.fxUsageLabel || ''); }
        }
        form.scrollIntoView({ block: 'nearest', behavior: reducedMotion ? 'auto' : 'smooth' });
        var first = form.querySelector('select, input:not([type="hidden"])');
        if (first) { first.focus(); }
    };

    Explorer.prototype.submitForm = function (form) {
        var self = this;
        var kind = form.dataset.fxForm;
        if (kind === 'mount' && !form.querySelector('select[name="component_key"]').value) {
            showMessage(form, 'Alege componenta de montat.', true);
            return;
        }
        if (!form.reportValidity()) { return; }
        var button = form.querySelector('[data-fx-submit]');
        button.disabled = true;
        postWrite(kind, new FormData(form)).then(function (data) {
            // Locul are acum inregistrari; inspectia ii da starea. Panoul se reincarca din server.
            var hot = self.root.querySelector('.fx-hot.is-selected');
            if (hot && hot._slot) {
                hot._slot.records = true;
                hot.classList.add('has-records');
                if (kind === 'inspect') {
                    var result = (form.querySelector('input[name="result"]:checked') || {}).value || null;
                    hot._slot.state = result;
                    hot.classList.remove('fx-state-ok', 'fx-state-warn', 'fx-state-crit');
                    if (result) { hot.classList.add('fx-state-' + STATE_CLASS[result]); }
                }
                self.flash = data.message;
                var focused = self.stage.querySelector('[data-fx-sub].is-selected');
                self.loadPanel(hot._slot, hot._member, focused ? focused.dataset.fxSub : '');
            }
        }).catch(function (error) {
            showMessage(form, error.message, true);
            button.disabled = false;
        });
    };

    /* ---------------------------------------------------------------- inspector + separator */

    var SPLIT_KEY = 'fx.split';
    var DIVIDER_W = 18;
    var readSplit = function () {
        try { var value = Number(window.localStorage.getItem(SPLIT_KEY)); return value > 0.2 && value < 0.85 ? value : 0.55; } catch (error) { return 0.55; }
    };
    var saveSplit = function (value) {
        try { window.localStorage.setItem(SPLIT_KEY, value.toFixed(3)); } catch (error) { /* fara stocare: doar sesiunea curenta */ }
    };

    // Limitele separatorului din latimea reala a spatiului: schema >= 450 px, inspectorul >= 400 px
    // cand incap; altfel proportional (nicio parte nu devine inutilizabila).
    Explorer.prototype.splitBounds = function () {
        var width = Math.max(1, this.body.clientWidth - DIVIDER_W);
        var minLeft = Math.min(450, width * 0.42) / width;
        var minRight = Math.min(400, width * 0.38) / width;
        return { min: minLeft, max: 1 - minRight, width: width };
    };

    Explorer.prototype.setSplit = function (ratio, persist) {
        var bounds = this.splitBounds();
        var value = Math.min(bounds.max, Math.max(bounds.min, ratio));
        this.split = value;
        this.body.style.setProperty('--fx-split', value.toFixed(4));
        this.divider.setAttribute('aria-valuenow', String(Math.round(value * 100)));
        this.divider.setAttribute('aria-valuemin', String(Math.round(bounds.min * 100)));
        this.divider.setAttribute('aria-valuemax', String(Math.round(bounds.max * 100)));
        this.divider.setAttribute('aria-valuetext', 'Schema ' + Math.round(value * 100) + '%, inspector ' + (100 - Math.round(value * 100)) + '%');
        if (persist) { saveSplit(value); }
    };

    Explorer.prototype.initInspector = function () {
        var self = this;
        var divider = this.divider;
        var drag = null;
        var onMove = function (event) {
            if (!drag || event.pointerId !== drag.id) { return; }
            event.preventDefault();
            var rect = self.body.getBoundingClientRect();
            var ratio = (event.clientX - drag.offset - rect.left) / Math.max(1, rect.width - DIVIDER_W);
            self.setSplit(ratio, false);
        };
        var stop = function (event) {
            if (!drag || (event && event.pointerId !== undefined && event.pointerId !== drag.id)) { return; }
            try { divider.releasePointerCapture(drag.id); } catch (error) { /* deja eliberat */ }
            drag = null;
            self.body.classList.remove('is-resizing');
            document.body.classList.remove('fx-resizing');
            divider.removeEventListener('pointermove', onMove);
            divider.removeEventListener('pointerup', stop);
            divider.removeEventListener('pointercancel', stop);
            divider.removeEventListener('lostpointercapture', stop);
            saveSplit(self.split);
        };
        divider.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 && event.pointerType === 'mouse') { return; }
            event.preventDefault();
            // Fara salt la inceput: tinem distanta dintre pointer si marginea stanga a separatorului.
            drag = { id: event.pointerId, offset: event.clientX - divider.getBoundingClientRect().left };
            divider.setPointerCapture(event.pointerId);
            self.body.classList.add('is-resizing');
            document.body.classList.add('fx-resizing');
            divider.addEventListener('pointermove', onMove);
            divider.addEventListener('pointerup', stop);
            divider.addEventListener('pointercancel', stop);
            divider.addEventListener('lostpointercapture', stop);
        });
        divider.addEventListener('keydown', function (event) {
            var step = event.shiftKey ? 0.08 : 0.02;
            var bounds = self.splitBounds();
            var map = { ArrowLeft: self.split - step, ArrowRight: self.split + step, Home: bounds.min, End: bounds.max };
            if (map[event.key] === undefined) { return; }
            event.preventDefault();
            self.setSplit(map[event.key], true);
        });

        // In inspector: tab-uri, inchidere, piese asociate, formulare, raport.
        this.inspector.addEventListener('click', function (event) {
            if (event.target.closest('[data-fi-close]')) { self.closeInspector(); return; }
            var tab = event.target.closest('[data-fi-tab]');
            if (tab) { self.showInspectorTab(tab.dataset.fiTab); return; }
            var sub = event.target.closest('[data-fi-sub]');
            if (sub) { self.openInspector(sub.dataset.fiSub); return; }
            var open = event.target.closest('[data-fi-open-form]');
            if (open) {
                self.showInspectorTab('maintenance');
                self.inspector.querySelectorAll('[data-fi-form]').forEach(function (form) { form.hidden = form.dataset.fiForm !== open.dataset.fiOpenForm; });
                var form = self.inspector.querySelector('[data-fi-form="' + open.dataset.fiOpenForm + '"]');
                if (form) {
                    form.scrollIntoView({ block: 'nearest', behavior: reducedMotion ? 'auto' : 'smooth' });
                    var first = form.querySelector('select, input:not([type="hidden"])');
                    if (first) { first.focus(); }
                }
                return;
            }
            var close = event.target.closest('[data-fi-close-form]');
            if (close) { close.closest('form').hidden = true; return; }
            if (event.target.closest('[data-fi-report]')) { self.printInspector(); return; }
            if (event.target.closest('[data-fi-narrow-diagram]')) { self.body.classList.toggle('show-diagram'); }
        });
        this.inspector.addEventListener('submit', function (event) {
            var form = event.target.closest('[data-fi-form]');
            if (!form) { return; }
            event.preventDefault();
            event.stopPropagation();
            if (!form.reportValidity()) { return; }
            var button = form.querySelector('[data-fi-submit]');
            button.disabled = true;
            postWrite(form.dataset.fiForm, new FormData(form)).then(function (data) {
                var hot = self.root.querySelector('.fx-hot.is-selected');
                if (hot && hot._slot) {
                    hot._slot.records = true;
                    hot.classList.add('has-records');
                    if (form.dataset.fiForm === 'inspect') {
                        var result = (form.querySelector('input[name="result"]:checked') || {}).value || null;
                        hot._slot.state = result;
                        hot.classList.remove('fx-state-ok', 'fx-state-warn', 'fx-state-crit');
                        if (result) { hot.classList.add('fx-state-' + STATE_CLASS[result]); }
                    }
                }
                self.inspectorFlash = data.message;
                self.openInspector(self.subKey, self.inspectorTab);
                // Panoul categoriei de sub rand arata aceleasi date: se reincarca si el.
                if (self.selection && self.selection.type === 'slot' && !self.panel.hidden) {
                    var member = self.unit.members.filter(function (item) { return item.vehicle_id === self.selection.memberId; })[0];
                    self.loadPanel(self.selection.slot, member, '');
                }
            }).catch(function (error) {
                showMessage(form, error.message, true);
                button.disabled = false;
            });
        });
        this.root.querySelector('[data-fx-stage]').addEventListener('click', function (event) {
            if (event.target.closest('[data-fx-narrow-back]')) { self.body.classList.remove('show-diagram'); }
        });
        this.split = readSplit();
    };

    Explorer.prototype.showInspectorTab = function (tab) {
        this.inspectorTab = tab;
        this.inspector.querySelectorAll('[data-fi-tab]').forEach(function (button) {
            var active = button.dataset.fiTab === tab;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        this.inspector.querySelectorAll('[data-fi-panel]').forEach(function (panel) { panel.hidden = panel.dataset.fiPanel !== tab; });
    };

    // Componenta aleasa (eticheta din detalierea ansamblului sau o piesa asociata): inspectorul ia
    // locul legendei, in dreapta aceluiasi spatiu; schema ramane pe loc, doar se ingusteaza.
    Explorer.prototype.openInspector = function (componentKey, tab) {
        var self = this;
        if (!this.selection || this.selection.type !== 'slot' || !componentKey) { return; }
        var slot = this.selection.slot;
        var member = this.unit.members.filter(function (item) { return item.vehicle_id === self.selection.memberId; })[0];
        this.subKey = componentKey;
        this.stage.querySelectorAll('[data-fx-sub]').forEach(function (node) { node.classList.toggle('is-selected', node.dataset.fxSub === componentKey); });
        this.drawCalloutLines();
        var wasOpen = this.body.classList.contains('is-inspecting');
        if (!wasOpen) {
            this.setSplit(readSplit(), false);
            this.divider.hidden = false;
            this.inspector.hidden = false;
            this.body.classList.add('is-inspecting');
            this.inspector.innerHTML = '<div class="fi-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Se încarcă componenta...</div>';
        } else {
            this.inspector.classList.add('is-updating');
        }
        var url = componentUrl.replace('tire_fleet_component', 'tire_fleet_component_inspector') + (componentUrl.indexOf('?') === -1 ? '?' : '&')
            + 'vehicle_id=' + member.vehicle_id + '&category=' + encodeURIComponent(slot.category) + '&location=' + encodeURIComponent(slot.location) + '&component_key=' + encodeURIComponent(componentKey);
        var request = (this.inspectorRequest = window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(function (response) { return response.json(); }));
        request.then(function (data) {
            if (self.inspectorRequest !== request || !self.body.classList.contains('is-inspecting')) { return; }
            if (!data.ok) { throw new Error(data.error || 'Eroare la încărcare.'); }
            self.inspector.innerHTML = data.html;
            self.inspector.classList.remove('is-updating');
            self.inspector.querySelector('.fi').insertAdjacentHTML('afterbegin', '<button type="button" class="fi-narrow-toggle" data-fi-narrow-diagram><i class="bi bi-diagram-3" aria-hidden="true"></i>Vezi schema</button>');
            self.showInspectorTab(tab || 'details');
            if (self.inspectorFlash) {
                var body = self.inspector.querySelector('.fi-body');
                body.insertAdjacentHTML('afterbegin', '<div class="fx-flash" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> ' + escapeHtml(self.inspectorFlash) + '</div>');
                self.inspectorFlash = null;
            }
            var title = self.inspector.querySelector('.fi-name');
            if (title && !wasOpen) { title.setAttribute('tabindex', '-1'); title.focus({ preventScroll: true }); }
        }).catch(function (error) {
            if (self.inspectorRequest !== request) { return; }
            self.inspector.classList.remove('is-updating');
            self.inspector.innerHTML = '<div class="fi-loading text-danger"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ' + escapeHtml(error.message)
                + ' <button type="button" class="btn btn-sm btn-light border ms-2" data-fi-close>Închide</button></div>';
        });
    };

    // Inchiderea: legenda revine, selectia componentei se sterge; categoria, ansamblul marit si
    // randul extins raman cum erau.
    Explorer.prototype.closeInspector = function () {
        if (!this.body || !this.body.classList.contains('is-inspecting')) { return; }
        this.inspectorRequest = null;
        this.subKey = null;
        this.body.classList.remove('is-inspecting', 'show-diagram');
        this.divider.hidden = true;
        this.inspector.hidden = true;
        this.inspector.innerHTML = '';
        this.stage.querySelectorAll('[data-fx-sub].is-selected').forEach(function (node) { node.classList.remove('is-selected'); });
        this.drawCalloutLines();
    };

    // Raportul componentei: inspectorul tiparit (toate tab-urile), prin dialogul de tiparire / PDF al browserului.
    Explorer.prototype.printInspector = function () {
        var fi = this.inspector.querySelector('.fi');
        if (!fi) { return; }
        // O copie direct sub <body>: restul paginii se ascunde complet (fara pagini goale la final).
        var holder = document.createElement('div');
        holder.className = 'fx-print-root';
        var copy = fi.cloneNode(true);
        copy.classList.add('is-printing');
        holder.appendChild(copy);
        document.body.appendChild(holder);
        document.body.classList.add('fx-printing');
        var cleanup = function () {
            if (holder.parentNode) { holder.parentNode.removeChild(holder); }
            document.body.classList.remove('fx-printing');
            window.removeEventListener('afterprint', cleanup);
        };
        window.addEventListener('afterprint', cleanup);
        window.print();
        window.setTimeout(cleanup, 1500);
    };

    Explorer.prototype.showTires = function () {
        var self = this;
        if (!this.tireArea.hidden) { return; }
        this.request = null;
        morph(this.detail, function () {
            self.panel.hidden = true;
            self.panel.innerHTML = '';
            self.tireArea.hidden = false;
        });
    };

    Explorer.prototype.overview = function () {
        this.slotId = null;
        this.selection = null;
        this.root.classList.remove('has-selection');
        this.root.querySelectorAll('.fx-axle.is-selected').forEach(function (node) { node.classList.remove('is-selected'); });
        this.setCategory('');
        if (this.hooks.onOverview) { this.hooks.onOverview(); }
    };

    /* ---------------------------------------------------------------- utilitare formulare */

    var axleFlagsText = function (attrs) {
        var flags = [];
        if (attrs) {
            if (attrs.liftable !== null && attrs.liftable !== undefined) { flags.push('Ridicabilă: ' + (attrs.liftable ? 'da' : 'nu')); }
            if (attrs.load_bearing !== null && attrs.load_bearing !== undefined) { flags.push('Portantă: ' + (attrs.load_bearing ? 'da' : 'nu')); }
            if (attrs.suspension) { flags.push('Suspensie: ' + (SUSPENSION_TEXT[attrs.suspension] || attrs.suspension)); }
            if (attrs.brakes) { flags.push('Frâne: ' + (BRAKE_TEXT[attrs.brakes] || attrs.brakes)); }
        }
        return flags.join(' · ');
    };
    var showMessage = function (form, text, isError) {
        var box = form.querySelector('[data-fx-form-message]');
        if (!box) {
            box = document.createElement('div');
            box.setAttribute('data-fx-form-message', '');
            box.setAttribute('role', 'alert');
            form.insertBefore(box, form.querySelector('.fx-form-actions'));
        }
        box.className = 'fx-form-message ' + (isError ? 'is-error' : 'is-ok');
        box.textContent = text;
        box.hidden = false;
    };
    // Cat e in stoc din componenta aleasa: avertizare inainte de trimitere (serverul verifica oricum).
    var stockHint = function (form) {
        var option = form.querySelector('select[name="component_key"]').selectedOptions[0];
        var hint = form.querySelector('[data-fx-stock-hint]');
        var source = form.querySelector('select[name="source"]').value;
        var quantity = Number(form.querySelector('input[name="quantity"]').value || 0);
        if (!option || !option.value) { hint.textContent = ''; return; }
        var stock = option.dataset.stock === '' ? null : Number(option.dataset.stock);
        if (stock === null) {
            hint.textContent = 'Componenta nu are piesă în stoc: deschide Reparații Auto pentru sincronizarea catalogului.';
        } else if (source === 'stoc') {
            hint.textContent = 'În stoc: ' + stock.toLocaleString('ro-RO') + ' ' + option.dataset.unit + (quantity > stock ? ' — insuficient; alege montaj direct sau adaugă în stoc.' : ' · după montare: ' + (stock - quantity).toLocaleString('ro-RO') + '.');
        } else {
            hint.textContent = 'Montaj direct: stocul nu se modifică (piesa vine de la service / furnizor).';
        }
    };

    window.FleetXray = {
        mount: function (detail, unit, hooks) {
            if (!detail.querySelector('[data-fx-explorer]')) { return null; }
            detail._fxExplorer = new Explorer(detail, unit, hooks);
            return detail._fxExplorer;
        },
        get: function (detail) { return detail ? detail._fxExplorer || null : null; }
    };
})();
