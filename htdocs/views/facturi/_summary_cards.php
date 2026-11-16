<?php
declare(strict_types=1);

/**
 * Cardurile de sumar Facturi (Total cu TVA, Asociate, De rezolvat, Respinse). Folosite de
 * pagina Facturi și de Tabloul de bord.
 * 
 * @var array    $summary    InvoiceModel::getSummary()
 * @var callable $money
 * @var callable $statusUrl  fn(string $status): string
 */
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card h-100 facturi-stat">
            <div class="card-body">
                <div class="facturi-stat-label">Total cu TVA</div>
                <div class="facturi-stat-value"><?= e($money($summary['total_cu_tva'] ?? 0)) ?></div>
                <div class="facturi-stat-sub"><?= (int) ($summary['count'] ?? 0) ?> facturi · suma exclude respinsele</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <a class="card h-100 facturi-stat text-decoration-none" href="<?= e($statusUrl('asociata')) ?>">
            <div class="card-body">
                <div class="facturi-stat-label">Asociate</div>
                <div class="facturi-stat-value text-success"><?= (int) ($summary['asociate'] ?? 0) ?></div>
                <div class="facturi-stat-sub">au cheltuiala pe cursă</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <a class="card h-100 facturi-stat text-decoration-none" href="<?= e($statusUrl('de_rezolvat')) ?>">
            <div class="card-body">
                <div class="facturi-stat-label">De rezolvat</div>
                <div class="facturi-stat-value text-warning"><?= (int) ($summary['de_verificat'] ?? 0) + (int) ($summary['neasociate'] ?? 0) + (int) ($summary['in_procesare'] ?? 0) ?></div>
                <div class="facturi-stat-sub">
                    <?= (int) ($summary['de_verificat'] ?? 0) ?> de verificat · <?= (int) ($summary['neasociate'] ?? 0) ?> fără cursă<?= (int) ($summary['in_procesare'] ?? 0) > 0 ? ' · ' . (int) $summary['in_procesare'] . ' în procesare' : '' ?>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <a class="card h-100 facturi-stat text-decoration-none" href="<?= e($statusUrl('respinsa')) ?>">
            <div class="card-body">
                <div class="facturi-stat-label">Respinse</div>
                <div class="facturi-stat-value text-secondary"><?= (int) ($summary['respinse'] ?? 0) ?></div>
                <div class="facturi-stat-sub">nu intră pe nicio cursă</div>
            </div>
        </a>
    </div>
</div>
