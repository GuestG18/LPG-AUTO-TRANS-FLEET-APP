<?php
/**
 * Cardul „Valoare echipamente alocate” + cele trei carduri de stare din dreapta.
 * Comun pentru Echipamente șoferi și Echipamente TESA (diferă doar sursa de persoane).
 *
 * Un singur card care își schimbă fața (ca „Cost total operațional” din Tablou de bord):
 *   Nivel 1 „total”  --(click pe valoare)-->  Nivel 2 „persoane”  --(click pe persoană)-->  Nivel 3 „detaliu”
 * Nivelul 3 arată silueta din Catalog echipamente, categoriile persoanei și articolele ei.
 *
 * Așteaptă: $valueOverview (DriverEquipmentModel::buildValueOverview), $valueRows (rândurile
 * getOwnerRows, pentru meniurile de articol), $kpis, $ownerType, $canManage, $itemDataAttributes,
 * $money, $baseQuery.
 */

$overview = is_array($valueOverview ?? null) ? $valueOverview : ['owners' => [], 'totals' => []];
$vOwners = $overview['owners'];
$vTotals = ($overview['totals'] ?? []) + ['valoare' => 0.0, 'bucati' => 0, 'articole' => 0, 'persoane' => 0, 'pret_lipsa' => 0];
$isStaffPage = ($ownerType ?? DriverEquipmentModel::OWNER_DRIVER) === DriverEquipmentModel::OWNER_STAFF;
$role = $isStaffPage ? 'tesa' : 'sofer';
$personWord = $isStaffPage ? 'persoană' : 'șofer';
$peopleWord = $isStaffPage ? 'TESA' : 'Șoferi';
$defaultVariant = $isStaffPage ? 'f' : 'm';

include __DIR__ . '/_silhouette_assets.php';

$rowsById = [];
foreach (is_array($valueRows ?? null) ? $valueRows : [] as $valueRow) {
    $rowsById[(int) $valueRow['id']] = $valueRow;
}

$plural = static fn(int $count, string $one, string $many): string => format_number_ro($count, 0) . ' ' . ($count === 1 ? $one : $many);
$baseFilters = array_diff_key($baseQuery ?? ['page' => 'echipamente_soferi'], ['q' => true]);
if ($isStaffPage) {
    $baseFilters['action'] = 'tesa';
}


// Datele pentru JS: nivelul 3 se completează din ele (antet, categorii, puncte pe siluetă).
$jsOwners = [];
foreach ($vOwners as $owner) {
    $jsOwners[(string) $owner['id']] = [
        'nume' => $owner['nume'],
        'initiale' => $owner['initiale'],
        'meta' => $owner['meta'],
        'valoare' => round($owner['valoare'], 2),
        'articole' => $owner['articole'],
        'bucati' => $owner['bucati'],
        'pretLipsa' => $owner['pret_lipsa'],
        'categorii' => array_map(static fn(array $c): array => [
            'key' => $c['key'], 'name' => $c['name'], 'icon' => $c['icon'], 'anchor' => $c['anchor'],
            'valoare' => round($c['valoare'], 2), 'bucati' => $c['bucati'], 'procent' => $c['procent'],
        ], $owner['categorii']),
    ];
}
?>
<?php $stockOverview = $stockOverview ?? null; ?>
<link rel="stylesheet" href="<?= e(url('assets/css/echipamente-valoare.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/echipamente-valoare.css'))) ?>">

