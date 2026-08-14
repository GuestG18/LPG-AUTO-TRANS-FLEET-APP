<?php
$transportTypeOptions = is_array($transportTypeOptions ?? null) ? $transportTypeOptions : [
    'primar' => 'Primar km',
    'distributie' => 'Distributie',
    'primar_distributie' => 'Primar+Distributie',
    'compresor' => 'Compresor',
];
$distributionRouteTariffModeOptions = is_array($distributionRouteTariffModeOptions ?? null) ? $distributionRouteTariffModeOptions : [
    'tona_km' => 'Pret tona + Pret km',
    'tona' => 'Doar Pret tona',
    'km' => 'Doar Pret km',
];

$selectedTransportTypes = $beneficiaryFormData['tip_transporturi'] ?? [];
if (!is_array($selectedTransportTypes)) {
    $selectedTransportTypes = [];
}
$selectedTransportTypes = array_values(array_unique(array_map('strval', $selectedTransportTypes)));

$selectedTransportLabels = [];
foreach ($transportTypeOptions as $transportTypeValue => $transportTypeLabel) {
    if (in_array((string) $transportTypeValue, $selectedTransportTypes, true)) {
        $selectedTransportLabels[] = (string) $transportTypeLabel;
    }
}
$selectedTransportButtonLabel = $selectedTransportLabels !== [] ? implode(', ', $selectedTransportLabels) : '-- Selecteaza --';

$isPrimarSelected = in_array('primar', $selectedTransportTypes, true);
$isDistributieSelected = in_array('distributie', $selectedTransportTypes, true);
$isPrimaryDistributionSelected = in_array('primar_distributie', $selectedTransportTypes, true);
$isCompresorSelected = in_array('compresor', $selectedTransportTypes, true);
$isCatalogSelected = $isPrimarSelected || $isDistributieSelected || $isPrimaryDistributionSelected;

$distributionBeneficiaryId = (int) ($distributionBeneficiaryId ?? 0);
$distributionConfigReady = $distributionBeneficiaryId > 0 && $isDistributieSelected;
$catalogConfigReady = $distributionBeneficiaryId > 0 && $isCatalogSelected;
$distributionBeneficiaryName = trim((string) ($beneficiaryFormData['nume'] ?? ''));

$locSelectedVehicleIds = array_map('strval', (array) ($locFormData['vehicle_ids'] ?? []));
$locSelectedVehicleLabels = [];
foreach (($vehicles ?? []) as $vehicle) {
    $vehicleId = (int) ($vehicle['id'] ?? 0);
    $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? ''));
    if ($vehicleId > 0 && in_array((string) $vehicleId, $locSelectedVehicleIds, true)) {
        $locSelectedVehicleLabels[] = trim($vehicleLabel);
    }
}
$locVehicleButtonLabel = $locSelectedVehicleLabels !== [] ? implode(', ', $locSelectedVehicleLabels) : '-- Fara alocare --';

$zoneSelectedVehicleIds = array_map('strval', (array) ($zoneFormData['vehicle_ids'] ?? []));
$zoneSelectedVehicleLabels = [];
foreach (($vehicles ?? []) as $vehicle) {
    $vehicleId = (int) ($vehicle['id'] ?? 0);
    $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? ''));
    if ($vehicleId > 0 && in_array((string) $vehicleId, $zoneSelectedVehicleIds, true)) {
        $zoneSelectedVehicleLabels[] = trim($vehicleLabel);
    }
}
$zoneVehicleButtonLabel = $zoneSelectedVehicleLabels !== [] ? implode(', ', $zoneSelectedVehicleLabels) : '-- Fara alocare --';

$distributionOnlyRouteRules = is_array($distributionOnlyRouteRules ?? null) ? $distributionOnlyRouteRules : [];
$primaryDistributionRouteRules = is_array($primaryDistributionRouteRules ?? null) ? $primaryDistributionRouteRules : [];
$distributionOnlyRouteFormData = is_array($distributionOnlyRouteFormData ?? null) ? $distributionOnlyRouteFormData : [];
$distributionOnlyRouteFormErrors = is_array($distributionOnlyRouteFormErrors ?? null) ? $distributionOnlyRouteFormErrors : [];
$distributionOnlyRouteFormMode = trim((string) ($distributionOnlyRouteFormMode ?? '')) !== ''
    ? (string) $distributionOnlyRouteFormMode
    : (trim((string) ($distributionOnlyRouteFormData['route_id'] ?? '')) !== '' ? 'edit' : 'create');
$isDistributionOnlyRouteEditMode = $distributionOnlyRouteFormMode === 'edit';
$primaryDistributionRouteFormData = is_array($primaryDistributionRouteFormData ?? null) ? $primaryDistributionRouteFormData : [];
$primaryDistributionRouteFormErrors = is_array($primaryDistributionRouteFormErrors ?? null) ? $primaryDistributionRouteFormErrors : [];
$primaryDistributionRouteFormMode = trim((string) ($primaryDistributionRouteFormMode ?? '')) !== ''
    ? (string) $primaryDistributionRouteFormMode
    : (trim((string) ($primaryDistributionRouteFormData['route_id'] ?? '')) !== '' ? 'edit' : 'create');
$isPrimaryDistributionRouteEditMode = $primaryDistributionRouteFormMode === 'edit';
$vehicleLabelById = [];
$vehiclePlateById = [];
$vehicleDetailsById = [];
foreach (($vehicles ?? []) as $vehicle) {
    $vehicleId = (int) ($vehicle['id'] ?? 0);
    if ($vehicleId <= 0) {
        continue;
    }

    $vehiclePlate = trim((string) ($vehicle['nr_inmatriculare'] ?? ''));
    if ($vehiclePlate === '') {
        $vehiclePlate = 'Vehicul #' . $vehicleId;
    }
    $vehicleName = trim((string) ($vehicle['nume'] ?? ($vehicle['denumire'] ?? '')));
    $vehicleBrand = trim((string) ($vehicle['marca'] ?? ''));
    $vehicleModel = trim((string) ($vehicle['model'] ?? ''));
    $vehicleDetail = trim($vehicleBrand . ' ' . $vehicleModel);
    $vehicleLabel = trim($vehiclePlate . ' - ' . $vehicleDetail);
    $vehicleLabelById[$vehicleId] = trim($vehicleLabel);
    $vehiclePlateById[$vehicleId] = $vehiclePlate;
    $vehicleDetailsById[$vehicleId] = [
        'id' => $vehicleId,
        'plate' => $vehiclePlate,
        'name' => $vehicleName,
        'brand' => $vehicleBrand,
        'model' => $vehicleModel,
        'detail' => $vehicleDetail,
        'label' => trim($vehicleLabel),
        'search' => trim(implode(' ', array_filter([$vehiclePlate, $vehicleName, $vehicleBrand, $vehicleModel, $vehicleLabel]))),
    ];
}
$buildRouteVehicleButtonLabel = static function (array $selectedVehicleIds, array $labelsById): string {
    $selectedLabels = [];
    foreach ($selectedVehicleIds as $selectedVehicleId) {
        $vehicleId = (int) $selectedVehicleId;
        if ($vehicleId <= 0 || !isset($labelsById[$vehicleId])) {
            continue;
        }

        $selectedLabels[] = $labelsById[$vehicleId];
    }

    return $selectedLabels !== [] ? implode(', ', $selectedLabels) : '-- Selecteaza vehiculele --';
};
$buildRouteVehicleItems = static function (string $vehicleIdsRaw, array $detailsById): array {
    $vehicleItems = [];
    foreach (explode(',', $vehicleIdsRaw) as $vehicleIdRaw) {
        $vehicleIdRaw = trim($vehicleIdRaw);
        if ($vehicleIdRaw === '' || !ctype_digit($vehicleIdRaw)) {
            continue;
        }

        $vehicleId = (int) $vehicleIdRaw;
        if ($vehicleId <= 0 || isset($vehicleItems[$vehicleId])) {
            continue;
        }

        if (isset($detailsById[$vehicleId])) {
            $vehicleItems[$vehicleId] = $detailsById[$vehicleId];
            continue;
        }

        $fallbackLabel = 'Vehicul #' . $vehicleId;
        $vehicleItems[$vehicleId] = [
            'id' => $vehicleId,
            'plate' => $fallbackLabel,
            'name' => $fallbackLabel,
            'brand' => '',
            'model' => '',
            'detail' => '',
            'label' => $fallbackLabel,
            'search' => $fallbackLabel,
        ];
    }

    return array_values($vehicleItems);
};
$renderRouteVehicleButton = static function (array $vehicleItems, string $rowKey): string {
    $vehicleCount = count($vehicleItems);
    $countLabel = $vehicleCount === 1 ? '1 vehicul' : $vehicleCount . ' vehicule';

    if ($vehicleCount === 0) {
        return '<span class="tcv2-vehicles-none" aria-label="Niciun vehicul alocat">&mdash;</span>';
    }

    $safeRowKey = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $rowKey) ?: uniqid('vehicles_', false);
    $popoverId = 'dispatcher_vehicle_popover_' . $safeRowKey;
    $vehicleTitle = implode(', ', array_map(static fn(array $item): string => (string) ($item['label'] ?? $item['plate'] ?? ''), $vehicleItems));
    $totalLabel = $vehicleCount === 1 ? 'Total 1 vehicul' : 'Total ' . $vehicleCount . ' vehicule';

    $html = '<div class="dispatcher-vehicle-list" data-dispatcher-vehicle-list>';
    $html .= '<button type="button" class="dispatcher-vehicle-count-btn" data-dispatcher-vehicle-toggle data-popover-id="' . e($popoverId) . '" aria-expanded="false" aria-controls="' . e($popoverId) . '" aria-label="' . e('Afiseaza ' . $countLabel) . '" title="' . e($vehicleTitle) . '">';
    $html .= '<span>' . e($countLabel) . '</span><i class="bi bi-chevron-down" aria-hidden="true"></i>';
    $html .= '</button>';
    $html .= '<div class="dispatcher-vehicle-popover" id="' . e($popoverId) . '" data-dispatcher-vehicle-popover role="dialog" aria-label="Lista vehicule alocate" hidden>';
    $html .= '<div class="dispatcher-vehicle-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="dispatcher-vehicle-search-input" data-dispatcher-vehicle-search placeholder="Caut&#259; vehicul..." aria-label="Cauta vehicul"></div>';
    $html .= '<ul class="dispatcher-vehicle-popover-list" role="list">';

    foreach ($vehicleItems as $vehicleItem) {
        $plate = trim((string) ($vehicleItem['plate'] ?? ''));
        $detail = trim((string) ($vehicleItem['detail'] ?? ''));
        $search = trim((string) ($vehicleItem['search'] ?? (($vehicleItem['label'] ?? '') . ' ' . $detail)));

        $html .= '<li class="dispatcher-vehicle-popover-item" data-dispatcher-vehicle-item data-vehicle-search="' . e($search) . '">';
        $html .= '<strong>' . e($plate !== '' ? $plate : '-') . '</strong>';
        if ($detail !== '') {
            $html .= '<span class="dispatcher-vehicle-separator" aria-hidden="true">&mdash;</span><span class="dispatcher-vehicle-detail">' . e($detail) . '</span>';
        }
        $html .= '</li>';
    }

    $html .= '</ul>';
    $html .= '<div class="dispatcher-vehicle-popover-empty" data-dispatcher-vehicle-empty hidden>Niciun vehicul gasit.</div>';
    $html .= '<div class="dispatcher-vehicle-popover-total">' . e($totalLabel) . '</div>';
    $html .= '</div></div>';

    return $html;
};
$renderTransportRowActions = static function (string $menuKey, string $editHref, string $deleteAction, array $deleteFields, string $confirmMessage, ?string $detailsHref = null): string {
    $safeMenuKey = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $menuKey) ?: uniqid('actions_', false);
    $menuId = 'transport_row_actions_' . $safeMenuKey;
    $html = '<div class="transport-row-actions" data-transport-actions>';
    $html .= '<button type="button" class="transport-row-actions-toggle" data-transport-actions-toggle data-menu-id="' . e($menuId) . '" aria-haspopup="menu" aria-expanded="false" aria-controls="' . e($menuId) . '" aria-label="Actiuni rand" title="Actiuni">';
    $html .= '<i class="bi bi-three-dots-vertical" aria-hidden="true"></i>';
    $html .= '</button>';
    $html .= '<div class="transport-row-actions-menu" id="' . e($menuId) . '" data-transport-actions-menu role="menu" hidden>';
    if ($detailsHref !== null && $detailsHref !== '') {
        $html .= '<a class="transport-row-actions-item" role="menuitem" href="' . e($detailsHref) . '">Detalii</a>';
    }
    $html .= '<a class="transport-row-actions-item" role="menuitem" href="' . e($editHref) . '">Editeaza</a>';
    $html .= '<form method="post" action="' . e($deleteAction) . '" class="transport-row-actions-form" role="none">';
    $html .= csrf_field();
    foreach ($deleteFields as $fieldName => $fieldValue) {
        $html .= '<input type="hidden" name="' . e((string) $fieldName) . '" value="' . e((string) $fieldValue) . '">';
    }
    $html .= '<button type="submit" class="transport-row-actions-item transport-row-actions-danger" role="menuitem" data-confirm="' . e($confirmMessage) . '">Sterge</button>';
    $html .= '</form></div></div>';

    return $html;
};
$distributionOnlyRouteSelectedVehicleIds = array_map('strval', (array) ($distributionOnlyRouteFormData['vehicle_ids'] ?? []));
$distributionOnlyRouteVehicleButtonLabel = $buildRouteVehicleButtonLabel($distributionOnlyRouteSelectedVehicleIds, $vehicleLabelById);
$primaryDistributionRouteSelectedVehicleIds = array_map('strval', (array) ($primaryDistributionRouteFormData['vehicle_ids'] ?? []));
$primaryDistributionRouteVehicleButtonLabel = $buildRouteVehicleButtonLabel($primaryDistributionRouteSelectedVehicleIds, $vehicleLabelById);
$primaryRouteSelectedVehicleIds = array_map('strval', (array) ($primaryRouteFormData['vehicle_ids'] ?? []));
$primaryRouteVehicleButtonLabel = $buildRouteVehicleButtonLabel($primaryRouteSelectedVehicleIds, $vehicleLabelById);
$compresorSelectedVehicleIds = array_map('strval', (array) ($beneficiaryFormData['compresor_vehicle_ids'] ?? []));
$compresorSelectedVehicleLabels = [];
$compresorSelectedVehiclePlates = [];
foreach ($compresorSelectedVehicleIds as $compresorSelectedVehicleId) {
    $vehicleId = (int) $compresorSelectedVehicleId;
    if ($vehicleId <= 0 || !isset($vehicleLabelById[$vehicleId])) {
        continue;
    }
    $compresorSelectedVehicleLabels[] = $vehicleLabelById[$vehicleId];
    if (isset($vehiclePlateById[$vehicleId])) {
        $compresorSelectedVehiclePlates[] = $vehiclePlateById[$vehicleId];
    }
}
$compresorVehicleCount = count($compresorSelectedVehiclePlates);
$compresorVehicleButtonLabel = '-- Selecteaza vehiculele --';
if ($compresorVehicleCount > 0) {
    $compresorVehicleButtonLabel = $compresorVehicleCount === 1
        ? '1 vehicul selectat'
        : $compresorVehicleCount . ' vehicule selectate';
}
$primaryRouteRules = is_array($primaryRouteRules ?? null) ? $primaryRouteRules : [];
$canAddDistributionRoute = ($locations ?? []) !== [] && ($zones ?? []) !== [];
$primaryRouteFormData = is_array($primaryRouteFormData ?? null) ? $primaryRouteFormData : [];
$primaryRouteFormErrors = is_array($primaryRouteFormErrors ?? null) ? $primaryRouteFormErrors : [];
$primaryRouteFormMode = trim((string) ($primaryRouteFormMode ?? '')) !== ''
    ? (string) $primaryRouteFormMode
    : (trim((string) ($primaryRouteFormData['route_id'] ?? '')) !== '' ? 'edit' : 'create');
$isPrimaryRouteEditMode = $primaryRouteFormMode === 'edit';
$primaryConfigReady = $distributionBeneficiaryId > 0 && $isPrimarSelected;
$canAddPrimaryRoute = ($locations ?? []) !== [] && ($zones ?? []) !== [];

// --- V2 UX: tab-uri, pasi si stari calculate (doar prezentare, fara schimbari de request-uri) ---
$isBeneficiaryEditMode = ($beneficiaryFormMode ?? 'create') === 'edit';
$beneficiaryFormErrorsSafe = is_array($beneficiaryFormErrors ?? null) ? $beneficiaryFormErrors : [];
$locFormErrorsSafe = is_array($locFormErrors ?? null) ? $locFormErrors : [];
$zoneFormErrorsSafe = is_array($zoneFormErrors ?? null) ? $zoneFormErrors : [];
$distributionOnlyRouteRulesCount = count($distributionOnlyRouteRules);
$primaryDistributionRouteRulesCount = count($primaryDistributionRouteRules);
$primaryRouteRulesCount = count($primaryRouteRules);
$catalogLocationsCount = count($locations ?? []);
$catalogZonesCount = count($zones ?? []);
$totalRouteRulesCount = ($isPrimarSelected ? $primaryRouteRulesCount : 0)
    + ($isDistributieSelected ? $distributionOnlyRouteRulesCount : 0)
    + ($isPrimaryDistributionSelected ? $primaryDistributionRouteRulesCount : 0);

$activeConfigTab = 'beneficiar';
if ($beneficiaryFormErrorsSafe !== []) {
    $activeConfigTab = 'beneficiar';
} elseif ($distributionOnlyRouteFormErrors !== [] || $isDistributionOnlyRouteEditMode) {
    $activeConfigTab = 'distributie';
} elseif ($primaryDistributionRouteFormErrors !== [] || $isPrimaryDistributionRouteEditMode) {
    $activeConfigTab = 'primar_distributie';
} elseif ($primaryRouteFormErrors !== [] || $isPrimaryRouteEditMode) {
    $activeConfigTab = 'primar';
} elseif ($locFormErrorsSafe !== [] || $zoneFormErrorsSafe !== [] || (int) ($locFormData['id'] ?? 0) > 0 || (int) ($zoneFormData['id'] ?? 0) > 0) {
    $activeConfigTab = 'catalog';
}

// V2.3: cate rute folosesc fiecare loc / zona (dupa nume, in toate cele 3 seturi de reguli)
$locUsageByName = [];
$zoneUsageByName = [];
foreach ([$distributionOnlyRouteRules, $primaryDistributionRouteRules, $primaryRouteRules] as $catalogUsageRuleSet) {
    foreach ($catalogUsageRuleSet as $catalogUsageRule) {
        $catalogUsageLocName = mb_strtolower(trim((string) ($catalogUsageRule['loc_nume'] ?? '')));
        $catalogUsageZoneName = mb_strtolower(trim((string) ($catalogUsageRule['zona_nume'] ?? '')));
        if ($catalogUsageLocName !== '') {
            $locUsageByName[$catalogUsageLocName] = ($locUsageByName[$catalogUsageLocName] ?? 0) + 1;
        }
        if ($catalogUsageZoneName !== '') {
            $zoneUsageByName[$catalogUsageZoneName] = ($zoneUsageByName[$catalogUsageZoneName] ?? 0) + 1;
        }
    }
}
$catalogEditingLocId = (int) ($locFormData['id'] ?? 0);
$catalogEditingZoneId = (int) ($zoneFormData['id'] ?? 0);

// V2.5: eticheta compacta "N vehicule selectate" pentru selectoarele de vehicule din formularele de rute
$formatVehicleCountLabel = static function (array $selectedVehicleIds, array $labelsById): string {
    $selectedCount = 0;
    foreach ($selectedVehicleIds as $selectedVehicleId) {
        $vehicleId = (int) $selectedVehicleId;
        if ($vehicleId > 0 && isset($labelsById[$vehicleId])) {
            $selectedCount++;
        }
    }
    if ($selectedCount === 0) {
        return '-- Selecteaza vehiculele --';
    }
    return $selectedCount === 1 ? '1 vehicul selectat' : $selectedCount . ' vehicule selectate';
};

// V2.8: lista distincta de garaje, pentru filtrul de vehicule din formularul de rute
$vehicleGarageOptions = [];
foreach (($vehicles ?? []) as $garageVehicle) {
    $garageName = trim((string) ($garageVehicle['garaj'] ?? ''));
    if ($garageName !== '') {
        $vehicleGarageOptions[mb_strtolower($garageName)] = $garageName;
    }
}
ksort($vehicleGarageOptions);

