<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/DriverWhatsAppModel.php';
require_once __DIR__ . '/../models/InactiveResourceApprovalModel.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/AccessRightsModel.php';
require_once __DIR__ . '/../includes/access.php';

/**
 * Solicitarile de aprobare (Vehicule, Soferi, Reparatii, Diurne, Rute) pe WhatsApp, prin BaileyBot.
 *
 * Aceleasi cereri ca in panoul "Solicitari aprobare in asteptare" (inactive_resource_approvals);
 * tab-ul "Operatori" nu e o aprobare (activitatea operatorilor / taxe de refacturat) si nu intra aici.
 *
 *   enqueuePending()  la fiecare citire a outbox-ului de catre bot: fiecare cerere noua in asteptare
 *                     pleaca o singura data la fiecare aprobator (UNIQUE cerere + utilizator), cu
 *                     butoanele "Aprob" / "Resping";
 *   handleInbound()   butonul apasat (sau "APROB 123" / "RESPING 123") -> aceeasi decizie ca in
 *                     aplicatie: InactiveResourceApprovalModel::approve()/reject() (doar daca cererea e
 *                     inca in asteptare) + aplicarea rutelor aprobate, ca in InactiveResourceApprovalController.
 *
 * Aprobatori: utilizatori activi cu dreptul inactive_approvals.review (user_can, aceleasi reguli ca
 * in aplicatie), cu telefon, din APPROVAL_WA_USER_IDS (lista goala = nimeni, ca sa nu plece nimic
 * fara o alegere explicita).
 */
class ApprovalWhatsAppService
{
    public const ACTION_PREFIX = 'approval:';

    /** @var callable(): void */
    private $afterApprove;

    /**
     * @param array<string, mixed>|null $config null = din .env
     * @param callable|null $afterApprove dupa o aprobare (implicit: aplica rutele aprobate, ca pagina web)
     */
    public function __construct(
        private PDO $db,
        private DriverWhatsAppModel $outbox,
        private ?InactiveResourceApprovalModel $approvals = null,
        private ?array $config = null,
        ?callable $afterApprove = null,
    ) {
        $this->approvals ??= new InactiveResourceApprovalModel($db);
        $this->config ??= self::config();
        $this->afterApprove = $afterApprove ?? fn() => $this->applyApprovedRouteConfigs();
    }

