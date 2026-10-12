<?php
/**
 * Revizii → Prezentare generală: KPI pe flota, situatia pe categorii (donut), alerte,
 * istoricul unificat si interventiile pe luni. Toate cifrele vin din
 * ReviziiService::buildOverview() (acelasi calcul ca paginile pe categorii, doar din BD).
 *
 * Asteapta: $overview, $vehicles, $canEdit, $canSimulate, $credentialsAvailable, $autoSync.
 */
$overview = is_array($overview ?? null) ? $overview : [];
$kpi = $overview['kpi'] ?? [];
$categories = $overview['categorii'] ?? [];
$alerts = $overview['alerte'] ?? [];
$history = $overview['istoric'] ?? [];
$settings = $overview['settings'] ?? [];
$lastSync = $overview['last_sync'] ?? null;
$hourSource = (string) ($overview['hour_source'] ?? 'ore_aspirare');
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$canEdit = !empty($canEdit);
$canSimulate = !empty($canSimulate);
$credentialsAvailable = !empty($credentialsAvailable);
$factor = (float) ($settings['factor_km_ora'] ?? 40);
$defaultWarn = (int) ($settings['prag_avertizare_km'] ?? 1500);

$fmtKm = static fn (float $value): string => number_format($value, 0, ',', '.');
$fmtDate = static fn (?string $value, string $format = 'd.m.Y H:i'): string => $value ? date($format, (int) strtotime($value)) : '—';
$categoryUrl = static fn (string $tab, array $extra = []): string => build_query_url(
    ($tab === ReviziiModel::TIP_SCHIMB_ULEI ? ['page' => 'revizii'] : ['page' => 'revizii', 'tip' => $tab]) + $extra
);
$truckSvg = '<svg viewBox="0 0 64 32" aria-hidden="true"><rect x="2" y="6" width="38" height="18" rx="2" fill="#e9edf3" stroke="#8a96a8"/><path d="M42 11h10l7 7v6H42z" fill="#2f5db8"/><rect x="46" y="13" width="6" height="5" rx="1" fill="#cfe0ff"/><circle cx="12" cy="26" r="4" fill="#38404d"/><circle cx="30" cy="26" r="4" fill="#38404d"/><circle cx="52" cy="26" r="4" fill="#38404d"/></svg>';
$oilSvg = '<svg viewBox="0 0 32 20" aria-hidden="true"><path d="M3 7h6l2-2h6l3 2 9-3-7 10H8L5 11H3z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M29 12c0 1.4-1 2.5-2 2.5s-2-1.1-2-2.5 2-3.5 2-3.5 2 2.1 2 3.5z" fill="currentColor"/></svg>';

$categoryMeta = [
    'schimb_ulei' => ['icon' => '<span class="rv-oil">' . $oilSvg . '</span>', 'desc' => 'Monitorizare pe baza km și ore de funcționare', 'due' => 'Necesită revizie'],
    'placute_frana' => ['icon' => '<i class="bi bi-disc"></i>', 'desc' => 'Monitorizare pe baza kilometrajului', 'due' => 'Necesită înlocuire'],
    'anvelope' => ['icon' => '<i class="bi bi-record-circle"></i>', 'desc' => 'Monitorizare pe set (cap tractor / semi-remorcă)', 'due' => 'Necesită înlocuire'],
];
$pct = static fn (int $count, int $total): string => $total > 0 ? number_format($count / $total * 100, 0) . '%' : '—';

