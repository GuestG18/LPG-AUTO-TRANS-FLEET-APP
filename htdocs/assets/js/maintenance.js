(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var destinationInputs = document.querySelectorAll('input[name="usage_destination"]');
        var stockFields = document.querySelector('[data-part-stock-fields]');
        var directFields = document.querySelector('[data-part-direct-fields]');
        var directVehicle = document.querySelector('[name="mount_vehicle_id"]');

        function syncPartDestination() {
            var selected = document.querySelector('input[name="usage_destination"]:checked');
            var isDirect = selected && selected.value === 'direct';
            if (stockFields) {
                stockFields.classList.toggle('d-none', isDirect);
            }
            if (directFields) {
                directFields.classList.toggle('d-none', !isDirect);
            }
            if (directVehicle) {
                directVehicle.required = !!isDirect;
            }
            destinationInputs.forEach(function (input) {
                var card = input.closest('.maintenance-choice-card');
                if (card) {
                    card.classList.toggle('active', input.checked);
                }
            });
        }

        destinationInputs.forEach(function (input) {
            input.addEventListener('change', syncPartDestination);
        });
        syncPartDestination();
        initPartCatalogPicker();

        // „Adaugă piesă”: componenta se alege pe aceeași cale ca în Reparații Auto și se leagă de piesa ei din stoc.
        function initPartCatalogPicker() {
            var section = document.querySelector('[data-part-catalog]');
            var jsonNode = section ? section.querySelector('[data-catalog-json]') : null;
            if (!section || !jsonNode) {
                return;
            }
            var data;
            try {
                data = JSON.parse(jsonNode.textContent || '{}');
            } catch (error) {
                return;
            }
            var form = section.closest('form');
            var typeSelect = section.querySelector('[data-catalog-vehicle-type]');
            var vehicleSelect = section.querySelector('[data-catalog-vehicle]');
            var primarySelect = section.querySelector('[data-catalog-primary]');
            var subSelect = section.querySelector('[data-catalog-subcategory]');
            var componentSelect = section.querySelector('[data-catalog-component]');
            var partIdInput = section.querySelector('[data-catalog-part-id]');
            var stockInfo = section.querySelector('[data-catalog-stock-info]');
            var catalogFields = section.querySelector('[data-catalog-fields]');
            var qtyLabel = form.querySelector('[data-stock-qty-label]');
            var qtyInput = form.querySelector('[name="stoc_initial"]');
            var mountKm = form.querySelector('[name="mount_km"]');
            var lockedNames = ['cod_piesa', 'denumire', 'categorie'];
            var filledNames = ['cod_piesa', 'denumire', 'categorie', 'descriere', 'producator', 'cod_oem', 'stoc_minim',
                'pret_achizitie', 'furnizor', 'locatie_depozit', 'garantie_piesa', 'garantie_manopera',
                'interval_km', 'interval_luni', 'avertizare_km', 'avertizare_zile'];

            function field(name) {
                return form.querySelector('[name="' + name + '"]');
            }

            function fillOptions(select, options, selected) {
                select.innerHTML = '';
                options.forEach(function (option) {
                    var node = document.createElement('option');
                    node.value = option.value;
                    node.textContent = option.label;
                    select.appendChild(node);
                });
                if (selected !== undefined && options.some(function (option) { return option.value === selected; })) {
                    select.value = selected;
                }
            }

            // Ramurile si subcategoriile pe tip de vehicul vin din arborele administrat (data.branches).
            function branchesFor(type) {
                var types = type === 'ansamblu' ? ['cap_tractor', 'semiremorca'] : [type];
                var seen = {};
                var result = [];
                types.forEach(function (item) {
                    ((data.branches || {})[item] || []).forEach(function (branch) {
                        if (!seen[branch.primary]) {
                            seen[branch.primary] = true;
                            result.push(branch);
                        }
                    });
                });
                return result;
            }

            function isCatalog() {
                var checked = form.querySelector('input[name="part_source"]:checked');
                return !checked || checked.value === 'catalog';
            }

            function isDirect() {
                var checked = form.querySelector('input[name="usage_destination"]:checked');
                return !!checked && checked.value === 'direct';
            }

            function renderVehicles() {
                var type = typeSelect.value;
                var vehicles = (data.vehicles || []).filter(function (vehicle) {
                    return type === 'ansamblu' ? (vehicle.type === 'cap_tractor' || vehicle.type === 'semiremorca') : vehicle.type === type;
                });
                fillOptions(vehicleSelect, [{ value: '', label: vehicles.length ? 'Selectează vehiculul' : 'Niciun vehicul de acest tip' }]
                    .concat(vehicles.map(function (vehicle) { return { value: String(vehicle.id), label: vehicle.label }; })), vehicleSelect.value);
                var primaries = branchesFor(type).map(function (branch) { return branch.primary; });
                fillOptions(primarySelect, primaries.map(function (key) {
                    return { value: key, label: (data.primary_labels || {})[key] || key };
                }), primarySelect.value || (type === 'cap_tractor' ? 'sasiu' : 'rezervor'));
                renderSubcategories();
            }

            function renderSubcategories() {
                var primary = primarySelect.value;
                var branch = branchesFor(typeSelect.value).filter(function (item) { return item.primary === primary; })[0];
                var subs = branch ? branch.subs : [];
                fillOptions(subSelect, subs.map(function (key) {
                    return { value: key, label: (data.subcategory_labels || {})[key] || key };
                }), subSelect.value || (primary === 'sasiu' ? 'sasiu' : 'livrare_gaz'));
                renderComponents();
            }

            function renderComponents() {
                var previous = componentSelect.value;
                componentSelect.innerHTML = '<option value="">Selectează componenta</option>';
                ((data.category_groups || {})[subSelect.value] || []).forEach(function (categoryId) {
                    var category = (data.categories || {})[categoryId];
                    if (!category || !category.components.length) {
                        return;
                    }
                    var group = document.createElement('optgroup');
                    group.label = category.name;
                    category.components.forEach(function (component) {
                        var node = document.createElement('option');
                        node.value = component.key;
                        node.textContent = component.name + (component.code ? ' · ' + component.code : '');
                        group.appendChild(node);
                    });
                    componentSelect.appendChild(group);
                });
                componentSelect.value = previous;
                applyComponent();
            }

            function findComponent(key) {
                var found = null;
                Object.keys(data.categories || {}).some(function (categoryId) {
                    var category = data.categories[categoryId];
                    return category.components.some(function (component) {
                        if (component.key === key) {
                            found = { category: category, component: component };
                            return true;
                        }
                        return false;
                    });
                });
                return found;
            }

            function setValue(name, value) {
                var input = field(name);
                if (!input) {
                    return;
                }
                var text = value === null || value === undefined ? '' : String(value);
                if (input.tagName === 'SELECT' && text !== '' && !Array.prototype.some.call(input.options, function (option) { return option.value === text; })) {
                    var option = document.createElement('option');
                    option.value = text;
                    option.textContent = text;
                    input.appendChild(option);
                }
                input.value = text;
            }

            function setLocked(locked) {
                lockedNames.forEach(function (name) {
                    var input = field(name);
                    if (!input) {
                        return;
                    }
                    if (input.tagName === 'SELECT') {
                        input.classList.toggle('pe-none', locked);
                        input.tabIndex = locked ? -1 : 0;
                    } else {
                        input.readOnly = locked;
                    }
                    input.classList.toggle('bg-body-tertiary', locked);
                });
            }

            function applyComponent() {
                var match = componentSelect.value ? findComponent(componentSelect.value) : null;
                var part = match ? match.component.part : null;
                partIdInput.value = part ? String(part.id) : '';
                if (!match) {
                    filledNames.forEach(function (name) { setValue(name, ''); });
                    stockInfo.textContent = '';
                    return;
                }
                setValue('cod_piesa', match.component.code);
                setValue('denumire', match.component.name);
                setValue('categorie', match.category.name);
                setValue('descriere', match.component.description);
                ['producator', 'cod_oem', 'furnizor', 'locatie_depozit', 'garantie_piesa', 'garantie_manopera',
                    'interval_km', 'interval_luni', 'avertizare_km', 'avertizare_zile'].forEach(function (name) {
                    setValue(name, part ? part[name] : '');
                });
                setValue('stoc_minim', part && part.stoc_minim > 0 ? part.stoc_minim : '');
                setValue('pret_achizitie', part && part.pret_achizitie > 0 ? part.pret_achizitie : '');
                if (part && part.unitate_masura) {
                    setValue('unitate_masura', part.unitate_masura);
                }
                var maintenance = field('pentru_mentenanta');
                if (maintenance) {
                    maintenance.checked = true;
                }
                stockInfo.textContent = part
                    ? 'În stoc acum: ' + Number(part.stoc_curent || 0).toLocaleString('ro-RO') + ' ' + (part.unitate_masura || 'buc')
                    : 'Componenta nu are încă piesă în stoc — va fi creată.';
            }

            function syncVehicle() {
                var mountVehicle = field('mount_vehicle_id');
                if (!mountVehicle || !vehicleSelect.value) {
                    return;
                }
                mountVehicle.value = vehicleSelect.value;
                var vehicle = (data.vehicles || []).filter(function (item) { return String(item.id) === vehicleSelect.value; })[0];
                if (mountKm && vehicle && vehicle.km > 0) {
                    mountKm.value = vehicle.km;
                }
            }

            function syncSource() {
                var catalog = isCatalog();
                catalogFields.classList.toggle('d-none', !catalog);
                componentSelect.required = catalog;
                setLocked(catalog);
                if (catalog) {
                    applyComponent();
                } else {
                    partIdInput.value = '';
                    filledNames.forEach(function (name) { setValue(name, ''); });
                }
                syncQuantity();
            }

            function syncQuantity() {
                var addsToExisting = isCatalog() && !isDirect();
                if (qtyLabel) {
                    qtyLabel.innerHTML = addsToExisting ? 'Cantitate adăugată <span>*</span>' : 'Stoc inițial';
                }
                if (qtyInput) {
                    qtyInput.required = addsToExisting;
                    qtyInput.min = addsToExisting ? '0.01' : '0';
                }
            }

            typeSelect.addEventListener('change', function () {
                primarySelect.value = '';
                subSelect.value = '';
                renderVehicles();
            });
            vehicleSelect.addEventListener('change', syncVehicle);
            primarySelect.addEventListener('change', function () {
                subSelect.value = '';
                renderSubcategories();
            });
            subSelect.addEventListener('change', renderComponents);
            componentSelect.addEventListener('change', applyComponent);
            form.querySelectorAll('input[name="part_source"]').forEach(function (input) {
                input.addEventListener('change', syncSource);
            });
            destinationInputs.forEach(function (input) {
                input.addEventListener('change', function () {
                    syncQuantity();
                    syncVehicle();
                });
            });

            renderVehicles();
            syncSource();
        }

        function syncCostCenterSelect(vehicleSelect) {
            var form = vehicleSelect.closest('form') || document;
            var costCenterSelect = form.querySelector('[data-maintenance-cost-center-select]');
            if (!costCenterSelect) {
                return;
            }

            if (!costCenterSelect._maintenanceOptions) {
                costCenterSelect._maintenanceOptions = Array.prototype.map.call(costCenterSelect.options, function (option) {
                    return {
                        value: option.value,
                        text: option.text,
                        vehicleType: option.dataset.vehicleType || 'universal'
                    };
                });
            }

            var selectedOption = vehicleSelect.selectedOptions && vehicleSelect.selectedOptions.length > 0
                ? vehicleSelect.selectedOptions[0]
                : null;
            var vehicleType = selectedOption ? (selectedOption.dataset.vehicleType || '') : '';
            var currentValue = costCenterSelect.value;
            var visibleOptions = costCenterSelect._maintenanceOptions.filter(function (option) {
                return option.value === ''
                    || vehicleType === ''
                    || option.vehicleType === 'universal'
                    || option.vehicleType === vehicleType;
            });

            costCenterSelect.innerHTML = '';
            visibleOptions.forEach(function (optionData) {
                var option = document.createElement('option');
                option.value = optionData.value;
                option.textContent = optionData.text;
                option.dataset.vehicleType = optionData.vehicleType;
                costCenterSelect.appendChild(option);
            });

            var stillAvailable = visibleOptions.some(function (optionData) {
                return optionData.value === currentValue;
            });
            costCenterSelect.value = stillAvailable ? currentValue : '';
        }

        document.querySelectorAll('[data-maintenance-vehicle-select]').forEach(function (vehicleSelect) {
            vehicleSelect.addEventListener('change', function () {
                syncCostCenterSelect(vehicleSelect);
            });
            syncCostCenterSelect(vehicleSelect);
        });

        var componentForm = document.getElementById('maintenance-component-group-form');
        if (componentForm) {
            var componentId = componentForm.querySelector('[data-maintenance-component-id]');
            var componentVehicle = componentForm.querySelector('[data-maintenance-component-vehicle]');
            var componentName = componentForm.querySelector('[data-maintenance-component-name]');
            var componentParts = componentForm.querySelector('[data-maintenance-component-components]');
            var componentActive = componentForm.querySelector('[data-maintenance-component-active]');
            var componentReset = componentForm.querySelector('[data-maintenance-component-reset]');

            function resetComponentForm() {
                componentForm.reset();
                if (componentId) {
                    componentId.value = '';
                }
                if (componentActive) {
                    componentActive.checked = true;
                }
            }

            document.querySelectorAll('[data-maintenance-component-edit]').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (componentId) {
                        componentId.value = button.dataset.id || '';
                    }
                    if (componentVehicle) {
                        componentVehicle.value = button.dataset.vehicleType || 'universal';
                    }
                    if (componentName) {
                        componentName.value = button.dataset.name || '';
                    }
                    if (componentParts) {
                        try {
                            componentParts.value = decodeURIComponent(button.dataset.components || '');
                        } catch (error) {
                            componentParts.value = '';
                        }
                    }
                    if (componentActive) {
                        componentActive.checked = button.dataset.active === '1';
                    }
                    componentForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    if (componentName) {
                        componentName.focus();
                    }
                });
            });

            if (componentReset) {
                componentReset.addEventListener('click', resetComponentForm);
            }
        }

        var laborCost = document.querySelector('input[name="cost_manopera"]');
        var partsCost = document.querySelector('input[name="cost_piese"]');
        var totalCost = document.querySelector('input[name="cost"]');
        function syncRepairTotal() {
            if (!laborCost || !partsCost || !totalCost) {
                return;
            }
            var labor = Number.parseFloat(String(laborCost.value || '0').replace(',', '.')) || 0;
            var parts = Number.parseFloat(String(partsCost.value || '0').replace(',', '.')) || 0;
            totalCost.value = (labor + parts).toFixed(2);
        }
        [laborCost, partsCost].forEach(function (input) {
            if (input) {
                input.addEventListener('input', syncRepairTotal);
            }
        });

        var repairInvoiceToggles = document.querySelectorAll('[data-repair-invoice-toggle]');
        var repairInvoiceDetails = document.querySelectorAll('[data-repair-invoice-detail]');
        var repairInvoiceRows = document.querySelectorAll('[data-repair-invoice-row]');

        function setActiveRepairInvoice(invoiceId) {
            repairInvoiceDetails.forEach(function (detail) {
                detail.classList.toggle('active', detail.dataset.repairInvoiceDetail === invoiceId);
            });
            repairInvoiceRows.forEach(function (row) {
                row.classList.toggle('is-expanded', row.dataset.repairInvoiceRow === invoiceId);
            });
            repairInvoiceToggles.forEach(function (toggle) {
                var isActive = toggle.dataset.repairInvoiceToggle === invoiceId;
                toggle.classList.toggle('active', isActive);
                toggle.setAttribute('aria-expanded', isActive ? 'true' : 'false');
            });
        }

        repairInvoiceToggles.forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                setActiveRepairInvoice(toggle.dataset.repairInvoiceToggle || '');
            });
        });

        document.querySelectorAll('[data-repair-invoice-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                var invoiceId = button.dataset.repairInvoiceClose || '';
                repairInvoiceDetails.forEach(function (detail) {
                    if (detail.dataset.repairInvoiceDetail === invoiceId) {
                        detail.classList.remove('active');
                    }
                });
                repairInvoiceRows.forEach(function (row) {
                    if (row.dataset.repairInvoiceRow === invoiceId) {
                        row.classList.remove('is-expanded');
                    }
                });
                repairInvoiceToggles.forEach(function (toggle) {
                    if (toggle.dataset.repairInvoiceToggle === invoiceId) {
                        toggle.classList.remove('active');
                        toggle.setAttribute('aria-expanded', 'false');
                    }
                });
            });
        });

        document.querySelectorAll('.modal[data-auto-open="1"]').forEach(function (modalElement) {
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
            }
        });

        if (window.location.hash === '#maintenance-records') {
            var recordModal = document.getElementById('maintenanceRecordModal');
            if (recordModal && window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(recordModal).show();
            }
        }
    });
})();
