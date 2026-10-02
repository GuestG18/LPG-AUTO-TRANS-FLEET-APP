<?php
/**
 * Campurile unei facturi, comune formularului de incarcare (modal din lista) si
 * formularului de editare din pagina de detaliu.
 *
 * Asteapta: $fieldValues (randul sau []), $fieldPrefix (prefix pentru id-uri),
 * $vehicles, $drivers, $fieldsDisabled (bool).
 */
$fieldValues = is_array($fieldValues ?? null) ? $fieldValues : [];
$fieldPrefix = (string) ($fieldPrefix ?? 'factura');
$fieldsDisabled = (bool) ($fieldsDisabled ?? false);
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$drivers = is_array($drivers ?? null) ? $drivers : [];
$disabledAttr = $fieldsDisabled ? ' disabled' : '';

$value = static fn(string $key): string => (string) ($fieldValues[$key] ?? '');
$decimal = static fn(string $key): string => ($fieldValues[$key] ?? null) !== null && $fieldValues[$key] !== ''
    ? number_format((float) $fieldValues[$key], 2, ',', '')
    : '';
$selectedType = $value('tip');
?>
<div class="row g-2">
    <div class="col-12 col-md-6">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>Tip">Tip <span class="text-danger">*</span></label>
        <select class="form-select form-select-sm" id="<?= e($fieldPrefix) ?>Tip" name="tip" required<?= $disabledAttr ?>>
            <option value="">Alege tipul…</option>
            <?php foreach (InvoiceModel::TYPES as $typeKey => $typeConfig): ?>
                <option value="<?= e($typeKey) ?>" <?= $selectedType === $typeKey ? 'selected' : '' ?>>
                    <?= e($typeConfig['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>Data">Data documentului <span class="text-danger">*</span></label>
        <input type="date" class="form-control form-control-sm" id="<?= e($fieldPrefix) ?>Data" name="data_document" value="<?= e($value('data_document')) ?>" required<?= $disabledAttr ?>>
    </div>
    <div class="col-12 col-md-7">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>Furnizor">Furnizor</label>
        <input type="text" class="form-control form-control-sm" id="<?= e($fieldPrefix) ?>Furnizor" name="furnizor" maxlength="255" value="<?= e($value('furnizor')) ?>"<?= $disabledAttr ?>>
    </div>
    <div class="col-12 col-md-5">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>Numar">Nr. document</label>
        <input type="text" class="form-control form-control-sm" id="<?= e($fieldPrefix) ?>Numar" name="numar_document" maxlength="100" value="<?= e($value('numar_document')) ?>"<?= $disabledAttr ?>>
    </div>
    <div class="col-6 col-md-4">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>FaraTva">Fără TVA</label>
        <input type="text" inputmode="decimal" class="form-control form-control-sm" id="<?= e($fieldPrefix) ?>FaraTva" name="valoare_fara_tva" placeholder="0,00" value="<?= e($decimal('valoare_fara_tva')) ?>"<?= $disabledAttr ?>>
    </div>
    <div class="col-6 col-md-5">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>CuTva">Cu TVA <span class="text-danger">*</span></label>
        <input type="text" inputmode="decimal" class="form-control form-control-sm" id="<?= e($fieldPrefix) ?>CuTva" name="valoare_cu_tva" placeholder="0,00" value="<?= e($decimal('valoare_cu_tva')) ?>" required<?= $disabledAttr ?>>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>Moneda">Moneda</label>
        <input type="text" class="form-control form-control-sm text-uppercase" id="<?= e($fieldPrefix) ?>Moneda" name="moneda" maxlength="3" value="<?= e($value('moneda') !== '' ? $value('moneda') : 'RON') ?>"<?= $disabledAttr ?>>
    </div>
    <div class="col-12 form-text mt-0">Pe cursă intră valoarea <strong>cu TVA</strong>, în lei.</div>

    <div class="col-12 col-md-6">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>Vehicul">Vehicul</label>
        <select class="form-select form-select-sm" id="<?= e($fieldPrefix) ?>Vehicul" name="vehicle_id"<?= $disabledAttr ?>>
            <option value="">— după nr. de pe factură —</option>
            <?php foreach ($vehicles as $vehicle): ?>
                <option value="<?= (int) $vehicle['id'] ?>" <?= (int) ($fieldValues['vehicle_id'] ?? 0) === (int) $vehicle['id'] ? 'selected' : '' ?>>
                    <?= e((string) $vehicle['nr_inmatriculare']) ?><?= (string) $vehicle['status'] !== 'activ' ? ' (inactiv)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>NrExtras">Nr. auto de pe factură</label>
        <input type="text" class="form-control form-control-sm text-uppercase" id="<?= e($fieldPrefix) ?>NrExtras" name="nr_inmatriculare_extras" maxlength="30" value="<?= e($value('nr_inmatriculare_extras')) ?>" placeholder="ex. TM 07 LPG"<?= $disabledAttr ?>>
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>Sofer">Șofer</label>
        <select class="form-select form-select-sm" id="<?= e($fieldPrefix) ?>Sofer" name="driver_id"<?= $disabledAttr ?>>
            <option value="">— după numele de pe factură —</option>
            <?php foreach ($drivers as $driver): ?>
                <option value="<?= (int) $driver['id'] ?>" <?= (int) ($fieldValues['driver_id'] ?? 0) === (int) $driver['id'] ? 'selected' : '' ?>>
                    <?= e((string) $driver['nume']) ?><?= (string) $driver['status'] !== 'activ' ? ' (inactiv)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-6">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>SoferExtras">Nume șofer de pe factură</label>
        <input type="text" class="form-control form-control-sm" id="<?= e($fieldPrefix) ?>SoferExtras" name="sofer_extras" maxlength="150" value="<?= e($value('sofer_extras')) ?>"<?= $disabledAttr ?>>
    </div>
    <div class="col-12 form-text mt-0">Alegerea din listă are prioritate; textul de pe factură se folosește când lista e goală.</div>

    <div class="col-12">
        <label class="form-label small mb-1" for="<?= e($fieldPrefix) ?>Obs">Observații</label>
        <textarea class="form-control form-control-sm" id="<?= e($fieldPrefix) ?>Obs" name="observatii" rows="2"<?= $disabledAttr ?>><?= e($value('observatii')) ?></textarea>
    </div>
</div>
