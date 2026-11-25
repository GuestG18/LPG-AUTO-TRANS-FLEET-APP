<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ChatInteractivService.php';

/**
 * Pagina „Chat Interactiv” (?page=chat_interactiv) — LPG Connect, lumea 3D multiplayer.
 *
 *   action=index        — pagina (lumea 3D ocupă zona de conținut)
 *   action=bootstrap    — JSON: utilizator, avatar, token WS, drepturi, panou, istoric chat
 *   action=ws_token     — JSON: token WS nou (reconectare)
 *   action=set_avatar   — POST: avatarul ales (persistat per cont)
 *   action=notes        — JSON: notele unei categorii (cat=noutate|personal|termen, state=...)
 *   action=note         — JSON: detaliul unei note (+ marcare citit pentru noutăți)
 *   action=note_store   — POST (multipart): notă nouă
 *   action=note_update  — POST: op=status|archive|restore|edit|ack
 *   action=board        — JSON: ce se desenează pe panoul 3D
 *   action=messages     — JSON: istoric chat (before=id)
 *   action=chat_read    — POST: ultimul mesaj citit
 *   action=reminders    — JSON: memento-uri scadente
 *   action=attachment   — descărcare atașament (cu verificarea dreptului de citire)
 *
 * Mesajele de chat NU se scriu de aici: le trimite serverul WS prin ChatInteractivApiController.
 */
class ChatInteractivController
{
    private ChatInteractivService $service;
    private float $startedAt = 0.0;
    private string $action = '';

    public function __construct(private PDO $db)
    {
        $this->service = new ChatInteractivService($db);
    }

