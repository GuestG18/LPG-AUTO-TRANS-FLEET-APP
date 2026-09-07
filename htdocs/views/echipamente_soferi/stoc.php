<?php
/**
 * Stoc echipamente — ce avem disponibil și nealocat.
 *
 * Complementară paginii de alocări: acolo se vede CINE CE ARE, aici CÂT AVEM.
 * Un rând = un articol într-o gestiune; extinderea arată mărimile (pentru
 * articolele pe cantitate) și unitățile individuale (pentru cele serializate).
 */

$filters = is_array($filters ?? null) ? $filters : [];
$stockRows = is_array($stockRows ?? null) ? $stockRows : [];
$stockSummary = is_array($stockSummary ?? null) ? $stockSummary : [];
$catalogItems = is_array($catalogItems ?? null) ? $catalogItems : [];
$locations = is_array($locations ?? null) ? $locations : [];
$categories = is_array($categories ?? null) ? $categories : DriverEquipmentModel::DEFAULT_CATEGORIES;
$movements = is_array($movements ?? null) ? $movements : [];

$canStock = !function_exists('can') || can('echipamente_soferi', 'manage_stock');
$canManage = !function_exists('can') || can('echipamente_soferi', 'manage_assignments');
$canExport = !function_exists('can') || can('echipamente_soferi', 'export');

$money = static fn(mixed $value): string => format_number_ro((float) ($value ?? 0), 2) . ' lei';
$moneyShort = static fn(mixed $value): string => format_number_ro((float) ($value ?? 0), 0) . ' lei';
$dash = static fn(mixed $value): string => trim((string) ($value ?? '')) !== '' ? trim((string) $value) : '—';

$baseQuery = ['page' => 'echipamente_soferi', 'action' => 'stoc'];
foreach (['q', 'categorie', 'status', 'locatie', 'tip'] as $key) {
    if (trim((string) ($filters[$key] ?? '')) !== '') {
        $baseQuery[$key] = (string) $filters[$key];
    }
}
$hasFilters = count($baseQuery) > 2;

$itemIcon = static function (array $item): string {
    $name = mb_strtolower((string) ($item['denumire'] ?? ''));
    return match (true) {
        str_contains($name, 'sim') => 'bi-sim',
        str_contains($name, 'telefon') => 'bi-phone',
        str_contains($name, 'tablet') => 'bi-tablet',
        str_contains($name, 'radio'), str_contains($name, 'stație') => 'bi-broadcast-pin',
        str_contains($name, 'card') => 'bi-credit-card-2-front',
        str_contains($name, 'bocanc') => 'bi-boombox',
        str_contains($name, 'cască'), str_contains($name, 'casca') => 'bi-shield',
        str_contains($name, 'vestă'), str_contains($name, 'vesta') => 'bi-vignette',
        str_contains($name, 'trusă'), str_contains($name, 'scule') => 'bi-tools',
        default => (string) ($item['grupa'] ?? 'fizic') === 'comunicatii' ? 'bi-broadcast' : 'bi-bag',
    };
};
?>
<link rel="stylesheet" href="<?= e(url('assets/css/echipamente-soferi.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/echipamente-soferi.css'))) ?>">

