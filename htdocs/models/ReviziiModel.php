<?php
declare(strict_types=1);

/**
 * Date pentru Revizii → Schimb ulei.
 *
 * Tabele (create la runtime, oglinda in database/update_revizii_schimb_ulei.sql):
 *  - revizii_setari         factor km/ora motor, prag avertizare, interval implicit;
 *  - revizii_planuri        un plan per componenta (vehicul) si tip de revizie:
 *                           interval, baseline (momentul de la care se aduna uzura);
 *  - revizii_istoric        reviziile inregistrate — doar INSERT, niciodata modificat;
 *  - revizii_sas_vehicule   maparea vehicul local ↔ carId SAS (scrisa de sincronizare,
 *                           pagina nu cheama API-ul);
 *  - revizii_sas_segmente   segmentele travelsheet (km, durata, ore motor pe loc);
 *  - revizii_sas_acoperire  zilele sincronizate per carId (ok / eroare) — o zi lipsa
 *                           inseamna date necunoscute, nu 0 km.
 *
 * Odometrul vehiculului (vehicule.km_bord) si contorul vechi km_revizie NU sunt
 * modificate de acest modul.
 */
class ReviziiModel
{
    public const TIP_SCHIMB_ULEI = 'schimb_ulei';

    public const DEFAULT_SETTINGS = [
        'factor_km_ora' => '40',
        'prag_avertizare_km' => '1500',
        'interval_implicit_km' => '10000',
        'backfill_max_zile' => '180',
        // ore_aspirare = regula existenta din Dispecer curse; sas_pe_loc = idleEngineSeconds SasFleet.
        'sursa_ore_motor' => 'ore_aspirare',
    ];

    /**
     * Tab-urile paginii. 'ore' = se aplica conversia orelor motor (doar uleiul: motorul
     * pornit pe loc uzeaza uleiul, nu placutele sau anvelopele).
     */
    public const TABS = [
        'schimb_ulei' => ['label' => 'Schimb ulei', 'ore' => true],
        'placute_frana' => ['label' => 'Plăcuțe frână', 'ore' => false],
        'anvelope' => ['label' => 'Anvelope', 'ore' => false],
    ];

    /**
     * Componentele urmarite per tab: tip_revizie => eticheta, separat pentru vehiculele cu
     * motor si pentru semi-remorci. Fiecare tip are plan, T0 si istoric proprii.
     */
    public const TAB_ITEMS = [
        // Uleiul de motor: doar vehiculele cu motor (cap tractor, camion), nu semi-remorca.
        'schimb_ulei' => [
            'motor' => ['schimb_ulei' => ''],
            'trailer' => [],
        ],
        'placute_frana' => [
            'motor' => ['placute_fata' => 'Față', 'placute_spate' => 'Spate'],
            'trailer' => ['placute_semi' => 'Set'],
        ],
        'anvelope' => [
            'motor' => ['anvelope' => ''],
            'trailer' => ['anvelope' => ''],
        ],
    ];

    /** @return array<string, string> tip_revizie => eticheta */
    public static function itemsFor(string $tab, bool $isTrailer): array
    {
        return self::TAB_ITEMS[$tab][$isTrailer ? 'trailer' : 'motor'] ?? [];
    }

    /** Numele componentei pentru afisare (ex. „Plăcuțe spate”, „Anvelope semi-remorcă”). */
    public static function componentName(string $tip, string $vehicleType): string
    {
        $trailer = is_trailer_vehicle_type($vehicleType);
        $unit = $trailer ? 'semi-remorcă' : ($vehicleType === 'camion' ? 'camion' : 'cap tractor');

        return match ($tip) {
            'schimb_ulei' => 'Ulei motor',
            'placute_fata' => 'Plăcuțe față',
            'placute_spate' => 'Plăcuțe spate',
            'placute_semi' => 'Plăcuțe semi-remorcă',
            'anvelope' => 'Anvelope ' . $unit,
            default => $tip,
        };
    }

