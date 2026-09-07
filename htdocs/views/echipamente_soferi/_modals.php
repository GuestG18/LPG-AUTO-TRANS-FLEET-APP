<?php
/**
 * Modalele paginii "Echipamente șoferi": predare, înlocuire din stoc,
 * returnare, marcaje de stare și detaliile articolului alocat.
 */

require __DIR__ . '/_form_helpers.php';

// Pagina curentă decide cine primește echipamentul; inventarul rămâne comun.
$modalOwnerType = (string) ($ownerType ?? DriverEquipmentModel::OWNER_DRIVER) === DriverEquipmentModel::OWNER_STAFF
    ? DriverEquipmentModel::OWNER_STAFF
    : DriverEquipmentModel::OWNER_DRIVER;
$modalIsStaff = $modalOwnerType === DriverEquipmentModel::OWNER_STAFF;
$modalOwnerLabel = $modalIsStaff ? 'Persoană TESA' : 'Șofer';
$modalOwnerPlaceholder = $modalIsStaff ? 'Selectează persoana' : 'Selectează șoferul';
$modalOwnerOptions = is_array($ownerOptions ?? null) ? $ownerOptions : (is_array($driverOptions ?? null) ? $driverOptions : []);
?>

<!-- ------------------------------------------------- Predare echipament -->
<div class="modal fade des-modal" id="desHandoverModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="<?= e($formAction('predare')) ?>" data-des-form="handover">
                <?= csrf_field() ?>
                <?php $keepFilters(); ?>
                <input type="hidden" name="detinator_tip" value="<?= e($modalOwnerType) ?>">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title">Predă echipament</h2>
                        <p>Articolul iese din stoc și intră în evidența șoferului selectat.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="desHandoverDriver"><?= e($modalOwnerLabel) ?></label>
                            <select class="form-select" id="desHandoverDriver" name="driver_id" required data-des-field="driver">
                                <option value=""><?= e($modalOwnerPlaceholder) ?></option>
                                <?php foreach ($modalOwnerOptions as $ownerOption): ?>
                                    <?php $ownerFunction = trim((string) ($ownerOption['functie'] ?? '')); ?>
                                    <option value="<?= e((string) $ownerOption['id']) ?>">
                                        <?= e((string) $ownerOption['nume']) ?><?= $ownerFunction !== '' ? ' — ' . e($ownerFunction) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="desHandoverCatalog">Echipament</label>
                            <select class="form-select" id="desHandoverCatalog" name="catalog_id" required data-des-field="catalog">
                                <option value="">Selectează articolul</option>
                                <?php if ($modalIsStaff): ?>
                                    <optgroup label="IT &amp; Birou"><?php $catalogOptions($catalogItems, 'it_birou', 'tesa'); ?></optgroup>
                                    <optgroup label="Comunicații"><?php $catalogOptions($catalogItems, 'comunicatii', 'tesa'); ?></optgroup>
                                    <optgroup label="Acces &amp; alte active"><?php $catalogOptions($catalogItems, 'acces', 'tesa'); ?></optgroup>
                                    <optgroup label="Alte echipamente"><?php $catalogOptions($catalogItems, 'fizic', 'tesa'); ?></optgroup>
                                <?php else: ?>
                                    <optgroup label="Echipamente fizice"><?php $catalogOptions($catalogItems, 'fizic', 'sofer'); ?></optgroup>
                                    <optgroup label="Comunicații"><?php $catalogOptions($catalogItems, 'comunicatii', 'sofer'); ?></optgroup>
                                    <optgroup label="IT &amp; Birou"><?php $catalogOptions($catalogItems, 'it_birou', 'sofer'); ?></optgroup>
                                    <optgroup label="Acces &amp; alte active"><?php $catalogOptions($catalogItems, 'acces', 'sofer'); ?></optgroup>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <div class="des-callout is-neutral" data-des-stock-hint>
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                <div>
                                    <strong>Selectează un articol</strong>
                                    <span>Disponibilitatea din stoc apare aici.</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <label class="form-label" for="desHandoverQty">Cantitate</label>
                            <input class="form-control" id="desHandoverQty" type="number" name="cantitate" value="1" min="1" step="1" required>
                        </div>
                        <div class="col-6 col-md-3" data-des-when="marime" hidden>
                            <label class="form-label" for="desHandoverSize">Mărime</label>
                            <input class="form-control" id="desHandoverSize" type="text" name="marime" placeholder="ex. 43 / L">
                        </div>
                        <div class="col-12 col-md-6" data-des-when="identificator" hidden>
                            <label class="form-label" for="desHandoverIdent">Identificare (IMEI / număr / serie)</label>
                            <input class="form-control" id="desHandoverIdent" type="text" name="identificator" placeholder="ex. 07xx 123 456">
                        </div>
                        <div class="col-12 col-md-4" data-des-when="operator" hidden>
                            <label class="form-label" for="desHandoverOperator">Operator</label>
                            <input class="form-control" id="desHandoverOperator" type="text" name="operator" placeholder="ex. Orange">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="desHandoverDate">Data predării</label>
                            <input class="form-control" id="desHandoverDate" type="date" name="data_predarii" value="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="desHandoverCost">Cost / buc. (lei)</label>
                            <input class="form-control" id="desHandoverCost" type="number" name="cost_unitar" step="0.01" min="0" placeholder="din catalog">
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="desHandoverCondition">Stare la predare</label>
                            <select class="form-select" id="desHandoverCondition" name="stare">
                                <?php foreach (DriverEquipmentModel::CONDITIONS as $value => $label): ?>
                                    <?php if (in_array($value, ['deteriorata', 'pierduta'], true)) { continue; } ?>
                                    <option value="<?= e((string) $value) ?>" <?= $value === 'noua' ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label" for="desHandoverSource">Sursa articolului</label>
                            <select class="form-select" id="desHandoverSource" name="sursa">
                                <option value="stoc">Din stoc (scade stocul)</option>
                                <option value="direct">Achiziție directă (nu atinge stocul)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="desHandoverNotes">Observații</label>
                            <input class="form-control" id="desHandoverNotes" type="text" name="observatii" maxlength="255" placeholder="ex. pentru contact dispecerat">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="des-btn" type="button" data-bs-dismiss="modal">Renunță</button>
                    <button class="des-btn des-btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i>Predă echipamentul</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ------------------------------------------------- Înlocuire din stoc -->
