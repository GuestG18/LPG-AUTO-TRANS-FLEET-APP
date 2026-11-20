<?php
/**
 * Cardul „De înlocuit” — al patrulea card cu fețe:
 *   1 De înlocuit (total)  -->  2 Distribuție înlocuiri (pe persoane)  -->  3 Înlocuire echipamente
 * Nivelul 3: silueta din Catalog, 4 indicatori, categoriile persoanei, tabelul articolelor
 * și „Înregistrează înlocuirea”, care deschide formularul EXISTENT „Înlocuiește din stoc”
 * (action=inlocuieste -> DriverEquipmentModel::replaceFromStock(): verifică stocul pe
 * mărime, închide alocarea veche după motiv, predă bucata nouă din stoc, totul atomic).
 *
 * Date: DriverEquipmentModel::buildReplacementOverview() — aceeași regulă ca indicatorul
 * de_inlocuit (status de_inlocuit / necesita_inlocuire). JS-ul e comun cu „De returnat”
 * (personWorkspace în echipamente-valoare.js).
 *
 * Așteaptă (din _value_morph.php): $replacementOverview, $money, $plural, $img, $role,
 * $isStaffPage, $defaultVariant, $canManage, $itemDataAttributes.
 */

$repl = is_array($replacementOverview ?? null) ? $replacementOverview : ['owners' => [], 'totals' => []];
$iOwners = $repl['owners'];
$iTotals = ($repl['totals'] ?? []) + ['bucati' => 0, 'valoare' => 0.0, 'persoane' => 0, 'articole' => 0, 'pret_lipsa' => 0, 'fara_stoc' => 0];
$iPeople = $isStaffPage ? 'TESA' : 'Șoferi';
$iPerson = $isStaffPage ? 'Angajat' : 'Șofer';

