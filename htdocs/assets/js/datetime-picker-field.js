/**
 * Selector data + ora cu acelasi aspect ca "Data si ora inceput" din Adauga cursa
 * (clasele .dispatcher-datetime-*), dar independent de initRaceForm.
 *
 * Markup asteptat (in interiorul unui [data-datetime-picker]):
 *   input text  [data-dtp-display]   - ce vede operatorul, dd/mm/yyyy HH:mm
 *   button      [data-dtp-toggle]    - deschide calendarul
 *   div         [data-dtp-popover]   - popover-ul (hidden)
 *   input hidden [data-dtp-date]     - valoarea trimisa, Y-m-d
 *   input hidden [data-dtp-time]     - valoarea trimisa, HH:MM (optional; lipsa => doar data)
 *
 * Valorile ascunse se pot seta din alt cod; apoi FleetDateTimePicker.sync(container)
 * reface textul afisat.
 */
(function () {
    'use strict';

    var MONTHS = ['Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];
    var SHORT_MONTHS = ['Ian', 'Feb', 'Mar', 'Apr', 'Mai', 'Iun', 'Iul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var WEEKDAYS = ['Lu', 'Ma', 'Mi', 'Jo', 'Vi', 'Sa', 'Du'];
    var openPicker = null;

    function pad(value) {
        return (value < 10 ? '0' : '') + String(value);
    }

    function today() {
        var now = new Date();
        return { year: now.getFullYear(), month: now.getMonth() + 1, day: now.getDate() };
    }

    function parseDate(raw) {
        var value = String(raw || '').trim();
        var match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
        var parts = null;
        if (match) {
            parts = { year: +match[1], month: +match[2], day: +match[3] };
        } else {
            match = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/.exec(value);
            if (match) {
                parts = { year: +match[3], month: +match[2], day: +match[1] };
            }
        }
        if (!parts) {
            return null;
        }
        var date = new Date(parts.year, parts.month - 1, parts.day);
        if (date.getFullYear() !== parts.year || date.getMonth() !== parts.month - 1 || date.getDate() !== parts.day) {
            return null;
        }
        return parts;
    }

    function normalizeTime(raw) {
        var value = String(raw || '').trim().replace(/\s+/g, '');
        var hours;
        var minutes;
        var match;
        if (value === '') {
            return '';
        }
        if ((match = /^(\d{1,2})[:.,;hH](\d{1,2})(?::\d{2})?$/.exec(value))) {
            hours = +match[1];
            minutes = +match[2];
        } else if (/^\d{1,2}$/.test(value)) {
            hours = +value;
            minutes = 0;
        } else if (/^\d{3,4}$/.test(value)) {
            hours = +value.slice(0, -2);
            minutes = +value.slice(-2);
        } else {
            return null;
        }
        if (hours > 23 || minutes > 59) {
            return null;
        }
        return pad(hours) + ':' + pad(minutes);
    }

    function sameDate(a, b) {
        return !!(a && b && a.year === b.year && a.month === b.month && a.day === b.day);
    }

    function isoDate(parts) {
        return parts ? parts.year + '-' + pad(parts.month) + '-' + pad(parts.day) : '';
    }

    function displayDate(parts) {
        return parts ? pad(parts.day) + '/' + pad(parts.month) + '/' + parts.year : '';
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        if (tag === 'button') { node.type = 'button'; }
        return node;
    }

    function iconButton(action, label, icon) {
        var button = el('button', 'dispatcher-datetime-picker-icon-btn');
        var i = el('i', 'bi ' + icon);
        button.setAttribute('data-datetime-action', action);
        button.setAttribute('aria-label', label);
        button.title = label;
        i.setAttribute('aria-hidden', 'true');
        button.appendChild(i);
        return button;
    }

    function header(title, left, right, titleAction) {
        var wrap = el('div', 'dispatcher-datetime-picker-header');
        var titleEl = el(titleAction ? 'button' : 'div', 'dispatcher-datetime-picker-title', title);
        if (titleAction) { titleEl.setAttribute('data-datetime-action', titleAction); }
        wrap.appendChild(left || el('span', 'dispatcher-datetime-picker-header-spacer'));
        wrap.appendChild(titleEl);
        wrap.appendChild(right || el('span', 'dispatcher-datetime-picker-header-spacer'));
        return wrap;
    }

    function optionButton(text, attr, value, selected) {
        var button = el('button', 'dispatcher-datetime-time-option', text);
        button.setAttribute(attr, String(value));
        if (selected) {
            button.classList.add('is-selected');
            button.setAttribute('aria-pressed', 'true');
        }
        return button;
    }

    function Picker(container) {
        this.container = container;
        this.display = container.querySelector('[data-dtp-display]');
        this.toggle = container.querySelector('[data-dtp-toggle]');
        this.popover = container.querySelector('[data-dtp-popover]');
        this.dateField = container.querySelector('[data-dtp-date]');
        this.timeField = container.querySelector('[data-dtp-time]');
        this.dateOnly = !this.timeField;
        this.state = { view: 'date', date: null, hour: null, minute: null, year: 0, month: 0 };
        this.bind();
        this.syncDisplay();
    }

    Picker.prototype.syncDisplay = function () {
        var parts = parseDate(this.dateField.value);
        var time = this.timeField ? normalizeTime(this.timeField.value) : '';
        this.display.value = parts ? (displayDate(parts) + (time ? ' ' + time : '')) : '';
        this.setInvalid(false);
    };

    Picker.prototype.setInvalid = function (invalid) {
        this.display.classList.toggle('is-invalid', !!invalid || this.container.hasAttribute('data-dtp-server-error'));
        if (invalid) {
            this.display.setAttribute('aria-invalid', 'true');
        } else {
            this.display.removeAttribute('aria-invalid');
        }
    };

    Picker.prototype.write = function (parts, time) {
        this.container.removeAttribute('data-dtp-server-error');
        this.dateField.value = isoDate(parts);
        if (this.timeField) { this.timeField.value = time || ''; }
        this.syncDisplay();
        this.dateField.dispatchEvent(new Event('change', { bubbles: true }));
    };

    // Textul tastat de operator -> valorile ascunse. Gol => camp gol.
    Picker.prototype.readTyped = function (markInvalid) {
        var value = this.display.value.trim().replace(/T/g, ' ').replace(/[,\s]+/g, ' ');
        if (value === '') {
            this.write(null, '');
            return true;
        }
        var pieces = value.split(' ');
        var parts = pieces.length <= 2 ? parseDate(pieces[0]) : null;
        var time = pieces.length === 2 && !this.dateOnly ? normalizeTime(pieces[1]) : '';
        if (!parts || time === null) {
            if (markInvalid) { this.setInvalid(true); }
            return false;
        }
        this.write(parts, time);
        return true;
    };

    Picker.prototype.loadState = function () {
        var parts = parseDate(this.dateField.value);
        var time = this.timeField ? normalizeTime(this.timeField.value) : '';
        var base = parts || today();
        this.state.date = parts;
        this.state.hour = time ? +time.slice(0, 2) : null;
        this.state.minute = time ? +time.slice(3, 5) : null;
        this.state.year = base.year;
        this.state.month = base.month;
    };

    Picker.prototype.applyTime = function (hour, minute) {
        this.state.date = this.state.date || parseDate(this.dateField.value) || today();
        this.state.hour = hour;
        this.state.minute = minute;
        this.write(this.state.date, pad(hour) + ':' + pad(minute));
    };

    Picker.prototype.render = function () {
        var pop = this.popover;
        var s = this.state;
        var self = this;
        while (pop.firstChild) { pop.removeChild(pop.firstChild); }

        if (s.view === 'month') {
            pop.appendChild(header(String(s.year), iconButton('prev-year', 'Anul precedent', 'bi-chevron-left'), iconButton('next-year', 'Anul urmator', 'bi-chevron-right'), 'show-year'));
            var monthGrid = el('div', 'dispatcher-datetime-month-grid');
            SHORT_MONTHS.forEach(function (label, index) {
                monthGrid.appendChild(optionButton(label, 'data-datetime-month', index + 1, s.date && s.date.year === s.year && s.date.month === index + 1));
            });
            pop.appendChild(monthGrid);
            return;
        }

        if (s.view === 'year') {
            var start = Math.floor(s.year / 12) * 12;
            pop.appendChild(header(start + ' - ' + (start + 11), iconButton('prev-year-range', 'Interval precedent', 'bi-chevron-left'), iconButton('next-year-range', 'Interval urmator', 'bi-chevron-right')));
            var yearGrid = el('div', 'dispatcher-datetime-year-grid');
            for (var y = start; y < start + 12; y += 1) {
                yearGrid.appendChild(optionButton(String(y), 'data-datetime-year', y, s.date && s.date.year === y));
            }
            pop.appendChild(yearGrid);
            return;
        }

        if (s.view === 'time') {
            var modeSwitch = el('div', 'dispatcher-datetime-mode-switch');
            modeSwitch.appendChild(iconButton('show-date', 'Alege data', 'bi-calendar3'));
            pop.appendChild(modeSwitch);
            var spinner = el('div', 'dispatcher-datetime-spinner');
            var hourValue = el('button', 'dispatcher-datetime-spinner-value', pad(s.hour || 0));
            var minuteValue = el('button', 'dispatcher-datetime-spinner-value', pad(s.minute || 0));
            hourValue.setAttribute('data-datetime-action', 'show-hour');
            hourValue.setAttribute('aria-label', 'Alege ora');
            minuteValue.setAttribute('data-datetime-action', 'show-minute');
            minuteValue.setAttribute('aria-label', 'Alege minutele');
            [
                iconButton('hour-up', 'Creste ora', 'bi-chevron-up'), el('span'), iconButton('minute-up', 'Creste minutele', 'bi-chevron-up'),
                hourValue, el('span', 'dispatcher-datetime-spinner-separator', ':'), minuteValue,
                iconButton('hour-down', 'Scade ora', 'bi-chevron-down'), el('span'), iconButton('minute-down', 'Scade minutele', 'bi-chevron-down')
            ].forEach(function (node) { spinner.appendChild(node); });
            pop.appendChild(spinner);
            return;
        }

        if (s.view === 'hour') {
            var hourSwitch = el('div', 'dispatcher-datetime-mode-switch');
            hourSwitch.appendChild(iconButton('show-date', 'Alege data', 'bi-calendar3'));
            pop.appendChild(hourSwitch);
            var hourGrid = el('div', 'dispatcher-datetime-time-grid dispatcher-datetime-hour-grid');
            for (var h = 0; h < 24; h += 1) {
                hourGrid.appendChild(optionButton(pad(h), 'data-datetime-hour', h, s.hour === h));
            }
            pop.appendChild(hourGrid);
            return;
        }

        if (s.view === 'minute') {
            pop.appendChild(header(pad(s.hour || 0) + ':mm', iconButton('show-hour', 'Alege ora', 'bi-chevron-left'), iconButton('show-date', 'Alege data', 'bi-calendar3')));
            var minuteGrid = el('div', 'dispatcher-datetime-time-grid dispatcher-datetime-minute-grid');
            var minutes = [];
            for (var m = 0; m < 60; m += 5) { minutes.push(m); }
            if (s.minute !== null && minutes.indexOf(s.minute) === -1) {
                minutes.push(s.minute);
                minutes.sort(function (a, b) { return a - b; });
            }
            minutes.forEach(function (minute) {
                minuteGrid.appendChild(optionButton(pad(minute), 'data-datetime-minute', minute, s.minute === minute));
            });
            pop.appendChild(minuteGrid);
            return;
        }

        // Calendarul zilelor.
        var now = today();
        var first = new Date(s.year, s.month - 1, 1);
        var gridStart = new Date(s.year, s.month - 1, 1 - ((first.getDay() + 6) % 7));
        pop.appendChild(header(MONTHS[s.month - 1] + ' ' + s.year, iconButton('prev-month', 'Luna precedenta', 'bi-chevron-left'), iconButton('next-month', 'Luna urmatoare', 'bi-chevron-right'), 'show-month'));
        var weekdays = el('div', 'dispatcher-datetime-weekdays');
        WEEKDAYS.forEach(function (label) { weekdays.appendChild(el('span', '', label)); });
        pop.appendChild(weekdays);
        var days = el('div', 'dispatcher-datetime-days');
        for (var i = 0; i < 42; i += 1) {
            var cell = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i);
            var parts = { year: cell.getFullYear(), month: cell.getMonth() + 1, day: cell.getDate() };
            var day = el('button', 'dispatcher-datetime-day', String(parts.day));
            if (parts.month !== s.month) { day.classList.add('is-muted'); }
            if (sameDate(parts, now)) { day.classList.add('is-today'); }
            if (sameDate(parts, s.date)) {
                day.classList.add('is-selected');
                day.setAttribute('aria-pressed', 'true');
            }
            day.setAttribute('data-datetime-date', isoDate(parts));
            days.appendChild(day);
        }
        pop.appendChild(days);
        if (!self.dateOnly) {
            var footer = el('div', 'dispatcher-datetime-picker-footer');
            footer.appendChild(iconButton('show-time', 'Alege ora', 'bi-clock'));
            pop.appendChild(footer);
        }
    };

    Picker.prototype.open = function () {
        if (openPicker && openPicker !== this) { openPicker.close(); }
        this.readTyped(false);
        this.loadState();
        this.state.view = 'date';
        this.render();
        this.popover.hidden = false;
        this.toggle.setAttribute('aria-expanded', 'true');
        openPicker = this;
    };

    Picker.prototype.close = function () {
        this.popover.hidden = true;
        this.toggle.setAttribute('aria-expanded', 'false');
        if (openPicker === this) { openPicker = null; }
    };

    Picker.prototype.handleClick = function (event) {
        var s = this.state;
        var target = event.target instanceof Element ? event.target : null;
        if (!target) { return; }
        event.stopPropagation();

        var action = target.closest('[data-datetime-action]');
        if (action) {
            var name = action.getAttribute('data-datetime-action');
            if (name === 'prev-month' || name === 'next-month') {
                var moved = new Date(s.year, s.month - 1 + (name === 'prev-month' ? -1 : 1), 1);
                s.year = moved.getFullYear();
                s.month = moved.getMonth() + 1;
            } else if (name === 'prev-year' || name === 'next-year') {
                s.year += name === 'prev-year' ? -1 : 1;
            } else if (name === 'prev-year-range' || name === 'next-year-range') {
                s.year += name === 'prev-year-range' ? -12 : 12;
            } else if (name === 'show-date') {
                if (s.date) { s.year = s.date.year; s.month = s.date.month; }
                s.view = 'date';
            } else if (name === 'show-month' || name === 'show-year' || name === 'show-hour') {
                s.view = name.slice(5);
            } else if (name === 'show-time' || name === 'show-minute') {
                if (s.hour === null) { s.hour = 0; }
                if (s.minute === null) { s.minute = 0; }
                s.view = name === 'show-time' ? 'time' : 'minute';
            } else if (/^(hour|minute)-(up|down)$/.test(name)) {
                var hour = s.hour || 0;
                var minute = s.minute || 0;
                if (name === 'hour-up') { hour = (hour + 1) % 24; }
                if (name === 'hour-down') { hour = (hour + 23) % 24; }
                if (name === 'minute-up') { minute = (minute + 1) % 60; }
                if (name === 'minute-down') { minute = (minute + 59) % 60; }
                this.applyTime(hour, minute);
                s.view = 'time';
            }
            this.render();
            return;
        }

        var dayButton = target.closest('[data-datetime-date]');
        if (dayButton) {
            var parts = parseDate(dayButton.getAttribute('data-datetime-date'));
            if (!parts) { return; }
            s.date = parts;
            s.year = parts.year;
            s.month = parts.month;
            this.write(parts, this.timeField ? (normalizeTime(this.timeField.value) || '') : '');
            if (this.dateOnly) {
                this.close();
                return;
            }
            this.render();
            return;
        }

        var monthButton = target.closest('[data-datetime-month]');
        if (monthButton) {
            s.month = +monthButton.getAttribute('data-datetime-month');
            s.view = 'date';
            this.render();
            return;
        }

        var yearButton = target.closest('[data-datetime-year]');
        if (yearButton) {
            s.year = +yearButton.getAttribute('data-datetime-year');
            s.view = 'month';
            this.render();
            return;
        }

        var hourButton = target.closest('[data-datetime-hour]');
        if (hourButton) {
            this.applyTime(+hourButton.getAttribute('data-datetime-hour'), s.minute || 0);
            s.view = 'time';
            this.render();
            return;
        }

        var minuteButton = target.closest('[data-datetime-minute]');
        if (minuteButton) {
            this.applyTime(s.hour || 0, +minuteButton.getAttribute('data-datetime-minute'));
            s.view = 'time';
            this.render();
        }
    };

    Picker.prototype.bind = function () {
        var self = this;
        this.display.addEventListener('input', function () { self.setInvalid(false); });
        this.display.addEventListener('change', function () { self.readTyped(true); });
        this.display.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                self.open();
            }
        });
        this.toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (self.popover.hidden) { self.open(); } else { self.close(); }
        });
        this.popover.addEventListener('click', function (event) { self.handleClick(event); });
    };

    document.addEventListener('click', function (event) {
        if (openPicker && !(event.target instanceof Node && openPicker.container.contains(event.target))) {
            openPicker.close();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && openPicker) {
            var toggle = openPicker.toggle;
            openPicker.close();
            toggle.focus();
        }
    });

    function init(root) {
        (root || document).querySelectorAll('[data-datetime-picker]').forEach(function (container) {
            if (!container.fleetDateTimePicker) {
                container.fleetDateTimePicker = new Picker(container);
            }
        });
    }

    window.FleetDateTimePicker = {
        init: init,
        // Dupa ce alt cod a scris direct in campurile ascunse.
        sync: function (root) {
            init(root);
            var scope = root || document;
            var containers = scope.matches && scope.matches('[data-datetime-picker]')
                ? [scope]
                : Array.prototype.slice.call(scope.querySelectorAll('[data-datetime-picker]'));
            containers.forEach(function (container) {
                container.removeAttribute('data-dtp-server-error');
                container.fleetDateTimePicker.syncDisplay();
            });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }
})();