// V2.7: vehiculele grupate dupa capacitatea de transport, pentru dropdown-urile de selectie
$vehicleCapacityGroups = [];
foreach (($vehicles ?? []) as $capacityGroupVehicle) {
    $capacityGroupVehicleId = (int) ($capacityGroupVehicle['id'] ?? 0);
    if ($capacityGroupVehicleId <= 0) {
        continue;
    }
    $vehicleCapacityValue = (float) ($capacityGroupVehicle['capacitate_transport'] ?? 0);
    $vehicleCapacityKey = $vehicleCapacityValue > 0 ? number_format($vehicleCapacityValue, 2, '.', '') : 'fara';
    if (!isset($vehicleCapacityGroups[$vehicleCapacityKey])) {
        $vehicleCapacityGroups[$vehicleCapacityKey] = [
            'label' => $vehicleCapacityValue > 0
                ? rtrim(rtrim(number_format($vehicleCapacityValue, 2, '.', ''), '0'), '.') . ' tone'
                : 'Fara capacitate',
            'capacity' => $vehicleCapacityValue,
            'vehicles' => [],
        ];
    }
    $vehicleCapacityGroups[$vehicleCapacityKey]['vehicles'][] = $capacityGroupVehicle;
}
uasort($vehicleCapacityGroups, static fn(array $a, array $b): int => $b['capacity'] <=> $a['capacity']);
$configTabVisibility = [
    'beneficiar' => true,
    'catalog' => $isCatalogSelected,
    'primar' => $isPrimarSelected,
    'distributie' => $isDistributieSelected,
    'primar_distributie' => $isPrimaryDistributionSelected,
];
if (empty($configTabVisibility[$activeConfigTab])) {
    $activeConfigTab = 'beneficiar';
}

$configTabsNav = [
    'beneficiar' => ['step' => '1', 'label' => 'Beneficiar & tarife', 'requires' => '', 'count' => null, 'locked' => false],
    'catalog' => ['step' => '2', 'label' => 'Catalog Loc / Zona', 'requires' => 'catalog', 'count' => $catalogLocationsCount + $catalogZonesCount, 'locked' => !$catalogConfigReady],
    'primar' => ['step' => '3', 'label' => 'Rute Primar', 'requires' => 'primar', 'count' => $primaryRouteRulesCount, 'locked' => !$primaryConfigReady || !$canAddPrimaryRoute],
    'distributie' => ['step' => '3', 'label' => 'Rute Distributie', 'requires' => 'distributie', 'count' => $distributionOnlyRouteRulesCount, 'locked' => !$catalogConfigReady || !$canAddDistributionRoute],
    'primar_distributie' => ['step' => '3', 'label' => 'Rute Primar+Distributie', 'requires' => 'primar_distributie', 'count' => $primaryDistributionRouteRulesCount, 'locked' => !$catalogConfigReady || !$canAddDistributionRoute],
];

$configStep1State = $distributionBeneficiaryId > 0 ? 'done' : 'current';
if (!$isCatalogSelected) {
    $configStep2State = 'na';
} elseif (!$catalogConfigReady) {
    $configStep2State = 'locked';
} elseif ($canAddDistributionRoute) {
    $configStep2State = 'done';
} else {
    $configStep2State = 'current';
}
if (!$isCatalogSelected) {
    $configStep3State = 'na';
} elseif (!$catalogConfigReady || !$canAddDistributionRoute) {
    $configStep3State = 'locked';
} elseif ($totalRouteRulesCount > 0) {
    $configStep3State = 'done';
} else {
    $configStep3State = 'current';
}
$configStep2Hint = [
    'na' => 'Doar pentru Primar / Distributie',
    'locked' => 'Se deblocheaza dupa pasul 1',
    'current' => 'Adauga locuri si zone',
    'done' => $catalogLocationsCount . ' locuri · ' . $catalogZonesCount . ' zone',
][$configStep2State];
$configStep3Hint = [
    'na' => 'Doar pentru tipurile cu rute',
    'locked' => 'Se deblocheaza dupa pasul 2',
    'current' => 'Adauga rute pe tipurile bifate',
    'done' => $totalRouteRulesCount . ' rute configurate',
][$configStep3State];

// V2.1: mod creare (fara beneficiar salvat) + stari "formular deschis" pentru panourile de rute
$configCreateMode = $distributionBeneficiaryId <= 0;
$distributionRouteFormOpen = $isDistributionOnlyRouteEditMode || $distributionOnlyRouteFormErrors !== [] || $distributionOnlyRouteRulesCount === 0;
$primaryDistributionRouteFormOpen = $isPrimaryDistributionRouteEditMode || $primaryDistributionRouteFormErrors !== [] || $primaryDistributionRouteRulesCount === 0;
$primaryRouteFormOpen = $isPrimaryRouteEditMode || $primaryRouteFormErrors !== [] || $primaryRouteRulesCount === 0;
if ($configCreateMode) {
    foreach ($configTabVisibility as $configTabVisibilityKey => $configTabVisibilityValue) {
        if ($configTabVisibilityKey !== 'beneficiar') {
            $configTabVisibility[$configTabVisibilityKey] = false;
        }
    }
    $activeConfigTab = 'beneficiar';
}
?>