<section class="dcv" data-dcv data-dcv-role="<?= e($role) ?>" data-dcv-default-variant="<?= e($defaultVariant) ?>" aria-label="Valoare echipamente alocate">

    <article class="dcv-card" data-dcv-card data-dcv-level="1" data-ws-card="value">

        <!-- ---------------------------------------------- Nivel 1: total -->
        <div class="dcv-face dcv-l1" data-dcv-face="1">
            <div class="dcv-l1-head">
                <span class="dcv-ico" aria-hidden="true"><i class="bi bi-stack"></i></span>
                <div>
                    <h2>Valoare echipamente alocate</h2>
                    <p>Valoarea totală a echipamentelor atribuite <?= $isStaffPage ? 'personalului TESA' : 'șoferilor' ?> activi, la prețul de la predare.</p>
                </div>
            </div>

            <button class="dcv-total" type="button" data-dcv-go="2" title="Vezi distribuția pe <?= $isStaffPage ? 'persoane' : 'șoferi' ?>">
                <i class="bi bi-stack" aria-hidden="true"></i>
                <strong><?= e($money($vTotals['valoare'])) ?></strong>
                <i class="bi bi-chevron-right dcv-total-go" aria-hidden="true"></i>
            </button>

            <div class="dcv-l1-stats">
                <span><i class="bi bi-box" aria-hidden="true"></i><?= e($plural((int) $vTotals['articole'], 'articol', 'articole')) ?></span>
                <span><i class="bi bi-boxes" aria-hidden="true"></i><?= e($plural((int) $vTotals['bucati'], 'bucată', 'bucăți')) ?></span>
                <span><i class="bi bi-people" aria-hidden="true"></i><?= e($plural((int) $vTotals['persoane'], $isStaffPage ? 'persoană echipată' : 'șofer echipat', $isStaffPage ? 'persoane echipate' : 'șoferi echipați')) ?></span>
            </div>

            <?php if ((int) $vTotals['pret_lipsa'] > 0): ?>
                <p class="dcv-warn"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><?= e($plural((int) $vTotals['pret_lipsa'], 'linie fără preț', 'linii fără preț')) ?> — valoarea este incompletă.</p>
            <?php endif; ?>

            <button class="dcv-hint" type="button" data-dcv-go="2">
                <i class="bi bi-hand-index" aria-hidden="true"></i>
                Click pe valoare pentru a vedea distribuția pe <?= $isStaffPage ? 'persoane' : 'șoferi' ?>.
            </button>
        </div>

        <!-- -------------------------------------------- Nivel 2: pe persoană -->
        <div class="dcv-face dcv-l2" data-dcv-face="2" hidden>
            <header class="dcv-face-head">
                <button class="dcv-back" type="button" data-dcv-go="1" aria-label="Înapoi la valoarea totală"><i class="bi bi-arrow-left"></i></button>
                <div>
                    <h2>Valoare pe <?= e($personWord) ?></h2>
                    <p>Distribuția valorii echipamentelor pe <?= $isStaffPage ? 'persoane' : 'șoferi' ?>.</p>
                </div>
            </header>

            <div class="dcv-l2-tools">
                <label class="dcv-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <span class="visually-hidden">Caută <?= e($personWord) ?></span>
                    <input type="search" placeholder="Caută <?= e($personWord) ?>..." data-dcv-search autocomplete="off">
                </label>
                <details class="dcv-filter">
                    <summary class="dcv-filter-btn"><i class="bi bi-funnel" aria-hidden="true"></i>Filtre</summary>
                    <div class="dcv-filter-pop">
                        <label><input type="radio" name="dcvSort" value="valoare" checked data-dcv-sort> Valoare (descrescător)</label>
                        <label><input type="radio" name="dcvSort" value="nume" data-dcv-sort> Nume (A–Z)</label>
                        <hr>
                        <label><input type="checkbox" data-dcv-only-equipped> Doar cu echipamente</label>
                    </div>
                </details>
            </div>

            <div class="dcv-list-head">
                <span><?= $isStaffPage ? 'Persoană' : 'Șofer' ?></span>
                <span class="is-num">Valoare totală</span>
                <span class="is-num">Articole</span>
                <span class="is-num">Bucăți</span>
                <span></span>
            </div>
            <div class="dcv-list" data-dcv-list>
                <?php foreach ($vOwners as $index => $owner): ?>
                    <button class="dcv-person" type="button" data-dcv-owner="<?= e((string) $owner['id']) ?>"
                            data-name="<?= e(mb_strtolower($owner['nume'] . ' ' . $owner['meta'])) ?>"
                            data-sort-value="<?= e((string) $owner['valoare']) ?>" data-sort-name="<?= e($owner['nume']) ?>"
                            data-equipped="<?= $owner['bucati'] > 0 ? '1' : '0' ?>">
                        <span class="dcv-person-name">
                            <span class="dcv-avatar"><?= e($owner['initiale']) ?></span>
                            <span><?= e($owner['nume']) ?></span>
                        </span>
                        <span class="is-num is-money"><?= e($money($owner['valoare'])) ?><?php if ($owner['pret_lipsa'] > 0): ?><i class="bi bi-exclamation-circle dcv-miss" title="<?= e($plural((int) $owner['pret_lipsa'], 'linie fără preț', 'linii fără preț')) ?>"></i><?php endif; ?></span>
                        <span class="is-num"><?= e((string) $owner['articole']) ?></span>
                        <span class="is-num"><?= e((string) $owner['bucati']) ?></span>
                        <i class="bi bi-chevron-right dcv-person-go" aria-hidden="true"></i>
                    </button>
                <?php endforeach; ?>
                <p class="dcv-empty" data-dcv-list-empty <?= $vOwners === [] ? '' : 'hidden' ?>>
                    <?= $vOwners === [] ? 'Nu există ' . ($isStaffPage ? 'persoane' : 'șoferi') . ' pentru filtrele selectate.' : 'Niciun rezultat pentru căutare.' ?>
                </p>
            </div>
        </div>

        <!-- ------------------------------------------ Nivel 3: detaliu persoană -->
        <div class="dcv-face dcv-l3" data-dcv-face="3" hidden>
            <header class="dcv-crumbs">
                <button class="dcv-back" type="button" data-dcv-go="2" aria-label="Înapoi la lista pe <?= e($personWord) ?>"><i class="bi bi-arrow-left"></i></button>
                <nav aria-label="Navigare">
                    <button type="button" data-dcv-go="1">Total valoare</button>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    <button type="button" data-dcv-go="2"><?= e($peopleWord) ?></button>
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    <strong data-dcv-crumb-name>—</strong>
                </nav>
            </header>

            <div class="dcv-l3-body">
                <div class="dcv-figure-wrap">
                    <div class="dcv-figure" data-variant="<?= e($defaultVariant) ?>">
                        <span class="dcv-platform" aria-hidden="true"></span>
                        <img class="dcv-figure-img <?= $defaultVariant === 'm' ? 'is-active' : '' ?>" data-variant="m" src="<?= e($img($role, 'm')) ?>" alt="Siluetă <?= $isStaffPage ? 'TESA' : 'șofer' ?> — masculin" draggable="false">
                        <img class="dcv-figure-img <?= $defaultVariant === 'f' ? 'is-active' : '' ?>" data-variant="f" src="<?= e($img($role, 'f')) ?>" alt="Siluetă <?= $isStaffPage ? 'TESA' : 'șofer' ?> — feminin" draggable="false">
                        <div class="dcv-dots" data-dcv-dots></div>
                    </div>
                    <div class="dcv-seg" role="radiogroup" aria-label="Siluetă">
                        <button type="button" role="radio" data-dcv-variant="m" title="Masculin" aria-label="Masculin"><i class="bi bi-gender-male"></i></button>
                        <button type="button" role="radio" data-dcv-variant="f" title="Feminin" aria-label="Feminin"><i class="bi bi-gender-female"></i></button>
                    </div>
                </div>

                <div class="dcv-detail">
                    <div class="dcv-id">
                        <span class="dcv-avatar is-lg" data-dcv-initials>—</span>
                        <div class="dcv-id-text">
                            <strong data-dcv-name>—</strong>
                            <span data-dcv-meta></span>
                        </div>
                        <div class="dcv-chips">
                            <span class="dcv-chip is-blue"><i class="bi bi-stack" aria-hidden="true"></i><b data-dcv-value>0,00 lei</b></span>
                            <span class="dcv-chip"><i class="bi bi-box" aria-hidden="true"></i><span data-dcv-articles>0 articole</span></span>
                            <span class="dcv-chip"><i class="bi bi-boxes" aria-hidden="true"></i><span data-dcv-pieces>0 bucăți</span></span>
                        </div>
                    </div>

                    <div class="dcv-cats" data-dcv-cats role="tablist" aria-label="Categorii"></div>

                    <div class="dcv-items">
                        <div class="dcv-items-scroll" data-dcv-items-scroll>
                            <table class="dcv-table">
                                <thead>
                                    <tr>
                                        <th>Echipament</th>
                                        <th class="is-num">Cantitate</th>
                                        <th class="is-num">Preț unitar</th>
                                        <th class="is-num">Valoare</th>
                                        <th class="is-act"><span class="visually-hidden">Acțiuni</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($vOwners as $owner): ?>
                                    <?php
                                    $rawItems = [];
                                    $sourceRow = $rowsById[$owner['id']] ?? null;
                                    if ($sourceRow !== null) {
                                        foreach (array_merge($sourceRow['echipamente_fizice'] ?? [], $sourceRow['comunicatii'] ?? [], $sourceRow['alte_active'] ?? []) as $raw) {
                                            $rawItems[(int) $raw['id']] = $raw;
                                        }
                                    }
                                    ?>
                                    <?php foreach ($owner['linii'] as $line): ?>
                                        <tr class="dcv-item" data-owner="<?= e((string) $owner['id']) ?>" data-cat="<?= e($line['cat']) ?>" hidden>
                                            <td>
                                                <div class="dcv-item-name">
                                                    <span class="dcv-item-ico" data-cat-icon="<?= e($line['cat']) ?>" aria-hidden="true"></span>
                                                    <div>
                                                        <strong><?= e($line['denumire']) ?><?= $line['marime'] !== '' ? ' <small>' . e($line['marime']) . '</small>' : '' ?></strong>
                                                        <span><?= e($line['categorie']) ?><?= $line['grupa'] !== '' && mb_strtolower($line['grupa']) !== mb_strtolower($line['categorie']) ? ' / ' . e($line['grupa']) : '' ?><?php if ($line['de_inlocuit']): ?> · <em class="dcv-flag"><?= e($line['status']) ?></em><?php endif; ?></span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="is-num"><?= e((string) $line['cantitate']) ?> buc.</td>
                                            <td class="is-num"><?= $line['pret'] !== null ? e($money($line['pret'])) : '<span class="dcv-missing" title="Costul nu a fost completat la predare">preț lipsă</span>' ?></td>
                                            <td class="is-num"><strong><?= $line['pret'] !== null ? e($money($line['valoare'])) : '—' ?></strong></td>
                                            <td class="is-act">
                                                <?php if (isset($rawItems[$line['id']])): ?>
                                                    <?php $item = $rawItems[$line['id']]; $driver = $sourceRow; include __DIR__ . '/_item_menu.php'; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="dcv-empty is-items" data-dcv-items-empty hidden>
                                <span data-dcv-items-empty-text>Nu are echipamente alocate.</span>
                                <?php if (!empty($canManage)): ?>
                                    <button class="des-btn des-btn-sm" type="button" data-des-open="handover" data-dcv-handover>
                                        <i class="bi bi-plus-lg" aria-hidden="true"></i>Predă echipament
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </article>

    <?php // Cardurile de inventar sunt toate cu fețe: „În stoc”, „De returnat”, „De înlocuit”. ?>
    <?php include __DIR__ . '/_stock_morph.php'; ?>
    <?php include __DIR__ . '/_return_morph.php'; ?>
    <?php include __DIR__ . '/_replace_morph.php'; ?>

    <script type="application/json" data-dcv-data><?= json_encode([
        'owners' => $jsOwners,
        'anchors' => $silhouetteAnchors,
        'role' => $role,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</section>
<script src="<?= e(url('assets/js/echipamente-valoare.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/echipamente-valoare.js'))) ?>" defer></script>
