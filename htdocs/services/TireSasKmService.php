<?php
declare(strict_types=1);

require_once __DIR__ . '/OdometerProviders.php';
require_once __DIR__ . '/ReviziiCalculator.php';
require_once __DIR__ . '/TrailerSasKmService.php';

/**
 * Km anvelopelor din SAS, fara nicio legatura cu Dispecer curse.
 *
 * Fiecare montare numara deplasarile SAS de la anvelope_alocari.sas_de_la (minutul de dupa montare)
 * pana la demontare (sau pana acum). Deplasarile se pastreaza pe vehicul (anvelope_sas_miscari), iar
 * km fiecarei montari se recalculeaza din ele, deci o rulare repetata nu poate numara de doua ori.
 * Semiremorcile nu sunt in SAS: primesc deplasarile capului tractor, doar cat a fost cuplat.
 *
 * Fereastra se reia cu o zi in urma, pentru ca unitatile GPS pot trimite cu intarziere. O demontare
 * ramane deschisa recalcularii (km_sas_final = 0) pana cand SAS a fost citit cu o ora dupa ea.
 */
final class TireSasKmService
{
    private const STALE_SECONDS = 900;
    private const RESUME_SECONDS = 86400;
    private const SETTLE_SECONDS = 3600;
    private const MAX_DAYS_PER_CALL = 31;
    private const LOCK_NAME = 'anvelope_km_sas';

    private PDO $db;
    private SasOdometerSource $sas;
    private ?int $nowTs;

    public function __construct(PDO $db, ?SasOdometerSource $sas = null, ?int $nowTs = null)
    {
        $this->db = $db;
        $this->sas = $sas ?? new SasFleetOdometerSource($db);
        $this->nowTs = $nowTs;
        (new TireModel($db))->ensureSasKmSchema();
    }

    private function now(): int
    {
        return $this->nowTs ?? time();
    }

    /**
     * O rulare: actualizeaza cel mult $maxVehicles vehicule (cele mai vechi primele).
     *
     * @return array{synced:int, remaining:int, changed:int, errors:int, busy:bool, no_sas:list<string>, uncoupled:list<string>, last_synced:?string}
     */
    public function run(int $maxVehicles = 3): array
    {
        $result = ['synced' => 0, 'remaining' => 0, 'changed' => 0, 'errors' => 0, 'busy' => false, 'no_sas' => [], 'uncoupled' => [], 'last_synced' => null];
        if (!$this->sas->credentialsAvailable()) {
            $result['errors'] = 1;
            return $result;
        }
        if ((int) $this->db->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() !== 1) {
            $result['busy'] = true;
            return $result;
        }

        try {
            $allocations = $this->pendingAllocations();
            $couplings = $this->couplings($allocations);
            $carIds = $this->carIds();
            $states = $this->states();
            $needs = [];
            foreach ($allocations as $allocation) {
                $sources = $this->sources($allocation, $couplings);
                if ($sources === [] && $allocation['data_end'] === null && $this->isTrailer($allocation)) {
                    $result['uncoupled'][(string) $allocation['nr_inmatriculare']] = true;
                }
                foreach ($sources as [$vehicleId, $from, $to]) {
                    if (!isset($carIds[$vehicleId])) {
                        $result['no_sas'][(string) ($allocation['nr_inmatriculare'])] = true;
                        continue;
                    }
                    $needs[$vehicleId]['from'] = min($needs[$vehicleId]['from'] ?? PHP_INT_MAX, $from);
                    $needs[$vehicleId]['ended'] = max($needs[$vehicleId]['ended'] ?? 0, $allocation['demontat_la'] !== null ? (int) strtotime((string) $allocation['demontat_la']) : 0);
                    $needs[$vehicleId]['changed'] = max($needs[$vehicleId]['changed'] ?? 0, (int) strtotime((string) $allocation['updated_at']));
                    $needs[$vehicleId]['boundaries'][$from] = true;
                    if ($to < $this->now()) {
                        $needs[$vehicleId]['boundaries'][$to] = true;
                    }
                }
            }
            foreach ($needs as $vehicleId => $need) {
                $needs[$vehicleId]['straddle'] = $this->firstStraddledBoundary($vehicleId, array_keys($need['boundaries']));
            }

            $due = array_keys(array_filter($needs, fn (array $need, int $vehicleId): bool => $this->isDue($need, $states[$vehicleId] ?? null), ARRAY_FILTER_USE_BOTH));
            usort($due, static fn (int $a, int $b): int => strcmp((string) ($states[$a]['updated_at'] ?? ''), (string) ($states[$b]['updated_at'] ?? '')));

            $synced = [];
            foreach (array_slice($due, 0, max(1, $maxVehicles)) as $vehicleId) {
                if ($this->syncVehicle($vehicleId, $carIds[$vehicleId], $needs[$vehicleId], $states[$vehicleId] ?? null)) {
                    $synced[$vehicleId] = true;
                } else {
                    $result['errors']++;
                }
            }
            $result['synced'] = count($synced);
            $result['remaining'] = max(0, count($due) - $result['synced'] - $result['errors']);

            $states = $this->states();
            // O montare noua pe un vehicul deja citit (deplasarile ei sunt deja salvate) primeste km imediat,
            // fara sa astepte urmatoarea citire SAS a vehiculului.
            $recalc = $synced;
            foreach ($needs as $vehicleId => $need) {
                $coveredFrom = $states[$vehicleId]['acoperit_de_la'] ?? null;
                if ($coveredFrom !== null && $need['from'] >= (int) strtotime((string) $coveredFrom)) {
                    $recalc[$vehicleId] = true;
                }
            }
            if ($recalc !== []) {
                $result['changed'] = $this->recalculate($allocations, $couplings, $carIds, $states, $recalc);
            }
            $lastSynced = array_filter(array_column($states, 'synced_until'));
            $result['last_synced'] = $lastSynced !== [] ? max($lastSynced) : null;
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')")->fetchAll();
        }

        $result['no_sas'] = array_keys($result['no_sas']);
        $result['uncoupled'] = array_keys($result['uncoupled']);

        return $result;
    }

