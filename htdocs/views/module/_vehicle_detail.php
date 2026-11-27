<?php
/**
 * Fișa „Detalii vehicul” (page=vehicule&action=show).
 *
 * Doar prezentare: statusul (EntityStatusService), documentele (DocumentModel),
 * cuplajul (VehicleCouplingModel + action=cupleaza/decupleaza) vin din controller
 * exact ca înainte. Aici nu se recalculează nimic din regulile de business.
 *
 * Variabile așteptate din show.php: $record, $module, $routePage, $backUrl,
 * $statusContext, $vehicleDocuments, $vehicleCouplingContext.
 */

$vdId = (int) ($record['id'] ?? 0);
$vdDetailFields = $module['detail_fields'] ?? $module['form_fields'];

// ---- Status calculat automat (doar citit) ----------------------------------
$vdStatusContext = is_array($statusContext ?? null) ? $statusContext : [];
$vdStatusValue = (string) ($vdStatusContext['status'] ?? ($record['status'] ?? 'inactiv'));
$vdIsActive = $vdStatusValue === 'activ';
$vdChecks = is_array($vdStatusContext['checks'] ?? null) ? $vdStatusContext['checks'] : [];
$vdIssueChecks = array_values(array_filter($vdChecks, static fn (array $c): bool => (string) ($c['state'] ?? 'valid') !== 'valid'));
$vdValidChecks = array_values(array_filter($vdChecks, static fn (array $c): bool => (string) ($c['state'] ?? 'valid') === 'valid'));
$vdRequiredTotal = count($vdChecks);
$vdRequiredValid = count($vdValidChecks);

$vdNormalize = static function (string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);

    return preg_replace('/\s+/', ' ', $value) ?? $value;
};

// ---- Documente: data de expirare afișată (aceeași regulă ca tabelul vechi) --
$vdDocuments = is_array($vehicleDocuments ?? null) ? $vehicleDocuments : [];
$vdToday = new DateTimeImmutable('today');
$vdDocRows = [];
$vdDocExpiryByType = [];
foreach ($vdDocuments as $vdDocument) {
    $customFieldRows = is_array($vdDocument['custom_field_display_rows'] ?? null) ? $vdDocument['custom_field_display_rows'] : [];
    $expiryDate = trim((string) ($vdDocument['data_expirare'] ?? ''));
    $expiryLabel = '';
    if ($expiryDate === '' && $customFieldRows !== []) {
        $customDateRows = array_values(array_filter($customFieldRows, static function (array $row): bool {
            return strtolower(trim((string) ($row['type'] ?? 'text'))) === 'date' && trim((string) ($row['value'] ?? '')) !== '';
        }));
        usort($customDateRows, static fn (array $a, array $b): int => strcmp((string) ($a['value'] ?? ''), (string) ($b['value'] ?? '')));
        if ($customDateRows !== []) {
            $expiryDate = (string) ($customDateRows[0]['value'] ?? '');
            $expiryLabel = (string) ($customDateRows[0]['label'] ?? '');
        }
    }

    $typeKey = $vdNormalize((string) ($vdDocument['tip_document'] ?? ''));
    if ($typeKey !== '' && $expiryDate !== '' && (!isset($vdDocExpiryByType[$typeKey]) || $expiryDate > $vdDocExpiryByType[$typeKey])) {
        $vdDocExpiryByType[$typeKey] = $expiryDate;
    }

    $vdDocRows[] = [
        'document' => $vdDocument,
        'custom_fields' => $customFieldRows,
        'expiry_date' => $expiryDate,
        'expiry_label' => $expiryLabel,
        'search' => $vdNormalize(implode(' ', [
            (string) ($vdDocument['tip_document'] ?? ''),
            (string) ($vdDocument['numar_document'] ?? ''),
            (string) ($vdDocument['fisier_original'] ?? ''),
            $expiryLabel,
        ])),
    ];
}

// Chip-uri pentru documentele obligatorii: starea vine din EntityStatusService;
// „expiră curând” (≤30 zile, ca în expiry_badge_html) e doar o nuanță vizuală.
$vdSoonCount = 0;
$vdChips = [];
foreach (array_merge($vdIssueChecks, $vdValidChecks) as $vdCheck) {
    $state = (string) ($vdCheck['state'] ?? 'valid');
    $label = (string) ($vdCheck['label'] ?? '-');
    $chipClass = match ($state) {
        'valid' => 'is-valid',
        'missing' => 'is-missing',
        'expired' => 'is-expired',
        default => 'is-warning',
    };
    $chipIcon = match ($state) {
        'valid' => 'bi-check-lg',
        'missing' => 'bi-file-earmark-x',
        'expired' => 'bi-calendar-x',
        default => 'bi-exclamation-triangle',
    };
    if ($state === 'valid') {
        $expiry = $vdDocExpiryByType[$vdNormalize($label)] ?? '';
        if ($expiry !== '') {
            try {
                $days = (int) $vdToday->diff(new DateTimeImmutable($expiry))->format('%r%a');
                if ($days >= 0 && $days <= 30) {
                    $chipClass = 'is-soon';
                    $chipIcon = 'bi-hourglass-split';
                    $vdSoonCount++;
                }
            } catch (Throwable) {
            }
        }
    }
    $vdChips[] = [
        'label' => $label,
        'class' => $chipClass,
        'icon' => $chipIcon,
        'message' => (string) ($vdCheck['message'] ?? ''),
    ];
}
$vdChipsVisible = 4;

