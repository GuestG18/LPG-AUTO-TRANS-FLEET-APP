<?php
declare(strict_types=1);

/**
 * Teste pentru calculul salarial din Contabilitate Personal.
 *
 *   php scripts/test_payroll.php
 *
 * Partea 1 (fara baza de date): PayrollCalculatorService — BRUT->NET, NET->BRUT,
 * facilitatea salariului minim S1/S2 2026, deducerea personala, baza minima la
 * norma partiala, situatiile pe care motorul refuza sa le calculeze.
 * Valorile de referinta: netul oficial la salariul minim (Ministerul Muncii /
 * HG 146/2026 + OUG 89/2025): 2.574 lei (4.050 brut, S1 2026), 2.699 lei (4.325 brut, S2 2026).
 *
 * Partea 2 (baza de date, intr-o tranzactie anulata la final): regulile din DB,
 * istoric salarial pe luni, instantaneul confirmat, schimbarea lunii.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/services/PayrollCalculatorService.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "\033[32m  PASS\033[0m  $name\n";
    } else {
        $failed++;
        echo "\033[31m  FAIL\033[0m  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

function rule_2026(string $half): array
{
    $pd = ['base_percent_by_dependents' => [20, 25, 30, 35, 45], 'income_window_above_minimum' => 2000, 'step_lei' => 50, 'step_percent' => 0.5, 'youth_percent' => 15, 'youth_max_age' => 26, 'child_in_school_amount' => 100];
    $common = [
        'cas_employee_rate' => '25.000', 'cass_employee_rate' => '10.000', 'income_tax_rate' => '10.000', 'cam_employer_rate' => '2.250',
        'non_taxable_requires_eligibility' => 1, 'non_taxable_excludes_cam' => 1, 'minimum_contribution_base_enabled' => 1,
        'personal_deduction_config' => $pd, 'rounding_mode' => 'ro_salarii', 'legal_reference' => 'test',
    ];

    return $half === 'S1'
        ? $common + ['id' => 1, 'name' => '2026-S1', 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30', 'minimum_gross_salary' => '4050.00', 'non_taxable_minimum_salary_amount' => '300.00', 'non_taxable_income_limit' => '4300.00']
        : $common + ['id' => 2, 'name' => '2026-S2', 'valid_from' => '2026-07-01', 'valid_to' => '2026-12-31', 'minimum_gross_salary' => '4325.00', 'non_taxable_minimum_salary_amount' => '200.00', 'non_taxable_income_limit' => '4600.00'];
}

function profile(array $override = []): array
{
    return $override + [
        'contract_type' => 'cim', 'norm_type' => 'full', 'hours_per_day' => 8, 'is_basic_function' => 1,
        'salary_input_type' => 'gross', 'dependents_count' => 0, 'children_in_school' => 0, 'under_26' => 0,
        'work_conditions' => 'normale', 'tax_exemption' => 'niciuna', 'min_base_exemption' => null,
    ];
}

function input(array $rule, array $profile, float $salary, array $extra = []): array
{
    $month = $rule['name'] === '2026-S1' ? '2026-06' : '2026-09';
    return array_replace_recursive([
        'period' => ['start' => $month . '-01', 'end' => date('Y-m-t', strtotime($month . '-01')), 'label' => $month],
        'rule' => $rule,
        'profile' => $profile,
        'salary' => ['amount' => $salary],
        'employment' => ['hire_date' => '2024-01-01', 'termination_date' => null],
        'attendance' => ['recorded' => true, 'worked_days' => 21, 'absent_days' => 0, 'co_days' => 0, 'cm_days' => 0, 'co_source' => 'test'],
        'items' => [],
    ], $extra);
}

$engine = new PayrollCalculatorService();

echo "\n1) Rotunjire\n";
check('baza: 937,50 -> 937 (0,50 se neglijeaza)', PayrollCalculatorService::roundBase(937.5, 'ro_salarii') === 937.0);
check('baza: 937,51 -> 938', PayrollCalculatorService::roundBase(937.51, 'ro_salarii') === 938.0);
check('suma: 937,50 -> 938', PayrollCalculatorService::roundAmount(937.5, 'ro_salarii') === 938.0);
check('suma: 412,49 -> 412', PayrollCalculatorService::roundAmount(412.49, 'ro_salarii') === 412.0);

echo "\n2) Salariul minim S2 2026 (4.325, facilitate 200 lei) — net oficial 2.699\n";
$r = $engine->calculate(input(rule_2026('S2'), profile(), 4325));
check('status calculat', $r['status'] === 'calculat', $r['status'] . ' ' . json_encode($r['missing'] ?? []));
check('facilitate eligibila 200', $r['non_taxable_facility_eligible'] === true && $r['non_taxable_amount'] === 200.0, (string) $r['non_taxable_amount']);
check('CAS 1.031 (25% din 4.125)', $r['cas'] === 1031.0, (string) $r['cas']);
check('CASS 413 (10% din 4.125 = 412,50)', $r['cass'] === 413.0, (string) $r['cass']);
check('deducere personala 865 (20% din 4.325)', $r['personal_deduction'] === 865.0, (string) $r['personal_deduction']);
check('impozit 182 (10% din 1.816)', $r['income_tax'] === 182.0 && $r['income_tax_base'] === 1816.0, $r['income_tax_base'] . ' / ' . $r['income_tax']);
check('NET 2.699 (oficial)', $r['net_salary'] === 2699.0, (string) $r['net_salary']);
check('CAM 93 (2,25% din 4.125)', $r['cam'] === 93.0, (string) $r['cam']);
check('cost total firma 4.418', $r['total_employer_cost'] === 4418.0, (string) $r['total_employer_cost']);

echo "\n3) Salariul minim S1 2026 (4.050, facilitate 300 lei) — net oficial 2.574\n";
$r = $engine->calculate(input(rule_2026('S1'), profile(), 4050));
check('facilitate 300', $r['non_taxable_amount'] === 300.0, (string) $r['non_taxable_amount']);
check('CAS 938 (937,50 in sus)', $r['cas'] === 938.0, (string) $r['cas']);
check('NET 2.574 (oficial)', $r['net_salary'] === 2574.0, (string) $r['net_salary']);
check('cost firma 4.134 (CAM 84 pe 3.750)', $r['total_employer_cost'] === 4134.0 && $r['cam'] === 84.0, $r['total_employer_cost'] . ' / ' . $r['cam']);

echo "\n4) Iunie vs iulie: minim, suma netaxabila si plafon diferite\n";
$june = $engine->calculate(input(rule_2026('S1'), profile(), 4325));
$july = $engine->calculate(input(rule_2026('S2'), profile(), 4325));
check('iunie: 4.325 != minimul 4.050 -> fara facilitate', $june['non_taxable_amount'] === 0.0 && str_contains($june['eligibility_reason'], 'nu este egal'), $june['eligibility_reason']);
check('iulie: 4.325 = minimul -> facilitate 200', $july['non_taxable_amount'] === 200.0);
check('regula fiscala diferita (1 vs 2)', $june['fiscal_rule_id'] === 1 && $july['fiscal_rule_id'] === 2);

echo "\n5) Facilitate: angajati NEeligibili\n";
$notBasic = $engine->calculate(input(rule_2026('S2'), profile(['is_basic_function' => 0]), 4325));
check('nu e functie de baza -> fara facilitate', $notBasic['non_taxable_amount'] === 0.0);
check('nu e functie de baza -> fara deducere personala', $notBasic['personal_deduction'] === 0.0);
check('nu e functie de baza -> CAS 1.081, CASS 433, impozit 281, net 2.530', $notBasic['cas'] === 1081.0 && $notBasic['cass'] === 433.0 && $notBasic['income_tax'] === 281.0 && $notBasic['net_salary'] === 2530.0, json_encode([$notBasic['cas'], $notBasic['cass'], $notBasic['income_tax'], $notBasic['net_salary']]));
$overCeiling = $engine->calculate(input(rule_2026('S2'), profile(), 4325, ['items' => [[
    'category' => 'spor', 'name' => 'Spor weekend', 'amount' => 400, 'paid_in_cash' => 1,
    'subject_to_cas' => 1, 'subject_to_cass' => 1, 'subject_to_income_tax' => 1, 'subject_to_cam' => 1, 'excluded_from_facility_ceiling' => 0,
]]]));
check('minim + spor 400 = 4.725 > plafon 4.600 -> fara facilitate', $overCeiling['non_taxable_amount'] === 0.0, $overCeiling['eligibility_reason']);
$tickets = $engine->calculate(input(rule_2026('S2'), profile(), 4325, ['items' => [[
    'category' => 'beneficiu', 'name' => 'Tichete de masa', 'amount' => 400, 'paid_in_cash' => 0,
    'subject_to_cas' => 0, 'subject_to_cass' => 1, 'subject_to_income_tax' => 1, 'subject_to_cam' => 0, 'excluded_from_facility_ceiling' => 1,
]]]));
check('tichetele de masa nu intra in plafon -> facilitatea ramane', $tickets['non_taxable_amount'] === 200.0, $tickets['eligibility_reason']);
check('tichetele: CASS pe 4.525, fara CAS/CAM pe ele', $tickets['cass_base'] === 4525.0 && $tickets['cas_base'] === 4125.0 && $tickets['cam_base'] === 4125.0, json_encode([$tickets['cas_base'], $tickets['cass_base'], $tickets['cam_base']]));
check('tichetele nu se platesc in bani, dar intra in costul firmei', $tickets['gross_salary'] === 4325.0 && $tickets['total_employer_cost'] === 4325.0 + 400 + 93, (string) $tickets['total_employer_cost']);
$partTimeMin = $engine->calculate(input(rule_2026('S2'), profile(['norm_type' => 'part', 'hours_per_day' => 4, 'min_base_exemption' => 'elev_student']), 4325));
check('norma partiala -> fara facilitate', $partTimeMin['non_taxable_amount'] === 0.0);

echo "\n6) Salariu standard BRUT -> NET (8.000 brut, fara persoane in intretinere)\n";
$r = $engine->calculate(input(rule_2026('S2'), profile(), 8000));
check('CAS 2.000 / CASS 800', $r['cas'] === 2000.0 && $r['cass'] === 800.0);
check('peste minim + 2.000 -> deducere 0', $r['personal_deduction'] === 0.0);
check('impozit 520 / net 4.680', $r['income_tax'] === 520.0 && $r['net_salary'] === 4680.0, $r['income_tax'] . ' / ' . $r['net_salary']);
check('CAM 180 / cost firma 8.180', $r['cam'] === 180.0 && $r['total_employer_cost'] === 8180.0);

echo "\n7) Deducerea personala pe transe (art. 77)\n";
$r = $engine->calculate(input(rule_2026('S2'), profile(), 4400));
check('4.400 = minim + 75 -> transa 2 -> 19% -> 822', $r['personal_deduction'] === 822.0, (string) $r['personal_deduction']);
$r = $engine->calculate(input(rule_2026('S2'), profile(['dependents_count' => 2, 'children_in_school' => 1]), 5000));
check('5.000 = minim + 675 (transa 14), 2 pers. -> 23% -> 995 + 100 copil = 1.095', $r['personal_deduction'] === 1095.0, (string) $r['personal_deduction']);
$r = $engine->calculate(input(rule_2026('S2'), profile(['under_26' => 1]), 6325));
check('6.325 = minim + 2.000 (ultima transa): 0% + sub 26 ani 15% = 649', $r['personal_deduction'] === 649.0, (string) $r['personal_deduction']);
$r = $engine->calculate(input(rule_2026('S2'), profile(['under_26' => 1]), 6326));
check('6.326 > minim + 2.000 -> fara deducere de baza si fara cea pt. tineri', $r['personal_deduction'] === 0.0, (string) $r['personal_deduction']);

echo "\n8) NET -> BRUT si reversibilitate\n";
foreach ([2699.0, 3000.0, 4680.0, 5000.0, 7321.0] as $target) {
    $r = $engine->calculate(input(rule_2026('S2'), profile(['salary_input_type' => 'net']), $target));
    $back = $engine->calculate(input(rule_2026('S2'), profile(['salary_input_type' => 'gross']), (float) $r['base_gross_salary']));
    $below = $engine->calculate(input(rule_2026('S2'), profile(['salary_input_type' => 'gross']), (float) $r['base_gross_salary'] - 1));
    check(
        'NET ' . $target . ' -> BRUT ' . $r['base_gross_salary'] . ' -> NET ' . $back['net_salary'] . ' (toleranta 1 leu, brutul - 1 da mai putin)',
        $back['net_salary'] >= $target && $back['net_salary'] - $target <= PayrollCalculatorService::NET_TOLERANCE
            && ($below['status'] !== 'calculat' || $below['net_salary'] < $target),
        json_encode([$r['base_gross_salary'], $back['net_salary'], $below['net_salary'] ?? null])
    );
}
$r = $engine->calculate(input(rule_2026('S2'), profile(['salary_input_type' => 'net']), 4680));
check('NET 4.680 -> BRUT 8.000', $r['base_gross_salary'] === 8000.0, (string) $r['base_gross_salary']);
$r = $engine->calculate(input(rule_2026('S2'), profile(['salary_input_type' => 'net']), 2699));
check('NET 2.699 -> BRUT 4.325 cu facilitate (nu un multiplicator fix)', $r['base_gross_salary'] === 4325.0 && $r['non_taxable_amount'] === 200.0, (string) $r['base_gross_salary']);

echo "\n9) Norma partiala: baza minima de contributii\n";
$r = $engine->calculate(input(rule_2026('S2'), profile(['norm_type' => 'part', 'hours_per_day' => 4, 'min_base_exemption' => 'niciuna']), 2163));
check('brutul ramane 2.163 (nu se ridica la minim)', $r['gross_salary'] === 2163.0);
check('CAS angajat pe 2.163 = 541', $r['cas'] === 541.0, (string) $r['cas']);
check('diferenta angajator: 25%+10% din (4.325-2.163) = 541 + 216', $r['employer_contribution_differences'] === 757.0, (string) $r['employer_contribution_differences']);
$r = $engine->calculate(input(rule_2026('S2'), profile(['norm_type' => 'part', 'hours_per_day' => 4, 'min_base_exemption' => 'elev_student']), 2163));
check('exceptie (elev/student) -> fara diferenta', $r['employer_contribution_differences'] === 0.0);

echo "\n10) Nu se calculeaza fals\n";
$incomplete = profile();
$incomplete['dependents_count'] = null;
$incomplete['salary_input_type'] = null;
$r = $engine->calculate(input(rule_2026('S2'), $incomplete, 5000));
check('profil incomplet -> neconfigurat, cu campurile lipsa', $r['status'] === 'neconfigurat' && count($r['missing']) === 2 && !isset($r['net_salary']), json_encode($r['missing']));
$r = $engine->calculate(array_merge(input(rule_2026('S2'), profile(), 5000), ['rule' => null]));
check('fara regula fiscala -> neconfigurat', $r['status'] === 'neconfigurat' && str_contains($r['missing'][0], 'Nu există configurație fiscală'));
$r = $engine->calculate(input(rule_2026('S2'), profile(), 5000, ['attendance' => ['cm_days' => 2]]));
check('CM in luna -> Necesita calcul CM', $r['status'] === 'necesita_verificare' && str_contains(implode(' ', $r['missing']), 'Necesită calcul CM'));
$r = $engine->calculate(input(rule_2026('S2'), profile(), 5000, ['employment' => ['hire_date' => '2026-09-10']]));
check('angajat in cursul lunii -> necesita verificare', $r['status'] === 'necesita_verificare');
$r = $engine->calculate(input(rule_2026('S2'), profile(['work_conditions' => 'deosebite']), 5000));
check('conditii deosebite -> necesita verificare', $r['status'] === 'necesita_verificare');
$r = $engine->calculate(input(rule_2026('S2'), profile(['contract_type' => 'colaborare']), 5000));
check('colaborator -> nu se calculeaza prin stat', $r['status'] === 'necesita_verificare');
$r = $engine->calculate(input(rule_2026('S2'), profile(), 5000, ['attendance' => ['recorded' => false]]));
check('fara pontaj -> neconfigurat', $r['status'] === 'neconfigurat');
$r = $engine->calculate(input(rule_2026('S2'), profile(), 5000, ['attendance' => ['co_days' => 5]]));
check('CO in luna -> calculat dar "de verificat"', $r['status'] === 'de_verificat' && $r['warnings'] !== []);
$r = $engine->calculate(input(rule_2026('S2'), profile(), 3000));
check('brut sub minim la norma intreaga -> necesita verificare', $r['status'] === 'necesita_verificare');

$dbTests = $root . '/scripts/test_payroll_db.php';
if (is_file($dbTests)) {
    require $dbTests;
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "Rezultat: $passed trecute, $failed esuate\n";
exit($failed === 0 ? 0 : 1);
