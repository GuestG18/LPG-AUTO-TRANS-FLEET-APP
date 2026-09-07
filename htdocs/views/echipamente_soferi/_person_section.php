<?php
/**
 * O secțiune de echipament din panoul unui deținător.
 *
 * Ocupă întreaga lățime a panoului și se poate plia individual, dar rămâne
 * mereu în aceeași coloană verticală: colapsarea nu mută secțiunile una lângă
 * alta. Setul de coloane vine din `layout`, ca fiecare fel de activ să fie
 * citit cu informația care contează pentru el.
 *
 * Așteaptă: $section, $sectionId, $driver, $canManage, $itemIcon, $money, $dash.
 */

$layout = (string) ($section['layout'] ?? 'fizic');
$items = is_array($section['items'] ?? null) ? $section['items'] : [];

$columns = match ($layout) {
    'comunicatii' => ['Activ', 'Tip', 'Identificare', 'Predare', 'Cost', 'Status'],
    'it_birou' => ['Echipament', 'Categorie', 'Serie / Nr. inventar', 'Predare', 'Cost', 'Status'],
    'acces' => ['Activ', 'Categorie', 'Identificare', 'Predare', 'Cost', 'Status'],
    default => ['Echipament', 'Categorie', 'Cant.', 'Mărime', 'Predare', 'Cost / buc.', 'Valoare', 'Stare / Termen'],
};
?>
<section class="des-subcard des-section">
    <button class="des-subcard-head des-section-head" type="button"
            data-des-section="<?= e($sectionId) ?>" aria-expanded="true" aria-controls="<?= e($sectionId) ?>">
        <i class="bi bi-chevron-down des-section-chevron" aria-hidden="true"></i>
        <i class="bi <?= e((string) $section['icon']) ?>" aria-hidden="true"></i>
        <h3><?= e((string) $section['titlu']) ?> (<?= e((string) $section['total']) ?>)</h3>
    </button>

    <div class="des-section-body" id="<?= e($sectionId) ?>">
        <div class="des-table-wrap">
            <table class="des-subtable">
                <thead>
                    <tr>
                        <?php foreach ($columns as $column): ?>
                            <th class="<?= in_array($column, ['Cant.'], true) ? 'des-col-num' : '' ?>"><?= e($column) ?></th>
                        <?php endforeach; ?>
                        <th class="des-col-actions">Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($items === []): ?>
                    <tr><td colspan="<?= e((string) (count($columns) + 1)) ?>" class="des-empty">Nicio alocare în această secțiune.</td></tr>
                <?php endif; ?>

                <?php foreach ($items as $item): ?>
                    <?php
                    $tone = (string) $item['stare_termen']['tone'];
                    $rowClass = $tone === 'danger' ? 'is-critical' : ($tone === 'warning' ? 'is-flagged' : '');
                    $cost = $item['cost_lunar'] !== null && (float) $item['cost_lunar'] > 0
                        ? $money($item['cost_lunar']) . ' / lună'
                        : $money($item['valoare_totala']);
                    ?>
                    <tr class="<?= e($rowClass) ?>">
                        <?php if ($layout === 'comunicatii' || $layout === 'acces'): ?>
                            <td>
                                <span class="des-item-icon <?= $layout === 'comunicatii' ? 'is-blue' : '' ?>">
                                    <i class="bi <?= e($itemIcon($item)) ?>" aria-hidden="true"></i>
                                </span>
                            </td>
                            <td class="des-item-name"><?= e((string) $item['denumire']) ?></td>
                            <?php if ($layout === 'acces'): ?>
                                <td><?= e((string) $item['categorie']) ?></td>
                            <?php endif; ?>
                            <td>
                                <?= e($dash($item['identificator'])) ?>
                                <?php if (trim((string) ($item['operator'] ?? '')) !== ''): ?>
                                    <span class="des-note"><?= e((string) $item['operator']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(format_date_ro((string) $item['data_predarii'])) ?></td>
                            <td class="des-col-money"><?= e($cost) ?></td>
                            <td>
                                <span class="des-status is-<?= e($tone) ?>"><?= e((string) $item['stare_termen']['label']) ?></span>
                                <?php if ((string) $item['stare_termen']['note'] !== ''): ?>
                                    <span class="des-note"><?= e((string) $item['stare_termen']['note']) ?></span>
                                <?php endif; ?>
                            </td>

                        <?php elseif ($layout === 'it_birou'): ?>
                            <td class="des-item-name">
                                <span class="des-item-icon"><i class="bi <?= e($itemIcon($item)) ?>" aria-hidden="true"></i></span><?= e((string) $item['denumire']) ?>
                            </td>
                            <td><?= e((string) $item['categorie']) ?></td>
                            <td><?= e($dash($item['identificator'])) ?></td>
                            <td><?= e(format_date_ro((string) $item['data_predarii'])) ?></td>
                            <td class="des-col-money"><?= e($cost) ?></td>
                            <td>
                                <span class="des-status is-<?= e($tone) ?>"><?= e((string) $item['stare_termen']['label']) ?></span>
                                <?php if ((string) $item['stare_termen']['note'] !== ''): ?>
                                    <span class="des-note"><?= e((string) $item['stare_termen']['note']) ?></span>
                                <?php endif; ?>
                            </td>

                        <?php else: ?>
                            <td class="des-item-name"><?= e((string) $item['denumire']) ?></td>
                            <td><?= e((string) $item['categorie']) ?></td>
                            <td class="des-col-num"><?= e((string) $item['cantitate']) ?></td>
                            <td><?= e($dash($item['marime'])) ?></td>
                            <td><?= e(format_date_ro((string) $item['data_predarii'])) ?></td>
                            <td class="des-col-money"><?= e($money($item['cost_unitar'])) ?></td>
                            <td class="des-col-money"><?= e($money($item['valoare_totala'])) ?></td>
                            <td>
                                <span class="des-status is-<?= e($tone) ?>"><?= e((string) $item['stare_termen']['label']) ?></span>
                                <?php if ((string) $item['stare_termen']['note'] !== ''): ?>
                                    <span class="des-note"><?= e((string) $item['stare_termen']['note']) ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>

                        <td class="des-col-actions">
                            <?php include __DIR__ . '/_item_menu.php'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
