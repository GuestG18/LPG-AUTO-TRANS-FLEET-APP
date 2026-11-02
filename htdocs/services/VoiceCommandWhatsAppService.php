<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/DriverWhatsAppModel.php';
require_once __DIR__ . '/../models/UserModel.php';
require_once __DIR__ . '/../includes/access.php';
require_once __DIR__ . '/../includes/audit_context.php';

/**
 * Comenzi vocale pe WhatsApp (test): "talon 435" -> documentul ITP al masinii B 435 NET, ca fisier.
 *
 * Botul transcrie mesajul vocal local (whisper.cpp) si trimite textul in v1/inbound ("transcript").
 * Aici se decide tot: cine are voie (VOICE_COMMANDS_USER_IDS + dreptul de a vedea Documente vehicule),
 * ce document se cere si ce se trimite. Fisierul pleaca prin coada obisnuita (scop "vehicle_document");
 * botul il descarca prin v1/outbox/file doar cat timp detine lock-ul randului.
 *
 * Oprit implicit (VOICE_COMMANDS_ENABLED=0). Se elimina stergand acest fisier si cele doua blocuri
 * marcate "Comenzi vocale" din WhatsAppBotApiController.
 */
class VoiceCommandWhatsAppService
{
    public const OUTBOX_PURPOSE = 'vehicle_document';
    private const MAX_FILE_BYTES = 15 * 1024 * 1024;

    /** Cuvantul spus (fara diacritice) => tipurile din documente.tip_document (comparate fara majuscule). */
    public const DOCUMENT_WORDS = [
        'talon' => ['ITP'], 'taloane' => ['ITP'], 'itp' => ['ITP'], 'inspectie' => ['ITP'],
        'rca' => ['RCA'], 'casco' => ['CASCO'],
        'rovinieta' => ['Rovinieta'], 'rovigneta' => ['Rovinieta'], 'vinieta' => ['Rovinieta'],
        'carte' => ['Carte'], 'cartea' => ['Carte'], 'civ' => ['Carte'],
        'copie conforma' => ['Copie conforma'], 'copia conforma' => ['Copie conforma'], 'licenta' => ['Copie conforma'],
        'tahograf' => ['Tahograf'], 'adr' => ['ADR'],
        'metrologie' => ['METROLOGIE'], 'brml' => ['METROLOGIE'], 'iprochim' => ['IPROCHIM'],
        'organism notificat' => ['ORGANISM NOTIFICAT', 'Tuv'], 'tuv' => ['ORGANISM NOTIFICAT', 'Tuv'],
    ];

    /** Eticheta din mesaj (talonul e documentul de tip ITP). */
    private const LABELS = ['itp' => 'Talon (ITP)', 'carte' => 'Cartea de identitate', 'copie conforma' => 'Copie conformă', 'rovinieta' => 'Rovinietă'];

    private const UNITS = ['zero' => 0, 'unu' => 1, 'una' => 1, 'un' => 1, 'o' => 1, 'doi' => 2, 'doua' => 2, 'trei' => 3, 'patru' => 4,
        'cinci' => 5, 'sase' => 6, 'sapte' => 7, 'opt' => 8, 'noua' => 9];
    private const TEENS = ['zece' => 10, 'unsprezece' => 11, 'doisprezece' => 12, 'douasprezece' => 12, 'treisprezece' => 13,
        'paisprezece' => 14, 'patrusprezece' => 14, 'cincisprezece' => 15, 'saisprezece' => 16, 'saptesprezece' => 17,
        'optsprezece' => 18, 'nouasprezece' => 19];
    private const TENS = ['douazeci' => 20, 'treizeci' => 30, 'patruzeci' => 40, 'cincizeci' => 50, 'saizeci' => 60,
        'saptezeci' => 70, 'optzeci' => 80, 'nouazeci' => 90];

    private array $config;
    private DriverWhatsAppModel $outbox;
    private ?array $usersCache = null;

    public function __construct(private PDO $db, ?DriverWhatsAppModel $outbox = null, ?array $config = null)
    {
        $this->outbox = $outbox ?? new DriverWhatsAppModel($db);
        $this->config = $config ?? self::config();
    }

