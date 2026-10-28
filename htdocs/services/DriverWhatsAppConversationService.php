<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/DriverWhatsAppModel.php';
require_once __DIR__ . '/DriverFirstMovementService.php';

/**
 * Raspunsurile soferilor pe WhatsApp (mesaje directe primite de BaileyBot).
 *
 * BaileyBot trimite fiecare mesaj direct aici (WhatsAppBotApiController -> handleInbound);
 * toata logica si starea raman in Fleet. Butoanele si textul duc la aceeasi actiune:
 *   driver_app_confirmed      buton "Am deschis aplicația" | "1" | textul butonului
 *   driver_support_requested  buton "Am nevoie de ajutor"  | "2" | "ajutor" | textul butonului
 *
 * Stari (driver_whatsapp_conversations):
 *   idle                          mesajele fara legatura cu fluxul sunt ignorate (fara raspuns),
 *                                 cu exceptia unui tichet deschis: mesajul se adauga la tichet;
 *   awaiting_problem_description  urmatorul mesaj text devine descrierea tichetului;
 *                                 "anulează" renunta, starea expira dupa DRIVER_WA_SUPPORT_SESSION_MINUTES.
 *
 * Confirmarea "Am deschis aplicația" este doar declaratia soferului: Fleet nu are un API care
 * sa verifice independent sesiunea aplicatiei.
 */
class DriverWhatsAppConversationService
{
    public const ACTION_CONFIRMED = 'driver_app_confirmed';
    public const ACTION_SUPPORT = 'driver_support_requested';

    /** *...* = bold in WhatsApp. */
    public const MOVEMENT_PROMPT = "*Bună ziua!*\nVehiculul dumneavoastră a înregistrat prima deplasare a zilei.\nVă rugăm să deschideți aplicația și să verificați dacă sesiunea este activă.";
    public const CONFIRMED_REPLY = 'Vă mulțumim! Confirmarea dumneavoastră a fost înregistrată.';
    public const ALREADY_CONFIRMED_REPLY = 'Confirmarea dumneavoastră pentru astăzi a fost deja înregistrată. Vă mulțumim!';
    public const SUPPORT_PROMPT = 'Vă rugăm să descrieți problema întâmpinată cu aplicația. Mesajul dumneavoastră va fi transmis echipei de suport.';
    public const SUPPORT_PROMPT_HINT = 'Pentru a renunța, scrieți „anulează”.';
    public const CANCELLED_REPLY = 'Solicitarea a fost anulată. Dacă aveți nevoie de ajutor, răspundeți oricând cu 2.';
    public const DESCRIPTION_NEEDS_TEXT = 'Vă rugăm să descrieți problema în scris, într-un mesaj text (pentru a renunța, scrieți „anulează”).';
    public const DESCRIPTION_TOO_SHORT = 'Vă rugăm să descrieți problema în câteva cuvinte, ca echipa de suport să vă poată ajuta.';
    public const FOLLOWUP_ADDED = 'Mesajul a fost adăugat la solicitarea #%s și transmis echipei de suport.';

    public const STATE_IDLE = 'idle';
    public const STATE_AWAITING_DESCRIPTION = 'awaiting_problem_description';

    /** Un mesaj nou de la un sofer cu tichet deschis se adauga la tichet daca a fost activ in ultimele zile. */
    private const FOLLOWUP_WINDOW_DAYS = 7;

    private const MIN_DESCRIPTION_CHARS = 5;
    private const MAX_DESCRIPTION_CHARS = 2000;

    public function __construct(private PDO $db, private DriverWhatsAppModel $model, private ?int $sessionMinutes = null)
    {
        $this->sessionMinutes ??= max(5, (int) (getenv('DRIVER_WA_SUPPORT_SESSION_MINUTES') ?: 30));
    }

    /** @return array<int, array{id: string, title: string}> optiunile notificarii (butoane + raspuns numeric) */
    public static function movementOptions(): array
    {
        return [
            // Emoji-ul face parte din eticheta afisata; normalizeAction() il ignora la raspunsul scris.
            ['id' => self::ACTION_CONFIRMED, 'title' => '✅ Am deschis aplicația'],
            ['id' => self::ACTION_SUPPORT, 'title' => '🆘 Am nevoie de ajutor'],
        ];
    }

