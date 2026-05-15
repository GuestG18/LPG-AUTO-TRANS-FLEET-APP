(function () {
    var WEEKDAY_LABELS = ['Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sâ', 'Du'];
    var MONTH_LABELS = [
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

    function safeParseJson(rawValue, fallbackValue) {
        if (typeof rawValue !== 'string' || rawValue.trim() === '') {
            return fallbackValue;
        }

        try {
            var parsed = JSON.parse(rawValue);
            return parsed !== null && parsed !== undefined ? parsed : fallbackValue;
        } catch (error) {
            return fallbackValue;
        }
    }

    function toDate(dateValue) {
        if (typeof dateValue !== 'string' || dateValue.trim() === '') {
            return null;
        }

        var normalized = dateValue.trim();
        var created = new Date(normalized + 'T00:00:00');
        if (Number.isNaN(created.getTime())) {
            return null;
        }

        return created;
    }

    function cloneDate(date) {
        return new Date(date.getFullYear(), date.getMonth(), date.getDate());
    }

    function formatIsoDate(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function formatRoDate(dateValue) {
        var date = toDate(dateValue);
        if (!date) {
            return '-';
        }

        return String(date.getDate()).padStart(2, '0') + '.'
            + String(date.getMonth() + 1).padStart(2, '0') + '.'
            + date.getFullYear();
    }

    function formatMonthLabel(date) {
        return MONTH_LABELS[date.getMonth()] + ' ' + date.getFullYear();
    }

    function getMondayBasedDayIndex(date) {
        return (date.getDay() + 6) % 7;
    }

    function startOfWeek(date) {
        var d = cloneDate(date);
        d.setDate(d.getDate() - getMondayBasedDayIndex(d));
        return d;
    }

    function endOfWeek(date) {
        var d = startOfWeek(date);
        d.setDate(d.getDate() + 6);
        return d;
    }

    function areSameDay(a, b) {
        return a.getFullYear() === b.getFullYear()
            && a.getMonth() === b.getMonth()
            && a.getDate() === b.getDate();
    }

    function diffDays(startDate, endDate) {
        var msInDay = 24 * 60 * 60 * 1000;
        var start = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate());
        var end = new Date(endDate.getFullYear(), endDate.getMonth(), endDate.getDate());
        return Math.round((end.getTime() - start.getTime()) / msInDay);
    }

    function normalizeEvents(eventsRaw) {
        if (!Array.isArray(eventsRaw)) {
            return [];
        }

        var normalized = [];
        eventsRaw.forEach(function (row) {
            var start = toDate(String(row.data_inceput || ''));
            var end = toDate(String(row.data_sfarsit || ''));
            if (!start || !end || end < start) {
                return;
            }

            normalized.push({
                id: Number(row.id || 0),
                driverId: Number(row.driver_id || 0),
                driverName: String(row.sofer_nume || 'Șofer'),
                driverPlate: String(row.sofer_nr_inmatriculare || ''),
                start: start,
                end: end,
                leaveType: String(row.tip_concediu || ''),
                replacementName: String(row.inlocuitor_nume || ''),
                replacementPlate: String(row.inlocuitor_nr_inmatriculare || ''),
                status: String(row.status || 'in_asteptare'),
                note: String(row.note || '')
            });
        });

        return normalized;
    }

    function intersectsRange(eventStart, eventEnd, rangeStart, rangeEnd) {
        return !(eventEnd < rangeStart || eventStart > rangeEnd);
    }

    function clampDate(date, minDate, maxDate) {
        if (date < minDate) {
            return cloneDate(minDate);
        }
        if (date > maxDate) {
            return cloneDate(maxDate);
        }
        return cloneDate(date);
    }

    function leaveTypeLabel(leaveType) {
        var map = {
            odihna: 'Concediu de odihnă',
            personal: 'Concediu personal',
            medical: 'Concediu medical',
            fara_plata: 'Concediu fără plată'
        };
        return map[leaveType] || leaveType || '-';
    }

    function statusLabel(status) {
        var map = {
            aprobat: 'Aprobat',
            respins: 'Respins',
            in_asteptare: 'În așteptare',
            in_asteptare_aprobare: 'În așteptare aprobare'
        };
        return map[status] || status || '-';
    }

    function eventTypeClass(leaveType) {
        if (leaveType === 'personal') {
            return 'leave-type-personal';
        }
        if (leaveType === 'medical') {
            return 'leave-type-medical';
        }
        if (leaveType === 'fara_plata') {
            return 'leave-type-fara_plata';
        }
        return 'leave-type-odihna';
    }

    function eventStatusClass(status) {
        if (status === 'respins') {
            return 'leave-event-respins';
        }
        if (status === 'aprobat') {
            return 'leave-event-aprobat';
        }
        if (status === 'in_asteptare_aprobare') {
            return 'leave-event-asteptare-aprobare';
        }
        return 'leave-event-asteptare';
    }

    function createWeekdayHeader() {
        var header = document.createElement('div');
        header.className = 'leave-calendar-weekdays';

        WEEKDAY_LABELS.forEach(function (label) {
            var day = document.createElement('div');
            day.className = 'leave-weekday';
            day.textContent = label;
            header.appendChild(day);
        });

        return header;
    }

    function createEventButton(eventData, startCol, span, lane, weekStart, weekEnd) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'leave-calendar-event '
            + eventTypeClass(eventData.leaveType) + ' '
            + eventStatusClass(eventData.status);
        button.style.left = 'calc((' + startCol + ' * (100% / 7)) + 6px)';
        button.style.width = 'calc((' + span + ' * (100% / 7)) - 12px)';
        button.style.top = (lane * 24 + 38) + 'px';
        button.textContent = eventData.driverName;
        button.setAttribute('data-bs-toggle', 'tooltip');

        var displayStart = clampDate(eventData.start, weekStart, weekEnd);
        var displayEnd = clampDate(eventData.end, weekStart, weekEnd);
        var tooltipText = eventData.driverName
            + ' · ' + leaveTypeLabel(eventData.leaveType)
            + ' · ' + formatRoDate(formatIsoDate(displayStart))
            + ' - ' + formatRoDate(formatIsoDate(displayEnd))
            + ' · ' + statusLabel(eventData.status);
        button.setAttribute('title', tooltipText);

        button.dataset.requestId = String(eventData.id || 0);
        button.dataset.driver = eventData.driverName || '-';
        button.dataset.driverPlate = eventData.driverPlate || '-';
        button.dataset.period = formatRoDate(formatIsoDate(eventData.start))
            + ' - ' + formatRoDate(formatIsoDate(eventData.end));
        button.dataset.tip = leaveTypeLabel(eventData.leaveType);
        button.dataset.status = statusLabel(eventData.status);

        var replacementText = '-';
        if (eventData.replacementName) {
            replacementText = eventData.replacementName;
            if (eventData.replacementPlate) {
                replacementText += ' - ' + eventData.replacementPlate;
            }
        }
        button.dataset.replacement = replacementText;
        button.dataset.note = eventData.note && eventData.note.trim() !== '' ? eventData.note : '-';

        return button;
    }

    function renderWeek(container, weekStart, focusMonthDate, events, today, options) {
        var weekEnd = cloneDate(weekStart);
        weekEnd.setDate(weekEnd.getDate() + 6);

        var row = document.createElement('div');
        row.className = 'leave-calendar-week';

        var daysGrid = document.createElement('div');
        daysGrid.className = 'leave-week-days';

        for (var i = 0; i < 7; i += 1) {
            var dayDate = cloneDate(weekStart);
            dayDate.setDate(dayDate.getDate() + i);

            var dayCell = document.createElement('div');
            dayCell.className = 'leave-day-cell';
            if (dayDate.getMonth() !== focusMonthDate.getMonth()) {
                dayCell.classList.add('is-outside-month');
            }
            if (areSameDay(dayDate, today)) {
                dayCell.classList.add('is-today');
            }
            if (i >= 5) {
                dayCell.classList.add('is-weekend');
            }

            var dayNumber = document.createElement('span');
            dayNumber.className = 'leave-day-number';
            dayNumber.textContent = String(dayDate.getDate());
            dayCell.appendChild(dayNumber);
            daysGrid.appendChild(dayCell);
        }

        row.appendChild(daysGrid);

        var overlappedEvents = events
            .filter(function (eventData) {
                return intersectsRange(eventData.start, eventData.end, weekStart, weekEnd);
            })
            .sort(function (a, b) {
                if (a.start.getTime() !== b.start.getTime()) {
                    return a.start.getTime() - b.start.getTime();
                }
                return b.end.getTime() - a.end.getTime();
            });

        var lanes = [];
        var barsContainer = document.createElement('div');
        barsContainer.className = 'leave-week-bars';

        overlappedEvents.forEach(function (eventData) {
            var segmentStart = clampDate(eventData.start, weekStart, weekEnd);
            var segmentEnd = clampDate(eventData.end, weekStart, weekEnd);
            var startCol = Math.max(0, Math.min(6, diffDays(weekStart, segmentStart)));
            var endCol = Math.max(0, Math.min(6, diffDays(weekStart, segmentEnd)));
            var span = Math.max(1, endCol - startCol + 1);

            var laneIndex = 0;
            while (lanes[laneIndex] !== undefined && startCol <= lanes[laneIndex]) {
                laneIndex += 1;
            }
            lanes[laneIndex] = endCol;

            barsContainer.appendChild(
                createEventButton(eventData, startCol, span, laneIndex, weekStart, weekEnd)
            );
        });

        var laneCount = Math.max(lanes.length, 0);
        row.style.setProperty('--leave-event-lanes', String(laneCount));
        row.appendChild(barsContainer);

        if (options && options.weekOnly === true && laneCount === 0) {
            row.classList.add('leave-week-empty');
        }

        container.appendChild(row);
    }

    function renderMonthView(root, focusDate, events) {
        var monthStart = new Date(focusDate.getFullYear(), focusDate.getMonth(), 1);
        var gridStart = startOfWeek(monthStart);
        var today = new Date();

        root.innerHTML = '';
        root.appendChild(createWeekdayHeader());

        for (var weekIndex = 0; weekIndex < 6; weekIndex += 1) {
            var weekStart = cloneDate(gridStart);
            weekStart.setDate(gridStart.getDate() + (weekIndex * 7));
            renderWeek(root, weekStart, monthStart, events, today, null);
        }
    }

    function renderWeekView(root, focusDate, events) {
        var currentWeekStart = startOfWeek(focusDate);
        var monthDate = new Date(focusDate.getFullYear(), focusDate.getMonth(), 1);
        var today = new Date();

        root.innerHTML = '';
        root.appendChild(createWeekdayHeader());
        renderWeek(root, currentWeekStart, monthDate, events, today, { weekOnly: true });
    }

    function renderDayView(root, focusDate, events) {
        var isoFocus = formatIsoDate(focusDate);
        var dayEvents = events.filter(function (eventData) {
            return isoFocus >= formatIsoDate(eventData.start) && isoFocus <= formatIsoDate(eventData.end);
        });

        root.innerHTML = '';

        var dayWrap = document.createElement('div');
        dayWrap.className = 'leave-day-view';

        var heading = document.createElement('div');
        heading.className = 'leave-day-view-header';
        heading.textContent = formatRoDate(isoFocus) + ' · ' + WEEKDAY_LABELS[getMondayBasedDayIndex(focusDate)];
        dayWrap.appendChild(heading);

        if (dayEvents.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'leave-day-view-empty';
            empty.textContent = 'Nu există concedii pentru ziua selectată.';
            dayWrap.appendChild(empty);
            root.appendChild(dayWrap);
            return;
        }

        var list = document.createElement('div');
        list.className = 'leave-day-view-list';
        dayEvents.forEach(function (eventData) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'leave-day-event-item '
                + eventTypeClass(eventData.leaveType) + ' '
                + eventStatusClass(eventData.status);
            item.dataset.requestId = String(eventData.id || 0);
            item.dataset.driver = eventData.driverName || '-';
            item.dataset.driverPlate = eventData.driverPlate || '-';
            item.dataset.period = formatRoDate(formatIsoDate(eventData.start))
                + ' - ' + formatRoDate(formatIsoDate(eventData.end));
            item.dataset.tip = leaveTypeLabel(eventData.leaveType);
            item.dataset.status = statusLabel(eventData.status);
            item.dataset.replacement = eventData.replacementName || '-';
            item.dataset.note = eventData.note && eventData.note.trim() !== '' ? eventData.note : '-';
            item.innerHTML = ''
                + '<span class="leave-day-event-title">' + eventData.driverName + '</span>'
                + '<span class="leave-day-event-meta">' + leaveTypeLabel(eventData.leaveType) + ' · ' + statusLabel(eventData.status) + '</span>';
            list.appendChild(item);
        });

        dayWrap.appendChild(list);
        root.appendChild(dayWrap);
    }

    function initializeTooltips(scopeElement) {
        if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) {
            return;
        }

        var tooltipTriggers = scopeElement.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggers.forEach(function (tooltipTrigger) {
            bootstrap.Tooltip.getOrCreateInstance(tooltipTrigger);
        });
    }

    function openDetailsModal(detailsSource) {
        var modalElement = document.getElementById('leaveDetailsModal');
        if (!modalElement || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            return;
        }

        var mapping = {
            driver: '[data-role="detail-driver"]',
            driverPlate: '[data-role="detail-driver-plate"]',
            period: '[data-role="detail-period"]',
            tip: '[data-role="detail-tip"]',
            status: '[data-role="detail-status"]',
            replacement: '[data-role="detail-replacement"]',
            note: '[data-role="detail-note"]'
        };

        Object.keys(mapping).forEach(function (key) {
            var target = modalElement.querySelector(mapping[key]);
            if (!target) {
                return;
            }

            var value = detailsSource[key];
            target.textContent = typeof value === 'string' && value.trim() !== '' ? value : '-';
        });

        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }

    function readDetailsFromDataset(dataset) {
        return {
            driver: dataset.driver || '-',
            driverPlate: dataset.driverPlate || '-',
            period: dataset.period || '-',
            tip: dataset.tip || '-',
            status: dataset.status || '-',
            replacement: dataset.replacement || '-',
            note: dataset.note || '-'
        };
    }

    function showToast(message, type) {
        var toastElement = document.getElementById('leaveActionToast');
        if (!toastElement || typeof bootstrap === 'undefined' || !bootstrap.Toast) {
            return;
        }

        var toastMessage = toastElement.querySelector('[data-role="toast-message"]');
        if (!toastMessage) {
            return;
        }

        toastMessage.textContent = message;
        toastElement.classList.remove(
            'text-bg-success',
            'text-bg-danger',
            'text-bg-warning',
            'text-bg-info'
        );

        var className = 'text-bg-info';
        if (type === 'success') {
            className = 'text-bg-success';
        } else if (type === 'danger') {
            className = 'text-bg-danger';
        } else if (type === 'warning') {
            className = 'text-bg-warning';
        }
        toastElement.classList.add(className);

        bootstrap.Toast.getOrCreateInstance(toastElement, {
            delay: 4500
        }).show();
    }

    function initializeSelectSearch() {
        var searchInputs = document.querySelectorAll('[data-role="select-search"]');
        searchInputs.forEach(function (searchInput) {
            var targetId = searchInput.getAttribute('data-target');
            if (!targetId) {
                return;
            }

            var select = document.getElementById(targetId);
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }

            var optionsSnapshot = Array.prototype.map.call(select.options, function (option) {
                return {
                    value: String(option.value || ''),
                    text: String(option.textContent || ''),
                    selected: !!option.selected
                };
            });

            function repopulateOptions(filterValue) {
                var normalized = String(filterValue || '').trim().toLowerCase();
                var currentValue = select.value;
                var fragment = document.createDocumentFragment();
                var currentValueStillVisible = false;

                optionsSnapshot.forEach(function (item) {
                    var isPlaceholder = item.value === '';
                    var isMatch = normalized === '' || item.text.toLowerCase().indexOf(normalized) !== -1;

                    if (!isPlaceholder && !isMatch) {
                        return;
                    }

                    var option = document.createElement('option');
                    option.value = item.value;
                    option.textContent = item.text;
                    option.selected = item.value === currentValue;
                    if (option.selected) {
                        currentValueStillVisible = true;
                    }
                    fragment.appendChild(option);
                });

                if (!currentValueStillVisible && currentValue !== '') {
                    optionsSnapshot.some(function (item) {
                        if (item.value !== currentValue) {
                            return false;
                        }
                        var option = document.createElement('option');
                        option.value = item.value;
                        option.textContent = item.text;
                        option.selected = true;
                        fragment.appendChild(option);
                        return true;
                    });
                }

                select.innerHTML = '';
                select.appendChild(fragment);
            }

            searchInput.addEventListener('input', function () {
                repopulateOptions(searchInput.value);
            });
        });
    }

    function initializeDeleteModal() {
        var triggerButtons = document.querySelectorAll('.leave-delete-trigger');
        var deleteIdInput = document.getElementById('leave-delete-id');
        var deleteContext = document.querySelector('#leaveDeleteModal [data-role="delete-context"]');
        var confirmButton = document.getElementById('leave-delete-confirm-btn');
        var deleteForm = document.getElementById('leave-delete-form');
        var deleteModalElement = document.getElementById('leaveDeleteModal');

        if (
            !(deleteIdInput instanceof HTMLInputElement)
            || !(confirmButton instanceof HTMLButtonElement)
            || !(deleteForm instanceof HTMLFormElement)
            || !deleteModalElement
            || typeof bootstrap === 'undefined'
            || !bootstrap.Modal
        ) {
            return;
        }

        var modal = bootstrap.Modal.getOrCreateInstance(deleteModalElement);

        triggerButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var isLocked = String(button.getAttribute('data-delete-locked') || '0') === '1';
                if (isLocked) {
                    var lockedMessage = button.getAttribute('data-delete-lock-message')
                        || 'Cererea nu poate fi stearsa in statusul curent.';
                    showToast(lockedMessage, 'warning');
                    return;
                }

                var requestId = button.getAttribute('data-request-id') || '';
                var driver = button.getAttribute('data-driver') || 'șofer';
                var period = button.getAttribute('data-period') || '';

                deleteIdInput.value = requestId;
                if (deleteContext) {
                    deleteContext.textContent = driver + (period ? ' · ' + period : '');
                }
                modal.show();
            });
        });

        confirmButton.addEventListener('click', function () {
            deleteForm.submit();
        });
    }

    function initializeCalendarControls() {
        var controlsForm = document.getElementById('leave-calendar-controls');
        if (!(controlsForm instanceof HTMLFormElement)) {
            return;
        }

        var monthInput = controlsForm.querySelector('[data-role="month-input"]');
        var focusInput = controlsForm.querySelector('[data-role="focus-date"]');
        var prevBtn = controlsForm.querySelector('[data-role="month-prev"]');
        var nextBtn = controlsForm.querySelector('[data-role="month-next"]');
        var todayBtn = controlsForm.querySelector('[data-role="calendar-today"]');
        var viewSelect = controlsForm.querySelector('[data-role="view-select"]');

        if (!(monthInput instanceof HTMLInputElement) || !(focusInput instanceof HTMLInputElement)) {
            return;
        }

        function parseMonthValue(monthValue) {
            if (!/^\d{4}-\d{2}$/.test(monthValue || '')) {
                return null;
            }
            var date = toDate(monthValue + '-01');
            return date;
        }

        function setMonth(date) {
            monthInput.value = String(date.getFullYear()) + '-' + String(date.getMonth() + 1).padStart(2, '0');
            focusInput.value = formatIsoDate(date);
        }

        function submitControls() {
            controlsForm.submit();
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                var current = parseMonthValue(monthInput.value) || new Date();
                current.setDate(1);
                current.setMonth(current.getMonth() - 1);
                setMonth(current);
                submitControls();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                var current = parseMonthValue(monthInput.value) || new Date();
                current.setDate(1);
                current.setMonth(current.getMonth() + 1);
                setMonth(current);
                submitControls();
            });
        }

        if (todayBtn) {
            todayBtn.addEventListener('click', function () {
                var now = new Date();
                setMonth(now);
                submitControls();
            });
        }

        monthInput.addEventListener('change', function () {
            var monthDate = parseMonthValue(monthInput.value);
            if (!monthDate) {
                return;
            }
            focusInput.value = formatIsoDate(monthDate);
            submitControls();
        });

        if (viewSelect instanceof HTMLSelectElement) {
            viewSelect.addEventListener('change', submitControls);
        }
    }

    function initializeFormValidation() {
        var form = document.getElementById('leave-request-form');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        form.addEventListener('submit', function (event) {
            var driverField = form.querySelector('[name="driver_id"]');
            var startField = form.querySelector('[name="data_inceput"]');
            var endField = form.querySelector('[name="data_sfarsit"]');
            var replacementField = form.querySelector('[name="inlocuitor_id"]');
            var hasError = false;

            if (driverField instanceof HTMLSelectElement && String(driverField.value || '').trim() === '') {
                driverField.classList.add('is-invalid');
                hasError = true;
            }

            if (startField instanceof HTMLInputElement && String(startField.value || '').trim() === '') {
                startField.classList.add('is-invalid');
                hasError = true;
            }

            if (endField instanceof HTMLInputElement && String(endField.value || '').trim() === '') {
                endField.classList.add('is-invalid');
                hasError = true;
            }

            if (
                startField instanceof HTMLInputElement
                && endField instanceof HTMLInputElement
                && startField.value !== ''
                && endField.value !== ''
                && endField.value < startField.value
            ) {
                endField.classList.add('is-invalid');
                hasError = true;
                showToast('Data de sfârșit nu poate fi înainte de data de început.', 'warning');
            }

            if (
                driverField instanceof HTMLSelectElement
                && replacementField instanceof HTMLSelectElement
                && replacementField.value !== ''
                && replacementField.value === driverField.value
            ) {
                replacementField.classList.add('is-invalid');
                hasError = true;
                showToast('Înlocuitorul nu poate fi același cu șoferul selectat.', 'warning');
            }

            if (hasError) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    }

    function initializeCalendar() {
        var root = document.getElementById('leave-calendar-root');
        var skeleton = document.querySelector('[data-role="calendar-skeleton"]');
        var emptyState = document.querySelector('[data-role="calendar-empty"]');
        var errorState = document.querySelector('[data-role="calendar-error"]');
        var panelTitle = document.querySelector('.leave-calendar-panel .leave-panel-title');
        var panelHint = document.querySelector('.leave-calendar-title-hint');

        if (!root) {
            return;
        }

        var events = normalizeEvents(safeParseJson(root.getAttribute('data-events'), []));
        var focusDate = toDate(root.getAttribute('data-focus-date') || '')
            || toDate((root.getAttribute('data-month') || '') + '-01')
            || new Date();
        var view = String(root.getAttribute('data-view') || 'luna').toLowerCase();

        try {
            if (panelTitle) {
                panelTitle.textContent = formatMonthLabel(new Date(focusDate.getFullYear(), focusDate.getMonth(), 1));
            }
            if (panelHint) {
                panelHint.textContent = formatRoDate(formatIsoDate(focusDate));
            }

            if (view === 'saptamana') {
                renderWeekView(root, focusDate, events);
            } else if (view === 'zi') {
                renderDayView(root, focusDate, events);
            } else {
                renderMonthView(root, focusDate, events);
            }

            if (events.length === 0 && emptyState) {
                emptyState.classList.remove('d-none');
            } else if (emptyState) {
                emptyState.classList.add('d-none');
            }

            initializeTooltips(root);
            root.classList.remove('d-none');
        } catch (error) {
            if (errorState) {
                errorState.classList.remove('d-none');
            }
        } finally {
            if (skeleton) {
                skeleton.classList.add('d-none');
            }
        }

        root.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            var clickable = target.closest('.leave-calendar-event, .leave-day-event-item');
            if (!clickable) {
                return;
            }

            openDetailsModal(readDetailsFromDataset(clickable.dataset));
        });
    }

    function initializeTableViewButtons() {
        var buttons = document.querySelectorAll('.leave-view-btn');
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                openDetailsModal(readDetailsFromDataset(button.dataset));
            });
        });
    }

    function initializeFlashToast() {
        var flash = document.getElementById('leave-toast-flash');
        if (!flash) {
            return;
        }

        var message = flash.getAttribute('data-message') || '';
        var type = flash.getAttribute('data-type') || 'info';
        if (message.trim() === '') {
            return;
        }

        showToast(message, type);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initializeSelectSearch();
        initializeCalendarControls();
        initializeFormValidation();
        initializeCalendar();
        initializeTableViewButtons();
        initializeDeleteModal();
        initializeFlashToast();
    });
})();
