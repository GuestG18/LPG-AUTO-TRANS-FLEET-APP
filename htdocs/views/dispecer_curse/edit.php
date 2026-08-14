<?php
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
$dispecerReturnUrl = (string) ($_SERVER['REQUEST_URI'] ?? build_query_url([
    'page' => 'dispecer_curse',
    'action' => 'edit',
    'id' => (int) ($race['id'] ?? 0),
]));
$selectedTransportType = (string) ($raceFormData['tip_transport'] ?? 'primar');
$isDistributionSelected = in_array($selectedTransportType, ['distributie', 'primar_distributie'], true);
$isPrimarySelected = in_array($selectedTransportType, ['primar', 'primar_tona'], true);
$isPrimaryDistributionSelected = $selectedTransportType === 'primar_distributie';
$isAgreedKmNamingSelected = in_array($selectedTransportType, ['primar', 'primar_distributie'], true);
$isKmTotalSelected = $isPrimarySelected || $isPrimaryDistributionSelected;
$isCompressorSelected = $selectedTransportType === 'compresor';

$selectedGoodsTypeKeys = [];
foreach ((array) ($raceFormData['tip_marfa'] ?? []) as $selectedGoodsTypeKey) {
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
$formatRaceDateInput = static function ($value): string {
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $matches) === 1) {
        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        return checkdate($month, $day, $year) ? sprintf('%02d/%02d/%04d', $day, $month, $year) : $raw;
    }

    if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $raw, $matches) === 1) {
        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = (int) $matches[3];

        return checkdate($month, $day, $year) ? sprintf('%02d/%02d/%04d', $day, $month, $year) : $raw;
    }

    return $raw;
};
$formStartTimeValueRaw = trim((string) ($raceFormData['ora_inceput'] ?? ''));
$formStartTimeValue = $formStartTimeValueRaw !== '' ? substr($formStartTimeValueRaw, 0, 5) : '';
$formEndTimeValueRaw = trim((string) ($raceFormData['ora_sfarsit'] ?? ''));
$formEndTimeValue = $formEndTimeValueRaw !== '' ? substr($formEndTimeValueRaw, 0, 5) : '';
$formDurationMinutes = null;
$formDurationMinutesRaw = $raceFormData['durata_cursa_minute'] ?? null;
if ($formDurationMinutesRaw !== null && $formDurationMinutesRaw !== '' && is_numeric((string) $formDurationMinutesRaw)) {
    $formDurationMinutes = max(0, (int) $formDurationMinutesRaw);
}
$formDurationPreviewText = $formDurationMinutes !== null
    ? 'Durata cursa calculata: ' . $formatDurationLabel($formDurationMinutes) . ' (' . $formDurationMinutes . ' min)'
    : 'Durata cursa se calculeaza automat dupa ora inceput/sfarsit.';

$raceId = (int) ($race['id'] ?? 0);
$editExpenseId = (int) ($expenseFormData['expense_id'] ?? 0);
$editingExpense = $expenseBeingEdited !== null || $editExpenseId > 0;
$existingExpenseDoc = is_array($expenseBeingEdited) ? (string) ($expenseBeingEdited['file_path'] ?? '') : '';
$existingExpenseDocName = is_array($expenseBeingEdited) ? (string) ($expenseBeingEdited['original_name'] ?? '') : '';
$existingExpenseDocUrl = $existingExpenseDoc !== '' ? url('uploads/curse_cheltuieli/' . rawurlencode($existingExpenseDoc)) : null;
$existingRefacturareDoc = is_array($expenseBeingEdited) ? (string) ($expenseBeingEdited['refacturare_document_path'] ?? '') : '';
$existingRefacturareDocName = is_array($expenseBeingEdited) ? (string) ($expenseBeingEdited['refacturare_document_original_name'] ?? '') : '';
$existingRefacturareDocUrl = $existingRefacturareDoc !== '' ? url('uploads/curse_cheltuieli/' . rawurlencode($existingRefacturareDoc)) : null;
$expenseRefacturareEnabled = (string) ($expenseFormData['refacturare_enabled'] ?? '0') === '1';
$expenseCategories = is_array($expenseCategories ?? null) ? $expenseCategories : [];
$expenseEntryTypes = is_array($expenseEntryTypes ?? null) ? $expenseEntryTypes : (array) ($expenseTypes ?? []);
unset($expenseEntryTypes['motorina']);
$selectedExpenseCategoryId = (string) ($expenseFormData['categorie_id'] ?? '');
if ($selectedExpenseCategoryId === '' && (string) ($expenseFormData['tip_cheltuiala'] ?? '') !== '') {
    foreach ($expenseCategories as $expenseCategory) {
        if ((string) ($expenseCategory['legacy_key'] ?? '') === (string) ($expenseFormData['tip_cheltuiala'] ?? '')) {
            $selectedExpenseCategoryId = (string) ($expenseCategory['id'] ?? '');
            break;
        }
    }
}
$selectedRefacturareExpenseType = (string) ($expenseFormData['refacturare_tip_cheltuiala'] ?? '');
if (!isset($expenseEntryTypes[$selectedRefacturareExpenseType])) {
    $selectedRefacturareExpenseType = '';
}
$showRefacturareRoadTaxDetails = $expenseRefacturareEnabled && $selectedRefacturareExpenseType === 'taxe_drum';

