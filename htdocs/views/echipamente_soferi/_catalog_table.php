<?php
/**
 * Tabelul complet al catalogului (vederea „Tabel complet”): toate coloanele de
 * logică, rândurile extensibile cu stoc/alocări și meniul de acțiuni.
 * Se include din _catalog_overview.php cu variabilele din catalog.php.
 */
?>
    <div class="des-card">
        <div class="des-table-wrap">
            <table class="des-table">
                <thead>
                    <tr>
                        <th style="width: 46px;"><span class="visually-hidden">Extinde</span></th>
                        <th>Denumire</th>
                        <th>Categorie</th>
                        <th>Grupă</th>
                        <th>Destinație</th>
                        <th>Tip logic</th>
                        <th>Returnabil</th>
                        <th>Urmărește stare</th>
                        <th>Înlocuire periodică</th>
                        <th>Durată standard</th>
                        <th>Expirare</th>
                        <th>Mărime</th>
                        <th>Serializat</th>
                        <th>Cost / buc.</th>
                        <th class="des-col-num">Stoc</th>
                        <th>Status</th>
                        <th class="des-col-actions">Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($catalogItems === []): ?>
                    <tr>
                        <td colspan="15" class="des-empty">
                            <?= $search !== ''
                                ? 'Niciun articol din catalog nu corespunde căutării „' . e($search) . '”.'
                                : 'Catalogul este gol. Adaugă primul articol.' ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($catalogItems as $item): ?>
                    <?php
                    $panelId = 'des-catalog-' . (int) $item['id'];
                    $breakdown = $catalogBreakdown[(int) $item['id']] ?? ['stoc' => [], 'unitati' => [], 'alocari' => [], 'total_stoc' => 0, 'total_alocat' => 0];
                    $hasDetails = $breakdown['stoc'] !== [] || $breakdown['unitati'] !== [] || $breakdown['alocari'] !== [];
                    ?>
                    <tr class="des-driver-row <?= $hasDetails ? '' : 'is-plain' ?>"
                        <?= $hasDetails ? 'data-des-row="' . e($panelId) . '" tabindex="0" role="button" aria-expanded="false" aria-controls="' . e($panelId) . '"' : '' ?>>
                        <td>
                            <?php if ($hasDetails): ?>
                                <span class="des-toggle" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                            <?php endif; ?>
                        </td>
                        <td class="des-item-name">
                            <?php $itemPhoto = $photoUrl($item['imagine'] ?? null); ?>
                            <?php if ($itemPhoto !== null): ?><img class="des-photo-thumb" src="<?= e($itemPhoto) ?>" alt="" loading="lazy"><?php endif; ?>
                            <?= e((string) $item['denumire']) ?>
                        </td>
                        <td><?= e((string) $item['categorie']) ?></td>
                        <td><?= e(DriverEquipmentModel::GROUPS[(string) $item['grupa']] ?? 'Echipament fizic') ?></td>
                        <td>
                            <?php $destination = (string) ($item['destinatie'] ?? 'ambele'); ?>
                            <span class="des-badge <?= $destination === 'ambele' ? 'is-blue' : 'is-muted' ?>">
                                <?= e(DriverEquipmentModel::DESTINATIONS[$destination] ?? 'Ambele') ?>
                            </span>
                        </td>
                        <td><span class="des-logic"><?= e(DriverEquipmentModel::LOGIC_TYPES[(string) $item['tip_logic']] ?? (string) $item['tip_logic']) ?></span></td>
                        <td><?= $yes($item['returnabil']) ?></td>
                        <td><?= $yes($item['urmareste_stare']) ?></td>
                        <td><?= $yes($item['inlocuire_periodica']) ?></td>
                        <td><?= (int) ($item['durata_standard_luni'] ?? 0) > 0 ? e((string) $item['durata_standard_luni']) . ' luni' : '—' ?></td>
                        <td><?= $yes($item['urmareste_expirare']) ?></td>
                        <td><?= $yes($item['necesita_marime']) ?></td>
                        <td><?= $yes($item['serializat']) ?></td>
                        <td class="des-col-money"><?= e($money($item['cost_implicit'])) ?></td>
                        <td class="des-col-num"><span class="des-pill"><?= e((string) $item['stoc_total']) ?></span></td>
                        <td>
                            <span class="des-badge <?= (int) $item['activ'] === 1 ? 'is-success' : 'is-muted' ?>">
                                <?= (int) $item['activ'] === 1 ? 'Activ' : 'Inactiv' ?>
                            </span>
                        </td>
                        <td class="des-col-actions">
                            <?php if ($canCatalog): ?>
                                <div class="dropdown" data-des-stop>
                                    <button class="des-menu-btn" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="Acțiuni articol">
                                        <i class="bi bi-three-dots" aria-hidden="true"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <button class="dropdown-item" type="button" data-des-catalog-edit='<?= e(json_encode([
                                                'id' => (int) $item['id'],
                                                'denumire' => (string) $item['denumire'],
                                                'categorie' => (string) $item['categorie'],
                                                'grupa' => (string) $item['grupa'],
                                                'destinatie' => (string) ($item['destinatie'] ?? 'ambele'),
                                                'tip_logic' => (string) $item['tip_logic'],
                                                'returnabil' => (int) $item['returnabil'],
                                                'urmareste_stare' => (int) $item['urmareste_stare'],
                                                'inlocuire_periodica' => (int) $item['inlocuire_periodica'],
                                                'durata_standard_luni' => (int) ($item['durata_standard_luni'] ?? 0),
                                                'urmareste_expirare' => (int) $item['urmareste_expirare'],
                                                'necesita_marime' => (int) $item['necesita_marime'],
                                                'necesita_identificator' => (int) $item['necesita_identificator'],
                                                'serializat' => (int) $item['serializat'],
                                                'cost_implicit' => (float) $item['cost_implicit'],
                                                'cost_lunar' => $item['cost_lunar'] !== null ? (float) $item['cost_lunar'] : '',
                                                'prag_minim_stoc' => (int) $item['prag_minim_stoc'],
                                                'locatie_implicita' => (string) $item['locatie_implicita'],
                                                'activ' => (int) $item['activ'],
                                                'imagine_url' => $photoUrl($item['imagine'] ?? null) ?? '',
                                                'observatii' => (string) ($item['observatii'] ?? ''),
                                            ], JSON_UNESCAPED_UNICODE)) ?>'>
                                                <i class="bi bi-pencil"></i>Editează
                                            </button>
                                        </li>
                                        <li>
                                            <button class="dropdown-item text-danger" type="button" data-des-catalog-delete="<?= e((string) $item['id']) ?>" data-des-denumire="<?= e((string) $item['denumire']) ?>">
                                                <i class="bi bi-trash3"></i>Șterge / dezactivează
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ($hasDetails): ?>
                        <tr class="des-expand-row" id="<?= e($panelId) ?>" hidden>
                            <td class="des-expand-cell" colspan="17">
                                <?php include __DIR__ . '/_catalog_panel.php'; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
