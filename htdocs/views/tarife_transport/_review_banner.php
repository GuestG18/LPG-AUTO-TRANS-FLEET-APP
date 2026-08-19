<?php
/**
 * Fuel-driven review recommendation banner.
 *
 * The system NEVER changes a tariff by itself. This banner offers exactly two
 * administrator actions: defer, or open the edit dialog. A numeric recommended
 * value is shown only when the component carries an explicitly configured fuel
 * sensitivity (`fuel_weight`) — otherwise the recommendation stays qualitative.
 *
 * Expects: $review (array), $active (array), $canManage, $money, $activeTab
 */
$variation = $review['variation_percent'] !== null ? (float) $review['variation_percent'] : null;
$recommended = $review['recommended_value'] !== null ? (float) $review['recommended_value'] : null;
$unit = (string) ($active['unit'] ?? '');
?>
<div class="tt-review-banner">
    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true" style="color:#d97706;font-size:17px;"></i>
    <div class="tt-review-text">
        <strong>Revizuire recomandată.</strong>
        Prețul ponderat al motorinei
        <?= $variation !== null && $variation >= 0 ? 'a crescut' : 'a scăzut' ?>
        cu <strong><?= $variation !== null ? e(format_number_ro(abs($variation), 2)) : '—' ?>%</strong>
        față de referința tarifului activ
        (<?= e($money($review['reference_fuel_price'] !== null ? (float) $review['reference_fuel_price'] : null, 4)) ?> lei/L
        → <?= e($money($review['current_weighted_price'] !== null ? (float) $review['current_weighted_price'] : null, 4)) ?> lei/L).
        <?php if ($recommended !== null): ?>
            Tarif recomandat: <strong><?= e($money($recommended, 4)) ?> <?= e($unit) ?></strong>.
        <?php else: ?>
            <em>Nu există o sensibilitate la combustibil configurată pentru această componentă,
            deci nu se propune o valoare numerică — decizia rămâne integral a administratorului.</em>
        <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
        <div class="tt-review-actions">
            <form method="post" action="<?= e(build_query_url(['page' => 'tarife_transport', 'action' => 'dismiss_review'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="tariff_version_id" value="<?= (int) $active['id'] ?>">
                <input type="hidden" name="beneficiar_id" value="<?= (int) $selectedBeneficiaryId ?>">
                <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                <button type="submit" class="tt-btn tt-btn-sm">Ignoră momentan</button>
            </form>

            <button type="button" class="tt-btn tt-btn-sm tt-btn-primary"
                    data-tt-edit
                    data-component="<?= e((string) $active['component_key']) ?>"
                    data-transport="<?= e((string) $active['transport_type']) ?>"
                    data-route-id="<?= (int) $active['route_ref_id'] ?>"
                    data-label="<?= e(TransportTariffModel::componentLabel((string) $active['component_key'])) ?>"
                    data-unit="<?= e($unit) ?>"
                    data-current="<?= e((string) $active['value']) ?>"
                    <?= $recommended !== null ? 'data-recommended="' . e((string) $recommended) . '"' : '' ?>
                    data-context="Recomandare bazată pe variația motorinei">
                Modifică tariful
            </button>
        </div>
    <?php endif; ?>
</div>
