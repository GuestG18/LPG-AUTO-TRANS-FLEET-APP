/*
 * Cautare live in listele de modul (Vehicule usoare / Vehicule grele).
 *
 * Filtrarea ramane pe server: tabelul e paginat din baza de date, deci cerem aceeasi ruta cu
 * aceiasi parametri GET ca trimiterea clasica a formularului (cautare + filtre, pagina 1) si
 * inlocuim doar cardul cu rezultate (tabel + total + paginare). `live=1` ii spune serverului sa
 * sara peste lucrul care nu afecteaza tabelul (sincronizarea statusurilor, panoul de inactive).
 *
 * Markup asteptat:
 *   <form data-live-search data-live-search-target="#idRezultate"> ... <input data-live-search-input> ...
 *   <div id="idRezultate" data-live-search-results> ... </div>
 * Optional: [data-live-search-spinner], [data-live-search-status], [data-live-search-total],
 *           [data-live-search-region][id] (zone in afara cardului inlocuite odata cu el, ex. modale),
 *           [data-live-search-sync-href="cheie"] (link-uri a caror adresa urmeaza filtrele, ex. Export CSV).
 * Dupa fiecare inlocuire se emite pe document evenimentul `module-live-search:updated`.
 */
(function () {
    'use strict';

    var SEARCH_DELAY = 250;
    var LOADING_DELAY = 150;

    function init(form) {
        var input = form.querySelector('[data-live-search-input]');
        var results = document.querySelector(form.getAttribute('data-live-search-target') || '');
        if (!(input instanceof HTMLInputElement) || !(results instanceof HTMLElement) || results.id === '') {
            return;
        }

        var spinner = form.querySelector('[data-live-search-spinner]');
        var status = form.querySelector('[data-live-search-status]');
        var timer = 0;
        var loadingTimer = 0;
        var controller = null;
        var requestNo = 0;
        var composing = false;

        // Parametrii exact ca la trimiterea formularului, cu cautarea curatata si fara pagina:
        // orice schimbare a cautarii reporneste de la prima pagina.
        function buildParams() {
            var params = new URLSearchParams();
            new FormData(form).forEach(function (value, key) {
                if (typeof value !== 'string') {
                    return;
                }
                var clean = key === input.name ? value.trim() : value;
                if (clean === '' && key !== 'page') {
                    return;
                }
                params.append(key, clean);
            });
            params.delete('p');
            params.delete('live');
            return params;
        }

        var lastKey = buildParams().toString();

        function setLoading(isLoading) {
            window.clearTimeout(loadingTimer);
            results.setAttribute('aria-busy', isLoading ? 'true' : 'false');
            if (!isLoading) {
                results.classList.remove('is-loading');
                if (spinner) { spinner.hidden = true; }
                return;
            }
            // Indicatorul apare doar daca raspunsul intarzie, ca sa nu clipeasca la fiecare tasta.
            loadingTimer = window.setTimeout(function () {
                results.classList.add('is-loading');
                if (spinner) { spinner.hidden = false; }
            }, LOADING_DELAY);
        }

        function swapResults(fresh, doc) {
            var oldWrap = results.querySelector('.table-responsive');
            var scrollLeft = oldWrap ? oldWrap.scrollLeft : 0;

            results.innerHTML = fresh.innerHTML;

            var newWrap = results.querySelector('.table-responsive');
            if (newWrap && scrollLeft > 0) {
                newWrap.scrollLeft = scrollLeft;
            }

            // Zone legate de randuri dar randate in afara tabelului (ex. ferestrele modale ale randurilor).
            document.querySelectorAll('[data-live-search-region][id]').forEach(function (region) {
                var freshRegion = doc.getElementById(region.id);
                region.innerHTML = freshRegion ? freshRegion.innerHTML : '';
            });

            document.querySelectorAll('[data-live-search-sync-href]').forEach(function (link) {
                var key = link.getAttribute('data-live-search-sync-href') || '';
                var freshLink = doc.querySelector('[data-live-search-sync-href="' + key + '"]');
                if (freshLink && freshLink.hasAttribute('href')) {
                    link.setAttribute('href', freshLink.getAttribute('href'));
                }
            });

            if (status) {
                var totalEl = results.querySelector('[data-live-search-total]');
                var total = totalEl ? totalEl.getAttribute('data-live-search-total') : null;
                status.textContent = total === null ? '' : (total === '1' ? '1 rezultat' : total + ' rezultate');
            }

            document.dispatchEvent(new CustomEvent('module-live-search:updated', { detail: { container: results } }));
        }

        function run() {
            window.clearTimeout(timer);
            timer = 0;

            var params = buildParams();
            var key = params.toString();
            if (key === lastKey) {
                return;
            }
            lastKey = key;

            var pageUrl = window.location.pathname + '?' + key;
            var fetchParams = new URLSearchParams(params);
            fetchParams.set('live', '1');
            var fetchUrl = window.location.pathname + '?' + fetchParams.toString();

            if (controller) {
                controller.abort();
            }
            controller = typeof AbortController === 'function' ? new AbortController() : null;
            var myRequest = ++requestNo;
            setLoading(true);

            fetch(fetchUrl, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller ? controller.signal : undefined
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text().then(function (html) {
                    return { html: html, url: response.url };
                });
            }).then(function (payload) {
                // Un raspuns mai vechi nu are voie sa suprascrie rezultatele unei cautari mai noi.
                if (myRequest !== requestNo) {
                    return;
                }

                var doc = new DOMParser().parseFromString(payload.html, 'text/html');
                var fresh = doc.getElementById(results.id);
                if (!fresh) {
                    // Sesiune expirata / redirect: incarcarea completa arata pagina corecta (ex. login).
                    window.location.href = pageUrl;
                    return;
                }

                swapResults(fresh, doc);
                try {
                    window.history.replaceState(window.history.state, '', pageUrl);
                } catch (error) {
                    // URL-ul ramane cel vechi; tabelul e deja actualizat.
                }
                setLoading(false);
            }).catch(function (error) {
                if (myRequest !== requestNo || (error && error.name === 'AbortError')) {
                    return;
                }
                setLoading(false);
                // Urmatoarea tasta reincearca aceeasi cautare.
                lastKey = null;
                if (window.console) {
                    window.console.warn('[module-live-search]', error);
                }
            });
        }

        function schedule() {
            if (composing) {
                return;
            }
            window.clearTimeout(timer);
            timer = window.setTimeout(run, SEARCH_DELAY);
        }

        // Orice camp al formularului (cautare, select-uri, casute multiselect, date) actualizeaza tabelul.
        // `input` + `change` pot veni amandoua pentru acelasi camp: cheia identica opreste a doua cerere.
        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
        input.addEventListener('compositionstart', function () { composing = true; });
        input.addEventListener('compositionend', function () {
            composing = false;
            schedule();
        });

        // Enter (sau orice trimitere a formularului) nu reincarca pagina: cauta imediat.
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            run();
        });
        input.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || event.isComposing) {
                return;
            }
            event.preventDefault();
            run();
        });

        // „Resetează”: goleste cautarea si filtrele vizibile pe loc. Selectia venita din Dashboard
        // (filtre ascunse, cu bannerul ei deasupra listei) se reseteaza tot prin incarcarea paginii.
        var resetLink = form.querySelector('[data-live-search-reset]');
        if (resetLink) {
            resetLink.addEventListener('click', function (event) {
                var hasHiddenSelection = Array.prototype.some.call(
                    form.querySelectorAll('input[type="hidden"]:not([name="page"]):not([name="action"])'),
                    function (field) { return field.value !== ''; }
                );
                if (hasHiddenSelection || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
                    return;
                }
                event.preventDefault();

                input.value = '';
                form.querySelectorAll('select').forEach(function (select) {
                    select.selectedIndex = 0;
                });
                form.querySelectorAll('input').forEach(function (field) {
                    if (field.type === 'hidden' || field === input) {
                        return;
                    }
                    if (field.type === 'checkbox' || field.type === 'radio') {
                        if (field.checked) {
                            field.checked = false;
                            // Eticheta multiselect-ului („Toate”) se reface din evenimentul change.
                            field.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                        return;
                    }
                    field.value = '';
                });
                run();
                input.focus();
            });
        }
    }

    document.querySelectorAll('form[data-live-search]').forEach(init);
})();
