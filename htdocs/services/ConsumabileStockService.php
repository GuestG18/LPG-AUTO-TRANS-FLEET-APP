<?php
declare(strict_types=1);

/**
 * Consumabile & Fluide Tehnice — stocul fizic (Stoc Piese → tab Consumabile).
 *
 * Separare clara:
 *   - consumabile_produse         catalogul de produse (o singura identitate per produs, legat optional
 *                                  de o componenta din Catalog componente prin component_key)
 *   - consumabile_compatibilitate perechi de produse declarate EXPLICIT compatibile (amestec permis);
 *                                  doua uleiuri cu aceeasi vascozitate NU sunt compatibile automat
 *   - consumabile_recipiente      recipientele fizice (butoi, bidon, IBC...) cu cantitatea curenta
 *   - consumabile_miscari         jurnalul imuabil al tuturor operatiunilor (fara stergere; corectiile
 *                                  sunt stornari = miscari compensatorii, cu referinta la original)
 *
 * Reguli:
 *   - Cantitatile se calculeaza in miimi intregi (fara erori de virgula mobila) si nu pot fi negative.
 *   - Fiecare operatiune e atomica: recipientele implicate se blocheaza (SELECT ... FOR UPDATE, in ordinea
 *     id-urilor, ca doua transferuri simultane sa nu se blocheze reciproc), se valideaza, se scriu
 *     soldurile si miscarea, apoi COMMIT.
 *   - Cheia de idempotenta (UNIQUE) a fiecarei operatiuni face ca un dublu-click / o retrimitere sa
 *     produca o singura inregistrare.
 *   - Transferul intre recipiente NU e consum. Consumul = completare / schimb complet pe vehicul.
 */
final class ConsumabileStockService
{
    public const CONTAINER_TYPES = [
        'butoi' => 'Butoi',
        'bidon' => 'Bidon / flacon',
        'ibc' => 'Container IBC',
        'rezervor' => 'Rezervor de depozitare',
        'altul' => 'Alt recipient',
    ];

    public const CONTAINER_STATUSES = ['activ' => 'Activ', 'in_uz' => 'În uz', 'inactiv' => 'Inactiv'];

    public const UNITS = ['L' => 'L', 'kg' => 'kg', 'buc' => 'buc'];

    public const PRODUCT_KINDS = ['fluid' => 'Fluid tehnic', 'consumabil' => 'Consumabil'];

    public const FLUID_TYPES = [
        'ulei_motor' => 'Ulei motor',
        'ulei_transmisie' => 'Ulei transmisie',
        'ulei_diferential' => 'Ulei diferențial',
        'ulei_servodirectie' => 'Ulei servodirecție',
        'ulei_hidraulic' => 'Ulei hidraulic',
        'antigel' => 'Antigel / lichid răcire',
        'adblue' => 'AdBlue',
        'lichid_frana' => 'Lichid de frână',
        'lichid_parbriz' => 'Lichid parbriz',
        'vaselina' => 'Vaselină / unsoare',
        'altul' => 'Alt produs',
    ];

    /**
     * Categoriile din Catalog componente (nume fara diacritice, litere mici) in care se cauta piesele
     * pentru un tip de fluid. Tipurile lipsa (ulei transmisie, alt produs) arata toate piesele.
     */
    public const FLUID_PART_CATEGORIES = [
        'ulei_motor' => ['motor'],
        'ulei_diferential' => ['rulare'],
        'ulei_servodirectie' => ['directie'],
        'ulei_hidraulic' => ['hidraulic'],
        'antigel' => ['racire'],
        'adblue' => ['evacuare'],
        'lichid_frana' => ['franare'],
        'lichid_parbriz' => ['comfort'],
        'vaselina' => ['suspensie', 'rulare', 'directie'],
    ];

    public const MONITORING_SOURCES = [
        'km_can' => 'KM CAN',
        'km_gps' => 'KM GPS',
        'ore_motor' => 'Ore motor',
        'ore_pto' => 'Ore PTO / pompă',
        'timp' => 'Timp',
        'nivel_can' => 'Nivel CAN',
        'inspectie' => 'Inspecție',
        'consum_alimentari' => 'Consum + alimentări',
    ];

    public const MOVEMENT_TYPES = [
        'intrare' => 'Intrare în stoc',
        'transfer' => 'Transfer între recipiente',
        'completare' => 'Completare fluid vehicul',
        'schimb_complet' => 'Schimb complet fluid',
        'retur' => 'Retur în stoc',
        'pierdere' => 'Pierdere / vărsare',
        'ajustare' => 'Ajustare inventar',
        'stornare' => 'Stornare',
    ];

    /** Miscarile care scot cantitatea din stoc spre utilizare pe vehicul (consum efectiv). */
    public const CONSUMPTION_TYPES = ['completare', 'schimb_complet'];

    private const TABLES = ['consumabile_produse', 'consumabile_compatibilitate', 'consumabile_recipiente', 'consumabile_miscari',
        'vehicule_sisteme_fluide', 'vehicule_fluide_evenimente'];

    private static bool $schemaReady = false;

    public function __construct(private PDO $db)
    {
    }

