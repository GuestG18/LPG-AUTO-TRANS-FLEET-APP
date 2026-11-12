<?php
/**
 * Editorul schemei pe pagina Auto (assets/js/auto-scheme-editor.js):
 *  - bara „Editare schemă” peste schema unei configuratii generale: punctele se trag, pozitiile se
 *    salveaza pe configuratie (op positions_save) si le urmeaza toate vehiculele ei;
 *  - sistemele de pe schema se adauga chiar din bara (sisteme standard sau sisteme proprii noi);
 *  - dialogul unui sistem propriu (nume, pictograma, culoare, tipuri de vehicul, loc, componente).
 *
 * Intrare (din auto_structure.php): $page, $isVehicle, $vehicle, $structure, $manageUrl, $addable, $scopeFields.
 */
$customSystems = (array) ($page['custom_systems'] ?? []);
$catalogGroups = (array) ($page['catalog_groups'] ?? []);
ksort($catalogGroups);
$schemeStructure = is_array($page['scheme_structure'] ?? null) ? $page['scheme_structure'] : null;
$partSystems = (array) ($page['part_systems'] ?? []);
$pinnedParts = (array) ($page['pinned_parts'] ?? []);
$catalogCategories = (array) ($page['catalog_categories'] ?? []);
asort($catalogCategories);
$currentType = $isVehicle ? (string) $vehicle['type'] : (string) $structure['type'];
$roleTypes = ['truck' => 'camion', 'tractor' => 'cap_tractor', 'trailer' => 'semiremorca'];
$customClient = [];
foreach ($customSystems as $key => $definition) {
    $customClient[$key] = [
        'label' => $definition['label'],
        'icon' => $definition['icon'],
        'color' => $definition['color'],
        'scope' => $definition['scope'],
        'types' => array_values(array_filter(array_map(static fn (string $role): ?string => $roleTypes[$role] ?? null, $definition['roles']))),
        'components' => $definition['components'],
    ];
}
$swatches = ['#6366f1', '#2563eb', '#0891b2', '#0d9488', '#16a34a', '#ca8a04', '#ea580c', '#dc2626', '#db2777', '#9333ea', '#475569', '#0f172a'];
$editUrl = $isVehicle
    ? ($schemeStructure !== null ? build_query_url(['page' => 'mentenanta', 'action' => 'auto', 'structura' => $schemeStructure['id']]) . '#schema-editare' : '')
    : '';
