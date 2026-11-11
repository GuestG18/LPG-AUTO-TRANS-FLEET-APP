<?php
declare(strict_types=1);

/**
 * Consumabile & Fluide Tehnice — etapa 2: sistemele de fluide ale vehiculelor si starea lor.
 *
 * Surse (nimic inventat; lipsa unei surse = stare explicita „indisponibil”):
 *   - KM CAN  : vehicle_odometer_readings provider 'can' (raportul web SAS, odometrul de bord)
 *   - KM GPS  : vehicle_odometer_readings provider 'sas' (kmIndex SAS; fallback declarat cand CAN lipseste)
 *   - Ore motor: revizii_sas_segmente.work_seconds (SAS travelsheet), doar pe zilele acoperite
 *   - Ore PTO / pompa GPL: orele de aspirare din curse (ReviziiModel::getAspirationEvents) — introduse
 *     manual; NU sunt orele motorului
 *   - Nivel: doar masuratori inregistrate de operator (verificari). Nu exista nivel CAN pentru fluide in SAS.
 *
 * Calculele sunt deterministe; procentul de interval = intervalul de service parcurs, nu degradarea uleiului.
 */
final class VehicleFluidService
{
    /** Telemetria mai veche de atat e marcata „invechita”. */
    public const STALE_HOURS = 48;

    /** Fereastra in care o citire de odometru e considerata valabila pentru un eveniment. */
    private const READING_WINDOW_DAYS = 7;

    public const SYSTEM_PRESETS = [
        'motor' => ['Motor (ulei motor)', 'ulei_motor', 'km_can', 'ore_motor', 0, 0, 'KM CAN + timp / ore motor'],
        'transmisie' => ['Cutie de viteze', 'ulei_transmisie', 'km_can', 'none', 0, 0, 'KM CAN + timp'],
        'diferential' => ['Diferențial / punte', 'ulei_diferential', 'km_can', 'none', 0, 0, 'KM CAN + timp'],
        'servodirectie' => ['Servodirecție', 'ulei_servodirectie', 'km_can', 'none', 1, 1, 'Inspecție + km / timp; circuit închis'],
        'hidraulic_pompa' => ['Hidraulic pompă GPL', 'ulei_hidraulic', 'none', 'ore_pto', 0, 0, 'Ore funcționare pompă / PTO'],
        'racire' => ['Răcire (antigel)', 'antigel', 'none', 'none', 1, 1, 'Timp + revizie'],
        'adblue' => ['AdBlue', 'adblue', 'km_can', 'none', 0, 0, 'Consum + alimentări (etapa 3)'],
        'frana' => ['Lichid de frână', 'lichid_frana', 'none', 'none', 1, 1, 'Timp + inspecție'],
        'altul' => ['Alt sistem', '', 'none', 'none', 0, 0, ''],
    ];

    public const KM_SOURCES = ['km_can' => 'KM CAN', 'km_gps' => 'KM GPS', 'none' => 'Fără km'];

    public const HOUR_SOURCES = ['none' => 'Fără ore', 'ore_motor' => 'Ore motor (SAS)', 'ore_pto' => 'Ore pompă / PTO (curse)'];

    public const LEVEL_STATUSES = [
        'la_maxim' => 'La maxim',
        'ok' => 'În limite',
        'la_minim' => 'La minim',
        'sub_minim' => 'Sub minim',
        'peste_maxim' => 'Peste maxim',
    ];

    public const STATUSES = [
        'normal' => ['Normal', 'is-ok'],
        'verificare_necesara' => ['Verificare necesară', 'is-low'],
        'completare_necesara' => ['Completare necesară', 'is-empty'],
        'consum_anormal' => ['Consum anormal / pierdere suspectată', 'is-empty'],
        'revizie_apropiata' => ['Revizie apropiată', 'is-low'],
        'revizie_depasita' => ['Revizie depășită', 'is-empty'],
        'date_insuficiente' => ['Date insuficiente', 'is-off'],
    ];

    public function __construct(private PDO $db)
    {
    }

    // =========================================================================
    // Sisteme
    // =========================================================================

