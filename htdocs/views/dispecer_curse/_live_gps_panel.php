<?php
/**
 * Banda "Curse in desfasurare (GPS live)" de pe lista Dispecer curse.
 *
 * Doua surse de adevar, afisate separat:
 *  - GPS (SAS, prin cache-ul partajat): ce face fizic vehiculul acum
 *    (pozitie, viteza, prospetimea raportarii);
 *  - aplicatia (curse_dispecer): daca activitatea e inregistrata ca cursa.
 * Datele vin din ?page=dispecer_curse&action=live_gps; randarea, filtrarea,
 * cautarea si detaliile expandabile sunt in assets/js/dispatcher-live.js.
 * "Adauga cursa" precompleteaza formularul existent de mai jos, fara sa-i
 * ocoleasca validarile.
 */
$liveGpsUrl = build_query_url(['page' => 'dispecer_curse', 'action' => 'live_gps']);
$liveCssVersion = (string) @filemtime(BASE_PATH . '/assets/css/dispatcher-live.css');
$liveJsVersion = (string) @filemtime(BASE_PATH . '/assets/js/dispatcher-live.js');
?>
<link rel="stylesheet" href="<?= e(url('assets/css/dispatcher-live.css?v=' . $liveCssVersion)) ?>">

<section class="dispatcher-live d-none" id="dispatcher-live" data-endpoint="<?= e($liveGpsUrl) ?>" aria-labelledby="dispatcher-live-title">
    <header class="dispatcher-live-header">
        <div class="dispatcher-live-heading">
            <button type="button" class="dispatcher-live-title-btn" id="dispatcher-live-toggle"
                    aria-expanded="true" aria-controls="dispatcher-live-body" title="Restrânge / extinde">
                <span class="dispatcher-live-pulse" aria-hidden="true"></span>
                <h2 class="dispatcher-live-title" id="dispatcher-live-title">
                    Curse în desfășurare <span class="dispatcher-live-title-muted">(GPS live)</span>
                </h2>
                <span class="dispatcher-live-count" data-dl-count>0</span>
                <i class="bi bi-chevron-up dispatcher-live-collapse-icon" aria-hidden="true"></i>
            </button>
            <div class="dispatcher-live-chips" role="group" aria-label="Sumar vehicule active">
                <button type="button" class="dispatcher-live-chip dispatcher-live-chip--active" data-dl-filter="all" aria-pressed="true">
                    <span data-dl-counter="active">0</span> vehicule active
                </button>
                <button type="button" class="dispatcher-live-chip dispatcher-live-chip--trip" data-dl-filter="with_trip" aria-pressed="false">
                    <strong data-dl-counter="with_trip">0</strong> cu cursă asociată
                </button>
                <button type="button" class="dispatcher-live-chip dispatcher-live-chip--missing" data-dl-filter="without_trip" aria-pressed="false">
                    <strong data-dl-counter="without_trip">0</strong> fără cursă asociată
                </button>
                <button type="button" class="dispatcher-live-chip dispatcher-live-chip--idle" data-dl-filter="stationary" aria-pressed="false">
                    <span class="dispatcher-live-chip-dot" aria-hidden="true"></span>
                    <span data-dl-counter="stationary">0</span> staționare
                </button>
            </div>
        </div>
        <div class="dispatcher-live-tools">
            <label class="dispatcher-live-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" data-dl-search autocomplete="off"
                       placeholder="Caută după nr. înmatriculare, șofer, locație..."
                       aria-label="Caută după nr. înmatriculare, șofer, locație">
            </label>
            <select class="form-select dispatcher-live-select" data-dl-filter-select aria-label="Filtrează vehiculele">
                <option value="all">Toate vehiculele</option>
                <option value="with_trip">Cu cursă asociată</option>
                <option value="without_trip">Fără cursă asociată</option>
                <option value="stationary">Staționare / poziție veche</option>
            </select>
            <span class="dispatcher-live-updated" data-dl-updated aria-live="polite"></span>
            <button type="button" class="dispatcher-live-refresh" data-dl-refresh title="Reîmprospătează pozițiile" aria-label="Reîmprospătează pozițiile">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <div class="dispatcher-live-body" id="dispatcher-live-body">
        <div class="dispatcher-live-alert d-none" data-dl-error role="status"></div>
        <div class="dispatcher-live-board" role="table" aria-label="Vehicule active GPS">
            <div class="dispatcher-live-head" role="row">
                <div class="dispatcher-live-cell dispatcher-live-col-vehicle" role="columnheader">Vehicul</div>
                <div class="dispatcher-live-cell dispatcher-live-col-driver" role="columnheader">Șofer</div>
                <div class="dispatcher-live-cell dispatcher-live-col-position" role="columnheader">Poziție (ultima actualizare)</div>
                <div class="dispatcher-live-cell dispatcher-live-col-speed" role="columnheader">Viteză</div>
                <div class="dispatcher-live-cell dispatcher-live-col-status" role="columnheader">Status</div>
                <div class="dispatcher-live-cell dispatcher-live-col-trip" role="columnheader">Cursă asociată</div>
                <div class="dispatcher-live-cell dispatcher-live-col-actions" role="columnheader">Acțiuni</div>
            </div>
            <div class="dispatcher-live-rows" data-dl-rows role="rowgroup"></div>
            <div class="dispatcher-live-empty" data-dl-empty>Se încarcă pozițiile din SAS...</div>
        </div>
    </div>
</section>

<script src="<?= e(url('assets/js/dispatcher-live.js?v=' . $liveJsVersion)) ?>" defer></script>
