<?php
/**
 * Centru ajustare tarife combustibil & curs BNR — conținutul paginii (meniul și
 * antetul aplicației rămân cele existente). Referința vizuală: macheta „Ajustare tarife”.
 *
 * Indicatorii și KPI-urile se randează pe server; tabelul de simulare, detaliile,
 * graficele și fluxul de aplicare sunt desenate de assets/js/ajustare-tarife.js din
 * JSON-ul #atPageData.
 *
 * Așteaptă: $indicators, $simulation, $approvals, $baselines, $lastSaved, $series,
 * $beneficiaries, $canSync, $canConfigure, $canApply, $adjustBases,
 * $testMode, $testMonths, $testReferenceEnd, $realMonthly (modul test: ?test=1).
 */
$ind = $indicators;
$fx = $ind['fx'];
$diesel = $ind['diesel'];
$cmp = $ind['comparison'];
$settings = $ind['settings'];
$baseline = $ind['baseline'];
$summary = $simulation['summary'];

$num = static fn (?float $v, int $d = 2): string => $v === null ? '—' : number_format($v, $d, ',', '.');
$pct = static fn (?float $v, int $d = 2): string => $v === null ? '—' : (($v > 0 ? '+' : ($v < 0 ? '−' : '')) . number_format(abs($v), $d, ',', '.') . '%');
$tone = static fn (?float $v): string => $v === null ? 'muted' : ($v > 0.00001 ? 'up' : ($v < -0.00001 ? 'down' : 'flat'));
$dt = static function (?string $v, bool $time = true): string {
    if ($v === null || $v === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($v))->format($time ? 'd.m.Y, H:i' : 'd.m.Y');
    } catch (Throwable) {
        return $v;
    }
};

