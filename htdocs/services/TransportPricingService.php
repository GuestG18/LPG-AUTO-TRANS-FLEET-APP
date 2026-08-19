<?php
declare(strict_types=1);

/**
 * The single authoritative pricing engine for transport trips.
 *
 * Responsibilities
 *   - resolve the tariff VERSION in force on the trip's business date (data_cursa)
 *   - resolve beneficiary-level and route-level rates
 *   - apply the tariff mode (Distribuție)
 *   - apply the fixed-price override (full replacement)
 *   - produce the total plus an auditable breakdown
 *
 * The formulas reproduce exactly those verified in
 * ANALIZA_COMPONENTE_TARIFARE_TRANSPORT.md §4–§11 against production records.
 *
 * IMPORTANT
 *   The date used for version resolution is ALWAYS the trip's business date,
 *   never NOW(), created_at or updated_at. Retroactively dated trips therefore
 *   price at the tariff that was in force on the day they happened.
 */
class TransportPricingService
{
    private PDO $db;
    private TransportTariffModel $tariffs;

    /** Legacy configuration snapshot cache, keyed by beneficiary id. */
    private array $beneficiaryCache = [];

    public function __construct(PDO $db, ?TransportTariffModel $tariffs = null)
    {
        $this->db = $db;
        $this->tariffs = $tariffs ?? new TransportTariffModel($db);
    }

    /**
     * Produce a quote for a trip.
     *
     * @param array<string,mixed> $trip Keys: beneficiar_id, tip_transport, data_cursa,
     *        vehicle_id, loc_incarcare_id, zona_distributie_id, cantitate_incarcata,
     *        km_cursa, km_totali, ore_aspirare, km_dislocare, tona_livrata,
     *        tona_aspirata_lichida, tona_aspirata_gazoasa
     *
     * @return array{
     *   ok:bool, transport_type:string, business_date:string,
     *   pret_tarifare:float, total_facturare:float,
     *   tariff_version_id:?int, components:array, warnings:array, notes:array,
     *   fixed_price_applied:bool, unit:string
     * }
     */
    public function quote(array $trip): array
    {
        $transportType = trim((string) ($trip['tip_transport'] ?? ''));
        $beneficiaryId = (int) ($trip['beneficiar_id'] ?? 0);
        $businessDate = $this->normalizeDate((string) ($trip['data_cursa'] ?? ''))
            ?? date('Y-m-d');

        $result = [
            'ok' => false,
            'transport_type' => $transportType,
            'business_date' => $businessDate,
            'pret_tarifare' => 0.0,
            'total_facturare' => 0.0,
            'tariff_version_id' => null,
            'components' => [],
            'warnings' => [],
            'notes' => [],
            'fixed_price_applied' => false,
            'unit' => '',
        ];

        if (!array_key_exists($transportType, TransportTariffModel::TRANSPORT_TYPES)) {
            $result['warnings'][] = 'Tip de transport necunoscut.';
            return $result;
        }
        if ($beneficiaryId <= 0) {
            $result['warnings'][] = 'Beneficiarul nu este selectat.';
            return $result;
        }

        $beneficiary = $this->loadBeneficiary($beneficiaryId);
        if ($beneficiary === null) {
            $result['warnings'][] = 'Beneficiarul nu exista.';
            return $result;
        }

        return match ($transportType) {
            'primar' => $this->quotePrimarKm($trip, $beneficiary, $businessDate, $result),
            'primar_tona' => $this->quotePrimarTona($trip, $beneficiary, $businessDate, $result),
            'distributie' => $this->quoteDistributie($trip, $beneficiary, $businessDate, $result),
            'primar_distributie' => $this->quotePrimarDistributie($trip, $beneficiary, $businessDate, $result),
            'compresor' => $this->quoteCompresor($trip, $beneficiary, $businessDate, $result),
            default => $result,
        };
    }

