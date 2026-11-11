<?php
declare(strict_types=1);

/**
 * Pagina Auto: schema vehiculului sus, configuratia pe sisteme dedesubt. Ce e aici se vede pe schema.
 * Datele vin din MaintenanceController::getAutoStructureData.
 */
$page = is_array($structurePage ?? null) ? $structurePage : [];
$structures = (array) ($page['structures'] ?? []);
$vehicles = (array) ($page['vehicles'] ?? []);
$structure = is_array($page['structure'] ?? null) ? $page['structure'] : null;
$vehicle = is_array($page['vehicle'] ?? null) ? $page['vehicle'] : null;
$sections = (array) ($page['sections'] ?? []);
$addable = (array) ($page['addable'] ?? []);
$removed = (array) ($page['removed'] ?? []);
$compatible = (array) ($page['compatible'] ?? []);
$partner = is_array($page['partner'] ?? null) ? $page['partner'] : null;
$axleColumns = (array) ($page['axle_columns'] ?? []);
$canManage = !empty($page['can_manage']);
$openSection = (string) ($page['open'] ?? '');
$isVehicle = $vehicle !== null;
$selectedVehicle = $vehicle; // pentru _vehicle_explorer.php

$manageUrl = build_query_url(['page' => 'mentenanta', 'action' => 'auto_structure_manage']);
$pageUrl = static fn (array $params): string => build_query_url(['page' => 'mentenanta', 'action' => 'auto'] + $params);
$scopeFields = static function () use ($isVehicle, $vehicle, $structure): string {
    return $isVehicle
        ? '<input type="hidden" name="vehicle_id" value="' . e((string) $vehicle['id']) . '">'
        : '<input type="hidden" name="structura" value="' . e((string) ($structure['id'] ?? 0)) . '">';
};
$axleText = static function (?array $kinds): string {
    if ($kinds === null) {
        return 'Toate';
    }
    return implode(', ', array_map(static fn (string $kind): string => VehicleAxleTypeService::KINDS[$kind] ?? $kind, $kinds));
};
// Tabelul pe tipuri de axa: doar sistemele legate de axe; o componenta apare o singura data.
$axleMatrix = [];
$axleMatrixSeen = [];
foreach ($sections as $section) {
    if (!$section['axle_scoped']) {
        continue;
    }
    $matrixRows = [];
    foreach ($section['components'] as $row) {
        if (!isset($axleMatrixSeen[$row['key']])) {
            $axleMatrixSeen[$row['key']] = true;
            $matrixRows[] = $row;
        }
    }
    if ($matrixRows !== []) {
        $axleMatrix[] = ['label' => $section['label'], 'rows' => $matrixRows];
    }
}
$originLabels = ['general' => 'General', 'custom' => 'Personalizat', 'added' => 'Doar acest vehicul'];
// O componenta poate aparea in doua sisteme (ex. Compresor aer): se numara o singura data.
$componentOrigins = [];
foreach ($sections as $section) {
    foreach ($section['components'] as $row) {
        $componentOrigins[$row['key']] = $row['origin'];
    }
}
$totalComponents = count($componentOrigins);
$customCount = count(array_filter($componentOrigins, static fn (string $origin): bool => $origin !== 'general'));
$vehicleGroups = [];
foreach ($vehicles as $item) {
    $vehicleGroups[$item['type']][] = $item;
}
$selectedOption = $isVehicle ? 'v:' . $vehicle['id'] : 's:' . (int) ($structure['id'] ?? 0);
$assetVersion = static fn (string $path): string => url($path . '?v=' . (string) @filemtime(BASE_PATH . '/' . $path));
?>
<link rel="stylesheet" href="<?= e($assetVersion('assets/css/auto-structure.css')) ?>">