    // =========================================================================
    // Schema (fara DDL in tranzactie: CREATE TABLE face COMMIT implicit)
    // =========================================================================

    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count(self::TABLES), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)");
        $stmt->execute(self::TABLES);
        if ((int) $stmt->fetchColumn() < count(self::TABLES)) {
            if ($this->db->inTransaction()) {
                throw new RuntimeException('Schema Consumabile lipsește; nu poate fi creată în interiorul unei tranzacții.');
            }
            foreach (self::schemaStatements() as $sql) {
                $this->db->exec($sql);
            }
        }
        // 2026_11_11_000002: piesele pe care se foloseste produsul.
        $column = $this->db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumabile_produse' AND COLUMN_NAME = 'componente_vehicul'");
        if ((int) $column->fetchColumn() === 0 && !$this->db->inTransaction()) {
            $this->db->exec('ALTER TABLE consumabile_produse ADD COLUMN componente_vehicul TEXT NULL AFTER sistem_mecanic');
        }
        // 2026_11_12_000001: compatibilitate pe numere de inmatriculare (id-uri de vehicul), alternativa la tipuri_vehicul.
        $column = $this->db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consumabile_produse' AND COLUMN_NAME = 'vehicule_ids'");
        if ((int) $column->fetchColumn() === 0 && !$this->db->inTransaction()) {
            $this->db->exec('ALTER TABLE consumabile_produse ADD COLUMN vehicule_ids TEXT NULL AFTER tipuri_vehicul');
        }
        self::$schemaReady = true;
    }

    /** @return string[] aceleasi instructiuni ca database/migrations/2026_11_10_000001_consumabile_fluide.sql */
    public static function schemaStatements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS consumabile_produse (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                component_key VARCHAR(10) NULL,
                denumire VARCHAR(190) NOT NULL,
                producator VARCHAR(120) NULL,
                cod_produs VARCHAR(80) NULL,
                tip_articol VARCHAR(20) NOT NULL DEFAULT 'fluid',
                tip_fluid VARCHAR(40) NULL,
                vascozitate VARCHAR(40) NULL,
                specificatie VARCHAR(255) NULL,
                aprobari TEXT NULL,
                um VARCHAR(10) NOT NULL DEFAULT 'L',
                sistem_mecanic VARCHAR(80) NULL,
                componente_vehicul TEXT NULL,
                tipuri_vehicul VARCHAR(120) NULL,
                vehicule_ids TEXT NULL,
                compatibilitate_note TEXT NULL,
                interval_km INT UNSIGNED NULL,
                interval_luni SMALLINT UNSIGNED NULL,
                interval_ore INT UNSIGNED NULL,
                sursa_monitorizare VARCHAR(120) NULL,
                prag_alerta DECIMAL(12,3) NULL,
                critic TINYINT(1) NOT NULL DEFAULT 0,
                circuit_inchis TINYINT(1) NOT NULL DEFAULT 0,
                permite_retur TINYINT(1) NOT NULL DEFAULT 0,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_cp_component (component_key),
                KEY idx_cp_tip (tip_fluid, activ)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS consumabile_compatibilitate (
                produs_a INT UNSIGNED NOT NULL,
                produs_b INT UNSIGNED NOT NULL,
                note VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (produs_a, produs_b),
                CONSTRAINT fk_cc_a FOREIGN KEY (produs_a) REFERENCES consumabile_produse (id),
                CONSTRAINT fk_cc_b FOREIGN KEY (produs_b) REFERENCES consumabile_produse (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS consumabile_recipiente (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cod VARCHAR(40) NOT NULL,
                tip VARCHAR(20) NOT NULL,
                tip_personalizat VARCHAR(60) NULL,
                produs_id INT UNSIGNED NULL,
                um VARCHAR(10) NULL,
                capacitate DECIMAL(12,3) NOT NULL,
                cantitate DECIMAL(12,3) NOT NULL DEFAULT 0,
                prag_minim DECIMAL(12,3) NULL,
                locatie VARCHAR(120) NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'activ',
                lot VARCHAR(60) NULL,
                data_expirare DATE NULL,
                observatii TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_cr_cod (cod),
                KEY idx_cr_produs (produs_id, status),
                CONSTRAINT fk_cr_produs FOREIGN KEY (produs_id) REFERENCES consumabile_produse (id),
                CONSTRAINT chk_cr_cantitate CHECK (cantitate >= 0 AND cantitate <= capacitate)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS consumabile_miscari (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tip VARCHAR(20) NOT NULL,
                produs_id INT UNSIGNED NOT NULL,
                cantitate DECIMAL(12,3) NOT NULL,
                um VARCHAR(10) NOT NULL,
                recipient_sursa_id INT UNSIGNED NULL,
                sursa_inainte DECIMAL(12,3) NULL,
                sursa_dupa DECIMAL(12,3) NULL,
                recipient_dest_id INT UNSIGNED NULL,
                dest_inainte DECIMAL(12,3) NULL,
                dest_dupa DECIMAL(12,3) NULL,
                vehicul_id INT NULL,
                km_vehicul INT UNSIGNED NULL,
                sistem VARCHAR(80) NULL,
                cantitate_scoasa DECIMAL(12,3) NULL,
                furnizor VARCHAR(120) NULL,
                document VARCHAR(120) NULL,
                pret_unitar DECIMAL(12,4) NULL,
                lot VARCHAR(60) NULL,
                data_expirare DATE NULL,
                motiv VARCHAR(255) NULL,
                observatii TEXT NULL,
                referinta_id BIGINT UNSIGNED NULL,
                stornata_de_id BIGINT UNSIGNED NULL,
                idempotency_key VARCHAR(64) NOT NULL,
                efectuat_la DATETIME NOT NULL,
                user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_cm_key (idempotency_key),
                KEY idx_cm_produs (produs_id, efectuat_la),
                KEY idx_cm_vehicul (vehicul_id, efectuat_la),
                KEY idx_cm_sursa (recipient_sursa_id, efectuat_la),
                KEY idx_cm_dest (recipient_dest_id, efectuat_la),
                KEY idx_cm_tip (tip, efectuat_la),
                KEY idx_cm_ref (referinta_id),
                CONSTRAINT fk_cm_produs FOREIGN KEY (produs_id) REFERENCES consumabile_produse (id),
                CONSTRAINT fk_cm_sursa FOREIGN KEY (recipient_sursa_id) REFERENCES consumabile_recipiente (id),
                CONSTRAINT fk_cm_dest FOREIGN KEY (recipient_dest_id) REFERENCES consumabile_recipiente (id),
                CONSTRAINT chk_cm_cantitate CHECK (cantitate > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // Etapa 2: sistemele de fluide ale fiecarui vehicul si evenimentele lor (verificari masurate,
            // completari, schimburi complete). Referinta de revizie = ultimul schimb neanulat, altfel
            // referinta manuala din sistem. Km / orele se fotografiaza la momentul evenimentului.
            "CREATE TABLE IF NOT EXISTS vehicule_sisteme_fluide (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT NOT NULL,
                sistem VARCHAR(40) NOT NULL,
                denumire VARCHAR(80) NOT NULL,
                produs_id INT UNSIGNED NULL,
                tip_fluid VARCHAR(40) NULL,
                capacitate_nominala DECIMAL(10,3) NULL,
                um VARCHAR(10) NOT NULL DEFAULT 'L',
                interval_km INT UNSIGNED NULL,
                interval_luni SMALLINT UNSIGNED NULL,
                interval_ore INT UNSIGNED NULL,
                sursa_km VARCHAR(10) NOT NULL DEFAULT 'km_can',
                sursa_ore VARCHAR(10) NOT NULL DEFAULT 'none',
                avertizare_procent TINYINT UNSIGNED NOT NULL DEFAULT 90,
                verificare_zile SMALLINT UNSIGNED NULL,
                prag_consum DECIMAL(10,3) NULL,
                critic TINYINT(1) NOT NULL DEFAULT 0,
                circuit_inchis TINYINT(1) NOT NULL DEFAULT 0,
                referinta_data DATETIME NULL,
                referinta_km_can DECIMAL(12,1) NULL,
                referinta_km_gps DECIMAL(12,1) NULL,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_vsf_vehicle_system (vehicle_id, sistem),
                KEY idx_vsf_produs (produs_id),
                CONSTRAINT fk_vsf_produs FOREIGN KEY (produs_id) REFERENCES consumabile_produse (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS vehicule_fluide_evenimente (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sistem_id INT UNSIGNED NOT NULL,
                vehicle_id INT NOT NULL,
                tip VARCHAR(20) NOT NULL,
                efectuat_la DATETIME NOT NULL,
                nivel_status VARCHAR(12) NULL,
                nivel_valoare DECIMAL(10,3) NULL,
                nivel_unitate VARCHAR(10) NULL,
                cantitate DECIMAL(10,3) NULL,
                pana_la_reper TINYINT(1) NOT NULL DEFAULT 0,
                km_can DECIMAL(12,1) NULL,
                km_gps DECIMAL(12,1) NULL,
                km_bord INT UNSIGNED NULL,
                miscare_id BIGINT UNSIGNED NULL,
                observatii VARCHAR(500) NULL,
                anulat_la DATETIME NULL,
                anulat_de INT UNSIGNED NULL,
                motiv_anulare VARCHAR(255) NULL,
                idempotency_key VARCHAR(64) NULL,
                user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_vfe_miscare (miscare_id),
                UNIQUE KEY uq_vfe_key (idempotency_key),
                KEY idx_vfe_sistem (sistem_id, efectuat_la),
                KEY idx_vfe_vehicle (vehicle_id, efectuat_la),
                CONSTRAINT fk_vfe_sistem FOREIGN KEY (sistem_id) REFERENCES vehicule_sisteme_fluide (id),
                CONSTRAINT fk_vfe_miscare FOREIGN KEY (miscare_id) REFERENCES consumabile_miscari (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    // =========================================================================
    // Catalog produse
    // =========================================================================

    /** @return array<int, array<string, mixed>> */
    public function products(bool $activeOnly = false): array
    {
        $this->ensureSchema();
        $rows = $this->db->query(
            'SELECT p.*,
                    (SELECT COALESCE(SUM(r.cantitate), 0) FROM consumabile_recipiente r WHERE r.produs_id = p.id AND r.status <> \'inactiv\') AS stoc_total,
                    (SELECT COUNT(*) FROM consumabile_recipiente r WHERE r.produs_id = p.id AND r.status <> \'inactiv\') AS recipiente
             FROM consumabile_produse p'
            . ($activeOnly ? ' WHERE p.activ = 1' : '')
            . ' ORDER BY p.activ DESC, p.denumire'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $components = $this->catalogComponents();
        $result = [];
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['component'] = $row['component_key'] !== null ? ($components[(string) $row['component_key']] ?? null) : null;
            $row['tipuri_vehicul_list'] = self::csv((string) ($row['tipuri_vehicul'] ?? ''));
            $row['vehicule_list'] = array_map('intval', self::csv((string) ($row['vehicule_ids'] ?? '')));
            $row['sisteme_list'] = self::productSystemKeys($row['sistem_mecanic'] ?? null);
            $row['sistem_label'] = self::productSystemLabel($row['sistem_mecanic'] ?? null);
            $row['piese_list'] = self::csv((string) ($row['componente_vehicul'] ?? ''));
            $row['piese_nume'] = array_values(array_filter(array_map(static fn (string $key): string => str_starts_with($key, 'cat:')
                ? substr($key, 4) . ' (toată categoria)'
                : (string) ($components[$key]['name'] ?? ''), $row['piese_list'])));
            $row['compatibile'] = [];
            $result[$row['id']] = $row;
        }
        foreach ($this->db->query('SELECT produs_a, produs_b FROM consumabile_compatibilitate')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pair) {
            $a = (int) $pair['produs_a'];
            $b = (int) $pair['produs_b'];
            if (isset($result[$a], $result[$b])) {
                $result[$a]['compatibile'][] = $b;
                $result[$b]['compatibile'][] = $a;
            }
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function product(int $id): ?array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('SELECT * FROM consumabile_produse WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /** @param array<string, mixed> $data */
    public function saveProduct(array $data, ?int $id, ?int $userId): int
    {
        $this->ensureSchema();
        $name = $this->text($data['denumire'] ?? '', 190);
        if ($name === null || mb_strlen($name) < 2) {
            throw new InvalidArgumentException('Scrie denumirea produsului.');
        }
        $unit = (string) ($data['um'] ?? 'L');
        if (!isset(self::UNITS[$unit])) {
            throw new InvalidArgumentException('Unitate de măsură necunoscută.');
        }
        $kind = (string) ($data['tip_articol'] ?? 'fluid');
        if (!isset(self::PRODUCT_KINDS[$kind])) {
            $kind = 'fluid';
        }
        $fluidType = (string) ($data['tip_fluid'] ?? '');
        $fluidType = isset(self::FLUID_TYPES[$fluidType]) ? $fluidType : null;
        $componentKey = trim((string) ($data['component_key'] ?? ''));
        $componentKey = $componentKey !== '' ? $componentKey : null;
        if (!array_key_exists('component_key', $data) && $id !== null) {
            // Campul nu mai e in formular: legatura veche (identitate de catalog) ramane neatinsa.
            $componentKey = ($this->product($id)['component_key'] ?? null) ?: null;
        } elseif ($componentKey !== null) {
            $components = $this->catalogComponents();
            if (!isset($components[$componentKey])) {
                throw new InvalidArgumentException('Componenta aleasă nu există în Catalog componente.');
            }
            $stmt = $this->db->prepare('SELECT id FROM consumabile_produse WHERE component_key = :k AND id <> :id');
            $stmt->execute([':k' => $componentKey, ':id' => (int) $id]);
            if ($stmt->fetchColumn() !== false) {
                throw new InvalidArgumentException('Componenta e deja legată de alt produs (o singură identitate de catalog per produs).');
            }
        }
        $allParts = (new AutoComponentCatalogService($this->db))->components();
        // O intrare e fie o piesa (cheia din catalog), fie o categorie intreaga ("cat:Motor" = toate piesele din Motor,
        // inclusiv cele adaugate ulterior); piesele dintr-o categorie bifata intreaga nu se mai pastreaza separat.
        $allCategories = [];
        foreach ($allParts as $component) {
            $allCategories[(string) ($component['category'] ?? '')] = true;
        }
        $parts = array_values(array_unique(array_filter(array_map('strval', (array) ($data['componente_vehicul'] ?? [])),
            static fn (string $key): bool => str_starts_with($key, 'cat:') ? isset($allCategories[substr($key, 4)]) : isset($allParts[$key]))));
        $wholeCategories = array_map(static fn (string $key): string => substr($key, 4), array_filter($parts, static fn (string $key): bool => str_starts_with($key, 'cat:')));
        $parts = array_values(array_filter($parts, static fn (string $key): bool => str_starts_with($key, 'cat:')
            || !in_array((string) ($allParts[$key]['category'] ?? ''), $wholeCategories, true)));
        $vehicleTypes = array_values(array_intersect(['camion', 'cap_tractor', 'semiremorca'], array_map('strval', (array) ($data['tipuri_vehicul'] ?? []))));
        // Compatibilitatea e fie pe tip de vehicul, fie pe numere de inmatriculare (alese direct sau pe categoria de capacitate).
        $vehicleIds = [];
        if ((string) ($data['compat_mode'] ?? '') === 'vehicule') {
            $vehicleTypes = [];
            $wanted = array_values(array_unique(array_filter(array_map('intval', (array) ($data['vehicule_ids'] ?? [])), static fn (int $v): bool => $v > 0)));
            if ($wanted !== []) {
                $known = array_map('intval', $this->db->query('SELECT id FROM vehicule WHERE id IN (' . implode(',', $wanted) . ')')->fetchAll(PDO::FETCH_COLUMN) ?: []);
                $vehicleIds = array_values(array_intersect($wanted, $known));
                sort($vehicleIds);
            }
        }
        $sources = array_values(array_intersect(array_keys(self::MONITORING_SOURCES), array_map('strval', (array) ($data['sursa_monitorizare'] ?? []))));

        if ($id !== null) {
            $current = $this->product($id);
            if ($current === null) {
                throw new InvalidArgumentException('Produsul nu mai există.');
            }
            if ((string) $current['um'] !== $unit) {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM consumabile_recipiente WHERE produs_id = :id AND cantitate > 0');
                $stmt->execute([':id' => $id]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new InvalidArgumentException('Unitatea de măsură nu se poate schimba cât timp produsul e în recipiente cu stoc.');
                }
            }
        }

        $values = [
            ':component' => $componentKey,
            ':denumire' => $name,
            ':producator' => $this->text($data['producator'] ?? '', 120),
            ':cod' => $this->text($data['cod_produs'] ?? '', 80),
            ':tip_articol' => $kind,
            ':tip_fluid' => $fluidType,
            ':vascozitate' => $this->text($data['vascozitate'] ?? '', 40),
            ':specificatie' => $this->text($data['specificatie'] ?? '', 255),
            ':aprobari' => $this->text($data['aprobari'] ?? '', 4000),
            ':um' => $unit,
            ':sistem' => self::systemForFluid($fluidType),
            ':piese' => $parts !== [] ? implode(',', $parts) : null,
            ':tipuri' => $vehicleTypes !== [] ? implode(',', $vehicleTypes) : null,
            ':vehicule_ids' => $vehicleIds !== [] ? implode(',', $vehicleIds) : null,
            ':compat_note' => $this->text($data['compatibilitate_note'] ?? '', 4000),
            ':interval_km' => $this->positiveInt($data['interval_km'] ?? null),
            ':interval_luni' => $this->positiveInt($data['interval_luni'] ?? null),
            ':interval_ore' => $this->positiveInt($data['interval_ore'] ?? null),
            ':sursa' => $sources !== [] ? implode(',', $sources) : null,
            ':prag' => $this->optionalQuantity($data['prag_alerta'] ?? null),
            ':critic' => !empty($data['critic']) ? 1 : 0,
            ':circuit' => !empty($data['circuit_inchis']) ? 1 : 0,
            ':retur' => !empty($data['permite_retur']) ? 1 : 0,
            ':activ' => array_key_exists('activ', $data) ? (!empty($data['activ']) ? 1 : 0) : 1,
            ':now' => date('Y-m-d H:i:s'),
        ];
        if ($id === null) {
            $this->db->prepare(
                'INSERT INTO consumabile_produse
                    (component_key, denumire, producator, cod_produs, tip_articol, tip_fluid, vascozitate, specificatie, aprobari, um,
                     sistem_mecanic, componente_vehicul, tipuri_vehicul, vehicule_ids, compatibilitate_note, interval_km, interval_luni, interval_ore, sursa_monitorizare,
                     prag_alerta, critic, circuit_inchis, permite_retur, activ, created_by, created_at, updated_at)
                 VALUES (:component, :denumire, :producator, :cod, :tip_articol, :tip_fluid, :vascozitate, :specificatie, :aprobari, :um,
                     :sistem, :piese, :tipuri, :vehicule_ids, :compat_note, :interval_km, :interval_luni, :interval_ore, :sursa,
                     :prag, :critic, :circuit, :retur, :activ, :user, :now, :now2)'
            )->execute($values + [':user' => $userId, ':now2' => $values[':now']]);

            return (int) $this->db->lastInsertId();
        }
        $this->db->prepare(
            'UPDATE consumabile_produse SET component_key = :component, denumire = :denumire, producator = :producator, cod_produs = :cod,
                tip_articol = :tip_articol, tip_fluid = :tip_fluid, vascozitate = :vascozitate, specificatie = :specificatie,
                aprobari = :aprobari, um = :um, sistem_mecanic = :sistem, componente_vehicul = :piese, tipuri_vehicul = :tipuri, vehicule_ids = :vehicule_ids, compatibilitate_note = :compat_note,
                interval_km = :interval_km, interval_luni = :interval_luni, interval_ore = :interval_ore, sursa_monitorizare = :sursa,
                prag_alerta = :prag, critic = :critic, circuit_inchis = :circuit, permite_retur = :retur, activ = :activ, updated_at = :now
             WHERE id = :id'
        )->execute($values + [':id' => $id]);
        if ((string) ($current['um'] ?? $unit) !== $unit) {
            $this->db->prepare('UPDATE consumabile_recipiente SET um = :um WHERE produs_id = :id')->execute([':um' => $unit, ':id' => $id]);
        }

        return $id;
    }

    /**
     * Stergere definitiva a unui produs NEFOLOSIT. Istoricul miscarilor e imuabil si trimite la produs,
     * deci un produs cu miscari, recipiente sau sisteme de vehicul legate nu se sterge (se dezactiveaza).
     *
     * @return array<string, mixed> randul sters (pentru audit)
     */
    public function deleteProduct(int $id): array
    {
        $this->ensureSchema();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM consumabile_produse WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Produsul nu există.');

            $count = function (string $sql) use ($id): int {
                $q = $this->db->prepare($sql);
                $q->execute([':id' => $id]);

                return (int) $q->fetchColumn();
            };
            $blockers = [];
            if (($n = $count('SELECT COUNT(*) FROM consumabile_miscari WHERE produs_id = :id')) > 0) {
                $blockers[] = $n . ' operațiuni în istoric';
            }
            if (($n = $count('SELECT COUNT(*) FROM consumabile_recipiente WHERE produs_id = :id')) > 0) {
                $blockers[] = $n . ' recipiente (și cele inactive)';
            }
            if (($n = $count('SELECT COUNT(*) FROM vehicule_sisteme_fluide WHERE produs_id = :id')) > 0) {
                $blockers[] = $n . ' sisteme de vehicul';
            }
            if ($blockers !== []) {
                throw new InvalidArgumentException('Produsul nu se poate șterge: e folosit în ' . implode(', ', $blockers)
                    . '. Debifează „Activ” ca să-l scoți din liste.');
            }

            $this->db->prepare('DELETE FROM consumabile_compatibilitate WHERE produs_a = :a OR produs_b = :b')->execute([':a' => $id, ':b' => $id]);
            $this->db->prepare('DELETE FROM consumabile_produse WHERE id = :id')->execute([':id' => $id]);
            $this->db->commit();

            return $product;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Stergere definitiva a unui recipient care n-a avut nicio miscare (nici cantitate initiala).
     *
     * @return array<string, mixed> randul sters (pentru audit)
     */
    public function deleteContainer(int $id): array
    {
        $this->ensureSchema();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM consumabile_recipiente WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $container = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new InvalidArgumentException('Recipientul nu există.');

            $q = $this->db->prepare('SELECT COUNT(*) FROM consumabile_miscari WHERE recipient_sursa_id = :s OR recipient_dest_id = :d');
            $q->execute([':s' => $id, ':d' => $id]);
            $moves = (int) $q->fetchColumn();
            if ($moves > 0 || (float) $container['cantitate'] > 0) {
                throw new InvalidArgumentException('Recipientul nu se poate șterge: are ' . $moves
                    . ' operațiuni în istoric. Pune statusul „Inactiv” ca să-l scoți din liste.');
            }

            $this->db->prepare('DELETE FROM consumabile_recipiente WHERE id = :id')->execute([':id' => $id]);
            $this->db->commit();

            return $container;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** Compatibilitate declarata explicit (amestec permis in acelasi recipient). */
    public function setCompatible(int $productA, int $productB, bool $compatible, ?string $note, ?int $userId): void
    {
        $this->ensureSchema();
        if ($productA === $productB) {
            throw new InvalidArgumentException('Alege două produse diferite.');
        }
        $a = $this->product($productA);
        $b = $this->product($productB);
        if ($a === null || $b === null) {
            throw new InvalidArgumentException('Produs inexistent.');
        }
        if ($compatible && (string) $a['um'] !== (string) $b['um']) {
            throw new InvalidArgumentException('Produsele au unități de măsură diferite.');
        }
        [$low, $high] = $productA < $productB ? [$productA, $productB] : [$productB, $productA];
        if ($compatible) {
            $this->db->prepare(
                'INSERT INTO consumabile_compatibilitate (produs_a, produs_b, note, created_by, created_at) VALUES (:a, :b, :n, :u, NOW())
                 ON DUPLICATE KEY UPDATE note = VALUES(note)'
            )->execute([':a' => $low, ':b' => $high, ':n' => $this->text($note ?? '', 255), ':u' => $userId]);
        } else {
            $this->db->prepare('DELETE FROM consumabile_compatibilitate WHERE produs_a = :a AND produs_b = :b')->execute([':a' => $low, ':b' => $high]);
        }
    }

    public function areCompatible(int $productA, int $productB): bool
    {
        if ($productA === $productB) {
            return true;
        }
        [$low, $high] = $productA < $productB ? [$productA, $productB] : [$productB, $productA];
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM consumabile_compatibilitate WHERE produs_a = :a AND produs_b = :b');
        $stmt->execute([':a' => $low, ':b' => $high]);

        return (int) $stmt->fetchColumn() > 0;
    }

    // =========================================================================
    // Recipiente
    // =========================================================================

    /**
     * @param array<string, mixed> $filters produs_id, tip_fluid, status, search
     * @return array<int, array<string, mixed>>
     */
    public function containers(array $filters = []): array
    {
        $this->ensureSchema();
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['produs_id'])) {
            $where[] = 'r.produs_id = :produs';
            $params[':produs'] = (int) $filters['produs_id'];
        }
        if (!empty($filters['tip_fluid'])) {
            $where[] = 'p.tip_fluid = :tip_fluid';
            $params[':tip_fluid'] = (string) $filters['tip_fluid'];
        }
        if (empty($filters['include_inactive'])) {
            $where[] = "r.status <> 'inactiv'";
        }
        $rows = $this->db->prepare(
            'SELECT r.*, p.denumire AS produs, p.tip_fluid, p.vascozitate, p.specificatie, p.tip_articol AS produs_tip,
                    (SELECT MAX(m.efectuat_la) FROM consumabile_miscari m WHERE m.recipient_sursa_id = r.id OR m.recipient_dest_id = r.id) AS ultima_miscare
             FROM consumabile_recipiente r
             LEFT JOIN consumabile_produse p ON p.id = r.produs_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY r.status = \'inactiv\', p.denumire, r.cod'
        );
        $rows->execute($params);
        $result = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[] = $this->decorateContainer($row);
        }
        if (!empty($filters['state'])) {
            $result = array_values(array_filter($result, static fn (array $row): bool => $row['stare'] === $filters['state']));
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function container(int $id): ?array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare(
            'SELECT r.*, p.denumire AS produs, p.tip_fluid, p.vascozitate, p.specificatie, p.tip_articol AS produs_tip
             FROM consumabile_recipiente r LEFT JOIN consumabile_produse p ON p.id = r.produs_id WHERE r.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->decorateContainer($row) : null;
    }

    /**
     * Creare / editare recipient. Cantitatea NU se editeaza aici: la creare, cantitatea initiala
     * devine o miscare „Intrare in stoc” (aceeasi tranzactie); apoi doar prin operatiuni.
     *
     * @param array<string, mixed> $data
     */
    public function saveContainer(array $data, ?int $id, ?int $userId): int
    {
        $this->ensureSchema();
        $type = (string) ($data['tip'] ?? '');
        if (!isset(self::CONTAINER_TYPES[$type])) {
            throw new InvalidArgumentException('Alege tipul recipientului.');
        }
        $capacity = $this->milli($data['capacitate'] ?? null);
        if ($capacity <= 0) {
            throw new InvalidArgumentException('Capacitatea nominală trebuie să fie mai mare decât 0.');
        }
        $status = (string) ($data['status'] ?? 'activ');
        if (!isset(self::CONTAINER_STATUSES[$status])) {
            $status = 'activ';
        }
        $threshold = $this->optionalMilli($data['prag_minim'] ?? null);
        if ($threshold !== null && $threshold > $capacity) {
            throw new InvalidArgumentException('Pragul minim nu poate depăși capacitatea.');
        }
        $productId = (int) ($data['produs_id'] ?? 0);
        $product = $productId > 0 ? $this->product($productId) : null;
        if ($productId > 0 && $product === null) {
            throw new InvalidArgumentException('Produsul ales nu există.');
        }
        $expiry = $this->optionalDate($data['data_expirare'] ?? null);
        $code = strtoupper(trim((string) ($data['cod'] ?? '')));
        if ($code !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,39}$/', $code)) {
            throw new InvalidArgumentException('Identificatorul poate conține doar litere, cifre și . _ / -');
        }
        $fields = [
            ':tip' => $type,
            ':tip_pers' => $type === 'altul' ? $this->text($data['tip_personalizat'] ?? '', 60) : null,
            ':capacitate' => $this->fromMilli($capacity),
            ':prag' => $threshold !== null ? $this->fromMilli($threshold) : null,
            ':locatie' => $this->text($data['locatie'] ?? '', 120),
            ':status' => $status,
            ':lot' => $this->text($data['lot'] ?? '', 60),
            ':expirare' => $expiry,
            ':obs' => $this->text($data['observatii'] ?? '', 2000),
            ':now' => date('Y-m-d H:i:s'),
        ];

        if ($id !== null) {
            return $this->transaction(function () use ($id, $fields, $capacity, $product, $productId, $code): int {
                $current = $this->lockContainers([$id])[$id] ?? null;
                if ($current === null) {
                    throw new InvalidArgumentException('Recipientul nu mai există.');
                }
                $quantity = $this->milli($current['cantitate']);
                if ($capacity < $quantity) {
                    throw new InvalidArgumentException('Capacitatea nu poate fi mai mică decât cantitatea curentă (' . $this->display($quantity) . ').');
                }
                $currentProduct = (int) ($current['produs_id'] ?? 0);
                if ($productId !== $currentProduct && $quantity > 0) {
                    throw new InvalidArgumentException('Produsul se poate schimba doar când recipientul e gol.');
                }
                $set = 'tip = :tip, tip_personalizat = :tip_pers, capacitate = :capacitate, prag_minim = :prag, locatie = :locatie,
                        status = :status, lot = :lot, data_expirare = :expirare, observatii = :obs, updated_at = :now,
                        produs_id = :produs, um = :um';
                $params = $fields + [':produs' => $productId > 0 ? $productId : null, ':um' => $product['um'] ?? null, ':id' => $id];
                if ($code !== '' && $code !== (string) $current['cod']) {
                    $this->assertUniqueCode($code, $id);
                    $set .= ', cod = :cod';
                    $params[':cod'] = $code;
                }
                $this->db->prepare('UPDATE consumabile_recipiente SET ' . $set . ' WHERE id = :id')->execute($params);

                return $id;
            });
        }

        $initial = $this->optionalMilli($data['cantitate_initiala'] ?? null) ?? 0;
        if ($initial > 0 && $product === null) {
            throw new InvalidArgumentException('Alege produsul pentru cantitatea inițială.');
        }
        if ($initial > $capacity) {
            throw new InvalidArgumentException('Cantitatea inițială depășește capacitatea recipientului.');
        }
        $key = $initial > 0 ? $this->cleanKey((string) ($data['idempotency_key'] ?? '')) : null;
        if ($key !== null && ($existing = $this->movementByKey($key)) !== null) {
            return (int) ($existing['recipient_dest_id'] ?? 0); // retrimitere: recipientul exista deja
        }

        return $this->transaction(function () use ($fields, $code, $type, $product, $productId, $initial, $key, $data, $userId): int {
            $finalCode = $code !== '' ? $code : $this->nextCode($type);
            $this->assertUniqueCode($finalCode, null);
            $this->db->prepare(
                'INSERT INTO consumabile_recipiente
                    (cod, tip, tip_personalizat, produs_id, um, capacitate, cantitate, prag_minim, locatie, status, lot, data_expirare, observatii, created_by, created_at, updated_at)
                 VALUES (:cod, :tip, :tip_pers, :produs, :um, :capacitate, 0, :prag, :locatie, :status, :lot, :expirare, :obs, :user, :now, :now2)'
            )->execute($fields + [
                ':cod' => $finalCode, ':produs' => $productId > 0 ? $productId : null, ':um' => $product['um'] ?? null,
                ':user' => $userId, ':now2' => $fields[':now'],
            ]);
            $containerId = (int) $this->db->lastInsertId();
            if ($initial > 0 && $key !== null) {
                $this->db->prepare('UPDATE consumabile_recipiente SET cantitate = :q WHERE id = :id')
                    ->execute([':q' => $this->fromMilli($initial), ':id' => $containerId]);
                $this->insertMovement([
                    'tip' => 'intrare', 'produs_id' => $productId, 'cantitate' => $initial, 'um' => (string) $product['um'],
                    'recipient_dest_id' => $containerId, 'dest_inainte' => 0, 'dest_dupa' => $initial,
                    'furnizor' => $data['furnizor'] ?? null, 'document' => $data['document'] ?? null,
                    'lot' => $data['lot'] ?? null, 'data_expirare' => $this->optionalDate($data['data_expirare'] ?? null),
                    'motiv' => 'Cantitate inițială la înregistrarea recipientului',
                    'idempotency_key' => $key, 'efectuat_la' => date('Y-m-d H:i:s'), 'user_id' => $userId,
                ]);
            }

            return $containerId;
        });
    }

    // =========================================================================
    // Operatiuni de stoc
    // =========================================================================

    /**
     * Punctul unic de intrare pentru operatiuni (validate pe server, atomice, idempotente).
     *
     * @param array<string, mixed> $data
     * @return array{movement: array<string, mixed>, duplicate: bool}
     */
    public function operate(string $type, array $data, ?int $userId): array
    {
        $this->ensureSchema();
        $key = $this->cleanKey((string) ($data['idempotency_key'] ?? ''));
        $existing = $this->movementByKey($key);
        if ($existing !== null) {
            return ['movement' => $existing, 'duplicate' => true];
        }
        $at = $this->operationTime($data['efectuat_la'] ?? null);
        $common = [
            'idempotency_key' => $key,
            'efectuat_la' => $at,
            'user_id' => $userId,
            'motiv' => $data['motiv'] ?? null,
            'observatii' => $data['observatii'] ?? null,
        ];

        try {
            $movementId = $this->transaction(fn (): int => match ($type) {
                'intrare' => $this->doReceive($data, $common),
                'transfer' => $this->doTransfer($data, $common),
                'completare', 'schimb_complet' => $this->doDispense($type, $data, $common),
                'retur' => $this->doReturn($data, $common),
                'pierdere' => $this->doLoss($data, $common),
                'ajustare' => $this->doAdjust($data, $common),
                'stornare' => $this->doReverse($data, $common),
                default => throw new InvalidArgumentException('Operațiune necunoscută.'),
            });
        } catch (PDOException $exception) {
            // Aceeasi cheie trimisa simultan: a doua cerere loveste UNIQUE → intoarcem inregistrarea existenta.
            if ((string) $exception->getCode() === '23000' && ($existing = $this->movementByKey($key)) !== null) {
                return ['movement' => $existing, 'duplicate' => true];
            }
            throw $exception;
        }

        return ['movement' => $this->movement($movementId) ?? [], 'duplicate' => false];
    }

    /** @param array<string, mixed> $common */
    private function doReceive(array $data, array $common): int
    {
        $destId = (int) ($data['recipient_dest_id'] ?? 0);
        $quantity = $this->requiredQuantity($data['cantitate'] ?? null);
        $dest = $this->lockContainers([$destId])[$destId] ?? throw new InvalidArgumentException('Alege recipientul destinație.');
        $this->assertUsable($dest);
        $productId = (int) ($data['produs_id'] ?? 0) ?: (int) ($dest['produs_id'] ?? 0);
        $product = $productId > 0 ? $this->product($productId) : null;
        if ($product === null) {
            throw new InvalidArgumentException('Alege produsul recepționat.');
        }
        [$before, $after] = $this->acceptInto($dest, $product, $quantity);
        $lot = $this->text($data['lot'] ?? '', 60);
        $expiry = $this->optionalDate($data['data_expirare'] ?? null);
        $this->writeContainer($destId, $after, $product, $before === 0 ? ['lot' => $lot, 'data_expirare' => $expiry] : $this->mergeLot($dest, $lot, $expiry));

        return $this->insertMovement($common + [
            'tip' => 'intrare', 'produs_id' => (int) $product['id'], 'cantitate' => $quantity, 'um' => (string) $product['um'],
            'recipient_dest_id' => $destId, 'dest_inainte' => $before, 'dest_dupa' => $after,
            'furnizor' => $data['furnizor'] ?? null, 'document' => $data['document'] ?? null,
            'pret_unitar' => $data['pret_unitar'] ?? null, 'lot' => $lot, 'data_expirare' => $expiry,
        ]);
    }

    /** @param array<string, mixed> $common */
    private function doTransfer(array $data, array $common): int
    {
        $sourceId = (int) ($data['recipient_sursa_id'] ?? 0);
        $destId = (int) ($data['recipient_dest_id'] ?? 0);
        if ($sourceId <= 0 || $destId <= 0) {
            throw new InvalidArgumentException('Alege recipientul sursă și recipientul destinație.');
        }
        if ($sourceId === $destId) {
            throw new InvalidArgumentException('Sursa și destinația trebuie să fie recipiente diferite.');
        }
        $quantity = $this->requiredQuantity($data['cantitate'] ?? null);
        $locked = $this->lockContainers([$sourceId, $destId]);
        $source = $locked[$sourceId] ?? throw new InvalidArgumentException('Recipientul sursă nu mai există.');
        $dest = $locked[$destId] ?? throw new InvalidArgumentException('Recipientul destinație nu mai există.');
        $this->assertUsable($source);
        $this->assertUsable($dest);
        $product = $this->containerProduct($source);
        [$sourceBefore, $sourceAfter] = $this->takeFrom($source, $quantity);
        [$destBefore, $destAfter] = $this->acceptInto($dest, $product, $quantity);

        $this->writeContainer($sourceId, $sourceAfter, null, []);
        $this->writeContainer($destId, $destAfter, $product, $destBefore === 0
            ? ['lot' => $source['lot'], 'data_expirare' => $source['data_expirare']]
            : $this->mergeLot($dest, $source['lot'], $source['data_expirare']));

        return $this->insertMovement($common + [
            'tip' => 'transfer', 'produs_id' => (int) $product['id'], 'cantitate' => $quantity, 'um' => (string) $product['um'],
            'recipient_sursa_id' => $sourceId, 'sursa_inainte' => $sourceBefore, 'sursa_dupa' => $sourceAfter,
            'recipient_dest_id' => $destId, 'dest_inainte' => $destBefore, 'dest_dupa' => $destAfter,
            'lot' => $source['lot'], 'data_expirare' => $source['data_expirare'],
        ]);
    }

    /** Completare / schimb complet: singurele miscari care inregistreaza consum pe vehicul. */
    private function doDispense(string $type, array $data, array $common): int
    {
        $sourceId = (int) ($data['recipient_sursa_id'] ?? 0);
        $vehicleId = (int) ($data['vehicul_id'] ?? 0);
        $quantity = $this->requiredQuantity($data['cantitate'] ?? null);
        $vehicle = $this->vehicle($vehicleId) ?? throw new InvalidArgumentException('Alege vehiculul.');
        $source = $this->lockContainers([$sourceId])[$sourceId] ?? throw new InvalidArgumentException('Alege recipientul din care se ia fluidul.');
        $this->assertUsable($source);
        if (!empty($source['data_expirare']) && (string) $source['data_expirare'] < date('Y-m-d')) {
            throw new InvalidArgumentException('Lotul din ' . $source['cod'] . ' a expirat la ' . date('d.m.Y', (int) strtotime((string) $source['data_expirare'])) . '.');
        }
        $product = $this->containerProduct($source);
        $vehicleType = $this->autoVehicleType((string) ($vehicle['tip_vehicul'] ?? ''));
        $allowedTypes = self::csv((string) ($product['tipuri_vehicul'] ?? ''));
        $allowedVehicles = array_map('intval', self::csv((string) ($product['vehicule_ids'] ?? '')));
        if ($allowedVehicles !== [] && !in_array($vehicleId, $allowedVehicles, true)) {
            throw new InvalidArgumentException('„' . $product['denumire'] . '” nu este marcat compatibil cu vehiculul ' . $vehicle['nr_inmatriculare'] . '.');
        }
        if ($allowedTypes !== [] && $vehicleType !== '' && !in_array($vehicleType, $allowedTypes, true)) {
            throw new InvalidArgumentException('„' . $product['denumire'] . '” nu este marcat compatibil cu tipul vehiculului ' . $vehicle['nr_inmatriculare'] . '.');
        }
        $systemId = (int) ($data['sistem_id'] ?? 0);
        $system = null;
        if ($systemId > 0) {
            $system = $this->vehicleSystem($systemId, $vehicleId);
            $systemProduct = (int) ($system['produs_id'] ?? 0);
            if ($systemProduct > 0 && !$this->areCompatible($systemProduct, (int) $product['id'])) {
                throw new InvalidArgumentException('„' . $product['denumire'] . '” nu este produsul configurat pentru sistemul „' . $system['denumire']
                    . '” și nici declarat compatibil cu acesta.');
            }
        }
        [$before, $after] = $this->takeFrom($source, $quantity);
        $this->writeContainer($sourceId, $after, null, []);
        $drained = $type === 'schimb_complet' ? $this->optionalMilli($data['cantitate_scoasa'] ?? null) : null;

        $movementId = $this->insertMovement($common + [
            'tip' => $type, 'produs_id' => (int) $product['id'], 'cantitate' => $quantity, 'um' => (string) $product['um'],
            'recipient_sursa_id' => $sourceId, 'sursa_inainte' => $before, 'sursa_dupa' => $after,
            'vehicul_id' => $vehicleId, 'km_vehicul' => $this->positiveInt($data['km_vehicul'] ?? null),
            'sistem' => $system['denumire'] ?? ($data['sistem'] ?? (self::productSystemLabel($product['sistem_mecanic'] ?? null) ?: null)), 'cantitate_scoasa' => $drained,
            'lot' => $source['lot'], 'data_expirare' => $source['data_expirare'],
        ]);
        if ($system !== null) {
            // Evenimentul pe sistemul vehiculului, in aceeasi tranzactie (km la momentul operatiunii).
            (new VehicleFluidService($this->db))->insertEvent($system, [
                'tip' => $type,
                'efectuat_la' => $common['efectuat_la'],
                'cantitate' => $this->fromMilli($quantity),
                'pana_la_reper' => $type === 'schimb_complet' || !empty($data['pana_la_reper']),
                'km_bord' => $data['km_vehicul'] ?? null,
                'miscare_id' => $movementId,
                'observatii' => $common['observatii'] ?? null,
                'user_id' => $common['user_id'],
            ]);
        }

        return $movementId;
    }

    /** @return array<string, mixed> sistemul de fluid al vehiculului (blocat pentru actualizare) */
    private function vehicleSystem(int $systemId, int $vehicleId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM vehicule_sisteme_fluide WHERE id = :id AND activ = 1 FOR UPDATE');
        $stmt->execute([':id' => $systemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['vehicle_id'] !== $vehicleId) {
            throw new InvalidArgumentException('Sistemul ales nu aparține vehiculului.');
        }

        return $row;
    }

    /** Retur: doar pentru produse care il permit si doar cu referinta la iesirea spre vehicul (trasabil). */
    private function doReturn(array $data, array $common): int
    {
        $referenceId = (int) ($data['referinta_id'] ?? 0);
        $destId = (int) ($data['recipient_dest_id'] ?? 0);
        $quantity = $this->requiredQuantity($data['cantitate'] ?? null);
        $this->requireReason($common['motiv']);
        $reference = $this->lockMovement($referenceId);
        if ($reference === null || !in_array((string) $reference['tip'], self::CONSUMPTION_TYPES, true)) {
            throw new InvalidArgumentException('Returul trebuie legat de o completare / un schimb pe vehicul.');
        }
        if (!empty($reference['stornata_de_id'])) {
            throw new InvalidArgumentException('Operațiunea de referință a fost stornată.');
        }
        $product = $this->product((int) $reference['produs_id']) ?? throw new InvalidArgumentException('Produs inexistent.');
        if ((int) $product['permite_retur'] !== 1) {
            throw new InvalidArgumentException('Produsul „' . $product['denumire'] . '” nu permite retur în stoc (setarea din fișa produsului).');
        }
        $returnable = $this->milli($reference['cantitate']) - $this->returnedFor($referenceId);
        if ($quantity > $returnable) {
            throw new InvalidArgumentException('Se pot returna cel mult ' . $this->display($returnable, (string) $product['um']) . ' din această operațiune.');
        }
        $dest = $this->lockContainers([$destId])[$destId] ?? throw new InvalidArgumentException('Alege recipientul în care se returnează.');
        $this->assertUsable($dest);
        [$before, $after] = $this->acceptInto($dest, $product, $quantity);
        $this->writeContainer($destId, $after, $product, $before === 0 ? ['lot' => $reference['lot'], 'data_expirare' => $reference['data_expirare']] : []);

        return $this->insertMovement($common + [
            'tip' => 'retur', 'produs_id' => (int) $product['id'], 'cantitate' => $quantity, 'um' => (string) $product['um'],
            'recipient_dest_id' => $destId, 'dest_inainte' => $before, 'dest_dupa' => $after,
            'vehicul_id' => $reference['vehicul_id'], 'referinta_id' => $referenceId,
        ]);
    }

    private function doLoss(array $data, array $common): int
    {
        $sourceId = (int) ($data['recipient_sursa_id'] ?? 0);
        $quantity = $this->requiredQuantity($data['cantitate'] ?? null);
        $this->requireReason($common['motiv']);
        $source = $this->lockContainers([$sourceId])[$sourceId] ?? throw new InvalidArgumentException('Alege recipientul.');
        $product = $this->containerProduct($source);
        [$before, $after] = $this->takeFrom($source, $quantity);
        $this->writeContainer($sourceId, $after, null, []);

        return $this->insertMovement($common + [
            'tip' => 'pierdere', 'produs_id' => (int) $product['id'], 'cantitate' => $quantity, 'um' => (string) $product['um'],
            'recipient_sursa_id' => $sourceId, 'sursa_inainte' => $before, 'sursa_dupa' => $after,
        ]);
    }

    /** Ajustare inventar: cantitatea numarata fizic; diferenta devine miscare (+ in destinatie / - din sursa). */
    private function doAdjust(array $data, array $common): int
    {
        $containerId = (int) ($data['recipient_id'] ?? 0);
        $counted = $this->milli($data['cantitate_numarata'] ?? null, true);
        $this->requireReason($common['motiv']);
        $container = $this->lockContainers([$containerId])[$containerId] ?? throw new InvalidArgumentException('Alege recipientul.');
        $product = $this->containerProduct($container);
        $current = $this->milli($container['cantitate']);
        $capacity = $this->milli($container['capacitate']);
        if ($counted > $capacity) {
            throw new InvalidArgumentException('Cantitatea numărată depășește capacitatea recipientului (' . $this->display($capacity, (string) $product['um']) . ').');
        }
        $delta = $counted - $current;
        if ($delta === 0) {
            throw new InvalidArgumentException('Cantitatea numărată este egală cu stocul din aplicație: nu e nimic de ajustat.');
        }
        $this->writeContainer($containerId, $counted, null, []);
        $fields = ['tip' => 'ajustare', 'produs_id' => (int) $product['id'], 'cantitate' => abs($delta), 'um' => (string) $product['um']];
        $fields += $delta > 0
            ? ['recipient_dest_id' => $containerId, 'dest_inainte' => $current, 'dest_dupa' => $counted]
            : ['recipient_sursa_id' => $containerId, 'sursa_inainte' => $current, 'sursa_dupa' => $counted];

        return $this->insertMovement($common + $fields);
    }

    /**
     * Stornare: miscarea originala ramane; se scrie miscarea inversa si originalul primeste
     * stornata_de_id. Nu se storneaza o stornare si nici o iesire spre vehicul care are retururi active.
     */
    private function doReverse(array $data, array $common): int
    {
        $originalId = (int) ($data['referinta_id'] ?? 0);
        $this->requireReason($common['motiv']);
        $original = $this->lockMovement($originalId) ?? throw new InvalidArgumentException('Operațiunea nu mai există.');
        if ((string) $original['tip'] === 'stornare') {
            throw new InvalidArgumentException('O stornare nu se stornează; înregistrează o operațiune nouă.');
        }
        if (!empty($original['stornata_de_id'])) {
            throw new InvalidArgumentException('Operațiunea a fost deja stornată.');
        }
        if (in_array((string) $original['tip'], self::CONSUMPTION_TYPES, true) && $this->returnedFor($originalId) > 0) {
            throw new InvalidArgumentException('Stornează întâi retururile legate de această operațiune.');
        }
        $quantity = $this->milli($original['cantitate']);
        $product = $this->product((int) $original['produs_id']) ?? throw new InvalidArgumentException('Produs inexistent.');
        // Inversul: ce a intrat intr-un recipient iese, ce a iesit intra inapoi.
        $giveBackTo = !empty($original['recipient_sursa_id']) ? (int) $original['recipient_sursa_id'] : null;
        $takeBackFrom = !empty($original['recipient_dest_id']) ? (int) $original['recipient_dest_id'] : null;
        $locked = $this->lockContainers(array_values(array_filter([$giveBackTo, $takeBackFrom])));
        $fields = ['tip' => 'stornare', 'produs_id' => (int) $product['id'], 'cantitate' => $quantity, 'um' => (string) $product['um'],
            'referinta_id' => $originalId, 'vehicul_id' => $original['vehicul_id']];
        if ($takeBackFrom !== null) {
            $container = $locked[$takeBackFrom] ?? throw new InvalidArgumentException('Recipientul nu mai există.');
            [$before, $after] = $this->takeFrom($container, $quantity, 'pentru stornare');
            $this->writeContainer($takeBackFrom, $after, null, []);
            $fields += ['recipient_sursa_id' => $takeBackFrom, 'sursa_inainte' => $before, 'sursa_dupa' => $after];
        }
        if ($giveBackTo !== null) {
            $container = $locked[$giveBackTo] ?? throw new InvalidArgumentException('Recipientul nu mai există.');
            [$before, $after] = $this->acceptInto($container, $product, $quantity);
            $this->writeContainer($giveBackTo, $after, $product, []);
            $fields += ['recipient_dest_id' => $giveBackTo, 'dest_inainte' => $before, 'dest_dupa' => $after];
        }
        $reversalId = $this->insertMovement($common + $fields);
        $this->db->prepare('UPDATE consumabile_miscari SET stornata_de_id = :s WHERE id = :id AND stornata_de_id IS NULL')
            ->execute([':s' => $reversalId, ':id' => $originalId]);
        // Evenimentul de pe sistemul vehiculului (completare / schimb) se anuleaza odata cu miscarea.
        $this->db->prepare('UPDATE vehicule_fluide_evenimente SET anulat_la = NOW(), anulat_de = :u, motiv_anulare = :m WHERE miscare_id = :id AND anulat_la IS NULL')
            ->execute([':u' => $common['user_id'], ':m' => mb_substr('Stornare: ' . trim((string) $common['motiv']), 0, 255), ':id' => $originalId]);

        return $reversalId;
    }

    // =========================================================================
    // Citiri pentru pagina
    // =========================================================================

    /** @return array<string, mixed>|null */
    public function movement(int $id): ?array
    {
        $stmt = $this->db->prepare($this->movementSelect() . ' WHERE m.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->decorateMovement($row) : null;
    }

    /**
     * Istoric paginat (nu se incarca tot jurnalul la deschiderea tabului).
     *
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function movements(array $filters, int $page = 1, int $perPage = 25): array
    {
        $this->ensureSchema();
        [$where, $params] = $this->movementWhere($filters);
        $count = $this->db->prepare('SELECT COUNT(*) FROM consumabile_miscari m WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $perPage = max(10, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $stmt = $this->db->prepare($this->movementSelect() . ' WHERE ' . $where . ' ORDER BY m.efectuat_la DESC, m.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
        $stmt->execute($params);

        return [
            'rows' => array_map(fn (array $row): array => $this->decorateMovement($row), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /** Ultimele iesiri spre vehicule (pentru selectorul de retur / stornare). */
    public function recentDispenses(int $limit = 60): array
    {
        $this->ensureSchema();
        $stmt = $this->db->query($this->movementSelect() . " WHERE m.tip IN ('completare', 'schimb_complet') AND m.stornata_de_id IS NULL ORDER BY m.efectuat_la DESC, m.id DESC LIMIT " . max(1, $limit));
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $row = $this->decorateMovement($row);
            $row['returnabil'] = $this->fromMilli($this->milli($row['cantitate']) - $this->returnedFor((int) $row['id']));
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Situatia generala: cifre separate pe produs si unitate (litri, kg si bucati nu se aduna).
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $this->ensureSchema();
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        $byProduct = $this->db->query(
            "SELECT p.id, p.denumire, p.tip_fluid, p.um, COALESCE(SUM(r.cantitate), 0) AS cantitate, COALESCE(SUM(r.capacitate), 0) AS capacitate, COUNT(r.id) AS recipiente
             FROM consumabile_produse p
             LEFT JOIN consumabile_recipiente r ON r.produs_id = p.id AND r.status <> 'inactiv'
             WHERE p.activ = 1
             GROUP BY p.id, p.denumire, p.tip_fluid, p.um
             ORDER BY p.denumire"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stockByUnit = [];
        foreach ($byProduct as $row) {
            $stockByUnit[(string) $row['um']] = ($stockByUnit[(string) $row['um']] ?? 0) + $this->milli($row['cantitate']);
        }
        $flow = $this->db->prepare(
            "SELECT m.tip, m.um, COALESCE(SUM(m.cantitate), 0) AS cantitate, COUNT(*) AS operatiuni, COUNT(DISTINCT m.vehicul_id) AS vehicule
             FROM consumabile_miscari m
             WHERE m.efectuat_la >= :since AND m.stornata_de_id IS NULL AND m.tip <> 'stornare'
             GROUP BY m.tip, m.um"
        );
        $flow->execute([':since' => $since]);
        $consumption = [];
        $losses = [];
        $operations = 0;
        foreach ($flow->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $operations += (int) $row['operatiuni'];
            $unit = (string) $row['um'];
            if (in_array((string) $row['tip'], self::CONSUMPTION_TYPES, true)) {
                $consumption[$unit] = ($consumption[$unit] ?? 0) + $this->milli($row['cantitate']);
            } elseif ((string) $row['tip'] === 'retur') {
                $consumption[$unit] = ($consumption[$unit] ?? 0) - $this->milli($row['cantitate']);
            } elseif ((string) $row['tip'] === 'pierdere') {
                $losses[$unit] = ($losses[$unit] ?? 0) + $this->milli($row['cantitate']);
            }
        }
        $vehicles = $this->db->prepare(
            "SELECT COUNT(DISTINCT vehicul_id) FROM consumabile_miscari WHERE efectuat_la >= :since AND tip IN ('completare', 'schimb_complet') AND stornata_de_id IS NULL"
        );
        $vehicles->execute([':since' => $since]);
        $containers = $this->containers();
        $low = array_values(array_filter($containers, static fn (array $row): bool => in_array($row['stare'], ['stoc_scazut', 'gol'], true)));
        $expiring = array_values(array_filter($containers, static fn (array $row): bool => in_array($row['stare_expirare'], ['expirat', 'expira_curand'], true)));
        $articles = $this->db->query("SELECT tip_articol, COUNT(*) AS n FROM mentenanta_auto_componente WHERE activ = 1 AND tip_articol IN ('consumabil', 'fluid') GROUP BY tip_articol")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        return [
            'products' => count($byProduct),
            'catalog_articles' => array_map('intval', $articles),
            'containers' => count($containers),
            'stock_by_unit' => array_map(fn (int $value): string => $this->fromMilli($value), $stockByUnit),
            'by_product' => $byProduct,
            'consumption_30d' => array_map(fn (int $value): string => $this->fromMilli(max(0, $value)), $consumption),
            'losses_30d' => array_map(fn (int $value): string => $this->fromMilli($value), $losses),
            'operations_30d' => $operations,
            'vehicles_30d' => (int) $vehicles->fetchColumn(),
            'low_containers' => $low,
            'expiring_containers' => $expiring,
        ];
    }

    /**
     * Situatia pe vehicule din operatiunile inregistrate (completari / schimburi). Nivelul fluidului
     * NU este cunoscut din aceste date: status „Date insuficiente” pana la configurarea sistemelor
     * vehiculului (etapa 2). Nu se afiseaza niciun nivel estimat sau masurat inventat.
     *
     * @param array<string, mixed> $filters vehicle_ids[], tip_fluid, produs_id, from, to
     * @return array<int, array<string, mixed>>
     */
    public function vehicleSummary(array $filters): array
    {
        $this->ensureSchema();
        $where = ["m.tip IN ('completare', 'schimb_complet', 'retur')", 'm.stornata_de_id IS NULL', 'm.vehicul_id IS NOT NULL'];
        $params = [];
        $ids = array_values(array_filter(array_map('intval', (array) ($filters['vehicle_ids'] ?? []))));
        if ($ids !== []) {
            $where[] = 'm.vehicul_id IN (' . implode(',', $ids) . ')';
        }
        if (!empty($filters['tip_fluid'])) {
            $where[] = 'p.tip_fluid = :tip_fluid';
            $params[':tip_fluid'] = (string) $filters['tip_fluid'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'm.efectuat_la >= :from';
            $params[':from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'm.efectuat_la <= :to';
            $params[':to'] = $filters['to'] . ' 23:59:59';
        }
        $stmt = $this->db->prepare(
            "SELECT m.vehicul_id, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul, m.produs_id, p.denumire AS produs, p.tip_fluid, m.um,
                    MAX(m.sistem) AS sistem, p.sistem_mecanic AS sistem_produs,
                    SUM(CASE WHEN m.tip IN ('completare', 'schimb_complet') THEN m.cantitate ELSE -m.cantitate END) AS cantitate_neta,
                    SUM(m.tip = 'completare') AS completari,
                    SUM(m.tip = 'schimb_complet') AS schimburi,
                    MAX(CASE WHEN m.tip IN ('completare', 'schimb_complet') THEN m.efectuat_la END) AS ultima_operatiune,
                    MAX(CASE WHEN m.tip = 'schimb_complet' THEN m.efectuat_la END) AS ultimul_schimb,
                    MAX(m.km_vehicul) AS ultimul_km
             FROM consumabile_miscari m
             JOIN consumabile_produse p ON p.id = m.produs_id
             JOIN vehicule v ON v.id = m.vehicul_id
             WHERE " . implode(' AND ', $where) . '
             GROUP BY m.vehicul_id, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul, m.produs_id, p.denumire, p.tip_fluid, p.sistem_mecanic, m.um
             ORDER BY v.nr_inmatriculare, p.denumire'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $last = $this->db->prepare(
            "SELECT tip, cantitate FROM consumabile_miscari WHERE vehicul_id = :v AND produs_id = :p AND tip IN ('completare', 'schimb_complet') AND stornata_de_id IS NULL
             ORDER BY efectuat_la DESC, id DESC LIMIT 1"
        );
        foreach ($rows as &$row) {
            $last->execute([':v' => (int) $row['vehicul_id'], ':p' => (int) $row['produs_id']]);
            $lastRow = $last->fetch(PDO::FETCH_ASSOC) ?: [];
            $row['sistem'] = ($row['sistem'] ?? null) ?: (self::productSystemLabel($row['sistem_produs'] ?? null) ?: null);
            $row['ultima_tip'] = (string) ($lastRow['tip'] ?? '');
            $row['ultima_cantitate'] = (string) ($lastRow['cantitate'] ?? '0');
            $row['status'] = 'date_insuficiente';
        }
        unset($row);

        return $rows;
    }

    /** @return array<int, array<string, mixed>> vehiculele active (pentru selectoare) */
    public function vehicles(): array
    {
        return $this->db->query(
            // Categoria de capacitate ca in Dispecer / Configurare transport: capul tractor ia categoria semiremorcii cuplate activ.
            "SELECT v.id, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul, v.km_bord,
                    cc.id AS categorie_capacitate_id, cc.nume AS categorie_capacitate, cc.ordine_afisare AS categorie_capacitate_ordine
             FROM vehicule v
             LEFT JOIN (
                SELECT vc1.tractor_id, vc1.semiremorca_id FROM vehicule_cuplaje vc1
                INNER JOIN (SELECT tractor_id, MAX(id) AS max_id FROM vehicule_cuplaje WHERE activ = 1 GROUP BY tractor_id) latest ON latest.max_id = vc1.id
             ) vc ON vc.tractor_id = v.id
             LEFT JOIN vehicule s ON s.id = vc.semiremorca_id
             LEFT JOIN vehicule_categorii_capacitate cc ON cc.id = CASE
                    WHEN v.tip_vehicul = 'cap_tractor' THEN COALESCE(s.categorie_capacitate_id, v.categorie_capacitate_id)
                    ELSE v.categorie_capacitate_id END
             WHERE v.status = 'activ' AND v.nr_inmatriculare <> 'STOC-ANVELOPE' ORDER BY v.nr_inmatriculare"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, array{key:string, name:string, category:string, item_type:string}> componentele de tip consumabil / fluid */
    public function catalogComponents(): array
    {
        $result = [];
        foreach ((new AutoComponentCatalogService($this->db))->components() as $key => $component) {
            $result[$key] = $component;
        }

        return $result;
    }

    // =========================================================================
    // Interne
    // =========================================================================

    /** Tranzactie proprie sau SAVEPOINT cand apelantul are deja una (ex. testele). */
    private function transaction(callable $work): mixed
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        } else {
            $this->db->exec('SAVEPOINT consumabile_op');
        }
        try {
            $result = $work();
            if ($own) {
                $this->db->commit();
            } else {
                $this->db->exec('RELEASE SAVEPOINT consumabile_op');
            }

            return $result;
        } catch (Throwable $exception) {
            if ($own) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } else {
                $this->db->exec('ROLLBACK TO SAVEPOINT consumabile_op');
            }
            throw $exception;
        }
    }

    /**
     * Blocheaza recipientele in ordinea id-urilor (acelasi ordin in toate operatiunile = fara deadlock).
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private function lockContainers(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        sort($ids);
        $stmt = $this->db->prepare('SELECT * FROM consumabile_recipiente WHERE id IN (' . implode(',', $ids) . ') ORDER BY id FOR UPDATE');
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(int) $row['id']] = $row;
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function lockMovement(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM consumabile_miscari WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /** @return array{0:int, 1:int} [inainte, dupa] */
    private function takeFrom(array $container, int $quantity, string $context = ''): array
    {
        $before = $this->milli($container['cantitate']);
        if ($quantity > $before) {
            throw new InvalidArgumentException('Stoc insuficient în ' . $container['cod'] . ($context !== '' ? ' ' . $context : '') . ': disponibil '
                . $this->display($before, (string) ($container['um'] ?? '')) . ', cerut ' . $this->display($quantity, (string) ($container['um'] ?? '')) . '.');
        }

        return [$before, $before - $quantity];
    }

    /**
     * Verifica produsul si capacitatea destinatiei. Un recipient gol poate primi orice produs (devine
     * al acelui produs); unul cu stoc doar acelasi produs sau unul declarat explicit compatibil.
     *
     * @return array{0:int, 1:int}
     */
    private function acceptInto(array $container, array $product, int $quantity): array
    {
        $before = $this->milli($container['cantitate']);
        $capacity = $this->milli($container['capacitate']);
        $currentProduct = (int) ($container['produs_id'] ?? 0);
        if ($before > 0 && $currentProduct !== (int) $product['id']) {
            if ($currentProduct > 0 && !$this->areCompatible($currentProduct, (int) $product['id'])) {
                $current = $this->product($currentProduct);
                throw new InvalidArgumentException('Amestec interzis: ' . $container['cod'] . ' conține „' . ($current['denumire'] ?? '?')
                    . '”, iar „' . $product['denumire'] . '” nu este declarat compatibil cu acesta.');
            }
            if ((string) ($container['um'] ?? '') !== (string) $product['um']) {
                throw new InvalidArgumentException('Unități de măsură diferite între produse.');
            }
        }
        if ($before + $quantity > $capacity) {
            throw new InvalidArgumentException('Capacitate depășită în ' . $container['cod'] . ': loc liber '
                . $this->display($capacity - $before, (string) $product['um']) . ', cerut ' . $this->display($quantity, (string) $product['um']) . '.');
        }

        return [$before, $before + $quantity];
    }

    /** @param array<string, mixed> $extra lot / data_expirare */
    private function writeContainer(int $id, int $quantity, ?array $product, array $extra): void
    {
        $sets = ['cantitate = :q', 'updated_at = NOW()'];
        $params = [':q' => $this->fromMilli($quantity), ':id' => $id];
        if ($product !== null) {
            $sets[] = 'produs_id = :p';
            $sets[] = 'um = :um';
            $params[':p'] = (int) $product['id'];
            $params[':um'] = (string) $product['um'];
        }
        foreach (['lot', 'data_expirare'] as $column) {
            if (array_key_exists($column, $extra)) {
                $sets[] = $column . ' = :' . $column;
                $params[':' . $column] = $extra[$column] !== '' ? $extra[$column] : null;
            }
        }
        $this->db->prepare('UPDATE consumabile_recipiente SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
    }

    /** La amestec de loturi: se pastreaza lotul destinatiei si cea mai apropiata data de expirare. */
    private function mergeLot(array $dest, ?string $lot, ?string $expiry): array
    {
        $dates = array_values(array_filter([(string) ($dest['data_expirare'] ?? ''), (string) ($expiry ?? '')]));
        $result = ['data_expirare' => $dates !== [] ? min($dates) : null];
        if (($dest['lot'] ?? null) === null && $lot !== null) {
            $result['lot'] = $lot;
        }

        return $result;
    }

    /** @param array<string, mixed> $fields */
    private function insertMovement(array $fields): int
    {
        $columns = ['tip', 'produs_id', 'cantitate', 'um', 'recipient_sursa_id', 'sursa_inainte', 'sursa_dupa', 'recipient_dest_id', 'dest_inainte',
            'dest_dupa', 'vehicul_id', 'km_vehicul', 'sistem', 'cantitate_scoasa', 'furnizor', 'document', 'pret_unitar', 'lot', 'data_expirare',
            'motiv', 'observatii', 'referinta_id', 'idempotency_key', 'efectuat_la', 'user_id'];
        $quantities = ['cantitate', 'sursa_inainte', 'sursa_dupa', 'dest_inainte', 'dest_dupa', 'cantitate_scoasa'];
        $texts = ['sistem' => 80, 'furnizor' => 120, 'document' => 120, 'lot' => 60, 'motiv' => 255, 'observatii' => 2000];
        $params = [];
        foreach ($columns as $column) {
            $value = $fields[$column] ?? null;
            if (in_array($column, $quantities, true) && $value !== null) {
                $value = $this->fromMilli((int) $value);
            } elseif (isset($texts[$column])) {
                $value = $this->text($value ?? '', $texts[$column]);
            } elseif ($column === 'pret_unitar' && $value !== null && $value !== '') {
                $value = number_format(max(0, (float) str_replace(',', '.', (string) $value)), 4, '.', '');
            } elseif ($value === '') {
                $value = null;
            }
            $params[':' . $column] = $value;
        }
        $this->db->prepare(
            'INSERT INTO consumabile_miscari (' . implode(', ', $columns) . ', created_at)
             VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ', NOW())'
        )->execute($params);

        return (int) $this->db->lastInsertId();
    }

    private function returnedFor(int $movementId): int
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(cantitate), 0) FROM consumabile_miscari WHERE referinta_id = :id AND tip = 'retur' AND stornata_de_id IS NULL");
        $stmt->execute([':id' => $movementId]);

        return $this->milli($stmt->fetchColumn());
    }

    /** @return array<string, mixed>|null */
    private function movementByKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT id FROM consumabile_miscari WHERE idempotency_key = :k');
        $stmt->execute([':k' => $key]);
        $id = $stmt->fetchColumn();

        return $id !== false ? $this->movement((int) $id) : null;
    }

    private function movementSelect(): string
    {
        return 'SELECT m.*, p.denumire AS produs, p.tip_fluid, rs.cod AS sursa_cod, rs.tip AS sursa_tip, rd.cod AS dest_cod, rd.tip AS dest_tip,
                       v.nr_inmatriculare, u.nume AS utilizator
                FROM consumabile_miscari m
                JOIN consumabile_produse p ON p.id = m.produs_id
                LEFT JOIN consumabile_recipiente rs ON rs.id = m.recipient_sursa_id
                LEFT JOIN consumabile_recipiente rd ON rd.id = m.recipient_dest_id
                LEFT JOIN vehicule v ON v.id = m.vehicul_id
                LEFT JOIN utilizatori u ON u.id = m.user_id';
    }

    /** @return array{0:string, 1:array<string, mixed>} */
    private function movementWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['tip']) && isset(self::MOVEMENT_TYPES[(string) $filters['tip']])) {
            $where[] = 'm.tip = :tip';
            $params[':tip'] = (string) $filters['tip'];
        }
        if (!empty($filters['produs_id'])) {
            $where[] = 'm.produs_id = :produs';
            $params[':produs'] = (int) $filters['produs_id'];
        }
        if (!empty($filters['recipient_id'])) {
            $where[] = '(m.recipient_sursa_id = :r1 OR m.recipient_dest_id = :r2)';
            $params[':r1'] = (int) $filters['recipient_id'];
            $params[':r2'] = (int) $filters['recipient_id'];
        }
        $ids = array_values(array_filter(array_map('intval', (array) ($filters['vehicle_ids'] ?? []))));
        if ($ids !== []) {
            $where[] = 'm.vehicul_id IN (' . implode(',', $ids) . ')';
        }
        if (!empty($filters['from'])) {
            $where[] = 'm.efectuat_la >= :from';
            $params[':from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 'm.efectuat_la <= :to';
            $params[':to'] = $filters['to'] . ' 23:59:59';
        }

        return [implode(' AND ', $where), $params];
    }

    private function decorateMovement(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['tip_label'] = self::MOVEMENT_TYPES[(string) $row['tip']] ?? (string) $row['tip'];
        $row['este_consum'] = in_array((string) $row['tip'], self::CONSUMPTION_TYPES, true);
        $row['stornata'] = !empty($row['stornata_de_id']);

        return $row;
    }

    private function decorateContainer(array $row): array
    {
        $quantity = $this->milli($row['cantitate']);
        $capacity = $this->milli($row['capacitate']);
        $threshold = $row['prag_minim'] !== null ? $this->milli($row['prag_minim']) : null;
        $row['id'] = (int) $row['id'];
        $row['procent'] = $capacity > 0 ? round($quantity * 100 / $capacity, 1) : 0.0;
        $row['tip_label'] = $row['tip'] === 'altul' && trim((string) ($row['tip_personalizat'] ?? '')) !== ''
            ? (string) $row['tip_personalizat'] : (self::CONTAINER_TYPES[(string) $row['tip']] ?? (string) $row['tip']);
        $row['stare'] = match (true) {
            (string) $row['status'] === 'inactiv' => 'inactiv',
            $quantity === 0 => 'gol',
            $threshold !== null && $quantity <= $threshold => 'stoc_scazut',
            default => 'ok',
        };
        $row['stare_expirare'] = match (true) {
            empty($row['data_expirare']) => '',
            (string) $row['data_expirare'] < date('Y-m-d') => 'expirat',
            (string) $row['data_expirare'] <= date('Y-m-d', strtotime('+30 days')) => 'expira_curand',
            default => 'valabil',
        };

        return $row;
    }

    private function containerProduct(array $container): array
    {
        $productId = (int) ($container['produs_id'] ?? 0);
        $product = $productId > 0 ? $this->product($productId) : null;
        if ($product === null) {
            throw new InvalidArgumentException('Recipientul ' . $container['cod'] . ' nu are un produs asociat.');
        }

        return $product;
    }

    private function assertUsable(array $container): void
    {
        if ((string) $container['status'] === 'inactiv') {
            throw new InvalidArgumentException('Recipientul ' . $container['cod'] . ' este inactiv.');
        }
    }

    private function assertUniqueCode(string $code, ?int $exceptId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM consumabile_recipiente WHERE cod = :c AND id <> :id');
        $stmt->execute([':c' => $code, ':id' => (int) $exceptId]);
        if ($stmt->fetchColumn() !== false) {
            throw new InvalidArgumentException('Identificatorul ' . $code . ' este deja folosit.');
        }
    }

    private function nextCode(string $type): string
    {
        $prefix = ['butoi' => 'BUT', 'bidon' => 'BID', 'ibc' => 'IBC', 'rezervor' => 'REZ'][$type] ?? 'REC';
        $stmt = $this->db->prepare("SELECT cod FROM consumabile_recipiente WHERE cod LIKE :p");
        $stmt->execute([':p' => $prefix . '-%']);
        $max = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $code) {
            if (preg_match('/^' . $prefix . '-(\d+)$/', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('%s-%03d', $prefix, $max + 1);
    }

    /** @return array<string, mixed>|null */
    private function vehicle(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id, nr_inmatriculare, tip_vehicul, status FROM vehicule WHERE id = :id AND status = 'activ'");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private function autoVehicleType(string $type): string
    {
        return match (true) {
            $type === 'camion' => 'camion',
            $type === 'cap_tractor' => 'cap_tractor',
            str_starts_with($type, 'semiremorca') => 'semiremorca',
            default => '',
        };
    }

    private function requireReason(mixed $reason): void
    {
        if (trim((string) $reason) === '') {
            throw new InvalidArgumentException('Scrie motivul operațiunii.');
        }
    }

    private function operationTime(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return date('Y-m-d H:i:s');
        }
        $time = strtotime(str_replace('T', ' ', $value));
        if ($time === false) {
            throw new InvalidArgumentException('Data operațiunii nu este validă.');
        }
        if ($time > time() + 300) {
            throw new InvalidArgumentException('Data operațiunii nu poate fi în viitor.');
        }

        return date('Y-m-d H:i:s', $time);
    }

    private function cleanKey(string $key): string
    {
        $key = trim($key);
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            throw new InvalidArgumentException('Cheia operațiunii lipsește. Reîncarcă pagina și reîncearcă.');
        }

        return $key;
    }

    private function requiredQuantity(mixed $value): int
    {
        $quantity = $this->milli($value);
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Cantitatea trebuie să fie mai mare decât 0.');
        }

        return $quantity;
    }

    /** Cantitate in miimi (intreg). „1,5” = 1500. Negativele sunt respinse. */
    public function milli(mixed $value, bool $required = false): int
    {
        $text = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if ($text === '') {
            if ($required) {
                throw new InvalidArgumentException('Completează cantitatea.');
            }

            return 0;
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException('Cantitate invalidă: „' . $value . '”.');
        }
        [$int, $dec] = array_pad(explode('.', $text, 2), 2, '');
        if (strlen(rtrim($dec, '0')) > 3) {
            throw new InvalidArgumentException('Cantitatea acceptă cel mult 3 zecimale.');
        }
        if (strlen($int) > 9) {
            throw new InvalidArgumentException('Cantitate prea mare.');
        }

        return (int) $int * 1000 + (int) str_pad(substr($dec, 0, 3), 3, '0');
    }

    private function optionalMilli(mixed $value): ?int
    {
        return trim((string) $value) === '' ? null : $this->milli($value);
    }

    private function optionalQuantity(mixed $value): ?string
    {
        $milli = $this->optionalMilli($value);

        return $milli !== null ? $this->fromMilli($milli) : null;
    }

    public function fromMilli(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $value = abs($value);

        return $sign . intdiv($value, 1000) . '.' . str_pad((string) ($value % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function display(int $milli, string $unit = ''): string
    {
        $text = rtrim(rtrim(number_format($milli / 1000, 3, ',', '.'), '0'), ',');

        return trim($text . ' ' . $unit);
    }

    private function optionalDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $date = DateTime::createFromFormat('Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Dată invalidă: ' . $value . '.');
        }

        return $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = preg_replace('/[^\d]/', '', (string) $value) ?? '';

        return $value !== '' && (int) $value > 0 ? (int) $value : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        if ($max > 255) {
            $value = trim((string) $value);
        }

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    /** @return string[] */
    /**
     * Sistemele de vehicul pentru care e destinat produsul (chei din VehicleFluidService::SYSTEM_PRESETS,
     * fara „altul”). Valorile vechi scrise liber („Motor”) se potrivesc pe cheie / inceputul etichetei.
     *
     * @return list<string>
     */
    public static function productSystemKeys(?string $value): array
    {
        $presets = VehicleFluidService::SYSTEM_PRESETS;
        unset($presets['altul']);
        $keys = [];
        foreach (self::csv((string) $value) as $part) {
            $needle = mb_strtolower($part);
            foreach ($presets as $key => $preset) {
                $label = mb_strtolower((string) $preset[0]);
                if ($needle === $key || $needle === $label || str_starts_with($label, $needle . ' ') || str_starts_with($label, $needle . ' (')) {
                    $keys[$key] = true;
                    break;
                }
            }
        }

        return array_values(array_intersect(array_keys($presets), array_keys($keys)));
    }

    /** Sistemul de vehicul care foloseste tipul de fluid (cheie din SYSTEM_PRESETS) sau null. */
    public static function systemForFluid(?string $fluidType): ?string
    {
        foreach (VehicleFluidService::SYSTEM_PRESETS as $key => $preset) {
            if ($fluidType !== null && $fluidType !== '' && (string) $preset[1] === $fluidType) {
                return $key;
            }
        }

        return null;
    }

    /** Eticheta afisata; textul vechi nepotrivit ramane cum a fost scris. */
    public static function productSystemLabel(?string $value): string
    {
        $keys = self::productSystemKeys($value);
        if ($keys === []) {
            return trim((string) $value);
        }

        return implode(', ', array_map(static fn (string $key): string => (string) VehicleFluidService::SYSTEM_PRESETS[$key][0], $keys));
    }

    private static function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }
}
