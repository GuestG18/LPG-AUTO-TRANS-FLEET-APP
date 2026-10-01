/*
 * Dashboard - filtre live.
 *
 * Schimbarea perioadei / categoriei / vehiculului nu mai reincarca pagina: se cere
 * aceeasi pagina in fundal, iar zonele marcate cu data-dashboard-live="<cheie>" sunt
 * inlocuite cu cele din raspuns. Cardurile raman aceleasi elemente (starea cardului
 * de cost operational si listenerii din app.js se pastreaza), doar continutul se schimba.
 * Numerele (data-dashboard-count / data-dashboard-money) numara de la valoarea veche la
 * cea noua, iar sectiunile isi animeaza inaltimea cand listele cresc sau scad.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-dashboard-live-root]');
    var form = root ? root.querySelector('[data-dashboard-live-form]') : null;

    if (!(form instanceof HTMLFormElement) || !window.fetch || !window.DOMParser) {
        return;
    }

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var vehicleSelect = form.querySelector('select[name="vehicle_id"]');
    var resetLink = form.querySelector('[data-dashboard-live-reset]');
    var filterPanel = form.closest('.dashboard-filter-panel');
    var morphSections = Array.prototype.slice.call(root.querySelectorAll('.dashboard-main-grid'));
    var pendingController = null;
    var requestSeq = 0;

    var progress = document.createElement('div');
    progress.className = 'dashboard-live-progress';
    progress.setAttribute('aria-hidden', 'true');
    if (filterPanel) {
        filterPanel.appendChild(progress);
    }

    function selectedCategory() {
        var checked = form.querySelector('input[name="vehicle_category"]:checked');
        return checked ? checked.value : 'toate';
    }

    // Lista de vehicule arata doar masinile din categoria aleasa; o selectie din alta
    // categorie ar da un dashboard gol, asa ca se revine la "Toate vehiculele".
    function syncVehicleOptions(category) {
        if (!(vehicleSelect instanceof HTMLSelectElement)) {
            return;
        }

        Array.prototype.forEach.call(vehicleSelect.options, function (option) {
            var optionCategory = option.getAttribute('data-vehicle-category');
            var visible = !optionCategory || category === 'toate' || optionCategory === category;
            option.hidden = !visible;
            option.disabled = !visible;
        });

        var current = vehicleSelect.options[vehicleSelect.selectedIndex];
        if (current && current.disabled) {
            vehicleSelect.value = '';
        }
    }

    function buildUrl() {
        var params = new URLSearchParams();
        var periodField = form.querySelector('[data-dashboard-period-input]');
        var isCustomPeriod = !!periodField && periodField.value === 'personalizat';
        new FormData(form).forEach(function (value, key) {
            if (key === 'vehicle_id' && value === '') {
                return;
            }
            // Datele perioadei personalizate conteaza doar cand ea este aleasa.
            if ((key === 'date_from' || key === 'date_to') && !isCustomPeriod) {
                return;
            }
            params.append(key, String(value));
        });

        return window.location.pathname + '?' + params.toString();
    }

    function parseNumber(text) {
        var clean = String(text || '').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
        var value = parseFloat(clean);
        return isFinite(value) ? value : null;
    }

    function formatMoney(value) {
        var fixed = Math.abs(value).toFixed(2).split('.');
        var integer = fixed[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return (value < 0 ? '-' : '') + integer + ',' + fixed[1];
    }

    function collectValues() {
        var values = {};
        root.querySelectorAll('[data-dashboard-count], [data-dashboard-money]').forEach(function (el) {
            var key = el.getAttribute('data-dashboard-count') !== null
                ? 'c:' + el.getAttribute('data-dashboard-count')
                : 'm:' + el.getAttribute('data-dashboard-money');
            var value = parseNumber(el.textContent);
            if (value !== null) {
                values[key] = value;
            }
        });

        return values;
    }

    function animateNumber(el, from, to, isMoney) {
        var finalText = el.textContent;
        if (reduceMotion || from === to) {
            return;
        }

        var duration = 650;
        var start = null;
        el.classList.remove('is-counting-up', 'is-counting-down');
        el.classList.add(to > from ? 'is-counting-up' : 'is-counting-down');

        function frame(now) {
            if (start === null) {
                start = now;
            }
            var t = Math.min(1, (now - start) / duration);
            var eased = 1 - Math.pow(1 - t, 3);
            var current = from + (to - from) * eased;
            el.textContent = isMoney ? formatMoney(current) : String(Math.round(current));

            if (t < 1 && el.isConnected) {
                window.requestAnimationFrame(frame);
                return;
            }

            // Textul final vine de la server, formatat exact ca la o incarcare normala.
            el.textContent = finalText;
            window.setTimeout(function () {
                el.classList.remove('is-counting-up', 'is-counting-down');
            }, 250);
        }

        el.textContent = isMoney ? formatMoney(from) : String(Math.round(from));
        window.requestAnimationFrame(frame);
    }

    function runNumberAnimations(oldValues) {
        root.querySelectorAll('[data-dashboard-count], [data-dashboard-money]').forEach(function (el) {
            var isMoney = el.getAttribute('data-dashboard-money') !== null;
            var key = isMoney ? 'm:' + el.getAttribute('data-dashboard-money') : 'c:' + el.getAttribute('data-dashboard-count');
            var to = parseNumber(el.textContent);
            var from = Object.prototype.hasOwnProperty.call(oldValues, key) ? oldValues[key] : 0;
            if (to !== null) {
                animateNumber(el, from, to, isMoney);
            }
        });
    }

    function measureSections() {
        return morphSections.map(function (section) {
            return section.getBoundingClientRect().height;
        });
    }

    // FLIP pe inaltimea sectiunilor: continutul se schimba instant, sectiunea
    // aluneca de la inaltimea veche la cea noua.
    function animateSectionHeights(before) {
        if (reduceMotion) {
            return;
        }

        morphSections.forEach(function (section, index) {
            var from = before[index];
            var to = section.getBoundingClientRect().height;
            if (!from || !to || Math.abs(from - to) < 2) {
                return;
            }

            section.style.height = from + 'px';
            section.style.overflow = 'hidden';
            section.getBoundingClientRect();
            section.style.transition = 'height 360ms cubic-bezier(0.22, 1, 0.36, 1)';
            section.style.height = to + 'px';

            var done = false;
            var cleanup = function () {
                if (done) {
                    return;
                }
                done = true;
                section.style.height = '';
                section.style.overflow = '';
                section.style.transition = '';
            };
            section.addEventListener('transitionend', cleanup, { once: true });
            window.setTimeout(cleanup, 420);
        });
    }

    function playMorph(el) {
        if (reduceMotion) {
            return;
        }

        el.classList.remove('is-dashboard-refreshed');
        void el.offsetWidth;
        el.classList.add('is-dashboard-refreshed');
        window.setTimeout(function () {
            el.classList.remove('is-dashboard-refreshed');
        }, 900);
    }

    function setLoading(loading) {
        root.classList.toggle('is-dashboard-loading', loading);
        morphSections.forEach(function (section) {
            section.setAttribute('aria-busy', loading ? 'true' : 'false');
        });
    }

    function applyResponse(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nextRoot = doc.querySelector('[data-dashboard-live-root]');
        if (!nextRoot) {
            return false;
        }

        var oldValues = collectValues();
        var heights = measureSections();
        var changed = [];

        root.querySelectorAll('[data-dashboard-live]').forEach(function (el) {
            var key = el.getAttribute('data-dashboard-live');
            var next = nextRoot.querySelector('[data-dashboard-live="' + key + '"]');
            if (!next || next.innerHTML === el.innerHTML) {
                return;
            }

            el.innerHTML = next.innerHTML;
            changed.push(el);
        });

        restoreInactiveView();
        animateSectionHeights(heights);
        changed.forEach(playMorph);
        runNumberAnimations(oldValues);

        return true;
    }

    function refresh() {
        var url = buildUrl();
        var seq = ++requestSeq;

        if (pendingController) {
            pendingController.abort();
        }
        pendingController = typeof AbortController === 'function' ? new AbortController() : null;

        setLoading(true);
        window.history.replaceState(null, '', url);

        window.fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: pendingController ? pendingController.signal : undefined
        })
            .then(function (response) {
                if (!response.ok || /[?&]page=login\b/.test(response.url)) {
                    throw new Error('fallback');
                }
                return response.text();
            })
            .then(function (html) {
                if (seq !== requestSeq) {
                    return;
                }
                if (!applyResponse(html)) {
                    throw new Error('fallback');
                }
                setLoading(false);
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                // Sesiune expirata sau raspuns neasteptat: incarcare clasica a paginii.
                window.location.assign(url);
            });
    }

    form.addEventListener('change', function (event) {
        var target = event.target;
        if (!(target instanceof HTMLInputElement) && !(target instanceof HTMLSelectElement)) {
            return;
        }
        if (target.name === 'vehicle_category') {
            syncVehicleOptions(target.value);
        }
        if (['period', 'vehicle_category', 'vehicle_id'].indexOf(target.name) !== -1) {
            refresh();
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        refresh();
    });

    if (resetLink) {
        resetLink.addEventListener('click', function (event) {
            event.preventDefault();

            resetPeriodField();
            var defaultCategory = form.querySelector('input[name="vehicle_category"][value="toate"]');
            if (defaultCategory) {
                defaultCategory.checked = true;
            }
            if (vehicleSelect instanceof HTMLSelectElement) {
                vehicleSelect.value = '';
            }
            syncVehicleOptions('toate');
            refresh();
        });
    }

    // Perioadă: același câmp ca în Carburanți — calendar de interval (flatpickr) cu
    // scurtături în interior. Un interval complet sau o scurtătură reîmprospătează
    // dashboard-ul pe loc. Chip-ul "Perioadă" (re-randat la fiecare refresh) deschide
    // același calendar, prin delegare pe formular.
    var periodDisplay = form.querySelector('[data-dashboard-period-display]');
    var periodInput = form.querySelector('[data-dashboard-period-input]');
    var periodFrom = form.querySelector('[data-dashboard-date-from]');
    var periodTo = form.querySelector('[data-dashboard-date-to]');
    var periodPicker = null;
    var pendingPreset = null;
    var MONTHS_RO = ['Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie',
        'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];

    function toIso(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function toRo(d) {
        return String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear();
    }

    function prettyLabel(start, end) {
        if (toIso(start) === toIso(end)) {
            return toRo(start);
        }
        var lastDay = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
        if (start.getDate() === 1 && end.getFullYear() === start.getFullYear()
            && end.getMonth() === start.getMonth() && end.getDate() === lastDay) {
            return MONTHS_RO[start.getMonth()] + ' ' + start.getFullYear();
        }
        return toRo(start) + ' – ' + toRo(end);
    }

    function periodPresets() {
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var monthLabel = function (d) { return MONTHS_RO[d.getMonth()] + ' ' + d.getFullYear(); };
        var thirtyAgo = new Date(today);
        thirtyAgo.setDate(today.getDate() - 29);
        var prevStart = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        return [
            { text: 'Luna aceasta', key: 'luna_curenta', start: new Date(today.getFullYear(), today.getMonth(), 1), end: today, label: monthLabel(today) },
            { text: 'Luna trecută', key: 'personalizat', start: prevStart, end: new Date(today.getFullYear(), today.getMonth(), 0), label: monthLabel(prevStart) },
            { text: 'Ultimele 30 de zile', key: 'ultimele_30_zile', start: thirtyAgo, end: today, label: 'Ultimele 30 de zile' },
            { text: 'Anul curent', key: 'an_curent', start: new Date(today.getFullYear(), 0, 1), end: today, label: 'Anul ' + today.getFullYear() }
        ];
    }

    function setPeriod(key, start, end, label, silent) {
        if (!periodInput || !periodFrom || !periodTo || !periodDisplay) {
            return;
        }
        var from = toIso(start);
        var to = toIso(end);
        var unchanged = periodInput.value === key && periodFrom.value === from && periodTo.value === to;
        periodInput.value = key;
        periodFrom.value = from;
        periodTo.value = to;
        // setDate rescrie inputul în formatul flatpickr: eticheta se pune după el.
        if (periodPicker) {
            periodPicker.setDate([start, end], false);
        }
        periodDisplay.value = label;
        periodDisplay.defaultValue = label;
        if (!silent && !unchanged) {
            refresh();
        }
    }

    function resetPeriodField() {
        var current = periodPresets()[0];
        setPeriod(current.key, current.start, current.end, current.label, true);
    }

    if (periodDisplay && periodInput && periodFrom && periodTo) {
        if (window.flatpickr) {
            periodPicker = window.flatpickr(periodDisplay, {
                mode: 'range',
                locale: window.flatpickr.l10ns && window.flatpickr.l10ns.ro ? 'ro' : 'default',
                dateFormat: 'Y-m-d',
                defaultDate: periodFrom.value && periodTo.value ? [periodFrom.value, periodTo.value] : [],
                onReady: function (selectedDates, dateStr, fp) {
                    var presets = document.createElement('div');
                    presets.className = 'fuel-fp-presets dashboard-fp-presets';
                    periodPresets().forEach(function (preset, index) {
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = preset.text;
                        button.addEventListener('click', function () {
                            pendingPreset = periodPresets()[index]; // datele de azi, la click
                            fp.close();
                        });
                        presets.appendChild(button);
                    });
                    fp.calendarContainer.appendChild(presets);
                },
                onClose: function (selectedDates) {
                    if (pendingPreset) {
                        var preset = pendingPreset;
                        pendingPreset = null;
                        setPeriod(preset.key, preset.start, preset.end, preset.label, false);
                        return;
                    }
                    if (selectedDates.length === 2) {
                        // deschis și închis fără schimbare: păstrează perioada (și cheia ei)
                        if (toIso(selectedDates[0]) === periodFrom.value && toIso(selectedDates[1]) === periodTo.value) {
                            periodDisplay.value = periodDisplay.defaultValue;
                            return;
                        }
                        setPeriod('personalizat', selectedDates[0], selectedDates[1], prettyLabel(selectedDates[0], selectedDates[1]), false);
                        return;
                    }
                    // selecție incompletă: revine la perioada curentă
                    if (periodFrom.value && periodTo.value) {
                        periodPicker.setDate([periodFrom.value, periodTo.value], false);
                    }
                    periodDisplay.value = periodDisplay.defaultValue;
                }
            });
            // flatpickr își scrie propriul format; readucem eticheta de la server.
            periodDisplay.value = periodDisplay.defaultValue;
        } else {
            // Fără CDN: cele două date devin calendare native vizibile.
            periodDisplay.closest('.fuel-date-input').hidden = true;
            [periodFrom, periodTo].forEach(function (input) {
                input.type = 'date';
                input.classList.add('dashboard-select');
                input.addEventListener('change', function () {
                    if (periodFrom.value && periodTo.value && periodFrom.value <= periodTo.value) {
                        periodInput.value = 'personalizat';
                        refresh();
                    }
                });
            });
        }

        form.addEventListener('click', function (event) {
            var chip = event.target instanceof Element ? event.target.closest('[data-dashboard-period-picker]') : null;
            if (!chip) {
                return;
            }
            event.preventDefault();
            if (periodPicker) {
                periodPicker.open();
            } else {
                periodFrom.focus();
            }
        });
    }

    // Cardurile "Status vehicule" si "Status soferi" au mai multe fete: sumarul, lista
    // inactivilor cu documentul / motivul concret si (la vehicule) totalul / activele pe tip.
    // Click pe "Inactive" / un motiv / "Active" / "Total" intoarce cardul pe loc; motivul ales devine
    // filtrul listei. Ctrl/Shift-click pastreaza linkul clasic.
    function applyInactiveFilter(card, reason) {
        var face = card.querySelector('[data-dashboard-inactive-face]');
        if (!face) {
            return;
        }

        var visible = 0;
        face.querySelectorAll('.dashboard-inactive-unit').forEach(function (unit) {
            var keys = (unit.getAttribute('data-dashboard-inactive-reasons') || '').split(' ');
            var show = reason === 'all' || keys.indexOf(reason) !== -1;
            unit.hidden = !show;
            if (show) {
                visible++;
            }
            unit.querySelectorAll('[data-dashboard-inactive-reason]').forEach(function (issue) {
                issue.classList.toggle('is-muted', reason !== 'all' && issue.getAttribute('data-dashboard-inactive-reason') !== reason);
            });
        });

        face.querySelectorAll('[data-dashboard-inactive-filter]').forEach(function (button) {
            var active = button.getAttribute('data-dashboard-inactive-filter') === reason;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        var counter = face.querySelector('[data-dashboard-inactive-visible]');
        if (counter) {
            counter.textContent = String(visible);
        }
    }

    function replayClass(el, className, duration) {
        if (!el || reduceMotion) {
            return;
        }
        el.classList.remove(className);
        void el.offsetWidth;
        el.classList.add(className);
        window.setTimeout(function () {
            el.classList.remove(className);
        }, duration);
    }

    // Fata afisata e tinuta pe card in data-card-face ("inactive:<motiv>", "active", "total"),
    // ca sa supravietuiasca reincarcarii live a continutului.
    function setCardFace(card, faceName, reason, animate) {
        var summary = card.querySelector('[data-dashboard-summary-face]');
        var face = faceName ? card.querySelector('[data-dashboard-face="' + faceName + '"]') : null;
        if (!summary || (faceName && !face)) {
            return;
        }

        if (faceName === 'inactive' && reason !== 'all' && !face.querySelector('[data-dashboard-inactive-filter="' + reason + '"]')) {
            reason = 'all';
        }

        var previous = card.querySelector('[data-dashboard-face]:not([hidden])') || summary;
        var heights = animate ? measureSections() : null;

        card.querySelectorAll('[data-dashboard-face]').forEach(function (other) {
            other.hidden = other !== face;
        });
        summary.hidden = !!face;

        if (faceName === 'inactive') {
            applyInactiveFilter(card, reason);
        }
        if (faceName) {
            card.setAttribute('data-card-face', faceName + (faceName === 'inactive' ? ':' + reason : ''));
        } else {
            card.removeAttribute('data-card-face');
        }
        card.classList.toggle('is-face-flipped', !!face);

        if (!animate) {
            return;
        }

        var current = face || summary;
        animateSectionHeights(heights);
        if (previous !== current) {
            replayClass(card, 'is-card-turning', 560);
        }
        replayClass(current, 'is-face-entering', 700);

        var focusTarget = face
            ? face.querySelector('[data-dashboard-face-close]')
            : summary.querySelector('[data-dashboard-inactive-open="all"], [data-dashboard-active-open], [data-dashboard-face-open]');
        if (focusTarget) {
            focusTarget.focus({ preventScroll: true });
        }
    }

    function restoreInactiveView() {
        root.querySelectorAll('[data-dashboard-flip-card][data-card-face]').forEach(function (card) {
            var state = (card.getAttribute('data-card-face') || '').split(':');
            setCardFace(card, state[0], state[1] || 'all', false);
        });
    }

    root.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var card = target ? target.closest('[data-dashboard-flip-card]') : null;
        if (!card) {
            return;
        }

        var opener = target.closest('[data-dashboard-inactive-open], [data-dashboard-active-open], [data-dashboard-face-open]');
        if (opener) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                return;
            }
            event.preventDefault();
            if (opener.hasAttribute('data-dashboard-face-open')) {
                setCardFace(card, opener.getAttribute('data-dashboard-face-open'), null, true);
            } else if (opener.hasAttribute('data-dashboard-active-open')) {
                setCardFace(card, opener.getAttribute('data-dashboard-active-open') || 'active', null, true);
            } else {
                setCardFace(card, 'inactive', opener.getAttribute('data-dashboard-inactive-open'), true);
            }
            return;
        }

        if (target.closest('[data-dashboard-face-close]')) {
            event.preventDefault();
            setCardFace(card, null, null, true);
            return;
        }

        var filterButton = target.closest('[data-dashboard-inactive-filter]');
        if (filterButton) {
            var reason = filterButton.getAttribute('data-dashboard-inactive-filter');
            var heights = measureSections();
            card.setAttribute('data-card-face', 'inactive:' + reason);
            applyInactiveFilter(card, reason);
            animateSectionHeights(heights);
            replayClass(card.querySelector('.dashboard-inactive-list'), 'is-face-entering', 700);
            return;
        }

        // In interiorul unui tip desfacut: filtrul pe categoria de capacitate arata doar
        // numerele din categoria aleasa si muta linkul spre lista de vehicule pe ea.
        var capacityButton = target.closest('[data-dashboard-capacity-filter]');
        if (capacityButton) {
            var platesBox = capacityButton.closest('.dashboard-active-type-plates');
            if (!platesBox) {
                return;
            }
            var capacity = capacityButton.getAttribute('data-dashboard-capacity-filter');
            var sectionsBefore = measureSections();

            platesBox.querySelectorAll('[data-dashboard-capacity-filter]').forEach(function (button) {
                var isCurrent = button === capacityButton;
                button.classList.toggle('is-active', isCurrent);
                button.setAttribute('aria-pressed', isCurrent ? 'true' : 'false');
            });
            platesBox.querySelectorAll('[data-capacity]').forEach(function (plate) {
                plate.hidden = capacity !== 'all' && plate.getAttribute('data-capacity') !== capacity;
            });

            var listLink = platesBox.querySelector('[data-dashboard-capacity-list]');
            if (listLink) {
                listLink.href = capacityButton.getAttribute('data-list-url') || listLink.href;
                var listCount = listLink.querySelector('[data-dashboard-capacity-list-count]');
                if (listCount) {
                    listCount.textContent = capacityButton.getAttribute('data-list-count') || '';
                }
            }

            animateSectionHeights(sectionsBefore);
            replayClass(platesBox.querySelector('.dashboard-plate-grid'), 'is-face-entering', 600);
            return;
        }

        // Fata "active": un tip de vehicul se desface in numerele de inmatriculare.
        var typeToggle = target.closest('[data-dashboard-type-toggle]');
        if (typeToggle) {
            var row = typeToggle.closest('.dashboard-active-type');
            var plates = row ? row.querySelector('.dashboard-active-type-plates') : null;
            if (!plates) {
                return;
            }
            var expand = plates.hidden;
            var before = measureSections();
            plates.hidden = !expand;
            row.classList.toggle('is-expanded', expand);
            row.querySelectorAll('[data-dashboard-type-toggle]').forEach(function (toggle) {
                toggle.setAttribute('aria-expanded', expand ? 'true' : 'false');
            });
            animateSectionHeights(before);
            if (expand) {
                replayClass(plates, 'is-face-entering', 600);
            }
        }
    });

    root.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        var card = event.target instanceof Element ? event.target.closest('[data-dashboard-flip-card]') : null;
        if (card && card.classList.contains('is-face-flipped')) {
            setCardFace(card, null, null, true);
        }
    });

    syncVehicleOptions(selectedCategory());
})();