$days = ['Duminică', 'Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă'];
$months = ['', 'Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];
$todayLabel = $days[(int) date('w')] . ', ' . (int) date('j') . ' ' . $months[(int) date('n')] . ' ' . date('Y');
$lastUpdate = max((string) ($fx['last_success'] ?? ''), (string) ($diesel['last_success'] ?? '')) ?: null;

$monthsLabel = static fn (int $m): string => $m === 1 ? 'ultima lună' : ($m === 12 ? 'ultimele 12 luni' : 'ultimele ' . $m . ' luni');
$windowLabel = $dt($ind['window']['start'], false) . ' – ' . $dt($ind['window']['end'], false);

$statusMap = [
    'threshold_up' => ['Prag depășit', 'danger', 'Creștere peste prag — necesită analiză și decizie'],
    'threshold_down' => ['Prag depășit', 'success', 'Scădere peste prag — necesită analiză și decizie'],
    'within' => ['În limite', 'ok', 'Variația este sub pragul de ajustare'],
    'stale' => ['Date neactualizate', 'warn', 'Sincronizarea unei surse este întârziată'],
    'no_baseline' => ['Fără referință', 'muted', 'Salvează o referință pentru comparație'],
    'no_threshold' => ['Fără prag', 'muted', 'Configurează pragul de ajustare'],
    'insufficient' => ['Date insuficiente', 'muted', 'Prea puține observații în fereastră'],
    'unavailable' => ['Calcul indisponibil', 'muted', 'Lipsește cursul BNR sau prețul motorinei'],
];
[$statusLabel, $statusTone, $statusHint] = $statusMap[$ind['status']] ?? ['—', 'muted', ''];
if ($ind['status'] === 'stale' && in_array($ind['threshold_state'], ['up', 'down'], true)) {
    $statusHint = 'Prag atins, dar datele trebuie actualizate înainte de decizie';
}

$variation = $ind['variation_pct'];
$threshold = $settings['threshold'];

$pageJson = json_encode([
    'rules' => $simulation['rules'],
    'indicators' => [
        'window' => $ind['window'],
        'fx_avg' => $fx['avg'],
        'diesel_ron' => $diesel['avg_ron'],
        'diesel_eur' => $diesel['avg_eur'],
        'variation' => $variation,
        'threshold' => $threshold,
        'basis' => $settings['basis'],
        'status' => $ind['status'],
    ],
    'series' => $series,
    'approvals' => $approvals,
    'can' => ['sync' => $canSync, 'configure' => $canConfigure, 'apply' => $canApply],
    'token' => csrf_token(),
    'testMode' => $testMode,
    'testMonths' => $testMonths,
    'needsAutoSync' => $fx['current_date'] === null || $fx['age_days'] === null || $fx['age_days'] >= 1,
    'urls' => [
        'base' => build_query_url(['page' => 'ajustare_tarife']),
        'series' => build_query_url(['page' => 'ajustare_tarife', 'action' => 'series']),
        'auto_sync' => build_query_url(['page' => 'ajustare_tarife', 'action' => 'auto_sync']),
        'sync' => build_query_url(['page' => 'ajustare_tarife', 'action' => 'sync_bnr']),
        'snapshot' => build_query_url(['page' => 'ajustare_tarife', 'action' => 'save_snapshot']),
        'compare' => build_query_url(['page' => 'ajustare_tarife', 'action' => 'compare']),
        'approval' => build_query_url(['page' => 'ajustare_tarife', 'action' => 'approval']),
        'approvals' => build_query_url(['page' => 'ajustare_tarife', 'action' => 'approvals']),
        'compare_periods' => build_query_url(array_merge(['page' => 'ajustare_tarife', 'action' => 'compare_periods'], $testMode ? ['test' => '1'] : [])),
        'period_baseline' => build_query_url(['page' => 'ajustare_tarife', 'action' => 'save_period_baseline']),
        'apply' => build_query_url(['page' => 'ajustare_tarife', 'action' => 'apply']),
        'tarife' => build_query_url(['page' => 'tarife_transport']),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

$minEffective = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$defaultEffective = (new DateTimeImmutable('first day of next month'))->format('Y-m-d');
?>
<link rel="stylesheet" href="<?= e(url('assets/css/ajustare-tarife.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/ajustare-tarife.css'))) ?>">

<div class="at-page" id="atPage">
    <!-- 1. Antet, breadcrumb, sincronizare -->
    <div class="at-head">
        <div>
            <nav class="at-crumbs" aria-label="breadcrumb">
                <span>Combustibil</span><i class="bi bi-chevron-right" aria-hidden="true"></i><strong>Ajustare tarife</strong>
            </nav>
            <h1>Centru ajustare tarife combustibil &amp; curs BNR</h1>
            <p>Actualizare zilnică a indicatorilor și tarifelor simulate. Aplicare manuală a tarifelor contractuale.</p>
        </div>
        <div class="at-head-actions">
        <a class="at-test-toggle<?= $testMode ? ' is-on' : '' ?>" href="<?= e(build_query_url($testMode ? ['page' => 'ajustare_tarife'] : ['page' => 'ajustare_tarife', 'test' => '1'])) ?>"
           title="<?= $testMode ? 'Ieși din modul test și revino la datele reale' : 'Recalculează pagina cu prețuri de motorină introduse manual pe lună (doar simulare)' ?>">
            <i class="bi bi-<?= $testMode ? 'toggle-on' : 'toggle-off' ?>" aria-hidden="true"></i>Simulare test
        </a>
        <div class="at-syncbox">
            <div class="at-syncbox-date">
                <i class="bi bi-calendar3" aria-hidden="true"></i>
                <div>
                    <strong><?= e($todayLabel) ?></strong>
                    <span>Ultima actualizare: <?= e($dt($lastUpdate)) ?></span>
                </div>
            </div>
            <button type="button" class="at-syncbox-btn" data-at-sync <?= $canSync ? '' : 'disabled' ?>
                    title="<?= $canSync ? 'Sincronizează acum cursul BNR și recalculează simulările' : 'Nu ai dreptul de sincronizare manuală' ?>"
                    aria-label="Sincronizează cursul BNR">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            </button>
        </div>
        </div>
    </div>

    <?php if ($testMode): ?>
        <div class="at-alert at-alert-test"><i class="bi bi-sliders" aria-hidden="true"></i>
            <span><strong>Mod test.</strong> Motorina folosește prețurile introduse mai jos pe lună (lunile fără preț de test rămân cu media reală CardOil); cursul EUR/RON este cel real BNR.
            Nimic nu se salvează ca indicator, referință sau tarif, iar aplicarea tarifelor este dezactivată.
            <a href="<?= e(build_query_url(['page' => 'ajustare_tarife'])) ?>">Înapoi la datele reale</a></span></div>
    <?php endif; ?>

    <?php if ($fx['last_error'] !== null): ?>
        <div class="at-alert at-alert-warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span>Ultima sincronizare BNR a eșuat — se folosesc datele salvate anterior (curs publicat <?= e($dt($fx['current_date'], false)) ?>). <small><?= e($fx['last_error']) ?></small></span></div>
    <?php endif; ?>
    <?php if ($diesel['stale']): ?>
        <div class="at-alert at-alert-warn"><i class="bi bi-fuel-pump" aria-hidden="true"></i>
            <span>Prețul motorinei nu este actualizat: ultima sincronizare CardOil reușită <?= e($dt($diesel['last_success'])) ?>, ultima alimentare <?= e($dt($diesel['last'])) ?>.
            Simulările rămân informative; aplicarea este blocată până la actualizare (<a href="<?= e(build_query_url(['page' => 'carburanti'])) ?>">Carburanți → Sincronizare</a>).</span></div>
    <?php endif; ?>

    <!-- 2. Șapte KPI -->
    <div class="at-kpis">
        <div class="at-kpi">
            <span class="at-kpi-ico at-ico-blue"><i class="bi bi-currency-euro" aria-hidden="true"></i></span>
            <div class="at-kpi-body">
                <span class="at-kpi-label">Curs EUR/RON mediu <?= e($settings['months'] === 1 ? '1 lună' : $settings['months'] . ' luni') ?></span>
                <strong class="at-kpi-value" title="Media aritmetică a <?= (int) $fx['observations'] ?> cursuri BNR publicate între <?= e($windowLabel) ?>"><?= e($num($fx['avg'], 4)) ?></strong>
                <span class="at-kpi-sub"><?= (int) $fx['observations'] ?> observații BNR</span>
            </div>
        </div>
        <div class="at-kpi">
            <span class="at-kpi-ico at-ico-blue"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></span>
            <div class="at-kpi-body">
                <span class="at-kpi-label">Curs EUR/RON curent</span>
                <strong class="at-kpi-value at-kpi-value-sm"><?= e($num($fx['current'], 4)) ?></strong>
                <span class="at-kpi-delta at-<?= e($tone($fx['current_vs_avg_pct'])) ?>">
                    <i class="bi bi-arrow-<?= ($fx['current_vs_avg_pct'] ?? 0) >= 0 ? 'up' : 'down' ?>" aria-hidden="true"></i><?= e($pct($fx['current_vs_avg_pct'])) ?>
                </span>
                <span class="at-kpi-sub">vs medie · publicat <?= e($dt($fx['current_date'], false)) ?><?= $fx['stale'] ? ' · <b class="at-stale">vechi</b>' : '' ?></span>
            </div>
        </div>
        <div class="at-kpi">
            <span class="at-kpi-ico at-ico-orange"><i class="bi bi-fuel-pump" aria-hidden="true"></i></span>
            <div class="at-kpi-body">
                <span class="at-kpi-label">Motorină medie <?= e($settings['months'] === 1 ? '1 lună' : $settings['months'] . ' luni') ?></span>
                <strong class="at-kpi-value" title="<?= e($diesel['method']) ?> <?= (int) $diesel['observations'] ?> alimentări, <?= e($num($diesel['liters'], 0)) ?> l"><?= e($num($diesel['avg_ron'], 2)) ?></strong>
                <span class="at-kpi-sub">lei/l<?= $diesel['stale'] ? ' · <b class="at-stale">neactualizat</b>' : '' ?></span>
            </div>
        </div>
        <div class="at-kpi">
            <span class="at-kpi-ico at-ico-blue"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
            <div class="at-kpi-body">
                <span class="at-kpi-label">Motorină medie în euro</span>
                <strong class="at-kpi-value" title="<?= e($num($diesel['avg_ron'], 4)) ?> lei/l ÷ <?= e($num($fx['avg'], 4)) ?> = <?= e($num($diesel['avg_eur'], 4)) ?> €/l"><?= e($num($diesel['avg_eur'], 2)) ?></strong>
                <span class="at-kpi-sub">€/l</span>
            </div>
        </div>
        <div class="at-kpi">
            <span class="at-kpi-ico at-ico-<?= $tone($variation) === 'down' ? 'green' : 'red' ?>"><i class="bi bi-graph-<?= $tone($variation) === 'down' ? 'down' : 'up' ?>-arrow" aria-hidden="true"></i></span>
            <div class="at-kpi-body">
                <span class="at-kpi-label">Variație totală vs referință</span>
                <strong class="at-kpi-value at-<?= e($tone($variation)) ?>" title="<?= $variation !== null ? e('Valoare exactă: ' . number_format($variation, 6, ',', '.') . '%') : 'Fără referință salvată' ?>"><?= e($pct($variation)) ?></strong>
                <span class="at-kpi-sub"><?= $baseline !== null ? e(($settings['basis'] === 'diesel_ron' ? 'motorină lei' : 'motorină €') . ' · ref. ' . (($baseline['source'] ?? '') === 'test' ? 'test' : '#' . $baseline['id'])) : 'fără referință' ?></span>
            </div>
        </div>
        <div class="at-kpi">
            <span class="at-kpi-ico at-ico-blue"><i class="bi bi-gear" aria-hidden="true"></i></span>
            <div class="at-kpi-body">
                <span class="at-kpi-label">Prag ajustare</span>
                <strong class="at-kpi-value"><?= $threshold !== null ? '±' . e(rtrim(rtrim(number_format($threshold, 2, ',', ''), '0'), ',')) . '%' : '—' ?></strong>
                <span class="at-kpi-sub">declanșare analiză</span>
            </div>
        </div>
        <div class="at-kpi">
            <span class="at-kpi-ico at-ico-<?= $statusTone === 'danger' ? 'red' : ($statusTone === 'success' || $statusTone === 'ok' ? 'green' : ($statusTone === 'warn' ? 'orange' : 'gray')) ?>">
                <i class="bi bi-<?= in_array($statusTone, ['ok', 'success'], true) && $ind['status'] === 'within' ? 'check-circle' : 'exclamation-triangle' ?>" aria-hidden="true"></i>
            </span>
            <div class="at-kpi-body">
                <span class="at-kpi-label">Status</span>
                <span class="at-status-pill at-status-<?= e($statusTone) ?>"><?= e($statusLabel) ?></span>
                <span class="at-kpi-sub"><?= e($statusHint) ?></span>
            </div>
        </div>
    </div>

    <?php if ($testMode): ?>
        <?php
        $testInfo = $ind['test'] ?? [];
        $monthNames = ['', 'ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'noi', 'dec'];
        $monthLabel = static fn (string $ym): string => $monthNames[(int) substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
        $panelMonths = [];
        for ($i = 12; $i >= 0; $i--) {
            $panelMonths[] = (new DateTimeImmutable('first day of this month'))->modify('-' . $i . ' months')->format('Y-m');
        }
        // Tabelul de verificare: fiecare lună din fereastră, câte zile intră, ce preț s-a folosit.
        $breakdownTable = static function (array $rows, ?float $avg) use ($num, $dt, $monthLabel): string {
            if ($rows === []) {
                return '<div class="at-empty at-empty-box">—</div>';
            }
            $html = '<table class="at-table at-table-sm"><thead><tr><th>Lună</th><th>Interval</th><th class="num">Zile</th><th class="num">Preț folosit</th><th class="num">Preț × zile</th></tr></thead><tbody>';
            $sumDays = 0;
            $sum = 0.0;
            foreach ($rows as $row) {
                $value = $row['value'];
                $tag = $row['source'] === 'test' ? '<span class="at-tag-test">test</span>'
                    : ($row['source'] === 'real' ? '<span class="at-tag-real">real</span>' : '<span class="at-tag-prov">fără preț</span>');
                if ($value !== null) {
                    $sumDays += (int) $row['days'];
                    $sum += $value * (int) $row['days'];
                }
                $html .= '<tr><td>' . e($monthLabel((string) $row['month'])) . '</td><td>' . e($dt((string) $row['from'], false) . ' – ' . $dt((string) $row['to'], false)) . '</td>'
                    . '<td class="num">' . (int) $row['days'] . '</td><td class="num">' . e($num($value, 4)) . ' ' . $tag . '</td>'
                    . '<td class="num">' . ($value !== null ? e($num($value * (int) $row['days'], 4)) : '—') . '</td></tr>';
            }
            $html .= '</tbody><tfoot><tr><td colspan="2"><strong>Medie = Σ(preț × zile) ÷ Σ zile</strong></td><td class="num"><strong>' . $sumDays
                . '</strong></td><td class="num" colspan="2"><strong>' . e($num($sum, 4)) . ' ÷ ' . $sumDays . ' = ' . e($num($avg, 4)) . ' lei/l</strong></td></tr></tfoot></table>';

            return $html;
        };
        ?>
        <section class="at-card at-test-panel">
            <div class="at-card-head">
                <h2><i class="bi bi-sliders" aria-hidden="true"></i>Prețuri test motorină pe luni</h2>
                <span class="at-muted">Media ferestrei = media lunilor ponderată cu zilele din fereastră. Câmp gol = media reală CardOil a lunii.</span>
            </div>
            <div class="at-test-grid">
                <form method="post" action="<?= e(build_query_url(['page' => 'ajustare_tarife', 'action' => 'save_test_months'])) ?>">
                    <?= csrf_field() ?>
                    <fieldset <?= $canConfigure ? '' : 'disabled' ?>>
                        <table class="at-table at-table-sm">
                            <thead><tr><th>Lună</th><th class="num">Medie reală</th><th class="num">Preț test (lei/l)</th></tr></thead>
                            <tbody>
                            <?php foreach ($panelMonths as $ym): ?>
                                <?php $realRow = $realMonthly[$ym] ?? null; ?>
                                <tr>
                                    <td><?= e($monthLabel($ym)) ?></td>
                                    <td class="num"><?= $realRow !== null
                                        ? e($num($realRow['avg'], 4)) . '<small>' . e($num($realRow['liters'], 0)) . ' l · ' . (int) $realRow['count'] . ' alim.</small>'
                                        : '<span class="at-muted">fără date</span>' ?></td>
                                    <td class="num"><input type="text" inputmode="decimal" class="form-control form-control-sm at-test-input" name="months[<?= e($ym) ?>]"
                                        value="<?= isset($testMonths[$ym]) ? e(rtrim(rtrim(number_format($testMonths[$ym], 4, ',', ''), '0'), ',')) : '' ?>"
                                        placeholder="<?= $realRow !== null ? e($num($realRow['avg'], 2)) : '—' ?>" aria-label="Preț test <?= e($monthLabel($ym)) ?>"></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="at-test-ref">
                            <label for="atTestRef">Referința de test: fereastra se termină la</label>
                            <input type="date" id="atTestRef" name="reference_end" class="form-control form-control-sm" value="<?= e($testReferenceEnd) ?>">
                            <small class="at-muted">Gol = se folosește referința salvată (dacă există). Referința de test nu se salvează ca referință reală.</small>
                        </div>
                        <?php if ($canConfigure): ?>
                            <div class="at-test-actions">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-at-test-clear>Golește</button>
                                <button type="submit" class="btn btn-sm btn-primary">Salvează și recalculează</button>
                            </div>
                        <?php endif; ?>
                    </fieldset>
                </form>
                <div class="at-test-check">
                    <h3>Fereastra curentă · <?= e($windowLabel) ?></h3>
                    <?= $breakdownTable((array) ($testInfo['current_breakdown'] ?? []), $diesel['avg_ron']) ?>
                    <h3>Fereastra de referință<?= $baseline !== null ? ' · ' . e($dt((string) $baseline['window_start'], false) . ' – ' . $dt((string) $baseline['window_end'], false)) : '' ?>
                        <?= !empty($testInfo['reference_is_test']) ? '<span class="at-tag-test">test</span>' : ($baseline !== null ? '<span class="at-tag-real">referință salvată #' . (int) $baseline['id'] . '</span>' : '') ?></h3>
                    <?php if (!empty($testInfo['reference_is_test'])): ?>
                        <?= $breakdownTable((array) $testInfo['reference_breakdown'], (float) $baseline['diesel_ron']) ?>
                    <?php elseif ($baseline === null): ?>
                        <div class="at-empty at-empty-box">Alege data de referință de test (stânga) ca să vezi variația.</div>
                    <?php else: ?>
                        <p class="at-muted">Motorină referință: <?= e($num((float) $baseline['diesel_ron'], 4)) ?> lei/l (referința salvată, date reale).</p>
                    <?php endif; ?>
                    <?php if ($baseline !== null && $cmp !== null): ?>
                        <h3>Calcul variație</h3>
                        <ul class="at-test-steps">
                            <li>Motorină în EUR referință = <?= e($num((float) $baseline['diesel_ron'], 4)) ?> ÷ <?= e($num((float) $baseline['eur_ron'], 4)) ?> = <strong><?= e($num($cmp['p0'], 4)) ?> €/l</strong></li>
                            <li>Motorină în EUR curentă = <?= e($num($diesel['avg_ron'], 4)) ?> ÷ <?= e($num($fx['avg'], 4)) ?> = <strong><?= e($num($cmp['p1'], 4)) ?> €/l</strong></li>
                            <li>Motorină lei: (<?= e($num($diesel['avg_ron'], 4)) ?> ÷ <?= e($num((float) $baseline['diesel_ron'], 4)) ?> − 1) × 100 = <strong><?= e($pct($cmp['diesel_ron_pct'], 4)) ?></strong></li>
                            <li>Motorină EUR: (<?= e($num($cmp['p1'], 4)) ?> ÷ <?= e($num($cmp['p0'], 4)) ?> − 1) × 100 = <strong><?= e($pct($cmp['combined_pct'], 4)) ?></strong></li>
                            <li>Variație folosită (<?= $settings['basis'] === 'diesel_ron' ? 'motorină lei' : 'motorină EUR' ?>) = <strong><?= e($pct($variation, 4)) ?></strong>
                                → <?= in_array($ind['threshold_state'], ['up', 'down'], true)
                                    ? '<strong class="at-up">prag ±' . e($num($threshold, 2)) . '% atins</strong>'
                                    : 'sub pragul de ±' . e($num($threshold, 2)) . '%' ?></li>
                            <?php if (in_array($ind['threshold_state'], ['up', 'down'], true)): ?>
                                <li>Tarifele se modifică cu același procent: ex. 1,1800 × (1 <?= $variation < 0 ? '−' : '+' ?> <?= e($num(abs($variation), 4)) ?>%) = <strong><?= e($num(1.18 * (1 + $variation / 100), 4)) ?></strong> lei/km</li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php
    // Calculator comparație perioade: lunile disponibile (ultimele 13, cea mai nouă prima).
    $calcMonthNames = ['', 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];
    $calcMonths = [];
    for ($i = 0; $i <= 12; $i++) {
        $m = (new DateTimeImmutable('first day of this month'))->modify('-' . $i . ' months');
        $calcMonths[$m->format('Y-m')] = $calcMonthNames[(int) $m->format('n')] . ' ' . $m->format('Y');
    }
    $calcA = (new DateTimeImmutable('first day of this month'))->format('Y-m');
    $calcB = (new DateTimeImmutable('first day of this month'))->modify('-1 month')->format('Y-m');
    $calcSelect = static function (string $name, string $selected) use ($calcMonths): string {
        $html = '<select class="form-select form-select-sm" data-at-calc="' . e($name) . '">';
        foreach ($calcMonths as $value => $label) {
            $html .= '<option value="' . e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
        }

        return $html . '</select>';
    };
    ?>
    <section class="at-card at-calc">
        <div class="at-card-head">
            <h2><i class="bi bi-calculator" aria-hidden="true"></i>Calculator comparație perioade</h2>
            <span class="at-muted">Media motorinei dintr-o lună sau din 3 luni față de altă lună sau 3 luni · prag ±<?= e($num($threshold, 2)) ?>%<?= $testMode ? ' · <b class="at-tag-test">prețuri test</b>' : '' ?></span>
        </div>
        <div class="at-calc-form">
            <div class="at-calc-period">
                <label>Perioada comparată</label>
                <div class="at-calc-inputs">
                    <select class="form-select form-select-sm" data-at-calc="a_len"><option value="1" selected>1 lună</option><option value="3">3 luni</option></select>
                    <span>care se termină cu</span>
                    <?= $calcSelect('a', $calcA) ?>
                </div>
            </div>
            <div class="at-calc-vs">față de</div>
            <div class="at-calc-period">
                <label>Perioada de referință</label>
                <div class="at-calc-inputs">
                    <select class="form-select form-select-sm" data-at-calc="b_len"><option value="1">1 lună</option><option value="3" selected>3 luni</option></select>
                    <span>care se termină cu</span>
                    <?= $calcSelect('b', $calcB) ?>
                </div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" data-at-calc-run><i class="bi bi-calculator" aria-hidden="true"></i> Calculează</button>
        </div>
        <div class="at-calc-result" data-at-calc-result>
            <div class="at-empty at-empty-box">Alege perioadele și apasă „Calculează”. Exemplu: octombrie (1 lună) față de iulie – septembrie (3 luni).</div>
        </div>
    </section>

    <!-- 3–5. Setări + grafice -->
    <div class="at-row at-row-3">
        <section class="at-card at-settings">
            <h2><i class="bi bi-gear" aria-hidden="true"></i>Setări sursă &amp; logică</h2>
            <form method="post" action="<?= e(build_query_url(['page' => 'ajustare_tarife', 'action' => 'save_settings'])) ?>" id="atSettingsForm">
                <?= csrf_field() ?>
                <fieldset <?= $canConfigure ? '' : 'disabled' ?>>
                    <div class="at-field">
                        <label for="atSource">Sursă curs</label>
                        <select id="atSource" class="form-select form-select-sm" disabled><option>BNR XML</option></select>
                    </div>
                    <div class="at-field">
                        <label for="atMonths">Interval analiză</label>
                        <select id="atMonths" name="months" class="form-select form-select-sm">
                            <?php foreach ([1, 3, 6, 12] as $m): ?>
                                <option value="<?= $m ?>" <?= $settings['months'] === $m ? 'selected' : '' ?>><?= e(ucfirst($monthsLabel($m))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="at-field">
                        <label for="atUpdate">Actualizare</label>
                        <select id="atUpdate" class="form-select form-select-sm" disabled><option>Zilnic</option></select>
                    </div>
                    <div class="at-field">
                        <label>Ultima sincronizare</label>
                        <div class="at-field-static">
                            <span title="Curs BNR — ultima sincronizare reușită"><i class="bi bi-calendar3" aria-hidden="true"></i><?= e($dt($fx['last_success'])) ?> <em>BNR</em></span>
                            <span class="<?= $diesel['stale'] ? 'at-stale' : '' ?>" title="Motorină CardOil — ultima sincronizare reușită"><i class="bi bi-fuel-pump" aria-hidden="true"></i><?= e($dt($diesel['last_success'])) ?> <em>CardOil</em></span>
                        </div>
                    </div>
                    <hr>
                    <div class="at-field">
                        <label for="atBasis">Regula de simulare</label>
                        <select id="atBasis" name="basis" class="form-select form-select-sm" title="Indicatorul a cărui variație declanșează pragul și ajustează tariful">
                            <option value="diesel_eur" <?= $settings['basis'] === 'diesel_eur' ? 'selected' : '' ?>>Tarife simulate · motorină în EUR</option>
                            <option value="diesel_ron" <?= $settings['basis'] === 'diesel_ron' ? 'selected' : '' ?>>Tarife simulate · motorină în lei</option>
                        </select>
                    </div>
                    <div class="at-field">
                        <label for="atThreshold">Prag ajustare (±%)</label>
                        <input id="atThreshold" name="threshold" class="form-control form-control-sm" inputmode="decimal" value="<?= $threshold !== null ? e(rtrim(rtrim(number_format($threshold, 4, ',', ''), '0'), ',')) : '' ?>" placeholder="ex. 2">
                    </div>
                    <div class="at-field">
                        <label for="atApplyMode">Aplicare tarife contractuale</label>
                        <select id="atApplyMode" class="form-select form-select-sm" disabled><option>Manuală</option></select>
                    </div>
                    <div class="at-field">
                        <label>Referință</label>
                        <div class="at-field-static">
                            <?php if ($baseline !== null): ?>
                                <span title="<?= e($baseline['label']) ?>"><i class="bi bi-bookmark-check" aria-hidden="true"></i><?= ($baseline['source'] ?? '') === 'test' ? 'Test' : '#' . (int) $baseline['id'] ?> · <?= e($dt($baseline['window_start'], false)) ?> – <?= e($dt($baseline['window_end'], false)) ?></span>
                                <span><?= e($num((float) $baseline['diesel_ron'], 4)) ?> lei/l · <?= e($num((float) $baseline['eur_ron'], 4)) ?> · <?= e($num((float) $baseline['diesel_eur'], 4)) ?> €/l</span>
                            <?php else: ?>
                                <span class="at-stale"><i class="bi bi-bookmark-x" aria-hidden="true"></i>Nicio referință salvată</span>
                            <?php endif; ?>
                            <?php if ($canConfigure): ?><button type="button" class="at-link-btn" data-bs-toggle="modal" data-bs-target="#atBaselineModal">Referință nouă…</button><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($canConfigure): ?>
                        <div class="at-settings-actions"><button type="submit" class="btn btn-sm btn-outline-primary">Salvează setările</button></div>
                    <?php endif; ?>
                </fieldset>
            </form>
            <div class="at-note">
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                <span>În weekend și în zilele libere se folosesc doar observațiile publicate de BNR.</span>
            </div>
        </section>

        <section class="at-card at-chart-card">
            <div class="at-card-head">
                <h2><i class="bi bi-graph-up" aria-hidden="true"></i>Evoluție curs EUR/RON – <span data-at-period-label="fx"><?= e($monthsLabel(3)) ?></span></h2>
                <div class="at-periods" data-at-periods="fx" role="group" aria-label="Perioadă grafic curs">
                    <?php foreach (['1' => '1L', '3' => '3L', '6' => '6L', '12' => '1A'] as $m => $label): ?>
                        <button type="button" data-months="<?= $m ?>" class="<?= (int) $m === 3 ? 'active' : '' ?>"><?= $label ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="at-chart-wrap"><canvas id="atFxChart" aria-label="Evoluția cursului EUR/RON"></canvas><div class="at-chart-empty" hidden>Nu există cursuri BNR în perioadă.</div></div>
            <div class="at-legend"><span><i class="at-dot at-dot-blue"></i>Curs EUR/RON</span><span><i class="at-dash"></i>Medie fereastră activă</span></div>
        </section>

        <section class="at-card at-chart-card">
            <div class="at-card-head">
                <h2><i class="bi bi-fuel-pump" aria-hidden="true"></i>Evoluție motorină – lei vs euro</h2>
                <div class="at-periods" data-at-periods="diesel" role="group" aria-label="Perioadă grafic motorină">
                    <?php foreach (['1' => '1L', '3' => '3L', '6' => '6L', '12' => '1A'] as $m => $label): ?>
                        <button type="button" data-months="<?= $m ?>" class="<?= (int) $m === 3 ? 'active' : '' ?>"><?= $label ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="at-axis-labels"><span>Lei/l</span><span>€/l</span></div>
            <div class="at-chart-wrap"><canvas id="atDieselChart" aria-label="Evoluția prețului motorinei în lei și în euro"></canvas><div class="at-chart-empty" hidden>Nu există alimentări cu motorină în perioadă.</div></div>
            <div class="at-legend"><span><i class="at-dot at-dot-green"></i>Motorină (lei/l)</span><span><i class="at-dot at-dot-orange"></i>Motorină (€/l)</span><?= $testMode ? '<span><i class="at-dash at-dash-test"></i>Preț test (lei/l)</span>' : '' ?></div>
        </section>
    </div>

    <!-- 6–7. Tarife simulate + impact -->
    <div class="at-row at-row-sim">
        <section class="at-card at-table-card">
            <div class="at-card-head">
                <h2><i class="bi bi-truck" aria-hidden="true"></i>Tarife simulate pe tip transport</h2>
                <div class="at-filters">
                    <div class="at-periods" data-at-currency role="group" aria-label="Monedă afișată"
                         title="€ = tarif în lei ÷ cursul mediu EUR/RON BNR al ferestrei (<?= e($num($fx['avg'], 4)) ?>). Tarifele contractuale rămân în lei.">
                        <button type="button" data-cur="lei" class="active">lei</button>
                        <button type="button" data-cur="eur">€</button>
                    </div>
                    <select class="form-select form-select-sm" data-at-filter="beneficiar" aria-label="Filtru beneficiar">
                        <option value="">Toți beneficiarii</option>
                        <?php foreach ($beneficiaries as $name): ?><option value="<?= e($name) ?>"><?= e($name) ?></option><?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm" data-at-filter="tip" aria-label="Filtru tip transport">
                        <option value="">Toate tipurile</option>
                        <?php foreach (TransportTariffModel::TRANSPORT_TYPES as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="at-table" id="atSimTable">
                    <thead>
                        <tr>
                            <th>Tip transport</th>
                            <th>Unitate</th>
                            <th class="num">Tarif contractual actual</th>
                            <th class="num">Tarif simulat</th>
                            <th class="num">Diferență %</th>
                            <th class="ctr">Status</th>
                            <th class="ctr">Acțiune</th>
                        </tr>
                    </thead>
                    <tbody><tr><td colspan="7" class="at-empty">Se încarcă…</td></tr></tbody>
                </table>
            </div>
            <p class="at-table-foot">
                <span data-at-sim-foot></span>
                Tarifele contractuale sunt în lei; comutatorul lei | € arată echivalentul în euro la cursul mediu EUR/RON BNR al ferestrei (fără a schimba tariful). Gruparea e doar pentru afișare: fiecare tarif se simulează și se aplică separat, pe beneficiar și rută.
            </p>
        </section>

        <section class="at-card at-impact">
            <h2><i class="bi bi-bar-chart-line" aria-hidden="true"></i>Analiză impact</h2>
            <?php if ($cmp === null): ?>
                <div class="at-empty at-empty-box">Impactul se calculează după salvarea unei referințe (Setări → Referință nouă).</div>
            <?php else: ?>
                <?php $d = $cmp['diesel_ron_pct']; $f = $cmp['fx_only_pct']; $c = $cmp['combined_pct']; ?>
                <div class="at-impact-item at-impact-<?= e($tone($d)) ?>" title="(F1 / F0 − 1) × 100 — variația prețului motorinei în lei, fără efect de curs">
                    <i class="bi bi-fuel-pump" aria-hidden="true"></i>
                    <div><strong>Impact preț motorină</strong><span>Motorină: <?= e($num((float) $baseline['diesel_ron'], 2)) ?> → <?= e($num($diesel['avg_ron'], 2)) ?> lei/l</span></div>
                    <b><?= e($pct($d)) ?></b>
                </div>
                <div class="at-impact-item at-impact-<?= e($tone($f)) ?>" title="(E0 / E1 − 1) × 100 — efectul cursului asupra motorinei exprimate în EUR. Cursul EUR/RON a variat cu <?= e($pct($cmp['fx_rate_pct'])) ?>; un euro mai scump face motorina mai ieftină în EUR.">
                    <i class="bi bi-currency-euro" aria-hidden="true"></i>
                    <div><strong>Impact curs EUR</strong><span>Curs: <?= e($num((float) $baseline['eur_ron'], 4)) ?> → <?= e($num($fx['avg'], 4)) ?> (<?= e($pct($cmp['fx_rate_pct'])) ?>)</span></div>
                    <b><?= e($pct($f)) ?></b>
                </div>
                <div class="at-impact-item at-impact-total" title="((F1/E1) / (F0/E0) − 1) × 100 — efectul combinat, multiplicativ (nu suma simplă)">
                    <i class="bi bi-bar-chart-fill" aria-hidden="true"></i>
                    <div><strong>Impact total motorină în EUR</strong><span>Combinație efect motorină + curs EUR</span></div>
                    <b><?= e($pct($c)) ?></b>
                </div>
                <p class="at-impact-foot">
                    Atribuire aditivă (Shapley): motorină <?= e($pct($cmp['contrib_diesel_pct'])) ?> + curs <?= e($pct($cmp['contrib_fx_pct'])) ?> = <?= e($pct($c)) ?>.<br>
                    Ajustare tarife: <strong><?= in_array($ind['threshold_state'], ['up', 'down'], true) ? e($pct($variation)) : '0%' ?></strong>
                    <span class="at-tag-<?= in_array($ind['threshold_state'], ['up', 'down'], true) ? 'ok' : 'real' ?>"><?= in_array($ind['threshold_state'], ['up', 'down'], true) ? 'același procent ca motorina' : 'sub prag' ?></span>
                </p>
            <?php endif; ?>
        </section>
    </div>

    <!-- 8–9. Aplicare manuală + ultimele aprobări -->
    <div class="at-row at-row-apply">
        <section class="at-card at-apply">
            <h2><i class="bi bi-file-earmark-text" aria-hidden="true"></i>Aplicare manuală tarife contractuale</h2>
            <div class="at-apply-grid">
                <div>
                    <label for="atEffective">Dată intrare în vigoare</label>
                    <div class="at-input-icon"><i class="bi bi-calendar3" aria-hidden="true"></i>
                        <input type="date" id="atEffective" class="form-control" min="<?= e($minEffective) ?>" value="<?= e($defaultEffective) ?>" <?= $canApply ? '' : 'disabled' ?>>
                    </div>
                    <small class="at-muted" data-at-selection>—</small>
                </div>
                <div>
                    <label for="atNote">Notă (opțional)</label>
                    <textarea id="atNote" class="form-control" rows="2" maxlength="250" placeholder="Ex. Ajustare tarife conform analiză lunară <?= e($months[(int) date('n')] . ' ' . date('Y')) ?>…"></textarea>
                    <small class="at-counter"><span data-at-counter>0</span>/250</small>
                </div>
            </div>
            <div class="at-apply-actions">
                <button type="button" class="btn btn-primary" data-at-save-snapshot <?= $canSync && !$testMode ? '' : 'disabled' ?><?= $testMode ? ' title="Dezactivat în modul test"' : '' ?>><i class="bi bi-floppy" aria-hidden="true"></i>Salvează simulare</button>
                <button type="button" class="btn btn-outline-secondary" data-at-compare><i class="bi bi-graph-up" aria-hidden="true"></i>Compară cu ultima aprobare</button>
                <button type="button" class="btn btn-success" data-at-apply <?= $testMode ? 'disabled title="Dezactivat în modul test"' : ($canApply ? '' : 'disabled title="Nu ai dreptul de aplicare a tarifelor contractuale"') ?>><i class="bi bi-check-circle" aria-hidden="true"></i>Aplică tarife contractuale</button>
            </div>
            <?php if ($lastSaved !== null): ?>
                <small class="at-muted">Ultima simulare salvată: #<?= (int) $lastSaved['id'] ?> · <?= e($dt((string) $lastSaved['created_at'])) ?><?= $lastSaved['note'] ? ' · ' . e((string) $lastSaved['note']) : '' ?></small>
            <?php endif; ?>
        </section>

        <section class="at-card at-approvals">
            <div class="at-card-head">
                <h2><i class="bi bi-clock-history" aria-hidden="true"></i>Ultimele aprobări</h2>
                <button type="button" class="at-link-btn" data-at-all-approvals>Vezi toate</button>
            </div>
            <table class="at-table at-table-sm">
                <thead><tr><th>Dată</th><th>Tip</th><th class="num">Variație</th><th>Notă</th><th class="ctr">Acțiune</th></tr></thead>
                <tbody>
                <?php if ($approvals === []): ?>
                    <tr><td colspan="5" class="at-empty">Nicio aprobare încă. Tarifele contractuale nu au fost ajustate din acest modul.</td></tr>
                <?php endif; ?>
                <?php foreach ($approvals as $approval): ?>
                    <?php $avg = $approval['avg_change_percent'] !== null ? (float) $approval['avg_change_percent'] : null; ?>
                    <tr>
                        <td><?= e($dt((string) $approval['approved_at'], false)) ?></td>
                        <td><span class="at-badge-apply">Aplicare</span></td>
                        <td class="num at-<?= e($tone($avg)) ?>"><?= e($pct($avg)) ?></td>
                        <td class="at-ellipsis" title="<?= e((string) ($approval['note'] ?? '')) ?>"><?= e((string) ($approval['note'] ?: ((int) $approval['items_count'] . ' tarife, în vigoare ' . $dt((string) $approval['effective_from'], false)))) ?></td>
                        <td class="ctr"><button type="button" class="at-view-btn" data-at-approval="<?= (int) $approval['id'] ?>"><i class="bi bi-eye" aria-hidden="true"></i>Vezi</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>

    <!-- 10. Formule și explicații -->
    <section class="at-card at-formulas">
        <h2><i class="bi bi-info-circle" aria-hidden="true"></i>Formule și explicații</h2>
        <div class="at-formulas-grid">
            <div>
                <p><strong>Motorină în euro</strong> = Preț mediu motorină / Curs mediu EUR/RON</p>
                <small>Exemplu: <?= e($num($diesel['avg_ron'], 4)) ?> lei/l ÷ <?= e($num($fx['avg'], 4)) ?> = <?= e($num($diesel['avg_eur'], 4)) ?> €/l</small>
                <p><strong>Curs mediu</strong> = Σ Cursuri BNR / Număr observații</p>
                <small><?= (int) $fx['observations'] ?> cursuri publicate în <?= e($windowLabel) ?>; zilele fără publicare nu se completează.</small>
            </div>
            <div class="at-formulas-sep"></div>
            <div>
                <p><strong>Variație %</strong> = (Valoare actuală / Valoare referință − 1) × 100</p>
                <small>Exemplu: (<?= e($num($fx['current'], 4)) ?> / <?= e($num($fx['avg'], 4)) ?> − 1) × 100 = <?= e($pct($fx['current_vs_avg_pct'])) ?></small>
                <p><strong>Tarif nou</strong> = Tarif × (1 + Variație %)</p>
                <small>Doar când variația atinge pragul (±<?= e($num($threshold, 2)) ?>%); tariful se modifică cu același procent ca motorina.</small>
            </div>
            <div class="at-note at-note-box">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>Tarifele simulate sunt actualizate automat pe baza evoluției prețului motorinei și a cursului EUR/RON. Aplicarea tarifelor contractuale se face manual.</span>
            </div>
        </div>
        <details class="at-details">
            <summary>Mai multe explicații</summary>
            <ul>
                <li><b>Cursul mediu EUR/RON</b> folosește doar cursurile efectiv publicate de BNR în fereastra glisantă (<?= e($monthsLabel($settings['months'])) ?>, inclusiv capetele). În weekend și sărbători nu se inventează valori; „cursul curent” este ultimul curs publicat, cu data lui.</li>
                <li><b>Motorina medie</b> este media ponderată cu volumul a alimentărilor CardOil cu motorină (Σ valoare ÷ Σ litri, prețuri cu TVA, doar date API). Alimentările de test, AdBlue și benzina nu intră în calcul.</li>
                <li><b>Motorina în EUR depinde de două lucruri</b>: prețul în lei și cursul. Dacă EUR/RON crește și prețul în lei rămâne același, motorina exprimată în EUR scade. Efectele se compun multiplicativ: (1 + motorină) × (1 + curs) − 1, nu prin adunare.</li>
                <li><b>Pragul de ±<?= $threshold !== null ? e(rtrim(rtrim(number_format($threshold, 2, ',', ''), '0'), ',')) : '—' ?>%</b> este un declanșator de analiză, nu o majorare automată. Comparația se face pe valoarea exactă, nerotunjită, față de referința salvată (nu față de ziua precedentă), ca schimbările lente să nu se piardă.</li>
                <li><b>Tarifele simulate</b> se recalculează automat zilnic. <b>Tarifele contractuale</b> se modifică doar manual, de un utilizator autorizat, cu dată viitoare de intrare în vigoare: se creează o versiune nouă, cea veche rămâne în istoric, iar cursele și facturile existente nu se recalculează.</li>
                <li><b>Regula de ajustare</b>: când variația motorinei atinge pragul, tariful se modifică cu <b>același procent</b> (ex. motorina +3,45% → tariful +3,45%). Pragul este implicit 2% și se poate modifica din Setări.</li>
                <li><b>Calculatorul de perioade</b> compară orice lună sau 3 luni calendaristice cu altă lună sau 3 luni, cu aceleași formule; perioada de referință se poate salva ca referință implicită.</li>
            </ul>
        </details>
    </section>
</div>

<!-- Modal: detalii grup -->
<div class="modal fade at-modal" id="atDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" data-at-details-title>Detalii</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button></div>
            <div class="modal-body" data-at-details-body></div>
        </div>
    </div>
</div>

<!-- Modal: confirmare aplicare -->
<div class="modal fade at-modal" id="atApplyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Verificare înainte de aplicare</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button></div>
            <div class="modal-body" data-at-apply-body></div>
            <div class="modal-footer at-apply-confirm">
                <label class="form-check"><input type="checkbox" class="form-check-input" data-at-apply-default checked> <span class="form-check-label">Noua referință devine referința implicită</span></label>
                <label class="form-check"><input type="checkbox" class="form-check-input" data-at-apply-check> <span class="form-check-label">Am verificat valorile vechi și noi</span></label>
                <input type="text" class="form-control form-control-sm" data-at-apply-word placeholder="Scrie APLIC" style="width:120px" autocomplete="off">
                <button type="button" class="btn btn-success" data-at-apply-confirm disabled>Aplică tarifele</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: generic (comparare, aprobări) -->
<div class="modal fade at-modal" id="atInfoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" data-at-info-title></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button></div>
            <div class="modal-body" data-at-info-body></div>
        </div>
    </div>
</div>

<?php if ($canConfigure): ?>
<!-- Modal: referință nouă -->
<div class="modal fade at-modal" id="atBaselineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="<?= e(build_query_url(['page' => 'ajustare_tarife', 'action' => 'save_baseline'])) ?>">
            <?= csrf_field() ?>
            <div class="modal-header"><h5 class="modal-title">Referință nouă</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button></div>
            <div class="modal-body">
                <p class="at-muted">Referința îngheață media motorinei și a cursului pe fereastra de <?= e($monthsLabel($settings['months'])) ?> care se termină la data aleasă. Nu se modifică ulterior; o referință nouă nu o șterge pe cea veche.</p>
                <div class="mb-2"><label class="form-label" for="atBlEnd">Fereastra se termină la</label>
                    <input type="date" class="form-control" id="atBlEnd" name="window_end" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required></div>
                <div class="mb-2"><label class="form-label" for="atBlLabel">Denumire</label>
                    <input type="text" class="form-control" id="atBlLabel" name="label" maxlength="160" placeholder="ex. Referință tarife august 2026"></div>
                <div class="mb-2"><label class="form-label" for="atBlNote">Notă</label>
                    <input type="text" class="form-control" id="atBlNote" name="note" maxlength="255"></div>
                <label class="form-check"><input type="checkbox" class="form-check-input" name="make_default" value="1" checked> <span class="form-check-label">Folosește ca referință implicită (tarifele cu referință proprie o păstrează)</span></label>
                <?php if ($baselines !== []): ?>
                    <hr><h6>Referințe salvate</h6>
                    <table class="at-table at-table-sm"><thead><tr><th>#</th><th>Fereastră</th><th class="num">Motorină</th><th class="num">EUR/RON</th><th class="num">€/l</th></tr></thead><tbody>
                    <?php foreach ($baselines as $b): ?>
                        <tr><td><?= (int) $b['id'] ?><?= (int) $b['is_default'] === 1 ? ' <span class="at-tag-ok">implicită</span>' : '' ?><?= $b['source'] === 'approval' ? ' <span class="at-tag-prov">aprobare</span>' : '' ?></td>
                            <td><?= e($dt((string) $b['window_start'], false)) ?> – <?= e($dt((string) $b['window_end'], false)) ?></td>
                            <td class="num"><?= e($num((float) $b['diesel_ron'], 4)) ?></td><td class="num"><?= e($num((float) $b['eur_ron'], 4)) ?></td><td class="num"><?= e($num((float) $b['diesel_eur'], 4)) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button><button type="submit" class="btn btn-primary">Salvează referința</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="at-toast" data-at-toast hidden></div>

<script type="application/json" id="atPageData"><?= $pageJson ?: '{}' ?></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= e(url('assets/js/ajustare-tarife.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/ajustare-tarife.js'))) ?>"></script>