    // =================================================================
    // PRIMAR KM   total = km × pret_km   (rate: beneficiary level)
    // =================================================================
    private function quotePrimarKm(array $trip, array $beneficiary, string $date, array $result): array
    {
        $beneficiaryId = (int) $beneficiary['id'];
        $route = $this->resolvePrimaryRoute($trip, $beneficiaryId);

        // Quantity: the route's agreed km overrides the operator's value,
        // unless the route is flagged for manual km entry.
        $km = (float) ($trip['km_cursa'] ?? 0);
        if ($route !== null) {
            if (empty($route['km_agreati_manual']) && (int) $route['km_tarifare'] > 0) {
                $km = (float) $route['km_tarifare'];
                $result['notes'][] = 'Km facturați provin din configurarea rutei (km tarifare).';
            } elseif (!empty($route['km_agreati_manual'])) {
                $result['notes'][] = 'Rută cu km agreați introduși manual în cursă.';
            }
        } else {
            $result['warnings'][] = 'Nu există o rută Primar configurată pentru combinația Loc ↔ Zonă.';
        }

        // Fixed-price override — FULL replacement.
        $override = $this->resolveFixedPriceOverride($beneficiaryId, $route, 'primar', $date);
        if ($override !== null) {
            return $this->applyFixedPrice($result, $override, 'primar');
        }

        $rate = $this->resolveRate($beneficiaryId, 'pret_km', null, $date, (float) ($beneficiary['pret_km'] ?? 0));
        if ($rate['value'] <= 0) {
            $fallback = (float) ($beneficiary['pret_tarifare'] ?? 0);
            if ($fallback > 0) {
                $rate['value'] = $fallback;
                $rate['version_id'] = null;
                $result['warnings'][] = 'Tariful provine din câmpul legacy `pret_tarifare` (fallback ascuns).';
            }
        }

        $total = $km * $rate['value'];

        $result['ok'] = true;
        $result['unit'] = 'lei/km';
        $result['pret_tarifare'] = round($rate['value'], 2);
        $result['total_facturare'] = round($total, 2);
        $result['tariff_version_id'] = $rate['version_id'];
        $result['components'][] = [
            'key' => 'pret_km',
            'label' => 'Preț / km',
            'rate' => round($rate['value'], 4),
            'unit' => 'lei/km',
            'quantity' => round($km, 2),
            'quantity_unit' => 'km',
            'amount' => round($total, 2),
            'version_id' => $rate['version_id'],
            'source' => $rate['source'],
        ];

        if ($rate['value'] <= 0) {
            $result['warnings'][] = 'Beneficiarul nu are un tarif lei/km configurat.';
        }

        return $result;
    }

    // =================================================================
    // PRIMAR TONE   total = tone × pret_tona   (km NOT invoiced)
    // =================================================================
    private function quotePrimarTona(array $trip, array $beneficiary, string $date, array $result): array
    {
        $beneficiaryId = (int) $beneficiary['id'];
        $route = $this->resolvePrimaryRoute($trip, $beneficiaryId);

        $override = $this->resolveFixedPriceOverride($beneficiaryId, $route, 'primar', $date);
        if ($override !== null) {
            return $this->applyFixedPrice($result, $override, 'primar_tona');
        }

        $tone = (float) ($trip['cantitate_incarcata'] ?? 0);
        $rate = $this->resolveRate($beneficiaryId, 'pret_tona', null, $date, (float) ($beneficiary['pret_tona'] ?? 0));

        if ($rate['value'] <= 0) {
            $fallback = (float) ($beneficiary['pret_tarifare'] ?? 0);
            if ($fallback > 0) {
                $rate['value'] = $fallback;
                $rate['version_id'] = null;
                $result['warnings'][] = 'Tariful provine din câmpul legacy `pret_tarifare` (fallback ascuns).';
            }
        }

        $total = $tone * $rate['value'];

        $result['ok'] = true;
        $result['unit'] = 'lei/tona';
        $result['pret_tarifare'] = round($rate['value'], 2);
        $result['total_facturare'] = round($total, 2);
        $result['tariff_version_id'] = $rate['version_id'];
        $result['components'][] = [
            'key' => 'pret_tona',
            'label' => 'Preț / tonă',
            'rate' => round($rate['value'], 4),
            'unit' => 'lei/tona',
            'quantity' => round($tone, 2),
            'quantity_unit' => 'tone',
            'amount' => round($total, 2),
            'version_id' => $rate['version_id'],
            'source' => $rate['source'],
        ];
        $result['notes'][] = 'Kilometrii nu participă la valoarea facturată pentru Primar tone.';

        if ($rate['value'] <= 0) {
            $result['warnings'][] = 'Beneficiarul nu are un tarif lei/tonă configurat.';
        }

        return $result;
    }

