<?php
$baseQuery = [
    'page' => 'dispecer_curse',
    'action' => 'index',
    'q' => $search,
    'tip_transport' => $filters['tip_transport'] ?? '',
    'vehicle_id' => $filters['vehicle_id'] ?? '',
    'loc_incarcare_id' => $filters['loc_incarcare_id'] ?? '',
    'beneficiar_id' => $filters['beneficiar_id'] ?? '',
    'zona_distributie_id' => $filters['zona_distributie_id'] ?? '',
    'data_start' => $filters['data_start'] ?? '',
    'data_end' => $filters['data_end'] ?? '',
];
$currentListUrl = build_query_url($baseQuery);
$hasActiveFilters = $search !== '';
foreach (['tip_transport', 'vehicle_id', 'loc_incarcare_id', 'beneficiar_id', 'zona_distributie_id', 'data_start', 'data_end'] as $filterKey) {
    if (trim((string) ($filters[$filterKey] ?? '')) !== '') {
        $hasActiveFilters = true;
        break;
    }
}

$zoneTariffJson = json_encode($zoneTariffs, JSON_UNESCAPED_UNICODE);
if (!is_string($zoneTariffJson)) {
    $zoneTariffJson = '{}';
}
$zoneExtraKmJson = json_encode($zoneExtraKmCosts ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($zoneExtraKmJson)) {
    $zoneExtraKmJson = '{}';
}

$beneficiaryPricingJson = json_encode($beneficiaryPricing ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($beneficiaryPricingJson)) {
    $beneficiaryPricingJson = '{}';
}

$loadLocationTariffJson = json_encode($loadLocationTariffs ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($loadLocationTariffJson)) {
    $loadLocationTariffJson = '{}';
}

$vehicleDefaultLoadLocationJson = json_encode($vehicleDefaultLoadLocationMap ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($vehicleDefaultLoadLocationJson)) {
    $vehicleDefaultLoadLocationJson = '{}';
}

$vehicleGarageJson = json_encode($vehicleGarageMap ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($vehicleGarageJson)) {
    $vehicleGarageJson = '{}';
}

$vehicleDefaultDistributionZoneJson = json_encode($vehicleDefaultDistributionZoneMap ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($vehicleDefaultDistributionZoneJson)) {
    $vehicleDefaultDistributionZoneJson = '{}';
}

$loadLocationsByBeneficiaryJson = json_encode($loadLocationsByBeneficiary ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($loadLocationsByBeneficiaryJson)) {
    $loadLocationsByBeneficiaryJson = '{}';
}

$distributionZonesByBeneficiaryJson = json_encode($distributionZonesByBeneficiary ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($distributionZonesByBeneficiaryJson)) {
    $distributionZonesByBeneficiaryJson = '{}';
}

$distributionRouteTariffMapJson = json_encode($distributionRouteTariffMap ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($distributionRouteTariffMapJson)) {
    $distributionRouteTariffMapJson = '{}';
}
$primaryRouteKmMapJson = json_encode($primaryRouteKmMap ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($primaryRouteKmMapJson)) {
    $primaryRouteKmMapJson = '{}';
}

$vehicleDefaultLoadLocationByBeneficiaryJson = json_encode($vehicleDefaultLoadLocationMapByBeneficiary ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($vehicleDefaultLoadLocationByBeneficiaryJson)) {
    $vehicleDefaultLoadLocationByBeneficiaryJson = '{}';
}

$vehicleDefaultDistributionZoneByBeneficiaryJson = json_encode($vehicleDefaultDistributionZoneMapByBeneficiary ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($vehicleDefaultDistributionZoneByBeneficiaryJson)) {
    $vehicleDefaultDistributionZoneByBeneficiaryJson = '{}';
}
$compressorVehicleByBeneficiaryJson = json_encode($compressorVehicleMapByBeneficiary ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($compressorVehicleByBeneficiaryJson)) {
    $compressorVehicleByBeneficiaryJson = '{}';
}
$activeDriverVehicleIdsJson = json_encode(array_values(array_unique(array_map('intval', (array) ($activeDriverVehicleIds ?? [])))), JSON_UNESCAPED_UNICODE);
if (!is_string($activeDriverVehicleIdsJson)) {
    $activeDriverVehicleIdsJson = '[]';
}
$driversByVehicleJson = json_encode($driversByVehicle ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($driversByVehicleJson)) {
    $driversByVehicleJson = '{}';
}
$allDriversPayload = [];
foreach ((array) ($allActiveDrivers ?? []) as $allDriverRow) {
    $allDriverId = (int) ($allDriverRow['id'] ?? 0);
    $allDriverName = trim((string) ($allDriverRow['nume'] ?? ''));
    if ($allDriverId > 0 && $allDriverName !== '') {
        $allDriversPayload[] = ['id' => $allDriverId, 'nume' => $allDriverName];
    }
}
$allDriversJson = json_encode($allDriversPayload, JSON_UNESCAPED_UNICODE);
if (!is_string($allDriversJson)) {
    $allDriversJson = '[]';
}
$selectedTransportType = (string) ($formData['tip_transport'] ?? '');
$isDistributionSelected = in_array($selectedTransportType, ['distributie', 'primar_distributie'], true);
$isPrimarySelected = in_array($selectedTransportType, ['primar', 'primar_tona'], true);
$isPrimaryDistributionSelected = $selectedTransportType === 'primar_distributie';
$isAgreedKmNamingSelected = in_array($selectedTransportType, ['primar', 'primar_distributie'], true);
$isKmTotalSelected = $isPrimarySelected || $isPrimaryDistributionSelected;
$isCompressorSelected = $selectedTransportType === 'compresor';

$selectedGoodsTypeKeys = [];
foreach ((array) ($formData['tip_marfa'] ?? []) as $selectedGoodsTypeKey) {
    $selectedGoodsTypeKey = trim((string) $selectedGoodsTypeKey);
    if ($selectedGoodsTypeKey === '') {
        continue;
    }
    $selectedGoodsTypeKeys[$selectedGoodsTypeKey] = $selectedGoodsTypeKey;
}
$selectedGoodsTypeKeys = array_values($selectedGoodsTypeKeys);
$selectedGoodsTypeLabels = [];
foreach ($selectedGoodsTypeKeys as $selectedGoodsTypeKey) {
    if (isset($goodsTypeOptions[$selectedGoodsTypeKey])) {
        $selectedGoodsTypeLabels[] = (string) $goodsTypeOptions[$selectedGoodsTypeKey];
    }
}
$selectedGoodsTypeButtonLabel = $selectedGoodsTypeLabels !== [] ? implode(', ', $selectedGoodsTypeLabels) : '-- Selecteaza --';

$formatDurationLabel = static function (?int $minutes): string {
    if ($minutes === null || $minutes < 0) {
        return '-';
    }

    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;

    if ($hours > 0 && $mins > 0) {
        return $hours . 'h ' . $mins . 'm';
    }
    if ($hours > 0) {
        return $hours . 'h';
    }

    return $mins . 'm';
};

$renderDispatcherSummaryDetails = static function (array $parts, string $rowKey, string $summaryLabel): string {
    $items = [];
    foreach ($parts as $part) {
        $label = rtrim(trim((string) ($part['label'] ?? '')), ':');
        $value = trim((string) ($part['value'] ?? ''));
        if ($label === '' && $value === '') {
            continue;
        }

        $items[] = [
            'label' => $label,
            'value' => $value,
            'is_total' => !empty($part['is_total']),
        ];
    }

    $count = count($items);
    $countLabel = $count === 1 ? '1 detaliu' : $count . ' detalii';
    $safeRowKey = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $rowKey) ?: uniqid('summary_', false);

    if ($count === 0) {
        return '<button type="button" class="dispatcher-summary-count-btn is-empty" disabled aria-label="0 detalii ' . e($summaryLabel) . '">0 detalii</button>';
    }

    $popoverId = 'dispatcher_summary_popover_' . $safeRowKey;
    $titleParts = [];
    foreach ($items as $item) {
        $titleParts[] = trim((string) $item['label'] . ': ' . (string) $item['value']);
    }
    $title = implode(' | ', $titleParts);
    $totalLabel = 'Total ' . $countLabel;

    $html = '<div class="dispatcher-summary-list" data-dispatcher-summary-list>';
    $html .= '<button type="button" class="dispatcher-summary-count-btn" data-dispatcher-summary-toggle data-popover-id="' . e($popoverId) . '" aria-haspopup="dialog" aria-expanded="false" aria-controls="' . e($popoverId) . '" aria-label="' . e('Afiseaza ' . $countLabel . ' ' . $summaryLabel) . '" title="' . e($title) . '">';
    $html .= '<span>' . e($countLabel) . '</span><i class="bi bi-chevron-down" aria-hidden="true"></i>';
    $html .= '</button>';
    $html .= '<div class="dispatcher-summary-popover" id="' . e($popoverId) . '" data-dispatcher-summary-popover role="dialog" aria-label="' . e('Detalii ' . $summaryLabel) . '" tabindex="-1" hidden>';
    $html .= '<ul class="dispatcher-summary-popover-list" role="list">';

    foreach ($items as $item) {
        $valueClass = !empty($item['is_total']) ? ' dispatcher-summary-value-total' : '';
        $html .= '<li class="dispatcher-summary-popover-item" role="listitem">';
        $html .= '<strong>' . e((string) ($item['label'] !== '' ? $item['label'] : '-')) . '</strong>';
        $html .= '<span class="' . trim('dispatcher-summary-value' . $valueClass) . '">' . e((string) ($item['value'] !== '' ? $item['value'] : '-')) . '</span>';
        $html .= '</li>';
    }

    $html .= '</ul>';
    $html .= '<div class="dispatcher-summary-popover-total">' . e($totalLabel) . '</div>';
    $html .= '</div></div>';

    return $html;
};

$formStartTimeValueRaw = trim((string) ($formData['ora_inceput'] ?? ''));
$formStartTimeValue = $formStartTimeValueRaw !== '' ? substr($formStartTimeValueRaw, 0, 5) : '';
$formEndTimeValueRaw = trim((string) ($formData['ora_sfarsit'] ?? ''));
$formEndTimeValue = $formEndTimeValueRaw !== '' ? substr($formEndTimeValueRaw, 0, 5) : '';
$formDurationMinutes = null;
$formDurationMinutesRaw = $formData['durata_cursa_minute'] ?? null;
if ($formDurationMinutesRaw !== null && $formDurationMinutesRaw !== '' && is_numeric((string) $formDurationMinutesRaw)) {
    $formDurationMinutes = max(0, (int) $formDurationMinutesRaw);
}
$formDurationPreviewText = $formDurationMinutes !== null
    ? 'Durata cursa calculata: ' . $formatDurationLabel($formDurationMinutes) . ' (' . $formDurationMinutes . ' min)'
    : 'Durata cursa se calculeaza automat dupa ora inceput/sfarsit.';
$formatRaceDateForDisplay = static function (string $value): string {
    $value = trim($value);
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        return $value;
    }

    return $date->format('d/m/Y');
};
$openRacesCount = (int) ($openRacesOverview['count'] ?? 0);
$openRacesRows = is_array($openRacesOverview['rows'] ?? null) ? $openRacesOverview['rows'] : [];
$openRacesSeverityCounts = is_array($openRacesOverview['severity_counts'] ?? null)
    ? $openRacesOverview['severity_counts']
    : ['critical' => 0, 'important' => 0, 'minor' => 0];
$openRacesPlates = is_array($openRacesOverview['plates'] ?? null) ? $openRacesOverview['plates'] : [];
$dispecerReturnUrl = (string) ($_SERVER['REQUEST_URI'] ?? build_query_url(['page' => 'dispecer_curse']));
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h4 mb-0">Dispecer curse</h2>
    <div class="d-flex gap-2">
        <a
            class="btn btn-outline-secondary"
            data-role="config-transport-link"
            data-base-href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config'])) ?>"
            href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config'])) ?>"
        >
            Configurare Transport
        </a>
    </div>
</div>


