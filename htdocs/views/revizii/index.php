<?php
/**
 * Revizii: schimb ulei, placute frana, anvelope (cate un tab). Datele vin integral din BD
 * (telemetria SasFleet e sincronizata in fundal); filtrarea, cautarea, ordonarea si
 * paginarea se fac in browser.
 *
 * Asteapta: $dashboard (ReviziiService::buildDashboard), $vehicles, $canEdit, $canSimulate,
 * $credentialsAvailable, $autoSync.
 */
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$tab = (string) ($dashboard['tab'] ?? ReviziiModel::TIP_SCHIMB_ULEI);
$tabs = ReviziiModel::TABS;
$tabHours = (bool) ($tabs[$tab]['ore'] ?? false);
$tabLabel = (string) ($tabs[$tab]['label'] ?? 'Schimb ulei');
// Uleiul se urmareste doar pe vehiculele cu motor: fara ansambluri si fara coloane de semi-remorca.
$withTrailers = (bool) ($dashboard['with_trailers'] ?? true);
$unitPlural = $withTrailers ? 'ansambluri' : 'vehicule';
$rows = $dashboard['rows'] ?? [];
$kpi = $dashboard['kpi'] ?? ['in_regula' => 0, 'se_apropie' => 0, 'necesita' => 0, 'total' => 0];
$settings = $dashboard['settings'] ?? [];
$lastSync = $dashboard['last_sync'] ?? null;
$hourSource = (string) ($dashboard['hour_source'] ?? 'ore_aspirare');
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$canEdit = !empty($canEdit);
$canSimulate = !empty($canSimulate);
$credentialsAvailable = !empty($credentialsAvailable);
$factor = (float) ($settings['factor_km_ora'] ?? 40);
// Intervalul implicit exista doar pentru ulei; placutele si anvelopele se completeaza explicit.
$defaultInterval = $tab === ReviziiModel::TIP_SCHIMB_ULEI ? (int) ($settings['interval_implicit_km'] ?? 10000) : null;
$defaultWarn = (int) ($settings['prag_avertizare_km'] ?? 1500);
$hourSources = ReviziiModel::HOUR_SOURCES;
$recordVerb = $tab === ReviziiModel::TIP_SCHIMB_ULEI ? 'Înregistrează revizie' : 'Înregistrează înlocuire';
$tabUrl = static fn (string $key): string => build_query_url($key === ReviziiModel::TIP_SCHIMB_ULEI ? ['page' => 'revizii'] : ['page' => 'revizii', 'tip' => $key]);

$fmtKm = static fn (?float $value): string => $value === null ? '—' : number_format($value, 0, ',', '.');
$fmtDec = static fn (?float $value, int $decimals = 1): string => $value === null ? '—' : number_format($value, $decimals, ',', '.');
$fmtDate = static fn (?string $value, string $format = 'd.m.Y H:i'): string => $value ? date($format, (int) strtotime($value)) : '—';

$statusMeta = [
    'in_regula' => ['label' => 'În regulă', 'class' => 'ok', 'icon' => 'bi-check-circle-fill'],
    'se_apropie' => ['label' => 'Se apropie', 'class' => 'soon', 'icon' => 'bi-clock-fill'],
    'necesita' => ['label' => $tab === ReviziiModel::TIP_SCHIMB_ULEI ? 'Necesită revizie' : 'Necesită înlocuire', 'class' => 'due', 'icon' => 'bi-exclamation-triangle-fill'],
    'fara_date' => ['label' => 'Date insuficiente', 'class' => 'nodata', 'icon' => 'bi-question-circle-fill'],
    'neconfigurat' => ['label' => 'Neconfigurat', 'class' => 'none', 'icon' => 'bi-dash-circle'],
];
$kindLabels = [
    'ansamblu' => 'Ansamblu',
    'cap_tractor' => 'Cap tractor',
    'camion' => 'Camion',
    'semiremorca' => 'Semi-remorcă',
];
$daysLabel = static fn (int $n): string => $n === 1 ? '1 zi' : $n . ' zile';
// Cu telemetrie lipsa km consumati sunt minimi, deci km ramasi sunt o limita superioara.
$upperBound = static fn (array $c): string => ($c['calc'] !== null && !$c['complet']) ? '≤ ' : '';
$componentLabel = static fn (array $c): string => ($c['is_trailer'] ? 'Semi-remorcă' : ($c['tip'] === 'camion' ? 'Camion' : 'Cap tractor'))
    . ($c['sub'] !== '' ? ' · ' . $c['sub'] : '');

$truckSvg = '<svg viewBox="0 0 64 32" aria-hidden="true"><rect x="2" y="6" width="38" height="18" rx="2" fill="#e9edf3" stroke="#8a96a8"/><path d="M42 11h10l7 7v6H42z" fill="#2f5db8"/><rect x="46" y="13" width="6" height="5" rx="1" fill="#cfe0ff"/><circle cx="12" cy="26" r="4" fill="#38404d"/><circle cx="30" cy="26" r="4" fill="#38404d"/><circle cx="52" cy="26" r="4" fill="#38404d"/></svg>';
$oilSvg = '<svg viewBox="0 0 32 20" aria-hidden="true"><path d="M3 7h6l2-2h6l3 2 9-3-7 10H8L5 11H3z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M29 12c0 1.4-1 2.5-2 2.5s-2-1.1-2-2.5 2-3.5 2-3.5 2 2.1 2 3.5z" fill="currentColor"/></svg>';
$tabIcons = [
    'schimb_ulei' => '<span class="rv-oil">' . $oilSvg . '</span>',
    'placute_frana' => '<i class="bi bi-disc"></i>',
    'anvelope' => '<i class="bi bi-record-circle"></i>',
];

