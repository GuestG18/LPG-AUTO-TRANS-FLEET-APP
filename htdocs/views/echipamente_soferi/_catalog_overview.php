<?php
/**
 * Catalog echipamente — vederea pe siluetă (implicită), vederea pe categorii,
 * tabelul complet (vechiul ecran) și spațiul de lucru al unei categorii.
 *
 * Toate cifrele vin din DriverEquipmentModel::buildCatalogOverview(): categoriile
 * sunt cele reale din catalog, iar valoarea este valoarea STOCULUI (disponibil ×
 * cost linie de stoc), nu costul echipamentului alocat unei persoane.
 *
 * Variabile: $catalogOverview, $catalogItems, $catalogBreakdown, $canCatalog,
 * $money, $dash, $yes (din catalog.php). Tabelul complet se include din
 * _catalog_table.php, neschimbat ca funcționalitate.
 */

$overview = is_array($catalogOverview ?? null) ? $catalogOverview : ['categories' => [], 'items' => [], 'stages' => [], 'totals' => []];
$ovCategories = $overview['categories'];
$ovItems = $overview['items'];
$ovStages = $overview['stages'];
$ovTotals = $overview['totals'] + ['articole' => 0, 'stoc' => 0, 'alocat' => 0, 'valoare' => 0.0];
$itemsById = [];
foreach ($catalogItems as $catalogItem) {
    $itemsById[(int) $catalogItem['id']] = $catalogItem;
}

// Siluetele + punctele de pe corp: comune cu cardul de valoare (Echipamente șoferi / TESA).
include __DIR__ . '/_silhouette_assets.php';

/**
 * Miniatura unei categorii = silueta mărită pe zona ei (casca, pieptul, bocancii…).
 * Procentele aliniază punctul de ancorare în centrul casetei (vezi CSS .dcx-thumb).
 */
$thumbStyle = static function (array $anchor, float $zoom = 3.0): string {
    $ax = $anchor[0] / 100;
    $ay = $anchor[1] / 100;
    $zh = $zoom * 860 / 360;
    $px = ($ax * $zoom - .5) / ($zoom - 1) * 100;
    $py = ($ay * $zh - .5) / ($zh - 1) * 100;

    return sprintf('--dcx-zoom:%s%%;--dcx-px:%s%%;--dcx-py:%s%%', $zoom * 100, round(max(0, min(100, $px)), 2), round(max(0, min(100, $py)), 2));
};
$categoryThumb = static function (array $category, string $role = 'sofer', string $variant = 'm') use ($thumbStyle, $findAsset, $anchorFor): string {
    $photo = $findAsset('categorii/' . $category['key']);
    if ($photo !== null) {
        return '<span class="dcx-thumb is-photo" aria-hidden="true"><img src="' . e($photo) . '" alt="" loading="lazy"></span>';
    }
    // Imaginile (SVG sau decupaje) au aceeași pânză 360:860, deci ancora e valabilă și pentru miniatură.
    $anchor = $anchorFor($role, $variant, (string) $category['anchor']);
    if ($category['anchor'] === 'alte') {
        return '<span class="dcx-thumb is-icon" aria-hidden="true"><i class="bi ' . e((string) $category['icon']) . '"></i></span>';
    }

    return '<span class="dcx-thumb" data-role="' . e($role) . '" style="' . e($thumbStyle($anchor)) . '" aria-hidden="true"></span>';
};

$roles = [
    // „default” = varianta cu care se deschide pagina (cea din machetă).
    'sofer' => ['title' => 'Șofer', 'footer' => 'șofer', 'icon' => 'bi-truck-front', 'default' => 'm'],
    'tesa' => ['title' => 'TESA', 'footer' => 'TESA', 'icon' => 'bi-person', 'default' => 'f'],
];

$plural = static fn(int $count, string $one, string $many): string => $count . ' ' . ($count === 1 ? $one : $many);