$jsReplaceOwners = [];
foreach ($iOwners as $owner) {
    $jsReplaceOwners[(string) $owner['id']] = [
        'nume' => $owner['nume'], 'initiale' => $owner['initiale'], 'meta' => $owner['meta'], 'inactiv' => false,
        'articole' => $owner['articole'], 'bucati' => $owner['bucati'], 'valoare' => round($owner['valoare'], 2),
        'linii' => count($owner['linii']), 'pretLipsa' => $owner['pret_lipsa'], 'faraStoc' => $owner['fara_stoc'], 'foto' => $personPhotos($owner['linii']), 'catalogIds' => $ownedIds($owner['linii']),
        'categorii' => array_map(static fn(array $c): array => [
            'key' => $c['key'], 'name' => $c['name'], 'icon' => $c['icon'], 'anchor' => $c['anchor'],
            'articole' => $c['articole'], 'bucati' => $c['bucati'], 'valoare' => round($c['valoare'], 2),
        ], $owner['categorii']),
    ];
}
?>
<article class="dci-card" data-dci-card data-dci-level="1" data-ws-card="replace" aria-label="De înlocuit">

    <!-- ------------------------------------------------ Nivel 1: De înlocuit -->
    <button class="dci-face dci-l1" type="button" data-dci-face="1" data-dci-go="2" title="Vezi cine are echipamente de înlocuit">
        <span class="dcs-l1-top">
            <span class="dci-ico" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></span>
            <strong>De înlocuit</strong>
            <i class="bi bi-chevron-right dcs-go" aria-hidden="true"></i>
        </span>
        <b class="dci-qty"><?= e(format_number_ro($iTotals['bucati'], 0)) ?> <small>buc.</small></b>
        <small class="dcs-sub"><?= e($plural((int) $iTotals['articole'], 'articol de înlocuit', 'articole de înlocuit')) ?></small>
        <small class="dcs-desc">Alocări marcate „De înlocuit” sau „Necesită înlocuire”.</small>
        <span class="dcs-val" title="Cost estimat de înlocuire: prețul liniei de stoc din care s-ar preda, altfel costul din catalog.">
            <?php if ((int) $iTotals['bucati'] > 0 && (float) $iTotals['valoare'] <= 0): ?>
                <span class="dcv-missing">cost indisponibil</span>
            <?php else: ?>
                <?= e($money($iTotals['valoare'])) ?><?= (int) $iTotals['pret_lipsa'] > 0 ? ' *' : '' ?> <small>estimat</small>
            <?php endif; ?>
        </span>
    </button>

    <!-- ----------------------------------------- Nivel 2: Distribuție înlocuiri -->
    <div class="dci-face dcs-l2" data-dci-face="2" hidden>
        <header class="dcv-face-head">
            <button class="dcv-back" type="button" data-dci-go="1" aria-label="Înapoi la De înlocuit"><i class="bi bi-arrow-left"></i></button>
            <div>
                <h2>Distribuție înlocuiri</h2>
                <p>Lista persoanelor care au echipamente ce trebuie înlocuite.</p>
            </div>
        </header>

        <label class="dcv-search dcs-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <span class="visually-hidden">Caută persoană sau echipament</span>
            <input type="search" placeholder="Caută <?= $isStaffPage ? 'angajat, departament' : 'șofer, număr' ?>, echipament..." data-dci-search autocomplete="off">
        </label>

        <div class="dcr-list-head">
            <span><?= e($iPerson) ?></span>
            <span class="is-num">Articole</span>
            <span class="is-num">Cantitate</span>
            <span class="is-num">Valoare estimată</span>
            <span></span>
        </div>
        <div class="dcs-list" data-dci-list>
            <?php foreach ($iOwners as $owner): ?>
                <?php
                $haystack = [$owner['nume'], $owner['meta']];
                foreach ($owner['linii'] as $line) {
                    $haystack[] = $line['item']['denumire'];
                }
                ?>
                <button class="dcr-row dci-row" type="button" data-dci-owner="<?= e((string) $owner['id']) ?>" data-name="<?= e(mb_strtolower(implode(' ', $haystack))) ?>">
                    <span class="dcv-person-name">
                        <span class="dcv-avatar"><?= e($owner['initiale']) ?></span>
                        <span>
                            <?= e($owner['nume']) ?>
                            <small class="dcr-meta"><?= e($owner['meta'] !== '' ? $owner['meta'] : '—') ?><?= $owner['fara_stoc'] > 0 ? ' · lipsă stoc' : '' ?></small>
                        </span>
                    </span>
                    <span class="is-num"><?= e((string) $owner['articole']) ?></span>
                    <span class="is-num"><strong><?= e(format_number_ro($owner['bucati'], 0)) ?></strong> buc.</span>
                    <span class="is-num is-money"><?= e($money($owner['valoare'])) ?><?php if ($owner['pret_lipsa'] > 0): ?><i class="bi bi-exclamation-circle dcv-miss" title="Linii fără preț de înlocuire — estimare incompletă"></i><?php endif; ?></span>
                    <i class="bi bi-chevron-right dcv-person-go" aria-hidden="true"></i>
                </button>
            <?php endforeach; ?>
            <div class="dcr-empty" data-dci-list-empty <?= $iOwners === [] ? '' : 'hidden' ?>>
                <?php if ($iOwners === []): ?>
                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                    <strong>Nimic de înlocuit</strong>
                    <span>Nicio alocare nu este marcată „De înlocuit” sau „Necesită înlocuire”.</span>
                <?php else: ?>
                    <span>Niciun rezultat pentru căutare.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- --------------------------------------- Nivel 3: Înlocuire echipamente -->
    <div class="dci-face dcr-l3" data-dci-face="3" hidden>
        <header class="dcv-crumbs">
            <button class="dcv-back" type="button" data-dci-go="2" aria-label="Înapoi la lista de înlocuiri"><i class="bi bi-arrow-left"></i></button>
            <nav aria-label="Navigare">
                <button type="button" data-dci-go="1">De înlocuit</button>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <button type="button" data-dci-go="2"><?= e($iPeople) ?></button>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <strong data-dci-crumb>—</strong>
            </nav>
        </header>

        <div class="dcr-body">
            <div class="dcv-figure-wrap">
                <div class="dcv-figure dci-figure dcr-figure" data-variant="<?= e($defaultVariant) ?>">
                    <span class="dcv-platform" aria-hidden="true"></span>
                    <img class="dcv-figure-img <?= $defaultVariant === 'm' ? 'is-active' : '' ?>" data-variant="m" src="<?= e($img($role, 'm')) ?>" alt="" draggable="false">
                    <img class="dcv-figure-img <?= $defaultVariant === 'f' ? 'is-active' : '' ?>" data-variant="f" src="<?= e($img($role, 'f')) ?>" alt="" draggable="false">
                    <div class="dcv-dots" data-dci-dots></div>
                    </div>
                    <svg class="dcv-bubble-links" data-bubble-links aria-hidden="true" focusable="false"></svg>
                    <div class="dcv-bubbles" data-bubbles>
                </div>
            </div>

            <div class="dcv-detail">
                <div class="dcv-id">
                    <span class="dci-head-ico" aria-hidden="true"><i class="bi bi-arrow-repeat"></i></span>
                    <div class="dcv-id-text">
                        <strong>Înlocuire echipamente</strong>
                        <span>Articolele care trebuie înlocuite pentru <b data-dci-name>—</b><span class="dci-meta-sep"> · </span><span data-dci-meta></span>.</span>
                    </div>
                    <?php if (!empty($canManage)): ?>
                        <button class="dcr-action dci-action" type="button" data-dci-action disabled>
                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>Înregistrează înlocuirea
                        </button>
                    <?php endif; ?>
                </div>

                <div class="dcr-metrics">
                    <div class="dcs-metric dci-metric"><span>Total de înlocuit</span><b data-dci-m-total>0</b><small>alocări (linii)</small></div>
                    <div class="dcs-metric"><span>Nr. articole</span><b data-dci-m-articole>0</b><small>tipuri distincte</small></div>
                    <div class="dcs-metric"><span>Nr. bucăți</span><b data-dci-m-bucati>0</b><small data-dci-m-extra>—</small></div>
                    <div class="dcs-metric dci-metric"><span>Valoare estimată</span><b data-dci-m-valoare>0,00 lei</b><small data-dci-m-note>cost estimat de înlocuire</small></div>
                </div>

                <div class="dcv-cats dci-cats" data-dci-cats role="tablist" aria-label="Categorii"></div>

                <div class="dcv-items">
                    <div class="dcv-items-scroll dcr-scroll">
                        <table class="dcv-table dcr-table dci-table">
                            <thead>
                                <tr>
                                    <th>Articol</th>
                                    <th>Categorie</th>
                                    <th class="is-num">Cantitate</th>
                                    <th>Motiv</th>
                                    <th>Data scadenței</th>
                                    <th class="is-num">Valoare estimată</th>
                                    <th>Status</th>
                                    <th class="is-act"><span class="visually-hidden">Acțiuni</span></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($iOwners as $owner): ?>
                                <?php $ownerForAttrs = ['id' => $owner['id'], 'nume' => $owner['nume']]; ?>
                                <?php foreach ($owner['linii'] as $line): ?>
                                    <?php $item = $line['item']; ?>
                                    <tr class="dci-line" data-owner="<?= e((string) $owner['id']) ?>" data-cat="<?= e($line['cat']) ?>" hidden tabindex="0">
                                        <td>
                                            <div class="dcv-item-name">
                                                <?php $photo = $linePhoto($line); ?>
                                                <?php if ($photo !== null): ?><span class="dcv-item-ico is-photo" aria-hidden="true"><img src="<?= e($photo) ?>" alt="" loading="lazy"></span><?php else: ?><span class="dcv-item-ico" aria-hidden="true"><i class="bi <?= e($line['icon']) ?>"></i></span><?php endif; ?>
                                                <div>
                                                    <strong><?= e((string) $item['denumire']) ?><?= $line['marime'] !== '' ? ' <small>' . e($line['marime']) . '</small>' : '' ?></strong>
                                                    <span class="<?= $line['stoc_ok'] ? 'dci-stock-ok' : 'dci-stock-no' ?>">
                                                        <?= $line['stoc_ok'] ? 'în stoc: ' . e((string) $line['stoc']) . ' buc.' : 'lipsă stoc' . ($line['stoc'] > 0 ? ' (doar ' . e((string) $line['stoc']) . ' buc.)' : '') ?><?= $line['marime'] !== '' ? ' · mărimea ' . e($line['marime']) : '' ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= e($line['categorie']) ?></td>
                                        <td class="is-num"><?= e((string) $line['cantitate']) ?> buc.</td>
                                        <td<?= $line['nota'] !== '' ? ' title="' . e($line['nota']) . '"' : '' ?>><?= e($line['motiv']) ?></td>
                                        <td>
                                            <?php if ($line['termen'] !== null): ?>
                                                <?= e(format_date_ro((string) $line['termen'])) ?>
                                                <?php if ($line['zile'] !== null && $line['zile'] < 0): ?><small class="dci-late">depășit</small><?php endif; ?>
                                            <?php else: ?>
                                                <span class="dcv-missing" title="Articolul nu are un termen de înlocuire înregistrat">fără termen</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="is-num"><strong><?= $line['valoare'] !== null ? e($money($line['valoare'])) : '<span class="dcv-missing">preț lipsă</span>' ?></strong></td>
                                        <td><span class="dcr-badge is-red"><?= e($line['status']) ?></span></td>
                                        <td class="is-act">
                                            <?php if (!empty($canManage)): ?>
                                                <button class="dci-row-replace dcr-row-return" type="button" data-des-open="replace"<?= $itemDataAttributes($item, $ownerForAttrs) ?> title="Înlocuiește din stoc">
                                                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i><span class="visually-hidden">Înlocuiește</span>
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
                    Înlocuirea verifică stocul pe mărime și, dacă nu ajunge, nu se înregistrează. Bucata veche intră înapoi în stoc doar dacă e returnabilă și „uzată” sau „în stare bună”.
                    <?php if (empty($canManage)): ?> Nu ai drept de a înregistra înlocuiri.<?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <script type="application/json" data-dci-data><?= json_encode(['owners' => $jsReplaceOwners], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</article>
