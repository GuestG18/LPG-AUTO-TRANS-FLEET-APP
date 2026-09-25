<?php
$filters = is_array($filters ?? null) ? $filters : [];
$summary = is_array($summary ?? null) ? $summary : [];
$staffTypes = is_array($staffTypes ?? null) ? $staffTypes : [];
$staffTypeOptions = is_array($staffTypeOptions ?? null) ? $staffTypeOptions : [];
$allStaffTypeOptions = is_array($allStaffTypeOptions ?? null) ? $allStaffTypeOptions : [];
$driverOptions = is_array($driverOptions ?? null) ? $driverOptions : [];
$vehicleOptions = is_array($vehicleOptions ?? null) ? $vehicleOptions : [];
$rows = is_array($rows ?? null) ? $rows : [];
$rowModals = [];
$documentsBySubject = is_array($documentsBySubject ?? null) ? $documentsBySubject : [];
$salaryHistoryBySubject = is_array($salaryHistoryBySubject ?? null) ? $salaryHistoryBySubject : [];
$diurnaHistoryByDriver = is_array($diurnaHistoryByDriver ?? null) ? $diurnaHistoryByDriver : [];
$documentTypeOptionsByStaffType = is_array($documentTypeOptionsByStaffType ?? null) ? $documentTypeOptionsByStaffType : [];
$sort = (string) ($sort ?? 'updated_at');
$direction = (string) ($direction ?? 'desc');
$pagination = is_array($pagination ?? null) ? $pagination : ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => 10];

