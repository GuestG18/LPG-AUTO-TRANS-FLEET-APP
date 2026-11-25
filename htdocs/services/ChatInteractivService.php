<?php
declare(strict_types=1);

/**
 * Chat Interactiv (LPG Connect): persistența lumii 3D — avatare, note de pe panoul informativ,
 * mesaje de chat. Toate verificările de drept pentru note se fac AICI (server), nu în browser.
 *
 * Categorii de note:
 *   noutate  — anunț comun; îl văd toți cei cu acces la pagină (sau publicul din note_permissions);
 *              publică doar cine are chat_interactiv.publish_news.
 *   personal — nota mea; o văd doar proprietarul (owner_id) și autorul (dacă a atribuit-o altcuiva).
 *   termen   — sarcină cu termen; o văd angajatul atribuit și autorul. Atribuirea către altcineva
 *              cere chat_interactiv.assign_tasks.
 * Nimic nu se șterge: „arhivat” scoate nota de pe panoul activ, istoricul rămâne.
 */
class ChatInteractivService
{
    public const ROOM_CODE = 'sediu';
    public const AVATARS = [
        'avaturn_a' => ['label' => 'Avatar A', 'model' => 'avatar-a.glb'],
        'avaturn_b' => ['label' => 'Avatar B', 'model' => 'avatar-b.glb'],
    ];
    public const CATEGORIES = ['noutate', 'personal', 'termen'];
    public const IMPORTANCE = ['normala', 'ridicata', 'critica'];
    public const PRIORITIES = ['scazuta', 'normala', 'ridicata', 'urgenta'];
    public const STATUSES = ['activ', 'in_lucru', 'finalizat', 'arhivat'];
    public const BOARD_LIMIT = 9;          // câte bilețele se desenează pe panoul 3D
    public const MESSAGE_MAX = 1000;
    public const ATTACHMENT_MAX = 5242880; // 5 MB
    public const ATTACHMENT_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

    private static bool $schemaChecked = false;

    public function __construct(private PDO $db)
    {
    }

    // ------------------------------------------------------------------ schema

