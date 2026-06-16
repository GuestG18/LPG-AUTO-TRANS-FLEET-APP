<?php
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$kpis = is_array($dashboard['kpis'] ?? null) ? $dashboard['kpis'] : [];
$categoryTotals = is_array($dashboard['category_totals'] ?? null) ? $dashboard['category_totals'] : [];
$monthlyEvolution = is_array($dashboard['monthly_evolution'] ?? null) ? $dashboard['monthly_evolution'] : [];
$typeTotals = is_array($dashboard['type_totals'] ?? null) ? $dashboard['type_totals'] : [];
$rows = is_array($rows ?? null) ? $rows : [];
$documentsByExpense = is_array($documentsByExpense ?? null) ? $documentsByExpense : [];
$categories = is_array($categories ?? null) ? $categories : [];
$manualCategories = is_array($manualCategories ?? null) ? $manualCategories : [];
$filters = is_array($filters ?? null) ? $filters : [];
$pagination = is_array($pagination ?? null) ? $pagination : ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 10];
$paymentMethods = is_array($paymentMethods ?? null) ? $paymentMethods : [];
$documentTypes = is_array($documentTypes ?? null) ? $documentTypes : [];
$rentPaymentStatuses = is_array($rentPaymentStatuses ?? null) ? $rentPaymentStatuses : [];
$sort = (string) ($sort ?? 'data');
$direction = (string) ($direction ?? 'desc');
$subtitle = (string) ($subtitle ?? 'Evidența cheltuielilor administrative și de birou');

$money = static fn(mixed $value): string => format_number_ro((float) ($value ?? 0), 2) . ' lei';
$date = static fn(mixed $value): string => !empty($value) ? format_date_ro((string) $value) : '-';
$datetime = static fn(mixed $value): string => !empty($value) ? format_datetime_ro((string) $value) : '-';
$show = static fn(mixed $value): string => trim((string) ($value ?? '')) !== '' ? trim((string) $value) : '-';
$paymentLabel = static fn(string $method): string => (string) ($paymentMethods[$method] ?? $method);
$documentTypeLabel = static fn(string $type): string => (string) ($documentTypes[$type] ?? $type);
$rentStatusLabel = static fn(?string $status): string => $status !== null && $status !== '' ? (string) ($rentPaymentStatuses[$status] ?? $status) : '-';

$baseQuery = [
    'page' => 'cheltuieli_birou',
    'date_start' => (string) ($filters['date_start'] ?? ''),
    'date_end' => (string) ($filters['date_end'] ?? ''),
    'category_id' => (string) ($filters['category_id'] ?? ''),
    'payment_method' => (string) ($filters['payment_method'] ?? ''),
    'q' => (string) ($filters['q'] ?? ''),
    'sort' => $sort,
    'dir' => $direction,
];

$sortUrl = static function (string $column) use ($baseQuery, $sort, $direction): string {
    $nextDirection = ($sort === $column && $direction === 'asc') ? 'desc' : 'asc';
    return build_query_url(array_merge($baseQuery, ['sort' => $column, 'dir' => $nextDirection, 'p' => 1]));
};
$sortMark = static function (string $column) use ($sort, $direction): string {
    if ($sort !== $column) {
        return '';
    }

    return $direction === 'asc' ? ' ↑' : ' ↓';
};

$totalCategories = 0.0;
foreach ($categoryTotals as $categoryRow) {
    $totalCategories += (float) ($categoryRow['total'] ?? 0);
}

$donutStops = [];
$currentDegree = 0.0;
foreach ($categoryTotals as $index => $categoryRow) {
    $value = (float) ($categoryRow['total'] ?? 0);
    if ($value <= 0 || $totalCategories <= 0) {
        continue;
    }
    $color = trim((string) ($categoryRow['color'] ?? '')) !== '' ? (string) $categoryRow['color'] : '#94a3b8';
    $nextDegree = $currentDegree + ($value / $totalCategories) * 360;
    $donutStops[] = $color . ' ' . format_number_ro($currentDegree, 2) . 'deg ' . format_number_ro($nextDegree, 2) . 'deg';
    $currentDegree = $nextDegree;
}
$donutStyle = $donutStops !== []
    ? 'background: conic-gradient(' . str_replace(',', '.', implode(', ', $donutStops)) . ');'
    : 'background: #e2e8f0;';