// Datele pentru JS: KPI-urile spațiului de lucru se recalculează la filtrul Șofer/TESA.
$jsCategories = [];
foreach ($ovCategories as $key => $category) {
    $jsCategories[$key] = [
        'name' => $category['name'],
        'anchor' => $category['anchor'],
        'roles' => array_keys($category['roles']),
    ];
}
$jsItems = array_values(array_map(static fn(array $row): array => [
    'id' => $row['id'],
    'cat' => $row['categorie'],
    'dest' => $row['destinatie'],
    'stoc' => $row['stoc'],
    'alocat' => $row['alocat'],
    'valoare' => round($row['valoare'], 2),
], $ovItems));
?>
<?php // --dcx-img-sofer / --dcx-img-tesa (miniaturile) le setează JS-ul cu URL-ul absolut al siluetei active. ?>
<div class="dcx" data-dcx data-dcx-view="silueta">

    <!-- Navigare: moduri de afișare + categorii -->
    <aside class="dcx-nav" aria-label="Navigare catalog">
        <div class="dcx-nav-views" role="tablist" aria-label="Mod de afișare">
            <button class="dcx-nav-view is-active" type="button" role="tab" aria-selected="true" data-dcx-view-btn="silueta">
                <i class="bi bi-person-arms-up" aria-hidden="true"></i>Vedere siluetă
            </button>
            <button class="dcx-nav-view" type="button" role="tab" aria-selected="false" data-dcx-view-btn="categorii">
                <i class="bi bi-grid" aria-hidden="true"></i>Vedere categorii
            </button>
            <button class="dcx-nav-view" type="button" role="tab" aria-selected="false" data-dcx-view-btn="tabel">
                <i class="bi bi-table" aria-hidden="true"></i>Tabel complet
            </button>
        </div>

        <div class="dcx-nav-list">
            <button class="dcx-nav-cat" type="button" data-dcx-open="*">
                <span class="dcx-nav-ico"><i class="bi bi-stack" aria-hidden="true"></i></span>
                <span class="dcx-nav-name">Toate categoriile</span>
                <span class="dcx-nav-count"><?= e((string) $ovTotals['articole']) ?></span>
            </button>
            <?php foreach ($ovCategories as $key => $category): ?>
                <button class="dcx-nav-cat" type="button" data-dcx-open="<?= e((string) $key) ?>">
                    <?= $categoryThumb($category) ?>
                    <span class="dcx-nav-name"><?= e((string) $category['name']) ?></span>
                    <span class="dcx-nav-count"><?= e((string) $category['total']['articole']) ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <dl class="dcx-nav-totals">
            <div><dt>Bucăți în stoc</dt><dd><?= e(format_number_ro($ovTotals['stoc'], 0)) ?></dd></div>
            <div><dt>Alocate</dt><dd><?= e(format_number_ro($ovTotals['alocat'], 0)) ?></dd></div>
            <div><dt>Valoare stoc</dt><dd><?= e($money($ovTotals['valoare'])) ?></dd></div>
        </dl>
    </aside>

    <div class="dcx-main">

        <!-- 1. Vedere siluetă: Șofer și TESA alăturate -->
        <section class="dcx-pane dcx-stages" data-dcx-pane="silueta" aria-label="Echipamente pe siluetă">
            <?php foreach ($roles as $role => $meta): ?>
                <?php
                $stage = $ovStages[$role] ?? ['cards' => [], 'overflow' => []];
                $roleTotals = ['articole' => 0, 'stoc' => 0, 'alocat' => 0, 'valoare' => 0.0];
                foreach ($ovCategories as $category) {
                    foreach ($roleTotals as $metric => $value) {
                        $roleTotals[$metric] += $category['roles'][$role][$metric] ?? 0;
                    }
                }
                ?>
                <?php $dv = $meta['default']; ?>
                <article class="dcx-stage" data-dcx-stage="<?= e($role) ?>" data-variant="<?= e($dv) ?>" data-default-variant="<?= e($dv) ?>">
                    <header class="dcx-stage-head">
                        <span class="dcx-stage-ico" aria-hidden="true"><i class="bi <?= e($meta['icon']) ?>"></i></span>
                        <h2>Echipare <?= e($meta['title']) ?></h2>
                        <div class="dcx-seg is-head" role="radiogroup" aria-label="Siluetă <?= e($meta['title']) ?>">
                            <button type="button" role="radio" aria-checked="<?= $dv === 'm' ? 'true' : 'false' ?>" class="<?= $dv === 'm' ? 'is-active' : '' ?>" data-dcx-variant="m" title="Masculin"><i class="bi bi-gender-male" aria-hidden="true"></i><span>Masculin</span></button>
                            <button type="button" role="radio" aria-checked="<?= $dv === 'f' ? 'true' : 'false' ?>" class="<?= $dv === 'f' ? 'is-active' : '' ?>" data-dcx-variant="f" title="Feminin"><i class="bi bi-gender-female" aria-hidden="true"></i><span>Feminin</span></button>
                        </div>
                        <span class="dcx-stat" title="Articole din catalog cu destinația <?= e($meta['title']) ?> sau Ambele">
                            <i class="bi bi-box" aria-hidden="true"></i><?= e($plural((int) $roleTotals['articole'], 'articol', 'articole')) ?>
                        </span>
                        <span class="dcx-stat is-money" title="Valoarea stocului disponibil">
                            <i class="bi bi-stack" aria-hidden="true"></i><?= e($money($roleTotals['valoare'])) ?>
                        </span>
                    </header>

                    <div class="dcx-stage-body">
                        <svg class="dcx-links" aria-hidden="true" focusable="false"></svg>


                        <div class="dcx-figure">
                            <span class="dcx-platform" aria-hidden="true"></span>
                            <img class="dcx-figure-img <?= $dv === 'm' ? 'is-active' : '' ?>" data-variant="m" src="<?= e($img($role, 'm')) ?>" alt="Siluetă <?= e($meta['title']) ?> — masculin" draggable="false">
                            <img class="dcx-figure-img <?= $dv === 'f' ? 'is-active' : '' ?>" data-variant="f" src="<?= e($img($role, 'f')) ?>" alt="Siluetă <?= e($meta['title']) ?> — feminin" draggable="false">
                            <?php foreach ($stage['cards'] as $card): ?>
                                <?php
                                $zone = (string) $ovCategories[$card['key']]['anchor'];
                                $am = $anchorFor($role, 'm', $zone);
                                $af = $anchorFor($role, 'f', $zone);
                                ?>
                                <button class="dcx-dot" type="button" tabindex="-1"
                                        data-dcx-open="<?= e((string) $card['key']) ?>" data-dcx-key="<?= e((string) $card['key']) ?>"
                                        data-at-m="<?= e($am[0] . ',' . $am[1]) ?>" data-at-f="<?= e($af[0] . ',' . $af[1]) ?>"
                                        style="left:<?= e((string) ($dv === 'f' ? $af : $am)[0]) ?>%;top:<?= e((string) ($dv === 'f' ? $af : $am)[1]) ?>%"
                                        aria-label="<?= e((string) $ovCategories[$card['key']]['name']) ?>"></button>
                            <?php endforeach; ?>
                        </div>

                        <?php foreach ($stage['cards'] as $card): ?>
                            <?php
                            $category = $ovCategories[$card['key']];
                            $stats = $category['roles'][$role];
                            ?>
                            <button class="dcx-card" type="button" data-slot="<?= e((string) $card['slot']) ?>"
                                    data-dcx-open="<?= e((string) $card['key']) ?>" data-dcx-key="<?= e((string) $card['key']) ?>"
                                    data-dcx-role="<?= e($role) ?>"
                                    title="<?= e($plural((int) $stats['articole'], 'articol', 'articole')) ?> · <?= e(format_number_ro($stats['alocat'], 0)) ?> buc. alocate">
                                <?= $categoryThumb($category, $role, $dv) ?>
                                <span class="dcx-card-text">
                                    <strong><?= e((string) $category['name']) ?></strong>
                                    <span><?= e(format_number_ro($stats['stoc'], 0)) ?> buc. în stoc</span>
                                    <b><?= e($money($stats['valoare'])) ?></b>
                                </span>
                            </button>
                        <?php endforeach; ?>

                        <?php if ($stage['cards'] === []): ?>
                            <p class="dcx-stage-empty">
                                Niciun articol din catalog nu are destinația <?= e($meta['title']) ?> sau Ambele.
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if ($stage['overflow'] !== []): ?>
                        <div class="dcx-overflow">
                            <span>Alte categorii:</span>
                            <?php foreach ($stage['overflow'] as $key): ?>
                                <button type="button" class="dcx-chip" data-dcx-open="<?= e((string) $key) ?>" data-dcx-role="<?= e($role) ?>">
                                    <?= e((string) $ovCategories[$key]['name']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <button class="dcx-stage-foot" type="button" data-dcx-open="*" data-dcx-role="<?= e($role) ?>">
                        <i class="bi <?= e($meta['icon']) ?> dcx-foot-person" aria-hidden="true"></i>
                        <span class="dcx-foot-label">
                            <strong>Total echipare <?= e($meta['footer']) ?></strong>
                            <small><?= e($plural((int) $roleTotals['articole'], 'articol', 'articole')) ?> · <?= e(format_number_ro($roleTotals['stoc'], 0)) ?> buc. în stoc · <?= e(format_number_ro($roleTotals['alocat'], 0)) ?> alocate</small>
                        </span>
                        <i class="bi bi-stack dcx-foot-ico" aria-hidden="true"></i>
                        <b><?= e($money($roleTotals['valoare'])) ?></b>
                        <i class="bi bi-chevron-right dcx-foot-go" aria-hidden="true"></i>
                    </button>
                </article>
            <?php endforeach; ?>
        </section>

        <!-- 2. Vedere categorii -->
        <section class="dcx-pane dcx-grid" data-dcx-pane="categorii" hidden aria-label="Categorii">
            <?php if ($ovCategories === []): ?>
                <p class="dcx-stage-empty">Catalogul este gol. Adaugă primul articol.</p>
            <?php endif; ?>
            <?php foreach ($ovCategories as $key => $category): ?>
                <button class="dcx-card is-grid" type="button" data-dcx-open="<?= e((string) $key) ?>" data-dcx-key="<?= e((string) $key) ?>">
                    <?= $categoryThumb($category) ?>
                    <span class="dcx-card-text">
                        <strong><?= e((string) $category['name']) ?></strong>
                        <span><?= e($plural((int) $category['total']['articole'], 'articol', 'articole')) ?> · <?= e(format_number_ro($category['total']['stoc'], 0)) ?> buc. în stoc · <?= e(format_number_ro($category['total']['alocat'], 0)) ?> alocate</span>
                        <b><?= e($money($category['total']['valoare'])) ?></b>
                    </span>
                    <i class="bi bi-chevron-right dcx-card-go" aria-hidden="true"></i>
                </button>
            <?php endforeach; ?>
        </section>

        <!-- 3. Tabelul complet (ecranul existent, toate coloanele și acțiunile) -->
        <section class="dcx-pane" data-dcx-pane="tabel" hidden aria-label="Tabel complet">
            <?php include __DIR__ . '/_catalog_table.php'; ?>
        </section>

        <!-- 4. Spațiul de lucru al unei categorii (înlocuiește complet vederea curentă) -->
        <section class="dcx-ws" data-dcx-ws hidden aria-labelledby="dcxWsTitle" tabindex="-1">
            <header class="dcx-ws-head">
                <span class="dcx-ws-thumb" data-dcx-ws-thumb aria-hidden="true"></span>
                <div class="dcx-ws-title">
                    <h2 id="dcxWsTitle" data-dcx-ws-name>—</h2>
                    <p data-dcx-ws-desc>Articolele din catalog și stocul lor.</p>
                </div>
                <div class="dcx-seg is-small" role="radiogroup" aria-label="Destinație">
                    <button type="button" role="radio" aria-checked="true" class="is-active" data-dcx-ws-role="">Toate</button>
                    <button type="button" role="radio" aria-checked="false" data-dcx-ws-role="sofer">Șofer</button>
                    <button type="button" role="radio" aria-checked="false" data-dcx-ws-role="tesa">TESA</button>
                </div>
                <button class="dcx-ws-close" type="button" data-dcx-close aria-label="Închide categoria">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </header>

            <div class="dcx-kpis">
                <div class="dcx-kpi"><i class="bi bi-box" aria-hidden="true"></i><strong data-dcx-kpi="articole">0</strong><span>articole</span></div>
                <div class="dcx-kpi"><i class="bi bi-stack" aria-hidden="true"></i><strong data-dcx-kpi="stoc">0</strong><span>buc. în stoc</span></div>
                <div class="dcx-kpi"><i class="bi bi-person-check" aria-hidden="true"></i><strong data-dcx-kpi="alocat">0</strong><span>buc. alocate</span></div>
                <div class="dcx-kpi is-money"><i class="bi bi-coin" aria-hidden="true"></i><strong data-dcx-kpi="valoare">0,00 lei</strong><span>valoare stoc</span></div>
            </div>

            <div class="dcx-ws-scroll" data-dcx-ws-scroll>
                <table class="dcx-table">
                    <thead>
                        <tr>
                            <th>Articol</th>
                            <th>Mărimi / Variante</th>
                            <th class="is-num">În stoc</th>
                            <th class="is-num">Alocate</th>
                            <th class="is-num">Preț / buc.</th>
                            <th class="is-num">Valoare stoc</th>
                            <th>Status</th>
                            <th class="is-actions"><span class="visually-hidden">Acțiuni</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ovItems as $id => $row): ?>
                        <?php
                        $item = $itemsById[$id];
                        $category = $ovCategories[$row['categorie']];
                        $breakdown = $catalogBreakdown[$id] ?? ['stoc' => [], 'unitati' => [], 'alocari' => [], 'total_stoc' => 0, 'total_alocat' => 0];
                        $panelId = 'dcx-ws-panel-' . $id;
                        ?>
                        <tr class="dcx-row" data-dcx-row="<?= e($panelId) ?>" data-cat="<?= e((string) $row['categorie']) ?>" data-dest="<?= e((string) $row['destinatie']) ?>" tabindex="0" aria-expanded="false" aria-controls="<?= e($panelId) ?>">
                            <td>
                                <div class="dcx-item">
                                    <?= $categoryThumb($category, $row['destinatie'] === 'tesa' ? 'tesa' : 'sofer') ?>
                                    <div>
                                        <strong><?= e((string) $item['denumire']) ?></strong>
                                        <span>#<?= e((string) $id) ?> · <?= e(DriverEquipmentModel::DESTINATIONS[(string) $row['destinatie']] ?? 'Ambele') ?> · <?= e(DriverEquipmentModel::LOGIC_TYPES[(string) $item['tip_logic']] ?? (string) $item['tip_logic']) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="dcx-sizes">
                                    <?php if ($row['marimi'] === []): ?>
                                        <span class="dcx-size is-empty">—</span>
                                    <?php endif; ?>
                                    <?php foreach ($row['marimi'] as $size => $qty): ?>
                                        <span class="dcx-size <?= $qty > 0 ? '' : 'is-empty' ?>" title="<?= e((string) $qty) ?> buc. disponibile"><?= e((string) $size) ?><?php if (count($row['marimi']) > 1): ?><small><?= e((string) $qty) ?></small><?php endif; ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="is-num"><strong><?= e(format_number_ro($row['stoc'], 0)) ?></strong></td>
                            <td class="is-num"><?= e(format_number_ro($row['alocat'], 0)) ?></td>
                            <td class="is-num"><?= $row['pret'] !== null ? e($money($row['pret'])) : '<span class="dcx-missing" title="Costul nu este completat în catalog">nesetat</span>' ?></td>
                            <td class="is-num"><strong><?= e($money($row['valoare'])) ?></strong></td>
                            <td><span class="des-status is-<?= e((string) $row['status']['tone']) ?>"><?= e((string) $row['status']['label']) ?></span></td>
                            <td class="is-actions">
                                <button class="dcx-row-toggle" type="button" data-dcx-row-toggle aria-label="Detalii stoc și alocări">
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </button>
                                <?php if ($canCatalog): ?>
                                    <div class="dropdown" data-des-stop>
                                        <button class="des-menu-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Acțiuni articol">
                                            <i class="bi bi-three-dots" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <button class="dropdown-item" type="button" data-des-catalog-edit-ref="<?= e((string) $id) ?>">
                                                    <i class="bi bi-pencil"></i>Editează
                                                </button>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'stoc', 'q' => (string) $item['denumire']])) ?>">
                                                    <i class="bi bi-boxes"></i>Vezi în stoc
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'q' => (string) $item['denumire']])) ?>">
                                                    <i class="bi bi-people"></i>Vezi alocările
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button class="dropdown-item text-danger" type="button" data-des-catalog-delete="<?= e((string) $id) ?>" data-des-denumire="<?= e((string) $item['denumire']) ?>">
                                                    <i class="bi bi-trash3"></i>Șterge / dezactivează
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="dcx-row-detail" id="<?= e($panelId) ?>" data-cat="<?= e((string) $row['categorie']) ?>" data-dest="<?= e((string) $row['destinatie']) ?>" hidden>
                            <td colspan="8"><?php include __DIR__ . '/_catalog_panel.php'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="dcx-ws-empty" data-dcx-ws-empty hidden>
                        <td colspan="8">Niciun articol pentru filtrul ales.</td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <footer class="dcx-ws-foot">
                <div class="dcx-ws-links">
                    <a class="des-btn" data-dcx-link="alocari" href="<?= e(build_query_url(['page' => 'echipamente_soferi'])) ?>">
                        <i class="bi bi-people" aria-hidden="true"></i>Vezi toate alocările
                    </a>
                    <a class="des-btn" data-dcx-link="stoc" href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'stoc'])) ?>">
                        <i class="bi bi-boxes" aria-hidden="true"></i>Vezi în stoc
                    </a>
                    <a class="des-btn des-btn-primary" href="<?= e(build_query_url(['page' => 'echipamente_soferi'])) ?>">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>Alocă echipament
                    </a>
                </div>
                <div class="dcx-ws-total">
                    <span>Total categorie</span>
                    <strong data-dcx-kpi="valoare">0,00 lei</strong>
                </div>
            </footer>
        </section>
    </div>

    <script type="application/json" id="dcx-data"><?= json_encode([
        'categories' => $jsCategories,
        'items' => $jsItems,
        'stockUrl' => build_query_url(['page' => 'echipamente_soferi', 'action' => 'stoc']),
        'allocUrl' => build_query_url(['page' => 'echipamente_soferi']),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</div>