    public function handle(string $action): void
    {
        $this->startedAt = microtime(true);
        $this->action = $action;
        if (!can('chat_interactiv')) {
            http_response_code(403);
            render('errors/403.php', ['pageTitle' => 'Acces interzis', 'currentPage' => '']);
            return;
        }
        $this->service->ensureSchema();
        $user = (array) current_user();

        if ($action === 'index' || $action === '') {
            $this->index($user);
            return;
        }
        if ($action === 'attachment') {
            $this->download($user);
            return;
        }

        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $postActions = ['set_avatar', 'note_store', 'note_update', 'chat_read'];
        if (in_array($action, $postActions, true)) {
            if (!$isPost) {
                $this->json(['ok' => false, 'message' => 'Metodă nepermisă.'], 405);
            }
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_token'] ?? null);
            if (!verify_csrf_token(is_string($token) ? $token : null)) {
                $this->json(['ok' => false, 'message' => 'Sesiune expirată. Reîncarcă pagina.'], 419);
            }
        }
        // Sesiunea nu mai e necesară după citire: eliberăm lacătul ca cererile paralele să nu se blocheze.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $payload = match ($action) {
                'bootstrap' => $this->bootstrap($user),
                'ws_token' => ['token' => $this->token($user)],
                'set_avatar' => $this->setAvatar($user),
                'notes' => $this->service->listNotes($user, (string) ($_GET['cat'] ?? ''), (string) ($_GET['state'] ?? '')),
                'note' => ['note' => ChatInteractivService::publicNote($this->service->noteDetail($user, (int) ($_GET['id'] ?? 0)))],
                'note_store' => ['note' => ChatInteractivService::publicNote($this->service->createNote($user, $_POST, $_FILES['atasament'] ?? null))],
                'note_update' => ['note' => ChatInteractivService::publicNote($this->service->updateNote($user, (int) ($_POST['id'] ?? 0), (string) ($_POST['op'] ?? ''), $_POST))],
                'board' => $this->service->boardSummary($user),
                'messages' => $this->service->messages((int) $user['id'], (int) ($_GET['before'] ?? 0)),
                'chat_read' => $this->chatRead($user),
                'reminders' => ['reminders' => $this->service->dueReminders($user)],
                default => throw new InvalidArgumentException('Acțiune necunoscută.'),
            };
            $this->json(['ok' => true] + $payload);
        } catch (DomainException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('[ChatInteractivController][' . $action . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->json(['ok' => false, 'message' => 'Operația nu a reușit. Reîncearcă.'], 500);
        }
    }

    private function index(array $user): void
    {
        $modelsBase = 'assets/models/chat-interactiv/';
        render('chat_interactiv/index.php', [
            'pageTitle' => 'Chat Interactiv',
            'currentPage' => 'chat_interactiv',
            'config' => [
                'bootstrapUrl' => build_query_url(['page' => 'chat_interactiv', 'action' => 'bootstrap']),
                'endpoint' => build_query_url(['page' => 'chat_interactiv']),
                'csrf' => csrf_token(),
                'assets' => [
                    'office' => url($modelsBase . 'office.glb?v=' . (string) @filemtime(BASE_PATH . '/' . $modelsBase . 'office.glb')),
                    'world' => url('assets/js/chat-interactiv/world.json?v=' . (string) @filemtime(BASE_PATH . '/assets/js/chat-interactiv/world.json')),
                    'avatars' => [
                        'avaturn_a' => [
                            'model' => url($modelsBase . 'avatar-a.glb?v=' . (string) @filemtime(BASE_PATH . '/' . $modelsBase . 'avatar-a.glb')),
                            'idle' => url($modelsBase . 'avatar-a-idle.anim.glb?v=' . (string) @filemtime(BASE_PATH . '/' . $modelsBase . 'avatar-a-idle.anim.glb')),
                        ],
                        'avaturn_b' => [
                            'model' => url($modelsBase . 'avatar-b.glb?v=' . (string) @filemtime(BASE_PATH . '/' . $modelsBase . 'avatar-b.glb')),
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function bootstrap(array $user): array
    {
        $uid = (int) $user['id'];
        $avatar = $this->service->avatarFor($uid);
        return [
            'user' => ['id' => $uid, 'nume' => (string) ($user['nume'] ?? ''), 'rol' => (string) ($user['rol'] ?? '')],
            'avatar' => $avatar,
            'avatars' => array_map(static fn($k, $a) => ['key' => $k, 'label' => $a['label']], array_keys(ChatInteractivService::AVATARS), ChatInteractivService::AVATARS),
            'ws' => ['url' => $this->wsUrl(), 'token' => $avatar !== null ? $this->token($user) : null],
            'ice' => ChatInteractivService::iceServers(),
            'perms' => [
                'publish_news' => can('chat_interactiv', 'publish_news'),
                'assign_tasks' => can('chat_interactiv', 'assign_tasks'),
                'share_screen' => can('chat_interactiv', 'share_screen'),
            ],
            'users' => can('chat_interactiv', 'assign_tasks') ? $this->service->assignableUsers() : [],
            'board' => $this->service->boardSummary($user),
            'chat' => $this->service->messages($uid),
        ];
    }

    private function token(array $user): string
    {
        $avatar = $this->service->avatarFor((int) $user['id']);
        if ($avatar === null) {
            throw new InvalidArgumentException('Alege mai întâi un avatar.');
        }
        return $this->service->wsToken($user, $avatar);
    }

    private function setAvatar(array $user): array
    {
        $this->service->setAvatar((int) $user['id'], (string) ($_POST['avatar'] ?? ''));
        return ['avatar' => (string) $_POST['avatar'], 'token' => $this->token($user)];
    }

    private function chatRead(array $user): array
    {
        $this->service->markChatRead((int) $user['id'], (int) ($_POST['last_id'] ?? 0));
        return [];
    }

    private function wsUrl(): string
    {
        $configured = ChatInteractivService::env('CHAT_INTERACTIV_WS_URL');
        if ($configured !== '') {
            return $configured;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $port = (int) ChatInteractivService::env('CHAT_INTERACTIV_WS_PORT', '3011');
        return ($https ? 'wss://' : 'ws://') . $host . ':' . $port;
    }

    private function download(array $user): void
    {
        try {
            $file = $this->service->attachment($user, (int) ($_GET['id'] ?? 0));
        } catch (Throwable $e) {
            http_response_code(404);
            exit('Atașamentul nu este disponibil.');
        }
        header('Content-Type: ' . $file['mime']);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $file['name']) . '"');
        header('Content-Length: ' . (string) filesize($file['path']));
        readfile($file['path']);
        exit;
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        // Diagnostic: cererile lente (> 2 s) se notează, cu timpul pierdut ÎNAINTE de controller
        // (pornirea aplicației, lacătul sesiunii PHP) separat de timpul din controller.
        $now = microtime(true);
        $start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? $this->startedAt);
        if ($this->startedAt > 0 && $now - $start > 2.0) {
            error_log(sprintf('[ChatInteractiv][lent] %s: total %.2fs = înainte de controller %.2fs + în controller %.2fs',
                $this->action, $now - $start, $this->startedAt - $start, $now - $this->startedAt));
        }
        exit;
    }
}
