<?php
declare(strict_types=1);

/** Scriere in financiar_audit (append-only). Se alatura tranzactiei apelantului. */
final class FinancialAuditLog
{
    public static function record(
        PDO $db,
        string $entity,
        int $entityId,
        string $action,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?string $reason = null,
        ?array $details = null,
        ?int $userId = null
    ): void {
        $db->prepare(
            'INSERT INTO financiar_audit (entitate, entitate_id, actiune, camp, valoare_veche, valoare_noua, motiv, detalii, user_id, created_at)
             VALUES (:e, :id, :a, :c, :o, :n, :m, :d, :u, NOW())'
        )->execute([
            ':e' => $entity,
            ':id' => $entityId,
            ':a' => $action,
            ':c' => $field,
            ':o' => $oldValue !== null ? mb_substr($oldValue, 0, 255) : null,
            ':n' => $newValue !== null ? mb_substr($newValue, 0, 255) : null,
            ':m' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null,
            ':d' => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ':u' => $userId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function history(PDO $db, string $entity, int $entityId): array
    {
        $stmt = $db->prepare('SELECT * FROM financiar_audit WHERE entitate = :e AND entitate_id = :id ORDER BY created_at, id');
        $stmt->execute([':e' => $entity, ':id' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