    public static function config(): array
    {
        $env = static fn(string $name, string $default = ''): string => is_string($v = getenv($name)) && trim($v) !== '' ? trim($v) : $default;

        return [
            'enabled' => in_array(strtolower($env('VOICE_COMMANDS_ENABLED', '0')), ['1', 'true', 'yes', 'on', 'da'], true),
            'user_ids' => array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $env('VOICE_COMMANDS_USER_IDS')) ?: []))),
        ];
    }

    // -------------------------------------------------------------------------
    // Intelegerea textului
    // -------------------------------------------------------------------------

    /** Litere mici, fara diacritice si punctuatie. */
    public static function plain(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * "talon 435", "Talonul pentru 435.", "RCA-ul de la patru sute treizeci și cinci", "rovinieta B 435 NET".
     *
     * @return array{word:string, types:string[], number:string, plate:?string}|null
     */
    public static function parse(string $transcript): ?array
    {
        $plain = ' ' . self::plain($transcript) . ' ';
        $found = null;
        foreach (self::DOCUMENT_WORDS as $word => $types) {
            // "talonul", "rca ul", "rovinieta": cuvantul la inceputul unui cuvant spus.
            if (preg_match('/ ' . preg_quote($word, '/') . '(?:ul|ului|a|ua)?(?= )/', $plain, $m, PREG_OFFSET_CAPTURE) === 1
                && ($found === null || $m[0][1] < $found['pos'])) {
                $found = ['word' => $word, 'types' => $types, 'pos' => $m[0][1]];
            }
        }
        if ($found === null) {
            return null;
        }
        $rest = trim(substr($plain, $found['pos'] + strlen($found['word']) + 1));

        // Numarul complet spus ("B 435 NET", "b435net"): judetul, cifrele, literele.
        $plate = null;
        if (preg_match('/\b([a-z]{1,2}) ?(\d{2,3}) ?([a-z]{3})\b/', $rest, $p) === 1) {
            $plate = strtoupper($p[1] . $p[2] . $p[3]);
        }
        $number = self::number($rest);
        if ($number === null) {
            return null;
        }

        return ['word' => $found['word'], 'types' => $found['types'], 'number' => $number, 'plate' => $plate];
    }

    /** Cifrele ("435", "4 3 5") sau numarul spus in cuvinte ("patru sute treizeci si cinci", "patru trei cinci"). */
    public static function number(string $plain): ?string
    {
        if (preg_match('/\d+(?: \d+)*/', $plain, $m) === 1) {
            return str_replace(' ', '', $m[0]);
        }
        $value = 0;
        $digits = '';
        $onlyUnits = true;
        $seen = false;
        $tokens = explode(' ', $plain);
        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? '';
            if ($token === 'si' && $seen) {
                continue;
            }
            if (isset(self::UNITS[$token]) && in_array($next, ['suta', 'sute'], true)) {
                $value += self::UNITS[$token] * 100;
                $onlyUnits = false;
            } elseif (in_array($token, ['suta', 'sute'], true)) {
                if (!$seen) {
                    $value += 100; // "suta" singur
                }
                $onlyUnits = false;
            } elseif (isset(self::TENS[$token])) {
                $value += self::TENS[$token];
                $onlyUnits = false;
            } elseif (isset(self::TEENS[$token])) {
                $value += self::TEENS[$token];
                $onlyUnits = false;
            } elseif (isset(self::UNITS[$token]) && !($token === 'o' && !$seen)) {
                $value += self::UNITS[$token];
                $digits .= (string) self::UNITS[$token];
            } elseif ($seen) {
                break; // numarul s-a terminat
            } else {
                continue; // cuvinte de legatura inainte de numar ("pentru", "de la", "masina")
            }
            $seen = true;
        }
        if (!$seen) {
            return null;
        }

        // "patru trei cinci" = 435 (cifra cu cifra), "patru sute treizeci si cinci" = 435.
        return $onlyUnits && strlen($digits) > 1 ? $digits : (string) $value;
    }

    // -------------------------------------------------------------------------
    // Mesaj primit
    // -------------------------------------------------------------------------

    /** Mesajul e o comanda vocala pentru acest serviciu? Fara efecte. */
    public function claims(array $message): bool
    {
        if (!$this->config['enabled'] || ($message['kind'] ?? '') !== 'media' || ($message['media_type'] ?? null) !== 'audio') {
            return false;
        }
        if (trim((string) ($message['transcript'] ?? '')) === '') {
            return false;
        }
        $phone = (string) ($message['phone'] ?? '');

        // In pasul "inregistreaza mesaj" al conducerii, mesajul vocal e anuntul, nu o comanda.
        return $this->userByPhone($phone) !== null && !$this->awaitsAnnouncement($phone);
    }

    /** @return array{handled: bool, outcome: string, discard_audio?: bool} */
    public function handleInbound(array $message): array
    {
        $waMessageId = trim((string) ($message['wa_message_id'] ?? ''));
        $phone = (string) ($message['phone'] ?? '');
        $user = $this->userByPhone($phone);
        if ($waMessageId === '' || $user === null) {
            return ['handled' => false, 'outcome' => 'unknown_sender'];
        }
        // Textul nu se pastreaza in jurnalul mesajelor primite: doar rezultatul.
        $inboundId = $this->outbox->logInbound($waMessageId, null, 'media', null, null, date('Y-m-d H:i:s'));
        if ($inboundId === null) {
            return ['handled' => true, 'outcome' => 'duplicate', 'discard_audio' => true];
        }
        $transcript = mb_substr(trim((string) $message['transcript']), 0, 200);
        $outcome = $this->answer($user, $transcript, $waMessageId);
        $this->outbox->setInboundOutcome($inboundId, 'voice_cmd_' . $outcome);

        return ['handled' => true, 'outcome' => 'voice_cmd_' . $outcome, 'discard_audio' => true];
    }

    private function answer(array $user, string $transcript, string $waMessageId): string
    {
        $heard = '🎙️ Am înțeles: „' . $transcript . '”';
        $command = self::parse($transcript);
        if ($command === null) {
            $this->reply($user, $waMessageId, $heard . "\nNu am recunoscut comanda. Spuneți documentul și numărul mașinii, de exemplu: *talon 435*.");
            return 'not_understood';
        }

        $vehicles = $this->vehiclesByNumber($command['number'], $command['plate']);
        if ($vehicles === []) {
            $this->reply($user, $waMessageId, $heard . "\nNu am găsit nicio mașină cu numărul *" . $command['number'] . '*.');
            return 'vehicle_not_found';
        }
        if (count($vehicles) > 1) {
            $plates = implode("\n", array_map(static fn(array $v): string => '• ' . $v['nr_inmatriculare'], $vehicles));
            $this->reply($user, $waMessageId, $heard . "\nAm găsit mai multe mașini cu numărul " . $command['number'] . ":\n" . $plates
                . "\nSpuneți numărul complet, de exemplu: *talon B " . $command['number'] . ' NET*.');
            return 'vehicle_ambiguous';
        }
        $vehicle = $vehicles[0];

        $document = $this->latestDocument((int) $vehicle['id'], $command['types']);
        $label = self::LABELS[strtolower($command['types'][0])] ?? $command['types'][0];
        if ($document === null || $this->documentPath($document) === null) {
            $this->reply($user, $waMessageId, $heard . "\n" . $vehicle['nr_inmatriculare'] . ' nu are *' . $label . '* încărcat în aplicație.');
            return 'document_missing';
        }

        $caption = $heard . "\n📄 *" . $label . '* — ' . $vehicle['nr_inmatriculare'];
        if (!empty($document['data_expirare'])) {
            $expires = strtotime((string) $document['data_expirare']);
            $caption .= "\n" . ($expires < strtotime('today') ? '⚠️ Expirat la ' : 'Valabil până la ') . date('d.m.Y', $expires);
        }
        $this->outbox->enqueueForUser(self::OUTBOX_PURPOSE, $user['id'], $caption, [], 'voicecmd:' . $waMessageId . ':doc', self::OUTBOX_PURPOSE, (int) $document['id']);
        audit_log_write($this->db, 'documente', (int) $document['id'], 'view',
            'Document ' . $document['tip_document'] . ' ' . $vehicle['nr_inmatriculare'] . ' trimis pe WhatsApp (comandă vocală, ' . $user['nume'] . ')',
            null, null, $user['id']);

        return 'document_sent';
    }

    private function reply(array $user, string $waMessageId, string $body): void
    {
        $this->outbox->enqueueForUser('voice_command', $user['id'], mb_substr($body, 0, 1000), [], 'voicecmd:' . $waMessageId . ':reply');
    }

    // -------------------------------------------------------------------------
    // Fisierul pentru bot (v1/outbox/file)
    // -------------------------------------------------------------------------

    /**
     * Continutul documentului unui rand din coada, doar pentru detinatorul lock-ului (status "sending").
     *
     * @return array{filename:string, mimetype:string, data:string}|null
     */
    public function outboxFile(int $outboxId, string $lockToken): ?array
    {
        if (!$this->config['enabled'] || $outboxId <= 0 || $lockToken === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT related_id FROM whatsapp_outbox
            WHERE id = :id AND lock_token = :token AND status = 'sending' AND purpose = :purpose AND related_type = :purpose2");
        $stmt->execute([':id' => $outboxId, ':token' => $lockToken, ':purpose' => self::OUTBOX_PURPOSE, ':purpose2' => self::OUTBOX_PURPOSE]);
        $documentId = $stmt->fetchColumn();
        if ($documentId === false) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT d.*, v.nr_inmatriculare FROM documente d JOIN vehicule v ON v.id = d.vehicle_id WHERE d.id = :id');
        $stmt->execute([':id' => (int) $documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        $path = $document !== false ? $this->documentPath($document) : null;
        if ($path === null) {
            return null;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$extension]
            ?? 'application/octet-stream';
        $name = preg_replace('/[^A-Za-z0-9]+/', '_', $document['nr_inmatriculare'] . ' ' . $document['tip_document']) . ($extension !== '' ? '.' . $extension : '');

        return ['filename' => trim((string) $name, '_'), 'mimetype' => $mime, 'data' => base64_encode((string) file_get_contents($path))];
    }

    // -------------------------------------------------------------------------
    // Date
    // -------------------------------------------------------------------------

    /** Utilizatorul din VOICE_COMMANDS_USER_IDS cu acest telefon, activ si cu drept de a vedea Documente vehicule. */
    public function userByPhone(string $phone): ?array
    {
        if ($this->usersCache === null) {
            $this->usersCache = [];
            $ids = $this->config['user_ids'];
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $this->db->prepare("SELECT id, telefon FROM utilizatori WHERE id IN ($placeholders) ORDER BY id");
                $stmt->execute($ids);
                $users = new UserModel($this->db);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $userPhone = NotificationRuleModel::normalizePhone((string) ($row['telefon'] ?? ''));
                    $user = $users->findAuthUserById((int) $row['id']);
                    if ($userPhone !== null && $user !== null && user_can($user, 'documente', 'view')) {
                        $this->usersCache[] = ['id' => (int) $user['id'], 'nume' => (string) $user['nume'], 'phone' => $userPhone];
                    }
                }
            }
        }
        $matches = array_values(array_filter($this->usersCache, static fn(array $u): bool => $u['phone'] === $phone));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function awaitsAnnouncement(string $phone): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT 1 FROM whatsapp_maintenance_sessions WHERE phone = :p AND flow = 'voice' AND step = 'record' AND expires_at >= NOW()");
            $stmt->execute([':p' => $phone]);

            return $stmt->fetchColumn() !== false;
        } catch (PDOException) {
            return false; // tabela nu exista inca: nu exista nici conversatie
        }
    }

    /** Masinile al caror numar de inmatriculare are aceste cifre ("435" -> B 435 NET); numarul complet decide. */
    private function vehiclesByNumber(string $number, ?string $plate): array
    {
        $rows = $this->db->query("SELECT id, nr_inmatriculare, status FROM vehicule ORDER BY status = 'activ' DESC, nr_inmatriculare")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $compact = static fn(string $p): string => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $p) ?? '');
        if ($plate !== null) {
            $exact = array_values(array_filter($rows, static fn(array $v): bool => $compact((string) $v['nr_inmatriculare']) === $plate));
            if ($exact !== []) {
                return $exact;
            }
        }
        $matches = array_values(array_filter($rows, static function (array $v) use ($number): bool {
            preg_match_all('/\d+/', (string) $v['nr_inmatriculare'], $m);
            return in_array(ltrim($number, '0') ?: '0', array_map(static fn(string $d): string => ltrim($d, '0') ?: '0', $m[0]), true);
        }));
        // O masina activa are prioritate fata de una inactiva cu acelasi numar.
        $active = array_values(array_filter($matches, static fn(array $v): bool => $v['status'] === 'activ'));

        return $active !== [] ? $active : $matches;
    }

    /** Cel mai nou document de acest tip care are fisier. */
    private function latestDocument(int $vehicleId, array $types): ?array
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->db->prepare("SELECT * FROM documente
            WHERE vehicle_id = ? AND LOWER(tip_document) IN ($placeholders) AND fisier_stocat IS NOT NULL AND fisier_stocat <> ''
            ORDER BY data_expirare IS NULL, data_expirare DESC, id DESC LIMIT 1");
        $stmt->execute(array_merge([$vehicleId], array_map('strtolower', $types)));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function documentPath(array $document): ?string
    {
        $stored = basename((string) ($document['fisier_stocat'] ?? ''));
        if ($stored === '' || $stored === '.' || $stored === '..') {
            return null;
        }
        $path = BASE_PATH . '/uploads/documente/' . $stored;

        return is_file($path) && filesize($path) <= self::MAX_FILE_BYTES ? $path : null;
    }
}
