<?php
declare(strict_types=1);

/*
 * Contextul cererii pentru jurnalele de audit (IP + dispozitiv) si evenimentele
 * de autentificare. Folosit de pagina "Rapoarte activitate utilizatori".
 *
 * Coloanele ip_address / user_agent din audit_log, cursa_audit_log si
 * login_email_codes sunt adaugate de ActivityReportModel::ensureSchema() (la
 * deschiderea raportului, in afara oricarei tranzactii) si de migrarea
 * 2026_10_15_000001_rapoarte_activitate.sql. Aici NU se face DDL: un ALTER in
 * mijlocul unei tranzactii ar face commit implicit. Daca o coloana lipseste,
 * stampilarea este sarita in tacere — jurnalul original ramane neatins.
 */

/** IP-ul clientului (REMOTE_ADDR; X-Forwarded-For doar in spatele unui proxy local). */
function audit_request_ip(): ?string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '') {
        return null;
    }

    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $isLocalProxy = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($forwarded !== '' && $isLocalProxy) {
        $first = trim(explode(',', $forwarded)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
            return $first;
        }
    }

    return mb_substr($remote, 0, 45);
}

function audit_request_user_agent(): ?string
{
    $agent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    return $agent !== '' ? mb_substr($agent, 0, 255) : null;
}

/** "Chrome / Windows" din user agent (afisare compacta in raport). */
function audit_device_label(?string $userAgent): string
{
    $ua = (string) $userAgent;
    if ($ua === '') {
        return '';
    }

    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        str_contains($ua, 'curl/') => 'curl',
        default => 'Alt browser',
    };
    $os = match (true) {
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Mac OS') => 'macOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => '',
    };

    return $os !== '' ? $browser . ' / ' . $os : $browser;
}

/** Are tabela coloanele de context? (cache pe cerere) */
function audit_context_columns_available(PDO $db, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME IN ('ip_address', 'user_agent')
        ");
        $stmt->execute([':t' => $table]);
        $cache[$table] = (int) $stmt->fetchColumn() === 2;
    } catch (Throwable $exception) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

/**
 * Completeaza IP-ul si dispozitivul pe randul de jurnal tocmai inserat.
 * Nu arunca niciodata: auditul principal nu trebuie sa cada din cauza contextului.
 */
function audit_stamp_request_context(PDO $db, string $table, int $id): void
{
    if ($id <= 0 || PHP_SAPI === 'cli' || !in_array($table, ['audit_log', 'cursa_audit_log', 'login_email_codes'], true)) {
        return;
    }
    if (!audit_context_columns_available($db, $table)) {
        return;
    }

    try {
        $stmt = $db->prepare('UPDATE ' . $table . ' SET ip_address = :ip, user_agent = :ua WHERE id = :id');
        $stmt->execute([':ip' => audit_request_ip(), ':ua' => audit_request_user_agent(), ':id' => $id]);
    } catch (Throwable $exception) {
        error_log('[audit_context] stamp ' . $table . ': ' . $exception->getMessage());
    }
}

/** Cheile care nu se afiseaza niciodata in jurnal (parole, coduri, token-uri). */
function audit_is_secret_field(string $field): bool
{
    return (bool) preg_match('/(parola|password|passwd|secret|token|api_?key|code_hash|cod_verificare|otp|credential)/i', $field);
}

/** Inlocuieste valorile secrete dintr-un instantaneu inainte de a-l scrie in jurnal. */
function audit_redact_snapshot(?array $snapshot): ?array
{
    if ($snapshot === null) {
        return null;
    }
    $clean = [];
    foreach ($snapshot as $key => $value) {
        if (audit_is_secret_field((string) $key)) {
            // Amprenta scurta (a hash-ului, nu a parolei) lasa raportul sa vada CA s-a
            // schimbat valoarea, fara sa poata fi recuperata.
            $clean[$key] = ($value === null || $value === '') ? null : '[ascuns#' . substr(hash('sha256', (string) $value), 0, 10) . ']';
            continue;
        }
        $clean[$key] = is_array($value) ? audit_redact_snapshot($value) : $value;
    }

    return $clean;
}

/**
 * Scrie un eveniment in audit_log (tabela generica before/after), cu contextul cererii.
 * Folosit de modulele care nu aveau inca jurnal (Vehicule, Soferi, Utilizatori).
 */
function audit_log_write(PDO $db, string $module, int $recordId, string $action, string $description, ?array $before, ?array $after, ?int $userId): void
{
    try {
        $stmt = $db->prepare('
            INSERT INTO audit_log (modul, record_id, actiune, descriere, before_data, after_data, user_id, created_at)
            VALUES (:modul, :record_id, :actiune, :descriere, :before_data, :after_data, :user_id, :created_at)
        ');
        $encode = static fn (?array $data): ?string => $data === null
            ? null
            : (json_encode(audit_redact_snapshot($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null);
        $stmt->execute([
            ':modul'       => $module,
            ':record_id'   => max(0, $recordId),
            ':actiune'     => $action,
            ':descriere'   => mb_substr($description, 0, 255),
            ':before_data' => $encode($before),
            ':after_data'  => $encode($after),
            ':user_id'     => $userId !== null && $userId > 0 ? $userId : null,
            ':created_at'  => date('Y-m-d H:i:s'),
        ]);
        audit_stamp_request_context($db, 'audit_log', (int) $db->lastInsertId());
    } catch (Throwable $exception) {
        error_log('[audit_context] audit_log_write ' . $module . ': ' . $exception->getMessage());
    }
}

/** Creeaza tabela evenimentelor de autentificare (doar in afara unei tranzactii). */
function audit_ensure_login_events_table(PDO $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if ($db->inTransaction()) {
        return false;
    }

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS user_login_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                email VARCHAR(190) NULL,
                metoda VARCHAR(30) NOT NULL,
                reusit TINYINT(1) NOT NULL DEFAULT 1,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_login_events_created (created_at),
                INDEX idx_login_events_user (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ready = true;
    } catch (Throwable $exception) {
        error_log('[audit_context] user_login_events: ' . $exception->getMessage());
        $ready = false;
    }

    return $ready;
}

/**
 * Inregistreaza o autentificare (reusita sau esuata). Metoda: cod_email | passkey | parola.
 * Nu se salveaza niciodata codul sau parola introdusa.
 */
function audit_record_login(PDO $db, ?int $userId, ?string $email, string $method, bool $success): void
{
    if (PHP_SAPI === 'cli' || !audit_ensure_login_events_table($db)) {
        return;
    }

    try {
        $stmt = $db->prepare('
            INSERT INTO user_login_events (user_id, email, metoda, reusit, ip_address, user_agent, created_at)
            VALUES (:user_id, :email, :metoda, :reusit, :ip, :ua, :created_at)
        ');
        $stmt->execute([
            ':user_id'    => $userId !== null && $userId > 0 ? $userId : null,
            ':email'      => $email !== null ? mb_substr($email, 0, 190) : null,
            ':metoda'     => mb_substr($method, 0, 30),
            ':reusit'     => $success ? 1 : 0,
            ':ip'         => audit_request_ip(),
            ':ua'         => audit_request_user_agent(),
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $exception) {
        error_log('[audit_context] audit_record_login: ' . $exception->getMessage());
    }
}
