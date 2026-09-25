<?php
declare(strict_types=1);

/**
 * Leaga calculul salarial de datele aplicatiei pentru o luna contabila.
 *
 * Incarca PE LOT (nu per angajat) regula fiscala a lunii, profilurile, salariul
 * aplicabil din istoric, pontajul (personal_luna), concediile aprobate ale
 * soferilor (Programare concedii, read-only) si elementele lunii, apoi ruleaza
 * PayrollCalculatorService si salveaza rezultatul in payroll_monthly.
 *
 * Amprenta intrarilor (input_fingerprint) permite sa aratam "Necesita recalculare"
 * cand ceva s-a schimbat dupa calcul (salariu, profil, pontaj, elemente, regula).
 */
class PayrollMonthService
{
    private StaffAccountancyModel $staffModel;
    private PayrollModel $payrollModel;
    private PayrollCalculatorService $engine;

    public function __construct(StaffAccountancyModel $staffModel, PayrollModel $payrollModel, ?PayrollCalculatorService $engine = null)
    {
        $this->staffModel = $staffModel;
        $this->payrollModel = $payrollModel;
        $this->engine = $engine ?? new PayrollCalculatorService();
    }

    public static function subjectPairs(array $rows): array
    {
        $pairs = ['driver' => [], 'staff' => []];
        foreach ($rows as $row) {
            $type = (string) ($row['source_type'] ?? '');
            if (isset($pairs[$type])) {
                $pairs[$type][] = (int) $row['source_id'];
            }
        }

        return $pairs;
    }

    /**
     * Intrarile motorului pentru toate randurile, cu cateva interogari in total.
     *
     * @return array{rule: ?array, rule_error: ?string, inputs: array<string, array>}
     */
    public function buildInputs(array $rows, array $period): array
    {
        $pairs = self::subjectPairs($rows);
        $ruleLookup = $this->payrollModel->findRuleForPeriod($period['start'], $period['end'], $period['label']);
        $profiles = $this->payrollModel->getProfilesForSubjects($pairs);
        $items = $this->payrollModel->getItemsForSubjects($pairs, $period['period']);
        $salaryHistory = $this->staffModel->getSalaryHistoryForRows($rows);
        $attendance = $this->staffModel->getMonthlyRecordsForRows($rows, $period['period']);
        $leaves = $this->staffModel->getApprovedLeavesForDrivers($pairs['driver'], $period['start'], $period['end']);

        $inputs = [];
        foreach ($rows as $row) {
            $type = (string) $row['source_type'];
            $id = (int) $row['source_id'];
            $key = $type . '-' . $id;
            $history = $salaryHistory[$key] ?? [];
            $hire = !empty($row['data_angajare']) ? substr((string) $row['data_angajare'], 0, 10) : null;
            $salary = StaffAccountancyModel::salaryAt($history, $row['salariu'] !== null ? (float) $row['salariu'] : null, $hire, $period['end']);
            $changedMidMonth = null;
            foreach ($history as $change) {
                if ($change['effective_date'] > $period['start'] && $change['effective_date'] <= $period['end']) {
                    $changedMidMonth = (string) $change['effective_date'];
                }
            }

            $record = $attendance[$key] ?? null;
            $recorded = $record !== null && $record['zile_lucrate'] !== null;
            if ($type === 'driver') {
                // CO/CM soferi: DOAR din Programare concedii (aprobat), niciodata introduse aici.
                $leaveTotals = StaffAccountancyModel::leaveDaysInMonth($leaves[$id] ?? [], $period['start'], $period['end'])['totals'];
                $coDays = (float) $leaveTotals['CO'];
                $cmDays = (float) $leaveTotals['CM'];
                $coSource = 'Programare concedii (aprobat)';
            } else {
                $coDays = $record !== null && $record['zile_co'] !== null ? (float) $record['zile_co'] : ($recorded ? 0.0 : null);
                $cmDays = $record !== null && $record['zile_cm'] !== null ? (float) $record['zile_cm'] : ($recorded ? 0.0 : null);
                $coSource = 'pontaj';
            }

            $monthItems = [];
            foreach ($items[$key] ?? [] as $item) {
                $amount = $item['amount'] !== null ? (float) $item['amount'] : null;
                if ($amount === null && $item['calculation_type'] === 'procent_din_baza') {
                    $amount = null; // suma se stabileste dupa brutul de baza (mai jos, in calculate())
                }
                $monthItems[] = [
                    'id' => (int) $item['id'],
                    'category' => $item['category'],
                    'name' => $item['name'],
                    'calculation_type' => $item['calculation_type'],
                    'default_value' => $item['default_value'],
                    'amount' => $amount,
                    'paid_in_cash' => (int) $item['paid_in_cash'],
                    'subject_to_cas' => (int) $item['subject_to_cas'],
                    'subject_to_cass' => (int) $item['subject_to_cass'],
                    'subject_to_income_tax' => (int) $item['subject_to_income_tax'],
                    'subject_to_cam' => (int) $item['subject_to_cam'],
                    'excluded_from_facility_ceiling' => (int) $item['excluded_from_facility_ceiling'],
                    'reason' => $item['reason'],
                ];
            }

            $inputs[$key] = [
                'period' => ['start' => $period['start'], 'end' => $period['end'], 'label' => $period['label']],
                'rule' => $ruleLookup['rule'],
                'profile' => $profiles[$key] ?? null,
                'salary' => ['amount' => $salary['amount'], 'source' => $salary['source'], 'changed_mid_month' => $changedMidMonth],
                'employment' => [
                    'hire_date' => $hire,
                    'termination_date' => !empty($row['termination_effective_date']) ? substr((string) $row['termination_effective_date'], 0, 10) : null,
                ],
                'attendance' => [
                    'recorded' => $recorded,
                    'worked_days' => $recorded ? (float) $record['zile_lucrate'] : null,
                    'absent_days' => $record !== null && $record['zile_absente'] !== null ? (float) $record['zile_absente'] : null,
                    'co_days' => $coDays,
                    'cm_days' => $cmDays,
                    'co_source' => $coSource,
                ],
                'items' => $monthItems,
            ];
        }

        return ['rule' => $ruleLookup['rule'], 'rule_error' => $ruleLookup['error'], 'inputs' => $inputs];
    }

