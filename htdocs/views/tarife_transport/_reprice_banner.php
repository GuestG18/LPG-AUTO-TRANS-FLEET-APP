<?php
/**
 * Post-save recalculation preview + confirm.
 *
 * Shown right after a tariff version is saved (?reprice_version_id=...).
 * Lists how many existing trips would move to the new price and asks for an
 * explicit confirmation. Nothing is repriced until the operator confirms.
 *
 * Expects: $repricePreview (array from TariffRepriceService::preview),
 *          $selectedBeneficiaryId, $activeTab, $canManage
 */
$rpVersion = (array) $repricePreview['version'];
$rpRows = (array) $repricePreview['rows'];
$rpChanged = (int) $repricePreview['changed'];
$rpInvoiced = (int) $repricePreview['invoiced_changed'];
$rpUnchanged = (int) $repricePreview['unchanged'];
$rpSkipped = (int) $repricePreview['skipped'];
$rpOld = (float) $repricePreview['old_total'];
$rpNew = (float) $repricePreview['new_total'];
$rpDelta = $rpNew - $rpOld;
$rpLabel = TransportTariffModel::componentLabel((string) $rpVersion['component_key']);
$rpFrom = (string) $rpVersion['valid_from'];
try {
    $rpFrom = (new DateTimeImmutable($rpFrom))->format('d.m.Y');
} catch (Throwable) {
    // keep raw value
}
$rpDismissUrl = build_query_url([
    'page' => 'tarife_transport',
    'beneficiar_id' => $selectedBeneficiaryId,
    'tab' => $activeTab,
]);
?>
<div class="tt-inline-alert <?= $rpChanged > 0 ? 'is-info' : '' ?>" id="tt-reprice-banner">
    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
    <div style="flex:1;">
        <?php if ($rpChanged <= 0): ?>
            <strong>Nicio cursă existentă de recalculat.</strong>
            Noul tarif (<?= e($rpLabel) ?>, valabil de la <?= e($rpFrom) ?>) se va aplica automat curselor viitoare.
            <?php if ($rpUnchanged > 0): ?>
                <?= (int) $rpUnchanged ?> curse din perioadă au deja valoarea corectă.
            <?php endif; ?>
            <?php if ($rpSkipped > 0): ?>
                <?= (int) $rpSkipped ?> curse nu au putut fi evaluate.
            <?php endif; ?>
        <?php else: ?>
            <strong>Aplici noul tarif și curselor existente?</strong>
            <?= e($rpLabel) ?>, valabil de la <?= e($rpFrom) ?>:
            <strong><?= (int) $rpChanged ?> curse</strong> din perioadă ar trece la noua valoare
            (<?= e(format_number_ro($rpOld, 2)) ?> lei → <?= e(format_number_ro($rpNew, 2)) ?> lei,
            diferență <?= $rpDelta >= 0 ? '+' : '−' ?><?= e(format_number_ro(abs($rpDelta), 2)) ?> lei).
            <?php if ($rpInvoiced > 0): ?>
                <br><span style="color:#b45309;"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <strong><?= (int) $rpInvoiced ?> dintre ele sunt deja facturate</strong> — după recalculare,
                valorile din aplicație pot diferi de facturile emise.</span>
            <?php endif; ?>
            <?php if ($rpSkipped > 0): ?>
                <br><?= (int) $rpSkipped ?> curse vor fi sărite (tariful nu se poate rezolva pentru ele).
            <?php endif; ?>
            <details style="margin-top:6px;">
                <summary style="cursor:pointer;">Vezi cursele afectate (<?= count($rpRows) ?>)</summary>
                <div style="max-height:220px;overflow:auto;margin-top:6px;">
                    <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
                        <thead>
                            <tr style="text-align:left;">
                                <th style="padding:2px 8px 2px 0;">Data</th>
                                <th style="padding:2px 8px 2px 0;">Vehicul</th>
                                <th style="padding:2px 8px 2px 0;">Status</th>
                                <th style="padding:2px 8px 2px 0;text-align:right;">Vechi (lei)</th>
                                <th style="padding:2px 0;text-align:right;">Nou (lei)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rpRows as $rpRow): ?>
                                <tr>
                                    <td style="padding:2px 8px 2px 0;"><?= e((string) $rpRow['data_cursa']) ?></td>
                                    <td style="padding:2px 8px 2px 0;"><?= e((string) $rpRow['vehicul']) ?></td>
                                    <td style="padding:2px 8px 2px 0;"><?= e((string) $rpRow['status_facturare']) ?></td>
                                    <td style="padding:2px 8px 2px 0;text-align:right;"><?= e(format_number_ro((float) $rpRow['old_total'], 2)) ?></td>
                                    <td style="padding:2px 0;text-align:right;"><strong><?= e(format_number_ro((float) $rpRow['new_total'], 2)) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </div>

    <?php if ($canManage && $rpChanged > 0): ?>
        <div style="display:flex;gap:8px;align-items:center;flex-shrink:0;">
            <a class="tt-btn tt-btn-sm" href="<?= e($rpDismissUrl) ?>">Nu recalcula</a>
            <form method="post" action="<?= e(build_query_url(['page' => 'tarife_transport', 'action' => 'apply_reprice'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="tariff_version_id" value="<?= (int) $rpVersion['id'] ?>">
                <input type="hidden" name="beneficiar_id" value="<?= (int) $selectedBeneficiaryId ?>">
                <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                <button type="submit" class="tt-btn tt-btn-sm tt-btn-primary">
                    Recalculează <?= (int) $rpChanged ?> curse
                </button>
            </form>
        </div>
    <?php elseif ($rpChanged <= 0): ?>
        <a class="tt-btn tt-btn-sm" href="<?= e($rpDismissUrl) ?>" style="flex-shrink:0;">OK</a>
    <?php endif; ?>
</div>
