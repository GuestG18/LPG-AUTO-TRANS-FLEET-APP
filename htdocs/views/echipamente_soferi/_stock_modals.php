<?php
/**
 * Modalele paginii "Stoc echipamente": intrare în stoc (recepție), ieșire din
 * stoc (cu motiv, fără ștergere de istoric) și setările liniei de stoc.
 */

require __DIR__ . '/_form_helpers.php';

$locationOptions = is_array($locations ?? null) ? $locations : [DriverEquipmentModel::DEFAULT_LOCATION];
?>

<?php // Gestiunile existente sunt sugestii; se poate scrie oricând una nouă. ?>
<datalist id="desStockLocationOptions">
    <?php foreach ($locationOptions as $locationOption): ?>
        <option value="<?= e((string) $locationOption) ?>"></option>
    <?php endforeach; ?>
</datalist>

<!-- ------------------------------------------------------ Intrare în stoc -->
<div class="modal fade des-modal" id="desStockInModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="<?= e($formAction('stoc_intrare')) ?>" data-des-form="stock-in">
                <?= csrf_field() ?>
                <?php $keepFilters(); ?>
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title">Intrare în stoc</h2>
                        <p>Recepție de marfă. Articolele serializate primesc automat câte o unitate în inventar.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <label class="form-label" for="desStockInCatalog">Echipament</label>
                            <select class="form-select" id="desStockInCatalog" name="catalog_id" required data-des-field="catalog">
                                <option value="">Selectează articolul din catalog</option>
                                <optgroup label="Echipamente fizice"><?php $catalogOptions($catalogItems, 'fizic'); ?></optgroup>
                                <optgroup label="Comunicații"><?php $catalogOptions($catalogItems, 'comunicatii'); ?></optgroup>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label" for="desStockInQty">Cantitate</label>
                            <input class="form-control" id="desStockInQty" type="number" name="cantitate" value="1" min="1" step="1" required>
                        </div>
                        <div class="col-6 col-md-3" data-des-when="marime" hidden>
                            <label class="form-label" for="desStockInSize">Mărime</label>
                            <input class="form-control" id="desStockInSize" type="text" name="marime" placeholder="ex. 43 / L" maxlength="20">
                        </div>

                        <div class="col-12">
                            <div class="des-callout is-neutral" data-des-stock-hint>
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                <div><strong>Stoc curent</strong><span>Selectează un articol.</span></div>
                            </div>
                        </div>

                        <div class="col-6 col-md-4">
                            <label class="form-label" for="desStockInCost">Cost / buc. (lei)</label>
                            <input class="form-control" id="desStockInCost" type="number" name="cost_unitar" min="0" step="0.01" placeholder="din catalog">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="desStockInDate">Data intrării</label>
                            <input class="form-control" id="desStockInDate" type="date" name="data_intrarii" value="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="desStockInLocation">Locație / gestiune</label>
                            <input class="form-control" id="desStockInLocation" type="text" name="locatie" maxlength="120"
                                   list="desStockLocationOptions" data-des-field="locatie" placeholder="din catalog sau gestiune nouă">
                        </div>

                        <?php // Câmpurile de identificare apar doar pentru articolele urmărite bucată cu bucată. ?>
                        <div class="col-12 col-md-6" data-des-when="serie" hidden>
                            <label class="form-label" for="desStockInSerial">Serie / IMEI</label>
                            <input class="form-control" id="desStockInSerial" type="text" name="serie" maxlength="120" placeholder="doar pentru o singură bucată">
                        </div>
                        <div class="col-6 col-md-3" data-des-when="sim" hidden>
                            <label class="form-label" for="desStockInPhone">Număr telefon</label>
                            <input class="form-control" id="desStockInPhone" type="text" name="numar_telefon" maxlength="40" placeholder="07xx xxx xxx">
                        </div>
                        <div class="col-6 col-md-3" data-des-when="sim" hidden>
                            <label class="form-label" for="desStockInIccid">ICCID</label>
                            <input class="form-control" id="desStockInIccid" type="text" name="iccid" maxlength="40" placeholder="8940...">
                        </div>
                        <div class="col-6 col-md-3" data-des-when="sim" hidden>
                            <label class="form-label" for="desStockInOperator">Operator</label>
                            <input class="form-control" id="desStockInOperator" type="text" name="operator" maxlength="60" placeholder="ex. Orange">
                        </div>
                        <div class="col-6 col-md-3" data-des-when="sim" hidden>
                            <label class="form-label" for="desStockInSubscription">Tip</label>
                            <select class="form-select" id="desStockInSubscription" name="tip_abonament">
                                <?php foreach (DriverEquipmentModel::SUBSCRIPTION_TYPES as $value => $label): ?>
                                    <option value="<?= e((string) $value) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3" data-des-when="sim" hidden>
                            <label class="form-label" for="desStockInMonthly">Cost lunar (lei)</label>
                            <input class="form-control" id="desStockInMonthly" type="number" name="cost_lunar" min="0" step="0.01" placeholder="din catalog">
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="desStockInSupplier">Furnizor</label>
                            <input class="form-control" id="desStockInSupplier" type="text" name="furnizor" maxlength="120" placeholder="opțional">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="desStockInDocument">Document / factură</label>
                            <input class="form-control" id="desStockInDocument" type="text" name="document" maxlength="120" placeholder="opțional">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="desStockInNotes">Observații</label>
                            <input class="form-control" id="desStockInNotes" type="text" name="observatii" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="des-btn" type="button" data-bs-dismiss="modal">Renunță</button>
                    <button class="des-btn des-btn-primary" type="submit"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>Înregistrează intrarea</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ------------------------------------------------------ Ieșire din stoc -->