    /** Montarile care inca isi pot schimba km: cele active si demontarile neinchise definitiv. */
    private function pendingAllocations(): array
    {
        return $this->db->query(
            'SELECT a.id, a.vehicle_id, a.sas_de_la, a.demontat_la, a.data_end, a.km_sas, GREATEST(a.created_at, a.updated_at) AS updated_at, v.nr_inmatriculare, v.tip_vehicul
             FROM anvelope_alocari a
             INNER JOIN vehicule v ON v.id = a.vehicle_id
             WHERE a.sas_de_la IS NOT NULL AND (a.data_end IS NULL OR a.km_sas_final = 0)'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function isTrailer(array $allocation): bool
    {
        return str_starts_with((string) $allocation['tip_vehicul'], 'semiremorca');
    }

    /** @return array<int, list<array<string, mixed>>> semiremorca => cuplaje */
    private function couplings(array $allocations): array
    {
        $trailerIds = [];
        foreach ($allocations as $allocation) {
            if ($this->isTrailer($allocation)) {
                $trailerIds[(int) $allocation['vehicle_id']] = true;
            }
        }
        if ($trailerIds === []) {
            return [];
        }
        $rows = $this->db->query(
            'SELECT semiremorca_id, tractor_id, data_start, data_end FROM vehicule_cuplaje
             WHERE semiremorca_id IN (' . implode(',', array_map('intval', array_keys($trailerIds))) . ')'
        )->fetchAll(PDO::FETCH_ASSOC);
        $byTrailer = [];
        foreach ($rows as $row) {
            $byTrailer[(int) $row['semiremorca_id']][] = $row;
        }

        return $byTrailer;
    }

    /**
     * De unde vin km unei montari: vehiculul insusi sau, la semiremorca, capetele tractor cuplate.
     *
     * @return list<array{0:int, 1:int, 2:int}> [vehicul SAS, de la, pana la]
     */
    private function sources(array $allocation, array $couplings): array
    {
        $from = (int) strtotime((string) $allocation['sas_de_la']);
        $to = $allocation['demontat_la'] !== null ? (int) strtotime((string) $allocation['demontat_la']) : $this->now();
        if ($to <= $from) {
            return [];
        }
        if (!$this->isTrailer($allocation)) {
            return [[(int) $allocation['vehicle_id'], $from, $to]];
        }
        $sources = [];
        foreach ($couplings[(int) $allocation['vehicle_id']] ?? [] as $coupling) {
            $windowFrom = max($from, (int) strtotime((string) $coupling['data_start']));
            $windowTo = $coupling['data_end'] !== null ? min($to, (int) strtotime((string) $coupling['data_end'])) : $to;
            if ($windowTo > $windowFrom) {
                $sources[] = [(int) $coupling['tractor_id'], $windowFrom, $windowTo];
            }
        }

        return $sources;
    }

    /** @return array<int, int> vehicul => carId SAS */
    private function carIds(): array
    {
        $map = TrailerSasKmService::sasCarIds($this->db);
        try {
            foreach ($this->sas->fleetCars() as $car) {
                if ($car['local_vehicle_id'] !== null && !isset($map[$car['local_vehicle_id']])) {
                    $map[$car['local_vehicle_id']] = $car['sas_vehicle_id'];
                }
            }
        } catch (Throwable $exception) {
            error_log('[TireSasKm] flota SAS indisponibila: ' . $exception->getMessage());
        }

        return $map;
    }

    /** @return array<int, array<string, mixed>> */
    private function states(): array
    {
        return $this->db->query('SELECT vehicle_id, anvelope_sas_stare.* FROM anvelope_sas_stare')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    }

    private function isDue(array $need, ?array $state): bool
    {
        if ($state === null || $state['acoperit_de_la'] === null) {
            return true;
        }
        $attemptedAt = (int) strtotime((string) $state['updated_at']);

        return $attemptedAt <= $this->now() - self::STALE_SECONDS
            || $need['ended'] > $attemptedAt
            || $need['from'] < (int) strtotime((string) $state['acoperit_de_la'])
            // O montare/demontare noua cade in mijlocul unei deplasari deja salvate: deplasarea se recere taiata la acel minut.
            || ($need['straddle'] !== null && $need['changed'] >= $attemptedAt);
    }

    /** Primul moment de montare/demontare care cade in interiorul unei deplasari salvate (sau null). */
    private function firstStraddledBoundary(int $vehicleId, array $boundaries): ?int
    {
        sort($boundaries);
        $stmt = $this->db->prepare('SELECT 1 FROM anvelope_sas_miscari WHERE vehicle_id = :v AND start_at < :b AND end_at > :b2 LIMIT 1');
        foreach ($boundaries as $boundary) {
            $moment = date('Y-m-d H:i:s', (int) $boundary);
            $stmt->execute([':v' => $vehicleId, ':b' => $moment, ':b2' => $moment]);
            if ($stmt->fetchColumn() !== false) {
                return (int) $boundary;
            }
        }

        return null;
    }

    private function syncVehicle(int $vehicleId, int $carId, array $need, ?array $state): bool
    {
        $from = $need['from'];
        if ($state !== null && $state['acoperit_de_la'] !== null && $from >= (int) strtotime((string) $state['acoperit_de_la'])) {
            $from = max((int) strtotime((string) $state['acoperit_de_la']), (int) strtotime((string) $state['synced_until']) - self::RESUME_SECONDS);
        }
        if ($need['straddle'] !== null) {
            $from = min($from, $need['straddle']);
        }
        $from = (int) strtotime(date('Y-m-d 00:00:00', $from));
        $to = $this->now();

        // Cererile catre SAS se taie la fiecare moment de montare/demontare: SAS imparte o deplasare exact la
        // marginea intervalului cerut (dupa punctele GPS), deci km de dupa montare sunt cei reali, nu o proportie de timp.
        $cuts = array_values(array_filter(array_keys($need['boundaries']), static fn (int $b): bool => $b > $from && $b < $to));
        sort($cuts);
        $movements = [];
        try {
            $chunk = $from;
            while ($chunk < $to) {
                $chunkEnd = min($to, $chunk + self::MAX_DAYS_PER_CALL * 86400);
                foreach ($cuts as $cut) {
                    if ($cut > $chunk) {
                        $chunkEnd = min($chunkEnd, $cut);
                        break;
                    }
                }
                array_push($movements, ...$this->sas->fetchReadings($carId, date('Y-m-d H:i:s', $chunk), date('Y-m-d H:i:s', $chunkEnd))['movements']);
                $chunk = $chunkEnd;
            }
        } catch (Throwable $exception) {
            error_log('[TireSasKm] vehicul #' . $vehicleId . ': ' . $exception->getMessage());
            $this->db->prepare(
                'INSERT INTO anvelope_sas_stare (vehicle_id, last_status, last_note, updated_at) VALUES (:v, :s, :n, :u)
                 ON DUPLICATE KEY UPDATE last_status = VALUES(last_status), last_note = VALUES(last_note), updated_at = VALUES(updated_at)'
            )->execute([':v' => $vehicleId, ':s' => 'eroare', ':n' => mb_substr($exception->getMessage(), 0, 500), ':u' => date('Y-m-d H:i:s', $to)]);
            return false;
        }

        $coveredFrom = $state !== null && $state['acoperit_de_la'] !== null ? min($from, (int) strtotime((string) $state['acoperit_de_la'])) : $from;
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM anvelope_sas_miscari WHERE vehicle_id = :v AND start_at >= :f')
                ->execute([':v' => $vehicleId, ':f' => date('Y-m-d H:i:s', $from)]);
            $insert = $this->db->prepare('INSERT IGNORE INTO anvelope_sas_miscari (vehicle_id, start_at, end_at, km) VALUES (:v, :s, :e, :k)');
            foreach ($movements as $movement) {
                $insert->execute([':v' => $vehicleId, ':s' => $movement['start'], ':e' => $movement['end'], ':k' => $movement['km']]);
            }
            $this->db->prepare(
                'INSERT INTO anvelope_sas_stare (vehicle_id, acoperit_de_la, synced_until, last_status, last_note, updated_at) VALUES (:v, :c, :s, :st, NULL, :u)
                 ON DUPLICATE KEY UPDATE acoperit_de_la = VALUES(acoperit_de_la), synced_until = VALUES(synced_until), last_status = VALUES(last_status), last_note = NULL, updated_at = VALUES(updated_at)'
            )->execute([':v' => $vehicleId, ':c' => date('Y-m-d H:i:s', $coveredFrom), ':s' => date('Y-m-d H:i:s', $to), ':st' => 'ok', ':u' => date('Y-m-d H:i:s', $to)]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return true;
    }

    /** Km montarilor atinse de vehiculele tocmai citite. Intoarce cate si-au schimbat km afisati. */
    private function recalculate(array $allocations, array $couplings, array $carIds, array $states, array $synced): int
    {
        $segments = [];
        $update = $this->db->prepare(
            'UPDATE anvelope_alocari
             SET km_sas = :km,
                 km_end = IF(data_end IS NULL, km_end, COALESCE(km_start, 0) + km_inainte_sas + :km_rotunjit),
                 km_sas_final = :final
             WHERE id = :id'
        );

        $changed = 0;
        foreach ($allocations as $allocation) {
            $sources = $this->sources($allocation, $couplings);
            if (array_filter($sources, static fn (array $source): bool => isset($synced[$source[0]])) === []) {
                continue;
            }

            $km = 0.0;
            $settled = $allocation['demontat_la'] !== null;
            $settleAt = (int) strtotime((string) $allocation['demontat_la']) + self::SETTLE_SECONDS;
            foreach ($sources as [$vehicleId, $from, $to]) {
                if (!isset($carIds[$vehicleId])) {
                    $settled = false;
                    continue;
                }
                $segments[$vehicleId] ??= $this->storedSegments($vehicleId);
                $km += ReviziiCalculator::usage($segments[$vehicleId], [[$from, $to]], false)['km'];
                if ((int) strtotime((string) ($states[$vehicleId]['synced_until'] ?? '')) < $settleAt) {
                    $settled = false;
                }
            }

            $km = round($km, 1);
            $previous = (float) $allocation['km_sas'];
            if ($km === $previous && !$settled) {
                continue;
            }
            $update->execute([':km' => $km, ':km_rotunjit' => (int) round($km), ':final' => $settled ? 1 : 0, ':id' => (int) $allocation['id']]);
            if ((int) round($km) !== (int) round($previous)) {
                $changed++;
            }
        }

        return $changed;
    }

    /** @return array<int, array{start:int, end:int, km:float, idle:float}> */
    private function storedSegments(int $vehicleId): array
    {
        $stmt = $this->db->prepare('SELECT start_at, end_at, km FROM anvelope_sas_miscari WHERE vehicle_id = :v');
        $stmt->execute([':v' => $vehicleId]);
        $segments = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $segments[] = ['start' => (int) strtotime((string) $row['start_at']), 'end' => (int) strtotime((string) $row['end_at']), 'km' => (float) $row['km'], 'idle' => 0.0];
        }

        return ReviziiCalculator::normalizeSegments($segments)['segments'];
    }
}