<?php if ($openRacesCount > 0): ?>
    <div class="dispatcher-open-races-alert-container">
        <button
            type="button"
            class="dispatcher-open-races-toggle"
            data-open-races-toggle
            aria-expanded="false"
            aria-haspopup="dialog"
            aria-controls="open-races-panel"
        >
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Atentie: curse cu informatii lipsa (<?= e((string) $openRacesCount) ?>)</span>
        </button>
    </div>

    <div class="dispatcher-open-races-modal-overlay d-none" id="open-races-panel" data-open-races-panel role="dialog" aria-modal="true" aria-labelledby="open-races-modal-title">
        <section class="dispatcher-open-races-modal orx-modal" role="document">
            <header class="orx-header">
                <div class="orx-header-icon" aria-hidden="true">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <div class="orx-header-titles">
                    <h3 id="open-races-modal-title">Atenție: curse cu informații lipsă (<span data-orx-header-count data-orx-total="<?= e((string) $openRacesCount) ?>"><?= e((string) $openRacesCount) ?></span>)</h3>
                    <p>Completează informațiile lipsă pentru a putea continua.</p>
                </div>
                <button type="button" class="orx-close" data-open-races-close aria-label="Închide">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </header>

            <div class="orx-toolbar">
                <div class="orx-tabs" role="tablist" aria-label="Filtrare după severitate">
                    <button type="button" class="orx-tab is-active" data-orx-severity-tab="" aria-pressed="true">
                        <i class="bi bi-list-ul" aria-hidden="true"></i>
                        <span>Toate (<?= e((string) $openRacesCount) ?>)</span>
                    </button>
                    <button type="button" class="orx-tab orx-tab-critical" data-orx-severity-tab="critical" aria-pressed="false">
                        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                        <span>Critice (<?= e((string) ($openRacesSeverityCounts['critical'] ?? 0)) ?>)</span>
                    </button>
                    <button type="button" class="orx-tab orx-tab-important" data-orx-severity-tab="important" aria-pressed="false">
                        <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                        <span>Importante (<?= e((string) ($openRacesSeverityCounts['important'] ?? 0)) ?>)</span>
                    </button>
                    <button type="button" class="orx-tab orx-tab-minor" data-orx-severity-tab="minor" aria-pressed="false">
                        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                        <span>Minore (<?= e((string) ($openRacesSeverityCounts['minor'] ?? 0)) ?>)</span>
                    </button>
                </div>
                <div class="orx-filters">
                    <div class="orx-select-wrap">
                        <i class="bi bi-truck" aria-hidden="true"></i>
                        <select class="orx-transport-select" data-orx-transport aria-label="Filtrare după tip transport">
                            <option value="">Tip transport</option>
                            <?php foreach ($transportTypes as $transportTypeValue => $transportTypeLabel): ?>
                                <option value="<?= e((string) $transportTypeValue) ?>"><?= e((string) $transportTypeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="orx-plates" data-orx-plates>
                        <button type="button" class="orx-plates-toggle" data-orx-plates-toggle aria-haspopup="listbox" aria-expanded="false">
                            <i class="bi bi-truck-front" aria-hidden="true"></i>
                            <span data-orx-plates-label>Nr. înmatriculare</span>
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </button>
                        <div class="orx-plates-menu" data-orx-plates-menu hidden>
                            <div class="orx-plates-search">
                                <i class="bi bi-search" aria-hidden="true"></i>
                                <input type="search" data-orx-plates-search placeholder="Caută nr. înmatriculare..." aria-label="Caută număr de înmatriculare">
                            </div>
                            <label class="orx-plates-option orx-plates-selectall">
                                <input type="checkbox" data-orx-plates-all>
                                <span>Selectează toate</span>
                            </label>
                            <div class="orx-plates-list" data-orx-plates-list>
                                <?php foreach ($openRacesPlates as $openRacesPlate): ?>
                                    <label class="orx-plates-option">
                                        <input type="checkbox" value="<?= e((string) $openRacesPlate) ?>" data-orx-plate-option>
                                        <span><?= e((string) $openRacesPlate) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="orx-plates-footer">
                                <span class="orx-plates-count" data-orx-plates-count>0 vehicule selectate</span>
                                <a href="#" data-orx-plates-clear>Șterge selecția</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p class="orx-subnote">Cursele de mai jos conțin informații incomplete care ar putea afecta facturarea sau raportarea.</p>

            <div class="orx-body dispatcher-open-races-modal-body">
                <?php
                $orxSeverityMeta = [
                    'critical' => ['badge' => 'Lipsuri', 'chip' => 'Obligatorie', 'summary' => 'Informații lipsă critice', 'icon' => 'bi-exclamation-circle-fill'],
                    'important' => ['badge' => 'Importanță', 'chip' => 'Importantă', 'summary' => 'Informații importante', 'icon' => 'bi-exclamation-circle-fill'],
                    'minor' => ['badge' => 'Minoră', 'chip' => 'Minoră', 'summary' => 'Informații lipsă minore', 'icon' => 'bi-info-circle-fill'],
                ];
                $orxFieldIcons = [
                    'ora_inceput' => 'bi-clock-history',
                    'ora_sfarsit' => 'bi-clock',
                    'data_incarcare' => 'bi-calendar-event',
                    'km_cursa' => 'bi-signpost',
                    'km_totali' => 'bi-signpost-split',
                    'cantitate_incarcata' => 'bi-box-seam',
                    'nr_clienti' => 'bi-people',
                    'zona_distributie_id' => 'bi-geo-alt',
                    'ore_aspirare' => 'bi-stopwatch',
                    'km_dislocare' => 'bi-signpost',
                    'tona_livrata' => 'bi-box-seam',
                    'tona_aspirata_lichida' => 'bi-droplet',
                    'tona_aspirata_gazoasa' => 'bi-wind',
                    'cantitate_prelevata' => 'bi-eyedropper',
                    'total_facturare' => 'bi-cash-coin',
                    'cheltuieli' => 'bi-receipt',
                    'cost_km_mixt' => 'bi-calculator',
                ];
                ?>
                <?php foreach ($openRacesRows as $openRace): ?>
                    <?php
                        $openRaceId = (int) ($openRace['id'] ?? 0);
                        $openPlate = trim((string) ($openRace['nr_inmatriculare'] ?? ''));
                        $openDriver = trim((string) ($openRace['sofer_nume'] ?? ''));
                        $openBeneficiar = trim((string) ($openRace['beneficiar_nume'] ?? ''));
                        $openTransportType = (string) ($openRace['tip_transport'] ?? '');
                        $openTransportLabel = $transportTypes[$openTransportType] ?? '-';
                        $openSeverity = (string) ($openRace['missing_severity'] ?? 'minor');
                        $openSeverityMeta = $orxSeverityMeta[$openSeverity] ?? $orxSeverityMeta['minor'];
                        $openMissing = is_array($openRace['missing_information'] ?? null) ? $openRace['missing_information'] : [];
                        $openTopSeverityItems = array_values(array_filter($openMissing, static fn ($item) => ($item['severity'] ?? '') === $openSeverity));
                        $openStartDate = trim((string) ($openRace['data_inceput'] ?? ''));
                        $openStartTime = trim((string) ($openRace['ora_inceput'] ?? ''));
                        $openUpdatedAt = trim((string) ($openRace['updated_at'] ?? ''));
                        $openExpenseCount = max(0, (int) ($openRace['expense_count'] ?? 0));
                        $openVehiclePhotoUrl = vehicle_image_url((string) ($openRace['poza_stocata'] ?? ''));
                        $openVehiclePhotoAlt = trim((string) ($openRace['poza_original'] ?? ''));
                        if ($openVehiclePhotoAlt === '') {
                            $openVehiclePhotoAlt = $openPlate !== '' ? ('Poza ' . $openPlate) : 'Poza vehicul';
                        }
                        $openTitle = trim($openPlate . ($openDriver !== '' ? ' - ' . $openDriver : ''));
                        if ($openTitle === '') {
                            $openTitle = 'Cursa #' . $openRaceId;
                        }
                        $openDetailsUrl = build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $openRaceId]);
                        $openExpenseUrl = $openDetailsUrl . '#expense-section';
                        $openSummaryListed = array_slice($openTopSeverityItems, 0, 3);
                        $openSummaryExtra = count($openTopSeverityItems) - count($openSummaryListed);
                    ?>
                    <article
                        class="orx-card"
                        data-open-race-card
                        data-orx-severity-value="<?= e($openSeverity) ?>"
                        data-orx-transport-value="<?= e($openTransportType) ?>"
                        data-orx-plate-value="<?= e($openPlate) ?>"
                    >
                        <div class="orx-card-main">
                            <div class="orx-card-avatar <?= $openVehiclePhotoUrl !== null ? 'has-photo' : '' ?>" aria-hidden="true">
                                <?php if ($openVehiclePhotoUrl !== null): ?>
                                    <img src="<?= e($openVehiclePhotoUrl) ?>" alt="<?= e($openVehiclePhotoAlt) ?>" loading="lazy">
                                <?php else: ?>
                                    <i class="bi bi-truck"></i>
                                <?php endif; ?>
                            </div>
                            <div class="orx-card-info">
                                <div class="orx-card-title">
                                    <strong><?= e($openTitle) ?></strong>
                                    <span class="orx-badge orx-badge-<?= e($openSeverity) ?>">
                                        <i class="bi bi-circle-fill" aria-hidden="true"></i>
                                        <?= e($openSeverityMeta['badge']) ?>
                                    </span>
                                </div>
                                <div class="orx-card-meta">
                                    <span><i class="bi bi-truck" aria-hidden="true"></i> <?= e((string) $openTransportLabel) ?></span>
                                    <span class="orx-meta-sep">|</span>
                                    <span><i class="bi bi-building" aria-hidden="true"></i> <?= e($openBeneficiar !== '' ? $openBeneficiar : '-') ?></span>
                                </div>
                                <div class="orx-card-meta">
                                    <span><i class="bi bi-calendar3" aria-hidden="true"></i> Actualizat: <?= e($openUpdatedAt !== '' ? format_datetime_ro($openUpdatedAt) : '-') ?></span>
                                    <span class="orx-meta-sep">|</span>
                                    <span><i class="bi bi-calendar-event" aria-hidden="true"></i> Start: <?= e($openStartDate !== '' ? format_date_ro($openStartDate) : '-') ?></span>
                                    <span class="orx-meta-sep">|</span>
                                    <span><i class="bi bi-clock" aria-hidden="true"></i> Ora: <?= e($openStartTime !== '' ? substr($openStartTime, 0, 5) : '-') ?></span>
                                    <span class="orx-meta-sep">|</span>
                                    <span><i class="bi bi-briefcase" aria-hidden="true"></i> Cheltuieli: <?= e((string) $openExpenseCount) ?></span>
                                </div>
                            </div>
                            <div class="orx-card-actions">
                                <a class="orx-btn orx-btn-outline" href="<?= e($openDetailsUrl) ?>">
                                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                                    <span>Detalii</span>
                                </a>
                                <div class="orx-summary orx-summary-<?= e($openSeverity) ?>">
                                    <div class="orx-summary-title">
                                        <i class="bi <?= e($openSeverityMeta['icon']) ?>" aria-hidden="true"></i>
                                        <?= e($openSeverityMeta['summary']) ?> (<?= e((string) count($openTopSeverityItems)) ?>)
                                    </div>
                                    <ul class="orx-summary-list">
                                        <?php foreach ($openSummaryListed as $openSummaryItem): ?>
                                            <li><i class="bi bi-circle-fill" aria-hidden="true"></i> <?= e((string) ($openSummaryItem['label'] ?? '')) ?></li>
                                        <?php endforeach; ?>
                                        <?php if ($openSummaryExtra > 0): ?>
                                            <li class="orx-summary-more">+ încă <?= e((string) $openSummaryExtra) ?></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <a class="orx-btn orx-btn-primary" href="<?= e($openExpenseUrl) ?>">
                                    <i class="bi bi-plus-circle" aria-hidden="true"></i>
                                    <span>Adaugă cheltuieli</span>
                                </a>
                                <button type="button" class="orx-expand" data-open-race-card-toggle aria-expanded="false" aria-label="Extinde lipsurile">
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="orx-card-details" data-open-race-card-details hidden>
                            <div class="orx-details-grid">
                                <div class="orx-need orx-need-<?= e($openSeverity) ?>">
                                    <div class="orx-need-head">
                                        <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                                        <strong>Ce trebuie completat?</strong>
                                    </div>
                                    <p class="orx-need-intro">
                                        <?php $orxMissingCount = count($openMissing); ?>
                                        <?= e($orxMissingCount === 1 ? 'Este 1 informație lipsă' : ('Sunt ' . $orxMissingCount . ' informații lipsă')) ?> care pot afecta finalizarea corectă a cursei.
                                    </p>
                                    <?php foreach ($openMissing as $openMissingItem): ?>
                                        <?php
                                            $orxItemSeverity = (string) ($openMissingItem['severity'] ?? 'minor');
                                            $orxItemMeta = $orxSeverityMeta[$orxItemSeverity] ?? $orxSeverityMeta['minor'];
                                            $orxItemField = (string) ($openMissingItem['field'] ?? '');
                                            $orxItemFocus = (string) ($openMissingItem['focus'] ?? '');
                                            $orxItemIcon = $orxFieldIcons[$orxItemField] ?? 'bi-exclamation-circle';
                                            if ($orxItemFocus === 'expenses') {
                                                $orxItemUrl = $openExpenseUrl;
                                                $orxItemLinkLabel = 'Adaugă cheltuieli';
                                            } elseif ($orxItemFocus !== '') {
                                                $orxItemUrl = build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $openRaceId, 'focus' => $orxItemFocus]);
                                                $orxItemLinkLabel = 'Mergi la datele cursei';
                                            } else {
                                                $orxItemUrl = $openDetailsUrl;
                                                $orxItemLinkLabel = 'Mergi la detaliile cursei';
                                            }
                                        ?>
                                        <div class="orx-need-item">
                                            <i class="bi <?= e($orxItemIcon) ?> orx-need-item-icon" aria-hidden="true"></i>
                                            <div class="orx-need-item-body">
                                                <div class="orx-need-item-title">
                                                    <strong><?= e((string) ($openMissingItem['label'] ?? '')) ?></strong>
                                                    <span class="orx-chip orx-chip-<?= e($orxItemSeverity) ?>"><?= e($orxItemMeta['chip']) ?></span>
                                                </div>
                                                <div class="orx-need-item-expl"><?= e((string) ($openMissingItem['explanation'] ?? '')) ?></div>
                                            </div>
                                            <a class="orx-goto" href="<?= e($orxItemUrl) ?>">
                                                <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                                <span><?= e($orxItemLinkLabel) ?></span>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="orx-context">
                                    <div class="orx-context-head">
                                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                                        <strong>Informații despre cursă</strong>
                                    </div>
                                    <?php
                                        $orxIsCompressor = $openTransportType === 'compresor';
                                        $orxGoodsLabels = [];
                                        foreach (array_filter(array_map('trim', explode(',', (string) ($openRace['tip_marfa'] ?? '')))) as $orxGoodsKey) {
                                            $orxGoodsLabels[] = (string) ($goodsTypeOptions[strtolower($orxGoodsKey)] ?? $orxGoodsKey);
                                        }
                                        $orxContextPairs = [];
                                        $orxContextPairs['Beneficiar transport'] = $openBeneficiar;
                                        $orxContextPairs['Șofer'] = $openDriver;
                                        $orxContextPairs['Tip marfă'] = implode(', ', $orxGoodsLabels);
                                        if ($orxIsCompressor) {
                                            $orxContextPairs['Loc plecare'] = trim((string) ($openRace['loc_plecare'] ?? ''));
                                            $orxContextPairs['Loc livrare'] = trim((string) ($openRace['loc_livrare'] ?? ''));
                                            $orxContextPairs['Km efectuați'] = trim((string) ($openRace['km_dislocare'] ?? ''));
                                        } else {
                                            $orxContextPairs['Loc încărcare'] = trim((string) ($openRace['loc_incarcare_nume'] ?? ''));
                                            $orxContextPairs[$openTransportType === 'primar' ? 'Loc descărcare' : 'Zona distribuție'] = trim((string) ($openRace['zona_distributie_nume'] ?? ''));
                                            $orxContextPairs['Km efectuați'] = trim((string) ($openRace[in_array($openTransportType, ['primar', 'primar_tona', 'primar_distributie'], true) ? 'km_totali' : 'km_cursa'] ?? ''));
                                        }
                                        $orxContextPairs['Capacitate transport'] = trim((string) ($openRace['capacitate_transport'] ?? ''));
                                        $orxContextPairs['Start'] = trim(($openStartDate !== '' ? format_date_ro($openStartDate) : '') . ($openStartTime !== '' ? ' ' . substr($openStartTime, 0, 5) : ''));
                                    ?>
                                    <dl class="orx-context-grid">
                                        <?php foreach ($orxContextPairs as $orxContextLabel => $orxContextValue): ?>
                                            <div class="orx-context-cell">
                                                <dt><?= e((string) $orxContextLabel) ?></dt>
                                                <dd><?= e($orxContextValue !== '' ? (string) $orxContextValue : '–') ?></dd>
                                            </div>
                                        <?php endforeach; ?>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
                <div class="orx-empty" data-orx-empty hidden>
                    <i class="bi bi-filter-circle" aria-hidden="true"></i>
                    Nicio cursă nu corespunde filtrelor selectate.
                </div>
            </div>

            <footer class="orx-footer">
                <div class="orx-footer-info">
                    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                    <div>
                        <strong>Completează informațiile lipsă pentru a finaliza facturarea curselor sau corectează erorile de raportare.</strong>
                        <span>Poți deschide cursa pentru editare și completa doar câmpurile marcate.</span>
                    </div>
                </div>
                <button type="button" class="orx-footer-close" data-open-races-close>Închide</button>
            </footer>
        </section>
    </div>
<?php endif; ?>

