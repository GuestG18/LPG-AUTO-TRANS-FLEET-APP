/*
 * Documente obligatorii (?page=documente&action=required_documents).
 * O singura stare (din server) alimenteaza KPI-urile, categoriile, tabelul, sidebar-ul si matricea.
 * Fiecare modificare merge la required_documents_api; interfata se redeseneaza doar din raspunsul
 * serverului (fara stare "salvata" optimista).
 */
(function () {
    'use strict';

    var configEl = document.getElementById('rdc-config');
    var root = document.querySelector('[data-rdc-root]');
    if (!configEl || !root) {
        return;
    }

    var config = JSON.parse(configEl.textContent || '{}');
    var state = config.state;
    if (!state) {
        return;
    }

    var canManage = !!config.canManage;
    var STORAGE_KEY = 'rdc.selectedCategory';
    var CATEGORY_ICONS = {
        cap_tractor: 'bi-truck-front-fill',
        camion: 'bi-truck',
        semiremorca_primar: 'bi-truck-flatbed',
        semiremorca_distributie: 'bi-truck-flatbed',
        autovehicul: 'bi-car-front-fill'
    };
    var GROUP_ICONS = { grele: 'bi-truck-front-fill', usoare: 'bi-car-front-fill' };

    var ui = {
        category: readStoredCategory(),
        filter: 'all',
        search: '',
        typesExpanded: false,
        matrixExpanded: false,
        busy: false
    };

    // ---------- utilitare ----------
    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function readStoredCategory() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) || '';
        } catch (e) {
            return '';
        }
    }

    function storeCategory(key) {
        try {
            window.localStorage.setItem(STORAGE_KEY, key);
        } catch (e) { /* stocarea poate lipsi */ }
    }

    function categoryByKey(key) {
        for (var i = 0; i < state.categories.length; i++) {
            if (state.categories[i].key === key) {
                return state.categories[i];
            }
        }
        return null;
    }

    function currentCategory() {
        var cat = categoryByKey(ui.category);
        if (!cat) {
            cat = categoryByKey('camion') || state.categories[0];
            ui.category = cat ? cat.key : '';
        }
        return cat;
    }

    function cellOf(doc, type) {
        return doc.cells && Object.prototype.hasOwnProperty.call(doc.cells, type) ? doc.cells[type] : null;
    }

    function docByKey(key) {
        for (var i = 0; i < state.documents.length; i++) {
            if (state.documents[i].key === key) {
                return state.documents[i];
            }
        }
        return null;
    }

    function docIcon(label) {
        var k = String(label).toUpperCase();
        if (k.indexOf('ADR') === 0) return { icon: 'bi-diamond', red: true };
        if (k.indexOf('CASCO') !== -1) return { icon: 'bi-shield' };
        if (k === 'RCA') return { icon: 'bi-shield-check' };
        if (k.indexOf('ITP') !== -1) return { icon: 'bi-wrench' };
        if (k.indexOf('IPROCHIM') !== -1) return { icon: 'bi-eyedropper' };
        if (k.indexOf('METROLOG') !== -1) return { icon: 'bi-speedometer2' };
        if (k.indexOf('ORGANISM') !== -1) return { icon: 'bi-bank' };
        if (k.indexOf('ROVINIET') !== -1) return { icon: 'bi-signpost-split' };
        if (k.indexOf('TAHOGRAF') !== -1) return { icon: 'bi-clock' };
        if (k.indexOf('COPIE') !== -1) return { icon: 'bi-files' };
        return { icon: 'bi-file-earmark-text' };
    }

    function docIconHtml(label) {
        var icon = docIcon(label);
        return '<span class="rdc-doc-icon' + (icon.red ? ' is-red' : '') + '" aria-hidden="true"><i class="bi ' + icon.icon + '"></i></span>';
    }

    function formatMoney(value) {
        return Number(value || 0).toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function requiredCountFor(type) {
        var n = 0;
        state.documents.forEach(function (doc) {
            var cell = cellOf(doc, type);
            if (cell && cell.required) n++;
        });
        return n;
    }

    function plural(n, one, many) {
        return n + ' ' + (n === 1 ? one : many);
    }

    // ---------- comunicare ----------
    function api(op, data) {
        var body = new FormData();
        body.append('_token', config.csrf);
        body.append('op', op);
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) { body.append(key + '[]', item); });
            } else {
                body.append(key, value === true ? '1' : value === false ? '0' : value);
            }
        });

        ui.busy = true;
        return fetch(config.urls.api, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                return response.json().catch(function () {
                    return { success: false, message: 'Răspuns invalid de la server (' + response.status + ').' };
                });
            })
            .then(function (payload) {
                ui.busy = false;
                if (!payload || !payload.success) {
                    throw new Error((payload && payload.message) || 'Modificarea nu a putut fi salvată.');
                }
                if (payload.state) {
                    state = payload.state;
                }
                render();
                toast(payload.message || 'Salvat.');
                return payload;
            })
            .catch(function (error) {
                ui.busy = false;
                render(); // revine la starea confirmata de server
                toast(error && error.message ? error.message : 'Eroare de rețea. Încearcă din nou.', true);
                throw error;
            });
    }

    function toast(message, isError) {
        var host = document.querySelector('[data-rdc-toasts]');
        if (!host) return;
        var el = document.createElement('div');
        el.className = 'rdc-toast' + (isError ? ' is-error' : '');
        el.setAttribute('role', isError ? 'alert' : 'status');
        el.innerHTML = '<i class="bi ' + (isError ? 'bi-exclamation-octagon-fill' : 'bi-check-circle-fill') + '" aria-hidden="true"></i><span>' + esc(message) + '</span>';
        host.appendChild(el);
        window.setTimeout(function () { el.remove(); }, isError ? 6000 : 3200);
    }

    // ---------- randare ----------
    function render() {
        currentCategory();
        renderKpis();
        renderGroups();
        renderCategoryTabs();
        renderTable();
        renderDetails();
        renderTypes();
        renderMatrix();
    }

    function renderKpis() {
        var k = state.kpis || {};
        root.querySelectorAll('[data-rdc-kpi]').forEach(function (el) {
            el.textContent = String(k[el.getAttribute('data-rdc-kpi')] || 0);
        });
        var sub = root.querySelector('[data-rdc-kpi-attention-sub]');
        if (sub) {
            sub.textContent = 'vehicule cu documente lipsă (' + (k.attention_missing || 0) + ') sau expirate (' + (k.attention_expired || 0) + ')';
        }
    }

    function renderGroups() {
        var host = root.querySelector('[data-rdc-groups]');
        host.innerHTML = state.groups.map(function (group) {
            var cards = group.types.map(function (type) {
                var cat = categoryByKey(type);
                if (!cat) return '';
                var active = cat.key === ui.category;
                return '<button type="button" class="rdc-cat-card' + (active ? ' is-active' : '') + '" data-rdc-select="' + esc(cat.key) + '" aria-pressed="' + active + '" aria-label="' + esc(cat.label + ': ' + plural(requiredCountFor(cat.key), 'document obligatoriu', 'documente obligatorii') + ', ' + plural(cat.vehicles, 'vehicul', 'vehicule')) + '">' +
                    '<span class="rdc-cat-card-icon" aria-hidden="true"><i class="bi ' + (CATEGORY_ICONS[cat.key] || 'bi-truck') + '"></i></span>' +
                    '<span><span class="rdc-cat-card-name d-block">' + esc(cat.label) + '</span>' +
                    '<span class="rdc-cat-card-meta d-block">' + plural(requiredCountFor(cat.key), 'document', 'documente') + ' • ' + plural(cat.vehicles, 'vehicul', 'vehicule') + '</span></span>' +
                    '</button>';
            }).join('');
            return '<div class="rdc-card rdc-group"><h2 class="rdc-group-title"><i class="bi ' + (GROUP_ICONS[group.key] || 'bi-truck') + '" aria-hidden="true"></i>' + esc(group.label) + '</h2><div class="rdc-group-cards">' + cards + '</div></div>';
        }).join('');
    }

    function renderCategoryTabs() {
        var host = root.querySelector('[data-rdc-cat-tabs]');
        host.innerHTML = state.categories.map(function (cat) {
            var active = cat.key === ui.category;
            return '<button type="button" role="tab" class="rdc-cat-tab' + (active ? ' is-active' : '') + '" data-rdc-select="' + esc(cat.key) + '" aria-selected="' + active + '" tabindex="' + (active ? '0' : '-1') + '">' +
                '<i class="bi ' + (CATEGORY_ICONS[cat.key] || 'bi-truck') + '" aria-hidden="true"></i>' + esc(cat.label) + '</button>';
        }).join('');
    }

    function filteredDocuments() {
        var needle = ui.search.trim().toLowerCase();
        return state.documents.filter(function (doc) {
            var cell = cellOf(doc, ui.category);
            if (needle && doc.label.toLowerCase().indexOf(needle) === -1) return false;
            if (ui.filter === 'required') return !!(cell && cell.required);
            if (ui.filter === 'unconfigured') return !cell;
            return true;
        });
    }

    function renderTable() {
        var host = root.querySelector('[data-rdc-rows]');
        var docs = filteredDocuments();
        if (!docs.length) {
            var msg = ui.search ? 'Niciun document nu corespunde căutării.' : (ui.filter === 'unconfigured' ? 'Toate documentele sunt configurate pentru această categorie.' : 'Nu există documente pentru filtrul ales.');
            host.innerHTML = '<tr class="rdc-empty-row"><td colspan="7">' + esc(msg) + '</td></tr>';
            return;
        }

        host.innerHTML = docs.map(function (doc) {
            var cell = cellOf(doc, ui.category);
            var configured = !!cell;
            var required = !!(cell && cell.required);
            var expiry = !configured
                ? '<span class="rdc-muted">—</span>'
                : (cell.requires_expiry
                    ? '<span class="rdc-badge rdc-badge-red"><i class="bi bi-calendar-x" aria-hidden="true"></i>Expirare necesară</span>'
                    : '<span class="rdc-badge rdc-badge-gray"><i class="bi bi-slash-circle" aria-hidden="true"></i>Fără expirare</span>');
            var cost = !configured
                ? '<span class="rdc-muted">—</span>'
                : '<a class="rdc-cost" href="' + esc(config.urls.costs) + '" title="Cost și valabilitate implicite pentru categorie. Prețurile pe vehicul se setează în Configurare Costuri.">' +
                    '<i class="bi bi-file-earmark-text" aria-hidden="true"></i>' + formatMoney(cell.cost) + ' lei · ' + esc(cell.validity_days) + ' zile' +
                    (cell.prices > 0 ? ' <small title="' + plural(cell.prices, 'preț setat', 'prețuri setate') + ' pe vehicul">+' + cell.prices + ' pe vehicul</small>' : '') + '</a>';
            var status = !configured
                ? '<span class="rdc-badge rdc-badge-red rdc-badge-pill"><i class="bi bi-x-circle" aria-hidden="true"></i>Neconfigurat</span>'
                : (required
                    ? '<span class="rdc-badge rdc-badge-green rdc-badge-pill"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Obligatoriu</span>'
                    : '<span class="rdc-badge rdc-badge-outline rdc-badge-pill"><i class="bi bi-dash-circle" aria-hidden="true"></i>Opțional</span>');
            var switchTitle = configured ? (required ? 'Obligatoriu – apasă pentru a-l face opțional' : 'Opțional – apasă pentru a-l face obligatoriu') : 'Neconfigurat – apasă pentru a-l adăuga ca obligatoriu (cu dată de expirare)';

            var actions = '';
            if (canManage) {
                var menu = configured
                    ? '<li><button type="button" class="dropdown-item" data-rdc-row-action="toggle-expiry" data-doc="' + esc(doc.key) + '"><i class="bi ' + (cell.requires_expiry ? 'bi-calendar-minus' : 'bi-calendar-plus') + '" aria-hidden="true"></i>' + (cell.requires_expiry ? 'Nu mai cere data de expirare' : 'Cere data de expirare') + '</button></li>' +
                      '<li><a class="dropdown-item" href="' + esc(config.urls.costs) + '"><i class="bi bi-cash-coin" aria-hidden="true"></i>Prețuri pe vehicul (Configurare Costuri)</a></li>' +
                      '<li><a class="dropdown-item" href="' + esc(config.urls.types + '&q=' + encodeURIComponent(doc.label)) + '"><i class="bi bi-ui-checks" aria-hidden="true"></i>Câmpuri personalizate</a></li>' +
                      '<li><hr class="dropdown-divider"></li>' +
                      '<li><button type="button" class="dropdown-item text-danger" data-rdc-row-action="remove" data-doc="' + esc(doc.key) + '"><i class="bi bi-x-circle" aria-hidden="true"></i>Scoate din categorie</button></li>'
                    : '<li><button type="button" class="dropdown-item" data-rdc-row-action="add" data-doc="' + esc(doc.key) + '"><i class="bi bi-plus-circle" aria-hidden="true"></i>Adaugă în categorie…</button></li>' +
                      '<li><button type="button" class="dropdown-item" data-rdc-row-action="edit-type" data-doc="' + esc(doc.key) + '"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>Vezi pe toate categoriile</button></li>';
                actions = '<div class="rdc-actions">' +
                    '<button type="button" class="rdc-icon-btn" data-rdc-row-action="' + (configured ? 'edit' : 'add') + '" data-doc="' + esc(doc.key) + '" title="' + (configured ? 'Editează' : 'Adaugă în categorie') + '" aria-label="' + (configured ? 'Editează ' : 'Adaugă ') + esc(doc.label) + '"><i class="bi bi-pencil" aria-hidden="true"></i></button>' +
                    '<div class="dropdown"><button type="button" class="rdc-icon-btn" data-bs-toggle="dropdown" data-bs-popper-config="{&quot;strategy&quot;:&quot;fixed&quot;}" aria-expanded="false" title="Mai multe acțiuni" aria-label="Mai multe acțiuni pentru ' + esc(doc.label) + '"><i class="bi bi-three-dots" aria-hidden="true"></i></button>' +
                    '<ul class="dropdown-menu dropdown-menu-end">' + menu + '</ul></div></div>';
            } else {
                actions = '<span class="rdc-muted">—</span>';
            }

            return '<tr class="' + (configured ? '' : 'is-unconfigured') + '">' +
                '<td>' + docIconHtml(doc.label) + '</td>' +
                '<td><span class="rdc-doc-name">' + esc(doc.label) + '</span></td>' +
                '<td><button type="button" class="rdc-switch" role="switch" aria-checked="' + required + '" aria-label="' + esc(doc.label) + ' obligatoriu" title="' + esc(switchTitle) + '" data-rdc-toggle="' + esc(doc.key) + '"' + (canManage ? '' : ' disabled') + '></button></td>' +
                '<td>' + expiry + '</td>' +
                '<td class="rdc-td-cost">' + cost + '</td>' +
                '<td>' + status + '</td>' +
                '<td class="rdc-col-actions">' + actions + '</td>' +
                '</tr>';
        }).join('');
    }

    function renderDetails() {
        var cat = currentCategory();
        if (!cat) return;
        var total = 0, required = 0;
        state.documents.forEach(function (doc) {
            var cell = cellOf(doc, cat.key);
            if (cell) {
                total++;
                if (cell.required) required++;
            }
        });
        root.querySelector('[data-rdc-detail-name]').textContent = cat.label;
        root.querySelector('[data-rdc-detail-sub]').textContent = plural(cat.vehicles, 'vehicul', 'vehicule') + ' în această categorie';
        root.querySelector('[data-rdc-detail-icon]').innerHTML = '<i class="bi ' + (CATEGORY_ICONS[cat.key] || 'bi-truck') + '" aria-hidden="true"></i>';
        var values = { total: total, required: required, optional: total - required, vehicles: cat.vehicles };
        root.querySelectorAll('[data-rdc-detail]').forEach(function (el) {
            el.textContent = String(values[el.getAttribute('data-rdc-detail')] || 0);
        });
    }

    function renderTypes() {
        var host = root.querySelector('[data-rdc-types]');
        var docs = ui.typesExpanded ? state.documents : state.documents.slice(0, 5);
        host.innerHTML = docs.length ? docs.map(function (doc) {
            var categories = Object.keys(doc.cells || {}).length;
            return '<li>' + docIconHtml(doc.label) +
                '<span class="rdc-type-name" title="' + esc(doc.label) + '">' + esc(doc.label) + '</span>' +
                '<span class="rdc-badge rdc-badge-green" title="Configurat pe ' + plural(categories, 'categorie', 'categorii') + '"><i class="bi bi-check2" aria-hidden="true"></i>Activ</span>' +
                (canManage
                    ? '<span class="rdc-type-actions"><button type="button" class="rdc-icon-btn" data-rdc-type-action="edit" data-doc="' + esc(doc.key) + '" title="Editează pe categorii" aria-label="Editează ' + esc(doc.label) + '"><i class="bi bi-pencil" aria-hidden="true"></i></button>' +
                      '<button type="button" class="rdc-icon-btn is-danger" data-rdc-type-action="delete" data-doc="' + esc(doc.key) + '" title="Șterge tipul de document" aria-label="Șterge ' + esc(doc.label) + '"><i class="bi bi-trash" aria-hidden="true"></i></button></span>'
                    : '<span></span>') +
                '</li>';
        }).join('') : '<li><span></span><span class="rdc-muted">Nu există tipuri de documente.</span><span></span><span></span></li>';

        var toggle = root.querySelector('[data-rdc-action="toggle-types"]');
        toggle.hidden = state.documents.length <= 5;
        toggle.setAttribute('aria-expanded', String(ui.typesExpanded));
        toggle.innerHTML = ui.typesExpanded
            ? 'Arată mai puține <i class="bi bi-arrow-up" aria-hidden="true"></i>'
            : 'Vezi toate tipurile de documente (' + state.documents.length + ') <i class="bi bi-arrow-right" aria-hidden="true"></i>';
    }

    function renderMatrix() {
        var head = root.querySelector('[data-rdc-matrix-head]');
        var body = root.querySelector('[data-rdc-matrix-body]');
        head.innerHTML = '<tr><th>Tip document</th>' + state.categories.map(function (cat) {
            return '<th><i class="bi ' + (CATEGORY_ICONS[cat.key] || 'bi-truck') + '" aria-hidden="true"></i>' + esc(cat.label) + '</th>';
        }).join('') + '</tr>';
        body.innerHTML = state.documents.map(function (doc) {
            return '<tr><td>' + docIconHtml(doc.label) + ' ' + esc(doc.label) + '</td>' + state.categories.map(function (cat) {
                var cell = cellOf(doc, cat.key);
                if (!cell) return '<td><span class="rdc-mstat rdc-mstat-unconfigured"><i class="bi bi-x-circle" aria-hidden="true"></i>Neconfigurat</span></td>';
                if (cell.required) return '<td><span class="rdc-mstat rdc-mstat-required"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Obligatoriu</span></td>';
                return '<td><span class="rdc-mstat rdc-mstat-optional"><i class="bi bi-dash-circle" aria-hidden="true"></i>Opțional</span></td>';
            }).join('') + '</tr>';
        }).join('');

        var section = root.querySelector('[data-rdc-matrix]');
        section.classList.toggle('is-expanded', ui.matrixExpanded);
        var btn = section.querySelector('[data-rdc-action="toggle-matrix"]');
        btn.setAttribute('aria-expanded', String(ui.matrixExpanded));
        btn.querySelector('span').textContent = ui.matrixExpanded ? 'Restrânge matricea' : 'Afișează matricea completă';
    }

    // ---------- modale ----------
    function modal(id) {
        var el = document.getElementById(id);
        return window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(el) : null;
    }

    var formModalEl = document.getElementById('rdcFormModal');
    var formHandler = null;

    function openForm(title, bodyHtml, submitLabel, onSubmit, options) {
        options = options || {};
        formModalEl.querySelector('[data-rdc-form-title]').textContent = title;
        formModalEl.querySelector('[data-rdc-form-body]').innerHTML = bodyHtml;
        var submit = formModalEl.querySelector('[data-rdc-form-submit]');
        submit.textContent = submitLabel || 'Salvează';
        submit.hidden = !!options.hideSubmit;
        submit.disabled = false;
        formModalEl.querySelector('[data-bs-dismiss="modal"].rdc-btn').textContent = options.hideSubmit ? 'Închide' : 'Renunță';
        formHandler = onSubmit;
        var instance = modal('rdcFormModal');
        if (instance) instance.show();
        if (options.onOpen) options.onOpen(formModalEl);
    }

    formModalEl.addEventListener('shown.bs.modal', function () {
        var first = formModalEl.querySelector('[data-rdc-form-body] input:not([type=hidden]):not([disabled]), [data-rdc-form-body] select');
        if (first) first.focus();
    });

    formModalEl.querySelector('[data-rdc-form]').addEventListener('submit', function (event) {
        event.preventDefault();
        event.stopPropagation(); // garda globala anti-dublu-submit din app.js
        if (!formHandler) return;
        var submit = formModalEl.querySelector('[data-rdc-form-submit]');
        var errorEl = formModalEl.querySelector('[data-rdc-form-error]');
        if (errorEl) errorEl.textContent = '';
        var result = formHandler(formModalEl);
        if (result === false) return;
        submit.disabled = true;
        var original = submit.textContent;
        submit.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Se salvează…';
        Promise.resolve(result).then(function () {
            var instance = modal('rdcFormModal');
            if (instance) instance.hide();
        }).catch(function (error) {
            if (errorEl) errorEl.textContent = error && error.message ? error.message : 'Eroare.';
        }).then(function () {
            submit.disabled = false;
            submit.textContent = original;
        });
    }, true);

    var confirmHandler = null;
    function openConfirm(title, bodyHtml, okLabel, onConfirm) {
        var el = document.getElementById('rdcConfirmModal');
        el.querySelector('[data-rdc-confirm-title]').textContent = title;
        el.querySelector('[data-rdc-confirm-body]').innerHTML = bodyHtml;
        el.querySelector('[data-rdc-confirm-ok]').textContent = okLabel || 'Șterge';
        confirmHandler = onConfirm;
        var instance = modal('rdcConfirmModal');
        if (instance) instance.show();
    }

    document.querySelector('[data-rdc-confirm-ok]').addEventListener('click', function () {
        var btn = this;
        if (!confirmHandler) return;
        btn.disabled = true;
        Promise.resolve(confirmHandler()).then(function () {
            var instance = modal('rdcConfirmModal');
            if (instance) instance.hide();
        }).catch(function () { /* mesajul e deja in toast */ }).then(function () {
            btn.disabled = false;
        });
    });

    function checkboxRow(id, title, hint, checked) {
        return '<div class="rdc-check-row"><label for="' + id + '"><strong>' + esc(title) + '</strong>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>' +
            '<div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="' + id + '"' + (checked ? ' checked' : '') + '></div></div>';
    }

    function categoryChecks(selected) {
        return '<div class="rdc-cat-checks">' + state.groups.map(function (group) {
            return '<div class="rdc-cat-checks-group">' + esc(group.label) + '</div>' + group.types.map(function (type) {
                var cat = categoryByKey(type);
                return '<label><input class="form-check-input m-0" type="checkbox" name="rdc_types" value="' + esc(type) + '"' + (selected.indexOf(type) !== -1 ? ' checked' : '') + '>' + esc(cat ? cat.label : type) + '</label>';
            }).join('');
        }).join('') + '</div>';
    }

    function openCreateType(preselected) {
        var body =
            '<div class="mb-3"><label class="form-label" for="rdc_new_name">Tip document *</label>' +
            '<input type="text" class="form-control" id="rdc_new_name" maxlength="120" placeholder="Ex: Licență transport" required></div>' +
            '<div class="mb-3"><div class="form-label">Categorii de vehicule *</div>' + categoryChecks(preselected) + '</div>' +
            checkboxRow('rdc_new_required', 'Obligatoriu', 'Vehiculele din categoriile bifate trebuie să aibă documentul.', true) +
            checkboxRow('rdc_new_expiry', 'Cere data de expirare în formularul Documente', null, true) +
            '<div class="form-text mt-2">Se creează cu cost 0 și valabilitate 365 de zile; le ajustezi din Configurare Costuri.</div>' +
            '<div class="rdc-field-error" data-rdc-form-error></div>';
        openForm('Adaugă tip document', body, 'Salvează tipul', function (el) {
            var name = el.querySelector('#rdc_new_name').value.trim();
            var types = Array.prototype.map.call(el.querySelectorAll('input[name=rdc_types]:checked'), function (input) { return input.value; });
            var errorEl = el.querySelector('[data-rdc-form-error]');
            if (!name) { errorEl.textContent = 'Completează numele documentului.'; return false; }
            if (!types.length) { errorEl.textContent = 'Bifează cel puțin o categorie.'; return false; }
            return api('create_type', {
                document: name,
                vehicle_types: types,
                required: el.querySelector('#rdc_new_required').checked,
                requires_expiry: el.querySelector('#rdc_new_expiry').checked
            });
        });
    }

    function openAddToCategory(presetKey) {
        var cat = currentCategory();
        var available = state.documents.filter(function (doc) { return !cellOf(doc, cat.key); });
        var options = available.map(function (doc) {
            return '<option value="' + esc(doc.key) + '"' + (doc.key === presetKey ? ' selected' : '') + '>' + esc(doc.label) + '</option>';
        }).join('');
        var body =
            '<div class="mb-3"><label class="form-label" for="rdc_add_doc">Tip document</label>' +
            '<select class="form-select" id="rdc_add_doc">' + options + '<option value="__new__"' + (!available.length ? ' selected' : '') + '>+ Tip document nou…</option></select></div>' +
            '<div class="mb-3" data-rdc-new-wrap hidden><label class="form-label" for="rdc_add_name">Nume document nou</label>' +
            '<input type="text" class="form-control" id="rdc_add_name" maxlength="120" placeholder="Ex: Licență transport"></div>' +
            checkboxRow('rdc_add_required', 'Obligatoriu', null, true) +
            checkboxRow('rdc_add_expiry', 'Cere data de expirare în formularul Documente', null, true) +
            '<div class="form-text mt-2">Se adaugă cu cost 0 și valabilitate 365 de zile pentru ' + esc(cat.label) + '.</div>' +
            '<div class="rdc-field-error" data-rdc-form-error></div>';
        openForm('Adaugă document – ' + cat.label, body, 'Adaugă', function (el) {
            var selected = el.querySelector('#rdc_add_doc').value;
            var errorEl = el.querySelector('[data-rdc-form-error]');
            var data = {
                vehicle_type: cat.key,
                required: el.querySelector('#rdc_add_required').checked,
                requires_expiry: el.querySelector('#rdc_add_expiry').checked
            };
            if (selected === '__new__') {
                var name = el.querySelector('#rdc_add_name').value.trim();
                if (!name) { errorEl.textContent = 'Completează numele documentului.'; return false; }
                var existing = docByKey(name.toUpperCase());
                if (existing && cellOf(existing, cat.key)) { errorEl.textContent = 'Documentul este deja configurat pentru această categorie.'; return false; }
                if (existing) {
                    data.document = existing.label;
                    return api('add_to_category', data);
                }
                data.document = name;
                data.vehicle_types = [cat.key];
                delete data.vehicle_type;
                return api('create_type', data);
            }
            data.document = docByKey(selected).label;
            return api('add_to_category', data);
        }, {
            onOpen: function (el) {
                var select = el.querySelector('#rdc_add_doc');
                var wrap = el.querySelector('[data-rdc-new-wrap]');
                var sync = function () { wrap.hidden = select.value !== '__new__'; };
                select.addEventListener('change', sync);
                sync();
            }
        });
    }

    function openEditCell(doc) {
        var cat = currentCategory();
        var cell = cellOf(doc, cat.key);
        if (!cell) { openAddToCategory(doc.key); return; }
        var body =
            '<div class="rdc-info-box mb-3"><strong>' + esc(doc.label) + '</strong> · ' + esc(cat.label) + '</div>' +
            checkboxRow('rdc_edit_required', 'Obligatoriu', 'Lipsa sau expirarea documentului face vehiculul inactiv.', cell.required) +
            checkboxRow('rdc_edit_expiry', 'Cere data de expirare în formularul Documente', 'Se aplică doar pentru ' + cat.label + '.', cell.requires_expiry) +
            '<div class="rdc-info-box mt-3">Cost implicit: <strong>' + formatMoney(cell.cost) + ' lei</strong> · valabilitate <strong>' + esc(cell.validity_days) + ' zile</strong>' +
            (cell.prices > 0 ? ' · ' + plural(cell.prices, 'preț setat', 'prețuri setate') + ' pe vehicul' : '') +
            '<br><a href="' + esc(config.urls.costs) + '">Configurare Costuri</a> · <a href="' + esc(config.urls.types + '&q=' + encodeURIComponent(doc.label)) + '">Câmpuri personalizate</a></div>' +
            '<div class="form-text mt-2">Numele nu se poate schimba aici: documentele deja încărcate sunt legate de nume.</div>' +
            '<div class="rdc-field-error" data-rdc-form-error></div>';
        openForm('Editează document', body, 'Salvează', function (el) {
            var wantRequired = el.querySelector('#rdc_edit_required').checked;
            var wantExpiry = el.querySelector('#rdc_edit_expiry').checked;
            var chain = Promise.resolve();
            var changed = false;
            if (wantRequired !== cell.required) {
                changed = true;
                chain = chain.then(function () { return api('toggle_required', { document: doc.label, vehicle_type: cat.key, required: wantRequired }); });
            }
            if (wantExpiry !== cell.requires_expiry) {
                changed = true;
                chain = chain.then(function () { return api('set_expiry', { document: doc.label, vehicle_type: cat.key, requires_expiry: wantExpiry }); });
            }
            return changed ? chain : Promise.resolve();
        });
    }

    function openEditType(doc) {
        var renderGrid = function () {
            var current = docByKey(doc.key) || doc;
            return '<table class="rdc-type-grid"><thead><tr><th>Categorie</th><th>Configurat</th><th>Obligatoriu</th><th>Cere expirare</th></tr></thead><tbody>' +
                state.categories.map(function (cat) {
                    var cell = cellOf(current, cat.key);
                    return '<tr><td>' + esc(cat.label) + '</td>' +
                        '<td>' + (cell ? '<span class="rdc-badge rdc-badge-green">Da</span>' : '<span class="rdc-badge rdc-badge-red">Nu</span>') + '</td>' +
                        '<td><button type="button" class="rdc-switch" role="switch" aria-checked="' + !!(cell && cell.required) + '" aria-label="' + esc(cat.label) + ' obligatoriu" data-rdc-grid-required="' + esc(cat.key) + '"></button></td>' +
                        '<td><button type="button" class="rdc-switch" role="switch" aria-checked="' + !!(cell && cell.requires_expiry) + '" aria-label="' + esc(cat.label) + ' cere expirare" data-rdc-grid-expiry="' + esc(cat.key) + '"' + (cell ? '' : ' disabled') + '></button></td></tr>';
                }).join('') + '</tbody></table>';
        };
        var body =
            '<div class="rdc-info-box mb-3"><strong>' + esc(doc.label) + '</strong> · ' + plural(doc.uploaded, 'document încărcat', 'documente încărcate') + ' · ' + plural(doc.prices, 'preț', 'prețuri') + ' pe vehicul</div>' +
            '<div data-rdc-grid>' + renderGrid() + '</div>' +
            '<div class="form-text mt-2">Fiecare comutator se salvează imediat. Activarea „Obligatoriu” pe o categorie neconfigurată o adaugă (cost 0, 365 de zile, cu dată de expirare). Numele nu se poate schimba: documentele încărcate sunt legate de nume.</div>';
        openForm('Editează tip document', body, '', null, {
            hideSubmit: true,
            onOpen: function (el) {
                var grid = el.querySelector('[data-rdc-grid]');
                grid.onclick = function (event) {
                    var btn = event.target.closest('[data-rdc-grid-required], [data-rdc-grid-expiry]');
                    if (!btn || btn.disabled || ui.busy) return;
                    var isRequired = btn.hasAttribute('data-rdc-grid-required');
                    var type = btn.getAttribute(isRequired ? 'data-rdc-grid-required' : 'data-rdc-grid-expiry');
                    var next = btn.getAttribute('aria-checked') !== 'true';
                    btn.classList.add('is-busy');
                    var request = isRequired
                        ? api('toggle_required', { document: doc.label, vehicle_type: type, required: next })
                        : api('set_expiry', { document: doc.label, vehicle_type: type, requires_expiry: next });
                    request.catch(function () {}).then(function () { grid.innerHTML = renderGrid(); });
                };
            }
        });
    }

    function confirmRemoveFromCategory(doc) {
        var cat = currentCategory();
        openConfirm('Scoate din categorie',
            '<p class="mb-2">Scoți <strong>' + esc(doc.label) + '</strong> din <strong>' + esc(cat.label) + '</strong>?</p>' +
            '<ul class="small mb-0"><li>Nu mai apare în formularul Documente pentru această categorie și nu mai e cerut.</li>' +
            '<li>Se pierd costul implicit, valabilitatea și câmpurile personalizate setate pentru această categorie.</li>' +
            '<li>Documentele deja încărcate și prețurile pe vehicul rămân.</li></ul>',
            'Scoate', function () {
                return api('remove_from_category', { document: doc.label, vehicle_type: cat.key });
            });
    }

    function confirmDeleteType(doc) {
        openConfirm('Șterge tipul de document',
            '<p class="mb-2">Ștergi <strong>' + esc(doc.label) + '</strong> de pe <strong>toate</strong> categoriile de vehicule?</p>' +
            '<ul class="small mb-0"><li>Nu mai apare în formularul Documente și nu mai e cerut niciunui vehicul.</li>' +
            '<li>Se șterg și prețurile setate pe vehicul în Configurare Costuri (' + doc.prices + ').</li>' +
            '<li>Documentele deja încărcate rămân în istoric (' + doc.uploaded + ').</li></ul>',
            'Șterge', function () {
                return api('delete_type', { document: doc.label });
            });
    }

    function openHistory() {
        var el = document.getElementById('rdcHistoryModal');
        var body = el.querySelector('[data-rdc-history-body]');
        body.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Se încarcă…</div>';
        var instance = modal('rdcHistoryModal');
        if (instance) instance.show();
        fetch(config.urls.history, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!payload || !payload.success) throw new Error((payload && payload.message) || 'Istoricul nu a putut fi încărcat.');
                body.innerHTML = payload.rows.length
                    ? '<ul class="rdc-history">' + payload.rows.map(function (row) {
                        return '<li><span class="rdc-history-date">' + esc(row.created_at) + '</span><span>' + esc(row.description) + '</span><span class="rdc-history-user">' + esc(row.user) + '</span></li>';
                    }).join('') + '</ul>'
                    : '<div class="rdc-empty-state">Nu există modificări înregistrate. Istoricul se păstrează începând cu 19.10.2026.</div>';
            })
            .catch(function (error) {
                body.innerHTML = '<div class="rdc-empty-state">' + esc(error.message) + '</div>';
            });
    }

    // ---------- evenimente ----------
    function selectCategory(key) {
        if (!categoryByKey(key)) return;
        ui.category = key;
        storeCategory(key);
        render();
    }

    root.addEventListener('click', function (event) {
        var target = event.target;

        var select = target.closest('[data-rdc-select]');
        if (select) {
            selectCategory(select.getAttribute('data-rdc-select'));
            return;
        }

        var filter = target.closest('[data-rdc-filter]');
        if (filter) {
            ui.filter = filter.getAttribute('data-rdc-filter');
            root.querySelectorAll('[data-rdc-filter]').forEach(function (btn) {
                var active = btn === filter;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-pressed', String(active));
            });
            renderTable();
            return;
        }

        var toggle = target.closest('[data-rdc-toggle]');
        if (toggle && !toggle.disabled) {
            if (ui.busy) return;
            var doc = docByKey(toggle.getAttribute('data-rdc-toggle'));
            var next = toggle.getAttribute('aria-checked') !== 'true';
            toggle.classList.add('is-busy');
            toggle.disabled = true;
            api('toggle_required', { document: doc.label, vehicle_type: ui.category, required: next }).catch(function () {});
            return;
        }

        var rowAction = target.closest('[data-rdc-row-action]');
        if (rowAction) {
            var rowDoc = docByKey(rowAction.getAttribute('data-doc'));
            if (!rowDoc) return;
            var action = rowAction.getAttribute('data-rdc-row-action');
            if (action === 'edit') openEditCell(rowDoc);
            if (action === 'add') openAddToCategory(rowDoc.key);
            if (action === 'edit-type') openEditType(rowDoc);
            if (action === 'remove') confirmRemoveFromCategory(rowDoc);
            if (action === 'toggle-expiry') {
                var cell = cellOf(rowDoc, ui.category);
                if (cell) api('set_expiry', { document: rowDoc.label, vehicle_type: ui.category, requires_expiry: !cell.requires_expiry }).catch(function () {});
            }
            return;
        }

        var typeAction = target.closest('[data-rdc-type-action]');
        if (typeAction) {
            var typeDoc = docByKey(typeAction.getAttribute('data-doc'));
            if (!typeDoc) return;
            if (typeAction.getAttribute('data-rdc-type-action') === 'edit') openEditType(typeDoc);
            else confirmDeleteType(typeDoc);
            return;
        }

        var pageAction = target.closest('[data-rdc-action]');
        if (!pageAction) return;
        switch (pageAction.getAttribute('data-rdc-action')) {
            case 'create-type':
                openCreateType([ui.category]);
                break;
            case 'add-to-category':
                openAddToCategory(null);
                break;
            case 'history':
                openHistory();
                break;
            case 'toggle-types':
                ui.typesExpanded = !ui.typesExpanded;
                renderTypes();
                break;
            case 'toggle-matrix':
                ui.matrixExpanded = !ui.matrixExpanded;
                renderMatrix();
                break;
            case 'show-matrix':
                ui.matrixExpanded = true;
                renderMatrix();
                document.getElementById('rdc-matrix').scrollIntoView({ behavior: 'smooth', block: 'start' });
                break;
        }
    });

    // Navigare cu sagetile intre tab-urile de categorie.
    root.querySelector('[data-rdc-cat-tabs]').addEventListener('keydown', function (event) {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        var keys = state.categories.map(function (cat) { return cat.key; });
        var index = keys.indexOf(ui.category);
        index = (index + (event.key === 'ArrowRight' ? 1 : -1) + keys.length) % keys.length;
        selectCategory(keys[index]);
        var active = root.querySelector('.rdc-cat-tab.is-active');
        if (active) active.focus();
        event.preventDefault();
    });

    root.querySelector('[data-rdc-search]').addEventListener('input', function () {
        ui.search = this.value;
        renderTable();
    });

    render();
})();