// ---- Cuplaj (același context ca înainte) ------------------------------------
$vdCoupling = is_array($vehicleCouplingContext ?? null) ? $vehicleCouplingContext : [];
$vdVehicleType = (string) ($vdCoupling['vehicle_type'] ?? ($record['tip_vehicul'] ?? 'autovehicul'));
$vdVehicleTypeNormalized = normalize_vehicle_type($vdVehicleType);
$vdActiveCoupling = is_array($vdCoupling['active_coupling'] ?? null) ? $vdCoupling['active_coupling'] : null;
$vdTractorOptions = is_array($vdCoupling['tractor_options'] ?? null) ? $vdCoupling['tractor_options'] : [];
$vdTrailerOptions = is_array($vdCoupling['trailer_options'] ?? null) ? $vdCoupling['trailer_options'] : [];
$vdCouplingHistory = is_array($vdCoupling['history'] ?? null) ? $vdCoupling['history'] : [];
$vdIsTractor = $vdVehicleTypeNormalized === 'cap_tractor';
$vdIsTrailer = !$vdIsTractor && is_trailer_vehicle_type($vdVehicleType);
$vdCanCouple = $vdIsTractor || $vdIsTrailer;
$vdPartnerNr = '';
$vdPartnerLabel = '';
if ($vdActiveCoupling !== null) {
    $vdPartnerNr = (string) ($vdIsTractor ? ($vdActiveCoupling['semiremorca_nr'] ?? '-') : ($vdActiveCoupling['tractor_nr'] ?? '-'));
    $vdPartnerLabel = trim((string) ($vdIsTractor
        ? (($vdActiveCoupling['semiremorca_marca'] ?? '') . ' ' . ($vdActiveCoupling['semiremorca_model'] ?? ''))
        : (($vdActiveCoupling['tractor_marca'] ?? '') . ' ' . ($vdActiveCoupling['tractor_model'] ?? ''))));
}

// ---- Câmpuri pe categorii ---------------------------------------------------
// Orice câmp din detail_fields neatribuit ajunge în „Suplimentar”, ca să nu se piardă nimic.
$vdCategoryFields = [
    'identitate' => ['nr_inmatriculare', 'marca', 'model', 'tip_vehicul', 'an_fabricatie', 'serie_sasiu'],
    'tehnice' => ['formula_axelor', 'mma', 'nr_fabricatie', 'an_fabricatie_rezervor', 'organism_notificat'],
    'capacitati' => ['capacitate_transport', 'capacitate_transport_confirmata', 'categorie_capacitate_nume', 'capacitate_rezervor'],
    'cuplare' => ['cuplaj_curent'],
    'mentenanta' => ['km_bord', 'km_revizie'],
    'exploatare' => ['status', 'garaj'],
    'suplimentar' => ['observatii', 'poza_original', 'created_at', 'updated_at'],
];
$vdAssigned = array_merge(...array_values($vdCategoryFields));
foreach ($vdDetailFields as $vdField => $vdMeta) {
    if (($vdMeta['store'] ?? true) !== false && !in_array($vdField, $vdAssigned, true)) {
        $vdCategoryFields['suplimentar'][] = $vdField;
    }
}
$vdCategories = [
    'identitate' => ['label' => 'Identitate', 'icon' => 'bi-car-front'],
    'tehnice' => ['label' => 'Date tehnice', 'icon' => 'bi-gear'],
    'capacitati' => ['label' => 'Capacități', 'icon' => 'bi-fuel-pump'],
    'cuplare' => ['label' => 'Cuplare', 'icon' => 'bi-link-45deg'],
    'mentenanta' => ['label' => 'Mentenanță', 'icon' => 'bi-tools'],
    'exploatare' => ['label' => 'Exploatare', 'icon' => 'bi-bar-chart-line'],
    'suplimentar' => ['label' => 'Suplimentar', 'icon' => 'bi-layers'],
];

$vdFieldValueHtml = static function (string $field) use ($vdDetailFields, $record): ?string {
    if (!isset($vdDetailFields[$field]) || (($vdDetailFields[$field]['store'] ?? true) === false)) {
        return null;
    }
    $meta = $vdDetailFields[$field];
    if (($meta['type'] ?? '') === 'vehicle_photo_detail') {
        $photoUrl = vehicle_image_url(isset($record['poza_stocata']) ? (string) $record['poza_stocata'] : null);
        if ($photoUrl === null) {
            return '<span class="text-muted">Nu există poză încărcată</span>';
        }
        $photoName = trim((string) ($record['poza_original'] ?? '')) !== '' ? (string) $record['poza_original'] : 'Deschide imaginea';

        return '<a href="' . e($photoUrl) . '" target="_blank" rel="noopener"><i class="bi bi-image me-1" aria-hidden="true"></i>' . e($photoName) . '</a>';
    }

    return format_value_html($record[$field] ?? null, $meta, $record);
};

$vdRenderFieldList = static function (array $fields) use ($vdDetailFields, $vdFieldValueHtml): void {
    $rows = [];
    foreach ($fields as $field) {
        $html = $vdFieldValueHtml($field);
        if ($html !== null) {
            $rows[] = ['label' => (string) ($vdDetailFields[$field]['label'] ?? $field), 'html' => $html, 'field' => $field];
        }
    }
    if ($rows === []) {
        echo '<p class="vd-empty-note">Nu există câmpuri în această categorie.</p>';

        return;
    }
    $half = (int) ceil(count($rows) / 2);
    $columns = count($rows) > 1 ? [array_slice($rows, 0, $half), array_slice($rows, $half)] : [$rows];
    echo '<div class="vd-field-columns">';
    foreach ($columns as $column) {
        echo '<dl class="vd-field-list">';
        foreach ($column as $row) {
            echo '<div class="vd-field-row" data-field="' . e($row['field']) . '"><dt>' . e($row['label']) . '</dt><dd>' . $row['html'] . '</dd></div>';
        }
        echo '</dl>';
    }
    echo '</div>';
};