    // =================================================================
    // DISTRIBUȚIE   mode-dependent, route-priced
    // =================================================================
    private function quoteDistributie(array $trip, array $beneficiary, string $date, array $result): array
    {
        $beneficiaryId = (int) $beneficiary['id'];
        $route = $this->resolveDistributionRoute($trip, $beneficiaryId, 'distributie');

        if ($route === null) {
            $result['warnings'][] = 'Nu există o regulă de distribuție configurată pentru Loc ↔ Zonă și vehiculul ales.';
            $result['ok'] = true;
            return $result;
        }

        $mode = $this->normalizeTariffMode((string) ($route['tarif_mod'] ?? ''));
        $usesTon = in_array($mode, ['tona_km', 'tona'], true);
        $usesKm = in_array($mode, ['tona_km', 'km'], true);

        $override = $this->resolveFixedPriceOverride($beneficiaryId, $route, 'distributie', $date);
        if ($override !== null) {
            return $this->applyFixedPrice($result, $override, 'distributie');
        }

        $tone = (float) ($trip['cantitate_incarcata'] ?? 0);
        $km = (float) ($trip['km_cursa'] ?? 0);

        $tonRate = ['value' => 0.0, 'version_id' => null, 'source' => 'mode_disabled'];
        $kmRate = ['value' => 0.0, 'version_id' => null, 'source' => 'mode_disabled'];

        if ($usesTon) {
            $tonRate = $this->resolveRate(
                $beneficiaryId,
                'tarif_tona',
                (int) $route['id'],
                $date,
                (float) ($route['tarif_tona'] ?? 0)
            );
            if ($tonRate['value'] <= 0) {
                $tonRate = $this->resolveDistributionTonFallback($trip, $beneficiary, $route, $result);
            }
        }

        if ($usesKm) {
            $kmRate = $this->resolveRate(
                $beneficiaryId,
                'cost_extra_km',
                (int) $route['id'],
                $date,
                (float) ($route['cost_extra_km'] ?? 0)
            );
            if ($kmRate['value'] <= 0) {
                $kmRate = $this->resolveDistributionKmFallback($trip, $beneficiary, $result);
            }
        }

        $tonComponent = $tone * $tonRate['value'];
        // For simple Distribuție the km component applies only when a km rate exists.
        $kmComponent = $kmRate['value'] > 0 ? $km * $kmRate['value'] : 0.0;
        $total = $tonComponent + $kmComponent;

        $result['ok'] = true;
        $result['total_facturare'] = round($total, 2);
        $result['pret_tarifare'] = round($tonRate['value'] > 0 ? $tonRate['value'] : $kmRate['value'], 2);
        $result['unit'] = $tonRate['value'] > 0 ? 'lei/tona' : 'lei/km';
        $result['tariff_version_id'] = $tonRate['version_id'] ?? $kmRate['version_id'];
        $result['tariff_mode'] = $mode;

        if ($usesTon) {
            $result['components'][] = [
                'key' => 'tarif_tona',
                'label' => 'Tarif tonă',
                'rate' => round($tonRate['value'], 4),
                'unit' => 'lei/tona',
                'quantity' => round($tone, 2),
                'quantity_unit' => 'tone',
                'amount' => round($tonComponent, 2),
                'version_id' => $tonRate['version_id'],
                'source' => $tonRate['source'],
            ];
        }
        if ($usesKm) {
            $result['components'][] = [
                'key' => 'cost_extra_km',
                'label' => 'Tarif km',
                'rate' => round($kmRate['value'], 4),
                'unit' => 'lei/km',
                'quantity' => round($km, 2),
                'quantity_unit' => 'km',
                'amount' => round($kmComponent, 2),
                'version_id' => $kmRate['version_id'],
                'source' => $kmRate['source'],
            ];
        }

        if ($tonRate['value'] <= 0 && $kmRate['value'] <= 0) {
            $result['warnings'][] = 'Nu există un tarif valid pentru această regulă de distribuție.';
        }

        return $result;
    }

