<?php
declare(strict_types=1);

class DocumentModel extends BaseModel
{
    public function getNotificationSummary(?int $vehicleId = null): array
    {
        $conditions = [];
        $params = [];

        if ($vehicleId !== null) {
            $conditions[] = 'd.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $sql = '
            SELECT
                SUM(CASE WHEN d.data_expirare < CURDATE() THEN 1 ELSE 0 END) AS expirate,
                SUM(CASE WHEN d.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS expira_7_zile,
                SUM(CASE WHEN d.data_expirare BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS expira_30_zile,
                SUM(CASE WHEN d.fisier_stocat IS NULL OR d.fisier_stocat = "" THEN 1 ELSE 0 END) AS fara_fisier
            FROM documente d
            ' . $whereSql . '
        ';

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->execute();

        $row = $stmt->fetch() ?: [];

        return [
            'expirate' => (int) ($row['expirate'] ?? 0),
            'expira_7_zile' => (int) ($row['expira_7_zile'] ?? 0),
            'expira_30_zile' => (int) ($row['expira_30_zile'] ?? 0),
            'fara_fisier' => (int) ($row['fara_fisier'] ?? 0),
        ];
    }

    public function getUrgentDocuments(?int $vehicleId = null, int $limit = 5): array
    {
        $sql = '
            SELECT d.id,
                   d.tip_document,
                   d.numar_document,
                   d.data_expirare,
                   d.fisier_original,
                   v.nr_inmatriculare AS vehicul_label
            FROM documente d
            INNER JOIN vehicule v ON v.id = d.vehicle_id
            WHERE d.data_expirare <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ';

        $params = [];

        if ($vehicleId !== null) {
            $sql .= ' AND d.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }

        $sql .= '
            ORDER BY d.data_expirare ASC, d.id DESC
            LIMIT :limit_rows
        ';

        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt, $params);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getAuditLogsForDocument(int $documentId, int $limit = 15): array
    {
        $sql = '
            SELECT a.*,
                   u.nume AS utilizator_nume
            FROM audit_log a
            LEFT JOIN utilizatori u ON u.id = a.user_id
            WHERE a.modul = :modul
              AND a.record_id = :record_id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT :limit_rows
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':modul', 'documente', PDO::PARAM_STR);
        $stmt->bindValue(':record_id', $documentId, PDO::PARAM_INT);
        $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getDocumentsForVehicle(int $vehicleId): array
    {
        $sql = '
            SELECT d.id,
                   d.vehicle_id,
                   d.tip_document,
                   d.numar_document,
                   d.data_expirare,
                   d.fisier_original,
                   d.fisier_stocat,
                   d.updated_at
            FROM documente d
            WHERE d.vehicle_id = :vehicle_id
            ORDER BY d.data_expirare ASC, d.id DESC
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getDocumentsForDriver(int $driverId): array
    {
        $sql = '
            SELECT d.id,
                   d.driver_id,
                   d.tip_document,
                   d.numar_document,
                   d.data_expirare,
                   d.fisier_original,
                   d.fisier_stocat,
                   d.updated_at,
                   s.nume AS sofer_label,
                   s.telefon AS sofer_telefon,
                   v.nr_inmatriculare AS vehicul_label
            FROM documente_soferi d
            INNER JOIN soferi s ON s.id = d.driver_id
            LEFT JOIN vehicule v ON v.id = s.vehicle_id
            WHERE d.driver_id = :driver_id
            ORDER BY d.data_expirare ASC, d.id DESC
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function logAudit(string $modul, int $recordId, string $actiune, string $descriere, ?int $userId, ?array $beforeData, ?array $afterData): void
    {
        $sql = '
            INSERT INTO audit_log (
                modul,
                record_id,
                actiune,
                descriere,
                before_data,
                after_data,
                user_id,
                created_at
            ) VALUES (
                :modul,
                :record_id,
                :actiune,
                :descriere,
                :before_data,
                :after_data,
                :user_id,
                :created_at
            )
        ';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':modul', $modul, PDO::PARAM_STR);
        $stmt->bindValue(':record_id', $recordId, PDO::PARAM_INT);
        $stmt->bindValue(':actiune', $actiune, PDO::PARAM_STR);
        $stmt->bindValue(':descriere', $descriere, PDO::PARAM_STR);
        $stmt->bindValue(':before_data', $beforeData !== null ? json_encode($beforeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $beforeData !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':after_data', $afterData !== null ? json_encode($afterData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, $afterData !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        if ($userId !== null && $userId > 0) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
        }

        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->execute();
    }

    private function bindAll(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $placeholder => $value) {
            if (is_int($value)) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
                continue;
            }

            if ($value === null) {
                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
                continue;
            }

            $stmt->bindValue($placeholder, (string) $value, PDO::PARAM_STR);
        }
    }
}
