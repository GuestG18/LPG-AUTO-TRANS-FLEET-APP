<?php
declare(strict_types=1);

class MaintenanceModel extends BaseModel
{
    public const COST_CENTERS = [
        'Motor',
        'Transmisie',
        'Sistem frânare',
        'Sistem electric',
        'Suspensie',
        'Sistem pneumatic',
        'Sistem hidraulic',
        'Sistem răcire',
        'Caroserie',
        'Consumabile',
        'Altele',
    ];

    public const VEHICLE_TYPE_LABELS = [
        'universal' => 'Toate tipurile',
        'autovehicul' => 'Autovehicul',
        'autoutilitara' => 'Autoutilitara',
        'camion' => 'Camion',
        'cap_tractor' => 'Cap tractor',
        'semiremorca' => 'Semiremorca',
        'semiremorca_primar' => 'Semiremorca primar',
        'semiremorca_distributie' => 'Semiremorca distributie',
    ];

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    public function getVehicles(): array
    {
        $stmt = $this->db->query(
            "SELECT id, nr_inmatriculare, marca, model, tip_vehicul, km_bord
             FROM vehicule
             WHERE status = 'activ'
               AND nr_inmatriculare <> 'STOC-ANVELOPE'
             ORDER BY nr_inmatriculare ASC"
        );

        return $stmt->fetchAll();
    }

    public function getDrivers(): array
    {
        return $this->db->query(
            "SELECT id, nume FROM soferi WHERE status = 'activ' ORDER BY nume ASC"
        )->fetchAll();
    }

    public function getCostCenterOptions(): array
    {
        $optionMap = [];
        foreach ($this->getPersistedCostCenterNames() as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $optionMap['universal:' . mb_strtolower($name)] = [
                    'label' => $name,
                    'vehicle_type' => 'universal',
                    'components' => '',
                ];
            }
        }

