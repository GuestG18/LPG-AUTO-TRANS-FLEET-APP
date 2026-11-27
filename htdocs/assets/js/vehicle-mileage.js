/*
 * Detalii vehicul — cardul de kilometraj: carusel pe trei surse independente
 * (SAS GPS → SAS CAN → CardOil → SAS GPS) + perioadă.
 * Toate cele trei surse vin deodată (randarea paginii sau o singură cerere la schimbarea
 * perioadei, action=mileage): schimbarea sursei NU face cereri. Răspunsurile vechi
 * (o perioadă aleasă între timp) sunt ignorate.
 */
(function () {
    'use strict';

    var card = document.querySelector('[data-vd-km]');
    if (!card) {
        return;
    }
    var dataEl = card.querySelector('[data-vd-km-data]');
    var data;
    try {
        data = JSON.parse(dataEl ? dataEl.textContent : 'null');
    } catch (e) {
        data = null;
    }
    if (!data || !data.sources) {
        return;
    }

    var ORDER = ['sas', 'can', 'cardoil'];
    var PERIODS = [
        ['all', 'Până în prezent'],
        ['month', 'Luna curentă'],
        ['prev_month', 'Luna precedentă'],
        ['year', 'Anul curent'],
        ['custom', 'Interval personalizat']
    ];
    var STATUS_TEXT = {
        complete: 'Completă', partial: 'Parțială', unavailable: 'Indisponibilă',
        conflict: 'Conflict', invalid: 'Invalid', duplicate: 'Suprapus'
    };
    var FRESHNESS_TEXT = {
        actual: 'Actualizat', neactualizat: 'Date neactualizate', eroare: 'Eroare la ultima sincronizare',
        nesincronizat: 'Încă nesincronizat', eveniment: 'Citire la alimentare'
    };
    var TYPE_TEXT = {
        gps_km_index: 'Index GPS (kmIndex SAS) — nu este odometrul de bord',
        can_odometer: 'Odometru de bord din CAN (SAS)',
        pump_odometer: 'Odometru tastat la pompă (CardOil)'
    };
    var PRECISION_TEXT = {
        parcat: 'exact (parcat)', toleranta: 'în toleranță', prima_citire: 'prima citire din interval',
        ultima_citire: 'ultima citire disponibilă', eveniment: 'alimentare'
    };

    var stage = card.querySelector('[data-vd-km-stage]');
    var metaText = card.querySelector('[data-vd-km-meta]');
    var metaDot = card.querySelector('[data-vd-km-dot]');
    var infoBtn = card.querySelector('[data-vd-km-info]');
    var periodBtn = card.querySelector('[data-vd-km-period]');
    var pop = card.querySelector('[data-vd-km-pop]');
    var dots = Array.prototype.slice.call(card.querySelectorAll('[data-vd-km-go]'));
    var url = card.getAttribute('data-url');
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var index = 0;
    var popMode = null;
    var requestSeq = 0;
    var controller = null;
    var customFrom = '';
    var customTo = '';
    var lastError = '';

    var numberFmt = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    var numberFmt1 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 1 });

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function fmtKm(value, precise) {
        return value === null || value === undefined ? '—' : (precise ? numberFmt1 : numberFmt).format(value);
    }
    function fmtDate(value, withTime) {
        if (!value) {
            return '—';
        }
        var m = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
        if (!m) {
            return String(value);
        }
        return m[3] + '.' + m[2] + '.' + m[1] + (withTime && m[4] ? ' ' + m[4] + ':' + m[5] : '');
    }

    function current() {
        return data.sources[ORDER[index]];
    }

    function dotClass(src) {
        if (src.status === 'unavailable') {
            return 'is-off';
        }
        if (src.status === 'ok' && src.coverage === 'complete') {
            return 'is-ok';
        }
        return 'is-warn';
    }

    function metaFor(src) {
        var parts = [src.provider_label + (src.via_coupling ? ', prin cuplare' : '')];
        if (src.status === 'unavailable') {
            parts.push(src.via_coupling && data.period.code === 'all' ? 'indisponibil' : shortReason(src));
        } else if (data.period.absolute && !src.via_coupling) {
            parts.push(fmtDate(src.measured_at));
        } else {
            parts.push(data.period.short);
        }
        // Starea (actual / neactualizat / parțial) o arată punctul colorat + titlul; textul rămâne scurt.
        return parts.join(' • ');
    }

    function shortReason(src) {
        var reason = String(src.reason || 'Fără citiri disponibile.');
        var since = reason.match(/începe la (\d{2}\.\d{2}\.\d{4})/);
        if (since) {
            return 'istoric de la ' + since[1];
        }
        if (/nesincronizat/i.test(reason)) {
            return 'nesincronizat';
        }
        if (/nu raportează/.test(reason)) {
            return 'fără date în SAS';
        }
        if (/^Sursă indisponibilă/.test(reason)) {
            return 'sursă indisponibilă';
        }
        if (/insuficiente/.test(reason)) {
            return 'citiri insuficiente';
        }
        if (/cuplare indisponibil/.test(reason)) {
            return 'istoric de cuplare indisponibil';
        }
        return 'fără citiri';
    }

    function slideHtml(src) {
        var empty = src.value === null;
        return '<strong class="' + (empty ? 'is-empty' : '') + '">' + esc(fmtKm(src.value)) + '</strong>'
            + '<span class="vd-km-label">' + esc(src.label) + '</span>';
    }

    function render(direction) {
        var src = current();
        var next = document.createElement('div');
        next.className = 'vd-km-slide';
        next.innerHTML = slideHtml(src);
        var old = stage.querySelector('.vd-km-slide:not(.is-leaving)');

        if (!old || !direction || reduceMotion) {
            stage.innerHTML = '';
            stage.appendChild(next);
        } else {
            old.classList.add('is-leaving');
            next.classList.add(direction > 0 ? 'from-right' : 'from-left');
            stage.appendChild(next);
            // forțează reflow, apoi pornește tranziția
            void next.offsetWidth;
            old.classList.add(direction > 0 ? 'to-left' : 'to-right');
            next.classList.remove('from-right', 'from-left');
            window.setTimeout(function () {
                if (old.parentNode) {
                    old.parentNode.removeChild(old);
                }
            }, 300);
        }

        var metaParts = metaFor(src).split(" • ");
        metaText.innerHTML = '<b class="vd-km-prov">' + esc(metaParts.shift()) + '</b><span> • </span>' + esc(metaParts.join(' • '));
        var stateText = src.status === 'unavailable' ? '' : (src.status === 'partial' ? 'Acoperire parțială. '
            : (src.status === 'stale' ? 'Date neactualizate. ' : ''));
        infoBtn.setAttribute('title', stateText + (src.reason && src.status !== 'stale' && src.status !== 'partial' ? src.reason + ' ' : '') + 'Click pentru detalii despre calcul.');
        infoBtn.setAttribute('aria-label', metaFor(src) + '. ' + stateText + 'Detalii despre calcul');
        metaDot.className = 'vd-km-dot ' + dotClass(src);
        dots.forEach(function (dot, i) {
            dot.setAttribute('aria-selected', i === index ? 'true' : 'false');
            dot.setAttribute('tabindex', i === index ? '0' : '-1');
        });
        var periodLabel = 'Perioadă: ' + data.period.label + (data.period.code === 'custom' || data.period.code === 'prev_month' ? ' (' + data.period.short + ')' : '');
        periodBtn.setAttribute('aria-label', periodLabel);
        periodBtn.setAttribute('title', periodLabel);
        periodBtn.classList.toggle('is-set', data.period.code !== 'all');
        if (popMode === 'info') {
            renderInfo();
        }
    }

    function go(newIndex, direction) {
        var n = ORDER.length;
        newIndex = ((newIndex % n) + n) % n;
        if (newIndex === index) {
            return;
        }
        index = newIndex;
        render(direction);
    }

    // ---------------------------------------------------------------- popover

    function openPop(mode) {
        if (popMode === mode) {
            closePop();
            return;
        }
        popMode = mode;
        if (mode === 'info') {
            renderInfo();
        } else {
            renderPeriods();
        }
        pop.hidden = false;
        infoBtn.setAttribute('aria-expanded', mode === 'info' ? 'true' : 'false');
        periodBtn.setAttribute('aria-expanded', mode === 'period' ? 'true' : 'false');
        pop.setAttribute('aria-label', mode === 'info' ? 'Detalii kilometraj' : 'Alege perioada');
    }

    function closePop() {
        popMode = null;
        pop.hidden = true;
        infoBtn.setAttribute('aria-expanded', 'false');
        periodBtn.setAttribute('aria-expanded', 'false');
    }

    function badge(status) {
        var cls = status === 'complete' ? 'is-ok' : (status === 'partial' ? 'is-warn' : '');
        return '<span class="vd-km-badge ' + cls + '">' + esc(STATUS_TEXT[status] || status) + '</span>';
    }

    function boundaryText(b) {
        if (!b) {
            return '—';
        }
        return fmtKm(b.km, true) + ' km la ' + fmtDate(b.at, true) + (b.precision && PRECISION_TEXT[b.precision] ? ' (' + PRECISION_TEXT[b.precision] + ')' : '');
    }

    function renderInfo() {
        var src = current();
        var isDistance = src.mode === 'distance';
        var rows = [
            ['Sursă', src.provider_label + (src.via_coupling ? ' — prin cuplare' : '')],
            ['Măsurătoare', TYPE_TEXT[src.measurement_type] || src.measurement_type],
            ['Perioadă', data.period.label + (data.period.absolute ? '' : ' (' + fmtDate(data.period.start, true) + ' → ' + fmtDate(data.period.end, true) + ')')],
            [isDistance ? 'Km parcurși' : 'Kilometraj', src.value === null ? '—' : fmtKm(src.value, true) + ' km' + (src.confirmed_zero ? ' (0 confirmat)' : '')]
        ];
        if (!isDistance && src.measured_at) {
            rows.push(['Citire din', fmtDate(src.measured_at, true)]);
        }
        if (src.boundaries) {
            rows.push(['Citire inițială', boundaryText(src.boundaries.start)]);
            rows.push(['Citire finală', boundaryText(src.boundaries.end)]);
        } else if (isDistance && src.interval) {
            rows.push(['Interval măsurat', fmtDate(src.interval.from, true) + ' → ' + fmtDate(src.interval.to, true)]);
        }
        rows.push(['Acoperire', STATUS_TEXT[src.coverage] || src.coverage]);
        if (src.freshness) {
            rows.push(['Prospețime', FRESHNESS_TEXT[src.freshness] || src.freshness]);
        }
        if (src.last_checked_at) {
            rows.push(['Ultima sincronizare', fmtDate(src.last_checked_at, true)]);
        }

        var html = '<h4>' + esc(src.label) + '</h4><dl>';
        rows.forEach(function (r) {
            html += '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>';
        });
        html += '</dl>';

        if (src.via_coupling) {
            html += '<div class="vd-km-note is-info">Calculat pe baza istoricului de cuplare: km capului tractor doar cât semiremorca a fost cuplată. Nu este odometrul propriu al semiremorcii.</div>';
        } else if (src.provider === 'sas') {
            html += '<div class="vd-km-note is-info">Indexul GPS SAS nu este calibrat pe bord: valoarea absolută poate diferi de odometrul vehiculului.</div>';
        }
        if (src.reason) {
            html += '<div class="vd-km-note">' + esc(src.reason) + '</div>';
        }
        if (src.notes && src.notes.length) {
            html += '<ul>' + src.notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') + '</ul>';
        }

        if (src.trailer) {
            var t = src.trailer;
            html += '<dl style="margin-top:8px">'
                + '<dt>Intervale analizate</dt><dd>' + esc(t.analysed) + '</dd>'
                + '<dt>Calculate complet</dt><dd>' + esc(t.complete) + '</dd>'
                + '<dt>Incomplete / conflict</dt><dd>' + esc(t.incomplete) + ' / ' + esc(t.conflicts) + '</dd>'
                + '<dt>Acoperire de la</dt><dd>' + esc(fmtDate(t.earliest_coverage, true)) + '</dd>'
                + '<dt>Ultima citire</dt><dd>' + esc(fmtDate(t.latest_measurement, true)) + '</dd>'
                + '</dl>';
            if (t.intervals && t.intervals.length) {
                html += '<table class="vd-km-table"><thead><tr><th>Tractor</th><th>Cuplat</th><th>Calculat pe</th><th class="num">Km</th><th>Stare</th></tr></thead><tbody>';
                t.intervals.forEach(function (i) {
                    var calc = i.start && i.end ? fmtDate(i.start.at, true) + ' → ' + fmtDate(i.end.at, true) : '—';
                    html += '<tr title="' + esc(i.reason || '') + '"><td>' + esc(i.tractor_plate || '—') + '</td>'
                        + '<td>' + esc(fmtDate(i.coupling_start)) + ' → ' + esc(i.active ? 'activ' : fmtDate(i.coupling_end)) + '</td>'
                        + '<td>' + esc(calc) + '</td>'
                        + '<td class="num">' + esc(fmtKm(i.km, true)) + '</td>'
                        + '<td>' + badge(i.status) + '</td></tr>';
                });
                html += '</tbody></table>';
            }
        }
        pop.innerHTML = html;
    }

    function renderPeriods() {
        var html = '<h4>Perioadă</h4><div class="vd-km-periods" role="radiogroup" aria-label="Perioadă">';
        PERIODS.forEach(function (p) {
            html += '<button type="button" role="radio" data-vd-km-period-code="' + p[0] + '" aria-checked="' + (data.period.code === p[0] ? 'true' : 'false') + '">' + esc(p[1]) + '</button>';
        });
        html += '</div>';
        var showCustom = data.period.code === 'custom' || pop.getAttribute('data-custom-open') === '1';
        if (showCustom) {
            readPickers();
            var from = customFrom || (data.period.code === 'custom' ? String(data.period.start).slice(0, 16) : monthStart());
            var to = customTo || (data.period.code === 'custom' ? String(data.period.end).slice(0, 16) : nowStamp());
            html += '<div class="vd-km-custom">'
                + pickerField('from', 'De la (data și ora)', from)
                + pickerField('to', 'Până la (data și ora)', to)
                + '<button type="button" data-vd-km-apply>Aplică</button></div>';
        }
        if (lastError) {
            html += '<div class="vd-km-error" role="alert">' + esc(lastError) + '</div>';
        }
        pop.innerHTML = html;
        pop.classList.toggle('has-custom', showCustom);
        if (showCustom && window.FleetDateTimePicker) {
            // Același selector dată + oră ca în „Adaugă cursă” (assets/js/datetime-picker-field.js).
            window.FleetDateTimePicker.sync(pop);
        }
    }

    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }
    function stamp(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }
    function nowStamp() {
        return stamp(new Date());
    }
    function monthStart() {
        var d = new Date();
        return stamp(new Date(d.getFullYear(), d.getMonth(), 1, 0, 0));
    }

    /** Câmpul dată + oră: valorile trimise stau în inputurile ascunse (Y-m-d și HH:MM). */
    function pickerField(role, label, value) {
        var parts = String(value || '').split(/[ T]/);
        return '<div class="vd-km-dt"><span class="vd-km-dt-label">' + esc(label) + '</span>'
            + '<div class="dispatcher-datetime-field" data-datetime-picker data-vd-km-dt="' + role + '">'
            + '<div class="input-group input-group-sm dispatcher-datetime-input-group">'
            + '<input type="text" class="form-control" placeholder="dd/mm/yyyy HH:mm" inputmode="numeric" autocomplete="off" maxlength="16" aria-label="' + esc(label) + '" data-dtp-display>'
            + '<button type="button" class="btn btn-outline-secondary" aria-label="Deschide calendarul și ora" aria-expanded="false" data-dtp-toggle><i class="bi bi-calendar3" aria-hidden="true"></i></button>'
            + '</div><div class="dispatcher-datetime-popover" hidden data-dtp-popover></div>'
            + '<input type="hidden" value="' + esc(parts[0] || '') + '" data-dtp-date>'
            + '<input type="hidden" value="' + esc((parts[1] || '00:00').slice(0, 5)) + '" data-dtp-time>'
            + '</div></div>';
    }

    function pickerValue(role) {
        var box = pop.querySelector('[data-vd-km-dt="' + role + '"]');
        if (!box) {
            return null;
        }
        var date = (box.querySelector('[data-dtp-date]') || {}).value || '';
        var time = (box.querySelector('[data-dtp-time]') || {}).value || '00:00';
        return date ? date + ' ' + time : '';
    }

    /** Păstrează ce a ales operatorul înainte de o re-randare a listei. */
    function readPickers() {
        var from = pickerValue('from');
        var to = pickerValue('to');
        if (from !== null) {
            customFrom = from;
        }
        if (to !== null) {
            customTo = to;
        }
    }

    function load(period, from, to) {
        requestSeq += 1;
        var seq = requestSeq;
        if (controller) {
            controller.abort();
        }
        controller = window.AbortController ? new AbortController() : null;
        var query = '&period=' + encodeURIComponent(period);
        if (period === 'custom') {
            query += '&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
        }
        card.classList.add('is-loading');
        card.setAttribute('aria-busy', 'true');
        lastError = '';
        fetch(url + query, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, message: 'Răspuns invalid de la server.' };
            });
        }).then(function (payload) {
            if (seq !== requestSeq) {
                return; // a fost aleasă între timp altă perioadă
            }
            if (!payload || !payload.ok) {
                lastError = (payload && payload.message) || 'Kilometrajul nu a putut fi calculat.';
                if (popMode === 'period') {
                    renderPeriods();
                }
                return;
            }
            data = payload;
            if (popMode === 'period') {
                closePop();
            }
            render(0);
        }).catch(function (error) {
            if (seq !== requestSeq || (error && error.name === 'AbortError')) {
                return;
            }
            lastError = 'Kilometrajul nu a putut fi calculat. Încearcă din nou.';
            if (popMode === 'period') {
                renderPeriods();
            }
        }).then(function () {
            if (seq === requestSeq) {
                card.classList.remove('is-loading');
                card.removeAttribute('aria-busy');
            }
        });
    }

    // ---------------------------------------------------------------- evenimente

    card.addEventListener('click', function (event) {
        var target = event.target;
        var step = target.closest('[data-vd-km-step]');
        if (step) {
            var dir = parseInt(step.getAttribute('data-vd-km-step'), 10);
            go(index + dir, dir);
            return;
        }
        var dot = target.closest('[data-vd-km-go]');
        if (dot) {
            var to = ORDER.indexOf(dot.getAttribute('data-vd-km-go'));
            go(to, to > index ? 1 : -1);
            return;
        }
        if (target.closest('[data-vd-km-info]')) {
            openPop('info');
            return;
        }
        if (target.closest('[data-vd-km-period]')) {
            pop.removeAttribute('data-custom-open');
            openPop('period');
            return;
        }
        var periodChoice = target.closest('[data-vd-km-period-code]');
        if (periodChoice) {
            var code = periodChoice.getAttribute('data-vd-km-period-code');
            if (code === 'custom') {
                pop.setAttribute('data-custom-open', '1');
                renderPeriods();
                var fromInput = pop.querySelector('[data-vd-km-dt="from"] [data-dtp-display]');
                if (fromInput) {
                    fromInput.focus();
                }
                return;
            }
            pop.removeAttribute('data-custom-open');
            load(code);
            return;
        }
        if (target.closest('[data-vd-km-apply]')) {
            // Textul tastat în selector se validează la „change” (blur): îl forțăm înainte de citire.
            var typed = document.activeElement;
            if (typed && typed.matches && typed.matches('[data-dtp-display]') && pop.contains(typed)) {
                typed.blur();
            }
            readPickers();
            if (!customFrom || !customTo) {
                lastError = 'Alege data și ora de început și de sfârșit.';
                renderPeriods();
                return;
            }
            if (customFrom >= customTo) {
                lastError = 'Începutul intervalului trebuie să fie înainte de sfârșit.';
                renderPeriods();
                return;
            }
            lastError = '';
            load('custom', customFrom, customTo);
        }
    });


    card.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && popMode) {
            closePop();
            card.focus();
            return;
        }
        if (pop.contains(event.target)) {
            return;
        }
        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            var dir = event.key === 'ArrowRight' ? 1 : -1;
            event.preventDefault();
            go(index + dir, dir);
            var focused = document.activeElement;
            if (focused && focused.hasAttribute('data-vd-km-go')) {
                dots[index].focus();
            }
        }
    });

    document.addEventListener('click', function (event) {
        // Un element re-randat chiar de acest click (lista de perioade) nu mai e in DOM: nu e click in afara.
        if (popMode && event.target.isConnected && !card.contains(event.target)) {
            closePop();
        }
    });

    render(0);
})();
