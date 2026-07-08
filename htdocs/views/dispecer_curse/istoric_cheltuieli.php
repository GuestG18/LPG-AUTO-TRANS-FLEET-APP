<?php
$filters = is_array($filters ?? null) ? $filters : [];
$rows = is_array($rows ?? null) ? $rows : [];
$summary = is_array($summary ?? null) ? $summary : [];
$charts = is_array($charts ?? null) ? $charts : [];
$pagination = is_array($pagination ?? null) ? $pagination : [];
$options = is_array($options ?? null) ? $options : [];
$canManageCategories = !empty($canManageCategories);
$returnUrl = (string) ($_SERVER['REQUEST_URI'] ?? build_query_url(['page' => 'istoric_cheltuieli_curse']));

$formatDateSlash = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }
    try {
        return (new DateTimeImmutable($value))->format('d/m/Y');
    } catch (Throwable) {
        return $value;
    }
};

$formatMoney = static function (float $value): string {
    return format_number_ro($value, 2) . ' lei';
};

$raceCode = static function (int $raceId, string $raceDate = ''): string {
    $year = date('Y');
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raceDate)) {
        $year = substr($raceDate, 0, 4);
    }
    return 'CURS-' . $year . '-' . str_pad((string) $raceId, 4, '0', STR_PAD_LEFT);
};

$filterBase = [
    'page' => 'istoric_cheltuieli_curse',
    'q' => $filters['q'] ?? '',
    'category_id' => $filters['category_id'] ?? '',
    'vehicle_id' => $filters['vehicle_id'] ?? '',
    'driver_id' => $filters['driver_id'] ?? '',
    'race_id' => $filters['race_id'] ?? '',
    'date_from' => $filters['date_from'] ?? '',
    'date_to' => $filters['date_to'] ?? '',
    'document_state' => $filters['document_state'] ?? '',
    'per_page' => $pagination['per_page'] ?? 10,
];

$chartJson = json_encode($charts, JSON_UNESCAPED_UNICODE);
if (!is_string($chartJson)) {
    $chartJson = '{}';
}

$activeCategories = is_array($options['active_categories'] ?? null) ? $options['active_categories'] : [];
$allCategories = is_array($options['categories'] ?? null) ? $options['categories'] : [];
$vehicles = is_array($options['vehicles'] ?? null) ? $options['vehicles'] : [];
$drivers = is_array($options['drivers'] ?? null) ? $options['drivers'] : [];
$filterRaces = is_array($options['races'] ?? null) ? $options['races'] : [];
$addExpenseRaces = is_array($options['add_expense_races'] ?? null) ? $options['add_expense_races'] : [];
$detailModals = '';

$kpis = [
    [
        'tone' => 'green',
        'icon' => 'bi-graph-up-arrow',
        'title' => 'Venit total pe curse',
        'value' => $formatMoney((float) ($summary['venit_total'] ?? 0)),
        'note' => 'in perioada selectata',
    ],
    [
        'tone' => 'red',
        'icon' => 'bi-wallet2',
        'title' => 'Cheltuieli totale',
        'value' => $formatMoney((float) ($summary['cheltuieli_totale'] ?? 0)),
        'note' => 'in perioada selectata',
    ],
    [
        'tone' => 'blue',
        'icon' => 'bi-bar-chart-line',
        'title' => 'Profit total',
        'value' => $formatMoney((float) ($summary['profit_total'] ?? 0)),
        'note' => 'in perioada selectata',
    ],
    [
        'tone' => 'orange',
        'icon' => 'bi-pie-chart',
        'title' => 'Marja profit',
        'value' => format_number_ro((float) ($summary['marja_profit'] ?? 0), 1) . '%',
        'note' => 'in perioada selectata',
    ],
    [
        'tone' => 'purple',
        'icon' => 'bi-list-task',
        'title' => 'Numar curse',
        'value' => (string) (int) ($summary['numar_curse'] ?? 0),
        'note' => 'in perioada selectata',
    ],
];
?>

