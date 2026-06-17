<?php
$filters = is_array($filters ?? null) ? $filters : [];
$summary = is_array($summary ?? null) ? $summary : [];
$staffTypes = is_array($staffTypes ?? null) ? $staffTypes : [];
$staffTypeOptions = is_array($staffTypeOptions ?? null) ? $staffTypeOptions : [];
$allStaffTypeOptions = is_array($allStaffTypeOptions ?? null) ? $allStaffTypeOptions : [];
$driverOptions = is_array($driverOptions ?? null) ? $driverOptions : [];
$uploadSubjectOptions = is_array($uploadSubjectOptions ?? null) ? $uploadSubjectOptions : [];
$rows = is_array($rows ?? null) ? $rows : [];
$rowModals = [];
$documentsBySubject = is_array($documentsBySubject ?? null) ? $documentsBySubject : [];
$salaryHistoryBySubject = is_array($salaryHistoryBySubject ?? null) ? $salaryHistoryBySubject : [];
$documentTypeOptionsByStaffType = is_array($documentTypeOptionsByStaffType ?? null) ? $documentTypeOptionsByStaffType : [];
$sort = (string) ($sort ?? 'updated_at');
$direction = (string) ($direction ?? 'desc');
$pagination = is_array($pagination ?? null) ? $pagination : ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 10];

$money = static fn(mixed $value): string => $value === null || $value === '' ? '-' : format_number_ro($value, 0) . ' RON';
$categoryLabel = static fn(string $category): string => $category === 'office' ? 'Personal de birou' : 'Personal operațional';
$documentBadge = static function (string $status): string {
    return match ($status) {
        'expirat' => '<span class="badge text-bg-danger">Expirat</span>',
        'expira_curand' => '<span class="badge text-bg-warning text-dark">Expiră curând</span>',
        'valid' => '<span class="badge text-bg-success">Valid</span>',
        default => '<span class="badge text-bg-secondary">Fără documente</span>',
    };
};
$subjectKey = static fn(array $row): string => (string) ($row['source_type'] ?? '') . '-' . (int) ($row['source_id'] ?? 0);
$totalPersonal = max(0, (int) ($summary['total_personal'] ?? 0));
$operationalCount = max(0, (int) ($summary['personal_operational'] ?? 0));
$officeCount = max(0, (int) ($summary['personal_birou'] ?? 0));
$categoryPercent = static fn(int $count): string => $totalPersonal > 0 ? format_number_ro(($count / $totalPersonal) * 100, 1) . '% din total' : '0% din total';
$advancedFiltersOpen = (int) ($filters['staff_type_id'] ?? 0) > 0
    || trim((string) ($filters['category'] ?? '')) !== ''
    || trim((string) ($filters['status'] ?? '')) !== ''
    || trim((string) ($filters['functie'] ?? '')) !== ''
    || trim((string) ($filters['salary_min'] ?? '')) !== ''
    || trim((string) ($filters['salary_max'] ?? '')) !== ''
    || trim((string) ($filters['document_status'] ?? '')) !== '';
$baseQuery = [
    'page' => 'contabilitate_personal',
    'q' => $filters['q'] ?? '',
    'staff_type_id' => $filters['staff_type_id'] ?? '',
    'category' => $filters['category'] ?? '',
    'status' => $filters['status'] ?? '',
    'functie' => $filters['functie'] ?? '',
    'salary_min' => $filters['salary_min'] ?? '',
    'salary_max' => $filters['salary_max'] ?? '',
    'document_status' => $filters['document_status'] ?? '',
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
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $letters .= mb_substr($part, 0, 1);
        if (mb_strlen($letters) >= 2) {
            break;
        }
    }

    return mb_strtoupper($letters !== '' ? $letters : '?');
};
$allDocumentTypes = [];
foreach ($documentTypeOptionsByStaffType as $typeOptions) {
    foreach ((array) $typeOptions as $documentType) {
        $allDocumentTypes[(string) $documentType] = (string) $documentType;
    }
}
foreach (['Contract de muncă', 'Act adițional', 'CI / Buletin', 'Permis conducere', 'Medicina muncii', 'Aviz medical', 'Certificat profesional', 'Alte documente'] as $documentType) {
    $allDocumentTypes[$documentType] = $documentType;
}
$staffTypeMeta = [];
foreach ($staffTypes as $type) {
    $typeId = (int) ($type['id'] ?? 0);
    if ($typeId <= 0) {
        continue;
    }

    $requirements = [];
    foreach ((array) ($type['requirements'] ?? []) as $requirement) {
        $documentType = trim((string) ($requirement['document_type'] ?? ''));
        if ($documentType === '') {
            continue;
        }

        $requirements[] = [
            'document_type' => $documentType,
            'requires_expiry' => (int) ($requirement['requires_expiry'] ?? 0) === 1,
            'warning_days' => (int) ($requirement['warning_days'] ?? 30),
        ];
    }

    $staffTypeMeta[$typeId] = [
        'id' => $typeId,
        'name' => (string) ($type['name'] ?? ''),
        'is_driver_linked' => (int) ($type['is_driver_linked'] ?? 0) === 1,
        'mandatory_documents_enabled' => (int) ($type['mandatory_documents_enabled'] ?? 0) === 1,
        'requirements' => $requirements,
    ];
}
$staffTypeMetaJson = json_encode($staffTypeMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($staffTypeMetaJson)) {
    $staffTypeMetaJson = '{}';
}
?>

