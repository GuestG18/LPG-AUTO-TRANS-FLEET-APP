<?php
declare(strict_types=1);

/**
 * Data access for the versioned transport tariff module.
 *
 * IDENTITY MODEL
 *   A *pricing rule* is identified by `rule_signature`:
 *       "<beneficiar_id>|<component_key>|<route_ref_id|0>"
 *   Several rows of transport_tariff_versions may share a signature; they are
 *   successive versions distinguished only by [valid_from, valid_to].
 *   Two versions of the same signature may never overlap in time — this is
 *   enforced transactionally in createVersion().
 *
 * HISTORICAL SAFETY
 *   Nothing in this class ever writes to curse_dispecer.
 */
class TransportTariffModel extends BaseModel
{
    /** Sentinel used by the migration for tariffs with no evidenced start date. */
    public const MIGRATION_BASELINE = '2000-01-01';

    public const TRANSPORT_TYPES = [
        'primar' => 'Primar km',
        'primar_tona' => 'Primar tone',
        'distributie' => 'Distribuție',
        'primar_distributie' => 'P+D (Primar + Distribuție)',
        'compresor' => 'Compresor',
    ];

    /**
     * The complete catalogue of commercial components, exactly as established in
     * ANALIZA_COMPONENTE_TARIFARE_TRANSPORT.md. `level` distinguishes a
     * beneficiary-global rate from a route-scoped one — the distinction the UI
     * must never blur.
     */
    public const COMPONENTS = [
        'pret_km' => [
            'label' => 'Preț / km',
            'unit' => 'lei/km',
            'transport_type' => 'primar',
            'level' => 'beneficiary',
            'kind' => 'rate',
        ],
        'pret_tona' => [
            'label' => 'Preț / tonă',
            'unit' => 'lei/tona',
            'transport_type' => 'primar_tona',
            'level' => 'beneficiary',
            'kind' => 'rate',
        ],
        'tarif_tona' => [
            'label' => 'Tarif tonă',
            'unit' => 'lei/tona',
            'transport_type' => null,
            'level' => 'route',
            'kind' => 'rate',
        ],
        'cost_extra_km' => [
            'label' => 'Tarif km',
            'unit' => 'lei/km',
            'transport_type' => null,
            'level' => 'route',
            'kind' => 'rate',
        ],
        'cost_cursa' => [
            'label' => 'Cost / cursă',
            'unit' => 'lei/cursa',
            'transport_type' => null,
            'level' => 'route',
            'kind' => 'fixed_price',
        ],
        'pret_ora_aspirare' => [
            'label' => 'Oră aspirare',
            'unit' => 'lei/ora',
            'transport_type' => 'compresor',
            'level' => 'beneficiary',
            'kind' => 'rate',
        ],
        'pret_km_dislocare' => [
            'label' => 'Km dislocare',
            'unit' => 'lei/km',
            'transport_type' => 'compresor',
            'level' => 'beneficiary',
            'kind' => 'rate',
        ],
        'pret_tona_livrata' => [
            'label' => 'Tonă livrată',
            'unit' => 'lei/tona',
            'transport_type' => 'compresor',
            'level' => 'beneficiary',
            'kind' => 'rate',
        ],
        'pret_tona_aspirata_lichida' => [
            'label' => 'Tonă aspirată lichidă',
            'unit' => 'lei/tona',
            'transport_type' => 'compresor',
            'level' => 'beneficiary',
            'kind' => 'rate',
        ],
        'pret_tona_aspirata_gazoasa' => [
            'label' => 'Tonă aspirată gazoasă',
            'unit' => 'lei/tona',
            'transport_type' => 'compresor',
            'level' => 'beneficiary',
            'kind' => 'rate',
        ],
    ];

    /** The five Compresor components, in display order. */
    public const COMPRESSOR_COMPONENTS = [
        'pret_ora_aspirare',
        'pret_km_dislocare',
        'pret_tona_livrata',
        'pret_tona_aspirata_lichida',
        'pret_tona_aspirata_gazoasa',
    ];

    private const DEFAULT_SETTINGS = [
        'fuel_review_threshold_percent' => '',
        'fuel_data_stale_days' => '7',
        'fuel_min_observations' => '5',
        'fuel_min_liters' => '500',
    ];

    public static function buildSignature(int $beneficiaryId, string $componentKey, ?int $routeRefId): string
    {
        return $beneficiaryId . '|' . $componentKey . '|' . (int) $routeRefId;
    }

