/*
 * Montaj anvelope (Stoc anvelope): selector vehicul -> axa -> pozitie.
 *  - in modalul "Muta anvelopa" (o anvelopa) inlocuieste lista lunga "Pozitie tinta";
 *  - in modalul "Monteaza anvelope" (montaj in masa): fiecare anvelopa cu vehiculul,
 *    axa si pozitia ei, salvate impreuna intr-o singura tranzactie (bulk_mount_tire_stock).
 * Sursa de adevar pentru axe/pozitii/ocupare/compatibilitate e serverul (tire_mount_layout);
 * serverul reverifica totul la salvare.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-tire-mount-config]');
    if (!root) { return; }

    var cfg = {
        vehiclesUrl: root.dataset.vehiclesUrl,
        layoutUrl: root.dataset.layoutUrl,
        saveUrl: root.dataset.saveUrl,
        csrf: root.dataset.csrf
    };

    var SIDE_SHORT = { LO: 'S ext', LI: 'S int', L: 'Stanga', RI: 'D int', RO: 'D ext', R: 'Dreapta' };
    var LEFT_ORDER = ['LO', 'LI', 'L'];
    var RIGHT_ORDER = ['R', 'RI', 'RO'];
    var uid = 0;

    // ---------- utilitare ----------
    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function withParams(url, params) {
        var target = new URL(url, window.location.href);
        Object.keys(params).forEach(function (key) {
            var value = params[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) { target.searchParams.append(key + '[]', String(item)); });
            } else {
                target.searchParams.set(key, String(value));
            }
        });
        return target.toString();
    }

    function getJson(url) {
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false, error: 'Raspuns invalid de la server.' }; })
                    .then(function (data) {
                        if (!response.ok || !data || data.ok !== true) {
                            throw new Error((data && data.error) || 'Eroare la incarcare.');
                        }
                        return data;
                    });
            });
    }

    var vehicleSearchCache = {};
    // Cu tireIds, serverul intoarce doar vehiculele compatibile cu cel putin una dintre anvelope.
    function searchVehicles(query, tireIds) {
        var key = query.trim().toUpperCase() + '|' + tireIds.join(',');
        if (!vehicleSearchCache[key]) {
            vehicleSearchCache[key] = getJson(withParams(cfg.vehiclesUrl, { q: query.trim(), tire_ids: tireIds }))
                .then(function (data) { return { vehicles: data.vehicles || [], hidden: Number(data.hidden_incompatible || 0) }; })
                .catch(function (error) { delete vehicleSearchCache[key]; throw error; });
        }
        return vehicleSearchCache[key];
    }

    function loadLayout(cache, vehicleId, tireIds) {
        if (!cache[vehicleId]) {
            cache[vehicleId] = getJson(withParams(cfg.layoutUrl, { vehicle_id: vehicleId, tire_ids: tireIds }))
                .catch(function (error) { delete cache[vehicleId]; throw error; });
        }
        return cache[vehicleId];
    }

    function findPosition(layout, positionId) {
        var found = null;
        (layout ? layout.axles : []).forEach(function (axle) {
            axle.positions.forEach(function (position) {
                if (position.position_id === positionId) { found = { axle: axle, position: position }; }
            });
        });
        return found;
    }

    function findAxle(layout, axleNo) {
        return (layout ? layout.axles : []).filter(function (axle) { return axle.axle_no === axleNo; })[0] || null;
    }

    function vehicleSubtitle(vehicle) {
        return [vehicle.name, vehicle.type_label].filter(Boolean).join(' · ');
    }

    // ---------- selector vehicul cu cautare (combobox accesibil) ----------
    function VehicleCombobox(host, options) {
        var self = this;
        var listId = 'tmVehicleList' + (++uid);
        this.value = null;
        this.onChange = options.onChange || function () {};
        this.tireIds = options.tireIds || function () { return []; };
        host.innerHTML =
            '<div class="tm-combo">' +
                '<div class="tm-combo-field">' +
                    '<i class="bi bi-search" aria-hidden="true"></i>' +
                    '<input type="text" class="form-control" role="combobox" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="' + listId + '"' +
                        (options.inputId ? ' id="' + esc(options.inputId) + '"' : '') +
                        ' placeholder="' + esc(options.placeholder || 'Cauta nr. inmatriculare, marca, model...') + '">' +
                    '<button type="button" class="tm-combo-clear" aria-label="Sterge vehiculul ales" hidden><i class="bi bi-x-lg"></i></button>' +
                '</div>' +
                '<ul class="tm-combo-list" role="listbox" id="' + listId + '" hidden></ul>' +
            '</div>';
        this.input = host.querySelector('input');
        this.list = host.querySelector('.tm-combo-list');
        this.clearBtn = host.querySelector('.tm-combo-clear');
        this.results = [];
        this.active = -1;
        this.timer = 0;
        this.requestNo = 0;

        this.input.addEventListener('focus', function () { self.search(self.value ? '' : self.input.value); });
        this.input.addEventListener('input', function () {
            if (self.value) { self.setValue(null, true); }
            window.clearTimeout(self.timer);
            self.pending = true;
            self.pickWhenReady = false;
            self.requestNo++; // raspunsurile cautarilor anterioare nu mai conteaza
            self.timer = window.setTimeout(function () { self.search(self.input.value); }, 160);
        });
        this.input.addEventListener('keydown', function (event) { self.onKey(event); });
        this.input.addEventListener('blur', function () { window.setTimeout(function () { self.close(); }, 120); });
        this.clearBtn.addEventListener('click', function () {
            self.input.value = '';
            self.setValue(null, true);
            self.input.focus();
        });
        this.list.addEventListener('mousedown', function (event) {
            var item = event.target.closest('[data-index]');
            if (!item) { return; }
            event.preventDefault();
            self.pick(Number(item.dataset.index));
        });
    }

    VehicleCombobox.prototype.search = function (query) {
        var self = this;
        var requestNo = ++this.requestNo;
        this.list.hidden = false;
        this.input.setAttribute('aria-expanded', 'true');
        this.list.innerHTML = '<li class="tm-combo-empty">Se cauta...</li>';
        this.pending = true;
        var tireIds = this.tireIds();
        searchVehicles(query, tireIds).then(function (data) {
            if (requestNo !== self.requestNo) { return; }
            self.pending = false;
            self.results = data.vehicles;
            self.hidden = data.hidden;
            self.searchedTireIds = tireIds;
            self.active = data.vehicles.length > 0 ? 0 : -1;
            self.render(query);
            if (self.pickWhenReady) {
                self.pickWhenReady = false;
                if (self.active >= 0) { self.pick(self.active); }
            }
        }).catch(function (error) {
            if (requestNo !== self.requestNo) { return; }
            self.pending = false;
            self.pickWhenReady = false;
            self.list.innerHTML = '<li class="tm-combo-empty text-danger">' + esc(error.message) + '</li>';
        });
    };

    VehicleCombobox.prototype.render = function (query) {
        var self = this;
        var tireCount = (this.searchedTireIds || []).length;
        var hiddenNote = this.hidden > 0
            ? '<li class="tm-combo-empty tm-combo-hidden" role="presentation"><i class="bi bi-funnel me-1"></i>' + this.hidden + ' vehicule ascunse: nu sunt compatibile cu ' + (tireCount === 1 ? 'anvelopa' : 'anvelopele selectate') + '.</li>'
            : '';
        if (this.results.length === 0) {
            this.list.innerHTML = '<li class="tm-combo-empty">Niciun vehicul activ' + (tireCount > 0 ? ' compatibil' : '') + ' nu corespunde cautarii' + (query.trim() ? ' „' + esc(query.trim()) + '”' : '') + '.</li>' + hiddenNote;
            this.input.removeAttribute('aria-activedescendant');
            return;
        }
        this.list.innerHTML = this.results.map(function (vehicle, index) {
            var selected = self.value && self.value.id === vehicle.id;
            var compatible = (vehicle.compatible_tire_ids || []).length;
            var partial = tireCount > 1 && compatible < tireCount
                ? '<span class="tm-combo-partial">compatibil cu ' + compatible + ' din ' + tireCount + ' anvelope</span>'
                : '';
            return '<li role="option" id="' + self.list.id + '-' + index + '" data-index="' + index + '" class="tm-combo-option' + (index === self.active ? ' is-active' : '') + '" aria-selected="' + (selected ? 'true' : 'false') + '">' +
                '<span class="tm-combo-plate">' + esc(vehicle.plate) + '</span>' +
                '<span class="tm-combo-sub">' + esc(vehicleSubtitle(vehicle)) + '</span>' + partial +
            '</li>';
        }).join('') + hiddenNote;
        this.syncActive();
    };

    VehicleCombobox.prototype.syncActive = function () {
        var self = this;
        Array.prototype.forEach.call(this.list.querySelectorAll('[data-index]'), function (item) {
            item.classList.toggle('is-active', Number(item.dataset.index) === self.active);
        });
        var activeItem = this.list.querySelector('[data-index="' + this.active + '"]');
        if (activeItem) {
            this.input.setAttribute('aria-activedescendant', activeItem.id);
            activeItem.scrollIntoView({ block: 'nearest' });
        } else {
            this.input.removeAttribute('aria-activedescendant');
        }
    };

    VehicleCombobox.prototype.onKey = function (event) {
        var open = !this.list.hidden;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (!open) { this.search(this.value ? '' : this.input.value); return; }
            if (this.results.length === 0) { return; }
            var step = event.key === 'ArrowDown' ? 1 : -1;
            this.active = (this.active + step + this.results.length) % this.results.length;
            this.syncActive();
        } else if (event.key === 'Enter') {
            // Enter nu trimite formularul cat timp lista e deschisa.
            if (open || this.pending) {
                event.preventDefault();
                // Cautarea pentru textul curent inca nu a raspuns: alegem primul rezultat cand vine.
                if (this.pending) { this.pickWhenReady = true; return; }
                if (this.active >= 0) { this.pick(this.active); }
            }
        } else if (event.key === 'Escape') {
            if (open) {
                // Inchide doar lista, nu si modalul.
                event.preventDefault();
                event.stopPropagation();
                this.close();
            }
        }
    };

    VehicleCombobox.prototype.pick = function (index) {
        var vehicle = this.results[index];
        if (!vehicle) { return; }
        this.setValue(vehicle, true);
        this.close();
    };

    VehicleCombobox.prototype.close = function () {
        this.list.hidden = true;
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
    };

    VehicleCombobox.prototype.setValue = function (vehicle, notify) {
        var changed = (this.value ? this.value.id : 0) !== (vehicle ? vehicle.id : 0);
        this.value = vehicle || null;
        this.input.value = vehicle ? vehicle.plate + (vehicle.name ? ' — ' + vehicle.name : '') : (notify ? this.input.value : '');
        this.input.classList.toggle('has-value', !!vehicle);
        this.clearBtn.hidden = !vehicle;
        if (!vehicle && !notify) { this.input.value = ''; }
        if (notify && changed) { this.onChange(this.value); }
    };

    // ---------- modalul "Muta anvelopa" (o anvelopa) ----------
    function initSinglePicker(picker) {
        if (picker.dataset.ready === '1') { return; }
        picker.dataset.ready = '1';

        var form = picker.closest('form');
        var tireId = Number(picker.dataset.tireId || 0);
        var tireMounted = picker.dataset.tireMounted === '1';
        var currentPositionId = Number(picker.dataset.currentPositionId || 0);
        var vehicleInput = picker.querySelector('[data-picker-vehicle-input]');
        var positionInput = picker.querySelector('[data-picker-position-input]');
        var swapBox = form ? form.querySelector('[data-picker-swap]') : null;
        var sourceVehicleInput = form ? form.querySelector('input[name="source_vehicle_id"]') : null;
        var cache = {};
        var state = { vehicle: null, layout: null, axleNo: null, positionId: null };
        var axleSelectId = 'tmAxle' + (++uid);
        var vehicleInputId = 'tmVehicle' + uid;

        var ui = document.createElement('div');
        ui.className = 'tm-single';
        ui.innerHTML =
            '<div class="row g-3">' +
                '<div class="col-md-7"><label class="form-label" for="' + vehicleInputId + '">Vehicul destinatie</label><div data-combo></div></div>' +
                '<div class="col-md-5"><label class="form-label" for="' + axleSelectId + '">Axa</label><select class="form-select" id="' + axleSelectId + '" disabled><option value="">Alege vehiculul</option></select></div>' +
                '<div class="col-12"><span class="form-label d-block">Pozitie</span><div class="tm-pos-grid" role="radiogroup" aria-label="Pozitie pe axa"><span class="text-muted small">Alege axa</span></div></div>' +
            '</div>' +
            '<div class="tm-picker-note small mt-2" aria-live="polite"></div>';
        picker.appendChild(ui);

        var axleSelect = ui.querySelector('select');
        var grid = ui.querySelector('.tm-pos-grid');
        var note = ui.querySelector('.tm-picker-note');
        var combo = new VehicleCombobox(ui.querySelector('[data-combo]'), {
            inputId: vehicleInputId,
            tireIds: function () { return [tireId]; },
            onChange: function (vehicle) { selectVehicle(vehicle); }
        });

        function setNote(html, kind) {
            note.className = 'tm-picker-note small mt-2' + (kind ? ' text-' + kind : '');
            note.innerHTML = html || '';
        }

        function sync() {
            vehicleInput.value = state.vehicle ? String(state.vehicle.id) : '';
            positionInput.value = state.positionId ? String(state.positionId) : '';
            describeSelection();
        }

        function positionState(axle, position) {
            if (position.position_id === currentPositionId) { return 'current'; }
            if (axle.compatible_tire_ids.indexOf(tireId) < 0) { return 'incompatible'; }
            if (position.occupied_by) { return tireMounted ? 'occupied-swap' : 'occupied'; }
            return 'free';
        }

        function describeSelection() {
            if (!state.layout || !state.positionId) { setNote(''); return; }
            var hit = findPosition(state.layout, state.positionId);
            if (!hit) { setNote(''); return; }
            if (hit.position.occupied_by) {
                var swapOn = swapBox && swapBox.checked;
                setNote('<i class="bi bi-arrow-left-right me-1"></i>Pozitia e ocupata de <strong>' + esc(hit.position.occupied_by.label) + '</strong>. ' +
                    (swapOn
                        ? 'La salvare, anvelopele isi schimba pozitiile: ' + esc(hit.position.occupied_by.label) + ' trece pe pozitia curenta a acestei anvelope.'
                        : 'Bifeaza „Schimba pozitiile daca tinta este ocupata” ca sa faci schimbul.'), swapOn ? 'primary' : 'warning');
                return;
            }
            setNote('<i class="bi bi-check-circle me-1"></i>' + esc(state.vehicle.plate) + ' · ' + esc(hit.axle.label) + ' · ' + esc(hit.position.label), 'success');
        }

        function renderAxles() {
            var axles = state.layout ? state.layout.axles : [];
            axleSelect.innerHTML = '<option value="">' + (axles.length ? 'Alege axa' : 'Vehiculul nu are pozitii configurate') + '</option>' +
                axles.map(function (axle) {
                    return '<option value="' + axle.axle_no + '"' + (axle.axle_no === state.axleNo ? ' selected' : '') + '>' + esc(axle.label) + (axle.compatible_tire_ids.indexOf(tireId) < 0 ? ' (incompatibila)' : '') + '</option>';
                }).join('');
            axleSelect.disabled = axles.length === 0;
        }

        function renderPositions() {
            var axle = findAxle(state.layout, state.axleNo);
            if (!axle) {
                grid.innerHTML = '<span class="text-muted small">' + (state.layout ? 'Alege axa' : 'Alege vehiculul') + '</span>';
                return;
            }
            grid.innerHTML = axle.positions.map(function (position) {
                var status = positionState(axle, position);
                var selected = position.position_id === state.positionId;
                var disabled = status === 'current' || status === 'incompatible' || status === 'occupied';
                var statusLabel = {
                    current: 'Pozitia curenta',
                    incompatible: 'Incompatibila',
                    occupied: 'Ocupata: ' + (position.occupied_by ? position.occupied_by.label : ''),
                    'occupied-swap': 'Ocupata: ' + (position.occupied_by ? position.occupied_by.label : ''),
                    free: 'Libera'
                }[status];
                return '<button type="button" role="radio" aria-checked="' + (selected ? 'true' : 'false') + '" class="tm-pos-chip is-' + status + (selected ? ' is-selected' : '') + '" data-position-id="' + position.position_id + '"' + (disabled ? ' disabled' : '') +
                    ' title="' + esc(position.full_label + ' — ' + statusLabel + (status === 'occupied' ? ' (anvelopa din stoc nu are pozitie de schimb; demonteaza intai)' : '')) + '">' +
                    '<span class="tm-pos-chip-label">' + esc(position.label) + '</span>' +
                    '<span class="tm-pos-chip-status">' + esc(selected ? 'Selectata' : statusLabel) + '</span>' +
                '</button>';
            }).join('');
        }

        function selectVehicle(vehicle) {
            state.vehicle = vehicle;
            state.layout = null;
            state.axleNo = null;
            state.positionId = null;
            renderAxles();
            renderPositions();
            sync();
            if (!vehicle) { return; }
            axleSelect.innerHTML = '<option value="">Se incarca axele...</option>';
            loadLayout(cache, vehicle.id, [tireId]).then(function (layout) {
                if (!state.vehicle || state.vehicle.id !== vehicle.id) { return; }
                state.layout = layout;
                var compatible = layout.axles.filter(function (axle) { return axle.compatible_tire_ids.indexOf(tireId) >= 0; });
                if (compatible.length === 1) { state.axleNo = compatible[0].axle_no; }
                renderAxles();
                renderPositions();
                sync();
            }).catch(function (error) {
                setNote(esc(error.message), 'danger');
                axleSelect.innerHTML = '<option value="">Eroare la incarcare</option>';
            });
        }

        axleSelect.addEventListener('change', function () {
            state.axleNo = axleSelect.value === '' ? null : Number(axleSelect.value);
            state.positionId = null;
            renderPositions();
            sync();
        });
        grid.addEventListener('click', function (event) {
            var chip = event.target.closest('[data-position-id]');
            if (!chip || chip.disabled) { return; }
            state.positionId = Number(chip.dataset.positionId);
            renderPositions();
            sync();
        });
        if (swapBox) { swapBox.addEventListener('change', describeSelection); }

        if (form) {
            form.addEventListener('submit', function (event) {
                var message = '';
                var hit = state.positionId ? findPosition(state.layout, state.positionId) : null;
                if (!state.vehicle) {
                    message = 'Alege vehiculul destinatie.';
                } else if (!hit) {
                    message = 'Alege axa si pozitia tinta.';
                } else if (hit.position.occupied_by && !(swapBox && swapBox.checked)) {
                    message = 'Pozitia aleasa este ocupata. Bifeaza „Schimba pozitiile daca tinta este ocupata” sau alege o pozitie libera.';
                }
                if (message) {
                    // stopPropagation: garda globala anti-dublu-submit din app.js ignora preventDefault.
                    event.preventDefault();
                    event.stopPropagation();
                    setNote('<i class="bi bi-exclamation-circle me-1"></i>' + esc(message), 'danger');
                }
            });
        }

        // Anvelopa montata: pornim de pe vehiculul ei (mutare intre axe).
        var sourceVehicleId = sourceVehicleInput ? Number(sourceVehicleInput.value || 0) : 0;
        if (sourceVehicleId > 0) {
            loadLayout(cache, sourceVehicleId, [tireId]).then(function (layout) {
                if (state.vehicle) { return; }
                combo.setValue(layout.vehicle, false);
                state.vehicle = layout.vehicle;
                state.layout = layout;
                var current = findPosition(layout, currentPositionId);
                state.axleNo = current ? current.axle.axle_no : null;
                renderAxles();
                renderPositions();
                sync();
            }).catch(function () { /* utilizatorul poate cauta vehiculul manual */ });
        }
    }

    document.addEventListener('show.bs.modal', function (event) {
        var picker = event.target && event.target.querySelector ? event.target.querySelector('[data-tire-target-picker]') : null;
        if (picker) { initSinglePicker(picker); }
    });

    // ---------- modalul "Monteaza anvelope" (montaj in masa) ----------
    var modalEl = root;
    var body = modalEl.querySelector('[data-bulk-body]');
    var saveBtn = modalEl.querySelector('[data-bulk-save]');
    var backBtn = modalEl.querySelector('[data-bulk-back]');
    var footerProgress = modalEl.querySelector('[data-bulk-footer-progress]');
    var bulk = null;

    function todayIso() {
        var now = new Date();
        return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    }

    // Selectia vine din store-ul paginii (dupa id-ul anvelopei), deci include si anvelopele
    // selectate care sunt ascunse acum de filtrele live.
    function selectedTires() {
        var picked = window.fleetTireSelection ? window.fleetTireSelection.all() : [];
        return {
            eligible: picked.filter(function (tire) { return tire.mountable; }).map(function (tire) {
                return {
                    id: tire.id,
                    label: tire.label,
                    size: tire.size,
                    serial: tire.serial,
                    status: tire.status,
                    type: tire.type,
                    vehicle: null,
                    axleNo: null,
                    positionId: null,
                    observation: '',
                    proposed: false,
                    error: ''
                };
            }),
            skipped: picked.filter(function (tire) { return !tire.mountable; }).map(function (tire) {
                return tire.label + (tire.mounted ? ' (deja montata)' : ' (' + (tire.status || 'indisponibila') + ')');
            })
        };
    }

    function tireIds() { return bulk.rows.map(function (row) { return row.id; }); }

    function rowLayout(row) { return row.vehicle ? bulk.layouts[row.vehicle.id] || null : null; }

    // Starea unui rand: incomplete | invalid | conflict | complete
    function rowStatus(row) {
        if (!row.vehicle || !row.axleNo || !row.positionId) { return { key: 'incomplete', label: 'Incompleta' }; }
        var layout = rowLayout(row);
        if (!layout) { return { key: 'incomplete', label: 'Se incarca...' }; }
        var hit = findPosition(layout, row.positionId);
        if (!hit) { return { key: 'invalid', label: 'Pozitie inexistenta' }; }
        if (hit.position.occupied_by) { return { key: 'invalid', label: 'Pozitie ocupata' }; }
        if (hit.axle.compatible_tire_ids.indexOf(row.id) < 0) { return { key: 'invalid', label: 'Incompatibila' }; }
        var others = bulk.rows.filter(function (other) { return other !== row && other.positionId === row.positionId; });
        if (others.length > 0) {
            return { key: 'conflict', label: 'Conflict cu #' + others.map(function (other) { return bulk.rows.indexOf(other) + 1; }).join(', #') };
        }
        if (row.error) { return { key: 'invalid', label: 'Respinsa la salvare' }; }
        return { key: 'complete', label: row.proposed ? 'Propusa automat' : 'Pozitie valida' };
    }

    function takenBy(positionId, exceptRow) {
        return bulk.rows.filter(function (row) { return row !== exceptRow && row.positionId === positionId; })[0] || null;
    }

    function ensureLayout(vehicle) {
        if (!vehicle) { return Promise.resolve(null); }
        if (bulk.layouts[vehicle.id]) { return Promise.resolve(bulk.layouts[vehicle.id]); }
        return loadLayout(bulk.layoutRequests, vehicle.id, tireIds()).then(function (layout) {
            if (bulk) { bulk.layouts[vehicle.id] = layout; }
            return layout;
        });
    }

    function setRowVehicle(row, vehicle) {
        var sameVehicle = (row.vehicle ? row.vehicle.id : 0) === (vehicle ? vehicle.id : 0);
        if (!sameVehicle) {
            // Vehicul nou: axa si pozitia vechi nu mai sunt valabile.
            row.axleNo = null;
            row.positionId = null;
            row.proposed = false;
        }
        row.vehicle = vehicle;
        row.error = '';
        if (row.combo && (row.combo.value ? row.combo.value.id : 0) !== (vehicle ? vehicle.id : 0)) {
            row.combo.setValue(vehicle, false);
        }
        return ensureLayout(vehicle).then(function () { refreshAll(); }).catch(function (error) {
            row.error = error.message;
            refreshAll();
        });
    }

    // Vehiculul din cautare stie cu ce anvelope e compatibil; doar pe acelea il aplicam.
    function fitsVehicle(row, vehicle) {
        return !Array.isArray(vehicle.compatible_tire_ids) || vehicle.compatible_tire_ids.indexOf(row.id) >= 0;
    }

    function incompatibleNote(vehicle) {
        var skipped = bulk.rows.filter(function (row) { return !fitsVehicle(row, vehicle); });
        return skipped.length > 0
            ? ' ' + skipped.length + ' anvelope nu sunt compatibile cu ' + vehicle.plate + ' (' + skipped.map(function (row) { return '#' + (bulk.rows.indexOf(row) + 1); }).join(', ') + ') — alege-le alt vehicul.'
            : '';
    }

    function sharedVehicleChanged(vehicle) {
        bulk.shared = vehicle;
        hideApplyConfirm();
        if (!vehicle) { refreshAll(); return; }
        // Neconditionat doar pentru anvelopele fara vehicul; restul doar cu "Aplica tuturor".
        var empty = bulk.rows.filter(function (row) { return !row.vehicle && fitsVehicle(row, vehicle); });
        ensureLayout(vehicle).then(function () {
            empty.forEach(function (row) { setRowVehicle(row, vehicle); });
            if (bulk.activeIndex < 0 || bulk.activeIndex >= bulk.rows.length) { bulk.activeIndex = 0; }
            var applied = empty.length > 0 ? 'Vehiculul ' + vehicle.plate + ' a fost aplicat la ' + empty.length + ' anvelope fara vehicul.' : '';
            var note = incompatibleNote(vehicle);
            flashInfo((applied + note).trim(), note ? 'warning' : 'muted');
            refreshAll();
        }).catch(function (error) { flashInfo(error.message, 'danger'); });
    }

    function applySharedToAll(resetOthers) {
        var vehicle = bulk.shared;
        if (!vehicle) { return; }
        bulk.rows.forEach(function (row) {
            if (!fitsVehicle(row, vehicle)) { return; }
            if (!row.vehicle || row.vehicle.id === vehicle.id || resetOthers) { setRowVehicle(row, vehicle); }
        });
        hideApplyConfirm();
        refreshAll();
    }

    function requestApplyAll() {
        if (!bulk.shared) { flashInfo('Alege mai intai vehiculul comun.', 'warning'); return; }
        var affected = bulk.rows.filter(function (row) { return row.vehicle && row.vehicle.id !== bulk.shared.id && fitsVehicle(row, bulk.shared); });
        if (affected.length === 0) {
            applySharedToAll(false);
            var note = incompatibleNote(bulk.shared);
            flashInfo(note ? ('Vehiculul ' + bulk.shared.plate + ' este aplicat anvelopelor compatibile.' + note) : 'Vehiculul ' + bulk.shared.plate + ' este aplicat tuturor anvelopelor.', note ? 'warning' : 'muted');
            return;
        }
        var box = body.querySelector('[data-apply-confirm]');
        box.hidden = false;
        box.querySelector('[data-apply-text]').innerHTML =
            '<strong>' + affected.length + ' anvelope</strong> au deja alt vehicul ales (' +
            affected.map(function (row) { return '#' + (bulk.rows.indexOf(row) + 1) + ' pe ' + esc(row.vehicle.plate) + (row.positionId ? ' cu pozitie' : ''); }).join(', ') +
            '). Daca aplici ' + esc(bulk.shared.plate) + ' tuturor, axa si pozitia lor se reseteaza.';
    }

    function hideApplyConfirm() {
        var box = body.querySelector('[data-apply-confirm]');
        if (box) { box.hidden = true; }
    }

    function flashInfo(message, kind) {
        var box = body.querySelector('[data-bulk-info]');
        if (!box) { return; }
        box.className = 'tm-bulk-info small' + (message ? ' text-' + (kind || 'muted') : '');
        box.textContent = message || '';
    }

    // Propune pozitii libere, compatibile, nefolosite pe vehiculul comun; nu atinge alocarile manuale.
    function autoAssign() {
        var vehicle = bulk.shared;
        if (!vehicle) { flashInfo('Alege mai intai vehiculul comun ca sa propun pozitii libere.', 'warning'); return; }
        ensureLayout(vehicle).then(function (layout) {
            var proposed = 0;
            var noSlot = [];
            bulk.rows.forEach(function (row) {
                if (row.positionId && rowStatus(row).key === 'complete') { return; }
                if (row.vehicle && row.vehicle.id !== vehicle.id) { return; }
                var pick = null;
                layout.axles.some(function (axle) {
                    if (axle.compatible_tire_ids.indexOf(row.id) < 0) { return false; }
                    return axle.positions.some(function (position) {
                        if (position.occupied_by || takenBy(position.position_id, row)) { return false; }
                        pick = { axle: axle, position: position };
                        return true;
                    });
                });
                if (!pick) { noSlot.push('#' + (bulk.rows.indexOf(row) + 1)); return; }
                row.vehicle = vehicle;
                if (row.combo) { row.combo.setValue(vehicle, false); }
                row.axleNo = pick.axle.axle_no;
                row.positionId = pick.position.position_id;
                row.proposed = true;
                row.error = '';
                proposed++;
            });
            flashInfo(
                (proposed > 0 ? proposed + ' pozitii propuse automat — verifica-le inainte de salvare.' : 'Nu am propus nicio pozitie noua.') +
                (noSlot.length > 0 ? ' Fara pozitie libera compatibila: ' + noSlot.join(', ') + '.' : ''),
                proposed > 0 ? 'primary' : 'warning'
            );
            refreshAll();
        }).catch(function (error) { flashInfo(error.message, 'danger'); });
    }

    function buildSkeleton() {
        var skipped = bulk.skipped.length > 0
            ? '<div class="alert alert-warning py-2 small mb-3"><i class="bi bi-info-circle me-1"></i>' + bulk.skipped.length + ' anvelope din selectie nu sunt incluse (doar anvelopele nemontate, In stoc / Rezerva / Resapata se pot monta in masa): ' + esc(bulk.skipped.slice(0, 6).join(', ')) + (bulk.skipped.length > 6 ? ' ...' : '') + '. Pentru o anvelopa montata foloseste „Muta anvelopa”.</div>'
            : '';
        var sharedId = 'tmSharedVehicle' + (++uid);
        body.innerHTML =
            '<div class="tm-bulk-progress" data-bulk-progress></div>' +
            skipped +
            '<div class="alert alert-danger py-2 small" data-bulk-general hidden></div>' +
            '<section class="tm-card mb-3">' +
                '<div class="tm-card-title">Informatii comune</div>' +
                '<div class="row g-3 align-items-end">' +
                    '<div class="col-lg-5"><label class="form-label" for="' + sharedId + '">Vehicul destinatie</label>' +
                        '<div class="d-flex gap-2"><div class="flex-grow-1" data-shared-combo></div>' +
                        '<button type="button" class="btn btn-outline-primary text-nowrap" data-apply-all title="Aplica vehiculul tuturor anvelopelor">Aplica tuturor</button></div></div>' +
                    '<div class="col-sm-4 col-lg-2"><label class="form-label" for="tmBulkDate">Data montarii</label><input type="date" class="form-control" id="tmBulkDate" data-bulk-date value="' + esc(bulk.date) + '" required></div>' +
                    '<div class="col-sm-8 col-lg-5"><label class="form-label" for="tmBulkReason">Motiv</label><input type="text" class="form-control" id="tmBulkReason" data-bulk-reason maxlength="255" value="' + esc(bulk.reason) + '"></div>' +
                    '<div class="col-12"><label class="form-label" for="tmBulkObs">Observatii</label><textarea class="form-control" id="tmBulkObs" rows="1" data-bulk-observation maxlength="1000" placeholder="Se aplica tuturor anvelopelor; poti adauga observatii individuale pe fiecare rand.">' + esc(bulk.observation) + '</textarea></div>' +
                '</div>' +
                '<div class="tm-apply-confirm mt-3" data-apply-confirm hidden>' +
                    '<div class="small mb-2" data-apply-text></div>' +
                    '<div class="d-flex flex-wrap gap-2">' +
                        '<button type="button" class="btn btn-sm btn-warning" data-apply-reset>Aplica si reseteaza</button>' +
                        '<button type="button" class="btn btn-sm btn-outline-secondary" data-apply-empty>Doar anvelopele fara vehicul</button>' +
                        '<button type="button" class="btn btn-sm btn-link" data-apply-cancel>Renunta</button>' +
                    '</div>' +
                '</div>' +
            '</section>' +
            '<section class="tm-card mb-3" data-diagram-card>' +
                '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">' +
                    '<div class="tm-card-title mb-0" data-diagram-title>Schema pozitiilor</div>' +
                    '<button type="button" class="btn btn-sm btn-outline-primary" data-auto-assign><i class="bi bi-magic me-1"></i>Aloca automat pozitiile libere</button>' +
                '</div>' +
                '<div class="tm-bulk-info small" data-bulk-info aria-live="polite"></div>' +
                '<div data-diagram></div>' +
            '</section>' +
            '<div class="tm-card-title">Anvelope</div>' +
            '<div class="tm-rows" data-rows></div>';

        var sharedCombo = new VehicleCombobox(body.querySelector('[data-shared-combo]'), {
            inputId: sharedId,
            tireIds: tireIds,
            onChange: sharedVehicleChanged
        });
        bulk.sharedCombo = sharedCombo;

        body.querySelector('[data-apply-all]').addEventListener('click', requestApplyAll);
        body.querySelector('[data-apply-reset]').addEventListener('click', function () { applySharedToAll(true); });
        body.querySelector('[data-apply-empty]').addEventListener('click', function () { applySharedToAll(false); });
        body.querySelector('[data-apply-cancel]').addEventListener('click', hideApplyConfirm);
        body.querySelector('[data-auto-assign]').addEventListener('click', autoAssign);
        body.querySelector('[data-bulk-date]').addEventListener('input', function (event) { bulk.date = event.target.value; refreshAll(); });
        body.querySelector('[data-bulk-reason]').addEventListener('input', function (event) { bulk.reason = event.target.value; });
        body.querySelector('[data-bulk-observation]').addEventListener('input', function (event) { bulk.observation = event.target.value; });
        body.querySelector('[data-diagram]').addEventListener('click', onDiagramClick);

        var rowsHost = body.querySelector('[data-rows]');
        bulk.rows.forEach(function (row) { rowsHost.appendChild(buildRow(row)); });
    }

    function buildRow(row) {
        var el = document.createElement('div');
        var vehicleId = 'tmRowVehicle' + (++uid);
        el.className = 'tm-row';
        el.dataset.rowId = String(row.id);
        el.innerHTML =
            '<div class="tm-row-head">' +
                '<span class="tm-row-num" data-row-num></span>' +
                '<div class="tm-row-tire">' +
                    '<div class="fw-semibold">' + esc(row.label) + '</div>' +
                    '<div class="small text-muted">' + esc([row.size, row.serial ? 'Seria ' + row.serial : '', row.type, row.status].filter(Boolean).join(' · ')) + '</div>' +
                '</div>' +
                '<span class="tm-row-status" data-row-status></span>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary tm-row-remove" data-row-remove aria-label="Scoate anvelopa din operatiune" title="Scoate din operatiune"><i class="bi bi-x-lg"></i></button>' +
            '</div>' +
            '<div class="row g-2 align-items-end">' +
                '<div class="col-md-5"><label class="form-label small" for="' + vehicleId + '">Vehicul</label><div data-row-combo></div></div>' +
                '<div class="col-md-3"><label class="form-label small" for="' + vehicleId + 'a">Axa</label><select class="form-select" id="' + vehicleId + 'a" data-row-axle></select></div>' +
                '<div class="col-md-4"><label class="form-label small" for="' + vehicleId + 'p">Pozitie</label><select class="form-select" id="' + vehicleId + 'p" data-row-position></select></div>' +
            '</div>' +
            '<div class="tm-row-msg small" data-row-msg></div>' +
            '<button type="button" class="btn btn-link btn-sm px-0 tm-row-obs-toggle" data-row-obs-toggle>Observatie individuala</button>' +
            '<textarea class="form-control form-control-sm mt-1" rows="1" maxlength="1000" data-row-obs hidden placeholder="Inlocuieste observatia comuna pentru aceasta anvelopa"></textarea>';

        row.el = el;
        row.combo = new VehicleCombobox(el.querySelector('[data-row-combo]'), {
            inputId: vehicleId,
            tireIds: function () { return [row.id]; },
            onChange: function (vehicle) { setRowVehicle(row, vehicle); }
        });
        el.addEventListener('focusin', function () { setActive(row); });
        el.addEventListener('click', function () { setActive(row); });
        el.querySelector('[data-row-axle]').addEventListener('change', function (event) {
            row.axleNo = event.target.value === '' ? null : Number(event.target.value);
            row.positionId = null;
            row.proposed = false;
            row.error = '';
            refreshAll();
        });
        el.querySelector('[data-row-position]').addEventListener('change', function (event) {
            row.positionId = event.target.value === '' ? null : Number(event.target.value);
            row.proposed = false;
            row.error = '';
            refreshAll();
        });
        el.querySelector('[data-row-remove]').addEventListener('click', function (event) {
            event.stopPropagation();
            var index = bulk.rows.indexOf(row);
            bulk.rows.splice(index, 1);
            el.remove();
            if (bulk.activeIndex >= bulk.rows.length) { bulk.activeIndex = bulk.rows.length - 1; }
            refreshAll();
        });
        el.querySelector('[data-row-obs-toggle]').addEventListener('click', function () {
            var obs = el.querySelector('[data-row-obs]');
            obs.hidden = !obs.hidden;
            if (!obs.hidden) { obs.focus(); }
        });
        el.querySelector('[data-row-obs]').addEventListener('input', function (event) { row.observation = event.target.value; });
        return el;
    }

    function setActive(row) {
        var index = bulk.rows.indexOf(row);
        if (index < 0 || index === bulk.activeIndex) { return; }
        bulk.activeIndex = index;
        refreshAll();
    }

    function refreshRow(row, index) {
        var el = row.el;
        var layout = rowLayout(row);
        var status = rowStatus(row);
        el.classList.toggle('is-active', index === bulk.activeIndex);
        el.dataset.status = status.key;
        el.querySelector('[data-row-num]').textContent = String(index + 1);
        var badge = el.querySelector('[data-row-status]');
        badge.className = 'tm-row-status is-' + status.key;
        badge.textContent = status.label;

        var axleSelect = el.querySelector('[data-row-axle]');
        var positionSelect = el.querySelector('[data-row-position]');
        if (!row.vehicle) {
            axleSelect.innerHTML = '<option value="">Alege vehiculul</option>';
            axleSelect.disabled = true;
        } else if (!layout) {
            axleSelect.innerHTML = '<option value="">Se incarca...</option>';
            axleSelect.disabled = true;
        } else {
            axleSelect.disabled = layout.axles.length === 0;
            axleSelect.innerHTML = '<option value="">' + (layout.axles.length ? 'Alege axa' : 'Fara pozitii configurate') + '</option>' + layout.axles.map(function (axle) {
                var compatible = axle.compatible_tire_ids.indexOf(row.id) >= 0;
                return '<option value="' + axle.axle_no + '"' + (axle.axle_no === row.axleNo ? ' selected' : '') + (compatible ? '' : ' disabled') + '>' + esc(axle.label) + (compatible ? '' : ' (incompatibila)') + '</option>';
            }).join('');
        }

        var axle = findAxle(layout, row.axleNo);
        if (!axle) {
            positionSelect.innerHTML = '<option value="">' + (row.vehicle ? 'Alege axa' : '—') + '</option>';
            positionSelect.disabled = true;
        } else {
            positionSelect.disabled = false;
            positionSelect.innerHTML = '<option value="">Alege pozitia</option>' + axle.positions.map(function (position) {
                var other = takenBy(position.position_id, row);
                var suffix = position.occupied_by ? ' — ocupata: ' + position.occupied_by.label
                    : (other ? ' — aleasa pt. #' + (bulk.rows.indexOf(other) + 1) : ' — libera');
                return '<option value="' + position.position_id + '"' + (position.position_id === row.positionId ? ' selected' : '') + (position.occupied_by ? ' disabled' : '') + '>' + esc(position.label + suffix) + '</option>';
            }).join('');
        }

        var msg = el.querySelector('[data-row-msg]');
        var hit = layout && row.positionId ? findPosition(layout, row.positionId) : null;
        if (row.error) {
            msg.className = 'tm-row-msg small text-danger';
            msg.textContent = row.error;
        } else if (status.key === 'conflict') {
            msg.className = 'tm-row-msg small text-danger';
            msg.textContent = 'Aceeasi pozitie e aleasa pentru mai multe anvelope. Alege alta pozitie.';
        } else if (status.key === 'invalid' && hit && hit.position.occupied_by) {
            msg.className = 'tm-row-msg small text-danger';
            msg.textContent = 'Pozitia este ocupata de ' + hit.position.occupied_by.label + '. In montajul in masa nu se fac schimburi — demonteaza intai anvelopa existenta.';
        } else if (status.key === 'complete' && hit) {
            msg.className = 'tm-row-msg small text-success';
            msg.textContent = row.vehicle.plate + ' · ' + hit.axle.label + ' · ' + hit.position.label;
        } else {
            msg.className = 'tm-row-msg small text-muted';
            msg.textContent = '';
        }
    }

    function diagramVehicle() {
        var active = bulk.rows[bulk.activeIndex];
        if (active && active.vehicle) { return active.vehicle; }
        return bulk.shared;
    }

    function slotHtml(axle, position, activeRow) {
        var owner = takenBy(position.position_id, null);
        var owners = bulk.rows.filter(function (row) { return row.positionId === position.position_id; });
        var state = 'free';
        var text = SIDE_SHORT[position.side_code] || position.label;
        var title = position.full_label;
        if (position.occupied_by) {
            state = 'occupied';
            title += ' — ocupata: ' + position.occupied_by.label;
        } else if (owners.length > 1) {
            state = 'conflict';
            title += ' — conflict: #' + owners.map(function (row) { return bulk.rows.indexOf(row) + 1; }).join(', #');
        } else if (owner) {
            state = owner === activeRow ? 'selected' : 'assigned';
            title += ' — anvelopa #' + (bulk.rows.indexOf(owner) + 1) + ' (' + owner.label + ')';
        } else if (activeRow && axle.compatible_tire_ids.indexOf(activeRow.id) < 0) {
            state = 'unavailable';
            title += ' — incompatibila cu anvelopa #' + (bulk.activeIndex + 1);
        } else {
            title += ' — libera';
        }
        var badge = owners.length > 0 ? '<span class="tm-slot-num">#' + owners.map(function (row) { return bulk.rows.indexOf(row) + 1; }).join(',') + '</span>' : '';
        var disabled = state === 'occupied' || state === 'unavailable';
        return '<button type="button" class="tm-slot is-' + state + '" data-slot-position="' + position.position_id + '" data-slot-axle="' + axle.axle_no + '"' + (disabled ? ' disabled' : '') + ' title="' + esc(title) + '" aria-label="' + esc(title) + '">' +
            '<span class="tm-slot-text">' + esc(text) + '</span>' + badge + '</button>';
    }

    function renderDiagram() {
        var host = body.querySelector('[data-diagram]');
        var titleEl = body.querySelector('[data-diagram-title]');
        var vehicle = diagramVehicle();
        if (!vehicle) {
            titleEl.textContent = 'Schema pozitiilor';
            host.innerHTML = '<div class="text-muted small py-2">Alege vehiculul comun sau vehiculul unei anvelope ca sa vezi pozitiile.</div>';
            return;
        }
        var layout = bulk.layouts[vehicle.id];
        titleEl.innerHTML = 'Schema ' + esc(vehicle.plate) + ' <span class="text-muted fw-normal">' + esc(vehicleSubtitle(vehicle)) + '</span>';
        if (!layout) {
            host.innerHTML = '<div class="text-muted small py-2">Se incarca pozitiile...</div>';
            ensureLayout(vehicle).then(function () { if (bulk) { refreshAll(); } }).catch(function (error) { host.innerHTML = '<div class="text-danger small">' + esc(error.message) + '</div>'; });
            return;
        }
        if (layout.axles.length === 0) {
            host.innerHTML = '<div class="text-muted small py-2">Vehiculul nu are pozitii de anvelope configurate (formula axelor).</div>';
            return;
        }
        var activeRow = bulk.rows[bulk.activeIndex] || null;
        var hint = activeRow
            ? 'Click pe o pozitie libera ca sa o atribui anvelopei <strong>#' + (bulk.activeIndex + 1) + ' ' + esc(activeRow.label) + '</strong>. Click pe pozitia ei o elibereaza.'
            : '';
        host.innerHTML =
            '<div class="tm-diagram">' + layout.axles.map(function (axle) {
                var bySide = {};
                axle.positions.forEach(function (position) { bySide[position.side_code] = position; });
                var known = LEFT_ORDER.concat(RIGHT_ORDER);
                var others = axle.positions.filter(function (position) { return known.indexOf(position.side_code) < 0; });
                var left = LEFT_ORDER.filter(function (code) { return bySide[code]; }).map(function (code) { return slotHtml(axle, bySide[code], activeRow); }).join('');
                var right = RIGHT_ORDER.filter(function (code) { return bySide[code]; }).map(function (code) { return slotHtml(axle, bySide[code], activeRow); }).join('');
                var extra = others.map(function (position) { return slotHtml(axle, position, activeRow); }).join('');
                return '<div class="tm-axle">' +
                    '<div class="tm-axle-label">' + esc(axle.label) + '</div>' +
                    '<div class="tm-axle-row"><div class="tm-axle-side is-left">' + left + '</div><div class="tm-axle-bar" aria-hidden="true"></div><div class="tm-axle-side is-right">' + right + '</div>' + (extra ? '<div class="tm-axle-side">' + extra + '</div>' : '') + '</div>' +
                '</div>';
            }).join('') + '</div>' +
            '<div class="tm-legend small"><span class="tm-legend-item is-free">Libera</span><span class="tm-legend-item is-occupied">Ocupata</span><span class="tm-legend-item is-selected">Selectata (randul activ)</span><span class="tm-legend-item is-assigned">Alocata altei anvelope</span><span class="tm-legend-item is-conflict">Conflict</span><span class="tm-legend-item is-unavailable">Indisponibila</span></div>' +
            (hint ? '<div class="small text-muted mt-1">' + hint + '</div>' : '');
    }

    function onDiagramClick(event) {
        var slot = event.target.closest('[data-slot-position]');
        if (!slot || slot.disabled) { return; }
        var row = bulk.rows[bulk.activeIndex];
        if (!row) { return; }
        var vehicle = diagramVehicle();
        var positionId = Number(slot.dataset.slotPosition);
        if (row.positionId === positionId) {
            row.positionId = null;
            row.proposed = false;
            refreshAll();
            return;
        }
        var assign = function () {
            row.axleNo = Number(slot.dataset.slotAxle);
            row.positionId = positionId;
            row.proposed = false;
            row.error = '';
            // Trecem la urmatoarea anvelopa fara pozitie.
            var next = bulk.rows.findIndex(function (other, index) { return index > bulk.activeIndex && !other.positionId; });
            if (next < 0) { next = bulk.rows.findIndex(function (other) { return !other.positionId; }); }
            if (next >= 0) { bulk.activeIndex = next; }
            refreshAll();
        };
        if (!row.vehicle || row.vehicle.id !== vehicle.id) {
            setRowVehicle(row, vehicle).then(assign);
        } else {
            assign();
        }
    }

    function counts() {
        var statuses = bulk.rows.map(rowStatus);
        return {
            total: bulk.rows.length,
            complete: statuses.filter(function (s) { return s.key === 'complete'; }).length,
            conflict: statuses.filter(function (s) { return s.key === 'conflict'; }).length,
            invalid: statuses.filter(function (s) { return s.key === 'invalid'; }).length
        };
    }

    function refreshAll() {
        if (!bulk || bulk.step !== 'edit') { return; }
        bulk.rows.forEach(refreshRow);
        renderDiagram();
        var c = counts();
        var remaining = c.total - c.complete;
        var progress = body.querySelector('[data-bulk-progress]');
        var percent = c.total > 0 ? Math.round((c.complete / c.total) * 100) : 0;
        progress.innerHTML =
            '<div class="d-flex flex-wrap align-items-center gap-2">' +
                '<strong>' + c.total + ' anvelope selectate</strong><span class="text-muted">·</span><span>' + c.complete + ' din ' + c.total + ' pozitionate</span>' +
                (remaining > 0 ? '<span class="text-muted">· ' + remaining + ' ramase</span>' : '') +
                (c.conflict > 0 ? '<span class="badge text-bg-danger">' + c.conflict + ' in conflict</span>' : '') +
                (c.invalid > 0 ? '<span class="badge text-bg-danger">' + c.invalid + ' invalide</span>' : '') +
            '</div>' +
            '<div class="progress mt-2" role="progressbar" aria-label="Progres alocare" aria-valuenow="' + percent + '" aria-valuemin="0" aria-valuemax="100" style="height:6px"><div class="progress-bar" style="width:' + percent + '%"></div></div>';
        if (c.total === 0) {
            body.querySelector('[data-rows]').innerHTML = '<div class="text-muted small py-3">Nu a mai ramas nicio anvelopa in operatiune.</div>';
        }
        footerProgress.textContent = c.total > 0 ? c.complete + ' din ' + c.total + ' pozitionate' : '';
        var dateOk = /^\d{4}-\d{2}-\d{2}$/.test(bulk.date || '');
        saveBtn.disabled = c.total === 0 || c.complete !== c.total || !dateOk || bulk.saving;
        saveBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Salveaza montarea';
        backBtn.hidden = true;
    }

    function showGeneral(messages) {
        var box = body.querySelector('[data-bulk-general]');
        if (!box) { return; }
        box.hidden = !messages || messages.length === 0;
        box.innerHTML = (messages || []).map(esc).join('<br>');
    }

    function showConfirm() {
        bulk.step = 'confirm';
        bulk.editNodes = Array.prototype.slice.call(body.childNodes);
        var byVehicle = {};
        var order = [];
        bulk.rows.forEach(function (row, index) {
            var hit = findPosition(rowLayout(row), row.positionId);
            if (!byVehicle[row.vehicle.id]) { byVehicle[row.vehicle.id] = { vehicle: row.vehicle, items: [] }; order.push(row.vehicle.id); }
            byVehicle[row.vehicle.id].items.push({ index: index, row: row, hit: hit });
        });
        var summary = document.createElement('div');
        summary.className = 'tm-confirm';
        summary.innerHTML =
            '<div class="alert alert-primary py-2 small"><i class="bi bi-clipboard-check me-1"></i>Verifica montarea. Se salveaza totul intr-o singura operatiune: ori se monteaza toate anvelopele, ori niciuna.</div>' +
            '<div class="d-flex flex-wrap gap-3 small mb-3"><span><strong>' + bulk.rows.length + '</strong> anvelope</span><span><strong>' + order.length + '</strong> ' + (order.length === 1 ? 'vehicul' : 'vehicule') + '</span><span>Data: <strong>' + esc(bulk.date) + '</strong></span>' +
            (bulk.reason.trim() ? '<span>Motiv: <strong>' + esc(bulk.reason.trim()) + '</strong></span>' : '') + '</div>' +
            order.map(function (vehicleId) {
                var group = byVehicle[vehicleId];
                return '<div class="tm-card mb-2"><div class="tm-card-title">' + esc(group.vehicle.plate) + ' <span class="text-muted fw-normal">' + esc(vehicleSubtitle(group.vehicle)) + '</span></div>' +
                    '<table class="table table-sm mb-0 small"><thead><tr><th>#</th><th>Anvelopa</th><th>Axa</th><th>Pozitie</th></tr></thead><tbody>' +
                    group.items.map(function (item) {
                        return '<tr><td>' + (item.index + 1) + '</td><td>' + esc(item.row.label) + (item.row.size ? ' <span class="text-muted">' + esc(item.row.size) + '</span>' : '') + (item.row.proposed ? ' <span class="badge text-bg-light border">propusa</span>' : '') + '</td><td>' + esc(item.hit ? item.hit.axle.label : '-') + '</td><td>' + esc(item.hit ? item.hit.position.label : '-') + '</td></tr>';
                    }).join('') + '</tbody></table></div>';
            }).join('') +
            '<div class="small text-muted"><i class="bi bi-shield-check me-1"></i>Nicio anvelopa deja montata nu este mutata, schimbata sau demontata de aceasta operatiune.</div>';
        body.innerHTML = '';
        body.appendChild(summary);
        backBtn.hidden = false;
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check2-all me-1"></i>Confirma montarea';
        body.scrollTop = 0;
    }

    function backToEdit() {
        if (!bulk || bulk.step === 'edit') { return; }
        bulk.step = 'edit';
        body.innerHTML = '';
        (bulk.editNodes || []).forEach(function (node) { body.appendChild(node); });
        refreshAll();
    }

    function submit() {
        if (bulk.saving) { return; }
        bulk.saving = true;
        saveBtn.disabled = true;
        backBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Se salveaza...';

        var data = new URLSearchParams();
        data.append('_token', cfg.csrf);
        data.append('mount_date', bulk.date);
        data.append('reason', bulk.reason.trim());
        data.append('observation', bulk.observation.trim());
        bulk.rows.forEach(function (row, index) {
            data.append('assignments[' + index + '][tire_id]', String(row.id));
            data.append('assignments[' + index + '][vehicle_id]', String(row.vehicle.id));
            data.append('assignments[' + index + '][position_id]', String(row.positionId));
            data.append('assignments[' + index + '][observation]', row.observation.trim());
        });

        fetch(cfg.saveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: data.toString()
        }).then(function (response) {
            return response.json().catch(function () { return { ok: false, general: ['Raspuns invalid de la server (' + response.status + ').'] }; });
        }).then(function (result) {
            if (result && result.ok) {
                saveBtn.innerHTML = '<i class="bi bi-check2 me-1"></i>Montate: ' + result.mounted;
                // Reincarcam pagina: tabelul, contoarele si mesajul de succes vin de la server; selectia se goleste.
                window.location.reload();
                return;
            }
            failSave(result || {});
        }).catch(function () {
            failSave({ general: ['Conexiunea a esuat. Nicio anvelopa nu a fost montata; incearca din nou.'] });
        });
    }

    function failSave(result) {
        bulk.saving = false;
        backBtn.disabled = false;
        var errors = result.errors || {};
        bulk.rows.forEach(function (row) { row.error = errors[row.id] || errors[String(row.id)] || ''; });
        // Ocuparea s-a putut schimba intre timp: recitim pozitiile vehiculelor implicate.
        bulk.layouts = {};
        bulk.layoutRequests = {};
        var vehicles = {};
        bulk.rows.forEach(function (row) { if (row.vehicle) { vehicles[row.vehicle.id] = row.vehicle; } });
        if (bulk.shared) { vehicles[bulk.shared.id] = bulk.shared; }
        backToEdit();
        var general = (result.general || []).slice();
        var errorCount = Object.keys(errors).length;
        if (errorCount > 0) { general.push(errorCount + ' anvelope au probleme — vezi mesajele de pe randuri.'); }
        showGeneral(general.length > 0 ? general : ['Salvarea a esuat. Nicio anvelopa nu a fost montata.']);
        Promise.all(Object.keys(vehicles).map(function (id) { return ensureLayout(vehicles[id]).catch(function () { return null; }); }))
            .then(function () { refreshAll(); });
        body.scrollTop = 0;
    }

    saveBtn.addEventListener('click', function () {
        if (!bulk) { return; }
        if (bulk.step === 'edit') {
            var c = counts();
            if (c.total === 0 || c.complete !== c.total) { return; }
            showConfirm();
        } else {
            submit();
        }
    });
    backBtn.addEventListener('click', backToEdit);

    modalEl.addEventListener('hide.bs.modal', function (event) {
        if (bulk && bulk.saving) { event.preventDefault(); }
    });
    modalEl.addEventListener('hidden.bs.modal', function () {
        bulk = null;
        body.innerHTML = '';
        backBtn.hidden = true;
    });

    function openBulk() {
        var selection = selectedTires();
        if (selection.eligible.length === 0) { return; }
        bulk = {
            rows: selection.eligible,
            skipped: selection.skipped,
            shared: null,
            layouts: {},
            layoutRequests: {},
            activeIndex: 0,
            date: todayIso(),
            reason: 'Montaj anvelope',
            observation: '',
            step: 'edit',
            saving: false
        };
        backBtn.disabled = false;
        buildSkeleton();
        refreshAll();
        // bootstrap se incarca in footer, dupa acest script: il folosim doar la click.
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-tire-bulk-mount]')) {
            event.preventDefault();
            openBulk();
        }
    });
})();