// ---- KPI ---------------------------------------------------------------------
// Kilometrajul din Centru Kilometraj (VehicleMileageService, doar citire). vehicule.km_bord ramane
// contorul intern (Dispecer): nu se mai afiseaza in KPI, dar ramane in categoria „Mentenanță”.
$vdMileage = isset($vehicleMileage) && is_array($vehicleMileage) ? $vehicleMileage : null;
$vdFormula = trim((string) ($record['formula_axelor'] ?? ''));
$vdCapTransport = $record['capacitate_transport'] ?? null;
$vdCapRezervor = $record['capacitate_rezervor'] ?? null;
$vdPhotoUrl = vehicle_image_url(isset($record['poza_stocata']) ? (string) $record['poza_stocata'] : null);
$vdTitle = (string) ($record['nr_inmatriculare'] ?? '-');
$vdMakeModel = trim((string) ($record['marca'] ?? '') . ' ' . (string) ($record['model'] ?? ''));
$vdYear = $record['an_fabricatie'] ?? null;

$vdCssVersion = (string) @filemtime(BASE_PATH . '/assets/css/vehicle-detail.css');
$vdJsVersion = (string) @filemtime(BASE_PATH . '/assets/js/vehicle-detail.js');
$vdEditUrl = build_query_url(['page' => $routePage, 'action' => 'edit', 'id' => $vdId]);
$vdAxisUrl = build_query_url(['page' => 'mentenanta', 'action' => 'axis_config', 'vehicle_id' => $vdId]);
$vdTechUrl = build_query_url(['page' => 'stare_tehnica', 'vehicle_id' => $vdId]);
?>
<link rel="stylesheet" href="<?= e(url('assets/css/vehicle-detail.css?v=' . $vdCssVersion)) ?>">