<div class="des-page" data-des-stock-page data-des-search="<?= e((string) ($filters['q'] ?? '')) ?>">

    <?php $activeTab = 'stoc'; include __DIR__ . '/_tabs.php'; ?>

    <!-- ------------------------------------------------------------ Antet -->
    <header class="des-header">
        <div>
            <h1>Stoc echipamente</h1>
            <p>Gestionează articolele disponibile care nu sunt alocate momentan șoferilor.</p>
        </div>
        <div class="des-header-actions">
            <?php if ($canExport): ?>
                <a class="des-btn" href="<?= e(build_query_url(array_merge($baseQuery, ['action' => 'export_stoc']))) ?>">
                    <i class="bi bi-download" aria-hidden="true"></i>Export
                </a>
            <?php endif; ?>
            <?php if ($canStock): ?>
                <button class="des-btn" type="button" data-des-open="stock-out">
                    <i class="bi bi-box-arrow-up" aria-hidden="true"></i>Scoate din stoc
                </button>
                <button class="des-btn des-btn-primary" type="button" data-des-open="stock-in">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>Intrare în stoc
                </button>
            <?php endif; ?>
        </div>
    </header>

    <!-- -------------------------------------------------------------- KPI -->
    <section class="des-kpi-grid" aria-label="Indicatori stoc">
        <article class="des-kpi">
            <div class="des-kpi-main">
                <span class="des-kpi-icon is-violet"><i class="bi bi-boxes" aria-hidden="true"></i></span>
                <span class="des-kpi-value"><?= e(format_number_ro((int) ($stockSummary['articole'] ?? 0), 0)) ?></span>
                <span class="des-kpi-label">Articole în stoc</span>
                <span class="des-kpi-note"><?= e((string) ($stockSummary['tipuri'] ?? 0)) ?> tipuri · <?= e($moneyShort($stockSummary['valoare'] ?? 0)) ?></span>
            </div>
        </article>

        <article class="des-kpi">
            <div class="des-kpi-main">
                <span class="des-kpi-icon is-green"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></span>
                <span class="des-kpi-value"><?= e((string) ($stockSummary['disponibile_inlocuire'] ?? 0)) ?></span>
                <span class="des-kpi-label">Disponibile pentru înlocuire</span>
                <span class="des-kpi-note">bucăți libere, nerezervate</span>
            </div>
        </article>

        <article class="des-kpi">
            <div class="des-kpi-main">
                <span class="des-kpi-icon is-amber"><i class="bi bi-graph-down-arrow" aria-hidden="true"></i></span>
                <span class="des-kpi-value"><?= e((string) ($stockSummary['stoc_scazut'] ?? 0)) ?></span>
                <span class="des-kpi-label">Stoc scăzut</span>
                <span class="des-kpi-note">sub pragul minim configurat</span>
            </div>
        </article>

        <article class="des-kpi">
            <div class="des-kpi-main">
                <span class="des-kpi-icon is-red"><i class="bi bi-x-octagon" aria-hidden="true"></i></span>
                <span class="des-kpi-value"><?= e((string) ($stockSummary['epuizate'] ?? 0)) ?></span>
                <span class="des-kpi-label">Epuizate</span>
                <span class="des-kpi-note">necesită aprovizionare</span>
            </div>
        </article>

        <article class="des-kpi">
            <div class="des-kpi-main">
                <span class="des-kpi-icon is-teal"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
                <span class="des-kpi-value"><?= e($moneyShort($stockSummary['valoare'] ?? 0)) ?></span>
                <span class="des-kpi-label">Valoare stoc</span>
                <span class="des-kpi-note"><?= e((string) ($stockSummary['rezervate'] ?? 0)) ?> bucăți rezervate</span>
            </div>
        </article>
    </section>

    <!-- ----------------------------------------------------------- Filtre -->
    <form class="des-filters" id="desStockFilters" method="get" action="<?= e(url('index.php')) ?>">
        <input type="hidden" name="page" value="echipamente_soferi">
        <input type="hidden" name="action" value="stoc">

        <div class="des-filter des-filter-wide">
            <label for="desStockSearch">Caută</label>
            <div class="des-filter-control">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="desStockSearch" type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>"
                       placeholder="Caută echipament, categorie, serie...">
            </div>
        </div>

        <div class="des-filter">
            <label for="desStockCategory">Categorie</label>
            <div class="des-filter-control">
                <i class="bi bi-tags" aria-hidden="true"></i>
                <select id="desStockCategory" name="categorie">
                    <option value="">Toate</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e((string) $category) ?>" <?= (string) ($filters['categorie'] ?? '') === (string) $category ? 'selected' : '' ?>><?= e((string) $category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="des-filter">
            <label for="desStockStatus">Status</label>
            <div class="des-filter-control">
                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                <select id="desStockStatus" name="status">
                    <option value="">Toate</option>
                    <?php foreach (DriverEquipmentModel::STOCK_STATUS_FILTERS as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($filters['status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="des-filter">
            <label for="desStockLocation">Locație</label>
            <div class="des-filter-control">
                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                <select id="desStockLocation" name="locatie">
                    <option value="">Toate</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= e((string) $location) ?>" <?= (string) ($filters['locatie'] ?? '') === (string) $location ? 'selected' : '' ?>><?= e((string) $location) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="des-filter">
            <label for="desStockType">Tip</label>
            <div class="des-filter-control">
                <i class="bi bi-diagram-3" aria-hidden="true"></i>
                <select id="desStockType" name="tip">
                    <option value="">Toate</option>
                    <?php foreach (DriverEquipmentModel::STOCK_TYPE_FILTERS as $value => $label): ?>
                        <option value="<?= e((string) $value) ?>" <?= (string) ($filters['tip'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="des-filter des-filter-reset">
            <a class="des-btn w-100 justify-content-center <?= $hasFilters ? '' : 'des-btn-ghost' ?>"
               href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'stoc'])) ?>">
                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Resetează
            </a>
        </div>
    </form>

    <?php
    $search = (string) ($filters['q'] ?? '');
    $searchCount = count($stockRows);
    $searchLabel = $searchCount === 1 ? 'articol' : 'articole';
    $searchResetUrl = build_query_url(array_diff_key($baseQuery, ['q' => true]));
    include __DIR__ . '/_search_note.php';
    ?>

    <!-- ------------------------------------------------------ Tabel stoc -->
    <div class="des-card">
        <div class="des-table-wrap">
            <table class="des-table">
                <thead>
                    <tr>
                        <th style="width: 46px;"><span class="visually-hidden">Extinde</span></th>
                        <th>Echipament</th>
                        <th>Categorie</th>
                        <th class="des-col-num">Disponibil</th>
                        <th class="des-col-num">Rezervat</th>
                        <th class="des-col-num">Total</th>
                        <th class="des-col-num">Prag minim</th>
                        <th>Cost / buc.</th>
                        <th>Valoare stoc</th>
                        <th>Locație</th>
                        <th>Status</th>
                        <th class="des-col-actions">Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($stockRows === []): ?>
                    <tr><td colspan="12" class="des-empty">Nu există articole în stoc pentru filtrele selectate.</td></tr>
                <?php endif; ?>

                <?php foreach ($stockRows as $row): ?>
                    <?php
                    $panelId = 'des-stoc-' . preg_replace('/[^a-z0-9]+/i', '-', (string) $row['key']);
                    $cost = (string) $row['tip_logic'] === 'service_asset' && $row['cost_lunar'] !== null
                        ? $money($row['cost_lunar']) . ' / lună'
                        : $money($row['cost_unitar']);
                    $hasDetails = $row['marimi'] !== [] || $row['unitati'] !== [];
                    ?>
                    <tr class="des-driver-row <?= $hasDetails ? '' : 'is-plain' ?>"
                        <?= $hasDetails ? 'data-des-row="' . e($panelId) . '" tabindex="0" role="button" aria-expanded="false" aria-controls="' . e($panelId) . '"' : '' ?>>
                        <td>
                            <?php if ($hasDetails): ?>
                                <span class="des-toggle" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="des-driver">
                                <span class="des-item-icon"><i class="bi <?= e($itemIcon($row)) ?>" aria-hidden="true"></i></span>
                                <span>
                                    <span class="des-driver-name"><?= e((string) $row['denumire']) ?></span>
                                    <span class="des-driver-meta">
                                        <?= e(DriverEquipmentModel::LOGIC_TYPES[(string) $row['tip_logic']] ?? (string) $row['tip_logic']) ?>
                                        <?= $row['serializat'] ? ' · serializat' : '' ?>
                                    </span>
                                </span>
                            </div>
                        </td>
                        <td><?= e((string) $row['categorie']) ?></td>
                        <td class="des-col-num">
                            <span class="des-pill <?= $row['status']['key'] === 'epuizat' ? 'is-red' : ($row['status']['key'] === 'scazut' ? 'is-amber' : 'is-green') ?>"><?= e((string) $row['disponibil']) ?></span>
                        </td>
                        <td class="des-col-num">
                            <span class="des-pill <?= (int) $row['rezervat'] > 0 ? 'is-blue' : 'is-muted' ?>"><?= e((string) $row['rezervat']) ?></span>
                        </td>
                        <td class="des-col-num"><?= e((string) $row['total']) ?></td>
                        <td class="des-col-num"><?= e((string) $row['prag_minim']) ?></td>
                        <td class="des-col-money"><?= e($cost) ?></td>
                        <td class="des-col-money"><?= $row['valoare'] !== null ? e($money($row['valoare'])) : '—' ?></td>
                        <td><?= e((string) $row['locatie']) ?></td>
                        <td><span class="des-badge is-<?= e((string) $row['status']['tone']) ?>"><?= e((string) $row['status']['label']) ?></span></td>
                        <td class="des-col-actions">
                            <div class="dropdown" data-des-stop>
                                <button class="des-menu-btn" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="Acțiuni stoc">
                                    <i class="bi bi-three-dots" aria-hidden="true"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <?php if ($canStock): ?>
                                        <li>
                                            <button class="dropdown-item" type="button" data-des-open="stock-in"
                                                    data-des-catalog="<?= e((string) $row['catalog_id']) ?>"
                                                    data-des-locatie="<?= e((string) $row['locatie']) ?>">
                                                <i class="bi bi-box-arrow-in-down"></i>Intrare în stoc
                                            </button>
                                        </li>
                                        <li>
                                            <button class="dropdown-item" type="button" data-des-open="stock-out"
                                                    data-des-catalog="<?= e((string) $row['catalog_id']) ?>"
                                                    data-des-locatie="<?= e((string) $row['locatie']) ?>">
                                                <i class="bi bi-box-arrow-up"></i>Scoate din stoc
                                            </button>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($canManage): ?>
                                        <li>
                                            <a class="dropdown-item" href="<?= e(build_query_url(['page' => 'echipamente_soferi'])) ?>">
                                                <i class="bi bi-person-plus"></i>Predă unui șofer
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($canStock && $row['stoc_ids'] !== []): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <button class="dropdown-item" type="button" data-des-open="stock-settings"
                                                    data-des-stoc="<?= e((string) $row['stoc_ids'][0]) ?>"
                                                    data-des-denumire="<?= e((string) $row['denumire']) ?>"
                                                    data-des-prag="<?= e((string) $row['prag_minim']) ?>"
                                                    data-des-cost="<?= e(number_format((float) $row['cost_unitar'], 2, '.', '')) ?>"
                                                    data-des-utilizabil="<?= (int) $row['utilizabil_inlocuire'] === 1 ? '1' : '0' ?>">
                                                <i class="bi bi-sliders"></i>Setări stoc
                                            </button>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>

                    <?php if ($hasDetails): ?>
                        <tr class="des-expand-row" id="<?= e($panelId) ?>" hidden>
                            <td class="des-expand-cell" colspan="12">
                                <div class="des-panel">
                                    <div class="des-subgrid <?= $row['marimi'] === [] || $row['unitati'] === [] ? 'is-single' : '' ?>">

                                        <?php if ($row['marimi'] !== []): ?>
                                            <?php // Disponibilul se citește pe mărime: 42 nu înlocuiește 43. ?>
                                            <section class="des-subcard">
                                                <div class="des-subcard-head">
                                                    <i class="bi bi-rulers" aria-hidden="true"></i>
                                                    <h3>Disponibil pe mărimi</h3>
                                                </div>
                                                <div class="des-table-wrap">
                                                    <table class="des-subtable">
                                                        <thead>
                                                            <tr>
                                                                <th>Mărime</th>
                                                                <th class="des-col-num">Disponibil</th>
                                                                <th class="des-col-num">Rezervat</th>
                                                                <th class="des-col-num">Prag minim</th>
                                                                <th>Status</th>
                                                                <th class="des-col-actions">Acțiuni</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                        <?php foreach ($row['marimi'] as $size): ?>
                                                            <?php
                                                            $sizeTone = (int) $size['disponibil'] <= 0
                                                                ? 'danger'
                                                                : ((int) $size['disponibil'] <= (int) $size['prag_minim'] ? 'warning' : 'success');
                                                            $sizeLabel = (int) $size['disponibil'] <= 0
                                                                ? 'Epuizat'
                                                                : ((int) $size['disponibil'] <= (int) $size['prag_minim'] ? 'Stoc scăzut' : 'În stoc');
                                                            ?>
                                                            <tr class="<?= $sizeTone === 'danger' ? 'is-critical' : ($sizeTone === 'warning' ? 'is-flagged' : '') ?>">
                                                                <td class="des-item-name"><?= e((string) $size['marime']) ?></td>
                                                                <td class="des-col-num"><?= e((string) $size['disponibil']) ?></td>
                                                                <td class="des-col-num"><?= e((string) $size['rezervat']) ?></td>
                                                                <td class="des-col-num"><?= e((string) $size['prag_minim']) ?></td>
                                                                <td><span class="des-status is-<?= e($sizeTone) ?>"><?= e($sizeLabel) ?></span></td>
                                                                <td class="des-col-actions">
                                                                    <?php if ($canStock): ?>
                                                                        <div class="dropdown" data-des-stop>
                                                                            <button class="des-menu-btn" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="Acțiuni mărime">
                                                                                <i class="bi bi-three-dots" aria-hidden="true"></i>
                                                                            </button>
                                                                            <ul class="dropdown-menu dropdown-menu-end">
                                                                                <li>
                                                                                    <button class="dropdown-item" type="button" data-des-open="stock-in"
                                                                                            data-des-catalog="<?= e((string) $row['catalog_id']) ?>"
                                                                                            data-des-marime="<?= e((string) $size['marime']) ?>"
                                                                                            data-des-locatie="<?= e((string) $row['locatie']) ?>">
                                                                                        <i class="bi bi-box-arrow-in-down"></i>Intrare pe mărimea <?= e((string) $size['marime']) ?>
                                                                                    </button>
                                                                                </li>
                                                                                <li>
                                                                                    <button class="dropdown-item" type="button" data-des-open="stock-out"
                                                                                            data-des-catalog="<?= e((string) $row['catalog_id']) ?>"
                                                                                            data-des-marime="<?= e((string) $size['marime']) ?>"
                                                                                            data-des-locatie="<?= e((string) $row['locatie']) ?>">
                                                                                        <i class="bi bi-box-arrow-up"></i>Ieșire de pe mărimea <?= e((string) $size['marime']) ?>
                                                                                    </button>
                                                                                </li>
                                                                                <li>
                                                                                    <button class="dropdown-item" type="button" data-des-open="stock-settings"
                                                                                            data-des-stoc="<?= e((string) $size['stoc_id']) ?>"
                                                                                            data-des-denumire="<?= e((string) $row['denumire'] . ' — mărimea ' . $size['marime']) ?>"
                                                                                            data-des-prag="<?= e((string) $size['prag_minim']) ?>"
                                                                                            data-des-cost="<?= e(number_format((float) $row['cost_unitar'], 2, '.', '')) ?>"
                                                                                            data-des-utilizabil="<?= (int) $row['utilizabil_inlocuire'] === 1 ? '1' : '0' ?>">
                                                                                        <i class="bi bi-sliders"></i>Setări mărime
                                                                                    </button>
                                                                                </li>
                                                                            </ul>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </section>
                                        <?php endif; ?>

                                        <?php if ($row['unitati'] !== []): ?>
                                            <?php // Articolele identificabile individual: fiecare bucată cu istoria ei. ?>
                                            <section class="des-subcard">
                                                <div class="des-subcard-head">
                                                    <i class="bi bi-upc-scan" aria-hidden="true"></i>
                                                    <h3>Unități în evidență (<?= e((string) count($row['unitati'])) ?>)</h3>
                                                </div>
                                                <div class="des-table-wrap">
                                                    <table class="des-subtable">
                                                        <thead>
                                                            <tr>
                                                                <th>Serie / IMEI</th>
                                                                <?php if ((string) $row['tip_logic'] === 'service_asset'): ?>
                                                                    <th>Număr / operator</th>
                                                                    <th>ICCID</th>
                                                                    <th>Abonament</th>
                                                                <?php endif; ?>
                                                                <th>Stare</th>
                                                                <th>Intrare</th>
                                                                <th>Cost achiziție</th>
                                                                <th>Furnizor</th>
                                                                <th>Locație</th>
                                                                <th>Status</th>
                                                                <th class="des-col-actions">Acțiuni</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                        <?php foreach ($row['unitati'] as $unit): ?>
                                                            <?php
                                                            $unitTone = match ((string) $unit['status']) {
                                                                'disponibil' => 'success',
                                                                'alocat', 'rezervat' => 'blue',
                                                                'deteriorat', 'pierdut' => 'danger',
                                                                default => 'muted',
                                                            };
                                                            ?>
                                                            <tr>
                                                                <td class="des-item-name"><?= e($dash($unit['serie'])) ?></td>
                                                                <?php if ((string) $row['tip_logic'] === 'service_asset'): ?>
                                                                    <td>
                                                                        <?= e($dash($unit['numar_telefon'])) ?>
                                                                        <?php if (trim((string) ($unit['operator'] ?? '')) !== ''): ?>
                                                                            <span class="des-note"><?= e((string) $unit['operator']) ?></span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td><?= e($dash($unit['iccid'])) ?></td>
                                                                    <td>
                                                                        <?= e(DriverEquipmentModel::SUBSCRIPTION_TYPES[(string) $unit['tip_abonament']] ?? '—') ?>
                                                                        <?php if ($unit['cost_lunar'] !== null && (float) $unit['cost_lunar'] > 0): ?>
                                                                            <span class="des-note"><?= e($money($unit['cost_lunar'])) ?> / lună</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                <?php endif; ?>
                                                                <td><?= e((string) $unit['stare_label']) ?></td>
                                                                <td><?= $unit['data_intrarii'] !== null ? e(format_date_ro((string) $unit['data_intrarii'])) : '—' ?></td>
                                                                <td class="des-col-money"><?= e($money($unit['cost_achizitie'])) ?></td>
                                                                <td><?= e($dash($unit['furnizor'])) ?></td>
                                                                <td><?= e((string) $unit['locatie']) ?></td>
                                                                <td>
                                                                    <span class="des-badge is-<?= e($unitTone) ?>"><?= e((string) $unit['status_label']) ?></span>
                                                                    <?php if ((string) $unit['status'] === 'alocat' && trim((string) ($unit['sofer_nume'] ?? '')) !== ''): ?>
                                                                        <span class="des-note"><?= e((string) $unit['sofer_nume']) ?></span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="des-col-actions">
                                                                    <?php if ($canStock && (string) $unit['status'] === 'disponibil'): ?>
                                                                        <div class="dropdown" data-des-stop>
                                                                            <button class="des-menu-btn" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" aria-label="Acțiuni unitate">
                                                                                <i class="bi bi-three-dots" aria-hidden="true"></i>
                                                                            </button>
                                                                            <ul class="dropdown-menu dropdown-menu-end">
                                                                                <li>
                                                                                    <button class="dropdown-item" type="button" data-des-open="stock-out"
                                                                                            data-des-catalog="<?= e((string) $row['catalog_id']) ?>"
                                                                                            data-des-unitate="<?= e((string) $unit['id']) ?>"
                                                                                            data-des-locatie="<?= e((string) $unit['locatie']) ?>"
                                                                                            data-des-marime="<?= e((string) $unit['marime']) ?>"
                                                                                            data-des-eticheta="<?= e($dash($unit['serie'] ?? $unit['numar_telefon'])) ?>">
                                                                                        <i class="bi bi-box-arrow-up"></i>Scoate această unitate
                                                                                    </button>
                                                                                </li>
                                                                            </ul>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </section>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ------------------------------------------------ Registru mișcări -->
    <?php if ($movements !== []): ?>
        <div class="des-section-head">
            <div>
                <h2>Ultimele mișcări</h2>
                <p>Intrări, ieșiri, predări și returnări — istoricul care explică stocul curent.</p>
            </div>
        </div>
        <div class="des-card">
            <div class="des-log">
                <?php foreach ($movements as $movement): ?>
                    <?php
                    [$icon, $tone, $label] = match ((string) $movement['tip']) {
                        'predare' => ['bi-box-arrow-right', 'is-blue', 'Predare'],
                        'returnare' => ['bi-box-arrow-in-left', 'is-green', 'Returnare'],
                        'inlocuire' => ['bi-arrow-repeat', 'is-amber', 'Înlocuire'],
                        'deteriorare' => ['bi-exclamation-triangle', 'is-amber', 'Deteriorare'],
                        'pierdere' => ['bi-x-octagon', 'is-red', 'Pierdere'],
                        'intrare_stoc' => ['bi-box-arrow-in-down', 'is-green', 'Intrare stoc'],
                        'iesire_stoc' => ['bi-box-arrow-up', 'is-red', 'Ieșire stoc'],
                        default => ['bi-pencil', 'is-muted', 'Ajustare'],
                    };
                    $reason = DriverEquipmentModel::REMOVAL_REASONS[(string) ($movement['motiv'] ?? '')] ?? '';
                    ?>
                    <div class="des-log-item">
                        <span class="des-item-icon"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></span>
                        <span class="des-log-text">
                            <span class="des-badge <?= e($tone) ?>"><?= e($label) ?></span>
                            <strong><?= e((string) $movement['denumire']) ?></strong>
                            <span>
                                × <?= e((string) $movement['cantitate']) ?>
                                <?= trim((string) ($movement['marime'] ?? '')) !== '' ? ' · mărimea ' . e((string) $movement['marime']) : '' ?>
                                <?= $movement['sofer_nume'] !== null ? ' · ' . e((string) $movement['sofer_nume']) : '' ?>
                                <?= $reason !== '' ? ' · ' . e($reason) : '' ?>
                            </span>
                        </span>
                        <small><?= e(format_datetime_ro((string) $movement['created_at'])) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php include __DIR__ . '/_stock_modals.php'; ?>
</div>

<script id="des-stock-data" type="application/json"><?= json_encode(
    array_map(static function (array $row): array {
        return [
            'catalog_id' => (int) $row['catalog_id'],
            'denumire' => (string) $row['denumire'],
            'locatie' => (string) $row['locatie'],
            'liber' => (int) $row['liber'],
            'disponibil' => (int) $row['disponibil'],
            'rezervat' => (int) $row['rezervat'],
            'necesita_marime' => (bool) $row['necesita_marime'],
            'serializat' => (bool) $row['serializat'],
            'utilizabil' => (int) $row['utilizabil_inlocuire'] === 1,
            'pe_marime' => array_column($row['marimi'], 'disponibil', 'marime'),
        ];
    }, $stockRows),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<script src="<?= e(url('assets/js/echipamente-soferi.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/echipamente-soferi.js'))) ?>"></script>
