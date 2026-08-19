<?php
declare(strict_types=1);

/**
 * Fuel-driven tariff REVIEW recommendations.
 *
 * ABSOLUTE RULE
 *   This service never writes a commercial value. It evaluates the diesel index
 *   against each active tariff version's frozen reference and records a
 *   recommendation status. Only an administrator, through an explicit action,
 *   can create a new tariff version.
 *
 * STATUSES
 *   NO_REFERENCE       the active version carries no captured fuel reference
 *   INSUFFICIENT_DATA  too few observations / litres in the monitoring window
 *   DATA_STALE         the CardOil feed is older than the configured window
 *   OK                 within limits (or no threshold configured)
 *   REVIEW_RECOMMENDED |variation| >= configured threshold
 *   REVIEWED           an administrator acted on it
 *   DISMISSED          an administrator deferred it
 */
class TariffReviewService
{
    private PDO $db;
    private TransportTariffModel $tariffs;
    private FuelPriceIndexService $fuelIndex;

    public function __construct(
        PDO $db,
        ?TransportTariffModel $tariffs = null,
        ?FuelPriceIndexService $fuelIndex = null
    ) {
        $this->db = $db;
        $this->tariffs = $tariffs ?? new TransportTariffModel($db);
        $this->fuelIndex = $fuelIndex ?? new FuelPriceIndexService($db);
    }

    /**
     * Evaluate the fuel context for one active tariff version.
     *
     * The monitoring window starts at the version's effective date — NOT at a
     * calendar month boundary — so changing a tariff mid-month resets the
     * commercial monitoring context.
     *
     * @return array<string,mixed>
     */
    public function evaluateVersion(array $version, ?array $settings = null): array
    {
        $settings ??= $this->tariffs->getSettings();

        $staleDays = max(1, (int) ($settings['fuel_data_stale_days'] ?? 7));
        $minObservations = max(0, (int) ($settings['fuel_min_observations'] ?? 5));
        $minLiters = max(0.0, (float) ($settings['fuel_min_liters'] ?? 500));
        $threshold = $this->tariffs->getReviewThresholdPercent();

        $periodStart = (string) $version['valid_from'];
        // A migrated sentinel start date would drag in the entire history; clamp
        // the monitoring window to the reference capture date when one exists.
        if ($periodStart === TransportTariffModel::MIGRATION_BASELINE
            && !empty($version['reference_captured_at'])) {
            $periodStart = substr((string) $version['reference_captured_at'], 0, 10);
        }

        $index = $this->fuelIndex->getWeightedDieselPrice($periodStart, null);
        $freshness = $this->fuelIndex->getSyncFreshness($staleDays);

        $reference = $version['reference_fuel_price'] !== null
            ? (float) $version['reference_fuel_price']
            : null;

        $current = $index['weighted_price'];
        $variation = $this->fuelIndex->calculateVariationPercent($current, $reference);

        $status = 'OK';
        if ($reference === null || $reference <= 0.0) {
            $status = 'NO_REFERENCE';
        } elseif (!$index['available']
            || $index['observation_count'] < $minObservations
            || $index['total_liters'] < $minLiters
        ) {
            $status = 'INSUFFICIENT_DATA';
        } elseif ($freshness['is_stale']) {
            // Stale data must never masquerade as a trustworthy recommendation.
            $status = 'DATA_STALE';
        } elseif ($threshold !== null && $variation !== null && abs($variation) >= $threshold) {
            $status = 'REVIEW_RECOMMENDED';
        }

        // A numeric recommendation is produced ONLY when the administrator has
        // explicitly configured a fuel sensitivity for this component.
        $recommended = null;
        $fuelWeight = $version['fuel_weight'] !== null ? (float) $version['fuel_weight'] : null;
        if ($status === 'REVIEW_RECOMMENDED'
            && $fuelWeight !== null
            && $variation !== null
        ) {
            $recommended = round((float) $version['value'] * (1 + (($variation / 100) * $fuelWeight)), 4);
        }

        return [
            'tariff_version_id' => (int) $version['id'],
            'rule_signature' => (string) $version['rule_signature'],
            'beneficiar_id' => (int) $version['beneficiar_id'],
            'transport_type' => (string) $version['transport_type'],
            'component_key' => (string) $version['component_key'],
            'status' => $status,
            'reference_fuel_price' => $reference,
            'current_weighted_price' => $current,
            'variation_percent' => $variation,
            'liters_analysed' => $index['total_liters'],
            'observation_count' => $index['observation_count'],
            'period_start' => $periodStart,
            'period_end' => $index['last_observation'] !== null
                ? substr((string) $index['last_observation'], 0, 10)
                : null,
            'last_sync_at' => $freshness['last_success_at'],
            'recommended_value' => $recommended,
            'fuel_weight' => $fuelWeight,
            'threshold_percent' => $threshold,
            'evaluated_at' => date('Y-m-d H:i:s'),
            'index' => $index,
            'freshness' => $freshness,
        ];
    }

