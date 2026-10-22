<?php
/**
 * Variabilele folosite de randul unei anvelope (_tire_row.php), comune paginii Stoc anvelope
 * si randurilor incarcate la extinderea unui grup (ModuleController::tireStockChildrenAction).
 *
 * Intrari: $tireStockFormOptions (TireModel::getTireRowFormOptions() sau contextul paginii),
 *          $tireStockPickerPrefix (optional; prefix unic pentru id-urile selectorului de vehicul).
 */
$targetTypeOptions = is_array($tireStockFormOptions['target_type_options'] ?? null) ? $tireStockFormOptions['target_type_options'] : [];
$targetVehicleTypeOptions = $targetTypeOptions;
unset($targetVehicleTypeOptions['universal']);
$tireAxleTypeOptions = is_array($tireStockFormOptions['axle_type_options'] ?? null) ? $tireStockFormOptions['axle_type_options'] : [];
$tireAxleTypeFormOptions = $tireAxleTypeOptions;
unset($tireAxleTypeFormOptions['universal_balloon']);
$tireStatusOptions = is_array($tireStockFormOptions['status_options'] ?? null) ? $tireStockFormOptions['status_options'] : [];
$initialConditionOptions = is_array($tireStockFormOptions['initial_condition_options'] ?? null) ? $tireStockFormOptions['initial_condition_options'] : ['new' => 'Noua', 'used' => 'Folosita'];
$vehiclePickerPrefix = (string) ($tireStockPickerPrefix ?? 'tireVehiclePicker');
$vehiclePickerCounter = 0;
$renderVehicleCompatibilityPicker = static function (string $fieldName, array $selectedValues, bool $required = false) use ($targetVehicleTypeOptions, $vehiclePickerPrefix, &$vehiclePickerCounter): void {
    $vehiclePickerCounter++;
    $pickerId = $vehiclePickerPrefix . $vehiclePickerCounter;
    $selectedMap = [];
    foreach ($selectedValues as $selectedValue) {
        $selectedMap[(string) $selectedValue] = true;
    }
    ?>
    <div class="tire-vehicle-picker dropdown" data-tire-vehicle-picker data-required="<?= $required ? '1' : '0' ?>">
        <button class="form-select tire-vehicle-picker-toggle" type="button" id="<?= e($pickerId) ?>" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
            <span data-tire-vehicle-picker-label>Selecteaza tip vehicul</span>
        </button>
        <div class="dropdown-menu tire-vehicle-picker-menu" aria-labelledby="<?= e($pickerId) ?>">
            <?php foreach ($targetVehicleTypeOptions as $value => $label): ?>
                <label class="dropdown-item tire-vehicle-picker-option">
                    <input class="form-check-input" type="checkbox" name="<?= e($fieldName) ?>" value="<?= e((string) $value) ?>" data-tire-vehicle-option <?= isset($selectedMap[(string) $value]) ? 'checked' : '' ?>>
                    <span><?= e((string) $label) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
};
$formatKm = static function ($value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float) $value, 0, ',', '.') . ' km';
};
