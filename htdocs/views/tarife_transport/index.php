<?php
/**
 * Administrare tarife transport — page shell.
 *
 * Visual reference: mockup 1 (Primar km) and mockup 2 (remaining tabs).
 * Business content follows ANALIZA_COMPONENTE_TARIFARE_TRANSPORT.md, which
 * overrides anything the mockups only imply.
 *
 * @var bool       $schemaReady
 * @var array      $beneficiaries
 * @var int        $selectedBeneficiaryId
 * @var array|null $beneficiary
 * @var string     $activeTab
 * @var bool       $canManage
 * @var array      $versions
 * @var array      $reviews
 * @var array      $primaryRoutes
 * @var array      $distributionRoutes
 * @var array      $pdRoutes
 * @var array      $vehiclePlates
 * @var array      $history
 * @var array|null $fuel
 * @var array      $summary
 * @var float|null $thresholdPercent
 */

$tabs = [
    'primar' => 'Primar km',
    'primar_tona' => 'Primar tone',
    'distributie' => 'Distribuție',
    'primar_distributie' => 'P+D (Primar + Distribuție)',
    'compresor' => 'Compresor',
];

$today = date('Y-m-d');

/** Active version for a component (+ optional route). */
$activeVersion = static function (string $componentKey, ?int $routeRefId = null) use ($versions, $today): ?array {
    foreach ($versions as $version) {
        if ((string) $version['component_key'] !== $componentKey) {
            continue;
        }
        if ((int) $version['route_ref_id'] !== (int) $routeRefId) {
            continue;
        }
        if (($version['status'] ?? '') === 'active') {
            return $version;
        }
    }
    return null;
};

/** Next scheduled version for a component (+ optional route). */
$scheduledVersion = static function (string $componentKey, ?int $routeRefId = null) use ($versions): ?array {
    $best = null;
    foreach ($versions as $version) {
        if ((string) $version['component_key'] !== $componentKey) {
            continue;
        }
        if ((int) $version['route_ref_id'] !== (int) $routeRefId) {
            continue;
        }
        if (($version['status'] ?? '') !== 'scheduled') {
            continue;
        }
        if ($best === null || (string) $version['valid_from'] < (string) $best['valid_from']) {
            $best = $version;
        }
    }
    return $best;
};

$reviewFor = static function (?array $version) use ($reviews): ?array {
    if ($version === null) {
        return null;
    }
    return $reviews[(int) $version['id']] ?? null;
};

$money = static fn (?float $value, int $decimals = 2): string
    => $value === null ? '—' : format_number_ro($value, $decimals);

$dateRo = static function (?string $date): string {
    if ($date === null || $date === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($date))->format('d.m.Y');
    } catch (Throwable) {
        return $date;
    }
};

$dateTimeRo = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('d.m.Y H:i');
    } catch (Throwable) {
        return $value;
    }
};

/** Render eligible-vehicle chips from the stored CSV. */
$vehicleChips = static function (?string $csv, int $limit = 2) use ($vehiclePlates): string {
    $csv = trim((string) $csv);
    if ($csv === '') {
        return '<span class="tt-chip tt-chip-more">Toate vehiculele</span>';
    }
    $ids = array_values(array_filter(array_map('intval', explode(',', $csv))));
    if ($ids === []) {
        return '<span class="tt-chip tt-chip-more">Toate vehiculele</span>';
    }
    $html = '';
    foreach (array_slice($ids, 0, $limit) as $id) {
        $plate = $vehiclePlates[$id] ?? ('#' . $id);
        $html .= '<span class="tt-chip">' . e($plate) . '</span>';
    }
    $rest = count($ids) - $limit;
    if ($rest > 0) {
        $html .= '<span class="tt-chip tt-chip-more">+' . $rest . '</span>';
    }
    return $html;
};

$tabUrl = static fn (string $tab): string => build_query_url([
    'page' => 'tarife_transport',
    'beneficiar_id' => $selectedBeneficiaryId,
    'tab' => $tab,
]);

