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
        var startTimeField = form.querySelector('[data-role="ora-inceput"]');
        var endTimeField = form.querySelector('[data-role="ora-sfarsit"]');
        var timeNowButtonEls = form.querySelectorAll('[data-role="time-now"]');
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

        if (!tipField || !beneficiaryField || !totalPreview) {
            return;
        }

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

        function getDistributionRulesForBeneficiary(beneficiaryId) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            var beneficiaryRules = beneficiaryKey !== '' && Object.prototype.hasOwnProperty.call(distributionRouteRulesByBeneficiary, beneficiaryKey)
                ? (distributionRouteRulesByBeneficiary[beneficiaryKey] || [])
                : [];

            return beneficiaryRules.concat(distributionGlobalRules);
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

                var addPair = function (locationIdRaw, zoneIdRaw, kmTariff, routeId, matchDirection) {
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
                        ruleId: normalizedRouteId,
                        matchDirection: String(matchDirection || 'direct')
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
                return !!pricingConfig.suporta_distributie;
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

        function getDistributionScopedVehicleSetForBeneficiary(beneficiaryId) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            var vehicleSet = {};
            var hasScopedRules = false;

            if (beneficiaryKey === '') {
                return { hasScopedRules: false, vehicleSet: {} };
            }

            var rules = getDistributionRulesForBeneficiary(beneficiaryKey);
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

            var baseAssignedVehicleSet = getAssignedVehicleSetForBeneficiary(beneficiaryKey);
            var allowedVehicleSet = {};

            if (isPrimaryTransport(transportType)) {
                allowedVehicleSet = activeDriverVehicleSet;
            } else if (isDistributionTransport(transportType)) {
                var scopedDistributionVehicles = getDistributionScopedVehicleSetForBeneficiary(beneficiaryKey);
                if (scopedDistributionVehicles.hasScopedRules) {
                    allowedVehicleSet = scopedDistributionVehicles.vehicleSet;
                } else {
                    allowedVehicleSet = baseAssignedVehicleSet;
                }
            } else {
                // Compresor: preferam alocarea dedicata; fallback pe Distributie si apoi pe configurari existente.
                var compressorAssignedVehicleSet = getCompressorAssignedVehicleSetForBeneficiary(beneficiaryKey);
                if (Object.keys(compressorAssignedVehicleSet).length > 0) {
                    allowedVehicleSet = compressorAssignedVehicleSet;
                } else {
                    var distributionScopedFallback = getDistributionScopedVehicleSetForBeneficiary(beneficiaryKey);
                    if (distributionScopedFallback.hasScopedRules && Object.keys(distributionScopedFallback.vehicleSet).length > 0) {
                        allowedVehicleSet = distributionScopedFallback.vehicleSet;
                    } else {
                        allowedVehicleSet = baseAssignedVehicleSet;
                    }
                }
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
                var capacityValue = String(option.capacity || '').trim();
                if (capacityValue !== '') {
                    optionEl.setAttribute('data-capacitate-transport', capacityValue);
                }
                if (optionValue === targetValue) {
                    optionEl.selected = true;
                    hasSelectedValue = true;
                }
                vehicleField.appendChild(optionEl);
            });

            if (!hasSelectedValue) {
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
                if (optionValue === targetValue) {
                    optionEl.selected = true;
                    hasSelectedValue = true;
                }
                driverField.appendChild(optionEl);
            });

            if (!hasSelectedValue) {
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
                    placeholderLabel = '-- Niciun vehicul activ cu sofer asociat --';
                } else if (transportType === 'compresor') {
                    placeholderLabel = '-- Configureaza Vehicule Compresor in Configurare Transport --';
                } else {
                    placeholderLabel = '-- Niciun vehicul configurat --';
                }
            }

            rebuildVehicleSelectOptions(allowedVehicleOptions, selectedVehicleId, placeholderLabel);
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

        function rebuildSelectOptions(selectField, options, selectedValue, placeholderLabel) {
            if (!(selectField instanceof HTMLSelectElement)) {
                return;
            }

            var targetValue = String(selectedValue || '');
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

            if (!hasSelectedValue) {
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
                    zoneLabel += ' (tarif zonÄƒ: '
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

        function getVehicleScopedRouteRules(beneficiaryId, vehicleId) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            var selectedVehicleId = String(vehicleId || '').trim();
            var rules = beneficiaryKey !== ''
                ? getDistributionRulesForBeneficiary(beneficiaryKey)
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
                ? 'Sunt afisate doar locurile si zonele care au perechi configurate pentru vehiculul selectat (Loc ↔ Zona).'
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

            return {
                ruleId: routeId,
                kmTariff: Math.max(0, Math.round(kmTariff)),
                active: isActive,
                matchDirection: String(matchDirection || rawRule.matchDirection || 'direct')
            };
        }

        function getPrimaryRouteRuleFromMapKey(beneficiaryKey, locationKey, zoneKey, matchDirection) {
            var pairKey = beneficiaryKey + '|' + locationKey + '|' + zoneKey;
            if (!Object.prototype.hasOwnProperty.call(primaryRouteKmMap, pairKey)) {
                return null;
            }

            return normalizePrimaryRouteRule(primaryRouteKmMap[pairKey], matchDirection);
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
                var selectedPairText = 'Combinatia selectata Loc ↔ Zona este valida in Setari Primar, iar Km efectuati se completeaza automat.';
                if (primaryLocationNote) {
                    primaryLocationNote.textContent = selectedPairText;
                }
                if (primaryZoneNote) {
                    primaryZoneNote.textContent = selectedPairText;
                }
                syncFieldHoverHints(String(tipField ? (tipField.value || '') : ''));
                return;
            }

            var choosePairText = 'Selecteaza o combinatie Loc ↔ Zona disponibila in Setari Primar pentru acest beneficiar.';
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
                return;
            }

            var beneficiaryId = String(beneficiaryField ? (beneficiaryField.value || '') : '').trim();
            var locationId = String(loadLocationField ? (loadLocationField.value || '') : '').trim();
            var zoneId = String(zoneField ? (zoneField.value || '') : '').trim();
            var primaryRule = getPrimaryRouteRule(primaryScope, beneficiaryId, locationId, zoneId);
            if (!primaryRule) {
                if (kmField.hasAttribute('data-primary-km-autofilled')) {
                    kmField.value = '';
                    kmField.removeAttribute('data-primary-km-autofilled');
                }
                return;
            }

            var kmTariffValue = Math.max(0, Math.round(parseNumber(primaryRule.kmTariff)));
            kmField.value = String(kmTariffValue);
            kmField.setAttribute('data-primary-km-autofilled', '1');
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
            var routeRule = getDistributionRouteRule(beneficiaryId, locationId, zoneId, vehicleId);
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
                var routeScope = getVehicleScopedRouteRules(beneficiaryId, vehicleId);
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
                if (!supportsDistribution) {
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

        function getDistributionRouteRule(beneficiaryId, locationId, zoneId, vehicleId) {
            var beneficiaryKey = String(beneficiaryId || '').trim();
            var locationKey = String(locationId || '');
            var zoneKey = String(zoneId || '');
            if (locationKey === '' || zoneKey === '') {
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

            var beneficiaryScopedRules = getDistributionRulesForBeneficiary(beneficiaryKey);

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

        function syncKmDistributionCalculationNote(transportType, kmAgreatiValue, kmEfectuatiValue) {
            if (!(kmDistributionCalculationNote instanceof HTMLElement)) {
                return;
            }

            var isMixedTransport = String(transportType || '').trim() === 'primar_distributie';
            kmDistributionCalculationNote.classList.toggle('d-none', !isMixedTransport);
            if (!isMixedTransport) {
                return;
            }

            var kmAgreati = Math.max(0, parseNumber(kmAgreatiValue));
            kmDistributionCalculationNote.textContent =
                'Cost/km Distributie (calcul): (Cantitate × Tarif tona) / Km agreati'
                + (kmAgreati > 0 ? (' (' + formatKmValueRo(kmAgreati) + ' km)') : '');
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
            var showCostDistributie = false;
            var showCostMixt = false;

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
                selectedVehicleId
            );
            var total = 0;
            var effectiveDistributionKmRate = 0;

            if (isPrimaryKmTransport(transportType)) {
                total = kmValue * rates.perKm;
            } else if (isPrimaryTonTransport(transportType)) {
                total = quantityValue * rates.perTon;
            } else if (isDistributionTransport(transportType)) {
                if (!hasCompleteDistributionSelection) {
                    total = 0;
                    effectiveDistributionKmRate = 0;
                } else {
                    var sameRoute = isSameDistributionRoute();
                    var effectiveRouteRule = routeRule;
                    var effectiveTonRate = effectiveRouteRule && effectiveRouteRule.active && effectiveRouteRule.tariffPerTon > 0
                        ? effectiveRouteRule.tariffPerTon
                        : resolveDistributionTonRate(locationTariff, zoneTariff, rates.perTon, sameRoute);
                    var effectiveKmRate = effectiveRouteRule && effectiveRouteRule.active && effectiveRouteRule.extraKmCost > 0
                        ? effectiveRouteRule.extraKmCost
                        : (zoneExtraKmCost > 0 ? zoneExtraKmCost : rates.perKm);
                    var fixedRideCost = effectiveRouteRule && effectiveRouteRule.active && effectiveRouteRule.applyRideCost && effectiveRouteRule.rideCost > 0
                        ? effectiveRouteRule.rideCost
                        : 0;
                    effectiveDistributionKmRate = Math.max(0, effectiveKmRate);
                    var distributionKmComponent = isDistributionWithKmTransport(transportType) && fixedRideCost <= 0
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
                distributionTonRate = effectiveRouteRuleForCost && effectiveRouteRuleForCost.active && effectiveRouteRuleForCost.tariffPerTon > 0
                    ? effectiveRouteRuleForCost.tariffPerTon
                    : resolveDistributionTonRate(locationTariff, zoneTariff, rates.perTon, sameRouteForCost);
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

            var totalPrimar = includesPrimarySegment ? (kmPrimar * primaryPerKmRate) : 0;
            var totalDistributie = includesDistributionSegment
                ? (distributionFixedRideCost > 0 ? distributionFixedRideCost : (Math.max(0, quantityValue) * distributionTonRate))
                : 0;
            var costKmPrimar = includesPrimarySegment && kmPrimar > 0
                ? safeDivide(totalPrimar, kmPrimar)
                : 0;
            var costKmDistributie = 0;
            if (transportType === 'primar_distributie') {
                // Regula stabilita: Cost/km Distributie foloseste Km agreati.
                costKmDistributie = kmPrimar > 0
                    ? safeDivide(totalDistributie, kmPrimar)
                    : 0;
            } else if (includesDistributionSegment && kmDistributie > 0) {
                costKmDistributie = safeDivide(totalDistributie, kmDistributie);
            }
            var costKmMixt = 0;
            if (transportType === 'primar_distributie') {
                // Regula stabilita: Cost/km Mixt foloseste Km agreati.
                costKmMixt = kmPrimar > 0
                    ? safeDivide(total, kmPrimar)
                    : 0;
            } else if (includesPrimarySegment && !includesDistributionSegment) {
                costKmMixt = costKmPrimar;
            } else if (!includesPrimarySegment && includesDistributionSegment) {
                costKmMixt = costKmDistributie;
            }

            costKmPrimar = roundToTwo(costKmPrimar);
            costKmDistributie = roundToTwo(costKmDistributie);
            costKmMixt = roundToTwo(costKmMixt);

            if (costKmPrimarPreview) {
                costKmPrimarPreview.textContent = formatCostPerKmRo(costKmPrimar);
            }
            if (costKmDistributiePreview) {
                costKmDistributiePreview.textContent = formatCostPerKmRo(costKmDistributie);
            }
            if (costKmMixtPreview) {
                costKmMixtPreview.textContent = formatCostPerKmRo(costKmMixt);
            }

            if (priceDisplayField) {
                if (isPrimaryKmTransport(transportType)) {
                    priceDisplayField.value = (
                        'mod calcul: km' +
                        ' | km: ' + rates.perKm.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                    );
                } else if (isPrimaryTonTransport(transportType)) {
                    priceDisplayField.value = (
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
                        var effectiveDisplayTonRate = hasActiveRouteRule && effectiveDisplayRule.tariffPerTon > 0
                            ? effectiveDisplayRule.tariffPerTon
                            : resolveDistributionTonRate(locationTariff, zoneTariff, rates.perTon, sameRouteForDisplay);
                        var effectiveDisplayKmRate = hasActiveRouteRule && effectiveDisplayRule.extraKmCost > 0
                            ? effectiveDisplayRule.extraKmCost
                            : (zoneExtraKmCost > 0 ? zoneExtraKmCost : rates.perKm);
                        var displayFixedRideCost = hasActiveRouteRule && effectiveDisplayRule.applyRideCost && effectiveDisplayRule.rideCost > 0
                            ? effectiveDisplayRule.rideCost
                            : 0;
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
                                : (isDistributionWithKmTransport(transportType) ? 'tona + km' : 'doar tona'));
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

            totalPreview.textContent = formatCurrencyRo(total);
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
        });
        if (vehicleField) {
            vehicleField.addEventListener('change', function () {
                syncDriverOptionsByVehicle(false);
                syncVehicleTransportCapacity();
                syncScopedLocationZoneOptions();
                applyVehicleDefaultLoadLocation(false);
                syncScopedLocationZoneOptions();
                applyVehicleDefaultDistributionZone(false);
                recalculateTotal();
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

        form.addEventListener('submit', function () {
            normalizeTimeFieldValue(startTimeField);
            normalizeTimeFieldValue(endTimeField);
        });

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