<?php
$resumeParentId = (int) ($formData['parent_cursa_id'] ?? 0);
$isResumeMode = $resumeParentId > 0;
$resumeSourceRow = isset($resumeSource) && is_array($resumeSource) ? $resumeSource : null;
?>
<div class="row g-3 align-items-start">
    <div class="col-12">
        <div class="card border-0 shadow-sm" id="add-race-form">
            <div class="card-header bg-white">
                <h3 class="h6 mb-0"><?= $isResumeMode ? 'Adaugă Cursă — continuare a cursei #' . e((string) $resumeParentId) : 'Adaugă Cursă' ?></h3>
            </div>
            <div class="card-body">
                <?php if ($isResumeMode): ?>
                    <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
                        <i class="bi bi-arrow-repeat mt-1" aria-hidden="true"></i>
                        <div>
                            <strong>Reluare cursă #<?= e((string) $resumeParentId) ?></strong>
                            <?php if ($resumeSourceRow !== null): ?>
                                <?php
                                $resumeSrcVehicle = trim((string) ($resumeSourceRow['nr_inmatriculare'] ?? ''));
                                $resumeSrcDriver = trim((string) ($resumeSourceRow['sofer_nume'] ?? ''));
                                $resumeSrcEndDate = trim((string) ($resumeSourceRow['data_sfarsit'] ?? ''));
                                $resumeSrcEndTime = substr(trim((string) ($resumeSourceRow['ora_sfarsit'] ?? '')), 0, 5);
                                ?>
                                <div class="small">
                                    Segment anterior: <?= e($resumeSrcVehicle !== '' ? $resumeSrcVehicle : 'vehicul -') ?>,
                                    șofer <?= e($resumeSrcDriver !== '' ? $resumeSrcDriver : '-') ?>,
                                    încheiat la <?= e($resumeSrcEndDate !== '' ? format_date_ro($resumeSrcEndDate) : '-') ?><?= e($resumeSrcEndTime !== '' ? ' ' . $resumeSrcEndTime : '') ?>.
                                </div>
                            <?php endif; ?>
                            <div class="small">Segmentul nou este o înregistrare separată: poți schimba șoferul sau vehiculul, iar calculele (tarife, costuri/km) se aplică vehiculului și șoferului din acest segment. Km, cantitățile și orele se introduc doar pentru acest segment.</div>
                            <a class="small" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Renunță la reluare</a>
                        </div>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'store'])) ?>" class="dispatcher-race-form" data-zone-tariffs='<?= e($zoneTariffJson) ?>' data-zone-extra-km-costs='<?= e($zoneExtraKmJson) ?>' data-distribution-route-tariffs='<?= e($distributionRouteTariffMapJson) ?>' data-primary-route-km-map='<?= e($primaryRouteKmMapJson) ?>' data-beneficiary-pricing='<?= e($beneficiaryPricingJson) ?>' data-load-location-tariffs='<?= e($loadLocationTariffJson) ?>' data-vehicle-default-load-locations='<?= e($vehicleDefaultLoadLocationJson) ?>' data-vehicle-default-distribution-zones='<?= e($vehicleDefaultDistributionZoneJson) ?>' data-vehicle-garages='<?= e($vehicleGarageJson) ?>' data-load-locations-by-beneficiary='<?= e($loadLocationsByBeneficiaryJson) ?>' data-distribution-zones-by-beneficiary='<?= e($distributionZonesByBeneficiaryJson) ?>' data-vehicle-default-load-locations-by-beneficiary='<?= e($vehicleDefaultLoadLocationByBeneficiaryJson) ?>' data-vehicle-default-distribution-zones-by-beneficiary='<?= e($vehicleDefaultDistributionZoneByBeneficiaryJson) ?>' data-compresor-vehicles-by-beneficiary='<?= e($compressorVehicleByBeneficiaryJson) ?>' data-active-driver-vehicle-ids='<?= e($activeDriverVehicleIdsJson) ?>' data-drivers-by-vehicle='<?= e($driversByVehicleJson) ?>' data-all-drivers='<?= e($allDriversJson) ?>' data-inactive-resource-status-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'inactive_resource_status'])) ?>" data-inactive-approval-mode="<?= (function_exists('can') && can('inactive_approvals', 'review')) ? 'admin' : 'user' ?>" data-inactive-approval-request-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'request_inactive_vehicle_approval'])) ?>" data-inactive-approval-cancel-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'cancel_inactive_vehicle_approval'])) ?>" data-inactive-trip-id="" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="parent_cursa_id" value="<?= e($isResumeMode ? (string) $resumeParentId : '') ?>">
                    <input type="hidden" name="vehicle_config_decision" value="" data-vehicle-config-decision>
                    <input type="hidden" name="inactive_approval_decision" value="<?= e((string) ($formData['inactive_approval_decision'] ?? '')) ?>" data-inactive-approval-decision>
                    <input type="hidden" name="inactive_approval_signature" value="" data-inactive-approval-signature>
            <input type="hidden" name="confirm_incomplete" value="">
                    <datalist id="race_time_options">
                        <?php for ($hour = 0; $hour < 24; $hour++): ?>
                            <?php foreach (['00', '15', '30', '45'] as $minute): ?>
                                <option value="<?= e(sprintf('%02d:%s', $hour, $minute)) ?>"></option>
                            <?php endforeach; ?>
                        <?php endfor; ?>
                    </datalist>

                    <div class="row g-3">
                        <?php if (isset($formErrors['inactive_resources'])): ?>
                            <div class="col-12">
                                <div class="alert alert-warning d-flex align-items-center gap-2 mb-0" role="alert">
                                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                    <span><?= e((string) $formErrors['inactive_resources']) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="col-12 col-md-6 dispatcher-top-field">
                            <label class="form-label" for="race_beneficiar_id">Beneficiar transport <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['beneficiar_id']) ? 'is-invalid' : '' ?>" id="race_beneficiar_id" name="beneficiar_id" required>
                                <option value="">-- Selecteaza --</option>
                                <?php foreach ($beneficiaries as $beneficiary): ?>
                                    <?php $beneficiaryId = (int) ($beneficiary['id'] ?? 0); ?>
                                    <option value="<?= e((string) $beneficiaryId) ?>" <?= (string) ($formData['beneficiar_id'] ?? '') === (string) $beneficiaryId ? 'selected' : '' ?>>
                                        <?= e((string) ($beneficiary['nume'] ?? '-')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($formErrors['beneficiar_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['beneficiar_id']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-top-field">
                            <label class="form-label" for="race_tip_transport">Tip Transport <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['tip_transport']) ? 'is-invalid' : '' ?>" id="race_tip_transport" name="tip_transport" data-role="tip-transport" required>
                                <option value="">-- Selecteaza --</option>
                                <?php foreach ($transportTypes as $value => $label): ?>
                                    <option value="<?= e((string) $value) ?>" <?= (string) ($formData['tip_transport'] ?? '') === (string) $value ? 'selected' : '' ?>>
                                        <?= e((string) $label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($formErrors['tip_transport'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tip_transport']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-top-field">
                            <label class="form-label" for="race_vehicle_id">Nr. Înmatriculare <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['vehicle_id']) ? 'is-invalid' : '' ?>" id="race_vehicle_id" name="vehicle_id" required title="Pentru Primar km/tone: se afiseaza vehicule active cu sofer asociat. Pentru celelalte tipuri: filtrare dupa beneficiar si configurari.">
                                <option value="">-- Selectează --</option>
                                <?php foreach (($raceVehicles ?? []) as $vehicle): ?>
                                    <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                                    <option
                                        value="<?= e((string) $vehicleId) ?>"
                                        data-capacitate-transport="<?= e((string) ($vehicle['capacitate_transport'] ?? '')) ?>"
                                        <?= (string) ($formData['vehicle_id'] ?? '') === (string) $vehicleId ? 'selected' : '' ?>
                                    >
                                        <?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?> - <?= e((string) ($vehicle['marca'] ?? '')) ?> <?= e((string) ($vehicle['model'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($formErrors['vehicle_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['vehicle_id']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-top-field">
                            <label class="form-label" for="race_driver_id">Sofer <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['driver_id']) ? 'is-invalid' : '' ?>" id="race_driver_id" name="driver_id" required title="Soferii se incarca automat dupa vehiculul selectat.">
                                <option value="">-- Selecteaza mai intai vehiculul --</option>
                                <?php
                                    $selectedVehicleForDriver = (int) ($formData['vehicle_id'] ?? 0);
                                    $selectedDriverId = (string) ($formData['driver_id'] ?? '');
                                    $driverOptions = $selectedVehicleForDriver > 0
                                        ? (array) ($driversByVehicle[$selectedVehicleForDriver] ?? [])
                                        : [];
                                ?>
                                <?php foreach ($driverOptions as $driver): ?>
                                    <?php $driverId = (int) ($driver['id'] ?? 0); ?>
                                    <?php if ($driverId <= 0) { continue; } ?>
                                    <option value="<?= e((string) $driverId) ?>" <?= $selectedDriverId === (string) $driverId ? 'selected' : '' ?>>
                                        <?= e((string) ($driver['nume'] ?? '-')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($formErrors['driver_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['driver_id']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-schedule-field" data-role="field-start-datetime">
                            <label class="form-label" for="race_start_datetime">Data si ora inceput <span class="text-danger">*</span></label>
                            <?php
                                $startDateValue = (string) ($formData['data_inceput'] ?? ($formData['data_cursa'] ?? ''));
                                $startDateDisplayValue = $formatRaceDateForDisplay($startDateValue);
                                $startDateTimeDisplayValue = trim($startDateDisplayValue . ($formStartTimeValue !== '' ? ' ' . $formStartTimeValue : ''));
                                $startDateTimeHasError = isset($formErrors['data_inceput']) || isset($formErrors['ora_inceput']);
                            ?>
                            <div class="dispatcher-datetime-field" data-role="start-datetime-field">
                                <div class="input-group dispatcher-datetime-input-group">
                                    <input
                                        type="text"
                                        class="form-control <?= $startDateTimeHasError ? 'is-invalid' : '' ?>"
                                        id="race_start_datetime"
                                        value="<?= e($startDateTimeDisplayValue) ?>"
                                        placeholder="dd/mm/yyyy HH:mm"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        maxlength="16"
                                        data-role="start-datetime-display"
                                        aria-label="Data si ora inceput"
                                    >
                                    <button type="button" class="btn btn-outline-secondary" data-role="start-datetime-toggle" aria-label="Deschide calendarul si ora de inceput" aria-expanded="false">
                                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="dispatcher-datetime-popover" data-role="start-datetime-popover" hidden></div>
                                <input
                                    type="hidden"
                                    data-role="race-date-ro"
                                    id="race_data_inceput"
                                    name="data_inceput"
                                    value="<?= e($startDateDisplayValue) ?>"
                                    required
                                >
                                <input
                                    type="hidden"
                                    id="race_ora_inceput"
                                    name="ora_inceput"
                                    value="<?= e($formStartTimeValue) ?>"
                                    data-role="ora-inceput"
                                >
                            </div>
                            <?php if (isset($formErrors['data_inceput'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['data_inceput']) ?></div><?php endif; ?>
                            <?php if (isset($formErrors['ora_inceput'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['ora_inceput']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-12 col-md-6 dispatcher-schedule-field" data-role="field-data-incarcare">
                            <label class="form-label" for="race_data_incarcare">Data incarcare</label>
                            <?php $loadingDateValue = (string) ($formData['data_incarcare'] ?? ''); ?>
                            <div class="input-group fleet-date-field">
                                <input type="text" class="form-control js-date-display-input <?= isset($formErrors['data_incarcare']) ? 'is-invalid' : '' ?>" id="race_data_incarcare" name="data_incarcare" value="<?= e($formatRaceDateForDisplay($loadingDateValue)) ?>" placeholder="dd/mm/yyyy" inputmode="numeric" maxlength="10" autocomplete="off" data-date-picker-id="race_data_incarcare_picker">
                                <button type="button" class="btn btn-outline-secondary js-date-picker-button" data-date-picker-target="race_data_incarcare_picker" aria-label="Deschide calendarul pentru data incarcarii"><i class="bi bi-calendar3" aria-hidden="true"></i></button>
                                <input type="date" id="race_data_incarcare_picker" class="fleet-date-picker-native" value="<?= e($loadingDateValue) ?>" tabindex="-1" aria-hidden="true">
                            </div>
                            <?php if (isset($formErrors['data_incarcare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['data_incarcare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-schedule-field">
                            <label class="form-label" for="race_end_datetime">Data si ora sfarsit <span class="text-danger">*</span></label>
                            <?php
                                $endDateValue = (string) ($formData['data_sfarsit'] ?? ($formData['data_cursa'] ?? ''));
                                $endDateDisplayValue = $formatRaceDateForDisplay($endDateValue);
                                $endDateTimeDisplayValue = trim($endDateDisplayValue . ($formEndTimeValue !== '' ? ' ' . $formEndTimeValue : ''));
                                $endDateTimeHasError = isset($formErrors['data_sfarsit']) || isset($formErrors['ora_sfarsit']);
                            ?>
                            <div class="dispatcher-datetime-field" data-role="end-datetime-field">
                                <div class="input-group dispatcher-datetime-input-group">
                                    <input
                                        type="text"
                                        class="form-control <?= $endDateTimeHasError ? 'is-invalid' : '' ?>"
                                        id="race_end_datetime"
                                        value="<?= e($endDateTimeDisplayValue) ?>"
                                        placeholder="dd/mm/yyyy HH:mm"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        maxlength="16"
                                        data-role="end-datetime-display"
                                        aria-label="Data si ora sfarsit"
                                        title="<?= e($formDurationPreviewText) ?>"
                                    >
                                    <button type="button" class="btn btn-outline-secondary" data-role="end-datetime-toggle" aria-label="Deschide calendarul si ora de sfarsit" aria-expanded="false">
                                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <div class="dispatcher-datetime-popover" data-role="end-datetime-popover" hidden></div>
                                <input
                                    type="hidden"
                                    data-role="race-date-ro"
                                    id="race_data_sfarsit"
                                    name="data_sfarsit"
                                    value="<?= e($endDateDisplayValue) ?>"
                                    required
                                >
                                <input
                                    type="hidden"
                                    id="race_ora_sfarsit"
                                    name="ora_sfarsit"
                                    value="<?= e($formEndTimeValue) ?>"
                                    data-role="ora-sfarsit"
                                    title="<?= e($formDurationPreviewText) ?>"
                                >
                            </div>
                            <?php if (isset($formErrors['data_sfarsit'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['data_sfarsit']) ?></div><?php endif; ?>
                            <?php if (isset($formErrors['ora_sfarsit'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['ora_sfarsit']) ?></div><?php endif; ?>
                            <div class="form-text d-none dispatcher-hover-note" data-role="durata-cursa-hint" data-default-text="<?= e($formDurationPreviewText) ?>"><?= e($formDurationPreviewText) ?></div>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-primary-grid-field" data-role="field-loc-incarcare">
                            <label class="form-label" for="race_loc_incarcare_id">Loc Încărcare <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['loc_incarcare_id']) ? 'is-invalid' : '' ?>" id="race_loc_incarcare_id" name="loc_incarcare_id" required title="Selecteaza locul de incarcare pentru cursa.">
                                <option value="">-- Selectează --</option>
                                <?php foreach ($loadLocations as $location): ?>
                                    <?php $locationId = (int) ($location['id'] ?? 0); ?>
                                    <option value="<?= e((string) $locationId) ?>" <?= (string) ($formData['loc_incarcare_id'] ?? '') === (string) $locationId ? 'selected' : '' ?>>
                                        <?= e((string) ($location['nume'] ?? '-')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($formErrors['loc_incarcare_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['loc_incarcare_id']) ?></div><?php endif; ?>
                            <div class="form-text text-muted d-none dispatcher-hover-note" data-role="distributie-note-loc">
                                Pentru Distributie / Primar+Distributie: regula de ruta are prioritate pe perechile configurate bidirectional (Loc ? Zona). Daca nu exista pereche, se aplica fallback loc/zona/beneficiar.
                            </div>
                            <div class="form-text text-muted d-none dispatcher-hover-note" data-role="primar-note-loc">
                                Pentru Primar km / Primar tone: sunt afisate doar locurile din Setari Primar, iar Km efectuati este luat automat din perechea Loc ? Zona.
                            </div>
                        </div>

                        <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-grid-field" data-role="field-loc-plecare">
                            <label class="form-label" for="race_loc_plecare">Loc plecare <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($formErrors['loc_plecare']) ? 'is-invalid' : '' ?>" id="race_loc_plecare" name="loc_plecare" value="<?= e((string) ($formData['loc_plecare'] ?? '')) ?>" data-role="loc-plecare">
                            <?php if (isset($formErrors['loc_plecare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['loc_plecare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-grid-field" data-role="field-loc-aspirare">
                            <label class="form-label" for="race_loc_aspirare">Loc aspirare <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($formErrors['loc_aspirare']) ? 'is-invalid' : '' ?>" id="race_loc_aspirare" name="loc_aspirare" value="<?= e((string) ($formData['loc_aspirare'] ?? '')) ?>" data-role="loc-aspirare">
                            <?php if (isset($formErrors['loc_aspirare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['loc_aspirare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-grid-field" data-role="field-loc-livrare">
                            <label class="form-label" for="race_loc_livrare">Loc livrare <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($formErrors['loc_livrare']) ? 'is-invalid' : '' ?>" id="race_loc_livrare" name="loc_livrare" value="<?= e((string) ($formData['loc_livrare'] ?? '')) ?>" data-role="loc-livrare">
                            <?php if (isset($formErrors['loc_livrare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['loc_livrare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-grid-field" data-role="field-loc-livrare-cursa">
                            <label class="form-label" for="race_loc_livrare_cursa">Loc inchidere cursa <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($formErrors['loc_livrare_cursa']) ? 'is-invalid' : '' ?>" id="race_loc_livrare_cursa" name="loc_livrare_cursa" value="<?= e((string) ($formData['loc_livrare_cursa'] ?? '')) ?>" data-role="loc-livrare-cursa">
                            <?php if (isset($formErrors['loc_livrare_cursa'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['loc_livrare_cursa']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-primary-grid-field dispatcher-compressor-grid-field dispatcher-compressor-metric-field" data-role="field-tip-marfa">
                            <label class="form-label" for="race_tip_marfa">Tip marfa <span class="text-danger">*</span></label>
                            <div class="dropdown transport-multiselect-dropdown goods-multiselect-dropdown" data-role="goods-type-dropdown">
                                <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start transport-multiselect-toggle <?= isset($formErrors['tip_marfa']) ? 'is-invalid' : '' ?>" type="button" id="race_tip_marfa" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Poti selecta unul sau mai multe tipuri de marfa.">
                                    <span class="goods-multiselect-label" data-default-label="-- Selecteaza --"><?= e($selectedGoodsTypeButtonLabel) ?></span>
                                </button>
                                <div class="dropdown-menu w-100 transport-multiselect-menu p-2" aria-labelledby="race_tip_marfa">
                                    <?php foreach (($goodsTypeOptions ?? []) as $goodsTypeKey => $goodsTypeLabel): ?>
                                        <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 transport-multiselect-option">
                                            <input class="form-check-input m-0" type="checkbox" name="tip_marfa[]" value="<?= e((string) $goodsTypeKey) ?>" <?= in_array((string) $goodsTypeKey, $selectedGoodsTypeKeys, true) ? 'checked' : '' ?>>
                                            <span><?= e((string) $goodsTypeLabel) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php if (isset($formErrors['tip_marfa'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tip_marfa']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-primary-grid-field" data-role="field-cantitate">
                            <label class="form-label" for="race_cantitate_incarcata">Cantitate Încărcată</label>
                            <input type="number" class="form-control <?= isset($formErrors['cantitate_incarcata']) ? 'is-invalid' : '' ?>" id="race_cantitate_incarcata" name="cantitate_incarcata" step="0.01" min="0" value="<?= e((string) ($formData['cantitate_incarcata'] ?? '')) ?>" data-role="cantitate" title="Valoarea introdusa este folosita direct in calcule, fara conversie automata.">
                            <?php if (isset($formErrors['cantitate_incarcata'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['cantitate_incarcata']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-primary-grid-field" data-role="field-capacitate-transport">
                            <label class="form-label" for="race_capacitate_transport">Capacitate transport</label>
                            <input type="number" class="form-control <?= isset($formErrors['capacitate_transport']) ? 'is-invalid' : '' ?>" id="race_capacitate_transport" name="capacitate_transport" step="0.01" min="0" value="<?= e((string) ($formData['capacitate_transport'] ?? '')) ?>" data-role="capacitate-transport" readonly title="Se completeaza automat din fisa vehiculului.">
                            <?php if (isset($formErrors['capacitate_transport'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['capacitate_transport']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6" data-role="field-km">
                            <label class="form-label" for="race_km_cursa" data-role="km-label" data-default-label="Km efectuati" data-primary-km-label="Km agreati"><?= $isAgreedKmNamingSelected ? 'Km agreati' : 'Km efectuati' ?></label>
                            <input type="number" class="form-control <?= isset($formErrors['km_cursa']) ? 'is-invalid' : '' ?>" id="race_km_cursa" name="km_cursa" min="0" step="1" value="<?= e((string) ($formData['km_cursa'] ?? '')) ?>" data-role="km">
                            <?php if (isset($formErrors['km_cursa'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['km_cursa']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isDistributionSelected ? '' : 'd-none' ?>" data-role="field-nr-clienti">
                            <label class="form-label" for="race_nr_clienti">Nr. Clienți</label>
                            <input type="number" class="form-control <?= isset($formErrors['nr_clienti']) ? 'is-invalid' : '' ?>" id="race_nr_clienti" name="nr_clienti" min="0" step="1" value="<?= e((string) ($formData['nr_clienti'] ?? '')) ?>">
                            <?php if (isset($formErrors['nr_clienti'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['nr_clienti']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6" data-role="field-zona">
                            <label class="form-label" for="race_zona_distributie_id" data-role="zona-label" data-default-label="Zona distributie" data-primary-label="Zona descarcare" data-primary-km-label="Loc descarcare">Zona distributie</label>
                            <select class="form-select <?= isset($formErrors['zona_distributie_id']) ? 'is-invalid' : '' ?>" id="race_zona_distributie_id" name="zona_distributie_id" data-role="zona" title="Selecteaza zona de distributie/descarcare pentru ruta.">
                                <option value="">-- Selectează --</option>
                                <?php foreach ($distributionZones as $zone): ?>
                                    <?php $zoneId = (int) ($zone['id'] ?? 0); ?>
                                    <?php $zoneExtraKmCost = (float) ($zone['cost_extra_km'] ?? 0); ?>
                                    <option value="<?= e((string) $zoneId) ?>" <?= (string) ($formData['zona_distributie_id'] ?? '') === (string) $zoneId ? 'selected' : '' ?>>
                                        <?= e((string) ($zone['nume'] ?? '-')) ?>
                                        (tarif zonă: <?= e(format_number_ro((float) ($zone['tarif_distributie'] ?? 0), 2)) ?> lei<?php if ($zoneExtraKmCost > 0): ?>, extra km: <?= e(format_number_ro($zoneExtraKmCost, 2)) ?> lei/km<?php endif; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($formErrors['zona_distributie_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['zona_distributie_id']) ?></div><?php endif; ?>
                            <div class="form-text text-muted d-none dispatcher-hover-note" data-role="distributie-note-zone">
                                Prioritate calcul: regula de ruta (Loc ? Zona), apoi regulile loc/zona, apoi fallback beneficiar. Distributie = Cantitate × Tariful activ; Primar+Distributie = Cantitate × Tariful activ + Km × Cost extra/km activ.
                            </div>
                            <div class="form-text text-muted d-none dispatcher-hover-note" data-role="primar-note-zone">
                                Pentru Primar km / Primar tone, selectia Loc ? Zona este filtrata din Setari Primar si se aplica bidirectional.
                            </div>
                        </div>

                        <div class="col-12 col-md-6 <?= $isKmTotalSelected ? '' : 'd-none' ?>" data-role="field-km-totali">
                            <label class="form-label" for="race_km_totali" data-role="km-total-label" data-default-label="Km totali" data-primary-km-label="Km efectuati"><?= $isAgreedKmNamingSelected ? 'Km efectuati' : 'Km totali' ?></label>
                            <input type="number" class="form-control <?= isset($formErrors['km_totali']) ? 'is-invalid' : '' ?>" id="race_km_totali" name="km_totali" min="0" step="1" value="<?= e((string) ($formData['km_totali'] ?? '')) ?>" data-role="km-totali">
                            <?php if (isset($formErrors['km_totali'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['km_totali']) ?></div><?php endif; ?>
                            <div class="form-text text-muted <?= $isPrimaryDistributionSelected ? '' : 'd-none' ?>" data-role="km-distributie-calculation">Cost/km Distributie (calcul): Km distributie = Km efectuati - Km agreati; Cost/km Distributie = Cost distributie (Pret tona x tone) / Km distributie.</div>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-compressor-metric-field" data-role="field-ore-aspirare">
                            <label class="form-label" for="race_ore_aspirare">Ore aspirare</label>
                            <input type="text" class="form-control <?= isset($formErrors['ore_aspirare']) ? 'is-invalid' : '' ?>" id="race_ore_aspirare" name="ore_aspirare" value="<?= e((string) ($formData['ore_aspirare'] ?? '')) ?>" data-role="ore-aspirare" placeholder="ex: 2h sau 2" title="1h = 40 km echivalenti pentru scaderea Km revizie.">
                            <?php if (isset($formErrors['ore_aspirare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['ore_aspirare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-metric-field" data-role="field-tona-aspirata-lichida">
                            <label class="form-label" for="race_tona_aspirata_lichida">Tona lichida aspirata</label>
                            <input type="number" class="form-control <?= isset($formErrors['tona_aspirata_lichida']) ? 'is-invalid' : '' ?>" id="race_tona_aspirata_lichida" name="tona_aspirata_lichida" step="0.01" min="0" value="<?= e((string) ($formData['tona_aspirata_lichida'] ?? '')) ?>" data-role="tona-aspirata-lichida">
                            <?php if (isset($formErrors['tona_aspirata_lichida'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tona_aspirata_lichida']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-metric-field" data-role="field-tona-aspirata-gazoasa">
                            <label class="form-label" for="race_tona_aspirata_gazoasa">Tona gazoasa aspirata</label>
                            <input type="number" class="form-control <?= isset($formErrors['tona_aspirata_gazoasa']) ? 'is-invalid' : '' ?>" id="race_tona_aspirata_gazoasa" name="tona_aspirata_gazoasa" step="0.01" min="0" value="<?= e((string) ($formData['tona_aspirata_gazoasa'] ?? '')) ?>" data-role="tona-aspirata-gazoasa">
                            <?php if (isset($formErrors['tona_aspirata_gazoasa'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tona_aspirata_gazoasa']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-compressor-metric-field" data-role="field-tona-livrata">
                            <label class="form-label" for="race_tona_livrata">Cantitate livrata (tone)</label>
                            <input type="number" class="form-control <?= isset($formErrors['tona_livrata']) ? 'is-invalid' : '' ?>" id="race_tona_livrata" name="tona_livrata" step="0.01" min="0" value="<?= e((string) ($formData['tona_livrata'] ?? '')) ?>" data-role="tona-livrata">
                            <?php if (isset($formErrors['tona_livrata'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tona_livrata']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-compressor-metric-field" data-role="field-km-dislocare">
                            <label class="form-label" for="race_km_dislocare">Km efectuati</label>
                            <input type="number" class="form-control <?= isset($formErrors['km_dislocare']) ? 'is-invalid' : '' ?>" id="race_km_dislocare" name="km_dislocare" step="0.01" min="0" value="<?= e((string) ($formData['km_dislocare'] ?? '')) ?>" data-role="km-dislocare">
                            <?php if (isset($formErrors['km_dislocare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['km_dislocare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-compressor-metric-field" data-role="preview-total-field">
                            <label class="form-label">Total Facturare (estimare)</label>
                            <div class="dispatcher-total-preview" data-role="total-preview">0,00 lei</div>
                        </div>

                        <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-primar-field">
                            <label class="form-label">Cost/km Primar</label>
                            <div class="dispatcher-total-preview" data-role="cost-km-primar-preview">0,00 lei/km</div>
                        </div>

                        <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-distributie-field">
                            <label class="form-label">Cost/km Distribu?ie</label>
                            <div class="dispatcher-total-preview" data-role="cost-km-distributie-preview">0,00 lei/km</div>
                        </div>

                        <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-mixt-field">
                            <label class="form-label">Cost/km Mixt</label>
                            <div class="dispatcher-total-preview" data-role="cost-km-mixt-preview">0,00 lei/km</div>
                        </div>

                        <?php /* Statusul de facturare nu se mai alege la creare: orice cursa noua intra automat "in curs de facturare". Se schimba doar din Centralizator Facturare. */ ?>

                        <div class="col-12">
                            <label class="form-label" for="race_observatii">Observații</label>
                            <textarea class="form-control <?= isset($formErrors['observatii']) ? 'is-invalid' : '' ?>" id="race_observatii" name="observatii" rows="3"><?= e((string) ($formData['observatii'] ?? '')) ?></textarea>
                            <?php if (isset($formErrors['observatii'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['observatii']) ?></div><?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Adaugă Cursă</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <div class="d-flex align-items-center gap-2">
                    <h3 class="h6 mb-0">Filtre</h3>
                    <?php if ($hasActiveFilters): ?>
                        <span class="badge rounded-pill text-bg-primary">active</span>
                    <?php endif; ?>
                </div>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary dispatcher-filter-toggle"
                    data-dispatcher-filter-toggle
                    aria-expanded="false"
                    aria-controls="dispatcher-filter-panel"
                >
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    <span>Afiseaza filtre</span>
                    <i class="bi bi-chevron-down dispatcher-filter-toggle-chevron" aria-hidden="true"></i>
                </button>
            </div>
            <div class="card-body" id="dispatcher-filter-panel" hidden>
                <form method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="page" value="dispecer_curse">
                    <input type="hidden" name="action" value="index">

                    <div class="col-12 col-xl-4">
                        <label class="form-label" for="filter_q">Căutare</label>
                        <input type="text" class="form-control" id="filter_q" name="q" value="<?= e((string) $search) ?>" placeholder="Nr. auto, zonă, observații...">
                    </div>

                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label" for="filter_tip_transport">Tip Transport</label>
                        <select class="form-select" id="filter_tip_transport" name="tip_transport">
                            <option value="">Toate</option>
                            <?php foreach ($transportTypes as $value => $label): ?>
                                <option value="<?= e((string) $value) ?>" <?= (string) ($filters['tip_transport'] ?? '') === (string) $value ? 'selected' : '' ?>>
                                    <?= e((string) $label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label" for="filter_vehicle_id">Nr. Înmatriculare</label>
                        <select class="form-select" id="filter_vehicle_id" name="vehicle_id">
                            <option value="">Toate</option>
                            <?php foreach (($filterVehicles ?? []) as $vehicle): ?>
                                <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                                <option value="<?= e((string) $vehicleId) ?>" <?= (string) ($filters['vehicle_id'] ?? '') === (string) $vehicleId ? 'selected' : '' ?>>
                                    <?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label" for="filter_loc_incarcare_id">Loc Încărcare</label>
                        <select class="form-select" id="filter_loc_incarcare_id" name="loc_incarcare_id">
                            <option value="">Toate</option>
                            <?php foreach ($loadLocations as $location): ?>
                                <?php $locationId = (int) ($location['id'] ?? 0); ?>
                                <option value="<?= e((string) $locationId) ?>" <?= (string) ($filters['loc_incarcare_id'] ?? '') === (string) $locationId ? 'selected' : '' ?>>
                                    <?= e((string) ($location['nume'] ?? '-')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    
                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label" for="filter_beneficiar_id">Beneficiar transport</label>
                        <select class="form-select" id="filter_beneficiar_id" name="beneficiar_id">
                            <option value="">Toate</option>
                            <?php foreach ($beneficiaries as $beneficiary): ?>
                                <?php $beneficiaryId = (int) ($beneficiary['id'] ?? 0); ?>
                                <option value="<?= e((string) $beneficiaryId) ?>" <?= (string) ($filters['beneficiar_id'] ?? '') === (string) $beneficiaryId ? 'selected' : '' ?>>
                                    <?= e((string) ($beneficiary['nume'] ?? '-')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label" for="filter_zona_distributie_id">Zonă Distribuție</label>
                        <select class="form-select" id="filter_zona_distributie_id" name="zona_distributie_id">
                            <option value="">Toate</option>
                            <?php foreach ($distributionZones as $zone): ?>
                                <?php $zoneId = (int) ($zone['id'] ?? 0); ?>
                                <option value="<?= e((string) $zoneId) ?>" <?= (string) ($filters['zona_distributie_id'] ?? '') === (string) $zoneId ? 'selected' : '' ?>>
                                    <?= e((string) ($zone['nume'] ?? '-')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label" for="filter_data_start">Data de la</label>
                        <input type="date" class="form-control" id="filter_data_start" name="data_start" value="<?= e((string) ($filters['data_start'] ?? '')) ?>">
                    </div>

                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label" for="filter_data_end">Data până la</label>
                        <input type="date" class="form-control" id="filter_data_end" name="data_end" value="<?= e((string) ($filters['data_end'] ?? '')) ?>">
                    </div>

                    <div class="col-12 col-md-6 col-xl-2 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary">Aplică filtre</button>
                        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Resetează</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mt-3 dispatcher-races-card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2 flex-wrap dispatcher-races-card-header">
        <h3 class="h6 mb-0">Desfasurator curse</h3>
        <div class="d-flex align-items-center gap-2">
            <div class="dispatcher-column-manager" data-dispatcher-column-manager>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary dispatcher-column-toggle"
                    data-dispatcher-columns-toggle
                    aria-haspopup="dialog"
                    aria-expanded="false"
                    aria-controls="dispatcher-columns-panel"
                >
                    <i class="bi bi-layout-three-columns" aria-hidden="true"></i>
                    <span>Coloane</span>
                    <i class="bi bi-chevron-down dispatcher-column-toggle-chevron" aria-hidden="true"></i>
                </button>
                <div
                    class="dispatcher-column-panel"
                    id="dispatcher-columns-panel"
                    data-dispatcher-columns-panel
                    role="dialog"
                    aria-label="Alege coloanele afisate si ordinea lor"
                    tabindex="-1"
                    hidden
                >
                    <div class="dispatcher-column-panel-header">
                        <div>
                            <strong>Coloane tabel</strong>
                            <span>Vizibilitate si ordine</span>
                        </div>
                    </div>
                    <div class="dispatcher-column-list" data-dispatcher-columns-list></div>
                    <div class="dispatcher-column-panel-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-dispatcher-columns-reset>Reseteaza</button>
                    </div>
                </div>
            </div>
            <button
                type="submit"
                class="btn btn-sm btn-outline-danger"
                id="bulk-race-delete-btn"
                form="bulk-race-delete-form"
                disabled
                data-confirm="Ștergi cursele selectate? Cursele vor fi mutate în Curse șterse și vor putea fi restaurate ulterior."
            >
                Sterge selectate
            </button>
            <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                id="races-reset-filters-btn"
                title="Reseteaza toate filtrele din antetul tabelului"
            >
                <i class="bi bi-funnel" aria-hidden="true"></i>
                Reseteaza filtre
            </button>
            <small class="text-muted">Total: <?= e((string) $pagination['total_rows']) ?> curse</small>
        </div>
    </div>
    <div class="card-body p-0">
        <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'delete_bulk'])) ?>" id="bulk-race-delete-form" class="d-none">
            <?= csrf_field() ?>
            <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
        </form>
        <div class="table-responsive dispatcher-races-table-wrap table-container">
            <table class="table table-hover align-middle mb-0 dispatcher-races-table curse-table table-dispecer" data-dispatcher-column-table>
                <colgroup>
                    <col class="col-plate">
                    <col class="col-registration-details">
                    <col class="col-driver">
                    <col class="col-type">
                    <col class="col-loading-date">
                    <col class="col-interval">
                    <col class="col-duration">
                    <col class="col-diurna">
                    <col class="col-route">
                    <col class="col-beneficiary">
                    <col class="col-goods-type">
                    <col class="col-activity">
                    <col class="col-financial">
                    <col class="col-beneficiary">
                    <col class="col-actions">
                </colgroup>
                <thead>
                <tr>
                    <th class="col-plate">
                        <label class="dispatcher-plate-head mb-0" for="bulk-race-select-all">
                            <input type="checkbox" class="form-check-input m-0" id="bulk-race-select-all" aria-label="Selecteaza toate cursele">
                            <span>Nr. Înmatriculare</span>
                        </label>
                    </th>
                    <th class="col-registration-details">Detalii inregistrare</th>
                    <th class="col-driver">Sofer</th>
                    <th class="col-type">Tip Transport</th>
                    <th class="col-loading-date text-center">Data incarcare</th>
                    <th class="col-interval">Interval</th>
                    <th class="col-duration text-end">Durata cursa</th>
                    <th class="col-diurna text-end">Diurna</th>
                    <th class="col-route">Traseu</th>
                    <th class="col-beneficiary">Beneficiar</th>
                    <th class="col-goods-type">Tip marfă</th>
                    <th class="col-activity">Activitate</th>
                    <th class="col-financial">Financiar</th>
                    <th class="col-beneficiary">Observatii</th>
                    <th class="col-actions text-center">Actiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="15" class="text-center text-muted py-4">Nu există curse înregistrate.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $raceId = (int) ($row['id'] ?? 0);
                        $transportType = (string) ($row['tip_transport'] ?? '');
                        $transportLabel = $transportTypes[$transportType] ?? '-';
                        $transportBadgeClass = match ($transportType) {
                            'distributie' => 'bg-warning text-dark',
                            'compresor' => 'bg-success',
                            'primar', 'primar_tona', 'primar_km' => 'bg-primary',
                            'primar_distributie', 'mixt' => 'bg-info text-dark',
                            default => 'bg-secondary',
                        };
                        $locIncarcareRaw = trim((string) ($row['loc_incarcare_nume'] ?? ''));
                        $locIncarcare = $locIncarcareRaw !== '' ? $locIncarcareRaw : '-';
                        $locPlecare = trim((string) ($row['loc_plecare'] ?? ''));
                        if ($transportType === 'compresor' && $locPlecare === '' && $locIncarcare !== '-') {
                            $locPlecare = $locIncarcare;
                        }
                        $locDescarcareRaw = trim((string) (($row['loc_descarcare_nume'] ?? '') !== '' ? $row['loc_descarcare_nume'] : ($row['loc_descarcare'] ?? '')));
                        $locAspirare = trim((string) ($row['loc_aspirare'] ?? ''));
                        $locLivrare = trim((string) ($row['loc_livrare'] ?? ''));
                        $locLivrareCursa = trim((string) ($row['loc_livrare_cursa'] ?? ''));
                        $beneficiarNume = (string) (($row['beneficiar_nume'] ?? '') !== '' ? $row['beneficiar_nume'] : '-');
                        $goodsTypeLabels = [];
                        $goodsTypeRaw = (string) ($row['tip_marfa'] ?? '');
                        if ($goodsTypeRaw !== '') {
                            foreach (explode(',', $goodsTypeRaw) as $goodsTypePart) {
                                $goodsTypeKey = trim(strtolower((string) $goodsTypePart));
                                if ($goodsTypeKey === '' || !isset($goodsTypeOptions[$goodsTypeKey])) {
                                    continue;
                                }
                                $goodsTypeLabels[$goodsTypeKey] = (string) $goodsTypeOptions[$goodsTypeKey];
                            }
                        }
                        $goodsTypeLabel = $goodsTypeLabels !== [] ? implode(', ', array_values($goodsTypeLabels)) : '-';
                        $driverName = trim((string) ($row['sofer_nume'] ?? ''));
                        if ($driverName === '') {
                            $driverName = '-';
                        }
                        $zonaDistributieRaw = trim((string) ($row['zona_distributie_nume'] ?? ''));
                        $zonaDescarcareRaw = trim((string) (($row['zona_descarcare_nume'] ?? '') !== '' ? $row['zona_descarcare_nume'] : ($row['zona_descarcare'] ?? '')));
                        $zonaDistributie = $zonaDistributieRaw !== '' ? $zonaDistributieRaw : '-';
                        $routeParts = [];
                        $addRoutePart = static function (array &$parts, string $label, string $value): void {
                            $normalized = trim($value);
                            if ($normalized === '' || $normalized === '-') {
                                return;
                            }
                            $parts[] = ['label' => $label, 'value' => $normalized];
                        };
                        $addRoutePart($routeParts, 'Loc incarcare', $locIncarcareRaw);
                        $addRoutePart($routeParts, 'Loc descarcare', $locDescarcareRaw);
                        $addRoutePart($routeParts, 'Zona descarcare', $zonaDescarcareRaw);
                        $addRoutePart($routeParts, 'Zona distributie', $zonaDistributieRaw);
                        $addRoutePart($routeParts, 'Loc plecare', $locPlecare);
                        $addRoutePart($routeParts, 'Loc aspirare', $locAspirare);
                        $addRoutePart($routeParts, 'Loc livrare', $locLivrare);
                        $addRoutePart($routeParts, 'Loc inchidere cursa', $locLivrareCursa);
                        $routeTitle = '-';
                        if ($routeParts !== []) {
                            $routeTitleParts = [];
                            foreach ($routeParts as $routePart) {
                                $routeTitleParts[] = (string) ($routePart['label'] ?? '') . ': ' . (string) ($routePart['value'] ?? '');
                            }
                            $routeTitle = implode(' | ', $routeTitleParts);
                        }
                        $dataIncarcare = trim((string) ($row['data_incarcare'] ?? ''));
                        $dataIncarcareLabel = $dataIncarcare !== '' ? format_date_ro($dataIncarcare) : '-';
                        $dataInceput = (string) (($row['data_inceput'] ?? '') !== '' ? $row['data_inceput'] : ($row['data_cursa'] ?? ''));
                        $dataSfarsit = (string) (($row['data_sfarsit'] ?? '') !== '' ? $row['data_sfarsit'] : $dataInceput);
                        $oraInceputRaw = trim((string) ($row['ora_inceput'] ?? ''));
                        $oraSfarsitRaw = trim((string) ($row['ora_sfarsit'] ?? ''));
                        $oraInceput = $oraInceputRaw !== '' ? substr($oraInceputRaw, 0, 5) : '-';
                        $oraSfarsit = $oraSfarsitRaw !== '' ? substr($oraSfarsitRaw, 0, 5) : '-';
                        $intervalStartLabel = format_date_ro($dataInceput) . ($oraInceput !== '-' ? (' ' . $oraInceput) : '');
                        $intervalEndLabel = format_date_ro($dataSfarsit) . ($oraSfarsit !== '-' ? (' ' . $oraSfarsit) : '');
                        $intervalLabel = $intervalStartLabel . ' - ' . $intervalEndLabel;
                        $intervalParts = [
                            ['label' => 'Start', 'value' => $intervalStartLabel],
                            ['label' => 'Sfarsit', 'value' => $intervalEndLabel],
                        ];
                        $observatii = trim((string) ($row['observatii'] ?? ''));
                        $createdAtDisplay = format_datetime_ro((string) ($row['created_at'] ?? ''));
                        $createdByName = trim((string) ($row['creat_de_nume'] ?? ''));
                        if ($createdByName === '') {
                            $createdByName = '-';
                        }
                        $updatedAuditAtDisplay = format_datetime_ro((string) ($row['actualizat_la'] ?? ''));
                        $updatedByName = trim((string) ($row['actualizat_de_nume'] ?? ''));
                        if ($updatedByName === '') {
                            $updatedByName = '-';
                        }
                        $registrationDetailsParts = ['Adaugat: ' . $createdAtDisplay . ' de ' . $createdByName];
                        if ($updatedAuditAtDisplay !== '-') {
                            $registrationDetailsParts[] = 'Editat: ' . $updatedAuditAtDisplay . ' de ' . $updatedByName;
                        }
                        $registrationDetailsLabel = implode(' | ', $registrationDetailsParts);
                        $durationMinutes = null;
                        $durationMinutesRaw = $row['durata_cursa_minute'] ?? null;
                        if ($durationMinutesRaw !== null && $durationMinutesRaw !== '' && is_numeric((string) $durationMinutesRaw)) {
                            $durationMinutes = max(0, (int) $durationMinutesRaw);
                        }
                        $durationLabel = $formatDurationLabel($durationMinutes);
                        $diurnaValue = '-';
                        if ($durationMinutes !== null) {
                            $diurnaValue = (string) intdiv($durationMinutes, 12 * 60);
                        }
                        $billingStatus = (string) ($row['status_facturare'] ?? 'in_curs_facturare');
                        if (!isset(($billingStatuses ?? [])[$billingStatus])) {
                            $billingStatus = 'in_curs_facturare';
                        }
                        $capacityRaw = $row['capacitate_transport'] ?? null;
                        $capacityValue = ($capacityRaw !== null && $capacityRaw !== '') ? (float) $capacityRaw : null;
                        $loadedQtyRaw = $row['cantitate_incarcata'] ?? null;
                        $loadedQtyValue = ($loadedQtyRaw !== null && $loadedQtyRaw !== '') ? (float) $loadedQtyRaw : null;
                        $loadedQtyDisplayTon = $loadedQtyValue;
                        $freeCapacityValue = null;
                        if ($capacityValue !== null && $loadedQtyDisplayTon !== null) {
                            $freeCapacityValue = $capacityValue - $loadedQtyDisplayTon;
                            if ($freeCapacityValue < 0) {
                                $freeCapacityValue = 0.0;
                            }
                        }
                        $hasKmCursa = isset($row['km_cursa']) && $row['km_cursa'] !== '' && is_numeric((string) $row['km_cursa']);
                        $hasKmTotali = isset($row['km_totali']) && $row['km_totali'] !== '' && is_numeric((string) $row['km_totali']);
                        $kmCursaValue = $hasKmCursa ? (float) $row['km_cursa'] : null;
                        $kmTotaliValue = $hasKmTotali ? (float) $row['km_totali'] : null;
                        $kmEfectuatiDisplayValue = $kmCursaValue;
                        $kmNefacturatiValue = null;
                        if ($kmCursaValue !== null && $kmTotaliValue !== null && $kmTotaliValue >= $kmCursaValue) {
                            $kmNefacturatiValue = $kmTotaliValue - $kmCursaValue;
                        }
                        $costKmPrimarValue = (float) ($row['cost_km_primar'] ?? 0);
                        $costKmDistributieValue = (float) ($row['cost_km_distributie'] ?? 0);
                        $costKmMixtValue = (float) ($row['cost_km_mixt'] ?? 0);
                        $costKmCompresorValue = (float) ($row['cost_km_compresor'] ?? 0);
                        $refacturareFacturataValue = (float) ($row['total_refacturare_facturata'] ?? 0);
                        $displayTotalFacturare = (float) ($row['total_facturare'] ?? 0) + $refacturareFacturataValue;
                        if (($transportType === 'primar_distributie' || $transportType === 'mixt') && $kmTotaliValue !== null && $displayTotalFacturare > 0) {
                            $kmDistributieValue = $kmCursaValue !== null ? max(0.0, $kmTotaliValue - $kmCursaValue) : null;
                            $distributionQuantityValue = isset($row['cantitate_incarcata']) && is_numeric((string) $row['cantitate_incarcata']) ? max(0.0, (float) $row['cantitate_incarcata']) : 0.0;
                            $distributionRateValue = isset($row['pret_tarifare']) && is_numeric((string) $row['pret_tarifare']) ? max(0.0, (float) $row['pret_tarifare']) : 0.0;
                            $distributionBillingValue = $distributionQuantityValue * $distributionRateValue;
                            $costKmDistributieValue = $kmDistributieValue !== null && $kmDistributieValue > 0 ? round($distributionBillingValue / $kmDistributieValue, 2) : $costKmDistributieValue;
                            $costKmMixtValue = $kmTotaliValue > 0 ? round($displayTotalFacturare / $kmTotaliValue, 2) : $costKmMixtValue;
                        }
                        if ($transportType === 'compresor' && $costKmCompresorValue <= 0) {
                            $kmCompresor = isset($row['km_dislocare']) ? (float) $row['km_dislocare'] : 0.0;
                            if ($kmCompresor > 0) {
                                $costKmCompresorValue = round($displayTotalFacturare / $kmCompresor, 2);
                            }
                        }
                        $toPositiveFloat = static function (mixed $value): ?float {
                            if ($value === null || $value === '') {
                                return null;
                            }
                            if (!is_numeric((string) $value)) {
                                return null;
                            }
                            $number = (float) $value;
                            return $number > 0 ? $number : null;
                        };
                        $clientsCountValue = null;
                        if (isset($row['nr_clienti']) && $row['nr_clienti'] !== '' && is_numeric((string) $row['nr_clienti'])) {
                            $parsedClients = (int) $row['nr_clienti'];
                            if ($parsedClients > 0) {
                                $clientsCountValue = $parsedClients;
                            }
                        }
                        $oreAspirareValue = $toPositiveFloat($row['ore_aspirare'] ?? null);
                        $tonaLivrataValue = $toPositiveFloat($row['tona_livrata'] ?? null);
                        $tonaLichidaValue = $toPositiveFloat($row['tona_aspirata_lichida'] ?? null);
                        $tonaGazoasaValue = $toPositiveFloat($row['tona_aspirata_gazoasa'] ?? null);
                        $kmDislocareValue = $toPositiveFloat($row['km_dislocare'] ?? null);
                        $kmDisplayValue = ($kmEfectuatiDisplayValue !== null && $kmEfectuatiDisplayValue > 0) ? (float) $kmEfectuatiDisplayValue : null;
                        $kmTotaliPositiveValue = ($kmTotaliValue !== null && $kmTotaliValue > 0) ? (float) $kmTotaliValue : null;
                        $kmNefacturatiPositiveValue = ($kmNefacturatiValue !== null && $kmNefacturatiValue > 0) ? (float) $kmNefacturatiValue : null;
                        $loadedPercentValue = null;
                        if ($capacityValue !== null && $capacityValue > 0 && $loadedQtyDisplayTon !== null && $loadedQtyDisplayTon > 0) {
                            $loadedPercentValue = max(0.0, min(100.0, ($loadedQtyDisplayTon / $capacityValue) * 100.0));
                        }
                        $activityParts = [];
                        $addActivityPart = static function (array &$parts, string $label, ?string $value): void {
                            $normalized = trim((string) $value);
                            if ($normalized === '') {
                                return;
                            }
                            $parts[] = [
                                'label' => $label,
                                'value' => $normalized,
                            ];
                        };
                        $formatTonValue = static function (?float $value): ?string {
                            if ($value === null || $value <= 0) {
                                return null;
                            }
                            return format_number_ro($value, 2) . ' t';
                        };
                        $formatKmValue = static function (?float $value): ?string {
                            if ($value === null || $value <= 0) {
                                return null;
                            }
                            return number_format($value, 0, ',', '.');
                        };
                        if ($transportType === 'compresor') {
                            $addActivityPart($activityParts, 'ASPIRARE:', $oreAspirareValue !== null ? format_number_ro($oreAspirareValue, 2) . 'h' : null);
                            $addActivityPart($activityParts, 'LICHID:', $formatTonValue($tonaLichidaValue));
                            $addActivityPart($activityParts, 'GAZOS:', $formatTonValue($tonaGazoasaValue));
                            $addActivityPart($activityParts, 'LIVRAT:', $formatTonValue($tonaLivrataValue));
                            $kmCompresorDisplayValue = $kmDislocareValue ?? $kmDisplayValue;
                            $addActivityPart($activityParts, 'KM:', $formatKmValue($kmCompresorDisplayValue));
                        } elseif ($transportType === 'distributie') {
                            $addActivityPart($activityParts, 'INCARCAT:', $formatTonValue($loadedQtyDisplayTon));
                            $addActivityPart($activityParts, 'LIVRAT:', $formatTonValue($tonaLivrataValue));
                            $addActivityPart($activityParts, 'CLIENTI:', $clientsCountValue !== null ? (string) $clientsCountValue : null);
                            $addActivityPart($activityParts, 'KM:', $formatKmValue($kmDisplayValue));
                            $addActivityPart($activityParts, 'KM NEFACT.:', $formatKmValue($kmNefacturatiPositiveValue));
                            $addActivityPart($activityParts, 'GRAD:', $loadedPercentValue !== null ? number_format($loadedPercentValue, 0, ',', '.') . '%' : null);
                        } elseif ($transportType === 'primar_distributie' || $transportType === 'mixt') {
                            $addActivityPart($activityParts, 'INCARCAT:', $formatTonValue($loadedQtyDisplayTon));
                            $addActivityPart($activityParts, 'LIVRAT:', $formatTonValue($tonaLivrataValue));
                            $addActivityPart($activityParts, 'CLIENTI:', $clientsCountValue !== null ? (string) $clientsCountValue : null);
                            $addActivityPart($activityParts, 'KM:', $formatKmValue($kmDisplayValue));
                            $addActivityPart($activityParts, 'KM NEFACT.:', $formatKmValue($kmNefacturatiPositiveValue));
                        } else {
                            $addActivityPart($activityParts, 'KM:', $formatKmValue($kmDisplayValue));
                            $addActivityPart($activityParts, 'KM TOTAL:', $formatKmValue($kmTotaliPositiveValue));
                            $addActivityPart($activityParts, 'INCARCAT:', $formatTonValue($loadedQtyDisplayTon));
                            $addActivityPart($activityParts, 'GRAD:', $loadedPercentValue !== null ? number_format($loadedPercentValue, 0, ',', '.') . '%' : null);
                        }
                        $activityTitle = '-';
                        if ($activityParts !== []) {
                            $activityTitleParts = [];
                            foreach ($activityParts as $activityPart) {
                                $activityTitleParts[] = (string) ($activityPart['label'] ?? '') . ' ' . (string) ($activityPart['value'] ?? '');
                            }
                            $activityTitle = implode(' | ', $activityTitleParts);
                        }
                        $financialParts = [
                            ['label' => 'Total:', 'value' => format_number_ro($displayTotalFacturare, 2) . ' lei', 'is_total' => true],
                            ['label' => 'Tarif:', 'value' => format_number_ro((float) ($row['pret_tarifare'] ?? 0), 2) . ' lei'],
                        ];
                        $addFinancialCostKmPart = static function (array &$parts, string $label, ?float $value, bool $alwaysShow = false): void {
                            if ($value === null || (!$alwaysShow && $value <= 0)) {
                                return;
                            }

                            $parts[] = [
                                'label' => $label,
                                'value' => format_number_ro(max(0.0, $value), 2) . ' lei/km',
                            ];
                        };
                        if ($transportType === 'compresor') {
                            $addFinancialCostKmPart($financialParts, 'Cost/km Compresor:', $costKmCompresorValue);
                        } elseif ($transportType === 'distributie') {
                            $addFinancialCostKmPart($financialParts, 'Cost/km Distributie:', $costKmDistributieValue);
                        } elseif ($transportType === 'primar' || $transportType === 'primar_tona' || $transportType === 'primar_km') {
                            $addFinancialCostKmPart($financialParts, 'Cost/km Primar:', $costKmPrimarValue);
                        } elseif ($transportType === 'primar_distributie' || $transportType === 'mixt') {
                            $addFinancialCostKmPart($financialParts, 'Cost/km Mixt:', $costKmMixtValue, true);
                            $addFinancialCostKmPart($financialParts, 'Cost/km Distributie:', $costKmDistributieValue, true);
                        }
                        $financialParts[] = ['label' => 'Chelt.:', 'value' => format_number_ro((float) ($row['total_cheltuieli'] ?? 0), 2) . ' lei'];
                        $financialTitleParts = [];
                        foreach ($financialParts as $financialPart) {
                            $financialTitleParts[] = rtrim((string) ($financialPart['label'] ?? ''), ':') . ': ' . (string) ($financialPart['value'] ?? '');
                        }
                        $financialTitle = implode(' | ', $financialTitleParts);
                        $billingStatusRowClass = 'race-status-' . preg_replace('/[^a-z0-9_]/', '', strtolower($billingStatus));
                        ?>
                        <tr class="<?= e($billingStatusRowClass) ?>" data-billing-status="<?= e($billingStatus) ?>">
                            <td class="col-plate">
                                <div class="cell-content">
                                    <div class="vehicle-wrap">
                                        <label class="vehicle-main mb-0" for="bulk-race-id-<?= e((string) $raceId) ?>">
                                            <input
                                                type="checkbox"
                                                class="form-check-input m-0 bulk-race-checkbox"
                                                id="bulk-race-id-<?= e((string) $raceId) ?>"
                                                name="ids[]"
                                                value="<?= e((string) $raceId) ?>"
                                                form="bulk-race-delete-form"
                                                aria-label="Selecteaza cursa ID <?= e((string) $raceId) ?>"
                                            >
                                            <strong class="dispatcher-cell-text dispatcher-cell-nowrap dispatcher-plate-value vehicle-cell nr-auto-cell"><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></strong>
                                        </label>
                                        <?php $rowParentId = (int) (($resumeParents ?? [])[$raceId] ?? 0); ?>
                                        <?php $rowChildIds = (array) (($resumeChildren ?? [])[$raceId] ?? []); ?>
                                        <?php if ($rowParentId > 0): ?>
                                            <a class="badge bg-info text-dark text-decoration-none" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $rowParentId])) ?>" title="Această cursă este un segment care continuă cursa #<?= e((string) $rowParentId) ?>.">
                                                <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Continuare #<?= e((string) $rowParentId) ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php foreach ($rowChildIds as $rowChildId): ?>
                                            <a class="badge bg-secondary text-decoration-none" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => (int) $rowChildId])) ?>" title="Cursa a fost continuată de segmentul #<?= e((string) $rowChildId) ?>.">
                                                <i class="bi bi-signpost-split" aria-hidden="true"></i> Continuată de #<?= e((string) $rowChildId) ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="col-registration-details">
                                <div class="cell-content">
                                    <span class="dispatcher-registration-details-line" title="<?= e($registrationDetailsLabel) ?>"><?= e($registrationDetailsLabel) ?></span>
                                </div>
                            </td>
                            <td class="col-driver">
                                <div class="cell-content">
                                    <span class="dispatcher-cell-text driver-cell" title="<?= e($driverName) ?>"><?= e($driverName) ?></span>
                                </div>
                            </td>
                            <td class="col-type">
                                <div class="cell-content">
                                    <span class="badge rounded-pill dispatcher-transport-badge transport-badge transport-cell <?= e($transportBadgeClass) ?>"><?= e((string) $transportLabel) ?></span>
                                </div>
                            </td>
                            <td class="col-loading-date text-center-cell">
                                <div class="cell-content center">
                                    <span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e($dataIncarcareLabel) ?></span>
                                </div>
                            </td>
                            <td class="col-interval">
                                <div class="cell-content center dispatcher-summary-cell-content" title="<?= e($intervalLabel) ?>">
                                    <?= $renderDispatcherSummaryDetails($intervalParts, 'interval-' . (string) $raceId, 'interval cursa #' . (string) $raceId) ?>
                                </div>
                            </td>
                            <td class="col-duration text-center-cell">
                                <div class="cell-content center">
                                    <span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e($durationLabel) ?></span>
                                </div>
                            </td>
                            <td class="col-diurna text-center-cell">
                                <div class="cell-content center">
                                    <span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e($diurnaValue) ?></span>
                                </div>
                            </td>
                            <td class="col-route">
                                <div class="cell-content center dispatcher-summary-cell-content" title="<?= e($routeTitle) ?>">
                                    <?= $renderDispatcherSummaryDetails($routeParts, 'route-' . (string) $raceId, 'traseu cursa #' . (string) $raceId) ?>
                                </div>
                            </td>
                            <td class="col-beneficiary">
                                <div class="cell-content">
                                    <span class="dispatcher-cell-text" title="<?= e($beneficiarNume) ?>"><?= e($beneficiarNume) ?></span>
                                </div>
                            </td>
                            <td class="col-goods-type">
                                <div class="cell-content">
                                    <span class="dispatcher-cell-text" title="<?= e($goodsTypeLabel) ?>"><?= e($goodsTypeLabel) ?></span>
                                </div>
                            </td>
                            <td class="col-activity">
                                <div class="cell-content center dispatcher-summary-cell-content" title="<?= e($activityTitle) ?>">
                                    <?= $renderDispatcherSummaryDetails($activityParts, 'activity-' . (string) $raceId, 'activitate cursa #' . (string) $raceId) ?>
                                </div>
                            </td>
                            <td class="col-financial">
                                <div class="cell-content center dispatcher-summary-cell-content" title="<?= e($financialTitle) ?>">
                                    <?= $renderDispatcherSummaryDetails($financialParts, 'financial-' . (string) $raceId, 'financiar cursa #' . (string) $raceId) ?>
                                </div>
                            </td>
                            <td class="col-beneficiary">
                                <div class="cell-content">
                                    <span class="dispatcher-cell-text" title="<?= e($observatii !== '' ? $observatii : '-') ?>"><?= e($observatii !== '' ? $observatii : '-') ?></span>
                                </div>
                            </td>
                            <td class="col-actions text-center-cell">
                                <div class="cell-content center">
                                    <div class="dispatcher-race-actions" data-dispatcher-race-actions>
                                        <button
                                            type="button"
                                            class="dispatcher-race-actions-toggle"
                                            data-dispatcher-race-actions-toggle
                                            data-menu-id="dispatcher_race_actions_<?= e((string) $raceId) ?>"
                                            aria-haspopup="menu"
                                            aria-expanded="false"
                                            aria-controls="dispatcher_race_actions_<?= e((string) $raceId) ?>"
                                            aria-label="Actiuni cursa #<?= e((string) $raceId) ?>"
                                            title="Actiuni"
                                        >
                                            <i class="bi bi-three-dots" aria-hidden="true"></i>
                                        </button>
                                        <div class="dispatcher-race-actions-menu" id="dispatcher_race_actions_<?= e((string) $raceId) ?>" data-dispatcher-race-actions-menu role="menu" hidden>
                                            <a class="dispatcher-race-actions-item" role="menuitem" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId])) ?>">Editează</a>
                                            <a class="dispatcher-race-actions-item" role="menuitem" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'resume_id' => $raceId]) . '#add-race-form') ?>" title="Creează o cursă nouă, legată de aceasta, cu posibilitatea de a schimba șoferul sau vehiculul.">Reia cursa (segment nou)</a>
                                            <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'delete'])) ?>" class="dispatcher-race-actions-form" role="none">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id" value="<?= e((string) $raceId) ?>">
                                                <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
                                                <button type="submit" class="dispatcher-race-actions-item dispatcher-race-actions-danger" role="menuitem" data-confirm="Ștergi cursa #<?= e((string) $raceId) ?>? Cursa va fi mutată în Curse șterse și va putea fi restaurată ulterior.">
                                                    Șterge
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<?php include __DIR__ . '/_expense_prompt_modal.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAllEl = document.getElementById('bulk-race-select-all');
    const bulkDeleteBtnEl = document.getElementById('bulk-race-delete-btn');
    const raceCheckboxEls = Array.from(document.querySelectorAll('.bulk-race-checkbox'));
    const filterToggleEl = document.querySelector('[data-dispatcher-filter-toggle]');
    const filterPanelEl = document.getElementById('dispatcher-filter-panel');
    const racesCardEl = document.querySelector('.dispatcher-races-card');
    const racesHeaderEl = document.querySelector('.dispatcher-races-card-header');
    const racesTableWrapEl = document.querySelector('.dispatcher-races-table-wrap');
    const racesTableEl = document.querySelector('[data-dispatcher-column-table]');

    if (filterToggleEl instanceof HTMLButtonElement && filterPanelEl instanceof HTMLElement) {
        const filterToggleLabelEl = filterToggleEl.querySelector('span');
        const filterToggleChevronEl = filterToggleEl.querySelector('.dispatcher-filter-toggle-chevron');
        const setFilterExpanded = function (expanded) {
            filterPanelEl.hidden = !expanded;
            filterToggleEl.classList.toggle('is-open', expanded);
            filterToggleEl.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            if (filterToggleLabelEl instanceof HTMLElement) {
                filterToggleLabelEl.textContent = expanded ? 'Ascunde filtre' : 'Afiseaza filtre';
            }
            if (filterToggleChevronEl instanceof HTMLElement) {
                filterToggleChevronEl.classList.toggle('bi-chevron-up', expanded);
                filterToggleChevronEl.classList.toggle('bi-chevron-down', !expanded);
            }
        };

        setFilterExpanded(false);
        filterToggleEl.addEventListener('click', function () {
            setFilterExpanded(filterPanelEl.hidden);
        });
    }

    if (racesCardEl instanceof HTMLElement && racesHeaderEl instanceof HTMLElement) {
        const syncRacesHeaderOffset = function () {
            racesCardEl.style.setProperty('--dispatcher-races-header-offset', racesHeaderEl.offsetHeight + 'px');
        };

        syncRacesHeaderOffset();
        window.addEventListener('resize', syncRacesHeaderOffset);
        if (typeof ResizeObserver !== 'undefined') {
            const racesHeaderObserver = new ResizeObserver(syncRacesHeaderOffset);
            racesHeaderObserver.observe(racesHeaderEl);
            racesHeaderEl.dispatcherStickyObserver = racesHeaderObserver;
        }
    }

    if (
        racesCardEl instanceof HTMLElement
        && racesHeaderEl instanceof HTMLElement
        && racesTableWrapEl instanceof HTMLElement
        && racesTableEl instanceof HTMLTableElement
    ) {
        const stickyHeaderEl = document.createElement('div');
        stickyHeaderEl.className = 'dispatcher-races-sticky-column-header';
        stickyHeaderEl.setAttribute('aria-hidden', 'true');
        stickyHeaderEl.hidden = true;
        document.body.appendChild(stickyHeaderEl);

        let stickyHeaderTableEl = null;
        let stickyHeaderFrame = 0;

        const sanitizeStickyHeader = function () {
            stickyHeaderEl.querySelectorAll('[id]').forEach(function (el) {
                el.removeAttribute('id');
            });
            // Controalele de sortare/filtrare (.races-head-control) raman active si in antetul lipicios.
            stickyHeaderEl.querySelectorAll('input, button:not(.races-head-control), select, textarea, a').forEach(function (el) {
                el.setAttribute('tabindex', '-1');
                if ('disabled' in el) {
                    el.disabled = true;
                }
            });
        };

        const rebuildStickyHeader = function () {
            const tableHeadEl = racesTableEl.querySelector('thead');
            if (!(tableHeadEl instanceof HTMLTableSectionElement)) {
                stickyHeaderEl.hidden = true;
                stickyHeaderTableEl = null;
                return;
            }

            const tableCloneEl = racesTableEl.cloneNode(false);
            tableCloneEl.removeAttribute('data-dispatcher-column-table');
            tableCloneEl.classList.add('dispatcher-races-sticky-table');

            const colGroupEl = racesTableEl.querySelector('colgroup');
            if (colGroupEl instanceof HTMLTableColElement || colGroupEl instanceof HTMLElement) {
                tableCloneEl.appendChild(colGroupEl.cloneNode(true));
            }
            tableCloneEl.appendChild(tableHeadEl.cloneNode(true));

            stickyHeaderEl.replaceChildren(tableCloneEl);
            stickyHeaderTableEl = tableCloneEl;
            sanitizeStickyHeader();
        };

        const syncStickyHeaderPosition = function () {
            stickyHeaderFrame = 0;

            const tableHeadEl = racesTableEl.querySelector('thead');
            if (!(tableHeadEl instanceof HTMLTableSectionElement)) {
                stickyHeaderEl.hidden = true;
                return;
            }

            if (!(stickyHeaderTableEl instanceof HTMLTableElement)) {
                rebuildStickyHeader();
            }

            const tableRect = racesTableEl.getBoundingClientRect();
            const wrapRect = racesTableWrapEl.getBoundingClientRect();
            const headerRect = tableHeadEl.getBoundingClientRect();
            const titleHeaderRect = racesHeaderEl.getBoundingClientRect();
            const topOffset = Math.max(0, Math.round(titleHeaderRect.bottom));
            const headerHeight = Math.max(1, Math.round(headerRect.height));
            const shouldShow = tableRect.top < topOffset && tableRect.bottom > (topOffset + headerHeight);

            stickyHeaderEl.hidden = !shouldShow;
            // Butonul "informatii lipsa" ramane vizibil pana cand atinge efectiv banda
            // antetului lipicios; doar atunci se ascunde, ca sa nu blocheze sortarea/filtrarea.
            // Reapare automat cand utilizatorul deruleaza inapoi in sus.
            var openRacesAlertEl = document.querySelector('.dispatcher-open-races-alert-container');
            var alertTouchesStickyHeader = false;
            if (shouldShow && openRacesAlertEl instanceof HTMLElement) {
                var openRacesAlertRect = openRacesAlertEl.getBoundingClientRect();
                alertTouchesStickyHeader = openRacesAlertRect.bottom > topOffset
                    && openRacesAlertRect.top < (topOffset + headerHeight);
            }
            document.body.classList.toggle('dispatcher-races-header-pinned', alertTouchesStickyHeader);
            if (!shouldShow || !(stickyHeaderTableEl instanceof HTMLTableElement)) {
                return;
            }

            stickyHeaderEl.style.left = Math.round(wrapRect.left) + 'px';
            stickyHeaderEl.style.top = topOffset + 'px';
            stickyHeaderEl.style.width = Math.round(wrapRect.width) + 'px';
            stickyHeaderEl.style.height = headerHeight + 'px';
            stickyHeaderTableEl.style.width = Math.round(racesTableEl.offsetWidth) + 'px';
            stickyHeaderTableEl.style.transform = 'translateX(' + (-racesTableWrapEl.scrollLeft) + 'px)';
        };

        const scheduleStickyHeaderSync = function () {
            if (stickyHeaderFrame !== 0) {
                return;
            }
            stickyHeaderFrame = window.requestAnimationFrame(syncStickyHeaderPosition);
        };

        rebuildStickyHeader();
        scheduleStickyHeaderSync();
        window.addEventListener('scroll', scheduleStickyHeaderSync, { passive: true });
        window.addEventListener('resize', scheduleStickyHeaderSync);
        racesTableWrapEl.addEventListener('scroll', scheduleStickyHeaderSync, { passive: true });

        if (typeof ResizeObserver !== 'undefined') {
            const stickyTableObserver = new ResizeObserver(scheduleStickyHeaderSync);
            stickyTableObserver.observe(racesTableEl);
            stickyTableObserver.observe(racesTableWrapEl);
            racesTableEl.dispatcherStickyTableObserver = stickyTableObserver;
        }

        if (typeof MutationObserver !== 'undefined') {
            const stickyHeaderMutationObserver = new MutationObserver(function () {
                rebuildStickyHeader();
                scheduleStickyHeaderSync();
            });
            stickyHeaderMutationObserver.observe(racesTableEl, {
                attributes: true,
                childList: true,
                subtree: true,
            });
            racesTableEl.dispatcherStickyHeaderMutationObserver = stickyHeaderMutationObserver;
        }
    }

    // --- Sortare si filtrare rapida direct din antetul Desfasuratorului ---
    // Click pe antet = sortare asc/desc; butonul palnie = filtru rapid pe valorile coloanei.
    // Functioneaza si din antetul lipicios (clona), prin delegare catre antetul real.
    if (racesTableEl instanceof HTMLTableElement) {
        const racesHeadRowEl = racesTableEl.querySelector('thead tr');
        const racesBodyEl = racesTableEl.querySelector('tbody');

        if (racesHeadRowEl instanceof HTMLTableRowElement && racesBodyEl instanceof HTMLTableSectionElement) {
            const columnFilters = {};
            const resetFiltersButtonEl = document.getElementById('races-reset-filters-btn');
            let currentSortIndex = -1;
            let currentSortDir = 1;

            const getDataRows = function () {
                return Array.from(racesBodyEl.querySelectorAll('tr')).filter(function (rowEl) {
                    return rowEl.querySelector('td[colspan]') === null;
                });
            };

            const getCellValue = function (rowEl, columnIndex) {
                const cellEl = rowEl.cells[columnIndex];
                if (!cellEl) {
                    return '';
                }
                const titledEl = cellEl.querySelector('[title]');
                const raw = (titledEl && titledEl.getAttribute('title')) || cellEl.textContent || '';
                return raw.replace(/\s+/g, ' ').trim();
            };

            const parseDateValue = function (text) {
                const m = text.match(/(\d{2})[.\/-](\d{2})[.\/-](\d{4})(?:[ T]?(\d{2}):(\d{2}))?/);
                if (!m) {
                    return null;
                }
                return Date.UTC(+m[3], +m[2] - 1, +m[1], +(m[4] || 0), +(m[5] || 0));
            };

            const parseNumberValue = function (text) {
                const normalized = text.replace(/\./g, '').replace(/,/g, '.');
                const m = normalized.match(/-?\d+(?:\.\d+)?/);
                return m ? parseFloat(m[0]) : null;
            };

            const updateHeadIndicators = function () {
                Array.from(racesHeadRowEl.cells).forEach(function (thEl, columnIndex) {
                    thEl.classList.toggle('races-sorted-asc', columnIndex === currentSortIndex && currentSortDir === 1);
                    thEl.classList.toggle('races-sorted-desc', columnIndex === currentSortIndex && currentSortDir === -1);
                    const arrowEl = thEl.querySelector('.races-sort-arrow');
                    if (arrowEl instanceof HTMLElement) {
                        arrowEl.textContent = columnIndex === currentSortIndex ? (currentSortDir === 1 ? '▲' : '▼') : '↕';
                    }
                    const filterSet = columnFilters[columnIndex];
                    thEl.classList.toggle('races-filtered', !!(filterSet && filterSet.size > 0));
                });

                if (resetFiltersButtonEl instanceof HTMLButtonElement) {
                    resetFiltersButtonEl.disabled = Object.keys(columnFilters).length === 0;
                }
            };

            const applySort = function (columnIndex) {
                if (columnIndex === currentSortIndex) {
                    currentSortDir = -currentSortDir;
                } else {
                    currentSortIndex = columnIndex;
                    currentSortDir = 1;
                }

                const rows = getDataRows();
                // Tipul coloanei se decide dupa primele valori nenule: data -> numar -> text.
                let dateHits = 0;
                let numberHits = 0;
                let sampled = 0;
                rows.some(function (rowEl) {
                    const value = getCellValue(rowEl, columnIndex);
                    if (value === '' || value === '-') {
                        return false;
                    }
                    sampled++;
                    if (parseDateValue(value) !== null) {
                        dateHits++;
                    } else if (parseNumberValue(value) !== null) {
                        numberHits++;
                    }
                    return sampled >= 10;
                });
                const columnType = sampled > 0 && dateHits >= sampled / 2
                    ? 'date'
                    : (sampled > 0 && numberHits >= sampled / 2 ? 'number' : 'text');

                const sortKey = function (rowEl) {
                    const value = getCellValue(rowEl, columnIndex);
                    if (columnType === 'date') {
                        const parsedDate = parseDateValue(value);
                        return parsedDate === null ? -Infinity : parsedDate;
                    }
                    if (columnType === 'number') {
                        const parsedNumber = parseNumberValue(value);
                        return parsedNumber === null ? -Infinity : parsedNumber;
                    }
                    return value.toLowerCase();
                };

                rows
                    .map(function (rowEl, position) {
                        return { rowEl: rowEl, key: sortKey(rowEl), position: position };
                    })
                    .sort(function (a, b) {
                        if (a.key < b.key) {
                            return -1 * currentSortDir;
                        }
                        if (a.key > b.key) {
                            return 1 * currentSortDir;
                        }
                        if (typeof a.key === 'string' && typeof b.key === 'string') {
                            const cmp = a.key.localeCompare(b.key, 'ro');
                            if (cmp !== 0) {
                                return cmp * currentSortDir;
                            }
                        }
                        return a.position - b.position;
                    })
                    .forEach(function (entry) {
                        racesBodyEl.appendChild(entry.rowEl);
                    });

                updateHeadIndicators();
            };

            const applyFilters = function () {
                getDataRows().forEach(function (rowEl) {
                    let visible = true;
                    Object.keys(columnFilters).forEach(function (key) {
                        const allowed = columnFilters[key];
                        if (!allowed || allowed.size === 0) {
                            return;
                        }
                        if (!allowed.has(getCellValue(rowEl, parseInt(key, 10)))) {
                            visible = false;
                        }
                    });
                    rowEl.classList.toggle('d-none', !visible);
                    if (!visible) {
                        const checkboxEl = rowEl.querySelector('.bulk-race-checkbox');
                        if (checkboxEl instanceof HTMLInputElement && checkboxEl.checked) {
                            checkboxEl.checked = false;
                            checkboxEl.dispatchEvent(new Event('change'));
                        }
                    }
                });
                updateHeadIndicators();
            };

            // Dropdown-ul de filtrare rapida (unic, repozitionat per coloana).
            const filterDropdownEl = document.createElement('div');
            filterDropdownEl.className = 'dispatcher-races-filter-dropdown';
            filterDropdownEl.hidden = true;
            document.body.appendChild(filterDropdownEl);
            let openFilterColumn = -1;

            // Selectie pe interval cu Shift + click, generica pentru orice filtru de coloana:
            // ancora este ultima optiune apasata fara Shift, valabila doar in dropdown-ul curent.
            let filterRangeAnchorEl = null;
            let suppressRangeChange = false;

            const closeFilterDropdown = function () {
                filterDropdownEl.hidden = true;
                openFilterColumn = -1;
                filterRangeAnchorEl = null;
            };

            // Un singur handler delegat pe componenta de dropdown: functioneaza identic
            // pentru toate coloanele, actuale sau viitoare, fara cod specific per coloana.
            filterDropdownEl.addEventListener('click', function (event) {
                const optionEl = event.target instanceof Element
                    ? event.target.closest('.races-filter-list .races-filter-option')
                    : null;
                if (!(optionEl instanceof HTMLElement)) {
                    return;
                }
                const checkboxEl = optionEl.querySelector('input[type="checkbox"]');
                if (!(checkboxEl instanceof HTMLInputElement)) {
                    return;
                }

                const listEl = optionEl.closest('.races-filter-list');
                const visibleOptions = listEl instanceof HTMLElement
                    ? Array.prototype.filter.call(listEl.querySelectorAll('.races-filter-option'), function (el) {
                        return !el.classList.contains('d-none');
                    })
                    : [];

                if (!event.shiftKey) {
                    // Click normal: comportamentul existent ramane; optiunea devine ancora.
                    filterRangeAnchorEl = optionEl;
                    return;
                }

                const anchorIndex = visibleOptions.indexOf(filterRangeAnchorEl);
                const targetIndex = visibleOptions.indexOf(optionEl);
                if (anchorIndex === -1 || targetIndex === -1) {
                    // Ancora lipseste sau a fost ascunsa de cautare: tratam ca selectie
                    // normala, iar optiunea apasata devine noua ancora.
                    filterRangeAnchorEl = optionEl;
                    return;
                }

                // Starea rezultata a tintei: daca clickul a fost direct pe checkbox,
                // starea e deja comutata; daca a fost pe eticheta, comutarea nativa e oprita
                // si o aplicam noi pe tot intervalul.
                const clickedCheckboxDirectly = event.target === checkboxEl;
                const resultingChecked = clickedCheckboxDirectly ? checkboxEl.checked : !checkboxEl.checked;
                if (!clickedCheckboxDirectly) {
                    event.preventDefault();
                }

                const from = Math.min(anchorIndex, targetIndex);
                const to = Math.max(anchorIndex, targetIndex);
                const targetSet = columnFilters[openFilterColumn] || new Set();
                for (let optionIdx = from; optionIdx <= to; optionIdx++) {
                    const rangeCheckboxEl = visibleOptions[optionIdx].querySelector('input[type="checkbox"]');
                    if (!(rangeCheckboxEl instanceof HTMLInputElement)) {
                        continue;
                    }
                    rangeCheckboxEl.checked = resultingChecked;
                    if (resultingChecked) {
                        targetSet.add(rangeCheckboxEl.value);
                    } else {
                        targetSet.delete(rangeCheckboxEl.value);
                    }
                }
                if (targetSet.size > 0) {
                    columnFilters[openFilterColumn] = targetSet;
                } else {
                    delete columnFilters[openFilterColumn];
                }

                // O singura aplicare a filtrelor pentru intregul interval; change-ul nativ
                // al checkboxului tinta (cand exista) este suprimat pana la finalul clickului.
                suppressRangeChange = true;
                applyFilters();
                window.setTimeout(function () {
                    suppressRangeChange = false;
                }, 0);
            });

            // Trece rândul prin toate filtrele active, cu exceptia coloanei date.
            // Folosit pentru filtrarea in cascada: optiunile unei coloane reflecta doar
            // randurile ramase dupa filtrele celorlalte coloane.
            const rowMatchesFiltersExcept = function (rowEl, excludedColumnIndex) {
                let matches = true;
                Object.keys(columnFilters).forEach(function (key) {
                    const filterColumnIndex = parseInt(key, 10);
                    if (filterColumnIndex === excludedColumnIndex) {
                        return;
                    }
                    const allowed = columnFilters[key];
                    if (!allowed || allowed.size === 0) {
                        return;
                    }
                    if (!allowed.has(getCellValue(rowEl, filterColumnIndex))) {
                        matches = false;
                    }
                });
                return matches;
            };

            const openFilterDropdown = function (columnIndex, anchorRect) {
                openFilterColumn = columnIndex;
                // Continutul se reconstruieste: ancora Shift a vechii liste nu mai e valabila.
                filterRangeAnchorEl = null;
                const headTh = racesHeadRowEl.cells[columnIndex];
                const columnLabel = headTh ? headTh.textContent.replace(/[▲▼↕]/g, '').replace(/\s+/g, ' ').trim() : '';

                const activeSet = columnFilters[columnIndex] || new Set();

                const uniqueValues = [];
                const seenValues = {};
                getDataRows().forEach(function (rowEl) {
                    // Cascada: arata doar valorile disponibile dupa filtrele celorlalte coloane.
                    if (!rowMatchesFiltersExcept(rowEl, columnIndex)) {
                        return;
                    }
                    const value = getCellValue(rowEl, columnIndex);
                    if (value === '' || Object.prototype.hasOwnProperty.call(seenValues, value)) {
                        return;
                    }
                    seenValues[value] = true;
                    uniqueValues.push(value);
                });
                // Valorile deja bifate pe aceasta coloana raman vizibile chiar daca nu mai au
                // randuri disponibile, ca sa poata fi debifate.
                activeSet.forEach(function (value) {
                    if (!Object.prototype.hasOwnProperty.call(seenValues, value)) {
                        seenValues[value] = true;
                        uniqueValues.push(value);
                    }
                });
                uniqueValues.sort(function (a, b) {
                    // Valorile deja bifate apar primele, ca selectia activa sa fie mereu vizibila.
                    const aChecked = activeSet.has(a);
                    const bChecked = activeSet.has(b);
                    if (aChecked !== bChecked) {
                        return aChecked ? -1 : 1;
                    }
                    // Datele calendaristice se ordoneaza cronologic, restul alfabetic.
                    const aDate = parseDateValue(a);
                    const bDate = parseDateValue(b);
                    if (aDate !== null && bDate !== null) {
                        return aDate - bDate;
                    }
                    return a.localeCompare(b, 'ro');
                });

                filterDropdownEl.replaceChildren();
                const headerEl = document.createElement('div');
                headerEl.className = 'races-filter-head';
                const titleEl = document.createElement('strong');
                titleEl.textContent = 'Filtru: ' + columnLabel;
                const resetEl = document.createElement('a');
                resetEl.href = '#';
                resetEl.textContent = 'Toate';
                resetEl.addEventListener('click', function (event) {
                    event.preventDefault();
                    delete columnFilters[columnIndex];
                    applyFilters();
                    closeFilterDropdown();
                });
                headerEl.appendChild(titleEl);
                headerEl.appendChild(resetEl);
                filterDropdownEl.appendChild(headerEl);

                const searchEl = document.createElement('input');
                searchEl.type = 'search';
                searchEl.className = 'form-control form-control-sm mt-1';
                searchEl.placeholder = 'Cauta valoare...';
                filterDropdownEl.appendChild(searchEl);

                const listEl = document.createElement('div');
                listEl.className = 'races-filter-list';
                listEl.title = 'Shift + click pentru selectarea unui interval';
                uniqueValues.forEach(function (value) {
                    const optionEl = document.createElement('label');
                    optionEl.className = 'races-filter-option';
                    const checkboxEl = document.createElement('input');
                    checkboxEl.type = 'checkbox';
                    checkboxEl.className = 'form-check-input m-0 mt-1';
                    checkboxEl.value = value;
                    checkboxEl.checked = activeSet.has(value);
                    checkboxEl.addEventListener('change', function () {
                        if (suppressRangeChange) {
                            // Selectia pe interval (Shift) a actualizat deja starea si filtrele.
                            return;
                        }
                        const targetSet = columnFilters[columnIndex] || new Set();
                        if (checkboxEl.checked) {
                            targetSet.add(value);
                        } else {
                            targetSet.delete(value);
                        }
                        if (targetSet.size > 0) {
                            columnFilters[columnIndex] = targetSet;
                        } else {
                            delete columnFilters[columnIndex];
                        }
                        applyFilters();
                    });
                    const labelSpan = document.createElement('span');
                    labelSpan.textContent = value;
                    optionEl.appendChild(checkboxEl);
                    optionEl.appendChild(labelSpan);
                    listEl.appendChild(optionEl);
                });
                filterDropdownEl.appendChild(listEl);

                // Resetare globala, direct din zona de filtrare: sterge filtrele tuturor coloanelor.
                const resetAllWrapEl = document.createElement('div');
                resetAllWrapEl.className = 'races-filter-reset-all-wrap';
                const resetAllEl = document.createElement('a');
                resetAllEl.href = '#';
                resetAllEl.className = 'races-filter-reset-all';
                resetAllEl.innerHTML = '<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Resetează toate filtrele';
                resetAllEl.addEventListener('click', function (event) {
                    event.preventDefault();
                    resetAllColumnFilters();
                });
                resetAllWrapEl.appendChild(resetAllEl);
                filterDropdownEl.appendChild(resetAllWrapEl);

                searchEl.addEventListener('input', function () {
                    const needle = searchEl.value.toLowerCase();
                    Array.from(listEl.children).forEach(function (optionEl) {
                        optionEl.classList.toggle('d-none', optionEl.textContent.toLowerCase().indexOf(needle) === -1);
                    });
                });

                filterDropdownEl.hidden = false;
                const dropdownWidth = Math.min(320, Math.max(220, anchorRect.width * 2));
                let left = Math.round(anchorRect.left);
                if (left + dropdownWidth > window.innerWidth - 8) {
                    left = Math.max(8, window.innerWidth - dropdownWidth - 8);
                }
                filterDropdownEl.style.left = left + 'px';
                filterDropdownEl.style.top = Math.round(anchorRect.bottom + 4) + 'px';
                filterDropdownEl.style.width = dropdownWidth + 'px';

                // Dropdown-ul ramane mereu in interiorul ferestrei: daca nu are loc sub
                // palnie, se deschide deasupra ei; altfel se lipeste de marginea de jos.
                const dropdownRect = filterDropdownEl.getBoundingClientRect();
                if (dropdownRect.bottom > window.innerHeight - 8) {
                    const topAbove = Math.round(anchorRect.top - dropdownRect.height - 4);
                    if (topAbove >= 8) {
                        filterDropdownEl.style.top = topAbove + 'px';
                    } else {
                        filterDropdownEl.style.top = Math.max(8, window.innerHeight - dropdownRect.height - 8) + 'px';
                    }
                }
                searchEl.focus();
            };

            document.addEventListener('click', function (event) {
                if (filterDropdownEl.hidden) {
                    return;
                }
                if (filterDropdownEl.contains(event.target) || (event.target instanceof Element && event.target.closest('.races-filter-btn'))) {
                    return;
                }
                closeFilterDropdown();
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeFilterDropdown();
                }
            });
            window.addEventListener('scroll', closeFilterDropdown, { passive: true });

            // Sterge toate filtrele de coloana (sortarea ramane neatinsa).
            const resetAllColumnFilters = function () {
                Object.keys(columnFilters).forEach(function (key) {
                    delete columnFilters[key];
                });
                closeFilterDropdown();
                applyFilters();
            };

            if (resetFiltersButtonEl instanceof HTMLButtonElement) {
                resetFiltersButtonEl.addEventListener('click', resetAllColumnFilters);
            }

            const handleHeaderIntent = function (thEl, targetEl, anchorRect) {
                const columnIndex = thEl.cellIndex;
                if (thEl.classList.contains('col-actions')) {
                    return;
                }
                if (targetEl.closest('.races-filter-btn')) {
                    if (openFilterColumn === columnIndex && !filterDropdownEl.hidden) {
                        closeFilterDropdown();
                    } else {
                        openFilterDropdown(columnIndex, anchorRect);
                    }
                    return;
                }
                // Click pe eticheta cu checkbox-ul "selecteaza tot" nu declanseaza sortarea.
                if (targetEl.closest('label, input')) {
                    return;
                }
                applySort(columnIndex);
            };

            // Pregateste antetul real: cursor, sageata de sortare, buton de filtrare.
            Array.from(racesHeadRowEl.cells).forEach(function (thEl) {
                if (thEl.classList.contains('col-actions')) {
                    return;
                }
                thEl.classList.add('races-sortable');
                thEl.title = 'Click: sorteaza. Palnie: filtreaza valorile coloanei.';
                const controlsEl = document.createElement('span');
                controlsEl.className = 'races-head-controls';
                const arrowEl = document.createElement('span');
                arrowEl.className = 'races-sort-arrow';
                arrowEl.textContent = '↕';
                const filterBtnEl = document.createElement('button');
                filterBtnEl.type = 'button';
                filterBtnEl.className = 'races-head-control races-filter-btn';
                filterBtnEl.setAttribute('aria-label', 'Filtreaza coloana');
                filterBtnEl.innerHTML = '<i class="bi bi-funnel" aria-hidden="true"></i>';
                controlsEl.appendChild(arrowEl);
                controlsEl.appendChild(filterBtnEl);

                // Continutul existent al antetului intra intr-un container flexibil:
                // textul se scurteaza cu "..." cand coloana e ingusta, iar sageata de
                // sortare si palnia raman mereu vizibile si clickabile.
                const headWrapEl = document.createElement('div');
                headWrapEl.className = 'races-head-wrap';
                const headLabelEl = document.createElement('span');
                headLabelEl.className = 'races-head-label';
                while (thEl.firstChild) {
                    headLabelEl.appendChild(thEl.firstChild);
                }
                headWrapEl.appendChild(headLabelEl);
                headWrapEl.appendChild(controlsEl);
                thEl.appendChild(headWrapEl);
            });

            racesHeadRowEl.addEventListener('click', function (event) {
                const thEl = event.target instanceof Element ? event.target.closest('th') : null;
                if (thEl instanceof HTMLTableCellElement) {
                    const anchorEl = event.target instanceof Element ? (event.target.closest('.races-filter-btn') || thEl) : thEl;
                    handleHeaderIntent(thEl, event.target, anchorEl.getBoundingClientRect());
                }
            });

            // Delegare din antetul lipicios (clona): actiunile se aplica pe antetul real.
            const stickyHostEl = document.querySelector('.dispatcher-races-sticky-column-header');
            if (stickyHostEl instanceof HTMLElement) {
                stickyHostEl.addEventListener('click', function (event) {
                    const thEl = event.target instanceof Element ? event.target.closest('th') : null;
                    if (thEl instanceof HTMLTableCellElement) {
                        const anchorEl = event.target instanceof Element ? (event.target.closest('.races-filter-btn') || thEl) : thEl;
                        handleHeaderIntent(thEl, event.target, anchorEl.getBoundingClientRect());
                    }
                });
            }

            updateHeadIndicators();
        }
    }

    const refreshBulkDeleteState = function () {
        if (!(bulkDeleteBtnEl instanceof HTMLButtonElement)) {
            return;
        }

        const selectedCount = raceCheckboxEls.filter(function (checkboxEl) {
            return checkboxEl instanceof HTMLInputElement && checkboxEl.checked;
        }).length;

        bulkDeleteBtnEl.disabled = selectedCount === 0;

        if (!(selectAllEl instanceof HTMLInputElement)) {
            return;
        }

        if (raceCheckboxEls.length === 0) {
            selectAllEl.checked = false;
            selectAllEl.indeterminate = false;
            selectAllEl.disabled = true;
            return;
        }

        selectAllEl.checked = selectedCount === raceCheckboxEls.length;
        selectAllEl.indeterminate = selectedCount > 0 && selectedCount < raceCheckboxEls.length;
    };

    if (selectAllEl instanceof HTMLInputElement) {
        selectAllEl.addEventListener('change', function () {
            raceCheckboxEls.forEach(function (checkboxEl) {
                if (!(checkboxEl instanceof HTMLInputElement)) {
                    return;
                }

                checkboxEl.checked = selectAllEl.checked;
            });

            refreshBulkDeleteState();
        });
    }

    raceCheckboxEls.forEach(function (checkboxEl) {
        checkboxEl.addEventListener('change', refreshBulkDeleteState);
    });

    refreshBulkDeleteState();

    var closestElement = function (target, selector) {
        return target instanceof Element ? target.closest(selector) : null;
    };

    var activeSummaryState = null;

    var positionSummaryPopover = function (triggerEl, popoverEl) {
        if (!(triggerEl instanceof HTMLElement) || !(popoverEl instanceof HTMLElement)) {
            return;
        }

        var margin = 12;
        var viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        var viewportHeight = document.documentElement.clientHeight || window.innerHeight;
        var width = Math.min(304, Math.max(220, viewportWidth - margin * 2));
        var maxHeight = Math.min(360, Math.max(180, viewportHeight - margin * 2));

        popoverEl.style.width = width + 'px';
        popoverEl.style.maxHeight = maxHeight + 'px';

        var triggerRect = triggerEl.getBoundingClientRect();
        var popoverRect = popoverEl.getBoundingClientRect();
        var layerHeight = Math.min(popoverRect.height || popoverEl.scrollHeight || maxHeight, maxHeight);
        var left = triggerRect.left;

        if (left + width > viewportWidth - margin) {
            left = triggerRect.right - width;
        }
        left = Math.max(margin, Math.min(left, viewportWidth - margin - width));

        var top = triggerRect.bottom + 8;
        var topIfAbove = triggerRect.top - layerHeight - 8;
        if (top + layerHeight > viewportHeight - margin && topIfAbove >= margin) {
            top = topIfAbove;
        } else if (top + layerHeight > viewportHeight - margin) {
            top = Math.max(margin, viewportHeight - margin - layerHeight);
        }
        top = Math.max(margin, Math.min(top, viewportHeight - margin - layerHeight));

        popoverEl.style.left = Math.round(left) + 'px';
        popoverEl.style.top = Math.round(top) + 'px';
    };

    var closeSummaryPopover = function (restoreFocus) {
        if (activeSummaryState === null) {
            return;
        }

        var previousState = activeSummaryState;
        activeSummaryState = null;
        previousState.button.setAttribute('aria-expanded', 'false');
        previousState.button.classList.remove('is-open');

        var iconEl = previousState.button.querySelector('i');
        if (iconEl instanceof HTMLElement) {
            iconEl.classList.remove('bi-chevron-up');
            iconEl.classList.add('bi-chevron-down');
        }

        previousState.popover.hidden = true;
        previousState.popover.style.left = '';
        previousState.popover.style.top = '';
        previousState.popover.style.visibility = '';

        if (restoreFocus) {
            previousState.button.focus({ preventScroll: true });
        }
    };

    var openSummaryPopover = function (buttonEl) {
        if (!(buttonEl instanceof HTMLButtonElement) || buttonEl.disabled) {
            return;
        }

        var popoverId = String(buttonEl.dataset.popoverId || '');
        var popoverEl = popoverId !== '' ? document.getElementById(popoverId) : null;
        if (!(popoverEl instanceof HTMLElement)) {
            return;
        }

        closeSummaryPopover(false);

        activeSummaryState = {
            button: buttonEl,
            popover: popoverEl
        };

        buttonEl.setAttribute('aria-expanded', 'true');
        buttonEl.classList.add('is-open');

        var iconEl = buttonEl.querySelector('i');
        if (iconEl instanceof HTMLElement) {
            iconEl.classList.remove('bi-chevron-down');
            iconEl.classList.add('bi-chevron-up');
        }

        popoverEl.style.visibility = 'hidden';
        popoverEl.hidden = false;
        positionSummaryPopover(buttonEl, popoverEl);
        popoverEl.style.visibility = '';
    };

    var repositionSummaryPopover = function () {
        if (activeSummaryState !== null) {
            positionSummaryPopover(activeSummaryState.button, activeSummaryState.popover);
        }
    };

    document.addEventListener('click', function (event) {
        var summaryButtonEl = closestElement(event.target, '[data-dispatcher-summary-toggle]');
        if (summaryButtonEl instanceof HTMLButtonElement) {
            event.preventDefault();
            if (activeSummaryState !== null && activeSummaryState.button === summaryButtonEl) {
                closeSummaryPopover(false);
                return;
            }
            openSummaryPopover(summaryButtonEl);
            return;
        }

        if (closestElement(event.target, '[data-dispatcher-summary-popover]') === null) {
            closeSummaryPopover(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && activeSummaryState !== null) {
            event.preventDefault();
            closeSummaryPopover(true);
            return;
        }

        var summaryButtonEl = closestElement(event.target, '[data-dispatcher-summary-toggle]');
        if (summaryButtonEl instanceof HTMLButtonElement && event.key === 'ArrowDown') {
            event.preventDefault();
            openSummaryPopover(summaryButtonEl);
        }
    });

    document.addEventListener('scroll', repositionSummaryPopover, true);
    window.addEventListener('resize', repositionSummaryPopover);

    var activeRaceActionsState = null;

    var positionRaceActionsMenu = function (triggerEl, menuEl) {
        if (!(triggerEl instanceof HTMLElement) || !(menuEl instanceof HTMLElement)) {
            return;
        }

        var margin = 12;
        var viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        var viewportHeight = document.documentElement.clientHeight || window.innerHeight;
        var width = Math.min(168, Math.max(144, viewportWidth - margin * 2));
        var maxHeight = Math.min(220, Math.max(140, viewportHeight - margin * 2));

        menuEl.style.width = width + 'px';
        menuEl.style.maxHeight = maxHeight + 'px';

        var triggerRect = triggerEl.getBoundingClientRect();
        var menuRect = menuEl.getBoundingClientRect();
        var layerHeight = Math.min(menuRect.height || menuEl.scrollHeight || maxHeight, maxHeight);
        var left = triggerRect.right - width;

        if (left < margin) {
            left = triggerRect.left;
        }
        left = Math.max(margin, Math.min(left, viewportWidth - margin - width));

        var top = triggerRect.bottom + 8;
        var topIfAbove = triggerRect.top - layerHeight - 8;
        if (top + layerHeight > viewportHeight - margin && topIfAbove >= margin) {
            top = topIfAbove;
        } else if (top + layerHeight > viewportHeight - margin) {
            top = Math.max(margin, viewportHeight - margin - layerHeight);
        }
        top = Math.max(margin, Math.min(top, viewportHeight - margin - layerHeight));

        menuEl.style.left = Math.round(left) + 'px';
        menuEl.style.top = Math.round(top) + 'px';
    };

    var closeRaceActionsMenu = function (restoreFocus) {
        if (activeRaceActionsState === null) {
            return;
        }

        var previousState = activeRaceActionsState;
        activeRaceActionsState = null;
        previousState.button.setAttribute('aria-expanded', 'false');
        previousState.button.classList.remove('is-open');
        previousState.menu.hidden = true;
        previousState.menu.style.left = '';
        previousState.menu.style.top = '';
        previousState.menu.style.visibility = '';

        if (restoreFocus) {
            previousState.button.focus({ preventScroll: true });
        }
    };

    var openRaceActionsMenu = function (buttonEl, focusFirstItem) {
        if (!(buttonEl instanceof HTMLButtonElement)) {
            return;
        }

        var menuId = String(buttonEl.dataset.menuId || '');
        var menuEl = menuId !== '' ? document.getElementById(menuId) : null;
        if (!(menuEl instanceof HTMLElement)) {
            return;
        }

        closeRaceActionsMenu(false);
        closeSummaryPopover(false);
        if (typeof closeDispatcherColumnPanel === 'function') {
            closeDispatcherColumnPanel(false);
        }

        activeRaceActionsState = {
            button: buttonEl,
            menu: menuEl
        };

        buttonEl.setAttribute('aria-expanded', 'true');
        buttonEl.classList.add('is-open');
        menuEl.style.visibility = 'hidden';
        menuEl.hidden = false;
        positionRaceActionsMenu(buttonEl, menuEl);
        menuEl.style.visibility = '';

        if (focusFirstItem) {
            var firstItemEl = menuEl.querySelector('[role="menuitem"]');
            if (firstItemEl instanceof HTMLElement) {
                firstItemEl.focus({ preventScroll: true });
            }
        }
    };

    var repositionRaceActionsMenu = function () {
        if (activeRaceActionsState !== null) {
            positionRaceActionsMenu(activeRaceActionsState.button, activeRaceActionsState.menu);
        }
    };

    document.addEventListener('click', function (event) {
        var actionsButtonEl = closestElement(event.target, '[data-dispatcher-race-actions-toggle]');
        if (actionsButtonEl instanceof HTMLButtonElement) {
            event.preventDefault();
            if (activeRaceActionsState !== null && activeRaceActionsState.button === actionsButtonEl) {
                closeRaceActionsMenu(false);
                return;
            }
            openRaceActionsMenu(actionsButtonEl, false);
            return;
        }

        if (closestElement(event.target, '[data-dispatcher-race-actions-menu]') === null) {
            closeRaceActionsMenu(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        var actionsButtonEl = closestElement(event.target, '[data-dispatcher-race-actions-toggle]');
        if (actionsButtonEl instanceof HTMLButtonElement && event.key === 'ArrowDown') {
            event.preventDefault();
            openRaceActionsMenu(actionsButtonEl, true);
            return;
        }

        if (event.key === 'Escape' && activeRaceActionsState !== null) {
            event.preventDefault();
            closeRaceActionsMenu(true);
            return;
        }

        if (activeRaceActionsState === null || !activeRaceActionsState.menu.contains(event.target)) {
            return;
        }

        var menuItems = Array.prototype.slice.call(activeRaceActionsState.menu.querySelectorAll('[role="menuitem"]'))
            .filter(function (itemEl) {
                return itemEl instanceof HTMLElement && !itemEl.hasAttribute('disabled');
            });
        if (menuItems.length === 0) {
            return;
        }

        var currentIndex = menuItems.indexOf(document.activeElement);
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            menuItems[(currentIndex + 1 + menuItems.length) % menuItems.length].focus({ preventScroll: true });
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            menuItems[(currentIndex - 1 + menuItems.length) % menuItems.length].focus({ preventScroll: true });
        } else if (event.key === 'Home') {
            event.preventDefault();
            menuItems[0].focus({ preventScroll: true });
        } else if (event.key === 'End') {
            event.preventDefault();
            menuItems[menuItems.length - 1].focus({ preventScroll: true });
        }
    });

    document.addEventListener('scroll', repositionRaceActionsMenu, true);
    window.addEventListener('resize', repositionRaceActionsMenu);

    var columnManagerEl = document.querySelector('[data-dispatcher-column-manager]');
    var columnToggleEl = document.querySelector('[data-dispatcher-columns-toggle]');
    var columnPanelEl = document.querySelector('[data-dispatcher-columns-panel]');
    var columnListEl = document.querySelector('[data-dispatcher-columns-list]');
    var columnResetEl = document.querySelector('[data-dispatcher-columns-reset]');
    var columnTableEl = document.querySelector('[data-dispatcher-column-table]');
    var columnStorageKey = 'fleet.dispecerCurse.columns.v1';
    var defaultDispatcherColumns = [
        { key: 'plate', label: 'Nr. Inmatriculare', required: true },
        { key: 'registration_details', label: 'Detalii inregistrare' },
        { key: 'driver', label: 'Sofer' },
        { key: 'type', label: 'Tip Transport' },
        { key: 'loading_date', label: 'Data incarcare' },
        { key: 'interval', label: 'Interval' },
        { key: 'duration', label: 'Durata cursa' },
        { key: 'diurna', label: 'Diurna' },
        { key: 'route', label: 'Traseu' },
        { key: 'beneficiary', label: 'Beneficiar' },
        { key: 'goods_type', label: 'Tip marfa' },
        { key: 'activity', label: 'Activitate' },
        { key: 'financial', label: 'Financiar' },
        { key: 'observations', label: 'Observatii' },
        { key: 'actions', label: 'Actiuni', required: true }
    ];
    var defaultDispatcherColumnOrder = defaultDispatcherColumns.map(function (column) {
        return column.key;
    });
    var dispatcherColumnByKey = defaultDispatcherColumns.reduce(function (columns, column) {
        columns[column.key] = column;
        return columns;
    }, {});
    var dispatcherColumnState = null;

    var normalizeDispatcherColumnState = function (rawState) {
        var rawOrder = rawState && Array.isArray(rawState.order) ? rawState.order : [];
        var order = [];
        rawOrder.forEach(function (columnKey) {
            if (dispatcherColumnByKey[columnKey] && order.indexOf(columnKey) === -1) {
                order.push(columnKey);
            }
        });
        defaultDispatcherColumnOrder.forEach(function (columnKey) {
            if (order.indexOf(columnKey) === -1) {
                var insertIndex = order.length;
                for (var nextIndex = defaultDispatcherColumnOrder.indexOf(columnKey) + 1; nextIndex < defaultDispatcherColumnOrder.length; nextIndex++) {
                    var nextColumnKey = defaultDispatcherColumnOrder[nextIndex];
                    var existingIndex = order.indexOf(nextColumnKey);
                    if (existingIndex !== -1) {
                        insertIndex = existingIndex;
                        break;
                    }
                }
                order.splice(insertIndex, 0, columnKey);
            }
        });

        var rawVisible = rawState && rawState.visible && typeof rawState.visible === 'object' ? rawState.visible : {};
        var visible = {};
        defaultDispatcherColumns.forEach(function (column) {
            visible[column.key] = column.required ? true : rawVisible[column.key] !== false;
        });

        return {
            order: order,
            visible: visible
        };
    };

    var loadDispatcherColumnState = function () {
        try {
            return normalizeDispatcherColumnState(JSON.parse(window.localStorage.getItem(columnStorageKey) || 'null'));
        } catch (error) {
            return normalizeDispatcherColumnState(null);
        }
    };

    var saveDispatcherColumnState = function () {
        try {
            window.localStorage.setItem(columnStorageKey, JSON.stringify(dispatcherColumnState));
        } catch (error) {
            // localStorage can be unavailable in restricted browser modes.
        }
    };

    var resetDispatcherColumnState = function () {
        dispatcherColumnState = normalizeDispatcherColumnState({
            order: defaultDispatcherColumnOrder.slice(),
            visible: {}
        });
        try {
            window.localStorage.removeItem(columnStorageKey);
        } catch (error) {
            // Ignore storage failures and still reset the current view.
        }
        applyDispatcherColumnState();
    };

    var isDispatcherColumnStateCustomized = function () {
        if (dispatcherColumnState === null) {
            return false;
        }

        for (var orderIndex = 0; orderIndex < defaultDispatcherColumnOrder.length; orderIndex++) {
            if (dispatcherColumnState.order[orderIndex] !== defaultDispatcherColumnOrder[orderIndex]) {
                return true;
            }
        }

        return defaultDispatcherColumns.some(function (column) {
            return dispatcherColumnState.visible[column.key] === false;
        });
    };

    var getDispatcherColumnElements = function (containerEl) {
        var elementsByKey = {};
        Array.prototype.slice.call(containerEl.children).forEach(function (childEl, childIndex) {
            if (!(childEl instanceof HTMLElement) && !(childEl instanceof HTMLTableColElement)) {
                return;
            }

            if (!childEl.dataset.dispatcherColumnKey && defaultDispatcherColumns[childIndex]) {
                childEl.dataset.dispatcherColumnKey = defaultDispatcherColumns[childIndex].key;
            }

            var columnKey = childEl.dataset.dispatcherColumnKey || '';
            if (dispatcherColumnByKey[columnKey]) {
                elementsByKey[columnKey] = childEl;
            }
        });

        return elementsByKey;
    };

    var reorderDispatcherColumnContainer = function (containerEl, order) {
        var elementsByKey = getDispatcherColumnElements(containerEl);
        order.forEach(function (columnKey) {
            if (elementsByKey[columnKey]) {
                containerEl.appendChild(elementsByKey[columnKey]);
            }
        });
    };

    var setDispatcherColumnVisibility = function (containerEl) {
        var elementsByKey = getDispatcherColumnElements(containerEl);
        defaultDispatcherColumnOrder.forEach(function (columnKey) {
            if (!elementsByKey[columnKey]) {
                return;
            }

            elementsByKey[columnKey].style.display = dispatcherColumnState.visible[columnKey] === false ? 'none' : '';
        });
    };

    function applyDispatcherColumnState() {
        if (!(columnTableEl instanceof HTMLTableElement) || dispatcherColumnState === null) {
            return;
        }

        var colgroupEl = columnTableEl.querySelector('colgroup');
        var headerRowEl = columnTableEl.querySelector('thead tr');
        if (colgroupEl instanceof HTMLElement) {
            reorderDispatcherColumnContainer(colgroupEl, dispatcherColumnState.order);
            setDispatcherColumnVisibility(colgroupEl);
        }
        if (headerRowEl instanceof HTMLTableRowElement) {
            reorderDispatcherColumnContainer(headerRowEl, dispatcherColumnState.order);
            setDispatcherColumnVisibility(headerRowEl);
        }

        Array.prototype.slice.call(columnTableEl.querySelectorAll('tbody tr')).forEach(function (rowEl) {
            if (!(rowEl instanceof HTMLTableRowElement) || rowEl.cells.length !== defaultDispatcherColumns.length) {
                return;
            }

            reorderDispatcherColumnContainer(rowEl, dispatcherColumnState.order);
            setDispatcherColumnVisibility(rowEl);
        });

        var visibleColumnCount = defaultDispatcherColumns.filter(function (column) {
            return dispatcherColumnState.visible[column.key] !== false;
        }).length;
        columnTableEl.style.setProperty('--dispatcher-visible-columns', String(Math.max(1, visibleColumnCount)));
        columnTableEl.classList.toggle('dispatcher-columns-customized', isDispatcherColumnStateCustomized());
        renderDispatcherColumnList();
    }

    var moveDispatcherColumn = function (columnKey, direction) {
        var currentIndex = dispatcherColumnState.order.indexOf(columnKey);
        var targetIndex = currentIndex + direction;
        if (currentIndex < 0 || targetIndex < 0 || targetIndex >= dispatcherColumnState.order.length) {
            return;
        }

        dispatcherColumnState.order.splice(currentIndex, 1);
        dispatcherColumnState.order.splice(targetIndex, 0, columnKey);
        saveDispatcherColumnState();
        applyDispatcherColumnState();
    };

    function renderDispatcherColumnList() {
        if (!(columnListEl instanceof HTMLElement) || dispatcherColumnState === null) {
            return;
        }

        columnListEl.innerHTML = '';
        dispatcherColumnState.order.forEach(function (columnKey, orderIndex) {
            var column = dispatcherColumnByKey[columnKey];
            if (!column) {
                return;
            }

            var itemEl = document.createElement('div');
            itemEl.className = 'dispatcher-column-item';

            var labelEl = document.createElement('label');
            labelEl.className = 'dispatcher-column-check';

            var checkboxEl = document.createElement('input');
            checkboxEl.type = 'checkbox';
            checkboxEl.checked = dispatcherColumnState.visible[columnKey] !== false;
            checkboxEl.disabled = column.required === true;
            checkboxEl.setAttribute('aria-label', 'Afiseaza coloana ' + column.label);
            checkboxEl.addEventListener('change', function () {
                dispatcherColumnState.visible[columnKey] = checkboxEl.checked;
                saveDispatcherColumnState();
                applyDispatcherColumnState();
            });

            var labelTextEl = document.createElement('span');
            labelTextEl.textContent = column.label;

            labelEl.appendChild(checkboxEl);
            labelEl.appendChild(labelTextEl);

            var upButtonEl = document.createElement('button');
            upButtonEl.type = 'button';
            upButtonEl.className = 'dispatcher-column-order-btn';
            upButtonEl.disabled = orderIndex === 0;
            upButtonEl.setAttribute('aria-label', 'Muta coloana ' + column.label + ' mai sus');
            upButtonEl.innerHTML = '<i class="bi bi-chevron-up" aria-hidden="true"></i>';
            upButtonEl.addEventListener('click', function () {
                moveDispatcherColumn(columnKey, -1);
            });

            var downButtonEl = document.createElement('button');
            downButtonEl.type = 'button';
            downButtonEl.className = 'dispatcher-column-order-btn';
            downButtonEl.disabled = orderIndex === dispatcherColumnState.order.length - 1;
            downButtonEl.setAttribute('aria-label', 'Muta coloana ' + column.label + ' mai jos');
            downButtonEl.innerHTML = '<i class="bi bi-chevron-down" aria-hidden="true"></i>';
            downButtonEl.addEventListener('click', function () {
                moveDispatcherColumn(columnKey, 1);
            });

            itemEl.appendChild(labelEl);
            itemEl.appendChild(upButtonEl);
            itemEl.appendChild(downButtonEl);
            columnListEl.appendChild(itemEl);
        });
    }

    var closeDispatcherColumnPanel = function (restoreFocus) {
        if (!(columnPanelEl instanceof HTMLElement) || !(columnToggleEl instanceof HTMLButtonElement) || columnPanelEl.hidden) {
            return;
        }

        columnPanelEl.hidden = true;
        columnToggleEl.setAttribute('aria-expanded', 'false');
        columnToggleEl.classList.remove('is-open');

        var iconEl = columnToggleEl.querySelector('.dispatcher-column-toggle-chevron');
        if (iconEl instanceof HTMLElement) {
            iconEl.classList.remove('bi-chevron-up');
            iconEl.classList.add('bi-chevron-down');
        }

        if (restoreFocus) {
            columnToggleEl.focus({ preventScroll: true });
        }
    };

    var openDispatcherColumnPanel = function () {
        if (!(columnPanelEl instanceof HTMLElement) || !(columnToggleEl instanceof HTMLButtonElement)) {
            return;
        }

        closeSummaryPopover(false);
        closeRaceActionsMenu(false);
        columnPanelEl.hidden = false;
        columnToggleEl.setAttribute('aria-expanded', 'true');
        columnToggleEl.classList.add('is-open');

        var iconEl = columnToggleEl.querySelector('.dispatcher-column-toggle-chevron');
        if (iconEl instanceof HTMLElement) {
            iconEl.classList.remove('bi-chevron-down');
            iconEl.classList.add('bi-chevron-up');
        }
    };

    if (
        columnManagerEl instanceof HTMLElement
        && columnToggleEl instanceof HTMLButtonElement
        && columnPanelEl instanceof HTMLElement
        && columnListEl instanceof HTMLElement
        && columnTableEl instanceof HTMLTableElement
    ) {
        dispatcherColumnState = loadDispatcherColumnState();
        applyDispatcherColumnState();

        columnToggleEl.addEventListener('click', function (event) {
            event.preventDefault();
            if (columnPanelEl.hidden) {
                openDispatcherColumnPanel();
                return;
            }
            closeDispatcherColumnPanel(false);
        });

        if (columnResetEl instanceof HTMLButtonElement) {
            columnResetEl.addEventListener('click', function () {
                resetDispatcherColumnState();
            });
        }

        document.addEventListener('click', function (event) {
            if (columnPanelEl.hidden || closestElement(event.target, '[data-dispatcher-column-manager]') !== null) {
                return;
            }
            closeDispatcherColumnPanel(false);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !columnPanelEl.hidden) {
                event.preventDefault();
                closeDispatcherColumnPanel(true);
            }
        });
    }

    var openRacesToggleEl = document.querySelector('[data-open-races-toggle]');
    var openRacesModalEl = document.querySelector('[data-open-races-panel]');
    var openRacesCloseEls = document.querySelectorAll('[data-open-races-close]');
    var openRacesFilterEls = document.querySelectorAll('[data-open-races-filter]');
    var lastOpenRacesFocusEl = null;
    if (
        openRacesToggleEl instanceof HTMLButtonElement
        && openRacesModalEl instanceof HTMLElement
    ) {
        var isOpenRacesOpen = function () {
            return !openRacesModalEl.classList.contains('d-none');
        };

        var setOpenRaceCardExpanded = function (cardEl, expanded) {
            if (!(cardEl instanceof HTMLElement)) {
                return;
            }

            var detailsEl = cardEl.querySelector('[data-open-race-card-details]');
            var toggleEl = cardEl.querySelector('[data-open-race-card-toggle]');
            var iconEl = toggleEl instanceof HTMLElement ? toggleEl.querySelector('i') : null;

            cardEl.classList.toggle('is-expanded', expanded);

            if (detailsEl instanceof HTMLElement) {
                detailsEl.hidden = !expanded;
            }

            if (toggleEl instanceof HTMLButtonElement) {
                toggleEl.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                toggleEl.setAttribute('aria-label', expanded ? 'Restrange lipsurile' : 'Extinde lipsurile');
            }

            if (iconEl instanceof HTMLElement) {
                iconEl.classList.toggle('bi-chevron-up', expanded);
                iconEl.classList.toggle('bi-chevron-down', !expanded);
            }
        };

        // Filtrare combinata: tab severitate + tip transport + numere de inmatriculare (multi-select).
        var orxActiveSeverity = '';
        var orxSeverityTabEls = openRacesModalEl.querySelectorAll('[data-orx-severity-tab]');
        var orxTransportSelectEl = openRacesModalEl.querySelector('[data-orx-transport]');
        var orxPlatesWrapEl = openRacesModalEl.querySelector('[data-orx-plates]');
        var orxPlatesToggleEl = openRacesModalEl.querySelector('[data-orx-plates-toggle]');
        var orxPlatesMenuEl = openRacesModalEl.querySelector('[data-orx-plates-menu]');
        var orxPlatesSearchEl = openRacesModalEl.querySelector('[data-orx-plates-search]');
        var orxPlatesAllEl = openRacesModalEl.querySelector('[data-orx-plates-all]');
        var orxPlatesLabelEl = openRacesModalEl.querySelector('[data-orx-plates-label]');
        var orxPlatesCountEl = openRacesModalEl.querySelector('[data-orx-plates-count]');
        var orxPlatesClearEl = openRacesModalEl.querySelector('[data-orx-plates-clear]');
        var orxPlateOptionEls = openRacesModalEl.querySelectorAll('[data-orx-plate-option]');
        var orxEmptyEl = openRacesModalEl.querySelector('[data-orx-empty]');

        var orxSelectedPlates = function () {
            var plates = [];
            orxPlateOptionEls.forEach(function (optionEl) {
                if (optionEl instanceof HTMLInputElement && optionEl.checked) {
                    plates.push(optionEl.value);
                }
            });
            return plates;
        };

        var orxSyncPlatesUi = function () {
            var selected = orxSelectedPlates();
            if (orxPlatesLabelEl instanceof HTMLElement) {
                orxPlatesLabelEl.textContent = selected.length > 0
                    ? 'Nr. înmatriculare (' + selected.length + ')'
                    : 'Nr. înmatriculare';
            }
            if (orxPlatesCountEl instanceof HTMLElement) {
                orxPlatesCountEl.textContent = selected.length === 1
                    ? '1 vehicul selectat'
                    : selected.length + ' vehicule selectate';
            }
            if (orxPlatesAllEl instanceof HTMLInputElement) {
                var total = orxPlateOptionEls.length;
                orxPlatesAllEl.checked = total > 0 && selected.length === total;
                orxPlatesAllEl.indeterminate = selected.length > 0 && selected.length < total;
            }
        };

        var applyOpenRacesFilters = function () {
            var transport = orxTransportSelectEl instanceof HTMLSelectElement ? orxTransportSelectEl.value : '';
            var plates = orxSelectedPlates();
            var visibleCount = 0;

            openRacesModalEl.querySelectorAll('[data-open-race-card]').forEach(function (cardEl) {
                if (!(cardEl instanceof HTMLElement)) {
                    return;
                }

                var matches = (orxActiveSeverity === '' || cardEl.getAttribute('data-orx-severity-value') === orxActiveSeverity)
                    && (transport === '' || cardEl.getAttribute('data-orx-transport-value') === transport)
                    && (plates.length === 0 || plates.indexOf(cardEl.getAttribute('data-orx-plate-value') || '') !== -1);

                cardEl.hidden = !matches;
                if (!matches) {
                    setOpenRaceCardExpanded(cardEl, false);
                } else {
                    visibleCount++;
                }
            });

            if (orxEmptyEl instanceof HTMLElement) {
                orxEmptyEl.hidden = visibleCount > 0;
            }

            // Numarul din titlu reflecta cursele care corespund filtrelor active:
            // fara filtre -> totalul; cu filtre -> "N din total".
            var orxHeaderCountEl = openRacesModalEl.querySelector('[data-orx-header-count]');
            if (orxHeaderCountEl instanceof HTMLElement) {
                var orxTotalCount = orxHeaderCountEl.getAttribute('data-orx-total') || '0';
                var orxHasActiveFilters = orxActiveSeverity !== ''
                    || transport !== ''
                    || plates.length > 0;
                orxHeaderCountEl.textContent = orxHasActiveFilters
                    ? visibleCount + ' din ' + orxTotalCount
                    : orxTotalCount;
            }

            orxSeverityTabEls.forEach(function (tabEl) {
                if (!(tabEl instanceof HTMLButtonElement)) {
                    return;
                }
                var isActive = (tabEl.getAttribute('data-orx-severity-tab') || '') === orxActiveSeverity;
                tabEl.classList.toggle('is-active', isActive);
                tabEl.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });

            orxSyncPlatesUi();
        };

        var setOpenRacesFilter = function () {
            // Reset complet al filtrelor (la deschiderea popup-ului).
            orxActiveSeverity = '';
            if (orxTransportSelectEl instanceof HTMLSelectElement) {
                orxTransportSelectEl.value = '';
            }
            orxPlateOptionEls.forEach(function (optionEl) {
                if (optionEl instanceof HTMLInputElement) {
                    optionEl.checked = false;
                }
            });
            if (orxPlatesSearchEl instanceof HTMLInputElement) {
                orxPlatesSearchEl.value = '';
                orxPlateOptionEls.forEach(function (optionEl) {
                    var labelEl = optionEl instanceof HTMLElement ? optionEl.closest('.orx-plates-option') : null;
                    if (labelEl instanceof HTMLElement) {
                        labelEl.classList.remove('d-none');
                    }
                });
            }
            if (orxPlatesMenuEl instanceof HTMLElement) {
                orxPlatesMenuEl.hidden = true;
            }
            applyOpenRacesFilters();
        };

        orxSeverityTabEls.forEach(function (tabEl) {
            if (!(tabEl instanceof HTMLButtonElement)) {
                return;
            }
            tabEl.addEventListener('click', function () {
                orxActiveSeverity = tabEl.getAttribute('data-orx-severity-tab') || '';
                applyOpenRacesFilters();
            });
        });

        if (orxTransportSelectEl instanceof HTMLSelectElement) {
            orxTransportSelectEl.addEventListener('change', applyOpenRacesFilters);
        }

        if (orxPlatesToggleEl instanceof HTMLButtonElement && orxPlatesMenuEl instanceof HTMLElement) {
            orxPlatesToggleEl.addEventListener('click', function () {
                var willOpen = orxPlatesMenuEl.hidden;
                orxPlatesMenuEl.hidden = !willOpen;
                orxPlatesToggleEl.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                if (willOpen && orxPlatesSearchEl instanceof HTMLInputElement) {
                    orxPlatesSearchEl.focus();
                }
            });
            document.addEventListener('click', function (event) {
                if (orxPlatesMenuEl.hidden) {
                    return;
                }
                if (event.target instanceof Node && orxPlatesWrapEl instanceof HTMLElement && !orxPlatesWrapEl.contains(event.target)) {
                    orxPlatesMenuEl.hidden = true;
                    orxPlatesToggleEl.setAttribute('aria-expanded', 'false');
                }
            });
        }

        orxPlateOptionEls.forEach(function (optionEl) {
            if (optionEl instanceof HTMLInputElement) {
                optionEl.addEventListener('change', applyOpenRacesFilters);
            }
        });

        if (orxPlatesAllEl instanceof HTMLInputElement) {
            orxPlatesAllEl.addEventListener('change', function () {
                var check = orxPlatesAllEl.checked;
                orxPlateOptionEls.forEach(function (optionEl) {
                    var labelEl = optionEl instanceof HTMLElement ? optionEl.closest('.orx-plates-option') : null;
                    var isVisible = !(labelEl instanceof HTMLElement) || !labelEl.classList.contains('d-none');
                    if (optionEl instanceof HTMLInputElement && isVisible) {
                        optionEl.checked = check;
                    }
                });
                applyOpenRacesFilters();
            });
        }

        if (orxPlatesSearchEl instanceof HTMLInputElement) {
            orxPlatesSearchEl.addEventListener('input', function () {
                var needle = orxPlatesSearchEl.value.toLowerCase();
                orxPlateOptionEls.forEach(function (optionEl) {
                    var labelEl = optionEl instanceof HTMLElement ? optionEl.closest('.orx-plates-option') : null;
                    if (labelEl instanceof HTMLElement) {
                        labelEl.classList.toggle('d-none', labelEl.textContent.toLowerCase().indexOf(needle) === -1);
                    }
                });
            });
        }

        if (orxPlatesClearEl instanceof HTMLElement) {
            orxPlatesClearEl.addEventListener('click', function (event) {
                event.preventDefault();
                orxPlateOptionEls.forEach(function (optionEl) {
                    if (optionEl instanceof HTMLInputElement) {
                        optionEl.checked = false;
                    }
                });
                applyOpenRacesFilters();
            });
        }

        var setOpenRacesOpen = function (open) {
            if (open === isOpenRacesOpen()) {
                return;
            }

            openRacesToggleEl.setAttribute('aria-expanded', open ? 'true' : 'false');
            openRacesModalEl.classList.toggle('d-none', !open);
            document.body.classList.toggle('dispatcher-open-races-modal-open', open);

            if (open) {
                lastOpenRacesFocusEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;
                setOpenRacesFilter('');
                var closeEl = openRacesModalEl.querySelector('[data-open-races-close]');
                if (closeEl instanceof HTMLElement) {
                    closeEl.focus();
                }
                return;
            }

            if (lastOpenRacesFocusEl instanceof HTMLElement) {
                lastOpenRacesFocusEl.focus();
            }
        };

        openRacesToggleEl.addEventListener('click', function () {
            setOpenRacesOpen(true);
        });

        openRacesCloseEls.forEach(function (closeEl) {
            if (!(closeEl instanceof HTMLButtonElement)) {
                return;
            }

            closeEl.addEventListener('click', function () {
                setOpenRacesOpen(false);
            });
        });

        openRacesFilterEls.forEach(function (filterEl) {
            if (!(filterEl instanceof HTMLButtonElement)) {
                return;
            }

            filterEl.addEventListener('click', function () {
                if (filterEl.disabled) {
                    return;
                }

                setOpenRacesFilter(filterEl.getAttribute('data-open-races-filter') || '');
            });
        });

        openRacesModalEl.querySelectorAll('[data-open-race-card-toggle]').forEach(function (toggleEl) {
            if (!(toggleEl instanceof HTMLButtonElement)) {
                return;
            }

            toggleEl.addEventListener('click', function () {
                var cardEl = toggleEl.closest('[data-open-race-card]');
                if (!(cardEl instanceof HTMLElement)) {
                    return;
                }

                var expanded = toggleEl.getAttribute('aria-expanded') !== 'true';
                if (expanded) {
                    openRacesModalEl.querySelectorAll('[data-open-race-card]').forEach(function (otherCardEl) {
                        setOpenRaceCardExpanded(otherCardEl, false);
                    });
                }

                setOpenRaceCardExpanded(cardEl, expanded);
            });
        });

        document.addEventListener('click', function (event) {
            if (!isOpenRacesOpen()) {
                return;
            }

            var targetEl = event.target;
            if (!(targetEl instanceof Node)) {
                return;
            }

            if (targetEl === openRacesModalEl) {
                setOpenRacesOpen(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isOpenRacesOpen()) {
                setOpenRacesOpen(false);
            }
        });
    }

});
</script>

<div class="modal fade" tabindex="-1" data-vehicle-route-decision-modal aria-labelledby="vehicle-route-decision-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="vehicle-route-decision-title">
                    <i class="bi bi-truck me-1" aria-hidden="true"></i>
                    Vehicul neconfigurat pe rută
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Renunță"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Vehiculul <strong data-vehicle-route-decision-name>-</strong> nu este configurat pentru beneficiarul și tipul de transport ales (Configurare Transport).</p>
                <p class="mb-0">Cum vrei să îl folosești?</p>
            </div>
            <div class="modal-footer flex-wrap">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Renunță</button>
                <button type="button" class="btn btn-outline-primary" data-vehicle-route-decision-trip title="Vehiculul este folosit doar pentru această cursă, fără a modifica Configurare Transport.">Doar pentru această cursă</button>
                <button type="button" class="btn btn-primary" data-vehicle-route-decision-permanent title="Vehiculul este adăugat pe ruta corespunzătoare (beneficiar + tip transport) în Configurare Transport.">Adaugă permanent pe rută</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_inactive_resource_modal.php'; ?>

<?php if (!empty($maintenancePopupMessages)): ?>
    <div class="modal fade" id="kmRevizieAlertModal" tabindex="-1" aria-labelledby="kmRevizieAlertTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="kmRevizieAlertTitle">Alertă revizie vehicul</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Unul sau mai multe vehicule au ajuns la pragul de revizie:</p>
                    <ul class="mb-0">
                        <?php foreach ((array) $maintenancePopupMessages as $popupMessage): ?>
                            <li><?= e((string) $popupMessage) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Am înțeles</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modalElement = document.getElementById('kmRevizieAlertModal');
            if (!modalElement) {
                return;
            }

            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var modal = new bootstrap.Modal(modalElement);
                modal.show();
                return;
            }

            alert(<?= json_encode(implode("\n", (array) $maintenancePopupMessages), JSON_UNESCAPED_UNICODE) ?>);
        });
    </script>
<?php endif; ?>

<?php $incompleteConfirmItems = is_array($incompleteConfirmItems ?? null) ? array_values(array_filter(array_map('strval', $incompleteConfirmItems))) : []; ?>
<?php if ($incompleteConfirmItems !== []): ?>
    <div class="modal fade" id="incompleteTripConfirmModal" tabindex="-1" aria-labelledby="incompleteTripConfirmTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="incompleteTripConfirmTitle">Salvezi cursa fara toate informatiile?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Cursei ii lipsesc urmatoarele informatii:</p>
                    <ul class="mb-2">
                        <?php foreach ($incompleteConfirmItems as $incompleteConfirmItem): ?>
                            <li><?= e($incompleteConfirmItem) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="text-muted small mb-0">Poti salva oricum — cursa va aparea in meniul „curse cu informatii lipsa" si o poti completa ulterior de acolo.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Nu, completez acum</button>
                    <button type="button" class="btn btn-primary" data-role="confirm-incomplete-save">Da, salveaza oricum</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var incompleteModalElement = document.getElementById('incompleteTripConfirmModal');
            if (!incompleteModalElement) { return; }
            var incompleteRaceForm = document.querySelector('form.dispatcher-race-form');
            var incompleteConfirmButton = incompleteModalElement.querySelector('[data-role="confirm-incomplete-save"]');
            if (incompleteConfirmButton && incompleteRaceForm) {
                incompleteConfirmButton.addEventListener('click', function () {
                    var incompleteFlagInput = incompleteRaceForm.querySelector('input[name="confirm_incomplete"]');
                    if (incompleteFlagInput) { incompleteFlagInput.value = '1'; }
                    if (typeof incompleteRaceForm.requestSubmit === 'function') {
                        incompleteRaceForm.requestSubmit();
                    } else {
                        incompleteRaceForm.submit();
                    }
                });
            }
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                new bootstrap.Modal(incompleteModalElement).show();
                return;
            }
            incompleteModalElement.classList.add('show');
            incompleteModalElement.style.display = 'block';
        });
    </script>
<?php endif; ?>

<script src="<?= e(url('assets/js/dispecer-curse.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/dispecer-curse.js'))) ?>"></script>