$statusBadge = static function (?array $review): string {
    if ($review === null) {
        return '';
    }
    $status = (string) $review['status'];
    $tone = TariffReviewService::statusTone($status);
    $label = TariffReviewService::statusLabel($status);
    return '<span class="tt-badge tt-badge-' . e($tone) . '">' . e($label) . '</span>';
};
?>
<div class="tt-page">

    <div class="tt-header">
        <div class="tt-header-titles">
            <h1>Administrare tarife transport</h1>
            <p>Configurează și monitorizează tarifele comerciale pentru fiecare tip de transport.</p>
        </div>

        <div class="tt-header-actions">
            <form method="get" class="tt-beneficiary-picker" id="tt-beneficiary-form">
                <input type="hidden" name="page" value="tarife_transport">
                <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                <label for="tt-beneficiary-select">Beneficiar selectat:</label>
                <select name="beneficiar_id" id="tt-beneficiary-select">
                    <?php if ($beneficiaries === []): ?>
                        <option value="0">Niciun beneficiar</option>
                    <?php endif; ?>
                    <?php foreach ($beneficiaries as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === $selectedBeneficiaryId ? 'selected' : '' ?>>
                            <?= e((string) $option['nume']) ?><?= empty($option['activ']) ? ' (inactiv)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <a class="tt-btn" href="<?= e(build_query_url(['page' => 'tarife_transport', 'action' => 'istoric', 'beneficiar_id' => $selectedBeneficiaryId])) ?>">
                <i class="bi bi-clock-history" aria-hidden="true"></i> Istoric modificări
            </a>

            <?php if ($canManage): ?>
                <button type="button" class="tt-btn tt-btn-primary" data-tt-new-tariff>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Tarif nou
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$schemaReady): ?>
        <div class="tt-inline-alert is-danger">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>
                <strong>Schema de tarife versionate nu este instalată.</strong><br>
                Rulează <code>php scripts/migrate_transport_tariffs.php</code> pentru a activa modulul.
                Până atunci, calculele folosesc configurarea existentă din <em>Configurare transport</em>.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($schemaReady && $thresholdPercent === null): ?>
        <div class="tt-inline-alert is-info">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                Pragul de revizuire nu este configurat, deci nu se emit recomandări automate.
                Monitorizarea motorinei rămâne activă și informativă.
                <?php if ($canManage): ?>
                    <button type="button" class="tt-btn tt-btn-sm" data-tt-open-settings style="margin-left:8px;">Configurează pragul</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($schemaReady && !empty($repricePreview)): ?>
        <?php include __DIR__ . '/_reprice_banner.php'; ?>
    <?php endif; ?>

    <nav class="tt-tabs" role="tablist" aria-label="Tipuri de transport">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="tt-tab <?= $activeTab === $key ? 'is-active' : '' ?>"
               role="tab"
               aria-selected="<?= $activeTab === $key ? 'true' : 'false' ?>"
               href="<?= e($tabUrl($key)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($selectedBeneficiaryId <= 0 || $beneficiary === null): ?>
        <div class="tt-card">
            <div class="tt-empty">
                <i class="bi bi-people" aria-hidden="true"></i>
                <p>Niciun beneficiar de transport disponibil.</p>
                <small>Adaugă întâi un beneficiar din <em>Configurare transport</em>.</small>
            </div>
        </div>
    <?php else: ?>
        <div class="tt-layout">
            <div class="tt-main">
                <?php
                $partials = [
                    'primar' => '_tab_primar.php',
                    'primar_tona' => '_tab_primar_tona.php',
                    'distributie' => '_tab_distributie.php',
                    'primar_distributie' => '_tab_pd.php',
                    'compresor' => '_tab_compresor.php',
                ];
                include __DIR__ . '/' . $partials[$activeTab];
                ?>
            </div>

            <aside class="tt-side">
                <?php include __DIR__ . '/_sidebar.php'; ?>
            </aside>
        </div>
    <?php endif; ?>

    <?php include __DIR__ . '/_modal.php'; ?>
</div>

<link rel="stylesheet" href="<?= e(url('assets/css/tarife-transport.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/tarife-transport.css'))) ?>">
<script src="<?= e(url('assets/js/tarife-transport.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/tarife-transport.js'))) ?>" defer></script>
