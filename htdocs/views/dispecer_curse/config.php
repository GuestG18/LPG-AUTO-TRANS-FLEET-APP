<?php
$transportTypeOptions = is_array($transportTypeOptions ?? null) ? $transportTypeOptions : [
    'primar' => 'Primar km',
    'distributie' => 'Distributie',
    'compresor' => 'Compresor',
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
$isCompresorSelected = in_array('compresor', $selectedTransportTypes, true);
$isCatalogSelected = $isPrimarSelected || $isDistributieSelected;

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

$distributionRouteFormData = is_array($distributionRouteFormData ?? null) ? $distributionRouteFormData : [];
$distributionRouteFormErrors = is_array($distributionRouteFormErrors ?? null) ? $distributionRouteFormErrors : [];
$distributionRouteFormMode = trim((string) ($distributionRouteFormMode ?? '')) !== ''
    ? (string) $distributionRouteFormMode
    : (trim((string) ($distributionRouteFormData['route_id'] ?? '')) !== '' ? 'edit' : 'create');
$isRouteEditMode = $distributionRouteFormMode === 'edit';
$routeSelectedVehicleIds = array_map('strval', (array) ($distributionRouteFormData['vehicle_ids'] ?? []));
$vehicleLabelById = [];
foreach (($vehicles ?? []) as $vehicle) {
    $vehicleId = (int) ($vehicle['id'] ?? 0);
    if ($vehicleId <= 0) {
        continue;
    }

    $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? ''));
    $vehicleLabelById[$vehicleId] = trim($vehicleLabel);
}
$routeSelectedVehicleLabels = [];
foreach ($routeSelectedVehicleIds as $routeSelectedVehicleId) {
    $vehicleId = (int) $routeSelectedVehicleId;
    if ($vehicleId <= 0 || !isset($vehicleLabelById[$vehicleId])) {
        continue;
    }
    $routeSelectedVehicleLabels[] = $vehicleLabelById[$vehicleId];
}
$routeVehicleButtonLabel = $routeSelectedVehicleLabels !== [] ? implode(', ', $routeSelectedVehicleLabels) : '-- Selecteaza vehiculele --';
$compresorSelectedVehicleIds = array_map('strval', (array) ($beneficiaryFormData['compresor_vehicle_ids'] ?? []));
$compresorSelectedVehicleLabels = [];
foreach ($compresorSelectedVehicleIds as $compresorSelectedVehicleId) {
    $vehicleId = (int) $compresorSelectedVehicleId;
    if ($vehicleId <= 0 || !isset($vehicleLabelById[$vehicleId])) {
        continue;
    }
    $compresorSelectedVehicleLabels[] = $vehicleLabelById[$vehicleId];
}
$compresorVehicleButtonLabel = $compresorSelectedVehicleLabels !== [] ? implode(', ', $compresorSelectedVehicleLabels) : '-- Selecteaza vehiculele --';
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

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h4 mb-0">Configurare transport</h2>
    <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Inapoi la curse</a>
</div>