    // =================================================================
    // P+D   total = tone × tarif_tona + km × cost_extra_km  (mode locked)
    // =================================================================
    private function quotePrimarDistributie(array $trip, array $beneficiary, string $date, array $result): array
    {
        $beneficiaryId = (int) $beneficiary['id'];
        $route = $this->resolveDistributionRoute($trip, $beneficiaryId, 'primar_distributie');

        if ($route === null) {
            $result['warnings'][] = 'Nu există o regulă P+D configurată pentru Loc ↔ Zonă și vehiculul ales.';
            $result['ok'] = true;
            return $result;
        }

        $override = $this->resolveFixedPriceOverride($beneficiaryId, $route, 'primar_distributie', $date);
        if ($override !== null) {
            return $this->applyFixedPrice($result, $override, 'primar_distributie');
        }

        $tone = (float) ($trip['cantitate_incarcata'] ?? 0);
        $km = (float) ($trip['km_cursa'] ?? 0);
        if ($km <= 0 && (int) ($route['km_tarifare'] ?? 0) > 0) {
            $km = (float) $route['km_tarifare'];
            $result['notes'][] = 'Km preluați din km tarifare (agreat) ai rutei.';
        }

        $tonRate = $this->resolveRate($beneficiaryId, 'tarif_tona', (int) $route['id'], $date, (float) ($route['tarif_tona'] ?? 0));
        $kmRate = $this->resolveRate($beneficiaryId, 'cost_extra_km', (int) $route['id'], $date, (float) ($route['cost_extra_km'] ?? 0));

        $tonComponent = $tone * $tonRate['value'];
        $kmComponent = $km * $kmRate['value'];
        $total = $tonComponent + $kmComponent;

        $result['ok'] = true;
        $result['unit'] = 'lei/tona';
        $result['pret_tarifare'] = round($tonRate['value'], 2);
        $result['total_facturare'] = round($total, 2);
        $result['tariff_version_id'] = $tonRate['version_id'];
        $result['tariff_mode'] = 'tona_km';
        $result['components'][] = [
            'key' => 'tarif_tona',
            'label' => 'Tarif tonă',
            'rate' => round($tonRate['value'], 4),
            'unit' => 'lei/tona',
            'quantity' => round($tone, 2),
            'quantity_unit' => 'tone',
            'amount' => round($tonComponent, 2),
            'version_id' => $tonRate['version_id'],
            'source' => $tonRate['source'],
        ];
        $result['components'][] = [
            'key' => 'cost_extra_km',
            'label' => 'Tarif km',
            'rate' => round($kmRate['value'], 4),
            'unit' => 'lei/km',
            'quantity' => round($km, 2),
            'quantity_unit' => 'km',
            'amount' => round($kmComponent, 2),
            'version_id' => $kmRate['version_id'],
            'source' => $kmRate['source'],
        ];
        $result['notes'][] = 'Km totali nu influențează valoarea facturată (doar indicatorii derivați).';

        return $result;
    }

