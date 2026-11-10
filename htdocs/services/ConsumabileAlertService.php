<?php
declare(strict_types=1);

/**
 * Consumabile & Fluide Tehnice — etapa 4: alertele (sursa notificarilor).
 *
 * evaluate() recalculeaza conditiile curente si tine tabela consumabile_alerte:
 *   - o conditie noua deschide O alerta (cheie stabila, ex. „sistem:12”); cat timp conditia ramane,
 *     alerta ramane aceeasi (fara alerte noi la fiecare rulare / actualizare API);
 *   - cand conditia dispare la recalculare (ex. nivel remasurat, stoc reumplut, schimb inregistrat),
 *     alerta se inchide automat: rezolvarea e verificata pe date, nu presupusa;
 *   - o alerta inchisa manual („verificat”) nu se redeschide pana nu apar date noi (amprenta diferita).
 *
 * Notificarile (worker.py) trimit o singura data per alerta deschisa si regula; reamintirile urmeaza
 * optiunea „repeta pana la rezolvare” a regulii.
 */
final class ConsumabileAlertService
{
    public const TYPES = [
        'consumabil_completare' => ['Consumabil sub nivelul minim confirmat', 'critical'],
        'consumabil_verificare' => ['Verificare nivel necesară', 'warn'],
        'consumabil_revizie_apropiata' => ['Revizie fluid apropiată', 'warn'],
        'consumabil_revizie_depasita' => ['Revizie fluid depășită', 'critical'],
        'consumabil_consum_anormal' => ['Consum anormal / pierdere suspectată', 'critical'],
        'consumabil_stoc_insuficient' => ['Stoc insuficient pentru intervenție', 'warn'],
        'consumabil_recipient_minim' => ['Recipient cu stoc minim atins', 'warn'],
        'consumabil_date_indisponibile' => ['Date SAS / CardOil indisponibile sau învechite', 'info'],
    ];

    /** CardOil fara alimentari noi de atatea ore = date invechite. */
    public const CARDOIL_STALE_HOURS = 72;

    /** Recalcularea din pagina se face cel mult o data la atat (scriptul workerului o face mereu). */
    private const PAGE_REFRESH_SECONDS = 600;

    private static bool $schemaReady = false;

