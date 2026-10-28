/* Mentenanta -> Interventii V2: lista + KPI + filtre live, panou lateral redimensionabil,
 * selector de componente (Registru piese & lucrari), lucrari, documente, finalizare controlata.
 * Datele vin din endpoint-urile ?page=mentenanta&action=intervention_* (JSON). */
(function () {
    'use strict';

    var STORAGE_WIDTH = 'iv2.drawerWidth';
    var MIN_WIDTH = 480;

    // ------------------------------------------------------------------ utilitare

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtDate(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
        return match ? match[3] + '.' + match[2] + '.' + match[1] : '—';
    }

    function fmtDateTime(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ''));
        return match ? match[3] + '.' + match[2] + '.' + match[1] + ' ' + match[4] + ':' + match[5] : fmtDate(value);
    }

    function fmtNumber(value, decimals) {
        var number = Number(value || 0);
        return number.toLocaleString('ro-RO', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    function fmtMoney(value) {
        return fmtNumber(value, 2) + ' lei';
    }

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var args = arguments;
            var self = this;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(self, args); }, wait);
        };
    }

    function normalize(value) {
        return String(value || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }

    function readJson(id) {
        var node = document.getElementById(id);
        if (!node) {
            return null;
        }
        try {
            return JSON.parse(node.textContent || '{}');
        } catch (error) {
            return null;
        }
    }

    function storageGet(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function storageSet(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (error) {
            /* stocarea locala poate lipsi (mod privat) */
        }
    }

    function autoVehicleType(type) {
        if (type === 'camion' || type === 'cap_tractor') {
            return type;
        }
        if (/^semiremorca/.test(String(type || ''))) {
            return 'semiremorca';
        }
        return '';
    }

    function vehicleTypeLabel(type) {
        return {
            camion: 'Camion', cap_tractor: 'Cap tractor', semiremorca: 'Semiremorcă', semiremorca_primar: 'Semiremorcă',
            semiremorca_distributie: 'Semiremorcă', autoutilitara: 'Autoutilitară', autovehicul: 'Autovehicul'
        }[type] || (type || '—');
    }

    function modal(element) {
        // bootstrap se incarca in footer, dupa scripturile paginii: il cautam la folosire.
        return window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal.getOrCreateInstance(element) : null;
    }

    function toast(message, tone) {
        var host = document.querySelector('.iv2-toasts');
        if (!host) {
            host = document.createElement('div');
            host.className = 'iv2-toasts';
            host.setAttribute('role', 'status');
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }
        var item = document.createElement('div');
        item.className = 'iv2-toast is-' + (tone || 'success');
        item.textContent = message;
        host.appendChild(item);
        setTimeout(function () {
            item.classList.add('is-leaving');
            setTimeout(function () { item.remove(); }, 300);
        }, tone === 'danger' ? 6000 : 3200);
    }

    /** Doua click-uri pe acelasi buton = confirmare (confirm() nativ e blocat in browserul integrat). */
    function armed(button, label) {
        if (button.dataset.armed === '1') {
            return true;
        }
        button.dataset.armed = '1';
        button.classList.add('is-armed');
        var original = button.getAttribute('title') || '';
        button.setAttribute('title', label || 'Apasă din nou pentru confirmare');
        var text = button.querySelector('[data-arm-text]');
        var originalText = text ? text.textContent : '';
        if (text) {
            text.textContent = label || 'Confirmă';
        }
        setTimeout(function () {
            button.dataset.armed = '';
            button.classList.remove('is-armed');
            button.setAttribute('title', original);
            if (text) {
                text.textContent = originalText;
            }
        }, 4000);
        return false;
    }

    function Api(endpoint, token) {
        this.endpoint = endpoint;
        this.token = token;
    }

    Api.prototype.url = function (action, params) {
        var query = new URLSearchParams(Object.assign({ page: 'mentenanta', action: action }, params || {}));
        return this.endpoint + '?' + query.toString();
    };

    Api.prototype.handle = function (response) {
        return response.json().catch(function () {
            return { ok: false, message: 'Răspuns neașteptat de la server (' + response.status + ').' };
        }).then(function (data) {
            if (!response.ok || !data || data.ok === false) {
                var error = new Error((data && data.message) || 'Operațiunea nu a reușit.');
                error.status = response.status;
                throw error;
            }
            return data;
        });
    };

    Api.prototype.get = function (action, params, signal) {
        return fetch(this.url(action, params), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: signal
        }).then(this.handle);
    };

    Api.prototype.post = function (action, data) {
        var body = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            Object.keys(data || {}).forEach(function (key) {
                var value = data[key];
                body.append(key, value === null || value === undefined ? '' : value);
            });
        }
        body.append('_token', this.token);
        return fetch(this.url(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(this.handle);
    };

    // ------------------------------------------------------------------ selector de componente

    /**
     * Ierarhia din Reparatii Auto: Tip vehicul -> Vehicul -> Ramura (Sasiu / Rezervor)
     * -> Subcategorie -> Categorie -> Componenta. Cap tractor are doar Sasiu.
     */
    function ComponentPicker(host, options) {
        this.host = host;
        this.catalog = options.catalog || { categories: [], primary: {}, subcategories: {}, groups: {} };
        this.api = options.api || null;
        this.multiple = options.multiple !== false;
        this.operations = options.operations || {};
        this.onChange = options.onChange || function () {};
        // Km service foloseste alt endpoint (drepturile dispecerului) pentru marcajul "configurata".
        this.loadConfigured = options.loadConfigured || null;
        this.vehicle = null;
        this.configured = {};
        this.selected = new Map();
        this.active = null;
        this.query = '';
        this.onlyConfigured = false;
        this.uid = 'iv2p' + Math.random().toString(36).slice(2, 8);
        this.build();
    }

    ComponentPicker.prototype.build = function () {
        this.host.innerHTML = ''
            + '<div class="iv2-picker">'
            + '  <div class="iv2-picker-top">'
            + '    <div class="iv2-picker-crumb" data-p-crumb></div>'
            + '    <div class="iv2-picker-search"><i class="bi bi-search" aria-hidden="true"></i>'
            + '      <input class="form-control" type="search" data-p-search placeholder="Caută componentă sau cod (ex. pivoți, SUS-002)" aria-label="Caută componentă"></div>'
            + '    <label class="iv2-picker-toggle"><input class="form-check-input m-0" type="checkbox" data-p-configured> Doar configurate pe vehicul</label>'
            + '  </div>'
            + '  <div class="iv2-picker-main">'
            + '    <div class="iv2-picker-tree" data-p-tree role="tree" aria-label="Categorii"></div>'
            + '    <div class="iv2-picker-list" data-p-list role="group" aria-label="Componente"></div>'
            + '  </div>'
            + '  <div class="iv2-picker-selected" data-p-selected></div>'
            + '</div>';
        var self = this;
        this.searchInput = this.host.querySelector('[data-p-search]');
        this.searchInput.addEventListener('input', debounce(function () {
            self.query = self.searchInput.value;
            self.renderList();
        }, 150));
        this.searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
        this.host.querySelector('[data-p-configured]').addEventListener('change', function (event) {
            self.onlyConfigured = event.target.checked;
            self.renderTree();
            self.renderList();
        });
        this.host.querySelector('[data-p-tree]').addEventListener('click', function (event) {
            var button = event.target.closest('[data-p-node]');
            if (!button) {
                return;
            }
            self.active = { primary: button.dataset.primary, category: Number(button.dataset.category) };
            self.query = '';
            self.searchInput.value = '';
            self.renderTree();
            self.renderList();
        });
        this.host.querySelector('[data-p-list]').addEventListener('change', function (event) {
            var input = event.target.closest('[data-p-pick]');
            if (!input) {
                return;
            }
            var id = input.value;
            if (input.checked) {
                if (!self.multiple) {
                    self.selected.clear();
                }
                self.selected.set(id, { key: input.dataset.key, primary: input.dataset.primary, position: '', operation: 'verificare' });
            } else {
                self.selected.delete(id);
            }
            if (!self.multiple) {
                self.renderList();
            }
            self.renderSelected();
            self.onChange(self.value());
        });
        var selectedHost = this.host.querySelector('[data-p-selected]');
        var updateChip = function (event) {
            var chip = event.target.closest('[data-p-chip]');
            if (!chip || !self.selected.has(chip.dataset.pChip)) {
                return;
            }
            var entry = self.selected.get(chip.dataset.pChip);
            if (event.target.matches('[data-p-position]')) {
                entry.position = event.target.value;
            }
            if (event.target.matches('[data-p-operation]')) {
                entry.operation = event.target.value;
            }
            self.onChange(self.value());
        };
        selectedHost.addEventListener('input', updateChip);
        selectedHost.addEventListener('change', updateChip);
        selectedHost.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-p-remove]');
            if (!remove) {
                return;
            }
            self.selected.delete(remove.dataset.pRemove);
            self.renderList();
            self.renderSelected();
            self.onChange(self.value());
        });
        this.render();
    };

    ComponentPicker.prototype.branches = function () {
        var type = this.vehicle ? autoVehicleType(this.vehicle.type) : '';
        var groups = this.catalog.groups || {};
        var sasiu = { primary: 'sasiu', subs: ['sasiu', 'hidraulic'] };
        var rezervor = { primary: 'rezervor', subs: ['sasiu', 'hidraulic', 'livrare_gaz'] };
        var list = type === 'cap_tractor' ? [sasiu] : [sasiu, rezervor];
        return list.map(function (branch) {
            return {
                primary: branch.primary,
                subs: branch.subs.map(function (sub) { return { key: sub, categories: groups[sub] || [] }; })
            };
        });
    };

    ComponentPicker.prototype.categoryById = function (id) {
        return (this.catalog.categories || []).find(function (category) { return Number(category.id) === Number(id); }) || null;
    };

    ComponentPicker.prototype.visibleComponents = function (category) {
        var self = this;
        return (category.components || []).filter(function (component) {
            return !self.onlyConfigured || Object.prototype.hasOwnProperty.call(self.configured, component.key);
        });
    };

    ComponentPicker.prototype.setVehicle = function (vehicle) {
        var changed = !this.vehicle || !vehicle || this.vehicle.id !== vehicle.id;
        this.vehicle = vehicle;
        if (changed) {
            this.active = null;
            this.configured = {};
            var allowed = this.allowedPrimaries();
            var self = this;
            this.selected.forEach(function (entry, id) {
                if (allowed.indexOf(entry.primary) === -1) {
                    self.selected.delete(id);
                }
            });
        }
        this.render();
        if (changed && vehicle && vehicle.id && (this.loadConfigured || this.api)) {
            var self2 = this;
            var request = this.loadConfigured
                ? this.loadConfigured(vehicle.id)
                : this.api.get('intervention_vehicle_components', { vehicle_id: vehicle.id }).then(function (data) { return data.configured; });
            request.then(function (configured) {
                if (self2.vehicle && self2.vehicle.id === vehicle.id) {
                    self2.configured = configured || {};
                    self2.render();
                }
            }).catch(function () { /* fara marcajul "configurata" */ });
        }
    };

    ComponentPicker.prototype.allowedPrimaries = function () {
        return this.branches().map(function (branch) { return branch.primary; });
    };

    ComponentPicker.prototype.render = function () {
        var crumb = this.host.querySelector('[data-p-crumb]');
        if (this.vehicle) {
            crumb.innerHTML = '<span>' + esc(vehicleTypeLabel(this.vehicle.type)) + '</span><i class="bi bi-chevron-right"></i><b>' + esc(this.vehicle.plate) + '</b>'
                + (this.vehicle.model ? '<span>' + esc(this.vehicle.model) + '</span>' : '');
        } else {
            crumb.innerHTML = '<span>Alege vehiculul pentru a filtra componentele</span>';
        }
        this.renderTree();
        this.renderList();
        this.renderSelected();
    };

    ComponentPicker.prototype.renderTree = function () {
        var self = this;
        var html = '';
        var primaryLabels = this.catalog.primary || {};
        var subLabels = this.catalog.subcategories || {};
        this.branches().forEach(function (branch) {
            html += '<div class="tree-primary">' + esc(primaryLabels[branch.primary] || branch.primary) + '</div>';
            branch.subs.forEach(function (sub) {
                html += '<div class="tree-sub">' + esc(subLabels[sub.key] || sub.key) + '</div>';
                sub.categories.forEach(function (categoryId) {
                    var category = self.categoryById(categoryId);
                    if (!category) {
                        return;
                    }
                    var count = self.visibleComponents(category).length;
                    var isActive = self.active && self.active.primary === branch.primary && self.active.category === Number(categoryId);
                    html += '<button type="button" role="treeitem" data-p-node data-primary="' + esc(branch.primary) + '" data-category="' + esc(categoryId) + '" class="' + (isActive ? 'is-active' : '') + '" aria-selected="' + (isActive ? 'true' : 'false') + '">'
                        + '<i class="bi ' + esc(category.icon || 'bi-circle') + '" aria-hidden="true"></i>' + esc(category.name) + '<small>' + count + '</small></button>';
                });
            });
        });
        this.host.querySelector('[data-p-tree]').innerHTML = html;
    };

    ComponentPicker.prototype.renderList = function () {
        var self = this;
        var list = this.host.querySelector('[data-p-list]');
        var rows = [];
        var query = normalize(this.query);
        if (query !== '') {
            var terms = query.split(/\s+/);
            this.branches().forEach(function (branch) {
                branch.subs.forEach(function (sub) {
                    sub.categories.forEach(function (categoryId) {
                        var category = self.categoryById(categoryId);
                        if (!category) {
                            return;
                        }
                        self.visibleComponents(category).forEach(function (component) {
                            var haystack = normalize(component.name + ' ' + component.code + ' ' + category.name);
                            if (terms.every(function (term) { return haystack.indexOf(term) !== -1; })) {
                                rows.push({ component: component, category: category, primary: branch.primary });
                            }
                        });
                    });
                });
            });
        } else if (this.active) {
            var category = this.categoryById(this.active.category);
            if (category) {
                this.visibleComponents(category).forEach(function (component) {
                    rows.push({ component: component, category: category, primary: self.active.primary });
                });
            }
        }

        if (rows.length === 0) {
            list.innerHTML = '<div class="iv2-picker-empty">' + (query !== ''
                ? 'Nicio componentă nu se potrivește căutării.'
                : (this.active ? 'Nicio componentă în această categorie.' : 'Alege o categorie din stânga sau caută după nume / cod.')) + '</div>';
            return;
        }
        var primaryLabels = this.catalog.primary || {};
        var showPrimary = this.allowedPrimaries().length > 1;
        list.innerHTML = rows.slice(0, 200).map(function (row) {
            var id = row.primary + ':' + row.component.key;
            var checked = self.selected.has(id);
            var configured = Object.prototype.hasOwnProperty.call(self.configured, row.component.key);
            return '<label><input class="form-check-input m-0" type="' + (self.multiple ? 'checkbox' : 'radio') + '" name="' + self.uid + '" data-p-pick value="' + esc(id) + '" data-key="' + esc(row.component.key) + '" data-primary="' + esc(row.primary) + '"' + (checked ? ' checked' : '') + '>'
                + '<span><strong>' + esc(row.component.name) + '</strong><small>' + (showPrimary ? esc(primaryLabels[row.primary] || row.primary) + ' › ' : '') + esc(row.category.name) + '</small></span>'
                + (configured ? '<span class="cfg" title="Configurată pe acest vehicul în Reparații Auto">Configurată</span>' : '')
                + '<span class="code">' + esc(row.component.code) + '</span></label>';
        }).join('');
    };

    ComponentPicker.prototype.findComponent = function (key) {
        var found = null;
        (this.catalog.categories || []).some(function (category) {
            return (category.components || []).some(function (component) {
                if (component.key === key) {
                    found = { component: component, category: category };
                    return true;
                }
                return false;
            });
        });
        return found;
    };

    ComponentPicker.prototype.renderSelected = function () {
        var self = this;
        var host = this.host.querySelector('[data-p-selected]');
        if (!this.multiple) {
            host.innerHTML = '';
            return;
        }
        var html = '';
        var primaryLabels = this.catalog.primary || {};
        this.selected.forEach(function (entry, id) {
            var found = self.findComponent(entry.key);
            if (!found) {
                return;
            }
            html += '<div class="iv2-picker-chip" data-p-chip="' + esc(id) + '">'
                + '<span><strong>' + esc(found.component.name) + '</strong><small>' + esc((primaryLabels[entry.primary] || '') + ' › ' + found.category.name + ' · ' + found.component.code) + '</small></span>'
                + '<input class="form-control" data-p-position maxlength="80" placeholder="Localizare (ex. Axa 4)" aria-label="Localizare ' + esc(found.component.name) + '" value="' + esc(entry.position) + '">'
                + '<select class="form-select" data-p-operation aria-label="Lucrare solicitată ' + esc(found.component.name) + '">'
                + Object.keys(self.operations).map(function (key) {
                    return '<option value="' + esc(key) + '"' + (entry.operation === key ? ' selected' : '') + '>' + esc(self.operations[key]) + '</option>';
                }).join('')
                + '</select>'
                + '<button class="iv2-icon-btn is-danger" type="button" data-p-remove="' + esc(id) + '" aria-label="Scoate ' + esc(found.component.name) + '"><i class="bi bi-x-lg"></i></button>'
                + '</div>';
        });
        host.innerHTML = html;
    };

    ComponentPicker.prototype.value = function () {
        var result = [];
        this.selected.forEach(function (entry) {
            result.push({ key: entry.key, primary: entry.primary, position: entry.position || '', operation: entry.operation || 'verificare' });
        });
        return result;
    };

    ComponentPicker.prototype.clear = function () {
        this.selected.clear();
        this.query = '';
        if (this.searchInput) {
            this.searchInput.value = '';
        }
        this.render();
    };

    // ------------------------------------------------------------------ formularul Adauga / Editeaza

    function initInterventionForm() {
        var form = document.querySelector('[data-iv2-intervention-form]');
        var boot = readJson('iv2-form-boot');
        if (!form || !boot) {
            return;
        }
        var api = new Api(boot.endpoint, '');
        var vehicleSelect = form.querySelector('[data-iv2-form-vehicle]');
        var hidden = form.querySelector('[data-iv2-components-json]');
        var picker = new ComponentPicker(form.querySelector('[data-iv2-form-picker]'), {
            catalog: boot.catalog,
            api: api,
            operations: (boot.labels || {}).operation || {},
            onChange: function (value) { hidden.value = JSON.stringify(value); }
        });

        function syncVehicle() {
            var id = Number(vehicleSelect.value || 0);
            var vehicle = id > 0 && boot.vehicles ? boot.vehicles[id] : null;
            picker.setVehicle(vehicle ? { id: id, plate: vehicle.plate, model: vehicle.model, type: vehicle.type } : null);
        }

        vehicleSelect.addEventListener('change', syncVehicle);
        syncVehicle();
        form.addEventListener('input', function (event) {
            if (event.target.classList.contains('is-invalid') && String(event.target.value || '').trim() !== '') {
                event.target.classList.remove('is-invalid');
            }
        });
        form.addEventListener('change', function (event) {
            if (event.target.classList.contains('is-invalid') && String(event.target.value || '').trim() !== '') {
                event.target.classList.remove('is-invalid');
            }
        });

        form.addEventListener('submit', function (event) {
            hidden.value = JSON.stringify(picker.value());
            var missing = [];
            [['vehicle_id', 'Vehicul'], ['data_programata', 'Data programată'], ['descriere', 'Problemă raportată / Observații']].forEach(function (pair) {
                var field = form.elements[pair[0]];
                var valid = field && String(field.value || '').trim() !== '';
                if (field) {
                    field.classList.toggle('is-invalid', !valid);
                }
                if (!valid) {
                    missing.push(pair[1]);
                }
            });
            if (missing.length > 0) {
                // stopPropagation: garda globala anti-dublu-submit din app.js nu tine cont de preventDefault.
                event.preventDefault();
                event.stopPropagation();
                toast('Completează: ' + missing.join(', ') + '.', 'danger');
                var first = form.querySelector('.is-invalid');
                if (first) {
                    first.focus();
                }
            }
        }, true);

        var modalElement = document.getElementById('maintenanceScheduleModal');
        if (modalElement) {
            modalElement.addEventListener('hidden.bs.modal', function () {
                // Formularul de editare deschis din URL: la inchidere, URL-ul revine la lista.
                var url = new URL(window.location.href);
                if (url.searchParams.has('edit_intervention_id')) {
                    url.searchParams.delete('edit_intervention_id');
                    window.history.replaceState(null, '', url.toString());
                }
            });
        }
    }

    // ------------------------------------------------------------------ pagina Interventii

    function InterventionsPage(root, boot) {
        this.root = root;
        this.boot = boot;
        this.api = new Api(boot.endpoint, boot.token);
        this.labels = boot.labels || {};
        this.can = boot.can || {};
        this.filters = Object.assign({ search: '', vehicle_id: '', tip: '', status: '', date_from: '', date_to: '', responsabil: '', sursa: '' }, boot.filters || {});
        this.page = (boot.list && boot.list.page) || 1;
        this.perPage = (boot.list && boot.list.per_page) || 10;
        this.list = boot.list || { rows: [], total: 0, kpis: {} };
        this.selectedId = 0;
        this.detail = null;
        this.tab = 'sumar';
        this.dirty = {};
        this.editing = {};
        this.loadController = null;
        this.drawer = document.querySelector('[data-iv2-drawer]');
        this.init();
    }

    InterventionsPage.prototype.init = function () {
        var self = this;
        var filtersForm = this.root.querySelector('[data-iv2-filters]');
        this.filtersForm = filtersForm;
        var reload = function () { self.page = 1; self.load(); };
        filtersForm.addEventListener('submit', function (event) { event.preventDefault(); event.stopPropagation(); reload(); }, true);
        filtersForm.elements.search.addEventListener('input', debounce(function () {
            self.filters.search = filtersForm.elements.search.value.trim();
            reload();
        }, 300));
        ['vehicle_id', 'tip', 'status', 'date_from', 'date_to', 'responsabil'].forEach(function (name) {
            filtersForm.elements[name].addEventListener('change', function () {
                self.filters[name] = filtersForm.elements[name].value;
                reload();
            });
        });
        filtersForm.addEventListener('reset', function (event) {
            event.preventDefault();
            Object.keys(self.filters).forEach(function (key) { self.filters[key] = ''; });
            Array.prototype.forEach.call(filtersForm.elements, function (field) {
                if (field.name) {
                    field.value = '';
                }
            });
            reload();
        });
        this.root.querySelectorAll('[data-iv2-kpi]').forEach(function (card) {
            card.addEventListener('click', function () {
                var status = card.dataset.iv2Kpi;
                self.filters.status = self.filters.status === status ? '' : status;
                filtersForm.elements.status.value = self.filters.status;
                reload();
            });
        });
        this.root.querySelector('[data-iv2-per-page]').addEventListener('change', function (event) {
            self.perPage = Number(event.target.value) || 10;
            reload();
        });
        this.root.querySelector('[data-iv2-pager]').addEventListener('click', function (event) {
            var button = event.target.closest('[data-page]');
            if (button && !button.disabled) {
                self.page = Number(button.dataset.page);
                self.load();
            }
        });

        var rows = this.root.querySelector('[data-iv2-rows]');
        rows.addEventListener('click', function (event) {
            var actionButton = event.target.closest('[data-row-action]');
            var row = event.target.closest('tr[data-id]');
            if (!row) {
                return;
            }
            var id = Number(row.dataset.id);
            if (event.target.closest('.dropdown-menu') && !actionButton) {
                return;
            }
            if (actionButton) {
                var action = actionButton.dataset.rowAction;
                if (action === 'edit') {
                    self.editInForm(id);
                } else if (action === 'delete') {
                    // Primul click doar armeaza butonul: meniul ramane deschis pentru confirmare.
                    if (armed(actionButton, 'Apasă din nou pentru ștergere')) {
                        self.deleteIntervention(id);
                    } else {
                        event.stopPropagation();
                    }
                } else {
                    self.open(id, action === 'view' ? 'sumar' : action);
                }
                return;
            }
            if (event.target.closest('[data-bs-toggle="dropdown"]')) {
                return;
            }
            self.open(id, 'sumar');
        });
        rows.addEventListener('keydown', function (event) {
            var row = event.target.closest('tr[data-id]');
            if (row && event.target === row && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                self.open(Number(row.dataset.id), 'sumar');
            }
        });

        this.initDrawer();
        this.render();
        if (this.boot.open_id > 0) {
            this.open(this.boot.open_id, 'sumar');
        }
    };

    InterventionsPage.prototype.queryParams = function () {
        var params = {};
        var self = this;
        Object.keys(this.filters).forEach(function (key) {
            var value = self.filters[key];
            if (value !== '' && value !== null && value !== undefined && String(value) !== '0') {
                params[key] = self.filters[key];
            }
        });
        return params;
    };

    InterventionsPage.prototype.syncUrl = function () {
        var url = new URL(window.location.href);
        ['search', 'vehicle_id', 'tip', 'status', 'date_from', 'date_to', 'responsabil', 'sursa', 'p', 'per_page', 'intervention_id', 'edit_intervention_id'].forEach(function (key) {
            url.searchParams.delete(key);
        });
        var params = this.queryParams();
        Object.keys(params).forEach(function (key) { url.searchParams.set(key, params[key]); });
        if (this.page > 1) {
            url.searchParams.set('p', this.page);
        }
        if (this.perPage !== 10) {
            url.searchParams.set('per_page', this.perPage);
        }
        if (this.selectedId > 0) {
            url.searchParams.set('intervention_id', this.selectedId);
        }
        window.history.replaceState(null, '', url.toString());

        var exportLink = document.querySelector('[data-stock-export]');
        if (exportLink) {
            var exportUrl = new URL(exportLink.href, window.location.href);
            ['search', 'vehicle_id', 'tip', 'status', 'date_from', 'date_to', 'responsabil', 'sursa', 'p', 'per_page', 'intervention_id', 'edit_intervention_id'].forEach(function (key) {
                exportUrl.searchParams.delete(key);
            });
            Object.keys(params).forEach(function (key) { exportUrl.searchParams.set(key, params[key]); });
            exportLink.href = exportUrl.toString();
        }
    };

    InterventionsPage.prototype.load = function () {
        var self = this;
        if (this.loadController) {
            this.loadController.abort();
        }
        this.loadController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var card = this.root.querySelector('[data-iv2-table-card]');
        card.classList.add('iv2-loading');
        card.setAttribute('aria-busy', 'true');
        var params = Object.assign(this.queryParams(), { p: this.page, per_page: this.perPage });
        return this.api.get('intervention_list', params, this.loadController ? this.loadController.signal : undefined).then(function (data) {
            self.list = data;
            self.page = data.page;
            self.render();
        }).catch(function (error) {
            if (error.name !== 'AbortError') {
                toast(error.message, 'danger');
            }
        }).then(function () {
            card.classList.remove('iv2-loading');
            card.setAttribute('aria-busy', 'false');
        });
    };

    InterventionsPage.prototype.render = function () {
        var self = this;
        var kpis = this.list.kpis || {};
        this.root.querySelectorAll('[data-iv2-kpi]').forEach(function (card) {
            var status = card.dataset.iv2Kpi;
            card.querySelector('[data-iv2-kpi-value]').textContent = String(kpis[status] || 0);
            var active = self.filters.status === status;
            card.classList.toggle('is-active', active);
            card.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        var total = Number(this.list.total || 0);
        this.root.querySelector('[data-iv2-total]').textContent = total === 1 ? '1 înregistrare' : total + ' înregistrări';
        this.root.querySelector('[data-iv2-rows]').innerHTML = (this.list.rows || []).length
            ? this.list.rows.map(function (row) { return self.rowHtml(row); }).join('')
            : '<tr class="iv2-empty-row"><td colspan="11" class="text-center text-muted py-5">Nu există intervenții pentru filtrele selectate.</td></tr>';
        this.renderPager();
        this.syncUrl();
    };

    InterventionsPage.prototype.rowHtml = function (row) {
        var selected = row.id === this.selectedId;
        var technical = !!this.can.technical;
        var canDelete = !!this.can.edit && !row.converted_maintenance_id;
        var menu = '<div class="dropdown d-inline-block">'
            + '<button class="iv2-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mai multe acțiuni pentru #' + row.id + '"><i class="bi bi-three-dots-vertical"></i></button>'
            + '<ul class="dropdown-menu dropdown-menu-end iv2-menu">'
            + '<li><button class="dropdown-item" type="button" data-row-action="lucrari"><i class="bi bi-list-check me-2"></i>Componente și lucrări</button></li>'
            + (this.can.costs ? '<li><button class="dropdown-item" type="button" data-row-action="costuri"><i class="bi bi-cash-coin me-2"></i>Costuri</button></li>' : '')
            + '<li><button class="dropdown-item" type="button" data-row-action="documente"><i class="bi bi-paperclip me-2"></i>Documente</button></li>'
            + '<li><button class="dropdown-item" type="button" data-row-action="istoric"><i class="bi bi-clock-history me-2"></i>Istoric</button></li>'
            + (canDelete ? '<li><hr class="dropdown-divider"></li><li><button class="dropdown-item text-danger" type="button" data-row-action="delete"><i class="bi bi-trash me-2"></i><span data-arm-text>Șterge intervenția</span></button></li>' : '')
            + '</ul></div>';
        var schedule = fmtDate(row.data_programata) + (row.ora_programata ? ' ' + esc(row.ora_programata) : '');
        return '<tr data-id="' + row.id + '" tabindex="0" class="' + (selected ? 'is-selected' : '') + '" aria-selected="' + (selected ? 'true' : 'false') + '">'
            + '<td class="iv2-chevron"><i class="bi ' + (selected ? 'bi-chevron-down' : 'bi-chevron-right') + '" aria-hidden="true"></i></td>'
            + '<td class="iv2-id"><strong>#' + row.id + '</strong><span class="iv2-src ' + (row.sursa === 'km_service' ? 'is-km">Km Service' : 'is-manual">Manual') + '</span></td>'
            + '<td>' + fmtDate(row.data_sesizare) + '</td>'
            + '<td class="iv2-vehicle"><strong>' + esc(row.nr_inmatriculare) + '</strong><span class="iv2-cell-sub">' + esc(row.vehicul_tip_label) + '</span></td>'
            + '<td><span class="iv2-problem" title="' + esc(row.descriere) + '">' + esc(row.descriere) + '</span></td>'
            + '<td class="text-center"><span class="iv2-count" title="Componente asociate">' + row.componente_count + '</span></td>'
            + '<td>' + schedule + '<span class="iv2-cell-sub">' + esc(row.furnizor || '—') + '</span></td>'
            + '<td>' + (row.responsabil_scurt ? esc(row.responsabil_scurt) : '<span class="text-muted" title="Responsabil tehnic nealocat">-</span>') + '</td>'
            + '<td><span class="iv2-badge pr-' + esc(row.prioritate) + '">' + esc(row.prioritate_label) + '</span></td>'
            + '<td><span class="iv2-badge st-' + esc(row.status_key) + '"' + (row.status_legacy ? ' title="Status vechi: ' + esc(row.status_legacy) + '"' : '') + '>' + esc(row.status_label) + '</span></td>'
            + '<td><div class="iv2-actions">'
            + '<button class="iv2-icon-btn is-primary" type="button" data-row-action="view" aria-label="Vezi intervenția #' + row.id + '"><i class="bi bi-eye"></i></button>'
            + (this.can.edit && row.status_key !== 'finalizata' ? '<button class="iv2-icon-btn" type="button" data-row-action="edit" aria-label="Editează intervenția #' + row.id + '"><i class="bi bi-pencil"></i></button>' : '')
            + (technical || canDelete ? menu : '')
            + '</div></td></tr>';
    };

    InterventionsPage.prototype.renderPager = function () {
        var pages = Math.max(1, Math.ceil(Number(this.list.total || 0) / this.perPage));
        var current = this.page;
        var html = '<button type="button" data-page="' + (current - 1) + '"' + (current <= 1 ? ' disabled' : '') + ' aria-label="Pagina anterioară"><i class="bi bi-chevron-left"></i></button>';
        var start = Math.max(1, current - 2);
        var end = Math.min(pages, start + 4);
        start = Math.max(1, end - 4);
        for (var page = start; page <= end; page++) {
            html += '<button type="button" data-page="' + page + '" class="' + (page === current ? 'is-current' : '') + '"' + (page === current ? ' aria-current="page"' : '') + '>' + page + '</button>';
        }
        html += '<button type="button" data-page="' + (current + 1) + '"' + (current >= pages ? ' disabled' : '') + ' aria-label="Pagina următoare"><i class="bi bi-chevron-right"></i></button>';
        this.root.querySelector('[data-iv2-pager]').innerHTML = html;
    };

    InterventionsPage.prototype.editInForm = function (id) {
        var go = function () {
            var url = new URL(window.location.href);
            url.searchParams.delete('intervention_id');
            url.searchParams.set('edit_intervention_id', id);
            window.location.href = url.toString();
        };
        this.guardUnsaved(go);
    };

    InterventionsPage.prototype.deleteIntervention = function (id) {
        var self = this;
        var form = document.createElement('form');
        form.method = 'post';
        form.action = this.api.url('delete_intervention');
        form.innerHTML = '<input type="hidden" name="_token" value="' + esc(this.boot.token) + '"><input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        self.dirty = {};
        form.submit();
    };

    // ------------------------------------------------------------------ panoul lateral

    InterventionsPage.prototype.initDrawer = function () {
        var self = this;
        var drawer = this.drawer;
        var saved = Number(storageGet(STORAGE_WIDTH) || 0);
        this.setWidth(saved > 0 ? saved : 620, false);

        drawer.querySelector('[data-iv2-close]').addEventListener('click', function () { self.close(); });
        drawer.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !document.querySelector('.modal.show')) {
                event.preventDefault();
                self.close();
            }
        });
        drawer.querySelectorAll('[data-iv2-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                self.showTab(tab.dataset.iv2Tab);
                drawer.querySelector('[data-iv2-d-body]').scrollTop = 0;
            });
            tab.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
                    return;
                }
                var tabs = Array.prototype.slice.call(drawer.querySelectorAll('[data-iv2-tab]')).filter(function (t) { return !t.hidden; });
                var index = tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : -1);
                var next = tabs[(index + tabs.length) % tabs.length];
                next.focus();
                self.showTab(next.dataset.iv2Tab);
            });
        });

        // Redimensionare din marginea stanga (mouse / atingere / tastatura).
        var resizer = drawer.querySelector('[data-iv2-resizer]');
        resizer.addEventListener('pointerdown', function (event) {
            event.preventDefault();
            resizer.setPointerCapture(event.pointerId);
            drawer.classList.add('is-resizing');
            var move = function (moveEvent) {
                self.setWidth(window.innerWidth - moveEvent.clientX, false);
            };
            var up = function () {
                drawer.classList.remove('is-resizing');
                resizer.removeEventListener('pointermove', move);
                resizer.removeEventListener('pointerup', up);
                resizer.removeEventListener('pointercancel', up);
                storageSet(STORAGE_WIDTH, String(self.width));
            };
            resizer.addEventListener('pointermove', move);
            resizer.addEventListener('pointerup', up);
            resizer.addEventListener('pointercancel', up);
        });
        resizer.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                self.setWidth(self.width + (event.key === 'ArrowLeft' ? 40 : -40), true);
            }
        });
        window.addEventListener('resize', debounce(function () { self.setWidth(self.width, false); self.syncTop(); }, 100));
        window.addEventListener('scroll', debounce(function () { self.syncTop(); }, 30), { passive: true });
        window.addEventListener('beforeunload', function (event) {
            if (Object.keys(self.dirty).length > 0) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        drawer.querySelector('[data-iv2-d-body]').addEventListener('click', function (event) { self.onBodyClick(event); });
        drawer.querySelector('[data-iv2-d-body]').addEventListener('input', function (event) { self.onBodyInput(event); });
        drawer.querySelector('[data-iv2-d-body]').addEventListener('change', function (event) { self.onBodyInput(event); });
        drawer.querySelector('[data-iv2-d-body]').addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (event.target.matches('[data-doc-upload]')) {
                self.uploadDocument(event.target);
            }
        }, true);
        drawer.querySelector('[data-iv2-d-foot]').addEventListener('click', function (event) { self.onFootClick(event); });
        drawer.querySelector('[data-iv2-d-menu]').addEventListener('click', function (event) { self.onMenuClick(event); });

        this.initItemModal();
        this.initPickerModal();
        this.initFinalizeModal();
    };

    InterventionsPage.prototype.setWidth = function (width, persist) {
        var max = Math.max(MIN_WIDTH, Math.min(1100, window.innerWidth - 360));
        this.width = Math.round(Math.max(MIN_WIDTH, Math.min(max, width)));
        this.drawer.style.setProperty('--iv2-w', this.width + 'px');
        document.body.style.setProperty('--iv2-w', this.width + 'px');
        this.drawer.querySelector('[data-iv2-resizer]').setAttribute('aria-valuenow', String(this.width));
        if (persist) {
            storageSet(STORAGE_WIDTH, String(this.width));
        }
    };

    InterventionsPage.prototype.syncTop = function () {
        var topbar = document.querySelector('.topbar');
        var top = topbar ? Math.max(0, topbar.getBoundingClientRect().bottom) : 0;
        this.drawer.style.setProperty('--iv2-top', top + 'px');
    };

    InterventionsPage.prototype.guardUnsaved = function (proceed) {
        if (Object.keys(this.dirty).length === 0) {
            proceed();
            return;
        }
        var self = this;
        var foot = this.drawer.querySelector('[data-iv2-d-foot]');
        foot.innerHTML = '<div class="iv2-note w-100 d-flex flex-wrap align-items-center gap-2">'
            + '<span class="me-auto"><i class="bi bi-exclamation-triangle me-1"></i>Ai modificări nesalvate în intervenția #' + this.selectedId + '.</span>'
            + '<button class="btn btn-sm btn-primary" type="button" data-guard="save">Salvează</button>'
            + '<button class="btn btn-sm btn-outline-danger" type="button" data-guard="discard">Renunță la modificări</button>'
            + '<button class="btn btn-sm btn-outline-secondary" type="button" data-guard="stay">Continuă editarea</button></div>';
        this.pendingProceed = proceed;
        foot.querySelector('[data-guard="save"]').focus();
        if (!this.drawer.classList.contains('is-open')) {
            this.drawer.classList.add('is-open');
        }
    };

    InterventionsPage.prototype.open = function (id, tab) {
        var self = this;
        if (id !== this.selectedId) {
            this.guardUnsaved(function () { self.dirty = {}; self.editing = {}; self.doOpen(id, tab); });
            return;
        }
        this.doOpen(id, tab);
    };

    InterventionsPage.prototype.doOpen = function (id, tab) {
        var self = this;
        this.lastFocus = document.activeElement;
        this.selectedId = id;
        this.tab = tab || 'sumar';
        this.syncTop();
        this.drawer.classList.add('is-open');
        this.drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('iv2-panel-open');
        this.root.querySelectorAll('tr[data-id]').forEach(function (row) {
            var isSelected = Number(row.dataset.id) === id;
            row.classList.toggle('is-selected', isSelected);
            row.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            var icon = row.querySelector('.iv2-chevron i');
            if (icon) {
                icon.className = 'bi ' + (isSelected ? 'bi-chevron-down' : 'bi-chevron-right');
            }
        });
        this.syncUrl();
        if (!this.detail || this.detail.intervention.id !== id) {
            this.drawer.querySelector('[data-iv2-d-body]').innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Se încarcă intervenția...</div>';
            this.drawer.querySelector('[data-iv2-d-foot]').innerHTML = '';
        }
        return this.api.get('intervention_detail', { id: id }).then(function (data) {
            if (self.selectedId !== id) {
                return;
            }
            self.detail = data;
            self.can = Object.assign({}, self.can, data.can || {});
            self.renderDrawer();
            self.drawer.querySelector('[data-iv2-d-body]').scrollTop = 0;
            self.drawer.focus({ preventScroll: true });
        }).catch(function (error) {
            toast(error.message, 'danger');
            if (error.status === 404) {
                self.forceClose();
                self.load();
            }
        });
    };

    InterventionsPage.prototype.close = function () {
        var self = this;
        this.guardUnsaved(function () { self.forceClose(); });
    };

    InterventionsPage.prototype.forceClose = function () {
        this.dirty = {};
        this.editing = {};
        this.selectedId = 0;
        this.detail = null;
        this.drawer.classList.remove('is-open');
        this.drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('iv2-panel-open');
        this.render();
        if (this.lastFocus && document.body.contains(this.lastFocus)) {
            this.lastFocus.focus({ preventScroll: true });
        }
    };

    InterventionsPage.prototype.refreshDetail = function (data) {
        if (data && data.intervention) {
            this.detail = data;
            this.can = Object.assign({}, this.can, data.can || {});
        }
        this.renderDrawer();
        this.load();
    };

    InterventionsPage.prototype.renderDrawer = function () {
        var d = this.detail;
        if (!d) {
            return;
        }
        var it = d.intervention;
        var drawer = this.drawer;
        drawer.querySelector('[data-iv2-d-id]').textContent = 'Intervenție #' + it.id;
        drawer.querySelector('[data-iv2-d-source]').innerHTML = '<span class="iv2-src ' + (it.sursa === 'km_service' ? 'is-km">Km Service' : 'is-manual">Manual') + '</span>';
        drawer.querySelector('[data-iv2-d-status]').innerHTML = '<span class="iv2-badge st-' + esc(it.status_key) + '"><i class="bi bi-circle-fill" style="font-size:.45rem"></i>' + esc(it.status_label) + '</span>';
        drawer.querySelector('[data-iv2-d-title]').textContent = it.nr_inmatriculare + ' – ' + this.shortProblem(it.descriere);
        drawer.querySelector('[data-iv2-d-subtitle]').innerHTML = '<span>' + esc(it.vehicul_tip_label) + '</span><span>·</span><span>' + esc(it.vehicul_model || '—') + '</span>'
            + (it.km_bord ? '<span>·</span><span title="Km bord orientativ (din Dispecer curse)">' + fmtNumber(it.km_bord, 0) + ' km</span>' : '');
        var activeItems = d.items.filter(function (item) { return item.stare_evaluare !== 'exclusa'; }).length;
        drawer.querySelector('[data-iv2-tab-count="lucrari"]').innerHTML = activeItems ? '<span class="iv2-tab-count">' + activeItems + '</span>' : '';
        drawer.querySelector('[data-iv2-tab-count="documente"]').innerHTML = d.documents.length ? '<span class="iv2-tab-count">' + d.documents.length + '</span>' : '';
        var costTab = drawer.querySelector('[data-iv2-tab="costuri"]');
        costTab.hidden = !this.can.costs;
        if (!this.can.costs && this.tab === 'costuri') {
            this.tab = 'sumar';
        }
        this.renderMenu();
        this.showTab(this.tab);
    };

    InterventionsPage.prototype.shortProblem = function (text) {
        var first = String(text || '').split(/\r?\n/)[0].trim();
        return first.length > 70 ? first.slice(0, 68) + '…' : (first || 'Intervenție');
    };

    InterventionsPage.prototype.showTab = function (tab) {
        this.tab = tab;
        var drawer = this.drawer;
        drawer.querySelectorAll('[data-iv2-tab]').forEach(function (button) {
            var active = button.dataset.iv2Tab === tab;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
        });
        var body = drawer.querySelector('[data-iv2-d-body]');
        body.id = 'iv2-panel-' + tab;
        body.setAttribute('role', 'tabpanel');
        var renderers = {
            sumar: this.renderSummary,
            lucrari: this.renderItemsTab,
            costuri: this.renderCosts,
            documente: this.renderDocuments,
            istoric: this.renderHistory
        };
        body.innerHTML = (renderers[tab] || this.renderSummary).call(this);
        this.applyDirtyToInputs();
        this.renderFoot();
    };

    InterventionsPage.prototype.isEditable = function () {
        return this.detail && this.detail.intervention.editabila && this.can.technical;
    };

    // ---- Sumar

    InterventionsPage.prototype.renderSummary = function () {
        var d = this.detail;
        var it = d.intervention;
        var editable = this.isEditable();
        var editGeneral = editable && this.editing.general;
        var editSchedule = editable && this.editing.schedule;
        var labels = this.labels;
        var coordinatorOptions = '<option value="">Nealocat</option>' + (this.boot.coordinators || []).map(function (c) {
            return '<option value="' + c.id + '"' + (Number(it.responsabil_user_id) === c.id ? ' selected' : '') + '>' + esc(c.nume) + '</option>';
        }).join('');
        var optionList = function (map, current) {
            return Object.keys(map).map(function (key) {
                return '<option value="' + esc(key) + '"' + (current === key ? ' selected' : '') + '>' + esc(map[key]) + '</option>';
            }).join('');
        };
        var responsible = it.responsabil_nume
            ? '<i class="bi bi-person-fill"></i><span>' + esc(it.responsabil_nume) + '</span>'
            : '<span class="iv2-badge unassigned"><i class="bi bi-person-dash"></i>Nealocat</span>';

        var html = '<section class="iv2-section"><div class="iv2-section-head"><h3>Informații generale</h3>'
            + (editable ? '<button class="btn btn-outline-primary" type="button" data-edit-toggle="general"><i class="bi bi-pencil me-1"></i>' + (editGeneral ? 'Gata' : 'Editează') + '</button>' : '')
            + '</div><div class="iv2-info-grid">'
            + '<div><span class="lbl">Vehicul</span><div class="val"><i class="bi bi-truck-front-fill"></i><span><b>' + esc(it.nr_inmatriculare) + '</b><small>' + esc((it.vehicul_model || '') + ' · ' + it.vehicul_tip_label) + '</small></span></div></div>'
            + '<div><span class="lbl">Data programată</span><div class="val"><i class="bi bi-calendar3"></i>' + fmtDate(it.data_programata) + (it.ora_programata ? ' ' + esc(it.ora_programata) : '') + '</div></div>'
            + '<div><span class="lbl">Tip intervenție</span><div class="val">' + (editGeneral
                ? '<select class="form-select" data-field="tip_interventie" aria-label="Tip intervenție"><option value="intretinere">Întreținere</option><option value="reparatie">Reparație</option></select>'
                : '<i class="bi bi-wrench-adjustable"></i>' + (it.tip_interventie === 'reparatie' ? 'Reparație' : 'Întreținere')) + '</div></div>'
            + '<div><span class="lbl">Status</span><div class="val"><span class="iv2-badge st-' + esc(it.status_key) + '">' + esc(it.status_label) + '</span></div></div>'
            + '<div><span class="lbl">Responsabil tehnic</span><div class="val">' + (editGeneral
                ? '<select class="form-select" data-field="responsabil_user_id" aria-label="Responsabil tehnic">' + coordinatorOptions + '</select>'
                : responsible) + '</div></div>'
            + '<div><span class="lbl">Furnizor / Service</span><div class="val"><i class="bi bi-building"></i>' + esc(it.furnizor || '—') + '</div></div>'
            + '<div><span class="lbl">Prioritate</span><div class="val">' + (editGeneral
                ? '<select class="form-select" data-field="prioritate" aria-label="Prioritate">' + optionList(labels.priority || {}, it.prioritate) + '</select>'
                : '<i class="bi bi-flag"></i>' + esc((labels.priority || {})[it.prioritate] || it.prioritate)) + '</div></div>'
            + '<div><span class="lbl">Sursă</span><div class="val"><i class="bi bi-link-45deg"></i>' + (it.sursa === 'km_service' ? 'Km Service' : 'Manual') + '</div></div>'
            + '</div></section>';

        // Sesizarea initiala: instantaneu pastrat, separat de diagnostic.
        var reportTitle = it.sursa === 'km_service' ? 'Sesizare inițială (din Km Service)' : 'Sesizare inițială';
        var report = it.sesizare_initiala;
        html += '<section class="iv2-section"><div class="iv2-section-head"><h3>' + reportTitle + '</h3></div>';
        if (report !== null && report !== undefined) {
            html += '<div class="iv2-report"><div><strong>' + esc(report) + '</strong>'
                + '<small>Înregistrată la: ' + fmtDateTime(it.sesizare_la || it.created_at) + (it.sesizare_de_nume ? ' · de către: ' + esc(it.sesizare_de_nume) : '') + '</small></div>'
                + (d.km_service ? '<button class="btn btn-outline-primary" type="button" data-toggle-origin aria-expanded="false"><i class="bi bi-file-earmark-text me-1"></i>Vezi detalii originale</button>' : '')
                + '</div>';
        } else {
            html += '<div class="iv2-report"><div><strong>' + esc(it.descriere) + '</strong><small>Înregistrare anterioară Intervenții V2: sesizarea inițială nu a fost păstrată separat; se afișează descrierea curentă. Creată la ' + fmtDateTime(it.created_at) + (it.creat_de_nume ? ' de ' + esc(it.creat_de_nume) : '') + '.</small></div>'
                + (d.km_service ? '<button class="btn btn-outline-primary" type="button" data-toggle-origin aria-expanded="false"><i class="bi bi-file-earmark-text me-1"></i>Vezi detalii originale</button>' : '')
                + '</div>';
        }
        if (report && report.trim() !== String(it.descriere || '').trim()) {
            html += '<div class="iv2-report-current"><b>Problemă raportată (actualizată):</b> ' + esc(it.descriere) + '</div>';
        }
        if (d.km_service) {
            var km = d.km_service;
            html += '<div class="iv2-origin" data-origin hidden><dl>'
                + '<dt>Intrare Km service</dt><dd>#' + km.id + ' · creată ' + fmtDateTime(km.created_at) + (km.creat_de_nume ? ' de ' + esc(km.creat_de_nume) : '') + '</dd>'
                + '<dt>Plecare la service</dt><dd>' + fmtDate(km.data_plecare) + (km.ora_plecare ? ' ' + esc(km.ora_plecare) : '') + '</dd>'
                + '<dt>Întoarcere</dt><dd>' + (km.data_intoarcere ? fmtDate(km.data_intoarcere) + (km.ora_intoarcere ? ' ' + esc(km.ora_intoarcere) : '') : '<span class="text-warning fw-semibold">Încă în service</span>') + '</dd>'
                + '<dt>Km service</dt><dd>' + fmtNumber(km.km, 0) + ' km</dd>'
                + '<dt>Service</dt><dd>' + esc(km.service_nume || '—') + '</dd>'
                + '<dt>Șofer</dt><dd>' + esc(km.sofer_nume || '—') + '</dd>'
                + '<dt>Motiv</dt><dd>' + esc(km.motiv || '—') + '</dd>'
                + '<dt>Observații</dt><dd>' + esc(km.observatii || '—') + '</dd>'
                + '</dl></div>';
        }
        if (it.interventie_origine_id) {
            html += '<div class="iv2-note is-info mt-2">Continuare a intervenției <a href="#" data-open-intervention="' + it.interventie_origine_id + '">#' + it.interventie_origine_id + '</a> (lucrări amânate).</div>';
        }
        html += '</section>';

        // Diagnostic tehnic al coordonatorului.
        html += '<section class="iv2-section iv2-diagnosis"><div class="iv2-section-head"><h3>Diagnostic tehnic</h3></div>'
            + '<label class="visually-hidden" for="iv2-diag">Diagnostic tehnic</label>'
            + '<textarea class="form-control" id="iv2-diag" data-field="diagnostic_tehnic" placeholder="Adaugă constatări, diagnostic, recomandări..."' + (editable ? '' : ' readonly') + '>' + esc(it.diagnostic_tehnic) + '</textarea>'
            + '<div class="iv2-diagnosis-foot"><span>' + (it.diagnostic_la ? 'Ultima modificare: ' + fmtDateTime(it.diagnostic_la) + (it.diagnostic_de_nume ? ' · ' + esc(it.diagnostic_de_nume) : '') : 'Diagnosticul coordonatorului se păstrează separat de sesizarea dispecerului.') + '</span>'
            + (editable ? '<button class="btn btn-secondary" type="button" data-save-diagnostic disabled>Salvează diagnostic</button>' : '')
            + '</div></section>';

        html += '<section class="iv2-section"><div class="iv2-section-head"><h3>Componente și lucrări</h3>' + this.itemButtons() + '</div>' + this.itemsTable(true) + '</section>';

        // Programare si disponibilitate.
        var availability = it.disponibilitate_vehicul;
        var availabilityLabel = availability ? (labels.availability || {})[availability] : 'Nespecificat';
        html += '<section class="iv2-section"><div class="iv2-section-head"><h3>Programare și disponibilitate</h3>'
            + (editable ? '<button class="btn btn-outline-primary" type="button" data-edit-toggle="schedule"><i class="bi bi-pencil me-1"></i>' + (editSchedule ? 'Gata' : 'Editează') + '</button>' : '')
            + '</div><div class="iv2-info-grid">'
            + '<div><span class="lbl">Dată programată</span><div class="val">' + (editSchedule
                ? '<input class="form-control" type="date" data-field="data_programata" aria-label="Dată programată">'
                : '<i class="bi bi-calendar3"></i>' + fmtDate(it.data_programata)) + '</div></div>'
            + '<div><span class="lbl">Ora (opțional)</span><div class="val">' + (editSchedule
                ? '<input class="form-control" type="time" data-field="ora_programata" aria-label="Ora programată">'
                : '<i class="bi bi-clock"></i>' + esc(it.ora_programata || '—')) + '</div></div>'
            + '<div><span class="lbl">Furnizor / Service</span><div class="val">' + (editSchedule
                ? '<input class="form-control" type="text" maxlength="190" data-field="furnizor" aria-label="Furnizor / Service">'
                : '<i class="bi bi-building"></i>' + esc(it.furnizor || '—')) + '</div></div>'
            + '<div><span class="lbl">Vehicul disponibil</span><div class="val">' + (editSchedule
                ? '<select class="form-select" data-field="disponibilitate_vehicul" aria-label="Vehicul disponibil"><option value="">Nespecificat</option>' + optionList(labels.availability || {}, availability || '') + '</select>'
                : '<span class="iv2-avail is-' + esc(availability || 'na') + '">' + esc(availabilityLabel) + '</span>') + '</div></div>'
            + '</div><div class="form-text mt-2">Disponibilitatea vehiculului se stabilește explicit de coordonator; nu se schimbă automat la finalizare.</div></section>';

        if (d.follow_ups && d.follow_ups.length) {
            html += '<div class="iv2-note is-info">Lucrări amânate mutate în: ' + d.follow_ups.map(function (f) {
                return '<a href="#" data-open-intervention="' + f.id + '">#' + f.id + '</a> (' + esc(f.status_label) + ', ' + fmtDate(f.data_programata) + ')';
            }).join(', ') + '</div>';
        }
        return html;
    };

    InterventionsPage.prototype.itemButtons = function () {
        if (!this.isEditable()) {
            return '';
        }
        return '<div class="d-flex flex-wrap gap-2">'
            + '<button class="btn btn-outline-primary" type="button" data-item-add><i class="bi bi-plus-lg me-1"></i>Adaugă componentă</button>'
            + '<button class="btn btn-outline-secondary" type="button" data-item-pick><i class="bi bi-ui-checks-grid me-1"></i>Selectează din registru</button></div>';
    };

    InterventionsPage.prototype.itemsTable = function (compact) {
        var d = this.detail;
        var labels = this.labels;
        var editable = this.isEditable();
        if (!d.items.length) {
            return '<div class="iv2-empty">Nicio componentă asociată. O cerere de diagnoză poate rămâne fără piesă până la constatarea coordonatorului.</div>';
        }
        var rows = d.items.map(function (item, index) {
            var result = item.rezultat
                ? '<span class="iv2-badge rz-' + esc(item.rezultat) + '">' + esc((labels.result || {})[item.rezultat]) + '</span>'
                : (item.progres !== 'neinceputa' ? '<small>' + esc((labels.progress || {})[item.progres]) + '</small>' : '-');
            if (item.usage_id) {
                result += '<small class="d-block text-success"><i class="bi bi-check2"></i> în istoricul componentei</small>';
            }
            var actions = editable
                ? '<div class="iv2-actions"><button class="iv2-icon-btn" type="button" data-item-edit="' + item.id + '" aria-label="Editează ' + esc(item.nume) + '"><i class="bi bi-pencil"></i></button>'
                    + (item.usage_id ? '' : '<button class="iv2-icon-btn is-danger" type="button" data-item-delete="' + item.id + '" title="Elimină" aria-label="Elimină ' + esc(item.nume) + '"><i class="bi bi-trash"></i></button>') + '</div>'
                : '';
            var component = '<strong>' + esc(item.nume) + (item.componenta_eliminata ? ' <small class="text-danger">(eliminată din registru)</small>' : '') + '</strong>'
                + '<small>' + esc([item.categorie ? (item.ramura_label ? item.ramura_label + ' › ' : '') + item.categorie : 'Lucrare fără componentă din registru', item.cod].filter(Boolean).join(' · ')) + '</small>'
                + (item.vehicul_componenta ? '<small class="d-block text-primary"><i class="bi bi-truck-flatbed"></i> pe ' + esc(item.vehicul_componenta) + ' (' + esc(item.vehicul_componenta_tip) + ')</small>' : '')
                + (item.origine === 'km_service' ? '<small class="d-block">Cerută din Km Service</small>' : '');
            return '<tr>'
                + '<td>' + (index + 1) + '</td>'
                + '<td>' + component + '</td>'
                + '<td>' + esc(item.pozitie || '-') + '</td>'
                + '<td><span class="iv2-badge ev-' + esc(item.stare_evaluare) + '">' + esc((labels.assessment || {})[item.stare_evaluare]) + '</span></td>'
                + '<td><span class="iv2-badge op">' + esc((labels.operation || {})[item.operatiune]) + '</span></td>'
                + (compact ? '' : '<td>' + esc((labels.progress || {})[item.progres]) + '</td>')
                + '<td><span title="' + esc(item.diagnostic) + '">' + esc(item.diagnostic ? (item.diagnostic.length > 60 ? item.diagnostic.slice(0, 58) + '…' : item.diagnostic) : '-') + '</span></td>'
                + '<td>' + result + '</td>'
                + '<td>' + actions + '</td></tr>';
        }).join('');
        return '<div class="iv2-items-wrap"><table class="iv2-items"><thead><tr><th>#</th><th>Componentă</th><th>Localizare</th><th>Stare</th><th>Lucrare solicitată</th>'
            + (compact ? '' : '<th>Progres</th>') + '<th>Diagnostic</th><th>Rezultat</th><th class="text-end">Acțiuni</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
    };

    InterventionsPage.prototype.renderItemsTab = function () {
        var d = this.detail;
        var excluded = d.items.filter(function (item) { return item.stare_evaluare === 'exclusa'; }).length;
        var replaced = d.items.filter(function (item) { return item.usage_id; }).length;
        return '<section class="iv2-section"><div class="iv2-section-head"><h3>Componente și lucrări</h3>' + this.itemButtons() + '</div>'
            + this.itemsTable(false)
            + '<div class="form-text mt-2">' + d.items.length + ' lucrări' + (excluded ? ' · ' + excluded + ' excluse' : '') + (replaced ? ' · ' + replaced + ' înlocuiri înregistrate' : '')
            + '. Starea evaluării (suspectată / confirmată / exclusă) e separată de progresul și rezultatul lucrării.</div></section>';
    };

    // ---- Costuri

    InterventionsPage.prototype.renderCosts = function () {
        var d = this.detail;
        if (!this.can.costs || !d.costs) {
            return '<div class="iv2-empty">Nu ai dreptul să vezi costurile intervențiilor.</div>';
        }
        var c = d.costs;
        var editable = this.isEditable();
        var input = function (field, value) {
            return '<input class="form-control" type="number" min="0" step="0.01" data-field="' + field + '" value="' + Number(value || 0).toFixed(2) + '" aria-label="' + field + '">';
        };
        var html = '<section class="iv2-section"><div class="iv2-section-head"><h3>Costuri</h3></div><div class="iv2-costs">'
            + '<div class="iv2-cost"><span>Cost estimat</span>' + (editable ? input('cost_estimat', c.estimat) : '<strong>' + fmtMoney(c.estimat) + '</strong>') + '</div>'
            + '<div class="iv2-cost"><span>Piese (real)</span><strong>' + fmtMoney(c.piese) + '</strong></div>'
            + '<div class="iv2-cost"><span>Manoperă (real)</span><strong>' + fmtMoney(c.manopera) + '</strong></div>'
            + '<div class="iv2-cost"><span>Costuri suplimentare</span>' + (editable && c.sursa === 'lucrari' ? input('cost_suplimentar', c.suplimentar) : '<strong>' + fmtMoney(c.suplimentar) + '</strong>') + '</div>'
            + '<div class="iv2-cost is-total"><span>Total real</span><strong>' + fmtMoney(c.total) + '</strong></div>'
            + '</div>';
        if (c.sursa === 'inregistrare') {
            html += '<div class="iv2-note is-info mt-3">Intervenție convertită înainte de V2: costul real este cel din înregistrarea finală #' + c.inregistrare.id + ' (afișat o singură dată, nu adunat peste lucrări).</div>';
        } else if (c.inregistrare) {
            html += '<div class="iv2-note is-info mt-3">Costul real a fost trecut în înregistrarea finală #' + c.inregistrare.id + ' (' + (c.inregistrare.tip === 'reparatie' ? 'Reparații' : 'Întreținere') + ', ' + fmtMoney(c.inregistrare.cost) + '); nu se dublează în rapoarte.</div>';
        }
        html += '</section>';

        var items = d.items.filter(function (item) { return item.stare_evaluare !== 'exclusa'; });
        html += '<section class="iv2-section"><div class="iv2-section-head"><h3>Pe lucrări</h3></div>';
        if (!items.length) {
            html += '<div class="iv2-empty">Nicio lucrare cu cost.</div>';
        } else {
            html += '<div class="iv2-items-wrap"><table class="iv2-items"><thead><tr><th>Componentă</th><th>Lucrare</th><th class="text-end">Piese</th><th class="text-end">Manoperă</th><th class="text-end">Total</th></tr></thead><tbody>'
                + items.map(function (item) {
                    return '<tr><td><strong>' + esc(item.nume) + '</strong><small>' + esc(item.pozitie) + '</small></td><td>' + esc((this.labels.operation || {})[item.operatiune]) + '</td>'
                        + '<td class="text-end">' + fmtMoney(item.cost_piese) + '</td><td class="text-end">' + fmtMoney(item.cost_manopera) + '</td><td class="text-end"><b>' + fmtMoney(item.cost_piese + item.cost_manopera) + '</b></td></tr>';
                }, this).join('') + '</tbody></table></div>';
        }
        html += '<div class="form-text mt-2">Costurile de piese și manoperă se completează pe fiecare lucrare (creion) sau la finalizare.</div></section>';
        return html;
    };

    // ---- Documente

    InterventionsPage.prototype.renderDocuments = function () {
        var d = this.detail;
        var self = this;
        var canUpload = this.can.technical;
        var html = '<section class="iv2-section"><div class="iv2-section-head"><h3>Documente</h3></div>';
        if (!d.documents.length) {
            html += '<div class="iv2-empty">Nu există documente atașate. Poți încărca devize, facturi, comenzi de lucru, fotografii sau rapoarte de reparație.</div>';
        } else {
            html += '<ul class="iv2-docs list-unstyled mb-0">' + d.documents.map(function (doc) {
                var url = self.api.url('intervention_document', { id: doc.id, sursa: doc.sursa });
                var isImage = /\.(jpe?g|png|webp)$/i.test(doc.nume);
                return '<li><i class="bi ' + (isImage ? 'bi-image' : (/\.pdf$/i.test(doc.nume) ? 'bi-file-earmark-pdf' : 'bi-file-earmark')) + '" aria-hidden="true"></i>'
                    + '<div class="doc-meta"><a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(doc.nume) + '</a>'
                    + '<small>' + esc(doc.tip_label) + ' · ' + fmtDateTime(doc.created_at) + (doc.incarcat_de ? ' · ' + esc(doc.incarcat_de) : '') + (doc.marime ? ' · ' + fmtNumber(doc.marime / 1024, 0) + ' KB' : '') + '</small></div>'
                    + '<a class="iv2-icon-btn" href="' + esc(self.api.url('intervention_document', { id: doc.id, sursa: doc.sursa, download: 1 })) + '" aria-label="Descarcă ' + esc(doc.nume) + '"><i class="bi bi-download"></i></a>'
                    + (canUpload && doc.sursa === 'interventie' ? '<button class="iv2-icon-btn is-danger" type="button" data-doc-delete="' + doc.id + '" aria-label="Șterge ' + esc(doc.nume) + '"><i class="bi bi-trash"></i></button>' : '')
                    + '</li>';
            }).join('') + '</ul>';
        }
        html += '</section>';
        if (canUpload) {
            var types = this.labels.document || {};
            html += '<section class="iv2-section"><div class="iv2-section-head"><h3>Încarcă document</h3></div>'
                + '<form class="iv2-upload" data-doc-upload><select class="form-select" name="tip" aria-label="Tip document">'
                + Object.keys(types).map(function (key) { return '<option value="' + esc(key) + '">' + esc(types[key]) + '</option>'; }).join('')
                + '</select><input class="form-control" type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx" aria-label="Fișier">'
                + '<button class="btn btn-primary" type="submit"><i class="bi bi-upload me-1"></i>Încarcă</button></form>'
                + '<div class="form-text mt-2">PDF, imagini, Word sau Excel, maximum 8 MB. Fișierele se descarcă doar prin aplicație, cu drept de acces.</div></section>';
        }
        return html;
    };

    // ---- Istoric

    InterventionsPage.prototype.renderHistory = function () {
        var d = this.detail;
        var it = d.intervention;
        var html = '<section class="iv2-section"><div class="iv2-section-head"><h3>Istoric</h3></div>';
        var hasCreation = d.history.some(function (event) { return event.tip === 'creare'; });
        if (!hasCreation) {
            html += '<div class="iv2-note is-info mb-3">Înregistrare creată la ' + fmtDateTime(it.created_at) + (it.creat_de_nume ? ' de ' + esc(it.creat_de_nume) : '')
                + ', înainte de istoricul detaliat al intervențiilor. Evenimentele anterioare nu sunt reconstituite.</div>';
        }
        if (!d.history.length) {
            html += '<div class="iv2-empty">Nicio modificare înregistrată încă.</div>';
        } else {
            html += '<ol class="iv2-timeline">' + d.history.slice().reverse().map(function (event) {
                return '<li class="t-' + esc(event.tip) + '"><strong>' + esc(event.descriere) + '</strong><small>' + fmtDateTime(event.created_at) + ' · ' + esc(event.user_nume || 'Sistem') + '</small></li>';
            }).join('') + '</ol>';
        }
        return html + '</section>';
    };

    // ---- Subsol si meniu

    InterventionsPage.prototype.renderFoot = function () {
        var foot = this.drawer.querySelector('[data-iv2-d-foot]');
        var d = this.detail;
        if (!d) {
            foot.innerHTML = '';
            return;
        }
        var it = d.intervention;
        var dirty = Object.keys(this.dirty).length > 0;
        var html = '';
        if (this.can.technical && it.editabila) {
            html += '<button class="btn btn-outline-danger" type="button" data-foot="cancel"><i class="bi bi-x-octagon me-1"></i><span data-arm-text>Anulează intervenția</span></button>';
        }
        html += '<div class="iv2-foot-right">';
        if (this.can.technical && it.editabila) {
            html += '<button class="btn ' + (dirty ? 'btn-outline-primary' : 'btn-secondary') + '" type="button" data-foot="save"' + (dirty ? '' : ' disabled') + '><i class="bi bi-floppy me-1"></i>Salvează modificările</button>';
            var next = {
                de_verificat: ['programata', 'bi-calendar-check', 'Marchează ca programată'],
                programata: ['in_service', 'bi-tools', 'Marchează în service'],
                in_service: ['finalize', 'bi-check2-circle', 'Finalizează intervenția'],
                in_asteptare: ['in_service', 'bi-play-circle', 'Reia lucrul (În service)']
            }[it.status_key];
            if (next) {
                html += '<button class="btn ' + (next[0] === 'finalize' ? 'btn-success' : 'btn-primary') + '" type="button" data-foot="' + (next[0] === 'finalize' ? 'finalize' : 'status') + '" data-status="' + next[0] + '"><i class="bi ' + next[1] + ' me-1"></i>' + next[2] + '</button>';
            }
        } else if (this.can.technical && it.status_key === 'anulata') {
            html += '<button class="btn btn-outline-primary" type="button" data-foot="status" data-status="de_verificat"><i class="bi bi-arrow-counterclockwise me-1"></i>Redeschide (De verificat)</button>';
        } else if (it.status_key === 'finalizata' && it.converted_maintenance_id) {
            html += '<span class="text-muted small">Finalizată la ' + fmtDateTime(it.finalizat_la) + (it.finalizat_de_nume ? ' de ' + esc(it.finalizat_de_nume) : '') + '</span>'
                + '<a class="btn btn-outline-primary" href="' + esc(this.recordUrl()) + '"><i class="bi bi-box-arrow-up-right me-1"></i>Înregistrarea finală #' + it.converted_maintenance_id + '</a>';
        } else if (!this.can.technical) {
            html += '<span class="text-muted small"><i class="bi bi-lock me-1"></i>Doar vizualizare (fără drept de coordonare tehnică)</span>';
        }
        html += '</div>';
        foot.innerHTML = html;
        this.pendingProceed = null;
    };

    InterventionsPage.prototype.recordUrl = function () {
        var it = this.detail.intervention;
        var type = this.detail.costs && this.detail.costs.inregistrare ? this.detail.costs.inregistrare.tip : it.tip_interventie;
        return this.api.url(type === 'reparatie' ? 'repairs' : 'maintenance', { view_id: it.converted_maintenance_id });
    };

    InterventionsPage.prototype.renderMenu = function () {
        var it = this.detail.intervention;
        var menu = this.drawer.querySelector('[data-iv2-d-menu]');
        var items = [];
        if (this.can.edit && it.status_key !== 'finalizata') {
            items.push('<li><button class="dropdown-item" type="button" data-menu="edit"><i class="bi bi-pencil-square me-2"></i>Editează în formular</button></li>');
        }
        if (this.can.technical && (it.status_key === 'programata' || it.status_key === 'in_service' || it.status_key === 'de_verificat')) {
            items.push('<li><button class="dropdown-item" type="button" data-menu="status" data-status="in_asteptare"><i class="bi bi-pause-circle me-2"></i>Pune în așteptare</button></li>');
        }
        if (this.can.technical && it.status_key === 'in_service') {
            items.push('<li><button class="dropdown-item" type="button" data-menu="status" data-status="programata"><i class="bi bi-calendar me-2"></i>Înapoi la Programată</button></li>');
        }
        if (this.can.technical && it.editabila && (it.status_key === 'programata' || it.status_key === 'in_asteptare' || it.status_key === 'de_verificat')) {
            items.push('<li><button class="dropdown-item" type="button" data-menu="finalize"><i class="bi bi-check2-circle me-2"></i>Finalizează intervenția</button></li>');
        }
        if (it.converted_maintenance_id) {
            items.push('<li><a class="dropdown-item" href="' + esc(this.recordUrl()) + '"><i class="bi bi-box-arrow-up-right me-2"></i>Înregistrarea finală #' + it.converted_maintenance_id + '</a></li>');
        }
        items.push('<li><button class="dropdown-item" type="button" data-menu="copy"><i class="bi bi-link-45deg me-2"></i>Copiază linkul intervenției</button></li>');
        if (this.can.edit && !it.converted_maintenance_id) {
            items.push('<li><hr class="dropdown-divider"></li><li><button class="dropdown-item text-danger" type="button" data-menu="delete"><i class="bi bi-trash me-2"></i><span data-arm-text>Șterge intervenția</span></button></li>');
        }
        menu.innerHTML = items.join('');
    };

    InterventionsPage.prototype.onMenuClick = function (event) {
        var button = event.target.closest('[data-menu]');
        if (!button) {
            return;
        }
        var id = this.selectedId;
        var action = button.dataset.menu;
        if (action === 'edit') {
            this.editInForm(id);
        } else if (action === 'status') {
            this.save({ status: button.dataset.status });
        } else if (action === 'finalize') {
            this.openFinalize();
        } else if (action === 'copy') {
            var url = new URL(window.location.href);
            url.searchParams.set('intervention_id', id);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url.toString()).then(function () { toast('Linkul a fost copiat.'); });
            }
        } else if (action === 'delete') {
            event.stopPropagation();
            if (armed(button, 'Apasă din nou pentru ștergere')) {
                this.deleteIntervention(id);
            }
        }
    };

    InterventionsPage.prototype.onFootClick = function (event) {
        var self = this;
        var guard = event.target.closest('[data-guard]');
        if (guard) {
            var proceed = this.pendingProceed;
            if (guard.dataset.guard === 'stay') {
                this.renderFoot();
            } else if (guard.dataset.guard === 'discard') {
                this.dirty = {};
                this.editing = {};
                if (proceed) {
                    proceed();
                } else {
                    this.showTab(this.tab);
                }
            } else if (guard.dataset.guard === 'save') {
                this.save({}).then(function (ok) {
                    if (ok && proceed) {
                        proceed();
                    }
                });
            }
            return;
        }
        var button = event.target.closest('[data-foot]');
        if (!button) {
            return;
        }
        var action = button.dataset.foot;
        if (action === 'save') {
            this.save({});
        } else if (action === 'status') {
            this.save({ status: button.dataset.status });
        } else if (action === 'finalize') {
            if (Object.keys(this.dirty).length > 0) {
                this.save({}).then(function (ok) { if (ok) { self.openFinalize(); } });
            } else {
                this.openFinalize();
            }
        } else if (action === 'cancel') {
            if (armed(button, 'Confirmă anularea')) {
                this.save({ status: 'anulata' });
            }
        }
    };

    InterventionsPage.prototype.save = function (extra) {
        var self = this;
        var payload = Object.assign({ id: this.selectedId }, this.dirty, extra || {});
        if (Object.keys(payload).length <= 1) {
            return Promise.resolve(true);
        }
        var buttons = this.drawer.querySelectorAll('[data-iv2-d-foot] button');
        buttons.forEach(function (button) { button.disabled = true; });
        return this.api.post('intervention_update', payload).then(function (data) {
            self.dirty = {};
            self.editing = {};
            var changed = (data.result && data.result.changed) || [];
            toast(changed.length ? 'Modificările au fost salvate.' : 'Nu a fost nimic de salvat.');
            self.refreshDetail(data);
            return true;
        }).catch(function (error) {
            toast(error.message, 'danger');
            self.renderFoot();
            return false;
        });
    };

    InterventionsPage.prototype.onBodyInput = function (event) {
        var field = event.target.closest('[data-field]');
        if (!field || !this.detail) {
            return;
        }
        var name = field.dataset.field;
        var it = this.detail.intervention;
        var original = name === 'cost_estimat' || name === 'cost_suplimentar'
            ? Number((this.detail.costs || {})[name === 'cost_estimat' ? 'estimat' : 'suplimentar'] || 0).toFixed(2)
            : String(it[name] === null || it[name] === undefined ? '' : it[name]);
        var value = field.value;
        var compare = name === 'cost_estimat' || name === 'cost_suplimentar' ? Number(value || 0).toFixed(2) : value;
        if (compare === original) {
            delete this.dirty[name];
        } else {
            this.dirty[name] = value;
        }
        var diagnosticButton = this.drawer.querySelector('[data-save-diagnostic]');
        if (diagnosticButton) {
            var hasDiagnostic = Object.prototype.hasOwnProperty.call(this.dirty, 'diagnostic_tehnic');
            diagnosticButton.disabled = !hasDiagnostic;
            diagnosticButton.className = 'btn ' + (hasDiagnostic ? 'btn-primary' : 'btn-secondary');
        }
        var save = this.drawer.querySelector('[data-foot="save"]');
        if (save) {
            var dirty = Object.keys(this.dirty).length > 0;
            save.disabled = !dirty;
            save.className = 'btn ' + (dirty ? 'btn-outline-primary' : 'btn-secondary');
        }
    };

    /** Valorile din dirty / detalii in campurile randate (dupa schimbarea tabului sau modul Editeaza). */
    InterventionsPage.prototype.applyDirtyToInputs = function () {
        var self = this;
        var it = this.detail ? this.detail.intervention : {};
        this.drawer.querySelectorAll('[data-iv2-d-body] [data-field]').forEach(function (field) {
            var name = field.dataset.field;
            if (Object.prototype.hasOwnProperty.call(self.dirty, name)) {
                field.value = self.dirty[name];
            } else if (field.tagName !== 'TEXTAREA' && name !== 'cost_estimat' && name !== 'cost_suplimentar') {
                field.value = it[name] === null || it[name] === undefined ? '' : it[name];
            }
        });
        var diagnosticButton = this.drawer.querySelector('[data-save-diagnostic]');
        if (diagnosticButton && Object.prototype.hasOwnProperty.call(this.dirty, 'diagnostic_tehnic')) {
            diagnosticButton.disabled = false;
            diagnosticButton.className = 'btn btn-primary';
        }
    };

    InterventionsPage.prototype.onBodyClick = function (event) {
        var self = this;
        var target = event.target;
        var toggle = target.closest('[data-edit-toggle]');
        if (toggle) {
            this.editing[toggle.dataset.editToggle] = !this.editing[toggle.dataset.editToggle];
            this.showTab(this.tab);
            return;
        }
        if (target.closest('[data-toggle-origin]')) {
            var origin = this.drawer.querySelector('[data-origin]');
            var button = target.closest('[data-toggle-origin]');
            if (origin) {
                origin.hidden = !origin.hidden;
                button.setAttribute('aria-expanded', origin.hidden ? 'false' : 'true');
            }
            return;
        }
        if (target.closest('[data-save-diagnostic]')) {
            this.save({});
            return;
        }
        var openLink = target.closest('[data-open-intervention]');
        if (openLink) {
            event.preventDefault();
            this.open(Number(openLink.dataset.openIntervention), 'sumar');
            return;
        }
        if (target.closest('[data-item-add]')) {
            this.openItemModal(null);
            return;
        }
        if (target.closest('[data-item-pick]')) {
            this.openPickerModal();
            return;
        }
        var edit = target.closest('[data-item-edit]');
        if (edit) {
            var item = this.detail.items.find(function (i) { return i.id === Number(edit.dataset.itemEdit); });
            this.openItemModal(item || null);
            return;
        }
        var remove = target.closest('[data-item-delete]');
        if (remove) {
            if (armed(remove, 'Apasă din nou pentru eliminare')) {
                this.api.post('intervention_item_delete', { intervention_id: this.selectedId, item_id: remove.dataset.itemDelete }).then(function (data) {
                    toast('Componenta a fost eliminată din intervenție.');
                    self.refreshDetail(data);
                }).catch(function (error) { toast(error.message, 'danger'); });
            }
            return;
        }
        var docDelete = target.closest('[data-doc-delete]');
        if (docDelete) {
            if (armed(docDelete, 'Apasă din nou pentru ștergere')) {
                this.api.post('intervention_document_delete', { intervention_id: this.selectedId, document_id: docDelete.dataset.docDelete }).then(function (data) {
                    toast('Documentul a fost șters.');
                    self.refreshDetail(data);
                }).catch(function (error) { toast(error.message, 'danger'); });
            }
        }
    };

    InterventionsPage.prototype.uploadDocument = function (form) {
        var self = this;
        var file = form.elements.document.files[0];
        if (!file) {
            toast('Alege fișierul de încărcat.', 'danger');
            return;
        }
        if (file.size > 8 * 1024 * 1024) {
            toast('Fișierul trebuie să fie mai mic de 8 MB.', 'danger');
            return;
        }
        var data = new FormData(form);
        data.append('intervention_id', this.selectedId);
        var button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        this.api.post('intervention_document_upload', data).then(function (response) {
            toast('Documentul a fost încărcat.');
            self.refreshDetail(response);
        }).catch(function (error) {
            toast(error.message, 'danger');
            button.disabled = false;
        });
    };

    // ---- Modal lucrare

    InterventionsPage.prototype.initItemModal = function () {
        var self = this;
        this.itemModal = document.getElementById('iv2ItemModal');
        this.itemModal.querySelector('[data-iv2-item-form]').addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation();
            self.submitItem(event.target);
        }, true);
        this.itemModal.addEventListener('change', function (event) {
            if (event.target.name === 'operatiune') {
                self.itemModal.querySelector('[data-replacement]').hidden = event.target.value !== 'inlocuire';
            }
            if (event.target.name === 'garantie_luni' || event.target.name === 'data_montare') {
                self.suggestWarranty(event.target.form);
            }
        });
    };

    InterventionsPage.prototype.suggestWarranty = function (form) {
        var months = Number(form.elements.garantie_luni.value || 0);
        var start = form.elements.data_montare.value || this.boot.today;
        if (months > 0 && !form.elements.garantie_pana_la.dataset.manual) {
            var date = new Date(start + 'T00:00:00');
            date.setMonth(date.getMonth() + months);
            form.elements.garantie_pana_la.value = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
        }
    };

    InterventionsPage.prototype.openItemModal = function (item) {
        var self = this;
        var labels = this.labels;
        var isNew = !item;
        var it = this.detail.intervention;
        var value = item || { stare_evaluare: 'suspectata', operatiune: 'verificare', progres: 'neinceputa', rezultat: '', cost_piese: 0, cost_manopera: 0 };
        var select = function (name, map, current, empty) {
            return '<select class="form-select" name="' + name + '" id="iv2-it-' + name + '">' + (empty ? '<option value="">' + esc(empty) + '</option>' : '')
                + Object.keys(map).map(function (key) { return '<option value="' + esc(key) + '"' + (current === key ? ' selected' : '') + '>' + esc(map[key]) + '</option>'; }).join('') + '</select>';
        };
        var field = function (name, label, html, col) {
            return '<div class="' + (col || 'col-md-6') + '"><label class="form-label" for="iv2-it-' + name + '">' + label + '</label>' + html + '</div>';
        };
        var text = function (name, current, extra) {
            return '<input class="form-control" id="iv2-it-' + name + '" name="' + name + '" value="' + esc(current === null || current === undefined ? '' : current) + '"' + (extra || '') + '>';
        };
        var html = '<input type="hidden" name="item_id" value="' + (item ? item.id : '') + '">';
        if (isNew) {
            html += '<div class="mb-3"><label class="form-label">Componentă din registru</label><div data-item-picker></div>'
                + '<div class="mt-2"><label class="form-label" for="iv2-it-denumire_libera">sau lucrare fără componentă din registru</label>'
                + '<input class="form-control" id="iv2-it-denumire_libera" name="denumire_libera" maxlength="190" placeholder="Ex: Verificare generală, Diagnoză computerizată"></div></div>';
        } else {
            html += '<div class="iv2-note is-info mb-3"><b>' + esc(item.nume) + '</b>' + (item.cod ? ' · ' + esc(item.cod) : '') + (item.categorie ? ' · ' + esc(item.categorie) : '') + '</div>';
        }
        html += '<div class="row g-3">'
            + field('pozitie', 'Localizare / poziție montaj', text('pozitie', value.pozitie, ' maxlength="80" placeholder="Ex: Axa 4 stânga"'))
            + field('stare_evaluare', 'Stare evaluare', select('stare_evaluare', labels.assessment || {}, value.stare_evaluare), 'col-md-3')
            + field('operatiune', 'Lucrare solicitată', select('operatiune', labels.operation || {}, value.operatiune), 'col-md-3')
            + field('diagnostic', 'Diagnostic', '<textarea class="form-control" id="iv2-it-diagnostic" name="diagnostic" rows="2">' + esc(value.diagnostic || '') + '</textarea>', 'col-12')
            + field('progres', 'Progres lucrare', select('progres', labels.progress || {}, value.progres), 'col-md-6')
            + field('rezultat', 'Rezultat', select('rezultat', labels.result || {}, value.rezultat, 'Nestabilit'), 'col-md-6')
            + '</div>'
            + '<fieldset class="iv2-form-section mt-3 mb-0" data-replacement' + (value.operatiune === 'inlocuire' ? '' : ' hidden') + '><legend class="fs-6 fw-bold">Înlocuire</legend><div class="row g-3">'
            + field('piesa_montata', 'Piesă montată (denumire / cod)', text('piesa_montata', value.piesa_montata, ' maxlength="190"'))
            + field('piesa_demontata', 'Piesă demontată', text('piesa_demontata', value.piesa_demontata, ' maxlength="190"'))
            + field('data_montare', 'Data montării', text('data_montare', value.data_montare, ' type="date"'), 'col-md-4')
            + field('km_montare', 'Km la montare', text('km_montare', value.km_montare, ' type="number" min="0" step="1" placeholder="' + esc(it.km_bord || '') + '"'), 'col-md-4')
            + field('garantie_luni', 'Garanție (luni)', text('garantie_luni', value.garantie_luni, ' type="number" min="0" max="600"'), 'col-md-2')
            + field('garantie_pana_la', 'Garanție până la', text('garantie_pana_la', value.garantie_pana_la, ' type="date"'), 'col-md-2')
            + '</div><div class="form-text">Înlocuirea intră în istoricul componentei (și resetează uzura) doar la finalizarea intervenției, după confirmare.</div></fieldset>';
        if (this.can.costs) {
            html += '<div class="row g-3 mt-1">'
                + field('cost_piese', 'Cost piese (lei)', text('cost_piese', Number(value.cost_piese || 0).toFixed(2), ' type="number" min="0" step="0.01"'), 'col-md-6')
                + field('cost_manopera', 'Cost manoperă (lei)', text('cost_manopera', Number(value.cost_manopera || 0).toFixed(2), ' type="number" min="0" step="0.01"'), 'col-md-6')
                + '</div>';
        }
        html += '<div class="mt-3">' + '<label class="form-label" for="iv2-it-observatii">Observații</label><textarea class="form-control" id="iv2-it-observatii" name="observatii" rows="2">' + esc(value.observatii || '') + '</textarea></div>';
        this.itemModal.querySelector('[data-iv2-item-body]').innerHTML = html;
        this.itemModal.querySelector('#iv2ItemModalTitle').textContent = isNew ? 'Adaugă componentă și lucrare' : 'Editează lucrarea';
        var warrantyDate = this.itemModal.querySelector('[name="garantie_pana_la"]');
        warrantyDate.addEventListener('input', function () { warrantyDate.dataset.manual = warrantyDate.value ? '1' : ''; });
        if (value.garantie_pana_la) {
            warrantyDate.dataset.manual = '1';
        }
        this.itemPicker = null;
        if (isNew) {
            this.itemPicker = new ComponentPicker(this.itemModal.querySelector('[data-item-picker]'), {
                catalog: this.boot.catalog,
                api: this.api,
                multiple: false,
                operations: labels.operation || {}
            });
            var vehicle = (this.boot.vehicles || {})[it.vehicle_id];
            this.itemPicker.setVehicle({ id: it.vehicle_id, plate: it.nr_inmatriculare, model: it.vehicul_model, type: vehicle ? vehicle.type : it.vehicul_tip });
        }
        var instance = modal(this.itemModal);
        if (instance) {
            instance.show();
        }
        setTimeout(function () {
            var first = self.itemModal.querySelector(isNew ? '[data-p-search]' : '[name="pozitie"]');
            if (first) {
                first.focus();
            }
        }, 250);
    };

    InterventionsPage.prototype.submitItem = function (form) {
        var self = this;
        var data = new FormData(form);
        data.append('intervention_id', this.selectedId);
        if (this.itemPicker) {
            var picked = this.itemPicker.value()[0];
            if (picked) {
                data.append('component_key', picked.key);
                data.append('ramura_principala', picked.primary);
            } else if (String(data.get('denumire_libera') || '').trim() === '') {
                toast('Alege o componentă din registru sau scrie denumirea lucrării.', 'danger');
                return;
            }
        }
        var submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        this.api.post('intervention_item_save', data).then(function (response) {
            var instance = modal(self.itemModal);
            if (instance) {
                instance.hide();
            }
            toast('Lucrarea a fost salvată.');
            self.refreshDetail(response);
        }).catch(function (error) {
            toast(error.message, 'danger');
        }).then(function () { submit.disabled = false; });
    };

    // ---- Modal selectie multipla din registru

    InterventionsPage.prototype.initPickerModal = function () {
        var self = this;
        this.pickerModal = document.getElementById('iv2PickerModal');
        this.pickerModal.querySelector('[data-iv2-picker-form]').addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var value = self.bulkPicker ? self.bulkPicker.value() : [];
            if (!value.length) {
                toast('Bifează cel puțin o componentă.', 'danger');
                return;
            }
            var submit = event.target.querySelector('[type="submit"]');
            submit.disabled = true;
            self.api.post('intervention_item_save', { intervention_id: self.selectedId, components_json: JSON.stringify(value) }).then(function (response) {
                var instance = modal(self.pickerModal);
                if (instance) {
                    instance.hide();
                }
                toast(value.length === 1 ? 'Componenta a fost adăugată.' : value.length + ' componente au fost adăugate.');
                self.refreshDetail(response);
            }).catch(function (error) {
                toast(error.message, 'danger');
            }).then(function () { submit.disabled = false; });
        }, true);
    };

    InterventionsPage.prototype.openPickerModal = function () {
        var self = this;
        var it = this.detail.intervention;
        var counter = this.pickerModal.querySelector('[data-iv2-picker-count]');
        this.bulkPicker = new ComponentPicker(this.pickerModal.querySelector('[data-iv2-picker-host]'), {
            catalog: this.boot.catalog,
            api: this.api,
            operations: this.labels.operation || {},
            onChange: function (value) {
                counter.textContent = value.length ? value.length + ' selectate — vor fi adăugate ca „Suspectată”' : '';
            }
        });
        counter.textContent = '';
        var vehicle = (this.boot.vehicles || {})[it.vehicle_id];
        this.bulkPicker.setVehicle({ id: it.vehicle_id, plate: it.nr_inmatriculare, model: it.vehicul_model, type: vehicle ? vehicle.type : it.vehicul_tip });
        var instance = modal(this.pickerModal);
        if (instance) {
            instance.show();
        }
        setTimeout(function () {
            var search = self.pickerModal.querySelector('[data-p-search]');
            if (search) {
                search.focus();
            }
        }, 250);
    };

    // ---- Finalizare

    InterventionsPage.prototype.initFinalizeModal = function () {
        var self = this;
        this.finalizeModal = document.getElementById('iv2FinalizeModal');
        this.finalizeModal.querySelector('[data-iv2-finalize-form]').addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation();
            self.submitFinalize(event.target);
        }, true);
        this.finalizeModal.addEventListener('change', function (event) {
            var block = event.target.closest('[data-final-item]');
            if (block && event.target.name === 'rezultat') {
                var replacement = block.querySelector('[data-final-replacement]');
                if (replacement) {
                    replacement.hidden = event.target.value !== 'executata';
                }
            }
            if (event.target.name === 'creeaza_continuare') {
                self.finalizeModal.querySelector('[data-follow-date]').hidden = !event.target.checked;
            }
        });
    };

    InterventionsPage.prototype.openFinalize = function () {
        var d = this.detail;
        var it = d.intervention;
        var labels = this.labels;
        var self = this;
        if (!it.responsabil_user_id) {
            toast('Alocă responsabilul tehnic înainte de finalizare (Informații generale → Editează).', 'danger');
            this.editing.general = true;
            this.showTab('sumar');
            return;
        }
        var resultOptions = function (current) {
            return '<option value="">Alege rezultatul</option>' + Object.keys(labels.result || {}).map(function (key) {
                return '<option value="' + key + '"' + (current === key ? ' selected' : '') + '>' + esc(labels.result[key]) + '</option>';
            }).join('');
        };
        var html = '<div class="row g-3 mb-3">'
            + '<div class="col-md-4"><label class="form-label" for="iv2-fin-date">Data execuției <span class="text-danger">*</span></label><input class="form-control" id="iv2-fin-date" type="date" name="data_executie" value="' + esc(this.boot.today) + '" required></div>'
            + '<div class="col-md-4"><label class="form-label" for="iv2-fin-km">Km vehicul la execuție</label><input class="form-control" id="iv2-fin-km" type="number" name="km" min="0" step="1" value="' + esc(it.km_bord || '') + '"><div class="form-text">Precompletat cu km bord orientativ; corectează cu odometrul real.</div></div>'
            + '<div class="col-md-4"><label class="form-label">Responsabil tehnic</label><div class="form-control-plaintext">' + esc(it.responsabil_nume) + '</div></div>'
            + '</div>';
        var items = d.items;
        if (!items.length) {
            html += '<div class="iv2-note is-info mb-3">Intervenția nu are lucrări pe componente. Se înregistrează doar intervenția (fără efect pe istoricul componentelor).</div>';
        }
        html += items.map(function (item) {
            if (item.stare_evaluare === 'exclusa') {
                return '<div class="iv2-final-item is-excluded"><header><strong>' + esc(item.nume) + '</strong><span class="iv2-badge ev-exclusa">Exclusă</span></header><small>Componentă exclusă la evaluare — nu cere rezultat.</small></div>';
            }
            var isReplacement = item.operatiune === 'inlocuire';
            var defaultResult = item.rezultat || (item.progres === 'terminata' ? 'executata' : '');
            var block = '<div class="iv2-final-item' + (isReplacement ? ' is-replacement' : '') + '" data-final-item="' + item.id + '">'
                + '<header><span><strong>' + esc(item.nume) + '</strong> <small class="text-muted">' + esc([item.pozitie, item.cod, item.vehicul_componenta ? 'pe ' + item.vehicul_componenta : ''].filter(Boolean).join(' · ')) + '</small></span>'
                + '<span><span class="iv2-badge op">' + esc(labels.operation[item.operatiune]) + '</span> <span class="iv2-badge ev-' + esc(item.stare_evaluare) + '">' + esc(labels.assessment[item.stare_evaluare]) + '</span></span></header>'
                + '<div class="row g-2">'
                + '<div class="col-md-4"><label class="form-label">Rezultat <span class="text-danger">*</span></label><select class="form-select" name="rezultat" required>' + resultOptions(defaultResult) + '</select></div>'
                + '<div class="col-md-8"><label class="form-label">Diagnostic / constatare</label><input class="form-control" name="diagnostic" value="' + esc(item.diagnostic) + '"></div>';
            if (self.can.costs) {
                block += '<div class="col-md-3"><label class="form-label">Cost piese</label><input class="form-control" type="number" min="0" step="0.01" name="cost_piese" value="' + Number(item.cost_piese || 0).toFixed(2) + '"></div>'
                    + '<div class="col-md-3"><label class="form-label">Cost manoperă</label><input class="form-control" type="number" min="0" step="0.01" name="cost_manopera" value="' + Number(item.cost_manopera || 0).toFixed(2) + '"></div>';
            }
            block += '<div class="col-md-' + (self.can.costs ? '6' : '12') + '"><label class="form-label">Observații</label><input class="form-control" name="observatii" value="' + esc(item.observatii) + '"></div>'
                + '</div>';
            if (isReplacement) {
                if (item.usage_id) {
                    block += '<div class="iv2-note is-info mt-2">Înlocuirea este deja în istoricul componentei; nu se dublează.</div>';
                } else if (!item.component_key) {
                    block += '<div class="iv2-note mt-2">Lucrare fără componentă din registru: înlocuirea nu poate intra în istoricul componentei.</div>';
                } else {
                    block += '<div class="mt-2" data-final-replacement' + (defaultResult === 'executata' ? '' : ' hidden') + '><div class="row g-2">'
                        + '<div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="confirma_inlocuire" value="1"><span class="form-check-label"><b>Confirm înlocuirea</b> — se înregistrează montarea în istoricul componentei și monitorizarea uzurii pornește de la această dată / km.</span></label></div>'
                        + '<div class="col-md-6"><label class="form-label">Piesă montată</label><input class="form-control" name="piesa_montata" maxlength="190" value="' + esc(item.piesa_montata) + '"></div>'
                        + '<div class="col-md-6"><label class="form-label">Piesă demontată</label><input class="form-control" name="piesa_demontata" maxlength="190" value="' + esc(item.piesa_demontata) + '"></div>'
                        + '<div class="col-md-3"><label class="form-label">Data montării</label><input class="form-control" type="date" name="data_montare" value="' + esc(item.data_montare || '') + '" placeholder="data execuției"></div>'
                        + '<div class="col-md-3"><label class="form-label">Km la montare</label><input class="form-control" type="number" min="0" name="km_montare" value="' + esc(item.km_montare || '') + '" placeholder="km execuție"></div>'
                        + '<div class="col-md-3"><label class="form-label">Garanție (luni)</label><input class="form-control" type="number" min="0" max="600" name="garantie_luni" value="' + esc(item.garantie_luni || '') + '"></div>'
                        + '<div class="col-md-3"><label class="form-label">Garanție până la</label><input class="form-control" type="date" name="garantie_pana_la" value="' + esc(item.garantie_pana_la || '') + '"></div>'
                        + '</div></div>';
                }
            } else {
                block += '<div class="form-text">' + esc(labels.operation[item.operatiune]) + ': se salvează lucrarea și costul; uzura componentei nu se resetează.</div>';
            }
            return block + '</div>';
        }).join('');
        html += '<div class="iv2-form-section mt-3 mb-0"><label class="form-check"><input class="form-check-input" type="checkbox" name="creeaza_continuare" value="1" checked><span class="form-check-label">Creează o intervenție nouă (De verificat) pentru lucrările <b>amânate</b></span></label>'
            + '<div class="mt-2" data-follow-date><label class="form-label" for="iv2-fin-follow">Data programată a continuării</label><input class="form-control" id="iv2-fin-follow" type="date" name="data_continuare" value="' + esc(this.boot.today) + '" style="max-width:220px"></div>'
            + '<div class="form-text">Lucrările amânate nu se marchează niciodată ca executate. Disponibilitatea vehiculului nu se schimbă automat.</div></div>';
        this.finalizeModal.querySelector('[data-iv2-finalize-body]').innerHTML = html;
        var instance = modal(this.finalizeModal);
        if (instance) {
            instance.show();
        }
    };

    InterventionsPage.prototype.submitFinalize = function (form) {
        var self = this;
        var outcomes = {};
        var missing = [];
        form.querySelectorAll('[data-final-item]').forEach(function (block) {
            var outcome = {};
            block.querySelectorAll('input, select, textarea').forEach(function (field) {
                if (!field.name || (field.closest('[data-final-replacement]') && field.closest('[data-final-replacement]').hidden)) {
                    return;
                }
                outcome[field.name] = field.type === 'checkbox' ? (field.checked ? 1 : 0) : field.value;
            });
            var select = block.querySelector('[name="rezultat"]');
            select.classList.toggle('is-invalid', !outcome.rezultat);
            if (!outcome.rezultat) {
                missing.push(block.querySelector('strong').textContent);
            }
            var confirm = block.querySelector('[name="confirma_inlocuire"]');
            if (confirm && outcome.rezultat === 'executata' && !confirm.checked) {
                missing.push(block.querySelector('strong').textContent + ' (confirmă înlocuirea)');
            }
            outcomes[block.dataset.finalItem] = outcome;
        });
        if (!form.elements.data_executie.value) {
            missing.push('Data execuției');
        }
        if (missing.length) {
            toast('Completează: ' + missing.join(', ') + '.', 'danger');
            return;
        }
        var submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        this.api.post('intervention_finalize', {
            id: this.selectedId,
            outcomes_json: JSON.stringify(outcomes),
            data_executie: form.elements.data_executie.value,
            km: form.elements.km.value,
            creeaza_continuare: form.elements.creeaza_continuare.checked ? 1 : '',
            data_continuare: form.elements.data_continuare.value
        }).then(function (response) {
            var instance = modal(self.finalizeModal);
            if (instance) {
                instance.hide();
            }
            var result = response.result || {};
            toast(result.already
                ? 'Intervenția era deja finalizată; nu s-a dublat nimic.'
                : 'Intervenția a fost finalizată (înregistrarea #' + result.maintenance_id + (result.montari ? ', ' + result.montari + ' înlocuiri în istoricul componentelor' : '') + (result.follow_up_id ? ', continuare #' + result.follow_up_id : '') + ').');
            self.dirty = {};
            self.refreshDetail(response);
        }).catch(function (error) {
            toast(error.message, 'danger');
        }).then(function () { submit.disabled = false; });
    };

    // ------------------------------------------------------------------ pornire

    // Refolosit de formularul Km service (Dispecer curse), acelasi registru de componente.
    window.FleetComponentPicker = ComponentPicker;

    document.addEventListener('DOMContentLoaded', function () {
        initInterventionForm();
        var root = document.querySelector('[data-iv2]');
        var boot = readJson('iv2-boot');
        if (root && boot) {
            window.fleetInterventions = new InterventionsPage(root, boot);
        }
    });
})();
