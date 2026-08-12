<?php
declare(strict_types=1);

class LeasingSchedulerModel extends BaseModel
{
    public const FREQUENCIES = [
        'monthly' => 'Lunar',
        'quarterly' => 'Trimestrial',
        'yearly' => 'Anual',
    ];

    public const DOCUMENT_TYPES = [
        'contract_leasing' => 'Contract leasing',
        'grafic_rambursare' => 'Grafic rambursare',
        'factura_rata' => 'Factura rata',
        'dovada_plata' => 'Dovada plata',
        'act_aditional' => 'Act aditional',
        'alte_documente' => 'Alte documente',
    ];

    public const STATUS_LABELS = [
        'la_zi' => 'La zi',
        'in_asteptare' => 'In asteptare',
        'restant' => 'Restant',
        'finalizat' => 'Finalizat',
        'arhivat' => 'Arhivat',
    ];

    private bool $schemaEnsured = false;

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS leasing_contracts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                financier VARCHAR(160) NOT NULL,
                contract_number VARCHAR(100) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                initial_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                advance_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                total_installments INT UNSIGNED NOT NULL DEFAULT 0,
                default_installment_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(12) NOT NULL DEFAULT 'lei',
                frequency ENUM('monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
                due_day TINYINT UNSIGNED NOT NULL DEFAULT 15,
                status ENUM('active','closed','archived') NOT NULL DEFAULT 'active',
                notes TEXT NULL,
                created_by INT UNSIGNED NULL,
                closed_at DATETIME NULL,
                archived_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_leasing_contract_number (contract_number),
                INDEX idx_leasing_contracts_vehicle (vehicle_id),
                INDEX idx_leasing_contracts_status (status),
                INDEX idx_leasing_contracts_period (start_date, end_date),
                CONSTRAINT fk_leasing_contracts_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE RESTRICT,
                CONSTRAINT fk_leasing_contracts_user FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS leasing_installments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                contract_id INT UNSIGNED NOT NULL,
                installment_number INT UNSIGNED NOT NULL,
                due_date DATE NOT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(12) NOT NULL DEFAULT 'lei',
                status ENUM('unpaid','partial','paid','cancelled') NOT NULL DEFAULT 'unpaid',
                payment_date DATE NULL,
                amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                notes TEXT NULL,
                payment_proof_original VARCHAR(255) NULL,
                payment_proof_stored VARCHAR(255) NULL,
                paid_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_leasing_installment_number (contract_id, installment_number),
                INDEX idx_leasing_installments_due (due_date),
                INDEX idx_leasing_installments_status_due (status, due_date),
                CONSTRAINT fk_leasing_installments_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE,
                CONSTRAINT fk_leasing_installments_paid_by FOREIGN KEY (paid_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS leasing_payment_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                contract_id INT UNSIGNED NOT NULL,
                installment_id INT UNSIGNED NOT NULL,
                payment_date DATE NOT NULL,
                amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                notes TEXT NULL,
                proof_original VARCHAR(255) NULL,
                proof_stored VARCHAR(255) NULL,
                registered_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_leasing_payment_history_contract (contract_id, payment_date),
                INDEX idx_leasing_payment_history_installment (installment_id),
                CONSTRAINT fk_leasing_payment_history_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE,
                CONSTRAINT fk_leasing_payment_history_installment FOREIGN KEY (installment_id) REFERENCES leasing_installments(id) ON DELETE CASCADE,
                CONSTRAINT fk_leasing_payment_history_user FOREIGN KEY (registered_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS leasing_documents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                contract_id INT UNSIGNED NOT NULL,
                document_type VARCHAR(80) NOT NULL,
                original_name VARCHAR(255) NULL,
                stored_name VARCHAR(255) NULL,
                mime_type VARCHAR(150) NULL,
                file_size INT UNSIGNED NULL,
                notes TEXT NULL,
                uploaded_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_leasing_documents_contract (contract_id),
                CONSTRAINT fk_leasing_documents_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE,
                CONSTRAINT fk_leasing_documents_user FOREIGN KEY (uploaded_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS leasing_notification_settings (
                contract_id INT UNSIGNED NOT NULL PRIMARY KEY,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                days_before_7 TINYINT(1) NOT NULL DEFAULT 1,
                days_before_3 TINYINT(1) NOT NULL DEFAULT 1,
                days_before_1 TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT fk_leasing_notification_settings_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS leasing_notification_recipients (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                contract_id INT UNSIGNED NOT NULL,
                email VARCHAR(190) NOT NULL,
                name VARCHAR(160) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_leasing_notification_recipient (contract_id, email),
                INDEX idx_leasing_notification_recipients_email (email),
                CONSTRAINT fk_leasing_notification_recipients_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS leasing_notification_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                contract_id INT UNSIGNED NOT NULL,
                installment_id INT UNSIGNED NOT NULL,
                recipient_email VARCHAR(190) NOT NULL,
                reminder_interval_days TINYINT UNSIGNED NOT NULL,
                notification_type VARCHAR(60) NOT NULL DEFAULT 'leasing_installment_due',
                sent_at DATETIME NULL,
                status ENUM('sent','failed','skipped') NOT NULL DEFAULT 'sent',
                provider_response TEXT NULL,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_leasing_notification_lookup (installment_id, recipient_email, reminder_interval_days, notification_type, status),
                INDEX idx_leasing_notification_contract (contract_id, created_at),
                CONSTRAINT fk_leasing_notification_log_contract FOREIGN KEY (contract_id) REFERENCES leasing_contracts(id) ON DELETE CASCADE,
                CONSTRAINT fk_leasing_notification_log_installment FOREIGN KEY (installment_id) REFERENCES leasing_installments(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->schemaEnsured = true;
    }

    public function getDashboard(array $input): array
    {
        $this->ensureSchema();
        $filters = $this->normalizeFilters($input);
        $contracts = $this->getContracts($filters);

        return [
            'filters' => $filters,
            'kpis' => $this->getKpis(),
            'contracts' => $this->attachContractCollections($contracts),
            'filterOptions' => $this->getFilterOptions(),
            'vehicleOptions' => $this->getVehicleOptions(),
            'statusOptions' => self::STATUS_LABELS,
            'frequencyOptions' => self::FREQUENCIES,
            'documentTypes' => self::DOCUMENT_TYPES,
        ];
    }

    public function getContractsForExport(array $input): array
    {
        $this->ensureSchema();
        return $this->getContracts($this->normalizeFilters($input));
    }

    public function getVehicleOptions(): array
    {
        $this->ensureSchema();
        $stmt = $this->db->query("
            SELECT id, nr_inmatriculare, marca, model, poza_original, poza_stocata
            FROM vehicule
            WHERE COALESCE(status, 'activ') = 'activ'
              AND nr_inmatriculare <> 'STOC-ANVELOPE'
              AND serie_sasiu <> 'STOCANVELOPE00001'
            ORDER BY nr_inmatriculare ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findContract(int $contractId): ?array
    {
        $this->ensureSchema();
        if ($contractId <= 0) {
            return null;
        }

        $rows = $this->getContracts(['selected_id' => $contractId, 'include_archived' => true]);
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $contractId) {
                return $row;
            }
        }

        return null;
    }

    public function findInstallment(int $installmentId): ?array
    {
        $this->ensureSchema();
        if ($installmentId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT i.*, c.contract_number, c.financier, c.currency AS contract_currency,
                   v.nr_inmatriculare, v.marca, v.model
            FROM leasing_installments i
            INNER JOIN leasing_contracts c ON c.id = i.contract_id
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            WHERE i.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $installmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function hasOverlappingActiveContract(int $vehicleId, string $startDate, string $endDate, ?int $excludeId = null): bool
    {
        $this->ensureSchema();

        $sql = "
            SELECT COUNT(*)
            FROM leasing_contracts
            WHERE vehicle_id = :vehicle_id
              AND status = 'active'
              AND NOT (end_date < :start_date OR start_date > :end_date)
        ";
        $params = [
            ':vehicle_id' => $vehicleId,
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ];

        if ($excludeId !== null && $excludeId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function createContract(array $data, array $notificationSettings, array $recipients, ?array $documentData, ?int $userId): int
    {
        $this->ensureSchema();
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO leasing_contracts (
                    vehicle_id, financier, contract_number, start_date, end_date,
                    initial_value, advance_amount, total_installments, default_installment_amount,
                    currency, frequency, due_day, status, notes, created_by, created_at, updated_at
                ) VALUES (
                    :vehicle_id, :financier, :contract_number, :start_date, :end_date,
                    :initial_value, :advance_amount, :total_installments, :default_installment_amount,
                    :currency, :frequency, :due_day, 'active', :notes, :created_by, :created_at, :updated_at
                )
            ");
            $this->bindContractStatement($stmt, $data, $now, $userId);
            $stmt->execute();
            $contractId = (int) $this->db->lastInsertId();

            $this->generateInstallments(
                $contractId,
                (string) $data['start_date'],
                (int) $data['total_installments'],
                (int) $data['due_day'],
                (float) $data['default_installment_amount'],
                (string) $data['currency'],
                (string) $data['frequency'],
                false
            );
            $this->saveNotificationSettings($contractId, $notificationSettings, $recipients);
            if ($documentData !== null) {
                $this->insertDocument($contractId, $documentData, $userId);
            }
            $this->logAudit('create', $contractId, 'Contract leasing creat: ' . (string) $data['contract_number'], null, $data, $userId);

            $this->db->commit();
            return $contractId;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function updateContract(int $contractId, array $data, array $notificationSettings, array $recipients, bool $regenerateSchedule, ?int $userId): bool
    {
        $this->ensureSchema();
        $existing = $this->findContract($contractId);
        if ($existing === null) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE leasing_contracts
                SET vehicle_id = :vehicle_id,
                    financier = :financier,
                    contract_number = :contract_number,
                    start_date = :start_date,
                    end_date = :end_date,
                    initial_value = :initial_value,
                    advance_amount = :advance_amount,
                    total_installments = :total_installments,
                    default_installment_amount = :default_installment_amount,
                    currency = :currency,
                    frequency = :frequency,
                    due_day = :due_day,
                    notes = :notes,
                    updated_at = :updated_at
                WHERE id = :id
            ");
            $this->bindContractStatement($stmt, $data, $now, $userId, false);
            $stmt->bindValue(':id', $contractId, PDO::PARAM_INT);
            $stmt->execute();

            if ($regenerateSchedule) {
                $this->generateInstallments(
                    $contractId,
                    (string) $data['start_date'],
                    (int) $data['total_installments'],
                    (int) $data['due_day'],
                    (float) $data['default_installment_amount'],
                    (string) $data['currency'],
                    (string) $data['frequency'],
                    true
                );
            }

            $this->saveNotificationSettings($contractId, $notificationSettings, $recipients);
            $this->logAudit('update', $contractId, 'Contract leasing actualizat: ' . (string) $data['contract_number'], $existing, $data, $userId);

            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function markInstallmentPaid(int $installmentId, array $paymentData, ?array $proofData, ?int $userId): bool
    {
        $this->ensureSchema();
        $installment = $this->findInstallment($installmentId);
        if ($installment === null) {
            return false;
        }

        $amount = max(0.0, (float) ($paymentData['amount_paid'] ?? 0));
        $dueAmount = (float) ($installment['amount'] ?? 0);
        $status = $amount + 0.005 >= $dueAmount ? 'paid' : 'partial';
        $paymentDate = (string) ($paymentData['payment_date'] ?? date('Y-m-d'));
        $notes = trim((string) ($paymentData['notes'] ?? ''));
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE leasing_installments
                SET status = :status,
                    payment_date = :payment_date,
                    amount_paid = :amount_paid,
                    notes = :notes,
                    payment_proof_original = COALESCE(:proof_original, payment_proof_original),
                    payment_proof_stored = COALESCE(:proof_stored, payment_proof_stored),
                    paid_by = :paid_by,
                    updated_at = :updated_at
                WHERE id = :id
            ");
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':payment_date', $paymentDate);
            $stmt->bindValue(':amount_paid', number_format($amount, 2, '.', ''));
            $this->bindNullableString($stmt, ':notes', $notes);
            $this->bindNullableString($stmt, ':proof_original', $proofData['original_name'] ?? null);
            $this->bindNullableString($stmt, ':proof_stored', $proofData['stored_name'] ?? null);
            $this->bindNullableInt($stmt, ':paid_by', $userId);
            $stmt->bindValue(':updated_at', $now);
            $stmt->bindValue(':id', $installmentId, PDO::PARAM_INT);
            $stmt->execute();

            $historyStmt = $this->db->prepare("
                INSERT INTO leasing_payment_history (
                    contract_id, installment_id, payment_date, amount_paid, notes,
                    proof_original, proof_stored, registered_by, created_at
                ) VALUES (
                    :contract_id, :installment_id, :payment_date, :amount_paid, :notes,
                    :proof_original, :proof_stored, :registered_by, :created_at
                )
            ");
            $historyStmt->bindValue(':contract_id', (int) $installment['contract_id'], PDO::PARAM_INT);
            $historyStmt->bindValue(':installment_id', $installmentId, PDO::PARAM_INT);
            $historyStmt->bindValue(':payment_date', $paymentDate);
            $historyStmt->bindValue(':amount_paid', number_format($amount, 2, '.', ''));
            $this->bindNullableString($historyStmt, ':notes', $notes);
            $this->bindNullableString($historyStmt, ':proof_original', $proofData['original_name'] ?? null);
            $this->bindNullableString($historyStmt, ':proof_stored', $proofData['stored_name'] ?? null);
            $this->bindNullableInt($historyStmt, ':registered_by', $userId);
            $historyStmt->bindValue(':created_at', $now);
            $historyStmt->execute();

            $this->logAudit(
                'payment',
                (int) $installment['contract_id'],
                'Rata leasing #' . (string) $installment['installment_number'] . ' marcata ca platita',
                $installment,
                ['installment_id' => $installmentId, 'amount_paid' => $amount, 'payment_date' => $paymentDate, 'status' => $status],
                $userId
            );

            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function updateNotificationSettings(int $contractId, array $settings, array $recipients, ?int $userId): bool
    {
        $this->ensureSchema();
        $existing = $this->findContract($contractId);
        if ($existing === null) {
            return false;
        }

        $before = [
            'settings' => $this->getNotificationSettingsForContract($contractId),
            'recipients' => $this->getRecipientsForContractIds([$contractId])[$contractId] ?? [],
        ];
        $this->saveNotificationSettings($contractId, $settings, $recipients);
        $after = [
            'settings' => $this->getNotificationSettingsForContract($contractId),
            'recipients' => $this->getRecipientsForContractIds([$contractId])[$contractId] ?? [],
        ];
        $this->logAudit('notifications', $contractId, 'Setari notificari leasing actualizate', $before, $after, $userId);

        return true;
    }

    public function addDocument(int $contractId, array $documentData, ?int $userId): int
    {
        $this->ensureSchema();
        if ($this->findContract($contractId) === null) {
            return 0;
        }

        $documentId = $this->insertDocument($contractId, $documentData, $userId);
        $this->logAudit('document', $contractId, 'Document leasing incarcat: ' . (string) ($documentData['original_name'] ?? ''), null, $documentData, $userId);

        return $documentId;
    }

    public function findDocument(int $documentId): ?array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('SELECT * FROM leasing_documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function closeContract(int $contractId, ?int $userId): bool
    {
        return $this->setContractLifecycleStatus($contractId, 'closed', $userId);
    }

    public function archiveContract(int $contractId, ?int $userId): bool
    {
        return $this->setContractLifecycleStatus($contractId, 'archived', $userId);
    }

    public function sendDueReminders(EmailService $emailService): array
    {
        $this->ensureSchema();
        $stmt = $this->db->query("
            SELECT c.id AS contract_id, c.contract_number, c.financier,
                   c.currency, v.nr_inmatriculare, v.marca, v.model,
                   i.id AS installment_id, i.installment_number, i.due_date, i.amount,
                   COALESCE(rem.remaining_balance, 0) AS remaining_balance,
                   DATEDIFF(i.due_date, CURDATE()) AS days_until_due,
                   s.days_before_7, s.days_before_3, s.days_before_1
            FROM leasing_contracts c
            INNER JOIN leasing_notification_settings s ON s.contract_id = c.id AND s.enabled = 1
            INNER JOIN leasing_installments i ON i.contract_id = c.id AND i.status <> 'paid'
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN (
                SELECT contract_id, SUM(GREATEST(amount - amount_paid, 0)) AS remaining_balance
                FROM leasing_installments
                WHERE status <> 'paid'
                GROUP BY contract_id
            ) rem ON rem.contract_id = c.id
            WHERE c.status = 'active'
              AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ORDER BY i.due_date ASC, c.id ASC
        ");

        $summary = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $days = (int) ($row['days_until_due'] ?? -1);
            if (!$this->reminderIntervalEnabled($row, $days)) {
                continue;
            }

            $recipients = $this->getRecipientsForContractIds([(int) $row['contract_id']])[(int) $row['contract_id']] ?? [];
            foreach ($recipients as $recipient) {
                $summary['processed']++;
                $email = trim((string) ($recipient['email'] ?? ''));
                if ($email === '' || $this->notificationAlreadySent((int) $row['installment_id'], $email, $days)) {
                    $summary['skipped']++;
                    continue;
                }

                $result = $emailService->deliverTextEmail(
                    $email,
                    (string) ($recipient['name'] ?? ''),
                    $this->buildReminderSubject($row),
                    $this->buildReminderBody($row),
                    [
                        'context' => 'leasing_installment_due',
                        'context_id' => 'installment:' . (string) $row['installment_id'],
                        'metadata' => [
                            'contract_id' => (int) $row['contract_id'],
                            'installment_id' => (int) $row['installment_id'],
                            'days_until_due' => $days,
                        ],
                    ]
                );

                $sent = (bool) ($result['sent'] ?? false);
                $this->logNotificationAttempt($row, $email, $days, $sent, $result);
                $summary[$sent ? 'sent' : 'failed']++;
            }
        }

        return $summary;
    }

    private function normalizeFilters(array $input): array
    {
        $status = trim((string) ($input['status'] ?? ''));
        if ($status !== '' && !array_key_exists($status, self::STATUS_LABELS)) {
            $status = '';
        }

        return [
            'financier' => trim((string) ($input['financier'] ?? '')),
            'status' => $status,
            'vehicle_id' => max(0, (int) ($input['vehicle_id'] ?? 0)),
            'due_date' => $this->normalizeDate((string) ($input['due_date'] ?? '')) ?? '',
            'q' => trim((string) ($input['q'] ?? '')),
            'selected_id' => max(0, (int) ($input['selected_id'] ?? 0)),
            'include_archived' => !empty($input['include_archived']),
        ];
    }

    private function getContracts(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['selected_id'])) {
            $conditions[] = 'c.id = :selected_id';
            $params[':selected_id'] = (int) $filters['selected_id'];
        } elseif (($filters['status'] ?? '') === 'arhivat') {
            $conditions[] = "c.status = 'archived'";
        } elseif (empty($filters['include_archived'])) {
            $conditions[] = "c.status <> 'archived'";
        }

        if (($filters['financier'] ?? '') !== '') {
            $conditions[] = 'c.financier = :financier';
            $params[':financier'] = (string) $filters['financier'];
        }
        if ((int) ($filters['vehicle_id'] ?? 0) > 0) {
            $conditions[] = 'c.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = (int) $filters['vehicle_id'];
        }
        if (($filters['due_date'] ?? '') !== '') {
            $conditions[] = 'nexti.due_date = :due_date';
            $params[':due_date'] = (string) $filters['due_date'];
        }
        if (($filters['q'] ?? '') !== '') {
            $conditions[] = '(v.nr_inmatriculare LIKE :q OR v.marca LIKE :q OR v.model LIKE :q OR c.contract_number LIKE :q OR c.financier LIKE :q)';
            $params[':q'] = '%' . (string) $filters['q'] . '%';
        }

        $whereSql = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $stmt = $this->db->prepare("
            SELECT c.*,
                   v.nr_inmatriculare, v.marca, v.model, v.poza_original, v.poza_stocata,
                   COALESCE(agg.installment_count, 0) AS generated_installments,
                   COALESCE(agg.paid_count, 0) AS paid_installments,
                   COALESCE(agg.unpaid_count, 0) AS unpaid_installments,
                   COALESCE(agg.overdue_count, 0) AS overdue_installments,
                   COALESCE(agg.total_paid, 0) AS total_paid,
                   COALESCE(agg.remaining_balance, 0) AS remaining_balance,
                   COALESCE(agg.total_scheduled, 0) AS total_scheduled,
                   nexti.id AS next_installment_id,
                   nexti.installment_number AS next_installment_number,
                   nexti.due_date AS next_due_date,
                   nexti.amount AS next_installment_amount,
                   ns.enabled AS notifications_enabled,
                   ns.days_before_7, ns.days_before_3, ns.days_before_1
            FROM leasing_contracts c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN (
                SELECT contract_id,
                       COUNT(*) AS installment_count,
                       SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                       SUM(CASE WHEN status <> 'paid' THEN 1 ELSE 0 END) AS unpaid_count,
                       SUM(CASE WHEN status <> 'paid' AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count,
                       SUM(CASE WHEN status = 'paid' THEN amount_paid ELSE 0 END) AS total_paid,
                       SUM(CASE WHEN status <> 'paid' THEN GREATEST(amount - amount_paid, 0) ELSE 0 END) AS remaining_balance,
                       SUM(amount) AS total_scheduled
                FROM leasing_installments
                GROUP BY contract_id
            ) agg ON agg.contract_id = c.id
            LEFT JOIN leasing_installments nexti ON nexti.id = (
                SELECT i2.id
                FROM leasing_installments i2
                WHERE i2.contract_id = c.id
                  AND i2.status <> 'paid'
                ORDER BY i2.due_date ASC, i2.installment_number ASC
                LIMIT 1
            )
            LEFT JOIN leasing_notification_settings ns ON ns.contract_id = c.id
            {$whereSql}
            ORDER BY
                CASE WHEN nexti.due_date IS NULL THEN 1 ELSE 0 END ASC,
                nexti.due_date ASC,
                c.id DESC
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        $rows = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $row = $this->decorateContractRow($row);
            if (($filters['status'] ?? '') !== '' && (string) $row['calculated_status'] !== (string) $filters['status']) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function decorateContractRow(array $row): array
    {
        $generated = (int) ($row['generated_installments'] ?? 0);
        $paid = (int) ($row['paid_installments'] ?? 0);
        $progress = $generated > 0 ? round(($paid / $generated) * 100, 1) : 0.0;
        $row['progress_percent'] = min(100.0, max(0.0, $progress));
        $row['advance_percent'] = (float) ($row['initial_value'] ?? 0) > 0
            ? round(((float) ($row['advance_amount'] ?? 0) / (float) $row['initial_value']) * 100, 1)
            : 0.0;
        $row['days_until_next_due'] = $this->daysUntil((string) ($row['next_due_date'] ?? ''));
        $row['calculated_status'] = $this->calculateStatus($row);
        $row['calculated_status_label'] = self::STATUS_LABELS[(string) $row['calculated_status']] ?? '-';
        $row['notification_intervals'] = $this->notificationIntervalsFromRow($row);

        return $row;
    }

    private function calculateStatus(array $row): string
    {
        $lifecycleStatus = (string) ($row['status'] ?? 'active');
        if ($lifecycleStatus === 'archived') {
            return 'arhivat';
        }
        if ($lifecycleStatus === 'closed') {
            return 'finalizat';
        }

        $generated = (int) ($row['generated_installments'] ?? 0);
        $unpaid = (int) ($row['unpaid_installments'] ?? 0);
        if ($generated > 0 && $unpaid === 0) {
            return 'finalizat';
        }
        if ((int) ($row['overdue_installments'] ?? 0) > 0) {
            return 'restant';
        }

        $daysUntil = $row['days_until_next_due'];
        if ($daysUntil !== null && $daysUntil >= 0) {
            $warningDays = max([1, 3, 7, 30, ...$this->notificationIntervalsFromRow($row)]);
            if ($daysUntil <= $warningDays) {
                return 'in_asteptare';
            }
        }

        return 'la_zi';
    }

    private function getKpis(): array
    {
        $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $monthEnd = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');

        $active = (int) $this->db->query("SELECT COUNT(*) FROM leasing_contracts WHERE status = 'active'")->fetchColumn();

        $current = $this->sumInstallments("i.due_date BETWEEN :start AND :end", [
            ':start' => $monthStart,
            ':end' => $monthEnd,
        ]);
        $upcoming = $this->sumInstallments("i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)", []);
        $overdue = $this->sumInstallments("i.due_date < CURDATE()", []);

        return [
            'active_contracts' => $active,
            'current_month_count' => $current['count'],
            'current_month_total' => $current['total'],
            'upcoming_count' => $upcoming['count'],
            'upcoming_total' => $upcoming['total'],
            'overdue_count' => $overdue['count'],
            'overdue_total' => $overdue['total'],
            'month_label' => current_month_ro(),
        ];
    }

    private function sumInstallments(string $dateCondition, array $params): array
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS item_count,
                   COALESCE(SUM(GREATEST(i.amount - i.amount_paid, 0)), 0) AS total_amount
            FROM leasing_installments i
            INNER JOIN leasing_contracts c ON c.id = i.contract_id
            WHERE c.status = 'active'
              AND i.status <> 'paid'
              AND {$dateCondition}
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'count' => (int) ($row['item_count'] ?? 0),
            'total' => (float) ($row['total_amount'] ?? 0),
        ];
    }

    private function getFilterOptions(): array
    {
        $financiers = $this->db->query("
            SELECT DISTINCT financier
            FROM leasing_contracts
            WHERE TRIM(financier) <> ''
            ORDER BY financier ASC
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return ['financiers' => array_values($financiers)];
    }

    private function attachContractCollections(array $contracts): array
    {
        $ids = array_map(static fn(array $row): int => (int) $row['id'], $contracts);
        if ($ids === []) {
            return [];
        }

        $installments = $this->getInstallmentsForContractIds($ids);
        $documents = $this->getDocumentsForContractIds($ids);
        $payments = $this->getPaymentHistoryForContractIds($ids);
        $recipients = $this->getRecipientsForContractIds($ids);

        foreach ($contracts as &$contract) {
            $id = (int) $contract['id'];
            $contract['installments'] = $installments[$id] ?? [];
            $contract['documents'] = $documents[$id] ?? [];
            $contract['payment_history'] = $payments[$id] ?? [];
            $contract['recipients'] = $recipients[$id] ?? [];
        }
        unset($contract);

        return $contracts;
    }

    private function getInstallmentsForContractIds(array $contractIds): array
    {
        return $this->groupRowsByContract($contractIds, "
            SELECT *
            FROM leasing_installments
            WHERE contract_id IN (%s)
            ORDER BY installment_number ASC
        ");
    }

    private function getDocumentsForContractIds(array $contractIds): array
    {
        return $this->groupRowsByContract($contractIds, "
            SELECT *
            FROM leasing_documents
            WHERE contract_id IN (%s)
            ORDER BY created_at DESC, id DESC
        ");
    }

    private function getPaymentHistoryForContractIds(array $contractIds): array
    {
        return $this->groupRowsByContract($contractIds, "
            SELECT h.*, i.installment_number, i.due_date,
                   DATEDIFF(h.payment_date, i.due_date) AS delay_days,
                   u.nume AS registered_by_name
            FROM leasing_payment_history h
            INNER JOIN leasing_installments i ON i.id = h.installment_id
            LEFT JOIN utilizatori u ON u.id = h.registered_by
            WHERE h.contract_id IN (%s)
            ORDER BY h.payment_date DESC, h.id DESC
        ");
    }

    private function getRecipientsForContractIds(array $contractIds): array
    {
        return $this->groupRowsByContract($contractIds, "
            SELECT *
            FROM leasing_notification_recipients
            WHERE contract_id IN (%s)
            ORDER BY email ASC
        ");
    }

    private function groupRowsByContract(array $contractIds, string $sqlTemplate): array
    {
        $contractIds = array_values(array_unique(array_filter(array_map('intval', $contractIds))));
        if ($contractIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contractIds), '?'));
        $stmt = $this->db->prepare(sprintf($sqlTemplate, $placeholders));
        foreach ($contractIds as $index => $id) {
            $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $grouped = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $contractId = (int) ($row['contract_id'] ?? 0);
            if ($contractId > 0) {
                $grouped[$contractId][] = $row;
            }
        }

        return $grouped;
    }

    private function bindContractStatement(PDOStatement $stmt, array $data, string $now, ?int $userId, bool $includeCreated = true): void
    {
        $stmt->bindValue(':vehicle_id', (int) $data['vehicle_id'], PDO::PARAM_INT);
        $stmt->bindValue(':financier', (string) $data['financier']);
        $stmt->bindValue(':contract_number', (string) $data['contract_number']);
        $stmt->bindValue(':start_date', (string) $data['start_date']);
        $stmt->bindValue(':end_date', (string) $data['end_date']);
        $stmt->bindValue(':initial_value', number_format((float) $data['initial_value'], 2, '.', ''));
        $stmt->bindValue(':advance_amount', number_format((float) $data['advance_amount'], 2, '.', ''));
        $stmt->bindValue(':total_installments', (int) $data['total_installments'], PDO::PARAM_INT);
        $stmt->bindValue(':default_installment_amount', number_format((float) $data['default_installment_amount'], 2, '.', ''));
        $stmt->bindValue(':currency', (string) $data['currency']);
        $stmt->bindValue(':frequency', (string) $data['frequency']);
        $stmt->bindValue(':due_day', (int) $data['due_day'], PDO::PARAM_INT);
        $this->bindNullableString($stmt, ':notes', $data['notes'] ?? null);
        $stmt->bindValue(':updated_at', $now);
        if ($includeCreated) {
            $this->bindNullableInt($stmt, ':created_by', $userId);
            $stmt->bindValue(':created_at', $now);
        }
    }

    private function generateInstallments(
        int $contractId,
        string $startDate,
        int $totalInstallments,
        int $dueDay,
        float $amount,
        string $currency,
        string $frequency,
        bool $preservePaid
    ): void {
        $paidNumbers = [];
        if ($preservePaid) {
            $stmt = $this->db->prepare("SELECT installment_number FROM leasing_installments WHERE contract_id = :id AND status = 'paid'");
            $stmt->execute([':id' => $contractId]);
            foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $number) {
                $paidNumbers[(int) $number] = true;
            }
            $delete = $this->db->prepare("DELETE FROM leasing_installments WHERE contract_id = :id AND status <> 'paid'");
        } else {
            $delete = $this->db->prepare('DELETE FROM leasing_installments WHERE contract_id = :id');
        }
        $delete->execute([':id' => $contractId]);

        $stepMonths = match ($frequency) {
            'quarterly' => 3,
            'yearly' => 12,
            default => 1,
        };
        $base = new DateTimeImmutable($startDate);
        $insert = $this->db->prepare("
            INSERT INTO leasing_installments (
                contract_id, installment_number, due_date, amount, currency, status, created_at, updated_at
            ) VALUES (
                :contract_id, :installment_number, :due_date, :amount, :currency, 'unpaid', :created_at, :updated_at
            )
        ");
        $now = date('Y-m-d H:i:s');

        for ($number = 1; $number <= $totalInstallments; $number++) {
            if (isset($paidNumbers[$number])) {
                continue;
            }
            $dueDate = $this->calculateDueDate($base, ($number - 1) * $stepMonths, $dueDay);
            $insert->bindValue(':contract_id', $contractId, PDO::PARAM_INT);
            $insert->bindValue(':installment_number', $number, PDO::PARAM_INT);
            $insert->bindValue(':due_date', $dueDate);
            $insert->bindValue(':amount', number_format($amount, 2, '.', ''));
            $insert->bindValue(':currency', $currency);
            $insert->bindValue(':created_at', $now);
            $insert->bindValue(':updated_at', $now);
            $insert->execute();
        }
    }

    private function calculateDueDate(DateTimeImmutable $base, int $monthOffset, int $dueDay): string
    {
        $monthBase = $base->modify('first day of this month')->modify('+' . $monthOffset . ' months');
        $lastDay = (int) $monthBase->format('t');
        $day = max(1, min($dueDay, $lastDay));

        return $monthBase->setDate((int) $monthBase->format('Y'), (int) $monthBase->format('m'), $day)->format('Y-m-d');
    }

    private function saveNotificationSettings(int $contractId, array $settings, array $recipients): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            INSERT INTO leasing_notification_settings (
                contract_id, enabled, days_before_7, days_before_3, days_before_1, created_at, updated_at
            ) VALUES (
                :contract_id, :enabled, :days_before_7, :days_before_3, :days_before_1, :created_at, :updated_at
            )
            ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                days_before_7 = VALUES(days_before_7),
                days_before_3 = VALUES(days_before_3),
                days_before_1 = VALUES(days_before_1),
                updated_at = VALUES(updated_at)
        ");
        $stmt->execute([
            ':contract_id' => $contractId,
            ':enabled' => !empty($settings['enabled']) ? 1 : 0,
            ':days_before_7' => in_array(7, $settings['intervals'] ?? [], true) ? 1 : 0,
            ':days_before_3' => in_array(3, $settings['intervals'] ?? [], true) ? 1 : 0,
            ':days_before_1' => in_array(1, $settings['intervals'] ?? [], true) ? 1 : 0,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $this->db->prepare('DELETE FROM leasing_notification_recipients WHERE contract_id = :id')->execute([':id' => $contractId]);
        $insert = $this->db->prepare("
            INSERT IGNORE INTO leasing_notification_recipients (contract_id, email, name, created_at, updated_at)
            VALUES (:contract_id, :email, :name, :created_at, :updated_at)
        ");
        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? $recipient));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $insert->bindValue(':contract_id', $contractId, PDO::PARAM_INT);
            $insert->bindValue(':email', $email);
            $this->bindNullableString($insert, ':name', $recipient['name'] ?? null);
            $insert->bindValue(':created_at', $now);
            $insert->bindValue(':updated_at', $now);
            $insert->execute();
        }
    }

    private function getNotificationSettingsForContract(int $contractId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM leasing_notification_settings WHERE contract_id = :id LIMIT 1');
        $stmt->execute([':id' => $contractId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function insertDocument(int $contractId, array $documentData, ?int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            INSERT INTO leasing_documents (
                contract_id, document_type, original_name, stored_name, mime_type, file_size, notes, uploaded_by, created_at, updated_at
            ) VALUES (
                :contract_id, :document_type, :original_name, :stored_name, :mime_type, :file_size, :notes, :uploaded_by, :created_at, :updated_at
            )
        ");
        $stmt->bindValue(':contract_id', $contractId, PDO::PARAM_INT);
        $stmt->bindValue(':document_type', (string) ($documentData['document_type'] ?? 'alte_documente'));
        $this->bindNullableString($stmt, ':original_name', $documentData['original_name'] ?? null);
        $this->bindNullableString($stmt, ':stored_name', $documentData['stored_name'] ?? null);
        $this->bindNullableString($stmt, ':mime_type', $documentData['mime_type'] ?? null);
        $this->bindNullableInt($stmt, ':file_size', isset($documentData['file_size']) ? (int) $documentData['file_size'] : null);
        $this->bindNullableString($stmt, ':notes', $documentData['notes'] ?? null);
        $this->bindNullableInt($stmt, ':uploaded_by', $userId);
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    private function setContractLifecycleStatus(int $contractId, string $status, ?int $userId): bool
    {
        $this->ensureSchema();
        $existing = $this->findContract($contractId);
        if ($existing === null || !in_array($status, ['closed', 'archived'], true)) {
            return false;
        }

        $timestampColumn = $status === 'closed' ? 'closed_at' : 'archived_at';
        $stmt = $this->db->prepare("
            UPDATE leasing_contracts
            SET status = :status,
                {$timestampColumn} = :ts,
                updated_at = :ts
            WHERE id = :id
        ");
        $now = date('Y-m-d H:i:s');
        $stmt->execute([':status' => $status, ':ts' => $now, ':id' => $contractId]);
        $this->logAudit($status === 'closed' ? 'close' : 'archive', $contractId, 'Contract leasing ' . ($status === 'closed' ? 'inchis' : 'arhivat'), $existing, ['status' => $status], $userId);

        return true;
    }

    private function notificationIntervalsFromRow(array $row): array
    {
        $intervals = [];
        if ((int) ($row['days_before_7'] ?? 1) === 1) {
            $intervals[] = 7;
        }
        if ((int) ($row['days_before_3'] ?? 1) === 1) {
            $intervals[] = 3;
        }
        if ((int) ($row['days_before_1'] ?? 1) === 1) {
            $intervals[] = 1;
        }

        return $intervals;
    }

    private function reminderIntervalEnabled(array $row, int $days): bool
    {
        return match ($days) {
            7 => (int) ($row['days_before_7'] ?? 0) === 1,
            3 => (int) ($row['days_before_3'] ?? 0) === 1,
            1 => (int) ($row['days_before_1'] ?? 0) === 1,
            default => false,
        };
    }

    private function notificationAlreadySent(int $installmentId, string $email, int $days): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM leasing_notification_log
            WHERE installment_id = :installment_id
              AND recipient_email = :email
              AND reminder_interval_days = :days
              AND notification_type = 'leasing_installment_due'
              AND status = 'sent'
        ");
        $stmt->execute([
            ':installment_id' => $installmentId,
            ':email' => $email,
            ':days' => $days,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function logNotificationAttempt(array $row, string $email, int $days, bool $sent, array $result): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO leasing_notification_log (
                contract_id, installment_id, recipient_email, reminder_interval_days,
                notification_type, sent_at, status, provider_response, error_message, created_at
            ) VALUES (
                :contract_id, :installment_id, :recipient_email, :reminder_interval_days,
                'leasing_installment_due', :sent_at, :status, :provider_response, :error_message, :created_at
            )
        ");
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            ':contract_id' => (int) $row['contract_id'],
            ':installment_id' => (int) $row['installment_id'],
            ':recipient_email' => $email,
            ':reminder_interval_days' => $days,
            ':sent_at' => $sent ? $now : null,
            ':status' => $sent ? 'sent' : 'failed',
            ':provider_response' => isset($result['response']) ? (string) $result['response'] : null,
            ':error_message' => isset($result['error']) ? (string) $result['error'] : null,
            ':created_at' => $now,
        ]);
    }

    private function buildReminderSubject(array $row): string
    {
        return 'Scadenta leasing - '
            . (string) ($row['nr_inmatriculare'] ?? '-')
            . ' - '
            . format_date_ro((string) ($row['due_date'] ?? ''));
    }

    private function buildReminderBody(array $row): string
    {
        $days = (int) ($row['days_until_due'] ?? 0);
        $vehicle = trim((string) ($row['marca'] ?? '') . ' ' . (string) ($row['model'] ?? ''));

        return "Rata de leasing aferenta vehiculului " . (string) ($row['nr_inmatriculare'] ?? '-') . " ajunge la scadenta in {$days} zile.\n\n"
            . "Vehicul: " . (string) ($row['nr_inmatriculare'] ?? '-') . ' - ' . $vehicle . "\n"
            . "Contract: " . (string) ($row['contract_number'] ?? '-') . "\n"
            . "Finantator: " . (string) ($row['financier'] ?? '-') . "\n"
            . "Rata: #" . (string) ($row['installment_number'] ?? '-') . "\n"
            . "Valoare: " . format_number_ro((float) ($row['amount'] ?? 0), 2) . ' ' . (string) ($row['currency'] ?? 'lei') . "\n"
            . "Scadenta: " . format_date_ro((string) ($row['due_date'] ?? '')) . "\n"
            . "Sold ramas: " . format_number_ro((float) ($row['remaining_balance'] ?? 0), 2) . ' ' . (string) ($row['currency'] ?? 'lei') . "\n";
    }

    private function daysUntil(string $date): ?int
    {
        if ($date === '') {
            return null;
        }
        try {
            $today = new DateTimeImmutable('today');
            $target = new DateTimeImmutable($date);
            return (int) $today->diff($target)->format('%r%a');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $date;
    }

    private function logAudit(string $action, int $recordId, string $description, ?array $beforeData, ?array $afterData, ?int $userId): void
    {
        if (!$this->tableExists('audit_log')) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO audit_log (modul, record_id, actiune, descriere, before_data, after_data, user_id, created_at)
            VALUES ('scadentar_leasing', :record_id, :actiune, :descriere, :before_data, :after_data, :user_id, :created_at)
        ");
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $stmt->bindValue(':actiune', $action);
        $description = function_exists('mb_substr') ? mb_substr($description, 0, 255, 'UTF-8') : substr($description, 0, 255);
        $stmt->bindValue(':descriere', $description);
        $stmt->bindValue(':before_data', $beforeData !== null ? json_encode($beforeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $beforeData !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':after_data', $afterData !== null ? json_encode($afterData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $afterData !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $this->bindNullableInt($stmt, ':user_id', $userId);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'));
        $stmt->execute();
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
        ");
        $stmt->execute([':table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function bindNullableString(PDOStatement $stmt, string $key, mixed $value): void
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, $value);
    }

    private function bindNullableInt(PDOStatement $stmt, string $key, mixed $value): void
    {
        if ($value === null || trim((string) $value) === '') {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, (string) $value);
            }
        }
    }
}