    public static function ticketReply(string $reference): string
    {
        return 'Solicitarea dumneavoastră a fost înregistrată cu numărul #' . $reference
            . ' și transmisă echipei de suport. Veți primi răspuns aici, pe WhatsApp.';
    }

    /** Textul cu care tichetul e confirmat cand emailul catre suport nu a putut fi trimis (dupa toate reincercarile). */
    public static function ticketReplyEmailFailed(string $reference): string
    {
        return 'Solicitarea dumneavoastră a fost înregistrată cu numărul #' . $reference
            . '. Echipa de suport o va prelua cât mai curând. Veți primi răspuns aici, pe WhatsApp.';
    }

    // -------------------------------------------------------------------------
    // Normalizare
    // -------------------------------------------------------------------------

    private static function plain(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii !== false) {
            $text = $ascii;
        }

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', $text) ?? '') ?? '');
    }

    /**
     * Actiunea aleasa: ID-ul butonului, sau textul ("1", "1.", "1 — Am deschis aplicația",
     * textul butonului, cu sau fara diacritice). null = mesaj liber.
     */
    public static function normalizeAction(?string $buttonId, ?string $text): ?string
    {
        if (in_array($buttonId, [self::ACTION_CONFIRMED, self::ACTION_SUPPORT], true)) {
            return $buttonId;
        }
        $plain = self::plain((string) $text);
        if ($plain === '') {
            return null;
        }
        if ($plain === '1' || $plain === 'am deschis aplicatia' || $plain === '1 am deschis aplicatia' || $plain === 'am deschis') {
            return self::ACTION_CONFIRMED;
        }
        if ($plain === '2' || $plain === 'am nevoie de ajutor' || $plain === '2 am nevoie de ajutor' || $plain === 'ajutor') {
            return self::ACTION_SUPPORT;
        }

        return null;
    }

    public static function isCancel(?string $text): bool
    {
        return in_array(self::plain((string) $text), ['anuleaza', 'anulez', 'anulare', 'renunt', 'stop', 'cancel'], true);
    }

    // -------------------------------------------------------------------------
    // Mesaj primit
    // -------------------------------------------------------------------------

    /**
     * @param array{wa_message_id: string, phone: string, kind: string, action_id?: ?string, text?: ?string, received_at?: ?string} $message
     * @return array{handled: bool, outcome: string, ticket?: string}
     */
    public function handleInbound(array $message): array
    {
        $waMessageId = trim((string) ($message['wa_message_id'] ?? ''));
        $phone = (string) ($message['phone'] ?? '');
        $kind = (string) ($message['kind'] ?? 'text');
        $buttonId = isset($message['action_id']) ? (string) $message['action_id'] : null;
        $text = isset($message['text']) ? trim((string) $message['text']) : null;
        $receivedAt = !empty($message['received_at']) && strtotime((string) $message['received_at']) !== false
            ? date('Y-m-d H:i:s', strtotime((string) $message['received_at'])) : date('Y-m-d H:i:s');

        if ($waMessageId === '' || preg_match('/^\+\d{10,15}$/', $phone) !== 1) {
            return ['handled' => false, 'outcome' => 'invalid_message'];
        }

        $driver = $this->model->findActiveDriverByPhone($phone);
        if ($driver === null) {
            // Numar necunoscut: nu salvam continutul (date personale fara rost) si nu raspundem.
            return ['handled' => false, 'outcome' => 'unknown_sender'];
        }
        $driverId = $driver['id'];

        $config = DriverFirstMovementService::config();
        if ($config['test_mode'] && !in_array($driverId, $config['test_driver_ids'], true)) {
            return ['handled' => false, 'outcome' => 'not_in_test_allowlist'];
        }

        // Totul intr-o tranzactie (o reia pe cea a apelantului, ex. testele): mesajul, starea si raspunsul.
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $inboundId = $this->model->logInbound($waMessageId, $driverId, $kind, $buttonId, $text, $receivedAt);
            if ($inboundId === null) {
                if ($ownTransaction) {
                    $this->db->commit();
                }

                return ['handled' => true, 'outcome' => 'duplicate'];
            }

            $conversation = $this->model->lockConversation($driverId);
            $result = $this->dispatch($driverId, $conversation, $waMessageId, $kind, $buttonId, $text);
            $this->model->setInboundOutcome($inboundId, $result['outcome'], $result['ticket_id'] ?? null, $result['notification_id'] ?? null);
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $response = ['handled' => $result['outcome'] !== 'ignored', 'outcome' => $result['outcome']];
        if (isset($result['reference'])) {
            $response['ticket'] = $result['reference'];
        }

        return $response;
    }

    /** @return array<string, mixed> outcome + ids */
    private function dispatch(int $driverId, array $conversation, string $waMessageId, string $kind, ?string $buttonId, ?string $text): array
    {
        $today = date('Y-m-d');
        $state = (string) ($conversation['state'] ?? self::STATE_IDLE);
        $expired = $state !== self::STATE_IDLE && !empty($conversation['expires_at']) && strtotime((string) $conversation['expires_at']) < time();
        if ($expired) {
            $this->model->setConversation($driverId, self::STATE_IDLE, null, null);
            $state = self::STATE_IDLE;
        }

        $action = self::normalizeAction($buttonId, $text);

        // 1. Asteptam descrierea problemei
        if ($state === self::STATE_AWAITING_DESCRIPTION) {
            if (self::isCancel($text)) {
                $this->model->setConversation($driverId, self::STATE_IDLE, null, null);
                $this->reply($driverId, 'support_cancelled', self::CANCELLED_REPLY, $waMessageId);

                return ['outcome' => 'support_cancelled'];
            }
            if ($action === self::ACTION_SUPPORT) {
                // A apasat din nou "Am nevoie de ajutor": reamintim ce asteptam.
                $this->extendSession($driverId, $conversation['notification_id'] !== null ? (int) $conversation['notification_id'] : null);
                $this->reply($driverId, 'support_prompt', self::SUPPORT_PROMPT . "\n\n" . self::SUPPORT_PROMPT_HINT, $waMessageId);

                return ['outcome' => 'support_prompt_repeated'];
            }
            if ($action === self::ACTION_CONFIRMED) {
                $this->model->setConversation($driverId, self::STATE_IDLE, null, null);

                return $this->confirm($driverId, $today, $waMessageId, $text);
            }
            if ($kind !== 'text' || $text === null || $text === '') {
                $this->reply($driverId, 'support_needs_text', self::DESCRIPTION_NEEDS_TEXT, $waMessageId);

                return ['outcome' => 'description_not_text'];
            }
            if (mb_strlen($text) < self::MIN_DESCRIPTION_CHARS) {
                $this->reply($driverId, 'support_needs_text', self::DESCRIPTION_TOO_SHORT, $waMessageId);

                return ['outcome' => 'description_too_short'];
            }

            return $this->createTicket($driverId, $conversation, $waMessageId, $text);
        }

        // 2. Raspuns la notificare (buton sau 1 / 2)
        if ($action === self::ACTION_CONFIRMED) {
            return $this->confirm($driverId, $today, $waMessageId, $text);
        }
        if ($action === self::ACTION_SUPPORT) {
            $notification = $this->model->latestNotificationForDriver($driverId, $today);
            if ($notification !== null && (string) $notification['status'] !== 'confirmed') {
                $this->model->recordNotificationResponse((int) $notification['id'], self::ACTION_SUPPORT, $text);
            }
            $notificationId = $notification !== null ? (int) $notification['id'] : null;
            $this->extendSession($driverId, $notificationId);
            $this->reply($driverId, 'support_prompt', self::SUPPORT_PROMPT . "\n\n" . self::SUPPORT_PROMPT_HINT, $waMessageId);

            return ['outcome' => 'support_requested', 'notification_id' => $notificationId];
        }

        // 3. Mesaj liber: se adauga la un tichet deschis, altfel se ignora (alt flux / conversatie obisnuita).
        // Un numar singur ("4") e o alegere ratata dintr-un meniu, nu un mesaj pentru suport.
        if ($kind === 'text' && $text !== null && $text !== '' && preg_match('/^\d{1,2}\.?$/', $text) !== 1) {
            $ticket = $this->model->openTicketForDriver($driverId, self::FOLLOWUP_WINDOW_DAYS);
            if ($ticket !== null) {
                $messageId = $this->model->addSupportMessage((int) $ticket['id'], 'whatsapp_to_email', $waMessageId, 'driver:' . $driverId, mb_substr($text, 0, self::MAX_DESCRIPTION_CHARS), 'pending');
                $this->model->touchTicket((int) $ticket['id']);
                $this->reply($driverId, 'support_followup', sprintf(self::FOLLOWUP_ADDED, $ticket['reference']), $waMessageId);

                return ['outcome' => 'ticket_followup', 'ticket_id' => (int) $ticket['id'], 'reference' => (string) $ticket['reference'], 'message_id' => $messageId];
            }
        }

        return ['outcome' => 'ignored'];
    }

    private function confirm(int $driverId, string $today, string $waMessageId, ?string $text): array
    {
        $notification = $this->model->latestNotificationForDriver($driverId, $today);
        if ($notification === null) {
            // "1" fara notificare azi: nu e un raspuns la fluxul nostru.
            return ['outcome' => 'ignored'];
        }
        if ((string) $notification['status'] === 'confirmed') {
            $this->reply($driverId, 'confirmation_repeat', self::ALREADY_CONFIRMED_REPLY, $waMessageId);

            return ['outcome' => 'already_confirmed', 'notification_id' => (int) $notification['id']];
        }
        $this->model->recordNotificationResponse((int) $notification['id'], self::ACTION_CONFIRMED, $text);
        $this->reply($driverId, 'confirmation_ack', self::CONFIRMED_REPLY, $waMessageId);

        return ['outcome' => 'confirmed', 'notification_id' => (int) $notification['id']];
    }

    private function createTicket(int $driverId, array $conversation, string $waMessageId, string $text): array
    {
        $notificationId = $conversation['notification_id'] !== null ? (int) $conversation['notification_id'] : null;
        $notification = $notificationId !== null ? $this->model->getNotification($notificationId) : null;
        $vehicleId = $notification !== null ? (int) $notification['vehicle_id'] : null;
        if ($vehicleId === null) {
            $vehicles = $this->model->getDefaultVehicleIdsForDriver($driverId);
            $vehicleId = count($vehicles) === 1 ? $vehicles[0] : null;
        }

        $ticket = $this->model->createTicket($driverId, $vehicleId, $notificationId, mb_substr($text, 0, self::MAX_DESCRIPTION_CHARS), $waMessageId);
        $this->model->setConversation($driverId, self::STATE_IDLE, null, null);
        // Confirmarea catre sofer asteapta emailul catre suport: notification_service/support_mail.py
        // elibereaza una dintre cele doua variante, dupa rezultatul trimiterii.
        $this->model->enqueue('support_ack', $driverId, self::ticketReply($ticket['reference']), [], 'support_ack:' . $ticket['id'], 'ticket', $ticket['id'], 'held');
        $this->model->enqueue('support_ack', $driverId, self::ticketReplyEmailFailed($ticket['reference']), [], 'support_ack_failed:' . $ticket['id'], 'ticket', $ticket['id'], 'held');

        return ['outcome' => 'ticket_created', 'ticket_id' => $ticket['id'], 'reference' => $ticket['reference'], 'notification_id' => $notificationId];
    }

    private function extendSession(int $driverId, ?int $notificationId): void
    {
        $this->model->setConversation($driverId, self::STATE_AWAITING_DESCRIPTION, $notificationId, date('Y-m-d H:i:s', time() + 60 * $this->sessionMinutes));
    }

    /** Raspuns catre sofer; cheia de deduplicare = mesajul la care raspundem (o reluare nu dubleaza raspunsul). */
    private function reply(int $driverId, string $purpose, string $body, string $inReplyToWaMessageId): void
    {
        $this->model->enqueue($purpose, $driverId, $body, [], 'reply:' . $inReplyToWaMessageId, 'inbound', null);
    }
}
