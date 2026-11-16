<?php
declare(strict_types=1);

/**
 * Banda KPI Cheltuieli (Total / Administrative / Operaționale). Folosită de pagina Cheltuieli
 * și de Tabloul de bord.
 * 
 * @var array    $summary  ExpenseModel::getSummary()
 * @var callable $money
 */
$carburant = is_array($summary['carburant'] ?? null) ? $summary['carburant'] : [];
$carburantTotal = (float) ($carburant['total'] ?? 0);
$carburantCount = (int) ($carburant['count'] ?? 0);
?>
<div class="chx-kpi-band mb-3">
    <div class="chx-kpi">
        <div class="chx-kpi-icon is-blue"><i class="bi bi-clipboard-data" aria-hidden="true"></i></div>
        <div>
            <div class="chx-kpi-label">Total cheltuieli</div>
            <div class="chx-kpi-value"><?= e($money($summary['total'] ?? 0)) ?></div>
            <div class="chx-kpi-note">
                <?php if ($carburantTotal > 0): ?>
                    include <?= e($money($carburantTotal)) ?> carburant
                <?php else: ?>
                    în perioada selectată
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="chx-kpi">
        <div class="chx-kpi-icon is-green"><i class="bi bi-building" aria-hidden="true"></i></div>
        <div>
            <div class="chx-kpi-label">Administrative</div>
            <div class="chx-kpi-value"><?= e($money($summary['administrativa'] ?? 0)) ?></div>
            <div class="chx-kpi-note"><?= e(format_number_ro((float) ($summary['procent_administrativa'] ?? 0), 1)) ?>% din total</div>
        </div>
    </div>
    <div class="chx-kpi">
        <div class="chx-kpi-icon is-orange"><i class="bi bi-truck" aria-hidden="true"></i></div>
        <div>
            <div class="chx-kpi-label">Operaționale</div>
            <div class="chx-kpi-value"><?= e($money($summary['operationala'] ?? 0)) ?></div>
            <div class="chx-kpi-note">
                <?= e(format_number_ro((float) ($summary['procent_operationala'] ?? 0), 1)) ?>% din total
                <?php if ($carburantCount > 0): ?>
                    · <?= e((string) $carburantCount) ?> alimentări
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
