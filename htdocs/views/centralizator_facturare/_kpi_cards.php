<?php
declare(strict_types=1);

/**
 * Cardurile KPI din Centralizator facturare (km / curse / tone sau valoarea pe tip), cu a doua
 * fata (defalcarea pe tip de transport, la hover / focus) si comparatia cu luna anterioara.
 * Folosite de pagina Centralizator si de Tablou de bord.
 *
 * @var array $kpiCards  CentralizatorFacturareService::buildKpis()['cards']
 * (+ _kpi_helpers.php)
 */
?>
<?php foreach (array_values($kpiCards) as $index => $card): ?>
    <?php
    $cardUnit = (string) ($card['unit'] ?? '');
    $breakdown = (array) ($card['breakdown'] ?? []);
    ?>
    <article class="cf-card cf-kpi is-<?= e((string) ($card['theme'] ?? 'blue')) ?><?= $breakdown !== [] ? ' has-breakdown' : '' ?>"<?= $breakdown !== [] ? ' tabindex="0"' : '' ?>>
        <div class="cf-kpi-icon" aria-hidden="true"><i class="bi <?= e((string) ($card['icon'] ?? 'bi-bar-chart-fill')) ?>"></i></div>
        <div class="cf-kpi-summary">
            <span class="cf-kpi-title"><?= e((string) ($card['title'] ?? '-')) ?></span>
            <strong class="cf-kpi-value"><?= e($kpiValue($card)) ?></strong>
            <div class="cf-kpi-foot"><?= $comparisonMarkup($card['comparison'] ?? null) ?></div>
        </div>
        <?php if ($breakdown !== []): ?>
            <?php /* A doua fata a cardului: aceeasi dimensiune, afisata la hover / focus. */ ?>
            <ul class="cf-kpi-breakdown" role="note" aria-label="<?= e((string) ($card['title'] ?? '')) ?> pe tipuri de transport">
                <?php foreach ($breakdown as $item): ?>
                    <?php
                    $itemColor = (string) ($item['color'] ?? '#2f7df4');
                    $itemTitle = (string) ($item['label'] ?? '-') . ': ' . $fmtPercent($item['share_percent'] ?? 0) . ' din total'
                        . ($cardUnit !== 'curse' ? ', ' . $fmtSmart($item['trips'] ?? 0, 0) . ' curse' : '');
                    ?>
                    <li class="cf-kpi-bd-row" style="--dot: <?= e($itemColor) ?>" title="<?= e($itemTitle) ?>">
                        <span class="cf-transport-name"><span class="cf-dot"></span><?= e((string) ($item['label'] ?? '-')) ?></span>
                        <strong><?= e($kpiValue(['value' => $item['value'] ?? 0, 'unit' => $cardUnit])) ?></strong>
                        <span class="cf-kpi-bd-share"><?= e($fmtPercent($item['share_percent'] ?? 0)) ?></span>
                        <?= $breakdownChange($item['comparison'] ?? null) ?: '<span class="cf-kpi-bd-change"></span>' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
