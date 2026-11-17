<?php
/**
 * Catalog echipamente șoferi — definiția logicii pentru fiecare tip de articol.
 * De aici se decide cum se comportă articolul în pagina de alocări: dacă se
 * urmărește starea, dacă are înlocuire periodică, dacă e returnabil etc.
 */

$catalogItems = is_array($catalogItems ?? null) ? $catalogItems : [];
$catalogBreakdown = is_array($catalogBreakdown ?? null) ? $catalogBreakdown : [];
$categories = is_array($categories ?? null) ? $categories : DriverEquipmentModel::DEFAULT_CATEGORIES;
$locations = is_array($locations ?? null) ? $locations : [DriverEquipmentModel::DEFAULT_LOCATION];
$filters = is_array($filters ?? null) ? $filters : [];
$search = trim((string) ($filters['q'] ?? ''));
$canCatalog = !function_exists('can') || can('echipamente_soferi', 'manage_catalog');

$money = static fn(mixed $value): string => format_number_ro((float) ($value ?? 0), 2) . ' lei';
$dash = static fn(mixed $value): string => trim((string) ($value ?? '')) !== '' ? trim((string) $value) : '—';
$yes = static fn(mixed $value): string => (int) $value === 1
    ? '<span class="des-yesno">Da</span>'
    : '<span class="des-yesno is-no">Nu</span>';
?>
<link rel="stylesheet" href="<?= e(url('assets/css/echipamente-soferi.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/echipamente-soferi.css'))) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/echipamente-catalog.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/echipamente-catalog.css'))) ?>">