<div class="course-expense-history-page" data-course-expense-charts='<?= e($chartJson) ?>'>
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h2 class="h4 mb-1">Istoric cheltuieli curse</h2>
            <div class="small text-muted">
                Dispecer curse <i class="bi bi-chevron-right mx-1" aria-hidden="true"></i> Istoric cheltuieli curse
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#courseExpenseCategoriesModal">
                <i class="bi bi-gear me-1" aria-hidden="true"></i> Categorii cheltuieli
            </button>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#courseExpenseAddModal">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Adauga cheltuiala
            </button>
        </div>
    </div>

    <div class="course-expense-filter-card mb-3">
        <form method="get" class="course-expense-filter-grid">
            <input type="hidden" name="page" value="istoric_cheltuieli_curse">
            <div class="course-expense-filter-field is-search">
                <label class="form-label visually-hidden" for="course_expense_q">Cautare</label>
                <div class="course-expense-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input class="form-control" id="course_expense_q" type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Cauta dupa cuvant cheie...">
                </div>
            </div>
            <div class="course-expense-filter-field">
                <label class="form-label" for="course_expense_category">Tip cheltuiala</label>
                <select class="form-select" id="course_expense_category" name="category_id">
                    <option value="">Toate tipurile</option>
                    <?php foreach ($activeCategories as $category): ?>
                        <?php $categoryId = (int) ($category['id'] ?? 0); ?>
                        <?php if ($categoryId <= 0): continue; endif; ?>
                        <option value="<?= e((string) $categoryId) ?>" <?= (int) ($filters['category_id'] ?? 0) === $categoryId ? 'selected' : '' ?>>
                            <?= e((string) ($category['nume'] ?? '-')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="course-expense-filter-field">
                <label class="form-label" for="course_expense_vehicle">Vehicul</label>
                <select class="form-select" id="course_expense_vehicle" name="vehicle_id">
                    <option value="">Toate vehiculele</option>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <?php $vehicleId = (int) ($vehicle['id'] ?? 0); ?>
                        <?php if ($vehicleId <= 0): continue; endif; ?>
                        <option value="<?= e((string) $vehicleId) ?>" <?= (int) ($filters['vehicle_id'] ?? 0) === $vehicleId ? 'selected' : '' ?>>
                            <?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="course-expense-filter-field">
                <label class="form-label" for="course_expense_driver">Sofer</label>
                <select class="form-select" id="course_expense_driver" name="driver_id">
                    <option value="">Toti soferii</option>
                    <?php foreach ($drivers as $driver): ?>
                        <?php $driverId = (int) ($driver['id'] ?? 0); ?>
                        <?php if ($driverId <= 0): continue; endif; ?>
                        <option value="<?= e((string) $driverId) ?>" <?= (int) ($filters['driver_id'] ?? 0) === $driverId ? 'selected' : '' ?>>
                            <?= e((string) ($driver['nume'] ?? '-')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="course-expense-filter-field">
                <label class="form-label" for="course_expense_race">Cursa asociata</label>
                <select class="form-select" id="course_expense_race" name="race_id">
                    <option value="">Toate cursele</option>
                    <?php foreach ($filterRaces as $race): ?>
                        <?php $raceId = (int) ($race['id'] ?? 0); ?>
                        <?php if ($raceId <= 0): continue; endif; ?>
                        <option value="<?= e((string) $raceId) ?>" <?= (int) ($filters['race_id'] ?? 0) === $raceId ? 'selected' : '' ?>>
                            <?= e($raceCode($raceId, (string) ($race['data_inceput'] ?? ''))) ?> - <?= e((string) ($race['nr_inmatriculare'] ?? '-')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="course-expense-filter-field">
                <label class="form-label" for="course_expense_date_from">De la</label>
                <input class="form-control" id="course_expense_date_from" type="date" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
            </div>
            <div class="course-expense-filter-field">
                <label class="form-label" for="course_expense_date_to">Pana la</label>
                <input class="form-control" id="course_expense_date_to" type="date" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
            </div>
            <div class="course-expense-filter-field">
                <label class="form-label" for="course_expense_document">Are document</label>
                <select class="form-select" id="course_expense_document" name="document_state">
                    <option value="" <?= (string) ($filters['document_state'] ?? '') === '' ? 'selected' : '' ?>>Toate</option>
                    <option value="cu" <?= (string) ($filters['document_state'] ?? '') === 'cu' ? 'selected' : '' ?>>Cu document</option>
                    <option value="fara" <?= (string) ($filters['document_state'] ?? '') === 'fara' ? 'selected' : '' ?>>Fara document</option>
                </select>
            </div>
            <div class="course-expense-filter-actions">
                <button type="submit" class="btn course-expense-filter-btn"><i class="bi bi-funnel" aria-hidden="true"></i> Filtreaza</button>
                <a class="btn course-expense-reset-btn" href="<?= e(build_query_url(['page' => 'istoric_cheltuieli_curse'])) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reseteaza</a>
                <button type="submit" class="btn course-expense-export-btn" name="action" value="export"><i class="bi bi-file-earmark-spreadsheet-fill" aria-hidden="true"></i> Exporta Excel</button>
            </div>
        </form>
    </div>

    <div class="course-expense-kpi-grid mb-3">
        <?php foreach ($kpis as $kpi): ?>
            <article class="course-expense-kpi is-<?= e($kpi['tone']) ?>">
                <span class="course-expense-kpi-icon"><i class="bi <?= e($kpi['icon']) ?>" aria-hidden="true"></i></span>
                <div>
                    <div class="course-expense-kpi-title"><?= e($kpi['title']) ?></div>
                    <strong><?= e($kpi['value']) ?></strong>
                    <small><?= e($kpi['note']) ?></small>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="course-expense-table-card mb-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 course-expense-table">
                <thead>
                <tr>
                    <th>Data</th>
                    <th>Cursa</th>
                    <th>Vehicul</th>
                    <th>Sofer</th>
                    <th class="text-end">Venit cursa</th>
                    <th class="text-end">Cheltuieli totale</th>
                    <th class="text-end">Profit</th>
                    <th class="text-center">Marja profit</th>
                    <th>Top categorie cheltuiala</th>
                    <th class="text-end">Actiuni</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">Nu exista rezultate pentru filtrele selectate.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $rowRaceId = (int) ($row['id'] ?? 0);
                        $rowRevenue = (float) ($row['venit_cursa'] ?? 0);
                        $rowExpenses = (float) ($row['total_cheltuieli'] ?? 0);
                        $rowProfit = (float) ($row['profit'] ?? ($rowRevenue - $rowExpenses));
                        $rowMargin = (float) ($row['marja_profit'] ?? 0);
                        $marginClass = $rowMargin >= 90 ? 'is-good' : ($rowMargin >= 70 ? 'is-medium' : 'is-low');
                        $topCategory = is_array($row['top_categorie'] ?? null) ? $row['top_categorie'] : null;
                        $details = is_array($row['expenses'] ?? null) ? $row['expenses'] : [];
                        $detailModalId = 'courseExpenseDetailsModal' . $rowRaceId;
                        ?>
                        <tr>
                            <td><?= e($formatDateSlash((string) ($row['data_cursa'] ?? ''))) ?></td>
                            <td class="fw-semibold"><?= e($raceCode($rowRaceId, (string) ($row['data_cursa'] ?? ''))) ?></td>
                            <td><?= e((string) ($row['nr_inmatriculare'] ?? '-')) ?></td>
                            <td><?= e((string) ($row['sofer_nume'] ?? '-')) ?></td>
                            <td class="text-end"><?= e($formatMoney($rowRevenue)) ?></td>
                            <td class="text-end"><?= e($formatMoney($rowExpenses)) ?></td>
                            <td class="text-end <?= $rowProfit >= 0 ? 'text-success' : 'text-danger' ?> fw-semibold"><?= e($formatMoney($rowProfit)) ?></td>
                            <td class="text-center"><span class="course-expense-margin <?= e($marginClass) ?>"><?= e(format_number_ro($rowMargin, 1)) ?>%</span></td>
                            <td>
                                <?php if ($topCategory !== null): ?>
                                    <?= e((string) ($topCategory['nume'] ?? '-')) ?> (<?= e($formatMoney((float) ($topCategory['suma'] ?? 0))) ?>)
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="course-expense-actions">
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#<?= e($detailModalId) ?>" title="Vezi detalii" aria-label="Vezi detalii">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </button>
                                    <a class="btn btn-sm btn-outline-danger" href="<?= e(build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $rowRaceId]) . '#expense-section') ?>" title="Editeaza cursa" aria-label="Editeaza cursa">
                                        <i class="bi bi-graph-up" aria-hidden="true"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#<?= e($detailModalId) ?>" title="Defalcare cheltuieli" aria-label="Defalcare cheltuieli">
                                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <?php ob_start(); ?>
                        <div class="modal fade" id="<?= e($detailModalId) ?>" tabindex="-1" aria-labelledby="<?= e($detailModalId) ?>Title" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="<?= e($detailModalId) ?>Title">Detalii cheltuieli - <?= e($raceCode($rowRaceId, (string) ($row['data_cursa'] ?? ''))) ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle mb-0">
                                                <thead>
                                                <tr>
                                                    <th>Data</th>
                                                    <th>Categorie</th>
                                                    <th class="text-end">Suma</th>
                                                    <th>Document</th>
                                                    <th>Observatii</th>
                                                    <th>Adaugat de</th>
                                                    <th>Creat la</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php if ($details === []): ?>
                                                    <tr><td colspan="7" class="text-center text-muted py-3">Nu exista detalii.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($details as $detail): ?>
                                                        <?php
                                                        $docPath = trim((string) ($detail['file_path'] ?? ''));
                                                        $docName = trim((string) ($detail['original_name'] ?? ''));
                                                        $docUrl = $docPath !== '' ? url('uploads/curse_cheltuieli/' . rawurlencode($docPath)) : null;
                                                        ?>
                                                        <tr>
                                                            <td><?= e($formatDateSlash((string) ($detail['data_cheltuiala'] ?? ''))) ?></td>
                                                            <td><?= e((string) ($detail['categorie_nume'] ?? '-')) ?></td>
                                                            <td class="text-end"><?= e($formatMoney((float) ($detail['suma'] ?? 0))) ?></td>
                                                            <td>
                                                                <?php if ($docUrl !== null): ?>
                                                                    <a class="btn btn-sm btn-outline-secondary" href="<?= e($docUrl) ?>" target="_blank" rel="noopener"><?= e($docName !== '' ? $docName : basename($docPath)) ?></a>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?= nl2br(e((string) ($detail['observatii'] ?? '-'))) ?></td>
                                                            <td><?= e(trim((string) ($detail['added_by_nume'] ?? '')) !== '' ? (string) $detail['added_by_nume'] : '-') ?></td>
                                                            <td><?= e(format_datetime_ro((string) ($detail['created_at'] ?? ''))) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php $detailModals .= (string) ob_get_clean(); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="course-expense-table-footer">
            <span>Afisare <?= e((string) min((int) ($pagination['total_rows'] ?? 0), ((int) ($pagination['page'] ?? 1) - 1) * (int) ($pagination['per_page'] ?? 10) + 1)) ?> - <?= e((string) min((int) ($pagination['total_rows'] ?? 0), (int) ($pagination['page'] ?? 1) * (int) ($pagination['per_page'] ?? 10))) ?> din <?= e((string) ($pagination['total_rows'] ?? 0)) ?> rezultate</span>
            <div class="d-flex align-items-center gap-2">
                <?php
                $currentPageIndex = (int) ($pagination['page'] ?? 1);
                $totalPages = (int) ($pagination['total_pages'] ?? 1);
                ?>
                <nav aria-label="Paginare istoric cheltuieli">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $currentPageIndex <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($filterBase, ['p' => max(1, $currentPageIndex - 1)]))) ?>"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                        </li>
                        <?php for ($p = max(1, $currentPageIndex - 2); $p <= min($totalPages, $currentPageIndex + 2); $p++): ?>
                            <li class="page-item <?= $p === $currentPageIndex ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($filterBase, ['p' => $p]))) ?>"><?= e((string) $p) ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $currentPageIndex >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($filterBase, ['p' => min($totalPages, $currentPageIndex + 1)]))) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                        </li>
                    </ul>
                </nav>
                <form method="get" class="d-flex">
                    <?php foreach ($filterBase as $key => $value): ?>
                        <?php if ($key === 'per_page'): continue; endif; ?>
                        <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
                    <?php endforeach; ?>
                    <select class="form-select form-select-sm" name="per_page" onchange="this.form.submit()">
                        <?php foreach ([10, 25, 50, 100] as $perPageOption): ?>
                            <option value="<?= e((string) $perPageOption) ?>" <?= (int) ($pagination['per_page'] ?? 10) === $perPageOption ? 'selected' : '' ?>><?= e((string) $perPageOption) ?> / pagina</option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>
    </div>

    <?= $detailModals ?>

    <div class="course-expense-analytics-grid">
        <section class="course-expense-chart-card">
            <h3>Cheltuieli pe categorii</h3>
            <div class="course-expense-chart-body is-donut">
                <div class="course-expense-donut-canvas"><canvas id="courseExpenseCategoryDonut"></canvas></div>
                <div class="course-expense-category-list" id="courseExpenseCategoryLegend"></div>
            </div>
            <div class="course-expense-chart-total">Total: <?= e($formatMoney((float) (($charts['categories']['total'] ?? 0)))) ?></div>
        </section>
        <section class="course-expense-chart-card">
            <h3>Cheltuieli pe categorii</h3>
            <div class="course-expense-chart-wrap"><canvas id="courseExpenseCategoryBars"></canvas></div>
        </section>
        <section class="course-expense-chart-card">
            <h3>Top 5 curse dupa profit</h3>
            <div class="course-expense-chart-wrap"><canvas id="courseExpenseTopProfit"></canvas></div>
        </section>
        <section class="course-expense-chart-card">
            <h3>Evolutie profit pe zile</h3>
            <div class="course-expense-chart-wrap"><canvas id="courseExpenseDailyProfit"></canvas></div>
        </section>
    </div>