<div class="accountancy-page">
    <div class="accountancy-page-title mb-4">
        <div>
            <h2 class="h4 mb-1">Contabilitate Personal</h2>
            <div class="text-primary small">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Acces permis doar pentru contabilitate si administratori.
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4 accountancy-kpi-row">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="accountancy-kpi h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="accountancy-kpi-icon is-blue"><i class="bi bi-people" aria-hidden="true"></i></div>
                    <div>
                        <div class="accountancy-kpi-label">Total personal</div>
                        <div class="accountancy-kpi-value"><?= e((string) $totalPersonal) ?></div>
                        <div class="accountancy-kpi-note">Toți angajații</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="accountancy-kpi h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="accountancy-kpi-icon is-green"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
                    <div>
                        <div class="accountancy-kpi-label">Total salarii lunare</div>
                        <div class="accountancy-kpi-value"><?= e(format_number_ro($summary['total_salarii'] ?? 0, 0)) ?> RON</div>
                        <div class="accountancy-kpi-note">Toți angajații</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="accountancy-kpi h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="accountancy-kpi-icon is-purple"><i class="bi bi-people" aria-hidden="true"></i></div>
                    <div>
                        <div class="accountancy-kpi-label">Personal operațional</div>
                        <div class="accountancy-kpi-value"><?= e((string) $operationalCount) ?></div>
                        <div class="accountancy-kpi-note"><?= e($categoryPercent($operationalCount)) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="accountancy-kpi h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="accountancy-kpi-icon is-orange"><i class="bi bi-person" aria-hidden="true"></i></div>
                    <div>
                        <div class="accountancy-kpi-label">Personal de birou</div>
                        <div class="accountancy-kpi-value"><?= e((string) $officeCount) ?></div>
                        <div class="accountancy-kpi-note"><?= e($categoryPercent($officeCount)) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section class="accountancy-section mb-0">
        <div class="accountancy-section-header">
            <h3 class="h5 mb-0">Categorii personal</h3>
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#staffTypesPanelModal">
                <i class="bi bi-sliders" aria-hidden="true"></i> Configurează tipuri personal
            </button>
        </div>
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <a class="accountancy-category-card is-operational" href="<?= e(build_query_url(array_merge($baseQuery, ['category' => 'operational', 'p' => 1]))) ?>">
                    <span class="accountancy-category-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                    <span class="accountancy-category-body">
                        <span class="accountancy-category-title">Personal operațional</span>
                        <span class="accountancy-category-text">Șoferi, Ajutoare, Mecanici, Spălători, etc.</span>
                    </span>
                    <span class="accountancy-category-badge"><?= e((string) $operationalCount) ?> angajați</span>
                    <i class="bi bi-chevron-right accountancy-category-arrow" aria-hidden="true"></i>
                </a>
            </div>
            <div class="col-12 col-lg-6">
                <a class="accountancy-category-card is-office" href="<?= e(build_query_url(array_merge($baseQuery, ['category' => 'office', 'p' => 1]))) ?>">
                    <span class="accountancy-category-icon"><i class="bi bi-person" aria-hidden="true"></i></span>
                    <span class="accountancy-category-body">
                        <span class="accountancy-category-title">Personal de birou</span>
                        <span class="accountancy-category-text">Contabili, Administratori, HR, Manageri, etc.</span>
                    </span>
                    <span class="accountancy-category-badge"><?= e((string) $officeCount) ?> angajați</span>
                    <i class="bi bi-chevron-right accountancy-category-arrow" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </section>

    <section class="accountancy-section accountancy-management-section">
        <div class="accountancy-section-header">
            <h3 class="h5 mb-0">Gestionare personal</h3>
        </div>
        <form method="get" class="accountancy-toolbar">
            <input type="hidden" name="page" value="contabilitate_personal">
            <div class="accountancy-toolbar-actions">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă angajat
                </button>
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                    <i class="bi bi-upload" aria-hidden="true"></i> Încarcă document
                </button>
            </div>
            <div class="accountancy-toolbar-filters">
                <div class="accountancy-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" class="form-control" id="filter_q" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Caută angajat...">
                </div>
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#staffFiltersPanel" aria-expanded="<?= $advancedFiltersOpen ? 'true' : 'false' ?>" aria-controls="staffFiltersPanel">
                    <i class="bi bi-funnel" aria-hidden="true"></i> Filtrează
                </button>
            </div>
            <div class="collapse accountancy-filter-panel <?= $advancedFiltersOpen ? 'show' : '' ?>" id="staffFiltersPanel">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="filter_staff_type">Tip personal</label>
                        <select class="form-select" id="filter_staff_type" name="staff_type_id">
                            <option value="">Toate</option>
                            <?php foreach ($allStaffTypeOptions as $type): ?>
                                <?php $typeId = (int) ($type['id'] ?? 0); ?>
                                <option value="<?= e((string) $typeId) ?>" <?= (string) ($filters['staff_type_id'] ?? '') === (string) $typeId ? 'selected' : '' ?>>
                                    <?= e((string) ($type['name'] ?? '-')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="filter_category">Categorie</label>
                        <select class="form-select" id="filter_category" name="category">
                            <option value="">Toate</option>
                            <option value="operational" <?= (string) ($filters['category'] ?? '') === 'operational' ? 'selected' : '' ?>>Personal operațional</option>
                            <option value="office" <?= (string) ($filters['category'] ?? '') === 'office' ? 'selected' : '' ?>>Personal de birou</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label" for="filter_status">Status</label>
                        <select class="form-select" id="filter_status" name="status">
                            <option value="">Toate</option>
                            <option value="activ" <?= (string) ($filters['status'] ?? '') === 'activ' ? 'selected' : '' ?>>Activ</option>
                            <option value="inactiv" <?= (string) ($filters['status'] ?? '') === 'inactiv' ? 'selected' : '' ?>>Inactiv</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-xl-2">
                        <label class="form-label" for="filter_function">Funcție</label>
                        <input type="text" class="form-control" id="filter_function" name="functie" value="<?= e((string) ($filters['functie'] ?? '')) ?>">
                    </div>
                    <div class="col-6 col-xl-2">
                        <label class="form-label" for="filter_document_status">Documente</label>
                        <select class="form-select" id="filter_document_status" name="document_status">
                            <option value="">Toate</option>
                            <option value="valid" <?= (string) ($filters['document_status'] ?? '') === 'valid' ? 'selected' : '' ?>>Valid</option>
                            <option value="expira_curand" <?= (string) ($filters['document_status'] ?? '') === 'expira_curand' ? 'selected' : '' ?>>Expiră curând</option>
                            <option value="expirat" <?= (string) ($filters['document_status'] ?? '') === 'expirat' ? 'selected' : '' ?>>Expirat</option>
                            <option value="fara_documente" <?= (string) ($filters['document_status'] ?? '') === 'fara_documente' ? 'selected' : '' ?>>Fără documente</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="form-label" for="filter_salary_min">Salariu minim</label>
                        <input type="number" min="0" step="0.01" class="form-control" id="filter_salary_min" name="salary_min" value="<?= e((string) ($filters['salary_min'] ?? '')) ?>">
                    </div>
                    <div class="col-6 col-md-3 col-xl-2">
                        <label class="form-label" for="filter_salary_max">Salariu maxim</label>
                        <input type="number" min="0" step="0.01" class="form-control" id="filter_salary_max" name="salary_max" value="<?= e((string) ($filters['salary_max'] ?? '')) ?>">
                    </div>
                    <div class="col-12 col-xl-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Aplică filtre</button>
                        <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'contabilitate_personal'])) ?>">Resetează</a>
                    </div>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 accountancy-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><a href="<?= e($sortUrl('nume')) ?>">Nume<?= e($sortMark('nume')) ?></a></th>
                        <th><a href="<?= e($sortUrl('tip')) ?>">Tip personal<?= e($sortMark('tip')) ?></a></th>
                        <th><a href="<?= e($sortUrl('functie')) ?>">Funcție<?= e($sortMark('functie')) ?></a></th>
                        <th><a href="<?= e($sortUrl('salariu')) ?>">Salariu lunar<?= e($sortMark('salariu')) ?></a></th>
                        <th><a href="<?= e($sortUrl('data_angajare')) ?>">Data angajării<?= e($sortMark('data_angajare')) ?></a></th>
                        <th><a href="<?= e($sortUrl('documente')) ?>">Documente<?= e($sortMark('documente')) ?></a></th>
                        <th>Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Nu există înregistrări.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $index => $row): ?>
                        <?php
                        $key = $subjectKey($row);
                        $rowId = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
                        $documents = $documentsBySubject[$key] ?? [];
                        $salaryHistory = $salaryHistoryBySubject[$key] ?? [];
                        $sourceType = (string) ($row['source_type'] ?? '');
                        $sourceId = (int) ($row['source_id'] ?? 0);
                        $staffTypeId = (int) ($row['staff_type_id'] ?? 0);
                        $canDelete = $sourceType === 'staff' && (int) ($row['can_delete'] ?? 0) === 1;
                        $category = (string) ($row['category'] ?? 'operational');
                        $rowNumber = ((int) ($pagination['page'] ?? 1) - 1) * (int) ($pagination['per_page'] ?? 10) + $index + 1;
                        ?>
                        <tr>
                            <td class="accountancy-row-number"><?= e((string) $rowNumber) ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="accountancy-avatar"><?= e($initials((string) ($row['nume'] ?? ''))) ?></div>
                                    <div>
                                        <div class="fw-semibold"><?= e((string) ($row['nume'] ?? '-')) ?></div>
                                        <div class="small text-muted"><?= e($sourceType === 'driver' ? (string) ($row['vehicle_label'] ?? '-') : (string) ($row['email'] ?? '')) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="accountancy-type-pill <?= $category === 'office' ? 'is-office' : 'is-operational' ?>"><?= e((string) ($row['staff_type_name'] ?? '-')) ?></span></td>
                            <td><?= e((string) ($row['functie'] ?? '-')) ?></td>
                            <td><?= e($money($row['salariu'] ?? null)) ?></td>
                            <td><?= e(!empty($row['data_angajare']) ? format_date_ro((string) $row['data_angajare']) : '-') ?></td>
                            <td>
                                <button type="button" class="accountancy-doc-button" data-bs-toggle="modal" data-bs-target="#documentsModal<?= e($rowId) ?>" title="Documente">
                                    <i class="bi bi-folder2-open" aria-hidden="true"></i>
                                    <?= e((string) ($row['document_count'] ?? 0)) ?>
                                </button>
                            </td>
                            <td>
                                <div class="accountancy-action-group">
                                    <button type="button" class="accountancy-icon-action" data-bs-toggle="modal" data-bs-target="#detailsModal<?= e($rowId) ?>" title="Vizualizează" aria-label="Vizualizează">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </button>
                                    <?php if ($sourceType === 'staff'): ?>
                                        <button type="button" class="accountancy-icon-action is-primary" data-bs-toggle="modal" data-bs-target="#editStaffModal<?= e($rowId) ?>" title="Editare" aria-label="Editare">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="accountancy-icon-action is-primary" data-bs-toggle="modal" data-bs-target="#salaryModal<?= e($rowId) ?>" title="Editare" aria-label="Editare">
                                            <i class="bi bi-pencil" aria-hidden="true"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'delete_staff'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $sourceId) ?>">
                                            <button type="submit" class="accountancy-icon-action is-danger" data-confirm="Sigur stergi acest angajat?" title="Ștergere" aria-label="Ștergere">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="accountancy-icon-action is-danger" disabled title="Ștergere indisponibilă" aria-label="Ștergere indisponibilă">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <?php ob_start(); ?>
                        <div class="modal fade" id="detailsModal<?= e($rowId) ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h3 class="modal-title fs-5">Detalii personal</h3>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                                    </div>
                                    <div class="modal-body">
                                        <dl class="row mb-0">
                                            <dt class="col-sm-4">Nume</dt><dd class="col-sm-8"><?= e((string) ($row['nume'] ?? '-')) ?></dd>
                                            <dt class="col-sm-4">Tip personal</dt><dd class="col-sm-8"><?= e((string) ($row['staff_type_name'] ?? '-')) ?></dd>
                                            <dt class="col-sm-4">Categorie</dt><dd class="col-sm-8"><?= e($categoryLabel((string) ($row['category'] ?? 'operational'))) ?></dd>
                                            <dt class="col-sm-4">Funcție</dt><dd class="col-sm-8"><?= e((string) ($row['functie'] ?? '-')) ?></dd>
                                            <dt class="col-sm-4">Telefon</dt><dd class="col-sm-8"><?= e((string) ($row['telefon'] ?? '-')) ?></dd>
                                            <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e((string) ($row['email'] ?? '-')) ?></dd>
                                            <dt class="col-sm-4">Vehicul alocat</dt><dd class="col-sm-8"><?= e((string) ($row['vehicle_label'] ?? '-')) ?></dd>
                                            <dt class="col-sm-4">Salariu lunar</dt><dd class="col-sm-8"><?= e($money($row['salariu'] ?? null)) ?></dd>
                                            <dt class="col-sm-4">Data angajării</dt><dd class="col-sm-8"><?= e(!empty($row['data_angajare']) ? format_date_ro((string) $row['data_angajare']) : '-') ?></dd>
                                            <dt class="col-sm-4">Observații</dt><dd class="col-sm-8"><?= nl2br(e((string) ($row['observatii'] ?? '-'))) ?></dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($sourceType === 'staff'): ?>
                            <div class="modal fade" id="editStaffModal<?= e($rowId) ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'update_staff'])) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $sourceId) ?>">
                                            <div class="modal-header">
                                                <h3 class="modal-title fs-5">Editare personal</h3>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label">Nume complet</label>
                                                        <input type="text" class="form-control" name="nume_complet" value="<?= e((string) ($row['nume'] ?? '')) ?>" required>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Tip personal</label>
                                                        <select class="form-select" name="staff_type_id" required>
                                                            <?php foreach ($staffTypeOptions as $type): ?>
                                                                <?php if ((int) ($type['is_driver_linked'] ?? 0) === 1) { continue; } ?>
                                                                <?php $typeId = (int) ($type['id'] ?? 0); ?>
                                                                <option value="<?= e((string) $typeId) ?>" <?= $staffTypeId === $typeId ? 'selected' : '' ?>><?= e((string) ($type['name'] ?? '-')) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Funcție</label>
                                                        <input type="text" class="form-control" name="functie" value="<?= e((string) ($row['functie'] ?? '')) ?>" required>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Telefon</label>
                                                        <input type="text" class="form-control" name="telefon" value="<?= e((string) ($row['telefon'] ?? '')) ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Email</label>
                                                        <input type="email" class="form-control" name="email" value="<?= e((string) ($row['email'] ?? '')) ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Data angajării</label>
                                                        <input type="date" class="form-control" name="data_angajare" value="<?= e((string) ($row['data_angajare'] ?? '')) ?>">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Status</label>
                                                        <select class="form-select" name="status">
                                                            <option value="activ" <?= (string) ($row['status'] ?? '') === 'activ' ? 'selected' : '' ?>>Activ</option>
                                                            <option value="inactiv" <?= (string) ($row['status'] ?? '') === 'inactiv' ? 'selected' : '' ?>>Inactiv</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Observații</label>
                                                        <textarea class="form-control" name="observatii" rows="3"><?= e((string) ($row['observatii'] ?? '')) ?></textarea>
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
                        <?php endif; ?>

                        <div class="modal fade" id="salaryModal<?= e($rowId) ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'update_salary'])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="source_type" value="<?= e($sourceType) ?>">
                                        <input type="hidden" name="source_id" value="<?= e((string) $sourceId) ?>">
                                        <div class="modal-header">
                                            <h3 class="modal-title fs-5">Istoric salariu</h3>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row g-3 mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Angajat</label>
                                                    <input type="text" class="form-control" value="<?= e((string) ($row['nume'] ?? '')) ?>" readonly>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">Salariu curent</label>
                                                    <input type="number" min="0" step="0.01" class="form-control" name="salariu" value="<?= e((string) ($row['salariu'] ?? '')) ?>" required>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">Data aplicării</label>
                                                    <input type="date" class="form-control" name="effective_date" value="<?= e(date('Y-m-d')) ?>" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Observații</label>
                                                    <textarea class="form-control" name="notes" rows="2"></textarea>
                                                </div>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle">
                                                    <thead>
                                                        <tr>
                                                            <th>Salariu anterior</th>
                                                            <th>Salariu curent</th>
                                                            <th>Data aplicării</th>
                                                            <th>Actualizat de</th>
                                                            <th>Observații</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if ($salaryHistory === []): ?>
                                                            <tr><td colspan="5" class="text-muted">Nu există istoric salarial.</td></tr>
                                                        <?php endif; ?>
                                                        <?php foreach ($salaryHistory as $history): ?>
                                                            <tr>
                                                                <td><?= e($money($history['previous_salary'] ?? null)) ?></td>
                                                                <td><?= e($money($history['current_salary'] ?? null)) ?></td>
                                                                <td><?= e(!empty($history['effective_date']) ? format_date_ro((string) $history['effective_date']) : '-') ?></td>
                                                                <td><?= e((string) ($history['updated_by_name'] ?? '-')) ?></td>
                                                                <td><?= e((string) ($history['notes'] ?? '-')) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                                            <button type="submit" class="btn btn-primary">Actualizează salariu</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="documentsModal<?= e($rowId) ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-xl">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div>
                                            <h3 class="modal-title fs-5">Documente personal</h3>
                                            <div class="small text-muted"><?= e((string) ($row['nume'] ?? '-')) ?> / <?= e((string) ($row['staff_type_name'] ?? '-')) ?></div>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="table-responsive mb-3">
                                            <table class="table table-sm align-middle">
                                                <thead>
                                                    <tr>
                                                        <th>Tip document</th>
                                                        <th>Număr document</th>
                                                        <th>Data emiterii</th>
                                                        <th>Data expirării</th>
                                                        <th>Status</th>
                                                        <th>Fișier</th>
                                                        <th>Observații</th>
                                                        <th></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if ($documents === []): ?>
                                                        <tr><td colspan="8" class="text-muted">Nu există documente încărcate.</td></tr>
                                                    <?php endif; ?>
                                                    <?php foreach ($documents as $document): ?>
                                                        <tr>
                                                            <td><?= e((string) ($document['tip_document'] ?? '-')) ?></td>
                                                            <td><?= e((string) ($document['numar_document'] ?? '-')) ?></td>
                                                            <td><?= e(!empty($document['data_emitere']) ? format_date_ro((string) $document['data_emitere']) : '-') ?></td>
                                                            <td><?= e(!empty($document['data_expirare']) ? format_date_ro((string) $document['data_expirare']) : '-') ?></td>
                                                            <td><?= $documentBadge((string) ($document['expiration_status'] ?? 'valid')) ?></td>
                                                            <td><?= document_file_link_html($document['fisier_original'] ?? null, $document['fisier_stocat'] ?? null) ?></td>
                                                            <td><?= e((string) ($document['observatii'] ?? '-')) ?></td>
                                                            <td>
                                                                <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'delete_document'])) ?>">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="source_type" value="<?= e($sourceType) ?>">
                                                                    <input type="hidden" name="document_id" value="<?= e((string) ((int) ($document['id'] ?? 0))) ?>">
                                                                    <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Stergi acest document?">Șterge</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'store_document'])) ?>" class="accountancy-inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="source_type" value="<?= e($sourceType) ?>">
                                            <input type="hidden" name="source_id" value="<?= e((string) $sourceId) ?>">
                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-3">
                                                    <label class="form-label">Tip document</label>
                                                    <select class="form-select" name="tip_document" required>
                                                        <?php foreach (($documentTypeOptionsByStaffType[$staffTypeId] ?? $allDocumentTypes) as $documentType): ?>
                                                            <option value="<?= e((string) $documentType) ?>"><?= e((string) $documentType) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">Număr document</label>
                                                    <input type="text" class="form-control" name="numar_document">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">Data emiterii</label>
                                                    <input type="date" class="form-control" name="data_emitere">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label">Data expirării</label>
                                                    <input type="date" class="form-control" name="data_expirare">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">Fișier upload</label>
                                                    <input type="file" class="form-control" name="fisier_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Observații</label>
                                                    <textarea class="form-control" name="observatii" rows="2"></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-primary">Adaugă Document</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php $rowModals[] = ob_get_clean(); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <small class="text-muted">
                Se afișează <?= e((string) min((int) $pagination['total_rows'], ((int) $pagination['page'] - 1) * (int) $pagination['per_page'] + 1)) ?>
                -
                <?= e((string) min((int) $pagination['total_rows'], (int) $pagination['page'] * (int) $pagination['per_page'])) ?>
                din <?= e((string) ($pagination['total_rows'] ?? 0)) ?> persoane
            </small>
            <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
                <nav aria-label="Paginare contabilitate personal">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        $currentPageNo = (int) ($pagination['page'] ?? 1);
                        $totalPages = (int) ($pagination['total_pages'] ?? 1);
                        ?>
                        <li class="page-item <?= $currentPageNo <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => max(1, $currentPageNo - 1)]))) ?>">Anterior</a>
                        </li>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $currentPageNo === $i ? 'active' : '' ?>">
                                <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => $i]))) ?>"><?= e((string) $i) ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $currentPageNo >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= e(build_query_url(array_merge($baseQuery, ['p' => min($totalPages, $currentPageNo + 1)]))) ?>">Următor</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php foreach ($rowModals as $rowModalHtml): ?>
    <?= $rowModalHtml ?>
