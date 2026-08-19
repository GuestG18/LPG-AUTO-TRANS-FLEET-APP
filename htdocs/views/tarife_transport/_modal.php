<?php
/**
 * Tariff edit dialog + module settings dialog.
 *
 * The "Valoare nouă" field is NEVER auto-submitted with the recommended value.
 * A recommendation can be applied with one click, but the administrator always
 * confirms the final number and the effective date.
 */
$todayIso = date('Y-m-d');
$tomorrowIso = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$nextMonthIso = (new DateTimeImmutable('first day of next month'))->format('Y-m-d');
?>

<div class="tt-modal-backdrop" id="tt-edit-modal" hidden>
    <div class="tt-modal" role="dialog" aria-modal="true" aria-labelledby="tt-edit-title">
        <form method="post" action="<?= e(build_query_url(['page' => 'tarife_transport', 'action' => 'store_version'])) ?>" id="tt-edit-form">
            <?= csrf_field() ?>
            <input type="hidden" name="beneficiar_id" value="<?= (int) $selectedBeneficiaryId ?>">
            <input type="hidden" name="component_key" id="tt-f-component">
            <input type="hidden" name="transport_type" id="tt-f-transport">
            <input type="hidden" name="route_ref_id" id="tt-f-route">

            <div class="tt-modal-head">
                <div>
                    <h5 id="tt-edit-title">Modifică tariful</h5>
                    <p id="tt-edit-subtitle">—</p>
                </div>
                <button type="button" class="tt-modal-close" data-tt-close aria-label="Închide">&times;</button>
            </div>

            <div class="tt-modal-body">
                <ul class="tt-meta-list" style="margin-bottom:14px;">
                    <li><span class="tt-meta-key">Beneficiar</span><span class="tt-meta-val"><?= e((string) ($beneficiary['nume'] ?? '—')) ?></span></li>
                    <li><span class="tt-meta-key">Tip transport</span><span class="tt-meta-val" id="tt-v-transport">—</span></li>
                    <li id="tt-row-route"><span class="tt-meta-key">Rută</span><span class="tt-meta-val" id="tt-v-route">—</span></li>
                    <li><span class="tt-meta-key">Componentă tarifară</span><span class="tt-meta-val" id="tt-v-component">—</span></li>
                    <li><span class="tt-meta-key">Unitate</span><span class="tt-meta-val" id="tt-v-unit">—</span></li>
                </ul>

                <?php if ($fuel !== null && ($fuel['current_price'] !== null || $fuel['reference_price'] !== null)): ?>
                    <div class="tt-fuel-context">
                        <h6><i class="bi bi-fuel-pump" aria-hidden="true"></i> Context combustibil (motorină)</h6>
                        <ul class="tt-meta-list">
                            <li><span class="tt-meta-key">Preț referință</span><span class="tt-meta-val"><?= $fuel['reference_price'] !== null ? e($money((float) $fuel['reference_price'], 4)) . ' lei/L' : '—' ?></span></li>
                            <li><span class="tt-meta-key">Media ponderată</span><span class="tt-meta-val"><?= $fuel['current_price'] !== null ? e($money((float) $fuel['current_price'], 4)) . ' lei/L' : '—' ?></span></li>
                            <li><span class="tt-meta-key">Variație</span><span class="tt-meta-val">
                                <?php if ($fuel['variation_percent'] === null): ?>—<?php else: ?>
                                    <?= (float) $fuel['variation_percent'] >= 0 ? '+' : '−' ?><?= e(format_number_ro(abs((float) $fuel['variation_percent']), 2)) ?> %
                                <?php endif; ?>
                            </span></li>
                            <li id="tt-row-recommended" hidden>
                                <span class="tt-meta-key">Tarif recomandat</span>
                                <span class="tt-meta-val" id="tt-v-recommended">—</span>
                            </li>
                        </ul>
                        <p style="margin:8px 0 0;font-size:11.5px;color:#4b6ea8;" id="tt-recommend-note">
                            Nu există o sensibilitate la combustibil configurată pentru această componentă,
                            deci nu se propune o valoare numerică.
                        </p>
                    </div>
                <?php endif; ?>

                <div class="tt-compare">
                    <div class="tt-compare-cell">
                        <span>ACTUAL</span>
                        <strong id="tt-v-current">—</strong>
                    </div>
                    <div class="tt-compare-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
                    <div class="tt-compare-cell is-new">
                        <span>NOU</span>
                        <strong id="tt-v-preview">—</strong>
                    </div>
                </div>

                <div class="tt-field-row">
                    <div class="tt-field">
                        <label for="tt-f-value">Valoare nouă <span style="color:#ef4444">*</span></label>
                        <input type="text" inputmode="decimal" name="new_value" id="tt-f-value" required autocomplete="off" placeholder="ex: 1,29">
                        <small id="tt-hint-unit">Se acceptă virgulă sau punct ca separator zecimal.</small>
                    </div>
                    <div class="tt-field">
                        <label for="tt-f-valid-from">Valabil de la <span style="color:#ef4444">*</span></label>
                        <input type="date" name="valid_from" id="tt-f-valid-from" required value="<?= e($todayIso) ?>" min="1900-01-01">
                        <small>
                            <button type="button" class="tt-btn tt-btn-sm" style="height:24px;padding:0 8px;" data-tt-date="<?= e($todayIso) ?>">Azi</button>
                            <button type="button" class="tt-btn tt-btn-sm" style="height:24px;padding:0 8px;" data-tt-date="<?= e($tomorrowIso) ?>">Mâine</button>
                            <button type="button" class="tt-btn tt-btn-sm" style="height:24px;padding:0 8px;" data-tt-date="<?= e($nextMonthIso) ?>">Luna viitoare</button>
                        </small>
                    </div>
                </div>

                <div class="tt-field">
                    <label for="tt-f-fuel-weight">Sensibilitate la combustibil (opțional)</label>
                    <input type="text" inputmode="decimal" name="fuel_weight" id="tt-f-fuel-weight" placeholder="0 = fără influență · 1 = expunere totală" autocomplete="off">
                    <small>
                        Se folosește <strong>doar</strong> pentru a propune o valoare la viitoarele recomandări.
                        Lasă gol dacă expunerea la motorină nu este stabilită comercial — atunci sistemul
                        va recomanda revizuirea fără să propună o cifră.
                    </small>
                </div>

                <div class="tt-field">
                    <label for="tt-f-reason">Motiv modificare</label>
                    <input type="text" name="reason" id="tt-f-reason" maxlength="255" placeholder="ex: renegociere contract, indexare combustibil" autocomplete="off">
                </div>

                <div class="tt-inline-alert is-info" style="margin:0;">
                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                    <div>
                        Se creează o <strong>versiune nouă</strong>. Versiunea curentă se închide cu o zi
                        înainte de data aleasă și rămâne în istoric.
                        <strong>Cursele deja salvate nu se modifică.</strong>
                    </div>
                </div>
            </div>

            <div class="tt-modal-foot">
                <button type="button" class="tt-btn" data-tt-close>Anulează</button>
                <button type="submit" class="tt-btn tt-btn-primary">Confirmă tariful</button>
            </div>
        </form>
    </div>
