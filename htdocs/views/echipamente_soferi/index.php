<?php
/**
 * Echipamente șoferi — ce are fiecare șofer + stocul nealocat.
 *
 * Structura paginii: antet, KPI, filtre, tabelul de șoferi (colapsabil, cu
 * panoul detaliat pe echipamente fizice / comunicații), secțiunea de stoc și
 * modalele de operare (predare, înlocuire din stoc, returnare, mișcări stoc).
 */

$filters = is_array($filters ?? null) ? $filters : [];
$kpis = is_array($kpis ?? null) ? $kpis : [];
$driverRows = is_array($driverRows ?? null) ? $driverRows : [];
$searchResults = is_array($searchResults ?? null) ? $searchResults : null;
$searchView = (string) ($searchView ?? 'echipamente');
$stockMap = is_array($stockMap ?? null) ? $stockMap : [];
$catalogItems = is_array($catalogItems ?? null) ? $catalogItems : [];
$driverOptions = is_array($driverOptions ?? null) ? $driverOptions : [];
$monthOptions = is_array($monthOptions ?? null) ? $monthOptions : [];
$categories = is_array($categories ?? null) ? $categories : DriverEquipmentModel::DEFAULT_CATEGORIES;

$canManage = !function_exists('can') || can('echipamente_soferi', 'manage_assignments');
$canStock = !function_exists('can') || can('echipamente_soferi', 'manage_stock');
$canCatalog = !function_exists('can') || can('echipamente_soferi', 'manage_catalog');
$canExport = !function_exists('can') || can('echipamente_soferi', 'export');

$money = static fn(mixed $value): string => format_number_ro((float) ($value ?? 0), 2) . ' lei';
$moneyShort = static fn(mixed $value): string => format_number_ro((float) ($value ?? 0), 0) . ' lei';
$dash = static fn(mixed $value): string => trim((string) ($value ?? '')) !== '' ? trim((string) $value) : '—';

$baseQuery = ['page' => 'echipamente_soferi'];
foreach (['luna', 'categorie', 'tip_activ', 'status', 'returnabil', 'q'] as $key) {
    if (trim((string) ($filters[$key] ?? '')) !== '') {
        $baseQuery[$key] = (string) $filters[$key];
    }
}
if ((int) ($filters['driver_id'] ?? 0) > 0) {
    $baseQuery['driver_id'] = (string) $filters['driver_id'];
}
$hasFilters = count($baseQuery) > 1;

$equippedPercent = (int) ($kpis['soferi_activi'] ?? 0) > 0
    ? (int) round(((int) ($kpis['soferi_echipati'] ?? 0) / (int) $kpis['soferi_activi']) * 100)
    : 0;

/** Iconița articolului, aleasă după categorie / logică — ajută scanarea rapidă. */
$itemIcon = static function (array $item): string {
    $name = mb_strtolower((string) ($item['denumire'] ?? ''));
    if (str_contains($name, 'sim')) {
        return 'bi-sim';
    }
    if (str_contains($name, 'telefon')) {
        return 'bi-phone';
    }
    if (str_contains($name, 'tablet')) {
        return 'bi-tablet';
    }
    if (str_contains($name, 'radio') || str_contains($name, 'stație') || str_contains($name, 'statie')) {
        return 'bi-broadcast-pin';
    }
    if (str_contains($name, 'card')) {
        return 'bi-credit-card-2-front';
    }
    if (str_contains($name, 'bocanc')) {
        return 'bi-boombox';
    }
    if (str_contains($name, 'cască') || str_contains($name, 'casca')) {
        return 'bi-shield';
    }
    if (str_contains($name, 'vestă') || str_contains($name, 'vesta')) {
        return 'bi-vignette';
    }
    if (str_contains($name, 'trusă') || str_contains($name, 'trusa') || str_contains($name, 'scule')) {
        return 'bi-tools';
    }

    return (string) ($item['grupa'] ?? 'fizic') === 'comunicatii' ? 'bi-broadcast' : 'bi-bag';
};