</div>

<div class="modal fade" id="courseExpenseAddModal" tabindex="-1" aria-labelledby="courseExpenseAddModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="<?= e(build_query_url(['page' => 'istoric_cheltuieli_curse', 'action' => 'store_expense'])) ?>" enctype="multipart/form-data" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="courseExpenseAddModalTitle">Adauga cheltuiala</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
                </div>
                <div class="modal-body">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="add_expense_race_id">Cursa <span class="text-danger">*</span></label>
                            <select class="form-select" id="add_expense_race_id" name="race_id" data-role="course-expense-race-select" required>
                                <option value="">-- Selecteaza cursa --</option>
                                <?php foreach ($addExpenseRaces as $race): ?>
                                    <?php $raceId = (int) ($race['id'] ?? 0); ?>
                                    <?php if ($raceId <= 0): continue; endif; ?>
                                    <option
                                        value="<?= e((string) $raceId) ?>"
                                        data-vehicle="<?= e((string) ($race['nr_inmatriculare'] ?? '-')) ?>"
                                        data-driver="<?= e(trim((string) ($race['sofer_nume'] ?? '')) !== '' ? (string) $race['sofer_nume'] : '-') ?>"
                                    >
                                        <?= e($raceCode($raceId, (string) ($race['data_inceput'] ?? ''))) ?> - <?= e((string) ($race['nr_inmatriculare'] ?? '-')) ?> - <?= e($formatDateSlash((string) ($race['data_inceput'] ?? ''))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="add_expense_vehicle_display">Vehicul</label>
                            <input class="form-control" id="add_expense_vehicle_display" type="text" data-role="course-expense-vehicle-display" readonly>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="add_expense_driver_display">Sofer</label>
                            <input class="form-control" id="add_expense_driver_display" type="text" data-role="course-expense-driver-display" readonly>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="add_expense_category_id">Categorie <span class="text-danger">*</span></label>
                            <select class="form-select" id="add_expense_category_id" name="categorie_id" required>
                                <option value="">-- Selecteaza categoria --</option>
                                <?php foreach ($activeCategories as $category): ?>
                                    <?php $categoryId = (int) ($category['id'] ?? 0); ?>
                                    <?php if ($categoryId <= 0): continue; endif; ?>
                                    <option value="<?= e((string) $categoryId) ?>"><?= e((string) ($category['nume'] ?? '-')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="add_expense_suma">Suma <span class="text-danger">*</span></label>
                            <input class="form-control" id="add_expense_suma" type="number" name="suma" min="0.01" step="0.01" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="add_expense_data">Data cheltuiala <span class="text-danger">*</span></label>
                            <input class="form-control" id="add_expense_data" type="date" name="data_cheltuiala" value="<?= e(date('Y-m-d')) ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="add_expense_document">Document doveditor</label>
                            <input class="form-control" id="add_expense_document" type="file" name="document_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                            <div class="form-text">PDF, JPG, PNG, WEBP, DOC, DOCX. Maxim 5 MB.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="add_expense_notes">Observatii</label>
                            <textarea class="form-control" id="add_expense_notes" name="observatii" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuleaza</button>
                    <button type="submit" class="btn btn-primary">Salveaza cheltuiala</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="courseExpenseCategoriesModal" tabindex="-1" aria-labelledby="courseExpenseCategoriesModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="courseExpenseCategoriesModalTitle">Categorii cheltuieli</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Inchide"></button>
            </div>
            <div class="modal-body">
                <?php if ($canManageCategories): ?>
                    <form method="post" action="<?= e(build_query_url(['page' => 'istoric_cheltuieli_curse', 'action' => 'store_category'])) ?>" class="course-expense-category-new mb-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                        <div>
                            <label class="form-label" for="new_category_name">Categorie noua</label>
                            <input class="form-control" id="new_category_name" name="nume" maxlength="150" required>
                        </div>
                        <div>
                            <label class="form-label" for="new_category_description">Descriere</label>
                            <input class="form-control" id="new_category_description" name="descriere">
                        </div>
                        <label class="form-check course-expense-category-active">
                            <input class="form-check-input" type="checkbox" name="activ" value="1" checked>
                            <span class="form-check-label">Activa</span>
                        </label>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Adauga</button>
                    </form>
                <?php endif; ?>

                <div class="course-expense-category-list-edit">
                    <?php foreach ($allCategories as $category): ?>
                        <?php
                        $categoryId = (int) ($category['id'] ?? 0);
                        if ($categoryId <= 0) {
                            continue;
                        }
                        $usageCount = (int) ($category['usage_count'] ?? 0);
                        $isActive = (int) ($category['activ'] ?? 0) === 1;
                        ?>
                        <div class="course-expense-category-row">
                            <?php if ($canManageCategories): ?>
                                <form method="post" action="<?= e(build_query_url(['page' => 'istoric_cheltuieli_curse', 'action' => 'update_category'])) ?>" class="course-expense-category-edit-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                                    <input type="hidden" name="id" value="<?= e((string) $categoryId) ?>">
                                    <input class="form-control" name="nume" maxlength="150" value="<?= e((string) ($category['nume'] ?? '')) ?>" required>
                                    <input class="form-control" name="descriere" value="<?= e((string) ($category['descriere'] ?? '')) ?>" placeholder="Descriere optionala">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="activ" value="1" <?= $isActive ? 'checked' : '' ?>>
                                        <span class="form-check-label"><?= $isActive ? 'Activa' : 'Inactiva' ?></span>
                                    </label>
                                    <span class="badge text-bg-light border"><?= e((string) $usageCount) ?> utilizari</span>
                                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i></button>
                                </form>
                                <div class="course-expense-category-row-actions">
                                    <?php if ($usageCount > 0): ?>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'istoric_cheltuieli_curse', 'action' => 'archive_category'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                                            <input type="hidden" name="id" value="<?= e((string) $categoryId) ?>">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Arhiveaza</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'istoric_cheltuieli_curse', 'action' => 'delete_category'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                                            <input type="hidden" name="id" value="<?= e((string) $categoryId) ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="Stergi categoria selectata?"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="course-expense-category-readonly">
                                    <strong><?= e((string) ($category['nume'] ?? '-')) ?></strong>
                                    <span><?= e((string) ($category['descriere'] ?? '')) ?></span>
                                    <span class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $isActive ? 'Activa' : 'Inactiva' ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= e(url('assets/js/istoric-cheltuieli-curse.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/istoric-cheltuieli-curse.js'))) ?>"></script>
