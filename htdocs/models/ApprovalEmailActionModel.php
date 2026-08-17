<?php
declare(strict_types=1);

/**
 * Token-uri de aprobare trimise pe email.
 *
 * Aceeasi tabela este folosita si de notification_service/approval_flow.py:
 * un token consumat prin link devine inutilizabil si prin reply, si invers.
 * In baza de date se pastreaza doar SHA-256 al token-ului.
 */
class ApprovalEmailActionModel extends BaseModel
{
    public const TOKEN_PATTERN = '/^[abcdefghjkmnpqrstuvwxyz23456789]{16}$/';

    public function hashToken(string $token): string
    {
        return hash('sha256', strtolower(trim($token)));
    }

    public function isValidTokenFormat(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, strtolower(trim($token))) === 1;
    }

    public function findByToken(string $token): ?array
    {
        if (!$this->isValidTokenFormat($token)) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT
                ea.*,
                a.status AS approval_status,
                a.resource_type,
                a.resource_label,
                a.inactive_reason_label,
                a.inactive_since,
                a.usage_context,
                a.requested_at,
                u.nume AS requested_by_name
            FROM approval_email_actions ea
            INNER JOIN inactive_resource_approvals a ON a.id = ea.approval_id
            LEFT JOIN utilizatori u ON u.id = a.requested_by_user_id
            WHERE ea.token_hash = :token_hash
            LIMIT 1
        ");
        $stmt->execute([':token_hash' => $this->hashToken($token)]);

        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function documentsFor(int $approvalId): array
    {
        $stmt = $this->db->prepare("
            SELECT document_name, document_status, expiry_date
            FROM inactive_resource_approval_documents
            WHERE approval_id = :approval_id
            ORDER BY document_name ASC
        ");
        $stmt->execute([':approval_id' => $approvalId]);

        return $stmt->fetchAll();
    }

    /**
     * Motivul pentru care token-ul nu poate fi folosit, sau null daca este valabil.
     */
    public function rejectionReason(?array $action): ?string
    {
        if ($action === null) {
            return 'Linkul nu este valid. Verifica daca l-ai deschis intreg din email.';
        }

        $status = (string) ($action['status'] ?? '');
        if ($status === 'used') {
            return 'Aceasta decizie a fost deja inregistrata. Nu se intampla nimic in plus.';
        }

        if ($status === 'refused') {
            return 'Linkul a fost blocat pentru ca a fost folosit de pe o adresa neasteptata.';
        }

        if ($status !== 'active') {
            return 'Linkul nu mai este valabil.';
        }

        $expiresAt = (string) ($action['expires_at'] ?? '');
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && time() > strtotime($expiresAt)) {
            return 'Linkul a expirat. Intra in aplicatie ca sa decizi.';
        }

        if ((string) ($action['approval_status'] ?? '') !== 'pending') {
            return 'Cererea a fost decisa intre timp de altcineva.';
        }

        return null;
    }

    /**
     * Consuma token-ul si aplica decizia. Totul intr-o tranzactie:
     * daca cererea nu mai este 'pending', token-ul nu se consuma degeaba.
     *
     * @return array{applied: bool, message: string, status: string}
     */
    public function consume(string $token, string $via = 'link'): array
    {
        $action = $this->findByToken($token);
        $blocked = $this->rejectionReason($action);
        if ($blocked !== null || $action === null) {
            return [
                'applied' => false,
                'message' => $blocked ?? 'Linkul nu este valid.',
                'status' => (string) ($action['approval_status'] ?? 'unknown'),
            ];
        }

        $actionId = (int) $action['id'];
        $approvalId = (int) $action['approval_id'];
        $targetStatus = (string) $action['action'] === 'approve' ? 'approved' : 'rejected';
        $reviewerId = $action['recipient_user_id'] !== null ? (int) $action['recipient_user_id'] : null;
        $note = sprintf(
            '%s prin email (%s) de %s.',
            $targetStatus === 'approved' ? 'Aprobat' : 'Respins',
            $via,
            (string) $action['recipient_email']
        );

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                UPDATE inactive_resource_approvals
                SET status = :status,
                    reviewed_by_user_id = :reviewer,
                    reviewed_at = NOW(),
                    review_note = :note,
                    updated_at = NOW()
                WHERE id = :id
                  AND status = 'pending'
            ");
            $stmt->execute([
                ':status' => $targetStatus,
                ':reviewer' => $reviewerId,
                ':note' => $note,
                ':id' => $approvalId,
            ]);

            if ($stmt->rowCount() === 0) {
                $this->db->rollBack();
                return [
                    'applied' => false,
                    'message' => 'Cererea a fost decisa intre timp de altcineva.',
                    'status' => $targetStatus,
                ];
            }

            $stmt = $this->db->prepare("
                UPDATE approval_email_actions
                SET status = 'used',
                    used_at = NOW(),
                    used_from_email = :email,
                    result_note = :note,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':email' => (string) $action['recipient_email'],
                ':note' => $note,
                ':id' => $actionId,
            ]);

            // Token-ul opus (si orice alt token activ pe aceeasi cerere) devine inutil.
            $stmt = $this->db->prepare("
                UPDATE approval_email_actions
                SET status = 'expired',
                    result_note = COALESCE(result_note, 'Cerere decisa intre timp.'),
                    updated_at = NOW()
                WHERE approval_id = :approval_id
                  AND status = 'active'
                  AND id <> :id
            ");
            $stmt->execute([':approval_id' => $approvalId, ':id' => $actionId]);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return [
            'applied' => true,
            'message' => $targetStatus === 'approved'
                ? 'Cererea a fost aprobata.'
                : 'Cererea a fost respinsa.',
            'status' => $targetStatus,
        ];
    }
}