$statusBadge = static function (?array $component, bool $small = false) use ($statusMeta): string {
    if ($component === null) {
        return '<span class="text-muted">—</span>';
    }
    $meta = $statusMeta[$component['status']] ?? $statusMeta['neconfigurat'];
    return '<span class="rv-badge rv-badge-' . $meta['class'] . ($small ? ' rv-badge-sm' : '') . '"><i class="bi ' . $meta['icon'] . '"></i>' . e($meta['label']) . '</span>';
};
$statusBadgeFor = static function (string $status) use ($statusMeta): string {
    $meta = $statusMeta[$status] ?? $statusMeta['neconfigurat'];
    return '<span class="rv-badge rv-badge-' . $meta['class'] . '"><i class="bi ' . $meta['icon'] . '"></i>' . e($meta['label']) . '</span>';
};

$tooltip = static function (array $component) use ($fmtDec, $fmtKm, $factor): string {
    $calc = $component['calc'];
    if ($calc === null) {
        return 'Componenta nu are încă un T0 / interval configurat.';
    }
    $lines = ['Km efectuați: ' . $fmtKm($calc['km_efectuati'])];
    if ($component['ore_aplicabile']) {
        $lines[] = 'Ore motor eligibile: ' . $fmtDec($calc['ore_motor'], 2) . ' h × ' . $fmtDec($factor, 0) . ' = ' . $fmtKm($calc['km_din_ore']) . ' km';
    }
    if ($calc['consum_initial_km'] > 0) {
        $lines[] = 'Consum inițial (odometru): ' . $fmtKm($calc['consum_initial_km']);
    }
    $lines[] = 'Km total echivalenți: ' . $fmtKm($calc['km_total']) . ' / ' . $fmtKm((float) $calc['interval_km']);
    if (!$component['complet']) {
        $lines[] = 'Date incomplete — valorile sunt minime.';
    }

    return implode("\n", $lines);
};

$flags = static function (array $component): string {
    $html = '';
    if ($component['calc'] !== null && !$component['complet']) {
        $html .= ' <i class="bi bi-exclamation-diamond rv-flag" title="Date incomplete: valorile sunt minime" aria-label="Date incomplete"></i>';
    }
    if (!empty($component['date_vechi'])) {
        $html .= ' <i class="bi bi-clock-history rv-flag" title="Sincronizare SasFleet veche" aria-label="Sincronizare veche"></i>';
    }
    return $html;
};

/** Eticheta scurta a sub-componentei (Față / Spate / Set) in celulele stivuite din tabel. */
$subPrefix = static fn (array $c): string => $c['sub'] !== '' ? '<span class="rv-sub">' . e($c['sub']) . '</span>' : '';

$remainingCell = static function (array $items) use ($fmtKm, $tooltip, $statusMeta, $flags, $upperBound, $subPrefix): string {
    if ($items === []) {
        return '<span class="text-muted">—</span>';
    }
    $html = '';
    foreach ($items as $component) {
        if ($component['calc'] === null) {
            $value = '<span class="text-muted" title="' . e($tooltip($component)) . '">—</span>';
        } else {
            $class = $statusMeta[$component['status']]['class'] ?? 'none';
            $value = '<span class="rv-remaining rv-text-' . $class . '" title="' . e($tooltip($component)) . '">' . $upperBound($component) . $fmtKm($component['calc']['km_ramasi']) . '</span>' . $flags($component);
        }
        $html .= '<div class="rv-stack">' . $subPrefix($component) . $value . '</div>';
    }
    return $html;
};

$statusCell = static function (array $items) use ($statusBadge, $subPrefix): string {
    if ($items === []) {
        return '<span class="text-muted">—</span>';
    }
    $html = '';
    foreach ($items as $component) {
        $html .= '<div class="rv-stack">' . $subPrefix($component) . $statusBadge($component, count($items) > 1) . '</div>';
    }
    return $html;
};

$progressBar = static function (?array $component) use ($statusMeta): string {
    if ($component === null || $component['calc'] === null) {
        return '<div class="rv-progress-line"><div class="rv-progress"><span style="width:0"></span></div><span class="rv-progress-pct">—</span></div>';
    }
    $pct = (float) $component['calc']['progres'];
    $class = $statusMeta[$component['status']]['class'] ?? 'none';

    return '<div class="rv-progress-line"><div class="rv-progress"><span class="rv-bar-' . $class . '" style="width:' . max(0, min(100, $pct)) . '%"></span></div><span class="rv-progress-pct">' . number_format($pct, 0) . '%</span></div>';
};

