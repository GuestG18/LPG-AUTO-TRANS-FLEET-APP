<?php
$rows = is_array($rows ?? null) ? $rows : [];
$kpis = is_array($kpis ?? null) ? $kpis : ['randuri' => 0, 'total_piese' => 0.0, 'total_manopera' => 0.0];
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$selectedVehicleId = (int) ($selectedVehicleId ?? 0);
$intakeUrl = build_query_url(['page' => 'ocr_piese', 'action' => 'intake']);
$sandboxUrl = build_query_url(['page' => 'dev_ocr_test']);
$rowAddUrl = build_query_url(['page' => 'ocr_piese', 'action' => 'row_add']);
$rowUpdateUrl = build_query_url(['page' => 'ocr_piese', 'action' => 'row_update']);
$rowDeleteUrl = build_query_url(['page' => 'ocr_piese', 'action' => 'row_delete']);
$baseUrl = build_query_url(['page' => 'ocr_piese']);
?>
<style>
    /* Grila in stil Excel pentru registrul de piese. */
    .reg-table { font-size: .85rem; }
    .reg-table th { white-space: nowrap; }
    .reg-table td.reg-cell { cursor: cell; min-width: 5rem; }
    .reg-table td.reg-cell:hover { outline: 2px solid #9ec5fe; outline-offset: -2px; }
    .reg-table td.reg-cell.reg-editing { padding: 0; outline: 2px solid #0d6efd; outline-offset: -2px; }
    .reg-table td.reg-cell.reg-editing input,
    .reg-table td.reg-cell.reg-editing select {
        border: 0; border-radius: 0; width: 100%; height: 100%;
        font-size: .85rem; padding: .35rem .5rem; background: #fffbe6;
    }
    .reg-table td.reg-cell.reg-editing input:focus,
    .reg-table td.reg-cell.reg-editing select:focus { outline: none; box-shadow: none; }
    .reg-table td.reg-saved { animation: reg-flash 1s; }
    @keyframes reg-flash { 0% { background: #d1e7dd; } 100% { background: transparent; } }
    .reg-table td.reg-num { text-align: right; font-variant-numeric: tabular-nums; }
    .reg-table col.reg-col-text { min-width: 14rem; }
    .reg-text-cell { white-space: pre-wrap; word-break: break-word; }
    .reg-wrap { max-height: 68vh; overflow: auto; }
    .reg-wrap thead th { position: sticky; top: 0; z-index: 2; background: #f8f9fa; box-shadow: inset 0 -1px 0 #dee2e6; }
    /* Meniul de actiuni (三 puncte) - atasat de body ca sa nu fie taiat de scroll. */
    .reg-action-menu {
        position: fixed; z-index: 1060; min-width: 12rem;
        background: #fff; border: 1px solid rgba(0,0,0,.15); border-radius: .375rem;
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.15); padding: .25rem 0; font-size: .875rem;
    }
    .reg-action-menu .reg-menu-item {
        display: flex; align-items: center; gap: .5rem; width: 100%;
        padding: .35rem .85rem; border: 0; background: none; text-align: left;
        color: #212529; text-decoration: none; cursor: pointer;
    }
    .reg-action-menu .reg-menu-item:hover { background: #f8f9fa; }
    .reg-action-menu .reg-menu-item.text-danger:hover { background: #f8d7da; }
    .reg-action-menu .reg-menu-item.disabled { color: #adb5bd; cursor: default; }
    .reg-action-menu .reg-menu-sep { border-top: 1px solid #dee2e6; margin: .25rem 0; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h5 mb-0"><i class="bi bi-table me-1" aria-hidden="true"></i>Registru piese &amp; lucrări</h1>
        <div class="text-muted small">Reparații / Înlocuiri / Îmbunătățiri per vehicul — click pe orice celulă pentru editare, ca în Excel.</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($sandboxUrl) ?>"><i class="bi bi-flask me-1" aria-hidden="true"></i>Sandbox OCR</a>
        <button type="button" class="btn btn-sm btn-outline-primary" id="reg-add-row"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Adaugă rând</button>
        <a class="btn btn-sm btn-primary" href="<?= e($intakeUrl) ?>"><i class="bi bi-magic me-1" aria-hidden="true"></i>Recepție factură (OCR)</a>
    </div>
</div>

<div class="row g-2 mb-3 align-items-end">
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="reg-vehicle-filter">Vehicul</label>
        <select class="form-select form-select-sm" id="reg-vehicle-filter">
            <option value="0">Toate vehiculele</option>
            <?php foreach ($vehicles as $vehicle): ?>
                <option value="<?= (int) $vehicle['id'] ?>" <?= (int) $vehicle['id'] === $selectedVehicleId ? 'selected' : '' ?>>
                    <?= e((string) $vehicle['nr_inmatriculare']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="reg-search">Caută în registru</label>
        <input type="search" class="form-control form-control-sm" id="reg-search" placeholder="piesă, furnizor, dată...">
    </div>
    <div class="col-12 col-md-7">
        <div class="d-flex gap-3 justify-content-md-end small text-muted flex-wrap">
            <span>Rânduri: <strong class="text-body"><?= e((string) $kpis['randuri']) ?></strong></span>
            <span>Total piese: <strong class="text-body"><?= e(format_number_ro($kpis['total_piese'])) ?> lei</strong></span>
            <span>Total manoperă: <strong class="text-body"><?= e(format_number_ro($kpis['total_manopera'])) ?> lei</strong></span>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="reg-export-csv"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Export CSV</button>
        </div>
    </div>
</div>

<div id="reg-error" class="alert alert-danger d-none py-2"></div>

<div class="card">
    <div class="reg-wrap">
        <table class="table table-sm table-striped table-hover reg-table mb-0" id="reg-table">
            <thead>
                <tr>
                    <th>Vehicul</th>
                    <th>Data</th>
                    <th>Reparații</th>
                    <th>Înlocuiri</th>
                    <th>Îmbunătățiri</th>
                    <th class="text-end">Preț</th>
                    <th>Furnizor</th>
                    <th class="text-end">Preț manoperă</th>
                    <th>Furnizor manoperă</th>
                    <th class="text-end">KM bord</th>
                    <th>Acțiuni</th>
                </tr>
            </thead>
            <tbody id="reg-body">
            <?php foreach ($rows as $row): ?>
                <tr data-row-id="<?= (int) $row['id'] ?>">
                    <td class="reg-cell" data-field="vehicle_id" data-raw="<?= (int) ($row['vehicle_id'] ?? 0) ?>"><?= e((string) ($row['vehicul'] ?? '')) ?></td>
                    <td class="reg-cell" data-field="data_interventie" data-raw="<?= e((string) ($row['data_interventie'] ?? '')) ?>"><?= e(format_date_ro($row['data_interventie'] ?? null)) ?></td>
                    <td class="reg-cell reg-text-cell" data-field="reparatii"><?= e((string) ($row['reparatii'] ?? '')) ?></td>
                    <td class="reg-cell reg-text-cell" data-field="inlocuiri"><?= e((string) ($row['inlocuiri'] ?? '')) ?></td>
                    <td class="reg-cell reg-text-cell" data-field="imbunatatiri"><?= e((string) ($row['imbunatatiri'] ?? '')) ?></td>
                    <td class="reg-cell reg-num" data-field="pret" data-raw="<?= e((string) ($row['pret'] ?? '')) ?>"><?= $row['pret'] !== null ? e(format_number_ro($row['pret'])) : '' ?></td>
                    <td class="reg-cell" data-field="furnizor"><?= e((string) ($row['furnizor'] ?? '')) ?></td>
                    <td class="reg-cell reg-num" data-field="pret_manopera" data-raw="<?= e((string) ($row['pret_manopera'] ?? '')) ?>"><?= $row['pret_manopera'] !== null ? e(format_number_ro($row['pret_manopera'])) : '' ?></td>
                    <td class="reg-cell" data-field="furnizor_manopera"><?= e((string) ($row['furnizor_manopera'] ?? '')) ?></td>
                    <td class="reg-cell reg-num" data-field="km_bord" data-raw="<?= e((string) ($row['km_bord'] ?? '')) ?>"><?= $row['km_bord'] !== null ? e(number_format((float) $row['km_bord'], 0, ',', '.')) : '' ?></td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-light border py-0 px-1 reg-menu-btn" title="Acțiuni"
                                <?php if (!empty($row['factura_fisier'])): ?>
                                    data-invoice-url="<?= e(url('uploads/ocr_piese/' . rawurlencode((string) $row['factura_fisier']))) ?>"
                                    data-invoice-label="<?= e((string) (($row['numar_factura'] ?? '') !== '' ? $row['numar_factura'] : 'fără număr')) ?>"
                                <?php elseif (!empty($row['factura_id'])): ?>
                                    data-invoice-missing="1"
                                <?php endif; ?>>
                            <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($rows === []): ?>
        <div class="text-center text-muted py-5" id="reg-empty">
            <i class="bi bi-inbox fs-1 d-block mb-2" aria-hidden="true"></i>
            Registrul este gol. Adaugă un rând manual sau folosește <strong>Recepție factură (OCR)</strong>.
        </div>
    <?php endif; ?>
</div>

<form class="d-none" id="reg-token-form"><?= csrf_field() ?></form>

<script>
(function () {
    'use strict';

    var ROW_ADD_URL = <?= json_encode($rowAddUrl, JSON_UNESCAPED_SLASHES) ?>;
    var ROW_UPDATE_URL = <?= json_encode($rowUpdateUrl, JSON_UNESCAPED_SLASHES) ?>;
    var ROW_DELETE_URL = <?= json_encode($rowDeleteUrl, JSON_UNESCAPED_SLASHES) ?>;
    var BASE_URL = <?= json_encode($baseUrl, JSON_UNESCAPED_SLASHES) ?>;
    var SELECTED_VEHICLE = <?= (int) $selectedVehicleId ?>;
    var VEHICLES = <?= json_encode(array_map(
        static fn (array $v): array => ['id' => (int) $v['id'], 'nr' => (string) $v['nr_inmatriculare']],
        $vehicles
    ), JSON_UNESCAPED_UNICODE) ?>;

    var body = document.getElementById('reg-body');
    var errorBox = document.getElementById('reg-error');
    var FIELD_TYPES = {
        vehicle_id: 'vehicle', data_interventie: 'date',
        reparatii: 'text', inlocuiri: 'text', imbunatatiri: 'text',
        pret: 'number', furnizor: 'text', pret_manopera: 'number',
        furnizor_manopera: 'text', km_bord: 'int'
    };

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('d-none');
        setTimeout(function () { errorBox.classList.add('d-none'); }, 6000);
    }

    function csrfToken() {
        var input = document.querySelector('#reg-token-form input[name="_token"]');
        return input ? input.value : '';
    }

    function postForm(url, data) {
        var formData = new FormData();
        formData.append('_token', csrfToken());
        Object.keys(data).forEach(function (key) { formData.append(key, data[key]); });
        return fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Răspuns neașteptat de la server (HTTP ' + response.status + ').');
                });
            })
            .then(function (payload) {
                if (!payload.ok) { throw new Error(payload.error || 'Operațiunea a eșuat.'); }
                return payload;
            });
    }

    function formatNumberRo(value, decimals) {
        return Number(value).toLocaleString('ro-RO', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    function formatDateRo(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        return m ? m[3] + '.' + m[2] + '.' + m[1] : '-';
    }

    function displayValue(field, normalized) {
        if (normalized === null || normalized === undefined || normalized === '') { return ''; }
        var type = FIELD_TYPES[field];
        if (type === 'number') { return formatNumberRo(parseFloat(normalized), 2); }
        if (type === 'int') { return formatNumberRo(parseInt(normalized, 10), 0); }
        if (type === 'date') { return formatDateRo(normalized); }
        if (type === 'vehicle') {
            var found = VEHICLES.filter(function (v) { return String(v.id) === String(normalized); });
            return found.length ? found[0].nr : '';
        }
        return normalized;
    }

    // --- Editare inline (click pe celula) ---
    var activeEditor = null;

    function closeEditor(save) {
        if (!activeEditor) { return; }
        var editor = activeEditor;
        activeEditor = null;

        var cell = editor.cell;
        var newValue = editor.input.value;
        cell.classList.remove('reg-editing');
        cell.textContent = editor.originalDisplay;

        if (!save || newValue === editor.originalRaw) { return; }

        var rowId = cell.closest('tr').dataset.rowId;
        var field = cell.dataset.field;

        postForm(ROW_UPDATE_URL, { row_id: rowId, field: field, value: newValue })
            .then(function (payload) {
                var normalized = payload.value;
                cell.dataset.raw = normalized === null ? '' : normalized;
                cell.textContent = displayValue(field, normalized);
                cell.classList.add('reg-saved');
                setTimeout(function () { cell.classList.remove('reg-saved'); }, 1100);
            })
            .catch(function (error) {
                showError(field + ': ' + error.message);
                cell.textContent = editor.originalDisplay;
            });
    }

    function openEditor(cell) {
        if (activeEditor) { closeEditor(true); }
        var field = cell.dataset.field;
        var type = FIELD_TYPES[field];
        var raw = cell.dataset.raw !== undefined ? cell.dataset.raw : cell.textContent.trim();

        var input;
        if (type === 'vehicle') {
            input = document.createElement('select');
            var emptyOpt = document.createElement('option');
            emptyOpt.value = '';
            emptyOpt.textContent = '—';
            input.appendChild(emptyOpt);
            VEHICLES.forEach(function (v) {
                var opt = document.createElement('option');
                opt.value = v.id;
                opt.textContent = v.nr;
                if (String(v.id) === String(raw) && raw !== '0') { opt.selected = true; }
                input.appendChild(opt);
            });
        } else {
            input = document.createElement('input');
            if (type === 'date') { input.type = 'date'; input.value = raw; }
            else if (type === 'number') { input.type = 'number'; input.step = '0.01'; input.min = '0'; input.value = raw; }
            else if (type === 'int') { input.type = 'number'; input.step = '1'; input.min = '0'; input.value = raw; }
            else { input.type = 'text'; input.value = cell.textContent.trim(); }
        }

        activeEditor = {
            cell: cell,
            input: input,
            originalDisplay: cell.textContent,
            originalRaw: (type === 'vehicle' || type === 'date' || type === 'number' || type === 'int') ? raw : cell.textContent.trim()
        };

        cell.textContent = '';
        cell.classList.add('reg-editing');
        cell.appendChild(input);
        input.focus();
        if (input.select) { try { input.select(); } catch (e) {} }

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); closeEditor(true); }
            if (event.key === 'Escape') { event.preventDefault(); closeEditor(false); }
        });
        input.addEventListener('blur', function () { closeEditor(true); });
        if (type === 'vehicle') {
            input.addEventListener('change', function () { closeEditor(true); });
        }
    }

    // --- Meniu de actiuni per rand (trei puncte) ---
    // Atasat de body si pozitionat fix, ca sa nu fie taiat de scroll-ul grilei.
    // Stergerea ramane in doi pasi (fara confirm() nativ - blocat in unele
    // browsere embedded): primul click "Sigur? Apasa din nou", al doilea sterge.
    var actionMenu = null;

    function closeActionMenu() {
        if (actionMenu) { actionMenu.remove(); actionMenu = null; }
    }

    function menuItem(html, className) {
        var item = document.createElement('button');
        item.type = 'button';
        item.className = 'reg-menu-item' + (className ? ' ' + className : '');
        item.innerHTML = html;
        return item;
    }

    function openActionMenu(menuBtn) {
        closeActionMenu();
        var tr = menuBtn.closest('tr');

        actionMenu = document.createElement('div');
        actionMenu.className = 'reg-action-menu';

        var invoiceUrl = menuBtn.dataset.invoiceUrl;
        if (invoiceUrl) {
            var link = document.createElement('a');
            link.className = 'reg-menu-item';
            link.href = invoiceUrl;
            link.target = '_blank';
            link.rel = 'noopener';
            link.innerHTML = '<i class="bi bi-file-earmark-text" aria-hidden="true"></i>Deschide factura (' + (menuBtn.dataset.invoiceLabel || '') + ')';
            link.addEventListener('click', closeActionMenu);
            actionMenu.appendChild(link);
            actionMenu.appendChild(Object.assign(document.createElement('div'), { className: 'reg-menu-sep' }));
        } else if (menuBtn.dataset.invoiceMissing) {
            var info = menuItem('<i class="bi bi-file-earmark-x" aria-hidden="true"></i>Din OCR, fără fișier atașat', 'disabled');
            info.disabled = true;
            actionMenu.appendChild(info);
            actionMenu.appendChild(Object.assign(document.createElement('div'), { className: 'reg-menu-sep' }));
        }

        var deleteItem = menuItem('<i class="bi bi-trash3" aria-hidden="true"></i>Șterge rândul', 'text-danger');
        deleteItem.addEventListener('click', function (event) {
            event.stopPropagation();
            if (!deleteItem.dataset.armed) {
                deleteItem.dataset.armed = '1';
                deleteItem.innerHTML = '<i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Sigur? Apasă din nou';
                return;
            }
            closeActionMenu();
            postForm(ROW_DELETE_URL, { row_id: tr.dataset.rowId })
                .then(function () { tr.remove(); })
                .catch(function (error) { showError(error.message); });
        });
        actionMenu.appendChild(deleteItem);

        document.body.appendChild(actionMenu);
        var btnRect = menuBtn.getBoundingClientRect();
        var menuRect = actionMenu.getBoundingClientRect();
        var left = Math.min(btnRect.left, window.innerWidth - menuRect.width - 8);
        var top = btnRect.bottom + 4;
        if (top + menuRect.height > window.innerHeight - 8) { top = btnRect.top - menuRect.height - 4; }
        actionMenu.style.left = Math.max(8, left) + 'px';
        actionMenu.style.top = Math.max(8, top) + 'px';
    }

    document.addEventListener('click', function (event) {
        if (actionMenu && !actionMenu.contains(event.target) && !event.target.closest('.reg-menu-btn')) {
            closeActionMenu();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeActionMenu(); }
    });
    document.querySelector('.reg-wrap').addEventListener('scroll', closeActionMenu);

    document.getElementById('reg-table').addEventListener('click', function (event) {
        var menuBtn = event.target.closest('.reg-menu-btn');
        if (menuBtn) {
            if (actionMenu) { closeActionMenu(); } else { openActionMenu(menuBtn); }
            return;
        }
        var cell = event.target.closest('td.reg-cell');
        if (cell && !cell.classList.contains('reg-editing')) { openEditor(cell); }
    });

    // --- Adauga rand gol (ca in Excel) ---
    document.getElementById('reg-add-row').addEventListener('click', function () {
        postForm(ROW_ADD_URL, { vehicle_id: SELECTED_VEHICLE })
            .then(function (payload) {
                var empty = document.getElementById('reg-empty');
                if (empty) { empty.remove(); }
                var tr = document.createElement('tr');
                tr.dataset.rowId = payload.row_id;
                var fields = ['vehicle_id', 'data_interventie', 'reparatii', 'inlocuiri', 'imbunatatiri',
                    'pret', 'furnizor', 'pret_manopera', 'furnizor_manopera', 'km_bord'];
                fields.forEach(function (field) {
                    var td = document.createElement('td');
                    td.className = 'reg-cell' + (FIELD_TYPES[field] === 'number' || FIELD_TYPES[field] === 'int' ? ' reg-num' : '')
                        + (FIELD_TYPES[field] === 'text' && field !== 'furnizor' && field !== 'furnizor_manopera' ? ' reg-text-cell' : '');
                    td.dataset.field = field;
                    if (field === 'vehicle_id') {
                        td.dataset.raw = String(SELECTED_VEHICLE || 0);
                        td.textContent = displayValue('vehicle_id', SELECTED_VEHICLE || '');
                    } else if (field === 'data_interventie') {
                        td.dataset.raw = payload.data_interventie;
                        td.textContent = formatDateRo(payload.data_interventie);
                    } else {
                        td.dataset.raw = '';
                    }
                    tr.appendChild(td);
                });
                var actionTd = document.createElement('td');
                actionTd.className = 'text-center';
                actionTd.innerHTML = '<button type="button" class="btn btn-sm btn-light border py-0 px-1 reg-menu-btn" title="Acțiuni"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>';
                tr.appendChild(actionTd);
                body.appendChild(tr);
                tr.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                var firstText = tr.querySelector('td[data-field="reparatii"]');
                if (firstText) { openEditor(firstText); }
            })
            .catch(function (error) { showError(error.message); });
    });

    // --- Filtru vehicul (reincarcare cu parametru) ---
    document.getElementById('reg-vehicle-filter').addEventListener('change', function () {
        var id = parseInt(this.value, 10) || 0;
        window.location.href = id > 0 ? BASE_URL + '&vehicul=' + id : BASE_URL;
    });

    // --- Cautare client-side ---
    document.getElementById('reg-search').addEventListener('input', function () {
        var needle = this.value.trim().toLowerCase();
        Array.prototype.forEach.call(body.querySelectorAll('tr'), function (tr) {
            tr.style.display = needle === '' || tr.textContent.toLowerCase().indexOf(needle) !== -1 ? '' : 'none';
        });
    });

    // --- Export CSV ---
    document.getElementById('reg-export-csv').addEventListener('click', function () {
        var headers = ['Vehicul', 'Data', 'Reparatii', 'Inlocuiri', 'Imbunatatiri', 'Pret', 'Furnizor', 'Pret manopera', 'Furnizor manopera', 'KM bord'];
        var csvRows = [headers.join(';')];
        Array.prototype.forEach.call(body.querySelectorAll('tr'), function (tr) {
            if (tr.style.display === 'none') { return; }
            var cells = tr.querySelectorAll('td.reg-cell');
            var values = Array.prototype.map.call(cells, function (td) {
                return '"' + td.textContent.trim().replace(/"/g, '""') + '"';
            });
            csvRows.push(values.join(';'));
        });
        var blob = new Blob(['﻿' + csvRows.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'registru_piese.csv';
        link.click();
        URL.revokeObjectURL(link.href);
    });
})();
</script>
