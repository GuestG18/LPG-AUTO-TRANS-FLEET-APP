<?php
/**
 * Meniul de acțiuni pentru un articol alocat unui șofer.
 * Se include din index.php, în ambele tabele (fizice și comunicații), cu
 * $item, $driver, $canManage și $itemDataAttributes deja în scope.
 *
 * Acțiunile disponibile depind de starea articolului: unul returnat sau
 * înlocuit nu mai poate fi returnat sau marcat din nou.
 */

$itemAttributes = $itemDataAttributes($item, $driver);
$isActive = !empty($item['activa']);
$needsReplacement = in_array((string) $item['status'], DriverEquipmentModel::REPLACEMENT_STATUSES, true);
?>
<div class="dropdown" data-des-stop>
    <button class="des-menu-btn" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="Acțiuni articol">
        <i class="bi bi-three-dots" aria-hidden="true"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li>
            <button class="dropdown-item" type="button" data-des-open="details"<?= $itemAttributes ?>
                    data-des-categorie="<?= e((string) $item['categorie']) ?>"
                    data-des-logic="<?= e(DriverEquipmentModel::LOGIC_TYPES[(string) $item['tip_logic']] ?? (string) $item['tip_logic']) ?>"
                    data-des-predare="<?= e(format_date_ro((string) $item['data_predarii'])) ?>"
                    data-des-termen="<?= e($item['termen'] !== null ? format_date_ro((string) $item['termen']) : '—') ?>"
                    data-des-cost="<?= e(format_number_ro((float) $item['cost_unitar'], 2)) ?>"
                    data-des-identificator="<?= e((string) ($item['identificator'] ?? '')) ?>"
                    data-des-observatii="<?= e((string) ($item['observatii'] ?? '')) ?>">
                <i class="bi bi-eye"></i>Vezi detalii
            </button>
        </li>

        <?php if ($canManage && $isActive): ?>
            <li><hr class="dropdown-divider"></li>
            <li class="dropdown-header">Operațiuni</li>
            <li>
                <button class="dropdown-item" type="button" data-des-open="replace"<?= $itemAttributes ?>>
                    <i class="bi bi-arrow-repeat"></i>Înlocuiește<?= $needsReplacement ? ' (recomandat)' : '' ?>
                </button>
            </li>
            <li>
                <button class="dropdown-item" type="button" data-des-open="return"<?= $itemAttributes ?>>
                    <i class="bi bi-box-arrow-in-left"></i><?= (int) $item['returnabil'] === 1 ? 'Returnează în stoc' : 'Returnează' ?>
                </button>
            </li>

            <li><hr class="dropdown-divider"></li>
            <li class="dropdown-header">Marchează</li>
            <li>
                <button class="dropdown-item" type="button" data-des-open="mark" data-des-marcaj="deteriorat"<?= $itemAttributes ?>>
                    <i class="bi bi-exclamation-triangle"></i>Deteriorat
                </button>
            </li>
            <li>
                <button class="dropdown-item" type="button" data-des-open="mark" data-des-marcaj="de_inlocuit"<?= $itemAttributes ?>>
                    <i class="bi bi-clock-history"></i>De înlocuit
                </button>
            </li>
            <li>
                <button class="dropdown-item" type="button" data-des-open="mark" data-des-marcaj="pierdut"<?= $itemAttributes ?>>
                    <i class="bi bi-x-octagon"></i>Pierdut
                </button>
            </li>
            <?php if ((string) $item['stare'] !== 'buna' || $needsReplacement): ?>
                <li>
                    <button class="dropdown-item" type="button" data-des-open="mark" data-des-marcaj="bun"<?= $itemAttributes ?>>
                        <i class="bi bi-check2-circle"></i>Stare bună
                    </button>
                </li>
            <?php endif; ?>
        <?php elseif ($canManage): ?>
            <li><hr class="dropdown-divider"></li>
            <li>
                <button class="dropdown-item" type="button" data-des-open="handover" data-des-sofer-id="<?= e((string) $driver['id']) ?>" data-des-sofer="<?= e((string) $driver['nume']) ?>" data-des-catalog="<?= e((string) $item['catalog_id']) ?>" data-des-denumire="<?= e((string) $item['denumire']) ?>">
                    <i class="bi bi-plus-circle"></i>Predă din nou
                </button>
            </li>
        <?php endif; ?>
    </ul>
</div>