// Datele pentru modalele de configurare / revizie / simulare.
$pack = static function (array $component) use ($defaultInterval, $defaultWarn): array {
    return [
        'id' => $component['vehicle_id'],
        'tip' => $component['tip_revizie'],
        'sub' => $component['sub'],
        'nr' => $component['nr'],
        'marca' => $component['marca'],
        'km_bord' => $component['km_bord'],
        'interval' => $component['plan']['interval_km'] ?? $defaultInterval,
        'km_last' => $component['plan']['km_ultima_revizie'] ?? null,
        'warn' => $component['plan']['prag_avertizare_km'] ?? $defaultWarn,
        'configured' => $component['plan'] !== null,
        't0' => $component['plan']['baseline_at'] ?? null,
        'is_trailer' => $component['is_trailer'],
        'vtype' => $component['tip'],
    ];
};
$jsRows = [];
foreach ($rows as $index => $row) {
    $jsRows[] = [
        'key' => 'r' . $index,
        'kind' => $row['kind'],
        'label' => $row['label'],
        'tractor' => array_map($pack, $row['items']['tractor']),
        'semi' => array_map($pack, $row['items']['semi']),
    ];
}
$simPlans = [];
foreach ($jsRows as $jsRow) {
    foreach (array_merge($jsRow['tractor'], $jsRow['semi']) as $item) {
        $simPlans[$tab][$item['id']] ??= $item;
    }
}
$trailerIds = [];
foreach ($vehicles as $vehicle) {
    if (is_trailer_vehicle_type((string) $vehicle['tip_vehicul'])) {
        $trailerIds[(int) $vehicle['id']] = true;
    }
}
$pageData = [
    'tab' => $tab,
    'hours' => $tabHours,
    'simPlans' => $simPlans,
    'trailers' => $trailerIds,
    // Deschidere directa din Prezentare generala: ?record=<vehicul>&comp=<tip> sau ?config=<vehicul>.
    'open' => [
        'record' => (int) ($_GET['record'] ?? 0),
        'comp' => (string) ($_GET['comp'] ?? ''),
        'config' => (int) ($_GET['config'] ?? 0),
        'from' => (string) ($_GET['from'] ?? ''),
        'configurare' => !empty($_GET['configurare']),
    ],
    'rows' => $jsRows,
    'defaults' => ['interval' => $defaultInterval, 'warn' => $defaultWarn, 'factor' => $factor, 'source' => $hourSource],
];
$pageJson = json_encode($pageData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

/** Panoul cu calculul complet al unei componente (folosit in tabel si in modalul de detalii). */
$detailCard = static function (array $component) use ($statusBadge, $fmtDec, $fmtKm, $fmtDate, $factor, $statusMeta, $componentLabel, $hourSources, $daysLabel, $upperBound, $tabHours): string {
    $calc = $component['calc'];
    $detail = $component['detaliu'];
    ob_start();
    ?>
    <div class="rv-detail-card">
        <div class="rv-detail-title"><?= e($componentLabel($component)) ?> · <strong><?= e($component['nr']) ?></strong> <?= $statusBadge($component, true) ?></div>
        <?php if ($calc === null): ?>
            <p class="text-muted small mb-2">Fără T0 configurat. Folosește „Configurare revizie”.</p>
        <?php else: ?>
            <dl class="rv-breakdown">
                <dt>Km efectuați <small><?= $component['is_trailer'] ? 'alocați din capul tractor, pe intervalele de cuplare' : 'SasFleet, de la T0' ?></small></dt>
                <dd><?= e($fmtDec($calc['km_efectuati'])) ?></dd>
                <?php if ($tabHours): ?>
                    <dt>Ore motor eligibile <small><?= $component['is_trailer'] ? 'nu se aplică semi-remorcii' : e($hourSources[$detail['sursa_ore']] ?? '') ?></small></dt>
                    <dd><?= $component['is_trailer'] ? '—' : e($fmtDec($calc['ore_motor'], 2)) . ' h' ?></dd>
                    <dt>Km echivalenți din ore motor <small>ore × <?= e($fmtDec($factor, 0)) ?> km/oră</small></dt>
                    <dd><?= e($fmtDec($calc['km_din_ore'])) ?></dd>
                <?php endif; ?>
                <?php if ($calc['consum_initial_km'] > 0): ?>
                    <dt>Consum inițial <small>odometru, până la configurare</small></dt>
                    <dd><?= e($fmtDec($calc['consum_initial_km'])) ?></dd>
                <?php endif; ?>
                <dt class="rv-total"><?= $tabHours ? 'Km total echivalenți' : 'Km de la T0' ?></dt>
                <dd class="rv-total"><?= e($fmtDec($calc['km_total'])) ?></dd>
                <dt>Interval <small>prag notificare <?= e($fmtKm((float) $component['prag_km'])) ?> km</small></dt>
                <dd><?= e($fmtKm((float) $calc['interval_km'])) ?></dd>
                <dt class="rv-total">Km rămași</dt>
                <dd class="rv-total rv-text-<?= e($statusMeta[$component['status']]['class'] ?? 'none') ?>"><?= e($upperBound($component) . $fmtDec($calc['km_ramasi'])) ?></dd>
            </dl>
            <?php if ($component['ore_aplicabile']): ?>
                <p class="rv-compare">SasFleet: motor pornit <?= e($fmtDec((float) $detail['ore_motor_pornit'], 1)) ?> h (pe loc <?= e($fmtDec((float) $detail['ore_sas_pe_loc'], 1)) ?> h, în mers <?= e($fmtDec(max(0, (float) $detail['ore_motor_pornit'] - (float) $detail['ore_sas_pe_loc']), 1)) ?> h) · ore aspirare din curse <?= e($fmtDec((float) $detail['ore_aspirare'], 1)) ?> h</p>
            <?php elseif (!$tabHours): ?>
                <p class="rv-compare">Uzura se calculează doar din km parcurși — orele cu motorul pornit nu se aplică.</p>
            <?php endif; ?>
        <?php endif; ?>
        <ul class="rv-meta">
            <?php if ($component['plan'] !== null): ?>
                <li><i class="bi bi-flag"></i> T0: <?= e($fmtDate($component['plan']['baseline_at'])) ?><?= $component['plan']['km_ultima_revizie'] !== null ? ' · ' . e($fmtKm((float) $component['plan']['km_ultima_revizie'])) . ' km bord' : '' ?></li>
            <?php endif; ?>
            <li><i class="bi bi-broadcast"></i> Ultima sincronizare SasFleet: <?= e($fmtDate($component['ultima_sincronizare'])) ?><?= !empty($component['date_vechi']) ? ' <span class="rv-stale">(date vechi)</span>' : '' ?></li>
            <?php foreach ($component['surse'] as $source): ?><li><i class="bi bi-database"></i> <?= e($source) ?></li><?php endforeach; ?>
            <?php if ($component['zile_lipsa'] !== []):
                $days = $component['zile_lipsa'];
                $shown = array_map(static fn (string $d): string => date('d.m', (int) strtotime($d)), array_slice($days, 0, 8));
                ?>
                <li class="rv-warn"><i class="bi bi-exclamation-diamond"></i> <?= e($daysLabel(count($days))) ?> fără telemetrie (<?= e(implode(', ', $shown)) ?><?= count($days) > 8 ? '…' : '' ?>) — km sunt minimi; lipsa datelor nu e tratată ca 0 km.</li>
            <?php endif; ?>
            <?php foreach ($component['note'] as $note): ?><li class="text-muted"><i class="bi bi-dot"></i><?= e($note) ?></li><?php endforeach; ?>
        </ul>
        <?php if ($component['pasi'] !== []): ?>
            <details class="rv-steps">
                <summary>Pașii calculului</summary>
                <ol><?php foreach ($component['pasi'] as $step): ?><li><?= e($step) ?></li><?php endforeach; ?></ol>
            </details>
        <?php endif; ?>
        <?php if (!empty($component['istoric'])): ?>
            <table class="rv-history">
                <thead><tr><th>T0</th><th>Km bord</th><th>Km consumați</th><th>Interval</th><th>Operator</th></tr></thead>
                <tbody>
                <?php foreach ($component['istoric'] as $entry): ?>
                    <tr>
                        <td><?= e($fmtDate((string) $entry['data_revizie'])) ?></td>
                        <td><?= e($fmtKm($entry['km_bord'] !== null ? (float) $entry['km_bord'] : null)) ?></td>
                        <td><?= e($fmtKm($entry['km_echivalenti'] !== null ? (float) $entry['km_echivalenti'] : null)) ?><?= (int) $entry['date_complete'] === 1 || $entry['km_echivalenti'] === null ? '' : ' <i class="bi bi-exclamation-diamond rv-flag" title="Ciclul anterior avea zile fără telemetrie"></i>' ?></td>
                        <td><?= e($fmtKm((float) $entry['interval_km'])) ?></td>
                        <td><?= e((string) ($entry['created_by_name'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-muted small mb-0">Nicio înregistrare încă.</p>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
};

/** Indicatorul compact al unei componente pe card. */
$compactComponent = static function (array $component) use ($statusMeta, $fmtKm, $fmtDate, $tooltip, $componentLabel, $flags, $upperBound, $tabHours): string {
    $calc = $component['calc'];
    $class = $statusMeta[$component['status']]['class'] ?? 'none';
    ob_start();
    ?>
    <div class="rv-comp">
        <div class="rv-comp-head">
            <span class="rv-comp-label"><?= e($componentLabel($component)) ?> · <?= e($component['nr']) ?></span>
            <span class="rv-dot rv-dot-<?= e($class) ?>" title="<?= e($statusMeta[$component['status']]['label'] ?? '') ?>"></span>
        </div>
        <?php if ($calc === null): ?>
            <div class="rv-comp-empty">Neconfigurat — fără T0</div>
        <?php else: ?>
            <div class="rv-comp-main" title="<?= e($tooltip($component)) ?>">
                <span class="rv-comp-rem rv-text-<?= e($class) ?>"><?= e($upperBound($component) . $fmtKm($calc['km_ramasi'])) ?></span>
                <span class="rv-comp-unit">km rămași<?= $flags($component) ?></span>
                <span class="rv-comp-of"><?= e($fmtKm($calc['km_total'])) ?> / <?= e($fmtKm((float) $calc['interval_km'])) ?> km<?= $tabHours ? ' echiv.' : '' ?></span>
            </div>
            <?php if ($component['ore_aplicabile'] && $component['detaliu'] !== null): ?>
                <div class="rv-comp-hours" title="Motor pornit total (SasFleet), din care pe loc; în conversie intră doar orele eligibile">
                    <i class="bi bi-speedometer2"></i> Motor pornit <?= e(number_format((float) $component['detaliu']['ore_motor_pornit'], 1, ',', '.')) ?> h · pe loc <?= e(number_format((float) $component['detaliu']['ore_sas_pe_loc'], 1, ',', '.')) ?> h
                    · <strong>+<?= e($fmtKm($calc['km_din_ore'])) ?> km</strong>
                </div>
            <?php endif; ?>
            <div class="rv-progress-line"><div class="rv-progress"><span class="rv-bar-<?= e($class) ?>" style="width:<?= max(0, min(100, (float) $calc['progres'])) ?>%"></span></div><span class="rv-progress-pct"><?= number_format((float) $calc['progres'], 0) ?>%</span></div>
        <?php endif; ?>
        <div class="rv-comp-meta">
            <span><i class="bi bi-flag"></i> T0 <?= e($fmtDate($component['plan']['baseline_at'] ?? null, 'd.m.Y')) ?></span>
            <span><i class="bi bi-broadcast"></i> <?= e($fmtDate($component['ultima_sincronizare'], 'd.m H:i')) ?></span>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<link rel="stylesheet" href="<?= e(url('assets/css/revizii.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/revizii.css'))) ?>">

<div class="rv-page">
    <div class="rv-heading">
        <div>
            <h1>Revizii</h1>
            <p>Monitorizează schimbul de ulei, plăcuțele de frână și anvelopele, după km reali SasFleet.</p>
        </div>
        <div class="rv-heading-actions">
            <?php if ($canSimulate): ?>
                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#rvSimModal"><i class="bi bi-calculator"></i> Simulare calcul revizie</button>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-primary" data-config-open data-bs-toggle="modal" data-bs-target="#rvConfigModal"><i class="bi bi-gear"></i> Configurare revizie</button>
            <?php endif; ?>
        </div>
    </div>

    <?php $activeTab = $tab; require __DIR__ . '/_tabs.php'; ?>

    <?php if (!$credentialsAvailable): ?>
        <div class="alert alert-warning py-2">Credențialele SasFleet nu sunt configurate (<code>SAS_API_USERNAME</code> / <code>SAS_API_PASSWORD</code>) — kilometrajul nu se poate sincroniza automat.</div>
    <?php endif; ?>

    <div class="rv-kpis">
        <div class="rv-kpi rv-kpi-ok">
            <div class="rv-kpi-icon"><?= $tabIcons[$tab] ?? $oilSvg ?></div>
            <div><div class="rv-kpi-value"><?= (int) $kpi['in_regula'] ?></div><div class="rv-kpi-title"><?= $withTrailers ? 'Ansambluri' : 'Vehicule' ?> în regulă</div><div class="rv-kpi-desc"><?= $withTrailers ? 'Toate componentele în termen' : 'Cap tractor / camion în termen' ?></div></div>
        </div>
        <div class="rv-kpi rv-kpi-soon">
            <div class="rv-kpi-icon"><i class="bi bi-clock"></i></div>
            <div><div class="rv-kpi-value"><?= (int) $kpi['se_apropie'] ?></div><div class="rv-kpi-title">Se apropie de termen</div><div class="rv-kpi-desc">Cel puțin o componentă aproape de termen</div></div>
        </div>
        <div class="rv-kpi rv-kpi-due">
            <div class="rv-kpi-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div><div class="rv-kpi-value"><?= (int) $kpi['necesita'] ?></div><div class="rv-kpi-title"><?= e($statusMeta['necesita']['label']) ?></div><div class="rv-kpi-desc">Cel puțin o componentă depășită</div></div>
        </div>
        <div class="rv-kpi rv-kpi-total">
            <div class="rv-kpi-icon"><i class="bi bi-truck-front"></i></div>
            <div><div class="rv-kpi-value"><?= (int) $kpi['total'] ?></div><div class="rv-kpi-title">Total <?= e($unitPlural) ?></div><div class="rv-kpi-desc"><?= $withTrailers ? 'Monitorizate pentru ' . e(mb_strtolower($tabLabel)) : 'Capete tractor și camioane (semi-remorcile nu au motor)' ?></div></div>
        </div>
    </div>

    <section class="rv-card rv-status" aria-labelledby="rvMonitorTitle">
        <div class="rv-status-head">
            <div class="rv-status-title">
                <i class="bi bi-truck"></i>
                <div>
                    <h2 id="rvMonitorTitle">Monitorizare vehicule · <?= e($tabLabel) ?></h2>
                    <div class="rv-sync-line">
                        Ultima sincronizare SasFleet: <strong><?= e($fmtDate($lastSync)) ?></strong>
                        <?php if ($lastSync !== null && time() - (int) strtotime($lastSync) > ReviziiService::STALE_AFTER_SECONDS): ?>
                            <span class="rv-stale"><i class="bi bi-exclamation-triangle"></i> date vechi</span>
                        <?php endif; ?>
                        <?php if ($credentialsAvailable): ?>
                            <button type="button" class="btn btn-link btn-sm p-0 ms-1" id="rvSyncBtn" data-autosync="<?= !empty($autoSync) ? '1' : '0' ?>" data-url="<?= e(build_query_url(['page' => 'revizii', 'action' => 'sync'])) ?>" data-token="<?= e(csrf_token()) ?>"><i class="bi bi-arrow-repeat"></i> Sincronizează</button>
                            <span class="rv-sync-msg" id="rvSyncMsg"></span>
                        <?php endif; ?>
                        <?php if ($tabHours): ?>
                            · Ore eligibile: <?= e($hourSources[$hourSource] ?? $hourSource) ?>, × <?= e($fmtDec($factor, 0)) ?> km/oră
                        <?php else: ?>
                            · Calcul doar din km parcurși (fără ore motor)
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="rv-filters">
                <div class="rv-search"><i class="bi bi-search"></i><input type="search" class="form-control" id="rvSearch" placeholder="Caută ansamblu, număr, marcă..." aria-label="Caută"></div>
                <div class="rv-select-icon"><i class="bi bi-funnel"></i>
                    <select class="form-select" id="rvStatusFilter" aria-label="Status">
                        <option value="">Toate statusurile</option>
                        <option value="necesita"><?= e($statusMeta['necesita']['label']) ?></option>
                        <option value="se_apropie">Se apropie</option>
                        <option value="in_regula">În regulă</option>
                        <option value="fara_date">Date insuficiente</option>
                        <option value="neconfigurat">Neconfigurat</option>
                    </select>
                </div>
                <div class="rv-select-icon"><i class="bi bi-truck"></i>
                    <select class="form-select" id="rvKindFilter" aria-label="Tip">
                        <?php if ($withTrailers): ?>
                            <option value="">Toate ansamblurile</option>
                            <option value="ansamblu">Ansambluri (cap + semi)</option>
                            <option value="cap_tractor">Cap tractor fără semi</option>
                            <option value="camion">Camioane</option>
                            <option value="semiremorca">Semi-remorci necuplate</option>
                        <?php else: ?>
                            <option value="">Toate vehiculele</option>
                            <option value="cap_tractor">Capete tractor</option>
                            <option value="camion">Camioane</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="btn-group rv-view-switch" role="group" aria-label="Mod de afișare">
                    <button type="button" class="btn btn-outline-secondary active" data-view="cards" aria-pressed="true"><i class="bi bi-grid-3x2-gap"></i> Carduri</button>
                    <button type="button" class="btn btn-outline-secondary" data-view="table" aria-pressed="false"><i class="bi bi-table"></i> Tabel</button>
                </div>
            </div>
        </div>

        <?php if ($rows === []): ?>
            <p class="text-center text-muted py-4 mb-0">Nu există capete tractor, camioane sau semi-remorci active.</p>
        <?php endif; ?>

        <div class="rv-grid" id="rvCards" data-view-panel="cards">
            <?php foreach ($rows as $index => $row):
                $tractor = $row['tractor'];
                $semi = $row['semi'];
                $lead = $tractor ?? $semi;
                $allItems = array_merge($row['items']['tractor'], $row['items']['semi']);
                $statuses = array_unique(array_column($allItems, 'status'));
                $search = mb_strtolower(implode(' ', array_filter([$tractor['nr'] ?? '', $tractor['marca'] ?? '', $semi['nr'] ?? '', $semi['marca'] ?? '', $kindLabels[$row['kind']] ?? ''])));
                $class = $statusMeta[$row['status']]['class'] ?? 'none';
                ?>
                <article class="rv-vcard rv-vcard-<?= e($class) ?>" data-row="r<?= $index ?>" data-kind="<?= e($row['kind']) ?>" data-statuses="<?= e(implode(' ', $statuses)) ?>" data-search="<?= e($search) ?>" data-order="<?= $index ?>">
                    <header class="rv-vcard-head">
                        <span class="rv-thumb"><?php if (!empty($lead['poza'])): ?><img src="<?= e((string) $lead['poza']) ?>" alt="" loading="lazy"><?php else: ?><?= $truckSvg ?><?php endif; ?></span>
                        <div class="rv-vcard-id">
                            <strong><?= e($tractor['nr'] ?? $semi['nr']) ?><?= $tractor && $semi ? ' <span class="rv-plus">+</span> ' . e($semi['nr']) : '' ?></strong>
                            <small><?= e($kindLabels[$row['kind']] ?? '') ?> · <?= e($lead['marca'] !== '' ? $lead['marca'] : '—') ?></small>
                        </div>
                        <?= $statusBadgeFor($row['status']) ?>
                    </header>
                    <div class="rv-vcard-body<?= count($allItems) > 1 ? ' rv-two-comp' : '' ?>">
                        <?php foreach ($allItems as $component): ?><?= $compactComponent($component) ?><?php endforeach; ?>
                    </div>
                    <footer class="rv-vcard-foot">
                        <button type="button" class="btn btn-link btn-sm" data-detail-open="r<?= $index ?>"><i class="bi bi-calculator"></i> Detalii calcul</button>
                        <span class="rv-vcard-actions">
                            <?php if ($canEdit): ?>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-configure="r<?= $index ?>" title="Configurează" aria-label="Configurează"><i class="bi bi-gear"></i></button>
                                <button type="button" class="btn btn-primary btn-sm rv-record" data-row-key="r<?= $index ?>" data-bs-toggle="modal" data-bs-target="#rvRecordModal"><?= e($recordVerb) ?></button>
                            <?php endif; ?>
                        </span>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="table-responsive" data-view-panel="table" hidden>
            <table class="rv-table" id="rvTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?= $withTrailers ? 'Ansamblu / Vehicul' : 'Vehicul' ?></th>
                        <th><?= $withTrailers ? 'Cap tractor' : 'Cap tractor / camion' ?><small>Marcă • Număr</small></th>
                        <?php if ($withTrailers): ?><th>Semi-remorcă<small>Marcă • Număr</small></th><?php endif; ?>
                        <th>Km curent<small><?= $withTrailers ? 'cap tractor' : 'bord' ?></small></th>
                        <?php if ($withTrailers): ?><th>Km curent<small>semi-remorcă</small></th><?php endif; ?>
                        <th>Km rămași<small><?= $withTrailers ? 'cap tractor' : 'până la schimb' ?></small></th>
                        <?php if ($withTrailers): ?><th>Km rămași<small>semi-remorcă</small></th><?php endif; ?>
                        <th>Status<?= $withTrailers ? '<small>cap tractor</small>' : '' ?></th>
                        <?php if ($withTrailers): ?><th>Status<small>semi-remorcă</small></th><?php endif; ?>
                        <th>Progres</th>
                        <th>Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $index => $row):
                    $tractor = $row['tractor'];
                    $semi = $row['semi'];
                    $lead = $tractor ?? $semi;
                    $allItems = array_merge($row['items']['tractor'], $row['items']['semi']);
                    $statuses = array_unique(array_column($allItems, 'status'));
                    $search = mb_strtolower(implode(' ', array_filter([$tractor['nr'] ?? '', $tractor['marca'] ?? '', $semi['nr'] ?? '', $semi['marca'] ?? '', $kindLabels[$row['kind']] ?? ''])));
                    ?>
                    <tr class="rv-row" data-row="r<?= $index ?>" data-kind="<?= e($row['kind']) ?>" data-statuses="<?= e(implode(' ', $statuses)) ?>" data-search="<?= e($search) ?>">
                        <td class="rv-idx"></td>
                        <td>
                            <div class="rv-vehicle">
                                <span class="rv-thumb"><?php if (!empty($lead['poza'])): ?><img src="<?= e((string) $lead['poza']) ?>" alt="" loading="lazy"><?php else: ?><?= $truckSvg ?><?php endif; ?></span>
                                <span><strong><?= e($lead['nr']) ?></strong><small><?= e($lead['marca'] !== '' ? $lead['marca'] : ($kindLabels[$row['kind']] ?? '')) ?></small></span>
                            </div>
                        </td>
                        <td><?php if ($tractor): ?><span class="rv-two"><?= e($tractor['marca'] ?: '—') ?><small><?= e($tractor['nr']) ?></small></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                        <?php if ($withTrailers): ?><td><?php if ($semi): ?><span class="rv-two"><?= e($semi['marca'] ?: '—') ?><small><?= e($semi['nr']) ?></small></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td><?php endif; ?>
                        <td><?= $tractor ? e($fmtKm((float) $tractor['km_bord'])) : '<span class="text-muted">—</span>' ?></td>
                        <?php if ($withTrailers): ?><td><?= $semi ? e($fmtKm((float) $semi['km_bord'])) : '<span class="text-muted">—</span>' ?></td><?php endif; ?>
                        <td><?= $remainingCell($row['items']['tractor']) ?></td>
                        <?php if ($withTrailers): ?><td><?= $remainingCell($row['items']['semi']) ?></td><?php endif; ?>
                        <td><?= $statusCell($row['items']['tractor']) ?></td>
                        <?php if ($withTrailers): ?><td><?= $statusCell($row['items']['semi']) ?></td><?php endif; ?>
                        <td class="rv-progress-cell"><?php foreach ($allItems as $component): ?><?= $progressBar($component) ?><?php endforeach; ?></td>
                        <td>
                            <div class="rv-actions">
                                <?php if ($canEdit): ?>
                                    <button type="button" class="btn btn-primary btn-sm rv-record" data-row-key="r<?= $index ?>" data-bs-toggle="modal" data-bs-target="#rvRecordModal"><?= e($recordVerb) ?></button>
                                <?php endif; ?>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-outline-secondary btn-sm rv-kebab" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mai multe acțiuni"><i class="bi bi-three-dots-vertical"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><button type="button" class="dropdown-item" data-toggle-detail="r<?= $index ?>"><i class="bi bi-calculator me-2"></i>Detalii calcul și istoric</button></li>
                                        <?php if ($canEdit): ?><li><button type="button" class="dropdown-item" data-configure="r<?= $index ?>"><i class="bi bi-gear me-2"></i>Configurează</button></li><?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr class="rv-detail" data-detail="r<?= $index ?>" hidden><td colspan="<?= $withTrailers ? 12 : 8 ?>"></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="rv-foot">
            <span id="rvCount" data-unit="<?= e($unitPlural) ?>">Afișează <?= count($rows) ?> din <?= count($rows) ?> <?= e($unitPlural) ?></span>
            <nav class="rv-pager" id="rvPager" aria-label="Paginare"></nav>
        </div>
    </section>
</div>

<?php foreach ($rows as $index => $row): ?>
<template id="rvDetail-r<?= $index ?>">
    <div class="rv-detail-grid">
        <?php foreach (array_merge($row['items']['tractor'], $row['items']['semi']) as $component): ?><?= $detailCard($component) ?><?php endforeach; ?>
    </div>
</template>
<?php endforeach; ?>

<div class="modal fade" id="rvDetailModal" tabindex="-1" aria-labelledby="rvDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rvDetailTitle">Detalii calcul · <?= e($tabLabel) ?> · <span data-detail-label></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body" data-detail-body></div>
        </div>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="rvConfigModal" tabindex="-1" aria-labelledby="rvConfigTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form class="modal-content rv-config" method="post" action="<?= e(build_query_url(['page' => 'revizii', 'action' => 'save_config'])) ?>" id="rvConfigForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="rvConfigTitle"><i class="bi bi-gear me-1"></i>Configurare <?= e(mb_strtolower($tabLabel)) ?> – <span data-config-title>Ansamblu</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <p class="rv-modal-intro"><?= $withTrailers
                    ? 'Intervalele de kilometri pentru ' . e(mb_strtolower($tabLabel)) . '. Fiecare componentă (cap tractor' . ($tab === 'placute_frana' ? ' față / spate' : '') . ', semi-remorcă) are configurare și evidență separate.'
                    : 'Intervalul de kilometri pentru schimbul de ulei, per cap tractor sau camion. Semi-remorcile nu au motor, deci nu au schimb de ulei.' ?></p>
                <div class="row g-3 mb-3">
                    <div class="col-md-5"<?= $withTrailers ? '' : ' hidden' ?>>
                        <label class="form-label" for="rvTipMonitorizare">Tip monitorizare</label>
                        <select class="form-select" id="rvTipMonitorizare">
                            <option value="individual">Vehicul individual</option>
                            <option value="ansamblu">Ansamblu</option>
                        </select>
                    </div>
                    <div class="<?= $withTrailers ? 'col-md-7' : 'col-12' ?>">
                        <label class="form-label" for="rvTargetRow" data-target-label>Ansamblu</label>
                        <select class="form-select" id="rvTargetRow"></select>
                    </div>
                </div>
                <div class="rv-config-components" data-config-items></div>
                <?php if ($tabHours): ?>
                    <fieldset class="rv-hours" data-hours-fieldset>
                        <legend>Ore motor eligibile <small>(setare globală, doar pentru cap tractor / camion)</small></legend>
                        <div class="row g-2">
                            <div class="col-sm-8">
                                <label class="form-label" for="rvHourSource">Sursa orelor</label>
                                <select class="form-select" id="rvHourSource" name="sursa_ore_motor">
                                    <?php foreach ($hourSources as $key => $label): ?>
                                        <option value="<?= e($key) ?>" <?= $key === $hourSource ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label" for="rvFactor">Factor conversie (km / oră)</label>
                                <input type="number" min="0" max="500" step="1" class="form-control" id="rvFactor" name="factor_km_ora" value="<?= e((string) (0 + $factor)) ?>" required>
                            </div>
                        </div>
                    </fieldset>
                <?php endif; ?>
                <p class="small text-muted mb-0 mt-2">„Km la ultima revizie” gol păstrează T0 actual. Pentru un T0 exact (data și ora montajului / reviziei) folosește „<?= e($recordVerb) ?>”.</p>
                <p class="small text-muted mb-0 mt-1">Cine primește emailul la atingerea pragului de notificare se setează în <a href="<?= e(build_query_url(['page' => 'notificari', 'action' => 'create'])) ?>">Notificări</a> (Tip entitate „Revizie (km)”).</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-floppy"></i> Salvează configurarea</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="rvRecordModal" tabindex="-1" aria-labelledby="rvRecordTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post" action="<?= e(build_query_url(['page' => 'revizii', 'action' => 'record'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="hidden" name="return" value="" data-record-return>
            <div class="modal-header">
                <h5 class="modal-title" id="rvRecordTitle"><?= e($recordVerb) ?> · <?= e($tabLabel) ?> · <span data-record-label></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <div class="mb-1 fw-semibold small">Componente</div>
                <p class="form-text mt-0 mb-2">Fiecare componentă are propriul T0: data și ora sunt obligatorii, iar ciclul nou pornește exact din acel minut.</p>
                <div data-record-components></div>
                <div class="mb-2 mt-3">
                    <label class="form-label" for="rvRecordNotes">Observații</label>
                    <textarea class="form-control" id="rvRecordNotes" name="observatii" rows="2" maxlength="500"></textarea>
                </div>
                <p class="small text-muted mb-0">Odometrul vehiculului nu se modifică, iar înregistrările anterioare rămân în istoric. „Km bord” e opțional și doar informativ.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                <button type="submit" class="btn btn-primary"><?= e($recordVerb) ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canSimulate):
    $simTab = $tab;
    $simDefaultInterval = $defaultInterval ?? 10000;
    require __DIR__ . '/_sim_modal.php';
endif; ?>

<script type="application/json" id="rvPageData"><?= $pageJson ?: '{}' ?></script>
<script src="<?= e(url('assets/js/revizii.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/revizii.js'))) ?>"></script>
<script src="<?= e(url('assets/js/revizii-common.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/revizii-common.js'))) ?>"></script>
