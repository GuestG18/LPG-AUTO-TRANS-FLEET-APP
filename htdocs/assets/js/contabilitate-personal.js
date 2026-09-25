/**
 * Contabilitate Personal: randuri expandabile cu detaliu lunar incarcat la cerere.
 *
 * Detaliul unui angajat (Sumar, Pontaj & Calendar, Istoric salarial...) se cere de la
 * server doar cand randul este deschis, ca pagina sa nu incarce detaliile tuturor.
 * Bootstrap se incarca in footer, dupa acest script: modalele din fragment folosesc
 * data-bs-toggle (delegat de Bootstrap), deci nu depindem de el la initializare.
 */
(function () {
    'use strict';

    var page = document.querySelector('.cp-page');
    if (!page) {
        return;
    }

    var pagePeriod = page.getAttribute('data-cp-period') || '';
    var detailUrl = page.getAttribute('data-cp-detail-url') || '';

    // Detaliul extins nu trebuie sa fie mai lat decat zona vizibila a tabelului.
    var tableWrap = page.querySelector('.cp-table-card .table-responsive');
    function syncDetailWidth() {
        if (tableWrap) {
            page.style.setProperty('--cp-detail-width', Math.max(280, tableWrap.clientWidth - 24) + 'px');
        }
    }
    syncDetailWidth();
    window.addEventListener('resize', syncDetailWidth);

    // Numarul de persoane afisate urmeaza filtrele din antet.
    var table = page.querySelector('.cp-table[data-column-filter]');
    var counter = page.querySelector('[data-cp-row-count]');
    if (table && counter) {
        table.addEventListener('columnfilter:applied', function (event) {
            var detail = event.detail || {};
            counter.textContent = detail.visible === detail.total
                ? detail.total + ' persoane'
                : detail.visible + ' din ' + detail.total + ' persoane';
        });
    }

    // Selecturile din antet / filtre se aplica imediat.
    page.querySelectorAll('[data-cp-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.form) {
                select.form.submit();
            }
        });
    });

    function detailRowFor(row) {
        return row ? page.querySelector('[data-cp-detail-row="' + row.getAttribute('data-cp-row') + '"]') : null;
    }

    function loadDetail(row, period, tab) {
        var detailRow = detailRowFor(row);
        if (!detailRow) {
            return;
        }
        var host = detailRow.querySelector('[data-cp-detail-host]');
        var url = detailUrl
            + '&source_type=' + encodeURIComponent(row.getAttribute('data-source-type') || '')
            + '&source_id=' + encodeURIComponent(row.getAttribute('data-source-id') || '')
            + '&luna=' + encodeURIComponent(period || pagePeriod);

        host.setAttribute('aria-busy', 'true');
        host.classList.add('is-loading');
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                return response.text().then(function (html) {
                    if (!response.ok && !html) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return html;
                });
            })
            .then(function (html) {
                host.innerHTML = html;
                host.setAttribute('data-loaded', '1');
                if (tab) {
                    activateTab(host, tab);
                }
            })
            .catch(function () {
                host.innerHTML = '<div class="cp-alert is-warning m-3"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Detaliile nu au putut fi încărcate. Reîncearcă.</div>';
            })
            .finally(function () {
                host.removeAttribute('aria-busy');
                host.classList.remove('is-loading');
            });
    }

    function setExpanded(row, expanded, tab) {
        var detailRow = detailRowFor(row);
        if (!detailRow) {
            return;
        }
        var toggle = row.querySelector('[data-cp-expand]');
        row.classList.toggle('is-expanded', expanded);
        detailRow.hidden = !expanded;
        if (toggle) {
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }
        var host = detailRow.querySelector('[data-cp-detail-host]');
        if (expanded && host && host.getAttribute('data-loaded') !== '1') {
            loadDetail(row, pagePeriod, tab);
        } else if (expanded && tab && host) {
            activateTab(host, tab);
        }
    }

    function activateTab(scope, name) {
        var detail = scope.querySelector('[data-cp-detail]') || scope;
        var found = false;
        detail.querySelectorAll('[data-cp-tab]').forEach(function (button) {
            var active = button.getAttribute('data-cp-tab') === name;
            found = found || active;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        if (!found) {
            return;
        }
        detail.querySelectorAll('[data-cp-pane]').forEach(function (pane) {
            var active = pane.getAttribute('data-cp-pane') === name;
            pane.hidden = !active;
            pane.classList.toggle('is-active', active);
        });
    }

    page.addEventListener('click', function (event) {
        var target = event.target;

        var tabButton = target.closest('[data-cp-tab]');
        if (tabButton) {
            activateTab(tabButton.closest('[data-cp-detail-host]'), tabButton.getAttribute('data-cp-tab'));
            return;
        }

        var gotoButton = target.closest('[data-cp-goto]');
        if (gotoButton) {
            activateTab(gotoButton.closest('[data-cp-detail-host]'), gotoButton.getAttribute('data-cp-goto'));
            return;
        }

        var toggleButton = target.closest('[data-cp-toggle]');
        if (toggleButton) {
            var panel = document.getElementById(toggleButton.getAttribute('data-cp-toggle'));
            if (panel) {
                panel.hidden = !panel.hidden;
            }
            return;
        }

        var monthButton = target.closest('[data-cp-detail-month]');
        if (monthButton) {
            var detailRowForMonth = monthButton.closest('[data-cp-detail-row]');
            var mainRow = detailRowForMonth ? page.querySelector('[data-cp-row="' + detailRowForMonth.getAttribute('data-cp-detail-row') + '"]') : null;
            var currentTab = detailRowForMonth ? detailRowForMonth.querySelector('.cp-tab.is-active') : null;
            loadDetail(mainRow, monthButton.getAttribute('data-cp-detail-month'), currentTab ? currentTab.getAttribute('data-cp-tab') : null);
            return;
        }

        // Clic pe rand (nu pe butoane, linkuri sau meniuri) = expandare / restrangere.
        var row = target.closest('tr.cp-row');
        if (!row) {
            return;
        }
        var interactive = target.closest('a, button:not([data-cp-expand]), input, select, textarea, label, .dropdown, .dropdown-menu');
        if (interactive) {
            return;
        }
        setExpanded(row, !row.classList.contains('is-expanded'));
    });

    page.addEventListener('change', function (event) {
        var select = event.target.closest('[data-cp-detail-month-select]');
        if (select) {
            var detailRow = select.closest('[data-cp-detail-row]');
            var mainRow = detailRow ? page.querySelector('[data-cp-row="' + detailRow.getAttribute('data-cp-detail-row') + '"]') : null;
            var currentTab = detailRow ? detailRow.querySelector('.cp-tab.is-active') : null;
            loadDetail(mainRow, select.value, currentTab ? currentTab.getAttribute('data-cp-tab') : null);
            return;
        }

        var regime = event.target.closest('[data-cp-regime-select]');
        if (regime) {
            var details = regime.form ? regime.form.querySelector('[data-cp-regime-details]') : null;
            if (details) {
                details.hidden = regime.value !== 'personalizat';
                details.required = regime.value === 'personalizat';
            }
        }
    });

    // Formularele vechi (salariu, diurna, documente...) se intorc pe aceeasi luna.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || (form.method || '').toLowerCase() !== 'post') {
            return;
        }
        if ((form.getAttribute('action') || '').indexOf('page=contabilitate_personal') === -1 || form.querySelector('[name="luna"]')) {
            return;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'luna';
        input.value = pagePeriod;
        form.appendChild(input);
    }, true);

    // Revenire dupa salvare: ?open=driver-12&tab=pontaj redeschide randul.
    var params = new URLSearchParams(window.location.search);
    var openKey = (params.get('open') || '').replace(/[^a-zA-Z0-9_-]/g, '');
    if (openKey) {
        var openRow = page.querySelector('[data-cp-row="' + openKey + '"]');
        if (openRow) {
            setExpanded(openRow, true, (params.get('tab') || '').replace(/[^a-z_]/g, '') || null);
            openRow.scrollIntoView({ block: 'start' });
        }
    }
})();

/**
 * Panoul "Configurare salarii si contributii": taburi + redeschidere dupa salvare
 * (?config=fiscal|retineri|sporuri|beneficii|setari). Bootstrap se incarca in footer,
 * deci panoul se deschide la 'load'.
 */
(function () {
    'use strict';

    var panel = document.getElementById('cpPayrollConfig');
    if (!panel) {
        return;
    }

    panel.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-cp-config-tab]');
        if (!tab) {
            return;
        }
        var name = tab.getAttribute('data-cp-config-tab');
        panel.querySelectorAll('[data-cp-config-tab]').forEach(function (button) {
            button.classList.toggle('is-active', button === tab);
        });
        panel.querySelectorAll('[data-cp-config-pane]').forEach(function (pane) {
            pane.hidden = pane.getAttribute('data-cp-config-pane') !== name;
        });
    });

    if (panel.getAttribute('data-cp-open') === '1') {
        window.addEventListener('load', function () {
            if (window.bootstrap && window.bootstrap.Offcanvas) {
                window.bootstrap.Offcanvas.getOrCreateInstance(panel).show();
            }
        });
    }
})();
