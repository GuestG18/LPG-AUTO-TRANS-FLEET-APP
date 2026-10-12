/* Revizii (pagini pe categorii): filtre carduri/tabel, detalii, configurare (modal), inregistrare T0.
   Simularea si sincronizarea sunt in revizii-common.js. */
(function () {
    'use strict';

    var PAGE_SIZE = { cards: 12, table: 8 };
    var data = {};
    try {
        data = JSON.parse((document.getElementById('rvPageData') || {}).textContent || '{}');
    } catch (e) {
        data = {};
    }
    var rowsData = data.rows || [];
    var defaults = data.defaults || {};
    var byKey = {};
    rowsData.forEach(function (row) { byKey[row.key] = row; });

    function fmt(value, digits) {
        if (value === null || value === undefined || value === '') { return '—'; }
        return new Intl.NumberFormat('ro-RO', { minimumFractionDigits: digits || 0, maximumFractionDigits: digits || 0 }).format(Number(value));
    }
    function esc(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function localInput(date) {
        var d = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return d.toISOString().slice(0, 16);
    }
    function storage(key, value) {
        try {
            if (value === undefined) { return window.localStorage.getItem(key); }
            window.localStorage.setItem(key, value);
        } catch (e) { /* stocare indisponibila */ }
        return null;
    }

    // ------------------------------------------------------------ filtre + vederi
    var page = document.querySelector('.rv-page');
    if (!page) { return; }
    var table = document.getElementById('rvTable');
    var cardsBox = document.getElementById('rvCards');
    var search = document.getElementById('rvSearch');
    var statusFilter = document.getElementById('rvStatusFilter');
    var kindFilter = document.getElementById('rvKindFilter');
    var pager = document.getElementById('rvPager');
    var countLabel = document.getElementById('rvCount');
    var viewButtons = Array.prototype.slice.call(document.querySelectorAll('[data-view]'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-view-panel]'));
    var view = storage('revizii.view') === 'table' ? 'table' : 'cards';
    var currentPage = 1;

    function items() {
        return view === 'cards'
            ? Array.prototype.slice.call(cardsBox.querySelectorAll('.rv-vcard'))
            : Array.prototype.slice.call(table.querySelectorAll('tr.rv-row'));
    }

    function matches(el) {
        var term = (search.value || '').trim().toLowerCase();
        if (term && el.getAttribute('data-search').indexOf(term) === -1) { return false; }
        if (statusFilter.value && (' ' + el.getAttribute('data-statuses') + ' ').indexOf(' ' + statusFilter.value + ' ') === -1) { return false; }
        if (kindFilter.value && el.getAttribute('data-kind') !== kindFilter.value) { return false; }
        return true;
    }

    function render() {
        panels.forEach(function (panel) { panel.hidden = panel.getAttribute('data-view-panel') !== view; });
        viewButtons.forEach(function (button) {
            var on = button.getAttribute('data-view') === view;
            button.classList.toggle('active', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        var all = items();
        var visible = all.filter(matches);
        var size = PAGE_SIZE[view];
        var pages = Math.max(1, Math.ceil(visible.length / size));
        currentPage = Math.min(currentPage, pages);
        var from = (currentPage - 1) * size;
        var shown = visible.slice(from, from + size);

        all.forEach(function (el) {
            var on = shown.indexOf(el) !== -1;
            el.hidden = !on;
            if (view === 'table') {
                var detail = table.querySelector('tr[data-detail="' + el.getAttribute('data-row') + '"]');
                if (detail) { detail.hidden = !on || !el.classList.contains('is-open'); }
            }
        });
        if (view === 'table') {
            shown.forEach(function (tr, i) { tr.querySelector('.rv-idx').textContent = String(from + i + 1); });
        }
        countLabel.textContent = 'Afișează ' + shown.length + ' din ' + visible.length + ' ' + (countLabel.getAttribute('data-unit') || 'ansambluri');
        renderPager(pages);
    }

    function renderPager(pages) {
        pager.innerHTML = '';
        function button(label, target, opts) {
            var b = document.createElement('button');
            b.type = 'button';
            b.innerHTML = label;
            if (opts.active) { b.className = 'active'; b.setAttribute('aria-current', 'page'); }
            if (opts.disabled) { b.disabled = true; }
            if (opts.aria) { b.setAttribute('aria-label', opts.aria); }
            b.addEventListener('click', function () { currentPage = target; render(); });
            pager.appendChild(b);
        }
        button('<i class="bi bi-chevron-left"></i>', currentPage - 1, { disabled: currentPage <= 1, aria: 'Pagina anterioară' });
        for (var p = 1; p <= pages; p++) {
            button(String(p), p, { active: p === currentPage });
        }
        button('<i class="bi bi-chevron-right"></i>', currentPage + 1, { disabled: currentPage >= pages, aria: 'Pagina următoare' });
    }

    [search, statusFilter, kindFilter].forEach(function (el) {
        el.addEventListener(el === search ? 'input' : 'change', function () { currentPage = 1; render(); });
    });
    viewButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            view = button.getAttribute('data-view');
            storage('revizii.view', view);
            currentPage = 1; // filtrele raman aceleasi; doar paginarea se reia
            render();
        });
    });

    // ------------------------------------------------------------ detalii calcul
    function detailHtml(key) {
        var tpl = document.getElementById('rvDetail-' + key);
        return tpl ? tpl.innerHTML : '';
    }

    function toggleTableDetail(key) {
        var tr = table.querySelector('tr[data-row="' + key + '"]');
        var detail = table.querySelector('tr[data-detail="' + key + '"]');
        if (!tr || !detail) { return; }
        var open = detail.hidden;
        if (open && !detail.firstElementChild.innerHTML) {
            detail.firstElementChild.innerHTML = detailHtml(key);
        }
        detail.hidden = !open;
        tr.classList.toggle('is-open', open);
    }

    var detailModal = document.getElementById('rvDetailModal');
    function openDetailModal(key) {
        if (!detailModal || !window.bootstrap) { return; }
        detailModal.querySelector('[data-detail-label]').textContent = (byKey[key] || {}).label || '';
        detailModal.querySelector('[data-detail-body]').innerHTML = detailHtml(key);
        window.bootstrap.Modal.getOrCreateInstance(detailModal).show();
    }

    page.addEventListener('click', function (event) {
        var target = event.target;
        var toggle = target.closest('[data-toggle-detail]');
        if (toggle) { toggleTableDetail(toggle.getAttribute('data-toggle-detail')); return; }
        var openDetail = target.closest('[data-detail-open]');
        if (openDetail) { openDetailModal(openDetail.getAttribute('data-detail-open')); return; }
        var configure = target.closest('[data-configure]');
        if (configure) { openConfig(configure.getAttribute('data-configure')); return; }
        if (target.closest('button, a, input, select, .dropdown-menu, details')) { return; }
        var tr = target.closest('tr.rv-row');
        if (tr) { toggleTableDetail(tr.getAttribute('data-row')); }
    });

    // ------------------------------------------------------------ configurare (modal)
    var configModal = document.getElementById('rvConfigModal');
    var configForm = document.getElementById('rvConfigForm');
    var openConfig = function () {};
    var tabHasHours = !!data.hours;

    // Eticheta unei componente: "Cap tractor · Față · B 665 NET".
    var itemLabel = function (c) {
        var base = c.is_trailer ? 'Semi-remorcă' : (c.vtype === 'camion' ? 'Camion' : 'Cap tractor');
        return base + (c.sub ? ' · ' + c.sub : '') + ' · ' + c.nr;
    };
    var rowItems = function (row) { return (row.tractor || []).concat(row.semi || []); };
    var dateRo = function (value) { return (value || '').slice(0, 16).replace(/^(\d{4})-(\d{2})-(\d{2})/, '$3.$2.$1'); };

    if (configModal && configForm) {
        var typeSelect = document.getElementById('rvTipMonitorizare');
        var targetSelect = document.getElementById('rvTargetRow');
        var targetLabel = configForm.querySelector('[data-target-label]');
        var configTitle = configForm.querySelector('[data-config-title]');
        var hoursFieldset = configForm.querySelector('[data-hours-fieldset]');
        var itemsBox = configForm.querySelector('[data-config-items]');

        var fillTargets = function () {
            var assembly = typeSelect.value === 'ansamblu';
            targetSelect.innerHTML = '';
            rowsData.forEach(function (row) {
                if ((row.kind === 'ansamblu') !== assembly) { return; }
                var option = document.createElement('option');
                option.value = row.key;
                option.textContent = [row.tractor[0] && row.tractor[0].nr, row.semi[0] && row.semi[0].nr].filter(Boolean).join(' + ');
                targetSelect.appendChild(option);
            });
            targetLabel.textContent = assembly ? 'Ansamblu' : 'Vehicul';
            configTitle.textContent = assembly ? 'Ansamblu' : 'Vehicul individual';
            fillPanels();
        };

        var syncPanel = function (panel) {
            var enabled = panel.querySelector('[data-field="enabled"]').checked;
            panel.classList.toggle('is-disabled', !enabled);
            panel.querySelectorAll('input:not([data-field="enabled"])').forEach(function (input) { input.disabled = !enabled; });
        };

        var syncHours = function () {
            if (!hoursFieldset) { return; }
            // Orele motor se aplica doar capului tractor / camionului.
            var motorOn = Array.prototype.some.call(itemsBox.querySelectorAll('.rv-panel'), function (panel) {
                return panel.getAttribute('data-trailer') === '0' && panel.querySelector('[data-field="enabled"]').checked;
            });
            hoursFieldset.disabled = !motorOn;
            hoursFieldset.classList.toggle('is-disabled', !motorOn);
        };

        var buildPanel = function (c, index) {
            var panel = document.createElement('fieldset');
            panel.className = 'rv-panel ' + (c.is_trailer ? 'rv-panel-semi' : 'rv-panel-tractor');
            panel.setAttribute('data-trailer', c.is_trailer ? '1' : '0');
            var id = 'rvCfg' + index;
            var name = function (field) { return 'items[' + index + '][' + field + ']'; };
            panel.innerHTML =
                '<legend class="rv-panel-title"><input class="form-check-input" type="checkbox" value="1" id="' + id + '" data-field="enabled" checked>' +
                '<label for="' + id + '"><i class="bi ' + (c.is_trailer ? 'bi-truck-flatbed' : 'bi-truck-front-fill') + '"></i> <span></span></label><small></small></legend>' +
                '<input type="hidden" data-field="id"><input type="hidden" data-field="tip">' +
                '<div class="row g-2">' +
                '<div class="col-sm-4"><label class="form-label">Interval (km)</label><input type="number" min="500" max="300000" step="100" class="form-control" data-field="interval" required></div>' +
                '<div class="col-sm-4"><label class="form-label">Km la ultima revizie</label><input type="number" min="0" step="1" class="form-control" data-field="km_last"></div>' +
                '<div class="col-sm-4"><label class="form-label">Prag notificare (km rămași)</label><input type="number" min="0" step="100" class="form-control" data-field="warn" required></div>' +
                '</div><div class="invalid-feedback d-block" data-panel-error></div>';
            var set = function (field, attr, value) { panel.querySelector('[data-field="' + field + '"]')[attr] = value; };
            set('enabled', 'name', name('enabled'));
            set('id', 'name', name('vehicle_id'));
            set('id', 'value', c.id);
            set('tip', 'name', name('tip'));
            set('tip', 'value', c.tip);
            set('interval', 'name', name('interval_km'));
            set('interval', 'value', c.interval === null || c.interval === undefined ? '' : c.interval);
            set('km_last', 'name', name('km_ultima_revizie'));
            set('km_last', 'value', c.km_last === null ? '' : c.km_last);
            set('km_last', 'placeholder', 'gol = păstrează T0 (bord acum ' + fmt(c.km_bord) + ')');
            set('warn', 'name', name('prag_avertizare_km'));
            set('warn', 'value', c.warn);
            panel.querySelector('legend span').textContent = itemLabel(c).replace(' · ' + c.nr, '');
            panel.querySelector('legend small').textContent = c.nr + (c.configured ? ' · T0 ' + dateRo(c.t0) : ' · neconfigurat');
            panel.querySelector('[data-field="enabled"]').addEventListener('change', function () {
                syncPanel(panel);
                syncHours();
            });
            syncPanel(panel);
            return panel;
        };

        var fillPanels = function () {
            var row = byKey[targetSelect.value] || null;
            itemsBox.innerHTML = '';
            if (row) {
                rowItems(row).forEach(function (c, index) { itemsBox.appendChild(buildPanel(c, index)); });
            }
            syncHours();
        };

        openConfig = function (key) {
            var row = byKey[key];
            if (!row || !window.bootstrap) { return; }
            typeSelect.value = row.kind === 'ansamblu' ? 'ansamblu' : 'individual';
            fillTargets();
            targetSelect.value = key;
            fillPanels();
            window.bootstrap.Modal.getOrCreateInstance(configModal).show();
        };

        typeSelect.addEventListener('change', fillTargets);
        targetSelect.addEventListener('change', fillPanels);
        // Fara ansambluri in tab (ex. ulei): selectorul porneste direct pe vehicule individuale.
        if (!rowsData.some(function (row) { return row.kind === 'ansamblu'; })) {
            typeSelect.value = 'individual';
        }

        // Deschis din antet: formularul se reincarca din datele salvate (modificarile nesalvate se pierd la Renunță).
        configModal.addEventListener('show.bs.modal', function (event) {
            if (event.relatedTarget && event.relatedTarget.hasAttribute('data-config-open')) {
                fillTargets();
            }
            if (hoursFieldset) {
                document.getElementById('rvFactor').value = defaults.factor;
                document.getElementById('rvHourSource').value = defaults.source;
            }
        });

        configForm.addEventListener('submit', function (event) {
            var ok = true;
            var any = false;
            var panels = itemsBox.querySelectorAll('.rv-panel');
            Array.prototype.forEach.call(panels, function (panel) {
                var error = panel.querySelector('[data-panel-error]');
                error.textContent = '';
                if (!panel.querySelector('[data-field="enabled"]').checked) { return; }
                any = true;
                var interval = Number(panel.querySelector('[data-field="interval"]').value);
                var warn = panel.querySelector('[data-field="warn"]').value;
                var kmLast = panel.querySelector('[data-field="km_last"]').value;
                var messages = [];
                if (!interval || interval < 500 || interval > 300000) { messages.push('Completează intervalul (500 – 300.000 km).'); }
                if (warn === '' || Number(warn) < 0 || Number(warn) >= interval) { messages.push('Pragul trebuie să fie între 0 și interval.'); }
                if (kmLast !== '' && Number(kmLast) < 0) { messages.push('Km la ultima revizie nu pot fi negativi.'); }
                if (messages.length) { ok = false; error.textContent = messages.join(' '); }
            });
            if (hoursFieldset && !hoursFieldset.disabled) {
                var factor = document.getElementById('rvFactor');
                if (factor.value === '' || Number(factor.value) < 0 || Number(factor.value) > 500) {
                    ok = false;
                    factor.classList.add('is-invalid');
                } else {
                    factor.classList.remove('is-invalid');
                }
            }
            if (!any && panels.length) {
                ok = false;
                panels[0].querySelector('[data-panel-error]').textContent = 'Bifează cel puțin o componentă.';
            }
            if (!ok) { event.preventDefault(); }
        });

        fillTargets();
    }

    // ------------------------------------------------------------ inregistrare T0 (revizie / montaj)
    var recordModal = document.getElementById('rvRecordModal');
    if (recordModal) {
        var container = recordModal.querySelector('[data-record-components]');
        var recordLabel = recordModal.querySelector('[data-record-label]');

        page.addEventListener('click', function (event) {
            var button = event.target.closest('.rv-record');
            if (!button) { return; }
            var row = byKey[button.getAttribute('data-row-key')];
            if (!row) { return; }
            recordLabel.textContent = row.label;
            var maxMoment = localInput(new Date());
            container.innerHTML = '';
            rowItems(row).forEach(function (component, index) {
                var id = 'rvRec' + index;
                var name = function (field) { return 'rec[' + index + '][' + field + ']'; };
                var wrap = document.createElement('div');
                wrap.className = 'rv-record-component';
                // Fara precompletare: data si ora (T0) se introduc explicit, per componenta.
                wrap.innerHTML =
                    '<div class="form-check mb-1"><input class="form-check-input" type="checkbox" value="1" checked id="' + id + '">' +
                    '<label class="form-check-label fw-semibold" for="' + id + '"></label></div>' +
                    '<input type="hidden" data-f="vehicle_id"><input type="hidden" data-f="tip">' +
                    '<div class="row g-2">' +
                    '<div class="col-sm-7"><label class="form-label small mb-1" for="' + id + 'at">Data și ora (T0)</label>' +
                    '<input type="datetime-local" class="form-control form-control-sm" id="' + id + 'at" required></div>' +
                    '<div class="col-sm-5"><label class="form-label small mb-1" for="' + id + 'km">Km bord (opțional)</label>' +
                    '<input type="number" min="0" class="form-control form-control-sm" id="' + id + 'km"></div>' +
                    '</div>';
                var check = wrap.querySelector('input[type="checkbox"]');
                check.name = name('enabled');
                wrap.querySelector('[data-f="vehicle_id"]').name = name('vehicle_id');
                wrap.querySelector('[data-f="vehicle_id"]').value = component.id;
                wrap.querySelector('[data-f="tip"]').name = name('tip');
                wrap.querySelector('[data-f="tip"]').value = component.tip;
                wrap.querySelector('label.form-check-label').textContent = itemLabel(component) +
                    (component.configured ? ' — T0 actual ' + dateRo(component.t0) : ' — neconfigurat');
                var at = wrap.querySelector('input[type="datetime-local"]');
                at.name = name('at');
                at.max = maxMoment;
                var km = wrap.querySelector('input[type="number"]');
                km.name = name('km');
                km.placeholder = 'în aplicație: ' + fmt(component.km_bord);
                var toggle = function () {
                    at.disabled = !check.checked;
                    km.disabled = !check.checked;
                };
                // Placutele / anvelopele fara interval configurat nu pot primi T0 (lipseste intervalul).
                if (!component.configured && !tabHasHours) {
                    check.checked = false;
                    check.disabled = true;
                    wrap.querySelector('label.form-check-label').textContent += ' (setează întâi intervalul din „Configurare”)';
                }
                check.addEventListener('change', toggle);
                toggle();
                container.appendChild(wrap);
            });
        });
    }

    // ------------------------------------------------------------ deschidere din Prezentare generala
    // ?record=<vehicul>[&comp=<tip>]&from=prezentare deschide formularul de T0 pentru componenta
    // exacta a alertei; ?config=<vehicul> deschide configurarea vehiculului.
    var openRequest = data.open || {};
    var rowKeyOfVehicle = function (vehicleId) {
        var found = null;
        rowsData.forEach(function (row) {
            rowItems(row).forEach(function (c) { if (c.id === vehicleId && !found) { found = row.key; } });
        });
        return found;
    };
    window.addEventListener('load', function () {
        if (openRequest.record) {
            var key = rowKeyOfVehicle(openRequest.record);
            var button = key ? document.querySelector('.rv-record[data-row-key="' + key + '"]') : null;
            if (button) {
                button.click();
                if (window.bootstrap) { window.bootstrap.Modal.getOrCreateInstance(recordModal).show(); }
                // Doar componenta din alerta ramane bifata (celelalte pot fi bifate manual).
                if (openRequest.comp) {
                    recordModal.querySelectorAll('.rv-record-component').forEach(function (wrap) {
                        var tip = wrap.querySelector('[data-f="tip"]').value;
                        var vid = Number(wrap.querySelector('[data-f="vehicle_id"]').value);
                        var check = wrap.querySelector('input[type="checkbox"]');
                        if (!check.disabled && (tip !== openRequest.comp || vid !== openRequest.record)) {
                            check.checked = false;
                            check.dispatchEvent(new Event('change'));
                        }
                    });
                }
                var returnInput = recordModal.querySelector('[data-record-return]');
                if (returnInput && openRequest.from === 'prezentare') { returnInput.value = 'prezentare'; }
            }
        } else if (openRequest.config) {
            var configKey = rowKeyOfVehicle(openRequest.config);
            if (configKey) { openConfig(configKey); }
        } else if (openRequest.configurare) {
            var headerConfig = document.querySelector('[data-config-open]');
            if (headerConfig) { headerConfig.click(); }
        }
    });

    render();
})();