<?php endforeach; ?>

<div class="modal fade" id="staffTypesPanelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title fs-5">Tipuri personal</h3>
                    <div class="small text-muted">Adaugă, editează și configurează documentele obligatorii pentru fiecare tip.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-end mb-3">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStaffTypeModal">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă tip personal
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle accountancy-config-table mb-0">
                        <thead>
                            <tr>
                                <th>Tip personal</th>
                                <th>Categorie</th>
                                <th>Angajați</th>
                                <th>Documente obligatorii</th>
                                <th>Legătură Șoferi</th>
                                <th>Status</th>
                                <th class="text-end">Acțiuni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($staffTypes === []): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Nu există tipuri configurate.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($staffTypes as $type): ?>
                                <?php
                                $typeId = (int) ($type['id'] ?? 0);
                                $isDriverType = (int) ($type['is_driver_linked'] ?? 0) === 1;
                                $requirementsCount = count(is_array($type['requirements'] ?? null) ? $type['requirements'] : []);
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e((string) ($type['name'] ?? '-')) ?></div>
                                        <?php if ($isDriverType): ?>
                                            <div class="small text-muted">Tip sistem, conectat la pagina Șoferi</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($categoryLabel((string) ($type['category'] ?? 'operational'))) ?></td>
                                    <td><?= e((string) ($type['employee_count'] ?? 0)) ?></td>
                                    <td><?= e((string) $requirementsCount) ?></td>
                                    <td>
                                        <?php if ($isDriverType): ?>
                                            <span class="badge text-bg-primary">Conectat</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-light border">Nu</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= status_badge_html((string) ($type['status'] ?? 'activ')) ?></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#staffTypeConfigModal<?= e((string) $typeId) ?>">
                                            Configurează
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addStaffTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'store_type'])) ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Adaugă Tip Personal</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Denumire</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Categorie</label>
                        <select class="form-select" name="category">
                            <option value="operational">Personal operațional</option>
                            <option value="office">Personal de birou</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descriere</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="activ">Activ</option>
                            <option value="inactiv">Inactiv</option>
                        </select>
                    </div>
                    <div class="border rounded-3 bg-light p-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <div class="fw-semibold">Documente obligatorii</div>
                                <div class="small text-muted">Le poti configura acum pentru noul tip de personal. Liniile goale sunt ignorate.</div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-action="add-staff-type-requirement">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i> Adauga document
                            </button>
                        </div>
                        <div class="vstack gap-3" data-role="staff-type-requirements-list" data-next-index="1">
                            <div class="border rounded-3 bg-white p-3" data-role="staff-type-requirement-row">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-5">
                                        <label class="form-label">Tip document</label>
                                        <input type="text" class="form-control" name="requirements[0][document_type]" list="documentTypeDatalist" placeholder="Ex: Contract de munca">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Data expirarii</label>
                                        <select class="form-select" name="requirements[0][requires_expiry]">
                                            <option value="1">Obligatorie</option>
                                            <option value="0">Optionala</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Avertizare</label>
                                        <select class="form-select" name="requirements[0][warning_days]">
                                            <option value="30">30 zile</option>
                                            <option value="60">60 zile</option>
                                            <option value="90">90 zile</option>
                                        </select>
                                    </div>
                                    <div class="col-md-1 d-flex justify-content-md-end">
                                        <button type="button" class="btn btn-outline-danger btn-sm" data-action="remove-staff-type-requirement" aria-label="Elimina document">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="mandatory_documents_enabled" value="1">
                    <input type="hidden" name="can_create_employees" value="1">
                    <input type="hidden" name="can_delete_employees" value="1">
                    <input type="hidden" name="document_warning_days" value="30">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                    <button type="submit" class="btn btn-primary">Salvează</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($staffTypes as $type): ?>
    <?php
    $typeId = (int) ($type['id'] ?? 0);
    $isDriverType = (int) ($type['is_driver_linked'] ?? 0) === 1;
    $typeRequirements = is_array($type['requirements'] ?? null) ? $type['requirements'] : [];
    ?>
    <div class="modal fade" id="staffTypeConfigModal<?= e((string) $typeId) ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Configurare Tip Personal</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'update_type'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $typeId) ?>">
                        <?php if ($isDriverType): ?>
                            <input type="hidden" name="status" value="activ">
                            <input type="hidden" name="category" value="operational">
                            <input type="hidden" name="can_create_employees" value="0">
                            <input type="hidden" name="can_delete_employees" value="0">
                        <?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Denumire</label>
                                <input type="text" class="form-control" name="name" value="<?= e((string) ($type['name'] ?? '')) ?>" <?= $isDriverType ? 'readonly' : '' ?> required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Categorie</label>
                                <select class="form-select" name="category" <?= $isDriverType ? 'disabled' : '' ?>>
                                    <option value="operational" <?= (string) ($type['category'] ?? '') === 'operational' ? 'selected' : '' ?>>Personal operațional</option>
                                    <option value="office" <?= (string) ($type['category'] ?? '') === 'office' ? 'selected' : '' ?>>Personal de birou</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" <?= $isDriverType ? 'disabled' : '' ?>>
                                    <option value="activ" <?= (string) ($type['status'] ?? '') === 'activ' ? 'selected' : '' ?>>Activ</option>
                                    <option value="inactiv" <?= (string) ($type['status'] ?? '') === 'inactiv' ? 'selected' : '' ?>>Inactiv</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descriere</label>
                                <textarea class="form-control" name="description" rows="2"><?= e((string) ($type['description'] ?? '')) ?></textarea>
                            </div>
                            <div class="col-md-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="driverLinked<?= e((string) $typeId) ?>" <?= $isDriverType ? 'checked' : '' ?> disabled>
                                    <label class="form-check-label" for="driverLinked<?= e((string) $typeId) ?>">Conectat la Șoferi</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <input type="hidden" name="salary_required" value="0">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="salary_required" value="1" id="salaryRequired<?= e((string) $typeId) ?>" <?= (int) ($type['salary_required'] ?? 0) === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="salaryRequired<?= e((string) $typeId) ?>">Salariu obligatoriu</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <input type="hidden" name="vehicle_required" value="0">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="vehicle_required" value="1" id="vehicleRequired<?= e((string) $typeId) ?>" <?= (int) ($type['vehicle_required'] ?? 0) === 1 ? 'checked' : '' ?> <?= $isDriverType ? 'disabled' : '' ?>>
                                    <label class="form-check-label" for="vehicleRequired<?= e((string) $typeId) ?>">Vehicul obligatoriu</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <input type="hidden" name="mandatory_documents_enabled" value="0">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="mandatory_documents_enabled" value="1" id="mandatoryDocs<?= e((string) $typeId) ?>" <?= (int) ($type['mandatory_documents_enabled'] ?? 0) === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="mandatoryDocs<?= e((string) $typeId) ?>">Documente obligatorii active</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Avertizare documente</label>
                                <select class="form-select" name="document_warning_days">
                                    <?php foreach ([30, 60, 90] as $days): ?>
                                        <option value="<?= e((string) $days) ?>" <?= (int) ($type['document_warning_days'] ?? 30) === $days ? 'selected' : '' ?>><?= e((string) $days) ?> zile</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <input type="hidden" name="can_create_employees" value="0">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="can_create_employees" value="1" id="canCreate<?= e((string) $typeId) ?>" <?= (int) ($type['can_create_employees'] ?? 0) === 1 ? 'checked' : '' ?> <?= $isDriverType ? 'disabled' : '' ?>>
                                    <label class="form-check-label" for="canCreate<?= e((string) $typeId) ?>">Permite adăugare angajați</label>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <input type="hidden" name="can_delete_employees" value="0">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="can_delete_employees" value="1" id="canDelete<?= e((string) $typeId) ?>" <?= (int) ($type['can_delete_employees'] ?? 0) === 1 ? 'checked' : '' ?> <?= $isDriverType ? 'disabled' : '' ?>>
                                    <label class="form-check-label" for="canDelete<?= e((string) $typeId) ?>">Permite ștergere angajați</label>
                                </div>
                            </div>
                            <?php if ($isDriverType): ?>
                                <div class="col-12">
                                    <div class="alert alert-info mb-0">Șofer este conectat la modulul Șoferi, nu poate fi dezactivat sau șters și importă automat șoferii existenți.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">Salvează</button>
                        </div>
                    </form>

                    <hr>

                    <div class="row g-3">
                        <div class="col-lg-6">
                            <h4 class="h6">Documente obligatorii</h4>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                            <th>Document</th>
                                            <th>Expirare</th>
                                            <th>Avertizare</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($typeRequirements === []): ?>
                                            <tr><td colspan="4" class="text-muted">Nu există documente obligatorii configurate.</td></tr>
                                        <?php endif; ?>
                                        <?php foreach ($typeRequirements as $requirement): ?>
                                            <tr>
                                                <td><?= e((string) ($requirement['document_type'] ?? '-')) ?></td>
                                                <td><?= ((int) ($requirement['requires_expiry'] ?? 1) === 1) ? 'Da' : 'Nu' ?></td>
                                                <td><?= e((string) ($requirement['warning_days'] ?? 30)) ?> zile</td>
                                                <td>
                                                    <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'delete_requirement'])) ?>">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="id" value="<?= e((string) ((int) ($requirement['id'] ?? 0))) ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Elimini acest document obligatoriu?">Elimină</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <h4 class="h6">Adaugă document obligatoriu</h4>
                            <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'add_requirement'])) ?>" class="row g-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="staff_type_id" value="<?= e((string) $typeId) ?>">
                                <div class="col-12">
                                    <label class="form-label">Tip document</label>
                                    <input type="text" class="form-control" name="document_type" list="documentTypeDatalist" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Data expirării</label>
                                    <select class="form-select" name="requires_expiry">
                                        <option value="1">Obligatorie</option>
                                        <option value="0">Opțională</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Avertizare</label>
                                    <select class="form-select" name="warning_days">
                                        <option value="30">30 zile</option>
                                        <option value="60">60 zile</option>
                                        <option value="90">90 zile</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-outline-primary">Salvează document</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<div class="modal fade" id="addStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'store_staff'])) ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Adaugă angajat</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tip personal</label>
                        <select class="form-select" name="staff_type_id" data-role="accountancy-staff-type-select" required>
                            <?php foreach ($staffTypeOptions as $type): ?>
                                <option value="<?= e((string) ((int) ($type['id'] ?? 0))) ?>" data-driver-linked="<?= (int) ($type['is_driver_linked'] ?? 0) === 1 ? '1' : '0' ?>">
                                    <?= e((string) ($type['name'] ?? '-')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div data-role="accountancy-driver-fields">
                        <div class="alert alert-info">Pentru tipul Șofer se selectează un șofer existent din modulul Șoferi. Nu se creează un șofer nou.</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Șofer existent</label>
                                <select class="form-select" name="driver_id">
                                    <option value="">Selectează șofer</option>
                                    <?php foreach ($driverOptions as $driver): ?>
                                        <option value="<?= e((string) ((int) ($driver['id'] ?? 0))) ?>">
                                            <?= e((string) ($driver['nume'] ?? '-')) ?><?= !empty($driver['vehicle_label']) ? ' / ' . e((string) $driver['vehicle_label']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Salariu</label>
                                <input type="number" min="0" step="0.01" class="form-control" name="driver_salariu">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Data angajării</label>
                                <input type="date" class="form-control" name="driver_data_angajare">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Observații</label>
                                <textarea class="form-control" name="driver_observatii" rows="2"></textarea>
                            </div>
                        </div>
                    </div>

                    <div data-role="accountancy-direct-fields">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nume complet</label>
                                <input type="text" class="form-control" name="nume_complet">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Funcție</label>
                                <input type="text" class="form-control" name="functie">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Telefon</label>
                                <input type="text" class="form-control" name="telefon">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Salariu lunar</label>
                                <input type="number" min="0" step="0.01" class="form-control" name="salariu">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data angajării</label>
                                <input type="date" class="form-control" name="data_angajare">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="activ">Activ</option>
                                    <option value="inactiv">Inactiv</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Observații</label>
                                <textarea class="form-control" name="observatii" rows="3"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="border rounded-3 bg-light p-3 mt-4" data-role="accountancy-required-docs-panel">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <h4 class="h6 mb-1">Documente initiale pentru tipul selectat</h4>
                                <div class="small text-muted">Poti incarca direct aici documentele cerute. Liniile lasate goale nu se salveaza.</div>
                            </div>
                            <span class="badge text-bg-light border" data-role="accountancy-required-docs-count">0 documente</span>
                        </div>
                        <div class="alert alert-info py-2 px-3 mb-3 d-none" data-role="accountancy-required-docs-driver-note">
                            Pentru tipul Sofer, documentele se administreaza din modulul Soferi.
                        </div>
                        <div class="small text-muted mb-3 d-none" data-role="accountancy-required-docs-empty">
                            Tipul selectat nu are documente obligatorii configurate inca.
                        </div>
                        <div class="vstack gap-3" data-role="accountancy-required-docs-list"></div>
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

<div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'store_document'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="source_type" data-role="upload-source-type">
                <input type="hidden" name="source_id" data-role="upload-source-id">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Încarcă Document</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Persoană</label>
                            <select class="form-select" data-role="upload-subject-select" required>
                                <option value="">Selectează persoană</option>
                                <?php foreach ($uploadSubjectOptions as $row): ?>
                                    <option value="<?= e((string) ($row['source_type'] ?? '')) ?>:<?= e((string) ((int) ($row['source_id'] ?? 0))) ?>">
                                        <?= e((string) ($row['nume'] ?? '-')) ?> / <?= e((string) ($row['staff_type_name'] ?? '-')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tip document</label>
                            <input type="text" class="form-control" name="tip_document" list="documentTypeDatalist" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Număr document</label>
                            <input type="text" class="form-control" name="numar_document">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Data emiterii</label>
                            <input type="date" class="form-control" name="data_emitere">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Data expirării</label>
                            <input type="date" class="form-control" name="data_expirare">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Fișier upload</label>
                            <input type="file" class="form-control" name="fisier_upload" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observații</label>
                            <textarea class="form-control" name="observatii" rows="3"></textarea>
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

<datalist id="documentTypeDatalist">
    <?php foreach ($allDocumentTypes as $documentType): ?>
        <option value="<?= e((string) $documentType) ?>"></option>
    <?php endforeach; ?>
</datalist>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var staffTypeMeta = <?= $staffTypeMetaJson ?>;

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildRequirementRow(index) {
        return '' +
            '<div class="border rounded-3 bg-white p-3" data-role="staff-type-requirement-row">' +
                '<div class="row g-3 align-items-end">' +
                    '<div class="col-md-5">' +
                        '<label class="form-label">Tip document</label>' +
                        '<input type="text" class="form-control" name="requirements[' + index + '][document_type]" list="documentTypeDatalist" placeholder="Ex: Contract de munca">' +
                    '</div>' +
                    '<div class="col-md-3">' +
                        '<label class="form-label">Data expirarii</label>' +
                        '<select class="form-select" name="requirements[' + index + '][requires_expiry]">' +
                            '<option value="1">Obligatorie</option>' +
                            '<option value="0">Optionala</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="col-md-3">' +
                        '<label class="form-label">Avertizare</label>' +
                        '<select class="form-select" name="requirements[' + index + '][warning_days]">' +
                            '<option value="30">30 zile</option>' +
                            '<option value="60">60 zile</option>' +
                            '<option value="90">90 zile</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="col-md-1 d-flex justify-content-md-end">' +
                        '<button type="button" class="btn btn-outline-danger btn-sm" data-action="remove-staff-type-requirement" aria-label="Elimina document">' +
                            '<i class="bi bi-trash" aria-hidden="true"></i>' +
                        '</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
    }

    function buildStaffDocumentRow(requirement, index) {
        var documentType = escapeHtml(requirement.document_type || '');
        var warningDays = escapeHtml(requirement.warning_days || 30);
        var expiryBadge = requirement.requires_expiry ? 'Expirare obligatorie' : 'Expirare optionala';
        var expiryNote = requirement.requires_expiry
            ? 'Completeaza si data expirarii cand documentul o are.'
            : 'Poti lasa data expirarii goala pentru acest document.';

        return '' +
            '<div class="border rounded-3 bg-white p-3">' +
                '<input type="hidden" name="staff_documents[' + index + '][tip_document]" value="' + documentType + '">' +
                '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">' +
                    '<div>' +
                        '<div class="fw-semibold">' + documentType + '</div>' +
                        '<div class="small text-muted">' + escapeHtml(expiryNote) + ' Avertizare: ' + warningDays + ' zile.</div>' +
                    '</div>' +
                    '<span class="badge text-bg-light border">' + expiryBadge + '</span>' +
                '</div>' +
                '<div class="row g-3">' +
                    '<div class="col-md-4">' +
                        '<label class="form-label">Numar document</label>' +
                        '<input type="text" class="form-control" name="staff_documents[' + index + '][numar_document]">' +
                    '</div>' +
                    '<div class="col-md-4">' +
                        '<label class="form-label">Data emiterii</label>' +
                        '<input type="date" class="form-control" name="staff_documents[' + index + '][data_emitere]">' +
                    '</div>' +
                    '<div class="col-md-4">' +
                        '<label class="form-label">Data expirarii</label>' +
                        '<input type="date" class="form-control" name="staff_documents[' + index + '][data_expirare]">' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Fisier document</label>' +
                        '<input type="file" class="form-control" name="staff_document_files[' + index + ']" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Observatii</label>' +
                        '<textarea class="form-control" name="staff_documents[' + index + '][observatii]" rows="2"></textarea>' +
                    '</div>' +
                '</div>' +
            '</div>';
    }

    document.addEventListener('click', function (event) {
        var addButton = event.target.closest('[data-action="add-staff-type-requirement"]');
        if (addButton) {
            var modalBody = addButton.closest('.modal-body');
            var listEl = modalBody ? modalBody.querySelector('[data-role="staff-type-requirements-list"]') : null;
            if (!listEl) {
                return;
            }

            var nextIndex = parseInt(listEl.getAttribute('data-next-index') || '1', 10);
            listEl.insertAdjacentHTML('beforeend', buildRequirementRow(nextIndex));
            listEl.setAttribute('data-next-index', String(nextIndex + 1));
            return;
        }

        var removeButton = event.target.closest('[data-action="remove-staff-type-requirement"]');
        if (removeButton) {
            var row = removeButton.closest('[data-role="staff-type-requirement-row"]');
            if (row) {
                row.remove();
            }
        }
    });

    document.querySelectorAll('[data-role="accountancy-staff-type-select"]').forEach(function (selectEl) {
        var form = selectEl.closest('form');
        if (!form) {
            return;
        }

        var driverFields = form.querySelector('[data-role="accountancy-driver-fields"]');
        var directFields = form.querySelector('[data-role="accountancy-direct-fields"]');
        var docsList = form.querySelector('[data-role="accountancy-required-docs-list"]');
        var docsEmpty = form.querySelector('[data-role="accountancy-required-docs-empty"]');
        var docsDriverNote = form.querySelector('[data-role="accountancy-required-docs-driver-note"]');
        var docsCount = form.querySelector('[data-role="accountancy-required-docs-count"]');

        function syncFields() {
            var option = selectEl.options[selectEl.selectedIndex];
            var isDriver = option && option.getAttribute('data-driver-linked') === '1';
            var selectedTypeId = option ? String(option.value || '') : '';
            var selectedMeta = staffTypeMeta[selectedTypeId] || null;
            var requirements = selectedMeta && selectedMeta.mandatory_documents_enabled && Array.isArray(selectedMeta.requirements)
                ? selectedMeta.requirements
                : [];

            if (driverFields) {
                driverFields.classList.toggle('d-none', !isDriver);
                driverFields.querySelectorAll('select, input, textarea').forEach(function (input) {
                    input.disabled = !isDriver;
                });
            }
            if (directFields) {
                directFields.classList.toggle('d-none', isDriver);
                directFields.querySelectorAll('select, input, textarea').forEach(function (input) {
                    input.disabled = isDriver;
                });
            }

            if (docsList) {
                docsList.innerHTML = '';
                if (!isDriver && requirements.length > 0) {
                    requirements.forEach(function (requirement, index) {
                        docsList.insertAdjacentHTML('beforeend', buildStaffDocumentRow(requirement, index));
                    });
                }
            }
            if (docsDriverNote) {
                docsDriverNote.classList.toggle('d-none', !isDriver);
            }
            if (docsEmpty) {
                docsEmpty.classList.toggle('d-none', isDriver || requirements.length > 0);
            }
            if (docsCount) {
                docsCount.textContent = (isDriver ? 0 : requirements.length) + ' documente';
            }
        }

        selectEl.addEventListener('change', syncFields);
        syncFields();
    });

    document.querySelectorAll('[data-role="upload-subject-select"]').forEach(function (selectEl) {
        var form = selectEl.closest('form');
        if (!form) {
            return;
        }

        var typeInput = form.querySelector('[data-role="upload-source-type"]');
        var idInput = form.querySelector('[data-role="upload-source-id"]');

        function syncSubject() {
            var value = selectEl.value || '';
            var parts = value.split(':');
            if (typeInput) {
                typeInput.value = parts[0] || '';
            }
            if (idInput) {
                idInput.value = parts[1] || '';
            }
        }

        selectEl.addEventListener('change', syncSubject);
        syncSubject();
    });
});
</script>
