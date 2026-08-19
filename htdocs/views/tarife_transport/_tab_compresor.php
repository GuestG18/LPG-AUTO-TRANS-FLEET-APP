<?php
/**
 * Tab "Compresor".
 *
 * VERIFIED SEMANTICS (ANALIZA_COMPONENTE_TARIFARE_TRANSPORT.md §10)
 *   FIVE independent, additive, beneficiary-level rates. Never one tariff.
 *
 *   total = ore_aspirare × pret_ora_aspirare
 *         + km_dislocare × pret_km_dislocare
 *         + tona_livrata × pret_tona_livrata
 *         + tona_aspirata_lichida × pret_tona_aspirata_lichida   (only if rate > 0)
 *         + tona_aspirata_gazoasa × pret_tona_aspirata_gazoasa   (only if rate > 0)
 *
 *   No route. No cost_cursa. Vehicle configuration is eligibility only.
 */

$components = [
    'pret_ora_aspirare' => [
        'label' => 'Oră aspirare', 'unit' => 'lei / oră', 'icon' => 'bi-clock-history', 'tone' => 'tt-ic-blue',
        'quantity' => 'ore_aspirare', 'note' => 'Timpul de aspirare efectiv din cursă',
    ],
    'pret_km_dislocare' => [
        'label' => 'Km dislocare', 'unit' => 'lei / km', 'icon' => 'bi-signpost-split', 'tone' => 'tt-ic-green',
        'quantity' => 'km_dislocare', 'note' => 'Km parcurși pentru deplasarea utilajului',
    ],
    'pret_tona_livrata' => [
        'label' => 'Tonă livrată', 'unit' => 'lei / tonă', 'icon' => 'bi-box-seam', 'tone' => 'tt-ic-amber',
        'quantity' => 'tona_livrata', 'note' => 'Cantitatea livrată la client',
    ],
    'pret_tona_aspirata_lichida' => [
        'label' => 'Tonă aspirată lichidă', 'unit' => 'lei / tonă', 'icon' => 'bi-droplet-fill', 'tone' => 'tt-ic-purple',
        'quantity' => 'tona_aspirata_lichida', 'note' => 'Se aplică doar dacă tariful este > 0 (altfel câmpul se ascunde în cursă)',
    ],
    'pret_tona_aspirata_gazoasa' => [
        'label' => 'Tonă aspirată gazoasă', 'unit' => 'lei / tonă', 'icon' => 'bi-wind', 'tone' => 'tt-ic-cyan',
        'quantity' => 'tona_aspirata_gazoasa', 'note' => 'Se aplică doar dacă tariful este > 0 (altfel câmpul se ascunde în cursă)',
    ],
];

$activeCount = 0;
$resolved = [];
foreach ($components as $key => $meta) {
    $version = $activeVersion($key, null);
    $value = $version !== null ? (float) $version['value'] : (float) ($beneficiary[$key] ?? 0);
    $resolved[$key] = [
        'meta' => $meta,
        'version' => $version,
        'scheduled' => $scheduledVersion($key, null),
        'review' => $reviewFor($version),
        'value' => $value,
        'used' => $value > 0,
    ];
    if ($value > 0) {
        $activeCount++;
    }
}
?>

<?php foreach ($resolved as $key => $entry): ?>
    <?php if ($entry['review'] !== null && (string) $entry['review']['status'] === 'REVIEW_RECOMMENDED' && $entry['version'] !== null): ?>
        <?php $review = $entry['review']; $active = $entry['version']; include __DIR__ . '/_review_banner.php'; ?>
    <?php endif; ?>
<?php endforeach; ?>