    /**
     * Re-evaluate every currently active version, optionally for one beneficiary.
     * Called after a successful CardOil sync and when the module page is opened.
     *
     * @return array{evaluated:int, recommended:int, statuses:array<string,int>}
     */
    public function evaluateActiveVersions(?int $beneficiaryId = null): array
    {
        if (!$this->tariffs->schemaReady()) {
            return ['evaluated' => 0, 'recommended' => 0, 'statuses' => []];
        }

        $today = date('Y-m-d');
        $sql = '
            SELECT * FROM transport_tariff_versions
            WHERE valid_from <= :today
              AND (valid_to IS NULL OR valid_to >= :today2)
        ';
        $params = ['today' => $today, 'today2' => $today];
        if ($beneficiaryId !== null) {
            $sql .= ' AND beneficiar_id = :bid';
            $params['bid'] = $beneficiaryId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $settings = $this->tariffs->getSettings();
        $statuses = [];
        $recommended = 0;

        foreach ($versions as $version) {
            $existing = $this->tariffs->getReviewForVersion((int) $version['id']);
            // Respect an administrator's explicit decision: do not resurrect a
            // dismissed/reviewed recommendation on every sync.
            if (is_array($existing)
                && in_array((string) $existing['status'], ['DISMISSED', 'REVIEWED'], true)
                && $existing['resolved_at'] !== null
            ) {
                $statuses[(string) $existing['status']] = ($statuses[(string) $existing['status']] ?? 0) + 1;
                continue;
            }

            $evaluation = $this->evaluateVersion($version, $settings);
            $this->tariffs->upsertReview($evaluation);

            $statuses[$evaluation['status']] = ($statuses[$evaluation['status']] ?? 0) + 1;
            if ($evaluation['status'] === 'REVIEW_RECOMMENDED') {
                $recommended++;
            }
        }

        return [
            'evaluated' => count($versions),
            'recommended' => $recommended,
            'statuses' => $statuses,
        ];
    }

    /**
     * Capture the fuel reference for a newly confirmed tariff version.
     *
     * The reference is frozen at confirmation time and must not move when
     * CardOil syncs again — it represents the fuel environment the tariff was
     * decided in.
     *
     * @return array{price:?float, captured_at:string, period_start:string, liters:float, observations:int}
     */
    public function captureReferenceForNewVersion(string $effectiveFrom): array
    {
        $settings = $this->tariffs->getSettings();
        $staleDays = max(1, (int) ($settings['fuel_data_stale_days'] ?? 7));

        // Reference window: the 30 days preceding the decision, which is the
        // fuel environment actually observable when the decision is taken.
        $windowStart = (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
        $index = $this->fuelIndex->getWeightedDieselPrice($windowStart, null);
        $freshness = $this->fuelIndex->getSyncFreshness($staleDays);

        return [
            'price' => $index['weighted_price'],
            'captured_at' => date('Y-m-d H:i:s'),
            'period_start' => $windowStart,
            'period_end' => $index['last_observation'] !== null
                ? substr((string) $index['last_observation'], 0, 10)
                : null,
            'liters' => $index['total_liters'],
            'observations' => $index['observation_count'],
            'is_stale' => $freshness['is_stale'],
            'effective_from' => $effectiveFrom,
        ];
    }

    /**
     * Aggregate fuel monitoring card for the beneficiary page.
     *
     * @return array<string,mixed>
     */
    public function getMonitoringSummary(?int $beneficiaryId = null): array
    {
        $settings = $this->tariffs->getSettings();
        $staleDays = max(1, (int) ($settings['fuel_data_stale_days'] ?? 7));
        $threshold = $this->tariffs->getReviewThresholdPercent();
        $freshness = $this->fuelIndex->getSyncFreshness($staleDays);

        // Anchor the displayed window on the earliest active reference so the
        // card matches what the recommendations are actually measured against.
        $anchor = null;
        $reference = null;
        if ($this->tariffs->schemaReady() && $beneficiaryId !== null) {
            $today = date('Y-m-d');
            $stmt = $this->db->prepare('
                SELECT reference_fuel_price, reference_captured_at, valid_from
                FROM transport_tariff_versions
                WHERE beneficiar_id = :bid
                  AND reference_fuel_price IS NOT NULL
                  AND valid_from <= :today
                  AND (valid_to IS NULL OR valid_to >= :today2)
                ORDER BY valid_from DESC, id DESC
                LIMIT 1
            ');
            $stmt->execute(['bid' => $beneficiaryId, 'today' => $today, 'today2' => $today]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $reference = (float) $row['reference_fuel_price'];
                $anchor = !empty($row['reference_captured_at'])
                    ? substr((string) $row['reference_captured_at'], 0, 10)
                    : (string) $row['valid_from'];
            }
        }

        $periodStart = $anchor ?? (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d');
        $index = $this->fuelIndex->getWeightedDieselPrice($periodStart, null);
        $variation = $this->fuelIndex->calculateVariationPercent($index['weighted_price'], $reference);

        $status = 'OK';
        if ($reference === null) {
            $status = 'NO_REFERENCE';
        } elseif (!$index['available']
            || $index['observation_count'] < (int) ($settings['fuel_min_observations'] ?? 5)
            || $index['total_liters'] < (float) ($settings['fuel_min_liters'] ?? 500)
        ) {
            $status = 'INSUFFICIENT_DATA';
        } elseif ($freshness['is_stale']) {
            $status = 'DATA_STALE';
        } elseif ($threshold !== null && $variation !== null && abs($variation) >= $threshold) {
            $status = 'REVIEW_RECOMMENDED';
        }

        return [
            'status' => $status,
            'reference_price' => $reference,
            'current_price' => $index['weighted_price'],
            'variation_percent' => $variation,
            'liters' => $index['total_liters'],
            'observations' => $index['observation_count'],
            'period_start' => $periodStart,
            'period_end' => $index['last_observation'] !== null
                ? substr((string) $index['last_observation'], 0, 10)
                : null,
            'last_sync_at' => $freshness['last_success_at'],
            'sync_age_days' => $freshness['age_days'],
            'is_stale' => $freshness['is_stale'],
            'threshold_percent' => $threshold,
            'excluded_non_api' => $index['excluded_non_api'],
            'excluded_adblue' => $index['excluded_adblue'],
            'audit_avg_unit_price' => $index['audit_avg_unit_price'],
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'OK' => 'În limite',
            'REVIEW_RECOMMENDED' => 'Revizuire recomandată',
            'DATA_STALE' => 'Date neactualizate',
            'INSUFFICIENT_DATA' => 'Date insuficiente',
            'NO_REFERENCE' => 'Fără referință',
            'REVIEWED' => 'Revizuit',
            'DISMISSED' => 'Amânat',
            default => $status,
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'OK', 'REVIEWED' => 'ok',
            'REVIEW_RECOMMENDED' => 'warn',
            'DATA_STALE' => 'stale',
            'INSUFFICIENT_DATA', 'NO_REFERENCE' => 'muted',
            'DISMISSED' => 'muted',
            default => 'muted',
        };
    }
}
