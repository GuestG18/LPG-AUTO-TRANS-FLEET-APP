<?php
declare(strict_types=1);

require_once __DIR__ . '/OdometerProviders.php';
require_once __DIR__ . '/ReviziiCalculator.php';

/**
 * Km semiremorcilor din SAS (Centru Kilometraj).
 *
 * De la data de comutare (km_center_setari.semiremorci_sas_de_la), contorul unei
 * semiremorci (vehicule.km_bord / km_revizie) nu mai creste din km curselor din
 * Dispecer, ci din km SAS (distanta pe segmente) ai capului tractor cuplat, pe
 * ferestrele de cuplare datate (vehicule_cuplaje.data_start / data_end).
 *
 * Contorul ramane un contor: toate paginile care lucreaza pe diferente (anvelope,
 * piese, revizii) primesc km SAS fara salt. Ce s-a numarat inainte de comutare nu
 * se schimba.
 *
 * Granularitate pe zile inchise: o zi se adauga o singura data, dupa ce s-a terminat
 * (pana la 00:00 azi). O zi fara raspuns SAS se reia la rularea urmatoare; nimic nu
 * se inventeaza. Fractiunile de km se pastreaza (km_rest) ca suma sa nu derive.
 */
final class TrailerSasKmService
{
    /** Cate zile se aduc dintr-un apel SAS (fereastra de peste o zi: SAS da distanta oricum). */
    private const MAX_DAYS_PER_CALL = 31;

    private PDO $db;
    private SasOdometerSource $sas;
    /** @var callable|null */
    private $logger;
    private ?int $nowTs;

