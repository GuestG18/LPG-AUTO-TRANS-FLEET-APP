<?php
$dashboard = is_array($dashboard ?? null) ? $dashboard : [];
$kpis = is_array($dashboard['kpis'] ?? null) ? $dashboard['kpis'] : [];
$categoryTotals = is_array($dashboard['category_totals'] ?? null) ? $dashboard['category_totals'] : [];
$monthlyEvolution = is_array($dashboard['monthly_evolution'] ?? null) ? $dashboard['monthly_evolution'] : [];
$rows = is_array($rows ?? null) ? $rows : [];
$documentsByExpense = is_array($documentsByExpense ?? null) ? $documentsByExpense : [];
$categories = is_array($categories ?? null) ? $categories : [];
$filters = is_array($filters ?? null) ? $filters : [];
$pagination = is_array($pagination ?? null) ? $pagination : ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 10];
$paymentMethods = is_array($paymentMethods ?? null) ? $paymentMethods : [];
$documentTypes = is_array($documentTypes ?? null) ? $documentTypes : [];
$sort = (string) ($sort ?? 'data');
$direction = (string) ($direction ?? 'desc');
$subtitle = (string) ($subtitle ?? 'Evidența cheltuielilor administrative care nu sunt asociate cu o locație');

$money = static fn(mixed $value): string => format_number_ro((float) ($value ?? 0), 2) . ' lei';
$moneyShort = static fn(mixed $value): string => format_number_ro((float) ($value ?? 0), 0);
$date = static fn(mixed $value): string => !empty($value) ? format_date_ro((string) $value) : '-';
$datetime = static fn(mixed $value): string => !empty($value) ? format_datetime_ro((string) $value) : '-';
$show = static fn(mixed $value): string => trim((string) ($value ?? '')) !== '' ? trim((string) $value) : '-';
$paymentLabel = static fn(string $method): string => (string) ($paymentMethods[$method] ?? $method);
$documentTypeLabel = static fn(string $type): string => (string) ($documentTypes[$type] ?? $type);

/* Validated categorical palette (CVD-safe ordering) - keyed by category slug. */
$categoryPalette = [
    'taxe-impozite' => '#2a78d6',
    'asigurari-firma' => '#1baf7a',
    'contabilitate-audit' => '#eda100',
    'consultanta-juridica' => '#008300',
    'licente-autorizatii' => '#4a3aa7',
    'deplasari-protocol' => '#e34948',
    'marketing-publicitate' => '#e87ba4',
    'comisioane-bancare-admin' => '#eb6834',
    'resurse-umane-training' => '#184f95',
    'alte-cheltuieli-administrative' => '#9a6b1f',
];
$categoryColor = static function (?string $slug, ?string $fallback = null) use ($categoryPalette): string {
    $slug = (string) $slug;
    if (isset($categoryPalette[$slug])) {
        return $categoryPalette[$slug];
    }
    $fallback = trim((string) $fallback);
    return $fallback !== '' ? $fallback : '#898781';
};

