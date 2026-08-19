<?php
declare(strict_types=1);

/**
 * Volume-weighted diesel price index derived from CardOil observations.
 *
 * HARD RULES (from ANALIZA_FUEL_API_TARIFARE.md and the module brief)
 *   - Motorină ONLY. AdBlue is a chemical additive and is never mixed in.
 *   - source_type = 'api' ONLY. Test/demo/manual rows can never influence a
 *     commercial figure.
 *   - Rows with quantity_liters <= 0 or total_value <= 0 are excluded.
 *   - The fleet indicator is SUM(total_value) / SUM(quantity_liters) — a
 *     volume-weighted price, never AVG(unit_price).
 *
 * `unit_price` (the authoritative 4-decimal CardOil value) is retained and
 * reported for audit/validation, but it is not the principal indicator.
 */
class FuelPriceIndexService
{
    public const FUEL_TYPE_DIESEL = 'motorina';

    private PDO $db;
    private ?bool $hasSourceType = null;
    private ?bool $hasUnitPrice = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Weighted diesel price for a closed/open period.
     *
     * @return array{
     *   available:bool, weighted_price:?float, total_value:float, total_liters:float,
     *   observation_count:int, period_start:string, period_end:?string,
     *   first_observation:?string, last_observation:?string,
     *   audit_avg_unit_price:?float, excluded_non_api:int, excluded_adblue:int
     * }
     */
    public function getWeightedDieselPrice(string $periodStart, ?string $periodEnd = null): array
    {
        $empty = [
            'available' => false,
            'weighted_price' => null,
            'total_value' => 0.0,
            'total_liters' => 0.0,
            'observation_count' => 0,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'first_observation' => null,
            'last_observation' => null,
            'audit_avg_unit_price' => null,
            'excluded_non_api' => 0,
            'excluded_adblue' => 0,
        ];

        if (!$this->fuelTableReady()) {
            return $empty;
        }

        $where = ['f.fuel_type = :fuel_type', 'f.quantity_liters > 0', 'f.total_value > 0'];
        $params = ['fuel_type' => self::FUEL_TYPE_DIESEL, 'period_start' => $periodStart . ' 00:00:00'];
        $where[] = 'f.fillup_datetime >= :period_start';

        if ($periodEnd !== null && $periodEnd !== '') {
            $where[] = 'f.fillup_datetime <= :period_end';
            $params['period_end'] = $periodEnd . ' 23:59:59';
        }

        if ($this->hasSourceType()) {
            $where[] = "f.source_type = 'api'";
        } else {
            // Defensive fallback for an environment where the migration has not
            // yet run: keep the known test markers out of the commercial figure.
            $where[] = "f.api_id NOT LIKE 'test-%'";
            $where[] = "f.api_id NOT LIKE 'demo-cardoil-%'";
        }

        $unitPriceSelect = $this->hasUnitPrice()
            ? 'AVG(NULLIF(f.unit_price, 0)) AS audit_avg_unit_price'
            : 'NULL AS audit_avg_unit_price';

        $sql = "
            SELECT
                COALESCE(SUM(f.total_value), 0)     AS total_value,
                COALESCE(SUM(f.quantity_liters), 0) AS total_liters,
                COUNT(*)                            AS observation_count,
                MIN(f.fillup_datetime)              AS first_observation,
                MAX(f.fillup_datetime)              AS last_observation,
                {$unitPriceSelect}
            FROM fuel_fillups f
            WHERE " . implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalValue = (float) ($row['total_value'] ?? 0);
        $totalLiters = (float) ($row['total_liters'] ?? 0);
        $count = (int) ($row['observation_count'] ?? 0);

        $weighted = $totalLiters > 0 ? round($totalValue / $totalLiters, 4) : null;

        return [
            'available' => $weighted !== null && $count > 0,
            'weighted_price' => $weighted,
            'total_value' => round($totalValue, 2),
            'total_liters' => round($totalLiters, 2),
            'observation_count' => $count,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'first_observation' => $row['first_observation'] ?? null,
            'last_observation' => $row['last_observation'] ?? null,
            'audit_avg_unit_price' => isset($row['audit_avg_unit_price']) && $row['audit_avg_unit_price'] !== null
                ? round((float) $row['audit_avg_unit_price'], 4)
                : null,
            'excluded_non_api' => $this->countExcluded($periodStart, $periodEnd, 'non_api'),
            'excluded_adblue' => $this->countExcluded($periodStart, $periodEnd, 'adblue'),
        ];
    }

    /**
     * Percentage deviation of the current weighted price against a reference.
     * Returns NULL when no valid reference exists — never a fabricated number.
     */
    public function calculateVariationPercent(?float $currentPrice, ?float $referencePrice): ?float
    {
        if ($currentPrice === null || $referencePrice === null) {
            return null;
        }
        if ($referencePrice <= 0.0) {
            return null;
        }

        return round((($currentPrice - $referencePrice) / $referencePrice) * 100, 4);
    }

