<?php
$filters = is_array($filters ?? null) ? $filters : [];
$summary = is_array($summary ?? null) ? $summary : [];
$rows = is_array($rows ?? null) ? $rows : [];
$documentsBySubject = is_array($documentsBySubject ?? null) ? $documentsBySubject : [];
$profilesBySubject = is_array($profilesBySubject ?? null) ? $profilesBySubject : [];
$terminationReasons = is_array($terminationReasons ?? null) ? $terminationReasons : [];
$staffTypeOptions = is_array($staffTypeOptions ?? null) ? $staffTypeOptions : [];
$sort = (string) ($sort ?? 'data_plecare');
$direction = (string) ($direction ?? 'desc');
$pagination = is_array($pagination ?? null) ? $pagination : ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 10];
$rowModals = [];

$totalFormer = max(0, (int) ($summary['total_former'] ?? 0));
$formerOperational = max(0, (int) ($summary['former_operational'] ?? 0));
$formerOffice = max(0, (int) ($summary['former_office'] ?? 0));
$formerLast12 = max(0, (int) ($summary['former_last_12_months'] ?? 0));
$percentLabel = static fn(int $count): string => $totalFormer > 0 ? format_number_ro(($count / $totalFormer) * 100, 1) . '% din total' : '0% din total';
$subjectKey = static fn(array $row): string => (string) ($row['source_type'] ?? '') . '-' . (int) ($row['source_id'] ?? 0);
$categoryLabel = static fn(string $category): string => $category === 'office' ? 'Personal de birou' : 'Operațional';
$seniorityLabel = static function (mixed $activeDays): string {
    $days = max(0, (int) $activeDays);
    if ($days <= 0) {
        return '-';
    }
    $monthsTotal = intdiv($days, 30);
    if ($monthsTotal <= 0) {
        return $days === 1 ? '1 zi' : $days . ' zile';
    }
    $years = intdiv($monthsTotal, 12);
    $months = $monthsTotal % 12;
    $parts = [];
    if ($years > 0) {
        $parts[] = $years === 1 ? '1 an' : $years . ' ani';
    }
    if ($months > 0) {
        $parts[] = $months === 1 ? '1 lună' : $months . ' luni';
    }
    return $parts !== [] ? implode(' ', $parts) : '0 luni';
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
$reasonBadgeClass = static function (string $reason): string {
    $key = strtolower($reason);
    if (str_contains($key, 'demisie') || str_contains($key, 'pensionare')) {
        return 'is-green';
    }
    if (str_contains($key, 'contract') || str_contains($key, 'acord')) {
        return 'is-orange';
    }
    if (str_contains($key, 'reorganiz')) {
        return 'is-purple';
    }
    if (str_contains($key, 'neprezent') || str_contains($key, 'conced')) {
        return 'is-red';
    }
    return 'is-muted';
};
$baseQuery = [
    'page' => 'fosti_angajati',
    'q' => $filters['q'] ?? '',
    'personnel_type' => $filters['personnel_type'] ?? '',
    'reason' => $filters['reason'] ?? '',
    'period' => $filters['period'] ?? '',
    'date_start' => $filters['date_start'] ?? '',
    'date_end' => $filters['date_end'] ?? '',
    'tab' => $filters['tab'] ?? 'all',
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
$tabUrl = static function (string $tab) use ($baseQuery): string {
    return build_query_url(array_merge($baseQuery, ['tab' => $tab, 'p' => 1]));
};
$activeTab = (string) ($filters['tab'] ?? 'all');
?>

<div class="accountancy-page former-employees-page">
    <div class="former-page-breadcrumb">
        <a href="<?= e(build_query_url(['page' => 'contabilitate_personal'])) ?>">Contabilitate Personal</a>
        <span>/</span>
        <span>Fo&#537;ti angaja&#539;i</span>
    </div>

    <div class="accountancy-page-title mb-4">
        <div>
            <h2 class="h4 mb-1">Fo&#537;ti angaja&#539;i</h2>
            <div class="text-primary small">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Eviden&#539;&#259; centralizat&#259; a personalului care a p&#259;r&#259;sit compania.
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4 accountancy-kpi-row">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="accountancy-kpi h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="accountancy-kpi-icon is-purple"><i class="bi bi-people" aria-hidden="true"></i></div>
                    <div>
                        <div class="accountancy-kpi-label">Total fo&#537;ti angaja&#539;i</div>
                        <div class="accountancy-kpi-value"><?= e((string) $totalFormer) ?></div>
                        <div class="accountancy-kpi-note">Toate categoriile</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="accountancy-kpi h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="accountancy-kpi-icon is-green"><i class="bi bi-briefcase" aria-hidden="true"></i></div>
                    <div>
                        <div class="accountancy-kpi-label">Opera&#539;ional</div>
                        <div class="accountancy-kpi-value"><?= e((string) $formerOperational) ?></div>
                        <div class="accountancy-kpi-note"><?= e($percentLabel($formerOperational)) ?></div>
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
                        <div class="accountancy-kpi-value"><?= e((string) $formerOffice) ?></div>
                        <div class="accountancy-kpi-note"><?= e($percentLabel($formerOffice)) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="accountancy-kpi h-100">
                <div class="d-flex align-items-center gap-3">
                    <div class="accountancy-kpi-icon is-blue"><i class="bi bi-calendar2-check" aria-hidden="true"></i></div>
                    <div>
                        <div class="accountancy-kpi-label">Plecați în ultimele 12 luni</div>
                        <div class="accountancy-kpi-value"><?= e((string) $formerLast12) ?></div>
                        <div class="accountancy-kpi-note">Actualizat recent</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section class="former-filter-panel">
        <form method="get" class="former-filter-form">
            <input type="hidden" name="page" value="fosti_angajati">
            <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
            <a class="btn btn-outline-secondary former-back-btn" href="<?= e(build_query_url(['page' => 'contabilitate_personal'])) ?>">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Înapoi la Contabilitate Personal
            </a>
            <div class="accountancy-search former-filter-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" class="form-control" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Caută fost angajat...">
            </div>
            <div class="former-filter-field">
                <label class="form-label">Tip personal</label>
                <select class="form-select" name="personnel_type">
                    <option value="">Toate</option>
                    <option value="operational" <?= (string) ($filters['personnel_type'] ?? '') === 'operational' ? 'selected' : '' ?>>Operațional</option>
                    <option value="office" <?= (string) ($filters['personnel_type'] ?? '') === 'office' ? 'selected' : '' ?>>Personal de birou</option>
                </select>
            </div>
            <div class="former-filter-field">
                <label class="form-label">Motiv plecare</label>
                <select class="form-select" name="reason">
                    <option value="">Toate</option>
                    <?php foreach ($terminationReasons as $reasonValue => $reasonLabel): ?>
                        <option value="<?= e((string) $reasonValue) ?>" <?= (string) ($filters['reason'] ?? '') === (string) $reasonValue ? 'selected' : '' ?>><?= e((string) $reasonLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="former-filter-field">
                <label class="form-label">Perioadă</label>
                <select class="form-select" name="period" data-former-period-select>
                    <option value="">Toate perioadele</option>
                    <option value="last_30_days" <?= (string) ($filters['period'] ?? '') === 'last_30_days' ? 'selected' : '' ?>>Ultimele 30 zile</option>
                    <option value="last_3_months" <?= (string) ($filters['period'] ?? '') === 'last_3_months' ? 'selected' : '' ?>>Ultimele 3 luni</option>
                    <option value="last_6_months" <?= (string) ($filters['period'] ?? '') === 'last_6_months' ? 'selected' : '' ?>>Ultimele 6 luni</option>
                    <option value="last_12_months" <?= (string) ($filters['period'] ?? '') === 'last_12_months' ? 'selected' : '' ?>>Ultimele 12 luni</option>
                    <option value="current_year" <?= (string) ($filters['period'] ?? '') === 'current_year' ? 'selected' : '' ?>>Anul curent</option>
                    <option value="previous_year" <?= (string) ($filters['period'] ?? '') === 'previous_year' ? 'selected' : '' ?>>Anul anterior</option>
                    <option value="custom" <?= (string) ($filters['period'] ?? '') === 'custom' ? 'selected' : '' ?>>Interval personalizat</option>
                </select>
            </div>
            <div class="former-filter-field former-custom-period <?= (string) ($filters['period'] ?? '') === 'custom' ? '' : 'd-none' ?>" data-former-custom-period>
                <label class="form-label">De la</label>
                <input type="date" class="form-control" name="date_start" value="<?= e((string) ($filters['date_start'] ?? '')) ?>">
            </div>
            <div class="former-filter-field former-custom-period <?= (string) ($filters['period'] ?? '') === 'custom' ? '' : 'd-none' ?>" data-former-custom-period>
                <label class="form-label">Până la</label>
                <input type="date" class="form-control" name="date_end" value="<?= e((string) ($filters['date_end'] ?? '')) ?>">
            </div>
            <div class="former-filter-actions">
                <button type="submit" class="btn btn-outline-secondary former-filter-button">
                    <i class="bi bi-funnel" aria-hidden="true"></i> Filtrează
                </button>
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(['page' => 'fosti_angajati'])) ?>">Resetează</a>
                <a class="btn btn-outline-secondary" href="<?= e(build_query_url(array_merge($baseQuery, ['action' => 'export']))) ?>">
                    <i class="bi bi-download" aria-hidden="true"></i> Export listă
                </a>
            </div>
        </form>
    </section>

    <nav class="former-tabs" aria-label="Categorii foști angajați">
        <a class="former-tab <?= $activeTab === 'all' ? 'active' : '' ?>" href="<?= e($tabUrl('all')) ?>">To&#539;i</a>
        <a class="former-tab <?= $activeTab === 'operational' ? 'active' : '' ?>" href="<?= e($tabUrl('operational')) ?>">Opera&#539;ional</a>
        <a class="former-tab <?= $activeTab === 'office' ? 'active' : '' ?>" href="<?= e($tabUrl('office')) ?>">Birou</a>
    </nav>

    <section class="accountancy-section former-table-section">
        <div class="accountancy-section-header">
            <h3 class="h5 mb-0">List&#259; fo&#537;ti angaja&#539;i</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 accountancy-table former-table">
                <thead>
                    <tr>
                        <th><a href="<?= e($sortUrl('nume')) ?>">Nume<?= e($sortMark('nume')) ?></a></th>
                        <th><a href="<?= e($sortUrl('tip')) ?>">Tip personal<?= e($sortMark('tip')) ?></a></th>
                        <th><a href="<?= e($sortUrl('functie')) ?>">Func&#539;ie<?= e($sortMark('functie')) ?></a></th>
                        <th><a href="<?= e($sortUrl('data_angajare')) ?>">Data angaj&#259;rii<?= e($sortMark('data_angajare')) ?></a></th>
                        <th><a href="<?= e($sortUrl('data_plecare')) ?>">Data plec&#259;rii<?= e($sortMark('data_plecare')) ?></a></th>
                        <th><a href="<?= e($sortUrl('vechime')) ?>">Vechime<?= e($sortMark('vechime')) ?></a></th>
                        <th><a href="<?= e($sortUrl('motiv')) ?>">Motiv plecare<?= e($sortMark('motiv')) ?></a></th>
                        <th><a href="<?= e($sortUrl('sursa')) ?>">Sursa<?= e($sortMark('sursa')) ?></a></th>
                        <th><a href="<?= e($sortUrl('documente')) ?>">Documente<?= e($sortMark('documente')) ?></a></th>
                        <th>Ac&#539;iuni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="10" class="former-empty-state">
                                <div class="former-empty-icon"><i class="bi bi-archive" aria-hidden="true"></i></div>
                                <strong>Nu exist&#259; fo&#537;ti angaja&#539;i</strong>
                                <span>Persoanele pentru care colaborarea a fost încheiată vor apărea aici.</span>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $key = $subjectKey($row);
                        $rowId = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
                        $category = (string) ($row['category'] ?? 'operational');
                        $documents = $documentsBySubject[$key] ?? [];
                        $profile = $profilesBySubject[$key] ?? ['employment_periods' => [], 'salary_history' => [], 'driver_history' => ['trips' => [], 'leaves' => [], 'vehicle_assignments' => []]];
                        $profilePeriods = is_array($profile['employment_periods'] ?? null) ? $profile['employment_periods'] : [];
                        $profileSalary = is_array($profile['salary_history'] ?? null) ? $profile['salary_history'] : [];
                        $driverHistory = is_array($profile['driver_history'] ?? null) ? $profile['driver_history'] : ['trips' => [], 'leaves' => [], 'vehicle_assignments' => []];
                        $rowPhotoUrl = (string) ($row['source_type'] ?? '') === 'driver' ? driver_image_url((string) ($row['poza_stocata'] ?? '')) : null;
                        $sourceType = (string) ($row['source_type'] ?? '');
                        $sourceId = (int) ($row['source_id'] ?? 0);
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="accountancy-avatar <?= $rowPhotoUrl !== null ? 'has-photo' : '' ?>">
                                        <?php if ($rowPhotoUrl !== null): ?>
                                            <img src="<?= e($rowPhotoUrl) ?>" alt="<?= e((string) ($row['nume'] ?? '')) ?>" loading="lazy">
                                        <?php else: ?>
                                            <?= e($initials((string) ($row['nume'] ?? ''))) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="fw-semibold"><?= e((string) ($row['nume'] ?? '-')) ?></div>
                                </div>
                            </td>
                            <td><span class="accountancy-type-pill <?= $category === 'office' ? 'is-office' : 'is-operational' ?>"><?= e($categoryLabel($category)) ?></span></td>
                            <td><?= e((string) ($row['functie'] ?? '-')) ?></td>
                            <td><?= e(!empty($row['data_angajare']) ? format_date_ro((string) $row['data_angajare']) : '-') ?></td>
                            <td><?= e(!empty($row['termination_effective_date']) ? format_date_ro((string) $row['termination_effective_date']) : '-') ?></td>
                            <td><?= e($seniorityLabel($row['active_days'] ?? 0)) ?></td>
                            <td><span class="former-reason-badge <?= e($reasonBadgeClass((string) ($row['termination_reason'] ?? ''))) ?>"><?= e((string) ($row['termination_reason'] ?? '-')) ?></span></td>
                            <td><?= e((string) ($row['source_label'] ?? '-')) ?></td>
                            <td>
                                <button type="button" class="accountancy-doc-button" data-bs-toggle="modal" data-bs-target="#formerDocumentsModal<?= e($rowId) ?>" title="Documente">
                                    <i class="bi bi-folder2-open" aria-hidden="true"></i>
                                    <?= e((string) ($row['document_count'] ?? 0)) ?>
                                </button>
                            </td>
                            <td>
                                <div class="accountancy-action-group">
                                    <button type="button" class="accountancy-icon-action" data-bs-toggle="modal" data-bs-target="#formerProfileModal<?= e($rowId) ?>" title="Vizualizează profilul" aria-label="Vizualizează profilul">
                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                    </button>
                                    <a class="accountancy-icon-action" href="<?= e(build_query_url(['page' => 'fosti_angajati', 'action' => 'history_sheet', 'source_type' => $sourceType, 'source_id' => $sourceId])) ?>" target="_blank" rel="noopener" title="Fișă istoric angajat" aria-label="Fișă istoric angajat">
                                        <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                    </a>
                                    <button type="button" class="accountancy-icon-action is-success" data-bs-toggle="modal" data-bs-target="#formerRehireModal<?= e($rowId) ?>" title="Reangajează" aria-label="Reangajează">
                                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="accountancy-icon-action is-primary" data-bs-toggle="modal" data-bs-target="#formerEditTerminationModal<?= e($rowId) ?>" title="Editează datele plecării" aria-label="Editează datele plecării">
                                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <?php ob_start(); ?>
                        <div class="modal fade" id="formerDocumentsModal<?= e($rowId) ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-xl">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div>
                                            <h3 class="modal-title fs-5">Documente angajat</h3>
                                            <div class="small text-muted"><?= e((string) ($row['nume'] ?? '-')) ?></div>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                                    </div>
                                    <div class="modal-body">
                                        <?php if (!empty($row['termination_document_path'])): ?>
                                            <div class="mb-3">
                                                <strong>Document încetare:</strong>
                                                <?= document_file_link_html($row['termination_document_original'] ?? null, $row['termination_document_path'] ?? null) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle">
                                                <thead><tr><th>Tip document</th><th>Număr</th><th>Emitere</th><th>Expirare</th><th>Fișier</th><th>Observații</th></tr></thead>
                                                <tbody>
                                                    <?php if ($documents === []): ?>
                                                        <tr><td colspan="6" class="text-muted">Nu există documente încărcate.</td></tr>
                                                    <?php endif; ?>
                                                    <?php foreach ($documents as $document): ?>
                                                        <tr>
                                                            <td><?= e((string) ($document['tip_document'] ?? '-')) ?></td>
                                                            <td><?= e((string) ($document['numar_document'] ?? '-')) ?></td>
                                                            <td><?= e(!empty($document['data_emitere']) ? format_date_ro((string) $document['data_emitere']) : '-') ?></td>
                                                            <td><?= e(!empty($document['data_expirare']) ? format_date_ro((string) $document['data_expirare']) : '-') ?></td>
                                                            <td><?= document_file_link_html($document['fisier_original'] ?? null, $document['fisier_stocat'] ?? null) ?></td>
                                                            <td><?= e((string) ($document['observatii'] ?? '-')) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="formerProfileModal<?= e($rowId) ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <div>
                                            <h3 class="modal-title fs-5"><?= e((string) ($row['nume'] ?? '-')) ?></h3>
                                            <div class="small text-muted"><?= e($categoryLabel($category)) ?> / <?= e((string) ($row['source_label'] ?? '-')) ?></div>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                                    </div>
                                    <div class="modal-body former-profile-grid">
                                        <section>
                                            <h4>Profil</h4>
                                            <dl class="row mb-0">
                                                <dt class="col-sm-4">Funcție</dt><dd class="col-sm-8"><?= e((string) ($row['functie'] ?? '-')) ?></dd>
                                                <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= status_badge_html('terminated') ?></dd>
                                                <dt class="col-sm-4">Telefon</dt><dd class="col-sm-8"><?= e((string) ($row['telefon'] ?? '-')) ?></dd>
                                                <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e((string) ($row['email'] ?? '-')) ?></dd>
                                                <dt class="col-sm-4">Data angajării</dt><dd class="col-sm-8"><?= e(!empty($row['data_angajare']) ? format_date_ro((string) $row['data_angajare']) : '-') ?></dd>
                                                <dt class="col-sm-4">Ultima zi lucrată</dt><dd class="col-sm-8"><?= e(!empty($row['last_working_day']) ? format_date_ro((string) $row['last_working_day']) : '-') ?></dd>
                                                <dt class="col-sm-4">Data plecării</dt><dd class="col-sm-8"><?= e(!empty($row['termination_effective_date']) ? format_date_ro((string) $row['termination_effective_date']) : '-') ?></dd>
                                                <dt class="col-sm-4">Vechime</dt><dd class="col-sm-8"><?= e($seniorityLabel($row['active_days'] ?? 0)) ?></dd>
                                                <dt class="col-sm-4">Motiv</dt><dd class="col-sm-8"><?= e((string) ($row['termination_reason'] ?? '-')) ?></dd>
                                                <dt class="col-sm-4">Eligibil reangajare</dt><dd class="col-sm-8"><?= (int) ($row['rehire_eligible'] ?? 0) === 1 ? 'Da' : 'Nu' ?></dd>
                                                <dt class="col-sm-4">Observații plecare</dt><dd class="col-sm-8"><?= nl2br(e((string) ($row['termination_notes'] ?? '-'))) ?></dd>
                                            </dl>
                                        </section>
                                        <section>
                                            <h4>Perioade angajare</h4>
                                            <?php if ($profilePeriods === []): ?>
                                                <div class="former-profile-empty">Nu există perioade în istoric.</div>
                                            <?php else: ?>
                                                <div class="table-responsive"><table class="table table-sm align-middle">
                                                    <thead><tr><th>Tip</th><th>Funcție</th><th>Angajare</th><th>Plecare</th><th>Status</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach ($profilePeriods as $period): ?>
                                                            <tr>
                                                                <td><?= e($categoryLabel((string) ($period['personnel_type'] ?? 'operational'))) ?></td>
                                                                <td><?= e((string) ($period['function_name'] ?? '-')) ?></td>
                                                                <td><?= e(!empty($period['hire_date']) ? format_date_ro((string) $period['hire_date']) : '-') ?></td>
                                                                <td><?= e(!empty($period['termination_date']) ? format_date_ro((string) $period['termination_date']) : '-') ?></td>
                                                                <td><?= e((string) ($period['status'] ?? '-')) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table></div>
                                            <?php endif; ?>
                                        </section>
                                        <section>
                                            <h4>Istoric salarial</h4>
                                            <?php if ($profileSalary === []): ?>
                                                <div class="former-profile-empty">Nu există istoric salarial.</div>
                                            <?php else: ?>
                                                <div class="table-responsive"><table class="table table-sm align-middle">
                                                    <thead><tr><th>Anterior</th><th>Curent</th><th>Data</th><th>Note</th></tr></thead>
                                                    <tbody>
                                                        <?php foreach ($profileSalary as $salaryRow): ?>
                                                            <tr>
                                                                <td><?= e($salaryRow['previous_salary'] !== null ? format_number_ro($salaryRow['previous_salary'], 0) . ' RON' : '-') ?></td>
                                                                <td><?= e(format_number_ro($salaryRow['current_salary'] ?? 0, 0) . ' RON') ?></td>
                                                                <td><?= e(!empty($salaryRow['effective_date']) ? format_date_ro((string) $salaryRow['effective_date']) : '-') ?></td>
                                                                <td><?= e((string) ($salaryRow['notes'] ?? '-')) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table></div>
                                            <?php endif; ?>
                                        </section>
                                        <?php if ($sourceType === 'driver'): ?>
                                            <section>
                                                <h4>Istoric operațional</h4>
                                                <?php $tripRows = is_array($driverHistory['trips'] ?? null) ? $driverHistory['trips'] : []; ?>
                                                <?php if ($tripRows === []): ?>
                                                    <div class="former-profile-empty">Nu există curse în istoricul disponibil.</div>
                                                <?php else: ?>
                                                    <div class="table-responsive"><table class="table table-sm align-middle">
                                                        <thead><tr><th>Data</th><th>Vehicul</th><th>Transport</th><th>Status</th></tr></thead>
                                                        <tbody>
                                                            <?php foreach ($tripRows as $trip): ?>
                                                                <tr>
                                                                    <td><?= e(!empty($trip['data_start']) ? format_date_ro((string) $trip['data_start']) : '-') ?></td>
                                                                    <td><?= e((string) ($trip['vehicle_label'] ?? '-')) ?></td>
                                                                    <td><?= e((string) ($trip['tip_transport'] ?? '-')) ?></td>
                                                                    <td><?= e((string) ($trip['status_facturare'] ?? '-')) ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table></div>
                                                <?php endif; ?>
                                            </section>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="formerEditTerminationModal<?= e($rowId) ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'fosti_angajati', 'action' => 'update_termination'])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="source_type" value="<?= e($sourceType) ?>">
                                        <input type="hidden" name="source_id" value="<?= e((string) $sourceId) ?>">
                                        <div class="modal-header">
                                            <h3 class="modal-title fs-5">Editează datele plecării</h3>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Data plecării</label>
                                                    <input type="date" class="form-control" name="termination_date" value="<?= e((string) ($row['termination_effective_date'] ?? '')) ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Ultima zi lucrată</label>
                                                    <input type="date" class="form-control" name="last_working_day" value="<?= e((string) ($row['last_working_day'] ?? '')) ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Motiv plecare</label>
                                                    <input type="text" class="form-control" name="termination_reason" value="<?= e((string) ($row['termination_reason'] ?? '')) ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Document încetare</label>
                                                    <input type="file" class="form-control" name="termination_document" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Eligibil pentru reangajare</label>
                                                    <select class="form-select" name="rehire_eligible">
                                                        <option value="1" <?= (int) ($row['rehire_eligible'] ?? 0) === 1 ? 'selected' : '' ?>>Da</option>
                                                        <option value="0" <?= (int) ($row['rehire_eligible'] ?? 0) !== 1 ? 'selected' : '' ?>>Nu</option>
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Observații</label>
                                                    <textarea class="form-control" name="termination_notes" rows="3"><?= e((string) ($row['termination_notes'] ?? '')) ?></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="termination_assets_returned" value="1" <?= (int) ($row['termination_assets_returned'] ?? 0) === 1 ? 'checked' : '' ?>>
                                                        <span class="form-check-label">Bunurile și documentele companiei au fost predate</span>
                                                    </label>
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

                        <div class="modal fade" id="formerRehireModal<?= e($rowId) ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <form method="post" action="<?= e(build_query_url(['page' => 'fosti_angajati', 'action' => 'rehire'])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="source_type" value="<?= e($sourceType) ?>">
                                        <input type="hidden" name="source_id" value="<?= e((string) $sourceId) ?>">
                                        <?php if ($sourceType === 'driver'): ?>
                                            <input type="hidden" name="function_name" value="Șofer">
                                        <?php endif; ?>
                                        <div class="modal-header">
                                            <h3 class="modal-title fs-5">Reangajează angajat</h3>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="former-rehire-context">
                                                <strong><?= e((string) ($row['nume'] ?? '-')) ?></strong>
                                                <span><?= e((string) ($row['functie'] ?? '-')) ?> / <?= e($categoryLabel($category)) ?> / plecare <?= e(!empty($row['termination_effective_date']) ? format_date_ro((string) $row['termination_effective_date']) : '-') ?></span>
                                                <span>Motiv: <?= e((string) ($row['termination_reason'] ?? '-')) ?>. Eligibil: <?= (int) ($row['rehire_eligible'] ?? 0) === 1 ? 'Da' : 'Nu' ?></span>
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Noua dată de angajare</label>
                                                    <input type="date" class="form-control" name="hire_date" value="<?= e(date('Y-m-d')) ?>" required>
                                                </div>
                                                <?php if ($sourceType === 'staff'): ?>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Tip personal</label>
                                                        <select class="form-select" name="staff_type_id" required>
                                                            <?php foreach ($staffTypeOptions as $type): ?>
                                                                <?php if ((int) ($type['is_driver_linked'] ?? 0) === 1) { continue; } ?>
                                                                <?php $typeId = (int) ($type['id'] ?? 0); ?>
                                                                <option value="<?= e((string) $typeId) ?>" <?= (int) ($row['staff_type_id'] ?? 0) === $typeId ? 'selected' : '' ?>><?= e((string) ($type['name'] ?? '-')) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Funcție</label>
                                                        <input type="text" class="form-control" name="function_name" value="<?= e((string) ($row['functie'] ?? '')) ?>" required>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Tip personal</label>
                                                        <input type="text" class="form-control" value="Operațional / Șoferi" readonly>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Funcție</label>
                                                        <input type="text" class="form-control" value="Șofer" readonly>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="col-md-6">
                                                    <label class="form-label">Sursa modul</label>
                                                    <input type="text" class="form-control" value="<?= e((string) ($row['source_label'] ?? '-')) ?>" readonly>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Salariu lunar</label>
                                                    <input type="number" min="0" step="0.01" class="form-control" name="salary" value="<?= e((string) ($row['salariu'] ?? '')) ?>">
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Observații reangajare</label>
                                                    <textarea class="form-control" name="rehire_notes" rows="3"></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                                            <button type="submit" class="btn btn-success" data-confirm="Reangajezi această persoană?">Reangajează</button>
                                        </div>
                                    </form>
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
                <nav aria-label="Paginare foști angajați">
                    <ul class="pagination pagination-sm mb-0">
                        <?php $currentPageNo = (int) ($pagination['page'] ?? 1); $totalPages = (int) ($pagination['total_pages'] ?? 1); ?>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-former-period-select]').forEach(function (select) {
        var form = select.closest('form');
        if (!form) {
            return;
        }
        var fields = form.querySelectorAll('[data-former-custom-period]');
        function syncCustomFields() {
            fields.forEach(function (field) {
                field.classList.toggle('d-none', select.value !== 'custom');
            });
        }
        select.addEventListener('change', syncCustomFields);
        syncCustomFields();
    });
});
</script>
