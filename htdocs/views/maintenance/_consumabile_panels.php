<?php
declare(strict_types=1);

/** Panourile laterale ale tabului Consumabile (operatiuni, recipient, produs). Inclus din _consumabile.php. */
$vehicleTypeChoices = AutoComponentCatalogService::VEHICLE_TYPES;
?>
<?php if ($canOperate): ?>
<div class="offcanvas offcanvas-end cons-panel" tabindex="-1" id="consOpPanel" aria-labelledby="consOpTitle">
    <div class="offcanvas-header">
        <div><h2 class="offcanvas-title fs-5" id="consOpTitle">Operațiune stoc</h2><small class="text-muted">Validată și înregistrată pe server, atomic.</small></div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
    </div>
    <div class="offcanvas-body">
        <form data-cons-op-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="idempotency_key" value="">
            <input type="hidden" name="referinta_id" value="">
            <div class="cons-op-types" role="radiogroup" aria-label="Tip operațiune">
                <?php foreach (['intrare' => 'bi-box-arrow-in-down', 'transfer' => 'bi-arrow-left-right', 'completare' => 'bi-droplet-half', 'schimb_complet' => 'bi-arrow-repeat', 'retur' => 'bi-box-arrow-in-left', 'pierdere' => 'bi-exclamation-triangle', 'ajustare' => 'bi-sliders'] as $type => $icon): ?>
                    <label><input type="radio" name="op" value="<?= e($type) ?>"><span><i class="bi <?= e($icon) ?>"></i><?= e(ConsumabileStockService::MOVEMENT_TYPES[$type]) ?></span></label>
                <?php endforeach; ?>
                <label class="d-none" data-cons-reverse-type><input type="radio" name="op" value="stornare"><span><i class="bi bi-arrow-counterclockwise"></i>Stornare</span></label>
            </div>

            <div class="cons-op-reference d-none" data-cons-field="reference"><i class="bi bi-arrow-counterclockwise"></i> <span data-cons-ref-label></span>
                <small class="d-block">Se înregistrează mișcarea inversă; originalul rămâne în istoric, marcat „stornată”.</small></div>

            <label class="cons-field" data-cons-field="dispense"><span>Iese din vehiculul / operațiunea</span>
                <select class="form-select" name="dispense_ref" data-cons-dispense></select>
                <small class="text-muted">Returul e permis doar pentru produsele marcate „permite retur”, în limita cantității ieșite.</small>
            </label>
            <label class="cons-field" data-cons-field="source"><span>Recipient sursă</span><select class="form-select" name="recipient_sursa_id" data-cons-select="containers"></select></label>
            <label class="cons-field" data-cons-field="container"><span>Recipient</span><select class="form-select" name="recipient_id" data-cons-select="containers"></select></label>
            <label class="cons-field" data-cons-field="dest"><span>Recipient destinație</span><select class="form-select" name="recipient_dest_id" data-cons-select="containers"></select></label>
            <label class="cons-field" data-cons-field="product"><span>Produs recepționat</span><select class="form-select" name="produs_id" data-cons-select="products"></select></label>
            <label class="cons-field" data-cons-field="vehicle"><span>Vehicul</span><select class="form-select" name="vehicul_id" data-cons-select="vehicles"></select></label>
            <label class="cons-field" data-cons-field="vsystem"><span>Sistem vehicul</span><select class="form-select" name="sistem_id" data-cons-vsystem></select>
                <small class="text-muted">Legarea de sistem actualizează situația vehiculului (completare / ultimul schimb).</small></label>
            <label class="cons-check-line" data-cons-field="toref"><input type="checkbox" name="pana_la_reper" value="1"> Completat până la reperul maxim (punct de referință pentru calculul consumului)</label>
            <div class="cons-field-row">
                <label class="cons-field" data-cons-field="quantity"><span>Cantitate <em data-cons-unit></em></span><input class="form-control" name="cantitate" inputmode="decimal" autocomplete="off" placeholder="ex. 20"></label>
                <label class="cons-field" data-cons-field="counted"><span>Cantitate numărată fizic <em data-cons-unit></em></span><input class="form-control" name="cantitate_numarata" inputmode="decimal" autocomplete="off"></label>
                <label class="cons-field" data-cons-field="km"><span>KM vehicul (opțional)</span><input class="form-control" name="km_vehicul" inputmode="numeric" autocomplete="off"></label>
            </div>
            <label class="cons-field" data-cons-field="drained"><span>Cantitate scoasă (fluid vechi, opțional)</span><input class="form-control" name="cantitate_scoasa" inputmode="decimal" autocomplete="off"></label>
            <label class="cons-field" data-cons-field="system"><span>Sistem mecanic (text, dacă nu e configurat)</span><input class="form-control" name="sistem" maxlength="80" placeholder="ex. Motor, Cutie de viteze, Pompă GPL"></label>
            <div class="cons-field-row" data-cons-field="receipt">
                <label class="cons-field"><span>Furnizor</span><input class="form-control" name="furnizor" maxlength="120"></label>
                <label class="cons-field"><span>Document (factură / aviz)</span><input class="form-control" name="document" maxlength="120"></label>
            </div>
            <div class="cons-field-row" data-cons-field="lot">
                <label class="cons-field"><span>Lot / serie</span><input class="form-control" name="lot" maxlength="60"></label>
                <label class="cons-field"><span>Data expirării</span><input class="form-control" type="date" name="data_expirare"></label>
            </div>
            <label class="cons-field" data-cons-field="when"><span>Data și ora operațiunii</span><input class="form-control" type="datetime-local" name="efectuat_la"></label>
            <label class="cons-field" data-cons-field="reason"><span>Motiv <em data-cons-reason-required>(obligatoriu)</em></span><input class="form-control" name="motiv" maxlength="255"></label>
            <label class="cons-field"><span>Observații</span><textarea class="form-control" name="observatii" rows="2" maxlength="2000"></textarea></label>

            <div class="cons-preview" data-cons-preview aria-live="polite"></div>
            <div class="cons-op-error d-none" data-cons-error role="alert"></div>
            <div class="cons-panel-actions">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Anulează</button>
                <button type="submit" class="btn btn-primary" data-cons-submit><i class="bi bi-check2-circle me-1"></i>Confirmă operațiunea</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="offcanvas offcanvas-end cons-panel" tabindex="-1" id="consContainerPanel" aria-labelledby="consContainerTitle">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title fs-5" id="consContainerTitle">Recipient</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
    </div>
    <div class="offcanvas-body">
        <form data-cons-container-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="">
            <input type="hidden" name="idempotency_key" value="">
            <div class="cons-field-row">
                <label class="cons-field"><span>Identificator unic</span><input class="form-control" name="cod" maxlength="40" placeholder="automat (ex. BUT-001)"></label>
                <label class="cons-field"><span>Tip recipient</span><select class="form-select" name="tip"><?php foreach (ConsumabileStockService::CONTAINER_TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
            </div>
            <label class="cons-field" data-cons-custom-type><span>Denumire tip recipient</span><input class="form-control" name="tip_personalizat" maxlength="60" placeholder="ex. Canistră 5 L"></label>
            <label class="cons-field"><span>Produs / fluid asociat</span><select class="form-select" name="produs_id" data-cons-select="products" data-cons-allow-empty></select>
                <small class="text-muted" data-cons-product-lock>Produsul se poate schimba doar când recipientul e gol.</small></label>
            <div class="cons-field-row">
                <label class="cons-field"><span>Capacitate nominală <em data-cons-unit></em></span><input class="form-control" name="capacitate" inputmode="decimal" required></label>
                <label class="cons-field"><span>Prag minim <em data-cons-unit></em></span><input class="form-control" name="prag_minim" inputmode="decimal"></label>
            </div>
            <label class="cons-field" data-cons-initial><span>Cantitate inițială <em data-cons-unit></em></span><input class="form-control" name="cantitate_initiala" inputmode="decimal" placeholder="0">
                <small class="text-muted">Se înregistrează ca „Intrare în stoc”. După creare, cantitatea se modifică doar prin operațiuni.</small></label>
            <div class="cons-field-row">
                <label class="cons-field"><span>Locație</span><input class="form-control" name="locatie" maxlength="120" placeholder="ex. Depozit central"></label>
                <label class="cons-field"><span>Status</span><select class="form-select" name="status"><?php foreach (ConsumabileStockService::CONTAINER_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="cons-field-row">
                <label class="cons-field"><span>Lot / serie</span><input class="form-control" name="lot" maxlength="60"></label>
                <label class="cons-field"><span>Data expirării</span><input class="form-control" type="date" name="data_expirare"></label>
            </div>
            <label class="cons-field"><span>Observații</span><textarea class="form-control" name="observatii" rows="2" maxlength="2000"></textarea></label>
            <div class="cons-op-error d-none" data-cons-error role="alert"></div>
            <div class="cons-panel-actions">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Anulează</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Salvează</button>
            </div>
        </form>
    </div>
</div>

<div class="offcanvas offcanvas-end cons-panel cons-panel-wide" tabindex="-1" id="consProductPanel" aria-labelledby="consProductTitle">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title fs-5" id="consProductTitle">Produs</h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
    </div>
    <div class="offcanvas-body">
        <form data-cons-product-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="">
            <label class="cons-field"><span>Denumire produs</span><input class="form-control" name="denumire" maxlength="190" required placeholder="ex. Ulei motor 15W-40"></label>
            <div class="cons-field-row">
                <label class="cons-field"><span>Producător / marcă</span><input class="form-control" name="producator" maxlength="120"></label>
                <label class="cons-field"><span>Cod produs</span><input class="form-control" name="cod_produs" maxlength="80"></label>
            </div>
            <div class="cons-field-row">
                <label class="cons-field"><span>Tip articol</span><select class="form-select" name="tip_articol"><?php foreach (ConsumabileStockService::PRODUCT_KINDS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
                <label class="cons-field"><span>Tip fluid</span><select class="form-select" name="tip_fluid"><option value="">—</option><?php foreach (ConsumabileStockService::FLUID_TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
                <label class="cons-field"><span>UM</span><select class="form-select" name="um"><?php foreach (ConsumabileStockService::UNITS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="cons-field-row">
                <label class="cons-field"><span>Vâscozitate (dacă e cazul)</span><input class="form-control" name="vascozitate" maxlength="40" placeholder="ex. 15W-40"></label>
                <label class="cons-field"><span>Specificație</span><input class="form-control" name="specificatie" maxlength="255" placeholder="ex. API CI-4 / ACEA E7"></label>
            </div>
            <label class="cons-field"><span>Aprobări tehnice</span><textarea class="form-control" name="aprobari" rows="2" placeholder="ex. MAN M3275-1, Volvo VDS-3, MB 228.3"></textarea></label>
            <div class="cons-field-row">
                <label class="cons-field"><span>Sistem mecanic asociat</span><input class="form-control" name="sistem_mecanic" maxlength="80" placeholder="ex. Motor"></label>
                <label class="cons-field"><span>Componentă din Catalog componente</span><select class="form-select" name="component_key"><option value="">— fără legătură —</option><?php foreach ((array) ($cons['components'] ?? []) as $key => $component): ?><option value="<?= e((string) $key) ?>"><?= e((string) $component['name'] . ' · ' . $component['category']) ?></option><?php endforeach; ?></select>
                    <small class="text-muted">Lista conține componentele marcate „Consumabil” sau „Fluid tehnic” în catalog.</small></label>
            </div>
            <fieldset class="cons-field"><legend>Compatibil cu vehiculele</legend>
                <?php foreach ($vehicleTypeChoices as $k => $l): ?><label class="form-check-inline"><input type="checkbox" name="tipuri_vehicul[]" value="<?= e($k) ?>"> <?= e($l) ?></label><?php endforeach; ?>
                <small class="text-muted d-block">Nimic bifat = fără restricție pe tip de vehicul.</small>
            </fieldset>
            <label class="cons-field"><span>Note compatibilitate / echipamente</span><textarea class="form-control" name="compatibilitate_note" rows="2"></textarea></label>
            <div class="cons-field-row">
                <label class="cons-field"><span>Interval km</span><input class="form-control" name="interval_km" inputmode="numeric"></label>
                <label class="cons-field"><span>Interval luni</span><input class="form-control" name="interval_luni" inputmode="numeric"></label>
                <label class="cons-field"><span>Interval ore</span><input class="form-control" name="interval_ore" inputmode="numeric"></label>
            </div>
            <fieldset class="cons-field"><legend>Sursă de monitorizare</legend>
                <?php foreach (ConsumabileStockService::MONITORING_SOURCES as $k => $l): ?><label class="form-check-inline"><input type="checkbox" name="sursa_monitorizare[]" value="<?= e($k) ?>"> <?= e($l) ?></label><?php endforeach; ?>
            </fieldset>
            <div class="cons-field-row">
                <label class="cons-field"><span>Prag de alertare <em data-cons-unit></em></span><input class="form-control" name="prag_alerta" inputmode="decimal"></label>
            </div>
            <div class="cons-checks">
                <label><input type="checkbox" name="critic" value="1"> Fluid critic (pierderea cere inspecție)</label>
                <label><input type="checkbox" name="circuit_inchis" value="1"> Circuit închis (ex. servodirecție: completările repetate = anomalie)</label>
                <label><input type="checkbox" name="permite_retur" value="1"> Permite retur în stoc</label>
                <label><input type="hidden" name="activ" value="0"><input type="checkbox" name="activ" value="1" checked> Activ</label>
            </div>
            <fieldset class="cons-field" data-cons-compat><legend>Compatibil (amestec permis) cu</legend>
                <small class="text-muted d-block mb-1">Doar declarații explicite. Două fluide cu aceeași vâscozitate nu sunt considerate automat compatibile.</small>
                <div data-cons-compat-list></div>
            </fieldset>
            <div class="cons-op-error d-none" data-cons-error role="alert"></div>
            <div class="cons-panel-actions">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Anulează</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Salvează produsul</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="offcanvas offcanvas-end cons-panel cons-panel-wide" tabindex="-1" id="consSystemPanel" aria-labelledby="consSystemTitle">
    <div class="offcanvas-header">
        <div><h2 class="offcanvas-title fs-5" id="consSystemTitle">Sistem fluid vehicul</h2><small class="text-muted">Pragurile se configurează conform specificațiilor echipamentului.</small></div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
    </div>
    <div class="offcanvas-body">
        <form data-cons-system-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="">
            <div class="cons-field-row">
                <label class="cons-field"><span>Vehicul</span><select class="form-select" name="vehicle_id" data-cons-select="vehicles"></select></label>
                <label class="cons-field"><span>Sistem mecanic</span><select class="form-select" name="sistem"><?php foreach (VehicleFluidService::SYSTEM_PRESETS as $k => $preset): ?><option value="<?= e($k) ?>"><?= e($preset[0]) ?></option><?php endforeach; ?></select></label>
            </div>
            <small class="cons-hint" data-cons-preset-hint></small>
            <div class="cons-field-row">
                <label class="cons-field"><span>Denumire (opțional)</span><input class="form-control" name="denumire" maxlength="80"></label>
                <label class="cons-field"><span>Produs configurat</span><select class="form-select" name="produs_id" data-cons-select="products" data-cons-allow-empty></select></label>
            </div>
            <div class="cons-field-row">
                <label class="cons-field"><span>Tip fluid</span><select class="form-select" name="tip_fluid"><option value="">—</option><?php foreach (ConsumabileStockService::FLUID_TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
                <label class="cons-field"><span>Capacitate nominală sistem</span><input class="form-control" name="capacitate_nominala" inputmode="decimal" placeholder="ex. 38"></label>
                <label class="cons-field"><span>UM</span><select class="form-select" name="um"><?php foreach (ConsumabileStockService::UNITS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
            </div>
            <fieldset class="cons-field"><legend>Interval de schimb (gol = nemonitorizat)</legend>
                <div class="cons-field-row">
                    <label class="cons-field"><span>km</span><input class="form-control" name="interval_km" inputmode="numeric" placeholder="ex. 30000"></label>
                    <label class="cons-field"><span>luni</span><input class="form-control" name="interval_luni" inputmode="numeric" placeholder="ex. 12"></label>
                    <label class="cons-field"><span>ore</span><input class="form-control" name="interval_ore" inputmode="numeric"></label>
                    <label class="cons-field"><span>avertizare la %</span><input class="form-control" name="avertizare_procent" inputmode="numeric" value="90"></label>
                </div>
            </fieldset>
            <div class="cons-field-row">
                <label class="cons-field"><span>Sursă km</span><select class="form-select" name="sursa_km"><?php foreach (VehicleFluidService::KM_SOURCES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
                <label class="cons-field"><span>Sursă ore</span><select class="form-select" name="sursa_ore"><?php foreach (VehicleFluidService::HOUR_SOURCES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
            </div>
            <small class="cons-hint">Orele motorului nu sunt orele pompei GPL: pompa / PTO folosește orele de aspirare din curse.</small>
            <div class="cons-field-row">
                <label class="cons-field"><span>Verificare nivel la fiecare (zile)</span><input class="form-control" name="verificare_zile" inputmode="numeric" placeholder="ex. 14"></label>
                <label class="cons-field"><span>Prag consum anormal (UM / 1.000 km)</span><input class="form-control" name="prag_consum" inputmode="decimal"></label>
            </div>
            <div class="cons-checks">
                <label><input type="checkbox" name="critic" value="1"> Fluid critic (pierderea cere inspecție)</label>
                <label><input type="checkbox" name="circuit_inchis" value="1"> Circuit închis (completările repetate = anomalie)</label>
                <label data-cons-adblue-only><input type="checkbox" name="alimentare_la_plin" value="1"> AdBlue: alimentările se fac la plin (permite estimarea restului și a autonomiei)</label>
            </div>
            <fieldset class="cons-field"><legend>Ultimul schimb complet (referință, dacă nu a fost înregistrat în aplicație)</legend>
                <div class="cons-field-row">
                    <label class="cons-field"><span>Data</span><input class="form-control" type="datetime-local" name="referinta_data"></label>
                    <label class="cons-field"><span>KM CAN la schimb</span><input class="form-control" name="referinta_km_can" inputmode="decimal" placeholder="din SAS dacă e gol"></label>
                    <label class="cons-field"><span>KM GPS la schimb</span><input class="form-control" name="referinta_km_gps" inputmode="decimal" placeholder="din SAS dacă e gol"></label>
                </div>
                <small class="text-muted">Un schimb complet înregistrat ulterior (din stoc sau manual) devine automat noua referință.</small>
            </fieldset>
            <label class="cons-field" data-cons-copy><span>Aplică aceeași configurație și pe</span>
                <select class="form-select" name="copy_vehicle_ids[]" multiple size="1" data-cons-multi-panel></select>
                <small class="text-muted">Referința (data / km ultimului schimb) nu se copiază. Vehiculele care au deja sistemul sunt sărite.</small></label>
            <div class="cons-op-error d-none" data-cons-error role="alert"></div>
            <div class="cons-panel-actions">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Anulează</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Salvează sistemul</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canOperate): ?>
<div class="offcanvas offcanvas-end cons-panel" tabindex="-1" id="consEventPanel" aria-labelledby="consEventTitle">
    <div class="offcanvas-header">
        <div><h2 class="offcanvas-title fs-5" id="consEventTitle">Verificare nivel</h2><small class="text-muted" data-cons-event-system></small></div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
    </div>
    <div class="offcanvas-body">
        <form data-cons-event-form novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="idempotency_key" value="">
            <input type="hidden" name="sistem_id" value="">
            <div class="cons-op-types" role="radiogroup" aria-label="Tip înregistrare">
                <label><input type="radio" name="tip" value="verificare" checked><span><i class="bi bi-clipboard-check"></i>Verificare nivel</span></label>
                <label><input type="radio" name="tip" value="completare"><span><i class="bi bi-droplet-half"></i>Completare fără stoc</span></label>
                <label><input type="radio" name="tip" value="schimb_complet"><span><i class="bi bi-arrow-repeat"></i>Schimb fără stoc</span></label>
            </div>
            <fieldset class="cons-field" data-cons-ev="level"><legend>Nivel constatat (măsurat)</legend>
                <div class="cons-level-choices">
                    <?php foreach (VehicleFluidService::LEVEL_STATUSES as $k => $l): ?><label><input type="radio" name="nivel_status" value="<?= e($k) ?>"><span class="cons-level is-measured is-<?= e($k) ?>"><?= e($l) ?></span></label><?php endforeach; ?>
                </div>
                <div class="cons-field-row">
                    <label class="cons-field"><span>Valoare citită (opțional)</span><input class="form-control" name="nivel_valoare" inputmode="decimal"></label>
                    <label class="cons-field"><span>Unitate</span><select class="form-select" name="nivel_unitate"><option value="">—</option><option>%</option><option>L</option><option>mm</option><option>cm</option></select></label>
                </div>
            </fieldset>
            <div data-cons-ev="qty">
                <label class="cons-field"><span>Cantitate adăugată <em data-cons-event-unit></em></span><input class="form-control" name="cantitate" inputmode="decimal"></label>
                <small class="text-muted d-block">Fluid care nu iese din stocul aplicației (ex. cumpărat pe drum). Pentru stoc folosește „Completare vehicul”.</small>
                <label class="cons-check-line"><input type="checkbox" name="pana_la_reper" value="1"> Completat până la reperul maxim</label>
            </div>
            <div class="cons-field-row">
                <label class="cons-field"><span>Data și ora</span><input class="form-control" type="datetime-local" name="efectuat_la"></label>
                <label class="cons-field"><span>KM bord (opțional)</span><input class="form-control" name="km_bord" inputmode="numeric"></label>
            </div>
            <small class="text-muted">KM CAN și KM GPS se preiau automat din SAS pentru momentul înregistrării (dacă există citiri).</small>
            <label class="cons-field"><span>Observații</span><textarea class="form-control" name="observatii" rows="2" maxlength="500"></textarea></label>
            <div class="cons-op-error d-none" data-cons-error role="alert"></div>
            <div class="cons-panel-actions">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Anulează</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Salvează</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="offcanvas offcanvas-end cons-panel cons-panel-wide" tabindex="-1" id="consHistoryPanel" aria-labelledby="consHistoryTitle">
    <div class="offcanvas-header">
        <div><h2 class="offcanvas-title fs-5" id="consHistoryTitle">Istoric sistem</h2><small class="text-muted" data-cons-history-sub></small></div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
    </div>
    <div class="offcanvas-body"><div data-cons-history-body>Se încarcă…</div></div>
</div>
