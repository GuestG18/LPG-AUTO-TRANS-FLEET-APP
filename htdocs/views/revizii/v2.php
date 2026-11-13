<?php
/**
 * Revizii & Mentenanță (V2) — spatiul de lucru unic. Totul se randeaza in browser din
 * $workspace (ReviziiV2Service::workspace()); detaliile grele (istoric, uzura km SAS,
 * stoc) se incarca la cerere prin actiunile v2_*.
 *
 * Asteapta: $workspace, $loadError, $canEdit, $canConfigure, $canExport, $canSimulate, $canDocuments.
 */
$asset = static fn (string $path): string => url($path . '?v=' . (string) @filemtime(BASE_PATH . '/' . $path));
$pageJson = json_encode([
    'workspace' => $workspace,
    'error' => $loadError ?? null,
    'token' => csrf_token(),
    'can' => [
        'edit' => !empty($canEdit), 'configure' => !empty($canConfigure), 'export' => !empty($canExport),
        'simulate' => !empty($canSimulate), 'documents' => !empty($canDocuments),
    ],
    'urls' => [
        'base' => build_query_url(['page' => 'revizii']),
        'notificari' => build_query_url(['page' => 'notificari']),
        'consumabile' => build_query_url(['page' => 'mentenanta', 'action' => 'stock', 'tab' => 'consumabile']),
        'legacy' => [
            'prezentare' => build_query_url(['page' => 'revizii', 'tip' => 'prezentare']),
            'schimb_ulei' => build_query_url(['page' => 'revizii', 'tip' => 'schimb_ulei']),
            'placute_frana' => build_query_url(['page' => 'revizii', 'tip' => 'placute_frana']),
            'anvelope' => build_query_url(['page' => 'revizii', 'tip' => 'anvelope']),
            'istoric' => build_query_url(['page' => 'revizii', 'tip' => 'istoric']),
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
?>
<link rel="stylesheet" href="<?= e($asset('assets/css/revizii-v2.css')) ?>">

<div class="rv2" id="rv2">
    <header class="rv2-head">
        <div class="rv2-title">
            <i class="bi bi-wrench-adjustable rv2-title-icon" aria-hidden="true"></i>
            <div>
                <h1>Revizii &amp; Mentenanță</h1>
                <p>Monitorizare, planificare și execuție revizii X / Y (secvență calculată din km) · componente · consumabile · costuri</p>
            </div>
        </div>
        <div class="rv2-head-actions">
            <div class="rv2-sync" id="rv2Sync" data-state="indisponibil" title="">
                <span class="rv2-sync-dot" aria-hidden="true"></span><span id="rv2SyncText">SasFleet: —</span>
            </div>
            <button type="button" class="rv2-iconbtn" id="rv2SyncBtn" title="Sincronizează acum (ore motor SAS) și reîmprospătează" aria-label="Sincronizare manuală"><i class="bi bi-arrow-repeat"></i></button>
            <button type="button" class="rv2-btn-outline" id="rv2SimBtn"><i class="bi bi-calculator"></i> Simulare calcul</button>
            <div class="dropdown">
                <button type="button" class="rv2-btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear"></i> Configurare revizii</button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><button class="dropdown-item" type="button" data-cfg="planuri"><i class="bi bi-diagram-3 me-2"></i>Planuri de mentenanță</button></li>
                    <li><button class="dropdown-item" type="button" data-cfg="operatii"><i class="bi bi-list-check me-2"></i>Catalog operațiuni</button></li>
                    <li><button class="dropdown-item" type="button" data-cfg="sabloane"><i class="bi bi-layers me-2"></i>Șabloane Revizia X / Y</button></li>
                    <li><button class="dropdown-item" type="button" data-cfg="vehicule"><i class="bi bi-truck me-2"></i>Vehicule: plan, ciclu, excepții</button></li>
                    <li><button class="dropdown-item" type="button" data-cfg="setari"><i class="bi bi-broadcast me-2"></i>Alerte și surse de date</button></li>
                    <li><button class="dropdown-item" type="button" data-cfg="import"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Import din Excel</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><button class="dropdown-item" type="button" data-open-history><i class="bi bi-clock-history me-2"></i>Istoric revizii</button></li>
                    <li><a class="dropdown-item" href="<?= e(build_query_url(['page' => 'revizii', 'tip' => 'istoric'])) ?>"><i class="bi bi-folder2-open me-2"></i>Istoric km SAS (documente, rapoarte)</a></li>
                </ul>
            </div>
            <div class="dropdown">
                <button type="button" class="rv2-iconbtn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Alerte revizii" id="rv2BellBtn">
                    <i class="bi bi-bell"></i><span class="rv2-badge-dot" id="rv2BellCount" hidden>0</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow rv2-bell-menu" id="rv2BellMenu"></div>
            </div>
        </div>
    </header>

    <div id="rv2Error" class="alert alert-danger" hidden></div>

    <section class="rv2-kpis" id="rv2Kpis" aria-label="Indicatori revizii"></section>
    <div class="rv2-kpi-expand" id="rv2KpiExpand"><div class="rv2-kpi-expand-inner" id="rv2KpiExpandInner"></div></div>

    <section class="rv2-toolbar" aria-label="Filtre">
        <div class="rv2-field rv2-field-fleet">
            <label for="rv2FFleet">Flotă</label>
            <select class="form-select" id="rv2FFleet">
                <option value="">Toate vehiculele</option>
                <option value="cap_tractor">Capete tractor</option>
                <option value="camion">Camioane</option>
                <option value="semiremorca">Semiremorci</option>
            </select>
        </div>
        <div class="rv2-field">
            <label for="rv2FType">Tip revizie</label>
            <select class="form-select" id="rv2FType">
                <option value="">Toate (X, Y)</option>
                <option value="X">Revizia X</option>
                <option value="Y">Revizia Y</option>
            </select>
        </div>
        <div class="rv2-field">
            <label for="rv2FStatus">Status</label>
            <select class="form-select" id="rv2FStatus">
                <option value="">Toate</option>
                <option value="in_termen">În termen</option>
                <option value="se_apropie">Se apropie de termen</option>
                <option value="scadent">Scadent</option>
                <option value="depasit">Depășit</option>
                <option value="fara_date">Date insuficiente</option>
                <option value="neconfigurat">Neconfigurat</option>
                <option value="programata">Programat</option>
                <option value="in_desfasurare">În desfășurare</option>
            </select>
        </div>
        <div class="rv2-field">
            <label for="rv2FComp">Componentă</label>
            <select class="form-select" id="rv2FComp"><option value="">Toate</option></select>
        </div>
        <div class="rv2-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control" id="rv2FSearch" placeholder="Caută nr. auto, model, componentă…" aria-label="Caută">
        </div>
        <button type="button" class="rv2-tool-btn" id="rv2AdvBtn" title="Filtre avansate" aria-expanded="false"><i class="bi bi-funnel"></i><span class="rv2-badge-dot" id="rv2AdvCount" hidden>0</span></button>
        <div class="dropdown">
            <button type="button" class="rv2-tool-btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" title="Scadență între date" id="rv2DateBtn"><i class="bi bi-calendar3"></i><i class="bi bi-chevron-down small"></i></button>
            <div class="dropdown-menu dropdown-menu-end p-3 shadow" style="width:280px">
                <div class="mb-2 small fw-semibold">Scadență (estimată) între</div>
                <input type="date" class="form-control form-control-sm mb-2" id="rv2FFrom" aria-label="De la">
                <input type="date" class="form-control form-control-sm mb-2" id="rv2FTo" aria-label="Până la">
                <div class="small text-muted">Data scadenței = termenul calendaristic sau estimarea din km/zi (ultimele 30 zile), cea mai apropiată.</div>
            </div>
        </div>
        <div class="rv2-field">
            <label for="rv2FWindow">Interval</label>
            <select class="form-select" id="rv2FWindow">
                <option value="">Oricând</option>
                <option value="7">Următoarele 7 zile</option>
                <option value="30">Următoarele 30 zile</option>
                <option value="90">Următoarele 90 zile</option>
                <option value="180">Următoarele 180 zile</option>
            </select>
        </div>
        <button type="button" class="rv2-tool-btn" id="rv2Reset"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
    </section>
    <div class="rv2-adv" id="rv2Adv">
        <div class="rv2-adv-inner">
            <div><label class="form-label small fw-semibold" for="rv2AMake">Marcă / model</label><select class="form-select form-select-sm" id="rv2AMake" multiple></select></div>
            <div><label class="form-label small fw-semibold" for="rv2AVeh">Vehicule</label><select class="form-select form-select-sm" id="rv2AVeh" multiple></select></div>
            <div><label class="form-label small fw-semibold" for="rv2ACat">Categorie componentă</label><select class="form-select form-select-sm" id="rv2ACat" multiple></select></div>
            <div><label class="form-label small fw-semibold" for="rv2AOpType">Tip operațiune</label><select class="form-select form-select-sm" id="rv2AOpType" multiple></select></div>
            <div>
                <label class="form-label small fw-semibold" for="rv2ACrit">Factor de uzură / criteriu</label>
                <select class="form-select form-select-sm" id="rv2ACrit" multiple>
                    <option value="km">Km</option><option value="ore">Ore motor</option><option value="calendar">Calendar</option>
                </select>
            </div>
            <div>
                <label class="form-label small fw-semibold" for="rv2ASrc">Sursă km</label>
                <select class="form-select form-select-sm" id="rv2ASrc">
                    <option value="">Oricare</option><option value="can">Km CAN</option><option value="gps">Km GPS</option><option value="contor">Contor (semi-remorcă)</option><option value="none">Fără citire</option>
                </select>
                <label class="form-label small fw-semibold mt-2" for="rv2AHours">Ore motor</label>
                <select class="form-select form-select-sm" id="rv2AHours">
                    <option value="">Oricare</option><option value="da">Cu ore SAS</option><option value="nu">Fără ore SAS</option>
                </select>
            </div>
            <div>
                <label class="form-label small fw-semibold" for="rv2AConf">Configurare</label>
                <select class="form-select form-select-sm" id="rv2AConf">
                    <option value="">Oricare</option><option value="da">Revizii configurate</option><option value="nu">Neconfigurate</option>
                </select>
                <label class="form-label small fw-semibold mt-2" for="rv2ASched">Programare</label>
                <select class="form-select form-select-sm" id="rv2ASched">
                    <option value="">Oricare</option><option value="da">Programate / în lucru</option><option value="nu">Neprogramate</option>
                </select>
            </div>
        </div>
    </div>
    <div class="rv2-chips" id="rv2Chips" aria-live="polite"></div>

    <div class="rv2-main">
        <section class="rv2-card" aria-label="Vehicule">
            <div class="rv2-table-head">
                <div>
                    <h2 id="rv2Count">Vehicule</h2>
                    <span class="text-muted mx-1">·</span>
                    <button type="button" class="rv2-sort" id="rv2SortBtn">Sortare: Scadență <i class="bi bi-arrow-up"></i></button>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="rv2-viewtoggle" role="group" aria-label="Vizualizare">
                        <button type="button" class="is-on" data-view="list" title="Tabel"><i class="bi bi-list-ul"></i></button>
                        <button type="button" data-view="grid" title="Carduri"><i class="bi bi-grid"></i></button>
                    </div>
                    <button type="button" class="rv2-expand-all" id="rv2ExpandAll"><i class="bi bi-arrows-expand"></i> Expandă tot</button>
                </div>
            </div>
            <div id="rv2Body"></div>
            <div class="rv2-foot">
                <div>Afișează
                    <select class="form-select" id="rv2PerPage"><option>10</option><option selected>25</option><option>50</option><option>100</option></select>
                    <span id="rv2Total" class="ms-1"></span>
                </div>
                <div class="rv2-pager" id="rv2Pager"></div>
            </div>
        </section>
        <aside class="rv2-card rv2-panel" id="rv2Panel" aria-label="Detaliu vehicul"></aside>
    </div>
</div>

<!-- Executie revizie (lucrare) -->
<div class="modal fade rv2-modal" id="rv2ExecModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rv2ExecTitle">Înregistrează revizia</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body" id="rv2ExecBody"></div>
            <div class="modal-footer justify-content-between">
                <div class="rv2-exec-sum" id="rv2ExecSum"></div>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-danger btn-sm" id="rv2ExecCancel" hidden>Anulează lucrarea</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Închide</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="rv2ExecSchedule">Programează</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="rv2ExecSave">Salvează progresul</button>
                    <button type="button" class="btn btn-primary btn-sm" id="rv2ExecFinalize"><i class="bi bi-check2-circle"></i> Finalizează revizia</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Operatiune independenta -->
<div class="modal fade rv2-modal" id="rv2OpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="rv2OpTitle">Înregistrează operațiune</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button></div>
            <div class="modal-body" id="rv2OpBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Renunță</button>
                <button type="button" class="btn btn-primary btn-sm" id="rv2OpSave">Înregistrează</button>
            </div>
        </div>
    </div>
</div>

<!-- Simulare / explicatie -->
<div class="modal fade rv2-modal" id="rv2SimModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Simulare calcul — următoarea revizie</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button></div>
            <div class="modal-body">
                <div class="d-flex gap-2 align-items-end mb-3 flex-wrap">
                    <div class="flex-grow-1"><label class="form-label" for="rv2SimVeh">Vehicul</label><select class="form-select" id="rv2SimVeh"></select></div>
                    <button type="button" class="btn btn-primary" id="rv2SimRun"><i class="bi bi-play-fill"></i> Calculează</button>
                </div>
                <p class="rv2-cfg-help mb-2">Calculul folosește datele reale ale vehiculului (odometru, ore SAS, istoricul execuțiilor) și regulile configurate. Nu salvează nimic.
                    Simularea pe date istorice SAS (km echivalenți) rămâne în <a href="<?= e(build_query_url(['page' => 'revizii', 'tip' => 'schimb_ulei'])) ?>">Schimb ulei — km SAS</a>.</p>
                <div id="rv2SimOut"></div>
            </div>
        </div>
    </div>
</div>

<!-- Configurare -->
<div class="modal fade rv2-modal" id="rv2CfgModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-lg-down" style="max-width:min(1400px,96vw)">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-gear me-2"></i>Configurare revizii</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-lg-2">
                        <nav class="nav flex-column rv2-cfg-nav" id="rv2CfgNav">
                            <button class="nav-link" type="button" data-sec="planuri"><i class="bi bi-diagram-3 me-2"></i>Planuri</button>
                            <button class="nav-link" type="button" data-sec="operatii"><i class="bi bi-list-check me-2"></i>Catalog operațiuni</button>
                            <button class="nav-link" type="button" data-sec="sabloane"><i class="bi bi-layers me-2"></i>Șabloane X / Y</button>
                            <button class="nav-link" type="button" data-sec="vehicule"><i class="bi bi-truck me-2"></i>Vehicule</button>
                            <button class="nav-link" type="button" data-sec="setari"><i class="bi bi-broadcast me-2"></i>Alerte &amp; surse</button>
                            <button class="nav-link" type="button" data-sec="import"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Import</button>
                        </nav>
                        <?php if (empty($canConfigure)): ?>
                            <div class="alert alert-warning small mt-3 mb-0">Doar vizualizare: modificările cer dreptul „Revizii → configurare”.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-10" id="rv2CfgBody"><div class="rv2-skel"></div><div class="rv2-skel"></div></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Istoric -->
<div class="offcanvas offcanvas-end rv2-drawer" tabindex="-1" id="rv2HistDrawer" aria-labelledby="rv2HistTitle">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title fw-bold" id="rv2HistTitle"><i class="bi bi-clock-history me-2"></i>Istoric revizii</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
    </div>
    <div class="offcanvas-body" id="rv2HistBody"></div>
</div>

<!-- Detaliu componenta / operatiune -->
<div class="offcanvas offcanvas-end rv2-drawer" tabindex="-1" id="rv2CompDrawer" aria-labelledby="rv2CompTitle">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title fw-bold" id="rv2CompTitle">Componentă</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
    </div>
    <div class="offcanvas-body" id="rv2CompBody"></div>
</div>

<div class="rv2-toast" id="rv2Toast" aria-live="polite"></div>

<script type="application/json" id="rv2Data"><?= $pageJson ?: '{}' ?></script>
<script src="<?= e($asset('assets/js/revizii-v2.js')) ?>"></script>
