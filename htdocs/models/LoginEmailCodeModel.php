<?php
declare(strict_types=1);

class LoginEmailCodeModel extends BaseModel
{
    public function createCode(
        int $userId,
        string $email,
        string $codeHash,
        int $ttlSeconds,
        int $maxAttempts
    ): int {
        $now = time();
        $sentAt = date('Y-m-d H:i:s', $now);
        $expiresAt = date('Y-m-d H:i:s', $now + max(60, $ttlSeconds));
        $maxAttempts = max(1, $maxAttempts);

        $sql = 'INSERT INTO login_email_codes (
                    user_id,
                    email,
                    code_hash,
                    expires_at,
                    sent_at,
                    attempts,
                    max_attempts,
                    used_at,
                    created_at,
                    updated_at
                ) VALUES (
                    :user_id,
                    :email,
                    :code_hash,
                    :expires_at,
                    :sent_at,
                    0,
                    :max_attempts,
                    NULL,
                    :created_at,
                    :updated_at
                )';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':email' => trim($email),
            ':code_hash' => $codeHash,
            ':expires_at' => $expiresAt,
            ':sent_at' => $sentAt,
            ':max_attempts' => $maxAttempts,
            ':created_at' => $sentAt,
            ':updated_at' => $sentAt,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findCodeByIdForUser(int $codeId, int $userId): ?array
    {
        $sql = 'SELECT *
                FROM login_email_codes
                WHERE id = :id
                  AND user_id = :user_id
                LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $codeId,
            ':user_id' => $userId,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function findLatestActiveCodeForUser(int $userId): ?array
    {
        $sql = 'SELECT *
                FROM login_email_codes
                WHERE user_id = :user_id
                  AND used_at IS NULL
                ORDER BY id DESC
                LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function markCodeUsed(int $codeId): void
    {
        $sql = 'UPDATE login_email_codes
                SET used_at = COALESCE(used_at, :used_at),
                    updated_at = :updated_at
                WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            ':id' => $codeId,
            ':used_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function incrementAttempts(int $codeId): int
    {
        $sql = 'UPDATE login_email_codes
                SET attempts = attempts + 1,
                    updated_at = :updated_at
                WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $codeId,
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);

        $row = $this->findCodeById($codeId);
        return (int) ($row['attempts'] ?? 0);
    }

    public function invalidateActiveCodesForUser(int $userId): void
    {
        $sql = 'UPDATE login_email_codes
                SET used_at = COALESCE(used_at, :used_at),
                    updated_at = :updated_at
                WHERE user_id = :user_id
                  AND used_at IS NULL';
        $stmt = $this->db->prepare($sql);
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            ':user_id' => $userId,
            ':used_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function invalidateOtherActiveCodesForUser(int $userId, int $keepCodeId): void
    {
        $sql = 'UPDATE login_email_codes
                SET used_at = COALESCE(used_at, :used_at),
                    updated_at = :updated_at
                WHERE user_id = :user_id
                  AND used_at IS NULL
                  AND id <> :keep_id';
        $stmt = $this->db->prepare($sql);
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            ':user_id' => $userId,
            ':keep_id' => $keepCodeId,
            ':used_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    private function findCodeById(int $codeId): ?array
    {
        $sql = 'SELECT * FROM login_email_codes WHERE id = :id LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $codeId]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}
