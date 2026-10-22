<?php
/**
 * Interiorul unui grup din Stoc anvelope, incarcat la extindere:
 *  - produs nemontat: subgrup pe locatie de depozitare -> anvelopele;
 *  - vehicul: subgrup pe axa -> anvelopele in ordinea pozitiilor fizice.
 * Fiecare anvelopa este randul ei complet (_tire_row.php), cu actiunile si modalele proprii.
 *
 * Intrari: $group (TireModel::summarizeInventoryGroups) si $tireStockFormOptions / $tireStockPickerPrefix.
 */
include __DIR__ . '/_row_context.php';
$jsonAttr = static fn ($value): string => e((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$tireRowParent = (string) $group['key'];
$subGroups = $group['kind'] === 'product' ? $group['locations'] : $group['axles'];
?>
<?php foreach ($subGroups as $subGroup): ?>
    <?php
    $subKey = (string) $subGroup['key'];
    $subCount = count($subGroup['members']);
    $subTitle = $group['kind'] === 'product' ? 'Locatie: ' . $subGroup['label'] : $subGroup['label'] . ($subGroup['type_label'] !== '' ? ' — ' . $subGroup['type_label'] : '');
    ?>
    <tr class="tire-sub-row" data-tire-child-of="<?= e($tireRowParent) ?>" data-tire-sub-key="<?= e($subKey) ?>">
        <td class="tire-cell-expander">
            <button type="button" class="tire-expander tire-expander-sm" data-tire-sub-toggle aria-expanded="true" aria-label="Restrange <?= e($subTitle) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        </td>
        <td class="tire-cell-check">
            <input type="checkbox" class="form-check-input" data-tire-group-select data-members="<?= $jsonAttr($subGroup['member_meta']) ?>" aria-label="Selecteaza anvelopele din <?= e($subTitle) ?>">
        </td>
        <td colspan="7">
            <?php if ($group['kind'] === 'product'): ?>
                <span class="tire-sub-title">Locatie: <strong><?= e((string) $subGroup['label']) ?></strong></span>
            <?php else: ?>
                <?php $axleCondition = $subGroup['condition_meta']; ?>
                <span class="tire-sub-title"><strong><?= e((string) $subGroup['label']) ?></strong><?= $subGroup['type_label'] !== '' ? ' — ' . e((string) $subGroup['type_label']) : '' ?></span>
                <span class="tire-sub-facts">
                    <span><span class="<?= e((string) ($axleCondition['dot_class'] ?? 'tire-dot')) ?>"></span><?= e((string) $axleCondition['label']) ?></span>
                    <span><?= $subGroup['wear_avg'] !== null ? e(number_format((float) $subGroup['wear_avg'], 0, ',', '.')) . '% uzura (medie)' : 'Uzura necunoscuta' ?></span>
                    <span>Km ramasi (medie): <?= e($formatKm($subGroup['remaining_avg'])) ?></span>
                </span>
            <?php endif; ?>
        </td>
        <td colspan="2" class="text-end pe-3">
            <span class="tire-count-badge is-soft"><?= e((string) $subCount) ?> <?= $subCount === 1 ? 'anvelopa' : 'anvelope' ?></span>
        </td>
    </tr>
    <?php foreach ($subGroup['members'] as $tireRow): ?>
        <?php $tireRowSub = $subKey; include __DIR__ . '/_tire_row.php'; ?>
    <?php endforeach; ?>
<?php endforeach; ?>
