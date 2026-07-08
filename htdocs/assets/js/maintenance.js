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