<div class="modal fade des-modal" id="desReplaceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e($formAction('inlocuieste')) ?>" data-des-form="replace">
                <?= csrf_field() ?>
                <?php $keepFilters(); ?>
                <input type="hidden" name="detinator_tip" value="<?= e($modalOwnerType) ?>">
                <input type="hidden" name="alocare_id" data-des-field="alocare">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title">Înlocuiește echipamentul</h2>
                        <p>Verificăm întâi dacă înlocuirea se poate face din stocul existent.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <div class="des-summary-line mb-3">
                        <span class="des-item-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                        <div>
                            <strong data-des-text="denumire">—</strong>
                            <span>alocat lui <span data-des-text="sofer">—</span> · stare curentă: <span data-des-text="stare">—</span></span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <label class="form-label" for="desReplaceCatalog">Articol de înlocuire</label>
                            <select class="form-select" id="desReplaceCatalog" name="catalog_id" required data-des-field="catalog">
                                <?php foreach (DriverEquipmentModel::GROUPS as $groupKey => $groupLabel): ?>
                                    <optgroup label="<?= e($groupLabel) ?>"><?php $catalogOptions($catalogItems, (string) $groupKey); ?></optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label" for="desReplaceQty">Cantitate</label>
                            <input class="form-control" id="desReplaceQty" type="number" name="cantitate" value="1" min="1" step="1" required>
                        </div>
                        <div class="col-6 col-md-3" data-des-when="marime" hidden>
                            <label class="form-label" for="desReplaceSize">Mărime</label>
                            <input class="form-control" id="desReplaceSize" type="text" name="marime">
                        </div>

                        <div class="col-12">
                            <div class="des-callout is-neutral" data-des-stock-hint>
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                <div>
                                    <strong>Verificare stoc</strong>
                                    <span>Selectează articolul de înlocuire.</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6" data-des-when="identificator" hidden>
                            <label class="form-label" for="desReplaceIdent">Identificare articol nou</label>
                            <input class="form-control" id="desReplaceIdent" type="text" name="identificator" placeholder="ex. IMEI nou">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="desReplaceReason">Ce se întâmplă cu articolul vechi</label>
                            <select class="form-select" id="desReplaceReason" name="motiv">
                                <option value="deteriorat">Deteriorat — nu revine în stoc</option>
                                <option value="uzat">Uzat, dar utilizabil — revine în stoc dacă e returnabil</option>
                                <option value="bun">În stare bună — revine în stoc dacă e returnabil</option>
                                <option value="pierdut">Pierdut — se scoate din evidență</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="desReplaceNotes">Observații</label>
                            <input class="form-control" id="desReplaceNotes" type="text" name="observatii" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="des-btn" type="button" data-bs-dismiss="modal">Renunță</button>
                    <button class="des-btn des-btn-primary" type="submit" data-des-submit>
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>Înlocuiește din stoc
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ------------------------------------------------------------ Returnare -->
<div class="modal fade des-modal" id="desReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e($formAction('returnare')) ?>">
                <?= csrf_field() ?>
                <?php $keepFilters(); ?>
                <input type="hidden" name="detinator_tip" value="<?= e($modalOwnerType) ?>">
                <input type="hidden" name="alocare_id" data-des-field="alocare">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title">Returnează echipamentul</h2>
                        <p>Articolele returnabile și utilizabile se întorc în stocul disponibil.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <div class="des-summary-line mb-3">
                        <span class="des-item-icon"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i></span>
                        <div>
                            <strong data-des-text="denumire">—</strong>
                            <span>de la <span data-des-text="sofer">—</span></span>
                        </div>
                    </div>

                    <div class="des-callout is-neutral mb-3" data-des-returnable-hint>
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <div>
                            <strong>Articol returnabil</strong>
                            <span>Va crește stocul disponibil dacă alegi „revine în stoc”.</span>
                        </div>
                    </div>

                    <label class="form-label">Rezultatul returnării</label>
                    <div class="d-grid gap-2">
                        <label class="des-summary-line">
                            <input type="radio" name="rezultat" value="stoc" checked>
                            <span><strong>Revine în stoc</strong><span>Articol în stare bună, poate fi realocat.</span></span>
                        </label>
                        <label class="des-summary-line">
                            <input type="radio" name="rezultat" value="deteriorat">
                            <span><strong>Deteriorat</strong><span>Iese din uz, nu intră în stocul disponibil.</span></span>
                        </label>
                        <label class="des-summary-line">
                            <input type="radio" name="rezultat" value="pierdut">
                            <span><strong>Pierdut</strong><span>Se marchează ca pierdut, fără intrare în stoc.</span></span>
                        </label>
                    </div>

                    <div class="mt-3">
                        <label class="form-label" for="desReturnNotes">Observații</label>
                        <input class="form-control" id="desReturnNotes" type="text" name="observatii" maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="des-btn" type="button" data-bs-dismiss="modal">Renunță</button>
                    <button class="des-btn des-btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i>Confirmă returnarea</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- --------------------------------------------------------------- Marcaj -->