$baseQuery = [
    'page' => 'cheltuieli_administrative',
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

/* ---- Donut chart data ---- */
$totalCategories = 0.0;
foreach ($categoryTotals as $categoryRow) {
    $totalCategories += (float) ($categoryRow['total'] ?? 0);
}

$donutSegments = [];
foreach ($categoryTotals as $categoryRow) {
    $value = (float) ($categoryRow['total'] ?? 0);
    if ($value <= 0 || $totalCategories <= 0) {
        continue;
    }
    $donutSegments[] = [
        'color' => $categoryColor((string) ($categoryRow['slug'] ?? ''), (string) ($categoryRow['color'] ?? '')),
        'value' => $value,
    ];
}

$donutStops = [];
$gap = count($donutSegments) > 1 ? 1.6 : 0.0; /* degrees of white gap between segments */
$currentDegree = 0.0;
foreach ($donutSegments as $segment) {
    $sweep = ($segment['value'] / $totalCategories) * 360;
    $startColor = $currentDegree + ($gap / 2);
    $endColor = max($startColor, $currentDegree + $sweep - ($gap / 2));
    if ($gap > 0) {
        $donutStops[] = '#ffffff ' . number_format($currentDegree, 2, '.', '') . 'deg ' . number_format($startColor, 2, '.', '') . 'deg';
    }
    $donutStops[] = $segment['color'] . ' ' . number_format($startColor, 2, '.', '') . 'deg ' . number_format($endColor, 2, '.', '') . 'deg';
    if ($gap > 0) {
        $donutStops[] = '#ffffff ' . number_format($endColor, 2, '.', '') . 'deg ' . number_format($currentDegree + $sweep, 2, '.', '') . 'deg';
    }
    $currentDegree += $sweep;
}
$donutStyle = $donutStops !== []
    ? 'background: conic-gradient(' . implode(', ', $donutStops) . ');'
    : 'background: #eceae4;';

/* ---- Line chart data ---- */
$maxEvolution = 0.0;
foreach ($monthlyEvolution as $monthRow) {
    $maxEvolution = max($maxEvolution, (float) ($monthRow['total'] ?? 0));
}
/* nice rounded axis max: 1 / 2 / 2.5 / 5 x 10^n */
$niceMax = 100.0;
if ($maxEvolution > 0) {
    $pow = pow(10, floor(log10($maxEvolution)));
    foreach ([1, 2, 2.5, 3, 4, 5, 6, 8, 10] as $step) {
        if ($maxEvolution <= $step * $pow) {
            $niceMax = $step * $pow;
            break;
        }
    }
}

$plotLeft = 62.0;
$plotRight = 606.0;
$plotTop = 18.0;
$plotBottom = 196.0;
$plotHeight = $plotBottom - $plotTop;

$chartPoints = [];
$chartMeta = [];
$countEvolution = max(1, count($monthlyEvolution));
$maxIndex = -1;
$maxValueSeen = -1.0;
foreach ($monthlyEvolution as $index => $monthRow) {
    $value = (float) ($monthRow['total'] ?? 0);
    $x = $countEvolution === 1 ? ($plotLeft + $plotRight) / 2 : $plotLeft + ($index * (($plotRight - $plotLeft) / ($countEvolution - 1)));
    $y = $plotBottom - (($value / $niceMax) * $plotHeight);
    $chartPoints[] = round($x, 2) . ',' . round($y, 2);
    $chartMeta[] = [
        'x' => round($x, 2),
        'y' => round($y, 2),
        'label' => (string) ($monthRow['label'] ?? ''),
        'value' => $value,
    ];
    if ($value > $maxValueSeen) {
        $maxValueSeen = $value;
        $maxIndex = $index;
    }
}
$lastIndex = count($chartMeta) - 1;
$areaPoints = $chartPoints !== []
    ? implode(' ', $chartPoints) . ' ' . round((float) $chartMeta[$lastIndex]['x'], 2) . ',' . $plotBottom . ' ' . round((float) $chartMeta[0]['x'], 2) . ',' . $plotBottom
    : '';

$documentDownloadUrl = static function (array $document): string {
    $id = (int) ($document['id'] ?? $document['document_id'] ?? 0);
    return $id > 0 ? build_query_url(['page' => 'cheltuieli_administrative', 'action' => 'download_document', 'document_id' => $id]) : '#';
};

$categoryOptions = static function (?int $selectedId = null) use ($categories): void {
    echo '<option value="">Selectează categoria</option>' . PHP_EOL;
    foreach ($categories as $category) {
        $id = (int) ($category['id'] ?? 0);
        echo '<option value="' . e((string) $id) . '"'
            . ($selectedId !== null && $selectedId === $id ? ' selected' : '')
            . '>'
            . e((string) ($category['name'] ?? '-'))
            . '</option>' . PHP_EOL;
    }
};

$expenseForm = function (string $mode, ?array $expense = null) use ($categoryOptions, $paymentMethods, $documentTypes): void {
    $isEdit = $mode === 'edit';
    $id = (int) ($expense['id'] ?? 0);
    $modalId = $isEdit ? 'editAdminExpenseModal' . $id : 'addAdminExpenseModal';
    $title = $isEdit ? 'Editează cheltuială' : 'Adaugă cheltuială';
    $action = $isEdit ? 'update' : 'store';
    $selectedCategoryId = $isEdit ? (int) ($expense['category_id'] ?? 0) : null;
    ?>
    <div class="modal fade" id="<?= e($modalId) ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'cheltuieli_administrative', 'action' => $action])) ?>" data-admin-expense-form>
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
                                <select class="form-select" name="category_id" required>
                                    <?php $categoryOptions($selectedCategoryId); ?>
                                </select>
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
                            <div class="col-12 col-md-6 col-lg-3">
                                <label class="form-label">Furnizor</label>
                                <input type="text" class="form-control" name="supplier" value="<?= e((string) ($expense['supplier'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-6 col-lg-3">
                                <label class="form-label">Număr factură / bon</label>
                                <input type="text" class="form-control" name="invoice_number" value="<?= e((string) ($expense['invoice_number'] ?? '')) ?>">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Sumă fără TVA</label>
                                <input type="number" min="0" step="0.01" class="form-control" name="amount_net" value="<?= e((string) ($expense['amount_net'] ?? '')) ?>" data-admin-amount-net>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">TVA</label>
                                <input type="number" min="0" step="0.01" class="form-control" name="vat_amount" value="<?= e((string) ($expense['vat_amount'] ?? '')) ?>" data-admin-amount-vat>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label">Total cu TVA</label>
                                <input type="number" min="0" step="0.01" class="form-control" name="amount_total" value="<?= e((string) ($expense['amount_total'] ?? '')) ?>" data-admin-amount-total required>
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

<style>
.admx-page {
    --admx-surface: #ffffff;
    --admx-plane: transparent;
    --admx-ink: #16181d;
    --admx-ink-2: #52514e;
    --admx-muted: #898781;
    --admx-hairline: #e7e5de;
    --admx-grid: #eeede8;
    --admx-accent: #2a78d6;
    --admx-accent-soft: #eaf2fc;
    --admx-danger: #d03b3b;
    --admx-radius: 14px;
    --admx-shadow: 0 1px 2px rgba(15, 20, 30, 0.04), 0 4px 16px rgba(15, 20, 30, 0.05);
    color: var(--admx-ink);
}
.admx-page * { min-width: 0; }

/* ---------- Header ---------- */
.admx-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1rem 1.25rem;
    margin-bottom: 1.25rem;
}
.admx-header-id {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    flex: 1 1 320px;
}
.admx-header-icon {
    width: 48px;
    height: 48px;
    flex: 0 0 48px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    font-size: 1.35rem;
    color: var(--admx-accent);
    background: var(--admx-accent-soft);
    border: 1px solid rgba(42, 120, 214, 0.18);
}
.admx-header h2 {
    font-size: clamp(1.15rem, 2.4vw, 1.45rem);
    font-weight: 700;
    letter-spacing: -0.01em;
    margin: 0;
}
.admx-header-sub {
    color: var(--admx-ink-2);
    font-size: 0.85rem;
    margin-top: 2px;
}
.admx-header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-left: auto;
}
.admx-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border-radius: 10px;
    padding: 0.5rem 0.95rem;
    font-size: 0.875rem;
    font-weight: 600;
    border: 1px solid transparent;
    text-decoration: none;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    white-space: nowrap;
}
.admx-btn-primary {
    background: var(--admx-accent);
    color: #fff;
}
.admx-btn-primary:hover { background: #1c5cab; color: #fff; }
.admx-btn-ghost {
    background: var(--admx-surface);
    border-color: var(--admx-hairline);
    color: var(--admx-ink-2);
}
.admx-btn-ghost:hover { border-color: var(--admx-accent); color: var(--admx-accent); }

/* ---------- Cards ---------- */
.admx-card {
    background: var(--admx-surface);
    border: 1px solid var(--admx-hairline);
    border-radius: var(--admx-radius);
    box-shadow: var(--admx-shadow);
}

/* ---------- KPI row ---------- */
.admx-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(215px, 1fr));
    gap: 0.9rem;
    margin-bottom: 1.25rem;
}
.admx-kpi {
    position: relative;
    padding: 1rem 1.1rem;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}
