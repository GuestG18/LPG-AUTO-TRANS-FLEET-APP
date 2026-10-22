/*
 * Stoc anvelope: inventar grupat.
 *  - grupurile (produs nemontat / vehicul) se extind la cerere; anvelopele lor vin din
 *    tire_stock_children cu filtrele curente (aceleasi ca lista);
 *  - starea extinsa se tine in sessionStorage dupa cheia stabila a grupului
 *    ("p:<produs>", "v:<vehicul>", subgrupuri "...|l:<locatie>" / "...|a:<axa>");
 *  - o cautare exacta dupa serie / id deschide grupul anvelopei fara sa-l memoreze;
 *  - actiunile de selectie pe grup trec prin window.fleetTireSelection (bara de actiuni).
 */
(function () {
    'use strict';

    var results = document.querySelector('[data-tire-results]');
    if (!results) { return; }

    var EXPANDED_KEY = 'fleet.tireStock.expandedGroups';
    var COLLAPSED_SUBS_KEY = 'fleet.tireStock.collapsedSubgroups';
    var generation = 0;

    function readSet(key) {
        try { return new Set(JSON.parse(window.sessionStorage.getItem(key) || '[]')); } catch (error) { return new Set(); }
    }
    function writeSet(key, set) {
        try { window.sessionStorage.setItem(key, JSON.stringify(Array.from(set).slice(-500))); } catch (error) { /* sesiune fara storage: doar nu se memoreaza */ }
    }
    var expanded = readSet(EXPANDED_KEY);
    var collapsedSubs = readSet(COLLAPSED_SUBS_KEY);

    function tbody() { return results.querySelector('tbody'); }
    function cssKey(key) { return window.CSS && CSS.escape ? CSS.escape(key) : key.replace(/"/g, '\\"'); }
    function groupRow(key) { return tbody().querySelector('tr.tire-group-row[data-tire-group-key="' + cssKey(key) + '"]'); }
    function childRows(key) { return tbody().querySelectorAll('tr[data-tire-child-of="' + cssKey(key) + '"]'); }
    function groupRows() { return Array.prototype.slice.call(tbody().querySelectorAll('tr.tire-group-row')); }

    // URL pentru endpoint-urile paginii, cu filtrele curente (URL-ul e tinut la zi de filtrarea live).
    function pageUrl(action, extra) {
        var params = new URLSearchParams(window.location.search);
        params.set('page', 'mentenanta');
        params.set('action', action);
        params.delete('p');
        Object.keys(extra || {}).forEach(function (key) {
            var value = extra[key];
            if (Array.isArray(value)) {
                params.delete(key + '[]');
                value.forEach(function (item) { params.append(key + '[]', item); });
            } else {
                params.set(key, value);
            }
        });
        return window.location.pathname + '?' + params.toString();
    }

    function getJson(url) {
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                return response.json().catch(function () { return { ok: false, error: 'Raspuns invalid de la server.' }; }).then(function (data) {
                    if (!response.ok || !data || data.ok !== true) { throw new Error((data && data.error) || 'Eroare la incarcare.'); }
                    return data;
                });
            });
    }

    function setExpandedUi(row, on) {
        row.classList.toggle('is-expanded', on);
        var button = row.querySelector('[data-tire-toggle]');
        if (button) {
            button.setAttribute('aria-expanded', on ? 'true' : 'false');
            button.setAttribute('aria-label', (on ? 'Restrange' : 'Extinde') + ' grupul');
        }
    }

    function applySubState(key) {
        childRows(key).forEach(function (row) {
            if (row.classList.contains('tire-sub-row')) {
                var subKey = row.dataset.tireSubKey;
                var open = !collapsedSubs.has(subKey);
                var button = row.querySelector('[data-tire-sub-toggle]');
                if (button) { button.setAttribute('aria-expanded', open ? 'true' : 'false'); }
                tbody().querySelectorAll('tr.tire-child-row[data-tire-sub="' + cssKey(subKey) + '"]').forEach(function (tireRow) {
                    tireRow.classList.toggle('is-hidden-by-sub', !open);
                });
            }
        });
    }

    function show(key) {
        childRows(key).forEach(function (row) { row.classList.remove('is-collapsed-child'); });
        applySubState(key);
    }
    function hide(key) {
        childRows(key).forEach(function (row) { row.classList.add('is-collapsed-child'); });
    }

    // Incarca intr-o singura cerere anvelopele grupurilor care nu le au inca in pagina.
    function load(keys) {
        var missing = keys.filter(function (key) { return groupRow(key) && childRows(key).length === 0; });
        if (missing.length === 0) { return Promise.resolve(); }
        var myGeneration = generation;
        missing.forEach(function (key) {
            var button = groupRow(key).querySelector('[data-tire-toggle]');
            if (button) { button.classList.add('is-loading'); }
        });
        return getJson(pageUrl('tire_stock_children', { keys: missing })).then(function (data) {
            if (myGeneration !== generation) { return; }
            missing.forEach(function (key) {
                var row = groupRow(key);
                var html = data.html ? data.html[key] : undefined;
                if (!row || html === undefined || childRows(key).length > 0) { return; }
                var template = document.createElement('template');
                template.innerHTML = html.trim();
                var rows = Array.prototype.slice.call(template.content.children);
                rows.forEach(function (child) { child.classList.add('is-collapsed-child'); });
                row.after.apply(row, rows);
            });
            if (typeof window.fleetTireInitRows === 'function') { window.fleetTireInitRows(tbody()); }
            if (window.fleetTireSelection) { window.fleetTireSelection.rowsReplaced(); }
        }).catch(function (error) {
            if (myGeneration !== generation) { return; }
            missing.forEach(function (key) {
                var row = groupRow(key);
                if (row) { setExpandedUi(row, false); }
            });
            window.console.error(error);
        }).finally(function () {
            missing.forEach(function (key) {
                var row = groupRow(key);
                var button = row ? row.querySelector('[data-tire-toggle]') : null;
                if (button) { button.classList.remove('is-loading'); }
            });
        });
    }

    function expand(keys, remember) {
        keys.forEach(function (key) {
            var row = groupRow(key);
            if (!row) { return; }
            setExpandedUi(row, true);
            if (remember) { expanded.add(key); }
        });
        if (remember) { writeSet(EXPANDED_KEY, expanded); }
        return load(keys).then(function () {
            keys.forEach(function (key) {
                var row = groupRow(key);
                if (row && row.classList.contains('is-expanded')) { show(key); }
            });
        });
    }

    function collapse(keys) {
        keys.forEach(function (key) {
            var row = groupRow(key);
            if (row) { setExpandedUi(row, false); }
            expanded.delete(key);
            hide(key);
        });
        writeSet(EXPANDED_KEY, expanded);
    }

    function toggle(key) {
        var row = groupRow(key);
        if (!row) { return; }
        if (row.classList.contains('is-expanded')) { collapse([key]); } else { expand([key], true); }
    }

    // Dupa randarea rezultatelor (pagina, filtre, tab, paginare): redeschide grupurile memorate
    // si pe cele cu potrivire exacta de serie, actualizeaza numaratorile tab-urilor.
    function afterRender() {
        generation++;
        var meta = tbody().querySelector('[data-tire-results-meta]');
        if (meta) {
            try {
                var counts = JSON.parse(meta.dataset.counts || '{}');
                document.querySelectorAll('[data-tire-view-count]').forEach(function (badge) {
                    badge.textContent = String(counts[badge.dataset.tireViewCount] || 0);
                });
            } catch (error) { /* numaratorile raman cele vechi */ }
        }
        var remembered = [];
        var automatic = [];
        groupRows().forEach(function (row) {
            var key = row.dataset.tireGroupKey;
            if (expanded.has(key)) { remembered.push(key); } else if (row.dataset.autoExpand === '1') { automatic.push(key); }
        });
        if (remembered.length > 0) { expand(remembered, false); }
        if (automatic.length > 0) {
            expand(automatic, false).then(function () {
                var first = groupRow(automatic[0]);
                if (first) { first.scrollIntoView({ block: 'nearest' }); }
            });
        }
    }

    function selectGroup(key, add) {
        return getJson(pageUrl('tire_stock_selection', { group: key, scope: 'all' })).then(function (data) {
            if (!window.fleetTireSelection) { return; }
            if (add) { window.fleetTireSelection.add(data.tires || []); } else { window.fleetTireSelection.remove((data.tires || []).map(function (tire) { return tire.id; })); }
        }).catch(function (error) { window.console.error(error); });
    }

    results.addEventListener('click', function (event) {
        var toggleButton = event.target.closest('[data-tire-toggle]');
        if (toggleButton) {
            toggle(toggleButton.closest('tr').dataset.tireGroupKey);
            return;
        }
        var openLink = event.target.closest('[data-tire-toggle-open]');
        if (openLink) {
            var openKey = openLink.closest('tr').dataset.tireGroupKey;
            if (!groupRow(openKey).classList.contains('is-expanded')) { expand([openKey], true); }
            return;
        }
        var subToggle = event.target.closest('[data-tire-sub-toggle]');
        if (subToggle) {
            var subRow = subToggle.closest('tr');
            var subKey = subRow.dataset.tireSubKey;
            if (collapsedSubs.has(subKey)) { collapsedSubs.delete(subKey); } else { collapsedSubs.add(subKey); }
            writeSet(COLLAPSED_SUBS_KEY, collapsedSubs);
            applySubState(subRow.dataset.tireChildOf);
            return;
        }
        var selectAll = event.target.closest('[data-tire-group-select-all]');
        if (selectAll) { selectGroup(selectAll.dataset.tireGroupSelectAll, true); return; }
        var deselect = event.target.closest('[data-tire-group-deselect]');
        if (deselect) { selectGroup(deselect.dataset.tireGroupDeselect, false); return; }
        // Click pe restul randului de grup = extinde / restrange (nu si pe controale).
        var row = event.target.closest('tr.tire-group-row');
        if (row && !event.target.closest('input, button, a, label, select, .dropdown, .dropdown-menu')) {
            toggle(row.dataset.tireGroupKey);
        }
    });

    var expandAll = document.querySelector('[data-tire-expand-all]');
    if (expandAll) {
        expandAll.addEventListener('click', function () {
            expand(groupRows().map(function (row) { return row.dataset.tireGroupKey; }), true);
        });
    }
    var collapseAll = document.querySelector('[data-tire-collapse-all]');
    if (collapseAll) {
        collapseAll.addEventListener('click', function () {
            collapse(groupRows().map(function (row) { return row.dataset.tireGroupKey; }));
        });
    }

    // "Selecteaza toate cele N rezultate": toate anvelopele filtrelor curente, de pe toate paginile.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-tire-select-results]');
        if (!button) { return; }
        button.disabled = true;
        getJson(pageUrl('tire_stock_selection', {})).then(function (data) {
            if (window.fleetTireSelection) { window.fleetTireSelection.add(data.tires || []); }
        }).catch(function (error) { window.console.error(error); }).finally(function () { button.disabled = false; });
    });

    // Tab-urile Toate / In stoc - Rezerva / Montate: un filtru in plus ("view"), aplicat live.
    document.querySelectorAll('[data-tire-view]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            var input = document.querySelector('[data-tire-view-input]');
            if (!input || input.value === tab.dataset.tireView) { return; }
            input.value = tab.dataset.tireView;
            document.querySelectorAll('[data-tire-view]').forEach(function (other) {
                var active = other === tab;
                other.classList.toggle('is-active', active);
                other.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            if (window.fleetTireFilters) { window.fleetTireFilters.apply(true); }
        });
    });

    window.fleetTireGroups = { afterRender: afterRender };
    document.addEventListener('DOMContentLoaded', afterRender);
})();