    // =================================================================
    // COMPRESOR   five independent additive components
    // =================================================================
    private function quoteCompresor(array $trip, array $beneficiary, string $date, array $result): array
    {
        $beneficiaryId = (int) $beneficiary['id'];

        $definitions = [
            'pret_ora_aspirare' => ['qty' => 'ore_aspirare', 'label' => 'Oră aspirare', 'unit' => 'lei/ora', 'qunit' => 'ore'],
            'pret_km_dislocare' => ['qty' => 'km_dislocare', 'label' => 'Km dislocare', 'unit' => 'lei/km', 'qunit' => 'km'],
            'pret_tona_livrata' => ['qty' => 'tona_livrata', 'label' => 'Tonă livrată', 'unit' => 'lei/tona', 'qunit' => 'tone'],
            'pret_tona_aspirata_lichida' => ['qty' => 'tona_aspirata_lichida', 'label' => 'Tonă aspirată lichidă', 'unit' => 'lei/tona', 'qunit' => 'tone'],
            'pret_tona_aspirata_gazoasa' => ['qty' => 'tona_aspirata_gazoasa', 'label' => 'Tonă aspirată gazoasă', 'unit' => 'lei/tona', 'qunit' => 'tone'],
        ];

        $total = 0.0;
        $primaryVersionId = null;
        $representativeRate = 0.0;
        $representativeUnit = '';
        $activeRates = 0;

        foreach ($definitions as $componentKey => $definition) {
            $rate = $this->resolveRate(
                $beneficiaryId,
                $componentKey,
                null,
                $date,
                (float) ($beneficiary[$componentKey] ?? 0)
            );

            $quantity = (float) ($trip[$definition['qty']] ?? 0);

            // Components 4 and 5 self-gate: a zero rate removes them entirely.
            $applies = $rate['value'] > 0;
            $amount = $applies ? $quantity * $rate['value'] : 0.0;
            $total += $amount;

            if ($rate['value'] > 0) {
                $activeRates++;
                if ($representativeRate <= 0) {
                    $representativeRate = $rate['value'];
                    $representativeUnit = $definition['unit'];
                    $primaryVersionId = $rate['version_id'];
                }
            }

            $result['components'][] = [
                'key' => $componentKey,
                'label' => $definition['label'],
                'rate' => round($rate['value'], 4),
                'unit' => $definition['unit'],
                'quantity' => round($quantity, 2),
                'quantity_unit' => $definition['qunit'],
                'amount' => round($amount, 2),
                'version_id' => $rate['version_id'],
                'source' => $rate['source'],
                'active' => $applies,
            ];
        }

        $result['ok'] = true;
        $result['unit'] = $representativeUnit;
        $result['pret_tarifare'] = round($representativeRate, 2);
        $result['total_facturare'] = round($total, 2);
        $result['tariff_version_id'] = $primaryVersionId;

        if ($activeRates === 0) {
            $result['warnings'][] = 'Beneficiarul nu are niciun tarif Compresor configurat.';
        }

        return $result;
    }

    // =================================================================
    // Rate resolution
    // =================================================================

    /**
     * Resolve one component rate at a business date.
     *
     * Order: versioned tariff in force on that date → legacy configuration value.
     * The legacy fallback keeps the module working during the coexistence phase
     * described in the migration plan.
     *
     * @return array{value:float, version_id:?int, source:string, valid_from:?string, valid_to:?string}
     */
    public function resolveRate(
        int $beneficiaryId,
        string $componentKey,
        ?int $routeRefId,
        string $businessDate,
        float $legacyValue = 0.0
    ): array {
        $signature = TransportTariffModel::buildSignature($beneficiaryId, $componentKey, $routeRefId);
        $version = $this->tariffs->resolveVersionAt($signature, $businessDate);

        if (is_array($version)) {
            return [
                'value' => (float) $version['value'],
                'version_id' => (int) $version['id'],
                'source' => 'version',
                'valid_from' => (string) $version['valid_from'],
                'valid_to' => $version['valid_to'] !== null ? (string) $version['valid_to'] : null,
            ];
        }

        return [
            'value' => max(0.0, $legacyValue),
            'version_id' => null,
            'source' => 'legacy_config',
            'valid_from' => null,
            'valid_to' => null,
        ];
    }

    /**
     * The fixed-price override is a FULL replacement of the normal calculation —
     * never a minimum and never additive.
     *
     * @return array{value:float, version_id:?int}|null
     */
    private function resolveFixedPriceOverride(int $beneficiaryId, ?array $route, string $scope, string $date): ?array
    {
        if ($route === null) {
            return null;
        }
        if (empty($route['aplica_cost_cursa'])) {
            return null;
        }

        $rate = $this->resolveRate(
            $beneficiaryId,
            'cost_cursa',
            (int) $route['id'],
            $date,
            (float) ($route['cost_cursa'] ?? 0)
        );

        return $rate['value'] > 0
            ? ['value' => $rate['value'], 'version_id' => $rate['version_id']]
            : null;
    }

