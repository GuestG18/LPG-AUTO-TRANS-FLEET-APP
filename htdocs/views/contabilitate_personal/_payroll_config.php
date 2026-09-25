<?php
/**
 * Panoul "Configurare salarii si contributii" (offcanvas din dreapta).
 *
 * Variabile din index.php: $payrollConfig (rules, item_types, audit), $period,
 * $payrollRuleLookup, $canPayrollConfig.
 *
 * Regulile fiscale sunt versionate: o regula folosita de un stat confirmat nu se
 * mai editeaza — se creeaza o versiune noua de la o data (cea veche se inchide).
 */
$configTab = in_array($_GET['config'] ?? '', ['fiscal', 'retineri', 'sporuri', 'beneficii', 'setari'], true) ? (string) $_GET['config'] : 'fiscal';
$configRules = $payrollConfig['rules'];
$requestedRule = (string) ($_GET['rule'] ?? '');
$selectedRule = null;
$isNewRule = $requestedRule === 'new';
foreach ($configRules as $configRule) {
    if ((string) $configRule['id'] === $requestedRule) {
        $selectedRule = $configRule;
    }
}
if ($selectedRule === null && !$isNewRule) {
    $selectedRule = $payrollRuleLookup['rule'] !== null
        ? ($configRules[array_search((int) $payrollRuleLookup['rule']['id'], array_map('intval', array_column($configRules, 'id')), true)] ?? null)
        : ($configRules[0] ?? null);
}
$ruleLocked = $selectedRule !== null && (int) $selectedRule['confirmed_count'] > 0;
// Regula noua porneste de la ultima regula (valori de copiat), cu inceputul dupa sfarsitul ei.
$template = $isNewRule ? ($configRules[0] ?? null) : $selectedRule;
$rv = static function (string $field) use ($template, $isNewRule): string {
    if ($template === null) {
        return '';
    }
    if ($isNewRule && $field === 'valid_from') {
        return $template['valid_to'] !== null ? (new DateTimeImmutable((string) $template['valid_to']))->modify('+1 day')->format('Y-m-d') : '';
    }
    if ($isNewRule && in_array($field, ['valid_to', 'name', 'legal_reference', 'notes'], true)) {
        return '';
    }
    $value = $template[$field] ?? '';
    if (is_numeric($value) && str_contains((string) $value, '.')) {
        $value = rtrim(rtrim((string) $value, '0'), '.');
    }

    return (string) $value;
};
$pdCfg = is_array($template['personal_deduction_config'] ?? null) ? $template['personal_deduction_config'] : [];
$pdPercents = is_array($pdCfg['base_percent_by_dependents'] ?? null) ? $pdCfg['base_percent_by_dependents'] : ['', '', '', '', ''];
$fieldsDisabled = !$canPayrollConfig;
$configUrl = static fn (array $extra): string => build_query_url(array_merge(['page' => 'contabilitate_personal', 'luna' => $period['key']], $extra));
$typesByCategory = ['retinere' => [], 'spor' => [], 'beneficiu' => []];
foreach ($payrollConfig['item_types'] as $itemType) {
    $typesByCategory[$itemType['category']][] = $itemType;
}
$editTypeId = (int) ($_GET['type'] ?? 0);
?>
<div class="offcanvas offcanvas-end cp-config" tabindex="-1" id="cpPayrollConfig" aria-labelledby="cpPayrollConfigTitle" data-cp-open="<?= isset($_GET['config']) ? '1' : '0' ?>">
    <div class="offcanvas-header">
        <h3 class="offcanvas-title fs-5" id="cpPayrollConfigTitle">Configurare salarii și contribuții</h3>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Închide"></button>
    </div>
    <div class="cp-config-tabs" role="tablist">
        <?php foreach (['fiscal' => ['bi-file-earmark-text', 'Parametri fiscali'], 'retineri' => ['bi-dash-circle', 'Tipuri rețineri'], 'sporuri' => ['bi-plus-circle', 'Sporuri'], 'beneficii' => ['bi-gift', 'Beneficii / Ajutoare'], 'setari' => ['bi-sliders', 'Alte setări']] as $tabKey => [$tabIcon, $tabLabel]): ?>
            <button type="button" class="cp-config-tab <?= $configTab === $tabKey ? 'is-active' : '' ?>" data-cp-config-tab="<?= e($tabKey) ?>" role="tab"><i class="bi <?= e($tabIcon) ?>" aria-hidden="true"></i><?= e($tabLabel) ?></button>
        <?php endforeach; ?>
    </div>
    <div class="offcanvas-body">
        <?php if (!$canPayrollConfig): ?>
            <div class="cp-alert is-muted"><i class="bi bi-shield-lock" aria-hidden="true"></i> Vizualizare. Modificarea cere dreptul „Configurare salarii și contribuții”.</div>
        <?php endif; ?>

        <!-- ================= PARAMETRI FISCALI ================= -->
        <div class="cp-config-pane" data-cp-config-pane="fiscal" <?= $configTab === 'fiscal' ? '' : 'hidden' ?>>
            <div class="cp-rule-versions">
                <?php foreach ($configRules as $configRule): ?>
                    <a class="cp-rule-version <?= !$isNewRule && $selectedRule !== null && (int) $selectedRule['id'] === (int) $configRule['id'] ? 'is-active' : '' ?>" href="<?= e($configUrl(['config' => 'fiscal', 'rule' => (int) $configRule['id']])) ?>">
                        <span class="fw-semibold"><?= e((string) $configRule['name']) ?></span>
                        <span><?= e(format_date_ro((string) $configRule['valid_from'])) ?> – <?= e($configRule['valid_to'] !== null ? format_date_ro((string) $configRule['valid_to']) : 'prezent') ?></span>
                        <?php if ((int) $configRule['confirmed_count'] > 0): ?><i class="bi bi-lock-fill" title="Folosită de state confirmate" aria-label="Blocată"></i><?php endif; ?>
                        <?php if ($configRule['status'] !== 'activ'): ?><span class="cp-badge is-muted">inactivă</span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <?php if ($canPayrollConfig): ?>
                    <a class="cp-rule-version is-new <?= $isNewRule ? 'is-active' : '' ?>" href="<?= e($configUrl(['config' => 'fiscal', 'rule' => 'new'])) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Perioadă nouă</a>
                <?php endif; ?>
            </div>

            <?php if ($ruleLocked): ?>
                <div class="cp-alert is-info"><i class="bi bi-lock" aria-hidden="true"></i> Regula este folosită de <?= e((string) $selectedRule['confirmed_count']) ?> state confirmate și nu se mai modifică. Pentru o schimbare legislativă creați o versiune nouă de la o dată: regula actuală se închide automat în ziua anterioară.</div>
            <?php endif; ?>

            <?php if ($template !== null || $isNewRule): ?>
                <form method="post" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'payroll_rule_save'])) ?>" class="cp-config-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="luna" value="<?= e($period['key']) ?>">
                    <input type="hidden" name="rule_id" value="<?= e($isNewRule || $selectedRule === null ? '0' : (string) (int) $selectedRule['id']) ?>">
                    <fieldset <?= $fieldsDisabled ? 'disabled' : '' ?>>
                        <div class="cp-config-section">Perioada de valabilitate</div>
                        <div class="row g-2 align-items-end">
                            <div class="col-12"><label class="form-label" for="cfgName">Denumire</label><input type="text" class="form-control form-control-sm" id="cfgName" name="name" maxlength="120" required value="<?= e($rv('name')) ?>" placeholder="Ex.: 2027-S1"></div>
                            <div class="col-5"><label class="form-label" for="cfgFrom">De la</label><input type="date" class="form-control form-control-sm" id="cfgFrom" name="valid_from" required value="<?= e($rv('valid_from')) ?>"></div>
                            <div class="col-5"><label class="form-label" for="cfgTo">Până la</label><input type="date" class="form-control form-control-sm" id="cfgTo" name="valid_to" value="<?= e($rv('valid_to')) ?>"></div>
                            <div class="col-2">
                                <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" id="cfgActive" name="status" value="activ" <?= $isNewRule || $rv('status') === 'activ' ? 'checked' : '' ?>><label class="form-check-label small" for="cfgActive">Activă</label></div>
                            </div>
                        </div>

                        <div class="cp-config-section">Contribuții și impozite</div>
                        <div class="row g-2">
                            <?php foreach (['cas_employee_rate' => 'CAS (angajat)', 'cass_employee_rate' => 'CASS (angajat)', 'income_tax_rate' => 'Impozit pe venit', 'cam_employer_rate' => 'CAM (angajator)'] as $rateField => $rateLabel): ?>
                                <div class="col-6 col-sm-3">
                                    <label class="form-label" for="cfg<?= e($rateField) ?>"><?= e($rateLabel) ?></label>
                                    <div class="input-group input-group-sm"><input type="number" step="0.001" min="0" max="100" class="form-control" id="cfg<?= e($rateField) ?>" name="<?= e($rateField) ?>" required value="<?= e($rv($rateField)) ?>"><span class="input-group-text">%</span></div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="cp-config-section">Salariu minim</div>
                        <div class="row g-2">
                            <div class="col-4"><label class="form-label" for="cfgMin">Salariu minim brut</label><div class="input-group input-group-sm"><input type="number" step="0.01" min="0" class="form-control" id="cfgMin" name="minimum_gross_salary" required value="<?= e($rv('minimum_gross_salary')) ?>"><span class="input-group-text">RON</span></div></div>
                            <div class="col-4"><label class="form-label" for="cfgHours">Ore medii / lună</label><input type="number" step="0.001" min="0" class="form-control form-control-sm" id="cfgHours" name="monthly_hours_norm" required value="<?= e($rv('monthly_hours_norm')) ?>"></div>
                            <div class="col-4"><label class="form-label" for="cfgHourly">Minim / oră</label><div class="input-group input-group-sm"><input type="number" step="0.001" min="0" class="form-control" id="cfgHourly" name="minimum_hourly_salary" required value="<?= e($rv('minimum_hourly_salary')) ?>"><span class="input-group-text">RON</span></div></div>
                        </div>

                        <div class="cp-config-section">Facilitate fiscală (sumă netaxabilă la salariul minim)</div>
                        <div class="row g-2">
                            <div class="col-4"><label class="form-label" for="cfgNt">Sumă potențial netaxabilă</label><div class="input-group input-group-sm"><input type="number" step="0.01" min="0" class="form-control" id="cfgNt" name="non_taxable_minimum_salary_amount" value="<?= e($rv('non_taxable_minimum_salary_amount')) ?>"><span class="input-group-text">RON</span></div></div>
                            <div class="col-4"><label class="form-label" for="cfgNtLimit">Plafon venit brut</label><div class="input-group input-group-sm"><input type="number" step="0.01" min="0" class="form-control" id="cfgNtLimit" name="non_taxable_income_limit" value="<?= e($rv('non_taxable_income_limit')) ?>"><span class="input-group-text">RON</span></div></div>
                            <div class="col-4"><label class="form-label">Eligibilitate</label><div class="form-control form-control-sm bg-light">Obligatorie (verificată)</div></div>
                            <div class="col-12">
                                <div class="form-check"><input class="form-check-input" type="checkbox" id="cfgNtCam" name="non_taxable_excludes_cam" value="1" <?= $isNewRule || $rv('non_taxable_excludes_cam') === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="cfgNtCam">Suma netaxabilă se scade și din baza CAM</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" id="cfgMinBase" name="minimum_contribution_base_enabled" value="1" <?= $isNewRule || $rv('minimum_contribution_base_enabled') === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="cfgMinBase">Baza minimă de contribuții la normă parțială (diferența suportată de angajator)</label></div>
                            </div>
                        </div>

                        <div class="cp-config-section">Deducere personală (art. 77)</div>
                        <div class="row g-2">
                            <?php foreach (['0 pers.', '1 pers.', '2 pers.', '3 pers.', '4+ pers.'] as $depIndex => $depLabel): ?>
                                <div class="col"><label class="form-label" for="cfgPd<?= e((string) $depIndex) ?>"><?= e($depLabel) ?></label><div class="input-group input-group-sm"><input type="number" step="0.01" min="0" max="100" class="form-control" id="cfgPd<?= e((string) $depIndex) ?>" name="pd_percent_<?= e((string) $depIndex) ?>" required value="<?= e((string) ($pdPercents[$depIndex] ?? '')) ?>"><span class="input-group-text">%</span></div></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="row g-2 mt-0">
                            <div class="col-6 col-sm-3"><label class="form-label" for="cfgPdWin">Fereastră peste minim</label><input type="number" step="1" min="0" class="form-control form-control-sm" id="cfgPdWin" name="pd_window" required value="<?= e((string) ($pdCfg['income_window_above_minimum'] ?? '')) ?>"></div>
                            <div class="col-6 col-sm-3"><label class="form-label" for="cfgPdStep">Tranșă (lei)</label><input type="number" step="1" min="1" class="form-control form-control-sm" id="cfgPdStep" name="pd_step_lei" required value="<?= e((string) ($pdCfg['step_lei'] ?? '')) ?>"></div>
                            <div class="col-6 col-sm-3"><label class="form-label" for="cfgPdStepPct">Scădere / tranșă (pp)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" id="cfgPdStepPct" name="pd_step_percent" required value="<?= e((string) ($pdCfg['step_percent'] ?? '')) ?>"></div>
                            <div class="col-6 col-sm-3"><label class="form-label" for="cfgPdYouth">Sub 26 ani (%)</label><input type="number" step="0.01" min="0" class="form-control form-control-sm" id="cfgPdYouth" name="pd_youth_percent" required value="<?= e((string) ($pdCfg['youth_percent'] ?? '')) ?>"></div>
                            <div class="col-6 col-sm-3"><label class="form-label" for="cfgPdChild">Copil la școală (lei)</label><input type="number" step="1" min="0" class="form-control form-control-sm" id="cfgPdChild" name="pd_child_amount" required value="<?= e((string) ($pdCfg['child_in_school_amount'] ?? '')) ?>"></div>
                            <div class="col-6 col-sm-5">
                                <label class="form-label" for="cfgRound">Rotunjire</label>
                                <select class="form-select form-select-sm" id="cfgRound" name="rounding_mode">
                                    <?php foreach (['ro_salarii' => 'Salarii RO (baze ≤0,50 în jos, sume 0,50 în sus)', 'leu_half_down' => 'Leu (≤0,50 în jos)', 'leu_half_up' => 'Leu (0,50 în sus)', 'bani' => 'La bani (2 zecimale)'] as $roundKey => $roundLabel): ?>
                                        <option value="<?= e($roundKey) ?>" <?= ($rv('rounding_mode') ?: 'ro_salarii') === $roundKey ? 'selected' : '' ?>><?= e($roundLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="cp-config-section">Bază legală</div>
                        <textarea class="form-control form-control-sm" name="legal_reference" rows="3" placeholder="Ex.: HG nr. …/2027 (M.Of. …), Legea 227/2015 art. …"><?= e($rv('legal_reference')) ?></textarea>
                        <textarea class="form-control form-control-sm mt-2" name="notes" rows="2" placeholder="Observații"><?= e($rv('notes')) ?></textarea>

                        <div class="cp-config-section">Motivul modificării (obligatoriu, intră în jurnal)</div>
                        <input type="text" class="form-control form-control-sm" name="reason" maxlength="500" required placeholder="Ex.: HG nr. 146/2026 — salariul minim de la 01.07.2026">

                        <div class="cp-alert is-info mt-3 mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i> Aceste valori sunt folosite pentru calculul automat al salariilor pe perioada de valabilitate. Lunile deja confirmate nu se modifică.</div>
                        <?php if ($canPayrollConfig): ?>
                            <div class="d-flex flex-wrap gap-2 align-items-end">
                                <?php if (!$ruleLocked): ?>
                                    <button type="submit" name="mode" value="save" class="btn btn-sm btn-primary"><?= $isNewRule ? 'Creează regula' : 'Salvează configurarea' ?></button>
                                <?php endif; ?>
                                <?php if (!$isNewRule && $selectedRule !== null): ?>
                                    <div class="cp-version-box">
                                        <label class="form-label mb-0" for="cfgNewFrom">Versiune nouă de la</label>
                                        <div class="d-flex gap-2">
                                            <input type="date" class="form-control form-control-sm" id="cfgNewFrom" name="new_valid_from">
                                            <button type="submit" name="mode" value="version" class="btn btn-sm btn-outline-primary text-nowrap" data-confirm="Închizi regula actuală în ziua dinaintea datei alese și creezi o regulă nouă cu valorile din formular?">Creează versiune</button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </fieldset>
                </form>
            <?php else: ?>
                <div class="text-muted small">Nu există reguli fiscale. Creați prima perioadă.</div>
            <?php endif; ?>
        </div>

        <!-- ================= TIPURI: RETINERI / SPORURI / BENEFICII ================= -->
        <?php foreach (['retineri' => 'retinere', 'sporuri' => 'spor', 'beneficii' => 'beneficiu'] as $paneKey => $category): ?>
            <?php
            $editType = null;
            foreach ($typesByCategory[$category] as $candidate) {
                if ((int) $candidate['id'] === $editTypeId) {
                    $editType = $candidate;
                }
            }
            $tv = static fn (string $field, string $default = '') => $editType !== null && $editType[$field] !== null ? (string) $editType[$field] : $default;
            $isDeduction = $category === 'retinere';
            ?>
            <div class="cp-config-pane" data-cp-config-pane="<?= e($paneKey) ?>" <?= $configTab === $paneKey ? '' : 'hidden' ?>>
                <p class="small text-muted">
                    <?php if ($isDeduction): ?>
                        Rețineri din salariul net (poprire, avans, rate interne legale, corecții). CAS, CASS și impozitul NU sunt rețineri manuale — le calculează motorul.
                    <?php elseif ($category === 'spor'): ?>
                        Un spor se aplică doar dacă este adăugat pe angajat, pe lună. Procentul/suma vin din contract sau regulament — nu se presupun.
                    <?php else: ?>
                        Beneficii și ajutoare cu tratament fiscal explicit (ex.: tichete de masă: impozit + CASS, fără CAS/CAM, în natură, excluse din plafonul facilității).
                    <?php endif; ?>
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle cp-mini-table">
                        <thead><tr><th>Denumire</th><th>Calcul</th><?php if (!$isDeduction): ?><th>Tratament fiscal</th><?php endif; ?><th>Valabil</th><th></th></tr></thead>
                        <tbody>
                            <?php if ($typesByCategory[$category] === []): ?>
                                <tr><td colspan="5" class="text-muted">Nu există tipuri configurate.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($typesByCategory[$category] as $itemType): ?>
                                <tr class="<?= (int) $itemType['active'] === 1 ? '' : 'text-muted' ?>">
                                    <td class="fw-semibold"><?= e((string) $itemType['name']) ?></td>
                                    <td><?= e(['suma_fixa' => 'sumă fixă', 'procent_din_baza' => format_number_ro((float) $itemType['default_value'], 2) . '% din bază', 'manual' => 'manual'][$itemType['calculation_type']] ?? '') ?></td>
                                    <?php if (!$isDeduction): ?>
                                        <td class="small">
                                            <?= (int) $itemType['paid_in_cash'] === 1 ? 'bani' : 'natură' ?>;
                                            <?php $taxFlags = array_filter(['CAS' => $itemType['subject_to_cas'], 'CASS' => $itemType['subject_to_cass'], 'impozit' => $itemType['subject_to_income_tax'], 'CAM' => $itemType['subject_to_cam']], static fn ($v): bool => (int) $v === 1); ?>
                                            <?= e($taxFlags === [] ? 'neimpozabil' : implode(', ', array_keys($taxFlags))) ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="small"><?= e(format_date_ro((string) $itemType['valid_from'])) ?> – <?= e($itemType['valid_to'] !== null ? format_date_ro((string) $itemType['valid_to']) : 'prezent') ?><?= (int) $itemType['active'] === 1 ? '' : ' · inactiv' ?></td>
                                    <td class="text-end"><?php if ($canPayrollConfig): ?><a class="cp-icon-btn" href="<?= e($configUrl(['config' => $paneKey, 'type' => (int) $itemType['id']])) ?>" title="Editează" aria-label="Editează"><i class="bi bi-pencil" aria-hidden="true"></i></a><?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($canPayrollConfig): ?>
                    <form method="post" class="cp-config-form row g-2" action="<?= e(build_query_url(['page' => 'contabilitate_personal', 'action' => 'payroll_item_type_save'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="luna" value="<?= e($period['key']) ?>">
                        <input type="hidden" name="category" value="<?= e($category) ?>">
                        <input type="hidden" name="id" value="<?= e($editType !== null ? (string) (int) $editType['id'] : '0') ?>">
                        <div class="cp-config-section col-12"><?= $editType !== null ? 'Editare: ' . e((string) $editType['name']) : 'Tip nou' ?></div>
                        <div class="col-12 col-sm-6"><label class="form-label">Denumire</label><input type="text" class="form-control form-control-sm" name="name" maxlength="120" required value="<?= e($tv('name')) ?>" placeholder="<?= $isDeduction ? 'Ex.: Poprire' : ($category === 'spor' ? 'Ex.: Spor de noapte' : 'Ex.: Tichete de masă') ?>"></div>
                        <div class="col-6 col-sm-3">
                            <label class="form-label">Calcul</label>
                            <select class="form-select form-select-sm" name="calculation_type">
                                <?php foreach (['manual' => 'Manual (sumă pe lună)', 'suma_fixa' => 'Sumă fixă', 'procent_din_baza' => '% din brutul de bază'] as $calcKey => $calcLabel): ?>
                                    <?php if ($isDeduction && $calcKey === 'procent_din_baza') { continue; } ?>
                                    <option value="<?= e($calcKey) ?>" <?= $tv('calculation_type', 'manual') === $calcKey ? 'selected' : '' ?>><?= e($calcLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-sm-3"><label class="form-label">Valoare (lei / %)</label><input type="number" step="0.001" min="0" class="form-control form-control-sm" name="default_value" value="<?= e($tv('default_value')) ?>"></div>
                        <?php if (!$isDeduction): ?>
                            <div class="col-12">
                                <label class="form-label mb-1">Tratament fiscal (bifați explicit)</label>
                                <div class="d-flex flex-wrap gap-3">
                                    <?php foreach (['paid_in_cash' => 'Plătit în bani', 'subject_to_cas' => 'CAS', 'subject_to_cass' => 'CASS', 'subject_to_income_tax' => 'Impozit', 'subject_to_cam' => 'CAM', 'excluded_from_facility_ceiling' => 'Exclus din plafonul facilității (tichete/vouchere/hrană)'] as $flagKey => $flagLabel): ?>
                                        <div class="form-check"><input class="form-check-input" type="checkbox" id="cfg<?= e($paneKey . $flagKey) ?>" name="<?= e($flagKey) ?>" value="1" <?= $tv($flagKey, $flagKey === 'paid_in_cash' ? '1' : '0') === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="cfg<?= e($paneKey . $flagKey) ?>"><?= e($flagLabel) ?></label></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="col-6 col-sm-4"><label class="form-label">De la</label><input type="date" class="form-control form-control-sm" name="valid_from" required value="<?= e($tv('valid_from', date('Y-01-01'))) ?>"></div>
                        <div class="col-6 col-sm-4"><label class="form-label">Până la</label><input type="date" class="form-control form-control-sm" name="valid_to" value="<?= e($tv('valid_to')) ?>"></div>
                        <div class="col-12 col-sm-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="cfg<?= e($paneKey) ?>Active" name="active" value="1" <?= $tv('active', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="cfg<?= e($paneKey) ?>Active">Activ</label></div></div>
                        <div class="col-12"><input type="text" class="form-control form-control-sm" name="legal_reference" maxlength="255" placeholder="Bază legală / contract / regulament intern" value="<?= e($tv('legal_reference')) ?>"></div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-primary"><?= $editType !== null ? 'Salvează' : 'Adaugă tipul' ?></button>
                            <?php if ($editType !== null): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e($configUrl(['config' => $paneKey])) ?>">Renunță</a><?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <!-- ================= ALTE SETARI ================= -->
        <div class="cp-config-pane" data-cp-config-pane="setari" <?= $configTab === 'setari' ? '' : 'hidden' ?>>
            <div class="cp-config-section">Principii de calcul</div>
            <ul class="small ps-3">
                <li>Nicio cotă nu este scrisă în cod: toate valorile vin din regula fiscală valabilă pentru toată luna.</li>
                <li>Fără regulă validă pentru o lună, calculul se oprește (nu se preia regula lunii anterioare).</li>
                <li>Situații nesuportate (CM, lună parțială, absențe, condiții deosebite, scutiri, colaboratori) → „Necesită verificare contabilă”, fără rezultat fals.</li>
                <li>„Calculat” ≠ „Confirmat contabil”. Confirmarea îngheață rezultatul; redeschiderea cere motiv și rămâne în jurnal.</li>
                <li>Diurna rămâne cost separat (Dispecer curse) și nu intră în salariu.</li>
            </ul>
            <div class="cp-config-section">Jurnal modificări configurare fiscală</div>
            <?php if ($payrollConfig['audit'] === []): ?>
                <div class="small text-muted">Nicio modificare înregistrată.</div>
            <?php else: ?>
                <ul class="cp-audit-list">
                    <?php foreach ($payrollConfig['audit'] as $auditRow): ?>
                        <li>
                            <b><?= e((string) $auditRow['rule_name']) ?></b> · <?= e((string) $auditRow['action']) ?><?= $auditRow['field'] ? ' · ' . e((string) $auditRow['field']) : '' ?>
                            · <?= e(format_datetime_ro((string) $auditRow['created_at'])) ?> · <?= e((string) ($auditRow['user_name'] ?? '—')) ?>
                            <?php if ($auditRow['field']): ?><div class="cp-explain"><?= e((string) $auditRow['old_value']) ?> → <?= e((string) $auditRow['new_value']) ?></div><?php endif; ?>
                            <?php if ($auditRow['reason']): ?><div class="cp-explain">Motiv: <?= e((string) $auditRow['reason']) ?></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
