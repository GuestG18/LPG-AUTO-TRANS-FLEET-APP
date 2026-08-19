<?php
/**
 * Tab "P+D (Primar + Distribuție)".
 *
 * VERIFIED SEMANTICS (ANALIZA_COMPONENTE_TARIFARE_TRANSPORT.md §9)
 *   total_facturare = (cantitate_incarcata × tarif_tona) + (km_cursa × cost_extra_km)
 *
 *   The tariff mode is LOCKED to tona_km — no selector is offered, matching the
 *   engine (controller line 3002 forces it at save time).
 *   `km_tarifare` is a QUANTITY that pre-fills km_cursa; `km_totali` does NOT
 *   affect the invoice, only the derived cost/km indicators.
 */
?>

<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">P+D (Primar + Distribuție) — <small>Tarife configurate pe rută</small></h2>
        <a class="tt-btn tt-btn-sm" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $selectedBeneficiaryId])) ?>">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Rută nouă
        </a>
    </div>

    <div class="tt-card-body is-tight">
        <div class="tt-hero-grid" style="grid-template-columns:minmax(0,1fr) minmax(0,1.35fr) minmax(0,1fr);">
            <div class="tt-panel">
                <h3 class="tt-panel-heading">Mod tarifare</h3>
                <div class="tt-mode-pills" style="margin-bottom:10px;">
                    <span class="tt-mode-pill is-locked"><i class="bi bi-lock-fill" aria-hidden="true"></i> Tonă + Km (fix)</span>
                </div>
                <p class="tt-price-note">
                    Nu se poate schimba modul. P+D folosește întotdeauna ambele componente.
                </p>
            </div>

            <div class="tt-panel">
                <h3 class="tt-panel-heading">Formula de calcul</h3>
                <div class="tt-formula">
                    Total facturare <span class="tt-formula-op">=</span> (tone
                    <span class="tt-formula-op">×</span> tarif tonă)
                    <span class="tt-formula-op">+</span> (km cursă
                    <span class="tt-formula-op">×</span> tarif km)
                </div>
                <p class="tt-where-label">Unde:</p>
                <ul class="tt-where">
                    <li>tone = cantitatea încărcată în cursă</li>
                    <li>km cursă = km efectuați; dacă lipsesc, se preiau din <code>km_tarifare</code></li>
                    <li><strong>km totali nu intră în valoarea facturată</strong> (doar în indicatorii derivați)</li>
                    <li>cost / cursă (dacă este activ) înlocuiește complet ambele componente</li>
                </ul>
            </div>

            <div class="tt-panel">
                <h3 class="tt-panel-heading">Alte informații</h3>
                <ul class="tt-meta-list">
                    <li><span class="tt-meta-key">Nivel tarif</span><span class="tt-meta-val">Rută</span></li>
                    <li><span class="tt-meta-key">Cheie unicitate</span><span class="tt-meta-val">Beneficiar + Loc + Zonă</span></li>
                    <li><span class="tt-meta-key">Depinde de vehicul</span><span class="tt-meta-val">Doar eligibilitate</span></li>
                    <li><span class="tt-meta-key">Rute configurate</span><span class="tt-meta-val"><?= count($pdRoutes) ?></span></li>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Rute configurate pentru P+D</h2>
    </div>

    <div class="tt-table-wrap">
        <table class="tt-table">
            <thead>
                <tr>
                    <th>Loc încărcare</th>
                    <th>Zonă descărcare</th>
                    <th>Tarif tonă (lei/t)</th>
                    <th>Tarif km (lei/km)</th>
                    <th>Km tarifare (agreat)</th>
                    <th>Cost / cursă</th>
                    <th>Vehicule eligibile</th>
                    <th>Activ</th>
                    <th class="tt-col-actions">Acțiuni</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pdRoutes === []): ?>
                    <tr><td colspan="9" class="tt-empty-cell">
                        Nu există încă rute P+D configurate pentru acest beneficiar.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($pdRoutes as $route): ?>
                        <?php
                        $routeId = (int) $route['id'];
                        $routeLabel = (string) $route['loc_nume'] . ' → ' . (string) $route['zona_nume'];

                        $tonVersion = $activeVersion('tarif_tona', $routeId);
                        $kmVersion = $activeVersion('cost_extra_km', $routeId);
                        $costVersion = $activeVersion('cost_cursa', $routeId);
                        $tonScheduled = $scheduledVersion('tarif_tona', $routeId);
                        $kmScheduled = $scheduledVersion('cost_extra_km', $routeId);

                        $tonValue = $tonVersion !== null ? (float) $tonVersion['value'] : (float) ($route['tarif_tona'] ?? 0);
                        $kmValue = $kmVersion !== null ? (float) $kmVersion['value'] : (float) ($route['cost_extra_km'] ?? 0);
                        $costValue = $costVersion !== null ? (float) $costVersion['value'] : (float) ($route['cost_cursa'] ?? 0);
                        $overrideActive = !empty($route['aplica_cost_cursa']) && $costValue > 0;

                        $tonReview = $reviewFor($tonVersion);
                        $kmReview = $reviewFor($kmVersion);
                        ?>
                        <tr>
                            <td><?= e((string) $route['loc_nume']) ?></td>
                            <td><?= e((string) $route['zona_nume']) ?></td>

                            <td class="tt-num">
                                <?= e($money($tonValue, 2)) ?>
                                <?php if ($tonScheduled !== null): ?>
                                    <br><span class="tt-badge tt-badge-info" style="margin-top:3px;">→ <?= e($money((float) $tonScheduled['value'], 2)) ?> din <?= e($dateRo((string) $tonScheduled['valid_from'])) ?></span>
                                <?php endif; ?>
                                <?php if ($tonReview !== null && (string) $tonReview['status'] === 'REVIEW_RECOMMENDED'): ?>
                                    <br><span class="tt-badge tt-badge-warn" style="margin-top:3px;">Revizuire recomandată</span>
                                <?php endif; ?>
                            </td>

                            <td class="tt-num">
                                <?= e($money($kmValue, 2)) ?>
                                <?php if ($kmScheduled !== null): ?>
                                    <br><span class="tt-badge tt-badge-info" style="margin-top:3px;">→ <?= e($money((float) $kmScheduled['value'], 2)) ?> din <?= e($dateRo((string) $kmScheduled['valid_from'])) ?></span>
                                <?php endif; ?>
                                <?php if ($kmReview !== null && (string) $kmReview['status'] === 'REVIEW_RECOMMENDED'): ?>
                                    <br><span class="tt-badge tt-badge-warn" style="margin-top:3px;">Revizuire recomandată</span>
                                <?php endif; ?>
                            </td>

                            <td class="tt-num"><?= e(format_number_ro((float) $route['km_tarifare'], 2)) ?> km</td>

                            <td class="tt-num">
                                <?php if ($costValue > 0): ?>
                                    <?= e($money($costValue, 2)) ?> lei
                                    <?php if ($overrideActive): ?>
                                        <br><span class="tt-badge tt-badge-warn" style="margin-top:3px;">Înlocuiește calculul</span>
                                    <?php else: ?>
                                        <br><span class="tt-badge tt-badge-muted" style="margin-top:3px;">Inactiv</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="tt-dash">–</span>
                                <?php endif; ?>
                            </td>

                            <td><?= $vehicleChips($route['vehicle_ids'] ?? null) ?></td>
                            <td><i class="bi <?= !empty($route['activ']) ? 'bi-check-circle-fill tt-status-dot is-on' : 'bi-dash-circle tt-status-dot is-off' ?>" aria-hidden="true"></i></td>
                            <td class="tt-col-actions">
                                <span class="tt-actions">
                                    <?php if ($canManage): ?>
                                        <button type="button" class="tt-btn tt-btn-icon" title="Editează tarif tonă"
                                                data-tt-edit data-component="tarif_tona" data-transport="primar_distributie"
                                                data-route-id="<?= $routeId ?>" data-label="Tarif tonă" data-unit="lei/tona"
                                                data-current="<?= e((string) $tonValue) ?>" data-context="<?= e($routeLabel) ?>">
                                            <i class="bi bi-box-seam" aria-hidden="true"></i>
                                        </button>
                                        <button type="button" class="tt-btn tt-btn-icon" title="Editează tarif km"
                                                data-tt-edit data-component="cost_extra_km" data-transport="primar_distributie"
                                                data-route-id="<?= $routeId ?>" data-label="Tarif km" data-unit="lei/km"
                                                data-current="<?= e((string) $kmValue) ?>" data-context="<?= e($routeLabel) ?>">
                                            <i class="bi bi-signpost" aria-hidden="true"></i>
                                        </button>
                                        <button type="button" class="tt-btn tt-btn-icon" title="Editează cost / cursă"
                                                data-tt-edit data-component="cost_cursa" data-transport="primar_distributie"
                                                data-route-id="<?= $routeId ?>" data-label="Cost / cursă" data-unit="lei/cursa"
                                                data-current="<?= e((string) $costValue) ?>" data-context="<?= e($routeLabel) ?>">
                                            <i class="bi bi-cash-coin" aria-hidden="true"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a class="tt-btn tt-btn-icon" title="Deschide în Configurare transport"
                                       href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $selectedBeneficiaryId, 'route_primar_distributie_edit_id' => $routeId])) ?>">
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
            Când „Aplică cost cursă" este activ și costul &gt; 0, valoarea înlocuiește
            <strong>complet</strong> ambele componente (tonaj și km).
        </span>
    </div>