/** Atributele comune pe care le citesc modalele din butoanele de acțiune. */
$itemDataAttributes = static function (array $item, array $driver): string {
    $attributes = [
        'data-des-alocare' => (string) $item['id'],
        'data-des-catalog' => (string) $item['catalog_id'],
        'data-des-denumire' => (string) $item['denumire'],
        'data-des-sofer' => (string) $driver['nume'],
        'data-des-sofer-id' => (string) $driver['id'],
        'data-des-cantitate' => (string) $item['cantitate'],
        'data-des-marime' => (string) ($item['marime'] ?? ''),
        'data-des-returnabil' => (int) $item['returnabil'] === 1 ? '1' : '0',
        'data-des-stare' => DriverEquipmentModel::CONDITIONS[(string) $item['stare']] ?? '',
        'data-des-status' => DriverEquipmentModel::STATUSES[(string) $item['status']] ?? '',
    ];

    $html = '';
    foreach ($attributes as $name => $value) {
        $html .= ' ' . $name . '="' . e($value) . '"';
    }

    return $html;
};

$statusBadge = static function (array $status): string {
    return '<span class="des-status is-' . e((string) $status['tone']) . '">' . e((string) $status['label']) . '</span>';
};
?>
<link rel="stylesheet" href="<?= e(url('assets/css/echipamente-soferi.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/echipamente-soferi.css'))) ?>">