    private function applyFixedPrice(array $result, array $override, string $transportType): array
    {
        $result['ok'] = true;
        $result['fixed_price_applied'] = true;
        $result['unit'] = 'lei/cursa';
        $result['pret_tarifare'] = round($override['value'], 2);
        $result['total_facturare'] = round($override['value'], 2);
        $result['tariff_version_id'] = $override['version_id'];
        $result['components'][] = [
            'key' => 'cost_cursa',
            'label' => 'Cost / cursă (înlocuiește complet calculul)',
            'rate' => round($override['value'], 4),
            'unit' => 'lei/cursa',
            'quantity' => 1,
            'quantity_unit' => 'cursă',
            'amount' => round($override['value'], 2),
            'version_id' => $override['version_id'],
            'source' => 'override',
        ];
        $result['notes'][] = 'Cost / cursă activ: înlocuiește complet calculul normal.';

        return $result;
    }

    // -----------------------------------------------------------------
    // Distribution fallback chain (dormant/hidden tiers, flagged loudly)
    // -----------------------------------------------------------------

    private function resolveDistributionTonFallback(array $trip, array $beneficiary, array $route, array &$result): array
    {
        $loc = $this->loadLoadLocation((int) ($trip['loc_incarcare_id'] ?? 0));
        $zone = $this->loadZone((int) ($trip['zona_distributie_id'] ?? 0));

        $locTariff = (float) ($loc['tarif'] ?? 0);
        $zoneTariff = (float) ($zone['tarif_distributie'] ?? 0);
        $beneficiaryTariff = (float) ($beneficiary['pret_distributie_tona'] ?? 0);

        $sameRoute = $this->isSameDistributionPoint(
            (string) ($loc['nume'] ?? ''),
            (string) ($zone['nume'] ?? '')
        );

        $ordered = $sameRoute
            ? [['loc', $locTariff], ['zona', $zoneTariff], ['beneficiar', $beneficiaryTariff]]
            : [['zona', $zoneTariff], ['loc', $locTariff], ['beneficiar', $beneficiaryTariff]];

        foreach ($ordered as [$sourceName, $value]) {
            if ($value > 0) {
                $result['warnings'][] = sprintf(
                    'Tariful pe tonă provine dintr-un fallback legacy (%s), nu din regula de rută.',
                    $sourceName
                );

                return ['value' => $value, 'version_id' => null, 'source' => 'fallback_' . $sourceName];
            }
        }

        return ['value' => 0.0, 'version_id' => null, 'source' => 'none'];
    }

    private function resolveDistributionKmFallback(array $trip, array $beneficiary, array &$result): array
    {
        $zone = $this->loadZone((int) ($trip['zona_distributie_id'] ?? 0));
        $zoneExtra = (float) ($zone['cost_extra_km'] ?? 0);
        if ($zoneExtra > 0) {
            $result['warnings'][] = 'Tariful pe km provine dintr-un fallback legacy (zonă), nu din regula de rută.';

            return ['value' => $zoneExtra, 'version_id' => null, 'source' => 'fallback_zona'];
        }

        $beneficiaryKm = (float) ($beneficiary['pret_distributie_km'] ?? 0);
        if ($beneficiaryKm > 0) {
            $result['warnings'][] = 'Tariful pe km provine din câmpul ascuns `pret_distributie_km` al beneficiarului.';

            return ['value' => $beneficiaryKm, 'version_id' => null, 'source' => 'fallback_beneficiar'];
        }

        return ['value' => 0.0, 'version_id' => null, 'source' => 'none'];
    }

    // -----------------------------------------------------------------
    // Route resolution (bidirectional, vehicle-scoped)
    // -----------------------------------------------------------------

