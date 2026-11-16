<?php
declare(strict_types=1);

/**
 * „Alerte consumabile & fluide” în Tablou de bord: cardul din tabul Consumabile
 * (views/maintenance/_consumabile_alerts.php). Închiderea manuală rămâne în pagina-sursă.
 *
 * @var array $cons  ConsumabileController::dashboardAlerts()
 */
$ov = (array) ($cons['overview'] ?? []);
$canOperate = false;
$consUrl = static fn (array $extra = []): string => build_query_url(array_merge(['page' => 'mentenanta', 'action' => 'stock', 'tab' => 'consumabile'], $extra));
$fmtDate = static fn (?string $value, bool $time = false): string => $value ? date($time ? 'd.m.Y H:i' : 'd.m.Y', (int) strtotime($value)) : '—';
?>
<div class="cons-module dash-cons-module">
    <?php require BASE_PATH . '/views/maintenance/_consumabile_alerts.php'; ?>
</div>