    public static function fingerprint(array $input): string
    {
        $relevant = $input;
        unset($relevant['period']['label']);
        if (is_array($relevant['rule'] ?? null)) {
            $relevant['rule'] = array_intersect_key($relevant['rule'], array_flip(array_merge(PayrollModel::RULE_FIELDS, ['id'])));
        }
        if (is_array($relevant['profile'] ?? null)) {
            $relevant['profile'] = array_intersect_key($relevant['profile'], array_flip(PayrollModel::PROFILE_FIELDS));
        }

        return sha1((string) json_encode($relevant));
    }

    /** Ruleaza motorul pe o intrare (inclusiv sporurile procentuale din brutul de baza). */
    public function calculate(array $input): array
    {
        $pending = [];
        foreach ($input['items'] as $index => $item) {
            if ($item['amount'] === null && $item['calculation_type'] === 'procent_din_baza' && $item['default_value'] !== null) {
                $pending[] = $index;
                $input['items'][$index]['amount'] = 0.0;
            }
        }
        $result = $this->engine->calculate($input);
        if ($pending === [] || !isset($result['base_gross_salary'])) {
            return $result;
        }

        // Spor procentual: procentul tipului × brutul de baza, apoi recalcul complet.
        foreach ($pending as $index) {
            $input['items'][$index]['amount'] = PayrollCalculatorService::roundAmount(
                (float) $result['base_gross_salary'] * (float) $input['items'][$index]['default_value'] / 100,
                (string) $input['rule']['rounding_mode']
            );
        }
        $input['profile']['salary_input_type'] = 'gross';
        $input['salary']['amount'] = $result['base_gross_salary'];
        $final = $this->engine->calculate($input);
        $final['salary_input_type'] = $result['salary_input_type'];
        $final['configured_salary'] = $result['configured_salary'];
        $final['details']['conversion'] = $result['details']['conversion'] ?? null;

        return $final;
    }

