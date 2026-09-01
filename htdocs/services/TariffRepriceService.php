<?php
declare(strict_types=1);

/**
 * Bulk repricing of existing dispatcher trips after a tariff version change.
 *
 * Flow: after saving a version in "Administrare tarife transport", the page
 * shows a preview (how many trips would change, old vs. new totals) and the
 * operator confirms explicitly. Nothing is repriced without that confirmation.
 *
 * Rules:
 *   - only trips of the version's beneficiary + transport type, with
 *     data_cursa >= valid_from (and <= valid_to when the version is closed);
 *   - route-level components (cost_cursa / tarif_tona / cost_extra_km) only
 *     touch trips on that route (loc_incarcare_id + zona_distributie_id);
 *   - each trip is re-quoted through TransportPricingService at its OWN
 *     business date, so a trip dated before the version keeps its old price;
 *   - invoiced trips ARE repriced (explicit business decision) — the preview
 *     always shows how many invoiced trips are affected;
 *   - the trip update is a targeted UPDATE (financial columns only): km and
 *     operational data never change, so no vehicle-km resync is involved.
 */
class TariffRepriceService
{
    private PDO $db;
    private TransportPricingService $pricing;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->pricing = new TransportPricingService($db);
    }

    /**
     * Build the preview for a version: affected trips with old/new totals.
     *
     * @return array{
     *   version: array<string,mixed>,
     *   rows: array<int,array<string,mixed>>,
     *   changed: int, unchanged: int, skipped: int, invoiced_changed: int,
     *   old_total: float, new_total: float
     * }|null null when the version does not exist.
     */
    public function preview(int $versionId): ?array
    {
        $version = $this->loadVersion($versionId);
        if ($version === null) {
            return null;
        }

        $rows = [];
        $changed = 0;
        $unchanged = 0;
        $skipped = 0;
        $invoicedChanged = 0;
        $oldTotal = 0.0;
        $newTotal = 0.0;

        foreach ($this->loadCandidateTrips($version) as $trip) {
            $quoted = $this->quoteTrip($trip);
            if ($quoted === null) {
                $skipped++;
                continue;
            }

            $oldValue = round((float) $trip['total_facturare'], 2);
            $newValue = round((float) $quoted['total_facturare'], 2);
            $isChanged = abs($newValue - $oldValue) >= 0.005
                || abs(round((float) $quoted['pret_tarifare'], 2) - round((float) $trip['pret_tarifare'], 2)) >= 0.005;

            if (!$isChanged) {
                $unchanged++;
                continue;
            }

            $changed++;
            $oldTotal += $oldValue;
            $newTotal += $newValue;
            $isInvoiced = (string) $trip['status_facturare'] === 'facturat';
            if ($isInvoiced) {
                $invoicedChanged++;
            }

            $rows[] = [
                'id' => (int) $trip['id'],
                'data_cursa' => (string) $trip['data_cursa'],
                'vehicul' => (string) ($trip['nr_inmatriculare'] ?? ''),
                'status_facturare' => (string) $trip['status_facturare'],
                'old_total' => $oldValue,
                'new_total' => $newValue,
            ];
        }

        return [
            'version' => $version,
            'rows' => $rows,
            'changed' => $changed,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'invoiced_changed' => $invoicedChanged,
            'old_total' => round($oldTotal, 2),
            'new_total' => round($newTotal, 2),
        ];
    }

    /**
     * Reprice all affected trips for a version. Re-quotes at apply time (the
     * preview is informative, never trusted as stale data).
     *
     * @return array{changed:int, unchanged:int, skipped:int, invoiced_changed:int}|null
     */
    public function apply(int $versionId, ?int $userId): ?array
    {
        $version = $this->loadVersion($versionId);
        if ($version === null) {
            return null;
        }

        $changed = 0;
        $unchanged = 0;
        $skipped = 0;
        $invoicedChanged = 0;

        $update = $this->db->prepare('
            UPDATE curse_dispecer
            SET pret_tarifare = :pret,
                total_facturare = :total,
                cost_km_primar = :ckp,
                cost_km_distributie = :ckd,
                cost_km_mixt = :ckm,
                cost_km_compresor = :ckc,
                tariff_version_id = :version_id,
                tariff_breakdown_json = :breakdown,
                updated_at = :now
            WHERE id = :id
        ');
        $audit = $this->db->prepare('
            INSERT INTO cursa_audit_log (cursa_id, action, performed_by, performed_at, details_json)
            VALUES (:cursa_id, :action, :performed_by, :performed_at, :details_json)
        ');

        $this->db->beginTransaction();
        try {
            foreach ($this->loadCandidateTrips($version) as $trip) {
                $quoted = $this->quoteTrip($trip);
                if ($quoted === null) {
                    $skipped++;
                    continue;
                }

                $oldValue = round((float) $trip['total_facturare'], 2);
                $oldPrice = round((float) $trip['pret_tarifare'], 2);
                $newValue = round((float) $quoted['total_facturare'], 2);
                $newPrice = round((float) $quoted['pret_tarifare'], 2);

                if (abs($newValue - $oldValue) < 0.005 && abs($newPrice - $oldPrice) < 0.005) {
                    $unchanged++;
                    continue;
                }

                $costKm = $this->recomputeCostKmSnapshots($trip, $newValue);

                $breakdown = json_encode($quoted['quote'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $update->bindValue(':pret', number_format($newPrice, 2, '.', ''));
                $update->bindValue(':total', number_format($newValue, 2, '.', ''));
                $update->bindValue(':ckp', number_format($costKm['cost_km_primar'], 2, '.', ''));
                $update->bindValue(':ckd', number_format($costKm['cost_km_distributie'], 2, '.', ''));
                $update->bindValue(':ckm', number_format($costKm['cost_km_mixt'], 2, '.', ''));
                $update->bindValue(':ckc', number_format($costKm['cost_km_compresor'], 2, '.', ''));
                if ($quoted['tariff_version_id'] !== null && (int) $quoted['tariff_version_id'] > 0) {
                    $update->bindValue(':version_id', (int) $quoted['tariff_version_id'], PDO::PARAM_INT);
                } else {
                    $update->bindValue(':version_id', null, PDO::PARAM_NULL);
                }
                if (is_string($breakdown) && $breakdown !== '') {
                    $update->bindValue(':breakdown', $breakdown);
                } else {
                    $update->bindValue(':breakdown', null, PDO::PARAM_NULL);
                }
                $update->bindValue(':now', date('Y-m-d H:i:s'));
                $update->bindValue(':id', (int) $trip['id'], PDO::PARAM_INT);
                $update->execute();

                $isInvoiced = (string) $trip['status_facturare'] === 'facturat';
                if ($isInvoiced) {
                    $invoicedChanged++;
                }

                $details = json_encode([
                    'source' => 'tariff_reprice',
                    'tariff_version_id' => $versionId,
                    'component_key' => (string) $version['component_key'],
                    'old_pret_tarifare' => $oldPrice,
                    'new_pret_tarifare' => $newPrice,
                    'old_total_facturare' => $oldValue,
                    'new_total_facturare' => $newValue,
                    'was_invoiced' => $isInvoiced,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $audit->bindValue(':cursa_id', (int) $trip['id'], PDO::PARAM_INT);
                $audit->bindValue(':action', 'updated');
                if ($userId !== null && $userId > 0) {
                    $audit->bindValue(':performed_by', $userId, PDO::PARAM_INT);
                } else {
                    $audit->bindValue(':performed_by', null, PDO::PARAM_NULL);
                }
                $audit->bindValue(':performed_at', date('Y-m-d H:i:s'));
                if (is_string($details)) {
                    $audit->bindValue(':details_json', $details);
                } else {
                    $audit->bindValue(':details_json', null, PDO::PARAM_NULL);
                }
                $audit->execute();

                $changed++;
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return [
            'changed' => $changed,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'invoiced_changed' => $invoicedChanged,
        ];
    }

    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function loadVersion(int $versionId): ?array
    {
        if ($versionId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM transport_tariff_versions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Trips potentially affected by the version. The re-quote at the trip's
     * own date remains the authority — this SELECT only narrows the scan.
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadCandidateTrips(array $version): array
    {
        $sql = '
            SELECT c.id, c.beneficiar_id, c.tip_transport, c.data_cursa, c.vehicle_id,
                   c.loc_incarcare_id, c.zona_distributie_id,
                   c.cantitate_incarcata, c.km_cursa, c.km_totali, c.ore_aspirare, c.km_dislocare,
                   c.tona_livrata, c.tona_aspirata_lichida, c.tona_aspirata_gazoasa,
                   c.pret_tarifare, c.total_facturare, c.status_facturare,
                   c.cost_km_primar, c.cost_km_distributie, c.cost_km_mixt, c.cost_km_compresor,
                   v.nr_inmatriculare
            FROM curse_dispecer c
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            WHERE c.deleted_at IS NULL
              AND c.beneficiar_id = :beneficiar_id
              AND c.tip_transport = :tip_transport
              AND c.data_cursa >= :valid_from
        ';
        $params = [
            'beneficiar_id' => (int) $version['beneficiar_id'],
            'tip_transport' => (string) $version['transport_type'],
            'valid_from' => (string) $version['valid_from'],
        ];

        $validTo = (string) ($version['valid_to'] ?? '');
        if ($validTo !== '') {
            $sql .= ' AND c.data_cursa <= :valid_to';
            $params['valid_to'] = $validTo;
        }

        // Route-scoped component: restrict to that route's Loc <-> Zona pair.
        $locId = (int) ($version['loc_incarcare_id'] ?? 0);
        $zoneId = (int) ($version['zona_distributie_id'] ?? 0);
        if ((int) ($version['route_ref_id'] ?? 0) > 0 && $locId > 0 && $zoneId > 0) {
            $sql .= ' AND c.loc_incarcare_id = :loc_id AND c.zona_distributie_id = :zona_id';
            $params['loc_id'] = $locId;
            $params['zona_id'] = $zoneId;
        }

        $sql .= ' ORDER BY c.data_cursa ASC, c.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Re-quote one trip at its business date. Mirrors the per-trip
     * "Recalculeaza tariful" path: a result counts only when at least one
     * component resolved from a tariff VERSION.
     *
     * @return array{pret_tarifare:float, total_facturare:float, tariff_version_id:?int, quote:array<string,mixed>}|null
     */
    private function quoteTrip(array $trip): ?array
    {
        try {
            $quote = $this->pricing->quote([
                'beneficiar_id' => (int) $trip['beneficiar_id'],
                'tip_transport' => (string) $trip['tip_transport'],
                'data_cursa' => (string) $trip['data_cursa'],
                'vehicle_id' => (int) ($trip['vehicle_id'] ?? 0),
                'loc_incarcare_id' => (int) ($trip['loc_incarcare_id'] ?? 0),
                'zona_distributie_id' => (int) ($trip['zona_distributie_id'] ?? 0),
                'cantitate_incarcata' => (float) ($trip['cantitate_incarcata'] ?? 0),
                'km_cursa' => (float) ($trip['km_cursa'] ?? 0),
                'km_totali' => (float) ($trip['km_totali'] ?? 0),
                'ore_aspirare' => (float) ($trip['ore_aspirare'] ?? 0),
                'km_dislocare' => (float) ($trip['km_dislocare'] ?? 0),
                'tona_livrata' => (float) ($trip['tona_livrata'] ?? 0),
                'tona_aspirata_lichida' => (float) ($trip['tona_aspirata_lichida'] ?? 0),
                'tona_aspirata_gazoasa' => (float) ($trip['tona_aspirata_gazoasa'] ?? 0),
            ]);
        } catch (Throwable $exception) {
            error_log('[TariffRepriceService][quote] cursa #' . (int) $trip['id'] . ': ' . $exception->getMessage());
            return null;
        }

        if (empty($quote['ok'])) {
            return null;
        }

        $resolvedFromVersion = false;
        foreach ((array) ($quote['components'] ?? []) as $component) {
            if ((string) ($component['source'] ?? '') === 'version') {
                $resolvedFromVersion = true;
                break;
            }
        }
        if (!$resolvedFromVersion) {
            return null;
        }

        return [
            'pret_tarifare' => (float) ($quote['pret_tarifare'] ?? 0),
            'total_facturare' => (float) ($quote['total_facturare'] ?? 0),
            'tariff_version_id' => isset($quote['tariff_version_id']) && $quote['tariff_version_id'] !== null
                ? (int) $quote['tariff_version_id']
                : null,
            'quote' => $quote,
        ];
    }

    /**
     * Refresh the per-type cost/km snapshot from the new total, using the same
     * denominators as the historical backfill (revenue/km, per transport type).
     * Columns not owned by the trip's type keep their stored values.
     *
     * @return array{cost_km_primar:float, cost_km_distributie:float, cost_km_mixt:float, cost_km_compresor:float}
     */
    private function recomputeCostKmSnapshots(array $trip, float $newTotal): array
    {
        $result = [
            'cost_km_primar' => round((float) ($trip['cost_km_primar'] ?? 0), 2),
            'cost_km_distributie' => round((float) ($trip['cost_km_distributie'] ?? 0), 2),
            'cost_km_mixt' => round((float) ($trip['cost_km_mixt'] ?? 0), 2),
            'cost_km_compresor' => round((float) ($trip['cost_km_compresor'] ?? 0), 2),
        ];

        $type = (string) $trip['tip_transport'];
        $kmCursa = (float) ($trip['km_cursa'] ?? 0);
        $kmTotali = (float) ($trip['km_totali'] ?? 0);
        $kmDislocare = (float) ($trip['km_dislocare'] ?? 0);

        if (($type === 'primar' || $type === 'primar_tona') && $kmCursa > 0) {
            $result['cost_km_primar'] = round($newTotal / $kmCursa, 2);
            $result['cost_km_mixt'] = $result['cost_km_primar'];
        } elseif ($type === 'distributie' && $kmCursa > 0) {
            $result['cost_km_distributie'] = round($newTotal / $kmCursa, 2);
            $result['cost_km_mixt'] = $result['cost_km_distributie'];
        } elseif ($type === 'primar_distributie' && $kmTotali > 0) {
            $result['cost_km_mixt'] = round($newTotal / $kmTotali, 2);
        } elseif ($type === 'compresor' && $kmDislocare > 0) {
            $result['cost_km_compresor'] = round($newTotal / $kmDislocare, 2);
        }

        return $result;
    }
}
