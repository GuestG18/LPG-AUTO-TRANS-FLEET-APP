<?php
declare(strict_types=1);

/**
 * Motorul de calcul salarial (BRUT -> NET si NET -> BRUT) pentru o luna.
 *
 * Serviciu pur: nu citeste baza de date. Primeste regula fiscala a lunii, profilul
 * de salarizare, salariul configurat, pontajul si elementele lunii (sporuri,
 * retineri, beneficii) si intoarce un rezultat structurat + explicatia fiecarei
 * valori (baza, cota, suma, regula).
 *
 * Nicio cota nu este scrisa aici: CAS, CASS, impozit, CAM, salariul minim, suma
 * netaxabila, plafonul, deducerea personala si rotunjirea vin din regula fiscala.
 *
 * Cand o situatie nu este suportata explicit (luna partiala, CM, conditii speciale,
 * scutiri, contract de colaborare...) calculul se OPRESTE si intoarce motivul, in
 * loc sa ghiceasca. "Calculat" nu inseamna "confirmat de contabil".
 */
class PayrollCalculatorService
{
    public const STATUS_UNCONFIGURED = 'neconfigurat';
    public const STATUS_CALCULATED = 'calculat';
    public const STATUS_TO_REVIEW = 'de_verificat';
    public const STATUS_NEEDS_ACCOUNTANT = 'necesita_verificare';
    public const STATUS_ERROR = 'eroare';

    /** Toleranta NET -> BRUT: salariul brut e in lei intregi, deci netul rezultat poate depasi tinta cu cel mult 1 leu. */
    public const NET_TOLERANCE = 1.0;

    // ------------------------------------------------------------------
    // Rotunjire (centralizata)
    // ------------------------------------------------------------------

    /**
     * Modul 'ro_salarii' (implicit): BAZELE de calcul se rotunjesc la leu neglijand
     * fractiunile pana la 50 de bani inclusiv (normele Codului fiscal), iar SUMELE
     * (contributii, impozit, deduceri) la leu cu 0,50 in sus — varianta care
     * reproduce netul oficial la salariul minim (2.574 lei la 4.050 lei in S1 2026,
     * 2.699 lei la 4.325 lei in S2 2026).
     */
    public static function roundBase(float $value, string $mode): float
    {
        return self::roundMoney($value, $mode === 'ro_salarii' ? 'leu_half_down' : $mode);
    }

    public static function roundAmount(float $value, string $mode): float
    {
        return self::roundMoney($value, $mode === 'ro_salarii' ? 'leu_half_up' : $mode);
    }

    /**
     * 'leu_half_down': fractiunile pana la 50 de bani inclusiv se neglijeaza, cele
     * peste 50 de bani se rotunjesc la 1 leu. 'leu_half_up': 0,50 se rotunjeste in sus.
     */
    public static function roundMoney(float $value, string $mode): float
    {
        $value = round($value, 6);
        switch ($mode) {
            case 'ro_salarii':
                throw new InvalidArgumentException('Modul ro_salarii se aplica prin roundBase() / roundAmount().');
            case 'leu_half_down':
                $sign = $value < 0 ? -1.0 : 1.0;
                $abs = abs($value);
                $whole = floor($abs);
                $fraction = round($abs - $whole, 6);
                return $sign * ($fraction > 0.5 ? $whole + 1 : $whole);
            case 'leu_half_up':
                return round($value, 0, PHP_ROUND_HALF_UP);
            case 'bani':
                return round($value, 2);
            default:
                throw new InvalidArgumentException('Mod de rotunjire necunoscut: ' . $mode);
        }
    }

    // ------------------------------------------------------------------
    // Punct de intrare
    // ------------------------------------------------------------------

