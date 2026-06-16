<?php
declare(strict_types=1);

class NotificationDeliveryModel extends BaseModel
{
    private ?bool $tableAvailable = null;

    public function canLog(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'notification_deliveries'
            ");
            $stmt->execute();
            $this->tableAvailable = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            $this->tableAvailable = false;
        }

        return $this->tableAvailable;
    }

    public function create(array $delivery): ?int
    {
        if (!$this->canLog()) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $status = $this->normalizeStatus((string) ($delivery['status'] ?? 'failed'));
        $sentAt = $status === 'sent' ? ($delivery['sent_at'] ?? $now) : null;

        $sql = "
            INSERT INTO notification_deliveries (
                context,
                context_id,
                channel,
                recipient_email,
                recipient_name,
                subject,
                message,
                status,
                provider,
                provider_response,
                error_message,
                diagnostics_json,
                metadata_json,
                created_at,
                sent_at
            ) VALUES (
                :context,
                :context_id,
                :channel,
                :recipient_email,
                :recipient_name,
                :subject,
                :message,
                :status,
                :provider,
                :provider_response,
                :error_message,
                :diagnostics_json,
                :metadata_json,
                :created_at,
                :sent_at
            )
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':context' => $this->limit((string) ($delivery['context'] ?? 'general'), 80),
                ':context_id' => $this->nullableLimitedString($delivery['context_id'] ?? null, 120),
                ':channel' => 'email',
                ':recipient_email' => $this->limit((string) ($delivery['recipient_email'] ?? ''), 190),
                ':recipient_name' => $this->nullableLimitedString($delivery['recipient_name'] ?? null, 190),
                ':subject' => $this->limit((string) ($delivery['subject'] ?? ''), 255),
                ':message' => (string) ($delivery['message'] ?? ''),
                ':status' => $status,
                ':provider' => $this->limit((string) ($delivery['provider'] ?? 'smtp'), 100),
                ':provider_response' => $this->nullableString($delivery['provider_response'] ?? null),
                ':error_message' => $this->nullableString($delivery['error_message'] ?? null),
                ':diagnostics_json' => $this->encodeJson($delivery['diagnostics'] ?? []),
                ':metadata_json' => $this->encodeJson($delivery['metadata'] ?? []),
                ':created_at' => (string) ($delivery['created_at'] ?? $now),
                ':sent_at' => $sentAt,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (Throwable $exception) {
            error_log('[NotificationDeliveryModel] Nu am putut scrie logul de notificare: ' . $exception->getMessage());
            return null;
        }
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['pending', 'sent', 'failed', 'skipped'], true) ? $status : 'failed';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableLimitedString(mixed $value, int $limit): ?string
    {
        $value = $this->nullableString($value);
        return $value === null ? null : $this->limit($value, $limit);
    }

    private function limit(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : null;
    }
}
