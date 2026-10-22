/*
 * Stoc anvelope: cautare si filtre live (fara butonul "Filtre").
 * Filtrarea ramane pe server (aceeasi ruta, aceiasi parametri GET, aceeasi interogare si paginare
 * ca la incarcarea normala): cerem pagina cu filtrele curente si inlocuim doar randurile tabelului
 * si paginarea. Astfel cautarea acopera tot inventarul, nu doar randurile afisate.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-tire-live-filters]');
    var results = document.querySelector('[data-tire-results]');
    var pagination = document.querySelector('[data-tire-pagination]');
    if (!form || !results || !pagination) { return; }

    var FILTER_KEYS = ['q', 'vehicle_type', 'axle_config', 'tire_type', 'status', 'condition', 'location', 'mounted', 'view'];
    // Tab-ul (view) nu e un filtru resetabil: "Reseteaza filtrele" il pastreaza.
    var RESETTABLE_KEYS = FILTER_KEYS.filter(function (key) { return key !== 'view'; });
    var SEARCH_DELAY = 300;
    var searchInput = form.querySelector('[name="q"]');
    var clearBtn = form.querySelector('[data-tire-search-clear]');
    var resetBtn = form.querySelector('[data-tire-filters-reset]');
    var progress = document.querySelector('[data-tire-live-progress]');
    var liveStatus = document.querySelector('[data-tire-live-status]');
    var bulkReturnQuery = document.querySelector('#tireBulkForm input[name="return_query"]');
    var timer = 0;
    var controller = null;
    var requestNo = 0;
    var lastQuery = null;

    function filterValues() {
        var values = {};
        FILTER_KEYS.forEach(function (key) {
            var field = form.elements.namedItem(key);
            var value = field ? String(field.value || '').trim() : '';
            if (value !== '') { values[key] = value; }
        });
        return values;
    }

    function currentParams() {
        return new URLSearchParams(window.location.search);
    }

    // URL-ul paginii pentru filtrele date; paginarea porneste de la 1 cand se schimba filtrele.
    function buildUrl(values, page) {
        var params = new URLSearchParams();
        params.set('page', 'mentenanta');
        params.set('action', 'tire_stock');
        Object.keys(values).forEach(function (key) { params.set(key, values[key]); });
        var perPage = currentParams().get('per_page');
        if (perPage) { params.set('per_page', perPage); }
        if (page && page > 1) { params.set('p', String(page)); }
        return window.location.pathname + '?' + params.toString();
    }

    function syncControls() {
        var values = filterValues();
        if (clearBtn) { clearBtn.hidden = !searchInput || searchInput.value === ''; }
        if (resetBtn) { resetBtn.disabled = RESETTABLE_KEYS.every(function (key) { return values[key] === undefined; }); }
    }

    function setLoading(on) {
        if (progress) { progress.classList.toggle('is-loading', on); }
        results.classList.toggle('is-loading', on);
        pagination.classList.toggle('is-loading', on);
        results.setAttribute('aria-busy', on ? 'true' : 'false');
        if (on) {
            // Pastram inaltimea cat timp se incarca, ca pagina sa nu sara.
            results.style.minHeight = results.offsetHeight + 'px';
        }
    }

    function load(url) {
        var query = url.split('?')[1] || '';
        if (query === lastQuery) { return; }
        lastQuery = query;
        window.clearTimeout(timer);
        if (controller) { controller.abort(); }
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        var myRequest = ++requestNo;
        setLoading(true);

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            return response.text().then(function (html) { return { response: response, html: html }; });
        }).then(function (payload) {
            // Doar raspunsul ultimei cereri are voie sa schimbe tabelul.
            if (myRequest !== requestNo) { return; }
            var doc = new DOMParser().parseFromString(payload.html, 'text/html');
            var newBody = doc.querySelector('[data-tire-results] tbody');
            var newPagination = doc.querySelector('[data-tire-pagination]');
            if (!payload.response.ok || !newBody || !newPagination) {
                // Sesiune expirata / eroare: incarcam pagina normal, cu aceleasi filtre.
                window.location.href = url;
                return;
            }
            var body = results.querySelector('tbody');
            body.innerHTML = newBody.innerHTML;
            pagination.innerHTML = newPagination.innerHTML;
            window.history.replaceState(null, '', url);
            if (bulkReturnQuery) { bulkReturnQuery.value = query; }
            if (typeof window.fleetTireInitRows === 'function') { window.fleetTireInitRows(body); }
            if (window.fleetTireSelection) { window.fleetTireSelection.rowsReplaced(); }
            if (window.fleetTireGroups) { window.fleetTireGroups.afterRender(); }
            var summary = pagination.querySelector('.text-muted');
            if (liveStatus) { liveStatus.textContent = summary ? summary.textContent.trim() : ''; }
            finish(myRequest);
        }).catch(function (error) {
            if (error && error.name === 'AbortError') { return; }
            if (myRequest !== requestNo) { return; }
            lastQuery = null;
            if (liveStatus) { liveStatus.textContent = 'Filtrarea a esuat. Verifica conexiunea si incearca din nou.'; }
            finish(myRequest);
        });
    }

    function finish(myRequest) {
        if (myRequest !== requestNo) { return; }
        setLoading(false);
        results.style.minHeight = '';
        controller = null;
    }

    function applyFilters(immediate) {
        syncControls();
        window.clearTimeout(timer);
        var run = function () { load(buildUrl(filterValues(), 1)); };
        if (immediate) { run(); } else { timer = window.setTimeout(run, SEARCH_DELAY); }
    }

    // Starea de pornire: URL-ul cu care s-a randat pagina.
    lastQuery = window.location.search.replace(/^\?/, '');

    if (searchInput) {
        searchInput.addEventListener('input', function () { applyFilters(false); });
        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && searchInput.value !== '') {
                event.preventDefault();
                searchInput.value = '';
                applyFilters(true);
            }
        });
    }
    form.addEventListener('change', function (event) {
        if (event.target.tagName === 'SELECT') { applyFilters(true); }
    });
    // Enter in campul de cautare: aplicam imediat, fara reincarcarea paginii.
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        event.stopPropagation();
        applyFilters(true);
    });
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            searchInput.focus();
            applyFilters(true);
        });
    }
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            RESETTABLE_KEYS.forEach(function (key) {
                var field = form.elements.namedItem(key);
                if (field) { field.value = ''; }
            });
            applyFilters(true);
        });
    }

    // Paginarea: aceleasi filtre, alta pagina, fara reincarcare.
    pagination.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) { return; }
        event.preventDefault();
        var page = Number(new URL(link.href, window.location.href).searchParams.get('p') || 1);
        load(buildUrl(filterValues(), page));
    });
    // "N / pagina" are onchange="this.form.submit()": il oprim in faza de captura si incarcam live.
    document.addEventListener('change', function (event) {
        var select = event.target;
        if (!(select instanceof HTMLSelectElement) || select.name !== 'per_page' || !pagination.contains(select)) { return; }
        event.stopPropagation();
        var params = currentParams();
        params.set('per_page', select.value);
        params.delete('p');
        window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
        load(buildUrl(filterValues(), 1));
    }, true);

    window.fleetTireFilters = { apply: applyFilters };
    syncControls();
})();