?>
<div class="as-scheme-editor" id="schema-editare" data-scheme-editor
     data-mode="<?= $isVehicle ? 'vehicle' : 'structure' ?>"
     data-edit-url="<?= e($editUrl) ?>"
     data-edit-label="<?= e($schemeStructure['name'] ?? '') ?>">
    <?php if (!$isVehicle): ?>
        <div class="as-scheme-bar" data-scheme-bar hidden>
            <div class="as-scheme-bar-text">
                <strong><i class="bi bi-arrows-move" aria-hidden="true"></i> Editare schemă</strong>
                <span>Trage punctele unde vrei · săgeți = mutare fină (Shift = mai mult) · dublu-click = locul implicit</span>
            </div>
            <div class="as-scheme-bar-actions">
                <div class="as-scheme-add">
                    <button type="button" class="as-btn is-small" data-scheme-add-toggle aria-expanded="false"><i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă sistem</button>
                    <div class="as-scheme-add-menu" data-scheme-add-menu hidden>
                        <?php if ($addable !== []): ?>
                            <span class="as-scheme-add-title">Sisteme disponibile</span>
                            <?php foreach ($addable as $system): ?>
                                <button type="button" class="as-scheme-add-item" style="--as-accent: <?= e($system['color']) ?>" data-scheme-system-add="<?= e($system['key']) ?>">
                                    <i class="bi <?= e($system['icon']) ?>" aria-hidden="true"></i> <?= e($system['label']) ?><?= !empty($system['custom']) ? ' <em>propriu</em>' : '' ?>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <button type="button" class="as-scheme-add-item is-new" data-scheme-custom-new><i class="bi bi-plus-circle-dotted" aria-hidden="true"></i> Sistem nou…</button>
                    </div>
                </div>
                <button type="button" class="as-btn is-small" data-scheme-part-new><i class="bi bi-nut" aria-hidden="true"></i> Adaugă piesă</button>
                <button type="button" class="as-btn is-small" data-scheme-reset-all title="Toate punctele revin la locul lor implicit"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Toate la implicit</button>
                <button type="button" class="as-btn is-small" data-scheme-cancel>Renunță</button>
                <button type="button" class="as-btn is-small is-primary" data-scheme-save><i class="bi bi-check2" aria-hidden="true"></i> Salvează pozițiile <span class="as-scheme-count" data-scheme-count hidden></span></button>
            </div>
        </div>

        <form method="post" action="<?= e($manageUrl) ?>" data-scheme-form hidden>
            <?= csrf_field() ?>
            <?= $scopeFields() ?>
            <input type="hidden" name="op" value="positions_save">
            <input type="hidden" name="positions" value="">
            <input type="hidden" name="category" value="">
            <input type="hidden" name="part_key" value="">
            <input type="hidden" name="scheme_edit" value="">
        </form>

        <dialog class="as-dialog" data-scheme-part-dialog aria-labelledby="asPartDialogTitle">
            <form method="post" action="<?= e($manageUrl) ?>" class="as-dialog-form" data-scheme-part-form>
                <?= csrf_field() ?>
                <?= $scopeFields() ?>
                <input type="hidden" name="op" value="part_pin">
                <input type="hidden" name="positions" value="">
                <input type="hidden" name="scheme_edit" value="1">
                <header class="as-dialog-head">
                    <span class="as-dialog-preview" data-scheme-part-preview style="--as-accent: #6366f1"><i class="bi bi-nut" aria-hidden="true"></i></span>
                    <div>
                        <h2 id="asPartDialogTitle">Adaugă piesă pe schemă</h2>
                        <p>Piesa primește punctul ei pe schemă, în culoarea sistemului, și intră în configurație.</p>
                    </div>
                    <button type="button" class="as-icon-btn" data-scheme-dialog-close aria-label="Închide"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </header>
                <div class="as-dialog-body">
                    <div class="as-segment" role="radiogroup" aria-label="Sursa piesei">
                        <label><input type="radio" name="part_mode" value="catalog" checked> <span><i class="bi bi-journal-text" aria-hidden="true"></i> Din catalog</span></label>
                        <label><input type="radio" name="part_mode" value="new"> <span><i class="bi bi-plus-circle" aria-hidden="true"></i> Piesă nouă</span></label>
                    </div>

                    <div class="as-dialog-field" data-part-mode="catalog">
                        <span>Piesa</span>
                        <input type="search" class="as-dialog-search" placeholder="Caută piesa în catalog..." data-scheme-part-search aria-label="Caută piesa în catalog">
                        <select name="part_key" size="8" class="as-dialog-list" data-scheme-part-select aria-label="Piesa din catalog">
                            <?php foreach ($catalogGroups as $group => $items): ?>
                                <optgroup label="<?= e($group) ?>">
                                    <?php foreach ($items as $item): ?>
                                        <?php $pinned = isset($pinnedParts[$item['key']]); ?>
                                        <option value="<?= e($item['key']) ?>" data-name="<?= e(mb_strtolower($item['name'] . ' ' . $group, 'UTF-8')) ?>"<?= $pinned ? ' disabled' : '' ?>><?= e($item['name']) ?><?= $pinned ? ' (deja pe schemă)' : '' ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="as-dialog-row" data-part-mode="new" hidden>
                        <label class="as-dialog-field"><span>Numele piesei noi</span><input type="text" name="part_name" maxlength="120" placeholder="ex. Senzor temperatură agregat"></label>
                        <label class="as-dialog-field"><span>Categoria din catalog</span>
                            <select name="part_catalog_category" class="as-dialog-select">
                                <option value="">Alege categoria</option>
                                <?php foreach ($catalogCategories as $id => $name): ?>
                                    <option value="<?= e((string) $id) ?>"><?= e($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <label class="as-dialog-field"><span>Sistemul de pe schemă (culoarea și locul punctului)</span>
                        <select name="part_system" class="as-dialog-select" required data-scheme-part-system>
                            <option value="">Alege sistemul</option>
                            <?php foreach ($partSystems as $key => $system): ?>
                                <option value="<?= e($key) ?>" data-color="<?= e($system['color']) ?>" data-icon="<?= e($system['icon']) ?>"><?= e($system['label']) ?><?= $system['on_scheme'] ? '' : ' (se adaugă pe schemă)' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php if ($pinnedParts !== []): ?>
                        <div class="as-dialog-field">
                            <span>Piese deja pe schemă</span>
                            <ul class="as-pinned">
                                <?php foreach ($pinnedParts as $key => $part): ?>
                                    <?php $system = $partSystems[$part['category']] ?? null; ?>
                                    <li style="--as-accent: <?= e($system['color'] ?? '#6366f1') ?>">
                                        <i class="bi <?= e($system['icon'] ?? 'bi-nut') ?>" aria-hidden="true"></i>
                                        <span><?= e($part['name']) ?> <em><?= e($system['label'] ?? $part['category']) ?></em></span>
                                        <button type="button" class="as-icon-btn" title="Scoate punctul de pe schemă" aria-label="Scoate <?= e($part['name']) ?> de pe schemă" data-scheme-part-unpin="<?= e((string) $key) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
                <footer class="as-dialog-foot">
                    <span class="as-dialog-spacer"></span>
                    <button type="button" class="as-btn" data-scheme-dialog-close>Renunță</button>
                    <button type="submit" class="as-btn is-primary">Pune pe schemă</button>
                </footer>
            </form>
        </dialog>
        <script type="application/json" id="asPartSystems"><?= json_encode((object) ($page['part_default_system'] ?? []), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php endif; ?>

    <dialog class="as-dialog" data-scheme-dialog aria-labelledby="asSchemeDialogTitle">
        <form method="post" action="<?= e($manageUrl) ?>" class="as-dialog-form" data-scheme-custom-form>
            <?= csrf_field() ?>
            <?= $scopeFields() ?>
            <input type="hidden" name="op" value="custom_system_save">
            <input type="hidden" name="custom_key" value="">
            <input type="hidden" name="positions" value="">
            <input type="hidden" name="scheme_edit" value="">
            <header class="as-dialog-head">
                <span class="as-dialog-preview" data-scheme-preview style="--as-accent: #6366f1"><i class="bi bi-gear" aria-hidden="true"></i></span>
                <div>
                    <h2 id="asSchemeDialogTitle" data-scheme-dialog-title>Sistem nou pe schemă</h2>
                    <p>Apare pe schemă ca un punct propriu; componentele lui vin din catalogul Reparații Auto.</p>
                </div>
                <button type="button" class="as-icon-btn" data-scheme-dialog-close aria-label="Închide"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </header>
            <div class="as-dialog-body">
                <label class="as-dialog-field"><span>Nume</span><input type="text" name="custom_name" maxlength="60" required placeholder="ex. Sistem frigorific, Lift hidraulic, Tahograf"></label>

                <fieldset class="as-dialog-field">
                    <legend>Pictogramă</legend>
                    <div class="as-icon-grid">
                        <?php foreach (VehicleComponentRegistry::CUSTOM_ICONS as $index => $icon): ?>
                            <label class="as-icon-choice" title="<?= e(substr($icon, 3)) ?>">
                                <input type="radio" name="custom_icon" value="<?= e($icon) ?>"<?= $index === 0 ? ' checked' : '' ?>>
                                <i class="bi <?= e($icon) ?>" aria-hidden="true"></i><span class="visually-hidden"><?= e(substr($icon, 3)) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="as-dialog-field">
                    <legend>Culoare</legend>
                    <div class="as-swatches">
                        <?php foreach ($swatches as $swatch): ?>
                            <button type="button" class="as-swatch" style="--as-swatch: <?= e($swatch) ?>" data-scheme-swatch="<?= e($swatch) ?>" aria-label="Culoare <?= e($swatch) ?>"></button>
                        <?php endforeach; ?>
                        <label class="as-swatch-custom" title="Altă culoare"><input type="color" name="custom_color" value="#6366f1" aria-label="Altă culoare"></label>
                    </div>
                </fieldset>

                <div class="as-dialog-row">
                    <fieldset class="as-dialog-field">
                        <legend>Se aplică la</legend>
                        <?php foreach (VehicleStructureService::VEHICLE_TYPES as $type => $label): ?>
                            <label class="as-check"><input type="checkbox" name="custom_types[]" value="<?= e($type) ?>"<?= $type === $currentType ? ' checked' : '' ?>> <?= e($label) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <fieldset class="as-dialog-field">
                        <legend>Puncte pe schemă</legend>
                        <?php foreach (VehicleComponentRegistry::CUSTOM_SCOPES as $scope => $label): ?>
                            <label class="as-check"><input type="radio" name="custom_scope" value="<?= e($scope) ?>"<?= $scope === 'vehicle' ? ' checked' : '' ?>> <?= e($label) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                </div>

                <fieldset class="as-dialog-field">
                    <legend>Componente din catalog <small data-scheme-picked></small></legend>
                    <input type="search" class="as-dialog-search" placeholder="Caută în catalog..." data-scheme-component-search aria-label="Caută componentă în catalog">
                    <div class="as-dialog-components" data-scheme-components>
                        <?php foreach ($catalogGroups as $group => $items): ?>
                            <div class="as-dialog-group" data-scheme-group>
                                <strong><?= e($group) ?></strong>
                                <?php foreach ($items as $item): ?>
                                    <label class="as-check" data-scheme-component data-name="<?= e(mb_strtolower($item['name'] . ' ' . $group, 'UTF-8')) ?>">
                                        <input type="checkbox" name="custom_components[]" value="<?= e($item['key']) ?>"> <?= e($item['name']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <span class="as-hint">Componenta rămâne și în sistemul ei standard. Lipsește? Adaug-o din „Catalog componente”.</span>
                </fieldset>
            </div>
            <footer class="as-dialog-foot">
                <button type="button" class="as-btn is-danger" data-scheme-custom-delete data-as-confirm-text="Apasă din nou: sistemul dispare de pe toate schemele" hidden><i class="bi bi-trash3" aria-hidden="true"></i> Șterge sistemul</button>
                <span class="as-dialog-spacer"></span>
                <button type="button" class="as-btn" data-scheme-dialog-close>Renunță</button>
                <button type="submit" class="as-btn is-primary" data-scheme-dialog-submit>Creează și pune pe schemă</button>
            </footer>
        </form>
    </dialog>

    <script type="application/json" id="asSchemeCustom"><?= json_encode((object) $customClient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</div>
