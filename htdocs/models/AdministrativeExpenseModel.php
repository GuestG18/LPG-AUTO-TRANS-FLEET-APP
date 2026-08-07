<?php
declare(strict_types=1);

class AdministrativeExpenseModel extends BaseModel
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

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        try {
            $this->db->query('SELECT 1 FROM administrative_expense_categories LIMIT 1');
            return;
        } catch (Throwable) {
            // Tables missing - create them below.
        }

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS administrative_expense_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(140) NOT NULL,
                status ENUM("activ", "inactiv") NOT NULL DEFAULT "activ",
                color VARCHAR(20) NULL,
                sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_admin_expense_categories_slug (slug),
                INDEX idx_admin_expense_categories_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS administrative_expenses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category_id INT UNSIGNED NOT NULL,
                expense_date DATE NOT NULL,
                description VARCHAR(255) NOT NULL,
                supplier VARCHAR(190) NULL,
                amount_net DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                amount_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                payment_method ENUM("cash", "card", "transfer_bancar", "alte") NOT NULL DEFAULT "transfer_bancar",
                invoice_number VARCHAR(120) NULL,
                notes TEXT NULL,
                added_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_admin_expenses_date (expense_date),
                INDEX idx_admin_expenses_category_date (category_id, expense_date),
                INDEX idx_admin_expenses_payment_method (payment_method),
                INDEX idx_admin_expenses_added_by (added_by),
                CONSTRAINT fk_admin_expenses_category FOREIGN KEY (category_id) REFERENCES administrative_expense_categories(id) ON DELETE RESTRICT,
                CONSTRAINT fk_admin_expenses_added_by FOREIGN KEY (added_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
                CONSTRAINT fk_admin_expenses_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS administrative_expense_documents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                expense_id INT UNSIGNED NOT NULL,
                document_type ENUM("factura", "bon_fiscal", "chitanta", "contract", "alt_document") NOT NULL DEFAULT "factura",
                original_name VARCHAR(255) NULL,
                stored_name VARCHAR(255) NULL,
                uploaded_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_admin_expense_documents_expense (expense_id),
                CONSTRAINT fk_admin_expense_documents_expense FOREIGN KEY (expense_id) REFERENCES administrative_expenses(id) ON DELETE CASCADE,
                CONSTRAINT fk_admin_expense_documents_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->db->exec('
            INSERT INTO administrative_expense_categories
                (name, slug, status, color, sort_order, created_at, updated_at)
            SELECT seed.name, seed.slug, "activ", seed.color, seed.sort_order, NOW(), NOW()
            FROM (
                SELECT "Taxe și impozite" AS name, "taxe-impozite" AS slug, "#2a78d6" AS color, 10 AS sort_order UNION ALL
                SELECT "Asigurări firmă", "asigurari-firma", "#1baf7a", 20 UNION ALL
                SELECT "Contabilitate / Audit", "contabilitate-audit", "#eda100", 30 UNION ALL
                SELECT "Consultanță juridică", "consultanta-juridica", "#008300", 40 UNION ALL
                SELECT "Licențe și autorizații", "licente-autorizatii", "#4a3aa7", 50 UNION ALL
                SELECT "Deplasări / Protocol", "deplasari-protocol", "#e34948", 60 UNION ALL
                SELECT "Marketing / Publicitate", "marketing-publicitate", "#e87ba4", 70 UNION ALL
                SELECT "Comisioane bancare", "comisioane-bancare-admin", "#eb6834", 80 UNION ALL
                SELECT "Resurse umane / Training", "resurse-umane-training", "#184f95", 90 UNION ALL
                SELECT "Alte cheltuieli administrative", "alte-cheltuieli-administrative", "#9a6b1f", 100
            ) AS seed
            WHERE NOT EXISTS (
                SELECT 1
                FROM administrative_expense_categories existing
                WHERE existing.slug = seed.slug
            )
        ');
    }

    public function getDashboardData(): array
    {
        $today = new DateTimeImmutable('today');
        $currentMonthStart = $today->modify('first day of this month');
        $currentMonthEnd = $today->modify('last day of this month');
        $yearStart = $today->setDate((int) $today->format('Y'), 1, 1);

        $monthlyTotal = $this->sumExpenses($currentMonthStart, $currentMonthEnd);
        $yearTotal = $this->sumExpenses($yearStart, $today);

        return [
            'kpis' => [
                'total_lunar' => $monthlyTotal,
                'total_an_curent' => $yearTotal,
                'numar_cheltuieli' => $this->countExpenses($yearStart, $today),
                'ultima_cheltuiala' => $this->getLatestExpense(),
            ],
            'category_totals' => $this->getCategoryTotalsForYear($today),
            'monthly_evolution' => $this->getMonthlyEvolution($today),
        ];
    }

    public function getCategories(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM administrative_expense_categories';

        if ($onlyActive) {
            $sql .= " WHERE status = 'activ'";
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';

        return $this->db->query($sql)->fetchAll();
    }

    public function findCategory(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM administrative_expense_categories WHERE id = :id LIMIT 1');
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
            FROM administrative_expenses e
            INNER JOIN administrative_expense_categories c ON c.id = e.category_id
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
                u.nume AS added_by_name,
                (SELECT COUNT(*) FROM administrative_expense_documents d WHERE d.expense_id = e.id) AS document_count,
                (SELECT d.id FROM administrative_expense_documents d WHERE d.expense_id = e.id ORDER BY d.id DESC LIMIT 1) AS document_id,
                (SELECT d.document_type FROM administrative_expense_documents d WHERE d.expense_id = e.id ORDER BY d.id DESC LIMIT 1) AS document_type,
                (SELECT d.original_name FROM administrative_expense_documents d WHERE d.expense_id = e.id ORDER BY d.id DESC LIMIT 1) AS document_original_name,
                (SELECT d.stored_name FROM administrative_expense_documents d WHERE d.expense_id = e.id ORDER BY d.id DESC LIMIT 1) AS document_stored_name
            FROM administrative_expenses e
            INNER JOIN administrative_expense_categories c ON c.id = e.category_id
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
                (SELECT COUNT(*) FROM administrative_expense_documents d WHERE d.expense_id = e.id) AS document_count
            FROM administrative_expenses e
            INNER JOIN administrative_expense_categories c ON c.id = e.category_id
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
                INSERT INTO administrative_expenses (
                    category_id, expense_date, description, supplier, amount_net, vat_amount, amount_total,
                    payment_method, invoice_number, notes,
                    added_by, updated_by, created_at, updated_at
                ) VALUES (
                    :category_id, :expense_date, :description, :supplier, :amount_net, :vat_amount, :amount_total,
                    :payment_method, :invoice_number, :notes,
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
                UPDATE administrative_expenses
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
        $stmt = $this->db->prepare('DELETE FROM administrative_expenses WHERE id = :id');
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
            SELECT e.*, c.name AS category_name, c.slug AS category_slug
            FROM administrative_expenses e
            INNER JOIN administrative_expense_categories c ON c.id = e.category_id
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
            FROM administrative_expense_documents
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
            FROM administrative_expense_documents
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

        $stmt = $this->db->prepare('SELECT * FROM administrative_expense_documents WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $documentId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function getCategoryTotalsForYear(DateTimeImmutable $today): array
    {
        $yearStart = $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d');
        $yearEnd = $today->format('Y-m-d');

        $stmt = $this->db->prepare('
            SELECT
                c.id,
                c.name,
                c.slug,
                c.color,
                COALESCE(SUM(e.amount_total), 0) AS total
            FROM administrative_expense_categories c
            LEFT JOIN administrative_expenses e
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

        return $stmt->fetchAll();
    }

    private function getMonthlyEvolution(DateTimeImmutable $today): array
    {
        $months = [];
        $firstMonth = $today->modify('first day of this month')->modify('-5 months');

        for ($i = 0; $i < 6; $i++) {
            $monthStart = $firstMonth->modify('+' . $i . ' months');
            $monthEnd = $monthStart->modify('last day of this month');
            $total = $this->sumExpenses($monthStart, $monthEnd);

            $months[] = [
                'label' => $this->monthLabel($monthStart),
                'month' => $monthStart->format('Y-m'),
                'total' => $total,
            ];
        }

        return $months;
    }

    private function sumExpenses(DateTimeImmutable $startDate, DateTimeImmutable $endDate): float
    {
        $stmt = $this->db->prepare('
            SELECT COALESCE(SUM(e.amount_total), 0)
            FROM administrative_expenses e
            WHERE e.expense_date BETWEEN :start_date AND :end_date
        ');
        $stmt->execute([
            ':start_date' => $startDate->format('Y-m-d'),
            ':end_date' => $endDate->format('Y-m-d'),
        ]);

        return (float) $stmt->fetchColumn();
    }

    private function countExpenses(DateTimeImmutable $startDate, DateTimeImmutable $endDate): int
    {
        $stmt = $this->db->prepare('
            SELECT COUNT(*)
            FROM administrative_expenses e
            WHERE e.expense_date BETWEEN :start_date AND :end_date
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
            FROM administrative_expenses e
            INNER JOIN administrative_expense_categories c ON c.id = e.category_id
            ORDER BY e.created_at DESC, e.id DESC
            LIMIT 1
        ');
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
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
            INSERT INTO administrative_expense_documents (
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
