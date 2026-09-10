<?php
/**
 * "Modifică tarif" — bulk price edit across ALL routes of a transport type.
 *
 * Each row shows the route, and per price column: the CURRENT value with its
 * validity period, plus a "new value" input. Blank inputs stay unchanged.
 * One shared "Valabil de la / până la" + reason applies to everything filled;
 * the save is all-or-nothing (store_versions_bulk with per-row route ids).
 *
 * Expects $routesModal:
 *   transport => transport_type key
 *   title     => modal title
 *   columns   => [['key' => component_key, 'label' => ..., 'unit' => ...], ...]
 *   rows      => [[
 *       'route_id' => int,
 *       'label'    => route label,
 *       'values'   => [component_key => ['current' => float, 'period' => string, 'enabled' => bool]],
 *   ], ...]
 * Plus page context: $selectedBeneficiaryId, $canManage, $money.
 */
if (empty($canManage) || empty($routesModal['rows'])) {
    return;
}
$rmTodayIso = date('Y-m-d');
$rmTomorrowIso = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$rmNextMonthIso = (new DateTimeImmutable('first day of next month'))->format('Y-m-d');
?>
<div class="tt-modal-backdrop" id="tt-routes-modal" hidden>
    <div class="tt-modal" style="max-width:820px;" role="dialog" aria-modal="true" aria-labelledby="tt-routes-title">
        <form method="post" action="<?= e(build_query_url(['page' => 'tarife_transport', 'action' => 'store_versions_bulk'])) ?>" id="tt-routes-form">
            <?= csrf_field() ?>
            <input type="hidden" name="beneficiar_id" value="<?= (int) $selectedBeneficiaryId ?>">
            <input type="hidden" name="transport_type" value="<?= e((string) $routesModal['transport']) ?>">
            <input type="hidden" name="route_ref_id" value="0">

            <div class="tt-modal-head">
                <div>
                    <h5 id="tt-routes-title"><?= e((string) $routesModal['title']) ?></h5>
                    <p>Completează prețul nou doar pe rutele pe care vrei să le modifici — restul rămân neschimbate.</p>
                </div>
                <button type="button" class="tt-modal-close" data-tt-close aria-label="Închide">&times;</button>
            </div>

            <div class="tt-modal-body">
                <div class="tt-table-wrap" style="margin-bottom:14px;max-height:340px;overflow-y:auto;">
                    <table class="tt-table">
                        <thead>
                            <tr>
                                <th>Rută</th>
                                <?php foreach ($routesModal['columns'] as $rmColumn): ?>
                                    <th><?= e((string) $rmColumn['label']) ?> <small style="font-weight:400;color:#64748b;">(<?= e((string) $rmColumn['unit']) ?>)</small></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($routesModal['rows'] as $rmRow): ?>
                                <tr>
                                    <td style="white-space:normal;min-width:150px;"><strong><?= e((string) $rmRow['label']) ?></strong></td>
                                    <?php foreach ($routesModal['columns'] as $rmColumn): ?>
                                        <?php $rmCell = $rmRow['values'][$rmColumn['key']] ?? null; ?>
                                        <td style="min-width:150px;">
                                            <?php if ($rmCell === null || empty($rmCell['enabled'])): ?>
                                                <span class="tt-dash">–</span>
                                            <?php else: ?>
                                                <div style="font-size:12px;color:#475569;margin-bottom:4px;">
                                                    Vechi: <strong style="color:#0f172a;"><?= e($money((float) $rmCell['current'], 2)) ?></strong>
                                                    <br><span style="font-size:11px;"><?= e((string) $rmCell['period']) ?></span>
                                                </div>
                                                <input type="hidden" name="bulk_component[]" value="<?= e((string) $rmColumn['key']) ?>">
                                                <input type="hidden" name="bulk_route[]" value="<?= (int) $rmRow['route_id'] ?>">
                                                <input type="text" inputmode="decimal" name="bulk_value[]"
                                                       autocomplete="off" placeholder="preț nou" style="width:110px;">
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="tt-field-row">
                    <div class="tt-field">
                        <label for="tt-rf-valid-from">Preț nou — de la <span style="color:#ef4444">*</span></label>
                        <input type="date" name="valid_from" id="tt-rf-valid-from" required value="<?= e($rmTodayIso) ?>" min="1900-01-01">
                        <small>
                            <button type="button" class="tt-btn tt-btn-sm" style="height:24px;padding:0 8px;" data-tt-routes-date="<?= e($rmTodayIso) ?>">Azi</button>
                            <button type="button" class="tt-btn tt-btn-sm" style="height:24px;padding:0 8px;" data-tt-routes-date="<?= e($rmTomorrowIso) ?>">Mâine</button>
                            <button type="button" class="tt-btn tt-btn-sm" style="height:24px;padding:0 8px;" data-tt-routes-date="<?= e($rmNextMonthIso) ?>">Luna viitoare</button>
                        </small>
                    </div>
                    <div class="tt-field">
                        <label for="tt-rf-valid-to">Până la (opțional)</label>
                        <input type="date" name="valid_to" id="tt-rf-valid-to" min="1900-01-01">
                        <small>Gol = nelimitat. Cu dată de sfârșit, după interval prețurile anterioare redevin active automat.</small>
                    </div>
                </div>

                <div class="tt-field">
                    <label for="tt-rf-reason">Motiv modificare</label>
                    <input type="text" name="reason" id="tt-rf-reason" maxlength="255" placeholder="ex: renegociere contract" autocomplete="off">
                </div>

                <div class="tt-inline-alert is-info" style="margin:0;">
                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                    <div>
                        Toate prețurile completate se salvează <strong>împreună, cu aceeași perioadă</strong>
                        (totul sau nimic). După salvare ți se va propune <strong>recalcularea curselor existente</strong>
                        de pe toate rutele modificate.
                    </div>
                </div>
            </div>

            <div class="tt-modal-foot">
                <button type="button" class="tt-btn" data-tt-close>Anulează</button>
                <button type="submit" class="tt-btn tt-btn-primary">Confirmă tarifele</button>
            </div>
        </form>
    </div>
</div>