    /**
     * Calculeaza si salveaza lunile angajatilor dati. Statele confirmate NU se ating.
     *
     * @return array{total: int, calculated: array, to_review: array, unconfigured: array, needs_accountant: array, errors: array, confirmed_skipped: array}
     */
    public function calculateAndSave(array $rows, array $period, ?int $userId): array
    {
        $summary = ['total' => count($rows), 'calculated' => [], 'to_review' => [], 'unconfigured' => [], 'needs_accountant' => [], 'errors' => [], 'confirmed_skipped' => []];
        $built = $this->buildInputs($rows, $period);
        $existing = $this->payrollModel->getPayrollForSubjects(self::subjectPairs($rows), $period['period']);

        foreach ($rows as $row) {
            $key = $row['source_type'] . '-' . (int) $row['source_id'];
            $name = (string) $row['nume'];
            if (($existing[$key]['confirmation_status'] ?? '') === 'confirmat') {
                $summary['confirmed_skipped'][] = $name;
                continue;
            }
            try {
                $input = $built['inputs'][$key];
                $result = $this->calculate($input);
                $result['details']['inputs'] = [
                    'salary_source' => $input['salary']['source'],
                    'attendance' => $input['attendance'],
                    'profile' => is_array($input['profile']) ? array_intersect_key($input['profile'], array_flip(PayrollModel::PROFILE_FIELDS)) : null,
                    'items' => $input['items'],
                ];
                $this->payrollModel->savePayrollResult((string) $row['source_type'], (int) $row['source_id'], $period['period'], $result, self::fingerprint($input), $userId);
                $bucket = match ($result['status']) {
                    PayrollCalculatorService::STATUS_CALCULATED => 'calculated',
                    PayrollCalculatorService::STATUS_TO_REVIEW => 'to_review',
                    PayrollCalculatorService::STATUS_UNCONFIGURED => 'unconfigured',
                    PayrollCalculatorService::STATUS_NEEDS_ACCOUNTANT => 'needs_accountant',
                    default => 'errors',
                };
                $summary[$bucket][] = $name;
            } catch (Throwable $exception) {
                error_log('[PayrollMonthService] ' . $key . ' ' . $exception->getMessage());
                $summary['errors'][] = $name . ' (' . $exception->getMessage() . ')';
            }
        }

        return $summary;
    }

    /**
     * Eticheta afisata pentru stadiul calculului unui angajat pe luna. "Calculat" si
     * "Confirmat contabil" sunt doua stari separate (confirmarea e a contabilului).
     *
     * @return array{key: string, label: string, class: string, confirm_label: ?string, confirm_class: string}
     */
    public static function statusMeta(?array $record, bool $stale): array
    {
        if ($record === null) {
            return ['key' => 'lipsa', 'label' => 'Neînregistrată', 'class' => 'is-muted', 'confirm_label' => null, 'confirm_class' => ''];
        }
        $labels = [
            'neconfigurat' => ['Neconfigurat', 'is-warning'],
            'necesita_verificare' => ['Necesită verificare contabilă', 'is-danger'],
            'eroare' => ['Eroare de calcul', 'is-danger'],
            'calculat' => ['Calculat', 'is-info'],
            'de_verificat' => ['De verificat', 'is-warning'],
        ];
        [$label, $class] = $labels[$record['calculation_status']] ?? ['—', 'is-muted'];
        $confirmed = $record['confirmation_status'] === 'confirmat';
        if (!$confirmed && $stale) {
            return ['key' => 'necesita_recalculare', 'label' => $label, 'class' => $class, 'confirm_label' => 'Necesită recalculare', 'confirm_class' => 'is-danger'];
        }
        if ($confirmed) {
            return ['key' => 'confirmat', 'label' => $label, 'class' => $class, 'confirm_label' => '✓ Confirmat', 'confirm_class' => 'is-success'];
        }
        $calculated = in_array($record['calculation_status'], ['calculat', 'de_verificat'], true);

        return ['key' => (string) $record['calculation_status'], 'label' => $label, 'class' => $class, 'confirm_label' => $calculated ? 'Neconfirmat' : null, 'confirm_class' => 'is-warning-soft'];
    }

    /**
     * Starea afisata a lunii pentru fiecare rand: neinregistrat / statusul calculului,
     * confirmarea si "necesita recalculare" daca intrarile s-au schimbat dupa calcul.
     *
     * @return array<string, array>
     */
    public function statusForRows(array $rows, array $period): array
    {
        $payroll = $this->payrollModel->getPayrollForSubjects(self::subjectPairs($rows), $period['period']);
        $built = null;
        $out = [];
        foreach ($rows as $row) {
            $key = $row['source_type'] . '-' . (int) $row['source_id'];
            $record = $payroll[$key] ?? null;
            $stale = false;
            if ($record !== null && $record['confirmation_status'] === 'neconfirmat') {
                $built ??= $this->buildInputs($rows, $period);
                $stale = isset($built['inputs'][$key]) && self::fingerprint($built['inputs'][$key]) !== (string) $record['input_fingerprint'];
            }
            $out[$key] = ['record' => $record, 'stale' => $stale || ($record['confirmation_status'] ?? '') === 'necesita_recalculare'];
        }

        return $out;
    }
}