$maxEvolution = 0.0;
foreach ($monthlyEvolution as $monthRow) {
    $maxEvolution = max($maxEvolution, (float) ($monthRow['total'] ?? 0));
}
$maxEvolution = $maxEvolution > 0 ? $maxEvolution : 1;
$chartPoints = [];
$chartLabels = [];
$countEvolution = max(1, count($monthlyEvolution));
foreach ($monthlyEvolution as $index => $monthRow) {
    $x = $countEvolution === 1 ? 300 : 40 + ($index * (520 / ($countEvolution - 1)));
    $y = 150 - (((float) ($monthRow['total'] ?? 0) / $maxEvolution) * 115);
    $chartPoints[] = round($x, 2) . ',' . round($y, 2);
    $chartLabels[] = [
        'x' => $x,
        'y' => $y,
        'label' => (string) ($monthRow['label'] ?? ''),
        'value' => (float) ($monthRow['total'] ?? 0),
    ];
}

$categoryBadgeClass = static function (string $slug): string {
    return match ($slug) {
        'chirie-birou' => 'is-blue',
        'utilitati' => 'is-green',
        'salarii-birou' => 'is-yellow',
        'it-si-software', 'internet-telefonie' => 'is-purple',
        'consumabile-birou', 'cafea-apa-protocol' => 'is-orange',
        'produse-curatenie' => 'is-cyan',
        default => 'is-gray',
    };
};

$documentDownloadUrl = static function (array $document): string {
    $id = (int) ($document['id'] ?? $document['document_id'] ?? 0);
    return $id > 0 ? build_query_url(['page' => 'cheltuieli_birou', 'action' => 'download_document', 'document_id' => $id]) : '#';
};

$categoryOptions = static function (?int $selectedId = null) use ($manualCategories): void {
    echo '<option value="">Selectează categoria</option>' . PHP_EOL;
    foreach ($manualCategories as $category) {
        $id = (int) ($category['id'] ?? 0);
        echo '<option value="' . e((string) $id) . '"'
            . ($selectedId !== null && $selectedId === $id ? ' selected' : '')
            . ' data-slug="' . e((string) ($category['slug'] ?? '')) . '">'
            . e((string) ($category['name'] ?? '-'))
            . '</option>' . PHP_EOL;
    }
};

