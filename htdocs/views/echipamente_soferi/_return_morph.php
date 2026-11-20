<?php
/**
 * Cardul „De returnat” — al treilea card cu fețe:
 *   1 De returnat (total)  -->  2 Distribuție returnări (pe persoane)  -->  3 Returnare echipamente
 * Nivelul 3: silueta din Catalog, 4 indicatori, categoriile persoanei, tabelul articolelor
 * și „Înregistrează returnarea”, care deschide formularul EXISTENT de returnare (modalul
 * „Returnează echipamentul”, action=returnare -> DriverEquipmentModel::returnItem()).
 *
 * Date: DriverEquipmentModel::buildReturnOverview() — aceeași regulă ca indicatorul de_returnat.
 * Returnarea închide toată alocarea: backend-ul nu are returnări parțiale.
 *
 * Așteaptă (din _value_morph.php): $returnOverview, $money, $plural, $img, $role, $isStaffPage,
 * $defaultVariant, $canManage, $itemDataAttributes.
 */

$returns = is_array($returnOverview ?? null) ? $returnOverview : ['owners' => [], 'totals' => []];
$rOwners = $returns['owners'];
$rTotals = ($returns['totals'] ?? []) + ['bucati' => 0, 'valoare' => 0.0, 'persoane' => 0, 'articole' => 0, 'pret_lipsa' => 0, 'linii' => 0];
$rPeople = $isStaffPage ? 'TESA' : 'Șoferi';
$rPerson = $isStaffPage ? 'Angajat' : 'Șofer';

