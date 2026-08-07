<?php
declare(strict_types=1);

/**
 * Stocarea passkey-urilor (credentiale WebAuthn) per utilizator.
 * Tabelul se creeaza automat la prima folosire (conventia aplicatiei).
 */
class PasskeyModel extends BaseModel
{
    private static bool $schemaEnsured = false;

    public function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS user_passkeys (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                credential_id VARCHAR(512) NOT NULL,
                public_key TEXT NOT NULL,
                sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                aaguid VARCHAR(64) NULL,
                transports VARCHAR(191) NULL,
                label VARCHAR(120) NOT NULL DEFAULT 'Passkey',
                created_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                UNIQUE KEY uq_user_passkeys_credential (credential_id(191)),
                INDEX idx_user_passkeys_user (user_id),
                CONSTRAINT fk_user_passkeys_user FOREIGN KEY (user_id)
                    REFERENCES utilizatori(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaEnsured = true;
    }

    public function create(
        int $userId,
        string $credentialId,
        string $publicKeyPem,
        int $signCount,
        ?string $aaguid,
        ?string $transports,
        string $label
    ): int {
        $this->ensureSchema();
        $stmt = $this->db->prepare("
            INSERT INTO user_passkeys
                (user_id, credential_id, public_key, sign_count, aaguid, transports, label, created_at)
            VALUES
                (:user_id, :credential_id, :public_key, :sign_count, :aaguid, :transports, :label, :created_at)
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':credential_id' => $credentialId,
            ':public_key' => $publicKeyPem,
            ':sign_count' => max(0, $signCount),
            ':aaguid' => $aaguid !== '' ? $aaguid : null,
            ':transports' => $transports !== '' ? $transports : null,
            ':label' => $label !== '' ? mb_substr($label, 0, 120) : 'Passkey',
            ':created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findByCredentialId(string $credentialId): ?array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare("
            SELECT p.*, u.status AS user_status
            FROM user_passkeys p
            INNER JOIN utilizatori u ON u.id = p.user_id
            WHERE p.credential_id = :cid
            LIMIT 1
        ");
        $stmt->execute([':cid' => $credentialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function existsByCredentialId(string $credentialId): bool
    {
        return $this->findByCredentialId($credentialId) !== null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare("
            SELECT id, label, transports, created_at, last_used_at
            FROM user_passkeys
            WHERE user_id = :uid
            ORDER BY created_at DESC
        ");
        $stmt->execute([':uid' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,string> base64url credential ids */
    public function credentialIdsForUser(int $userId): array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare("SELECT credential_id FROM user_passkeys WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function countForUser(int $userId): int
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_passkeys WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public function touch(int $id, int $signCount): void
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare("
            UPDATE user_passkeys
            SET sign_count = GREATEST(sign_count, :c), last_used_at = :t
            WHERE id = :id
        ");
        $stmt->execute([':c' => max(0, $signCount), ':t' => date('Y-m-d H:i:s'), ':id' => $id]);
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare("DELETE FROM user_passkeys WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
