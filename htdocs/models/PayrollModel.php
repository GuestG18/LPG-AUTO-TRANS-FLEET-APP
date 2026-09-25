<?php
declare(strict_types=1);

/**
 * Datele calculului salarial: reguli fiscale versionate (cu audit), profilul de
 * salarizare, tipurile si elementele lunare (sporuri / retineri / beneficii),
 * statul lunar cu instantaneul confirmarii si jurnalul lui.
 *
 * Migrarea oficiala: database/migrations/2026_09_23_000002_payroll_salarizare.sql.
 */
class PayrollModel extends BaseModel
{
    /** Campurile unei reguli fiscale care se pot introduce din Configurare salarii. */
    public const RULE_FIELDS = [
        'name', 'valid_from', 'valid_to', 'status',
        'cas_employee_rate', 'cass_employee_rate', 'income_tax_rate', 'cam_employer_rate',
        'minimum_gross_salary', 'monthly_hours_norm', 'minimum_hourly_salary',
        'non_taxable_minimum_salary_amount', 'non_taxable_income_limit',
        'non_taxable_requires_eligibility', 'non_taxable_excludes_cam', 'minimum_contribution_base_enabled',
        'personal_deduction_config', 'rounding_mode', 'legal_reference', 'notes',
    ];

    public const PROFILE_FIELDS = [
        'contract_type', 'norm_type', 'hours_per_day', 'is_basic_function', 'salary_input_type',
        'dependents_count', 'children_in_school', 'under_26', 'work_conditions', 'tax_exemption',
        'min_base_exemption', 'notes',
    ];

    /** Rezultatul calculului salvat in payroll_monthly (acelasi nume ca in motor). */
    public const RESULT_FIELDS = [
        'salary_input_type', 'configured_salary', 'base_gross_salary', 'gross_salary', 'taxable_gross',
        'cas_base', 'cas', 'cass_base', 'cass', 'personal_deduction', 'income_tax_base', 'income_tax',
        'cam_base', 'cam', 'employer_contribution_differences', 'other_employer_costs', 'bonuses', 'benefits',
        'non_taxable_additions', 'other_deductions', 'non_taxable_amount', 'non_taxable_facility_eligible',
        'eligibility_reason', 'net_salary', 'net_payable', 'total_employee_withholdings',
        'total_employer_contributions', 'total_employer_cost',
    ];

    private static bool $schemaReady = false;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    // ------------------------------------------------------------------
    // Setari
    // ------------------------------------------------------------------

    /**
     * Calculul fiscal (BRUT/NET, contributii, cost firma) este OPTIONAL si implicit
     * dezactivat: atunci costul salarial al lunii = salariul configurat (istoric).
     */
    public function isFiscalCalculationEnabled(): bool
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM payroll_settings WHERE setting_key = :k');
        $stmt->execute([':k' => 'fiscal_calculation_enabled']);

