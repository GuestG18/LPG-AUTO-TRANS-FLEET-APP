<?php
$filters = is_array($filters ?? null) ? $filters : [];
$rows = is_array($rows ?? null) ? $rows : [];
$allocationsByExpense = is_array($allocationsByExpense ?? null) ? $allocationsByExpense : [];
$summary = is_array($summary ?? null) ? $summary : [];
$types = is_array($types ?? null) ? $types : [];
$vehicles = is_array($vehicles ?? null) ? $vehicles : [];
$drivers = is_array($drivers ?? null) ? $drivers : [];
$beneficiaries = is_array($beneficiaries ?? null) ? $beneficiaries : [];
$suppliers = is_array($suppliers ?? null) ? $suppliers : [];
$pagination = is_array($pagination ?? null) ? $pagination : ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 20];
$perPageOptions = is_array($perPageOptions ?? null) ? $perPageOptions : [10, 20, 50];

$money = static fn(mixed $value): string => format_number_ro((float) ($value ?? 0), 2) . ' lei';
$date = static fn(mixed $value): string => !empty($value) ? format_date_ro((string) $value) : '-';
$show = static fn(mixed $value): string => trim((string) ($value ?? '')) !== '' ? trim((string) $value) : '-';

$canCreate = !function_exists('can') || can('cheltuieli', 'create');
$canEdit = !function_exists('can') || can('cheltuieli', 'edit');
$canDelete = !function_exists('can') || can('cheltuieli', 'delete');
$canExport = !function_exists('can') || can('cheltuieli', 'export');

$activeCategorie = (string) ($filters['categorie'] ?? '');

$baseQuery = array_filter([
    'page' => 'cheltuieli',
    'date_start' => (string) ($filters['date_start'] ?? ''),
    'date_end' => (string) ($filters['date_end'] ?? ''),
    'categorie' => $activeCategorie,
    'alocare' => (string) ($filters['alocare'] ?? ''),
    'beneficiar_id' => (int) ($filters['beneficiar_id'] ?? 0) > 0 ? (string) $filters['beneficiar_id'] : '',
    'vehicul_id' => (int) ($filters['vehicul_id'] ?? 0) > 0 ? (string) $filters['vehicul_id'] : '',
    'sofer_id' => (int) ($filters['sofer_id'] ?? 0) > 0 ? (string) $filters['sofer_id'] : '',
    'tip_id' => (int) ($filters['tip_id'] ?? 0) > 0 ? (string) $filters['tip_id'] : '',
    'furnizor' => (string) ($filters['furnizor'] ?? ''),
    'q' => (string) ($filters['q'] ?? ''),
    'pp' => (string) ((int) ($pagination['per_page'] ?? 20)),
], static fn($value, $key) => $key === 'page' || $value !== '', ARRAY_FILTER_USE_BOTH);

$tabUrl = static function (string $categorie) use ($baseQuery): string {
    $query = $baseQuery;
    if ($categorie === '') {
        unset($query['categorie']);
    } else {
        $query['categorie'] = $categorie;
    }
    unset($query['p']);
    return build_query_url($query);
};

$pageUrl = static function (int $page) use ($baseQuery): string {
    return build_query_url(array_merge($baseQuery, ['p' => (string) $page]));
};