    /** @return array<int, array<string, mixed>> */
    public function systems(array $filters = []): array
    {
        $where = ['s.activ = 1'];
        $params = [];
        $ids = array_values(array_filter(array_map('intval', (array) ($filters['vehicle_ids'] ?? []))));
        if ($ids !== []) {
            $where[] = 's.vehicle_id IN (' . implode(',', $ids) . ')';
        }
        if (!empty($filters['tip_fluid'])) {
            $where[] = 's.tip_fluid = :tf';
            $params[':tf'] = (string) $filters['tip_fluid'];
        }
        if (!empty($filters['sistem'])) {
            $where[] = 's.sistem = :si';
            $params[':si'] = (string) $filters['sistem'];
        }
        $stmt = $this->db->prepare(
            "SELECT s.*, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul, p.denumire AS produs, p.vascozitate
             FROM vehicule_sisteme_fluide s
             JOIN vehicule v ON v.id = s.vehicle_id AND v.status = 'activ'
             LEFT JOIN consumabile_produse p ON p.id = s.produs_id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY v.nr_inmatriculare, s.sistem'
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function system(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vehicule_sisteme_fluide WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Creare / editare sistem. La creare se poate aplica aceeasi configuratie si pe alte vehicule
     * (copy_vehicle_ids); vehiculele care au deja sistemul sunt sarite.
     *
     * @return array{id:int, copied:int, skipped:int}
     */
    public function saveSystem(array $data, ?int $id, ?int $userId): array
    {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $key = (string) ($data['sistem'] ?? '');
        if (!isset(self::SYSTEM_PRESETS[$key])) {
            throw new InvalidArgumentException('Alege sistemul mecanic.');
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($data['denumire'] ?? '')) ?? '');
        $name = $name !== '' ? mb_substr($name, 0, 80) : self::SYSTEM_PRESETS[$key][0];
        $productId = (int) ($data['produs_id'] ?? 0);
        $product = null;
        if ($productId > 0) {
            $product = (new ConsumabileStockService($this->db))->product($productId) ?? throw new InvalidArgumentException('Produs inexistent.');
        }
        $kmSource = isset(self::KM_SOURCES[(string) ($data['sursa_km'] ?? '')]) ? (string) $data['sursa_km'] : 'none';
        $hourSource = isset(self::HOUR_SOURCES[(string) ($data['sursa_ore'] ?? '')]) ? (string) $data['sursa_ore'] : 'none';
        $intervalKm = $this->posInt($data['interval_km'] ?? null) ?? ($product !== null ? $this->posInt($product['interval_km']) : null);
        $intervalMonths = $this->posInt($data['interval_luni'] ?? null) ?? ($product !== null ? $this->posInt($product['interval_luni']) : null);
        $intervalHours = $this->posInt($data['interval_ore'] ?? null) ?? ($product !== null ? $this->posInt($product['interval_ore']) : null);
        if ($intervalKm !== null && $kmSource === 'none') {
            throw new InvalidArgumentException('Intervalul în km cere o sursă de km (KM CAN sau KM GPS).');
        }
        if ($intervalHours !== null && $hourSource === 'none') {
            throw new InvalidArgumentException('Intervalul în ore cere o sursă de ore (ore motor sau ore pompă / PTO).');
        }
        $warn = (int) ($data['avertizare_procent'] ?? 90);
        if ($warn < 50 || $warn > 99) {
            $warn = 90;
        }
        $refDate = trim((string) ($data['referinta_data'] ?? ''));
        $refDate = $refDate !== '' ? date('Y-m-d H:i:s', (int) strtotime(str_replace('T', ' ', $refDate))) : null;
        if ($refDate !== null && strtotime($refDate) > time() + 300) {
            throw new InvalidArgumentException('Data ultimului schimb nu poate fi în viitor.');
        }
        $values = [
            ':denumire' => $name,
            ':produs' => $productId > 0 ? $productId : null,
            ':tip_fluid' => isset(ConsumabileStockService::FLUID_TYPES[(string) ($data['tip_fluid'] ?? '')]) ? (string) $data['tip_fluid'] : ($product['tip_fluid'] ?? (self::SYSTEM_PRESETS[$key][1] ?: null)),
            ':capacitate' => $this->decimal($data['capacitate_nominala'] ?? null),
            ':um' => $product['um'] ?? (isset(ConsumabileStockService::UNITS[(string) ($data['um'] ?? '')]) ? (string) $data['um'] : 'L'),
            ':ikm' => $intervalKm,
            ':iluni' => $intervalMonths,
            ':iore' => $intervalHours,
            ':skm' => $kmSource,
            ':sore' => $hourSource,
            ':warn' => $warn,
            ':vzile' => $this->posInt($data['verificare_zile'] ?? null),
            ':prag' => $this->decimal($data['prag_consum'] ?? null),
            ':critic' => !empty($data['critic']) ? 1 : 0,
            ':circuit' => !empty($data['circuit_inchis']) ? 1 : 0,
            ':rdata' => $refDate,
            ':rcan' => $this->decimal($data['referinta_km_can'] ?? null, 1),
            ':rgps' => $this->decimal($data['referinta_km_gps'] ?? null, 1),
        ];
        // Referinta manuala fara km: se completeaza din telemetria de la acea data (daca exista).
        if ($refDate !== null && $values[':rcan'] === null) {
            $values[':rcan'] = $this->readingAt($vehicleId, 'can', $refDate);
        }
        if ($refDate !== null && $values[':rgps'] === null) {
            $values[':rgps'] = $this->readingAt($vehicleId, 'sas', $refDate);
        }

        if ($id !== null) {
            $current = $this->system($id) ?? throw new InvalidArgumentException('Sistemul nu mai există.');
            $this->db->prepare(
                'UPDATE vehicule_sisteme_fluide SET denumire = :denumire, produs_id = :produs, tip_fluid = :tip_fluid, capacitate_nominala = :capacitate,
                    um = :um, interval_km = :ikm, interval_luni = :iluni, interval_ore = :iore, sursa_km = :skm, sursa_ore = :sore,
                    avertizare_procent = :warn, verificare_zile = :vzile, prag_consum = :prag, critic = :critic, circuit_inchis = :circuit,
                    referinta_data = :rdata, referinta_km_can = :rcan, referinta_km_gps = :rgps, activ = :activ, updated_at = NOW()
                 WHERE id = :id'
            )->execute($values + [':activ' => array_key_exists('activ', $data) && empty($data['activ']) ? 0 : 1, ':id' => $id]);
            $this->saveFillsToFull($id, $data);

            return ['id' => $id, 'copied' => 0, 'skipped' => 0, 'vehicle_id' => (int) $current['vehicle_id']];
        }

        $targets = array_values(array_unique(array_filter(array_merge([$vehicleId], array_map('intval', (array) ($data['copy_vehicle_ids'] ?? []))))));
        $insert = $this->db->prepare(
            'INSERT INTO vehicule_sisteme_fluide
                (vehicle_id, sistem, denumire, produs_id, tip_fluid, capacitate_nominala, um, interval_km, interval_luni, interval_ore, sursa_km, sursa_ore,
                 avertizare_procent, verificare_zile, prag_consum, critic, circuit_inchis, referinta_data, referinta_km_can, referinta_km_gps,
                 activ, created_by, created_at, updated_at)
             VALUES (:vehicle, :sistem, :denumire, :produs, :tip_fluid, :capacitate, :um, :ikm, :iluni, :iore, :skm, :sore,
                 :warn, :vzile, :prag, :critic, :circuit, :rdata, :rcan, :rgps, 1, :user, NOW(), NOW())'
        );
        $exists = $this->db->prepare('SELECT id, activ FROM vehicule_sisteme_fluide WHERE vehicle_id = :v AND sistem = :s');
        $reactivate = $this->db->prepare('UPDATE vehicule_sisteme_fluide SET activ = 1, updated_at = NOW() WHERE id = :id');
        $vehicleCheck = $this->db->prepare("SELECT COUNT(*) FROM vehicule WHERE id = :id AND status = 'activ'");
        $firstId = 0;
        $copied = 0;
        $skipped = 0;
        foreach ($targets as $index => $target) {
            $vehicleCheck->execute([':id' => $target]);
            if ((int) $vehicleCheck->fetchColumn() === 0) {
                if ($index === 0) {
                    throw new InvalidArgumentException('Alege vehiculul.');
                }
                $skipped++;
                continue;
            }
            $exists->execute([':v' => $target, ':s' => $key]);
            $row = $exists->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                if ($index === 0 && (int) $row['activ'] === 1) {
                    throw new InvalidArgumentException('Vehiculul are deja sistemul „' . self::SYSTEM_PRESETS[$key][0] . '”. Editează-l pe cel existent.');
                }
                if ((int) $row['activ'] === 0 && $index === 0) {
                    $reactivate->execute([':id' => (int) $row['id']]);
                    $this->saveSystem($data, (int) $row['id'], $userId);
                    $firstId = (int) $row['id'];
                    continue;
                }
                $skipped++;
                continue;
            }
            $params = $values;
            // Referinta (data / km ultimului schimb) e proprie fiecarui vehicul: nu se copiaza.
            if ($index > 0) {
                $params[':rdata'] = null;
                $params[':rcan'] = null;
                $params[':rgps'] = null;
            }
            $insert->execute($params + [':vehicle' => $target, ':sistem' => $key, ':user' => $userId]);
            $newId = (int) $this->db->lastInsertId();
            $this->saveFillsToFull($newId, $data);
            if ($index === 0) {
                $firstId = $newId;
            } else {
                $copied++;
            }
        }

        return ['id' => $firstId, 'copied' => $copied, 'skipped' => $skipped, 'vehicle_id' => $vehicleId];
    }

    /** AdBlue: alimentarile se fac la plin (coloana adaugata in etapa 3, scrisa doar daca exista). */
    private function saveFillsToFull(int $systemId, array $data): void
    {
        static $hasColumn = null;
        if ($hasColumn === null) {
            $hasColumn = (int) $this->db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'vehicule_sisteme_fluide' AND COLUMN_NAME = 'alimentare_la_plin'")->fetchColumn() > 0;
        }
        if ($hasColumn && $systemId > 0) {
            $this->db->prepare('UPDATE vehicule_sisteme_fluide SET alimentare_la_plin = :p WHERE id = :id')
                ->execute([':p' => !empty($data['alimentare_la_plin']) ? 1 : 0, ':id' => $systemId]);
        }
    }

    // =========================================================================
    // Evenimente (verificari masurate, completari, schimburi)
    // =========================================================================

    /**
     * Verificare de nivel sau completare / schimb fara stoc (ex. produs cumparat pe drum), cu
     * idempotenta. Completarile din stoc trec prin ConsumabileStockService (care apeleaza insertEvent).
     */
    public function recordEvent(array $data, ?int $userId): array
    {
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            throw new InvalidArgumentException('Cheia operațiunii lipsește. Reîncarcă pagina și reîncearcă.');
        }
        $existing = $this->db->prepare('SELECT id FROM vehicule_fluide_evenimente WHERE idempotency_key = :k');
        $existing->execute([':k' => $key]);
        if (($id = $existing->fetchColumn()) !== false) {
            return ['id' => (int) $id, 'duplicate' => true];
        }
        $system = $this->system((int) ($data['sistem_id'] ?? 0));
        if ($system === null || (int) $system['activ'] !== 1) {
            throw new InvalidArgumentException('Alege sistemul vehiculului.');
        }
        $type = (string) ($data['tip'] ?? 'verificare');
        if (!in_array($type, ['verificare', 'completare', 'schimb_complet'], true)) {
            throw new InvalidArgumentException('Tip de înregistrare necunoscut.');
        }
        $level = (string) ($data['nivel_status'] ?? '');
        if ($type === 'verificare' && !isset(self::LEVEL_STATUSES[$level])) {
            throw new InvalidArgumentException('Alege nivelul constatat la verificare.');
        }
        $quantity = $this->decimal($data['cantitate'] ?? null);
        if ($type !== 'verificare' && ($quantity === null || (float) $quantity <= 0)) {
            throw new InvalidArgumentException('Completează cantitatea adăugată.');
        }
        $at = trim((string) ($data['efectuat_la'] ?? ''));
        $at = $at !== '' ? (int) strtotime(str_replace('T', ' ', $at)) : time();
        if ($at === 0 || $at > time() + 300) {
            throw new InvalidArgumentException('Data înregistrării nu este validă.');
        }
        try {
            $id = $this->insertEvent($system, [
                'tip' => $type,
                'efectuat_la' => date('Y-m-d H:i:s', $at),
                'nivel_status' => isset(self::LEVEL_STATUSES[$level]) ? $level : null,
                'nivel_valoare' => $this->decimal($data['nivel_valoare'] ?? null),
                'nivel_unitate' => in_array((string) ($data['nivel_unitate'] ?? ''), ['%', 'L', 'mm', 'cm'], true) ? (string) $data['nivel_unitate'] : null,
                'cantitate' => $type === 'verificare' ? null : $quantity,
                'pana_la_reper' => $type === 'schimb_complet' || !empty($data['pana_la_reper']),
                'km_bord' => $data['km_bord'] ?? null,
                'observatii' => $data['observatii'] ?? null,
                'idempotency_key' => $key,
                'user_id' => $userId,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $existing->execute([':k' => $key]);
                if (($id = $existing->fetchColumn()) !== false) {
                    return ['id' => (int) $id, 'duplicate' => true];
                }
            }
            throw $exception;
        }

        return ['id' => $id, 'duplicate' => false];
    }

    /** Inserarea propriu-zisa (si din tranzactia unei miscari de stoc). Km-ii se fotografiaza acum. */
    public function insertEvent(array $system, array $data): int
    {
        $at = (string) $data['efectuat_la'];
        $vehicleId = (int) $system['vehicle_id'];
        $kmBoard = preg_replace('/[^\d]/', '', (string) ($data['km_bord'] ?? '')) ?? '';
        $this->db->prepare(
            'INSERT INTO vehicule_fluide_evenimente
                (sistem_id, vehicle_id, tip, efectuat_la, nivel_status, nivel_valoare, nivel_unitate, cantitate, pana_la_reper,
                 km_can, km_gps, km_bord, miscare_id, observatii, idempotency_key, user_id, created_at)
             VALUES (:s, :v, :tip, :at, :ns, :nv, :nu, :q, :ref, :kcan, :kgps, :kbord, :m, :obs, :key, :u, NOW())'
        )->execute([
            ':s' => (int) $system['id'], ':v' => $vehicleId, ':tip' => (string) $data['tip'], ':at' => $at,
            ':ns' => $data['nivel_status'] ?? null, ':nv' => $data['nivel_valoare'] ?? null, ':nu' => $data['nivel_unitate'] ?? null,
            ':q' => $data['cantitate'] ?? null, ':ref' => !empty($data['pana_la_reper']) ? 1 : 0,
            ':kcan' => $this->readingAt($vehicleId, 'can', $at), ':kgps' => $this->readingAt($vehicleId, 'sas', $at),
            ':kbord' => $kmBoard !== '' ? (int) $kmBoard : null, ':m' => $data['miscare_id'] ?? null,
            ':obs' => trim((string) ($data['observatii'] ?? '')) !== '' ? mb_substr(trim((string) $data['observatii']), 0, 500) : null,
            ':key' => $data['idempotency_key'] ?? null, ':u' => $data['user_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Anularea unei inregistrari manuale (cele din stoc se anuleaza prin stornarea miscarii). */
    /**
     * Eliminarea unui sistem de pe vehicul. Fara nicio inregistrare (nici anulata) se sterge definitiv;
     * altfel se dezactiveaza (dispare din situatie si alerte, istoricul ramane; reconfigurarea il reactiveaza).
     *
     * @return array{deleted: bool, system: array<string, mixed>}
     */
    public function removeSystem(int $id): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM vehicule_sisteme_fluide WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $system = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Sistemul nu există.');

            $count = $this->db->prepare('SELECT COUNT(*) FROM vehicule_fluide_evenimente WHERE sistem_id = :id');
            $count->execute([':id' => $id]);
            $deleted = (int) $count->fetchColumn() === 0;
            if ($deleted) {
                $this->db->prepare('DELETE FROM vehicule_sisteme_fluide WHERE id = :id')->execute([':id' => $id]);
            } else {
                $this->db->prepare('UPDATE vehicule_sisteme_fluide SET activ = 0, updated_at = NOW() WHERE id = :id')->execute([':id' => $id]);
            }
            $this->db->commit();

            return ['deleted' => $deleted, 'system' => $system];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function cancelEvent(int $id, string $reason, ?int $userId): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Scrie motivul anulării.');
        }
        $stmt = $this->db->prepare('SELECT * FROM vehicule_fluide_evenimente WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($event === false) {
            throw new InvalidArgumentException('Înregistrarea nu mai există.');
        }
        if ($event['miscare_id'] !== null) {
            throw new InvalidArgumentException('Înregistrarea vine dintr-o operațiune de stoc: stornează operațiunea din „Transferuri și consum”.');
        }
        if ($event['anulat_la'] !== null) {
            throw new InvalidArgumentException('Înregistrarea a fost deja anulată.');
        }
        $this->db->prepare('UPDATE vehicule_fluide_evenimente SET anulat_la = NOW(), anulat_de = :u, motiv_anulare = :m WHERE id = :id AND anulat_la IS NULL')
            ->execute([':u' => $userId, ':m' => mb_substr($reason, 0, 255), ':id' => $id]);
    }

    /** @return array<int, array<string, mixed>> ultimele evenimente ale unui sistem (fisa) */
    public function events(int $systemId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, u.nume AS utilizator FROM vehicule_fluide_evenimente e LEFT JOIN utilizatori u ON u.id = e.user_id
             WHERE e.sistem_id = :s ORDER BY e.efectuat_la DESC, e.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([':s' => $systemId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // =========================================================================
    // Starea sistemelor
    // =========================================================================

    /**
     * Randurile tabelului „Situatie vehicule”: cate un rand per sistem, cu starea calculata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statusRows(array $filters = [], ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $systems = $this->systems($filters);
        if ($systems === []) {
            return [];
        }
        $vehicleIds = array_values(array_unique(array_map(static fn (array $s): int => (int) $s['vehicle_id'], $systems)));
        $systemIds = array_map(static fn (array $s): int => (int) $s['id'], $systems);
        $events = [];
        $stmt = $this->db->query(
            'SELECT * FROM vehicule_fluide_evenimente WHERE anulat_la IS NULL AND sistem_id IN (' . implode(',', $systemIds) . ') ORDER BY efectuat_la, id'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $event) {
            $events[(int) $event['sistem_id']][] = $event;
        }
        $telemetry = $this->latestReadings($vehicleIds);
        $rows = [];
        foreach ($systems as $system) {
            $systemEvents = $events[(int) $system['id']] ?? [];
            $reference = self::reference($system, $systemEvents);
            $hours = $this->hoursSince($system, $reference['date'], $now);
            $evaluation = self::evaluate($system, $systemEvents, $telemetry[(int) $system['vehicle_id']] ?? [], $hours, $now);
            $row = $system + $evaluation;
            if (!empty($filters['status']) && $row['status'] !== $filters['status']) {
                continue;
            }
            if (!empty($filters['from']) || !empty($filters['to'])) {
                $last = (string) ($row['last_event_at'] ?? '');
                if ($last === '' || (!empty($filters['from']) && $last < $filters['from'] . ' 00:00:00') || (!empty($filters['to']) && $last > $filters['to'] . ' 23:59:59')) {
                    continue;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<string, int> numarul de sisteme pe status (pentru cardurile din Situatia generala) */
    public function statusCounts(): array
    {
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        $vehicles = array_fill_keys(array_keys(self::STATUSES), []);
        foreach ($this->statusRows() as $row) {
            $counts[$row['status']]++;
            $vehicles[$row['status']][(int) $row['vehicle_id']] = true;
        }

        return ['systems' => $counts, 'vehicles' => array_map('count', $vehicles), 'total' => array_sum($counts)];
    }

    /** Referinta de revizie: ultimul schimb complet neanulat, altfel referinta manuala a sistemului. */
    public static function reference(array $system, array $events): array
    {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if ((string) $events[$i]['tip'] === 'schimb_complet') {
                return ['date' => (string) $events[$i]['efectuat_la'], 'km_can' => self::num($events[$i]['km_can']),
                    'km_gps' => self::num($events[$i]['km_gps']), 'origin' => 'schimb'];
            }
        }
        if (!empty($system['referinta_data'])) {
            return ['date' => (string) $system['referinta_data'], 'km_can' => self::num($system['referinta_km_can']),
                'km_gps' => self::num($system['referinta_km_gps']), 'origin' => 'manual'];
        }

        return ['date' => null, 'km_can' => null, 'km_gps' => null, 'origin' => null];
    }

    /**
     * Evaluarea unui sistem — functie pura (testabila fara baza de date).
     *
     * @param array<int, array<string, mixed>> $events evenimente neanulate, crescator dupa data
     * @param array{can?: array{km: float, at: string}, sas?: array{km: float, at: string}} $telemetry
     * @param array{value: ?float, source: string, note: string} $hours
     */
    public static function evaluate(array $system, array $events, array $telemetry, array $hours, DateTimeImmutable $now): array
    {
        $reference = self::reference($system, $events);
        $nowTs = $now->getTimestamp();

        // --- telemetrie: sursa preferata, fallback declarat, prospetime ---------------------
        $preferred = (string) ($system['sursa_km'] ?? 'none');
        $tele = ['state' => 'nefolosit', 'source' => null, 'fallback' => false, 'km' => null, 'at' => null,
            'km_can' => $telemetry['can']['km'] ?? null, 'km_can_at' => $telemetry['can']['at'] ?? null,
            'km_gps' => $telemetry['sas']['km'] ?? null, 'km_gps_at' => $telemetry['sas']['at'] ?? null];
        $kmUsage = null;
        $kmNote = '';
        if ($preferred !== 'none') {
            $order = $preferred === 'km_gps' ? ['sas' => 'km_gps'] : ['can' => 'km_can', 'sas' => 'km_gps'];
            foreach ($order as $provider => $sourceKey) {
                $current = $telemetry[$provider] ?? null;
                $refKm = $reference[$sourceKey];
                if ($current === null) {
                    continue;
                }
                if ($tele['source'] === null) {
                    $tele = array_merge($tele, ['source' => $sourceKey, 'km' => (float) $current['km'], 'at' => (string) $current['at'], 'fallback' => $sourceKey !== $preferred]);
                }
                if ($refKm !== null && $reference['date'] !== null) {
                    $tele = array_merge($tele, ['source' => $sourceKey, 'km' => (float) $current['km'], 'at' => (string) $current['at'], 'fallback' => $sourceKey !== $preferred]);
                    $driven = max(0.0, (float) $current['km'] - $refKm);
                    $kmUsage = ['used' => $driven, 'source' => $sourceKey];
                    break;
                }
            }
            if ($tele['source'] === null) {
                $tele['state'] = 'indisponibil';
                $kmNote = 'Nicio citire de km (SAS) pentru vehicul.';
            } else {
                $age = ($nowTs - (int) strtotime((string) $tele['at'])) / 3600;
                $tele['state'] = $age > self::STALE_HOURS ? 'invechit' : 'actual';
                $tele['age_hours'] = (int) round($age);
            }
            if ($kmUsage === null && $reference['date'] !== null && $tele['source'] !== null) {
                $kmNote = 'Lipsesc km la ultimul schimb în aceeași sursă (' . ($preferred === 'km_can' ? 'CAN / GPS' : 'GPS') . ').';
            }
        }

        // --- consum fata de interval ------------------------------------------------------------
        $usage = ['km' => null, 'months' => null, 'hours' => null];
        $intervalKm = self::num($system['interval_km'] ?? null);
        if ($intervalKm !== null && $kmUsage !== null) {
            $usage['km'] = ['used' => round($kmUsage['used']), 'interval' => $intervalKm, 'remaining' => round($intervalKm - $kmUsage['used']),
                'pct' => round($kmUsage['used'] * 100 / $intervalKm, 2), 'source' => $kmUsage['source']];
        }
        $intervalMonths = self::num($system['interval_luni'] ?? null);
        if ($intervalMonths !== null && $reference['date'] !== null) {
            $refTs = (int) strtotime((string) $reference['date']);
            $monthsUsed = max(0.0, ($nowTs - $refTs) / (86400 * 30.4375));
            $due = (new DateTimeImmutable((string) $reference['date']))->modify('+' . (int) $intervalMonths . ' months');
            $usage['months'] = ['used' => round($monthsUsed, 1), 'interval' => $intervalMonths, 'remaining' => round($intervalMonths - $monthsUsed, 1),
                'pct' => round($monthsUsed * 100 / $intervalMonths, 2), 'due' => $due->format('Y-m-d')];
        }
        $intervalHours = self::num($system['interval_ore'] ?? null);
        if ($intervalHours !== null && ($hours['value'] ?? null) !== null) {
            $usage['hours'] = ['used' => round((float) $hours['value'], 1), 'interval' => $intervalHours, 'remaining' => round($intervalHours - (float) $hours['value'], 1),
                'pct' => round((float) $hours['value'] * 100 / $intervalHours, 2), 'source' => $hours['source']];
        }
        $pcts = array_values(array_filter(array_map(static fn ($u) => $u['pct'] ?? null, $usage), static fn ($v) => $v !== null));
        $usagePct = $pcts !== [] ? max($pcts) : null;
        $intervalsConfigured = $intervalKm !== null || $intervalMonths !== null || $intervalHours !== null;

        // --- nivel (doar masurat) -----------------------------------------------------------------
        $level = ['state' => 'necunoscut', 'status' => null, 'at' => null, 'value' => null, 'unit' => null];
        $lastCheck = null;
        $lastTopup = null;
        foreach ($events as $event) {
            if ((string) $event['tip'] === 'verificare') {
                $lastCheck = $event;
            } else {
                $lastTopup = $event;
            }
        }
        $lastEvent = $events !== [] ? $events[count($events) - 1] : null;
        if ($lastCheck !== null && ($lastTopup === null || (string) $lastTopup['efectuat_la'] < (string) $lastCheck['efectuat_la'])) {
            $level = ['state' => 'masurat', 'status' => (string) $lastCheck['nivel_status'], 'at' => (string) $lastCheck['efectuat_la'],
                'value' => self::num($lastCheck['nivel_valoare']), 'unit' => $lastCheck['nivel_unitate']];
        } elseif ($lastTopup !== null && (int) $lastTopup['pana_la_reper'] === 1) {
            $level = ['state' => 'completat_la_reper', 'status' => 'la_maxim', 'at' => (string) $lastTopup['efectuat_la'], 'value' => null, 'unit' => null];
        } elseif ($lastTopup !== null) {
            $level = ['state' => 'necunoscut', 'status' => null, 'at' => (string) $lastTopup['efectuat_la'], 'value' => null, 'unit' => null];
        }

        // --- consum observat (doar intre completari la acelasi reper) ----------------------------
        $consumption = self::observedConsumption($system, $events, $kmUsage['source'] ?? ($tele['source'] ?? null), $tele['km']);

        // --- anomalii -------------------------------------------------------------------------------
        $anomaly = null;
        $since90 = date('Y-m-d H:i:s', $nowTs - 90 * 86400);
        $recentTopups = count(array_filter($events, static fn ($e) => (string) $e['tip'] === 'completare' && (string) $e['efectuat_la'] >= $since90));
        if ((int) ($system['circuit_inchis'] ?? 0) === 1 && $recentTopups >= 2) {
            $anomaly = $recentTopups . ' completări în 90 de zile pe un circuit închis';
        } elseif (($prag = self::num($system['prag_consum'] ?? null)) !== null && ($consumption['observed'] ?? null) !== null && $consumption['observed'] > $prag) {
            $anomaly = 'Consum observat ' . self::fmt($consumption['observed']) . ' ' . ($system['um'] ?? 'L') . '/1.000 km peste pragul de ' . self::fmt($prag);
        }
        $critical = (int) ($system['critic'] ?? 0) === 1 || (int) ($system['circuit_inchis'] ?? 0) === 1;

        // --- verificare periodica -------------------------------------------------------------
        $checkDue = false;
        $checkDays = self::num($system['verificare_zile'] ?? null);
        if ($checkDays !== null) {
            $checkDue = $lastCheck === null || ($nowTs - (int) strtotime((string) $lastCheck['efectuat_la'])) > $checkDays * 86400;
        }

        // --- status (prioritate) si actiunea recomandata ------------------------------------------
        $warn = (int) ($system['avertizare_procent'] ?? 90);
        $status = 'normal';
        $action = '—';
        if ($anomaly !== null) {
            $status = 'consum_anormal';
            $action = $critical ? 'Inspecție tehnică: pierdere suspectată (nu doar completare)' : 'Verifică scurgerile și consumul';
        } elseif ($usagePct !== null && $usagePct >= 100) {
            $status = 'revizie_depasita';
            $action = 'Programează schimbul complet';
        } elseif ($level['state'] === 'masurat' && $level['status'] === 'sub_minim') {
            $status = 'completare_necesara';
            $action = $critical ? 'Completează și inspectează circuitul' : 'Completează până la reper';
        } elseif ($usagePct !== null && $usagePct >= $warn) {
            $status = 'revizie_apropiata';
            $action = 'Planifică schimbul complet';
        } elseif ($checkDue || ($level['state'] === 'masurat' && in_array($level['status'], ['la_minim', 'peste_maxim'], true))) {
            $status = 'verificare_necesara';
            $action = $level['status'] === 'la_minim' ? 'Verifică și completează la următoarea oprire'
                : ($level['status'] === 'peste_maxim' ? 'Verifică nivelul (peste maxim)' : 'Verifică nivelul');
        } elseif (($intervalsConfigured && $usagePct === null) || (!$intervalsConfigured && $checkDays === null && $level['state'] === 'necunoscut')) {
            $status = 'date_insuficiente';
            $action = $reference['date'] === null && $intervalsConfigured ? 'Înregistrează ultimul schimb (referința)' : ($kmNote !== '' ? $kmNote : 'Configurează intervalele / verificarea');
        }

        return [
            'status' => $status,
            'status_label' => self::STATUSES[$status][0],
            'status_class' => self::STATUSES[$status][1],
            'action' => $action,
            'anomaly' => $anomaly,
            'telemetry' => $tele,
            'km_note' => $kmNote,
            'reference' => $reference,
            'usage' => $usage,
            'usage_pct' => $usagePct,
            'hours' => $hours,
            'level' => $level,
            'consumption' => $consumption,
            'last_check' => $lastCheck,
            'last_topup' => $lastTopup,
            'last_event_at' => $lastEvent['efectuat_la'] ?? null,
            'check_due' => $checkDue,
        ];
    }

    /**
     * Consum observat = cantitatea adaugata intre doua puncte de referinta comparabile (schimb complet
     * sau completare „pana la reper”) / km parcursi intre ele x 1.000. Fara puncte comparabile sau cu
     * prea putini km: null (nu se aplica o formula generica).
     */
    public static function observedConsumption(array $system, array $events, ?string $source, ?float $currentKm): array
    {
        $result = ['observed' => null, 'forecast' => null, 'confidence' => null, 'span_km' => null, 'topups' => 0, 'estimated_missing' => null, 'source' => $source];
        if ($source === null) {
            return $result;
        }
        $kmField = $source === 'km_gps' ? 'km_gps' : 'km_can';
        $start = null;
        foreach ($events as $index => $event) {
            if ((string) $event['tip'] === 'schimb_complet' && self::num($event[$kmField]) !== null) {
                $start = $index;
            }
        }
        if ($start === null) {
            foreach ($events as $index => $event) {
                if ((int) $event['pana_la_reper'] === 1 && self::num($event[$kmField]) !== null) {
                    $start = $index;
                    break;
                }
            }
        }
        if ($start === null) {
            return $result;
        }
        $startKm = (float) $events[$start][$kmField];
        $added = 0.0;
        $pending = 0.0;
        $topups = 0;
        $lastRefKm = null;
        for ($i = $start + 1, $n = count($events); $i < $n; $i++) {
            $event = $events[$i];
            if ((string) $event['tip'] === 'schimb_complet') {
                break;
            }
            if ((string) $event['tip'] !== 'completare') {
                continue;
            }
            $pending += (float) $event['cantitate'];
            $km = self::num($event[$kmField]);
            if ((int) $event['pana_la_reper'] === 1 && $km !== null && $km > $startKm) {
                $added += $pending;
                $pending = 0.0;
                $topups++;
                $lastRefKm = $km;
            }
        }
        if ($lastRefKm === null) {
            return $result;
        }
        $span = $lastRefKm - $startKm;
        if ($span < 500) {
            return array_merge($result, ['span_km' => round($span)]);
        }
        $observed = $added / $span * 1000;
        $result['observed'] = round($observed, 3);
        $result['forecast'] = round($observed, 3);
        $result['span_km'] = round($span);
        $result['topups'] = $topups;
        $result['confidence'] = $topups >= 3 && $span >= 5000 ? 'ridicat' : ($span >= 2000 ? 'mediu' : 'scazut');
        if ($currentKm !== null && $currentKm > $lastRefKm) {
            $missing = $observed * ($currentKm - $lastRefKm) / 1000;
            $capacity = self::num($system['capacitate_nominala'] ?? null);
            $result['estimated_missing'] = round($capacity !== null ? min($capacity, $missing) : $missing, 2);
        }

        return $result;
    }

    // =========================================================================
    // Telemetrie (citiri existente; nicio cerere API suplimentara)
    // =========================================================================

    /** @return array<int, array{can?: array{km: float, at: string}, sas?: array{km: float, at: string}}> */
    public function latestReadings(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_filter(array_map('intval', $vehicleIds)));
        if ($vehicleIds === [] || !$this->tableExists('vehicle_odometer_readings')) {
            return [];
        }
        $in = implode(',', $vehicleIds);
        $rows = $this->db->query(
            "SELECT r.vehicle_id, r.provider_code, r.odometer_km, r.measured_at
             FROM vehicle_odometer_readings r
             JOIN (SELECT vehicle_id, provider_code, MAX(measured_at) AS last_at FROM vehicle_odometer_readings
                   WHERE vehicle_id IN ($in) AND provider_code IN ('can', 'sas') AND validation_status = 'valid'
                   GROUP BY vehicle_id, provider_code) x
               ON x.vehicle_id = r.vehicle_id AND x.provider_code = r.provider_code AND x.last_at = r.measured_at
             WHERE r.validation_status = 'valid'"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['vehicle_id']][(string) $row['provider_code']] = ['km' => (float) $row['odometer_km'], 'at' => (string) $row['measured_at']];
        }

        return $result;
    }

    /** Ultima citire valida la / inainte de momentul dat (max. 7 zile in urma); altfel null. */
    public function readingAt(int $vehicleId, string $provider, string $at): ?string
    {
        if ($vehicleId <= 0 || !$this->tableExists('vehicle_odometer_readings')) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT odometer_km FROM vehicle_odometer_readings
             WHERE vehicle_id = :v AND provider_code = :p AND validation_status = 'valid' AND measured_at <= :at AND measured_at >= :from
             ORDER BY measured_at DESC LIMIT 1"
        );
        $stmt->execute([':v' => $vehicleId, ':p' => $provider, ':at' => $at, ':from' => date('Y-m-d H:i:s', (int) strtotime($at) - self::READING_WINDOW_DAYS * 86400)]);
        $km = $stmt->fetchColumn();

        return $km !== false ? (string) $km : null;
    }

    /**
     * Orele de la referinta: motor (SAS work_seconds, doar daca zilele sunt acoperite) sau pompa /
     * PTO (orele de aspirare din curse). Sursele nu se amesteca.
     *
     * @return array{value: ?float, source: string, note: string}
     */
    public function hoursSince(array $system, ?string $referenceDate, DateTimeImmutable $now): array
    {
        $source = (string) ($system['sursa_ore'] ?? 'none');
        if ($source === 'none') {
            return ['value' => null, 'source' => 'none', 'note' => ''];
        }
        if ($referenceDate === null) {
            return ['value' => null, 'source' => $source, 'note' => 'Fără referință (ultimul schimb).'];
        }
        $vehicleId = (int) $system['vehicle_id'];
        if ($source === 'ore_motor') {
            if (!$this->tableExists('revizii_sas_vehicule') || !$this->tableExists('revizii_sas_segmente')) {
                return ['value' => null, 'source' => $source, 'note' => 'Orele motor SAS nu sunt sincronizate.'];
            }
            $car = $this->db->prepare('SELECT sas_car_id FROM revizii_sas_vehicule WHERE vehicle_id = :v');
            $car->execute([':v' => $vehicleId]);
            $carId = $car->fetchColumn();
            if ($carId === false) {
                return ['value' => null, 'source' => $source, 'note' => 'Vehiculul nu e legat la SAS în Revizii.'];
            }
            $covered = $this->db->prepare("SELECT MIN(zi) FROM revizii_sas_acoperire WHERE sas_car_id = :c AND status = 'ok'");
            $covered->execute([':c' => $carId]);
            $firstDay = $covered->fetchColumn();
            if ($firstDay === false || $firstDay === null || (string) $firstDay > substr($referenceDate, 0, 10)) {
                return ['value' => null, 'source' => $source, 'note' => 'Orele SAS nu acoperă perioada de la ultimul schimb.'];
            }
            $sum = $this->db->prepare('SELECT COALESCE(SUM(work_seconds), 0) FROM revizii_sas_segmente WHERE sas_car_id = :c AND date_start >= :d');
            $sum->execute([':c' => $carId, ':d' => $referenceDate]);

            return ['value' => round((int) $sum->fetchColumn() / 3600, 1), 'source' => $source, 'note' => 'Ore motor din segmentele SAS'];
        }
        if (!class_exists('ReviziiModel') && is_file(dirname(__DIR__) . '/models/ReviziiModel.php')) {
            require_once dirname(__DIR__) . '/models/ReviziiModel.php';
        }
        if (!class_exists('ReviziiModel')) {
            return ['value' => null, 'source' => $source, 'note' => 'Orele de aspirare nu sunt disponibile.'];
        }
        $events = (new ReviziiModel($this->db))->getAspirationEvents([$vehicleId], substr($referenceDate, 0, 10));
        $refTs = (int) strtotime($referenceDate);
        $total = 0.0;
        foreach ($events['events'][$vehicleId] ?? [] as $event) {
            if ($event['start'] >= $refTs && $event['start'] <= $now->getTimestamp()) {
                $total += (float) $event['hours'];
            }
        }
        $missing = count($events['missing'][$vehicleId] ?? []);

        return ['value' => round($total, 1), 'source' => $source,
            'note' => 'Ore pompă din curse (introduse manual)' . ($missing > 0 ? '; ' . $missing . ' curse de compresor fără ore' : '')];
    }

    // =========================================================================

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
            $stmt->execute([':t' => $table]);
            $cache[$table] = (int) $stmt->fetchColumn() > 0;
        }

        return $cache[$table];
    }

    private static function num(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    public static function fmt(?float $value, int $decimals = 2): string
    {
        if ($value === null) {
            return '—';
        }

        return rtrim(rtrim(number_format($value, $decimals, ',', '.'), '0'), ',');
    }

    private function posInt(mixed $value): ?int
    {
        $value = preg_replace('/[^\d]/', '', (string) $value) ?? '';

        return $value !== '' && (int) $value > 0 ? (int) $value : null;
    }

    private function decimal(mixed $value, int $decimals = 3): ?string
    {
        $text = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if ($text === '') {
            return null;
        }
        if (!is_numeric($text) || (float) $text < 0) {
            throw new InvalidArgumentException('Valoare numerică invalidă: „' . $value . '”.');
        }

        return number_format((float) $text, $decimals, '.', '');
    }
}