$expensesTotal = 0.0;
foreach ($expenses as $expenseRow) {
    $expensesTotal += (float) ($expenseRow['suma'] ?? 0);
}
$invoicedRefacturareTotal = (float) ($race['total_refacturare_facturata'] ?? 0);
$expensesTotal = max(0.0, $expensesTotal - $invoicedRefacturareTotal);
$raceExpenseStatus = (string) ($race['cheltuieli_status'] ?? 'pending');
if (!in_array($raceExpenseStatus, ['pending', 'not_applicable'], true)) {
    $raceExpenseStatus = 'pending';
}
$raceExpenseCount = max((int) ($race['expense_count'] ?? 0), count($expenses));
$raceHasExpenses = $raceExpenseCount > 0;
$raceExpenseStatusReturnUrl = build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId]) . '#expense-section';
$raceExpenseStatusChoice = $raceExpenseStatus === 'not_applicable' ? 'pending' : 'not_applicable';
$raceExpenseStatusButtonLabel = $raceExpenseStatus === 'not_applicable' ? 'Marcheaza lipsa cheltuieli' : 'Nu e cazul';
$raceExpenseStatusBadgeClass = $raceExpenseStatus === 'not_applicable' ? 'bg-secondary' : 'bg-warning text-dark';
$raceExpenseStatusLabel = $raceExpenseStatus === 'not_applicable' ? 'Nu e cazul' : 'Lipsa cheltuieli';
// Deep-link generic: ?focus=<cheie> deruleaza si evidentiaza campul corespunzator.
$focusFieldMap = [
    'end_time' => 'edit_race_end_datetime',
    'start_time' => 'edit_race_start_datetime',
    'loading_date' => 'edit_race_data_incarcare',
    'driver' => 'edit_race_driver_id',
    'beneficiary' => 'edit_race_beneficiar_id',
    'goods' => 'edit_race_tip_marfa',
    'loading_location' => 'edit_race_loc_incarcare_id',
    'departure_location' => 'edit_race_loc_plecare',
    'suction_location' => 'edit_race_loc_aspirare',
    'delivery_location' => 'edit_race_loc_livrare',
    'closing_location' => 'edit_race_loc_livrare_cursa',
    'km' => 'edit_race_km_cursa',
    'km_total' => 'edit_race_km_totali',
    'clients' => 'edit_race_nr_clienti',
    'quantity' => 'edit_race_cantitate_incarcata',
    'distribution_zone' => 'edit_race_zona_distributie_id',
    'aspiration_hours' => 'edit_race_ore_aspirare',
    'displacement_km' => 'edit_race_km_dislocare',
    'delivered_quantity' => 'edit_race_tona_livrata',
    'liquid_tons' => 'edit_race_tona_aspirata_lichida',
    'gas_tons' => 'edit_race_tona_aspirata_gazoasa',
];
$focusKey = trim((string) ($_GET['focus'] ?? ''));
$focusFieldId = (string) ($focusFieldMap[$focusKey] ?? '');
$focusEndTime = $focusKey === 'end_time';
$displayTotalFacturare = (float) ($raceFormData['total_facturare'] ?? 0) + $invoicedRefacturareTotal;
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h4 mb-0">Editeaza cursa</h2>
    <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Inapoi la lista</a>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white">
        <h3 class="h6 mb-0">Date cursa</h3>
    </div>
    <div class="card-body">
        <form method="post"
              action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'update', 'id' => $raceId])) ?>"
              class="dispatcher-race-form"
              data-zone-tariffs='<?= e($zoneTariffJson) ?>'
              data-zone-extra-km-costs='<?= e($zoneExtraKmJson) ?>'
              data-distribution-route-tariffs='<?= e($distributionRouteTariffMapJson) ?>'
              data-primary-route-km-map='<?= e($primaryRouteKmMapJson) ?>'
              data-beneficiary-pricing='<?= e($beneficiaryPricingJson) ?>'
              data-load-location-tariffs='<?= e($loadLocationTariffJson) ?>'
              data-vehicle-default-load-locations='<?= e($vehicleDefaultLoadLocationJson) ?>'
              data-vehicle-default-distribution-zones='<?= e($vehicleDefaultDistributionZoneJson) ?>'
              data-vehicle-garages='<?= e($vehicleGarageJson) ?>'
              data-load-locations-by-beneficiary='<?= e($loadLocationsByBeneficiaryJson) ?>'
              data-distribution-zones-by-beneficiary='<?= e($distributionZonesByBeneficiaryJson) ?>'
              data-vehicle-default-load-locations-by-beneficiary='<?= e($vehicleDefaultLoadLocationByBeneficiaryJson) ?>'
              data-vehicle-default-distribution-zones-by-beneficiary='<?= e($vehicleDefaultDistributionZoneByBeneficiaryJson) ?>'
              data-compresor-vehicles-by-beneficiary='<?= e($compressorVehicleByBeneficiaryJson) ?>'
              data-active-driver-vehicle-ids='<?= e($activeDriverVehicleIdsJson) ?>'
              data-drivers-by-vehicle='<?= e($driversByVehicleJson) ?>'
              data-invoiced-refacturare-total='<?= e((string) $invoicedRefacturareTotal) ?>'
              data-inactive-resource-status-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'inactive_resource_status'])) ?>"
              data-inactive-approval-mode="<?= (function_exists('can') && can('inactive_approvals', 'review')) ? 'admin' : 'user' ?>"
              data-inactive-approval-request-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'request_inactive_vehicle_approval'])) ?>"
              data-inactive-approval-cancel-url="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'cancel_inactive_vehicle_approval'])) ?>"
              data-inactive-trip-id="<?= e((string) $raceId) ?>"
              novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="inactive_approval_decision" value="<?= e((string) ($raceFormData['inactive_approval_decision'] ?? '')) ?>" data-inactive-approval-decision>
            <input type="hidden" name="inactive_approval_signature" value="" data-inactive-approval-signature>
            <input type="hidden" name="confirm_incomplete" value="">
            <datalist id="edit_race_time_options">
                <?php for ($hour = 0; $hour < 24; $hour++): ?>
                    <?php foreach (['00', '15', '30', '45'] as $minute): ?>
                        <option value="<?= e(sprintf('%02d:%s', $hour, $minute)) ?>"></option>
                    <?php endforeach; ?>
                <?php endfor; ?>
            </datalist>

            <div class="row g-3">
                <?php if (isset($raceFormErrors['inactive_resources'])): ?>
                    <div class="col-12">
                        <div class="alert alert-warning d-flex align-items-center gap-2 mb-0" role="alert">
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            <span><?= e((string) $raceFormErrors['inactive_resources']) ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="col-12 col-md-6 dispatcher-top-field">
                    <label class="form-label" for="edit_race_beneficiar_id">Beneficiar transport <span class="text-danger">*</span></label>
                    <select class="form-select <?= isset($raceFormErrors['beneficiar_id']) ? 'is-invalid' : '' ?>" id="edit_race_beneficiar_id" name="beneficiar_id" required>
                        <option value="">-- Selecteaza --</option>
                        <?php foreach ($beneficiaries as $beneficiary): ?>
                            <?php
                                $beneficiaryId = (int) ($beneficiary['id'] ?? 0);
                                $beneficiaryName = (string) ($beneficiary['nume'] ?? '-');
                                $beneficiaryIsActive = !empty($beneficiary['activ']);
                            ?>
                            <option value="<?= e((string) $beneficiaryId) ?>" <?= (string) ($raceFormData['beneficiar_id'] ?? '') === (string) $beneficiaryId ? 'selected' : '' ?>>
                                <?= e($beneficiaryName) ?><?= $beneficiaryIsActive ? '' : ' (inactiv)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($raceFormErrors['beneficiar_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['beneficiar_id']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 dispatcher-top-field">
                    <label class="form-label" for="edit_race_tip_transport">Tip Transport <span class="text-danger">*</span></label>
                    <select class="form-select <?= isset($raceFormErrors['tip_transport']) ? 'is-invalid' : '' ?>" id="edit_race_tip_transport" name="tip_transport" data-role="tip-transport" required>
                        <?php foreach ($transportTypes as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>" <?= (string) ($raceFormData['tip_transport'] ?? 'primar') === (string) $value ? 'selected' : '' ?>>
                                <?= e((string) $label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($raceFormErrors['tip_transport'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['tip_transport']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 dispatcher-top-field">
                    <label class="form-label" for="edit_race_vehicle_id">Nr. Inmatriculare <span class="text-danger">*</span></label>
                    <select class="form-select <?= isset($raceFormErrors['vehicle_id']) ? 'is-invalid' : '' ?>" id="edit_race_vehicle_id" name="vehicle_id" required>
                        <option value="">-- Selecteaza --</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                            <option
                                value="<?= e((string) $vehicleId) ?>"
                                data-capacitate-transport="<?= e((string) ($vehicle['capacitate_transport'] ?? '')) ?>"
                                <?= (string) ($raceFormData['vehicle_id'] ?? '') === (string) $vehicleId ? 'selected' : '' ?>
                            >
                                <?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?> - <?= e((string) ($vehicle['marca'] ?? '')) ?> <?= e((string) ($vehicle['model'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($raceFormErrors['vehicle_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['vehicle_id']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 dispatcher-top-field">
                    <label class="form-label" for="edit_race_driver_id">Sofer <span class="text-danger">*</span></label>
                    <select class="form-select <?= isset($raceFormErrors['driver_id']) ? 'is-invalid' : '' ?>" id="edit_race_driver_id" name="driver_id" required>
                        <option value="">-- Selecteaza mai intai vehiculul --</option>
                        <?php
                            $selectedVehicleForDriver = (int) ($raceFormData['vehicle_id'] ?? 0);
                            $selectedDriverId = (string) ($raceFormData['driver_id'] ?? '');
                            $driverOptions = $selectedVehicleForDriver > 0
                                ? (array) ($driversByVehicle[$selectedVehicleForDriver] ?? [])
                                : [];
                        ?>
                        <?php $storedDriverInList = false; ?>
                        <?php foreach ($driverOptions as $driver): ?>
                            <?php $driverId = (int) ($driver['id'] ?? 0); ?>
                            <?php if ($driverId <= 0) { continue; } ?>
                            <?php if ($selectedDriverId === (string) $driverId) { $storedDriverInList = true; } ?>
                            <option value="<?= e((string) $driverId) ?>" <?= $selectedDriverId === (string) $driverId ? 'selected' : '' ?>>
                                <?= e((string) ($driver['nume'] ?? '-')) ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (!$storedDriverInList && $selectedDriverId !== '' && (int) $selectedDriverId > 0): ?>
                            <?php // Soferul salvat pe cursa nu mai este asignat vehiculului: il pastram selectabil, nu il pierdem. ?>
                            <?php $storedDriverName = trim((string) ($raceFormData['sofer_nume'] ?? '')); ?>
                            <option value="<?= e($selectedDriverId) ?>" selected data-stored-out-of-scope="1">
                                <?= e($storedDriverName !== '' ? $storedDriverName : ('Sofer #' . $selectedDriverId)) ?>
                            </option>
                        <?php endif; ?>
                    </select>
                    <?php if (isset($raceFormErrors['driver_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['driver_id']) ?></div><?php endif; ?>
                    <div class="form-text">Soferii se incarca automat dupa vehiculul selectat.</div>
                </div>

                <div class="col-12 col-md-6 dispatcher-schedule-field" data-role="field-start-datetime">
                    <label class="form-label" for="edit_race_start_datetime">Data si ora inceput <span class="text-danger">*</span></label>
                    <?php
                        $startDateDisplayValue = $formatRaceDateInput($raceFormData['data_inceput'] ?? ($raceFormData['data_cursa'] ?? ''));
                        $startDateTimeDisplayValue = trim($startDateDisplayValue . ($formStartTimeValue !== '' ? ' ' . $formStartTimeValue : ''));
                        $startDateTimeHasError = isset($raceFormErrors['data_inceput']) || isset($raceFormErrors['ora_inceput']);
                    ?>
                    <div class="dispatcher-datetime-field" data-role="start-datetime-field">
                        <div class="input-group dispatcher-datetime-input-group">
                            <input
                                type="text"
                                class="form-control <?= $startDateTimeHasError ? 'is-invalid' : '' ?>"
                                id="edit_race_start_datetime"
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
                            id="edit_race_data_inceput"
                            name="data_inceput"
                            value="<?= e($startDateDisplayValue) ?>"
                            required
                        >
                        <input
                            type="hidden"
                            id="edit_race_ora_inceput"
                            name="ora_inceput"
                            value="<?= e($formStartTimeValue) ?>"
                            data-role="ora-inceput"
                        >
                    </div>
                    <?php if (isset($raceFormErrors['data_inceput'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['data_inceput']) ?></div><?php endif; ?>
                    <?php if (isset($raceFormErrors['ora_inceput'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['ora_inceput']) ?></div><?php endif; ?>
                </div>
                <div class="col-12 col-md-6 dispatcher-schedule-field" data-role="field-data-incarcare">
                    <label class="form-label" for="edit_race_data_incarcare">Data incarcare</label>
                    <?php
                        $loadingDateRawValue = trim((string) ($raceFormData['data_incarcare'] ?? ''));
                        $loadingDateIsoValue = $loadingDateRawValue;
                        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $loadingDateRawValue, $loadingDateParts)) {
                            $loadingDateIsoValue = sprintf('%04d-%02d-%02d', (int) $loadingDateParts[3], (int) $loadingDateParts[2], (int) $loadingDateParts[1]);
                        }
                    ?>
                    <div class="input-group fleet-date-field">
                        <input type="text" class="form-control js-date-display-input <?= isset($raceFormErrors['data_incarcare']) ? 'is-invalid' : '' ?>" id="edit_race_data_incarcare" name="data_incarcare" value="<?= e($formatRaceDateInput($loadingDateRawValue)) ?>" placeholder="dd/mm/yyyy" inputmode="numeric" maxlength="10" autocomplete="off" data-date-picker-id="edit_race_data_incarcare_picker">
                        <button type="button" class="btn btn-outline-secondary js-date-picker-button" data-date-picker-target="edit_race_data_incarcare_picker" aria-label="Deschide calendarul pentru data incarcarii"><i class="bi bi-calendar3" aria-hidden="true"></i></button>
                        <input type="date" id="edit_race_data_incarcare_picker" class="fleet-date-picker-native" value="<?= e($loadingDateIsoValue) ?>" tabindex="-1" aria-hidden="true">
                    </div>
                    <?php if (isset($raceFormErrors['data_incarcare'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['data_incarcare']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 dispatcher-schedule-field">
                    <label class="form-label" for="edit_race_end_datetime">Data si ora sfarsit <span class="text-danger">*</span></label>
                    <?php
                        $endDateDisplayValue = $formatRaceDateInput($raceFormData['data_sfarsit'] ?? ($raceFormData['data_cursa'] ?? ''));
                        $endDateTimeDisplayValue = trim($endDateDisplayValue . ($formEndTimeValue !== '' ? ' ' . $formEndTimeValue : ''));
                        $endDateTimeHasError = isset($raceFormErrors['data_sfarsit']) || isset($raceFormErrors['ora_sfarsit']);
                    ?>
                    <div class="dispatcher-datetime-field" data-role="end-datetime-field">
                        <div class="input-group dispatcher-datetime-input-group">
                            <input
                                type="text"
                                class="form-control <?= $endDateTimeHasError ? 'is-invalid' : '' ?><?= $focusEndTime ? ' dispatcher-end-time-focus' : '' ?>"
                                id="edit_race_end_datetime"
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
                            id="edit_race_data_sfarsit"
                            name="data_sfarsit"
                            value="<?= e($endDateDisplayValue) ?>"
                            required
                        >
                        <input
                            type="hidden"
                            id="edit_race_ora_sfarsit"
                            name="ora_sfarsit"
                            value="<?= e($formEndTimeValue) ?>"
                            data-role="ora-sfarsit"
                            title="<?= e($formDurationPreviewText) ?>"
                        >
                    </div>
                    <?php if (isset($raceFormErrors['data_sfarsit'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['data_sfarsit']) ?></div><?php endif; ?>
                    <?php if (isset($raceFormErrors['ora_sfarsit'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['ora_sfarsit']) ?></div><?php endif; ?>
                    <div class="form-text d-none dispatcher-hover-note" data-role="durata-cursa-hint" data-default-text="<?= e($formDurationPreviewText) ?>"><?= e($formDurationPreviewText) ?></div>
                </div>

                <div class="col-12 col-md-6 dispatcher-primary-grid-field" data-role="field-loc-incarcare">
                    <label class="form-label" for="edit_race_loc_incarcare_id">Loc incarcare <span class="text-danger">*</span></label>
                    <select class="form-select <?= isset($raceFormErrors['loc_incarcare_id']) ? 'is-invalid' : '' ?>" id="edit_race_loc_incarcare_id" name="loc_incarcare_id" required>
                        <option value="">-- Selecteaza --</option>
                        <?php $selectedLoadLocationId = (string) ($raceFormData['loc_incarcare_id'] ?? ''); ?>
                        <?php $storedLoadLocationInList = false; ?>
                        <?php foreach ($loadLocations as $location): ?>
                            <?php $locationId = (int) ($location['id'] ?? 0); ?>
                            <?php if ($selectedLoadLocationId === (string) $locationId) { $storedLoadLocationInList = true; } ?>
                            <option value="<?= e((string) $locationId) ?>" <?= $selectedLoadLocationId === (string) $locationId ? 'selected' : '' ?>>
                                <?= e((string) ($location['nume'] ?? '-')) ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (!$storedLoadLocationInList && (int) $selectedLoadLocationId > 0): ?>
                            <?php // Locul salvat pe cursa nu mai este in lista activa: il pastram selectabil, nu il pierdem. ?>
                            <?php $storedLoadLocationName = trim((string) ($raceFormData['loc_incarcare_nume'] ?? '')); ?>
                            <option value="<?= e($selectedLoadLocationId) ?>" selected data-stored-out-of-scope="1">
                                <?= e($storedLoadLocationName !== '' ? $storedLoadLocationName : ('Loc #' . $selectedLoadLocationId)) ?>
                            </option>
                        <?php endif; ?>
                    </select>
                    <?php if (isset($raceFormErrors['loc_incarcare_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['loc_incarcare_id']) ?></div><?php endif; ?>
                    <div class="form-text text-muted <?= $isDistributionSelected ? '' : 'd-none' ?>" data-role="distributie-note-loc">
                        Pentru Distributie / Primar+Distributie: regula de ruta are prioritate pe perechile configurate bidirectional (Loc - Zona). Daca nu exista pereche, se aplica fallback loc/zona/beneficiar.
                    </div>
                    <div class="form-text text-muted <?= $isPrimarySelected ? '' : 'd-none' ?>" data-role="primar-note-loc">
                        Pentru Primar km / Primar tone: sunt afisate doar locurile din Setari Primar, iar Km efectuati este luat automat din perechea Loc - Zona.
                    </div>
                </div>

                <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-grid-field" data-role="field-loc-plecare">
                    <label class="form-label" for="edit_race_loc_plecare">Loc plecare <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= isset($raceFormErrors['loc_plecare']) ? 'is-invalid' : '' ?>" id="edit_race_loc_plecare" name="loc_plecare" value="<?= e((string) ($raceFormData['loc_plecare'] ?? '')) ?>" data-role="loc-plecare">
                    <?php if (isset($raceFormErrors['loc_plecare'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['loc_plecare']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-grid-field" data-role="field-loc-aspirare">
                    <label class="form-label" for="edit_race_loc_aspirare">Loc aspirare <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= isset($raceFormErrors['loc_aspirare']) ? 'is-invalid' : '' ?>" id="edit_race_loc_aspirare" name="loc_aspirare" value="<?= e((string) ($raceFormData['loc_aspirare'] ?? '')) ?>" data-role="loc-aspirare">
                    <?php if (isset($raceFormErrors['loc_aspirare'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['loc_aspirare']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-grid-field" data-role="field-loc-livrare">
                    <label class="form-label" for="edit_race_loc_livrare">Loc livrare <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= isset($raceFormErrors['loc_livrare']) ? 'is-invalid' : '' ?>" id="edit_race_loc_livrare" name="loc_livrare" value="<?= e((string) ($raceFormData['loc_livrare'] ?? '')) ?>" data-role="loc-livrare">
                    <?php if (isset($raceFormErrors['loc_livrare'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['loc_livrare']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-grid-field" data-role="field-loc-livrare-cursa">
                    <label class="form-label" for="edit_race_loc_livrare_cursa">Loc inchidere cursa <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= isset($raceFormErrors['loc_livrare_cursa']) ? 'is-invalid' : '' ?>" id="edit_race_loc_livrare_cursa" name="loc_livrare_cursa" value="<?= e((string) ($raceFormData['loc_livrare_cursa'] ?? '')) ?>" data-role="loc-livrare-cursa">
                    <?php if (isset($raceFormErrors['loc_livrare_cursa'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['loc_livrare_cursa']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 dispatcher-primary-grid-field dispatcher-compressor-grid-field dispatcher-compressor-metric-field" data-role="field-tip-marfa">
                    <label class="form-label" for="edit_race_tip_marfa">Tip marfa <span class="text-danger">*</span></label>
                    <div class="dropdown transport-multiselect-dropdown goods-multiselect-dropdown" data-role="goods-type-dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start transport-multiselect-toggle <?= isset($raceFormErrors['tip_marfa']) ? 'is-invalid' : '' ?>" type="button" id="edit_race_tip_marfa" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                            <span class="goods-multiselect-label" data-default-label="-- Selecteaza --"><?= e($selectedGoodsTypeButtonLabel) ?></span>
                        </button>
                        <div class="dropdown-menu w-100 transport-multiselect-menu p-2" aria-labelledby="edit_race_tip_marfa">
                            <?php foreach (($goodsTypeOptions ?? []) as $goodsTypeKey => $goodsTypeLabel): ?>
                                <label class="dropdown-item d-flex align-items-center gap-2 px-2 py-1 transport-multiselect-option">
                                    <input class="form-check-input m-0" type="checkbox" name="tip_marfa[]" value="<?= e((string) $goodsTypeKey) ?>" <?= in_array((string) $goodsTypeKey, $selectedGoodsTypeKeys, true) ? 'checked' : '' ?>>
                                    <span><?= e((string) $goodsTypeLabel) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-text">Poti selecta unul sau mai multe tipuri de marfa.</div>
                    <?php if (isset($raceFormErrors['tip_marfa'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['tip_marfa']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 dispatcher-primary-grid-field" data-role="field-cantitate">
                    <label class="form-label" for="edit_race_cantitate_incarcata">Cantitate incarcata</label>
                    <input type="number" class="form-control <?= isset($raceFormErrors['cantitate_incarcata']) ? 'is-invalid' : '' ?>" id="edit_race_cantitate_incarcata" name="cantitate_incarcata" step="0.01" min="0" value="<?= e((string) ($raceFormData['cantitate_incarcata'] ?? '')) ?>" data-role="cantitate">
                    <div class="form-text text-muted">Valoarea introdusa este folosita direct in calcule, fara conversie automata.</div>
                    <?php if (isset($raceFormErrors['cantitate_incarcata'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['cantitate_incarcata']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 dispatcher-primary-grid-field" data-role="field-capacitate-transport">
                    <label class="form-label" for="edit_race_capacitate_transport">Capacitate transport</label>
                    <input type="number" class="form-control <?= isset($raceFormErrors['capacitate_transport']) ? 'is-invalid' : '' ?>" id="edit_race_capacitate_transport" name="capacitate_transport" step="0.01" min="0" value="<?= e((string) ($raceFormData['capacitate_transport'] ?? '')) ?>" data-role="capacitate-transport" readonly>
                    <?php if (isset($raceFormErrors['capacitate_transport'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['capacitate_transport']) ?></div><?php endif; ?>
                    <div class="form-text">Se completeaza automat din fisa vehiculului.</div>
                </div>

                <div class="col-12 col-md-6" data-role="field-km">
                    <label class="form-label" for="edit_race_km_cursa" data-role="km-label" data-default-label="Km efectuati" data-primary-km-label="Km agreati"><?= $isAgreedKmNamingSelected ? 'Km agreati' : 'Km efectuati' ?></label>
                    <input type="number" class="form-control <?= isset($raceFormErrors['km_cursa']) ? 'is-invalid' : '' ?>" id="edit_race_km_cursa" name="km_cursa" min="0" step="1" value="<?= e((string) ($raceFormData['km_cursa'] ?? '')) ?>" data-role="km">
                    <?php if (isset($raceFormErrors['km_cursa'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['km_cursa']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 <?= $isDistributionSelected ? '' : 'd-none' ?>" data-role="field-nr-clienti">
                    <label class="form-label" for="edit_race_nr_clienti">Nr. clienti</label>
                    <input type="number" class="form-control <?= isset($raceFormErrors['nr_clienti']) ? 'is-invalid' : '' ?>" id="edit_race_nr_clienti" name="nr_clienti" min="0" step="1" value="<?= e((string) ($raceFormData['nr_clienti'] ?? '')) ?>">
                    <?php if (isset($raceFormErrors['nr_clienti'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['nr_clienti']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6" data-role="field-zona">
                    <label class="form-label" for="edit_race_zona_distributie_id" data-role="zona-label" data-default-label="Zona distributie" data-primary-label="Zona descarcare" data-primary-km-label="Loc descarcare">Zona distributie</label>
                    <select class="form-select <?= isset($raceFormErrors['zona_distributie_id']) ? 'is-invalid' : '' ?>" id="edit_race_zona_distributie_id" name="zona_distributie_id" data-role="zona">
                        <option value="">-- Selecteaza --</option>
                        <?php $selectedZoneId = (string) ($raceFormData['zona_distributie_id'] ?? ''); ?>
                        <?php $storedZoneInList = false; ?>
                        <?php foreach ($distributionZones as $zone): ?>
                            <?php $zoneId = (int) ($zone['id'] ?? 0); ?>
                            <?php if ($selectedZoneId === (string) $zoneId) { $storedZoneInList = true; } ?>
                            <?php $zoneExtraKmCost = (float) ($zone['cost_extra_km'] ?? 0); ?>
                            <option value="<?= e((string) $zoneId) ?>" <?= (string) ($raceFormData['zona_distributie_id'] ?? '') === (string) $zoneId ? 'selected' : '' ?>>
                                <?= e((string) ($zone['nume'] ?? '-')) ?>
                                (tarif zonÃ„Æ’: <?= e(format_number_ro((float) ($zone['tarif_distributie'] ?? 0), 2)) ?> lei<?php if ($zoneExtraKmCost > 0): ?>, extra km: <?= e(format_number_ro($zoneExtraKmCost, 2)) ?> lei/km<?php endif; ?>)
                            </option>
                        <?php endforeach; ?>
                        <?php if (!$storedZoneInList && (int) $selectedZoneId > 0): ?>
                            <?php // Zona salvata pe cursa nu mai este in lista activa: o pastram selectabila, nu o pierdem. ?>
                            <?php $storedZoneName = trim((string) ($raceFormData['zona_distributie_nume'] ?? '')); ?>
                            <option value="<?= e($selectedZoneId) ?>" selected data-stored-out-of-scope="1">
                                <?= e($storedZoneName !== '' ? $storedZoneName : ('Zona #' . $selectedZoneId)) ?>
                            </option>
                        <?php endif; ?>
                    </select>
                    <?php if (isset($raceFormErrors['zona_distributie_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['zona_distributie_id']) ?></div><?php endif; ?>
                    <div class="form-text text-muted <?= $isDistributionSelected ? '' : 'd-none' ?>" data-role="distributie-note-zone">
                        Prioritate calcul: regula de ruta (Loc - Zona), apoi regulile loc/zona, apoi fallback beneficiar. Distributie = Cantitate x Tariful activ; Primar+Distributie = Cantitate x Tariful activ + Km x Cost extra/km activ.
                    </div>
                    <div class="form-text text-muted <?= $isPrimarySelected ? '' : 'd-none' ?>" data-role="primar-note-zone">
                        Pentru Primar km / Primar tone, selectia Loc - Zona este filtrata din Setari Primar si se aplica bidirectional.
                    </div>
                </div>

                <div class="col-12 col-md-6 <?= $isKmTotalSelected ? '' : 'd-none' ?>" data-role="field-km-totali">
                    <label class="form-label" for="edit_race_km_totali" data-role="km-total-label" data-default-label="Km totali" data-primary-km-label="Km efectuati"><?= $isAgreedKmNamingSelected ? 'Km efectuati' : 'Km totali' ?></label>
                    <input type="number" class="form-control <?= isset($raceFormErrors['km_totali']) ? 'is-invalid' : '' ?>" id="edit_race_km_totali" name="km_totali" min="0" step="1" value="<?= e((string) ($raceFormData['km_totali'] ?? '')) ?>" data-role="km-totali">
                    <?php if (isset($raceFormErrors['km_totali'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['km_totali']) ?></div><?php endif; ?>
                    <div class="form-text text-muted <?= $isPrimaryDistributionSelected ? '' : 'd-none' ?>" data-role="km-distributie-calculation">Cost/km Distributie (calcul): Km distributie = Km efectuati - Km agreati; Cost/km Distributie = Cost distributie (Pret tona x tone) / Km distributie.</div>
                </div>

                <div class="col-12 col-md-6 dispatcher-compressor-metric-field" data-role="field-ore-aspirare">
                    <label class="form-label" for="edit_race_ore_aspirare">Ore aspirare</label>
                    <input type="text" class="form-control <?= isset($raceFormErrors['ore_aspirare']) ? 'is-invalid' : '' ?>" id="edit_race_ore_aspirare" name="ore_aspirare" value="<?= e((string) ($raceFormData['ore_aspirare'] ?? '')) ?>" data-role="ore-aspirare" placeholder="ex: 2h sau 2">
                    <?php if (isset($raceFormErrors['ore_aspirare'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['ore_aspirare']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-metric-field" data-role="field-tona-aspirata-lichida">
                    <label class="form-label" for="edit_race_tona_aspirata_lichida">Tona lichida aspirata</label>
                    <input type="number" class="form-control <?= isset($raceFormErrors['tona_aspirata_lichida']) ? 'is-invalid' : '' ?>" id="edit_race_tona_aspirata_lichida" name="tona_aspirata_lichida" step="0.01" min="0" value="<?= e((string) ($raceFormData['tona_aspirata_lichida'] ?? '')) ?>" data-role="tona-aspirata-lichida">
                    <?php if (isset($raceFormErrors['tona_aspirata_lichida'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['tona_aspirata_lichida']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-metric-field" data-role="field-tona-aspirata-gazoasa">
                    <label class="form-label" for="edit_race_tona_aspirata_gazoasa">Tona gazoasa aspirata</label>
                    <input type="number" class="form-control <?= isset($raceFormErrors['tona_aspirata_gazoasa']) ? 'is-invalid' : '' ?>" id="edit_race_tona_aspirata_gazoasa" name="tona_aspirata_gazoasa" step="0.01" min="0" value="<?= e((string) ($raceFormData['tona_aspirata_gazoasa'] ?? '')) ?>" data-role="tona-aspirata-gazoasa">
                    <?php if (isset($raceFormErrors['tona_aspirata_gazoasa'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['tona_aspirata_gazoasa']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 dispatcher-compressor-metric-field" data-role="field-tona-livrata">
                    <label class="form-label" for="edit_race_tona_livrata">Cantitate livrata (tone)</label>
                    <input type="number" class="form-control <?= isset($raceFormErrors['tona_livrata']) ? 'is-invalid' : '' ?>" id="edit_race_tona_livrata" name="tona_livrata" step="0.01" min="0" value="<?= e((string) ($raceFormData['tona_livrata'] ?? '')) ?>" data-role="tona-livrata">
                    <?php if (isset($raceFormErrors['tona_livrata'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['tona_livrata']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 dispatcher-compressor-metric-field" data-role="field-km-dislocare">
                    <label class="form-label" for="edit_race_km_dislocare">Km efectuati</label>
                    <input type="number" class="form-control <?= isset($raceFormErrors['km_dislocare']) ? 'is-invalid' : '' ?>" id="edit_race_km_dislocare" name="km_dislocare" step="0.01" min="0" value="<?= e((string) ($raceFormData['km_dislocare'] ?? '')) ?>" data-role="km-dislocare">
                    <?php if (isset($raceFormErrors['km_dislocare'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['km_dislocare']) ?></div><?php endif; ?>
                </div>

                <div class="col-12 col-md-6 dispatcher-compressor-metric-field" data-role="preview-total-field">
                    <label class="form-label">Total facturare (estimare)</label>
                    <div class="dispatcher-total-preview" data-role="total-preview"><?= e(format_number_ro($displayTotalFacturare, 2)) ?> lei</div>
                </div>

                <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-primar-field">
                    <label class="form-label">Cost/km Primar</label>
                    <div class="dispatcher-total-preview" data-role="cost-km-primar-preview"><?= e(format_number_ro((float) ($raceFormData['cost_km_primar'] ?? 0), 2)) ?> lei/km</div>
                </div>

                <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-distributie-field">
                    <label class="form-label">Cost/km Distributie</label>
                    <div class="dispatcher-total-preview" data-role="cost-km-distributie-preview"><?= e(format_number_ro((float) ($raceFormData['cost_km_distributie'] ?? 0), 2)) ?> lei/km</div>
                </div>

                <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-mixt-field">
                    <label class="form-label">Cost/km Mixt</label>
                    <div class="dispatcher-total-preview" data-role="cost-km-mixt-preview"><?= e(format_number_ro((float) ($raceFormData['cost_km_mixt'] ?? 0), 2)) ?> lei/km</div>
                </div>

                <?php /* Statusul de facturare nu se mai editeaza aici: se schimba doar din Centralizator Facturare. */ ?>

                <div class="col-12">
                    <label class="form-label" for="edit_race_observatii">Observatii</label>
                    <textarea class="form-control <?= isset($raceFormErrors['observatii']) ? 'is-invalid' : '' ?>" id="edit_race_observatii" name="observatii" rows="3"><?= e((string) ($raceFormData['observatii'] ?? '')) ?></textarea>
                    <?php if (isset($raceFormErrors['observatii'])): ?><div class="invalid-feedback d-block"><?= e((string) $raceFormErrors['observatii']) ?></div><?php endif; ?>
                </div>
            </div>

            <div class="mt-3 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Salveaza cursa</button>
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Inapoi</a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 align-items-start dispatcher-expense-layout <?= $expenseRefacturareEnabled ? 'has-refacturare' : '' ?>" data-role="expense-layout">
    <div class="col-12 col-xl-5 dispatcher-expense-form-column" id="expense-section">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h3 class="h6 mb-0"><?= $editingExpense ? 'Editeaza cheltuiala' : 'Adauga cheltuiala' ?></h3>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <?php if (!$raceHasExpenses): ?>
                        <span class="badge <?= e($raceExpenseStatusBadgeClass) ?>"><?= e($raceExpenseStatusLabel) ?></span>
                        <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'update_expense_status'])) ?>" class="m-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= e((string) $raceId) ?>">
                            <input type="hidden" name="cheltuieli_choice" value="<?= e($raceExpenseStatusChoice) ?>">
                            <input type="hidden" name="return_url" value="<?= e($raceExpenseStatusReturnUrl) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= e($raceExpenseStatusButtonLabel) ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($editingExpense): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId])) ?>">Anuleaza editarea</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <form method="post"
                      action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'save_expense', 'id' => $raceId])) ?>"
                      enctype="multipart/form-data"
                      class="dispatcher-expense-form <?= $expenseRefacturareEnabled ? 'has-refacturare' : '' ?>"
                      data-role="expense-form"
                      novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="expense_id" value="<?= e((string) ($expenseFormData['expense_id'] ?? '')) ?>">
                    <input type="hidden" name="race_id" value="<?= e((string) $raceId) ?>">
                    <input type="hidden" name="return_to" value="edit">

                    <div class="row g-2 mb-3 align-items-start expense-type-row">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="expense_tip_cheltuiala">Tip cheltuiala <span class="text-danger">*</span></label>
                            <select class="form-select <?= (isset($expenseFormErrors['categorie_id']) || isset($expenseFormErrors['tip_cheltuiala'])) ? 'is-invalid' : '' ?>" id="expense_tip_cheltuiala" name="categorie_id" required>
                                <option value="" <?= $selectedExpenseCategoryId === '' ? 'selected' : '' ?>>-- Selecteaza tipul --</option>
                                <?php foreach ($expenseCategories as $category): ?>
                                    <?php $categoryId = (int) ($category['id'] ?? 0); ?>
                                    <?php if ($categoryId <= 0): continue; endif; ?>
                                    <option
                                        value="<?= e((string) $categoryId) ?>"
                                        data-legacy-key="<?= e((string) ($category['legacy_key'] ?? '')) ?>"
                                        <?= $selectedExpenseCategoryId === (string) $categoryId ? 'selected' : '' ?>
                                    >
                                        <?= e((string) ($category['nume'] ?? '-')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Motorina se introduce separat in modulul Alimentari.</div>
                            <?php if (isset($expenseFormErrors['categorie_id']) || isset($expenseFormErrors['tip_cheltuiala'])): ?><div class="invalid-feedback d-block"><?= e((string) ($expenseFormErrors['categorie_id'] ?? $expenseFormErrors['tip_cheltuiala'])) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6">
                            <div class="form-check mb-2">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    value="1"
                                    id="expense_refacturare_enabled"
                                    name="refacturare_enabled"
                                    data-role="expense-refacturare-toggle"
                                    <?= $expenseRefacturareEnabled ? 'checked' : '' ?>
                                >
                                <label class="form-check-label" for="expense_refacturare_enabled">Refacturare</label>
                            </div>
                            <div class="<?= $expenseRefacturareEnabled ? '' : 'd-none' ?>" data-role="expense-refacturare-menu">
                                <label class="form-label visually-hidden" for="expense_refacturare_tip_cheltuiala">Refacturare</label>
                                <select
                                    class="form-select <?= isset($expenseFormErrors['refacturare_tip_cheltuiala']) ? 'is-invalid' : '' ?>"
                                    id="expense_refacturare_tip_cheltuiala"
                                    name="refacturare_tip_cheltuiala"
                                    <?= $expenseRefacturareEnabled ? '' : 'disabled' ?>
                                >
                                    <option value="" <?= $selectedRefacturareExpenseType === '' ? 'selected' : '' ?>>-- Selecteaza tipul --</option>
                                    <?php foreach ($expenseEntryTypes as $value => $label): ?>
                                        <option value="<?= e((string) $value) ?>" <?= $selectedRefacturareExpenseType === (string) $value ? 'selected' : '' ?>>
                                            <?= e((string) $label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($expenseFormErrors['refacturare_tip_cheltuiala'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_tip_cheltuiala']) ?></div><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="expense-main-panel">
                    <div class="mb-3 d-none expense-main-field" data-role="expense-road-tax-breakdown">
                        <label class="form-label mb-2">Detalii Taxe drum</label>
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-4"><strong>Taxa acces</strong></div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['taxa_acces_bucati']) ? 'is-invalid' : '' ?>"
                                    id="expense_taxa_acces_bucati"
                                    name="taxa_acces_bucati"
                                    min="0"
                                    step="1"
                                    placeholder="Bucati"
                                    value="<?= e((string) ($expenseFormData['taxa_acces_bucati'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['taxa_acces_bucati'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['taxa_acces_bucati']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['taxa_acces_pret']) ? 'is-invalid' : '' ?>"
                                    id="expense_taxa_acces_pret"
                                    name="taxa_acces_pret"
                                    min="0"
                                    step="0.01"
                                    placeholder="Pret / buc"
                                    value="<?= e((string) ($expenseFormData['taxa_acces_pret'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['taxa_acces_pret'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['taxa_acces_pret']) ?></div><?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-4"><strong>Port</strong></div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['port_bucati']) ? 'is-invalid' : '' ?>"
                                    id="expense_port_bucati"
                                    name="port_bucati"
                                    min="0"
                                    step="1"
                                    placeholder="Bucati"
                                    value="<?= e((string) ($expenseFormData['port_bucati'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['port_bucati'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['port_bucati']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['port_pret']) ? 'is-invalid' : '' ?>"
                                    id="expense_port_pret"
                                    name="port_pret"
                                    min="0"
                                    step="0.01"
                                    placeholder="Pret / buc"
                                    value="<?= e((string) ($expenseFormData['port_pret'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['port_pret'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['port_pret']) ?></div><?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-2">
                            <div class="col-12 col-md-4"><strong>Trece</strong></div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['trece_bucati']) ? 'is-invalid' : '' ?>"
                                    id="expense_trece_bucati"
                                    name="trece_bucati"
                                    min="0"
                                    step="1"
                                    placeholder="Bucati"
                                    value="<?= e((string) ($expenseFormData['trece_bucati'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['trece_bucati'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['trece_bucati']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['trece_pret']) ? 'is-invalid' : '' ?>"
                                    id="expense_trece_pret"
                                    name="trece_pret"
                                    min="0"
                                    step="0.01"
                                    placeholder="Pret / buc"
                                    value="<?= e((string) ($expenseFormData['trece_pret'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['trece_pret'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['trece_pret']) ?></div><?php endif; ?>
                            </div>
                        </div>

                        <div class="form-text mt-2">Suma se calculeaza automat din: bucati x pret pentru fiecare taxa.</div>
                    </div>

                    <div class="mb-3 expense-main-field">
                        <label class="form-label" for="expense_suma">Suma <span class="text-danger">*</span></label>
                        <input type="number" class="form-control <?= isset($expenseFormErrors['suma']) ? 'is-invalid' : '' ?>" id="expense_suma" name="suma" min="0.01" step="0.01" value="<?= e((string) ($expenseFormData['suma'] ?? '')) ?>" required>
                        <?php if (isset($expenseFormErrors['suma'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['suma']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3 expense-main-field">
                        <label class="form-label" for="expense_data_cheltuiala">Data cheltuiala <span class="text-danger">*</span></label>
                        <input type="date" class="form-control <?= isset($expenseFormErrors['data_cheltuiala']) ? 'is-invalid' : '' ?>" id="expense_data_cheltuiala" name="data_cheltuiala" value="<?= e((string) ($expenseFormData['data_cheltuiala'] ?? '')) ?>" required>
                        <?php if (isset($expenseFormErrors['data_cheltuiala'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['data_cheltuiala']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3 expense-main-field">
                        <label class="form-label" for="expense_observatii">Observatii</label>
                        <textarea class="form-control <?= isset($expenseFormErrors['observatii']) ? 'is-invalid' : '' ?>" id="expense_observatii" name="observatii" rows="3"><?= e((string) ($expenseFormData['observatii'] ?? '')) ?></textarea>
                        <?php if (isset($expenseFormErrors['observatii'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['observatii']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3 expense-main-field">
                        <label class="form-label" for="expense_document_upload">Document doveditor (upload)</label>
                        <input type="file" class="form-control <?= isset($expenseFormErrors['document_upload']) ? 'is-invalid' : '' ?>" id="expense_document_upload" name="document_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                        <div class="form-text">Formate acceptate: PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB.</div>
                        <?php if (isset($expenseFormErrors['document_upload'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['document_upload']) ?></div><?php endif; ?>
                    </div>

                    <?php if ($existingExpenseDocUrl !== null): ?>
                        <div class="alert alert-light border expense-main-field">
                            <div class="small text-muted mb-1">Document existent</div>
                            <a href="<?= e($existingExpenseDocUrl) ?>" target="_blank" rel="noopener">
                                <?= e($existingExpenseDocName !== '' ? $existingExpenseDocName : basename($existingExpenseDoc)) ?>
                            </a>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" value="1" id="expense_sterge_document" name="sterge_document">
                                <label class="form-check-label" for="expense_sterge_document">
                                    Sterge documentul curent la salvare
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary expense-submit-row" name="submit_intent" value="expense"><?= $editingExpense ? 'Actualizeaza cheltuiala' : 'Adauga cheltuiala' ?></button>
                    </div>
                    <div class="border rounded p-3 mb-3 expense-refacturare-panel <?= $expenseRefacturareEnabled ? '' : 'd-none' ?>" data-role="expense-refacturare-fields">
                        <div class="fw-semibold mb-3">Detalii Refacturare</div>

                    <div class="mb-3 <?= $showRefacturareRoadTaxDetails ? '' : 'd-none' ?>" data-role="refacturare-road-tax-breakdown">
                        <label class="form-label mb-2">Detalii Refacturare Taxe drum</label>
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-4"><strong>Taxa acces</strong></div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['refacturare_taxa_acces_bucati']) ? 'is-invalid' : '' ?>"
                                    id="expense_refacturare_taxa_acces_bucati"
                                    name="refacturare_taxa_acces_bucati"
                                    min="0"
                                    step="1"
                                    placeholder="Bucati"
                                    value="<?= e((string) ($expenseFormData['refacturare_taxa_acces_bucati'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['refacturare_taxa_acces_bucati'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_taxa_acces_bucati']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['refacturare_taxa_acces_pret']) ? 'is-invalid' : '' ?>"
                                    id="expense_refacturare_taxa_acces_pret"
                                    name="refacturare_taxa_acces_pret"
                                    min="0"
                                    step="0.01"
                                    placeholder="Pret / buc"
                                    value="<?= e((string) ($expenseFormData['refacturare_taxa_acces_pret'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['refacturare_taxa_acces_pret'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_taxa_acces_pret']) ?></div><?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-4"><strong>Port</strong></div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['refacturare_port_bucati']) ? 'is-invalid' : '' ?>"
                                    id="expense_refacturare_port_bucati"
                                    name="refacturare_port_bucati"
                                    min="0"
                                    step="1"
                                    placeholder="Bucati"
                                    value="<?= e((string) ($expenseFormData['refacturare_port_bucati'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['refacturare_port_bucati'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_port_bucati']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['refacturare_port_pret']) ? 'is-invalid' : '' ?>"
                                    id="expense_refacturare_port_pret"
                                    name="refacturare_port_pret"
                                    min="0"
                                    step="0.01"
                                    placeholder="Pret / buc"
                                    value="<?= e((string) ($expenseFormData['refacturare_port_pret'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['refacturare_port_pret'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_port_pret']) ?></div><?php endif; ?>
                            </div>
                        </div>

                        <div class="row g-2">
                            <div class="col-12 col-md-4"><strong>Trece</strong></div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['refacturare_trece_bucati']) ? 'is-invalid' : '' ?>"
                                    id="expense_refacturare_trece_bucati"
                                    name="refacturare_trece_bucati"
                                    min="0"
                                    step="1"
                                    placeholder="Bucati"
                                    value="<?= e((string) ($expenseFormData['refacturare_trece_bucati'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['refacturare_trece_bucati'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_trece_bucati']) ?></div><?php endif; ?>
                            </div>
                            <div class="col-6 col-md-4">
                                <input
                                    type="number"
                                    class="form-control form-control-sm <?= isset($expenseFormErrors['refacturare_trece_pret']) ? 'is-invalid' : '' ?>"
                                    id="expense_refacturare_trece_pret"
                                    name="refacturare_trece_pret"
                                    min="0"
                                    step="0.01"
                                    placeholder="Pret / buc"
                                    value="<?= e((string) ($expenseFormData['refacturare_trece_pret'] ?? '')) ?>"
                                >
                                <?php if (isset($expenseFormErrors['refacturare_trece_pret'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_trece_pret']) ?></div><?php endif; ?>
                            </div>
                        </div>

                        <div class="form-text mt-2">Suma se calculeaza automat din: bucati x pret pentru fiecare taxa.</div>
                    </div>

                        <div class="mb-3">
                            <label class="form-label" for="expense_refacturare_suma">Suma Refacturare <span class="text-danger">*</span></label>
                            <input type="number" class="form-control <?= isset($expenseFormErrors['refacturare_suma']) ? 'is-invalid' : '' ?>" id="expense_refacturare_suma" name="refacturare_suma" min="0.01" step="0.01" value="<?= e((string) ($expenseFormData['refacturare_suma'] ?? '')) ?>">
                            <?php if (isset($expenseFormErrors['refacturare_suma'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_suma']) ?></div><?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="expense_refacturare_data">Data Refacturare <span class="text-danger">*</span></label>
                            <input type="date" class="form-control <?= isset($expenseFormErrors['refacturare_data']) ? 'is-invalid' : '' ?>" id="expense_refacturare_data" name="refacturare_data" value="<?= e((string) ($expenseFormData['refacturare_data'] ?? date('Y-m-d'))) ?>">
                            <?php if (isset($expenseFormErrors['refacturare_data'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_data']) ?></div><?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="expense_refacturare_observatii">Observatii Refacturare</label>
                            <textarea class="form-control <?= isset($expenseFormErrors['refacturare_observatii']) ? 'is-invalid' : '' ?>" id="expense_refacturare_observatii" name="refacturare_observatii" rows="3"><?= e((string) ($expenseFormData['refacturare_observatii'] ?? '')) ?></textarea>
                            <?php if (isset($expenseFormErrors['refacturare_observatii'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_observatii']) ?></div><?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="expense_refacturare_document_upload">Document Refacturare (upload)</label>
                            <input type="file" class="form-control <?= isset($expenseFormErrors['refacturare_document_upload']) ? 'is-invalid' : '' ?>" id="expense_refacturare_document_upload" name="refacturare_document_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                            <div class="form-text">Formate acceptate: PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB.</div>
                            <?php if (isset($expenseFormErrors['refacturare_document_upload'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_document_upload']) ?></div><?php endif; ?>
                        </div>

                        <?php if ($existingRefacturareDocUrl !== null): ?>
                            <div class="alert alert-light border mb-0">
                                <div class="small text-muted mb-1">Document Refacturare existent</div>
                                <a href="<?= e($existingRefacturareDocUrl) ?>" target="_blank" rel="noopener">
                                    <?= e($existingRefacturareDocName !== '' ? $existingRefacturareDocName : basename($existingRefacturareDoc)) ?>
                                </a>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" value="1" id="expense_sterge_refacturare_document" name="sterge_refacturare_document">
                                    <label class="form-check-label" for="expense_sterge_refacturare_document">
                                        Sterge documentul Refacturare curent la salvare
                                    </label>
                                </div>
                            </div>
                        <?php endif; ?>

                        <button
                            type="submit"
                            class="btn btn-primary w-100 mt-3 expense-refacturare-submit"
                            name="submit_intent"
                            value="refacturare"
                            formaction="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'store_refacturare'])) ?>"
                            formnovalidate
                        >
                            <?= $editingExpense ? 'Actualizeaza Refacturare' : 'Adauga Refacturare' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-7 dispatcher-expense-list-column">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center gap-2">
                <h3 class="h6 mb-0">Cheltuieli cursa</h3>
                <div class="small text-muted">Total cheltuieli: <strong><?= e(format_number_ro($expensesTotal, 2)) ?> lei</strong></div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Data</th>
                            <th>Tip</th>
                            <th>Suma</th>
                            <th>Document</th>
                            <th class="text-end pe-3">Actiuni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($expenses === []): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">Nu exista cheltuieli pentru aceasta cursa.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $expense): ?>
                                <?php
                                $expenseId = (int) ($expense['id'] ?? 0);
                                $docPath = (string) ($expense['file_path'] ?? '');
                                $docName = (string) ($expense['original_name'] ?? '');
                                $docUrl = $docPath !== '' ? url('uploads/curse_cheltuieli/' . rawurlencode($docPath)) : null;
                                $refacturareDocPath = (string) ($expense['refacturare_document_path'] ?? '');
                                $refacturareDocName = (string) ($expense['refacturare_document_original_name'] ?? '');
                                $refacturareDocUrl = $refacturareDocPath !== '' ? url('uploads/curse_cheltuieli/' . rawurlencode($refacturareDocPath)) : null;
                                $refacturareIsInvoiced = (int) ($expense['refacturare_facturata'] ?? 0) === 1;
                                $expenseTypeLabel = trim((string) ($expense['categorie_nume'] ?? ''));
                                if ($expenseTypeLabel === '') {
                                    $expenseTypeLabel = (string) ($expenseTypes[(string) ($expense['tip_cheltuiala'] ?? '')] ?? '-');
                                }
                                $refacturareTypeKey = (string) ($expense['refacturare_tip_cheltuiala'] ?? '');
                                $refacturareTypeLabel = (!$refacturareIsInvoiced && $refacturareTypeKey !== '') ? (string) ($expenseTypes[$refacturareTypeKey] ?? '') : '';
                                $refacturareAmountValue = !$refacturareIsInvoiced ? (float) ($expense['refacturare_suma'] ?? 0) : 0.0;
                                $refacturareDetailsRows = json_decode((string) ($expense['refacturare_detalii'] ?? ''), true);
                                $refacturareDetailsTotal = 0.0;
                                if (is_array($refacturareDetailsRows)) {
                                    foreach ($refacturareDetailsRows as $refacturareDetailsRow) {
                                        if (is_array($refacturareDetailsRow)) {
                                            $refacturareDetailsTotal += (float) ($refacturareDetailsRow['total'] ?? 0);
                                        }
                                    }
                                }
                                ?>
                                <tr>
                                    <td><?= e(format_date_ro((string) ($expense['data_cheltuiala'] ?? ''))) ?></td>
                                    <td>
                                        <?= e($expenseTypeLabel) ?>
                                        <?php if ($refacturareTypeLabel !== ''): ?>
                                            <div class="small text-muted">
                                                Refacturare: <?= e($refacturareTypeLabel) ?><?= $refacturareAmountValue > 0 ? ' (' . e(format_number_ro($refacturareAmountValue, 2)) . ' lei)' : ($refacturareDetailsTotal > 0 ? ' (' . e(format_number_ro($refacturareDetailsTotal, 2)) . ' lei)' : '') ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e(format_number_ro((float) ($expense['suma'] ?? 0), 2)) ?> lei</td>
                                    <td>
                                        <?php if ($docUrl !== null): ?>
                                            <a class="btn btn-sm btn-outline-secondary" href="<?= e($docUrl) ?>" target="_blank" rel="noopener">
                                                <?= e($docName !== '' ? $docName : basename($docPath)) ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($refacturareDocUrl !== null && !$refacturareIsInvoiced): ?>
                                            <a class="btn btn-sm btn-outline-secondary mt-1" href="<?= e($refacturareDocUrl) ?>" target="_blank" rel="noopener">
                                                Refacturare: <?= e($refacturareDocName !== '' ? $refacturareDocName : basename($refacturareDocPath)) ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($docUrl === null && $refacturareDocUrl === null): ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <div class="d-inline-flex gap-1">
                                            <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId, 'expense_id' => $expenseId])) ?>">Editeaza</a>
                                            <form method="post" action="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'delete_expense', 'id' => $raceId])) ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="expense_id" value="<?= e((string) $expenseId) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Sigur doresti sa stergi aceasta cheltuiala?">Sterge</button>
                                            </form>
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
    </div>
</div>

<style>
.expense-main-panel,
.expense-refacturare-panel {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.dispatcher-expense-form .form-control,
.dispatcher-expense-form .form-select {
    min-width: 0;
}

.dispatcher-expense-form .form-text {
    overflow-wrap: anywhere;
}

@media (min-width: 1200px) {
    .dispatcher-expense-layout.has-refacturare > .dispatcher-expense-form-column,
    .dispatcher-expense-layout.has-refacturare > .dispatcher-expense-list-column {
        flex: 0 0 100%;
        max-width: 100%;
        width: 100%;
    }

    .dispatcher-expense-form.has-refacturare {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        column-gap: 1.25rem;
        align-items: stretch;
    }

    .dispatcher-expense-form.has-refacturare > .expense-type-row {
        grid-column: 1 / -1;
    }

    .dispatcher-expense-form.has-refacturare > .expense-main-panel {
        grid-column: 1;
        min-height: 100%;
        padding: 1rem;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
    }

    .dispatcher-expense-form.has-refacturare .expense-submit-row,
    .dispatcher-expense-form.has-refacturare .expense-refacturare-submit {
        margin-top: auto !important;
        width: 100%;
    }

    .dispatcher-expense-form.has-refacturare > [data-role="expense-refacturare-fields"] {
        grid-column: 2;
        margin-bottom: 0 !important;
        min-height: 100%;
        background: transparent;
        overflow: hidden;
    }
}

@media (max-width: 575.98px) {
    .dispatcher-expense-form .expense-type-row > div {
        width: 100%;
    }

    .expense-refacturare-panel {
        padding: 1rem !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var expenseTypeEl = document.getElementById('expense_tip_cheltuiala');
    var expenseAmountEl = document.getElementById('expense_suma');
    var roadTaxBoxEl = document.querySelector('[data-role=\"expense-road-tax-breakdown\"]');
    var expenseFormEl = document.querySelector('[data-role=\"expense-form\"]');
    var expenseLayoutEl = document.querySelector('[data-role=\"expense-layout\"]');
    var refacturareToggleEl = document.querySelector('[data-role=\"expense-refacturare-toggle\"]');
    var refacturareMenuEl = document.querySelector('[data-role=\"expense-refacturare-menu\"]');
    var refacturareFieldsEl = document.querySelector('[data-role=\"expense-refacturare-fields\"]');
    var refacturareSelectEl = document.getElementById('expense_refacturare_tip_cheltuiala');
    var refacturareRoadTaxBoxEl = document.querySelector('[data-role=\"refacturare-road-tax-breakdown\"]');
    var refacturareAmountEl = document.getElementById('expense_refacturare_suma');
    var refacturareDateEl = document.getElementById('expense_refacturare_data');

    var getSelectedLegacyKey = function (selectEl) {
        if (!(selectEl instanceof HTMLSelectElement)) {
            return '';
        }
        var option = selectEl.options[selectEl.selectedIndex] || null;
        if (option instanceof HTMLOptionElement) {
            var legacyKey = option.getAttribute('data-legacy-key') || '';
            if (legacyKey !== '') {
                return legacyKey;
            }
        }
        return selectEl.value;
    };

    var syncRefacturareMenu = function () {
        if (!(refacturareToggleEl instanceof HTMLInputElement) || !(refacturareMenuEl instanceof HTMLElement) || !(refacturareSelectEl instanceof HTMLSelectElement)) {
            return;
        }

        var enabled = refacturareToggleEl.checked;
        if (expenseFormEl instanceof HTMLElement) {
            expenseFormEl.classList.toggle('has-refacturare', enabled);
        }
        if (expenseLayoutEl instanceof HTMLElement) {
            expenseLayoutEl.classList.toggle('has-refacturare', enabled);
        }
        refacturareMenuEl.classList.toggle('d-none', !enabled);
        if (refacturareFieldsEl instanceof HTMLElement) {
            refacturareFieldsEl.classList.toggle('d-none', !enabled);
        }
        refacturareSelectEl.disabled = !enabled;
        refacturareSelectEl.required = enabled;
        if (refacturareAmountEl instanceof HTMLInputElement) {
            refacturareAmountEl.required = enabled;
        }
        if (refacturareDateEl instanceof HTMLInputElement) {
            refacturareDateEl.required = enabled;
        }
        if (refacturareRoadTaxBoxEl instanceof HTMLElement) {
            refacturareRoadTaxBoxEl.classList.toggle('d-none', !enabled || refacturareSelectEl.value !== 'taxe_drum');
        }
    };

    if (refacturareToggleEl instanceof HTMLInputElement) {
        refacturareToggleEl.addEventListener('change', syncRefacturareMenu);
        syncRefacturareMenu();
    }
    if (refacturareSelectEl instanceof HTMLSelectElement) {
        refacturareSelectEl.addEventListener('change', syncRefacturareMenu);
    }

    if (!(expenseTypeEl instanceof HTMLSelectElement) || !(expenseAmountEl instanceof HTMLInputElement) || !(roadTaxBoxEl instanceof HTMLElement)) {
        return;
    }

    var roadTaxFields = [
        {
            qty: document.getElementById('expense_taxa_acces_bucati'),
            price: document.getElementById('expense_taxa_acces_pret')
        },
        {
            qty: document.getElementById('expense_port_bucati'),
            price: document.getElementById('expense_port_pret')
        },
        {
            qty: document.getElementById('expense_trece_bucati'),
            price: document.getElementById('expense_trece_pret')
        }
    ];
    var refacturareRoadTaxFields = [
        {
            qty: document.getElementById('expense_refacturare_taxa_acces_bucati'),
            price: document.getElementById('expense_refacturare_taxa_acces_pret')
        },
        {
            qty: document.getElementById('expense_refacturare_port_bucati'),
            price: document.getElementById('expense_refacturare_port_pret')
        },
        {
            qty: document.getElementById('expense_refacturare_trece_bucati'),
            price: document.getElementById('expense_refacturare_trece_pret')
        }
    ];
    var initialExpenseAmount = String(expenseAmountEl.value || '');

    var parseNumber = function (value) {
        var normalized = String(value || '').trim().replace(',', '.');
        if (normalized === '') {
            return null;
        }
        var parsed = Number(normalized);
        return Number.isFinite(parsed) ? parsed : null;
    };

    var formatAmount = function (value) {
        return (Math.round(value * 100) / 100).toFixed(2);
    };

    var calculateRoadTaxTotal = function () {
        var total = 0;
        roadTaxFields.forEach(function (field) {
            if (!(field.qty instanceof HTMLInputElement) || !(field.price instanceof HTMLInputElement)) {
                return;
            }

            var qty = parseNumber(field.qty.value);
            var price = parseNumber(field.price.value);
            if (qty !== null && qty > 0 && price !== null && price > 0) {
                total += qty * price;
            }
        });
        return Math.round(total * 100) / 100;
    };

    var calculateRefacturareRoadTaxTotal = function () {
        var total = 0;
        refacturareRoadTaxFields.forEach(function (field) {
            if (!(field.qty instanceof HTMLInputElement) || !(field.price instanceof HTMLInputElement)) {
                return;
            }

            var qty = parseNumber(field.qty.value);
            var price = parseNumber(field.price.value);
            if (qty !== null && qty > 0 && price !== null && price > 0) {
                total += qty * price;
            }
        });
        return Math.round(total * 100) / 100;
    };

    var hasAnyRoadTaxInput = function () {
        for (var i = 0; i < roadTaxFields.length; i++) {
            var field = roadTaxFields[i];
            if (!(field.qty instanceof HTMLInputElement) || !(field.price instanceof HTMLInputElement)) {
                continue;
            }
            if (String(field.qty.value || '').trim() !== '' || String(field.price.value || '').trim() !== '') {
                return true;
            }
        }
        return false;
    };

    var hasAnyRefacturareRoadTaxInput = function () {
        for (var i = 0; i < refacturareRoadTaxFields.length; i++) {
            var field = refacturareRoadTaxFields[i];
            if (!(field.qty instanceof HTMLInputElement) || !(field.price instanceof HTMLInputElement)) {
                continue;
            }
            if (String(field.qty.value || '').trim() !== '' || String(field.price.value || '').trim() !== '') {
                return true;
            }
        }
        return false;
    };

    var syncExpenseTaxMode = function () {
        var isRoadTax = getSelectedLegacyKey(expenseTypeEl) === 'taxe_drum';
        var isRefacturareRoadTax = refacturareToggleEl instanceof HTMLInputElement
            && refacturareSelectEl instanceof HTMLSelectElement
            && refacturareToggleEl.checked
            && refacturareSelectEl.value === 'taxe_drum';
        roadTaxBoxEl.classList.toggle('d-none', !isRoadTax);

        if (isRoadTax) {
            var total = calculateRoadTaxTotal();
            expenseAmountEl.readOnly = true;
            if (total > 0) {
                expenseAmountEl.value = formatAmount(total);
            } else if (hasAnyRoadTaxInput() || initialExpenseAmount === '') {
                expenseAmountEl.value = '';
            }
        } else {
            expenseAmountEl.readOnly = false;
        }

        if (isRefacturareRoadTax && refacturareAmountEl instanceof HTMLInputElement) {
            var refacturareTotal = calculateRefacturareRoadTaxTotal();
            refacturareAmountEl.readOnly = true;
            if (refacturareTotal > 0) {
                refacturareAmountEl.value = formatAmount(refacturareTotal);
            } else if (hasAnyRefacturareRoadTaxInput()) {
                refacturareAmountEl.value = '';
            }
        } else if (refacturareAmountEl instanceof HTMLInputElement) {
            refacturareAmountEl.readOnly = false;
        }
    };

    expenseTypeEl.addEventListener('change', syncExpenseTaxMode);
    roadTaxFields.forEach(function (field) {
        if (field.qty instanceof HTMLInputElement) {
            field.qty.addEventListener('input', syncExpenseTaxMode);
        }
        if (field.price instanceof HTMLInputElement) {
            field.price.addEventListener('input', syncExpenseTaxMode);
        }
    });
    refacturareRoadTaxFields.forEach(function (field) {
        if (field.qty instanceof HTMLInputElement) {
            field.qty.addEventListener('input', syncExpenseTaxMode);
        }
        if (field.price instanceof HTMLInputElement) {
            field.price.addEventListener('input', syncExpenseTaxMode);
        }
    });
    if (refacturareToggleEl instanceof HTMLInputElement) {
        refacturareToggleEl.addEventListener('change', syncExpenseTaxMode);
    }
    if (refacturareSelectEl instanceof HTMLSelectElement) {
        refacturareSelectEl.addEventListener('change', syncExpenseTaxMode);
    }

    syncExpenseTaxMode();
});
</script>

<?php include __DIR__ . '/_expense_prompt_modal.php'; ?>

<?php if (!empty($maintenancePopupMessages)): ?>
    <div class="modal fade" id="kmRevizieAlertModalEdit" tabindex="-1" aria-labelledby="kmRevizieAlertEditTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="kmRevizieAlertEditTitle">AlertÄƒ revizie vehicul</h5>
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
            var modalElement = document.getElementById('kmRevizieAlertModalEdit');
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

<?php include __DIR__ . '/_inactive_resource_modal.php'; ?>

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

<?php if ($focusFieldId !== ''): ?>
<script>
(function () {
    var focusTargetField = function () {
        var targetEl = document.getElementById(<?= json_encode($focusFieldId) ?>);
        if (!(targetEl instanceof HTMLElement)) {
            return;
        }

        targetEl.classList.add('dispatcher-field-deep-focus');

        var alignTargetField = function () {
            var topOffset = 112;
            var targetTop = targetEl.getBoundingClientRect().top + window.pageYOffset - topOffset;
            window.scrollTo({
                top: Math.max(0, Math.round(targetTop)),
                behavior: 'auto'
            });
        };

        alignTargetField();
        window.requestAnimationFrame(function () {
            alignTargetField();
            window.setTimeout(function () {
                alignTargetField();
                try {
                    targetEl.focus({ preventScroll: true });
                } catch (error) {
                    targetEl.focus();
                }
            }, 120);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', focusTargetField);
    } else {
        focusTargetField();
    }
})();
</script>
<?php endif; ?>
