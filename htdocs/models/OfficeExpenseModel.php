<?php
declare(strict_types=1);

class OfficeExpenseModel extends BaseModel
{
    public const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'card' => 'Card',
        'transfer_bancar' => 'Transfer bancar',
        'alte' => 'Alte metode',
    ];

    public const DOCUMENT_TYPES = [
        'factura' => 'Factură',
        'bon_fiscal' => 'Bon fiscal',
        'chitanta' => 'Chitanță',
        'contract' => 'Contract',
        'alt_document' => 'Alt document',
    ];

    public const RENT_PAYMENT_STATUSES = [
        'platit' => 'Plătit',
        'neplatit' => 'Neplătit',
        'intarziat' => 'Întârziat',
    ];

    public function getDashboardData(): array
    {
        $today = new DateTimeImmutable('today');
        $currentMonthStart = $today->modify('first day of this month');
        $currentMonthEnd = $today->modify('last day of this month');
        $yearStart = $today->setDate((int) $today->format('Y'), 1, 1);

        $monthlyManual = $this->sumManualExpenses($currentMonthStart, $currentMonthEnd);
        $monthlySalaries = $this->getOfficeSalaryForMonth($currentMonthStart);
        $yearManual = $this->sumManualExpenses($yearStart, $today);
        $yearSalaries = $this->getOfficeSalaryForYearToDate($today);

        return [
            'kpis' => [
                'total_lunar' => $monthlyManual + $monthlySalaries,
                'total_an_curent' => $yearManual + $yearSalaries,
                'numar_cheltuieli' => $this->countManualExpenses($yearStart, $today),
                'ultima_cheltuiala' => $this->getLatestExpense(),
            ],
            'category_totals' => $this->getCategoryTotalsForYear($today, $yearSalaries),
            'monthly_evolution' => $this->getMonthlyEvolution($today),
            'type_totals' => $this->getTypeTotalsForYear($today, $yearSalaries),
        ];
    }

    public function getCategories(bool $includeAutomatic = true, bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM office_expense_categories';
        $conditions = [];

        if (!$includeAutomatic) {
            $conditions[] = 'is_automatic = 0';
        }
        if ($onlyActive) {
            $conditions[] = "status = 'activ'";
        }
        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';

        return $this->db->query($sql)->fetchAll();
    }

    public function findCategory(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM office_expense_categories WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function getPaginatedExpenses(array $filters, string $sort, string $direction, int $page, int $perPage): array
    {
        [$whereSql, $params] = $this->buildExpenseWhere($filters);
        $orderBy = $this->resolveOrderBy($sort, $direction);

        $countStmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM office_expenses e
            INNER JOIN office_expense_categories c ON c.id = e.category_id
            LEFT JOIN utilizatori u ON u.id = e.added_by
            ' . $whereSql
        );
        $this->bindParams($countStmt, $params);
        $countStmt->execute();

        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare('
            SELECT
                e.*,
                c.name AS category_name,
                c.slug AS category_slug,
                c.color AS category_color,
                c.expense_scope,
                u.nume AS added_by_name,
                (SELECT COUNT(*) FROM office_expense_documents d WHERE d.expense_id = e.id) AS document_count,
                (SELECT d.id FROM office_expense_documents d WHERE d.expense_id = e.id ORDER BY d.id DESC LIMIT 1) AS document_id,
                (SELECT d.document_type FROM office_expense_documents d WHERE d.expense_id = e.id ORDER BY d.id DESC LIMIT 1) AS document_type,
                (SELECT d.original_name FROM office_expense_documents d WHERE d.expense_id = e.id ORDER BY d.id DESC LIMIT 1) AS document_original_name,
                (SELECT d.stored_name FROM office_expense_documents d WHERE d.expense_id = e.id ORDER BY d.id DESC LIMIT 1) AS document_stored_name
            FROM office_expenses e
            INNER JOIN office_expense_categories c ON c.id = e.category_id
            LEFT JOIN utilizatori u ON u.id = e.added_by
            ' . $whereSql . '
            ORDER BY ' . $orderBy . '
            LIMIT :limit_rows OFFSET :offset_rows
        ');
        $this->bindParams($stmt, $params);
        $stmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(),
            'page' => $page,
            'total_pages' => $totalPages,
            'total_rows' => $totalRows,
            'per_page' => $perPage,
        ];
    }

    public function getExpensesForExport(array $filters, string $sort, string $direction): array
    {
        [$whereSql, $params] = $this->buildExpenseWhere($filters);

        $stmt = $this->db->prepare('
            SELECT
                e.*,
                c.name AS category_name,
                c.slug AS category_slug,
                u.nume AS added_by_name,
                (SELECT COUNT(*) FROM office_expense_documents d WHERE d.expense_id = e.id) AS document_count
            FROM office_expenses e
            INNER JOIN office_expense_categories c ON c.id = e.category_id
            LEFT JOIN utilizatori u ON u.id = e.added_by
            ' . $whereSql . '
            ORDER BY ' . $this->resolveOrderBy($sort, $direction)
        );
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function createExpense(array $data, ?array $documentData, ?int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('
                INSERT INTO office_expenses (
                    category_id, expense_date, description, supplier, amount_net, vat_amount, amount_total,
                    payment_method, invoice_number, notes, monthly_rent_amount, contract_number,
                    rent_period_start, rent_period_end, due_date, payment_status, landlord_name,
                    added_by, updated_by, created_at, updated_at
                ) VALUES (
                    :category_id, :expense_date, :description, :supplier, :amount_net, :vat_amount, :amount_total,
                    :payment_method, :invoice_number, :notes, :monthly_rent_amount, :contract_number,
                    :rent_period_start, :rent_period_end, :due_date, :payment_status, :landlord_name,
                    :added_by, :updated_by, :created_at, :updated_at
                )
            ');
            $this->bindExpenseStatement($stmt, $data, $userId, $now);
            $stmt->execute();
            $expenseId = (int) $this->db->lastInsertId();

            if ($documentData !== null) {
                $this->insertDocument($expenseId, $documentData, $userId, $now);
            }

            $this->db->commit();
            return $expenseId;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function updateExpense(int $id, array $data, ?array $documentData, ?int $userId): bool
    {
        if ($id <= 0 || $this->findExpense($id) === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('
                UPDATE office_expenses
                SET category_id = :category_id,
                    expense_date = :expense_date,
                    description = :description,
                    supplier = :supplier,
                    amount_net = :amount_net,
                    vat_amount = :vat_amount,
                    amount_total = :amount_total,
                    payment_method = :payment_method,
                    invoice_number = :invoice_number,
                    notes = :notes,
                    monthly_rent_amount = :monthly_rent_amount,
                    contract_number = :contract_number,
                    rent_period_start = :rent_period_start,
                    rent_period_end = :rent_period_end,
                    due_date = :due_date,
                    payment_status = :payment_status,
                    landlord_name = :landlord_name,
                    updated_by = :updated_by,
                    updated_at = :updated_at
                WHERE id = :id
            ');
            $this->bindExpenseStatement($stmt, $data, $userId, $now, false);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            if ($documentData !== null) {
                $this->insertDocument($id, $documentData, $userId, $now);
            }

            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function deleteExpense(int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        $documents = $this->getDocumentsForExpense($id);
        $stmt = $this->db->prepare('DELETE FROM office_expenses WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $documents;
    }

    public function findExpense(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT e.*, c.name AS category_name, c.slug AS category_slug, c.is_automatic
            FROM office_expenses e
            INNER JOIN office_expense_categories c ON c.id = e.category_id
            WHERE e.id = :id
            LIMIT 1
        ');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function getDocumentsForExpense(int $expenseId): array
    {
        if ($expenseId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare('
            SELECT *
            FROM office_expense_documents
            WHERE expense_id = :expense_id
            ORDER BY id DESC
        ');
        $stmt->bindValue(':expense_id', $expenseId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getDocumentsForRows(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare('
            SELECT *
            FROM office_expense_documents
            WHERE expense_id IN (' . $placeholders . ')
            ORDER BY expense_id ASC, id DESC
        ');
        foreach ($ids as $index => $id) {
            $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $map = [];
        foreach ($stmt->fetchAll() as $document) {
            $expenseId = (int) ($document['expense_id'] ?? 0);
            if (!isset($map[$expenseId])) {
                $map[$expenseId] = [];
            }
            $map[$expenseId][] = $document;
        }

        return $map;
    }

    public function findDocument(int $documentId): ?array
    {
        if ($documentId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM office_expense_documents WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $documentId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function getCategoryTotalsForYear(DateTimeImmutable $today, float $officeSalaries): array
    {
        $yearStart = $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d');
        $yearEnd = $today->format('Y-m-d');

        $stmt = $this->db->prepare('
            SELECT
                c.id,
                c.name,
                c.slug,
                c.color,
                c.is_automatic,
                COALESCE(SUM(e.amount_total), 0) AS total
            FROM office_expense_categories c
            LEFT JOIN office_expenses e
                ON e.category_id = c.id
               AND e.expense_date BETWEEN :start_date AND :end_date
            WHERE c.status = "activ"
            GROUP BY c.id
            ORDER BY c.sort_order ASC, c.name ASC
        ');
        $stmt->execute([
            ':start_date' => $yearStart,
            ':end_date' => $yearEnd,
        ]);

        $rows = [];
        $hasSalaryRow = false;
        foreach ($stmt->fetchAll() as $row) {
            if ((string) ($row['slug'] ?? '') === 'salarii-birou') {
                $row['total'] = $officeSalaries;
                $hasSalaryRow = true;
            }
            $rows[] = $row;
        }

        if (!$hasSalaryRow && $officeSalaries > 0) {
            $rows[] = [
                'id' => 0,
                'name' => 'Salarii birou',
                'slug' => 'salarii-birou',
                'color' => '#fbbf24',
                'is_automatic' => 1,
                'total' => $officeSalaries,
            ];
        }

        return $rows;
    }

    private function getMonthlyEvolution(DateTimeImmutable $today): array
    {
        $months = [];
        $firstMonth = $today->modify('first day of this month')->modify('-5 months');

        for ($i = 0; $i < 6; $i++) {
            $monthStart = $firstMonth->modify('+' . $i . ' months');
            $monthEnd = $monthStart->modify('last day of this month');
            $manual = $this->sumManualExpenses($monthStart, $monthEnd);
            $salary = $this->getOfficeSalaryForMonth($monthStart);

            $months[] = [
                'label' => $this->monthLabel($monthStart),
                'month' => $monthStart->format('Y-m'),
                'manual_total' => $manual,
                'salary_total' => $salary,
                'total' => $manual + $salary,
            ];
        }

        return $months;
    }

    private function getTypeTotalsForYear(DateTimeImmutable $today, float $officeSalaries): array
    {
        $yearStart = $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d');
        $yearEnd = $today->format('Y-m-d');

        $stmt = $this->db->prepare('
            SELECT c.expense_scope, COALESCE(SUM(e.amount_total), 0) AS total
            FROM office_expenses e
            INNER JOIN office_expense_categories c ON c.id = e.category_id
            WHERE e.expense_date BETWEEN :start_date AND :end_date
            GROUP BY c.expense_scope
        ');
        $stmt->execute([
            ':start_date' => $yearStart,
            ':end_date' => $yearEnd,
        ]);

        $totals = [
            'administrative' => $officeSalaries,
            'operational' => 0.0,
        ];

        foreach ($stmt->fetchAll() as $row) {
            $scope = (string) ($row['expense_scope'] ?? 'administrative');
            if (!isset($totals[$scope])) {
                $scope = 'administrative';
            }
            $totals[$scope] += (float) ($row['total'] ?? 0);
        }

        $grandTotal = array_sum($totals);

        return [
            'administrative' => [
                'label' => 'Administrative (Birou)',
                'total' => $totals['administrative'],
                'percent' => $grandTotal > 0 ? ($totals['administrative'] / $grandTotal) * 100 : 0,
            ],
            'operational' => [
                'label' => 'Operaționale (Flotă)',
                'total' => $totals['operational'],
                'percent' => $grandTotal > 0 ? ($totals['operational'] / $grandTotal) * 100 : 0,
            ],
            'grand_total' => $grandTotal,
        ];
    }

    private function sumManualExpenses(DateTimeImmutable $startDate, DateTimeImmutable $endDate): float
    {
        $stmt = $this->db->prepare('
            SELECT COALESCE(SUM(e.amount_total), 0)
            FROM office_expenses e
            INNER JOIN office_expense_categories c ON c.id = e.category_id
            WHERE c.is_automatic = 0
              AND e.expense_date BETWEEN :start_date AND :end_date
        ');
        $stmt->execute([
            ':start_date' => $startDate->format('Y-m-d'),
            ':end_date' => $endDate->format('Y-m-d'),
        ]);

        return (float) $stmt->fetchColumn();
    }

    private function countManualExpenses(DateTimeImmutable $startDate, DateTimeImmutable $endDate): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM office_expenses e
            INNER JOIN office_expense_categories c ON c.id = e.category_id
            WHERE c.is_automatic = 0
              AND e.expense_date BETWEEN :start_date AND :end_date
        ');
        $stmt->execute([
            ':start_date' => $startDate->format('Y-m-d'),
            ':end_date' => $endDate->format('Y-m-d'),
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function getLatestExpense(): ?array
    {
        $stmt = $this->db->query('
            SELECT e.*, c.name AS category_name
            FROM office_expenses e
            INNER JOIN office_expense_categories c ON c.id = e.category_id
            ORDER BY e.created_at DESC, e.id DESC
            LIMIT 1
        ');
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function getOfficeSalaryForMonth(DateTimeImmutable $monthStart): float
    {
        $monthEnd = $monthStart->modify('last day of this month')->format('Y-m-d');

        $stmt = $this->db->prepare('
            SELECT COALESCE(SUM(COALESCE((
                SELECT sh.current_salary
                FROM salary_history sh
                WHERE sh.subject_type = "staff"
                  AND sh.staff_member_id = sm.id
                  AND sh.effective_date <= :month_end_history
                ORDER BY sh.effective_date DESC, sh.id DESC
                LIMIT 1
            ), sm.salariu, 0)), 0) AS total
            FROM staff_members sm
            INNER JOIN staff_types st ON st.id = sm.staff_type_id
            WHERE st.category = "office"
              AND sm.status = "activ"
              AND (sm.data_angajare IS NULL OR sm.data_angajare <= :month_end_hire)
        ');
        $stmt->execute([
            ':month_end_history' => $monthEnd,
            ':month_end_hire' => $monthEnd,
        ]);

        return (float) $stmt->fetchColumn();
    }

    private function getOfficeSalaryForYearToDate(DateTimeImmutable $today): float
    {
        $year = (int) $today->format('Y');
        $currentMonth = (int) $today->format('n');
        $total = 0.0;

        for ($month = 1; $month <= $currentMonth; $month++) {
            $total += $this->getOfficeSalaryForMonth((new DateTimeImmutable())->setDate($year, $month, 1));
        }

        return $total;
    }

    private function buildExpenseWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        $dateStart = trim((string) ($filters['date_start'] ?? ''));
        if ($dateStart !== '') {
            $conditions[] = 'e.expense_date >= :date_start';
            $params[':date_start'] = $dateStart;
        }

        $dateEnd = trim((string) ($filters['date_end'] ?? ''));
        if ($dateEnd !== '') {
            $conditions[] = 'e.expense_date <= :date_end';
            $params[':date_end'] = $dateEnd;
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $conditions[] = 'e.category_id = :category_id';
            $params[':category_id'] = $categoryId;
        }

        $paymentMethod = trim((string) ($filters['payment_method'] ?? ''));
        if (array_key_exists($paymentMethod, self::PAYMENT_METHODS)) {
            $conditions[] = 'e.payment_method = :payment_method';
            $params[':payment_method'] = $paymentMethod;
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(
                e.description LIKE :search_description
                OR COALESCE(e.supplier, "") LIKE :search_supplier
                OR COALESCE(e.invoice_number, "") LIKE :search_invoice
                OR c.name LIKE :search_category
            )';
            $params[':search_description'] = '%' . $search . '%';
            $params[':search_supplier'] = '%' . $search . '%';
            $params[':search_invoice'] = '%' . $search . '%';
            $params[':search_category'] = '%' . $search . '%';
        }

        return [
            $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions),
            $params,
        ];
    }

    private function resolveOrderBy(string $sort, string $direction): string
    {
        $columns = [
            'data' => 'e.expense_date',
            'categorie' => 'c.name',
            'descriere' => 'e.description',
            'furnizor' => 'e.supplier',
            'suma' => 'e.amount_total',
            'metoda' => 'e.payment_method',
            'adaugat_de' => 'u.nume',
            'created_at' => 'e.created_at',
        ];

        $column = $columns[$sort] ?? 'e.expense_date';
        $dir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        return $column . ' ' . $dir . ', e.id DESC';
    }

    private function bindExpenseStatement(PDOStatement $stmt, array $data, ?int $userId, string $now, bool $includeCreated = true): void
    {
        $params = [
            ':category_id' => (int) ($data['category_id'] ?? 0),
            ':expense_date' => (string) ($data['expense_date'] ?? date('Y-m-d')),
            ':description' => trim((string) ($data['description'] ?? '')),
            ':supplier' => $this->nullableString($data['supplier'] ?? null),
            ':amount_net' => (float) ($data['amount_net'] ?? 0),
            ':vat_amount' => (float) ($data['vat_amount'] ?? 0),
            ':amount_total' => (float) ($data['amount_total'] ?? 0),
            ':payment_method' => (string) ($data['payment_method'] ?? 'transfer_bancar'),
            ':invoice_number' => $this->nullableString($data['invoice_number'] ?? null),
            ':notes' => $this->nullableString($data['notes'] ?? null),
            ':monthly_rent_amount' => $data['monthly_rent_amount'] ?? null,
            ':contract_number' => $this->nullableString($data['contract_number'] ?? null),
            ':rent_period_start' => $this->nullableString($data['rent_period_start'] ?? null),
            ':rent_period_end' => $this->nullableString($data['rent_period_end'] ?? null),
            ':due_date' => $this->nullableString($data['due_date'] ?? null),
            ':payment_status' => $this->nullableString($data['payment_status'] ?? null),
            ':landlord_name' => $this->nullableString($data['landlord_name'] ?? null),
            ':updated_by' => $userId,
            ':updated_at' => $now,
        ];

        if ($includeCreated) {
            $params[':added_by'] = $userId;
            $params[':created_at'] = $now;
        }

        $this->bindParams($stmt, $params);
    }

    private function insertDocument(int $expenseId, array $documentData, ?int $userId, string $now): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO office_expense_documents (
                expense_id, document_type, original_name, stored_name, uploaded_by, created_at, updated_at
            ) VALUES (
                :expense_id, :document_type, :original_name, :stored_name, :uploaded_by, :created_at, :updated_at
            )
        ');
        $this->bindParams($stmt, [
            ':expense_id' => $expenseId,
            ':document_type' => (string) ($documentData['document_type'] ?? 'factura'),
            ':original_name' => $this->nullableString($documentData['original_name'] ?? null),
            ':stored_name' => $this->nullableString($documentData['stored_name'] ?? null),
            ':uploaded_by' => $userId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $stmt->execute();
    }

    private function monthLabel(DateTimeImmutable $date): string
    {
        $months = [
            1 => 'Ian',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'Mai',
            6 => 'Iun',
            7 => 'Iul',
            8 => 'Aug',
            9 => 'Sep',
            10 => 'Oct',
            11 => 'Noi',
            12 => 'Dec',
        ];

        return ($months[(int) $date->format('n')] ?? $date->format('M')) . ' ' . $date->format('Y');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } elseif ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
        }
    }
}
