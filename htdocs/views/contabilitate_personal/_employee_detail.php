<?php
/**
 * Randul extins al unui angajat, incarcat la cerere (?action=employee_detail).
 *
 * Variabile: $row, $period, $calendarMonth, $salaryHistory, $documents, $month,
 * $diurnaPolicy, $canEditSalary, $canReopen, $canOpenLeavePlanning.
 */
$sourceType = (string) $row['source_type'];
$sourceId = (int) $row['source_id'];
$rowId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sourceType . '-' . $sourceId);
$isDriver = $sourceType === 'driver';
$record = $month['record'];
$finalized = (bool) $month['finalized'];
$isCollaborator = (string) ($row['tip_colaborare'] ?? '') === 'colaborator';
$category = (string) ($row['category'] ?? 'operational');
$vehicleLabel = trim((string) ($row['vehicle_label'] ?? ''));
$photoUrl = $isDriver ? driver_image_url((string) ($row['poza_stocata'] ?? '')) : null;

$moneyFull = static fn (?float $value): string => $value === null ? '—' : format_number_ro($value, 2) . ' RON';
$money = static fn (mixed $value): string => $value === null || $value === '' ? '—' : format_number_ro((float) $value, 0) . ' RON';
$daysValue = static function (?float $value): string {
    if ($value === null) {
        return '—';
    }
    return floor($value) === $value ? (string) (int) $value : format_number_ro($value, 1);
};
$inputValue = static function (mixed $value): string {
    if ($value === null || $value === '') {
        return '';
    }
    $text = is_float($value) ? sprintf('%.2F', $value) : (string) $value;
    return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
};
$initials = static function (string $name): string {
    $letters = '';
    foreach (preg_split('/\s+/u', trim($name)) ?: [] as $part) {
        if ($part !== '') {
            $letters .= mb_substr($part, 0, 1);
        }
        if (mb_strlen($letters) >= 2) {
            break;
        }
    }
    return mb_strtoupper($letters !== '' ? $letters : '?');
};
$salarySourceLabel = match ((string) $month['applicable_salary_source']) {
    'istoric' => 'din istoricul salarial' . (!empty($month['applicable_salary_since']) ? ', valabil din ' . format_date_ro((string) $month['applicable_salary_since']) : ''),
    'curent' => 'fără modificări înregistrate — salariul din fișă',
    'neangajat' => 'angajatul nu era angajat în această lună',
    default => 'salariul pentru această lună nu este cunoscut',
};
$salaryPeriods = StaffAccountancyModel::salaryPeriods($salaryHistory);
$calSync = is_array($calendarMonth['sync'] ?? null) ? $calendarMonth['sync'] : [];
$calInfo = !empty($calendarMonth['complete'])
    ? 'Sărbători legale RO din Nager.Date' . (!empty($calSync['synced_at']) ? ', sincronizate la ' . format_datetime_ro((string) $calSync['synced_at']) : '') . '. Zilele lucrătoare = Luni–Vineri fără sărbători.'
    : 'Calendar incomplet: ' . (string) ($calSync['error'] ?? 'sărbătorile legale nu sunt disponibile.');
$recordStatusBadge = match ((string) $month['record_status']) {
    'finalizat' => '<span class="cp-badge is-success"><i class="bi bi-lock-fill" aria-hidden="true"></i> Finalizată</span>',
    'ciorna' => '<span class="cp-badge is-warning">Ciornă</span>',
    default => '<span class="cp-badge is-muted">Neînregistrată</span>',
};
$monthOptions = [];
$optionCursor = (new DateTimeImmutable($period['start']))->modify('-12 months');
for ($i = 0; $i <= 24; $i++) {
    $monthOptions[$optionCursor->format('Y-m')] = LegalCalendarService::MONTH_NAMES[(int) $optionCursor->format('n')] . ' ' . $optionCursor->format('Y');
    $optionCursor = $optionCursor->modify('+1 month');
}
$hiddenMonthFields = csrf_field()
    . '<input type="hidden" name="source_type" value="' . e($sourceType) . '">'
    . '<input type="hidden" name="source_id" value="' . e((string) $sourceId) . '">'
    . '<input type="hidden" name="luna" value="' . e($period['key']) . '">'
    . '<input type="hidden" name="return_open" value="' . e($rowId) . '">';
$prefillBase = $record !== null ? $record['salariu_baza'] : $month['applicable_salary'];

// --- calcul salarial (payroll_monthly) ---------------------------------------
$payrollStale = (bool) ($payrollStatus['stale'] ?? false);
$payrollConfirmed = $payrollRecord !== null && $payrollRecord['confirmation_status'] === 'confirmat';
$payrollHasResult = $payrollRecord !== null && in_array($payrollRecord['calculation_status'], ['calculat', 'de_verificat'], true);
$decode = static fn (mixed $json): array => is_string($json) && $json !== '' ? (array) (json_decode($json, true) ?? []) : [];
$payrollDetails = $payrollRecord !== null ? $decode($payrollRecord['calculation_details']) : [];
$payrollMissing = $payrollRecord !== null ? $decode($payrollRecord['missing_fields']) : [];
$payrollWarnings = $payrollRecord !== null ? $decode($payrollRecord['warnings']) : [];
if ($payrollRecord === null && is_array($payrollPreview ?? null) && !in_array($payrollPreview['status'], ['calculat', 'de_verificat'], true)) {
    $payrollMissing = (array) $payrollPreview['missing'];
} elseif ($payrollRecord === null && $payrollRuleLookup['rule'] === null) {
    $payrollMissing[] = (string) $payrollRuleLookup['error'];
}
$pNum = static fn (string $field): ?float => $payrollRecord !== null && $payrollRecord[$field] !== null ? (float) $payrollRecord[$field] : null;
$pRate = static fn (string $field): string => isset($payrollDetails['rule'][$field]) ? format_number_ro((float) $payrollDetails['rule'][$field], 2) . '%' : '—';
$payrollOtherCosts = (float) ($payrollDetails['in_kind_benefits'] ?? 0) + (float) ($pNum('non_taxable_additions') ?? 0);
$payrollMeta = PayrollMonthService::statusMeta($payrollRecord, $payrollStale);
$payrollStatusLabel = $payrollMeta['label'];
$payrollBadge = '<span class="cp-status-stack"><span class="cp-badge ' . e($payrollMeta['class']) . '">' . e($payrollMeta['label']) . '</span>'
    . ($payrollMeta['confirm_label'] !== null ? '<span class="cp-badge ' . e($payrollMeta['confirm_class']) . '">' . e($payrollMeta['confirm_label']) . '</span>' : '') . '</span>';
