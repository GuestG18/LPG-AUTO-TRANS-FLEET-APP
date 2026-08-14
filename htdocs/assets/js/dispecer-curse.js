(function () {
    function parseNumber(value) {
        if (value === null || value === undefined) {
            return 0;
        }

        var normalized = String(value).trim().replace(/\s+/g, '').replace(',', '.');
        var number = parseFloat(normalized);

        if (!isFinite(number)) {
            return 0;
        }

        return number;
    }

    function formatCurrencyRo(value) {
        try {
            return Number(value).toLocaleString('ro-RO', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ' lei';
        } catch (error) {
            return String(value.toFixed(2)).replace('.', ',') + ' lei';
        }
    }

    function formatCostPerKmRo(value) {
        try {
            return Number(value).toLocaleString('ro-RO', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ' lei/km';
        } catch (error) {
            return String(value.toFixed(2)).replace('.', ',') + ' lei/km';
        }
    }

    function roundToTwo(value) {
        var normalized = parseNumber(value);
        return Math.round(normalized * 100) / 100;
    }

    function safeDivide(numerator, denominator) {
        var denominatorValue = parseNumber(denominator);
        if (denominatorValue <= 0) {
            return 0;
        }

        return parseNumber(numerator) / denominatorValue;
    }

    function initTableMiddleMouseScroll() {
        var scrollWraps = document.querySelectorAll('.dispatcher-races-table-wrap, .table-container');
        if (!scrollWraps.length) {
            return;
        }

        var activeState = null;
        var animationFrame = 0;
        var previousUserSelect = '';
        var previousCursor = '';
        var suppressNextClick = false;
        var indicator = null;
        var ignoreSelector = 'a, button, input, select, textarea, label, [role="button"], [data-bs-toggle]';

        var stop = function () {
            if (animationFrame) {
                window.cancelAnimationFrame(animationFrame);
                animationFrame = 0;
            }

            if (activeState !== null) {
                document.body.style.userSelect = previousUserSelect;
                document.body.style.cursor = previousCursor;
            }
            if (indicator !== null) {
                indicator.remove();
                indicator = null;
            }

            activeState = null;
        };

        var showIndicator = function (x, y) {
            if (indicator !== null) {
                indicator.remove();
            }

            indicator = document.createElement('div');
            indicator.className = 'dispatcher-middle-scroll-indicator';
            indicator.style.left = x + 'px';
            indicator.style.top = y + 'px';
            document.body.appendChild(indicator);
        };

        var clampSpeed = function (value) {
            var deadZone = 12;
            if (Math.abs(value) <= deadZone) {
                return 0;
            }

            var direction = value < 0 ? -1 : 1;
            return Math.max(-34, Math.min(34, direction * ((Math.abs(value) - deadZone) * 0.18)));
        };

        var tick = function () {
            if (activeState === null) {
                return;
            }

            var horizontalSpeed = clampSpeed(activeState.currentX - activeState.originX);
            var verticalSpeed = clampSpeed(activeState.currentY - activeState.originY);

            if (horizontalSpeed !== 0) {
                activeState.wrapper.scrollLeft += horizontalSpeed;
            }
            if (verticalSpeed !== 0) {
                window.scrollBy(0, verticalSpeed);
            }

            animationFrame = window.requestAnimationFrame(tick);
        };

        scrollWraps.forEach(function (wrapper) {
            if (!(wrapper instanceof HTMLElement) || wrapper.dataset.middleMouseScrollBound === '1') {
                return;
            }

            wrapper.addEventListener('mousedown', function (event) {
                var eventTarget = event.target instanceof Element
                    ? event.target
                    : (event.target && event.target.parentElement instanceof Element ? event.target.parentElement : null);
                if (event.button !== 1 || (eventTarget !== null && eventTarget.closest(ignoreSelector) !== null)) {
                    return;
                }
                if (wrapper.scrollWidth <= wrapper.clientWidth && document.documentElement.scrollHeight <= window.innerHeight) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();

                stop();
                previousUserSelect = document.body.style.userSelect;
                previousCursor = document.body.style.cursor;
                document.body.style.userSelect = 'none';
                document.body.style.cursor = 'all-scroll';

                activeState = {
                    wrapper: wrapper,
                    originX: event.clientX,
                    originY: event.clientY,
                    currentX: event.clientX,
                    currentY: event.clientY
                };

                showIndicator(event.clientX, event.clientY);
                animationFrame = window.requestAnimationFrame(tick);
            });

            wrapper.addEventListener('auxclick', function (event) {
                if (event.button === 1 && activeState !== null) {
                    event.preventDefault();
                }
            });

            wrapper.dataset.middleMouseScrollBound = '1';
        });

        document.addEventListener('mousemove', function (event) {
            if (activeState === null) {
                return;
            }

            activeState.currentX = event.clientX;
            activeState.currentY = event.clientY;
        });

        document.addEventListener('mousedown', function (event) {
            if (activeState === null) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            suppressNextClick = true;
            stop();
        }, true);

        document.addEventListener('click', function (event) {
            if (!suppressNextClick) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            suppressNextClick = false;
        }, true);

        document.addEventListener('auxclick', function (event) {
            if (event.button === 1 && (activeState !== null || suppressNextClick)) {
                event.preventDefault();
                event.stopPropagation();
                suppressNextClick = false;
            }
        }, true);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                stop();
            }
        });

        window.addEventListener('blur', stop);
    }

    function normalizeTonInputToKgForPricing(value, vehicleCapacityTon) {
        var normalizedValue = parseNumber(value);
        if (!isFinite(normalizedValue) || normalizedValue <= 0) {
            return 0;
        }
        // Valorile sunt folosite direct in tone, fara conversie automata la kg.
        return normalizedValue;
    }

    function initRaceForm(form) {
        var tipField = form.querySelector('[data-role="tip-transport"]');
        var beneficiaryField = form.querySelector('[name="beneficiar_id"]');
        var configTransportLink = document.querySelector('[data-role="config-transport-link"]');
        var goodsTypeDropdown = form.querySelector('[data-role="goods-type-dropdown"]');
        var vehicleField = form.querySelector('[name="vehicle_id"]');
        var driverField = form.querySelector('[name="driver_id"]');
        var loadLocationField = form.querySelector('[name="loc_incarcare_id"]');
        var departureLocationField = form.querySelector('[data-role="loc-plecare"]');
        var suctionLocationField = form.querySelector('[data-role="loc-aspirare"]');
        var deliveryLocationField = form.querySelector('[data-role="loc-livrare"]');
        var raceDeliveryLocationField = form.querySelector('[data-role="loc-livrare-cursa"]');
        var kmField = form.querySelector('[data-role="km"]');
        var kmTotalField = form.querySelector('[data-role="km-totali"]');
        var quantityField = form.querySelector('[data-role="cantitate"]');
        var clientsField = form.querySelector('[name="nr_clienti"]');
        var zoneField = form.querySelector('[data-role="zona"]');
        var suctionHoursField = form.querySelector('[data-role="ore-aspirare"]');
        var relocationKmField = form.querySelector('[data-role="km-dislocare"]');
        var deliveredTonField = form.querySelector('[data-role="tona-livrata"]');
        var suctionLiquidTonField = form.querySelector('[data-role="tona-aspirata-lichida"]');
        var suctionGasTonField = form.querySelector('[data-role="tona-aspirata-gazoasa"]');
        var transportCapacityField = form.querySelector('[data-role="capacitate-transport"]');
        var startDateField = form.querySelector('[name="data_inceput"]');
        var endDateField = form.querySelector('[name="data_sfarsit"]');
        var raceDateFields = form.querySelectorAll('[data-role="race-date-ro"]');
        var startTimeField = form.querySelector('[data-role="ora-inceput"]');
        var endTimeField = form.querySelector('[data-role="ora-sfarsit"]');
        var timeNowButtonEls = form.querySelectorAll('[data-role="time-now"]');
        var startDateTimeField = form.querySelector('[data-role="start-datetime-field"]');
        var startDateTimeDisplayField = form.querySelector('[data-role="start-datetime-display"]');
        var startDateTimeToggleButton = form.querySelector('[data-role="start-datetime-toggle"]');
        var startDateTimePopover = form.querySelector('[data-role="start-datetime-popover"]');
        var endDateTimeField = form.querySelector('[data-role="end-datetime-field"]');
        var endDateTimeDisplayField = form.querySelector('[data-role="end-datetime-display"]');
        var endDateTimeToggleButton = form.querySelector('[data-role="end-datetime-toggle"]');
        var endDateTimePopover = form.querySelector('[data-role="end-datetime-popover"]');
        var durationHintField = form.querySelector('[data-role="durata-cursa-hint"]');
        var distributionLocationNote = form.querySelector('[data-role="distributie-note-loc"]');
        var distributionZoneNote = form.querySelector('[data-role="distributie-note-zone"]');
        var primaryLocationNote = form.querySelector('[data-role="primar-note-loc"]');
        var primaryZoneNote = form.querySelector('[data-role="primar-note-zone"]');
        var zoneLabel = form.querySelector('[data-role="zona-label"]');
        var kmLabel = form.querySelector('[data-role="km-label"]');
        var kmTotalLabel = form.querySelector('[data-role="km-total-label"]');
        var priceDisplayField = form.querySelector('[data-role="pret-calc"]');
        var totalPreview = form.querySelector('[data-role="total-preview"]');
        var costKmPrimarPreview = form.querySelector('[data-role="cost-km-primar-preview"]');
        var costKmDistributiePreview = form.querySelector('[data-role="cost-km-distributie-preview"]');
        var costKmMixtPreview = form.querySelector('[data-role="cost-km-mixt-preview"]');
        var totalPreviewField = form.querySelector('[data-role="preview-total-field"]');
        var costKmPrimarPreviewField = form.querySelector('[data-role="preview-cost-km-primar-field"]');
        var costKmDistributiePreviewField = form.querySelector('[data-role="preview-cost-km-distributie-field"]');
        var costKmMixtPreviewField = form.querySelector('[data-role="preview-cost-km-mixt-field"]');
        var kmDistributionCalculationNote = form.querySelector('[data-role="km-distributie-calculation"]');
        var costKmMixtCalculationNote = form.querySelector('[data-role="cost-km-mixt-calculation"]');
        var startDateTimePickerState = {
            view: 'date',
            viewedYear: null,
            viewedMonth: null,
            selectedDateParts: null,
            selectedHour: null,
            selectedMinute: null
        };
        var endDateTimePickerState = {
            view: 'date',
            viewedYear: null,
            viewedMonth: null,
            selectedDateParts: null,
            selectedHour: null,
            selectedMinute: null
        };
        var activeDateTimePicker = null;
        var dateTimePickers = [];

        if (!(costKmMixtCalculationNote instanceof HTMLElement) && costKmMixtPreviewField instanceof HTMLElement) {
            costKmMixtCalculationNote = document.createElement('div');
            costKmMixtCalculationNote.className = 'form-text text-muted d-none';
            costKmMixtCalculationNote.setAttribute('data-role', 'cost-km-mixt-calculation');
            costKmMixtCalculationNote.textContent = 'Cost/km Mixt (calcul): Total facturare estimata / Km efectuati.';
            costKmMixtPreviewField.appendChild(costKmMixtCalculationNote);
        }

        if (!tipField || !beneficiaryField || !totalPreview) {
            return;
        }

        var invoicedRefacturareTotal = Math.max(0, parseNumber(form.getAttribute('data-invoiced-refacturare-total') || '0'));

        function syncConfigTransportLink() {
            if (!(configTransportLink instanceof HTMLAnchorElement)) {
                return;
            }

            var baseHref = String(configTransportLink.getAttribute('data-base-href') || configTransportLink.getAttribute('href') || '').trim();
            if (baseHref === '') {
                return;
            }

            var beneficiaryId = String(beneficiaryField.value || '').trim();
            var transportType = String(tipField.value || '').trim();

            try {
                var url = new URL(baseHref, window.location.origin);
                if (beneficiaryId !== '') {
                    url.searchParams.set('beneficiar_edit_id', beneficiaryId);
                } else {
                    url.searchParams.delete('beneficiar_edit_id');
                }

                if (transportType === 'compresor') {
                    url.searchParams.set('transport_focus', 'compresor');
                } else {
                    url.searchParams.delete('transport_focus');
                }

                configTransportLink.href = url.pathname + url.search + url.hash;
            } catch (error) {
                configTransportLink.href = baseHref;
            }
        }

        var kmWrapper = form.querySelector('[data-role="field-km"]');
        var loadLocationWrapper = form.querySelector('[data-role="field-loc-incarcare"]');
        var departureLocationWrapper = form.querySelector('[data-role="field-loc-plecare"]');
        var suctionLocationWrapper = form.querySelector('[data-role="field-loc-aspirare"]');
        var deliveryLocationWrapper = form.querySelector('[data-role="field-loc-livrare"]');
        var raceDeliveryLocationWrapper = form.querySelector('[data-role="field-loc-livrare-cursa"]');
        var quantityWrapper = form.querySelector('[data-role="field-cantitate"]');
        var clientsWrapper = form.querySelector('[data-role="field-nr-clienti"]');
        var transportCapacityWrapper = form.querySelector('[data-role="field-capacitate-transport"]');
        var zoneWrapper = form.querySelector('[data-role="field-zona"]');
        var kmTotalWrapper = form.querySelector('[data-role="field-km-totali"]');
        var suctionHoursWrapper = form.querySelector('[data-role="field-ore-aspirare"]');
        var relocationKmWrapper = form.querySelector('[data-role="field-km-dislocare"]');
        var deliveredTonWrapper = form.querySelector('[data-role="field-tona-livrata"]');
        var suctionLiquidTonWrapper = form.querySelector('[data-role="field-tona-aspirata-lichida"]');
        var suctionGasTonWrapper = form.querySelector('[data-role="field-tona-aspirata-gazoasa"]');

        var beneficiaryPricing = {};
        try {
            beneficiaryPricing = JSON.parse(form.getAttribute('data-beneficiary-pricing') || '{}') || {};
        } catch (error) {
            beneficiaryPricing = {};
        }

        var loadLocationTariffs = {};
        try {
            loadLocationTariffs = JSON.parse(form.getAttribute('data-load-location-tariffs') || '{}') || {};
        } catch (error) {
            loadLocationTariffs = {};
        }

        var zoneTariffs = {};
        try {
            zoneTariffs = JSON.parse(form.getAttribute('data-zone-tariffs') || '{}') || {};
        } catch (error) {
            zoneTariffs = {};
        }

        var zoneExtraKmCosts = {};
        try {
            zoneExtraKmCosts = JSON.parse(form.getAttribute('data-zone-extra-km-costs') || '{}') || {};
        } catch (error) {
            zoneExtraKmCosts = {};
        }

        var distributionRouteTariffs = {};
        try {
            distributionRouteTariffs = JSON.parse(form.getAttribute('data-distribution-route-tariffs') || '{}') || {};
        } catch (error) {
            distributionRouteTariffs = {};
        }

        var primaryRouteKmMap = {};
        try {
            primaryRouteKmMap = JSON.parse(form.getAttribute('data-primary-route-km-map') || '{}') || {};
        } catch (error) {
            primaryRouteKmMap = {};
        }

        var vehicleDefaultLoadLocations = {};
        try {
            vehicleDefaultLoadLocations = JSON.parse(form.getAttribute('data-vehicle-default-load-locations') || '{}') || {};
        } catch (error) {
            vehicleDefaultLoadLocations = {};
        }

        var vehicleDefaultDistributionZones = {};
        try {
            vehicleDefaultDistributionZones = JSON.parse(form.getAttribute('data-vehicle-default-distribution-zones') || '{}') || {};
        } catch (error) {
            vehicleDefaultDistributionZones = {};
        }

        var vehicleGarages = {};
        try {
            vehicleGarages = JSON.parse(form.getAttribute('data-vehicle-garages') || '{}') || {};
        } catch (error) {
            vehicleGarages = {};
        }

        var loadLocationsByBeneficiary = {};
        try {
            loadLocationsByBeneficiary = JSON.parse(form.getAttribute('data-load-locations-by-beneficiary') || '{}') || {};
        } catch (error) {
            loadLocationsByBeneficiary = {};
        }

        var distributionZonesByBeneficiary = {};
        try {
            distributionZonesByBeneficiary = JSON.parse(form.getAttribute('data-distribution-zones-by-beneficiary') || '{}') || {};
        } catch (error) {
            distributionZonesByBeneficiary = {};
        }

        function buildEntityNameMap(groupedEntities) {
            var map = {};
            if (!groupedEntities || typeof groupedEntities !== 'object') {
                return map;
            }

            Object.keys(groupedEntities).forEach(function (beneficiaryId) {
                var items = groupedEntities[beneficiaryId];
                if (!Array.isArray(items)) {
                    return;
                }

                items.forEach(function (item) {
                    var entityId = String(item && item.id ? item.id : '');
                    var entityName = String(item && item.nume ? item.nume : '').trim();
                    if (entityId === '' || entityName === '') {
                        return;
                    }

                    if (!Object.prototype.hasOwnProperty.call(map, entityId)) {
                        map[entityId] = entityName;
                    }
                });
            });

            return map;
        }

        var loadLocationNamesById = buildEntityNameMap(loadLocationsByBeneficiary);
        var zoneNamesById = buildEntityNameMap(distributionZonesByBeneficiary);

        function buildEntityNameIndexByBeneficiary(groupedEntities) {
            var map = {};
            if (!groupedEntities || typeof groupedEntities !== 'object') {
                return map;
            }

            Object.keys(groupedEntities).forEach(function (beneficiaryId) {
                var items = groupedEntities[beneficiaryId];
                if (!Array.isArray(items)) {
                    return;
                }

                if (!Object.prototype.hasOwnProperty.call(map, beneficiaryId)) {
                    map[beneficiaryId] = {};
                }

                items.forEach(function (item) {
                    var entityId = String(item && item.id ? item.id : '').trim();
                    var entityName = normalizeMatchText(String(item && item.nume ? item.nume : ''));
                    if (entityId === '' || entityName === '') {
                        return;
                    }

                    if (!Object.prototype.hasOwnProperty.call(map[beneficiaryId], entityName)) {
                        map[beneficiaryId][entityName] = [];
                    }

                    if (map[beneficiaryId][entityName].indexOf(entityId) === -1) {
                        map[beneficiaryId][entityName].push(entityId);
                    }
                });
            });

            return map;
        }

        function getNameIndexedEntityIds(indexedMap, beneficiaryId, normalizedName) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            if (
                beneficiaryKey === ''
                || normalizedName === ''
                || !Object.prototype.hasOwnProperty.call(indexedMap, beneficiaryKey)
                || !Object.prototype.hasOwnProperty.call(indexedMap[beneficiaryKey] || {}, normalizedName)
            ) {
                return [];
            }

            var values = indexedMap[beneficiaryKey][normalizedName];
            return Array.isArray(values) ? values.slice() : [];
        }

        var loadLocationIdsByBeneficiaryName = buildEntityNameIndexByBeneficiary(loadLocationsByBeneficiary);
        var zoneIdsByBeneficiaryName = buildEntityNameIndexByBeneficiary(distributionZonesByBeneficiary);

        var vehicleDefaultLoadLocationsByBeneficiary = {};
        try {
            vehicleDefaultLoadLocationsByBeneficiary = JSON.parse(form.getAttribute('data-vehicle-default-load-locations-by-beneficiary') || '{}') || {};
        } catch (error) {
            vehicleDefaultLoadLocationsByBeneficiary = {};
        }

        var vehicleDefaultDistributionZonesByBeneficiary = {};
        try {
            vehicleDefaultDistributionZonesByBeneficiary = JSON.parse(form.getAttribute('data-vehicle-default-distribution-zones-by-beneficiary') || '{}') || {};
        } catch (error) {
            vehicleDefaultDistributionZonesByBeneficiary = {};
        }

        var compressorVehiclesByBeneficiary = {};
        try {
            compressorVehiclesByBeneficiary = JSON.parse(form.getAttribute('data-compresor-vehicles-by-beneficiary') || '{}') || {};
        } catch (error) {
            compressorVehiclesByBeneficiary = {};
        }

        var initialLoadLocationOptions = [];
        if (loadLocationField instanceof HTMLSelectElement) {
            initialLoadLocationOptions = Array.prototype.slice.call(loadLocationField.options).map(function (option) {
                return { value: String(option.value || ''), label: String(option.textContent || '') };
            });
        }

        var initialZoneOptions = [];
        if (zoneField instanceof HTMLSelectElement) {
            initialZoneOptions = Array.prototype.slice.call(zoneField.options).map(function (option) {
                return { value: String(option.value || ''), label: String(option.textContent || '') };
            });
        }

        var initialVehicleOptions = [];
        if (vehicleField instanceof HTMLSelectElement) {
            initialVehicleOptions = Array.prototype.slice.call(vehicleField.options).map(function (option) {
                return {
                    value: String(option.value || ''),
                    label: String(option.textContent || ''),
                    capacity: String(option.getAttribute('data-capacitate-transport') || '').trim()
                };
            });
        }

        var activeDriverVehicleSet = {};
        try {
            var activeDriverVehicleIdsRaw = JSON.parse(form.getAttribute('data-active-driver-vehicle-ids') || '[]') || [];
            if (Array.isArray(activeDriverVehicleIdsRaw)) {
                activeDriverVehicleIdsRaw.forEach(function (vehicleIdRaw) {
                    var vehicleId = String(vehicleIdRaw || '').trim();
                    if (vehicleId !== '') {
                        activeDriverVehicleSet[vehicleId] = true;
                    }
                });
            }
        } catch (error) {
            activeDriverVehicleSet = {};
        }

        var driversByVehicle = {};
        try {
            driversByVehicle = JSON.parse(form.getAttribute('data-drivers-by-vehicle') || '{}') || {};
        } catch (error) {
            driversByVehicle = {};
        }

        // Sandbox: lista tuturor soferilor activi, pentru optiunea "Alt sofer" din formular.
        var allDrivers = [];
        try {
            allDrivers = JSON.parse(form.getAttribute('data-all-drivers') || '[]') || [];
        } catch (error) {
            allDrivers = [];
        }
        var SHOW_ALL_DRIVERS_VALUE = '__show_all_drivers__';
        var driverListExpanded = false;
        var driverListVehicleId = '';

        // Sandbox: optiunea "Alt vehicul" — vehicule neconfigurate pe ruta, cu decizie admin.
        var SHOW_ALL_VEHICLES_VALUE = '__show_all_vehicles__';
        var vehicleListExpanded = false;
        var vehicleListContextKey = '';
        var lastEligibleVehicleSet = {};

        var defaultDistributionLocationNoteText = distributionLocationNote
            ? String(distributionLocationNote.textContent || '').trim()
            : '';
        var defaultDistributionZoneNoteText = distributionZoneNote
            ? String(distributionZoneNote.textContent || '').trim()
            : '';
        var defaultPrimaryLocationNoteText = primaryLocationNote
            ? String(primaryLocationNote.textContent || '').trim()
            : '';
        var defaultPrimaryZoneNoteText = primaryZoneNote
            ? String(primaryZoneNote.textContent || '').trim()
            : '';
        var defaultDurationHintText = durationHintField
            ? String(durationHintField.getAttribute('data-default-text') || durationHintField.textContent || '').trim()
            : '';
        var defaultLoadLocationTitle = loadLocationField
            ? String(loadLocationField.getAttribute('title') || '').trim()
            : '';
        var defaultZoneTitle = zoneField
            ? String(zoneField.getAttribute('title') || '').trim()
            : '';
        var defaultEndTimeTitle = endTimeField
            ? String(endTimeField.getAttribute('title') || '').trim()
            : '';

        function setNativeHoverTitle(field, text) {
            if (!(field instanceof HTMLElement)) {
                return;
            }

            var normalizedText = String(text || '').trim();
            if (normalizedText === '') {
                field.removeAttribute('title');
                return;
            }

            field.setAttribute('title', normalizedText);
        }

        function syncFieldHoverHints(transportType) {
            var currentTransportType = String(transportType || (tipField ? tipField.value : '') || '').trim();
            var locationHintText = defaultLoadLocationTitle;
            var zoneHintText = defaultZoneTitle;

            if (isDistributionTransport(currentTransportType)) {
                locationHintText = distributionLocationNote
                    ? String(distributionLocationNote.textContent || '').trim()
                    : locationHintText;
                zoneHintText = distributionZoneNote
                    ? String(distributionZoneNote.textContent || '').trim()
                    : zoneHintText;
            } else if (isPrimaryTransport(currentTransportType)) {
                locationHintText = primaryLocationNote
                    ? String(primaryLocationNote.textContent || '').trim()
                    : locationHintText;
                zoneHintText = primaryZoneNote
                    ? String(primaryZoneNote.textContent || '').trim()
                    : zoneHintText;
            }

            setNativeHoverTitle(loadLocationField, locationHintText);
            setNativeHoverTitle(zoneField, zoneHintText);
        }

        function parseDateForDuration(rawDate) {
            var value = String(rawDate || '').trim();
            var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
            if (!match) {
                var displayMatch = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/.exec(value);
                if (displayMatch) {
                    match = [
                        displayMatch[0],
                        displayMatch[3],
                        displayMatch[2].padStart(2, '0'),
                        displayMatch[1].padStart(2, '0')
                    ];
                }
            }
            if (!match) {
                return null;
            }

            var year = parseInt(match[1], 10);
            var month = parseInt(match[2], 10);
            var day = parseInt(match[3], 10);
            if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) {
                return null;
            }

            var date = new Date(year, month - 1, day);
            if (
                date.getFullYear() !== year
                || date.getMonth() !== (month - 1)
                || date.getDate() !== day
            ) {
                return null;
            }

            return { year: year, month: month, day: day };
        }

        function padDateComponent(value) {
            var number = parseInt(value, 10);
            if (!Number.isFinite(number) || number < 0) {
                return '00';
            }

            return (number < 10 ? '0' : '') + String(number);
        }

        function formatDateForDisplay(dateParts) {
            if (!dateParts) {
                return '';
            }

            return padDateComponent(dateParts.day) + '/' + padDateComponent(dateParts.month) + '/' + String(dateParts.year);
        }

        function normalizeRaceDateFieldValue(field) {
            if (!(field instanceof HTMLInputElement)) {
                return;
            }

            var value = String(field.value || '').trim();
            if (value === '') {
                field.value = '';
                return;
            }

            var dateParts = parseDateForDuration(value);
            if (dateParts !== null) {
                field.value = formatDateForDisplay(dateParts);
            }
        }

        function parseTimeForDuration(rawTime) {
            var value = String(rawTime || '').trim();
            var match = /^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/.exec(value);
            if (!match) {
                return null;
            }

            var hours = parseInt(match[1], 10);
            var minutes = parseInt(match[2], 10);
            if (!Number.isFinite(hours) || !Number.isFinite(minutes)) {
                return null;
            }

            return { hours: hours, minutes: minutes };
        }

        function padTimeComponent(value) {
            var number = parseInt(value, 10);
            if (!Number.isFinite(number) || number < 0) {
                return '00';
            }

            return (number < 10 ? '0' : '') + String(number);
        }

        function normalizeTimeInputValue(rawValue) {
            var value = String(rawValue || '').trim();
            if (value === '') {
                return '';
            }

            value = value.replace(/\s+/g, '');

            var hours = null;
            var minutes = null;
            var separatorMatch = /^(\d{1,2})[:.,;hH](\d{1,2})$/.exec(value);
            if (separatorMatch) {
                hours = parseInt(separatorMatch[1], 10);
                minutes = parseInt(separatorMatch[2], 10);
            } else if (/^\d{1,2}$/.test(value)) {
                hours = parseInt(value, 10);
                minutes = 0;
            } else if (/^\d{3,4}$/.test(value)) {
                hours = parseInt(value.slice(0, -2), 10);
                minutes = parseInt(value.slice(-2), 10);
            } else {
                return null;
            }

            if (!Number.isFinite(hours) || !Number.isFinite(minutes)) {
                return null;
            }

            if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) {
                return null;
            }

            return padTimeComponent(hours) + ':' + padTimeComponent(minutes);
        }

        function normalizeTimeFieldValue(field) {
            if (!(field instanceof HTMLInputElement)) {
                return;
            }

            var normalized = normalizeTimeInputValue(field.value);
            if (normalized === '') {
                field.value = '';
                return;
            }

            if (normalized !== null) {
                field.value = normalized;
            }
        }

        function applyCurrentTime(field) {
            if (!(field instanceof HTMLInputElement)) {
                return;
            }

            var now = new Date();
            field.value = padTimeComponent(now.getHours()) + ':' + padTimeComponent(now.getMinutes());
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
            field.focus();
        }

        function dispatchFieldUpdate(field) {
            if (!(field instanceof HTMLInputElement)) {
                return;
            }

            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function createDateTimePickerContext(field, displayField, toggleButton, popover, dateField, timeField, state) {
            if (
                !(field instanceof HTMLElement)
                || !(displayField instanceof HTMLInputElement)
                || !(toggleButton instanceof HTMLButtonElement)
                || !(popover instanceof HTMLElement)
                || !(dateField instanceof HTMLInputElement)
                || !(timeField instanceof HTMLInputElement)
            ) {
                return null;
            }

            return {
                field: field,
                displayField: displayField,
                toggleButton: toggleButton,
                popover: popover,
                dateField: dateField,
                timeField: timeField,
                state: state
            };
        }

        function getActiveDateTimePicker() {
            return activeDateTimePicker || dateTimePickers[0] || null;
        }

        function setActiveDateTimePicker(dateTimePicker) {
            if (!dateTimePicker) {
                return null;
            }

            activeDateTimePicker = dateTimePicker;
            return activeDateTimePicker;
        }

        function getTodayDateParts() {
            var now = new Date();

            return {
                year: now.getFullYear(),
                month: now.getMonth() + 1,
                day: now.getDate()
            };
        }

        function datePartsMatch(firstDateParts, secondDateParts) {
            return !!(
                firstDateParts
                && secondDateParts
                && firstDateParts.year === secondDateParts.year
                && firstDateParts.month === secondDateParts.month
                && firstDateParts.day === secondDateParts.day
            );
        }

        function formatDatePartsForDataset(dateParts) {
            if (!dateParts) {
                return '';
            }

            return String(dateParts.year) + '-' + padDateComponent(dateParts.month) + '-' + padDateComponent(dateParts.day);
        }

        function getPickerMonthLabels() {
            return [
                'Ianuarie',
                'Februarie',
                'Martie',
                'Aprilie',
                'Mai',
                'Iunie',
                'Iulie',
                'August',
                'Septembrie',
                'Octombrie',
                'Noiembrie',
                'Decembrie'
            ];
        }

        function getPickerShortMonthLabels() {
            return ['Ian', 'Feb', 'Mar', 'Apr', 'Mai', 'Iun', 'Iul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        }

        function formatStartDateTimeDisplayValue(dateRaw, timeRaw) {
            var dateValue = String(dateRaw || '').trim();
            var timeValue = String(timeRaw || '').trim();
            var dateParts = parseDateForDuration(dateValue);
            var normalizedTime = timeValue !== '' ? normalizeTimeInputValue(timeValue) : '';
            var dateDisplay = dateParts !== null ? formatDateForDisplay(dateParts) : dateValue;
            var timeDisplay = normalizedTime !== null ? normalizedTime : timeValue;

            return (dateDisplay + (timeDisplay !== '' ? ' ' + timeDisplay : '')).trim();
        }

        function setStartDateTimeDisplayInvalid(isInvalid) {
            if (!(getActiveDateTimePicker().displayField instanceof HTMLInputElement)) {
                return;
            }

            getActiveDateTimePicker().displayField.classList.toggle('is-invalid', !!isInvalid);
            if (isInvalid) {
                getActiveDateTimePicker().displayField.setAttribute('aria-invalid', 'true');
            } else {
                getActiveDateTimePicker().displayField.removeAttribute('aria-invalid');
            }
        }

        function parseStartDateTimeDisplayValue(rawValue) {
            var value = String(rawValue || '').trim().replace(/T/g, ' ').replace(/[,\s]+/g, ' ');
            if (value === '') {
                return null;
            }

            var valueParts = value.split(' ');
            if (valueParts.length > 2) {
                return null;
            }

            var dateParts = parseDateForDuration(valueParts[0]);
            if (dateParts === null) {
                return null;
            }

            var timeValue = '';
            if (valueParts.length === 2 && valueParts[1] !== '') {
                timeValue = normalizeTimeInputValue(valueParts[1]);
                if (timeValue === null) {
                    return null;
                }
            }

            return {
                dateParts: dateParts,
                timeValue: timeValue
            };
        }

        function applyStartDateTimeFieldValues(dateParts, timeValue) {
            if (dateParts && getActiveDateTimePicker().dateField instanceof HTMLInputElement) {
                getActiveDateTimePicker().dateField.value = formatDateForDisplay(dateParts);
                dispatchFieldUpdate(getActiveDateTimePicker().dateField);
            }

            if (getActiveDateTimePicker().timeField instanceof HTMLInputElement) {
                getActiveDateTimePicker().timeField.value = String(timeValue || '');
                dispatchFieldUpdate(getActiveDateTimePicker().timeField);
            }

            if (getActiveDateTimePicker().displayField instanceof HTMLInputElement) {
                getActiveDateTimePicker().displayField.value = formatStartDateTimeDisplayValue(
                    getActiveDateTimePicker().dateField instanceof HTMLInputElement ? getActiveDateTimePicker().dateField.value : '',
                    getActiveDateTimePicker().timeField instanceof HTMLInputElement ? getActiveDateTimePicker().timeField.value : ''
                );
                setStartDateTimeDisplayInvalid(false);
            }

            syncRaceDurationHint();
        }

        function syncStartDateTimeDisplayFromFields() {
            if (!(getActiveDateTimePicker().displayField instanceof HTMLInputElement)) {
                return;
            }

            getActiveDateTimePicker().displayField.value = formatStartDateTimeDisplayValue(
                getActiveDateTimePicker().dateField instanceof HTMLInputElement ? getActiveDateTimePicker().dateField.value : '',
                getActiveDateTimePicker().timeField instanceof HTMLInputElement ? getActiveDateTimePicker().timeField.value : ''
            );
        }

        function syncStartDateTimeFieldsFromDisplay(markInvalid) {
            if (!(getActiveDateTimePicker().displayField instanceof HTMLInputElement)) {
                return true;
            }

            var parsedValue = parseStartDateTimeDisplayValue(getActiveDateTimePicker().displayField.value);
            if (parsedValue === null) {
                if (markInvalid) {
                    setStartDateTimeDisplayInvalid(true);
                }
                return false;
            }

            applyStartDateTimeFieldValues(parsedValue.dateParts, parsedValue.timeValue);
            return true;
        }

        function syncStartDateTimePickerStateFromFields() {
            var selectedDateParts = parseDateForDuration(getActiveDateTimePicker().dateField instanceof HTMLInputElement ? getActiveDateTimePicker().dateField.value : '');
            var selectedTimeParts = parseTimeForDuration(getActiveDateTimePicker().timeField instanceof HTMLInputElement ? getActiveDateTimePicker().timeField.value : '');
            var fallbackDateParts = selectedDateParts || getTodayDateParts();

            getActiveDateTimePicker().state.selectedDateParts = selectedDateParts;
            getActiveDateTimePicker().state.selectedHour = selectedTimeParts ? selectedTimeParts.hours : null;
            getActiveDateTimePicker().state.selectedMinute = selectedTimeParts ? selectedTimeParts.minutes : null;
            getActiveDateTimePicker().state.viewedYear = fallbackDateParts.year;
            getActiveDateTimePicker().state.viewedMonth = fallbackDateParts.month;
        }

        function clearDateTimePickerElement(element) {
            while (element.firstChild) {
                element.removeChild(element.firstChild);
            }
        }

        function createDateTimePickerIconButton(action, label, iconName) {
            var button = document.createElement('button');
            var icon = document.createElement('i');

            button.type = 'button';
            button.className = 'dispatcher-datetime-picker-icon-btn';
            button.setAttribute('data-datetime-action', action);
            button.setAttribute('aria-label', label);
            button.title = label;
            icon.className = 'bi ' + iconName;
            icon.setAttribute('aria-hidden', 'true');
            button.appendChild(icon);

            return button;
        }

        function renderStartDateTimePickerHeader(titleText, leftButton, rightButton, titleAction) {
            var header = document.createElement('div');
            var title = titleAction ? document.createElement('button') : document.createElement('div');
            var left = leftButton || document.createElement('span');
            var right = rightButton || document.createElement('span');

            header.className = 'dispatcher-datetime-picker-header';
            title.className = 'dispatcher-datetime-picker-title';
            title.textContent = titleText;
            if (titleAction) {
                title.type = 'button';
                title.setAttribute('data-datetime-action', titleAction);
                title.setAttribute('aria-label', titleText);
            }
            if (!leftButton) {
                left.className = 'dispatcher-datetime-picker-header-spacer';
            }
            if (!rightButton) {
                right.className = 'dispatcher-datetime-picker-header-spacer';
            }

            header.appendChild(left);
            header.appendChild(title);
            header.appendChild(right);

            return header;
        }

        function renderStartDateTimeDateView() {
            var monthLabels = getPickerMonthLabels();
            var weekdayLabels = ['Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sa', 'Du'];
            var todayDateParts = getTodayDateParts();
            var selectedDateParts = getActiveDateTimePicker().state.selectedDateParts;
            var viewedYear = getActiveDateTimePicker().state.viewedYear || todayDateParts.year;
            var viewedMonth = getActiveDateTimePicker().state.viewedMonth || todayDateParts.month;
            var firstDay = new Date(viewedYear, viewedMonth - 1, 1);
            var firstOffset = (firstDay.getDay() + 6) % 7;
            var gridStart = new Date(viewedYear, viewedMonth - 1, 1 - firstOffset);
            var header = renderStartDateTimePickerHeader(
                monthLabels[viewedMonth - 1] + ' ' + String(viewedYear),
                createDateTimePickerIconButton('prev-month', 'Luna precedenta', 'bi-chevron-left'),
                createDateTimePickerIconButton('next-month', 'Luna urmatoare', 'bi-chevron-right'),
                'show-month'
            );
            var weekdays = document.createElement('div');
            var daysGrid = document.createElement('div');
            var footer = document.createElement('div');

            getActiveDateTimePicker().popover.appendChild(header);

            weekdays.className = 'dispatcher-datetime-weekdays';
            weekdayLabels.forEach(function (label) {
                var weekday = document.createElement('span');
                weekday.textContent = label;
                weekdays.appendChild(weekday);
            });
            getActiveDateTimePicker().popover.appendChild(weekdays);

            daysGrid.className = 'dispatcher-datetime-days';
            for (var dayIndex = 0; dayIndex < 42; dayIndex += 1) {
                var cellDate = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + dayIndex);
                var cellDateParts = {
                    year: cellDate.getFullYear(),
                    month: cellDate.getMonth() + 1,
                    day: cellDate.getDate()
                };
                var dayButton = document.createElement('button');

                dayButton.type = 'button';
                dayButton.className = 'dispatcher-datetime-day';
                if (cellDateParts.month !== viewedMonth) {
                    dayButton.classList.add('is-muted');
                }
                if (datePartsMatch(cellDateParts, todayDateParts)) {
                    dayButton.classList.add('is-today');
                }
                if (datePartsMatch(cellDateParts, selectedDateParts)) {
                    dayButton.classList.add('is-selected');
                    dayButton.setAttribute('aria-pressed', 'true');
                }
                dayButton.textContent = String(cellDateParts.day);
                dayButton.setAttribute('data-datetime-date', formatDatePartsForDataset(cellDateParts));
                daysGrid.appendChild(dayButton);
            }
            getActiveDateTimePicker().popover.appendChild(daysGrid);

            footer.className = 'dispatcher-datetime-picker-footer';
            footer.appendChild(createDateTimePickerIconButton('show-time', 'Alege ora', 'bi-clock'));
            getActiveDateTimePicker().popover.appendChild(footer);
        }

        function getPickerSelectedDateParts() {
            return getActiveDateTimePicker().state.selectedDateParts
                || parseDateForDuration(getActiveDateTimePicker().dateField instanceof HTMLInputElement ? getActiveDateTimePicker().dateField.value : '')
                || getTodayDateParts();
        }

        function getPickerSelectedHour() {
            return getActiveDateTimePicker().state.selectedHour === null ? 0 : getActiveDateTimePicker().state.selectedHour;
        }

        function getPickerSelectedMinute() {
            return getActiveDateTimePicker().state.selectedMinute === null ? 0 : getActiveDateTimePicker().state.selectedMinute;
        }

        function applyPickerTime(hourValue, minuteValue) {
            var selectedDateParts = getPickerSelectedDateParts();

            getActiveDateTimePicker().state.selectedDateParts = selectedDateParts;
            getActiveDateTimePicker().state.selectedHour = Math.max(0, Math.min(23, hourValue));
            getActiveDateTimePicker().state.selectedMinute = Math.max(0, Math.min(59, minuteValue));
            applyStartDateTimeFieldValues(
                selectedDateParts,
                padTimeComponent(getActiveDateTimePicker().state.selectedHour) + ':' + padTimeComponent(getActiveDateTimePicker().state.selectedMinute)
            );
        }

        function renderStartDateTimeModeSwitch(action, label, iconName) {
            var switcher = document.createElement('div');

            switcher.className = 'dispatcher-datetime-mode-switch';
            switcher.appendChild(createDateTimePickerIconButton(action, label, iconName));

            return switcher;
        }

        function renderStartDateTimeSpinnerView() {
            var hourValue = getPickerSelectedHour();
            var minuteValue = getPickerSelectedMinute();
            var spinner = document.createElement('div');
            var hourUp = createDateTimePickerIconButton('hour-up', 'Creste ora', 'bi-chevron-up');
            var minuteUp = createDateTimePickerIconButton('minute-up', 'Creste minutele', 'bi-chevron-up');
            var hourDown = createDateTimePickerIconButton('hour-down', 'Scade ora', 'bi-chevron-down');
            var minuteDown = createDateTimePickerIconButton('minute-down', 'Scade minutele', 'bi-chevron-down');
            var hourDisplay = document.createElement('button');
            var minuteDisplay = document.createElement('button');
            var separator = document.createElement('span');

            getActiveDateTimePicker().popover.appendChild(renderStartDateTimeModeSwitch('show-date', 'Alege data', 'bi-calendar3'));

            spinner.className = 'dispatcher-datetime-spinner';
            hourDisplay.type = 'button';
            hourDisplay.className = 'dispatcher-datetime-spinner-value';
            hourDisplay.textContent = padTimeComponent(hourValue);
            hourDisplay.setAttribute('data-datetime-action', 'show-hour');
            hourDisplay.setAttribute('aria-label', 'Alege ora');

            minuteDisplay.type = 'button';
            minuteDisplay.className = 'dispatcher-datetime-spinner-value';
            minuteDisplay.textContent = padTimeComponent(minuteValue);
            minuteDisplay.setAttribute('data-datetime-action', 'show-minute');
            minuteDisplay.setAttribute('aria-label', 'Alege minutele');

            separator.className = 'dispatcher-datetime-spinner-separator';
            separator.textContent = ':';

            spinner.appendChild(hourUp);
            spinner.appendChild(document.createElement('span'));
            spinner.appendChild(minuteUp);
            spinner.appendChild(hourDisplay);
            spinner.appendChild(separator);
            spinner.appendChild(minuteDisplay);
            spinner.appendChild(hourDown);
            spinner.appendChild(document.createElement('span'));
            spinner.appendChild(minuteDown);
            getActiveDateTimePicker().popover.appendChild(spinner);
        }

        function renderStartDateTimeMonthView() {
            var monthLabels = getPickerShortMonthLabels();
            var todayDateParts = getTodayDateParts();
            var viewedYear = getActiveDateTimePicker().state.viewedYear || todayDateParts.year;
            var selectedDateParts = getActiveDateTimePicker().state.selectedDateParts;
            var header = renderStartDateTimePickerHeader(
                String(viewedYear),
                createDateTimePickerIconButton('prev-year', 'Anul precedent', 'bi-chevron-left'),
                createDateTimePickerIconButton('next-year', 'Anul urmator', 'bi-chevron-right'),
                'show-year'
            );
            var monthGrid = document.createElement('div');

            getActiveDateTimePicker().popover.appendChild(header);
            monthGrid.className = 'dispatcher-datetime-month-grid';
            monthLabels.forEach(function (monthLabel, monthIndex) {
                var monthButton = document.createElement('button');
                var monthValue = monthIndex + 1;

                monthButton.type = 'button';
                monthButton.className = 'dispatcher-datetime-time-option';
                monthButton.textContent = monthLabel;
                monthButton.setAttribute('data-datetime-month', String(monthValue));
                if (selectedDateParts && selectedDateParts.year === viewedYear && selectedDateParts.month === monthValue) {
                    monthButton.classList.add('is-selected');
                    monthButton.setAttribute('aria-pressed', 'true');
                }
                monthGrid.appendChild(monthButton);
            });

            getActiveDateTimePicker().popover.appendChild(monthGrid);
        }

        function getYearGridStart(viewedYear) {
            return Math.floor(viewedYear / 12) * 12;
        }

        function renderStartDateTimeYearView() {
            var todayDateParts = getTodayDateParts();
            var viewedYear = getActiveDateTimePicker().state.viewedYear || todayDateParts.year;
            var yearStart = getYearGridStart(viewedYear);
            var selectedDateParts = getActiveDateTimePicker().state.selectedDateParts;
            var header = renderStartDateTimePickerHeader(
                String(yearStart) + ' - ' + String(yearStart + 11),
                createDateTimePickerIconButton('prev-year-range', 'Interval precedent', 'bi-chevron-left'),
                createDateTimePickerIconButton('next-year-range', 'Interval urmator', 'bi-chevron-right')
            );
            var yearGrid = document.createElement('div');

            getActiveDateTimePicker().popover.appendChild(header);
            yearGrid.className = 'dispatcher-datetime-year-grid';
            for (var yearOffset = 0; yearOffset < 12; yearOffset += 1) {
                var yearValue = yearStart + yearOffset;
                var yearButton = document.createElement('button');

                yearButton.type = 'button';
                yearButton.className = 'dispatcher-datetime-time-option';
                yearButton.textContent = String(yearValue);
                yearButton.setAttribute('data-datetime-year', String(yearValue));
                if (selectedDateParts && selectedDateParts.year === yearValue) {
                    yearButton.classList.add('is-selected');
                    yearButton.setAttribute('aria-pressed', 'true');
                }
                yearGrid.appendChild(yearButton);
            }

            getActiveDateTimePicker().popover.appendChild(yearGrid);
        }

        function renderStartDateTimeHourView() {
            var selectedHour = getActiveDateTimePicker().state.selectedHour;
            var hourGrid = document.createElement('div');

            getActiveDateTimePicker().popover.appendChild(renderStartDateTimeModeSwitch('show-date', 'Alege data', 'bi-calendar3'));
            hourGrid.className = 'dispatcher-datetime-time-grid dispatcher-datetime-hour-grid';

            for (var hour = 0; hour < 24; hour += 1) {
                var hourButton = document.createElement('button');
                hourButton.type = 'button';
                hourButton.className = 'dispatcher-datetime-time-option';
                if (selectedHour === hour) {
                    hourButton.classList.add('is-selected');
                    hourButton.setAttribute('aria-pressed', 'true');
                }
                hourButton.textContent = padTimeComponent(hour);
                hourButton.setAttribute('data-datetime-hour', String(hour));
                hourGrid.appendChild(hourButton);
            }

            getActiveDateTimePicker().popover.appendChild(hourGrid);
        }

        function renderStartDateTimeMinuteView() {
            var selectedHour = getActiveDateTimePicker().state.selectedHour;
            var selectedMinute = getActiveDateTimePicker().state.selectedMinute;
            var minuteValues = [];
            var header = renderStartDateTimePickerHeader(
                padTimeComponent(selectedHour === null ? 0 : selectedHour) + ':mm',
                createDateTimePickerIconButton('show-hour', 'Alege ora', 'bi-chevron-left'),
                createDateTimePickerIconButton('show-date', 'Alege data', 'bi-calendar3')
            );
            var minuteGrid = document.createElement('div');

            getActiveDateTimePicker().popover.appendChild(header);
            minuteGrid.className = 'dispatcher-datetime-time-grid dispatcher-datetime-minute-grid';

            for (var minute = 0; minute < 60; minute += 5) {
                minuteValues.push(minute);
            }
            if (selectedMinute !== null && minuteValues.indexOf(selectedMinute) === -1) {
                minuteValues.push(selectedMinute);
                minuteValues.sort(function (first, second) {
                    return first - second;
                });
            }

            minuteValues.forEach(function (minuteValue) {
                var minuteButton = document.createElement('button');
                minuteButton.type = 'button';
                minuteButton.className = 'dispatcher-datetime-time-option';
                if (selectedMinute === minuteValue) {
                    minuteButton.classList.add('is-selected');
                    minuteButton.setAttribute('aria-pressed', 'true');
                }
                minuteButton.textContent = padTimeComponent(minuteValue);
                minuteButton.setAttribute('data-datetime-minute', String(minuteValue));
                minuteGrid.appendChild(minuteButton);
            });

            getActiveDateTimePicker().popover.appendChild(minuteGrid);
        }

        function renderStartDateTimePopover() {
            if (!(getActiveDateTimePicker().popover instanceof HTMLElement)) {
                return;
            }

            clearDateTimePickerElement(getActiveDateTimePicker().popover);

            if (getActiveDateTimePicker().state.view === 'time') {
                renderStartDateTimeSpinnerView();
            } else if (getActiveDateTimePicker().state.view === 'hour') {
                renderStartDateTimeHourView();
            } else if (getActiveDateTimePicker().state.view === 'minute') {
                renderStartDateTimeMinuteView();
            } else if (getActiveDateTimePicker().state.view === 'month') {
                renderStartDateTimeMonthView();
            } else if (getActiveDateTimePicker().state.view === 'year') {
                renderStartDateTimeYearView();
            } else {
                renderStartDateTimeDateView();
            }
        }

        function closeStartDateTimePopover() {
            if (!(getActiveDateTimePicker().popover instanceof HTMLElement)) {
                return;
            }

            getActiveDateTimePicker().popover.hidden = true;
            if (getActiveDateTimePicker().toggleButton instanceof HTMLButtonElement) {
                getActiveDateTimePicker().toggleButton.setAttribute('aria-expanded', 'false');
            }
            document.removeEventListener('click', handleStartDateTimeDocumentClick);
            document.removeEventListener('keydown', handleStartDateTimeKeydown);
        }

        function openStartDateTimePopover(view) {
            if (!(getActiveDateTimePicker().popover instanceof HTMLElement)) {
                return;
            }

            syncStartDateTimeFieldsFromDisplay(false);
            syncStartDateTimePickerStateFromFields();
            getActiveDateTimePicker().state.view = view || getActiveDateTimePicker().state.view || 'date';
            renderStartDateTimePopover();
            dateTimePickers.forEach(function (dateTimePicker) {
                if (dateTimePicker === getActiveDateTimePicker()) {
                    return;
                }

                dateTimePicker.popover.hidden = true;
                dateTimePicker.toggleButton.setAttribute('aria-expanded', 'false');
            });
            getActiveDateTimePicker().popover.hidden = false;
            if (getActiveDateTimePicker().toggleButton instanceof HTMLButtonElement) {
                getActiveDateTimePicker().toggleButton.setAttribute('aria-expanded', 'true');
            }

            window.setTimeout(function () {
                document.addEventListener('click', handleStartDateTimeDocumentClick);
                document.addEventListener('keydown', handleStartDateTimeKeydown);
            }, 0);
        }

        function handleStartDateTimeDocumentClick(event) {
            var target = event.target;
            if (!(target instanceof Node)) {
                return;
            }

            if (getActiveDateTimePicker().field instanceof HTMLElement && getActiveDateTimePicker().field.contains(target)) {
                return;
            }

            closeStartDateTimePopover();
        }

        function handleStartDateTimeKeydown(event) {
            if (event.key !== 'Escape') {
                return;
            }

            closeStartDateTimePopover();
            if (getActiveDateTimePicker().toggleButton instanceof HTMLButtonElement) {
                getActiveDateTimePicker().toggleButton.focus();
            }
        }

        function handleStartDateTimePopoverClick(event) {
            var target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            event.stopPropagation();

            var actionButton = target.closest('[data-datetime-action]');
            if (actionButton instanceof HTMLElement && getActiveDateTimePicker().popover.contains(actionButton)) {
                var action = String(actionButton.getAttribute('data-datetime-action') || '');
                var viewedDate = new Date(
                    getActiveDateTimePicker().state.viewedYear || getTodayDateParts().year,
                    (getActiveDateTimePicker().state.viewedMonth || getTodayDateParts().month) - 1,
                    1
                );

                if (action === 'prev-month' || action === 'next-month') {
                    viewedDate.setMonth(viewedDate.getMonth() + (action === 'prev-month' ? -1 : 1));
                    getActiveDateTimePicker().state.viewedYear = viewedDate.getFullYear();
                    getActiveDateTimePicker().state.viewedMonth = viewedDate.getMonth() + 1;
                    renderStartDateTimePopover();
                    return;
                }

                if (action === 'prev-year' || action === 'next-year') {
                    getActiveDateTimePicker().state.viewedYear = (getActiveDateTimePicker().state.viewedYear || getTodayDateParts().year)
                        + (action === 'prev-year' ? -1 : 1);
                    renderStartDateTimePopover();
                    return;
                }

                if (action === 'prev-year-range' || action === 'next-year-range') {
                    getActiveDateTimePicker().state.viewedYear = (getActiveDateTimePicker().state.viewedYear || getTodayDateParts().year)
                        + (action === 'prev-year-range' ? -12 : 12);
                    renderStartDateTimePopover();
                    return;
                }

                if (action === 'show-date') {
                    if (getActiveDateTimePicker().state.selectedDateParts) {
                        getActiveDateTimePicker().state.viewedYear = getActiveDateTimePicker().state.selectedDateParts.year;
                        getActiveDateTimePicker().state.viewedMonth = getActiveDateTimePicker().state.selectedDateParts.month;
                    }
                    getActiveDateTimePicker().state.view = 'date';
                    renderStartDateTimePopover();
                    return;
                }

                if (action === 'show-month') {
                    getActiveDateTimePicker().state.view = 'month';
                    renderStartDateTimePopover();
                    return;
                }

                if (action === 'show-year') {
                    getActiveDateTimePicker().state.view = 'year';
                    renderStartDateTimePopover();
                    return;
                }

                if (action === 'show-time') {
                    if (getActiveDateTimePicker().state.selectedHour === null) {
                        getActiveDateTimePicker().state.selectedHour = 0;
                    }
                    if (getActiveDateTimePicker().state.selectedMinute === null) {
                        getActiveDateTimePicker().state.selectedMinute = 0;
                    }
                    getActiveDateTimePicker().state.view = 'time';
                    renderStartDateTimePopover();
                    return;
                }

                if (action === 'show-hour') {
                    getActiveDateTimePicker().state.view = 'hour';
                    renderStartDateTimePopover();
                    return;
                }

                if (action === 'show-minute') {
                    if (getActiveDateTimePicker().state.selectedHour === null) {
                        getActiveDateTimePicker().state.selectedHour = 0;
                    }
                    if (getActiveDateTimePicker().state.selectedMinute === null) {
                        getActiveDateTimePicker().state.selectedMinute = 0;
                    }
                    getActiveDateTimePicker().state.view = 'minute';
                    renderStartDateTimePopover();
                    return;
                }

                if (action === 'hour-up' || action === 'hour-down' || action === 'minute-up' || action === 'minute-down') {
                    var currentHour = getPickerSelectedHour();
                    var currentMinute = getPickerSelectedMinute();
                    if (action === 'hour-up') {
                        currentHour = (currentHour + 1) % 24;
                    } else if (action === 'hour-down') {
                        currentHour = (currentHour + 23) % 24;
                    } else if (action === 'minute-up') {
                        currentMinute = (currentMinute + 1) % 60;
                    } else {
                        currentMinute = (currentMinute + 59) % 60;
                    }
                    applyPickerTime(currentHour, currentMinute);
                    getActiveDateTimePicker().state.view = 'time';
                    renderStartDateTimePopover();
                    return;
                }
            }

            var dateButton = target.closest('[data-datetime-date]');
            if (dateButton instanceof HTMLElement && getActiveDateTimePicker().popover.contains(dateButton)) {
                var selectedDateParts = parseDateForDuration(dateButton.getAttribute('data-datetime-date'));
                if (selectedDateParts === null) {
                    return;
                }

                getActiveDateTimePicker().state.selectedDateParts = selectedDateParts;
                getActiveDateTimePicker().state.viewedYear = selectedDateParts.year;
                getActiveDateTimePicker().state.viewedMonth = selectedDateParts.month;
                applyStartDateTimeFieldValues(
                    selectedDateParts,
                    getActiveDateTimePicker().timeField instanceof HTMLInputElement ? (normalizeTimeInputValue(getActiveDateTimePicker().timeField.value) || '') : ''
                );
                renderStartDateTimePopover();
                return;
            }

            var monthButton = target.closest('[data-datetime-month]');
            if (monthButton instanceof HTMLElement && getActiveDateTimePicker().popover.contains(monthButton)) {
                var selectedMonth = parseInt(monthButton.getAttribute('data-datetime-month') || '', 10);
                if (!Number.isFinite(selectedMonth) || selectedMonth < 1 || selectedMonth > 12) {
                    return;
                }

                getActiveDateTimePicker().state.viewedMonth = selectedMonth;
                getActiveDateTimePicker().state.view = 'date';
                renderStartDateTimePopover();
                return;
            }

            var yearButton = target.closest('[data-datetime-year]');
            if (yearButton instanceof HTMLElement && getActiveDateTimePicker().popover.contains(yearButton)) {
                var selectedYear = parseInt(yearButton.getAttribute('data-datetime-year') || '', 10);
                if (!Number.isFinite(selectedYear) || selectedYear < 1) {
                    return;
                }

                getActiveDateTimePicker().state.viewedYear = selectedYear;
                getActiveDateTimePicker().state.view = 'month';
                renderStartDateTimePopover();
                return;
            }

            var hourButton = target.closest('[data-datetime-hour]');
            if (hourButton instanceof HTMLElement && getActiveDateTimePicker().popover.contains(hourButton)) {
                var hourValue = parseInt(hourButton.getAttribute('data-datetime-hour') || '', 10);
                if (!Number.isFinite(hourValue) || hourValue < 0 || hourValue > 23) {
                    return;
                }

                applyPickerTime(hourValue, getPickerSelectedMinute());
                getActiveDateTimePicker().state.view = 'time';
                renderStartDateTimePopover();
                return;
            }

            var minuteButton = target.closest('[data-datetime-minute]');
            if (minuteButton instanceof HTMLElement && getActiveDateTimePicker().popover.contains(minuteButton)) {
                var minuteValue = parseInt(minuteButton.getAttribute('data-datetime-minute') || '', 10);
                var minuteDateParts = getActiveDateTimePicker().state.selectedDateParts
                    || parseDateForDuration(getActiveDateTimePicker().dateField instanceof HTMLInputElement ? getActiveDateTimePicker().dateField.value : '')
                    || getTodayDateParts();
                if (!Number.isFinite(minuteValue) || minuteValue < 0 || minuteValue > 59) {
                    return;
                }

                getActiveDateTimePicker().state.selectedDateParts = minuteDateParts;
                applyPickerTime(getPickerSelectedHour(), minuteValue);
                getActiveDateTimePicker().state.view = 'time';
                renderStartDateTimePopover();
            }
        }

        function initStartDateTimeField(dateTimePicker) {
            if (!setActiveDateTimePicker(dateTimePicker)) {
                return;
            }

            syncStartDateTimeDisplayFromFields();

            dateTimePicker.displayField.addEventListener('input', function () {
                setActiveDateTimePicker(dateTimePicker);
                setStartDateTimeDisplayInvalid(false);
            });
            dateTimePicker.displayField.addEventListener('blur', function () {
                setActiveDateTimePicker(dateTimePicker);
                syncStartDateTimeFieldsFromDisplay(true);
            });
            dateTimePicker.displayField.addEventListener('change', function () {
                setActiveDateTimePicker(dateTimePicker);
                syncStartDateTimeFieldsFromDisplay(true);
            });
            dateTimePicker.displayField.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    setActiveDateTimePicker(dateTimePicker);
                    openStartDateTimePopover('date');
                }
            });

            dateTimePicker.toggleButton.addEventListener('click', function (event) {
                event.preventDefault();
                setActiveDateTimePicker(dateTimePicker);
                if (dateTimePicker.popover.hidden) {
                    openStartDateTimePopover('date');
                } else {
                    closeStartDateTimePopover();
                }
            });

            dateTimePicker.popover.addEventListener('click', function (event) {
                setActiveDateTimePicker(dateTimePicker);
                handleStartDateTimePopoverClick(event);
            });

            [dateTimePicker.dateField, dateTimePicker.timeField].forEach(function (field) {
                if (!(field instanceof HTMLInputElement)) {
                    return;
                }

                field.addEventListener('change', function () {
                    setActiveDateTimePicker(dateTimePicker);
                    syncStartDateTimeDisplayFromFields();
                });
            });
        }

        function formatDurationLabel(minutes) {
            var totalMinutes = parseInt(minutes, 10);
            if (!Number.isFinite(totalMinutes) || totalMinutes < 0) {
                return '-';
            }

            var hours = Math.floor(totalMinutes / 60);
            var mins = totalMinutes % 60;
            if (hours > 0 && mins > 0) {
                return String(hours) + 'h ' + String(mins) + 'm';
            }
            if (hours > 0) {
                return String(hours) + 'h';
            }

            return String(mins) + 'm';
        }

        function computeRaceDurationMinutes() {
            if (!startDateField || !endDateField || !startTimeField || !endTimeField) {
                return null;
            }

            var startDate = parseDateForDuration(startDateField.value);
            var endDate = parseDateForDuration(endDateField.value);
            var startTime = parseTimeForDuration(startTimeField.value);
            var endTime = parseTimeForDuration(endTimeField.value);
            if (!startDate || !endDate || !startTime || !endTime) {
                return null;
            }

            var startMoment = new Date(
                startDate.year,
                startDate.month - 1,
                startDate.day,
                startTime.hours,
                startTime.minutes,
                0,
                0
            );
            var endMoment = new Date(
                endDate.year,
                endDate.month - 1,
                endDate.day,
                endTime.hours,
                endTime.minutes,
                0,
                0
            );
            if (!isFinite(startMoment.getTime()) || !isFinite(endMoment.getTime())) {
                return null;
            }

            var diffMs = endMoment.getTime() - startMoment.getTime();
            if (diffMs < 0) {
                return -1;
            }

            return Math.round(diffMs / 60000);
        }

        function syncRaceDurationHint() {
            if (!durationHintField) {
                return;
            }

            var hintDefaultText = defaultDurationHintText !== ''
                ? defaultDurationHintText
                : 'Durata cursa se calculeaza automat dupa ora inceput/sfarsit.';
            var startDateValue = startDateField ? String(startDateField.value || '').trim() : '';
            var endDateValue = endDateField ? String(endDateField.value || '').trim() : '';
            var startTimeValue = startTimeField ? String(startTimeField.value || '').trim() : '';
            var endTimeValue = endTimeField ? String(endTimeField.value || '').trim() : '';
            if (startDateValue === '' || endDateValue === '' || startTimeValue === '' || endTimeValue === '') {
                durationHintField.textContent = hintDefaultText;
                setNativeHoverTitle(endTimeField, hintDefaultText !== '' ? hintDefaultText : defaultEndTimeTitle);
                return;
            }

            var durationMinutes = computeRaceDurationMinutes();
            if (durationMinutes === null) {
                durationHintField.textContent = 'Completeaza date si ore valide pentru calcul durata.';
                setNativeHoverTitle(endTimeField, durationHintField.textContent);
                return;
            }
            if (durationMinutes < 0) {
                durationHintField.textContent = 'Interval invalid: ora sfarsit trebuie dupa ora inceput.';
                setNativeHoverTitle(endTimeField, durationHintField.textContent);
                return;
            }

            durationHintField.textContent = 'Durata cursa calculata: ' + formatDurationLabel(durationMinutes) + ' (' + String(durationMinutes) + ' min)';
            setNativeHoverTitle(endTimeField, durationHintField.textContent);
        }

        function isDistributionTransport(transportType) {
            return transportType === 'distributie' || transportType === 'primar_distributie';
        }

        function isDistributionWithKmTransport(transportType) {
            return transportType === 'primar_distributie';
        }

        function normalizeDistributionRouteScope(scope) {
            var normalizedScope = String(scope || '').trim().toLowerCase();
            return normalizedScope === 'distributie' ? 'distributie' : 'primar_distributie';
        }

        function normalizeDistributionRouteTariffMode(mode) {
            var normalizedMode = String(mode || '').trim().toLowerCase();
            if (normalizedMode === 'tona' || normalizedMode === 'km') {
                return normalizedMode;
            }

            return 'tona_km';
        }

        function distributionRouteUsesTonTariff(mode) {
            var normalizedMode = normalizeDistributionRouteTariffMode(mode);
            return normalizedMode === 'tona_km' || normalizedMode === 'tona';
        }

        function distributionRouteUsesKmTariff(mode) {
            var normalizedMode = normalizeDistributionRouteTariffMode(mode);
            return normalizedMode === 'tona_km' || normalizedMode === 'km';
        }

        function resolveDistributionRouteScopeForTransport(transportType) {
            var normalizedTransportType = String(transportType || '').trim();
            if (normalizedTransportType === 'distributie') {
                return 'distributie';
            }
            if (normalizedTransportType === 'primar_distributie' || normalizedTransportType === 'mixt') {
                return 'primar_distributie';
            }

            return null;
        }

        function isPrimaryKmTransport(transportType) {
            return transportType === 'primar';
        }

        function isPrimaryTonTransport(transportType) {
            return transportType === 'primar_tona';
        }

        function isPrimaryTransport(transportType) {
            return isPrimaryKmTransport(transportType) || isPrimaryTonTransport(transportType);
        }

        function normalizeRouteVehicleIds(routeRule) {
            if (!routeRule || !Array.isArray(routeRule.vehicle_ids)) {
                return [];
            }

            var normalized = {};
            routeRule.vehicle_ids.forEach(function (vehicleIdRaw) {
                var vehicleId = String(vehicleIdRaw || '').trim();
                if (vehicleId !== '') {
                    normalized[vehicleId] = true;
                }
            });

            return Object.keys(normalized);
        }

        function buildDistributionRouteRulesByBeneficiary(routeMap) {
            var grouped = {};
            if (!routeMap || typeof routeMap !== 'object') {
                return grouped;
            }

            Object.keys(routeMap).forEach(function (routeKey) {
                var parts = String(routeKey || '').split('|');
                if (parts.length !== 3 && parts.length !== 2) {
                    return;
                }

                var defaultBeneficiaryId = '';
                var locationId = '';
                var zoneId = '';
                if (parts.length === 3) {
                    defaultBeneficiaryId = String(parts[0] || '').trim();
                    locationId = String(parts[1] || '').trim();
                    zoneId = String(parts[2] || '').trim();
                } else {
                    defaultBeneficiaryId = '__global__';
                    locationId = String(parts[0] || '').trim();
                    zoneId = String(parts[1] || '').trim();
                }
                if (locationId === '' || zoneId === '') {
                    return;
                }

                var rulesForPair = Array.isArray(routeMap[routeKey])
                    ? routeMap[routeKey]
                    : [routeMap[routeKey]];
                var locationName = Object.prototype.hasOwnProperty.call(loadLocationNamesById, locationId)
                    ? String(loadLocationNamesById[locationId] || '').trim()
                    : '';
                var zoneName = Object.prototype.hasOwnProperty.call(zoneNamesById, zoneId)
                    ? String(zoneNamesById[zoneId] || '').trim()
                    : '';

                rulesForPair.forEach(function (routeRule) {
                    if (!routeRule || typeof routeRule !== 'object') {
                        return;
                    }

                    var rawRouteBeneficiaryId = String(routeRule.beneficiar_id || '').trim();
                    var routeBeneficiaryId = rawRouteBeneficiaryId !== ''
                        ? rawRouteBeneficiaryId
                        : defaultBeneficiaryId;
                    if (routeBeneficiaryId === '') {
                        routeBeneficiaryId = '__global__';
                    }

                    if (!Object.prototype.hasOwnProperty.call(grouped, routeBeneficiaryId)) {
                        grouped[routeBeneficiaryId] = [];
                    }

                    var vehicleIds = normalizeRouteVehicleIds(routeRule);
                    var vehicleIdSet = {};
                    vehicleIds.forEach(function (vehicleId) {
                        vehicleIdSet[vehicleId] = true;
                    });

                    grouped[routeBeneficiaryId].push({
                        ruleId: parseInt(routeRule.id, 10) || 0,
                        beneficiaryId: routeBeneficiaryId,
                        locationId: locationId,
                        zoneId: zoneId,
                        transportScope: normalizeDistributionRouteScope(routeRule.transport_scope),
                        tariffMode: normalizeDistributionRouteTariffMode(routeRule.tarif_mod),
                        active: !!routeRule.activ,
                        tariffPerTon: parseNumber(routeRule.tarif_tona),
                        extraKmCost: parseNumber(routeRule.cost_extra_km),
                        kmTariff: Math.max(0, Math.round(parseNumber(routeRule.km_tarifare))),
                        rideCost: parseNumber(routeRule.cost_cursa),
                        applyRideCost: !!routeRule.aplica_cost_cursa,
                        vehicleIds: vehicleIds,
                        vehicleIdSet: vehicleIdSet,
                        locationName: locationName,
                        zoneName: zoneName
                    });
                });
            });

            return grouped;
        }

        var distributionRouteRulesByBeneficiary = buildDistributionRouteRulesByBeneficiary(distributionRouteTariffs);
        var distributionGlobalRules = Object.prototype.hasOwnProperty.call(distributionRouteRulesByBeneficiary, '__global__')
            ? (distributionRouteRulesByBeneficiary.__global__ || [])
            : [];

        function getDistributionRulesForBeneficiary(beneficiaryId, transportType) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            var beneficiaryRules = beneficiaryKey !== '' && Object.prototype.hasOwnProperty.call(distributionRouteRulesByBeneficiary, beneficiaryKey)
                ? (distributionRouteRulesByBeneficiary[beneficiaryKey] || [])
                : [];
            var targetScope = resolveDistributionRouteScopeForTransport(transportType);
            if (targetScope === null) {
                return [];
            }

            return beneficiaryRules.concat(distributionGlobalRules).filter(function (rule) {
                if (!rule || typeof rule !== 'object') {
                    return false;
                }

                return normalizeDistributionRouteScope(rule.transportScope) === targetScope;
            });
        }

        function buildPrimaryRouteRulesByBeneficiary(routeMap) {
            var grouped = {};
            if (!routeMap || typeof routeMap !== 'object') {
                return grouped;
            }

            Object.keys(routeMap).forEach(function (routeKey) {
                var parts = String(routeKey || '').split('|');
                if (parts.length !== 3) {
                    return;
                }

                var beneficiaryId = String(parts[0] || '').trim();
                var locationId = String(parts[1] || '').trim();
                var zoneId = String(parts[2] || '').trim();
                if (beneficiaryId === '' || locationId === '' || zoneId === '') {
                    return;
                }

                var routeRule = routeMap[routeKey];
                if (!routeRule || typeof routeRule !== 'object') {
                    return;
                }

                if (!Object.prototype.hasOwnProperty.call(grouped, beneficiaryId)) {
                    grouped[beneficiaryId] = [];
                }

                var locationName = Object.prototype.hasOwnProperty.call(loadLocationNamesById, locationId)
                    ? String(loadLocationNamesById[locationId] || '').trim()
                    : '';
                var zoneName = Object.prototype.hasOwnProperty.call(zoneNamesById, zoneId)
                    ? String(zoneNamesById[zoneId] || '').trim()
                    : '';

                grouped[beneficiaryId].push({
                    ruleId: parseInt(routeRule.id, 10) || 0,
                    locationId: locationId,
                    zoneId: zoneId,
                    kmTariff: Math.max(0, Math.round(parseNumber(routeRule.km_tarifare))),
                    rideCost: Math.max(0, parseNumber(routeRule.cost_cursa)),
                    applyRideCost: !!routeRule.aplica_cost_cursa,
                    vehicleIds: Array.isArray(routeRule.vehicle_ids) ? routeRule.vehicle_ids : [],
                    manualAgreedKm: !!routeRule.km_agreati_manual,
                    active: !!routeRule.activ,
                    locationName: locationName,
                    zoneName: zoneName
                });
            });

            return grouped;
        }

        var primaryRouteRulesByBeneficiary = buildPrimaryRouteRulesByBeneficiary(primaryRouteKmMap);

        function buildPrimaryRouteScopeByBeneficiary(routeRulesByBeneficiary) {
            var grouped = {};
            if (!routeRulesByBeneficiary || typeof routeRulesByBeneficiary !== 'object') {
                return grouped;
            }

            Object.keys(routeRulesByBeneficiary).forEach(function (beneficiaryId) {
                var routeRules = Array.isArray(routeRulesByBeneficiary[beneficiaryId])
                    ? routeRulesByBeneficiary[beneficiaryId]
                    : [];
                if (routeRules.length === 0) {
                    return;
                }

                var pairMap = {};
                var locationAllowSet = {};
                var zoneAllowSet = {};

                var addPair = function (locationIdRaw, zoneIdRaw, kmTariff, manualAgreedKm, rideCost, applyRideCost, routeId, matchDirection) {
                    var locationId = String(locationIdRaw || '').trim();
                    var zoneId = String(zoneIdRaw || '').trim();
                    if (locationId === '' || zoneId === '') {
                        return;
                    }

                    var pairKey = locationId + '|' + zoneId;
                    var normalizedRouteId = parseInt(routeId, 10);
                    if (!Number.isFinite(normalizedRouteId)) {
                        normalizedRouteId = 0;
                    }

                    var previousRule = pairMap[pairKey] || null;
                    if (previousRule && normalizedRouteId < (parseInt(previousRule.ruleId, 10) || 0)) {
                        return;
                    }

                    pairMap[pairKey] = {
                        locationId: locationId,
                        zoneId: zoneId,
                        kmTariff: Math.max(0, Math.round(parseNumber(kmTariff))),
                        manualAgreedKm: !!manualAgreedKm,
                        rideCost: Math.max(0, parseNumber(rideCost)),
                        applyRideCost: !!applyRideCost,
                        ruleId: normalizedRouteId,
                        matchDirection: String(matchDirection || 'direct'),
                        active: true
                    };

                    locationAllowSet[locationId] = true;
                    zoneAllowSet[zoneId] = true;
                };

                routeRules.forEach(function (routeRule) {
                    if (!routeRule || typeof routeRule !== 'object' || !routeRule.active) {
                        return;
                    }

                    addPair(
                        routeRule.locationId,
                        routeRule.zoneId,
                        routeRule.kmTariff,
                        routeRule.manualAgreedKm,
                        routeRule.rideCost,
                        routeRule.applyRideCost,
                        routeRule.ruleId,
                        'direct'
                    );

                    var locationNameKey = normalizeMatchText(routeRule.locationName || '');
                    var zoneNameKey = normalizeMatchText(routeRule.zoneName || '');
                    if (locationNameKey === '' || zoneNameKey === '') {
                        return;
                    }

                    var reverseLocationIds = getNameIndexedEntityIds(
                        loadLocationIdsByBeneficiaryName,
                        beneficiaryId,
                        zoneNameKey
                    );
                    var reverseZoneIds = getNameIndexedEntityIds(
                        zoneIdsByBeneficiaryName,
                        beneficiaryId,
                        locationNameKey
                    );

                    reverseLocationIds.forEach(function (reverseLocationId) {
                        reverseZoneIds.forEach(function (reverseZoneId) {
                            addPair(
                                reverseLocationId,
                                reverseZoneId,
                                routeRule.kmTariff,
                                routeRule.manualAgreedKm,
                                routeRule.rideCost,
                                routeRule.applyRideCost,
                                routeRule.ruleId,
                                'reverse'
                            );
                        });
                    });
                });

                if (Object.keys(pairMap).length === 0) {
                    return;
                }

                var locationOptionsById = {};
                getDistributionLocationOptionsForBeneficiary(beneficiaryId).forEach(function (option) {
                    if (!option || typeof option !== 'object') {
                        return;
                    }
                    var optionId = String(option.value || '').trim();
                    if (optionId !== '') {
                        locationOptionsById[optionId] = option;
                    }
                });

                var zoneOptionsById = {};
                getDistributionZoneOptionsForBeneficiary(beneficiaryId).forEach(function (option) {
                    if (!option || typeof option !== 'object') {
                        return;
                    }
                    var optionId = String(option.value || '').trim();
                    if (optionId !== '') {
                        zoneOptionsById[optionId] = option;
                    }
                });

                var locationOptions = [];
                Object.keys(locationAllowSet).forEach(function (locationId) {
                    if (Object.prototype.hasOwnProperty.call(locationOptionsById, locationId)) {
                        locationOptions.push(locationOptionsById[locationId]);
                    }
                });

                var zoneOptions = [];
                Object.keys(zoneAllowSet).forEach(function (zoneId) {
                    if (Object.prototype.hasOwnProperty.call(zoneOptionsById, zoneId)) {
                        zoneOptions.push(zoneOptionsById[zoneId]);
                    }
                });

                locationOptions.sort(function (a, b) {
                    return String(a.label || '').localeCompare(String(b.label || ''), 'ro');
                });
                zoneOptions.sort(function (a, b) {
                    return String(a.label || '').localeCompare(String(b.label || ''), 'ro');
                });

                grouped[String(beneficiaryId)] = {
                    hasActiveRules: true,
                    pairMap: pairMap,
                    locationOptions: locationOptions,
                    zoneOptions: zoneOptions
                };
            });

            return grouped;
        }

        var primaryRouteScopeByBeneficiary = buildPrimaryRouteScopeByBeneficiary(primaryRouteRulesByBeneficiary);

        function isTransportSupportedForBeneficiary(beneficiaryId, transportType) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            if (beneficiaryKey === '' || !Object.prototype.hasOwnProperty.call(beneficiaryPricing, beneficiaryKey)) {
                return false;
            }

            var pricingConfig = beneficiaryPricing[beneficiaryKey] || {};
            if (isPrimaryTransport(transportType)) {
                return !!pricingConfig.suporta_primar;
            }
            if (isDistributionTransport(transportType)) {
                return transportType === 'distributie'
                    ? !!pricingConfig.suporta_distributie
                    : !!pricingConfig.suporta_primar_distributie;
            }
            if (transportType === 'compresor') {
                return !!pricingConfig.suporta_compresor;
            }

            return false;
        }

        function getAssignedVehicleSetForBeneficiary(beneficiaryId) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            var assigned = {};
            if (beneficiaryKey === '') {
                return assigned;
            }

            var collectFromBeneficiaryMap = function (beneficiaryMap) {
                if (
                    !beneficiaryMap
                    || typeof beneficiaryMap !== 'object'
                    || !Object.prototype.hasOwnProperty.call(beneficiaryMap, beneficiaryKey)
                ) {
                    return;
                }

                var assignedMap = beneficiaryMap[beneficiaryKey] || {};
                Object.keys(assignedMap).forEach(function (vehicleId) {
                    var normalizedVehicleId = String(vehicleId || '').trim();
                    if (normalizedVehicleId !== '') {
                        assigned[normalizedVehicleId] = true;
                    }
                });
            };

            collectFromBeneficiaryMap(vehicleDefaultLoadLocationsByBeneficiary);
            collectFromBeneficiaryMap(vehicleDefaultDistributionZonesByBeneficiary);

            return assigned;
        }

        function getCompressorAssignedVehicleSetForBeneficiary(beneficiaryId) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            var assigned = {};
            if (
                beneficiaryKey === ''
                || !compressorVehiclesByBeneficiary
                || typeof compressorVehiclesByBeneficiary !== 'object'
                || !Object.prototype.hasOwnProperty.call(compressorVehiclesByBeneficiary, beneficiaryKey)
            ) {
                return assigned;
            }

            var assignedMap = compressorVehiclesByBeneficiary[beneficiaryKey] || {};
            Object.keys(assignedMap).forEach(function (vehicleId) {
                var normalizedVehicleId = String(vehicleId || '').trim();
                if (normalizedVehicleId !== '') {
                    assigned[normalizedVehicleId] = true;
                }
            });

            return assigned;
        }

        function getPrimaryAssignedVehicleSetForBeneficiary(beneficiaryId) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            var assigned = {};
            var rules = beneficiaryKey !== '' && Array.isArray(primaryRouteRulesByBeneficiary[beneficiaryKey])
                ? primaryRouteRulesByBeneficiary[beneficiaryKey]
                : [];

            rules.forEach(function (rule) {
                if (!rule || typeof rule !== 'object' || !rule.active || !Array.isArray(rule.vehicleIds)) {
                    return;
                }
                rule.vehicleIds.forEach(function (vehicleIdRaw) {
                    var vehicleId = String(vehicleIdRaw || '').trim();
                    if (vehicleId !== '') {
                        assigned[vehicleId] = true;
                    }
                });
            });

            return assigned;
        }

        function getDistributionScopedVehicleSetForBeneficiary(beneficiaryId, transportType) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            var vehicleSet = {};
            var hasScopedRules = false;

            if (beneficiaryKey === '') {
                return { hasScopedRules: false, vehicleSet: {} };
            }

            var rules = getDistributionRulesForBeneficiary(beneficiaryKey, transportType);
            rules.forEach(function (rule) {
                if (!rule || typeof rule !== 'object' || !rule.active) {
                    return;
                }
                if (!Array.isArray(rule.vehicleIds) || rule.vehicleIds.length === 0) {
                    return;
                }

                hasScopedRules = true;
                rule.vehicleIds.forEach(function (vehicleIdRaw) {
                    var normalizedVehicleId = String(vehicleIdRaw || '').trim();
                    if (normalizedVehicleId !== '') {
                        vehicleSet[normalizedVehicleId] = true;
                    }
                });
            });

            return { hasScopedRules: hasScopedRules, vehicleSet: vehicleSet };
        }

        function getEligibleVehicleOptions(beneficiaryId, transportType) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            if (beneficiaryKey === '' || !isTransportSupportedForBeneficiary(beneficiaryKey, transportType)) {
                return [];
            }

            var allowedVehicleSet = {};

            if (isPrimaryTransport(transportType)) {
                allowedVehicleSet = getPrimaryAssignedVehicleSetForBeneficiary(beneficiaryKey);
            } else if (isDistributionTransport(transportType)) {
                var scopedDistributionVehicles = getDistributionScopedVehicleSetForBeneficiary(beneficiaryKey, transportType);
                allowedVehicleSet = scopedDistributionVehicles.vehicleSet;
            } else {
                allowedVehicleSet = getCompressorAssignedVehicleSetForBeneficiary(beneficiaryKey);
            }

            if (Object.keys(allowedVehicleSet).length === 0) {
                return [];
            }

            return initialVehicleOptions.filter(function (option) {
                var optionValue = String(option.value || '').trim();
                return optionValue !== '' && Object.prototype.hasOwnProperty.call(allowedVehicleSet, optionValue);
            });
        }

        function rebuildVehicleSelectOptions(options, selectedValue, placeholderLabel) {
            if (!(vehicleField instanceof HTMLSelectElement)) {
                return;
            }

            var targetValue = String(selectedValue || '').trim();
            var preservedStoredLabel = findOptionLabelForValue(vehicleField, targetValue);
            var hasSelectedValue = false;
            vehicleField.innerHTML = '';

            var placeholderOption = document.createElement('option');
            placeholderOption.value = '';
            placeholderOption.textContent = String(placeholderLabel || '-- Selecteaza --');
            vehicleField.appendChild(placeholderOption);

            options.forEach(function (option) {
                var optionValue = String(option.value || '').trim();
                if (optionValue === '') {
                    return;
                }

                var optionEl = document.createElement('option');
                optionEl.value = optionValue;
                optionEl.textContent = String(option.label || optionValue);
                if (option.disabled) {
                    optionEl.disabled = true;
                }
                var capacityValue = String(option.capacity || '').trim();
                if (capacityValue !== '') {
                    optionEl.setAttribute('data-capacitate-transport', capacityValue);
                }
                if (!option.disabled && optionValue === targetValue) {
                    optionEl.selected = true;
                    hasSelectedValue = true;
                }
                vehicleField.appendChild(optionEl);
            });

            if (!hasSelectedValue && !appendPreservedStoredOption(vehicleField, targetValue, preservedStoredLabel)) {
                vehicleField.value = '';
            }

            syncDriverOptionsByVehicle(false);
            syncVehicleTransportCapacity();
        }

        function getDriverOptionsForVehicle(vehicleId) {
            var key = String(vehicleId || '').trim();
            if (key === '' || !Object.prototype.hasOwnProperty.call(driversByVehicle, key)) {
                return [];
            }

            var rows = Array.isArray(driversByVehicle[key]) ? driversByVehicle[key] : [];
            var unique = {};
            var options = [];
            rows.forEach(function (row) {
                if (!row || typeof row !== 'object') {
                    return;
                }

                var driverId = String(row.id || '').trim();
                if (driverId === '' || Object.prototype.hasOwnProperty.call(unique, driverId)) {
                    return;
                }

                var driverName = String(row.nume || '').trim();
                if (driverName === '') {
                    return;
                }

                unique[driverId] = true;
                options.push({
                    value: driverId,
                    label: driverName
                });
            });

            return options;
        }

        function rebuildDriverSelectOptions(options, selectedValue, placeholderLabel) {
            if (!(driverField instanceof HTMLSelectElement)) {
                return;
            }

            var targetValue = String(selectedValue || '').trim();
            var preservedStoredLabel = findOptionLabelForValue(driverField, targetValue);
            var hasSelectedValue = false;
            driverField.innerHTML = '';

            var placeholderOption = document.createElement('option');
            placeholderOption.value = '';
            placeholderOption.textContent = String(placeholderLabel || '-- Selecteaza --');
            driverField.appendChild(placeholderOption);

            options.forEach(function (option) {
                var optionValue = String(option.value || '').trim();
                if (optionValue === '') {
                    return;
                }

                var optionEl = document.createElement('option');
                optionEl.value = optionValue;
                optionEl.textContent = String(option.label || optionValue);
                if (option.disabled) {
                    optionEl.disabled = true;
                }
                if (!option.disabled && optionValue === targetValue) {
                    optionEl.selected = true;
                    hasSelectedValue = true;
                }
                driverField.appendChild(optionEl);
            });

            if (!hasSelectedValue && !appendPreservedStoredOption(driverField, targetValue, preservedStoredLabel)) {
                driverField.value = '';
            }
        }

        function syncDriverOptionsByVehicle(forceReset) {
            if (!(driverField instanceof HTMLSelectElement)) {
                return;
            }

            var vehicleId = String(vehicleField ? (vehicleField.value || '') : '').trim();
            var selectedDriverId = forceReset ? '' : String(driverField.value || '').trim();
            var options = vehicleId === '' ? [] : getDriverOptionsForVehicle(vehicleId);
            var placeholderLabel = '-- Selecteaza --';

            if (vehicleId === '') {
                placeholderLabel = '-- Selecteaza mai intai vehiculul --';
            } else if (options.length === 0) {
                placeholderLabel = '-- Niciun sofer asignat --';
            }

            // Sandbox: la schimbarea vehiculului, lista revine la soferii asignati.
            if (vehicleId !== driverListVehicleId) {
                driverListVehicleId = vehicleId;
                driverListExpanded = false;
            }

            if (vehicleId !== '' && allDrivers.length > 0) {
                var assignedIds = {};
                options.forEach(function (option) {
                    assignedIds[String(option.value)] = true;
                });
                var otherDrivers = allDrivers.filter(function (driver) {
                    return driver && !Object.prototype.hasOwnProperty.call(assignedIds, String(driver.id || ''));
                });

                if (driverListExpanded && otherDrivers.length > 0) {
                    if (options.length > 0) {
                        options.push({ value: '__sep__', label: '── Alti soferi activi (doar pentru aceasta cursa) ──', disabled: true });
                    }
                    otherDrivers.forEach(function (driver) {
                        options.push({ value: String(driver.id), label: String(driver.nume || driver.id) });
                    });
                } else if (!driverListExpanded && otherDrivers.length > 0) {
                    options.push({ value: SHOW_ALL_DRIVERS_VALUE, label: '➕ Alt sofer (arata toti soferii activi)...' });
                }
            }

            rebuildDriverSelectOptions(options, selectedDriverId, placeholderLabel);
        }

        function syncVehicleOptionsByContext() {
            if (!(vehicleField instanceof HTMLSelectElement)) {
                return;
            }

            var beneficiaryId = String(beneficiaryField ? (beneficiaryField.value || '') : '').trim();
            var transportType = String(tipField ? (tipField.value || '') : '').trim();
            var selectedVehicleId = String(vehicleField.value || '').trim();
            var allowedVehicleOptions = getEligibleVehicleOptions(beneficiaryId, transportType);

            var placeholderLabel = '-- Selecteaza --';
            if (beneficiaryId === '') {
                placeholderLabel = '-- Selecteaza mai intai beneficiarul --';
            } else if (transportType === '' || !isTransportSupportedForBeneficiary(beneficiaryId, transportType)) {
                placeholderLabel = '-- Tip transport indisponibil pentru beneficiar --';
            } else if (allowedVehicleOptions.length === 0) {
                if (isPrimaryTransport(transportType)) {
                    placeholderLabel = '-- Configureaza vehiculele Primar in Configurare transport --';
                } else if (transportType === 'compresor') {
                    placeholderLabel = '-- Configureaza vehiculele Compresor in Configurare transport --';
                } else {
                    placeholderLabel = '-- Configureaza vehiculele pentru acest tip de transport --';
                }
            }

            // Sandbox: la schimbarea contextului (beneficiar / tip transport) lista revine la vehiculele configurate.
            var vehicleContextKey = beneficiaryId + '|' + transportType;
            if (vehicleContextKey !== vehicleListContextKey) {
                vehicleListContextKey = vehicleContextKey;
                vehicleListExpanded = false;
            }

            lastEligibleVehicleSet = {};
            allowedVehicleOptions.forEach(function (option) {
                lastEligibleVehicleSet[String(option.value || '').trim()] = true;
            });

            // Extinderea listei este disponibila doar in formularul Adauga Cursa (care are
            // campul de decizie); formularul de editare pastreaza comportamentul existent.
            var vehicleExpansionAvailable = form.querySelector('[data-vehicle-config-decision]') !== null;

            var displayVehicleOptions = allowedVehicleOptions.slice();
            if (vehicleExpansionAvailable && beneficiaryId !== '' && transportType !== '' && isTransportSupportedForBeneficiary(beneficiaryId, transportType)) {
                var otherVehicles = initialVehicleOptions.filter(function (option) {
                    var optionValue = String(option.value || '').trim();
                    return optionValue !== '' && !Object.prototype.hasOwnProperty.call(lastEligibleVehicleSet, optionValue);
                });
                if (vehicleListExpanded && otherVehicles.length > 0) {
                    if (displayVehicleOptions.length > 0) {
                        displayVehicleOptions.push({ value: '__sep_vehicles__', label: '── Alte vehicule (necesita decizie) ──', disabled: true });
                    }
                    displayVehicleOptions = displayVehicleOptions.concat(otherVehicles);
                } else if (!vehicleListExpanded && otherVehicles.length > 0) {
                    displayVehicleOptions.push({ value: SHOW_ALL_VEHICLES_VALUE, label: '➕ Alt vehicul (arata toate vehiculele)...' });
                }
            }

            rebuildVehicleSelectOptions(displayVehicleOptions, selectedVehicleId, placeholderLabel);
        }

        // Sandbox: decizia adminului pentru un vehicul neconfigurat pe ruta curenta.
        var vehicleDecisionField = form.querySelector('[data-vehicle-config-decision]');
        var vehicleDecisionModalEl = document.querySelector('[data-vehicle-route-decision-modal]');
        var vehicleDecisionModal = vehicleDecisionModalEl instanceof HTMLElement && typeof bootstrap !== 'undefined' && bootstrap.Modal
            ? bootstrap.Modal.getOrCreateInstance(vehicleDecisionModalEl)
            : null;
        var vehicleDecisionNameEl = vehicleDecisionModalEl instanceof HTMLElement
            ? vehicleDecisionModalEl.querySelector('[data-vehicle-route-decision-name]')
            : null;
        var vehicleDecisionResolved = false;

        function setVehicleConfigDecision(value) {
            if (vehicleDecisionField instanceof HTMLInputElement) {
                vehicleDecisionField.value = String(value || '');
            }
        }

        function maybePromptVehicleRouteDecision() {
            if (!(vehicleDecisionField instanceof HTMLInputElement)) {
                // Formular fara camp de decizie (ex. editare) -> comportament neschimbat.
                return;
            }
            var vehicleId = String(vehicleField.value || '').trim();
            if (vehicleId === '' || Object.prototype.hasOwnProperty.call(lastEligibleVehicleSet, vehicleId)) {
                // Vehicul configurat pe ruta -> nu este nevoie de decizie.
                setVehicleConfigDecision('');
                return;
            }

            var selectedOption = vehicleField.options[vehicleField.selectedIndex];
            var vehicleLabel = selectedOption ? String(selectedOption.textContent || vehicleId) : vehicleId;

            if (vehicleDecisionModal && vehicleDecisionModalEl instanceof HTMLElement) {
                if (vehicleDecisionNameEl instanceof HTMLElement) {
                    vehicleDecisionNameEl.textContent = vehicleLabel;
                }
                vehicleDecisionResolved = false;
                vehicleDecisionModal.show();
                return;
            }

            // Fallback fara bootstrap: confirm() simplu.
            var permanent = window.confirm(
                'Vehiculul ' + vehicleLabel + ' nu este configurat pe aceasta ruta.\n\n'
                + 'OK = adauga permanent pe ruta (Configurare Transport)\n'
                + 'Cancel = foloseste doar pentru aceasta cursa'
            );
            setVehicleConfigDecision(permanent ? 'permanent' : 'trip');
        }

        if (vehicleDecisionModalEl instanceof HTMLElement) {
            var vehicleDecisionTripBtn = vehicleDecisionModalEl.querySelector('[data-vehicle-route-decision-trip]');
            var vehicleDecisionPermanentBtn = vehicleDecisionModalEl.querySelector('[data-vehicle-route-decision-permanent]');

            if (vehicleDecisionTripBtn instanceof HTMLElement) {
                vehicleDecisionTripBtn.addEventListener('click', function () {
                    setVehicleConfigDecision('trip');
                    vehicleDecisionResolved = true;
                    if (vehicleDecisionModal) {
                        vehicleDecisionModal.hide();
                    }
                });
            }
            if (vehicleDecisionPermanentBtn instanceof HTMLElement) {
                vehicleDecisionPermanentBtn.addEventListener('click', function () {
                    setVehicleConfigDecision('permanent');
                    vehicleDecisionResolved = true;
                    if (vehicleDecisionModal) {
                        vehicleDecisionModal.hide();
                    }
                });
            }
            vehicleDecisionModalEl.addEventListener('hidden.bs.modal', function () {
                if (!vehicleDecisionResolved) {
                    // Renuntare: vehiculul neconfigurat este deselectat.
                    setVehicleConfigDecision('');
                    if (vehicleField instanceof HTMLSelectElement) {
                        vehicleField.value = '';
                        vehicleField.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            });
        }

        var inactiveDecisionField = form.querySelector('[data-inactive-approval-decision]');
        var inactiveSignatureField = form.querySelector('[data-inactive-approval-signature]');
        var inactiveStatusUrl = String(form.getAttribute('data-inactive-resource-status-url') || '').trim();
        var inactiveTripId = String(form.getAttribute('data-inactive-trip-id') || '').trim();
        var inactiveModalEl = document.querySelector('[data-inactive-resource-modal]');
        var inactiveModal = inactiveModalEl instanceof HTMLElement && typeof bootstrap !== 'undefined' && bootstrap.Modal
            ? bootstrap.Modal.getOrCreateInstance(inactiveModalEl)
            : null;
        var inactiveModalTitle = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-modal-title]') : null;
        var inactiveModalBody = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-modal-body]') : null;
        var inactiveModalIcon = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-modal-icon]') : null;
        var inactiveSessionOption = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-session-option]') : null;
        var inactiveSessionLabel = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-session-label]') : null;
        var inactiveSessionCheckbox = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-session-dismiss]') : null;
        var inactiveApproveNowButton = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-approve-now]') : null;
        var inactiveApproveLaterButton = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-approve-later]') : null;
        var inactiveAdminCancelButton = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-admin-cancel]') : null;
        var inactiveRequestApprovalButton = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-request-approval]') : null;
        var inactivePostponeButton = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-postpone]') : null;
        var inactiveCloseButton = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-close]') : null;
        var inactiveCancelRequestButton = inactiveModalEl instanceof HTMLElement ? inactiveModalEl.querySelector('[data-inactive-resource-cancel-request]') : null;
        var inactiveApprovalMode = String(form.getAttribute('data-inactive-approval-mode') || 'admin').trim();
        var inactiveUserApprovalFlow = inactiveApprovalMode === 'user';
        var inactiveApprovalRequestUrl = String(form.getAttribute('data-inactive-approval-request-url') || '').trim();
        var inactiveApprovalCancelUrl = String(form.getAttribute('data-inactive-approval-cancel-url') || '').trim();
        var pendingInactiveResources = [];
        var pendingInactiveApproval = null;
        var pendingInactiveSubmit = false;
        var inactiveStatusSequence = 0;

        function getInactiveSelectionSignature() {
            var vehicleId = vehicleField instanceof HTMLSelectElement ? String(vehicleField.value || '').trim() : '';
            var driverId = driverField instanceof HTMLSelectElement ? String(driverField.value || '').trim() : '';

            return [vehicleId, driverId, inactiveTripId].join(':');
        }

        function clearInactiveApprovalDecision() {
            if (inactiveDecisionField instanceof HTMLInputElement) {
                inactiveDecisionField.value = '';
            }
            if (inactiveSignatureField instanceof HTMLInputElement) {
                inactiveSignatureField.value = '';
            }
        }

        function setInactiveApprovalDecision(decision) {
            if (inactiveDecisionField instanceof HTMLInputElement) {
                inactiveDecisionField.value = decision;
            }
            if (inactiveSignatureField instanceof HTMLInputElement) {
                inactiveSignatureField.value = getInactiveSelectionSignature();
            }
        }

        function inactiveApprovalDecisionIsCurrent() {
            if (!(inactiveDecisionField instanceof HTMLInputElement) || !(inactiveSignatureField instanceof HTMLInputElement)) {
                return true;
            }

            var decision = String(inactiveDecisionField.value || '').trim();
            if (decision !== 'approved' && decision !== 'pending') {
                return false;
            }

            return String(inactiveSignatureField.value || '').trim() === getInactiveSelectionSignature();
        }

        function setInactiveElementVisible(element, visible) {
            if (element instanceof HTMLElement) {
                element.classList.toggle('d-none', !visible);
            }
        }

        function setInactiveModalIcon(tone) {
            if (!(inactiveModalIcon instanceof HTMLElement)) {
                return;
            }

            inactiveModalIcon.classList.toggle('is-success', tone === 'success');
            inactiveModalIcon.classList.toggle('is-warning', tone !== 'success');
            inactiveModalIcon.innerHTML = '';
            var icon = document.createElement('i');
            icon.className = tone === 'success' ? 'bi bi-check-lg' : 'bi bi-exclamation-triangle-fill';
            icon.setAttribute('aria-hidden', 'true');
            inactiveModalIcon.appendChild(icon);
        }

        function setInactiveModalControls(mode) {
            var isAdmin = mode === 'admin';
            var isUserBefore = mode === 'user-before';
            var isUserSent = mode === 'user-sent';
            var isUserReadonly = mode === 'user-readonly';

            setInactiveElementVisible(inactiveAdminCancelButton, isAdmin);
            setInactiveElementVisible(inactiveApproveLaterButton, isAdmin);
            setInactiveElementVisible(inactiveApproveNowButton, isAdmin);
            setInactiveElementVisible(inactiveRequestApprovalButton, isUserBefore);
            setInactiveElementVisible(inactivePostponeButton, isUserBefore);
            setInactiveElementVisible(inactiveCloseButton, isUserBefore || isUserSent || isUserReadonly);
            setInactiveElementVisible(inactiveCancelRequestButton, isUserSent && pendingInactiveApproval && pendingInactiveApproval.can_cancel !== false);

            if (inactiveModalEl instanceof HTMLElement) {
                inactiveModalEl.classList.toggle('is-user-approval-flow', !isAdmin);
                inactiveModalEl.classList.toggle('is-user-before', isUserBefore);
                inactiveModalEl.classList.toggle('is-user-sent', isUserSent);
            }
        }

        function getInactiveCsrfToken() {
            var tokenField = form.querySelector('input[name="_token"]');
            return tokenField instanceof HTMLInputElement ? String(tokenField.value || '') : '';
        }

        function postInactiveApprovalJson(url, data) {
            var formData = new FormData();
            formData.append('_token', getInactiveCsrfToken());
            Object.keys(data || {}).forEach(function (key) {
                formData.append(key, String(data[key] || ''));
            });

            return fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            }).then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (payload) {
                    if (!response.ok || !payload.success) {
                        throw new Error(String(payload.message || 'Solicitarea nu a putut fi procesata.'));
                    }

                    return payload;
                });
            });
        }

        function clearInactiveApprovalFeedback() {
            if (!(inactiveModalBody instanceof HTMLElement)) {
                return;
            }

            inactiveModalBody.querySelectorAll('[data-inactive-approval-feedback]').forEach(function (element) {
                element.remove();
            });
        }

        function showInactiveApprovalFeedback(message) {
            if (!(inactiveModalBody instanceof HTMLElement)) {
                return;
            }

            clearInactiveApprovalFeedback();
            var feedback = document.createElement('div');
            feedback.className = 'inactive-user-approval-alert is-error';
            feedback.setAttribute('data-inactive-approval-feedback', 'error');
            var icon = document.createElement('i');
            icon.className = 'bi bi-exclamation-triangle';
            icon.setAttribute('aria-hidden', 'true');
            feedback.appendChild(icon);
            var text = document.createElement('span');
            text.textContent = String(message || 'Solicitarea nu a putut fi anulata. Reincearca.');
            feedback.appendChild(text);
            inactiveModalBody.insertBefore(feedback, inactiveModalBody.firstChild);
        }

        function inactiveResourceKey(resource) {
            if (!resource || typeof resource !== 'object') {
                return '';
            }

            return [
                'inactive-resource-warning',
                String(resource.resource_type || '').trim(),
                String(resource.resource_id || '').trim(),
                inactiveTripId || 'new'
            ].join(':');
        }

        function isInactiveResourceSuppressed(resource) {
            var key = inactiveResourceKey(resource);
            if (key === '') {
                return false;
            }

            try {
                return window.sessionStorage.getItem(key) === '1';
            } catch (error) {
                return false;
            }
        }

        function suppressInactiveResource(resource) {
            var key = inactiveResourceKey(resource);
            if (key === '') {
                return;
            }

            try {
                window.sessionStorage.setItem(key, '1');
            } catch (error) {
            }
        }

        function shouldAskForInactiveResource(resource) {
            if (!resource || typeof resource !== 'object' || !resource.is_inactive) {
                return false;
            }

            if (normalUserVehicleResource(resource) && userPendingApprovalForResource(resource) !== null) {
                return false;
            }

            var existingStatus = String(resource.existing_approval_status || '').trim();
            return existingStatus !== 'pending' && existingStatus !== 'approved';
        }

        function getPromptableInactiveResources(resources, ignoreSuppression) {
            if (!Array.isArray(resources)) {
                return [];
            }

            return resources.filter(function (resource) {
                return shouldAskForInactiveResource(resource) && (ignoreSuppression === true || !isInactiveResourceSuppressed(resource));
            });
        }

        function getDecisionRequiredInactiveResources(resources) {
            if (!Array.isArray(resources)) {
                return [];
            }

            return resources.filter(shouldAskForInactiveResource);
        }

        function formatInactiveDate(value) {
            var raw = String(value || '').trim();
            var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (!match) {
                return '-';
            }

            return match[3] + '.' + match[2] + '.' + match[1];
        }

        function formatInactiveDateTime(value) {
            var raw = String(value || '').trim();
            var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
            if (!match) {
                return raw !== '' ? raw : '-';
            }

            var date = match[3] + '.' + match[2] + '.' + match[1];
            if (match[4] && match[5]) {
                return date + ', ' + match[4] + ':' + match[5];
            }

            return date;
        }

        function inactiveContextLabel(value) {
            var label = String(value || 'Dispecer curse').replace(/_/g, ' ').trim();
            return label !== '' ? label : 'Dispecer curse';
        }

        function userPendingApprovalForResource(resource) {
            if (!resource || typeof resource !== 'object') {
                return null;
            }

            var approval = resource.user_pending_approval;
            return approval && typeof approval === 'object' ? approval : null;
        }

        function normalUserVehicleResource(resource) {
            return inactiveUserApprovalFlow
                && inactiveApprovalRequestUrl !== ''
                && inactiveApprovalCancelUrl !== ''
                && resource
                && typeof resource === 'object'
                && String(resource.resource_type || '').trim() === 'vehicle';
        }

        function inactiveDocumentsLabel(resource) {
            var names = [];
            if (Array.isArray(resource.affected_document_names)) {
                resource.affected_document_names.forEach(function (name) {
                    var normalized = String(name || '').trim();
                    if (normalized !== '') {
                        names.push(normalized);
                    }
                });
            }

            if (names.length === 0 && Array.isArray(resource.documents)) {
                resource.documents.forEach(function (documentRow) {
                    if (!documentRow || typeof documentRow !== 'object') {
                        return;
                    }
                    var normalized = String(documentRow.document_name || documentRow.document_type || '').trim();
                    if (normalized !== '') {
                        names.push(normalized);
                    }
                });
            }

            return names.filter(function (name, index, array) {
                return array.indexOf(name) === index;
            }).join(', ');
        }

        function appendInactiveInfoLine(list, label, value) {
            var normalizedValue = String(value || '').trim();
            if (normalizedValue === '') {
                return;
            }

            var dt = document.createElement('dt');
            dt.textContent = label;
            var dd = document.createElement('dd');
            dd.textContent = normalizedValue;
            list.appendChild(dt);
            list.appendChild(dd);
        }

        function renderInactiveModal(resources) {
            if (!(inactiveModalBody instanceof HTMLElement)) {
                return;
            }

            inactiveModalBody.innerHTML = '';
            var intro = document.createElement('p');
            intro.className = 'inactive-resource-warning-intro';
            intro.textContent = resources.length === 1
                ? 'Continuarea utilizarii acestei resurse in Dispecer curse se face pe propria raspundere.'
                : 'Continuarea utilizarii acestor resurse in Dispecer curse se face pe propria raspundere.';
            inactiveModalBody.appendChild(intro);

            resources.forEach(function (resource) {
                var card = document.createElement('article');
                card.className = 'inactive-resource-warning-card';

                var badge = document.createElement('span');
                badge.className = 'inactive-resource-warning-badge tone-' + String(resource.reason_tone || 'muted').trim();
                var badgeIcon = document.createElement('i');
                badgeIcon.className = 'bi ' + String(resource.reason_icon || 'bi-exclamation-circle').trim();
                badgeIcon.setAttribute('aria-hidden', 'true');
                badge.appendChild(badgeIcon);
                badge.appendChild(document.createTextNode(String(resource.reason_label || 'Alt motiv')));
                card.appendChild(badge);

                var title = document.createElement('h6');
                title.textContent = String(resource.resource_label || 'Resursa inactiva');
                card.appendChild(title);

                var list = document.createElement('dl');
                list.className = 'inactive-resource-warning-list';
                appendInactiveInfoLine(list, 'Motiv:', String(resource.reason_label || 'Alt motiv'));
                appendInactiveInfoLine(list, 'Documente afectate:', inactiveDocumentsLabel(resource));
                appendInactiveInfoLine(list, 'Detaliu:', String(resource.detail || '').trim());
                appendInactiveInfoLine(list, 'Inactiv din:', formatInactiveDate(resource.inactive_since));
                appendInactiveInfoLine(list, 'Utilizat in:', String(resource.usage_context || 'Dispecer curse').replace(/_/g, ' '));
                card.appendChild(list);
                inactiveModalBody.appendChild(card);
            });
        }

        function appendUserApprovalLine(list, label, value) {
            var dt = document.createElement('dt');
            dt.textContent = label;
            var dd = document.createElement('dd');
            var normalizedValue = String(value || '').trim();
            dd.textContent = normalizedValue !== '' ? normalizedValue : '-';
            list.appendChild(dt);
            list.appendChild(dd);
        }

        function renderUserInactiveVehicleRequestModal(resource) {
            if (!(inactiveModalBody instanceof HTMLElement)) {
                return;
            }

            inactiveModalBody.innerHTML = '';

            var warning = document.createElement('div');
            warning.className = 'inactive-user-approval-alert is-warning';
            var warningIcon = document.createElement('i');
            warningIcon.className = 'bi bi-exclamation-triangle';
            warningIcon.setAttribute('aria-hidden', 'true');
            warning.appendChild(warningIcon);
            var warningText = document.createElement('span');
            warningText.textContent = 'Acest vehicul este marcat ca inactiv. Solicita aprobarea unui administrator pentru utilizare.';
            warning.appendChild(warningText);
            inactiveModalBody.appendChild(warning);

            var card = document.createElement('article');
            card.className = 'inactive-user-approval-card';

            var title = document.createElement('h6');
            title.textContent = String(resource.resource_label || 'Vehicul inactiv');
            card.appendChild(title);

            var list = document.createElement('dl');
            list.className = 'inactive-resource-warning-list inactive-user-approval-list';
            appendUserApprovalLine(list, 'Motiv inactivitate:', String(resource.reason_label || 'Alt motiv'));
            appendUserApprovalLine(list, 'Documente afectate:', inactiveDocumentsLabel(resource));
            appendUserApprovalLine(list, 'Inactiv din:', formatInactiveDate(resource.inactive_since));
            appendUserApprovalLine(list, 'Utilizat in:', inactiveContextLabel(resource.usage_context));
            card.appendChild(list);
            inactiveModalBody.appendChild(card);
        }

        function renderUserInactiveVehicleSentModal(resource, approval) {
            if (!(inactiveModalBody instanceof HTMLElement)) {
                return;
            }

            inactiveModalBody.innerHTML = '';

            var success = document.createElement('div');
            success.className = 'inactive-user-approval-alert is-success';
            var successIcon = document.createElement('i');
            successIcon.className = 'bi bi-check-circle';
            successIcon.setAttribute('aria-hidden', 'true');
            success.appendChild(successIcon);
            var successCopy = document.createElement('div');
            var successTitle = document.createElement('strong');
            successTitle.textContent = 'Cererea ta a fost trimisa administratorului pentru aprobare.';
            var successBody = document.createElement('span');
            successBody.textContent = 'Vei fi notificat imediat ce cererea ta este aprobata sau respinsa.';
            successCopy.appendChild(successTitle);
            successCopy.appendChild(successBody);
            success.appendChild(successCopy);
            inactiveModalBody.appendChild(success);

            var card = document.createElement('article');
            card.className = 'inactive-user-approval-card';

            var badge = document.createElement('span');
            badge.className = 'inactive-user-approval-status-badge';
            var badgeIcon = document.createElement('i');
            badgeIcon.className = 'bi bi-clock';
            badgeIcon.setAttribute('aria-hidden', 'true');
            badge.appendChild(badgeIcon);
            badge.appendChild(document.createTextNode(String(approval.status_label || 'In asteptare')));
            card.appendChild(badge);

            var title = document.createElement('h6');
            title.textContent = String(approval.resource_label || resource.resource_label || 'Vehicul inactiv');
            card.appendChild(title);

            var list = document.createElement('dl');
            list.className = 'inactive-resource-warning-list inactive-user-approval-list';
            appendUserApprovalLine(list, 'Motiv inactivitate:', String(approval.inactive_reason_label || resource.reason_label || 'Alt motiv'));
            appendUserApprovalLine(list, 'Documente afectate:', Array.isArray(approval.affected_document_names) && approval.affected_document_names.length > 0 ? approval.affected_document_names.join(', ') : inactiveDocumentsLabel(resource));
            appendUserApprovalLine(list, 'Inactiv din:', formatInactiveDate(approval.inactive_since || resource.inactive_since));
            appendUserApprovalLine(list, 'Utilizat in:', inactiveContextLabel(approval.usage_context || resource.usage_context));
            appendUserApprovalLine(list, 'Solicitat la:', formatInactiveDateTime(approval.requested_at));
            appendUserApprovalLine(list, 'Solicitat de:', String(approval.requested_by_name || '') + ' (Tu)');
            appendUserApprovalLine(list, 'Tip solicitare:', String(approval.resource_type_label || 'Vehicul'));
            card.appendChild(list);
            inactiveModalBody.appendChild(card);

            var info = document.createElement('div');
            info.className = 'inactive-user-approval-alert is-info';
            var infoIcon = document.createElement('i');
            infoIcon.className = 'bi bi-info-lg';
            infoIcon.setAttribute('aria-hidden', 'true');
            info.appendChild(infoIcon);
            var infoCopy = document.createElement('div');
            var infoTitle = document.createElement('strong');
            infoTitle.textContent = 'Ce urmeaza?';
            var infoBody = document.createElement('span');
            infoBody.textContent = 'Administratorul va verifica cererea si va decide daca o aproba sau o respinge. Userul va primi notificare cand exista un raspuns.';
            infoCopy.appendChild(infoTitle);
            infoCopy.appendChild(infoBody);
            info.appendChild(infoCopy);
            inactiveModalBody.appendChild(info);

            var cancelHelp = document.createElement('div');
            cancelHelp.className = 'inactive-user-approval-alert is-cancel-help';
            var cancelHelpIcon = document.createElement('i');
            cancelHelpIcon.className = 'bi bi-exclamation-triangle';
            cancelHelpIcon.setAttribute('aria-hidden', 'true');
            cancelHelp.appendChild(cancelHelpIcon);
            var cancelHelpCopy = document.createElement('div');
            var cancelHelpTitle = document.createElement('strong');
            cancelHelpTitle.textContent = 'Poti anula solicitarea cat timp este in asteptare.';
            var cancelHelpBody = document.createElement('span');
            cancelHelpBody.textContent = 'Daca ai selectat din greseala acest vehicul, poti anula cererea si solicita din nou pentru alt vehicul.';
            cancelHelpCopy.appendChild(cancelHelpTitle);
            cancelHelpCopy.appendChild(cancelHelpBody);
            cancelHelp.appendChild(cancelHelpCopy);
            inactiveModalBody.appendChild(cancelHelp);
        }

        function titleForInactiveResources(resources) {
            if (resources.length !== 1) {
                return 'Resurse inactive selectate';
            }

            return String(resources[0].resource_type || '') === 'driver'
                ? 'Sofer inactiv utilizat'
                : 'Vehicul inactiv utilizat';
        }

        function showInactiveModal(resources, shouldSubmitAfterDecision) {
            if (!(inactiveModalTitle instanceof HTMLElement) || inactiveModal === null) {
                return false;
            }

            clearInactiveApprovalFeedback();
            pendingInactiveApproval = null;
            pendingInactiveResources = resources.slice();
            pendingInactiveSubmit = shouldSubmitAfterDecision === true;
            if (inactiveSessionCheckbox instanceof HTMLInputElement) {
                inactiveSessionCheckbox.checked = false;
            }

            if (resources.length === 1 && normalUserVehicleResource(resources[0])) {
                inactiveModalTitle.textContent = 'Vehicul inactiv utilizat';
                setInactiveModalIcon('warning');
                setInactiveModalControls('user-before');
                if (inactiveSessionOption instanceof HTMLElement) {
                    inactiveSessionOption.classList.remove('d-none');
                }
                if (inactiveSessionLabel instanceof HTMLElement) {
                    inactiveSessionLabel.textContent = 'Nu mai afisa acest mesaj pentru acest vehicul in aceasta sesiune';
                }
                renderUserInactiveVehicleRequestModal(resources[0]);
                inactiveModal.show();

                return true;
            }

            inactiveModalTitle.textContent = titleForInactiveResources(resources);
            setInactiveModalIcon('warning');
            setInactiveModalControls(inactiveUserApprovalFlow ? 'user-readonly' : 'admin');
            if (inactiveSessionOption instanceof HTMLElement) {
                inactiveSessionOption.classList.remove('d-none');
            }
            if (inactiveSessionLabel instanceof HTMLElement) {
                inactiveSessionLabel.textContent = 'Nu mai afisa pentru aceasta selectie in aceasta sesiune';
            }
            renderInactiveModal(resources);
            inactiveModal.show();

            return true;
        }

        function showInactiveApprovalSentModal(resource, shouldSubmitAfterDecision) {
            var approval = userPendingApprovalForResource(resource);
            if (!(inactiveModalTitle instanceof HTMLElement) || inactiveModal === null || approval === null) {
                return false;
            }

            clearInactiveApprovalFeedback();
            pendingInactiveResources = [resource];
            pendingInactiveApproval = approval;
            pendingInactiveSubmit = shouldSubmitAfterDecision === true;
            inactiveModalTitle.textContent = 'Solicitare de aprobare trimisa';
            setInactiveModalIcon('success');
            setInactiveModalControls('user-sent');
            if (inactiveSessionCheckbox instanceof HTMLInputElement) {
                inactiveSessionCheckbox.checked = false;
            }
            if (inactiveSessionOption instanceof HTMLElement) {
                inactiveSessionOption.classList.add('d-none');
            }
            renderUserInactiveVehicleSentModal(resource, approval);
            inactiveModal.show();

            return true;
        }

        function submitRaceFormAfterInactiveDecision() {
            form.dataset.inactiveApprovalBypass = '1';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.submit();
        }

        function completeInactiveDecision(decision) {
            setInactiveApprovalDecision(decision);
            if (inactiveSessionCheckbox instanceof HTMLInputElement && inactiveSessionCheckbox.checked) {
                pendingInactiveResources.forEach(suppressInactiveResource);
            }
            if (inactiveModal !== null) {
                inactiveModal.hide();
            }

            if (pendingInactiveSubmit) {
                pendingInactiveSubmit = false;
                submitRaceFormAfterInactiveDecision();
            }
        }

        function requestInactiveVehicleApproval() {
            var resource = pendingInactiveResources.find(normalUserVehicleResource);
            if (!resource || inactiveApprovalRequestUrl === '') {
                return;
            }

            clearInactiveApprovalFeedback();
            if (inactiveRequestApprovalButton instanceof HTMLButtonElement) {
                inactiveRequestApprovalButton.disabled = true;
            }

            postInactiveApprovalJson(inactiveApprovalRequestUrl, {
                vehicle_id: resource.resource_id
            }).then(function (payload) {
                var updatedResource = Object.assign({}, resource, payload.resource || {});
                if (payload.approval && typeof payload.approval === 'object') {
                    updatedResource.user_pending_approval = payload.approval;
                    updatedResource.existing_approval_status = 'pending';
                }

                pendingInactiveSubmit = false;
                clearInactiveApprovalDecision();
                showInactiveApprovalSentModal(updatedResource, false);
                window.dispatchEvent(new CustomEvent('inactiveApprovalRequestChanged', {
                    detail: {
                        action: 'created',
                        approval: payload.approval || null
                    }
                }));
            }).catch(function (error) {
                alert(error.message || 'Solicitarea nu a putut fi trimisa. Reincearca.');
            }).finally(function () {
                if (inactiveRequestApprovalButton instanceof HTMLButtonElement) {
                    inactiveRequestApprovalButton.disabled = false;
                }
            });
        }

        function postponeInactiveVehicleApproval() {
            if (inactiveSessionCheckbox instanceof HTMLInputElement && inactiveSessionCheckbox.checked) {
                pendingInactiveResources.forEach(suppressInactiveResource);
            }

            pendingInactiveSubmit = false;
            pendingInactiveApproval = null;
            clearInactiveApprovalDecision();
        }

        function cancelInactiveVehicleApprovalRequest() {
            if (!pendingInactiveApproval || inactiveApprovalCancelUrl === '') {
                return;
            }

            if (!window.confirm('Sigur dorești să anulezi această solicitare?')) {
                return;
            }

            clearInactiveApprovalFeedback();
            if (inactiveCancelRequestButton instanceof HTMLButtonElement) {
                inactiveCancelRequestButton.disabled = true;
            }

            var approvalId = pendingInactiveApproval.id;
            postInactiveApprovalJson(inactiveApprovalCancelUrl, {
                approval_id: approvalId
            }).then(function (payload) {
                var resource = pendingInactiveResources[0] || null;
                if (resource && typeof resource === 'object') {
                    resource = Object.assign({}, resource);
                    resource.user_pending_approval = null;
                    resource.existing_approval_status = null;
                }

                pendingInactiveApproval = null;
                pendingInactiveResources = resource && typeof resource === 'object' ? [resource] : [];
                clearInactiveApprovalDecision();
                if (inactiveModal !== null) {
                    inactiveModal.hide();
                }

                window.dispatchEvent(new CustomEvent('inactiveApprovalRequestChanged', {
                    detail: {
                        action: 'cancelled',
                        approval_id: approvalId,
                        message: payload.message || '',
                        summary: payload.summary || null
                    }
                }));
            }).catch(function (error) {
                showInactiveApprovalFeedback(error.message || 'Solicitarea nu a putut fi anulata. Reincearca.');
            }).finally(function () {
                if (inactiveCancelRequestButton instanceof HTMLButtonElement) {
                    inactiveCancelRequestButton.disabled = false;
                }
            });
        }

        function fetchInactiveResourceStatus() {
            if (inactiveStatusUrl === '') {
                return Promise.resolve({ inactive_resources: [] });
            }

            var vehicleId = vehicleField instanceof HTMLSelectElement ? String(vehicleField.value || '').trim() : '';
            var driverId = driverField instanceof HTMLSelectElement ? String(driverField.value || '').trim() : '';
            if (vehicleId === '' && driverId === '') {
                return Promise.resolve({ inactive_resources: [] });
            }

            var url = new URL(inactiveStatusUrl, window.location.origin);
            if (vehicleId !== '') {
                url.searchParams.set('vehicle_id', vehicleId);
            }
            if (driverId !== '') {
                url.searchParams.set('driver_id', driverId);
            }
            if (inactiveTripId !== '') {
                url.searchParams.set('trip_id', inactiveTripId);
            }

            return fetch(url.toString(), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Inactive resource status request failed.');
                }

                return response.json();
            });
        }

        function checkInactiveResourcesForSelection(options) {
            var config = options || {};
            var sequence = ++inactiveStatusSequence;

            return fetchInactiveResourceStatus().then(function (payload) {
                if (sequence !== inactiveStatusSequence) {
                    return { inactiveResources: [], decisionRequired: [], promptable: [] };
                }

                var inactiveResources = Array.isArray(payload.inactive_resources) ? payload.inactive_resources : [];
                var decisionRequired = getDecisionRequiredInactiveResources(inactiveResources);
                var promptable = getPromptableInactiveResources(inactiveResources, config.ignoreSuppression === true);

                if (config.showModal) {
                    var pendingUserVehicle = inactiveResources.find(function (resource) {
                        return normalUserVehicleResource(resource) && userPendingApprovalForResource(resource) !== null;
                    }) || null;
                    if (pendingUserVehicle !== null) {
                        showInactiveApprovalSentModal(pendingUserVehicle, config.submitAfterDecision === true);
                    } else if (promptable.length > 0) {
                        var promptableUserVehicle = promptable.find(normalUserVehicleResource) || null;
                        showInactiveModal(
                            promptableUserVehicle !== null ? [promptableUserVehicle] : promptable,
                            config.submitAfterDecision === true
                        );
                    }
                }

                return {
                    inactiveResources: inactiveResources,
                    decisionRequired: decisionRequired,
                    promptable: promptable
                };
            });
        }

        function promptInactiveResourcesAfterSelectionChange() {
            clearInactiveApprovalDecision();
            if (inactiveStatusUrl === '') {
                return;
            }

            window.setTimeout(function () {
                checkInactiveResourcesForSelection({ showModal: true, submitAfterDecision: false }).catch(function () {
                });
            }, 0);
        }

        if (inactiveApproveNowButton instanceof HTMLButtonElement) {
            inactiveApproveNowButton.addEventListener('click', function () {
                completeInactiveDecision('approved');
            });
        }

        if (inactiveApproveLaterButton instanceof HTMLButtonElement) {
            inactiveApproveLaterButton.addEventListener('click', function () {
                completeInactiveDecision('pending');
            });
        }

        if (inactiveRequestApprovalButton instanceof HTMLButtonElement) {
            inactiveRequestApprovalButton.addEventListener('click', requestInactiveVehicleApproval);
        }

        if (inactivePostponeButton instanceof HTMLButtonElement) {
            inactivePostponeButton.addEventListener('click', postponeInactiveVehicleApproval);
        }

        if (inactiveCancelRequestButton instanceof HTMLButtonElement) {
            inactiveCancelRequestButton.addEventListener('click', cancelInactiveVehicleApprovalRequest);
        }

        if (inactiveModalEl instanceof HTMLElement) {
            inactiveModalEl.addEventListener('hidden.bs.modal', function () {
                pendingInactiveSubmit = false;
                pendingInactiveResources = [];
                pendingInactiveApproval = null;
            });
        }

        function setFieldState(wrapper, field, enabled, hideWhenDisabled) {
            if (!field) {
                return;
            }

            if (wrapper) {
                wrapper.classList.toggle('dispatcher-field-disabled', !enabled);
                if (hideWhenDisabled) {
                    wrapper.classList.toggle('d-none', !enabled);
                }
            }

            field.disabled = !enabled;
            if (!enabled) {
                field.removeAttribute('required');
            }
        }

        // Pe formularul de editare (are data-inactive-trip-id nenul), o valoare stocata care
        // nu se mai regaseste in lista curenta de optiuni nu se pierde silentios: ramane
        // selectabila, pastrand eticheta initiala. Pe formularul de adaugare comportamentul
        // ramane cel existent (selectia este golita).
        function findOptionLabelForValue(selectField, targetValue) {
            if (!(selectField instanceof HTMLSelectElement) || targetValue === '') {
                return '';
            }
            for (var optionIndex = 0; optionIndex < selectField.options.length; optionIndex++) {
                if (String(selectField.options[optionIndex].value || '') === targetValue) {
                    return String(selectField.options[optionIndex].textContent || '').trim();
                }
            }
            return '';
        }

        function appendPreservedStoredOption(selectField, targetValue, label) {
            if (inactiveTripId === '' || targetValue === '') {
                return false;
            }
            var optionEl = document.createElement('option');
            optionEl.value = targetValue;
            optionEl.textContent = label !== '' ? label : ('#' + targetValue);
            optionEl.setAttribute('data-stored-out-of-scope', '1');
            optionEl.selected = true;
            selectField.appendChild(optionEl);
            return true;
        }

        function rebuildSelectOptions(selectField, options, selectedValue, placeholderLabel) {
            if (!(selectField instanceof HTMLSelectElement)) {
                return;
            }

            var targetValue = String(selectedValue || '');
            var preservedStoredLabel = findOptionLabelForValue(selectField, targetValue);
            var hasSelectedValue = false;
            selectField.innerHTML = '';

            var placeholderOption = document.createElement('option');
            placeholderOption.value = '';
            placeholderOption.textContent = placeholderLabel;
            selectField.appendChild(placeholderOption);

            options.forEach(function (option) {
                var optionValue = String(option.value || '');
                if (optionValue === '') {
                    return;
                }

                var optionEl = document.createElement('option');
                optionEl.value = optionValue;
                optionEl.textContent = String(option.label || optionValue);
                var routeName = String(option.routeName || '').trim();
                if (routeName !== '') {
                    optionEl.setAttribute('data-route-name', routeName);
                }
                if (optionValue === targetValue) {
                    optionEl.selected = true;
                    hasSelectedValue = true;
                }
                selectField.appendChild(optionEl);
            });

            if (!hasSelectedValue && !appendPreservedStoredOption(selectField, targetValue, preservedStoredLabel)) {
                selectField.value = '';
            }
        }

        function getDistributionLocationOptionsForBeneficiary(beneficiaryId) {
            var key = String(beneficiaryId || '');
            if (key === '' || !Object.prototype.hasOwnProperty.call(loadLocationsByBeneficiary, key)) {
                return [];
            }

            return (loadLocationsByBeneficiary[key] || []).map(function (location) {
                var locationId = String(location.id || '');
                var locationName = String(location.nume || '').trim();
                if (locationId === '' || locationName === '') {
                    return null;
                }

                return {
                    value: locationId,
                    label: locationName,
                    routeName: locationName
                };
            }).filter(function (item) {
                return item !== null;
            });
        }

        function getDistributionZoneOptionsForBeneficiary(beneficiaryId) {
            var key = String(beneficiaryId || '');
            if (key === '' || !Object.prototype.hasOwnProperty.call(distributionZonesByBeneficiary, key)) {
                return [];
            }

            return (distributionZonesByBeneficiary[key] || []).map(function (zone) {
                var zoneId = String(zone.id || '');
                var zoneName = String(zone.nume || '').trim();
                if (zoneId === '' || zoneName === '') {
                    return null;
                }

                var zoneLabel = zoneName;
                var zoneTariffValue = parseNumber(zone.tarif_distributie);
                var zoneExtraValue = parseNumber(zone.cost_extra_km);
                if (zoneTariffValue > 0 || zoneExtraValue > 0) {
                    zoneLabel += ' (tarif zonă: '
                        + zoneTariffValue.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                        + ' lei';
                    if (zoneExtraValue > 0) {
                        zoneLabel += ', extra km: '
                            + zoneExtraValue.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                            + ' lei/km';
                    }
                    zoneLabel += ')';
                }

                return {
                    value: zoneId,
                    label: zoneLabel,
                    routeName: zoneName
                };
            }).filter(function (item) {
                return item !== null;
            });
        }

        function getVehicleScopedRouteRules(beneficiaryId, vehicleId, transportType) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            var selectedVehicleId = String(vehicleId || '').trim();
            var rules = beneficiaryKey !== ''
                ? getDistributionRulesForBeneficiary(beneficiaryKey, transportType)
                : [];

            var activeRules = rules.filter(function (rule) {
                return !!rule.active;
            });

            if (activeRules.length === 0) {
                return {
                    hasActiveRules: false,
                    hasVehicleScopedRules: false,
                    scopedRules: []
                };
            }

            var hasVehicleScopedRules = activeRules.some(function (rule) {
                return Array.isArray(rule.vehicleIds) && rule.vehicleIds.length > 0;
            });

            if (!hasVehicleScopedRules) {
                return {
                    hasActiveRules: false,
                    hasVehicleScopedRules: false,
                    scopedRules: []
                };
            }

            var scopedRules = [];
            if (selectedVehicleId !== '') {
                scopedRules = activeRules.filter(function (rule) {
                    return Array.isArray(rule.vehicleIds)
                        && rule.vehicleIds.length > 0
                        && Object.prototype.hasOwnProperty.call(rule.vehicleIdSet || {}, selectedVehicleId);
                });
            }

            return {
                hasActiveRules: true,
                hasVehicleScopedRules: hasVehicleScopedRules,
                scopedRules: scopedRules
            };
        }

        function updateDistributionScopeNotes(routeScope, vehicleId) {
            if (distributionLocationNote) {
                distributionLocationNote.textContent = defaultDistributionLocationNoteText;
            }
            if (distributionZoneNote) {
                distributionZoneNote.textContent = defaultDistributionZoneNoteText;
            }

            if (!routeScope || !routeScope.hasActiveRules) {
                syncFieldHoverHints(String(tipField ? (tipField.value || '') : ''));
                return;
            }

            var scopedText = (routeScope.scopedRules || []).length > 0
                ? 'Sunt afisate doar locurile si zonele care au perechi configurate pentru vehiculul selectat (Loc ? Zona).'
                : 'Nu exista perechi de ruta configurate pentru vehiculul selectat.';
            if (distributionLocationNote) {
                distributionLocationNote.textContent = scopedText;
            }
            if (distributionZoneNote) {
                distributionZoneNote.textContent = scopedText;
            }
            syncFieldHoverHints(String(tipField ? (tipField.value || '') : ''));
        }

        function getScopedDistributionOptions(routeScope, locationOptions, zoneOptions, selectedLocationValue, selectedZoneValue) {
            var safeLocationOptions = Array.isArray(locationOptions) ? locationOptions : [];
            var safeZoneOptions = Array.isArray(zoneOptions) ? zoneOptions : [];
            var locationOptionById = {};
            var zoneOptionById = {};
            safeLocationOptions.forEach(function (option) {
                if (!option || typeof option !== 'object') {
                    return;
                }
                var optionId = String(option.value || '');
                if (optionId !== '') {
                    locationOptionById[optionId] = option;
                }
            });
            safeZoneOptions.forEach(function (option) {
                if (!option || typeof option !== 'object') {
                    return;
                }
                var optionId = String(option.value || '');
                if (optionId !== '') {
                    zoneOptionById[optionId] = option;
                }
            });

            var scopedRules = routeScope && Array.isArray(routeScope.scopedRules) ? routeScope.scopedRules : [];
            if (scopedRules.length === 0) {
                return {
                    locationOptions: safeLocationOptions,
                    zoneOptions: safeZoneOptions
                };
            }

            var locationAllowSet = {};
            var zoneAllowSet = {};
            var selectedLocationKey = String(selectedLocationValue || '');
            var selectedZoneKey = String(selectedZoneValue || '');
            scopedRules.forEach(function (rule) {
                if (!rule || !rule.active) {
                    return;
                }
                var locationId = String(rule.locationId || '');
                var zoneId = String(rule.zoneId || '');
                if (locationId === '' || zoneId === '') {
                    return;
                }

                if (selectedZoneKey !== '' && zoneId !== selectedZoneKey) {
                    return;
                }
                if (selectedLocationKey !== '' && locationId !== selectedLocationKey) {
                    return;
                }

                locationAllowSet[locationId] = true;
                zoneAllowSet[zoneId] = true;
            });

            if (Object.keys(locationAllowSet).length === 0 || Object.keys(zoneAllowSet).length === 0) {
                // Fallback la setul general pe perechi pentru vehicul cand selectia curenta nu mai este compatibila.
                scopedRules.forEach(function (rule) {
                    if (!rule || !rule.active) {
                        return;
                    }
                    var locationId = String(rule.locationId || '');
                    var zoneId = String(rule.zoneId || '');
                    if (locationId === '' || zoneId === '') {
                        return;
                    }
                    locationAllowSet[locationId] = true;
                    zoneAllowSet[zoneId] = true;
                });
            }

            var scopedLocationOptions = [];
            Object.keys(locationAllowSet).forEach(function (locationId) {
                if (Object.prototype.hasOwnProperty.call(locationOptionById, locationId)) {
                    scopedLocationOptions.push(locationOptionById[locationId]);
                }
            });

            var scopedZoneOptions = [];
            Object.keys(zoneAllowSet).forEach(function (zoneId) {
                if (Object.prototype.hasOwnProperty.call(zoneOptionById, zoneId)) {
                    scopedZoneOptions.push(zoneOptionById[zoneId]);
                }
            });

            scopedLocationOptions.sort(function (a, b) {
                return String(a.label || '').localeCompare(String(b.label || ''), 'ro');
            });
            scopedZoneOptions.sort(function (a, b) {
                return String(a.label || '').localeCompare(String(b.label || ''), 'ro');
            });

            return {
                locationOptions: scopedLocationOptions,
                zoneOptions: scopedZoneOptions
            };
        }

        function getScopedPrimaryOptions(primaryScope, selectedLocationValue, selectedZoneValue) {
            var safePrimaryScope = primaryScope && typeof primaryScope === 'object'
                ? primaryScope
                : { hasActiveRules: false, pairMap: {}, locationOptions: [], zoneOptions: [] };
            if (!safePrimaryScope.hasActiveRules) {
                return {
                    locationOptions: [],
                    zoneOptions: [],
                    hasSelectedPair: false
                };
            }

            var pairMap = safePrimaryScope.pairMap && typeof safePrimaryScope.pairMap === 'object'
                ? safePrimaryScope.pairMap
                : {};
            var selectedLocationKey = String(selectedLocationValue || '').trim();
            var selectedZoneKey = String(selectedZoneValue || '').trim();
            var locationAllowSet = {};
            var zoneAllowSet = {};
            var hasSelectedPair = false;

            Object.keys(pairMap).forEach(function (pairKey) {
                var pairRule = pairMap[pairKey];
                if (!pairRule || typeof pairRule !== 'object') {
                    return;
                }

                var locationId = String(pairRule.locationId || '').trim();
                var zoneId = String(pairRule.zoneId || '').trim();
                if (locationId === '' || zoneId === '') {
                    return;
                }

                if (selectedLocationKey !== '' && locationId !== selectedLocationKey) {
                    return;
                }
                if (selectedZoneKey !== '' && zoneId !== selectedZoneKey) {
                    return;
                }

                if (selectedLocationKey !== '' && selectedZoneKey !== '' && locationId === selectedLocationKey && zoneId === selectedZoneKey) {
                    hasSelectedPair = true;
                }

                locationAllowSet[locationId] = true;
                zoneAllowSet[zoneId] = true;
            });

            if (Object.keys(locationAllowSet).length === 0 || Object.keys(zoneAllowSet).length === 0) {
                Object.keys(pairMap).forEach(function (pairKey) {
                    var pairRule = pairMap[pairKey];
                    if (!pairRule || typeof pairRule !== 'object') {
                        return;
                    }
                    var locationId = String(pairRule.locationId || '').trim();
                    var zoneId = String(pairRule.zoneId || '').trim();
                    if (locationId === '' || zoneId === '') {
                        return;
                    }
                    locationAllowSet[locationId] = true;
                    zoneAllowSet[zoneId] = true;
                });
            }

            var scopedLocationOptions = (safePrimaryScope.locationOptions || []).filter(function (option) {
                if (!option || typeof option !== 'object') {
                    return false;
                }
                var optionId = String(option.value || '').trim();
                return optionId !== '' && Object.prototype.hasOwnProperty.call(locationAllowSet, optionId);
            });
            var scopedZoneOptions = (safePrimaryScope.zoneOptions || []).filter(function (option) {
                if (!option || typeof option !== 'object') {
                    return false;
                }
                var optionId = String(option.value || '').trim();
                return optionId !== '' && Object.prototype.hasOwnProperty.call(zoneAllowSet, optionId);
            });

            return {
                locationOptions: scopedLocationOptions,
                zoneOptions: scopedZoneOptions,
                hasSelectedPair: hasSelectedPair
            };
        }

        function selectPrimaryRouteVariantForVehicle(mapEntry) {
            if (!mapEntry || typeof mapEntry !== 'object') {
                return mapEntry;
            }

            var variants = Array.isArray(mapEntry.variants) ? mapEntry.variants : [];
            if (variants.length <= 1) {
                return mapEntry;
            }

            // Perechea are mai multe reguli (vehicule diferite => km diferiti):
            // alegem varianta care contine vehiculul selectat, apoi varianta fara
            // restrictie de vehicule; daca niciuna nu acopera vehiculul, nu exista
            // regula aplicabila.
            var vehicleValue = parseInt(String(vehicleField ? (vehicleField.value || '') : '').trim(), 10);
            if (!Number.isFinite(vehicleValue) || vehicleValue <= 0) {
                return mapEntry;
            }

            for (var variantIndex = 0; variantIndex < variants.length; variantIndex += 1) {
                var variantVehicleIds = Array.isArray(variants[variantIndex].vehicle_ids) ? variants[variantIndex].vehicle_ids : [];
                var containsVehicle = variantVehicleIds.some(function (variantVehicleId) {
                    return parseInt(String(variantVehicleId), 10) === vehicleValue;
                });
                if (containsVehicle) {
                    return variants[variantIndex];
                }
            }

            for (var fallbackIndex = 0; fallbackIndex < variants.length; fallbackIndex += 1) {
                var fallbackVehicleIds = Array.isArray(variants[fallbackIndex].vehicle_ids) ? variants[fallbackIndex].vehicle_ids : [];
                if (fallbackVehicleIds.length === 0) {
                    return variants[fallbackIndex];
                }
            }

            return null;
        }

        function normalizePrimaryRouteRule(rawRule, matchDirection) {
            if (!rawRule || typeof rawRule !== 'object') {
                return null;
            }

            var routeId = parseInt(
                Object.prototype.hasOwnProperty.call(rawRule, 'ruleId') ? rawRule.ruleId : rawRule.id,
                10
            );
            if (!Number.isFinite(routeId)) {
                routeId = 0;
            }

            var kmTariff = 0;
            if (Object.prototype.hasOwnProperty.call(rawRule, 'kmTariff')) {
                kmTariff = parseNumber(rawRule.kmTariff);
            } else if (Object.prototype.hasOwnProperty.call(rawRule, 'km_tarifare')) {
                kmTariff = parseNumber(rawRule.km_tarifare);
            }

            var isActive = Object.prototype.hasOwnProperty.call(rawRule, 'active')
                ? !!rawRule.active
                : !!rawRule.activ;
            var usesManualAgreedKm = Object.prototype.hasOwnProperty.call(rawRule, 'manualAgreedKm')
                ? !!rawRule.manualAgreedKm
                : !!rawRule.km_agreati_manual;
            var rideCost = Object.prototype.hasOwnProperty.call(rawRule, 'rideCost')
                ? parseNumber(rawRule.rideCost)
                : parseNumber(rawRule.cost_cursa);
            var applyRideCost = Object.prototype.hasOwnProperty.call(rawRule, 'applyRideCost')
                ? !!rawRule.applyRideCost
                : !!rawRule.aplica_cost_cursa;

            return {
                ruleId: routeId,
                kmTariff: Math.max(0, Math.round(kmTariff)),
                manualAgreedKm: usesManualAgreedKm,
                rideCost: Math.max(0, rideCost),
                applyRideCost: applyRideCost,
                active: isActive,
                matchDirection: String(matchDirection || rawRule.matchDirection || 'direct')
            };
        }

        function getPrimaryRouteRuleFromMapKey(beneficiaryKey, locationKey, zoneKey, matchDirection) {
            var pairKey = beneficiaryKey + '|' + locationKey + '|' + zoneKey;
            if (!Object.prototype.hasOwnProperty.call(primaryRouteKmMap, pairKey)) {
                return null;
            }

            return normalizePrimaryRouteRule(selectPrimaryRouteVariantForVehicle(primaryRouteKmMap[pairKey]), matchDirection);
        }

        function getPrimaryRouteRuleFromNamePair(beneficiaryKey, expectedLocationName, expectedZoneName, matchDirection) {
            if (
                beneficiaryKey === ''
                || !Object.prototype.hasOwnProperty.call(primaryRouteRulesByBeneficiary, beneficiaryKey)
            ) {
                return null;
            }

            var beneficiaryRules = Array.isArray(primaryRouteRulesByBeneficiary[beneficiaryKey])
                ? primaryRouteRulesByBeneficiary[beneficiaryKey]
                : [];
            var bestRule = null;
            var bestRuleId = -1;

            for (var index = 0; index < beneficiaryRules.length; index += 1) {
                var primaryRule = beneficiaryRules[index];
                if (!primaryRule || typeof primaryRule !== 'object' || !primaryRule.active) {
                    continue;
                }

                var locationName = normalizeMatchText(primaryRule.locationName || '');
                var zoneName = normalizeMatchText(primaryRule.zoneName || '');
                if (locationName === '' || zoneName === '') {
                    continue;
                }
                if (locationName !== expectedLocationName || zoneName !== expectedZoneName) {
                    continue;
                }

                var candidateRule = normalizePrimaryRouteRule(primaryRule, matchDirection);
                if (!candidateRule || !candidateRule.active) {
                    continue;
                }

                var candidateRuleId = parseInt(candidateRule.ruleId, 10);
                if (!Number.isFinite(candidateRuleId)) {
                    candidateRuleId = 0;
                }
                if (candidateRuleId >= bestRuleId) {
                    bestRuleId = candidateRuleId;
                    bestRule = candidateRule;
                }
            }

            return bestRule;
        }

        function getPrimaryRouteRule(primaryScope, beneficiaryId, locationId, zoneId) {
            var safePrimaryScope = primaryScope && typeof primaryScope === 'object'
                ? primaryScope
                : null;

            var beneficiaryKey = String(beneficiaryId || '').trim();
            var locationKey = String(locationId || '').trim();
            var zoneKey = String(zoneId || '').trim();
            if (beneficiaryKey === '' || locationKey === '' || zoneKey === '') {
                return null;
            }

            var directRule = getPrimaryRouteRuleFromMapKey(beneficiaryKey, locationKey, zoneKey, 'direct');
            if (directRule && directRule.active) {
                return directRule;
            }

            if (locationKey !== zoneKey) {
                var reverseRule = getPrimaryRouteRuleFromMapKey(beneficiaryKey, zoneKey, locationKey, 'reverse');
                if (reverseRule && reverseRule.active) {
                    return reverseRule;
                }
            }

            var selectedLocationName = normalizeMatchText(getSelectedDistributionPointName(loadLocationField, loadLocationNamesById));
            var selectedZoneName = normalizeMatchText(getSelectedDistributionPointName(zoneField, zoneNamesById));
            if (selectedLocationName !== '' && selectedZoneName !== '') {
                var directNameRule = getPrimaryRouteRuleFromNamePair(
                    beneficiaryKey,
                    selectedLocationName,
                    selectedZoneName,
                    'direct_name'
                );
                if (directNameRule && directNameRule.active) {
                    return directNameRule;
                }

                if (selectedLocationName !== selectedZoneName) {
                    var reverseNameRule = getPrimaryRouteRuleFromNamePair(
                        beneficiaryKey,
                        selectedZoneName,
                        selectedLocationName,
                        'reverse_name'
                    );
                    if (reverseNameRule && reverseNameRule.active) {
                        return reverseNameRule;
                    }
                }
            }

            if (!safePrimaryScope || !safePrimaryScope.hasActiveRules) {
                return null;
            }

            var pairMap = safePrimaryScope.pairMap && typeof safePrimaryScope.pairMap === 'object'
                ? safePrimaryScope.pairMap
                : {};
            var pairKey = locationKey + '|' + zoneKey;
            if (!Object.prototype.hasOwnProperty.call(pairMap, pairKey)) {
                return null;
            }

            var routeRule = normalizePrimaryRouteRule(pairMap[pairKey], 'scoped');
            if (!routeRule || !routeRule.active) {
                return null;
            }

            return routeRule;
        }

        function updatePrimaryScopeNotes(primaryScope, hasSelectedPair) {
            if (primaryLocationNote) {
                primaryLocationNote.textContent = defaultPrimaryLocationNoteText;
            }
            if (primaryZoneNote) {
                primaryZoneNote.textContent = defaultPrimaryZoneNoteText;
            }

            if (!primaryScope || !primaryScope.hasActiveRules) {
                var noPrimaryRoutesText = 'Nu exista perechi Primar configurate pentru beneficiarul selectat.';
                if (primaryLocationNote) {
                    primaryLocationNote.textContent = noPrimaryRoutesText;
                }
                if (primaryZoneNote) {
                    primaryZoneNote.textContent = noPrimaryRoutesText;
                }
                syncFieldHoverHints(String(tipField ? (tipField.value || '') : ''));
                return;
            }

            if (hasSelectedPair) {
                var selectedRule = getPrimaryRouteRule(
                    primaryScope,
                    beneficiaryField ? beneficiaryField.value : '',
                    loadLocationField ? loadLocationField.value : '',
                    zoneField ? zoneField.value : ''
                );
                var selectedPairText = selectedRule && selectedRule.manualAgreedKm
                    ? 'Combinatia selectata Loc ? Zona este valida. Completeaza manual Km agreati pentru aceasta cursa.'
                    : 'Combinatia selectata Loc ? Zona este valida in Setari Primar, iar Km agreati se completeaza automat.';
                if (primaryLocationNote) {
                    primaryLocationNote.textContent = selectedPairText;
                }
                if (primaryZoneNote) {
                    primaryZoneNote.textContent = selectedPairText;
                }
                syncFieldHoverHints(String(tipField ? (tipField.value || '') : ''));
                return;
            }

            var choosePairText = 'Selecteaza o combinatie Loc ? Zona disponibila in Setari Primar pentru acest beneficiar.';
            if (primaryLocationNote) {
                primaryLocationNote.textContent = choosePairText;
            }
            if (primaryZoneNote) {
                primaryZoneNote.textContent = choosePairText;
            }
            syncFieldHoverHints(String(tipField ? (tipField.value || '') : ''));
        }

        function applyPrimaryRouteKmTariff(primaryScope) {
            if (!kmField) {
                return;
            }

            var transportType = String(tipField.value || '');
            if (!isPrimaryTransport(transportType)) {
                if (kmField.hasAttribute('data-primary-km-autofilled')) {
                    kmField.removeAttribute('data-primary-km-autofilled');
                }
                kmField.removeAttribute('data-primary-km-manual');
                kmField.removeAttribute('data-primary-route-id');
                return;
            }

            var beneficiaryId = String(beneficiaryField ? (beneficiaryField.value || '') : '').trim();
            var locationId = String(loadLocationField ? (loadLocationField.value || '') : '').trim();
            var zoneId = String(zoneField ? (zoneField.value || '') : '').trim();
            var primaryRule = getPrimaryRouteRule(primaryScope, beneficiaryId, locationId, zoneId);
            if (!primaryRule) {
                if (kmField.hasAttribute('data-primary-km-autofilled') || kmField.hasAttribute('data-primary-km-manual')) {
                    kmField.value = '';
                    kmField.removeAttribute('data-primary-km-autofilled');
                    kmField.removeAttribute('data-primary-km-manual');
                    kmField.removeAttribute('data-primary-route-id');
                }
                kmField.readOnly = true;
                if (!isPrimaryKmTransport(transportType)) {
                    kmField.removeAttribute('required');
                }
                return;
            }

            var routeId = String(primaryRule.ruleId || '');
            var previousRouteId = String(kmField.getAttribute('data-primary-route-id') || '');
            if (primaryRule.manualAgreedKm) {
                if (kmField.hasAttribute('data-primary-km-autofilled') || (previousRouteId !== '' && previousRouteId !== routeId)) {
                    kmField.value = '';
                }
                kmField.removeAttribute('data-primary-km-autofilled');
                kmField.setAttribute('data-primary-km-manual', '1');
                kmField.setAttribute('data-primary-route-id', routeId);
                kmField.readOnly = false;
                kmField.setAttribute('required', 'required');
                return;
            }

            var kmTariffValue = Math.max(0, Math.round(parseNumber(primaryRule.kmTariff)));
            kmField.value = String(kmTariffValue);
            kmField.setAttribute('data-primary-km-autofilled', '1');
            kmField.removeAttribute('data-primary-km-manual');
            kmField.setAttribute('data-primary-route-id', routeId);
            kmField.readOnly = true;
            if (!isPrimaryKmTransport(transportType)) {
                kmField.removeAttribute('required');
            }
        }

        function applyDistributionRouteKmTariff() {
            if (!kmField) {
                return;
            }

            var transportType = String(tipField.value || '');
            if (!isDistributionWithKmTransport(transportType)) {
                if (kmField.hasAttribute('data-distribution-km-autofilled')) {
                    kmField.removeAttribute('data-distribution-km-autofilled');
                }
                return;
            }

            var beneficiaryId = String(beneficiaryField ? (beneficiaryField.value || '') : '').trim();
            var locationId = String(loadLocationField ? (loadLocationField.value || '') : '').trim();
            var zoneId = String(zoneField ? (zoneField.value || '') : '').trim();
            var vehicleId = String(vehicleField ? (vehicleField.value || '') : '').trim();
            var routeRule = getDistributionRouteRule(beneficiaryId, locationId, zoneId, vehicleId, transportType);
            var kmTariffValue = routeRule && routeRule.active
                ? Math.max(0, Math.round(parseNumber(routeRule.kmTariff)))
                : 0;

            if (kmTariffValue <= 0) {
                if (kmField.hasAttribute('data-distribution-km-autofilled')) {
                    kmField.value = '';
                    kmField.removeAttribute('data-distribution-km-autofilled');
                }
                return;
            }

            kmField.value = String(kmTariffValue);
            kmField.setAttribute('data-distribution-km-autofilled', '1');
        }

        function syncScopedLocationZoneOptions() {
            if (!(loadLocationField instanceof HTMLSelectElement) || !(zoneField instanceof HTMLSelectElement)) {
                return;
            }

            var transportType = String(tipField.value || '');
            var beneficiaryId = String(beneficiaryField.value || '');
            var vehicleId = String(vehicleField ? (vehicleField.value || '') : '');
            var selectedLocationValue = String(loadLocationField.value || '');
            var selectedZoneValue = String(zoneField.value || '');

            if (isDistributionTransport(transportType)) {
                var scopedLocationOptionsBase = beneficiaryId !== ''
                    ? getDistributionLocationOptionsForBeneficiary(beneficiaryId)
                    : [];
                var scopedZoneOptionsBase = beneficiaryId !== ''
                    ? getDistributionZoneOptionsForBeneficiary(beneficiaryId)
                    : [];
                var routeScope = getVehicleScopedRouteRules(beneficiaryId, vehicleId, transportType);
                var scopedOptions = getScopedDistributionOptions(
                    routeScope,
                    scopedLocationOptionsBase,
                    scopedZoneOptionsBase,
                    selectedLocationValue,
                    selectedZoneValue
                );
                rebuildSelectOptions(loadLocationField, scopedOptions.locationOptions, selectedLocationValue, '-- Selecteaza --');
                rebuildSelectOptions(zoneField, scopedOptions.zoneOptions, selectedZoneValue, '-- Selecteaza --');
                updateDistributionScopeNotes(routeScope, vehicleId);
                updatePrimaryScopeNotes({ hasActiveRules: false }, false);
                applyDistributionRouteKmTariff();
                return;
            }

            if (isPrimaryTransport(transportType)) {
                var primaryScope = beneficiaryId !== '' && Object.prototype.hasOwnProperty.call(primaryRouteScopeByBeneficiary, beneficiaryId)
                    ? primaryRouteScopeByBeneficiary[beneficiaryId]
                    : { hasActiveRules: false, pairMap: {}, locationOptions: [], zoneOptions: [] };
                var primaryScopedOptions = getScopedPrimaryOptions(primaryScope, selectedLocationValue, selectedZoneValue);
                rebuildSelectOptions(loadLocationField, primaryScopedOptions.locationOptions, selectedLocationValue, '-- Selecteaza --');
                rebuildSelectOptions(zoneField, primaryScopedOptions.zoneOptions, selectedZoneValue, '-- Selecteaza --');
                var selectedPrimaryLocation = String(loadLocationField.value || '');
                var selectedPrimaryZone = String(zoneField.value || '');
                var selectedPrimaryRule = getPrimaryRouteRule(
                    primaryScope,
                    beneficiaryId,
                    selectedPrimaryLocation,
                    selectedPrimaryZone
                );
                updateDistributionScopeNotes({ hasActiveRules: false, scopedRules: [] }, vehicleId);
                updatePrimaryScopeNotes(primaryScope, selectedPrimaryRule !== null);
                applyPrimaryRouteKmTariff(primaryScope);
                return;
            }

            if (!isDistributionTransport(transportType) && !isPrimaryTransport(transportType)) {
                rebuildSelectOptions(loadLocationField, initialLoadLocationOptions, selectedLocationValue, '-- Selecteaza --');
                rebuildSelectOptions(zoneField, initialZoneOptions, selectedZoneValue, '-- Selecteaza --');
                if (distributionLocationNote) {
                    distributionLocationNote.textContent = defaultDistributionLocationNoteText;
                }
                if (distributionZoneNote) {
                    distributionZoneNote.textContent = defaultDistributionZoneNoteText;
                }
                if (primaryLocationNote) {
                    primaryLocationNote.textContent = defaultPrimaryLocationNoteText;
                }
                if (primaryZoneNote) {
                    primaryZoneNote.textContent = defaultPrimaryZoneNoteText;
                }
                syncFieldHoverHints(transportType);
            }
        }

        function getBeneficiaryRates(transportType) {
            var selectedBeneficiaryId = String(beneficiaryField.value || '');
            if (!selectedBeneficiaryId || !Object.prototype.hasOwnProperty.call(beneficiaryPricing, selectedBeneficiaryId)) {
                return {
                    perKm: 0,
                    perTon: 0,
                    perHourSuction: 0,
                    perKmRelocation: 0,
                    perDeliveredTon: 0,
                    perSuctionLiquidTon: 0,
                    perSuctionGasTon: 0
                };
            }

            var config = beneficiaryPricing[selectedBeneficiaryId] || {};
            var baseRate = parseNumber(config.pret_tarifare);
            var perKm = parseNumber(config.pret_km);
            var perTon = parseNumber(config.pret_tona);
            var perDistributionKm = parseNumber(config.pret_distributie_km);
            var perDistributionTon = parseNumber(config.pret_distributie_tona);
            var perHourSuction = parseNumber(config.pret_ora_aspirare);
            var perKmRelocation = parseNumber(config.pret_km_dislocare);
            var perDeliveredTon = parseNumber(config.pret_tona_livrata);
            var perSuctionLiquidTon = parseNumber(config.pret_tona_aspirata_lichida);
            var perSuctionGasTon = parseNumber(config.pret_tona_aspirata_gazoasa);
            var supportsPrimary = !!config.suporta_primar;
            var supportsDistribution = !!config.suporta_distributie;
            var supportsPrimaryDistribution = !!config.suporta_primar_distributie;
            var supportsCompressor = !!config.suporta_compresor;

            if (isPrimaryKmTransport(transportType) || isPrimaryTonTransport(transportType)) {
                if (!supportsPrimary) {
                    return {
                        perKm: 0,
                        perTon: 0,
                        perHourSuction: 0,
                        perKmRelocation: 0,
                        perDeliveredTon: 0,
                        perSuctionLiquidTon: 0,
                        perSuctionGasTon: 0
                    };
                }

                return {
                    perKm: perKm > 0 ? perKm : baseRate,
                    perTon: perTon > 0 ? perTon : baseRate,
                    perHourSuction: 0,
                    perKmRelocation: 0,
                    perDeliveredTon: 0,
                    perSuctionLiquidTon: 0,
                    perSuctionGasTon: 0
                };
            }

            if (isDistributionTransport(transportType)) {
                if (
                    (transportType === 'distributie' && !supportsDistribution)
                    || (transportType !== 'distributie' && !supportsPrimaryDistribution)
                ) {
                    return {
                        perKm: 0,
                        perTon: 0,
                        perHourSuction: 0,
                        perKmRelocation: 0,
                        perDeliveredTon: 0,
                        perSuctionLiquidTon: 0,
                        perSuctionGasTon: 0
                    };
                }

                return {
                    perKm: perDistributionKm > 0 ? perDistributionKm : 0,
                    perTon: perDistributionTon > 0 ? perDistributionTon : (perTon > 0 ? perTon : baseRate),
                    perHourSuction: 0,
                    perKmRelocation: 0,
                    perDeliveredTon: 0,
                    perSuctionLiquidTon: 0,
                    perSuctionGasTon: 0
                };
            }

            if (transportType === 'compresor') {
                if (!supportsCompressor) {
                    return {
                        perKm: 0,
                        perTon: 0,
                        perHourSuction: 0,
                        perKmRelocation: 0,
                        perDeliveredTon: 0,
                        perSuctionLiquidTon: 0,
                        perSuctionGasTon: 0
                    };
                }

                return {
                    perKm: 0,
                    perTon: 0,
                    perHourSuction: perHourSuction,
                    perKmRelocation: perKmRelocation,
                    perDeliveredTon: perDeliveredTon,
                    perSuctionLiquidTon: perSuctionLiquidTon,
                    perSuctionGasTon: perSuctionGasTon
                };
            }

            return {
                perKm: 0,
                perTon: 0,
                perHourSuction: 0,
                perKmRelocation: 0,
                perDeliveredTon: 0,
                perSuctionLiquidTon: 0,
                perSuctionGasTon: 0
            };
        }

        function initGoodsTypeDropdown() {
            if (!goodsTypeDropdown) {
                return;
            }

            var labelEl = goodsTypeDropdown.querySelector('.goods-multiselect-label');
            var checkboxEls = goodsTypeDropdown.querySelectorAll('input[type="checkbox"][name="tip_marfa[]"]');
            var defaultLabel = labelEl ? (labelEl.getAttribute('data-default-label') || '-- Selecteaza --') : '-- Selecteaza --';

            var refreshGoodsTypeLabel = function () {
                if (!labelEl) {
                    return;
                }

                var selectedLabels = [];
                checkboxEls.forEach(function (checkboxEl) {
                    if (!(checkboxEl instanceof HTMLInputElement) || !checkboxEl.checked) {
                        return;
                    }
                    var text = checkboxEl.closest('label')?.querySelector('span')?.textContent?.trim();
                    if (text) {
                        selectedLabels.push(text);
                    }
                });

                if (selectedLabels.length === 0) {
                    labelEl.textContent = defaultLabel;
                    labelEl.removeAttribute('title');
                    return;
                }

                var joined = selectedLabels.join(', ');
                labelEl.textContent = joined;
                labelEl.setAttribute('title', joined);
            };

            checkboxEls.forEach(function (checkboxEl) {
                checkboxEl.addEventListener('change', refreshGoodsTypeLabel);
            });

            refreshGoodsTypeLabel();
        }

        function getLoadLocationTariff(locationId) {
            var key = String(locationId || '');
            if (key === '' || !Object.prototype.hasOwnProperty.call(loadLocationTariffs, key)) {
                return 0;
            }

            return parseNumber(loadLocationTariffs[key]);
        }

        function getZoneTariff(zoneId) {
            var key = String(zoneId || '');
            if (key === '' || !Object.prototype.hasOwnProperty.call(zoneTariffs, key)) {
                return 0;
            }

            return parseNumber(zoneTariffs[key]);
        }

        function getZoneExtraKmCost(zoneId) {
            var key = String(zoneId || '');
            if (key === '' || !Object.prototype.hasOwnProperty.call(zoneExtraKmCosts, key)) {
                return 0;
            }

            return parseNumber(zoneExtraKmCosts[key]);
        }

        function getDistributionRouteRule(beneficiaryId, locationId, zoneId, vehicleId, transportType) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            var locationKey = String(locationId || '');
            var zoneKey = String(zoneId || '');
            var targetRouteScope = resolveDistributionRouteScopeForTransport(transportType);
            if (locationKey === '' || zoneKey === '') {
                return null;
            }
            if (targetRouteScope === null) {
                return null;
            }

            var selectedVehicleId = String(vehicleId || '').trim();
            var resolveRuleForKey = function (pairKey, matchDirection) {
                if (!Object.prototype.hasOwnProperty.call(distributionRouteTariffs, pairKey)) {
                    return null;
                }

                var rawRules = distributionRouteTariffs[pairKey];
                var candidateRules = Array.isArray(rawRules) ? rawRules : [rawRules];
                var bestRule = null;
                var bestScore = -1;
                var bestRuleId = -1;

                for (var index = 0; index < candidateRules.length; index += 1) {
                    var rule = candidateRules[index];
                    if (!rule || typeof rule !== 'object') {
                        continue;
                    }

                    var ruleBeneficiaryId = String(rule.beneficiar_id || '').trim();
                    if (beneficiaryKey !== '' && ruleBeneficiaryId !== '' && ruleBeneficiaryId !== beneficiaryKey) {
                        continue;
                    }
                    if (normalizeDistributionRouteScope(rule.transport_scope) !== targetRouteScope) {
                        continue;
                    }

                    var scopedVehicleIds = Array.isArray(rule.vehicle_ids) ? rule.vehicle_ids : [];
                    var scopedVehicleIdSet = {};
                    scopedVehicleIds.forEach(function (scopedVehicleId) {
                        var normalizedVehicleId = String(scopedVehicleId || '').trim();
                        if (normalizedVehicleId !== '') {
                            scopedVehicleIdSet[normalizedVehicleId] = true;
                        }
                    });
                    var hasVehicleScope = scopedVehicleIds.length > 0;
                    if (hasVehicleScope && (selectedVehicleId === '' || !Object.prototype.hasOwnProperty.call(scopedVehicleIdSet, selectedVehicleId))) {
                        continue;
                    }

                    var ruleId = parseInt(rule.id, 10);
                    if (!Number.isFinite(ruleId)) {
                        ruleId = 0;
                    }
                    var score = hasVehicleScope ? 2 : 1;
                    if (score > bestScore || (score === bestScore && ruleId > bestRuleId)) {
                        bestScore = score;
                        bestRuleId = ruleId;
                        bestRule = {
                            ruleId: ruleId,
                            tariffMode: normalizeDistributionRouteTariffMode(rule.tarif_mod),
                            tariffPerTon: parseNumber(rule.tarif_tona),
                            extraKmCost: parseNumber(rule.cost_extra_km),
                            kmTariff: Math.max(0, Math.round(parseNumber(rule.km_tarifare))),
                            rideCost: parseNumber(rule.cost_cursa),
                            applyRideCost: !!rule.aplica_cost_cursa,
                            vehicleIds: scopedVehicleIds,
                            active: !!rule.activ,
                            matchDirection: matchDirection
                        };
                    }
                }

                return bestRule;
            };

            if (beneficiaryKey !== '') {
                var directBeneficiaryKey = beneficiaryKey + '|' + locationKey + '|' + zoneKey;
                var directBeneficiaryRule = resolveRuleForKey(directBeneficiaryKey, 'direct');
                if (directBeneficiaryRule) {
                    return directBeneficiaryRule;
                }

                if (locationKey !== zoneKey) {
                    var reverseBeneficiaryKey = beneficiaryKey + '|' + zoneKey + '|' + locationKey;
                    var reverseBeneficiaryRule = resolveRuleForKey(reverseBeneficiaryKey, 'reverse');
                    if (reverseBeneficiaryRule) {
                        return reverseBeneficiaryRule;
                    }
                }
            }

            var directGlobalKey = locationKey + '|' + zoneKey;
            var directGlobalRule = resolveRuleForKey(directGlobalKey, 'global_direct');
            if (directGlobalRule) {
                return directGlobalRule;
            }

            if (locationKey !== zoneKey) {
                var reverseGlobalKey = zoneKey + '|' + locationKey;
                var reverseGlobalRule = resolveRuleForKey(reverseGlobalKey, 'global_reverse');
                if (reverseGlobalRule) {
                    return reverseGlobalRule;
                }
            }

            var selectedLocationName = normalizeMatchText(getSelectedDistributionPointName(loadLocationField, loadLocationNamesById));
            var selectedZoneName = normalizeMatchText(getSelectedDistributionPointName(zoneField, zoneNamesById));
            if (selectedLocationName === '' || selectedZoneName === '') {
                return null;
            }

            var beneficiaryScopedRules = getDistributionRulesForBeneficiary(beneficiaryKey, transportType);

            var resolveRuleFromNames = function (expectedLocationName, expectedZoneName, matchDirection) {
                var bestRule = null;
                var bestScore = -1;
                var bestRuleId = -1;

                for (var ruleIndex = 0; ruleIndex < beneficiaryScopedRules.length; ruleIndex += 1) {
                    var scopedRule = beneficiaryScopedRules[ruleIndex];
                    if (!scopedRule || !scopedRule.active) {
                        continue;
                    }

                    var scopedLocationName = normalizeMatchText(scopedRule.locationName || '');
                    var scopedZoneName = normalizeMatchText(scopedRule.zoneName || '');
                    if (scopedLocationName === '' || scopedZoneName === '') {
                        continue;
                    }
                    if (scopedLocationName !== expectedLocationName || scopedZoneName !== expectedZoneName) {
                        continue;
                    }

                    var scopedVehicleIds = Array.isArray(scopedRule.vehicleIds) ? scopedRule.vehicleIds : [];
                    var scopedVehicleIdSet = scopedRule.vehicleIdSet && typeof scopedRule.vehicleIdSet === 'object'
                        ? scopedRule.vehicleIdSet
                        : {};
                    var hasVehicleScope = scopedVehicleIds.length > 0;
                    if (hasVehicleScope && (selectedVehicleId === '' || !Object.prototype.hasOwnProperty.call(scopedVehicleIdSet, selectedVehicleId))) {
                        continue;
                    }

                    var scopedRuleId = parseInt(scopedRule.ruleId, 10);
                    if (!Number.isFinite(scopedRuleId)) {
                        scopedRuleId = 0;
                    }
                    var score = hasVehicleScope ? 2 : 1;
                    if (score > bestScore || (score === bestScore && scopedRuleId > bestRuleId)) {
                        bestScore = score;
                        bestRuleId = scopedRuleId;
                        bestRule = {
                            ruleId: scopedRuleId,
                            tariffMode: normalizeDistributionRouteTariffMode(scopedRule.tariffMode),
                            tariffPerTon: parseNumber(scopedRule.tariffPerTon),
                            extraKmCost: parseNumber(scopedRule.extraKmCost),
                            kmTariff: Math.max(0, Math.round(parseNumber(scopedRule.kmTariff))),
                            rideCost: parseNumber(scopedRule.rideCost),
                            applyRideCost: !!scopedRule.applyRideCost,
                            vehicleIds: scopedVehicleIds,
                            active: true,
                            matchDirection: matchDirection
                        };
                    }
                }

                return bestRule;
            };

            var directNameRule = resolveRuleFromNames(selectedLocationName, selectedZoneName, 'direct_name');
            if (directNameRule) {
                return directNameRule;
            }

            if (selectedLocationName !== selectedZoneName) {
                var reverseNameRule = resolveRuleFromNames(selectedZoneName, selectedLocationName, 'reverse_name');
                if (reverseNameRule) {
                    return reverseNameRule;
                }
            }

            return null;
        }

        function getDistributionRouteTemplateRule(routeScope, sameRoute) {
            if (!routeScope || !routeScope.hasActiveRules) {
                return null;
            }

            var scopedRules = Array.isArray(routeScope.scopedRules) ? routeScope.scopedRules : [];
            for (var index = 0; index < scopedRules.length; index += 1) {
                var scopedRule = scopedRules[index];
                if (!scopedRule || !scopedRule.active) {
                    continue;
                }

                var locationName = normalizeMatchText(scopedRule.locationName || '');
                var zoneName = normalizeMatchText(scopedRule.zoneName || '');
                if (locationName === '' || zoneName === '') {
                    continue;
                }

                var isSameRouteRule = locationName === zoneName;
                if (isSameRouteRule !== sameRoute) {
                    continue;
                }

                return {
                    tariffMode: normalizeDistributionRouteTariffMode(scopedRule.tariffMode),
                    tariffPerTon: parseNumber(scopedRule.tariffPerTon),
                    extraKmCost: parseNumber(scopedRule.extraKmCost),
                    kmTariff: Math.max(0, Math.round(parseNumber(scopedRule.kmTariff))),
                    rideCost: parseNumber(scopedRule.rideCost),
                    applyRideCost: !!scopedRule.applyRideCost,
                    vehicleIds: Array.isArray(scopedRule.vehicleIds) ? scopedRule.vehicleIds : [],
                    active: true
                };
            }

            return null;
        }

        function getSelectedDistributionPointName(selectField, namesMap) {
            if (!(selectField instanceof HTMLSelectElement)) {
                return '';
            }

            var selectedId = String(selectField.value || '');
            if (selectedId !== '' && Object.prototype.hasOwnProperty.call(namesMap, selectedId)) {
                return String(namesMap[selectedId] || '').trim();
            }

            var selectedOption = selectField.options[selectField.selectedIndex];
            if (!selectedOption) {
                return '';
            }

            var explicitRouteName = String(selectedOption.getAttribute('data-route-name') || '').trim();
            if (explicitRouteName !== '') {
                return explicitRouteName;
            }

            return String(selectedOption.textContent || '').trim();
        }

        function isSameDistributionRoute() {
            var locationName = normalizeMatchText(getSelectedDistributionPointName(loadLocationField, loadLocationNamesById));
            var zoneName = normalizeMatchText(getSelectedDistributionPointName(zoneField, zoneNamesById));

            return locationName !== '' && zoneName !== '' && locationName === zoneName;
        }

        function resolveDistributionTonRate(locationTariff, zoneTariff, beneficiaryTonRate, sameRoute) {
            if (sameRoute) {
                if (locationTariff > 0) {
                    return locationTariff;
                }
                if (zoneTariff > 0) {
                    return zoneTariff;
                }
            } else {
                if (zoneTariff > 0) {
                    return zoneTariff;
                }
                if (locationTariff > 0) {
                    return locationTariff;
                }
            }

            return beneficiaryTonRate > 0 ? beneficiaryTonRate : 0;
        }

        function hasLoadLocationOption(locationId) {
            if (!loadLocationField) {
                return false;
            }

            var optionValue = String(locationId || '');
            if (optionValue === '') {
                return false;
            }

            for (var index = 0; index < loadLocationField.options.length; index += 1) {
                if (String(loadLocationField.options[index].value || '') === optionValue) {
                    return true;
                }
            }

            return false;
        }

        function normalizeMatchText(value) {
            var normalized = String(value || '').trim().toLocaleLowerCase();
            if (typeof normalized.normalize === 'function') {
                normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }

            return normalized.replace(/\s+/g, ' ');
        }

        function findLoadLocationIdByVehicleGarage(vehicleId) {
            if (!loadLocationField) {
                return '';
            }

            if (!Object.prototype.hasOwnProperty.call(vehicleGarages, vehicleId)) {
                return '';
            }

            var garageName = normalizeMatchText(vehicleGarages[vehicleId]);
            if (garageName === '') {
                return '';
            }

            for (var index = 0; index < loadLocationField.options.length; index += 1) {
                var option = loadLocationField.options[index];
                var optionValue = String(option.value || '');
                if (optionValue === '') {
                    continue;
                }

                if (normalizeMatchText(option.textContent || '') === garageName) {
                    return optionValue;
                }
            }

            return '';
        }

        function hasZoneOption(zoneId) {
            if (!zoneField) {
                return false;
            }

            var optionValue = String(zoneId || '');
            if (optionValue === '') {
                return false;
            }

            for (var index = 0; index < zoneField.options.length; index += 1) {
                if (String(zoneField.options[index].value || '') === optionValue) {
                    return true;
                }
            }

            return false;
        }

        function applyVehicleDefaultLoadLocation(onlyWhenEmpty) {
            if (!vehicleField || !loadLocationField) {
                return;
            }

            var transportType = String(tipField.value || '');
            if (!isDistributionTransport(transportType)) {
                return;
            }

            var vehicleId = String(vehicleField.value || '');
            var beneficiaryId = String(beneficiaryField.value || '');
            if (vehicleId === '') {
                return;
            }

            var mappedLocationId = '';
            // Prioritate noua: pentru distributie, daca exista loc incarcare cu acelasi nume ca garajul vehiculului,
            // il selectam direct in contextul beneficiarului curent.
            mappedLocationId = findLoadLocationIdByVehicleGarage(vehicleId);

            if (mappedLocationId === '' || !hasLoadLocationOption(mappedLocationId)) {
                if (
                    beneficiaryId !== ''
                    && Object.prototype.hasOwnProperty.call(vehicleDefaultLoadLocationsByBeneficiary, beneficiaryId)
                    && Object.prototype.hasOwnProperty.call(vehicleDefaultLoadLocationsByBeneficiary[beneficiaryId] || {}, vehicleId)
                ) {
                    mappedLocationId = String((vehicleDefaultLoadLocationsByBeneficiary[beneficiaryId] || {})[vehicleId] || '');
                } else if (Object.prototype.hasOwnProperty.call(vehicleDefaultLoadLocations, vehicleId)) {
                    mappedLocationId = String(vehicleDefaultLoadLocations[vehicleId] || '');
                }
            }

            if (mappedLocationId === '' || !hasLoadLocationOption(mappedLocationId)) {
                return;
            }

            if (onlyWhenEmpty && String(loadLocationField.value || '') !== '') {
                return;
            }

            loadLocationField.value = mappedLocationId;
        }

        function syncVehicleTransportCapacity() {
            if (!(vehicleField instanceof HTMLSelectElement) || !(transportCapacityField instanceof HTMLInputElement)) {
                return;
            }

            var selectedOption = vehicleField.options[vehicleField.selectedIndex];
            if (!selectedOption) {
                transportCapacityField.value = '';
                return;
            }

            var capacityRaw = String(selectedOption.getAttribute('data-capacitate-transport') || '').trim();
            transportCapacityField.value = capacityRaw;
        }

        function applyVehicleDefaultDistributionZone(onlyWhenEmpty) {
            if (!vehicleField || !zoneField) {
                return;
            }

            var transportType = String(tipField.value || '');
            if (!isDistributionTransport(transportType)) {
                return;
            }

            var vehicleId = String(vehicleField.value || '');
            var beneficiaryId = String(beneficiaryField.value || '');
            if (vehicleId === '') {
                return;
            }

            var mappedZoneId = '';
            if (
                beneficiaryId !== ''
                && Object.prototype.hasOwnProperty.call(vehicleDefaultDistributionZonesByBeneficiary, beneficiaryId)
                && Object.prototype.hasOwnProperty.call(vehicleDefaultDistributionZonesByBeneficiary[beneficiaryId] || {}, vehicleId)
            ) {
                mappedZoneId = String((vehicleDefaultDistributionZonesByBeneficiary[beneficiaryId] || {})[vehicleId] || '');
            } else if (Object.prototype.hasOwnProperty.call(vehicleDefaultDistributionZones, vehicleId)) {
                mappedZoneId = String(vehicleDefaultDistributionZones[vehicleId] || '');
            }

            if (mappedZoneId === '' || !hasZoneOption(mappedZoneId)) {
                return;
            }

            if (onlyWhenEmpty && String(zoneField.value || '') !== '') {
                return;
            }

            zoneField.value = mappedZoneId;
        }

        function syncZoneFieldLabel(transportType) {
            if (!zoneLabel) {
                return;
            }

            var defaultLabel = String(zoneLabel.getAttribute('data-default-label') || 'Zona distributie').trim();
            var primaryLabel = String(zoneLabel.getAttribute('data-primary-label') || 'Zona descarcare').trim();
            var primaryKmLabel = String(zoneLabel.getAttribute('data-primary-km-label') || 'Loc descarcare').trim();

            if (isPrimaryKmTransport(transportType)) {
                zoneLabel.textContent = primaryKmLabel;
                return;
            }

            zoneLabel.textContent = isPrimaryTonTransport(transportType) ? primaryLabel : defaultLabel;
        }

        function syncKmFieldLabels(transportType) {
            var useAgreedKmLabels = isPrimaryKmTransport(transportType) || isDistributionWithKmTransport(transportType);

            if (kmLabel) {
                var kmDefaultLabel = String(kmLabel.getAttribute('data-default-label') || 'Km efectuati').trim();
                var kmPrimaryKmLabel = String(kmLabel.getAttribute('data-primary-km-label') || 'Km agreati').trim();
                kmLabel.textContent = useAgreedKmLabels ? kmPrimaryKmLabel : kmDefaultLabel;
            }

            if (kmTotalLabel) {
                var kmTotalDefaultLabel = String(kmTotalLabel.getAttribute('data-default-label') || 'Km totali').trim();
                var kmTotalPrimaryKmLabel = String(kmTotalLabel.getAttribute('data-primary-km-label') || 'Km efectuati').trim();
                kmTotalLabel.textContent = useAgreedKmLabels ? kmTotalPrimaryKmLabel : kmTotalDefaultLabel;
            }
        }

        function formatKmValueRo(value) {
            var normalized = Math.max(0, parseNumber(value));
            var isInteger = Math.abs(normalized - Math.round(normalized)) < 0.0001;
            try {
                return Number(normalized).toLocaleString('ro-RO', {
                    minimumFractionDigits: isInteger ? 0 : 2,
                    maximumFractionDigits: isInteger ? 0 : 2
                });
            } catch (error) {
                return String(isInteger ? Math.round(normalized) : roundToTwo(normalized)).replace('.', ',');
            }
        }

        function formatSignedKmValueRo(value) {
            var normalized = parseNumber(value);
            var isInteger = Math.abs(normalized - Math.round(normalized)) < 0.0001;
            try {
                return Number(normalized).toLocaleString('ro-RO', {
                    minimumFractionDigits: isInteger ? 0 : 2,
                    maximumFractionDigits: isInteger ? 0 : 2
                });
            } catch (error) {
                return String(isInteger ? Math.round(normalized) : roundToTwo(normalized)).replace('.', ',');
            }
        }

        function syncKmDistributionCalculationNote(transportType, kmAgreatiValue, kmEfectuatiValue, distributionBillingValue, costKmDistributieValue) {
            if (!(kmDistributionCalculationNote instanceof HTMLElement)) {
                return;
            }

            var isMixedTransport = String(transportType || '').trim() === 'primar_distributie';
            kmDistributionCalculationNote.classList.toggle('d-none', !isMixedTransport);
            if (!isMixedTransport) {
                return;
            }

            var kmAgreati = Math.max(0, parseNumber(kmAgreatiValue));
            var kmEfectuati = Math.max(0, parseNumber(kmEfectuatiValue));
            var kmDistributie = Math.max(0, kmEfectuati - kmAgreati);
            var distributionBilling = Math.max(0, parseNumber(distributionBillingValue));
            kmDistributionCalculationNote.textContent =
                'Cost/km Distributie (calcul): Km distributie = Km efectuati - Km agreati'
                + ' (' + formatKmValueRo(kmEfectuati) + ' - ' + formatKmValueRo(kmAgreati) + ' = ' + formatKmValueRo(kmDistributie) + ')'
                + '; Cost/km Distributie = Cost distributie (Pret tona x tone) / Km distributie.';
            if (distributionBilling > 0 && kmDistributie > 0) {
                kmDistributionCalculationNote.textContent += ' '
                    + formatCurrencyRo(distributionBilling)
                    + ' / '
                    + formatKmValueRo(kmDistributie)
                    + ' km'
                    + ' = '
                    + formatCostPerKmRo(costKmDistributieValue);
            }
        }

        function syncCostKmMixtCalculationNote(transportType, totalValue, kmTotaliValue, costKmMixtValue) {
            if (!(costKmMixtCalculationNote instanceof HTMLElement)) {
                return;
            }

            var normalizedTransportType = String(transportType || '').trim();
            var isMixedTransport = normalizedTransportType === 'primar_distributie' || normalizedTransportType === 'mixt';
            costKmMixtCalculationNote.classList.toggle('d-none', !isMixedTransport);
            if (!isMixedTransport) {
                return;
            }

            var kmTotali = Math.max(0, parseNumber(kmTotaliValue));
            if (kmTotali <= 0) {
                costKmMixtCalculationNote.textContent =
                    'Cost/km Mixt (calcul): Total facturare estimata / Km efectuati (introdu Km efectuati > 0).';
                return;
            }

            if (parseNumber(totalValue) <= 0) {
                costKmMixtCalculationNote.textContent =
                    'Cost/km Mixt (calcul): Total facturare estimata / Km efectuati (Total facturare trebuie sa fie > 0).';
                return;
            }

            costKmMixtCalculationNote.textContent =
                'Cost/km Mixt (calcul): Total facturare estimata / Km efectuati = '
                + formatCurrencyRo(totalValue)
                + ' / '
                + formatKmValueRo(kmTotali)
                + ' km'
                + ' = '
                + formatCostPerKmRo(costKmMixtValue);
        }

        function shouldShowCompressorLiquidSuctionField(transportType, rates) {
            return transportType === 'compresor';
        }

        function shouldShowCompressorGasSuctionField(transportType, rates) {
            return transportType === 'compresor';
        }

        function setPreviewFieldVisibility(fieldWrapper, isVisible) {
            if (!(fieldWrapper instanceof HTMLElement)) {
                return;
            }
            fieldWrapper.classList.toggle('d-none', !isVisible);
        }

        function syncPreviewFieldsVisibility(transportType) {
            var showTotal = true;
            var showCostPrimar = false;
            var showCostDistributie = String(transportType || '').trim() === 'distributie'
                || String(transportType || '').trim() === 'primar_distributie'
                || String(transportType || '').trim() === 'mixt';
            var showCostMixt = String(transportType || '').trim() === 'primar_distributie'
                || String(transportType || '').trim() === 'mixt';

            setPreviewFieldVisibility(totalPreviewField, showTotal);
            setPreviewFieldVisibility(costKmPrimarPreviewField, showCostPrimar);
            setPreviewFieldVisibility(costKmDistributiePreviewField, showCostDistributie);
            setPreviewFieldVisibility(costKmMixtPreviewField, showCostMixt);
        }

        function syncTransportMode() {
            var transportType = String(tipField.value || '');
            syncVehicleOptionsByContext();
            syncConfigTransportLink();
            var rates = getBeneficiaryRates(transportType);
            var isPrimaryKm = isPrimaryKmTransport(transportType);
            var isPrimaryTon = isPrimaryTonTransport(transportType);
            var isPrimary = isPrimaryTransport(transportType);
            var isDistribution = isDistributionTransport(transportType);
            var isCompressor = transportType === 'compresor';
            form.classList.toggle('dispatcher-primary-layout', transportType === '' || isPrimary || isDistributionTransport(transportType));
            form.classList.toggle('dispatcher-compressor-layout', isCompressor);
            form.classList.toggle('dispatcher-primar-km-compact-layout', isPrimaryKm);
            syncPreviewFieldsVisibility(transportType);
            syncZoneFieldLabel(transportType);
            syncKmFieldLabels(transportType);

            setFieldState(loadLocationWrapper, loadLocationField, !isCompressor, true);
            setFieldState(departureLocationWrapper, departureLocationField, isCompressor, true);
            setFieldState(suctionLocationWrapper, suctionLocationField, isCompressor, true);
            setFieldState(deliveryLocationWrapper, deliveryLocationField, isCompressor, true);
            setFieldState(raceDeliveryLocationWrapper, raceDeliveryLocationField, isCompressor, true);
            setFieldState(kmWrapper, kmField, !isCompressor, true);
            setFieldState(kmTotalWrapper, kmTotalField, isPrimary || isDistributionWithKmTransport(transportType), true);
            syncKmDistributionCalculationNote(
                transportType,
                kmField ? kmField.value : 0,
                kmTotalField ? kmTotalField.value : 0
            );

            if (isCompressor) {
                setFieldState(quantityWrapper, quantityField, false, true);
            } else {
                // Pentru Primar km, cantitatea ramane editabila pentru stocare in lista curse,
                // dar calculul total continua sa foloseasca doar km-ul de tarifare.
                setFieldState(quantityWrapper, quantityField, true, false);
            }
            setFieldState(clientsWrapper, clientsField, isDistribution, true);
            setFieldState(transportCapacityWrapper, transportCapacityField, !isCompressor, true);
            setFieldState(zoneWrapper, zoneField, (isDistribution || isPrimaryKm || isPrimaryTon), true);
            setFieldState(suctionHoursWrapper, suctionHoursField, isCompressor, true);
            setFieldState(relocationKmWrapper, relocationKmField, isCompressor, true);
            setFieldState(deliveredTonWrapper, deliveredTonField, isCompressor, true);
            setFieldState(suctionLiquidTonWrapper, suctionLiquidTonField, isCompressor, true);
            setFieldState(suctionGasTonWrapper, suctionGasTonField, isCompressor, true);

            if (distributionLocationNote) {
                distributionLocationNote.classList.toggle('d-none', !isDistribution);
            }
            if (distributionZoneNote) {
                distributionZoneNote.classList.toggle('d-none', !isDistribution);
            }
            if (primaryLocationNote) {
                primaryLocationNote.classList.toggle('d-none', !isPrimary);
            }
            if (primaryZoneNote) {
                primaryZoneNote.classList.toggle('d-none', !isPrimary);
            }

            if (kmField) {
                kmField.readOnly = isPrimary || isDistributionWithKmTransport(transportType);
                if (!isPrimary) {
                    kmField.removeAttribute('data-primary-km-manual');
                    kmField.removeAttribute('data-primary-route-id');
                }
            }

            // Doar pentru Distributie: "Data incarcare" se afiseaza inaintea "Data si ora inceput";
            // pentru celelalte tipuri ramane ordinea implicita (start inaintea datei de incarcare).
            var loadingDateWrapEl = form.querySelector('[data-role="field-data-incarcare"]');
            var startDateTimeWrapEl = form.querySelector('[data-role="field-start-datetime"]');
            if (loadingDateWrapEl instanceof HTMLElement && startDateTimeWrapEl instanceof HTMLElement && loadingDateWrapEl.parentNode === startDateTimeWrapEl.parentNode) {
                if (transportType === 'distributie') {
                    if (startDateTimeWrapEl.previousElementSibling !== loadingDateWrapEl) {
                        startDateTimeWrapEl.parentNode.insertBefore(loadingDateWrapEl, startDateTimeWrapEl);
                    }
                } else if (loadingDateWrapEl.previousElementSibling !== startDateTimeWrapEl) {
                    loadingDateWrapEl.parentNode.insertBefore(startDateTimeWrapEl, loadingDateWrapEl);
                }
            }

            if (kmField && (isPrimaryKm || isDistributionWithKmTransport(transportType)) && !isCompressor) {
                kmField.setAttribute('required', 'required');
            } else if (kmField) {
                kmField.removeAttribute('required');
            }

            if (quantityField && (isDistribution || isPrimaryTon)) {
                quantityField.setAttribute('required', 'required');
            } else if (quantityField) {
                quantityField.removeAttribute('required');
            }

            if (zoneField && (isDistribution || isPrimary)) {
                zoneField.setAttribute('required', 'required');
            } else if (zoneField) {
                zoneField.removeAttribute('required');
            }

            if (loadLocationField && !isCompressor) {
                loadLocationField.setAttribute('required', 'required');
            } else if (loadLocationField) {
                loadLocationField.removeAttribute('required');
            }

            if (suctionHoursField && isCompressor) {
                suctionHoursField.setAttribute('required', 'required');
            } else if (suctionHoursField) {
                suctionHoursField.removeAttribute('required');
            }

            [departureLocationField, suctionLocationField, deliveryLocationField, raceDeliveryLocationField].forEach(function (field) {
                if (!(field instanceof HTMLInputElement)) {
                    return;
                }
                if (isCompressor) {
                    field.setAttribute('required', 'required');
                } else {
                    field.removeAttribute('required');
                }
            });

            syncScopedLocationZoneOptions();
            if (isDistribution) {
                applyVehicleDefaultLoadLocation(true);
                syncScopedLocationZoneOptions();
                applyVehicleDefaultDistributionZone(true);
            }
            syncFieldHoverHints(transportType);
            recalculateTotal();
        }

        function recalculateTotal() {
            var transportType = String(tipField.value || '');
            if (isPrimaryTransport(transportType)) {
                var selectedBeneficiaryForPrimary = String(beneficiaryField ? (beneficiaryField.value || '') : '').trim();
                var primaryScopeForCalculation = selectedBeneficiaryForPrimary !== ''
                    && Object.prototype.hasOwnProperty.call(primaryRouteScopeByBeneficiary, selectedBeneficiaryForPrimary)
                    ? primaryRouteScopeByBeneficiary[selectedBeneficiaryForPrimary]
                    : { hasActiveRules: false, pairMap: {}, locationOptions: [], zoneOptions: [] };
                applyPrimaryRouteKmTariff(primaryScopeForCalculation);
            } else if (isDistributionWithKmTransport(transportType)) {
                applyDistributionRouteKmTariff();
            }

            var transportCapacityValue = parseNumber(transportCapacityField ? transportCapacityField.value : 0);
            var rawQuantityValue = parseNumber(quantityField ? quantityField.value : 0);
            var quantityValue = rawQuantityValue;
            var kmValue = parseNumber(kmField ? kmField.value : 0);
            var kmTotalValue = parseNumber(kmTotalField ? kmTotalField.value : 0);
            syncKmDistributionCalculationNote(transportType, kmValue, kmTotalValue);
            var suctionHoursValue = parseNumber(suctionHoursField ? suctionHoursField.value : 0);
            var relocationKmValue = parseNumber(relocationKmField ? relocationKmField.value : 0);
            var rawDeliveredTonValue = parseNumber(deliveredTonField ? deliveredTonField.value : 0);
            var deliveredTonValue = normalizeTonInputToKgForPricing(rawDeliveredTonValue, transportCapacityValue);
            var liquidSuctionTonValue = parseNumber(suctionLiquidTonField ? suctionLiquidTonField.value : 0);
            var gasSuctionTonValue = parseNumber(suctionGasTonField ? suctionGasTonField.value : 0);
            var rates = getBeneficiaryRates(transportType);
            var showCompressorLiquidSuctionField = shouldShowCompressorLiquidSuctionField(transportType, rates);
            var showCompressorGasSuctionField = shouldShowCompressorGasSuctionField(transportType, rates);
            var selectedBeneficiaryId = beneficiaryField ? beneficiaryField.value : '';
            var selectedVehicleId = vehicleField ? vehicleField.value : '';
            var selectedLocationId = loadLocationField ? loadLocationField.value : '';
            var selectedZoneId = zoneField ? zoneField.value : '';
            var selectedPrimaryRouteRule = null;
            if (isPrimaryTransport(transportType)) {
                var selectedPrimaryScope = String(selectedBeneficiaryId || '').trim() !== ''
                    && Object.prototype.hasOwnProperty.call(primaryRouteScopeByBeneficiary, String(selectedBeneficiaryId))
                    ? primaryRouteScopeByBeneficiary[String(selectedBeneficiaryId)]
                    : { hasActiveRules: false, pairMap: {}, locationOptions: [], zoneOptions: [] };
                selectedPrimaryRouteRule = getPrimaryRouteRule(
                    selectedPrimaryScope,
                    selectedBeneficiaryId,
                    selectedLocationId,
                    selectedZoneId
                );
            }
            var primaryFixedRideCost = selectedPrimaryRouteRule
                && selectedPrimaryRouteRule.active
                && selectedPrimaryRouteRule.applyRideCost
                && selectedPrimaryRouteRule.rideCost > 0
                ? selectedPrimaryRouteRule.rideCost
                : 0;
            var hasDistributionLocationSelection = String(selectedLocationId || '').trim() !== '';
            var hasDistributionZoneSelection = String(selectedZoneId || '').trim() !== '';
            var hasCompleteDistributionSelection = hasDistributionLocationSelection && hasDistributionZoneSelection;
            var locationTariff = getLoadLocationTariff(loadLocationField ? loadLocationField.value : '');
            var zoneTariff = getZoneTariff(zoneField ? zoneField.value : '');
            var zoneExtraKmCost = getZoneExtraKmCost(zoneField ? zoneField.value : '');
            var routeRule = getDistributionRouteRule(
                selectedBeneficiaryId,
                selectedLocationId,
                selectedZoneId,
                selectedVehicleId,
                transportType
            );
            var total = 0;
            var effectiveDistributionKmRate = 0;

            if (isPrimaryKmTransport(transportType)) {
                total = primaryFixedRideCost > 0 ? primaryFixedRideCost : (kmValue * rates.perKm);
            } else if (isPrimaryTonTransport(transportType)) {
                total = primaryFixedRideCost > 0 ? primaryFixedRideCost : (quantityValue * rates.perTon);
            } else if (isDistributionTransport(transportType)) {
                if (!hasCompleteDistributionSelection) {
                    total = 0;
                    effectiveDistributionKmRate = 0;
                } else {
                    var sameRoute = isSameDistributionRoute();
                    var effectiveRouteRule = routeRule;
                    var hasActiveEffectiveRouteRule = !!(effectiveRouteRule && effectiveRouteRule.active);
                    var routeUsesTonTariff = !hasActiveEffectiveRouteRule || distributionRouteUsesTonTariff(effectiveRouteRule.tariffMode);
                    var routeUsesKmTariff = !hasActiveEffectiveRouteRule || distributionRouteUsesKmTariff(effectiveRouteRule.tariffMode);
                    var effectiveTonRate = routeUsesTonTariff
                        ? (
                            effectiveRouteRule && effectiveRouteRule.active && effectiveRouteRule.tariffPerTon > 0
                                ? effectiveRouteRule.tariffPerTon
                                : resolveDistributionTonRate(locationTariff, zoneTariff, rates.perTon, sameRoute)
                        )
                        : 0;
                    var effectiveKmRate = routeUsesKmTariff
                        ? (
                            effectiveRouteRule && effectiveRouteRule.active && effectiveRouteRule.extraKmCost > 0
                                ? effectiveRouteRule.extraKmCost
                                : (zoneExtraKmCost > 0 ? zoneExtraKmCost : rates.perKm)
                        )
                        : 0;
                    var fixedRideCost = effectiveRouteRule && effectiveRouteRule.active && effectiveRouteRule.applyRideCost && effectiveRouteRule.rideCost > 0
                        ? effectiveRouteRule.rideCost
                        : 0;
                    effectiveDistributionKmRate = Math.max(0, effectiveKmRate);
                    var shouldApplyDistributionKmComponent = false;
                    if (fixedRideCost <= 0) {
                        if (transportType === 'distributie') {
                            // Distributie simpla foloseste strict Pret/km (optional) din setarile de distributie.
                            shouldApplyDistributionKmComponent = effectiveKmRate > 0;
                        } else if (isDistributionWithKmTransport(transportType)) {
                            shouldApplyDistributionKmComponent = true;
                        }
                    }
                    var distributionKmComponent = shouldApplyDistributionKmComponent
                        ? (kmValue * effectiveKmRate)
                        : 0;
                    total = fixedRideCost + ((fixedRideCost > 0 ? 0 : (quantityValue * effectiveTonRate))) + distributionKmComponent;
                }
            } else if (transportType === 'compresor') {
                total = (suctionHoursValue * rates.perHourSuction)
                    + (relocationKmValue * rates.perKmRelocation)
                    + (deliveredTonValue * rates.perDeliveredTon)
                    + (showCompressorLiquidSuctionField ? (liquidSuctionTonValue * rates.perSuctionLiquidTon) : 0)
                    + (showCompressorGasSuctionField ? (gasSuctionTonValue * rates.perSuctionGasTon) : 0);
            }

            var includesPrimarySegment = isPrimaryTransport(transportType) || isDistributionWithKmTransport(transportType);
            var includesDistributionSegment = isDistributionTransport(transportType);
            var primaryRates = getBeneficiaryRates('primar');
            var primaryPerKmRate = 0;
            if (includesPrimarySegment) {
                primaryPerKmRate = transportType === 'primar_distributie'
                    ? Math.max(0, effectiveDistributionKmRate)
                    : Math.max(0, parseNumber(primaryRates.perKm));
            }
            var distributionTonRate = 0;
            if (includesDistributionSegment && hasCompleteDistributionSelection) {
                var sameRouteForCost = isSameDistributionRoute();
                var effectiveRouteRuleForCost = routeRule;
                var costRouteUsesTonTariff = !(effectiveRouteRuleForCost && effectiveRouteRuleForCost.active)
                    || distributionRouteUsesTonTariff(effectiveRouteRuleForCost.tariffMode);
                distributionTonRate = costRouteUsesTonTariff
                    ? (
                        effectiveRouteRuleForCost && effectiveRouteRuleForCost.active && effectiveRouteRuleForCost.tariffPerTon > 0
                            ? effectiveRouteRuleForCost.tariffPerTon
                            : resolveDistributionTonRate(locationTariff, zoneTariff, rates.perTon, sameRouteForCost)
                    )
                    : 0;
            }
            distributionTonRate = Math.max(0, distributionTonRate);
            var distributionFixedRideCost = includesDistributionSegment
                && routeRule
                && routeRule.active
                && routeRule.applyRideCost
                && routeRule.rideCost > 0
                ? routeRule.rideCost
                : 0;

            var kmPrimar = 0;
            var kmDistributie = 0;
            if (transportType === 'primar_distributie') {
                kmPrimar = Math.max(0, kmValue);
                kmDistributie = Math.max(0, kmTotalValue - kmPrimar);
            } else if (transportType === 'distributie') {
                // Pentru Distributie simpla, divizorul este strict Km efectuati (nu diferenta).
                kmDistributie = Math.max(0, kmValue);
            } else if (isPrimaryTransport(transportType)) {
                kmPrimar = Math.max(0, kmValue);
            }

            var totalPrimar = includesPrimarySegment
                ? (
                    isPrimaryTransport(transportType) && primaryFixedRideCost > 0
                        ? primaryFixedRideCost
                        : (kmPrimar * primaryPerKmRate)
                )
                : 0;
            var totalDistributie = 0;
            if (includesDistributionSegment) {
                if (transportType === 'distributie') {
                    // Pentru Distributie simpla folosim totalul complet (tona + componenta km optionala).
                    totalDistributie = total;
                } else {
                    totalDistributie = distributionFixedRideCost > 0
                        ? distributionFixedRideCost
                        : (Math.max(0, quantityValue) * distributionTonRate);
                }
            }
            var costKmPrimar = includesPrimarySegment && kmPrimar > 0
                ? safeDivide(totalPrimar, kmPrimar)
                : 0;
            var costKmDistributie = 0;
            if (transportType === 'primar_distributie') {
                // Primar+Distributie foloseste doar valoarea componentei de distributie.
                costKmDistributie = kmDistributie > 0
                    ? safeDivide(totalDistributie, kmDistributie)
                    : 0;
            } else if (includesDistributionSegment && kmDistributie > 0) {
                costKmDistributie = safeDivide(totalDistributie, kmDistributie);
            }
            var costKmMixt = 0;
            if (transportType === 'primar_distributie' || transportType === 'mixt') {
                // Regula stabilita: Cost/km Mixt = Total facturare / Km efectuati.
                costKmMixt = kmTotalValue > 0
                    ? safeDivide(total, kmTotalValue)
                    : 0;
            } else if (includesPrimarySegment && !includesDistributionSegment) {
                costKmMixt = costKmPrimar;
            } else if (!includesPrimarySegment && includesDistributionSegment) {
                costKmMixt = costKmDistributie;
            }

            costKmPrimar = roundToTwo(costKmPrimar);
            costKmDistributie = roundToTwo(costKmDistributie);
            costKmMixt = roundToTwo(costKmMixt);
            syncKmDistributionCalculationNote(transportType, kmValue, kmTotalValue, totalDistributie, costKmDistributie);

            if (costKmPrimarPreview) {
                costKmPrimarPreview.textContent = formatCostPerKmRo(costKmPrimar);
            }
            if (costKmDistributiePreview) {
                costKmDistributiePreview.textContent = formatCostPerKmRo(costKmDistributie);
            }
            if (costKmMixtPreview) {
                costKmMixtPreview.textContent = formatCostPerKmRo(costKmMixt);
            }
            syncCostKmMixtCalculationNote(transportType, total, kmTotalValue, costKmMixt);

            if (priceDisplayField) {
                if (isPrimaryKmTransport(transportType)) {
                    priceDisplayField.value = primaryFixedRideCost > 0
                        ? ('mod calcul: cost cursa fix | cost cursa: ' + primaryFixedRideCost.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
                        : (
                            'mod calcul: km' +
                            ' | km: ' + rates.perKm.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                        );
                } else if (isPrimaryTonTransport(transportType)) {
                    priceDisplayField.value = primaryFixedRideCost > 0
                        ? ('mod calcul: cost cursa fix | cost cursa: ' + primaryFixedRideCost.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
                        : (
                            'mod calcul: tona' +
                            ' | tona: ' + rates.perTon.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                        );
                } else if (isDistributionTransport(transportType)) {
                    if (!hasCompleteDistributionSelection) {
                        priceDisplayField.value = 'Selecteaza locul de incarcare si zona de distributie pentru calcul.';
                    } else {
                        var sameRouteForDisplay = isSameDistributionRoute();
                        var effectiveDisplayRule = routeRule;
                        var routeSourceLabel = 'fallback';
                        if (effectiveDisplayRule && effectiveDisplayRule.active) {
                            routeSourceLabel = 'exact';
                        }
                        var hasActiveRouteRule = !!(effectiveDisplayRule && effectiveDisplayRule.active);
                        var displayUsesTonTariff = !hasActiveRouteRule || distributionRouteUsesTonTariff(effectiveDisplayRule.tariffMode);
                        var displayUsesKmTariff = !hasActiveRouteRule || distributionRouteUsesKmTariff(effectiveDisplayRule.tariffMode);
                        var effectiveDisplayTonRate = displayUsesTonTariff
                            ? (
                                hasActiveRouteRule && effectiveDisplayRule.tariffPerTon > 0
                                    ? effectiveDisplayRule.tariffPerTon
                                    : resolveDistributionTonRate(locationTariff, zoneTariff, rates.perTon, sameRouteForDisplay)
                            )
                            : 0;
                        var effectiveDisplayKmRate = displayUsesKmTariff
                            ? (
                                hasActiveRouteRule && effectiveDisplayRule.extraKmCost > 0
                                    ? effectiveDisplayRule.extraKmCost
                                    : (zoneExtraKmCost > 0 ? zoneExtraKmCost : rates.perKm)
                            )
                            : 0;
                        var displayFixedRideCost = hasActiveRouteRule && effectiveDisplayRule.applyRideCost && effectiveDisplayRule.rideCost > 0
                            ? effectiveDisplayRule.rideCost
                            : 0;
                        var displayCalculationMode = displayUsesTonTariff && displayUsesKmTariff
                            ? 'tona + km'
                            : (displayUsesTonTariff ? 'doar tona' : 'doar km');
                        priceDisplayField.value =
                            'regula ruta: ' + routeSourceLabel +
                            ' | ' +
                            'ruta: ' + (sameRouteForDisplay ? 'aceeasi localitate' : 'localitati diferite') +
                            ' | ' +
                            'loc: ' + locationTariff.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                            ' | zona: ' + zoneTariff.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                            ' | extra km: ' + zoneExtraKmCost.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                            ' | tarif tona activ: ' + effectiveDisplayTonRate.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                            ' | tarif km activ: ' + effectiveDisplayKmRate.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                            ' | mod calcul: ' + (displayFixedRideCost > 0
                                ? ('cost cursa fix (' + displayFixedRideCost.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ')')
                                : displayCalculationMode);
                    }
                } else if (transportType === 'compresor') {
                    priceDisplayField.value =
                        'ora: ' + rates.perHourSuction.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                        ' | km dislocare: ' + rates.perKmRelocation.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                        ' | tona livrata: ' + rates.perDeliveredTon.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                        ' | tona aspirata lichida: ' + rates.perSuctionLiquidTon.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                        ' | tona aspirata gazoasa: ' + rates.perSuctionGasTon.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) +
                        ((showCompressorLiquidSuctionField || showCompressorGasSuctionField) ? '' : ' | split aspirata: inactiv');
                } else {
                    priceDisplayField.value = '';
                }
            }

            var totalForDisplay = total + invoicedRefacturareTotal;
            totalPreview.textContent = formatCurrencyRo(totalForDisplay);
        }

        tipField.addEventListener('change', syncTransportMode);
        beneficiaryField.addEventListener('change', function () {
            syncVehicleOptionsByContext();
            syncConfigTransportLink();
            syncDriverOptionsByVehicle(false);
            syncScopedLocationZoneOptions();
            applyVehicleDefaultLoadLocation(false);
            syncScopedLocationZoneOptions();
            applyVehicleDefaultDistributionZone(true);
            recalculateTotal();
            promptInactiveResourcesAfterSelectionChange();
        });
        if (vehicleField) {
            vehicleField.addEventListener('change', function () {
                if (String(vehicleField.value || '') === SHOW_ALL_VEHICLES_VALUE) {
                    // Extinde lista cu toate vehiculele active; alegerea unui vehicul neconfigurat cere decizia adminului.
                    vehicleListExpanded = true;
                    syncVehicleOptionsByContext();
                    vehicleField.focus();
                    return;
                }
                maybePromptVehicleRouteDecision();
                syncDriverOptionsByVehicle(false);
                syncVehicleTransportCapacity();
                syncScopedLocationZoneOptions();
                applyVehicleDefaultLoadLocation(false);
                syncScopedLocationZoneOptions();
                applyVehicleDefaultDistributionZone(false);
                recalculateTotal();
                promptInactiveResourcesAfterSelectionChange();
            });
        }
        if (driverField) {
            driverField.addEventListener('change', function () {
                if (String(driverField.value || '') === SHOW_ALL_DRIVERS_VALUE) {
                    // Extinde lista cu toti soferii activi; soferul ales ramane valabil doar pentru aceasta cursa.
                    driverListExpanded = true;
                    syncDriverOptionsByVehicle(false);
                    driverField.focus();
                    return;
                }
                promptInactiveResourcesAfterSelectionChange();
            });
        }
        if (loadLocationField) {
            loadLocationField.addEventListener('change', function () {
                syncScopedLocationZoneOptions();
                recalculateTotal();
            });
        }
        if (zoneField) {
            zoneField.addEventListener('change', function () {
                syncScopedLocationZoneOptions();
                recalculateTotal();
            });
        }

        [kmField, kmTotalField, quantityField, zoneField, suctionHoursField, relocationKmField, deliveredTonField, suctionLiquidTonField, suctionGasTonField].forEach(function (field) {
            if (!field) {
                return;
            }
            field.addEventListener('input', recalculateTotal);
            field.addEventListener('change', recalculateTotal);
        });

        [startTimeField, endTimeField].forEach(function (field) {
            if (!(field instanceof HTMLInputElement)) {
                return;
            }

            normalizeTimeFieldValue(field);
            field.addEventListener('blur', function () {
                normalizeTimeFieldValue(field);
                syncRaceDurationHint();
            });
            field.addEventListener('change', function () {
                normalizeTimeFieldValue(field);
            });
        });

        raceDateFields.forEach(function (field) {
            if (!(field instanceof HTMLInputElement)) {
                return;
            }

            normalizeRaceDateFieldValue(field);
            field.addEventListener('blur', function () {
                normalizeRaceDateFieldValue(field);
                syncRaceDurationHint();
            });
            field.addEventListener('change', function () {
                normalizeRaceDateFieldValue(field);
            });
        });

        dateTimePickers = [
            createDateTimePickerContext(
                startDateTimeField,
                startDateTimeDisplayField,
                startDateTimeToggleButton,
                startDateTimePopover,
                startDateField,
                startTimeField,
                startDateTimePickerState
            ),
            createDateTimePickerContext(
                endDateTimeField,
                endDateTimeDisplayField,
                endDateTimeToggleButton,
                endDateTimePopover,
                endDateField,
                endTimeField,
                endDateTimePickerState
            )
        ].filter(function (dateTimePicker) {
            return dateTimePicker !== null;
        });

        dateTimePickers.forEach(function (dateTimePicker) {
            initStartDateTimeField(dateTimePicker);
        });

        timeNowButtonEls.forEach(function (button) {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            var targetRole = String(button.getAttribute('data-target-role') || '').trim();
            var targetField = null;
            if (targetRole === 'ora-inceput') {
                targetField = startTimeField;
            } else if (targetRole === 'ora-sfarsit') {
                targetField = endTimeField;
            } else if (targetRole !== '') {
                targetField = form.querySelector('[data-role="' + targetRole.replace(/"/g, '\\"') + '"]');
            }

            if (!(targetField instanceof HTMLInputElement)) {
                return;
            }

            button.addEventListener('click', function () {
                applyCurrentTime(targetField);
            });
        });

        [startDateField, endDateField, startTimeField, endTimeField].forEach(function (field) {
            if (!field) {
                return;
            }
            field.addEventListener('input', syncRaceDurationHint);
            field.addEventListener('change', syncRaceDurationHint);
        });

        form.addEventListener('submit', function (event) {
            var firstInvalidDateTimePicker = null;
            dateTimePickers.forEach(function (dateTimePicker) {
                setActiveDateTimePicker(dateTimePicker);
                if (!syncStartDateTimeFieldsFromDisplay(true) && firstInvalidDateTimePicker === null) {
                    firstInvalidDateTimePicker = dateTimePicker;
                }
            });

            if (firstInvalidDateTimePicker !== null) {
                event.preventDefault();
                event.stopPropagation();
                if (typeof event.stopImmediatePropagation === 'function') {
                    event.stopImmediatePropagation();
                }
                firstInvalidDateTimePicker.displayField.focus();
                return;
            }

            raceDateFields.forEach(function (field) {
                normalizeRaceDateFieldValue(field);
            });
            normalizeTimeFieldValue(startTimeField);
            normalizeTimeFieldValue(endTimeField);

            if (form.dataset.inactiveApprovalBypass === '1') {
                delete form.dataset.inactiveApprovalBypass;
                return;
            }

            if (inactiveStatusUrl === '' || inactiveApprovalDecisionIsCurrent()) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }

            checkInactiveResourcesForSelection({ showModal: false, ignoreSuppression: true }).then(function (result) {
                if (result.decisionRequired.length === 0) {
                    clearInactiveApprovalDecision();
                    submitRaceFormAfterInactiveDecision();
                    return;
                }

                if (result.promptable.length === 0) {
                    setInactiveApprovalDecision('pending');
                    submitRaceFormAfterInactiveDecision();
                    return;
                }

                var promptableUserVehicle = result.promptable.find(normalUserVehicleResource) || null;
                var resourcesToPrompt = promptableUserVehicle !== null ? [promptableUserVehicle] : result.promptable;
                if (!showInactiveModal(resourcesToPrompt, true)) {
                    setInactiveApprovalDecision('pending');
                    submitRaceFormAfterInactiveDecision();
                }
            }).catch(function () {
                alert('Nu s-a putut verifica statusul resurselor inactive. Incearca din nou.');
            });
        }, true);

        initGoodsTypeDropdown();
        syncTransportMode();
        syncConfigTransportLink();
        syncRaceDurationHint();
    }

    function initCreateExpenseInRaceForm(form) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var enabledField = form.querySelector('[data-role="create-expense-enabled"]');
        var fieldsBox = form.querySelector('[data-role="create-expense-fields"]');
        var itemsContainer = form.querySelector('[data-role="create-expense-items"]');
        var addRowButton = form.querySelector('[data-role="create-expense-add-row"]');
        var rowTemplate = form.querySelector('[data-role="create-expense-row-template"]');
        if (
            !(enabledField instanceof HTMLInputElement)
            || !(fieldsBox instanceof HTMLElement)
            || !(itemsContainer instanceof HTMLElement)
            || !(addRowButton instanceof HTMLElement)
            || !(rowTemplate instanceof HTMLTemplateElement)
        ) {
            return;
        }

        var parseNumber = function (value) {
            var normalized = String(value || '').trim().replace(',', '.');
            if (normalized === '') {
                return null;
            }
            var parsed = Number(normalized);
            return Number.isFinite(parsed) ? parsed : null;
        };

        var formatAmount = function (value) {
            return (Math.round(value * 100) / 100).toFixed(2);
        };

        var calculateRoadTaxTotal = function (row) {
            var roadTaxFields = [
                {
                    qty: row.querySelector('[name$="[taxa_acces_bucati]"]'),
                    price: row.querySelector('[name$="[taxa_acces_pret]"]')
                },
                {
                    qty: row.querySelector('[name$="[port_bucati]"]'),
                    price: row.querySelector('[name$="[port_pret]"]')
                },
                {
                    qty: row.querySelector('[name$="[trece_bucati]"]'),
                    price: row.querySelector('[name$="[trece_pret]"]')
                }
            ];
            var total = 0;
            roadTaxFields.forEach(function (field) {
                if (!(field.qty instanceof HTMLInputElement) || !(field.price instanceof HTMLInputElement)) {
                    return;
                }

                var qty = parseNumber(field.qty.value);
                var price = parseNumber(field.price.value);
                if (qty !== null && qty > 0 && price !== null && price > 0) {
                    total += qty * price;
                }
            });
            return Math.round(total * 100) / 100;
        };

        var hasAnyRoadTaxInput = function (row) {
            var roadTaxFields = [
                {
                    qty: row.querySelector('[name$="[taxa_acces_bucati]"]'),
                    price: row.querySelector('[name$="[taxa_acces_pret]"]')
                },
                {
                    qty: row.querySelector('[name$="[port_bucati]"]'),
                    price: row.querySelector('[name$="[port_pret]"]')
                },
                {
                    qty: row.querySelector('[name$="[trece_bucati]"]'),
                    price: row.querySelector('[name$="[trece_pret]"]')
                }
            ];
            for (var i = 0; i < roadTaxFields.length; i++) {
                var field = roadTaxFields[i];
                if (!(field.qty instanceof HTMLInputElement) || !(field.price instanceof HTMLInputElement)) {
                    continue;
                }

                if (String(field.qty.value || '').trim() !== '' || String(field.price.value || '').trim() !== '') {
                    return true;
                }
            }
            return false;
        };

        var syncExpenseRowMode = function (row) {
            if (!(row instanceof HTMLElement)) {
                return;
            }

            var typeField = row.querySelector('[data-role="create-expense-type"]');
            var amountField = row.querySelector('[data-role="create-expense-amount"]');
            var roadTaxBox = row.querySelector('[data-role="create-expense-road-tax-breakdown"]');
            if (
                !(typeField instanceof HTMLSelectElement)
                || !(amountField instanceof HTMLInputElement)
                || !(roadTaxBox instanceof HTMLElement)
            ) {
                return;
            }

            var enabled = enabledField.checked;
            var isRoadTax = typeField.value === 'taxe_drum';

            roadTaxBox.classList.toggle('d-none', !(enabled && isRoadTax));
            amountField.disabled = !enabled;
            typeField.disabled = !enabled;

            if (!enabled) {
                amountField.readOnly = false;
                return;
            }

            if (isRoadTax) {
                var total = calculateRoadTaxTotal(row);
                amountField.readOnly = true;
                if (total > 0) {
                    amountField.value = formatAmount(total);
                } else if (hasAnyRoadTaxInput(row)) {
                    amountField.value = '';
                }
            } else {
                amountField.readOnly = false;
            }
        };

        var reindexRows = function () {
            var rows = itemsContainer.querySelectorAll('[data-role="create-expense-item"]');
            rows.forEach(function (row, idx) {
                var rowNumber = row.querySelector('[data-role="create-expense-row-number"]');
                if (rowNumber instanceof HTMLElement) {
                    rowNumber.textContent = String(idx + 1);
                }
            });
        };

        var bindExpenseRow = function (row) {
            if (!(row instanceof HTMLElement) || row.dataset.boundExpenseRow === '1') {
                return;
            }

            var typeField = row.querySelector('[data-role="create-expense-type"]');
            var removeButton = row.querySelector('[data-role="create-expense-remove-row"]');
            var roadInputs = row.querySelectorAll('[data-role="create-expense-road-qty"], [data-role="create-expense-road-price"]');

            if (typeField instanceof HTMLSelectElement) {
                typeField.addEventListener('change', function () {
                    syncExpenseRowMode(row);
                });
            }

            roadInputs.forEach(function (input) {
                if (input instanceof HTMLInputElement) {
                    input.addEventListener('input', function () {
                        syncExpenseRowMode(row);
                    });
                }
            });

            if (removeButton instanceof HTMLElement) {
                removeButton.addEventListener('click', function () {
                    var rows = itemsContainer.querySelectorAll('[data-role="create-expense-item"]');
                    if (rows.length <= 1) {
                        return;
                    }
                    row.remove();
                    reindexRows();
                });
            }

            row.dataset.boundExpenseRow = '1';
            syncExpenseRowMode(row);
        };

        var getNextRowIndex = function () {
            var maxIndex = -1;
            var inputs = itemsContainer.querySelectorAll('input[name^="create_expense_items["], select[name^="create_expense_items["], textarea[name^="create_expense_items["]');
            inputs.forEach(function (input) {
                if (!(input instanceof HTMLElement)) {
                    return;
                }
                var name = input.getAttribute('name') || '';
                var match = name.match(/^create_expense_items\[(\d+)\]/);
                if (!match) {
                    return;
                }
                var idx = Number(match[1]);
                if (Number.isFinite(idx) && idx > maxIndex) {
                    maxIndex = idx;
                }
            });
            return maxIndex + 1;
        };

        var addExpenseRow = function () {
            var nextIndex = getNextRowIndex();
            var nextRowNumber = itemsContainer.querySelectorAll('[data-role="create-expense-item"]').length + 1;
            var html = rowTemplate.innerHTML
                .replace(/__INDEX__/g, String(nextIndex))
                .replace(/__ROW__/g, String(nextRowNumber));

            var wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            var row = wrapper.firstElementChild;
            if (!(row instanceof HTMLElement)) {
                return;
            }

            itemsContainer.appendChild(row);
            bindExpenseRow(row);
            reindexRows();
        };

        var syncSectionMode = function () {
            var enabled = enabledField.checked;
            fieldsBox.classList.toggle('d-none', !enabled);
            addRowButton.toggleAttribute('disabled', !enabled);

            var rows = itemsContainer.querySelectorAll('[data-role="create-expense-item"]');
            rows.forEach(function (row) {
                var controls = row.querySelectorAll('input, select, textarea, button[data-role="create-expense-remove-row"]');
                controls.forEach(function (control) {
                    if (!(control instanceof HTMLInputElement) && !(control instanceof HTMLSelectElement) && !(control instanceof HTMLTextAreaElement) && !(control instanceof HTMLButtonElement)) {
                        return;
                    }
                    if (control.getAttribute('data-role') === 'create-expense-remove-row') {
                        control.toggleAttribute('disabled', !enabled);
                        return;
                    }
                    control.disabled = !enabled;
                });
                syncExpenseRowMode(row);
            });
        };

        addRowButton.addEventListener('click', addExpenseRow);
        enabledField.addEventListener('change', syncSectionMode);

        var existingRows = itemsContainer.querySelectorAll('[data-role="create-expense-item"]');
        existingRows.forEach(function (row) {
            bindExpenseRow(row);
        });
        reindexRows();
        syncSectionMode();
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTableMiddleMouseScroll();

        var forms = document.querySelectorAll('.dispatcher-race-form');
        if (!forms.length) {
            return;
        }

        forms.forEach(function (form) {
            initRaceForm(form);
            initCreateExpenseInRaceForm(form);
        });
    });
})();
