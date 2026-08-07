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
        return '<button type="button" class="dispatcher-vehicle-count-btn is-empty" disabled aria-label="0 vehicule alocate">0 vehicule</button>';
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
$renderTransportRowActions = static function (string $menuKey, string $editHref, string $deleteAction, array $deleteFields, string $confirmMessage): string {
    $safeMenuKey = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $menuKey) ?: uniqid('actions_', false);
    $menuId = 'transport_row_actions_' . $safeMenuKey;
    $html = '<div class="transport-row-actions" data-transport-actions>';
    $html .= '<button type="button" class="transport-row-actions-toggle" data-transport-actions-toggle data-menu-id="' . e($menuId) . '" aria-haspopup="menu" aria-expanded="false" aria-controls="' . e($menuId) . '" aria-label="Actiuni rand" title="Actiuni">';
    $html .= '<i class="bi bi-three-dots-vertical" aria-hidden="true"></i>';
    $html .= '</button>';
    $html .= '<div class="transport-row-actions-menu" id="' . e($menuId) . '" data-transport-actions-menu role="menu" hidden>';
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
?>

<div class="transport-config-page">
    <div class="transport-config-page-header">
        <div class="transport-config-page-title-wrap">
            <h2 class="h4 mb-0">Configurare transport</h2>
            <p class="transport-config-page-subtitle mb-0">Gestioneaza regulile de tarifare pe beneficiar, catalogul de rute si setarile Primar/Distributie.</p>
        </div>
        <div class="transport-config-page-actions">
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Inapoi la curse</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm transport-config-unified-card transport-config-shell">
        <div class="card-header bg-white transport-config-shell-header">
            <h3 class="h5 mb-0">Regula tarifare beneficiar</h3>
            <span class="transport-config-shell-kicker">Administrare</span>
        </div>
        <div class="card-body transport-config-shell-body">
        <section class="transport-config-block transport-config-block-primary">
            <div class="transport-config-block-header">
                <h4 class="h6 mb-0">Date beneficiar si tipuri transport</h4>
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

                <div class="col-12 col-xl-4 transport-beneficiary-basics">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="config_beneficiar_nume">Beneficiar <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($beneficiaryFormErrors['nume']) ? 'is-invalid' : '' ?>" id="config_beneficiar_nume" name="nume" maxlength="150" value="<?= e((string) ($beneficiaryFormData['nume'] ?? '')) ?>" required>
                            <?php if (isset($beneficiaryFormErrors['nume'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['nume']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="config_beneficiar_tip_transporturi_toggle">Tipuri transport <span class="text-danger">*</span></label>
                            <div class="dropdown transport-multiselect-dropdown" data-role="transport-type-dropdown">
                                <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start transport-multiselect-toggle <?= isset($beneficiaryFormErrors['tip_transport']) ? 'is-invalid' : '' ?>" type="button" id="config_beneficiar_tip_transporturi_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                    <span class="transport-multiselect-label" data-default-label="-- Selecteaza --"><?= e($selectedTransportButtonLabel) ?></span>
                                </button>
                                <div class="dropdown-menu w-100 transport-multiselect-menu p-2" aria-labelledby="config_beneficiar_tip_transporturi_toggle">
                                    <?php foreach ($transportTypeOptions as $transportTypeValue => $transportTypeLabel): ?>
                                        <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 transport-multiselect-option">
                                            <input class="form-check-input m-0" type="checkbox" name="tip_transporturi[]" value="<?= e((string) $transportTypeValue) ?>" <?= in_array((string) $transportTypeValue, $selectedTransportTypes, true) ? 'checked' : '' ?>>
                                            <span><?= e((string) $transportTypeLabel) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-text">Bifeaza tipurile de transport pentru care vrei reguli active.</div>
                            <?php if (isset($beneficiaryFormErrors['tip_transport'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['tip_transport']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 transport-config-status-row">
                            <label class="form-label" for="config_beneficiar_activ">Status</label>
                            <div class="form-check form-switch transport-config-switch">
                                <input class="form-check-input" type="checkbox" role="switch" value="1" id="config_beneficiar_activ" name="activ" <?= (string) ($beneficiaryFormData['activ'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="config_beneficiar_activ">Activ</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-8 transport-beneficiary-pricing">
                    <div class="transport-type-cards" data-role="transport-type-cards">
                        <section class="transport-type-rule-card" data-transport-card="primar" <?= $isPrimarSelected ? '' : 'hidden' ?>>
                            <div class="transport-type-rule-card-header">
                                <h5 class="mb-1">Regula tarifare Primar</h5>
                                <p class="text-muted mb-0">Aceste tarife sunt folosite pentru cursele Primar ale beneficiarului selectat.</p>
                            </div>
                            <div class="row g-3 mt-1">
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
                        </section>

                        <section class="transport-type-rule-card" data-transport-card="compresor" <?= $isCompresorSelected ? '' : 'hidden' ?>>
                            <div class="transport-type-rule-card-header">
                                <h5 class="mb-1">Regula tarifare Compresor</h5>
                                <p class="text-muted mb-0">Completeaza tarifele dedicate operatiunilor de compresor.</p>
                            </div>
                            <div class="row g-3 mt-1">
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
                                                data-chips-target="#config_compresor_vehicle_chips"
                                            ><?= e($compresorVehicleButtonLabel) ?></span>
                                        </button>
                                        <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="config_compresor_vehicle_ids_toggle">
                                            <?php foreach (($vehicles ?? []) as $vehicle): ?>
                                                <?php
                                                $vehicleId = (int) ($vehicle['id'] ?? 0);
                                                if ($vehicleId <= 0) {
                                                    continue;
                                                }
                                                $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? ''));
                                                $vehiclePlate = trim((string) ($vehicle['nr_inmatriculare'] ?? ''));
                                                ?>
                                                <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option">
                                                    <input class="form-check-input m-0" type="checkbox" name="compresor_vehicle_ids[]" value="<?= e((string) $vehicleId) ?>" data-vehicle-plate="<?= e($vehiclePlate) ?>" <?= in_array((string) $vehicleId, $compresorSelectedVehicleIds, true) ? 'checked' : '' ?>>
                                                    <span><?= e($vehicleLabel) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 mt-2" id="config_compresor_vehicle_chips">
                                        <?php foreach ($compresorSelectedVehiclePlates as $selectedPlate): ?>
                                            <span class="badge rounded-pill text-bg-light border"><?= e($selectedPlate) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="form-text">Vehiculele selectate vor fi disponibile la adaugare cursa pentru tipul Compresor.</div>
                                    <?php if (isset($beneficiaryFormErrors['compresor_vehicle_ids'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['compresor_vehicle_ids']) ?></div><?php endif; ?>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2 justify-content-end transport-config-form-actions">
                    <button type="submit" class="btn btn-primary"><?= ($beneficiaryFormMode ?? 'create') === 'edit' ? 'Actualizeaza regula beneficiar' : 'Salveaza regula beneficiar' ?></button>
                </div>
            </form>
        </section>

        <section class="transport-config-block transport-config-block-secondary mt-3" data-transport-card="catalog" <?= $isCatalogSelected ? '' : 'hidden' ?>>
            <div class="transport-distribution-nested-header">
                <h4 class="h6 mb-0">Catalog locatii si zone pentru <?= $distributionBeneficiaryName !== '' ? e($distributionBeneficiaryName) : 'beneficiarul curent' ?></h4>
                <span class="text-muted small">Acest catalog este folosit pentru Primar si Distributie.</span>
            </div>

            <?php if (!$catalogConfigReady): ?>
                <div class="alert alert-warning mt-3 mb-0">Salveaza mai intai regula beneficiarului cu tipul Primar sau Distributie bifat.</div>
                <div class="alert alert-warning mt-3 mb-0" data-transport-card="distributie" <?= $isDistributieSelected ? '' : 'hidden' ?>>
                    Salveaza mai intai regula beneficiarului cu tipul Distributie bifat, apoi poti configura rutele Distributie.
                </div>
                <div class="alert alert-warning mt-3 mb-0" data-transport-card="primar_distributie" <?= $isPrimaryDistributionSelected ? '' : 'hidden' ?>>
                    Salveaza mai intai regula beneficiarului cu tipul Primar+Distributie bifat, apoi poti configura rutele Primar+Distributie.
                </div>
            <?php else: ?>
                <div class="transport-distribution-panel transport-distribution-catalog-panel mt-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h5 class="h6 mb-0">Catalog locatii si zone</h5>
                        <span class="text-muted small">Completeaza doar locul de incarcare si zona de descarcare.</span>
                    </div>
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

                <?php if ($isDistributieSelected): ?>
                    <?php if (!$canAddDistributionRoute): ?>
                        <div class="alert alert-warning mt-3 mb-0">Adauga cel putin un Loc incarcare si o Zona descarcare, apoi poti crea configuratii de ruta.</div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_distributie'])) ?>" novalidate class="mt-3 transport-route-form" data-transport-card="distributie">
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
                                <div class="col-12 col-xl-2">
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

                                <div class="col-12 col-xl-2">
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

                                <div class="col-12 col-md-6 col-xl-2">
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

                                <div class="col-12 col-md-6 col-xl-2">
                                    <label class="form-label" for="config_distribution_only_route_tarif_tona">Pret tona (RON) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control <?= isset($distributionOnlyRouteFormErrors['tarif_tona']) ? 'is-invalid' : '' ?>" id="config_distribution_only_route_tarif_tona" name="route_tarif_tona" min="0" step="0.01" value="<?= e((string) ($distributionOnlyRouteFormData['tarif_tona'] ?? '')) ?>">
                                    <?php if (isset($distributionOnlyRouteFormErrors['tarif_tona'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionOnlyRouteFormErrors['tarif_tona']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-6 col-xl-2">
                                    <label class="form-label" for="config_distribution_only_route_cost_extra_km">Pret km (RON) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control <?= isset($distributionOnlyRouteFormErrors['cost_extra_km']) ? 'is-invalid' : '' ?>" id="config_distribution_only_route_cost_extra_km" name="route_cost_extra_km" min="0" step="0.01" value="<?= e((string) ($distributionOnlyRouteFormData['cost_extra_km'] ?? '')) ?>">
                                    <?php if (isset($distributionOnlyRouteFormErrors['cost_extra_km'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionOnlyRouteFormErrors['cost_extra_km']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-xl-2">
                                    <label class="form-label" for="config_distribution_only_route_vehicle_ids_toggle">Vehicule <span class="text-danger">*</span></label>
                                    <div class="dropdown vehicle-multiselect-dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle <?= isset($distributionOnlyRouteFormErrors['vehicle_ids']) ? 'is-invalid' : '' ?>" type="button" id="config_distribution_only_route_vehicle_ids_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                            <span class="vehicle-multiselect-label" data-default-label="-- Selecteaza vehiculele --"><?= e($distributionOnlyRouteVehicleButtonLabel) ?></span>
                                        </button>
                                        <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="config_distribution_only_route_vehicle_ids_toggle">
                                            <?php foreach (($vehicles ?? []) as $vehicle): ?>
                                                <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                                                <?php $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? '')); ?>
                                                <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option">
                                                    <input class="form-check-input m-0" type="checkbox" name="route_vehicle_ids[]" value="<?= e((string) $vehicleId) ?>" <?= in_array((string) $vehicleId, $distributionOnlyRouteSelectedVehicleIds, true) ? 'checked' : '' ?>>
                                                    <span><?= e(trim($vehicleLabel)) ?></span>
                                                </label>
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

                    <div class="transport-distribution-panel transport-distribution-route-panel mt-3" data-transport-card="distributie">
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

                <?php if ($isPrimaryDistributionSelected): ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_distributie'])) ?>" novalidate class="mt-3 transport-route-form" data-transport-card="primar_distributie">
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
                                <div class="col-12 col-xl-2">
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

                                <div class="col-12 col-xl-2">
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

                                <div class="col-12 col-md-6 col-xl-2">
                                    <label class="form-label" for="config_primary_distribution_route_tarif_tona">Pret tona (RON) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control <?= isset($primaryDistributionRouteFormErrors['tarif_tona']) ? 'is-invalid' : '' ?>" id="config_primary_distribution_route_tarif_tona" name="route_tarif_tona" min="0" step="0.01" value="<?= e((string) ($primaryDistributionRouteFormData['tarif_tona'] ?? '')) ?>" required>
                                    <?php if (isset($primaryDistributionRouteFormErrors['tarif_tona'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['tarif_tona']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-6 col-xl-2">
                                    <label class="form-label" for="config_primary_distribution_route_cost_extra_km">Pret km (RON) <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control <?= isset($primaryDistributionRouteFormErrors['cost_extra_km']) ? 'is-invalid' : '' ?>" id="config_primary_distribution_route_cost_extra_km" name="route_cost_extra_km" min="0" step="0.01" value="<?= e((string) ($primaryDistributionRouteFormData['cost_extra_km'] ?? '')) ?>" required>
                                    <?php if (isset($primaryDistributionRouteFormErrors['cost_extra_km'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['cost_extra_km']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-6 col-xl-2">
                                    <label class="form-label" for="config_primary_distribution_route_km_tarifare">Km agreati <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control <?= isset($primaryDistributionRouteFormErrors['km_tarifare']) ? 'is-invalid' : '' ?>" id="config_primary_distribution_route_km_tarifare" name="route_km_tarifare" min="1" step="1" value="<?= e((string) ($primaryDistributionRouteFormData['km_tarifare'] ?? '')) ?>" required>
                                    <?php if (isset($primaryDistributionRouteFormErrors['km_tarifare'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['km_tarifare']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-md-6 col-xl-2">
                                    <label class="form-label" for="config_primary_distribution_route_cost_cursa">Cost cursa (RON)</label>
                                    <input type="number" class="form-control <?= isset($primaryDistributionRouteFormErrors['cost_cursa']) ? 'is-invalid' : '' ?>" id="config_primary_distribution_route_cost_cursa" name="route_cost_cursa" min="0" step="0.01" value="<?= e((string) ($primaryDistributionRouteFormData['cost_cursa'] ?? '')) ?>">
                                    <?php if (isset($primaryDistributionRouteFormErrors['cost_cursa'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['cost_cursa']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-xl-2">
                                    <label class="form-label" for="config_primary_distribution_route_vehicle_ids_toggle">Vehicule <span class="text-danger">*</span></label>
                                    <div class="dropdown vehicle-multiselect-dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle <?= isset($primaryDistributionRouteFormErrors['vehicle_ids']) ? 'is-invalid' : '' ?>" type="button" id="config_primary_distribution_route_vehicle_ids_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                            <span class="vehicle-multiselect-label" data-default-label="-- Selecteaza vehiculele --"><?= e($primaryDistributionRouteVehicleButtonLabel) ?></span>
                                        </button>
                                        <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="config_primary_distribution_route_vehicle_ids_toggle">
                                            <?php foreach (($vehicles ?? []) as $vehicle): ?>
                                                <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                                                <?php $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? '')); ?>
                                                <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option">
                                                    <input class="form-check-input m-0" type="checkbox" name="route_vehicle_ids[]" value="<?= e((string) $vehicleId) ?>" <?= in_array((string) $vehicleId, $primaryDistributionRouteSelectedVehicleIds, true) ? 'checked' : '' ?>>
                                                    <span><?= e(trim($vehicleLabel)) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php if (isset($primaryDistributionRouteFormErrors['vehicle_ids'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryDistributionRouteFormErrors['vehicle_ids']) ?></div><?php endif; ?>
                                </div>

                                <div class="col-12 col-xl-2">
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

                    <div class="transport-distribution-panel transport-distribution-route-panel mt-3" data-transport-card="primar_distributie">
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
            <?php endif; ?>
        </section>

        <section class="transport-config-block transport-config-block-secondary mt-3" data-transport-card="primar" <?= $isPrimarSelected ? '' : 'hidden' ?>>
            <div class="transport-distribution-nested-header">
                <h4 class="h6 mb-0">Setari primare pentru <?= $distributionBeneficiaryName !== '' ? e($distributionBeneficiaryName) : 'beneficiarul curent' ?></h4>
                <span class="text-muted small">Configureaza perechile Loc incarcare ↔ Zona descarcare si Km tarifare pentru Primar km / Primar tone.</span>
            </div>

            <?php if (!$primaryConfigReady): ?>
                <div class="alert alert-warning mt-3 mb-0">Salveaza mai intai regula beneficiarului cu tipul Primar km bifat.</div>
            <?php else: ?>
                <?php if (!$canAddPrimaryRoute): ?>
                    <div class="alert alert-warning mt-3 mb-0">Adauga cel putin un Loc incarcare si o Zona descarcare in catalog, apoi poti crea rute Primar.</div>
                <?php endif; ?>

                <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_primar_ruta'])) ?>" novalidate class="mt-2 transport-route-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="beneficiar_id" value="<?= e((string) $distributionBeneficiaryId) ?>">
                    <input type="hidden" name="route_primar_id" value="<?= e((string) ($primaryRouteFormData['route_id'] ?? '')) ?>">

                    <div class="transport-distribution-panel transport-distribution-route-panel">
                        <?php if ($isPrimaryRouteEditMode): ?>
                            <div class="alert alert-info py-2 mb-3">Editezi o ruta Primar existenta. Poti modifica perechea Loc ↔ Zona si Km tarifare.</div>
                        <?php endif; ?>

                        <div class="row g-3 align-items-end transport-distribution-route-grid">
                            <div class="col-12 col-xl-2">
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

                            <div class="col-12 col-xl-2">
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

                            <div class="col-12 col-md-6 col-xl-2">
                                <label class="form-label" for="config_primary_route_km_tarifare">Km tarifare <span class="text-danger">*</span></label>
                                <input type="number" class="form-control <?= isset($primaryRouteFormErrors['km_tarifare']) ? 'is-invalid' : '' ?>" id="config_primary_route_km_tarifare" name="route_primar_km_tarifare" min="1" step="1" value="<?= e((string) ($primaryRouteFormData['km_tarifare'] ?? '')) ?>" <?= (string) ($primaryRouteFormData['km_agreati_manual'] ?? '0') === '1' ? 'disabled' : 'required' ?>>
                                <?php if (isset($primaryRouteFormErrors['km_tarifare'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryRouteFormErrors['km_tarifare']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-md-6 col-xl-2">
                                <label class="form-label" for="config_primary_route_cost_cursa">Cost cursa (RON)</label>
                                <input type="number" class="form-control <?= isset($primaryRouteFormErrors['cost_cursa']) ? 'is-invalid' : '' ?>" id="config_primary_route_cost_cursa" name="route_primar_cost_cursa" min="0" step="0.01" value="<?= e((string) ($primaryRouteFormData['cost_cursa'] ?? '')) ?>">
                                <?php if (isset($primaryRouteFormErrors['cost_cursa'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryRouteFormErrors['cost_cursa']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-xl-3">
                                <label class="form-label" for="config_primary_route_vehicle_ids_toggle">Vehicule <span class="text-danger">*</span></label>
                                <div class="dropdown vehicle-multiselect-dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle <?= isset($primaryRouteFormErrors['vehicle_ids']) ? 'is-invalid' : '' ?>" type="button" id="config_primary_route_vehicle_ids_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                        <span class="vehicle-multiselect-label" data-default-label="-- Selecteaza vehiculele --"><?= e($primaryRouteVehicleButtonLabel) ?></span>
                                    </button>
                                    <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="config_primary_route_vehicle_ids_toggle">
                                        <?php foreach (($vehicles ?? []) as $vehicle): ?>
                                            <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                                            <?php $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? '')); ?>
                                            <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option">
                                                <input class="form-check-input m-0" type="checkbox" name="route_primar_vehicle_ids[]" value="<?= e((string) $vehicleId) ?>" <?= in_array((string) $vehicleId, $primaryRouteSelectedVehicleIds, true) ? 'checked' : '' ?>>
                                                <span><?= e(trim($vehicleLabel)) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php if (isset($primaryRouteFormErrors['vehicle_ids'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryRouteFormErrors['vehicle_ids']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-md-6 col-xl-2">
                                <label class="form-label" for="config_primary_route_km_agreati_manual">Km agreati</label>
                                <div class="form-check form-switch transport-config-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="config_primary_route_km_agreati_manual" name="route_primar_km_agreati_manual" value="1" <?= (string) ($primaryRouteFormData['km_agreati_manual'] ?? '0') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="config_primary_route_km_agreati_manual">Introducere manuala in cursa</label>
                                </div>
                            </div>

                            <div class="col-12 col-md-6 col-xl-2">
                                <label class="form-label d-block">Aplicare Cost Cursa</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="config_primary_route_aplica_cost_cursa" name="route_primar_aplica_cost_cursa" value="1" <?= (string) ($primaryRouteFormData['aplica_cost_cursa'] ?? '0') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="config_primary_route_aplica_cost_cursa">Aplica doar pe aceasta ruta</label>
                                </div>
                            </div>

                            <div class="col-12 col-md-6 col-xl-1">
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
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="transport-config-block transport-config-block-secondary mt-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2 transport-rules-header">
                <h4 class="h6 mb-0">Reguli beneficiar configurate</h4>
                <div class="d-flex flex-wrap align-items-center gap-2">
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
                    <span class="text-muted small"><?= e((string) count($beneficiaries ?? [])) ?> reguli disponibile</span>
                </div>
            </div>
            <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_delete_beneficiari'])) ?>" id="bulk-beneficiary-delete-form" class="d-none">
                <?= csrf_field() ?>
            </form>
            <div class="table-responsive transport-rules-table-wrap transport-data-table-panel">
                <table class="table table-sm align-middle mb-0 transport-config-table transport-rules-table">
                    <thead>
                    <tr>
                        <th class="text-center">
                            <input type="checkbox" class="form-check-input" id="bulk-beneficiary-select-all" aria-label="Selecteaza toate regulile">
                        </th>
                        <th class="col-id text-start">ID</th>
                        <th class="col-beneficiar text-start">Beneficiar</th>
                        <th class="col-tip text-start">Tip transport</th>
                        <th class="col-money text-end">Primar km</th>
                        <th class="col-money text-end">Primar tona</th>
                        <th class="col-money text-end">Distrib km</th>
                        <th class="col-money text-end">Distrib tona</th>
                        <th class="col-money text-end">Ora aspirare</th>
                        <th class="col-money text-end">Km dislocare</th>
                        <th class="col-money text-end">Tona livrata</th>
                        <th class="col-status text-center">Status</th>
                        <th class="col-actions text-end">Actiuni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (($beneficiaries ?? []) === []): ?>
                        <tr><td colspan="13" class="text-center text-muted py-3">Nu exista reguli salvate.</td></tr>
                    <?php else: ?>
                        <?php foreach (($beneficiaries ?? []) as $beneficiary): ?>
                            <?php
                            $beneficiaryId = (int) ($beneficiary['id'] ?? 0);
                            $transportModes = [];
                            if (!empty($beneficiary['suporta_primar'])) { $transportModes[] = 'Primar'; }
                            if (!empty($beneficiary['suporta_distributie'])) { $transportModes[] = 'Distributie'; }
                            if (!empty($beneficiary['suporta_primar_distributie'])) { $transportModes[] = 'Primar+Distributie'; }
                            if (!empty($beneficiary['suporta_compresor'])) { $transportModes[] = 'Compresor'; }
                            if ($transportModes === []) { $transportModes[] = '-'; }
                            $beneficiaryName = (string) ($beneficiary['nume'] ?? '-');
                            ?>
                            <tr>
                                <td class="text-center">
                                    <input
                                        type="checkbox"
                                        class="form-check-input bulk-beneficiary-checkbox"
                                        name="ids[]"
                                        value="<?= e((string) $beneficiaryId) ?>"
                                        form="bulk-beneficiary-delete-form"
                                        aria-label="Selecteaza beneficiarul <?= e($beneficiaryName) ?>"
                                    >
                                </td>
                                <td class="col-id text-start"><?= e((string) $beneficiaryId) ?></td>
                                <td class="col-beneficiar text-start" title="<?= e($beneficiaryName) ?>"><span class="beneficiary-ellipsis"><?= e($beneficiaryName) ?></span></td>
                                <td class="col-tip text-start"><?= e(implode(' + ', $transportModes)) ?></td>
                                <td class="col-money text-end"><?= e(format_number_ro((float) ($beneficiary['pret_km'] ?? 0), 2)) ?> lei</td>
                                <td class="col-money text-end"><?= e(format_number_ro((float) ($beneficiary['pret_tona'] ?? 0), 2)) ?> lei</td>
                                <td class="col-money text-end"><?= e(format_number_ro((float) ($beneficiary['pret_distributie_km'] ?? 0), 2)) ?> lei</td>
                                <td class="col-money text-end"><?= e(format_number_ro((float) ($beneficiary['pret_distributie_tona'] ?? 0), 2)) ?> lei</td>
                                <td class="col-money text-end"><?= e(format_number_ro((float) ($beneficiary['pret_ora_aspirare'] ?? 0), 2)) ?> lei</td>
                                <td class="col-money text-end"><?= e(format_number_ro((float) ($beneficiary['pret_km_dislocare'] ?? 0), 2)) ?> lei</td>
                                <td class="col-money text-end"><?= e(format_number_ro((float) ($beneficiary['pret_tona_livrata'] ?? 0), 2)) ?> lei</td>
                                <td class="col-status text-center"><?= !empty($beneficiary['activ']) ? '<span class="badge transport-status-badge transport-status-active">Activ</span>' : '<span class="badge transport-status-badge transport-status-inactive">Inactiv</span>' ?></td>
                                <td class="col-actions text-end">
                                    <div class="d-inline-flex gap-1 flex-nowrap justify-content-end transport-actions-wrap">
                                        <a class="btn btn-sm btn-outline-secondary transport-action-btn transport-action-neutral" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_view_id' => $beneficiaryId])) ?>">Detalii</a>
                                        <a class="btn btn-sm btn-outline-primary transport-action-btn transport-action-edit" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $beneficiaryId])) ?>">Editeaza</a>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_delete_beneficiar'])) ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $beneficiaryId) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger transport-action-btn transport-action-delete" data-confirm="Sigur doresti sa stergi acest beneficiar?">Sterge</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
</div>

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
        const checkboxEls = dropdownEl.querySelectorAll('input[type="checkbox"]');
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
                uniquePlates.forEach(function (plate) {
                    const chipEl = document.createElement('span');
                    chipEl.className = 'badge rounded-pill text-bg-light border';
                    chipEl.textContent = plate;
                    chipsContainer.appendChild(chipEl);
                });
            }
        };

        checkboxEls.forEach(function (checkboxEl) {
            checkboxEl.addEventListener('change', refreshVehicleLabel);
        });

        refreshVehicleLabel();
    };

    document.querySelectorAll('.vehicle-multiselect-dropdown').forEach(initVehicleMultiselectDropdown);
});
</script>
