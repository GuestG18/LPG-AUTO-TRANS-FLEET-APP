<?php
/**
 * Cardul „În stoc” — al doilea card cu fețe de lângă „Valoare echipamente alocate”:
 *   1 În stoc (total)  -->  2 Distribuție stoc (pe categorii)  -->  3 Detaliu stoc (articole, linii)
 * Selectarea unui articol în nivelul 3 doar filtrează conținutul (nu e un al patrulea nivel).
 *
 * Datele: DriverEquipmentModel::buildStockOverview() = rândurile paginii Stoc echipamente
 * (aceleași rezervări, praguri, statusuri și valori). Stocul e comun Șoferi + TESA.
 *
 * Așteaptă (din _value_morph.php): $stockOverview, $money, $plural, $findAsset, $anchorFor,
 * $role, $canManage, $canStock.
 */

$stock = is_array($stockOverview ?? null) ? $stockOverview : ['categories' => [], 'totals' => []];
$sCats = $stock['categories'];
$sTotals = ($stock['totals'] ?? []) + ['disponibil' => 0, 'rezervat' => 0, 'valoare' => 0.0, 'articole' => 0, 'pret_lipsa' => 0];
$stockPageUrl = build_query_url(['page' => 'echipamente_soferi', 'action' => 'stoc']);
$canStock = $canStock ?? (!function_exists('can') || can('echipamente_soferi', 'manage_stock'));

/** Imaginea categoriei: fotografie din categorii/{slug} sau silueta mărită pe zona ei. */
$stockVisual = static function (array $category, string $class) use ($findAsset, $anchorFor, $role, $img): string {
    $photo = $findAsset('categorii/' . $category['key']);
    if ($photo !== null) {
        return '<span class="' . e($class) . ' is-photo"><img src="' . e($photo) . '" alt="" loading="lazy"></span>';
    }
    if ($category['anchor'] === 'alte') {
        return '<span class="' . e($class) . ' is-icon"><i class="bi ' . e((string) $category['icon']) . '"></i></span>';
    }
    // Aceeași tehnică ca miniaturile din Catalog: pânza 360×860, ancora în centrul casetei.
    $anchor = $anchorFor($role, 'm', (string) $category['anchor']);
    $zoom = 2.6;
    $zh = $zoom * 860 / 360;
    $px = max(0, min(100, ($anchor[0] / 100 * $zoom - .5) / ($zoom - 1) * 100));
    $py = max(0, min(100, ($anchor[1] / 100 * $zh - .5) / ($zh - 1) * 100));

    return '<span class="' . e($class) . ' is-crop" style="' . e(sprintf('background-image:url("%s");--dcs-zoom:%s%%;--dcs-px:%s%%;--dcs-py:%s%%', $img($role, 'm'), $zoom * 100, round($px, 2), round($py, 2))) . '"></span>';
};

