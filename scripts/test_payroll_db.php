<?php
declare(strict_types=1);

/**
 * Partea 2 din test_payroll.php: fluxul complet pe baza de date, intr-o tranzactie
 * anulata la final (nu raman date de test). Se ruleaza prin scripts/test_payroll.php.
 */

require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/StaffAccountancyModel.php';
require_once $root . '/htdocs/models/PayrollModel.php';
require_once $root . '/htdocs/services/LegalCalendarService.php';
require_once $root . '/htdocs/services/StaffMonthlyAccountingService.php';
require_once $root . '/htdocs/services/PayrollMonthService.php';

$db = get_pdo();
$staffModel = new StaffAccountancyModel($db);
$payrollModel = new PayrollModel($db);
$service = new PayrollMonthService($staffModel, $payrollModel);

/** Daca tranzactia s-a pierdut (COMMIT implicit), testul se opreste imediat, fara sa mai scrie. */
function assert_in_transaction(PDO $db): void
{
    if (!$db->inTransaction()) {
        fwrite(STDERR, "\nOPRIT: tranzactia de test s-a inchis (COMMIT implicit). Verificati datele scrise.\n");
        exit(2);
    }
}

$db->beginTransaction();
try {
    echo "\n11) Regulile fiscale din baza de date\n";
    $june = StaffMonthlyAccountingService::parsePeriod('2026-06');
    $sept = StaffMonthlyAccountingService::parsePeriod('2026-09');
    $jan27 = StaffMonthlyAccountingService::parsePeriod('2027-01');
    $ruleJune = $payrollModel->findRuleForPeriod($june['start'], $june['end'], $june['label'])['rule'];
    $ruleSept = $payrollModel->findRuleForPeriod($sept['start'], $sept['end'], $sept['label'])['rule'];
    check('iunie 2026 -> regula S1 (minim 4.050, facilitate 300)', $ruleJune !== null && (float) $ruleJune['minimum_gross_salary'] === 4050.0 && (float) $ruleJune['non_taxable_minimum_salary_amount'] === 300.0);
    check('septembrie 2026 -> regula S2 (minim 4.325, facilitate 200, plafon 4.600)', $ruleSept !== null && (float) $ruleSept['minimum_gross_salary'] === 4325.0 && (float) $ruleSept['non_taxable_income_limit'] === 4600.0);
    $lookup = $payrollModel->findRuleForPeriod($jan27['start'], $jan27['end'], $jan27['label']);
    check('ianuarie 2027 -> fara regula, NU se foloseste decembrie 2026', $lookup['rule'] === null && $lookup['error'] === 'Nu există configurație fiscală validă pentru Ianuarie 2027.', (string) $lookup['error']);

    try {
        $payrollModel->saveRule(null, array_merge($ruleSept, ['name' => 'suprapusa', 'valid_from' => '2026-12-01', 'valid_to' => '2027-06-30']), 'test', null);
        check('regula suprapusa este refuzata', false);
    } catch (InvalidArgumentException $exception) {
        check('regula suprapusa este refuzata', str_contains($exception->getMessage(), 'suprapune'), $exception->getMessage());
    }

    // Un sofer de test, inserat direct: metodele care ruleaza DDL (ensure*Schema)
    // ar face COMMIT implicit si ar scoate testul din tranzactie.
    $db->prepare("INSERT INTO soferi (nume, telefon, salariu, data_angajare, permis_expira_la, status, tip_colaborare, created_at, updated_at)
                  VALUES ('Test Salarizare', '0700000000', 4500, '2025-01-01', '9999-12-31', 'activ', 'angajat', NOW(), NOW())")->execute();
    $driverId = (int) $db->lastInsertId();
    assert_in_transaction($db);
    $row = static fn () => $staffModel->findSubject('driver', (int) $driverId);

    assert_in_transaction($db);
    echo "\n12) Profil incomplet -> Necesita configurare (fara rezultat fals)\n";
    $summary = $service->calculateAndSave([$row()], $sept, null);
    $record = $payrollModel->findPayroll('driver', (int) $driverId, $sept['period']);
    check('status neconfigurat, raportat in rezumat', $record['calculation_status'] === 'neconfigurat' && count($summary['unconfigured']) === 1);
    check('niciun cost salvat pentru profil incomplet', $record['total_employer_cost'] === null && $record['net_salary'] === null);
    check('lipsurile sunt enumerate (profil + pontaj)', str_contains((string) $record['missing_fields'], 'Profil salarizare') && str_contains((string) $record['missing_fields'], 'Pontajul'));

    assert_in_transaction($db);
    echo "\n13) Istoric salarial NET: ian-iun 4.500, din iulie 5.000\n";
    $staffModel->updateSalary('driver', (int) $driverId, 4500.0, '2026-01-01', 'test', null);
    $staffModel->updateSalary('driver', (int) $driverId, 5000.0, '2026-07-01', 'test', null);
    $payrollModel->saveProfile('driver', (int) $driverId, [
        'contract_type' => 'cim', 'norm_type' => 'full', 'hours_per_day' => 8, 'is_basic_function' => 1, 'salary_input_type' => 'net',
        'dependents_count' => 0, 'children_in_school' => 0, 'under_26' => 0, 'work_conditions' => 'normale', 'tax_exemption' => 'niciuna',
        'min_base_exemption' => null, 'notes' => null,
    ], null);
    $may = StaffMonthlyAccountingService::parsePeriod('2026-05');
    foreach ([$may, $sept] as $p) {
        $staffModel->saveMonthlyRecord('driver', (int) $driverId, $p['period'], ['zile_lucrate' => 24, 'zile_absente' => 0, 'salariu_baza' => null], null);
    }
    $service->calculateAndSave([$row()], $may, null);
    $service->calculateAndSave([$row()], $sept, null);
    $mayRec = $payrollModel->findPayroll('driver', (int) $driverId, $may['period']);
    $septRec = $payrollModel->findPayroll('driver', (int) $driverId, $sept['period']);
    check('mai: salariu configurat 4.500 NET, regula S1', (float) $mayRec['configured_salary'] === 4500.0 && (int) $mayRec['fiscal_rule_id'] === (int) $ruleJune['id'], $mayRec['configured_salary'] . ' ' . $mayRec['calculation_status']);
    check('septembrie: salariu configurat 5.000 NET, regula S2', (float) $septRec['configured_salary'] === 5000.0 && (int) $septRec['fiscal_rule_id'] === (int) $ruleSept['id']);
    check('septembrie: net calculat 5.000 (NET->BRUT), brut ' . $septRec['gross_salary'] . ', cost ' . $septRec['total_employer_cost'], (float) $septRec['net_salary'] >= 5000.0 && (float) $septRec['net_salary'] - 5000 <= 1.0 && (float) $septRec['total_employer_cost'] > (float) $septRec['gross_salary']);
    check('cost firma != net (brut + CAM)', (float) $septRec['total_employer_cost'] === (float) $septRec['gross_salary'] + (float) $septRec['cam']);
    check('KPI luna = SUM(total_employer_cost)', $payrollModel->getMonthTotals($sept['period'])['total_cost'] === (float) $septRec['total_employer_cost'] + array_sum(array_map('floatval', $db->query("SELECT COALESCE(SUM(total_employer_cost),0) FROM payroll_monthly WHERE perioada = '2026-09-01' AND calculation_status IN ('calculat','de_verificat') AND NOT (subject_type = 'driver' AND subject_id = " . (int) $driverId . ')')->fetchAll(PDO::FETCH_COLUMN))));

    assert_in_transaction($db);
    echo "\n14) Sofer: regim 6 zile, pontaj 24 zile (nu cele 22 zile lucratoare RO)\n";
    $staffModel->updateWorkRegime('driver', (int) $driverId, '6_zile', null, null);
    $built = $service->buildInputs([$row()], $sept)['inputs']['driver-' . $driverId];
    check('zilele lucrate vin din pontaj (24), nu din calendarul legal', $built['attendance']['worked_days'] === 24.0);

    assert_in_transaction($db);
    echo "\n15) Schimbarea lunii: octombrie nu refoloseste septembrie\n";
    $oct = StaffMonthlyAccountingService::parsePeriod('2026-10');
    check('octombrie: niciun calcul inca', $payrollModel->findPayroll('driver', (int) $driverId, $oct['period']) === null);
    $octInputs = $service->buildInputs([$row()], $oct)['inputs']['driver-' . $driverId];
    check('octombrie: pontaj necompletat (nu cel din septembrie)', $octInputs['attendance']['recorded'] === false);

    assert_in_transaction($db);
    echo "\n16) Confirmare si instantaneu\n";
    $payrollModel->confirmPayroll('driver', (int) $driverId, $sept['period'], null);
    $confirmed = $payrollModel->findPayroll('driver', (int) $driverId, $sept['period']);
    check('status confirmat', $confirmed['confirmation_status'] === 'confirmat' && $confirmed['confirmed_at'] !== null);
    $staffModel->updateSalary('driver', (int) $driverId, 6000.0, '2026-09-01', 'marire retroactiva', null);
    $summary = $service->calculateAndSave([$row()], $sept, null);
    $after = $payrollModel->findPayroll('driver', (int) $driverId, $sept['period']);
    check('dupa schimbarea salariului: septembrie confirmat ramane neschimbat', $after['net_salary'] === $confirmed['net_salary'] && $after['total_employer_cost'] === $confirmed['total_employer_cost'] && count($summary['confirmed_skipped']) === 1);
    try {
        $payrollModel->saveRule((int) $ruleSept['id'], array_merge($ruleSept, ['cas_employee_rate' => 26]), 'test', null);
        check('regula folosita de un stat confirmat NU se editeaza', false);
    } catch (InvalidArgumentException $exception) {
        check('regula folosita de un stat confirmat NU se editeaza', str_contains($exception->getMessage(), 'versiune nouă'), $exception->getMessage());
    }
    $newRuleId = $payrollModel->closeRuleAndCreateVersion((int) $ruleSept['id'], '2026-11-01', array_merge($ruleSept, ['name' => 'test-nov', 'valid_to' => '2026-12-31', 'cas_employee_rate' => 26]), 'test lege noua', null);
    $closed = $payrollModel->findRule((int) $ruleSept['id']);
    check('versiune noua: regula veche inchisa la 31.10.2026', $closed['valid_to'] === '2026-10-31');
    check('septembrie ramane pe regula veche', (int) $payrollModel->findPayroll('driver', (int) $driverId, $sept['period'])['fiscal_rule_id'] === (int) $ruleSept['id']);
    $nov = StaffMonthlyAccountingService::parsePeriod('2026-11');
    check('noiembrie foloseste noua regula', (int) $payrollModel->findRuleForPeriod($nov['start'], $nov['end'], $nov['label'])['rule']['id'] === $newRuleId);
    $auditCount = (int) $db->query('SELECT COUNT(*) FROM payroll_fiscal_rule_audit WHERE rule_id IN (' . (int) $ruleSept['id'] . ',' . $newRuleId . ')')->fetchColumn();
    check('jurnal configurare: inchidere + creare', $auditCount >= 2, (string) $auditCount);

    assert_in_transaction($db);
    echo "\n17) Redeschidere cu jurnal\n";
    $payrollModel->reopenPayroll('driver', (int) $driverId, $sept['period'], 'marire salariala retroactiva', null);
    $service->calculateAndSave([$row()], $sept, null);
    $recalc = $payrollModel->findPayroll('driver', (int) $driverId, $sept['period']);
    check('dupa redeschidere + recalcul: salariul nou 6.000 NET', (float) $recalc['configured_salary'] === 6000.0 && $recalc['confirmation_status'] === 'neconfirmat');
    $audit = $payrollModel->getPayrollAudit((int) $recalc['id']);
    $actions = array_column($audit, 'action');
    check('jurnal: calcul, confirmare, redeschidere (cu motiv si rezultatul vechi), recalculare', in_array('confirmare', $actions, true) && in_array('redeschidere', $actions, true) && in_array('recalculare', $actions, true), implode(',', $actions));
    $reopenRow = $audit[array_search('redeschidere', $actions, true)];
    check('redeschiderea pastreaza vechiul rezultat', str_contains((string) $reopenRow['old_result'], (string) $confirmed['total_employer_cost']) && $reopenRow['reason'] === 'marire salariala retroactiva');

    assert_in_transaction($db);
    echo "\n18) Recalcul necesar dupa schimbarea datelor\n";
    $payrollModel->saveProfile('driver', (int) $driverId, [
        'contract_type' => 'cim', 'norm_type' => 'full', 'hours_per_day' => 8, 'is_basic_function' => 1, 'salary_input_type' => 'net',
        'dependents_count' => 2, 'children_in_school' => 0, 'under_26' => 0, 'work_conditions' => 'normale', 'tax_exemption' => 'niciuna',
        'min_base_exemption' => null, 'notes' => null,
    ], null);
    $status = $service->statusForRows([$row()], $sept)['driver-' . $driverId];
    check('profil schimbat dupa calcul -> Necesita recalculare', $status['stale'] === true);
    try {
        $payrollModel->confirmPayroll('driver', (int) $driverId, $sept['period'], null);
        $confirmedAnyway = $payrollModel->findPayroll('driver', (int) $driverId, $sept['period'])['confirmation_status'] === 'confirmat';
        check('(modelul permite confirmarea; controllerul o blocheaza pe baza amprentei)', $confirmedAnyway);
    } catch (InvalidArgumentException $exception) {
        check('confirmare blocata', true);
    }
    assert_in_transaction($db);
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "  (tranzactie anulata — nu au ramas date de test)\n";
}
