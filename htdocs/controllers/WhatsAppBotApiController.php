<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/DriverWhatsAppModel.php';
require_once __DIR__ . '/../services/DriverWhatsAppConversationService.php';
require_once __DIR__ . '/../services/DriverFirstMovementService.php';
require_once __DIR__ . '/../services/ApprovalWhatsAppService.php';
require_once __DIR__ . '/../services/MaintenanceWhatsAppService.php';

/**
 * API server-to-server pentru BaileyBot (whatsapp-agent-lab), fara sesiune de browser.
 *
 * Autentificare: headerul X-Bot-Token = FLEET_WHATSAPP_BOT_TOKEN din .env (comparat cu
 * hash_equals; un token neconfigurat respinge orice cerere), ca la AssistantApiController.
 *
 *   POST v1/outbox/claim   {limit}                                   mesajele de trimis (lock)
 *   POST v1/outbox/result  {id, lock_token, status, wa_message_id, error, retryable}
 *   POST v1/inbound        {wa_message_id, phone, kind, action_id, text, received_at}
 *
 * Botul nu decide nimic: Fleet alege destinatarii, textele si starea conversatiei.
 */
class WhatsAppBotApiController
{
    private const MAX_BODY_BYTES = 65536;

    private ?PDO $db = null;

    public function handle(string $path): void
    {
        try {
            $route = trim($path, '/');
            if (!in_array($route, ['v1/outbox/claim', 'v1/outbox/result', 'v1/inbound', 'v1/voice/claim', 'v1/voice/result'], true)) {
                $this->sendJson(['success' => false, 'error' => 'not_found'], 404);
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                header('Allow: POST');
                $this->sendJson(['success' => false, 'error' => 'method_not_allowed'], 405);
            }
            $this->requireValidToken();
            $input = $this->readJsonBody();

            $model = new DriverWhatsAppModel($this->db());
            $model->ensureSchema();

            switch ($route) {
                // Apelantul vocal (voice-caller): anunturile conducerii ajunse la ora lor.
                case 'v1/voice/claim':
                case 'v1/voice/result':
                    if (!MaintenanceWhatsAppService::config()['enabled']) {
                        $this->sendJson(['success' => false, 'error' => 'disabled'], 409);
                    }
                    $voice = new MaintenanceWhatsAppService($this->db(), $model);
                    $voice->ensureSchema();
                    if ($route === 'v1/voice/claim') {
                        $this->sendJson(['success' => true, 'calls' => $voice->claimVoiceCalls((int) ($input['limit'] ?? 1))]);
                    }
                    $ok = $voice->reportVoiceCall((int) ($input['id'] ?? 0), (string) ($input['lock_token'] ?? ''), (string) ($input['status'] ?? ''),
                        isset($input['detail']) ? (string) $input['detail'] : null);
                    $this->sendJson(['success' => $ok, 'error' => $ok ? null : 'lock_lost'], $ok ? 200 : 409);
                    // no break: sendJson iese
                case 'v1/outbox/claim':
                    // Solicitarile de aprobare noi intra in coada inainte de revendicare (fara cron separat).
                    $approvals = new ApprovalWhatsAppService($this->db(), $model);
                    if (ApprovalWhatsAppService::config()['enabled']) {
                        try {
                            $approvals->ensureSchema();
                            $approvals->enqueuePending();
                        } catch (Throwable $exception) {
                            error_log('[whatsapp_bot_api][approvals] ' . $exception->getMessage());
                        }
                    }
                    // Programari service: schimbarile facute din pagina Interventii ajung si la sofer.
                    if (MaintenanceWhatsAppService::config()['enabled']) {
                        try {
                            $maintenance = new MaintenanceWhatsAppService($this->db(), $model);
                            $maintenance->ensureSchema();
                            $maintenance->syncDriverNotifications();
                            $maintenance->notifyAssignments();
                        } catch (Throwable $exception) {
                            error_log('[whatsapp_bot_api][maintenance] ' . $exception->getMessage());
                        }
                    }
                    $claim = $model->claimOutbox((int) ($input['limit'] ?? 5));
                    $this->sendJson(['success' => true] + $claim);
                    // no break: sendJson iese
                case 'v1/outbox/result':
                    $ok = $model->reportOutboxResult(
                        (int) ($input['id'] ?? 0),
                        (string) ($input['lock_token'] ?? ''),
                        ($input['status'] ?? '') === 'sent',
                        isset($input['wa_message_id']) ? mb_substr((string) $input['wa_message_id'], 0, 64) : null,
                        isset($input['error']) ? (string) $input['error'] : null,
                        (bool) ($input['retryable'] ?? true)
                    );
                    $this->sendJson(['success' => $ok, 'error' => $ok ? null : 'lock_lost'], $ok ? 200 : 409);
                    // no break
                case 'v1/inbound':
                    $message = [
                        'wa_message_id' => (string) ($input['wa_message_id'] ?? ''),
                        'phone' => (string) ($input['phone'] ?? ''),
                        'kind' => in_array($input['kind'] ?? '', ['text', 'button', 'media', 'other'], true) ? (string) $input['kind'] : 'other',
                        'action_id' => isset($input['action_id']) ? mb_substr((string) $input['action_id'], 0, 40) : null,
                        'text' => isset($input['text']) ? mb_substr((string) $input['text'], 0, 4000) : null,
                        'received_at' => isset($input['received_at']) ? (string) $input['received_at'] : null,
                        // Mesaj vocal (pentru anunturile conducerii): tipul si durata, fara continut.
                        'media_type' => in_array($input['media_type'] ?? '', ['audio', 'audio_failed', 'image', 'video', 'document', 'sticker'], true) ? (string) $input['media_type'] : null,
                        'media_seconds' => isset($input['media_seconds']) ? max(0, min(3600, (int) $input['media_seconds'])) : null,
                    ];
                    // Aprobarile au ID-uri proprii (approval:123:approve) sau "APROB 123": nu se confunda cu soferii.
                    $approvals = new ApprovalWhatsAppService($this->db(), $model);
                    if ($approvals->isApprovalMessage($message)) {
                        $approvals->ensureSchema();
                        $result = $approvals->handleInbound($message);
                        if ($result['handled']) {
                            $this->sendJson(['success' => true] + $result);
                        }
                    }
                    // Programari service: comenzile (PROGRAMARE SERVICE, MENIU...), butoanele mnt: si conversatia in curs.
                    if (MaintenanceWhatsAppService::config()['enabled']) {
                        $maintenance = new MaintenanceWhatsAppService($this->db(), $model);
                        $maintenance->ensureSchema();
                        if ($maintenance->claims($message)) {
                            $result = $maintenance->handleInbound($message);
                            if ($result['handled']) {
                                $this->sendJson(['success' => true] + $result);
                            }
                        }
                    }
                    $service = new DriverWhatsAppConversationService($this->db(), $model);
                    $this->sendJson(['success' => true] + $service->handleInbound([
                        'wa_message_id' => (string) ($input['wa_message_id'] ?? ''),
                        'phone' => (string) ($input['phone'] ?? ''),
                        'kind' => in_array($input['kind'] ?? '', ['text', 'button', 'media', 'other'], true) ? (string) $input['kind'] : 'other',
                        'action_id' => isset($input['action_id']) ? mb_substr((string) $input['action_id'], 0, 40) : null,
                        'text' => isset($input['text']) ? mb_substr((string) $input['text'], 0, 4000) : null,
                        'received_at' => isset($input['received_at']) ? (string) $input['received_at'] : null,
                    ]));
            }
        } catch (Throwable $exception) {
            error_log('[whatsapp_bot_api] ' . $exception->getMessage());
            $this->sendJson(['success' => false, 'error' => 'server_error'], 500);
        }
    }

    private function requireValidToken(): void
    {
        $expected = trim((string) (getenv('FLEET_WHATSAPP_BOT_TOKEN') ?: ''));
        $given = trim((string) ($_SERVER['HTTP_X_BOT_TOKEN'] ?? ''));

        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            $this->sendJson(['success' => false, 'error' => 'unauthorized'], 401);
        }
    }

    /** @return array<string, mixed> */
    private function readJsonBody(): array
    {
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            $this->sendJson(['success' => false, 'error' => 'payload_too_large'], 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->sendJson(['success' => false, 'error' => 'invalid_json'], 400);
        }

        return $data;
    }

    private function db(): PDO
    {
        return $this->db ??= get_pdo();
    }

    private function sendJson(array $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