// Date pentru grafice / istoric (randate in browser) si pentru simulator / sincronizare.
$trailerIds = [];
foreach ($vehicles as $vehicle) {
    if (is_trailer_vehicle_type((string) $vehicle['tip_vehicul'])) {
        $trailerIds[(int) $vehicle['id']] = true;
    }
}
$chartCategories = [];
foreach ($categories as $tab => $category) {
    $chartCategories[$tab] = ['label' => $category['label'], 'counts' => $category['counts']];
}
$pageJson = json_encode([
    'tab' => 'prezentare',
    'categorii' => $chartCategories,
    'istoric' => $history,
    'urls' => [
        'schimb_ulei' => $categoryUrl('schimb_ulei'),
        'placute_frana' => $categoryUrl('placute_frana'),
        'anvelope' => $categoryUrl('anvelope'),
    ],
    'simPlans' => $overview['sim_plans'] ?? [],
    'trailers' => $trailerIds,
    'defaults' => ['interval' => (int) ($settings['interval_implicit_km'] ?? 10000), 'warn' => $defaultWarn, 'factor' => $factor, 'source' => $hourSource],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$visibleAlerts = 4;
?>
<link rel="stylesheet" href="<?= e(url('assets/css/revizii.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/revizii.css'))) ?>">

<div class="rv-page rv-overview">
    <div class="rv-heading">
        <div>
            <h1>Revizii</h1>
            <p>Prezentare generală a activității de mentenanță pentru întreaga flotă.</p>
        </div>
        <div class="rv-heading-actions">
            <?php if ($canSimulate): ?>
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#rvSimModal"><i class="bi bi-calculator"></i> Simulare calcul revizie</button>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <div class="dropdown">
                    <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear"></i> Configurare revizie</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach ($categories as $tab => $category): ?>
                            <li><a class="dropdown-item" href="<?= e($categoryUrl($tab, ['configurare' => 1])) ?>"><?= $categoryMeta[$tab]['icon'] ?? '' ?> <?= e($category['label']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php $activeTab = 'prezentare'; require __DIR__ . '/_tabs.php'; ?>

    <div class="rv-kpis">
        <div class="rv-kpi rv-kpi-ok">
            <div class="rv-kpi-icon"><?= $oilSvg ?></div>
            <div><div class="rv-kpi-value"><?= (int) ($kpi['in_regula'] ?? 0) ?></div><div class="rv-kpi-title">Vehicule în regulă</div><div class="rv-kpi-desc">Nu necesită intervenții în perioada următoare</div></div>
        </div>
        <div class="rv-kpi rv-kpi-soon">
            <div class="rv-kpi-icon"><i class="bi bi-clock"></i></div>
            <div><div class="rv-kpi-value"><?= (int) ($kpi['se_apropie'] ?? 0) ?></div><div class="rv-kpi-title">Necesită atenție</div><div class="rv-kpi-desc">Se apropie de termenul de revizie</div></div>
        </div>
        <div class="rv-kpi rv-kpi-due">
            <div class="rv-kpi-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div><div class="rv-kpi-value"><?= (int) ($kpi['necesita'] ?? 0) ?></div><div class="rv-kpi-title">Necesită intervenție</div><div class="rv-kpi-desc">Depășite sau cu termen imediat</div></div>
        </div>
        <div class="rv-kpi rv-kpi-total">
            <div class="rv-kpi-icon"><i class="bi bi-truck-front"></i></div>
            <div>
                <div class="rv-kpi-value"><?= (int) ($kpi['total'] ?? 0) ?></div>
                <div class="rv-kpi-title">Total vehicule monitorizate <i class="bi bi-info-circle rv-info-tip" title="Vehicule active distincte (cap tractor, camion, semi-remorcă). Un vehicul cu mai multe componente se numără o singură dată, cu starea celei mai critice componente."></i></div>
                <div class="rv-kpi-desc">Din flota activă<?php if (($kpi['neconfigurat'] ?? 0) + ($kpi['fara_date'] ?? 0) > 0): ?> · <span class="rv-kpi-extra"><?= (int) ($kpi['neconfigurat'] ?? 0) ?> neconfigurate<?= ($kpi['fara_date'] ?? 0) > 0 ? ', ' . (int) $kpi['fara_date'] . ' cu date insuficiente' : '' ?></span><?php endif; ?></div>
            </div>
        </div>
    </div>

    <section class="rv-card rv-ov-section" aria-labelledby="rvOvCategories">
        <div class="rv-ov-head">
            <h2 id="rvOvCategories">Situație pe tip de revizie</h2>
            <span class="rv-ov-head-side">
                <span class="rv-sync-line">Ultima sincronizare SasFleet: <strong><?= e($fmtDate($lastSync)) ?></strong>
                    <?php if ($credentialsAvailable): ?>
                        <button type="button" class="btn btn-link btn-sm p-0 ms-1" id="rvSyncBtn" data-autosync="<?= !empty($autoSync) ? '1' : '0' ?>" data-url="<?= e(build_query_url(['page' => 'revizii', 'action' => 'sync'])) ?>" data-token="<?= e(csrf_token()) ?>"><i class="bi bi-arrow-repeat"></i> Sincronizează</button>
                        <span class="rv-sync-msg" id="rvSyncMsg"></span>
                    <?php endif; ?>
                </span>
                <a class="rv-ov-link" href="<?= e($categoryUrl('schimb_ulei')) ?>">Vezi toate vehiculele <i class="bi bi-arrow-right"></i></a>
            </span>
        </div>
        <div class="rv-ov-categories">
            <?php foreach ($categories as $tab => $category):
                $counts = $category['counts'];
                $total = (int) $counts['total'];
                $meta = $categoryMeta[$tab];
                $rowsLegend = [
                    ['in_regula', 'În regulă', 'ok'],
                    ['se_apropie', 'Se apropie de termen', 'soon'],
                    ['necesita', $meta['due'], 'due'],
                ];
                if ($counts['fara_date'] > 0) {
                    $rowsLegend[] = ['fara_date', 'Date insuficiente', 'nodata'];
                }
                if ($counts['neconfigurat'] > 0) {
                    $rowsLegend[] = ['neconfigurat', 'Neconfigurat', 'none'];
                }
                ?>
                <article class="rv-ov-cat" data-category="<?= e($tab) ?>">
                    <header class="rv-ov-cat-head">
                        <span class="rv-ov-cat-icon"><?= $meta['icon'] ?></span>
                        <div>
                            <h3><?= e($category['label']) ?></h3>
                            <p><?= e($meta['desc']) ?></p>
                        </div>
                        <a class="rv-ov-link" href="<?= e($categoryUrl($tab)) ?>">Vezi detalii <i class="bi bi-arrow-right"></i></a>
                    </header>
                    <div class="rv-ov-cat-body">
                        <div class="rv-donut">
                            <canvas id="rvDonut-<?= e($tab) ?>" width="120" height="120" aria-label="Distribuția stărilor pentru <?= e($category['label']) ?>" role="img"></canvas>
                            <div class="rv-donut-center"><strong><?= $total ?></strong><span>vehicule</span></div>
                        </div>
                        <div class="rv-ov-legend-wrap">
                            <ul class="rv-ov-legend">
                                <?php foreach ($rowsLegend as [$key, $label, $class]): ?>
                                    <li><span class="rv-dot rv-dot-<?= e($class) ?>"></span><span class="rv-ov-legend-label"><?= e($label) ?></span><strong><?= (int) $counts[$key] ?></strong><span class="rv-ov-legend-pct"><?= e($pct((int) $counts[$key], $total)) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($tab === 'anvelope'): ?>
                                <div class="rv-ov-axles">
                                    <svg viewBox="0 0 92 28" aria-hidden="true"><rect x="2" y="4" width="10" height="20" rx="2"/><rect x="14" y="4" width="10" height="20" rx="2"/><line x1="24" y1="14" x2="68" y2="14"/><rect x="68" y="4" width="10" height="20" rx="2"/><rect x="80" y="4" width="10" height="20" rx="2"/></svg>
                                    <span>Monitorizare pe <?= (int) $category['componente'] ?> seturi<br><small>cap tractor / camion, semi-remorcă</small></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="rv-card rv-ov-section" aria-labelledby="rvOvAlerts">
        <div class="rv-ov-head">
            <h2 id="rvOvAlerts">Priorități / Alerte <?php if ($alerts !== []): ?><span class="rv-ov-count"><?= count($alerts) ?></span><?php endif; ?></h2>
            <?php if (count($alerts) > $visibleAlerts): ?>
                <button type="button" class="rv-ov-link btn btn-link p-0" id="rvAlertsToggle" aria-expanded="false" aria-controls="rvAlerts">Vezi toate alertele (<?= count($alerts) ?>) <i class="bi bi-arrow-right"></i></button>
            <?php endif; ?>
        </div>
        <?php if ($alerts === []): ?>
            <p class="rv-ov-empty"><i class="bi bi-check2-circle"></i> Nicio alertă: nicio componentă configurată nu este depășită sau aproape de termen.</p>
        <?php else: ?>
            <div class="rv-ov-alerts" id="rvAlerts">
                <?php foreach ($alerts as $i => $alert):
                    $due = $alert['status'] === ReviziiCalculator::STATUS_DUE;
                    $recordUrl = $categoryUrl($alert['categorie'], ['record' => $alert['vehicle_id'], 'comp' => $alert['tip'], 'from' => 'prezentare']);
                    ?>
                    <article class="rv-alert rv-alert-<?= $due ? 'due' : 'soon' ?>"<?= $i >= $visibleAlerts ? ' hidden data-extra-alert' : '' ?>>
                        <div class="rv-alert-main">
                            <span class="rv-alert-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
                            <div class="rv-alert-text">
                                <strong><?= e($alert['nr']) ?></strong>
                                <span><?= e($alert['categorie_label']) ?><?= $alert['categorie'] !== 'schimb_ulei' ? ' · ' . e($alert['componenta']) : '' ?></span>
                                <span class="rv-alert-km">
                                    <?= $due ? 'Depășit cu ' . e($fmtKm(abs($alert['km_ramasi']))) . ' km' : e($fmtKm($alert['km_ramasi'])) . ' km rămași' ?>
                                    <?php if (!$alert['complet']): ?><i class="bi bi-exclamation-diamond rv-flag" title="Date incomplete: km rămași sunt o limită superioară"></i><?php endif; ?>
                                </span>
                                <small><?= $due ? ($alert['categorie'] === 'schimb_ulei' ? 'Necesită schimb de ulei imediat' : 'Necesită înlocuire imediată') : 'Se apropie de termen (prag ' . e($fmtKm((float) $alert['prag_km'])) . ' km)' ?></small>
                            </div>
                            <span class="rv-alert-thumb"><?php if (!empty($alert['poza'])): ?><img src="<?= e((string) $alert['poza']) ?>" alt="" loading="lazy"><?php else: ?><?= $truckSvg ?><?php endif; ?></span>
                        </div>
                        <?php if ($canEdit): ?>
                            <a class="btn btn-primary btn-sm rv-alert-btn" href="<?= e($recordUrl) ?>">Înregistrează revizie</a>
                        <?php else: ?>
                            <a class="btn btn-outline-secondary btn-sm rv-alert-btn" href="<?= e($categoryUrl($alert['categorie'])) ?>">Vezi detalii</a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="rv-ov-bottom">
        <section class="rv-card rv-ov-section rv-ov-activity" aria-labelledby="rvOvActivity">
            <div class="rv-ov-head"><h2 id="rvOvActivity">Activitate recentă</h2></div>
            <div class="rv-ov-filters">
                <div class="rv-search"><i class="bi bi-search"></i><input type="search" class="form-control" id="rvHistSearch" placeholder="Caută vehicul, componentă..." aria-label="Caută în activitate"></div>
                <select class="form-select" id="rvHistCategory" aria-label="Categorie">
                    <option value="">Toate categoriile</option>
                    <?php foreach ($categories as $tab => $category): ?><option value="<?= e($tab) ?>"><?= e($category['label']) ?></option><?php endforeach; ?>
                </select>
                <select class="form-select" id="rvHistStatus" aria-label="Status">
                    <option value="">Toate statusurile</option>
                    <option value="finalizat">Finalizat</option>
                    <option value="date_incomplete">Date incomplete</option>
                </select>
                <div class="rv-select-icon"><i class="bi bi-calendar3"></i>
                    <select class="form-select" id="rvHistPeriod" aria-label="Perioadă">
                        <option value="30" selected>Ultimele 30 de zile</option>
                        <option value="90">Ultimele 90 de zile</option>
                        <option value="180">Ultimele 6 luni</option>
                        <option value="365">Ultimele 12 luni</option>
                        <option value="0">Tot istoricul</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="rv-table rv-ov-table">
                    <thead>
                        <tr><th>#</th><th>Dată</th><th>Vehicul</th><th>Categorie</th><th>Componentă</th><th>Acțiune</th><th>Operator</th><th>Status</th><th><span class="visually-hidden">Acțiuni</span></th></tr>
                    </thead>
                    <tbody id="rvHistBody"></tbody>
                </table>
            </div>
            <div class="rv-foot">
                <span id="rvHistCount"></span>
                <nav class="rv-pager" id="rvHistPager" aria-label="Paginare activitate"></nav>
            </div>
        </section>

        <section class="rv-card rv-ov-section rv-ov-chart" aria-labelledby="rvOvChart">
            <div class="rv-ov-head">
                <h2 id="rvOvChart">Intervenții pe categorie</h2>
                <div class="rv-select-icon"><i class="bi bi-calendar3"></i>
                    <select class="form-select form-select-sm" id="rvChartPeriod" aria-label="Perioadă grafic">
                        <option value="6" selected>Ultimele 6 luni</option>
                        <option value="12">Ultimele 12 luni</option>
                    </select>
                </div>
            </div>
            <div class="rv-ov-chart-box">
                <canvas id="rvMonthly" aria-label="Intervenții pe lună și categorie" role="img"></canvas>
                <p class="rv-ov-empty" id="rvMonthlyEmpty" hidden><i class="bi bi-bar-chart"></i> Nicio intervenție înregistrată în perioada aleasă.</p>
            </div>
        </section>
    </div>
</div>

<?php if ($canSimulate):
    $simTab = ReviziiModel::TIP_SCHIMB_ULEI;
    $simDefaultInterval = (int) ($settings['interval_implicit_km'] ?? 10000);
    require __DIR__ . '/_sim_modal.php';
endif; ?>

<script type="application/json" id="rvPageData"><?= $pageJson ?: '{}' ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= e(url('assets/js/revizii-overview.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/revizii-overview.js'))) ?>"></script>
<script src="<?= e(url('assets/js/revizii-common.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/revizii-common.js'))) ?>"></script>
