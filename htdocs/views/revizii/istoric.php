<?php
/**
 * Revizii → Istoric revizii: istoricul unificat al interventiilor (ulei, placute, anvelope),
 * cu filtre server-side, KPI, grafice, detalii, documente, exporturi si rapoarte.
 * Datele vin din ReviziiIstoricController (prima pagina randata in JSON, restul prin
 * ?action=istoric_data); revizii-istoric.js deseneaza tot ce depinde de filtre.
 *
 * Asteapta: $filters, $options, $initial, $sort, $dir, $canEdit, $canExport, $canDocuments.
 */
$filters = is_array($filters ?? null) ? $filters : ReviziiIstoricModel::normalizeFilters([]);
$options = is_array($options ?? null) ? $options : ['vehicule' => [], 'operatori' => [], 'ani' => [(int) date('Y')], 'fara_operator' => false];
$initial = is_array($initial ?? null) ? $initial : [];
$canEdit = !empty($canEdit);
$canExport = !empty($canExport);
$canDocuments = !empty($canDocuments);
$kpi = $initial['kpi'] ?? ['total' => 0, 'finalizate' => 0, 'cu_documente' => 0, 'vehicule' => 0];
$pct = static fn (int $n, int $total): string => $total > 0 ? round($n / $total * 100) . '% din total' : '— din total';
$selectedCategory = $filters['categorii'][0] ?? '';
$selectedVehicle = $filters['vehicule'][0] ?? 0;
$selectedOperator = $filters['operator'];
$year = (int) ($initial['lunar']['an'] ?? date('Y'));