$jsReturnOwners = [];
foreach ($rOwners as $owner) {
    $jsReturnOwners[(string) $owner['id']] = [
        'nume' => $owner['nume'], 'initiale' => $owner['initiale'], 'meta' => $owner['meta'], 'inactiv' => $owner['inactiv'],
        'articole' => $owner['articole'], 'bucati' => $owner['bucati'], 'valoare' => round($owner['valoare'], 2),
        'linii' => count($owner['linii']), 'pretLipsa' => $owner['pret_lipsa'], 'foto' => $personPhotos($owner['linii']), 'catalogIds' => $ownedIds($owner['linii']),
        'categorii' => array_map(static fn(array $c): array => [
            'key' => $c['key'], 'name' => $c['name'], 'icon' => $c['icon'], 'anchor' => $c['anchor'],
            'articole' => $c['articole'], 'bucati' => $c['bucati'], 'valoare' => round($c['valoare'], 2),
        ], $owner['categorii']),
    ];
}
?>
<article class="dcr-card" data-dcr-card data-dcr-level="1" data-ws-card="return" aria-label="De returnat">

    <!-- ------------------------------------------------ Nivel 1: De returnat -->
    <button class="dcr-face dcr-l1" type="button" data-dcr-face="1" data-dcr-go="2" title="Vezi cine are echipamente de returnat">
        <span class="dcs-l1-top">
            <span class="dcr-ico" aria-hidden="true"><i class="bi bi-arrow-counterclockwise"></i></span>
            <strong>De returnat</strong>
            <i class="bi bi-chevron-right dcs-go" aria-hidden="true"></i>
        </span>
        <b class="dcr-qty"><?= e(format_number_ro($rTotals['bucati'], 0)) ?> <small>buc.</small></b>
        <small class="dcs-sub"><?= e($plural((int) $rTotals['articole'], 'articol de returnat', 'articole de returnat')) ?></small>
        <small class="dcs-desc">Returnabile marcate pentru înlocuire sau rămase la persoane inactive.</small>
        <span class="dcs-val" title="Valoarea înregistrată la predare a echipamentelor încă nereturnate — nu este o pierdere și nici o sumă recuperată."><?= e($money($rTotals['valoare'])) ?><?= (int) $rTotals['pret_lipsa'] > 0 ? ' *' : '' ?> <small>de recuperat</small></span>
    </button>

    <!-- ----------------------------------------- Nivel 2: Distribuție returnări -->
    <div class="dcr-face dcs-l2" data-dcr-face="2" hidden>
        <header class="dcv-face-head">
            <button class="dcv-back" type="button" data-dcr-go="1" aria-label="Înapoi la De returnat"><i class="bi bi-arrow-left"></i></button>
            <div>
                <h2>Distribuție returnări</h2>
                <p>Lista persoanelor care au echipamente de returnat.</p>
            </div>
        </header>

        <label class="dcv-search dcs-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <span class="visually-hidden">Caută persoană sau echipament</span>
            <input type="search" placeholder="Caută <?= $isStaffPage ? 'angajat' : 'șofer' ?>, număr, echipament..." data-dcr-search autocomplete="off">
        </label>

        <div class="dcr-list-head">
            <span><?= e($rPerson) ?></span>
            <span class="is-num">Articole</span>
            <span class="is-num">Cantitate</span>
            <span class="is-num">De recuperat</span>
            <span></span>
        </div>
        <div class="dcs-list dcr-list" data-dcr-list>
            <?php foreach ($rOwners as $owner): ?>
                <?php
                $haystack = [$owner['nume'], $owner['meta']];
                foreach ($owner['linii'] as $line) {
                    $haystack[] = $line['denumire'];
                }
                ?>
                <button class="dcr-row" type="button" data-dcr-owner="<?= e((string) $owner['id']) ?>" data-name="<?= e(mb_strtolower(implode(' ', $haystack))) ?>">
                    <span class="dcv-person-name">
                        <span class="dcv-avatar"><?= e($owner['initiale']) ?></span>
                        <span>
                            <?= e($owner['nume']) ?>
                            <small class="dcr-meta"><?= e($owner['meta'] !== '' ? $owner['meta'] : '—') ?><?= $owner['inactiv'] ? ' · inactiv' : '' ?></small>
                        </span>
                    </span>
                    <span class="is-num"><?= e((string) $owner['articole']) ?></span>
                    <span class="is-num"><strong><?= e(format_number_ro($owner['bucati'], 0)) ?></strong> buc.</span>
                    <span class="is-num is-money"><?= e($money($owner['valoare'])) ?><?php if ($owner['pret_lipsa'] > 0): ?><i class="bi bi-exclamation-circle dcv-miss" title="Linii fără preț — valoare incompletă"></i><?php endif; ?></span>
                    <i class="bi bi-chevron-right dcv-person-go" aria-hidden="true"></i>
                </button>
            <?php endforeach; ?>
            <div class="dcr-empty" data-dcr-list-empty <?= $rOwners === [] ? '' : 'hidden' ?>>
                <?php if ($rOwners === []): ?>
                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                    <strong>Nimic de returnat</strong>
                    <span>Nicio alocare returnabilă nu este marcată pentru înlocuire și nu a rămas la o persoană inactivă.</span>
                <?php else: ?>
                    <span>Niciun rezultat pentru căutare.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ---------------------------------------- Nivel 3: Returnare echipamente -->
    <div class="dcr-face dcr-l3" data-dcr-face="3" hidden>
        <header class="dcv-crumbs">
            <button class="dcv-back" type="button" data-dcr-go="2" aria-label="Înapoi la lista de returnări"><i class="bi bi-arrow-left"></i></button>
            <nav aria-label="Navigare">
                <button type="button" data-dcr-go="1">De returnat</button>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <button type="button" data-dcr-go="2"><?= e($rPeople) ?></button>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <strong data-dcr-crumb>—</strong>
            </nav>
        </header>

        <div class="dcr-body">
            <div class="dcv-figure-wrap">
                <div class="dcv-figure dcr-figure" data-variant="<?= e($defaultVariant) ?>">
                    <span class="dcv-platform" aria-hidden="true"></span>
                    <img class="dcv-figure-img <?= $defaultVariant === 'm' ? 'is-active' : '' ?>" data-variant="m" src="<?= e($img($role, 'm')) ?>" alt="" draggable="false">
                    <img class="dcv-figure-img <?= $defaultVariant === 'f' ? 'is-active' : '' ?>" data-variant="f" src="<?= e($img($role, 'f')) ?>" alt="" draggable="false">
                    <div class="dcv-dots" data-dcr-dots></div>
                    </div>
                    <svg class="dcv-bubble-links" data-bubble-links aria-hidden="true" focusable="false"></svg>
                    <div class="dcv-bubbles" data-bubbles>
                </div>
            </div>

            <div class="dcv-detail">
                <div class="dcv-id">
                    <span class="dcv-avatar is-lg dcr-avatar" data-dcr-initials>—</span>
                    <div class="dcv-id-text">
                        <strong data-dcr-name>—</strong>
                        <span data-dcr-meta></span>
                    </div>
                    <?php if (!empty($canManage)): ?>
                        <button class="dcr-action" type="button" data-dcr-action disabled>
                            <i class="bi bi-box-arrow-in-left" aria-hidden="true"></i>Înregistrează returnarea
                        </button>
                    <?php endif; ?>
                </div>

                <div class="dcr-metrics">
                    <div class="dcs-metric dcr-metric"><span>Total de returnat</span><b data-dcr-m-total>0</b><small>alocări (linii)</small></div>
                    <div class="dcs-metric"><span>Nr. articole</span><b data-dcr-m-articole>0</b><small>tipuri distincte</small></div>
                    <div class="dcs-metric"><span>Nr. bucăți</span><b data-dcr-m-bucati>0</b><small>de adus înapoi</small></div>
                    <div class="dcs-metric dcr-metric"><span>Valoare de recuperat</span><b data-dcr-m-valoare>0,00 lei</b><small data-dcr-m-note>nu e pierdere</small></div>
                </div>

                <div class="dcv-cats dcr-cats" data-dcr-cats role="tablist" aria-label="Categorii"></div>

                <div class="dcv-items">
                    <div class="dcv-items-scroll dcr-scroll">
                        <table class="dcv-table dcr-table">
                            <thead>
                                <tr>
                                    <th>Articol</th>
                                    <th>Categorie</th>
                                    <th class="is-num">Cantitate</th>
                                    <th>Motiv</th>
                                    <th>Data predării</th>
                                    <th class="is-num">Valoare totală</th>
                                    <th>Status</th>
                                    <th class="is-act"><span class="visually-hidden">Acțiuni</span></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rOwners as $owner): ?>
                                <?php $ownerForAttrs = ['id' => $owner['id'], 'nume' => $owner['nume']]; ?>
                                <?php foreach ($owner['linii'] as $line): ?>
                                    <?php
                                    $itemForAttrs = [
                                        'id' => $line['id'], 'catalog_id' => $line['catalog_id'], 'denumire' => $line['denumire'],
                                        'cantitate' => $line['cantitate'], 'marime' => $line['marime'], 'returnabil' => $line['returnabil'],
                                        'stare' => $line['stare_raw'], 'status' => $line['status_raw'],
                                    ];
                                    ?>
                                    <tr class="dcr-line" data-owner="<?= e((string) $owner['id']) ?>" data-cat="<?= e($line['cat']) ?>" data-line="<?= e((string) $line['id']) ?>" hidden tabindex="0">
                                        <td>
                                            <div class="dcv-item-name">
                                                <?php $photo = $linePhoto($line); ?>
                                                <?php if ($photo !== null): ?><span class="dcv-item-ico is-photo" aria-hidden="true"><img src="<?= e($photo) ?>" alt="" loading="lazy"></span><?php else: ?><span class="dcv-item-ico" aria-hidden="true"><i class="bi <?= e($line['icon']) ?>"></i></span><?php endif; ?>
                                                <div>
                                                    <strong><?= e($line['denumire']) ?><?= $line['marime'] !== '' ? ' <small>' . e($line['marime']) . '</small>' : '' ?></strong>
                                                    <span>stare: <?= e($line['stare']) ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= e($line['categorie']) ?></td>
                                        <td class="is-num"><?= e((string) $line['cantitate']) ?> buc.</td>
                                        <td><?= e($line['motiv']) ?></td>
                                        <td><?= e(format_date_ro($line['data_predarii'])) ?></td>
                                        <td class="is-num"><strong><?= $line['valoare'] !== null ? e($money($line['valoare'])) : '<span class="dcv-missing">preț lipsă</span>' ?></strong></td>
                                        <td><span class="dcr-badge <?= in_array($line['status_key'], DriverEquipmentModel::REPLACEMENT_STATUSES, true) ? 'is-orange' : 'is-muted' ?>"><?= e($line['status']) ?></span></td>
                                        <td class="is-act">
                                            <?php if (!empty($canManage)): ?>
                                                <button class="dcr-row-return" type="button" data-des-open="return"<?= $itemDataAttributes($itemForAttrs, $ownerForAttrs) ?> title="Returnează acest articol">
                                                    <i class="bi bi-box-arrow-in-left" aria-hidden="true"></i><span class="visually-hidden">Returnează</span>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p class="dcr-note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Returnarea se înregistrează pe toată linia (cantitatea predată). Doar „Revine în stoc” crește stocul; „Deteriorat” și „Pierdut” nu.
                    <?php if (empty($canManage)): ?> Nu ai drept de a înregistra returnări.<?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <script type="application/json" data-dcr-data><?= json_encode(['owners' => $jsReturnOwners], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</article>