$money = static fn(mixed $value): string => $value === null || $value === '' ? '-' : format_number_ro($value, 0) . ' RON';
$activeDaysLabel = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '-';
    }

    $days = max(0, (int) $value);
    return $days === 1 ? '1 zi' : $days . ' zile';
};
$isEmploymentContractDocument = static function (string $documentType): bool {
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $documentType) : false;
    $base = is_string($ascii) && trim($ascii) !== '' ? $ascii : $documentType;
    $normalized = (string) preg_replace('/[^a-z0-9]+/', '', strtolower($base));

    return in_array($normalized, ['contractdemunca', 'contractdeangajare'], true);
};
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
$baseQuery = [
    'page' => 'contabilitate_personal',
    'q' => $filters['q'] ?? '',
    'staff_type_id' => (int) ($filters['staff_type_id'] ?? 0) > 0 ? (int) $filters['staff_type_id'] : '',
    'category' => $filters['category'] ?? '',
    'status' => $filters['status'] ?? '',
    'functie' => $filters['functie'] ?? '',
    'salary_min' => $filters['salary_min'] ?? '',
    'salary_max' => $filters['salary_max'] ?? '',
    'document_status' => $filters['document_status'] ?? '',
    'regim' => $filters['regim'] ?? '',
    'luna_status' => $filters['luna_status'] ?? '',
    'sort' => $sort,
    'dir' => $direction,
];
$sortUrl = static function (string $column) use (&$baseQuery, $sort, $direction): string {
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
$currentAddCategory = in_array((string) ($filters['category'] ?? ''), ['operational', 'office'], true)
    ? (string) ($filters['category'] ?? '')
    : '';
$currentAddStaffTypeId = max(0, (int) ($filters['staff_type_id'] ?? 0));
$addStaffTypeOptions = array_values(array_filter($staffTypeOptions, static function (array $type) use ($currentAddCategory, $currentAddStaffTypeId): bool {
    $typeId = (int) ($type['id'] ?? 0);
    if ($typeId <= 0) {
        return false;
    }
    if ($currentAddStaffTypeId > 0 && $typeId !== $currentAddStaffTypeId) {
        return false;
    }
    if ($currentAddCategory !== '' && (string) ($type['category'] ?? '') !== $currentAddCategory) {
        return false;
    }

    return true;
}));
$period = is_array($period ?? null) ? $period : StaffMonthlyAccountingService::parsePeriod(null);
$calendarMonth = is_array($calendarMonth ?? null) ? $calendarMonth : ['complete' => false, 'working_days' => null, 'weekend_days' => null, 'holiday_days' => null, 'holidays_on_weekend' => null, 'calendar_days' => null, 'holidays' => [], 'days' => [], 'sync' => []];
$monthCost = is_array($monthCost ?? null) ? $monthCost : ['record_count' => 0, 'paid_count' => 0, 'finalized_count' => 0, 'total_cost' => null];
$monthBySubject = is_array($monthBySubject ?? null) ? $monthBySubject : [];
$baseQuery['luna'] = $period['key'];
// Calculul salarial al lunii (payroll_monthly): stadiu pe angajat, totaluri, regula fiscala.
// Calculul fiscal e optional (implicit dezactivat): atunci costul = salariul configurat al lunii.
$fiscalEnabled = (bool) ($fiscalEnabled ?? false);
$configuredSalaryCost = is_array($configuredSalaryCost ?? null) ? $configuredSalaryCost : null;
$tableColspan = $fiscalEnabled ? 10 : 8;
$payrollStatusBySubject = is_array($payrollStatusBySubject ?? null) ? $payrollStatusBySubject : [];
$payrollProfiles = is_array($payrollProfiles ?? null) ? $payrollProfiles : [];
$payrollTotals = is_array($payrollTotals ?? null) ? $payrollTotals : ['calculated_count' => 0, 'confirmed_count' => 0, 'problem_count' => 0, 'total_cost' => null, 'confirmed_cost' => 0.0];
$payrollRuleLookup = is_array($payrollRuleLookup ?? null) ? $payrollRuleLookup : ['rule' => null, 'error' => 'Configurația fiscală nu a putut fi încărcată.'];
$payrollConfig = is_array($payrollConfig ?? null) ? $payrollConfig : ['rules' => [], 'item_types' => [], 'audit' => []];
$payrollBulkSummary = is_array($payrollBulkSummary ?? null) ? $payrollBulkSummary : null;
$canPayrollCalculate = !function_exists('can') || can('contabilitate_personal', 'payroll_calculate');
$canPayrollConfig = !function_exists('can') || can('contabilitate_personal', 'payroll_config');
$moneyShort = static fn (mixed $value): string => $value === null || $value === '' ? '—' : format_number_ro((float) $value, 0) . ' RON';
$documentStatusCounts = is_array($documentStatusCounts ?? null) ? $documentStatusCounts : [];
// Culoarea randului dupa starea documentelor angajatului.
$documentStatusMeta = [
    'valid' => ['label' => 'Valide', 'title' => 'Toate documentele sunt valide'],
    'expira_curand' => ['label' => 'Expiră curând', 'title' => 'Cel puțin un document expiră în perioada de avertizare'],
    'expirat' => ['label' => 'Expirate', 'title' => 'Cel puțin un document este expirat'],
    'fara_documente' => ['label' => 'Fără documente', 'title' => 'Nu are documente încărcate'],
];
$percent = static fn (int $count): string => $totalPersonal > 0 ? format_number_ro(($count / $totalPersonal) * 100, 1) . '%' : '0%';
$canOpenLeavePlanning = function_exists('can') && can('programare_concedii');
$canExport = !function_exists('can') || can('contabilitate_personal', 'export');
// Selectorul de luna: anul anterior, anul selectat si anul urmator.
$monthOptions = [];
for ($optionYear = $period['year'] - 1; $optionYear <= $period['year'] + 1; $optionYear++) {
    for ($optionMonth = 1; $optionMonth <= 12; $optionMonth++) {
        $monthOptions[sprintf('%04d-%02d', $optionYear, $optionMonth)] = LegalCalendarService::MONTH_NAMES[$optionMonth] . ' ' . $optionYear;
    }
}
?>

<link rel="stylesheet" href="<?= e(url('assets/css/contabilitate-personal.css?v=' . (string) @filemtime(BASE_PATH . '/assets/css/contabilitate-personal.css'))) ?>">

<div class="accountancy-page cp-page" data-cp-period="<?= e($period['key']) ?>" data-cp-detail-url="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'employee_detail'])) ?>">
    <header class="cp-header">
        <div>
            <h2 class="cp-title">Contabilitate Personal</h2>
            <p class="cp-subtitle-text">Evidență angajați, salarii, zile lucrate și costuri salariale</p>
        </div>
        <div class="cp-header-actions">
            <form method="get" class="cp-month-picker" data-cp-month-form>
                <?php foreach ($baseQuery as $queryKey => $queryValue): ?>
                    <?php if ($queryKey !== 'luna' && (string) $queryValue !== ''): ?>
                        <input type="hidden" name="<?= e($queryKey) ?>" value="<?= e((string) $queryValue) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <a class="cp-month-nav" href="<?= e(build_query_url(array_merge($baseQuery, ['luna' => $period['prev'], 'p' => 1]))) ?>" aria-label="Luna anterioară" title="Luna anterioară"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                <select class="form-select cp-month-select" name="luna" aria-label="Luna contabilă" data-cp-autosubmit>
                    <?php foreach ($monthOptions as $optionKey => $optionLabel): ?>
                        <option value="<?= e($optionKey) ?>" <?= $optionKey === $period['key'] ? 'selected' : '' ?>><?= e($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <a class="cp-month-nav" href="<?= e(build_query_url(array_merge($baseQuery, ['luna' => $period['next'], 'p' => 1]))) ?>" aria-label="Luna următoare" title="Luna următoare"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            </form>
            <div class="btn-group cp-btn-group">
                <button type="button" class="btn cp-btn-light" data-bs-toggle="modal" data-bs-target="#cpLegalCalendarModal">
                    <span class="cp-flag-ro" aria-hidden="true"><i></i><i></i><i></i></span> Calendar legal
                </button>
                <button type="button" class="btn cp-btn-light dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mai multe opțiuni">
                    <i class="bi bi-calendar-week" aria-hidden="true"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#cpLegalCalendarModal"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>Calendar legal <?= e($period['label']) ?></button></li>
                    <?php if ($canOpenLeavePlanning): ?>
                        <li><a class="dropdown-item" href="<?= e(build_query_url(['page' => 'programare_concedii'])) ?>"><i class="bi bi-airplane me-2" aria-hidden="true"></i>Programare concedii</a></li>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <?php if ($canPayrollConfig): ?>
                        <li>
                            <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'payroll_toggle'])) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="luna" value="<?= e($period['key']) ?>">
                                <input type="hidden" name="enabled" value="<?= $fiscalEnabled ? '0' : '1' ?>">
                                <button type="submit" class="dropdown-item" data-confirm="<?= $fiscalEnabled ? 'Dezactivezi calculul fiscal? Costul salarial se va lua din salariul configurat. Calculele existente se păstrează.' : 'Activezi calculul fiscal (brut/net, CAS, CASS, impozit, CAM, cost firmă)? Necesită profil de salarizare pentru fiecare angajat.' ?>">
                                    <i class="bi <?= $fiscalEnabled ? 'bi-toggle-on text-primary' : 'bi-toggle-off' ?> me-2" aria-hidden="true"></i>Calcul fiscal salarii (opțional): <?= $fiscalEnabled ? 'activ' : 'inactiv' ?>
                                </button>
                            </form>
                        </li>
                    <?php endif; ?>
                    <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#staffTypesPanelModal"><i class="bi bi-sliders me-2" aria-hidden="true"></i>Configurează tipuri personal</button></li>
                    <?php if ($canExport): ?>
                        <li><a class="dropdown-item" href="<?= e(build_query_url(array_merge($baseQuery, ['action' => 'export']))) ?>"><i class="bi bi-download me-2" aria-hidden="true"></i>Export CSV</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php if ($fiscalEnabled): ?>
                <button type="button" class="btn cp-btn-light cp-btn-config" data-bs-toggle="offcanvas" data-bs-target="#cpPayrollConfig" aria-controls="cpPayrollConfig">
                    <i class="bi bi-gear" aria-hidden="true"></i> Configurare salarii
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-primary cp-btn-primary" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă angajat
            </button>
        </div>
    </header>

    <div class="cp-kpis">
        <div class="cp-kpi">
            <div class="cp-kpi-icon is-blue"><i class="bi bi-people" aria-hidden="true"></i></div>
            <div class="cp-kpi-body">
                <div class="cp-kpi-label">Total personal</div>
                <div class="cp-kpi-value"><?= e((string) $totalPersonal) ?></div>
                <div class="cp-kpi-note"><?= e((string) $operationalCount) ?> operațional • <?= e((string) $officeCount) ?> birou</div>
            </div>
        </div>
        <div class="cp-kpi">
            <div class="cp-kpi-icon is-green"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
            <div class="cp-kpi-body">
                <div class="cp-kpi-label">Total salarii lunare (configurate)</div>
                <div class="cp-kpi-value"><?= e(format_number_ro($summary['total_salarii'] ?? 0, 0)) ?> RON</div>
                <div class="cp-kpi-note" title="Suma salariilor curente din fișe. Nu este costul istoric al unei luni.">salarii curente din fișe</div>
            </div>
        </div>
        <div class="cp-kpi">
            <div class="cp-kpi-icon is-purple"><i class="bi bi-database" aria-hidden="true"></i></div>
            <div class="cp-kpi-body">
                <div class="cp-kpi-label">Cost salarial lună selectată</div>
                <?php if (!$fiscalEnabled): ?>
                    <?php if ($configuredSalaryCost !== null && $configuredSalaryCost['count'] > 0): ?>
                        <div class="cp-kpi-value"><?= e(format_number_ro($configuredSalaryCost['total'], 0)) ?> RON</div>
                        <div class="cp-kpi-note" title="Suma salariilor configurate valabile în <?= e($period['label']) ?> (din istoricul salarial), pentru angajații din acea lună.">
                            <?= e((string) $configuredSalaryCost['count']) ?> <?= $configuredSalaryCost['count'] === 1 ? 'angajat' : 'angajați' ?> · salarii configurate
                        </div>
                    <?php else: ?>
                        <div class="cp-kpi-value is-pending">—</div>
                        <div class="cp-kpi-note">niciun salariu configurat în <?= e($period['label']) ?></div>
                    <?php endif; ?>
                <?php elseif ($payrollTotals['total_cost'] !== null): ?>
                    <div class="cp-kpi-value"><?= e(format_number_ro($payrollTotals['total_cost'], 0)) ?> RON</div>
                    <div class="cp-kpi-note" title="Suma costului total firmă (brut + contribuții angajator + beneficii) din calculele lunii.">
                        <?= e((string) $payrollTotals['calculated_count']) ?> <?= $payrollTotals['calculated_count'] === 1 ? 'angajat calculat' : 'angajați calculați' ?>
                        · <?= e((string) $payrollTotals['confirmed_count']) ?>/<?= e((string) $payrollTotals['calculated_count']) ?> confirmați
                    </div>
                <?php elseif ($payrollRuleLookup['rule'] === null): ?>
                    <div class="cp-kpi-value is-pending">—</div>
                    <div class="cp-kpi-note text-warning-emphasis"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> fără configurație fiscală</div>
                <?php else: ?>
                    <div class="cp-kpi-value is-pending">—</div>
                    <div class="cp-kpi-note" title="Costul lunii se formează din calculele salariale ale lunii, nu din salariile curente.">în așteptare · luna nu este calculată</div>
                <?php endif; ?>
            </div>
        </div>
        <button type="button" class="cp-kpi is-clickable" data-bs-toggle="modal" data-bs-target="#cpLegalCalendarModal" title="Deschide calendarul legal">
            <div class="cp-kpi-icon is-sky"><i class="bi bi-calendar3" aria-hidden="true"></i></div>
            <div class="cp-kpi-body">
                <div class="cp-kpi-label">Zile lucrătoare (RO)</div>
                <?php if ($calendarMonth['working_days'] !== null): ?>
                    <div class="cp-kpi-value"><?= e((string) $calendarMonth['working_days']) ?></div>
                    <div class="cp-kpi-note">în <?= e($period['label']) ?> <i class="bi bi-info-circle" title="Zile lucrătoare legale (Luni–Vineri fără sărbători legale). Nu sunt zilele lucrate de angajați." aria-hidden="true"></i></div>
                <?php else: ?>
                    <div class="cp-kpi-value is-pending">—</div>
                    <div class="cp-kpi-note text-warning-emphasis"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> calendar incomplet</div>
                <?php endif; ?>
            </div>
        </button>
        <div class="cp-kpi">
            <div class="cp-kpi-icon is-orange"><i class="bi bi-pie-chart-fill" aria-hidden="true"></i></div>
            <div class="cp-kpi-body">
                <div class="cp-kpi-label">Distribuție personal</div>
                <div class="cp-distribution">
                    <div><i class="cp-dot is-primary"></i><b><?= e($percent($operationalCount)) ?></b> Operațional</div>
                    <div><i class="cp-dot is-amber"></i><b><?= e($percent($officeCount)) ?></b> • Birou</div>
                </div>
            </div>
        </div>
    </div>

    <section class="cp-card cp-toolbar-card cp-categories">
        <div class="cp-block-head">
            <div class="cp-block-title">Categorii personal</div>
            <button type="button" class="btn btn-sm cp-btn-light" data-bs-toggle="modal" data-bs-target="#staffTypesPanelModal">
                <i class="bi bi-sliders" aria-hidden="true"></i> Configurează tipuri personal
            </button>
        </div>
        <div class="cp-category-row">
            <?php $activeCategory = (string) ($filters['category'] ?? ''); ?>
            <a class="cp-category is-operational <?= $activeCategory === 'operational' ? 'is-active' : '' ?>" href="<?= e(build_query_url(array_merge($baseQuery, ['category' => $activeCategory === 'operational' ? '' : 'operational', 'p' => 1]))) ?>">
                <i class="bi bi-people" aria-hidden="true"></i>
                <span class="cp-category-name">Personal operațional</span>
                <span class="cp-category-count"><?= e((string) $operationalCount) ?> <?= $operationalCount === 1 ? 'angajat' : 'angajați' ?></span>
            </a>
            <a class="cp-category is-office <?= $activeCategory === 'office' ? 'is-active' : '' ?>" href="<?= e(build_query_url(array_merge($baseQuery, ['category' => $activeCategory === 'office' ? '' : 'office', 'p' => 1]))) ?>">
                <i class="bi bi-person-workspace" aria-hidden="true"></i>
                <span class="cp-category-name">Personal de birou</span>
                <span class="cp-category-count"><?= e((string) $officeCount) ?> <?= $officeCount === 1 ? 'angajat' : 'angajați' ?></span>
            </a>
            <a class="cp-category is-former" href="<?= e(build_query_url(['page' => 'fosti_angajati'])) ?>">
                <i class="bi bi-people-fill" aria-hidden="true"></i>
                <span class="cp-category-name">Foști angajați</span>
                <i class="bi bi-chevron-right cp-category-arrow" aria-hidden="true"></i>
            </a>
        </div>
    </section>

    <?php if (empty($calendarMonth['complete'])): ?>
        <div class="cp-alert is-warning mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            Calendarul legal pentru <?= e($period['label']) ?> este incomplet: <?= e((string) ($calendarMonth['sync']['error'] ?? 'sărbătorile legale nu sunt disponibile.')) ?>
            Zilele lucrătoare nu sunt afișate până la sincronizare (se reîncearcă automat).
        </div>
    <?php endif; ?>

    <section class="cp-card cp-table-card">
        <?php
        // Starea documentelor: culoarea randului + filtru (chip-urile de deasupra tabelului).
        $activeDocumentStatus = (string) ($filters['document_status'] ?? '');
        $activeFilterCount = count(array_filter([
            $filters['q'] ?? '', $filters['staff_type_id'] ?? '', $filters['status'] ?? '', $filters['functie'] ?? '',
            $filters['salary_min'] ?? '', $filters['salary_max'] ?? '', $filters['regim'] ?? '', $filters['luna_status'] ?? '', $activeDocumentStatus,
        ], static fn ($value): bool => (string) $value !== '' && (string) $value !== '0'));
        ?>
        <?php if ($fiscalEnabled && $payrollRuleLookup['rule'] === null): ?>
            <div class="cp-alert is-warning cp-table-alert">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <div class="flex-grow-1"><?= e((string) $payrollRuleLookup['error']) ?> Calculul salarial nu folosește automat regula unei alte perioade.</div>
                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="offcanvas" data-bs-target="#cpPayrollConfig">Configurează perioada</button>
            </div>
        <?php endif; ?>
        <?php if ($fiscalEnabled && $payrollBulkSummary !== null): ?>
            <?php
            $bulkGroups = [
                'calculated' => ['Calculate', 'is-info'],
                'to_review' => ['Calculate, de verificat', 'is-warning'],
                'unconfigured' => ['Necesită configurare', 'is-warning'],
                'needs_accountant' => ['Necesită verificare contabilă', 'is-danger'],
                'errors' => ['Erori de validare', 'is-danger'],
                'confirmed_skipped' => ['Deja confirmate (neatinse)', 'is-success'],
            ];
            ?>
            <div class="cp-bulk-summary">
                <div class="cp-bulk-title"><i class="bi bi-calculator" aria-hidden="true"></i> Calculează luna — <?= e((string) ($payrollBulkSummary['period_label'] ?? '')) ?>: <?= e((string) $payrollBulkSummary['total']) ?> angajați</div>
                <div class="cp-bulk-groups">
                    <?php foreach ($bulkGroups as $groupKey => [$groupLabel, $groupClass]): ?>
                        <?php $groupNames = (array) ($payrollBulkSummary[$groupKey] ?? []); ?>
                        <?php if ($groupNames !== []): ?>
                            <details class="cp-bulk-group">
                                <summary><span class="cp-badge <?= e($groupClass) ?>"><?= e((string) count($groupNames)) ?></span> <?= e($groupLabel) ?></summary>
                                <div class="cp-explain"><?= e(implode(', ', $groupNames)) ?></div>
                            </details>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="cp-explain">Motivele exacte sunt în rândul fiecărui angajat → „Calcul salarial”. Filtrați după „Status lună” pentru a-i găsi.</div>
            </div>
        <?php endif; ?>
        <div class="cp-table-toolbar">
            <div class="cp-doc-legend" role="group" aria-label="Filtrează după starea documentelor">
                <span class="cp-doc-legend-label">Documente:</span>
                <a class="cp-doc-chip <?= $activeDocumentStatus === '' ? 'is-active' : '' ?>" href="<?= e(build_query_url(array_merge($baseQuery, ['document_status' => '', 'p' => 1]))) ?>">Toate</a>
                <?php foreach ($documentStatusMeta as $statusKey => $statusMeta): ?>
                    <a
                        class="cp-doc-chip is-<?= e($statusKey) ?> <?= $activeDocumentStatus === $statusKey ? 'is-active' : '' ?>"
                        href="<?= e(build_query_url(array_merge($baseQuery, ['document_status' => $activeDocumentStatus === $statusKey ? '' : $statusKey, 'p' => 1]))) ?>"
                        title="<?= e($statusMeta['title']) ?>"
                    >
                        <i class="cp-doc-swatch" aria-hidden="true"></i><?= e($statusMeta['label']) ?>
                        <span class="cp-doc-chip-count"><?= e((string) ($documentStatusCounts[$statusKey] ?? 0)) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="cp-table-tools">
            <?php if ($fiscalEnabled && $canPayrollCalculate): ?>
                <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'payroll_calculate_month'])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="luna" value="<?= e($period['key']) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary" data-confirm="Calculezi salariile pentru <?= e($period['label']) ?> pentru toți angajații? Calculele confirmate nu se modifică." <?= $payrollRuleLookup['rule'] === null ? 'disabled' : '' ?>>
                        <i class="bi bi-calculator" aria-hidden="true"></i> Calculează luna
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($activeFilterCount > 0): ?>
                <a class="cp-reset-filters" href="<?= e(build_query_url(['page' => 'contabilitate_personal', 'luna' => $period['key'], 'category' => $filters['category'] ?? ''])) ?>">
                    <i class="bi bi-x-circle" aria-hidden="true"></i> Resetează filtrele (<?= e((string) $activeFilterCount) ?>)
                </a>
            <?php endif; ?>
            </div>
        </div>
        <div class="table-responsive">
            <!-- Sortare + filtru din antet ca in Desfasurator curse (assets/js/table-column-filter.js):
                 valorile din lista sunt cele din tabel, in cascada, cu cautare si Shift + click. -->
            <table class="table align-middle mb-0 cp-table" data-column-filter>
                <thead>
                    <tr>
                        <th class="cp-col-toggle" data-no-filter aria-label="Detalii"></th>
                        <th>Nume</th>
                        <th>Tip personal</th>
                        <?php if ($fiscalEnabled): ?>
                            <th>Salariu configurat<span class="cp-th-period">curent · NET/BRUT</span></th>
                            <th>Salariu brut<span class="cp-th-period"><?= e($period['short_label']) ?></span></th>
                            <th>Cost firmă<span class="cp-th-period"><?= e($period['short_label']) ?></span></th>
                        <?php else: ?>
                            <th>Salariu lunar (curent)</th>
                        <?php endif; ?>
                        <th>Regim lucru</th>
                        <th>Zile lucrate<span class="cp-th-period"><?= e($period['short_label']) ?></span></th>
                        <?php if ($fiscalEnabled): ?>
                            <th>Status lună<span class="cp-th-period"><?= e($period['short_label']) ?></span></th>
                        <?php else: ?>
                            <th>Cost salarial<span class="cp-th-period"><?= e($period['short_label']) ?></span></th>
                        <?php endif; ?>
                        <th class="cp-col-actions" data-no-filter>Acțiuni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="<?= e((string) $tableColspan) ?>" class="text-center text-muted py-4">Nu există înregistrări.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $index => $row): ?>
                        <?php
                        $key = $subjectKey($row);
                        $rowId = preg_replace('/[^a-zA-Z0-9_-]/', '', $key);
                        $documents = $documentsBySubject[$key] ?? [];
                        $salaryHistory = $salaryHistoryBySubject[$key] ?? [];
                        $diurnaHistory = (string) ($row['source_type'] ?? '') === 'driver' ? ($diurnaHistoryByDriver[(int) ($row['source_id'] ?? 0)] ?? []) : [];
                        $diurnaPolicy = DriverDiurnaModel::policyAt($diurnaHistory, null);
                        $sourceType = (string) ($row['source_type'] ?? '');
                        $sourceId = (int) ($row['source_id'] ?? 0);
                        $staffTypeId = (int) ($row['staff_type_id'] ?? 0);
                        $canDelete = $sourceType === 'staff' && (int) ($row['can_delete'] ?? 0) === 1;
                        // Dreptul granular "Incheiere activitate" din Drepturi de acces.
                        $canEndActivity = !function_exists('can') || can('contabilitate_personal', 'end_activity');
                        $isTerminated = (string) ($row['employment_status'] ?? 'active') === 'terminated' || !empty($row['termination_effective_date']);
                        $isActive = !$isTerminated;
                        $category = (string) ($row['category'] ?? 'operational');
                        $rowPhotoUrl = $sourceType === 'driver' ? driver_image_url((string) ($row['poza_stocata'] ?? '')) : null;
                        $rowPhotoAlt = trim((string) ($row['poza_original'] ?? ''));
                        if ($rowPhotoAlt === '') {
                            $rowPhotoAlt = 'Poza ' . (string) ($row['nume'] ?? 'angajat');
                        }
                        $rowMonth = $monthBySubject[$key] ?? null;
                        $rowRegime = StaffAccountancyModel::workRegimeLabel($row['regim_lucru'] ?? null, $row['regim_lucru_detalii'] ?? null);
                        $documentStatus = (string) ($row['document_status'] ?? '');
                        $rowPayroll = $payrollStatusBySubject[$key]['record'] ?? null;
                        $rowPayrollMeta = PayrollMonthService::statusMeta($rowPayroll, (bool) ($payrollStatusBySubject[$key]['stale'] ?? false));
                        $rowPayrollOk = $rowPayroll !== null && in_array($rowPayroll['calculation_status'], ['calculat', 'de_verificat'], true);
                        $rowInputType = $payrollProfiles[$key]['salary_input_type'] ?? null;
                        ?>
                        <?php $rowDocMeta = $documentStatusMeta[$documentStatus] ?? null; ?>
                        <tr class="cp-row<?= $rowDocMeta !== null ? ' cp-doc-' . e($documentStatus) : '' ?>" data-cp-row="<?= e($rowId) ?>" data-source-type="<?= e($sourceType) ?>" data-source-id="<?= e((string) $sourceId) ?>">
                            <td class="cp-col-toggle" title="<?= e($rowDocMeta['title'] ?? '') ?>">
                                <button type="button" class="cp-chevron" data-cp-expand data-trips-toggle aria-expanded="false" aria-controls="cpDetail<?= e($rowId) ?>" aria-label="Detalii <?= e((string) ($row['nume'] ?? '')) ?>">
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                </button>
                            </td>
                            <td data-filter-values="<?= e((string) ($row['nume'] ?? '')) ?>">
                                <div class="cp-name-cell">
                                    <div class="cp-avatar <?= $rowPhotoUrl !== null ? 'has-photo' : '' ?>">
                                        <?php if ($rowPhotoUrl !== null): ?>
                                            <img src="<?= e($rowPhotoUrl) ?>" alt="<?= e($rowPhotoAlt) ?>" loading="lazy">
                                        <?php else: ?>
                                            <?= e($initials((string) ($row['nume'] ?? ''))) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="cp-name">
                                            <?= e((string) ($row['nume'] ?? '-')) ?>
                                            <?php if ((string) ($row['tip_colaborare'] ?? '') === 'colaborator'): ?>
                                                <span class="cp-badge is-collab">Colaborator</span>
                                            <?php endif; ?>
                                            <?php if ((string) ($row['status'] ?? 'activ') === 'inactiv'): ?>
                                                <span class="cp-badge is-muted" title="Indisponibil temporar (nu apare în operațiuni). Rămâne angajat; plecările definitive sunt în Foști angajați.">Inactiv</span>
                                            <?php endif; ?>
                                            <?php if ($rowDocMeta !== null): ?>
                                                <span class="visually-hidden">Documente: <?= e($rowDocMeta['title']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="cp-name-sub"><?= e($sourceType === 'driver' ? (string) ($row['vehicle_label'] ?? '-') : (string) ($row['email'] ?? '')) ?></div>
                                    </div>
                                </div>
                            </td>
                            <?php
                            // Functia apare sub tip doar cand spune ceva in plus (ex. "Contabil" la Personal de birou).
                            $rowTypeName = (string) ($row['staff_type_name'] ?? '-');
                            $rowFunctie = trim((string) ($row['functie'] ?? ''));
                            $rowFunctieExtra = $rowFunctie !== '' && mb_strtolower($rowFunctie) !== mb_strtolower($rowTypeName);
                            ?>
                            <td data-filter-values="<?= e($rowTypeName) ?>">
                                <span class="cp-type-pill <?= $category === 'office' ? 'is-office' : 'is-operational' ?>"><?= e($rowTypeName) ?></span>
                                <?php if ($rowFunctieExtra): ?>
                                    <div class="cp-name-sub"><?= e($rowFunctie) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-filter-values="<?= e($money($row['salariu'] ?? null)) ?>" data-value="<?= e($row['salariu'] !== null ? (string) (float) $row['salariu'] : '') ?>">
                                <?= e($money($row['salariu'] ?? null)) ?>
                                <?php if ($fiscalEnabled): ?>
                                <span class="cp-input-type <?= $rowInputType === null ? 'is-unset' : '' ?>" title="<?= $rowInputType === null ? 'Profil de salarizare neconfigurat: nu se știe dacă salariul este NET sau BRUT' : 'Salariul din fișă este ' . ($rowInputType === 'net' ? 'NET' : 'BRUT') ?>"><?= $rowInputType === 'net' ? 'NET' : ($rowInputType === 'gross' ? 'BRUT' : '?') ?></span>
                                <?php endif; ?>
                            </td>
                            <?php if ($fiscalEnabled): ?>
                            <td><?= $rowPayrollOk ? e($moneyShort($rowPayroll['gross_salary'])) : '<span class="text-muted">—</span>' ?></td>
                            <td>
                                <?php if ($rowPayrollOk): ?>
                                    <span class="fw-semibold"><?= e($moneyShort($rowPayroll['total_employer_cost'])) ?></span>
                                <?php else: ?>
                                    <span class="text-muted" title="Luna nu este calculată pentru acest angajat">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><?= $rowRegime !== null ? e($rowRegime) : '<span class="text-muted">Nesetat</span>' ?></td>
                            <td>
                                <?php if ($rowMonth !== null && $rowMonth['worked_days'] !== null): ?>
                                    <?= e(floor($rowMonth['worked_days']) === $rowMonth['worked_days'] ? (string) (int) $rowMonth['worked_days'] : format_number_ro($rowMonth['worked_days'], 1)) ?>
                                <?php else: ?>
                                    <span class="text-muted" title="Pontajul lunii nu este înregistrat">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if (!$fiscalEnabled): ?>
                            <td>
                                <?php $rowConfiguredCost = $rowMonth['applicable_salary'] ?? null; ?>
                                <?php if ($rowConfiguredCost !== null): ?>
                                    <span class="fw-semibold" title="Salariul configurat valabil în <?= e($period['label']) ?><?= !empty($rowMonth['applicable_salary_since']) ? ' (din ' . e(format_date_ro((string) $rowMonth['applicable_salary_since'])) . ')' : '' ?>"><?= e($moneyShort($rowConfiguredCost)) ?></span>
                                <?php else: ?>
                                    <span class="text-muted" title="<?= ($rowMonth['applicable_salary_source'] ?? '') === 'neangajat' ? 'Nu era angajat în această lună' : 'Salariu neconfigurat' ?>">—</span>
                                <?php endif; ?>
                            </td>
                            <?php else: ?>
                            <td>
                                <span class="cp-status-stack" title="<?= e($rowPayroll !== null && $rowPayroll['confirmed_at'] ? 'Confirmat la ' . format_datetime_ro((string) $rowPayroll['confirmed_at']) . ' de ' . (string) ($rowPayroll['confirmed_by_name'] ?? '—') : '') ?>">
                                    <span class="cp-badge <?= e($rowPayrollMeta['class']) ?>"><?= e($rowPayrollMeta['label']) ?></span>
                                    <?php if ($rowPayrollMeta['confirm_label'] !== null): ?>
                                        <span class="cp-badge <?= e($rowPayrollMeta['confirm_class']) ?>"><?= e($rowPayrollMeta['confirm_label']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <?php endif; ?>
                            <td class="cp-col-actions">
                                <div class="cp-actions">
                                    <?php if ($sourceType === 'staff'): ?>
                                        <button type="button" class="cp-action-btn" data-bs-toggle="modal" data-bs-target="#editStaffModal<?= e($rowId) ?>" title="Editare" aria-label="Editare"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                    <?php else: ?>
                                        <button type="button" class="cp-action-btn" data-bs-toggle="modal" data-bs-target="#salaryModal<?= e($rowId) ?>" title="Editare salariu" aria-label="Editare salariu"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                    <?php endif; ?>
                                    <div class="dropdown">
                                        <button
                                            type="button"
                                            class="cp-action-btn"
                                            id="accountancyActionsMenu<?= e($rowId) ?>"
                                            data-bs-toggle="dropdown"
                                            data-bs-boundary="viewport"
                                            data-bs-offset="0,6"
                                            aria-expanded="false"
                                            title="Acțiuni"
                                            aria-label="Deschide acțiuni"
                                        >
                                            <i class="bi bi-three-dots" aria-hidden="true"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end accountancy-actions-menu" aria-labelledby="accountancyActionsMenu<?= e($rowId) ?>">
                                            <li>
                                                <button type="button" class="dropdown-item accountancy-action-menu-item" data-bs-toggle="modal" data-bs-target="#detailsModal<?= e($rowId) ?>">
                                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                                    <span>Vizualizează</span>
                                                </button>
                                            </li>
                                            <li>
                                                <button type="button" class="dropdown-item accountancy-action-menu-item" data-bs-toggle="modal" data-bs-target="#salaryModal<?= e($rowId) ?>">
                                                    <i class="bi bi-cash-coin" aria-hidden="true"></i>
                                                    <span>Salariu &amp; istoric</span>
                                                </button>
                                            </li>
                                            <?php if ($sourceType === 'driver'): ?>
                                                <li>
                                                    <button type="button" class="dropdown-item accountancy-action-menu-item" data-bs-toggle="modal" data-bs-target="#diurnaModal<?= e($rowId) ?>">
                                                        <i class="bi bi-calendar-check" aria-hidden="true"></i>
                                                        <span>Diurnă</span>
                                                    </button>
                                                </li>
                                            <?php endif; ?>
                                            <li>
                                                <button type="button" class="dropdown-item accountancy-action-menu-item" data-bs-toggle="modal" data-bs-target="#documentsModal<?= e($rowId) ?>">
                                                    <i class="bi bi-folder2-open" aria-hidden="true"></i>
                                                    <span>Documente (<?= e((string) ($row['document_count'] ?? 0)) ?>)</span>
                                                </button>
                                            </li>
                                            <li>
                                                <?php if ($isActive && $canEndActivity): ?>
                                                    <button type="button" class="dropdown-item accountancy-action-menu-item" data-bs-toggle="modal" data-bs-target="#endActivityModal<?= e($rowId) ?>">
                                                        <i class="bi bi-person-dash" aria-hidden="true"></i>
                                                        <span>Încetează activitatea</span>
                                                    </button>
                                                <?php elseif ($isTerminated): ?>
                                                    <button type="button" class="dropdown-item accountancy-action-menu-item" disabled>
                                                        <i class="bi bi-person-dash" aria-hidden="true"></i>
                                                        <span>Activitate încetată</span>
                                                    </button>
                                                <?php endif; ?>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <?php if ($canDelete): ?>
                                                    <form method="post" class="accountancy-action-menu-form" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'delete_staff'])) ?>">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="id" value="<?= e((string) $sourceId) ?>">
                                                        <button type="submit" class="dropdown-item accountancy-action-menu-item is-danger" data-confirm="Sigur stergi acest angajat?">
                                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                                            <span>Ștergere</span>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button type="button" class="dropdown-item accountancy-action-menu-item is-danger" disabled>
                                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                                        <span>Ștergere indisponibilă</span>
                                                    </button>
                                                <?php endif; ?>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr class="cp-detail-row" id="cpDetail<?= e($rowId) ?>" data-cp-detail-row="<?= e($rowId) ?>" data-trips-detail hidden>
                            <td colspan="<?= e((string) $tableColspan) ?>">
                                <div class="cp-detail" data-cp-detail-host>
                                    <div class="cp-detail-loading"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Se încarcă detaliile...</div>
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
                                            <dt class="col-sm-4">Data încetării</dt><dd class="col-sm-8"><?= e(!empty($row['data_incetare']) ? format_date_ro((string) $row['data_incetare']) : '-') ?></dd>
                                            <dt class="col-sm-4">Zile active</dt><dd class="col-sm-8"><?= e($activeDaysLabel($row['active_days'] ?? null)) ?></dd>
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

                        <?php if ($isActive && $canEndActivity): ?>
                        <div class="modal fade" id="endActivityModal<?= e($rowId) ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'end_activity'])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="source_type" value="<?= e($sourceType) ?>">
                                        <input type="hidden" name="source_id" value="<?= e((string) $sourceId) ?>">
                                        <input type="hidden" name="return_to" value="contabilitate_personal">
                                        <div class="modal-header">
                                            <h3 class="modal-title fs-5">Încheiere colaborare</h3>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label">Angajat</label>
                                                <input type="text" class="form-control" value="<?= e((string) ($row['nume'] ?? '-')) ?>" readonly>
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label">Data plecării <span class="text-danger">*</span></label>
                                                    <input type="date" class="form-control" name="termination_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Ultima zi lucrată</label>
                                                    <input type="date" class="form-control" name="last_working_day" max="<?= e(date('Y-m-d')) ?>">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Motiv plecare <span class="text-danger">*</span></label>
                                                    <select class="form-select" name="termination_reason" required>
                                                        <option value="">Selectează motivul</option>
                                                        <option value="Demisie">Demisie</option>
                                                        <option value="Incetare contract">Incetare contract</option>
                                                        <option value="Concediere">Concediere</option>
                                                        <option value="Pensionare">Pensionare</option>
                                                        <option value="Reorganizare">Reorganizare</option>
                                                        <option value="Abandon / Neprezentare">Abandon / Neprezentare</option>
                                                        <option value="Acordul partilor">Acordul partilor</option>
                                                        <option value="Alte motive">Alte motive</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Motiv personalizat</label>
                                                    <input type="text" class="form-control" name="termination_reason_custom" placeholder="Completează pentru Alte motive">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Document incetare</label>
                                                    <input type="file" class="form-control" name="termination_document" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label">Eligibil pentru reangajare</label>
                                                    <select class="form-select" name="rehire_eligible">
                                                        <option value="1">Da</option>
                                                        <option value="0">Nu</option>
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Observații</label>
                                                    <textarea class="form-control" name="termination_notes" rows="3"></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="termination_assets_returned" value="1">
                                                        <span class="form-check-label">Confirmare ca bunurile si documentele companiei au fost predate</span>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuleaza</button>
                                            <button type="submit" class="btn btn-danger" data-confirm="Închei colaborarea pentru această persoană?">Încheie colaborarea</button>
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

                        <?php if ($sourceType === 'driver'): ?>
                        <div class="modal fade" id="diurnaModal<?= e($rowId) ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'update_diurna'])) ?>" data-diurna-form>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="driver_id" value="<?= e((string) $sourceId) ?>">
                                        <div class="modal-header">
                                            <h3 class="modal-title fs-5">Diurnă - <?= e((string) ($row['nume'] ?? '')) ?></h3>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p class="small text-muted">
                                                Numărul de diurne se calculează din cursele din Dispecer curse. Aici stabilești dacă șoferul
                                                primește diurnă și cât valorează o zi. Schimbarea se aplică de la data aleasă; cursele de dinainte
                                                își păstrează regula veche.
                                            </p>
                                            <div class="row g-3 mb-3">
                                                <div class="col-md-4">
                                                    <label class="form-label">Primește diurnă</label>
                                                    <select class="form-select" name="primeste_diurna" data-diurna-receives>
                                                        <option value="1" <?= $diurnaPolicy['status'] !== DriverDiurnaModel::STATUS_NONE ? 'selected' : '' ?>>Da</option>
                                                        <option value="0" <?= $diurnaPolicy['status'] === DriverDiurnaModel::STATUS_NONE ? 'selected' : '' ?>>Nu</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4" data-diurna-rate-field>
                                                    <label class="form-label">Valoare / zi (lei)</label>
                                                    <input type="number" min="0" step="0.01" class="form-control" name="valoare_zi" value="<?= e($diurnaPolicy['rate'] !== null ? (string) $diurnaPolicy['rate'] : '') ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">Se aplică de la</label>
                                                    <input type="date" class="form-control" name="data_aplicare" value="<?= e(date('Y-m-01')) ?>" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Observații</label>
                                                    <input type="text" class="form-control" name="observatii" maxlength="255">
                                                </div>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle">
                                                    <thead><tr><th>Se aplică de la</th><th>Primește</th><th>Valoare / zi</th><th>Stabilit de</th><th>Observații</th></tr></thead>
                                                    <tbody>
                                                        <?php if ($diurnaHistory === []): ?>
                                                            <tr><td colspan="5" class="text-muted">Diurna nu a fost stabilită pentru acest șofer.</td></tr>
                                                        <?php endif; ?>
                                                        <?php foreach ($diurnaHistory as $history): ?>
                                                            <tr>
                                                                <td><?= e(format_date_ro((string) $history['data_aplicare'])) ?></td>
                                                                <td><?= (int) $history['primeste_diurna'] === 1 ? 'Da' : 'Nu' ?></td>
                                                                <td><?= $history['valoare_zi'] !== null ? e($money($history['valoare_zi'])) : '-' ?></td>
                                                                <td><?= e((string) ($history['created_by_name'] ?? '-')) ?></td>
                                                                <td><?= e((string) (($history['observatii'] ?? '') !== '' ? $history['observatii'] : '-')) ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                                            <button type="submit" class="btn btn-primary">Salvează diurna</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

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

                                        <?php $allowedDocumentTypes = $documentTypeOptionsByStaffType[$staffTypeId] ?? []; ?>
                                        <?php if ($allowedDocumentTypes === []): ?>
                                            <div class="alert alert-warning mb-3">
                                                Nu există tipuri de document configurate pentru acest tip de personal. Adaugă regula din configurarea tipului de personal.
                                            </div>
                                        <?php endif; ?>
                                        <form method="post" enctype="multipart/form-data" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'store_document'])) ?>" class="accountancy-inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="source_type" value="<?= e($sourceType) ?>">
                                            <input type="hidden" name="source_id" value="<?= e((string) $sourceId) ?>">
                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-3">
                                                    <label class="form-label">Tip document</label>
                                                    <select class="form-select" name="tip_document" required <?= $allowedDocumentTypes === [] ? 'disabled' : '' ?>>
                                                        <?php foreach ($allowedDocumentTypes as $documentType): ?>
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
                                                    <button type="submit" class="btn btn-primary" <?= $allowedDocumentTypes === [] ? 'disabled' : '' ?>>Adaugă Document</button>
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

        <div class="cp-table-footer">
            <small class="text-muted" data-cp-row-count data-total="<?= e((string) count($rows)) ?>"><?= e((string) count($rows)) ?> persoane</small>
        </div>
    </section>
