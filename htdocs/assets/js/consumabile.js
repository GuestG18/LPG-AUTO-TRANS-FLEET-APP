/*
 * Stoc Piese → tab Consumabile: panourile laterale (operatiuni, recipient, produs) si filtrele.
 * Calculele din previzualizare sunt doar informative; serverul revalideaza totul tranzactional.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-cons-module]');
    var bootNode = document.getElementById('consBoot');
    if (!root || !bootNode) {
        return;
    }
    var boot = {};
    try {
        boot = JSON.parse(bootNode.textContent || '{}');
    } catch (error) {
        return;
    }
    var containers = boot.containers || [];
    var products = boot.products || [];
    var vehicles = boot.vehicles || [];
    var dispenses = boot.dispenses || [];
    var systems = boot.systems || [];
    var presets = boot.presets || {};
    var byId = function (list, id) {
        id = Number(id);
        for (var i = 0; i < list.length; i++) {
            if (Number(list[i].id) === id) { return list[i]; }
        }
        return null;
    };

    function newKey() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID().replace(/-/g, '');
        }
        return Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
    }

    function num(value) {
        var text = String(value == null ? '' : value).replace(/\s/g, '').replace(',', '.');
        if (text === '' || !/^\d+(\.\d+)?$/.test(text)) { return null; }
        return Math.round(parseFloat(text) * 1000) / 1000;
    }

    function fmt(value, unit) {
        var rounded = Math.round(Number(value || 0) * 1000) / 1000;
        var text = rounded.toLocaleString('ro-RO', { maximumFractionDigits: 3 });
        return unit ? text + ' ' + unit : text;
    }

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function containerLabel(c) {
        return c.cod + ' · ' + c.tip_label + (c.produs ? ' · ' + c.produs : ' · gol') + ' · ' + fmt(c.cantitate) + '/' + fmt(c.capacitate, c.um || '');
    }

    function fill(select, items, labelFn, placeholder, selected) {
        select.innerHTML = '';
        if (placeholder !== null) {
            select.appendChild(new Option(placeholder, ''));
        }
        items.forEach(function (item) {
            select.appendChild(new Option(labelFn(item), String(item.id)));
        });
        if (selected !== undefined && selected !== null && selected !== '') {
            select.value = String(selected);
        }
    }

    function post(url, form, extra) {
        var data = new FormData(form);
        Object.keys(extra || {}).forEach(function (key) { data.set(key, extra[key]); });
        return fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false, message: 'Răspuns neașteptat de la server (' + response.status + ').' }; });
            });
    }

    function showError(form, message) {
        var box = form.querySelector('[data-cons-error]');
        if (!box) { return; }
        box.textContent = message || '';
        box.classList.toggle('d-none', !message);
    }

    function toastAndReload(message) {
        var toast = document.createElement('div');
        toast.className = 'cons-toast';
        toast.setAttribute('role', 'status');
        toast.textContent = message;
        document.body.appendChild(toast);
        window.setTimeout(function () { window.location.reload(); }, 700);
    }

    function offcanvas(id) {
        var node = document.getElementById(id);
        if (!node || !window.bootstrap) { return null; }
        return { node: node, api: window.bootstrap.Offcanvas.getOrCreateInstance(node) };
    }

    // ------------------------------------------------------------------ operatiuni

    var opForm = document.querySelector('[data-cons-op-form]');
    var FIELDS = {
        intrare: ['dest', 'product', 'quantity', 'receipt', 'lot', 'when', 'reason'],
        transfer: ['source', 'dest', 'quantity', 'when', 'reason'],
        completare: ['source', 'vehicle', 'vsystem', 'toref', 'quantity', 'km', 'system', 'when', 'reason'],
        schimb_complet: ['source', 'vehicle', 'vsystem', 'quantity', 'drained', 'km', 'system', 'when', 'reason'],
        retur: ['dispense', 'dest', 'quantity', 'when', 'reason'],
        pierdere: ['source', 'quantity', 'when', 'reason'],
        ajustare: ['container', 'counted', 'when', 'reason'],
        stornare: ['reference', 'reason']
    };
    var REASON_REQUIRED = ['retur', 'pierdere', 'ajustare', 'stornare'];

    function opType() {
        var checked = opForm.querySelector('input[name="op"]:checked');
        return checked ? checked.value : 'transfer';
    }

    function field(name) {
        return opForm.querySelector('[name="' + name + '"]');
    }

    function refreshOpSelects() {
        var type = opType();
        var source = field('recipient_sursa_id');
        var dest = field('recipient_dest_id');
        var sourceValue = source.value;
        var destValue = dest.value;
        var usable = containers.filter(function (c) { return c.status !== 'inactiv'; });
        fill(source, usable.filter(function (c) { return c.produs_id > 0 && c.cantitate > 0; }), containerLabel, 'Alege recipientul sursă', sourceValue);
        var sourceContainer = byId(containers, source.value);
        var destList = usable.filter(function (c) { return type !== 'transfer' || !sourceContainer || c.id !== sourceContainer.id; });
        fill(dest, destList, containerLabel, 'Alege recipientul destinație', destValue);
        var container = field('recipient_id');
        fill(container, usable.filter(function (c) { return c.produs_id > 0; }), containerLabel, 'Alege recipientul', container.value);
    }

    function refreshVehicleSystems(selected) {
        var select = opForm.querySelector('[data-cons-vsystem]');
        if (!select) { return; }
        var vehicleId = Number(field('vehicul_id').value || 0);
        var list = systems.filter(function (sys) { return sys.vehicle_id === vehicleId; });
        fill(select, list, function (sys) { return sys.denumire; }, list.length ? '— fără legătură cu un sistem —' : 'Vehiculul nu are sisteme configurate', selected || select.value);
        var system = byId(systems, select.value);
        var free = opForm.querySelector('[data-cons-field="system"]');
        if (free && (opType() === 'completare' || opType() === 'schimb_complet')) { free.classList.toggle('d-none', !!system); }
    }

    function setVisible() {
        var type = opType();
        var visible = FIELDS[type] || [];
        opForm.querySelectorAll('[data-cons-field]').forEach(function (node) {
            node.classList.toggle('d-none', visible.indexOf(node.dataset.consField) === -1);
        });
        opForm.querySelectorAll('[data-cons-field="reference"]').forEach(function (node) {
            node.classList.toggle('d-none', type !== 'stornare');
        });
        var required = opForm.querySelector('[data-cons-reason-required]');
        if (required) { required.classList.toggle('d-none', REASON_REQUIRED.indexOf(type) === -1); }
        document.getElementById('consOpTitle').textContent = (boot.movement_types || {})[type] || 'Operațiune';
        refreshOpSelects();
        refreshVehicleSystems();
        updatePreview();
    }

    function currentUnit() {
        var type = opType();
        var c = null;
        if (type === 'intrare') {
            var p = byId(products, field('produs_id').value);
            if (p) { return p.um; }
            c = byId(containers, field('recipient_dest_id').value);
        } else if (type === 'ajustare') {
            c = byId(containers, field('recipient_id').value);
        } else if (type === 'retur') {
            c = byId(containers, field('recipient_dest_id').value);
        } else {
            c = byId(containers, field('recipient_sursa_id').value);
        }
        return c ? (c.um || '') : '';
    }

    function line(label, container, before, after, warn) {
        return '<div class="cons-preview-line' + (warn ? ' is-warn' : '') + '"><span>' + esc(label) + '</span><strong>' + esc(container) + '</strong>'
            + '<em>' + esc(fmt(before)) + ' → ' + esc(fmt(after, currentUnit())) + '</em></div>';
    }

    function updatePreview() {
        var preview = opForm.querySelector('[data-cons-preview]');
        var type = opType();
        var q = num(field('cantitate').value);
        var html = '';
        var unit = currentUnit();
        opForm.querySelectorAll('[data-cons-unit]').forEach(function (node) { node.textContent = unit ? '(' + unit + ')' : ''; });
        var src = byId(containers, field('recipient_sursa_id').value);
        var dst = byId(containers, field('recipient_dest_id').value);
        if (type === 'transfer' && src && dst && q) {
            var mixed = dst.cantitate > 0 && dst.produs_id !== src.produs_id;
            html += line('Sursă', src.cod, src.cantitate, src.cantitate - q, q > src.cantitate);
            html += line('Destinație', dst.cod, dst.cantitate, dst.cantitate + q, dst.cantitate + q > dst.capacitate || mixed);
            html += '<div class="cons-preview-note">Transfer: stocul total rămâne același, nu se înregistrează consum.</div>';
            if (q > src.cantitate) { html += '<div class="cons-preview-warn">Cantitatea depășește stocul sursei.</div>'; }
            if (dst.cantitate + q > dst.capacitate) { html += '<div class="cons-preview-warn">Depășește capacitatea destinației (loc liber ' + esc(fmt(dst.capacitate - dst.cantitate, unit)) + ').</div>'; }
            if (mixed) { html += '<div class="cons-preview-warn">Destinația conține alt produs („' + esc(dst.produs) + '”). Amestecul e permis doar dacă produsele sunt declarate compatibile.</div>'; }
        } else if ((type === 'completare' || type === 'schimb_complet') && src && q) {
            var vehicle = byId(vehicles, field('vehicul_id').value);
            html += line('Sursă', src.cod, src.cantitate, src.cantitate - q, q > src.cantitate);
            html += '<div class="cons-preview-line is-vehicle"><span>Vehicul</span><strong>' + esc(vehicle ? vehicle.label : '—') + '</strong><em>+' + esc(fmt(q, unit)) + '</em></div>';
            html += '<div class="cons-preview-note">Consum efectiv înregistrat: ' + esc(fmt(q, unit)) + (type === 'schimb_complet' ? ' (schimb complet, separat de completări)' : '') + '.</div>';
            if (q > src.cantitate) { html += '<div class="cons-preview-warn">Cantitatea depășește stocul sursei.</div>'; }
        } else if (type === 'intrare' && dst && q) {
            html += line('Destinație', dst.cod, dst.cantitate, dst.cantitate + q, dst.cantitate + q > dst.capacitate);
            if (dst.cantitate + q > dst.capacitate) { html += '<div class="cons-preview-warn">Depășește capacitatea recipientului.</div>'; }
        } else if (type === 'pierdere' && src && q) {
            html += line('Sursă', src.cod, src.cantitate, src.cantitate - q, q > src.cantitate);
            html += '<div class="cons-preview-note">Pierderea scade stocul, dar nu e consum pe vehicul.</div>';
        } else if (type === 'ajustare') {
            var c = byId(containers, field('recipient_id').value);
            var counted = num(field('cantitate_numarata').value);
            if (c && counted !== null) {
                html += line('Recipient', c.cod, c.cantitate, counted, counted > c.capacitate);
                html += '<div class="cons-preview-note">Diferență de inventar: ' + esc((counted - c.cantitate >= 0 ? '+' : '') + fmt(counted - c.cantitate, unit)) + '.</div>';
            }
        } else if (type === 'retur' && dst && q) {
            var ref = byId(dispenses, field('dispense_ref').value);
            html += line('Destinație', dst.cod, dst.cantitate, dst.cantitate + q, dst.cantitate + q > dst.capacitate);
            if (ref && q > ref.returnabil) { html += '<div class="cons-preview-warn">Se pot returna cel mult ' + esc(fmt(ref.returnabil, unit)) + '.</div>'; }
        } else if (type === 'stornare') {
            html += '<div class="cons-preview-note">Cantitățile revin la starea dinaintea operațiunii, prin mișcarea inversă.</div>';
        }
        preview.innerHTML = html;
        preview.classList.toggle('d-none', html === '');
    }

    function openOp(type, preset) {
        if (!opForm) { return; }
        var panel = offcanvas('consOpPanel');
        if (!panel) { return; }
        opForm.reset();
        showError(opForm, '');
        opForm.querySelector('input[name="idempotency_key"]').value = newKey();
        field('referinta_id').value = '';
        var reverseLabel = opForm.querySelector('[data-cons-reverse-type]');
        reverseLabel.classList.toggle('d-none', type !== 'stornare');
        opForm.querySelectorAll('.cons-op-types label').forEach(function (label) {
            if (!label.hasAttribute('data-cons-reverse-type')) { label.classList.toggle('d-none', type === 'stornare'); }
        });
        var radio = opForm.querySelector('input[name="op"][value="' + type + '"]');
        if (radio) { radio.checked = true; }
        fill(field('produs_id'), products.filter(function (p) { return p.activ; }), function (p) { return p.denumire + ' (' + p.um + ')'; }, 'Produsul recipientului', '');
        fill(field('vehicul_id'), vehicles, function (v) { return v.label; }, 'Alege vehiculul', '');
        fill(field('dispense_ref'), dispenses, function (d) { return d.label + ' · returnabil ' + fmt(d.returnabil); }, 'Alege ieșirea spre vehicul', '');
        refreshOpSelects();
        preset = preset || {};
        if (preset.source) { field('recipient_sursa_id').value = preset.source; }
        refreshOpSelects();
        if (preset.dest) { field('recipient_dest_id').value = preset.dest; }
        if (preset.container) { field('recipient_id').value = preset.container; }
        if (preset.vehicle) { field('vehicul_id').value = preset.vehicle; }
        refreshVehicleSystems(preset.system || '');
        if (!preset.product && preset.system) {
            var presetSystem = byId(systems, preset.system);
            if (presetSystem && presetSystem.produs_id) { preset.product = presetSystem.produs_id; }
        }
        if (preset.product && !preset.source) {
            var match = containers.filter(function (c) { return c.produs_id === Number(preset.product) && c.cantitate > 0 && c.status !== 'inactiv'; })
                .sort(function (a, b) { return a.cantitate - b.cantitate; })[0];
            if (match) { field('recipient_sursa_id').value = match.id; }
        }
        if (type === 'stornare') {
            field('referinta_id').value = preset.ref || '';
            opForm.querySelector('[data-cons-ref-label]').textContent = preset.refLabel || '';
        }
        setVisible();
        panel.api.show();
    }

    if (opForm) {
        opForm.addEventListener('change', function (event) {
            if (event.target.name === 'op') { setVisible(); return; }
            if (event.target.name === 'recipient_sursa_id') { refreshOpSelects(); }
            if (event.target.name === 'vehicul_id' || event.target.name === 'sistem_id') { refreshVehicleSystems(); }
            if (event.target.name === 'dispense_ref') {
                var ref = byId(dispenses, event.target.value);
                field('referinta_id').value = ref ? ref.id : '';
            }
            updatePreview();
        });
        opForm.addEventListener('input', updatePreview);
        opForm.addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation(); // garda globala anti-dublu-submit din app.js
            var submit = opForm.querySelector('[data-cons-submit]');
            var type = opType();
            if (REASON_REQUIRED.indexOf(type) !== -1 && field('motiv').value.trim() === '') {
                showError(opForm, 'Scrie motivul operațiunii.');
                return;
            }
            submit.disabled = true;
            showError(opForm, '');
            post(boot.endpoints.op, opForm, { op: type }).then(function (result) {
                if (result.ok) {
                    toastAndReload(result.message || 'Operațiunea a fost înregistrată.');
                    return;
                }
                showError(opForm, result.message || 'Operațiunea nu a putut fi salvată.');
                submit.disabled = false;
            }).catch(function () {
                // Aceeasi cheie la retrimitere: daca prima cerere a ajuns totusi, nu se dubleaza.
                showError(opForm, 'Conexiune întreruptă. Apasă din nou „Confirmă” (operațiunea nu se va dubla).');
                submit.disabled = false;
            });
        });
    }

    root.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-cons-open-op]');
        if (!trigger) { return; }
        event.preventDefault();
        openOp(trigger.dataset.consOpenOp, {
            source: trigger.dataset.source, dest: trigger.dataset.dest, container: trigger.dataset.container,
            vehicle: trigger.dataset.vehicle, product: trigger.dataset.product, ref: trigger.dataset.ref, refLabel: trigger.dataset.refLabel,
            system: trigger.dataset.system
        });
    });

    // ------------------------------------------------------------------ recipient

    var containerForm = document.querySelector('[data-cons-container-form]');
    function syncContainerUnit() {
        var product = byId(products, containerForm.querySelector('[name="produs_id"]').value);
        containerForm.querySelectorAll('[data-cons-unit]').forEach(function (node) { node.textContent = product ? '(' + product.um + ')' : ''; });
        containerForm.querySelector('[data-cons-custom-type]').classList.toggle('d-none', containerForm.querySelector('[name="tip"]').value !== 'altul');
    }
    function openContainer(id) {
        var panel = offcanvas('consContainerPanel');
        if (!panel || !containerForm) { return; }
        var c = id ? byId(containers, id) : null;
        containerForm.reset();
        showError(containerForm, '');
        containerForm.querySelector('[name="id"]').value = c ? c.id : '';
        containerForm.querySelector('[name="idempotency_key"]').value = newKey();
        fill(containerForm.querySelector('[name="produs_id"]'), products.filter(function (p) { return p.activ || (c && p.id === c.produs_id); }),
            function (p) { return p.denumire + ' (' + p.um + ')'; }, '— fără produs —', c ? c.produs_id || '' : '');
        if (c) {
            ['cod', 'tip', 'tip_personalizat', 'capacitate', 'prag_minim', 'locatie', 'status', 'lot', 'data_expirare', 'observatii'].forEach(function (name) {
                var input = containerForm.querySelector('[name="' + name + '"]');
                if (input) { input.value = c[name] == null ? '' : c[name]; }
            });
        }
        var productSelect = containerForm.querySelector('[name="produs_id"]');
        productSelect.disabled = !!(c && c.cantitate > 0);
        containerForm.querySelector('[data-cons-product-lock]').classList.toggle('d-none', !(c && c.cantitate > 0));
        containerForm.querySelector('[data-cons-initial]').classList.toggle('d-none', !!c);
        containerForm.querySelector('[data-cons-container-delete]').classList.toggle('d-none', !c);
        document.getElementById('consContainerTitle').textContent = c ? 'Recipient ' + c.cod : 'Recipient nou';
        syncContainerUnit();
        panel.api.show();
    }
    if (containerForm) {
        containerForm.addEventListener('change', syncContainerUnit);
        containerForm.addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var button = containerForm.querySelector('[type="submit"]');
            button.disabled = true;
            var select = containerForm.querySelector('[name="produs_id"]');
            var extra = select.disabled ? { produs_id: select.value } : {};
            post(boot.endpoints.container, containerForm, extra).then(function (result) {
                if (result.ok) { toastAndReload(result.message); return; }
                showError(containerForm, result.message);
                button.disabled = false;
            }).catch(function () {
                showError(containerForm, 'Conexiune întreruptă. Reîncearcă.');
                button.disabled = false;
            });
        });
    }
    root.addEventListener('click', function (event) {
        var create = event.target.closest('[data-cons-container-new]');
        var edit = event.target.closest('[data-cons-container-edit]');
        if (create) { openContainer(null); }
        if (edit) { openContainer(edit.dataset.consContainerEdit); }
    });

    // ------------------------------------------------------------------ produs

    var productForm = document.querySelector('[data-cons-product-form]');
    var originalCompat = [];
    function openProduct(id) {
        var panel = offcanvas('consProductPanel');
        if (!panel || !productForm) { return; }
        var p = id ? byId(products, id) : null;
        productForm.reset();
        showError(productForm, '');
        productForm.querySelector('[name="id"]').value = p ? p.id : '';
        if (p) {
            ['denumire', 'producator', 'cod_produs', 'tip_articol', 'tip_fluid', 'um', 'vascozitate', 'specificatie', 'aprobari',
                'component_key', 'compatibilitate_note', 'interval_km', 'interval_luni', 'interval_ore', 'prag_alerta'].forEach(function (name) {
                var input = productForm.querySelector('[name="' + name + '"]');
                if (input) { input.value = p[name] == null ? '' : p[name]; }
            });
            productForm.querySelectorAll('[name="tipuri_vehicul[]"]').forEach(function (box) { box.checked = p.tipuri_vehicul.indexOf(box.value) !== -1; });
            productForm.querySelectorAll('[name="sursa_monitorizare[]"]').forEach(function (box) { box.checked = p.sursa_monitorizare.indexOf(box.value) !== -1; });
            ['critic', 'circuit_inchis', 'permite_retur'].forEach(function (name) { productForm.querySelector('input[type="checkbox"][name="' + name + '"]').checked = Number(p[name]) === 1; });
            productForm.querySelector('input[type="checkbox"][name="activ"]').checked = Number(p.activ) === 1;
        }
        originalCompat = p ? p.compatibile.slice() : [];
        var list = productForm.querySelector('[data-cons-compat-list]');
        var compatBox = productForm.querySelector('[data-cons-compat]');
        compatBox.classList.toggle('d-none', !p);
        list.innerHTML = p ? products.filter(function (other) { return other.id !== p.id && other.um === p.um; }).map(function (other) {
            return '<label class="form-check-inline"><input type="checkbox" data-compat-id="' + other.id + '"' + (originalCompat.indexOf(other.id) !== -1 ? ' checked' : '') + '> '
                + esc(other.denumire) + (other.vascozitate ? ' <small class="text-muted">' + esc(other.vascozitate) + '</small>' : '') + '</label>';
        }).join('') || '<small class="text-muted">Niciun alt produs cu aceeași unitate de măsură.</small>' : '';
        productForm.querySelector('[data-cons-product-delete]').classList.toggle('d-none', !p);
        partsSelected = p ? (p.piese || []).slice() : [];
        productForm.querySelector('[data-cons-parts-all]').checked = false;
        productForm.querySelector('[data-cons-parts-search]').value = '';
        renderParts();
        document.getElementById('consProductTitle').textContent = p ? p.denumire : 'Produs nou';
        panel.api.show();
    }
    // Piesele din Catalog componente pe care se foloseste produsul, filtrate dupa tipul fluidului.
    var parts = boot.parts || [];
    var partsSelected = [];
    function plain(text) { return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
    function renderParts() {
        var box = productForm.querySelector('[data-cons-parts]');
        if (!box) { return; }
        var fluid = productForm.querySelector('[name="tip_fluid"]').value;
        var categories = (boot.fluidPartCategories || {})[fluid] || null;
        var showAll = productForm.querySelector('[data-cons-parts-all]').checked || !categories;
        var term = plain(productForm.querySelector('[data-cons-parts-search]').value.trim());
        var list = parts.filter(function (part) {
            var match = showAll || categories.indexOf(plain(part.category)) !== -1;
            return (match || partsSelected.indexOf(part.key) !== -1) && (term === '' || plain(part.name + ' ' + part.category).indexOf(term) !== -1);
        });
        var html = '';
        var group = null;
        list.slice().sort(function (a, b) { return a.category.localeCompare(b.category) || a.name.localeCompare(b.name); }).forEach(function (part) {
            if (part.category !== group) { group = part.category; html += '<div class="cons-parts-group">' + esc(group) + '</div>'; }
            html += '<label><input type="checkbox" name="componente_vehicul[]" value="' + esc(part.key) + '"'
                + (partsSelected.indexOf(part.key) !== -1 ? ' checked' : '') + '> ' + esc(part.name) + '</label>';
        });
        box.innerHTML = html || '<div class="cons-parts-empty">Nicio piesă potrivită. Bifează „Arată toate piesele din catalog”.</div>';
        productForm.querySelector('[data-cons-parts-hint]').textContent = !fluid
            ? 'Alege întâi tipul fluidului; lista arată piesele din Catalog componente potrivite.'
            : (categories ? 'Piese din categoriile potrivite pentru acest fluid (' + categories.join(', ') + ').' : 'Pentru acest tip de fluid se arată toate piesele.');
    }
    if (productForm) {
        productForm.addEventListener('change', function (event) {
            if (event.target.name === 'componente_vehicul[]') {
                var key = event.target.value;
                partsSelected = partsSelected.filter(function (k) { return k !== key; });
                if (event.target.checked) { partsSelected.push(key); }
            }
            if (event.target.name === 'tip_fluid' || event.target.hasAttribute('data-cons-parts-all')) { renderParts(); }
        });
        productForm.querySelector('[data-cons-parts-search]').addEventListener('input', renderParts);
        productForm.addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var button = productForm.querySelector('[type="submit"]');
            button.disabled = true;
            var id = productForm.querySelector('[name="id"]').value;
            post(boot.endpoints.product, productForm).then(function (result) {
                if (!result.ok) { throw new Error(result.message || 'Produsul nu a putut fi salvat.'); }
                var changes = [];
                productForm.querySelectorAll('[data-compat-id]').forEach(function (box) {
                    var other = Number(box.dataset.compatId);
                    var was = originalCompat.indexOf(other) !== -1;
                    if (box.checked !== was) { changes.push({ other: other, on: box.checked }); }
                });
                return changes.reduce(function (chain, change) {
                    return chain.then(function () {
                        var data = new FormData();
                        data.set('_token', productForm.querySelector('[name="_token"]').value);
                        data.set('produs_a', id);
                        data.set('produs_b', String(change.other));
                        data.set('compatibil', change.on ? '1' : '0');
                        return fetch(boot.endpoints.compat, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); })
                            .then(function (r) { if (!r.ok) { throw new Error(r.message); } });
                    });
                }, Promise.resolve()).then(function () { toastAndReload(result.message); });
            }).catch(function (error) {
                showError(productForm, error.message || 'Conexiune întreruptă. Reîncearcă.');
                button.disabled = false;
            });
        });
    }
    // Stergere definitiva: serverul refuza daca exista istoric (atunci se dezactiveaza).
    function wireDelete(form, selector, endpoint, label) {
        var button = form && form.querySelector(selector);
        if (!button) { return; }
        button.addEventListener('click', function () {
            var id = form.querySelector('[name="id"]').value;
            if (!id || !window.confirm('Ștergi definitiv ' + label + '? Operațiunea nu se poate anula.')) { return; }
            var data = new FormData();
            data.set('_token', form.querySelector('[name="_token"]').value);
            data.set('id', id);
            button.disabled = true;
            showError(form, '');
            fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Răspuns neașteptat de la server (' + r.status + ').' }; }); })
                .then(function (result) {
                    if (result.ok) { toastAndReload(result.message); return; }
                    showError(form, result.message);
                    button.disabled = false;
                })
                .catch(function () {
                    showError(form, 'Conexiune întreruptă. Reîncearcă.');
                    button.disabled = false;
                });
        });
    }
    wireDelete(containerForm, '[data-cons-container-delete]', boot.endpoints.containerDelete, 'recipientul');
    wireDelete(productForm, '[data-cons-product-delete]', boot.endpoints.productDelete, 'produsul');
    wireDelete(document.querySelector('[data-cons-system-form]'), '[data-cons-system-remove]', boot.endpoints.systemRemove,
        'sistemul de pe vehicul (dacă are înregistrări, istoricul rămâne și sistemul doar dispare din situație)');

    root.addEventListener('click', function (event) {
        var create = event.target.closest('[data-cons-product-new]');
        var edit = event.target.closest('[data-cons-product-edit]');
        if (create) { openProduct(null); }
        if (edit) { openProduct(edit.dataset.consProductEdit); }
    });


    // ------------------------------------------------------------------ sisteme vehicul (etapa 2)

    var systemForm = document.querySelector('[data-cons-system-form]');
    var copyBox = null;
    function buildCopyList(currentVehicle) {
        var holder = systemForm.querySelector('[data-cons-copy]');
        var select = holder.querySelector('select');
        if (select) { select.remove(); }
        if (copyBox) { copyBox.remove(); }
        copyBox = document.createElement('div');
        copyBox.className = 'cons-check-list';
        var search = document.createElement('input');
        search.type = 'search';
        search.className = 'form-control form-control-sm mb-1';
        search.placeholder = 'Caută vehicul...';
        copyBox.appendChild(search);
        var list = document.createElement('div');
        list.className = 'cons-check-items';
        vehicles.forEach(function (v) {
            if (Number(v.id) === Number(currentVehicle)) { return; }
            var label = document.createElement('label');
            label.innerHTML = '<input type="checkbox" name="copy_vehicle_ids[]" value="' + v.id + '"> ' + esc(v.label);
            list.appendChild(label);
        });
        copyBox.appendChild(list);
        search.addEventListener('input', function () {
            var term = search.value.trim().toLowerCase();
            Array.from(list.children).forEach(function (label) { label.hidden = term !== '' && label.textContent.toLowerCase().indexOf(term) === -1; });
        });
        holder.insertBefore(copyBox, holder.querySelector('small'));
    }
    function applyPreset(key, overwrite) {
        var preset = presets[key];
        var hint = systemForm.querySelector('[data-cons-preset-hint]');
        systemForm.querySelector('[data-cons-adblue-only]').classList.toggle('d-none', key !== 'adblue');
        if (hint) { hint.textContent = preset && preset.hint ? 'Monitorizare recomandată: ' + preset.hint : ''; }
        if (!preset || !overwrite) { return; }
        systemForm.querySelector('[name="tip_fluid"]').value = preset.tip_fluid || '';
        systemForm.querySelector('[name="sursa_km"]').value = preset.sursa_km;
        systemForm.querySelector('[name="sursa_ore"]').value = preset.sursa_ore;
        systemForm.querySelector('[name="critic"]').checked = Number(preset.critic) === 1;
        systemForm.querySelector('[name="circuit_inchis"]').checked = Number(preset.circuit_inchis) === 1;
    }
    // Produsele bifate pentru sistemul ales apar primele (ordinea alfabetica se pastreaza in fiecare grup).
    function fillSystemProducts(systemKey, sys, selected) {
        var select = systemForm.querySelector('[name="produs_id"]');
        var presetFluid = presets[systemKey] ? presets[systemKey].tip_fluid : '';
        var forSystem = function (p) { return (presetFluid && p.tip_fluid === presetFluid) || (p.sisteme || []).indexOf(systemKey) !== -1; };
        var list = products.filter(function (p) { return p.activ || (sys && p.id === sys.produs_id); });
        list = list.filter(forSystem).concat(list.filter(function (p) { return !forSystem(p); }));
        fill(select, list, function (p) { return p.denumire + ' (' + p.um + ')' + (forSystem(p) ? ' ✓ pentru acest sistem' : ''); }, '— fără produs —', selected);
    }
    function openSystem(id, vehicleId) {
        var panel = offcanvas('consSystemPanel');
        if (!panel || !systemForm) { return; }
        var sys = id ? byId(systems, id) : null;
        systemForm.reset();
        showError(systemForm, '');
        systemForm.querySelector('[name="id"]').value = sys ? sys.id : '';
        fill(systemForm.querySelector('[name="vehicle_id"]'), vehicles, function (v) { return v.label; }, 'Alege vehiculul', sys ? sys.vehicle_id : (vehicleId || ''));
        fillSystemProducts(sys ? sys.sistem : systemForm.querySelector('[name="sistem"]').value, sys, sys ? sys.produs_id || '' : '');
        systemForm.querySelector('[name="vehicle_id"]').disabled = !!sys;
        systemForm.querySelector('[name="sistem"]').disabled = !!sys;
        if (sys) {
            ['sistem', 'denumire', 'tip_fluid', 'capacitate_nominala', 'um', 'interval_km', 'interval_luni', 'interval_ore', 'avertizare_procent',
                'sursa_km', 'sursa_ore', 'verificare_zile', 'prag_consum', 'referinta_data', 'referinta_km_can', 'referinta_km_gps'].forEach(function (name) {
                var input = systemForm.querySelector('[name="' + name + '"]');
                if (input) { input.value = sys[name] == null ? '' : sys[name]; }
            });
            systemForm.querySelector('[name="critic"]').checked = sys.critic === 1;
            systemForm.querySelector('[name="circuit_inchis"]').checked = sys.circuit_inchis === 1;
            systemForm.querySelector('[name="alimentare_la_plin"]').checked = sys.alimentare_la_plin === 1;
            applyPreset(sys.sistem, false);
        } else {
            applyPreset(systemForm.querySelector('[name="sistem"]').value, true);
        }
        systemForm.querySelector('[data-cons-copy]').classList.toggle('d-none', !!sys);
        if (!sys) { buildCopyList(vehicleId); }
        systemForm.querySelector('[data-cons-system-remove]').classList.toggle('d-none', !sys);
        document.getElementById('consSystemTitle').textContent = sys ? sys.denumire : 'Sistem fluid nou';
        panel.api.show();
    }
    if (systemForm) {
        systemForm.addEventListener('change', function (event) {
            if (event.target.name === 'sistem') { applyPreset(event.target.value, true); fillSystemProducts(event.target.value, null, systemForm.querySelector('[name="produs_id"]').value); }
            if (event.target.name === 'vehicle_id' && !systemForm.querySelector('[name="id"]').value) { buildCopyList(event.target.value); }
            if (event.target.name === 'produs_id') {
                var product = byId(products, event.target.value);
                if (product) {
                    if (product.tip_fluid) { systemForm.querySelector('[name="tip_fluid"]').value = product.tip_fluid; }
                    systemForm.querySelector('[name="um"]').value = product.um;
                    ['interval_km', 'interval_luni', 'interval_ore'].forEach(function (name) {
                        var input = systemForm.querySelector('[name="' + name + '"]');
                        if (input.value === '' && product[name]) { input.value = product[name]; }
                    });
                }
            }
        });
        systemForm.addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var button = systemForm.querySelector('[type="submit"]');
            button.disabled = true;
            var extra = {};
            ['vehicle_id', 'sistem'].forEach(function (name) {
                var input = systemForm.querySelector('[name="' + name + '"]');
                if (input.disabled) { extra[name] = input.value; }
            });
            post(boot.endpoints.system, systemForm, extra).then(function (result) {
                if (result.ok) { toastAndReload(result.message); return; }
                showError(systemForm, result.message);
                button.disabled = false;
            }).catch(function () {
                showError(systemForm, 'Conexiune întreruptă. Reîncearcă.');
                button.disabled = false;
            });
        });
    }

    var eventForm = document.querySelector('[data-cons-event-form]');
    function eventType() {
        var checked = eventForm.querySelector('input[name="tip"]:checked');
        return checked ? checked.value : 'verificare';
    }
    function syncEventFields() {
        var type = eventType();
        eventForm.querySelector('[data-cons-ev="level"]').classList.toggle('d-none', type !== 'verificare');
        eventForm.querySelector('[data-cons-ev="qty"]').classList.toggle('d-none', type === 'verificare');
        eventForm.querySelector('[name="pana_la_reper"]').closest('label').classList.toggle('d-none', type === 'schimb_complet');
        document.getElementById('consEventTitle').textContent = { verificare: 'Verificare nivel', completare: 'Completare fără stoc', schimb_complet: 'Schimb complet fără stoc' }[type];
    }
    function openEvent(systemId) {
        var panel = offcanvas('consEventPanel');
        var sys = byId(systems, systemId);
        if (!panel || !eventForm || !sys) { return; }
        eventForm.reset();
        showError(eventForm, '');
        eventForm.querySelector('[name="idempotency_key"]').value = newKey();
        eventForm.querySelector('[name="sistem_id"]').value = sys.id;
        var vehicle = byId(vehicles, sys.vehicle_id);
        panel.node.querySelector('[data-cons-event-system]').textContent = (vehicle ? vehicle.label : '') + ' · ' + sys.denumire;
        eventForm.querySelector('[data-cons-event-unit]').textContent = '(' + (sys.um || 'L') + ')';
        syncEventFields();
        panel.api.show();
    }
    if (eventForm) {
        eventForm.addEventListener('change', syncEventFields);
        eventForm.addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var button = eventForm.querySelector('[type="submit"]');
            button.disabled = true;
            post(boot.endpoints.event, eventForm).then(function (result) {
                if (result.ok) { toastAndReload(result.message); return; }
                showError(eventForm, result.message);
                button.disabled = false;
            }).catch(function () {
                showError(eventForm, 'Conexiune întreruptă. Salvează din nou (înregistrarea nu se va dubla).');
                button.disabled = false;
            });
        });
    }

    function openHistory(systemId) {
        var panel = offcanvas('consHistoryPanel');
        if (!panel) { return; }
        var body = panel.node.querySelector('[data-cons-history-body]');
        var sys = byId(systems, systemId);
        var vehicle = sys ? byId(vehicles, sys.vehicle_id) : null;
        panel.node.querySelector('[data-cons-history-sub]').textContent = (vehicle ? vehicle.label + ' · ' : '') + (sys ? sys.denumire : '');
        body.textContent = 'Se încarcă…';
        panel.api.show();
        var url = boot.history_url + (boot.history_url.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(systemId);
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (result) {
            if (!result.ok) { body.textContent = result.message || 'Istoricul nu a putut fi încărcat.'; return; }
            var labels = boot.level_labels || {};
            var typeLabels = { verificare: 'Verificare', completare: 'Completare', schimb_complet: 'Schimb complet' };
            var unit = sys ? sys.um : 'L';
            var rows = (result.events || []).map(function (e) {
                var what = e.tip === 'verificare' ? '<span class="cons-level is-measured is-' + esc(e.nivel_status) + '">' + esc(labels[e.nivel_status] || e.nivel_status) + '</span>'
                    + (e.nivel_valoare ? ' ' + esc(fmt(e.nivel_valoare, e.nivel_unitate || '')) : '')
                    : '+' + esc(fmt(e.cantitate, unit)) + (Number(e.pana_la_reper) === 1 ? ' <small>(la reper)</small>' : '') + (e.miscare_id ? ' <small class="text-muted">din stoc</small>' : ' <small class="text-muted">fără stoc</small>');
                var cancel = !e.anulat_la && !e.miscare_id && boot.can_manage
                    ? '<button type="button" class="btn btn-sm btn-light" data-cons-event-cancel="' + e.id + '" title="Anulează înregistrarea"><i class="bi bi-x-lg"></i></button>' : '';
                return '<tr class="' + (e.anulat_la ? 'is-reversed' : '') + '"><td>' + esc(String(e.efectuat_la).slice(0, 16).replace('T', ' ')) + '</td><td>' + esc(typeLabels[e.tip] || e.tip) + '</td><td>' + what + '</td>'
                    + '<td><small>' + (e.km_can ? 'CAN ' + esc(fmt(e.km_can)) : '') + (e.km_gps ? '<br>GPS ' + esc(fmt(e.km_gps)) : '') + (!e.km_can && !e.km_gps ? '—' : '') + '</small></td>'
                    + '<td><small>' + esc(e.utilizator || '') + (e.observatii ? '<br>' + esc(e.observatii) : '') + (e.anulat_la ? '<br><b>anulată:</b> ' + esc(e.motiv_anulare || '') : '') + '</small></td><td>' + cancel + '</td></tr>';
            }).join('');
            var st = result.system || {};
            var head = st.status_label ? '<p><span class="cons-pill ' + esc(st.status_class) + '">' + esc(st.status_label) + '</span> ' + esc(st.action || '') + '</p>' : '';
            body.innerHTML = head + '<div class="table-responsive"><table class="table cons-table mb-0"><thead><tr><th>Data</th><th>Tip</th><th>Nivel / cantitate</th><th>KM</th><th>Utilizator / observații</th><th></th></tr></thead><tbody>'
                + (rows || '<tr><td colspan="6" class="cons-empty">Nicio înregistrare.</td></tr>') + '</tbody></table></div>';
        }).catch(function () { body.textContent = 'Istoricul nu a putut fi încărcat.'; });
    }
    document.addEventListener('click', function (event) {
        var cancel = event.target.closest('[data-cons-event-cancel]');
        if (!cancel) { return; }
        var reason = cancel.dataset.reason || '';
        var row = cancel.closest('tr');
        if (!row.querySelector('[data-cons-cancel-reason]')) {
            var cell = cancel.parentNode;
            cell.innerHTML = '<input class="form-control form-control-sm mb-1" data-cons-cancel-reason placeholder="Motivul anulării"><button type="button" class="btn btn-sm btn-danger" data-cons-event-cancel="' + cancel.dataset.consEventCancel + '">Anulează</button>';
            cell.querySelector('input').focus();
            return;
        }
        reason = row.querySelector('[data-cons-cancel-reason]').value.trim();
        if (reason === '') { row.querySelector('[data-cons-cancel-reason]').focus(); return; }
        var data = new FormData();
        var token = document.querySelector('[name="_token"]');
        data.set('_token', token ? token.value : '');
        data.set('id', cancel.dataset.consEventCancel);
        data.set('motiv', reason);
        fetch(boot.endpoints.event_cancel, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (result) {
            if (result.ok) { toastAndReload(result.message); return; }
            var note = document.createElement('small');
            note.className = 'd-block text-danger';
            note.textContent = result.message || 'Anularea nu a putut fi salvată.';
            cancel.parentNode.appendChild(note);
        });
    });

    root.addEventListener('click', function (event) {
        var create = event.target.closest('[data-cons-system-new]');
        var edit = event.target.closest('[data-cons-system-edit]');
        var check = event.target.closest('[data-cons-event]');
        var history = event.target.closest('[data-cons-history]');
        if (create) { openSystem(null, create.dataset.vehicle || ''); }
        if (edit) { openSystem(edit.dataset.consSystemEdit); }
        if (check) { openEvent(check.dataset.consEvent); }
        if (history) { openHistory(history.dataset.consHistory); }
    });

    // ------------------------------------------------------------------ AdBlue (etapa 3)

    root.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-cons-toggle]');
        if (toggle) {
            var row = document.getElementById(toggle.dataset.consToggle);
            if (row) { row.hidden = !row.hidden; }
            return;
        }
        var sync = event.target.closest('[data-cons-adblue-sync]');
        if (!sync) { return; }
        var form = sync.closest('form');
        var data = new FormData(form);
        var token = document.querySelector('[name="_token"]');
        data.set('_token', token ? token.value : '');
        sync.disabled = true;
        var label = sync.innerHTML;
        sync.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Se citesc intervalele din SAS…';
        fetch(boot.endpoints.adblue_sync, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.ok && result.stats && (result.stats.stored > 0 || result.stats.calls > 0)) {
                    toastAndReload(result.message);
                    return;
                }
                sync.disabled = false;
                sync.innerHTML = label;
                var note = form.querySelector('.cons-sync-note') || document.createElement('small');
                note.className = 'cons-sync-note d-block w-100 ' + (result.ok ? 'text-muted' : 'text-danger');
                note.textContent = result.message || 'Nu e nimic de actualizat.';
                form.appendChild(note);
            })
            .catch(function () {
                sync.disabled = false;
                sync.innerHTML = label;
            });
    });

    // ------------------------------------------------------------------ alerte (etapa 4)

    root.addEventListener('click', function (event) {
        var close = event.target.closest('[data-cons-alert-close]');
        if (!close) { return; }
        var item = close.closest('li');
        var input = item.querySelector('[data-cons-alert-reason]');
        if (!input) {
            var box = document.createElement('div');
            box.className = 'cons-alert-confirm';
            box.innerHTML = '<input class="form-control form-control-sm" data-cons-alert-reason placeholder="Ce s-a verificat? (ex. inspecție fără scurgeri)">'
                + '<button type="button" class="btn btn-sm btn-primary" data-cons-alert-close="' + close.dataset.consAlertClose + '">Închide alerta</button>';
            item.querySelector('.cons-alert-body').appendChild(box);
            box.querySelector('input').focus();
            return;
        }
        if (input.value.trim() === '') { input.focus(); return; }
        var data = new FormData();
        var token = document.querySelector('[name="_token"]');
        data.set('_token', token ? token.value : '');
        data.set('id', close.dataset.consAlertClose);
        data.set('motiv', input.value.trim());
        fetch(boot.endpoints.alert_close, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.ok) { toastAndReload(result.message); return; }
                var note = document.createElement('small');
                note.className = 'd-block text-danger';
                note.textContent = result.message || 'Alerta nu a putut fi închisă.';
                item.querySelector('.cons-alert-body').appendChild(note);
            });
    });

    // ------------------------------------------------------------------ filtru multi-vehicul

    root.querySelectorAll('select[data-cons-multi]').forEach(function (select) {
        var wrap = document.createElement('div');
        wrap.className = 'cons-multi dropdown';
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'form-select form-select-sm text-start';
        button.setAttribute('data-bs-toggle', 'dropdown');
        button.setAttribute('data-bs-auto-close', 'outside');
        var menu = document.createElement('div');
        menu.className = 'dropdown-menu cons-multi-menu';
        var search = document.createElement('input');
        search.type = 'search';
        search.className = 'form-control form-control-sm mb-1';
        search.placeholder = 'Caută...';
        menu.appendChild(search);
        var listBox = document.createElement('div');
        listBox.className = 'cons-multi-list';
        menu.appendChild(listBox);
        Array.from(select.options).forEach(function (option) {
            var label = document.createElement('label');
            label.innerHTML = '<input type="checkbox"' + (option.selected ? ' checked' : '') + '> ' + esc(option.text);
            label.querySelector('input').addEventListener('change', function (e) { option.selected = e.target.checked; render(); });
            listBox.appendChild(label);
        });
        search.addEventListener('input', function () {
            var term = search.value.trim().toLowerCase();
            Array.from(listBox.children).forEach(function (label) { label.hidden = term !== '' && label.textContent.toLowerCase().indexOf(term) === -1; });
        });
        function render() {
            var chosen = Array.from(select.selectedOptions).map(function (o) { return o.text; });
            button.textContent = chosen.length === 0 ? 'Toate' : (chosen.length <= 2 ? chosen.join(', ') : chosen.length + ' vehicule');
        }
        select.hidden = true;
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(button);
        wrap.appendChild(menu);
        wrap.appendChild(select);
        render();
    });
})();
