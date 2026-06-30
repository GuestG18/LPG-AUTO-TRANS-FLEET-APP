(function () {
    'use strict';

    function qs(root, selector) {
        return root ? root.querySelector(selector) : null;
    }

    function field(form, name) {
        return qs(form, '[data-field="' + name + '"]');
    }

    function setValue(form, name, value) {
        var el = field(form, name);
        if (!el) {
            return;
        }
        if (el instanceof HTMLInputElement && el.type === 'checkbox') {
            el.checked = Boolean(value);
            return;
        }
        el.value = value == null ? '' : String(value);
    }

    function parseNumber(value) {
        var normalized = String(value || '').trim().replace(',', '.');
        if (normalized === '') {
            return null;
        }
        var parsed = Number(normalized);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function formatMoney(value) {
        return (Math.round(value * 100) / 100).toFixed(2);
    }

    var monthNames = ['Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];
    var weekdayLabels = ['Du', 'Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sâ'];

    function pad2(value) {
        return String(value).padStart(2, '0');
    }

    function dateKey(year, month, day) {
        return String(year).padStart(4, '0') + '-' + pad2(month) + '-' + pad2(day);
    }

    function parseDateParts(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        if (!match) {
            return null;
        }
        return {
            year: Number(match[1]),
            month: Number(match[2]),
            day: Number(match[3])
        };
    }

    function formatDateDisplay(value) {
        var parts = parseDateParts(value);
        if (!parts) {
            return '';
        }
        return pad2(parts.day) + '.' + pad2(parts.month) + '.' + String(parts.year);
    }

    function syncDateDisplay(form) {
        var dateEl = field(form, 'data_alimentare');
        var displayEl = field(form, 'data_alimentare_display');
        if (displayEl instanceof HTMLInputElement) {
            displayEl.value = formatDateDisplay(dateEl instanceof HTMLInputElement ? dateEl.value : '');
        }
    }

    function calendarDefault() {
        var defaults = window.FUEL_CALENDAR_DEFAULT || {};
        var now = new Date();
        var year = Number(defaults.year) || now.getFullYear();
        var month = Number(defaults.month) || (now.getMonth() + 1);
        return { year: year, month: month };
    }

    function calendarMonthFromForm(form) {
        var dateEl = field(form, 'data_alimentare');
        if (dateEl instanceof HTMLInputElement) {
            var parts = parseDateParts(dateEl.value);
            if (parts) {
                return { year: parts.year, month: parts.month };
            }
        }

        return calendarDefault();
    }

    function rideCalendarState(form) {
        if (!form._fuelRideCalendarState) {
            var defaults = calendarDefault();
            form._fuelRideCalendarState = {
                year: defaults.year,
                month: defaults.month,
                trips: [],
                loading: false,
                error: false
            };
        }
        return form._fuelRideCalendarState;
    }

    function renderRideCalendarMessage(form, message, iconClass) {
        var calendar = qs(form, '[data-role="ride-calendar"]');
        if (!(calendar instanceof HTMLElement)) {
            return;
        }
        calendar.innerHTML = '<div class="fuel-calendar-empty"><i class="bi ' + escapeHtml(iconClass || 'bi-calendar-event') + '"></i> ' + escapeHtml(message) + '</div>';
    }

    function datePickerWrap(form) {
        return qs(form, '[data-role="fuel-date-picker"]');
    }

    function openRideCalendar(form) {
        var wrap = datePickerWrap(form);
        if (wrap instanceof HTMLElement) {
            wrap.classList.add('is-open');
        }
        renderRideCalendar(form);
    }

    function closeRideCalendar(form) {
        var wrap = datePickerWrap(form);
        if (wrap instanceof HTMLElement) {
            wrap.classList.remove('is-open');
        }
    }

    function tripMapByDate(trips) {
        var map = {};
        (trips || []).forEach(function (trip) {
            (trip.dates || []).forEach(function (date) {
                if (!map[date]) {
                    map[date] = [];
                }
                map[date].push(trip);
            });
        });
        return map;
    }

    function renderRideCalendar(form) {
        var calendar = qs(form, '[data-role="ride-calendar"]');
        if (!(calendar instanceof HTMLElement)) {
            return;
        }
        var vehicleEl = field(form, 'vehicle_id');
        if (!(vehicleEl instanceof HTMLSelectElement) || !vehicleEl.value) {
            renderRideCalendarMessage(form, 'Selectează vehiculul ca să vezi zilele cu curse din Dispecer.', 'bi-calendar-event');
            return;
        }

        var state = rideCalendarState(form);
        var selectedParts = parseDateParts(field(form, 'data_alimentare') ? field(form, 'data_alimentare').value : '');
        var selectedDate = selectedParts ? dateKey(selectedParts.year, selectedParts.month, selectedParts.day) : '';
        var byDate = tripMapByDate(state.trips);
        var days = new Date(state.year, state.month, 0).getDate();
        var firstWeekday = new Date(state.year, state.month - 1, 1).getDay();
        var monthLabel = (monthNames[state.month - 1] || String(state.month)) + ' ' + state.year;
        var html = [
            '<div class="fuel-calendar-head">',
            '<div><strong>Calendar curse Dispecer</strong><span>Click pe o zi albastră ca să setezi data alimentării.</span></div>',
            '<div class="fuel-calendar-nav">',
            '<button type="button" class="btn btn-sm btn-outline-secondary" data-calendar-nav="-1" aria-label="Luna anterioară"><i class="bi bi-chevron-left"></i></button>',
            '<span>' + escapeHtml(monthLabel) + '</span>',
            '<button type="button" class="btn btn-sm btn-outline-secondary" data-calendar-nav="1" aria-label="Luna următoare"><i class="bi bi-chevron-right"></i></button>',
            '</div>',
            '</div>'
        ];

        if (state.loading) {
            html.push('<div class="fuel-calendar-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Se încarcă intervalele...</div>');
        }

        html.push('<div class="fuel-calendar-grid">');
        weekdayLabels.forEach(function (label) {
            html.push('<div class="fuel-calendar-weekday">' + escapeHtml(label) + '</div>');
        });
        for (var blank = 0; blank < firstWeekday; blank += 1) {
            html.push('<div class="fuel-calendar-blank"></div>');
        }
        for (var day = 1; day <= days; day += 1) {
            var date = dateKey(state.year, state.month, day);
            var tripsForDay = byDate[date] || [];
            var primaryTrip = tripsForDay[0] || null;
            var classes = ['fuel-calendar-day'];
            if (primaryTrip) {
                classes.push('has-trip');
            }
            if (date === selectedDate) {
                classes.push('is-selected');
            }
            var title = primaryTrip
                ? primaryTrip.interval + ' · ' + (primaryTrip.tip_transport_label || '-') + ' · ' + (primaryTrip.beneficiar || '-')
                : 'Fără cursă Dispecer';
            html.push(
                '<button type="button" class="' + classes.join(' ') + '" data-calendar-date="' + escapeHtml(date) + '" title="' + escapeHtml(title) + '"' + (primaryTrip ? ' data-trip-id="' + escapeHtml(primaryTrip.id) + '"' : '') + '>' +
                '<span>' + day + '</span>' +
                (primaryTrip ? '<small>' + escapeHtml(primaryTrip.tip_transport_label || 'Cursă') + '</small>' : '') +
                '</button>'
            );
        }
        html.push('</div>');

        if (!state.loading && state.trips.length === 0) {
            html.push('<div class="fuel-calendar-note"><i class="bi bi-info-circle"></i> Nu sunt curse Dispecer pentru acest vehicul în luna afișată.</div>');
        } else {
            html.push('<div class="fuel-calendar-legend"><span class="fuel-calendar-dot"></span> Zile cu cursă / interval Dispecer</div>');
        }
        calendar.innerHTML = html.join('');

        calendar.querySelectorAll('[data-calendar-nav]').forEach(function (button) {
            button.addEventListener('click', function () {
                var direction = Number(button.getAttribute('data-calendar-nav') || 0);
                var month = state.month + direction;
                var year = state.year;
                if (month < 1) {
                    month = 12;
                    year -= 1;
                } else if (month > 12) {
                    month = 1;
                    year += 1;
                }
                loadRideCalendar(form, year, month);
            });
        });

        calendar.querySelectorAll('[data-calendar-date]').forEach(function (button) {
            button.addEventListener('click', function () {
                var pickedDate = button.getAttribute('data-calendar-date') || '';
                handleDatePicked(form, pickedDate);
            });
        });
    }

    function loadRideCalendar(form, year, month) {
        var vehicleEl = field(form, 'vehicle_id');
        if (!(vehicleEl instanceof HTMLSelectElement) || !vehicleEl.value) {
            renderRideCalendarMessage(form, 'Selectează vehiculul ca să vezi zilele cu curse din Dispecer.', 'bi-calendar-event');
            return;
        }
        var state = rideCalendarState(form);
        state.year = year;
        state.month = month;
        state.loading = true;
        state.error = false;
        renderRideCalendar(form);

        var url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('page', 'alimentari');
        url.searchParams.set('action', 'trips_calendar');
        url.searchParams.set('vehicle_id', vehicleEl.value);
        url.searchParams.set('year', String(year));
        url.searchParams.set('month', String(month));

        fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                state.trips = payload && Array.isArray(payload.trips) ? payload.trips : [];
                state.loading = false;
                state.error = false;
                renderRideCalendar(form);
            })
            .catch(function () {
                state.trips = [];
                state.loading = false;
                state.error = true;
                renderRideCalendarMessage(form, 'Nu s-au putut încărca zilele cu curse Dispecer.', 'bi-exclamation-triangle');
            });
    }

    function refreshRideCalendar(form) {
        var month = calendarMonthFromForm(form);
        loadRideCalendar(form, month.year, month.month);
    }

    function syncRideCalendarToDate(form) {
        var parts = parseDateParts(field(form, 'data_alimentare') ? field(form, 'data_alimentare').value : '');
        var state = rideCalendarState(form);
        if (parts && (parts.year !== state.year || parts.month !== state.month)) {
            loadRideCalendar(form, parts.year, parts.month);
            return;
        }
        renderRideCalendar(form);
    }

    function handleDatePicked(form, pickedDate) {
        setValue(form, 'data_alimentare', pickedDate);
        syncDateDisplay(form);
        renderRideCalendar(form);
        if (form.hasAttribute('data-fuel-t0-form')) {
            form._fuelT0KmTouched = false;
            syncT0MonthYear(form);
            suggestT0Km(form, true);
        } else {
            form._fuelRefuelKmTouched = false;
            detectTrip(form);
        }
        closeRideCalendar(form);
    }

    function setupFuelDatePicker(form) {
        var dateDisplay = field(form, 'data_alimentare_display');
        var dateToggle = qs(form, '[data-role="fuel-date-toggle"]');
        [dateDisplay, dateToggle].forEach(function (el) {
            if (el instanceof HTMLElement) {
                el.addEventListener('click', function (event) {
                    event.preventDefault();
                    refreshRideCalendar(form);
                    openRideCalendar(form);
                });
            }
        });
        if (dateDisplay instanceof HTMLInputElement) {
            dateDisplay.addEventListener('focus', function () {
                refreshRideCalendar(form);
                openRideCalendar(form);
            });
        }

        document.addEventListener('click', function (event) {
            var wrap = datePickerWrap(form);
            if (wrap instanceof HTMLElement && event.target instanceof Node && !wrap.contains(event.target)) {
                closeRideCalendar(form);
            }
        });
    }

    function resetForm(form) {
        if (!form) {
            return;
        }
        form.reset();
        setValue(form, 'id', '');
        setValue(form, 'cursa_id', '');
        form._fuelRefuelKmTouched = false;
        form._fuelT0KmTouched = false;
        form._fuelT0FuelAutoFilled = false;
        renderRefuelKmHint(form, '');
        renderT0KmHint(form, '');
        renderT0FuelHint(form, '');
        syncDateDisplay(form);
        closeRideCalendar(form);
        var invoice = field(form, 'current_invoice');
        if (invoice instanceof HTMLElement) {
            invoice.textContent = '';
        }
    }

    function renderTripCard(form, trip) {
        var card = qs(form, '[data-role="trip-card"]');
        if (!(card instanceof HTMLElement)) {
            return;
        }

        if (!trip || !trip.id) {
            setValue(form, 'cursa_id', '');
            card.innerHTML = '<div class="fuel-trip-warning"><i class="bi bi-exclamation-triangle"></i><strong>Fără cursă asociată</strong><span>Nu există interval în Dispecer pentru vehiculul și data selectate.</span></div>';
            return;
        }

        setValue(form, 'cursa_id', trip.id);
        card.innerHTML = [
            '<div class="fuel-trip-card-found">',
            '<div class="fuel-trip-card-head"><i class="bi bi-check-circle"></i><strong>Cursă Dispecer detectată</strong><button type="button" class="btn btn-sm btn-outline-secondary" data-role="clear-trip">Nu asocia</button></div>',
            '<div class="fuel-trip-details">',
            '<span><b>Interval:</b> ' + escapeHtml(trip.interval || '-') + '</span>',
            '<span><b>Tip:</b> ' + escapeHtml(trip.tip_transport_label || '-') + '</span>',
            '<span><b>Beneficiar:</b> ' + escapeHtml(trip.beneficiar || '-') + '</span>',
            '<span><b>Șofer:</b> ' + escapeHtml(trip.sofer || '-') + '</span>',
            '<span class="fuel-trip-route"><b>Traseu:</b> ' + escapeHtml(trip.traseu || '-') + '</span>',
            '</div></div>'
        ].join('');

        var clearBtn = qs(card, '[data-role="clear-trip"]');
        if (clearBtn instanceof HTMLButtonElement) {
            clearBtn.addEventListener('click', function () {
                setValue(form, 'cursa_id', '');
                suggestRefuelKm(form, false);
                card.innerHTML = '<div class="fuel-trip-warning"><i class="bi bi-exclamation-triangle"></i><strong>Fără cursă asociată</strong><span>Alimentarea va fi salvată fără cursă Dispecer.</span></div>';
            });
        }
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function detectTrip(form) {
        var vehicleEl = field(form, 'vehicle_id');
        var dateEl = field(form, 'data_alimentare');
        if (!(vehicleEl instanceof HTMLSelectElement) || !(dateEl instanceof HTMLInputElement)) {
            return;
        }
        var vehicleId = vehicleEl.value;
        var date = dateEl.value;
        if (!vehicleId || !date) {
            var card = qs(form, '[data-role="trip-card"]');
            if (card instanceof HTMLElement) {
                card.innerHTML = '<div class="fuel-trip-empty"><i class="bi bi-info-circle"></i> Selectează vehiculul și data alimentării pentru detectarea intervalului din Dispecer Curse.</div>';
            }
            setValue(form, 'cursa_id', '');
            renderRefuelKmHint(form, '');
            return;
        }

        var url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('page', 'alimentari');
        url.searchParams.set('action', 'detect_trip');
        url.searchParams.set('vehicle_id', vehicleId);
        url.searchParams.set('date', date);

        fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                renderTripCard(form, payload && payload.trip ? payload.trip : null);
                suggestRefuelKm(form, false);
            })
            .catch(function () {
                renderTripCard(form, null);
                suggestRefuelKm(form, false);
            });
    }

    function renderRefuelKmHint(form, message, tone) {
        var hint = qs(form, '[data-role="refuel-km-hint"]');
        if (!(hint instanceof HTMLElement)) {
            return;
        }
        hint.className = 'form-text fuel-refuel-km-hint' + (tone ? ' is-' + tone : '');
        hint.textContent = message || '';
    }

    function suggestRefuelKm(form, force) {
        if (!form || !form.hasAttribute('data-fuel-refuel-form')) {
            return;
        }
        var vehicleEl = field(form, 'vehicle_id');
        var dateEl = field(form, 'data_alimentare');
        var kmEl = field(form, 'km_bord');
        var idEl = field(form, 'id');
        if (!(vehicleEl instanceof HTMLSelectElement) || !(dateEl instanceof HTMLInputElement) || !(kmEl instanceof HTMLInputElement)) {
            return;
        }
        if (idEl instanceof HTMLInputElement && idEl.value) {
            return;
        }
        if (!vehicleEl.value || !dateEl.value) {
            renderRefuelKmHint(form, '');
            return;
        }

        renderRefuelKmHint(form, 'Se calculeazÄƒ Km Bord din Vehicule È™i cursele Dispecer...', 'muted');

        var tripIdEl = field(form, 'cursa_id');
        var url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('page', 'alimentari');
        url.searchParams.set('action', 'refuel_km_suggestion');
        url.searchParams.set('vehicle_id', vehicleEl.value);
        url.searchParams.set('date', dateEl.value);
        if (tripIdEl instanceof HTMLInputElement && tripIdEl.value) {
            url.searchParams.set('trip_id', tripIdEl.value);
        }

        fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                var suggestion = payload && payload.suggestion ? payload.suggestion : null;
                if (!suggestion) {
                    renderRefuelKmHint(form, 'Nu s-a gÄƒsit km pentru vehiculul selectat.', 'warning');
                    return;
                }

                var shouldFill = force || !form._fuelRefuelKmTouched || kmEl.value === '';
                if (shouldFill) {
                    kmEl.value = suggestion.suggested_km == null ? '' : String(suggestion.suggested_km);
                    form._fuelRefuelKmTouched = false;
                }

                var message = suggestion.later_km > 0
                    ? 'Km sugerat: ' + suggestion.current_km + ' - ' + suggestion.later_km + ' km din curse Dispecer ulterioare = ' + suggestion.suggested_km + '.'
                    : 'Km sugerat preluat direct din Vehicule: ' + suggestion.current_km + '.';
                if (suggestion.detected_trip && suggestion.detected_trip.interval) {
                    message += ' Cursa selectatÄƒ: ' + suggestion.detected_trip.interval;
                    if (Number(suggestion.detected_trip_km || 0) > 0) {
                        message += ', ' + suggestion.detected_trip_km + ' km incluÈ™i.';
                    } else {
                        message += '.';
                    }
                }
                renderRefuelKmHint(form, message, 'success');
            })
            .catch(function () {
                renderRefuelKmHint(form, 'Nu s-a putut calcula automat Km Bord.', 'warning');
            });
    }

    function syncTotal(form) {
        var liters = parseNumber(field(form, 'litri') ? field(form, 'litri').value : '');
        var price = parseNumber(field(form, 'pret_litru') ? field(form, 'pret_litru').value : '');
        var total = field(form, 'total_cost_preview');
        if (!(total instanceof HTMLInputElement)) {
            return;
        }
        total.value = liters !== null && price !== null && liters > 0 && price > 0 ? formatMoney(liters * price) : '';
    }

    function fillRefuelForm(form, record) {
        resetForm(form);
        setValue(form, 'id', record.id || '');
        setValue(form, 'vehicle_id', record.vehicle_id || '');
        setValue(form, 'data_alimentare', record.data_alimentare || '');
        syncDateDisplay(form);
        setValue(form, 'km_bord', record.km_bord || '');
        form._fuelRefuelKmTouched = true;
        renderRefuelKmHint(form, '');
        setValue(form, 'litri', record.litri || '');
        setValue(form, 'pret_litru', record.pret_litru || '');
        setValue(form, 'furnizor', record.furnizor || '');
        setValue(form, 'observatii', record.observatii || '');
        setValue(form, 'cursa_id', record.cursa_id || '');
        var invoice = field(form, 'current_invoice');
        if (invoice instanceof HTMLElement && record.factura_original) {
            invoice.textContent = 'Factura curentă: ' + record.factura_original;
        }
        syncTotal(form);
        refreshRideCalendar(form);
        if (record.trip && record.trip.id) {
            renderTripCard(form, record.trip);
        } else {
            detectTrip(form);
        }
    }

    function fillT0Form(form, record) {
        resetForm(form);
        setValue(form, 'id', record.id || '');
        setValue(form, 'vehicle_id', record.vehicle_id || '');
        setValue(form, 'data_alimentare', record.data_alimentare || '');
        syncDateDisplay(form);
        setValue(form, 'km_bord', record.km_bord || '');
        setValue(form, 'fuel_state', record.fuel_state || '');
        setValue(form, 'full_flag', Boolean(record.full_flag));
        setValue(form, 'observatii', record.observatii || '');
        syncT0MonthYear(form);
        syncT0FullState(form, false);
        form._fuelT0KmTouched = true;
        renderT0KmHint(form, '');
    }

    function syncT0MonthYear(form) {
        var dateEl = field(form, 'data_alimentare');
        var monthEl = field(form, 't0_month_preview');
        var yearEl = field(form, 't0_year_preview');
        if (!(dateEl instanceof HTMLInputElement)) {
            return;
        }
        var value = dateEl.value || '';
        if (!value || value.length < 7) {
            if (monthEl instanceof HTMLInputElement) {
                monthEl.value = '';
            }
            if (yearEl instanceof HTMLInputElement) {
                yearEl.value = '';
            }
            return;
        }
        var parts = value.split('-');
        var monthNames = ['Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];
        var monthIndex = Number(parts[1]) - 1;
        if (monthEl instanceof HTMLInputElement) {
            monthEl.value = monthNames[monthIndex] || '';
        }
        if (yearEl instanceof HTMLInputElement) {
            yearEl.value = parts[0] || '';
        }
    }

    function renderT0KmHint(form, message, tone) {
        var hint = qs(form, '[data-role="t0-km-hint"]');
        if (!(hint instanceof HTMLElement)) {
            return;
        }
        hint.className = 'form-text fuel-t0-km-hint' + (tone ? ' is-' + tone : '');
        hint.textContent = message || '';
    }

    function renderT0FuelHint(form, message, tone) {
        var hint = qs(form, '[data-role="t0-fuel-hint"]');
        if (!(hint instanceof HTMLElement)) {
            return;
        }
        hint.className = 'form-text fuel-t0-fuel-hint' + (tone ? ' is-' + tone : '');
        hint.textContent = message || '';
    }

    function selectedVehicleReservoirCapacity(form) {
        var vehicleEl = field(form, 'vehicle_id');
        if (!(vehicleEl instanceof HTMLSelectElement) || vehicleEl.selectedIndex < 0) {
            return null;
        }
        var option = vehicleEl.options[vehicleEl.selectedIndex];
        var raw = option ? option.getAttribute('data-reservoir-capacity') : '';
        var value = parseNumber(raw);
        return value !== null && value > 0 ? value : null;
    }

    function syncT0FullState(form, forceFill) {
        var fullEl = field(form, 'full_flag');
        var fuelEl = field(form, 'fuel_state');
        if (!(fullEl instanceof HTMLInputElement) || !(fuelEl instanceof HTMLInputElement)) {
            return;
        }

        if (fullEl.checked) {
            var capacity = selectedVehicleReservoirCapacity(form);
            if (capacity !== null) {
                fuelEl.value = formatMoney(capacity);
                fuelEl.readOnly = true;
                form._fuelT0FuelAutoFilled = true;
                renderT0FuelHint(form, 'FULL: stare combustibil setată la capacitatea rezervorului (' + formatMoney(capacity) + ' L).', 'success');
            } else {
                fuelEl.readOnly = false;
                if (forceFill) {
                    fuelEl.value = '';
                }
                form._fuelT0FuelAutoFilled = false;
                renderT0FuelHint(form, 'Completează Capacitate rezervor în Detalii Vehicul pentru a folosi FULL automat.', 'warning');
            }
            return;
        }

        fuelEl.readOnly = false;
        if (form._fuelT0FuelAutoFilled) {
            fuelEl.value = '';
        }
        form._fuelT0FuelAutoFilled = false;
        renderT0FuelHint(form, '');
    }

    function suggestT0Km(form, force) {
        var vehicleEl = field(form, 'vehicle_id');
        var dateEl = field(form, 'data_alimentare');
        var kmEl = field(form, 'km_bord');
        var idEl = field(form, 'id');
        if (!(vehicleEl instanceof HTMLSelectElement) || !(dateEl instanceof HTMLInputElement) || !(kmEl instanceof HTMLInputElement)) {
            return;
        }
        if (idEl instanceof HTMLInputElement && idEl.value) {
            return;
        }
        if (!vehicleEl.value || !dateEl.value) {
            renderT0KmHint(form, '');
            return;
        }

        renderT0KmHint(form, 'Se calculează Km Bord T0...', 'muted');

        var url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('page', 'alimentari');
        url.searchParams.set('action', 't0_km_suggestion');
        url.searchParams.set('vehicle_id', vehicleEl.value);
        url.searchParams.set('date', dateEl.value);

        fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                var suggestion = payload && payload.suggestion ? payload.suggestion : null;
                if (!suggestion) {
                    renderT0KmHint(form, 'Nu s-a găsit km pentru vehiculul selectat.', 'warning');
                    return;
                }

                var shouldFill = force || !form._fuelT0KmTouched || kmEl.value === '';
                if (shouldFill) {
                    kmEl.value = suggestion.suggested_km == null ? '' : String(suggestion.suggested_km);
                    form._fuelT0KmTouched = false;
                }

                var message = suggestion.later_km > 0
                    ? 'Km calculat din Vehicule: ' + suggestion.current_km + ' - ' + suggestion.later_km + ' km din curse Dispecer ulterioare/în derulare.'
                    : 'Km preluat direct din Vehicule: ' + suggestion.current_km + '.';
                if (suggestion.first_month_trip && suggestion.first_month_trip.interval) {
                    message += ' Prima cursă relevantă: ' + suggestion.first_month_trip.interval + '.';
                }
                renderT0KmHint(form, message, 'success');
            })
            .catch(function () {
                renderT0KmHint(form, 'Nu s-a putut calcula automat Km Bord T0.', 'warning');
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var refuelForm = document.querySelector('[data-fuel-refuel-form]');
        var t0Form = document.querySelector('[data-fuel-t0-form]');
        var refuelModalEl = document.getElementById('fuelRefuelModal');
        var t0ModalEl = document.getElementById('fuelT0Modal');

        if (refuelForm instanceof HTMLFormElement) {
            ['litri', 'pret_litru'].forEach(function (name) {
                var el = field(refuelForm, name);
                if (el instanceof HTMLInputElement) {
                    el.addEventListener('input', function () { syncTotal(refuelForm); });
                }
            });
            var vehicleEl = field(refuelForm, 'vehicle_id');
            if (vehicleEl instanceof HTMLSelectElement) {
                vehicleEl.addEventListener('change', function () {
                    refuelForm._fuelRefuelKmTouched = false;
                    refreshRideCalendar(refuelForm);
                    detectTrip(refuelForm);
                });
            }
            var refuelKm = field(refuelForm, 'km_bord');
            if (refuelKm instanceof HTMLInputElement) {
                refuelKm.addEventListener('input', function () {
                    refuelForm._fuelRefuelKmTouched = true;
                });
            }
            setupFuelDatePicker(refuelForm);
        }

        if (refuelModalEl) {
            refuelModalEl.addEventListener('show.bs.modal', function (event) {
                if (event.relatedTarget && event.relatedTarget.hasAttribute('data-fuel-edit')) {
                    return;
                }
                resetForm(refuelForm);
                refreshRideCalendar(refuelForm);
                var title = refuelModalEl.querySelector('.modal-title');
                if (title) {
                    title.textContent = 'Adaugă Alimentare';
                }
                detectTrip(refuelForm);
            });
        }

        if (t0ModalEl) {
            t0ModalEl.addEventListener('show.bs.modal', function (event) {
                if (event.relatedTarget && event.relatedTarget.hasAttribute('data-fuel-edit')) {
                    return;
                }
                resetForm(t0Form);
                var title = t0ModalEl.querySelector('.modal-title');
                if (title) {
                    title.textContent = 'Adaugă T0 manual';
                }
                syncT0MonthYear(t0Form);
            });
        }

        if (t0Form instanceof HTMLFormElement) {
            setupFuelDatePicker(t0Form);
            var t0Vehicle = field(t0Form, 'vehicle_id');
            if (t0Vehicle instanceof HTMLSelectElement) {
                t0Vehicle.addEventListener('change', function () {
                    t0Form._fuelT0KmTouched = false;
                    refreshRideCalendar(t0Form);
                    suggestT0Km(t0Form, true);
                    syncT0FullState(t0Form, true);
                });
            }
            var t0Km = field(t0Form, 'km_bord');
            if (t0Km instanceof HTMLInputElement) {
                t0Km.addEventListener('input', function () {
                    t0Form._fuelT0KmTouched = true;
                });
            }
            var t0Full = field(t0Form, 'full_flag');
            if (t0Full instanceof HTMLInputElement) {
                t0Full.addEventListener('change', function () {
                    syncT0FullState(t0Form, true);
                });
            }
            var t0Fuel = field(t0Form, 'fuel_state');
            if (t0Fuel instanceof HTMLInputElement) {
                t0Fuel.addEventListener('input', function () {
                    if (!t0Fuel.readOnly) {
                        t0Form._fuelT0FuelAutoFilled = false;
                    }
                });
            }
        }

        document.querySelectorAll('[data-fuel-edit]').forEach(function (button) {
            button.addEventListener('click', function () {
                var raw = button.getAttribute('data-record') || '{}';
                var record = {};
                try {
                    record = JSON.parse(raw);
                } catch (error) {
                    record = {};
                }
                if (record.tip_inregistrare === 't0') {
                    fillT0Form(t0Form, record);
                    var t0Title = t0ModalEl ? t0ModalEl.querySelector('.modal-title') : null;
                    if (t0Title) {
                        t0Title.textContent = 'Editează T0';
                    }
                    bootstrap.Modal.getOrCreateInstance(t0ModalEl).show(button);
                } else {
                    fillRefuelForm(refuelForm, record);
                    var refuelTitle = refuelModalEl ? refuelModalEl.querySelector('.modal-title') : null;
                    if (refuelTitle) {
                        refuelTitle.textContent = 'Editează Alimentare';
                    }
                    bootstrap.Modal.getOrCreateInstance(refuelModalEl).show(button);
                }
            });
        });

        if (window.FUEL_EDIT_RECORD) {
            if (window.FUEL_EDIT_RECORD.tip_inregistrare === 't0' && t0ModalEl) {
                fillT0Form(t0Form, window.FUEL_EDIT_RECORD);
                bootstrap.Modal.getOrCreateInstance(t0ModalEl).show();
            } else if (refuelModalEl) {
                fillRefuelForm(refuelForm, window.FUEL_EDIT_RECORD);
                bootstrap.Modal.getOrCreateInstance(refuelModalEl).show();
            }
        }
    });
})();