    /**
     * @param array $input [
     *   'period' => ['start' => Y-m-d, 'end' => Y-m-d, 'label' => string],
     *   'rule' => array|null (rand payroll_fiscal_rules, personal_deduction_config decodat),
     *   'profile' => array|null (rand payroll_profiles),
     *   'salary' => ['amount' => ?float],
     *   'employment' => ['hire_date' => ?Y-m-d, 'termination_date' => ?Y-m-d],
     *   'attendance' => ['recorded' => bool, 'worked_days' => ?float, 'absent_days' => ?float,
     *                    'co_days' => ?float, 'cm_days' => ?float, 'co_source' => string],
     *   'items' => [[ 'category', 'name', 'amount', 'paid_in_cash', 'subject_to_cas', ... ]],
     * ]
     */
    public function calculate(array $input): array
    {
        $missing = [];
        $blockers = [];
        $warnings = [];

        $rule = $input['rule'] ?? null;
        $profile = $input['profile'] ?? null;
        $period = $input['period'];
        $salaryAmount = $input['salary']['amount'] ?? null;

        if ($rule === null) {
            $missing[] = 'Nu există configurație fiscală validă pentru ' . ($period['label'] ?? $period['start']) . '.';
        }

        // --- profil: nimic nu se deduce -------------------------------------
        $profile = is_array($profile) ? $profile : [];
        $required = [
            'contract_type' => 'Tip contract',
            'norm_type' => 'Normă (întreagă / parțială)',
            'is_basic_function' => 'Funcție de bază aici (da / nu)',
            'salary_input_type' => 'Salariul configurat este NET sau BRUT',
            'dependents_count' => 'Persoane în întreținere',
            'children_in_school' => 'Copii până la 18 ani înscriși la școală',
            'under_26' => 'Vârstă sub 26 de ani (da / nu)',
            'work_conditions' => 'Condiții de muncă',
            'tax_exemption' => 'Scutire de impozit',
        ];
        foreach ($required as $field => $label) {
            if (!array_key_exists($field, $profile) || $profile[$field] === null || $profile[$field] === '') {
                $missing[] = 'Profil salarizare: ' . $label . ' — neconfigurat.';
            }
        }
        if (($profile['norm_type'] ?? null) === 'part') {
            if (($profile['hours_per_day'] ?? null) === null || (float) $profile['hours_per_day'] <= 0) {
                $missing[] = 'Profil salarizare: Ore / zi (normă parțială) — neconfigurat.';
            }
            if (($profile['min_base_exemption'] ?? null) === null || $profile['min_base_exemption'] === '') {
                $missing[] = 'Profil salarizare: Excepție de la baza minimă de contribuții (normă parțială) — neconfigurat.';
            }
        }

        if ($salaryAmount === null || $salaryAmount <= 0) {
            $missing[] = 'Salariul valabil în această lună nu este cunoscut (fișă / istoric salarial).';
        }

        $attendance = $input['attendance'] ?? [];
        if (empty($attendance['recorded'])) {
            $missing[] = 'Pontajul lunii nu este înregistrat (Pontaj & Calendar: zile lucrate, zile absente).';
        } elseif (($attendance['absent_days'] ?? null) === null) {
            $missing[] = 'Pontaj: zilele absente nu sunt completate (0 dacă nu există).';
        }

        if ($missing !== []) {
            return $this->stop(self::STATUS_UNCONFIGURED, $missing, [], $rule);
        }

        // --- situatii pe care motorul NU le calculeaza (nu ghicim) ------------
        if ($profile['contract_type'] !== 'cim') {
            $blockers[] = 'Contractul nu este CIM (' . $profile['contract_type'] . '): nu se calculează prin statul de salarii.';
        }
        if ($profile['work_conditions'] !== 'normale') {
            $blockers[] = 'Condiții de muncă „' . $profile['work_conditions'] . '”: tratamentul CAS pentru condiții deosebite/speciale nu este implementat.';
        }
        if ($profile['tax_exemption'] !== 'niciuna') {
            $blockers[] = 'Scutire de impozit „' . $profile['tax_exemption'] . '”: calculul cu scutiri nu este implementat.';
        }
        $hire = $input['employment']['hire_date'] ?? null;
        $termination = $input['employment']['termination_date'] ?? null;
        if ($hire !== null && $hire > $period['start']) {
            $blockers[] = 'Angajat în cursul lunii (' . $hire . '): calculul proporțional nu este implementat.';
        }
        if ($termination !== null && $termination < $period['end']) {
            $blockers[] = 'Contract încetat în cursul lunii (' . $termination . '): calculul proporțional nu este implementat.';
        }
        if (!empty($input['salary']['changed_mid_month'])) {
            $blockers[] = 'Salariul s-a modificat în cursul lunii (' . $input['salary']['changed_mid_month'] . '): calculul pe două perioade nu este implementat.';
        }
        if ((float) ($attendance['cm_days'] ?? 0) > 0) {
            $blockers[] = 'Necesită calcul CM: ' . $this->days((float) $attendance['cm_days']) . ' zile de concediu medical (indemnizația CM are regim separat, neimplementat).';
        }
        if ((float) ($attendance['absent_days'] ?? 0) > 0) {
            $blockers[] = 'Absențe în lună (' . $this->days((float) $attendance['absent_days']) . ' zile): reducerea salariului nu este implementată.';
        }
        if ($blockers !== []) {
            return $this->stop(self::STATUS_NEEDS_ACCOUNTANT, $blockers, [], $rule);
        }

        if ((float) ($attendance['co_days'] ?? 0) > 0) {
            $warnings[] = 'Concediu de odihnă ' . $this->days((float) $attendance['co_days']) . ' zile (' . ($attendance['co_source'] ?? 'pontaj') . '): indemnizația de CO a fost considerată egală cu salariul de bază al zilelor respective. Corect doar pentru salariu fix, fără sporuri în ultimele 3 luni — de verificat.';
        }

        $ctx = $this->context($rule, $profile, $input['items'] ?? []);
        if ($ctx['errors'] !== []) {
            return $this->stop(self::STATUS_ERROR, $ctx['errors'], [], $rule);
        }

        // --- salariul de baza brut -------------------------------------------
        $inputType = (string) $profile['salary_input_type'];
        $conversion = null;
        if ($inputType === 'gross') {
            $baseGross = round((float) $salaryAmount, 2);
        } else {
            $conversion = $this->netToGross((float) $salaryAmount, $ctx);
            if ($conversion === null) {
                return $this->stop(self::STATUS_ERROR, ['Nu am găsit un salariu brut care să producă netul de ' . $salaryAmount . ' lei.'], [], $rule);
            }
            $baseGross = $conversion['gross'];
            if ($conversion['difference'] > self::NET_TOLERANCE) {
                $warnings[] = 'NET→BRUT: netul rezultat (' . $conversion['net'] . ' lei) depășește ținta cu ' . $conversion['difference'] . ' lei — de verificat.';
            }
        }

        if ($profile['norm_type'] === 'full' && $baseGross < (float) $rule['minimum_gross_salary']) {
            return $this->stop(self::STATUS_NEEDS_ACCOUNTANT, [
                'Salariul de bază brut (' . $baseGross . ' lei) este sub salariul minim brut (' . $rule['minimum_gross_salary'] . ' lei) pentru normă întreagă.',
            ], [], $rule);
        }

        $result = $this->computeFromGross($baseGross, $ctx, true);
        $result['warnings'] = array_merge($warnings, $result['warnings']);
        $result['status'] = $result['warnings'] === [] ? self::STATUS_CALCULATED : self::STATUS_TO_REVIEW;
        $result['missing'] = [];
        $result['salary_input_type'] = $inputType;
        $result['configured_salary'] = (float) $salaryAmount;
        $result['fiscal_rule_id'] = (int) $rule['id'];
        $result['details']['conversion'] = $conversion;
        $result['details']['rule'] = $this->ruleSnapshot($rule);

        return $result;
    }

