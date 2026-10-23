<?php
declare(strict_types=1);

require_once __DIR__ . '/NotificationRuleModel.php';

/**
 * Notificari WhatsApp catre soferi (prima miscare a zilei) si suport prin WhatsApp + email.
 *
 * Tabele (migrarea oficiala: database/migrations/2026_10_19_000002_whatsapp_soferi_suport.sql;
 * ensureSchema() doar tine scripturile in picioare pe o baza inca nemigrata):
 *
 *   vehicle_movement_days          o verificare GPS pe vehicul + zi (prima miscare, km, decizie)
 *   driver_movement_notifications  o notificare pe sofer + vehicul + zi (UNIQUE: idempotenta)
 *   whatsapp_outbox                mesajele de trimis de BaileyBot (UNIQUE dedupe_key)
 *   whatsapp_inbound_messages      mesajele primite de la soferi (UNIQUE Message ID)
 *   driver_whatsapp_conversations  starea conversatiei per sofer (ex. asteapta descrierea problemei)
 *   driver_support_tickets         tichetele de suport (#SUP-000123)
 *   driver_support_messages        istoricul tichetului: WhatsApp -> email si email -> WhatsApp
 *
 * Telefonul nu se copiaza in aceste tabele: se ia la trimitere din soferi.telefon.
 */
class DriverWhatsAppModel extends BaseModel
{
    private static bool $schemaEnsured = false;

    /** Un mesaj "sending" mai vechi de atat (bot cazut la mijloc) se poate revendica din nou. */
    public const OUTBOX_LOCK_MINUTES = 5;

