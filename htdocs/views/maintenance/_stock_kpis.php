<?php
declare(strict_types=1);

/**
 * KPI Stoc piese (valoare, total, sub stoc minim, fără mișcare). Folosit de Mentenanță → Stoc
 * piese și de Tabloul de bord.
 * 
 * @var array $stockKpis  MaintenanceModel::getStockKpis()
 */
$currency = isset($currency) && is_callable($currency) ? $currency : static fn (float $value): string => format_number_ro($value, 2) . ' lei';
?>
<div class="maintenance-kpi-grid maintenance-stock-kpis">
    <?php foreach ([
        ['icon' => 'bi-cash-stack', 'tone' => 'green', 'title' => 'Valoare totală stoc', 'value' => $currency((float) ($stockKpis['valoare_totala'] ?? 0)), 'note' => 'valoare de achiziție'],
        ['icon' => 'bi-box-seam', 'tone' => 'blue', 'title' => 'Total piese', 'value' => (string) ((int) ($stockKpis['total_piese'] ?? 0)), 'note' => 'repere în catalog'],
        ['icon' => 'bi-exclamation-triangle', 'tone' => 'orange', 'title' => 'Piese sub stoc minim', 'value' => (string) ((int) ($stockKpis['sub_minim'] ?? 0)), 'note' => 'necesită aprovizionare'],
        ['icon' => 'bi-hourglass-split', 'tone' => 'purple', 'title' => 'Piese fără mișcare', 'value' => (string) ((int) ($stockKpis['fara_miscare'] ?? 0)), 'note' => 'în ultimele 180 zile'],
    ] as $kpi): ?><article class="maintenance-kpi-card tone-<?= e($kpi['tone']) ?>"><div class="maintenance-kpi-main"><span class="maintenance-kpi-icon"><i class="bi <?= e($kpi['icon']) ?>"></i></span><div><div class="maintenance-kpi-title"><?= e($kpi['title']) ?></div><div class="maintenance-kpi-value"><?= e($kpi['value']) ?></div><div class="maintenance-kpi-note"><?= e($kpi['note']) ?></div></div></div></article><?php endforeach; ?>
</div>
