<?php
declare(strict_types=1);

/**
 * Cardurile KPI Carburanti (morph: Motorina, AdBlue, consum mediu x2, cost total) + cardurile
 * Vehicule grele / usoare / Neinregistrate. Folosit de pagina Carburanti si de Tabloul de bord;
 * analiza extinsa a cardurilor o face assets/js/fuel-kpi-morph.js (action=kpi_card_data).
 *
 * @var array $fuelData, $kpis, $filters (+ _kpi_context.php)
 * @var array $fuelVehicleGroups  vehiculele pe categorii (selectorul din analiza extinsa)
 * @var callable|null $fuelSplitUrl fn(string $class, bool $isActive): string — implicit: filtrul paginii
 */
$fuelSplitUrl = isset($fuelSplitUrl) && is_callable($fuelSplitUrl) ? $fuelSplitUrl : null;
?>
<?php
// -----------------------------------------------------------------
// Cardurile KPI morph (assets/js/fuel-kpi-morph.js): starea compacta e randata aici, din
// aceleasi $kpis ca pana acum; analiza extinsa se incarca lenes (action=kpi_card_data).
// -----------------------------------------------------------------
$fkmSparkData = is_array($fuelData['kpi_sparklines'] ?? null) ? $fuelData['kpi_sparklines'] : [];
$fkmSparkSvg = static function (string $field) use ($fkmSparkData): string {
    $values = array_map(static fn (array $bucket): ?float => isset($bucket[$field]) && $bucket[$field] !== null ? (float) $bucket[$field] : null, $fkmSparkData);
    $points = [];
    foreach ($values as $index => $value) {
        if ($value !== null) {
            $points[] = [$index, $value];
        }
    }
    if (count($points) < 2) {
        return '';
    }
    $numbers = array_column($points, 1);
    $min = min($numbers);
    $max = max($numbers);
    $span = $max - $min;
    $last = max(1, count($values) - 1);
    $coords = [];
    foreach ($points as [$index, $value]) {
        $x = round($index / $last * 120, 2);
        $y = $span > 0 ? round(30 - ($value - $min) / $span * 26, 2) : 17.0;
        $coords[] = $x . ',' . $y;
    }
    $line = 'M' . implode(' L', $coords);
    $area = $line . ' L' . explode(',', end($coords))[0] . ',32 L' . explode(',', $coords[0])[0] . ',32 Z';

    return '<svg viewBox="0 0 120 32" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
        . '<path class="fkm-spark-area" d="' . $area . '"/>'
        . '<path class="fkm-spark-line" d="' . $line . '"/></svg>';
};
// Aceeasi regula ca $changeBadge: scaderea e „buna" (verde), cresterea „rea" (rosu).
$fkmChange = static function (?float $value): string {
    if ($value === null) {
        return '<span class="fkm-change is-muted" data-fkm-shared="change" title="Fără date în perioada precedentă"><i class="bi bi-dash-lg" aria-hidden="true"></i> fără comparație</span>';
    }
    $isGood = $value <= 0;

    return '<span class="fkm-change ' . ($isGood ? 'is-good' : 'is-bad') . '" data-fkm-shared="change" title="Față de perioada precedentă de aceeași lungime">'
        . '<i class="bi ' . ($isGood ? 'bi-arrow-down' : 'bi-arrow-up') . '" aria-hidden="true"></i> '
        . e(format_number_ro(abs($value), 1)) . '%</span>';
};
$fkmCards = [
    ['key' => 'motorina', 'title' => 'Motorină consumată', 'icon' => 'bi-fuel-pump', 'accent' => 'green',
     'value' => $formatLiters((float) ($kpis['motorina_liters'] ?? 0)),
     'sub' => 'Cost total: ' . $formatCurrency((float) ($kpis['total_value'] ?? 0)),
     'change' => $kpis['changes']['motorina_liters'] ?? null, 'spark' => 'motorina_liters'],
    ['key' => 'adblue', 'title' => 'AdBlue consumat', 'icon' => 'bi-bag-fill', 'accent' => 'blue',
     'value' => $formatLiters((float) ($kpis['adblue_liters'] ?? 0)),
     'sub' => 'Raportat pe perioada selectată',
     'change' => $kpis['changes']['adblue_liters'] ?? null, 'spark' => 'adblue_liters'],
    ['key' => 'consum', 'title' => 'Consum mediu Motorină', 'icon' => 'bi-graph-up-arrow', 'accent' => 'amber',
     'value' => format_number_ro((float) ($kpis['motorina_avg_l100'] ?? 0), 2) . ' L/100 km',
     'sub' => $consumptionDetail !== '' ? $consumptionDetail : 'Pe perioada selectata',
     'change' => $kpis['changes']['motorina_avg_l100'] ?? null, 'spark' => 'l100'],
    ['key' => 'adblue_pct', 'title' => 'Consum mediu AdBlue', 'icon' => 'bi-droplet-fill', 'accent' => 'cyan',
     'value' => $formatPercent((float) ($kpis['adblue_percent'] ?? 0)),
     'sub' => 'Raportat la motorină',
     'change' => $kpis['changes']['adblue_percent'] ?? null, 'spark' => 'adblue_pct'],
    ['key' => 'cost', 'title' => 'Cost total carburant', 'icon' => 'bi-cash-coin', 'accent' => 'purple',
     'value' => $formatCurrency((float) ($kpis['total_value'] ?? 0)),
     'sub' => 'Toate tipurile de carburant',
     'change' => $kpis['changes']['total_value'] ?? null, 'spark' => 'value'],
];
$fkmVehicleList = [];
foreach (($fuelVehicleGroups ?? []) as $fkmGroup) {
    foreach ($fkmGroup['vehicles'] as $fkmVehicle) {
        $fkmPlate = trim((string) ($fkmVehicle['vehicle_registration'] ?? ''));
        if ($fkmPlate !== '') {
            $fkmVehicleList[] = [
                'plate' => $fkmPlate,
                'label' => trim(trim((string) ($fkmVehicle['marca'] ?? '')) . ' ' . trim((string) ($fkmVehicle['model'] ?? ''))),
                'group' => (string) $fkmGroup['label'],
            ];
        }
    }
}
$fkmConfig = [
    'endpoint' => url('index.php') . '?page=carburanti&action=kpi_card_data',
    'pageUrl' => url('index.php'),
    'global' => [
        'date_from' => (string) ($filters['date_from'] ?? ''),
        'date_to' => (string) ($filters['date_to'] ?? ''),
        'vehicles' => $selectedVehicles,
        'transport_group' => (string) ($filters['transport_group'] ?? ''),
        'fuel_type' => (string) ($filters['fuel_type'] ?? ''),
        'brand' => (string) ($filters['brand'] ?? ''),
        'vehicle_class' => (string) ($filters['vehicle_class'] ?? ''),
        'period_label' => $periodLabel,
    ],
    'vehicles' => $fkmVehicleList,
    'transportLabels' => ['primar' => 'Primar', 'distributie' => 'Distribuție', 'compresor' => 'Compresor', 'primar_distributie' => 'Primar + Distribuție', 'neasociat' => 'Neasociat'],
];
?>
<link rel="stylesheet" href="<?= e(url('assets/css/fuel-kpi-morph.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/fuel-kpi-morph.css'))) ?>">
<div class="fkm-grid" data-fkm-root aria-label="Indicatori carburant — deschide un card pentru analiză detaliată">
    <script type="application/json" data-fkm-config><?= json_encode($fkmConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php foreach ($fkmCards as $fkmCard): ?>
        <article class="fkm-card fkm-accent-<?= e($fkmCard['accent']) ?>" data-fkm-card="<?= e($fkmCard['key']) ?>">
            <div class="fkm-inner" data-fkm-inner>
                <header class="fkm-head">
                    <span class="fkm-icon" data-fkm-shared="icon"><i class="bi <?= e($fkmCard['icon']) ?>" aria-hidden="true"></i></span>
                    <div class="fkm-heading">
                        <h3 class="fkm-title" data-fkm-shared="title" id="fkm-title-<?= e($fkmCard['key']) ?>">
                            <button type="button" class="fkm-expand" data-fkm-expand aria-expanded="false" aria-controls="fkm-panel-<?= e($fkmCard['key']) ?>"><?= e($fkmCard['title']) ?></button>
                            <span class="fkm-context" data-fkm-context hidden></span>
                            <button type="button" class="fkm-info" data-fkm-info aria-label="Cum se calculează <?= e(mb_strtolower($fkmCard['title'])) ?>"><i class="bi bi-info-circle" aria-hidden="true"></i></button>
                        </h3>
                        <div class="fkm-value-row">
                            <strong class="fkm-value" data-fkm-shared="value" data-fkm-value><?= e($fkmCard['value']) ?></strong>
                            <span class="fkm-change-wrap" data-fkm-change-wrap><?= $fkmChange($fkmCard['change'] !== null ? (float) $fkmCard['change'] : null) ?><span class="fkm-change-note">față de perioada precedentă</span></span>
                        </div>
                        <small class="fkm-sub" data-fkm-shared="sub" data-fkm-sub title="<?= e($fkmCard['sub']) ?>"><?= e($fkmCard['sub']) ?></small>
                    </div>
                    <div class="fkm-tools">
                        <button type="button" class="fkm-tool" data-fkm-menu aria-haspopup="menu" aria-expanded="false" aria-label="Opțiuni <?= e(mb_strtolower($fkmCard['title'])) ?>"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                        <button type="button" class="fkm-tool fkm-collapse" data-fkm-collapse aria-label="Restrânge analiza" title="Restrânge (Esc)"><i class="bi bi-arrows-angle-contract" aria-hidden="true"></i></button>
                    </div>
                </header>
                <div class="fkm-spark" data-fkm-spark><?= $fkmSparkSvg($fkmCard['spark']) ?></div>
                <div class="fkm-panel" id="fkm-panel-<?= e($fkmCard['key']) ?>" data-fkm-panel role="region" aria-labelledby="fkm-title-<?= e($fkmCard['key']) ?>" hidden></div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php
// Cifrele cardurilor ignora filtrul de categorie (vezi getDashboardData),
// ca ambele carduri sa ramana comparabile si clicabile.
$split = is_array($kpis['split'] ?? null) ? $kpis['split'] : $kpis;
$fuelSplitTotal = (float) ($split['total_value'] ?? 0);
$activeVehicleClass = (string) ($filters['vehicle_class'] ?? '');
$fuelSplitGroups = [
    ['key' => 'grele', 'label' => 'Vehicule grele', 'hint' => 'Camioane, capete tractor', 'icon' => 'bi-truck', 'accent' => 'blue', 'value' => (float) ($split['heavy_value'] ?? 0), 'liters' => (float) ($split['heavy_liters'] ?? 0), 'fillups' => (int) ($split['heavy_fillups'] ?? 0)],
    ['key' => 'usoare', 'label' => 'Vehicule ușoare', 'hint' => 'Autoturisme, autoutilitare', 'icon' => 'bi-car-front', 'accent' => 'purple', 'value' => (float) ($split['light_value'] ?? 0), 'liters' => (float) ($split['light_liters'] ?? 0), 'fillups' => (int) ($split['light_fillups'] ?? 0)],
    ['key' => 'neinregistrate', 'label' => 'Neînregistrate', 'hint' => 'Carduri fără vehicul în flotă', 'icon' => 'bi-question-circle', 'accent' => 'amber', 'value' => (float) ($split['unregistered_value'] ?? 0), 'liters' => (float) ($split['unregistered_liters'] ?? 0), 'fillups' => (int) ($split['unregistered_fillups'] ?? 0)],
];
?>
<div class="fuel-split<?= $activeVehicleClass !== '' ? ' has-active' : '' ?>" aria-label="Cost carburant pe categorie de vehicul — clic pentru a filtra pagina">
    <?php foreach ($fuelSplitGroups as $group): ?>
        <?php
        $share = $fuelSplitTotal > 0 ? $group['value'] / $fuelSplitTotal * 100 : 0.0;
        $isActiveClass = $activeVehicleClass === $group['key'];
        // Clic pe cardul activ scoate filtrul; pe celalalt comuta categoria.
        $splitUrl = $fuelSplitUrl !== null
            ? $fuelSplitUrl($group['key'], $isActiveClass)
            : build_query_url(array_merge($_GET, ['page' => 'carburanti', 'vehicle_class' => $isActiveClass ? '' : $group['key']]));
        ?>
        <a class="fuel-split-card fuel-split-<?= e($group['accent']) ?><?= $isActiveClass ? ' is-active' : '' ?>"
           href="<?= e($splitUrl) ?>"
           aria-pressed="<?= $isActiveClass ? 'true' : 'false' ?>"
           title="<?= e($isActiveClass ? 'Arată toate vehiculele' : 'Filtrează pagina pe ' . mb_strtolower($group['label'])) ?>">
            <i class="bi <?= e($group['icon']) ?>" aria-hidden="true"></i>
            <div class="fuel-split-main">
                <span><?= e($group['label']) ?> <small><?= e($group['hint']) ?></small></span>
                <strong><?= e($formatCurrency($group['value'])) ?></strong>
                <small><?= e($formatLiters($group['liters'])) ?> · <?= e(format_number_ro((float) $group['fillups'], 0)) ?> alimentări</small>
            </div>
            <div class="fuel-split-share">
                <strong><?= e(format_number_ro($share, 1)) ?>%</strong>
                <small>din cost total</small>
                <div class="fuel-split-bar"><span style="width: <?= e(number_format(min(100.0, max(0.0, $share)), 1, '.', '')) ?>%"></span></div>
            </div>
            <?php if ($isActiveClass): ?>
                <span class="fuel-split-active-tag"><i class="bi bi-funnel-fill" aria-hidden="true"></i> Filtru activ · clic pentru toate</span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>
