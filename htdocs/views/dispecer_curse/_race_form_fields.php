<?php
/**
 * Campurile formularului de cursa, folosite in DOUA locuri:
 *   - formularul "Adauga cursa" din Desfasurator ($fieldMode = 'trip');
 *   - formularul unei FAZE a cursei ($fieldMode = 'phase'), ca faza sa se
 *     completeze exact ca o cursa: aceleasi campuri, aceleasi etichete si acelasi
 *     comportament (initRaceForm din dispecer-curse.js ruleaza pe orice formular
 *     cu clasa .dispatcher-race-form).
 *
 * Diferenta la faze: beneficiarul, tipul de transport si tipul de marfa apartin
 * cursei, deci se trimit ascunse (JS-ul le citeste ca sa filtreze locurile si
 * zonele), iar tariful nu se previzualizeaza — cursa se factureaza o singura data.
 *
 * Variabile asteptate: $formData, $formErrors si listele de optiuni din pagina.
 *   $fieldPrefix - prefixul id-urilor ("race" pentru cursa, "phase_<id>" pentru faze)
 *   $fieldMode   - 'trip' | 'phase'
 */

$fieldPrefix = isset($fieldPrefix) && $fieldPrefix !== '' ? (string) $fieldPrefix : 'race';
$fieldMode = isset($fieldMode) && $fieldMode === 'phase' ? 'phase' : 'trip';
$fieldIsPhase = $fieldMode === 'phase';
$formData = isset($formData) && is_array($formData) ? $formData : [];
$formErrors = isset($formErrors) && is_array($formErrors) ? $formErrors : [];

// Valorile derivate se calculeaza AICI, din $formData, ca partialul sa mearga la
// fel si pentru o cursa si pentru o faza (fiecare faza are alt $formData).
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
    if ($selectedGoodsTypeKey !== '') {
        $selectedGoodsTypeKeys[$selectedGoodsTypeKey] = $selectedGoodsTypeKey;
    }
}
$selectedGoodsTypeKeys = array_values($selectedGoodsTypeKeys);
$selectedGoodsTypeLabels = [];
foreach ($selectedGoodsTypeKeys as $selectedGoodsTypeKey) {
    if (isset($goodsTypeOptions[$selectedGoodsTypeKey])) {
        $selectedGoodsTypeLabels[] = (string) $goodsTypeOptions[$selectedGoodsTypeKey];
    }
}
$selectedGoodsTypeButtonLabel = $selectedGoodsTypeLabels !== [] ? implode(', ', $selectedGoodsTypeLabels) : '-- Selecteaza --';

$formStartTimeValue = substr(trim((string) ($formData['ora_inceput'] ?? '')), 0, 5);
$formEndTimeValue = substr(trim((string) ($formData['ora_sfarsit'] ?? '')), 0, 5);
$formDurationMinutes = null;
$formDurationMinutesRaw = $formData['durata_cursa_minute'] ?? null;
if ($formDurationMinutesRaw !== null && $formDurationMinutesRaw !== '' && is_numeric((string) $formDurationMinutesRaw)) {
    $formDurationMinutes = max(0, (int) $formDurationMinutesRaw);
}
$formDurationPreviewText = $formDurationMinutes !== null
    ? 'Durata calculata: ' . intdiv($formDurationMinutes, 60) . 'h ' . ($formDurationMinutes % 60) . 'm'
    : 'Durata se calculeaza automat dupa ora inceput/sfarsit.';