    /**
     * NET -> BRUT pentru salariul de baza (fara elementele lunii), cu acelasi motor
     * ca BRUT -> NET. Salariul brut este in lei intregi: se cauta cel mai mic brut
     * al carui net este >= tinta. Facilitatea (suma netaxabila) face netul
     * nemonoton in jurul salariului minim, deci candidatul "la minim" se verifica separat.
     *
     * @return array{gross: float, net: float, difference: float, iterations: int}|null
     */
    public function netToGross(float $targetNet, array $ctx): ?array
    {
        $baseOnlyCtx = $ctx;
        $baseOnlyCtx['items'] = [];

        $netAt = function (float $gross, bool $allowFacility) use ($baseOnlyCtx): float {
            return $this->computeFromGross($gross, $baseOnlyCtx, $allowFacility)['net_salary'];
        };

        // Fara facilitate netul creste monoton cu brutul: cautare binara pe lei intregi.
        $low = max(1.0, floor($targetNet));
        $high = max($low + 1, ceil($targetNet * 3));
        $iterations = 0;
        if ($netAt($high, false) < $targetNet) {
            return null;
        }
        while ($high - $low > 1 && $iterations < 60) {
            $iterations++;
            $mid = floor(($low + $high) / 2);
            if ($netAt($mid, false) >= $targetNet) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }
        $gross = $netAt($low, false) >= $targetNet ? $low : $high;

        // Candidatul "exact la salariul minim", unde se poate aplica facilitatea.
        $minimum = (float) $ctx['rule']['minimum_gross_salary'];
        if ($minimum < $gross) {
            $netAtMinimum = $this->computeFromGross($minimum, $baseOnlyCtx, true);
            if ($netAtMinimum['non_taxable_amount'] > 0 && $netAtMinimum['net_salary'] >= $targetNet) {
                $gross = $minimum;
            }
        }

        $final = $this->computeFromGross($gross, $baseOnlyCtx, true);

        return [
            'gross' => $gross,
            'net' => $final['net_salary'],
            'difference' => round($final['net_salary'] - $targetNet, 2),
            'iterations' => $iterations,
        ];
    }