$allocationBadge = static function (array $allocation) use ($money): array {
    $tip = (string) ($allocation['tip_alocare'] ?? '');
    if ($tip === 'vehicul') {
        $label = trim((string) ($allocation['vehicul_nr'] ?? ''));
        $sub = trim((string) ($allocation['vehicul_marca'] ?? '') . ' ' . (string) ($allocation['vehicul_model'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($allocation['eticheta'] ?? 'Vehicul șters'));
            $sub = 'vehicul șters';
        }
        return ['icon' => 'bi-truck', 'label' => $label, 'sub' => $sub, 'suma' => $money($allocation['suma'] ?? 0)];
    }
    if ($tip === 'sofer') {
        $label = trim((string) ($allocation['sofer_nume'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($allocation['eticheta'] ?? 'Șofer șters'));
        }
        return ['icon' => 'bi-person', 'label' => $label, 'sub' => '', 'suma' => $money($allocation['suma'] ?? 0)];
    }
    return ['icon' => 'bi-building', 'label' => 'Companie', 'sub' => '', 'suma' => $money($allocation['suma'] ?? 0)];
};

$totalRows = (int) ($pagination['total_rows'] ?? 0);
$currentPage = (int) ($pagination['page'] ?? 1);
$perPage = (int) ($pagination['per_page'] ?? 20);
$totalPages = (int) ($pagination['total_pages'] ?? 1);
$fromRow = $totalRows > 0 ? (($currentPage - 1) * $perPage + 1) : 0;
$toRow = min($totalRows, $currentPage * $perPage);

$alocare = is_array($summary['alocare'] ?? null) ? $summary['alocare'] : ['vehicul' => 0, 'sofer' => 0, 'companie' => 0];
$alocareTotal = (float) ($summary['alocare_total'] ?? 0);
$topTipuri = is_array($summary['top_tipuri'] ?? null) ? $summary['top_tipuri'] : [];

$donutStops = [];
$donutColors = ['vehicul' => '#2563eb', 'sofer' => '#16a34a', 'companie' => '#f59e0b'];
$currentDeg = 0.0;
foreach (['vehicul', 'sofer', 'companie'] as $slice) {
    $value = (float) ($alocare[$slice] ?? 0);
    if ($value <= 0 || $alocareTotal <= 0) {
        continue;
    }
    $next = $currentDeg + ($value / $alocareTotal) * 360;
    $donutStops[] = $donutColors[$slice] . ' ' . str_replace(',', '.', format_number_ro($currentDeg, 2)) . 'deg ' . str_replace(',', '.', format_number_ro($next, 2)) . 'deg';
    $currentDeg = $next;
}
$donutStyle = $donutStops !== []
    ? 'background: conic-gradient(' . implode(', ', $donutStops) . ');'
    : 'background: #e2e8f0;';

// Payload JSON pentru formularul de editare (expense + alocari + documente).
$rowsJson = [];
foreach ($rows as $row) {
    $rowId = (int) ($row['id'] ?? 0);
    $rowAllocations = [];
    foreach ($allocationsByExpense[$rowId] ?? [] as $allocation) {
        $rowAllocations[] = [
            'tip' => (string) ($allocation['tip_alocare'] ?? ''),
            'vehicul_id' => (int) ($allocation['vehicul_id'] ?? 0),
            'sofer_id' => (int) ($allocation['sofer_id'] ?? 0),
            'suma' => (float) ($allocation['suma'] ?? 0),
        ];
    }
    $rowsJson[$rowId] = [
        'id' => $rowId,
        'categorie' => (string) ($row['categorie'] ?? ''),
        'tip_id' => (int) ($row['tip_id'] ?? 0),
        'data_cheltuiala' => (string) ($row['data_cheltuiala'] ?? ''),
        'furnizor' => (string) ($row['furnizor'] ?? ''),
        'valoare' => (float) ($row['valoare'] ?? 0),
        'numar_document' => (string) ($row['numar_document'] ?? ''),
        'observatii' => (string) ($row['observatii'] ?? ''),
        'beneficiar_id' => (int) ($row['beneficiar_id'] ?? 0),
        'alocare_tip' => (string) ($row['alocare_tip'] ?? 'companie'),
        'distribuire' => (string) ($row['distribuire'] ?? 'egal'),
        'alocari' => $rowAllocations,
    ];
}
?>

<div class="chx-page">
    <div class="chx-header mb-3">
        <div class="chx-header-left">
            <h2 class="chx-title mb-0">Cheltuieli</h2>
            <div class="chx-tabs mt-3">
                <a class="chx-tab <?= $activeCategorie === '' ? 'is-active' : '' ?>" href="<?= e($tabUrl('')) ?>">
                    <i class="bi bi-list-ul" aria-hidden="true"></i> Toate cheltuielile
                    <span class="chx-tab-count"><?= e((string) ((int) ($summary['count'] ?? 0))) ?></span>
                </a>
                <a class="chx-tab <?= $activeCategorie === 'administrativa' ? 'is-active' : '' ?>" href="<?= e($tabUrl('administrativa')) ?>">
                    <i class="bi bi-building" aria-hidden="true"></i> Cheltuieli Administrative
                    <span class="chx-tab-count"><?= e((string) ((int) ($summary['count_administrativa'] ?? 0))) ?></span>
                </a>
                <a class="chx-tab <?= $activeCategorie === 'operationala' ? 'is-active' : '' ?>" href="<?= e($tabUrl('operationala')) ?>">
                    <i class="bi bi-truck" aria-hidden="true"></i> Cheltuieli Operaționale
                    <span class="chx-tab-count"><?= e((string) ((int) ($summary['count_operationala'] ?? 0))) ?></span>
                </a>
            </div>
        </div>
        <?php if ($canCreate): ?>
            <button type="button" class="btn btn-primary chx-add-btn" data-bs-toggle="modal" data-bs-target="#chxExpenseModal" data-chx-add>
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă cheltuială
            </button>
        <?php endif; ?>
    </div>

    <form method="get" class="chx-filter-card mb-4" id="chxFilterForm">
        <input type="hidden" name="page" value="cheltuieli">
        <input type="hidden" name="pp" value="<?= e((string) $perPage) ?>">
        <div class="chx-filter-grid">
            <div class="chx-filter-field chx-filter-period">
                <label class="chx-filter-label">Perioadă</label>
                <div class="chx-range" data-chx-range>
                    <input type="hidden" name="date_start" value="<?= e((string) ($filters['date_start'] ?? '')) ?>">
                    <input type="hidden" name="date_end" value="<?= e((string) ($filters['date_end'] ?? '')) ?>">
                    <button type="button" class="form-control chx-range-btn" data-chx-range-btn aria-haspopup="dialog" aria-expanded="false">
                        <i class="bi bi-calendar3" aria-hidden="true"></i>
                        <span data-chx-range-label></span>
                        <i class="bi bi-chevron-down chx-range-caret" aria-hidden="true"></i>
                    </button>
                    <div class="chx-range-menu" hidden data-chx-range-menu role="dialog" aria-label="Alege perioada">
                        <div class="chx-range-cal">
                            <div class="chx-range-cal-head">
                                <button type="button" data-chx-cal-prev aria-label="Luna anterioară"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                                <strong data-chx-cal-title></strong>
                                <button type="button" data-chx-cal-next aria-label="Luna următoare"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                            </div>
                            <div class="chx-range-weekdays">
                                <span>Lu</span><span>Ma</span><span>Mi</span><span>Jo</span><span>Vi</span><span>Sâ</span><span>Du</span>
                            </div>
                            <div class="chx-range-days" data-chx-cal-days></div>
                            <div class="chx-range-foot">
                                <span class="chx-range-sel" data-chx-range-sel></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($activeCategorie !== ''): ?>
                <?php // Categoria se alege din tab-urile de sus; filtrul activ se pastreaza la submit. ?>
                <input type="hidden" name="categorie" value="<?= e($activeCategorie) ?>">
            <?php endif; ?>
            <div class="chx-filter-field">
                <label class="chx-filter-label">Alocată către</label>
                <select class="form-select" name="alocare" data-chx-autosubmit>
                    <option value="">Toate</option>
                    <option value="vehicul" <?= (string) ($filters['alocare'] ?? '') === 'vehicul' ? 'selected' : '' ?>>Vehicul</option>
                    <option value="sofer" <?= (string) ($filters['alocare'] ?? '') === 'sofer' ? 'selected' : '' ?>>Șofer</option>
                    <option value="companie" <?= (string) ($filters['alocare'] ?? '') === 'companie' ? 'selected' : '' ?>>Companie</option>
                </select>
            </div>
            <div class="chx-filter-field">
                <label class="chx-filter-label">Beneficiar / Client</label>
                <select class="form-select" name="beneficiar_id" data-chx-autosubmit>
                    <option value="">Toți clienții</option>
                    <?php foreach ($beneficiaries as $beneficiary): ?>
                        <option value="<?= e((string) $beneficiary['id']) ?>" <?= (int) ($filters['beneficiar_id'] ?? 0) === (int) $beneficiary['id'] ? 'selected' : '' ?>>
                            <?= e((string) $beneficiary['nume']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="chx-filter-field">
                <label class="chx-filter-label">Vehicul</label>
                <select class="form-select" name="vehicul_id" data-chx-autosubmit>
                    <option value="">Toate vehiculele</option>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <option value="<?= e((string) $vehicle['id']) ?>" <?= (int) ($filters['vehicul_id'] ?? 0) === (int) $vehicle['id'] ? 'selected' : '' ?>>
                            <?= e((string) $vehicle['nr_inmatriculare'] . ' - ' . (string) $vehicle['marca'] . ' ' . (string) $vehicle['model']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="chx-filter-field">
                <label class="chx-filter-label">Șofer</label>
                <select class="form-select" name="sofer_id" data-chx-autosubmit>
                    <option value="">Toți șoferii</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?= e((string) $driver['id']) ?>" <?= (int) ($filters['sofer_id'] ?? 0) === (int) $driver['id'] ? 'selected' : '' ?>>
                            <?= e((string) $driver['nume']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="chx-filter-field">
                <label class="chx-filter-label">Tip cheltuială</label>
                <select class="form-select" name="tip_id" data-chx-autosubmit>
                    <option value="">Toate</option>
                    <?php foreach ($types as $type): ?>
                        <?php // Cand un tab de categorie este activ, listam doar tipurile lui.
                        if ($activeCategorie !== '' && (string) $type['categorie'] !== $activeCategorie) {
                            continue;
                        } ?>
                        <option value="<?= e((string) $type['id']) ?>" <?= (int) ($filters['tip_id'] ?? 0) === (int) $type['id'] ? 'selected' : '' ?>>
                            <?= e((string) $type['nume']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="chx-filter-field">
                <label class="chx-filter-label">Furnizor</label>
                <input type="text" class="form-control" name="furnizor" value="<?= e((string) ($filters['furnizor'] ?? '')) ?>" placeholder="Caută furnizor" list="chxSupplierList">
            </div>
            <div class="chx-filter-field chx-filter-keyword">
                <label class="chx-filter-label">Cuvânt cheie</label>
                <div class="chx-search">
                    <input type="search" class="form-control" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Caută în descriere...">
                    <i class="bi bi-search" aria-hidden="true"></i>
                </div>
            </div>
            <div class="chx-filter-field chx-filter-actions">
                <label class="chx-filter-label">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-outline-primary chx-filter-btn">
                        <i class="bi bi-funnel" aria-hidden="true"></i> Filtrează
                    </button>
                    <a class="btn btn-outline-secondary chx-filter-btn" href="<?= e(build_query_url(['page' => 'cheltuieli'])) ?>">
                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Resetează filtrele
                    </a>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-3">
        <div class="col-12 col-xxl-9">
            <div class="chx-kpi-band mb-3">
                <div class="chx-kpi">
                    <div class="chx-kpi-icon is-blue"><i class="bi bi-clipboard-data" aria-hidden="true"></i></div>
                    <div>
                        <div class="chx-kpi-label">Total cheltuieli</div>
                        <div class="chx-kpi-value"><?= e($money($summary['total'] ?? 0)) ?></div>
                        <div class="chx-kpi-note">în perioada selectată</div>
                    </div>
                </div>
                <div class="chx-kpi">
                    <div class="chx-kpi-icon is-green"><i class="bi bi-building" aria-hidden="true"></i></div>
                    <div>
                        <div class="chx-kpi-label">Administrative</div>
                        <div class="chx-kpi-value"><?= e($money($summary['administrativa'] ?? 0)) ?></div>
                        <div class="chx-kpi-note"><?= e(format_number_ro((float) ($summary['procent_administrativa'] ?? 0), 1)) ?>% din total</div>
                    </div>
                </div>
                <div class="chx-kpi">
                    <div class="chx-kpi-icon is-orange"><i class="bi bi-truck" aria-hidden="true"></i></div>
                    <div>
                        <div class="chx-kpi-label">Operaționale</div>
                        <div class="chx-kpi-value"><?= e($money($summary['operationala'] ?? 0)) ?></div>
                        <div class="chx-kpi-note"><?= e(format_number_ro((float) ($summary['procent_operationala'] ?? 0), 1)) ?>% din total</div>
                    </div>
                </div>
            </div>

            <section class="chx-table-card">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 chx-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Categorie</th>
                                <th>Tip cheltuială</th>
                                <th>Alocată către</th>
                                <th>Alocare</th>
                                <th>Beneficiar / Client</th>
                                <th>Furnizor</th>
                                <th class="text-end">Valoare</th>
                                <th class="text-center">Document</th>
                                <th class="text-center">Acțiuni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows === []): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-5">
                                        Nicio cheltuială în perioada selectată. Ajustează filtrele sau adaugă o cheltuială nouă.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $rowId = (int) ($row['id'] ?? 0);
                                $rowAllocations = $allocationsByExpense[$rowId] ?? [];
                                $isMulti = count($rowAllocations) > 1;
                                $alocareTip = (string) ($row['alocare_tip'] ?? 'companie');
                                ?>
                                <tr>
                                    <td class="text-nowrap"><?= e($date($row['data_cheltuiala'] ?? null)) ?></td>
                                    <td>
                                        <?php if ((string) ($row['categorie'] ?? '') === 'operationala'): ?>
                                            <span class="chx-badge is-operational">Operațională</span>
                                        <?php else: ?>
                                            <span class="chx-badge is-administrative">Administrativă</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($show($row['tip_nume'] ?? null)) ?></td>
                                    <td><?= e(ExpenseModel::ALLOCATION_TYPES[$alocareTip] ?? $alocareTip) ?></td>
                                    <td>
                                        <?php if ($rowAllocations === []): ?>
                                            <span class="text-muted">-</span>
                                        <?php elseif (!$isMulti): ?>
                                            <?php $badge = $allocationBadge($rowAllocations[0]); ?>
                                            <span class="chx-entity">
                                                <i class="bi <?= e($badge['icon']) ?>" aria-hidden="true"></i>
                                                <span class="chx-entity-text">
                                                    <strong><?= e($badge['label']) ?></strong>
                                                    <?php if ($badge['sub'] !== ''): ?><small><?= e($badge['sub']) ?></small><?php endif; ?>
                                                </span>
                                            </span>
                                        <?php else: ?>
                                            <button type="button" class="chx-multi-toggle" data-chx-toggle-detail="chxDetail<?= e((string) $rowId) ?>">
                                                <i class="bi bi-diagram-3" aria-hidden="true"></i>
                                                <?= e((string) count($rowAllocations)) ?> alocări
                                                <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($show($row['beneficiar_nume'] ?? null)) ?></td>
                                    <td><?= e($show($row['furnizor'] ?? null)) ?></td>
                                    <td class="text-end text-nowrap fw-semibold"><?= e($money($row['valoare'] ?? 0)) ?></td>
                                    <td class="text-center">
                                        <?php if ((int) ($row['document_count'] ?? 0) > 0): ?>
                                            <a class="chx-doc-link" href="<?= e(build_query_url(['page' => 'cheltuieli', 'action' => 'download_document', 'document_id' => (string) ($row['document_id'] ?? 0)])) ?>" title="<?= e($show($row['document_original_name'] ?? null)) ?>">
                                                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="dropdown">
                                            <button type="button" class="chx-actions-btn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Acțiuni">
                                                <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php if ($isMulti): ?>
                                                    <li><button type="button" class="dropdown-item" data-chx-toggle-detail="chxDetail<?= e((string) $rowId) ?>"><i class="bi bi-eye me-2" aria-hidden="true"></i>Vezi alocările</button></li>
                                                <?php endif; ?>
                                                <?php if ($canEdit): ?>
                                                    <li><button type="button" class="dropdown-item" data-chx-edit="<?= e((string) $rowId) ?>"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Editează</button></li>
                                                <?php endif; ?>
                                                <?php if ($canDelete): ?>
                                                    <li>
                                                        <form method="post" action="<?= e(build_query_url(['page' => 'cheltuieli', 'action' => 'delete'])) ?>" onsubmit="return confirm('Ștergi această cheltuială și alocările ei?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="id" value="<?= e((string) $rowId) ?>">
                                                            <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2" aria-hidden="true"></i>Șterge</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php if ($isMulti): ?>
                                    <tr class="chx-detail-row" id="chxDetail<?= e((string) $rowId) ?>" hidden>
                                        <td colspan="10">
                                            <div class="chx-detail-box">
                                                <div class="chx-detail-title">Alocările cheltuielii (<?= e($money($row['valoare'] ?? 0)) ?> în total)</div>
                                                <div class="chx-detail-grid">
                                                    <?php foreach ($rowAllocations as $allocation): ?>
                                                        <?php $badge = $allocationBadge($allocation); ?>
                                                        <div class="chx-detail-item">
                                                            <i class="bi <?= e($badge['icon']) ?>" aria-hidden="true"></i>
                                                            <span><?= e($badge['label']) ?><?= $badge['sub'] !== '' ? ' · ' . e($badge['sub']) : '' ?></span>
                                                            <strong><?= e($badge['suma']) ?></strong>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="chx-table-footer">
                    <div class="chx-results-info">
                        Afișare <?= e((string) $fromRow) ?> - <?= e((string) $toRow) ?> din <?= e((string) $totalRows) ?> rezultate
                    </div>
                    <nav class="chx-pagination" aria-label="Paginare cheltuieli">
                        <a class="chx-page-btn <?= $currentPage <= 1 ? 'is-disabled' : '' ?>" href="<?= e($pageUrl(1)) ?>" aria-label="Prima pagină">&laquo;</a>
                        <a class="chx-page-btn <?= $currentPage <= 1 ? 'is-disabled' : '' ?>" href="<?= e($pageUrl(max(1, $currentPage - 1))) ?>" aria-label="Pagina anterioară">&lsaquo;</a>
                        <?php
                        $startPage = max(1, $currentPage - 2);
                        $endPage = min($totalPages, $startPage + 4);
                        $startPage = max(1, $endPage - 4);
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a class="chx-page-btn <?= $i === $currentPage ? 'is-active' : '' ?>" href="<?= e($pageUrl($i)) ?>"><?= e((string) $i) ?></a>
                        <?php endfor; ?>
                        <a class="chx-page-btn <?= $currentPage >= $totalPages ? 'is-disabled' : '' ?>" href="<?= e($pageUrl(min($totalPages, $currentPage + 1))) ?>" aria-label="Pagina următoare">&rsaquo;</a>
                        <a class="chx-page-btn <?= $currentPage >= $totalPages ? 'is-disabled' : '' ?>" href="<?= e($pageUrl($totalPages)) ?>" aria-label="Ultima pagină">&raquo;</a>
                    </nav>
                    <div>
                        <select class="form-select form-select-sm chx-per-page" data-chx-per-page aria-label="Rezultate pe pagină">
                            <?php foreach ($perPageOptions as $option): ?>
                                <option value="<?= e((string) $option) ?>" <?= $perPage === (int) $option ? 'selected' : '' ?>><?= e((string) $option) ?> / pagină</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-xxl-3">
            <section class="chx-side-card mb-3">
                <h3 class="chx-side-title">Distribuție după alocare</h3>
                <div class="chx-donut-wrap">
                    <div class="chx-donut" style="<?= e($donutStyle) ?>"><span></span></div>
                    <div class="chx-legend">
                        <?php
                        $legendLabels = ['vehicul' => 'Vehicule', 'sofer' => 'Șoferi', 'companie' => 'Companie'];
                        foreach ($legendLabels as $key => $label):
                            $value = (float) ($alocare[$key] ?? 0);
                            $percent = $alocareTotal > 0 ? ($value / $alocareTotal) * 100 : 0;
                        ?>
                            <div class="chx-legend-line">
                                <span class="chx-dot" style="background: <?= e($donutColors[$key]) ?>"></span>
                                <span class="chx-legend-name"><?= e($label) ?></span>
                                <span class="chx-legend-value"><?= e($money($value)) ?> (<?= e(format_number_ro($percent, 1)) ?>%)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="chx-side-card">
                <h3 class="chx-side-title">Top cheltuieli (după valoare)</h3>
                <div class="chx-top-list">
                    <?php if ($topTipuri === []): ?>
                        <div class="text-muted small py-2">Nicio cheltuială în perioada selectată.</div>
                    <?php endif; ?>
                    <?php foreach ($topTipuri as $topRow): ?>
                        <div class="chx-top-line">
                            <span><?= e((string) ($topRow['nume'] ?? '-')) ?></span>
                            <strong><?= e($money($topRow['total'] ?? 0)) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($canExport): ?>
                    <a class="chx-report-link" href="<?= e(build_query_url(array_merge($baseQuery, ['action' => 'export']))) ?>">
                        <i class="bi bi-bar-chart-line" aria-hidden="true"></i> Vezi raport detaliat
                    </a>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<datalist id="chxSupplierList">
    <?php foreach ($suppliers as $supplier): ?>
        <option value="<?= e($supplier) ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php if ($canCreate || $canEdit): ?>
<div class="modal fade" id="chxExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable chx-modal">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'cheltuieli', 'action' => 'store'])) ?>" id="chxExpenseForm"
                  data-store-url="<?= e(build_query_url(['page' => 'cheltuieli', 'action' => 'store'])) ?>"
                  data-update-url="<?= e(build_query_url(['page' => 'cheltuieli', 'action' => 'update'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="" data-chx-id>
                <input type="hidden" name="aloc_mode" value="simplu" data-chx-aloc-mode>
                <input type="hidden" name="alocare_tip" value="companie" data-chx-alocare-tip>

                <div class="modal-header chx-modal-header">
                    <h3 class="modal-title chx-modal-title" data-chx-modal-title>Adaugă cheltuială</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>

                <div class="modal-body chx-modal-body">
                    <div class="chx-section-title">1. Detalii cheltuială</div>
                    <div class="row g-3">
                        <div class="col-12 col-md-6 col-lg-5">
                            <label class="chx-form-label">Categorie <span class="chx-req">*</span></label>
                            <div class="chx-toggle-group chx-toggle-nowrap" data-chx-cat-group>
                                <label class="chx-toggle is-active">
                                    <input type="radio" name="categorie" value="administrativa" checked>
                                    <i class="bi bi-building" aria-hidden="true"></i> Administrativă
                                </label>
                                <label class="chx-toggle">
                                    <input type="radio" name="categorie" value="operationala">
                                    <i class="bi bi-truck" aria-hidden="true"></i> Operațională
                                </label>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-4">
                            <label class="chx-form-label">Tip cheltuială <span class="chx-req">*</span></label>
                            <select class="form-select" name="tip_id" data-chx-tip required>
                                <option value="">Selectează tipul</option>
                                <?php foreach ($types as $type): ?>
                                    <option value="<?= e((string) $type['id']) ?>" data-categorie="<?= e((string) $type['categorie']) ?>">
                                        <?= e((string) $type['nume']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="chx-form-label">Data cheltuielii <span class="chx-req">*</span></label>
                            <input type="date" class="form-control" name="data_cheltuiala" value="<?= e(date('Y-m-d')) ?>" required>
                        </div>

                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="chx-form-label">Furnizor <span class="chx-req">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="furnizor" placeholder="Caută furnizor" list="chxSupplierList" required>
                                <button type="button" class="btn btn-outline-primary" title="Furnizor nou: scrie direct numele în câmp" data-chx-new-supplier>
                                    <i class="bi bi-plus" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="chx-form-label">Valoare (fără TVA) <span class="chx-req">*</span></label>
                            <div class="input-group">
                                <input type="number" min="0.01" step="0.01" class="form-control" name="valoare" placeholder="0,00" data-chx-valoare required>
                                <span class="input-group-text">lei</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="chx-form-label">Nr. document / Factură</label>
                            <input type="text" class="form-control" name="numar_document" placeholder="FAC-0000">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="chx-form-label">Document</label>
                            <input type="file" class="form-control chx-file-input" name="document_upload" accept=".pdf,.jpg,.jpeg,.png">
                            <div class="chx-hint">PDF, JPG, PNG (max. 10MB)</div>
                        </div>

                        <div class="col-12">
                            <label class="chx-form-label">Observații <span class="text-muted fw-normal">(opțional)</span></label>
                            <textarea class="form-control" name="observatii" rows="2" placeholder="Adaugă observații..."></textarea>
                        </div>
                    </div>

                    <div class="chx-section-title mt-4">2. Alocare cheltuială</div>
                    <div data-chx-simple-alloc>
                        <label class="chx-form-label">Alocată către <span class="chx-req">*</span></label>
                        <div class="chx-toggle-group chx-toggle-wide" data-chx-alloc-group>
                            <label class="chx-toggle">
                                <input type="radio" name="alocare_tip_ui" value="vehicul">
                                <i class="bi bi-truck" aria-hidden="true"></i> Vehicul
                            </label>
                            <label class="chx-toggle">
                                <input type="radio" name="alocare_tip_ui" value="sofer">
                                <i class="bi bi-person" aria-hidden="true"></i> Șofer
                            </label>
                            <label class="chx-toggle is-active">
                                <input type="radio" name="alocare_tip_ui" value="companie" checked>
                                <i class="bi bi-building" aria-hidden="true"></i> Companie
                            </label>
                        </div>

                        <div class="mt-3" data-chx-entity-block="vehicul" hidden>
                            <label class="chx-form-label">Selectează vehicul(e) <span class="chx-req">*</span></label>
                            <div class="chx-multiselect" data-chx-multiselect="vehicul">
                                <div class="chx-chips form-control" data-chx-chips tabindex="0" role="button" aria-haspopup="listbox">
                                    <span class="chx-chips-placeholder">Alege unul sau mai multe vehicule...</span>
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="chx-ms-menu" hidden>
                                    <input type="search" class="form-control form-control-sm mb-2" placeholder="Caută vehicul..." data-chx-ms-search>
                                    <div class="chx-ms-options">
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <?php $vehicleLabel = (string) $vehicle['nr_inmatriculare'] . ' - ' . (string) $vehicle['marca'] . ' ' . (string) $vehicle['model']; ?>
                                            <label class="chx-ms-option">
                                                <input type="checkbox" name="vehicule[]" value="<?= e((string) $vehicle['id']) ?>" data-label="<?= e($vehicleLabel) ?>">
                                                <span><?= e($vehicleLabel) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3" data-chx-entity-block="sofer" hidden>
                            <label class="chx-form-label">Selectează șofer(i) <span class="chx-req">*</span></label>
                            <div class="chx-multiselect" data-chx-multiselect="sofer">
                                <div class="chx-chips form-control" data-chx-chips tabindex="0" role="button" aria-haspopup="listbox">
                                    <span class="chx-chips-placeholder">Alege unul sau mai mulți șoferi...</span>
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </div>
                                <div class="chx-ms-menu" hidden>
                                    <input type="search" class="form-control form-control-sm mb-2" placeholder="Caută șofer..." data-chx-ms-search>
                                    <div class="chx-ms-options">
                                        <?php foreach ($drivers as $driver): ?>
                                            <label class="chx-ms-option">
                                                <input type="checkbox" name="soferi[]" value="<?= e((string) $driver['id']) ?>" data-label="<?= e((string) $driver['nume']) ?>">
                                                <span><?= e((string) $driver['nume']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3" data-chx-dist-block hidden>
                            <label class="chx-form-label">Mod distribuire <span class="chx-req">*</span></label>
                            <div class="chx-dist-grid">
                                <label class="chx-dist-card is-active">
                                    <input type="radio" name="distribuire" value="egal" checked>
                                    <span class="chx-dist-radio"></span>
                                    <span>
                                        <strong>Egal <span class="text-primary">(recomandat)</span></strong>
                                        <small>Valoarea se împarte egal între entitățile selectate</small>
                                    </span>
                                </label>
                                <label class="chx-dist-card">
                                    <input type="radio" name="distribuire" value="manual">
                                    <span class="chx-dist-radio"></span>
                                    <span>
                                        <strong>Manual</strong>
                                        <small>Introduceți valoarea pentru fiecare entitate</small>
                                    </span>
                                </label>
                            </div>
                            <div class="chx-equal-info mt-3" data-chx-equal-info hidden>
                                <i class="bi bi-info-circle" aria-hidden="true"></i>
                                <span data-chx-equal-text></span>
                                <strong class="ms-auto text-primary" data-chx-equal-per></strong>
                            </div>
                            <div class="mt-3" data-chx-manual-list hidden></div>
                        </div>
                    </div>

                    <div class="chx-mixed-note" data-chx-mixed-note hidden>
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        Alocarea se face prin pozițiile din secțiunea 4 (factură cu alocări multiple).
                    </div>

                    <div class="chx-alloc-total mt-3" data-chx-alloc-total hidden>
                        <span>Total alocat: <strong data-chx-total-alocat>0,00 lei</strong> / Total cheltuială: <strong data-chx-total-cheltuiala>0,00 lei</strong></span>
                        <span class="chx-alloc-status" data-chx-alloc-status></span>
                    </div>

                    <div class="chx-section-title mt-4">3. Beneficiar / Client <span class="text-muted fw-normal">(opțional)</span></div>
                    <div class="d-flex align-items-center gap-3">
                        <span>Cheltuiala aparține unui beneficiar?</span>
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" role="switch" name="beneficiar_activ" value="1" data-chx-benef-switch>
                        </div>
                    </div>
                    <div class="mt-3" data-chx-benef-block hidden>
                        <label class="chx-form-label">Beneficiar / Client <span class="chx-req">*</span></label>
                        <div class="input-group">
                            <select class="form-select" name="beneficiar_id">
                                <option value="">Selectează beneficiarul</option>
                                <?php foreach ($beneficiaries as $beneficiary): ?>
                                    <option value="<?= e((string) $beneficiary['id']) ?>"><?= e((string) $beneficiary['nume']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-primary" title="Beneficiarii se administrează din Dispecer curse → Configurare">
                                <i class="bi bi-plus" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="chx-mixed-card mt-4">
                        <button type="button" class="chx-mixed-head" data-bs-toggle="collapse" data-bs-target="#chxMixedBody" aria-expanded="false" aria-controls="chxMixedBody">
                            <span>
                                <strong>4. Factură cu mai multe alocări diferite</strong> <span class="text-muted fw-normal">(opțional)</span>
                                <small>Pentru alocări mixte (vehicule, șoferi și companie)</small>
                            </span>
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </button>
                        <div class="collapse" id="chxMixedBody">
                            <div class="chx-mixed-body">
                                <div data-chx-mixed-lines></div>
                                <button type="button" class="btn btn-outline-primary btn-sm mt-2" data-chx-add-line>
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă poziție
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer chx-modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                    <button type="submit" class="btn btn-primary" data-chx-submit>Salvează cheltuiala</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';

    var EXPENSES = <?= json_encode($rowsJson, JSON_UNESCAPED_UNICODE) ?>;
    var VEHICLE_LABELS = <?= json_encode(array_column(array_map(static fn(array $v): array => ['id' => (int) $v['id'], 'label' => (string) $v['nr_inmatriculare'] . ' - ' . (string) $v['marca'] . ' ' . (string) $v['model']], $vehicles), 'label', 'id'), JSON_UNESCAPED_UNICODE) ?>;
    var DRIVER_LABELS = <?= json_encode(array_column(array_map(static fn(array $d): array => ['id' => (int) $d['id'], 'label' => (string) $d['nume']], $drivers), 'label', 'id'), JSON_UNESCAPED_UNICODE) ?>;

    function formatLei(value) {
        return value.toLocaleString('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' lei';
    }

    // ------------------------------------------------------------ filtre
    document.querySelectorAll('[data-chx-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () {
            document.getElementById('chxFilterForm').submit();
        });
    });
    var perPageSelect = document.querySelector('[data-chx-per-page]');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function () {
            var form = document.getElementById('chxFilterForm');
            form.querySelector('input[name="pp"]').value = perPageSelect.value;
            form.submit();
        });
    }

    // ------------------------------------------- selector interval (Perioada)
    (function () {
        var range = document.querySelector('[data-chx-range]');
        if (!range) {
            return;
        }

        var MONTHS = ['Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];
        var startInput = range.querySelector('input[name="date_start"]');
        var endInput = range.querySelector('input[name="date_end"]');
        var btn = range.querySelector('[data-chx-range-btn]');
        var label = range.querySelector('[data-chx-range-label]');
        var menu = range.querySelector('[data-chx-range-menu]');
        var title = range.querySelector('[data-chx-cal-title]');
        var daysEl = range.querySelector('[data-chx-cal-days]');
        var selEl = range.querySelector('[data-chx-range-sel]');

        function parseIso(value) {
            var parts = String(value || '').split('-');
            if (parts.length !== 3) { return null; }
            var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
            return isNaN(d.getTime()) ? null : d;
        }
        function iso(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        }
        function fmt(d) {
            return String(d.getDate()).padStart(2, '0') + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear();
        }

        var start = parseIso(startInput.value);
        var end = parseIso(endInput.value);
        var today = new Date();
        var view = new Date((start || today).getFullYear(), (start || today).getMonth(), 1);

        function updateLabel() {
            var s = parseIso(startInput.value);
            var e = parseIso(endInput.value);
            label.textContent = s && e ? fmt(s) + ' - ' + fmt(e) : 'Alege perioada';
        }

        function renderCal() {
            title.textContent = MONTHS[view.getMonth()] + ' ' + view.getFullYear();
            daysEl.innerHTML = '';
            var firstDay = new Date(view.getFullYear(), view.getMonth(), 1);
            var lead = (firstDay.getDay() + 6) % 7; // saptamana incepe luni
            var total = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
            for (var i = 0; i < lead; i++) {
                daysEl.appendChild(document.createElement('span'));
            }
            for (var day = 1; day <= total; day++) {
                (function (day) {
                    var d = new Date(view.getFullYear(), view.getMonth(), day);
                    var cell = document.createElement('button');
                    cell.type = 'button';
                    cell.textContent = String(day);
                    var t = d.getTime();
                    if (start && t === start.getTime()) { cell.classList.add('is-edge'); }
                    if (end && t === end.getTime()) { cell.classList.add('is-edge'); }
                    if (start && end && t > start.getTime() && t < end.getTime()) { cell.classList.add('in-range'); }
                    if (t === new Date(today.getFullYear(), today.getMonth(), today.getDate()).getTime()) { cell.classList.add('is-today'); }
                    cell.addEventListener('click', function () {
                        if (!start || (start && end)) {
                            start = d; end = null;
                            renderCal();
                            return;
                        }
                        if (d.getTime() < start.getTime()) {
                            start = d;
                            renderCal();
                            return;
                        }
                        // A doua apasare inchide selectia si aplica direct filtrul.
                        end = d;
                        applyRange();
                    });
                    daysEl.appendChild(cell);
                })(day);
            }
            selEl.textContent = start && !end
                ? (fmt(start) + ' - alege data de sfârșit')
                : 'Alege data de început';
        }

        function applyRange() {
            startInput.value = iso(start);
            endInput.value = iso(end);
            updateLabel();
            toggleMenu(false);
            document.getElementById('chxFilterForm').submit();
        }

        function toggleMenu(force) {
            var show = typeof force === 'boolean' ? force : menu.hidden;
            if (show) {
                // Redeschiderea porneste de la valorile aplicate.
                start = parseIso(startInput.value);
                end = parseIso(endInput.value);
                view = new Date((start || today).getFullYear(), (start || today).getMonth(), 1);
                renderCal();
            }
            menu.hidden = !show;
            btn.setAttribute('aria-expanded', show ? 'true' : 'false');
        }

        btn.addEventListener('click', function () { toggleMenu(); });
        document.addEventListener('click', function (event) {
            if (!range.contains(event.target)) {
                toggleMenu(false);
            }
        });
        range.querySelector('[data-chx-cal-prev]').addEventListener('click', function () {
            view = new Date(view.getFullYear(), view.getMonth() - 1, 1);
            renderCal();
        });
        range.querySelector('[data-chx-cal-next]').addEventListener('click', function () {
            view = new Date(view.getFullYear(), view.getMonth() + 1, 1);
            renderCal();
        });

        updateLabel();
    })();

    // ------------------------------------------------- detalii alocari (tabel)
    document.querySelectorAll('[data-chx-toggle-detail]').forEach(function (button) {
        button.addEventListener('click', function () {
            var row = document.getElementById(button.getAttribute('data-chx-toggle-detail'));
            if (row) {
                row.hidden = !row.hidden;
            }
        });
    });

    // ------------------------------------------------------------------ modal
    var form = document.getElementById('chxExpenseForm');
    if (!form) {
        return;
    }

    var modalEl = document.getElementById('chxExpenseModal');
    var valInput = form.querySelector('[data-chx-valoare]');
    var tipSelect = form.querySelector('[data-chx-tip]');
    var allocModeInput = form.querySelector('[data-chx-aloc-mode]');
    var allocTipInput = form.querySelector('[data-chx-alocare-tip]');
    var simpleAllocBlock = form.querySelector('[data-chx-simple-alloc]');
    var mixedNote = form.querySelector('[data-chx-mixed-note]');
    var distBlock = form.querySelector('[data-chx-dist-block]');
    var equalInfo = form.querySelector('[data-chx-equal-info]');
    var equalText = form.querySelector('[data-chx-equal-text]');
    var equalPer = form.querySelector('[data-chx-equal-per]');
    var manualList = form.querySelector('[data-chx-manual-list]');
    var allocTotalBar = form.querySelector('[data-chx-alloc-total]');
    var totalAlocatEl = form.querySelector('[data-chx-total-alocat]');
    var totalCheltEl = form.querySelector('[data-chx-total-cheltuiala]');
    var allocStatusEl = form.querySelector('[data-chx-alloc-status]');
    var submitBtn = form.querySelector('[data-chx-submit]');
    var mixedLines = form.querySelector('[data-chx-mixed-lines]');
    var mixedCollapseEl = document.getElementById('chxMixedBody');
    var benefSwitch = form.querySelector('[data-chx-benef-switch]');
    var benefBlock = form.querySelector('[data-chx-benef-block]');
    var mixedLineIndex = 0;

    function currentValue() {
        var raw = parseFloat(String(valInput.value).replace(',', '.'));
        return isNaN(raw) ? 0 : raw;
    }

    function activeCategory() {
        var checked = form.querySelector('input[name="categorie"]:checked');
        return checked ? checked.value : 'administrativa';
    }

    function activeAllocType() {
        var checked = form.querySelector('input[name="alocare_tip_ui"]:checked');
        return checked ? checked.value : 'companie';
    }

    function activeDistribution() {
        var checked = form.querySelector('input[name="distribuire"]:checked');
        return checked ? checked.value : 'egal';
    }

    function selectedEntities(type) {
        var name = type === 'vehicul' ? 'vehicule[]' : 'soferi[]';
        return Array.prototype.slice.call(form.querySelectorAll('input[name="' + name + '"]:checked'));
    }

    function isMixedMode() {
        return mixedLines.children.length > 0;
    }

    // Sincronizeaza tab-urile vizuale (categorie / alocare) cu radio-urile.
    form.querySelectorAll('[data-chx-cat-group] input, [data-chx-alloc-group] input').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var group = radio.closest('[data-chx-cat-group], [data-chx-alloc-group]');
            group.querySelectorAll('.chx-toggle').forEach(function (toggle) {
                toggle.classList.toggle('is-active', toggle.querySelector('input').checked);
            });
            if (group.hasAttribute('data-chx-cat-group')) {
                filterTipOptions();
            } else {
                refreshAllocationUi();
            }
        });
    });

    function filterTipOptions(keepValue) {
        var category = activeCategory();
        var currentTip = keepValue || '';
        Array.prototype.slice.call(tipSelect.options).forEach(function (option) {
            if (!option.value) {
                return;
            }
            var matches = option.getAttribute('data-categorie') === category;
            option.hidden = !matches;
            option.disabled = !matches;
        });
        var selected = tipSelect.options[tipSelect.selectedIndex];
        if (currentTip) {
            tipSelect.value = currentTip;
        } else if (selected && selected.value && selected.disabled) {
            tipSelect.value = '';
        }
    }

    // ------------------------------------------------------------ multiselect
    document.querySelectorAll('[data-chx-multiselect]').forEach(function (wrapper) {
        var chips = wrapper.querySelector('[data-chx-chips]');
        var menu = wrapper.querySelector('.chx-ms-menu');
        var search = wrapper.querySelector('[data-chx-ms-search]');

        function toggleMenu(force) {
            menu.hidden = typeof force === 'boolean' ? !force : !menu.hidden;
        }

        chips.addEventListener('click', function () { toggleMenu(); });
        chips.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggleMenu();
            }
        });
        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) {
                toggleMenu(false);
            }
        });
        if (search) {
            search.addEventListener('input', function () {
                var term = search.value.toLowerCase();
                wrapper.querySelectorAll('.chx-ms-option').forEach(function (option) {
                    option.hidden = option.textContent.toLowerCase().indexOf(term) === -1;
                });
            });
        }
        wrapper.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                renderChips(wrapper);
                refreshAllocationUi();
            });
        });
    });

    function renderChips(wrapper) {
        var chips = wrapper.querySelector('[data-chx-chips]');
        var placeholder = wrapper.getAttribute('data-chx-multiselect') === 'vehicul'
            ? 'Alege unul sau mai multe vehicule...'
            : 'Alege unul sau mai mulți șoferi...';
        var selected = wrapper.querySelectorAll('input[type="checkbox"]:checked');
        chips.innerHTML = '';
        if (selected.length === 0) {
            chips.innerHTML = '<span class="chx-chips-placeholder">' + placeholder + '</span><i class="bi bi-chevron-down" aria-hidden="true"></i>';
            return;
        }
        selected.forEach(function (checkbox) {
            var chip = document.createElement('span');
            chip.className = 'chx-chip';
            chip.innerHTML = '<span></span><button type="button" aria-label="Elimină">&times;</button>';
            chip.querySelector('span').textContent = checkbox.getAttribute('data-label');
            chip.querySelector('button').addEventListener('click', function (event) {
                event.stopPropagation();
                checkbox.checked = false;
                renderChips(wrapper);
                refreshAllocationUi();
            });
            chips.appendChild(chip);
        });
        var caret = document.createElement('i');
        caret.className = 'bi bi-chevron-down';
        caret.setAttribute('aria-hidden', 'true');
        chips.appendChild(caret);
    }

    // ------------------------------------------------- logica alocare simpla
    form.querySelectorAll('input[name="distribuire"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            form.querySelectorAll('.chx-dist-card').forEach(function (card) {
                card.classList.toggle('is-active', card.querySelector('input').checked);
            });
            refreshAllocationUi();
        });
    });

    valInput.addEventListener('input', refreshAllocationUi);

    function refreshAllocationUi() {
        var mixed = isMixedMode();
        var allocType = activeAllocType();
        var value = currentValue();

        allocModeInput.value = mixed ? 'mixt' : 'simplu';
        simpleAllocBlock.hidden = mixed;
        mixedNote.hidden = !mixed;

        // Dezactiveaza inputurile din alocarea simpla cand se folosesc pozitiile mixte,
        // ca sa nu ajunga in POST date contradictorii.
        simpleAllocBlock.querySelectorAll('input, select').forEach(function (field) {
            field.disabled = mixed;
        });

        if (!mixed) {
            allocTipInput.value = allocType;
            form.querySelectorAll('[data-chx-entity-block]').forEach(function (block) {
                var visible = block.getAttribute('data-chx-entity-block') === allocType;
                block.hidden = !visible;
                block.querySelectorAll('input').forEach(function (field) {
                    field.disabled = !visible;
                });
            });

            var entities = allocType === 'companie' ? [] : selectedEntities(allocType);
            var showDist = allocType !== 'companie' && entities.length > 1;
            distBlock.hidden = !showDist;
            distBlock.querySelectorAll('input').forEach(function (field) {
                field.disabled = !showDist;
            });

            if (showDist && activeDistribution() === 'egal') {
                equalInfo.hidden = false;
                manualList.hidden = true;
                manualList.innerHTML = '';
                var per = entities.length > 0 ? value / entities.length : 0;
                var entityWord = allocType === 'vehicul' ? 'vehicule' : 'șoferi';
                equalText.textContent = 'Valoarea totală de ' + formatLei(value) + ' va fi împărțită egal între cele ' + entities.length + ' ' + (allocType === 'vehicul' ? entityWord + ' selectate.' : entityWord + ' selectați.');
                equalPer.textContent = '≈ ' + formatLei(per) + ' / ' + (allocType === 'vehicul' ? 'vehicul' : 'șofer');
            } else if (showDist) {
                equalInfo.hidden = true;
                renderManualInputs(allocType, entities);
            } else {
                equalInfo.hidden = true;
                manualList.hidden = true;
                manualList.innerHTML = '';
            }
        }

        updateTotals();
    }

    function renderManualInputs(allocType, entities) {
        var prefix = allocType === 'vehicul' ? 'suma_vehicul' : 'suma_sofer';
        var existing = {};
        manualList.querySelectorAll('input').forEach(function (input) {
            existing[input.name] = input.value;
        });
        manualList.innerHTML = '';
        manualList.hidden = false;
        entities.forEach(function (checkbox) {
            var name = prefix + '[' + checkbox.value + ']';
            var row = document.createElement('div');
            row.className = 'chx-manual-row';
            row.innerHTML = '<span class="chx-manual-label"></span>' +
                '<div class="input-group input-group-sm chx-manual-input">' +
                '<input type="number" min="0.01" step="0.01" class="form-control" placeholder="0,00">' +
                '<span class="input-group-text">lei</span></div>';
            row.querySelector('.chx-manual-label').textContent = checkbox.getAttribute('data-label');
            var input = row.querySelector('input');
            input.name = name;
            if (existing[name]) {
                input.value = existing[name];
            }
            input.addEventListener('input', updateTotals);
            manualList.appendChild(row);
        });
    }

    function updateTotals() {
        var value = currentValue();
        var mixed = isMixedMode();
        var allocType = activeAllocType();
        var needsBar = false;
        var allocated = 0;

        if (mixed) {
            needsBar = true;
            mixedLines.querySelectorAll('[data-chx-line-suma]').forEach(function (input) {
                var amount = parseFloat(String(input.value).replace(',', '.'));
                if (!isNaN(amount)) {
                    allocated += amount;
                }
            });
        } else if (allocType !== 'companie' && selectedEntities(allocType).length > 1 && activeDistribution() === 'manual') {
            needsBar = true;
            manualList.querySelectorAll('input').forEach(function (input) {
                var amount = parseFloat(String(input.value).replace(',', '.'));
                if (!isNaN(amount)) {
                    allocated += amount;
                }
            });
        }

        allocTotalBar.hidden = !needsBar;
        if (!needsBar) {
            submitBtn.disabled = false;
            return;
        }

        allocated = Math.round(allocated * 100) / 100;
        totalAlocatEl.textContent = formatLei(allocated);
        totalCheltEl.textContent = formatLei(value);

        var matches = Math.abs(allocated - value) <= 0.01 && value > 0;
        allocStatusEl.textContent = matches ? 'Alocarea este completă' : 'Suma alocată trebuie să fie egală cu valoarea cheltuielii';
        allocStatusEl.classList.toggle('is-ok', matches);
        allocStatusEl.classList.toggle('is-error', !matches);
        submitBtn.disabled = !matches;
    }

    // ----------------------------------------------------- pozitii mixte (4)
    form.querySelector('[data-chx-add-line]').addEventListener('click', function () {
        addMixedLine();
    });

    function addMixedLine(preset) {
        var index = mixedLineIndex++;
        var row = document.createElement('div');
        row.className = 'chx-mixed-line';
        row.innerHTML =
            '<select class="form-select form-select-sm" data-chx-line-tip>' +
            '<option value="vehicul">Vehicul</option>' +
            '<option value="sofer">Șofer</option>' +
            '<option value="companie">Companie</option>' +
            '</select>' +
            '<select class="form-select form-select-sm" data-chx-line-entity></select>' +
            '<div class="input-group input-group-sm chx-mixed-suma">' +
            '<input type="number" min="0.01" step="0.01" class="form-control" placeholder="0,00" data-chx-line-suma>' +
            '<span class="input-group-text">lei</span></div>' +
            '<button type="button" class="btn btn-outline-danger btn-sm" aria-label="Șterge poziția"><i class="bi bi-trash" aria-hidden="true"></i></button>';

        var tipField = row.querySelector('[data-chx-line-tip]');
        var entityField = row.querySelector('[data-chx-line-entity]');
        var sumaField = row.querySelector('[data-chx-line-suma]');

        tipField.name = 'pozitii[' + index + '][tip]';
        sumaField.name = 'pozitii[' + index + '][suma]';

        function fillEntityOptions() {
            var tip = tipField.value;
            entityField.innerHTML = '';
            if (tip === 'companie') {
                entityField.hidden = true;
                entityField.name = '';
                entityField.required = false;
                return;
            }
            entityField.hidden = false;
            entityField.required = true;
            entityField.name = 'pozitii[' + index + '][' + (tip === 'vehicul' ? 'vehicul_id' : 'sofer_id') + ']';
            var labels = tip === 'vehicul' ? VEHICLE_LABELS : DRIVER_LABELS;
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = tip === 'vehicul' ? 'Selectează vehiculul' : 'Selectează șoferul';
            entityField.appendChild(placeholder);
            Object.keys(labels).forEach(function (id) {
                var option = document.createElement('option');
                option.value = id;
                option.textContent = labels[id];
                entityField.appendChild(option);
            });
        }

        tipField.addEventListener('change', fillEntityOptions);
        sumaField.addEventListener('input', updateTotals);
        row.querySelector('.btn-outline-danger').addEventListener('click', function () {
            row.remove();
            refreshAllocationUi();
        });

        fillEntityOptions();
        if (preset) {
            tipField.value = preset.tip;
            fillEntityOptions();
            if (preset.tip === 'vehicul') {
                entityField.value = String(preset.vehicul_id);
            } else if (preset.tip === 'sofer') {
                entityField.value = String(preset.sofer_id);
            }
            sumaField.value = preset.suma;
        }

        mixedLines.appendChild(row);
        refreshAllocationUi();
    }

    if (mixedCollapseEl) {
        mixedCollapseEl.addEventListener('shown.bs.collapse', function () {
            if (mixedLines.children.length === 0) {
                addMixedLine();
            }
        });
        mixedCollapseEl.addEventListener('hidden.bs.collapse', function () {
            mixedLines.innerHTML = '';
            refreshAllocationUi();
        });
    }

    // ----------------------------------------------------------- beneficiar
    benefSwitch.addEventListener('change', function () {
        benefBlock.hidden = !benefSwitch.checked;
        benefBlock.querySelector('select').required = benefSwitch.checked;
    });

    form.querySelector('[data-chx-new-supplier]').addEventListener('click', function () {
        var input = form.querySelector('input[name="furnizor"]');
        input.value = '';
        input.focus();
    });

    // ---------------------------------------------------------- add / edit
    function resetForm() {
        form.reset();
        form.action = form.getAttribute('data-store-url');
        form.querySelector('[data-chx-id]').value = '';
        form.querySelector('[data-chx-modal-title]').textContent = 'Adaugă cheltuială';
        submitBtn.textContent = 'Salvează cheltuiala';
        mixedLines.innerHTML = '';
        if (mixedCollapseEl && mixedCollapseEl.classList.contains('show')) {
            bootstrap.Collapse.getOrCreateInstance(mixedCollapseEl).hide();
        }
        form.querySelectorAll('[data-chx-multiselect] input[type="checkbox"]').forEach(function (checkbox) {
            checkbox.checked = false;
        });
        form.querySelectorAll('[data-chx-multiselect]').forEach(renderChips);
        form.querySelectorAll('.chx-toggle-group').forEach(function (group) {
            group.querySelectorAll('.chx-toggle').forEach(function (toggle) {
                toggle.classList.toggle('is-active', toggle.querySelector('input').checked);
            });
        });
        form.querySelectorAll('.chx-dist-card').forEach(function (card) {
            card.classList.toggle('is-active', card.querySelector('input').checked);
        });
        benefBlock.hidden = true;
        benefBlock.querySelector('select').required = false;
        manualList.innerHTML = '';
        filterTipOptions();
        refreshAllocationUi();
    }

    var addButton = document.querySelector('[data-chx-add]');
    if (addButton) {
        addButton.addEventListener('click', resetForm);
    }

    document.querySelectorAll('[data-chx-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            var expense = EXPENSES[button.getAttribute('data-chx-edit')];
            if (!expense) {
                return;
            }
            resetForm();

            form.action = form.getAttribute('data-update-url');
            form.querySelector('[data-chx-id]').value = expense.id;
            form.querySelector('[data-chx-modal-title]').textContent = 'Editează cheltuială';
            submitBtn.textContent = 'Salvează modificările';

            form.querySelector('input[name="categorie"][value="' + expense.categorie + '"]').checked = true;
            form.querySelectorAll('[data-chx-cat-group] .chx-toggle').forEach(function (toggle) {
                toggle.classList.toggle('is-active', toggle.querySelector('input').checked);
            });
            filterTipOptions(String(expense.tip_id));
            form.querySelector('input[name="data_cheltuiala"]').value = expense.data_cheltuiala;
            form.querySelector('input[name="furnizor"]').value = expense.furnizor;
            valInput.value = expense.valoare;
            form.querySelector('input[name="numar_document"]').value = expense.numar_document;
            form.querySelector('textarea[name="observatii"]').value = expense.observatii;

            if (expense.beneficiar_id > 0) {
                benefSwitch.checked = true;
                benefBlock.hidden = false;
                var benefSelect = benefBlock.querySelector('select');
                benefSelect.required = true;
                benefSelect.value = String(expense.beneficiar_id);
            }

            if (expense.alocare_tip === 'mixt') {
                if (mixedCollapseEl) {
                    bootstrap.Collapse.getOrCreateInstance(mixedCollapseEl).show();
                }
                mixedLines.innerHTML = '';
                expense.alocari.forEach(function (allocation) {
                    addMixedLine(allocation);
                });
            } else {
                var allocRadio = form.querySelector('input[name="alocare_tip_ui"][value="' + expense.alocare_tip + '"]');
                if (allocRadio) {
                    allocRadio.checked = true;
                }
                form.querySelectorAll('[data-chx-alloc-group] .chx-toggle').forEach(function (toggle) {
                    toggle.classList.toggle('is-active', toggle.querySelector('input').checked);
                });
                if (expense.alocare_tip === 'vehicul' || expense.alocare_tip === 'sofer') {
                    var name = expense.alocare_tip === 'vehicul' ? 'vehicule[]' : 'soferi[]';
                    expense.alocari.forEach(function (allocation) {
                        var id = expense.alocare_tip === 'vehicul' ? allocation.vehicul_id : allocation.sofer_id;
                        var checkbox = form.querySelector('input[name="' + name + '"][value="' + id + '"]');
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                    form.querySelectorAll('[data-chx-multiselect]').forEach(renderChips);
                    var distRadio = form.querySelector('input[name="distribuire"][value="' + expense.distribuire + '"]');
                    if (distRadio) {
                        distRadio.checked = true;
                    }
                    form.querySelectorAll('.chx-dist-card').forEach(function (card) {
                        card.classList.toggle('is-active', card.querySelector('input').checked);
                    });
                }
            }

            refreshAllocationUi();

            // Sumele manuale se completeaza dupa ce inputurile au fost generate.
            if (expense.alocare_tip !== 'mixt' && expense.distribuire === 'manual') {
                expense.alocari.forEach(function (allocation) {
                    var id = expense.alocare_tip === 'vehicul' ? allocation.vehicul_id : allocation.sofer_id;
                    var prefix = expense.alocare_tip === 'vehicul' ? 'suma_vehicul' : 'suma_sofer';
                    var input = manualList.querySelector('input[name="' + prefix + '[' + id + ']"]');
                    if (input) {
                        input.value = allocation.suma;
                    }
                });
                updateTotals();
            }

            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        });
    });

    filterTipOptions();
    refreshAllocationUi();
})();
</script>