    /**
     * Freshness of the CardOil feed.
     *
     * @return array{last_sync_at:?string, last_success_at:?string, age_days:?int, is_stale:bool, status:?string}
     */
    public function getSyncFreshness(int $staleAfterDays = 7): array
    {
        $result = [
            'last_sync_at' => null,
            'last_success_at' => null,
            'age_days' => null,
            'is_stale' => true,
            'status' => null,
        ];

        try {
            $stmt = $this->db->query("
                SELECT sync_started_at, status
                FROM fuel_sync_logs
                ORDER BY id DESC LIMIT 1
            ");
            $last = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($last)) {
                $result['last_sync_at'] = (string) $last['sync_started_at'];
                $result['status'] = (string) $last['status'];
            }

            $successStmt = $this->db->query("
                SELECT sync_started_at
                FROM fuel_sync_logs
                WHERE status = 'success'
                ORDER BY id DESC LIMIT 1
            ");
            $success = $successStmt->fetchColumn();
            if (is_string($success) && $success !== '') {
                $result['last_success_at'] = $success;
                $age = (new DateTimeImmutable('today'))
                    ->diff(new DateTimeImmutable(substr($success, 0, 10)))
                    ->days;
                $result['age_days'] = (int) $age;
                $result['is_stale'] = (int) $age > $staleAfterDays;
            }
        } catch (Throwable $exception) {
            error_log('[FuelPriceIndexService][freshness] ' . $exception->getMessage());
        }

        return $result;
    }

    /**
     * Backfill unit_price for rows imported before the column existed, or by a
     * client build that does not yet persist it. Never rewrites raw_payload and
     * never overwrites a value that is already present.
     *
     * @return array{from_api:int, derived:int}
     */
    public function backfillUnitPrices(): array
    {
        if (!$this->hasUnitPrice()) {
            return ['from_api' => 0, 'derived' => 0];
        }

        $fromApi = (int) $this->db->exec("
            UPDATE fuel_fillups
            SET unit_price = CAST(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.pu_alimentare')) AS DECIMAL(12,4)),
                unit_price_source = 'api'
            WHERE unit_price IS NULL
              AND raw_payload IS NOT NULL
              AND JSON_VALID(raw_payload)
              AND JSON_EXTRACT(raw_payload, '$.pu_alimentare') IS NOT NULL
        ");

        $derived = (int) $this->db->exec("
            UPDATE fuel_fillups
            SET unit_price = ROUND(total_value / quantity_liters, 4),
                unit_price_source = 'derived'
            WHERE unit_price IS NULL
              AND quantity_liters > 0
        ");

        return ['from_api' => $fromApi, 'derived' => $derived];
    }

    /**
     * Cross-check the stored unit_price against the derived total/litres value.
     * Purely diagnostic — used by the tests and the admin diagnostics panel.
     *
     * @return array{checked:int, matching:int, max_deviation:float}
     */
    public function verifyUnitPriceConsistency(): array
    {
        if (!$this->hasUnitPrice()) {
            return ['checked' => 0, 'matching' => 0, 'max_deviation' => 0.0];
        }

        $stmt = $this->db->query("
            SELECT COUNT(*) AS checked,
                   SUM(ABS(unit_price - (total_value / quantity_liters)) < 0.005) AS matching,
                   COALESCE(MAX(ABS(unit_price - (total_value / quantity_liters))), 0) AS max_deviation
            FROM fuel_fillups
            WHERE quantity_liters > 0 AND unit_price IS NOT NULL
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'checked' => (int) ($row['checked'] ?? 0),
            'matching' => (int) ($row['matching'] ?? 0),
            'max_deviation' => round((float) ($row['max_deviation'] ?? 0), 6),
        ];
    }

    // -----------------------------------------------------------------

    private function countExcluded(string $periodStart, ?string $periodEnd, string $kind): int
    {
        if (!$this->fuelTableReady()) {
            return 0;
        }

        $where = ['f.fillup_datetime >= :period_start'];
        $params = ['period_start' => $periodStart . ' 00:00:00'];
        if ($periodEnd !== null && $periodEnd !== '') {
            $where[] = 'f.fillup_datetime <= :period_end';
            $params['period_end'] = $periodEnd . ' 23:59:59';
        }

        if ($kind === 'adblue') {
            $where[] = "f.fuel_type <> :fuel_type";
            $params['fuel_type'] = self::FUEL_TYPE_DIESEL;
        } elseif ($this->hasSourceType()) {
            $where[] = "f.source_type <> 'api'";
        } else {
            return 0;
        }

        try {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM fuel_fillups f WHERE ' . implode(' AND ', $where));
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function fuelTableReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        try {
            $stmt = $this->db->query("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fuel_fillups'
            ");
            $ready = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            $ready = false;
        }

        return $ready;
    }

    private function hasSourceType(): bool
    {
        return $this->hasSourceType ??= $this->columnExists('fuel_fillups', 'source_type');
    }

    private function hasUnitPrice(): bool
    {
        return $this->hasUnitPrice ??= $this->columnExists('fuel_fillups', 'unit_price');
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
            ");
            $stmt->execute(['t' => $table, 'c' => $column]);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
