<?php
/**
 * Tab "Distribuție".
 *
 * VERIFIED SEMANTICS (ANALIZA_COMPONENTE_TARIFARE_TRANSPORT.md §6–§8)
 *   Pricing tuple: beneficiary + loading location + unloading zone + scope='distributie'
 *   (UNIQUE) — the vehicle is ELIGIBILITY only and can never carry a second rate.
 *
 *   MODE tona     → total = cantitate_incarcata × tarif_tona
 *   MODE km       → total = km_cursa × cost_extra_km
 *   MODE tona_km  → total = (cantitate_incarcata × tarif_tona) + (km_cursa × cost_extra_km)
 */

$modeLabels = ['tona' => 'Tonă', 'km' => 'Km', 'tona_km' => 'Tonă + Km'];

$hiddenBeneficiaryTon = (float) ($beneficiary['pret_distributie_tona'] ?? 0);
$hiddenBeneficiaryKm = (float) ($beneficiary['pret_distributie_km'] ?? 0);

// Detect rules whose effective rate would come from a dormant/hidden fallback.
$fallbackRoutes = [];
foreach ($distributionRoutes as $route) {
    $mode = (string) ($route['tarif_mod'] ?? 'tona_km');
    $usesTon = in_array($mode, ['tona', 'tona_km'], true);
    $usesKm = in_array($mode, ['km', 'tona_km'], true);
    $routeId = (int) $route['id'];

    $tonVersion = $activeVersion('tarif_tona', $routeId);
    $kmVersion = $activeVersion('cost_extra_km', $routeId);
    $tonValue = $tonVersion !== null ? (float) $tonVersion['value'] : (float) ($route['tarif_tona'] ?? 0);
    $kmValue = $kmVersion !== null ? (float) $kmVersion['value'] : (float) ($route['cost_extra_km'] ?? 0);

    if (($usesTon && $tonValue <= 0) || ($usesKm && $kmValue <= 0)) {
        $fallbackRoutes[] = (string) $route['loc_nume'] . ' → ' . (string) $route['zona_nume'];
    }
}
?>

