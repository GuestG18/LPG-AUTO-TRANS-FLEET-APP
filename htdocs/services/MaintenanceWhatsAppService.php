<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/DriverWhatsAppModel.php';
require_once __DIR__ . '/../models/InterventionWorkflowModel.php';
require_once __DIR__ . '/AutoComponentCatalogService.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../models/AccessRightsModel.php';
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/audit_context.php';
require_once __DIR__ . '/DriverFirstMovementService.php';

/**
 * Programari service pe WhatsApp (Mentenanta V2, etapa 1), prin BaileyBot.
 *
 * Cererea soferului este o interventie Interventii V2 (mentenanta_interventii_programate) in statusul
 * "De verificat"; aprobarea operatorului o trece in "Programata" cu responsabil tehnic si data,
 * prin aceleasi metode ca pagina (InterventionWorkflowModel::saveFromForm / updateFromPanel).
 * Nu se creeaza nimic in Km service.
 *
 *   Sofer:     PROGRAMARE SERVICE / SERVICE / REVIZIE / REPARAȚIE  -> vehicul, data, descriere, confirmare
 *              STATUS SERVICE                                       -> cererile si interventiile deschise
 *   Operator:  cardul cererii (Aprobă / Respinge / Alte acțiuni), CERERI SERVICE, CERERE 123
 *   Coordonator (etapa 2): cardul interventiei (Preiau / Lucrări, Detalii, Reasignare), trimis cand
 *              devine responsabil; INTERVENTIILE MELE, INTERVENTIE 123. Lucrari: adaugare din registrul
 *              de componente (filtrat pe tipul vehiculului / semiremorca cuplata), confirmare, excludere cu
 *              motiv, operatiune, progres, diagnostic, costuri (cu interventions_costs), informatii
 *              componenta; diagnostic tehnic, status; finalizare prin InterventionWorkflowModel::finalize()
 *              cu confirmarea explicita a fiecarei inlocuiri. Orice modificare se arata intai pentru
 *              confirmare si se salveaza doar daca interventia nu s-a schimbat intre timp.
 *   Oricine:   MENIU (doar actiunile permise), ANULEAZĂ
 *
 * Starea conversatiei sta in Fleet (whatsapp_maintenance_sessions, pe numar de telefon); botul
 * doar transporta. Butoanele unei conversatii poarta versiunea ei (mnt:s:<versiune>:<optiune>):
 * un buton vechi nu mai face nimic. Butoanele operatorului poarta versiunea interventiei
 * (updated_at): daca interventia s-a schimbat intre timp, actiunea e refuzata si cardul retrimis.
 *
 * Soferi: aceeasi regula de test ca notificarile (DRIVER_WA_TEST_MODE / DRIVER_WA_TEST_DRIVER_IDS).
 * Operatori: utilizatorii din MAINT_WA_USER_IDS cu dreptul mentenanta.interventions_technical
 * (lista goala = nimeni). Fiecare actiune reverifica dreptul pe server.
 */
class MaintenanceWhatsAppService
{
    public const ACTION_PREFIX = 'mnt:';

    private const MIN_DESCRIPTION_CHARS = 5;
    private const MAX_DESCRIPTION_CHARS = 2000;
    private const MAX_DAYS_AHEAD = 120;
    /** Soferul are atat timp sa raspunda la o cerere de detalii a operatorului. */
    private const DETAILS_SESSION_HOURS = 24;
    /** Notificarile catre sofer: doar cererile recente. */
    private const NOTIFY_WINDOW_DAYS = 60;

    private InterventionWorkflowModel $workflow;
    private array $config;
    /** @var array<int, array{id:int, nume:string, phone:string, user:array}>|null */
    private ?array $reviewersCache = null;
    private ?array $bossesCache = null;
    /** Expeditorul mesajului curent, daca e din conducere (setat in dispatch). */
    private ?array $bossActor = null;
    /** Mesajele trimise ca raspuns la mesajul primit curent (cheie de deduplicare). */
    private string $replyKey = '';
    private int $replyCount = 0;

    public function __construct(private PDO $db, private DriverWhatsAppModel $outbox, ?InterventionWorkflowModel $workflow = null, ?array $config = null)
    {
        $this->config = $config ?? self::config();
        $this->workflow = $workflow ?? new InterventionWorkflowModel($db);
    }