$thresholdLabel = static function (array $thresholds): string {
    if ($thresholds === []) {
        return '—';
    }
    if (count($thresholds) === 1) {
        return format_number_ro($thresholds[0], 0) . ' buc.';
    }

    return format_number_ro(min($thresholds), 0) . '–' . format_number_ro(max($thresholds), 0) . ' buc.';
};
?>
<article class="dcs-card" data-dcs-card data-dcs-level="1" data-ws-card="stock" aria-label="În stoc">

    <!-- ------------------------------------------------- Nivel 1: În stoc -->
    <button class="dcs-face dcs-l1" type="button" data-dcs-face="1" data-dcs-go="2" title="Vezi distribuția stocului pe categorii">
        <span class="dcs-l1-top">
            <span class="dcs-ico" aria-hidden="true"><i class="bi bi-archive"></i></span>
            <strong>În stoc</strong>
            <i class="bi bi-chevron-right dcs-go" aria-hidden="true"></i>
        </span>
        <b class="dcs-qty"><?= e(format_number_ro($sTotals['disponibil'], 0)) ?> <small>buc.</small></b>
        <small class="dcs-sub"><?= e($plural((int) $sTotals['articole'], 'articol disponibil', 'articole disponibile')) ?></small>
        <small class="dcs-desc">Bucăți libere în stocul comun șoferi + TESA. Click pentru categorii.</small>
        <span class="dcs-val"><?= e($money($sTotals['valoare'])) ?><?= (int) $sTotals['pret_lipsa'] > 0 ? ' *' : '' ?></span>
    </button>

    <!-- -------------------------------------- Nivel 2: Distribuție stoc -->
    <div class="dcs-face dcs-l2" data-dcs-face="2" hidden>
        <header class="dcv-face-head">
            <button class="dcv-back" type="button" data-dcs-go="1" aria-label="Înapoi la În stoc"><i class="bi bi-arrow-left"></i></button>
            <div>
                <h2>Distribuție stoc</h2>
                <p>Stoc comun șoferi + TESA, pe categorii din catalog.</p>
            </div>
        </header>

        <label class="dcv-search dcs-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <span class="visually-hidden">Caută categorie, gestiune, articol</span>
            <input type="search" placeholder="Caută categorie, gestiune, articol..." data-dcs-search autocomplete="off">
        </label>

        <div class="dcs-list-head">
            <span>Categorie</span>
            <span class="is-num">Articole</span>
            <span class="is-num">Disponibil</span>
            <span class="is-num">Valoare stoc</span>
            <span></span>
        </div>
        <div class="dcs-list" data-dcs-list>
            <?php foreach ($sCats as $category): ?>
                <?php
                $haystack = [$category['name']];
                foreach ($category['articole'] as $article) {
                    $haystack[] = $article['denumire'];
                    foreach ($article['linii'] as $line) {
                        $haystack[] = $line['locatie'] . ' ' . $line['marime'];
                    }
                }
                ?>
                <button class="dcs-row" type="button" data-dcs-cat="<?= e($category['key']) ?>" data-name="<?= e(mb_strtolower(implode(' ', $haystack))) ?>">
                    <span class="dcs-row-name">
                        <span class="dcs-row-ico" aria-hidden="true"><i class="bi <?= e($category['icon']) ?>"></i></span>
                        <span><?= e($category['name']) ?></span>
                    </span>
                    <span class="is-num"><?= e((string) count($category['articole'])) ?></span>
                    <span class="is-num"><strong><?= e(format_number_ro($category['disponibil'], 0)) ?></strong> buc.</span>
                    <span class="is-num is-money"><?= e($money($category['valoare'])) ?><?php if ($category['pret_lipsa'] > 0): ?><i class="bi bi-exclamation-circle dcv-miss" title="Linii fără preț — valoare incompletă"></i><?php endif; ?></span>
                    <i class="bi bi-chevron-right dcv-person-go" aria-hidden="true"></i>
                </button>
            <?php endforeach; ?>
            <p class="dcv-empty" data-dcs-list-empty <?= $sCats === [] ? '' : 'hidden' ?>>
                <?= $sCats === [] ? 'Nu există articole în stoc.' : 'Niciun rezultat pentru căutare.' ?>
            </p>
        </div>
        <a class="dcs-foot-link" href="<?= e($stockPageUrl) ?>">Deschide Stoc echipamente <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>

    <!-- ----------------------------------------- Nivel 3: Detaliu stoc -->
    <div class="dcs-face dcs-l3" data-dcs-face="3" hidden>
        <header class="dcv-crumbs">
            <button class="dcv-back" type="button" data-dcs-go="2" aria-label="Înapoi la categorii"><i class="bi bi-arrow-left"></i></button>
            <nav aria-label="Navigare">
                <button type="button" data-dcs-go="1">În stoc</button>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <button type="button" data-dcs-go="2">Categorii</button>
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                <strong data-dcs-crumb>—</strong>
            </nav>
        </header>

        <?php foreach ($sCats as $category): ?>
            <section class="dcs-detail" data-dcs-detail="<?= e($category['key']) ?>" hidden>
                <div class="dcs-head">
                    <span class="dcs-head-ico" aria-hidden="true"><i class="bi <?= e($category['icon']) ?>"></i></span>
                    <h2><?= e($category['name']) ?></h2>
                    <div class="dcv-chips">
                        <span class="dcv-chip"><i class="bi bi-box" aria-hidden="true"></i><?= e($plural(count($category['articole']), 'tip de articol', 'tipuri de articole')) ?></span>
                        <span class="dcv-chip is-green"><i class="bi bi-archive" aria-hidden="true"></i><?= e(format_number_ro($category['disponibil'], 0)) ?> buc. disponibile</span>
                        <span class="dcv-chip is-blue"><i class="bi bi-stack" aria-hidden="true"></i><b><?= e($money($category['valoare'])) ?></b></span>
                    </div>
                </div>

                <div class="dcs-body">
                    <!-- Ilustrația + selectorul vertical de articole -->
                    <div class="dcs-visual">
                        <div class="dcs-stage">
                            <?= $stockVisual($category, 'dcs-art') ?>
                            <span class="dcv-platform" aria-hidden="true"></span>
                        </div>
                        <div class="dcs-picker" role="listbox" aria-label="Articole">
                            <?php foreach ($category['articole'] as $article): ?>
                                <button class="dcs-pick" type="button" role="option" data-dcs-article="<?= e((string) $article['id']) ?>" title="<?= e($article['denumire']) ?>">
                                    <i class="bi <?= e($category['icon']) ?>" aria-hidden="true"></i>
                                    <span><?= e(mb_strtoupper(mb_substr((string) preg_replace('/[^\p{L}\p{N}]+/u', '', (string) preg_replace('/^\[[^\]]*\]\s*/u', '', $article['denumire'])), 0, 2))) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="dcs-main">
                        <!-- Indicatorii categoriei (sau ai articolului selectat) -->
                        <?php
                        $metricSets = ['' => [
                            'disponibil' => $category['disponibil'], 'rezervat' => $category['rezervat'],
                            'prag' => $thresholdLabel($category['praguri']), 'valoare' => $category['valoare'],
                            'tipuri' => count($category['articole']), 'sub_prag' => $category['sub_prag'],
                        ]];
                        foreach ($category['articole'] as $article) {
                            $under = 0;
                            foreach ($article['linii'] as $line) {
                                $under += $line['status']['key'] !== 'in_stoc' ? 1 : 0;
                            }
                            $metricSets[(string) $article['id']] = [
                                'disponibil' => $article['disponibil'], 'rezervat' => $article['rezervat'],
                                'prag' => $thresholdLabel($article['praguri']), 'valoare' => $article['valoare'],
                                'tipuri' => count($article['linii']), 'sub_prag' => $under,
                            ];
                        }
                        ?>
                        <?php foreach ($metricSets as $setKey => $set): ?>
                            <div class="dcs-metrics" data-dcs-metrics="<?= e((string) $setKey) ?>" <?= $setKey === '' ? '' : 'hidden' ?>>
                                <div class="dcs-metric is-green"><span>Disponibil</span><b><?= e(format_number_ro($set['disponibil'], 0)) ?></b><small>buc.</small></div>
                                <div class="dcs-metric is-blue"><span>Rezervat</span><b><?= e(format_number_ro($set['rezervat'], 0)) ?></b><small>buc.</small></div>
                                <div class="dcs-metric is-red" title="Pragurile se configurează pe articol / mărime"><span>Prag minim</span><b><?= e($set['prag']) ?></b><small><?= $set['sub_prag'] > 0 ? e($plural((int) $set['sub_prag'], 'linie sub prag', 'linii sub prag')) : 'nicio linie sub prag' ?></small></div>
                                <div class="dcs-metric is-money"><span>Valoare stoc</span><b><?= e($money($set['valoare'])) ?></b><small>disponibil × preț</small></div>
                                <div class="dcs-metric"><span><?= $setKey === '' ? 'Tipuri de articole' : 'Linii de stoc' ?></span><b><?= e((string) $set['tipuri']) ?></b><small><?= $setKey === '' ? 'în categorie' : 'gestiune / mărime' ?></small></div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Selectorul orizontal de articole -->
                        <div class="dcs-articles" role="listbox" aria-label="Articole din categorie">
                            <?php foreach ($category['articole'] as $article): ?>
                                <button class="dcs-article" type="button" role="option" data-dcs-article="<?= e((string) $article['id']) ?>">
                                    <span class="dcs-article-ico" aria-hidden="true"><i class="bi <?= e($category['icon']) ?>"></i></span>
                                    <span class="dcs-article-text">
                                        <strong><?= e($article['denumire']) ?></strong>
                                        <small><?= e(format_number_ro($article['disponibil'], 0)) ?> buc. · <?= e($money($article['valoare'])) ?></small>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <!-- Tabelul detaliat -->
                        <div class="dcs-table-box">
                            <table class="dcv-table dcs-table">
                                <thead>
                                    <tr>
                                        <th>Articol</th>
                                        <th>Gestiune / Locație</th>
                                        <th>Mărime</th>
                                        <th class="is-num">Disponibil</th>
                                        <th class="is-num">Rezervat</th>
                                        <th class="is-num">Prag minim</th>
                                        <th class="is-num">Preț unitar</th>
                                        <th class="is-num">Valoare totală</th>
                                        <th>Status</th>
                                        <th class="is-act"><span class="visually-hidden">Acțiuni</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($category['articole'] as $article): ?>
                                    <?php foreach ($article['linii'] as $line): ?>
                                        <tr class="dcs-line" data-dcs-line="<?= e((string) $article['id']) ?>">
                                            <td>
                                                <div class="dcv-item-name">
                                                    <span class="dcv-item-ico" aria-hidden="true"><i class="bi <?= e($category['icon']) ?>"></i></span>
                                                    <strong><?= e($article['denumire']) ?></strong>
                                                </div>
                                            </td>
                                            <td><?= e($line['locatie']) ?></td>
                                            <td><?= $line['marime'] !== '' ? '<span class="dcx-size">' . e($line['marime']) . '</span>' : '—' ?></td>
                                            <td class="is-num"><strong><?= e(format_number_ro($line['disponibil'], 0)) ?></strong></td>
                                            <td class="is-num"><?= e(format_number_ro($line['rezervat'], 0)) ?></td>
                                            <td class="is-num"><?= e(format_number_ro($line['prag'], 0)) ?></td>
                                            <td class="is-num"><?= $line['abonament'] ? '<span class="dcv-missing">abonament</span>' : ($line['pret'] !== null ? e($money($line['pret'])) : '<span class="dcv-missing">preț lipsă</span>') ?></td>
                                            <td class="is-num"><strong><?= $line['valoare'] !== null ? e($money($line['valoare'])) : '—' ?></strong></td>
                                            <td><span class="des-status is-<?= e((string) $line['status']['tone']) ?>"><?= e((string) $line['status']['label']) ?></span></td>
                                            <td class="is-act">
                                                <div class="dropdown" data-des-stop>
                                                    <button class="des-menu-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Acțiuni articol">
                                                        <i class="bi bi-three-dots" aria-hidden="true"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <?php if (!empty($canManage)): ?>
                                                            <li>
                                                                <button class="dropdown-item" type="button" data-des-open="handover" data-des-catalog="<?= e((string) $article['id']) ?>" data-des-denumire="<?= e($article['denumire']) ?>">
                                                                    <i class="bi bi-person-plus"></i>Predă din stoc
                                                                </button>
                                                            </li>
                                                        <?php endif; ?>
                                                        <li>
                                                            <a class="dropdown-item" href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'stoc', 'q' => $article['denumire']])) ?>">
                                                                <i class="bi bi-boxes"></i><?= !empty($canStock) ? 'Intrare / ieșire stoc' : 'Vezi în stoc' ?>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</article>
