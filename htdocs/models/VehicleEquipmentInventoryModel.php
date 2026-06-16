<?php
declare(strict_types=1);

class VehicleEquipmentInventoryModel extends BaseModel
{
    public const STATUS_VALID = 'valid';
    public const STATUS_SOON = 'expira_curand';
    public const STATUS_EXPIRED = 'expirat';
    public const STATUS_MISSING = 'lipsa_date';
    public const EQUIPMENT_TYPES = [
        'mandatory' => 'Obligatorie',
        'optional' => 'Suplimentară',
    ];

    public function getVehicleTypeOptions(): array
    {
        return [
            'autovehicul' => 'Autoturism',
            'autoutilitara' => 'Autoutilitară',
            'camion' => 'Camion',
            'cap_tractor' => 'Cap tractor',
            'semiremorca_primar' => 'Semi-remorcă primar',
            'semiremorca_distributie' => 'Semi-remorcă distribuție',
            'semiremorca' => 'Semi-remorcă',
        ];
    }

    public function getStatusOptions(): array
    {
        return [
            self::STATUS_VALID => 'Valid',
            self::STATUS_SOON => 'Expiră Curând',
            self::STATUS_EXPIRED => 'Expirat',
            self::STATUS_MISSING => 'Lipsă Date',
        ];
    }

    public function getCatalogItems(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM inventar_dotari_catalog';
        if ($activeOnly) {
            $sql .= ' WHERE activ = 1';
        }
        $sql .= ' ORDER BY categorie ASC, nume ASC';

        return $this->db->query($sql)->fetchAll();
    }