</section>

<section class="tt-card tt-logic">
    <div class="tt-card-head">
        <h2 class="tt-card-title tt-logic-title">Rezumat logic și surse (P+D)</h2>
    </div>
    <div class="tt-card-body">
        <div class="tt-logic-flow">
            <div class="tt-logic-step">
                <h6>Tarif tonă</h6>
                <p>Din rută</p>
                <code>configurare_rute_distributie.tarif_tona</code>
            </div>
            <div class="tt-logic-arrow"><i class="bi bi-plus-lg" aria-hidden="true"></i></div>
            <div class="tt-logic-step">
                <h6>Tarif km</h6>
                <p>Din rută</p>
                <code>configurare_rute_distributie.cost_extra_km</code>
            </div>
            <div class="tt-logic-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
            <div class="tt-logic-step">
                <h6>Override (opțional)</h6>
                <p>Cost / cursă</p>
                <code>configurare_rute_distributie.cost_cursa</code>
            </div>
            <div class="tt-logic-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
            <div class="tt-logic-step">
                <h6>Eligibilitate regulă</h6>
                <p>În funcție de vehicule</p>
                <code>configurare_rute_distributie.vehicle_ids</code>
            </div>
        </div>
    </div>
</section>

<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Despre P+D</h2>
    </div>
    <div class="tt-card-body">
        <div class="tt-about-grid">
            <ul class="tt-check-list">
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Tariful tonă și tariful km sunt definite pe fiecare rută.</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Ambele componente se aplică întotdeauna (mod fix Tonă + Km).</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Km tarifare (agreat) este o cantitate, nu un tarif.</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Costul / cursă, dacă este activ, înlocuiește complet ambele componente.</li>
            </ul>

            <div class="tt-negative-panel">
                <h6>Ce NU influențează tariful</h6>
                <ul class="tt-cross-list">
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Km totali (doar indicatori derivați)</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Capacitatea vehiculului</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Marfa / Tip marfă</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Data cursei / Perioada</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Direcția rutei (A → B este aceeași cu B → A)</li>
                </ul>
            </div>
        </div>
    </div>
</section>