$expenseForm = function (string $mode, ?array $expense = null) use ($categoryOptions, $paymentMethods, $documentTypes, $rentPaymentStatuses): void {
    $isEdit = $mode === 'edit';
    $id = (int) ($expense['id'] ?? 0);
    $modalId = $isEdit ? 'editOfficeExpenseModal' . $id : 'addOfficeExpenseModal';
    $title = $isEdit ? 'Editează cheltuială' : 'Adaugă cheltuială';
    $action = $isEdit ? 'update' : 'store';
    $selectedCategoryId = $isEdit ? (int) ($expense['category_id'] ?? 0) : null;
    ?>
    <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'cheltuieli_birou', 'action' => $action])) ?>" data-office-expense-form>
                    <?= csrf_field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <?php endif; ?>
                    <div class="modal-header">
                        <h3 class="modal-title fs-5"><?= e($title) ?></h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label">Data cheltuielii</label>
                                <input type="date" class="form-control" name="expense_date" value="<?= e((string) ($expense['expense_date'] ?? date('Y-m-d'))) ?>" required>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Categorie</label>
                                <select class="form-select" name="category_id" data-office-expense-category required>
                                    <?php $categoryOptions($selectedCategoryId); ?>
                                </select>
                                <div class="form-text">Salarii birou este calculată automat din Contabilitate Personal.</div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Metodă plată</label>
                                <select class="form-select" name="payment_method" required>
                                    <?php foreach ($paymentMethods as $value => $label): ?>
                                        <option value="<?= e((string) $value) ?>" <?= (string) ($expense['payment_method'] ?? 'transfer_bancar') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-lg-6">
                                <label class="form-label">Descriere</label>
                                <input type="text" class="form-control" name="description" value="<?= e((string) ($expense['description'] ?? '')) ?>" required>
                            </div>
                            <div class="col-12 col-lg-3">
                                <label class="form-label">Furnizor</label>
                                <input type="text" class="form-control" name="supplier" value="<?= e((string) ($expense['supplier'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-lg-3">
                                <label class="form-label">Număr factură / bon</label>
                                <input type="text" class="form-control" name="invoice_number" value="<?= e((string) ($expense['invoice_number'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Sumă fără TVA</label>
                                <input type="number" min="0" step="0.01" class="form-control" name="amount_net" value="<?= e((string) ($expense['amount_net'] ?? '')) ?>" data-office-amount-net>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">TVA</label>
                                <input type="number" min="0" step="0.01" class="form-control" name="vat_amount" value="<?= e((string) ($expense['vat_amount'] ?? '')) ?>" data-office-amount-vat>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Total cu TVA</label>
                                <input type="number" min="0" step="0.01" class="form-control" name="amount_total" value="<?= e((string) ($expense['amount_total'] ?? '')) ?>" data-office-amount-total required>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Tip document</label>
                                <select class="form-select" name="document_type">
                                    <?php foreach ($documentTypes as $value => $label): ?>
                                        <option value="<?= e((string) $value) ?>"><?= e((string) $label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-8">
                                <label class="form-label">Upload document</label>
                                <input type="file" class="form-control" name="document_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                                <?php if ($isEdit): ?>
                                    <div class="form-text">Încărcarea unui document nou îl adaugă la istoricul cheltuielii.</div>
                                <?php endif; ?>
                            </div>

                            <div class="col-12 d-none" data-office-rent-fields>
                                <div class="office-rent-panel">
                                    <div class="fw-semibold mb-3">Detalii chirie birou</div>
                                    <div class="row g-3">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Valoare chirie lunară</label>
                                            <input type="number" min="0" step="0.01" class="form-control" name="monthly_rent_amount" value="<?= e((string) ($expense['monthly_rent_amount'] ?? '')) ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Număr contract</label>
                                            <input type="text" class="form-control" name="contract_number" value="<?= e((string) ($expense['contract_number'] ?? '')) ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Proprietar / firmă</label>
                                            <input type="text" class="form-control" name="landlord_name" value="<?= e((string) ($expense['landlord_name'] ?? '')) ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Perioadă chirie - început</label>
                                            <input type="date" class="form-control" name="rent_period_start" value="<?= e((string) ($expense['rent_period_start'] ?? '')) ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Perioadă chirie - sfârșit</label>
                                            <input type="date" class="form-control" name="rent_period_end" value="<?= e((string) ($expense['rent_period_end'] ?? '')) ?>">
                                        </div>
                                        <div class="col-12 col-md-2">
                                            <label class="form-label">Data scadenței</label>
                                            <input type="date" class="form-control" name="due_date" value="<?= e((string) ($expense['due_date'] ?? '')) ?>">
                                        </div>
                                        <div class="col-12 col-md-2">
                                            <label class="form-label">Status plată</label>
                                            <select class="form-select" name="payment_status">
                                                <option value="">-</option>
                                                <?php foreach ($rentPaymentStatuses as $value => $label): ?>
                                                    <option value="<?= e((string) $value) ?>" <?= (string) ($expense['payment_status'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Observații</label>
                                <textarea class="form-control" name="notes" rows="3"><?= e((string) ($expense['notes'] ?? '')) ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                        <button type="submit" class="btn btn-primary">Salvează</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
};
?>

<div class="office-expenses-page">
    <div class="office-page-title mb-4">
        <div class="office-title-icon"><i class="bi bi-building" aria-hidden="true"></i></div>
        <div>
            <h2 class="h4 mb-1">Cheltuieli Birou</h2>
            <div class="text-primary small"><?= e($subtitle) ?></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="office-kpi h-100">
                <div class="office-kpi-icon is-blue"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
                <div>
                    <div class="office-kpi-label">Total cheltuieli lunare</div>
                    <div class="office-kpi-value"><?= e($money($kpis['total_lunar'] ?? 0)) ?></div>
                    <div class="office-kpi-note">Include Salarii birou automat</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="office-kpi h-100">
                <div class="office-kpi-icon is-green"><i class="bi bi-calendar3" aria-hidden="true"></i></div>
                <div>
                    <div class="office-kpi-label">Total cheltuieli anul curent</div>
                    <div class="office-kpi-value"><?= e($money($kpis['total_an_curent'] ?? 0)) ?></div>
                    <div class="office-kpi-note">Manual + Salarii birou</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="office-kpi h-100">
                <div class="office-kpi-icon is-purple"><i class="bi bi-receipt" aria-hidden="true"></i></div>
                <div>
                    <div class="office-kpi-label">Număr cheltuieli</div>
                    <div class="office-kpi-value"><?= e((string) ((int) ($kpis['numar_cheltuieli'] ?? 0))) ?></div>
                    <div class="office-kpi-note">Înregistrări manuale</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="office-kpi h-100">
                <div class="office-kpi-icon is-orange"><i class="bi bi-clock" aria-hidden="true"></i></div>
                <div>
                    <div class="office-kpi-label">Ultima cheltuială adăugată</div>
                    <?php $latest = is_array($kpis['ultima_cheltuiala'] ?? null) ? $kpis['ultima_cheltuiala'] : null; ?>
                    <div class="office-kpi-value"><?= e($latest !== null ? $money($latest['amount_total'] ?? 0) : '-') ?></div>
                    <div class="office-kpi-note"><?= e($latest !== null ? $datetime($latest['created_at'] ?? null) : 'Nicio cheltuială') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-5">
            <section class="office-panel h-100">
                <h3 class="office-panel-title">Cheltuieli pe categorii <span>(an curent)</span></h3>
                <div class="office-category-panel">
                    <div class="office-donut" style="<?= e($donutStyle) ?>">
                        <div class="office-donut-center">
                            <strong><?= e($money($totalCategories)) ?></strong>
                        </div>
                    </div>
                    <div class="office-category-list">
                        <?php foreach ($categoryTotals as $categoryRow): ?>
                            <?php
                            $value = (float) ($categoryRow['total'] ?? 0);
                            $percent = $totalCategories > 0 ? ($value / $totalCategories) * 100 : 0;
                            $color = trim((string) ($categoryRow['color'] ?? '')) !== '' ? (string) $categoryRow['color'] : '#94a3b8';
                            ?>
                            <div class="office-category-line">
                                <span class="office-dot" style="background: <?= e($color) ?>"></span>
                                <span class="office-category-name"><?= e((string) ($categoryRow['name'] ?? '-')) ?></span>
                                <span class="office-category-amount"><?= e($money($value)) ?></span>
                                <span class="office-category-percent">(<?= e(format_number_ro($percent, 1)) ?>%)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-5">
            <section class="office-panel h-100">
                <h3 class="office-panel-title">Evoluția cheltuielilor <span>(ultimele 6 luni)</span></h3>
                <div class="office-line-chart">
                    <svg viewBox="0 0 600 210" role="img" aria-label="Evoluția cheltuielilor">
                        <line x1="40" y1="35" x2="560" y2="35" />
                        <line x1="40" y1="75" x2="560" y2="75" />
                        <line x1="40" y1="115" x2="560" y2="115" />
                        <line x1="40" y1="155" x2="560" y2="155" />
                        <?php if ($chartPoints !== []): ?>
                            <polyline points="<?= e(implode(' ', $chartPoints)) ?>" />
                            <?php foreach ($chartLabels as $point): ?>
                                <circle cx="<?= e((string) round((float) $point['x'], 2)) ?>" cy="<?= e((string) round((float) $point['y'], 2)) ?>" r="4" />
                                <text x="<?= e((string) round((float) $point['x'], 2)) ?>" y="<?= e((string) max(16, (float) $point['y'] - 12)) ?>" class="value"><?= e(format_number_ro((float) $point['value'], 0)) ?></text>
                                <text x="<?= e((string) round((float) $point['x'], 2)) ?>" y="195" class="label"><?= e((string) $point['label']) ?></text>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </svg>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-2">
            <section class="office-panel h-100">
                <h3 class="office-panel-title">Cheltuieli după tip</h3>
                <?php
                $administrative = (array) ($typeTotals['administrative'] ?? ['total' => 0, 'percent' => 0]);
                $operational = (array) ($typeTotals['operational'] ?? ['total' => 0, 'percent' => 0]);
                ?>
                <div class="office-type-card">
                    <div class="fw-semibold">Administrative / Birou</div>
                    <div><?= e($money($administrative['total'] ?? 0)) ?></div>
                    <div class="office-progress"><span style="width: <?= e((string) min(100, max(0, (float) ($administrative['percent'] ?? 0)))) ?>%"></span></div>
                    <div class="text-end small"><?= e(format_number_ro($administrative['percent'] ?? 0, 1)) ?>%</div>
                </div>
                <div class="office-type-card">
                    <div class="fw-semibold">Operaționale / Flotă</div>
                    <div><?= e($money($operational['total'] ?? 0)) ?></div>
                    <div class="office-progress is-green"><span style="width: <?= e((string) min(100, max(0, (float) ($operational['percent'] ?? 0)))) ?>%"></span></div>
                    <div class="text-end small"><?= e(format_number_ro($operational['percent'] ?? 0, 1)) ?>%</div>
                </div>
                <div class="office-total-company">
                    <span>Total cheltuieli companie</span>
                    <strong><?= e($money($typeTotals['grand_total'] ?? 0)) ?></strong>
                </div>
            </section>
        </div>
    </div>

    <div class="office-actions-row mb-3">
        <div class="office-actions-left">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOfficeExpenseModal">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă cheltuială
            </button>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(array_merge($baseQuery, ['action' => 'export']))) ?>">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        </div>
        <form method="get" class="office-filter-form">
            <input type="hidden" name="page" value="cheltuieli_birou">
            <div class="office-period-filter">
                <input type="date" class="form-control" name="date_start" value="<?= e((string) ($filters['date_start'] ?? '')) ?>" aria-label="Perioadă început">
                <span>-</span>
                <input type="date" class="form-control" name="date_end" value="<?= e((string) ($filters['date_end'] ?? '')) ?>" aria-label="Perioadă sfârșit">
            </div>
            <select class="form-select" name="category_id" aria-label="Categorie">
                <option value="">Toate categoriile</option>
                <?php foreach ($manualCategories as $category): ?>
                    <?php $categoryId = (int) ($category['id'] ?? 0); ?>
                    <option value="<?= e((string) $categoryId) ?>" <?= (int) ($filters['category_id'] ?? 0) === $categoryId ? 'selected' : '' ?>>
                        <?= e((string) ($category['name'] ?? '-')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select class="form-select" name="payment_method" aria-label="Metodă plată">
                <option value="">Toate metodele</option>
                <?php foreach ($paymentMethods as $value => $label): ?>
                    <option value="<?= e((string) $value) ?>" <?= (string) ($filters['payment_method'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="office-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" class="form-control" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Caută...">
            </div>
            <button type="submit" class="btn btn-outline-secondary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filtrează
            </button>
            <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'cheltuieli_birou'])) ?>">Resetează</a>
        </form>
    </div>

    <section class="office-table-panel">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 office-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><a href="<?= e($sortUrl('data')) ?>">Data<?= e($sortMark('data')) ?></a></th>
                        <th><a href="<?= e($sortUrl('categorie')) ?>">Categorie<?= e($sortMark('categorie')) ?></a></th>
                        <th><a href="<?= e($sortUrl('descriere')) ?>">Descriere<?= e($sortMark('descriere')) ?></a></th>
                        <th><a href="<?= e($sortUrl('furnizor')) ?>">Furnizor<?= e($sortMark('furnizor')) ?></a></th>
                        <th><a href="<?= e($sortUrl('suma')) ?>">Sumă<?= e($sortMark('suma')) ?></a></th>
                        <th><a href="<?= e($sortUrl('metoda')) ?>">Metodă plată<?= e($sortMark('metoda')) ?></a></th>
                        <th>Document</th>
                        <th><a href="<?= e($sortUrl('adaugat_de')) ?>">Adăugat de<?= e($sortMark('adaugat_de')) ?></a></th>
                        <th>Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">Nu există cheltuieli în perioada selectată.</td></tr>
                    <?php endif; ?>
                    <?php $rowModals = []; ?>
                    <?php foreach ($rows as $index => $row): ?>
                        <?php
                        $rowId = (int) ($row['id'] ?? 0);
                        $rowNumber = ((int) ($pagination['page'] ?? 1) - 1) * (int) ($pagination['per_page'] ?? 10) + $index + 1;
                        $documents = $documentsByExpense[$rowId] ?? [];
                        $latestDocument = $documents[0] ?? null;
                        $slug = (string) ($row['category_slug'] ?? '');
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= e((string) $rowNumber) ?></td>
                            <td><?= e($date($row['expense_date'] ?? null)) ?></td>
                            <td><span class="office-category-pill <?= e($categoryBadgeClass($slug)) ?>"><?= e((string) ($row['category_name'] ?? '-')) ?></span></td>
                            <td><?= e((string) ($row['description'] ?? '-')) ?></td>
                            <td><?= e($show($row['supplier'] ?? null)) ?></td>
                            <td class="fw-semibold"><?= e($money($row['amount_total'] ?? 0)) ?></td>
                            <td><?= e($paymentLabel((string) ($row['payment_method'] ?? ''))) ?></td>
                            <td>
                                <?php if ((int) ($row['document_count'] ?? 0) > 0): ?>
                                    <button type="button" class="office-doc-button" data-bs-toggle="modal" data-bs-target="#officeExpenseDetails<?= e((string) $rowId) ?>">
                                        <i class="bi bi-folder2-open" aria-hidden="true"></i> <?= e((string) ($row['document_count'] ?? 0)) ?>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($show($row['added_by_name'] ?? null)) ?></td>
                            <td>
                                <div class="office-action-group">
                                    <button type="button" class="office-icon-action" data-bs-toggle="modal" data-bs-target="#officeExpenseDetails<?= e((string) $rowId) ?>" title="Vezi" aria-label="Vezi">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="office-icon-action is-primary" data-bs-toggle="modal" data-bs-target="#editOfficeExpenseModal<?= e((string) $rowId) ?>" title="Editează" aria-label="Editează">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </button>
                                    <?php if (is_array($latestDocument)): ?>
                                        <a class="office-icon-action is-primary" href="<?= e($documentDownloadUrl($latestDocument)) ?>" title="Descarcă document" aria-label="Descarcă document">
                                            <i class="bi bi-download" aria-hidden="true"></i>
                                        </a>
                                    <?php else: ?>
                                        <button type="button" class="office-icon-action" disabled title="Fără document" aria-label="Fără document">
                                            <i class="bi bi-download" aria-hidden="true"></i>
                                        </button>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(build_query_url(['page' => 'cheltuieli_birou', 'action' => 'delete'])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $rowId) ?>">
                                        <button type="submit" class="office-icon-action is-danger" data-confirm="Sigur ștergi această cheltuială?" title="Șterge" aria-label="Șterge">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <?php ob_start(); ?>
                        <div class="modal fade" id="officeExpenseDetails<?= e((string) $rowId) ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h3 class="modal-title fs-5">Detalii cheltuială</h3>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                                    </div>
                                    <div class="modal-body">
                                        <dl class="row mb-0">
                                            <dt class="col-sm-4">Data</dt><dd class="col-sm-8"><?= e($date($row['expense_date'] ?? null)) ?></dd>
                                            <dt class="col-sm-4">Categorie</dt><dd class="col-sm-8"><?= e((string) ($row['category_name'] ?? '-')) ?></dd>
                                            <dt class="col-sm-4">Descriere</dt><dd class="col-sm-8"><?= e((string) ($row['description'] ?? '-')) ?></dd>
                                            <dt class="col-sm-4">Furnizor</dt><dd class="col-sm-8"><?= e($show($row['supplier'] ?? null)) ?></dd>
                                            <dt class="col-sm-4">Sumă fără TVA</dt><dd class="col-sm-8"><?= e($money($row['amount_net'] ?? 0)) ?></dd>
                                            <dt class="col-sm-4">TVA</dt><dd class="col-sm-8"><?= e($money($row['vat_amount'] ?? 0)) ?></dd>
                                            <dt class="col-sm-4">Total cu TVA</dt><dd class="col-sm-8 fw-semibold"><?= e($money($row['amount_total'] ?? 0)) ?></dd>
                                            <dt class="col-sm-4">Metodă plată</dt><dd class="col-sm-8"><?= e($paymentLabel((string) ($row['payment_method'] ?? ''))) ?></dd>
                                            <dt class="col-sm-4">Număr factură / bon</dt><dd class="col-sm-8"><?= e($show($row['invoice_number'] ?? null)) ?></dd>
                                            <?php if ($slug === 'chirie-birou'): ?>
                                                <dt class="col-sm-4">Chirie lunară</dt><dd class="col-sm-8"><?= e($row['monthly_rent_amount'] !== null ? $money($row['monthly_rent_amount']) : '-') ?></dd>
                                                <dt class="col-sm-4">Contract</dt><dd class="col-sm-8"><?= e($show($row['contract_number'] ?? null)) ?></dd>
                                                <dt class="col-sm-4">Perioadă chirie</dt><dd class="col-sm-8"><?= e($date($row['rent_period_start'] ?? null)) ?> - <?= e($date($row['rent_period_end'] ?? null)) ?></dd>
                                                <dt class="col-sm-4">Scadență</dt><dd class="col-sm-8"><?= e($date($row['due_date'] ?? null)) ?></dd>
                                                <dt class="col-sm-4">Status plată</dt><dd class="col-sm-8"><?= e($rentStatusLabel((string) ($row['payment_status'] ?? ''))) ?></dd>
                                                <dt class="col-sm-4">Proprietar / firmă</dt><dd class="col-sm-8"><?= e($show($row['landlord_name'] ?? null)) ?></dd>
                                            <?php endif; ?>
                                            <dt class="col-sm-4">Observații</dt><dd class="col-sm-8"><?= nl2br(e($show($row['notes'] ?? null))) ?></dd>
                                        </dl>
                                        <hr>
                                        <h4 class="h6">Documente</h4>
                                        <?php if ($documents === []): ?>
                                            <p class="text-muted mb-0">Nu există documente încărcate.</p>
                                        <?php else: ?>
                                            <div class="list-group">
                                                <?php foreach ($documents as $document): ?>
                                                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?= e($documentDownloadUrl($document)) ?>">
                                                        <span><?= e($documentTypeLabel((string) ($document['document_type'] ?? ''))) ?> - <?= e($show($document['original_name'] ?? null)) ?></span>
                                                        <i class="bi bi-download" aria-hidden="true"></i>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php $expenseForm('edit', $row); ?>
                        <?php $rowModals[] = ob_get_clean(); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="office-table-footer">
            <small class="text-muted">
                Se afișează
                <?= e((string) min((int) ($pagination['total_rows'] ?? 0), ((int) ($pagination['page'] ?? 1) - 1) * (int) ($pagination['per_page'] ?? 10) + 1)) ?>
                -
                <?= e((string) min((int) ($pagination['total_rows'] ?? 0), (int) ($pagination['page'] ?? 1) * (int) ($pagination['per_page'] ?? 10))) ?>
                din <?= e((string) ($pagination['total_rows'] ?? 0)) ?> cheltuieli
            </small>
            <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
                <nav aria-label="Paginare cheltuieli birou">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $currentPageNo = (int) ($pagination['page'] ?? 1);
                        $totalPages = (int) ($pagination['total_pages'] ?? 1);
                        ?>
                        <li class="page-item <?= $currentPageNo <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => max(1, $currentPageNo - 1)]))) ?>"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                        </li>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $currentPageNo === $i ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $i]))) ?>"><?= e((string) $i) ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $currentPageNo >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => min($totalPages, $currentPageNo + 1)]))) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </section>
