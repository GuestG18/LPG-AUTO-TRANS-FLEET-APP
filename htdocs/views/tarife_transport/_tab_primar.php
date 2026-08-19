<?php
/**
 * Tab "Primar km".
 *
 * VERIFIED SEMANTICS (ANALIZA_COMPONENTE_TARIFARE_TRANSPORT.md §4)
 *   - the rate `pret_km` is BENEFICIARY-level, never per route;
 *   - `km_tarifare` is a QUANTITY configured on the route, not a price;
 *   - `cost_cursa` + `aplica_cost_cursa` is a FULL REPLACEMENT of the calculation;
 *   - the vehicle selects rule eligibility / the km variant, never the rate.
 */

$componentKey = 'pret_km';
$active = $activeVersion($componentKey, null);
$scheduled = $scheduledVersion($componentKey, null);
$review = $reviewFor($active);

$currentRate = $active !== null ? (float) $active['value'] : (float) ($beneficiary['pret_km'] ?? 0);
$hasVersion = $active !== null;
?>

<?php if ($review !== null && (string) $review['status'] === 'REVIEW_RECOMMENDED'): ?>
    <?php include __DIR__ . '/_review_banner.php'; ?>
<?php endif; ?>

<!-- ============ Rate card ============ -->
<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Primar km — <small>Tariful este la nivel de beneficiar</small></h2>
        <?php if ($canManage): ?>
            <button type="button" class="tt-btn tt-btn-sm"
                    data-tt-edit
                    data-component="pret_km"
                    data-transport="primar"
                    data-route-id="0"
                    data-label="Preț / km"
                    data-unit="lei/km"
                    data-current="<?= e((string) $currentRate) ?>"
                    data-context="Tarif la nivel de beneficiar — se aplică tuturor rutelor Primar">
                <i class="bi bi-pencil" aria-hidden="true"></i> Editează prețul / km
            </button>
        <?php endif; ?>
    </div>

    <div class="tt-card-body">
        <div class="tt-hero-grid">

            <div class="tt-panel tt-price-panel">
                <div class="tt-price-head">
                    <div class="tt-price-icon"><i class="bi bi-signpost-2" aria-hidden="true"></i></div>
                    <div>
                        <p class="tt-price-label">Preț actual</p>
                        <div class="tt-price-value">
                            <?= e($money($currentRate, 2)) ?> <small>lei / km</small>
                        </div>
                    </div>
                </div>
                <p class="tt-price-note">Valabil pentru toate rutele Primar ale beneficiarului.</p>

                <?php if (!$hasVersion): ?>
                    <span class="tt-badge tt-badge-muted"><i class="bi bi-info-circle" aria-hidden="true"></i> Valoare din configurarea legacy</span>
                <?php else: ?>
                    <span class="tt-badge tt-badge-ok">Activ din <?= e($dateRo((string) $active['valid_from'])) ?></span>
                <?php endif; ?>

                <?php if ($scheduled !== null): ?>
                    <div class="tt-fuel-alert is-muted" style="margin-top:4px;">
                        <i class="bi bi-calendar-event" aria-hidden="true"></i>
                        <div>
                            <strong>Tarif programat:</strong>
                            <?= e($money((float) $scheduled['value'], 4)) ?> lei/km
                            de la <?= e($dateRo((string) $scheduled['valid_from'])) ?>.
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tt-panel">
                <h3 class="tt-panel-heading">Formula de calcul</h3>
                <div class="tt-formula">
                    Total facturare <span class="tt-formula-op">=</span> km tarifare
                    <span class="tt-formula-op">×</span> <?= e($money($currentRate, 2)) ?> lei/km
                </div>
                <p class="tt-where-label">Unde:</p>
                <ul class="tt-where">
                    <li>km tarifare = cantitatea stabilită pe rută (<code>configurare_rute_primar.km_tarifare</code>)</li>
                    <li>cost / cursă (dacă este activ) înlocuiește complet calculul de mai sus</li>
                </ul>
            </div>

            <div class="tt-panel">
                <h3 class="tt-panel-heading">Alte informații</h3>
                <ul class="tt-meta-list">
                    <li><span class="tt-meta-key">Unitate tarif</span><span class="tt-meta-val">lei / km</span></li>
                    <li><span class="tt-meta-key">Nivel tarif</span><span class="tt-meta-val">Beneficiar</span></li>
                    <li><span class="tt-meta-key">Depinde de vehicul</span><span class="tt-meta-val">Da (eligibilitate regulă)</span></li>
                    <li><span class="tt-meta-key">Depinde de rută</span><span class="tt-meta-val">Cantitatea (km tarifare)</span></li>
                    <li><span class="tt-meta-key">Versiune activă</span><span class="tt-meta-val"><?= $hasVersion ? '#' . (int) $active['id'] : '—' ?></span></li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ============ Routes ============ -->