if (!isset($formatRaceDateForDisplay) || !is_callable($formatRaceDateForDisplay)) {
    $formatRaceDateForDisplay = static function (string $value): string {
        $value = trim($value);
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return $value;
        }

        return $date->format('d/m/Y');
    };
}
?>
                        <?php if (isset($formErrors['inactive_resources'])): ?>
                            <div class="col-12">
                                <div class="alert alert-warning d-flex align-items-center gap-2 mb-0" role="alert">
                                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                                    <span><?= e((string) $formErrors['inactive_resources']) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="col-12 col-md-6 dispatcher-top-field" data-role="field-beneficiar">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_beneficiar_id">Beneficiar transport <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['beneficiar_id']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_beneficiar_id" name="beneficiar_id" required>
                                <?php if (!$fieldIsPhase): ?><option value="">-- Selecteaza --</option><?php endif; ?>
                                <?php foreach ($beneficiaries as $beneficiary): ?>
                                    <?php $beneficiaryId = (int) ($beneficiary['id'] ?? 0); ?>
                                    <?php $beneficiarySelected = (string) ($formData['beneficiar_id'] ?? '') === (string) $beneficiaryId; ?>
                                    <?php if ($fieldIsPhase && !$beneficiarySelected) { continue; } ?>
                                    <option value="<?= e((string) $beneficiaryId) ?>" <?= $beneficiarySelected ? 'selected' : '' ?>>
                                        <?= e((string) ($beneficiary['nume'] ?? '-')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($formErrors['beneficiar_id'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['beneficiar_id']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-top-field" data-role="field-tip-transport">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_tip_transport">Tip Transport <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['tip_transport']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_tip_transport" name="tip_transport" data-role="tip-transport" required>
                                <?php if (!$fieldIsPhase): ?><option value="">-- Selecteaza --</option><?php endif; ?>
                                <?php foreach ($transportTypes as $value => $label): ?>
                                    <?php $transportSelected = (string) ($formData['tip_transport'] ?? '') === (string) $value; ?>
                                    <?php if ($fieldIsPhase && !$transportSelected) { continue; } ?>
                                    <option value="<?= e((string) $value) ?>" <?= $transportSelected ? 'selected' : '' ?>>
                                        <?= e((string) $label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($formErrors['tip_transport'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tip_transport']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-top-field" data-role="field-vehicul">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_vehicle_id">Nr. Înmatriculare <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['vehicle_id']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_vehicle_id" name="vehicle_id" required title="Pentru Primar km/tone: se afiseaza vehicule active cu sofer asociat. Pentru celelalte tipuri: filtrare dupa beneficiar si configurari.">
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

                        <div class="col-12 col-md-6 dispatcher-top-field" data-role="field-sofer">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_driver_id">Sofer <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['driver_id']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_driver_id" name="driver_id" required title="Soferii se incarca automat dupa vehiculul selectat.">
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
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_start_datetime">Data si ora inceput <span class="text-danger">*</span></label>
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
                                        id="<?= e($fieldPrefix) ?>_start_datetime"
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
                                    id="<?= e($fieldPrefix) ?>_data_inceput"
                                    name="data_inceput"
                                    value="<?= e($startDateDisplayValue) ?>"
                                    required
                                >
                                <input
                                    type="hidden"
                                    id="<?= e($fieldPrefix) ?>_ora_inceput"
                                    name="ora_inceput"
                                    value="<?= e($formStartTimeValue) ?>"
                                    data-role="ora-inceput"
                                >
                            </div>
                            <?php if (isset($formErrors['data_inceput'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['data_inceput']) ?></div><?php endif; ?>
                            <?php if (isset($formErrors['ora_inceput'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['ora_inceput']) ?></div><?php endif; ?>
                        </div>
                        <?php if (!$fieldIsPhase): ?>
                        <div class="col-12 col-md-6 dispatcher-schedule-field" data-role="field-data-incarcare">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_data_incarcare">Data incarcare</label>
                            <?php $loadingDateValue = (string) ($formData['data_incarcare'] ?? ''); ?>
                            <div class="input-group fleet-date-field">
                                <input type="text" class="form-control js-date-display-input <?= isset($formErrors['data_incarcare']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_data_incarcare" name="data_incarcare" value="<?= e($formatRaceDateForDisplay($loadingDateValue)) ?>" placeholder="dd/mm/yyyy" inputmode="numeric" maxlength="10" autocomplete="off" data-date-picker-id="<?= e($fieldPrefix) ?>_data_incarcare_picker">
                                <button type="button" class="btn btn-outline-secondary js-date-picker-button" data-date-picker-target="<?= e($fieldPrefix) ?>_data_incarcare_picker" aria-label="Deschide calendarul pentru data incarcarii"><i class="bi bi-calendar3" aria-hidden="true"></i></button>
                                <input type="date" id="<?= e($fieldPrefix) ?>_data_incarcare_picker" class="fleet-date-picker-native" value="<?= e($loadingDateValue) ?>" tabindex="-1" aria-hidden="true">
                            </div>
                            <?php if (isset($formErrors['data_incarcare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['data_incarcare']) ?></div><?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="col-12 col-md-6 dispatcher-schedule-field" data-role="field-end-datetime">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_end_datetime">Data si ora sfarsit <span class="text-danger">*</span></label>
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
                                        id="<?= e($fieldPrefix) ?>_end_datetime"
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
                                    id="<?= e($fieldPrefix) ?>_data_sfarsit"
                                    name="data_sfarsit"
                                    value="<?= e($endDateDisplayValue) ?>"
                                    required
                                >
                                <input
                                    type="hidden"
                                    id="<?= e($fieldPrefix) ?>_ora_sfarsit"
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
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_loc_incarcare_id">Loc Încărcare <span class="text-danger">*</span></label>
                            <select class="form-select <?= isset($formErrors['loc_incarcare_id']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_loc_incarcare_id" name="loc_incarcare_id" required title="Selecteaza locul de incarcare pentru cursa.">
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
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_loc_plecare">Loc plecare <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($formErrors['loc_plecare']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_loc_plecare" name="loc_plecare" value="<?= e((string) ($formData['loc_plecare'] ?? '')) ?>" data-role="loc-plecare">
                            <?php if (isset($formErrors['loc_plecare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['loc_plecare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-grid-field" data-role="field-loc-aspirare">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_loc_aspirare">Loc aspirare <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($formErrors['loc_aspirare']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_loc_aspirare" name="loc_aspirare" value="<?= e((string) ($formData['loc_aspirare'] ?? '')) ?>" data-role="loc-aspirare">
                            <?php if (isset($formErrors['loc_aspirare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['loc_aspirare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-grid-field" data-role="field-loc-livrare">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_loc_livrare">Loc livrare <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($formErrors['loc_livrare']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_loc_livrare" name="loc_livrare" value="<?= e((string) ($formData['loc_livrare'] ?? '')) ?>" data-role="loc-livrare">
                            <?php if (isset($formErrors['loc_livrare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['loc_livrare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-grid-field" data-role="field-loc-livrare-cursa">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_loc_livrare_cursa">Loc inchidere cursa <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?= isset($formErrors['loc_livrare_cursa']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_loc_livrare_cursa" name="loc_livrare_cursa" value="<?= e((string) ($formData['loc_livrare_cursa'] ?? '')) ?>" data-role="loc-livrare-cursa">
                            <?php if (isset($formErrors['loc_livrare_cursa'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['loc_livrare_cursa']) ?></div><?php endif; ?>
                        </div>

                        <?php if (!$fieldIsPhase): ?>
                        <div class="col-12 col-md-6 dispatcher-primary-grid-field dispatcher-compressor-grid-field dispatcher-compressor-metric-field" data-role="field-tip-marfa">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_tip_marfa">Tip marfa <span class="text-danger">*</span></label>
                            <div class="dropdown transport-multiselect-dropdown goods-multiselect-dropdown" data-role="goods-type-dropdown">
                                <button class="btn btn-outline-secondary dropdown-toggle w-100 text-start transport-multiselect-toggle <?= isset($formErrors['tip_marfa']) ? 'is-invalid' : '' ?>" type="button" id="<?= e($fieldPrefix) ?>_tip_marfa" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Poti selecta unul sau mai multe tipuri de marfa.">
                                    <span class="goods-multiselect-label" data-default-label="-- Selecteaza --"><?= e($selectedGoodsTypeButtonLabel) ?></span>
                                </button>
                                <div class="dropdown-menu w-100 transport-multiselect-menu p-2" aria-labelledby="<?= e($fieldPrefix) ?>_tip_marfa">
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
                        <?php endif; ?>

                        <div class="col-12 col-md-6 dispatcher-primary-grid-field" data-role="field-cantitate">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_cantitate_incarcata">Cantitate Încărcată</label>
                            <input type="number" class="form-control <?= isset($formErrors['cantitate_incarcata']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_cantitate_incarcata" name="cantitate_incarcata" step="0.01" min="0" value="<?= e((string) ($formData['cantitate_incarcata'] ?? '')) ?>" data-role="cantitate" title="Valoarea introdusa este folosita direct in calcule, fara conversie automata.">
                            <?php if (isset($formErrors['cantitate_incarcata'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['cantitate_incarcata']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-primary-grid-field" data-role="field-capacitate-transport">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_capacitate_transport">Capacitate transport reala</label>
                            <input type="number" class="form-control <?= isset($formErrors['capacitate_transport']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_capacitate_transport" name="capacitate_transport" step="0.01" min="0" value="<?= e((string) ($formData['capacitate_transport'] ?? '')) ?>" data-role="capacitate-transport" readonly title="Se completeaza automat din fisa vehiculului.">
                            <?php if (isset($formErrors['capacitate_transport'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['capacitate_transport']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6" data-role="field-km">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_km_cursa" data-role="km-label" data-default-label="Km efectuati" data-primary-km-label="Km agreati"><?= $isAgreedKmNamingSelected ? 'Km agreati' : 'Km efectuati' ?></label>
                            <input type="number" class="form-control <?= isset($formErrors['km_cursa']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_km_cursa" name="km_cursa" min="0" step="1" value="<?= e((string) ($formData['km_cursa'] ?? '')) ?>" data-role="km">
                            <?php if (isset($formErrors['km_cursa'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['km_cursa']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isDistributionSelected ? '' : 'd-none' ?>" data-role="field-nr-clienti">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_nr_clienti">Nr. Clienți</label>
                            <input type="number" class="form-control <?= isset($formErrors['nr_clienti']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_nr_clienti" name="nr_clienti" min="0" step="1" value="<?= e((string) ($formData['nr_clienti'] ?? '')) ?>">
                            <?php if (isset($formErrors['nr_clienti'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['nr_clienti']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6" data-role="field-zona">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_zona_distributie_id" data-role="zona-label" data-default-label="Zona distributie" data-primary-label="Zona descarcare" data-primary-km-label="Loc descarcare">Zona distributie</label>
                            <select class="form-select <?= isset($formErrors['zona_distributie_id']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_zona_distributie_id" name="zona_distributie_id" data-role="zona" title="Selecteaza zona de distributie/descarcare pentru ruta.">
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

                        <div class="col-12 col-md-6 d-none" data-role="field-ruta-plecare">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_ruta_plecare">Loc plecare (garaj)</label>
                            <select class="form-select" id="<?= e($fieldPrefix) ?>_ruta_plecare" name="loc_plecare_ruta" data-role="ruta-plecare" data-initial-value="<?= e((string) ($formData['loc_plecare'] ?? '')) ?>"></select>
                            <div class="form-text text-muted">Punctele de plecare configurate pe aceasta ruta. Km si pretul urmeaza varianta aleasa.</div>
                        </div>

                        <div class="col-12 col-md-6 d-none" data-role="field-ruta-intoarcere">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_ruta_intoarcere">Loc intoarcere (garaj)</label>
                            <select class="form-select" id="<?= e($fieldPrefix) ?>_ruta_intoarcere" name="loc_intoarcere" data-role="ruta-intoarcere" data-initial-value="<?= e((string) ($formData['loc_intoarcere'] ?? '')) ?>"></select>
                            <div class="form-text text-muted">Variantele configurate pe aceasta ruta. Km si pretul urmeaza varianta aleasa.</div>
                        </div>

                        <div class="col-12 col-md-6 <?= $isKmTotalSelected ? '' : 'd-none' ?>" data-role="field-km-totali">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_km_totali" data-role="km-total-label" data-default-label="Km totali" data-primary-km-label="Km efectuati"><?= $isAgreedKmNamingSelected ? 'Km efectuati' : 'Km totali' ?></label>
                            <input type="number" class="form-control <?= isset($formErrors['km_totali']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_km_totali" name="km_totali" min="0" step="1" value="<?= e((string) ($formData['km_totali'] ?? '')) ?>" data-role="km-totali">
                            <?php if (isset($formErrors['km_totali'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['km_totali']) ?></div><?php endif; ?>
                            <div class="form-text text-muted <?= $isPrimaryDistributionSelected ? '' : 'd-none' ?>" data-role="km-distributie-calculation">Cost/km Distributie (calcul): Km distributie = Km efectuati - Km agreati; Cost/km Distributie = Cost distributie (Pret tona x tone) / Km distributie.</div>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-compressor-metric-field" data-role="field-ore-aspirare">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_ore_aspirare">Ore aspirare</label>
                            <input type="text" class="form-control <?= isset($formErrors['ore_aspirare']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_ore_aspirare" name="ore_aspirare" value="<?= e((string) ($formData['ore_aspirare'] ?? '')) ?>" data-role="ore-aspirare" placeholder="ex: 2h sau 2" title="1h = 40 km echivalenti pentru scaderea Km revizie.">
                            <?php if (isset($formErrors['ore_aspirare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['ore_aspirare']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-metric-field" data-role="field-tona-aspirata-lichida">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_tona_aspirata_lichida">Tona lichida aspirata</label>
                            <input type="number" class="form-control <?= isset($formErrors['tona_aspirata_lichida']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_tona_aspirata_lichida" name="tona_aspirata_lichida" step="0.01" min="0" value="<?= e((string) ($formData['tona_aspirata_lichida'] ?? '')) ?>" data-role="tona-aspirata-lichida">
                            <?php if (isset($formErrors['tona_aspirata_lichida'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tona_aspirata_lichida']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 <?= $isCompressorSelected ? '' : 'd-none' ?> dispatcher-compressor-metric-field" data-role="field-tona-aspirata-gazoasa">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_tona_aspirata_gazoasa">Tona gazoasa aspirata</label>
                            <input type="number" class="form-control <?= isset($formErrors['tona_aspirata_gazoasa']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_tona_aspirata_gazoasa" name="tona_aspirata_gazoasa" step="0.01" min="0" value="<?= e((string) ($formData['tona_aspirata_gazoasa'] ?? '')) ?>" data-role="tona-aspirata-gazoasa">
                            <?php if (isset($formErrors['tona_aspirata_gazoasa'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tona_aspirata_gazoasa']) ?></div><?php endif; ?>
                        </div>

                        <?php /* Pe faza, cantitatea livrata se completeaza la orice tip de transport:
                                 marfa se incarca intr-o faza si se livreaza in urmatoarele. Pe cursa,
                                 campul ramane doar la Compresor, asa ca JS-ul nu trebuie sa il ascunda. */ ?>
                        <div class="col-12 col-md-6 dispatcher-compressor-metric-field" <?= $fieldIsPhase ? '' : 'data-role="field-tona-livrata"' ?>>
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_tona_livrata"><?= $fieldIsPhase ? 'Cantitate livrata in faza (tone)' : 'Cantitate livrata (tone)' ?></label>
                            <input type="number" class="form-control <?= isset($formErrors['tona_livrata']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_tona_livrata" name="tona_livrata" step="0.01" min="0" value="<?= e((string) ($formData['tona_livrata'] ?? '')) ?>" <?= $fieldIsPhase ? '' : 'data-role="tona-livrata"' ?>>
                            <?php if (isset($formErrors['tona_livrata'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['tona_livrata']) ?></div><?php endif; ?>
                        </div>

                        <div class="col-12 col-md-6 dispatcher-compressor-metric-field" data-role="field-km-dislocare">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_km_dislocare">Km efectuati</label>
                            <input type="number" class="form-control <?= isset($formErrors['km_dislocare']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_km_dislocare" name="km_dislocare" step="0.01" min="0" value="<?= e((string) ($formData['km_dislocare'] ?? '')) ?>" data-role="km-dislocare">
                            <?php if (isset($formErrors['km_dislocare'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['km_dislocare']) ?></div><?php endif; ?>
                        </div>

                        <?php /* Pe faza, previzualizarea tarifului nu se arata (cursa se factureaza o
                                 singura data), dar elementul ramane in pagina: initRaceForm() din
                                 dispecer-curse.js il cere ca sa porneasca, iar de el depinde tot
                                 comportamentul formularului. Ascuns cu style, ca JS-ul comuta clase. */ ?>
                        <div class="col-12 col-md-6 dispatcher-compressor-metric-field" data-role="preview-total-field"<?= $fieldIsPhase ? ' style="display:none" aria-hidden="true"' : '' ?>>
                            <?php if (!$fieldIsPhase): ?><label class="form-label">Total Facturare (estimare)</label><?php endif; ?>
                            <div class="dispatcher-total-preview" data-role="total-preview">0,00 lei</div>
                        </div>

                        <?php if (!$fieldIsPhase): ?>
                        <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-primar-field">
                            <label class="form-label">Cost/km Primar</label>
                            <div class="dispatcher-total-preview" data-role="cost-km-primar-preview">0,00 lei/km</div>
                        </div>
                        <?php endif; ?>

                        <?php if (!$fieldIsPhase): ?>
                        <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-distributie-field">
                            <label class="form-label">Cost/km Distribu?ie</label>
                            <div class="dispatcher-total-preview" data-role="cost-km-distributie-preview">0,00 lei/km</div>
                        </div>
                        <?php endif; ?>

                        <?php if (!$fieldIsPhase): ?>
                        <div class="col-12 col-md-6 d-none" data-role="preview-cost-km-mixt-field">
                            <label class="form-label">Cost/km Mixt</label>
                            <div class="dispatcher-total-preview" data-role="cost-km-mixt-preview">0,00 lei/km</div>
                        </div>
                        <?php endif; ?>

                        <?php /* Statusul de facturare nu se mai alege la creare: orice cursa noua intra automat "in curs de facturare". Se schimba doar din Centralizator Facturare. */ ?>

                        <div class="col-12">
                            <label class="form-label" for="<?= e($fieldPrefix) ?>_observatii">Observații</label>
                            <textarea class="form-control <?= isset($formErrors['observatii']) ? 'is-invalid' : '' ?>" id="<?= e($fieldPrefix) ?>_observatii" name="observatii" rows="3"><?= e((string) ($formData['observatii'] ?? '')) ?></textarea>
                            <?php if (isset($formErrors['observatii'])): ?><div class="invalid-feedback d-block"><?= e((string) $formErrors['observatii']) ?></div><?php endif; ?>
                        </div>