    // ------------------------------------------------------------------
    // Calculul propriu-zis (BRUT -> NET)
    // ------------------------------------------------------------------

    /**
     * @param bool $allowFacility false doar in cautarea NET->BRUT (ramura monotona).
     */
    public function computeFromGross(float $baseGross, array $ctx, bool $allowFacility): array
    {
        $rule = $ctx['rule'];
        $profile = $ctx['profile'];
        $mode = (string) $rule['rounding_mode'];
        $rb = static fn (float $value): float => self::roundBase($value, $mode);
        $ra = static fn (float $value): float => self::roundAmount($value, $mode);
        $pct = static fn (mixed $rate): float => (float) $rate / 100;
        $lines = [];
        $warnings = [];

        // --- componente -------------------------------------------------------
        $cashTaxable = $baseGross;           // bani platiti, supusi cel putin unei contributii/impozit
        $inKind = 0.0;                        // beneficii in natura (nu se platesc in bani)
        $nonTaxableCash = 0.0;                // adaosuri neimpozabile platite in bani
        $bonuses = 0.0;
        $benefits = 0.0;
        $deductions = 0.0;
        $casBase = $baseGross;
        $cassBase = $baseGross;
        $taxableIncome = $baseGross;          // venituri supuse impozitului (inainte de contributii)
        $camBase = $baseGross;
        $ceilingIncome = $baseGross;          // venitul pentru plafonul facilitatii
        $grossIncome = $baseGross;            // "venitul lunar brut" pentru deducerea personala
        $itemLines = [];

        foreach ($ctx['items'] as $item) {
            $amount = (float) $item['amount'];
            if ($item['category'] === 'retinere') {
                $deductions += $amount;
                $itemLines[] = ['label' => $item['name'], 'amount' => -$amount, 'treatment' => 'reținere din net'];
                continue;
            }

            $anyTax = $item['subject_to_cas'] || $item['subject_to_cass'] || $item['subject_to_income_tax'] || $item['subject_to_cam'];
            if ($item['category'] === 'spor') {
                $bonuses += $amount;
            } else {
                $benefits += $amount;
            }
            if ($item['paid_in_cash']) {
                if ($anyTax) {
                    $cashTaxable += $amount;
                } else {
                    $nonTaxableCash += $amount;
                }
            } else {
                $inKind += $amount;
            }
            if ($item['subject_to_cas']) {
                $casBase += $amount;
            }
            if ($item['subject_to_cass']) {
                $cassBase += $amount;
            }
            if ($item['subject_to_income_tax']) {
                $taxableIncome += $amount;
                $grossIncome += $amount;
                if (empty($item['excluded_from_facility_ceiling'])) {
                    $ceilingIncome += $amount;
                }
            }
            if ($item['subject_to_cam']) {
                $camBase += $amount;
            }
            $itemLines[] = [
                'label' => $item['name'],
                'amount' => $amount,
                'treatment' => $this->treatmentLabel($item),
            ];
        }

        // --- facilitatea: suma netaxabila la salariul minim ------------------
        $facility = $this->facility($baseGross, $ceilingIncome, $ctx, $allowFacility);
        $f = $facility['amount'];

        // --- CAS / CASS angajat + baza minima (norma partiala) ---------------
        $casBaseEmployee = $rb($casBase - $f);
        $cassBaseEmployee = $rb($cassBase - $f);
        $cas = $ra($casBaseEmployee * $pct($rule['cas_employee_rate']));
        $cass = $ra($cassBaseEmployee * $pct($rule['cass_employee_rate']));

        $employerDiff = 0.0;
        $diffLines = [];
        if ($profile['norm_type'] === 'part' && (int) $rule['minimum_contribution_base_enabled'] === 1
            && ($profile['min_base_exemption'] ?? '') === 'niciuna') {
            $minimum = (float) $rule['minimum_gross_salary'];
            if ($casBaseEmployee < $minimum) {
                $diffCas = $ra(($minimum - $casBaseEmployee) * $pct($rule['cas_employee_rate']));
                $diffCass = $cassBaseEmployee < $minimum ? $ra(($minimum - $cassBaseEmployee) * $pct($rule['cass_employee_rate'])) : 0.0;
                $employerDiff = $diffCas + $diffCass;
                $diffLines = [
                    ['key' => 'cas_diff', 'label' => 'Diferență CAS suportată de angajator', 'base' => $minimum - $casBaseEmployee, 'rate' => (float) $rule['cas_employee_rate'], 'amount' => $diffCas],
                    ['key' => 'cass_diff', 'label' => 'Diferență CASS suportată de angajator', 'base' => max(0, $minimum - $cassBaseEmployee), 'rate' => (float) $rule['cass_employee_rate'], 'amount' => $diffCass],
                ];
            }
        }

        // --- deducerea personala ---------------------------------------------
        $netIncome = $rb($taxableIncome - $f - $cas - $cass);
        $deduction = $this->personalDeduction($grossIncome, max(0.0, $netIncome), $ctx);

        // --- impozit ----------------------------------------------------------
        $taxBase = $rb(max(0.0, $netIncome - $deduction['total']));
        $tax = $ra($taxBase * $pct($rule['income_tax_rate']));

        // --- CAM angajator ----------------------------------------------------
        $camBaseFinal = $rb($camBase - ((int) $rule['non_taxable_excludes_cam'] === 1 ? $f : 0.0));
        $cam = $ra($camBaseFinal * $pct($rule['cam_employer_rate']));

        // --- total -------------------------------------------------------------
        $grossSalary = $cashTaxable;                                  // brut platit in bani
        $netSalary = round($grossSalary - $cas - $cass - $tax + $nonTaxableCash, 2);
        $netPayable = round($netSalary - $deductions, 2);
        $employerContributions = round($cam + $employerDiff, 2);
        $totalEmployerCost = round($grossSalary + $inKind + $nonTaxableCash + $employerContributions, 2);

        $ruleLabel = (string) $rule['name'];
        $lines = [
            ['key' => 'base_gross', 'label' => 'Salariu de bază brut', 'amount' => $baseGross, 'explain' => 'Din salariul configurat (' . ($profile['salary_input_type'] === 'net' ? 'NET, convertit în BRUT' : 'BRUT') . ').'],
            ['key' => 'facility', 'label' => 'Sumă netaxabilă (salariu minim)', 'amount' => $f, 'explain' => $facility['reason']],
            ['key' => 'cas', 'label' => 'CAS', 'base' => $casBaseEmployee, 'rate' => (float) $rule['cas_employee_rate'], 'amount' => $cas, 'explain' => 'Baza CAS = venituri supuse CAS' . ($f > 0 ? ' − suma netaxabilă' : '') . '. Regula ' . $ruleLabel . '.'],
            ['key' => 'cass', 'label' => 'CASS', 'base' => $cassBaseEmployee, 'rate' => (float) $rule['cass_employee_rate'], 'amount' => $cass, 'explain' => 'Baza CASS = venituri supuse CASS' . ($f > 0 ? ' − suma netaxabilă' : '') . '. Regula ' . $ruleLabel . '.'],
            ['key' => 'personal_deduction', 'label' => 'Deducere personală', 'amount' => $deduction['total'], 'explain' => $deduction['explain']],
            ['key' => 'income_tax', 'label' => 'Impozit pe venit', 'base' => $taxBase, 'rate' => (float) $rule['income_tax_rate'], 'amount' => $tax, 'explain' => 'Baza = venituri impozabile − suma netaxabilă − CAS − CASS − deducere personală (' . $netIncome . ' − ' . $deduction['total'] . '). Regula ' . $ruleLabel . '.'],
            ['key' => 'cam', 'label' => 'CAM (angajator)', 'base' => $camBaseFinal, 'rate' => (float) $rule['cam_employer_rate'], 'amount' => $cam, 'explain' => 'Baza CAM = venituri supuse CAM' . ($f > 0 && (int) $rule['non_taxable_excludes_cam'] === 1 ? ' − suma netaxabilă' : '') . '. Regula ' . $ruleLabel . '.'],
        ];

        return [
            'base_gross_salary' => $baseGross,
            'gross_salary' => $grossSalary,
            'taxable_gross' => $rb($taxableIncome - $f),
            'cas_base' => $casBaseEmployee,
            'cas' => $cas,
            'cass_base' => $cassBaseEmployee,
            'cass' => $cass,
            'personal_deduction' => $deduction['total'],
            'income_tax_base' => $taxBase,
            'income_tax' => $tax,
            'cam_base' => $camBaseFinal,
            'cam' => $cam,
            'employer_contribution_differences' => $employerDiff,
            'other_employer_costs' => 0.0,
            'bonuses' => $bonuses,
            'benefits' => $benefits,
            'non_taxable_additions' => $nonTaxableCash,
            'other_deductions' => $deductions,
            'non_taxable_amount' => $f,
            'non_taxable_facility_eligible' => $facility['eligible'],
            'eligibility_reason' => $facility['reason'],
            'net_salary' => $netSalary,
            'net_payable' => $netPayable,
            'total_employee_withholdings' => round($cas + $cass + $tax, 2),
            'total_employer_contributions' => $employerContributions,
            'total_employer_cost' => $totalEmployerCost,
            'warnings' => $warnings,
            'details' => [
                'lines' => array_merge($lines, $diffLines),
                'items' => $itemLines,
                'personal_deduction' => $deduction,
                'facility' => $facility,
                'in_kind_benefits' => $inKind,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Reguli auxiliare
    // ------------------------------------------------------------------

    /**
     * Eligibilitatea pentru suma netaxabila (OUG 89/2025 art. III): CIM, norma
     * intreaga, functie de baza, salariul de baza EGAL cu minimul in vigoare, venitul
     * brut (fara tichete de masa / vouchere / indemnizatie de hrana) <= plafon.
     * Luna partiala nu ajunge aici (este oprita inainte), deci nu se proratizeaza.
     */
    private function facility(float $baseGross, float $ceilingIncome, array $ctx, bool $allowFacility): array
    {
        $rule = $ctx['rule'];
        $profile = $ctx['profile'];
        $amount = (float) $rule['non_taxable_minimum_salary_amount'];
        $limit = $rule['non_taxable_income_limit'] !== null ? (float) $rule['non_taxable_income_limit'] : null;
        $minimum = (float) $rule['minimum_gross_salary'];

        $no = static fn (string $reason): array => ['eligible' => false, 'amount' => 0.0, 'reason' => $reason];
        if ($amount <= 0) {
            return $no('Regula fiscală a lunii nu prevede sumă netaxabilă.');
        }
        if (!$allowFacility) {
            return $no('Neevaluat (ramura de căutare NET→BRUT).');
        }
        if ($profile['contract_type'] !== 'cim') {
            return $no('Neeligibil: nu este contract individual de muncă.');
        }
        if ($profile['norm_type'] !== 'full') {
            return $no('Neeligibil: nu este normă întreagă.');
        }
        if ((int) $profile['is_basic_function'] !== 1) {
            return $no('Neeligibil: nu este locul funcției de bază.');
        }
        if (abs($baseGross - $minimum) > 0.001) {
            return $no('Neeligibil: salariul de bază brut (' . $baseGross . ') nu este egal cu salariul minim (' . $minimum . ').');
        }
        if ($limit !== null && $ceilingIncome > $limit) {
            return $no('Neeligibil: venitul brut (' . $ceilingIncome . ') depășește plafonul de ' . $limit . ' lei.');
        }

        return [
            'eligible' => true,
            'amount' => $amount,
            'reason' => 'Eligibil: CIM, normă întreagă, funcție de bază, salariu de bază = minimul (' . $minimum . '), venit brut ' . $ceilingIncome . ' ≤ ' . $limit . ' lei. Lună întreagă: ' . $amount . ' lei.',
        ];
    }

    /**
     * Deducerea personala (art. 77): de baza — procent din salariul minim dupa
     * numarul de persoane in intretinere, scazut pe transe peste minim, pana la
     * minim + fereastra; suplimentara — sub 26 de ani si copii la scoala.
     * Se acorda doar la functia de baza si in limita venitului impozabil.
     */
    private function personalDeduction(float $grossIncome, float $netIncome, array $ctx): array
    {
        $rule = $ctx['rule'];
        $profile = $ctx['profile'];
        $cfg = $rule['personal_deduction_config'];
        $mode = (string) $rule['rounding_mode'];
        $minimum = (float) $rule['minimum_gross_salary'];

        if ((int) $profile['is_basic_function'] !== 1) {
            return ['basic' => 0.0, 'youth' => 0.0, 'children' => 0.0, 'total' => 0.0, 'percent' => 0.0, 'explain' => 'Nu se acordă: veniturile nu sunt la locul funcției de bază.'];
        }

        $window = (float) $cfg['income_window_above_minimum'];
        $percents = array_map('floatval', $cfg['base_percent_by_dependents']);
        $dependents = min(count($percents) - 1, (int) $profile['dependents_count']);
        $percent = 0.0;
        $basic = 0.0;
        if ($grossIncome <= $minimum + $window) {
            $steps = $grossIncome > $minimum ? (int) ceil(round(($grossIncome - $minimum) / (float) $cfg['step_lei'], 6)) : 0;
            $percent = max(0.0, $percents[$dependents] - $steps * (float) $cfg['step_percent']);
            $basic = self::roundAmount($minimum * $percent / 100, $mode);
        }

        $youth = 0.0;
        if ((int) $profile['under_26'] === 1 && $grossIncome <= $minimum + $window) {
            $youth = self::roundAmount($minimum * (float) $cfg['youth_percent'] / 100, $mode);
        }
        $children = (int) $profile['children_in_school'] * (float) $cfg['child_in_school_amount'];

        $total = min($netIncome, $basic + $youth + $children);
        $explain = 'De bază: ' . $percent . '% × ' . $minimum . ' = ' . $basic
            . ' (' . $dependents . ' pers. în întreținere, venit brut ' . $grossIncome . ', fereastră minim + ' . $window . ')'
            . '; sub 26 ani: ' . $youth . '; copii la școală: ' . $children
            . ($total < $basic + $youth + $children ? '; limitată la venitul impozabil ' . $netIncome : '') . '.';

        return ['basic' => $basic, 'youth' => $youth, 'children' => $children, 'total' => $total, 'percent' => $percent, 'explain' => $explain];
    }

    private function context(array $rule, array $profile, array $items): array
    {
        $errors = [];
        $cfg = $rule['personal_deduction_config'] ?? null;
        foreach (['base_percent_by_dependents', 'income_window_above_minimum', 'step_lei', 'step_percent', 'youth_percent', 'child_in_school_amount'] as $key) {
            if (!is_array($cfg) || !array_key_exists($key, $cfg)) {
                $errors[] = 'Regula fiscală „' . $rule['name'] . '”: configurația deducerii personale nu are „' . $key . '”.';
            }
        }
        try {
            self::roundAmount(1.0, (string) $rule['rounding_mode']);
            self::roundBase(1.0, (string) $rule['rounding_mode']);
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }

        $normalized = [];
        foreach ($items as $item) {
            if ($item['amount'] === null) {
                $errors[] = 'Elementul „' . $item['name'] . '” nu are sumă.';
                continue;
            }
            $normalized[] = [
                'category' => (string) $item['category'],
                'name' => (string) $item['name'],
                'amount' => (float) $item['amount'],
                'paid_in_cash' => (int) $item['paid_in_cash'] === 1,
                'subject_to_cas' => (int) $item['subject_to_cas'] === 1,
                'subject_to_cass' => (int) $item['subject_to_cass'] === 1,
                'subject_to_income_tax' => (int) $item['subject_to_income_tax'] === 1,
                'subject_to_cam' => (int) $item['subject_to_cam'] === 1,
                'excluded_from_facility_ceiling' => (int) ($item['excluded_from_facility_ceiling'] ?? 0) === 1,
            ];
        }

        return ['rule' => $rule, 'profile' => $profile, 'items' => $normalized, 'errors' => $errors];
    }

    private function stop(string $status, array $reasons, array $warnings, ?array $rule): array
    {
        return [
            'status' => $status,
            'missing' => $reasons,
            'warnings' => $warnings,
            'fiscal_rule_id' => $rule !== null ? (int) $rule['id'] : null,
            'details' => ['rule' => $rule !== null ? $this->ruleSnapshot($rule) : null],
        ];
    }

    private function ruleSnapshot(array $rule): array
    {
        return [
            'id' => (int) $rule['id'],
            'name' => (string) $rule['name'],
            'valid_from' => (string) $rule['valid_from'],
            'valid_to' => $rule['valid_to'] !== null ? (string) $rule['valid_to'] : null,
            'cas_employee_rate' => (float) $rule['cas_employee_rate'],
            'cass_employee_rate' => (float) $rule['cass_employee_rate'],
            'income_tax_rate' => (float) $rule['income_tax_rate'],
            'cam_employer_rate' => (float) $rule['cam_employer_rate'],
            'minimum_gross_salary' => (float) $rule['minimum_gross_salary'],
            'non_taxable_minimum_salary_amount' => (float) $rule['non_taxable_minimum_salary_amount'],
            'non_taxable_income_limit' => $rule['non_taxable_income_limit'] !== null ? (float) $rule['non_taxable_income_limit'] : null,
            'rounding_mode' => (string) $rule['rounding_mode'],
            'personal_deduction_config' => $rule['personal_deduction_config'],
            'legal_reference' => $rule['legal_reference'] ?? null,
        ];
    }

    private function treatmentLabel(array $item): string
    {
        $parts = [];
        foreach (['subject_to_cas' => 'CAS', 'subject_to_cass' => 'CASS', 'subject_to_income_tax' => 'impozit', 'subject_to_cam' => 'CAM'] as $flag => $label) {
            if ($item[$flag]) {
                $parts[] = $label;
            }
        }

        return ($item['paid_in_cash'] ? 'în bani' : 'în natură') . '; ' . ($parts === [] ? 'neimpozabil' : 'supus: ' . implode(', ', $parts));
    }

    private function days(float $value): string
    {
        return floor($value) === $value ? (string) (int) $value : number_format($value, 1, ',', '');
    }
}