<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Rute configurate pentru Primar km</h2>
        <a class="tt-btn tt-btn-sm" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $selectedBeneficiaryId])) ?>">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Rută nouă
        </a>
    </div>

    <div class="tt-table-wrap">
        <table class="tt-table">
            <thead>
                <tr>
                    <th>Loc încărcare</th>
                    <th>Zonă descărcare</th>
                    <th>Km tarifare (agreat)</th>
                    <th>Cost / cursă (override)</th>
                    <th>Aplică cost cursă</th>
                    <th>Vehicule eligibile</th>
                    <th>Activ</th>
                    <th class="tt-col-actions">Acțiuni</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($primaryRoutes === []): ?>
                    <tr><td colspan="8" class="tt-empty-cell">
                        Nu există încă rute Primar configurate pentru acest beneficiar.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($primaryRoutes as $route): ?>
                        <?php
                        $routeId = (int) $route['id'];
                        $overrideActive = $activeVersion('cost_cursa', $routeId);
                        $overrideValue = $overrideActive !== null
                            ? (float) $overrideActive['value']
                            : (float) ($route['cost_cursa'] ?? 0);
                        $applies = !empty($route['aplica_cost_cursa']) && $overrideValue > 0;
                        ?>
                        <tr>
                            <td><?= e((string) $route['loc_nume']) ?></td>
                            <td><?= e((string) $route['zona_nume']) ?></td>
                            <td class="tt-num">
                                <?php if (!empty($route['km_agreati_manual'])): ?>
                                    <span class="tt-badge tt-badge-muted">Manual în cursă</span>
                                <?php else: ?>
                                    <?= e(format_number_ro((float) $route['km_tarifare'], 2)) ?> km
                                <?php endif; ?>
                            </td>
                            <td class="tt-num">
                                <?= $overrideValue > 0 ? e($money($overrideValue, 2)) . ' lei' : '<span class="tt-dash">–</span>' ?>
                            </td>
                            <td>
                                <?= $applies
                                    ? '<span class="tt-yes">Da</span>'
                                    : '<span class="tt-no">Nu</span>' ?>
                            </td>
                            <td><?= $vehicleChips($route['vehicle_ids'] ?? null) ?></td>
                            <td>
                                <i class="bi <?= !empty($route['activ']) ? 'bi-check-circle-fill tt-status-dot is-on' : 'bi-dash-circle tt-status-dot is-off' ?>" aria-hidden="true"></i>
                            </td>
                            <td class="tt-col-actions">
                                <span class="tt-actions">
                                    <?php if ($canManage): ?>
                                        <button type="button" class="tt-btn tt-btn-icon"
                                                title="Editează cost / cursă"
                                                data-tt-edit
                                                data-component="cost_cursa"
                                                data-transport="primar"
                                                data-route-id="<?= $routeId ?>"
                                                data-label="Cost / cursă"
                                                data-unit="lei/cursa"
                                                data-current="<?= e((string) $overrideValue) ?>"
                                                data-context="<?= e((string) $route['loc_nume'] . ' → ' . (string) $route['zona_nume']) ?>">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a class="tt-btn tt-btn-icon" title="Deschide în Configurare transport"
                                       href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $selectedBeneficiaryId, 'route_primar_edit_id' => $routeId])) ?>">
                                        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                                    </a>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="tt-note-strip">
        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
        <span>
            Dacă este activ „Aplică cost cursă", valoarea de mai sus înlocuiește
            <strong>complet</strong> calculul: km tarifare × <?= e($money($currentRate, 2)) ?> lei/km.
        </span>
    </div>
</section>

<!-- ============ Logic summary ============ -->
<section class="tt-card tt-logic">
    <div class="tt-card-head">
        <h2 class="tt-card-title tt-logic-title">Rezumat logic și surse (pentru Primar km)</h2>
    </div>
    <div class="tt-card-body">
        <div class="tt-logic-flow">
            <div class="tt-logic-step">
                <h6>Rată (lei/km)</h6>
                <p>Din beneficiar</p>
                <code>beneficiar.pret_km</code>
            </div>
            <div class="tt-logic-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
            <div class="tt-logic-step">
                <h6>Cantitate (km tarifare)</h6>
                <p>Din rută</p>
                <code>configurare_rute_primar.km_tarifare</code>
            </div>
            <div class="tt-logic-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
            <div class="tt-logic-step">
                <h6>Override (opțional)</h6>
                <p>Cost / cursă</p>
                <code>configurare_rute_primar.cost_cursa</code>
            </div>
            <div class="tt-logic-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
            <div class="tt-logic-step">
                <h6>Eligibilitate regulă</h6>
                <p>În funcție de vehicule</p>
                <code>configurare_rute_primar.vehicle_ids</code>
            </div>
        </div>
    </div>
</section>

<!-- ============ About ============ -->
<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Despre Primar km</h2>
    </div>
    <div class="tt-card-body">
        <div class="tt-about-grid">
            <ul class="tt-check-list">
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Tariful (lei/km) este unic la nivel de beneficiar.</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Cantitatea care intră în factură (km tarifare) este stabilită pe fiecare rută.</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Vehiculele definesc doar eligibilitatea regulii, nu prețul.</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Costul / cursă, dacă este activ, înlocuiește complet calculul pe km.</li>
            </ul>

            <div class="tt-negative-panel">
                <h6>Ce NU influențează tariful</h6>
                <ul class="tt-cross-list">
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Capacitatea vehiculului</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Marfa / Tip marfă</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Data cursei / Perioada</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Direcția rutei (A → B este aceeași cu B → A)</li>
                </ul>
            </div>
        </div>
    </div>
</section>
