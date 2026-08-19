<?php
/**
 * Right sidebar: Rezumat beneficiar → Monitorizare motorină → Ultimele modificări.
 *
 * The summary deliberately does NOT reduce Distribuție / P+D / Compresor to one
 * misleading universal price — those are route- or component-based.
 */

$typeIcons = [
    'primar' => ['bi-signpost-2', 'tt-ic-blue'],
    'primar_tona' => ['bi-box-seam', 'tt-ic-green'],
    'distributie' => ['bi-diagram-2', 'tt-ic-amber'],
    'primar_distributie' => ['bi-diagram-3', 'tt-ic-purple'],
    'compresor' => ['bi-bar-chart-line', 'tt-ic-cyan'],
];

$fuelStatus = (string) ($fuel['status'] ?? 'NO_REFERENCE');
$fuelTone = TariffReviewService::statusTone($fuelStatus);
$fuelLabel = TariffReviewService::statusLabel($fuelStatus);
$variation = $fuel['variation_percent'] ?? null;
?>

<!-- ============ Rezumat beneficiar ============ -->
<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Rezumat beneficiar <small>(toate tipurile)</small></h2>
    </div>

    <?php foreach ($summary as $type => $row): ?>
        <?php [$icon, $tone] = $typeIcons[$type] ?? ['bi-tag', 'tt-ic-blue']; ?>
        <div class="tt-summary-row">
            <div class="tt-summary-icon <?= e($tone) ?>"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></div>
            <div class="tt-summary-text">
                <strong><?= e((string) $row['label']) ?></strong>
                <span><?= e((string) $row['detail']) ?></span>
            </div>
            <div class="tt-summary-value <?= $row['value'] === null ? 'is-muted' : '' ?>">
                <?php if (!$row['supported']): ?>
                    <span class="tt-badge tt-badge-muted">Neactivat</span>
                <?php elseif ($row['value'] !== null): ?>
                    <?= e($money((float) $row['value'], 2)) ?> <?= e((string) $row['unit']) ?>
                <?php else: ?>
                    <?= e((string) ($row['value_label'] ?? '—')) ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <a class="tt-card-footer-link" href="<?= e(build_query_url(['page' => 'tarife_transport', 'action' => 'istoric', 'beneficiar_id' => $selectedBeneficiaryId])) ?>">
        <i class="bi bi-clock-history" aria-hidden="true"></i> Vezi toate modificările recente
    </a>
</section>

<!-- ============ Monitorizare motorină ============ -->
<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Monitorizare motorină</h2>
        <span class="tt-badge tt-badge-<?= e($fuelTone) ?>"><?= e($fuelLabel) ?></span>
    </div>

    <div class="tt-card-body is-tight">
        <?php if ($fuel === null): ?>
            <div class="tt-empty">
                <i class="bi bi-fuel-pump" aria-hidden="true"></i>
                <p>Monitorizarea nu este disponibilă.</p>
            </div>
        <?php else: ?>
            <div class="tt-fuel-metrics">
                <div class="tt-fuel-metric">
                    <span>Preț referință</span>
                    <strong><?= $fuel['reference_price'] !== null ? e($money((float) $fuel['reference_price'], 4)) . ' lei/L' : '—' ?></strong>
                </div>
                <div class="tt-fuel-metric">
                    <span>Media ponderată curentă</span>
                    <strong><?= $fuel['current_price'] !== null ? e($money((float) $fuel['current_price'], 4)) . ' lei/L' : '—' ?></strong>
                </div>
                <div class="tt-fuel-metric is-variation">
                    <span>Variație</span>
                    <strong class="<?= $variation === null ? '' : ((float) $variation >= 0 ? 'is-up' : 'is-down') ?>">
                        <?php if ($variation === null): ?>
                            —
                        <?php else: ?>
                            <?= (float) $variation >= 0 ? '+' : '−' ?><?= e(format_number_ro(abs((float) $variation), 2)) ?> %
                        <?php endif; ?>
                    </strong>
                </div>
                <div class="tt-fuel-metric">
                    <span>Volum analizat</span>
                    <strong><?= e(format_number_ro((float) ($fuel['liters'] ?? 0), 0)) ?> L</strong>
                </div>
            </div>

            <div class="tt-fuel-rows">
                <ul class="tt-meta-list">
                    <li>
                        <span class="tt-meta-key">Perioada analizată</span>
                        <span class="tt-meta-val">
                            <?= e($dateRo($fuel['period_start'] ?? null)) ?> → <?= e($dateRo($fuel['period_end'] ?? null)) ?>
                        </span>
                    </li>
                    <li>
                        <span class="tt-meta-key">Observații (motorină)</span>
                        <span class="tt-meta-val"><?= (int) ($fuel['observations'] ?? 0) ?></span>
                    </li>
                    <li>
                        <span class="tt-meta-key">Ultima sincronizare CardOil</span>
                        <span class="tt-meta-val"><?= e($dateTimeRo($fuel['last_sync_at'] ?? null)) ?></span>
                    </li>
                    <li>
                        <span class="tt-meta-key">Prag revizuire</span>
                        <span class="tt-meta-val">
                            <?= $fuel['threshold_percent'] !== null
                                ? e(format_number_ro((float) $fuel['threshold_percent'], 2)) . ' %'
                                : '<span class="tt-dash">neconfigurat</span>' ?>
                        </span>
                    </li>
                </ul>
            </div>

            <?php if ($fuelStatus === 'DATA_STALE'): ?>
                <div class="tt-fuel-alert is-stale">
                    <i class="bi bi-wifi-off" aria-hidden="true"></i>
                    <div>
                        <strong>Date CardOil neactualizate.</strong>
                        Ultima sincronizare reușită: <?= e($dateTimeRo($fuel['last_sync_at'] ?? null)) ?>
                        (<?= (int) ($fuel['sync_age_days'] ?? 0) ?> zile).
                        Recomandarea comercială nu poate fi considerată curentă.
                    </div>
                </div>
            <?php elseif ($fuelStatus === 'INSUFFICIENT_DATA'): ?>
                <div class="tt-fuel-alert is-muted">
                    <i class="bi bi-database-exclamation" aria-hidden="true"></i>
                    <div>Nu există suficiente date CardOil pentru un calcul de încredere în perioada analizată.</div>
                </div>
            <?php elseif ($fuelStatus === 'NO_REFERENCE'): ?>
                <div class="tt-fuel-alert is-muted">
                    <i class="bi bi-bookmark-dash" aria-hidden="true"></i>
                    <div>
                        Niciun tarif activ nu are o referință de combustibil asociată.
                        Referința se creează automat la următoarea modificare de tarif.
                    </div>
                </div>
            <?php elseif ($fuelStatus === 'REVIEW_RECOMMENDED'): ?>
                <div class="tt-fuel-alert is-warn">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <div>
                        Variația depășește pragul configurat. Sistemul <strong>nu modifică</strong> niciun tarif —
                        decizia rămâne a administratorului.
                    </div>
                </div>
            <?php endif; ?>

            <p class="tt-price-note" style="margin-top:10px;">
                Index calculat volumetric: <code>Σ valoare / Σ litri</code>, exclusiv motorină din API
                (<?= (int) ($fuel['excluded_adblue'] ?? 0) ?> înreg. AdBlue și
                <?= (int) ($fuel['excluded_non_api'] ?? 0) ?> înreg. non-API excluse).
            </p>
        <?php endif; ?>
    </div>
