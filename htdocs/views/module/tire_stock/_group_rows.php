<?php
/**
 * Randurile de nivel 1 din Stoc anvelope: un rand per grup (produs nemontat sau vehicul cu
 * anvelope montate). Anvelopele din grup se incarca la extindere (_group_children.php prin
 * tire_stock_children). Primul rand (ascuns) duce datele rezultatelor pentru JS: id-urile tuturor
 * anvelopelor care corespund filtrelor si numaratorile tab-urilor.
 *
 * Intrari: $inventoryGroups, $inventoryCounts, $inventoryMatchIds, $totalTiresMatching, $formatKm.
 */
$jsonAttr = static fn ($value): string => e((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$wearText = static function (?float $min, ?float $max): string {
    if ($min === null || $max === null) {
        return 'Uzura necunoscuta';
    }
    $minText = number_format($min, 0, ',', '.');
    $maxText = number_format($max, 0, ',', '.');
    return $minText === $maxText ? $minText . '% uzura' : $minText . '–' . $maxText . '% uzura';
};
$vehicleIcon = static fn (string $type): string => match ($type) {
    'semiremorca' => 'bi-truck-flatbed',
    'autovehicul' => 'bi-car-front',
    default => 'bi-truck',
};
?>
<tr class="d-none" data-tire-results-meta data-match-ids="<?= $jsonAttr(array_map('intval', $inventoryMatchIds)) ?>" data-counts="<?= $jsonAttr($inventoryCounts) ?>" data-total-tires="<?= e((string) $totalTiresMatching) ?>"></tr>
<?php if ($inventoryGroups === []): ?>
    <tr class="tire-empty-row"><td colspan="11" class="text-center text-muted py-5"><i class="bi bi-search d-block fs-4 mb-2"></i>Nu exista anvelope pentru filtrele selectate.</td></tr>
<?php endif; ?>
<?php foreach ($inventoryGroups as $group): ?>
    <?php
    $groupKey = (string) $group['key'];
    $isProduct = $group['kind'] === 'product';
    $count = (int) $group['count'];
    $totalUnfiltered = (int) ($group['total_unfiltered'] ?? $count);
    $conditionMeta = is_array($group['condition_meta'] ?? null) ? $group['condition_meta'] : ['label' => '-', 'dot_class' => 'tire-dot'];
    $groupTitle = $isProduct ? trim($group['brand'] . ' ' . $group['model']) : (string) $group['plate'];
    $warnings = (int) ($group['warnings'] ?? 0);
    ?>
    <tr class="tire-group-row <?= $isProduct ? 'tire-group-product' : 'tire-group-vehicle' ?>" data-tire-group-key="<?= e($groupKey) ?>" data-auto-expand="<?= !empty($group['auto_expand']) ? '1' : '0' ?>">
        <td class="tire-cell-expander">
            <button type="button" class="tire-expander" data-tire-toggle aria-expanded="false" aria-label="Extinde grupul <?= e($groupTitle) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        </td>
        <td class="tire-cell-check">
            <input type="checkbox" class="form-check-input tire-group-check" data-tire-group-select data-members="<?= $jsonAttr($group['member_meta']) ?>" aria-label="Selecteaza cele <?= e((string) $count) ?> anvelope din grupul <?= e($groupTitle) ?>" title="Selecteaza cele <?= e((string) $count) ?> anvelope care corespund filtrelor">
        </td>
        <?php if ($isProduct): ?>
            <?php $photoUrl = !empty($group['photo_path']) ? url('uploads/anvelope_profil/' . rawurlencode(basename((string) $group['photo_path']))) : ''; ?>
            <td>
                <div class="d-flex align-items-center gap-3">
                    <div class="tire-thumb tire-group-thumb"><?php if ($photoUrl !== ''): ?><img src="<?= e($photoUrl) ?>" alt="Poza profil <?= e($groupTitle) ?>"><?php endif; ?></div>
                    <div class="min-w-0">
                        <div class="tire-group-title"><?= e($groupTitle !== '' ? $groupTitle : 'Anvelopa fara marca') ?></div>
                        <div class="small text-muted"><?= e($group['size'] !== '' ? $group['size'] : '-') ?></div>
                        <span class="badge text-bg-light border"><?= e((string) $group['season_label']) ?></span>
                    </div>
                </div>
            </td>
            <td colspan="2">
                <div class="tire-fact-label">Stare generala</div>
                <div class="tire-fact-value" title="Starea celei mai uzate anvelope din grup; fiecare anvelopa are starea ei pe rand"><span class="<?= e((string) ($conditionMeta['dot_class'] ?? 'tire-dot')) ?>"></span><?= e((string) $conditionMeta['label']) ?></div>
                <div class="small text-muted"><?= e($wearText($group['wear_min'], $group['wear_max'])) ?><?= $count > 1 && $group['wear_min'] !== null && $group['wear_min'] !== $group['wear_max'] ? ' (min–max)' : '' ?></div>
            </td>
            <td colspan="2">
                <?php $locations = $group['locations']; $primary = $locations[0] ?? null; ?>
                <div class="tire-fact-label">Locatie principala</div>
                <div class="tire-fact-value"><?= e($primary !== null ? (string) $primary['label'] : '-') ?></div>
                <button type="button" class="btn btn-link p-0 small tire-fact-link" data-tire-toggle-open><?= e((string) count($locations)) ?> <?= count($locations) === 1 ? 'locatie' : 'locatii' ?></button>
            </td>
            <td>
                <div class="tire-fact-label">Tip anvelopa</div>
                <div class="tire-fact-value"><?= e((string) $group['tire_type_label']) ?></div>
                <div class="small text-muted text-truncate tire-fact-sub" title="<?= e((string) $group['compatibility_label']) ?>"><?= e((string) $group['compatibility_label']) ?></div>
            </td>
            <td colspan="2">
                <div class="tire-group-badges">
                    <span class="tire-count-badge" title="<?= $count < $totalUnfiltered ? 'Anvelope care corespund filtrelor, din totalul produsului nemontat' : 'Anvelope nemontate din acest produs' ?>"><?= $count < $totalUnfiltered ? e((string) $count) . ' din ' . e((string) $totalUnfiltered) : e((string) $count) ?> anvelope</span>
                    <span class="tire-count-badge is-green"><?= e((string) $group['in_stock']) ?> in stoc</span>
                    <?php if ((int) $group['spare'] > 0): ?><span class="tire-count-badge is-blue"><?= e((string) $group['spare']) ?> rezerva</span><?php endif; ?>
                    <span class="tire-count-badge" title="Anvelope din acelasi produs montate pe vehicule (afisate la vehiculele lor)"><?= e((string) $group['mounted_elsewhere']) ?> montate</span>
                    <?php if ($warnings > 0): ?><span class="tire-count-badge is-red" title="Deteriorate, lipsa, casate sau cu uzura mare"><i class="bi bi-exclamation-triangle me-1"></i><?= e((string) $warnings) ?> de inlocuit</span><?php endif; ?>
                </div>
            </td>
        <?php else: ?>
            <td>
                <div class="d-flex align-items-center gap-3">
                    <div class="tire-vehicle-thumb"><i class="bi <?= e($vehicleIcon((string) $group['vehicle_type'])) ?>" aria-hidden="true"></i></div>
                    <div class="min-w-0">
                        <div class="tire-group-title tire-group-plate"><?= e($groupTitle) ?></div>
                        <div class="small text-muted"><?= e(trim($group['vehicle_type_label'] . ($group['vehicle_name'] !== '' ? ' · ' . $group['vehicle_name'] : ''))) ?></div>
                    </div>
                </div>
            </td>
            <td colspan="2">
                <div class="tire-fact-label">Tip vehicul</div>
                <div class="tire-fact-value"><?= e((string) $group['vehicle_type_label']) ?></div>
                <div class="small text-muted"><?= e(trim(($group['vehicle_name'] !== '' ? $group['vehicle_name'] : '') . ($group['layout'] !== '' ? ' · ' . $group['layout'] : ''), ' ·')) ?: '-' ?></div>
            </td>
            <td colspan="2">
                <div class="tire-fact-label">Stare generala</div>
                <div class="tire-fact-value" title="Starea celei mai uzate anvelope de pe vehicul"><span class="<?= e((string) ($conditionMeta['dot_class'] ?? 'tire-dot')) ?>"></span><?= e((string) $conditionMeta['label']) ?></div>
                <div class="small text-muted"><?= $group['wear_avg'] !== null ? e(number_format((float) $group['wear_avg'], 0, ',', '.')) . '% uzura (medie)' : 'Uzura necunoscuta' ?></div>
            </td>
            <td>
                <div class="tire-fact-label">Km ramasi (medie)</div>
                <div class="tire-fact-value"><?= e($formatKm($group['remaining_avg'])) ?></div>
                <div class="small text-muted"><?= e((string) count($group['axles'])) ?> <?= count($group['axles']) === 1 ? 'axa' : 'axe' ?> cu anvelope</div>
            </td>
            <td colspan="2">
                <div class="tire-group-badges">
                    <span class="tire-count-badge" title="<?= $count < $totalUnfiltered ? 'Anvelope care corespund filtrelor, din cele montate pe vehicul' : 'Anvelope montate pe vehicul' ?>"><?= $count < $totalUnfiltered ? e((string) $count) . ' din ' . e((string) $totalUnfiltered) : e((string) $count) ?> anvelope</span>
                    <span class="tire-count-badge<?= (int) $group['missing_positions'] > 0 ? ' is-yellow' : '' ?>" title="Pozitii fara anvelopa, fata de formula axelor (<?= e((string) $group['expected_tires']) ?> pozitii)"><?= e((string) $group['missing_positions']) ?> lipsa</span>
                    <span class="tire-count-badge is-green"><?= e((string) $totalUnfiltered) ?> montate</span>
                    <?php if ($warnings > 0): ?><span class="tire-count-badge is-red" title="Anvelope cu uzura mare, deteriorate sau lipsa"><i class="bi bi-exclamation-triangle me-1"></i><?= e((string) $warnings) ?> de inlocuit</span><?php endif; ?>
                </div>
            </td>
        <?php endif; ?>
        <td class="text-end pe-3 text-nowrap">
            <div class="dropdown d-inline-block">
                <button class="btn btn-outline-secondary tire-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actiuni grup <?= e($groupTitle) ?>"><i class="bi bi-three-dots"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button class="dropdown-item" type="button" data-tire-group-select-all="<?= e($groupKey) ?>"><i class="bi bi-check2-all me-2"></i>Selecteaza tot grupul (<?= e((string) $totalUnfiltered) ?>, fara filtre)</button></li>
                    <li><button class="dropdown-item" type="button" data-tire-group-deselect="<?= e($groupKey) ?>"><i class="bi bi-x-square me-2"></i>Deselecteaza grupul</button></li>
                    <?php if (!$isProduct): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e(build_query_url(['page' => 'mentenanta', 'action' => 'axis_config', 'vehicle_id' => (int) $group['vehicle_id']])) ?>"><i class="bi bi-diagram-3 me-2"></i>Configuratie axe</a></li>
                        <li><a class="dropdown-item" href="<?= e(build_query_url(['page' => 'vehicule', 'action' => 'show', 'id' => (int) $group['vehicle_id']])) ?>"><i class="bi bi-truck me-2"></i>Fisa vehiculului</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <i class="bi bi-chevron-down tire-group-caret" aria-hidden="true"></i>
        </td>
    </tr>
<?php endforeach; ?>