<div class="as-page" data-as-page>
    <header class="as-toolbar">
        <div class="as-title">
            <h1>Configurație vehicule</h1>
            <p>Ce adaugi aici apare pe schemă.</p>
        </div>
        <label class="as-picker">
            <span>Configurație sau vehicul</span>
            <select data-as-select aria-label="Configurație sau vehicul">
                <optgroup label="Configurații generale">
                    <?php foreach ($structures as $item): ?>
                        <?php $value = 's:' . $item['id']; ?>
                        <option value="<?= e($value) ?>" data-url="<?= e($pageUrl(['structura' => $item['id']])) ?>"<?= $value === $selectedOption ? ' selected' : '' ?>><?= e($item['name']) ?> (<?= e((string) $item['vehicles']) ?> vehicule)</option>
                    <?php endforeach; ?>
                </optgroup>
                <?php foreach (VehicleStructureService::VEHICLE_TYPES as $type => $label): ?>
                    <?php if (!empty($vehicleGroups[$type])): ?>
                        <optgroup label="<?= e($label) ?>">
                            <?php foreach ($vehicleGroups[$type] as $item): ?>
                                <?php $value = 'v:' . $item['id']; ?>
                                <option value="<?= e($value) ?>" data-url="<?= e($pageUrl(['vehicle_id' => $item['id']])) ?>"<?= $value === $selectedOption ? ' selected' : '' ?>><?= e($item['label']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="as-search">
            <span class="visually-hidden">Caută componentă</span>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" placeholder="Caută componentă..." data-as-search>
        </label>
        <div class="as-toolbar-actions">
            <?php if ($canManage): ?>
                <button type="button" class="as-btn is-primary" data-as-toggle="new"><i class="bi bi-plus-lg" aria-hidden="true"></i> Configurație nouă</button>
            <?php endif; ?>
            <a class="as-btn" href="<?= e($pageUrl(['view' => 'catalog'])) ?>"><i class="bi bi-journal-text" aria-hidden="true"></i> Catalog componente</a>
        </div>
    </header>

    <?php if ($canManage): ?>
        <form class="as-card as-form" method="post" action="<?= e($manageUrl) ?>" data-as-panel="new" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="create">
            <h2>Configurație generală nouă</h2>
            <div class="as-fields">
                <label><span>Nume</span><input type="text" name="name" maxlength="120" required placeholder="ex. Semiremorcă 3 axe frigorifică"></label>
                <label><span>Tip vehicul</span>
                    <select name="vehicle_type" required>
                        <?php foreach (VehicleStructureService::VEHICLE_TYPES as $type => $label): ?>
                            <option value="<?= e($type) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>Număr de axe</span><input type="number" name="axle_count" min="1" max="6" value="2" required></label>
                <label><span>Pornește de la</span>
                    <select name="copy_from">
                        <option value="0">Sistemele standard ale tipului</option>
                        <?php foreach ($structures as $item): ?>
                            <option value="<?= e((string) $item['id']) ?>">Copie după: <?= e($item['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="as-form-actions">
                <button type="button" class="as-btn" data-as-toggle="new">Renunță</button>
                <button type="submit" class="as-btn is-primary">Creează</button>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($structure === null): ?>
        <p class="as-card as-empty">Nu există încă vehicule cu axe configurate. Configurează axele în Configurație Axe, apoi revino aici.</p>
    <?php else: ?>
        <section class="as-summary">
            <?php if ($isVehicle): ?>
                <div class="as-summary-main">
                    <strong><?= e($vehicle['label']) ?></strong>
                    <span><?= e(VehicleStructureService::VEHICLE_TYPES[$vehicle['type']] ?? '') ?> · <?= e((string) $vehicle['axles']) ?> axe · <?= e((string) count($sections)) ?> sisteme · <?= e((string) $totalComponents) ?> componente<?= $customCount > 0 ? ' · ' . e((string) $customCount) . ' personalizate' : '' ?></span>
                </div>
                <?php if ($partner !== null): ?>
                    <a class="as-btn" href="<?= e($pageUrl(['vehicle_id' => $partner['id']])) ?>"><i class="bi bi-link-45deg" aria-hidden="true"></i> <?= e($partner['label']) ?> cuplat: <?= e($partner['plate']) ?></a>
                <?php endif; ?>
                <form class="as-follow" method="post" action="<?= e($manageUrl) ?>" data-as-autosubmit>
                    <?= csrf_field() ?>
                    <input type="hidden" name="op" value="assign">
                    <input type="hidden" name="vehicle_id" value="<?= e((string) $vehicle['id']) ?>">
                    <label>
                        <span>Urmează</span>
                        <select name="target"<?= $canManage ? '' : ' disabled' ?>>
                            <?php foreach ($compatible as $item): ?>
                                <option value="<?= e((string) ($item['is_default'] ? 0 : $item['id'])) ?>"<?= $item['id'] === $structure['id'] ? ' selected' : '' ?>><?= e($item['name']) ?><?= $item['is_default'] ? ' (automat)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
                <a class="as-btn" href="<?= e($pageUrl(['structura' => $structure['id']])) ?>">Deschide generala</a>
            <?php else: ?>
                <div class="as-summary-main">
                    <strong><?= e($structure['name']) ?></strong>
                    <span><?= e($structure['type_label']) ?> · <?= e((string) $structure['axles']) ?> axe · <?= e((string) count($sections)) ?> sisteme · <?= e((string) $totalComponents) ?> componente · urmată de <?= e((string) ($structure['vehicles'] ?? 0)) ?> vehicule<?= $structure['is_default'] ? ' · implicită' : '' ?></span>
                </div>
                <?php if ($canManage): ?>
                    <button type="button" class="as-btn" data-as-toggle="rename"><i class="bi bi-pencil" aria-hidden="true"></i> Redenumește</button>
                    <?php if (!$structure['is_default']): ?>
                        <button type="button" class="as-btn" data-as-op="set_default">Setează implicită</button>
                        <button type="button" class="as-btn is-danger" data-as-op="delete" data-as-confirm="Apasă din nou pentru ștergere"><i class="bi bi-trash3" aria-hidden="true"></i> Șterge</button>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($canManage && !$isVehicle): ?>
            <form class="as-card as-form as-inline-form" method="post" action="<?= e($manageUrl) ?>" data-as-panel="rename" hidden>
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="rename">
                <input type="hidden" name="structura" value="<?= e((string) $structure['id']) ?>">
                <label><span>Nume nou</span><input type="text" name="name" maxlength="120" required value="<?= e($structure['name']) ?>"></label>
                <button type="button" class="as-btn" data-as-toggle="rename">Renunță</button>
                <button type="submit" class="as-btn is-primary">Salvează</button>
            </form>
        <?php endif; ?>

        <?php include __DIR__ . '/_vehicle_explorer.php'; ?>

        <?php if ($axleMatrix !== [] && $axleColumns !== []): ?>
            <details class="as-card as-matrix" id="piese-pe-tip-axa" open>
                <summary>
                    <strong>Piese pe tip de axă</strong>
                    <span>Bifează ce piese are fiecare tip de axă. Schema le arată pe axele de acel tip.</span>
                </summary>
                <form method="post" action="<?= e($manageUrl) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="op" value="axle_kinds_save">
                    <?= $scopeFields() ?>
                    <div class="as-matrix-scroll">
                        <table class="as-matrix-table">
                            <thead>
                                <tr>
                                    <th scope="col">Componentă</th>
                                    <?php foreach ($axleColumns as $column): ?>
                                        <th scope="col" class="is-kind">
                                            <?= e($column['label']) ?>
                                            <small><?= $column['axles'] !== [] ? e(implode(', ', array_map(static fn (int $no): string => 'A' . $no, $column['axles']))) : 'fără axă' ?></small>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <?php foreach ($axleMatrix as $group): ?>
                                <tbody data-as-matrix-group>
                                    <tr class="is-group"><th scope="colgroup" colspan="<?= e((string) (count($axleColumns) + 1)) ?>"><?= e($group['label']) ?></th></tr>
                                    <?php foreach ($group['rows'] as $row): ?>
                                        <tr data-as-row data-name="<?= e(mb_strtolower($row['name'], 'UTF-8')) ?>">
                                            <th scope="row">
                                                <?= e($row['name']) ?>
                                                <input type="hidden" name="matrix_keys[]" value="<?= e($row['key']) ?>">
                                            </th>
                                            <?php foreach ($axleColumns as $column): ?>
                                                <td class="is-kind">
                                                    <input type="checkbox" name="matrix[<?= e($row['key']) ?>][]" value="<?= e($column['kind']) ?>"
                                                           aria-label="<?= e($row['name'] . ' pe axa ' . $column['label']) ?>"
                                                           <?= $row['kinds'] === null || in_array($column['kind'], $row['kinds'], true) ? 'checked' : '' ?><?= $canManage ? '' : ' disabled' ?>>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    <?php if ($canManage): ?>
                        <div class="as-form-actions as-matrix-actions">
                            <?php if ($isVehicle): ?><span class="as-hint">Se aplică doar acestui vehicul.</span><?php else: ?><span class="as-hint">Se aplică vehiculelor care urmează această configurație și nu au valori proprii.</span><?php endif; ?>
                            <button type="submit" class="as-btn is-primary">Salvează</button>
                        </div>
                    <?php endif; ?>
                </form>
            </details>
        <?php endif; ?>

        <div class="as-sections" data-as-sections>
            <?php foreach ($sections as $section): ?>
                <?php $isOpen = $openSection === $section['key']; ?>
                <section class="as-card as-section" id="sistem-<?= e($section['key']) ?>" data-as-section style="--as-accent: <?= e($section['color']) ?>">
                    <header class="as-section-head">
                        <span class="as-section-icon"><i class="bi <?= e($section['icon']) ?>" aria-hidden="true"></i></span>
                        <h2><?= e($section['label']) ?></h2>
                        <span class="as-count"><?= e((string) count($section['components'])) ?> componente</span>
                        <?php if ($canManage): ?>
                            <div class="as-section-actions">
                                <?php if ($section['available'] !== []): ?>
                                    <button type="button" class="as-btn is-small" data-as-toggle="add-<?= e($section['key']) ?>" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă</button>
                                <?php endif; ?>
                                <button type="button" class="as-icon-btn" title="Scoate sistemul de pe schemă" aria-label="Scoate sistemul <?= e($section['label']) ?> de pe schemă" data-as-op="system_remove" data-category="<?= e($section['key']) ?>" data-as-confirm="Apasă din nou pentru a scoate sistemul"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                            </div>
                        <?php endif; ?>
                    </header>

                    <?php if ($canManage && $section['available'] !== []): ?>
                        <form class="as-add" method="post" action="<?= e($manageUrl) ?>" data-as-panel="add-<?= e($section['key']) ?>"<?= $isOpen ? '' : ' hidden' ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="op" value="component_add">
                            <input type="hidden" name="category" value="<?= e($section['key']) ?>">
                            <?= $scopeFields() ?>
                            <div class="as-add-head">
                                <strong>Alege din catalog</strong>
                                <label class="as-check"><input type="checkbox" data-as-check-all> Toate</label>
                            </div>
                            <div class="as-add-list">
                                <?php foreach ($section['available'] as $option): ?>
                                    <label class="as-check"><input type="checkbox" name="keys[]" value="<?= e($option['key']) ?>"> <?= e($option['name']) ?></label>
                                <?php endforeach; ?>
                            </div>
                            <div class="as-form-actions">
                                <button type="button" class="as-btn" data-as-toggle="add-<?= e($section['key']) ?>">Renunță</button>
                                <button type="submit" class="as-btn is-primary">Adaugă selectate</button>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($section['components'] === []): ?>
                        <p class="as-section-empty">
                            Apare pe schemă, dar nu are încă nicio componentă.
                            <?php if ($section['available'] === []): ?>Catalogul nu are componente pentru acest sistem: <a href="<?= e($pageUrl(['view' => 'catalog'])) ?>">adaugă-le în catalog</a>.<?php endif; ?>
                        </p>
                    <?php else: ?>
                        <div class="as-table" role="table" aria-label="Componente <?= e($section['label']) ?>">
                            <div class="as-row is-header" role="row">
                                <span role="columnheader">Componentă</span>
                                <span role="columnheader">Axe</span>
                                <span role="columnheader">Interval</span>
                                <span role="columnheader">Atenționare</span>
                                <span role="columnheader">Critic</span>
                                <span role="columnheader">Sursă</span>
                                <span role="columnheader"><span class="visually-hidden">Acțiuni</span></span>
                            </div>
                            <?php foreach ($section['components'] as $row): ?>
                                <?php
                                $editId = $section['key'] . '-' . $row['key'];
                                $money = static fn (string $value, string $unit): string => $value === '' ? '—' : $value . ' ' . $unit;
                                ?>
                                <div class="as-row" role="row" data-as-row data-name="<?= e(mb_strtolower($row['name'], 'UTF-8')) ?>">
                                    <span role="cell" class="as-name"><strong><?= e($row['name']) ?></strong><?php if ($row['code'] !== ''): ?><small><?= e($row['code']) ?></small><?php endif; ?></span>
                                    <span role="cell" data-label="Axe"><?= $section['axle_scoped'] ? e($axleText($row['kinds'])) : '—' ?></span>
                                    <span role="cell" data-label="Interval"><?= e($money($row['interval'], $row['unit'])) ?></span>
                                    <span role="cell" data-label="Atenționare"><?= e($money($row['warning'], $row['unit'])) ?></span>
                                    <span role="cell" data-label="Critic"><?= e($money($row['critical'], $row['unit'])) ?></span>
                                    <span role="cell" data-label="Sursă"><em class="as-origin is-<?= e($row['origin']) ?>"><?= e($isVehicle ? $originLabels[$row['origin']] : 'General') ?></em></span>
                                    <span role="cell" class="as-actions">
                                        <?php if ($canManage): ?>
                                            <button type="button" class="as-icon-btn" title="Editează" aria-label="Editează <?= e($row['name']) ?>" data-as-toggle="edit-<?= e($editId) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                            <?php if ($isVehicle && $row['origin'] === 'custom'): ?>
                                                <button type="button" class="as-icon-btn" title="Revino la configurația generală" aria-label="Revino la configurația generală pentru <?= e($row['name']) ?>" data-as-op="component_reset" data-component="<?= e($row['key']) ?>" data-category="<?= e($section['key']) ?>" data-as-confirm="Apasă din nou: valorile acestui vehicul se șterg"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                            <?php endif; ?>
                                            <button type="button" class="as-icon-btn" title="Elimină" aria-label="Elimină <?= e($row['name']) ?>" data-as-op="component_remove" data-component="<?= e($row['key']) ?>" data-category="<?= e($section['key']) ?>" data-as-confirm="Apasă din nou pentru eliminare"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if ($canManage): ?>
                                    <form class="as-edit" method="post" action="<?= e($manageUrl) ?>" data-as-panel="edit-<?= e($editId) ?>" hidden>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="op" value="component_save">
                                        <input type="hidden" name="category" value="<?= e($section['key']) ?>">
                                        <input type="hidden" name="component_key" value="<?= e($row['key']) ?>">
                                        <?= $scopeFields() ?>
                                        <div class="as-fields">
                                            <label><span>Monitorizare</span>
                                                <select name="monitoring_type" data-as-monitoring>
                                                    <?php foreach (VehicleStructureService::MONITORING_TYPES as $type => $meta): ?>
                                                        <option value="<?= e($type) ?>" data-unit="<?= e($meta['unit']) ?>"<?= $row['monitoring'] === $type ? ' selected' : '' ?>><?= e($type) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <?php foreach (['interval' => 'Interval', 'warning' => 'Atenționare la', 'critical' => 'Critic la', 'lifetime' => 'Durată de viață'] as $field => $label): ?>
                                                <label><span><?= e($label) ?></span>
                                                    <span class="as-unit-input"><input type="text" inputmode="numeric" name="<?= e($field) ?>" maxlength="40" value="<?= e($row[$field]) ?>"><em data-as-unit><?= e($row['unit']) ?></em></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($isVehicle && $row['origin'] === 'general'): ?>
                                            <p class="as-hint">Salvarea se aplică doar acestui vehicul. Restul vehiculelor rămân pe configurația generală.</p>
                                        <?php elseif (!$isVehicle): ?>
                                            <p class="as-hint">Se aplică tuturor vehiculelor care urmează această configurație și nu au valori proprii.</p>
                                        <?php endif; ?>
                                        <div class="as-form-actions">
                                            <button type="button" class="as-btn" data-as-toggle="edit-<?= e($editId) ?>">Renunță</button>
                                            <button type="submit" class="as-btn is-primary">Salvează</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <p class="as-card as-empty" data-as-no-results hidden>Nicio componentă nu se potrivește căutării.</p>
        </div>

        <?php if ($canManage && $addable !== []): ?>
            <section class="as-more">
                <h2>Adaugă un sistem pe schemă</h2>
                <div class="as-more-list">
                    <?php foreach ($addable as $system): ?>
                        <button type="button" class="as-system-add" style="--as-accent: <?= e($system['color']) ?>" data-as-op="system_add" data-category="<?= e($system['key']) ?>"><i class="bi <?= e($system['icon']) ?>" aria-hidden="true"></i> <?= e($system['label']) ?></button>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($isVehicle && $removed !== []): ?>
            <details class="as-removed">
                <summary>Eliminate de pe acest vehicul (<?= e((string) count($removed)) ?>)</summary>
                <?php foreach ($removed as $item): ?>
                    <div class="as-removed-row">
                        <span><?= e($item['label']) ?> <em><?= e($item['kind']) ?></em></span>
                        <?php if ($canManage): ?>
                            <button type="button" class="as-btn is-small" data-as-op="restore" data-tip="<?= e($item['tip']) ?>" data-element="<?= e($item['element']) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restaurează</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </details>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <form method="post" action="<?= e($manageUrl) ?>" data-as-action-form hidden>
                <?= csrf_field() ?>
                <?= $scopeFields() ?>
                <input type="hidden" name="op" value="">
                <input type="hidden" name="category" value="">
                <input type="hidden" name="component_key" value="">
                <input type="hidden" name="tip" value="">
                <input type="hidden" name="element" value="">
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="<?= e($assetVersion('assets/js/auto-structure.js')) ?>"></script>