    /** @return array{enabled: bool, user_ids: array<int, int>, lookback_days: int} */
    public static function config(): array
    {
        $env = static fn(string $name, string $default = ''): string => is_string($v = getenv($name)) && trim($v) !== '' ? trim($v) : $default;

        return [
            'enabled' => in_array(strtolower($env('APPROVAL_WA_ENABLED', '0')), ['1', 'true', 'yes', 'on', 'da'], true),
            'user_ids' => array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $env('APPROVAL_WA_USER_IDS')) ?: []))),
            // Doar cererile recente: la prima pornire nu trimitem tot istoricul.
            'lookback_days' => max(1, (int) $env('APPROVAL_WA_LOOKBACK_DAYS', '3')),
        ];
    }

    public static function schemaStatements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS approval_whatsapp_messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                approval_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                outbox_id INT UNSIGNED NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'sent',
                decision VARCHAR(20) NULL,
                decided_at DATETIME NULL,
                decision_wa_message_id VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_approval_whatsapp_user (approval_id, user_id),
                KEY idx_approval_whatsapp_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $this->outbox->ensureSchema();
        $this->approvals->ensureSchema();
        foreach (self::schemaStatements() as $statement) {
            $this->db->exec($statement);
        }
        $done = true;
    }

    // -------------------------------------------------------------------------
    // Aprobatori
    // -------------------------------------------------------------------------

    /** @return array<int, array{id: int, nume: string, phone: string}> utilizatorii care primesc si pot decide */
    public function reviewers(): array
    {
        if ($this->config['user_ids'] === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($this->config['user_ids']), '?'));
        $stmt = $this->db->prepare("SELECT id, telefon FROM utilizatori WHERE id IN ($placeholders)");
        $stmt->execute($this->config['user_ids']);

        $users = new UserModel($this->db);
        $reviewers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $phone = NotificationRuleModel::normalizePhone((string) ($row['telefon'] ?? ''));
            $user = $users->findAuthUserById((int) $row['id']);
            if ($phone === null || $user === null || !user_can($user, 'inactive_approvals', 'review')) {
                continue;
            }
            $reviewers[] = ['id' => (int) $user['id'], 'nume' => (string) $user['nume'], 'phone' => $phone];
        }

        return $reviewers;
    }

    /** Aprobatorul cu acest numar (E.164), sau null. */
    public function reviewerByPhone(string $phone): ?array
    {
        $matches = array_values(array_filter($this->reviewers(), static fn(array $r): bool => $r['phone'] === $phone));

        return count($matches) === 1 ? $matches[0] : null;
    }

    // -------------------------------------------------------------------------
    // Trimitere
    // -------------------------------------------------------------------------

    /** @return int mesaje noi puse in coada */
    public function enqueuePending(int $limit = 20): int
    {
        if (!$this->config['enabled']) {
            return 0;
        }
        $reviewers = $this->reviewers();
        if ($reviewers === []) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT id FROM inactive_resource_approvals
            WHERE status = 'pending' AND requested_at >= :since
            ORDER BY requested_at ASC, id ASC
            LIMIT " . max(1, $limit)
        );
        $stmt->execute([':since' => date('Y-m-d H:i:s', strtotime('-' . $this->config['lookback_days'] . ' days'))]);
        $pendingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $queued = 0;
        foreach ($pendingIds as $approvalId) {
            $approval = null;
            foreach ($reviewers as $reviewer) {
                $exists = $this->db->prepare('SELECT 1 FROM approval_whatsapp_messages WHERE approval_id = :a AND user_id = :u');
                $exists->execute([':a' => $approvalId, ':u' => $reviewer['id']]);
                if ($exists->fetchColumn() !== false) {
                    continue;
                }
                $approval ??= $this->approvals->getById($approvalId);
                if ($approval === null || (string) $approval['status'] !== 'pending') {
                    break;
                }
                if ($this->queueFor($approval, $reviewer)) {
                    $queued++;
                }
            }
        }

        return $queued;
    }

    private function queueFor(array $approval, array $reviewer): bool
    {
        $approvalId = (int) $approval['id'];
        $now = date('Y-m-d H:i:s');
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $insert = $this->db->prepare("
                INSERT IGNORE INTO approval_whatsapp_messages (approval_id, user_id, status, created_at, updated_at)
                VALUES (:a, :u, 'sent', :now, :now2)
            ");
            $insert->execute([':a' => $approvalId, ':u' => $reviewer['id'], ':now' => $now, ':now2' => $now]);
            if ($insert->rowCount() === 0) {
                if ($ownTransaction) {
                    $this->db->commit();
                }

                return false; // alt proces a trimis-o intre timp
            }
            $rowId = (int) $this->db->lastInsertId();
            $outboxId = $this->outbox->enqueueForUser(
                'approval_request',
                $reviewer['id'],
                self::messageText($approval),
                self::options($approvalId),
                'approval:' . $approvalId . ':user:' . $reviewer['id'],
                'approval',
                $approvalId
            );
            $this->db->prepare('UPDATE approval_whatsapp_messages SET outbox_id = :o WHERE id = :id')->execute([':o' => $outboxId, ':id' => $rowId]);
            if ($ownTransaction) {
                $this->db->commit();
            }

            return true;
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<int, array{id: string, title: string}> */
    public static function options(int $approvalId): array
    {
        return [
            ['id' => self::ACTION_PREFIX . $approvalId . ':approve', 'title' => '✅ Aprob'],
            ['id' => self::ACTION_PREFIX . $approvalId . ':reject', 'title' => '❌ Resping'],
        ];
    }

    /** Textul mesajului, din acelasi context ca panoul de aprobari din aplicatie. */
    public static function messageText(array $approval): string
    {
        $context = is_array($approval['approval_context'] ?? null) ? $approval['approval_context'] : [];
        $value = static fn(string $key): string => trim((string) ($context[$key] ?? ''));
        $lines = ['*Solicitare de aprobare #' . (int) $approval['id'] . '*'];

        $head = trim($value('resource_type_label') . ': ' . $value('primary_label'), ': ');
        if ($head !== '') {
            $lines[] = '*' . $head . '*';
        }
        foreach (['request_type_label' => 'Motiv', 'problem_title' => null, 'operation_title' => null] as $key => $label) {
            $text = $value($key);
            if ($text !== '' && !in_array($text, $lines, true)) {
                $lines[] = $label !== null ? $label . ': ' . $text : $text;
            }
        }

        $rows = array_merge(
            is_array($context['summary_rows'] ?? null) ? $context['summary_rows'] : [],
            is_array($context['detail_rows'] ?? null) ? $context['detail_rows'] : []
        );
        $seen = [];
        $details = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $text = trim((string) ($row['value'] ?? ''));
            if ($label === '' || $text === '' || isset($seen[$label]) || in_array($label, ['Modul'], true)) {
                continue;
            }
            $seen[$label] = true;
            $details[] = '• ' . $label . ': ' . $text;
            if (count($details) >= 10) {
                break;
            }
        }
        if ($details !== []) {
            $lines[] = '';
            array_push($lines, ...$details);
        }

        return mb_substr(implode("\n", $lines), 0, 3500);
    }

    // -------------------------------------------------------------------------
    // Decizia
    // -------------------------------------------------------------------------

    /**
     * Decizia dintr-un mesaj: butonul (approval:123:approve) sau textul "APROB 123" / "RESPING #123".
     *
     * @return array{approval_id: int, decision: string}|null
     */
    public static function parseDecision(?string $actionId, ?string $text): ?array
    {
        if ($actionId !== null && preg_match('/^approval:(\d+):(approve|reject)$/', $actionId, $m) === 1) {
            return ['approval_id' => (int) $m[1], 'decision' => $m[2]];
        }
        $plain = mb_strtolower(trim((string) $text));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $plain);
        $plain = $ascii !== false ? $ascii : $plain;
        if (preg_match('/^(aprob|aprobat|resping|respins|refuz)\s*#?\s*(\d+)$/', $plain, $m) === 1) {
            return ['approval_id' => (int) $m[2], 'decision' => str_starts_with($m[1], 'aprob') ? 'approve' : 'reject'];
        }

        return null;
    }

    public function isApprovalMessage(array $message): bool
    {
        return self::parseDecision($message['action_id'] ?? null, $message['text'] ?? null) !== null;
    }

    /**
     * @param array{wa_message_id: string, phone: string, kind: string, action_id?: ?string, text?: ?string, received_at?: ?string} $message
     * @return array{handled: bool, outcome: string}
     */
    public function handleInbound(array $message): array
    {
        $parsed = self::parseDecision($message['action_id'] ?? null, $message['text'] ?? null);
        $waMessageId = trim((string) ($message['wa_message_id'] ?? ''));
        if ($parsed === null || $waMessageId === '') {
            return ['handled' => false, 'outcome' => 'not_an_approval'];
        }
        if (!$this->config['enabled']) {
            return ['handled' => false, 'outcome' => 'approvals_disabled'];
        }
        $reviewer = $this->reviewerByPhone((string) ($message['phone'] ?? ''));
        if ($reviewer === null) {
            // Nu e un aprobator: nimic nu se decide si nu raspundem.
            return ['handled' => false, 'outcome' => 'not_a_reviewer'];
        }

        $inboundId = $this->outbox->logInbound($waMessageId, null, (string) ($message['kind'] ?? 'text'), $message['action_id'] ?? null, $message['text'] ?? null, date('Y-m-d H:i:s'));
        if ($inboundId === null) {
            return ['handled' => true, 'outcome' => 'duplicate'];
        }

        $approvalId = $parsed['approval_id'];
        $approve = $parsed['decision'] === 'approve';
        $note = ($approve ? 'Aprobat' : 'Respins') . ' prin WhatsApp de ' . $reviewer['nume'] . '.';
        $ok = $approve
            ? $this->approvals->approve($approvalId, $reviewer['id'], $note)
            : $this->approvals->reject($approvalId, $reviewer['id'], $note);

        $current = $this->approvals->getById($approvalId);
        $label = $current !== null ? self::shortLabel($current) : '';

        if ($ok) {
            $this->expireEmailTokens($approvalId);
            if ($approve) {
                try {
                    ($this->afterApprove)();
                } catch (Throwable $exception) {
                    error_log('[ApprovalWhatsApp][route_config] ' . $exception->getMessage());
                }
            }
            $this->db->prepare("
                UPDATE approval_whatsapp_messages SET status = 'decided', decision = :d, decided_at = :now, decision_wa_message_id = :wa, updated_at = :now2
                WHERE approval_id = :a AND user_id = :u
            ")->execute([':d' => $approve ? 'approved' : 'rejected', ':now' => date('Y-m-d H:i:s'), ':wa' => mb_substr($waMessageId, 0, 64), ':now2' => date('Y-m-d H:i:s'), ':a' => $approvalId, ':u' => $reviewer['id']]);
            $reply = ($approve ? '✅ Solicitarea #' . $approvalId . ' a fost aprobată.' : '❌ Solicitarea #' . $approvalId . ' a fost respinsă.')
                . ($label !== '' ? "\n" . $label : '');
            $outcome = $approve ? 'approved' : 'rejected';
        } elseif ($current === null) {
            $reply = 'Solicitarea #' . $approvalId . ' nu există.';
            $outcome = 'approval_not_found';
        } else {
            $status = ['approved' => 'aprobată', 'rejected' => 'respinsă', 'pending' => 'în așteptare'][(string) $current['status']] ?? (string) $current['status'];
            $by = trim((string) ($current['reviewed_by_name'] ?? ''));
            $reply = 'ℹ️ Solicitarea #' . $approvalId . ' nu mai este în așteptare: a fost deja ' . $status
                . ($by !== '' ? ' de ' . $by : '')
                . (!empty($current['reviewed_at']) ? ' la ' . date('d.m.Y H:i', strtotime((string) $current['reviewed_at'])) : '') . '.';
            $outcome = 'already_decided';
        }

        $this->outbox->setInboundOutcome($inboundId, $outcome);
        $this->outbox->enqueueForUser('approval_reply', $reviewer['id'], $reply, [], 'reply:' . $waMessageId, 'approval', $approvalId);

        return ['handled' => true, 'outcome' => $outcome];
    }

    private static function shortLabel(array $approval): string
    {
        $context = is_array($approval['approval_context'] ?? null) ? $approval['approval_context'] : [];

        return trim(trim((string) ($context['resource_type_label'] ?? '')) . ': ' . trim((string) ($context['primary_label'] ?? '')), ': ');
    }

    /** Link-urile de aprobare prin email ale aceleiasi cereri nu mai au rost (ca in approval_flow.py). */
    private function expireEmailTokens(int $approvalId): void
    {
        try {
            $this->db->prepare("
                UPDATE approval_email_actions SET status = 'expired', updated_at = :now,
                    result_note = COALESCE(result_note, 'Cerere decisa prin WhatsApp.')
                WHERE approval_id = :a AND status = 'active'
            ")->execute([':now' => date('Y-m-d H:i:s'), ':a' => $approvalId]);
        } catch (PDOException $exception) {
            // Tabela exista doar daca aprobarile prin email au fost instalate.
        }
    }

    /** Ca InactiveResourceApprovalController dupa o aprobare: rutele aprobate se aplica imediat. */
    private function applyApprovedRouteConfigs(): void
    {
        $root = dirname(__DIR__);
        require_once $root . '/includes/helpers.php';
        require_once $root . '/models/DispecerCurseModel.php';
        require_once $root . '/services/InactiveResourceStatusService.php';
        require_once $root . '/controllers/DispecerCurseController.php';
        (new DispecerCurseController($this->db))->applyApprovedRouteConfigRequests();
    }
}