</div>

<?= implode('', $rowModals ?? []) ?>
<?php $expenseForm('add'); ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function numberValue(input) {
        var value = input && input.value ? input.value.replace(',', '.') : '';
        var parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    document.querySelectorAll('[data-office-expense-form]').forEach(function (form) {
        var categorySelect = form.querySelector('[data-office-expense-category]');
        var rentFields = form.querySelector('[data-office-rent-fields]');
        var netInput = form.querySelector('[data-office-amount-net]');
        var vatInput = form.querySelector('[data-office-amount-vat]');
        var totalInput = form.querySelector('[data-office-amount-total]');
        var totalTouched = false;

        function syncRentFields() {
            if (!categorySelect || !rentFields) {
                return;
            }
            var option = categorySelect.options[categorySelect.selectedIndex];
            var isRent = option && option.getAttribute('data-slug') === 'chirie-birou';
            rentFields.classList.toggle('d-none', !isRent);
        }

        function syncTotal() {
            if (!totalInput || totalTouched) {
                return;
            }
            var total = numberValue(netInput) + numberValue(vatInput);
            if (total > 0) {
                totalInput.value = total.toFixed(2);
            }
        }

        if (categorySelect) {
            categorySelect.addEventListener('change', syncRentFields);
            syncRentFields();
        }
        if (netInput) {
            netInput.addEventListener('input', syncTotal);
        }
        if (vatInput) {
            vatInput.addEventListener('input', syncTotal);
        }
        if (totalInput) {
            totalInput.addEventListener('input', function () {
                totalTouched = true;
            });
        }
    });
});
</script>
