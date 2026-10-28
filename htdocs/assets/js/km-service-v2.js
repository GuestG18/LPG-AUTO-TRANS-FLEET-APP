/* Dispecer curse -> Km service V2: sectiunea "Solicitare interventie".
 * Componentele suspectate vin din Registru piese & lucrari (acelasi catalog ca Interventii V2);
 * randurile se trimit in components_json, iar serverul le transforma in lucrari (Suspectata).
 * Randurile deja evaluate de coordonator raman blocate (scopul tehnic confirmat).
 * Expune window.KmServiceV2.load(values), apelat de fill() din _service_km_panel.php. */
(function () {
    'use strict';

    var form = document.querySelector('[data-service-km-form]');
    var bootNode = document.getElementById('km-service-boot');
    if (!form || !bootNode) {
        return;
    }
    var boot = {};
    try {
        boot = JSON.parse(bootNode.textContent || '{}') || {};
    } catch (error) {
        return;
    }
    var catalog = boot.catalog || { categories: [], groups: {}, primary: {}, subcategories: {} };
    var vehicles = boot.vehicles || {};
    var operations = boot.operations || {};
    var assessment = boot.assessment || {};

    var vehicleSelect = form.querySelector('[data-service-km-field="vehicle_id"]');
    var hidden = form.querySelector('[data-ksv2-components-json]');
    var searchInput = form.querySelector('[data-ksv2-search]');
    var resultsBox = form.querySelector('[data-ksv2-results]');
    var rowsBox = form.querySelector('[data-ksv2-rows]');
    var notice = form.querySelector('[data-ksv2-notice]');
    var targetBox = form.querySelector('[data-ksv2-target]');
    var linkSelect = form.querySelector('[data-ksv2-link]');
    var linkText = form.querySelector('[data-ksv2-link-text]');
    var linkedBox = form.querySelector('[data-ksv2-linked]');
    var notes = form.querySelector('[data-service-km-field="observatii"]');
    var counter = form.querySelector('[data-ksv2-count]');
    var registryModal = document.getElementById('ksv2RegistryModal');

    var state = { entryId: '', vehicleId: 0, target: 'main', rows: [], linked: null, results: [], active: -1 };
    var infoCache = {};
    var LINK_DEFAULT = 'Se va genera o intervenție în Mentenanță pe baza acestor informații, sau se va lega la o intervenție existentă.';

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function normalize(value) {
        return String(value || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }

    function autoType(type) {
        if (type === 'camion' || type === 'cap_tractor') {
            return type;
        }
        return /^semiremorca/.test(String(type || '')) ? 'semiremorca' : '';
    }

    function typeLabel(type) {
        return { camion: 'Camion', cap_tractor: 'Cap tractor', semiremorca: 'Semiremorcă' }[autoType(type)] || 'Vehicul';
    }

    /** Ca in Reparatii Auto: cap tractor = doar Sasiu; restul Sasiu + Rezervor (Livrare Gaz doar pe Rezervor). */
    function branches(type) {
        var groups = catalog.groups || {};
        var sasiu = { primary: 'sasiu', categories: (groups.sasiu || []).concat(groups.hidraulic || []) };
        var rezervor = { primary: 'rezervor', categories: (groups.sasiu || []).concat(groups.hidraulic || [], groups.livrare_gaz || []) };
        return autoType(type) === 'cap_tractor' ? [sasiu] : [sasiu, rezervor];
    }

    var componentIndex = {};
    var categoryIndex = {};
    (catalog.categories || []).forEach(function (category) {
        categoryIndex[category.id] = category;
        (category.components || []).forEach(function (component) {
            componentIndex[component.key] = { component: component, category: category };
        });
    });

    function mainVehicle() {
        var vehicle = vehicles[state.vehicleId];
        return vehicle ? { id: state.vehicleId, plate: vehicle.plate, model: vehicle.model, type: vehicle.type } : null;
    }

    function trailer() {
        var vehicle = vehicles[state.vehicleId];
        return vehicle && vehicle.trailer ? vehicle.trailer : null;
    }

    function targetVehicle() {
        return state.target === 'trailer' && trailer() ? trailer() : mainVehicle();
    }

    function isAllowed(row, vehicle) {
        if (!vehicle || !componentIndex[row.key]) {
            return false;
        }
        var categoryId = componentIndex[row.key].category.id;
        return branches(vehicle.type).some(function (branch) {
            return branch.primary === row.primary && branch.categories.indexOf(categoryId) !== -1;
        });
    }

    function vehicleInfo(vehicleId) {
        if (!vehicleId) {
            return Promise.resolve({ configured: {}, configured_trailer: {}, open_interventions: [] });
        }
        if (!infoCache[vehicleId]) {
            var url = boot.info_url + (boot.info_url.indexOf('?') === -1 ? '?' : '&') + 'vehicle_id=' + encodeURIComponent(vehicleId);
            infoCache[vehicleId] = fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) { return response.json(); })
                .then(function (data) { return data && data.success ? data : { configured: {}, configured_trailer: {}, open_interventions: [] }; })
                .catch(function () { return { configured: {}, configured_trailer: {}, open_interventions: [] }; });
        }
        return infoCache[vehicleId];
    }

    function showNotice(text, tone) {
        notice.hidden = !text;
        notice.textContent = text || '';
        notice.className = 'ksv2-notice' + (tone ? ' is-' + tone : '');
    }

    // ------------------------------------------------------------------ vehiculul componentei

    function renderTarget() {
        var main = mainVehicle();
        var coupled = trailer();
        if (!main || !coupled) {
            state.target = 'main';
            targetBox.hidden = true;
            targetBox.innerHTML = '';
            return;
        }
        targetBox.hidden = false;
        targetBox.innerHTML = '<span class="ksv2-target-label">Componentă pe:</span>'
            + '<button type="button" class="' + (state.target === 'main' ? 'is-active' : '') + '" data-ksv2-target-btn="main" aria-pressed="' + (state.target === 'main') + '">'
            + esc(typeLabel(main.type)) + ' ' + esc(main.plate) + '</button>'
            + '<button type="button" class="' + (state.target === 'trailer' ? 'is-active' : '') + '" data-ksv2-target-btn="trailer" aria-pressed="' + (state.target === 'trailer') + '">'
            + 'Semiremorcă ' + esc(coupled.plate) + ' <small>(cuplată)</small></button>';
    }

    targetBox.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ksv2-target-btn]');
        if (!button) {
            return;
        }
        state.target = button.getAttribute('data-ksv2-target-btn');
        renderTarget();
        runSearch();
    });

    // ------------------------------------------------------------------ cautare rapida

    function runSearch() {
        var query = normalize(searchInput.value);
        var vehicle = targetVehicle();
        if (query === '' || !vehicle) {
            resultsBox.hidden = true;
            searchInput.setAttribute('aria-expanded', 'false');
            if (query !== '' && !vehicle) {
                resultsBox.hidden = false;
                resultsBox.innerHTML = '<div class="ksv2-result-empty">Alege mai întâi vehiculul.</div>';
            }
            return;
        }
        var terms = query.split(/\s+/);
        var primaryLabels = catalog.primary || {};
        var subLabels = catalog.subcategories || {};
        var showPrimary = branches(vehicle.type).length > 1;
        var results = [];
        branches(vehicle.type).forEach(function (branch) {
            branch.categories.forEach(function (categoryId) {
                var category = categoryIndex[categoryId];
                if (!category) {
                    return;
                }
                (category.components || []).forEach(function (component) {
                    var haystack = normalize([component.name, component.code, category.name, subLabels[category.subcategory] || '', primaryLabels[branch.primary] || ''].join(' '));
                    if (terms.every(function (term) { return haystack.indexOf(term) !== -1; })) {
                        results.push({ key: component.key, primary: branch.primary, name: component.name, code: component.code, category: category.name, path: (showPrimary ? (primaryLabels[branch.primary] || '') + ' › ' : '') + category.name });
                    }
                });
            });
        });
        state.results = results.slice(0, 30);
        state.active = state.results.length ? 0 : -1;
        vehicleInfo(state.vehicleId).then(function (info) {
            var configured = (state.target === 'trailer' ? info.configured_trailer : info.configured) || {};
            resultsBox.innerHTML = state.results.length
                ? state.results.map(function (result, index) {
                    return '<button type="button" role="option" id="ksv2-opt-' + index + '" class="ksv2-result' + (index === state.active ? ' is-active' : '') + '" data-ksv2-result="' + index + '" aria-selected="' + (index === state.active) + '">'
                        + '<i class="bi bi-gear" aria-hidden="true"></i><span><strong>' + esc(result.name) + '</strong><small>' + esc(result.path) + '</small></span>'
                        + (Object.prototype.hasOwnProperty.call(configured, result.key) ? '<span class="ksv2-cfg">Configurată</span>' : '')
                        + '<code>' + esc(result.code) + '</code></button>';
                }).join('')
                : '<div class="ksv2-result-empty">Nicio componentă nu se potrivește pentru ' + esc(vehicle.plate) + '.</div>';
            resultsBox.hidden = false;
            searchInput.setAttribute('aria-expanded', 'true');
            searchInput.setAttribute('aria-activedescendant', state.active >= 0 ? 'ksv2-opt-' + state.active : '');
        });
    }

    function pickResult(index) {
        var result = state.results[index];
        if (!result) {
            return;
        }
        addRow(result.key, result.primary, '', 'verificare');
        searchInput.value = '';
        resultsBox.hidden = true;
        searchInput.setAttribute('aria-expanded', 'false');
        searchInput.focus();
    }

    var searchTimer = null;
    searchInput.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(runSearch, 120);
    });
    searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault(); // Enter nu trimite formularul Km service
            if (!resultsBox.hidden && state.active >= 0) {
                pickResult(state.active);
            }
            return;
        }
        if (event.key === 'Escape') {
            resultsBox.hidden = true;
            searchInput.setAttribute('aria-expanded', 'false');
            return;
        }
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && state.results.length) {
            event.preventDefault();
            state.active = (state.active + (event.key === 'ArrowDown' ? 1 : -1) + state.results.length) % state.results.length;
            resultsBox.querySelectorAll('[data-ksv2-result]').forEach(function (button, index) {
                button.classList.toggle('is-active', index === state.active);
                button.setAttribute('aria-selected', index === state.active ? 'true' : 'false');
                if (index === state.active) {
                    button.scrollIntoView({ block: 'nearest' });
                }
            });
            searchInput.setAttribute('aria-activedescendant', 'ksv2-opt-' + state.active);
        }
    });
    resultsBox.addEventListener('mousedown', function (event) {
        var button = event.target.closest('[data-ksv2-result]');
        if (button) {
            event.preventDefault();
            pickResult(Number(button.getAttribute('data-ksv2-result')));
        }
    });
    searchInput.addEventListener('blur', function () {
        window.setTimeout(function () { resultsBox.hidden = true; searchInput.setAttribute('aria-expanded', 'false'); }, 150);
    });

    // ------------------------------------------------------------------ randuri

    function addRow(key, primary, position, operation) {
        var found = componentIndex[key];
        var coupled = trailer();
        var onTrailer = state.target === 'trailer' && coupled;
        if (!found) {
            return;
        }
        state.rows.push({
            item_id: 0, key: key, primary: primary, position: position || '', operation: operation || 'verificare',
            vehicle_id: onTrailer ? coupled.id : null, locked: false, stare: 'suspectata',
            name: found.component.name, category: found.category.name, code: found.component.code,
            vehicle_plate: onTrailer ? coupled.plate : ''
        });
        showNotice('');
        renderRows();
    }

    function renderRows() {
        var primaryLabels = catalog.primary || {};
        rowsBox.innerHTML = state.rows.map(function (row, index) {
            var subtitle = [];
            if (row.vehicle_plate) {
                subtitle.push('pe ' + row.vehicle_plate);
            }
            if (row.category) {
                subtitle.push((row.primary && primaryLabels[row.primary] && branches((mainVehicle() || {}).type).length > 1 ? primaryLabels[row.primary] + ' › ' : '') + row.category);
            }
            if (row.code) {
                subtitle.push(row.code);
            }
            var operationOptions = Object.keys(operations).map(function (key) {
                return '<option value="' + esc(key) + '"' + (row.operation === key ? ' selected' : '') + '>' + esc(operations[key]) + '</option>';
            }).join('');
            return '<div class="ksv2-row' + (row.locked ? ' is-locked' : '') + '" data-ksv2-row="' + index + '">'
                + '<span class="ksv2-row-icon" aria-hidden="true"><i class="bi ' + (row.locked ? 'bi-lock' : 'bi-gear') + '"></i></span>'
                + '<div class="ksv2-row-main"><strong>' + esc(row.name) + '</strong>'
                + '<small>' + esc(subtitle.join(' · ')) + '</small>'
                + (row.locked
                    ? (row.position ? '<small>' + esc(row.position) + '</small>' : '')
                    : '<input class="form-control form-control-sm ksv2-pos" maxlength="80" placeholder="Poziție / axă (ex. Axa 4 stânga)" value="' + esc(row.position) + '" data-ksv2-pos aria-label="Poziție ' + esc(row.name) + '">')
                + '</div>'
                + '<div class="ksv2-row-field"><span>Operație</span><select class="form-select form-select-sm" data-ksv2-op aria-label="Operație ' + esc(row.name) + '"' + (row.locked ? ' disabled' : '') + '>' + operationOptions + '</select></div>'
                + '<div class="ksv2-row-field"><span>Stare</span><span class="iv2-badge ev-' + esc(row.stare) + '">' + esc(assessment[row.stare] || row.stare) + '</span></div>'
                + (row.locked
                    ? '<span class="ksv2-row-lock" title="Evaluată de coordonatorul tehnic: se modifică doar din Mentenanță → Intervenții."><i class="bi bi-shield-lock" aria-hidden="true"></i><span class="visually-hidden">Blocată</span></span>'
                    : '<button type="button" class="ksv2-row-del" data-ksv2-del aria-label="Scoate ' + esc(row.name) + '"><i class="bi bi-trash" aria-hidden="true"></i></button>')
                + '</div>';
        }).join('');
        serialize();
    }

    rowsBox.addEventListener('input', function (event) {
        var rowNode = event.target.closest('[data-ksv2-row]');
        if (!rowNode) {
            return;
        }
        var row = state.rows[Number(rowNode.getAttribute('data-ksv2-row'))];
        if (event.target.matches('[data-ksv2-pos]')) {
            row.position = event.target.value;
        }
        if (event.target.matches('[data-ksv2-op]')) {
            row.operation = event.target.value;
        }
        serialize();
    });
    rowsBox.addEventListener('change', function (event) {
        if (event.target.matches('[data-ksv2-op]')) {
            rowsBox.dispatchEvent(new Event('input'));
            var rowNode = event.target.closest('[data-ksv2-row]');
            state.rows[Number(rowNode.getAttribute('data-ksv2-row'))].operation = event.target.value;
            serialize();
        }
    });
    rowsBox.addEventListener('click', function (event) {
        var del = event.target.closest('[data-ksv2-del]');
        if (!del) {
            return;
        }
        var rowNode = del.closest('[data-ksv2-row]');
        state.rows.splice(Number(rowNode.getAttribute('data-ksv2-row')), 1);
        renderRows();
        searchInput.focus();
    });

    /** Doar cererea dispecerului (randurile neblocate); cele evaluate raman pe server neschimbate. */
    function serialize() {
        hidden.value = JSON.stringify(state.rows.filter(function (row) { return !row.locked; }).map(function (row) {
            return { item_id: row.item_id || 0, key: row.key, primary: row.primary, position: row.position || '', operation: row.operation, vehicle_id: row.vehicle_id || null };
        }));
    }

    // ------------------------------------------------------------------ schimbarea vehiculului

    /** Componentele nepotrivite vehiculului nou se scot, cu mesaj — nu se asociaza in tacere. */
    function revalidate(previousVehicleId) {
        var main = mainVehicle();
        var coupled = trailer();
        var removed = [];
        state.rows = state.rows.filter(function (row) {
            if (row.locked) {
                return true;
            }
            var vehicle = row.vehicle_id ? (coupled && coupled.id === Number(row.vehicle_id) ? coupled : null) : main;
            if (!isAllowed(row, vehicle)) {
                removed.push(row.name + (row.vehicle_plate ? ' (pe ' + row.vehicle_plate + ')' : ''));
                return false;
            }
            return true;
        });
        var locked = state.rows.some(function (row) { return row.locked; });
        if (removed.length) {
            showNotice('Am scos componentele care nu se aplică vehiculului ales: ' + removed.join(', ') + '.', 'warning');
        } else if (locked && previousVehicleId && Number(previousVehicleId) !== state.vehicleId) {
            showNotice('Intervenția are componente evaluate de coordonator pentru vehiculul inițial; schimbarea vehiculului va fi refuzată la salvare.', 'warning');
        } else {
            showNotice('');
        }
        renderRows();
    }

    function refreshLink() {
        var main = mainVehicle();
        if (state.linked) {
            linkSelect.hidden = true;
            linkSelect.value = '';
            linkText.innerHTML = 'Cererea se actualizează în intervenția <b>#' + state.linked.id + '</b>; diagnosticul și componentele evaluate de coordonator nu se modifică de aici.';
            return;
        }
        linkText.textContent = LINK_DEFAULT;
        linkSelect.innerHTML = '<option value="">Creează o intervenție nouă</option>';
        linkSelect.hidden = true;
        if (!main || state.entryId) {
            return;
        }
        var vehicleId = state.vehicleId;
        vehicleInfo(vehicleId).then(function (info) {
            if (vehicleId !== state.vehicleId || state.linked) {
                return;
            }
            var open = info.open_interventions || [];
            if (!open.length) {
                return;
            }
            var wanted = linkSelect.getAttribute('data-ksv2-initial') || '';
            linkSelect.innerHTML = '<option value="">Creează o intervenție nouă</option>' + open.map(function (item) {
                return '<option value="' + item.id + '"' + (String(item.id) === wanted ? ' selected' : '') + '>Leagă de #' + item.id + ' · ' + esc(item.status_label) + ' — ' + esc(item.descriere) + '</option>';
            }).join('');
            linkSelect.hidden = false;
            linkText.textContent = 'Vehiculul are deja ' + (open.length === 1 ? 'o intervenție deschisă' : open.length + ' intervenții deschise') + ' în Mentenanță. Poți lega plecarea la service de ea în loc să creezi alta:';
        });
    }

    function renderLinked() {
        var link = linkedBox.querySelector('[data-ksv2-linked-link]');
        var open = linkedBox.querySelector('[data-ksv2-linked-open]');
        if (!state.linked) {
            linkedBox.hidden = true;
            return;
        }
        var url = boot.intervention_url + (boot.intervention_url.indexOf('?') === -1 ? '?' : '&') + 'intervention_id=' + state.linked.id;
        link.href = url;
        link.innerHTML = '#' + state.linked.id + ' · ' + esc(state.linked.status_label);
        open.href = url;
        linkedBox.hidden = false;
    }

    vehicleSelect.addEventListener('change', function () {
        var previous = state.vehicleId;
        state.vehicleId = Number(vehicleSelect.value || 0);
        state.target = 'main';
        renderTarget();
        revalidate(previous);
        refreshLink();
        if (searchInput.value) {
            runSearch();
        }
    });

    // ------------------------------------------------------------------ registru (ierarhie)

    var registryPicker = null;
    var registryTarget = null;
    form.querySelector('[data-ksv2-add]').addEventListener('click', function () {
        if (!mainVehicle()) {
            showNotice('Alege mai întâi vehiculul, ca să vezi doar componentele lui.', 'warning');
            vehicleSelect.focus();
            return;
        }
        searchInput.focus();
        runSearch();
    });
    form.querySelector('[data-ksv2-registry]').addEventListener('click', function () {
        var vehicle = targetVehicle();
        if (!vehicle) {
            showNotice('Alege mai întâi vehiculul, ca să vezi doar componentele lui.', 'warning');
            vehicleSelect.focus();
            return;
        }
        if (!window.FleetComponentPicker || !registryModal) {
            return;
        }
        registryTarget = state.target;
        var counterNode = registryModal.querySelector('[data-ksv2-registry-count]');
        counterNode.textContent = '';
        registryPicker = new window.FleetComponentPicker(registryModal.querySelector('[data-ksv2-registry-host]'), {
            catalog: catalog,
            operations: operations,
            loadConfigured: function () {
                return vehicleInfo(state.vehicleId).then(function (info) { return registryTarget === 'trailer' ? info.configured_trailer : info.configured; });
            },
            onChange: function (value) {
                counterNode.textContent = value.length ? value.length + ' selectate — vor fi trimise ca „Suspectată”' : '';
            }
        });
        registryPicker.setVehicle({ id: vehicle.id, plate: vehicle.plate, model: vehicle.model, type: vehicle.type });
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(registryModal).show();
        }
    });
    if (registryModal) {
        registryModal.querySelector('[data-ksv2-registry-add]').addEventListener('click', function () {
            var value = registryPicker ? registryPicker.value() : [];
            if (!value.length) {
                return;
            }
            var previousTarget = state.target;
            state.target = registryTarget || 'main';
            value.forEach(function (entry) { addRow(entry.key, entry.primary, entry.position, entry.operation); });
            state.target = previousTarget;
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(registryModal).hide();
            }
        });
    }

    // ------------------------------------------------------------------ observatii, motiv

    function updateCounter() {
        counter.textContent = String((notes.value || '').length);
    }
    notes.addEventListener('input', updateCounter);
    form.querySelector('[data-ksv2-clear="motiv"]').addEventListener('click', function () {
        var reason = form.querySelector('[data-service-km-field="motiv"]');
        reason.value = '';
        reason.focus();
    });

    form.addEventListener('submit', function () { serialize(); }, true);

    // ------------------------------------------------------------------ incarcare (Editeaza / Renunta / Km pierduti)

    function rowsFromValues(list) {
        return (Array.isArray(list) ? list : []).map(function (row) {
            var found = componentIndex[row.key] || null;
            return {
                item_id: Number(row.item_id || 0),
                key: row.key || '',
                primary: row.primary || 'sasiu',
                position: row.position || '',
                operation: row.operation || 'verificare',
                vehicle_id: row.vehicle_id ? Number(row.vehicle_id) : null,
                locked: !!row.locked,
                stare: row.stare || 'suspectata',
                name: row.name || (found ? found.component.name : row.key),
                category: row.category || (found ? found.category.name : ''),
                code: row.code || (found ? found.component.code : ''),
                vehicle_plate: row.vehicle_plate || (row.vehicle_id && vehicles[row.vehicle_id] ? vehicles[row.vehicle_id].plate : '')
            };
        }).filter(function (row) { return row.key || row.locked; });
    }

    function load(values) {
        values = values || {};
        state.entryId = String(values.id || '');
        state.vehicleId = Number(values.vehicle_id || 0);
        state.target = 'main';
        state.linked = values.interventie || null;
        state.rows = rowsFromValues(values.components);
        linkSelect.removeAttribute('data-ksv2-initial');
        searchInput.value = '';
        resultsBox.hidden = true;
        showNotice('');
        renderTarget();
        renderRows();
        refreshLink();
        renderLinked();
        updateCounter();
    }

    window.KmServiceV2 = { load: load };

    // Starea initiala (inclusiv valorile pastrate dupa o eroare de validare).
    var initialRows = [];
    try {
        initialRows = JSON.parse(hidden.value || '[]');
    } catch (error) {
        initialRows = [];
    }
    var initialLink = linkSelect.getAttribute('data-ksv2-initial') || '';
    var initialId = (form.querySelector('[data-service-km-field="id"]') || {}).value || '';
    var initialValues = { id: initialId, vehicle_id: vehicleSelect.value, components: initialRows };
    if (initialId) {
        // Dupa o eroare la modificare: interventia legata si randurile blocate vin din lista.
        document.querySelectorAll('[data-service-km-edit]').forEach(function (button) {
            var entry = null;
            try {
                entry = JSON.parse(button.getAttribute('data-service-km-edit') || '{}');
            } catch (error) {
                return;
            }
            if (entry && String(entry.id) === String(initialId)) {
                initialValues.interventie = entry.interventie || null;
                initialValues.components = (entry.components || []).filter(function (row) { return row.locked; }).concat(initialRows);
            }
        });
    }
    load(initialValues);
    if (initialLink) {
        linkSelect.setAttribute('data-ksv2-initial', initialLink);
        refreshLink();
    }
})();