        return (string) $stmt->fetchColumn() === '1';
    }

    public function setFiscalCalculationEnabled(bool $enabled, ?int $userId): void
    {
        $this->db->prepare('
            INSERT INTO payroll_settings (setting_key, setting_value, updated_by, updated_at)
            VALUES (:k, :v, :u, :t)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)
        ')->execute([':k' => 'fiscal_calculation_enabled', ':v' => $enabled ? '1' : '0', ':u' => $userId, ':t' => date('Y-m-d H:i:s')]);
    }

    // ------------------------------------------------------------------
    // Reguli fiscale
    // ------------------------------------------------------------------

    public function listRules(): array
    {
        $rows = $this->db->query('
            SELECT r.*,
                   (SELECT COUNT(*) FROM payroll_monthly pm WHERE pm.fiscal_rule_id = r.id AND pm.confirmation_status = "confirmat") AS confirmed_count,
                   (SELECT COUNT(*) FROM payroll_monthly pm WHERE pm.fiscal_rule_id = r.id) AS used_count
            FROM payroll_fiscal_rules r
            ORDER BY r.valid_from DESC, r.id DESC
        ')->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'decodeRule'], $rows);
    }

    public function findRule(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payroll_fiscal_rules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->decodeRule($row) : null;
    }

    /**
     * Regula activa care acopera TOATA luna. Fara regula (sau cu doua suprapuse)
     * intoarce null + motivul: nu se foloseste automat regula lunii anterioare.
     *
     * @return array{rule: ?array, error: ?string}
     */
    public function findRuleForPeriod(string $start, string $end, string $label): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM payroll_fiscal_rules
            WHERE status = "activ"
              AND valid_from <= :month_end
              AND (valid_to IS NULL OR valid_to >= :month_start)
            ORDER BY valid_from ASC
        ');
        $stmt->execute([':month_end' => $end, ':month_start' => $start]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return ['rule' => null, 'error' => 'Nu există configurație fiscală validă pentru ' . $label . '.'];
        }
        if (count($rows) > 1) {
            return ['rule' => null, 'error' => 'Configurații fiscale suprapuse sau schimbate în cursul lunii ' . $label . ' (' . implode(', ', array_column($rows, 'name')) . '). Corectați perioadele.'];
        }
        $rule = $this->decodeRule($rows[0]);
        if ($rule['valid_from'] > $start || ($rule['valid_to'] !== null && $rule['valid_to'] < $end)) {
            return ['rule' => null, 'error' => 'Regula „' . $rule['name'] . '” nu acoperă toată luna ' . $label . '.'];
        }

        return ['rule' => $rule, 'error' => null];
    }

    public function isRuleLocked(int $ruleId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM payroll_monthly WHERE fiscal_rule_id = :id AND confirmation_status = "confirmat"');
        $stmt->execute([':id' => $ruleId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Creeaza sau modifica o regula. O regula folosita de un stat confirmat NU se
     * modifica (doar se poate inchide prin closeRuleAndCreateVersion). Fiecare camp
     * schimbat se scrie in jurnal.
     */
    public function saveRule(?int $id, array $data, string $reason, ?int $userId): int
    {
        $this->assertRuleData($data);
        $this->assertNoOverlap($data['valid_from'], $data['valid_to'], $data['status'], $id);

        $now = date('Y-m-d H:i:s');
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }
        try {
            if ($id === null) {
                $columns = self::RULE_FIELDS;
                $stmt = $this->db->prepare('
                    INSERT INTO payroll_fiscal_rules (' . implode(', ', $columns) . ', created_by, updated_by, created_at, updated_at)
                    VALUES (:' . implode(', :', $columns) . ', :created_by, :updated_by, :created_at, :updated_at)
                ');
                $params = $this->ruleParams($data) + [':created_by' => $userId, ':updated_by' => $userId, ':created_at' => $now, ':updated_at' => $now];
                $stmt->execute($params);
                $id = (int) $this->db->lastInsertId();
                $this->auditRule($id, 'creare', null, null, json_encode($this->ruleForAudit($data), JSON_UNESCAPED_UNICODE), $data, $reason, $userId);
            } else {
                $existing = $this->findRule($id);
                if ($existing === null) {
                    throw new InvalidArgumentException('Regula fiscală nu există.');
                }
                if ($this->isRuleLocked($id)) {
                    throw new InvalidArgumentException('Regula „' . $existing['name'] . '” este folosită de state de salarii confirmate și nu se mai modifică. Închideți-o și creați o versiune nouă.');
                }
                $sets = [];
                foreach (self::RULE_FIELDS as $field) {
                    $sets[] = $field . ' = :' . $field;
                }
                $stmt = $this->db->prepare('UPDATE payroll_fiscal_rules SET ' . implode(', ', $sets) . ', updated_by = :updated_by, updated_at = :updated_at WHERE id = :id');
                $stmt->execute($this->ruleParams($data) + [':updated_by' => $userId, ':updated_at' => $now, ':id' => $id]);

                $old = $this->ruleForAudit($existing);
                $new = $this->ruleForAudit($data);
                foreach ($new as $field => $value) {
                    if ((string) ($old[$field] ?? '') !== (string) $value) {
                        $this->auditRule($id, 'modificare', $field, (string) ($old[$field] ?? ''), (string) $value, $data, $reason, $userId);
                    }
                }
                // Statele neconfirmate calculate cu regula veche trebuie recalculate.
                $this->db->prepare('UPDATE payroll_monthly SET confirmation_status = "necesita_recalculare", updated_at = :now WHERE fiscal_rule_id = :id AND confirmation_status = "neconfirmat"')
                    ->execute([':now' => $now, ':id' => $id]);
            }
            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return $id;
    }

    /**
     * Legislatia s-a schimbat: regula veche se inchide la ($newFrom - 1 zi) si se
     * creeaza una noua de la $newFrom. Lunile vechi raman pe regula veche.
     */
    public function closeRuleAndCreateVersion(int $ruleId, string $newFrom, array $data, string $reason, ?int $userId): int
    {
        $existing = $this->findRule($ruleId);
        if ($existing === null) {
            throw new InvalidArgumentException('Regula fiscală nu există.');
        }
        if ($newFrom <= $existing['valid_from']) {
            throw new InvalidArgumentException('Noua versiune trebuie să înceapă după ' . $existing['valid_from'] . '.');
        }
        $closeAt = (new DateTimeImmutable($newFrom))->modify('-1 day')->format('Y-m-d');
        $stmt = $this->db->prepare('SELECT MAX(perioada) FROM payroll_monthly WHERE fiscal_rule_id = :id AND confirmation_status = "confirmat"');
        $stmt->execute([':id' => $ruleId]);
        $lastConfirmed = $stmt->fetchColumn();
        if ($lastConfirmed && (new DateTimeImmutable((string) $lastConfirmed))->format('Y-m-t') > $closeAt) {
            throw new InvalidArgumentException('Există state confirmate cu regula veche după ' . $closeAt . '. Alegeți o dată de început ulterioară.');
        }

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare('UPDATE payroll_fiscal_rules SET valid_to = :valid_to, updated_by = :user, updated_at = :now WHERE id = :id')
                ->execute([':valid_to' => $closeAt, ':user' => $userId, ':now' => date('Y-m-d H:i:s'), ':id' => $ruleId]);
            $this->auditRule($ruleId, 'inchidere', 'valid_to', (string) ($existing['valid_to'] ?? ''), $closeAt, $existing, $reason, $userId);
            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $data['valid_from'] = $newFrom;
        return $this->saveRule(null, $data, $reason . ' (versiune nouă după regula #' . $ruleId . ')', $userId);
    }

    public function getRuleAudit(int $limit = 50): array
    {
        $stmt = $this->db->prepare('
            SELECT a.*, r.name AS rule_name, u.nume AS user_name
            FROM payroll_fiscal_rule_audit a
            JOIN payroll_fiscal_rules r ON r.id = a.rule_id
            LEFT JOIN utilizatori u ON u.id = a.user_id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------
    // Profil de salarizare
    // ------------------------------------------------------------------

    /** @return array<string, array> cheie "driver-12" */
    public function getProfilesForSubjects(array $pairs): array
    {
        return $this->fetchBySubjects('SELECT * FROM payroll_profiles WHERE ', $pairs, null);
    }

    public function saveProfile(string $sourceType, int $sourceId, array $data, ?int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        $columns = self::PROFILE_FIELDS;
        $updates = array_map(static fn (string $c): string => $c . ' = VALUES(' . $c . ')', $columns);
        $stmt = $this->db->prepare('
            INSERT INTO payroll_profiles (subject_type, driver_id, staff_member_id, subject_id, ' . implode(', ', $columns) . ', updated_by, created_at, updated_at)
            VALUES (:subject_type, :driver_id, :staff_member_id, :subject_id, :' . implode(', :', $columns) . ', :updated_by, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE ' . implode(', ', $updates) . ', updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)
        ');
        $params = [
            ':subject_type' => $sourceType,
            ':driver_id' => $sourceType === 'driver' ? $sourceId : null,
            ':staff_member_id' => $sourceType === 'staff' ? $sourceId : null,
            ':subject_id' => $sourceId,
            ':updated_by' => $userId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ];
        foreach ($columns as $column) {
            $params[':' . $column] = $data[$column] ?? null;
        }
        $stmt->execute($params);
    }

    // ------------------------------------------------------------------
    // Tipuri de sporuri / retineri / beneficii
    // ------------------------------------------------------------------

    public function listItemTypes(?string $category = null, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM payroll_item_types WHERE 1 = 1';
        $params = [];
        if ($category !== null) {
            $sql .= ' AND category = :category';
            $params[':category'] = $category;
        }
        if ($onlyActive) {
            $sql .= ' AND active = 1';
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY category, active DESC, name');
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findItemType(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM payroll_item_types WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function saveItemType(?int $id, array $data, ?int $userId): int
    {
        $fields = ['category', 'name', 'calculation_type', 'default_value', 'paid_in_cash', 'subject_to_cas', 'subject_to_cass',
            'subject_to_income_tax', 'subject_to_cam', 'excluded_from_facility_ceiling', 'valid_from', 'valid_to', 'active', 'legal_reference', 'notes'];
        $params = [];
        foreach ($fields as $field) {
            $params[':' . $field] = $data[$field] ?? null;
        }
        $params[':updated_at'] = date('Y-m-d H:i:s');

        if ($id === null) {
            $stmt = $this->db->prepare('
                INSERT INTO payroll_item_types (' . implode(', ', $fields) . ', created_by, created_at, updated_at)
                VALUES (:' . implode(', :', $fields) . ', :created_by, :created_at, :updated_at)
            ');
            $stmt->execute($params + [':created_by' => $userId, ':created_at' => $params[':updated_at']]);
            return (int) $this->db->lastInsertId();
        }

        $sets = array_map(static fn (string $f): string => $f . ' = :' . $f, $fields);
        $this->db->prepare('UPDATE payroll_item_types SET ' . implode(', ', $sets) . ', updated_at = :updated_at WHERE id = :id')
            ->execute($params + [':id' => $id]);

        return $id;
    }

    // ------------------------------------------------------------------
    // Elementele lunare ale angajatului
    // ------------------------------------------------------------------

    /** @return array<string, array<int, array>> cheie "driver-12" */
    public function getItemsForSubjects(array $pairs, string $period): array
    {
        return $this->fetchBySubjects('
            SELECT i.*, t.category, t.name, t.calculation_type, t.default_value, t.paid_in_cash, t.subject_to_cas,
                   t.subject_to_cass, t.subject_to_income_tax, t.subject_to_cam, t.excluded_from_facility_ceiling,
                   u.nume AS created_by_name
            FROM payroll_employee_items i
            JOIN payroll_item_types t ON t.id = i.item_type_id
            LEFT JOIN utilizatori u ON u.id = i.created_by
            WHERE i.perioada = ? AND ', $pairs, $period, true, 'i.');
    }

    public function addEmployeeItem(string $sourceType, int $sourceId, string $period, int $typeId, ?float $amount, ?string $reason, ?string $notes, ?int $userId): void
    {
        $this->assertNotConfirmed($sourceType, $sourceId, $period);
        $this->db->prepare('
            INSERT INTO payroll_employee_items (subject_type, subject_id, perioada, item_type_id, amount, reason, notes, created_by, created_at)
            VALUES (:subject_type, :subject_id, :perioada, :item_type_id, :amount, :reason, :notes, :created_by, :created_at)
        ')->execute([
            ':subject_type' => $sourceType, ':subject_id' => $sourceId, ':perioada' => $period, ':item_type_id' => $typeId,
            ':amount' => $amount !== null ? (string) $amount : null, ':reason' => $reason, ':notes' => $notes,
            ':created_by' => $userId, ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function deleteEmployeeItem(int $itemId, string $sourceType, int $sourceId): bool
    {
        $stmt = $this->db->prepare('SELECT perioada FROM payroll_employee_items WHERE id = :id AND subject_type = :t AND subject_id = :s');
        $stmt->execute([':id' => $itemId, ':t' => $sourceType, ':s' => $sourceId]);
        $period = $stmt->fetchColumn();
        if ($period === false) {
            return false;
        }
        $this->assertNotConfirmed($sourceType, $sourceId, (string) $period);
        $this->db->prepare('DELETE FROM payroll_employee_items WHERE id = :id')->execute([':id' => $itemId]);

        return true;
    }

    // ------------------------------------------------------------------
    // Statul lunar
    // ------------------------------------------------------------------

    /** @return array<string, array> cheie "driver-12" */
    public function getPayrollForSubjects(array $pairs, string $period): array
    {
        return $this->fetchBySubjects('
            SELECT pm.*, r.name AS fiscal_rule_name, uc.nume AS calculated_by_name, uf.nume AS confirmed_by_name
            FROM payroll_monthly pm
            LEFT JOIN payroll_fiscal_rules r ON r.id = pm.fiscal_rule_id
            LEFT JOIN utilizatori uc ON uc.id = pm.calculated_by
            LEFT JOIN utilizatori uf ON uf.id = pm.confirmed_by
            WHERE pm.perioada = ? AND ', $pairs, $period, false, 'pm.');
    }

    public function findPayroll(string $sourceType, int $sourceId, string $period): ?array
    {
        return $this->getPayrollForSubjects([$sourceType => [$sourceId]], $period)[$sourceType . '-' . $sourceId] ?? null;
    }

    public function isConfirmed(string $sourceType, int $sourceId, string $period): bool
    {
        $row = $this->findPayroll($sourceType, $sourceId, $period);

        return $row !== null && $row['confirmation_status'] === 'confirmat';
    }

    /**
     * Salveaza rezultatul calculului. Un stat confirmat nu se suprascrie niciodata
     * (se intoarce false); dupa redeschidere, recalcularea se scrie in jurnal.
     */
    public function savePayrollResult(string $sourceType, int $sourceId, string $period, array $result, string $fingerprint, ?int $userId): bool
    {
        $existing = $this->findPayroll($sourceType, $sourceId, $period);
        if ($existing !== null && $existing['confirmation_status'] === 'confirmat') {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $params = [
            ':subject_type' => $sourceType,
            ':driver_id' => $sourceType === 'driver' ? $sourceId : null,
            ':staff_member_id' => $sourceType === 'staff' ? $sourceId : null,
            ':subject_id' => $sourceId,
            ':perioada' => $period,
            ':fiscal_rule_id' => $result['fiscal_rule_id'] ?? null,
            ':calculation_status' => $result['status'],
            ':missing_fields' => json_encode($result['missing'] ?? [], JSON_UNESCAPED_UNICODE),
            ':warnings' => json_encode($result['warnings'] ?? [], JSON_UNESCAPED_UNICODE),
            ':calculation_details' => json_encode($result['details'] ?? null, JSON_UNESCAPED_UNICODE),
            ':input_fingerprint' => $fingerprint,
            ':calculated_at' => $now,
            ':calculated_by' => $userId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ];
        foreach (self::RESULT_FIELDS as $field) {
            $value = $result[$field] ?? null;
            $params[':' . $field] = is_bool($value) ? (int) $value : ($value !== null ? (string) $value : null);
        }

        $columns = array_merge(self::RESULT_FIELDS, ['fiscal_rule_id', 'calculation_status', 'missing_fields', 'warnings', 'calculation_details', 'input_fingerprint', 'calculated_at', 'calculated_by']);
        $updates = array_map(static fn (string $c): string => $c . ' = VALUES(' . $c . ')', $columns);
        $this->db->prepare('
            INSERT INTO payroll_monthly (subject_type, driver_id, staff_member_id, subject_id, perioada, ' . implode(', ', $columns) . ', confirmation_status, created_at, updated_at)
            VALUES (:subject_type, :driver_id, :staff_member_id, :subject_id, :perioada, :' . implode(', :', $columns) . ', "neconfirmat", :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE ' . implode(', ', $updates) . ', confirmation_status = "neconfirmat", updated_at = VALUES(updated_at)
        ')->execute($params);

        $saved = $this->findPayroll($sourceType, $sourceId, $period);
        if ($saved !== null && $existing !== null && $this->resultSummary($existing) !== $this->resultSummary($saved)) {
            $this->auditPayroll((int) $saved['id'], 'recalculare', null, $this->resultSummary($existing), $this->resultSummary($saved), $userId);
        } elseif ($saved !== null && $existing === null) {
            $this->auditPayroll((int) $saved['id'], 'calcul', null, null, $this->resultSummary($saved), $userId);
        }

        return true;
    }

    public function confirmPayroll(string $sourceType, int $sourceId, string $period, ?int $userId): void
    {
        $row = $this->findPayroll($sourceType, $sourceId, $period);
        if ($row === null) {
            throw new InvalidArgumentException('Calculul lunii nu există. Calculați mai întâi.');
        }
        if ($row['confirmation_status'] === 'confirmat') {
            throw new InvalidArgumentException('Calculul este deja confirmat.');
        }
        if ($row['confirmation_status'] === 'necesita_recalculare') {
            throw new InvalidArgumentException('Datele s-au schimbat după calcul. Recalculați înainte de confirmare.');
        }
        if (!in_array($row['calculation_status'], ['calculat', 'de_verificat'], true)) {
            throw new InvalidArgumentException('Doar un calcul reușit poate fi confirmat (status: ' . $row['calculation_status'] . ').');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->prepare('UPDATE payroll_monthly SET confirmation_status = "confirmat", confirmed_at = :now, confirmed_by = :user, updated_at = :now2 WHERE id = :id AND confirmation_status <> "confirmat"')
            ->execute([':now' => $now, ':user' => $userId, ':now2' => $now, ':id' => (int) $row['id']]);
        $this->auditPayroll((int) $row['id'], 'confirmare', null, null, $this->resultSummary($row), $userId);
    }

    /** Redeschiderea unui stat confirmat: motiv obligatoriu, rezultatul vechi ramane in jurnal. */
    public function reopenPayroll(string $sourceType, int $sourceId, string $period, string $reason, ?int $userId): void
    {
        $row = $this->findPayroll($sourceType, $sourceId, $period);
        if ($row === null || $row['confirmation_status'] !== 'confirmat') {
            throw new InvalidArgumentException('Calculul nu este confirmat.');
        }
        $this->db->prepare('UPDATE payroll_monthly SET confirmation_status = "necesita_recalculare", confirmed_at = NULL, confirmed_by = NULL, updated_at = :now WHERE id = :id')
            ->execute([':now' => date('Y-m-d H:i:s'), ':id' => (int) $row['id']]);
        $this->auditPayroll((int) $row['id'], 'redeschidere', $reason, $this->resultSummary($row), null, $userId);
    }

    public function getPayrollAudit(int $payrollId): array
    {
        $stmt = $this->db->prepare('
            SELECT a.*, u.nume AS user_name FROM payroll_monthly_audit a
            LEFT JOIN utilizatori u ON u.id = a.user_id
            WHERE a.payroll_id = :id ORDER BY a.created_at DESC, a.id DESC
        ');
        $stmt->execute([':id' => $payrollId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Costul salarial real al lunii = SUM(total_employer_cost) din statele calculate.
     * Sursa pentru KPI si, mai departe, pentru Dashboard Analitic (nu salariile curente).
     */
    public function getMonthTotals(string $period): array
    {
        $stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN calculation_status IN ('calculat','de_verificat') THEN 1 ELSE 0 END) AS calculated_count,
                SUM(CASE WHEN calculation_status IN ('calculat','de_verificat') AND confirmation_status = 'confirmat' THEN 1 ELSE 0 END) AS confirmed_count,
                SUM(CASE WHEN calculation_status IN ('calculat','de_verificat') THEN total_employer_cost ELSE 0 END) AS total_cost,
                SUM(CASE WHEN confirmation_status = 'confirmat' THEN total_employer_cost ELSE 0 END) AS confirmed_cost,
                SUM(CASE WHEN calculation_status NOT IN ('calculat','de_verificat') THEN 1 ELSE 0 END) AS problem_count
            FROM payroll_monthly WHERE perioada = :perioada
        ");
        $stmt->execute([':perioada' => $period]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'calculated_count' => (int) ($row['calculated_count'] ?? 0),
            'confirmed_count' => (int) ($row['confirmed_count'] ?? 0),
            'problem_count' => (int) ($row['problem_count'] ?? 0),
            'total_cost' => ($row['calculated_count'] ?? 0) > 0 ? (float) $row['total_cost'] : null,
            'confirmed_cost' => (float) ($row['confirmed_cost'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    private function assertNotConfirmed(string $sourceType, int $sourceId, string $period): void
    {
        if ($this->isConfirmed($sourceType, $sourceId, $period)) {
            throw new InvalidArgumentException('Calculul lunii este confirmat. Redeschideți-l înainte de modificări.');
        }
    }

    /**
     * Citire pe lot pentru mai multi angajati: $pairs = ['driver' => [ids], 'staff' => [ids]].
     */
    private function fetchBySubjects(string $sqlPrefix, array $pairs, ?string $period, bool $multiple = false, string $alias = ''): array
    {
        $conditions = [];
        $params = $period !== null ? [$period] : [];
        foreach (['driver', 'staff'] as $type) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $pairs[$type] ?? []), static fn (int $id): bool => $id > 0)));
            if ($ids === []) {
                continue;
            }
            $conditions[] = '(' . $alias . 'subject_type = ? AND ' . $alias . 'subject_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
            $params[] = $type;
            array_push($params, ...$ids);
        }
        if ($conditions === []) {
            return [];
        }

        $stmt = $this->db->prepare($sqlPrefix . '(' . implode(' OR ', $conditions) . ')');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['subject_type'] . '-' . (int) $row['subject_id'];
            if ($multiple) {
                $out[$key][] = $row;
            } else {
                $out[$key] = $row;
            }
        }

        return $out;
    }

    private function decodeRule(array $row): array
    {
        $row['personal_deduction_config'] = is_string($row['personal_deduction_config'] ?? null)
            ? json_decode($row['personal_deduction_config'], true)
            : ($row['personal_deduction_config'] ?? null);

        return $row;
    }

    private function ruleParams(array $data): array
    {
        $params = [];
        foreach (self::RULE_FIELDS as $field) {
            $value = $data[$field] ?? null;
            if ($field === 'personal_deduction_config') {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $params[':' . $field] = $value === null ? null : (string) $value;
        }

        return $params;
    }

    private function ruleForAudit(array $data): array
    {
        $out = [];
        foreach (self::RULE_FIELDS as $field) {
            $value = $data[$field] ?? null;
            if ($field === 'personal_deduction_config') {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (is_numeric($value)) {
                $value = rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
            }
            $out[$field] = $value === null ? '' : (string) $value;
        }

        return $out;
    }

    private function assertRuleData(array $data): void
    {
        foreach (['name', 'valid_from', 'cas_employee_rate', 'cass_employee_rate', 'income_tax_rate', 'cam_employer_rate', 'minimum_gross_salary', 'monthly_hours_norm', 'minimum_hourly_salary', 'rounding_mode'] as $required) {
            if (($data[$required] ?? null) === null || $data[$required] === '') {
                throw new InvalidArgumentException('Câmpul „' . $required . '” este obligatoriu.');
            }
        }
        if ($data['valid_to'] !== null && $data['valid_to'] < $data['valid_from']) {
            throw new InvalidArgumentException('„Până la” nu poate fi înainte de „De la”.');
        }
        if (!is_array($data['personal_deduction_config'] ?? null)) {
            throw new InvalidArgumentException('Configurația deducerii personale este invalidă.');
        }
    }

    private function assertNoOverlap(string $from, ?string $to, string $status, ?int $excludeId): void
    {
        if ($status !== 'activ') {
            return;
        }
        $stmt = $this->db->prepare('
            SELECT name FROM payroll_fiscal_rules
            WHERE status = "activ" AND id <> :exclude
              AND valid_from <= :to_date
              AND (valid_to IS NULL OR valid_to >= :from_date)
            LIMIT 1
        ');
        $stmt->execute([':exclude' => $excludeId ?? 0, ':to_date' => $to ?? '9999-12-31', ':from_date' => $from]);
        $clash = $stmt->fetchColumn();
        if ($clash !== false) {
            throw new InvalidArgumentException('Perioada se suprapune cu regula activă „' . $clash . '”.');
        }
    }

    private function auditRule(int $ruleId, string $action, ?string $field, ?string $old, ?string $new, array $data, string $reason, ?int $userId): void
    {
        $this->db->prepare('
            INSERT INTO payroll_fiscal_rule_audit (rule_id, action, field, old_value, new_value, valid_from, valid_to, reason, user_id, created_at)
            VALUES (:rule_id, :action, :field, :old_value, :new_value, :valid_from, :valid_to, :reason, :user_id, :created_at)
        ')->execute([
            ':rule_id' => $ruleId, ':action' => $action, ':field' => $field, ':old_value' => $old, ':new_value' => $new,
            ':valid_from' => $data['valid_from'] ?? null, ':valid_to' => $data['valid_to'] ?? null,
            ':reason' => $reason !== '' ? $reason : null, ':user_id' => $userId, ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function auditPayroll(int $payrollId, string $action, ?string $reason, ?array $old, ?array $new, ?int $userId): void
    {
        $this->db->prepare('
            INSERT INTO payroll_monthly_audit (payroll_id, action, reason, old_result, new_result, user_id, created_at)
            VALUES (:payroll_id, :action, :reason, :old_result, :new_result, :user_id, :created_at)
        ')->execute([
            ':payroll_id' => $payrollId, ':action' => $action, ':reason' => $reason,
            ':old_result' => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            ':new_result' => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            ':user_id' => $userId, ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function resultSummary(array $row): array
    {
        $out = ['status' => $row['calculation_status'] ?? null, 'fiscal_rule_id' => $row['fiscal_rule_id'] ?? null];
        foreach (['configured_salary', 'gross_salary', 'cas', 'cass', 'income_tax', 'cam', 'non_taxable_amount', 'net_salary', 'total_employer_cost'] as $field) {
            $out[$field] = $row[$field] ?? null;
        }

        return $out;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $exists = (int) $this->db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_monthly_audit'")->fetchColumn();
        if ($exists === 0) {
            $file = dirname(BASE_PATH) . '/database/migrations/2026_09_23_000002_payroll_salarizare.sql';
            if (!is_file($file)) {
                throw new RuntimeException('Rulați migrarea 2026_09_23_000002_payroll_salarizare.sql.');
            }
            $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
            foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
                if (trim($statement) !== '') {
                    $this->db->exec($statement);
                }
            }
        }
        $hasSettings = (int) $this->db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_settings'")->fetchColumn();
        if ($hasSettings === 0) {
            // Migrarea oficiala: 2026_09_24_000001_payroll_settings.sql (implicit: dezactivat).
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS payroll_settings (
                    setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
                    setting_value VARCHAR(255) NOT NULL,
                    updated_by INT UNSIGNED NULL,
                    updated_at DATETIME NOT NULL,
                    CONSTRAINT fk_payroll_settings_user FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $this->db->exec("INSERT IGNORE INTO payroll_settings (setting_key, setting_value, updated_at) VALUES ('fiscal_calculation_enabled', '0', NOW())");
        }
        self::$schemaReady = true;
    }
}