    public function __construct(PDO $db, ?SasOdometerSource $sas = null, ?callable $logger = null, ?int $nowTs = null)
    {
        $this->db = $db;
        $this->sas = $sas ?? new SasFleetOdometerSource($db);
        $this->logger = $logger;
        $this->nowTs = $nowTs;
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        // Setarile Centru Kilometraj (aici sta data de comutare); aceeasi definitie ca OdometerCenterModel.
        $this->db->exec("CREATE TABLE IF NOT EXISTS km_center_setari (
            setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->db->exec("CREATE TABLE IF NOT EXISTS semiremorci_km_sas (
            vehicle_id INT UNSIGNED NOT NULL PRIMARY KEY,
            synced_until DATETIME NOT NULL,
            km_rest DECIMAL(10,3) NOT NULL DEFAULT 0,
            km_total DECIMAL(12,1) NOT NULL DEFAULT 0,
            last_status VARCHAR(20) NULL,
            last_note VARCHAR(500) NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT fk_semi_km_sas_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->db->exec("CREATE TABLE IF NOT EXISTS semiremorci_km_sas_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT UNSIGNED NOT NULL,
            tractor_id INT UNSIGNED NULL,
            window_from DATETIME NOT NULL,
            window_to DATETIME NOT NULL,
            km DECIMAL(10,1) NOT NULL DEFAULT 0,
            km_applied INT NOT NULL DEFAULT 0,
            note VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_semi_km_sas_log (vehicle_id, window_from),
            CONSTRAINT fk_semi_km_sas_log_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function now(): int
    {
        return $this->nowTs ?? time();
    }

    /**
     * Data de comutare. La prima rulare se fixeaza la urmatorul 00:00: ziua in care
     * s-a instalat actualizarea ramane pe km curselor (altfel cursele introduse in acea
     * zi inainte de actualizare s-ar numara de doua ori).
     */
    public static function cutover(PDO $db, ?int $nowTs = null): ?string
    {
        try {
            $value = $db->query("SELECT setting_value FROM km_center_setari WHERE setting_key = 'semiremorci_sas_de_la'")->fetchColumn();
        } catch (Throwable) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function ensureCutover(): string
    {
        $existing = self::cutover($this->db);
        if ($existing !== null) {
            return $existing;
        }
        $value = date('Y-m-d 00:00:00', strtotime('tomorrow', $this->now()));
        $this->db->prepare(
            "INSERT IGNORE INTO km_center_setari (setting_key, setting_value) VALUES ('semiremorci_sas_de_la', :v)"
        )->execute([':v' => $value]);

        return (string) self::cutover($this->db);
    }

    /**
     * O rulare: pentru fiecare semiremorca, zilele inchise de la ultima rulare.
     *
     * @param list<int>|null $onlyVehicleIds restrange rularea (teste / o singura semiremorca)
     * @return array{trailers:int, days:int, km:float, calls:int, errors:int, message:string}
     */
    public function run(int $maxCalls = 60, ?array $onlyVehicleIds = null): array
    {
        $stats = ['trailers' => 0, 'days' => 0, 'km' => 0.0, 'calls' => 0, 'errors' => 0, 'message' => ''];
        $cutover = $this->ensureCutover();
        $today = date('Y-m-d 00:00:00', $this->now());
        if ($today <= $cutover) {
            $stats['message'] = 'Km semiremorcilor din SAS încep de la ' . date('d.m.Y', strtotime($cutover)) . '.';
            return $stats;
        }
        if (!$this->sas->credentialsAvailable()) {
            $stats['message'] = 'Credențialele SAS nu sunt configurate.';
            return $stats;
        }

        $carIds = self::sasCarIds($this->db);
        $states = $this->db->query('SELECT * FROM semiremorci_km_sas')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $trailers = $this->db->query("SELECT id, nr_inmatriculare, tip_vehicul FROM vehicule WHERE tip_vehicul LIKE 'semiremorca%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        $only = $onlyVehicleIds !== null ? array_flip(array_map('intval', $onlyVehicleIds)) : null;
        foreach ($trailers as $trailer) {
            $trailerId = (int) $trailer['id'];
            if ($only !== null && !isset($only[$trailerId])) {
                continue;
            }
            $from = isset($states[$trailerId]) ? (string) $states[$trailerId]['synced_until'] : $cutover;
            while ($from < $today) {
                if ($stats['calls'] >= $maxCalls) {
                    $stats['message'] = 'Buget de apeluri SAS atins; restul la rularea următoare.';
                    break 2;
                }
                $to = min($today, date('Y-m-d 00:00:00', strtotime($from . ' +' . self::MAX_DAYS_PER_CALL . ' days')));
                $result = $this->syncWindow($trailerId, $from, $to, $carIds, $stats['calls']);
                if ($result['status'] === 'eroare') {
                    $stats['errors']++;
                    $this->saveState($trailerId, $from, 0.0, 0.0, 'eroare', $result['note']);
                    break;
                }
                $stats['days'] += (int) round((strtotime($to) - strtotime($from)) / 86400);
                $stats['km'] += $result['km'];
                $from = $to;
            }
            $stats['trailers']++;
        }
        if ($stats['message'] === '') {
            $stats['message'] = sprintf('%d semiremorci actualizate din SAS, %s km adăugați%s.', $stats['trailers'],
                number_format($stats['km'], 0, ',', '.'), $stats['errors'] > 0 ? ', ' . $stats['errors'] . ' erori SAS (se reiau)' : '');
        }

        return $stats;
    }

    /**
     * Km unei semiremorci intr-o fereastra de zile inchise: suma km SAS ai fiecarui cap
     * tractor cu care a fost cuplata, doar pe intervalul cuplarii. Se aplica intr-o
     * tranzactie impreuna cu avansarea starii (o fereastra nu se poate numara de doua ori).
     *
     * @param array<int, int> $carIds tractor_id => SAS carId
     * @return array{status:string, km:float, note:?string}
     */
    private function syncWindow(int $trailerId, string $from, string $to, array $carIds, int &$calls): array
    {
        $fromTs = (int) strtotime($from);
        $toTs = (int) strtotime($to);
        $parts = [];
        $notes = [];
        foreach ($this->couplings($trailerId, $from, $to) as $coupling) {
            $wFrom = max($fromTs, (int) strtotime((string) $coupling['data_start']));
            $wTo = min($toTs, $coupling['data_end'] !== null ? (int) strtotime((string) $coupling['data_end']) : $toTs);
            if ($wTo <= $wFrom) {
                continue;
            }
            $tractorId = (int) $coupling['tractor_id'];
            $carId = $carIds[$tractorId] ?? null;
            if ($carId === null) {
                $notes[] = 'Capul tractor #' . $tractorId . ' nu este în SAS Fleet: 0 km pe ' . date('d.m.Y H:i', $wFrom) . ' – ' . date('d.m.Y H:i', $wTo) . '.';
                $parts[] = ['tractor_id' => $tractorId, 'from' => $wFrom, 'to' => $wTo, 'km' => 0.0];
                continue;
            }
            $calls++;
            try {
                $data = $this->sas->fetchReadings($carId, date('Y-m-d H:i:s', $wFrom), date('Y-m-d H:i:s', $wTo));
            } catch (Throwable $exception) {
                $this->log('semiremorca #' . $trailerId . ', carId=' . $carId . ': ' . $exception->getMessage());
                return ['status' => 'eroare', 'km' => 0.0, 'note' => 'SAS Fleet nu a răspuns: ' . $exception->getMessage()];
            }
            $segments = array_map(static fn (array $m): array => [
                'start' => (int) strtotime((string) $m['start']),
                'end' => (int) strtotime((string) $m['end']),
                'km' => (float) $m['km'],
                'idle' => 0.0,
            ], $data['movements']);
            $normalized = ReviziiCalculator::normalizeSegments($segments);
            // Doar km din intervalul cuplarii (segmentele de la margini, proportional).
            $km = ReviziiCalculator::usage($normalized['segments'], [[$wFrom, $wTo]], false)['km'];
            if ($normalized['rejected'] > 0) {
                $notes[] = $normalized['rejected'] . ' segmente SAS respinse ca implauzibile.';
            }
            $parts[] = ['tractor_id' => $tractorId, 'from' => $wFrom, 'to' => $wTo, 'km' => $km];
        }
        if ($parts === []) {
            $notes[] = 'Necuplată în perioadă: 0 km.';
        }

        $total = array_sum(array_column($parts, 'km'));
        $this->apply($trailerId, $from, $to, $parts, $total, $notes === [] ? null : implode(' ', $notes));

        return ['status' => 'ok', 'km' => $total, 'note' => null];
    }

    private function apply(int $trailerId, string $from, string $to, array $parts, float $km, ?string $note): void
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $stateStmt = $this->db->prepare('SELECT * FROM semiremorci_km_sas WHERE vehicle_id = :v FOR UPDATE');
            $stateStmt->execute([':v' => $trailerId]);
            $state = $stateStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($state !== null && (string) $state['synced_until'] > $from) {
                // Alta rulare a aplicat deja fereastra.
                if ($ownTransaction) {
                    $this->db->rollBack();
                }
                return;
            }
            $rest = (float) ($state['km_rest'] ?? 0) + $km;
            $applied = (int) floor($rest);
            $rest -= $applied;

            if ($applied > 0) {
                // Aceeasi regula ca Dispecer curse: km_bord creste, km_revizie (km pana la revizie) scade.
                $this->db->prepare(
                    'UPDATE vehicule SET km_bord = COALESCE(km_bord, 0) + :a, km_revizie = GREATEST(0, COALESCE(km_revizie, 0) - :b), updated_at = :u WHERE id = :v'
                )->execute([':a' => $applied, ':b' => $applied, ':u' => date('Y-m-d H:i:s'), ':v' => $trailerId]);
            }
            $log = $this->db->prepare(
                'INSERT INTO semiremorci_km_sas_log (vehicle_id, tractor_id, window_from, window_to, km, km_applied, note, created_at)
                 VALUES (:v, :t, :f, :to, :km, :a, :n, :c)'
            );
            $now = date('Y-m-d H:i:s');
            if ($parts === []) {
                $log->execute([':v' => $trailerId, ':t' => null, ':f' => $from, ':to' => $to, ':km' => 0, ':a' => $applied, ':n' => $note, ':c' => $now]);
            }
            foreach ($parts as $i => $part) {
                $log->execute([
                    ':v' => $trailerId, ':t' => $part['tractor_id'],
                    ':f' => date('Y-m-d H:i:s', $part['from']), ':to' => date('Y-m-d H:i:s', $part['to']),
                    ':km' => round($part['km'], 1), ':a' => $i === 0 ? $applied : 0,
                    ':n' => $i === 0 && $note !== null ? mb_substr($note, 0, 500) : null, ':c' => $now,
                ]);
            }
            $this->saveState($trailerId, $to, $rest, round((float) ($state['km_total'] ?? 0) + $km, 1), 'ok', $note);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function saveState(int $trailerId, string $syncedUntil, float $rest, float $total, string $status, ?string $note): void
    {
        if ($status === 'eroare') {
            // Pe eroare se pastreaza pozitia si restul; doar starea se schimba.
            $this->db->prepare(
                'INSERT INTO semiremorci_km_sas (vehicle_id, synced_until, last_status, last_note, updated_at) VALUES (:v, :s, :st, :n, :u)
                 ON DUPLICATE KEY UPDATE last_status = VALUES(last_status), last_note = VALUES(last_note), updated_at = VALUES(updated_at)'
            )->execute([':v' => $trailerId, ':s' => $syncedUntil, ':st' => $status, ':n' => $note !== null ? mb_substr($note, 0, 500) : null, ':u' => date('Y-m-d H:i:s')]);
            return;
        }
        $this->db->prepare(
            'INSERT INTO semiremorci_km_sas (vehicle_id, synced_until, km_rest, km_total, last_status, last_note, updated_at)
             VALUES (:v, :s, :r, :t, :st, :n, :u)
             ON DUPLICATE KEY UPDATE synced_until = VALUES(synced_until), km_rest = VALUES(km_rest), km_total = VALUES(km_total),
                last_status = VALUES(last_status), last_note = VALUES(last_note), updated_at = VALUES(updated_at)'
        )->execute([
            ':v' => $trailerId, ':s' => $syncedUntil, ':r' => round($rest, 3), ':t' => $total, ':st' => $status,
            ':n' => $note !== null ? mb_substr($note, 0, 500) : null, ':u' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Cuplajele semiremorcii care se suprapun cu [from, to). */
    private function couplings(int $trailerId, string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            'SELECT tractor_id, data_start, data_end FROM vehicule_cuplaje
             WHERE semiremorca_id = :s AND data_start < :to AND (data_end IS NULL OR data_end > :f)
             ORDER BY data_start ASC, id ASC'
        );
        $stmt->execute([':s' => $trailerId, ':to' => $to, ':f' => $from]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** vehicul => carId SAS: maparea din Centru Kilometraj, altfel cea din Revizii. */
    public static function sasCarIds(PDO $db): array
    {
        $map = [];
        try {
            foreach ($db->query('SELECT vehicle_id, sas_car_id FROM revizii_sas_vehicule')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(int) $row['vehicle_id']] = (int) $row['sas_car_id'];
            }
        } catch (Throwable) {
            // tabelul Revizii poate lipsi
        }
        try {
            $rows = $db->query("SELECT vehicle_id, external_provider_vehicle_id FROM vehicle_odometer_sources WHERE provider_code = 'sas' AND enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $map[(int) $row['vehicle_id']] = (int) $row['external_provider_vehicle_id'];
            }
        } catch (Throwable) {
            // Centru Kilometraj inca neinitializat
        }

        return $map;
    }

    /** Starea si ultimele ferestre pentru o semiremorca (Centru Kilometraj, istoric). */
    public function trailerState(int $trailerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM semiremorci_km_sas WHERE vehicle_id = :v');
        $stmt->execute([':v' => $trailerId]);
        $log = $this->db->prepare(
            'SELECT l.*, v.nr_inmatriculare AS tractor_plate FROM semiremorci_km_sas_log l LEFT JOIN vehicule v ON v.id = l.tractor_id
             WHERE l.vehicle_id = :v ORDER BY l.window_from DESC, l.id DESC LIMIT 60'
        );
        $log->execute([':v' => $trailerId]);

        return ['cutover' => self::cutover($this->db), 'state' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null, 'log' => $log->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function log(string $message): void
    {
        error_log('[SemiremorciKmSas] ' . $message);
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