</div>

<div class="modal fade" id="cpLegalCalendarModal" tabindex="-1" aria-labelledby="cpLegalCalendarTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content cp-modal">
            <div class="modal-header">
                <div>
                    <h3 class="modal-title fs-5" id="cpLegalCalendarTitle">
                        <span class="cp-flag-ro" aria-hidden="true"><i></i><i></i><i></i></span>
                        Calendar legal — <?= e($period['label']) ?>
                    </h3>
                    <div class="small text-muted">
                        Sărbători legale RO: Nager.Date<?= !empty($calendarMonth['sync']['synced_at']) ? ' · sincronizat la ' . e(format_datetime_ro((string) $calendarMonth['sync']['synced_at'])) : '' ?>.
                        Zi lucrătoare legală = Luni–Vineri, fără sărbători legale.
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Închide"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($calendarMonth['complete'])): ?>
                    <div class="cp-alert is-warning">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                        Calendar incomplet: <?= e((string) ($calendarMonth['sync']['error'] ?? 'sărbătorile legale nu sunt disponibile.')) ?>
                        Zilele de weekend sunt corecte, dar zilele lucrătoare nu pot fi confirmate.
                    </div>
                <?php endif; ?>
                <div class="cp-legal-modal-grid">
                    <div>
                        <?php $cal = $calendarMonth; $overlay = []; $calSize = 'lg'; require __DIR__ . '/_month_calendar.php'; ?>
                    </div>
                    <div>
                        <ul class="cp-stat-list">
                            <li><i class="bi bi-calendar3 is-blue" aria-hidden="true"></i><span>Zile calendaristice</span><b><?= e((string) ($calendarMonth['calendar_days'] ?? '—')) ?></b></li>
                            <li><i class="bi bi-briefcase-fill is-blue" aria-hidden="true"></i><span>Zile lucrătoare (RO)</span><b><?= e($calendarMonth['working_days'] !== null ? (string) $calendarMonth['working_days'] : '—') ?></b></li>
                            <li><i class="bi bi-cup-hot-fill is-slate" aria-hidden="true"></i><span>Zile weekend</span><b><?= e($calendarMonth['weekend_days'] !== null ? (string) $calendarMonth['weekend_days'] : '—') ?></b></li>
                            <li><i class="bi bi-star-fill is-red" aria-hidden="true"></i><span>Zile sărbători legale</span><b><?= e($calendarMonth['holiday_days'] !== null ? (string) $calendarMonth['holiday_days'] : '—') ?></b></li>
                        </ul>
                        <div class="cp-subtitle mt-3">Sărbători legale în <?= e($period['label']) ?></div>
                        <?php if (empty($calendarMonth['complete'])): ?>
                            <div class="small text-muted">Indisponibil.</div>
                        <?php elseif (($calendarMonth['holidays'] ?? []) === []): ?>
                            <div class="small text-muted">Nicio sărbătoare legală în această lună.</div>
                        <?php else: ?>
                            <ul class="cp-holiday-list">
                                <?php foreach ($calendarMonth['holidays'] as $holiday): ?>
                                    <?php $holidayDate = new DateTimeImmutable((string) $holiday['date']); ?>
                                    <li>
                                        <div class="cp-holiday-date"><?= e($holidayDate->format('j') . ' ' . LegalCalendarService::MONTH_NAMES[(int) $holidayDate->format('n')] . ' ' . $holidayDate->format('Y')) ?></div>
                                        <div class="fw-semibold"><?= e((string) $holiday['local_name']) ?></div>
                                        <div class="small text-muted">
                                            Sărbătoare legală<?= $holiday['on_weekend'] ? ' · cade în weekend (numărată o singură dată, ca weekend)' : '' ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="cp-alert is-info mt-3 mb-0">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Zilele lucrătoare legale nu sunt zilele lucrate de angajați: zilele lucrate se înregistrează separat, pe fiecare angajat (Pontaj &amp; Calendar).
                </div>
            </div>
        </div>
    </div>
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
                                <i class="bi bi-plus-lg" aria-hidden="true"></i> Adaugă document
                            </button>
                        </div>
                        <div class="vstack gap-3" data-role="staff-type-requirements-list" data-next-index="1">
                            <div class="border rounded-3 bg-white p-3" data-role="staff-type-requirement-row">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-5">
                                        <label class="form-label">Tip document</label>
                                        <input type="text" class="form-control" name="requirements[0][document_type]" value="Contract de muncă" readonly>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Data expirării</label>
                                        <input type="hidden" name="requirements[0][requires_expiry]" value="0">
                                        <select class="form-select" disabled>
                                            <option value="0" selected>Optionala</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Avertizare</label>
                                        <input type="hidden" name="requirements[0][warning_days]" value="30">
                                        <select class="form-select" name="requirements[0][warning_days]">
                                            <option value="30">30 zile</option>
                                            <option value="60">60 zile</option>
                                            <option value="90">90 zile</option>
                                        </select>
                                    </div>
                                    <div class="col-md-1 d-flex justify-content-md-end">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" aria-label="Document obligatoriu" disabled>
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
                                            <?php $isContractRequirement = $isEmploymentContractDocument((string) ($requirement['document_type'] ?? '')); ?>
                                            <tr>
                                                <td><?= e((string) ($requirement['document_type'] ?? '-')) ?></td>
                                                <td><?= !$isContractRequirement && (int) ($requirement['requires_expiry'] ?? 1) === 1 ? 'Da' : 'Nu' ?></td>
                                                <td><?= e((string) ($requirement['warning_days'] ?? 30)) ?> zile</td>
                                                <td>
                                                    <?php if ($isContractRequirement): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Fix</button>
                                                    <?php else: ?>
                                                        <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'delete_requirement'])) ?>">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="id" value="<?= e((string) ((int) ($requirement['id'] ?? 0))) ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Elimini acest document obligatoriu?">Elimină</button>
                                                        </form>
                                                    <?php endif; ?>
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
                        <select class="form-select" name="staff_type_id" data-role="accountancy-staff-type-select" required <?= $addStaffTypeOptions === [] ? 'disabled' : '' ?>>
                            <?php if ($addStaffTypeOptions === []): ?>
                                <option value="">Nu există tipuri active pentru filtrul curent</option>
                            <?php endif; ?>
                            <?php foreach ($addStaffTypeOptions as $type): ?>
                                <option
                                    value="<?= e((string) ((int) ($type['id'] ?? 0))) ?>"
                                    data-driver-linked="<?= (int) ($type['is_driver_linked'] ?? 0) === 1 ? '1' : '0' ?>"
                                    data-category="<?= e((string) ($type['category'] ?? '')) ?>"
                                >
                                    <?= e((string) ($type['name'] ?? '-')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($addStaffTypeOptions === []): ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                Schimba filtrul sau configureaza un tip de personal activ pentru categoria curenta.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div data-role="accountancy-driver-fields">
                        <div class="btn-group w-100 mb-3" role="group" aria-label="Tip șofer">
                            <input type="radio" class="btn-check" name="driver_mode" id="driverModeExisting" value="existent" checked data-role="accountancy-driver-mode">
                            <label class="btn btn-outline-primary" for="driverModeExisting">Șofer angajat existent</label>
                            <input type="radio" class="btn-check" name="driver_mode" id="driverModeCollaborator" value="colaborator" data-role="accountancy-driver-mode">
                            <label class="btn btn-outline-primary" for="driverModeCollaborator">Șofer colaborator (neangajat)</label>
                        </div>

                        <div data-role="accountancy-driver-mode-panel" data-mode="colaborator">
                            <div class="alert alert-info">Colaboratorul se adaugă în lista de șoferi, ca să poată fi ales pe curse în Dispecer curse. Nu este angajat al firmei.</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Nume complet</label>
                                    <input type="text" class="form-control" name="colaborator_nume" maxlength="100" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Telefon</label>
                                    <input type="text" class="form-control" name="colaborator_telefon" maxlength="20">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Vehicule pe care conduce</label>
                                    <select class="form-select" name="colaborator_vehicle_ids[]" multiple size="5">
                                        <?php foreach ($vehicleOptions as $vehicle): ?>
                                            <option value="<?= e((string) ((int) ($vehicle['id'] ?? 0))) ?>"><?= e((string) ($vehicle['nr_inmatriculare'] ?? '-')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">În Dispecer curse șoferul apare doar la vehiculele asociate. Ctrl+click pentru mai multe; primul selectat devine vehiculul principal.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Remunerație lunară</label>
                                    <input type="number" min="0" step="0.01" class="form-control" name="colaborator_salariu" placeholder="Opțional">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Început colaborare</label>
                                    <input type="date" class="form-control" name="colaborator_data_inceput" value="<?= e(date('Y-m-d')) ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Observații</label>
                                    <textarea class="form-control" name="colaborator_observatii" rows="2" placeholder="Ex.: firma colaboratorului, condiții de colaborare"></textarea>
                                </div>
                            </div>
                        </div>

                        <div data-role="accountancy-driver-mode-panel" data-mode="existent">
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
                    </div>

                    <div data-role="accountancy-direct-fields">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nume complet</label>
                                <input type="text" class="form-control" name="nume_complet">
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
                                <div class="small text-muted">Poți încărca direct aici documentele cerute. Liniile lăsate goale nu se salvează.</div>
                            </div>
                            <span class="badge text-bg-light border" data-role="accountancy-required-docs-count">0 documente</span>
                        </div>
                        <div class="alert alert-info py-2 px-3 mb-3 d-none" data-role="accountancy-required-docs-driver-note">
                            Pentru tipul Șofer, documentele se administrează din modulul Șoferi.
                        </div>
                        <div class="small text-muted mb-3 d-none" data-role="accountancy-required-docs-empty">
                            Tipul selectat nu are documente obligatorii configurate inca.
                        </div>
                        <div class="vstack gap-3" data-role="accountancy-required-docs-list"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anulează</button>
                    <button type="submit" class="btn btn-primary" <?= $addStaffTypeOptions === [] ? 'disabled' : '' ?>>Salvează</button>
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
                        '<label class="form-label">Data expirării</label>' +
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
            ? 'Completează și data expirării când documentul o are.'
            : 'Poți lăsa data expirării goală pentru acest document.';

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
                        '<label class="form-label">Număr document</label>' +
                        '<input type="text" class="form-control" name="staff_documents[' + index + '][numar_document]">' +
                    '</div>' +
                    '<div class="col-md-4">' +
                        '<label class="form-label">Data emiterii</label>' +
                        '<input type="date" class="form-control" name="staff_documents[' + index + '][data_emitere]">' +
                    '</div>' +
                    '<div class="col-md-4">' +
                        '<label class="form-label">Data expirării</label>' +
                        '<input type="date" class="form-control" name="staff_documents[' + index + '][data_expirare]">' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Fișier document</label>' +
                        '<input type="file" class="form-control" name="staff_document_files[' + index + ']" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">' +
                    '</div>' +
                    '<div class="col-12">' +
                        '<label class="form-label">Observații</label>' +
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
                var checkedMode = driverFields.querySelector('[data-role="accountancy-driver-mode"]:checked');
                var driverMode = checkedMode ? checkedMode.value : 'existent';
                driverFields.querySelectorAll('[data-role="accountancy-driver-mode"]').forEach(function (input) {
                    input.disabled = !isDriver;
                });
                driverFields.querySelectorAll('[data-role="accountancy-driver-mode-panel"]').forEach(function (panel) {
                    var active = isDriver && panel.getAttribute('data-mode') === driverMode;
                    panel.classList.toggle('d-none', !active);
                    panel.querySelectorAll('select, input, textarea').forEach(function (input) {
                        input.disabled = !active;
                    });
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
        form.querySelectorAll('[data-role="accountancy-driver-mode"]').forEach(function (input) {
            input.addEventListener('change', syncFields);
        });
        syncFields();
    });

});
</script>

