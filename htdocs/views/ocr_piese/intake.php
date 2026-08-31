<?php
$apiKeyConfigured = !empty($apiKeyConfigured);
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$maxFileBytes = (int) ($maxFileBytes ?? 1048576);
$maxImageBytes = (int) ($maxImageBytes ?? $maxFileBytes);
$maxFileMb = number_format($maxFileBytes / 1048576, 1, ',', '.');
$maxImageMb = number_format($maxImageBytes / 1048576, 0, ',', '.');
$runUrl = build_query_url(['page' => 'ocr_piese', 'action' => 'run']);
$saveUrl = build_query_url(['page' => 'ocr_piese', 'action' => 'save']);
$trackerUrl = build_query_url(['page' => 'ocr_piese']);
?>
<style>
    .ocr-dropzone {
        border: 2px dashed #adb5bd;
        border-radius: .75rem;
        background: #f8f9fa;
        cursor: pointer;
        transition: border-color .15s, background .15s;
    }
    .ocr-dropzone.dragover { border-color: #0d6efd; background: #e7f1ff; }
    .ocr-lines-table input.form-control { min-width: 4.5rem; }
    .ocr-lines-table td { vertical-align: middle; }
    .ocr-lines-table tr.ocr-line-unverified { background: #fff8e1; }
    .ocr-ref-text {
        max-height: 320px;
        overflow: auto;
        white-space: pre-wrap;
        word-break: break-word;
        font-family: var(--bs-font-monospace);
        font-size: .8rem;
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h5 mb-0"><i class="bi bi-receipt-cutoff me-1" aria-hidden="true"></i>Recepție factură piese (OCR)</h1>
        <div class="text-muted small">Încarcă factura, lasă OCR-ul să propună articolele, corectează și salvează în tracker.</div>
    </div>
    <div class="d-flex gap-2">
        <span class="badge text-bg-warning text-dark align-self-center"><i class="bi bi-cone-striped me-1" aria-hidden="true"></i>EXPERIMENTAL — separat de Stoc Piese</span>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e($trackerUrl) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Înapoi la tracker</a>
    </div>
</div>

<?php if (!$apiKeyConfigured): ?>
    <div class="alert alert-warning">
        <strong>Cheia OCR lipsește.</strong> Adaugă <code>OCR_SPACE_API_KEY</code> în <code>.env</code> pentru citirea automată.
        Poți totuși completa formularul manual mai jos.
    </div>
<?php endif; ?>

<!-- Pasul 1: fisier + OCR -->
<div class="card mb-3">
    <div class="card-header py-2 fw-semibold"><i class="bi bi-1-circle me-1" aria-hidden="true"></i>Factura</div>
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-lg-7">
                <div class="ocr-dropzone p-3 text-center" id="ocr-dropzone" role="button" tabindex="0"
                     aria-label="Trage factura aici sau apasă pentru a selecta un fișier">
                    <i class="bi bi-file-earmark-arrow-up fs-2 text-secondary" aria-hidden="true"></i>
                    <div class="fw-semibold">Trage factura aici sau apasă pentru selectare</div>
                    <div class="text-muted small">PDF max <?= e($maxFileMb) ?> MB (limită OCR.Space gratuit) &middot; JPG / PNG max <?= e($maxImageMb) ?> MB (comprimăm automat)</div>
                    <input type="file" id="ocr-file-input" class="d-none" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>
            <div class="col-lg-5">
                <div class="small text-muted" id="ocr-file-label">Niciun fișier selectat.</div>
                <div class="d-flex gap-2 mt-2">
                    <button type="button" class="btn btn-primary" id="ocr-run-btn" disabled>
                        <span class="spinner-border spinner-border-sm d-none me-1" id="ocr-run-spinner" aria-hidden="true"></span>
                        <i class="bi bi-magic me-1" aria-hidden="true"></i>Citește factura (OCR)
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="ocr-manual-btn">Completez manual</button>
                </div>
                <div class="small text-muted mt-2" id="ocr-run-status"></div>
            </div>
        </div>
        <div id="ocr-error" class="alert alert-danger mt-3 mb-0 d-none"></div>
    </div>
</div>

<!-- Pasul 2: formular de verificare (ascuns pana la OCR sau "manual") -->
<div id="ocr-review" class="d-none">
    <div class="card mb-3">
        <div class="card-header py-2 fw-semibold"><i class="bi bi-2-circle me-1" aria-hidden="true"></i>Date factură <span class="text-muted small fw-normal">(verifică valorile propuse de OCR)</span></div>
        <div class="card-body">
            <div class="row g-2">
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="f-numar">Număr factură</label>
                    <input type="text" class="form-control form-control-sm" id="f-numar" maxlength="80">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="f-data">Data facturii</label>
                    <input type="date" class="form-control form-control-sm" id="f-data">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1" for="f-furnizor">Furnizor</label>
                    <input type="text" class="form-control form-control-sm" id="f-furnizor" maxlength="190">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="f-cui">CUI furnizor</label>
                    <input type="text" class="form-control form-control-sm" id="f-cui" maxlength="20">
                </div>
                <div class="col-3 col-md-1">
                    <label class="form-label small mb-1" for="f-moneda">Monedă</label>
                    <select class="form-select form-select-sm" id="f-moneda">
                        <option value="RON">RON</option>
                        <option value="EUR">EUR</option>
                        <option value="USD">USD</option>
                    </select>
                </div>
                <div class="col-3 col-md-2">
                    <label class="form-label small mb-1" for="f-total">Total factură</label>
                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="f-total">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="f-vehicul">Vehicul (pentru registru)</label>
                    <select class="form-select form-select-sm" id="f-vehicul">
                        <option value="0">— fără vehicul —</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= (int) $vehicle['id'] ?>"><?= e((string) $vehicle['nr_inmatriculare']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="f-km">KM bord</label>
                    <input type="number" step="1" min="0" class="form-control form-control-sm" id="f-km" placeholder="Opțional">
                </div>
            </div>
            <div class="mt-2">
                <label class="form-label small mb-1" for="f-observatii">Observații</label>
                <input type="text" class="form-control form-control-sm" id="f-observatii" maxlength="500" placeholder="Opțional">
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="fw-semibold"><i class="bi bi-3-circle me-1" aria-hidden="true"></i>Articole (piese)</span>
            <div class="d-flex align-items-center gap-2 small">
                <span class="badge text-bg-warning text-dark d-none" id="ocr-unverified-hint">
                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Rândurile galbene nu au trecut verificarea cantitate &times; preț = valoare
                </span>
                <button type="button" class="btn btn-sm btn-outline-primary" id="ocr-add-line">
                    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Adaugă rând
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm ocr-lines-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:12rem">Denumire *</th>
                            <th style="min-width:6rem">Cod piesă</th>
                            <th style="min-width:7rem">Tip</th>
                            <th style="min-width:7.5rem">Tip lucrare</th>
                            <th style="min-width:8.5rem">Destinație</th>
                            <th style="min-width:7rem">Vehicul</th>
                            <th style="min-width:4rem">U.M.</th>
                            <th style="min-width:4.5rem" class="text-end">Cant.</th>
                            <th style="min-width:5.5rem" class="text-end">Preț unitar</th>
                            <th style="min-width:5.5rem" class="text-end">Valoare</th>
                            <th>Sursă</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="ocr-lines-body"></tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="6" class="text-end fw-semibold">Total articole:</td>
                            <td class="text-end fw-semibold" id="ocr-lines-total">0,00</td>
                            <td colspan="2"><span class="small" id="ocr-total-diff"></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2">
            <button class="btn btn-sm fw-semibold p-0 border-0 collapsed" type="button"
                    data-bs-toggle="collapse" data-bs-target="#ocr-ref-body" aria-expanded="false" aria-controls="ocr-ref-body">
                <i class="bi bi-chevron-expand me-1" aria-hidden="true"></i>Text OCR de referință (pentru corecturi)
            </button>
        </div>
        <div class="collapse" id="ocr-ref-body">
            <pre class="ocr-ref-text p-3 mb-0" id="ocr-ref-text">(fără text OCR — completare manuală)</pre>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-4">
        <a class="btn btn-outline-secondary" href="<?= e($trackerUrl) ?>">Renunță</a>
        <button type="button" class="btn btn-success" id="ocr-save-btn">
            <span class="spinner-border spinner-border-sm d-none me-1" id="ocr-save-spinner" aria-hidden="true"></span>
            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Salvează în tracker
        </button>
    </div>
</div>

<form class="d-none" id="ocr-token-form"><?= csrf_field() ?></form>

<script>
(function () {
    'use strict';

    var RUN_URL = <?= json_encode($runUrl, JSON_UNESCAPED_SLASHES) ?>;
    var SAVE_URL = <?= json_encode($saveUrl, JSON_UNESCAPED_SLASHES) ?>;
    var MAX_BYTES = <?= (int) $maxFileBytes ?>;
    var MAX_IMG_BYTES = <?= (int) $maxImageBytes ?>;
    var VEHICLES = <?= json_encode(array_map(
        static fn (array $v): array => ['id' => (int) $v['id'], 'nr' => (string) $v['nr_inmatriculare']],
        $vehicles
    ), JSON_UNESCAPED_UNICODE) ?>;
    var TIP_LUCRARE_OPTIONS = <?= json_encode(
        array_map(null, array_keys(OcrPartsModel::TIP_LUCRARE_OPTIONS), array_values(OcrPartsModel::TIP_LUCRARE_OPTIONS)),
        JSON_UNESCAPED_UNICODE
    ) ?>;
    var API_KEY_CONFIGURED = <?= $apiKeyConfigured ? 'true' : 'false' ?>;
    var ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

    var dropzone = document.getElementById('ocr-dropzone');
    var fileInput = document.getElementById('ocr-file-input');
    var fileLabel = document.getElementById('ocr-file-label');
    var runBtn = document.getElementById('ocr-run-btn');
    var runSpinner = document.getElementById('ocr-run-spinner');
    var runStatus = document.getElementById('ocr-run-status');
    var manualBtn = document.getElementById('ocr-manual-btn');
    var errorBox = document.getElementById('ocr-error');
    var reviewWrap = document.getElementById('ocr-review');
    var linesBody = document.getElementById('ocr-lines-body');
    var saveBtn = document.getElementById('ocr-save-btn');
    var saveSpinner = document.getElementById('ocr-save-spinner');

    var selectedFile = null;
    var ocrText = '';
    var ocrDurationMs = null;

    function showError(message) { errorBox.textContent = message; errorBox.classList.remove('d-none'); }
    function clearError() { errorBox.classList.add('d-none'); errorBox.textContent = ''; }

    function csrfToken() {
        var input = document.querySelector('#ocr-token-form input[name="_token"]');
        return input ? input.value : '';
    }

    function formatBytes(bytes) {
        if (bytes >= 1048576) { return (bytes / 1048576).toFixed(2).replace('.', ',') + ' MB'; }
        if (bytes >= 1024) { return (bytes / 1024).toFixed(1).replace('.', ',') + ' KB'; }
        return bytes + ' B';
    }

    function formatMoney(value) {
        return (isFinite(value) ? value : 0).toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // "3.962,75" / "3962.75" / "6,45" -> numar JS
    function parseRoNumber(raw) {
        if (raw === null || raw === undefined) { return null; }
        var text = String(raw).trim();
        if (text === '') { return null; }
        if (/^\d{1,3}(\.\d{3})+,\d{1,2}$/.test(text)) { return parseFloat(text.replace(/\./g, '').replace(',', '.')); }
        if (/^\d{1,3}(,\d{3})+\.\d{1,2}$/.test(text)) { return parseFloat(text.replace(/,/g, '')); }
        var normalized = parseFloat(text.replace(',', '.'));
        return isNaN(normalized) ? null : normalized;
    }

    // "21.08.2026" -> "2026-08-21" pentru input type=date
    function roDateToIso(raw) {
        if (!raw) { return ''; }
        var match = /^(\d{1,2})\.(\d{1,2})\.(\d{4})$/.exec(String(raw).trim());
        if (!match) { return ''; }
        return match[3] + '-' + ('0' + match[2]).slice(-2) + '-' + ('0' + match[1]).slice(-2);
    }

    function setSelectedFile(file) {
        clearError();
        if (!file) { return; }
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        if (ALLOWED_EXT.indexOf(ext) === -1) {
            showError('Tip de fișier neacceptat. Formate permise: PDF, JPG, JPEG, PNG.');
            return;
        }
        if (ext === 'pdf' && file.size > MAX_BYTES) {
            showError('PDF-ul are ' + formatBytes(file.size) + ' și depășește limita OCR.Space gratuit (' + formatBytes(MAX_BYTES) +
                '). Alternativă: fotografiază factura (JPG/PNG până la ' + formatBytes(MAX_IMG_BYTES) + ' — o comprimăm automat).');
            return;
        }
        if (ext !== 'pdf' && file.size > MAX_IMG_BYTES) {
            showError('Imaginea are ' + formatBytes(file.size) + ' și depășește limita de ' + formatBytes(MAX_IMG_BYTES) + '.');
            return;
        }
        selectedFile = file;
        fileLabel.textContent = file.name + ' (' + formatBytes(file.size) + ')';
        runBtn.disabled = !API_KEY_CONFIGURED;
    }

    function addLineRow(line) {
        line = line || {};
        var row = document.createElement('tr');
        if (line.din_ocr && line.verificat === false) { row.classList.add('ocr-line-unverified'); }

        function cell(input) { var td = document.createElement('td'); td.appendChild(input); return td; }
        function textInput(value, maxLength, listId) {
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-sm';
            input.value = value || '';
            if (maxLength) { input.maxLength = maxLength; }
            if (listId) { input.setAttribute('list', listId); }
            return input;
        }
        function numberInput(value, cls) {
            var input = document.createElement('input');
            input.type = 'number';
            input.step = '0.01';
            input.min = '0';
            input.className = 'form-control form-control-sm text-end ' + cls;
            if (value !== null && value !== undefined) { input.value = value; }
            return input;
        }

        var nameInput = textInput(line.denumire, 255);
        nameInput.classList.add('ocr-in-name');
        nameInput.required = true;
        if (line.linie_sursa) { nameInput.title = 'Linie OCR: ' + line.linie_sursa; }

        var codeInput = textInput(line.cod_piesa, 80); codeInput.classList.add('ocr-in-code');
        function makeSelect(className, options, selected) {
            var select = document.createElement('select');
            select.className = 'form-select form-select-sm ' + className;
            options.forEach(function (opt) {
                var option = document.createElement('option');
                option.value = opt[0];
                option.textContent = opt[1];
                if (String(opt[0]) === String(selected)) { option.selected = true; }
                select.appendChild(option);
            });
            return select;
        }

        var typeSelect = makeSelect('ocr-in-tip', [['piesa', 'Piesă'], ['manopera', 'Manoperă']], line.tip || 'piesa');
        var tlSelect = makeSelect('ocr-in-tl', TIP_LUCRARE_OPTIONS, line.tip_lucrare || 'inlocuire');
        var destSelect = makeSelect('ocr-in-dest', [['vehicul', 'Montează pe vehicul'], ['stoc', 'Trimite în stoc']], line.destinatie || 'vehicul');
        var vehSelect = makeSelect('ocr-in-veh', [['', '—']].concat(VEHICLES.map(function (v) { return [v.id, v.nr]; })),
            line.vehicle_id || document.getElementById('f-vehicul').value || '');
        typeSelect.addEventListener('change', function () {
            // Manopera nu merge in stoc.
            if (typeSelect.value === 'manopera') { destSelect.value = 'vehicul'; destSelect.disabled = true; tlSelect.value = 'reparatie'; }
            else { destSelect.disabled = false; }
        });
        if ((line.tip || 'piesa') === 'manopera') { destSelect.disabled = true; }
        var umInput = textInput(line.unitate_masura || 'buc', 30); umInput.classList.add('ocr-in-um');
        var qtyInput = numberInput(line.cantitate !== undefined && line.cantitate !== null ? line.cantitate : 1, 'ocr-in-qty');
        var priceInput = numberInput(line.pret_unitar, 'ocr-in-price');
        var valueInput = numberInput(line.valoare, 'ocr-in-value');

        function recompute() {
            var qty = parseFloat(qtyInput.value);
            var price = parseFloat(priceInput.value);
            if (isFinite(qty) && isFinite(price)) {
                valueInput.value = (Math.round(qty * price * 100) / 100).toFixed(2);
            }
            row.classList.remove('ocr-line-unverified');
            refreshTotals();
        }
        qtyInput.addEventListener('input', recompute);
        priceInput.addEventListener('input', recompute);
        valueInput.addEventListener('input', function () {
            row.classList.remove('ocr-line-unverified');
            refreshTotals();
        });

        row.appendChild(cell(nameInput));
        row.appendChild(cell(codeInput));
        row.appendChild(cell(typeSelect));
        row.appendChild(cell(tlSelect));
        row.appendChild(cell(destSelect));
        row.appendChild(cell(vehSelect));
        row.appendChild(cell(umInput));
        row.appendChild(cell(qtyInput));
        row.appendChild(cell(priceInput));
        row.appendChild(cell(valueInput));

        var sourceTd = document.createElement('td');
        var sourceBadge = document.createElement('span');
        sourceBadge.className = line.din_ocr ? 'badge text-bg-info text-dark' : 'badge text-bg-secondary';
        sourceBadge.textContent = line.din_ocr ? 'OCR' : 'manual';
        row.dataset.dinOcr = line.din_ocr ? '1' : '0';
        sourceTd.appendChild(sourceBadge);
        row.appendChild(sourceTd);

        var removeTd = document.createElement('td');
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-sm btn-outline-danger';
        removeBtn.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
        removeBtn.title = 'Șterge rândul';
        removeBtn.addEventListener('click', function () { row.remove(); refreshTotals(); });
        removeTd.appendChild(removeBtn);
        row.appendChild(removeTd);

        linesBody.appendChild(row);
        refreshTotals();
        return row;
    }

    function refreshTotals() {
        var sum = 0;
        linesBody.querySelectorAll('.ocr-in-value').forEach(function (input) {
            var value = parseFloat(input.value);
            if (isFinite(value)) { sum += value; }
        });
        document.getElementById('ocr-lines-total').textContent = formatMoney(sum);

        var invoiceTotal = parseFloat(document.getElementById('f-total').value);
        var diffEl = document.getElementById('ocr-total-diff');
        if (isFinite(invoiceTotal) && invoiceTotal > 0) {
            var diff = sum - invoiceTotal;
            if (Math.abs(diff) < 0.01) {
                diffEl.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> = total factură</span>';
            } else {
                diffEl.innerHTML = '<span class="text-danger">' + (diff > 0 ? '+' : '') + formatMoney(diff) + ' vs total factură (diferența poate fi TVA)</span>';
            }
        } else {
            diffEl.textContent = '';
        }

        var hasUnverified = linesBody.querySelector('.ocr-line-unverified') !== null;
        document.getElementById('ocr-unverified-hint').classList.toggle('d-none', !hasUnverified);
    }

    function openReview(payload) {
        reviewWrap.classList.remove('d-none');
        linesBody.innerHTML = '';

        if (payload) {
            var header = payload.header || {};
            document.getElementById('f-numar').value = header.numar_factura || '';
            document.getElementById('f-data').value = roDateToIso(header.data_facturii);
            document.getElementById('f-furnizor').value = header.furnizor || '';
            document.getElementById('f-cui').value = header.cui || '';
            if (header.moneda) { document.getElementById('f-moneda').value = header.moneda; }
            var total = parseRoNumber(header.total);
            document.getElementById('f-total').value = total !== null ? total.toFixed(2) : '';

            ocrText = payload.parsed_text || '';
            ocrDurationMs = payload.duration_ms || null;
            document.getElementById('ocr-ref-text').textContent = ocrText !== '' ? ocrText : '(fără text OCR)';

            (payload.lines || []).forEach(function (line) {
                line.din_ocr = true;
                addLineRow(line);
            });
            if (!(payload.lines || []).length) {
                addLineRow({});
            }
        } else {
            ocrText = '';
            ocrDurationMs = null;
            document.getElementById('ocr-ref-text').textContent = '(fără text OCR — completare manuală)';
            addLineRow({});
        }

        refreshTotals();
        reviewWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function runOcr() {
        if (!selectedFile) { return; }
        clearError();
        runBtn.disabled = true;
        runSpinner.classList.remove('d-none');
        runStatus.textContent = 'Se citește factura cu OCR.Space...';

        var formData = new FormData();
        formData.append('invoice', selectedFile, selectedFile.name);
        formData.append('_token', csrfToken());

        fetch(RUN_URL, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Serverul a răspuns într-un format neașteptat (HTTP ' + response.status + ').');
                });
            })
            .then(function (payload) {
                if (!payload.ok) {
                    throw new Error((payload.error || 'OCR eșuat.') + (payload.error_details ? ' — ' + payload.error_details : ''));
                }
                runStatus.textContent = 'OCR finalizat în ' + ((payload.duration_ms || 0) / 1000).toFixed(2).replace('.', ',') +
                    ' sec — ' + (payload.lines || []).length + ' articole propuse. Verifică și corectează mai jos.' +
                    (payload.compression_note ? ' ' + payload.compression_note : '');
                if (payload.parse_warning) { showError(payload.parse_warning); }
                openReview(payload);
            })
            .catch(function (error) {
                showError('Citirea OCR a eșuat: ' + error.message);
                runStatus.textContent = '';
            })
            .finally(function () {
                runBtn.disabled = !selectedFile || !API_KEY_CONFIGURED;
                runSpinner.classList.add('d-none');
            });
    }

    function collectLines() {
        var lines = [];
        var invalid = false;
        linesBody.querySelectorAll('tr').forEach(function (row) {
            var name = row.querySelector('.ocr-in-name').value.trim();
            if (name === '') { invalid = true; row.querySelector('.ocr-in-name').classList.add('is-invalid'); return; }
            row.querySelector('.ocr-in-name').classList.remove('is-invalid');
            lines.push({
                denumire: name,
                cod_piesa: row.querySelector('.ocr-in-code').value.trim(),
                tip: row.querySelector('.ocr-in-tip').value,
                tip_lucrare: row.querySelector('.ocr-in-tl').value,
                destinatie: row.querySelector('.ocr-in-dest').value,
                vehicle_id: row.querySelector('.ocr-in-veh').value,
                unitate_masura: row.querySelector('.ocr-in-um').value.trim() || 'buc',
                cantitate: parseFloat(row.querySelector('.ocr-in-qty').value) || 0,
                pret_unitar: parseFloat(row.querySelector('.ocr-in-price').value) || 0,
                valoare: parseFloat(row.querySelector('.ocr-in-value').value) || 0,
                din_ocr: row.dataset.dinOcr === '1'
            });
        });
        return { lines: lines, invalid: invalid };
    }

    function saveTracker() {
        clearError();
        var collected = collectLines();
        if (collected.invalid) {
            showError('Există rânduri fără denumire. Completează-le sau șterge-le.');
            return;
        }
        if (!collected.lines.length) {
            showError('Adaugă cel puțin un articol înainte de salvare.');
            return;
        }

        saveBtn.disabled = true;
        saveSpinner.classList.remove('d-none');

        var formData = new FormData();
        formData.append('_token', csrfToken());
        formData.append('numar_factura', document.getElementById('f-numar').value);
        formData.append('data_facturii', document.getElementById('f-data').value);
        formData.append('furnizor', document.getElementById('f-furnizor').value);
        formData.append('cui_furnizor', document.getElementById('f-cui').value);
        formData.append('moneda', document.getElementById('f-moneda').value);
        formData.append('total_factura', document.getElementById('f-total').value);
        formData.append('observatii', document.getElementById('f-observatii').value);
        formData.append('vehicle_id', document.getElementById('f-vehicul').value);
        formData.append('km_bord', document.getElementById('f-km').value);
        formData.append('ocr_text', ocrText);
        if (ocrDurationMs !== null) { formData.append('ocr_durata_ms', String(ocrDurationMs)); }
        formData.append('lines', JSON.stringify(collected.lines));
        if (selectedFile) { formData.append('invoice', selectedFile, selectedFile.name); }

        fetch(SAVE_URL, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Serverul a răspuns într-un format neașteptat (HTTP ' + response.status + ').');
                });
            })
            .then(function (payload) {
                if (!payload.ok) { throw new Error(payload.error || 'Salvarea a eșuat.'); }
                window.location.href = payload.redirect;
            })
            .catch(function (error) {
                showError(error.message);
                saveBtn.disabled = false;
                saveSpinner.classList.add('d-none');
            });
    }

    // Wiring
    dropzone.addEventListener('click', function () { fileInput.click(); });
    dropzone.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); fileInput.click(); }
    });
    fileInput.addEventListener('change', function () { setSelectedFile(fileInput.files[0] || null); });
    ['dragenter', 'dragover'].forEach(function (type) {
        dropzone.addEventListener(type, function (event) { event.preventDefault(); dropzone.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        dropzone.addEventListener(type, function (event) { event.preventDefault(); dropzone.classList.remove('dragover'); });
    });
    dropzone.addEventListener('drop', function (event) {
        var files = event.dataTransfer && event.dataTransfer.files;
        if (files && files.length) { setSelectedFile(files[0]); }
    });

    runBtn.addEventListener('click', runOcr);
    manualBtn.addEventListener('click', function () { openReview(null); });
    document.getElementById('ocr-add-line').addEventListener('click', function () { addLineRow({}); });
    document.getElementById('f-total').addEventListener('input', refreshTotals);
    saveBtn.addEventListener('click', saveTracker);
})();
</script>
