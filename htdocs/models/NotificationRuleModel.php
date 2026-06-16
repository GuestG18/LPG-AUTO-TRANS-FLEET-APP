<?php
declare(strict_types=1);

class NotificationRuleModel extends BaseModel
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureRuleSchemaExtensions();
    }

    public function getRules(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        if (!$this->tableExists('notification_rules')) {
            return [
                'rows' => [],
                'total_rows' => 0,
                'total_pages' => 1,
                'page' => 1,
                'per_page' => $perPage,
            ];
        }

        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(r.name LIKE :q OR COALESCE(r.document_type, '') LIKE :q OR COALESCE(rec.recipient_names, '') LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $entityType = trim((string) ($filters['entity_type'] ?? ''));
        if ($entityType !== '') {
            $where[] = 'r.entity_type = :entity_type';
            $params[':entity_type'] = $entityType;
        }

        $eventType = trim((string) ($filters['event_type'] ?? ''));
        if ($eventType !== '') {
            $where[] = 'r.event_type = :event_type';
            $params[':event_type'] = $eventType;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === 'active') {
            $where[] = 'r.enabled = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'r.enabled = 0';
        }

        $recipientsSql = "
            SELECT
                rr.rule_id,
                GROUP_CONCAT(u.nume ORDER BY u.nume SEPARATOR ', ') AS recipient_names,
                GROUP_CONCAT(u.email ORDER BY u.nume SEPARATOR ', ') AS recipient_emails
            FROM notification_rule_recipients rr
            INNER JOIN utilizatori u ON u.id = rr.user_id
            GROUP BY rr.rule_id
        ";
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $fromSql = "
            FROM notification_rules r
            LEFT JOIN ({$recipientsSql}) rec ON rec.rule_id = r.id
            {$whereSql}
        ";

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$fromSql}");
        $this->bindParams($countStmt, $params);
        $countStmt->execute();

        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare("
            SELECT
                r.*,
                rec.recipient_names,
                rec.recipient_emails
            {$fromSql}
            ORDER BY r.enabled DESC, r.updated_at DESC, r.id DESC
            LIMIT :limit_rows OFFSET :offset_rows
        ");
        $this->bindParams($stmt, $params);
        $stmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(),
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function getRuleById(int $id): ?array
    {
        if (!$this->tableExists('notification_rules')) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM notification_rules WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['recipient_user_ids'] = $this->getRuleRecipientIds($id);
        return $row;
    }

    public function createRule(array $data, array $recipientUserIds): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            INSERT INTO notification_rules (
                name,
                entity_type,
                event_type,
                document_type,
                days_before,
                threshold_km,
                threshold_tread_depth,
                channel,
                recipient_mode,
                enabled,
                repeat_until_resolved,
                daily_limit_enabled,
                metadata_json,
                created_at,
                updated_at
            ) VALUES (
                :name,
                :entity_type,
                :event_type,
                :document_type,
                :days_before,
                :threshold_km,
                :threshold_tread_depth,
                :channel,
                :recipient_mode,
                :enabled,
                :repeat_until_resolved,
                :daily_limit_enabled,
                :metadata_json,
                :created_at,
                :updated_at
            )
        ");
        $this->bindRuleData($stmt, $data, $now);
        $stmt->execute();

        $id = (int) $this->db->lastInsertId();
        $this->replaceRuleRecipients($id, $recipientUserIds);

        return $id;
    }

    public function updateRule(int $id, array $data, array $recipientUserIds): void
    {
        $stmt = $this->db->prepare("
            UPDATE notification_rules
            SET name = :name,
                entity_type = :entity_type,
                event_type = :event_type,
                document_type = :document_type,
                days_before = :days_before,
                threshold_km = :threshold_km,
                threshold_tread_depth = :threshold_tread_depth,
                channel = :channel,
                recipient_mode = :recipient_mode,
                enabled = :enabled,
                repeat_until_resolved = :repeat_until_resolved,
                daily_limit_enabled = :daily_limit_enabled,
                metadata_json = :metadata_json,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $this->bindRuleData($stmt, $data, date('Y-m-d H:i:s'), false);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $this->replaceRuleRecipients($id, $recipientUserIds);
    }

    public function setRuleEnabled(int $id, bool $enabled): void
    {
        $stmt = $this->db->prepare("
            UPDATE notification_rules
            SET enabled = :enabled,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->bindValue(':enabled', $enabled ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function deleteRule(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM notification_rules WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function getActiveEmailUsers(): array
    {
        $stmt = $this->db->query("
            SELECT id, nume, email, rol
            FROM utilizatori
            WHERE status = 'activ'
              AND email IS NOT NULL
              AND email <> ''
            ORDER BY rol = 'admin' DESC, nume ASC
        ");

        return $stmt->fetchAll();
    }

    public function getVehicleDocumentTypes(): array
    {
        if (!$this->tableExists('documente')) {
            return [];
        }

        $stmt = $this->db->query("
            SELECT DISTINCT tip_document
            FROM documente
            WHERE COALESCE(tip_document, '') <> ''
            ORDER BY tip_document ASC
        ");

        return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    public function getDriverDocumentTypes(): array
    {
        if (!$this->tableExists('documente_soferi')) {
            return [];
        }

        $stmt = $this->db->query("
            SELECT DISTINCT tip_document
            FROM documente_soferi
            WHERE COALESCE(tip_document, '') <> ''
            ORDER BY tip_document ASC
        ");

        return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    public function getEquipmentTypes(): array
    {
        if (!$this->tableExists('inventar_dotari_catalog')) {
            return [];
        }

        $stmt = $this->db->query("
            SELECT DISTINCT nume
            FROM inventar_dotari_catalog
            WHERE COALESCE(nume, '') <> ''
            ORDER BY nume ASC
        ");

        return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    public function getDeliveryHistory(array $filters, int $page, int $perPage): array
    {
        if (!$this->tableExists('notification_deliveries')) {
            return [
                'rows' => [],
                'total_rows' => 0,
                'total_pages' => 1,
                'page' => 1,
                'per_page' => $perPage,
            ];
        }

        $where = [];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(recipient_email LIKE :q OR subject LIKE :q OR COALESCE(error_message, '') LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['sent', 'failed', 'skipped', 'pending'], true)) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }

        $context = trim((string) ($filters['context'] ?? ''));
        if ($context !== '') {
            $where[] = 'context = :context';
            $params[':context'] = $context;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM notification_deliveries {$whereSql}");
        $this->bindParams($countStmt, $params);
        $countStmt->execute();

        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare("
            SELECT *
            FROM notification_deliveries
            {$whereSql}
            ORDER BY created_at DESC, id DESC
            LIMIT :limit_rows OFFSET :offset_rows
        ");
        $this->bindParams($stmt, $params);
        $stmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(),
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function getQueueRows(array $filters, int $page, int $perPage): array
    {
        if (!$this->tableExists('notification_queue') || !$this->tableExists('notification_deliveries')) {
            return [
                'rows' => [],
                'total_rows' => 0,
                'total_pages' => 1,
                'page' => 1,
                'per_page' => $perPage,
            ];
        }

        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(d.recipient_email LIKE :q OR d.subject LIKE :q OR COALESCE(q.last_error, '') LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['pending', 'processing', 'sent', 'failed'], true)) {
            $where[] = 'q.status = :status';
            $params[':status'] = $status;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $fromSql = "
            FROM notification_queue q
            INNER JOIN notification_deliveries d ON d.id = q.delivery_id
            {$whereSql}
        ";

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$fromSql}");
        $this->bindParams($countStmt, $params);
        $countStmt->execute();

        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare("
            SELECT
                q.*,
                d.context,
                d.context_id,
                d.channel,
                d.recipient_email,
                d.recipient_name,
                d.subject,
                d.status AS delivery_status,
                d.error_message
            {$fromSql}
            ORDER BY q.scheduled_for DESC, q.id DESC
            LIMIT :limit_rows OFFSET :offset_rows
        ");
        $this->bindParams($stmt, $params);
        $stmt->bindValue(':limit_rows', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset_rows', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows' => $stmt->fetchAll(),
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function getStats(): array
    {
        $stats = [
            'active_rules' => 0,
            'total_rules' => 0,
            'pending_queue' => 0,
            'sent_today' => 0,
            'failed_today' => 0,
            'last_delivery_at' => null,
            'last_worker_at' => null,
            'last_error' => null,
        ];

        if ($this->tableExists('notification_rules')) {
            $row = $this->db
                ->query('SELECT COUNT(*) AS total_rules, SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS active_rules FROM notification_rules')
                ->fetch();
            if (is_array($row)) {
                $stats['total_rules'] = (int) ($row['total_rules'] ?? 0);
                $stats['active_rules'] = (int) ($row['active_rules'] ?? 0);
            }
        }

        if ($this->tableExists('notification_queue')) {
            $stats['pending_queue'] = (int) $this->db
                ->query("SELECT COUNT(*) FROM notification_queue WHERE status IN ('pending', 'processing')")
                ->fetchColumn();

            $workerAt = $this->db
                ->query("SELECT MAX(updated_at) FROM notification_queue")
                ->fetchColumn();
            $stats['last_worker_at'] = $workerAt ?: null;
        }

        if (!$this->tableExists('notification_deliveries')) {
            return $stats;
        }

        $stats['sent_today'] = (int) $this->db
            ->query("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'sent' AND DATE(COALESCE(sent_at, created_at)) = CURDATE()")
            ->fetchColumn();
        $stats['failed_today'] = (int) $this->db
            ->query("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'failed' AND DATE(created_at) = CURDATE()")
            ->fetchColumn();

        $lastStmt = $this->db->query("
            SELECT created_at
            FROM notification_deliveries
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stats['last_delivery_at'] = $lastStmt->fetchColumn() ?: null;
        if ($stats['last_worker_at'] === null) {
            $stats['last_worker_at'] = $stats['last_delivery_at'];
        }

        $errorStmt = $this->db->query("
            SELECT error_message
            FROM notification_deliveries
            WHERE COALESCE(error_message, '') <> ''
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $stats['last_error'] = $errorStmt->fetchColumn() ?: null;

        return $stats;
    }

    public function getServiceStatus(): array
    {
        $status = [
            'smtp_configured' => defined('MAIL_HOST') && trim((string) MAIL_HOST) !== '' && defined('MAIL_FROM_ADDRESS') && trim((string) MAIL_FROM_ADDRESS) !== '',
            'queue_counts' => [
                'pending' => 0,
                'processing' => 0,
                'sent' => 0,
                'failed' => 0,
            ],
            'last_worker_at' => null,
            'last_error' => null,
        ];

        if ($this->tableExists('notification_queue')) {
            $stmt = $this->db->query("
                SELECT status, COUNT(*) AS total
                FROM notification_queue
                GROUP BY status
            ");
            foreach ($stmt->fetchAll() as $row) {
                $key = (string) ($row['status'] ?? '');
                if (array_key_exists($key, $status['queue_counts'])) {
                    $status['queue_counts'][$key] = (int) ($row['total'] ?? 0);
                }
            }

            $status['last_worker_at'] = $this->db
                ->query("SELECT MAX(updated_at) FROM notification_queue")
                ->fetchColumn() ?: null;
        }

        if ($this->tableExists('notification_deliveries')) {
            $status['last_error'] = $this->db
                ->query("SELECT error_message FROM notification_deliveries WHERE COALESCE(error_message, '') <> '' ORDER BY created_at DESC, id DESC LIMIT 1")
                ->fetchColumn() ?: null;
        }

        return $status;
    }

    public function queueEmail(
        string $email,
        string $name,
        string $subject,
        string $body,
        string $context,
        string $contextId,
        array $metadata = []
    ): array {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['queued' => false, 'error' => 'Adresa de email nu este valida.'];
        }

        if (!$this->tableExists('notification_deliveries') || !$this->tableExists('notification_queue')) {
            return ['queued' => false, 'error' => 'Schema de notificari nu este instalata.'];
        }

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $deliveryId = $this->createPendingDelivery(
                $context,
                $email,
                $name,
                $subject,
                $body,
                $contextId,
                $metadata
            );
            $this->createQueueJob($deliveryId, $this->deliveryDedupeKey($contextId, $email));

            if ($startedTransaction) {
                $this->db->commit();
            }

            return ['queued' => true, 'delivery_id' => $deliveryId];
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return ['queued' => false, 'error' => $exception->getMessage()];
        }
    }

    private function getEnabledRules(): array
    {
        $stmt = $this->db->query("
            SELECT *
            FROM notification_rules
            WHERE enabled = 1
            ORDER BY id ASC
        ");

        return $stmt->fetchAll();
    }

    private function fetchMatchesForRule(array $rule): array
    {
        return match ((string) ($rule['event_type'] ?? '')) {
            'vehicle_document_expiry' => $this->fetchVehicleDocumentMatches($rule),
            'driver_document_expiry' => $this->fetchDriverDocumentMatches($rule),
            default => [],
        };
    }

    private function fetchVehicleDocumentMatches(array $rule): array
    {
        $daysBefore = max(0, (int) ($rule['days_before'] ?? 30));
        $where = [
            'd.data_expirare IS NOT NULL',
            'd.data_expirare <= :max_date',
            "v.status = 'activ'",
        ];
        $params = [':max_date' => date('Y-m-d', strtotime('+' . $daysBefore . ' days'))];
        $documentType = trim((string) ($rule['document_type'] ?? ''));
        if ($documentType !== '') {
            $where[] = 'd.tip_document = :document_type';
            $params[':document_type'] = $documentType;
        }

        $stmt = $this->db->prepare("
            SELECT
                d.id AS entity_id,
                d.id AS document_id,
                'vehicle' AS entity_type,
                d.tip_document,
                d.numar_document,
                d.data_expirare,
                DATEDIFF(d.data_expirare, CURDATE()) AS days_left,
                v.id AS owner_id,
                v.nr_inmatriculare AS owner_name,
                CONCAT(v.marca, ' ', v.model) AS owner_details
            FROM documente d
            INNER JOIN vehicule v ON v.id = d.vehicle_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY d.data_expirare ASC, d.id ASC
            LIMIT 200
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function fetchDriverDocumentMatches(array $rule): array
    {
        $daysBefore = max(0, (int) ($rule['days_before'] ?? 30));
        $where = [
            'd.data_expirare IS NOT NULL',
            'd.data_expirare <= :max_date',
            "s.status = 'activ'",
        ];
        $params = [':max_date' => date('Y-m-d', strtotime('+' . $daysBefore . ' days'))];
        $documentType = trim((string) ($rule['document_type'] ?? ''));
        if ($documentType !== '') {
            $where[] = 'd.tip_document = :document_type';
            $params[':document_type'] = $documentType;
        }

        $stmt = $this->db->prepare("
            SELECT
                d.id AS entity_id,
                d.id AS document_id,
                'driver' AS entity_type,
                d.tip_document,
                d.numar_document,
                d.data_expirare,
                DATEDIFF(d.data_expirare, CURDATE()) AS days_left,
                s.id AS owner_id,
                s.nume AS owner_name,
                COALESCE(v.nr_inmatriculare, '') AS owner_details
            FROM documente_soferi d
            INNER JOIN soferi s ON s.id = d.driver_id
            LEFT JOIN vehicule v ON v.id = s.vehicle_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY d.data_expirare ASC, d.id ASC
            LIMIT 200
        ");
        $this->bindParams($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function resolveRecipients(array $rule): array
    {
        $mode = (string) ($rule['recipient_mode'] ?? 'admins');
        if ($mode === 'specific_users') {
            $stmt = $this->db->prepare("
                SELECT u.id, u.nume, u.email, u.rol
                FROM notification_rule_recipients rr
                INNER JOIN utilizatori u ON u.id = rr.user_id
                WHERE rr.rule_id = :rule_id
                  AND u.status = 'activ'
                  AND u.email IS NOT NULL
                  AND u.email <> ''
                ORDER BY u.nume ASC
            ");
            $stmt->bindValue(':rule_id', (int) $rule['id'], PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        }

        $stmt = $this->db->query("
            SELECT id, nume, email, rol
            FROM utilizatori
            WHERE status = 'activ'
              AND rol = 'admin'
              AND email IS NOT NULL
              AND email <> ''
            ORDER BY nume ASC
        ");

        return $stmt->fetchAll();
    }

    private function deliveryContextId(array $rule, array $match): string
    {
        return implode(':', [
            'rule',
            (int) $rule['id'],
            (string) $rule['event_type'],
            (string) $match['entity_type'],
            (int) $match['entity_id'],
            date('Y-m-d'),
        ]);
    }

    private function deliveryDedupeKey(string $contextId, string $email): string
    {
        return hash('sha256', $contextId . '|' . strtolower(trim($email)));
    }

    private function findExistingQueue(string $contextId, string $email): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                q.id AS queue_id,
                q.status AS queue_status,
                d.id AS delivery_id,
                d.status AS delivery_status
            FROM notification_deliveries d
            LEFT JOIN notification_queue q ON q.delivery_id = d.id
            WHERE d.context = 'fleet_rule'
              AND d.context_id = :context_id
              AND d.recipient_email = :recipient_email
            ORDER BY d.id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':context_id' => $contextId,
            ':recipient_email' => $email,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function createPendingDelivery(
        string $context,
        string $email,
        string $name,
        string $subject,
        string $body,
        string $contextId,
        array $metadata
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO notification_deliveries (
                context,
                context_id,
                channel,
                recipient_email,
                recipient_name,
                subject,
                message,
                status,
                provider,
                metadata_json,
                created_at
            ) VALUES (
                :context,
                :context_id,
                'email',
                :recipient_email,
                :recipient_name,
                :subject,
                :message,
                'pending',
                'smtp',
                :metadata_json,
                NOW()
            )
        ");
        $stmt->execute([
            ':context' => $this->limitString($context, 80),
            ':context_id' => $this->limitString($contextId, 160),
            ':recipient_email' => $this->limitString($email, 190),
            ':recipient_name' => $this->nullableLimitedString($name, 190),
            ':subject' => $this->limitString($subject, 255),
            ':message' => $body,
            ':metadata_json' => $this->encodeJson($metadata),
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function createQueueJob(int $deliveryId, string $dedupeKey): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO notification_queue (
                delivery_id,
                dedupe_key,
                status,
                attempts,
                max_attempts,
                scheduled_for,
                created_at,
                updated_at
            ) VALUES (
                :delivery_id,
                :dedupe_key,
                'pending',
                0,
                3,
                NOW(),
                NOW(),
                NOW()
            )
        ");
        $stmt->execute([
            ':delivery_id' => $deliveryId,
            ':dedupe_key' => $dedupeKey,
        ]);
    }

    private function requeueExistingDelivery(int $queueId, int $deliveryId): void
    {
        $queueStmt = $this->db->prepare("
            UPDATE notification_queue
            SET status = 'pending',
                attempts = 0,
                scheduled_for = NOW(),
                locked_at = NULL,
                last_error = NULL,
                updated_at = NOW()
            WHERE id = :id
        ");
        $queueStmt->bindValue(':id', $queueId, PDO::PARAM_INT);
        $queueStmt->execute();

        $deliveryStmt = $this->db->prepare("
            UPDATE notification_deliveries
            SET status = 'pending',
                error_message = NULL,
                provider_response = NULL,
                sent_at = NULL
            WHERE id = :id
        ");
        $deliveryStmt->bindValue(':id', $deliveryId, PDO::PARAM_INT);
        $deliveryStmt->execute();
    }

    private function buildNotificationEmail(array $rule, array $match, array $recipient): array
    {
        $daysLeft = (int) ($match['days_left'] ?? 0);
        $documentType = (string) ($match['tip_document'] ?? 'Document');
        $ownerName = (string) ($match['owner_name'] ?? '');
        $ownerDetails = trim((string) ($match['owner_details'] ?? ''));
        $number = trim((string) ($match['numar_document'] ?? ''));
        $recipientName = trim((string) ($recipient['nume'] ?? 'utilizator'));
        $status = $this->expiryStatusLabel($daysLeft);
        $entityLabel = (string) ($match['entity_type'] ?? '') === 'driver' ? 'Sofer' : 'Vehicul';
        $page = (string) ($match['entity_type'] ?? '') === 'driver' ? 'documente_soferi' : 'documente';
        $url = absolute_url('index.php?page=' . $page . '&action=show&id=' . (int) $match['document_id']);

        $subject = '[' . APP_NAME . '] ' . $status . ': ' . $documentType . ' - ' . $ownerName;
        $body = "Salut, {$recipientName},\n\n";
        $body .= "Exista o notificare pentru un document din flota.\n\n";
        $body .= "{$entityLabel}: {$ownerName}\n";
        if ($ownerDetails !== '') {
            $body .= "Detalii: {$ownerDetails}\n";
        }
        $body .= "Tip document: {$documentType}\n";
        $body .= "Serie / numar: " . ($number !== '' ? $number : '-') . "\n";
        $body .= "Data expirare: " . $this->formatDate((string) ($match['data_expirare'] ?? '')) . "\n";
        $body .= "Status: {$status}\n\n";
        $body .= "Deschide in aplicatie:\n{$url}\n\n";
        $body .= APP_NAME;

        return [$subject, $body];
    }

    private function expiryStatusLabel(int $daysLeft): string
    {
        if ($daysLeft < 0) {
            return 'Expirat de ' . abs($daysLeft) . ' zile';
        }

        if ($daysLeft === 0) {
            return 'Expira astazi';
        }

        return 'Expira in ' . $daysLeft . ' zile';
    }

    private function formatDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp !== false ? date('d.m.Y', $timestamp) : $value;
    }

    private function getRuleRecipientIds(int $ruleId): array
    {
        if (!$this->tableExists('notification_rule_recipients')) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT user_id
            FROM notification_rule_recipients
            WHERE rule_id = :rule_id
            ORDER BY user_id ASC
        ");
        $stmt->bindValue(':rule_id', $ruleId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function replaceRuleRecipients(int $ruleId, array $recipientUserIds): void
    {
        $deleteStmt = $this->db->prepare('DELETE FROM notification_rule_recipients WHERE rule_id = :rule_id');
        $deleteStmt->bindValue(':rule_id', $ruleId, PDO::PARAM_INT);
        $deleteStmt->execute();

        if ($recipientUserIds === []) {
            return;
        }

        $insertStmt = $this->db->prepare("
            INSERT INTO notification_rule_recipients (rule_id, user_id, created_at)
            VALUES (:rule_id, :user_id, NOW())
        ");

        foreach ($recipientUserIds as $userId) {
            $insertStmt->bindValue(':rule_id', $ruleId, PDO::PARAM_INT);
            $insertStmt->bindValue(':user_id', (int) $userId, PDO::PARAM_INT);
            $insertStmt->execute();
        }
    }

    private function bindRuleData(PDOStatement $stmt, array $data, string $now, bool $includeCreatedAt = true): void
    {
        $stmt->bindValue(':name', (string) $data['name']);
        $stmt->bindValue(':entity_type', (string) ($data['entity_type'] ?? 'vehicle'));
        $stmt->bindValue(':event_type', (string) $data['event_type']);
        $this->bindNullableString($stmt, ':document_type', $data['document_type'] ?? null);
        $stmt->bindValue(':days_before', max(0, (int) ($data['days_before'] ?? 30)), PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':threshold_km', $data['threshold_km'] ?? null);
        $this->bindNullableDecimal($stmt, ':threshold_tread_depth', $data['threshold_tread_depth'] ?? null);
        $stmt->bindValue(':channel', (string) ($data['channel'] ?? 'email'));
        $stmt->bindValue(':recipient_mode', (string) $data['recipient_mode']);
        $stmt->bindValue(':enabled', (int) ($data['enabled'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':repeat_until_resolved', (int) ($data['repeat_until_resolved'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':daily_limit_enabled', (int) ($data['daily_limit_enabled'] ?? 0), PDO::PARAM_INT);
        $stmt->bindValue(':metadata_json', $this->encodeJson($data['metadata'] ?? []));
        $stmt->bindValue(':updated_at', $now);

        if ($includeCreatedAt) {
            $stmt->bindValue(':created_at', $now);
        }
    }

    private function ensureRuleSchemaExtensions(): void
    {
        if (!$this->tableExists('notification_rules')) {
            return;
        }

        try {
            $columns = $this->tableColumns('notification_rules');

            if (isset($columns['event_type']) && str_starts_with(strtolower($columns['event_type']), 'enum(')) {
                $this->db->exec('ALTER TABLE notification_rules MODIFY event_type VARCHAR(80) NOT NULL');
            }

            if (isset($columns['recipient_mode']) && str_starts_with(strtolower($columns['recipient_mode']), 'enum(')) {
                $this->db->exec("ALTER TABLE notification_rules MODIFY recipient_mode VARCHAR(40) NOT NULL DEFAULT 'admins'");
            }

            $columns = $this->tableColumns('notification_rules');
            $this->addColumnIfMissing($columns, 'notification_rules', 'entity_type', "VARCHAR(40) NOT NULL DEFAULT 'vehicle' AFTER event_type");
            $this->addColumnIfMissing($columns, 'notification_rules', 'threshold_km', 'INT UNSIGNED NULL AFTER days_before');
            $this->addColumnIfMissing($columns, 'notification_rules', 'threshold_tread_depth', 'DECIMAL(5,2) NULL AFTER threshold_km');
            $this->addColumnIfMissing($columns, 'notification_rules', 'channel', "VARCHAR(30) NOT NULL DEFAULT 'email' AFTER threshold_tread_depth");
            $this->addColumnIfMissing($columns, 'notification_rules', 'repeat_until_resolved', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER enabled');
            $this->addColumnIfMissing($columns, 'notification_rules', 'daily_limit_enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER repeat_until_resolved');
            $this->addColumnIfMissing($columns, 'notification_rules', 'metadata_json', 'LONGTEXT NULL AFTER daily_limit_enabled');

            $this->db->exec("
                UPDATE notification_rules
                SET entity_type = CASE
                    WHEN event_type LIKE 'driver_%' THEN 'driver'
                    WHEN event_type LIKE 'tire_%' THEN 'tire'
                    WHEN event_type LIKE 'equipment_%' THEN 'equipment'
                    WHEN event_type LIKE 'leave_%' THEN 'leave'
                    ELSE 'vehicle'
                END
                WHERE entity_type IS NULL
                   OR entity_type = ''
                   OR event_type IN ('vehicle_document_expiry', 'driver_document_expiry')
            ");
        } catch (Throwable $exception) {
            error_log('[NotificationRuleModel] Nu am putut actualiza schema notificari: ' . $exception->getMessage());
        }
    }

    private function addColumnIfMissing(array $columns, string $table, string $column, string $definition): void
    {
        if (array_key_exists($column, $columns)) {
            return;
        }

        $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    private function tableColumns(string $table): array
    {
        $stmt = $this->db->prepare("
            SELECT COLUMN_NAME, COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
        ");
        $stmt->execute([':table' => $table]);

        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $columns[(string) $row['COLUMN_NAME']] = (string) $row['COLUMN_TYPE'];
        }

        return $columns;
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

        $stmt->bindValue($key, max(0, (int) $value), PDO::PARAM_INT);
    }

    private function bindNullableDecimal(PDOStatement $stmt, string $key, mixed $value): void
    {
        if ($value === null || trim((string) $value) === '') {
            $stmt->bindValue($key, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($key, number_format(max(0, (float) $value), 2, '.', ''));
    }

    private function nullableLimitedString(mixed $value, int $limit): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $this->limitString($value, $limit);
    }

    private function limitString(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : null;
    }

    private function bindParams(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
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
}