$profileComplete = $payrollProfile !== null;
foreach (['contract_type', 'norm_type', 'is_basic_function', 'salary_input_type', 'dependents_count', 'children_in_school', 'under_26', 'work_conditions', 'tax_exemption'] as $profileField) {
    if ($payrollProfile === null || $payrollProfile[$profileField] === null) {
        $profileComplete = false;
    }
}
if ($profileComplete && $payrollProfile['norm_type'] === 'part' && ($payrollProfile['hours_per_day'] === null || $payrollProfile['min_base_exemption'] === null)) {
    $profileComplete = false;
}
$ageNow = $birthDate !== null ? (int) (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable($period['end']))->y : null;
?>
<div class="cp-detail-inner" data-cp-detail="<?= e($rowId) ?>" data-cp-period="<?= e($period['key']) ?>">
    <div class="cp-detail-bar">
        <div class="cp-tabs" role="tablist">
            <button type="button" class="cp-tab is-active" data-cp-tab="sumar" role="tab"><i class="bi bi-house-door-fill" aria-hidden="true"></i>Sumar</button>
            <button type="button" class="cp-tab" data-cp-tab="pontaj" role="tab"><i class="bi bi-calendar3" aria-hidden="true"></i>Pontaj &amp; Calendar</button>
            <?php if ($fiscalEnabled): ?>
                <button type="button" class="cp-tab" data-cp-tab="calcul" role="tab"><i class="bi bi-calculator" aria-hidden="true"></i>Calcul salarial</button>
            <?php endif; ?>
            <button type="button" class="cp-tab" data-cp-tab="istoric" role="tab"><i class="bi bi-clock-history" aria-hidden="true"></i>Istoric salarial</button>
            <button type="button" class="cp-tab" data-cp-tab="fluturasi" role="tab"><i class="bi bi-receipt" aria-hidden="true"></i>Fluturași</button>
            <button type="button" class="cp-tab" data-cp-tab="documente" role="tab"><i class="bi bi-files" aria-hidden="true"></i>Documente<?= $documents !== [] ? ' <span class="cp-tab-count">' . e((string) count($documents)) . '</span>' : '' ?></button>
            <button type="button" class="cp-tab" data-cp-tab="notite" role="tab"><i class="bi bi-chat-left-text" aria-hidden="true"></i>Notițe</button>
        </div>
        <div class="cp-detail-tools">
            <span class="d-inline-block" tabindex="0" title="Fluturașii se activează după definirea regulilor de calcul salarial.">
                <button type="button" class="btn btn-sm btn-outline-primary cp-btn" disabled>
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i> Generează fluturaș
                </button>
            </span>
            <div class="cp-month-picker is-compact">
                <button type="button" class="cp-month-nav" data-cp-detail-month="<?= e($period['prev']) ?>" aria-label="Luna anterioară"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                <select class="form-select form-select-sm cp-month-select" data-cp-detail-month-select aria-label="Luna afișată pentru angajat">
                    <?php foreach ($monthOptions as $optionKey => $optionLabel): ?>
                        <option value="<?= e($optionKey) ?>" <?= $optionKey === $period['key'] ? 'selected' : '' ?>><?= e($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="cp-month-nav" data-cp-detail-month="<?= e($period['next']) ?>" aria-label="Luna următoare"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            </div>
        </div>
    </div>

    <!-- ===================== SUMAR ===================== -->
    <div class="cp-pane is-active" data-cp-pane="sumar">
        <div class="cp-summary-grid">
            <section class="cp-panel cp-profile">
                <div class="cp-profile-head">
                    <div class="cp-avatar is-lg <?= $photoUrl !== null ? 'has-photo' : '' ?>">
                        <?php if ($photoUrl !== null): ?>
                            <img src="<?= e($photoUrl) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <?= e($initials((string) $row['nume'])) ?>
                        <?php endif; ?>
                    </div>
                    <div class="min-w-0">
                        <div class="cp-profile-name"><?= e((string) $row['nume']) ?></div>
                        <div class="cp-profile-badges">
                            <?php if ($isCollaborator): ?><span class="cp-badge is-collab">Colaborator</span><?php endif; ?>
                            <span class="cp-type-pill <?= $category === 'office' ? 'is-office' : 'is-operational' ?>"><?= e((string) ($row['staff_type_name'] ?? '-')) ?></span>
                        </div>
                        <?php if ($isDriver && $vehicleLabel !== '' && $vehicleLabel !== '-'): ?>
                            <div class="cp-profile-sub"><i class="bi bi-truck" aria-hidden="true"></i> <?= e($vehicleLabel) ?></div>
                        <?php elseif (!$isDriver && !empty($row['email'])): ?>
                            <div class="cp-profile-sub"><?= e((string) $row['email']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <dl class="cp-kv">
                    <div><dt>Tip personal</dt><dd><?= e($category === 'office' ? 'Personal de birou' : 'Personal operațional') ?></dd></div>
                    <div><dt>Funcție</dt><dd><?= e((string) ($row['functie'] ?? '-')) ?></dd></div>
                    <div><dt>Data angajării</dt><dd class="fw-semibold"><?= e(!empty($row['data_angajare']) ? format_date_ro((string) $row['data_angajare']) : '—') ?></dd></div>
                    <div>
                        <dt>Regim de lucru</dt>
                        <dd>
                            <?php $regimeLive = StaffAccountancyModel::workRegimeLabel($row['regim_lucru'] ?? null, $row['regim_lucru_detalii'] ?? null); ?>
                            <?= $regimeLive !== null ? e($regimeLive) : '<span class="text-muted">Nesetat</span>' ?>
                            <button type="button" class="cp-icon-btn" data-cp-toggle="regime-<?= e($rowId) ?>" title="Modifică regimul de lucru" aria-label="Modifică regimul de lucru"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                        </dd>
                    </div>
                    <form method="post" class="cp-inline-form" id="regime-<?= e($rowId) ?>" hidden action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'update_work_regime'])) ?>">
                        <?= $hiddenMonthFields ?>
                        <select class="form-select form-select-sm" name="regim_lucru" data-cp-regime-select>
                            <option value="">Nesetat</option>
                            <?php foreach (StaffAccountancyModel::WORK_REGIMES as $regimeKey => $regimeLabel): ?>
                                <option value="<?= e($regimeKey) ?>" <?= (string) ($row['regim_lucru'] ?? '') === $regimeKey ? 'selected' : '' ?>><?= e($regimeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="form-control form-control-sm" name="regim_lucru_detalii" maxlength="120" placeholder="Ex.: 2 zile lucru / 2 zile liber" value="<?= e((string) ($row['regim_lucru_detalii'] ?? '')) ?>" data-cp-regime-details <?= (string) ($row['regim_lucru'] ?? '') === 'personalizat' ? '' : 'hidden' ?>>
                        <div class="cp-inline-form-note">Regimul descrie programul angajatului. Nu completează automat zilele lucrate.</div>
                        <button type="submit" class="btn btn-sm btn-primary">Salvează</button>
                    </form>
                    <div>
                        <dt>Salariu actual</dt>
                        <dd class="fw-semibold">
                            <?= e($money($row['salariu'] ?? null)) ?>
                            <button type="button" class="cp-icon-btn" data-bs-toggle="modal" data-bs-target="#salaryModal<?= e($rowId) ?>" title="Modifică salariul (cu istoric)" aria-label="Modifică salariul"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                        </dd>
                    </div>
                    <?php if ($isDriver && $diurnaPolicy !== null): ?>
                        <div>
                            <dt>Diurnă</dt>
                            <dd>
                                <span class="accountancy-diurna-pill is-<?= e($diurnaPolicy['status']) ?>"><?= e(DriverDiurnaModel::label($diurnaPolicy)) ?></span>
                                <button type="button" class="cp-icon-btn" data-bs-toggle="modal" data-bs-target="#diurnaModal<?= e($rowId) ?>" title="Stabilește diurna" aria-label="Stabilește diurna"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <div><dt>Disponibilitate</dt><dd><?= (string) ($row['status'] ?? 'activ') === 'inactiv' ? '<span class="cp-badge is-muted" title="Indisponibil temporar; rămâne angajat">Inactiv temporar</span>' : '<span class="cp-badge is-success">Disponibil</span>' ?></dd></div>
                </dl>
            </section>

            <section class="cp-panel">
                <h4 class="cp-panel-title">
                    Calendar legal — <?= e($period['label']) ?>
                    <i class="bi bi-info-circle cp-info" title="<?= e($calInfo) ?>" aria-label="<?= e($calInfo) ?>"></i>
                </h4>
                <?php if (empty($calendarMonth['complete'])): ?>
                    <div class="cp-alert is-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Sărbătorile legale nu sunt disponibile. Zilele lucrătoare nu pot fi confirmate.</div>
                <?php endif; ?>
                <?php $cal = $calendarMonth; $overlay = $month['leave_days']; $calSize = 'sm'; require __DIR__ . '/_month_calendar.php'; ?>
            </section>

            <section class="cp-panel">
                <h4 class="cp-panel-title">Rezumat lună</h4>
                <ul class="cp-stat-list">
                    <li><i class="bi bi-calendar3 is-blue" aria-hidden="true"></i><span>Zile calendaristice</span><b><?= e((string) ($calendarMonth['calendar_days'] ?? '—')) ?></b></li>
                    <li><i class="bi bi-briefcase-fill is-blue" aria-hidden="true"></i><span>Zile lucrătoare (RO)</span><b><?= e($calendarMonth['working_days'] !== null ? (string) $calendarMonth['working_days'] : '—') ?></b></li>
                    <li><i class="bi bi-cup-hot-fill is-slate" aria-hidden="true"></i><span>Zile weekend</span><b><?= e($calendarMonth['weekend_days'] !== null ? (string) $calendarMonth['weekend_days'] : '—') ?></b></li>
                    <li>
                        <i class="bi bi-star-fill is-red" aria-hidden="true"></i><span>Zile sărbători legale</span>
                        <b title="<?= !empty($calendarMonth['holidays_on_weekend']) ? e($calendarMonth['holidays_on_weekend'] . ' sărbătoare(i) în weekend, numărate la weekend') : '' ?>"><?= e($calendarMonth['holiday_days'] !== null ? (string) $calendarMonth['holiday_days'] : '—') ?></b>
                    </li>
                </ul>
                <div class="cp-stat-divider"><span>Angajat</span></div>
                <ul class="cp-stat-list">
                    <li>
                        <i class="bi bi-person-check-fill is-green" aria-hidden="true"></i><span>Zile lucrate (angajat)</span>
                        <b title="<?= $month['worked_days'] === null ? 'Pontajul lunii nu este înregistrat' : '' ?>"><?= e($daysValue($month['worked_days'])) ?></b>
                    </li>
                    <li>
                        <i class="bi bi-airplane-fill is-blue" aria-hidden="true"></i>
                        <span>Concediu (CO)<?php if ($isDriver): ?> <small class="cp-source" title="Read-only, din Programare concedii">planificare</small><?php endif; ?></span>
                        <b><?= e($daysValue($month['co_days'])) ?></b>
                    </li>
                    <li>
                        <i class="bi bi-plus-square-fill is-blue" aria-hidden="true"></i>
                        <span>Concediu medical (CM)<?php if ($isDriver): ?> <small class="cp-source" title="Read-only, din Programare concedii">planificare</small><?php endif; ?></span>
                        <b><?= e($daysValue($month['cm_days'])) ?></b>
                    </li>
                    <li><i class="bi bi-exclamation-triangle-fill is-blue" aria-hidden="true"></i><span>Zile absente</span><b><?= e($daysValue($month['absent_days'])) ?></b></li>
                </ul>
            </section>

            <?php if (!$fiscalEnabled): ?>
            <section class="cp-panel">
                <h4 class="cp-panel-title">Costuri salariale (<?= e($period['label']) ?>)</h4>
                <ul class="cp-cost-list">
                    <li><span>Salariu configurat (lună)</span><b><?= e($moneyFull($month['applicable_salary'])) ?></b></li>
                    <li><span>Salariu actual (fișă)</span><b><?= e($moneyFull($row['salariu'] !== null ? (float) $row['salariu'] : null)) ?></b></li>
                </ul>
                <div class="cp-cost-total">
                    <span>Cost salarial lună</span>
                    <b><?= e($moneyFull($month['applicable_salary'])) ?></b>
                </div>
                <div class="cp-note">
                    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                    <div>
                        Costul lunii este salariul configurat (<?= e($salarySourceLabel) ?>).
                        Calculul fiscal (brut/net, contribuții, cost firmă) este opțional și dezactivat.
                    </div>
                </div>
            </section>
            <?php else: ?>
            <section class="cp-panel">
                <h4 class="cp-panel-title d-flex justify-content-between align-items-center gap-2">
                    <span>Costuri salariale (<?= e($period['label']) ?>)</span>
                    <?= $payrollBadge ?>
                </h4>
                <?php if ($payrollHasResult): ?>
                    <ul class="cp-cost-list">
                        <li><span>Salariu net (angajat)</span><b><?= e($moneyFull($pNum('net_salary'))) ?></b></li>
                        <li><span>Salariu brut</span><b><?= e($moneyFull($pNum('gross_salary'))) ?></b></li>
                    </ul>
                    <div class="cp-cost-group">Rețineri / contribuții angajat</div>
                    <ul class="cp-cost-list">
                        <li><span>CAS (<?= e($pRate('cas_employee_rate')) ?>)</span><b><?= e($moneyFull($pNum('cas'))) ?></b></li>
                        <li><span>CASS (<?= e($pRate('cass_employee_rate')) ?>)</span><b><?= e($moneyFull($pNum('cass'))) ?></b></li>
                        <li><span>Impozit pe venit (<?= e($pRate('income_tax_rate')) ?>)</span><b><?= e($moneyFull($pNum('income_tax'))) ?></b></li>
                    </ul>
                    <div class="cp-cost-group">Angajator</div>
                    <ul class="cp-cost-list">
                        <li><span>CAM (<?= e($pRate('cam_employer_rate')) ?>)</span><b><?= e($moneyFull($pNum('cam'))) ?></b></li>
                        <li><span>Diferențe contribuții</span><b><?= e($moneyFull($pNum('employer_contribution_differences'))) ?></b></li>
                        <li><span>Alte costuri (beneficii în natură, sume neimpozabile)</span><b><?= e($moneyFull($payrollOtherCosts)) ?></b></li>
                    </ul>
                    <div class="cp-cost-group">Ajustări</div>
                    <ul class="cp-cost-list">
                        <li><span>Sporuri</span><b><?= e($moneyFull($pNum('bonuses'))) ?></b></li>
                        <li><span>Sumă netaxabilă (salariu minim)</span><b><?= e($moneyFull($pNum('non_taxable_amount'))) ?></b></li>
                        <li><span>Rețineri suplimentare</span><b><?= e(($pNum('other_deductions') ?? 0) > 0 ? '−' . $moneyFull($pNum('other_deductions')) : $moneyFull(0.0)) ?></b></li>
                    </ul>
                    <div class="cp-cost-total">
                        <span>Cost total firmă</span>
                        <b><?= e($moneyFull($pNum('total_employer_cost'))) ?></b>
                    </div>
                    <div class="cp-cost-meta">
                        Sursă: Calcul aplicație · Regulă: <?= e((string) ($payrollRecord['fiscal_rule_name'] ?? '—')) ?>
                        · Calculat: <?= e(!empty($payrollRecord['calculated_at']) ? format_datetime_ro((string) $payrollRecord['calculated_at']) : '—') ?>
                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-cp-goto="calcul">detalii</button>
                    </div>
                    <?php if ($payrollConfirmed): ?>
                        <div class="cp-confirmed">
                            <i class="bi bi-check-square-fill" aria-hidden="true"></i>
                            Confirmat contabil la <?= e(format_date_ro((string) $payrollRecord['confirmed_at'])) ?>
                            <span><i class="bi bi-person" aria-hidden="true"></i> <?= e((string) ($payrollRecord['confirmed_by_name'] ?? '—')) ?></span>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="cp-note">
                        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                        <div>
                            <?php if ($payrollRecord === null): ?>
                                Salariul pentru <?= e($period['label']) ?> nu a fost calculat.
                            <?php else: ?>
                                <?= e($payrollStatusLabel) ?>: calculul nu a produs un rezultat final.
                            <?php endif; ?>
                            Salariul contractual aplicabil lunii: <b><?= e($money($month['applicable_salary'])) ?></b> (<?= e($salarySourceLabel) ?>).
                            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-cp-goto="calcul">Deschide calculul salarial</button>
                        </div>
                    </div>
                    <?php if ($payrollMissing !== []): ?>
                        <ul class="cp-missing-list mt-2">
                            <?php foreach (array_slice($payrollMissing, 0, 4) as $missingItem): ?>
                                <li><?= e((string) $missingItem) ?></li>
                            <?php endforeach; ?>
                            <?php if (count($payrollMissing) > 4): ?><li>… încă <?= e((string) (count($payrollMissing) - 4)) ?></li><?php endif; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===================== PONTAJ & CALENDAR ===================== -->
    <div class="cp-pane" data-cp-pane="pontaj" hidden>
        <div class="cp-pontaj-grid">
            <section class="cp-panel">
                <h4 class="cp-panel-title">
                    Calendar legal și concedii — <?= e($period['label']) ?>
                    <i class="bi bi-info-circle cp-info" title="<?= e($calInfo) ?>" aria-label="<?= e($calInfo) ?>"></i>
                </h4>
                <?php $cal = $calendarMonth; $overlay = $month['leave_days']; $calSize = 'lg'; require __DIR__ . '/_month_calendar.php'; ?>

                <?php if ($isDriver): ?>
                    <div class="cp-subtitle mt-3">
                        Concedii aprobate
                        <span class="cp-source" title="Aprobarea (cu verificarea înlocuitorilor și a acoperirii flotei) se face doar în Programare concedii.">read-only · Programare concedii</span>
                    </div>
                    <?php if ($month['leaves'] === []): ?>
                        <div class="text-muted small">Niciun concediu aprobat în <?= e($period['label']) ?>.</div>
                    <?php else: ?>
                        <ul class="cp-leave-list">
                            <?php foreach ($month['leaves'] as $leave): ?>
                                <?php $leaveType = StaffAccountancyModel::LEAVE_TYPES[(string) $leave['tip_concediu']] ?? ['code' => '?', 'label' => 'Concediu']; ?>
                                <li>
                                    <span class="cp-cal-mark is-<?= e(strtolower($leaveType['code'])) ?>"><?= e($leaveType['code']) ?></span>
                                    <div>
                                        <div class="fw-semibold"><?= e($leaveType['label']) ?></div>
                                        <div class="small text-muted">
                                            <?= e(format_date_ro((string) $leave['data_inceput'])) ?> – <?= e(format_date_ro((string) $leave['data_sfarsit'])) ?>
                                            · Status: Aprobat · Sursă: Planificare concedii
                                            <?php if (!empty($leave['inlocuitor_nume'])): ?> · Înlocuitor: <?= e((string) $leave['inlocuitor_nume']) ?><?php endif; ?>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($canOpenLeavePlanning): ?>
                        <a class="small" href="<?= e(build_query_url(['page' => 'programare_concedii'])) ?>"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Modificările de concediu se fac în Programare concedii</a>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="cp-panel">
                <h4 class="cp-panel-title d-flex justify-content-between align-items-center gap-2">
                    <span>Pontaj — <?= e($period['label']) ?></span>
                    <?= $record !== null && $record['zile_lucrate'] !== null ? '<span class="cp-badge is-success">Înregistrat</span>' : '<span class="cp-badge is-muted">Neînregistrat</span>' ?>
                </h4>

                <?php if ($payrollConfirmed): ?>
                    <div class="cp-alert is-success">
                        <i class="bi bi-lock-fill" aria-hidden="true"></i>
                        Calculul salarial al lunii este confirmat: pontajul este înghețat (valorile folosite sunt în „Calcul salarial”).
                    </div>
                    <dl class="cp-kv">
                        <div><dt>Zile lucrate (angajat)</dt><dd><?= e($daysValue($month['worked_days'])) ?></dd></div>
                        <div><dt>Concediu (CO)</dt><dd><?= e($daysValue($month['co_days'])) ?></dd></div>
                        <div><dt>Concediu medical (CM)</dt><dd><?= e($daysValue($month['cm_days'])) ?></dd></div>
                        <div><dt>Zile absente</dt><dd><?= e($daysValue($month['absent_days'])) ?></dd></div>
                    </dl>
                <?php elseif (!$canEditSalary): ?>
                    <div class="cp-alert is-muted"><i class="bi bi-shield-lock" aria-hidden="true"></i> Nu ai dreptul „Salarii &amp; istoric salarial”, deci nu poți înregistra pontajul lunii.</div>
                <?php elseif ((string) $month['applicable_salary_source'] === 'neangajat'): ?>
                    <div class="cp-alert is-muted"><i class="bi bi-info-circle" aria-hidden="true"></i> Angajatul nu era angajat în <?= e($period['label']) ?>.</div>
                <?php else: ?>
                    <form method="post" class="cp-month-form" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'save_month'])) ?>">
                        <?= $hiddenMonthFields ?>
                        <input type="hidden" name="return_tab" value="pontaj">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label" for="cpWorked<?= e($rowId) ?>">Zile lucrate (angajat)</label>
                                <input type="number" class="form-control form-control-sm" id="cpWorked<?= e($rowId) ?>" name="zile_lucrate" min="0" max="31" step="0.5" value="<?= e($inputValue($record['zile_lucrate'] ?? null)) ?>">
                                <div class="form-text">Zile lucrătoare RO: <?= e($calendarMonth['working_days'] !== null ? (string) $calendarMonth['working_days'] : '—') ?> — doar informativ<?= $isDriver ? '; la șoferi contează regimul real' : '' ?>, nu se preia automat.</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="cpAbsent<?= e($rowId) ?>">Zile absente (nemotivate)</label>
                                <input type="number" class="form-control form-control-sm" id="cpAbsent<?= e($rowId) ?>" name="zile_absente" min="0" max="31" step="0.5" value="<?= e($inputValue($record['zile_absente'] ?? null)) ?>">
                                <div class="form-text">0 dacă nu există — gol înseamnă necompletat.</div>
                            </div>
                            <?php if ($isDriver): ?>
                                <div class="col-12">
                                    <div class="cp-readonly-field">
                                        <span>CO: <b><?= e($daysValue($month['co_days'])) ?></b> · CM: <b><?= e($daysValue($month['cm_days'])) ?></b> zile</span>
                                        <small>Din Programare concedii (aprobat), read-only. Nu se introduc aici.</small>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="col-6">
                                    <label class="form-label" for="cpCo<?= e($rowId) ?>">Concediu (CO)</label>
                                    <input type="number" class="form-control form-control-sm" id="cpCo<?= e($rowId) ?>" name="zile_co" min="0" max="31" step="0.5" value="<?= e($inputValue($record['zile_co'] ?? null)) ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="cpCm<?= e($rowId) ?>">Concediu medical (CM)</label>
                                    <input type="number" class="form-control form-control-sm" id="cpCm<?= e($rowId) ?>" name="zile_cm" min="0" max="31" step="0.5" value="<?= e($inputValue($record['zile_cm'] ?? null)) ?>">
                                </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label" for="cpNotes<?= e($rowId) ?>">Observații lună</label>
                                <textarea class="form-control form-control-sm" id="cpNotes<?= e($rowId) ?>" name="observatii" rows="2"><?= e((string) ($record['observatii'] ?? '')) ?></textarea>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save" aria-hidden="true"></i> Salvează pontajul</button>
                        </div>
                    </form>
                    <div class="form-text mt-2">
                        <?= $fiscalEnabled
                            ? 'Costul salarial se calculează în „Calcul salarial” din salariu, profil, pontaj și sporuri/rețineri.'
                            : 'Costul salarial al lunii este salariul configurat; pontajul este informativ.' ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <?php if ($fiscalEnabled): ?>
    <!-- ===================== CALCUL SALARIAL ===================== -->
    <div class="cp-pane" data-cp-pane="calcul" hidden>
        <div class="cp-pontaj-grid">
            <section class="cp-panel">
                <h4 class="cp-panel-title d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span>Calcul salarial — <?= e($period['label']) ?></span>
                    <?= $payrollBadge ?>
                </h4>

                <?php if ($payrollRuleLookup['rule'] === null): ?>
                    <div class="cp-alert is-warning">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                        <div>
                            <?= e((string) $payrollRuleLookup['error']) ?>
                            <div><button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="offcanvas" data-bs-target="#cpPayrollConfig">Configurează perioada</button></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($payrollStale && !$payrollConfirmed && $payrollRecord !== null): ?>
                    <div class="cp-alert is-warning"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Necesită recalculare: salariul, profilul, pontajul, sporurile sau regula fiscală s-au schimbat după calcul.</div>
                <?php endif; ?>

                <?php if ($payrollMissing !== []): ?>
                    <div class="cp-alert <?= $payrollRecord !== null && $payrollRecord['calculation_status'] === 'neconfigurat' ? 'is-muted' : 'is-warning' ?>">
                        <i class="bi bi-list-check" aria-hidden="true"></i>
                        <div>
                            <b><?= e($payrollRecord === null ? 'Înainte de calcul, lipsesc:' : $payrollStatusLabel) ?></b>
                            <ul class="cp-missing-list mb-0">
                                <?php foreach ($payrollMissing as $missingItem): ?>
                                    <li><?= e((string) $missingItem) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($payrollWarnings !== []): ?>
                    <div class="cp-alert is-warning">
                        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                        <div>
                            <b>De verificat de contabil</b>
                            <ul class="cp-missing-list mb-0">
                                <?php foreach ($payrollWarnings as $warningItem): ?>
                                    <li><?= e((string) $warningItem) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($payrollHasResult): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle cp-mini-table cp-explain-table">
                            <thead><tr><th>Componentă</th><th class="text-end">Bază</th><th class="text-end">Cotă</th><th class="text-end">Sumă</th></tr></thead>
                            <tbody>
                                <?php foreach ((array) ($payrollDetails['lines'] ?? []) as $line): ?>
                                    <tr>
                                        <td>
                                            <?= e((string) $line['label']) ?>
                                            <?php if (!empty($line['explain'])): ?>
                                                <div class="cp-explain"><?= e((string) $line['explain']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?= isset($line['base']) ? e(format_number_ro((float) $line['base'], 2)) : '' ?></td>
                                        <td class="text-end"><?= isset($line['rate']) ? e(format_number_ro((float) $line['rate'], 2)) . '%' : '' ?></td>
                                        <td class="text-end fw-semibold"><?= e(format_number_ro((float) $line['amount'], 2)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php foreach ((array) ($payrollDetails['items'] ?? []) as $itemLine): ?>
                                    <tr>
                                        <td><?= e((string) $itemLine['label']) ?><div class="cp-explain"><?= e((string) $itemLine['treatment']) ?></div></td>
                                        <td></td><td></td>
                                        <td class="text-end fw-semibold"><?= e(format_number_ro((float) $itemLine['amount'], 2)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr><th>Salariu brut</th><td></td><td></td><th class="text-end"><?= e($moneyFull($pNum('gross_salary'))) ?></th></tr>
                                <tr><th>Salariu net<?php if (($pNum('other_deductions') ?? 0) > 0): ?> / rest de plată<?php endif; ?></th><td></td><td></td><th class="text-end"><?= e($moneyFull($pNum('net_salary'))) ?><?php if (($pNum('other_deductions') ?? 0) > 0): ?> / <?= e($moneyFull($pNum('net_payable'))) ?><?php endif; ?></th></tr>
                                <tr class="is-total"><th>Cost total firmă</th><td></td><td></td><th class="text-end"><?= e($moneyFull($pNum('total_employer_cost'))) ?></th></tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="cp-explain mb-2">
                        <?php $conversion = $payrollDetails['conversion'] ?? null; ?>
                        <?php if (is_array($conversion)): ?>
                            NET→BRUT: ținta <?= e(format_number_ro((float) $payrollRecord['configured_salary'], 2)) ?> lei net → brut <?= e(format_number_ro((float) $conversion['gross'], 0)) ?> lei (net rezultat <?= e(format_number_ro((float) $conversion['net'], 2)) ?>, diferență <?= e(format_number_ro((float) $conversion['difference'], 2)) ?> lei; același motor ca BRUT→NET).
                        <?php else: ?>
                            Salariul configurat este BRUT (<?= e($moneyFull($pNum('configured_salary'))) ?>).
                        <?php endif; ?>
                        Regula fiscală: <?= e((string) ($payrollDetails['rule']['name'] ?? '—')) ?> (<?= e((string) ($payrollDetails['rule']['valid_from'] ?? '')) ?> – <?= e((string) ($payrollDetails['rule']['valid_to'] ?? 'prezent')) ?>).
                        <?php if (!empty($payrollDetails['rule']['legal_reference'])): ?>
                            <span title="<?= e((string) $payrollDetails['rule']['legal_reference']) ?>" class="text-decoration-underline">Bază legală</span>.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-2 mt-2">
                    <?php if ($canPayrollCalculate && !$payrollConfirmed): ?>
                        <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'payroll_calculate'])) ?>">
                            <?= $hiddenMonthFields ?>
                            <input type="hidden" name="return_tab" value="calcul">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-calculator" aria-hidden="true"></i> <?= $payrollRecord === null ? 'Calculează' : 'Recalculează' ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($canPayrollConfirm && $payrollHasResult && !$payrollConfirmed && !$payrollStale): ?>
                        <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'payroll_confirm'])) ?>">
                            <?= $hiddenMonthFields ?>
                            <input type="hidden" name="return_tab" value="calcul">
                            <button type="submit" class="btn btn-sm btn-success" data-confirm="Confirmi că ai verificat calculul salarial pentru <?= e($period['label']) ?>?<?= $payrollWarnings !== [] ? ' Calculul are atenționări de verificat.' : '' ?>">
                                <i class="bi bi-check2-square" aria-hidden="true"></i> Confirmă calculul
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if ($payrollConfirmed && $canPayrollReopen): ?>
                    <form method="post" class="cp-inline-form mt-2" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'payroll_reopen'])) ?>">
                        <?= $hiddenMonthFields ?>
                        <input type="hidden" name="return_tab" value="calcul">
                        <label class="form-label mb-0" for="cpReopen<?= e($rowId) ?>">Redeschide calculul confirmat — motiv (obligatoriu)</label>
                        <input type="text" class="form-control form-control-sm" id="cpReopen<?= e($rowId) ?>" name="reason" minlength="5" maxlength="1000" required placeholder="Ex.: corecție spor noapte omis">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Redeschizi calculul confirmat? Rezultatul actual rămâne în jurnal."><i class="bi bi-unlock" aria-hidden="true"></i> Redeschide calculul</button>
                    </form>
                <?php endif; ?>

                <?php if ($payrollAudit !== []): ?>
                    <div class="cp-subtitle mt-3">Jurnal</div>
                    <ul class="cp-audit-list">
                        <?php foreach (array_slice($payrollAudit, 0, 8) as $auditRow): ?>
                            <?php
                            $auditOld = $auditRow['old_result'] ? json_decode((string) $auditRow['old_result'], true) : null;
                            $auditNew = $auditRow['new_result'] ? json_decode((string) $auditRow['new_result'], true) : null;
                            ?>
                            <li>
                                <b><?= e(ucfirst((string) $auditRow['action'])) ?></b>
                                · <?= e(format_datetime_ro((string) $auditRow['created_at'])) ?> · <?= e((string) ($auditRow['user_name'] ?? '—')) ?>
                                <?php if (!empty($auditRow['reason'])): ?><div>Motiv: <?= e((string) $auditRow['reason']) ?></div><?php endif; ?>
                                <?php if (is_array($auditOld) || is_array($auditNew)): ?>
                                    <div class="cp-explain">
                                        <?php if (is_array($auditOld)): ?>Înainte: net <?= e((string) ($auditOld['net_salary'] ?? '—')) ?>, brut <?= e((string) ($auditOld['gross_salary'] ?? '—')) ?>, cost <?= e((string) ($auditOld['total_employer_cost'] ?? '—')) ?>. <?php endif; ?>
                                        <?php if (is_array($auditNew)): ?>După: net <?= e((string) ($auditNew['net_salary'] ?? '—')) ?>, brut <?= e((string) ($auditNew['gross_salary'] ?? '—')) ?>, cost <?= e((string) ($auditNew['total_employer_cost'] ?? '—')) ?>.<?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <div class="d-grid gap-3 align-content-start">
                <section class="cp-panel">
                    <h4 class="cp-panel-title">Sporuri, beneficii și rețineri — <?= e($period['label']) ?></h4>
                    <?php if ($payrollItems === []): ?>
                        <div class="small text-muted mb-2">Nimic adăugat pentru această lună. Un spor se aplică doar dacă este adăugat aici.</div>
                    <?php else: ?>
                        <ul class="cp-item-list">
                            <?php foreach ($payrollItems as $payrollItem): ?>
                                <li>
                                    <span class="cp-badge <?= $payrollItem['category'] === 'retinere' ? 'is-danger' : ($payrollItem['category'] === 'spor' ? 'is-success' : 'is-muted') ?>"><?= e(['spor' => 'Spor', 'retinere' => 'Reținere', 'beneficiu' => 'Beneficiu'][$payrollItem['category']] ?? '') ?></span>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="fw-semibold"><?= e((string) $payrollItem['name']) ?></div>
                                        <div class="cp-explain"><?= e($payrollItem['amount'] !== null ? format_number_ro((float) $payrollItem['amount'], 2) . ' lei' : format_number_ro((float) $payrollItem['default_value'], 2) . '% din brutul de bază') ?><?= !empty($payrollItem['reason']) ? ' · ' . e((string) $payrollItem['reason']) : '' ?></div>
                                    </div>
                                    <?php if ($canPayrollCalculate && !$payrollConfirmed): ?>
                                        <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'payroll_item_delete'])) ?>">
                                            <?= $hiddenMonthFields ?>
                                            <input type="hidden" name="return_tab" value="calcul">
                                            <input type="hidden" name="item_id" value="<?= e((string) (int) $payrollItem['id']) ?>">
                                            <button type="submit" class="cp-icon-btn" title="Șterge" aria-label="Șterge" data-confirm="Ștergi elementul?"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($canPayrollCalculate && !$payrollConfirmed): ?>
                        <?php $validTypes = array_values(array_filter($payrollItemTypes, static fn (array $t): bool => $t['valid_from'] <= $period['end'] && ($t['valid_to'] === null || $t['valid_to'] >= $period['start']))); ?>
                        <?php if ($validTypes === []): ?>
                            <div class="small text-muted">Nu există tipuri configurate. Adăugați-le din <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-bs-toggle="offcanvas" data-bs-target="#cpPayrollConfig">Configurare salarii</button>.</div>
                        <?php else: ?>
                            <form method="post" class="row g-2 align-items-end" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'payroll_item_add'])) ?>">
                                <?= $hiddenMonthFields ?>
                                <input type="hidden" name="return_tab" value="calcul">
                                <div class="col-12 col-sm-6">
                                    <label class="form-label" for="cpItemType<?= e($rowId) ?>">Tip</label>
                                    <select class="form-select form-select-sm" id="cpItemType<?= e($rowId) ?>" name="item_type_id" required>
                                        <?php foreach (['spor' => 'Sporuri', 'beneficiu' => 'Beneficii / ajutoare', 'retinere' => 'Rețineri'] as $groupKey => $groupLabel): ?>
                                            <optgroup label="<?= e($groupLabel) ?>">
                                                <?php foreach ($validTypes as $validType): ?>
                                                    <?php if ($validType['category'] === $groupKey): ?>
                                                        <option value="<?= e((string) (int) $validType['id']) ?>"><?= e((string) $validType['name']) ?><?= $validType['calculation_type'] === 'procent_din_baza' ? ' (' . e(format_number_ro((float) $validType['default_value'], 2)) . '%)' : '' ?></option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-3">
                                    <label class="form-label" for="cpItemAmount<?= e($rowId) ?>">Sumă (lei)</label>
                                    <input type="number" class="form-control form-control-sm" id="cpItemAmount<?= e($rowId) ?>" name="amount" min="0" step="0.01" placeholder="auto %">
                                </div>
                                <div class="col-6 col-sm-3">
                                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">Adaugă</button>
                                </div>
                                <div class="col-12">
                                    <input type="text" class="form-control form-control-sm" name="reason" maxlength="255" placeholder="Motiv (obligatoriu la rețineri: ex. poprire nr./dată)">
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <section class="cp-panel">
                    <h4 class="cp-panel-title d-flex justify-content-between align-items-center gap-2">
                        <span>Profil de salarizare</span>
                        <?= $profileComplete ? '<span class="cp-badge is-success">Configurat</span>' : '<span class="cp-badge is-warning">Neconfigurat</span>' ?>
                    </h4>
                    <?php $pf = $payrollProfile ?? []; $pv = static fn (string $f): string => isset($pf[$f]) && $pf[$f] !== null ? (string) $pf[$f] : ''; ?>
                    <form method="post" class="row g-2" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'payroll_profile_save'])) ?>">
                        <?= $hiddenMonthFields ?>
                        <input type="hidden" name="return_tab" value="calcul">
                        <?php
                        $profileSelect = static function (string $name, string $label, array $options, string $current, string $rowId, bool $disabled): string {
                            $html = '<div class="col-6"><label class="form-label" for="cpPf' . e($name . $rowId) . '">' . e($label) . '</label>'
                                . '<select class="form-select form-select-sm' . ($current === '' ? ' is-unset' : '') . '" id="cpPf' . e($name . $rowId) . '" name="' . e($name) . '"' . ($disabled ? ' disabled' : '') . '>'
                                . '<option value="">— Neconfigurat —</option>';
                            foreach ($options as $value => $optionLabel) {
                                $html .= '<option value="' . e((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . e($optionLabel) . '</option>';
                            }
                            return $html . '</select></div>';
                        };
                        $profileLocked = !$canPayrollCalculate;
                        ?>
                        <?= $profileSelect('salary_input_type', 'Salariul din fișă este', ['net' => 'NET (se calculează brutul)', 'gross' => 'BRUT'], $pv('salary_input_type'), $rowId, $profileLocked) ?>
                        <?= $profileSelect('contract_type', 'Tip contract', ['cim' => 'Contract individual de muncă', 'colaborare' => 'Colaborare (nu e salariat)', 'altul' => 'Altul'], $pv('contract_type'), $rowId, $profileLocked) ?>
                        <?= $profileSelect('norm_type', 'Normă', ['full' => 'Normă întreagă', 'part' => 'Normă parțială'], $pv('norm_type'), $rowId, $profileLocked) ?>
                        <div class="col-6">
                            <label class="form-label" for="cpPfHours<?= e($rowId) ?>">Ore / zi</label>
                            <input type="number" class="form-control form-control-sm" id="cpPfHours<?= e($rowId) ?>" name="hours_per_day" min="1" max="12" step="0.5" value="<?= e($pv('hours_per_day') !== '' ? rtrim(rtrim($pv('hours_per_day'), '0'), '.') : '') ?>" <?= $profileLocked ? 'disabled' : '' ?>>
                        </div>
                        <?= $profileSelect('is_basic_function', 'Funcția de bază este aici', ['1' => 'Da', '0' => 'Nu'], $pv('is_basic_function'), $rowId, $profileLocked) ?>
                        <?= $profileSelect('work_conditions', 'Condiții de muncă', ['normale' => 'Normale', 'deosebite' => 'Deosebite', 'speciale' => 'Speciale'], $pv('work_conditions'), $rowId, $profileLocked) ?>
                        <div class="col-6">
                            <label class="form-label" for="cpPfDep<?= e($rowId) ?>">Persoane în întreținere</label>
                            <input type="number" class="form-control form-control-sm<?= $pv('dependents_count') === '' ? ' is-unset' : '' ?>" id="cpPfDep<?= e($rowId) ?>" name="dependents_count" min="0" max="20" step="1" value="<?= e($pv('dependents_count')) ?>" placeholder="neconfigurat" <?= $profileLocked ? 'disabled' : '' ?>>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="cpPfKids<?= e($rowId) ?>">Copii &lt;18 ani la școală</label>
                            <input type="number" class="form-control form-control-sm<?= $pv('children_in_school') === '' ? ' is-unset' : '' ?>" id="cpPfKids<?= e($rowId) ?>" name="children_in_school" min="0" max="20" step="1" value="<?= e($pv('children_in_school')) ?>" placeholder="neconfigurat" <?= $profileLocked ? 'disabled' : '' ?>>
                        </div>
                        <?= $profileSelect('under_26', 'Sub 26 de ani', ['1' => 'Da', '0' => 'Nu'], $pv('under_26'), $rowId, $profileLocked) ?>
                        <?= $profileSelect('tax_exemption', 'Scutire de impozit', ['niciuna' => 'Niciuna', 'handicap' => 'Persoană cu handicap', 'it' => 'IT (programare)', 'constructii' => 'Construcții', 'agricol' => 'Agricultură / ind. alimentară', 'altele' => 'Altă scutire'], $pv('tax_exemption'), $rowId, $profileLocked) ?>
                        <?= $profileSelect('min_base_exemption', 'Excepție bază minimă (normă parțială)', ['niciuna' => 'Niciuna', 'elev_student' => 'Elev / student', 'pensionar' => 'Pensionar', 'handicap' => 'Persoană cu handicap', 'ucenic' => 'Ucenic', 'alt_contract' => 'Alt contract ≥ minim', 'altele' => 'Altă excepție legală'], $pv('min_base_exemption'), $rowId, $profileLocked) ?>
                        <?php if ($birthDate !== null): ?>
                            <div class="col-12 cp-explain">Data nașterii din fișa șoferului: <?= e(format_date_ro($birthDate)) ?> (<?= e((string) $ageNow) ?> ani) — completați „Sub 26 de ani” în consecință.</div>
                        <?php endif; ?>
                        <div class="col-12">
                            <textarea class="form-control form-control-sm" name="notes" rows="2" placeholder="Observații (ex.: documente depuse pentru persoane în întreținere)" <?= $profileLocked ? 'disabled' : '' ?>><?= e($pv('notes')) ?></textarea>
                        </div>
                        <?php if (!$profileLocked): ?>
                            <div class="col-12"><button type="submit" class="btn btn-sm btn-primary">Salvează profilul</button></div>
                        <?php endif; ?>
                        <div class="col-12 cp-explain">Câmpurile lăsate „Neconfigurat” nu se presupun: calculul cere completarea lor.</div>
                    </form>
                </section>
            </div>
        </div>
    </div>

    <?php endif; ?>
    <!-- ===================== ISTORIC SALARIAL ===================== -->
    <div class="cp-pane" data-cp-pane="istoric" hidden>
        <section class="cp-panel">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h4 class="cp-panel-title mb-0">Istoric salarial</h4>
                <button type="button" class="btn btn-sm btn-outline-primary cp-btn" data-bs-toggle="modal" data-bs-target="#salaryModal<?= e($rowId) ?>">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Modifică salariul
                </button>
            </div>
            <div class="cp-alert is-info">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Salariul aplicabil în <?= e($period['label']) ?>: <b><?= e($money($month['applicable_salary'])) ?></b> — <?= e($salarySourceLabel) ?>.
                O modificare nouă închide perioada anterioară; lunile trecute își păstrează salariul.
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 cp-mini-table">
                    <thead><tr><th>Salariu</th><th>Valabil de la</th><th>Valabil până la</th><th>Modificat de</th><th>Observații</th></tr></thead>
                    <tbody>
                        <?php if ($salaryPeriods === []): ?>
                            <tr><td colspan="5" class="text-muted">Nicio modificare înregistrată. Salariul din fișă (<?= e($money($row['salariu'] ?? null)) ?>) se consideră valabil de la angajare.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($salaryPeriods as $salaryPeriod): ?>
                            <?php $isApplicable = $salaryPeriod['valid_from'] <= $period['end'] && ($salaryPeriod['valid_to'] === null || $salaryPeriod['valid_to'] >= $period['end']); ?>
                            <tr class="<?= $isApplicable ? 'is-current' : '' ?>">
                                <td class="fw-semibold"><?= e($money($salaryPeriod['salary'])) ?></td>
                                <td><?= e(format_date_ro($salaryPeriod['valid_from'])) ?></td>
                                <td><?= e($salaryPeriod['valid_to'] !== null ? format_date_ro($salaryPeriod['valid_to']) : 'prezent') ?></td>
                                <td><?= e((string) ($salaryPeriod['updated_by_name'] ?? '—')) ?></td>
                                <td><?= e((string) (($salaryPeriod['notes'] ?? '') !== '' ? $salaryPeriod['notes'] : '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- ===================== FLUTURASI ===================== -->
    <div class="cp-pane" data-cp-pane="fluturasi" hidden>
        <section class="cp-panel cp-empty">
            <i class="bi bi-receipt-cutoff" aria-hidden="true"></i>
            <div class="fw-semibold">Fluturașii nu sunt încă disponibili</div>
            <div class="text-muted small">
                Generarea se activează după ce sunt definite regulile de calcul salarial (sporuri, contribuții, impozite, reguli pentru șoferi).
                Până atunci nu se emit fluturași estimativi.
            </div>
        </section>
    </div>

    <!-- ===================== DOCUMENTE ===================== -->
    <div class="cp-pane" data-cp-pane="documente" hidden>
        <section class="cp-panel">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h4 class="cp-panel-title mb-0">Documente</h4>
                <button type="button" class="btn btn-sm btn-outline-primary cp-btn" data-bs-toggle="modal" data-bs-target="#documentsModal<?= e($rowId) ?>">
                    <i class="bi bi-folder2-open" aria-hidden="true"></i> Gestionează documente
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 cp-mini-table">
                    <thead><tr><th>Tip document</th><th>Număr</th><th>Expiră la</th><th>Zile rămase</th><th>Status</th><th>Fișier</th></tr></thead>
                    <tbody>
                        <?php if ($documents === []): ?>
                            <tr><td colspan="6" class="text-muted">Nu există documente încărcate.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($documents as $document): ?>
                            <?php
                            $documentStatus = (string) ($document['expiration_status'] ?? 'valid');
                            // Zile ramase pana la expirare, fata de azi (negativ = expirat de X zile).
                            $daysLeft = !empty($document['data_expirare'])
                                ? (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable(substr((string) $document['data_expirare'], 0, 10)))->format('%r%a')
                                : null;
                            ?>
                            <tr>
                                <td><?= e((string) ($document['tip_document'] ?? '-')) ?></td>
                                <td><?= e((string) ($document['numar_document'] ?? '—')) ?></td>
                                <td><?= e(!empty($document['data_expirare']) ? format_date_ro((string) $document['data_expirare']) : '—') ?></td>
                                <td class="cp-days-left <?= $daysLeft === null ? '' : ($daysLeft < 0 ? 'is-expired' : ($documentStatus === 'expira_curand' ? 'is-soon' : '')) ?>">
                                    <?php if ($daysLeft === null): ?>—
                                    <?php elseif ($daysLeft < 0): ?>expirat de <?= e((string) abs($daysLeft)) ?> <?= abs($daysLeft) === 1 ? 'zi' : 'zile' ?>
                                    <?php elseif ($daysLeft === 0): ?>expiră azi
                                    <?php else: ?><?= e((string) $daysLeft) ?> <?= $daysLeft === 1 ? 'zi' : 'zile' ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($documentStatus === 'expirat'): ?><span class="cp-badge is-danger">Expirat</span>
                                    <?php elseif ($documentStatus === 'expira_curand'): ?><span class="cp-badge is-warning">Expiră curând</span>
                                    <?php else: ?><span class="cp-badge is-success">Valid</span><?php endif; ?>
                                </td>
                                <td><?= document_file_link_html($document['fisier_original'] ?? null, $document['fisier_stocat'] ?? null) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- ===================== NOTITE ===================== -->
    <div class="cp-pane" data-cp-pane="notite" hidden>
        <section class="cp-panel">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h4 class="cp-panel-title mb-0">Notițe</h4>
                <?php if (!$isDriver): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary cp-btn" data-bs-toggle="modal" data-bs-target="#editStaffModal<?= e($rowId) ?>">
                        <i class="bi bi-pencil" aria-hidden="true"></i> Editează
                    </button>
                <?php endif; ?>
            </div>
            <?php if (trim((string) ($row['observatii'] ?? '')) === ''): ?>
                <div class="text-muted small">Nu există notițe pentru acest angajat.</div>
            <?php else: ?>
                <div class="cp-notes"><?= nl2br(e((string) $row['observatii'])) ?></div>
            <?php endif; ?>
            <?php if ($isDriver): ?>
                <div class="small text-muted mt-2">Observațiile șoferilor se editează din modulul Șoferi.</div>
            <?php endif; ?>
            <?php if (!empty($record['observatii'])): ?>
                <div class="cp-subtitle mt-3">Observații <?= e($period['label']) ?></div>
                <div class="cp-notes"><?= nl2br(e((string) $record['observatii'])) ?></div>
            <?php endif; ?>
        </section>
    </div>
</div>