    private function resolvePrimaryRoute(array $trip, int $beneficiaryId): ?array
    {
        $locId = (int) ($trip['loc_incarcare_id'] ?? 0);
        $zoneId = (int) ($trip['zona_distributie_id'] ?? 0);
        $vehicleId = (int) ($trip['vehicle_id'] ?? 0);
        if ($locId <= 0 || $zoneId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT * FROM configurare_rute_primar
            WHERE beneficiar_id = :bid AND activ = 1
              AND (
                    (loc_incarcare_id = :loc AND zona_distributie_id = :zona)
                 OR (loc_incarcare_id = :zona2 AND zona_distributie_id = :loc2)
              )
            ORDER BY id DESC
        ');
        $stmt->execute([
            'bid' => $beneficiaryId,
            'loc' => $locId,
            'zona' => $zoneId,
            'zona2' => $zoneId,
            'loc2' => $locId,
        ]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $this->pickRuleForVehicle($rules, $vehicleId, true);
    }

    private function resolveDistributionRoute(array $trip, int $beneficiaryId, string $scope): ?array
    {
        $locId = (int) ($trip['loc_incarcare_id'] ?? 0);
        $zoneId = (int) ($trip['zona_distributie_id'] ?? 0);
        $vehicleId = (int) ($trip['vehicle_id'] ?? 0);
        if ($locId <= 0 || $zoneId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT * FROM configurare_rute_distributie
            WHERE beneficiar_id = :bid AND activ = 1 AND transport_scope = :scope
              AND (
                    (loc_incarcare_id = :loc AND zona_distributie_id = :zona)
                 OR (loc_incarcare_id = :zona2 AND zona_distributie_id = :loc2)
              )
            ORDER BY id DESC
        ');
        $stmt->execute([
            'bid' => $beneficiaryId,
            'scope' => $scope,
            'loc' => $locId,
            'zona' => $zoneId,
            'zona2' => $zoneId,
            'loc2' => $locId,
        ]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $this->pickRuleForVehicle($rules, $vehicleId, false);
    }

    /**
     * Vehicle tie-break. `$allowUnrestrictedFallback` reproduces the documented
     * asymmetry: the Primar resolver falls back to an unrestricted rule, the
     * distribution resolver does not once any sibling rule is vehicle-scoped.
     */
    private function pickRuleForVehicle(array $rules, int $vehicleId, bool $allowUnrestrictedFallback): ?array
    {
        if ($rules === []) {
            return null;
        }
        if ($vehicleId <= 0) {
            return $rules[0];
        }

        foreach ($rules as $rule) {
            $ids = $this->parseVehicleIds((string) ($rule['vehicle_ids'] ?? ''));
            if ($ids !== [] && in_array($vehicleId, $ids, true)) {
                return $rule;
            }
        }

        if ($allowUnrestrictedFallback) {
            foreach ($rules as $rule) {
                if (trim((string) ($rule['vehicle_ids'] ?? '')) === '') {
                    return $rule;
                }
            }
        }

        return count($rules) === 1 ? $rules[0] : null;
    }

    /** @return array<int,int> */
    private function parseVehicleIds(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function normalizeTariffMode(string $mode): string
    {
        $mode = trim(strtolower($mode));

        return in_array($mode, ['tona', 'km', 'tona_km'], true) ? $mode : 'tona_km';
    }

    private function isSameDistributionPoint(string $a, string $b): bool
    {
        $normalize = static function (string $value): string {
            $value = mb_strtolower(trim($value));
            if ($value === '') {
                return '';
            }
            $translit = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
            if (is_string($translit) && $translit !== '') {
                $value = strtolower($translit);
            }

            return trim((string) preg_replace('/\s+/u', ' ', $value));
        };

        $left = $normalize($a);
        $right = $normalize($b);

        return $left !== '' && $left === $right;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m) === 1) {
            return $m[0];
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function loadBeneficiary(int $id): ?array
    {
        if (array_key_exists($id, $this->beneficiaryCache)) {
            return $this->beneficiaryCache[$id];
        }

        $stmt = $this->db->prepare('SELECT * FROM configurare_beneficiari_transport WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $this->beneficiaryCache[$id] = is_array($row) ? $row : null;
    }

    private function loadLoadLocation(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM configurare_locuri_incarcare WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function loadZone(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM configurare_zone_distributie WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