<div class="modal fade des-modal" id="desMarkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e($formAction('marcheaza')) ?>">
                <?= csrf_field() ?>
                <?php $keepFilters(); ?>
                <input type="hidden" name="detinator_tip" value="<?= e($modalOwnerType) ?>">
                <input type="hidden" name="alocare_id" data-des-field="alocare">
                <input type="hidden" name="marcaj" data-des-field="marcaj">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title" data-des-text="titlu">Marchează articolul</h2>
                        <p data-des-text="subtitlu">Starea articolului se actualizează în evidența șoferului.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <div class="des-summary-line mb-3">
                        <span class="des-item-icon"><i class="bi bi-flag" aria-hidden="true"></i></span>
                        <div>
                            <strong data-des-text="denumire">—</strong>
                            <span>alocat lui <span data-des-text="sofer">—</span></span>
                        </div>
                    </div>
                    <div class="des-callout is-neutral mb-3" data-des-mark-hint>
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <div><strong>Efect</strong><span>—</span></div>
                    </div>
                    <label class="form-label" for="desMarkNotes">Observații</label>
                    <input class="form-control" id="desMarkNotes" type="text" name="observatii" maxlength="255" placeholder="ex. talpă desprinsă">
                </div>
                <div class="modal-footer">
                    <button class="des-btn" type="button" data-bs-dismiss="modal">Renunță</button>
                    <button class="des-btn des-btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i>Salvează marcajul</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ------------------------------------------------------ Detalii articol -->
<div class="modal fade des-modal" id="desDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title" data-des-text="denumire">Detalii articol</h2>
                    <p>alocat lui <span data-des-text="sofer">—</span></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-secondary fw-semibold">Categorie</dt>
                    <dd class="col-7" data-des-text="categorie">—</dd>
                    <dt class="col-5 text-secondary fw-semibold">Tip logic</dt>
                    <dd class="col-7" data-des-text="logic">—</dd>
                    <dt class="col-5 text-secondary fw-semibold">Cantitate</dt>
                    <dd class="col-7" data-des-text="cantitate">—</dd>
                    <dt class="col-5 text-secondary fw-semibold">Mărime</dt>
                    <dd class="col-7" data-des-text="marime">—</dd>
                    <dt class="col-5 text-secondary fw-semibold">Identificare</dt>
                    <dd class="col-7" data-des-text="identificator">—</dd>
                    <dt class="col-5 text-secondary fw-semibold">Data predării</dt>
                    <dd class="col-7" data-des-text="predare">—</dd>
                    <dt class="col-5 text-secondary fw-semibold">Termen înlocuire</dt>
                    <dd class="col-7" data-des-text="termen">—</dd>
                    <dt class="col-5 text-secondary fw-semibold">Cost / buc.</dt>
                    <dd class="col-7"><span data-des-text="cost">—</span> lei</dd>
                    <dt class="col-5 text-secondary fw-semibold">Stare</dt>
                    <dd class="col-7" data-des-text="stare">—</dd>
                    <dt class="col-5 text-secondary fw-semibold">Status</dt>
                    <dd class="col-7" data-des-text="status">—</dd>
                    <dt class="col-5 text-secondary fw-semibold">Returnabil</dt>
                    <dd class="col-7" data-des-text="returnabil">—</dd>
                    <dt class="col-5 text-secondary fw-semibold">Observații</dt>
                    <dd class="col-7 mb-0" data-des-text="observatii">—</dd>
                </dl>
            </div>
            <div class="modal-footer">
                <button class="des-btn" type="button" data-bs-dismiss="modal">Închide</button>
            </div>
        </div>
    </div>
</div>