<script>
// Venit din Istoric activitati sofer (cardul Cost total): ?open=salary|diurna&subject=driver-<id>
// deschide direct fereastra de salariu / diurna a soferului. Bootstrap se incarca in
// footer, dupa acest script, deci fereastra se deschide la 'load'.
window.addEventListener('load', function () {
    var params = new URLSearchParams(window.location.search);
    var modalPrefix = { salary: 'salaryModal', diurna: 'diurnaModal' }[params.get('open') || ''];
    var subject = (params.get('subject') || '').replace(/[^a-zA-Z0-9_-]/g, '');
    var modal = modalPrefix && subject ? document.getElementById(modalPrefix + subject) : null;
    if (modal && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }
});

// Diurna: campul "Valoare / zi" are sens doar cand soferul primeste diurna.
document.querySelectorAll('[data-diurna-form]').forEach(function (form) {
    var receives = form.querySelector('[data-diurna-receives]');
    var rateField = form.querySelector('[data-diurna-rate-field]');
    if (!receives || !rateField) {
        return;
    }
    var rateInput = rateField.querySelector('input');
    var sync = function () {
        var on = receives.value === '1';
        rateField.hidden = !on;
        rateInput.required = on;
    };
    receives.addEventListener('change', sync);
    sync();
});
</script>

<?php if ($fiscalEnabled) { require __DIR__ . '/_payroll_config.php'; } ?>

<script src="<?= e(url('assets/js/table-column-filter.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/table-column-filter.js'))) ?>"></script>
<script src="<?= e(url('assets/js/contabilitate-personal.js?v=' . (string) @filemtime(BASE_PATH . '/assets/js/contabilitate-personal.js'))) ?>"></script>
