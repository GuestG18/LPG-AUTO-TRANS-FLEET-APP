<?php
/**
 * Delete-version confirmation (mistake rollback).
 *
 * Shown when ?delete_version_id=... points to a version of the selected
 * beneficiary. Confirming deletes the version, re-opens its predecessor and
 * re-quotes the trips that were priced under it — all in one transaction.
 *
 * Expects: $deletePrompt {version, trip_count}, $selectedBeneficiaryId, $activeTab, $canManage
 */
$dvVersion = (array) $deletePrompt['version'];
$dvTripCount = (int) $deletePrompt['trip_count'];
$dvLabel = TransportTariffModel::componentLabel((string) $dvVersion['component_key']);
$dvUnit = (string) $dvVersion['unit'];
$dvFrom = (string) $dvVersion['valid_from'];
try {
    $dvFrom = (new DateTimeImmutable($dvFrom))->format('d.m.Y');
} catch (Throwable) {
    // keep raw value
}
$dvCancelUrl = build_query_url([
    'page' => 'tarife_transport',
    'beneficiar_id' => $selectedBeneficiaryId,
    'tab' => $activeTab,
]);
?>
<div class="tt-inline-alert is-danger" id="tt-delete-version-banner">
    <i class="bi bi-trash3" aria-hidden="true"></i>
    <div style="flex:1;">
        <strong>Ștergi versiunea #<?= (int) $dvVersion['id'] ?>?</strong>
        <?= e($dvLabel) ?> = <?= e(format_number_ro((float) $dvVersion['value'], 4)) ?> <?= e($dvUnit) ?>,
        valabilă de la <?= e($dvFrom) ?>.
        După ștergere, tariful anterior redevine valabil (versiunea precedentă sau configurarea de bază).
        <?php if ($dvTripCount > 0): ?>
            <br><strong><?= (int) $dvTripCount ?> curse</strong> au fost tarifate cu această versiune și vor fi
            <strong>readuse automat la tariful anterior</strong>, cu înregistrare în istoricul fiecărei curse.
        <?php else: ?>
            <br>Nicio cursă nu a fost tarifată cu această versiune — se șterge doar tariful.
        <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
        <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
            <a class="tt-btn tt-btn-sm" href="<?= e($dvCancelUrl) ?>">Renunță</a>
            <form method="post" action="<?= e(build_query_url(['page' => 'tarife_transport', 'action' => 'delete_version'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="tariff_version_id" value="<?= (int) $dvVersion['id'] ?>">
                <input type="hidden" name="beneficiar_id" value="<?= (int) $selectedBeneficiaryId ?>">
                <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                <button type="submit" class="tt-btn tt-btn-sm tt-btn-primary" style="background:#dc2626;border-color:#dc2626;">
                    Șterge versiunea<?= $dvTripCount > 0 ? ' și readu ' . (int) $dvTripCount . ' curse' : '' ?>
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>