        return array_values($optionMap);
    }

    public function getCostCenterNames(): array
    {
        $names = [];
        foreach ($this->getCostCenterOptions() as $option) {
            $label = trim((string) ($option['label'] ?? ''));
            if ($label !== '') {
                $names[$label] = $label;
            }
        }
        foreach ($this->getPersistedCostCenterNames() as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $names[$name] = $name;
            }
        }

        ksort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($names);
    }

    public function getOverview(array $filters): array
    {
        [$where, $params] = $this->buildRecordWhere($filters);
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS total_interventii,
                COALESCE(SUM(m.cost), 0) AS cost_total,
                COUNT(DISTINCT m.vehicle_id) AS vehicule_intervenite,
                COALESCE(AVG(NULLIF(m.zile_imobilizare, 0)), 0) AS timp_mediu,
                SUM(CASE WHEN m.status_interventie IN ('in_asteptare','in_lucru') THEN 1 ELSE 0 END) AS in_asteptare
             FROM mentenanta m
             INNER JOIN vehicule v ON v.id = m.vehicle_id" . $whereSql
        );
        $stmt->execute($params);
        $kpis = $stmt->fetch() ?: [];

        $scheduleStmt = $this->db->query(
            "SELECT COUNT(*) FROM mentenanta_interventii_programate
             WHERE status_interventie IN ('programata','confirmata','in_lucru')"
        );
        $kpis['in_asteptare'] = (int) ($kpis['in_asteptare'] ?? 0) + (int) $scheduleStmt->fetchColumn();
        $kpis['total_vehicule'] = (int) $this->db->query(
            "SELECT COUNT(*) FROM vehicule WHERE status = 'activ' AND nr_inmatriculare <> 'STOC-ANVELOPE'"
        )->fetchColumn();

        $typeStmt = $this->db->prepare(
            "SELECT m.record_type, COALESCE(SUM(m.cost), 0) AS total
             FROM mentenanta m INNER JOIN vehicule v ON v.id = m.vehicle_id" . $whereSql . "
             GROUP BY m.record_type"
        );
        $typeStmt->execute($params);
        $costByType = ['intretinere' => 0.0, 'reparatie' => 0.0];
        foreach ($typeStmt->fetchAll() as $row) {
            $key = (string) ($row['record_type'] ?? '');
            if (array_key_exists($key, $costByType)) {
                $costByType[$key] = (float) ($row['total'] ?? 0);
            }
        }

        $centerStmt = $this->db->prepare(
            "SELECT COALESCE(NULLIF(m.centru_cost, ''), 'Altele') AS label, SUM(m.cost) AS total
             FROM mentenanta m INNER JOIN vehicule v ON v.id = m.vehicle_id" . $whereSql . "
             GROUP BY COALESCE(NULLIF(m.centru_cost, ''), 'Altele')
             ORDER BY total DESC LIMIT 5"
        );
        $centerStmt->execute($params);

        $vehicleStmt = $this->db->prepare(
            "SELECT m.vehicle_id, v.nr_inmatriculare AS label, v.tip_vehicul, SUM(m.cost) AS total
             FROM mentenanta m INNER JOIN vehicule v ON v.id = m.vehicle_id" . $whereSql . "
             GROUP BY m.vehicle_id, v.nr_inmatriculare, v.tip_vehicul
             ORDER BY total DESC"
        );
        $vehicleStmt->execute($params);
        $vehicleCosts = $vehicleStmt->fetchAll();

        return [
            'kpis' => $kpis,
            'cost_by_type' => $costByType,
            'cost_centers' => $centerStmt->fetchAll(),
            'vehicle_costs' => array_slice($vehicleCosts, 0, 5),
            'ensemble_costs' => $this->buildActiveEnsembleCosts($vehicleCosts),
            'recent' => $this->getRecords($filters, null, 5),
        ];
    }

    public function getRecords(array $filters, ?string $recordType = null, int $limit = 100): array
    {
        [$where, $params] = $this->buildRecordWhere($filters, $recordType);
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $limit = max(1, min(500, $limit));

        $stmt = $this->db->prepare(
            "SELECT m.*, v.nr_inmatriculare, v.tip_vehicul, v.marca, v.model
             FROM mentenanta m
             INNER JOIN vehicule v ON v.id = m.vehicle_id" . $whereSql . "
             ORDER BY m.data_interventie DESC, m.id DESC
             LIMIT " . $limit
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getRecord(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, v.nr_inmatriculare, v.tip_vehicul
             FROM mentenanta m INNER JOIN vehicule v ON v.id = m.vehicle_id
             WHERE m.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getCorrectiveInvoices(array $filters, int $limit = 100): array
    {
        $this->backfillCorrectiveInvoicesFromRecords();
        [$where, $params] = $this->buildCorrectiveInvoiceWhere($filters);
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $limit = max(1, min(500, $limit));

        $stmt = $this->db->prepare(
            "SELECT
                si.*,
                COALESCE(NULLIF(si.supplier_name, ''), CONCAT('Furnizor #', si.supplier_id), '-') AS supplier_label,
                DATEDIFF(si.due_date, CURDATE()) AS due_days,
                (
                    SELECT COUNT(DISTINCT ivr.vehicle_id)
                    FROM invoice_vehicle_repairs ivr
                    WHERE ivr.invoice_id = si.id
                ) AS vehicle_count,
                (
                    SELECT GROUP_CONCAT(DISTINCT v.nr_inmatriculare ORDER BY v.nr_inmatriculare SEPARATOR ', ')
                    FROM invoice_vehicle_repairs ivr
                    INNER JOIN vehicule v ON v.id = ivr.vehicle_id
                    WHERE ivr.invoice_id = si.id
                ) AS vehicle_labels,
                (
                    SELECT COUNT(*)
                    FROM invoice_vehicle_repairs ivr
                    WHERE ivr.invoice_id = si.id
                ) AS repair_count,
                (
                    SELECT COUNT(*)
                    FROM invoice_repair_parts irp
                    INNER JOIN invoice_vehicle_repairs ivr ON ivr.id = irp.repair_id
                    WHERE ivr.invoice_id = si.id
                ) AS part_count
             FROM supplier_invoices si" . $whereSql . "
             ORDER BY si.invoice_date DESC, si.id DESC
             LIMIT " . $limit
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getCorrectiveInvoiceDetails(array $invoiceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $key = ':invoice_id_' . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $inSql = implode(', ', $placeholders);

        $details = [];
        foreach ($ids as $id) {
            $details[$id] = ['repairs' => []];
        }

        $repairStmt = $this->db->prepare(
            "SELECT
                r.*,
                v.nr_inmatriculare,
                v.tip_vehicul,
                v.marca,
                v.model,
                COALESCE((
                    SELECT SUM(p.total_with_vat)
                    FROM invoice_repair_parts p
                    WHERE p.repair_id = r.id
                ), 0) AS parts_total,
                COALESCE((
                    SELECT COUNT(*)
                    FROM invoice_repair_parts p
                    WHERE p.repair_id = r.id
                ), 0) AS part_count
             FROM invoice_vehicle_repairs r
             INNER JOIN vehicule v ON v.id = r.vehicle_id
             WHERE r.invoice_id IN (" . $inSql . ")
             ORDER BY r.invoice_id ASC, v.nr_inmatriculare ASC, r.id ASC"
        );
        $repairStmt->execute($params);
        $repairIndex = [];
        foreach ($repairStmt->fetchAll() as $repair) {
            $invoiceId = (int) ($repair['invoice_id'] ?? 0);
            $repair['parts'] = [];
            $details[$invoiceId]['repairs'][] = $repair;
            $repairIndex[(int) $repair['id']] = [$invoiceId, count($details[$invoiceId]['repairs']) - 1];
        }

        if ($repairIndex === []) {
            return $details;
        }

        $partStmt = $this->db->prepare(
            "SELECT p.*
             FROM invoice_repair_parts p
             INNER JOIN invoice_vehicle_repairs r ON r.id = p.repair_id
             WHERE r.invoice_id IN (" . $inSql . ")
             ORDER BY p.repair_id ASC, p.id ASC"
        );
        $partStmt->execute($params);
        foreach ($partStmt->fetchAll() as $part) {
            $repairId = (int) ($part['repair_id'] ?? 0);
            if (!isset($repairIndex[$repairId])) {
                continue;
            }
            [$invoiceId, $repairOffset] = $repairIndex[$repairId];
            $details[$invoiceId]['repairs'][$repairOffset]['parts'][] = $part;
        }

        return $details;
    }

    public function deleteCorrectiveInvoice(int $invoiceId): bool
    {
        if ($invoiceId <= 0) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $sourceStmt = $this->db->prepare(
                "SELECT DISTINCT source_maintenance_id
                 FROM invoice_vehicle_repairs
                 WHERE invoice_id = :invoice_id AND source_maintenance_id IS NOT NULL"
            );
            $sourceStmt->execute([':invoice_id' => $invoiceId]);
            $sourceIds = array_map('intval', $sourceStmt->fetchAll(PDO::FETCH_COLUMN));

            foreach ($sourceIds as $sourceId) {
                $usageStmt = $this->db->prepare(
                    "SELECT part_id, cantitate, direct_mount
                     FROM mentenanta_piese_utilizari WHERE maintenance_id = :id FOR UPDATE"
                );
                $usageStmt->execute([':id' => $sourceId]);
                foreach ($usageStmt->fetchAll() as $usage) {
                    if (empty($usage['direct_mount'])) {
                        $restore = $this->db->prepare(
                            "UPDATE mentenanta_piese
                             SET stoc_curent = stoc_curent + :qty, updated_at = :updated_at
                             WHERE id = :id"
                        );
                        $restore->execute([
                            ':qty' => (float) $usage['cantitate'],
                            ':updated_at' => date('Y-m-d H:i:s'),
                            ':id' => (int) $usage['part_id'],
                        ]);
                    }
                }

                $this->db->prepare("DELETE FROM mentenanta_piese_utilizari WHERE maintenance_id = :id")->execute([':id' => $sourceId]);
                $this->db->prepare("DELETE FROM mentenanta WHERE id = :id AND record_type = 'reparatie'")->execute([':id' => $sourceId]);
            }

            $deleted = $this->db->prepare("DELETE FROM supplier_invoices WHERE id = :id");
            $deleted->execute([':id' => $invoiceId]);
            $this->db->commit();

            return $deleted->rowCount() > 0;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function saveRecord(array $data, int $id = 0): int
    {
        $this->db->beginTransaction();
        try {
            $partId = (int) ($data['stock_part_id'] ?? 0);
            $partQuantity = max(0.0, (float) ($data['stock_part_quantity'] ?? 0));
            $stockPart = null;
            $stockPartCost = 0.0;
            if ($id <= 0 && $partId > 0 && $partQuantity > 0) {
                $stockPart = $this->lockStockPart($partId);
                if ($stockPart === null || (float) ($stockPart['stoc_curent'] ?? 0) < $partQuantity) {
                    throw new RuntimeException('Stoc insuficient pentru piesa selectată.');
                }
                $stockPartCost = $partQuantity * max(0, (float) ($stockPart['pret_achizitie'] ?? 0));
            }

            $laborCost = max(0, (float) ($data['cost_manopera'] ?? 0));
            $partsCost = max(0, (float) ($data['cost_piese'] ?? 0)) + $stockPartCost;
            $totalCost = max(0, (float) ($data['cost'] ?? 0));
            if ($laborCost > 0 || $partsCost > 0) {
                $totalCost = $laborCost + $partsCost;
            }
            $partsUsed = trim((string) ($data['piese_utilizate'] ?? ''));
            if ($stockPart !== null) {
                $stockPartLabel = trim((string) ($stockPart['denumire'] ?? 'Piesă din stoc'))
                    . ' × ' . rtrim(rtrim(number_format($partQuantity, 2, '.', ''), '0'), '.');
                $partsUsed = $partsUsed !== '' ? ($partsUsed . ', ' . $stockPartLabel) : $stockPartLabel;
            }

            $values = [
                ':vehicle_id' => (int) $data['vehicle_id'],
                ':tip_interventie' => trim((string) $data['tip_interventie']),
                ':record_type' => $this->normalizeRecordType((string) $data['record_type']),
                ':centru_cost' => trim((string) ($data['centru_cost'] ?? 'Altele')),
                ':technical_category_id' => !empty($data['technical_category_id']) ? (int) $data['technical_category_id'] : null,
                ':technical_component_id' => !empty($data['technical_component_id']) ? (int) $data['technical_component_id'] : null,
                ':technical_health_percent' => ($data['technical_health_percent'] ?? '') !== '' ? max(0, min(100, (int) $data['technical_health_percent'])) : null,
                ':descriere' => trim((string) ($data['descriere'] ?? '')),
                ':status_interventie' => $this->normalizeRecordStatus((string) ($data['status_interventie'] ?? 'finalizata')),
                ':data_interventie' => (string) $data['data_interventie'],
                ':km_interventie' => ($data['km_interventie'] ?? '') !== '' ? (int) $data['km_interventie'] : null,
                ':cost' => $totalCost,
                ':cost_manopera' => $laborCost,
                ':cost_piese' => $partsCost,
                ':zile_imobilizare' => max(0, (float) ($data['zile_imobilizare'] ?? 0)),
                ':atelier' => $this->nullIfEmpty((string) ($data['atelier'] ?? '')),
                ':furnizor_piesa' => $this->nullIfEmpty((string) ($data['furnizor_piesa'] ?? '')),
                ':piese_utilizate' => $this->nullIfEmpty($partsUsed),
                ':fisier_original' => $this->nullIfEmpty((string) ($data['fisier_original'] ?? '')),
                ':fisier_stocat' => $this->nullIfEmpty((string) ($data['fisier_stocat'] ?? '')),
                ':observatii' => $this->nullIfEmpty((string) ($data['observatii'] ?? '')),
                ':updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($id > 0) {
                $values[':id'] = $id;
                $sql = "UPDATE mentenanta SET
                    vehicle_id = :vehicle_id, tip_interventie = :tip_interventie,
                    record_type = :record_type, centru_cost = :centru_cost, descriere = :descriere,
                    technical_category_id = :technical_category_id,
                    technical_component_id = :technical_component_id,
                    technical_health_percent = :technical_health_percent,
                    status_interventie = :status_interventie, data_interventie = :data_interventie,
                    km_interventie = :km_interventie, cost = :cost, cost_manopera = :cost_manopera,
                    cost_piese = :cost_piese, zile_imobilizare = :zile_imobilizare,
                    atelier = :atelier, furnizor_piesa = :furnizor_piesa, piese_utilizate = :piese_utilizate,
                    fisier_original = COALESCE(:fisier_original, fisier_original),
                    fisier_stocat = COALESCE(:fisier_stocat, fisier_stocat),
                    observatii = :observatii, updated_at = :updated_at
                    WHERE id = :id";
                $this->db->prepare($sql)->execute($values);
            } else {
                $values[':created_at'] = date('Y-m-d H:i:s');
                $sql = "INSERT INTO mentenanta
                    (vehicle_id, tip_interventie, record_type, centru_cost, technical_category_id,
                     technical_component_id, technical_health_percent, descriere, status_interventie,
                     data_interventie, km_interventie, cost, cost_manopera, cost_piese, zile_imobilizare,
                     atelier, furnizor_piesa, piese_utilizate, fisier_original, fisier_stocat,
                     observatii, created_at, updated_at)
                    VALUES
                    (:vehicle_id, :tip_interventie, :record_type, :centru_cost, :technical_category_id,
                     :technical_component_id, :technical_health_percent, :descriere, :status_interventie,
                     :data_interventie, :km_interventie, :cost, :cost_manopera, :cost_piese, :zile_imobilizare,
                     :atelier, :furnizor_piesa, :piese_utilizate, :fisier_original, :fisier_stocat,
                     :observatii, :created_at, :updated_at)";
                $this->db->prepare($sql)->execute($values);
                $id = (int) $this->db->lastInsertId();

                if ($stockPart !== null) {
                    $this->decreaseStockAndCreateUsage(
                        $stockPart,
                        $partQuantity,
                        (int) $data['vehicle_id'],
                        $id,
                        null,
                        (string) $data['data_interventie'],
                        (int) ($data['km_interventie'] ?? 0),
                        'Intervenție mentenanță',
                        (string) ($data['observatii'] ?? ''),
                        false
                    );
                }
            }

            $this->db->commit();
            return $id;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteRecord(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $usageStmt = $this->db->prepare(
                "SELECT part_id, cantitate, direct_mount
                 FROM mentenanta_piese_utilizari WHERE maintenance_id = :id FOR UPDATE"
            );
            $usageStmt->execute([':id' => $id]);
            foreach ($usageStmt->fetchAll() as $usage) {
                if (empty($usage['direct_mount'])) {
                    $restore = $this->db->prepare(
                        "UPDATE mentenanta_piese SET stoc_curent = stoc_curent + :qty, updated_at = :updated_at WHERE id = :id"
                    );
                    $restore->execute([
                        ':qty' => (float) $usage['cantitate'],
                        ':updated_at' => date('Y-m-d H:i:s'),
                        ':id' => (int) $usage['part_id'],
                    ]);
                }
            }
            $this->db->prepare("DELETE FROM mentenanta_piese_utilizari WHERE maintenance_id = :id")->execute([':id' => $id]);
            $deleted = $this->db->prepare("DELETE FROM mentenanta WHERE id = :id");
            $deleted->execute([':id' => $id]);
            $this->db->commit();
            return $deleted->rowCount() > 0;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function getScheduledInterventions(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['vehicle_id'])) {
            $where[] = 'i.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = (int) $filters['vehicle_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'i.status_interventie = :status';
            $params[':status'] = (string) $filters['status'];
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare(
            "SELECT i.*, v.nr_inmatriculare, v.tip_vehicul, s.nume AS sofer_nume
             FROM mentenanta_interventii_programate i
             INNER JOIN vehicule v ON v.id = i.vehicle_id
             LEFT JOIN soferi s ON s.id = i.driver_id" . $whereSql . "
             ORDER BY FIELD(i.status_interventie, 'in_lucru','confirmata','programata','finalizata','anulata'),
                      i.data_programata ASC, i.id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getScheduledIntervention(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM mentenanta_interventii_programate WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function saveScheduledIntervention(array $data, int $id = 0, ?int $createdBy = null): int
    {
        $this->db->beginTransaction();
        try {
            $values = [
                ':vehicle_id' => (int) $data['vehicle_id'],
                ':tip_interventie' => $this->normalizeRecordType((string) $data['tip_interventie']),
                ':data_programata' => (string) $data['data_programata'],
                ':cost_estimat' => max(0, (float) ($data['cost_estimat'] ?? 0)),
                ':furnizor' => $this->nullIfEmpty((string) ($data['furnizor'] ?? '')),
                ':driver_id' => !empty($data['driver_id']) ? (int) $data['driver_id'] : null,
                ':client' => $this->nullIfEmpty((string) ($data['client'] ?? '')),
                ':centru_cost' => $this->nullIfEmpty((string) ($data['centru_cost'] ?? '')),
                ':descriere' => trim((string) ($data['descriere'] ?? '')),
                ':status_interventie' => $this->normalizeScheduleStatus((string) ($data['status_interventie'] ?? 'programata')),
                ':updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($id > 0) {
                $values[':id'] = $id;
                $this->db->prepare(
                    "UPDATE mentenanta_interventii_programate SET
                        vehicle_id = :vehicle_id, tip_interventie = :tip_interventie,
                        data_programata = :data_programata, cost_estimat = :cost_estimat,
                        furnizor = :furnizor, driver_id = :driver_id, client = :client,
                        centru_cost = :centru_cost, descriere = :descriere,
                        status_interventie = :status_interventie, updated_at = :updated_at
                     WHERE id = :id"
                )->execute($values);
            } else {
                $values[':created_by'] = $createdBy;
                $values[':created_at'] = date('Y-m-d H:i:s');
                $this->db->prepare(
                    "INSERT INTO mentenanta_interventii_programate
                        (vehicle_id, tip_interventie, data_programata, cost_estimat, furnizor,
                         driver_id, client, centru_cost, descriere, status_interventie,
                         created_by, created_at, updated_at)
                     VALUES
                        (:vehicle_id, :tip_interventie, :data_programata, :cost_estimat, :furnizor,
                         :driver_id, :client, :centru_cost, :descriere, :status_interventie,
                         :created_by, :created_at, :updated_at)"
                )->execute($values);
                $id = (int) $this->db->lastInsertId();
            }

            if ($values[':status_interventie'] === 'finalizata') {
                $this->convertScheduledIntervention($id);
            }

            $this->db->commit();
            return $id;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteScheduledIntervention(int $id): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM mentenanta_interventii_programate
             WHERE id = :id AND converted_maintenance_id IS NULL"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function getStockParts(array $filters = []): array
    {
        $where = ["p.mod_utilizare = 'stoc'"];
        $params = [];
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = "CONCAT_WS(' ', p.cod_piesa, p.denumire, p.cod_oem) LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }
        foreach (['categorie', 'furnizor'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = 'p.' . $field . ' = :' . $field;
                $params[':' . $field] = (string) $filters[$field];
            }
        }
        if (!empty($filters['stock_status'])) {
            if ($filters['stock_status'] === 'in_stock') {
                $where[] = 'p.stoc_curent > p.stoc_minim';
            } elseif ($filters['stock_status'] === 'low') {
                $where[] = 'p.stoc_curent > 0 AND p.stoc_curent <= p.stoc_minim';
            } elseif ($filters['stock_status'] === 'out') {
                $where[] = 'p.stoc_curent <= 0';
            }
        }
        if (!empty($filters['vehicle_type'])) {
            $where[] = 'FIND_IN_SET(:vehicle_type, p.tipuri_vehicul) > 0';
            $params[':vehicle_type'] = (string) $filters['vehicle_type'];
        }
        $stmt = $this->db->prepare(
            "SELECT p.*,
                    (p.stoc_curent * p.pret_achizitie) AS valoare_stoc,
                    CASE
                        WHEN p.stoc_curent <= 0 THEN 'out'
                        WHEN p.stoc_curent <= p.stoc_minim THEN 'low'
                        ELSE 'in_stock'
                    END AS stock_status
             FROM mentenanta_piese p
             WHERE " . implode(' AND ', $where) . "
             ORDER BY p.denumire ASC, p.id DESC"
        );
        $stmt->execute($params);
        $rows = array_map(fn (array $row): array => $this->decoratePartWarranty($row), $stmt->fetchAll());
        if (($filters['include_tires'] ?? true) !== false) {
            $rows = array_merge($rows, $this->getAvailableTireStockRows($filters));
        }
        usort($rows, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['denumire'] ?? ''), (string) ($right['denumire'] ?? ''));
        });
        return $rows;
    }

    public function getPart(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM mentenanta_piese WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->decoratePartWarranty($row) : null;
    }

    public function getAutoComponentPartIndex(): array
    {
        $stmt = $this->db->query(
            "SELECT id, cod_piesa, denumire, categorie, descriere, imagine_original, imagine_stocata,
                    garantie_piesa, garantie_manopera, interval_km, interval_luni, avertizare_km, avertizare_zile,
                    updated_at
             FROM mentenanta_piese
             WHERE status_piesa = 'activa'
             ORDER BY updated_at DESC, id DESC"
        );

        $index = [
            'by_name' => [],
            'by_category_name' => [],
        ];
        foreach ($stmt->fetchAll() as $row) {
            $row = $this->decoratePartWarranty($row);
            $nameKey = $this->autoPartLookupKey((string) ($row['denumire'] ?? ''));
            if ($nameKey === '') {
                continue;
            }

            $categoryKey = $this->autoPartLookupKey((string) ($row['categorie'] ?? ''));
            if ($categoryKey !== '') {
                $index['by_category_name'][$categoryKey . '|' . $nameKey] ??= $row;
            }
            $index['by_name'][$nameKey] ??= $row;
        }

        return $index;
    }

    public function syncAutoComponentsToStock(array $categories): int
    {
        $existing = [];
        $stmt = $this->db->query("SELECT cod_piesa, denumire, categorie FROM mentenanta_piese");
        foreach ($stmt->fetchAll() as $row) {
            $code = mb_strtolower(trim((string) ($row['cod_piesa'] ?? '')), 'UTF-8');
            if ($code !== '') {
                $existing['code:' . $code] = true;
            }
            $nameKey = $this->autoPartLookupKey((string) ($row['categorie'] ?? '') . '|' . (string) ($row['denumire'] ?? ''));
            if ($nameKey !== '') {
                $existing['name:' . $nameKey] = true;
            }
        }

        $insert = $this->db->prepare(
            "INSERT INTO mentenanta_piese
                (cod_piesa, denumire, categorie, unitate_masura, descriere, mod_utilizare,
                 stoc_curent, stoc_minim, pret_achizitie, tipuri_vehicul, sisteme_componente,
                 pentru_mentenanta, interval_km, interval_luni, avertizare_km, avertizare_zile,
                 status_piesa, created_at, updated_at)
             VALUES
                (:cod_piesa, :denumire, :categorie, 'buc', :descriere, 'stoc',
                 0, 0, 0, :tipuri_vehicul, :sisteme_componente,
                 1, :interval_km, :interval_luni, :avertizare_km, :avertizare_zile,
                 'activa', :created_at, :updated_at)"
        );

        $now = date('Y-m-d H:i:s');
        $created = 0;
        foreach ($categories as $category) {
            $categoryName = (string) ($category['name'] ?? '');
            $categoryId = (int) ($category['id'] ?? 0);
            $types = $this->autoDefaultVehicleTypesForCategory($categoryId);
            foreach ((array) ($category['components'] ?? []) as $component) {
                $code = trim((string) ($component['code'] ?? ''));
                $name = trim((string) ($component['name'] ?? ''));
                if ($code === '' || $name === '' || $categoryName === '') {
                    continue;
                }

                $codeKey = 'code:' . mb_strtolower($code, 'UTF-8');
                $nameKey = 'name:' . $this->autoPartLookupKey($categoryName . '|' . $name);
                if (isset($existing[$codeKey]) || isset($existing[$nameKey])) {
                    continue;
                }

                $monitoring = is_array($component['monitoring_by_vehicle'] ?? null) ? $component['monitoring_by_vehicle'] : [];
                $hasTime = false;
                foreach ($monitoring as $value) {
                    $lower = mb_strtolower((string) $value, 'UTF-8');
                    if (str_contains($lower, 'data') || str_contains($lower, 'timp')) {
                        $hasTime = true;
                        break;
                    }
                }

                $insert->execute([
                    ':cod_piesa' => $code,
                    ':denumire' => $name,
                    ':categorie' => $categoryName,
                    ':descriere' => $this->nullIfEmpty((string) ($component['description'] ?? '')),
                    ':tipuri_vehicul' => implode(',', $types),
                    ':sisteme_componente' => $categoryName,
                    ':interval_km' => $hasTime ? null : 30000,
                    ':interval_luni' => $hasTime ? 12 : null,
                    ':avertizare_km' => $hasTime ? null : 25000,
                    ':avertizare_zile' => $hasTime ? 30 : null,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);

                $existing[$codeKey] = true;
                $existing[$nameKey] = true;
                $created++;
            }
        }

        return $created;
    }

    public function getAutoComponentConfigs(int $vehicleId): array
    {
        if ($vehicleId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM mentenanta_auto_configurari
             WHERE vehicle_id = :vehicle_id
             ORDER BY updated_at DESC, id DESC"
        );
        $stmt->execute([':vehicle_id' => $vehicleId]);

        $configs = [];
        foreach ($stmt->fetchAll() as $row) {
            $methods = json_decode((string) ($row['monitoring_methods'] ?? '[]'), true);
            $row['monitoring_methods'] = is_array($methods) ? $methods : [];
            $configs[(string) ($row['component_key'] ?? '')] = $row;
        }

        return $configs;
    }

    public function getAutoPartUsageForVehicle(int $vehicleId): array
    {
        if ($vehicleId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT u.*
             FROM mentenanta_piese_utilizari u
             INNER JOIN (
                SELECT part_id, MAX(id) AS latest_id
                FROM mentenanta_piese_utilizari
                WHERE vehicle_id = :vehicle_id
                GROUP BY part_id
             ) latest ON latest.latest_id = u.id
             ORDER BY u.id DESC"
        );
        $stmt->execute([':vehicle_id' => $vehicleId]);

        $usage = [];
        foreach ($stmt->fetchAll() as $row) {
            $partId = (int) ($row['part_id'] ?? 0);
            if ($partId > 0) {
                $usage[$partId] = $row;
            }
        }

        return $usage;
    }

    public function saveAutoComponentConfig(array $data, array $image = []): void
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $componentKey = trim((string) ($data['component_id'] ?? ''));
        $componentName = trim((string) ($data['component_name'] ?? ''));
        if ($vehicleId <= 0 || $componentKey === '' || $componentName === '') {
            throw new RuntimeException('Selecteaza vehiculul si componenta inainte de salvare.');
        }

        $methods = $this->normalizeAutoMonitoringMethods($data['monitoring_methods'] ?? []);
        $unit = $this->autoUnitForMonitoringType((string) ($data['monitoring_type'] ?? 'Kilometri'));
        $stockPartId = max(0, (int) ($data['stock_part_id'] ?? 0));
        $now = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "INSERT INTO mentenanta_auto_configurari
                    (vehicle_id, vehicle_type, primary_category, subcategory, category_id, component_key,
                     stock_part_id, component_name, component_code, description, monitoring_type,
                     monitoring_methods, interval_value, warning_value, critical_value, lifetime_value,
                     unit, repairable, repair_resets_lifetime, requires_calibration, notes, created_at, updated_at)
                 VALUES
                    (:vehicle_id, :vehicle_type, :primary_category, :subcategory, :category_id, :component_key,
                     :stock_part_id, :component_name, :component_code, :description, :monitoring_type,
                     :monitoring_methods, :interval_value, :warning_value, :critical_value, :lifetime_value,
                     :unit, :repairable, :repair_resets_lifetime, :requires_calibration, :notes, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE
                    vehicle_type = VALUES(vehicle_type),
                    primary_category = VALUES(primary_category),
                    subcategory = VALUES(subcategory),
                    category_id = VALUES(category_id),
                    stock_part_id = VALUES(stock_part_id),
                    component_name = VALUES(component_name),
                    component_code = VALUES(component_code),
                    description = VALUES(description),
                    monitoring_type = VALUES(monitoring_type),
                    monitoring_methods = VALUES(monitoring_methods),
                    interval_value = VALUES(interval_value),
                    warning_value = VALUES(warning_value),
                    critical_value = VALUES(critical_value),
                    lifetime_value = VALUES(lifetime_value),
                    unit = VALUES(unit),
                    repairable = VALUES(repairable),
                    repair_resets_lifetime = VALUES(repair_resets_lifetime),
                    requires_calibration = VALUES(requires_calibration),
                    notes = VALUES(notes),
                    updated_at = VALUES(updated_at)"
            )->execute([
                ':vehicle_id' => $vehicleId,
                ':vehicle_type' => trim((string) ($data['vehicle_type'] ?? '')),
                ':primary_category' => trim((string) ($data['primary_category'] ?? '')),
                ':subcategory' => trim((string) ($data['subcategory'] ?? '')),
                ':category_id' => (int) ($data['category_id'] ?? 0),
                ':component_key' => $componentKey,
                ':stock_part_id' => $stockPartId > 0 ? $stockPartId : null,
                ':component_name' => $componentName,
                ':component_code' => trim((string) ($data['component_code'] ?? '')),
                ':description' => $this->nullIfEmpty((string) ($data['description'] ?? '')),
                ':monitoring_type' => trim((string) ($data['monitoring_type'] ?? 'Kilometri')) ?: 'Kilometri',
                ':monitoring_methods' => json_encode($methods, JSON_UNESCAPED_UNICODE),
                ':interval_value' => trim((string) ($data['interval_value'] ?? '')),
                ':warning_value' => trim((string) ($data['warning_value'] ?? '')),
                ':critical_value' => trim((string) ($data['critical_value'] ?? '')),
                ':lifetime_value' => trim((string) ($data['lifetime_value'] ?? '')),
                ':unit' => $unit,
                ':repairable' => !empty($data['repairable']) ? 1 : 0,
                ':repair_resets_lifetime' => !empty($data['repair_resets_lifetime']) ? 1 : 0,
                ':requires_calibration' => !empty($data['requires_calibration']) ? 1 : 0,
                ':notes' => $this->nullIfEmpty((string) ($data['notes'] ?? '')),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            if ($stockPartId > 0) {
                $partFields = [
                    'descriere = :descriere',
                    'garantie_piesa = :garantie_piesa',
                    'garantie_manopera = :garantie_manopera',
                    'interval_km = :interval_km',
                    'interval_luni = :interval_luni',
                    'avertizare_km = :avertizare_km',
                    'avertizare_zile = :avertizare_zile',
                    'updated_at = :updated_at',
                ];
                $partParams = [
                    ':descriere' => $this->nullIfEmpty((string) ($data['description'] ?? '')),
                    ':garantie_piesa' => $this->nullIfEmpty((string) ($data['garantie_piesa'] ?? '')),
                    ':garantie_manopera' => $this->nullIfEmpty((string) ($data['garantie_manopera'] ?? '')),
                    ':interval_km' => $unit === 'km' ? $this->numberStringToInt($data['interval_value'] ?? null) : null,
                    ':interval_luni' => $unit === 'luni' ? $this->numberStringToInt($data['interval_value'] ?? null) : null,
                    ':avertizare_km' => $unit === 'km' ? $this->numberStringToInt($data['warning_value'] ?? null) : null,
                    ':avertizare_zile' => $unit === 'luni' ? $this->numberStringToInt($data['warning_value'] ?? null) : null,
                    ':updated_at' => $now,
                    ':part_id' => $stockPartId,
                ];

                if (!empty($image['stored'])) {
                    $partFields[] = 'imagine_original = :imagine_original';
                    $partFields[] = 'imagine_stocata = :imagine_stocata';
                    $partParams[':imagine_original'] = (string) ($image['original'] ?? '');
                    $partParams[':imagine_stocata'] = (string) $image['stored'];
                }

                $this->db->prepare(
                    "UPDATE mentenanta_piese SET " . implode(', ', $partFields) . " WHERE id = :part_id"
                )->execute($partParams);
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function getStockKpis(): array
    {
        $row = $this->db->query(
            "SELECT
                COALESCE(SUM(stoc_curent * pret_achizitie), 0) AS valoare_totala,
                COUNT(*) AS total_piese,
                SUM(CASE WHEN stoc_curent <= stoc_minim THEN 1 ELSE 0 END) AS sub_minim
             FROM mentenanta_piese WHERE mod_utilizare = 'stoc'"
        )->fetch() ?: [];
        $tireTotals = $this->db->query(
            "SELECT COUNT(*) AS total_tires, COALESCE(SUM(COALESCE(purchase_price, 0)), 0) AS tire_value
             FROM anvelope WHERE status IN ('in_stock','spare')"
        )->fetch() ?: [];
        $row['valoare_totala'] = (float) ($row['valoare_totala'] ?? 0) + (float) ($tireTotals['tire_value'] ?? 0);
        $row['total_piese'] = (int) ($row['total_piese'] ?? 0) + (int) ($tireTotals['total_tires'] ?? 0);
        $row['fara_miscare'] = (int) $this->db->query(
            "SELECT COUNT(*) FROM mentenanta_piese p
             WHERE p.mod_utilizare = 'stoc'
               AND NOT EXISTS (
                    SELECT 1 FROM mentenanta_piese_utilizari u
                    WHERE u.part_id = p.id AND u.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
               )"
        )->fetchColumn();
        $row['fara_miscare'] += (int) $this->db->query(
            "SELECT COUNT(*) FROM anvelope a
             WHERE a.status IN ('in_stock','spare')
               AND NOT EXISTS (
                    SELECT 1 FROM anvelope_istoric h
                    WHERE h.tire_id = a.id AND h.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
               )"
        )->fetchColumn();
        return $row;
    }

    public function getStockFilterOptions(): array
    {
        $categories = $this->db->query(
                "SELECT DISTINCT categorie FROM mentenanta_piese WHERE categorie <> '' ORDER BY categorie"
            )->fetchAll(PDO::FETCH_COLUMN);
        $categories[] = 'Anvelope';
        $categories = array_values(array_unique(array_filter(array_map('strval', $categories))));
        sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

        $suppliers = $this->db->query(
                "SELECT DISTINCT furnizor FROM mentenanta_piese WHERE COALESCE(furnizor, '') <> '' ORDER BY furnizor"
            )->fetchAll(PDO::FETCH_COLUMN);
        $tireSuppliers = $this->db->query(
            "SELECT DISTINCT supplier FROM anvelope
             WHERE status IN ('in_stock','spare') AND COALESCE(supplier, '') <> '' ORDER BY supplier"
        )->fetchAll(PDO::FETCH_COLUMN);
        $suppliers = array_values(array_unique(array_filter(array_merge($suppliers, $tireSuppliers))));
        sort($suppliers, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'categories' => $categories,
            'suppliers' => $suppliers,
        ];
    }

    private function getAvailableTireStockRows(array $filters): array
    {
        $category = trim((string) ($filters['categorie'] ?? ''));
        if ($category !== '' && $category !== 'Anvelope') {
            return [];
        }
        $stockStatus = trim((string) ($filters['stock_status'] ?? ''));
        if (in_array($stockStatus, ['low', 'out'], true)) {
            return [];
        }

        $where = ["a.status IN ('in_stock','spare')"];
        $params = [];
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = "CONCAT_WS(' ', a.serial_number, a.brand, a.model, a.tire_size, a.dot_code) LIKE :tire_search";
            $params[':tire_search'] = '%' . $search . '%';
        }
        $supplier = trim((string) ($filters['furnizor'] ?? ''));
        if ($supplier !== '') {
            $where[] = 'a.supplier = :tire_supplier';
            $params[':tire_supplier'] = $supplier;
        }
        $vehicleType = trim((string) ($filters['vehicle_type'] ?? ''));
        if ($vehicleType !== '') {
            $compatibleTypes = [$vehicleType];
            if ($vehicleType === 'semiremorca') {
                $compatibleTypes[] = 'semiremorca_primar';
                $compatibleTypes[] = 'semiremorca_distributie';
            }
            $clauses = ["a.target_vehicle_type = 'universal'"];
            foreach ($compatibleTypes as $index => $type) {
                $typeKey = ':tire_vehicle_type_' . $index;
                $listKey = ':tire_vehicle_list_' . $index;
                $clauses[] = 'a.target_vehicle_type = ' . $typeKey;
                $clauses[] = 'FIND_IN_SET(' . $listKey . ', a.target_vehicle_types) > 0';
                $params[$typeKey] = $type;
                $params[$listKey] = $type;
            }
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        $stmt = $this->db->prepare(
            "SELECT a.* FROM anvelope a WHERE " . implode(' AND ', $where) . "
             ORDER BY a.brand ASC, a.model ASC, a.serial_number ASC"
        );
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll() as $tire) {
            $name = trim(implode(' ', array_filter([
                (string) ($tire['brand'] ?? ''),
                (string) ($tire['model'] ?? ''),
                (string) ($tire['tire_size'] ?? ''),
            ])));
            $targetTypes = trim((string) ($tire['target_vehicle_types'] ?? ''));
            if ($targetTypes === '') {
                $targetTypes = (string) ($tire['target_vehicle_type'] ?? 'universal');
            }
            $price = max(0, (float) ($tire['purchase_price'] ?? 0));
            $rows[] = [
                'id' => 'tire_' . (int) $tire['id'],
                'inventory_type' => 'tire',
                'tire_id' => (int) $tire['id'],
                'cod_piesa' => (string) ($tire['serial_number'] ?? ('ANV-' . (int) $tire['id'])),
                'denumire' => $name !== '' ? $name : 'Anvelopă',
                'categorie' => 'Anvelope',
                'cod_oem' => (string) ($tire['dot_code'] ?? ''),
                'tipuri_vehicul' => $targetTypes,
                'modele_vehicul' => (string) ($tire['usage_compatibility'] ?? ''),
                'sisteme_componente' => 'Anvelope / tren rulare',
                'stoc_curent' => 1.0,
                'stoc_minim' => 0.0,
                'unitate_masura' => 'buc',
                'pret_achizitie' => $price,
                'valoare_stoc' => $price,
                'furnizor' => (string) ($tire['supplier'] ?? ''),
                'locatie_depozit' => (string) ($tire['location_label'] ?? 'Rezervă'),
                'stock_status' => 'in_stock',
                'status_piesa' => 'activa',
                'garantie_piesa' => '',
                'garantie_manopera' => '',
                'warranty_status' => 'red',
                'warranty_label' => 'Fara garantie',
                'tire_status' => (string) ($tire['status'] ?? ''),
                'descriere' => (string) ($tire['notes'] ?? ''),
            ];
        }
        return $rows;
    }

    public function savePart(array $data, array $documents, ?int $createdBy = null): int
    {
        $this->db->beginTransaction();
        try {
            $mode = ($data['usage_destination'] ?? 'stock') === 'direct' ? 'direct' : 'stoc';
            $values = [
                ':cod_piesa' => trim((string) $data['cod_piesa']),
                ':denumire' => trim((string) $data['denumire']),
                ':categorie' => trim((string) $data['categorie']),
                ':producator' => $this->nullIfEmpty((string) ($data['producator'] ?? '')),
                ':cod_oem' => $this->nullIfEmpty((string) ($data['cod_oem'] ?? '')),
                ':unitate_masura' => trim((string) ($data['unitate_masura'] ?? 'buc')) ?: 'buc',
                ':descriere' => $this->nullIfEmpty((string) ($data['descriere'] ?? '')),
                ':mod_utilizare' => $mode,
                ':stoc_curent' => $mode === 'stoc' ? max(0, (float) ($data['stoc_initial'] ?? 0)) : 0,
                ':stoc_minim' => $mode === 'stoc' ? max(0, (float) ($data['stoc_minim'] ?? 0)) : 0,
                ':pret_achizitie' => max(0, (float) ($data['pret_achizitie'] ?? ($data['cost'] ?? 0))),
                ':furnizor' => $this->nullIfEmpty((string) ($data['furnizor'] ?? '')),
                ':locatie_depozit' => $mode === 'stoc' ? $this->nullIfEmpty((string) ($data['locatie_depozit'] ?? '')) : null,
                ':tipuri_vehicul' => implode(',', $this->normalizeList($data['tipuri_vehicul'] ?? [])),
                ':modele_vehicul' => implode(',', $this->normalizeList($data['modele_vehicul'] ?? [])),
                ':sisteme_componente' => implode(',', $this->normalizeList($data['sisteme_componente'] ?? [])),
                ':pentru_mentenanta' => !empty($data['pentru_mentenanta']) ? 1 : 0,
                ':interval_km' => $this->positiveIntOrNull($data['interval_km'] ?? null),
                ':interval_luni' => $this->positiveIntOrNull($data['interval_luni'] ?? null),
                ':avertizare_km' => $this->positiveIntOrNull($data['avertizare_km'] ?? null),
                ':avertizare_zile' => $this->positiveIntOrNull($data['avertizare_zile'] ?? null),
                ':garantie_piesa' => $this->nullIfEmpty((string) ($data['garantie_piesa'] ?? '')),
                ':garantie_manopera' => $this->nullIfEmpty((string) ($data['garantie_manopera'] ?? '')),
                ':factura_original' => $documents['invoice']['original'] ?? null,
                ':factura_stocata' => $documents['invoice']['stored'] ?? null,
                ':fisa_original' => $documents['technical']['original'] ?? null,
                ':fisa_stocata' => $documents['technical']['stored'] ?? null,
                ':imagine_original' => $documents['image']['original'] ?? null,
                ':imagine_stocata' => $documents['image']['stored'] ?? null,
                ':status_piesa' => ($data['status_piesa'] ?? 'activa') === 'inactiva' ? 'inactiva' : 'activa',
                ':created_at' => date('Y-m-d H:i:s'),
                ':updated_at' => date('Y-m-d H:i:s'),
            ];
            $this->db->prepare(
                "INSERT INTO mentenanta_piese
                    (cod_piesa, denumire, categorie, producator, cod_oem, unitate_masura, descriere,
                     mod_utilizare, stoc_curent, stoc_minim, pret_achizitie, furnizor, locatie_depozit,
                     tipuri_vehicul, modele_vehicul, sisteme_componente, pentru_mentenanta,
                     interval_km, interval_luni, avertizare_km, avertizare_zile, garantie_piesa, garantie_manopera,
                     factura_original, factura_stocata, fisa_original, fisa_stocata,
                     imagine_original, imagine_stocata, status_piesa, created_at, updated_at)
                 VALUES
                    (:cod_piesa, :denumire, :categorie, :producator, :cod_oem, :unitate_masura, :descriere,
                     :mod_utilizare, :stoc_curent, :stoc_minim, :pret_achizitie, :furnizor, :locatie_depozit,
                     :tipuri_vehicul, :modele_vehicul, :sisteme_componente, :pentru_mentenanta,
                     :interval_km, :interval_luni, :avertizare_km, :avertizare_zile, :garantie_piesa, :garantie_manopera,
                     :factura_original, :factura_stocata, :fisa_original, :fisa_stocata,
                     :imagine_original, :imagine_stocata, :status_piesa, :created_at, :updated_at)"
            )->execute($values);
            $partId = (int) $this->db->lastInsertId();

            if ($mode === 'direct') {
                $vehicleId = (int) ($data['mount_vehicle_id'] ?? 0);
                if ($vehicleId <= 0) {
                    throw new RuntimeException('Selectează vehiculul pe care se montează piesa.');
                }
                $mountDate = (string) ($data['mount_date'] ?? date('Y-m-d'));
                $cost = max(0, (float) ($data['cost'] ?? $values[':pret_achizitie']));
                $recordType = 'intretinere';
                $scheduledId = !empty($data['scheduled_intervention_id']) ? (int) $data['scheduled_intervention_id'] : null;
                if ($scheduledId !== null) {
                    $scheduled = $this->getScheduledIntervention($scheduledId);
                    if ($scheduled !== null) {
                        $recordType = $this->normalizeRecordType((string) $scheduled['tip_interventie']);
                    }
                }
                $recordStmt = $this->db->prepare(
                    "INSERT INTO mentenanta
                        (vehicle_id, tip_interventie, record_type, centru_cost, descriere, status_interventie,
                         data_interventie, km_interventie, cost, cost_manopera, cost_piese,
                         atelier, furnizor_piesa, piese_utilizate, observatii, created_at, updated_at)
                     VALUES
                        (:vehicle_id, :tip, :record_type, :center, :description, 'finalizata',
                         :mount_date, :km, :cost_total, 0, :cost_parts, :mounted_by, :supplier, :part_name, :notes, :created_at, :updated_at)"
                );
                $recordStmt->execute([
                    ':vehicle_id' => $vehicleId,
                    ':tip' => 'Montare piesă: ' . (string) $values[':denumire'],
                    ':record_type' => $recordType,
                    ':center' => trim((string) ($data['categorie'] ?? 'Consumabile')),
                    ':description' => 'Montare directă pe vehicul',
                    ':mount_date' => $mountDate,
                    ':km' => $this->positiveIntOrNull($data['mount_km'] ?? null),
                    ':cost_total' => $cost,
                    ':cost_parts' => $cost,
                    ':mounted_by' => $this->nullIfEmpty((string) ($data['mounted_by'] ?? '')),
                    ':supplier' => $values[':furnizor'],
                    ':part_name' => $values[':denumire'],
                    ':notes' => $this->nullIfEmpty((string) ($data['mount_notes'] ?? '')),
                    ':created_at' => date('Y-m-d H:i:s'),
                    ':updated_at' => date('Y-m-d H:i:s'),
                ]);
                $maintenanceId = (int) $this->db->lastInsertId();
                $usageStmt = $this->db->prepare(
                    "INSERT INTO mentenanta_piese_utilizari
                        (part_id, maintenance_id, scheduled_intervention_id, vehicle_id, cantitate,
                         cost_unitar, data_montare, km_montare, montata_de, observatii, direct_mount, created_at)
                     VALUES
                        (:part_id, :maintenance_id, :scheduled_id, :vehicle_id, 1,
                         :cost, :mount_date, :km, :mounted_by, :notes, 1, :created_at)"
                );
                $usageStmt->execute([
                    ':part_id' => $partId,
                    ':maintenance_id' => $maintenanceId,
                    ':scheduled_id' => $scheduledId,
                    ':vehicle_id' => $vehicleId,
                    ':cost' => $cost,
                    ':mount_date' => $mountDate,
                    ':km' => $this->positiveIntOrNull($data['mount_km'] ?? null),
                    ':mounted_by' => $this->nullIfEmpty((string) ($data['mounted_by'] ?? '')),
                    ':notes' => $this->nullIfEmpty((string) ($data['mount_notes'] ?? '')),
                    ':created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $this->db->commit();
            return $partId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function convertScheduledIntervention(int $id): void
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM mentenanta_interventii_programate WHERE id = :id FOR UPDATE"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row || !empty($row['converted_maintenance_id'])) {
            return;
        }
        $insert = $this->db->prepare(
            "INSERT INTO mentenanta
                (vehicle_id, tip_interventie, record_type, centru_cost, descriere, status_interventie,
                 data_interventie, cost, cost_manopera, cost_piese, atelier, observatii,
                 source_intervention_id, created_at, updated_at)
             VALUES
                (:vehicle_id, :tip, :record_type, :center, :description, 'finalizata',
                 :date, :cost_total, :cost_labor, 0, :supplier, :notes, :source_id, :created_at, :updated_at)"
        );
        $insert->execute([
            ':vehicle_id' => (int) $row['vehicle_id'],
            ':tip' => trim((string) $row['descriere']) ?: 'Intervenție programată',
            ':record_type' => $this->normalizeRecordType((string) $row['tip_interventie']),
            ':center' => $this->nullIfEmpty((string) ($row['centru_cost'] ?? '')),
            ':description' => trim((string) $row['descriere']),
            ':notes' => trim((string) $row['descriere']),
            ':date' => (string) $row['data_programata'],
            ':cost_total' => max(0, (float) $row['cost_estimat']),
            ':cost_labor' => max(0, (float) $row['cost_estimat']),
            ':supplier' => $this->nullIfEmpty((string) ($row['furnizor'] ?? '')),
            ':source_id' => $id,
            ':created_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s'),
        ]);
        $maintenanceId = (int) $this->db->lastInsertId();
        $this->db->prepare(
            "UPDATE mentenanta_interventii_programate
             SET converted_maintenance_id = :maintenance_id, updated_at = :updated_at WHERE id = :id"
        )->execute([
            ':maintenance_id' => $maintenanceId,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => $id,
        ]);
    }

    private function buildCorrectiveInvoiceWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'si.invoice_date >= :invoice_date_from';
            $params[':invoice_date_from'] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'si.invoice_date <= :invoice_date_to';
            $params[':invoice_date_to'] = (string) $filters['date_to'];
        }
        if (!empty($filters['vehicle_id'])) {
            $where[] = "EXISTS (
                SELECT 1 FROM invoice_vehicle_repairs ivr_filter
                WHERE ivr_filter.invoice_id = si.id AND ivr_filter.vehicle_id = :invoice_vehicle_id
            )";
            $params[':invoice_vehicle_id'] = (int) $filters['vehicle_id'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['draft', 'in_progress', 'finalizata', 'anulata'], true)) {
            $where[] = 'si.status = :invoice_status';
            $params[':invoice_status'] = (string) $filters['status'];
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = "(
                si.invoice_number LIKE :invoice_search
                OR si.supplier_name LIKE :invoice_search
                OR EXISTS (
                    SELECT 1
                    FROM invoice_vehicle_repairs ivr_search
                    INNER JOIN vehicule v_search ON v_search.id = ivr_search.vehicle_id
                    WHERE ivr_search.invoice_id = si.id
                      AND v_search.nr_inmatriculare LIKE :invoice_search
                )
            )";
            $params[':invoice_search'] = '%' . $search . '%';
        }

        return [$where, $params];
    }

    private function buildRecordWhere(array $filters, ?string $recordType = null): array
    {
        // Tire lifecycle records remain available in the dedicated Anvelope page.
        $where = ["m.tip_interventie NOT LIKE 'Anvelopa - %'"];
        $params = [];
        if (!empty($filters['date_from'])) {
            $where[] = 'm.data_interventie >= :date_from';
            $params[':date_from'] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'm.data_interventie <= :date_to';
            $params[':date_to'] = (string) $filters['date_to'];
        }
        if (!empty($filters['vehicle_id'])) {
            $where[] = 'm.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = (int) $filters['vehicle_id'];
        }
        $type = $recordType ?? ($filters['record_type'] ?? null);
        if (in_array($type, ['intretinere', 'reparatie'], true)) {
            $where[] = 'm.record_type = :record_type';
            $params[':record_type'] = $type;
        }
        if (!empty($filters['centru_cost'])) {
            $where[] = 'm.centru_cost = :centru_cost';
            $params[':centru_cost'] = (string) $filters['centru_cost'];
        }
        if (!empty($filters['technical_category_id'])) {
            $where[] = 'm.technical_category_id = :technical_category_id';
            $params[':technical_category_id'] = (int) $filters['technical_category_id'];
        }
        return [$where, $params];
    }

    private function getPersistedCostCenterNames(): array
    {
        $names = [];
        foreach (self::COST_CENTERS as $center) {
            $names[(string) $center] = (string) $center;
        }

        foreach ([
            'SELECT DISTINCT centru_cost FROM mentenanta WHERE COALESCE(centru_cost, \'\') <> \'\'',
            'SELECT DISTINCT centru_cost FROM mentenanta_interventii_programate WHERE COALESCE(centru_cost, \'\') <> \'\'',
            'SELECT DISTINCT categorie AS centru_cost FROM mentenanta_piese WHERE COALESCE(categorie, \'\') <> \'\'',
        ] as $sql) {
            foreach ($this->db->query($sql)->fetchAll() as $row) {
                $name = trim((string) ($row['centru_cost'] ?? ''));
                if ($name !== '') {
                    $names[$name] = $name;
                }
            }
        }

        ksort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($names);
    }

    private function decoratePartWarranty(array $row): array
    {
        $hasPartWarranty = trim((string) ($row['garantie_piesa'] ?? '')) !== '';
        $hasLaborWarranty = trim((string) ($row['garantie_manopera'] ?? '')) !== '';

        if ($hasPartWarranty && $hasLaborWarranty) {
            $row['warranty_status'] = 'green';
            $row['warranty_label'] = 'Garantie completa';
        } elseif ($hasPartWarranty || $hasLaborWarranty) {
            $row['warranty_status'] = 'yellow';
            $row['warranty_label'] = 'Garantie partiala';
        } else {
            $row['warranty_status'] = 'red';
            $row['warranty_label'] = 'Fara garantie';
        }

        return $row;
    }

    private function autoPartLookupKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/iu', '', $value) ?? '';
        return $value;
    }

    private function autoDefaultVehicleTypesForCategory(int $categoryId): array
    {
        if ($categoryId >= 1 && $categoryId <= 9) {
            return ['camion', 'cap_tractor', 'semiremorca'];
        }
        if ($categoryId === 10 || ($categoryId >= 11 && $categoryId <= 17)) {
            return ['camion', 'semiremorca'];
        }

        return ['camion'];
    }

    private function normalizeAutoMonitoringMethods(mixed $methods): array
    {
        if (is_string($methods)) {
            $methods = preg_split('/[|,]/', $methods) ?: [];
        }
        if (!is_array($methods)) {
            $methods = [];
        }

        $clean = [];
        foreach ($methods as $method) {
            $method = trim((string) $method);
            if ($method !== '') {
                $clean[$method] = $method;
            }
        }

        return array_values($clean !== [] ? $clean : ['Kilometri']);
    }

    private function autoUnitForMonitoringType(string $type): string
    {
        return match ($type) {
            'Ore functionare' => 'ore',
            'Timp luni/ani' => 'luni',
            default => 'km',
        };
    }

    private function numberStringToInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(['.', ',', ' '], ['', '.', ''], $value);
        if (!is_numeric($value)) {
            return null;
        }

        $number = (int) round((float) $value);
        return $number > 0 ? $number : null;
    }

    private function buildActiveEnsembleCosts(array $vehicleCosts): array
    {
        $costByVehicle = [];
        $labels = [];
        foreach ($vehicleCosts as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            $costByVehicle[$vehicleId] = (float) ($row['total'] ?? 0);
            $labels[$vehicleId] = (string) ($row['label'] ?? '');
        }
        $couplings = $this->db->query(
            "SELECT c.tractor_id, c.semiremorca_id,
                    vt.nr_inmatriculare AS tractor_label, vs.nr_inmatriculare AS trailer_label
             FROM vehicule_cuplaje c
             INNER JOIN vehicule vt ON vt.id = c.tractor_id
             INNER JOIN vehicule vs ON vs.id = c.semiremorca_id
             WHERE c.activ = 1 AND c.data_end IS NULL"
        )->fetchAll();
        $result = [];
        foreach ($couplings as $coupling) {
            $tractorId = (int) $coupling['tractor_id'];
            $trailerId = (int) $coupling['semiremorca_id'];
            $result[] = [
                'label' => (string) $coupling['tractor_label'] . ' + ' . (string) $coupling['trailer_label'],
                'total' => ($costByVehicle[$tractorId] ?? 0.0) + ($costByVehicle[$trailerId] ?? 0.0),
            ];
        }
        usort($result, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        return array_slice($result, 0, 3);
    }

    private function lockStockPart(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM mentenanta_piese
             WHERE id = :id AND mod_utilizare = 'stoc' AND status_piesa = 'activa'
             FOR UPDATE"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function decreaseStockAndCreateUsage(
        array $part,
        float $quantity,
        int $vehicleId,
        ?int $maintenanceId,
        ?int $scheduledId,
        string $date,
        int $km,
        string $mountedBy,
        string $notes,
        bool $direct
    ): void {
        $this->db->prepare(
            "UPDATE mentenanta_piese
             SET stoc_curent = stoc_curent - :quantity, updated_at = :updated_at WHERE id = :id"
        )->execute([
            ':quantity' => $quantity,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':id' => (int) $part['id'],
        ]);
        $this->db->prepare(
            "INSERT INTO mentenanta_piese_utilizari
                (part_id, maintenance_id, scheduled_intervention_id, vehicle_id, cantitate,
                 cost_unitar, data_montare, km_montare, montata_de, observatii, direct_mount, created_at)
             VALUES
                (:part_id, :maintenance_id, :scheduled_id, :vehicle_id, :quantity,
                 :unit_cost, :mount_date, :km, :mounted_by, :notes, :direct_mount, :created_at)"
        )->execute([
            ':part_id' => (int) $part['id'],
            ':maintenance_id' => $maintenanceId,
            ':scheduled_id' => $scheduledId,
            ':vehicle_id' => $vehicleId,
            ':quantity' => $quantity,
            ':unit_cost' => max(0, (float) ($part['pret_achizitie'] ?? 0)),
            ':mount_date' => $date,
            ':km' => $km > 0 ? $km : null,
            ':mounted_by' => $this->nullIfEmpty($mountedBy),
            ':notes' => $this->nullIfEmpty($notes),
            ':direct_mount' => $direct ? 1 : 0,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function ensureSchema(): void
    {
        $migration = dirname(__DIR__, 2) . '/database/update_maintenance_module_v2.sql';
        if (!is_file($migration)) {
            throw new RuntimeException('Migrarea pentru modulul Mentenanță lipsește.');
        }

        if (!$this->columnExists('mentenanta', 'record_type')) {
            $this->runMigrationScript((string) file_get_contents($migration));
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS mentenanta_interventii_programate (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                tip_interventie ENUM('intretinere','reparatie') NOT NULL,
                data_programata DATE NOT NULL,
                cost_estimat DECIMAL(12,2) NOT NULL DEFAULT 0,
                furnizor VARCHAR(190) NULL,
                driver_id INT UNSIGNED NULL,
                client VARCHAR(190) NULL,
                centru_cost VARCHAR(80) NULL,
                descriere TEXT NOT NULL,
                status_interventie ENUM('programata','confirmata','in_lucru','finalizata','anulata') NOT NULL DEFAULT 'programata',
                converted_maintenance_id INT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_ment_prog_vehicle (vehicle_id),
                INDEX idx_ment_prog_date (data_programata),
                INDEX idx_ment_prog_status (status_interventie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS mentenanta_piese (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cod_piesa VARCHAR(80) NOT NULL UNIQUE,
                denumire VARCHAR(190) NOT NULL,
                categorie VARCHAR(100) NOT NULL,
                producator VARCHAR(120) NULL,
                cod_oem VARCHAR(120) NULL,
                unitate_masura VARCHAR(30) NOT NULL DEFAULT 'buc',
                descriere TEXT NULL,
                mod_utilizare ENUM('stoc','direct') NOT NULL DEFAULT 'stoc',
                stoc_curent DECIMAL(12,2) NOT NULL DEFAULT 0,
                stoc_minim DECIMAL(12,2) NOT NULL DEFAULT 0,
                pret_achizitie DECIMAL(12,2) NOT NULL DEFAULT 0,
                furnizor VARCHAR(190) NULL,
                locatie_depozit VARCHAR(190) NULL,
                tipuri_vehicul TEXT NULL,
                modele_vehicul TEXT NULL,
                sisteme_componente TEXT NULL,
                pentru_mentenanta TINYINT(1) NOT NULL DEFAULT 0,
                interval_km INT UNSIGNED NULL,
                interval_luni SMALLINT UNSIGNED NULL,
                avertizare_km INT UNSIGNED NULL,
                avertizare_zile SMALLINT UNSIGNED NULL,
                garantie_piesa VARCHAR(120) NULL,
                garantie_manopera VARCHAR(120) NULL,
                factura_original VARCHAR(255) NULL,
                factura_stocata VARCHAR(255) NULL,
                fisa_original VARCHAR(255) NULL,
                fisa_stocata VARCHAR(255) NULL,
                imagine_original VARCHAR(255) NULL,
                imagine_stocata VARCHAR(255) NULL,
                status_piesa ENUM('activa','inactiva') NOT NULL DEFAULT 'activa',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $partColumns = [
            'garantie_piesa' => "VARCHAR(120) NULL AFTER avertizare_zile",
            'garantie_manopera' => "VARCHAR(120) NULL AFTER garantie_piesa",
        ];
        foreach ($partColumns as $column => $definition) {
            if (!$this->columnExists('mentenanta_piese', $column)) {
                $this->db->exec("ALTER TABLE mentenanta_piese ADD COLUMN `" . $column . "` " . $definition);
            }
        }
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS mentenanta_piese_utilizari (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                part_id INT UNSIGNED NOT NULL,
                maintenance_id INT UNSIGNED NULL,
                scheduled_intervention_id INT UNSIGNED NULL,
                vehicle_id INT UNSIGNED NOT NULL,
                cantitate DECIMAL(12,2) NOT NULL DEFAULT 1,
                cost_unitar DECIMAL(12,2) NOT NULL DEFAULT 0,
                data_montare DATE NOT NULL,
                km_montare INT UNSIGNED NULL,
                montata_de VARCHAR(190) NULL,
                observatii TEXT NULL,
                direct_mount TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                INDEX idx_ment_usage_part (part_id),
                INDEX idx_ment_usage_vehicle (vehicle_id),
                INDEX idx_ment_usage_maintenance (maintenance_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS mentenanta_auto_configurari (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                vehicle_type VARCHAR(40) NOT NULL DEFAULT '',
                primary_category VARCHAR(40) NOT NULL DEFAULT '',
                subcategory VARCHAR(40) NOT NULL DEFAULT '',
                category_id INT UNSIGNED NOT NULL DEFAULT 0,
                component_key VARCHAR(80) NOT NULL,
                stock_part_id INT UNSIGNED NULL,
                component_name VARCHAR(190) NOT NULL,
                component_code VARCHAR(80) NULL,
                description TEXT NULL,
                monitoring_type VARCHAR(40) NOT NULL DEFAULT 'Kilometri',
                monitoring_methods TEXT NULL,
                interval_value VARCHAR(40) NULL,
                warning_value VARCHAR(40) NULL,
                critical_value VARCHAR(40) NULL,
                lifetime_value VARCHAR(40) NULL,
                unit VARCHAR(20) NOT NULL DEFAULT 'km',
                repairable TINYINT(1) NOT NULL DEFAULT 1,
                repair_resets_lifetime TINYINT(1) NOT NULL DEFAULT 0,
                requires_calibration TINYINT(1) NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_auto_config_vehicle_component (vehicle_id, component_key),
                INDEX idx_auto_config_vehicle (vehicle_id),
                INDEX idx_auto_config_part (stock_part_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->ensureCorrectiveInvoiceSchema();
        $technicalRecordColumns = [
            'technical_category_id' => "INT UNSIGNED NULL AFTER centru_cost",
            'technical_component_id' => "INT UNSIGNED NULL AFTER technical_category_id",
            'technical_health_percent' => "TINYINT UNSIGNED NULL AFTER technical_component_id",
        ];
        foreach ($technicalRecordColumns as $column => $definition) {
            if (!$this->columnExists('mentenanta', $column)) {
                $this->db->exec("ALTER TABLE mentenanta ADD COLUMN `" . $column . "` " . $definition);
            }
        }
        $this->backfillCorrectiveInvoicesFromRecords();
    }

    private function ensureCorrectiveInvoiceSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS supplier_invoices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT UNSIGNED NULL,
                supplier_name VARCHAR(190) NULL,
                invoice_number VARCHAR(120) NOT NULL,
                invoice_date DATE NOT NULL,
                due_date DATE NULL,
                pdf_path VARCHAR(255) NULL,
                status ENUM('draft','in_progress','finalizata','anulata') NOT NULL DEFAULT 'finalizata',
                notes TEXT NULL,
                labour_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                parts_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
                vat_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                parts_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                grand_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                source_maintenance_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_supplier_invoices_number (invoice_number),
                UNIQUE KEY uk_supplier_invoices_source (source_maintenance_id),
                INDEX idx_supplier_invoices_supplier (supplier_id),
                INDEX idx_supplier_invoices_date (invoice_date),
                INDEX idx_supplier_invoices_status (status),
                CONSTRAINT fk_supplier_invoices_source_maintenance FOREIGN KEY (source_maintenance_id) REFERENCES mentenanta(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $invoiceColumns = [
            'supplier_name' => "VARCHAR(190) NULL AFTER supplier_id",
            'source_maintenance_id' => "INT UNSIGNED NULL AFTER grand_total",
        ];
        foreach ($invoiceColumns as $column => $definition) {
            if (!$this->columnExists('supplier_invoices', $column)) {
                $this->db->exec("ALTER TABLE supplier_invoices ADD COLUMN `" . $column . "` " . $definition);
            }
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS invoice_vehicle_repairs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                invoice_id INT UNSIGNED NOT NULL,
                vehicle_id INT UNSIGNED NOT NULL,
                km_at_repair INT UNSIGNED NULL,
                defect TEXT NULL,
                component_group_id INT UNSIGNED NULL,
                technical_category_id INT UNSIGNED NULL,
                technical_component_id INT UNSIGNED NULL,
                repair_description TEXT NULL,
                repair_status ENUM('in_asteptare','in_lucru','finalizata','anulata') NOT NULL DEFAULT 'finalizata',
                immobilization_days DECIMAL(6,2) NOT NULL DEFAULT 0,
                condition_after_percent TINYINT UNSIGNED NULL,
                labour_supplier_id INT UNSIGNED NULL,
                labour_supplier_name VARCHAR(190) NULL,
                labour_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
                source_maintenance_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_invoice_repairs_invoice (invoice_id),
                INDEX idx_invoice_repairs_vehicle (vehicle_id),
                INDEX idx_invoice_repairs_source (source_maintenance_id),
                CONSTRAINT fk_invoice_repairs_invoice FOREIGN KEY (invoice_id) REFERENCES supplier_invoices(id) ON DELETE CASCADE,
                CONSTRAINT fk_invoice_repairs_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
                CONSTRAINT fk_invoice_repairs_source_maintenance FOREIGN KEY (source_maintenance_id) REFERENCES mentenanta(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $repairColumns = [
            'labour_supplier_name' => "VARCHAR(190) NULL AFTER labour_supplier_id",
            'source_maintenance_id' => "INT UNSIGNED NULL AFTER labour_cost",
        ];
        foreach ($repairColumns as $column => $definition) {
            if (!$this->columnExists('invoice_vehicle_repairs', $column)) {
                $this->db->exec("ALTER TABLE invoice_vehicle_repairs ADD COLUMN `" . $column . "` " . $definition);
            }
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS invoice_repair_parts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                repair_id INT UNSIGNED NOT NULL,
                part_name VARCHAR(190) NOT NULL,
                part_code VARCHAR(100) NULL,
                stock_part_id INT UNSIGNED NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
                unit_price_without_vat DECIMAL(12,2) NOT NULL DEFAULT 0,
                discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
                value_without_vat DECIMAL(12,2) NOT NULL DEFAULT 0,
                vat_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
                vat_value DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_with_vat DECIMAL(12,2) NOT NULL DEFAULT 0,
                part_supplier_id INT UNSIGNED NULL,
                part_supplier_name VARCHAR(190) NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_invoice_parts_repair (repair_id),
                INDEX idx_invoice_parts_stock (stock_part_id),
                INDEX idx_invoice_parts_supplier (part_supplier_id),
                CONSTRAINT fk_invoice_parts_repair FOREIGN KEY (repair_id) REFERENCES invoice_vehicle_repairs(id) ON DELETE CASCADE,
                CONSTRAINT fk_invoice_parts_stock FOREIGN KEY (stock_part_id) REFERENCES mentenanta_piese(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if (!$this->columnExists('invoice_repair_parts', 'part_supplier_name')) {
            $this->db->exec("ALTER TABLE invoice_repair_parts ADD COLUMN part_supplier_name VARCHAR(190) NULL AFTER part_supplier_id");
        }
    }

    private function backfillCorrectiveInvoicesFromRecords(): void
    {
        static $backfilled = false;
        if ($backfilled) {
            return;
        }
        $backfilled = true;

        $records = $this->db->query(
            "SELECT *
             FROM mentenanta
             WHERE record_type = 'reparatie'
               AND tip_interventie NOT LIKE 'Anvelopa - %'
             ORDER BY id ASC"
        )->fetchAll();

        if ($records === []) {
            return;
        }

        $existingStmt = $this->db->prepare(
            "SELECT id FROM supplier_invoices WHERE source_maintenance_id = :source_id LIMIT 1"
        );
        $insertInvoice = $this->db->prepare(
            "INSERT INTO supplier_invoices
                (supplier_id, supplier_name, invoice_number, invoice_date, due_date, pdf_path, status, notes,
                 labour_total, parts_subtotal, vat_total, parts_total, grand_total, source_maintenance_id,
                 created_at, updated_at)
             VALUES
                (NULL, :supplier_name, :invoice_number, :invoice_date, :due_date, :pdf_path, :status, :notes,
                 :labour_total, :parts_subtotal, :vat_total, :parts_total, :grand_total, :source_maintenance_id,
                 :created_at, :updated_at)"
        );
        $updateInvoice = $this->db->prepare(
            "UPDATE supplier_invoices SET
                supplier_name = :supplier_name,
                invoice_number = :invoice_number,
                invoice_date = :invoice_date,
                due_date = :due_date,
                pdf_path = :pdf_path,
                status = :status,
                notes = :notes,
                labour_total = :labour_total,
                parts_subtotal = :parts_subtotal,
                vat_total = :vat_total,
                parts_total = :parts_total,
                grand_total = :grand_total,
                updated_at = :updated_at
             WHERE id = :id"
        );
        $insertRepair = $this->db->prepare(
            "INSERT INTO invoice_vehicle_repairs
                (invoice_id, vehicle_id, km_at_repair, defect, component_group_id, technical_category_id,
                 technical_component_id, repair_description, repair_status, immobilization_days,
                 condition_after_percent, labour_supplier_id, labour_supplier_name, labour_cost,
                 source_maintenance_id, created_at, updated_at)
             VALUES
                (:invoice_id, :vehicle_id, :km_at_repair, :defect, NULL, :technical_category_id,
                 :technical_component_id, :repair_description, :repair_status, :immobilization_days,
                 :condition_after_percent, NULL, :labour_supplier_name, :labour_cost,
                 :source_maintenance_id, :created_at, :updated_at)"
        );
        $usageStmt = $this->db->prepare(
            "SELECT u.*, p.cod_piesa, p.denumire, p.furnizor
             FROM mentenanta_piese_utilizari u
             LEFT JOIN mentenanta_piese p ON p.id = u.part_id
             WHERE u.maintenance_id = :maintenance_id
             ORDER BY u.id ASC"
        );
        $insertPart = $this->db->prepare(
            "INSERT INTO invoice_repair_parts
                (repair_id, part_name, part_code, stock_part_id, quantity, unit_price_without_vat,
                 discount_percent, value_without_vat, vat_percent, vat_value, total_with_vat,
                 part_supplier_id, part_supplier_name, notes, created_at, updated_at)
             VALUES
                (:repair_id, :part_name, :part_code, :stock_part_id, :quantity, :unit_price_without_vat,
                 :discount_percent, :value_without_vat, :vat_percent, :vat_value, :total_with_vat,
                 NULL, :part_supplier_name, :notes, :created_at, :updated_at)"
        );

        foreach ($records as $record) {
            $recordId = (int) ($record['id'] ?? 0);
            if ($recordId <= 0) {
                continue;
            }

            $total = max(0.0, (float) ($record['cost'] ?? 0));
            $labourTotal = max(0.0, (float) ($record['cost_manopera'] ?? 0));
            $partsTotal = max(0.0, (float) ($record['cost_piese'] ?? 0));
            if ($labourTotal <= 0 && $partsTotal <= 0) {
                $labourTotal = $total;
            }
            if ($total <= 0 || abs($total - ($labourTotal + $partsTotal)) > 0.01) {
                $total = $labourTotal + $partsTotal;
            }

            $supplierName = trim((string) ($record['atelier'] ?? ''));
            if ($supplierName === '') {
                $supplierName = trim((string) ($record['furnizor_piesa'] ?? ''));
            }
            if ($supplierName === '') {
                $supplierName = 'Furnizor nespecificat';
            }

            $invoiceDate = (string) ($record['data_interventie'] ?? date('Y-m-d'));
            $dueDate = $invoiceDate;
            try {
                $dueDate = (new DateTimeImmutable($invoiceDate))->modify('+30 days')->format('Y-m-d');
            } catch (Throwable) {
                $dueDate = date('Y-m-d', strtotime('+30 days'));
            }

            $status = match ((string) ($record['status_interventie'] ?? 'finalizata')) {
                'in_lucru', 'in_asteptare' => 'in_progress',
                'anulata' => 'anulata',
                default => 'finalizata',
            };
            $now = date('Y-m-d H:i:s');
            $invoiceNumber = 'FAV-BU-' . str_pad((string) $recordId, 9, '0', STR_PAD_LEFT);

            $existingStmt->execute([':source_id' => $recordId]);
            $invoiceId = (int) ($existingStmt->fetchColumn() ?: 0);
            $invoiceValues = [
                ':supplier_name' => $supplierName,
                ':invoice_number' => $invoiceNumber,
                ':invoice_date' => $invoiceDate,
                ':due_date' => $dueDate,
                ':pdf_path' => $this->nullIfEmpty((string) ($record['fisier_stocat'] ?? '')),
                ':status' => $status,
                ':notes' => $this->nullIfEmpty((string) ($record['observatii'] ?? '')),
                ':labour_total' => $labourTotal,
                ':parts_subtotal' => $partsTotal,
                ':vat_total' => 0,
                ':parts_total' => $partsTotal,
                ':grand_total' => $total,
                ':updated_at' => $now,
            ];

            if ($invoiceId > 0) {
                $this->db->prepare(
                    "DELETE p FROM invoice_repair_parts p
                     INNER JOIN invoice_vehicle_repairs r ON r.id = p.repair_id
                     WHERE r.invoice_id = :invoice_id"
                )->execute([':invoice_id' => $invoiceId]);
                $this->db->prepare("DELETE FROM invoice_vehicle_repairs WHERE invoice_id = :invoice_id")
                    ->execute([':invoice_id' => $invoiceId]);
                $updateInvoice->execute($invoiceValues + [':id' => $invoiceId]);
            } else {
                $insertInvoice->execute($invoiceValues + [
                    ':source_maintenance_id' => $recordId,
                    ':created_at' => (string) ($record['created_at'] ?? $now),
                ]);
                $invoiceId = (int) $this->db->lastInsertId();
            }

            $defect = trim((string) ($record['descriere'] ?? ''));
            if ($defect === '') {
                $defect = trim((string) ($record['observatii'] ?? ''));
            }
            if ($defect === '') {
                $defect = trim((string) ($record['tip_interventie'] ?? 'Reparație'));
            }

            $insertRepair->execute([
                ':invoice_id' => $invoiceId,
                ':vehicle_id' => (int) $record['vehicle_id'],
                ':km_at_repair' => !empty($record['km_interventie']) ? (int) $record['km_interventie'] : null,
                ':defect' => $defect,
                ':technical_category_id' => !empty($record['technical_category_id']) ? (int) $record['technical_category_id'] : null,
                ':technical_component_id' => !empty($record['technical_component_id']) ? (int) $record['technical_component_id'] : null,
                ':repair_description' => trim((string) ($record['tip_interventie'] ?? '')),
                ':repair_status' => $this->normalizeRecordStatus((string) ($record['status_interventie'] ?? 'finalizata')),
                ':immobilization_days' => max(0, (float) ($record['zile_imobilizare'] ?? 0)),
                ':condition_after_percent' => ($record['technical_health_percent'] ?? '') !== '' ? (int) $record['technical_health_percent'] : null,
                ':labour_supplier_name' => $this->nullIfEmpty((string) ($record['atelier'] ?? '')),
                ':labour_cost' => $labourTotal,
                ':source_maintenance_id' => $recordId,
                ':created_at' => (string) ($record['created_at'] ?? $now),
                ':updated_at' => $now,
            ]);
            $repairId = (int) $this->db->lastInsertId();

            $usageStmt->execute([':maintenance_id' => $recordId]);
            $usedParts = $usageStmt->fetchAll();
            foreach ($usedParts as $partRow) {
                $quantity = max(0.01, (float) ($partRow['cantitate'] ?? 1));
                $unitPrice = max(0.0, (float) ($partRow['cost_unitar'] ?? 0));
                $lineValue = round($quantity * $unitPrice, 2);
                $insertPart->execute([
                    ':repair_id' => $repairId,
                    ':part_name' => trim((string) ($partRow['denumire'] ?? '')) ?: 'Piesă reparație',
                    ':part_code' => $this->nullIfEmpty((string) ($partRow['cod_piesa'] ?? '')),
                    ':stock_part_id' => !empty($partRow['part_id']) ? (int) $partRow['part_id'] : null,
                    ':quantity' => $quantity,
                    ':unit_price_without_vat' => $unitPrice,
                    ':discount_percent' => 0,
                    ':value_without_vat' => $lineValue,
                    ':vat_percent' => 0,
                    ':vat_value' => 0,
                    ':total_with_vat' => $lineValue,
                    ':part_supplier_name' => $this->nullIfEmpty((string) ($partRow['furnizor'] ?? ($record['furnizor_piesa'] ?? ''))),
                    ':notes' => $this->nullIfEmpty((string) ($partRow['observatii'] ?? '')),
                    ':created_at' => (string) ($partRow['created_at'] ?? $now),
                    ':updated_at' => $now,
                ]);
            }

            if ($usedParts === [] && $partsTotal > 0) {
                $partName = trim((string) ($record['piese_utilizate'] ?? ''));
                $insertPart->execute([
                    ':repair_id' => $repairId,
                    ':part_name' => $partName !== '' ? $partName : 'Piese reparație',
                    ':part_code' => null,
                    ':stock_part_id' => null,
                    ':quantity' => 1,
                    ':unit_price_without_vat' => $partsTotal,
                    ':discount_percent' => 0,
                    ':value_without_vat' => $partsTotal,
                    ':vat_percent' => 0,
                    ':vat_value' => 0,
                    ':total_with_vat' => $partsTotal,
                    ':part_supplier_name' => $this->nullIfEmpty((string) ($record['furnizor_piesa'] ?? '')),
                    ':notes' => null,
                    ':created_at' => (string) ($record['created_at'] ?? $now),
                    ':updated_at' => $now,
                ]);
            }

            $this->recalculateCorrectiveInvoiceTotals($invoiceId);
        }
    }

    private function recalculateCorrectiveInvoiceTotals(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }

        $labourStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(labour_cost), 0)
             FROM invoice_vehicle_repairs
             WHERE invoice_id = :invoice_id"
        );
        $labourStmt->execute([':invoice_id' => $invoiceId]);
        $labourTotal = (float) $labourStmt->fetchColumn();

        $partsStmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(p.value_without_vat), 0) AS parts_subtotal,
                COALESCE(SUM(p.vat_value), 0) AS vat_total,
                COALESCE(SUM(p.total_with_vat), 0) AS parts_total
             FROM invoice_repair_parts p
             INNER JOIN invoice_vehicle_repairs r ON r.id = p.repair_id
             WHERE r.invoice_id = :invoice_id"
        );
        $partsStmt->execute([':invoice_id' => $invoiceId]);
        $parts = $partsStmt->fetch() ?: [];
        $partsSubtotal = (float) ($parts['parts_subtotal'] ?? 0);
        $vatTotal = (float) ($parts['vat_total'] ?? 0);
        $partsTotal = (float) ($parts['parts_total'] ?? 0);

        $this->db->prepare(
            "UPDATE supplier_invoices
             SET labour_total = :labour_total,
                 parts_subtotal = :parts_subtotal,
                 vat_total = :vat_total,
                 parts_total = :parts_total,
                 grand_total = :grand_total,
                 updated_at = :updated_at
             WHERE id = :invoice_id"
        )->execute([
            ':labour_total' => $labourTotal,
            ':parts_subtotal' => $partsSubtotal,
            ':vat_total' => $vatTotal,
            ':parts_total' => $partsTotal,
            ':grand_total' => $labourTotal + $partsTotal,
            ':updated_at' => date('Y-m-d H:i:s'),
            ':invoice_id' => $invoiceId,
        ]);
    }

    private function runMigrationScript(string $sql): void
    {
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, 'SET @') || str_starts_with($statement, 'PREPARE') || str_starts_with($statement, 'EXECUTE') || str_starts_with($statement, 'DEALLOCATE')) {
                continue;
            }
        }

        $columns = [
            'record_type' => "ENUM('intretinere','reparatie') NOT NULL DEFAULT 'intretinere' AFTER tip_interventie",
            'centru_cost' => "VARCHAR(80) NULL AFTER record_type",
            'descriere' => "TEXT NULL AFTER centru_cost",
            'status_interventie' => "ENUM('in_asteptare','in_lucru','finalizata','anulata') NOT NULL DEFAULT 'finalizata' AFTER descriere",
            'km_interventie' => "INT UNSIGNED NULL AFTER data_interventie",
            'piese_utilizate' => "TEXT NULL AFTER furnizor_piesa",
            'cost_manopera' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cost",
            'cost_piese' => "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cost_manopera",
            'zile_imobilizare' => "DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER cost_piese",
            'source_intervention_id' => "INT UNSIGNED NULL AFTER zile_imobilizare",
        ];
        foreach ($columns as $column => $definition) {
            if (!$this->columnExists('mentenanta', $column)) {
                $this->db->exec("ALTER TABLE mentenanta ADD COLUMN `" . $column . "` " . $definition);
            }
        }
        $this->db->exec(
            "UPDATE mentenanta SET
                record_type = CASE
                    WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'defect|repar|diagnoz|alternator|turbin|fran|cutie|pierdere|suspensie' THEN 'reparatie'
                    ELSE 'intretinere' END,
                centru_cost = COALESCE(NULLIF(centru_cost, ''), CASE
                    WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'fran' THEN 'Sistem frânare'
                    WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'electr|alternator' THEN 'Sistem electric'
                    WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'cutie|transmis' THEN 'Transmisie'
                    WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'suspens|pern.*aer' THEN 'Suspensie'
                    WHEN LOWER(CONCAT_WS(' ', tip_interventie, observatii)) REGEXP 'anvelop' THEN 'Consumabile'
                    ELSE 'Motor' END),
                descriere = COALESCE(NULLIF(descriere, ''), NULLIF(observatii, ''), tip_interventie),
                cost_manopera = CASE WHEN cost_manopera = 0 AND cost_piese = 0 THEN cost ELSE cost_manopera END"
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name"
        );
        $stmt->execute([':table_name' => $table, ':column_name' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function normalizeRecordType(string $value): string
    {
        return $value === 'reparatie' ? 'reparatie' : 'intretinere';
    }

    private function normalizeRecordStatus(string $value): string
    {
        return in_array($value, ['in_asteptare', 'in_lucru', 'finalizata', 'anulata'], true) ? $value : 'finalizata';
    }

    private function normalizeScheduleStatus(string $value): string
    {
        return in_array($value, ['programata', 'confirmata', 'in_lucru', 'finalizata', 'anulata'], true) ? $value : 'programata';
    }

    private function normalizeList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $result[$item] = $item;
            }
        }
        return array_values($result);
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        $number = (int) $value;
        return $number > 0 ? $number : null;
    }

    private function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);
        return $value !== '' ? $value : null;
    }
}
