/*
 * Fisa cursei peste Desfasuratorul de curse: clic pe un rand si fisa creste din
 * rand (ca o aplicatie pe telefon), cu actiunile cursei (Editeaza, Reia cursa,
 * Sterge); la inchidere se strange inapoi in rand. Ctrl/Cmd + clic deschide
 * fisa ca pagina completa intr-un tab nou.
 */
(function () {
    'use strict';

    var OPEN_MS = 460;
    var CLOSE_MS = 340;
    var EASE_OPEN = 'cubic-bezier(0.2, 0.95, 0.25, 1.04)';
    var EASE_CLOSE = 'cubic-bezier(0.4, 0, 0.6, 0.4)';

    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var active = null;

    var closest = function (target, selector) {
        return target instanceof Element ? target.closest(selector) : null;
    };

    // Rect-ul de unde creste panoul: partea VIZIBILA a randului apasat. Tabelul se
    // deruleaza orizontal, deci decupam randul la containerele care il taie si la ecran.
    var originRectFor = function (originEl) {
        if (!(originEl instanceof HTMLElement) || !originEl.isConnected) {
            return null;
        }
        var rect = originEl.getBoundingClientRect();
        var left = rect.left;
        var top = rect.top;
        var right = rect.right;
        var bottom = rect.bottom;

        for (var parentEl = originEl.parentElement; parentEl && parentEl !== document.body; parentEl = parentEl.parentElement) {
            var style = window.getComputedStyle(parentEl);
            if (style.overflowX === 'visible' && style.overflowY === 'visible') {
                continue;
            }
            var clipRect = parentEl.getBoundingClientRect();
            left = Math.max(left, clipRect.left);
            top = Math.max(top, clipRect.top);
            right = Math.min(right, clipRect.right);
            bottom = Math.min(bottom, clipRect.bottom);
        }
        left = Math.max(left, 0);
        top = Math.max(top, 0);
        right = Math.min(right, window.innerWidth);
        bottom = Math.min(bottom, window.innerHeight);

        if (right - left < 8 || bottom - top < 8) {
            return null;
        }
        return { left: left, top: top, width: right - left, height: bottom - top };
    };

    // Transformarea care asaza panoul (in pozitia finala) peste rect-ul de origine.
    var transformToRect = function (sheetEl, rect) {
        var finalRect = sheetEl.getBoundingClientRect();
        if (rect === null) {
            // Fara origine vizibila: pornim/terminam usor micsorat, centrat.
            return 'translate(' + (finalRect.width * 0.06) + 'px, ' + (finalRect.height * 0.06) + 'px) scale(0.88)';
        }
        var scaleX = Math.max(rect.width / finalRect.width, 0.01);
        var scaleY = Math.max(rect.height / finalRect.height, 0.01);
        return 'translate(' + (rect.left - finalRect.left) + 'px, ' + (rect.top - finalRect.top) + 'px) '
            + 'scale(' + scaleX + ', ' + scaleY + ')';
    };

    var buildOverlay = function (fullUrl) {
        var overlayEl = document.createElement('div');
        overlayEl.className = 'race-view-overlay';
        overlayEl.innerHTML = ''
            + '<div class="race-view-backdrop" data-race-view-close></div>'
            + '<div class="race-view-sheet is-icon" role="dialog" aria-modal="true" aria-label="Fișa cursei" tabindex="-1">'
            + '  <div class="race-view-grabber" aria-hidden="true"></div>'
            + '  <div class="race-view-body">'
            + '    <div class="race-view-loading">'
            + '      <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>'
            + '      <div>Se încarcă fișa cursei…</div>'
            + '    </div>'
            + '  </div>'
            + '</div>';
        overlayEl.dataset.fullUrl = fullUrl;
        return overlayEl;
    };

    var loadContent = function (state) {
        var url = new URL(state.url, window.location.href);
        url.searchParams.set('partial', '1');

        fetch(url.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (response) {
                if (!response.ok || response.redirected) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            })
            .then(function (html) {
                if (active !== state) {
                    return;
                }
                // Focusul ramane pe panou: focus pe butonul din antetul sticky
                // deruleaza fisa in Chrome, chiar si cu preventScroll.
                state.body.innerHTML = html;
                // Dupa stergere ne intoarcem in lista cu filtrele de acum.
                Array.prototype.forEach.call(state.body.querySelectorAll('[data-race-view-return-url]'), function (inputEl) {
                    inputEl.value = window.location.pathname + window.location.search;
                });
            })
            .catch(function () {
                if (active !== state) {
                    return;
                }
                state.body.innerHTML = ''
                    + '<div class="race-view-loading">'
                    + '  <i class="bi bi-exclamation-triangle fs-2 text-warning" aria-hidden="true"></i>'
                    + '  <div>Fișa cursei nu a putut fi încărcată.</div>'
                    + '  <div class="d-flex gap-2">'
                    + '    <a class="btn btn-sm btn-outline-primary" href="' + state.url.replace(/"/g, '&quot;') + '">Deschide pagina cursei</a>'
                    + '    <button type="button" class="btn btn-sm btn-outline-secondary" data-race-view-close>Închide</button>'
                    + '  </div>'
                    + '</div>';
            });
    };

    var open = function (url, originEl) {
        if (active !== null) {
            return;
        }

        var overlayEl = buildOverlay(url);
        document.body.appendChild(overlayEl);
        var sheetEl = overlayEl.querySelector('.race-view-sheet');
        var bodyEl = overlayEl.querySelector('.race-view-body');

        var state = {
            url: url,
            overlay: overlayEl,
            sheet: sheetEl,
            body: bodyEl,
            origin: originEl,
            lastFocus: document.activeElement,
            bodyOverflow: document.body.style.overflow
        };
        active = state;
        document.body.style.overflow = 'hidden';

        if (reducedMotion) {
            sheetEl.classList.remove('is-icon');
            overlayEl.classList.add('is-open');
        } else {
            sheetEl.style.transition = 'none';
            sheetEl.style.borderRadius = '40%';
            sheetEl.style.transform = transformToRect(sheetEl, originRectFor(originEl));
            sheetEl.getBoundingClientRect(); // fixeaza pozitia de start inainte de tranzitie

            requestAnimationFrame(function () {
                sheetEl.style.transition = 'transform ' + OPEN_MS + 'ms ' + EASE_OPEN
                    + ', border-radius ' + OPEN_MS + 'ms ' + EASE_OPEN;
                sheetEl.style.transform = 'none';
                sheetEl.style.borderRadius = '';
                sheetEl.classList.remove('is-icon');
                overlayEl.classList.add('is-open');
            });
        }

        sheetEl.focus({ preventScroll: true });
        loadContent(state);
    };

    var close = function () {
        var state = active;
        if (state === null || state.closing) {
            return;
        }
        state.closing = true;

        var finish = function () {
            state.overlay.remove();
            document.body.style.overflow = state.bodyOverflow;
            if (active === state) {
                active = null;
            }
            var focusEl = state.origin instanceof HTMLElement && state.origin.isConnected ? state.origin : state.lastFocus;
            if (focusEl instanceof HTMLElement) {
                focusEl.focus({ preventScroll: true });
            }
        };

        state.overlay.classList.add('is-closing');
        state.overlay.classList.remove('is-open');

        if (reducedMotion) {
            finish();
            return;
        }

        var sheetEl = state.sheet;
        // Panoul nu mai primeste clicuri cat se strange.
        state.overlay.style.pointerEvents = 'none';
        var closeOriginRect = originRectFor(state.origin);
        var targetTransform = transformToRect(sheetEl, closeOriginRect);
        sheetEl.style.transition = 'transform ' + CLOSE_MS + 'ms ' + EASE_CLOSE
            + ', border-radius ' + CLOSE_MS + 'ms ' + EASE_CLOSE
            + ', opacity ' + CLOSE_MS + 'ms ease';
        requestAnimationFrame(function () {
            sheetEl.classList.add('is-icon');
            sheetEl.style.transform = targetTransform;
            sheetEl.style.borderRadius = '40%';
            if (closeOriginRect === null) {
                sheetEl.style.opacity = '0';
            }
        });
        window.setTimeout(finish, CLOSE_MS + 20);
    };

    // Ce se apasa intr-un rand si NU deschide fisa: controalele proprii ale celulelor
    // (selectia pentru stergere in masa, "N detalii", fazele, diurna, linkuri).
    var INTERACTIVE_SELECTOR = 'a, button, input, select, textarea, label, summary, [role="button"], '
        + '[contenteditable], [data-dispatcher-summary-popover], [data-race-view-ignore]';
    var OPEN_POPOVER_SELECTOR = '[data-dispatcher-summary-popover]:not([hidden])';

    var raceViewUrl = function (raceId) {
        return window.location.pathname + '?page=dispecer_curse&action=view&id=' + encodeURIComponent(raceId);
    };

    var raceIdForRow = function (rowEl) {
        return rowEl.getAttribute('data-race-id') || rowEl.getAttribute('data-segments-for') || '';
    };

    // Un clic care doar inchide un popover "N detalii" deschis nu deschide si fisa.
    var popoverOpenAtPress = false;
    document.addEventListener('pointerdown', function () {
        popoverOpenAtPress = document.querySelector(OPEN_POPOVER_SELECTOR) !== null;
    }, true);

    document.addEventListener('click', function (event) {
        if (active !== null) {
            if (closest(event.target, '[data-race-view-close]')) {
                event.preventDefault();
                close();
            }
            return;
        }

        var rowEl = closest(event.target, '[data-dispatcher-column-table] tbody tr[data-race-id], [data-dispatcher-column-table] tbody tr[data-segments-for]');
        if (!(rowEl instanceof HTMLTableRowElement) || event.defaultPrevented || event.button !== 0) {
            return;
        }
        if (closest(event.target, INTERACTIVE_SELECTOR) || popoverOpenAtPress) {
            return;
        }
        // Textul selectat cu mouse-ul (copiere) nu e un clic pe rand.
        var selection = window.getSelection ? window.getSelection() : null;
        if (selection && !selection.isCollapsed && rowEl.contains(selection.anchorNode)) {
            return;
        }

        var raceId = raceIdForRow(rowEl);
        if (raceId === '') {
            return;
        }
        if (event.ctrlKey || event.metaKey) {
            window.open(raceViewUrl(raceId), '_blank', 'noopener');
            return;
        }
        open(raceViewUrl(raceId), rowEl);
    });

    document.addEventListener('keydown', function (event) {
        if (active === null) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            close();
            return;
        }
        // Tab ramane in panou cat timp e deschis.
        if (event.key === 'Tab') {
            var focusables = Array.prototype.filter.call(
                active.sheet.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'),
                function (el) { return el.offsetParent !== null; }
            );
            if (focusables.length === 0) {
                event.preventDefault();
                return;
            }
            var first = focusables[0];
            var last = focusables[focusables.length - 1];
            if (event.shiftKey && (document.activeElement === first || document.activeElement === active.sheet)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }, true);
})();