<div class="card border-0 shadow-sm transport-config-unified-card">
    <div class="card-header bg-white">
        <h3 class="h5 mb-0">Regula tarifare beneficiar</h3>
    </div>
    <div class="card-body">
        <div class="alert alert-info transport-config-note mb-4">
            Configureaza tarifele pe beneficiar si tip de transport. Pentru Distributie, locurile si zonele sunt salvate strict pentru beneficiarul curent.
        </div>

        <section class="transport-config-block">
            <div class="transport-config-block-header">
                <h4 class="h6 mb-0">Date beneficiar si tipuri transport</h4>
                <?php if (($beneficiaryFormMode ?? 'create') === 'edit'): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config'])) ?>">Reseteaza formular</a>
                <?php endif; ?>
            </div>

            <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_beneficiar'])) ?>" class="row g-3" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) ($beneficiaryFormData['id'] ?? '')) ?>">
                <input type="hidden" name="pret_tarifare" value="<?= e((string) ($beneficiaryFormData['pret_tarifare'] ?? '0')) ?>">

                <?php if (isset($beneficiaryFormErrors['id'])): ?>
                    <div class="col-12">
                        <div class="alert alert-danger py-2 mb-0"><?= e((string) $beneficiaryFormErrors['id']) ?></div>
                    </div>
                <?php endif; ?>

                <div class="col-12 col-xl-4">
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

                        <div class="col-12">
                            <label class="form-label" for="config_beneficiar_activ">Status</label>
                            <div class="form-check form-switch transport-config-switch">
                                <input class="form-check-input" type="checkbox" role="switch" value="1" id="config_beneficiar_activ" name="activ" <?= (string) ($beneficiaryFormData['activ'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="config_beneficiar_activ">Activ</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-8">
                    <div class="transport-type-cards" data-role="transport-type-cards">
                        <section class="transport-type-rule-card" data-transport-card="primar" <?= $isPrimarSelected ? '' : 'hidden' ?>>
                            <div class="transport-type-rule-card-header">
                                <h5 class="mb-1">Primar tariff rule</h5>
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

                        <section class="transport-type-rule-card" data-transport-card="distributie" <?= $isDistributieSelected ? '' : 'hidden' ?>>
                            <div class="transport-type-rule-card-header">
                                <h5 class="mb-1">Distributie tariff rule</h5>
                                <p class="text-muted mb-0">Configurezi tarifele de baza pentru distributie. Setarile de Loc si Zona se fac mai jos, pe acelasi beneficiar.</p>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="config_distributie_pret_tona">Pret/tona</label>
                                    <input type="number" class="form-control <?= isset($beneficiaryFormErrors['pret_distributie_tona']) ? 'is-invalid' : '' ?>" id="config_distributie_pret_tona" name="pret_distributie_tona" min="0" step="0.01" value="<?= e((string) ($beneficiaryFormData['pret_distributie_tona'] ?? '')) ?>">
                                    <?php if (isset($beneficiaryFormErrors['pret_distributie_tona'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['pret_distributie_tona']) ?></div><?php endif; ?>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label" for="config_distributie_pret_km">Pret/km (optional)</label>
                                    <input type="number" class="form-control <?= isset($beneficiaryFormErrors['pret_distributie_km']) ? 'is-invalid' : '' ?>" id="config_distributie_pret_km" name="pret_distributie_km" min="0" step="0.01" value="<?= e((string) ($beneficiaryFormData['pret_distributie_km'] ?? '')) ?>">
                                    <?php if (isset($beneficiaryFormErrors['pret_distributie_km'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['pret_distributie_km']) ?></div><?php endif; ?>
                                </div>
                            </div>
                        </section>

                        <section class="transport-type-rule-card" data-transport-card="compresor" <?= $isCompresorSelected ? '' : 'hidden' ?>>
                            <div class="transport-type-rule-card-header">
                                <h5 class="mb-1">Compresor tariff rule</h5>
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
                                            <span class="vehicle-multiselect-label" data-default-label="-- Selecteaza vehiculele --"><?= e($compresorVehicleButtonLabel) ?></span>
                                        </button>
                                        <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="config_compresor_vehicle_ids_toggle">
                                            <?php foreach (($vehicles ?? []) as $vehicle): ?>
                                                <?php
                                                $vehicleId = (int) ($vehicle['id'] ?? 0);
                                                if ($vehicleId <= 0) {
                                                    continue;
                                                }
                                                $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? ''));
                                                ?>
                                                <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option">
                                                    <input class="form-check-input m-0" type="checkbox" name="compresor_vehicle_ids[]" value="<?= e((string) $vehicleId) ?>" <?= in_array((string) $vehicleId, $compresorSelectedVehicleIds, true) ? 'checked' : '' ?>>
                                                    <span><?= e($vehicleLabel) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="form-text">Vehiculele selectate vor fi disponibile la adaugare cursa pentru tipul Compresor.</div>
                                    <?php if (isset($beneficiaryFormErrors['compresor_vehicle_ids'])): ?><div class="invalid-feedback d-block"><?= e((string) $beneficiaryFormErrors['compresor_vehicle_ids']) ?></div><?php endif; ?>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary"><?= ($beneficiaryFormMode ?? 'create') === 'edit' ? 'Actualizeaza regula beneficiar' : 'Salveaza regula beneficiar' ?></button>
                </div>
            </form>
        </section>

        <section class="transport-config-block mt-3" data-transport-card="catalog" <?= $isCatalogSelected ? '' : 'hidden' ?>>
            <div class="transport-distribution-nested-header">
                <h4 class="h6 mb-0">Catalog locatii si zone pentru <?= $distributionBeneficiaryName !== '' ? e($distributionBeneficiaryName) : 'beneficiarul curent' ?></h4>
                <span class="text-muted small">Acest catalog este folosit pentru Primar si Distributie.</span>
            </div>

            <?php if (!$catalogConfigReady): ?>
                <div class="alert alert-warning mt-3 mb-0">Salveaza mai intai regula beneficiarului cu tipul Primar sau Distributie bifat.</div>
            <?php else: ?>
                <div class="transport-distribution-panel transport-distribution-catalog-panel mt-2">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h5 class="h6 mb-0">Catalog locatii si zone</h5>
                        <span class="text-muted small">Completeaza doar locul de incarcare si zona de descarcare.</span>
                    </div>
                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_catalog'])) ?>" novalidate class="transport-distribution-inline-form">
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
                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-primary">Salveaza catalog</button>
                    </div>
                    </form>
                </div>

                <?php if ($isDistributieSelected): ?>
                    <?php if (!$canAddDistributionRoute): ?>
                        <div class="alert alert-warning mt-3 mb-0">Adauga cel putin un Loc incarcare si o Zona descarcare, apoi poti crea configuratii de ruta.</div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_distributie'])) ?>" novalidate class="mt-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="beneficiar_id" value="<?= e((string) $distributionBeneficiaryId) ?>">
                        <input type="hidden" name="route_id" value="<?= e((string) ($distributionRouteFormData['route_id'] ?? '')) ?>">

                    <div class="transport-distribution-panel transport-distribution-route-panel">
                        <?php if ($isRouteEditMode): ?>
                            <div class="alert alert-info py-2 mb-3">Editezi o configuratie existenta. Poti modifica perechea, preturile si vehiculele alocate.</div>
                        <?php endif; ?>
                        <div class="row g-3 align-items-end transport-distribution-route-grid">
                            <div class="col-12 col-xl-2">
                                <label class="form-label" for="config_route_loc_id">Loc incarcare <span class="text-danger">*</span></label>
                                <select class="form-select <?= isset($distributionRouteFormErrors['loc_id']) ? 'is-invalid' : '' ?>" id="config_route_loc_id" name="route_loc_id" required>
                                    <option value="">Selecteaza locatia de incarcare</option>
                                    <?php foreach (($locations ?? []) as $location): ?>
                                        <?php $locationId = (int) ($location['id'] ?? 0); ?>
                                        <option value="<?= e((string) $locationId) ?>" <?= (string) ($distributionRouteFormData['loc_id'] ?? '') === (string) $locationId ? 'selected' : '' ?>>
                                            <?= e((string) ($location['nume'] ?? '-')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($distributionRouteFormErrors['loc_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionRouteFormErrors['loc_id']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-xl-2">
                                <label class="form-label" for="config_route_zona_id">Zona descarcare <span class="text-danger">*</span></label>
                                <select class="form-select <?= isset($distributionRouteFormErrors['zona_id']) ? 'is-invalid' : '' ?>" id="config_route_zona_id" name="route_zona_id" required>
                                    <option value="">Selecteaza zona de descarcare</option>
                                    <?php foreach (($zones ?? []) as $zone): ?>
                                        <?php $zoneId = (int) ($zone['id'] ?? 0); ?>
                                        <option value="<?= e((string) $zoneId) ?>" <?= (string) ($distributionRouteFormData['zona_id'] ?? '') === (string) $zoneId ? 'selected' : '' ?>>
                                            <?= e((string) ($zone['nume'] ?? '-')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($distributionRouteFormErrors['zona_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionRouteFormErrors['zona_id']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-md-6 col-xl-2">
                                <label class="form-label" for="config_route_tarif_tona">Pret tona (RON) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control <?= isset($distributionRouteFormErrors['tarif_tona']) ? 'is-invalid' : '' ?>" id="config_route_tarif_tona" name="route_tarif_tona" min="0" step="0.01" value="<?= e((string) ($distributionRouteFormData['tarif_tona'] ?? '')) ?>" required>
                                <?php if (isset($distributionRouteFormErrors['tarif_tona'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionRouteFormErrors['tarif_tona']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-md-6 col-xl-2">
                                <label class="form-label" for="config_route_cost_extra_km">Pret km (RON) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control <?= isset($distributionRouteFormErrors['cost_extra_km']) ? 'is-invalid' : '' ?>" id="config_route_cost_extra_km" name="route_cost_extra_km" min="0" step="0.01" value="<?= e((string) ($distributionRouteFormData['cost_extra_km'] ?? '')) ?>" required>
                                <?php if (isset($distributionRouteFormErrors['cost_extra_km'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionRouteFormErrors['cost_extra_km']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-md-6 col-xl-2">
                                <label class="form-label" for="config_route_km_tarifare">Km tarifare <span class="text-danger">*</span></label>
                                <input type="number" class="form-control <?= isset($distributionRouteFormErrors['km_tarifare']) ? 'is-invalid' : '' ?>" id="config_route_km_tarifare" name="route_km_tarifare" min="1" step="1" value="<?= e((string) ($distributionRouteFormData['km_tarifare'] ?? '')) ?>" required>
                                <?php if (isset($distributionRouteFormErrors['km_tarifare'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionRouteFormErrors['km_tarifare']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-xl-2">
                                <label class="form-label" for="config_route_vehicle_ids_toggle">Vehicule <span class="text-danger">*</span></label>
                                <div class="dropdown vehicle-multiselect-dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start vehicle-multiselect-toggle <?= isset($distributionRouteFormErrors['vehicle_ids']) ? 'is-invalid' : '' ?>" type="button" id="config_route_vehicle_ids_toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                        <span class="vehicle-multiselect-label" data-default-label="-- Selecteaza vehiculele --"><?= e($routeVehicleButtonLabel) ?></span>
                                    </button>
                                    <div class="dropdown-menu w-100 p-2 vehicle-multiselect-menu" aria-labelledby="config_route_vehicle_ids_toggle">
                                        <?php foreach (($vehicles ?? []) as $vehicle): ?>
                                            <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                                            <?php $vehicleLabel = trim((string) ($vehicle['nr_inmatriculare'] ?? '-')) . ' - ' . trim((string) ($vehicle['marca'] ?? '')) . ' ' . trim((string) ($vehicle['model'] ?? '')); ?>
                                            <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 vehicle-multiselect-option">
                                                <input class="form-check-input m-0" type="checkbox" name="route_vehicle_ids[]" value="<?= e((string) $vehicleId) ?>" <?= in_array((string) $vehicleId, $routeSelectedVehicleIds, true) ? 'checked' : '' ?>>
                                                <span><?= e(trim($vehicleLabel)) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php if (isset($distributionRouteFormErrors['vehicle_ids'])): ?><div class="invalid-feedback d-block"><?= e((string) $distributionRouteFormErrors['vehicle_ids']) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <?php if (isset($distributionRouteFormErrors['route_id'])): ?><div class="invalid-feedback d-block mt-2"><?= e((string) $distributionRouteFormErrors['route_id']) ?></div><?php endif; ?>

                        <div class="mt-3 d-flex justify-content-end gap-2">
                            <?php if ($isRouteEditMode): ?>
                                <a
                                    class="btn btn-outline-secondary"
                                    href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId])) ?>"
                                >
                                    Renunta editarea
                                </a>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary" name="panel_action" value="add_route" <?= $canAddDistributionRoute ? '' : 'disabled' ?>>
                                <?= $isRouteEditMode ? 'Actualizeaza configuratia' : 'Adauga configuratie' ?>
                            </button>
                        </div>

                        <div class="mt-4">
                            <h5 class="h6 mb-3">Configuratii existente</h5>
                            <div class="table-responsive transport-config-table-wrap transport-route-table-wrap">
                                <table class="table table-sm align-middle mb-0 transport-config-table">
                                    <thead>
                                        <tr>
                                            <th>Loc incarcare</th>
                                            <th>Zona descarcare</th>
                                            <th>Pret tona (RON)</th>
                                            <th>Pret km (RON)</th>
                                            <th>Km tarifare</th>
                                            <th>Vehicule</th>
                                            <th class="text-end">Actiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (($distributionRouteRules ?? []) === []): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-3">Nu exista configuratii salvate pentru acest beneficiar.</td></tr>
                                    <?php else: ?>
                                        <?php foreach (($distributionRouteRules ?? []) as $routeRule): ?>
                                            <?php
                                            $routeId = (int) ($routeRule['id'] ?? 0);
                                            $routeVehicleLabels = [];
                                            $routeVehicleIdsRaw = trim((string) ($routeRule['vehicle_ids'] ?? ''));
                                            if ($routeVehicleIdsRaw !== '') {
                                                foreach (explode(',', $routeVehicleIdsRaw) as $routeVehicleIdRaw) {
                                                    $routeVehicleIdRaw = trim($routeVehicleIdRaw);
                                                    if ($routeVehicleIdRaw === '' || !ctype_digit($routeVehicleIdRaw)) {
                                                        continue;
                                                    }

                                                    $routeVehicleId = (int) $routeVehicleIdRaw;
                                                    if ($routeVehicleId > 0 && isset($vehicleLabelById[$routeVehicleId])) {
                                                        $routeVehicleLabels[] = $vehicleLabelById[$routeVehicleId];
                                                    } elseif ($routeVehicleId > 0) {
                                                        $routeVehicleLabels[] = 'Vehicul #' . $routeVehicleId;
                                                    }
                                                }
                                            }
                                            $routeVehicleText = $routeVehicleLabels !== [] ? implode(', ', array_values(array_unique($routeVehicleLabels))) : '-';
                                            ?>
                                            <tr>
                                                <td><?= e((string) ($routeRule['loc_nume'] ?? '-')) ?></td>
                                                <td><?= e((string) ($routeRule['zona_nume'] ?? '-')) ?></td>
                                                <td><?= e(format_number_ro((float) ($routeRule['tarif_tona'] ?? 0), 2)) ?></td>
                                                <td><?= e(format_number_ro((float) ($routeRule['cost_extra_km'] ?? 0), 2)) ?></td>
                                                <td><?= e((string) ((int) max(0, (int) ($routeRule['km_tarifare'] ?? 0)))) ?></td>
                                                <td><span class="dispatcher-cell-text" title="<?= e($routeVehicleText) ?>"><?= e($routeVehicleText) ?></span></td>
                                                <td class="text-end">
                                                    <a
                                                        class="btn btn-sm btn-outline-primary"
                                                        href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId, 'route_edit_id' => $routeId])) ?>"
                                                    >
                                                        Editeaza
                                                    </a>
                                                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_delete_ruta'])) ?>" class="d-inline ms-1">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="id" value="<?= e((string) $routeId) ?>">
                                                        <input type="hidden" name="beneficiar_id" value="<?= e((string) $distributionBeneficiaryId) ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Sigur doresti sa stergi aceasta configuratie?">Sterge</button>
                                                    </form>
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
            <?php endif; ?>
        </section>

        <section class="transport-config-block mt-3" data-transport-card="primar" <?= $isPrimarSelected ? '' : 'hidden' ?>>
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

                <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_store_primar_ruta'])) ?>" novalidate class="mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="beneficiar_id" value="<?= e((string) $distributionBeneficiaryId) ?>">
                    <input type="hidden" name="route_primar_id" value="<?= e((string) ($primaryRouteFormData['route_id'] ?? '')) ?>">

                    <div class="transport-distribution-panel transport-distribution-route-panel">
                        <?php if ($isPrimaryRouteEditMode): ?>
                            <div class="alert alert-info py-2 mb-3">Editezi o ruta Primar existenta. Poti modifica perechea Loc ↔ Zona si Km tarifare.</div>
                        <?php endif; ?>

                        <div class="row g-3 align-items-end transport-distribution-route-grid">
                            <div class="col-12 col-xl-4">
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

                            <div class="col-12 col-xl-4">
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
                                <input type="number" class="form-control <?= isset($primaryRouteFormErrors['km_tarifare']) ? 'is-invalid' : '' ?>" id="config_primary_route_km_tarifare" name="route_primar_km_tarifare" min="1" step="1" value="<?= e((string) ($primaryRouteFormData['km_tarifare'] ?? '')) ?>" required>
                                <?php if (isset($primaryRouteFormErrors['km_tarifare'])): ?><div class="invalid-feedback d-block"><?= e((string) $primaryRouteFormErrors['km_tarifare']) ?></div><?php endif; ?>
                            </div>

                            <div class="col-12 col-md-6 col-xl-2">
                                <div class="form-check form-switch transport-config-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="config_primary_route_activ" name="route_primar_activ" value="1" <?= (string) ($primaryRouteFormData['activ'] ?? '1') === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="config_primary_route_activ">Activ</label>
                                </div>
                            </div>
                        </div>
                        <?php if (isset($primaryRouteFormErrors['route_id'])): ?><div class="invalid-feedback d-block mt-2"><?= e((string) $primaryRouteFormErrors['route_id']) ?></div><?php endif; ?>

                        <div class="mt-3 d-flex justify-content-end gap-2">
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

                        <div class="mt-4">
                            <h5 class="h6 mb-3">Rute Primar existente</h5>
                            <div class="table-responsive transport-config-table-wrap transport-route-table-wrap">
                                <table class="table table-sm align-middle mb-0 transport-config-table">
                                    <thead>
                                        <tr>
                                            <th>Loc incarcare</th>
                                            <th>Zona descarcare</th>
                                            <th>Km tarifare</th>
                                            <th>Status</th>
                                            <th class="text-end">Actiuni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (($primaryRouteRules ?? []) === []): ?>
                                        <tr><td colspan="5" class="text-center text-muted py-3">Nu exista rute Primar salvate pentru acest beneficiar.</td></tr>
                                    <?php else: ?>
                                        <?php foreach (($primaryRouteRules ?? []) as $primaryRouteRule): ?>
                                            <?php $primaryRouteId = (int) ($primaryRouteRule['id'] ?? 0); ?>
                                            <tr>
                                                <td><?= e((string) ($primaryRouteRule['loc_nume'] ?? '-')) ?></td>
                                                <td><?= e((string) ($primaryRouteRule['zona_nume'] ?? '-')) ?></td>
                                                <td><?= e((string) ((int) ($primaryRouteRule['km_tarifare'] ?? 0))) ?></td>
                                                <td><?= !empty($primaryRouteRule['activ']) ? '<span class="badge transport-status-badge transport-status-active">Activ</span>' : '<span class="badge transport-status-badge transport-status-inactive">Inactiv</span>' ?></td>
                                                <td class="text-end">
                                                    <a
                                                        class="btn btn-sm btn-outline-primary"
                                                        href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config', 'beneficiar_edit_id' => $distributionBeneficiaryId, 'route_primar_edit_id' => $primaryRouteId])) ?>"
                                                    >
                                                        Editeaza
                                                    </a>
                                                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'config_delete_ruta_primar'])) ?>" class="d-inline ms-1">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="id" value="<?= e((string) $primaryRouteId) ?>">
                                                        <input type="hidden" name="beneficiar_id" value="<?= e((string) $distributionBeneficiaryId) ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Sigur doresti sa stergi aceasta ruta Primar?">Sterge</button>
                                                    </form>
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

        <section class="transport-config-block mt-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
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
            <div class="table-responsive transport-rules-table-wrap">
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

        checkboxes.forEach(function (checkbox) {
            if (!(checkbox instanceof HTMLInputElement)) {
                return;
            }
            const typeKey = String(checkbox.value || '');
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
            return typeKey === 'primar' || typeKey === 'distributie';
        });
        const catalogCards = document.querySelectorAll('[data-transport-card="catalog"]');
        catalogCards.forEach(function (card) {
            card.hidden = !showCatalogCard;
            setCardControlsEnabled(card, showCatalogCard);
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

    const initVehicleMultiselectDropdown = function (dropdownEl) {
        if (!dropdownEl) {
            return;
        }

        const labelEl = dropdownEl.querySelector('.vehicle-multiselect-label');
        const checkboxEls = dropdownEl.querySelectorAll('input[type="checkbox"]');
        const defaultLabel = labelEl?.dataset.defaultLabel || '-- Fara alocare --';

        const refreshVehicleLabel = function () {
            if (!labelEl) {
                return;
            }
            const selectedLabels = [];
            checkboxEls.forEach(function (checkboxEl) {
                if (!(checkboxEl instanceof HTMLInputElement) || !checkboxEl.checked) {
                    return;
                }
                const text = checkboxEl.closest('label')?.querySelector('span')?.textContent?.trim();
                if (text) {
                    selectedLabels.push(text);
                }
            });

            if (selectedLabels.length === 0) {
                labelEl.textContent = defaultLabel;
                labelEl.removeAttribute('title');
                return;
            }

            const joined = selectedLabels.join(', ');
            labelEl.textContent = joined;
            labelEl.setAttribute('title', joined);
        };

        checkboxEls.forEach(function (checkboxEl) {
            checkboxEl.addEventListener('change', refreshVehicleLabel);
        });

        refreshVehicleLabel();
    };

    document.querySelectorAll('.vehicle-multiselect-dropdown').forEach(initVehicleMultiselectDropdown);
});
</script>