    public function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;
        $exists = $this->db->query("SHOW TABLES LIKE 'chat_interactiv_chat_reads'")->fetchColumn();
        if ($exists !== false) {
            return;
        }
        $file = dirname(BASE_PATH) . '/database/migrations/2026_11_20_000001_chat_interactiv.sql';
        if (!is_file($file)) {
            throw new RuntimeException('Lipsește migrarea Chat Interactiv: ' . $file);
        }
        $sql = (string) file_get_contents($file);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (preg_split('/;\s*(\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->db->exec($statement);
            }
        }
    }

    public function roomId(): int
    {
        $stmt = $this->db->prepare('SELECT id FROM chat_interactiv_rooms WHERE cod = :cod LIMIT 1');
        $stmt->execute(['cod' => self::ROOM_CODE]);
        $id = (int) $stmt->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $this->db->prepare('INSERT IGNORE INTO chat_interactiv_rooms (cod, nume, max_participanti, activ, created_at) VALUES (:cod, :nume, 16, 1, NOW())')
            ->execute(['cod' => self::ROOM_CODE, 'nume' => 'Sediul LPG']);
        return (int) $this->db->query("SELECT id FROM chat_interactiv_rooms WHERE cod = '" . self::ROOM_CODE . "'")->fetchColumn();
    }

    // ------------------------------------------------------------------ avatar

    public function avatarFor(int $userId): ?string
    {
        $stmt = $this->db->prepare('SELECT avatar_key FROM chat_interactiv_avatars WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        $key = $stmt->fetchColumn();
        return is_string($key) && isset(self::AVATARS[$key]) ? $key : null;
    }

    public function setAvatar(int $userId, string $key): void
    {
        if (!isset(self::AVATARS[$key])) {
            throw new InvalidArgumentException('Avatar necunoscut.');
        }
        $this->db->prepare('INSERT INTO chat_interactiv_avatars (user_id, avatar_key, updated_at) VALUES (:u, :k, NOW())
            ON DUPLICATE KEY UPDATE avatar_key = VALUES(avatar_key), updated_at = VALUES(updated_at)')
            ->execute(['u' => $userId, 'k' => $key]);
    }

    // ------------------------------------------------------------------ token WS

    /**
     * Serverele ICE pentru WebRTC (partajarea ecranului): JSON în CHAT_INTERACTIV_ICE_SERVERS.
     * Implicit un STUN public (doar descoperirea adresei; video-ul merge direct între browsere).
     * Pentru rețele cu NAT strict se adaugă un server TURN (ex. coturn pe VPS).
     */
    public static function iceServers(): array
    {
        $raw = self::env('CHAT_INTERACTIV_ICE_SERVERS');
        $list = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($list) ? $list : [['urls' => 'stun:stun.l.google.com:19302']];
    }

    public static function secret(): string
    {
        return self::env('CHAT_INTERACTIV_SECRET');
    }

    /**
     * Citire .env sigură sub Apache pe Windows (thread-safe): getenv() nu vede mereu valorile puse cu
     * putenv() din alt fir, de aceea se citesc întâi $_ENV / $_SERVER (populate tot de Dotenv).
     */
    public static function env(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        // Ultima rezervă: Dotenv „immutable” nu completează $_ENV dacă variabila pare deja definită în
        // mediul procesului (comun firelor Apache), dar getenv() din firul curent nu o vede.
        static $file = null;
        if ($file === null) {
            $file = [];
            foreach (@file(dirname(BASE_PATH) . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
                    $file[$m[1]] = trim($m[2], " \t\"'");
                }
            }
        }
        return isset($file[$key]) && $file[$key] !== '' ? $file[$key] : $default;
    }

    /**
     * Token scurt (2 minute) pentru intrarea pe serverul WebSocket. Semnat HMAC-SHA256 cu secretul
     * din .env, pe care browserul nu îl vede. Serverul Node verifică semnătura și expirarea.
     */
    public function wsToken(array $user, string $avatarKey): string
    {
        $secret = self::secret();
        if ($secret === '') {
            throw new RuntimeException('CHAT_INTERACTIV_SECRET nu este configurat în .env.');
        }
        $payload = [
            'uid' => (int) $user['id'],
            'name' => (string) ($user['nume'] ?? ('Utilizator ' . (int) $user['id'])),
            'avatar' => $avatarKey,
            'room' => self::ROOM_CODE,
            // Dreptul de a prezenta pe ecranul din cameră (verificat și de serverul WS la share_start).
            'share' => function_exists('can') ? can('chat_interactiv', 'share_screen') : false,
            'exp' => time() + 120,
            'nonce' => bin2hex(random_bytes(8)),
        ];
        $body = self::b64url((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $body . '.' . self::b64url(hash_hmac('sha256', $body, $secret, true));
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    // ------------------------------------------------------------------ chat

    /** Salvează un mesaj trimis prin serverul WS (identitatea a fost verificată acolo prin token). */
    public function storeMessage(int $userId, string $text): array
    {
        $text = $this->cleanText($text, self::MESSAGE_MAX);
        if ($text === '') {
            throw new InvalidArgumentException('Mesaj gol.');
        }
        $user = $this->activeUser($userId);
        if ($user === null || !user_can($user, 'chat_interactiv', 'view')) {
            throw new InvalidArgumentException('Utilizator fără acces.');
        }
        $room = $this->roomId();
        $this->db->prepare('INSERT INTO chat_interactiv_messages (room_id, user_id, mesaj, created_at) VALUES (:r, :u, :m, NOW())')
            ->execute(['r' => $room, 'u' => $userId, 'm' => $text]);
        $id = (int) $this->db->lastInsertId();
        return $this->messageRow($id);
    }

    public function messages(int $userId, int $beforeId = 0, int $limit = 50): array
    {
        $room = $this->roomId();
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT m.id, m.user_id, u.nume, m.mesaj, m.created_at FROM chat_interactiv_messages m
                JOIN utilizatori u ON u.id = m.user_id WHERE m.room_id = :r' . ($beforeId > 0 ? ' AND m.id < :b' : '') .
               ' ORDER BY m.id DESC LIMIT ' . $limit;
        $stmt = $this->db->prepare($sql);
        $params = ['r' => $room];
        if ($beforeId > 0) {
            $params['b'] = $beforeId;
        }
        $stmt->execute($params);
        $rows = array_reverse(array_map([$this, 'formatMessage'], $stmt->fetchAll(PDO::FETCH_ASSOC)));
        return ['messages' => $rows, 'unread' => $this->unreadCount($userId), 'last_read_id' => $this->lastReadId($userId)];
    }

    public function markChatRead(int $userId, int $lastId): void
    {
        $room = $this->roomId();
        $this->db->prepare('INSERT INTO chat_interactiv_chat_reads (user_id, room_id, last_read_id, updated_at) VALUES (:u, :r, :l, NOW())
            ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id)), updated_at = VALUES(updated_at)')
            ->execute(['u' => $userId, 'r' => $room, 'l' => max(0, $lastId)]);
    }

    private function lastReadId(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT last_read_id FROM chat_interactiv_chat_reads WHERE user_id = :u AND room_id = :r');
        $stmt->execute(['u' => $userId, 'r' => $this->roomId()]);
        return (int) $stmt->fetchColumn();
    }

    private function unreadCount(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM chat_interactiv_messages WHERE room_id = :r AND id > :l AND user_id <> :u');
        $stmt->execute(['r' => $this->roomId(), 'l' => $this->lastReadId($userId), 'u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    private function messageRow(int $id): array
    {
        $stmt = $this->db->prepare('SELECT m.id, m.user_id, u.nume, m.mesaj, m.created_at FROM chat_interactiv_messages m JOIN utilizatori u ON u.id = m.user_id WHERE m.id = :id');
        $stmt->execute(['id' => $id]);
        return $this->formatMessage((array) $stmt->fetch(PDO::FETCH_ASSOC));
    }

    private function formatMessage(array $r): array
    {
        return ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id'], 'nume' => (string) $r['nume'], 'mesaj' => (string) $r['mesaj'], 'created_at' => (string) $r['created_at']];
    }

    // ------------------------------------------------------------------ note: citire

    /**
     * Lista unei categorii pentru utilizatorul curent, cu filtrul de stare:
     *   noutate:  active (implicit) | arhivate
     *   personal: active | finalizate | arhivate
     *   termen:   urmatoare | depasite | finalizate | arhivate | active (urmatoare + depasite)
     */
    public function listNotes(array $user, string $category, string $state = ''): array
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Categorie necunoscută.');
        }
        [$visSql, $params] = $this->visibilitySql($user, 'n');
        $params['r'] = $this->roomId();
        $params['cat'] = $category;
        $stateSql = match ($state) {
            'arhivate' => "n.status = 'arhivat'",
            'finalizate' => "n.status = 'finalizat'",
            'urmatoare' => "n.status IN ('activ','in_lucru') AND (n.termen IS NULL OR n.termen >= NOW())",
            'depasite' => "n.status IN ('activ','in_lucru') AND n.termen < NOW()",
            'toate' => '1=1',
            default => $category === 'noutate' ? "n.status <> 'arhivat'" : "n.status IN ('activ','in_lucru')",
        };
        $order = $category === 'noutate' ? 'n.publicat_la DESC' : 'n.termen IS NULL, n.termen ASC, n.id DESC';
        $sql = $this->noteSelect() . " WHERE n.room_id = :r AND n.categorie = :cat AND ($visSql) AND ($stateSql) ORDER BY $order LIMIT 200";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params + ['me' => (int) $user['id']]);
        $rows = array_map(fn($r) => $this->formatNote($r, $user), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['notes' => $rows, 'counts' => $this->counts($user)];
    }

    /** Contoarele pentru filele interfeței. */
    public function counts(array $user): array
    {
        [$visSql, $params] = $this->visibilitySql($user, 'n');
        $params['r'] = $this->roomId();
        $params['me'] = (int) $user['id'];
        $sql = "SELECT
                SUM(n.categorie = 'noutate' AND n.status <> 'arhivat') AS noutati,
                SUM(n.categorie = 'noutate' AND n.status <> 'arhivat' AND rd.citit_la IS NULL AND n.autor_id <> :me) AS noutati_necitite,
                SUM(n.categorie = 'personal' AND n.status IN ('activ','in_lucru')) AS personale,
                SUM(n.categorie = 'termen' AND n.status IN ('activ','in_lucru') AND (n.termen IS NULL OR n.termen >= NOW())) AS urmatoare,
                SUM(n.categorie = 'termen' AND n.status IN ('activ','in_lucru') AND n.termen < NOW()) AS depasite
            FROM chat_interactiv_notes n
            LEFT JOIN chat_interactiv_note_reads rd ON rd.note_id = n.id AND rd.user_id = :me2
            WHERE n.room_id = :r AND ($visSql)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params + ['me2' => (int) $user['id']]);
        return array_map('intval', (array) $stmt->fetch(PDO::FETCH_ASSOC));
    }

    /**
     * Ce se desenează pe panoul 3D: doar noutățile comune active (aceleași pentru toți cei din cameră),
     * cele mai recente BOARD_LIMIT, plus totalul. Noutățile cu public restrâns nu apar pe panoul comun.
     * Notele personale/termenele nu apar niciodată pe panoul fizic (l-ar vedea toți).
     */
    public function boardSummary(array $user): array
    {
        $room = $this->roomId();
        $base = "FROM chat_interactiv_notes n WHERE n.room_id = :r AND n.categorie = 'noutate' AND n.status <> 'arhivat'
                 AND NOT EXISTS (SELECT 1 FROM chat_interactiv_note_permissions p WHERE p.note_id = n.id)
                 AND (n.status <> 'finalizat' OR n.finalizat_la >= DATE_SUB(NOW(), INTERVAL 7 DAY))";
        $stmt = $this->db->prepare('SELECT n.id, n.titlu, n.importanta, n.status, n.publicat_la ' . $base . ' ORDER BY (n.importanta = \'critica\') DESC, n.publicat_la DESC LIMIT ' . self::BOARD_LIMIT);
        $stmt->execute(['r' => $room]);
        $items = array_map(static fn($r) => [
            'id' => (int) $r['id'], 'titlu' => (string) $r['titlu'], 'importanta' => (string) $r['importanta'],
            'status' => (string) $r['status'], 'publicat_la' => (string) $r['publicat_la'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $total = $this->db->prepare('SELECT COUNT(*) ' . $base);
        $total->execute(['r' => $room]);
        return ['items' => $items, 'total' => (int) $total->fetchColumn(), 'counts' => $this->counts($user)];
    }

    public function noteDetail(array $user, int $noteId): array
    {
        $note = $this->loadVisibleNote($user, $noteId);
        $stmt = $this->db->prepare('SELECT h.actiune, h.detalii, h.created_at, u.nume FROM chat_interactiv_note_history h
            LEFT JOIN utilizatori u ON u.id = h.user_id WHERE h.note_id = :id ORDER BY h.id DESC LIMIT 30');
        $stmt->execute(['id' => $noteId]);
        $note['istoric'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($note['categorie'] === 'noutate') {
            $this->db->prepare('INSERT INTO chat_interactiv_note_reads (note_id, user_id, citit_la) VALUES (:n, :u, NOW())
                ON DUPLICATE KEY UPDATE citit_la = citit_la')->execute(['n' => $noteId, 'u' => (int) $user['id']]);
            $note['citit'] = true;
        }
        return $note;
    }

    public function attachment(array $user, int $noteId): array
    {
        $note = $this->loadVisibleNote($user, $noteId);
        $stored = (string) ($note['_atasament_stocat'] ?? '');
        $path = self::attachmentDir() . '/' . basename($stored);
        if ($stored === '' || !is_file($path)) {
            throw new InvalidArgumentException('Nota nu are atașament.');
        }
        return ['path' => $path, 'name' => (string) $note['atasament'], 'mime' => (string) ($note['_atasament_mime'] ?: 'application/octet-stream')];
    }

    // ------------------------------------------------------------------ note: scriere

    public function createNote(array $user, array $in, ?array $file): array
    {
        $category = (string) ($in['categorie'] ?? '');
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Categorie necunoscută.');
        }
        $me = (int) $user['id'];
        $title = $this->cleanText((string) ($in['titlu'] ?? ''), 200);
        if ($title === '') {
            throw new InvalidArgumentException('Titlul este obligatoriu.');
        }
        $desc = $this->cleanText((string) ($in['descriere'] ?? ''), 5000);
        $owner = null;
        if ($category === 'noutate') {
            if (!can('chat_interactiv', 'publish_news')) {
                throw new DomainException('Nu ai dreptul să publici noutăți generale.');
            }
        } else {
            $owner = (int) ($in['owner_id'] ?? 0) ?: $me;
            if ($owner !== $me) {
                if (!can('chat_interactiv', 'assign_tasks')) {
                    throw new DomainException('Nu ai dreptul să atribui note altor angajați.');
                }
                $ownerUser = $this->activeUser($owner);
                if ($ownerUser === null || !user_can($ownerUser, 'chat_interactiv', 'view')) {
                    throw new InvalidArgumentException('Angajatul ales nu are acces la Chat Interactiv.');
                }
            }
        }
        $importance = in_array($in['importanta'] ?? '', self::IMPORTANCE, true) ? (string) $in['importanta'] : 'normala';
        $priority = in_array($in['prioritate'] ?? '', self::PRIORITIES, true) ? (string) $in['prioritate'] : 'normala';
        $due = $this->parseDateTime((string) ($in['termen'] ?? ''));
        if ($category === 'termen' && $due === null) {
            throw new InvalidArgumentException('Termenul (data și ora) este obligatoriu.');
        }
        $reminder = isset($in['reminder_minute']) && $in['reminder_minute'] !== '' ? max(0, min(43200, (int) $in['reminder_minute'])) : null;
        if ($category === 'noutate') {
            $reminder = null;
        }

        $stored = null;
        if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $stored = $this->storeAttachment($file);
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO chat_interactiv_notes (room_id, categorie, titlu, descriere, autor_id, owner_id, importanta, prioritate, status,
                    termen, reminder_minute, atasament_original, atasament_stocat, atasament_mime, publicat_la, created_at, updated_at)
                VALUES (:r, :c, :t, :d, :a, :o, :imp, :pri, \'activ\', :due, :rem, :ao, :as, :am, NOW(), NOW(), NOW())')
                ->execute([
                    'r' => $this->roomId(), 'c' => $category, 't' => $title, 'd' => $desc !== '' ? $desc : null, 'a' => $me, 'o' => $owner,
                    'imp' => $importance, 'pri' => $priority, 'due' => $due, 'rem' => $reminder,
                    'ao' => $stored['original'] ?? null, 'as' => $stored['stored'] ?? null, 'am' => $stored['mime'] ?? null,
                ]);
            $id = (int) $this->db->lastInsertId();
            if ($category === 'noutate') {
                foreach ((array) ($in['public_roluri'] ?? []) as $role) {
                    if (in_array($role, ['admin', 'contabilitate', 'utilizator'], true)) {
                        $this->db->prepare('INSERT IGNORE INTO chat_interactiv_note_permissions (note_id, tip, valoare) VALUES (:n, \'rol\', :v)')->execute(['n' => $id, 'v' => $role]);
                    }
                }
            }
            $this->history($id, $me, 'creare', ['categorie' => $category, 'titlu' => $title, 'owner_id' => $owner]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            if ($stored !== null) {
                @unlink(self::attachmentDir() . '/' . $stored['stored']);
            }
            throw $e;
        }
        return $this->loadVisibleNote($user, $id);
    }

    /** Schimbare de stare / editare / confirmare. Fiecare acțiune își verifică dreptul. */
    public function updateNote(array $user, int $noteId, string $op, array $in): array
    {
        $note = $this->loadVisibleNote($user, $noteId);
        $me = (int) $user['id'];
        $isAuthor = $note['autor_id'] === $me;
        $isOwner = $note['owner_id'] === $me;
        $admin = is_admin();

        switch ($op) {
            case 'ack':
                if ($note['categorie'] !== 'noutate') {
                    throw new InvalidArgumentException('Doar noutățile se confirmă.');
                }
                $this->db->prepare('INSERT INTO chat_interactiv_note_reads (note_id, user_id, citit_la, confirmat_la) VALUES (:n, :u, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE confirmat_la = COALESCE(confirmat_la, NOW())')->execute(['n' => $noteId, 'u' => $me]);
                $this->history($noteId, $me, 'confirmare', null);
                break;

            case 'status':
                $status = (string) ($in['status'] ?? '');
                if (!in_array($status, ['activ', 'in_lucru', 'finalizat'], true)) {
                    throw new InvalidArgumentException('Stare necunoscută.');
                }
                $allowed = $note['categorie'] === 'noutate' ? ($isAuthor || $admin) : ($isAuthor || $isOwner || $admin);
                if (!$allowed) {
                    throw new DomainException('Nu poți schimba starea acestei note.');
                }
                $this->db->prepare('UPDATE chat_interactiv_notes SET status = :s, finalizat_la = ' . ($status === 'finalizat' ? 'NOW()' : 'NULL') . ', arhivat_la = NULL, updated_at = NOW() WHERE id = :id')
                    ->execute(['s' => $status, 'id' => $noteId]);
                $this->history($noteId, $me, 'stare', ['din' => $note['status'], 'in' => $status]);
                break;

            case 'archive':
            case 'restore':
                $allowed = $note['categorie'] === 'personal' ? ($isOwner || $isAuthor || $admin) : ($isAuthor || $admin);
                if (!$allowed) {
                    throw new DomainException('Doar autorul sau administratorul poate arhiva nota.');
                }
                if ($op === 'archive') {
                    $this->db->prepare("UPDATE chat_interactiv_notes SET status = 'arhivat', arhivat_la = NOW(), updated_at = NOW() WHERE id = :id")->execute(['id' => $noteId]);
                } else {
                    $this->db->prepare("UPDATE chat_interactiv_notes SET status = 'activ', arhivat_la = NULL, finalizat_la = NULL, updated_at = NOW() WHERE id = :id")->execute(['id' => $noteId]);
                }
                $this->history($noteId, $me, $op === 'archive' ? 'arhivare' : 'restaurare', null);
                break;

            case 'edit':
                if (!($isAuthor || $admin || ($note['categorie'] === 'personal' && $isOwner))) {
                    throw new DomainException('Doar autorul poate edita nota.');
                }
                $title = $this->cleanText((string) ($in['titlu'] ?? $note['titlu']), 200);
                if ($title === '') {
                    throw new InvalidArgumentException('Titlul este obligatoriu.');
                }
                $desc = $this->cleanText((string) ($in['descriere'] ?? ($note['descriere'] ?? '')), 5000);
                $priority = in_array($in['prioritate'] ?? '', self::PRIORITIES, true) ? (string) $in['prioritate'] : $note['prioritate'];
                $importance = in_array($in['importanta'] ?? '', self::IMPORTANCE, true) ? (string) $in['importanta'] : $note['importanta'];
                $due = array_key_exists('termen', $in) ? $this->parseDateTime((string) $in['termen']) : $note['termen'];
                if ($note['categorie'] === 'termen' && $due === null) {
                    throw new InvalidArgumentException('Termenul (data și ora) este obligatoriu.');
                }
                $reminder = array_key_exists('reminder_minute', $in)
                    ? ($in['reminder_minute'] === '' ? null : max(0, min(43200, (int) $in['reminder_minute'])))
                    : $note['reminder_minute'];
                $this->db->prepare('UPDATE chat_interactiv_notes SET titlu = :t, descriere = :d, prioritate = :p, importanta = :i, termen = :due,
                        reminder_minute = :rem, reminder_trimis_la = IF(:due2 <=> termen AND :rem2 <=> reminder_minute, reminder_trimis_la, NULL), updated_at = NOW() WHERE id = :id')
                    ->execute(['t' => $title, 'd' => $desc !== '' ? $desc : null, 'p' => $priority, 'i' => $importance, 'due' => $due, 'rem' => $reminder,
                        'due2' => $due, 'rem2' => $reminder, 'id' => $noteId]);
                $this->history($noteId, $me, 'editare', ['titlu' => $title]);
                break;

            default:
                throw new InvalidArgumentException('Operație necunoscută.');
        }
        return $this->loadVisibleNote($user, $noteId);
    }

    /** Memento-urile scadente ale utilizatorului (le marchează trimise ca să nu se repete). */
    public function dueReminders(array $user): array
    {
        $me = (int) $user['id'];
        $stmt = $this->db->prepare("SELECT id, titlu, termen, categorie FROM chat_interactiv_notes
            WHERE owner_id = :me AND status IN ('activ','in_lucru') AND reminder_minute IS NOT NULL AND reminder_trimis_la IS NULL
              AND termen IS NOT NULL AND DATE_SUB(termen, INTERVAL reminder_minute MINUTE) <= NOW() LIMIT 10");
        $stmt->execute(['me' => $me]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $rows));
            $this->db->exec("UPDATE chat_interactiv_notes SET reminder_trimis_la = NOW() WHERE id IN ($ids)");
            foreach ($rows as $r) {
                $this->history((int) $r['id'], null, 'memento', null);
            }
        }
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'titlu' => (string) $r['titlu'], 'termen' => (string) $r['termen'], 'categorie' => (string) $r['categorie']], $rows);
    }

    /** Angajații cărora li se pot atribui note (au acces la pagină). */
    public function assignableUsers(): array
    {
        $rows = $this->db->query("SELECT id, nume, rol, status FROM utilizatori WHERE status = 'activ' ORDER BY nume")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            if (user_can($r, 'chat_interactiv', 'view')) {
                $out[] = ['id' => (int) $r['id'], 'nume' => (string) $r['nume']];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ interne

    /** @return array{0:string,1:array} condiția de vizibilitate pentru utilizator */
    private function visibilitySql(array $user, string $a): array
    {
        $me = (int) $user['id'];
        $role = (string) ($user['rol'] ?? '');
        $news = "($a.categorie = 'noutate' AND (
                    NOT EXISTS (SELECT 1 FROM chat_interactiv_note_permissions p WHERE p.note_id = $a.id)
                    OR EXISTS (SELECT 1 FROM chat_interactiv_note_permissions p WHERE p.note_id = $a.id
                               AND ((p.tip = 'rol' AND p.valoare = :vrole) OR (p.tip = 'utilizator' AND p.valoare = :vuid)))
                    OR $a.autor_id = :vme1" . ($role === 'admin' ? ' OR 1=1' : '') . '))';
        $own = "($a.categorie IN ('personal','termen') AND ($a.owner_id = :vme2 OR $a.autor_id = :vme3))";
        return ["$news OR $own", ['vrole' => $role, 'vuid' => (string) $me, 'vme1' => $me, 'vme2' => $me, 'vme3' => $me]];
    }

    private function noteSelect(): string
    {
        return 'SELECT n.*, au.nume AS autor_nume, ow.nume AS owner_nume, rd.citit_la, rd.confirmat_la,
                   (SELECT COUNT(*) FROM chat_interactiv_note_permissions p WHERE p.note_id = n.id) AS public_restrans
                FROM chat_interactiv_notes n
                JOIN utilizatori au ON au.id = n.autor_id
                LEFT JOIN utilizatori ow ON ow.id = n.owner_id
                LEFT JOIN chat_interactiv_note_reads rd ON rd.note_id = n.id AND rd.user_id = :me';
    }

    private function loadVisibleNote(array $user, int $noteId): array
    {
        [$visSql, $params] = $this->visibilitySql($user, 'n');
        $stmt = $this->db->prepare($this->noteSelect() . " WHERE n.id = :id AND ($visSql)");
        $stmt->execute($params + ['id' => $noteId, 'me' => (int) $user['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Aceeași eroare pentru „nu există” și „nu ai voie”: nu divulgăm existența notelor private.
            throw new InvalidArgumentException('Nota nu există sau nu ai acces la ea.');
        }
        return $this->formatNote($row, $user, true);
    }

    private function formatNote(array $r, array $user, bool $internal = false): array
    {
        $me = (int) $user['id'];
        $due = $r['termen'] !== null ? strtotime((string) $r['termen']) : null;
        $state = match (true) {
            $r['status'] === 'arhivat' => 'arhivat',
            $r['status'] === 'finalizat' => 'finalizat',
            $due !== null && $due < time() => 'depasit',
            default => 'urmator',
        };
        $out = [
            'id' => (int) $r['id'],
            'categorie' => (string) $r['categorie'],
            'titlu' => (string) $r['titlu'],
            'descriere' => $r['descriere'] !== null ? (string) $r['descriere'] : null,
            'autor_id' => (int) $r['autor_id'],
            'autor' => (string) $r['autor_nume'],
            'owner_id' => $r['owner_id'] !== null ? (int) $r['owner_id'] : null,
            'owner' => $r['owner_nume'] !== null ? (string) $r['owner_nume'] : null,
            'importanta' => (string) $r['importanta'],
            'prioritate' => (string) $r['prioritate'],
            'status' => (string) $r['status'],
            'stare' => $state,
            'termen' => $r['termen'] !== null ? (string) $r['termen'] : null,
            'reminder_minute' => $r['reminder_minute'] !== null ? (int) $r['reminder_minute'] : null,
            'atasament' => $r['atasament_original'] !== null ? (string) $r['atasament_original'] : null,
            'publicat_la' => (string) $r['publicat_la'],
            'finalizat_la' => $r['finalizat_la'],
            'arhivat_la' => $r['arhivat_la'],
            'citit' => $r['citit_la'] !== null,
            'confirmat' => $r['confirmat_la'] !== null,
            'public_restrans' => (int) $r['public_restrans'] > 0,
            'poate' => [
                'edita' => (int) $r['autor_id'] === $me || is_admin() || ($r['categorie'] === 'personal' && (int) $r['owner_id'] === $me),
                'stare' => $r['categorie'] === 'noutate' ? ((int) $r['autor_id'] === $me || is_admin()) : ((int) $r['autor_id'] === $me || (int) $r['owner_id'] === $me || is_admin()),
                'arhiva' => $r['categorie'] === 'personal' ? ((int) $r['owner_id'] === $me || (int) $r['autor_id'] === $me || is_admin()) : ((int) $r['autor_id'] === $me || is_admin()),
            ],
        ];
        if ($internal) {
            $out['_atasament_stocat'] = $r['atasament_stocat'];
            $out['_atasament_mime'] = $r['atasament_mime'];
        }
        return $out;
    }

    public static function publicNote(array $note): array
    {
        unset($note['_atasament_stocat'], $note['_atasament_mime']);
        return $note;
    }

    private function history(int $noteId, ?int $userId, string $action, ?array $details): void
    {
        $this->db->prepare('INSERT INTO chat_interactiv_note_history (note_id, user_id, actiune, detalii, created_at) VALUES (:n, :u, :a, :d, NOW())')
            ->execute(['n' => $noteId, 'u' => $userId, 'a' => $action, 'd' => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null]);
    }

    private function activeUser(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT id, nume, rol, status FROM utilizatori WHERE id = :id AND status = 'activ'");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Text simplu: fără caractere de control, lungime limitată. Escaparea HTML se face la afișare. */
    private function cleanText(string $text, int $max): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = trim(str_replace("\r\n", "\n", $text));
        return mb_substr($text, 0, $max);
    }

    private function parseDateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d'] as $fmt) {
            $d = DateTime::createFromFormat('!' . $fmt, $value);
            if ($d !== false) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException('Dată invalidă.');
    }

    public static function attachmentDir(): string
    {
        $dir = BASE_PATH . '/uploads/chat_interactiv';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        return $dir;
    }

    private function storeAttachment(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new InvalidArgumentException('Atașamentul nu a putut fi încărcat.');
        }
        if ((int) $file['size'] > self::ATTACHMENT_MAX) {
            throw new InvalidArgumentException('Atașamentul depășește 5 MB.');
        }
        $original = mb_substr(basename((string) $file['name']), 0, 200);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ATTACHMENT_EXT, true)) {
            throw new InvalidArgumentException('Tip de fișier nepermis.');
        }
        $mime = function_exists('mime_content_type') ? (string) @mime_content_type((string) $file['tmp_name']) : '';
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], self::attachmentDir() . '/' . $stored)) {
            throw new RuntimeException('Atașamentul nu a putut fi salvat.');
        }
        return ['original' => $original, 'stored' => $stored, 'mime' => $mime];
    }
}