    public const OUTBOX_MAX_ATTEMPTS = 5;

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    /** @return array<int, string> aceleasi instructiuni ca in migrare */
    public static function schemaStatements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS vehicle_movement_days (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                local_date DATE NOT NULL,
                sas_car_id INT UNSIGNED NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'below_threshold',
                movement_km DECIMAL(8,2) NOT NULL DEFAULT 0,
                first_movement_at DATETIME NULL,
                threshold_reached_at DATETIME NULL,
                trigger_json JSON NULL,
                detail VARCHAR(255) NULL,
                is_simulation TINYINT(1) NOT NULL DEFAULT 0,
                checks INT UNSIGNED NOT NULL DEFAULT 0,
                last_checked_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_vehicle_movement_day (vehicle_id, local_date),
                KEY idx_vehicle_movement_day_status (local_date, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS driver_movement_notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                driver_id INT UNSIGNED NOT NULL,
                vehicle_id INT UNSIGNED NOT NULL,
                local_date DATE NOT NULL,
                movement_day_id INT UNSIGNED NULL,
                trigger_json JSON NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'queued',
                outbox_id INT UNSIGNED NULL,
                wa_message_id VARCHAR(64) NULL,
                sent_at DATETIME NULL,
                response_action VARCHAR(40) NULL,
                response_text VARCHAR(255) NULL,
                responded_at DATETIME NULL,
                confirmed_at DATETIME NULL,
                last_error VARCHAR(255) NULL,
                is_simulation TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_driver_movement_notification (driver_id, vehicle_id, local_date),
                KEY idx_driver_movement_notification_day (driver_id, local_date),
                KEY idx_driver_movement_notification_wa (wa_message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS whatsapp_outbox (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                dedupe_key VARCHAR(120) NOT NULL,
                purpose VARCHAR(40) NOT NULL,
                driver_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NULL,
                body TEXT NOT NULL,
                options_json JSON NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
                next_attempt_at DATETIME NOT NULL,
                lock_token CHAR(32) NULL,
                locked_at DATETIME NULL,
                wa_message_id VARCHAR(64) NULL,
                last_error VARCHAR(255) NULL,
                related_type VARCHAR(40) NULL,
                related_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                sent_at DATETIME NULL,
                UNIQUE KEY uq_whatsapp_outbox_dedupe (dedupe_key),
                KEY idx_whatsapp_outbox_ready (status, next_attempt_at),
                KEY idx_whatsapp_outbox_related (related_type, related_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS whatsapp_inbound_messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                wa_message_id VARCHAR(64) NOT NULL,
                driver_id INT UNSIGNED NULL,
                kind VARCHAR(20) NOT NULL,
                action_id VARCHAR(40) NULL,
                text_content TEXT NULL,
                outcome VARCHAR(40) NOT NULL DEFAULT 'received',
                ticket_id INT UNSIGNED NULL,
                notification_id INT UNSIGNED NULL,
                received_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_whatsapp_inbound_message (wa_message_id),
                KEY idx_whatsapp_inbound_driver (driver_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS driver_whatsapp_conversations (
                driver_id INT UNSIGNED NOT NULL PRIMARY KEY,
                state VARCHAR(40) NOT NULL DEFAULT 'idle',
                notification_id INT UNSIGNED NULL,
                expires_at DATETIME NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS driver_support_tickets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reference VARCHAR(20) NULL,
                driver_id INT UNSIGNED NOT NULL,
                vehicle_id INT UNSIGNED NULL,
                notification_id INT UNSIGNED NULL,
                description TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                email_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                email_message_id VARCHAR(255) NULL,
                email_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                email_next_attempt_at DATETIME NULL,
                email_last_error VARCHAR(255) NULL,
                email_sent_at DATETIME NULL,
                source_wa_message_id VARCHAR(64) NULL,
                last_reply_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_driver_support_ticket_reference (reference),
                UNIQUE KEY uq_driver_support_ticket_source (source_wa_message_id),
                UNIQUE KEY uq_driver_support_ticket_email (email_message_id),
                KEY idx_driver_support_ticket_driver (driver_id, status),
                KEY idx_driver_support_ticket_email_status (email_status, email_next_attempt_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS driver_support_messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT UNSIGNED NULL,
                direction VARCHAR(30) NOT NULL,
                source_message_id VARCHAR(255) NOT NULL,
                sender VARCHAR(255) NULL,
                content TEXT NULL,
                delivery_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                email_message_id VARCHAR(255) NULL,
                outbox_id INT UNSIGNED NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                last_error VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                delivered_at DATETIME NULL,
                UNIQUE KEY uq_driver_support_message_source (direction, source_message_id),
                KEY idx_driver_support_message_ticket (ticket_id, created_at),
                KEY idx_driver_support_message_status (direction, delivery_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    public function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        foreach (self::schemaStatements() as $statement) {
            $this->db->exec($statement);
        }
        // Outbox creat inainte de mesajele catre utilizatori (aprobari): destinatar sofer SAU utilizator.
        $hasUserId = (int) $this->db->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_outbox' AND COLUMN_NAME = 'user_id'
        ")->fetchColumn();
        if ($hasUserId === 0) {
            $this->db->exec('ALTER TABLE whatsapp_outbox MODIFY driver_id INT UNSIGNED NULL, ADD COLUMN user_id INT UNSIGNED NULL AFTER driver_id');
        }
        self::$schemaEnsured = true;
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    // -------------------------------------------------------------------------
    // Soferi: identificare, eligibilitate, alocare pe vehicul
    // -------------------------------------------------------------------------

    /** Sofer activ: aceeasi regula ca notificarile (status) + modulele de personal (employment_status). */
    private const ACTIVE_DRIVER_SQL = "s.status = 'activ' AND COALESCE(s.employment_status, 'active') <> 'terminated'";

    /** @return array{id:int, nume:string, telefon:string, phone_e164:?string}|null */
    public function getActiveDriver(int $driverId): ?array
    {
        $stmt = $this->db->prepare('SELECT s.id, s.nume, s.telefon FROM soferi s WHERE s.id = :id AND ' . self::ACTIVE_DRIVER_SQL);
        $stmt->execute([':id' => $driverId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'nume' => (string) $row['nume'],
            'telefon' => (string) $row['telefon'],
            'phone_e164' => NotificationRuleModel::normalizePhone((string) $row['telefon']),
        ];
    }

    /**
     * Soferul activ cu acest numar (E.164). Doi soferi activi cu acelasi numar = ambiguu -> null.
     *
     * @return array{id:int, nume:string}|null
     */
    public function findActiveDriverByPhone(string $phoneE164): ?array
    {
        $rows = $this->db->query('SELECT s.id, s.nume, s.telefon FROM soferi s WHERE ' . self::ACTIVE_DRIVER_SQL)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $matches = array_values(array_filter($rows, static fn(array $r): bool => NotificationRuleModel::normalizePhone((string) $r['telefon']) === $phoneE164));
        if (count($matches) !== 1) {
            return null;
        }

        return ['id' => (int) $matches[0]['id'], 'nume' => (string) $matches[0]['nume']];
    }

    /**
     * Alocarea implicita a vehiculului (soferi_vehicule), doar soferii activi, principalul primul.
     *
     * @return array<int, array{driver_id:int, is_primary:bool}>
     */
    public function getDefaultDriversForVehicle(int $vehicleId): array
    {
        $stmt = $this->db->prepare('
            SELECT sv.driver_id, sv.is_primary
            FROM soferi_vehicule sv
            JOIN soferi s ON s.id = sv.driver_id
            WHERE sv.vehicle_id = :vehicle_id AND ' . self::ACTIVE_DRIVER_SQL . '
            ORDER BY sv.is_primary DESC, sv.driver_id ASC
        ');
        $stmt->execute([':vehicle_id' => $vehicleId]);

        return array_map(static fn(array $r): array => ['driver_id' => (int) $r['driver_id'], 'is_primary' => (int) $r['is_primary'] === 1], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<int, int> vehiculele unui sofer din alocarea implicita */
    public function getDefaultVehicleIdsForDriver(int $driverId): array
    {
        $stmt = $this->db->prepare('SELECT vehicle_id FROM soferi_vehicule WHERE driver_id = :driver_id');
        $stmt->execute([':driver_id' => $driverId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function getVehiclePlate(int $vehicleId): ?string
    {
        $stmt = $this->db->prepare('SELECT nr_inmatriculare FROM vehicule WHERE id = :id');
        $stmt->execute([':id' => $vehicleId]);
        $plate = $stmt->fetchColumn();

        return $plate === false ? null : (string) $plate;
    }

    // -------------------------------------------------------------------------
    // Verificarea GPS pe vehicul + zi
    // -------------------------------------------------------------------------

    public function getMovementDay(int $vehicleId, string $date): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vehicle_movement_days WHERE vehicle_id = :v AND local_date = :d');
        $stmt->execute([':v' => $vehicleId, ':d' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Salveaza rezultatul unei verificari. Un "triggered" nu se mai suprascrie (prima miscare
     * a zilei ramane cea detectata prima data, chiar daca datele GPS tarzii o muta).
     *
     * @param array<string, mixed> $data status, movement_km, first_movement_at, threshold_reached_at, trigger, detail, sas_car_id, is_simulation
     */
    public function saveMovementDay(int $vehicleId, string $date, array $data): int
    {
        $now = self::now();
        $stmt = $this->db->prepare("
            INSERT INTO vehicle_movement_days
                (vehicle_id, local_date, sas_car_id, status, movement_km, first_movement_at, threshold_reached_at,
                 trigger_json, detail, is_simulation, checks, last_checked_at, created_at)
            VALUES (:v, :d, :car, :status, :km, :first, :reached, :trigger, :detail, :sim, 1, :now, :now2)
            ON DUPLICATE KEY UPDATE
                checks = checks + 1,
                last_checked_at = VALUES(last_checked_at),
                sas_car_id = COALESCE(VALUES(sas_car_id), sas_car_id),
                movement_km = IF(status = 'triggered', movement_km, VALUES(movement_km)),
                first_movement_at = IF(status = 'triggered', first_movement_at, VALUES(first_movement_at)),
                threshold_reached_at = IF(status = 'triggered', threshold_reached_at, VALUES(threshold_reached_at)),
                trigger_json = IF(status = 'triggered', trigger_json, VALUES(trigger_json)),
                detail = VALUES(detail),
                is_simulation = IF(status = 'triggered', is_simulation, VALUES(is_simulation)),
                status = IF(status = 'triggered', status, VALUES(status))
        ");
        $stmt->execute([
            ':v' => $vehicleId,
            ':d' => $date,
            ':car' => $data['sas_car_id'] ?? null,
            ':status' => (string) $data['status'],
            ':km' => number_format((float) ($data['movement_km'] ?? 0), 2, '.', ''),
            ':first' => $data['first_movement_at'] ?? null,
            ':reached' => $data['threshold_reached_at'] ?? null,
            ':trigger' => isset($data['trigger']) ? json_encode($data['trigger'], JSON_UNESCAPED_UNICODE) : null,
            ':detail' => isset($data['detail']) ? mb_substr((string) $data['detail'], 0, 255) : null,
            ':sim' => !empty($data['is_simulation']) ? 1 : 0,
            ':now' => $now,
            ':now2' => $now,
        ]);

        return (int) ($this->getMovementDay($vehicleId, $date)['id'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // Notificari (o singura data pe sofer + vehicul + zi)
    // -------------------------------------------------------------------------

    /**
     * Creeaza notificarea si mesajul din outbox, in aceeasi tranzactie. Daca exista deja
     * (alt worker, repornire), nu face nimic: UNIQUE(driver_id, vehicle_id, local_date).
     *
     * @param array<string, mixed> $trigger
     * @param array<int, array{id:string, title:string}> $options
     * @return int|null id-ul notificarii noi, null daca exista deja
     */
    public function createNotificationWithOutbox(int $driverId, int $vehicleId, string $date, ?int $movementDayId, array $trigger, string $body, array $options, bool $isSimulation): ?int
    {
        $now = self::now();
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare('
                INSERT IGNORE INTO driver_movement_notifications
                    (driver_id, vehicle_id, local_date, movement_day_id, trigger_json, status, is_simulation, created_at, updated_at)
                VALUES (:driver, :vehicle, :d, :day, :trigger, :status, :sim, :now, :now2)
            ');
            $stmt->execute([
                ':driver' => $driverId, ':vehicle' => $vehicleId, ':d' => $date, ':day' => $movementDayId,
                ':trigger' => json_encode($trigger, JSON_UNESCAPED_UNICODE), ':status' => 'queued',
                ':sim' => $isSimulation ? 1 : 0, ':now' => $now, ':now2' => $now,
            ]);
            if ($stmt->rowCount() === 0) {
                if ($ownTransaction) {
                    $this->db->commit();
                }

                return null;
            }
            $notificationId = (int) $this->db->lastInsertId();
            $outboxId = $this->enqueue('movement_prompt', $driverId, $body, $options, 'movement:' . $notificationId, 'notification', $notificationId);
            $this->db->prepare('UPDATE driver_movement_notifications SET outbox_id = :o WHERE id = :id')->execute([':o' => $outboxId, ':id' => $notificationId]);
            if ($ownTransaction) {
                $this->db->commit();
            }

            return $notificationId;
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function findNotification(int $driverId, int $vehicleId, string $date): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM driver_movement_notifications WHERE driver_id = :dr AND vehicle_id = :v AND local_date = :d');
        $stmt->execute([':dr' => $driverId, ':v' => $vehicleId, ':d' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function getNotification(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM driver_movement_notifications WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Cea mai recenta notificare a soferului din ziua data (oricare vehicul). */
    public function latestNotificationForDriver(int $driverId, string $date): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM driver_movement_notifications WHERE driver_id = :dr AND local_date = :d ORDER BY id DESC LIMIT 1');
        $stmt->execute([':dr' => $driverId, ':d' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function recordNotificationResponse(int $id, string $action, ?string $text): void
    {
        $now = self::now();
        $status = $action === 'driver_app_confirmed' ? 'confirmed' : 'support_requested';
        $stmt = $this->db->prepare('
            UPDATE driver_movement_notifications
            SET response_action = :action, response_text = :text, responded_at = :now, status = :status,
                confirmed_at = IF(:is_confirm = 1, COALESCE(confirmed_at, :now2), confirmed_at), updated_at = :now3
            WHERE id = :id
        ');
        $stmt->execute([
            ':action' => $action, ':text' => $text !== null ? mb_substr($text, 0, 255) : null, ':now' => $now,
            ':status' => $status, ':is_confirm' => $action === 'driver_app_confirmed' ? 1 : 0, ':now2' => $now, ':now3' => $now, ':id' => $id,
        ]);
    }

    // -------------------------------------------------------------------------
    // Outbox (BaileyBot revendica, trimite, raporteaza)
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array{id:string, title:string}> $options butoanele de raspuns (pot lipsi)
     * @param string $status 'pending' (se trimite) sau 'held' (asteapta sa fie eliberat, ex. confirmarea
     *                       tichetului dupa ce emailul a ajuns la suport)
     * @return int id-ul mesajului (cel existent, daca dedupe_key era deja folosit)
     */
    public function enqueue(string $purpose, int $driverId, string $body, array $options, string $dedupeKey, ?string $relatedType = null, ?int $relatedId = null, string $status = 'pending'): int
    {
        return $this->insertOutbox($purpose, $driverId, null, $body, $options, $dedupeKey, $relatedType, $relatedId, $status);
    }

    /** Mesaj catre un utilizator al aplicatiei (ex. administratorul care aproba), telefonul din utilizatori. */
    public function enqueueForUser(string $purpose, int $userId, string $body, array $options, string $dedupeKey, ?string $relatedType = null, ?int $relatedId = null): int
    {
        return $this->insertOutbox($purpose, null, $userId, $body, $options, $dedupeKey, $relatedType, $relatedId, 'pending');
    }

    private function insertOutbox(string $purpose, ?int $driverId, ?int $userId, string $body, array $options, string $dedupeKey, ?string $relatedType, ?int $relatedId, string $status): int
    {
        // Fara ensureSchema() aici: poate rula in tranzactie, iar DDL ar face commit implicit.
        $now = self::now();
        $stmt = $this->db->prepare('
            INSERT IGNORE INTO whatsapp_outbox
                (dedupe_key, purpose, driver_id, user_id, body, options_json, status, attempts, max_attempts, next_attempt_at, related_type, related_id, created_at, updated_at)
            VALUES (:key, :purpose, :driver, :user, :body, :options, :status, 0, :max, :now, :rtype, :rid, :now2, :now3)
        ');
        $stmt->execute([
            ':key' => mb_substr($dedupeKey, 0, 120), ':purpose' => $purpose, ':driver' => $driverId, ':user' => $userId, ':body' => $body,
            ':status' => $status === 'held' ? 'held' : 'pending',
            ':options' => $options === [] ? null : json_encode($options, JSON_UNESCAPED_UNICODE), ':max' => self::OUTBOX_MAX_ATTEMPTS,
            ':now' => $now, ':rtype' => $relatedType, ':rid' => $relatedId, ':now2' => $now, ':now3' => $now,
        ]);
        if ($stmt->rowCount() === 1) {
            return (int) $this->db->lastInsertId();
        }
        $existing = $this->db->prepare('SELECT id FROM whatsapp_outbox WHERE dedupe_key = :key');
        $existing->execute([':key' => mb_substr($dedupeKey, 0, 120)]);

        return (int) $existing->fetchColumn();
    }

    /**
     * Revendica mesajele gata de trimis (atomic: UPDATE ... LIMIT cu un lock_token nou).
     * Un mesaj ramas "sending" (bot oprit dupa revendicare) se poate relua dupa OUTBOX_LOCK_MINUTES;
     * botul il retrimite cu acelasi Message ID, deci telefonul nu il afiseaza de doua ori.
     *
     * @return array{lock_token: string, messages: array<int, array<string, mixed>>}
     */
    public function claimOutbox(int $limit): array
    {
        $token = bin2hex(random_bytes(16));
        $now = self::now();
        $stmt = $this->db->prepare("
            UPDATE whatsapp_outbox
            SET status = 'sending', lock_token = :token, locked_at = :now, attempts = attempts + 1, updated_at = :now2
            WHERE (status = 'pending' AND next_attempt_at <= :now3)
               OR (status = 'sending' AND locked_at < DATE_SUB(:now4, INTERVAL " . self::OUTBOX_LOCK_MINUTES . " MINUTE))
            ORDER BY id ASC
            LIMIT " . max(1, min(20, $limit))
        );
        $stmt->execute([':token' => $token, ':now' => $now, ':now2' => $now, ':now3' => $now, ':now4' => $now]);

        $rows = $this->db->prepare('
            SELECT o.id, o.purpose, o.driver_id, o.user_id, o.body, o.options_json, o.attempts, o.max_attempts,
                   COALESCE(s.telefon, u.telefon) AS telefon
            FROM whatsapp_outbox o
            LEFT JOIN soferi s ON s.id = o.driver_id
            LEFT JOIN utilizatori u ON u.id = o.user_id
            WHERE o.lock_token = :token
            ORDER BY o.id ASC
        ');
        $rows->execute([':token' => $token]);

        $messages = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $messages[] = [
                'id' => (int) $row['id'],
                'purpose' => (string) $row['purpose'],
                'driver_id' => $row['driver_id'] !== null ? (int) $row['driver_id'] : null,
                'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'phone' => NotificationRuleModel::normalizePhone((string) ($row['telefon'] ?? '')),
                'body' => (string) $row['body'],
                'options' => $row['options_json'] !== null ? (json_decode((string) $row['options_json'], true) ?: []) : [],
                'attempt' => (int) $row['attempts'],
            ];
        }

        return ['lock_token' => $token, 'messages' => $messages];
    }

    /**
     * Rezultatul trimiterii. Doar detinatorul lock-ului il poate raporta.
     *
     * @return bool false daca mesajul nu (mai) apartine acestui lock
     */
    public function reportOutboxResult(int $id, string $lockToken, bool $sent, ?string $waMessageId, ?string $error, bool $retryable): bool
    {
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_outbox WHERE id = :id AND lock_token = :token AND status = \'sending\'');
        $stmt->execute([':id' => $id, ':token' => $lockToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        $now = self::now();

        if ($sent) {
            $update = $this->db->prepare("UPDATE whatsapp_outbox SET status = 'sent', wa_message_id = :wa, sent_at = :now, last_error = NULL, lock_token = NULL, updated_at = :now2 WHERE id = :id");
            $update->execute([':wa' => $waMessageId, ':now' => $now, ':now2' => $now, ':id' => $id]);
        } else {
            $final = !$retryable || (int) $row['attempts'] >= (int) $row['max_attempts'];
            // 1, 2, 4, 8 minute intre incercari
            $delayMinutes = min(30, 2 ** max(0, (int) $row['attempts'] - 1));
            $update = $this->db->prepare("
                UPDATE whatsapp_outbox
                SET status = :status, last_error = :error, lock_token = NULL,
                    next_attempt_at = DATE_ADD(:now, INTERVAL {$delayMinutes} MINUTE), updated_at = :now2
                WHERE id = :id
            ");
            $update->execute([':status' => $final ? 'failed' : 'pending', ':error' => mb_substr((string) $error, 0, 255), ':now' => $now, ':now2' => $now, ':id' => $id]);
        }

        $this->propagateOutboxResult($row, $sent, $waMessageId, $error, $sent || !$retryable || (int) $row['attempts'] >= (int) $row['max_attempts']);

        return true;
    }

    /** Starea mesajului se reflecta in notificare / istoricul tichetului. */
    private function propagateOutboxResult(array $row, bool $sent, ?string $waMessageId, ?string $error, bool $final): void
    {
        $now = self::now();
        $relatedId = $row['related_id'] !== null ? (int) $row['related_id'] : null;
        if ($relatedId === null) {
            return;
        }

        if ($row['related_type'] === 'notification') {
            if ($sent) {
                $this->db->prepare("UPDATE driver_movement_notifications SET status = IF(status = 'queued' OR status = 'failed', 'sent', status), wa_message_id = :wa, sent_at = COALESCE(sent_at, :now), last_error = NULL, updated_at = :now2 WHERE id = :id")
                    ->execute([':wa' => $waMessageId, ':now' => $now, ':now2' => $now, ':id' => $relatedId]);
            } elseif ($final) {
                $this->db->prepare("UPDATE driver_movement_notifications SET status = IF(status = 'queued', 'failed', status), last_error = :e, updated_at = :now WHERE id = :id")
                    ->execute([':e' => mb_substr((string) $error, 0, 255), ':now' => $now, ':id' => $relatedId]);
            }
        } elseif ($row['related_type'] === 'support_message') {
            if ($sent) {
                $this->db->prepare("UPDATE driver_support_messages SET delivery_status = 'sent', delivered_at = :now, last_error = NULL, attempts = attempts + 1 WHERE id = :id")
                    ->execute([':now' => $now, ':id' => $relatedId]);
            } else {
                $this->db->prepare('UPDATE driver_support_messages SET delivery_status = :st, last_error = :e, attempts = attempts + 1 WHERE id = :id')
                    ->execute([':st' => $final ? 'failed' : 'pending', ':e' => mb_substr((string) $error, 0, 255), ':id' => $relatedId]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Mesaje primite
    // -------------------------------------------------------------------------

    /** @return int|null id-ul nou, null daca Message ID-ul a mai fost primit (WhatsApp / bot l-a retrimis) */
    public function logInbound(string $waMessageId, ?int $driverId, string $kind, ?string $actionId, ?string $text, string $receivedAt): ?int
    {
        $stmt = $this->db->prepare('
            INSERT IGNORE INTO whatsapp_inbound_messages (wa_message_id, driver_id, kind, action_id, text_content, outcome, received_at, created_at)
            VALUES (:wa, :driver, :kind, :action, :text, \'received\', :received, :now)
        ');
        $stmt->execute([
            ':wa' => mb_substr($waMessageId, 0, 64), ':driver' => $driverId, ':kind' => $kind, ':action' => $actionId,
            ':text' => $text !== null ? mb_substr($text, 0, 4000) : null, ':received' => $receivedAt, ':now' => self::now(),
        ]);

        return $stmt->rowCount() === 1 ? (int) $this->db->lastInsertId() : null;
    }

    public function setInboundOutcome(int $id, string $outcome, ?int $ticketId = null, ?int $notificationId = null): void
    {
        $this->db->prepare('UPDATE whatsapp_inbound_messages SET outcome = :o, ticket_id = :t, notification_id = :n WHERE id = :id')
            ->execute([':o' => $outcome, ':t' => $ticketId, ':n' => $notificationId, ':id' => $id]);
    }

    // -------------------------------------------------------------------------
    // Conversatia
    // -------------------------------------------------------------------------

    /** Starea curenta, blocata pana la sfarsitul tranzactiei (doua mesaje simultane nu o strica). */
    public function lockConversation(int $driverId): array
    {
        $this->db->prepare("INSERT IGNORE INTO driver_whatsapp_conversations (driver_id, state, updated_at) VALUES (:d, 'idle', :now)")
            ->execute([':d' => $driverId, ':now' => self::now()]);
        $stmt = $this->db->prepare('SELECT * FROM driver_whatsapp_conversations WHERE driver_id = :d FOR UPDATE');
        $stmt->execute([':d' => $driverId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['driver_id' => $driverId, 'state' => 'idle', 'notification_id' => null, 'expires_at' => null];
    }

    public function setConversation(int $driverId, string $state, ?int $notificationId, ?string $expiresAt): void
    {
        $this->db->prepare('UPDATE driver_whatsapp_conversations SET state = :s, notification_id = :n, expires_at = :e, updated_at = :now WHERE driver_id = :d')
            ->execute([':s' => $state, ':n' => $notificationId, ':e' => $expiresAt, ':now' => self::now(), ':d' => $driverId]);
    }

    // -------------------------------------------------------------------------
    // Tichete de suport
    // -------------------------------------------------------------------------

    public static function ticketReference(int $id): string
    {
        return 'SUP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /** @return array{id:int, reference:string} */
    public function createTicket(int $driverId, ?int $vehicleId, ?int $notificationId, string $description, string $sourceWaMessageId): array
    {
        $now = self::now();
        $stmt = $this->db->prepare('
            INSERT INTO driver_support_tickets
                (driver_id, vehicle_id, notification_id, description, status, email_status, email_next_attempt_at, source_wa_message_id, created_at, updated_at)
            VALUES (:driver, :vehicle, :notification, :description, \'open\', \'pending\', :now, :source, :now2, :now3)
        ');
        $stmt->execute([
            ':driver' => $driverId, ':vehicle' => $vehicleId, ':notification' => $notificationId, ':description' => $description,
            ':now' => $now, ':source' => mb_substr($sourceWaMessageId, 0, 64), ':now2' => $now, ':now3' => $now,
        ]);
        $id = (int) $this->db->lastInsertId();
        $reference = self::ticketReference($id);
        $this->db->prepare('UPDATE driver_support_tickets SET reference = :r WHERE id = :id')->execute([':r' => $reference, ':id' => $id]);
        $this->addSupportMessage($id, 'whatsapp_to_email', $sourceWaMessageId, 'driver:' . $driverId, $description, 'pending');

        return ['id' => $id, 'reference' => $reference];
    }

    public function getTicket(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM driver_support_tickets WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Tichetul deschis cel mai recent al soferului, daca a avut activitate in ultimele $days zile. */
    public function openTicketForDriver(int $driverId, int $days): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM driver_support_tickets
            WHERE driver_id = :d AND status IN ('open', 'answered')
              AND GREATEST(created_at, COALESCE(last_reply_at, created_at)) >= DATE_SUB(:now, INTERVAL " . max(1, $days) . " DAY)
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':d' => $driverId, ':now' => self::now()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return int|null id-ul nou, null daca mesajul (directie + sursa) exista deja */
    public function addSupportMessage(?int $ticketId, string $direction, string $sourceMessageId, ?string $sender, ?string $content, string $deliveryStatus): ?int
    {
        $stmt = $this->db->prepare('
            INSERT IGNORE INTO driver_support_messages (ticket_id, direction, source_message_id, sender, content, delivery_status, created_at)
            VALUES (:t, :dir, :src, :sender, :content, :status, :now)
        ');
        $stmt->execute([
            ':t' => $ticketId, ':dir' => $direction, ':src' => mb_substr($sourceMessageId, 0, 255), ':sender' => $sender !== null ? mb_substr($sender, 0, 255) : null,
            ':content' => $content, ':status' => $deliveryStatus, ':now' => self::now(),
        ]);

        return $stmt->rowCount() === 1 ? (int) $this->db->lastInsertId() : null;
    }

    public function touchTicket(int $ticketId): void
    {
        $this->db->prepare('UPDATE driver_support_tickets SET updated_at = :now WHERE id = :id')->execute([':now' => self::now(), ':id' => $ticketId]);
    }

    public function cancelTicketDraft(int $driverId): void
    {
        $this->setConversation($driverId, 'idle', null, null);
    }
}
