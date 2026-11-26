<?php
/**
 * Conținutul tabului „Mărimi vestimentare” (Detalii șofer), încărcat la cerere
 * (ModuleController::driverSizesAction). Citire: insigne; Editează: selecturi în aceleași poziții.
 *
 * Variabile: $sections (DriverClothingSizeModel::getDriverSections), $driverId, $canEditSizes, $canEquipCatalog.
 */
?>
<form class="dd-sz-box" data-dd-sz-form novalidate>
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= e((string) $driverId) ?>">
    <div class="dd-sz-figure" aria-hidden="true">
        <svg viewBox="0 0 410 478" class="dd-sz-svg" focusable="false">
            <g class="dd-sz-guides">
                <line x1="94" y1="56" x2="324" y2="56"/>
                <line x1="94" y1="141" x2="324" y2="141"/>
                <line x1="94" y1="226" x2="324" y2="226"/>
                <line x1="94" y1="321" x2="324" y2="321"/>
                <line x1="94" y1="444" x2="324" y2="444"/>
            </g>
            <g class="dd-sz-body">
                <circle cx="207" cy="55" r="30"/>
                <path d="M196 85 L196 99 L218 99 L218 85"/>
                <path d="M196 99 L171 104 C153 108 146 120 143 140 L126 232 C123 246 124 252 130 255 C137 258 143 254 145 246 L157 168 L160 222 L254 222 L257 168 L269 246 C271 254 277 258 284 255 C290 252 291 246 288 232 L271 140 C268 120 261 108 243 104 L218 99 Z"/>
                <path d="M160 222 L254 222 L255 232 L159 232 Z"/>
                <path d="M159 232 L255 232 L258 300 L253 430 L211 430 L209 306 L205 306 L203 430 L161 430 L156 300 Z"/>
                <path d="M207 306 L207 430" class="dd-sz-seam"/>
                <path d="M161 430 L203 430 L204 453 L152 453 C151 444 155 436 161 430 Z"/>
                <path d="M211 430 L253 430 C259 436 263 444 262 453 L210 453 Z"/>
            </g>
            <g class="dd-sz-dots">
                <circle cx="324" cy="56" r="5"/>
                <circle cx="324" cy="141" r="5"/>
                <circle cx="324" cy="226" r="5"/>
                <circle cx="324" cy="321" r="5"/>
                <circle cx="324" cy="444" r="5"/>
            </g>
        </svg>
    </div>
    <div class="dd-sz-list">
        <?php if ($sections === []): ?>
            <div class="dd-empty dd-sz-empty">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>Catalogul de echipamente nu are articole cu mărimi destinate șoferilor.<?php if ($canEquipCatalog): ?> Bifează „Necesită mărime” în <a href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'catalog'])) ?>">Catalog echipamente</a>.<?php endif; ?></span>
            </div>
        <?php endif; ?>
        <?php foreach ($sections as $section): ?>
            <section class="dd-sz-group">
                <h4><?= e(mb_strtoupper((string) $section['title'], 'UTF-8')) ?></h4>
                <?php foreach ($section['items'] as $item): ?>
                    <?php $fieldId = 'dd-sz-' . (int) $item['catalog_id']; ?>
                    <div class="dd-sz-row">
                        <label for="<?= e($fieldId) ?>"><?= e((string) $item['denumire']) ?></label>
                        <span class="dd-sz-badge<?= $item['marime'] === '' ? ' is-unset' : '' ?>" data-dd-sz-view><?= e($item['marime'] !== '' ? (string) $item['marime'] : 'Nesetat') ?></span>
                        <?php if ($canEditSizes): ?>
                            <select class="dd-sz-select" id="<?= e($fieldId) ?>" name="marimi[<?= e((string) $item['catalog_id']) ?>]" data-dd-sz-edit data-initial="<?= e((string) $item['marime']) ?>" disabled>
                                <option value="">Nesetat</option>
                                <?php foreach ($item['options'] as $option): ?>
                                    <option value="<?= e((string) $option) ?>"<?= (string) $option === (string) $item['marime'] ? ' selected' : '' ?>><?= e((string) $option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>
</form>
