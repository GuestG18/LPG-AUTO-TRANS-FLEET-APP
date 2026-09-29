<?php
declare(strict_types=1);

/**
 * Link-uri temporare de descarcare pentru Fleet Assistant (Twilio descarca fisierul).
 *
 * Tokenul e aleator (32 octeti), iar in BD se pastreaza doar hash-ul SHA-256:
 * cine citeste tabela nu poate reconstrui link-ul. Tokenul e legat de un singur
 * document si de utilizatorul care l-a cerut; nu contine nicio cale de fisier.
 * Migrarea oficiala: database/migrations/2026_09_29_000001_assistant_download_tokens.sql.
 */
class AssistantDownloadTokenModel extends BaseModel
{
    public const TTL_SECONDS = 300;

    private static bool $schemaEnsured = false;

    public function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS assistant_download_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                token_hash CHAR(64) NOT NULL,
                document_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                channel_id INT UNSIGNED NULL,
                expires_at DATETIME NOT NULL,
                download_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_download_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_assistant_download_token (token_hash),
                KEY idx_assistant_download_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$schemaEnsured = true;
    }

    /**
     * @return string tokenul in clar (se trimite doar in URL, nu se salveaza)
     */
    public function create(int $documentId, int $userId, ?int $channelId): string
    {
        $this->ensureSchema();

        $now = new DateTimeImmutable('now');
        // Curatenie: tokenurile expirate de peste o zi nu mai au nicio utilitate.
        $cleanup = $this->db->prepare('DELETE FROM assistant_download_tokens WHERE expires_at < :cutoff');
        $cleanup->execute([':cutoff' => $now->modify('-1 day')->format('Y-m-d H:i:s')]);

        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare(
            'INSERT INTO assistant_download_tokens (token_hash, document_id, user_id, channel_id, expires_at, created_at)
             VALUES (:token_hash, :document_id, :user_id, :channel_id, :expires_at, :created_at)'
        );
        $stmt->execute([
            ':token_hash' => hash('sha256', $token),
            ':document_id' => $documentId,
            ':user_id' => $userId,
            ':channel_id' => $channelId,
            ':expires_at' => $now->modify('+' . self::TTL_SECONDS . ' seconds')->format('Y-m-d H:i:s'),
            ':created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    public function findByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $this->ensureSchema();
        $stmt = $this->db->prepare(
            'SELECT id, document_id, user_id, channel_id, expires_at
             FROM assistant_download_tokens
             WHERE token_hash = :token_hash
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function markDownloaded(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE assistant_download_tokens
             SET download_count = download_count + 1, last_download_at = :now
             WHERE id = :id'
        );
        $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $id]);
    }
}