.admx-kpi-label {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--admx-muted);
}
.admx-kpi-value {
    font-size: clamp(1.25rem, 2.6vw, 1.55rem);
    font-weight: 700;
    letter-spacing: -0.01em;
    line-height: 1.2;
    overflow-wrap: anywhere;
}
.admx-kpi-note { font-size: 0.78rem; color: var(--admx-ink-2); }
.admx-kpi-icon {
    position: absolute;
    top: 0.85rem;
    right: 0.9rem;
    width: 34px;
    height: 34px;
    border-radius: 9px;
    display: grid;
    place-items: center;
    font-size: 1rem;
    color: var(--admx-accent);
    background: var(--admx-accent-soft);
}

/* ---------- Charts ---------- */
.admx-charts {
    display: grid;
    grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
    gap: 0.9rem;
    margin-bottom: 1.25rem;
}
.admx-panel { padding: 1.1rem 1.2rem; display: flex; flex-direction: column; }
.admx-panel-title {
    font-size: 0.95rem;
    font-weight: 700;
    margin: 0 0 1rem;
}
.admx-panel-title span { color: var(--admx-muted); font-weight: 500; font-size: 0.8rem; }

.admx-donut-wrap {
    display: flex;
    align-items: center;
    gap: 1.4rem;
    flex: 1;
    flex-wrap: wrap;
}
.admx-donut {
    width: 168px;
    height: 168px;
    flex: 0 0 168px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    margin-inline: auto;
}
.admx-donut-hole {
    width: 112px;
    height: 112px;
    border-radius: 50%;
    background: var(--admx-surface);
    display: grid;
    place-items: center;
    text-align: center;
    padding: 0.5rem;
}
.admx-donut-hole strong { font-size: 0.98rem; line-height: 1.15; display: block; }
.admx-donut-hole small { color: var(--admx-muted); font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.04em; }
.admx-legend {
    flex: 1 1 220px;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    max-height: 230px;
    overflow-y: auto;
    padding-right: 0.25rem;
}
.admx-legend-row {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    font-size: 0.82rem;
    padding: 0.28rem 0.4rem;
    border-radius: 8px;
}
.admx-legend-row:hover { background: #f6f5f1; }
.admx-legend-dot {
    width: 10px;
    height: 10px;
    flex: 0 0 10px;
    border-radius: 3px;
}
.admx-legend-name {
    flex: 1;
    color: var(--admx-ink-2);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.admx-legend-amount { font-weight: 600; font-variant-numeric: tabular-nums; }
.admx-legend-pct { color: var(--admx-muted); font-variant-numeric: tabular-nums; font-size: 0.76rem; width: 3.4em; text-align: right; }

.admx-line-chart { flex: 1; }
.admx-line-chart svg { width: 100%; height: auto; display: block; }
.admx-line-chart .grid-line { stroke: var(--admx-grid); stroke-width: 1; }
.admx-line-chart .baseline { stroke: #c3c2b7; stroke-width: 1; }
.admx-line-chart .axis-label { fill: var(--admx-muted); font-size: 11px; font-variant-numeric: tabular-nums; }
.admx-line-chart .month-label { fill: var(--admx-muted); font-size: 11px; text-anchor: middle; }
.admx-line-chart .series-line { fill: none; stroke: var(--admx-accent); stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
.admx-line-chart .series-area { fill: url(#admxAreaFill); }
.admx-line-chart .series-dot { fill: var(--admx-accent); stroke: var(--admx-surface); stroke-width: 2; }
.admx-line-chart .hit-target { fill: transparent; cursor: pointer; }
.admx-line-chart .hit-target:hover + .series-dot,
.admx-line-chart .series-dot:hover { r: 6; }
.admx-line-chart .point-label { fill: var(--admx-ink-2); font-size: 11px; font-weight: 600; text-anchor: middle; font-variant-numeric: tabular-nums; }
.admx-chart-empty {
    flex: 1;
    display: grid;
    place-items: center;
    color: var(--admx-muted);
    font-size: 0.85rem;
    min-height: 160px;
}

/* ---------- Filter toolbar ---------- */
.admx-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.55rem;
    padding: 0.8rem 0.9rem;
    margin-bottom: 1rem;
}
.admx-toolbar .form-control,
.admx-toolbar .form-select {
    border-radius: 9px;
    border-color: var(--admx-hairline);
    font-size: 0.85rem;
    padding-top: 0.42rem;
    padding-bottom: 0.42rem;
}
.admx-dates {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex: 0 1 auto;
}
.admx-dates span { color: var(--admx-muted); }
.admx-dates .form-control { max-width: 150px; }
.admx-toolbar select { flex: 0 1 190px; }
.admx-search {
    position: relative;
    flex: 1 1 170px;
    min-width: 150px;
}
.admx-search i {
    position: absolute;
    left: 0.7rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--admx-muted);
    font-size: 0.85rem;
    pointer-events: none;
}
.admx-search .form-control { padding-left: 2rem; }
.admx-toolbar-actions { display: flex; gap: 0.45rem; }

/* ---------- Table ---------- */
.admx-table-card { overflow: hidden; }
.admx-table { margin: 0; font-size: 0.86rem; }
.admx-table thead th {
    background: #fafaf8;
    border-bottom: 1px solid var(--admx-hairline);
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--admx-muted);
    padding: 0.7rem 0.9rem;
    white-space: nowrap;
}
.admx-table thead th a { color: inherit; text-decoration: none; }
.admx-table thead th a:hover { color: var(--admx-accent); }
.admx-table tbody td {
    padding: 0.72rem 0.9rem;
    border-bottom: 1px solid #f1f0ea;
    vertical-align: middle;
}
.admx-table tbody tr:last-child td { border-bottom: 0; }
.admx-table tbody tr:hover { background: #fafaf8; }
.admx-amount { font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.admx-cat-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--admx-ink-2);
    background: #f6f5f1;
    border: 1px solid var(--admx-hairline);
    border-radius: 999px;
    padding: 0.22rem 0.65rem 0.22rem 0.5rem;
    max-width: 100%;
}
.admx-cat-pill .admx-legend-dot { width: 9px; height: 9px; flex: 0 0 9px; }
.admx-cat-pill span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.admx-doc-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border: 1px solid var(--admx-hairline);
    background: var(--admx-surface);
    color: var(--admx-ink-2);
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    padding: 0.25rem 0.55rem;
    cursor: pointer;
}
.admx-doc-chip:hover { border-color: var(--admx-accent); color: var(--admx-accent); }
.admx-actions { display: flex; gap: 0.3rem; }
.admx-icon-btn {
    width: 30px;
    height: 30px;
    display: grid;
    place-items: center;
    border-radius: 8px;
    border: 1px solid var(--admx-hairline);
    background: var(--admx-surface);
    color: var(--admx-ink-2);
    font-size: 0.82rem;
    cursor: pointer;
    text-decoration: none;
    transition: border-color 0.15s ease, color 0.15s ease, background-color 0.15s ease;
}
.admx-icon-btn:hover { border-color: var(--admx-accent); color: var(--admx-accent); }
.admx-icon-btn.is-danger:hover { border-color: var(--admx-danger); color: var(--admx-danger); background: #fdf1f1; }
.admx-icon-btn:disabled { opacity: 0.4; cursor: not-allowed; }

.admx-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: var(--admx-muted);
}
.admx-empty i { font-size: 2rem; display: block; margin-bottom: 0.5rem; }

.admx-table-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.75rem 0.9rem;
    border-top: 1px solid var(--admx-hairline);
    background: #fafaf8;
    font-size: 0.8rem;
    color: var(--admx-muted);
}

/* ---------- Responsive ---------- */
@media (max-width: 1199.98px) {
    .admx-charts { grid-template-columns: 1fr; }
}
@media (max-width: 991.98px) {
    .admx-table .admx-col-secondary { display: none; }
}
@media (max-width: 767.98px) {
    .admx-header-actions { width: 100%; }
    .admx-header-actions .admx-btn { flex: 1; justify-content: center; }
    .admx-dates, .admx-dates .form-control, .admx-toolbar select, .admx-toolbar-actions, .admx-toolbar-actions .admx-btn {
        flex: 1 1 100%;
        max-width: none;
        width: 100%;
    }
    .admx-toolbar-actions .admx-btn { justify-content: center; }
    .admx-donut-wrap { flex-direction: column; }
    .admx-legend { width: 100%; max-height: none; }

    /* table -> stacked cards */
    .admx-table thead { display: none; }
    .admx-table tbody tr {
        display: block;
        border-bottom: 1px solid var(--admx-hairline);
        padding: 0.65rem 0.9rem;
    }
    .admx-table tbody tr:hover { background: transparent; }
    .admx-table tbody td {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        border: 0;
        padding: 0.28rem 0;
        text-align: right;
    }
    .admx-table tbody td::before {
        content: attr(data-label);
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--admx-muted);
        text-align: left;
        flex: 0 0 auto;
    }
    .admx-table tbody td.admx-col-secondary { display: flex; }
    .admx-table tbody td[data-label=""]::before { display: none; }
    .admx-actions { justify-content: flex-end; }
}
</style>