$pageJson = json_encode([
    'initial' => $initial,
    'filters' => $filters,
    'sort' => $sort ?? 'data',
    'dir' => $dir ?? 'desc',
    'categorii' => array_map(static fn (array $tab): string => $tab['label'], ReviziiModel::TABS),
    'statusuri' => ReviziiIstoricModel::STATUSES,
    'can' => ['edit' => $canEdit, 'export' => $canExport, 'documents' => $canDocuments],
    'token' => csrf_token(),
    'urls' => [
        'base' => build_query_url(['page' => 'revizii']),
        'page' => build_query_url(['page' => 'revizii', 'tip' => 'istoric']),
        'categorie' => [
            'schimb_ulei' => build_query_url(['page' => 'revizii']),
            'placute_frana' => build_query_url(['page' => 'revizii', 'tip' => 'placute_frana']),
            'anvelope' => build_query_url(['page' => 'revizii', 'tip' => 'anvelope']),
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<link rel="stylesheet" href="<?= e(url('assets/css/revizii.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/revizii.css'))) ?>">

<div class="rv-page rv-hist" id="rvHist">
    <div class="rv-heading">
        <div>
            <h1>Revizii</h1>
            <p>Istoric complet al intervențiilor și reviziilor pentru întreaga flotă.</p>
        </div>
        <?php if ($canExport): ?>
            <div class="rv-heading-actions">
                <button type="button" class="btn btn-outline-primary rv-hist-btn" data-export="xlsx"><i class="bi bi-file-earmark-spreadsheet"></i> Export Excel</button>
                <button type="button" class="btn btn-outline-primary rv-hist-btn" data-export="pdf"><i class="bi bi-file-earmark-pdf"></i> Export PDF</button>
                <button type="button" class="btn btn-primary rv-hist-btn" data-bs-toggle="modal" data-bs-target="#rvReportModal"><i class="bi bi-bar-chart"></i> Generează raport</button>
            </div>
        <?php endif; ?>
    </div>

    <?php $activeTab = 'istoric'; require __DIR__ . '/_tabs.php'; ?>

    <div class="rv-kpis rv-hist-kpis">
        <div class="rv-kpi rv-kpi-ok">
            <div class="rv-kpi-icon"><i class="bi bi-wrench-adjustable"></i></div>
            <div><div class="rv-kpi-value" data-kpi="total"><?= (int) $kpi['total'] ?></div><div class="rv-kpi-title">Intervenții totale</div><div class="rv-kpi-desc">Toate intervențiile înregistrate</div></div>
        </div>
        <div class="rv-kpi rv-kpi-info">
            <div class="rv-kpi-icon"><i class="bi bi-check-lg"></i></div>
            <div>
                <div class="rv-kpi-value" data-kpi="finalizate"><?= (int) $kpi['finalizate'] ?></div>
                <div class="rv-kpi-title">Finalizate <i class="bi bi-info-circle rv-info-tip" title="Finalizate sau verificate. Nu include intervențiile cu „Date incomplete” (ciclul încheiat avea zile fără telemetrie SasFleet)."></i></div>
                <div class="rv-kpi-desc" data-kpi-pct="finalizate"><?= e($pct((int) $kpi['finalizate'], (int) $kpi['total'])) ?></div>
            </div>
        </div>
        <div class="rv-kpi rv-kpi-soon">
            <div class="rv-kpi-icon"><i class="bi bi-file-earmark-text"></i></div>
            <div><div class="rv-kpi-value" data-kpi="cu_documente"><?= (int) $kpi['cu_documente'] ?></div><div class="rv-kpi-title">Cu documente atașate</div><div class="rv-kpi-desc" data-kpi-pct="cu_documente"><?= e($pct((int) $kpi['cu_documente'], (int) $kpi['total'])) ?></div></div>
        </div>
        <div class="rv-kpi rv-kpi-total rv-kpi-plain">
            <div class="rv-kpi-icon"><i class="bi bi-truck-front"></i></div>
            <div><div class="rv-kpi-value" data-kpi="vehicule"><?= (int) $kpi['vehicule'] ?></div><div class="rv-kpi-title">Vehicule cu istoric</div><div class="rv-kpi-desc">Din flota activă și inactivă</div></div>
        </div>
    </div>

    <div class="rv-hist-layout">
        <div class="rv-hist-main">
            <section class="rv-card rv-hist-filters" aria-labelledby="rvHistFiltersTitle">
                <h2 id="rvHistFiltersTitle">Filtrează istoricul</h2>
                <form id="rvHistFilters" autocomplete="off" onsubmit="return false">
                    <div class="rv-hist-frow rv-hist-frow-1">
                        <div class="rv-search"><i class="bi bi-search"></i><input type="search" class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Caută vehicul, componentă, operator..." aria-label="Caută vehicul, componentă sau operator"></div>
                        <label class="rv-hist-date"><i class="bi bi-calendar3"></i><span>De la</span><input type="date" class="form-control" name="de_la" value="<?= e((string) $filters['de_la']) ?>" aria-label="De la data"></label>
                        <label class="rv-hist-date"><span>Până la</span><input type="date" class="form-control" name="pana_la" value="<?= e((string) $filters['pana_la']) ?>" aria-label="Până la data"></label>
                        <select class="form-select" name="categorie" aria-label="Categorie">
                            <option value="">Toate categoriile</option>
                            <?php foreach (ReviziiModel::TABS as $tab => $meta): ?>
                                <option value="<?= e($tab) ?>"<?= $selectedCategory === $tab ? ' selected' : '' ?>><?= e($meta['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select" name="vehicul" aria-label="Vehicul">
                            <option value="">Toate vehiculele</option>
                            <?php foreach ($options['vehicule'] as $vehicle): ?>
                                <option value="<?= (int) $vehicle['id'] ?>"<?= $selectedVehicle === $vehicle['id'] ? ' selected' : '' ?>><?= e($vehicle['nr']) ?><?= $vehicle['inactiv'] ? ' (inactiv)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rv-hist-frow rv-hist-frow-2">
                        <select class="form-select" name="status" aria-label="Status">
                            <option value="">Toate statusurile</option>
                            <?php foreach (ReviziiIstoricModel::STATUSES as $key => $label): ?>
                                <option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select" name="operator" aria-label="Operator">
                            <option value="">Toți operatorii</option>
                            <?php foreach ($options['operatori'] as $operator): ?>
                                <option value="<?= (int) $operator['id'] ?>"<?= $selectedOperator === $operator['id'] ? ' selected' : '' ?>><?= e($operator['nume']) ?></option>
                            <?php endforeach; ?>
                            <?php if ($options['fara_operator']): ?>
                                <option value="<?= ReviziiIstoricModel::OPERATOR_NONE ?>"<?= $selectedOperator === ReviziiIstoricModel::OPERATOR_NONE ? ' selected' : '' ?>>Nespecificat</option>
                            <?php endif; ?>
                        </select>
                        <div class="rv-hist-quick" role="group" aria-label="Perioade rapide">
                            <button type="button" class="btn" data-quick="30">Ultimele 30 zile</button>
                            <button type="button" class="btn" data-quick="6m">Ultimele 6 luni</button>
                            <button type="button" class="btn" data-quick="an">Anul curent</button>
                        </div>
                        <button type="button" class="btn btn-outline-primary rv-hist-reset" id="rvHistReset"><i class="bi bi-arrow-clockwise"></i> Resetează filtrele</button>
                    </div>
                </form>
            </section>

            <section class="rv-card rv-hist-table-card" aria-labelledby="rvHistTableTitle">
                <div class="rv-hist-table-head">
                    <h2 id="rvHistTableTitle">Istoric intervenții</h2>
                    <p id="rvHistSummary" aria-live="polite"></p>
                </div>
                <div class="rv-hist-table-wrap" id="rvHistTableWrap">
                    <table class="rv-table rv-hist-table">
                        <colgroup>
                            <col class="c-nr"><col class="c-data"><col class="c-veh"><col class="c-cat"><col class="c-comp"><col class="c-tip"><col class="c-km"><col class="c-ref"><col class="c-op"><col class="c-st"><col class="c-doc"><col class="c-obs"><col class="c-act">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th data-sort="data">Dată</th>
                                <th data-sort="vehicul">Vehicul</th>
                                <th data-sort="categorie">Categorie</th>
                                <th data-sort="componenta">Componentă / Punte</th>
                                <th>Tip intervenție</th>
                                <th data-sort="km" class="num">Km la<br>intervenție</th>
                                <th data-sort="referinta">T0 / Referință</th>
                                <th data-sort="operator">Operator</th>
                                <th data-sort="status">Status</th>
                                <th data-sort="documente">Documente</th>
                                <th>Observații</th>
                                <th>Acțiuni</th>
                            </tr>
                        </thead>
                        <tbody id="rvHistBody"></tbody>
                    </table>
                </div>
                <div class="rv-foot rv-hist-foot">
                    <span id="rvHistCount"></span>
                    <nav class="rv-pager" id="rvHistPager" aria-label="Paginare istoric"></nav>
                </div>
            </section>

            <section class="rv-card rv-hist-detail" id="rvHistDetail" aria-labelledby="rvHistDetailTitle" hidden>
                <h2 id="rvHistDetailTitle">Detalii intervenție</h2>
                <div id="rvHistDetailBody"></div>
            </section>
        </div>

        <aside class="rv-hist-side">
            <section class="rv-card rv-hist-side-card" aria-labelledby="rvHistMonthlyTitle">
                <div class="rv-hist-side-head">
                    <h2 id="rvHistMonthlyTitle">Intervenții pe lună</h2>
                    <select class="form-select form-select-sm" id="rvHistYear" aria-label="Anul graficului">
                        <?php foreach ($options['ani'] as $optionYear): ?>
                            <option value="<?= (int) $optionYear ?>"<?= (int) $optionYear === $year ? ' selected' : '' ?>>Anul <?= (int) $optionYear ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rv-hist-monthly"><canvas id="rvHistMonthly" role="img" aria-label="Intervenții pe lună și categorie"></canvas></div>
                <ul class="rv-hist-legend" aria-hidden="true">
                    <li><span class="rv-sq rv-sq-ulei"></span>Schimb ulei</li>
                    <li><span class="rv-sq rv-sq-frana"></span>Plăcuțe frână</li>
                    <li><span class="rv-sq rv-sq-anv"></span>Anvelope</li>
                </ul>
            </section>

            <section class="rv-card rv-hist-side-card" aria-labelledby="rvHistDonutTitle">
                <h2 id="rvHistDonutTitle">Distribuție pe categorii</h2>
                <div class="rv-hist-donut-row">
                    <div class="rv-hist-donut">
                        <canvas id="rvHistDonut" width="120" height="120" role="img" aria-label="Distribuția intervențiilor pe categorii"></canvas>
                        <div class="rv-donut-center"><strong id="rvHistDonutTotal">0</strong><span>intervenții</span></div>
                    </div>
                    <ul class="rv-hist-dist" id="rvHistDist"></ul>
                </div>
            </section>

            <?php if ($canExport): ?>
                <section class="rv-card rv-hist-side-card" aria-labelledby="rvHistReportsTitle">
                    <div class="rv-hist-side-head">
                        <h2 id="rvHistReportsTitle">Ultimele rapoarte generate</h2>
                        <button type="button" class="rv-ov-link btn btn-link p-0" data-bs-toggle="modal" data-bs-target="#rvReportsModal">Vezi toate rapoartele <i class="bi bi-arrow-right"></i></button>
                    </div>
                    <ul class="rv-hist-reports" id="rvHistReports"></ul>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>

<div class="rv-hist-toast" id="rvHistToast" role="status" aria-live="polite" hidden></div>
<?php if ($canDocuments): ?>
    <input type="file" id="rvHistFile" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" multiple hidden>
<?php endif; ?>

<?php if ($canExport): ?>
    <div class="modal fade" id="rvReportModal" tabindex="-1" aria-labelledby="rvReportModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form class="modal-content rv-config" id="rvReportForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="rvReportModalTitle"><i class="bi bi-bar-chart me-2"></i>Generează raport</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <p class="rv-modal-intro">Raportul folosește criteriile de mai jos (nu pagina vizibilă din tabel) și include toate intervențiile care se potrivesc. După generare îl găsești în „Ultimele rapoarte generate”.</p>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="rvRepTitle">Titlu (opțional)</label>
                            <input type="text" class="form-control" id="rvRepTitle" name="titlu" maxlength="190" placeholder="Implicit: Raport revizii · perioada aleasă">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="rvRepFrom">De la</label>
                            <input type="date" class="form-control" id="rvRepFrom" name="de_la" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="rvRepTo">Până la</label>
                            <input type="date" class="form-control" id="rvRepTo" name="pana_la" required>
                        </div>
                        <div class="col-sm-6">
                            <span class="form-label d-block">Categorii</span>
                            <?php foreach (ReviziiModel::TABS as $tab => $meta): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="categorii[]" value="<?= e($tab) ?>" id="rvRepCat-<?= e($tab) ?>" checked>
                                    <label class="form-check-label" for="rvRepCat-<?= e($tab) ?>"><?= e($meta['label']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="col-sm-6">
                            <span class="form-label d-block">Format</span>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="format" value="xlsx" id="rvRepXlsx" checked>
                                <label class="form-check-label" for="rvRepXlsx"><i class="bi bi-file-earmark-spreadsheet text-success"></i> Excel (.xlsx)</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="format" value="pdf" id="rvRepPdf">
                                <label class="form-check-label" for="rvRepPdf"><i class="bi bi-file-earmark-pdf text-danger"></i> PDF</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="flota" value="1" id="rvRepFleet" checked>
                                <label class="form-check-label" for="rvRepFleet">Întreaga flotă</label>
                            </div>
                            <select class="form-select" name="vehicule[]" id="rvRepVehicles" multiple size="6" aria-label="Vehicule incluse în raport" disabled>
                                <?php foreach ($options['vehicule'] as $vehicle): ?>
                                    <option value="<?= (int) $vehicle['id'] ?>"><?= e($vehicle['nr']) ?> · <?= e($vehicle['tip']) ?><?= $vehicle['inactiv'] ? ' (inactiv)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Ctrl / Cmd + clic pentru mai multe vehicule.</small>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="observatii" value="1" id="rvRepNotes" checked>
                                <label class="form-check-label" for="rvRepNotes">Include observațiile detaliate</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="documente" value="1" id="rvRepDocs" checked>
                                <label class="form-check-label" for="rvRepDocs">Include referințele documentelor atașate</label>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-danger mt-3 mb-0" id="rvReportError" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                    <button type="submit" class="btn btn-primary" id="rvReportSubmit"><i class="bi bi-gear"></i> Generează</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="rvReportsModal" tabindex="-1" aria-labelledby="rvReportsModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rvReportsModalTitle">Rapoarte generate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body"><ul class="rv-hist-reports rv-hist-reports-all" id="rvReportsAll"></ul></div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($canEdit): ?>
    <div class="modal fade" id="rvRefModal" tabindex="-1" aria-labelledby="rvRefModalTitle" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" id="rvRefForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="rvRefModalTitle">Referință intervenție</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <p class="rv-modal-intro" id="rvRefFor"></p>
                    <label class="form-label" for="rvRefInput">Referință (ex. nr. factură, deviz, fișă service)</label>
                    <input type="text" class="form-control" id="rvRefInput" maxlength="80">
                    <small class="text-muted">Lasă gol pentru a șterge referința. Data, km și operatorul intervenției nu se modifică.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                    <button type="submit" class="btn btn-primary">Salvează</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script type="application/json" id="rvHistData"><?= $pageJson ?: '{}' ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= e(url('assets/js/revizii-istoric.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/revizii-istoric.js'))) ?>"></script>