</div>

<?php if ($canManage): ?>
<div class="tt-modal-backdrop" id="tt-settings-modal" hidden>
    <div class="tt-modal" style="max-width:460px;" role="dialog" aria-modal="true" aria-labelledby="tt-settings-title">
        <form method="post" action="<?= e(build_query_url(['page' => 'tarife_transport', 'action' => 'save_settings'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="beneficiar_id" value="<?= (int) $selectedBeneficiaryId ?>">

            <div class="tt-modal-head">
                <div>
                    <h5 id="tt-settings-title">Prag de revizuire tarife</h5>
                    <p>Procentul de variație a motorinei de la care se recomandă revizuirea.</p>
                </div>
                <button type="button" class="tt-modal-close" data-tt-close aria-label="Închide">&times;</button>
            </div>

            <div class="tt-modal-body">
                <div class="tt-field">
                    <label for="tt-f-threshold">Prag (%)</label>
                    <input type="text" inputmode="decimal" name="fuel_review_threshold_percent" id="tt-f-threshold"
                           value="<?= e($thresholdPercent !== null ? (string) $thresholdPercent : '') ?>"
                           placeholder="ex: 5" autocomplete="off">
                    <small>
                        Lasă gol pentru a dezactiva complet recomandările automate.
                        Nu se presupune nicio valoare implicită în producție.
                    </small>
                </div>

                <div class="tt-inline-alert is-info" style="margin:0;">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <div>Pragul afectează doar recomandările. Niciun tarif nu se modifică automat.</div>
                </div>
            </div>

            <div class="tt-modal-foot">
                <button type="button" class="tt-btn" data-tt-close>Anulează</button>
                <button type="submit" class="tt-btn tt-btn-primary">Salvează pragul</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