<div class="des-page" data-des-page data-des-search="<?= e((string) ($filters['q'] ?? '')) ?>" data-des-stock-url="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'stoc'])) ?>">

    <?php $activeTab = 'soferi'; include __DIR__ . '/_tabs.php'; ?>

    <!-- ------------------------------------------------------------ Antet -->
    <header class="des-header">
        <div>
            <h1>Echipamente șoferi</h1>
            <p>Gestionează echipamentele atribuite șoferilor și comunicările acestora.</p>
        </div>
        <div class="des-header-actions">
            <div class="des-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <label class="visually-hidden" for="desSearch">Caută șofer, echipament, serie</label>
                <input id="desSearch" form="desFiltersForm" type="search" name="q"
                       value="<?= e((string) ($filters['q'] ?? '')) ?>"
                       placeholder="Caută șofer, echipament, serie...">
            </div>
            <?php if ($canCatalog): ?>
                <a class="des-btn" href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'catalog'])) ?>">
                    <i class="bi bi-journal-text" aria-hidden="true"></i>Catalog echipamente
                </a>
            <?php endif; ?>
            <?php if ($canExport): ?>
                <a class="des-btn" href="<?= e(build_query_url(array_merge($baseQuery, ['action' => 'export']))) ?>">
                    <i class="bi bi-download" aria-hidden="true"></i>Export
                </a>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <button class="des-btn des-btn-primary" type="button" data-des-open="handover">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>Predă echipament
                </button>
            <?php endif; ?>
        </div>
    </header>

    <!-- -------------------------------------------------------------- KPI -->
    <section class="des-kpi-grid" aria-label="Indicatori echipamente">
        <article class="des-kpi">
            <div class="des-kpi-main">
                <span class="des-kpi-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                <span class="des-kpi-value">
                    <?= e((string) ($kpis['soferi_echipati'] ?? 0)) ?> <small>/ <?= e((string) ($kpis['soferi_activi'] ?? 0)) ?></small>
                </span>
                <span class="des-kpi-label">Șoferi echipați</span>
                <span class="des-kpi-note">din șoferii activi</span>
            </div>
            <div class="des-ring" style="background: conic-gradient(#16a34a <?= e((string) ($equippedPercent * 3.6)) ?>deg, #e8edf5 0deg); border-radius: 50%;">
                <span style="background: #fff; border-radius: 50%; inset: 7px;"><?= e((string) $equippedPercent) ?>%</span>
            </div>
        </article>

        <article class="des-kpi">
            <div class="des-kpi-main">
                <span class="des-kpi-icon is-violet"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                <span class="des-kpi-value"><?= e(format_number_ro((int) ($kpis['obiecte_active'] ?? 0), 0)) ?></span>
                <span class="des-kpi-label">Obiecte active</span>
                <span class="des-kpi-note">în prezent la șoferi</span>
            </div>
        </article>

        <article class="des-kpi">
            <div class="des-kpi-main">
                <span class="des-kpi-icon is-teal"><i class="bi bi-sim" aria-hidden="true"></i></span>
                <span class="des-kpi-value"><?= e((string) ($kpis['sim_active'] ?? 0)) ?></span>
                <span class="des-kpi-label">SIM-uri active</span>
                <span class="des-kpi-note">pentru contact dispecerat</span>
            </div>
        </article>

        <article class="des-kpi">
            <div class="des-kpi-main">
                <span class="des-kpi-icon"><i class="bi bi-coin" aria-hidden="true"></i></span>
                <span class="des-kpi-value"><?= e($moneyShort($kpis['valoare_active'] ?? 0)) ?></span>
                <span class="des-kpi-label">Valoare active</span>
                <span class="des-kpi-note">echipamente și comunicații</span>
            </div>
        </article>

        <article class="des-kpi">
            <div class="des-kpi-main">
                <span class="des-kpi-icon is-amber"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
                <span class="des-kpi-value"><?= e((string) ($kpis['de_inlocuit'] ?? 0)) ?></span>
                <span class="des-kpi-label">De înlocuit</span>
                <span class="des-kpi-note">marcate de utilizatori</span>
            </div>
        </article>

        <article class="des-kpi">
            <div class="des-kpi-main">
                <span class="des-kpi-icon is-red"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></span>
                <span class="des-kpi-value"><?= e((string) ($kpis['nereturnate'] ?? 0)) ?></span>
                <span class="des-kpi-label">Nereturnate</span>
                <span class="des-kpi-note">de la foști angajați</span>
            </div>
        </article>

        <?php // Inventarul are ecran propriu; aici rămâne doar rezumatul, ca scurtătură. ?>
        <a class="des-kpi" href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'stoc'])) ?>">
            <div class="des-kpi-main">
                <span class="des-kpi-icon is-green"><i class="bi bi-archive" aria-hidden="true"></i></span>
                <span class="des-kpi-value"><?= e((string) ($kpis['in_stoc'] ?? 0)) ?></span>
                <span class="des-kpi-label">În stoc</span>
                <span class="des-kpi-note"><?= e((string) ($kpis['articole_in_stoc'] ?? 0)) ?> articole disponibile</span>
                <span class="des-kpi-link">Vezi stocul <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
            </div>
        </a>
    </section>

    <!-- ----------------------------------------------------------- Filtre -->
    <form class="des-filters" id="desFiltersForm" method="get" action="<?= e(url('index.php')) ?>" data-des-filters>
        <input type="hidden" name="page" value="echipamente_soferi">

        <div class="des-filter">
            <label for="desLuna">Lună</label>
            <div class="des-filter-control">
                <i class="bi bi-calendar3" aria-hidden="true"></i>
                <select id="desLuna" name="luna">
                    <option value="">Toate</option>
                    <?php foreach ($monthOptions as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($filters['luna'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="des-filter">
            <label for="desSofer">Șofer</label>
            <div class="des-filter-control">
                <i class="bi bi-person" aria-hidden="true"></i>
                <select id="desSofer" name="driver_id">
                    <option value="">Toți șoferii</option>
                    <?php foreach ($driverOptions as $driver): ?>
                        <option value="<?= e((string) $driver['id']) ?>" <?= (int) ($filters['driver_id'] ?? 0) === (int) $driver['id'] ? 'selected' : '' ?>><?= e((string) $driver['nume']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="des-filter">
            <label for="desCategorie">Categorie</label>
            <div class="des-filter-control">
                <i class="bi bi-tags" aria-hidden="true"></i>
                <select id="desCategorie" name="categorie">
                    <option value="">Toate</option>
                    <?php foreach (['Echipamente fizice', 'Comunicații'] as $group): ?>
                        <option value="<?= e($group) ?>" <?= (string) ($filters['categorie'] ?? '') === $group ? 'selected' : '' ?>><?= e($group) ?></option>
                    <?php endforeach; ?>
                    <?php // Categoriile vin din catalog, deci cele adăugate de utilizator apar aici. ?>
                    <?php foreach ($categories as $category): ?>
                        <?php if ($category === 'Comunicații') { continue; } ?>
                        <option value="<?= e((string) $category) ?>" <?= (string) ($filters['categorie'] ?? '') === (string) $category ? 'selected' : '' ?>><?= e((string) $category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="des-filter">
            <label for="desTipActiv">Tip activ</label>
            <div class="des-filter-control">
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i>
                <select id="desTipActiv" name="tip_activ">
                    <option value="">Toate</option>
                    <?php foreach (DriverEquipmentModel::ASSET_TYPE_FILTERS as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($filters['tip_activ'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="des-filter">
            <label for="desStatus">Status</label>
            <div class="des-filter-control">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <select id="desStatus" name="status">
                    <option value="">Toate</option>
                    <?php foreach (DriverEquipmentModel::STATUS_FILTERS as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($filters['status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="des-filter">
            <label for="desReturnabil">Returnabil</label>
            <div class="des-filter-control">
                <i class="bi bi-arrow-return-left" aria-hidden="true"></i>
                <select id="desReturnabil" name="returnabil">
                    <option value="">Toate</option>
                    <option value="da" <?= (string) ($filters['returnabil'] ?? '') === 'da' ? 'selected' : '' ?>>Da</option>
                    <option value="nu" <?= (string) ($filters['returnabil'] ?? '') === 'nu' ? 'selected' : '' ?>>Nu</option>
                </select>
            </div>
        </div>

        <div class="des-filter des-filter-reset">
            <a class="des-btn w-100 justify-content-center <?= $hasFilters ? '' : 'des-btn-ghost' ?>" href="<?= e(build_query_url(['page' => 'echipamente_soferi'])) ?>">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Resetează
            </a>
        </div>
    </form>

    <?php
    // Indicatorii din antet rămân la nivel de flotă; bara de mai jos spune clar
    // ce s-a găsit și permite comutarea între cele două citiri ale rezultatului.
    $search = (string) ($filters['q'] ?? '');
    $isItemSearch = $searchResults !== null;

    $searchResetUrl = build_query_url(array_diff_key($baseQuery, ['q' => true, 'vizualizare' => true]));
    $searchViewUrl = static function (string $view) use ($baseQuery): string {
        $query = array_diff_key($baseQuery, ['vizualizare' => true, 'p' => true]);
        if ($view !== 'echipamente') {
            $query['vizualizare'] = $view;
        }

        return build_query_url($query);
    };
    $searchPageUrl = static function (int $page) use ($baseQuery): string {
        $query = array_diff_key($baseQuery, ['p' => true]);
        if ($page > 1) {
            $query['p'] = (string) $page;
        }

        return build_query_url($query);
    };

    if ($isItemSearch) {
        $searchCount = (int) $searchResults['total'];
        $searchLabel = $searchCount === 1 ? 'echipament găsit' : 'echipamente găsite';
        $searchExtra = (int) $searchResults['detinatori'] > 0
            ? (string) $searchResults['detinatori'] . ((int) $searchResults['detinatori'] === 1 ? ' deținător' : ' deținători')
            : '';
    } else {
        $searchCount = count($driverRows);
        $searchLabel = $searchCount === 1 ? 'șofer' : 'șoferi';
    }
    $searchViews = [
        'echipamente' => ['label' => 'Echipamente', 'icon' => 'bi-box-seam'],
        'detinatori' => ['label' => 'Deținători', 'icon' => 'bi-person-badge'],
    ];
    $searchActiveView = $searchView;
    // În modul pe obiecte nu există rânduri de desfăcut: rezultatele sunt deja plate.
    $searchExpandable = !$isItemSearch;
    include __DIR__ . '/_search_note.php';
    ?>

    <?php if ($isItemSearch): ?>
        <?php // Căutarea răspunde la „ce obiecte se potrivesc”, nu la „cine are ceva”. ?>
        <?php include __DIR__ . '/_search_results.php'; ?>
    <?php else: ?>

    <!-- ------------------------------------------------- Tabel per șofer -->
    <div class="des-card">
        <div class="des-table-wrap">
            <table class="des-table">
                <thead>
                    <tr>
                        <th style="width: 46px;"><span class="visually-hidden">Extinde</span></th>
                        <th>Șofer</th>
                        <th class="des-col-num">Obiecte active</th>
                        <th class="des-col-num">Echipamente fizice</th>
                        <th class="des-col-num">Comunicații</th>
                        <th>Valoare</th>
                        <th class="des-col-num">De returnat</th>
                        <th>Ultima predare</th>
                        <th>Status</th>
                        <th class="des-col-actions">Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($driverRows === []): ?>
                    <tr>
                        <td colspan="10" class="des-empty">Nu există șoferi care să corespundă filtrelor selectate.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($driverRows as $driver): ?>
                    <?php
                    $panelId = 'des-driver-' . (int) $driver['id'];
                    $avatarTone = match ((string) $driver['status']['tone']) {
                        'danger' => 'is-red',
                        'warning' => 'is-amber',
                        'muted' => 'is-muted',
                        default => '',
                    };
                    ?>
                    <tr class="des-driver-row" data-des-row="<?= e($panelId) ?>" tabindex="0" role="button" aria-expanded="false" aria-controls="<?= e($panelId) ?>">
                        <td>
                            <span class="des-toggle" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                        </td>
                        <td>
                            <div class="des-driver">
                                <span class="des-avatar <?= e($avatarTone) ?>"><?= e((string) $driver['initiale']) ?></span>
                                <span>
                                    <span class="des-driver-name"><?= e((string) $driver['nume']) ?></span>
                                    <span class="des-driver-meta">
                                        <?= $driver['vehicul'] !== '' ? e((string) $driver['vehicul']) : e($dash($driver['telefon'])) ?>
                                        <?= $driver['sofer_activ'] ? '' : ' · inactiv' ?>
                                    </span>
                                </span>
                            </div>
                        </td>
                        <td class="des-col-num">
                            <span class="des-pill is-blue"><?= e((string) $driver['obiecte_active']) ?> obiecte</span>
                        </td>
                        <td class="des-col-num"><span class="des-pill"><?= e((string) $driver['nr_fizice']) ?></span></td>
                        <td class="des-col-num">
                            <span class="des-pill <?= (int) $driver['nr_comunicatii'] > 0 ? 'is-green' : 'is-muted' ?>"><?= e((string) $driver['nr_comunicatii']) ?></span>
                        </td>
                        <td class="des-col-money"><?= e($money($driver['valoare_totala'])) ?></td>
                        <td class="des-col-num">
                            <span class="des-pill <?= (int) $driver['de_returnat'] > 0 ? 'is-red' : 'is-muted' ?>"><?= e((string) $driver['de_returnat']) ?></span>
                        </td>
                        <td><?= $driver['ultima_predare'] !== null ? e(format_date_ro((string) $driver['ultima_predare'])) : '—' ?></td>
                        <td><?= $statusBadge($driver['status']) ?></td>
                        <td class="des-col-actions">
                            <div class="dropdown" data-des-stop>
                                <button class="des-menu-btn" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="Acțiuni șofer">
                                    <i class="bi bi-three-dots" aria-hidden="true"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button class="dropdown-item" type="button" data-des-expand="<?= e($panelId) ?>"><i class="bi bi-eye"></i>Vezi detalii</button></li>
                                    <?php if ($canManage): ?>
                                        <li>
                                            <button class="dropdown-item" type="button" data-des-open="handover" data-des-sofer-id="<?= e((string) $driver['id']) ?>" data-des-sofer="<?= e((string) $driver['nume']) ?>">
                                                <i class="bi bi-plus-circle"></i>Predă echipament
                                            </button>
                                        </li>
                                    <?php endif; ?>
                                    <li>
                                        <a class="dropdown-item" href="<?= e(build_query_url(['page' => 'documente_soferi', 'driver_id' => (string) $driver['id']])) ?>">
                                            <i class="bi bi-file-earmark-text"></i>Documente șofer
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>

                    <tr class="des-expand-row" id="<?= e($panelId) ?>" hidden>
                        <td class="des-expand-cell" colspan="10">
                            <?php include __DIR__ . '/_person_panel.php'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>

    <?php include __DIR__ . '/_modals.php'; ?>
</div>

<script id="des-stock-data" type="application/json"><?= json_encode(array_values($stockMap), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(url('assets/js/echipamente-soferi.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/echipamente-soferi.js'))) ?>"></script>