    public static function componentUnit(string $componentKey): string
    {
        return (string) (self::COMPONENTS[$componentKey]['unit'] ?? '');
    }

    public static function componentLabel(string $componentKey): string
    {
        return (string) (self::COMPONENTS[$componentKey]['label'] ?? $componentKey);
    }

    public function schemaReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME IN ('transport_tariff_versions','transport_tariff_history',
                                     'transport_tariff_reviews','transport_tariff_settings')
            ");
            $ready = (int) $stmt->fetchColumn() === 4;
        } catch (Throwable) {
            $ready = false;
        }

        return $ready;
    }

    // -----------------------------------------------------------------
    // Beneficiaries
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function getBeneficiaries(bool $onlyActive = false): array
    {
        $sql = '
            SELECT id, nume, activ,
                   suporta_primar, suporta_distributie, suporta_primar_distributie, suporta_compresor
            FROM configurare_beneficiari_transport
            ' . ($onlyActive ? 'WHERE activ = 1' : '') . '
            ORDER BY nume ASC
        ';

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getBeneficiary(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM configurare_beneficiari_transport WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    // -----------------------------------------------------------------
    // Version resolution
    // -----------------------------------------------------------------

    /**
     * The authoritative resolver: the version in force on a given business date.
     * Used by TransportPricingService with the trip's data_cursa — never NOW().
     */
    public function resolveVersionAt(string $signature, string $businessDate): ?array
    {
        if (!$this->schemaReady()) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT * FROM transport_tariff_versions
            WHERE rule_signature = :sig
              AND valid_from <= :d1
              AND (valid_to IS NULL OR valid_to >= :d2)
            ORDER BY valid_from DESC, id DESC
            LIMIT 1
        ');
        $stmt->execute(['sig' => $signature, 'd1' => $businessDate, 'd2' => $businessDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Bulk variant — one query for every signature a trip may need.
     *
     * @param array<int,string> $signatures
     * @return array<string,array<string,mixed>> keyed by signature
     */
    public function resolveVersionsAt(array $signatures, string $businessDate): array
    {
        $signatures = array_values(array_unique(array_filter($signatures)));
        if ($signatures === [] || !$this->schemaReady()) {
            return [];
        }

        $placeholders = [];
        $params = ['d1' => $businessDate, 'd2' => $businessDate];
        foreach ($signatures as $index => $signature) {
            $key = 'sig' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $signature;
        }

        $stmt = $this->db->prepare('
            SELECT t.* FROM transport_tariff_versions t
            INNER JOIN (
                SELECT rule_signature, MAX(valid_from) AS mx
                FROM transport_tariff_versions
                WHERE rule_signature IN (' . implode(',', $placeholders) . ')
                  AND valid_from <= :d1
                  AND (valid_to IS NULL OR valid_to >= :d2)
                GROUP BY rule_signature
            ) p ON p.rule_signature = t.rule_signature AND p.mx = t.valid_from
            WHERE t.valid_from <= :d1 AND (t.valid_to IS NULL OR t.valid_to >= :d2)
        ');
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(string) $row['rule_signature']] = $row;
        }

        return $map;
    }

    /** All versions of one rule, newest first. */
    public function getVersionHistoryForSignature(string $signature): array
    {
        if (!$this->schemaReady()) {
            return [];
        }

        $stmt = $this->db->prepare('
            SELECT * FROM transport_tariff_versions
            WHERE rule_signature = :sig
            ORDER BY valid_from DESC, id DESC
        ');
        $stmt->execute(['sig' => $signature]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getVersionById(int $id): ?array
    {
        if (!$this->schemaReady()) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM transport_tariff_versions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Every version belonging to a beneficiary, decorated with a lifecycle status.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getVersionsForBeneficiary(int $beneficiaryId, ?string $today = null): array
    {
        if (!$this->schemaReady()) {
            return [];
        }

        $today ??= date('Y-m-d');
        $stmt = $this->db->prepare('
            SELECT v.*,
                   li.nume AS loc_nume,
                   zd.nume AS zona_nume,
                   rd.tarif_mod       AS route_tarif_mod,
                   rd.km_tarifare     AS route_km_tarifare,
                   rd.aplica_cost_cursa AS route_aplica_cost_cursa,
                   rd.vehicle_ids     AS route_vehicle_ids,
                   rd.activ           AS route_activ,
                   rp.km_tarifare     AS primar_km_tarifare,
                   rp.km_agreati_manual AS primar_km_manual,
                   rp.aplica_cost_cursa AS primar_aplica_cost_cursa,
                   rp.vehicle_ids     AS primar_vehicle_ids,
                   rp.activ           AS primar_activ
            FROM transport_tariff_versions v
            LEFT JOIN configurare_locuri_incarcare li ON li.id = v.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = v.zona_distributie_id
            LEFT JOIN configurare_rute_distributie rd
                   ON rd.id = v.route_ref_id AND v.route_scope IN ("distributie","primar_distributie")
            LEFT JOIN configurare_rute_primar rp
                   ON rp.id = v.route_ref_id AND v.route_scope = "primar"
            WHERE v.beneficiar_id = :bid
            ORDER BY v.transport_type ASC, v.component_key ASC, v.valid_from DESC, v.id DESC
        ');
        $stmt->execute(['bid' => $beneficiaryId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['status'] = $this->deriveStatus($row, $today);
        }
        unset($row);

        return $rows;
    }

    /** active | scheduled | expired */
    public function deriveStatus(array $version, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        $from = (string) ($version['valid_from'] ?? '');
        $to = $version['valid_to'] !== null ? (string) $version['valid_to'] : null;

        if ($from > $today) {
            return 'scheduled';
        }
        if ($to !== null && $to < $today) {
            return 'expired';
        }

        return 'active';
    }

    // -----------------------------------------------------------------
    // Version creation — the only write path for commercial values
    // -----------------------------------------------------------------

    /**
     * Create a new version of a pricing rule, closing the previous one.
     *
     * Transactional and overlap-safe:
     *   - the version in force at $validFrom gets valid_to = $validFrom - 1 day;
     *   - any version that would start on/after $validFrom is rejected as a conflict
     *     (the caller must remove the scheduled one first);
     *   - nothing in curse_dispecer is touched.
     *
     * @param array<string,mixed> $payload
     * @return array{version_id:int, previous_id:?int, previous_value:?float}
     * @throws RuntimeException on validation/conflict
     */
    public function createVersion(array $payload): array
    {
        if (!$this->schemaReady()) {
            throw new RuntimeException('Schema de tarife versionate nu este instalata. Ruleaza scripts/migrate_transport_tariffs.php.');
        }

        $beneficiaryId = (int) ($payload['beneficiar_id'] ?? 0);
        $componentKey = trim((string) ($payload['component_key'] ?? ''));
        $routeRefId = isset($payload['route_ref_id']) && (int) $payload['route_ref_id'] > 0
            ? (int) $payload['route_ref_id']
            : null;
        $validFrom = trim((string) ($payload['valid_from'] ?? ''));
        $value = (float) ($payload['value'] ?? 0);

        if ($beneficiaryId <= 0) {
            throw new RuntimeException('Beneficiar invalid.');
        }
        if (!array_key_exists($componentKey, self::COMPONENTS)) {
            throw new RuntimeException('Componenta tarifara invalida.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) !== 1) {
            throw new RuntimeException('Data de intrare in vigoare este invalida.');
        }
        if ($value < 0) {
            throw new RuntimeException('Valoarea tarifului nu poate fi negativa.');
        }

        $signature = self::buildSignature($beneficiaryId, $componentKey, $routeRefId);
        $now = date('Y-m-d H:i:s');
        $previousDay = (new DateTimeImmutable($validFrom))->modify('-1 day')->format('Y-m-d');

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // A version starting on/after the new effective date would overlap.
            $conflictStmt = $this->db->prepare('
                SELECT id, valid_from FROM transport_tariff_versions
                WHERE rule_signature = :sig AND valid_from >= :vf
                ORDER BY valid_from ASC LIMIT 1
            ');
            $conflictStmt->execute(['sig' => $signature, 'vf' => $validFrom]);
            $conflict = $conflictStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($conflict)) {
                throw new RuntimeException(
                    'Exista deja o versiune de tarif valabila de la ' . (string) $conflict['valid_from']
                    . '. Sterge sau modifica intai versiunea programata.'
                );
            }

            // Close the version currently covering the new effective date.
            $currentStmt = $this->db->prepare('
                SELECT * FROM transport_tariff_versions
                WHERE rule_signature = :sig
                  AND valid_from <= :vf
                  AND (valid_to IS NULL OR valid_to >= :vf2)
                ORDER BY valid_from DESC, id DESC LIMIT 1
            ');
            $currentStmt->execute(['sig' => $signature, 'vf' => $validFrom, 'vf2' => $validFrom]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC);

            $previousId = null;
            $previousValue = null;
            if (is_array($current)) {
                $previousId = (int) $current['id'];
                $previousValue = (float) $current['value'];

                if ((string) $current['valid_from'] === $validFrom) {
                    throw new RuntimeException(
                        'Exista deja o versiune care incepe exact la ' . $validFrom . '.'
                    );
                }

                $closeStmt = $this->db->prepare('
                    UPDATE transport_tariff_versions
                    SET valid_to = :vt, updated_at = :ua
                    WHERE id = :id
                ');
                $closeStmt->execute(['vt' => $previousDay, 'ua' => $now, 'id' => $previousId]);
            }

            $component = self::COMPONENTS[$componentKey];
            $transportType = (string) ($payload['transport_type'] ?? ($component['transport_type'] ?? 'primar'));
            if (!array_key_exists($transportType, self::TRANSPORT_TYPES)) {
                throw new RuntimeException('Tip de transport invalid.');
            }

            $insert = $this->db->prepare('
                INSERT INTO transport_tariff_versions (
                    rule_signature, beneficiar_id, transport_type, component_key, unit,
                    route_scope, route_ref_id, loc_incarcare_id, zona_distributie_id,
                    value, valid_from, valid_to, fuel_weight,
                    reference_fuel_price, reference_captured_at,
                    source, reason, created_by, created_at, updated_at
                ) VALUES (
                    :rule_signature, :beneficiar_id, :transport_type, :component_key, :unit,
                    :route_scope, :route_ref_id, :loc_incarcare_id, :zona_distributie_id,
                    :value, :valid_from, NULL, :fuel_weight,
                    :reference_fuel_price, :reference_captured_at,
                    "manual", :reason, :created_by, :created_at, :updated_at
                )
            ');
            $insert->execute([
                'rule_signature' => $signature,
                'beneficiar_id' => $beneficiaryId,
                'transport_type' => $transportType,
                'component_key' => $componentKey,
                'unit' => (string) $component['unit'],
                'route_scope' => (string) ($payload['route_scope'] ?? 'none'),
                'route_ref_id' => $routeRefId,
                'loc_incarcare_id' => $payload['loc_incarcare_id'] ?? null,
                'zona_distributie_id' => $payload['zona_distributie_id'] ?? null,
                'value' => $value,
                'valid_from' => $validFrom,
                'fuel_weight' => $payload['fuel_weight'] ?? null,
                'reference_fuel_price' => $payload['reference_fuel_price'] ?? null,
                'reference_captured_at' => $payload['reference_captured_at'] ?? null,
                'reason' => $payload['reason'] ?? null,
                'created_by' => $payload['created_by'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $versionId = (int) $this->db->lastInsertId();

            if ($ownTransaction) {
                $this->db->commit();
            }

            return [
                'version_id' => $versionId,
                'previous_id' => $previousId,
                'previous_value' => $previousValue,
            ];
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    // -----------------------------------------------------------------
    // History
    // -----------------------------------------------------------------

    public function recordHistory(array $entry): void
    {
        if (!$this->schemaReady()) {
            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO transport_tariff_history (
                tariff_version_id, rule_signature, beneficiar_id, transport_type,
                component_key, route_ref_id, route_label, action,
                old_value, new_value, unit, effective_from, effective_to,
                reference_fuel_price, observed_fuel_price, fuel_variation_percent,
                fuel_liters_analysed, fuel_period_start, fuel_period_end,
                reason, changed_by, changed_by_name, changed_at
            ) VALUES (
                :tariff_version_id, :rule_signature, :beneficiar_id, :transport_type,
                :component_key, :route_ref_id, :route_label, :action,
                :old_value, :new_value, :unit, :effective_from, :effective_to,
                :reference_fuel_price, :observed_fuel_price, :fuel_variation_percent,
                :fuel_liters_analysed, :fuel_period_start, :fuel_period_end,
                :reason, :changed_by, :changed_by_name, :changed_at
            )
        ');

        $stmt->execute([
            'tariff_version_id' => $entry['tariff_version_id'] ?? null,
            'rule_signature' => (string) ($entry['rule_signature'] ?? ''),
            'beneficiar_id' => (int) ($entry['beneficiar_id'] ?? 0),
            'transport_type' => (string) ($entry['transport_type'] ?? 'primar'),
            'component_key' => (string) ($entry['component_key'] ?? ''),
            'route_ref_id' => $entry['route_ref_id'] ?? null,
            'route_label' => $entry['route_label'] ?? null,
            'action' => (string) ($entry['action'] ?? 'created'),
            'old_value' => $entry['old_value'] ?? null,
            'new_value' => $entry['new_value'] ?? null,
            'unit' => (string) ($entry['unit'] ?? ''),
            'effective_from' => $entry['effective_from'] ?? null,
            'effective_to' => $entry['effective_to'] ?? null,
            'reference_fuel_price' => $entry['reference_fuel_price'] ?? null,
            'observed_fuel_price' => $entry['observed_fuel_price'] ?? null,
            'fuel_variation_percent' => $entry['fuel_variation_percent'] ?? null,
            'fuel_liters_analysed' => $entry['fuel_liters_analysed'] ?? null,
            'fuel_period_start' => $entry['fuel_period_start'] ?? null,
            'fuel_period_end' => $entry['fuel_period_end'] ?? null,
            'reason' => $entry['reason'] ?? null,
            'changed_by' => $entry['changed_by'] ?? null,
            'changed_by_name' => $entry['changed_by_name'] ?? null,
            'changed_at' => (string) ($entry['changed_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function getHistory(?int $beneficiaryId = null, int $limit = 50): array
    {
        if (!$this->schemaReady()) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $sql = '
            SELECT h.*, u.nume AS user_nume
            FROM transport_tariff_history h
            LEFT JOIN utilizatori u ON u.id = h.changed_by
            ' . ($beneficiaryId !== null ? 'WHERE h.beneficiar_id = :bid' : '') . '
            ORDER BY h.changed_at DESC, h.id DESC
            LIMIT ' . $limit . '
        ';

        $stmt = $this->db->prepare($sql);
        if ($beneficiaryId !== null) {
            $stmt->bindValue(':bid', $beneficiaryId, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // -----------------------------------------------------------------
    // Reviews
    // -----------------------------------------------------------------

    public function upsertReview(array $review): void
    {
        if (!$this->schemaReady()) {
            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO transport_tariff_reviews (
                tariff_version_id, rule_signature, beneficiar_id, transport_type, component_key,
                status, reference_fuel_price, current_weighted_price, variation_percent,
                liters_analysed, observation_count, period_start, period_end, last_sync_at,
                recommended_value, evaluated_at
            ) VALUES (
                :tariff_version_id, :rule_signature, :beneficiar_id, :transport_type, :component_key,
                :status, :reference_fuel_price, :current_weighted_price, :variation_percent,
                :liters_analysed, :observation_count, :period_start, :period_end, :last_sync_at,
                :recommended_value, :evaluated_at
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                reference_fuel_price = VALUES(reference_fuel_price),
                current_weighted_price = VALUES(current_weighted_price),
                variation_percent = VALUES(variation_percent),
                liters_analysed = VALUES(liters_analysed),
                observation_count = VALUES(observation_count),
                period_start = VALUES(period_start),
                period_end = VALUES(period_end),
                last_sync_at = VALUES(last_sync_at),
                recommended_value = VALUES(recommended_value),
                evaluated_at = VALUES(evaluated_at)
        ');

        $stmt->execute([
            'tariff_version_id' => (int) $review['tariff_version_id'],
            'rule_signature' => (string) $review['rule_signature'],
            'beneficiar_id' => (int) $review['beneficiar_id'],
            'transport_type' => (string) $review['transport_type'],
            'component_key' => (string) $review['component_key'],
            'status' => (string) $review['status'],
            'reference_fuel_price' => $review['reference_fuel_price'] ?? null,
            'current_weighted_price' => $review['current_weighted_price'] ?? null,
            'variation_percent' => $review['variation_percent'] ?? null,
            'liters_analysed' => $review['liters_analysed'] ?? null,
            'observation_count' => (int) ($review['observation_count'] ?? 0),
            'period_start' => $review['period_start'] ?? null,
            'period_end' => $review['period_end'] ?? null,
            'last_sync_at' => $review['last_sync_at'] ?? null,
            'recommended_value' => $review['recommended_value'] ?? null,
            'evaluated_at' => (string) ($review['evaluated_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }

    public function getReviewForVersion(int $versionId): ?array
    {
        if (!$this->schemaReady()) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM transport_tariff_reviews WHERE tariff_version_id = :id LIMIT 1');
        $stmt->execute(['id' => $versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> keyed by tariff_version_id */
    public function getReviewsForBeneficiary(int $beneficiaryId): array
    {
        if (!$this->schemaReady()) {
            return [];
        }

        $stmt = $this->db->prepare('SELECT * FROM transport_tariff_reviews WHERE beneficiar_id = :bid');
        $stmt->execute(['bid' => $beneficiaryId]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['tariff_version_id']] = $row;
        }

        return $map;
    }

    public function markReviewResolved(int $versionId, string $status, ?int $userId): bool
    {
        if (!$this->schemaReady()) {
            return false;
        }

        $stmt = $this->db->prepare('
            UPDATE transport_tariff_reviews
            SET status = :status, resolved_at = :ra, resolved_by = :rb
            WHERE tariff_version_id = :id
        ');
        $stmt->execute([
            'status' => $status,
            'ra' => date('Y-m-d H:i:s'),
            'rb' => $userId,
            'id' => $versionId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markReviewNotified(int $versionId): void
    {
        if (!$this->schemaReady()) {
            return;
        }

        $stmt = $this->db->prepare('
            UPDATE transport_tariff_reviews SET notified_at = :na WHERE tariff_version_id = :id
        ');
        $stmt->execute(['na' => date('Y-m-d H:i:s'), 'id' => $versionId]);
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    /** @return array<string,string> */
    public function getSettings(): array
    {
        $settings = self::DEFAULT_SETTINGS;
        if (!$this->schemaReady()) {
            return $settings;
        }

        foreach ($this->db->query('SELECT setting_key, setting_value FROM transport_tariff_settings')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $settings;
    }

    public function setSetting(string $key, string $value, ?int $userId = null): void
    {
        if (!$this->schemaReady()) {
            return;
        }

        $stmt = $this->db->prepare('
            INSERT INTO transport_tariff_settings (setting_key, setting_value, updated_by, updated_at)
            VALUES (:k, :v, :u, :t)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                    updated_by = VALUES(updated_by),
                                    updated_at = VALUES(updated_at)
        ');
        $stmt->execute(['k' => $key, 'v' => $value, 'u' => $userId, 't' => date('Y-m-d H:i:s')]);
    }

    /**
     * The configured review threshold, or NULL when the administrator has not
     * set one. NULL must never be silently replaced by a default in production.
     */
    public function getReviewThresholdPercent(): ?float
    {
        $raw = trim((string) ($this->getSettings()['fuel_review_threshold_percent'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        return $value > 0 ? $value : null;
    }

    // -----------------------------------------------------------------
    // Route catalogue (read-only view over the legacy configuration)
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function getPrimaryRoutes(int $beneficiaryId): array
    {
        $stmt = $this->db->prepare('
            SELECT r.*, l.nume AS loc_nume, z.nume AS zona_nume
            FROM configurare_rute_primar r
            INNER JOIN configurare_locuri_incarcare l ON l.id = r.loc_incarcare_id
            INNER JOIN configurare_zone_distributie z ON z.id = r.zona_distributie_id
            WHERE r.beneficiar_id = :bid
            ORDER BY l.nume ASC, z.nume ASC
        ');
        $stmt->execute(['bid' => $beneficiaryId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array<string,mixed>> */
    public function getDistributionRoutes(int $beneficiaryId, string $scope): array
    {
        $stmt = $this->db->prepare('
            SELECT r.*, l.nume AS loc_nume, z.nume AS zona_nume
            FROM configurare_rute_distributie r
            INNER JOIN configurare_locuri_incarcare l ON l.id = r.loc_incarcare_id
            INNER JOIN configurare_zone_distributie z ON z.id = r.zona_distributie_id
            WHERE r.beneficiar_id = :bid AND r.transport_scope = :scope
            ORDER BY l.nume ASC, z.nume ASC
        ');
        $stmt->execute(['bid' => $beneficiaryId, 'scope' => $scope]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,string> vehicle id => plate */
    public function getVehiclePlateMap(): array
    {
        $map = [];
        foreach ($this->db->query('SELECT id, nr_inmatriculare FROM vehicule')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['id']] = trim((string) $row['nr_inmatriculare']);
        }

        return $map;
    }
}
