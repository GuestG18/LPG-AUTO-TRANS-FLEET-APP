/*
 * Fisa cursei peste Desfasuratorul de curse: clic pe un rand si fisa creste din
 * rand (ca o aplicatie pe telefon), cu actiunile cursei (Editeaza, Reia cursa,
 * Sterge); la inchidere se strange inapoi in rand. Ctrl/Cmd + clic deschide
 * fisa ca pagina completa intr-un tab nou.
 */
(function () {
    'use strict';

    var OPEN_MS = 460;
    var CLOSE_MS = 480;
    var LOGO_HOLD_MS = 420;   // cat ramane logo-ul vizibil in rand dupa ce panoul s-a strans
    var LOGO_FADE_MS = 220;
    var LOGO_RATIO = 272 / 720; // inaltime / latime pentru lpg-auto-trans-logo.png
    var EASE_OPEN = 'cubic-bezier(0.2, 0.95, 0.25, 1.04)';
    var EASE_CLOSE = 'cubic-bezier(0.45, 0, 0.2, 1)';

    // Logo-ul firmei, in culorile originale, pe placa albastru-deschis in care
    // se strange fisa la inchidere.
    // Calea se rezolva fata de acest script, ca sa mearga si local (/htdocs) si pe VPS.
    var scriptSrc = document.currentScript ? document.currentScript.src : '';
    var LOGO_URL = scriptSrc !== ''
        ? new URL('../img/lpg-auto-trans-logo.png', scriptSrc).toString()
        : 'assets/img/lpg-auto-trans-logo.png';
    new Image().src = LOGO_URL; // preincarcat, ca sa apara instant la prima inchidere

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
    var transformToRect = function (finalRect, rect) {
        if (rect === null) {
            // Fara origine vizibila: pornim/terminam usor micsorat, centrat.
            return 'translate(' + (finalRect.width * 0.06) + 'px, ' + (finalRect.height * 0.06) + 'px) scale(0.88)';
        }
        var scaleX = Math.max(rect.width / finalRect.width, 0.01);
        var scaleY = Math.max(rect.height / finalRect.height, 0.01);
        return 'translate(' + (rect.left - finalRect.left) + 'px, ' + (rect.top - finalRect.top) + 'px) '
            + 'scale(' + scaleX + ', ' + scaleY + ')';
    };

    // Placa din rand: dreptunghi cu colturi de ~12px dupa scalare (raza pe fiecare
    // axa compenseaza scalarea neuniforma, altfel ar iesi o elipsa).
    var radiusForRect = function (finalRect, rect) {
        if (rect === null) {
            return '40%';
        }
        var radiusPx = Math.min(12, rect.height / 2);
        return (radiusPx * finalRect.width / rect.width) + 'px / '
            + (radiusPx * finalRect.height / rect.height) + 'px';
    };

    // Logo-ul e un element separat, scalat UNIFORM (nu se deformeaza odata cu
    // panoul), asezat in centrul panoului; transformarea il duce in centrul randului.
    var createLogo = function (overlayEl, finalRect) {
        var logoWidth = Math.min(360, finalRect.width * 0.5);
        var logoHeight = logoWidth * LOGO_RATIO;
        var logoEl = document.createElement('img');
        logoEl.className = 'race-view-logo';
        logoEl.src = LOGO_URL;
        logoEl.alt = '';
        logoEl.style.width = logoWidth + 'px';
        logoEl.style.height = logoHeight + 'px';
        logoEl.style.left = (finalRect.left + finalRect.width / 2 - logoWidth / 2) + 'px';
        logoEl.style.top = (finalRect.top + finalRect.height / 2 - logoHeight / 2) + 'px';
        overlayEl.appendChild(logoEl);
        return logoEl;
    };

    var logoTransformToRect = function (logoEl, finalRect, rect) {
        if (rect === null) {
            return 'scale(0.88)';
        }
        var logoScale = Math.min(
            rect.width * 0.7 / parseFloat(logoEl.style.width),
            rect.height * 0.8 / parseFloat(logoEl.style.height)
        );
        var dx = (rect.left + rect.width / 2) - (finalRect.left + finalRect.width / 2);
        var dy = (rect.top + rect.height / 2) - (finalRect.top + finalRect.height / 2);
        return 'translate(' + dx + 'px, ' + dy + 'px) scale(' + logoScale + ')';
    };

    var buildOverlay = function () {
        var overlayEl = document.createElement('div');
        overlayEl.className = 'race-view-overlay';
        overlayEl.innerHTML = ''
            + '<div class="race-view-backdrop" data-race-view-close></div>'
            + '<div class="race-view-sheet is-icon" role="dialog" aria-modal="true" aria-label="Fișa cursei" aria-busy="true" tabindex="-1">'
            + '  <div class="race-view-grabber" aria-hidden="true"></div>'
            + '  <div class="race-view-body"></div>'
            + '  <div class="visually-hidden" role="status">Se încarcă fișa cursei…</div>'
            + '</div>';
        return overlayEl;
    };

    // Ecranul de pornire (placa albastru-deschis cu logo-ul) ramane pana sosesc
    // datele, dar cel putin cat dureaza animatia de deschidere.
    var revealContent = function (state) {
        if (state.revealed || active !== state) {
            return;
        }
        state.revealed = true;
        var waitMs = Math.max(0, state.splashUntil - Date.now());
        window.setTimeout(function () {
            if (active !== state || state.closing) {
                return;
            }
            state.sheet.removeAttribute('aria-busy');
            state.sheet.classList.remove('is-icon');
            state.overlay.classList.add('is-loaded');
            if (state.logo) {
                var logoEl = state.logo;
                state.logo = null;
                logoEl.classList.remove('is-visible', 'is-loading');
                window.setTimeout(function () { logoEl.remove(); }, LOGO_FADE_MS + 40);
            }
        }, waitMs);
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
                revealContent(state);
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
                revealContent(state);
            });
    };

    var open = function (url, originEl, raceId) {
        if (active !== null) {
            return;
        }

        var overlayEl = buildOverlay();
        document.body.appendChild(overlayEl);
        var sheetEl = overlayEl.querySelector('.race-view-sheet');
        var bodyEl = overlayEl.querySelector('.race-view-body');

        var state = {
            url: url,
            overlay: overlayEl,
            sheet: sheetEl,
            body: bodyEl,
            origin: originEl,
            raceId: String(raceId || ''),
            logo: null,
            revealed: false,
            splashUntil: Date.now() + (reducedMotion ? 0 : OPEN_MS + 120),
            lastFocus: document.activeElement,
            bodyOverflow: document.body.style.overflow
        };
        active = state;
        document.body.style.overflow = 'hidden';

        if (reducedMotion) {
            overlayEl.classList.add('is-open');
        } else {
            // Pornim din rand: placa albastru-deschis cu logo-ul, care creste in fisa.
            var finalRect = sheetEl.getBoundingClientRect();
            var openOriginRect = originRectFor(originEl);
            var logoEl = createLogo(overlayEl, finalRect);
            state.logo = logoEl;

            sheetEl.style.transition = 'none';
            sheetEl.style.borderRadius = radiusForRect(finalRect, openOriginRect);
            sheetEl.style.transform = transformToRect(finalRect, openOriginRect);
            logoEl.style.transition = 'none';
            logoEl.style.transform = logoTransformToRect(logoEl, finalRect, openOriginRect);
            logoEl.classList.add('is-visible');
            sheetEl.getBoundingClientRect(); // fixeaza pozitia de start inainte de tranzitie

            requestAnimationFrame(function () {
                sheetEl.style.transition = 'transform ' + OPEN_MS + 'ms ' + EASE_OPEN
                    + ', border-radius ' + OPEN_MS + 'ms ' + EASE_OPEN;
                sheetEl.style.transform = 'none';
                sheetEl.style.borderRadius = '';
                logoEl.style.transition = 'transform ' + OPEN_MS + 'ms ' + EASE_OPEN
                    + ', opacity ' + LOGO_FADE_MS + 'ms ease';
                logoEl.style.transform = 'none';
                overlayEl.classList.add('is-open');
                // Daca datele intarzie, logo-ul "respira" ca semn ca se incarca.
                window.setTimeout(function () {
                    if (state.logo === logoEl) {
                        logoEl.classList.add('is-loading');
                    }
                }, OPEN_MS);
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
            // Dupa ce animatia s-a terminat, randul ramane marcat ca vizualizat.
            markPreviewed(state.raceId, true);
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
        // Pozitia finala a panoului (fara transformare), chiar daca e inchis in timpul deschiderii.
        var currentTransform = sheetEl.style.transform;
        sheetEl.style.transition = 'none';
        sheetEl.style.transform = 'none';
        var sheetRect = sheetEl.getBoundingClientRect();
        sheetEl.style.transform = currentTransform;
        var closeOriginRect = originRectFor(state.origin);

        // Logo-ul ecranului de pornire (daca fisa inca se incarca) sau unul nou.
        var logoEl = state.logo || createLogo(state.overlay, sheetRect);
        state.logo = null;
        logoEl.classList.remove('is-loading');

        sheetEl.getBoundingClientRect(); // pozitia de start
        sheetEl.style.transition = 'transform ' + CLOSE_MS + 'ms ' + EASE_CLOSE
            + ', border-radius ' + CLOSE_MS + 'ms ' + EASE_CLOSE
            + ', opacity ' + LOGO_FADE_MS + 'ms ease';
        logoEl.style.transition = 'transform ' + CLOSE_MS + 'ms ' + EASE_CLOSE
            + ', opacity ' + LOGO_FADE_MS + 'ms ease';
        logoEl.getBoundingClientRect();

        requestAnimationFrame(function () {
            sheetEl.classList.add('is-icon');
            sheetEl.style.transform = transformToRect(sheetRect, closeOriginRect);
            sheetEl.style.borderRadius = radiusForRect(sheetRect, closeOriginRect);
            logoEl.classList.add('is-visible');
            logoEl.style.transform = logoTransformToRect(logoEl, sheetRect, closeOriginRect);
        });

        // Placa cu logo ramane o clipa in rand, apoi dispare usor.
        var holdMs = closeOriginRect !== null ? LOGO_HOLD_MS : 120;
        window.setTimeout(function () {
            sheetEl.style.opacity = '0';
            logoEl.style.opacity = '0';
        }, CLOSE_MS + holdMs);
        window.setTimeout(finish, CLOSE_MS + holdMs + LOGO_FADE_MS);
    };

    // Ultima cursa vizualizata: dunga albastra pe marginea randului si un ochi langa
    // numarul de inmatriculare, cu ora vizualizarii. Doar UN rand e marcat: cand
    // deschizi alta cursa, marcajul se muta pe ea. Se pastreaza si la reincarcare.
    var PREVIEWED_KEY = 'fleet.dispecerCurse.lastPreviewed.v1';
    var PREVIEWED_FLASH_MS = 2400;

    var loadLastPreviewed = function () {
        try {
            var data = JSON.parse(window.localStorage.getItem(PREVIEWED_KEY) || 'null');
            return data && data.id ? data : null;
        } catch (error) {
            return null;
        }
    };

    var saveLastPreviewed = function (data) {
        try {
            window.localStorage.setItem(PREVIEWED_KEY, JSON.stringify(data));
        } catch (error) {
            // localStorage poate lipsi (navigare privata): marcajul ramane doar pana la reincarcare.
        }
    };

    var clearPreviewedMarks = function () {
        Array.prototype.forEach.call(document.querySelectorAll('tr.is-previewed'), function (rowEl) {
            rowEl.classList.remove('is-previewed', 'is-just-previewed');
        });
    };

    // Ultima cursa vizualizata se recunoaste doar dupa culoarea randului: iconita cu ochi
    // (si tooltip-ul ei) a fost scoasa 2026-10-08, pentru ca marea inaltimea randului.
    var applyPreviewedMark = function (data, flash) {
        clearPreviewedMarks();
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-dispatcher-column-table] tbody tr[data-race-id="' + CSS.escape(data.id) + '"]'),
            function (rowEl) {
                rowEl.classList.add('is-previewed');
                if (flash) {
                    rowEl.classList.add('is-just-previewed');
                    window.setTimeout(function () { rowEl.classList.remove('is-just-previewed'); }, PREVIEWED_FLASH_MS);
                }
            }
        );
    };

    var markPreviewed = function (raceId, flash) {
        if (!raceId) {
            return;
        }
        var data = { id: String(raceId), at: Date.now() };
        saveLastPreviewed(data);
        applyPreviewedMark(data, flash);
    };

    // La incarcarea paginii refacem marcajul ultimei curse vizualizate.
    (function () {
        var data = loadLastPreviewed();
        if (data !== null) {
            applyPreviewedMark(data, false);
        }
    })();

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

    // In fisa cursei, clic pe randul unei faze deschide formularul direct pe acea faza.
    document.addEventListener('click', function (event) {
        var phaseRowEl = closest(event.target, 'tr[data-race-view-href]');
        if (!phaseRowEl || event.defaultPrevented || event.button !== 0 || closest(event.target, 'a, button, input, select, textarea, label')) {
            return;
        }
        if (window.getSelection && String(window.getSelection()).trim() !== '') {
            return;
        }
        event.preventDefault();
        var href = phaseRowEl.getAttribute('data-race-view-href') || '';
        if (event.ctrlKey || event.metaKey) {
            window.open(href, '_blank');
        } else {
            window.location.href = href;
        }
    });

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
            markPreviewed(raceId, true);
            return;
        }
        clearPreviewedMarks(); // marcajul vechi dispare cand deschizi alta cursa
        open(raceViewUrl(raceId), rowEl, raceId);
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
