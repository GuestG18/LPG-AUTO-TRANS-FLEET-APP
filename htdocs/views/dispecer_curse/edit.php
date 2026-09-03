<?php
$zoneTariffJson = json_encode($zoneTariffs, JSON_UNESCAPED_UNICODE);
if (!is_string($zoneTariffJson)) {
    $zoneTariffJson = '{}';
}
$zoneExtraKmJson = json_encode($zoneExtraKmCosts ?? [], JSON_UNESCAPED_UNICODE);
if (!is_string($zoneExtraKmJson)) {
    $zoneExtraKmJson = '{}';
}
$primaryExtendedBeneficiaryIds = [];
foreach (($beneficiaries ?? []) as $extendedPointsBeneficiary) {
    if (!empty($extendedPointsBeneficiary['rute_primar_puncte_extinse'])) {
        $primaryExtendedBeneficiaryIds[] = (string) (int) ($extendedPointsBeneficiary['id'] ?? 0);
    }
}
$primaryExtendedBeneficiaryJson = json_encode($primaryExtendedBeneficiaryIds, JSON_UNESCAPED_UNICODE);
if (!is_string($primaryExtendedBeneficiaryJson)) {
    $primaryExtendedBeneficiaryJson = '[]';
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

// Taxele de drum (Taxa acces / Port / Trecere) se completeaza cu locatie, bucati si pret unitar;
// restul tipurilor pastreaza suma introdusa manual plus observatii.
$tollExpenseTypes = is_array($tollExpenseTypes ?? null) ? $tollExpenseTypes : ['taxa_acces', 'port', 'trece'];
$expenseLocationSuggestions = is_array($expenseLocationSuggestions ?? null) ? $expenseLocationSuggestions : [];

// Locatiile propuse la taxele de drum urmeaza beneficiarul cursei: locurile de incarcare
// si zonele lui de distributie, plus locatiile deja scrise pe cursele aceluiasi beneficiar.
$expenseLocationSuggestionsByBeneficiary = is_array($expenseLocationSuggestionsByBeneficiary ?? null)
    ? $expenseLocationSuggestionsByBeneficiary
    : [];
$expenseLocationBeneficiaryId = (int) ($raceFormData['beneficiar_id'] ?? 0);
$expenseLocationSuggestions = $expenseLocationSuggestionsByBeneficiary[$expenseLocationBeneficiaryId]
    ?? ($expenseLocationSuggestionsByBeneficiary[0] ?? $expenseLocationSuggestions);
$expenseLocationSuggestionsJson = json_encode($expenseLocationSuggestionsByBeneficiary, JSON_UNESCAPED_UNICODE);
if (!is_string($expenseLocationSuggestionsJson)) {
    $expenseLocationSuggestionsJson = '{}';
}
$expenseTypeFieldValues = is_array($expenseFormData['tip'] ?? null) ? $expenseFormData['tip'] : [];
$refacturareTypeFieldValues = is_array($expenseFormData['refacturare_tip'] ?? null) ? $expenseFormData['refacturare_tip'] : [];

$selectedExpenseCategoryIds = is_array($expenseFormData['categorie_ids'] ?? null)
    ? array_map('strval', $expenseFormData['categorie_ids'])
    : [];
if ($selectedExpenseCategoryIds === [] && $selectedExpenseCategoryId !== '') {
    $selectedExpenseCategoryIds = [$selectedExpenseCategoryId];
}

$selectedRefacturareExpenseTypes = is_array($expenseFormData['refacturare_tip_cheltuieli'] ?? null)
    ? array_map('strval', $expenseFormData['refacturare_tip_cheltuieli'])
    : [];
if ($selectedRefacturareExpenseTypes === [] && $selectedRefacturareExpenseType !== '') {
    $selectedRefacturareExpenseTypes = [$selectedRefacturareExpenseType];
}
$selectedRefacturareExpenseTypes = array_values(array_filter(
    $selectedRefacturareExpenseTypes,
    static fn (string $type): bool => isset($expenseEntryTypes[$type])
));

// Cheltuielile si refacturarile stau in intervalul cursei: calendarul nu lasa
// sa alegi in afara lui, iar valoarea implicita e adusa inauntru.
$expenseDateRange = is_array($expenseDateRange ?? null) ? $expenseDateRange : ['min' => null, 'max' => null];
$expenseDateMin = is_string($expenseDateRange['min'] ?? null) ? $expenseDateRange['min'] : '';
$expenseDateMax = is_string($expenseDateRange['max'] ?? null) ? $expenseDateRange['max'] : '';
$clampExpenseDate = static function (string $value) use ($expenseDateMin, $expenseDateMax): string {
    if ($value === '') {
        return $expenseDateMin !== '' ? $expenseDateMin : $value;
    }
    if ($expenseDateMin !== '' && $value < $expenseDateMin) {
        return $expenseDateMin;
    }
    if ($expenseDateMax !== '' && $value > $expenseDateMax) {
        return $expenseDateMax;
    }

    return $value;
};
$expenseDateRangeHint = '';
if ($expenseDateMin !== '' || $expenseDateMax !== '') {
    $formatHintDate = static function (string $date): string {
        $timestamp = strtotime($date);

        return $timestamp !== false ? date('d.m.Y', $timestamp) : $date;
    };
    if ($expenseDateMin !== '' && $expenseDateMax !== '' && $expenseDateMin === $expenseDateMax) {
        $expenseDateRangeHint = 'Cursa este intr-o singura zi: ' . $formatHintDate($expenseDateMin) . '.';
    } elseif ($expenseDateMin !== '' && $expenseDateMax !== '') {
        $expenseDateRangeHint = 'Doar in intervalul cursei: '
            . $formatHintDate($expenseDateMin) . ' - ' . $formatHintDate($expenseDateMax) . '.';
    }
}

$expenseTypeInputType = $editingExpense ? 'radio' : 'checkbox';
// Cheltuielile vechi salvate pe tipul retras "Taxe drum" nu se pot muta automat
// pe unul dintre tipurile noi, pentru ca puteau contine mai multe taxe deodata.
$editingRetiredRoadTaxExpense = $editingExpense
    && (string) ($expenseFormData['tip_cheltuiala'] ?? '') === 'taxe_drum';

// Randurile de refacturare au suma 0 pe partea de cheltuiala, asa ca sumele lor
// se numara separat; altfel tabelul ar arata 0 lei pentru o refacturare reala.
$expensesTotal = 0.0;
$refacturareTotal = 0.0;
$refacturarePendingTotal = 0.0;
foreach ($expenses as $expenseRow) {
    $expensesTotal += (float) ($expenseRow['suma'] ?? 0);

    $rowRefacturare = (float) ($expenseRow['refacturare_suma'] ?? 0);
    if ($rowRefacturare > 0) {
        $refacturareTotal += $rowRefacturare;
        if ((int) ($expenseRow['refacturare_facturata'] ?? 0) !== 1) {
            $refacturarePendingTotal += $rowRefacturare;
        }
    }
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

// Flux "cursa tocmai adaugata": utilizatorul a raspuns "Da" la promptul de cheltuieli.
// In acest caz pagina de editare este doar o oprire scurta, nu un formular de cursa noua.
$postCreateFlow = trim((string) ($_GET['flux'] ?? '')) === 'cursa_noua';
// retur=0 = utilizatorul a debifat deja intoarcerea la lista pentru cursa asta.
$postCreateReturnChecked = trim((string) ($_GET['retur'] ?? '')) !== '0';
$postCreateFlowQuery = [];
if ($postCreateFlow) {
    $postCreateFlowQuery['flux'] = 'cursa_noua';
    if (!$postCreateReturnChecked) {
        $postCreateFlowQuery['retur'] = '0';
    }
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h2 class="h4 mb-0">Editeaza cursa</h2>
    <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Inapoi la lista</a>
</div>

<?php if ($postCreateFlow): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <div class="flex-grow-1">
            <strong>Editezi cursa #<?= e((string) $raceId) ?>, care tocmai a fost salvata.</strong>
            Formularul "Date cursa" de mai jos <u>nu</u> este un formular de cursa noua &ndash; daca scrii in el, modifici cursa salvata.
            <?php if ($postCreateReturnChecked): ?>
                Adauga cheltuiala mai jos, iar dupa salvare te intorci automat la lista, unde poti introduce o cursa noua.
            <?php else: ?>
                Ai debifat intoarcerea automata la lista, deci ramai pe cursa dupa salvare, ca sa mai poti adauga o cheltuiala sau o refacturare.
            <?php endif; ?>
        </div>
        <a class="btn btn-sm btn-outline-dark" href="<?= e(build_query_url(['page' => 'dispecer_curse'])) ?>">Adauga o cursa noua</a>
    </div>
<?php endif; ?>

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
              data-beneficiary-pricing='<?= e($beneficiaryPricingJson) ?>' data-primary-extended-beneficiaries='<?= e($primaryExtendedBeneficiaryJson) ?>'
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

                <div class="col-12 col-md-6 dispatcher-top-field" data-role="field-beneficiar">
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

                <div class="col-12 col-md-6 dispatcher-top-field" data-role="field-tip-transport">
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

                <div class="col-12 col-md-6 dispatcher-top-field" data-role="field-vehicul">
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

                <div class="col-12 col-md-6 dispatcher-top-field" data-role="field-sofer">
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

                <div class="col-12 col-md-6 dispatcher-schedule-field" data-role="field-end-datetime">
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

                <div class="col-12 col-md-6 d-none" data-role="field-ruta-plecare">
                    <label class="form-label" for="edit_race_ruta_plecare">Loc plecare (garaj)</label>
                    <select class="form-select" id="edit_race_ruta_plecare" name="loc_plecare_ruta" data-role="ruta-plecare" data-initial-value="<?= e((string) ($raceFormData['loc_plecare'] ?? '')) ?>"></select>
                    <div class="form-text text-muted">Punctele de plecare configurate pe aceasta ruta. Km si pretul urmeaza varianta aleasa.</div>
                </div>

                <div class="col-12 col-md-6 d-none" data-role="field-ruta-intoarcere">
                    <label class="form-label" for="edit_race_ruta_intoarcere">Loc intoarcere (garaj)</label>
                    <select class="form-select" id="edit_race_ruta_intoarcere" name="loc_intoarcere" data-role="ruta-intoarcere" data-initial-value="<?= e((string) ($raceFormData['loc_intoarcere'] ?? '')) ?>"></select>
                    <div class="form-text text-muted">Variantele configurate pe aceasta ruta. Km si pretul urmeaza varianta aleasa.</div>
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

                <?php
                // Recalculare comerciala EXPLICITA.
                // Editarea unei curse nu mai reevalueaza tacit valorile financiare;
                // operatorul trebuie sa ceara explicit recalcularea, iar cursele
                // facturate raman imutabile.
                $raceIsInvoiced = (string) ($raceFormData['status_facturare'] ?? '') === 'facturat';
                ?>
                <div class="col-12" data-role="tariff-recalc-block">
                    <input type="hidden" name="recalculate_tariff" id="edit_recalculate_tariff" value="0">
                    <?php if ($raceIsInvoiced): ?>
                        <div class="alert alert-secondary py-2 mb-0 small d-flex align-items-start gap-2">
                            <i class="bi bi-lock-fill" aria-hidden="true"></i>
                            <div>
                                Cursa este <strong>facturata</strong>. Valorile comerciale sunt imutabile
                                si nu pot fi recalculate.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-light border py-2 mb-0 small d-flex align-items-start gap-2 flex-wrap">
                            <i class="bi bi-shield-check text-primary" aria-hidden="true"></i>
                            <div class="flex-grow-1">
                                La salvare, <strong>valorile comerciale stocate raman neschimbate</strong>.
                                Foloseste butonul alaturat daca vrei sa reevaluezi tariful conform
                                <strong>datei cursei</strong>.
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    id="edit_recalculate_tariff_btn"
                                    data-race-total="<?= e((string) ($raceFormData['total_facturare'] ?? 0)) ?>"
                                    data-race-price="<?= e((string) ($raceFormData['pret_tarifare'] ?? 0)) ?>"
                                    data-preview-url="<?= e(build_query_url(['page' => 'tarife_transport', 'action' => 'preview'])) ?>">
                                <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Recalculeaza tariful
                            </button>
                        </div>
                    <?php endif; ?>
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
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(build_query_url(array_merge(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId], $postCreateFlowQuery))) ?>">Anuleaza editarea</a>
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
<?php if ($postCreateFlow): ?>
                    <input type="hidden" name="post_create_flow" value="1">
<?php endif; ?>

                    <?php
                    /**
                     * Blocul de campuri al unui tip bifat.
                     * Taxele de drum (Taxa acces / Port / Trecere) au Locatie, Bucati, Pret / buc si
                     * Total calculat automat, fara Observatii; restul tipurilor pastreaza suma manuala.
                     */
                    $renderExpenseTypeBlock = static function (
                        string $blockKey,
                        string $blockLabel,
                        bool $isToll,
                        string $fieldPrefix,
                        string $idPrefix,
                        array $values,
                        array $errors,
                        bool $isSelected
                    ): void {
                        $errorPrefix = $fieldPrefix . '.' . $blockKey . '.';
                        $fieldId = static fn (string $field): string => $idPrefix . preg_replace('/[^A-Za-z0-9_]/', '_', $blockKey) . '_' . $field;
                        $fieldName = static fn (string $field): string => $fieldPrefix . '[' . $blockKey . '][' . $field . ']';
                        $value = static fn (string $field): string => (string) ($values[$field] ?? '');
                        $error = static fn (string $field): string => (string) ($errors[$errorPrefix . $field] ?? '');
                        ?>
                        <div
                            class="border rounded p-3 mb-3 expense-type-block <?= $isSelected ? '' : 'd-none' ?>"
                            data-role="expense-type-block"
                            data-block-key="<?= e($blockKey) ?>"
                            data-field-prefix="<?= e($fieldPrefix) ?>"
                        >
                            <div class="fw-semibold mb-2"><?= e($blockLabel) ?></div>
                            <?php if ($isToll): ?>
                                <div class="row g-2">
                                    <div class="col-12">
                                        <label class="form-label mb-1" for="<?= e($fieldId('locatie')) ?>">Locatie <span class="text-danger">*</span></label>
                                        <input
                                            type="text"
                                            class="form-control form-control-sm <?= $error('locatie') !== '' ? 'is-invalid' : '' ?>"
                                            id="<?= e($fieldId('locatie')) ?>"
                                            name="<?= e($fieldName('locatie')) ?>"
                                            list="expense_location_options"
                                            maxlength="190"
                                            autocomplete="off"
                                            placeholder="ex. Port Constanta"
                                            value="<?= e($value('locatie')) ?>"
                                        >
                                        <div class="form-text" data-role="expense-location-hint"></div>
                                        <?php if ($error('locatie') !== ''): ?><div class="invalid-feedback d-block"><?= e($error('locatie')) ?></div><?php endif; ?>
                                    </div>
                                    <div class="col-6 col-lg-4">
                                        <label class="form-label mb-1" for="<?= e($fieldId('bucati')) ?>">Bucati <span class="text-danger">*</span></label>
                                        <input
                                            type="number"
                                            class="form-control form-control-sm <?= $error('bucati') !== '' ? 'is-invalid' : '' ?>"
                                            id="<?= e($fieldId('bucati')) ?>"
                                            name="<?= e($fieldName('bucati')) ?>"
                                            min="0"
                                            step="1"
                                            data-role="expense-line-quantity"
                                            value="<?= e($value('bucati')) ?>"
                                        >
                                        <?php if ($error('bucati') !== ''): ?><div class="invalid-feedback d-block"><?= e($error('bucati')) ?></div><?php endif; ?>
                                    </div>
                                    <div class="col-6 col-lg-4">
                                        <label class="form-label mb-1" for="<?= e($fieldId('pret')) ?>">Pret / buc <span class="text-danger">*</span></label>
                                        <input
                                            type="number"
                                            class="form-control form-control-sm <?= $error('pret') !== '' ? 'is-invalid' : '' ?>"
                                            id="<?= e($fieldId('pret')) ?>"
                                            name="<?= e($fieldName('pret')) ?>"
                                            min="0"
                                            step="0.01"
                                            data-role="expense-line-price"
                                            value="<?= e($value('pret')) ?>"
                                        >
                                        <?php if ($error('pret') !== ''): ?><div class="invalid-feedback d-block"><?= e($error('pret')) ?></div><?php endif; ?>
                                    </div>
                                    <div class="col-12 col-lg-4">
                                        <label class="form-label mb-1" for="<?= e($fieldId('total')) ?>">Total</label>
                                        <input
                                            type="text"
                                            class="form-control form-control-sm bg-light"
                                            id="<?= e($fieldId('total')) ?>"
                                            data-role="expense-line-total"
                                            value="0,00"
                                            readonly
                                            tabindex="-1"
                                        >
                                    </div>
                                </div>
                                <div class="form-text mt-2">Totalul se calculeaza automat: bucati x pret / buc.</div>
                            <?php else: ?>
                                <div class="mb-2">
                                    <label class="form-label mb-1" for="<?= e($fieldId('suma')) ?>">Suma <span class="text-danger">*</span></label>
                                    <input
                                        type="number"
                                        class="form-control form-control-sm <?= $error('suma') !== '' ? 'is-invalid' : '' ?>"
                                        id="<?= e($fieldId('suma')) ?>"
                                        name="<?= e($fieldName('suma')) ?>"
                                        min="0.01"
                                        step="0.01"
                                        value="<?= e($value('suma')) ?>"
                                    >
                                    <?php if ($error('suma') !== ''): ?><div class="invalid-feedback d-block"><?= e($error('suma')) ?></div><?php endif; ?>
                                </div>
                                <div>
                                    <label class="form-label mb-1" for="<?= e($fieldId('observatii')) ?>">Observatii</label>
                                    <textarea
                                        class="form-control form-control-sm <?= $error('observatii') !== '' ? 'is-invalid' : '' ?>"
                                        id="<?= e($fieldId('observatii')) ?>"
                                        name="<?= e($fieldName('observatii')) ?>"
                                        rows="2"
                                    ><?= e($value('observatii')) ?></textarea>
                                    <?php if ($error('observatii') !== ''): ?><div class="invalid-feedback d-block"><?= e($error('observatii')) ?></div><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php
                    };
                    ?>

                    <datalist
                        id="expense_location_options"
                        data-role="expense-location-options"
                        data-by-beneficiary='<?= e($expenseLocationSuggestionsJson) ?>'
                    >
                        <?php foreach ($expenseLocationSuggestions as $locationSuggestion): ?>
                            <option value="<?= e((string) $locationSuggestion) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>

                    <div class="row g-2 mb-3 align-items-start expense-type-row">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="expense_tip_cheltuiala">Tip cheltuiala <span class="text-danger">*</span></label>
                            <div class="dropdown" data-role="expense-type-dropdown">
                                <button
                                    class="form-select text-start <?= (isset($expenseFormErrors['categorie_id']) || isset($expenseFormErrors['tip_cheltuiala'])) ? 'is-invalid' : '' ?>"
                                    type="button"
                                    id="expense_tip_cheltuiala"
                                    data-bs-toggle="dropdown"
                                    data-bs-auto-close="outside"
                                    aria-expanded="false"
                                >
                                    <span data-role="expense-type-summary">-- Selecteaza tipurile --</span>
                                </button>
                                <div class="dropdown-menu w-100 p-2 expense-type-menu">
                                    <?php foreach ($expenseCategories as $category): ?>
                                        <?php
                                        $categoryId = (int) ($category['id'] ?? 0);
                                        if ($categoryId <= 0) {
                                            continue;
                                        }
                                        $categoryName = (string) ($category['nume'] ?? '-');
                                        $categoryChecked = in_array((string) $categoryId, $selectedExpenseCategoryIds, true);
                                        ?>
                                        <div class="form-check">
                                            <input
                                                class="form-check-input"
                                                type="<?= e($expenseTypeInputType) ?>"
                                                name="categorie_ids[]"
                                                value="<?= e((string) $categoryId) ?>"
                                                id="expense_type_option_<?= e((string) $categoryId) ?>"
                                                data-role="expense-type-option"
                                                data-block-key="<?= e((string) $categoryId) ?>"
                                                data-label="<?= e($categoryName) ?>"
                                                <?= $categoryChecked ? 'checked' : '' ?>
                                            >
                                            <label class="form-check-label" for="expense_type_option_<?= e((string) $categoryId) ?>"><?= e($categoryName) ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-text">
                                <?= $editingExpense
                                    ? 'La editarea unei cheltuieli existente poti pastra un singur tip.'
                                    : 'Poti bifa mai multe tipuri; fiecare se salveaza ca o cheltuiala separata pe aceeasi cursa.' ?>
                                Motorina se introduce separat in modulul Alimentari.
                            </div>
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
                                <div class="dropdown" data-role="expense-refacturare-type-dropdown">
                                    <button
                                        class="form-select text-start <?= isset($expenseFormErrors['refacturare_tip_cheltuiala']) ? 'is-invalid' : '' ?>"
                                        type="button"
                                        id="expense_refacturare_tip_cheltuiala"
                                        data-bs-toggle="dropdown"
                                        data-bs-auto-close="outside"
                                        aria-expanded="false"
                                    >
                                        <span data-role="expense-refacturare-type-summary">-- Selecteaza tipurile --</span>
                                    </button>
                                    <div class="dropdown-menu w-100 p-2 expense-type-menu">
                                        <?php foreach ($expenseEntryTypes as $entryTypeKey => $entryTypeLabel): ?>
                                            <?php $entryTypeChecked = in_array((string) $entryTypeKey, $selectedRefacturareExpenseTypes, true); ?>
                                            <div class="form-check">
                                                <input
                                                    class="form-check-input"
                                                    type="<?= e($expenseTypeInputType) ?>"
                                                    name="refacturare_tip_cheltuieli[]"
                                                    value="<?= e((string) $entryTypeKey) ?>"
                                                    id="expense_refacturare_type_option_<?= e((string) $entryTypeKey) ?>"
                                                    data-role="expense-refacturare-type-option"
                                                    data-block-key="<?= e((string) $entryTypeKey) ?>"
                                                    data-label="<?= e((string) $entryTypeLabel) ?>"
                                                    <?= $entryTypeChecked ? 'checked' : '' ?>
                                                    <?= $expenseRefacturareEnabled ? '' : 'disabled' ?>
                                                >
                                                <label class="form-check-label" for="expense_refacturare_type_option_<?= e((string) $entryTypeKey) ?>"><?= e((string) $entryTypeLabel) ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php if (isset($expenseFormErrors['refacturare_tip_cheltuiala'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_tip_cheltuiala']) ?></div><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="expense-main-panel">
                    <?php if ($editingRetiredRoadTaxExpense): ?>
                        <div class="alert alert-warning expense-main-field">
                            Aceasta cheltuiala a fost salvata pe vechiul tip <strong>Taxe drum</strong>, care putea contine mai multe taxe deodata.
                            Alege acum tipul potrivit (Taxa acces, Port sau Trecere) si completeaza locatia, bucatile si pretul.
                            Daca randul vechi continea mai multe taxe, adauga-le separat.
                        </div>
                    <?php endif; ?>

                    <div class="expense-main-field" data-role="expense-type-blocks">
                        <?php foreach ($expenseCategories as $category): ?>
                            <?php
                            $categoryId = (int) ($category['id'] ?? 0);
                            if ($categoryId <= 0) {
                                continue;
                            }
                            $categoryKey = (string) $categoryId;
                            $categoryLegacyKey = (string) ($category['legacy_key'] ?? '');
                            $renderExpenseTypeBlock(
                                $categoryKey,
                                (string) ($category['nume'] ?? '-'),
                                in_array($categoryLegacyKey, $tollExpenseTypes, true),
                                'tip',
                                'expense_line_',
                                is_array($expenseTypeFieldValues[$categoryKey] ?? null) ? $expenseTypeFieldValues[$categoryKey] : [],
                                $expenseFormErrors,
                                in_array($categoryKey, $selectedExpenseCategoryIds, true)
                            );
                            ?>
                        <?php endforeach; ?>
                        <div class="text-muted small <?= $selectedExpenseCategoryIds === [] ? '' : 'd-none' ?>" data-role="expense-type-empty">
                            Alege cel putin un tip de cheltuiala din lista de mai sus.
                        </div>
                    </div>

                    <div class="mb-3 expense-main-field">
                        <label class="form-label" for="expense_data_cheltuiala">Data cheltuiala <span class="text-danger">*</span></label>
                        <input
                            type="date"
                            class="form-control <?= isset($expenseFormErrors['data_cheltuiala']) ? 'is-invalid' : '' ?>"
                            id="expense_data_cheltuiala"
                            name="data_cheltuiala"
                            data-role="expense-date"
                            <?= $expenseDateMin !== '' ? 'min="' . e($expenseDateMin) . '"' : '' ?>
                            <?= $expenseDateMax !== '' ? 'max="' . e($expenseDateMax) . '"' : '' ?>
                            value="<?= e($clampExpenseDate((string) ($expenseFormData['data_cheltuiala'] ?? ''))) ?>"
                            required
                        >
                        <?php if ($expenseDateRangeHint !== ''): ?><div class="form-text" data-role="expense-date-hint"><?= e($expenseDateRangeHint) ?></div><?php endif; ?>
                        <?php if (isset($expenseFormErrors['data_cheltuiala'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['data_cheltuiala']) ?></div><?php endif; ?>
                    </div>

                    <div class="mb-3 expense-main-field">
                        <label class="form-label" for="expense_document_upload">Document doveditor (upload)</label>
                        <input type="file" class="form-control <?= isset($expenseFormErrors['document_upload']) ? 'is-invalid' : '' ?>" id="expense_document_upload" name="document_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                        <div class="form-text">Formate acceptate: PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB. Se ataseaza la fiecare cheltuiala salvata acum.</div>
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

<?php if ($postCreateFlow): ?>
                    <div class="expense-submit-row">
                        <div class="form-check mb-2 expense-return-choice">
                            <input class="form-check-input" type="checkbox" value="1" id="expense_return_to_list" name="return_to_list" <?= $postCreateReturnChecked ? 'checked' : '' ?>>
                            <label class="form-check-label" for="expense_return_to_list">
                                Dupa salvare ma intorc la lista, ca sa adaug o cursa noua.
                                <span class="d-block text-muted small">
                                    Debifeaza daca mai ai de adaugat ceva pe cursa #<?= e((string) $raceId) ?>
                                    (inca o cheltuiala sau o refacturare separata). Se aplica ambelor butoane de salvare.
                                </span>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" name="submit_intent" value="expense"><?= $editingExpense ? 'Actualizeaza cheltuiala' : 'Adauga cheltuiala' ?></button>
                    </div>
<?php else: ?>
                    <button type="submit" class="btn btn-primary expense-submit-row" name="submit_intent" value="expense"><?= $editingExpense ? 'Actualizeaza cheltuiala' : 'Adauga cheltuiala' ?></button>
<?php endif; ?>
                    </div>
                    <div class="border rounded p-3 mb-3 expense-refacturare-panel <?= $expenseRefacturareEnabled ? '' : 'd-none' ?>" data-role="expense-refacturare-fields">
                        <div class="fw-semibold mb-3">Detalii Refacturare</div>

                        <div data-role="expense-refacturare-type-blocks">
                            <?php foreach ($expenseEntryTypes as $entryTypeKey => $entryTypeLabel): ?>
                                <?php
                                $entryTypeKey = (string) $entryTypeKey;
                                $renderExpenseTypeBlock(
                                    $entryTypeKey,
                                    (string) $entryTypeLabel,
                                    in_array($entryTypeKey, $tollExpenseTypes, true),
                                    'refacturare_tip',
                                    'expense_refacturare_line_',
                                    is_array($refacturareTypeFieldValues[$entryTypeKey] ?? null) ? $refacturareTypeFieldValues[$entryTypeKey] : [],
                                    $expenseFormErrors,
                                    in_array($entryTypeKey, $selectedRefacturareExpenseTypes, true)
                                );
                                ?>
                            <?php endforeach; ?>
                            <div class="text-muted small <?= $selectedRefacturareExpenseTypes === [] ? '' : 'd-none' ?>" data-role="expense-refacturare-type-empty">
                                Alege cel putin un tip de refacturare din lista de mai sus.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="expense_refacturare_data">Data Refacturare <span class="text-danger">*</span></label>
                            <input
                                type="date"
                                class="form-control <?= isset($expenseFormErrors['refacturare_data']) ? 'is-invalid' : '' ?>"
                                id="expense_refacturare_data"
                                name="refacturare_data"
                                data-role="expense-date"
                                <?= $expenseDateMin !== '' ? 'min="' . e($expenseDateMin) . '"' : '' ?>
                                <?= $expenseDateMax !== '' ? 'max="' . e($expenseDateMax) . '"' : '' ?>
                                value="<?= e($clampExpenseDate((string) ($expenseFormData['refacturare_data'] ?? date('Y-m-d')))) ?>"
                            >
                            <?php if ($expenseDateRangeHint !== ''): ?><div class="form-text" data-role="expense-date-hint"><?= e($expenseDateRangeHint) ?></div><?php endif; ?>
                            <?php if (isset($expenseFormErrors['refacturare_data'])): ?><div class="invalid-feedback d-block"><?= e((string) $expenseFormErrors['refacturare_data']) ?></div><?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="expense_refacturare_document_upload">Document Refacturare (upload)</label>
                            <input type="file" class="form-control <?= isset($expenseFormErrors['refacturare_document_upload']) ? 'is-invalid' : '' ?>" id="expense_refacturare_document_upload" name="refacturare_document_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                            <div class="form-text">Formate acceptate: PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB. Se ataseaza la fiecare refacturare salvata acum.</div>
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
                <div class="small text-muted text-end">
                    <div>Total cheltuieli: <strong><?= e(format_number_ro($expensesTotal, 2)) ?> lei</strong></div>
                    <?php if ($refacturareTotal > 0): ?>
                        <div>
                            Total refacturare: <strong><?= e(format_number_ro($refacturareTotal, 2)) ?> lei</strong>
                            <?php if ($refacturarePendingTotal > 0 && $refacturarePendingTotal < $refacturareTotal): ?>
                                <span class="text-warning-emphasis">(nefacturat: <?= e(format_number_ro($refacturarePendingTotal, 2)) ?> lei)</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Data</th>
                            <th>Tip</th>
                            <th>Suma</th>
                            <th>Refacturare</th>
                            <th>Document</th>
                            <th class="text-end pe-3">Actiuni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($expenses === []): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">Nu exista cheltuieli pentru aceasta cursa.</td>
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
                                $expenseLocation = trim((string) ($expense['locatie'] ?? ''));
                                $expenseQuantity = (float) ($expense['bucati'] ?? 0);
                                $expenseUnitPrice = (float) ($expense['pret_unitar'] ?? 0);
                                $refacturareLocation = trim((string) ($expense['refacturare_locatie'] ?? ''));
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
                                        <?php if ($expenseLocation !== ''): ?>
                                            <div class="small"><?= e($expenseLocation) ?></div>
                                        <?php endif; ?>
                                        <?php if ($expenseQuantity > 0 && $expenseUnitPrice > 0): ?>
                                            <div class="small text-muted">
                                                <?= e(format_number_ro($expenseQuantity, 2)) ?> buc x <?= e(format_number_ro($expenseUnitPrice, 2)) ?> lei
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($refacturareTypeLabel !== ''): ?>
                                            <div class="small text-muted">
                                                Refacturare: <?= e($refacturareTypeLabel) ?><?= $refacturareLocation !== '' ? ' - ' . e($refacturareLocation) : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $expenseAmountValue = (float) ($expense['suma'] ?? 0); ?>
                                        <?php if ($expenseAmountValue > 0): ?>
                                            <?= e(format_number_ro($expenseAmountValue, 2)) ?> lei
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $rowRefacturareAmount = (float) ($expense['refacturare_suma'] ?? 0); ?>
                                        <?php if ($rowRefacturareAmount <= 0 && $refacturareDetailsTotal > 0) { $rowRefacturareAmount = $refacturareDetailsTotal; } ?>
                                        <?php if ($rowRefacturareAmount > 0): ?>
                                            <?= e(format_number_ro($rowRefacturareAmount, 2)) ?> lei
                                            <?php if ($refacturareIsInvoiced): ?>
                                                <span class="badge bg-success-subtle text-success-emphasis">facturat</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
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
                                            <a class="btn btn-sm btn-outline-primary" href="<?= e(build_query_url(array_merge(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $raceId, 'expense_id' => $expenseId], $postCreateFlowQuery))) ?>">Editeaza</a>
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
.expense-type-menu {
    max-height: 16rem;
    overflow-y: auto;
}

.dispatcher-expense-form .dropdown > .form-select {
    background-color: #fff;
}

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
    var expenseFormEl = document.querySelector('[data-role="expense-form"]');
    var expenseLayoutEl = document.querySelector('[data-role="expense-layout"]');
    var refacturareToggleEl = document.querySelector('[data-role="expense-refacturare-toggle"]');
    var refacturareMenuEl = document.querySelector('[data-role="expense-refacturare-menu"]');
    var refacturareFieldsEl = document.querySelector('[data-role="expense-refacturare-fields"]');
    var refacturareDateEl = document.getElementById('expense_refacturare_data');

    if (!(expenseFormEl instanceof HTMLElement)) {
        return;
    }

    var parseNumber = function (value) {
        var normalized = String(value === null || value === undefined ? '' : value).trim().replace(',', '.');
        if (normalized === '') {
            return null;
        }
        var parsed = Number(normalized);
        return isFinite(parsed) ? parsed : null;
    };

    var formatTotal = function (value) {
        return (Math.round(value * 100) / 100).toFixed(2).replace('.', ',');
    };

    // Fiecare bloc de taxa isi calculeaza singur totalul din bucati x pret / buc.
    var syncBlockTotal = function (block) {
        var totalEl = block.querySelector('[data-role="expense-line-total"]');
        if (!(totalEl instanceof HTMLInputElement)) {
            return;
        }

        var qtyEl = block.querySelector('[data-role="expense-line-quantity"]');
        var priceEl = block.querySelector('[data-role="expense-line-price"]');
        var qty = qtyEl instanceof HTMLInputElement ? parseNumber(qtyEl.value) : null;
        var price = priceEl instanceof HTMLInputElement ? parseNumber(priceEl.value) : null;
        var total = (qty !== null && qty > 0 && price !== null && price > 0) ? qty * price : 0;
        totalEl.value = formatTotal(total);
    };

    /**
     * Leaga un selector cu bife de blocurile lui: bifa arata blocul si ii activeaza campurile,
     * iar campurile ascunse raman dezactivate, ca sa nu ajunga in POST.
     */
    var wireTypeSelector = function (optionRole, blocksRole, summaryRole, emptyRole, emptySummary) {
        var options = Array.prototype.slice.call(expenseFormEl.querySelectorAll('[data-role="' + optionRole + '"]'));
        var blocksBox = expenseFormEl.querySelector('[data-role="' + blocksRole + '"]');
        var summaryEl = expenseFormEl.querySelector('[data-role="' + summaryRole + '"]');
        var emptyEl = expenseFormEl.querySelector('[data-role="' + emptyRole + '"]');
        if (options.length === 0 || !(blocksBox instanceof HTMLElement)) {
            return null;
        }

        var blocks = {};
        Array.prototype.forEach.call(blocksBox.querySelectorAll('[data-role="expense-type-block"]'), function (block) {
            blocks[block.getAttribute('data-block-key') || ''] = block;
        });

        var isEnabled = true;

        var sync = function (enabled) {
            if (typeof enabled === 'boolean') {
                isEnabled = enabled;
            }

            var selectedLabels = [];
            options.forEach(function (option) {
                if (!(option instanceof HTMLInputElement)) {
                    return;
                }
                option.disabled = !isEnabled;

                var key = option.getAttribute('data-block-key') || '';
                var block = blocks[key];
                var active = isEnabled && option.checked;
                if (active) {
                    selectedLabels.push(option.getAttribute('data-label') || key);
                }
                if (!(block instanceof HTMLElement)) {
                    return;
                }

                block.classList.toggle('d-none', !active);
                Array.prototype.forEach.call(block.querySelectorAll('input, textarea, select'), function (field) {
                    field.disabled = !active;
                });
                if (active) {
                    syncBlockTotal(block);
                }
            });

            if (summaryEl instanceof HTMLElement) {
                summaryEl.textContent = selectedLabels.length > 0 ? selectedLabels.join(', ') : emptySummary;
                summaryEl.classList.toggle('text-muted', selectedLabels.length === 0);
            }
            if (emptyEl instanceof HTMLElement) {
                emptyEl.classList.toggle('d-none', !isEnabled || selectedLabels.length > 0);
            }
        };

        options.forEach(function (option) {
            option.addEventListener('change', function () {
                sync();
            });
        });

        Array.prototype.forEach.call(blocksBox.querySelectorAll('[data-role="expense-line-quantity"], [data-role="expense-line-price"]'), function (field) {
            field.addEventListener('input', function () {
                var block = field.closest('[data-role="expense-type-block"]');
                if (block instanceof HTMLElement) {
                    syncBlockTotal(block);
                }
            });
        });

        return sync;
    };

    var syncExpenseTypes = wireTypeSelector(
        'expense-type-option',
        'expense-type-blocks',
        'expense-type-summary',
        'expense-type-empty',
        '-- Selecteaza tipurile --'
    );
    var syncRefacturareTypes = wireTypeSelector(
        'expense-refacturare-type-option',
        'expense-refacturare-type-blocks',
        'expense-refacturare-type-summary',
        'expense-refacturare-type-empty',
        '-- Selecteaza tipurile --'
    );

    var syncRefacturare = function () {
        var enabled = refacturareToggleEl instanceof HTMLInputElement ? refacturareToggleEl.checked : false;

        expenseFormEl.classList.toggle('has-refacturare', enabled);
        if (expenseLayoutEl instanceof HTMLElement) {
            expenseLayoutEl.classList.toggle('has-refacturare', enabled);
        }
        if (refacturareMenuEl instanceof HTMLElement) {
            refacturareMenuEl.classList.toggle('d-none', !enabled);
        }
        if (refacturareFieldsEl instanceof HTMLElement) {
            refacturareFieldsEl.classList.toggle('d-none', !enabled);
        }
        if (refacturareDateEl instanceof HTMLInputElement) {
            refacturareDateEl.required = enabled;
        }
        if (typeof syncRefacturareTypes === 'function') {
            syncRefacturareTypes(enabled);
        }
    };

    if (refacturareToggleEl instanceof HTMLInputElement) {
        refacturareToggleEl.addEventListener('change', syncRefacturare);
    }

    // Sugestiile de locatie urmeaza beneficiarul ales pe cursa, fara reincarcarea paginii.
    var locationDatalistEl = document.getElementById('expense_location_options');
    var beneficiaryEl = document.getElementById('edit_race_beneficiar_id');

    var locationsByBeneficiary = {};
    if (locationDatalistEl instanceof HTMLElement) {
        try {
            locationsByBeneficiary = JSON.parse(locationDatalistEl.getAttribute('data-by-beneficiary') || '{}') || {};
        } catch (error) {
            locationsByBeneficiary = {};
        }
    }

    // Lista activa, folosita si de completarea inline din campul de locatie.
    var activeLocationValues = [];

    /**
     * Prima locatie care incepe cu textul scris, ca sa o putem completa inline.
     * Comparatie case-insensitive, dar pastram scrierea corecta din configurare.
     */
    var findLocationCompletion = function (typed) {
        if (typed === '') {
            return null;
        }

        var needle = typed.toLowerCase();
        for (var i = 0; i < activeLocationValues.length; i++) {
            var candidate = String(activeLocationValues[i]);
            if (candidate.length > typed.length && candidate.toLowerCase().indexOf(needle) === 0) {
                return candidate;
            }
        }

        return null;
    };

    // Completarea propusa e selectata la finalul campului: Tab / Enter / sageata dreapta o accepta,
    // orice alta tastare o inlocuieste, Escape o renunta.
    var hasPendingCompletion = function (input) {
        return input.selectionStart !== input.selectionEnd && input.selectionEnd === input.value.length;
    };

    var acceptCompletion = function (input) {
        var end = input.value.length;
        input.setSelectionRange(end, end);
    };

    var applyInlineCompletion = function (input, event) {
        if (event && event.isComposing) {
            return;
        }

        // La stergere nu completam inapoi, altfel nu s-ar putea sterge niciodata ultima litera.
        var inputType = (event && event.inputType) ? String(event.inputType) : '';
        if (inputType.indexOf('delete') === 0 || inputType === 'historyUndo') {
            return;
        }

        // Completam doar cand cursorul e la finalul textului scris.
        if (input.selectionStart !== input.value.length) {
            return;
        }

        var typed = input.value;
        var match = findLocationCompletion(typed);
        if (match === null) {
            return;
        }

        input.value = match;
        input.setSelectionRange(typed.length, match.length);
    };

    Array.prototype.forEach.call(expenseFormEl.querySelectorAll('input[list="expense_location_options"]'), function (input) {
        input.addEventListener('input', function (event) {
            applyInlineCompletion(input, event);
        });

        input.addEventListener('keydown', function (event) {
            if (!hasPendingCompletion(input)) {
                return;
            }

            if (event.key === 'Enter') {
                // Enter accepta sugestia, nu trimite formularul.
                event.preventDefault();
                acceptCompletion(input);
                return;
            }

            if (event.key === 'ArrowRight' || event.key === 'End') {
                acceptCompletion(input);
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                input.value = input.value.slice(0, input.selectionStart);
            }
        });

        input.addEventListener('blur', function () {
            if (hasPendingCompletion(input)) {
                acceptCompletion(input);
            }
        });
    });

    var syncLocationSuggestions = function () {
        if (!(locationDatalistEl instanceof HTMLElement)) {
            return;
        }

        var beneficiaryId = beneficiaryEl instanceof HTMLSelectElement ? String(beneficiaryEl.value || '') : '';
        var beneficiaryLabel = '';
        if (beneficiaryEl instanceof HTMLSelectElement && beneficiaryEl.selectedIndex >= 0) {
            var selectedOption = beneficiaryEl.options[beneficiaryEl.selectedIndex];
            if (selectedOption && selectedOption.value !== '') {
                beneficiaryLabel = selectedOption.textContent.trim();
            }
        }

        var values = locationsByBeneficiary[beneficiaryId];
        if (!Array.isArray(values) || values.length === 0) {
            values = Array.isArray(locationsByBeneficiary['0']) ? locationsByBeneficiary['0'] : [];
            beneficiaryLabel = '';
        }

        locationDatalistEl.innerHTML = '';
        values.forEach(function (value) {
            var option = document.createElement('option');
            option.value = value;
            locationDatalistEl.appendChild(option);
        });
        activeLocationValues = values;

        var hint = values.length === 0
            ? 'Nu exista inca locatii salvate. Scrie una noua; va aparea in sugestii data viitoare.'
            : (beneficiaryLabel !== ''
                ? values.length + ' locatii pentru ' + beneficiaryLabel + '. Scrie primele litere si apasa Tab, sau sageata jos pentru toata lista.'
                : values.length + ' locatii disponibile. Alege beneficiarul cursei ca sa le filtram.');

        Array.prototype.forEach.call(expenseFormEl.querySelectorAll('[data-role="expense-location-hint"]'), function (el) {
            el.textContent = hint;
        });
    };

    if (beneficiaryEl instanceof HTMLSelectElement) {
        beneficiaryEl.addEventListener('change', syncLocationSuggestions);
    }
    syncLocationSuggestions();

    // Calendarul cheltuielilor urmeaza intervalul cursei chiar daca schimbi datele cursei
    // fara sa reincarci pagina. Campurile cursei tin data in format zi/luna/an.
    var raceStartEl = document.getElementById('edit_race_data_inceput');
    var raceEndEl = document.getElementById('edit_race_data_sfarsit');
    var expenseDateEls = Array.prototype.slice.call(expenseFormEl.querySelectorAll('[data-role="expense-date"]'));

    var toIsoDate = function (value) {
        var raw = String(value || '').trim();
        if (raw === '') {
            return '';
        }

        var roMatch = raw.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/);
        if (roMatch) {
            return roMatch[3] + '-' + ('0' + roMatch[2]).slice(-2) + '-' + ('0' + roMatch[1]).slice(-2);
        }

        return /^\d{4}-\d{2}-\d{2}$/.test(raw) ? raw : '';
    };

    var formatRoDate = function (isoDate) {
        var parts = String(isoDate || '').split('-');
        return parts.length === 3 ? parts[2] + '.' + parts[1] + '.' + parts[0] : isoDate;
    };

    var syncExpenseDateRange = function () {
        var min = raceStartEl instanceof HTMLInputElement ? toIsoDate(raceStartEl.value) : '';
        var max = raceEndEl instanceof HTMLInputElement ? toIsoDate(raceEndEl.value) : '';
        if (min !== '' && max !== '' && max < min) {
            var swap = min;
            min = max;
            max = swap;
        }

        var hint = '';
        if (min !== '' && max !== '') {
            hint = min === max
                ? 'Cursa este intr-o singura zi: ' + formatRoDate(min) + '.'
                : 'Doar in intervalul cursei: ' + formatRoDate(min) + ' - ' + formatRoDate(max) + '.';
        }

        expenseDateEls.forEach(function (input) {
            if (min !== '') {
                input.min = min;
            } else {
                input.removeAttribute('min');
            }
            if (max !== '') {
                input.max = max;
            } else {
                input.removeAttribute('max');
            }

            // Daca data ramasa in camp a iesit din interval, o aducem inapoi in el.
            var current = String(input.value || '');
            if (current === '' && min !== '') {
                input.value = min;
            } else if (min !== '' && current !== '' && current < min) {
                input.value = min;
            } else if (max !== '' && current !== '' && current > max) {
                input.value = max;
            }
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-role="expense-date-hint"]'), function (el) {
            el.textContent = hint;
            el.classList.toggle('d-none', hint === '');
        });
    };

    document.addEventListener('change', function (event) {
        if (event.target === raceStartEl || event.target === raceEndEl) {
            syncExpenseDateRange();
        }
    });
    expenseDateEls.forEach(function (input) {
        input.addEventListener('focus', syncExpenseDateRange);
    });
    syncExpenseDateRange();

    if (typeof syncExpenseTypes === 'function') {
        syncExpenseTypes(true);
    }
    syncRefacturare();
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

<script src="<?= e(url('assets/js/tariff-recalc.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/tariff-recalc.js'))) ?>" defer></script>
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