<div class="modal fade des-modal" id="desStockOutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e($formAction('stoc_iesire')) ?>" data-des-form="stock-out">
                <?= csrf_field() ?>
                <?php $keepFilters(); ?>
                <input type="hidden" name="unitate_id" data-des-field="unitate">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title">Scoate din stoc</h2>
                        <p>Ieșirea se înregistrează cu motiv și rămâne în istoricul mișcărilor.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <div class="des-summary-line mb-3" data-des-unit-line hidden>
                        <span class="des-item-icon"><i class="bi bi-upc-scan" aria-hidden="true"></i></span>
                        <div>
                            <strong data-des-text="unitate">—</strong>
                            <span>unitate identificată individual</span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <label class="form-label" for="desStockOutCatalog">Echipament</label>
                            <select class="form-select" id="desStockOutCatalog" name="catalog_id" required data-des-field="catalog">
                                <option value="">Selectează articolul</option>
                                <optgroup label="Echipamente fizice"><?php $catalogOptions($catalogItems, 'fizic'); ?></optgroup>
                                <optgroup label="Comunicații"><?php $catalogOptions($catalogItems, 'comunicatii'); ?></optgroup>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label" for="desStockOutQty">Cantitate</label>
                            <input class="form-control" id="desStockOutQty" type="number" name="cantitate" value="1" min="1" step="1" required data-des-field="cantitate">
                        </div>
                        <div class="col-6 col-md-3" data-des-when="marime" hidden>
                            <label class="form-label" for="desStockOutSize">Mărime</label>
                            <input class="form-control" id="desStockOutSize" type="text" name="marime" maxlength="20" data-des-field="marime">
                        </div>

                        <div class="col-12">
                            <div class="des-callout is-neutral" data-des-stock-hint>
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                <div><strong>Stoc curent</strong><span>Selectează un articol.</span></div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="desStockOutReason">Motiv</label>
                            <select class="form-select" id="desStockOutReason" name="motiv" required data-des-field="motiv">
                                <?php foreach (DriverEquipmentModel::REMOVAL_REASONS as $value => $label): ?>
                                    <option value="<?= e((string) $value) ?>" <?= $value === 'casat' ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="desStockOutLocation">Locație / gestiune</label>
                            <input class="form-control" id="desStockOutLocation" type="text" name="locatie" maxlength="120"
                                   list="desStockLocationOptions" data-des-field="locatie" placeholder="din catalog">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="desStockOutNotes">Observații</label>
                            <input class="form-control" id="desStockOutNotes" type="text" name="observatii" maxlength="255" placeholder="ex. casat prin proces-verbal 12/2026">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="des-btn" type="button" data-bs-dismiss="modal">Renunță</button>
                    <button class="des-btn des-btn-primary" type="submit"><i class="bi bi-box-arrow-up" aria-hidden="true"></i>Înregistrează ieșirea</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- --------------------------------------------------------- Setări stoc -->
<div class="modal fade des-modal" id="desStockSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e($formAction('stoc_setari')) ?>">
                <?= csrf_field() ?>
                <?php $keepFilters(); ?>
                <input type="hidden" name="stoc_id" data-des-field="stoc">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title">Setări stoc</h2>
                        <p data-des-text="denumire">—</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="desStockThreshold">Prag minim</label>
                            <input class="form-control" id="desStockThreshold" type="number" name="prag_minim" min="0" step="1" data-des-field="prag">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="desStockCost">Cost / buc. (lei)</label>
                            <input class="form-control" id="desStockCost" type="number" name="cost_unitar" min="0" step="0.01" data-des-field="cost">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="desStockUsable" name="utilizabil_inlocuire" data-des-field="utilizabil">
                                <label class="form-check-label" for="desStockUsable">
                                    Utilizabil la înlocuire
                                    <span class="des-note">Debifat, articolul nu este propus ca soluție de înlocuire.</span>
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="desStockSettingsNotes">Observații</label>
                            <input class="form-control" id="desStockSettingsNotes" type="text" name="observatii" maxlength="255" data-des-field="observatii">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="des-btn" type="button" data-bs-dismiss="modal">Renunță</button>
                    <button class="des-btn des-btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i>Salvează</button>
                </div>
            </form>
        </div>
    </div>
</div>
