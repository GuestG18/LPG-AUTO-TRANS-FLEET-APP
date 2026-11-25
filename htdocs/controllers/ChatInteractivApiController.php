<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ChatInteractivService.php';

/**
 * API intern server-to-server pentru serverul multiplayer (chat_interactiv_server/server.js).
 * Fără sesiune de browser. Autentificare: headerul X-Chat-Token = CHAT_INTERACTIV_SECRET din .env
 * (comparat cu hash_equals; un secret neconfigurat respinge orice cerere).
 *
 *   POST v1/messages  {user_id, text}  -> mesajul salvat (identitatea user_id a fost verificată de
 *                                         serverul WS din tokenul semnat la intrarea în cameră)
 */
class ChatInteractivApiController
{
    public function handle(string $path): void
    {
        $expected = ChatInteractivService::secret();
        $given = trim((string) ($_SERVER['HTTP_X_CHAT_TOKEN'] ?? ''));
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            $this->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->json(['ok' => false, 'error' => 'method'], 405);
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, 16385);
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $this->json(['ok' => false, 'error' => 'bad_json'], 400);
        }
        $service = new ChatInteractivService(get_pdo());
        try {
            $service->ensureSchema();
            if (trim($path, '/') === 'v1/messages') {
                $message = $service->storeMessage((int) ($input['user_id'] ?? 0), (string) ($input['text'] ?? ''));
                $this->json(['ok' => true, 'message' => $message]);
            }
            $this->json(['ok' => false, 'error' => 'not_found'], 404);
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[ChatInteractivApi] ' . $e->getMessage());
            $this->json(['ok' => false, 'error' => 'server'], 500);
        }
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