<div class="admx-page">

    <div class="admx-header">
        <div class="admx-header-id">
            <div class="admx-header-icon"><i class="bi bi-briefcase" aria-hidden="true"></i></div>
            <div>
                <h2>Cheltuieli Administrative</h2>
                <div class="admx-header-sub"><?= e($subtitle) ?></div>
            </div>
        </div>
        <div class="admx-header-actions">
            <a class="admx-btn admx-btn-ghost" href="<?= e(build_query_url(array_merge($baseQuery, ['action' => 'export']))) ?>">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
            <button type="button" class="admx-btn admx-btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminExpenseModal">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă cheltuială
            </button>
        </div>
    </div>

    <div class="admx-kpis">
        <div class="admx-card admx-kpi">
            <div class="admx-kpi-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
            <div class="admx-kpi-label">Total luna curentă</div>
            <div class="admx-kpi-value"><?= e($money($kpis['total_lunar'] ?? 0)) ?></div>
            <div class="admx-kpi-note">Cheltuieli administrative</div>
        </div>
        <div class="admx-card admx-kpi">
            <div class="admx-kpi-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></div>
            <div class="admx-kpi-label">Total an curent</div>
            <div class="admx-kpi-value"><?= e($money($kpis['total_an_curent'] ?? 0)) ?></div>
            <div class="admx-kpi-note">De la 1 ianuarie</div>
        </div>
        <div class="admx-card admx-kpi">
            <div class="admx-kpi-icon"><i class="bi bi-receipt" aria-hidden="true"></i></div>
            <div class="admx-kpi-label">Număr cheltuieli</div>
            <div class="admx-kpi-value"><?= e((string) ((int) ($kpis['numar_cheltuieli'] ?? 0))) ?></div>
            <div class="admx-kpi-note">Înregistrări în anul curent</div>
        </div>
        <div class="admx-card admx-kpi">
            <div class="admx-kpi-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
            <div class="admx-kpi-label">Ultima cheltuială</div>
            <?php $latest = is_array($kpis['ultima_cheltuiala'] ?? null) ? $kpis['ultima_cheltuiala'] : null; ?>
            <div class="admx-kpi-value"><?= e($latest !== null ? $money($latest['amount_total'] ?? 0) : '-') ?></div>
            <div class="admx-kpi-note"><?= e($latest !== null ? $datetime($latest['created_at'] ?? null) : 'Nicio cheltuială înregistrată') ?></div>
        </div>
    </div>

    <div class="admx-charts">
        <section class="admx-card admx-panel">
            <h3 class="admx-panel-title">Cheltuieli pe categorii <span>(an curent)</span></h3>
            <?php if ($totalCategories > 0): ?>
                <div class="admx-donut-wrap">
                    <div class="admx-donut" style="<?= e($donutStyle) ?>" role="img" aria-label="Distribuția cheltuielilor pe categorii">
                        <div class="admx-donut-hole">
                            <div>
                                <strong><?= e($money($totalCategories)) ?></strong>
                                <small>Total</small>
                            </div>
                        </div>
                    </div>
                    <div class="admx-legend">
                        <?php foreach ($categoryTotals as $categoryRow): ?>
                            <?php
                            $value = (float) ($categoryRow['total'] ?? 0);
                            if ($value <= 0) {
                                continue;
                            }
                            $percent = $totalCategories > 0 ? ($value / $totalCategories) * 100 : 0;
                            $color = $categoryColor((string) ($categoryRow['slug'] ?? ''), (string) ($categoryRow['color'] ?? ''));
                            ?>
                            <div class="admx-legend-row">
                                <span class="admx-legend-dot" style="background: <?= e($color) ?>"></span>
                                <span class="admx-legend-name"><?= e((string) ($categoryRow['name'] ?? '-')) ?></span>
                                <span class="admx-legend-amount"><?= e($money($value)) ?></span>
                                <span class="admx-legend-pct"><?= e(format_number_ro($percent, 1)) ?>%</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="admx-chart-empty">
                    <div class="text-center">
                        <i class="bi bi-pie-chart d-block fs-3 mb-2" aria-hidden="true"></i>
                        Nicio cheltuială înregistrată în anul curent.
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="admx-card admx-panel">
            <h3 class="admx-panel-title">Evoluția cheltuielilor <span>(ultimele 6 luni)</span></h3>
            <?php if ($chartMeta !== []): ?>
                <div class="admx-line-chart">
                    <svg viewBox="0 0 640 234" role="img" aria-label="Evoluția lunară a cheltuielilor administrative">
                        <defs>
                            <linearGradient id="admxAreaFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#2a78d6" stop-opacity="0.16"/>
                                <stop offset="100%" stop-color="#2a78d6" stop-opacity="0.01"/>
                            </linearGradient>
                        </defs>
                        <?php for ($g = 0; $g <= 4; $g++): ?>
                            <?php
                            $gy = $plotBottom - ($g * ($plotHeight / 4));
                            $gValue = ($niceMax / 4) * $g;
                            ?>
                            <line class="<?= $g === 0 ? 'baseline' : 'grid-line' ?>" x1="<?= e((string) $plotLeft) ?>" y1="<?= e((string) round($gy, 2)) ?>" x2="<?= e((string) $plotRight) ?>" y2="<?= e((string) round($gy, 2)) ?>" />
                            <text class="axis-label" x="<?= e((string) ($plotLeft - 8)) ?>" y="<?= e((string) round($gy + 4, 2)) ?>" text-anchor="end"><?= e($moneyShort($gValue)) ?></text>
                        <?php endfor; ?>

                        <polygon class="series-area" points="<?= e($areaPoints) ?>" />
                        <polyline class="series-line" points="<?= e(implode(' ', $chartPoints)) ?>" />

                        <?php foreach ($chartMeta as $index => $point): ?>
                            <?php if ($index === $maxIndex || $index === $lastIndex): ?>
                                <text class="point-label" x="<?= e((string) $point['x']) ?>" y="<?= e((string) max(14, $point['y'] - 12)) ?>"><?= e($moneyShort($point['value'])) ?></text>
                            <?php endif; ?>
                            <g>
                                <circle class="hit-target" cx="<?= e((string) $point['x']) ?>" cy="<?= e((string) $point['y']) ?>" r="14">
                                    <title><?= e($point['label']) ?>: <?= e($money($point['value'])) ?></title>
                                </circle>
                                <circle class="series-dot" cx="<?= e((string) $point['x']) ?>" cy="<?= e((string) $point['y']) ?>" r="4">
                                    <title><?= e($point['label']) ?>: <?= e($money($point['value'])) ?></title>
                                </circle>
                            </g>
                            <text class="month-label" x="<?= e((string) $point['x']) ?>" y="222"><?= e($point['label']) ?></text>
                        <?php endforeach; ?>
                    </svg>
                </div>
            <?php else: ?>
                <div class="admx-chart-empty">
                    <div class="text-center">
                        <i class="bi bi-graph-up d-block fs-3 mb-2" aria-hidden="true"></i>
                        Nu există date pentru ultimele 6 luni.
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <form method="get" class="admx-card admx-toolbar">
        <input type="hidden" name="page" value="cheltuieli_administrative">
        <div class="admx-dates">
            <input type="date" class="form-control" name="date_start" value="<?= e((string) ($filters['date_start'] ?? '')) ?>" aria-label="Perioadă început">
            <span>–</span>
            <input type="date" class="form-control" name="date_end" value="<?= e((string) ($filters['date_end'] ?? '')) ?>" aria-label="Perioadă sfârșit">
        </div>
        <select class="form-select" name="category_id" aria-label="Categorie">
            <option value="">Toate categoriile</option>
            <?php foreach ($categories as $category): ?>
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
        <div class="admx-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" class="form-control" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Caută descriere, furnizor...">
        </div>
        <div class="admx-toolbar-actions">
            <button type="submit" class="admx-btn admx-btn-primary">
                <i class="bi bi-funnel" aria-hidden="true"></i> Filtrează
            </button>
            <a class="admx-btn admx-btn-ghost" href="<?= e(build_query_url(['page' => 'cheltuieli_administrative'])) ?>">Resetează</a>
        </div>
    </form>

    <section class="admx-card admx-table-card">
        <?php if ($rows === []): ?>
            <div class="admx-empty">
                <i class="bi bi-inbox" aria-hidden="true"></i>
                <div class="mb-3">Nu există cheltuieli în perioada selectată.</div>
                <button type="button" class="admx-btn admx-btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminExpenseModal">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă prima cheltuială
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle admx-table">
                    <thead>
                        <tr>
                            <th><a href="<?= e($sortUrl('data')) ?>">Data<?= e($sortMark('data')) ?></a></th>
                            <th><a href="<?= e($sortUrl('categorie')) ?>">Categorie<?= e($sortMark('categorie')) ?></a></th>
                            <th><a href="<?= e($sortUrl('descriere')) ?>">Descriere<?= e($sortMark('descriere')) ?></a></th>
                            <th class="admx-col-secondary"><a href="<?= e($sortUrl('furnizor')) ?>">Furnizor<?= e($sortMark('furnizor')) ?></a></th>
                            <th class="text-end"><a href="<?= e($sortUrl('suma')) ?>">Sumă<?= e($sortMark('suma')) ?></a></th>
                            <th class="admx-col-secondary"><a href="<?= e($sortUrl('metoda')) ?>">Metodă plată<?= e($sortMark('metoda')) ?></a></th>
                            <th>Doc.</th>
                            <th class="admx-col-secondary"><a href="<?= e($sortUrl('adaugat_de')) ?>">Adăugat de<?= e($sortMark('adaugat_de')) ?></a></th>
                            <th class="text-end">Acțiuni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rowModals = []; ?>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php
                            $rowId = (int) ($row['id'] ?? 0);
                            $documents = $documentsByExpense[$rowId] ?? [];
                            $latestDocument = $documents[0] ?? null;
                            $rowColor = $categoryColor((string) ($row['category_slug'] ?? ''), (string) ($row['category_color'] ?? ''));
                            ?>
                            <tr>
                                <td data-label="Data" class="text-nowrap"><?= e($date($row['expense_date'] ?? null)) ?></td>
                                <td data-label="Categorie">
                                    <span class="admx-cat-pill">
                                        <span class="admx-legend-dot" style="background: <?= e($rowColor) ?>"></span>
                                        <span><?= e((string) ($row['category_name'] ?? '-')) ?></span>
                                    </span>
                                </td>
                                <td data-label="Descriere"><?= e((string) ($row['description'] ?? '-')) ?></td>
                                <td data-label="Furnizor" class="admx-col-secondary"><?= e($show($row['supplier'] ?? null)) ?></td>
                                <td data-label="Sumă" class="text-end admx-amount"><?= e($money($row['amount_total'] ?? 0)) ?></td>
                                <td data-label="Metodă plată" class="admx-col-secondary"><?= e($paymentLabel((string) ($row['payment_method'] ?? ''))) ?></td>
                                <td data-label="Documente">
                                    <?php if ((int) ($row['document_count'] ?? 0) > 0): ?>
                                        <button type="button" class="admx-doc-chip" data-bs-toggle="modal" data-bs-target="#adminExpenseDetails<?= e((string) $rowId) ?>">
                                            <i class="bi bi-paperclip" aria-hidden="true"></i><?= e((string) ($row['document_count'] ?? 0)) ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Adăugat de" class="admx-col-secondary"><?= e($show($row['added_by_name'] ?? null)) ?></td>
                                <td data-label="">
                                    <div class="admx-actions">
                                        <button type="button" class="admx-icon-btn" data-bs-toggle="modal" data-bs-target="#adminExpenseDetails<?= e((string) $rowId) ?>" title="Vezi detalii" aria-label="Vezi detalii">
                                            <i class="bi bi-eye" aria-hidden="true"></i>
                                        </button>
                                        <button type="button" class="admx-icon-btn" data-bs-toggle="modal" data-bs-target="#editAdminExpenseModal<?= e((string) $rowId) ?>" title="Editează" aria-label="Editează">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </button>
                                        <?php if (is_array($latestDocument)): ?>
                                            <a class="admx-icon-btn" href="<?= e($documentDownloadUrl($latestDocument)) ?>" title="Descarcă document" aria-label="Descarcă document">
                                                <i class="bi bi-download" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'cheltuieli_administrative', 'action' => 'delete'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $rowId) ?>">
                                            <button type="submit" class="admx-icon-btn is-danger" data-confirm="Sigur ștergi această cheltuială?" title="Șterge" aria-label="Șterge">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>

                            <?php ob_start(); ?>
                            <div class="modal fade" id="adminExpenseDetails<?= e((string) $rowId) ?>" tabindex="-1" aria-hidden="true">
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
                                                <dt class="col-sm-4">Adăugat de</dt><dd class="col-sm-8"><?= e($show($row['added_by_name'] ?? null)) ?></dd>
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
        <?php endif; ?>

        <div class="admx-table-footer">
            <span>
                Se afișează
                <?= e((string) min((int) ($pagination['total_rows'] ?? 0), ((int) ($pagination['page'] ?? 1) - 1) * (int) ($pagination['per_page'] ?? 10) + 1)) ?>
                -
                <?= e((string) min((int) ($pagination['total_rows'] ?? 0), (int) ($pagination['page'] ?? 1) * (int) ($pagination['per_page'] ?? 10))) ?>
                din <?= e((string) ($pagination['total_rows'] ?? 0)) ?> cheltuieli
            </span>
            <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
                <nav aria-label="Paginare cheltuieli administrative">
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

    document.querySelectorAll('[data-admin-expense-form]').forEach(function (form) {
        var netInput = form.querySelector('[data-admin-amount-net]');
        var vatInput = form.querySelector('[data-admin-amount-vat]');
        var totalInput = form.querySelector('[data-admin-amount-total]');
        var totalTouched = false;

        function syncTotal() {
            if (!totalInput || totalTouched) {
                return;
            }
            var total = numberValue(netInput) + numberValue(vatInput);
            if (total > 0) {
                totalInput.value = total.toFixed(2);
            }
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