<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Structură — <small>Tarife Compresor (componente independente)</small></h2>
        <span class="tt-badge <?= $activeCount > 0 ? 'tt-badge-ok' : 'tt-badge-muted' ?>">
            <?= $activeCount ?> din 5 componente active
        </span>
    </div>

    <div class="tt-table-wrap">
        <table class="tt-table">
            <thead>
                <tr>
                    <th>Componentă</th>
                    <th>Unitate</th>
                    <th>Tarif actual</th>
                    <th>Folosit în calcul</th>
                    <th>Observații</th>
                    <th class="tt-col-actions">Acțiuni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resolved as $key => $entry): ?>
                    <?php $meta = $entry['meta']; ?>
                    <tr>
                        <td>
                            <span class="tt-component-icon <?= e($meta['tone']) ?>">
                                <i class="bi <?= e($meta['icon']) ?>" aria-hidden="true"></i>
                            </span>
                            <?= e($meta['label']) ?>
                        </td>
                        <td class="tt-dash"><?= e($meta['unit']) ?></td>
                        <td class="tt-num">
                            <strong><?= e($money($entry['value'], 2)) ?></strong>
                            <?php if ($entry['scheduled'] !== null): ?>
                                <br><span class="tt-badge tt-badge-info" style="margin-top:3px;">
                                    → <?= e($money((float) $entry['scheduled']['value'], 2)) ?> din <?= e($dateRo((string) $entry['scheduled']['valid_from'])) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $entry['used']
                                ? '<span class="tt-yes">Da</span>'
                                : '<span class="tt-badge tt-badge-muted">Nu (tarif 0)</span>' ?>
                        </td>
                        <td style="white-space:normal;max-width:290px;" class="tt-dash"><?= e($meta['note']) ?></td>
                        <td class="tt-col-actions">
                            <?php if ($canManage): ?>
                                <button type="button" class="tt-btn tt-btn-icon" title="Editează tariful"
                                        data-tt-edit
                                        data-component="<?= e($key) ?>"
                                        data-transport="compresor"
                                        data-route-id="0"
                                        data-label="<?= e($meta['label']) ?>"
                                        data-unit="<?= e(str_replace(' ', '', $meta['unit'])) ?>"
                                        data-current="<?= e((string) $entry['value']) ?>"
                                        data-context="Tarif Compresor la nivel de beneficiar">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="tt-note-strip">
        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
        <span>
            Toate cele cinci componente sunt <strong>independente și aditive</strong>.
            Un tarif 0 elimină complet componenta din calcul — nu există un tarif global Compresor.
        </span>
    </div>
</section>

<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Formula de calcul (Compresor)</h2>
    </div>
    <div class="tt-card-body">
        <div class="tt-formula-chips">
            <span class="tt-formula-chip" style="background:#f8fafc;border-color:var(--tt-border);">Total facturare =</span>
            <?php $first = true; ?>
            <?php foreach ($resolved as $key => $entry): ?>
                <?php if (!$first): ?><span class="tt-formula-plus">+</span><?php endif; ?>
                <span class="tt-formula-chip <?= $entry['used'] ? '' : 'is-muted' ?>">
                    <?= e(str_replace('_', ' ', (string) $entry['meta']['quantity'])) ?>
                    × <?= e($money($entry['value'], 2)) ?>
                </span>
                <?php $first = false; ?>
            <?php endforeach; ?>
        </div>
        <p class="tt-price-note" style="margin-top:12px;">
            Componentele marcate estompat au tariful 0 și nu contribuie la total.
            Pentru „tonă aspirată lichidă/gazoasă", un tarif 0 ascunde și câmpul din formularul de cursă.
        </p>
    </div>
</section>

<section class="tt-card tt-logic">
    <div class="tt-card-head">
        <h2 class="tt-card-title tt-logic-title">Rezumat logic și surse (Compresor)</h2>
    </div>
    <div class="tt-card-body">
        <div class="tt-logic-flow">
            <div class="tt-logic-step">
                <h6>Tarife (5 componente)</h6>
                <p>La nivel de beneficiar</p>
                <code>configurare_beneficiari_transport</code>
            </div>
            <div class="tt-logic-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
            <div class="tt-logic-step">
                <h6>Cantități din cursă</h6>
                <p>ore, km, tone livrate/aspirate</p>
                <code>curse_dispecer</code>
            </div>
            <div class="tt-logic-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
            <div class="tt-logic-step">
                <h6>Toate componentele se adună</h6>
                <p>Fără override, fără rută</p>
                <code>Σ (cantitate × tarif)</code>
            </div>
            <div class="tt-logic-arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></div>
            <div class="tt-logic-step">
                <h6>Eligibilitate vehicul</h6>
                <p>Doar ce vehicule pot rula</p>
                <code>configurare_compresor_vehicule</code>
            </div>
        </div>
    </div>
</section>

<section class="tt-card">
    <div class="tt-card-head">
        <h2 class="tt-card-title">Despre Compresor</h2>
    </div>
    <div class="tt-card-body">
        <div class="tt-about-grid">
            <ul class="tt-check-list">
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Cele 5 tarife se configurează la nivel de beneficiar.</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Cantitățile reale din cursă înmulțesc fiecare componentă.</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Componentele sunt aditive și complet independente.</li>
                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Vehiculele definesc doar eligibilitatea, nu prețul.</li>
            </ul>

            <div class="tt-negative-panel">
                <h6>Ce NU influențează tariful</h6>
                <ul class="tt-cross-list">
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Nu există rută (loc / zonă)</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Nu există cost / cursă</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Capacitatea vehiculului</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Marfa / Tip marfă</li>
                    <li><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Data cursei / Perioada</li>
                </ul>
            </div>
        </div>
    </div>
</section>