<div class="des-page" data-des-catalog-page data-des-search="<?= e($search) ?>">

    <?php $activeTab = 'catalog'; include __DIR__ . '/_tabs.php'; ?>

    <header class="des-header dcx-header">
        <div class="dcx-header-title">
            <span class="dcx-header-ico" aria-hidden="true"><i class="bi bi-stack"></i></span>
            <div>
                <h1>Catalog echipamente</h1>
                <p>Definește tipurile de articole, gestionează stocul, alocările și costurile.</p>
            </div>
        </div>
        <div class="des-header-actions">
            <?php
            $filterOptions = [
                'stare' => ['label' => 'Stare articol', 'options' => ['' => 'Toate', 'activ' => 'Active', 'inactiv' => 'Inactive']],
                'destinatie' => ['label' => 'Destinație', 'options' => ['' => 'Toate', 'sofer' => 'Șofer', 'tesa' => 'TESA']],
                'stoc' => ['label' => 'Stoc', 'options' => ['' => 'Toate', 'cu' => 'Cu stoc', 'fara' => 'Fără stoc']],
            ];
            $activeFilters = count(array_filter(array_intersect_key($filters, $filterOptions), static fn($value): bool => (string) $value !== ''));
            ?>
            <form class="dcx-header-form" method="get" action="<?= e(url('index.php')) ?>" data-dcx-filter-form>
                <input type="hidden" name="page" value="echipamente_soferi">
                <input type="hidden" name="action" value="catalog">
                <div class="des-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <label class="visually-hidden" for="desCatalogSearch">Caută articol în catalog</label>
                    <input id="desCatalogSearch" type="search" name="q" value="<?= e($search) ?>"
                           placeholder="Caută articol, categorie, tip logic...">
                </div>
                <details class="dcx-filters">
                    <summary class="des-btn">
                        <i class="bi bi-funnel" aria-hidden="true"></i>Filtre
                        <?php if ($activeFilters > 0): ?><span class="dcx-filter-count"><?= e((string) $activeFilters) ?></span><?php endif; ?>
                    </summary>
                    <div class="dcx-filters-pop">
                        <?php foreach ($filterOptions as $name => $filter): ?>
                            <label class="form-label" for="dcxFilter_<?= e($name) ?>"><?= e($filter['label']) ?></label>
                            <select class="form-select form-select-sm" id="dcxFilter_<?= e($name) ?>" name="<?= e($name) ?>">
                                <?php foreach ($filter['options'] as $value => $label): ?>
                                    <option value="<?= e((string) $value) ?>" <?= (string) ($filters[$name] ?? '') === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endforeach; ?>
                        <?php if ($activeFilters > 0): ?>
                            <a class="dcx-filters-reset" href="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'catalog', 'q' => $search !== '' ? $search : null])) ?>">
                                <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Resetează filtrele
                            </a>
                        <?php endif; ?>
                    </div>
                </details>
            </form>
            <?php if ($canCatalog): ?>
                <button class="des-btn des-btn-primary" type="button" data-des-catalog-new>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>Articol nou
                </button>
            <?php endif; ?>
        </div>
    </header>

    <?php
    $searchCount = count($catalogItems);
    $searchLabel = $searchCount === 1 ? 'articol în catalog' : 'articole în catalog';

    $searchResetUrl = build_query_url(['page' => 'echipamente_soferi', 'action' => 'catalog']);
    include __DIR__ . '/_search_note.php';
    ?>

    <?php include __DIR__ . '/_catalog_overview.php'; ?>

    <?php if ($canCatalog): ?>
    <!-- Formular articol -->
    <div class="modal fade des-modal" id="desCatalogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" action="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'save_catalog'])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" data-des-field="id" value="">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title" data-des-text="titlu">Articol nou</h2>
                            <p>Logica aleasă aici decide ce coloane și ce acțiuni apar în pagina de alocări.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="desCatalogName">Denumire</label>
                                <input class="form-control" id="desCatalogName" type="text" name="denumire" maxlength="150" required data-des-field="denumire">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="desCatalogCategory">Categorie</label>
                                <?php // Text liber cu sugestii: se poate scrie o categorie nouă. ?>
                                <input class="form-control" id="desCatalogCategory" type="text" name="categorie"
                                       list="desCategoryOptions" maxlength="60" required
                                       placeholder="alege sau scrie una nouă" data-des-field="categorie">
                                <datalist id="desCategoryOptions">
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= e((string) $category) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <span class="des-note">Scrie o valoare nouă ca să adaugi o categorie.</span>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="desCatalogGroup">Grupă</label>
                                <select class="form-select" id="desCatalogGroup" name="grupa" data-des-field="grupa">
                                    <?php foreach (DriverEquipmentModel::GROUPS as $groupValue => $groupLabel): ?>
                                        <option value="<?= e((string) $groupValue) ?>"><?= e($groupLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="des-note">Alege în ce tabel apare articolul la deținător.</span>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="desCatalogDestination">Destinație</label>
                                <select class="form-select" id="desCatalogDestination" name="destinatie" data-des-field="destinatie">
                                    <?php foreach (DriverEquipmentModel::DESTINATIONS as $destValue => $destLabel): ?>
                                        <option value="<?= e((string) $destValue) ?>"><?= e($destLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="des-note">Cui se predă de obicei. Stocul rămâne comun.</span>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="desCatalogLogic">Tip logic</label>
                                <select class="form-select" id="desCatalogLogic" name="tip_logic" data-des-field="tip_logic">
                                    <?php foreach (DriverEquipmentModel::LOGIC_TYPES as $value => $label): ?>
                                        <option value="<?= e((string) $value) ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="desCatalogMonths">Durată standard (luni)</label>
                                <input class="form-control" id="desCatalogMonths" type="number" name="durata_standard_luni" min="0" step="1" data-des-field="durata_standard_luni">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="desCatalogThreshold">Prag minim stoc</label>
                                <input class="form-control" id="desCatalogThreshold" type="number" name="prag_minim_stoc" min="0" step="1" data-des-field="prag_minim_stoc">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label" for="desCatalogCost">Cost implicit (lei)</label>
                                <input class="form-control" id="desCatalogCost" type="number" name="cost_implicit" min="0" step="0.01" data-des-field="cost_implicit">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label" for="desCatalogMonthly">Cost lunar (lei)</label>
                                <input class="form-control" id="desCatalogMonthly" type="number" name="cost_lunar" min="0" step="0.01" placeholder="doar pentru abonamente" data-des-field="cost_lunar">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="desCatalogLocation">Locație implicită</label>
                                <input class="form-control" id="desCatalogLocation" type="text" name="locatie_implicita"
                                       list="desLocationOptions" maxlength="120"
                                       placeholder="alege sau scrie o gestiune nouă" data-des-field="locatie_implicita">
                                <datalist id="desLocationOptions">
                                    <?php foreach ($locations as $location): ?>
                                        <option value="<?= e((string) $location) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <div class="col-12">
                                <div class="row g-2">
                                    <?php
                                    $switches = [
                                        'returnabil' => 'Returnabil',
                                        'urmareste_stare' => 'Urmărește stare',
                                        'inlocuire_periodica' => 'Înlocuire periodică',
                                        'urmareste_expirare' => 'Urmărește expirare',
                                        'necesita_marime' => 'Necesită mărime',
                                        'necesita_identificator' => 'Necesită identificator (IMEI / număr)',
                                        'serializat' => 'Urmărit bucată cu bucată (serie / IMEI / ICCID)',
                                        'activ' => 'Activ',
                                    ];
                                    ?>
                                    <?php foreach ($switches as $name => $label): ?>
                                        <div class="col-12 col-md-6">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" role="switch" value="1"
                                                       id="desCatalog_<?= e($name) ?>" name="<?= e($name) ?>" data-des-field="<?= e($name) ?>">
                                                <label class="form-check-label" for="desCatalog_<?= e($name) ?>"><?= e($label) ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="des-btn" type="button" data-bs-dismiss="modal">Renunță</button>
                        <button class="des-btn des-btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i>Salvează articolul</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Confirmare ștergere -->
    <div class="modal fade des-modal" id="desCatalogDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="<?= e(build_query_url(['page' => 'echipamente_soferi', 'action' => 'delete_catalog'])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" data-des-field="id">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title">Șterge articolul</h2>
                            <p data-des-text="denumire">—</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="des-callout is-danger">
                            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
                            <div>
                                <strong>Atenție</strong>
                                <span>Dacă articolul are deja alocări, nu se șterge — se dezactivează, iar istoricul rămâne intact.</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="des-btn" type="button" data-bs-dismiss="modal">Renunță</button>
                        <button class="des-btn des-btn-primary" type="submit"><i class="bi bi-trash3" aria-hidden="true"></i>Confirmă</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php // Formularul de articol și comportamentul de căutare vin din JS-ul comun
      // al modulului: Bootstrap se încarcă în footer, deci verificarea lui se
      // face la click, nu la încărcarea scriptului. ?>
<script src="<?= e(url('assets/js/echipamente-soferi.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/echipamente-soferi.js'))) ?>"></script>
<script src="<?= e(url('assets/js/echipamente-catalog.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/echipamente-catalog.js'))) ?>"></script>