<div class="transport-config-page tcv2-page">
    <div class="tcv2-header">
        <div>
            <h2 class="h4 mb-1">Configurare transport</h2>
            <p class="tcv2-subtitle mb-0">Reguli de tarifare pe beneficiar: tarife de baza, catalog de locatii/zone si rute pe fiecare tip de transport.</p>
        </div>
        <div>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Inapoi la curse</a>
        </div>
    </div>

    <div class="tcv2-layout">
    <aside class="tcv2-sidebar">
        <div class="tcv2-side-head">
            <span class="tcv2-kicker">Beneficiari (<?= e((string) count($beneficiaries ?? [])) ?>)</span>
            <a class="btn btn-sm btn-primary" data-new-beneficiary href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config'])) ?>">+ Nou</a>
        </div>
        <input type="search" class="form-control form-control-sm tcv2-side-search" placeholder="Cauta beneficiar..." aria-label="Cauta beneficiar">
        <div class="tcv2-side-bulk">
            <label class="tcv2-side-selectall">
                <input type="checkbox" class="form-check-input" id="bulk-beneficiary-select-all" aria-label="Selecteaza toate regulile">
                <span>Selecteaza tot</span>
            </label>
            <button
                type="submit"
                class="btn btn-sm btn-outline-danger"
                id="bulk-beneficiary-delete-btn"
                form="bulk-beneficiary-delete-form"
                disabled
                data-confirm="Sigur doresti sa stergi beneficiarii selectati?"
            >
                Sterge selectate
            </button>
        </div>
        <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_delete_beneficiari'])) ?>" id="bulk-beneficiary-delete-form" class="d-none">
            <?= csrf_field() ?>
        </form>
        <div class="tcv2-side-list">
            <?php if (($beneficiaries ?? []) === []): ?>
                <p class="text-muted small px-2 py-1 mb-0">Nu exista reguli salvate.</p>
            <?php else: ?>
                <?php foreach (($beneficiaries ?? []) as $sideBeneficiary): ?>
                    <?php
                    $sideBeneficiaryId = (int) ($sideBeneficiary['id'] ?? 0);
                    $sideBeneficiaryName = (string) ($sideBeneficiary['nume'] ?? '-');
                    $sideIsActiveRow = $isBeneficiaryEditMode && $sideBeneficiaryId === $distributionBeneficiaryId;
                    $sideBadges = [];
                    if (!empty($sideBeneficiary['suporta_primar'])) { $sideBadges[] = ['P', 'Primar km']; }
                    if (!empty($sideBeneficiary['suporta_distributie'])) { $sideBadges[] = ['D', 'Distributie']; }
                    if (!empty($sideBeneficiary['suporta_primar_distributie'])) { $sideBadges[] = ['P+D', 'Primar+Distributie']; }
                    if (!empty($sideBeneficiary['suporta_compresor'])) { $sideBadges[] = ['C', 'Compresor']; }
                    ?>
                    <div class="tcv2-side-item<?= $sideIsActiveRow ? ' is-active' : '' ?>" data-side-item data-search="<?= e(mb_strtolower($sideBeneficiaryName)) ?>">
                        <input
                            type="checkbox"
                            class="form-check-input bulk-beneficiary-checkbox"
                            name="ids[]"
                            value="<?= e((string) $sideBeneficiaryId) ?>"
                            form="bulk-beneficiary-delete-form"
                            aria-label="Selecteaza beneficiarul <?= e($sideBeneficiaryName) ?>"
                        >
                        <a class="tcv2-side-link" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $sideBeneficiaryId])) ?>" title="Editeaza <?= e($sideBeneficiaryName) ?>">
                            <span class="tcv2-side-name">
                                <span class="tcv2-side-status <?= !empty($sideBeneficiary['activ']) ? 'is-on' : 'is-off' ?>" title="<?= !empty($sideBeneficiary['activ']) ? 'Activ' : 'Inactiv' ?>"></span>
                                <strong><?= e($sideBeneficiaryName) ?></strong>
                            </span>
                            <span class="tcv2-side-badges">
                                <?php foreach ($sideBadges as $sideBadge): ?>
                                    <span class="tcv2-side-badge" title="<?= e($sideBadge[1]) ?>"><?= e($sideBadge[0]) ?></span>
                                <?php endforeach; ?>
                            </span>
                        </a>
                        <div class="tcv2-side-actions">
                            <a class="tcv2-side-icon" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_view_id' => $sideBeneficiaryId])) ?>" title="Detalii"><i class="bi bi-info-circle" aria-hidden="true"></i></a>
                            <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_delete_beneficiar'])) ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e((string) $sideBeneficiaryId) ?>">
                                <button type="submit" class="tcv2-side-icon tcv2-side-icon-danger" data-confirm="Sigur doresti sa stergi acest beneficiar?" title="Sterge"><i class="bi bi-trash" aria-hidden="true"></i></button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <div class="tcv2-main">
    <div class="tcv2-context">
        <div class="tcv2-context-who">
            <?php if ($isBeneficiaryEditMode): ?>
                <span class="tcv2-kicker">Configurezi beneficiarul</span>
                <div class="tcv2-context-name-row">
                    <strong class="tcv2-context-name"><?= e($distributionBeneficiaryName !== '' ? $distributionBeneficiaryName : ('Beneficiar #' . $distributionBeneficiaryId)) ?></strong>
                    <?php foreach ($selectedTransportLabels as $selectedTypeLabel): ?>
                        <span class="badge tcv2-type-badge"><?= e((string) $selectedTypeLabel) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <span class="tcv2-kicker">Beneficiar nou</span>
                <div class="tcv2-context-name-row">
                    <strong class="tcv2-context-name">Incepe cu pasul 1</strong>
                </div>
                <span class="tcv2-context-hint">Dupa salvarea beneficiarului se deblocheaza catalogul si rutele.</span>
            <?php endif; ?>
        </div>
        <ol class="tcv2-steps">
            <li class="tcv2-step is-<?= e($configStep1State) ?>">
                <span class="tcv2-step-index">1</span>
                <div><strong>Beneficiar & tarife</strong><small><?= $configStep1State === 'done' ? 'Salvat' : 'Completeaza si salveaza' ?></small></div>
            </li>
            <li class="tcv2-step is-<?= e($configStep2State) ?>">
                <span class="tcv2-step-index">2</span>
                <div><strong>Catalog Loc / Zona</strong><small><?= e($configStep2Hint) ?></small></div>
            </li>
            <li class="tcv2-step is-<?= e($configStep3State) ?>">
                <span class="tcv2-step-index">3</span>
                <div><strong>Rute pe tip transport</strong><small><?= e($configStep3Hint) ?></small></div>
            </li>
        </ol>
    </div>

    <nav class="tcv2-tabs" role="tablist" aria-label="Sectiuni configurare">
        <?php foreach ($configTabsNav as $configTabKey => $configTabDef): ?>
            <button
                type="button"
                class="tcv2-tab<?= $activeConfigTab === $configTabKey ? ' is-active' : '' ?>"
                data-config-tab="<?= e((string) $configTabKey) ?>"
                data-tab-requires="<?= e((string) $configTabDef['requires']) ?>"
                data-tab-create-locked="<?= $configCreateMode && $configTabKey !== 'beneficiar' ? '1' : '0' ?>"
                role="tab"
                aria-selected="<?= $activeConfigTab === $configTabKey ? 'true' : 'false' ?>"
                <?= !empty($configTabVisibility[$configTabKey]) ? '' : 'hidden' ?>
            >
                <span class="tcv2-tab-step"><?= e((string) $configTabDef['step']) ?></span>
                <span class="tcv2-tab-label"><?= e((string) $configTabDef['label']) ?></span>
                <?php if (!empty($configTabDef['locked'])): ?>
                    <i class="bi bi-lock-fill tcv2-tab-lock" aria-hidden="true" title="Se deblocheaza dupa salvarea pasilor anteriori"></i>
                <?php elseif ($configTabDef['count'] !== null): ?>
                    <span class="tcv2-tab-count"><?= e((string) $configTabDef['count']) ?></span>
                <?php endif; ?>
            </button>
        <?php endforeach; ?>
    </nav>

    <div class="tcv2-workspace">
        <section class="tcv2-panel" data-config-tab-panel="beneficiar" <?= $activeConfigTab === 'beneficiar' ? '' : 'hidden' ?>>
            <div class="tcv2-panel-head">
                <div>
                    <h4 class="h6 mb-1">Date beneficiar si tipuri transport</h4>
                    <p class="tcv2-panel-hint mb-0">Alege tipurile de transport pentru care exista reguli active. Tarifele de baza pentru Primar si Compresor se completeaza aici; preturile pentru Distributie si Primar+Distributie se definesc pe fiecare ruta, la pasul 3.</p>
                </div>
                <?php if (($beneficiaryFormMode ?? 'create') === 'edit'): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config'])) ?>">Reseteaza formular</a>
                <?php endif; ?>
            </div>

            <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_beneficiar'])) ?>" class="row g-3 transport-beneficiary-form" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) ($beneficiaryFormData['id'] ?? '')) ?>">
                <input type="hidden" name="pret_tarifare" value="<?= e((string) ($beneficiaryFormData['pret_tarifare'] ?? '0')) ?>">
                <input type="hidden" name="pret_distributie_tona" value="<?= e((string) ($beneficiaryFormData['pret_distributie_tona'] ?? '0')) ?>">
                <input type="hidden" name="pret_distributie_km" value="<?= e((string) ($beneficiaryFormData['pret_distributie_km'] ?? '0')) ?>">

                <?php if (isset($beneficiaryFormErrors['id'])): ?>
                    <div class="col-12">
                        <div class="alert alert-danger py-2 mb-0"><?= e((string) $beneficiaryFormErrors['id']) ?></div>
                    </div>
                <?php endif; ?>

                <div class="col-12">
                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <label class="form-label" for="config_beneficiar_nume">Beneficiar <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($beneficiaryFormErrors['nume']) ? 'is-invalid' : '' ?>" id="config_beneficiar_nume" name="nume" maxlength="150" value="<?= e((string) ($beneficiaryFormData['nume'] ?? '')) ?>" required>
                            <?php if (isset($beneficiaryFormErrors['nume'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['nume']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-12 col-md-4 transport-config-status-row">
                            <label class="form-label" for="config_beneficiar_activ">Status</label>
                            <div class="form-check form-switch transport-config-switch">
                                <input class="form-check-input" type="checkbox" role="switch" value="1" id="config_beneficiar_activ" name="activ" <?= (string) ($beneficiaryFormData['activ'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="config_beneficiar_activ">Activ</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label mb-1">Tipuri transport <span class="text-danger">*</span></label>
                    <div class="form-text mt-0 mb-2">Bifeaza tipurile pentru care beneficiarul are reguli active — fiecare tip isi arata setarile direct in cardul lui.</div>
                    <?php if (isset($beneficiaryFormErrors['tip_transport'])): ?><div class="invalid-feedback d-block mb-2"><?= e((string) $beneficiaryFormErrors['tip_transport']) ?></div><?php endif; ?>
                    <div class="tcv2-type-grid" data-role="transport-type-dropdown">
                        <div class="tcv2-type-tile">
                            <label class="tcv2-type-tile-head">
                                <input class="form-check-input" type="checkbox" name="tip_transporturi[]" value="primar" <?= $isPrimarSelected ? 'checked' : '' ?>>
                                <span class="tcv2-type-tile-title">
                                    <strong>Primar km</strong>
                                    <small>Tarif de baza Pret/km + Pret/tona, aplicat pe rutele din tabul „Rute Primar".</small>
                                </span>
                            </label>
                            <div class="tcv2-type-tile-body" data-transport-card="primar" <?= $isPrimarSelected ? '' : 'hidden' ?>>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="config_primar_pret_km">Pret/km</label>
                                    <input type="number" class="form-control <?= isset($beneficiaryFormErrors['pret_km']) ? 'is-invalid' : '' ?>" id="config_primar_pret_km" name="pret_km" min="0" step="0.01" value="<?= e((string) ($beneficiaryFormData['pret_km'] ?? '')) ?>">
                                    <?php if (isset($beneficiaryFormErrors['pret_km'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['pret_km']) ?></div><?php endif; ?>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="config_primar_pret_tona">Pret/tona</label>
                                    <input type="number" class="form-control <?= isset($beneficiaryFormErrors['pret_tona']) ? 'is-invalid' : '' ?>" id="config_primar_pret_tona" name="pret_tona" min="0" step="0.01" value="<?= e((string) ($beneficiaryFormData['pret_tona'] ?? '')) ?>">
                                    <?php if (isset($beneficiaryFormErrors['pret_tona'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['pret_tona']) ?></div><?php endif; ?>
                                </div>
                            </div>
                            </div>
                        </div>

                        <div class="tcv2-type-tile">
                            <label class="tcv2-type-tile-head">
                                <input class="form-check-input" type="checkbox" name="tip_transporturi[]" value="distributie" <?= $isDistributieSelected ? 'checked' : '' ?>>
                                <span class="tcv2-type-tile-title">
                                    <strong>Distributie</strong>
                                    <small>Fara tarif de baza aici — pretul (tona / km) se stabileste pe fiecare ruta, in tabul „Rute Distributie".</small>
                                </span>
                            </label>
                            <div class="tcv2-type-tile-body" data-transport-card="distributie" <?= $isDistributieSelected ? '' : 'hidden' ?>>
                                <p class="tcv2-type-tile-note mb-0"><i class="bi bi-signpost-2" aria-hidden="true"></i> <?= e((string) $distributionOnlyRouteRulesCount) ?> configuratii de ruta existente<?= $catalogConfigReady ? '' : ' — se deblocheaza dupa salvare' ?>.</p>
                                <?php if ($catalogConfigReady): ?>
                                    <button type="button" class="tcv2-tile-link" data-open-tab="distributie">Deschide „Rute Distributie" <i class="bi bi-arrow-right-short" aria-hidden="true"></i></button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tcv2-type-tile">
                            <label class="tcv2-type-tile-head">
                                <input class="form-check-input" type="checkbox" name="tip_transporturi[]" value="primar_distributie" <?= $isPrimaryDistributionSelected ? 'checked' : '' ?>>
                                <span class="tcv2-type-tile-title">
                                    <strong>Primar+Distributie</strong>
                                    <small>Pret tona + Pret km + Km agreati, definite pe fiecare ruta, in tabul „Rute Primar+Distributie".</small>
                                </span>
                            </label>
                            <div class="tcv2-type-tile-body" data-transport-card="primar_distributie" <?= $isPrimaryDistributionSelected ? '' : 'hidden' ?>>
                                <p class="tcv2-type-tile-note mb-0"><i class="bi bi-signpost-split" aria-hidden="true"></i> <?= e((string) $primaryDistributionRouteRulesCount) ?> configuratii de ruta existente<?= $catalogConfigReady ? '' : ' — se deblocheaza dupa salvare' ?>.</p>
                                <?php if ($catalogConfigReady): ?>
                                    <button type="button" class="tcv2-tile-link" data-open-tab="primar_distributie">Deschide „Rute Primar+Distributie" <i class="bi bi-arrow-right-short" aria-hidden="true"></i></button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="tcv2-type-tile">
                            <label class="tcv2-type-tile-head">
                                <input class="form-check-input" type="checkbox" name="tip_transporturi[]" value="compresor" <?= $isCompresorSelected ? 'checked' : '' ?>>
                                <span class="tcv2-type-tile-title">
                                    <strong>Compresor</strong>
                                    <small>Tarife dedicate operatiunilor de compresor si vehiculele alocate.</small>
                                </span>
                            </label>
                            <div class="tcv2-type-tile-body" data-transport-card="compresor" <?= $isCompresorSelected ? '' : 'hidden' ?>>
                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="config_compresor_pret_ora_aspirare">Pret ora aspirare</label>
                                    <input type="number" class="form-control <?= isset($beneficiaryFormErrors['pret_ora_aspirare']) ? 'is-invalid' : '' ?>" id="config_compresor_pret_ora_aspirare" name="pret_ora_aspirare" min="0" step="0.01" value="<?= e((string) ($beneficiaryFormData['pret_ora_aspirare'] ?? '')) ?>">
                                    <?php if (isset($beneficiaryFormErrors['pret_ora_aspirare'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['pret_ora_aspirare']) ?></div><?php endif; ?>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="config_compresor_pret_km_dislocare">Pret km dislocare</label>
                                    <input type="number" class="form-control <?= isset($beneficiaryFormErrors['pret_km_dislocare']) ? 'is-invalid' : '' ?>" id="config_compresor_pret_km_dislocare" name="pret_km_dislocare" min="0" step="0.01" value="<?= e((string) ($beneficiaryFormData['pret_km_dislocare'] ?? '')) ?>">
                                    <?php if (isset($beneficiaryFormErrors['pret_km_dislocare'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['pret_km_dislocare']) ?></div><?php endif; ?>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="config_compresor_pret_tona_livrata">Pret tona livrata</label>
                                    <input type="number" class="form-control <?= isset($beneficiaryFormErrors['pret_tona_livrata']) ? 'is-invalid' : '' ?>" id="config_compresor_pret_tona_livrata" name="pret_tona_livrata" min="0" step="0.01" value="<?= e((string) ($beneficiaryFormData['pret_tona_livrata'] ?? '')) ?>">
                                    <?php if (isset($beneficiaryFormErrors['pret_tona_livrata'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['pret_tona_livrata']) ?></div><?php endif; ?>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="config_compresor_pret_tona_aspirata_lichida">Pret tona aspirata lichida</label>
                                    <input type="number" class="form-control <?= isset($beneficiaryFormErrors['pret_tona_aspirata_lichida']) ? 'is-invalid' : '' ?>" id="config_compresor_pret_tona_aspirata_lichida" name="pret_tona_aspirata_lichida" min="0" step="0.01" value="<?= e((string) ($beneficiaryFormData['pret_tona_aspirata_lichida'] ?? '')) ?>">
                                    <?php if (isset($beneficiaryFormErrors['pret_tona_aspirata_lichida'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['pret_tona_aspirata_lichida']) ?></div><?php endif; ?>
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="config_compresor_pret_tona_aspirata_gazoasa">Pret tona aspirata gazoasa</label>
                                    <input type="number" class="form-control <?= isset($beneficiaryFormErrors['pret_tona_aspirata_gazoasa']) ? 'is-invalid' : '' ?>" id="config_compresor_pret_tona_aspirata_gazoasa" name="pret_tona_aspirata_gazoasa" min="0" step="0.01" value="<?= e((string) ($beneficiaryFormData['pret_tona_aspirata_gazoasa'] ?? '')) ?>">
                                    <?php if (isset($beneficiaryFormErrors['pret_tona_aspirata_gazoasa'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['pret_tona_aspirata_gazoasa']) ?></div><?php endif; ?>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="config_compresor_vehicle_ids_toggle">Vehicule Compresor</label>
                                    <div class="dropdown vehicle-multiselect-dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle <?= isset($beneficiaryFormErrors['compresor_vehicle_ids']) ? 'is-invalid' : '' ?>" type="button" id="config_compresor_vehicle_ids_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                            <span
                                                class="vehicle-multiselect-label"
                                                data-default-label="-- Selecteaza vehiculele --"
                                                data-summary-mode="count"
                                                data-summary-singular="vehicul selectat"
                                                data-summary-plural="vehicule selectate"
                                            ><?= e($compresorVehicleButtonLabel) ?></span>
                                        </button>
                                        <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="config_compresor_vehicle_ids_toggle">
                                            <div class="tcv2-vehicle-menu-search"><input type="search" class="form-control form-control-sm" data-vehicle-menu-search placeholder="Cauta vehicul..." aria-label="Cauta vehicul"></div>
                                            <div class="tcv2-vehicle-menu-empty text-muted small px-2 py-1" hidden>Niciun vehicul gasit.</div>
                                            <?php foreach ($vehicleCapacityGroups as $capacityGroup): ?>
                                                <?php
                                                $capacityGroupHasSelection = false;
                                                foreach ($capacityGroup['vehicles'] as $capacityGroupOption) {
                                                    if (in_array((string) (int) ($capacityGroupOption['id'] ?? 0), $compresorSelectedVehicleIds, true)) {
                                                        $capacityGroupHasSelection = true;
                                                        break;
                                                    }
                                                }
                                                ?>
                                                <div class="tcv2-vehicle-group<?= $capacityGroupHasSelection ? '' : ' is-collapsed' ?>" data-vehicle-group data-group-label="<?= e(mb_strtolower((string) $capacityGroup['label'])) ?>">
                                                    <div class="tcv2-vehicle-group-head" data-vehicle-group-head>
                                                        <input class="form-check-input m-0" type="checkbox" data-vehicle-group-toggle aria-label="Selecteaza toate vehiculele: <?= e((string) $capacityGroup['label']) ?>">
                                                        <span><?= e((string) $capacityGroup['label']) ?></span>
                                                        <span class="tcv2-vehicle-group-count"><?= e((string) count($capacityGroup['vehicles'])) ?></span>
                                                        <i class="bi bi-chevron-down tcv2-vehicle-group-chevron" aria-hidden="true"></i>
                                                    </div>
                                                    <?php foreach ($capacityGroup['vehicles'] as $vehicle): ?>
                                                        <?php
                                                        $vehicleId = (int) ($vehicle['id'] ?? 0);
                                                        $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? ''));
                                                        $vehiclePlate = trim((string) ($vehicle['nr_inmatriculare'] ?? ''));
                                                        ?>
                                                        <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option" data-vehicle-garage="<?= e(mb_strtolower(trim((string) ($vehicle['garaj'] ?? '')))) ?>">
                                                            <input class="form-check-input m-0" type="checkbox" name="compresor_vehicle_ids[]" value="<?= e((string) $vehicleId) ?>" data-vehicle-plate="<?= e($vehiclePlate) ?>" <?= in_array((string) $vehicleId, $compresorSelectedVehicleIds, true) ? 'checked' : '' ?>>
                                                            <span><?= e($vehicleLabel) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="form-text">Vehiculele selectate vor fi disponibile la adaugare cursa pentru tipul Compresor.</div>
                                    <?php if (isset($beneficiaryFormErrors['compresor_vehicle_ids'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['compresor_vehicle_ids']) ?></div><?php endif; ?>
                                </div>
                            </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2 justify-content-end transport-config-form-actions">
                    <button type="submit" class="btn btn-primary"><?= ($beneficiaryFormMode ?? 'create') === 'edit' ? 'Actualizeaza regula beneficiar' : 'Salveaza si continua configurarea' ?></button>
                </div>
            </form>
        </section>

        <section class="tcv2-panel" data-config-tab-panel="catalog" <?= $activeConfigTab === 'catalog' ? '' : 'hidden' ?>>
            <div class="tcv2-panel-head">
                <div>
                    <h4 class="h6 mb-1">Catalog locatii si zone — <?= $distributionBeneficiaryName !== '' ? e($distributionBeneficiaryName) : 'beneficiarul curent' ?></h4>
                    <p class="tcv2-panel-hint mb-0">Locurile de incarcare si zonele de descarcare definite aici sunt refolosite de toate rutele: Primar, Distributie si Primar+Distributie.</p>
                </div>
            </div>

            <?php if (!$catalogConfigReady): ?>
                <div class="tcv2-locked">
                    <i class="bi bi-lock-fill tcv2-locked-icon" aria-hidden="true"></i>
                    <div>
                        <strong>Catalogul este blocat.</strong>
                        <p class="mb-0">Salveaza mai intai regula beneficiarului (pasul 1) cu tipul Primar sau Distributie bifat.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="transport-distribution-panel transport-distribution-catalog-panel mt-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h5 class="h6 mb-0">Catalog locatii si zone</h5>
                        <span class="text-muted small">Completeaza doar locul de incarcare si zona de descarcare.</span>
                    </div>
                    <?php if ($catalogEditingLocId > 0 || $catalogEditingZoneId > 0): ?>
                        <div class="alert alert-info py-2 mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span>
                                Redenumesti
                                <?php if ($catalogEditingLocId > 0): ?>locul „<strong><?= e((string) ($locFormData['nume'] ?? '')) ?></strong>"<?php endif; ?>
                                <?php if ($catalogEditingLocId > 0 && $catalogEditingZoneId > 0): ?> si <?php endif; ?>
                                <?php if ($catalogEditingZoneId > 0): ?>zona „<strong><?= e((string) ($zoneFormData['nume'] ?? '')) ?></strong>"<?php endif; ?>
                                — salvarea actualizeaza intrarea existenta peste tot unde e folosita.
                            </span>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId])) ?>">Renunta</a>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_catalog'])) ?>" novalidate class="transport-distribution-inline-form transport-config-inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="beneficiar_id" value="<?= e((string) $distributionBeneficiaryId) ?>">
                    <input type="hidden" name="loc_id" value="<?= e((string) ($locFormData['id'] ?? '')) ?>">
                    <input type="hidden" name="zona_id" value="<?= e((string) ($zoneFormData['id'] ?? '')) ?>">
                    <div class="row g-3 align-items-start transport-catalog-single-grid">
                        <?php if (isset($locFormErrors['id'])): ?><div class="col-12"><div class="alert alert-danger py-2 mb-0"><?= e((string) $locFormErrors['id']) ?></div></div><?php endif; ?>
                        <?php if (isset($zoneFormErrors['id'])): ?><div class="col-12"><div class="alert alert-danger py-2 mb-0"><?= e((string) $zoneFormErrors['id']) ?></div></div><?php endif; ?>

                        <div class="col-12 col-xl-6">
                            <label class="form-label" for="config_loc_nume_panel">Loc incarcare <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($locFormErrors['nume']) ? 'is-invalid' : '' ?>" id="config_loc_nume_panel" name="loc_nume" maxlength="120" value="<?= e((string) ($locFormData['nume'] ?? '')) ?>">
                            <?php if (isset($locFormErrors['nume'])): ?><div class="invalid-feedback d-block"><?= e((string) $locFormErrors['nume']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-12 col-xl-6">
                            <label class="form-label" for="config_zona_nume_panel">Zona descarcare <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($zoneFormErrors['nume']) ? 'is-invalid' : '' ?>" id="config_zona_nume_panel" name="zona_nume" maxlength="120" value="<?= e((string) ($zoneFormData['nume'] ?? '')) ?>">
                            <?php if (isset($zoneFormErrors['nume'])): ?><div class="invalid-feedback d-block"><?= e((string) $zoneFormErrors['nume']) ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-3 transport-config-inline-actions">
                        <button type="submit" class="btn btn-primary">Salveaza catalog</button>
                    </div>
                    </form>
                </div>

                <?php if ($catalogLocationsCount > 0 || $catalogZonesCount > 0): ?>
                    <div class="tcv2-catalog-manager">
                        <input type="search" class="form-control form-control-sm tcv2-catalog-search" placeholder="Filtreaza locurile si zonele..." aria-label="Filtreaza catalogul">
                        <div class="tcv2-catalog-cols">
                            <div class="tcv2-catalog-col">
                                <span class="tcv2-kicker">Locuri incarcare (<?= e((string) $catalogLocationsCount) ?>)</span>
                                <ul class="tcv2-catalog-list">
                                    <?php foreach (($locations ?? []) as $catalogLocation): ?>
                                        <?php
                                        $catalogLocationId = (int) ($catalogLocation['id'] ?? 0);
                                        $catalogLocationName = (string) ($catalogLocation['nume'] ?? '-');
                                        $catalogLocationUsage = $locUsageByName[mb_strtolower(trim($catalogLocationName))] ?? 0;
                                        ?>
                                        <li class="tcv2-catalog-item<?= $catalogEditingLocId === $catalogLocationId ? ' is-editing' : '' ?>" data-catalog-item data-search="<?= e(mb_strtolower($catalogLocationName)) ?>">
                                            <a class="tcv2-catalog-name" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId, 'loc_edit_id' => $catalogLocationId])) ?>" title="Redenumeste locul">
                                                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                                <span><?= e($catalogLocationName) ?></span>
                                            </a>
                                            <span class="tcv2-catalog-meta">
                                                <?php if (empty($catalogLocation['activ'])): ?><span class="tcv2-catalog-inactive" title="Inactiv">inactiv</span><?php endif; ?>
                                                <span class="tcv2-catalog-count<?= $catalogLocationUsage === 0 ? ' is-zero' : '' ?>" title="Rute care folosesc acest loc"><?= e((string) $catalogLocationUsage) ?> rute</span>
                                                <i class="bi bi-pencil tcv2-catalog-editicon" aria-hidden="true"></i>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <p class="tcv2-catalog-empty text-muted small mb-0" hidden>Niciun loc nu se potriveste filtrului.</p>
                            </div>
                            <div class="tcv2-catalog-col">
                                <span class="tcv2-kicker">Zone descarcare (<?= e((string) $catalogZonesCount) ?>)</span>
                                <ul class="tcv2-catalog-list">
                                    <?php foreach (($zones ?? []) as $catalogZone): ?>
                                        <?php
                                        $catalogZoneId = (int) ($catalogZone['id'] ?? 0);
                                        $catalogZoneName = (string) ($catalogZone['nume'] ?? '-');
                                        $catalogZoneUsage = $zoneUsageByName[mb_strtolower(trim($catalogZoneName))] ?? 0;
                                        ?>
                                        <li class="tcv2-catalog-item<?= $catalogEditingZoneId === $catalogZoneId ? ' is-editing' : '' ?>" data-catalog-item data-search="<?= e(mb_strtolower($catalogZoneName)) ?>">
                                            <a class="tcv2-catalog-name" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId, 'zona_edit_id' => $catalogZoneId])) ?>" title="Redenumeste zona">
                                                <i class="bi bi-pin-map" aria-hidden="true"></i>
                                                <span><?= e($catalogZoneName) ?></span>
                                            </a>
                                            <span class="tcv2-catalog-meta">
                                                <?php if (empty($catalogZone['activ'])): ?><span class="tcv2-catalog-inactive" title="Inactiv">inactiv</span><?php endif; ?>
                                                <span class="tcv2-catalog-count<?= $catalogZoneUsage === 0 ? ' is-zero' : '' ?>" title="Rute care folosesc aceasta zona"><?= e((string) $catalogZoneUsage) ?> rute</span>
                                                <i class="bi bi-pencil tcv2-catalog-editicon" aria-hidden="true"></i>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <p class="tcv2-catalog-empty text-muted small mb-0" hidden>Nicio zona nu se potriveste filtrului.</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="tcv2-panel" data-config-tab-panel="distributie" <?= $activeConfigTab === 'distributie' ? '' : 'hidden' ?>>
            <div class="tcv2-panel-head">
                <div>
                    <h4 class="h6 mb-1">Rute Distributie — <?= $distributionBeneficiaryName !== '' ? e($distributionBeneficiaryName) : 'beneficiarul curent' ?></h4>
                    <p class="tcv2-panel-hint mb-0">Folosite strict pentru cursele cu Tip Transport: Distributie. Alegi modul de tarifare (tona, km sau ambele) pe fiecare pereche Loc ↔ Zona.</p>
                </div>
                <?php if ($isDistributieSelected && $catalogConfigReady): ?>
                    <div class="tcv2-panel-actions">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-modal-open="tcv2_catalog_modal">+ Loc / Zona</button>
                        <button type="button" class="btn btn-sm btn-primary" data-toggle-form="tcv2_form_distributie" aria-expanded="<?= $distributionRouteFormOpen ? 'true' : 'false' ?>"><?= $isDistributionOnlyRouteEditMode ? 'Editare configuratie' : '+ Adauga configuratie' ?></button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$isDistributieSelected || !$catalogConfigReady): ?>
                <div class="tcv2-locked">
                    <i class="bi bi-lock-fill tcv2-locked-icon" aria-hidden="true"></i>
                    <div>
                        <strong>Rutele Distributie sunt blocate.</strong>
                        <p class="mb-0"><?= !$isDistributieSelected ? 'Bifeaza tipul Distributie la pasul 1 si salveaza regula beneficiarului, apoi configurezi rutele aici.' : 'Salveaza mai intai regula beneficiarului cu tipul Distributie bifat, apoi poti configura rutele Distributie.' ?></p>
                    </div>
                </div>
            <?php else: ?>
                    <?php if (!$canAddDistributionRoute): ?>
                        <div class="alert alert-warning mt-3 mb-0">Adauga cel putin un Loc incarcare si o Zona descarcare (foloseste butonul „+ Loc / Zona" de mai sus), apoi poti crea configuratii de ruta.</div>
                    <?php endif; ?>

                    <div class="tcv2-form-disclosure" id="tcv2_form_distributie" <?= $distributionRouteFormOpen ? '' : 'hidden' ?>>
                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_distributie'])) ?>" novalidate class="mt-3 transport-route-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="beneficiar_id" value="<?= e((string) $distributionBeneficiaryId) ?>">
                        <input type="hidden" name="route_scope" value="distributie">
                        <input type="hidden" name="route_id" value="<?= e((string) ($distributionOnlyRouteFormData['route_id'] ?? '')) ?>">

                        <div class="transport-distribution-panel transport-distribution-route-panel">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <h5 class="h6 mb-0">Configuratii rute Distributie</h5>
                                <span class="text-muted small">Acest panel este folosit strict pentru cursele cu Tip Transport: Distributie.</span>
                            </div>
                            <?php if ($isDistributionOnlyRouteEditMode): ?>
                                <div class="alert alert-info py-2 mb-3">Editezi o configuratie Distributie existenta.</div>
                            <?php endif; ?>
                            <div class="row g-3 align-items-end transport-distribution-route-grid">
                                <div class="col-12 tcv2-group-sep">Ruta</div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="config_distribution_only_route_loc_id">Loc incarcare <span class="text-danger">*</span></label>
                                    <select class="form-select <?= isset($distributionOnlyRouteFormErrors['loc_id']) ? 'is-invalid' : '' ?>" id="config_distribution_only_route_loc_id" name="route_loc_id" required>
                                        <option value="">Selecteaza locatia de incarcare</option>
                                        <?php foreach (($locations ?? []) as $location): ?>
                                            <?php $locationId = (int) ($location['id'] ?? 0); ?>
                                            <option value="<?= e((string) $locationId) ?>" <?= (string) ($distributionOnlyRouteFormData['loc_id'] ?? '') === (string) $locationId ? 'selected' : '' ?>>
                                                <?= e((string) ($location['nume'] ?? '-')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($distributionOnlyRouteFormErrors['loc_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionOnlyRouteFormErrors['loc_id']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="config_distribution_only_route_zona_id">Zona descarcare <span class="text-danger">*</span></label>
                                    <select class="form-select <?= isset($distributionOnlyRouteFormErrors['zona_id']) ? 'is-invalid' : '' ?>" id="config_distribution_only_route_zona_id" name="route_zona_id" required>
                                        <option value="">Selecteaza zona de descarcare</option>
                                        <?php foreach (($zones ?? []) as $zone): ?>
                                            <?php $zoneId = (int) ($zone['id'] ?? 0); ?>
                                            <option value="<?= e((string) $zoneId) ?>" <?= (string) ($distributionOnlyRouteFormData['zona_id'] ?? '') === (string) $zoneId ? 'selected' : '' ?>>
                                                <?= e((string) ($zone['nume'] ?? '-')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($distributionOnlyRouteFormErrors['zona_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionOnlyRouteFormErrors['zona_id']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 tcv2-group-sep">Tarifare</div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="config_distribution_only_route_tarif_mod">Tarife aplicate <span class="text-danger">*</span></label>
                                    <select class="form-select <?= isset($distributionOnlyRouteFormErrors['tarif_mod']) ? 'is-invalid' : '' ?>" id="config_distribution_only_route_tarif_mod" name="route_tarif_mod" required>
                                        <?php foreach ($distributionRouteTariffModeOptions as $tariffModeValue => $tariffModeLabel): ?>
                                            <option value="<?= e((string) $tariffModeValue) ?>" <?= (string) ($distributionOnlyRouteFormData['tarif_mod'] ?? 'tona_km') === (string) $tariffModeValue ? 'selected' : '' ?>>
                                                <?= e((string) $tariffModeLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($distributionOnlyRouteFormErrors['tarif_mod'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionOnlyRouteFormErrors['tarif_mod']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="config_distribution_only_route_tarif_tona">Pret tona (RON) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control <?= isset($distributionOnlyRouteFormErrors['tarif_tona']) ? 'is-invalid' : '' ?>" id="config_distribution_only_route_tarif_tona" name="route_tarif_tona" min="0" step="0.01" value="<?= e((string) ($distributionOnlyRouteFormData['tarif_tona'] ?? '')) ?>">
                                    <?php if (isset($distributionOnlyRouteFormErrors['tarif_tona'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionOnlyRouteFormErrors['tarif_tona']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-4">
                                    <label class="form-label" for="config_distribution_only_route_cost_extra_km">Pret km (RON) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control <?= isset($distributionOnlyRouteFormErrors['cost_extra_km']) ? 'is-invalid' : '' ?>" id="config_distribution_only_route_cost_extra_km" name="route_cost_extra_km" min="0" step="0.01" value="<?= e((string) ($distributionOnlyRouteFormData['cost_extra_km'] ?? '')) ?>">
                                    <?php if (isset($distributionOnlyRouteFormErrors['cost_extra_km'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionOnlyRouteFormErrors['cost_extra_km']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 tcv2-group-sep">Vehicule</div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="config_distribution_only_route_vehicle_ids_toggle">Vehicule <span class="text-danger">*</span></label>
                                    <div class="dropdown vehicle-multiselect-dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle <?= isset($distributionOnlyRouteFormErrors['vehicle_ids']) ? 'is-invalid' : '' ?>" type="button" id="config_distribution_only_route_vehicle_ids_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                            <span class="vehicle-multiselect-label" data-default-label="-- Selecteaza vehiculele --" data-summary-mode="count" data-summary-singular="vehicul selectat" data-summary-plural="vehicule selectate"><?= e($formatVehicleCountLabel($distributionOnlyRouteSelectedVehicleIds, $vehicleLabelById)) ?></span>
                                        </button>
                                        <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="config_distribution_only_route_vehicle_ids_toggle">
                                            <div class="tcv2-vehicle-menu-search"><input type="search" class="form-control form-control-sm" data-vehicle-menu-search placeholder="Cauta vehicul..." aria-label="Cauta vehicul"></div>
                                            <div class="tcv2-vehicle-menu-empty text-muted small px-2 py-1" hidden>Niciun vehicul gasit.</div>
                                            <?php foreach ($vehicleCapacityGroups as $capacityGroup): ?>
                                                <?php
                                                $capacityGroupHasSelection = false;
                                                foreach ($capacityGroup['vehicles'] as $capacityGroupOption) {
                                                    if (in_array((string) (int) ($capacityGroupOption['id'] ?? 0), $distributionOnlyRouteSelectedVehicleIds, true)) {
                                                        $capacityGroupHasSelection = true;
                                                        break;
                                                    }
                                                }
                                                ?>
                                                <div class="tcv2-vehicle-group<?= $capacityGroupHasSelection ? '' : ' is-collapsed' ?>" data-vehicle-group data-group-label="<?= e(mb_strtolower((string) $capacityGroup['label'])) ?>">
                                                    <div class="tcv2-vehicle-group-head" data-vehicle-group-head>
                                                        <input class="form-check-input m-0" type="checkbox" data-vehicle-group-toggle aria-label="Selecteaza toate vehiculele: <?= e((string) $capacityGroup['label']) ?>">
                                                        <span><?= e((string) $capacityGroup['label']) ?></span>
                                                        <span class="tcv2-vehicle-group-count"><?= e((string) count($capacityGroup['vehicles'])) ?></span>
                                                        <i class="bi bi-chevron-down tcv2-vehicle-group-chevron" aria-hidden="true"></i>
                                                    </div>
                                                    <?php foreach ($capacityGroup['vehicles'] as $vehicle): ?>
                                                        <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                                                        <?php $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? '')); ?>
                                                        <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option" data-vehicle-garage="<?= e(mb_strtolower(trim((string) ($vehicle['garaj'] ?? '')))) ?>">
                                                            <input class="form-check-input m-0" type="checkbox" name="route_vehicle_ids[]" value="<?= e((string) $vehicleId) ?>" <?= in_array((string) $vehicleId, $distributionOnlyRouteSelectedVehicleIds, true) ? 'checked' : '' ?>>
                                                            <span><?= e(trim($vehicleLabel)) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php if (isset($distributionOnlyRouteFormErrors['vehicle_ids'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionOnlyRouteFormErrors['vehicle_ids']) ?></div><?php endif; ?>
                                </div>
                            </div>
                            <?php if (isset($distributionOnlyRouteFormErrors['route_id'])): ?><div class="invalid-feedback d-block mt-2"><?= e((string) $distributionOnlyRouteFormErrors['route_id']) ?></div><?php endif; ?>

                            <div class="mt-3 d-flex justify-content-end gap-2 transport-config-inline-actions">
                                <?php if ($isDistributionOnlyRouteEditMode): ?>
                                    <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId])) ?>">Renunta editarea</a>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary" name="panel_action" value="add_route" <?= $canAddDistributionRoute ? '' : 'disabled' ?>>
                                    <?= $isDistributionOnlyRouteEditMode ? 'Actualizeaza configuratia' : 'Adauga configuratie' ?>
                                </button>
                            </div>
                        </div>
                    </form>
                    </div>

                    <div class="transport-distribution-panel transport-distribution-route-panel mt-3">
                        <div class="transport-data-table-panel">
                            <h5 class="h6 mb-3">Configuratii Distributie existente</h5>
                            <div class="table-responsive transport-config-table-wrap transport-route-table-wrap">
                                <table class="table table-sm align-middle mb-0 transport-config-table">
                                    <thead>
                                        <tr>
                                            <th>Loc incarcare</th>
                                            <th>Zona descarcare</th>
                                            <th>Tarife aplicate</th>
                                            <th>Pret tona (RON)</th>
                                            <th>Pret km (RON)</th>
                                            <th>Vehicule</th>
                                            <th class="text-end">Actiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if ($distributionOnlyRouteRules === []): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-3">Nu exista configuratii Distributie salvate pentru beneficiarul curent.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($distributionOnlyRouteRules as $routeRule): ?>
                                            <?php
                                            $routeId = (int) ($routeRule['id'] ?? 0);
                                            $routeTariffMode = (string) ($routeRule['tarif_mod'] ?? 'tona_km');
                                            if (!isset($distributionRouteTariffModeOptions[$routeTariffMode])) {
                                                $routeTariffMode = 'tona_km';
                                            }
                                            $routeUsesTonTariff = in_array($routeTariffMode, ['tona_km', 'tona'], true);
                                            $routeUsesKmTariff = in_array($routeTariffMode, ['tona_km', 'km'], true);
                                            $routeVehicleItems = $buildRouteVehicleItems((string) ($routeRule['vehicle_ids'] ?? ''), $vehicleDetailsById);
                                            ?>
                                            <tr>
                                                <td><?= e((string) ($routeRule['loc_nume'] ?? '-')) ?></td>
                                                <td><?= e((string) ($routeRule['zona_nume'] ?? '-')) ?></td>
                                                <td><?= e((string) $distributionRouteTariffModeOptions[$routeTariffMode]) ?></td>
                                                <td><?= $routeUsesTonTariff ? e(format_number_ro((float) ($routeRule['tarif_tona'] ?? 0), 2)) : '-' ?></td>
                                                <td><?= $routeUsesKmTariff ? e(format_number_ro((float) ($routeRule['cost_extra_km'] ?? 0), 2)) : '-' ?></td>
                                                <td class="dispatcher-vehicle-cell"><?= $renderRouteVehicleButton($routeVehicleItems, 'distributie-' . $routeId) ?></td>
                                                <td class="text-end transport-route-actions-cell">
                                                    <?= $renderTransportRowActions(
                                                        'distributie-' . $routeId,
                                                        build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId, 'route_distributie_edit_id' => $routeId]),
                                                        build_query_url(['page' => 'dispecer_curse', 'action' => 'config_delete_ruta']),
                                                        [
                                                            'id' => $routeId,
                                                            'beneficiar_id' => $distributionBeneficiaryId,
                                                            'route_scope' => 'distributie',
                                                        ],
                                                        'Sigur doresti sa stergi aceasta configuratie Distributie?'
                                                    ) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
            <?php endif; ?>
        </section>

        <section class="tcv2-panel" data-config-tab-panel="primar_distributie" <?= $activeConfigTab === 'primar_distributie' ? '' : 'hidden' ?>>
            <div class="tcv2-panel-head">
                <div>
                    <h4 class="h6 mb-1">Rute Primar+Distributie — <?= $distributionBeneficiaryName !== '' ? e($distributionBeneficiaryName) : 'beneficiarul curent' ?></h4>
                    <p class="tcv2-panel-hint mb-0">Folosite strict pentru cursele cu Tip Transport: Primar+Distributie. Fiecare ruta are Pret tona + Pret km, Km agreati si optional Cost cursa.</p>
                </div>
                <?php if ($isPrimaryDistributionSelected && $catalogConfigReady): ?>
                    <div class="tcv2-panel-actions">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-modal-open="tcv2_catalog_modal">+ Loc / Zona</button>
                        <button type="button" class="btn btn-sm btn-primary" data-toggle-form="tcv2_form_primar_distributie" aria-expanded="<?= $primaryDistributionRouteFormOpen ? 'true' : 'false' ?>"><?= $isPrimaryDistributionRouteEditMode ? 'Editare configuratie' : '+ Adauga configuratie' ?></button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$isPrimaryDistributionSelected || !$catalogConfigReady): ?>
                <div class="tcv2-locked">
                    <i class="bi bi-lock-fill tcv2-locked-icon" aria-hidden="true"></i>
                    <div>
                        <strong>Rutele Primar+Distributie sunt blocate.</strong>
                        <p class="mb-0"><?= !$isPrimaryDistributionSelected ? 'Bifeaza tipul Primar+Distributie la pasul 1 si salveaza regula beneficiarului, apoi configurezi rutele aici.' : 'Salveaza mai intai regula beneficiarului cu tipul Primar+Distributie bifat, apoi poti configura rutele Primar+Distributie.' ?></p>
                    </div>
                </div>
            <?php else: ?>
                    <?php if (!$canAddDistributionRoute): ?>
                        <div class="alert alert-warning mt-3 mb-0">Adauga cel putin un Loc incarcare si o Zona descarcare (foloseste butonul „+ Loc / Zona" de mai sus), apoi poti crea configuratii Primar+Distributie.</div>
                    <?php endif; ?>

                    <div class="tcv2-form-disclosure" id="tcv2_form_primar_distributie" <?= $primaryDistributionRouteFormOpen ? '' : 'hidden' ?>>
                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_distributie'])) ?>" novalidate class="mt-3 transport-route-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="beneficiar_id" value="<?= e((string) $distributionBeneficiaryId) ?>">
                        <input type="hidden" name="route_scope" value="primar_distributie">
                        <input type="hidden" name="route_id" value="<?= e((string) ($primaryDistributionRouteFormData['route_id'] ?? '')) ?>">

                        <div class="transport-distribution-panel transport-distribution-route-panel">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <h5 class="h6 mb-0">Configuratii rute Primar+Distributie</h5>
                                <span class="text-muted small">Acest panel este folosit strict pentru cursele cu Tip Transport: Primar+Distributie.</span>
                            </div>
                            <?php if ($isPrimaryDistributionRouteEditMode): ?>
                                <div class="alert alert-info py-2 mb-3">Editezi o configuratie Primar+Distributie existenta.</div>
                            <?php endif; ?>
                            <div class="row g-3 align-items-end transport-distribution-route-grid">
                                <div class="col-12 tcv2-group-sep">Ruta</div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="config_primary_distribution_route_loc_id">Loc incarcare <span class="text-danger">*</span></label>
                                    <select class="form-select <?= isset($primaryDistributionRouteFormErrors['loc_id']) ? 'is-invalid' : '' ?>" id="config_primary_distribution_route_loc_id" name="route_loc_id" required>
                                        <option value="">Selecteaza locatia de incarcare</option>
                                        <?php foreach (($locations ?? []) as $location): ?>
                                            <?php $locationId = (int) ($location['id'] ?? 0); ?>
                                            <option value="<?= e((string) $locationId) ?>" <?= (string) ($primaryDistributionRouteFormData['loc_id'] ?? '') === (string) $locationId ? 'selected' : '' ?>>
                                                <?= e((string) ($location['nume'] ?? '-')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($primaryDistributionRouteFormErrors['loc_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['loc_id']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="config_primary_distribution_route_zona_id">Zona descarcare <span class="text-danger">*</span></label>
                                    <select class="form-select <?= isset($primaryDistributionRouteFormErrors['zona_id']) ? 'is-invalid' : '' ?>" id="config_primary_distribution_route_zona_id" name="route_zona_id" required>
                                        <option value="">Selecteaza zona de descarcare</option>
                                        <?php foreach (($zones ?? []) as $zone): ?>
                                            <?php $zoneId = (int) ($zone['id'] ?? 0); ?>
                                            <option value="<?= e((string) $zoneId) ?>" <?= (string) ($primaryDistributionRouteFormData['zona_id'] ?? '') === (string) $zoneId ? 'selected' : '' ?>>
                                                <?= e((string) ($zone['nume'] ?? '-')) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (isset($primaryDistributionRouteFormErrors['zona_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['zona_id']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 tcv2-group-sep">Tarifare</div>
                                <div class="col-12 col-md-3">
                                    <label class="form-label" for="config_primary_distribution_route_tarif_tona">Pret tona (RON) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control <?= isset($primaryDistributionRouteFormErrors['tarif_tona']) ? 'is-invalid' : '' ?>" id="config_primary_distribution_route_tarif_tona" name="route_tarif_tona" min="0" step="0.01" value="<?= e((string) ($primaryDistributionRouteFormData['tarif_tona'] ?? '')) ?>" required>
                                    <?php if (isset($primaryDistributionRouteFormErrors['tarif_tona'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['tarif_tona']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-3">
                                    <label class="form-label" for="config_primary_distribution_route_cost_extra_km">Pret km (RON) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control <?= isset($primaryDistributionRouteFormErrors['cost_extra_km']) ? 'is-invalid' : '' ?>" id="config_primary_distribution_route_cost_extra_km" name="route_cost_extra_km" min="0" step="0.01" value="<?= e((string) ($primaryDistributionRouteFormData['cost_extra_km'] ?? '')) ?>" required>
                                    <?php if (isset($primaryDistributionRouteFormErrors['cost_extra_km'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['cost_extra_km']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-3">
                                    <label class="form-label" for="config_primary_distribution_route_km_tarifare">Km agreati <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control <?= isset($primaryDistributionRouteFormErrors['km_tarifare']) ? 'is-invalid' : '' ?>" id="config_primary_distribution_route_km_tarifare" name="route_km_tarifare" min="1" step="1" value="<?= e((string) ($primaryDistributionRouteFormData['km_tarifare'] ?? '')) ?>" required>
                                    <?php if (isset($primaryDistributionRouteFormErrors['km_tarifare'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['km_tarifare']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-3">
                                    <label class="form-label" for="config_primary_distribution_route_cost_cursa">Cost cursa (RON)</label>
                                    <input type="number" class="form-control <?= isset($primaryDistributionRouteFormErrors['cost_cursa']) ? 'is-invalid' : '' ?>" id="config_primary_distribution_route_cost_cursa" name="route_cost_cursa" min="0" step="0.01" value="<?= e((string) ($primaryDistributionRouteFormData['cost_cursa'] ?? '')) ?>">
                                    <?php if (isset($primaryDistributionRouteFormErrors['cost_cursa'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['cost_cursa']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 tcv2-group-sep">Vehicule si optiuni</div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="config_primary_distribution_route_vehicle_ids_toggle">Vehicule <span class="text-danger">*</span></label>
                                    <div class="dropdown vehicle-multiselect-dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle <?= isset($primaryDistributionRouteFormErrors['vehicle_ids']) ? 'is-invalid' : '' ?>" type="button" id="config_primary_distribution_route_vehicle_ids_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                            <span class="vehicle-multiselect-label" data-default-label="-- Selecteaza vehiculele --" data-summary-mode="count" data-summary-singular="vehicul selectat" data-summary-plural="vehicule selectate"><?= e($formatVehicleCountLabel($primaryDistributionRouteSelectedVehicleIds, $vehicleLabelById)) ?></span>
                                        </button>
                                        <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="config_primary_distribution_route_vehicle_ids_toggle">
                                            <div class="tcv2-vehicle-menu-search"><input type="search" class="form-control form-control-sm" data-vehicle-menu-search placeholder="Cauta vehicul..." aria-label="Cauta vehicul"></div>
                                            <div class="tcv2-vehicle-menu-empty text-muted small px-2 py-1" hidden>Niciun vehicul gasit.</div>
                                            <?php foreach ($vehicleCapacityGroups as $capacityGroup): ?>
                                                <?php
                                                $capacityGroupHasSelection = false;
                                                foreach ($capacityGroup['vehicles'] as $capacityGroupOption) {
                                                    if (in_array((string) (int) ($capacityGroupOption['id'] ?? 0), $primaryDistributionRouteSelectedVehicleIds, true)) {
                                                        $capacityGroupHasSelection = true;
                                                        break;
                                                    }
                                                }
                                                ?>
                                                <div class="tcv2-vehicle-group<?= $capacityGroupHasSelection ? '' : ' is-collapsed' ?>" data-vehicle-group data-group-label="<?= e(mb_strtolower((string) $capacityGroup['label'])) ?>">
                                                    <div class="tcv2-vehicle-group-head" data-vehicle-group-head>
                                                        <input class="form-check-input m-0" type="checkbox" data-vehicle-group-toggle aria-label="Selecteaza toate vehiculele: <?= e((string) $capacityGroup['label']) ?>">
                                                        <span><?= e((string) $capacityGroup['label']) ?></span>
                                                        <span class="tcv2-vehicle-group-count"><?= e((string) count($capacityGroup['vehicles'])) ?></span>
                                                        <i class="bi bi-chevron-down tcv2-vehicle-group-chevron" aria-hidden="true"></i>
                                                    </div>
                                                    <?php foreach ($capacityGroup['vehicles'] as $vehicle): ?>
                                                        <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                                                        <?php $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? '')); ?>
                                                        <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option" data-vehicle-garage="<?= e(mb_strtolower(trim((string) ($vehicle['garaj'] ?? '')))) ?>">
                                                            <input class="form-check-input m-0" type="checkbox" name="route_vehicle_ids[]" value="<?= e((string) $vehicleId) ?>" <?= in_array((string) $vehicleId, $primaryDistributionRouteSelectedVehicleIds, true) ? 'checked' : '' ?>>
                                                            <span><?= e(trim($vehicleLabel)) ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php if (isset($primaryDistributionRouteFormErrors['vehicle_ids'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['vehicle_ids']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-6">
                                    <label class="form-label d-block">Aplicare Cost Cursa</label>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="config_primary_distribution_route_aplica_cost_cursa" name="route_aplica_cost_cursa" value="1" <?= (string) ($primaryDistributionRouteFormData['aplica_cost_cursa'] ?? '0') === '1' ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="config_primary_distribution_route_aplica_cost_cursa">Aplica doar pe aceasta ruta</label>
                                    </div>
                                </div>
                            </div>
                            <?php if (isset($primaryDistributionRouteFormErrors['route_id'])): ?><div class="invalid-feedback d-block mt-2"><?= e((string) $primaryDistributionRouteFormErrors['route_id']) ?></div><?php endif; ?>

                            <div class="mt-3 d-flex justify-content-end gap-2 transport-config-inline-actions">
                                <?php if ($isPrimaryDistributionRouteEditMode): ?>
                                    <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId])) ?>">Renunta editarea</a>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary" name="panel_action" value="add_route" <?= $canAddDistributionRoute ? '' : 'disabled' ?>>
                                    <?= $isPrimaryDistributionRouteEditMode ? 'Actualizeaza configuratia' : 'Adauga configuratie' ?>
                                </button>
                            </div>
                        </div>
                    </form>
                    </div>

                    <div class="transport-distribution-panel transport-distribution-route-panel mt-3">
                        <div class="transport-data-table-panel">
                            <h5 class="h6 mb-3">Configuratii Primar+Distributie existente</h5>
                            <div class="table-responsive transport-config-table-wrap transport-route-table-wrap">
                                <table class="table table-sm align-middle mb-0 transport-config-table">
                                    <thead>
                                        <tr>
                                            <th>Loc incarcare</th>
                                            <th>Zona descarcare</th>
                                            <th>Pret tona (RON)</th>
                                            <th>Pret km (RON)</th>
                                            <th>Km agreati</th>
                                            <th>Cost cursa (RON)</th>
                                            <th>Aplica cost cursa</th>
                                            <th>Vehicule</th>
                                            <th class="text-end">Actiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if ($primaryDistributionRouteRules === []): ?>
                                        <tr><td colspan="9" class="text-center text-muted py-3">Nu exista configuratii Primar+Distributie salvate pentru beneficiarul curent.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($primaryDistributionRouteRules as $routeRule): ?>
                                            <?php
                                            $routeId = (int) ($routeRule['id'] ?? 0);
                                            $routeVehicleItems = $buildRouteVehicleItems((string) ($routeRule['vehicle_ids'] ?? ''), $vehicleDetailsById);
                                            ?>
                                            <tr>
                                                <td><?= e((string) ($routeRule['loc_nume'] ?? '-')) ?></td>
                                                <td><?= e((string) ($routeRule['zona_nume'] ?? '-')) ?></td>
                                                <td><?= e(format_number_ro((float) ($routeRule['tarif_tona'] ?? 0), 2)) ?></td>
                                                <td><?= e(format_number_ro((float) ($routeRule['cost_extra_km'] ?? 0), 2)) ?></td>
                                                <td><?= e((string) ((int) max(0, (int) ($routeRule['km_tarifare'] ?? 0)))) ?></td>
                                                <td><?= e(format_number_ro((float) ($routeRule['cost_cursa'] ?? 0), 2)) ?></td>
                                                <td><?= !empty($routeRule['aplica_cost_cursa']) ? 'Da' : 'Nu' ?></td>
                                                <td class="dispatcher-vehicle-cell"><?= $renderRouteVehicleButton($routeVehicleItems, 'primar-distributie-' . $routeId) ?></td>
                                                <td class="text-end transport-route-actions-cell">
                                                    <?= $renderTransportRowActions(
                                                        'primar-distributie-' . $routeId,
                                                        build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId, 'route_primar_distributie_edit_id' => $routeId]),
                                                        build_query_url(['page' => 'dispecer_curse', 'action' => 'config_delete_ruta']),
                                                        [
                                                            'id' => $routeId,
                                                            'beneficiar_id' => $distributionBeneficiaryId,
                                                            'route_scope' => 'primar_distributie',
                                                        ],
                                                        'Sigur doresti sa stergi aceasta configuratie Primar+Distributie?'
                                                    ) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
            <?php endif; ?>
        </section>

        <section class="tcv2-panel" data-config-tab-panel="primar" <?= $activeConfigTab === 'primar' ? '' : 'hidden' ?>>
            <div class="tcv2-panel-head">
                <div>
                    <h4 class="h6 mb-1">Rute Primar — <?= $distributionBeneficiaryName !== '' ? e($distributionBeneficiaryName) : 'beneficiarul curent' ?></h4>
                    <p class="tcv2-panel-hint mb-0">Perechi Loc incarcare ↔ Zona descarcare cu Km tarifare pentru Primar km / Primar tone. Tarifele Pret/km si Pret/tona definite la pasul 1 se aplica pe aceste rute.</p>
                </div>
                <?php if ($primaryConfigReady): ?>
                    <div class="tcv2-panel-actions">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-modal-open="tcv2_catalog_modal">+ Loc / Zona</button>
                        <button type="button" class="btn btn-sm btn-primary" data-toggle-form="tcv2_form_primar" aria-expanded="<?= $primaryRouteFormOpen ? 'true' : 'false' ?>"><?= $isPrimaryRouteEditMode ? 'Editare ruta' : '+ Adauga ruta' ?></button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$primaryConfigReady): ?>
                <div class="tcv2-locked">
                    <i class="bi bi-lock-fill tcv2-locked-icon" aria-hidden="true"></i>
                    <div>
                        <strong>Rutele Primar sunt blocate.</strong>
                        <p class="mb-0"><?= !$isPrimarSelected ? 'Bifeaza tipul Primar km la pasul 1 si salveaza regula beneficiarului, apoi configurezi rutele aici.' : 'Salveaza mai intai regula beneficiarului cu tipul Primar km bifat.' ?></p>
                    </div>
                </div>
            <?php else: ?>
                <?php if (!$canAddPrimaryRoute): ?>
                    <div class="alert alert-warning mt-3 mb-0">Adauga cel putin un Loc incarcare si o Zona descarcare (foloseste butonul „+ Loc / Zona" de mai sus), apoi poti crea rute Primar.</div>
                <?php endif; ?>

                <div class="tcv2-form-disclosure" id="tcv2_form_primar" <?= $primaryRouteFormOpen ? '' : 'hidden' ?>>
                <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_primar_ruta'])) ?>" novalidate class="mt-2 transport-route-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="beneficiar_id" value="<?= e((string) $distributionBeneficiaryId) ?>">
                    <input type="hidden" name="route_primar_id" value="<?= e((string) ($primaryRouteFormData['route_id'] ?? '')) ?>">

                    <div class="transport-distribution-panel transport-distribution-route-panel">
                        <?php if ($isPrimaryRouteEditMode): ?>
                            <div class="alert alert-info py-2 mb-3">Editezi o ruta Primar existenta. Poti modifica perechea Loc ↔ Zona si Km tarifare.</div>
                        <?php endif; ?>

                        <div class="row g-3 align-items-end transport-distribution-route-grid">
                            <div class="col-12 tcv2-group-sep">Ruta</div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="config_primary_route_loc_id">Loc incarcare <span class="text-danger">*</span></label>
                                <select class="form-select <?= isset($primaryRouteFormErrors['loc_id']) ? 'is-invalid' : '' ?>" id="config_primary_route_loc_id" name="route_primar_loc_id" required>
                                    <option value="">Selecteaza locatia de incarcare</option>
                                    <?php foreach (($locations ?? []) as $location): ?>
                                        <?php $locationId = (int) ($location['id'] ?? 0); ?>
                                        <option value="<?= e((string) $locationId) ?>" <?= (string) ($primaryRouteFormData['loc_id'] ?? '') === (string) $locationId ? 'selected' : '' ?>>
                                            <?= e((string) ($location['nume'] ?? '-')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($primaryRouteFormErrors['loc_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryRouteFormErrors['loc_id']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label" for="config_primary_route_zona_id">Zona descarcare <span class="text-danger">*</span></label>
                                <select class="form-select <?= isset($primaryRouteFormErrors['zona_id']) ? 'is-invalid' : '' ?>" id="config_primary_route_zona_id" name="route_primar_zona_id" required>
                                    <option value="">Selecteaza zona de descarcare</option>
                                    <?php foreach (($zones ?? []) as $zone): ?>
                                        <?php $zoneId = (int) ($zone['id'] ?? 0); ?>
                                        <option value="<?= e((string) $zoneId) ?>" <?= (string) ($primaryRouteFormData['zona_id'] ?? '') === (string) $zoneId ? 'selected' : '' ?>>
                                            <?= e((string) ($zone['nume'] ?? '-')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($primaryRouteFormErrors['zona_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryRouteFormErrors['zona_id']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 tcv2-group-sep">Tarifare</div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="config_primary_route_km_tarifare">Km tarifare <span class="text-danger">*</span></label>
                                <input type="number" class="form-control <?= isset($primaryRouteFormErrors['km_tarifare']) ? 'is-invalid' : '' ?>" id="config_primary_route_km_tarifare" name="route_primar_km_tarifare" min="1" step="1" value="<?= e((string) ($primaryRouteFormData['km_tarifare'] ?? '')) ?>" <?= (string) ($primaryRouteFormData['km_agreati_manual'] ?? '0') === '1' ? 'disabled' : 'required' ?>>
                                <?php if (isset($primaryRouteFormErrors['km_tarifare'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryRouteFormErrors['km_tarifare']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label" for="config_primary_route_cost_cursa">Cost cursa (RON)</label>
                                <input type="number" class="form-control <?= isset($primaryRouteFormErrors['cost_cursa']) ? 'is-invalid' : '' ?>" id="config_primary_route_cost_cursa" name="route_primar_cost_cursa" min="0" step="0.01" value="<?= e((string) ($primaryRouteFormData['cost_cursa'] ?? '')) ?>">
                                <?php if (isset($primaryRouteFormErrors['cost_cursa'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryRouteFormErrors['cost_cursa']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 tcv2-group-sep">Vehicule si optiuni</div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="config_primary_route_garage_filter_toggle">Garaj (filtru vehicule)</label>
                                <div class="dropdown vehicle-multiselect-dropdown" data-garage-filter-menu="config_primary_route_vehicle_ids_toggle">
                                    <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle" type="button" id="config_primary_route_garage_filter_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                        <span data-role="garage-filter-label">Toate garajele</span>
                                    </button>
                                    <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu">
                                        <?php foreach ($vehicleGarageOptions as $garageKey => $garageLabel): ?>
                                            <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1">
                                                <input class="form-check-input m-0" type="checkbox" value="<?= e((string) $garageKey) ?>">
                                                <span><?= e((string) $garageLabel) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="form-text">Optional: alege unul sau mai multe garaje — lista de vehicule arata doar vehiculele din garajele selectate.</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="config_primary_route_vehicle_ids_toggle">Vehicule <span class="text-danger">*</span></label>
                                <div class="dropdown vehicle-multiselect-dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle <?= isset($primaryRouteFormErrors['vehicle_ids']) ? 'is-invalid' : '' ?>" type="button" id="config_primary_route_vehicle_ids_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                        <span class="vehicle-multiselect-label" data-default-label="-- Selecteaza vehiculele --" data-summary-mode="count" data-summary-singular="vehicul selectat" data-summary-plural="vehicule selectate"><?= e($formatVehicleCountLabel($primaryRouteSelectedVehicleIds, $vehicleLabelById)) ?></span>
                                    </button>
                                    <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="config_primary_route_vehicle_ids_toggle">
                                        <div class="tcv2-vehicle-menu-search"><input type="search" class="form-control form-control-sm" data-vehicle-menu-search placeholder="Cauta vehicul..." aria-label="Cauta vehicul"></div>
                                        <div class="tcv2-vehicle-menu-empty text-muted small px-2 py-1" hidden>Niciun vehicul gasit.</div>
                                        <?php foreach ($vehicleCapacityGroups as $capacityGroup): ?>
                                            <?php
                                            $capacityGroupHasSelection = false;
                                            foreach ($capacityGroup['vehicles'] as $capacityGroupOption) {
                                                if (in_array((string) (int) ($capacityGroupOption['id'] ?? 0), $primaryRouteSelectedVehicleIds, true)) {
                                                    $capacityGroupHasSelection = true;
                                                    break;
                                                }
                                            }
                                            ?>
                                            <div class="tcv2-vehicle-group<?= $capacityGroupHasSelection ? '' : ' is-collapsed' ?>" data-vehicle-group data-group-label="<?= e(mb_strtolower((string) $capacityGroup['label'])) ?>">
                                                <div class="tcv2-vehicle-group-head" data-vehicle-group-head>
                                                    <input class="form-check-input m-0" type="checkbox" data-vehicle-group-toggle aria-label="Selecteaza toate vehiculele: <?= e((string) $capacityGroup['label']) ?>">
                                                    <span><?= e((string) $capacityGroup['label']) ?></span>
                                                    <span class="tcv2-vehicle-group-count"><?= e((string) count($capacityGroup['vehicles'])) ?></span>
                                                    <i class="bi bi-chevron-down tcv2-vehicle-group-chevron" aria-hidden="true"></i>
                                                </div>
                                                <?php foreach ($capacityGroup['vehicles'] as $vehicle): ?>
                                                    <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                                                    <?php $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? '')); ?>
                                                    <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option" data-vehicle-garage="<?= e(mb_strtolower(trim((string) ($vehicle['garaj'] ?? '')))) ?>">
                                                        <input class="form-check-input m-0" type="checkbox" name="route_primar_vehicle_ids[]" value="<?= e((string) $vehicleId) ?>" <?= in_array((string) $vehicleId, $primaryRouteSelectedVehicleIds, true) ? 'checked' : '' ?>>
                                                        <span><?= e(trim($vehicleLabel)) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php if (isset($primaryRouteFormErrors['vehicle_ids'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryRouteFormErrors['vehicle_ids']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label" for="config_primary_route_km_agreati_manual">Km agreati</label>
                                <div class="form-check form-switch transport-config-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="config_primary_route_km_agreati_manual" name="route_primar_km_agreati_manual" value="1" <?= (string) ($primaryRouteFormData['km_agreati_manual'] ?? '0') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="config_primary_route_km_agreati_manual">Introducere manuala in cursa</label>
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <label class="form-label d-block">Aplicare Cost Cursa</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="config_primary_route_aplica_cost_cursa" name="route_primar_aplica_cost_cursa" value="1" <?= (string) ($primaryRouteFormData['aplica_cost_cursa'] ?? '0') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="config_primary_route_aplica_cost_cursa">Aplica doar pe aceasta ruta</label>
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <div class="form-check form-switch transport-config-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="config_primary_route_activ" name="route_primar_activ" value="1" <?= (string) ($primaryRouteFormData['activ'] ?? '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="config_primary_route_activ">Activ</label>
                                </div>
                            </div>
                        </div>
                        <?php if (isset($primaryRouteFormErrors['route_id'])): ?><div class="invalid-feedback d-block mt-2"><?= e((string) $primaryRouteFormErrors['route_id']) ?></div><?php endif; ?>

                        <div class="mt-3 d-flex justify-content-end gap-2 transport-config-inline-actions">
                            <?php if ($isPrimaryRouteEditMode): ?>
                                <a
                                    class="btn btn-outline-secondary"
                                    href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId])) ?>"
                                >
                                    Renunta editarea
                                </a>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary" <?= $canAddPrimaryRoute ? '' : 'disabled' ?>>
                                <?= $isPrimaryRouteEditMode ? 'Actualizeaza ruta Primar' : 'Adauga ruta Primar' ?>
                            </button>
                        </div>
                    </div>
                </form>
                </div>

                <div class="mt-4 transport-data-table-panel">
                            <h5 class="h6 mb-3">Rute Primar existente</h5>
                            <div class="table-responsive transport-config-table-wrap transport-route-table-wrap">
                                <table class="table table-sm align-middle mb-0 transport-config-table">
                                    <thead>
                                        <tr>
                                            <th>Loc incarcare</th>
                                            <th>Zona descarcare</th>
                                            <th>Km tarifare</th>
                                            <th>Km manual</th>
                                            <th>Cost cursa (RON)</th>
                                            <th>Aplica cost cursa</th>
                                            <th>Vehicule</th>
                                            <th>Status</th>
                                            <th class="text-end">Actiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (($primaryRouteRules ?? []) === []): ?>
                                        <tr><td colspan="9" class="text-center text-muted py-3">Nu exista rute Primar salvate pentru acest beneficiar.</td></tr>
                                    <?php else: ?>
                                        <?php foreach (($primaryRouteRules ?? []) as $primaryRouteRule): ?>
                                            <?php
                                            $primaryRouteId = (int) ($primaryRouteRule['id'] ?? 0);
                                            $primaryRouteVehicleItems = $buildRouteVehicleItems((string) ($primaryRouteRule['vehicle_ids'] ?? ''), $vehicleDetailsById);
                                            ?>
                                            <tr>
                                                <td><?= e((string) ($primaryRouteRule['loc_nume'] ?? '-')) ?></td>
                                                <td><?= e((string) ($primaryRouteRule['zona_nume'] ?? '-')) ?></td>
                                                <td><?= !empty($primaryRouteRule['km_agreati_manual']) ? '-' : e((string) ((int) ($primaryRouteRule['km_tarifare'] ?? 0))) ?></td>
                                                <td><?= !empty($primaryRouteRule['km_agreati_manual']) ? 'Da' : 'Nu' ?></td>
                                                <td><?= e(format_number_ro((float) ($primaryRouteRule['cost_cursa'] ?? 0), 2)) ?></td>
                                                <td><?= !empty($primaryRouteRule['aplica_cost_cursa']) ? 'Da' : 'Nu' ?></td>
                                                <td class="dispatcher-vehicle-cell"><?= $renderRouteVehicleButton($primaryRouteVehicleItems, 'primar-' . $primaryRouteId) ?></td>
                                                <td><?= !empty($primaryRouteRule['activ']) ? '<span class="badge transport-status-badge transport-status-active">Activ</span>' : '<span class="badge transport-status-badge transport-status-inactive">Inactiv</span>' ?></td>
                                                <td class="text-end transport-route-actions-cell">
                                                    <?= $renderTransportRowActions(
                                                        'primar-' . $primaryRouteId,
                                                        build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId, 'route_primar_edit_id' => $primaryRouteId]),
                                                        build_query_url(['page' => 'dispecer_curse', 'action' => 'config_delete_ruta_primar']),
                                                        [
                                                            'id' => $primaryRouteId,
                                                            'beneficiar_id' => $distributionBeneficiaryId,
                                                        ],
                                                        'Sigur doresti sa stergi aceasta ruta Primar?'
                                                    ) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
            <?php endif; ?>
        </section>
    </div>

        <details class="tcv2-details-table">
            <summary>Tabel comparativ reguli beneficiari (<?= e((string) count($beneficiaries ?? [])) ?>)</summary>
            <div class="table-responsive transport-rules-table-wrap transport-data-table-panel mt-2">
                <table class="table table-sm align-middle mb-0 transport-config-table transport-rules-table">
                    <thead>
                    <tr>
                        <th class="col-beneficiar text-start">Beneficiar</th>
                        <th class="col-status text-center">Status</th>
                        <th class="text-start">Tarife pe tip transport</th>
                        <th class="col-actions text-end">Actiuni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (($beneficiaries ?? []) === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">Nu exista reguli salvate.</td></tr>
                    <?php else: ?>
                        <?php foreach (($beneficiaries ?? []) as $beneficiary): ?>
                            <?php
                            $beneficiaryId = (int) ($beneficiary['id'] ?? 0);
                            $beneficiaryName = (string) ($beneficiary['nume'] ?? '-');
                            $tariffLines = [];
                            if (!empty($beneficiary['suporta_primar'])) {
                                $tariffLines[] = ['Primar', format_number_ro((float) ($beneficiary['pret_km'] ?? 0), 2) . ' lei/km · ' . format_number_ro((float) ($beneficiary['pret_tona'] ?? 0), 2) . ' lei/tona'];
                            }
                            if (!empty($beneficiary['suporta_distributie'])) {
                                $distributionBaseParts = [];
                                if ((float) ($beneficiary['pret_distributie_km'] ?? 0) > 0) {
                                    $distributionBaseParts[] = format_number_ro((float) $beneficiary['pret_distributie_km'], 2) . ' lei/km';
                                }
                                if ((float) ($beneficiary['pret_distributie_tona'] ?? 0) > 0) {
                                    $distributionBaseParts[] = format_number_ro((float) $beneficiary['pret_distributie_tona'], 2) . ' lei/tona';
                                }
                                $tariffLines[] = ['Distributie', $distributionBaseParts !== [] ? implode(' · ', $distributionBaseParts) . ' · restul pe ruta' : 'preturi pe ruta'];
                            }
                            if (!empty($beneficiary['suporta_primar_distributie'])) {
                                $tariffLines[] = ['P+D', 'preturi pe ruta'];
                            }
                            if (!empty($beneficiary['suporta_compresor'])) {
                                $compressorParts = [];
                                foreach ([
                                    ['pret_ora_aspirare', 'lei/ora aspirare'],
                                    ['pret_km_dislocare', 'lei/km dislocare'],
                                    ['pret_tona_livrata', 'lei/t livrata'],
                                    ['pret_tona_aspirata_lichida', 'lei/t asp. lichida'],
                                    ['pret_tona_aspirata_gazoasa', 'lei/t asp. gazoasa'],
                                ] as $compressorField) {
                                    $compressorValue = (float) ($beneficiary[$compressorField[0]] ?? 0);
                                    if ($compressorValue > 0) {
                                        $compressorParts[] = format_number_ro($compressorValue, 2) . ' ' . $compressorField[1];
                                    }
                                }
                                $tariffLines[] = ['Compresor', $compressorParts !== [] ? implode(' · ', $compressorParts) : 'tarife necompletate'];
                            }
                            ?>
                            <tr class="tcv2-ben-row" data-expand-trigger="tcv2_ben_<?= e((string) $beneficiaryId) ?>">
                                <td class="col-beneficiar text-start" title="<?= e($beneficiaryName) ?>"><span class="beneficiary-ellipsis"><?= e($beneficiaryName) ?></span></td>
                                <td class="col-status text-center"><?= !empty($beneficiary['activ']) ? '<span class="badge transport-status-badge transport-status-active">Activ</span>' : '<span class="badge transport-status-badge transport-status-inactive">Inactiv</span>' ?></td>
                                <td class="tcv2-tariff-summary-cell">
                                    <?php if ($tariffLines === []): ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php else: ?>
                                        <button
                                            type="button"
                                            class="tcv2-expand-btn"
                                            data-expand-row="tcv2_ben_<?= e((string) $beneficiaryId) ?>"
                                            aria-expanded="false"
                                            aria-controls="tcv2_ben_<?= e((string) $beneficiaryId) ?>"
                                            title="Vezi tarifele configurate"
                                        >
                                            <span><?= count($tariffLines) === 1 ? '1 tarif configurat' : e((string) count($tariffLines)) . ' tarife configurate' ?></span>
                                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td class="col-actions text-end transport-route-actions-cell">
                                    <?= $renderTransportRowActions(
                                        'beneficiar-' . $beneficiaryId,
                                        build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId]),
                                        build_query_url(['page' => 'dispecer_curse', 'action' => 'config_delete_beneficiar']),
                                        [
                                            'id' => $beneficiaryId,
                                        ],
                                        'Sigur doresti sa stergi acest beneficiar?',
                                        build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_view_id' => $beneficiaryId])
                                    ) ?>
                                </td>
                            </tr>
                            <?php if ($tariffLines !== []): ?>
                                <tr class="tcv2-tariff-details" id="tcv2_ben_<?= e((string) $beneficiaryId) ?>" hidden>
                                    <td colspan="4">
                                        <div class="tcv2-tariff-details-inner">
                                            <?php foreach ($tariffLines as $tariffLine): ?>
                                                <div class="tcv2-tariff-line">
                                                    <span class="tcv2-tariff-tag"><?= e((string) $tariffLine[0]) ?></span>
                                                    <span class="tcv2-tariff-val"><?= e((string) $tariffLine[1]) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </div>
    </div>
</div>

<?php if ($distributionBeneficiaryId > 0): ?>
<div class="tcv2-modal-backdrop" id="tcv2_catalog_modal" hidden>
    <div class="tcv2-modal" role="dialog" aria-modal="true" aria-label="Adauga loc incarcare sau zona descarcare">
        <div class="tcv2-modal-head">
            <h5 class="h6 mb-0">Adauga in catalog (Loc / Zona)</h5>
            <button type="button" class="tcv2-modal-close" data-modal-close aria-label="Inchide">&times;</button>
        </div>
        <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_catalog'])) ?>" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="beneficiar_id" value="<?= e((string) $distributionBeneficiaryId) ?>">
            <input type="hidden" name="loc_id" value="">
            <input type="hidden" name="zona_id" value="">
            <div class="mb-3">
                <label class="form-label" for="tcv2_modal_loc_nume">Loc incarcare</label>
                <input type="text" class="form-control" id="tcv2_modal_loc_nume" name="loc_nume" maxlength="120" value="">
            </div>
            <div class="mb-3">
                <label class="form-label" for="tcv2_modal_zona_nume">Zona descarcare</label>
                <input type="text" class="form-control" id="tcv2_modal_zona_nume" name="zona_nume" maxlength="120" value="">
            </div>
            <p class="text-muted small mb-3">Poti completa doar unul dintre campuri. Dupa salvare, pagina se reincarca si noul loc/zona apare in liste.</p>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary" data-modal-close>Renunta</button>
                <button type="submit" class="btn btn-primary">Salveaza in catalog</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<style>
.dispatcher-vehicle-cell,
.transport-route-actions-cell {
    white-space: nowrap;
}

.dispatcher-vehicle-cell {
    min-width: 7.9rem;
}

.dispatcher-vehicle-list,
.transport-row-actions {
    display: inline-flex;
    align-items: center;
}

.dispatcher-vehicle-count-btn,
.transport-row-actions-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #ffffff;
    border-radius: 6px;
    line-height: 1;
}

.dispatcher-vehicle-count-btn {
    min-width: 7.2rem;
    height: 2.15rem;
    gap: 0.42rem;
    padding: 0 0.72rem;
    border: 1px solid #0d6efd;
    color: #0d6efd;
    font-size: 0.86rem;
    font-weight: 700;
    white-space: nowrap;
}

.dispatcher-vehicle-count-btn:hover,
.dispatcher-vehicle-count-btn:focus-visible {
    background: #f8fbff;
    border-color: #0b5ed7;
    color: #0b5ed7;
}

.dispatcher-vehicle-count-btn:focus-visible,
.transport-row-actions-toggle:focus-visible,
.transport-row-actions-item:focus-visible {
    outline: 3px solid rgba(13, 110, 253, 0.18);
    outline-offset: 2px;
}

.dispatcher-vehicle-count-btn.is-empty,
.dispatcher-vehicle-count-btn:disabled {
    min-width: 6.4rem;
    border-color: #d8e0eb;
    background: #f8fafc;
    color: #7b8798;
    cursor: not-allowed;
    opacity: 1;
}

.dispatcher-vehicle-popover,
.transport-row-actions-menu {
    position: fixed;
    z-index: 3060;
    background: #ffffff;
    border: 1px solid #dfe6f0;
    border-radius: 8px;
    box-shadow: 0 18px 44px rgba(15, 23, 42, 0.16);
}

.dispatcher-vehicle-popover[hidden],
.transport-row-actions-menu[hidden] {
    display: none !important;
}

.dispatcher-vehicle-popover {
    width: min(304px, calc(100vw - 24px));
    max-height: min(460px, calc(100vh - 24px));
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.dispatcher-vehicle-search {
    position: relative;
    padding: 0.72rem 0.78rem 0.52rem;
}

.dispatcher-vehicle-search i {
    position: absolute;
    left: 1.08rem;
    top: 50%;
    transform: translateY(-45%);
    color: #475569;
    font-size: 0.95rem;
    pointer-events: none;
}

.dispatcher-vehicle-search-input {
    width: 100%;
    height: 2.25rem;
    padding: 0 0.75rem 0 2.05rem;
    border: 1px solid #d8e0eb;
    border-radius: 6px;
    color: #0f172a;
    font-size: 0.84rem;
    outline: none;
}

.dispatcher-vehicle-search-input:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.14);
}

.dispatcher-vehicle-popover-list {
    flex: 1 1 auto;
    min-height: 0;
    margin: 0;
    padding: 0.28rem 0.9rem 0.62rem;
    list-style: none;
    overflow-y: auto;
}

.dispatcher-vehicle-popover-item {
    display: flex;
    align-items: baseline;
    gap: 0.32rem;
    padding: 0.26rem 0;
    color: #334155;
    font-size: 0.83rem;
    line-height: 1.32;
    white-space: nowrap;
}

.dispatcher-vehicle-popover-item strong {
    color: #0f172a;
    font-weight: 800;
}

.dispatcher-vehicle-separator {
    color: #64748b;
}

.dispatcher-vehicle-detail {
    color: #334155;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dispatcher-vehicle-popover-empty {
    padding: 1rem 0.9rem 1.15rem;
    color: #64748b;
    font-size: 0.84rem;
}

.dispatcher-vehicle-popover-total {
    flex: 0 0 auto;
    border-top: 1px solid #e5ebf4;
    padding: 0.62rem 0.9rem;
    color: #0d6efd;
    font-size: 0.84rem;
    font-weight: 800;
}

.transport-route-actions-cell {
    width: 3.8rem;
    min-width: 3.8rem;
}

.transport-row-actions-toggle {
    width: 2.35rem;
    height: 2.35rem;
    padding: 0;
    border: 1px solid #dbe3ee;
    color: #172033;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.transport-row-actions-toggle:hover,
.transport-row-actions-toggle:focus-visible,
.transport-row-actions-toggle.is-open {
    background: #f8fafc;
    border-color: #b7c5d8;
    color: #0d6efd;
}

.transport-row-actions-menu {
    min-width: 9.6rem;
    padding: 0.35rem;
}

.transport-row-actions-form {
    margin: 0;
}

.transport-row-actions-item {
    display: flex;
    align-items: center;
    width: 100%;
    min-height: 2.15rem;
    padding: 0.46rem 0.62rem;
    border: 0;
    border-radius: 5px;
    background: transparent;
    color: #172033;
    font-size: 0.86rem;
    font-weight: 650;
    line-height: 1.2;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
}

.transport-row-actions-item:hover,
.transport-row-actions-item:focus-visible {
    background: #f1f5f9;
    color: #0d6efd;
}

.transport-row-actions-danger {
    color: #dc3545;
}

.transport-row-actions-danger:hover,
.transport-row-actions-danger:focus-visible {
    background: #fff5f5;
    color: #b02a37;
}

/* --- V2: context bar, stepper, tab-uri, panouri --- */
.tcv2-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.tcv2-subtitle {
    color: #64748b;
    font-size: 0.92rem;
    max-width: 46rem;
}

.tcv2-context {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem 2rem;
    align-items: center;
    justify-content: space-between;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.9rem 1.1rem;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
    margin-bottom: 0.85rem;
}

.tcv2-kicker {
    display: block;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-size: 0.68rem;
    font-weight: 800;
    color: #64748b;
}

.tcv2-context-name-row {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex-wrap: wrap;
    margin-top: 0.15rem;
}

.tcv2-context-name {
    font-size: 1.05rem;
    color: #0f172a;
}

.tcv2-context-hint {
    color: #64748b;
    font-size: 0.8rem;
}

.tcv2-type-badge {
    background: #eef4ff;
    color: #1d4ed8;
    border: 1px solid #cbdcff;
    font-weight: 700;
}

.tcv2-steps {
    display: flex;
    gap: 0.4rem;
    list-style: none;
    margin: 0;
    padding: 0;
    flex-wrap: wrap;
}

.tcv2-step {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.45rem 0.8rem;
    border-radius: 10px;
    border: 1px dashed #dbe3ee;
    min-width: 12.2rem;
    background: #fbfdff;
}

.tcv2-step strong {
    display: block;
    font-size: 0.8rem;
    color: #0f172a;
    line-height: 1.2;
}

.tcv2-step small {
    display: block;
    color: #64748b;
    font-size: 0.72rem;
    line-height: 1.2;
}

.tcv2-step-index {
    width: 1.6rem;
    height: 1.6rem;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.8rem;
    background: #e2e8f0;
    color: #475569;
    flex: 0 0 auto;
}

.tcv2-step.is-done {
    border-style: solid;
    border-color: #bbe3c8;
    background: #f2fbf5;
}

.tcv2-step.is-done .tcv2-step-index {
    background: #198754;
    color: #ffffff;
}

.tcv2-step.is-current {
    border-style: solid;
    border-color: #9ec5fe;
    background: #f5f9ff;
}

.tcv2-step.is-current .tcv2-step-index {
    background: #0d6efd;
    color: #ffffff;
}

.tcv2-step.is-locked {
    opacity: 0.72;
}

.tcv2-step.is-na {
    opacity: 0.5;
}

.tcv2-tabs {
    display: flex;
    gap: 0.35rem;
    flex-wrap: wrap;
    border-bottom: 2px solid #e2e8f0;
    padding: 0 0.2rem;
}

.tcv2-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border: 0;
    background: transparent;
    padding: 0.62rem 0.95rem;
    border-radius: 10px 10px 0 0;
    font-weight: 700;
    font-size: 0.88rem;
    color: #475569;
    position: relative;
    cursor: pointer;
}

.tcv2-tab:hover {
    background: #f1f5f9;
    color: #0d6efd;
}

.tcv2-tab.is-active {
    color: #0d6efd;
    background: #ffffff;
}

.tcv2-tab.is-active::after {
    content: "";
    position: absolute;
    left: 0.4rem;
    right: 0.4rem;
    bottom: -2px;
    height: 2px;
    background: #0d6efd;
}

.tcv2-tab-step {
    width: 1.35rem;
    height: 1.35rem;
    border-radius: 50%;
    background: #e2e8f0;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.72rem;
    font-weight: 800;
}

.tcv2-tab.is-active .tcv2-tab-step {
    background: #0d6efd;
    color: #ffffff;
}

.tcv2-tab-count {
    background: #eef2f7;
    border-radius: 999px;
    padding: 0.05rem 0.5rem;
    font-size: 0.72rem;
    color: #334155;
}

.tcv2-tab-lock {
    color: #b45309;
    font-size: 0.8rem;
}

.tcv2-workspace {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-top: 0;
    border-radius: 0 0 12px 12px;
    padding: 1.15rem 1.25rem 1.35rem;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
}

.tcv2-panel[hidden] {
    display: none !important;
}

.tcv2-panel-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.tcv2-panel-hint {
    color: #64748b;
    font-size: 0.85rem;
    max-width: 52rem;
}

.tcv2-locked {
    display: flex;
    gap: 0.8rem;
    align-items: flex-start;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 10px;
    padding: 0.9rem 1rem;
    color: #92400e;
}

.tcv2-locked-icon {
    font-size: 1.1rem;
    line-height: 1;
}

.tcv2-locked p {
    color: #92400e;
    font-size: 0.88rem;
}

.tcv2-catalog-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
    gap: 1rem;
    margin-top: 1.1rem;
    padding-top: 1rem;
    border-top: 1px dashed #e2e8f0;
}

.tcv2-chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-top: 0.4rem;
}

.tcv2-list-section {
    margin-top: 1.25rem !important;
}

/* --- V2.1: master-detail, sidebar, modal, disclosure --- */
.tcv2-layout {
    display: grid;
    grid-template-columns: 300px minmax(0, 1fr);
    gap: 1rem;
    align-items: start;
}

@media (max-width: 1100px) {
    .tcv2-layout {
        grid-template-columns: 1fr;
    }
    .tcv2-side-list {
        max-height: 16rem;
    }
}

.tcv2-sidebar {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.85rem;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
    position: sticky;
    top: 0.75rem;
}

.tcv2-side-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.6rem;
}

.tcv2-side-search {
    margin-bottom: 0.6rem;
}

.tcv2-side-bulk {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.5rem;
    padding: 0.35rem 0.2rem 0.55rem;
    border-bottom: 1px solid #eef2f7;
    margin-bottom: 0.45rem;
}

.tcv2-side-selectall {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.78rem;
    color: #64748b;
    cursor: pointer;
    margin: 0;
}

.tcv2-side-list {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    max-height: 26rem;
    overflow-y: auto;
}

.tcv2-side-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.42rem 0.45rem;
    border-radius: 8px;
}

.tcv2-side-item:hover {
    background: #f5f8fc;
}

.tcv2-side-item.is-active {
    background: #eef4ff;
    box-shadow: inset 2px 0 0 #0d6efd;
}

.tcv2-side-link {
    flex: 1 1 auto;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    text-decoration: none;
    color: #0f172a;
}

.tcv2-side-name {
    display: flex;
    align-items: center;
    gap: 0.42rem;
    min-width: 0;
}

.tcv2-side-name strong {
    font-size: 0.88rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.tcv2-side-status {
    width: 0.55rem;
    height: 0.55rem;
    border-radius: 50%;
    flex: 0 0 auto;
}

.tcv2-side-status.is-on {
    background: #198754;
}

.tcv2-side-status.is-off {
    background: #cbd5e1;
}

.tcv2-side-badges {
    display: flex;
    gap: 0.25rem;
    flex-wrap: wrap;
}

.tcv2-side-badge {
    font-size: 0.62rem;
    font-weight: 800;
    color: #1d4ed8;
    background: #eef4ff;
    border: 1px solid #cbdcff;
    border-radius: 5px;
    padding: 0.05rem 0.3rem;
}

.tcv2-side-actions {
    display: flex;
    align-items: center;
    gap: 0.15rem;
    flex: 0 0 auto;
}

.tcv2-side-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.7rem;
    height: 1.7rem;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: #64748b;
    font-size: 0.85rem;
    text-decoration: none;
    cursor: pointer;
}

.tcv2-side-icon:hover {
    background: #eef2f7;
    color: #0d6efd;
}

.tcv2-side-icon-danger:hover {
    background: #fff5f5;
    color: #dc3545;
}

.tcv2-main {
    min-width: 0;
}

.tcv2-panel-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    flex: 0 0 auto;
}

.tcv2-form-disclosure[hidden] {
    display: none !important;
}

.tcv2-form-disclosure {
    border: 1px dashed #cbdcff;
    border-radius: 10px;
    padding: 0.35rem 0.85rem 0.85rem;
    background: #fbfdff;
    margin-top: 0.75rem;
}

.tcv2-details-table {
    margin-top: 1.25rem;
}

.tcv2-details-table > summary {
    cursor: pointer;
    color: #475569;
    font-size: 0.85rem;
    font-weight: 700;
    padding: 0.4rem 0.2rem;
}

.tcv2-details-table > summary:hover {
    color: #0d6efd;
}

.tcv2-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 4000;
    background: rgba(15, 23, 42, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.tcv2-modal-backdrop[hidden] {
    display: none !important;
}

.tcv2-modal {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
    width: min(430px, 100%);
    padding: 1.1rem 1.2rem 1.2rem;
}

.tcv2-modal-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.9rem;
}

.tcv2-modal-close {
    border: 0;
    background: transparent;
    font-size: 1.4rem;
    line-height: 1;
    color: #64748b;
    cursor: pointer;
}

.tcv2-modal-close:hover {
    color: #0f172a;
}

/* --- V2.2: card-uri tipuri transport, grupuri de campuri, context compact --- */
.tcv2-type-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(21rem, 1fr));
    gap: 0.75rem;
    align-items: start;
}

.tcv2-tile-link {
    display: inline-flex;
    align-items: center;
    gap: 0.1rem;
    margin-top: 0.5rem;
    border: 0;
    background: transparent;
    padding: 0;
    color: #0d6efd;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
}

.tcv2-tile-link:hover {
    text-decoration: underline;
}

.tcv2-type-tile {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #ffffff;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.tcv2-type-tile-head {
    border-radius: 11px 11px 0 0;
}

.tcv2-type-tile-body {
    border-radius: 0 0 11px 11px;
}

.tcv2-type-tile:has(> .tcv2-type-tile-head input:checked) {
    border-color: #9ec5fe;
    box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.08);
}

.tcv2-type-tile-head {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    padding: 0.75rem 0.9rem;
    cursor: pointer;
    margin: 0;
    width: 100%;
}

.tcv2-type-tile-head:hover {
    background: #f8fafc;
}

.tcv2-type-tile-head .form-check-input {
    margin-top: 0.2rem;
    flex: 0 0 auto;
}

.tcv2-type-tile-title {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    min-width: 0;
}

.tcv2-type-tile-title strong {
    font-size: 0.92rem;
    color: #0f172a;
}

.tcv2-type-tile-title small {
    color: #64748b;
    font-size: 0.76rem;
    line-height: 1.3;
}

.tcv2-type-tile-body {
    border-top: 1px dashed #e2e8f0;
    background: #fbfdff;
    padding: 0.85rem 0.9rem;
}

.tcv2-type-tile-body[hidden] {
    display: none !important;
}

.tcv2-type-tile-note {
    color: #475569;
    font-size: 0.82rem;
}

.tcv2-type-tile-note i {
    color: #0d6efd;
    margin-right: 0.2rem;
}

.tcv2-group-sep {
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-size: 0.68rem;
    font-weight: 800;
    color: #64748b;
    border-bottom: 1px solid #eef2f7;
    padding-bottom: 0.25rem;
    margin-top: 0.5rem;
}

.tcv2-vehicles-none {
    display: inline-block;
    min-width: 2rem;
    text-align: center;
    color: #94a3b8;
    font-weight: 700;
}

.tcv2-context {
    padding: 0.6rem 0.95rem;
    margin-bottom: 0.7rem;
}

.tcv2-context-name {
    font-size: 0.98rem;
}

.tcv2-step {
    min-width: 10rem;
    padding: 0.32rem 0.6rem;
}

.tcv2-step-index {
    width: 1.35rem;
    height: 1.35rem;
    font-size: 0.72rem;
}

.tcv2-step strong {
    font-size: 0.75rem;
}

.tcv2-step small {
    font-size: 0.68rem;
}

/* --- V2.3: manager catalog (locuri / zone) --- */
.tcv2-catalog-manager {
    margin-top: 1.1rem;
    padding-top: 1rem;
    border-top: 1px dashed #e2e8f0;
}

.tcv2-catalog-search {
    max-width: 22rem;
    margin-bottom: 0.75rem;
}

.tcv2-catalog-cols {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr));
    gap: 1rem;
}

.tcv2-catalog-list {
    list-style: none;
    margin: 0.45rem 0 0;
    padding: 0;
    max-height: 15rem;
    overflow-y: auto;
    border: 1px solid #eef2f7;
    border-radius: 10px;
    background: #ffffff;
}

.tcv2-catalog-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.6rem;
    padding: 0.42rem 0.65rem;
    border-bottom: 1px solid #f1f5f9;
}

.tcv2-catalog-item:last-child {
    border-bottom: 0;
}

.tcv2-catalog-item:hover {
    background: #f8fafc;
}

.tcv2-catalog-item:hover .tcv2-catalog-editicon {
    opacity: 1;
}

.tcv2-catalog-item.is-editing {
    background: #eef4ff;
    box-shadow: inset 2px 0 0 #0d6efd;
}

.tcv2-catalog-item[hidden] {
    display: none !important;
}

.tcv2-catalog-name {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    min-width: 0;
    color: #0f172a;
    font-size: 0.86rem;
    font-weight: 650;
    text-decoration: none;
}

.tcv2-catalog-name:hover {
    color: #0d6efd;
}

.tcv2-catalog-name i {
    color: #94a3b8;
    font-size: 0.8rem;
}

.tcv2-catalog-name span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.tcv2-catalog-meta {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex: 0 0 auto;
}

.tcv2-catalog-count {
    font-size: 0.72rem;
    font-weight: 700;
    color: #1d4ed8;
    background: #eef4ff;
    border-radius: 999px;
    padding: 0.08rem 0.5rem;
    white-space: nowrap;
}

.tcv2-catalog-count.is-zero {
    color: #94a3b8;
    background: #f1f5f9;
}

.tcv2-catalog-inactive {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #b45309;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 5px;
    padding: 0.05rem 0.35rem;
}

.tcv2-catalog-editicon {
    color: #94a3b8;
    font-size: 0.78rem;
    opacity: 0;
    transition: opacity 0.12s ease;
}

.tcv2-catalog-empty {
    padding: 0.5rem 0.2rem 0;
}

/* --- V2.4: tabel comparativ compact --- */
.tcv2-details-table .transport-rules-table {
    width: 100%;
}

.tcv2-details-table .transport-rules-table tbody td {
    white-space: normal;
}

.tcv2-details-table .transport-rules-table .col-beneficiar {
    min-width: 150px;
    max-width: none;
}

.tcv2-details-table .transport-rules-table .col-status {
    min-width: 90px;
}

.tcv2-details-table .transport-rules-table .col-actions {
    min-width: 64px;
}

.tcv2-tariff-cell {
    min-width: 12rem;
}

.tcv2-tariff-line {
    display: flex;
    gap: 0.5rem;
    align-items: baseline;
    padding: 0.12rem 0;
}

.tcv2-tariff-tag {
    flex: 0 0 auto;
    min-width: 6rem;
    text-align: center;
    font-size: 0.66rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #1d4ed8;
    background: #eef4ff;
    border: 1px solid #cbdcff;
    border-radius: 5px;
    padding: 0.08rem 0.4rem;
}

.tcv2-tariff-val {
    font-size: 0.82rem;
    color: #334155;
}

/* --- V2.6: randuri expandabile in tabelul comparativ --- */
.tcv2-ben-row {
    cursor: pointer;
}

.tcv2-tariff-summary-cell {
    white-space: nowrap;
}

.tcv2-expand-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border: 1px solid #dbe3ee;
    border-radius: 6px;
    background: #ffffff;
    padding: 0.28rem 0.65rem;
    color: #475569;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}

.tcv2-expand-btn:hover,
.tcv2-expand-btn:focus-visible,
.tcv2-expand-btn.is-open {
    color: #0d6efd;
    border-color: #9ec5fe;
    background: #f5f9ff;
}

.tcv2-expand-btn i {
    font-size: 0.75rem;
}

tr.tcv2-tariff-details[hidden] {
    display: none !important;
}

.tcv2-details-table .transport-rules-table tbody tr.tcv2-tariff-details td {
    background: #f8fafc;
    padding: 0.45rem 0.9rem 0.7rem;
}

.tcv2-details-table .transport-rules-table tbody tr.tcv2-tariff-details:hover td {
    background: #f8fafc;
}

.tcv2-tariff-details-inner {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    border-left: 3px solid #cbdcff;
    padding: 0.3rem 0.75rem;
    margin-left: 0.2rem;
}

/* --- V2.5: selector vehicule in stilul butonului "N vehicule" din tabele --- */
.tcv2-panel .vehicle-multiselect-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.45rem;
    width: auto;
    min-width: 12.5rem;
    height: 2.35rem;
    padding: 0 0.8rem;
    border: 1px solid #0d6efd;
    border-radius: 6px;
    background: #ffffff;
    color: #0d6efd;
    font-size: 0.86rem;
    font-weight: 700;
    white-space: nowrap;
}

.tcv2-panel .vehicle-multiselect-toggle:hover,
.tcv2-panel .vehicle-multiselect-toggle:focus-visible,
.tcv2-panel .vehicle-multiselect-toggle.show {
    background: #f8fbff;
    border-color: #0b5ed7;
    color: #0b5ed7;
}

.tcv2-panel .vehicle-multiselect-toggle.is-invalid {
    border-color: #dc3545;
    color: #dc3545;
}

.tcv2-panel .vehicle-multiselect-toggle .vehicle-multiselect-label {
    overflow: hidden;
    text-overflow: ellipsis;
}

.tcv2-panel .vehicle-multiselect-menu {
    min-width: 19rem;
    max-height: 21rem;
    overflow-y: auto;
}

.tcv2-vehicle-menu-search {
    position: sticky;
    top: -0.5rem;
    background: #ffffff;
    padding: 0.15rem 0.15rem 0.45rem;
    z-index: 1;
}

.vehicle-multiselect-option[hidden] {
    display: none !important;
}

/* --- V2.7: grupe de capacitate in dropdown-urile de vehicule --- */
.tcv2-vehicle-group[hidden] {
    display: none !important;
}

.tcv2-vehicle-group + .tcv2-vehicle-group {
    margin-top: 0.35rem;
}

.tcv2-vehicle-group-head {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.35rem 0.55rem;
    margin: 0;
    background: #f1f5f9;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.76rem;
    font-weight: 800;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.tcv2-vehicle-group-head:hover {
    background: #e8eef5;
}

.tcv2-vehicle-group-count {
    margin-left: auto;
    color: #64748b;
    font-size: 0.72rem;
    font-weight: 700;
}

.tcv2-vehicle-group .vehicle-multiselect-option {
    padding-left: 1.5rem !important;
}

.tcv2-vehicle-group-chevron {
    margin-left: 0.3rem;
    font-size: 0.72rem;
    color: #64748b;
    transition: transform 0.12s ease;
}

.tcv2-vehicle-group.is-collapsed .tcv2-vehicle-group-chevron {
    transform: rotate(-90deg);
}

.vehicle-multiselect-menu:not(.is-searching) .tcv2-vehicle-group.is-collapsed .vehicle-multiselect-option {
    display: none !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const transportTypeDropdown = document.querySelector('[data-role="transport-type-dropdown"]');

    const setCardControlsEnabled = function (card, enabled) {
        if (!(card instanceof HTMLElement)) {
            return;
        }

        card.querySelectorAll('input, select, textarea, button').forEach(function (control) {
            if (!(control instanceof HTMLElement)) {
                return;
            }

            if (typeof control.dataset.initialDisabled === 'undefined') {
                control.dataset.initialDisabled = control.hasAttribute('disabled') ? '1' : '0';
            }

            if (enabled) {
                control.toggleAttribute('disabled', control.dataset.initialDisabled === '1');
                return;
            }

            control.setAttribute('disabled', 'disabled');
        });
    };

    const updateTransportTypeCards = function () {
        if (!transportTypeDropdown) {
            return;
        }

        const checkboxes = transportTypeDropdown.querySelectorAll('input[type="checkbox"][name="tip_transporturi[]"]');
        const label = transportTypeDropdown.querySelector('.transport-multiselect-label');
        const defaultLabel = label ? (label.getAttribute('data-default-label') || '-- Selecteaza --') : '-- Selecteaza --';
        const selectedLabels = [];
        const selectedTypes = new Set();

        checkboxes.forEach(function (checkbox) {
            if (!(checkbox instanceof HTMLInputElement)) {
                return;
            }
            const typeKey = String(checkbox.value || '');
            if (checkbox.checked) {
                selectedTypes.add(typeKey);
            }
            const cards = document.querySelectorAll('[data-transport-card="' + typeKey + '"]');
            cards.forEach(function (card) {
                card.hidden = !checkbox.checked;
                setCardControlsEnabled(card, checkbox.checked);
            });
            if (!checkbox.checked) {
                return;
            }
            const text = checkbox.closest('label')?.querySelector('span')?.textContent?.trim();
            selectedLabels.push(text || typeKey);
        });

        const showCatalogCard = Array.from(checkboxes).some(function (checkbox) {
            if (!(checkbox instanceof HTMLInputElement) || !checkbox.checked) {
                return false;
            }
            const typeKey = String(checkbox.value || '');
            return typeKey === 'primar' || typeKey === 'distributie' || typeKey === 'primar_distributie';
        });
        const catalogCards = document.querySelectorAll('[data-transport-card="catalog"]');
        catalogCards.forEach(function (card) {
            card.hidden = !showCatalogCard;
            setCardControlsEnabled(card, showCatalogCard);
        });

        const showPrimaryDistributionCard = selectedTypes.has('primar_distributie');
        const primaryDistributionCards = document.querySelectorAll('[data-transport-card="primar_distributie"]');
        primaryDistributionCards.forEach(function (card) {
            card.hidden = !showPrimaryDistributionCard;
            setCardControlsEnabled(card, showPrimaryDistributionCard);
        });

        if (label) {
            label.textContent = selectedLabels.length > 0 ? selectedLabels.join(', ') : defaultLabel;
        }
    };

    if (transportTypeDropdown) {
        transportTypeDropdown.querySelectorAll('input[type="checkbox"][name="tip_transporturi[]"]').forEach(function (checkbox) {
            checkbox.addEventListener('change', updateTransportTypeCards);
        });
        updateTransportTypeCards();
    }

    const distributionTariffModeSelect = document.getElementById('config_distribution_only_route_tarif_mod');
    const distributionTonInput = document.getElementById('config_distribution_only_route_tarif_tona');
    const distributionKmInput = document.getElementById('config_distribution_only_route_cost_extra_km');
    const updateDistributionTariffInputs = function () {
        if (!(distributionTariffModeSelect instanceof HTMLSelectElement)) {
            return;
        }

        const mode = distributionTariffModeSelect.value || 'tona_km';
        const usesTon = mode === 'tona_km' || mode === 'tona';
        const usesKm = mode === 'tona_km' || mode === 'km';
        if (distributionTonInput instanceof HTMLInputElement) {
            distributionTonInput.required = usesTon;
            distributionTonInput.disabled = !usesTon;
        }
        if (distributionKmInput instanceof HTMLInputElement) {
            distributionKmInput.required = usesKm;
            distributionKmInput.disabled = !usesKm;
        }
    };

    if (distributionTariffModeSelect) {
        distributionTariffModeSelect.addEventListener('change', updateDistributionTariffInputs);
        updateDistributionTariffInputs();
    }

    const primaryManualKmToggle = document.getElementById('config_primary_route_km_agreati_manual');
    const primaryKmTariffInput = document.getElementById('config_primary_route_km_tarifare');
    const updatePrimaryKmInputMode = function () {
        if (!(primaryManualKmToggle instanceof HTMLInputElement) || !(primaryKmTariffInput instanceof HTMLInputElement)) {
            return;
        }

        const usesManualKm = primaryManualKmToggle.checked;
        if (usesManualKm) {
            primaryKmTariffInput.value = '';
        }
        primaryKmTariffInput.disabled = usesManualKm;
        primaryKmTariffInput.required = !usesManualKm;
        primaryKmTariffInput.dataset.initialDisabled = usesManualKm ? '1' : '0';
    };

    if (primaryManualKmToggle instanceof HTMLInputElement) {
        primaryManualKmToggle.addEventListener('change', updatePrimaryKmInputMode);
        updatePrimaryKmInputMode();
    }

    const primarTonField = document.getElementById('config_primar_pret_tona');
    const compresorTonField = document.getElementById('config_compresor_pret_tona');
    let syncingTonRate = false;
    const mirrorTonRate = function (sourceField, targetField) {
        if (!(sourceField instanceof HTMLInputElement) || !(targetField instanceof HTMLInputElement)) {
            return;
        }
        if (syncingTonRate) {
            return;
        }
        syncingTonRate = true;
        targetField.value = sourceField.value;
        syncingTonRate = false;
    };
    if (primarTonField instanceof HTMLInputElement && compresorTonField instanceof HTMLInputElement) {
        primarTonField.addEventListener('input', function () {
            mirrorTonRate(primarTonField, compresorTonField);
        });
        compresorTonField.addEventListener('input', function () {
            mirrorTonRate(compresorTonField, primarTonField);
        });
    }

    const bulkSelectAllEl = document.getElementById('bulk-beneficiary-select-all');
    const bulkDeleteBtnEl = document.getElementById('bulk-beneficiary-delete-btn');
    const bulkCheckboxEls = Array.from(document.querySelectorAll('.bulk-beneficiary-checkbox'));

    const refreshBulkDeleteState = function () {
        if (!bulkDeleteBtnEl) {
            return;
        }

        const selectedCount = bulkCheckboxEls.filter(function (checkboxEl) {
            return checkboxEl instanceof HTMLInputElement && checkboxEl.checked;
        }).length;

        bulkDeleteBtnEl.disabled = selectedCount === 0;

        if (!(bulkSelectAllEl instanceof HTMLInputElement)) {
            return;
        }

        if (bulkCheckboxEls.length === 0) {
            bulkSelectAllEl.checked = false;
            bulkSelectAllEl.indeterminate = false;
            bulkSelectAllEl.disabled = true;
            return;
        }

        bulkSelectAllEl.checked = selectedCount === bulkCheckboxEls.length;
        bulkSelectAllEl.indeterminate = selectedCount > 0 && selectedCount < bulkCheckboxEls.length;
    };

    if (bulkSelectAllEl instanceof HTMLInputElement) {
        bulkSelectAllEl.addEventListener('change', function () {
            bulkCheckboxEls.forEach(function (checkboxEl) {
                if (!(checkboxEl instanceof HTMLInputElement)) {
                    return;
                }
                checkboxEl.checked = bulkSelectAllEl.checked;
            });
            refreshBulkDeleteState();
        });
    }

    bulkCheckboxEls.forEach(function (checkboxEl) {
        checkboxEl.addEventListener('change', refreshBulkDeleteState);
    });
    refreshBulkDeleteState();

    const closestElement = function (target, selector) {
        return target instanceof Element ? target.closest(selector) : null;
    };

    let activeVehicleState = null;
    let activeActionsState = null;

    const positionFloatingLayer = function (triggerEl, layerEl, options) {
        if (!(triggerEl instanceof HTMLElement) || !(layerEl instanceof HTMLElement)) {
            return;
        }

        const config = options || {};
        const margin = 12;
        const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
        const preferredWidth = Number(config.width || 304);
        const preferredMaxHeight = Number(config.maxHeight || 460);
        const width = Math.min(Math.max(220, preferredWidth), Math.max(220, viewportWidth - margin * 2));
        const maxHeight = Math.min(preferredMaxHeight, Math.max(180, viewportHeight - margin * 2));

        layerEl.style.width = width + 'px';
        layerEl.style.maxHeight = maxHeight + 'px';

        const triggerRect = triggerEl.getBoundingClientRect();
        const layerRect = layerEl.getBoundingClientRect();
        const layerHeight = Math.min(layerRect.height || layerEl.scrollHeight || maxHeight, maxHeight);
        let left = triggerRect.left;

        if (left + width > viewportWidth - margin) {
            left = triggerRect.right - width;
        }
        left = Math.max(margin, Math.min(left, viewportWidth - margin - width));

        let top = triggerRect.bottom + 8;
        const topIfAbove = triggerRect.top - layerHeight - 8;
        if (top + layerHeight > viewportHeight - margin && topIfAbove >= margin) {
            top = topIfAbove;
        } else if (top + layerHeight > viewportHeight - margin) {
            top = Math.max(margin, viewportHeight - margin - layerHeight);
        }

        layerEl.style.left = Math.round(left) + 'px';
        layerEl.style.top = Math.round(top) + 'px';
    };

    const resetVehicleSearch = function (popoverEl) {
        if (!(popoverEl instanceof HTMLElement)) {
            return;
        }

        const searchInput = popoverEl.querySelector('[data-dispatcher-vehicle-search]');
        if (searchInput instanceof HTMLInputElement) {
            searchInput.value = '';
        }

        popoverEl.querySelectorAll('[data-dispatcher-vehicle-item]').forEach(function (itemEl) {
            if (itemEl instanceof HTMLElement) {
                itemEl.hidden = false;
            }
        });

        const emptyEl = popoverEl.querySelector('[data-dispatcher-vehicle-empty]');
        if (emptyEl instanceof HTMLElement) {
            emptyEl.hidden = true;
        }
    };

    const filterVehiclePopover = function (popoverEl) {
        if (!(popoverEl instanceof HTMLElement)) {
            return;
        }

        const searchInput = popoverEl.querySelector('[data-dispatcher-vehicle-search]');
        const query = searchInput instanceof HTMLInputElement
            ? searchInput.value.trim().toLocaleLowerCase('ro-RO')
            : '';
        let visibleCount = 0;

        popoverEl.querySelectorAll('[data-dispatcher-vehicle-item]').forEach(function (itemEl) {
            if (!(itemEl instanceof HTMLElement)) {
                return;
            }

            const searchText = String(itemEl.dataset.vehicleSearch || itemEl.textContent || '').toLocaleLowerCase('ro-RO');
            const isVisible = query === '' || searchText.includes(query);
            itemEl.hidden = !isVisible;
            if (isVisible) {
                visibleCount += 1;
            }
        });

        const emptyEl = popoverEl.querySelector('[data-dispatcher-vehicle-empty]');
        if (emptyEl instanceof HTMLElement) {
            emptyEl.hidden = visibleCount > 0;
        }
    };

    const closeVehiclePopover = function (restoreFocus) {
        if (activeVehicleState === null) {
            return;
        }

        const previousState = activeVehicleState;
        activeVehicleState = null;
        previousState.button.setAttribute('aria-expanded', 'false');
        previousState.button.classList.remove('is-open');
        const iconEl = previousState.button.querySelector('i');
        if (iconEl instanceof HTMLElement) {
            iconEl.classList.remove('bi-chevron-up');
            iconEl.classList.add('bi-chevron-down');
        }
        resetVehicleSearch(previousState.popover);
        previousState.popover.hidden = true;
        previousState.popover.style.left = '';
        previousState.popover.style.top = '';
        previousState.popover.style.visibility = '';

        if (restoreFocus) {
            previousState.button.focus({ preventScroll: true });
        }
    };

    const closeActionsMenu = function (restoreFocus) {
        if (activeActionsState === null) {
            return;
        }

        const previousState = activeActionsState;
        activeActionsState = null;
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

    const openVehiclePopover = function (buttonEl) {
        if (!(buttonEl instanceof HTMLButtonElement) || buttonEl.disabled) {
            return;
        }

        const popoverId = String(buttonEl.dataset.popoverId || '');
        const popoverEl = popoverId !== '' ? document.getElementById(popoverId) : null;
        if (!(popoverEl instanceof HTMLElement)) {
            return;
        }

        closeActionsMenu(false);
        closeVehiclePopover(false);

        activeVehicleState = {
            button: buttonEl,
            popover: popoverEl
        };

        buttonEl.setAttribute('aria-expanded', 'true');
        buttonEl.classList.add('is-open');
        const iconEl = buttonEl.querySelector('i');
        if (iconEl instanceof HTMLElement) {
            iconEl.classList.remove('bi-chevron-down');
            iconEl.classList.add('bi-chevron-up');
        }

        resetVehicleSearch(popoverEl);
        popoverEl.style.visibility = 'hidden';
        popoverEl.hidden = false;
        positionFloatingLayer(buttonEl, popoverEl, { width: 304, maxHeight: 460 });
        popoverEl.style.visibility = '';

        const searchInput = popoverEl.querySelector('[data-dispatcher-vehicle-search]');
        if (searchInput instanceof HTMLInputElement) {
            searchInput.focus({ preventScroll: true });
        }
    };

    const openActionsMenu = function (buttonEl, focusFirstItem) {
        if (!(buttonEl instanceof HTMLButtonElement) || buttonEl.disabled) {
            return;
        }

        const menuId = String(buttonEl.dataset.menuId || '');
        const menuEl = menuId !== '' ? document.getElementById(menuId) : null;
        if (!(menuEl instanceof HTMLElement)) {
            return;
        }

        closeVehiclePopover(false);
        closeActionsMenu(false);

        activeActionsState = {
            button: buttonEl,
            menu: menuEl
        };

        buttonEl.setAttribute('aria-expanded', 'true');
        buttonEl.classList.add('is-open');
        menuEl.style.visibility = 'hidden';
        menuEl.hidden = false;
        positionFloatingLayer(buttonEl, menuEl, { width: 156, maxHeight: 220 });
        menuEl.style.visibility = '';

        if (focusFirstItem) {
            const firstItem = menuEl.querySelector('[role="menuitem"]');
            if (firstItem instanceof HTMLElement) {
                firstItem.focus({ preventScroll: true });
            }
        }
    };

    const repositionOpenFloatingLayers = function () {
        if (activeVehicleState !== null) {
            positionFloatingLayer(activeVehicleState.button, activeVehicleState.popover, { width: 304, maxHeight: 460 });
        }
        if (activeActionsState !== null) {
            positionFloatingLayer(activeActionsState.button, activeActionsState.menu, { width: 156, maxHeight: 220 });
        }
    };

    document.addEventListener('click', function (event) {
        const vehicleButton = closestElement(event.target, '[data-dispatcher-vehicle-toggle]');
        if (vehicleButton instanceof HTMLButtonElement) {
            event.preventDefault();
            if (activeVehicleState !== null && activeVehicleState.button === vehicleButton) {
                closeVehiclePopover(false);
                return;
            }
            openVehiclePopover(vehicleButton);
            return;
        }

        const actionsButton = closestElement(event.target, '[data-transport-actions-toggle]');
        if (actionsButton instanceof HTMLButtonElement) {
            event.preventDefault();
            if (activeActionsState !== null && activeActionsState.button === actionsButton) {
                closeActionsMenu(false);
                return;
            }
            openActionsMenu(actionsButton, event.detail === 0);
            return;
        }

        if (closestElement(event.target, '[data-dispatcher-vehicle-popover]') === null) {
            closeVehiclePopover(false);
        }

        if (closestElement(event.target, '[data-transport-actions-menu]') === null) {
            closeActionsMenu(false);
        }
    });

    document.addEventListener('input', function (event) {
        const searchInput = closestElement(event.target, '[data-dispatcher-vehicle-search]');
        if (!(searchInput instanceof HTMLInputElement)) {
            return;
        }

        const popoverEl = searchInput.closest('[data-dispatcher-vehicle-popover]');
        filterVehiclePopover(popoverEl);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (activeVehicleState !== null || activeActionsState !== null) {
                event.preventDefault();
                const hadVehiclePopover = activeVehicleState !== null;
                closeVehiclePopover(hadVehiclePopover);
                closeActionsMenu(!hadVehiclePopover);
            }
            return;
        }

        const vehicleButton = closestElement(event.target, '[data-dispatcher-vehicle-toggle]');
        if (vehicleButton instanceof HTMLButtonElement && event.key === 'ArrowDown') {
            event.preventDefault();
            openVehiclePopover(vehicleButton);
            return;
        }

        const actionsButton = closestElement(event.target, '[data-transport-actions-toggle]');
        if (actionsButton instanceof HTMLButtonElement && event.key === 'ArrowDown') {
            event.preventDefault();
            openActionsMenu(actionsButton, true);
            return;
        }

        if (activeActionsState === null || !activeActionsState.menu.contains(event.target)) {
            return;
        }

        const menuItems = Array.from(activeActionsState.menu.querySelectorAll('[role="menuitem"]'))
            .filter(function (itemEl) {
                return itemEl instanceof HTMLElement && !itemEl.hasAttribute('disabled');
            });
        if (menuItems.length === 0) {
            return;
        }

        const currentIndex = Math.max(0, menuItems.indexOf(document.activeElement));
        let nextIndex = currentIndex;
        if (event.key === 'ArrowDown') {
            nextIndex = (currentIndex + 1) % menuItems.length;
        } else if (event.key === 'ArrowUp') {
            nextIndex = (currentIndex - 1 + menuItems.length) % menuItems.length;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = menuItems.length - 1;
        } else if (event.key === 'Tab') {
            closeActionsMenu(false);
            return;
        } else {
            return;
        }

        event.preventDefault();
        menuItems[nextIndex].focus({ preventScroll: true });
    });

    document.addEventListener('scroll', repositionOpenFloatingLayers, true);
    window.addEventListener('resize', repositionOpenFloatingLayers);

    if (transportTypeDropdown) {
        transportTypeDropdown.addEventListener('change', function (event) {
            const transportCheckbox = closestElement(event.target, 'input[type="checkbox"][name="tip_transporturi[]"]');
            if (transportCheckbox instanceof HTMLInputElement) {
                closeVehiclePopover(false);
                closeActionsMenu(false);
            }
        });
    }

    const initVehicleMultiselectDropdown = function (dropdownEl) {
        if (!dropdownEl) {
            return;
        }

        const labelEl = dropdownEl.querySelector('.vehicle-multiselect-label');
        const checkboxEls = dropdownEl.querySelectorAll('input[type="checkbox"]:not([data-vehicle-group-toggle])');
        const defaultLabel = labelEl?.dataset.defaultLabel || '-- Fara alocare --';
        const summaryMode = String(labelEl?.dataset.summaryMode || '').trim();
        const summarySingular = String(labelEl?.dataset.summarySingular || 'vehicul selectat').trim();
        const summaryPlural = String(labelEl?.dataset.summaryPlural || 'vehicule selectate').trim();
        const chipsTarget = String(labelEl?.dataset.chipsTarget || '').trim();
        const chipsContainer = chipsTarget !== '' ? document.querySelector(chipsTarget) : null;

        const refreshVehicleLabel = function () {
            if (!labelEl) {
                return;
            }
            const selectedLabels = [];
            const selectedPlates = [];
            checkboxEls.forEach(function (checkboxEl) {
                if (!(checkboxEl instanceof HTMLInputElement) || !checkboxEl.checked) {
                    return;
                }
                const text = checkboxEl.closest('label')?.querySelector('span')?.textContent?.trim();
                if (text) {
                    selectedLabels.push(text);
                }
                const rawPlate = checkboxEl.dataset.vehiclePlate?.trim();
                if (rawPlate) {
                    selectedPlates.push(rawPlate);
                    return;
                }
                if (text) {
                    const derivedPlate = text.split(' - ')[0]?.trim();
                    if (derivedPlate) {
                        selectedPlates.push(derivedPlate);
                    }
                }
            });

            if (selectedLabels.length === 0) {
                labelEl.textContent = defaultLabel;
                labelEl.removeAttribute('title');
                if (chipsContainer instanceof HTMLElement) {
                    chipsContainer.innerHTML = '';
                }
                return;
            }

            const joined = selectedLabels.join(', ');
            if (summaryMode === 'count') {
                labelEl.textContent = selectedLabels.length === 1
                    ? ('1 ' + summarySingular)
                    : (selectedLabels.length + ' ' + summaryPlural);
            } else {
                labelEl.textContent = joined;
            }
            labelEl.setAttribute('title', joined);

            if (chipsContainer instanceof HTMLElement) {
                chipsContainer.innerHTML = '';
                const uniquePlates = Array.from(new Set(selectedPlates));
                uniquePlates.slice(0, 6).forEach(function (plate) {
                    const chipEl = document.createElement('span');
                    chipEl.className = 'badge rounded-pill text-bg-light border';
                    chipEl.textContent = plate;
                    chipsContainer.appendChild(chipEl);
                });
                if (uniquePlates.length > 6) {
                    const moreEl = document.createElement('span');
                    moreEl.className = 'badge rounded-pill text-bg-light border';
                    moreEl.textContent = '+' + (uniquePlates.length - 6);
                    moreEl.title = uniquePlates.slice(6).join(', ');
                    chipsContainer.appendChild(moreEl);
                }
            }
        };

        checkboxEls.forEach(function (checkboxEl) {
            checkboxEl.addEventListener('change', refreshVehicleLabel);
        });

        refreshVehicleLabel();
    };

    document.querySelectorAll('.vehicle-multiselect-dropdown').forEach(initVehicleMultiselectDropdown);

    // --- V2: tab-uri workspace (beneficiar / catalog / rute per tip transport) ---
    const configTabButtons = Array.from(document.querySelectorAll('[data-config-tab]'));
    const configTabPanels = Array.from(document.querySelectorAll('[data-config-tab-panel]'));

    const activateConfigTab = function (tabKey) {
        let resolvedKey = String(tabKey || 'beneficiar');
        const targetButton = configTabButtons.find(function (buttonEl) {
            return buttonEl.dataset.configTab === resolvedKey && !buttonEl.hidden;
        });
        if (!targetButton) {
            resolvedKey = 'beneficiar';
        }
        configTabButtons.forEach(function (buttonEl) {
            const isActive = buttonEl.dataset.configTab === resolvedKey;
            buttonEl.classList.toggle('is-active', isActive);
            buttonEl.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        configTabPanels.forEach(function (panelEl) {
            panelEl.hidden = panelEl.dataset.configTabPanel !== resolvedKey;
        });
        closeVehiclePopover(false);
        closeActionsMenu(false);
    };

    configTabButtons.forEach(function (buttonEl) {
        buttonEl.addEventListener('click', function () {
            activateConfigTab(buttonEl.dataset.configTab);
        });
    });

    const configTabRequirementMet = function (requirement) {
        if (!requirement) {
            return true;
        }
        const checkedTypes = new Set();
        if (transportTypeDropdown) {
            transportTypeDropdown.querySelectorAll('input[type="checkbox"][name="tip_transporturi[]"]').forEach(function (checkboxEl) {
                if (checkboxEl instanceof HTMLInputElement && checkboxEl.checked) {
                    checkedTypes.add(String(checkboxEl.value || ''));
                }
            });
        }
        if (requirement === 'catalog') {
            return checkedTypes.has('primar') || checkedTypes.has('distributie') || checkedTypes.has('primar_distributie');
        }
        return checkedTypes.has(requirement);
    };

    const refreshConfigTabVisibility = function () {
        let activeKey = 'beneficiar';
        configTabButtons.forEach(function (buttonEl) {
            buttonEl.hidden = buttonEl.dataset.tabCreateLocked === '1'
                || !configTabRequirementMet(String(buttonEl.dataset.tabRequires || ''));
            if (buttonEl.classList.contains('is-active')) {
                activeKey = buttonEl.dataset.configTab;
            }
        });
        const activeButton = configTabButtons.find(function (buttonEl) {
            return buttonEl.dataset.configTab === activeKey;
        });
        if (!activeButton || activeButton.hidden) {
            activateConfigTab('beneficiar');
        }
    };

    if (transportTypeDropdown) {
        transportTypeDropdown.querySelectorAll('input[type="checkbox"][name="tip_transporturi[]"]').forEach(function (checkboxEl) {
            checkboxEl.addEventListener('change', refreshConfigTabVisibility);
        });
    }
    refreshConfigTabVisibility();

    // --- V2.4: link-uri "Deschide tabul X" din cardurile de tip transport ---
    document.addEventListener('click', function (event) {
        const openTabTrigger = closestElement(event.target, '[data-open-tab]');
        if (!(openTabTrigger instanceof HTMLElement)) {
            return;
        }
        activateConfigTab(String(openTabTrigger.dataset.openTab || 'beneficiar'));
        const tabsNav = document.querySelector('.tcv2-tabs');
        if (tabsNav instanceof HTMLElement) {
            tabsNav.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    // --- V2.8: "+ Nou" reactioneaza si cand esti deja pe formularul de creare ---
    const newBeneficiaryLink = document.querySelector('[data-new-beneficiary]');
    if (newBeneficiaryLink instanceof HTMLElement) {
        newBeneficiaryLink.addEventListener('click', function (event) {
            const currentParams = new URLSearchParams(window.location.search);
            const hasServerState = ['beneficiar_edit_id', 'beneficiar_view_id', 'loc_edit_id', 'zona_edit_id', 'route_distributie_edit_id', 'route_primar_distributie_edit_id', 'route_primar_edit_id', 'route_edit_id']
                .some(function (paramName) { return currentParams.has(paramName); });
            const nameInput = document.getElementById('config_beneficiar_nume');
            const nameHasContent = nameInput instanceof HTMLInputElement && nameInput.value.trim() !== '';
            if (hasServerState || nameHasContent) {
                return;
            }
            event.preventDefault();
            activateConfigTab('beneficiar');
            if (nameInput instanceof HTMLInputElement) {
                nameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                nameInput.focus({ preventScroll: true });
            }
        });
    }

    // --- V2.1: disclosure pentru formularele de rute ---
    document.querySelectorAll('[data-toggle-form]').forEach(function (toggleEl) {
        toggleEl.addEventListener('click', function () {
            const targetEl = document.getElementById(String(toggleEl.dataset.toggleForm || ''));
            if (!(targetEl instanceof HTMLElement)) {
                return;
            }
            targetEl.hidden = !targetEl.hidden;
            toggleEl.setAttribute('aria-expanded', targetEl.hidden ? 'false' : 'true');
            if (!targetEl.hidden) {
                const firstField = targetEl.querySelector('select, input:not([type="hidden"])');
                if (firstField instanceof HTMLElement) {
                    firstField.focus({ preventScroll: true });
                }
            }
        });
    });

    // --- V2.1: modal "Adauga in catalog" ---
    document.addEventListener('click', function (event) {
        const openTrigger = closestElement(event.target, '[data-modal-open]');
        if (openTrigger instanceof HTMLElement) {
            const modalEl = document.getElementById(String(openTrigger.dataset.modalOpen || ''));
            if (modalEl instanceof HTMLElement) {
                modalEl.hidden = false;
                const firstInput = modalEl.querySelector('input[name="loc_nume"]');
                if (firstInput instanceof HTMLInputElement) {
                    firstInput.focus({ preventScroll: true });
                }
            }
            return;
        }

        const closeTrigger = closestElement(event.target, '[data-modal-close]');
        if (closeTrigger instanceof HTMLElement) {
            const backdropEl = closeTrigger.closest('.tcv2-modal-backdrop');
            if (backdropEl instanceof HTMLElement) {
                backdropEl.hidden = true;
            }
            return;
        }

        if (event.target instanceof HTMLElement && event.target.classList.contains('tcv2-modal-backdrop')) {
            event.target.hidden = true;
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        document.querySelectorAll('.tcv2-modal-backdrop:not([hidden])').forEach(function (modalEl) {
            modalEl.hidden = true;
        });
    });

    // --- V2.6: randuri expandabile in tabelul comparativ ---
    const toggleTariffDetails = function (detailsRowId, toggleButton) {
        const detailsRow = document.getElementById(String(detailsRowId || ''));
        if (!(detailsRow instanceof HTMLElement)) {
            return;
        }
        detailsRow.hidden = !detailsRow.hidden;
        const expandButton = toggleButton instanceof HTMLElement
            ? toggleButton
            : document.querySelector('[data-expand-row="' + detailsRowId + '"]');
        if (expandButton instanceof HTMLElement) {
            expandButton.setAttribute('aria-expanded', detailsRow.hidden ? 'false' : 'true');
            expandButton.classList.toggle('is-open', !detailsRow.hidden);
            const chevronIcon = expandButton.querySelector('i');
            if (chevronIcon instanceof HTMLElement) {
                chevronIcon.classList.toggle('bi-chevron-down', detailsRow.hidden);
                chevronIcon.classList.toggle('bi-chevron-up', !detailsRow.hidden);
            }
        }
    };

    document.addEventListener('click', function (event) {
        const expandButton = closestElement(event.target, '[data-expand-row]');
        if (expandButton instanceof HTMLElement) {
            event.preventDefault();
            toggleTariffDetails(String(expandButton.dataset.expandRow || ''), expandButton);
            return;
        }

        const rowTrigger = closestElement(event.target, '[data-expand-trigger]');
        if (!(rowTrigger instanceof HTMLElement)) {
            return;
        }
        if (closestElement(event.target, 'a, button, form, input, .transport-row-actions')) {
            return;
        }
        toggleTariffDetails(String(rowTrigger.dataset.expandTrigger || ''), null);
    });

    // --- V2.5: cautare in dropdown-urile de selectie vehicule ---
    const filterVehicleMenu = function (searchInput) {
        const menuEl = searchInput.closest('.vehicle-multiselect-menu');
        if (!(menuEl instanceof HTMLElement)) {
            return;
        }
        const query = searchInput.value.trim().toLocaleLowerCase('ro-RO');
        const menuToggleId = String(menuEl.getAttribute('aria-labelledby') || '');
        const garageFilterEl = menuToggleId !== '' ? document.querySelector('[data-garage-filter-menu="' + menuToggleId + '"]') : null;
        const selectedGarages = garageFilterEl
            ? Array.from(garageFilterEl.querySelectorAll('input[type="checkbox"]:checked')).map(function (garageInput) { return String(garageInput.value); })
            : [];
        menuEl.classList.toggle('is-searching', query !== '' || selectedGarages.length > 0);
        let visibleCount = 0;
        menuEl.querySelectorAll('[data-vehicle-group]').forEach(function (groupEl) {
            if (!(groupEl instanceof HTMLElement)) {
                return;
            }
            const groupLabelMatches = query !== '' && String(groupEl.dataset.groupLabel || '').includes(query);
            let groupVisibleCount = 0;
            groupEl.querySelectorAll('.vehicle-multiselect-option').forEach(function (optionEl) {
                if (!(optionEl instanceof HTMLElement)) {
                    return;
                }
                const optionText = String(optionEl.textContent || '').toLocaleLowerCase('ro-RO');
                const garageAllowed = selectedGarages.length === 0 || selectedGarages.indexOf(String(optionEl.dataset.vehicleGarage || '')) !== -1;
                const isVisible = garageAllowed && (query === '' || groupLabelMatches || optionText.includes(query));
                optionEl.hidden = !isVisible;
                if (isVisible) {
                    groupVisibleCount += 1;
                }
            });
            groupEl.hidden = groupVisibleCount === 0;
            visibleCount += groupVisibleCount;
        });
        const emptyEl = menuEl.querySelector('.tcv2-vehicle-menu-empty');
        if (emptyEl instanceof HTMLElement) {
            emptyEl.hidden = visibleCount > 0;
        }
    };

    // --- V2.7: selectare in masa pe grupele de capacitate ---
    const refreshVehicleGroupToggle = function (groupEl) {
        if (!(groupEl instanceof HTMLElement)) {
            return;
        }
        const groupToggle = groupEl.querySelector('[data-vehicle-group-toggle]');
        if (!(groupToggle instanceof HTMLInputElement)) {
            return;
        }
        const optionInputs = Array.from(groupEl.querySelectorAll('.vehicle-multiselect-option input[type="checkbox"]'));
        const checkedCount = optionInputs.filter(function (optionInput) {
            return optionInput instanceof HTMLInputElement && optionInput.checked;
        }).length;
        groupToggle.checked = optionInputs.length > 0 && checkedCount === optionInputs.length;
        groupToggle.indeterminate = checkedCount > 0 && checkedCount < optionInputs.length;
    };

    document.addEventListener('change', function (event) {
        if (!(event.target instanceof HTMLInputElement)) {
            return;
        }
        if (event.target.hasAttribute('data-vehicle-group-toggle')) {
            const groupEl = event.target.closest('[data-vehicle-group]');
            if (!(groupEl instanceof HTMLElement)) {
                return;
            }
            const shouldCheck = event.target.checked;
            groupEl.querySelectorAll('.vehicle-multiselect-option input[type="checkbox"]').forEach(function (optionInput) {
                if (optionInput instanceof HTMLInputElement && !optionInput.closest('[hidden]') && optionInput.checked !== shouldCheck) {
                    optionInput.checked = shouldCheck;
                    optionInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            event.target.indeterminate = false;
            return;
        }
        if (event.target.closest('.vehicle-multiselect-option')) {
            refreshVehicleGroupToggle(event.target.closest('[data-vehicle-group]'));
        }
    });

    document.querySelectorAll('[data-vehicle-group]').forEach(refreshVehicleGroupToggle);

    document.addEventListener('click', function (event) {
        const groupHead = closestElement(event.target, '[data-vehicle-group-head]');
        if (!(groupHead instanceof HTMLElement)) {
            return;
        }
        if (event.target instanceof HTMLInputElement) {
            return;
        }
        const groupEl = groupHead.closest('[data-vehicle-group]');
        if (groupEl instanceof HTMLElement) {
            groupEl.classList.toggle('is-collapsed');
        }
    });

    document.addEventListener('input', function (event) {
        const menuSearchInput = closestElement(event.target, '[data-vehicle-menu-search]');
        if (menuSearchInput instanceof HTMLInputElement) {
            filterVehicleMenu(menuSearchInput);
        }
    });

    // --- V2.8: filtrul de garaj pentru lista de vehicule ---
    document.addEventListener('change', function (event) {
        const garageFilterRoot = closestElement(event.target, '[data-garage-filter-menu]');
        if (!(garageFilterRoot instanceof HTMLElement) || !(event.target instanceof HTMLInputElement)) {
            return;
        }
        const selectedGarageCount = garageFilterRoot.querySelectorAll('input[type="checkbox"]:checked').length;
        const garageFilterLabel = garageFilterRoot.querySelector('[data-role="garage-filter-label"]');
        if (garageFilterLabel instanceof HTMLElement) {
            garageFilterLabel.textContent = selectedGarageCount === 0
                ? 'Toate garajele'
                : (selectedGarageCount === 1 ? '1 garaj selectat' : selectedGarageCount + ' garaje selectate');
        }
        const targetToggleId = String(garageFilterRoot.dataset.garageFilterMenu || '');
        const targetSearchInput = document.querySelector('[aria-labelledby="' + targetToggleId + '"] [data-vehicle-menu-search]');
        if (targetSearchInput instanceof HTMLInputElement) {
            filterVehicleMenu(targetSearchInput);
        }
    });

    document.addEventListener('hidden.bs.dropdown', function (event) {
        const dropdownRoot = event.target instanceof Element ? event.target.closest('.vehicle-multiselect-dropdown') || event.target : null;
        if (!(dropdownRoot instanceof HTMLElement)) {
            return;
        }
        const menuSearchInput = dropdownRoot.querySelector('[data-vehicle-menu-search]');
        if (menuSearchInput instanceof HTMLInputElement && menuSearchInput.value !== '') {
            menuSearchInput.value = '';
            filterVehicleMenu(menuSearchInput);
        }
    });

    document.addEventListener('shown.bs.dropdown', function (event) {
        const dropdownRoot = event.target instanceof Element ? event.target.closest('.vehicle-multiselect-dropdown') || event.target : null;
        if (!(dropdownRoot instanceof HTMLElement)) {
            return;
        }
        const menuSearchInput = dropdownRoot.querySelector('[data-vehicle-menu-search]');
        if (menuSearchInput instanceof HTMLInputElement) {
            menuSearchInput.focus({ preventScroll: true });
        }
    });

    // --- V2.3: filtrare catalog locuri / zone ---
    const catalogSearchInput = document.querySelector('.tcv2-catalog-search');
    if (catalogSearchInput instanceof HTMLInputElement) {
        catalogSearchInput.addEventListener('input', function () {
            const query = catalogSearchInput.value.trim().toLocaleLowerCase('ro-RO');
            document.querySelectorAll('.tcv2-catalog-col').forEach(function (colEl) {
                let visibleCount = 0;
                colEl.querySelectorAll('[data-catalog-item]').forEach(function (itemEl) {
                    if (!(itemEl instanceof HTMLElement)) {
                        return;
                    }
                    const isVisible = query === '' || String(itemEl.dataset.search || '').includes(query);
                    itemEl.hidden = !isVisible;
                    if (isVisible) {
                        visibleCount += 1;
                    }
                });
                const emptyEl = colEl.querySelector('.tcv2-catalog-empty');
                if (emptyEl instanceof HTMLElement) {
                    emptyEl.hidden = visibleCount > 0;
                }
            });
        });
    }

    // --- V2.1: cautare in lista de beneficiari ---
    const sideSearchInput = document.querySelector('.tcv2-side-search');
    if (sideSearchInput instanceof HTMLInputElement) {
        sideSearchInput.addEventListener('input', function () {
            const query = sideSearchInput.value.trim().toLocaleLowerCase('ro-RO');
            document.querySelectorAll('[data-side-item]').forEach(function (itemEl) {
                if (!(itemEl instanceof HTMLElement)) {
                    return;
                }
                itemEl.hidden = query !== '' && !String(itemEl.dataset.search || '').includes(query);
            });
        });
    }
});
</script>
