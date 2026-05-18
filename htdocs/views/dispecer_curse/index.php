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
$currentListUrl = build_query_url(array_merge($baseQuery, ['p' => (int) ($pagination['page'] ?? 1)]));

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
$openRacesCount = (int) ($openRacesOverview['count'] ?? 0);
$openRacesRows = is_array($openRacesOverview['rows'] ?? null) ? $openRacesOverview['rows'] : [];
$openRacesMissingEndTimeCount = (int) ($openRacesOverview['missing_end_time_count'] ?? 0);
$openRacesMissingExpensesCount = (int) ($openRacesOverview['missing_expenses_count'] ?? 0);
$postCreateExpensePromptRaceId = (int) (($postCreateExpensePrompt['race_id'] ?? 0));
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

<div class="row g-3 align-items-start">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h3 class="h6 mb-0">AdaugÄƒ CursÄƒ</h3>
            </div>
            <div class="card-body">
                <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'store'])) ?>" class="dispatcher-race-form" data-zone-tariffs='<?= e($zoneTariffJson) ?>' data-zone-extra-km-costs='<?= e($zoneExtraKmJson) ?>' data-distribution-route-tariffs='<?= e($distributionRouteTariffMapJson) ?>' data-primary-route-km-map='<?= e($primaryRouteKmMapJson) ?>' data-beneficiary-pricing='<?= e($beneficiaryPricingJson) ?>' data-load-location-tariffs='<?= e($loadLocationTariffJson) ?>' data-vehicle-default-load-locations='<?= e($vehicleDefaultLoadLocationJson) ?>' data-vehicle-default-distribution-zones='<?= e($vehicleDefaultDistributionZoneJson) ?>' data-vehicle-garages='<?= e($vehicleGarageJson) ?>' data-load-locations-by-beneficiary='<?= e($loadLocationsByBeneficiaryJson) ?>' data-distribution-zones-by-beneficiary='<?= e($distributionZonesByBeneficiaryJson) ?>' data-vehicle-default-load-locations-by-beneficiary='<?= e($vehicleDefaultLoadLocationByBeneficiaryJson) ?>' data-vehicle-default-distribution-zones-by-beneficiary='<?= e($vehicleDefaultDistributionZoneByBeneficiaryJson) ?>' data-compresor-vehicles-by-beneficiary='<?= e($compressorVehicleByBeneficiaryJson) ?>' data-active-driver-vehicle-ids='<?= e($activeDriverVehicleIdsJson) ?>' data-drivers-by-vehicle='<?= e($driversByVehicleJson) ?>' novalidate>
                    <?= csrf_field() ?>
                    <datalist id="race_time_options">
                        <?php for ($hour = 0; $hour < 24; $hour++): ?>
                            <?php foreach (['00', '15', '30', '45'] as $minute): ?>
                                <option value="<?= e(sprintf('%02d:%s', $hour, $minute)) ?>"></option>
                            <?php endforeach; ?>
                        <?php endfor; ?>
                    </datalist>

                    <div class="row g-3">
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
                            <label class="form-label" for="race_vehicle_id">Nr. ÃŽnmatriculare <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['vehicle_id']) ? 'is-invalid' : '' ?>" id="race_vehicle_id" name="vehicle_id" required title="Pentru Primar km/tone: se afiseaza vehicule active cu sofer asociat. Pentru celelalte tipuri: filtrare dupa beneficiar si configurari.">
                                <option value="">-- SelecteazÄƒ --</option>
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

                        <div class="col-12 col-md-6 dispatcher-schedule-field">
                            <label class="form-label" for="race_data_inceput">Data inceput <span class="text-danger">*</span></label>
                            <input type="date" class="form-control <?= isset($formErrors['data_inceput']) ? 'is-invalid' : '' ?>" id="race_data_inceput" name="data_inceput" value="<?= e((string) ($formData['data_inceput'] ?? ($formData['data_cursa'] ?? ''))) ?>" required>
                            <?php if (isset($formErrors['data_inceput'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['data_inceput']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-schedule-field">
                            <label class="form-label" for="race_ora_inceput">Ora inceput</label>
                            <div class="input-group">
                                <input
                                    type="text"
                                    class="form-control <?= isset($formErrors['ora_inceput']) ? 'is-invalid' : '' ?>"
                                    id="race_ora_inceput"
                                    name="ora_inceput"
                                    value="<?= e($formStartTimeValue) ?>"
                                    placeholder="HH:mm"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]"
                                    list="race_time_options"
                                    data-role="ora-inceput"
                                >
                                <button type="button" class="btn btn-outline-secondary" data-role="time-now" data-target-role="ora-inceput" title="Completeaza cu ora curenta">Acum</button>
                            </div>
                            <div class="form-text">Format 24h (HH:mm). Poti scrie si 0930.</div>
                            <?php if (isset($formErrors['ora_inceput'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['ora_inceput']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-schedule-field">
                            <label class="form-label" for="race_data_sfarsit">Data sfarsit <span class="text-danger">*</span></label>
                            <input type="date" class="form-control <?= isset($formErrors['data_sfarsit']) ? 'is-invalid' : '' ?>" id="race_data_sfarsit" name="data_sfarsit" value="<?= e((string) ($formData['data_sfarsit'] ?? ($formData['data_cursa'] ?? ''))) ?>" required>
                            <?php if (isset($formErrors['data_sfarsit'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['data_sfarsit']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-schedule-field">
                            <label class="form-label" for="race_ora_sfarsit">Ora sfarsit</label>
                            <div class="input-group">
                                <input
                                    type="text"
                                    class="form-control <?= isset($formErrors['ora_sfarsit']) ? 'is-invalid' : '' ?>"
                                    id="race_ora_sfarsit"
                                    name="ora_sfarsit"
                                    value="<?= e($formEndTimeValue) ?>"
                                    placeholder="HH:mm"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    pattern="(?:[01][0-9]|2[0-3]):[0-5][0-9]"
                                    list="race_time_options"
                                    data-role="ora-sfarsit"
                                    title="<?= e($formDurationPreviewText) ?>"
                                >
                                <button type="button" class="btn btn-outline-secondary" data-role="time-now" data-target-role="ora-sfarsit" title="Completeaza cu ora curenta">Acum</button>
                            </div>
                            <?php if (isset($formErrors['ora_sfarsit'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['ora_sfarsit']) ?></div><?php endif; ?>
                            <div class="form-text d-none dispatcher-hover-note" data-role="durata-cursa-hint" data-default-text="<?= e($formDurationPreviewText) ?>"><?= e($formDurationPreviewText) ?></div>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-primary-grid-field dispatcher-compressor-grid-field" data-role="field-loc-incarcare">
                            <label class="form-label" for="race_loc_incarcare_id">Loc ÃŽncÄƒrcare <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['loc_incarcare_id']) ? 'is-invalid' : '' ?>" id="race_loc_incarcare_id" name="loc_incarcare_id" required title="Selecteaza locul de incarcare pentru cursa.">
                                <option value="">-- SelecteazÄƒ --</option>
                                <?php foreach ($loadLocations as $location): ?>
                                    <?php $locationId = (int) ($location['id'] ?? 0); ?>
                                    <option value="<?= e((string) $locationId) ?>" <?= (string) ($formData['loc_incarcare_id'] ?? '') === (string) $locationId ? 'selected' : '' ?>>
                                        <?= e((string) ($location['nume'] ?? '-')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($formErrors['loc_incarcare_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['loc_incarcare_id']) ?></div><?php endif; ?>
                            <div class="form-text text-muted d-none dispatcher-hover-note" data-role="distributie-note-loc">
                                Pentru Distributie / Primar+Distributie: regula de ruta are prioritate pe perechile configurate bidirectional (Loc ↔ Zona). Daca nu exista pereche, se aplica fallback loc/zona/beneficiar.
                            </div>
                            <div class="form-text text-muted d-none dispatcher-hover-note" data-role="primar-note-loc">
                                Pentru Primar km / Primar tone: sunt afisate doar locurile din Setari Primar, iar Km efectuati este luat automat din perechea Loc ↔ Zona.
                            </div>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-primary-grid-field dispatcher-compressor-grid-field" data-role="field-tip-marfa">
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
                            <label class="form-label" for="race_cantitate_incarcata">Cantitate ÃŽncÄƒrcatÄƒ</label>
                            <input type="number" class="form-control <?= isset($formErrors['cantitate_incarcata']) ? 'is-invalid' : '' ?>" id="race_cantitate_incarcata" name="cantitate_incarcata" step="0.01" min="0" value="<?= e((string) ($formData['cantitate_incarcata'] ?? '')) ?>" data-role="cantitate" title="Valoarea introdusa este folosita direct in calcule, fara conversie automata.">
                            <?php if (isset($formErrors['cantitate_incarcata'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['cantitate_incarcata']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-primary-grid-field dispatcher-compressor-grid-field" data-role="field-capacitate-transport">
                            <label class="form-label" for="race_capacitate_transport">Capacitate transport</label>
                            <input type="number" class="form-control <?= isset($formErrors['capacitate_transport']) ? 'is-invalid' : '' ?>" id="race_capacitate_transport" name="capacitate_transport" step="0.01" min="0" value="<?= e((string) ($formData['capacitate_transport'] ?? '')) ?>" data-role="capacitate-transport" readonly title="Se completeaza automat din fisa vehiculului.">
                            <?php if (isset($formErrors['capacitate_transport'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['capacitate_transport']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6" data-role="field-km">
                            <label class="form-label" for="race_km_cursa" data-role="km-label" data-default-label="Km efectuati" data-primary-km-label="Km agreati"><?= $isAgreedKmNamingSelected ? 'Km agreati' : 'Km efectuati' ?></label>
                            <input type="number" class="form-control <?= isset($formErrors['km_cursa']) ? 'is-invalid' : '' ?>" id="race_km_cursa" name="km_cursa" min="0" step="1" value="<?= e((string) ($formData['km_cursa'] ?? '')) ?>" data-role="km">
                            <?php if (isset($formErrors['km_cursa'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['km_cursa']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="race_ore_functionare">Ore functionare</label>
                            <input type="text" class="form-control <?= isset($formErrors['ore_functionare']) ? 'is-invalid' : '' ?>" id="race_ore_functionare" name="ore_functionare" value="<?= e((string) ($formData['ore_functionare'] ?? '')) ?>" placeholder="ex: 2h sau 2" title="1h = 40 km echivalenti pentru scaderea Km revizie.">
                            <?php if (isset($formErrors['ore_functionare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['ore_functionare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="race_nr_clienti">Nr. ClienÈ›i</label>
                            <input type="number" class="form-control <?= isset($formErrors['nr_clienti']) ? 'is-invalid' : '' ?>" id="race_nr_clienti" name="nr_clienti" min="0" step="1" value="<?= e((string) ($formData['nr_clienti'] ?? '')) ?>">
                            <?php if (isset($formErrors['nr_clienti'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['nr_clienti']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6" data-role="field-zona">
                            <label class="form-label" for="race_zona_distributie_id" data-role="zona-label" data-default-label="Zona distributie" data-primary-label="Zona descarcare">Zona distributie</label>
                            <select class="form-select <?= isset($formErrors['zona_distributie_id']) ? 'is-invalid' : '' ?>" id="race_zona_distributie_id" name="zona_distributie_id" data-role="zona" title="Selecteaza zona de distributie/descarcare pentru ruta.">
                                <option value="">-- SelecteazÄƒ --</option>
                                <?php foreach ($distributionZones as $zone): ?>
                                    <?php $zoneId = (int) ($zone['id'] ?? 0); ?>
                                    <?php $zoneExtraKmCost = (float) ($zone['cost_extra_km'] ?? 0); ?>
                                    <option value="<?= e((string) $zoneId) ?>" <?= (string) ($formData['zona_distributie_id'] ?? '') === (string) $zoneId ? 'selected' : '' ?>>
                                        <?= e((string) ($zone['nume'] ?? '-')) ?>
                                        (tarif zonÄƒ: <?= e(format_number_ro((float) ($zone['tarif_distributie'] ?? 0), 2)) ?> lei<?php if ($zoneExtraKmCost > 0): ?>, extra km: <?= e(format_number_ro($zoneExtraKmCost, 2)) ?> lei/km<?php endif; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($formErrors['zona_distributie_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['zona_distributie_id']) ?></div><?php endif; ?>
                            <div class="form-text text-muted d-none dispatcher-hover-note" data-role="distributie-note-zone">
                                Prioritate calcul: regula de ruta (Loc ↔ Zona), apoi regulile loc/zona, apoi fallback beneficiar. Distributie = Cantitate × Tariful activ; Primar+Distributie = Cantitate × Tariful activ + Km × Cost extra/km activ.
                            </div>
                            <div class="form-text text-muted d-none dispatcher-hover-note" data-role="primar-note-zone">
                                Pentru Primar km / Primar tone, selectia Loc ↔ Zona este filtrata din Setari Primar si se aplica bidirectional.
                            </div>
                        </div>

                        <div class="col-12 col-md-6 <?= $isKmTotalSelected ? '' : 'd-none' ?>" data-role="field-km-totali">
                            <label class="form-label" for="race_km_totali" data-role="km-total-label" data-default-label="Km totali" data-primary-km-label="Km efectuati"><?= $isAgreedKmNamingSelected ? 'Km efectuati' : 'Km totali' ?></label>
                            <input type="number" class="form-control <?= isset($formErrors['km_totali']) ? 'is-invalid' : '' ?>" id="race_km_totali" name="km_totali" min="0" step="1" value="<?= e((string) ($formData['km_totali'] ?? '')) ?>" data-role="km-totali">
                            <?php if (isset($formErrors['km_totali'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['km_totali']) ?></div><?php endif; ?>
                            <div class="form-text text-muted <?= $isPrimaryDistributionSelected ? '' : 'd-none' ?>" data-role="km-distributie-calculation">Cost/km Distributie (calcul): (Cantitate × Tarif tona) / Km agreati</div>
                        </div>

                        <div class="col-12 col-md-6" data-role="field-ore-aspirare">
                            <label class="form-label" for="race_ore_aspirare">Ore aspirare</label>
                            <input type="number" class="form-control <?= isset($formErrors['ore_aspirare']) ? 'is-invalid' : '' ?>" id="race_ore_aspirare" name="ore_aspirare" step="0.01" min="0" value="<?= e((string) ($formData['ore_aspirare'] ?? '')) ?>" data-role="ore-aspirare">
                            <?php if (isset($formErrors['ore_aspirare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['ore_aspirare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6" data-role="field-km-dislocare">
                            <label class="form-label" for="race_km_dislocare">Km dislocare</label>
                            <input type="number" class="form-control <?= isset($formErrors['km_dislocare']) ? 'is-invalid' : '' ?>" id="race_km_dislocare" name="km_dislocare" step="0.01" min="0" value="<?= e((string) ($formData['km_dislocare'] ?? '')) ?>" data-role="km-dislocare">
                            <?php if (isset($formErrors['km_dislocare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['km_dislocare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6" data-role="field-tona-livrata">
                            <label class="form-label" for="race_tona_livrata">Tona livrata</label>
                            <input type="number" class="form-control <?= isset($formErrors['tona_livrata']) ? 'is-invalid' : '' ?>" id="race_tona_livrata" name="tona_livrata" step="0.01" min="0" value="<?= e((string) ($formData['tona_livrata'] ?? '')) ?>" data-role="tona-livrata">
                            <?php if (isset($formErrors['tona_livrata'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tona_livrata']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 d-none" data-role="field-tona-aspirata-lichida">
                            <label class="form-label" for="race_tona_aspirata_lichida">Tona lichida aspirata</label>
                            <input type="number" class="form-control <?= isset($formErrors['tona_aspirata_lichida']) ? 'is-invalid' : '' ?>" id="race_tona_aspirata_lichida" name="tona_aspirata_lichida" step="0.01" min="0" value="<?= e((string) ($formData['tona_aspirata_lichida'] ?? '')) ?>" data-role="tona-aspirata-lichida">
                            <?php if (isset($formErrors['tona_aspirata_lichida'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tona_aspirata_lichida']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 d-none" data-role="field-tona-aspirata-gazoasa">
                            <label class="form-label" for="race_tona_aspirata_gazoasa">Tona gazoasa aspirata</label>
                            <input type="number" class="form-control <?= isset($formErrors['tona_aspirata_gazoasa']) ? 'is-invalid' : '' ?>" id="race_tona_aspirata_gazoasa" name="tona_aspirata_gazoasa" step="0.01" min="0" value="<?= e((string) ($formData['tona_aspirata_gazoasa'] ?? '')) ?>" data-role="tona-aspirata-gazoasa">
                            <?php if (isset($formErrors['tona_aspirata_gazoasa'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tona_aspirata_gazoasa']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6" data-role="preview-total-field">
                            <label class="form-label">Total Facturare (estimare)</label>
                            <div class="dispatcher-total-preview" data-role="total-preview">0,00 lei</div>
                        </div>

                        <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-primar-field">
                            <label class="form-label">Cost/km Primar</label>
                            <div class="dispatcher-total-preview" data-role="cost-km-primar-preview">0,00 lei/km</div>
                        </div>

                        <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-distributie-field">
                            <label class="form-label">Cost/km Distribuție</label>
                            <div class="dispatcher-total-preview" data-role="cost-km-distributie-preview">0,00 lei/km</div>
                        </div>

                        <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-mixt-field">
                            <label class="form-label">Cost/km Mixt</label>
                            <div class="dispatcher-total-preview" data-role="cost-km-mixt-preview">0,00 lei/km</div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="race_status_facturare">Status</label>
                            <select class="form-select <?= isset($formErrors['status_facturare']) ? 'is-invalid' : '' ?>" id="race_status_facturare" name="status_facturare">
                                <?php foreach (($billingStatuses ?? []) as $statusKey => $statusLabel): ?>
                                    <option value="<?= e((string) $statusKey) ?>" <?= (string) ($formData['status_facturare'] ?? 'in_curs_facturare') === (string) $statusKey ? 'selected' : '' ?>>
                                        <?= e((string) $statusLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($formErrors['status_facturare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['status_facturare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="race_observatii">ObservaÈ›ii</label>
                            <textarea class="form-control <?= isset($formErrors['observatii']) ? 'is-invalid' : '' ?>" id="race_observatii" name="observatii" rows="3"><?= e((string) ($formData['observatii'] ?? '')) ?></textarea>
                            <?php if (isset($formErrors['observatii'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['observatii']) ?></div><?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">AdaugÄƒ CursÄƒ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h3 class="h6 mb-0">Filtre</h3>
            </div>
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <input type="hidden" name="page" value="dispecer_curse">
                    <input type="hidden" name="action" value="index">

                    <div class="col-12 col-xl-4">
                        <label class="form-label" for="filter_q">CÄƒutare</label>
                        <input type="text" class="form-control" id="filter_q" name="q" value="<?= e((string) $search) ?>" placeholder="Nr. auto, zonÄƒ, observaÈ›ii...">
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
                        <label class="form-label" for="filter_vehicle_id">Nr. ÃŽnmatriculare</label>
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
                        <label class="form-label" for="filter_loc_incarcare_id">Loc ÃŽncÄƒrcare</label>
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
                        <label class="form-label" for="filter_zona_distributie_id">ZonÄƒ DistribuÈ›ie</label>
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
                        <label class="form-label" for="filter_data_end">Data pÃ¢nÄƒ la</label>
                        <input type="date" class="form-control" id="filter_data_end" name="data_end" value="<?= e((string) ($filters['data_end'] ?? '')) ?>">
                    </div>

                    <div class="col-12 col-md-6 col-xl-2 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary">AplicÄƒ filtre</button>
                        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">ReseteazÄƒ</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
        <h3 class="h6 mb-0">Lista Curse</h3>
        <div class="d-flex align-items-center gap-2">
            <button
                type="submit"
                class="btn btn-sm btn-outline-danger"
                id="bulk-race-delete-btn"
                form="bulk-race-delete-form"
                disabled
                data-confirm="Sigur doresti sa stergi cursele selectate?"
            >
                Sterge selectate
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
            <table class="table table-hover align-middle mb-0 dispatcher-races-table curse-table">
                <colgroup>
                    <col class="col-plate">
                    <col class="col-driver">
                    <col class="col-type">
                    <col class="col-date">
                    <col class="col-date">
                    <col class="col-duration">
                    <col class="col-location">
                    <col class="col-beneficiary">
                    <col class="col-goods-type">
                    <col class="col-qty">
                    <col class="col-prelevata">
                    <col class="col-clients">
                    <col class="col-capacity">
                    <col class="col-free-capacity">
                    <col class="col-km">
                    <col class="col-km-total">
                    <col class="col-km-unbilled">
                    <col class="col-hour">
                    <col class="col-relocation-km">
                    <col class="col-delivered-ton">
                    <col class="col-zone">
                    <col class="col-price">
                    <col class="col-total">
                    <col class="col-cost-km-primar">
                    <col class="col-cost-km-distributie">
                    <col class="col-cost-km-mixt">
                    <col class="col-cost-km-compresor">
                    <col class="col-expenses">
                    <col class="col-actions">
                    <col class="col-status">
                </colgroup>
                <thead>
                <tr>
                    <th class="col-plate">
                        <label class="dispatcher-plate-head mb-0" for="bulk-race-select-all">
                            <input type="checkbox" class="form-check-input m-0" id="bulk-race-select-all" aria-label="Selecteaza toate cursele">
                            <span>Nr. ÃŽnmatriculare</span>
                        </label>
                    </th>
                    <th class="col-driver">Sofer</th>
                    <th class="col-type">Tip Transport</th>
                    <th class="col-date">Data inceput</th>
                    <th class="col-date">Data sfarsit</th>
                    <th class="col-duration text-end">Durata cursa</th>
                    <th class="col-location">Loc ÃŽncÄƒrcare</th>
                    <th class="col-beneficiary">Beneficiar</th>
                    <th class="col-goods-type">Tip marfÄƒ</th>
                    <th class="col-qty text-end">Cantitate ÃŽncÄƒrcatÄƒ</th>
                    <th class="col-prelevata text-end">Cantitate prelevata</th>
                    <th class="col-clients text-end">Nr. ClienÈ›i</th>
                    <th class="col-capacity text-end">Capacitate transport</th>
                    <th class="col-free-capacity text-end">Grad Incarcare masina</th>
                    <th class="col-km text-end">Km efectuati</th>
                    <th class="col-km-total text-end">Km totali</th>
                    <th class="col-km-unbilled text-end">Km nefacturati</th>
                    <th class="col-hour text-end">Ore aspirare</th>
                    <th class="col-relocation-km text-end">Km dislocare</th>
                    <th class="col-delivered-ton text-end">Tona livrata</th>
                    <th class="col-zone">ZonÄƒ DistribuÈ›ie</th>
                    <th class="col-price text-end">PreÈ› Tarifare</th>
                    <th class="col-total text-end">Total Facturare</th>
                    <th class="col-cost-km-primar text-end">Cost/km Primar</th>
                    <th class="col-cost-km-distributie text-end">Cost/km DistribuÈ›ie</th>
                    <th class="col-cost-km-mixt text-end">Cost/km Mixt</th>
                    <th class="col-cost-km-compresor text-end">Cost/km Compresor</th>
                    <th class="col-expenses text-end">Cheltuieli</th>
                    <th class="col-actions text-end pe-3">AcÈ›iuni</th>
                    <th class="col-status">Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="30" class="text-center text-muted py-4">Nu existÄƒ curse Ã®nregistrate.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $raceId = (int) ($row['id'] ?? 0);
                        $transportType = (string) ($row['tip_transport'] ?? '');
                        $transportLabel = $transportTypes[$transportType] ?? '-';
                        $locIncarcare = (string) (($row['loc_incarcare_nume'] ?? '') !== '' ? $row['loc_incarcare_nume'] : '-');
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
                        $zonaDistributie = (string) (($row['zona_distributie_nume'] ?? '') !== '' ? $row['zona_distributie_nume'] : '-');
                        $dataInceput = (string) (($row['data_inceput'] ?? '') !== '' ? $row['data_inceput'] : ($row['data_cursa'] ?? ''));
                        $dataSfarsit = (string) (($row['data_sfarsit'] ?? '') !== '' ? $row['data_sfarsit'] : $dataInceput);
                        $durationMinutes = null;
                        $durationMinutesRaw = $row['durata_cursa_minute'] ?? null;
                        if ($durationMinutesRaw !== null && $durationMinutesRaw !== '' && is_numeric((string) $durationMinutesRaw)) {
                            $durationMinutes = max(0, (int) $durationMinutesRaw);
                        }
                        $durationLabel = $formatDurationLabel($durationMinutes);
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
                        if ($transportType === 'primar_distributie' && $kmCursaValue !== null && $kmCursaValue > 0) {
                            $qtyForDistribution = $loadedQtyValue !== null ? max(0.0, (float) $loadedQtyValue) : 0.0;
                            $tonRateForDistribution = max(0.0, (float) ($row['pret_tarifare'] ?? 0));
                            $distributionComponent = $qtyForDistribution * $tonRateForDistribution;
                            $costKmDistributieValue = round($distributionComponent / $kmCursaValue, 2);
                            $costKmMixtValue = round(((float) ($row['total_facturare'] ?? 0)) / $kmCursaValue, 2);
                        }
                        if ($transportType === 'compresor' && $costKmCompresorValue <= 0) {
                            $kmCompresor = isset($row['km_dislocare']) ? (float) $row['km_dislocare'] : 0.0;
                            if ($kmCompresor > 0) {
                                $costKmCompresorValue = round(((float) ($row['total_facturare'] ?? 0)) / $kmCompresor, 2);
                            }
                        }
                        $billingStatusRowClass = 'race-status-' . preg_replace('/[^a-z0-9_]/', '', strtolower($billingStatus));
                        ?>
                        <tr class="<?= e($billingStatusRowClass) ?>" data-billing-status="<?= e($billingStatus) ?>">
                            <td class="col-plate">
                                <label class="dispatcher-plate-cell mb-0" for="bulk-race-id-<?= e((string) $raceId) ?>">
                                    <input
                                        type="checkbox"
                                        class="form-check-input m-0 bulk-race-checkbox"
                                        id="bulk-race-id-<?= e((string) $raceId) ?>"
                                        name="ids[]"
                                        value="<?= e((string) $raceId) ?>"
                                        form="bulk-race-delete-form"
                                        aria-label="Selecteaza cursa ID <?= e((string) $raceId) ?>"
                                    >
                                    <span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></span>
                                </label>
                            </td>
                            <td class="col-driver"><span class="dispatcher-cell-text" title="<?= e($driverName) ?>"><?= e($driverName) ?></span></td>
                            <td class="col-type"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e((string) $transportLabel) ?></span></td>
                            <td class="col-date"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_date_ro($dataInceput)) ?></span></td>
                            <td class="col-date"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_date_ro($dataSfarsit)) ?></span></td>
                            <td class="col-duration text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e($durationLabel) ?></span></td>
                            <td class="col-location"><span class="dispatcher-cell-text" title="<?= e($locIncarcare) ?>"><?= e($locIncarcare) ?></span></td>
                            <td class="col-beneficiary"><span class="dispatcher-cell-text" title="<?= e($beneficiarNume) ?>"><?= e($beneficiarNume) ?></span></td>
                            <td class="col-goods-type"><span class="dispatcher-cell-text" title="<?= e($goodsTypeLabel) ?>"><?= e($goodsTypeLabel) ?></span></td>
                            <td class="col-qty text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= $loadedQtyDisplayTon !== null ? e(format_number_ro($loadedQtyDisplayTon, 2)) : '-' ?></span></td>
                            <td class="col-prelevata text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro($row['cantitate_prelevata'] ?? null, 2)) ?></span></td>
                            <td class="col-clients text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e((string) (($row['nr_clienti'] ?? '') !== '' ? $row['nr_clienti'] : '-')) ?></span></td>
                            <td class="col-capacity text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro($row['capacitate_transport'] ?? null, 2)) ?></span></td>
                            <td class="col-free-capacity text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= $freeCapacityValue !== null ? e(format_number_ro($freeCapacityValue, 2)) : '-' ?></span></td>
                            <td class="col-km text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e((string) ($kmEfectuatiDisplayValue !== null ? number_format($kmEfectuatiDisplayValue, 0, ',', '.') : '-')) ?></span></td>
                            <td class="col-km-total text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e((string) (($row['km_totali'] ?? '') !== '' ? number_format((float) $row['km_totali'], 0, ',', '.') : '-')) ?></span></td>
                            <td class="col-km-unbilled text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e((string) ($kmNefacturatiValue !== null ? number_format($kmNefacturatiValue, 0, ',', '.') : '-')) ?></span></td>
                            <td class="col-hour text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro($row['ore_aspirare'] ?? null, 2)) ?></span></td>
                            <td class="col-relocation-km text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro($row['km_dislocare'] ?? null, 2)) ?></span></td>
                            <td class="col-delivered-ton text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro($row['tona_livrata'] ?? null, 2)) ?></span></td>
                            <td class="col-zone"><span class="dispatcher-cell-text" title="<?= e($zonaDistributie) ?>"><?= e($zonaDistributie) ?></span></td>
                            <td class="col-price text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro((float) ($row['pret_tarifare'] ?? 0), 2)) ?> lei</span></td>
                            <td class="col-total text-end"><strong class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro((float) ($row['total_facturare'] ?? 0), 2)) ?> lei</strong></td>
                            <td class="col-cost-km-primar text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro($costKmPrimarValue, 2)) ?> lei/km</span></td>
                            <td class="col-cost-km-distributie text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro($costKmDistributieValue, 2)) ?> lei/km</span></td>
                            <td class="col-cost-km-mixt text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro($costKmMixtValue, 2)) ?> lei/km</span></td>
                            <td class="col-cost-km-compresor text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro($costKmCompresorValue, 2)) ?> lei/km</span></td>
                            <td class="col-expenses text-end"><span class="dispatcher-cell-text dispatcher-cell-nowrap"><?= e(format_number_ro((float) ($row['total_cheltuieli'] ?? 0), 2)) ?> lei</span></td>
                            <td class="col-actions text-end pe-3">
                                <div class="d-inline-flex gap-1 dispatcher-row-actions">
                                    <a class="btn btn-sm btn-outline-primary dispatcher-action-btn" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId])) ?>">EditeazÄƒ</a>
                                    <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'delete'])) ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $raceId) ?>">
                                        <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger dispatcher-action-btn" data-confirm="Sigur doreÈ™ti sÄƒ È™tergi aceastÄƒ cursÄƒ?">
                                            È˜terge
                                        </button>
                                    </form>
                                </div>
                            </td>
                            <td class="col-status">
                                <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'update_status'])) ?>" class="dispatcher-status-form m-0">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e((string) $raceId) ?>">
                                    <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
                                    <select class="form-select form-select-sm dispatcher-status-select" name="status_facturare" onchange="(function(el){var row=el.closest('tr');if(row){row.classList.remove('race-status-in_curs_facturare','race-status-facturat','race-status-nefacturat');row.classList.add('race-status-'+el.value);}el.form.submit();})(this)">
                                        <?php foreach (($billingStatuses ?? []) as $statusKey => $statusLabel): ?>
                                            <option value="<?= e((string) $statusKey) ?>" <?= $billingStatus === (string) $statusKey ? 'selected' : '' ?>>
                                                <?= e((string) $statusLabel) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (($pagination['total_pages'] ?? 1) > 1): ?>
        <div class="card-footer bg-white">
            <?php
            $currentPageIndex = (int) ($pagination['page'] ?? 1);
            $totalPages = (int) ($pagination['total_pages'] ?? 1);
            $prevPage = max(1, $currentPageIndex - 1);
            $nextPage = min($totalPages, $currentPageIndex + 1);
            ?>
            <nav aria-label="Paginare curse">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $currentPageIndex <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $prevPage]))) ?>">Anterior</a>
                    </li>
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?= $currentPageIndex === $p ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $p]))) ?>"><?= e((string) $p) ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $currentPageIndex >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $nextPage]))) ?>">UrmÄƒtor</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php if ($openRacesCount > 0): ?>
    <div class="dispatcher-open-races-tab" data-open-races-tab>
        <button
            type="button"
            class="dispatcher-open-races-toggle"
            data-open-races-toggle
            aria-expanded="false"
            aria-controls="open-races-panel"
        >
            Atentie: curse cu informatii lipsa (<?= e((string) $openRacesCount) ?>)
        </button>
        <div class="dispatcher-open-races-panel d-none" id="open-races-panel" data-open-races-panel>
            <div class="dispatcher-open-races-head">
                <div class="dispatcher-open-races-head-main">
                    <div>
                        <strong>Clasificare lipsuri</strong>
                        <small class="text-muted d-block">
                            Fara ora sfarsit: <?= e((string) $openRacesMissingEndTimeCount) ?> |
                            Fara cheltuieli: <?= e((string) $openRacesMissingExpensesCount) ?>
                        </small>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-open-races-close aria-label="Inchide meniul alerte">
                        Inchide
                    </button>
                </div>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($openRacesRows as $openRace): ?>
                    <?php
                        $openRaceId = (int) ($openRace['id'] ?? 0);
                        if ($openRaceId <= 0) {
                            continue;
                        }
                        $openPlate = trim((string) ($openRace['nr_inmatriculare'] ?? ''));
                        $openDriver = trim((string) ($openRace['sofer_nume'] ?? ''));
                        $openBeneficiar = trim((string) ($openRace['beneficiar_nume'] ?? ''));
                        $openStartDate = (string) ($openRace['data_inceput'] ?? '');
                        $openStartTime = trim((string) ($openRace['ora_inceput'] ?? ''));
                        $openTransportType = (string) ($openRace['tip_transport'] ?? '');
                        $openTransportLabel = $transportTypes[$openTransportType] ?? '-';
                        $openMissingEndTime = (int) ($openRace['missing_end_time'] ?? 0) === 1;
                        $openMissingExpenses = (int) ($openRace['missing_expenses'] ?? 0) === 1;
                        $openExpenseCount = max(0, (int) ($openRace['expense_count'] ?? 0));
                        $openTitleParts = [];
                        if ($openPlate !== '') {
                            $openTitleParts[] = $openPlate;
                        }
                        if ($openDriver !== '') {
                            $openTitleParts[] = $openDriver;
                        }
                        $openTitle = $openTitleParts !== [] ? implode(' - ', $openTitleParts) : ('Cursa #' . $openRaceId);
                        $openMetaParts = [];
                        $openMetaParts[] = $openTransportLabel;
                        if ($openBeneficiar !== '') {
                            $openMetaParts[] = $openBeneficiar;
                        }
                        if ($openStartDate !== '') {
                            $openMetaParts[] = 'Start: ' . format_date_ro($openStartDate) . ($openStartTime !== '' ? (' ' . substr($openStartTime, 0, 5)) : '');
                        }
                        $openMissingParts = [];
                        if ($openMissingEndTime) {
                            $openMissingParts[] = 'fara ora sfarsit';
                        }
                        if ($openMissingExpenses) {
                            $openMissingParts[] = 'fara cheltuieli';
                        }
                        if ($openMissingParts !== []) {
                            $openMetaParts[] = 'Lipsuri: ' . implode(', ', $openMissingParts);
                        }
                        $openMetaParts[] = 'Cheltuieli: ' . $openExpenseCount;
                        $openMeta = implode(' | ', $openMetaParts);
                    ?>
                    <?php
                        $openEditQuery = ['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $openRaceId];
                        $openEditHash = '';
                        if ($openMissingEndTime) {
                            // Prioritate: daca lipseste ora de sfarsit, utilizatorul trebuie trimis direct acolo,
                            // inclusiv in cazurile in care lipsesc si cheltuielile.
                            $openEditQuery['focus'] = 'end_time';
                        } elseif ($openMissingExpenses) {
                            $openEditHash = '#expense-section';
                        }
                        $openEditUrl = build_query_url($openEditQuery) . $openEditHash;
                        $openEditLabel = $openMissingEndTime ? 'Completeaza ora' : ($openMissingExpenses ? 'Adauga cheltuieli' : 'Editeaza');
                    ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                        <div class="dispatcher-open-race-text">
                            <div class="fw-semibold"><?= e($openTitle) ?></div>
                            <div class="small text-muted"><?= e($openMeta) ?></div>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e($openEditUrl) ?>">
                            <?= e($openEditLabel) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($openRacesCount > count($openRacesRows)): ?>
                <div class="dispatcher-open-races-footer text-muted small">
                    Mai exista inca <?= e((string) ($openRacesCount - count($openRacesRows))) ?> curse neafisate in lista.
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($postCreateExpensePromptRaceId > 0): ?>
    <div class="modal fade" id="postCreateExpensePromptModal" tabindex="-1" aria-labelledby="postCreateExpensePromptTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="postCreateExpensePromptTitle">Cursa a fost adaugata</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
                </div>
                <div class="modal-body">
                    Vrei sa adaugi cheltuieli pe cursa acum?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Nu</button>
                    <a class="btn btn-primary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $postCreateExpensePromptRaceId]) . '#expense-section') ?>">Da</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAllEl = document.getElementById('bulk-race-select-all');
    const bulkDeleteBtnEl = document.getElementById('bulk-race-delete-btn');
    const raceCheckboxEls = Array.from(document.querySelectorAll('.bulk-race-checkbox'));

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

    var openRacesTabEl = document.querySelector('[data-open-races-tab]');
    var openRacesToggleEl = document.querySelector('[data-open-races-toggle]');
    var openRacesPanelEl = document.querySelector('[data-open-races-panel]');
    var openRacesCloseEl = document.querySelector('[data-open-races-close]');
    if (
        openRacesTabEl instanceof HTMLElement
        && openRacesToggleEl instanceof HTMLButtonElement
        && openRacesPanelEl instanceof HTMLElement
    ) {
        var isOpenRacesExpanded = function () {
            return openRacesToggleEl.getAttribute('aria-expanded') === 'true';
        };

        var setOpenRacesExpanded = function (expanded) {
            if (expanded === isOpenRacesExpanded()) {
                return;
            }
            openRacesToggleEl.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            openRacesPanelEl.classList.toggle('d-none', !expanded);
        };

        openRacesToggleEl.addEventListener('click', function () {
            setOpenRacesExpanded(!isOpenRacesExpanded());
        });

        if (openRacesCloseEl instanceof HTMLButtonElement) {
            openRacesCloseEl.addEventListener('click', function () {
                setOpenRacesExpanded(false);
                openRacesToggleEl.focus();
            });
        }

        document.addEventListener('click', function (event) {
            if (!isOpenRacesExpanded()) {
                return;
            }

            var targetEl = event.target;
            if (!(targetEl instanceof Node)) {
                return;
            }

            if (!openRacesTabEl.contains(targetEl)) {
                setOpenRacesExpanded(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isOpenRacesExpanded()) {
                setOpenRacesExpanded(false);
            }
        });
    }

    var postCreateExpensePromptModalEl = document.getElementById('postCreateExpensePromptModal');
    if (postCreateExpensePromptModalEl instanceof HTMLElement && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        var postCreateExpensePromptModal = new bootstrap.Modal(postCreateExpensePromptModalEl);
        postCreateExpensePromptModal.show();
    }
});
</script>

<?php if (!empty($maintenancePopupMessages)): ?>
    <div class="modal fade" id="kmRevizieAlertModal" tabindex="-1" aria-labelledby="kmRevizieAlertTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="kmRevizieAlertTitle">AlertÄƒ revizie vehicul</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ÃŽnchide"></button>
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
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Am Ã®nÈ›eles</button>
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

<script src="<?= e(url('assets/js/dispecer-curse.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/dispecer-curse.js'))) ?>"></script>