    public function __construct(private PDO $db)
    {
    }

    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $exists = (int) $this->db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumabile_alerte'")->fetchColumn() > 0;
        if (!$exists) {
            if ($this->db->inTransaction()) {
                throw new RuntimeException('Schema alertelor lipsește; nu poate fi creată în interiorul unei tranzacții.');
            }
            $this->db->exec(self::schemaStatement());
        }
        self::$schemaReady = true;
    }

    public static function schemaStatement(): string
    {
        return "CREATE TABLE IF NOT EXISTS consumabile_alerte (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tip VARCHAR(40) NOT NULL,
            cheie VARCHAR(80) NOT NULL,
            amprenta VARCHAR(64) NOT NULL,
            severitate VARCHAR(10) NOT NULL,
            vehicle_id INT NULL,
            titlu VARCHAR(190) NOT NULL,
            subiect VARCHAR(190) NOT NULL,
            detalii VARCHAR(500) NULL,
            recomandare VARCHAR(255) NULL,
            link_sub VARCHAR(20) NOT NULL DEFAULT 'vehicule',
            status VARCHAR(10) NOT NULL DEFAULT 'deschisa',
            deschisa_la DATETIME NOT NULL,
            ultima_evaluare DATETIME NOT NULL,
            rezolvata_la DATETIME NULL,
            rezolvare VARCHAR(10) NULL,
            motiv_rezolvare VARCHAR(255) NULL,
            rezolvata_de INT UNSIGNED NULL,
            KEY idx_ca_status (status, tip),
            KEY idx_ca_cheie (cheie, status),
            KEY idx_ca_vehicle (vehicle_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    // =========================================================================
    // Conditiile curente
    // =========================================================================

    /**
     * @return list<array{tip:string, cheie:string, amprenta:string, vehicle_id:?int, subiect:string, detalii:string, recomandare:string, link_sub:string}>
     */
    public function currentConditions(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $conditions = [];
        $fluids = new VehicleFluidService($this->db);
        $stock = new ConsumabileStockService($this->db);
        $rows = $fluids->statusRows([], $now);
        $products = $stock->products(true);
        $available = $this->availableByProduct($products);
        $staleVehicles = [];

        foreach ($rows as $row) {
            $subject = $row['nr_inmatriculare'] . ' · ' . $row['denumire'];
            $base = ['vehicle_id' => (int) $row['vehicle_id'], 'subiect' => $subject, 'link_sub' => 'vehicule'];
            $status = (string) $row['status'];
            $systemKey = 'sistem:' . $row['id'];
            if ($status === 'completare_necesara') {
                $conditions[] = $base + ['tip' => 'consumabil_completare', 'cheie' => $systemKey,
                    'amprenta' => 'check:' . (string) ($row['last_check']['id'] ?? ''),
                    'detalii' => 'Nivel sub minim la verificarea din ' . date('d.m.Y', (int) strtotime((string) $row['level']['at'])) . '.',
                    'recomandare' => (string) $row['action']];
            } elseif ($status === 'verificare_necesara') {
                $conditions[] = $base + ['tip' => 'consumabil_verificare', 'cheie' => $systemKey,
                    'amprenta' => 'check:' . (string) ($row['last_check']['id'] ?? '') . ':' . (string) ($row['level']['status'] ?? ''),
                    'detalii' => $row['last_check'] !== null ? 'Ultima verificare: ' . date('d.m.Y', (int) strtotime((string) $row['last_check']['efectuat_la'])) . '.' : 'Nicio verificare de nivel înregistrată.',
                    'recomandare' => (string) $row['action']];
            } elseif ($status === 'revizie_apropiata' || $status === 'revizie_depasita') {
                $conditions[] = $base + ['tip' => $status === 'revizie_depasita' ? 'consumabil_revizie_depasita' : 'consumabil_revizie_apropiata',
                    'cheie' => $systemKey, 'amprenta' => 'ref:' . (string) ($row['reference']['date'] ?? ''),
                    'detalii' => 'Interval de service parcurs ' . VehicleFluidService::fmt((float) $row['usage_pct']) . '% (' . $this->usageText((array) $row['usage']) . ').',
                    'recomandare' => (string) $row['action']];
            } elseif ($status === 'consum_anormal') {
                $conditions[] = $base + ['tip' => 'consumabil_consum_anormal', 'cheie' => $systemKey,
                    'amprenta' => 'an:' . substr(sha1((string) $row['anomaly'] . '|' . ($row['last_topup']['id'] ?? '')), 0, 16),
                    'detalii' => (string) $row['anomaly'] . '.', 'recomandare' => (string) $row['action']];
            }

            // Stoc insuficient pentru interventia ceruta de status.
            $productId = (int) ($row['produs_id'] ?? 0);
            if ($productId > 0 && in_array($status, ['completare_necesara', 'revizie_depasita', 'revizie_apropiata'], true)) {
                $capacity = $row['capacitate_nominala'] !== null ? (float) $row['capacitate_nominala'] : null;
                $needed = $status === 'completare_necesara'
                    ? (float) ($row['consumption']['estimated_missing'] ?? ($capacity !== null ? $capacity * 0.1 : 1))
                    : $capacity;
                if ($needed !== null && $needed > 0) {
                    $have = $this->availableFor($productId, $products, $available);
                    if ($have + 0.0005 < $needed) {
                        $conditions[] = $base + ['tip' => 'consumabil_stoc_insuficient', 'cheie' => 'stoc:' . $systemKey, 'amprenta' => 'p:' . $productId,
                            'detalii' => 'Necesar ≈ ' . VehicleFluidService::fmt($needed) . ' ' . $row['um'] . ' „' . ($products[$productId]['denumire'] ?? '') . '”, disponibil ' . VehicleFluidService::fmt($have) . ' ' . $row['um'] . '.',
                            'recomandare' => 'Aprovizionează produsul înainte de intervenție', 'link_sub' => 'stocuri'];
                    }
                }
            }

            $tele = (array) $row['telemetry'];
            if (($row['sursa_km'] ?? 'none') !== 'none' && in_array($tele['state'] ?? '', ['invechit', 'indisponibil'], true)) {
                $staleVehicles[(int) $row['vehicle_id']] = ['nr' => (string) $row['nr_inmatriculare'], 'state' => (string) $tele['state'], 'at' => $tele['at'] ?? null];
            }
        }

        foreach ($staleVehicles as $vehicleId => $info) {
            $conditions[] = ['tip' => 'consumabil_date_indisponibile', 'cheie' => 'telemetrie:' . $vehicleId, 'amprenta' => $info['state'],
                'vehicle_id' => $vehicleId, 'subiect' => $info['nr'] . ' · telemetrie SAS', 'link_sub' => 'vehicule',
                'detalii' => $info['state'] === 'indisponibil' ? 'Nicio citire de km SAS pentru vehicul.'
                    : 'Ultima citire de km SAS: ' . date('d.m.Y H:i', (int) strtotime((string) $info['at'])) . ' (peste ' . VehicleFluidService::STALE_HOURS . ' h).',
                'recomandare' => 'Verifică sincronizarea Centrului Kilometraj / unitatea GPS'];
        }

        // Recipiente sub prag (doar cele cu prag configurat; golirea fara prag nu e alerta).
        foreach ($stock->containers() as $container) {
            if ($container['prag_minim'] !== null && in_array($container['stare'], ['stoc_scazut', 'gol'], true)) {
                $conditions[] = ['tip' => 'consumabil_recipient_minim', 'cheie' => 'recipient:' . $container['id'], 'amprenta' => 'prag',
                    'vehicle_id' => null, 'subiect' => $container['cod'] . ' · ' . ($container['produs'] ?? 'fără produs'), 'link_sub' => 'stocuri',
                    'detalii' => 'Cantitate ' . VehicleFluidService::fmt((float) $container['cantitate']) . ' ' . ($container['um'] ?? '') . ' (prag ' . VehicleFluidService::fmt((float) $container['prag_minim']) . ').',
                    'recomandare' => 'Reumple recipientul (intrare în stoc sau transfer)'];
            }
        }

        // AdBlue: anomaliile din analiza CardOil + CAN (fata de istoricul vehiculului).
        try {
            foreach ((new AdBlueService($this->db))->analysis([], $now)['rows'] as $row) {
                if (!empty($row['inactive']) || $row['anomalies'] === []) {
                    continue;
                }
                $conditions[] = ['tip' => 'consumabil_consum_anormal', 'cheie' => 'adblue:' . $row['vehicle_id'],
                    'amprenta' => 'ab:' . substr(sha1(implode('|', $row['anomalies'])), 0, 16), 'vehicle_id' => (int) $row['vehicle_id'],
                    'subiect' => $row['nr_inmatriculare'] . ' · AdBlue', 'link_sub' => 'adblue',
                    'detalii' => mb_substr(implode(' ', $row['anomalies']), 0, 480), 'recomandare' => 'Verifică alimentările AdBlue, asocierea cardului și sistemul SCR'];
            }
        } catch (Throwable $exception) {
            error_log('[ConsumabileAlertService][adblue] ' . $exception->getMessage());
        }

        // CardOil: fara alimentari noi primite din API = date invechite.
        $lastApi = $this->db->query("SELECT MAX(created_at) FROM fuel_fillups WHERE source_type = 'api' OR source_type IS NULL")->fetchColumn();
        if ($lastApi === false || $lastApi === null || ($now->getTimestamp() - (int) strtotime((string) $lastApi)) > self::CARDOIL_STALE_HOURS * 3600) {
            $conditions[] = ['tip' => 'consumabil_date_indisponibile', 'cheie' => 'cardoil', 'amprenta' => (string) ($lastApi ?: 'niciodata'),
                'vehicle_id' => null, 'subiect' => 'CardOil · alimentări', 'link_sub' => 'adblue',
                'detalii' => $lastApi ? 'Ultima alimentare primită din CardOil: ' . date('d.m.Y H:i', (int) strtotime((string) $lastApi)) . '.' : 'Nicio alimentare primită din CardOil.',
                'recomandare' => 'Verifică sincronizarea CardOil (Carburanți)'];
        }

        return $conditions;
    }

    // =========================================================================
    // Deschidere / pastrare / inchidere
    // =========================================================================

    /**
     * Recalculare cu lacat (pagina si workerul nu pot deschide aceeasi alerta de doua ori).
     *
     * @return array{opened:int, kept:int, resolved:int, locked?:bool}
     */
    public function evaluate(?DateTimeImmutable $now = null, ?array $conditions = null): array
    {
        $this->ensureSchema();
        $lockFile = dirname($this->refreshFile()) . '/consumabile_alerte.lock';
        $lock = is_dir(dirname($lockFile)) ? @fopen($lockFile, 'c') : false;
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return ['opened' => 0, 'kept' => 0, 'resolved' => 0, 'locked' => true];
        }
        try {
            return $this->evaluateLocked($now ?? new DateTimeImmutable('now'), $conditions);
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return array{opened:int, kept:int, resolved:int} */
    private function evaluateLocked(DateTimeImmutable $now, ?array $conditions): array
    {
        $conditions ??= $this->currentConditions($now);
        $stamp = $now->format('Y-m-d H:i:s');
        $stats = ['opened' => 0, 'kept' => 0, 'resolved' => 0];
        $open = [];
        foreach ($this->db->query("SELECT * FROM consumabile_alerte WHERE status = 'deschisa'")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $open[$row['cheie'] . '|' . $row['tip']] = $row;
        }
        $lastManual = $this->db->prepare(
            "SELECT amprenta FROM consumabile_alerte WHERE cheie = :c AND tip = :t AND status = 'rezolvata' AND rezolvare = 'manual' ORDER BY id DESC LIMIT 1"
        );
        $insert = $this->db->prepare(
            'INSERT INTO consumabile_alerte (tip, cheie, amprenta, severitate, vehicle_id, titlu, subiect, detalii, recomandare, link_sub, status, deschisa_la, ultima_evaluare)
             VALUES (:tip, :cheie, :amp, :sev, :v, :titlu, :subiect, :detalii, :rec, :link, \'deschisa\', :now, :now2)'
        );
        $update = $this->db->prepare(
            'UPDATE consumabile_alerte SET amprenta = :amp, detalii = :detalii, recomandare = :rec, subiect = :subiect, ultima_evaluare = :now WHERE id = :id'
        );
        $seen = [];
        foreach ($conditions as $condition) {
            $mapKey = $condition['cheie'] . '|' . $condition['tip'];
            if (isset($seen[$mapKey])) {
                continue;
            }
            $seen[$mapKey] = true;
            if (isset($open[$mapKey])) {
                $update->execute([':amp' => $condition['amprenta'], ':detalii' => mb_substr($condition['detalii'], 0, 500), ':rec' => mb_substr($condition['recomandare'], 0, 255),
                    ':subiect' => mb_substr($condition['subiect'], 0, 190), ':now' => $stamp, ':id' => (int) $open[$mapKey]['id']]);
                $stats['kept']++;
                continue;
            }
            $lastManual->execute([':c' => $condition['cheie'], ':t' => $condition['tip']]);
            if ($lastManual->fetchColumn() === $condition['amprenta']) {
                continue; // inchisa manual dupa verificare; fara date noi nu se redeschide
            }
            [$title, $severity] = self::TYPES[$condition['tip']];
            $insert->execute([':tip' => $condition['tip'], ':cheie' => $condition['cheie'], ':amp' => $condition['amprenta'], ':sev' => $severity,
                ':v' => $condition['vehicle_id'], ':titlu' => $title, ':subiect' => mb_substr($condition['subiect'], 0, 190),
                ':detalii' => mb_substr($condition['detalii'], 0, 500), ':rec' => mb_substr($condition['recomandare'], 0, 255),
                ':link' => $condition['link_sub'], ':now' => $stamp, ':now2' => $stamp]);
            $stats['opened']++;
        }
        $resolve = $this->db->prepare(
            "UPDATE consumabile_alerte SET status = 'rezolvata', rezolvata_la = :now, rezolvare = 'auto',
                motiv_rezolvare = 'Condiția nu mai apare la recalculare (rezolvare verificată pe date)' WHERE id = :id AND status = 'deschisa'"
        );
        foreach ($open as $mapKey => $row) {
            if (!isset($seen[$mapKey])) {
                $resolve->execute([':now' => $stamp, ':id' => (int) $row['id']]);
                $stats['resolved']++;
            }
        }
        $this->touchRefreshStamp();

        return $stats;
    }

    /** Inchidere manuala dupa verificare (ex. inspectie fara pierdere). Se redeschide doar la date noi. */
    public function close(int $id, string $reason, ?int $userId): void
    {
        $this->ensureSchema();
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Scrie ce s-a verificat.');
        }
        $stmt = $this->db->prepare("UPDATE consumabile_alerte SET status = 'rezolvata', rezolvata_la = NOW(), rezolvare = 'manual', motiv_rezolvare = :m, rezolvata_de = :u
            WHERE id = :id AND status = 'deschisa'");
        $stmt->execute([':m' => mb_substr($reason, 0, 255), ':u' => $userId, ':id' => $id]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Alerta nu mai este deschisă.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function openAlerts(): array
    {
        $this->ensureSchema();

        return $this->db->query(
            "SELECT a.*, v.nr_inmatriculare FROM consumabile_alerte a LEFT JOIN vehicule v ON v.id = a.vehicle_id
             WHERE a.status = 'deschisa'
             ORDER BY FIELD(a.severitate, 'critical', 'warn', 'info'), a.deschisa_la DESC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public function recentlyResolved(int $days = 7): array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare(
            "SELECT a.*, u.nume AS rezolvata_de_nume FROM consumabile_alerte a LEFT JOIN utilizatori u ON u.id = a.rezolvata_de
             WHERE a.status = 'rezolvata' AND a.rezolvata_la >= :since ORDER BY a.rezolvata_la DESC LIMIT 50"
        );
        $stmt->execute([':since' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days'))]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Recalculare din pagina, cel mult o data la 10 minute (worker-ul o face la fiecare rulare). */
    public function refreshIfDue(): void
    {
        $file = $this->refreshFile();
        if (is_file($file) && (time() - (int) @filemtime($file)) < self::PAGE_REFRESH_SECONDS) {
            return;
        }
        try {
            $this->evaluate();
        } catch (Throwable $exception) {
            error_log('[ConsumabileAlertService][refresh] ' . $exception->getMessage());
        }
    }

    // =========================================================================

    private function touchRefreshStamp(): void
    {
        $file = $this->refreshFile();
        if (is_dir(dirname($file))) {
            @touch($file);
        }
    }

    private function refreshFile(): string
    {
        return dirname(BASE_PATH) . '/storage/cache/consumabile_alerte.stamp';
    }

    /** @return array<int, float> cantitatea utilizabila (recipiente active, neexpirate) pe produs */
    private function availableByProduct(array $products): array
    {
        $result = [];
        foreach ((new ConsumabileStockService($this->db))->containers() as $container) {
            if ((int) ($container['produs_id'] ?? 0) <= 0 || $container['stare_expirare'] === 'expirat') {
                continue;
            }
            $result[(int) $container['produs_id']] = ($result[(int) $container['produs_id']] ?? 0) + (float) $container['cantitate'];
        }

        return $result;
    }

    private function availableFor(int $productId, array $products, array $available): float
    {
        $total = $available[$productId] ?? 0.0;
        foreach ((array) ($products[$productId]['compatibile'] ?? []) as $other) {
            $total += $available[(int) $other] ?? 0.0;
        }

        return $total;
    }

    private function usageText(array $usage): string
    {
        $parts = [];
        foreach (['km' => 'km', 'months' => 'luni', 'hours' => 'h'] as $key => $unit) {
            if (($usage[$key] ?? null) !== null) {
                $parts[] = VehicleFluidService::fmt((float) $usage[$key]['used'], 1) . ' / ' . VehicleFluidService::fmt((float) $usage[$key]['interval'], 0) . ' ' . $unit;
            }
        }

        return implode(', ', $parts);
    }
}
