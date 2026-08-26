<?php
$apiKeyConfigured = !empty($apiKeyConfigured);
$engineLabel = (string) ($engineLabel ?? 'OCR.Space');
$maxFileBytes = (int) ($maxFileBytes ?? 1048576);
$maxImageBytes = (int) ($maxImageBytes ?? $maxFileBytes);
$allowedExtensions = (array) ($allowedExtensions ?? ['pdf', 'jpg', 'jpeg', 'png']);
$runUrl = build_query_url(['page' => 'dev_ocr_test', 'action' => 'run']);
$maxFileMb = number_format($maxFileBytes / 1048576, 1, ',', '.');
$maxImageMb = number_format($maxImageBytes / 1048576, 0, ',', '.');
?>
<style>
    /* Stiluri locale doar pentru sandbox-ul OCR - pagina este un utilitar de dev. */
    .ocr-dropzone {
        border: 2px dashed #adb5bd;
        border-radius: .75rem;
        background: #f8f9fa;
        cursor: pointer;
        transition: border-color .15s, background .15s;
    }
    .ocr-dropzone.dragover { border-color: #0d6efd; background: #e7f1ff; }
    .ocr-text-panel {
        max-height: 480px;
        overflow: auto;
        white-space: pre-wrap;
        word-break: break-word;
        font-family: var(--bs-font-monospace);
        font-size: .85rem;
        background: #fff;
    }
    .ocr-json-panel {
        max-height: 420px;
        overflow: auto;
        font-family: var(--bs-font-monospace);
        font-size: .8rem;
        white-space: pre;
        background: #212529;
        color: #a5d6ff;
    }
    .ocr-preview-frame { max-height: 260px; overflow: hidden; }
    .ocr-preview-frame img { max-width: 100%; max-height: 240px; object-fit: contain; }
    .ocr-preview-frame iframe { width: 100%; height: 240px; border: 0; }
    .ocr-fields-table td:first-child { width: 40%; color: #6c757d; }
    .ocr-fields-table td { vertical-align: middle; }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h5 mb-0"><i class="bi bi-flask me-1" aria-hidden="true"></i>OCR Invoice Sandbox</h1>
        <div class="text-muted small">Testează OCR.Space pe facturi românești — pagină experimentală de dezvoltare.</div>
    </div>
    <span class="badge text-bg-warning text-dark"><i class="bi bi-cone-striped me-1" aria-hidden="true"></i>DEV / TESTARE — nu salvează nimic în aplicație</span>
</div>

<?php if (!$apiKeyConfigured): ?>
    <div class="alert alert-warning">
        <strong>Cheia API lipsește.</strong> Adaugă <code>OCR_SPACE_API_KEY=cheia_ta</code> în fișierul
        <code>.env</code> din rădăcina proiectului, apoi reîncarcă pagina. Cheia este folosită doar pe server
        și nu ajunge niciodată în browser.
    </div>
<?php endif; ?>

<!-- Sectiunea 1: upload factura -->
<div class="card mb-3">
    <div class="card-header py-2 fw-semibold"><i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>1. Încarcă factura</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="ocr-dropzone p-4 text-center" id="ocr-dropzone" role="button" tabindex="0"
                     aria-label="Trage factura aici sau apasă pentru a selecta un fișier">
                    <i class="bi bi-file-earmark-arrow-up fs-1 text-secondary" aria-hidden="true"></i>
                    <div class="fw-semibold mt-2">Trage factura aici</div>
                    <div class="text-muted small mb-2">PDF maxim <?= e($maxFileMb) ?> MB (limita OCR.Space gratuit; max 3 pagini) &middot; JPG / PNG maxim <?= e($maxImageMb) ?> MB (comprimare automată)</div>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="ocr-pick-btn">Selectează fișier</button>
                    <input type="file" id="ocr-file-input" class="d-none" accept=".pdf,.jpg,.jpeg,.png">
                </div>
                <div class="d-flex align-items-center gap-2 mt-3">
                    <button type="button" class="btn btn-primary" id="ocr-run-btn" disabled>
                        <span class="spinner-border spinner-border-sm d-none me-1" id="ocr-run-spinner" aria-hidden="true"></span>
                        Testează OCR
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="ocr-reset-btn" disabled>Renunță</button>
                </div>
            </div>
            <div class="col-lg-5">
                <div id="ocr-file-info" class="d-none">
                    <table class="table table-sm mb-2">
                        <tbody>
                            <tr><td class="text-muted">Fișier</td><td id="ocr-file-name"></td></tr>
                            <tr><td class="text-muted">Dimensiune</td><td id="ocr-file-size"></td></tr>
                            <tr><td class="text-muted">Tip</td><td id="ocr-file-type"></td></tr>
                        </tbody>
                    </table>
                    <div class="border rounded p-2 bg-light ocr-preview-frame" id="ocr-preview"></div>
                </div>
                <div class="text-muted small" id="ocr-file-empty">Niciun fișier selectat încă.</div>
            </div>
        </div>
    </div>
</div>

<div id="ocr-client-error" class="alert alert-danger d-none"></div>

<!-- Rezultate OCR (ascunse pana la primul test) -->
<div id="ocr-results" class="d-none">

    <!-- Sectiunea 2: status OCR -->
    <div class="card mb-3">
        <div class="card-header py-2 fw-semibold"><i class="bi bi-activity me-1" aria-hidden="true"></i>2. Status OCR</div>
        <div class="card-body py-3">
            <div id="ocr-status-badge" class="mb-2"></div>
            <div class="row g-2 small">
                <div class="col-6 col-md-3"><span class="text-muted d-block">Engine</span><span id="ocr-status-engine">-</span></div>
                <div class="col-6 col-md-3"><span class="text-muted d-block">Durată (măsurată de aplicație)</span><span id="ocr-status-duration">-</span></div>
                <div class="col-6 col-md-3"><span class="text-muted d-block">Fișier</span><span id="ocr-status-file">-</span></div>
                <div class="col-6 col-md-3"><span class="text-muted d-block">Dimensiune</span><span id="ocr-status-size">-</span></div>
            </div>
            <div id="ocr-status-error" class="alert alert-danger mt-3 mb-0 d-none"></div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <!-- Sectiunea 3: text recunoscut (rezultatul OCR brut) -->
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-body-text me-1" aria-hidden="true"></i>Text detectat <span class="text-muted fw-normal small">(recunoaștere OCR, neprelucrată)</span></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-ocr-copy="text">
                        <i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copiază text
                    </button>
                </div>
                <div class="card-body p-0">
                    <pre class="ocr-text-panel p-3 mb-0" id="ocr-parsed-text"></pre>
                </div>
            </div>
        </div>

        <!-- Sectiunea 4: campuri factura detectate (parsarea NOASTRA experimentala) -->
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header py-2 fw-semibold">
                    <i class="bi bi-ui-checks me-1" aria-hidden="true"></i>Câmpuri factură detectate
                    <span class="badge text-bg-warning text-dark ms-1">parsare experimentală proprie</span>
                </div>
                <div class="card-body py-2">
                    <div class="text-muted small mb-2">
                        Aceste valori NU vin de la OCR.Space — sunt extrase de reguli simple (regex) din textul
                        de alături și pot greși. Scopul: să vedem dacă greșește OCR-ul sau parserul nostru.
                    </div>
                    <table class="table table-sm ocr-fields-table mb-0">
                        <tbody id="ocr-fields-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Sectiunea 5: raspuns API brut -->
    <div class="card mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <button class="btn btn-sm fw-semibold p-0 border-0 collapsed" type="button"
                    data-bs-toggle="collapse" data-bs-target="#ocr-raw-body" aria-expanded="false" aria-controls="ocr-raw-body">
                <i class="bi bi-chevron-expand me-1" aria-hidden="true"></i>Răspuns API brut (JSON complet)
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-ocr-copy="json">
                <i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copiază JSON
            </button>
        </div>
        <div class="collapse" id="ocr-raw-body">
            <pre class="ocr-json-panel p-3 mb-0" id="ocr-raw-json"></pre>
        </div>
    </div>

    <!-- Sectiunea 6: debug recunoastere -->
    <div class="card mb-3">
        <div class="card-header py-2 fw-semibold"><i class="bi bi-bug me-1" aria-hidden="true"></i>Debug recunoaștere</div>
        <div class="card-body py-3">
            <div class="row g-3 small">
                <div class="col-6 col-md-2">
                    <span class="text-muted d-block">Caractere detectate</span>
                    <span class="fs-5" id="ocr-dbg-chars">-</span>
                </div>
                <div class="col-6 col-md-2">
                    <span class="text-muted d-block">Linii detectate</span>
                    <span class="fs-5" id="ocr-dbg-lines">-</span>
                </div>
                <div class="col-12 col-md-3">
                    <span class="text-muted d-block mb-1">Date găsite</span>
                    <div id="ocr-dbg-dates" class="d-flex flex-wrap gap-1"></div>
                </div>
                <div class="col-12 col-md-2">
                    <span class="text-muted d-block mb-1">CUI/CIF posibile</span>
                    <div id="ocr-dbg-cui" class="d-flex flex-wrap gap-1"></div>
                </div>
                <div class="col-12 col-md-3">
                    <span class="text-muted d-block mb-1">Valori monetare posibile</span>
                    <div id="ocr-dbg-money" class="d-flex flex-wrap gap-1"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<form class="d-none" id="ocr-token-form"><?= csrf_field() ?></form>

<script>
(function () {
    'use strict';

    var RUN_URL = <?= json_encode($runUrl, JSON_UNESCAPED_SLASHES) ?>;
    var MAX_BYTES = <?= (int) $maxFileBytes ?>;
    var MAX_IMG_BYTES = <?= (int) $maxImageBytes ?>;
    var ALLOWED_EXT = <?= json_encode(array_values($allowedExtensions)) ?>;
    var API_KEY_CONFIGURED = <?= $apiKeyConfigured ? 'true' : 'false' ?>;

    var dropzone = document.getElementById('ocr-dropzone');
    var fileInput = document.getElementById('ocr-file-input');
    var pickBtn = document.getElementById('ocr-pick-btn');
    var runBtn = document.getElementById('ocr-run-btn');
    var runSpinner = document.getElementById('ocr-run-spinner');
    var resetBtn = document.getElementById('ocr-reset-btn');
    var clientError = document.getElementById('ocr-client-error');
    var resultsWrap = document.getElementById('ocr-results');

    var selectedFile = null;
    var lastParsedText = '';
    var lastRawJson = '';
    var previewObjectUrl = null;

    var FIELD_LABELS = {
        numar_factura: 'Număr factură',
        data_facturii: 'Data facturii',
        data_scadentei: 'Data scadenței',
        furnizor: 'Furnizor',
        cui: 'CUI / CIF',
        subtotal: 'Subtotal / bază',
        tva: 'TVA',
        total: 'Total',
        moneda: 'Monedă'
    };

    function showClientError(message) {
        clientError.textContent = message;
        clientError.classList.remove('d-none');
    }

    function clearClientError() {
        clientError.classList.add('d-none');
        clientError.textContent = '';
    }

    function formatBytes(bytes) {
        if (bytes >= 1048576) { return (bytes / 1048576).toFixed(2).replace('.', ',') + ' MB'; }
        if (bytes >= 1024) { return (bytes / 1024).toFixed(1).replace('.', ',') + ' KB'; }
        return bytes + ' B';
    }

    function fileExtension(name) {
        var idx = name.lastIndexOf('.');
        return idx >= 0 ? name.slice(idx + 1).toLowerCase() : '';
    }

    function setSelectedFile(file) {
        clearClientError();

        if (!file) { return; }

        var ext = fileExtension(file.name);
        if (ALLOWED_EXT.indexOf(ext) === -1) {
            showClientError('Tip de fișier neacceptat („' + ext + '"). Formate permise: PDF, JPG, JPEG, PNG.');
            return;
        }
        if (ext === 'pdf' && file.size > MAX_BYTES) {
            showClientError('PDF-ul are ' + formatBytes(file.size) + ' și depășește limita planului gratuit OCR.Space (' +
                formatBytes(MAX_BYTES) + '). Alternativă: fotografiază factura ca JPG/PNG — o comprimăm automat.');
            return;
        }
        if (ext !== 'pdf' && file.size > MAX_IMG_BYTES) {
            showClientError('Imaginea are ' + formatBytes(file.size) + ' și depășește limita de ' + formatBytes(MAX_IMG_BYTES) + '.');
            return;
        }

        selectedFile = file;
        document.getElementById('ocr-file-name').textContent = file.name;
        document.getElementById('ocr-file-size').textContent = formatBytes(file.size);
        document.getElementById('ocr-file-type').textContent = file.type || ('.' + ext);
        document.getElementById('ocr-file-info').classList.remove('d-none');
        document.getElementById('ocr-file-empty').classList.add('d-none');
        runBtn.disabled = false;
        resetBtn.disabled = false;
        renderPreview(file, ext);
    }

    function renderPreview(file, ext) {
        var preview = document.getElementById('ocr-preview');
        preview.innerHTML = '';
        if (previewObjectUrl) { URL.revokeObjectURL(previewObjectUrl); previewObjectUrl = null; }

        previewObjectUrl = URL.createObjectURL(file);
        if (ext === 'pdf') {
            var frame = document.createElement('iframe');
            frame.src = previewObjectUrl;
            frame.title = 'Previzualizare ' + file.name;
            preview.appendChild(frame);
        } else {
            var img = document.createElement('img');
            img.src = previewObjectUrl;
            img.alt = 'Previzualizare ' + file.name;
            preview.appendChild(img);
        }
    }

    function resetSelection() {
        selectedFile = null;
        fileInput.value = '';
        if (previewObjectUrl) { URL.revokeObjectURL(previewObjectUrl); previewObjectUrl = null; }
        document.getElementById('ocr-file-info').classList.add('d-none');
        document.getElementById('ocr-file-empty').classList.remove('d-none');
        document.getElementById('ocr-preview').innerHTML = '';
        runBtn.disabled = true;
        resetBtn.disabled = true;
        clearClientError();
    }

    function setBusy(busy) {
        runBtn.disabled = busy || !selectedFile;
        pickBtn.disabled = busy;
        resetBtn.disabled = busy || !selectedFile;
        runSpinner.classList.toggle('d-none', !busy);
    }

    function badge(text, cls) {
        var el = document.createElement('span');
        el.className = 'badge ' + cls;
        el.textContent = text;
        return el;
    }

    function fillBadgeList(containerId, values, cls) {
        var container = document.getElementById(containerId);
        container.innerHTML = '';
        if (!values || !values.length) {
            container.appendChild(badge('niciuna', 'text-bg-light border text-muted'));
            return;
        }
        values.forEach(function (value) {
            container.appendChild(badge(value, cls));
        });
    }

    function renderResults(payload) {
        resultsWrap.classList.remove('d-none');

        var statusBadge = document.getElementById('ocr-status-badge');
        statusBadge.innerHTML = '';
        statusBadge.appendChild(payload.ok
            ? badge('OCR finalizat cu succes', 'text-bg-success')
            : badge('OCR eșuat', 'text-bg-danger'));

        var status = payload.status || {};
        document.getElementById('ocr-status-engine').textContent = status.engine || '-';
        document.getElementById('ocr-status-duration').textContent =
            typeof status.duration_ms === 'number' ? (status.duration_ms / 1000).toFixed(2).replace('.', ',') + ' sec' : '-';
        document.getElementById('ocr-status-file').textContent = status.file_name || '-';
        document.getElementById('ocr-status-size').textContent = status.file_size || '-';

        var statusError = document.getElementById('ocr-status-error');
        if (payload.error) {
            statusError.textContent = payload.error + (payload.error_details ? ' — Detalii: ' + payload.error_details : '');
            statusError.classList.remove('d-none');
        } else {
            statusError.classList.add('d-none');
        }

        lastParsedText = payload.parsed_text || '';
        document.getElementById('ocr-parsed-text').textContent =
            lastParsedText !== '' ? lastParsedText : '(niciun text recunoscut)';

        lastRawJson = payload.raw_response !== null && payload.raw_response !== undefined
            ? JSON.stringify(payload.raw_response, null, 4)
            : '(răspunsul API nu este disponibil)';
        document.getElementById('ocr-raw-json').textContent = lastRawJson;

        var fieldsBody = document.getElementById('ocr-fields-body');
        fieldsBody.innerHTML = '';
        Object.keys(FIELD_LABELS).forEach(function (key) {
            var row = document.createElement('tr');
            var labelCell = document.createElement('td');
            labelCell.textContent = FIELD_LABELS[key];
            var valueCell = document.createElement('td');
            var value = payload.fields ? payload.fields[key] : null;
            if (value) {
                valueCell.textContent = value;
                valueCell.className = 'fw-semibold';
            } else {
                valueCell.appendChild(badge('Nedetectat', 'text-bg-secondary'));
            }
            row.appendChild(labelCell);
            row.appendChild(valueCell);
            fieldsBody.appendChild(row);
        });

        var dbg = payload.debug || {};
        document.getElementById('ocr-dbg-chars').textContent =
            typeof dbg.caractere === 'number' ? dbg.caractere.toLocaleString('ro-RO') : '-';
        document.getElementById('ocr-dbg-lines').textContent =
            typeof dbg.linii === 'number' ? dbg.linii.toLocaleString('ro-RO') : '-';
        fillBadgeList('ocr-dbg-dates', dbg.date_gasite, 'text-bg-info text-dark');
        fillBadgeList('ocr-dbg-cui', dbg.cui_gasite, 'text-bg-primary');
        fillBadgeList('ocr-dbg-money', dbg.valori_monetare, 'text-bg-light border');

        resultsWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function runOcr() {
        if (!selectedFile) { return; }
        if (!API_KEY_CONFIGURED) {
            showClientError('Cheia OCR.Space nu este configurată. Adaugă OCR_SPACE_API_KEY în .env și reîncarcă pagina.');
            return;
        }

        clearClientError();
        setBusy(true);

        var formData = new FormData();
        formData.append('invoice', selectedFile, selectedFile.name);
        var tokenInput = document.querySelector('#ocr-token-form input[name="_token"]');
        formData.append('_token', tokenInput ? tokenInput.value : '');

        fetch(RUN_URL, { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (response) {
                return response.json().catch(function () {
                    throw new Error('Serverul a răspuns într-un format neașteptat (HTTP ' + response.status + ').');
                });
            })
            .then(function (payload) {
                if (payload && typeof payload === 'object' && 'ok' in payload) {
                    if (!payload.status) {
                        // Eroare de validare/CSRF: nu are sectiune de status, o afisam sus.
                        showClientError(payload.error || 'Cererea a fost respinsă.');
                        return;
                    }
                    renderResults(payload);
                    return;
                }
                throw new Error('Răspuns invalid de la server.');
            })
            .catch(function (error) {
                showClientError('Testul OCR a eșuat: ' + error.message);
            })
            .finally(function () {
                setBusy(false);
            });
    }

    function copyToClipboard(text, button) {
        var done = function () {
            var original = button.innerHTML;
            button.innerHTML = '<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Copiat';
            setTimeout(function () { button.innerHTML = original; }, 1500);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
            return;
        }
        // Fallback pentru http:// local (192.168.x.x nu este secure context).
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try { document.execCommand('copy'); done(); } catch (e) { /* ignorat */ }
        document.body.removeChild(textarea);
    }

    // Wiring UI
    pickBtn.addEventListener('click', function (event) { event.stopPropagation(); fileInput.click(); });
    dropzone.addEventListener('click', function () { fileInput.click(); });
    dropzone.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); fileInput.click(); }
    });
    fileInput.addEventListener('change', function () { setSelectedFile(fileInput.files[0] || null); });

    ['dragenter', 'dragover'].forEach(function (type) {
        dropzone.addEventListener(type, function (event) {
            event.preventDefault();
            dropzone.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function (type) {
        dropzone.addEventListener(type, function (event) {
            event.preventDefault();
            dropzone.classList.remove('dragover');
        });
    });
    dropzone.addEventListener('drop', function (event) {
        var files = event.dataTransfer && event.dataTransfer.files;
        if (files && files.length) { setSelectedFile(files[0]); }
    });

    runBtn.addEventListener('click', runOcr);
    resetBtn.addEventListener('click', resetSelection);

    document.querySelectorAll('[data-ocr-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            copyToClipboard(button.getAttribute('data-ocr-copy') === 'json' ? lastRawJson : lastParsedText, button);
        });
    });
})();
</script>