    /** Actiunea inregistrata (doar ce aplicatia inregistreaza efectiv: T0 nou = revizie / inlocuire). */
    public static function actionName(string $tip): string
    {
        return match (self::tabOfTip($tip)) {
            'schimb_ulei' => 'Schimb ulei',
            'placute_frana' => 'Înlocuire plăcuțe',
            'anvelope' => 'Înlocuire anvelope',
            default => 'Revizie',
        };
    }

    /** Tab-ul urmareste si semi-remorci (deci are sens gruparea pe ansamblu)? */
    public static function tabHasTrailers(string $tab): bool
    {
        return (self::TAB_ITEMS[$tab]['trailer'] ?? []) !== [];
    }

    public static function tabOfTip(string $tip): ?string
    {
        foreach (self::TAB_ITEMS as $tab => $groups) {
            if (isset($groups['motor'][$tip]) || isset($groups['trailer'][$tip])) {
                return $tab;
            }
        }

        return null;
    }

    public const HOUR_SOURCES = [
        'ore_aspirare' => 'Ore aspirare din curse (regula existentă)',
        'sas_pe_loc' => 'SasFleet: motor pornit cu vehiculul pe loc',
    ];

    private PDO $db;
    private static bool $schemaReady = false;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureSchema();
    }

    public function db(): PDO
    {
        return $this->db;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS revizii_setari (
                setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS revizii_planuri (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                tip_revizie VARCHAR(40) NOT NULL DEFAULT 'schimb_ulei',
                interval_km INT UNSIGNED NOT NULL,
                km_ultima_revizie INT UNSIGNED NULL,
                prag_avertizare_km INT UNSIGNED NULL,
                baseline_at DATETIME NOT NULL,
                consum_initial_km DECIMAL(10,1) NOT NULL DEFAULT 0,
                consum_initial_sursa VARCHAR(20) NOT NULL DEFAULT 'niciunul',
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_revizii_plan (vehicle_id, tip_revizie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS revizii_istoric (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                tip_revizie VARCHAR(40) NOT NULL DEFAULT 'schimb_ulei',
                data_revizie DATETIME NOT NULL,
                km_bord INT UNSIGNED NULL,
                interval_km INT UNSIGNED NOT NULL,
                km_efectuati DECIMAL(10,1) NULL,
                ore_motor DECIMAL(10,2) NULL,
                km_echivalenti DECIMAL(10,1) NULL,
                factor_km_ora DECIMAL(6,2) NOT NULL,
                sursa_ore VARCHAR(20) NULL,
                date_complete TINYINT(1) NOT NULL DEFAULT 1,
                observatii VARCHAR(500) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_revizii_istoric_vehicul (vehicle_id, tip_revizie, data_revizie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS revizii_sas_vehicule (
                vehicle_id INT UNSIGNED NOT NULL PRIMARY KEY,
                sas_car_id INT UNSIGNED NOT NULL,
                plate VARCHAR(30) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_revizii_sas_car (sas_car_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS revizii_sas_segmente (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                sas_car_id INT UNSIGNED NOT NULL,
                date_start DATETIME NOT NULL,
                date_end DATETIME NOT NULL,
                distance_km DECIMAL(10,3) NOT NULL DEFAULT 0,
                work_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                idle_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                fetched_at DATETIME NOT NULL,
                UNIQUE KEY uq_revizii_segment (sas_car_id, date_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS revizii_sas_acoperire (
                sas_car_id INT UNSIGNED NOT NULL,
                zi DATE NOT NULL,
                status VARCHAR(10) NOT NULL,
                inchisa TINYINT(1) NOT NULL DEFAULT 0,
                segmente INT UNSIGNED NOT NULL DEFAULT 0,
                eroare VARCHAR(500) NULL,
                fetched_at DATETIME NOT NULL,
                PRIMARY KEY (sas_car_id, zi)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($statements as $sql) {
            $this->db->exec($sql);
        }
        // Coloane adaugate dupa prima versiune a tabelelor.
        $this->ensureColumn('revizii_planuri', 'prag_avertizare_km', 'INT UNSIGNED NULL AFTER km_ultima_revizie');
        $this->ensureColumn('revizii_istoric', 'sursa_ore', 'VARCHAR(20) NULL AFTER factor_km_ora');

        self::$schemaReady = true;
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute([':t' => $table, ':c' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
        }
    }

    // ---------------------------------------------------------------- setari

    /** @return array<string, string> */
    public function getSettings(): array
    {
        $settings = self::DEFAULT_SETTINGS;
        foreach ($this->db->query('SELECT setting_key, setting_value FROM revizii_setari')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        return $settings;
    }

    public function saveSetting(string $key, string $value, ?int $userId): void
    {
        if (!array_key_exists($key, self::DEFAULT_SETTINGS)) {
            throw new InvalidArgumentException('Setare necunoscuta: ' . $key);
        }
        $stmt = $this->db->prepare(
            'INSERT INTO revizii_setari (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([':k' => $key, ':v' => $value, ':u' => $userId]);
    }

    // ---------------------------------------------------------------- vehicule

    /**
     * Vehiculele monitorizate pentru schimb ulei: capete tractor, camioane, semi-remorci active.
     * $includeInactive: si cele inactive (doar pentru simularea pe date istorice).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVehicles(bool $includeInactive = false): array
    {
        $rows = $this->db->query(
            "SELECT id, nr_inmatriculare, marca, model, tip_vehicul, status, COALESCE(km_bord, 0) AS km_bord, poza_stocata
             FROM vehicule
             WHERE " . ($includeInactive ? '1 = 1' : "status = 'activ'") . "
               AND tip_vehicul IN ('cap_tractor', 'camion', 'semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
             ORDER BY nr_inmatriculare"
        )->fetchAll(PDO::FETCH_ASSOC);

        $vehicles = [];
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['km_bord'] = (int) $row['km_bord'];
            $vehicles[$row['id']] = $row;
        }

        return $vehicles;
    }

    /**
     * Istoricul de cuplare (tractor ↔ semi-remorca) care se suprapune cu [$from, acum].
     *
     * @return array<int, array{tractor_id:int, semiremorca_id:int, activ:int, data_start:string, data_end:?string}>
     */
    public function getCouplings(?string $from = null): array
    {
        $sql = 'SELECT tractor_id, semiremorca_id, activ, data_start, data_end FROM vehicule_cuplaje';
        $params = [];
        if ($from !== null) {
            $sql .= ' WHERE data_end IS NULL OR data_end > :from';
            $params[':from'] = $from;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY data_start');
        $stmt->execute($params);

        return array_map(static fn (array $r): array => [
            'tractor_id' => (int) $r['tractor_id'],
            'semiremorca_id' => (int) $r['semiremorca_id'],
            'activ' => (int) $r['activ'],
            'data_start' => (string) $r['data_start'],
            'data_end' => $r['data_end'] !== null ? (string) $r['data_end'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Prima cuplare inregistrata vreodata — inainte de ea istoricul de cuplare nu exista. */
    public function getCouplingHistoryStart(): ?string
    {
        $value = $this->db->query('SELECT MIN(data_start) FROM vehicule_cuplaje')->fetchColumn();

        return $value !== false && $value !== null ? (string) $value : null;
    }

    // ---------------------------------------------------------------- planuri

    /** @return array<string, array<int, array<string, mixed>>> tip_revizie => vehicle_id => plan */
    public function getAllPlans(): array
    {
        $plans = [];
        foreach ($this->db->query('SELECT * FROM revizii_planuri')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $plans[(string) $row['tip_revizie']][(int) $row['vehicle_id']] = $row;
        }

        return $plans;
    }

    /** Ultima revizie inregistrata (data_revizie) pentru un vehicul si tip, daca exista. */
    public function getLastServiceAt(int $vehicleId, string $tip): ?string
    {
        $stmt = $this->db->prepare('SELECT MAX(data_revizie) FROM revizii_istoric WHERE vehicle_id = :v AND tip_revizie = :t');
        $stmt->execute([':v' => $vehicleId, ':t' => $tip]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null ? (string) $value : null;
    }

    /** @return array<int, array<string, mixed>> vehicle_id => plan */
    public function getPlans(string $tip = self::TIP_SCHIMB_ULEI): array
    {
        $stmt = $this->db->prepare('SELECT * FROM revizii_planuri WHERE tip_revizie = :tip');
        $stmt->execute([':tip' => $tip]);
        $plans = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $plans[(int) $row['vehicle_id']] = $row;
        }

        return $plans;
    }

    /**
     * Salveaza configurarea unei componente.
     *  - plan nou: baseline = acum; daca s-au dat km la ultima revizie, uzura de pana
     *    acum se ia din odometru (km_bord − km la ultima revizie, fara ore motor);
     *  - schimbarea intervalului NU reseteaza uzura acumulata;
     *  - schimbarea km la ultima revizie reporneste baseline-ul (acum + noul consum initial);
     *    un camp lasat gol pastreaza baseline-ul existent.
     */
    public function savePlan(int $vehicleId, int $intervalKm, ?int $kmLastService, int $kmBord, ?int $userId, ?int $warnKm = null, string $tip = self::TIP_SCHIMB_ULEI): void
    {
        $existing = $this->getPlans($tip)[$vehicleId] ?? null;
        $now = date('Y-m-d H:i:s');
        $initial = $kmLastService !== null ? max(0, $kmBord - $kmLastService) : 0;

        if ($existing === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO revizii_planuri (vehicle_id, tip_revizie, interval_km, km_ultima_revizie, prag_avertizare_km, baseline_at, consum_initial_km, consum_initial_sursa, updated_by)
                 VALUES (:v, :tip, :interval, :kmlast, :warn, :baseline, :initial, :sursa, :u)'
            );
            $stmt->execute([
                ':v' => $vehicleId, ':tip' => $tip, ':interval' => $intervalKm, ':kmlast' => $kmLastService, ':warn' => $warnKm,
                ':baseline' => $now, ':initial' => $initial,
                ':sursa' => $kmLastService !== null ? 'odometru' : 'niciunul', ':u' => $userId,
            ]);
            return;
        }

        $previousKm = $existing['km_ultima_revizie'] !== null ? (int) $existing['km_ultima_revizie'] : null;
        // Camp gol = "nu schimb": uzura acumulata nu se pierde dintr-o omisiune.
        if ($kmLastService !== null && $kmLastService !== $previousKm) {
            $stmt = $this->db->prepare(
                'UPDATE revizii_planuri SET interval_km = :interval, km_ultima_revizie = :kmlast, prag_avertizare_km = :warn, baseline_at = :baseline,
                        consum_initial_km = :initial, consum_initial_sursa = :sursa, updated_by = :u
                 WHERE id = :id'
            );
            $stmt->execute([
                ':interval' => $intervalKm, ':kmlast' => $kmLastService, ':warn' => $warnKm, ':baseline' => $now, ':initial' => $initial,
                ':sursa' => $kmLastService !== null ? 'odometru' : 'niciunul', ':u' => $userId, ':id' => (int) $existing['id'],
            ]);
            return;
        }

        $stmt = $this->db->prepare('UPDATE revizii_planuri SET interval_km = :interval, prag_avertizare_km = :warn, updated_by = :u WHERE id = :id');
        $stmt->execute([':interval' => $intervalKm, ':warn' => $warnKm, ':u' => $userId, ':id' => (int) $existing['id']]);
    }

    /**
     * Inregistreaza o revizie: rand nou in istoric (cu instantaneul uzurii consumate)
     * si mutarea baseline-ului planului la momentul reviziei. Istoricul anterior ramane intact.
     *
     * @param array<string, mixed> $snapshot rezultatul calculului pana la data reviziei
     */
    public function recordService(
        int $vehicleId,
        string $serviceAt,
        ?int $kmBord,
        int $intervalKm,
        array $snapshot,
        float $factor,
        string $hourSource,
        ?string $notes,
        ?int $userId,
        string $tip = self::TIP_SCHIMB_ULEI
    ): void {
        $insert = $this->db->prepare(
            'INSERT INTO revizii_istoric (vehicle_id, tip_revizie, data_revizie, km_bord, interval_km, km_efectuati, ore_motor,
                                          km_echivalenti, factor_km_ora, sursa_ore, date_complete, observatii, created_by)
             VALUES (:v, :tip, :at, :km, :interval, :driven, :hours, :total, :factor, :source, :complete, :notes, :u)'
        );
        $insert->execute([
            ':v' => $vehicleId, ':tip' => $tip, ':at' => $serviceAt, ':km' => $kmBord, ':interval' => $intervalKm,
            ':driven' => $snapshot['km_efectuati'] ?? null, ':hours' => $snapshot['ore_motor'] ?? null,
            ':total' => $snapshot['km_total'] ?? null, ':factor' => $factor, ':source' => $hourSource,
            ':complete' => !empty($snapshot['complet']) ? 1 : 0, ':notes' => $notes, ':u' => $userId,
        ]);

        $upsert = $this->db->prepare(
            "INSERT INTO revizii_planuri (vehicle_id, tip_revizie, interval_km, km_ultima_revizie, baseline_at, consum_initial_km, consum_initial_sursa, updated_by)
             VALUES (:v, :tip, :interval, :km, :at, 0, 'revizie', :u)
             ON DUPLICATE KEY UPDATE km_ultima_revizie = VALUES(km_ultima_revizie), baseline_at = VALUES(baseline_at),
                                     consum_initial_km = 0, consum_initial_sursa = 'revizie', updated_by = VALUES(updated_by)"
        );
        $upsert->execute([':v' => $vehicleId, ':tip' => $tip, ':interval' => $intervalKm, ':km' => $kmBord, ':at' => $serviceAt, ':u' => $userId]);
    }

    /**
     * Ultimele revizii per vehicul (cele mai noi primele).
     *
     * @param array<int, int> $vehicleIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getHistory(array $vehicleIds, int $perVehicle = 5, string $tip = self::TIP_SCHIMB_ULEI): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $vehicleIds))));
        if ($vehicleIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT h.*, u.nume AS created_by_name
             FROM revizii_istoric h
             LEFT JOIN utilizatori u ON u.id = h.created_by
             WHERE h.tip_revizie = ? AND h.vehicle_id IN ($placeholders)
             ORDER BY h.data_revizie DESC, h.id DESC"
        );
        $stmt->execute(array_merge([$tip], $vehicleIds));
        $history = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $vehicleId = (int) $row['vehicle_id'];
            if (count($history[$vehicleId] ?? []) < $perVehicle) {
                $history[$vehicleId][] = $row;
            }
        }

        return $history;
    }

    /**
     * Istoricul unificat (toate tipurile), cel mai nou primul — sursa pentru „Activitate
     * recentă” si graficul lunar din Prezentare generală. Un rand = o inregistrare reala.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllHistory(int $limit = 5000): array
    {
        $stmt = $this->db->prepare(
            'SELECT h.id, h.vehicle_id, h.tip_revizie, h.data_revizie, h.km_bord, h.interval_km, h.km_echivalenti,
                    h.date_complete, h.observatii, h.created_by, u.nume AS created_by_name,
                    v.nr_inmatriculare, v.tip_vehicul
             FROM revizii_istoric h
             JOIN vehicule v ON v.id = h.vehicle_id
             LEFT JOIN utilizatori u ON u.id = h.created_by
             ORDER BY h.data_revizie DESC, h.id DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ---------------------------------------------------------------- ore aspirare (Dispecer curse)

    /**
     * Orele de aspirare (regula existenta: ore_aspirare, cu fallback ore_functionare)
     * ale curselor nesterse, ca evenimente cu interval [inceput, sfarsit].
     * Cand cursa are faze (curse_segmente) cu ore, se folosesc fazele — cu vehiculul
     * fazei — si nu totalul cursei (altfel orele s-ar numara de doua ori).
     *
     * @param array<int, int> $vehicleIds
     * @return array{events: array<int, array<int, array{start:int, end:int, hours:float, race_id:int}>>, missing: array<int, array<int, array{start:int, end:int, race_id:int}>>}
     *         missing = curse de compresor fara ore completate
     */
    public function getAspirationEvents(array $vehicleIds, string $fromDate): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map('intval', $vehicleIds))));
        $result = ['events' => [], 'missing' => []];
        if ($vehicleIds === []) {
            return $result;
        }
        $in = implode(',', $vehicleIds);
        $span = static function (array $row): array {
            $startDate = (string) ($row['data_inceput'] ?: $row['data_cursa']);
            $endDate = (string) ($row['data_sfarsit'] ?: $startDate);
            $start = (int) strtotime($startDate . ' ' . ($row['ora_inceput'] ?: '00:00:00'));
            $end = (int) strtotime($endDate . ' ' . ($row['ora_sfarsit'] ?: '23:59:59'));
            return [$start, max($start, $end)];
        };

        $phaseRaces = [];
        try {
            $phases = $this->db->prepare(
                "SELECT s.cursa_id, s.vehicle_id, s.data_inceput, s.ora_inceput, s.data_sfarsit, s.ora_sfarsit, s.ore_functionare,
                        c.data_cursa
                 FROM curse_segmente s
                 JOIN curse_dispecer c ON c.id = s.cursa_id AND c.deleted_at IS NULL
                 WHERE s.deleted_at IS NULL AND s.ore_functionare > 0 AND s.vehicle_id IN ($in)
                   AND COALESCE(s.data_sfarsit, s.data_inceput, c.data_cursa) >= :from"
            );
            $phases->execute([':from' => $fromDate]);
            foreach ($phases->fetchAll(PDO::FETCH_ASSOC) as $row) {
                [$start, $end] = $span($row);
                $result['events'][(int) $row['vehicle_id']][] = ['start' => $start, 'end' => $end, 'hours' => (float) $row['ore_functionare'], 'race_id' => (int) $row['cursa_id']];
            }
            // O cursa cu faze cu ore (pe orice vehicul) nu se mai numara si la nivel de cursa.
            foreach ($this->db->query('SELECT DISTINCT cursa_id FROM curse_segmente WHERE deleted_at IS NULL AND ore_functionare > 0')->fetchAll(PDO::FETCH_COLUMN) as $raceId) {
                $phaseRaces[(int) $raceId] = true;
            }
        } catch (PDOException) {
            // Tabela de faze lipseste pe instalari vechi: raman doar orele la nivel de cursa.
        }

        $races = $this->db->prepare(
            "SELECT id, vehicle_id, tip_transport, data_cursa, data_inceput, ora_inceput, data_sfarsit, ora_sfarsit,
                    COALESCE(ore_aspirare, ore_functionare) AS ore
             FROM curse_dispecer
             WHERE deleted_at IS NULL AND vehicle_id IN ($in)
               AND COALESCE(data_sfarsit, data_inceput, data_cursa) >= :from
               AND (COALESCE(ore_aspirare, ore_functionare, 0) > 0 OR tip_transport = 'compresor')"
        );
        $races->execute([':from' => $fromDate]);
        foreach ($races->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $raceId = (int) $row['id'];
            if (isset($phaseRaces[$raceId])) {
                continue;
            }
            [$start, $end] = $span($row);
            $hours = (float) ($row['ore'] ?? 0);
            if ($hours <= 0) {
                $result['missing'][(int) $row['vehicle_id']][] = ['start' => $start, 'end' => $end, 'race_id' => $raceId];
                continue;
            }
            $result['events'][(int) $row['vehicle_id']][] = ['start' => $start, 'end' => $end, 'hours' => $hours, 'race_id' => $raceId];
        }

        return $result;
    }

    // ---------------------------------------------------------------- telemetrie SAS

    /** @return array<int, int> vehicle_id => sas_car_id */
    public function getSasMapping(): array
    {
        $map = [];
        foreach ($this->db->query('SELECT vehicle_id, sas_car_id FROM revizii_sas_vehicule')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['vehicle_id']] = (int) $row['sas_car_id'];
        }

        return $map;
    }

    /** @param array<int, array{sas_car_id:int, plate:string}> $mapping vehicle_id => car */
    public function replaceSasMapping(array $mapping): void
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->exec('DELETE FROM revizii_sas_vehicule');
            $stmt = $this->db->prepare('INSERT INTO revizii_sas_vehicule (vehicle_id, sas_car_id, plate) VALUES (:v, :c, :p)');
            foreach ($mapping as $vehicleId => $car) {
                $stmt->execute([':v' => $vehicleId, ':c' => $car['sas_car_id'], ':p' => $car['plate']]);
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Segmentele unui set de carId-uri incepand cu $from.
     *
     * @param array<int, int> $carIds
     * @return array<int, array<int, array{start:int, end:int, km:float, idle:float}>> car => segmente
     */
    public function getSegments(array $carIds, string $from): array
    {
        $carIds = array_values(array_unique(array_filter(array_map('intval', $carIds))));
        if ($carIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($carIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT sas_car_id, date_start, date_end, distance_km, idle_seconds
             FROM revizii_sas_segmente
             WHERE sas_car_id IN ($placeholders) AND date_end > ?
             ORDER BY sas_car_id, date_start"
        );
        $stmt->execute(array_merge($carIds, [$from]));
        $segments = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $segments[(int) $row['sas_car_id']][] = [
                'start' => (int) strtotime((string) $row['date_start']),
                'end' => (int) strtotime((string) $row['date_end']),
                'km' => (float) $row['distance_km'],
                'idle' => (float) $row['idle_seconds'],
            ];
        }

        return $segments;
    }

    /**
     * Zilele sincronizate cu succes, per carId.
     *
     * @param array<int, int> $carIds
     * @return array<int, array<string, bool>>
     */
    public function getOkDays(array $carIds, string $fromDate): array
    {
        $carIds = array_values(array_unique(array_filter(array_map('intval', $carIds))));
        if ($carIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($carIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT sas_car_id, zi FROM revizii_sas_acoperire
             WHERE status = 'ok' AND sas_car_id IN ($placeholders) AND zi >= ?"
        );
        $stmt->execute(array_merge($carIds, [$fromDate]));
        $days = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $days[(int) $row['sas_car_id']][(string) $row['zi']] = true;
        }

        return $days;
    }

    /**
     * Ultima sincronizare reusita si ultima eroare, per carId.
     *
     * @return array<int, array{last_ok:?string, last_error:?string, last_error_at:?string}>
     */
    public function getSyncState(): array
    {
        $state = [];
        $rows = $this->db->query(
            "SELECT sas_car_id,
                    MAX(CASE WHEN status = 'ok' THEN fetched_at END) AS last_ok,
                    MAX(CASE WHEN status = 'eroare' THEN fetched_at END) AS last_error_at
             FROM revizii_sas_acoperire GROUP BY sas_car_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $state[(int) $row['sas_car_id']] = [
                'last_ok' => $row['last_ok'] !== null ? (string) $row['last_ok'] : null,
                'last_error_at' => $row['last_error_at'] !== null ? (string) $row['last_error_at'] : null,
                'last_error' => null,
            ];
        }
        $errors = $this->db->query(
            "SELECT a.sas_car_id, a.eroare FROM revizii_sas_acoperire a
             JOIN (SELECT sas_car_id, MAX(fetched_at) AS m FROM revizii_sas_acoperire WHERE status = 'eroare' GROUP BY sas_car_id) x
               ON x.sas_car_id = a.sas_car_id AND x.m = a.fetched_at
             WHERE a.status = 'eroare'"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($errors as $row) {
            if (isset($state[(int) $row['sas_car_id']])) {
                $state[(int) $row['sas_car_id']]['last_error'] = $row['eroare'] !== null ? (string) $row['eroare'] : null;
            }
        }

        return $state;
    }

    /**
     * Zilele care trebuie (re)aduse din SAS pentru un carId: lipsa, cu eroare sau
     * neinchise (aduse inainte ca ziua sa se termine; azi si ieri se reimprospateaza).
     *
     * @return array<int, string>
     */
    public function getDaysToFetch(int $carId, string $fromDate, string $toDate): array
    {
        $stmt = $this->db->prepare(
            "SELECT zi FROM revizii_sas_acoperire
             WHERE sas_car_id = :c AND zi BETWEEN :f AND :t AND status = 'ok' AND inchisa = 1"
        );
        $stmt->execute([':c' => $carId, ':f' => $fromDate, ':t' => $toDate]);
        $closed = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

        $days = [];
        for ($day = $fromDate; $day <= $toDate; $day = date('Y-m-d', (int) strtotime($day . ' +1 day'))) {
            if (empty($closed[$day])) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * Inlocuieste atomic segmentele unei ferestre [$fromDate 00:00, $toDate 23:59:59]
     * pentru un carId si marcheaza zilele ca sincronizate. Resincronizarea aceleiasi
     * ferestre suprascrie, nu adauga — km si orele motor nu se pot dubla.
     *
     * @param array<int, array{start:string, end:string, km:float, work:int, idle:int}> $segments
     */
    public function storeWindow(int $carId, string $fromDate, string $toDate, array $segments, bool $closed): void
    {
        $now = date('Y-m-d H:i:s');
        $windowStart = $fromDate . ' 00:00:00';
        $windowEnd = $toDate . ' 23:59:59';

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $delete = $this->db->prepare(
                'DELETE FROM revizii_sas_segmente WHERE sas_car_id = :c AND date_start BETWEEN :f AND :t'
            );
            $delete->execute([':c' => $carId, ':f' => $windowStart, ':t' => $windowEnd]);

            $insert = $this->db->prepare(
                'INSERT INTO revizii_sas_segmente (sas_car_id, date_start, date_end, distance_km, work_seconds, idle_seconds, fetched_at)
                 VALUES (:c, :s, :e, :km, :w, :i, :f)
                 ON DUPLICATE KEY UPDATE date_end = VALUES(date_end), distance_km = VALUES(distance_km),
                    work_seconds = VALUES(work_seconds), idle_seconds = VALUES(idle_seconds), fetched_at = VALUES(fetched_at)'
            );
            $perDay = [];
            foreach ($segments as $segment) {
                if ($segment['start'] < $windowStart || $segment['start'] > $windowEnd) {
                    continue; // apartine altei ferestre
                }
                $insert->execute([
                    ':c' => $carId, ':s' => $segment['start'], ':e' => $segment['end'], ':km' => $segment['km'],
                    ':w' => $segment['work'], ':i' => $segment['idle'], ':f' => $now,
                ]);
                $day = substr($segment['start'], 0, 10);
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
            }

            $coverage = $this->db->prepare(
                "INSERT INTO revizii_sas_acoperire (sas_car_id, zi, status, inchisa, segmente, eroare, fetched_at)
                 VALUES (:c, :z, 'ok', :closed, :n, NULL, :f)
                 ON DUPLICATE KEY UPDATE status = 'ok', inchisa = VALUES(inchisa), segmente = VALUES(segmente),
                    eroare = NULL, fetched_at = VALUES(fetched_at)"
            );
            for ($day = $fromDate; $day <= $toDate; $day = date('Y-m-d', (int) strtotime($day . ' +1 day'))) {
                // O zi e "inchisa" doar daca a fost adusa dupa ce s-a terminat (+2h ca SAS sa finalizeze segmentele).
                $dayClosed = $closed && time() >= (int) strtotime($day . ' +1 day +2 hours');
                $coverage->execute([':c' => $carId, ':z' => $day, ':closed' => $dayClosed ? 1 : 0, ':n' => $perDay[$day] ?? 0, ':f' => $now]);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** Marcheaza esecul unei ferestre fara a strica zilele deja aduse cu succes. */
    public function markWindowError(int $carId, string $fromDate, string $toDate, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "INSERT INTO revizii_sas_acoperire (sas_car_id, zi, status, inchisa, segmente, eroare, fetched_at)
             VALUES (:c, :z, 'eroare', 0, 0, :e, :f)
             ON DUPLICATE KEY UPDATE
                eroare = IF(status = 'ok', eroare, VALUES(eroare)),
                fetched_at = IF(status = 'ok', fetched_at, VALUES(fetched_at))"
        );
        for ($day = $fromDate; $day <= $toDate; $day = date('Y-m-d', (int) strtotime($day . ' +1 day'))) {
            $stmt->execute([':c' => $carId, ':z' => $day, ':e' => mb_substr($message, 0, 500), ':f' => $now]);
        }
    }
}