<div class="vd-page" data-vehicle-detail data-vehicle-id="<?= e((string) $vdId) ?>">
    <div class="vd-toolbar">
        <h2 class="vd-page-title">Detalii vehicul</h2>
        <div class="vd-toolbar-actions">
            <a class="vd-btn vd-btn-primary" href="<?= e($vdTechUrl) ?>"><i class="bi bi-activity" aria-hidden="true"></i><span>Stare tehnică</span></a>
            <a class="vd-btn vd-btn-outline" href="<?= e($vdAxisUrl) ?>">
                <svg class="vd-axle-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="3.2" height="12" rx="1.2"/><rect x="18.8" y="6" width="3.2" height="12" rx="1.2"/><path d="M5.2 12h13.6M12 12V7.5"/><circle cx="12" cy="6" r="1.6"/></svg>
                <span>Configurație Axe</span>
            </a>
            <a class="vd-btn vd-btn-outline" href="<?= e($vdEditUrl) ?>"><i class="bi bi-pencil" aria-hidden="true"></i><span>Editează</span></a>
            <a class="vd-btn vd-btn-ghost" href="<?= e($backUrl) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i><span>Înapoi</span></a>
        </div>
    </div>

    <!-- A. Prezentare vehicul -->
    <section class="vd-overview" aria-label="Prezentare vehicul">
        <div class="vd-card vd-photo-card">
            <?php if ($vdPhotoUrl !== null): ?>
                <a class="vd-photo" href="<?= e($vdPhotoUrl) ?>" target="_blank" rel="noopener" title="Deschide imaginea">
                    <img src="<?= e($vdPhotoUrl) ?>" alt="Poza vehicul <?= e($vdTitle) ?>" loading="lazy">
                </a>
            <?php else: ?>
                <a class="vd-photo vd-photo-empty" href="<?= e($vdEditUrl) ?>" title="Încarcă o poză din Editează">
                    <svg viewBox="0 0 120 70" aria-hidden="true"><path d="M8 50V22a4 4 0 0 1 4-4h56a4 4 0 0 1 4 4v28M72 28h20l14 14v8H72"/><path d="M78 30h12l9 10H78z"/><circle cx="26" cy="54" r="7"/><circle cx="92" cy="54" r="7"/><path d="M4 54h15M33 54h52M99 54h8"/></svg>
                    <span>Nu există poză încărcată</span>
                    <small><i class="bi bi-upload" aria-hidden="true"></i> Încarcă din Editează</small>
                </a>
            <?php endif; ?>
        </div>

        <div class="vd-card vd-summary-card">
            <div class="vd-summary-head">
                <div class="vd-summary-ident">
                    <h3 class="vd-plate"><?= e($vdTitle) ?></h3>
                    <div class="vd-meta">
                        <span class="vd-meta-item"><i class="bi bi-buildings" aria-hidden="true"></i><?= e($vdMakeModel !== '' ? mb_strtoupper($vdMakeModel, 'UTF-8') : '-') ?></span>
                        <span class="vd-meta-item"><i class="bi bi-truck" aria-hidden="true"></i><?= e(vehicle_type_label($vdVehicleType)) ?></span>
                        <span class="vd-meta-item"><i class="bi bi-calendar3" aria-hidden="true"></i><?= ($vdYear !== null && $vdYear !== '') ? e(format_year_ro($vdYear)) : '-' ?></span>
                    </div>
                </div>
                <span class="vd-status-pill <?= $vdIsActive ? 'is-active' : 'is-inactive' ?>" title="Status calculat automat din documentele obligatorii">
                    <i class="bi <?= $vdIsActive ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>" aria-hidden="true"></i>
                    <?= $vdIsActive ? 'Activ' : 'Inactiv' ?>
                </span>
            </div>

            <?php if ($vdIsActive): ?>
                <div class="vd-availability <?= $vdSoonCount > 0 ? 'is-soon' : 'is-ok' ?>" role="status">
                    <i class="bi bi-check-circle" aria-hidden="true"></i>
                    <span>Vehiculul poate fi utilizat.<?php if ($vdSoonCount > 0): ?> <em><?= e((string) $vdSoonCount) ?> <?= $vdSoonCount === 1 ? 'document obligatoriu expiră' : 'documente obligatorii expiră' ?> în următoarele 30 de zile.</em><?php endif; ?></span>
                </div>
            <?php else: ?>
                <div class="vd-availability is-blocked" role="status">
                    <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
                    <span>Vehiculul nu poate fi utilizat<?php if ($vdIssueChecks !== []): ?>: <?= e(implode(' ', array_map(static fn (array $c): string => (string) ($c['message'] ?? ''), $vdIssueChecks))) ?><?php else: ?>.<?php endif; ?></span>
                </div>
            <?php endif; ?>

            <div class="vd-kpis">
                <?php if (is_array($vdMileage)): ?>
                <?php
                    $vdKmFirst = $vdMileage['sources']['sas'] ?? null;
                    $vdKmFirstValue = is_array($vdKmFirst) && $vdKmFirst['value'] !== null ? number_format((float) $vdKmFirst['value'], 0, ',', '.') : '—';
                    $vdKmUrl = url('index.php?page=vehicule&action=mileage&id=' . (int) ($record['id'] ?? 0));
                ?>
                <div class="vd-kpi vd-km" data-vd-km data-url="<?= e($vdKmUrl) ?>" tabindex="0" role="group"
                     aria-roledescription="carusel" aria-label="Kilometraj vehicul: săgețile stânga / dreapta schimbă sursa">
                    <button type="button" class="vd-km-arrow is-prev" data-vd-km-step="-1" aria-label="Sursa anterioară"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                    <i class="bi bi-speedometer2 vd-kpi-icon" aria-hidden="true"></i>
                    <div class="vd-km-body">
                        <div class="vd-km-stage" data-vd-km-stage aria-live="polite">
                            <div class="vd-km-slide"><strong><?= e($vdKmFirstValue) ?></strong><span class="vd-km-label"><?= e((string) ($vdKmFirst['label'] ?? 'Km desfășurare')) ?></span></div>
                        </div>
                        <button type="button" class="vd-km-meta" data-vd-km-info aria-haspopup="dialog" aria-expanded="false" title="Detalii despre calcul">
                            <i class="vd-km-dot" data-vd-km-dot aria-hidden="true"></i><span data-vd-km-meta><?= e((string) ($vdKmFirst['provider_label'] ?? 'SAS GPS')) ?></span>
                        </button>
                    </div>
                    <button type="button" class="vd-km-arrow is-next" data-vd-km-step="1" aria-label="Sursa următoare"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                    <button type="button" class="vd-km-period" data-vd-km-period aria-haspopup="dialog" aria-expanded="false" aria-label="Perioadă: Până în prezent" title="Perioadă: Până în prezent">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                    </button>
                    <div class="vd-km-dots" role="tablist" aria-label="Sursa kilometrajului">
                        <?php foreach (['sas' => 'SAS GPS', 'can' => 'SAS CAN', 'cardoil' => 'CardOil'] as $vdKmKey => $vdKmName): ?>
                            <button type="button" role="tab" class="vd-km-dot-btn" data-vd-km-go="<?= e($vdKmKey) ?>" aria-label="<?= e($vdKmName) ?>" aria-selected="<?= $vdKmKey === 'sas' ? 'true' : 'false' ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="vd-km-pop" data-vd-km-pop role="dialog" aria-modal="false" hidden></div>
                    <script type="application/json" data-vd-km-data><?= json_encode($vdMileage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
                </div>
                <?php else: ?>
                <div class="vd-kpi">
                    <i class="bi bi-speedometer2 vd-kpi-icon" aria-hidden="true"></i>
                    <div><strong>—</strong><span>Kilometraj indisponibil</span></div>
                </div>
                <?php endif; ?>
                <div class="vd-kpi">
                    <svg class="vd-kpi-icon vd-axle-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="3.2" height="12" rx="1.2"/><rect x="18.8" y="6" width="3.2" height="12" rx="1.2"/><path d="M5.2 12h13.6M12 12V7.5"/><circle cx="12" cy="6" r="1.6"/></svg>
                    <div><strong><?= e($vdFormula !== '' ? $vdFormula : '-') ?></strong><span>Formula axelor</span></div>
                </div>
                <div class="vd-kpi">
                    <i class="bi bi-box-seam vd-kpi-icon" aria-hidden="true"></i>
                    <div><strong><?= ($vdCapTransport !== null && $vdCapTransport !== '') ? e(format_number_ro($vdCapTransport, 2)) . ' tone' : '-' ?></strong><span>Capacitate transport</span></div>
                </div>
                <div class="vd-kpi">
                    <i class="bi bi-fuel-pump vd-kpi-icon" aria-hidden="true"></i>
                    <div><strong><?= ($vdCapRezervor !== null && $vdCapRezervor !== '') ? 'Rezervor ' . e(format_number_ro($vdCapRezervor, ((float) $vdCapRezervor == floor((float) $vdCapRezervor)) ? 0 : 2)) . ' L' : '-' ?></strong><span>Capacitate rezervor</span></div>
                </div>
            </div>
        </div>
    </section>

    <!-- B. Informații vehicul -->
    <section class="vd-card vd-section" data-vd-section="info">
        <div class="vd-section-head" data-vd-toggle-area>
            <button type="button" class="vd-section-title" data-vd-toggle aria-expanded="true" aria-controls="vd-info-body">
                <i class="bi bi-car-front vd-section-icon" aria-hidden="true"></i>
                <span>Informații vehicul</span>
            </button>
            <button type="button" class="vd-chevron" data-vd-toggle aria-expanded="true" aria-controls="vd-info-body" aria-label="Restrânge / extinde Informații vehicul">
                <i class="bi bi-chevron-up" aria-hidden="true"></i>
            </button>
        </div>
        <div class="vd-collapse" id="vd-info-body">
            <div class="vd-collapse-inner">
                <div class="vd-carousel" data-vd-carousel>
                    <div class="vd-carousel-nav" data-vd-wheel-zone>
                        <button type="button" class="vd-arrow" data-vd-prev aria-label="Categoria anterioară"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                        <div class="vd-tabs" role="tablist" aria-label="Categorii informații vehicul" data-vd-tabs>
                            <?php $vdIndex = 0; foreach ($vdCategories as $vdKey => $vdCat): ?>
                                <button type="button" class="vd-tab<?= $vdIndex === 0 ? ' is-active' : '' ?>" role="tab"
                                        id="vd-tab-<?= e($vdKey) ?>" aria-controls="vd-panel-<?= e($vdKey) ?>"
                                        aria-selected="<?= $vdIndex === 0 ? 'true' : 'false' ?>" tabindex="<?= $vdIndex === 0 ? '0' : '-1' ?>"
                                        data-vd-tab="<?= e($vdKey) ?>">
                                    <i class="bi <?= e($vdCat['icon']) ?>" aria-hidden="true"></i>
                                    <span><?= e($vdCat['label']) ?></span>
                                </button>
                            <?php $vdIndex++; endforeach; ?>
                        </div>
                        <button type="button" class="vd-arrow" data-vd-next aria-label="Categoria următoare"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                    </div>
                    <div class="vd-dots" aria-hidden="true">
                        <?php $vdIndex = 0; foreach ($vdCategories as $vdKey => $vdCat): ?>
                            <span class="vd-dot<?= $vdIndex === 0 ? ' is-active' : '' ?>" data-vd-dot="<?= e($vdKey) ?>"></span>
                        <?php $vdIndex++; endforeach; ?>
                    </div>
                    <p class="vd-hint"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Navighează cu săgețile, rotița pe bara de categorii sau scroll orizontal</p>

                    <div class="vd-panels" data-vd-panels><div class="vd-panels-track" data-vd-track>
                        <?php $vdIndex = 0; foreach ($vdCategories as $vdKey => $vdCat): ?>
                            <div class="vd-panel<?= $vdIndex === 0 ? ' is-active' : '' ?>" role="tabpanel" id="vd-panel-<?= e($vdKey) ?>"
                                 aria-labelledby="vd-tab-<?= e($vdKey) ?>" data-vd-panel="<?= e($vdKey) ?>" <?= $vdIndex === 0 ? '' : 'aria-hidden="true" inert' ?>>
                                <h4 class="vd-panel-title"><i class="bi <?= e($vdCat['icon']) ?>" aria-hidden="true"></i><?= e($vdCat['label']) ?></h4>

                                <?php if ($vdKey === 'cuplare'): ?>
                                    <?php $vdRenderFieldList($vdCategoryFields['cuplare']); ?>
                                    <div class="vd-coupling">
                                        <?php if (!$vdCanCouple): ?>
                                            <div class="vd-note">
                                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                                <span>Acest vehicul este de tip <strong><?= e(vehicle_type_label($vdVehicleType)) ?></strong>. Cuplajul este disponibil doar pentru <strong>Cap tractor</strong> și <strong>Semi-remorcă</strong>.</span>
                                            </div>
                                        <?php elseif ($vdIsTractor): ?>
                                            <div class="vd-coupling-current">
                                                <span class="vd-coupling-label">Semiremorca curentă</span>
                                                <strong><?= $vdActiveCoupling ? e($vdPartnerNr) : 'Nicio semiremorcă cuplată' ?></strong>
                                                <?php if ($vdPartnerLabel !== ''): ?><span class="text-muted"><?= e($vdPartnerLabel) ?></span><?php endif; ?>
                                            </div>
                                            <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'cupleaza'])) ?>" class="vd-coupling-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="tractor_id" value="<?= e((string) $vdId) ?>">
                                                <input type="hidden" name="redirect_vehicle_id" value="<?= e((string) $vdId) ?>">
                                                <div class="vd-coupling-field">
                                                    <label class="form-label mb-1" for="semiremorca_id">Selectează semiremorca</label>
                                                    <select class="form-select" id="semiremorca_id" name="semiremorca_id" data-vd-coupling-select <?= $vdTrailerOptions !== [] ? 'required' : 'disabled' ?>>
                                                        <?php if ($vdTrailerOptions !== []): ?>
                                                            <option value="">-- Selectează --</option>
                                                        <?php else: ?>
                                                            <option value="">Nu există semiremorci active și necuplate.</option>
                                                        <?php endif; ?>
                                                        <?php foreach ($vdTrailerOptions as $optionId => $optionLabel): ?>
                                                            <option value="<?= e((string) $optionId) ?>" <?= $vdActiveCoupling && (int) ($vdActiveCoupling['semiremorca_id'] ?? 0) === (int) $optionId ? 'selected' : '' ?>><?= e((string) $optionLabel) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <button type="submit" class="vd-btn vd-btn-primary" <?= $vdTrailerOptions !== [] ? '' : 'disabled' ?>><i class="bi bi-link-45deg" aria-hidden="true"></i>Salvează cuplaj</button>
                                            </form>
                                        <?php else: ?>
                                            <div class="vd-coupling-current">
                                                <span class="vd-coupling-label">Tractor curent</span>
                                                <strong><?= $vdActiveCoupling ? e($vdPartnerNr) : 'Niciun tractor cuplat' ?></strong>
                                                <?php if ($vdPartnerLabel !== ''): ?><span class="text-muted"><?= e($vdPartnerLabel) ?></span><?php endif; ?>
                                            </div>
                                            <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'cupleaza'])) ?>" class="vd-coupling-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="semiremorca_id" value="<?= e((string) $vdId) ?>">
                                                <input type="hidden" name="redirect_vehicle_id" value="<?= e((string) $vdId) ?>">
                                                <div class="vd-coupling-field">
                                                    <label class="form-label mb-1" for="tractor_id">Selectează cap tractor</label>
                                                    <select class="form-select" id="tractor_id" name="tractor_id" data-vd-coupling-select <?= $vdTractorOptions !== [] ? 'required' : 'disabled' ?>>
                                                        <option value="">-- Selectează --</option>
                                                        <?php foreach ($vdTractorOptions as $optionId => $optionLabel): ?>
                                                            <option value="<?= e((string) $optionId) ?>" <?= $vdActiveCoupling && (int) ($vdActiveCoupling['tractor_id'] ?? 0) === (int) $optionId ? 'selected' : '' ?>><?= e((string) $optionLabel) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <button type="submit" class="vd-btn vd-btn-primary" <?= $vdTractorOptions !== [] ? '' : 'disabled' ?>><i class="bi bi-link-45deg" aria-hidden="true"></i>Mută pe tractor</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($vdCouplingHistory !== []): ?>
                                            <div class="vd-subtitle">Istoric cuplaje</div>
                                            <div class="table-responsive vd-history">
                                                <table class="table table-sm align-middle mb-0">
                                                    <thead><tr><th>Cap tractor</th><th>Semiremorcă</th><th>Start</th><th>Sfârșit</th><th>Status</th></tr></thead>
                                                    <tbody>
                                                    <?php foreach ($vdCouplingHistory as $history): ?>
                                                        <tr>
                                                            <td><?= e((string) ($history['tractor_nr'] ?? '-')) ?></td>
                                                            <td><?= e((string) ($history['semiremorca_nr'] ?? '-')) ?></td>
                                                            <td><?= e(format_datetime_ro((string) ($history['data_start'] ?? ''))) ?></td>
                                                            <td><?= e(format_datetime_ro((string) ($history['data_end'] ?? ''))) ?></td>
                                                            <td><?= !empty($history['activ']) ? '<span class="badge text-bg-success">Activ</span>' : '<span class="badge text-bg-secondary">Încheiat</span>' ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <?php $vdRenderFieldList($vdCategoryFields[$vdKey] ?? []); ?>
                                    <?php if ($vdKey === 'mentenanta'): ?>
                                        <div class="vd-panel-links">
                                            <a class="vd-btn vd-btn-outline vd-btn-sm" href="<?= e($vdTechUrl) ?>"><i class="bi bi-activity" aria-hidden="true"></i>Stare tehnică</a>
                                            <a class="vd-btn vd-btn-outline vd-btn-sm" href="<?= e($vdAxisUrl) ?>"><i class="bi bi-diagram-3" aria-hidden="true"></i>Configurație Axe</a>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php $vdIndex++; endforeach; ?>
                    </div></div>
                </div>

                <!-- Cuplare / alocare (rezumat; formularul complet e în categoria Cuplare) -->
                <div class="vd-coupling-strip">
                    <div class="vd-coupling-strip-title"><i class="bi bi-link-45deg" aria-hidden="true"></i><span>Cuplare / alocare</span></div>
                    <div class="vd-coupling-strip-text">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <?php if (!$vdCanCouple): ?>
                            <span>Disponibil doar pentru cap tractor și semiremorcă. Tipul <strong><?= e(vehicle_type_label($vdVehicleType)) ?></strong> nu se cuplează.</span>
                        <?php elseif ($vdActiveCoupling !== null): ?>
                            <span>Cuplat cu <strong><?= e($vdPartnerNr) ?></strong><?= $vdPartnerLabel !== '' ? ' · ' . e($vdPartnerLabel) : '' ?><?php if (!empty($vdActiveCoupling['data_start'])): ?> din <?= e(format_datetime_ro((string) $vdActiveCoupling['data_start'])) ?><?php endif; ?>.</span>
                        <?php else: ?>
                            <span>Disponibil pentru cap tractor și semiremorcă. <?= $vdIsTractor ? 'Nicio semiremorcă cuplată.' : 'Niciun tractor cuplat.' ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($vdCanCouple): ?>
                        <div class="vd-coupling-strip-actions">
                            <button type="button" class="vd-btn vd-btn-primary vd-btn-sm" data-vd-goto="cuplare"><i class="bi bi-link-45deg" aria-hidden="true"></i><?= $vdActiveCoupling ? 'Schimbă' : 'Cuplează' ?></button>
                            <?php if ($vdActiveCoupling !== null): ?>
                                <form method="post" action="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'decupleaza'])) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="<?= $vdIsTractor ? 'tractor_id' : 'semiremorca_id' ?>" value="<?= e((string) $vdId) ?>">
                                    <input type="hidden" name="redirect_vehicle_id" value="<?= e((string) $vdId) ?>">
                                    <button type="submit" class="vd-btn vd-btn-outline vd-btn-sm" data-confirm="<?= e($vdIsTractor ? 'Sigur doresti sa decuplezi semiremorca de pe acest tractor?' : 'Sigur doresti sa decuplezi aceasta semiremorca?') ?>"><i class="bi bi-link-45deg" aria-hidden="true"></i>Decuplează</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- C. Documente asociate vehiculului -->
    <section class="vd-card vd-section" data-vd-section="docs">
        <div class="vd-section-head vd-docs-head" data-vd-toggle-area>
            <button type="button" class="vd-section-title" data-vd-toggle aria-expanded="true" aria-controls="vd-docs-body">
                <i class="bi bi-file-earmark-text vd-section-icon" aria-hidden="true"></i>
                <span>Documente asociate vehiculului</span>
            </button>
            <div class="vd-doc-chips">
                <?php if ($vdRequiredTotal > 0): ?>
                    <span class="vd-chip <?= $vdRequiredValid === $vdRequiredTotal ? 'is-valid' : 'is-expired' ?> vd-chip-strong" title="Documente obligatorii valabile / documente obligatorii pentru acest tip de vehicul">
                        <i class="bi <?= $vdRequiredValid === $vdRequiredTotal ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' ?>" aria-hidden="true"></i><?= e((string) $vdRequiredValid) ?>/<?= e((string) $vdRequiredTotal) ?> obligatorii valide
                    </span>
                <?php else: ?>
                    <span class="vd-chip is-neutral" title="Nu sunt configurate documente obligatorii pentru acest tip de vehicul">Fără documente obligatorii</span>
                <?php endif; ?>
                <span class="vd-chip <?= $vdIsActive ? 'is-valid' : 'is-expired' ?>">
                    <i class="bi <?= $vdIsActive ? 'bi-check-lg' : 'bi-x-lg' ?>" aria-hidden="true"></i><?= $vdIsActive ? 'Vehicul utilizabil' : 'Vehicul neutilizabil' ?>
                </span>
                <?php foreach ($vdChips as $vdChipIndex => $vdChip): ?>
                    <button type="button" class="vd-chip <?= e($vdChip['class']) ?><?= $vdChipIndex >= $vdChipsVisible ? ' is-extra' : '' ?>"
                            title="<?= e($vdChip['message']) ?>" data-vd-chip-search="<?= e($vdChip['label']) ?>" <?= $vdChipIndex >= $vdChipsVisible ? 'hidden' : '' ?>>
                        <i class="bi <?= e($vdChip['icon']) ?>" aria-hidden="true"></i><?= e($vdChip['label']) ?>
                    </button>
                <?php endforeach; ?>
                <?php if (count($vdChips) > $vdChipsVisible): ?>
                    <button type="button" class="vd-chip is-more" data-vd-chip-more aria-expanded="false" title="Arată toate documentele obligatorii">+<?= e((string) (count($vdChips) - $vdChipsVisible)) ?></button>
                <?php endif; ?>
            </div>
            <button type="button" class="vd-chevron" data-vd-toggle aria-expanded="true" aria-controls="vd-docs-body" aria-label="Restrânge / extinde Documente asociate vehiculului">
                <i class="bi bi-chevron-up" aria-hidden="true"></i>
            </button>
        </div>
        <div class="vd-collapse" id="vd-docs-body">
            <div class="vd-collapse-inner">
                <div class="vd-docs-toolbar">
                    <label class="vd-search">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" placeholder="Caută document..." autocomplete="off" aria-label="Caută document după tip, serie / număr sau fișier" data-vd-doc-search>
                    </label>
                    <span class="vd-docs-count" data-vd-doc-count aria-live="polite"><?= e((string) count($vdDocRows)) ?> <?= count($vdDocRows) === 1 ? 'document' : 'documente' ?></span>
                    <a class="vd-btn vd-btn-primary vd-btn-lg" href="<?= e(build_query_url(['page' => 'documente', 'action' => 'create', 'vehicle_id' => $vdId])) ?>"><i class="bi bi-plus-circle" aria-hidden="true"></i>Adaugă document</a>
                </div>

                <div class="vd-doc-table-wrap">
                    <div class="vd-doc-scroll" data-vd-doc-scroll tabindex="0" aria-label="Lista documentelor vehiculului">
                        <table class="vd-doc-table">
                            <thead>
                            <tr>
                                <th scope="col">Tip document</th>
                                <th scope="col">Serie / număr</th>
                                <th scope="col">Fișier</th>
                                <th scope="col">Expirare</th>
                                <th scope="col" class="vd-col-actions">Acțiuni</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($vdDocRows as $vdRow): ?>
                                <?php
                                $vdDoc = $vdRow['document'];
                                $vdDocId = (int) ($vdDoc['id'] ?? 0);
                                $vdHasFile = !empty($vdDoc['fisier_stocat']);
                                $vdPreviewUrl = build_query_url(['page' => 'documente', 'action' => 'preview', 'id' => $vdDocId]);
                                $vdShowUrl = build_query_url(['page' => 'documente', 'action' => 'show', 'id' => $vdDocId]);
                                $vdDocEditUrl = build_query_url(['page' => 'documente', 'action' => 'edit', 'id' => $vdDocId]);
                                $vdFileUrl = $vdHasFile ? document_file_url((string) $vdDoc['fisier_stocat']) : null;
                                $vdFileName = trim((string) ($vdDoc['fisier_original'] ?? '')) !== '' ? (string) $vdDoc['fisier_original'] : 'Descarcă fișier';
                                ?>
                                <tr data-vd-doc-row data-search="<?= e($vdRow['search']) ?>">
                                    <td>
                                        <div class="vd-doc-type"><?= e((string) ($vdDoc['tip_document'] ?? '-')) ?></div>
                                        <?php foreach ($vdRow['custom_fields'] as $customFieldRow): ?>
                                            <?php
                                            $fieldType = strtolower(trim((string) ($customFieldRow['type'] ?? 'text')));
                                            $fieldLabel = trim((string) ($customFieldRow['label'] ?? ''));
                                            $fieldValue = trim((string) ($customFieldRow['value'] ?? ''));
                                            $displayValue = match (true) {
                                                $fieldType === 'checkbox' => $fieldValue === '1' ? 'Da' : 'Nu',
                                                $fieldType === 'date' => $fieldValue !== '' ? format_date_ro($fieldValue) : '',
                                                default => $fieldValue,
                                            };
                                            ?>
                                            <?php if ($fieldType === 'checkbox' && $fieldValue === '1'): ?>
                                                <span class="badge text-bg-light border"><?= e($fieldLabel) ?></span>
                                            <?php elseif ($displayValue !== ''): ?>
                                                <div class="vd-doc-extra"><span><?= e($fieldLabel) ?>:</span> <strong><?= e($displayValue) ?></strong></div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="vd-doc-number"><?= e((string) (($vdDoc['numar_document'] ?? '') !== '' ? $vdDoc['numar_document'] : '-')) ?></td>
                                    <td>
                                        <?php if ($vdFileUrl !== null): ?>
                                            <a class="vd-file-link" href="<?= e($vdFileUrl) ?>" target="_blank" rel="noopener" title="<?= e($vdFileName) ?>"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span><?= e($vdFileName) ?></span></a>
                                        <?php else: ?>
                                            <span class="vd-file-link is-empty"><i class="bi bi-file-earmark" aria-hidden="true"></i><span>Fără fișier</span></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="vd-doc-expiry">
                                        <?= expiry_badge_html($vdRow['expiry_date']) ?>
                                        <?php if ($vdRow['expiry_label'] !== ''): ?><div class="vd-doc-extra"><?= e($vdRow['expiry_label']) ?></div><?php endif; ?>
                                    </td>
                                    <td class="vd-col-actions">
                                        <div class="vd-row-actions">
                                            <?php if ($vdHasFile): ?>
                                                <a class="vd-act" href="<?= e($vdPreviewUrl) ?>">Vezi în aplicație</a>
                                            <?php else: ?>
                                                <span class="vd-act is-placeholder" aria-hidden="true">Vezi în aplicație</span>
                                            <?php endif; ?>
                                            <a class="vd-act" href="<?= e($vdShowUrl) ?>">Detalii</a>
                                            <a class="vd-act is-primary" href="<?= e($vdDocEditUrl) ?>">Editează</a>
                                        </div>
                                        <div class="dropdown vd-row-menu">
                                            <button class="vd-act" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="Acțiuni document">
                                                <i class="bi bi-three-dots" aria-hidden="true"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php if ($vdHasFile): ?><li><a class="dropdown-item" href="<?= e($vdPreviewUrl) ?>"><i class="bi bi-eye me-2" aria-hidden="true"></i>Vezi în aplicație</a></li><?php endif; ?>
                                                <li><a class="dropdown-item" href="<?= e($vdShowUrl) ?>"><i class="bi bi-info-circle me-2" aria-hidden="true"></i>Detalii</a></li>
                                                <li><a class="dropdown-item" href="<?= e($vdDocEditUrl) ?>"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Editează</a></li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="vd-doc-empty" data-vd-doc-empty <?= $vdDocRows === [] ? '' : 'hidden' ?>>
                                <td colspan="5">
                                    <i class="bi bi-search" aria-hidden="true"></i>
                                    <span data-vd-doc-empty-text><?= $vdDocRows === [] ? 'Nu există documente asociate acestui vehicul.' : 'Niciun document nu corespunde căutării.' ?></span>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<script src="<?= e(url('assets/js/vehicle-detail.js?v=' . $vdJsVersion)) ?>" defer></script>
<?php if ($vdMileage !== null): ?>
<script src="<?= e(url('assets/js/datetime-picker-field.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/datetime-picker-field.js'))) ?>" defer></script>
<script src="<?= e(url('assets/js/vehicle-mileage.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/vehicle-mileage.js'))) ?>" defer></script>
<?php endif; ?>