</section>

<!-- ============ Ultimele modificări ============ -->
<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Ultimele modificări de tarife</h2>
    </div>

    <?php if ($history === []): ?>
        <div class="tt-empty">
            <i class="bi bi-inbox" aria-hidden="true"></i>
            <p>Nicio modificare înregistrată.</p>
            <small>Istoricul se populează la prima schimbare de tarif.</small>
        </div>
    <?php else: ?>
        <?php foreach ($history as $entry): ?>
            <?php
            $type = (string) $entry['transport_type'];
            [$icon, $tone] = $typeIcons[$type] ?? ['bi-tag', 'tt-ic-blue'];
            $old = $entry['old_value'] !== null ? (float) $entry['old_value'] : null;
            $new = $entry['new_value'] !== null ? (float) $entry['new_value'] : null;
            ?>
            <div class="tt-history-row">
                <div class="tt-summary-icon <?= e($tone) ?>"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></div>
                <div class="tt-history-body">
                    <div class="tt-history-title">
                        <strong>
                            <?= e(TransportTariffModel::componentLabel((string) $entry['component_key'])) ?>
                            <?= $entry['route_label'] ? '· ' . e((string) $entry['route_label']) : '' ?>
                        </strong>
                        <time><?= e($dateTimeRo((string) $entry['changed_at'])) ?></time>
                    </div>
                    <div class="tt-history-change">
                        <span class="tt-history-values">
                            <?= $old !== null ? e($money($old, 2)) : '—' ?> →
                            <strong><?= $new !== null ? e($money($new, 2)) : '—' ?></strong>
                            <?= e((string) $entry['unit']) ?>
                        </span>
                        <span class="tt-history-user"><?= e((string) ($entry['user_nume'] ?? $entry['changed_by_name'] ?? 'Sistem')) ?></span>
                    </div>
                    <?php if ((string) $entry['action'] === 'scheduled'): ?>
                        <span class="tt-badge tt-badge-info" style="margin-top:5px;">
                            Programat din <?= e($dateRo((string) $entry['effective_from'])) ?>
                        </span>
                    <?php elseif ((string) $entry['action'] === 'dismissed'): ?>
                        <span class="tt-badge tt-badge-muted" style="margin-top:5px;">Recomandare amânată</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <a class="tt-card-footer-link" href="<?= e(build_query_url(['page' => 'tarife_transport', 'action' => 'istoric', 'beneficiar_id' => $selectedBeneficiaryId])) ?>">
        Vezi istoricul complet <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </a>
</section>