<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Distribuție — <small>Tarife configurate pe rută</small></h2>
        <a class="tt-btn tt-btn-sm" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $selectedBeneficiaryId])) ?>">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Rută nouă
        </a>
    </div>

    <div class="tt-card-body is-tight">
        <div class="tt-hero-grid" style="grid-template-columns:minmax(0,1fr) minmax(0,1.3fr) minmax(0,1fr);">
            <div class="tt-panel">
                <h3 class="tt-panel-heading">Moduri de tarifare</h3>
                <div class="tt-mode-pills" style="margin-bottom:10px;">
                    <span class="tt-mode-pill">Tonă</span>
                    <span class="tt-mode-pill">Km</span>
                    <span class="tt-mode-pill">Tonă + Km</span>
                </div>
                <p class="tt-price-note">
                    Modul se alege pe fiecare rută și decide care dintre cele două rate se aplică.
                </p>
            </div>

            <div class="tt-panel">
                <h3 class="tt-panel-heading">Formule pe mod</h3>
                <ul class="tt-where" style="margin:0;">
                    <li><strong>Tonă:</strong> total = cantitate încărcată × tarif tonă</li>
                    <li><strong>Km:</strong> total = km cursă × tarif km</li>
                    <li><strong>Tonă + Km:</strong> total = (cantitate × tarif tonă) + (km × tarif km)</li>
                </ul>
            </div>

            <div class="tt-panel">
                <h3 class="tt-panel-heading">Alte informații</h3>
                <ul class="tt-meta-list">
                    <li><span class="tt-meta-key">Nivel tarif</span><span class="tt-meta-val">Rută</span></li>
                    <li><span class="tt-meta-key">Cheie unicitate</span><span class="tt-meta-val">Beneficiar + Loc + Zonă</span></li>
                    <li><span class="tt-meta-key">Depinde de vehicul</span><span class="tt-meta-val">Doar eligibilitate</span></li>
                    <li><span class="tt-meta-key">Rute configurate</span><span class="tt-meta-val"><?= count($distributionRoutes) ?></span></li>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Rute configurate pentru Distribuție</h2>
    </div>

    <div class="tt-table-wrap">
        <table class="tt-table">
            <thead>
                <tr>
                    <th>Loc încărcare</th>
                    <th>Zonă descărcare</th>
                    <th>Mod tarifare</th>
                    <th>Tarif tonă (lei/t)</th>
                    <th>Tarif km (lei/km)</th>
                    <th>Vehicule eligibile</th>
                    <th>Activ</th>
                    <th class="tt-col-actions">Acțiuni</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($distributionRoutes === []): ?>
                    <tr><td colspan="8" class="tt-empty-cell">
                        Nu există încă rute de Distribuție configurate pentru acest beneficiar.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($distributionRoutes as $route): ?>
                        <?php
                        $routeId = (int) $route['id'];
                        $mode = (string) ($route['tarif_mod'] ?? 'tona_km');
                        $usesTon = in_array($mode, ['tona', 'tona_km'], true);
                        $usesKm = in_array($mode, ['km', 'tona_km'], true);
                        $routeLabel = (string) $route['loc_nume'] . ' → ' . (string) $route['zona_nume'];

                        $tonVersion = $activeVersion('tarif_tona', $routeId);
                        $kmVersion = $activeVersion('cost_extra_km', $routeId);
                        $tonScheduled = $scheduledVersion('tarif_tona', $routeId);
                        $kmScheduled = $scheduledVersion('cost_extra_km', $routeId);

                        $tonValue = $tonVersion !== null ? (float) $tonVersion['value'] : (float) ($route['tarif_tona'] ?? 0);
                        $kmValue = $kmVersion !== null ? (float) $kmVersion['value'] : (float) ($route['cost_extra_km'] ?? 0);

                        $tonReview = $reviewFor($tonVersion);
                        $kmReview = $reviewFor($kmVersion);
                        ?>
                        <tr>
                            <td><?= e((string) $route['loc_nume']) ?></td>
                            <td><?= e((string) $route['zona_nume']) ?></td>
                            <td><span class="tt-mode-pill is-on"><?= e($modeLabels[$mode] ?? $mode) ?></span></td>

                            <td class="tt-num">
                                <?php if (!$usesTon): ?>
                                    <span class="tt-dash">–</span>
                                <?php else: ?>
                                    <?= e($money($tonValue, 2)) ?>
                                    <?php if ($tonValue <= 0): ?>
                                        <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;margin-left:4px;" title="Tarif 0 — calculul va folosi lanțul de fallback legacy"></i>
                                    <?php endif; ?>
                                    <?php if ($tonScheduled !== null): ?>
                                        <br><span class="tt-badge tt-badge-info" style="margin-top:3px;">→ <?= e($money((float) $tonScheduled['value'], 2)) ?> din <?= e($dateRo((string) $tonScheduled['valid_from'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($tonReview !== null && (string) $tonReview['status'] === 'REVIEW_RECOMMENDED'): ?>
                                        <br><span class="tt-badge tt-badge-warn" style="margin-top:3px;">Revizuire recomandată</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>

                            <td class="tt-num">
                                <?php if (!$usesKm): ?>
                                    <span class="tt-dash">–</span>
                                <?php else: ?>
                                    <?= e($money($kmValue, 2)) ?>
                                    <?php if ($kmValue <= 0): ?>
                                        <i class="bi bi-exclamation-triangle-fill" style="color:#d97706;margin-left:4px;" title="Tarif 0 — calculul va folosi lanțul de fallback legacy"></i>
                                    <?php endif; ?>
                                    <?php if ($kmScheduled !== null): ?>
                                        <br><span class="tt-badge tt-badge-info" style="margin-top:3px;">→ <?= e($money((float) $kmScheduled['value'], 2)) ?> din <?= e($dateRo((string) $kmScheduled['valid_from'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($kmReview !== null && (string) $kmReview['status'] === 'REVIEW_RECOMMENDED'): ?>
                                        <br><span class="tt-badge tt-badge-warn" style="margin-top:3px;">Revizuire recomandată</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>

                            <td><?= $vehicleChips($route['vehicle_ids'] ?? null) ?></td>
                            <td><i class="bi <?= !empty($route['activ']) ? 'bi-check-circle-fill tt-status-dot is-on' : 'bi-dash-circle tt-status-dot is-off' ?>" aria-hidden="true"></i></td>
                            <td class="tt-col-actions">
                                <span class="tt-actions">
                                    <?php if ($canManage && $usesTon): ?>
                                        <button type="button" class="tt-btn tt-btn-icon" title="Editează tarif tonă"
                                                data-tt-edit data-component="tarif_tona" data-transport="distributie"
                                                data-route-id="<?= $routeId ?>" data-label="Tarif tonă" data-unit="lei/tona"
                                                data-current="<?= e((string) $tonValue) ?>" data-context="<?= e($routeLabel) ?>">
                                            <i class="bi bi-box-seam" aria-hidden="true"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($canManage && $usesKm): ?>
                                        <button type="button" class="tt-btn tt-btn-icon" title="Editează tarif km"
                                                data-tt-edit data-component="cost_extra_km" data-transport="distributie"
                                                data-route-id="<?= $routeId ?>" data-label="Tarif km" data-unit="lei/km"
                                                data-current="<?= e((string) $kmValue) ?>" data-context="<?= e($routeLabel) ?>">
                                            <i class="bi bi-signpost" aria-hidden="true"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a class="tt-btn tt-btn-icon" title="Deschide în Configurare transport"
                                       href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $selectedBeneficiaryId, 'route_distributie_edit_id' => $routeId])) ?>">
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
            Vehiculele definesc <strong>eligibilitatea</strong> regulii. Cheia de unicitate
            (beneficiar + loc + zonă) face imposibile două tarife diferite pe aceeași rută.
        </span>
    </div>
</section>

<!-- ============ Fallback diagnostics ============ -->
<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Lanț de fallback pentru tarif <small>(dacă valoarea pe rută lipsește)</small></h2>
    </div>
    <div class="tt-card-body">
        <?php if ($fallbackRoutes !== []): ?>
            <div class="tt-inline-alert is-warn">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <div>
                    <strong>Atenție:</strong> tariful următoarelor rute provine dintr-un fallback legacy,
                    nu din regula de rută: <?= e(implode(', ', array_slice($fallbackRoutes, 0, 6))) ?><?= count($fallbackRoutes) > 6 ? '…' : '' ?>.
                    Sursele de fallback nu sunt editabile din nicio interfață.
                </div>
            </div>
        <?php endif; ?>

        <div class="tt-logic-flow">
            <div class="tt-logic-step">
                <h6>1. Regula de rută</h6>
                <p><span class="tt-badge tt-badge-ok">Activ</span></p>
                <code>configurare_rute_distributie</code>
            </div>
            <div class="tt-logic-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
            <div class="tt-logic-step">
                <h6>2. Zonă / Loc</h6>
                <p><span class="tt-badge tt-badge-muted">Dormant</span></p>
                <code>zona.tarif_distributie · loc.tarif</code>
            </div>
            <div class="tt-logic-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
            <div class="tt-logic-step">
                <h6>3. Tarif pe beneficiar</h6>
                <p>
                    <span class="tt-badge <?= ($hiddenBeneficiaryTon > 0 || $hiddenBeneficiaryKm > 0) ? 'tt-badge-warn' : 'tt-badge-muted' ?>">Ascuns</span>
                </p>
                <code>
                    pret_distributie_tona = <?= e($money($hiddenBeneficiaryTon, 2)) ?> ·
                    pret_distributie_km = <?= e($money($hiddenBeneficiaryKm, 2)) ?>
                </code>
            </div>
        </div>

        <?php if ($hiddenBeneficiaryKm > 0 || $hiddenBeneficiaryTon > 0): ?>
            <div class="tt-inline-alert is-warn" style="margin:14px 0 0;">
                <i class="bi bi-eye-slash-fill" aria-hidden="true"></i>
                <div>
                    Acest beneficiar are valori nenule pe câmpurile <strong>ascunse</strong>
                    de tarif distribuție. Ele nu apar în nicio interfață de configurare, dar
                    pot deveni active dacă o rută rămâne cu tariful 0 pe modul corespunzător.
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Despre Distribuție</h2>
    </div>
    <div class="tt-card-body">
        <div class="tt-about-grid">
            <ul class="tt-check-list">
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Tariful este definit pe rută (loc încărcare → zonă descărcare).</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Modul de tarifare decide dacă se aplică tona, km-ul sau ambele.</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Cantitățile provin din cursă (tonaj și km efectuați).</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Vehiculele definesc doar eligibilitatea regulii.</li>
            </ul>

            <div class="tt-negative-panel">
                <h6>Ce NU influențează tariful</h6>
                <ul class="tt-cross-list">
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Capacitatea vehiculului</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Marfa / Tip marfă</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Data cursei / Perioada</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Direcția rutei (A → B este aceeași cu B → A)</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Un tarif separat per vehicul (imposibil prin cheia de unicitate)</li>
                </ul>
            </div>
        </div>
    </div>
</section>