    /** @return array{enabled: bool, user_ids: array<int,int>, session_minutes: int, voice_user_ids: array<int,int>, driver_test_mode: bool, driver_ids: array<int,int>} */
    public static function config(): array
    {
        $env = static fn(string $name, string $default = ''): string => is_string($v = getenv($name)) && trim($v) !== '' ? trim($v) : $default;
        $drivers = DriverFirstMovementService::config();

        return [
            'enabled' => in_array(strtolower($env('MAINT_WA_ENABLED', '0')), ['1', 'true', 'yes', 'on', 'da'], true),
            'user_ids' => array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $env('MAINT_WA_USER_IDS')) ?: []))),
            'session_minutes' => max(5, (int) $env('MAINT_WA_SESSION_MINUTES', '30')),
            // Conducerea: vede in meniu "Inregistreaza mesaj" (anunt vocal catre soferi).
            'voice_user_ids' => array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $env('VOICE_WA_USER_IDS')) ?: []))),
            'driver_test_mode' => $drivers['test_mode'],
            'driver_ids' => $drivers['test_driver_ids'],
        ];
    }

    public static function schemaStatements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS whatsapp_maintenance_sessions (
                phone VARCHAR(20) NOT NULL PRIMARY KEY,
                role VARCHAR(10) NOT NULL,
                actor_id INT UNSIGNED NOT NULL,
                flow VARCHAR(30) NOT NULL,
                step VARCHAR(30) NOT NULL,
                data_json JSON NULL,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                expires_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS whatsapp_maintenance_requests (
                intervention_id INT UNSIGNED NOT NULL PRIMARY KEY,
                driver_id INT UNSIGNED NOT NULL,
                source_wa_message_id VARCHAR(64) NULL,
                notified_state VARCHAR(80) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_whatsapp_maintenance_request_source (source_wa_message_id),
                KEY idx_whatsapp_maintenance_request_driver (driver_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS whatsapp_maintenance_assignments (
                intervention_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (intervention_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // Anunturile vocale ale conducerii: mesajul vocal si lista celor care vor fi sunati.
            "CREATE TABLE IF NOT EXISTS whatsapp_voice_broadcasts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                created_by_user_id INT UNSIGNED NOT NULL,
                category VARCHAR(20) NOT NULL,
                audio_wa_message_id VARCHAR(64) NOT NULL,
                audio_seconds INT UNSIGNED NULL,
                scheduled_at DATETIME NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL,
                KEY idx_whatsapp_voice_broadcasts_status (status, scheduled_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS whatsapp_voice_broadcast_recipients (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                broadcast_id INT UNSIGNED NOT NULL,
                recipient_type VARCHAR(10) NOT NULL,
                recipient_id INT UNSIGNED NOT NULL,
                nume VARCHAR(120) NOT NULL,
                phone VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                lock_token VARCHAR(32) NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                result_detail VARCHAR(120) NULL,
                called_at DATETIME NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_whatsapp_voice_recipient (broadcast_id, phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    /** Categoriile de destinatari pentru anunturile vocale [cheie => eticheta]. */
    public const VOICE_CATEGORIES = ['drivers' => '🚚 Șoferi', 'users' => '🧑‍💼 Operatori', 'all' => '👥 Toți', 'other' => '📱 Alt număr'];

    /**
     * Numere scrise de conducere (persoane din afara aplicatiei): "0722 123 456 Ion Popescu, +40733..."
     *
     * @return array{people: array<int, array{type:string, id:int, nume:string, phone:string}>, invalid: array<int,string>}
     */
    public static function parseExtraNumbers(?string $text): array
    {
        $people = [];
        $invalid = [];
        foreach (preg_split('/[,;\n]+/', (string) $text) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (preg_match('/^(\+?[\d\s().\/-]{9,20}?)\s*([^\d+].*)?$/u', $entry, $m) !== 1
                || ($phone = NotificationRuleModel::normalizePhone(trim($m[1]))) === null) {
                $invalid[] = $entry;
                continue;
            }
            $name = trim((string) ($m[2] ?? ''));
            $people[$phone] = ['type' => 'external', 'id' => 0, 'nume' => $name !== '' ? mb_substr($name, 0, 60) : $phone, 'phone' => $phone];
        }

        return ['people' => array_values($people), 'invalid' => $invalid];
    }

    /**
     * Cine primeste anuntul vocal: soferii activi si/sau utilizatorii activi Fleet, cu telefon valid,
     * fara dubluri de telefon. In modul test doar soferii de test (utilizatorii: toti, vezi mai jos).
     *
     * @return array<int, array{type:string, id:int, nume:string, phone:string}>
     */
    public function voiceRecipients(string $category): array
    {
        $rows = [];
        if ($category === 'drivers' || $category === 'all') {
            $sql = "SELECT id, nume, telefon FROM soferi WHERE status = 'activ' AND (employment_status IS NULL OR employment_status = 'active')";
            $ids = $this->config['driver_test_mode'] ? ($this->config['driver_ids'] ?: [0]) : null;
            foreach ($this->selectPeople($sql, $ids) as $r) {
                $rows[] = ['type' => 'driver'] + $r;
            }
        }
        if ($category === 'users' || $category === 'all') {
            // Toti utilizatorii activi din aplicatie (ca in pagina Utilizatori). In test, apelantul vocal
            // suna oricum doar numerele din WA_CALL_ALLOWED_TO; restul apar in rezumat ca sarite.
            $sql = "SELECT id, nume, telefon FROM utilizatori WHERE status = 'activ'";
            foreach ($this->selectPeople($sql, null) as $r) {
                $rows[] = ['type' => 'user'] + $r;
            }
        }
        $byPhone = [];
        foreach ($rows as $r) {
            $byPhone[$r['phone']] ??= $r;
        }

        return array_values($byPhone);
    }

    /** @return array<int, array{id:int, nume:string, phone:string}> */
    private function selectPeople(string $sql, ?array $ids): array
    {
        $params = [];
        if ($ids !== null) {
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_map('intval', $ids);
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY nume');
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $phone = NotificationRuleModel::normalizePhone((string) ($row['telefon'] ?? ''));
            if ($phone !== null) {
                $out[] = ['id' => (int) $row['id'], 'nume' => (string) $row['nume'], 'phone' => $phone];
            }
        }

        return $out;
    }

    /** DDL: doar in afara unei tranzactii. */
    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $this->outbox->ensureSchema();
        InterventionWorkflowModel::ensureSchema($this->db);
        foreach (self::schemaStatements() as $statement) {
            $this->db->exec($statement);
        }
        // Tabele create inainte de programare / apelant: coloanele lipsa se adauga o singura data.
        $columns = [
            ['whatsapp_voice_broadcasts', 'scheduled_at', 'DATETIME NULL AFTER audio_seconds'],
            ['whatsapp_voice_broadcast_recipients', 'lock_token', 'VARCHAR(32) NULL AFTER status'],
            ['whatsapp_voice_broadcast_recipients', 'attempts', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER lock_token'],
            ['whatsapp_voice_broadcast_recipients', 'result_detail', 'VARCHAR(120) NULL AFTER attempts'],
            ['whatsapp_voice_broadcast_recipients', 'called_at', 'DATETIME NULL AFTER result_detail'],
        ];
        $exists = $this->db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        foreach ($columns as [$table, $column, $definition]) {
            $exists->execute([':t' => $table, ':c' => $column]);
            if ((int) $exists->fetchColumn() === 0) {
                $this->db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            }
        }
        $done = true;
    }

    // -------------------------------------------------------------------------
    // Text
    // -------------------------------------------------------------------------

    public static function plain(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));
        // Diacriticele romanesti explicit: iconv pe Windows scrie "â" ca "^a".
        $text = strtr($text, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($ascii !== false) {
            $text = $ascii;
        }

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9:.\/ ]+/', ' ', $text) ?? '') ?? '');
    }

    /**
     * Comanda scrisa, sau null.
     *
     * @return array{command: string, arg?: string|int}|null
     */
    public static function parseCommand(?string $text): ?array
    {
        $plain = trim(str_replace(['.', ':', '/'], ' ', self::plain($text)));
        $plain = (string) preg_replace('/\s+/', ' ', $plain);
        if (in_array($plain, ['meniu', 'menu', 'start'], true)) {
            return ['command' => 'menu'];
        }
        if (in_array($plain, ['programare service', 'programare', 'service', 'programare revizie', 'programare reparatie', 'revizie', 'reparatie'], true)) {
            $type = str_contains($plain, 'revizie') ? 'intretinere' : (str_contains($plain, 'reparatie') ? 'reparatie' : '');
            return ['command' => 'request', 'arg' => $type];
        }
        if (in_array($plain, ['status service', 'status programare', 'status programari', 'programarile mele'], true)) {
            return ['command' => 'status'];
        }
        if (in_array($plain, ['cereri service', 'cereri programare', 'cereri'], true)) {
            return ['command' => 'requests'];
        }
        if (in_array($plain, ['interventiile mele', 'interventii', 'lucrari', 'lucrarile mele'], true)) {
            return ['command' => 'mine'];
        }
        if (in_array($plain, ['inregistreaza mesaj', 'mesaj vocal', 'anunt vocal'], true)) {
            return ['command' => 'voice'];
        }
        if (preg_match('/^(cerere|interventie) #?\s*(\d+)$/', $plain, $m) === 1) {
            return ['command' => 'request_card', 'arg' => (int) $m[2]];
        }

        return null;
    }

    public static function isCancel(?string $text): bool
    {
        return in_array(self::plain($text), ['anuleaza', 'anulez', 'anulare', 'renunt', 'stop', 'cancel'], true);
    }

    /**
     * Data preferata scrisa de sofer: "30.10", "30.10.2026", "30/10 14:00", "azi", "mâine 9:30".
     *
     * @return array{date: string, time: ?string}|array{error: string}
     */
    public static function parseDate(?string $text, ?int $now = null): array
    {
        $now ??= time();
        $today = strtotime(date('Y-m-d', $now));
        $plain = self::plain($text);
        $time = null;
        // Ora doar dupa un spatiu: "12.11" singur e o data, nu ora 12:11.
        if (preg_match('/\s(?:ora\s*)?(\d{1,2})[:.](\d{2})$/', $plain, $m) === 1 && (int) $m[1] < 24 && (int) $m[2] < 60) {
            $time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            $plain = trim(substr($plain, 0, -strlen($m[0])));
        } elseif (preg_match('/\sora\s*(\d{1,2})$/', $plain, $m) === 1 && (int) $m[1] < 24) {
            $time = sprintf('%02d:00', (int) $m[1]);
            $plain = trim(substr($plain, 0, -strlen($m[0])));
        }

        $date = null;
        $relative = ['azi' => 0, 'astazi' => 0, 'maine' => 1, 'poimaine' => 2];
        if (isset($relative[$plain])) {
            $date = strtotime('+' . $relative[$plain] . ' day', $today);
        } elseif (preg_match('/^(\d{1,2})[.\/\- ](\d{1,2})(?:[.\/\- ](\d{2}|\d{4}))?$/', $plain, $m) === 1) {
            $year = isset($m[3]) ? (int) (strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]) : (int) date('Y', $now);
            if (!checkdate((int) $m[2], (int) $m[1], $year)) {
                return ['error' => 'Data nu este validă. Scrieți-o ca zi.lună, de exemplu 30.10.'];
            }
            $date = mktime(0, 0, 0, (int) $m[2], (int) $m[1], $year);
            // Fara an: o zi deja trecuta inseamna anul urmator (ex. 05.01 scris in decembrie).
            if (!isset($m[3]) && $date < $today && checkdate((int) $m[2], (int) $m[1], $year + 1)) {
                $date = mktime(0, 0, 0, (int) $m[2], (int) $m[1], $year + 1);
            }
        }
        if ($date === null || $date === false) {
            return ['error' => 'Nu am înțeles data. Scrieți-o ca zi.lună, de exemplu 30.10 sau 30.10 14:00.'];
        }
        if ($date < $today) {
            return ['error' => 'Data este în trecut. Alegeți o dată de azi încolo.'];
        }
        if ($date > strtotime('+' . self::MAX_DAYS_AHEAD . ' days', $today)) {
            return ['error' => 'Data este prea departe (maximum ' . self::MAX_DAYS_AHEAD . ' de zile). Alegeți o dată mai apropiată.'];
        }

        return ['date' => date('Y-m-d', $date), 'time' => $time];
    }

    private static function dateLabel(?string $date, ?string $time = null): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        $label = date('d.m.Y', (int) strtotime($date));

        return $time !== null && $time !== '' ? $label . ', ora ' . substr($time, 0, 5) : $label;
    }

    private static function typeLabel(string $type): string
    {
        return $type === 'reparatie' ? 'Reparație' : 'Revizie / întreținere';
    }

    // -------------------------------------------------------------------------
    // Cine scrie
    // -------------------------------------------------------------------------

    /** @return array{id:int, nume:string}|null soferul activ cu acest numar, daca are voie (modul test) */
    public function driverByPhone(string $phone): ?array
    {
        $driver = $this->outbox->findActiveDriverByPhone($phone);
        if ($driver === null) {
            return null;
        }
        if ($this->config['driver_test_mode'] && !in_array($driver['id'], $this->config['driver_ids'], true)) {
            return null;
        }

        return $driver;
    }

    /** @return array<int, array{id:int, nume:string, phone:string, user:array}> operatorii care primesc si decid cererile */
    public function reviewers(): array
    {
        if ($this->reviewersCache !== null) {
            return $this->reviewersCache;
        }
        if ($this->config['user_ids'] === []) {
            return $this->reviewersCache = [];
        }
        $placeholders = implode(',', array_fill(0, count($this->config['user_ids']), '?'));
        $stmt = $this->db->prepare("SELECT id, telefon FROM utilizatori WHERE id IN ($placeholders) ORDER BY id");
        $stmt->execute($this->config['user_ids']);
        $users = new UserModel($this->db);
        $reviewers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $phone = NotificationRuleModel::normalizePhone((string) ($row['telefon'] ?? ''));
            $user = $users->findAuthUserById((int) $row['id']);
            if ($phone === null || $user === null || !user_can($user, 'mentenanta', 'interventions_technical')) {
                continue;
            }
            $reviewers[] = ['id' => (int) $user['id'], 'nume' => (string) $user['nume'], 'phone' => $phone, 'user' => $user];
        }

        return $this->reviewersCache = $reviewers;
    }

    public function reviewerByPhone(string $phone): ?array
    {
        $matches = array_values(array_filter($this->reviewers(), static fn(array $r): bool => $r['phone'] === $phone));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array{id:int, nume:string, phone:string}|null utilizatorul din VOICE_WA_USER_IDS cu acest telefon */
    public function bossByPhone(string $phone): ?array
    {
        if ($this->bossesCache === null) {
            $this->bossesCache = [];
            $ids = $this->config['voice_user_ids'] ?? [];
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $this->db->prepare("SELECT id, telefon FROM utilizatori WHERE id IN ($placeholders) ORDER BY id");
                $stmt->execute($ids);
                $users = new UserModel($this->db);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $bossPhone = NotificationRuleModel::normalizePhone((string) ($row['telefon'] ?? ''));
                    $user = $users->findAuthUserById((int) $row['id']);
                    if ($bossPhone !== null && $user !== null) {
                        $this->bossesCache[] = ['id' => (int) $user['id'], 'nume' => (string) $user['nume'], 'phone' => $bossPhone];
                    }
                }
            }
        }
        $matches = array_values(array_filter($this->bossesCache, static fn(array $b): bool => $b['phone'] === $phone));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function reviewerById(int $userId): ?array
    {
        foreach ($this->reviewers() as $reviewer) {
            if ($reviewer['id'] === $userId) {
                return $reviewer;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Sesiunea (pe numar de telefon)
    // -------------------------------------------------------------------------

    private function loadSession(string $phone): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_maintenance_sessions WHERE phone = :p');
        $stmt->execute([':p' => $phone]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['data'] = json_decode((string) ($row['data_json'] ?? ''), true) ?: [];
        $row['version'] = (int) $row['version'];
        $row['actor_id'] = (int) $row['actor_id'];
        $row['expired'] = strtotime((string) $row['expires_at']) < time();

        return $row;
    }

    /** @return int versiunea noua */
    private function saveSession(string $phone, string $role, int $actorId, string $flow, string $step, array $data, ?int $minutes = null): int
    {
        $now = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', time() + 60 * ($minutes ?? $this->config['session_minutes']));
        $this->db->prepare("
            INSERT INTO whatsapp_maintenance_sessions (phone, role, actor_id, flow, step, data_json, version, expires_at, updated_at)
            VALUES (:p, :r, :a, :f, :s, :d, 1, :e, :now)
            ON DUPLICATE KEY UPDATE role = VALUES(role), actor_id = VALUES(actor_id), flow = VALUES(flow), step = VALUES(step),
                data_json = VALUES(data_json), version = version + 1, expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)
        ")->execute([':p' => $phone, ':r' => $role, ':a' => $actorId, ':f' => $flow, ':s' => $step,
            ':d' => json_encode($data, JSON_UNESCAPED_UNICODE), ':e' => $expires, ':now' => $now]);
        $stmt = $this->db->prepare('SELECT version FROM whatsapp_maintenance_sessions WHERE phone = :p');
        $stmt->execute([':p' => $phone]);

        return (int) $stmt->fetchColumn();
    }

    /** Pune sesiunea inapoi exact cum era (aceeasi versiune: butoanele mesajului raman valabile), cu termen nou. */
    private function restoreSession(array $session): void
    {
        $this->db->prepare('
            REPLACE INTO whatsapp_maintenance_sessions (phone, role, actor_id, flow, step, data_json, version, expires_at, updated_at)
            VALUES (:p, :r, :a, :f, :s, :d, :v, :e, :now)
        ')->execute([':p' => $session['phone'], ':r' => $session['role'], ':a' => $session['actor_id'], ':f' => $session['flow'], ':s' => $session['step'],
            ':d' => json_encode($session['data'], JSON_UNESCAPED_UNICODE), ':v' => $session['version'],
            ':e' => date('Y-m-d H:i:s', time() + 60 * $this->config['session_minutes']), ':now' => date('Y-m-d H:i:s')]);
    }

    private function clearSession(string $phone): void
    {
        $this->db->prepare('DELETE FROM whatsapp_maintenance_sessions WHERE phone = :p')->execute([':p' => $phone]);
    }

    // -------------------------------------------------------------------------
    // Trimitere
    // -------------------------------------------------------------------------

    /**
     * @param array{role:string, id:int} $to
     * @param array<int, array{id:string, title:string}> $options
     */
    private function send(array $to, string $purpose, string $body, array $options = [], ?string $dedupeKey = null, ?int $interventionId = null): int
    {
        // Toate optiunile pleaca la bot: el alege forma (pana la 3 butoane, lista "Alege" pana la 10,
        // peste atat text numerotat). Raspunsul scris (1, 2, ...) e citit oricum din sesiune.
        $dedupeKey ??= 'mnt:reply:' . $this->replyKey . ':' . (++$this->replyCount);
        $body = mb_substr($body, 0, 3800);

        return $to['role'] === 'driver'
            ? $this->outbox->enqueue($purpose, $to['id'], $body, $options, $dedupeKey, 'intervention', $interventionId)
            : $this->outbox->enqueueForUser($purpose, $to['id'], $body, $options, $dedupeKey, 'intervention', $interventionId);
    }

    /**
     * Intrebare cu optiuni in conversatie: salveaza pasul si trimite butoanele cu versiunea lui.
     *
     * @param array<int, array{0:string, 1:string}> $choices [cheie, eticheta]
     */
    private function ask(array $actor, string $flow, string $step, array $data, string $body, array $choices = [], ?int $minutes = null): void
    {
        $data['choices'] = $choices;
        $version = $this->saveSession($actor['phone'], $actor['role'], $actor['id'], $flow, $step, $data, $minutes);
        $options = array_map(static fn(array $c): array => ['id' => self::ACTION_PREFIX . 's:' . $version . ':' . $c[0], 'title' => $c[1]], $choices);
        $this->send($actor, 'maintenance_prompt', $body, $options, null, isset($data['intervention_id']) ? (int) $data['intervention_id'] : null);
    }

    /** Optiunea aleasa in pasul curent: butonul (cu versiunea sesiunii), numarul sau textul ei. */
    private static function choice(array $session, ?string $actionId, ?string $text): ?string
    {
        $choices = $session['data']['choices'] ?? [];
        if ($actionId !== null && preg_match('/^mnt:s:(\d+):(.+)$/', $actionId, $m) === 1) {
            return (int) $m[1] === $session['version'] && in_array($m[2], array_column($choices, 0), true) ? $m[2] : null;
        }
        $plain = self::plain($text);
        if (preg_match('/^(\d{1,2})\.?$/', $plain, $m) === 1 && isset($choices[(int) $m[1] - 1])) {
            return $choices[(int) $m[1] - 1][0];
        }
        foreach ($choices as $c) {
            if ($plain !== '' && self::plain($c[1]) === $plain) {
                return $c[0];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Mesaj primit
    // -------------------------------------------------------------------------

    /**
     * Mesajul e pentru acest flux? (comanda, buton mnt:, sau conversatie in curs). Fara efecte.
     */
    public function claims(array $message): bool
    {
        if (!$this->config['enabled']) {
            return false;
        }
        $phone = (string) ($message['phone'] ?? '');
        $actionId = isset($message['action_id']) ? (string) $message['action_id'] : null;
        if ($actionId !== null && str_starts_with($actionId, self::ACTION_PREFIX)) {
            return true;
        }
        $command = self::parseCommand($message['text'] ?? null);
        if ($command !== null) {
            return $this->driverByPhone($phone) !== null || $this->reviewerByPhone($phone) !== null || $this->bossByPhone($phone) !== null;
        }
        $session = preg_match('/^\+\d{10,15}$/', $phone) === 1 ? $this->loadSession($phone) : null;

        return $session !== null && !$session['expired'];
    }

    /**
     * @param array{wa_message_id: string, phone: string, kind: string, action_id?: ?string, text?: ?string} $message
     * @return array{handled: bool, outcome: string, intervention_id?: int}
     */
    public function handleInbound(array $message): array
    {
        $waMessageId = trim((string) ($message['wa_message_id'] ?? ''));
        $phone = (string) ($message['phone'] ?? '');
        if (!$this->config['enabled']) {
            return ['handled' => false, 'outcome' => 'maintenance_disabled'];
        }
        if ($waMessageId === '' || preg_match('/^\+\d{10,15}$/', $phone) !== 1) {
            return ['handled' => false, 'outcome' => 'invalid_message'];
        }
        $driver = $this->driverByPhone($phone);
        $reviewer = $this->reviewerByPhone($phone);
        $boss = $this->bossByPhone($phone);
        if ($driver === null && $reviewer === null && $boss === null) {
            // Nici sofer permis, nici operator: nimic nu se salveaza si nu raspundem.
            return ['handled' => false, 'outcome' => 'unknown_sender'];
        }

        $kind = (string) ($message['kind'] ?? 'text');
        $actionId = isset($message['action_id']) ? (string) $message['action_id'] : null;
        $text = isset($message['text']) ? trim((string) $message['text']) : null;

        $inboundId = $this->outbox->logInbound($waMessageId, $driver['id'] ?? null, $kind, $actionId, $text, date('Y-m-d H:i:s'));
        if ($inboundId === null) {
            return ['handled' => true, 'outcome' => 'duplicate'];
        }
        $this->replyKey = $waMessageId;
        $this->replyCount = 0;

        try {
            $media = ['type' => isset($message['media_type']) ? (string) $message['media_type'] : null, 'seconds' => isset($message['media_seconds']) ? (int) $message['media_seconds'] : null];
            $result = $this->dispatch($phone, $driver, $reviewer, $kind, $actionId, $text, $waMessageId, $boss, $media);
        } catch (Throwable $exception) {
            // Mesajul se poate relua (botul reincearca): nu ramane marcat ca primit.
            $this->db->prepare('DELETE FROM whatsapp_inbound_messages WHERE id = :id')->execute([':id' => $inboundId]);
            throw $exception;
        }
        $this->outbox->setInboundOutcome($inboundId, 'mnt_' . $result['outcome']);

        return ['handled' => true] + $result;
    }

    private function dispatch(string $phone, ?array $driver, ?array $reviewer, string $kind, ?string $actionId, ?string $text, string $waMessageId, ?array $boss = null, array $media = []): array
    {
        $driverActor = $driver !== null ? ['role' => 'driver', 'id' => $driver['id'], 'nume' => $driver['nume'], 'phone' => $phone] : null;
        $userActor = $reviewer !== null ? ['role' => 'user', 'id' => $reviewer['id'], 'nume' => $reviewer['nume'], 'phone' => $phone] : null;
        $bossActor = $boss !== null ? ['role' => 'user', 'id' => $boss['id'], 'nume' => $boss['nume'], 'phone' => $phone] : null;
        $this->bossActor = $bossActor;
        $fallback = $driverActor ?? $userActor ?? $bossActor;

        // 1. Butoanele cardului de cerere (operator)
        if ($actionId !== null && preg_match('/^mnt:r:(\d+):(ok|no|more):(\d+)$/', $actionId, $m) === 1) {
            if ($userActor === null) {
                return $this->say($fallback, 'Nu aveți dreptul de a decide cererile de service.', 'not_a_reviewer');
            }
            return $this->reviewAction($userActor, (int) $m[1], $m[2], (int) $m[3]);
        }
        // Butoanele cardului de interventie (coordonator)
        if ($actionId !== null && preg_match('/^mnt:c:(\d+):(take|work|info|reass):(\d+)$/', $actionId, $m) === 1) {
            if ($userActor === null) {
                return $this->say($fallback, 'Nu aveți dreptul de coordonare a intervențiilor.', 'not_a_reviewer');
            }
            return $this->coordAction($userActor, (int) $m[1], $m[2], (int) $m[3]);
        }
        // Anunt vocal salvat: ✏️ Modifica / 🗑️ Anuleaza (conducerea)
        if ($actionId !== null && preg_match('/^mnt:v:(\d+):(edit|cancel)$/', $actionId, $m) === 1) {
            return $this->voiceBroadcastAction($bossActor, (int) $m[1], $m[2]);
        }
        // 2. Meniul (butoane din versiunea fara sesiune)
        if ($actionId !== null && preg_match('/^mnt:m:(request|status|requests|mine|voice)$/', $actionId, $m) === 1) {
            return $this->command(['command' => $m[1], 'arg' => ''], $driverActor, $userActor, $phone);
        }
        // 3. Comenzi scrise (incep o conversatie noua)
        $command = $kind === 'text' ? self::parseCommand($text) : null;
        if ($command !== null) {
            return $this->command($command, $driverActor, $userActor, $phone);
        }

        $session = $this->loadSession($phone);
        if ($session === null || $session['expired']) {
            if ($session !== null) {
                $this->clearSession($phone);
            }
            if ($actionId !== null && str_starts_with($actionId, 'mnt:s:')) {
                return $this->say($fallback, 'Această opțiune nu mai este valabilă. Scrieți *MENIU* pentru a începe din nou.', 'stale_option');
            }
            return ['outcome' => 'no_session'];
        }
        if ($session['flow'] === 'menu') {
            // Meniul e comun soferului si operatorului (acelasi numar): fiecare comanda isi verifica rolul.
            $choice = self::choice($session, $actionId, $text);
            if ($choice !== null) {
                $result = $this->command(['command' => $choice, 'arg' => ''], $driverActor, $userActor, $phone);
                // Raspunsurile doar informative (status, liste) lasa meniul deschis: urmatorul "4" e tot o alegere
                // din meniu, nu un mesaj liber (care altfel ar ajunge, de ex., la tichetul de suport deschis).
                if (in_array($result['outcome'], ['status', 'status_empty', 'requests', 'requests_empty', 'mine', 'mine_empty', 'not_a_driver', 'not_a_reviewer', 'not_a_boss'], true)) {
                    $this->restoreSession($session);
                }
                return $result;
            }
            if ($actionId !== null && str_starts_with($actionId, 'mnt:s:')) {
                return $this->say($fallback, 'Această opțiune nu mai este valabilă. Scrieți *MENIU* pentru a începe din nou.', 'stale_option');
            }
            $this->clearSession($phone);
            return ['outcome' => 'no_session'];
        }
        $actor = $session['role'] === 'driver' ? $driverActor : ($session['flow'] === 'voice' ? $bossActor : $userActor);
        if ($actor === null || $actor['id'] !== $session['actor_id']) {
            // Dreptul s-a pierdut intre timp (sofer dezactivat, operator scos din lista).
            $this->clearSession($phone);
            return $this->say($fallback, 'Conversația a fost închisă: nu mai aveți acces la această acțiune.', 'access_lost');
        }
        if (self::isCancel($text)) {
            $this->clearSession($phone);
            return $this->say($actor, 'Am anulat. Scrieți *MENIU* oricând pentru a începe din nou.', 'cancelled');
        }
        if ($actionId !== null && str_starts_with($actionId, 'mnt:s:') && self::choice($session, $actionId, null) === null) {
            return $this->say($actor, 'Această opțiune nu mai este valabilă (ați răspuns deja sau conversația s-a schimbat). Folosiți ultimul mesaj primit.', 'stale_option');
        }

        return match ($session['flow']) {
            'review' => $this->reviewStep($actor, $session, $kind, $actionId, $text),
            'coord' => $this->coordStep($actor, $session, $kind, $actionId, $text),
            'voice' => $this->voiceStep($actor, $session, $kind, $actionId, $text, $media, $waMessageId),
            default => $this->driverStep($actor, $session, $kind, $actionId, $text, $waMessageId),
        };
    }

    /**
     * Anuntul vocal al conducerii: mesajul vocal -> categoria de personal -> confirmare -> lista salvata.
     * Pasii: record, category, confirm.
     */
    private function voiceStep(array $actor, array $session, string $kind, ?string $actionId, ?string $text, array $media, string $waMessageId): array
    {
        $choice = self::choice($session, $actionId, $text);
        if ($choice === 'cancel') {
            $this->clearSession($actor['phone']);
            return $this->say($actor, 'Am anulat. Mesajul vocal nu a fost trimis nimănui.', 'cancelled');
        }
        $data = $session['data'];
        unset($data['choices']);

        if ($session['step'] === 'record') {
            if ($kind === 'media' && ($media['type'] ?? null) === 'audio') {
                $data['audio_wa_message_id'] = $waMessageId;
                $data['audio_seconds'] = (int) ($media['seconds'] ?? 0) ?: null;
                return $this->askVoiceCategory($actor, $data, '✅ Am primit mesajul vocal' . ($data['audio_seconds'] ? ' (' . $data['audio_seconds'] . ' s)' : '') . '.');
            }
            if ($kind === 'media' && ($media['type'] ?? null) === 'audio_failed') {
                $this->restoreSession($session);
                return $this->say($actor, 'Nu am putut salva mesajul vocal. Vă rog să îl trimiteți din nou.', 'voice_save_failed');
            }
            // Text, poza sau fisier: conversatia ramane deschisa pentru mesajul vocal.
            $this->restoreSession($session);
            return $this->say($actor, 'Aștept un *mesaj vocal*: țineți apăsat butonul 🎤 și vorbiți, apoi trimiteți. Pentru a renunța, scrieți *ANULEAZĂ*.', 'voice_waiting');
        }

        if ($session['step'] === 'category') {
            if ($choice === null || !isset(self::VOICE_CATEGORIES[$choice])) {
                return $this->askVoiceCategory($actor, $data, 'Alegeți una dintre opțiuni.');
            }
            if ($choice === 'other') {
                $data['category'] = 'other';
                unset($data['picked']);
                $data['extra'] = [];
                return $this->askVoiceNumber($actor, $data, '');
            }
            unset($data['extra']);
            $recipients = $this->voiceRecipients($choice);
            if ($recipients === []) {
                return $this->askVoiceCategory($actor, $data, 'Nu există nicio persoană activă cu telefon în categoria *' . self::VOICE_CATEGORIES[$choice] . '*. Alegeți alta.');
            }
            $data['category'] = $choice;
            unset($data['picked']);
            if ($choice !== 'all') {
                // Operatori / Soferi: lista din aplicatie, conducerea bifeaza pe cine suna.
                $data['picked'] = [];
                $data['page'] = 0;
                return $this->askVoicePeople($actor, $data, '');
            }
            return $this->askVoiceDay($actor, $data, '*' . self::VOICE_CATEGORIES[$choice] . '*: ' . self::people(count($recipients)) . '.');
        }

        if ($session['step'] === 'people') {
            $everyone = $this->voiceRecipients((string) $data['category']);
            $keys = array_map(static fn(array $r): string => $r['type'][0] . $r['id'], $everyone);
            if ($choice === 'everyone') {
                $data['picked'] = $keys;
            } elseif ($choice === 'extra') {
                return $this->askVoiceNumber($actor, $data, '');
            } elseif ($choice === 'next') {
                $data['page'] = (int) ($data['page'] ?? 0) + 1;
                return $this->askVoicePeople($actor, $data, '');
            } elseif ($choice !== null && preg_match('/^p([du]\d+)$/', $choice, $m) === 1 && in_array($m[1], $keys, true)) {
                $picked = $data['picked'] ?? [];
                $picked = in_array($m[1], $picked, true) ? array_values(array_diff($picked, [$m[1]])) : [...$picked, $m[1]];
                $data['picked'] = $picked;
                return $this->askVoicePeople($actor, $data, '');
            } elseif ($choice !== 'done') {
                return $this->askVoicePeople($actor, $data, 'Atingeți un nume pentru a-l bifa, apoi *✅ Continuă*.');
            }
            if ($this->selectedRecipients($data) === []) {
                return $this->askVoicePeople($actor, $data, 'Bifați cel puțin o persoană.');
            }
            unset($data['page']);
            return $this->askVoiceDay($actor, $data, self::chosenLabel($this->selectedRecipients($data)));
        }

        if ($session['step'] === 'number') {
            $back = $data['category'] === 'other' ? null : 'people';
            if ($choice === 'back') {
                return $back === 'people' ? $this->askVoicePeople($actor, $data, '') : $this->askVoiceCategory($actor, array_diff_key($data, ['category' => 1, 'extra' => 1]), 'Alegeți categoria.');
            }
            if ($choice === 'done' && ($data['extra'] ?? []) !== []) {
                return $back === 'people' ? $this->askVoicePeople($actor, $data, '') : $this->askVoiceDay($actor, $data, self::chosenLabel($this->selectedRecipients($data)));
            }
            $parsed = self::parseExtraNumbers($text);
            if ($parsed['people'] === []) {
                return $this->askVoiceNumber($actor, $data, $parsed['invalid'] !== [] ? 'Numărul *' . $parsed['invalid'][0] . '* nu este valid.' : '');
            }
            $byPhone = [];
            foreach ([...($data['extra'] ?? []), ...$parsed['people']] as $p) {
                $byPhone[$p['phone']] = $p;
            }
            $data['extra'] = array_values($byPhone);
            $note = '✅ Adăugat: *' . implode(', ', array_column($parsed['people'], 'nume')) . '*'
                . ($parsed['invalid'] !== [] ? "\n⚠️ Nevalid (ignorat): " . implode(', ', $parsed['invalid']) : '');
            return $this->askVoiceNumber($actor, $data, $note);
        }

        if ($session['step'] === 'day') {
            if ($choice === 'now') {
                $data['scheduled_at'] = null;
                return $this->askVoiceConfirm($actor, $data);
            }
            if ($choice !== null && preg_match('/^d(\d{8})$/', $choice, $m) === 1) {
                $data['day'] = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
                return $this->askVoiceHour($actor, $data, '');
            }
            // Data scrisa: "30.10" sau "30.10 14:00" (cu ora, sare peste alegerea orei).
            $parsed = self::parseDate($text);
            if (isset($parsed['error'])) {
                return $this->askVoiceDay($actor, $data, $parsed['error']);
            }
            $data['day'] = $parsed['date'];
            if ($parsed['time'] !== null) {
                return $this->scheduleAt($actor, $data, $parsed['time']);
            }
            return $this->askVoiceHour($actor, $data, '');
        }

        if ($session['step'] === 'hour') {
            if ($choice === 'back') {
                unset($data['day']);
                return $this->askVoiceDay($actor, $data, 'Alegeți altă zi.');
            }
            if ($choice !== null && preg_match('/^h(\d{2})(\d{2})$/', $choice, $m) === 1) {
                return $this->scheduleAt($actor, $data, $m[1] . ':' . $m[2]);
            }
            if (preg_match('/^(\d{1,2})[:.](\d{2})$/', trim((string) $text), $m) === 1 && (int) $m[1] < 24 && (int) $m[2] < 60) {
                return $this->scheduleAt($actor, $data, sprintf('%02d:%02d', $m[1], $m[2]));
            }
            return $this->askVoiceHour($actor, $data, 'Alegeți o oră din listă sau scrieți-o, de exemplu *14:30*.');
        }

        // confirm
        if ($choice === 'back') {
            unset($data['category'], $data['picked'], $data['extra'], $data['day'], $data['scheduled_at']);
            return $this->askVoiceCategory($actor, $data, 'Alegeți altă categorie.');
        }
        if ($choice === 'when') {
            unset($data['day'], $data['scheduled_at']);
            return $this->askVoiceDay($actor, $data, 'Alegeți altă zi.');
        }
        if ($choice !== 'send') {
            $this->restoreSession($session);
            return $this->say($actor, 'Alegeți una dintre opțiuni: ✅ Confirmă, 🗓️ Altă dată, ↩️ Altă categorie sau ❌ Anulează.', 'voice_waiting');
        }
        if (($data['scheduled_at'] ?? null) !== null && strtotime((string) $data['scheduled_at']) <= time()) {
            unset($data['scheduled_at']);
            return $this->askVoiceHour($actor, $data, 'Ora aleasă a trecut între timp. Alegeți altă oră.');
        }
        // Lista se recalculeaza la confirmare: cineva dezactivat intre timp nu mai e sunat.
        $recipients = $this->selectedRecipients($data);
        if ($recipients === []) {
            return $this->askVoiceCategory($actor, $data, 'Între timp nu a mai rămas nimeni în această categorie. Alegeți alta.');
        }
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO whatsapp_voice_broadcasts (created_by_user_id, category, audio_wa_message_id, audio_seconds, scheduled_at, status, created_at)
                VALUES (:u, :c, :a, :s, :at, \'pending\', :now)')
                ->execute([':u' => $actor['id'], ':c' => $data['category'], ':a' => $data['audio_wa_message_id'], ':s' => $data['audio_seconds'],
                    ':at' => $data['scheduled_at'] ?? $now, ':now' => $now]);
            $broadcastId = (int) $this->db->lastInsertId();
            $insert = $this->db->prepare('INSERT INTO whatsapp_voice_broadcast_recipients (broadcast_id, recipient_type, recipient_id, nume, phone, status, updated_at)
                VALUES (:b, :t, :i, :n, :p, \'pending\', :now)');
            foreach ($recipients as $r) {
                $insert->execute([':b' => $broadcastId, ':t' => $r['type'], ':i' => $r['id'], ':n' => $r['nume'], ':p' => $r['phone'], ':now' => $now]);
            }
            audit_log_write($this->db, 'whatsapp_voice_broadcasts', $broadcastId, 'create', 'Anunț vocal #' . $broadcastId . ' (WhatsApp, ' . $actor['nume'] . ')', null,
                ['category' => $data['category'], 'recipients' => count($recipients)], $actor['id']);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        $this->clearSession($actor['phone']);
        $count = count($recipients);

        $when = ($data['scheduled_at'] ?? null) !== null ? 'programat ' . self::whenLabel((string) $data['scheduled_at']) : 'în curs de trimitere (apelăm acum)';

        // Butoanele raman valabile si dupa inchiderea conversatiei (fara sesiune): mnt:v:<id>:edit|cancel.
        $this->send($actor, 'maintenance_prompt', '✅ Anunțul #' . $broadcastId . ' este ' . $when . ', către ' . self::people($count)
            . ".\nVeți primi aici rezultatul după ultimul apel.\n\nV-ați răzgândit? Îl puteți modifica sau anula cât timp apelurile nu au pornit.",
            [['id' => self::ACTION_PREFIX . 'v:' . $broadcastId . ':edit', 'title' => '✏️ Modifică'], ['id' => self::ACTION_PREFIX . 'v:' . $broadcastId . ':cancel', 'title' => '🗑️ Anulează anunțul']]);

        return ['broadcast_id' => $broadcastId, 'outcome' => 'voice_saved'];
    }

    /**
     * ✏️ Modifica / 🗑️ Anuleaza pe un anunt salvat. Doar cine l-a creat; doar apelurile care n-au pornit.
     * Modifica = opreste anuntul si reia alegerea (cui, cand) cu aceeasi inregistrare.
     */
    private function voiceBroadcastAction(?array $boss, int $broadcastId, string $action): array
    {
        $stmt = $this->db->prepare('SELECT * FROM whatsapp_voice_broadcasts WHERE id = :id');
        $stmt->execute([':id' => $broadcastId]);
        $broadcast = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($boss === null || $broadcast === false || (int) $broadcast['created_by_user_id'] !== $boss['id']) {
            return $this->say($boss, 'Nu puteți modifica acest anunț.', 'voice_not_owner');
        }
        if (!in_array($broadcast['status'], ['pending', 'calling'], true)) {
            $state = $broadcast['status'] === 'cancelled' ? 'a fost deja anulat' : 's-a încheiat deja';
            return $this->say($boss, 'Anunțul #' . $broadcastId . ' ' . $state . '.', 'voice_not_editable');
        }
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $stop = $this->db->prepare("UPDATE whatsapp_voice_broadcast_recipients SET status = 'cancelled', updated_at = :now WHERE broadcast_id = :b AND status = 'pending'");
            $stop->execute([':now' => $now, ':b' => $broadcastId]);
            $stopped = $stop->rowCount();
            $left = $this->db->prepare("SELECT COUNT(*) FROM whatsapp_voice_broadcast_recipients WHERE broadcast_id = :b AND status = 'calling'");
            $left->execute([':b' => $broadcastId]);
            $inCall = (int) $left->fetchColumn();
            $answeredOrRung = $this->db->prepare("SELECT COUNT(*) FROM whatsapp_voice_broadcast_recipients WHERE broadcast_id = :b AND status NOT IN ('pending', 'calling', 'cancelled')");
            $answeredOrRung->execute([':b' => $broadcastId]);
            $alreadyCalled = (int) $answeredOrRung->fetchColumn();
            if ($inCall === 0 && $alreadyCalled === 0) {
                // Nimeni sunat inca: anuntul dispare complet, fara rezumat.
                $this->db->prepare("UPDATE whatsapp_voice_broadcasts SET status = 'cancelled' WHERE id = :id")->execute([':id' => $broadcastId]);
            }
            audit_log_write($this->db, 'whatsapp_voice_broadcasts', $broadcastId, 'update', 'Anunț vocal #' . $broadcastId . ' ' . ($action === 'edit' ? 'oprit pentru modificare' : 'anulat') . ' (WhatsApp)', null,
                ['apeluri_oprite' => $stopped], $boss['id']);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        if ($inCall === 0 && $alreadyCalled > 0) {
            $this->finishVoiceBroadcast($broadcastId); // rezumat pentru cei sunati deja
        }
        if ($stopped === 0) {
            return $this->say($boss, 'Prea târziu: toate apelurile anunțului #' . $broadcastId . ' au pornit deja.', 'voice_too_late');
        }
        $partial = $inCall + $alreadyCalled > 0 ? ' Apelurile deja pornite nu pot fi oprite; ' . self::people($stopped) . ' nu vor mai fi sunate.' : '';
        if ($action === 'cancel') {
            return $this->say($boss, '🗑️ Anunțul #' . $broadcastId . ' a fost anulat.' . $partial, 'voice_cancelled');
        }
        $data = ['audio_wa_message_id' => $broadcast['audio_wa_message_id'], 'audio_seconds' => $broadcast['audio_seconds'] !== null ? (int) $broadcast['audio_seconds'] : null];

        return ['outcome' => 'voice_edit'] + $this->askVoiceCategory($boss, $data, '✏️ Anunțul #' . $broadcastId . ' a fost oprit.' . $partial
            . "\nAlegeți din nou cui și când îl trimitem (același mesaj vocal). Dacă renunțați acum, nu se mai trimite nimic.");
    }

    /** Rezultatele pe care le poate raporta apelantul vocal. */
    public const VOICE_RESULTS = ['answered', 'no_answer', 'busy', 'failed', 'skipped'];
    private const VOICE_CALL_TIMEOUT_MINUTES = 10;

    /**
     * Apelantul vocal ia urmatoarele apeluri ajunse la ora lor (cu lock, ca la outbox).
     *
     * @return array<int, array{id:int, lock_token:string, broadcast_id:int, phone:string, nume:string, audio_wa_message_id:string, audio_seconds:?int}>
     */
    public function claimVoiceCalls(int $limit = 1): array
    {
        $limit = max(1, min(5, $limit));
        $now = date('Y-m-d H:i:s');
        // Un apel ramas "in curs" (apelantul a cazut) se incheie ca eroare, nu se repeta la nesfarsit.
        $this->db->prepare("UPDATE whatsapp_voice_broadcast_recipients SET status = 'failed', result_detail = 'caller_timeout', lock_token = NULL, updated_at = :now
            WHERE status = 'calling' AND updated_at < :stale")
            ->execute([':now' => $now, ':stale' => date('Y-m-d H:i:s', time() - 60 * self::VOICE_CALL_TIMEOUT_MINUTES)]);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT r.id, r.broadcast_id, r.phone, r.nume, b.audio_wa_message_id, b.audio_seconds
                FROM whatsapp_voice_broadcast_recipients r JOIN whatsapp_voice_broadcasts b ON b.id = r.broadcast_id
                WHERE r.status = 'pending' AND b.status IN ('pending', 'calling') AND COALESCE(b.scheduled_at, b.created_at) <= :now
                ORDER BY COALESCE(b.scheduled_at, b.created_at), r.id LIMIT $limit FOR UPDATE");
            $stmt->execute([':now' => $now]);
            $calls = [];
            $lock = $this->db->prepare("UPDATE whatsapp_voice_broadcast_recipients SET status = 'calling', lock_token = :t, attempts = attempts + 1, updated_at = :now WHERE id = :id");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $token = bin2hex(random_bytes(16));
                $lock->execute([':t' => $token, ':now' => $now, ':id' => $row['id']]);
                $this->db->prepare("UPDATE whatsapp_voice_broadcasts SET status = 'calling' WHERE id = :id AND status = 'pending'")->execute([':id' => $row['broadcast_id']]);
                $calls[] = ['id' => (int) $row['id'], 'lock_token' => $token, 'broadcast_id' => (int) $row['broadcast_id'], 'phone' => (string) $row['phone'],
                    'nume' => (string) $row['nume'], 'audio_wa_message_id' => (string) $row['audio_wa_message_id'],
                    'audio_seconds' => $row['audio_seconds'] !== null ? (int) $row['audio_seconds'] : null];
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $calls;
    }

    /** Rezultatul unui apel; cand anuntul nu mai are apeluri ramase, conducerea primeste rezumatul. */
    public function reportVoiceCall(int $id, string $lockToken, string $status, ?string $detail = null): bool
    {
        if (!in_array($status, self::VOICE_RESULTS, true) || $lockToken === '') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("UPDATE whatsapp_voice_broadcast_recipients SET status = :s, result_detail = :d, called_at = :now, lock_token = NULL, updated_at = :now2
            WHERE id = :id AND lock_token = :t AND status = 'calling'");
        $stmt->execute([':s' => $status, ':d' => $detail !== null ? mb_substr($detail, 0, 120) : null, ':now' => $now, ':now2' => $now, ':id' => $id, ':t' => $lockToken]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $broadcastId = (int) $this->db->query('SELECT broadcast_id FROM whatsapp_voice_broadcast_recipients WHERE id = ' . $id)->fetchColumn();
        $this->finishVoiceBroadcast($broadcastId);

        return true;
    }

    private function finishVoiceBroadcast(int $broadcastId): void
    {
        $left = $this->db->prepare("SELECT COUNT(*) FROM whatsapp_voice_broadcast_recipients WHERE broadcast_id = :b AND status IN ('pending', 'calling')");
        $left->execute([':b' => $broadcastId]);
        if ((int) $left->fetchColumn() > 0) {
            return;
        }
        $done = $this->db->prepare("UPDATE whatsapp_voice_broadcasts SET status = 'done' WHERE id = :id AND status <> 'done'");
        $done->execute([':id' => $broadcastId]);
        if ($done->rowCount() !== 1) {
            return; // rezumatul a plecat deja
        }
        $broadcast = $this->db->query('SELECT * FROM whatsapp_voice_broadcasts WHERE id = ' . $broadcastId)->fetch(PDO::FETCH_ASSOC);
        $rows = $this->db->query('SELECT nume, status FROM whatsapp_voice_broadcast_recipients WHERE broadcast_id = ' . $broadcastId . ' ORDER BY nume')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $labels = ['answered' => '✅ a răspuns și a ascultat mesajul', 'no_answer' => '❌ nu a răspuns', 'busy' => '⛔ a respins / ocupat', 'failed' => '⚠️ apelul nu a putut fi făcut',
            'skipped' => '⏸️ nesunat – numărul nu e în lista de test (WA_CALL_ALLOWED_TO)', 'cancelled' => '🚫 nesunat – anunț oprit'];
        $lines = ['📞 *Anunțul #' . $broadcastId . '* s-a încheiat'];
        foreach ($rows as $row) {
            $lines[] = '• ' . $row['nume'] . ' – ' . ($labels[$row['status']] ?? $row['status']);
        }
        $answered = count(array_filter($rows, static fn(array $r): bool => $r['status'] === 'answered'));
        $lines[] = '';
        $lines[] = $answered . ' din ' . count($rows) . ' au ascultat mesajul.';
        $this->outbox->enqueueForUser('voice_broadcast_summary', (int) $broadcast['created_by_user_id'], implode("\n", $lines), [], 'voice:summary:' . $broadcastId);
    }

    private static function people(int $count): string
    {
        return $count . ($count === 1 ? ' persoană' : ' persoane');
    }

    private const RO_DAYS = ['duminică', 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă'];

    /** "azi, ora 14:00" / "mâine, ora 9:30" / "vineri 30.10, ora 14:00" */
    public static function whenLabel(string $dateTime, ?int $now = null): string
    {
        $ts = strtotime($dateTime);
        $now ??= time();
        $days = (int) round((strtotime(date('Y-m-d', $ts)) - strtotime(date('Y-m-d', $now))) / 86400);
        $day = match ($days) {
            0 => 'azi',
            1 => 'mâine',
            default => self::RO_DAYS[(int) date('w', $ts)] . ' ' . date('d.m', $ts),
        };

        return $day . ', ora ' . date('H:i', $ts);
    }

    /** Calendarul: "Acum" si urmatoarele 7 zile; se poate scrie si o data ("30.10" sau "30.10 14:00"). */
    private function askVoiceDay(array $actor, array $data, string $intro): array
    {
        $choices = [['now', '📞 Acum']];
        for ($i = 0; $i < 7; $i++) {
            $ts = strtotime('+' . $i . ' days', strtotime(date('Y-m-d')));
            $name = $i === 0 ? 'Azi' : ($i === 1 ? 'Mâine' : mb_convert_case(self::RO_DAYS[(int) date('w', $ts)], MB_CASE_TITLE));
            $choices[] = ['d' . date('Ymd', $ts), '📅 ' . $name . ', ' . date('d.m', $ts)];
        }
        $choices[] = ['cancel', '❌ Anulează'];
        $this->ask($actor, 'voice', 'day', $data, trim($intro . "\n\n*Când facem apelul?*\nAlegeți ziua sau scrieți data, de exemplu *30.10* sau *30.10 14:00*."), $choices);

        return ['outcome' => 'voice_day'];
    }

    /** Ora pentru ziua aleasa; azi doar orele care n-au trecut. Se poate scrie si o ora ("14:30"). */
    private function askVoiceHour(array $actor, array $data, string $intro): array
    {
        $choices = [];
        foreach (['08:00', '09:00', '10:00', '11:00', '12:00', '14:00', '16:00', '18:00'] as $hour) {
            if (strtotime($data['day'] . ' ' . $hour) > time() + 300) {
                $choices[] = ['h' . str_replace(':', '', $hour), '🕐 ' . $hour];
            }
        }
        $choices[] = ['back', '↩️ Altă zi'];
        $choices[] = ['cancel', '❌ Anulează'];
        $dayLabel = substr(self::whenLabel($data['day'] . ' 00:00'), 0, -strlen(', ora 00:00'));
        $this->ask($actor, 'voice', 'hour', $data, trim($intro . "\n\n*La ce oră, " . $dayLabel . '?*' . "\nAlegeți ora sau scrieți-o, de exemplu *14:30*."), $choices);

        return ['outcome' => 'voice_hour'];
    }

    private function scheduleAt(array $actor, array $data, string $time): array
    {
        $at = strtotime($data['day'] . ' ' . $time);
        if ($at === false || $at <= time() + 60) {
            return $this->askVoiceHour($actor, $data, 'Ora ' . $time . ' a trecut. Alegeți o oră din viitor.');
        }
        $data['scheduled_at'] = date('Y-m-d H:i:00', $at);

        return $this->askVoiceConfirm($actor, $data);
    }

    private function askVoiceConfirm(array $actor, array $data): array
    {
        $recipients = $this->selectedRecipients($data);
        $names = array_map(static fn(array $r): string => '• ' . $r['nume'], array_slice($recipients, 0, 8));
        if (count($recipients) > 8) {
            $names[] = '• … și încă ' . (count($recipients) - 8);
        }
        $when = ($data['scheduled_at'] ?? null) !== null ? self::whenLabel((string) $data['scheduled_at']) : 'acum';
        $body = '*' . self::VOICE_CATEGORIES[$data['category']] . '*: ' . self::people(count($recipients)) . "\n" . implode("\n", $names)
            . "\n🗓️ Când: *" . $when . "*\n\nTrimitem mesajul vocal acestor persoane?";
        $this->ask($actor, 'voice', 'confirm', $data, $body, [['send', '✅ Confirmă'], ['when', '🗓️ Altă dată'], ['back', '↩️ Altă categorie'], ['cancel', '❌ Anulează']]);

        return ['outcome' => 'voice_confirm'];
    }

    private const VOICE_PEOPLE_PAGE = 5;

    /**
     * Persoanele categoriei (doar cele bifate daca s-a ales din lista: "u1", "d48"),
     * plus numerele scrise de mana; fiecare telefon o singura data.
     */
    private function selectedRecipients(array $data): array
    {
        $recipients = $this->voiceRecipients((string) $data['category']);
        if (isset($data['picked'])) {
            $recipients = array_values(array_filter($recipients, static fn(array $r): bool => in_array($r['type'][0] . $r['id'], $data['picked'], true)));
        }
        $byPhone = [];
        foreach ([...$recipients, ...($data['extra'] ?? [])] as $r) {
            $byPhone[$r['phone']] ??= $r;
        }

        return array_values($byPhone);
    }

    private static function chosenLabel(array $recipients): string
    {
        return 'Aleși: *' . self::people(count($recipients)) . '*.';
    }

    /** Numar din afara aplicatiei: il scrie conducerea (unul sau mai multe, optional cu nume). */
    private function askVoiceNumber(array $actor, array $data, string $intro): array
    {
        $extra = $data['extra'] ?? [];
        $body = "📱 *Alt număr*\nScrieți numărul de telefon, opțional urmat de nume, de exemplu:\n*0722 123 456 Ion Popescu*\nPuteți scrie mai multe, separate prin virgulă."
            . ($extra !== [] ? "\n\nNumere adăugate: *" . implode(', ', array_column($extra, 'nume')) . '*' : '');
        $choices = [];
        if ($extra !== []) {
            $choices[] = ['done', $data['category'] === 'other' ? '✅ Continuă (' . count($extra) . ')' : '✅ Gata, înapoi la listă'];
        }
        $choices[] = ['back', '↩️ Înapoi'];
        $choices[] = ['cancel', '❌ Anulează'];
        $this->ask($actor, 'voice', 'number', $data, trim($intro . "\n\n" . $body), $choices);

        return ['outcome' => 'voice_number'];
    }

    /** Lista din aplicatie: fiecare atingere bifeaza / debifeaza o persoana; cate 6 pe pagina. */
    private function askVoicePeople(array $actor, array $data, string $intro): array
    {
        $everyone = $this->voiceRecipients((string) $data['category']);
        $picked = $data['picked'] ?? [];
        $pages = max(1, (int) ceil(count($everyone) / self::VOICE_PEOPLE_PAGE));
        $data['page'] = ((int) ($data['page'] ?? 0)) % $pages;
        $choices = [['everyone', '👥 Toți (' . count($everyone) . ')']];
        foreach (array_slice($everyone, $data['page'] * self::VOICE_PEOPLE_PAGE, self::VOICE_PEOPLE_PAGE) as $r) {
            $key = $r['type'][0] . $r['id'];
            $choices[] = ['p' . $key, (in_array($key, $picked, true) ? '☑️ ' : '⬜ ') . $r['nume']];
        }
        if ($pages > 1) {
            $choices[] = ['next', '➡️ Următorii (pagina ' . ($data['page'] + 1) . '/' . $pages . ')'];
        }
        $choices[] = ['extra', '➕ Alt număr (din afara listei)'];
        $selected = count($this->selectedRecipients($data));
        if ($selected > 0) {
            $choices[] = ['done', '✅ Continuă (' . $selected . ' aleși)'];
        }
        $choices[] = ['cancel', '❌ Anulează'];
        $names = array_map(static fn(array $r): string => $r['nume'], $this->selectedRecipients($data));
        $body = '*' . self::VOICE_CATEGORIES[$data['category']] . "* – pe cine sunăm?\nAtingeți un nume pentru a-l bifa; atingeți din nou pentru a-l scoate."
            . "\n\nAleși: " . ($names === [] ? 'nimeni încă' : '*' . implode(', ', $names) . '*');
        $this->ask($actor, 'voice', 'people', $data, trim($intro . "\n\n" . $body), $choices);

        return ['outcome' => 'voice_people'];
    }

    private function askVoiceCategory(array $actor, array $data, string $intro): array
    {
        $choices = [];
        foreach (self::VOICE_CATEGORIES as $key => $label) {
            $choices[] = [$key, $label];
        }
        $choices[] = ['cancel', '❌ Anulează'];
        $this->ask($actor, 'voice', 'category', $data, $intro . "\n\n*Cui trimitem mesajul?*", $choices);

        return ['outcome' => 'voice_category'];
    }

    private function say(?array $to, string $body, string $outcome): array
    {
        if ($to !== null) {
            $this->send($to, 'maintenance_reply', $body);
        }

        return ['outcome' => $outcome];
    }

    private function command(array $command, ?array $driverActor, ?array $userActor, string $phone): array
    {
        $bossActor = $this->bossActor;
        $fallback = $driverActor ?? $userActor ?? $bossActor;
        switch ($command['command']) {
            case 'menu':
                // In sesiune: peste 3 optiuni meniul devine lista numerotata, iar "1", "2"... trebuie citite.
                $choices = [];
                if ($driverActor !== null) {
                    $choices[] = ['request', '📅 Programare service'];
                    $choices[] = ['status', '📋 Status service'];
                }
                if ($userActor !== null) {
                    $choices[] = ['requests', '📥 Cereri service'];
                    $choices[] = ['mine', '🛠️ Intervențiile mele'];
                }
                if ($bossActor !== null) {
                    $choices[] = ['voice', '🎙️ Înregistrează mesaj'];
                }
                $this->ask($fallback, 'menu', 'menu', [], "*Meniu service*\nAlegeți o opțiune sau scrieți comanda (de ex. *PROGRAMARE SERVICE*).", $choices);
                return ['outcome' => 'menu'];
            case 'voice':
                if ($bossActor === null) {
                    return $this->say($fallback, 'Mesajele vocale către șoferi pot fi trimise doar de conducere.', 'not_a_boss');
                }
                $this->ask($bossActor, 'voice', 'record', [], "🎙️ *Înregistrează mesaj*\nȚineți apăsat butonul 🎤 din WhatsApp și înregistrați mesajul pentru șoferi, apoi trimiteți-l aici.\n\nPentru a renunța, scrieți *ANULEAZĂ*.", [['cancel', '❌ Anulează']]);
                return ['outcome' => 'voice_prompt'];
            case 'mine':
                if ($userActor === null) {
                    return $this->say($fallback, 'Nu aveți dreptul de coordonare a intervențiilor.', 'not_a_reviewer');
                }
                return $this->listMine($userActor);
            case 'request':
                if ($driverActor === null) {
                    return $this->say($fallback, 'Programarea service se face de către șoferul vehiculului.', 'not_a_driver');
                }
                return $this->startRequest($driverActor, (string) ($command['arg'] ?? ''));
            case 'status':
                if ($driverActor === null) {
                    return $this->say($fallback, 'Statusul programărilor este disponibil pentru șoferi. Pentru cereri, scrieți *CERERI SERVICE*.', 'not_a_driver');
                }
                return $this->driverStatus($driverActor);
            case 'requests':
                if ($userActor === null) {
                    return $this->say($fallback, 'Nu aveți dreptul de a vedea cererile de service.', 'not_a_reviewer');
                }
                return $this->listRequests($userActor);
            case 'request_card':
                if ($userActor === null) {
                    return $this->say($fallback, 'Nu aveți dreptul de a vedea cererile de service.', 'not_a_reviewer');
                }
                $this->clearSession($phone);
                $row = $this->interventionRow((int) $command['arg']);
                if ($row === null) {
                    return $this->say($userActor, 'Intervenția #' . (int) $command['arg'] . ' nu există.', 'not_found');
                }
                // De verificat = cardul de decizie; dupa aprobare = cardul coordonatorului.
                InterventionWorkflowModel::statusKey((string) $row['status_interventie']) === 'de_verificat'
                    ? $this->sendCard($userActor, (int) $row['id'], null)
                    : $this->sendCoordCard($userActor, (int) $row['id'], null);
                return ['outcome' => 'card_sent'];
        }

        return ['outcome' => 'ignored'];
    }

    // -------------------------------------------------------------------------
    // Sofer: cererea de programare
    // -------------------------------------------------------------------------

    /** @return array<int, array{id:int, label:string}> vehiculele soferului (alocarea implicita + semiremorca cuplata) */
    public function driverVehicles(int $driverId): array
    {
        $context = $this->workflow->vehicleContext();
        $result = [];
        foreach ($this->outbox->getDefaultVehicleIdsForDriver($driverId) as $vehicleId) {
            if (!isset($context[$vehicleId])) {
                continue; // vehicul inactiv
            }
            $vehicle = $context[$vehicleId];
            $result[$vehicleId] = ['id' => $vehicleId, 'label' => trim($vehicle['plate'] . ' ' . ($vehicle['model'] !== '' ? '(' . $vehicle['model'] . ')' : ''))];
            if ($vehicle['trailer'] !== null && !isset($result[$vehicle['trailer']['id']])) {
                $result[$vehicle['trailer']['id']] = ['id' => (int) $vehicle['trailer']['id'], 'label' => $vehicle['trailer']['plate'] . ' (semiremorcă)'];
            }
        }

        return array_values($result);
    }

    private function vehicleLabel(int $vehicleId): string
    {
        $stmt = $this->db->prepare('SELECT nr_inmatriculare, marca, model FROM vehicule WHERE id = :id');
        $stmt->execute([':id' => $vehicleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return '#' . $vehicleId;
        }
        $model = trim((string) $row['marca'] . ' ' . (string) $row['model']);

        return (string) $row['nr_inmatriculare'] . ($model !== '' ? ' (' . $model . ')' : '');
    }

    /** @return array<int, array<string, mixed>> interventiile deschise ale vehiculului (orice sursa, inclusiv Km service) */
    private function openInterventions(int $vehicleId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, tip_interventie, status_interventie, data_programata, ora_programata, descriere
            FROM mentenanta_interventii_programate
            WHERE vehicle_id = :v AND status_interventie NOT IN ('finalizata', 'anulata')
            ORDER BY data_programata ASC, id ASC LIMIT 5
        ");
        $stmt->execute([':v' => $vehicleId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function interventionLine(array $row): string
    {
        $status = InterventionWorkflowModel::STATUS_LABELS[InterventionWorkflowModel::statusKey((string) $row['status_interventie'])];
        $line = '#' . (int) $row['id'] . ' · ' . self::typeLabel((string) $row['tip_interventie']) . ' · ' . $status
            . ' · ' . self::dateLabel((string) $row['data_programata'], $row['ora_programata'] ?? null);
        $description = trim((string) ($row['descriere'] ?? ''));

        return $description !== '' ? $line . "\n   " . mb_strimwidth($description, 0, 90, '…', 'UTF-8') : $line;
    }

    private function startRequest(array $actor, string $presetType): array
    {
        $vehicles = $this->driverVehicles($actor['id']);
        if ($vehicles === []) {
            $this->clearSession($actor['phone']);
            return $this->say($actor, 'Nu aveți niciun vehicul alocat în Fleet, așa că nu putem crea programarea. Vă rugăm să contactați dispeceratul.', 'no_vehicle');
        }
        $data = ['type' => $presetType];
        if (count($vehicles) === 1) {
            $data['vehicle_id'] = $vehicles[0]['id'];
            return $this->afterVehicle($actor, $data, 'Vehicul: *' . $vehicles[0]['label'] . '*');
        }
        $choices = array_map(static fn(array $v): array => ['v' . $v['id'], $v['label']], $vehicles);
        $choices[] = ['cancel', '❌ Anulează'];
        $this->ask($actor, 'request', 'vehicle', $data, "*Programare service*\nPentru ce vehicul este programarea?", $choices);

        return ['outcome' => 'asked_vehicle'];
    }

    private function afterVehicle(array $actor, array $data, string $intro = ''): array
    {
        $open = $this->openInterventions((int) $data['vehicle_id']);
        if ($open !== [] && empty($data['open_seen'])) {
            $data['open_seen'] = true;
            $body = ($intro !== '' ? $intro . "\n\n" : '') . "ℹ️ Vehiculul are deja intervenții deschise:\n"
                . implode("\n", array_map(static fn(array $row): string => self::interventionLine($row), $open))
                . "\n\nDoriți totuși o cerere nouă?";
            $this->ask($actor, 'request', 'existing', $data, $body, [['new', '➕ Cerere nouă'], ['cancel', '❌ Anulează']]);
            return ['outcome' => 'asked_existing'];
        }

        return $this->askType($actor, $data, $intro);
    }

    private function askType(array $actor, array $data, string $intro = ''): array
    {
        if (($data['type'] ?? '') !== '') {
            return $this->askDate($actor, $data, $intro);
        }
        $body = ($intro !== '' ? $intro . "\n\n" : '') . 'Ce tip de intervenție doriți?';
        $this->ask($actor, 'request', 'type', $data, $body, [['rev', '🛠️ Revizie'], ['rep', '🔧 Reparație'], ['cancel', '❌ Anulează']]);

        return ['outcome' => 'asked_type'];
    }

    private function askDate(array $actor, array $data, string $intro = ''): array
    {
        $body = ($intro !== '' ? $intro . "\n\n" : '') . "Ce dată preferați?\nScrieți data (de ex. *30.10* sau *30.10 14:00*) sau alegeți:";
        $this->ask($actor, 'request', 'date', $data, $body, [['d1', '📅 Mâine'], ['d2', '📅 Poimâine'], ['cancel', '❌ Anulează']]);

        return ['outcome' => 'asked_date'];
    }

    private function askDescription(array $actor, array $data): array
    {
        $this->ask($actor, 'request', 'description', $data, "Descrieți pe scurt ce trebuie verificat sau reparat.\n(Pentru a renunța, scrieți „anulează”.)");

        return ['outcome' => 'asked_description'];
    }

    private function askConfirm(array $actor, array $data): array
    {
        $body = "*Verificați cererea:*\n"
            . 'Vehicul: ' . $this->vehicleLabel((int) $data['vehicle_id']) . "\n"
            . 'Tip: ' . self::typeLabel((string) $data['type']) . "\n"
            . 'Data preferată: ' . self::dateLabel((string) $data['date'], $data['time'] ?? null) . "\n"
            . 'Descriere: ' . $data['description'];
        $this->ask($actor, 'request', 'confirm', $data, $body, [['send', '✅ Trimite'], ['edit', '✏️ Modifică'], ['cancel', '❌ Anulează']]);

        return ['outcome' => 'asked_confirm'];
    }

    /** Dupa un camp modificat din rezumat ne intoarcem la rezumat. */
    private function next(array $actor, array $data, callable $default): array
    {
        if (!empty($data['editing'])) {
            unset($data['editing']);
            return $this->askConfirm($actor, $data);
        }

        return $default($actor, $data);
    }

    private function driverStep(array $actor, array $session, string $kind, ?string $actionId, ?string $text, string $waMessageId): array
    {
        $data = $session['data'];
        $step = (string) $session['step'];
        $choice = self::choice($session, $actionId, $text);
        if ($choice === 'cancel') {
            $this->clearSession($actor['phone']);
            return $this->say($actor, 'Cererea a fost anulată. Scrieți *PROGRAMARE SERVICE* oricând pentru una nouă.', 'cancelled');
        }

        if ($session['flow'] === 'details') {
            return $this->driverDetailsAnswer($actor, $session, $kind, $text);
        }

        switch ($step) {
            case 'vehicle':
                if ($choice === null || !str_starts_with($choice, 'v')) {
                    break;
                }
                $data['vehicle_id'] = (int) substr($choice, 1);
                if (!in_array($data['vehicle_id'], array_column($this->driverVehicles($actor['id']), 'id'), true)) {
                    return $this->say($actor, 'Vehiculul nu vă mai este alocat. Scrieți *PROGRAMARE SERVICE* pentru a reîncepe.', 'vehicle_not_allowed');
                }
                return $this->afterVehicle($actor, $data);
            case 'existing':
                if ($choice !== 'new') {
                    break;
                }
                return $this->askType($actor, $data);
            case 'type':
                if ($choice !== 'rev' && $choice !== 'rep') {
                    break;
                }
                $data['type'] = $choice === 'rep' ? 'reparatie' : 'intretinere';
                return $this->next($actor, $data, fn(array $a, array $d): array => $this->askDate($a, $d));
            case 'date':
                if ($choice === 'd1' || $choice === 'd2') {
                    $data['date'] = date('Y-m-d', strtotime('+' . ($choice === 'd1' ? 1 : 2) . ' day', strtotime(date('Y-m-d'))));
                    $data['time'] = null;
                } else {
                    if ($kind !== 'text' || $text === null || $text === '') {
                        break;
                    }
                    $parsed = self::parseDate($text);
                    if (isset($parsed['error'])) {
                        return $this->say($actor, $parsed['error'], 'invalid_date');
                    }
                    $data['date'] = $parsed['date'];
                    $data['time'] = $parsed['time'];
                }
                return $this->next($actor, $data, fn(array $a, array $d): array => $this->askDescription($a, $d));
            case 'description':
                if ($kind !== 'text' || $text === null || mb_strlen($text) < self::MIN_DESCRIPTION_CHARS) {
                    return $this->say($actor, 'Vă rugăm să descrieți problema în câteva cuvinte, într-un mesaj text.', 'description_too_short');
                }
                $data['description'] = mb_substr($text, 0, self::MAX_DESCRIPTION_CHARS);
                return $this->askConfirm($actor, $data);
            case 'confirm':
                if ($choice === 'send') {
                    return $this->submitRequest($actor, $data, $waMessageId);
                }
                if ($choice === 'edit') {
                    $this->ask($actor, 'request', 'edit', $data, 'Ce doriți să modificați?', [['e_date', '📅 Data'], ['e_desc', '📝 Descrierea'], ['e_type', '🔧 Tipul']]);
                    return ['outcome' => 'asked_edit'];
                }
                break;
            case 'edit':
                $data['editing'] = true;
                if ($choice === 'e_date') {
                    return $this->askDate($actor, $data);
                }
                if ($choice === 'e_desc') {
                    return $this->askDescription($actor, $data);
                }
                if ($choice === 'e_type') {
                    $data['type'] = '';
                    return $this->askType($actor, $data);
                }
                break;
        }

        $count = count($session['data']['choices'] ?? []);
        return $this->say($actor, $count > 0
            ? 'Vă rugăm să alegeți una dintre opțiuni (1–' . $count . ') sau scrieți „anulează”.'
            : 'Vă rugăm să răspundeți la întrebarea de mai sus sau scrieți „anulează”.', 'unexpected_reply');
    }

    private function submitRequest(array $actor, array $data, string $waMessageId): array
    {
        $vehicleId = (int) $data['vehicle_id'];
        $description = trim((string) $data['description']);
        if (!in_array($vehicleId, array_column($this->driverVehicles($actor['id']), 'id'), true)) {
            $this->clearSession($actor['phone']);
            return $this->say($actor, 'Vehiculul nu vă mai este alocat; cererea nu a fost trimisă.', 'vehicle_not_allowed');
        }
        // Aceeasi cerere deja deschisa (ex. trimisa de doua ori): nu dublam.
        $duplicate = $this->db->prepare("
            SELECT id FROM mentenanta_interventii_programate
            WHERE vehicle_id = :v AND tip_interventie = :t AND data_programata = :d AND LOWER(TRIM(descriere)) = :desc
              AND status_interventie NOT IN ('finalizata', 'anulata')
            LIMIT 1
        ");
        $duplicate->execute([':v' => $vehicleId, ':t' => $data['type'], ':d' => $data['date'], ':desc' => mb_strtolower($description)]);
        $existingId = $duplicate->fetchColumn();
        if ($existingId !== false) {
            $this->clearSession($actor['phone']);
            return $this->say($actor, 'Există deja cererea #' . (int) $existingId . ' cu aceleași detalii. Scrieți *STATUS SERVICE* pentru a o urmări.', 'duplicate_request');
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $id = $this->workflow->saveFromForm([
                'vehicle_id' => $vehicleId,
                'tip_interventie' => $data['type'],
                'prioritate' => 'normala',
                'data_programata' => $data['date'],
                'ora_programata' => (string) ($data['time'] ?? ''),
                'driver_id' => $actor['id'],
                'descriere' => $description,
                'status' => 'de_verificat',
            ], 0, [], null);
            $now = date('Y-m-d H:i:s');
            $this->db->prepare('
                INSERT INTO whatsapp_maintenance_requests (intervention_id, driver_id, source_wa_message_id, notified_state, created_at, updated_at)
                VALUES (:i, :d, :wa, :state, :now, :now2)
            ')->execute([':i' => $id, ':d' => $actor['id'], ':wa' => mb_substr($waMessageId, 0, 64),
                ':state' => self::notifyState(['status_interventie' => 'de_verificat', 'data_programata' => $data['date'], 'ora_programata' => $data['time'] ?? null]),
                ':now' => $now, ':now2' => $now]);
            $this->workflow->logEvent($id, 'whatsapp', 'Cerere de programare trimisă prin WhatsApp de șoferul ' . $actor['nume'], [
                'sursa' => 'whatsapp', 'driver_id' => $actor['id'], 'wa_message_id' => $waMessageId,
            ], null);
            audit_log_write($this->db, 'mentenanta_interventii', $id, 'create', 'Cerere programare #' . $id . ' (WhatsApp, șofer ' . $actor['nume'] . ')', null, [
                'vehicle_id' => $vehicleId, 'tip_interventie' => $data['type'], 'data_programata' => $data['date'], 'ora_programata' => $data['time'] ?? null,
                'driver_id' => $actor['id'], 'sursa' => 'whatsapp',
            ], null);
            $this->clearSession($actor['phone']);
            $this->send($actor, 'maintenance_request_ack', '✅ Cererea de service *#' . $id . '* a fost trimisă spre aprobare.'
                . "\nVeți primi un mesaj aici când este analizată. Pentru status, scrieți *STATUS SERVICE*.", [], null, $id);
            $this->notifyReviewers($id);
            if ($own) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return ['outcome' => 'request_created', 'intervention_id' => $id];
    }

    private function driverStatus(array $actor): array
    {
        $this->clearSession($actor['phone']);
        $vehicleIds = array_column($this->driverVehicles($actor['id']), 'id');
        $params = [':d' => $actor['id'], ':since' => date('Y-m-d', strtotime('-' . self::NOTIFY_WINDOW_DAYS . ' days'))];
        $vehicleSql = '';
        if ($vehicleIds !== []) {
            $vehicleSql = ' OR (i.vehicle_id IN (' . implode(',', array_map('intval', $vehicleIds)) . ") AND i.status_interventie NOT IN ('finalizata', 'anulata'))";
        }
        $stmt = $this->db->prepare("
            SELECT i.id, i.tip_interventie, i.status_interventie, i.data_programata, i.ora_programata, i.descriere, v.nr_inmatriculare
            FROM mentenanta_interventii_programate i
            JOIN vehicule v ON v.id = i.vehicle_id
            LEFT JOIN whatsapp_maintenance_requests r ON r.intervention_id = i.id
            WHERE (r.driver_id = :d AND r.created_at >= :since)" . $vehicleSql . '
            ORDER BY i.data_programata DESC, i.id DESC LIMIT 8
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return $this->say($actor, "Nu aveți cereri sau intervenții deschise.\nPentru o programare nouă, scrieți *PROGRAMARE SERVICE*.", 'status_empty');
        }
        $lines = ['*Status service*'];
        foreach ($rows as $row) {
            $lines[] = '• ' . $row['nr_inmatriculare'] . ' ' . self::interventionLine($row);
        }

        return $this->say($actor, implode("\n", $lines), 'status');
    }

    private function driverDetailsAnswer(array $actor, array $session, string $kind, ?string $text): array
    {
        if ($kind !== 'text' || $text === null || mb_strlen($text) < 2) {
            return $this->say($actor, 'Vă rugăm să răspundeți printr-un mesaj text (sau scrieți „anulează”).', 'description_too_short');
        }
        $id = (int) ($session['data']['intervention_id'] ?? 0);
        $reviewerId = (int) ($session['data']['reviewer_id'] ?? 0);
        $answer = mb_substr($text, 0, self::MAX_DESCRIPTION_CHARS);
        $this->workflow->logEvent($id, 'whatsapp', 'Detalii de la șofer (WhatsApp): ' . mb_strimwidth($answer, 0, 400, '…', 'UTF-8'), [
            'sursa' => 'whatsapp', 'driver_id' => $actor['id'], 'text' => $answer,
        ], null);
        $this->clearSession($actor['phone']);
        $this->say($actor, 'Mulțumim! Răspunsul a fost transmis operatorului (cererea #' . $id . ').', 'details_answered');
        $reviewer = $this->reviewerById($reviewerId);
        if ($reviewer !== null) {
            $to = ['role' => 'user', 'id' => $reviewer['id'], 'nume' => $reviewer['nume'], 'phone' => $reviewer['phone']];
            $this->send($to, 'maintenance_details', '💬 Răspunsul șoferului ' . $actor['nume'] . ' la cererea #' . $id . ":\n" . $answer, [], null, $id);
            $this->sendCard($to, $id, null);
        }

        return ['outcome' => 'details_answered', 'intervention_id' => $id];
    }

    // -------------------------------------------------------------------------
    // Operator: cardul cererii si decizia
    // -------------------------------------------------------------------------

    private function interventionRow(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT i.*, v.nr_inmatriculare, v.marca, v.model, s.nume AS sofer_nume, u.nume AS responsabil_nume
            FROM mentenanta_interventii_programate i
            JOIN vehicule v ON v.id = i.vehicle_id
            LEFT JOIN soferi s ON s.id = i.driver_id
            LEFT JOIN utilizatori u ON u.id = i.responsabil_user_id
            WHERE i.id = :id
        ');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Versiunea interventiei pentru butoane: se schimba la orice salvare (updated_at) si la orice
     * schimbare a campurilor decise aici, chiar in aceeasi secunda.
     */
    public static function version(array $row): int
    {
        $fields = [];
        foreach (['updated_at', 'status_interventie', 'data_programata', 'ora_programata', 'responsabil_user_id', 'tip_interventie', 'descriere'] as $key) {
            $fields[] = (string) ($row[$key] ?? '');
        }

        return (int) sprintf('%u', crc32(implode('|', $fields)));
    }

    public static function cardText(array $row): string
    {
        $model = trim((string) ($row['marca'] ?? '') . ' ' . (string) ($row['model'] ?? ''));
        $statusKey = InterventionWorkflowModel::statusKey((string) $row['status_interventie']);
        $lines = [
            '*Cerere programare service #' . (int) $row['id'] . '*',
            'Vehicul: *' . $row['nr_inmatriculare'] . '*' . ($model !== '' ? ' (' . $model . ')' : ''),
        ];
        if (!empty($row['sofer_nume'])) {
            $lines[] = 'Șofer: ' . $row['sofer_nume'];
        }
        $lines[] = 'Tip: ' . self::typeLabel((string) $row['tip_interventie']);
        $lines[] = 'Data: ' . self::dateLabel((string) $row['data_programata'], $row['ora_programata'] ?? null);
        $lines[] = 'Problema: ' . mb_strimwidth(trim((string) ($row['sesizare_initiala'] ?? '') ?: (string) $row['descriere']), 0, 600, '…', 'UTF-8');
        $lines[] = 'Status: ' . InterventionWorkflowModel::STATUS_LABELS[$statusKey]
            . (!empty($row['responsabil_nume']) ? ' · Responsabil: ' . $row['responsabil_nume'] : '');

        return implode("\n", $lines);
    }

    /** Cardul cu butoanele de decizie (doar cat timp cererea e "De verificat"). */
    private function sendCard(array $to, int $id, ?string $dedupeKey): bool
    {
        $row = $this->interventionRow($id);
        if ($row === null) {
            return false;
        }
        $options = [];
        if (InterventionWorkflowModel::statusKey((string) $row['status_interventie']) === 'de_verificat') {
            $version = self::version($row);
            $options = [
                ['id' => self::ACTION_PREFIX . 'r:' . $id . ':ok:' . $version, 'title' => '✅ Aprobă'],
                ['id' => self::ACTION_PREFIX . 'r:' . $id . ':no:' . $version, 'title' => '❌ Respinge'],
                ['id' => self::ACTION_PREFIX . 'r:' . $id . ':more:' . $version, 'title' => '⚙️ Alte acțiuni'],
            ];
        }
        $this->send($to, 'maintenance_request', self::cardText($row), $options, $dedupeKey, $id);

        return true;
    }

    /** Cererea noua pleaca o data la fiecare operator. */
    public function notifyReviewers(int $id): int
    {
        $sent = 0;
        foreach ($this->reviewers() as $reviewer) {
            $to = ['role' => 'user', 'id' => $reviewer['id'], 'nume' => $reviewer['nume'], 'phone' => $reviewer['phone']];
            if ($this->sendCard($to, $id, 'mnt:card:' . $id . ':user:' . $reviewer['id'])) {
                $sent++;
            }
        }

        return $sent;
    }

    private function listRequests(array $actor): array
    {
        $this->clearSession($actor['phone']);
        $rows = $this->db->query("
            SELECT i.id, i.tip_interventie, i.status_interventie, i.data_programata, i.ora_programata, i.descriere, v.nr_inmatriculare
            FROM mentenanta_interventii_programate i
            JOIN whatsapp_maintenance_requests r ON r.intervention_id = i.id
            JOIN vehicule v ON v.id = i.vehicle_id
            WHERE i.status_interventie = 'de_verificat'
            ORDER BY i.data_programata ASC, i.id ASC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return $this->say($actor, 'Nu există cereri de service în așteptare.', 'requests_empty');
        }
        $lines = ['*Cereri service de verificat*'];
        foreach ($rows as $row) {
            $lines[] = '• ' . $row['nr_inmatriculare'] . ' ' . self::interventionLine($row);
        }
        $lines[] = '';
        $lines[] = 'Pentru a decide, scrieți *CERERE* și numărul (de ex. *CERERE ' . (int) $rows[0]['id'] . '*).';

        return $this->say($actor, implode("\n", $lines), 'requests');
    }

    /**
     * Cererea se poate decide doar daca e inca "De verificat" si neschimbata de la mesajul folosit.
     *
     * @return array|string randul, sau mesajul de refuz
     */
    private function decidable(int $id, ?int $version)
    {
        $row = $this->interventionRow($id);
        if ($row === null) {
            return 'Intervenția #' . $id . ' nu mai există.';
        }
        $statusKey = InterventionWorkflowModel::statusKey((string) $row['status_interventie']);
        if ($statusKey !== 'de_verificat') {
            $by = '';
            $stmt = $this->db->prepare('SELECT u.nume FROM utilizatori u WHERE u.id = :id');
            $stmt->execute([':id' => (int) ($row['updated_by'] ?? 0)]);
            $name = $stmt->fetchColumn();
            if ($name !== false) {
                $by = ' de ' . $name;
            }
            return 'ℹ️ Cererea #' . $id . ' nu mai este de verificat: este *' . InterventionWorkflowModel::STATUS_LABELS[$statusKey] . '*'
                . ' (modificată' . $by . ' la ' . date('d.m.Y H:i', (int) strtotime((string) $row['updated_at'])) . ').';
        }
        if ($version !== null && self::version($row) !== $version) {
            return 'stale';
        }

        return $row;
    }

    private function reviewAction(array $actor, int $id, string $action, int $version): array
    {
        $this->clearSession($actor['phone']);
        $row = $this->decidable($id, $version);
        if ($row === 'stale') {
            $this->say($actor, '⚠️ Cererea #' . $id . ' a fost modificată între timp. Iată varianta actuală:', 'stale_intervention');
            $this->sendCard($actor, $id, null);
            return ['outcome' => 'stale_intervention'];
        }
        if (is_string($row)) {
            return $this->say($actor, $row, 'not_decidable');
        }
        $data = ['intervention_id' => $id, 'version' => $version];

        if ($action === 'ok') {
            $coordinators = $this->workflow->getCoordinators();
            if ($coordinators === []) {
                return $this->say($actor, 'Nu există niciun utilizator cu dreptul de coordonare tehnică; cererea nu poate fi aprobată.', 'no_coordinator');
            }
            $choices = array_map(static fn(array $c): array => ['c' . $c['id'], '👤 ' . $c['nume']], $coordinators);
            $choices[] = ['cancel', '❌ Anulează'];
            $this->ask($actor, 'review', 'coordinator', $data, 'Aprobare cerere #' . $id . "\nCine este responsabilul tehnic?", $choices);
            return ['outcome' => 'asked_coordinator'];
        }
        if ($action === 'no') {
            $this->ask($actor, 'review', 'reject_reason', $data, 'Respingere cerere #' . $id . "\nScrieți motivul (îl va primi șoferul). Pentru a renunța, scrieți „anulează”.");
            return ['outcome' => 'asked_reject_reason'];
        }
        $this->ask($actor, 'review', 'more', $data, 'Cererea #' . $id . ' — ce doriți să faceți?', [['date', '📅 Modifică data'], ['info', '💬 Solicită detalii'], ['cancel', '❌ Anulează']]);

        return ['outcome' => 'asked_more'];
    }

    private function reviewStep(array $actor, array $session, string $kind, ?string $actionId, ?string $text): array
    {
        $data = $session['data'];
        $id = (int) $data['intervention_id'];
        $choice = self::choice($session, $actionId, $text);
        if ($choice === 'cancel') {
            $this->clearSession($actor['phone']);
            return $this->say($actor, 'Am renunțat. Cererea #' . $id . ' rămâne de verificat.', 'cancelled');
        }
        // Dreptul se reverifica la fiecare pas (poate fi retras intre timp).
        $reviewer = $this->reviewerById($actor['id']);
        if ($reviewer === null) {
            $this->clearSession($actor['phone']);
            return $this->say($actor, 'Nu mai aveți dreptul de a decide cererile de service.', 'not_a_reviewer');
        }
        $row = $this->decidable($id, (int) $data['version']);
        if ($row === 'stale' || is_string($row)) {
            $this->clearSession($actor['phone']);
            if ($row === 'stale') {
                $this->say($actor, '⚠️ Cererea #' . $id . ' a fost modificată între timp. Iată varianta actuală:', 'stale_intervention');
                $this->sendCard($actor, $id, null);
                return ['outcome' => 'stale_intervention'];
            }
            return $this->say($actor, $row, 'not_decidable');
        }
        $textValue = $kind === 'text' && $text !== null ? trim($text) : '';

        switch ((string) $session['step']) {
            case 'coordinator':
                if ($choice === null || !str_starts_with($choice, 'c')) {
                    break;
                }
                $data['coordinator_id'] = (int) substr($choice, 1);
                $this->ask($actor, 'review', 'approve_date', $data,
                    'Data propusă: *' . self::dateLabel((string) $row['data_programata'], $row['ora_programata'] ?? null) . '*',
                    [['keep', '✅ Păstrează data'], ['change', '📅 Altă dată'], ['cancel', '❌ Anulează']]);
                return ['outcome' => 'asked_approve_date'];
            case 'approve_date':
                if ($choice === 'keep') {
                    return $this->approve($actor, $row, (int) $data['coordinator_id'], (string) $row['data_programata'], $row['ora_programata'] !== null ? substr((string) $row['ora_programata'], 0, 5) : '');
                }
                if ($choice === 'change') {
                    $this->ask($actor, 'review', 'approve_new_date', $data, 'Scrieți data programării (de ex. *30.10* sau *30.10 14:00*).');
                    return ['outcome' => 'asked_date'];
                }
                break;
            case 'approve_new_date':
            case 'new_date':
                if ($textValue === '') {
                    break;
                }
                $parsed = self::parseDate($textValue);
                if (isset($parsed['error'])) {
                    return $this->say($actor, $parsed['error'], 'invalid_date');
                }
                if ($session['step'] === 'approve_new_date') {
                    return $this->approve($actor, $row, (int) $data['coordinator_id'], $parsed['date'], (string) ($parsed['time'] ?? ''));
                }
                return $this->changeDate($actor, $row, $parsed['date'], (string) ($parsed['time'] ?? ''));
            case 'reject_reason':
                if (mb_strlen($textValue) < 3) {
                    return $this->say($actor, 'Vă rugăm să scrieți motivul respingerii (sau „anulează”).', 'reason_too_short');
                }
                return $this->reject($actor, $row, mb_substr($textValue, 0, 500));
            case 'more':
                if ($choice === 'date') {
                    $this->ask($actor, 'review', 'new_date', $data, 'Scrieți noua dată (de ex. *30.10* sau *30.10 14:00*).');
                    return ['outcome' => 'asked_date'];
                }
                if ($choice === 'info') {
                    $this->ask($actor, 'review', 'info_question', $data, 'Scrieți întrebarea pentru șofer.');
                    return ['outcome' => 'asked_question'];
                }
                break;
            case 'info_question':
                if (mb_strlen($textValue) < 3) {
                    return $this->say($actor, 'Vă rugăm să scrieți întrebarea pentru șofer (sau „anulează”).', 'question_too_short');
                }
                return $this->askDriverDetails($actor, $row, mb_substr($textValue, 0, 1000));
        }

        $count = count($data['choices'] ?? []);
        return $this->say($actor, $count > 0
            ? 'Vă rugăm să alegeți una dintre opțiuni (1–' . $count . ') sau scrieți „anulează”.'
            : 'Vă rugăm să răspundeți la întrebarea de mai sus sau scrieți „anulează”.', 'unexpected_reply');
    }

    private function approve(array $actor, array $row, int $coordinatorId, string $date, string $time): array
    {
        $id = (int) $row['id'];
        $changes = $this->workflow->updateFromPanel($id, [
            'status' => 'programata',
            'responsabil_user_id' => $coordinatorId,
            'data_programata' => $date,
            'ora_programata' => $time,
        ], $actor['id'], false);
        $this->workflow->logEvent($id, 'whatsapp', 'Cerere aprobată prin WhatsApp de ' . $actor['nume'], ['sursa' => 'whatsapp'], $actor['id']);
        audit_log_write($this->db, 'mentenanta_interventii', $id, 'update', 'Intervenție #' . $id . ' aprobată prin WhatsApp', null, $changes, $actor['id']);
        $this->clearSession($actor['phone']);
        $this->say($actor, '✅ Cererea #' . $id . ' a fost aprobată: programată ' . self::dateLabel($date, $time) . '.', 'approved');
        $this->notifyDriver($id);

        return ['outcome' => 'approved', 'intervention_id' => $id];
    }

    private function reject(array $actor, array $row, string $reason): array
    {
        $id = (int) $row['id'];
        $changes = $this->workflow->updateFromPanel($id, ['status' => 'anulata'], $actor['id'], false);
        $this->workflow->logEvent($id, 'whatsapp', 'Cerere respinsă prin WhatsApp de ' . $actor['nume'] . ': ' . $reason, ['sursa' => 'whatsapp', 'motiv' => $reason], $actor['id']);
        audit_log_write($this->db, 'mentenanta_interventii', $id, 'update', 'Intervenție #' . $id . ' respinsă prin WhatsApp', null, $changes + ['motiv' => $reason], $actor['id']);
        $this->clearSession($actor['phone']);
        $this->say($actor, '❌ Cererea #' . $id . ' a fost respinsă.', 'rejected');
        $this->notifyDriver($id, $reason);

        return ['outcome' => 'rejected', 'intervention_id' => $id];
    }

    private function changeDate(array $actor, array $row, string $date, string $time): array
    {
        $id = (int) $row['id'];
        $changes = $this->workflow->updateFromPanel($id, ['data_programata' => $date, 'ora_programata' => $time], $actor['id'], false);
        $this->workflow->logEvent($id, 'whatsapp', 'Data modificată prin WhatsApp de ' . $actor['nume'], ['sursa' => 'whatsapp'], $actor['id']);
        audit_log_write($this->db, 'mentenanta_interventii', $id, 'update', 'Intervenție #' . $id . ' reprogramată prin WhatsApp', null, $changes, $actor['id']);
        $this->clearSession($actor['phone']);
        $this->say($actor, '📅 Data cererii #' . $id . ' este acum ' . self::dateLabel($date, $time) . '. Cererea rămâne de verificat.', 'date_changed');
        $this->notifyDriver($id);
        $this->sendCard($actor, $id, null);

        return ['outcome' => 'date_changed', 'intervention_id' => $id];
    }

    private function askDriverDetails(array $actor, array $row, string $question): array
    {
        $id = (int) $row['id'];
        $driverId = $this->requestDriverId($id) ?? ($row['driver_id'] !== null ? (int) $row['driver_id'] : null);
        $driver = $driverId !== null ? $this->outbox->getActiveDriver($driverId) : null;
        $this->clearSession($actor['phone']);
        if ($driver === null || $driver['phone_e164'] === null
            || ($this->config['driver_test_mode'] && !in_array($driver['id'], $this->config['driver_ids'], true))) {
            return $this->say($actor, 'Cererea #' . $id . ' nu are un șofer care poate fi contactat pe WhatsApp.', 'no_driver');
        }
        $this->workflow->logEvent($id, 'whatsapp', 'Detalii solicitate șoferului prin WhatsApp de ' . $actor['nume'] . ': ' . $question, ['sursa' => 'whatsapp', 'intrebare' => $question], $actor['id']);
        $driverActor = ['role' => 'driver', 'id' => $driver['id'], 'nume' => $driver['nume'], 'phone' => $driver['phone_e164']];
        $this->say($actor, '💬 Întrebarea a fost trimisă șoferului ' . $driver['nume'] . '. Veți primi răspunsul aici.', 'details_requested');
        $this->ask($driverActor, 'details', 'answer', ['intervention_id' => $id, 'reviewer_id' => $actor['id']],
            '💬 Operatorul solicită detalii pentru cererea de service *#' . $id . "*:\n" . $question . "\n\nRăspundeți aici, printr-un mesaj.",
            [], self::DETAILS_SESSION_HOURS * 60);

        return ['outcome' => 'details_requested', 'intervention_id' => $id];
    }

    // -------------------------------------------------------------------------
    // Coordonator: cardul interventiei, preluare, reasignare
    // -------------------------------------------------------------------------

    /** Statusurile in care coordonatorul lucreaza pe interventie (dupa aprobare, inainte de finalizare). */
    private const WORK_STATUSES = ['programata', 'in_service', 'in_asteptare'];

    /** 7 componente + Mai multe / Inapoi / Alte categorii = 10, cat incape intr-o lista WhatsApp. */
    private const ITEM_PAGE_SIZE = 7;

    private function canCosts(array $actor): bool
    {
        $reviewer = $this->reviewerById($actor['id']);

        return $reviewer !== null && user_can($reviewer['user'], 'mentenanta', 'interventions_costs');
    }

    private static function itemLine(array $item): string
    {
        $line = $item['nume'] . ($item['pozitie'] !== '' ? ' (' . $item['pozitie'] . ')' : '')
            . ($item['vehicul_componenta'] !== '' ? ' — ' . $item['vehicul_componenta'] : '')
            . ' · ' . InterventionWorkflowModel::OPERATION_LABELS[$item['operatiune']]
            . ' · ' . InterventionWorkflowModel::ASSESSMENT_LABELS[$item['stare_evaluare']];
        if ($item['stare_evaluare'] !== 'exclusa') {
            $line .= ' · ' . ($item['rezultat'] !== '' ? InterventionWorkflowModel::RESULT_LABELS[$item['rezultat']] : InterventionWorkflowModel::PROGRESS_LABELS[$item['progres']]);
        }

        return $line;
    }

    public static function coordCardText(array $row, array $items, bool $withCosts): string
    {
        $model = trim((string) ($row['marca'] ?? '') . ' ' . (string) ($row['model'] ?? ''));
        $statusKey = InterventionWorkflowModel::statusKey((string) $row['status_interventie']);
        $lines = [
            '*Intervenție #' . (int) $row['id'] . '* · ' . InterventionWorkflowModel::STATUS_LABELS[$statusKey],
            'Vehicul: *' . $row['nr_inmatriculare'] . '*' . ($model !== '' ? ' (' . $model . ')' : ''),
            'Tip: ' . self::typeLabel((string) $row['tip_interventie']) . ' · Data: ' . self::dateLabel((string) $row['data_programata'], $row['ora_programata'] ?? null),
            'Responsabil: ' . (!empty($row['responsabil_nume']) ? $row['responsabil_nume'] : 'Nealocat'),
        ];
        if (!empty($row['furnizor'])) {
            $lines[] = 'Service: ' . $row['furnizor'];
        }
        if (!empty($row['sofer_nume'])) {
            $lines[] = 'Șofer: ' . $row['sofer_nume'];
        }
        $lines[] = 'Sesizare: ' . mb_strimwidth(trim((string) ($row['sesizare_initiala'] ?? '') ?: (string) $row['descriere']), 0, 400, '…', 'UTF-8');
        if (trim((string) ($row['diagnostic_tehnic'] ?? '')) !== '') {
            $lines[] = 'Diagnostic: ' . mb_strimwidth(trim((string) $row['diagnostic_tehnic']), 0, 400, '…', 'UTF-8');
        }
        $lines[] = '';
        $lines[] = $items === [] ? 'Lucrări: niciuna încă.' : '*Lucrări:*';
        foreach (array_slice($items, 0, 15) as $i => $item) {
            $lines[] = ($i + 1) . '. ' . self::itemLine($item);
        }
        if ($withCosts) {
            $parts = array_sum(array_map(static fn(array $i): float => $i['stare_evaluare'] === 'exclusa' ? 0.0 : $i['cost_piese'], $items));
            $labor = array_sum(array_map(static fn(array $i): float => $i['stare_evaluare'] === 'exclusa' ? 0.0 : $i['cost_manopera'], $items));
            if ($parts + $labor > 0) {
                $lines[] = 'Costuri lucrări: piese ' . number_format($parts, 2, ',', '.') . ' · manoperă ' . number_format($labor, 2, ',', '.') . ' lei';
            }
        }

        return implode("\n", $lines);
    }

    /** Cardul coordonatorului: Preiau / Lucrări, Detalii, Reasignare. */
    private function sendCoordCard(array $to, int $id, ?string $dedupeKey, string $intro = ''): bool
    {
        $row = $this->interventionRow($id);
        if ($row === null) {
            return false;
        }
        $statusKey = InterventionWorkflowModel::statusKey((string) $row['status_interventie']);
        $options = [];
        if (in_array($statusKey, self::WORK_STATUSES, true)) {
            $version = self::version($row);
            $mine = (int) ($row['responsabil_user_id'] ?? 0) === $to['id'];
            $prefix = self::ACTION_PREFIX . 'c:' . $id . ':';
            $options = [
                $mine ? ['id' => $prefix . 'work:' . $version, 'title' => '🔧 Lucrări'] : ['id' => $prefix . 'take:' . $version, 'title' => '✅ Preiau'],
                ['id' => $prefix . 'info:' . $version, 'title' => '🔍 Detalii'],
                ['id' => $prefix . 'reass:' . $version, 'title' => '↗️ Reasignare'],
            ];
        }
        $body = ($intro !== '' ? $intro . "\n\n" : '') . self::coordCardText($row, $this->workflow->items($id), $this->canCosts($to));
        $this->send($to, 'maintenance_intervention', $body, $options, $dedupeKey, $id);

        return true;
    }

    private function listMine(array $actor): array
    {
        $this->clearSession($actor['phone']);
        $stmt = $this->db->prepare("
            SELECT i.id, i.tip_interventie, i.status_interventie, i.data_programata, i.ora_programata, i.descriere, v.nr_inmatriculare
            FROM mentenanta_interventii_programate i
            JOIN vehicule v ON v.id = i.vehicle_id
            WHERE i.responsabil_user_id = :u AND i.status_interventie IN ('programata', 'confirmata', 'in_lucru', 'in_asteptare')
            ORDER BY i.data_programata ASC, i.id ASC LIMIT 15
        ");
        $stmt->execute([':u' => $actor['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return $this->say($actor, 'Nu aveți intervenții deschise ca responsabil tehnic.', 'mine_empty');
        }
        $lines = ['*Intervențiile mele*'];
        foreach ($rows as $row) {
            $lines[] = '• ' . $row['nr_inmatriculare'] . ' ' . self::interventionLine($row);
        }
        $lines[] = '';
        $lines[] = 'Pentru detalii și lucrări, scrieți *INTERVENTIE* și numărul (de ex. *INTERVENTIE ' . (int) $rows[0]['id'] . '*).';

        return $this->say($actor, implode("\n", $lines), 'mine');
    }

    /**
     * Interventia pe care coordonatorul poate lucra acum: deschisa, a lui, neschimbata de la versiunea folosita.
     *
     * @return array|string randul, 'stale', sau mesajul de refuz
     */
    private function workable(array $actor, int $id, ?int $version, bool $requireMine = true)
    {
        $row = $this->interventionRow($id);
        if ($row === null) {
            return 'Intervenția #' . $id . ' nu mai există.';
        }
        $statusKey = InterventionWorkflowModel::statusKey((string) $row['status_interventie']);
        if (!in_array($statusKey, self::WORK_STATUSES, true)) {
            return 'ℹ️ Intervenția #' . $id . ' este *' . InterventionWorkflowModel::STATUS_LABELS[$statusKey] . '*; nu se mai poate lucra pe ea de aici.';
        }
        if ($requireMine && (int) ($row['responsabil_user_id'] ?? 0) !== $actor['id']) {
            return 'Intervenția #' . $id . ' are alt responsabil tehnic (' . ($row['responsabil_nume'] ?: 'Nealocat') . '). Apăsați *Preiau* pe cardul ei pentru a o prelua.';
        }
        if ($version !== null && self::version($row) !== $version) {
            return 'stale';
        }

        return $row;
    }

    /** Raspunsul la o interventie care nu (mai) e lucrabila; la 'stale' se retrimite cardul actual. */
    private function refuseWork(array $actor, int $id, string $reason): array
    {
        $this->clearSession($actor['phone']);
        if ($reason === 'stale') {
            $this->sendCoordCard($actor, $id, null, '⚠️ Intervenția #' . $id . ' a fost modificată între timp. Iată varianta actuală:');
            return ['outcome' => 'stale_intervention'];
        }

        return $this->say($actor, $reason, 'not_workable');
    }

    private function coordAction(array $actor, int $id, string $action, int $version): array
    {
        $this->clearSession($actor['phone']);
        if ($action === 'info') {
            $row = $this->interventionRow($id);
            if ($row === null) {
                return $this->say($actor, 'Intervenția #' . $id . ' nu mai există.', 'not_found');
            }
            $history = $this->workflow->history($id);
            $lines = [];
            foreach (array_reverse(array_slice($history, -6)) as $event) {
                $lines[] = '• ' . date('d.m H:i', (int) strtotime((string) ($event['created_at'] ?? 'now'))) . ' ' . mb_strimwidth((string) ($event['descriere'] ?? ''), 0, 120, '…', 'UTF-8');
            }
            $this->sendCoordCard($actor, $id, null, $lines !== [] ? "*Ultimele evenimente:*\n" . implode("\n", $lines) : '');
            return ['outcome' => 'details_sent'];
        }
        $row = $this->workable($actor, $id, $version, $action === 'work');
        if (is_string($row)) {
            return $this->refuseWork($actor, $id, $row);
        }
        if ($action === 'take') {
            if ((int) ($row['responsabil_user_id'] ?? 0) === $actor['id']) {
                return $this->workMenu($actor, $row, 'Sunteți deja responsabilul intervenției #' . $id . '.');
            }
            $data = ['intervention_id' => $id, 'version' => self::version($row), 'pending' => [
                'kind' => 'responsible', 'user_id' => $actor['id'],
                'summary' => 'Preluați intervenția #' . $id . ' ca responsabil tehnic' . (!empty($row['responsabil_nume']) ? ' (în locul lui ' . $row['responsabil_nume'] . ')' : '') . '?',
            ]];
            return $this->askConfirmChange($actor, $data);
        }
        if ($action === 'reass') {
            $coordinators = array_values(array_filter($this->workflow->getCoordinators(), static fn(array $c): bool => $c['id'] !== (int) ($row['responsabil_user_id'] ?? 0)));
            if ($coordinators === []) {
                return $this->say($actor, 'Nu există alt utilizator cu dreptul de coordonare tehnică.', 'no_coordinator');
            }
            $choices = array_map(static fn(array $c): array => ['u' . $c['id'], '👤 ' . $c['nume']], $coordinators);
            $choices[] = ['cancel', '❌ Anulează'];
            $this->ask($actor, 'coord', 'reassign', ['intervention_id' => $id, 'version' => self::version($row)], 'Reasignare intervenția #' . $id . "\nCine preia intervenția?", $choices);
            return ['outcome' => 'asked_reassign'];
        }

        return $this->workMenu($actor, $row);
    }

    // -------------------------------------------------------------------------
    // Coordonator: meniul de lucru si pasii lui
    // -------------------------------------------------------------------------

    private function workMenu(array $actor, array $row, string $intro = ''): array
    {
        $id = (int) $row['id'];
        $items = $this->workflow->items($id);
        $statusKey = InterventionWorkflowModel::statusKey((string) $row['status_interventie']);
        $choices = [
            ['add', '➕ Adaugă componentă'],
            ['items', '🔧 Lucrările (' . count($items) . ')'],
            ['diag', '🩺 Diagnostic tehnic'],
            ['status', '🔄 Status (' . InterventionWorkflowModel::STATUS_LABELS[$statusKey] . ')'],
            ['fin', '🏁 Finalizează intervenția'],
            ['close', '✖️ Închide'],
        ];
        $body = ($intro !== '' ? $intro . "\n\n" : '') . '*Intervenția #' . $id . '* · ' . $row['nr_inmatriculare'] . "\nCe doriți să faceți?";
        $this->ask($actor, 'coord', 'menu', ['intervention_id' => $id, 'version' => self::version($row)], $body, $choices);

        return ['outcome' => 'work_menu'];
    }

    private function askConfirmChange(array $actor, array $data): array
    {
        $this->ask($actor, 'coord', 'confirm', $data, '*Confirmați:*' . "\n" . $data['pending']['summary'], [['save', '✅ Salvează'], ['cancel', '❌ Renunță']]);

        return ['outcome' => 'asked_confirm_change'];
    }

    /** Lucrarea interventiei (dupa ID), sau null. */
    private function item(int $interventionId, int $itemId): ?array
    {
        foreach ($this->workflow->items($interventionId) as $item) {
            if ($item['id'] === $itemId) {
                return $item;
            }
        }

        return null;
    }

    private function itemMenu(array $actor, array $data, array $item, string $intro = ''): array
    {
        $choices = [];
        if ($item['stare_evaluare'] !== 'confirmata') {
            $choices[] = ['confirm', '✔️ Confirmă lucrarea'];
        }
        if ($item['stare_evaluare'] !== 'exclusa') {
            $choices[] = ['exclude', '🚫 Exclude (cu motiv)'];
            $choices[] = ['op', '🔁 Operațiune: ' . InterventionWorkflowModel::OPERATION_LABELS[$item['operatiune']]];
            $choices[] = ['prog', '▶️ Progres: ' . InterventionWorkflowModel::PROGRESS_LABELS[$item['progres']]];
            $choices[] = ['idiag', '🩺 Diagnostic componentă'];
            if ($this->canCosts($actor)) {
                $choices[] = ['cost', '💰 Costuri'];
            }
        }
        if ($item['component_key'] !== null && $item['component_key'] !== '') {
            $choices[] = ['cinfo', 'ℹ️ Informații componentă'];
        }
        $choices[] = ['back', '↩️ Înapoi'];
        $data['item_id'] = $item['id'];
        $details = self::itemLine($item)
            . ($item['diagnostic'] !== '' ? "\nDiagnostic: " . mb_strimwidth($item['diagnostic'], 0, 300, '…', 'UTF-8') : '')
            . ($item['observatii'] !== '' ? "\nObservații: " . mb_strimwidth($item['observatii'], 0, 300, '…', 'UTF-8') : '');
        $this->ask($actor, 'coord', 'item', $data, ($intro !== '' ? $intro . "\n\n" : '') . "*Lucrare:*\n" . $details, $choices);

        return ['outcome' => 'item_menu'];
    }

    private function coordStep(array $actor, array $session, string $kind, ?string $actionId, ?string $text): array
    {
        $data = $session['data'];
        $id = (int) ($data['intervention_id'] ?? 0);
        $step = (string) $session['step'];
        $choice = self::choice($session, $actionId, $text);
        $textValue = $kind === 'text' && $text !== null ? trim($text) : '';
        if ($choice === 'cancel' || $choice === 'close') {
            $this->clearSession($actor['phone']);
            return $this->say($actor, $choice === 'close' ? 'Am închis meniul intervenției #' . $id . '.' : 'Am renunțat; nimic nu a fost salvat.', 'cancelled');
        }
        if ($this->reviewerById($actor['id']) === null) {
            $this->clearSession($actor['phone']);
            return $this->say($actor, 'Nu mai aveți dreptul de coordonare a intervențiilor.', 'not_a_reviewer');
        }
        // Reasignarea / preluarea nu cer sa fii deja responsabil; restul da.
        $requireMine = !($step === 'reassign' || ($step === 'confirm' && ($data['pending']['kind'] ?? '') === 'responsible'));
        $row = $this->workable($actor, $id, (int) ($data['version'] ?? 0), $requireMine);
        if (is_string($row)) {
            return $this->refuseWork($actor, $id, $row);
        }

        switch ($step) {
            case 'menu':
                return $this->coordMenuChoice($actor, $row, $data, $choice);
            case 'reassign':
                if ($choice === null || !str_starts_with($choice, 'u')) {
                    break;
                }
                $userId = (int) substr($choice, 1);
                $name = '';
                foreach ($this->workflow->getCoordinators() as $c) {
                    if ($c['id'] === $userId) {
                        $name = $c['nume'];
                    }
                }
                $data['pending'] = ['kind' => 'responsible', 'user_id' => $userId, 'summary' => 'Responsabilul intervenției #' . $id . ' devine *' . $name . '*.'];
                return $this->askConfirmChange($actor, $data);
            case 'confirm':
                if ($choice !== 'save') {
                    break;
                }
                return $this->applyPending($actor, $row, $data);
            case 'diag':
                if (mb_strlen($textValue) < 3) {
                    return $this->say($actor, 'Scrieți diagnosticul tehnic (sau „anulează”).', 'text_too_short');
                }
                $data['pending'] = ['kind' => 'diagnostic', 'text' => mb_substr($textValue, 0, 5000), 'summary' => "Diagnostic tehnic:\n" . mb_substr($textValue, 0, 5000)];
                return $this->askConfirmChange($actor, $data);
            case 'status':
                if ($choice === null || !isset(InterventionWorkflowModel::STATUS_LABELS[$choice])) {
                    break;
                }
                $data['pending'] = ['kind' => 'status', 'status' => $choice, 'summary' => 'Statusul intervenției #' . $id . ' devine *' . InterventionWorkflowModel::STATUS_LABELS[$choice] . '*.'];
                return $this->askConfirmChange($actor, $data);
            case 'items':
                if ($choice === 'back') {
                    return $this->workMenu($actor, $row);
                }
                if ($choice === null || !str_starts_with($choice, 'i')) {
                    break;
                }
                $item = $this->item($id, (int) substr($choice, 1));
                if ($item === null) {
                    return $this->workMenu($actor, $row, 'Lucrarea nu mai există.');
                }
                return $this->itemMenu($actor, $data, $item);
            case 'item':
            case 'item_op':
            case 'item_prog':
            case 'item_text':
                return $this->itemStep($actor, $row, $data, $step, $choice, $textValue);
            case 'add_vehicle':
            case 'add_sub':
            case 'add_cat':
            case 'add_comp':
            case 'add_op':
            case 'add_pos':
                return $this->addStep($actor, $row, $data, $step, $choice, $textValue);
            case 'fin_item':
            case 'fin_replace':
            case 'fin_date':
            case 'fin_km':
            case 'fin_follow':
            case 'fin_confirm':
                return $this->finalizeStep($actor, $row, $data, $step, $choice, $textValue);
        }

        $count = count($data['choices'] ?? []);
        return $this->say($actor, $count > 0
            ? 'Vă rugăm să alegeți una dintre opțiuni (1–' . $count . ') sau scrieți „anulează”.'
            : 'Vă rugăm să răspundeți la întrebarea de mai sus sau scrieți „anulează”.', 'unexpected_reply');
    }

    private function coordMenuChoice(array $actor, array $row, array $data, ?string $choice): array
    {
        $id = (int) $row['id'];
        switch ($choice) {
            case 'add':
                return $this->addStart($actor, $row, $data);
            case 'items':
                $items = $this->workflow->items($id);
                if ($items === []) {
                    return $this->workMenu($actor, $row, 'Intervenția nu are încă lucrări. Folosiți *Adaugă componentă*.');
                }
                $choices = [];
                foreach (array_slice($items, 0, 20) as $item) {
                    $choices[] = ['i' . $item['id'], self::itemLine($item)];
                }
                $choices[] = ['back', '↩️ Înapoi'];
                $this->ask($actor, 'coord', 'items', $data, 'Lucrările intervenției #' . $id . ' — alegeți una:', $choices);
                return ['outcome' => 'asked_item'];
            case 'diag':
                $current = trim((string) ($row['diagnostic_tehnic'] ?? ''));
                $this->ask($actor, 'coord', 'diag', $data, ($current !== '' ? "Diagnosticul actual:\n" . $current . "\n\n" : '')
                    . "Scrieți diagnosticul tehnic (înlocuiește textul actual). Sesizarea inițială a șoferului rămâne neschimbată.\nPentru a renunța, scrieți „anulează”.");
                return ['outcome' => 'asked_diagnostic'];
            case 'status':
                $current = InterventionWorkflowModel::statusKey((string) $row['status_interventie']);
                $choices = [];
                foreach (self::WORK_STATUSES as $key) {
                    if ($key !== $current) {
                        $choices[] = [$key, InterventionWorkflowModel::STATUS_LABELS[$key]];
                    }
                }
                $choices[] = ['cancel', '❌ Anulează'];
                $this->ask($actor, 'coord', 'status', $data, 'Status actual: *' . InterventionWorkflowModel::STATUS_LABELS[$current] . "*\nNoul status?", $choices);
                return ['outcome' => 'asked_status'];
            case 'fin':
                return $this->finalizeStart($actor, $row, $data);
        }

        return $this->say($actor, 'Vă rugăm să alegeți una dintre opțiuni (1–' . count($data['choices'] ?? []) . ') sau scrieți „anulează”.', 'unexpected_reply');
    }

    private function itemStep(array $actor, array $row, array $data, string $step, ?string $choice, string $textValue): array
    {
        $id = (int) $row['id'];
        $item = $this->item($id, (int) ($data['item_id'] ?? 0));
        if ($item === null) {
            return $this->workMenu($actor, $row, 'Lucrarea nu mai există.');
        }
        $label = $item['nume'] . ($item['pozitie'] !== '' ? ' (' . $item['pozitie'] . ')' : '');

        if ($step === 'item') {
            switch ($choice) {
                case 'back':
                    return $this->workMenu($actor, $row);
                case 'confirm':
                    $data['pending'] = ['kind' => 'item', 'fields' => ['stare_evaluare' => 'confirmata'], 'summary' => $label . ': lucrarea devine *confirmată*.'];
                    return $this->askConfirmChange($actor, $data);
                case 'exclude':
                case 'idiag':
                case 'cost':
                    $data['text_kind'] = $choice;
                    $prompts = [
                        'exclude' => 'Scrieți motivul pentru care excludeți „' . $label . '” (rămâne în istoric ca exclusă).',
                        'idiag' => 'Scrieți diagnosticul pentru „' . $label . '”.',
                        'cost' => 'Scrieți costurile pentru „' . $label . "” ca: *piese 250 manopera 120* (lei, fără TVA).\nActual: piese " . number_format($item['cost_piese'], 2, ',', '.') . ', manoperă ' . number_format($item['cost_manopera'], 2, ',', '.') . '.',
                    ];
                    $this->ask($actor, 'coord', 'item_text', $data, $prompts[$choice] . "\nPentru a renunța, scrieți „anulează”.");
                    return ['outcome' => 'asked_item_text'];
                case 'op':
                    $choices = [];
                    foreach (InterventionWorkflowModel::OPERATION_LABELS as $key => $title) {
                        $choices[] = [$key, $title];
                    }
                    $this->ask($actor, 'coord', 'item_op', $data, 'Operațiunea pentru „' . $label . '”:', $choices);
                    return ['outcome' => 'asked_operation'];
                case 'prog':
                    $choices = [];
                    foreach (InterventionWorkflowModel::PROGRESS_LABELS as $key => $title) {
                        if ($key !== $item['progres']) {
                            $choices[] = [$key, $title];
                        }
                    }
                    $this->ask($actor, 'coord', 'item_prog', $data, 'Progresul pentru „' . $label . '”:', $choices);
                    return ['outcome' => 'asked_progress'];
                case 'cinfo':
                    $this->say($actor, $this->componentInfo((string) $item['component_key'], $item['vehicle_id'] ?? (int) $row['vehicle_id']), 'component_info');
                    return $this->itemMenu($actor, $data, $item);
            }
        } elseif ($step === 'item_op' && $choice !== null && isset(InterventionWorkflowModel::OPERATION_LABELS[$choice])) {
            $data['pending'] = ['kind' => 'item', 'fields' => ['operatiune' => $choice], 'summary' => $label . ': operațiunea devine *' . InterventionWorkflowModel::OPERATION_LABELS[$choice] . '*.'
                . ($choice === 'inlocuire' ? "\nUzura componentei se resetează doar la finalizare, după confirmarea explicită a înlocuirii." : '')];
            return $this->askConfirmChange($actor, $data);
        } elseif ($step === 'item_prog' && $choice !== null && isset(InterventionWorkflowModel::PROGRESS_LABELS[$choice])) {
            $data['pending'] = ['kind' => 'item', 'fields' => ['progres' => $choice], 'summary' => $label . ': progresul devine *' . InterventionWorkflowModel::PROGRESS_LABELS[$choice] . '*.'];
            return $this->askConfirmChange($actor, $data);
        } elseif ($step === 'item_text') {
            $kind = (string) ($data['text_kind'] ?? '');
            if (mb_strlen($textValue) < 2) {
                return $this->say($actor, 'Vă rugăm să scrieți textul cerut (sau „anulează”).', 'text_too_short');
            }
            if ($kind === 'exclude') {
                $note = trim(($item['observatii'] !== '' ? $item['observatii'] . "\n" : '') . 'Exclusă: ' . $textValue);
                $data['pending'] = ['kind' => 'item', 'fields' => ['stare_evaluare' => 'exclusa', 'observatii' => $note], 'summary' => $label . ': *exclusă*. Motiv: ' . $textValue];
            } elseif ($kind === 'idiag') {
                $data['pending'] = ['kind' => 'item', 'fields' => ['diagnostic' => mb_substr($textValue, 0, 5000)], 'summary' => $label . " — diagnostic:\n" . $textValue];
            } elseif ($kind === 'cost') {
                $costs = self::parseCosts($textValue);
                if ($costs === null) {
                    return $this->say($actor, 'Nu am înțeles costurile. Scrieți de ex. *piese 250 manopera 120* sau doar *manopera 120*.', 'invalid_costs');
                }
                $data['pending'] = ['kind' => 'item', 'fields' => $costs, 'summary' => $label . ': costuri '
                    . implode(', ', array_map(static fn(string $k, float $v): string => ($k === 'cost_piese' ? 'piese ' : 'manoperă ') . number_format($v, 2, ',', '.') . ' lei', array_keys($costs), $costs)) . '.'];
            } else {
                return $this->itemMenu($actor, $data, $item);
            }
            return $this->askConfirmChange($actor, $data);
        }

        return $this->say($actor, 'Vă rugăm să alegeți una dintre opțiuni (1–' . count($data['choices'] ?? []) . ') sau scrieți „anulează”.', 'unexpected_reply');
    }

    /** "piese 250 manopera 120,50" -> [cost_piese => 250.0, cost_manopera => 120.5]; null daca nu e nimic recunoscut. */
    public static function parseCosts(string $text): ?array
    {
        // Virgula zecimala inainte de plain(), care o elimina.
        $plain = self::plain(str_replace(',', '.', $text));
        $result = [];
        if (preg_match('/piese\s*:?\s*(\d+(?:\.\d{1,2})?)/', $plain, $m) === 1) {
            $result['cost_piese'] = round((float) $m[1], 2);
        }
        if (preg_match('/manopera\s*:?\s*(\d+(?:\.\d{1,2})?)/', $plain, $m) === 1) {
            $result['cost_manopera'] = round((float) $m[1], 2);
        }

        return $result === [] ? null : $result;
    }

    // ---------------------------------------------------------------- adaugare componenta din registru

    /**
     * Registrul pentru un vehicul: subcategorie -> categorie -> componente aplicabile tipului lui
     * (aceleasi reguli ca selectorul din pagina: ramurile tipului si monitorizarea "-" = nu se aplica).
     *
     * @return array<string, array<int, array{id:int, name:string, components: array<int, array{key:string, name:string}>}>>
     */
    public function vehicleRegistry(int $vehicleId): array
    {
        $stmt = $this->db->prepare('SELECT tip_vehicul FROM vehicule WHERE id = :id');
        $stmt->execute([':id' => $vehicleId]);
        $type = InterventionWorkflowModel::autoVehicleType((string) $stmt->fetchColumn());
        $primaries = InterventionWorkflowModel::allowedPrimaries($type);
        $tree = [];
        foreach ($this->workflow->componentCatalog()['categories'] as $category) {
            $placement = AutoComponentCatalogService::defaultPlacement((int) $category['id']);
            if ($placement === null || !in_array($placement[0], $primaries, true)) {
                continue;
            }
            $components = [];
            foreach ($category['components'] as $component) {
                $applies = $type === '' || trim((string) ($component['mon'][$type] ?? '')) !== '-';
                if ($applies && $component['key'] !== '') {
                    $components[] = ['key' => $component['key'], 'name' => $component['name']];
                }
            }
            if ($components !== []) {
                $tree[$placement[1]][(int) $category['id']] = ['id' => (int) $category['id'], 'name' => $category['name'], 'primary' => $placement[0], 'components' => $components];
            }
        }

        return $tree;
    }

    private function addStart(array $actor, array $row, array $data): array
    {
        $vehicleId = (int) $row['vehicle_id'];
        $context = $this->workflow->vehicleContext();
        $trailer = $context[$vehicleId]['trailer'] ?? null;
        $data['add'] = ['vehicle_id' => $vehicleId, 'item_vehicle_id' => null];
        if ($trailer !== null) {
            $this->ask($actor, 'coord', 'add_vehicle', $data, 'Pe ce vehicul este componenta?', [
                ['main', '🚛 ' . $row['nr_inmatriculare']], ['trailer', '🛞 ' . $trailer['plate'] . ' (semiremorcă)'], ['cancel', '❌ Anulează'],
            ]);
            return ['outcome' => 'asked_add_vehicle'];
        }

        return $this->askSubcategory($actor, $data);
    }

    private function askSubcategory(array $actor, array $data): array
    {
        $tree = $this->vehicleRegistry((int) $data['add']['vehicle_id']);
        if ($tree === []) {
            return $this->say($actor, 'Registrul de componente nu are componente pentru acest tip de vehicul.', 'registry_empty');
        }
        if (count($tree) === 1) {
            $data['add']['sub'] = (string) array_key_first($tree);
            return $this->askCategory($actor, $data);
        }
        $choices = [];
        foreach (array_keys($tree) as $sub) {
            $choices[] = [$sub, AutoComponentCatalogService::SUBCATEGORY_LABELS[$sub] ?? $sub];
        }
        $choices[] = ['cancel', '❌ Anulează'];
        $this->ask($actor, 'coord', 'add_sub', $data, 'Registru componente — alegeți ramura:', $choices);

        return ['outcome' => 'asked_subcategory'];
    }

    private function askCategory(array $actor, array $data): array
    {
        $tree = $this->vehicleRegistry((int) $data['add']['vehicle_id']);
        $categories = $tree[$data['add']['sub']] ?? [];
        if (count($categories) === 1) {
            $data['add']['cat'] = (int) array_key_first($categories);
            $data['add']['page'] = 0;
            return $this->askComponent($actor, $data);
        }
        $choices = [];
        foreach ($categories as $category) {
            $choices[] = ['k' . $category['id'], $category['name'] . ' (' . count($category['components']) . ')'];
        }
        $choices[] = ['back', '↩️ Înapoi'];
        $this->ask($actor, 'coord', 'add_cat', $data, (AutoComponentCatalogService::SUBCATEGORY_LABELS[$data['add']['sub']] ?? '') . ' — alegeți categoria:', $choices);

        return ['outcome' => 'asked_category'];
    }

    private function askComponent(array $actor, array $data): array
    {
        $tree = $this->vehicleRegistry((int) $data['add']['vehicle_id']);
        $category = $tree[$data['add']['sub']][(int) $data['add']['cat']] ?? null;
        if ($category === null) {
            return $this->askCategory($actor, $data);
        }
        $page = max(0, (int) ($data['add']['page'] ?? 0));
        $pages = (int) ceil(count($category['components']) / self::ITEM_PAGE_SIZE);
        $page = min($page, max(0, $pages - 1));
        $data['add']['page'] = $page;
        $choices = [];
        foreach (array_slice($category['components'], $page * self::ITEM_PAGE_SIZE, self::ITEM_PAGE_SIZE) as $component) {
            $choices[] = ['p' . $component['key'], $component['name']];
        }
        if ($page + 1 < $pages) {
            $choices[] = ['next', '➡️ Mai multe'];
        }
        if ($page > 0) {
            $choices[] = ['prev', '⬅️ Înapoi la pagina ' . $page];
        }
        $choices[] = ['back', '↩️ Alte categorii'];
        $this->ask($actor, 'coord', 'add_comp', $data, $category['name'] . ($pages > 1 ? ' (pagina ' . ($page + 1) . '/' . $pages . ')' : '') . ' — alegeți componenta:', $choices);

        return ['outcome' => 'asked_component'];
    }

    private function addStep(array $actor, array $row, array $data, string $step, ?string $choice, string $textValue): array
    {
        switch ($step) {
            case 'add_vehicle':
                if ($choice === 'main' || $choice === 'trailer') {
                    $trailer = $this->workflow->vehicleContext()[(int) $row['vehicle_id']]['trailer'] ?? null;
                    if ($choice === 'trailer' && $trailer !== null) {
                        $data['add']['vehicle_id'] = (int) $trailer['id'];
                        $data['add']['item_vehicle_id'] = (int) $trailer['id'];
                    }
                    return $this->askSubcategory($actor, $data);
                }
                break;
            case 'add_sub':
                if ($choice !== null && isset(AutoComponentCatalogService::SUBCATEGORY_LABELS[$choice])) {
                    $data['add']['sub'] = $choice;
                    return $this->askCategory($actor, $data);
                }
                break;
            case 'add_cat':
                if ($choice === 'back') {
                    return $this->askSubcategory($actor, $data);
                }
                if ($choice !== null && str_starts_with($choice, 'k')) {
                    $data['add']['cat'] = (int) substr($choice, 1);
                    $data['add']['page'] = 0;
                    return $this->askComponent($actor, $data);
                }
                break;
            case 'add_comp':
                if ($choice === 'next' || $choice === 'prev') {
                    $data['add']['page'] = (int) $data['add']['page'] + ($choice === 'next' ? 1 : -1);
                    return $this->askComponent($actor, $data);
                }
                if ($choice === 'back') {
                    return $this->askCategory($actor, $data);
                }
                if ($choice !== null && str_starts_with($choice, 'p')) {
                    $key = substr($choice, 1);
                    $category = $this->vehicleRegistry((int) $data['add']['vehicle_id'])[$data['add']['sub']][(int) $data['add']['cat']] ?? ['components' => [], 'primary' => 'sasiu'];
                    $component = null;
                    foreach ($category['components'] as $c) {
                        if ($c['key'] === $key) {
                            $component = $c;
                        }
                    }
                    if ($component === null) {
                        return $this->askComponent($actor, $data);
                    }
                    $data['add']['key'] = $key;
                    $data['add']['name'] = $component['name'];
                    $data['add']['primary'] = $category['primary'];
                    $choices = [];
                    foreach (InterventionWorkflowModel::OPERATION_LABELS as $op => $title) {
                        $choices[] = [$op, $title];
                    }
                    $this->ask($actor, 'coord', 'add_op', $data, '*' . $component['name'] . "*\nCe operațiune?", $choices);
                    return ['outcome' => 'asked_operation'];
                }
                break;
            case 'add_op':
                if ($choice !== null && isset(InterventionWorkflowModel::OPERATION_LABELS[$choice])) {
                    $data['add']['op'] = $choice;
                    $this->ask($actor, 'coord', 'add_pos', $data, 'Poziția (de ex. *față stânga*, *axa 2*), sau scrieți „-” dacă nu e cazul.');
                    return ['outcome' => 'asked_position'];
                }
                break;
            case 'add_pos':
                if ($textValue === '') {
                    break;
                }
                $position = in_array($textValue, ['-', '—', '0'], true) ? '' : mb_substr($textValue, 0, 80);
                $add = $data['add'];
                // Aceeasi componenta, pe acelasi vehicul si pozitie, deja printre lucrari (neexclusa): nu dublam.
                foreach ($this->workflow->items((int) $row['id']) as $item) {
                    if ($item['component_key'] === $add['key'] && $item['stare_evaluare'] !== 'exclusa'
                        && ($item['vehicle_id'] ?? null) === $add['item_vehicle_id'] && mb_strtolower($item['pozitie']) === mb_strtolower($position)) {
                        return $this->itemMenu($actor, $data, $item, 'ℹ️ Componenta există deja printre lucrări:');
                    }
                }
                $data['pending'] = [
                    'kind' => 'add',
                    'fields' => [
                        'component_key' => $add['key'], 'ramura_principala' => $add['primary'], 'pozitie' => $position,
                        'operatiune' => $add['op'], 'stare_evaluare' => 'confirmata', 'vehicle_id' => $add['item_vehicle_id'],
                    ],
                    'summary' => 'Adaug: *' . $add['name'] . '*' . ($position !== '' ? ' (' . $position . ')' : '') . ' — '
                        . InterventionWorkflowModel::OPERATION_LABELS[$add['op']] . ($add['item_vehicle_id'] !== null ? ' — pe semiremorcă' : ''),
                ];
                unset($data['add']);
                return $this->askConfirmChange($actor, $data);
        }

        return $this->say($actor, 'Vă rugăm să alegeți una dintre opțiuni (1–' . count($data['choices'] ?? []) . ') sau scrieți „anulează”.', 'unexpected_reply');
    }

    // ---------------------------------------------------------------- salvarea unei modificari confirmate

    private function applyPending(array $actor, array $row, array $data): array
    {
        $id = (int) $row['id'];
        $pending = $data['pending'] ?? [];
        unset($data['pending']);
        $canCosts = $this->canCosts($actor);
        try {
            switch ($pending['kind'] ?? '') {
                case 'responsible':
                    $changes = $this->workflow->updateFromPanel($id, ['responsabil_user_id' => (int) $pending['user_id']], $actor['id'], false);
                    $done = (int) $pending['user_id'] === $actor['id'] ? 'Ați preluat intervenția #' . $id . '.' : 'Intervenția #' . $id . ' a fost reasignată.';
                    break;
                case 'diagnostic':
                    $changes = $this->workflow->updateFromPanel($id, ['diagnostic_tehnic' => (string) $pending['text']], $actor['id'], false);
                    $done = 'Diagnosticul tehnic a fost salvat.';
                    break;
                case 'status':
                    $changes = $this->workflow->updateFromPanel($id, ['status' => (string) $pending['status']], $actor['id'], false);
                    $done = 'Status salvat: ' . InterventionWorkflowModel::STATUS_LABELS[$pending['status']] . '.';
                    break;
                case 'item':
                    $this->workflow->saveItem($id, (int) $data['item_id'], (array) $pending['fields'], $actor['id'], $canCosts);
                    $changes = ['lucrare' => (int) $data['item_id']] + (array) $pending['fields'];
                    $done = 'Lucrarea a fost actualizată.';
                    break;
                case 'add':
                    $itemId = $this->workflow->saveItem($id, 0, (array) $pending['fields'], $actor['id'], $canCosts);
                    $data['item_id'] = $itemId;
                    $changes = ['lucrare_adaugata' => $itemId] + (array) $pending['fields'];
                    $done = 'Componenta a fost adăugată la lucrări.';
                    break;
                default:
                    return $this->workMenu($actor, $row);
            }
        } catch (InvalidArgumentException $exception) {
            // Regulile modelului (ex. componenta scoasa din registru): mesajul lui, fara a salva nimic.
            return $this->workMenu($actor, $row, '⚠️ Nu s-a salvat: ' . $exception->getMessage());
        }
        $this->workflow->logEvent($id, 'whatsapp', 'Modificare prin WhatsApp (' . $actor['nume'] . '): ' . mb_strimwidth(strip_tags(str_replace('*', '', (string) $pending['summary'])), 0, 300, '…', 'UTF-8'), ['sursa' => 'whatsapp', 'tip' => $pending['kind']], $actor['id']);
        audit_log_write($this->db, 'mentenanta_interventii', $id, 'update', 'Intervenție #' . $id . ' modificată prin WhatsApp', null, $changes, $actor['id']);

        if (($pending['kind'] ?? '') === 'responsible' && (int) $pending['user_id'] !== $actor['id']) {
            $this->clearSession($actor['phone']);
            $this->say($actor, '✅ ' . $done, 'saved');
            $this->notifyAssignments($id);
            return ['outcome' => 'saved', 'intervention_id' => $id];
        }
        $fresh = $this->interventionRow($id) ?? $row;
        if (in_array($pending['kind'] ?? '', ['item', 'add'], true)) {
            $item = $this->item($id, (int) $data['item_id']);
            if ($item !== null) {
                $data['version'] = self::version($fresh);
                $this->itemMenu($actor, $data, $item, '✅ ' . $done);
                return ['outcome' => 'saved', 'intervention_id' => $id];
            }
        }
        if (!in_array(InterventionWorkflowModel::statusKey((string) $fresh['status_interventie']), self::WORK_STATUSES, true)) {
            $this->clearSession($actor['phone']);
            $this->say($actor, '✅ ' . $done, 'saved');
            return ['outcome' => 'saved', 'intervention_id' => $id];
        }
        $this->workMenu($actor, $fresh, '✅ ' . $done);

        return ['outcome' => 'saved', 'intervention_id' => $id];
    }

    // ---------------------------------------------------------------- informatii componenta

    /**
     * Ce stie Fleet despre componenta pe vehicul: configurarea din Reparatii Auto, ultimele lucrari si
     * garantia. Uzura (%) nu se calculeaza aici: formula ramane in pagina Reparatii Auto.
     */
    public function componentInfo(string $componentKey, int $vehicleId): string
    {
        $stmt = $this->db->prepare('SELECT nume, cod FROM mentenanta_auto_componente WHERE component_key = :k');
        $stmt->execute([':k' => $componentKey]);
        $component = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['nume' => $componentKey, 'cod' => ''];
        $lines = ['*' . $component['nume'] . '*' . ($component['cod'] !== '' ? ' (' . $component['cod'] . ')' : '') . ' — ' . $this->vehicleLabel($vehicleId)];

        $config = null;
        try {
            $stmt = $this->db->prepare('SELECT * FROM mentenanta_auto_configurari WHERE vehicle_id = :v AND component_key = :k ORDER BY updated_at DESC LIMIT 1');
            $stmt->execute([':v' => $vehicleId, ':k' => $componentKey]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $exception) {
            $config = null; // tabela exista doar dupa prima configurare din Reparatii Auto
        }
        if ($config === null) {
            $lines[] = 'Configurare: *Neconfigurat* pe acest vehicul (Reparații Auto).';
        } else {
            $unit = (string) ($config['unit'] ?? '');
            $lines[] = 'Monitorizare: ' . $config['monitoring_type'] . ' · interval ' . $config['interval_value'] . ' ' . $unit
                . ' · avertizare ' . $config['warning_value'] . ' · critic ' . $config['critical_value'];
            if (!empty($config['stock_part_id'])) {
                $stmt = $this->db->prepare('SELECT data_montare, km_montare FROM mentenanta_piese_utilizari WHERE vehicle_id = :v AND part_id = :p ORDER BY data_montare DESC, id DESC LIMIT 1');
                $stmt->execute([':v' => $vehicleId, ':p' => (int) $config['stock_part_id']]);
                $mount = $stmt->fetch(PDO::FETCH_ASSOC);
                $lines[] = 'Ultima montare: ' . ($mount ? self::dateLabel((string) $mount['data_montare']) . ($mount['km_montare'] !== null ? ' la ' . number_format((int) $mount['km_montare'], 0, ',', '.') . ' km' : '') : 'nu este înregistrată');
            }
        }
        $lines[] = 'Uzură: se vede în Reparații Auto (nu se calculează pe WhatsApp).';

        $stmt = $this->db->prepare("
            SELECT l.operatiune, l.rezultat, l.data_montare, l.garantie_pana_la, i.id AS intervention_id, i.data_programata
            FROM mentenanta_interventii_lucrari l
            JOIN mentenanta_interventii_programate i ON i.id = l.intervention_id
            WHERE l.component_key = :k AND COALESCE(l.vehicle_id, i.vehicle_id) = :v AND l.rezultat IS NOT NULL
            ORDER BY i.data_programata DESC, l.id DESC LIMIT 5
        ");
        $stmt->execute([':k' => $componentKey, ':v' => $vehicleId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $lines[] = '';
        $lines[] = $history === [] ? 'Istoric intervenții: nicio lucrare finalizată.' : '*Istoric intervenții:*';
        $warranty = null;
        foreach ($history as $h) {
            $lines[] = '• #' . (int) $h['intervention_id'] . ' ' . self::dateLabel((string) $h['data_programata']) . ' — '
                . InterventionWorkflowModel::OPERATION_LABELS[$h['operatiune']] . ', ' . InterventionWorkflowModel::RESULT_LABELS[$h['rezultat']];
            if ($warranty === null && !empty($h['garantie_pana_la'])) {
                $warranty = (string) $h['garantie_pana_la'];
            }
        }
        if ($warranty !== null) {
            $lines[] = 'Garanție până la: ' . self::dateLabel($warranty) . ($warranty < date('Y-m-d') ? ' (expirată)' : '');
        }

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------- finalizare controlata

    private function finalizeStart(array $actor, array $row, array $data): array
    {
        $data['fin'] = ['outcomes' => [], 'queue' => []];
        foreach ($this->workflow->items((int) $row['id']) as $item) {
            if ($item['stare_evaluare'] !== 'exclusa') {
                $data['fin']['queue'][] = $item['id'];
            }
        }

        return $this->finalizeNext($actor, $row, $data);
    }

    /** Urmatoarea lucrare fara rezultat confirmat, apoi data, km, continuarea si confirmarea. */
    private function finalizeNext(array $actor, array $row, array $data): array
    {
        $queue = $data['fin']['queue'];
        if ($queue !== []) {
            $item = $this->item((int) $row['id'], (int) $queue[0]);
            if ($item === null) {
                array_shift($data['fin']['queue']);
                return $this->finalizeNext($actor, $row, $data);
            }
            $data['fin']['current'] = $item['id'];
            $done = count($data['fin']['outcomes']);
            $choices = [];
            foreach (InterventionWorkflowModel::RESULT_LABELS as $key => $title) {
                $choices[] = [$key, $title];
            }
            $choices[] = ['cancel', '❌ Renunț la finalizare'];
            $this->ask($actor, 'coord', 'fin_item', $data, '*Finalizare #' . (int) $row['id'] . '* — lucrarea ' . ($done + 1) . ' din ' . ($done + count($queue)) . ":\n"
                . self::itemLine($item) . "\nCare este rezultatul?", $choices);
            return ['outcome' => 'asked_result'];
        }
        if (!isset($data['fin']['date'])) {
            $this->ask($actor, 'coord', 'fin_date', $data, 'Data execuției? Scrieți data (de ex. *28.10*) sau alegeți:', [['today', '📅 Azi'], ['cancel', '❌ Renunț']]);
            return ['outcome' => 'asked_execution_date'];
        }
        if (!array_key_exists('km', $data['fin'])) {
            $this->ask($actor, 'coord', 'fin_km', $data, 'Km la bord la execuție? Scrieți numărul (de ex. *412500*) sau „-” dacă nu îl știți.');
            return ['outcome' => 'asked_km'];
        }
        $postponed = array_filter($data['fin']['outcomes'], static fn(array $o): bool => $o['rezultat'] === 'amanata');
        if ($postponed !== [] && !isset($data['fin']['follow'])) {
            $this->ask($actor, 'coord', 'fin_follow', $data, count($postponed) . ' lucrări sunt amânate. Creez o intervenție nouă (De verificat) pentru ele?', [['yes', '✅ Da'], ['no', '❌ Nu']]);
            return ['outcome' => 'asked_follow_up'];
        }
        $lines = ['*Confirmați finalizarea intervenției #' . (int) $row['id'] . '*', 'Data execuției: ' . self::dateLabel((string) $data['fin']['date'])
            . ($data['fin']['km'] !== null ? ' · ' . number_format((int) $data['fin']['km'], 0, ',', '.') . ' km' : '')];
        foreach ($data['fin']['outcomes'] as $itemId => $outcome) {
            $item = $this->item((int) $row['id'], (int) $itemId);
            $lines[] = '• ' . ($item !== null ? $item['nume'] : '#' . $itemId) . ': ' . InterventionWorkflowModel::RESULT_LABELS[$outcome['rezultat']]
                . (!empty($outcome['confirma_inlocuire']) ? ' — *înlocuire confirmată* (uzura se resetează)' : '');
        }
        if (!empty($data['fin']['follow'])) {
            $lines[] = 'Lucrările amânate trec într-o intervenție nouă.';
        }
        $lines[] = "\nDupă finalizare, intervenția nu se mai poate modifica de aici.";
        $this->ask($actor, 'coord', 'fin_confirm', $data, implode("\n", $lines), [['finish', '🏁 Finalizează'], ['cancel', '❌ Renunț']]);

        return ['outcome' => 'asked_finalize'];
    }

    private function finalizeStep(array $actor, array $row, array $data, string $step, ?string $choice, string $textValue): array
    {
        switch ($step) {
            case 'fin_item':
                if ($choice === null || !isset(InterventionWorkflowModel::RESULT_LABELS[$choice])) {
                    break;
                }
                $item = $this->item((int) $row['id'], (int) $data['fin']['current']);
                if ($item !== null && $item['operatiune'] === 'inlocuire' && $choice === 'executata' && $item['usage_id'] === null) {
                    // Inlocuirea reseteaza uzura: se confirma explicit, separat.
                    $this->ask($actor, 'coord', 'fin_replace', $data, '⚠️ *' . $item['nume'] . "*: confirmați că s-a montat o piesă nouă?\nUzura componentei se resetează de la data și km-ul execuției.",
                        [['yes', '✅ Confirm înlocuirea'], ['no', '↩️ Alt rezultat']]);
                    return ['outcome' => 'asked_replacement'];
                }
                $data['fin']['outcomes'][(int) $data['fin']['current']] = ['rezultat' => $choice];
                array_shift($data['fin']['queue']);
                return $this->finalizeNext($actor, $row, $data);
            case 'fin_replace':
                if ($choice === 'yes') {
                    $data['fin']['outcomes'][(int) $data['fin']['current']] = ['rezultat' => 'executata', 'confirma_inlocuire' => true];
                    array_shift($data['fin']['queue']);
                }
                if ($choice === 'yes' || $choice === 'no') {
                    return $this->finalizeNext($actor, $row, $data);
                }
                break;
            case 'fin_date':
                if ($choice === 'today') {
                    $data['fin']['date'] = date('Y-m-d');
                    return $this->finalizeNext($actor, $row, $data);
                }
                if ($textValue !== '') {
                    $date = self::parseExecutionDate($textValue);
                    if ($date === null) {
                        return $this->say($actor, 'Data nu este validă sau este în viitor. Scrieți de ex. *28.10*.', 'invalid_date');
                    }
                    $data['fin']['date'] = $date;
                    return $this->finalizeNext($actor, $row, $data);
                }
                break;
            case 'fin_km':
                if ($textValue === '') {
                    break;
                }
                $digits = preg_replace('/[\s.]/', '', $textValue);
                if (in_array($textValue, ['-', '—'], true)) {
                    $data['fin']['km'] = null;
                } elseif (preg_match('/^\d{1,7}$/', (string) $digits) === 1) {
                    $data['fin']['km'] = (int) $digits;
                } else {
                    return $this->say($actor, 'Scrieți doar numărul de km (de ex. *412500*) sau „-”.', 'invalid_km');
                }
                return $this->finalizeNext($actor, $row, $data);
            case 'fin_follow':
                if ($choice === 'yes' || $choice === 'no') {
                    $data['fin']['follow'] = $choice === 'yes';
                    return $this->finalizeNext($actor, $row, $data);
                }
                break;
            case 'fin_confirm':
                if ($choice !== 'finish') {
                    break;
                }
                return $this->finalize($actor, $row, $data);
        }

        return $this->say($actor, 'Vă rugăm să alegeți una dintre opțiuni (1–' . count($data['choices'] ?? []) . ') sau scrieți „anulează”.', 'unexpected_reply');
    }

    /** Data executiei: azi sau in trecut (maximum un an). */
    public static function parseExecutionDate(string $text, ?int $now = null): ?string
    {
        $now ??= time();
        $plain = self::plain($text);
        if (in_array($plain, ['azi', 'astazi'], true)) {
            return date('Y-m-d', $now);
        }
        if ($plain === 'ieri') {
            return date('Y-m-d', strtotime('-1 day', $now));
        }
        if (preg_match('/^(\d{1,2})[.\/\- ](\d{1,2})(?:[.\/\- ](\d{2}|\d{4}))?$/', $plain, $m) !== 1) {
            return null;
        }
        $year = isset($m[3]) ? (int) (strlen($m[3]) === 2 ? '20' . $m[3] : $m[3]) : (int) date('Y', $now);
        if (!checkdate((int) $m[2], (int) $m[1], $year)) {
            return null;
        }
        $date = mktime(0, 0, 0, (int) $m[2], (int) $m[1], $year);
        // "28.12" scris in ianuarie = anul trecut; o zi din urmatoarele 30 e doar o data gresita (viitor).
        if (!isset($m[3]) && $date > strtotime('+30 days', $now)) {
            $date = mktime(0, 0, 0, (int) $m[2], (int) $m[1], $year - 1);
        }
        if ($date > $now || $date < strtotime('-1 year', $now)) {
            return null;
        }

        return date('Y-m-d', $date);
    }

    private function finalize(array $actor, array $row, array $data): array
    {
        $id = (int) $row['id'];
        require_once __DIR__ . '/../models/MaintenanceModel.php';
        try {
            $result = $this->workflow->finalize($id, $data['fin']['outcomes'], [
                'data_executie' => (string) $data['fin']['date'],
                'km' => $data['fin']['km'] !== null ? (string) $data['fin']['km'] : '',
                'creeaza_continuare' => !empty($data['fin']['follow']),
                'data_continuare' => '',
            ], $actor['id'], $this->canCosts($actor), new MaintenanceModel($this->db));
        } catch (InvalidArgumentException $exception) {
            $this->clearSession($actor['phone']);
            return $this->say($actor, '⚠️ Intervenția nu a fost finalizată: ' . $exception->getMessage(), 'finalize_refused');
        }
        if (!$result['already']) {
            $this->workflow->logEvent($id, 'whatsapp', 'Finalizată prin WhatsApp de ' . $actor['nume'], ['sursa' => 'whatsapp'], $actor['id']);
            audit_log_write($this->db, 'mentenanta_interventii', $id, 'finalize', 'Intervenție #' . $id . ' finalizată prin WhatsApp', null, $result, $actor['id']);
        }
        $this->clearSession($actor['phone']);
        $this->say($actor, '🏁 Intervenția #' . $id . ' a fost finalizată.'
            . ($result['montari'] > 0 ? "\nÎnlocuiri trecute în istoricul componentelor: " . $result['montari'] . '.' : '')
            . (!empty($result['follow_up_id']) ? "\nLucrările amânate: intervenția nouă #" . (int) $result['follow_up_id'] . ' (De verificat).' : ''), 'finalized');
        $this->notifyDriver($id);

        return ['outcome' => 'finalized', 'intervention_id' => $id] + $result;
    }

    // ---------------------------------------------------------------- notificarea responsabilului

    /**
     * Responsabilul nou (din WhatsApp sau din pagina Interventii) primeste cardul interventiei,
     * o singura data pentru fiecare pereche interventie + responsabil. Doar operatorii din lista.
     */
    public function notifyAssignments(?int $onlyId = null, int $lookbackDays = 3): int
    {
        if (!$this->config['enabled'] || $this->reviewers() === []) {
            return 0;
        }
        $ids = array_column($this->reviewers(), 'id');
        $sql = "
            SELECT i.id, i.responsabil_user_id
            FROM mentenanta_interventii_programate i
            LEFT JOIN whatsapp_maintenance_assignments a ON a.intervention_id = i.id AND a.user_id = i.responsabil_user_id
            WHERE a.intervention_id IS NULL
              AND i.responsabil_user_id IN (" . implode(',', array_map('intval', $ids)) . ")
              AND i.status_interventie IN ('programata', 'confirmata', 'in_lucru', 'in_asteptare')
              AND i.updated_at >= :since" . ($onlyId !== null ? ' AND i.id = :id' : '') . '
            ORDER BY i.id ASC LIMIT 20';
        $stmt = $this->db->prepare($sql);
        $params = [':since' => date('Y-m-d H:i:s', strtotime('-' . max(1, $lookbackDays) . ' days'))];
        if ($onlyId !== null) {
            $params[':id'] = $onlyId;
        }
        $stmt->execute($params);
        $sent = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $reviewer = $this->reviewerById((int) $row['responsabil_user_id']);
            if ($reviewer === null) {
                continue;
            }
            $insert = $this->db->prepare('INSERT IGNORE INTO whatsapp_maintenance_assignments (intervention_id, user_id, created_at) VALUES (:i, :u, :now)');
            $insert->execute([':i' => (int) $row['id'], ':u' => $reviewer['id'], ':now' => date('Y-m-d H:i:s')]);
            if ($insert->rowCount() === 0) {
                continue;
            }
            $to = ['role' => 'user', 'id' => $reviewer['id'], 'nume' => $reviewer['nume'], 'phone' => $reviewer['phone']];
            $this->sendCoordCard($to, (int) $row['id'], 'mnt:assign:' . (int) $row['id'] . ':' . $reviewer['id'], '👤 *Ați fost desemnat responsabil tehnic.*');
            $sent++;
        }

        return $sent;
    }

    // -------------------------------------------------------------------------
    // Notificarile catre sofer (din WhatsApp sau din pagina Interventii)
    // -------------------------------------------------------------------------

    private function requestDriverId(int $id): ?int
    {
        $stmt = $this->db->prepare('SELECT driver_id FROM whatsapp_maintenance_requests WHERE intervention_id = :i');
        $stmt->execute([':i' => $id]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** Ce a aflat deja soferul: status + data + ora. */
    public static function notifyState(array $row): string
    {
        return InterventionWorkflowModel::statusKey((string) $row['status_interventie']) . '|' . (string) $row['data_programata']
            . '|' . ($row['ora_programata'] !== null && $row['ora_programata'] !== '' ? substr((string) $row['ora_programata'], 0, 5) : '');
    }

    public static function driverMessage(array $row, string $previousState, ?string $reason = null): ?string
    {
        $id = (int) $row['id'];
        $statusKey = InterventionWorkflowModel::statusKey((string) $row['status_interventie']);
        [$previousStatus] = explode('|', $previousState . '|');
        $when = self::dateLabel((string) $row['data_programata'], $row['ora_programata'] ?? null);
        $vehicle = (string) ($row['nr_inmatriculare'] ?? '');

        if ($statusKey !== $previousStatus) {
            switch ($statusKey) {
                case 'programata':
                    return '✅ *Cererea de service #' . $id . ' a fost aprobată.*' . "\nVehicul: " . $vehicle . "\nProgramare: " . $when
                        . (!empty($row['furnizor']) ? "\nService: " . $row['furnizor'] : '');
                case 'anulata':
                    return '❌ *Cererea de service #' . $id . ' a fost respinsă.*' . ($reason !== null && $reason !== '' ? "\nMotiv: " . $reason : '')
                        . "\nPentru detalii, contactați dispeceratul.";
                case 'in_service':
                    return '🔧 Vehiculul ' . $vehicle . ' a intrat în service (intervenția #' . $id . ').';
                case 'in_asteptare':
                    return '⏳ Intervenția #' . $id . ' pentru ' . $vehicle . ' este în așteptare.';
                case 'finalizata':
                    return '🏁 Intervenția #' . $id . ' pentru ' . $vehicle . ' a fost finalizată.';
                case 'de_verificat':
                    return 'ℹ️ Cererea de service #' . $id . ' este din nou de verificat. Data propusă: ' . $when . '.';
            }
            return null;
        }
        if (in_array($statusKey, ['de_verificat', 'programata'], true)) {
            return '📅 ' . ($statusKey === 'programata' ? 'Programarea' : 'Data cererii') . ' #' . $id . ' pentru ' . $vehicle . ' a fost modificată: *' . $when . '*.';
        }

        return null;
    }

    /** Trimite soferului schimbarea (status / data), o singura data pentru fiecare stare. */
    public function notifyDriver(int $id, ?string $reason = null): bool
    {
        $stmt = $this->db->prepare('
            SELECT r.driver_id, r.notified_state, i.id, i.status_interventie, i.data_programata, i.ora_programata, i.furnizor, v.nr_inmatriculare
            FROM whatsapp_maintenance_requests r
            JOIN mentenanta_interventii_programate i ON i.id = r.intervention_id
            JOIN vehicule v ON v.id = i.vehicle_id
            WHERE r.intervention_id = :i
        ');
        $stmt->execute([':i' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && $this->notifyRow($row, $reason);
    }

    private function notifyRow(array $row, ?string $reason): bool
    {
        $state = self::notifyState($row);
        if ($state === (string) $row['notified_state']) {
            return false;
        }
        $message = self::driverMessage($row, (string) $row['notified_state'], $reason);
        $this->db->prepare('UPDATE whatsapp_maintenance_requests SET notified_state = :s, updated_at = :now WHERE intervention_id = :i')
            ->execute([':s' => $state, ':now' => date('Y-m-d H:i:s'), ':i' => (int) $row['id']]);
        if ($message === null) {
            return false;
        }
        $driver = $this->outbox->getActiveDriver((int) $row['driver_id']);
        if ($driver === null || ($this->config['driver_test_mode'] && !in_array($driver['id'], $this->config['driver_ids'], true))) {
            return false;
        }
        $this->send(['role' => 'driver', 'id' => $driver['id']], 'maintenance_status', $message, [], 'mnt:notify:' . (int) $row['id'] . ':' . $state, (int) $row['id']);

        return true;
    }

    /** La fiecare citire a outbox-ului: schimbarile facute din aplicatie ajung si la sofer. */
    public function syncDriverNotifications(int $limit = 50): int
    {
        if (!$this->config['enabled']) {
            return 0;
        }
        $stmt = $this->db->prepare('
            SELECT r.driver_id, r.notified_state, i.id, i.status_interventie, i.data_programata, i.ora_programata, i.furnizor, v.nr_inmatriculare
            FROM whatsapp_maintenance_requests r
            JOIN mentenanta_interventii_programate i ON i.id = r.intervention_id
            JOIN vehicule v ON v.id = i.vehicle_id
            WHERE r.created_at >= :since
              AND COALESCE(r.notified_state, \'\') <> CONCAT(
                    CASE i.status_interventie WHEN \'confirmata\' THEN \'programata\' WHEN \'in_lucru\' THEN \'in_service\' ELSE i.status_interventie END,
                    \'|\', i.data_programata, \'|\', COALESCE(LEFT(i.ora_programata, 5), \'\'))
            ORDER BY r.intervention_id ASC
            LIMIT ' . max(1, $limit)
        );
        $stmt->execute([':since' => date('Y-m-d H:i:s', strtotime('-' . self::NOTIFY_WINDOW_DAYS . ' days'))]);
        $sent = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if ($this->notifyRow($row, null)) {
                $sent++;
            }
        }

        return $sent;
    }
}