    public function getCatalogItem(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM inventar_dotari_catalog WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getRules(): array
    {
        $sql = '
            SELECT r.*, c.nume AS catalog_nume, c.categorie AS catalog_categorie
            FROM inventar_dotari_reguli r
            INNER JOIN inventar_dotari_catalog c ON c.id = r.catalog_id
            ORDER BY r.vehicle_type ASC, c.categorie ASC, c.nume ASC
        ';

        return $this->db->query($sql)->fetchAll();
    }

    public function getRulesGroupedByVehicleType(): array
    {
        $rules = [];
        foreach ($this->getRules() as $row) {
            if ((int) ($row['activ'] ?? 0) !== 1) {
                continue;
            }

            $vehicleType = $this->normalizeRuleVehicleType((string) ($row['vehicle_type'] ?? ''));
            if (!isset($rules[$vehicleType])) {
                $rules[$vehicleType] = [];
            }

            $rules[$vehicleType][(int) $row['catalog_id']] = $row;
        }

        return $rules;
    }

    public function getDashboardStats(): array
    {
        $summaries = $this->buildAllVehicleSummaries('', '');

        $stats = [
            'vehicle_count' => count($summaries),
            'fleet_cost' => 0.0,
            'assigned_count' => 0,
            'missing_vehicle_count' => 0,
            'expired_count' => 0,
            'expiring_soon_count' => 0,
            'inspection_due_count' => 0,
        ];

        foreach ($summaries as $summary) {
            $stats['fleet_cost'] += (float) ($summary['total_cost'] ?? 0);
            $stats['assigned_count'] += (int) ($summary['assigned_count'] ?? 0);
            if ((int) ($summary['missing_count'] ?? 0) > 0) {
                $stats['missing_vehicle_count']++;
            }
            $stats['expired_count'] += (int) ($summary['expired_count'] ?? 0);
            $stats['expiring_soon_count'] += (int) ($summary['expiring_soon_count'] ?? 0);
            $stats['inspection_due_count'] += (int) ($summary['inspection_due_count'] ?? 0);
        }

        return $stats;
    }

    public function getVehicleSummaries(array $filters, string $sort, string $direction, int $page, int $perPage): array
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $vehicleType = trim((string) ($filters['tip_vehicul'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $missingOnly = (string) ($filters['missing'] ?? '') === '1';
        $expiredOnly = (string) ($filters['expired'] ?? '') === '1';

        $summaries = $this->buildAllVehicleSummaries($search, $vehicleType);

        $summaries = array_values(array_filter($summaries, static function (array $summary) use ($status, $missingOnly, $expiredOnly): bool {
            if ($status !== '' && (string) ($summary['status'] ?? '') !== $status) {
                return false;
            }

            if ($missingOnly && (int) ($summary['missing_count'] ?? 0) <= 0) {
                return false;
            }

            if ($expiredOnly && (int) ($summary['expired_count'] ?? 0) <= 0) {
                return false;
            }

            return true;
        }));

        $this->sortVehicleSummaries($summaries, $sort, $direction);

        $totalRows = count($summaries);
        $totalPages = max(1, (int) ceil($totalRows / max(1, $perPage)));
        $page = min(max($page, 1), $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'rows' => array_slice($summaries, $offset, $perPage),
            'total_rows' => $totalRows,
            'total_pages' => $totalPages,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function getVehicleInventory(int $vehicleId): ?array
    {
        $vehicle = $this->findVehicle($vehicleId);
        if ($vehicle === null) {
            return null;
        }

        $assignments = $this->getAssignmentsByVehicleIds([$vehicleId])[$vehicleId] ?? [];
        $rules = $this->getRulesGroupedByVehicleType();
        $summary = $this->buildVehicleSummary($vehicle, $assignments, $rules[$this->normalizeRuleVehicleType((string) ($vehicle['tip_vehicul'] ?? ''))] ?? []);

        return [
            'vehicle' => $vehicle,
            'assignments' => $assignments,
            'missing' => $summary['missing_items'],
            'summary' => $summary,
        ];
    }

    public function findVehicle(int $vehicleId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT *
            FROM vehicule
            WHERE id = :id
              AND nr_inmatriculare <> "STOC-ANVELOPE"
              AND serie_sasiu <> "STOCANVELOPE00001"
            LIMIT 1
        ');
        $stmt->bindValue(':id', $vehicleId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getAssignment(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT d.*, c.nume AS catalog_nume, c.categorie AS catalog_categorie, c.poza_stocata AS catalog_poza_stocata,
                   c.poza_original AS catalog_poza_original, v.nr_inmatriculare, v.tip_vehicul
            FROM inventar_dotari_vehicule d
            INNER JOIN inventar_dotari_catalog c ON c.id = d.catalog_id
            INNER JOIN vehicule v ON v.id = d.vehicle_id
            WHERE d.id = :id
            LIMIT 1
        ');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return $row ? $this->decorateAssignment($row) : null;
    }

    public function createAssignment(array $data): int
    {
        $data = $this->prepareAssignmentData($data);
        $now = date('Y-m-d H:i:s');

        $sql = '
            INSERT INTO inventar_dotari_vehicule
                (vehicle_id, catalog_id, poza_original, poza_stocata, cost, data_achizitiei, data_fabricatiei,
                 data_ultimei_inspectii, interval_inspectie_luni, data_urmatoarei_inspectii, data_expirarii,
                 serie_cod_produs, observatii, created_at, updated_at)
            VALUES
                (:vehicle_id, :catalog_id, :poza_original, :poza_stocata, :cost, :data_achizitiei, :data_fabricatiei,
                 :data_ultimei_inspectii, :interval_inspectie_luni, :data_urmatoarei_inspectii, :data_expirarii,
                 :serie_cod_produs, :observatii, :created_at, :updated_at)
        ';

        $stmt = $this->db->prepare($sql);
        $this->bindAssignmentValues($stmt, array_merge($data, [
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function updateAssignment(int $id, array $data): bool
    {
        $data = $this->prepareAssignmentData($data);
        $data['updated_at'] = date('Y-m-d H:i:s');

        $sql = '
            UPDATE inventar_dotari_vehicule
            SET vehicle_id = :vehicle_id,
                catalog_id = :catalog_id,
                poza_original = :poza_original,
                poza_stocata = :poza_stocata,
                cost = :cost,
                data_achizitiei = :data_achizitiei,
                data_fabricatiei = :data_fabricatiei,
                data_ultimei_inspectii = :data_ultimei_inspectii,
                interval_inspectie_luni = :interval_inspectie_luni,
                data_urmatoarei_inspectii = :data_urmatoarei_inspectii,
                data_expirarii = :data_expirarii,
                serie_cod_produs = :serie_cod_produs,
                observatii = :observatii,
                updated_at = :updated_at
            WHERE id = :id
        ';

        $stmt = $this->db->prepare($sql);
        $this->bindAssignmentValues($stmt, $data);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteAssignment(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM inventar_dotari_vehicule WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function deleteVehicleAssignments(int $vehicleId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM inventar_dotari_vehicule WHERE vehicle_id = :vehicle_id');
        $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function saveCatalogItem(array $data, ?int $id = null): int
    {
        $now = date('Y-m-d H:i:s');
        $payload = [
            'nume' => trim((string) ($data['nume'] ?? '')),
            'categorie' => trim((string) ($data['categorie'] ?? '')),
            'equipment_type' => array_key_exists((string) ($data['equipment_type'] ?? ''), self::EQUIPMENT_TYPES)
                ? (string) $data['equipment_type']
                : 'mandatory',
            'poza_original' => $this->nullableString($data['poza_original'] ?? null),
            'poza_stocata' => $this->nullableString($data['poza_stocata'] ?? null),
            'cost_implicit' => max(0, (float) ($data['cost_implicit'] ?? 0)),
            'necesita_data_fabricatie' => !empty($data['necesita_data_fabricatie']) ? 1 : 0,
            'necesita_inspectie' => !empty($data['necesita_inspectie']) ? 1 : 0,
            'interval_implicit_inspectie_luni' => $this->positiveIntOrNull($data['interval_implicit_inspectie_luni'] ?? null),
            'necesita_data_expirarii' => !empty($data['necesita_data_expirarii']) ? 1 : 0,
            'activ' => !empty($data['activ']) ? 1 : 0,
            'updated_at' => $now,
        ];

        if ($id !== null && $id > 0) {
            $sql = '
                UPDATE inventar_dotari_catalog
                SET nume = :nume,
                    categorie = :categorie,
                    equipment_type = :equipment_type,
                    poza_original = :poza_original,
                    poza_stocata = :poza_stocata,
                    cost_implicit = :cost_implicit,
                    necesita_data_fabricatie = :necesita_data_fabricatie,
                    necesita_inspectie = :necesita_inspectie,
                    interval_implicit_inspectie_luni = :interval_implicit_inspectie_luni,
                    necesita_data_expirarii = :necesita_data_expirarii,
                    activ = :activ,
                    updated_at = :updated_at
                WHERE id = :id
            ';
            $stmt = $this->db->prepare($sql);
            $this->bindCatalogValues($stmt, $payload);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            return $id;
        }

        $payload['created_at'] = $now;
        $sql = '
            INSERT INTO inventar_dotari_catalog
                (nume, categorie, equipment_type, poza_original, poza_stocata, cost_implicit, necesita_data_fabricatie,
                 necesita_inspectie, interval_implicit_inspectie_luni, necesita_data_expirarii, activ, created_at, updated_at)
            VALUES
                (:nume, :categorie, :equipment_type, :poza_original, :poza_stocata, :cost_implicit, :necesita_data_fabricatie,
                 :necesita_inspectie, :interval_implicit_inspectie_luni, :necesita_data_expirarii, :activ, :created_at, :updated_at)
        ';
        $stmt = $this->db->prepare($sql);
        $this->bindCatalogValues($stmt, $payload);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    public function deleteCatalogItem(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM inventar_dotari_catalog WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function addRule(string $vehicleType, int $catalogId): bool
    {
        $vehicleType = $this->normalizeRuleVehicleType($vehicleType);
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            INSERT INTO inventar_dotari_reguli (vehicle_type, catalog_id, activ, created_at, updated_at)
            VALUES (:vehicle_type, :catalog_id, 1, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE activ = 1, updated_at = VALUES(updated_at)
        ');
        $stmt->bindValue(':vehicle_type', $vehicleType);
        $stmt->bindValue(':catalog_id', $catalogId, PDO::PARAM_INT);
        $stmt->bindValue(':created_at', $now);
        $stmt->bindValue(':updated_at', $now);

        return $stmt->execute();
    }

    public function deleteRule(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM inventar_dotari_reguli WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function buildExportRows(array $filters, string $sort, string $direction): array
    {
        $result = $this->getVehicleSummaries($filters, $sort, $direction, 1, PHP_INT_MAX);

        return $result['rows'];
    }

    private function buildAllVehicleSummaries(string $search, string $vehicleType): array
    {
        $conditions = [
            "nr_inmatriculare <> 'STOC-ANVELOPE'",
            "serie_sasiu <> 'STOCANVELOPE00001'",
            "status = 'activ'",
        ];

        $sql = 'SELECT * FROM vehicule WHERE ' . implode(' AND ', $conditions) . ' ORDER BY nr_inmatriculare ASC';
        $stmt = $this->db->query($sql);

        $vehicles = $stmt->fetchAll();
        $vehicleIds = array_map(static fn(array $vehicle): int => (int) $vehicle['id'], $vehicles);
        $assignmentsByVehicleId = $this->getAssignmentsByVehicleIds($vehicleIds);
        $rulesByVehicleType = $this->getRulesGroupedByVehicleType();
        $vehicleById = [];
        foreach ($vehicles as $vehicle) {
            $vehicleById[(int) ($vehicle['id'] ?? 0)] = $vehicle;
        }

        $summaries = [];
        $vehicleIdsUsedInAssemblies = [];
        foreach ($this->getActiveAssemblyPairs($vehicleIds) as $pair) {
            $tractorId = (int) ($pair['tractor_id'] ?? 0);
            $trailerId = (int) ($pair['semiremorca_id'] ?? 0);
            $tractor = $vehicleById[$tractorId] ?? null;
            $trailer = $vehicleById[$trailerId] ?? null;

            if ($tractor === null || $trailer === null) {
                continue;
            }

            $vehicleIdsUsedInAssemblies[$tractorId] = true;
            $vehicleIdsUsedInAssemblies[$trailerId] = true;
            $summaries[] = $this->buildAssemblySummary($tractor, $trailer, $assignmentsByVehicleId, $rulesByVehicleType);
        }

        foreach ($vehicles as $vehicle) {
            $vehicleId = (int) ($vehicle['id'] ?? 0);
            $vehicleTypeRaw = (string) ($vehicle['tip_vehicul'] ?? '');

            if (isset($vehicleIdsUsedInAssemblies[$vehicleId]) || $this->isAssemblyVehicleType($vehicleTypeRaw)) {
                continue;
            }

            $type = $this->normalizeRuleVehicleType((string) ($vehicle['tip_vehicul'] ?? ''));
            $summaries[] = $this->buildVehicleSummary(
                $vehicle,
                $assignmentsByVehicleId[(int) $vehicle['id']] ?? [],
                $rulesByVehicleType[$type] ?? []
            );
        }

        return array_values(array_filter($summaries, fn(array $summary): bool => $this->summaryMatchesInventoryUnitFilters($summary, $search, $vehicleType)));
    }

    private function getActiveAssemblyPairs(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $vehicleIds))));
        if ($vehicleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $sql = '
            SELECT vc.id, vc.tractor_id, vc.semiremorca_id
            FROM vehicule_cuplaje vc
            INNER JOIN vehicule t ON t.id = vc.tractor_id
            INNER JOIN vehicule s ON s.id = vc.semiremorca_id
            WHERE vc.activ = 1
              AND t.status = "activ"
              AND s.status = "activ"
              AND vc.tractor_id IN (' . $placeholders . ')
              AND vc.semiremorca_id IN (' . $placeholders . ')
            ORDER BY vc.id DESC
        ';

        $stmt = $this->db->prepare($sql);
        $bindIndex = 1;
        foreach ($vehicleIds as $vehicleId) {
            $stmt->bindValue($bindIndex++, $vehicleId, PDO::PARAM_INT);
        }
        foreach ($vehicleIds as $vehicleId) {
            $stmt->bindValue($bindIndex++, $vehicleId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $pairs = [];
        $usedTractors = [];
        $usedTrailers = [];
        foreach ($stmt->fetchAll() as $row) {
            $tractorId = (int) ($row['tractor_id'] ?? 0);
            $trailerId = (int) ($row['semiremorca_id'] ?? 0);
            if ($tractorId <= 0 || $trailerId <= 0 || isset($usedTractors[$tractorId]) || isset($usedTrailers[$trailerId])) {
                continue;
            }

            $pairs[] = $row;
            $usedTractors[$tractorId] = true;
            $usedTrailers[$trailerId] = true;
        }

        return $pairs;
    }

    private function buildAssemblySummary(array $tractor, array $trailer, array $assignmentsByVehicleId, array $rulesByVehicleType): array
    {
        $tractorId = (int) ($tractor['id'] ?? 0);
        $trailerId = (int) ($trailer['id'] ?? 0);
        $tractorSummary = $this->buildVehicleSummary(
            $tractor,
            $assignmentsByVehicleId[$tractorId] ?? [],
            $rulesByVehicleType[$this->normalizeRuleVehicleType((string) ($tractor['tip_vehicul'] ?? ''))] ?? []
        );
        $trailerSummary = $this->buildVehicleSummary(
            $trailer,
            $assignmentsByVehicleId[$trailerId] ?? [],
            $rulesByVehicleType[$this->normalizeRuleVehicleType((string) ($trailer['tip_vehicul'] ?? ''))] ?? []
        );

        $assignments = array_merge($tractorSummary['assignments'], $trailerSummary['assignments']);
        $missingItems = array_merge(
            $this->prefixMissingItems($tractorSummary['missing_items'], (string) ($tractor['nr_inmatriculare'] ?? '')),
            $this->prefixMissingItems($trailerSummary['missing_items'], (string) ($trailer['nr_inmatriculare'] ?? ''))
        );
        $status = $this->worstInventoryStatus((string) $tractorSummary['status'], (string) $trailerSummary['status']);

        return [
            'vehicle_id' => $tractorId,
            'primary_vehicle_id' => $tractorId,
            'partner_vehicle_id' => $trailerId,
            'unit_vehicle_ids' => [$tractorId, $trailerId],
            'unit_vehicle_types' => [
                (string) ($tractor['tip_vehicul'] ?? ''),
                (string) ($trailer['tip_vehicul'] ?? ''),
            ],
            'unit_vehicles' => [
                $this->vehicleFormContext($tractor, 'Cap tractor'),
                $this->vehicleFormContext($trailer, 'Semi-remorcă'),
            ],
            'is_assembly' => true,
            'nr_inmatriculare' => trim((string) ($tractor['nr_inmatriculare'] ?? '') . ' + ' . (string) ($trailer['nr_inmatriculare'] ?? '')),
            'tip_vehicul' => 'ansamblu',
            'tip_vehicul_label' => 'Ansamblu',
            'assigned_count' => (int) $tractorSummary['assigned_count'] + (int) $trailerSummary['assigned_count'],
            'missing_count' => count($missingItems),
            'missing_items' => $missingItems,
            'total_cost' => (float) $tractorSummary['total_cost'] + (float) $trailerSummary['total_cost'],
            'expiring_soon_count' => (int) $tractorSummary['expiring_soon_count'] + (int) $trailerSummary['expiring_soon_count'],
            'expired_count' => (int) $tractorSummary['expired_count'] + (int) $trailerSummary['expired_count'],
            'inspection_due_count' => (int) $tractorSummary['inspection_due_count'] + (int) $trailerSummary['inspection_due_count'],
            'missing_data_count' => (int) $tractorSummary['missing_data_count'] + (int) $trailerSummary['missing_data_count'],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'assignments' => $assignments,
        ];
    }

    private function prefixMissingItems(array $missingItems, string $vehicleLabel): array
    {
        return array_map(static function (array $item) use ($vehicleLabel): array {
            $item['vehicle_label'] = $vehicleLabel;
            $item['display_name'] = trim($vehicleLabel . ' - ' . (string) ($item['nume'] ?? ''));

            return $item;
        }, $missingItems);
    }

    private function vehicleFormContext(array $vehicle, string $role): array
    {
        $vehicleId = (int) ($vehicle['id'] ?? 0);

        return [
            'id' => $vehicleId,
            'vehicle_id' => $vehicleId,
            'nr_inmatriculare' => (string) ($vehicle['nr_inmatriculare'] ?? ''),
            'tip_vehicul' => (string) ($vehicle['tip_vehicul'] ?? ''),
            'tip_vehicul_label' => vehicle_type_label((string) ($vehicle['tip_vehicul'] ?? '')),
            'unit_role' => $role,
        ];
    }

    private function worstInventoryStatus(string ...$statuses): string
    {
        $priority = [
            self::STATUS_EXPIRED => 4,
            self::STATUS_SOON => 3,
            self::STATUS_MISSING => 2,
            self::STATUS_VALID => 1,
        ];

        $worstStatus = self::STATUS_VALID;
        $worstPriority = 0;
        foreach ($statuses as $status) {
            $currentPriority = $priority[$status] ?? 0;
            if ($currentPriority > $worstPriority) {
                $worstStatus = $status;
                $worstPriority = $currentPriority;
            }
        }

        return $worstStatus;
    }

    private function summaryMatchesInventoryUnitFilters(array $summary, string $search, string $vehicleType): bool
    {
        if ($search !== '') {
            $haystack = mb_strtolower((string) ($summary['nr_inmatriculare'] ?? ''), 'UTF-8');
            if (!str_contains($haystack, mb_strtolower($search, 'UTF-8'))) {
                return false;
            }
        }

        if ($vehicleType === '') {
            return true;
        }

        $types = is_array($summary['unit_vehicle_types'] ?? null)
            ? $summary['unit_vehicle_types']
            : [(string) ($summary['tip_vehicul'] ?? '')];

        return in_array($vehicleType, array_map('strval', $types), true);
    }

    private function isAssemblyVehicleType(string $vehicleType): bool
    {
        $normalized = strtolower(trim($vehicleType));

        return $normalized === 'cap_tractor' || is_trailer_vehicle_type($normalized);
    }

    private function buildVehicleSummary(array $vehicle, array $assignments, array $requiredRules): array
    {
        $assignedCatalogIds = [];
        $assignedCount = 0;
        $totalCost = 0.0;
        $expiredCount = 0;
        $expiringSoonCount = 0;
        $inspectionDueCount = 0;
        $missingDataCount = 0;

        foreach ($assignments as $assignment) {
            $assignedCount++;
            $assignedCatalogIds[(int) ($assignment['catalog_id'] ?? 0)] = true;
            $totalCost += (float) ($assignment['cost'] ?? 0);

            $status = (string) ($assignment['status'] ?? self::STATUS_VALID);
            if ($status === self::STATUS_EXPIRED) {
                $expiredCount++;
            } elseif ($status === self::STATUS_SOON) {
                $expiringSoonCount++;
            } elseif ($status === self::STATUS_MISSING) {
                $missingDataCount++;
            }

            if (!empty($assignment['inspection_due_soon'])) {
                $inspectionDueCount++;
            }
        }

        $missingItems = [];
        foreach ($requiredRules as $catalogId => $rule) {
            if (!isset($assignedCatalogIds[(int) $catalogId])) {
                $missingItems[] = [
                    'catalog_id' => (int) $catalogId,
                    'nume' => (string) ($rule['catalog_nume'] ?? ''),
                    'categorie' => (string) ($rule['catalog_categorie'] ?? ''),
                ];
            }
        }

        $missingCount = count($missingItems);
        $status = self::STATUS_VALID;
        if ($expiredCount > 0) {
            $status = self::STATUS_EXPIRED;
        } elseif ($expiringSoonCount > 0 || $inspectionDueCount > 0) {
            $status = self::STATUS_SOON;
        } elseif ($missingCount > 0 || $missingDataCount > 0) {
            $status = self::STATUS_MISSING;
        }

        return [
            'vehicle_id' => (int) $vehicle['id'],
            'primary_vehicle_id' => (int) $vehicle['id'],
            'partner_vehicle_id' => null,
            'unit_vehicle_ids' => [(int) $vehicle['id']],
            'unit_vehicle_types' => [(string) ($vehicle['tip_vehicul'] ?? '')],
            'unit_vehicles' => [$this->vehicleFormContext($vehicle, vehicle_type_label((string) ($vehicle['tip_vehicul'] ?? '')))],
            'is_assembly' => false,
            'nr_inmatriculare' => (string) ($vehicle['nr_inmatriculare'] ?? ''),
            'tip_vehicul' => (string) ($vehicle['tip_vehicul'] ?? ''),
            'tip_vehicul_label' => vehicle_type_label((string) ($vehicle['tip_vehicul'] ?? '')),
            'assigned_count' => $assignedCount,
            'missing_count' => $missingCount,
            'missing_items' => $missingItems,
            'total_cost' => $totalCost,
            'expiring_soon_count' => $expiringSoonCount,
            'expired_count' => $expiredCount,
            'inspection_due_count' => $inspectionDueCount,
            'missing_data_count' => $missingDataCount,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'assignments' => $assignments,
        ];
    }

    private function getAssignmentsByVehicleIds(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $vehicleIds))));
        if ($vehicleIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $sql = '
            SELECT d.*, c.nume AS catalog_nume, c.categorie AS catalog_categorie,
                   c.poza_original AS catalog_poza_original, c.poza_stocata AS catalog_poza_stocata,
                   c.cost_implicit, c.necesita_data_fabricatie, c.necesita_inspectie,
                   c.interval_implicit_inspectie_luni, c.necesita_data_expirarii,
                   v.nr_inmatriculare AS assigned_vehicle_nr,
                   v.tip_vehicul AS assigned_vehicle_type
            FROM inventar_dotari_vehicule d
            INNER JOIN inventar_dotari_catalog c ON c.id = d.catalog_id
            INNER JOIN vehicule v ON v.id = d.vehicle_id
            WHERE d.vehicle_id IN (' . $placeholders . ')
            ORDER BY c.categorie ASC, c.nume ASC, d.id ASC
        ';

        $stmt = $this->db->prepare($sql);
        foreach ($vehicleIds as $index => $vehicleId) {
            $stmt->bindValue($index + 1, $vehicleId, PDO::PARAM_INT);
        }
        $stmt->execute();

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            if (!isset($grouped[$vehicleId])) {
                $grouped[$vehicleId] = [];
            }

            $grouped[$vehicleId][] = $this->decorateAssignment($row);
        }

        return $grouped;
    }

    private function decorateAssignment(array $row): array
    {
        $statusData = $this->calculateAssignmentStatus($row);
        $row['status'] = $statusData['status'];
        $row['status_label'] = $this->statusLabel($statusData['status']);
        $row['status_reason'] = $statusData['reason'];
        $row['inspection_due_soon'] = $statusData['inspection_due_soon'];
        $row['effective_poza_stocata'] = trim((string) ($row['poza_stocata'] ?? '')) !== ''
            ? (string) $row['poza_stocata']
            : (string) ($row['catalog_poza_stocata'] ?? '');
        $row['effective_poza_original'] = trim((string) ($row['poza_original'] ?? '')) !== ''
            ? (string) $row['poza_original']
            : (string) ($row['catalog_poza_original'] ?? '');
        $row['assigned_vehicle_label'] = (string) ($row['assigned_vehicle_nr'] ?? '');
        $row['assigned_vehicle_type_label'] = vehicle_type_label((string) ($row['assigned_vehicle_type'] ?? ''));

        return $row;
    }

    private function calculateAssignmentStatus(array $row): array
    {
        $today = new DateTime('today');
        $soonLimit = (clone $today)->modify('+30 days');

        $needsFabricationDate = (int) ($row['necesita_data_fabricatie'] ?? 0) === 1;
        $needsInspection = (int) ($row['necesita_inspectie'] ?? 0) === 1;
        $needsExpiry = (int) ($row['necesita_data_expirarii'] ?? 0) === 1;

        if ($needsFabricationDate && trim((string) ($row['data_fabricatiei'] ?? '')) === '') {
            return ['status' => self::STATUS_MISSING, 'reason' => 'Lipsește data fabricației.', 'inspection_due_soon' => false];
        }

        if ($needsInspection && (
            trim((string) ($row['data_ultimei_inspectii'] ?? '')) === ''
            || (int) ($row['interval_inspectie_luni'] ?? 0) <= 0
            || trim((string) ($row['data_urmatoarei_inspectii'] ?? '')) === ''
        )) {
            return ['status' => self::STATUS_MISSING, 'reason' => 'Lipsește informația de inspecție.', 'inspection_due_soon' => false];
        }

        if ($needsExpiry && trim((string) ($row['data_expirarii'] ?? '')) === '') {
            return ['status' => self::STATUS_MISSING, 'reason' => 'Lipsește data expirării.', 'inspection_due_soon' => false];
        }

        $expiryStatus = $this->dateStatus((string) ($row['data_expirarii'] ?? ''), $today, $soonLimit);
        if ($expiryStatus === self::STATUS_EXPIRED) {
            return ['status' => self::STATUS_EXPIRED, 'reason' => 'Data expirării este depășită.', 'inspection_due_soon' => false];
        }

        $inspectionStatus = $this->dateStatus((string) ($row['data_urmatoarei_inspectii'] ?? ''), $today, $soonLimit);
        if ($inspectionStatus === self::STATUS_EXPIRED) {
            return ['status' => self::STATUS_EXPIRED, 'reason' => 'Inspecția este depășită.', 'inspection_due_soon' => false];
        }

        if ($expiryStatus === self::STATUS_SOON) {
            return ['status' => self::STATUS_SOON, 'reason' => 'Expiră în următoarele 30 de zile.', 'inspection_due_soon' => false];
        }

        if ($inspectionStatus === self::STATUS_SOON) {
            return ['status' => self::STATUS_SOON, 'reason' => 'Inspecția este scadentă în următoarele 30 de zile.', 'inspection_due_soon' => true];
        }

        return ['status' => self::STATUS_VALID, 'reason' => 'Dotare validă.', 'inspection_due_soon' => false];
    }

    private function dateStatus(string $date, DateTime $today, DateTime $soonLimit): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        try {
            $value = new DateTime($date);
        } catch (Exception) {
            return self::STATUS_MISSING;
        }

        if ($value < $today) {
            return self::STATUS_EXPIRED;
        }

        if ($value <= $soonLimit) {
            return self::STATUS_SOON;
        }

        return self::STATUS_VALID;
    }

    private function statusLabel(string $status): string
    {
        return $this->getStatusOptions()[$status] ?? 'Lipsă Date';
    }

    private function sortVehicleSummaries(array &$summaries, string $sort, string $direction): void
    {
        $allowed = [
            'nr_inmatriculare',
            'tip_vehicul',
            'assigned_count',
            'missing_count',
            'total_cost',
            'expiring_soon_count',
            'expired_count',
            'status',
        ];
        if (!in_array($sort, $allowed, true)) {
            $sort = 'nr_inmatriculare';
        }

        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        usort($summaries, static function (array $left, array $right) use ($sort, $direction): int {
            $leftValue = $left[$sort] ?? null;
            $rightValue = $right[$sort] ?? null;

            if (is_numeric($leftValue) && is_numeric($rightValue)) {
                $result = (float) $leftValue <=> (float) $rightValue;
            } else {
                $result = strnatcasecmp((string) $leftValue, (string) $rightValue);
            }

            return $direction === 'desc' ? -$result : $result;
        });
    }

    private function prepareAssignmentData(array $data): array
    {
        $catalog = $this->getCatalogItem((int) ($data['catalog_id'] ?? 0));
        $defaultCost = $catalog !== null ? (float) ($catalog['cost_implicit'] ?? 0) : 0.0;
        $defaultInterval = $catalog !== null ? $this->positiveIntOrNull($catalog['interval_implicit_inspectie_luni'] ?? null) : null;
        $interval = $this->positiveIntOrNull($data['interval_inspectie_luni'] ?? null) ?? $defaultInterval;
        $lastInspection = $this->nullableDate($data['data_ultimei_inspectii'] ?? null);

        return [
            'vehicle_id' => (int) ($data['vehicle_id'] ?? 0),
            'catalog_id' => (int) ($data['catalog_id'] ?? 0),
            'poza_original' => $this->nullableString($data['poza_original'] ?? null),
            'poza_stocata' => $this->nullableString($data['poza_stocata'] ?? null),
            'cost' => isset($data['cost']) && trim((string) $data['cost']) !== '' ? max(0, (float) $data['cost']) : $defaultCost,
            'data_achizitiei' => $this->nullableDate($data['data_achizitiei'] ?? null),
            'data_fabricatiei' => $this->nullableDate($data['data_fabricatiei'] ?? null),
            'data_ultimei_inspectii' => $lastInspection,
            'interval_inspectie_luni' => $interval,
            'data_urmatoarei_inspectii' => $this->calculateNextInspectionDate($lastInspection, $interval),
            'data_expirarii' => $this->nullableDate($data['data_expirarii'] ?? null),
            'serie_cod_produs' => $this->nullableString($data['serie_cod_produs'] ?? null),
            'observatii' => $this->nullableString($data['observatii'] ?? null),
        ];
    }

    private function calculateNextInspectionDate(?string $lastInspection, ?int $intervalMonths): ?string
    {
        if ($lastInspection === null || $intervalMonths === null || $intervalMonths <= 0) {
            return null;
        }

        try {
            return (new DateTime($lastInspection))->modify('+' . $intervalMonths . ' months')->format('Y-m-d');
        } catch (Exception) {
            return null;
        }
    }

    private function normalizeRuleVehicleType(string $type): string
    {
        if (function_exists('normalize_vehicle_type_for_form_select')) {
            return normalize_vehicle_type_for_form_select($type);
        }

        return strtolower(trim($type)) ?: 'autovehicul';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTime($value))->format('Y-m-d');
        } catch (Exception) {
            return null;
        }
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function bindAssignmentValues(PDOStatement $stmt, array $data): void
    {
        $stmt->bindValue(':vehicle_id', (int) $data['vehicle_id'], PDO::PARAM_INT);
        $stmt->bindValue(':catalog_id', (int) $data['catalog_id'], PDO::PARAM_INT);
        $this->bindNullableString($stmt, ':poza_original', $data['poza_original'] ?? null);
        $this->bindNullableString($stmt, ':poza_stocata', $data['poza_stocata'] ?? null);
        $stmt->bindValue(':cost', (string) number_format((float) $data['cost'], 2, '.', ''));
        $this->bindNullableString($stmt, ':data_achizitiei', $data['data_achizitiei'] ?? null);
        $this->bindNullableString($stmt, ':data_fabricatiei', $data['data_fabricatiei'] ?? null);
        $this->bindNullableString($stmt, ':data_ultimei_inspectii', $data['data_ultimei_inspectii'] ?? null);
        $this->bindNullableInt($stmt, ':interval_inspectie_luni', $data['interval_inspectie_luni'] ?? null);
        $this->bindNullableString($stmt, ':data_urmatoarei_inspectii', $data['data_urmatoarei_inspectii'] ?? null);
        $this->bindNullableString($stmt, ':data_expirarii', $data['data_expirarii'] ?? null);
        $this->bindNullableString($stmt, ':serie_cod_produs', $data['serie_cod_produs'] ?? null);
        $this->bindNullableString($stmt, ':observatii', $data['observatii'] ?? null);
        if (isset($data['created_at'])) {
            $stmt->bindValue(':created_at', (string) $data['created_at']);
        }
        $stmt->bindValue(':updated_at', (string) $data['updated_at']);
    }

    private function bindCatalogValues(PDOStatement $stmt, array $data): void
    {
        $stmt->bindValue(':nume', (string) $data['nume']);
        $stmt->bindValue(':categorie', (string) $data['categorie']);
        $stmt->bindValue(':equipment_type', (string) $data['equipment_type']);
        $this->bindNullableString($stmt, ':poza_original', $data['poza_original'] ?? null);
        $this->bindNullableString($stmt, ':poza_stocata', $data['poza_stocata'] ?? null);
        $stmt->bindValue(':cost_implicit', (string) number_format((float) $data['cost_implicit'], 2, '.', ''));
        $stmt->bindValue(':necesita_data_fabricatie', (int) $data['necesita_data_fabricatie'], PDO::PARAM_INT);
        $stmt->bindValue(':necesita_inspectie', (int) $data['necesita_inspectie'], PDO::PARAM_INT);
        $this->bindNullableInt($stmt, ':interval_implicit_inspectie_luni', $data['interval_implicit_inspectie_luni'] ?? null);
        $stmt->bindValue(':necesita_data_expirarii', (int) $data['necesita_data_expirarii'], PDO::PARAM_INT);
        $stmt->bindValue(':activ', (int) $data['activ'], PDO::PARAM_INT);
        if (isset($data['created_at'])) {
            $stmt->bindValue(':created_at', (string) $data['created_at']);
        }
        $stmt->bindValue(':updated_at', (string) $data['updated_at']);
    }

    private function bindNullableString(PDOStatement $stmt, string $placeholder, mixed $value): void
    {
        if ($value === null || trim((string) $value) === '') {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($placeholder, (string) $value);
    }

    private function bindNullableInt(PDOStatement $stmt, string $placeholder, mixed $value): void
    {
        if ($value === null || trim((string) $value) === '') {
            $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            return;
        }

        $stmt->bindValue($placeholder, (int) $value, PDO::PARAM_INT);
    }
}
