<?php
declare(strict_types=1);

/**
 * Revizii V2 — persistenta (planuri X / Y, ciclu 5 + 1, lucrari, executii pe operatiuni).
 *
 * Tabele (create la runtime; oglinda in database/migrations/2026_11_13_000001_revizii_v2.sql):
 *  - revizii_v2_operatii          catalogul operatiunilor (componenta + tip + operatiune);
 *  - revizii_v2_sabloane          sabloanele de revizie (X; Y mosteneste un X prin parinte_id);
 *  - revizii_v2_sablon_operatii   operatiunile proprii ale unui sablon (Y NU copiaza X-ul);
 *  - revizii_v2_planuri           planul pe familie de vehicule (tip / marca / model): intervalul X
 *                                 (km / ore / luni), pragul / intervalul recurent km al lui Y, toleranta de
 *                                 coincidenta X↔Y, politica X dupa Y, praguri de avertizare;
 *  - revizii_v2_plan_reguli       regula per operatiune in plan (aplicabila, intervale proprii, cantitati);
 *  - revizii_v2_vehicule          asignarea vehiculului (plan explicit, referintele la preluare: ultima revizie,
 *                                 ultima Y, X efectuate de la ultima Y — doar pentru etichete);
 *  - revizii_v2_vehicul_reguli    exceptiile per vehicul (operatiune dezactivata / interval diferit);
 *  - revizii_v2_lucrari           evenimentul de revizie (ordinul de lucru) — un singur eveniment pentru Y;
 *  - revizii_v2_lucrari_operatii  liniile ordinului, cu instantaneu (componenta, operatiune, regula);
 *  - revizii_v2_executii          istoricul pe operatiune (o singura istorie per vehicul × operatiune,
 *                                 alimentata si din revizii, si din inregistrari independente);
 *  - revizii_v2_documente         fisierele atasate unei lucrari;
 *  - revizii_v2_setari            setarile modulului (prag implicit zile, vechimea datelor).
 *
 * Modificarile de configurare se scriu si in audit_log (modul „revizii_v2”).
 */
final class ReviziiV2Model
{
    public const OPEN_STATUSES = ['programata', 'in_desfasurare'];

    public const WORK_STATUSES = [
        'programata' => 'Programată',
        'in_desfasurare' => 'În desfășurare',
        'finalizata' => 'Finalizată',
        'partiala' => 'Finalizată parțial',
        'anulata' => 'Anulată',
    ];

    public const ITEM_STATUSES = [
        'pending' => 'De efectuat',
        'in_progress' => 'În lucru',
        'completed' => 'Efectuat',
        'na' => 'Nu se aplică',
        'deferred' => 'Amânat',
        'blocked' => 'Blocat',
    ];

    public const OPERATION_TYPES = [
        'inlocuire' => 'Înlocuire',
        'gresare' => 'Gresare',
        'inspectie' => 'Inspecție',
        'completare' => 'Completare',
        'curatare' => 'Curățare',
        'reglaj' => 'Reglaj',
    ];

    public const CATEGORIES = [
        'fluide' => 'Uleiuri & fluide',
        'filtre' => 'Filtre',
        'gresare' => 'Gresare',
        'transmisie' => 'Transmisie',
        'franare' => 'Frânare',
        'altele' => 'Altele',
    ];

    public const VEHICLE_TYPES = [
        'cap_tractor' => 'Cap tractor',
        'camion' => 'Camion',
        'semiremorca' => 'Semiremorcă',
    ];

    public const DEFAULT_SETTINGS = [
        'date_vechi_ore' => '48',
        'prag_zile_implicit' => '14',
        'sursa_km_implicita' => 'auto',
    ];

    /**
     * Catalogul initial (din referinta furnizata). Fara intervale: valorile tehnice se
     * configureaza in plan, din documentatia producatorului / regulile firmei.
     * [cod, componenta, tip, operatiune, categorie, sistem_fluid, aplicabil, factori uzura, de_verificat, sablon]
     */
    private const SEED_OPERATIONS = [
        ['ulei_motor', 'Ulei motor', 'inlocuire', 'Înlocuire ulei motor', 'fluide', 'motor', 'cap_tractor,camion', 'Km parcurși, ore motor, ralanti prelungit, sarcină', 0, 'X'],
        ['filtru_ulei', 'Filtru ulei', 'inlocuire', 'Înlocuire filtru ulei', 'filtre', null, 'cap_tractor,camion', 'Km parcurși, ore motor', 0, 'X'],
        ['filtru_motorina', 'Filtru motorină', 'inlocuire', 'Înlocuire filtru motorină', 'filtre', null, 'cap_tractor,camion', 'Km parcurși, calitatea combustibilului', 0, 'X'],
        ['filtru_desicator', 'Filtru uscător/desicator', 'inlocuire', 'Înlocuire filtru uscător/desicator', 'filtre', null, 'cap_tractor,camion', 'Umiditate aer comprimat, consum aer, timp', 0, 'X'],
        ['filtru_aer', 'Filtru aer', 'inlocuire', 'Înlocuire filtru aer', 'filtre', null, 'cap_tractor,camion', 'Expunere la praf, ore motor, condiții de drum', 0, 'X'],
        ['filtru_habitaclu', 'Filtru habitaclu/polen', 'inlocuire', 'Înlocuire filtru habitaclu/polen', 'filtre', null, 'cap_tractor,camion', 'Praf, polen, timp', 1, 'X'],
        ['filtru_centrifugal', 'Filtru centrifugal', 'inlocuire', 'Înlocuire filtru centrifugal', 'filtre', null, 'cap_tractor,camion', 'Ore motor, contaminare ulei', 1, 'X'],
        ['filtru_servodirectie', 'Filtru servo-direcție', 'inlocuire', 'Înlocuire filtru servo-direcție', 'filtre', null, 'cap_tractor,camion', 'Km parcurși, timp', 0, 'X'],
        ['filtru_adblue', 'Filtru AdBlue', 'inlocuire', 'Înlocuire filtru AdBlue', 'filtre', null, 'cap_tractor,camion', 'Calitatea AdBlue, km parcurși', 0, 'X'],
        ['filtru_uscator_adblue', 'Filtru uscător AdBlue', 'inlocuire', 'Înlocuire filtru uscător AdBlue', 'filtre', null, 'cap_tractor,camion', 'Umiditate, timp', 1, 'X'],
        ['gresare_pivoti', 'Pivoți', 'gresare', 'Gresare pivoți', 'gresare', null, 'cap_tractor,camion', 'Km parcurși, apă / noroi, încărcare pe axă', 0, 'X'],
        ['gresare_cardan', 'Culise cardan', 'gresare', 'Gresare culise cardan', 'gresare', null, 'cap_tractor,camion', 'Km parcurși, cuplu transmis', 0, 'X'],
        ['gresare_placa', 'Placă cuplare', 'gresare', 'Gresare placă (potcoavă)', 'gresare', null, 'cap_tractor', 'Cuplări / decuplări, km parcurși', 0, 'X'],
        ['gresare_cui', 'Cui remorcă', 'gresare', 'Gresare cui remorcă', 'gresare', null, 'camion', 'Cuplări / decuplări, km parcurși', 0, 'X'],
        ['filtru_cabina', 'Filtru cabină', 'inlocuire', 'Înlocuire filtru cabină', 'filtre', null, 'cap_tractor,camion', 'Praf, timp', 1, 'X'],
        ['filtru_bypass', 'Filtru bypass ulei', 'inlocuire', 'Înlocuire filtru bypass ulei', 'filtre', null, 'cap_tractor,camion', 'Ore motor, contaminare ulei', 0, 'X'],
        ['filtru_sita_motrica', 'Filtru motrică (sită)', 'inlocuire', 'Înlocuire filtru motrică (sită)', 'filtre', null, 'cap_tractor,camion', 'Calitatea combustibilului', 1, 'X'],
        ['filtru_centrifugal_hartie', 'Filtru centrifugal (hârtie)', 'inlocuire', 'Înlocuire filtru centrifugal (hârtie)', 'filtre', null, 'cap_tractor,camion', 'Ore motor, contaminare ulei', 1, 'X'],
        ['ulei_cutie', 'Ulei cutie viteze', 'inlocuire', 'Înlocuire ulei cutie viteze', 'transmisie', 'transmisie', 'cap_tractor,camion', 'Km parcurși, ore motor, temperatură', 0, 'Y'],
        ['filtru_ulei_cutie', 'Filtru ulei cutie viteze', 'inlocuire', 'Înlocuire filtru ulei cutie viteze', 'transmisie', null, 'cap_tractor,camion', 'Km parcurși', 0, 'Y'],
        ['ulei_diferential', 'Ulei grup diferențial', 'inlocuire', 'Înlocuire ulei grup diferențial', 'transmisie', 'diferential', 'cap_tractor,camion', 'Km parcurși, sarcină, temperatură', 0, 'Y'],
        ['filtru_ulei_diferential', 'Filtru ulei grup diferențial', 'inlocuire', 'Înlocuire filtru ulei grup diferențial', 'transmisie', null, 'cap_tractor,camion', 'Km parcurși', 0, 'Y'],
    ];

    private static bool $schemaReady = false;

    public function __construct(private PDO $db)
    {
        $this->ensureSchema();
    }

    public function db(): PDO
    {
        return $this->db;
    }

    // ================================================================= schema

    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $statements = [
            "CREATE TABLE IF NOT EXISTS revizii_v2_operatii (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                cod VARCHAR(60) NOT NULL,
                componenta VARCHAR(120) NOT NULL,
                tip_operatie VARCHAR(20) NOT NULL DEFAULT 'inlocuire',
                operatie VARCHAR(190) NOT NULL,
                categorie VARCHAR(30) NOT NULL DEFAULT 'altele',
                sistem_fluid VARCHAR(40) NULL,
                aplicabil VARCHAR(120) NOT NULL DEFAULT 'cap_tractor,camion',
                factori_uzura VARCHAR(500) NULL,
                criterii_candidate VARCHAR(120) NULL,
                valori_candidate VARCHAR(255) NULL,
                component_key VARCHAR(10) NULL,
                lubrifiant VARCHAR(120) NULL,
                puncte_gresare SMALLINT UNSIGNED NULL,
                descriere TEXT NULL,
                de_verificat TINYINT(1) NOT NULL DEFAULT 0,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_rv2_op_cod (cod)
            ) $engine",
            "CREATE TABLE IF NOT EXISTS revizii_v2_sabloane (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                cod VARCHAR(60) NOT NULL,
                nume VARCHAR(120) NOT NULL,
                tip CHAR(1) NOT NULL DEFAULT 'X',
                parinte_id INT UNSIGNED NULL,
                versiune INT UNSIGNED NOT NULL DEFAULT 1,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_rv2_sablon_cod (cod),
                CONSTRAINT fk_rv2_sablon_parinte FOREIGN KEY (parinte_id) REFERENCES revizii_v2_sabloane (id)
            ) $engine",
            "CREATE TABLE IF NOT EXISTS revizii_v2_sablon_operatii (
                sablon_id INT UNSIGNED NOT NULL,
                operatie_id INT UNSIGNED NOT NULL,
                ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (sablon_id, operatie_id),
                CONSTRAINT fk_rv2_so_sablon FOREIGN KEY (sablon_id) REFERENCES revizii_v2_sabloane (id) ON DELETE CASCADE,
                CONSTRAINT fk_rv2_so_operatie FOREIGN KEY (operatie_id) REFERENCES revizii_v2_operatii (id)
            ) $engine",
            "CREATE TABLE IF NOT EXISTS revizii_v2_planuri (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                nume VARCHAR(120) NOT NULL,
                tip_vehicul VARCHAR(30) NULL,
                marca VARCHAR(50) NULL,
                model VARCHAR(50) NULL,
                sablon_x_id INT UNSIGNED NOT NULL,
                sablon_y_id INT UNSIGNED NULL,
                interval_km INT UNSIGNED NULL,
                interval_ore INT UNSIGNED NULL,
                interval_luni SMALLINT UNSIGNED NULL,
                logica VARCHAR(3) NOT NULL DEFAULT 'or',
                y_prag_km INT UNSIGNED NULL,
                y_interval_km INT UNSIGNED NULL,
                y_toleranta_km INT UNSIGNED NULL,
                politica_x VARCHAR(10) NOT NULL DEFAULT 'reset',
                sursa_km VARCHAR(10) NOT NULL DEFAULT 'auto',
                prag_km INT UNSIGNED NULL,
                prag_ore INT UNSIGNED NULL,
                prag_zile SMALLINT UNSIGNED NULL,
                cost_estimat_x DECIMAL(12,2) NULL,
                cost_estimat_y DECIMAL(12,2) NULL,
                responsabili VARCHAR(255) NULL,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_rv2_plan_x FOREIGN KEY (sablon_x_id) REFERENCES revizii_v2_sabloane (id),
                CONSTRAINT fk_rv2_plan_y FOREIGN KEY (sablon_y_id) REFERENCES revizii_v2_sabloane (id)
            ) $engine",
            "CREATE TABLE IF NOT EXISTS revizii_v2_plan_reguli (
                plan_id INT UNSIGNED NOT NULL,
                operatie_id INT UNSIGNED NOT NULL,
                activ TINYINT(1) NOT NULL DEFAULT 1,
                interval_km INT UNSIGNED NULL,
                interval_ore INT UNSIGNED NULL,
                interval_luni SMALLINT UNSIGNED NULL,
                logica VARCHAR(3) NOT NULL DEFAULT 'or',
                coef_ralanti_km DECIMAL(6,2) NULL,
                cantitate DECIMAL(10,3) NULL,
                um VARCHAR(10) NULL,
                produs_id INT UNSIGNED NULL,
                cost_estimat DECIMAL(12,2) NULL,
                PRIMARY KEY (plan_id, operatie_id),
                CONSTRAINT fk_rv2_pr_plan FOREIGN KEY (plan_id) REFERENCES revizii_v2_planuri (id) ON DELETE CASCADE,
                CONSTRAINT fk_rv2_pr_operatie FOREIGN KEY (operatie_id) REFERENCES revizii_v2_operatii (id)
            ) $engine",
            "CREATE TABLE IF NOT EXISTS revizii_v2_vehicule (
                vehicle_id INT UNSIGNED NOT NULL PRIMARY KEY,
                plan_id INT UNSIGNED NULL,
                x_de_la_ultima_y SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                y_efectuate SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                referinta_data DATETIME NULL,
                referinta_km DECIMAL(12,1) NULL,
                referinta_ore DECIMAL(10,1) NULL,
                ultima_y_data DATETIME NULL,
                ultima_y_km DECIMAL(12,1) NULL,
                origine_grila_km DECIMAL(12,1) NULL,
                observatii VARCHAR(500) NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_rv2_veh_plan FOREIGN KEY (plan_id) REFERENCES revizii_v2_planuri (id) ON DELETE SET NULL
            ) $engine",
            "CREATE TABLE IF NOT EXISTS revizii_v2_vehicul_reguli (
                vehicle_id INT UNSIGNED NOT NULL,
                operatie_id INT UNSIGNED NOT NULL,
                activ TINYINT(1) NULL,
                interval_km INT UNSIGNED NULL,
                interval_ore INT UNSIGNED NULL,
                interval_luni SMALLINT UNSIGNED NULL,
                cantitate DECIMAL(10,3) NULL,
                motiv VARCHAR(255) NULL,
                PRIMARY KEY (vehicle_id, operatie_id),
                CONSTRAINT fk_rv2_vr_operatie FOREIGN KEY (operatie_id) REFERENCES revizii_v2_operatii (id)
            ) $engine",
            "CREATE TABLE IF NOT EXISTS revizii_v2_lucrari (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                plan_id INT UNSIGNED NULL,
                tip CHAR(1) NOT NULL DEFAULT 'X',
                nr_x SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                nr_y SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                km_tinta DECIMAL(12,1) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'programata',
                activ_vehicle_id INT UNSIGNED NULL,
                data_programata DATE NULL,
                data_executie DATETIME NULL,
                km_executie DECIMAL(12,1) NULL,
                km_sursa VARCHAR(10) NULL,
                ore_executie DECIMAL(10,1) NULL,
                tehnician VARCHAR(120) NULL,
                observatii TEXT NULL,
                cost_manopera DECIMAL(12,2) NULL,
                cost_materiale DECIMAL(12,2) NULL,
                cost_total DECIMAL(12,2) NULL,
                completa TINYINT(1) NOT NULL DEFAULT 0,
                snapshot LONGTEXT NULL,
                created_by INT UNSIGNED NULL,
                finalized_by INT UNSIGNED NULL,
                finalized_at DATETIME NULL,
                anulat_motiv VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_rv2_lucrare_activa (activ_vehicle_id),
                KEY idx_rv2_lucrare_vehicul (vehicle_id, status, data_executie)
            ) $engine",
            "CREATE TABLE IF NOT EXISTS revizii_v2_lucrari_operatii (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                lucrare_id INT UNSIGNED NOT NULL,
                operatie_id INT UNSIGNED NOT NULL,
                sectiune CHAR(1) NOT NULL DEFAULT 'X',
                componenta VARCHAR(120) NOT NULL,
                operatie VARCHAR(190) NOT NULL,
                tip_operatie VARCHAR(20) NOT NULL,
                categorie VARCHAR(30) NULL,
                regula_snapshot TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                motiv VARCHAR(255) NULL,
                cantitate_estimata DECIMAL(10,3) NULL,
                cantitate_reala DECIMAL(10,3) NULL,
                um VARCHAR(10) NULL,
                produs_id INT UNSIGNED NULL,
                recipient_id INT UNSIGNED NULL,
                miscare_id BIGINT UNSIGNED NULL,
                cost_material DECIMAL(12,2) NULL,
                observatii VARCHAR(500) NULL,
                executie_id INT UNSIGNED NULL,
                ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY uq_rv2_lo (lucrare_id, operatie_id),
                CONSTRAINT fk_rv2_lo_lucrare FOREIGN KEY (lucrare_id) REFERENCES revizii_v2_lucrari (id) ON DELETE CASCADE
            ) $engine",
            "CREATE TABLE IF NOT EXISTS revizii_v2_executii (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                operatie_id INT UNSIGNED NOT NULL,
                lucrare_id INT UNSIGNED NULL,
                tip_executie VARCHAR(20) NOT NULL,
                reseteaza TINYINT(1) NOT NULL DEFAULT 1,
                data_executie DATETIME NOT NULL,
                km DECIMAL(12,1) NULL,
                km_sursa VARCHAR(10) NULL,
                ore DECIMAL(10,1) NULL,
                cantitate DECIMAL(10,3) NULL,
                um VARCHAR(10) NULL,
                produs_id INT UNSIGNED NULL,
                miscare_id BIGINT UNSIGNED NULL,
                cost DECIMAL(12,2) NULL,
                tehnician VARCHAR(120) NULL,
                observatii VARCHAR(500) NULL,
                idempotency_key VARCHAR(80) NOT NULL,
                anulat_la DATETIME NULL,
                anulat_motiv VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_rv2_exec_key (idempotency_key),
                KEY idx_rv2_exec_vo (vehicle_id, operatie_id, data_executie),
                CONSTRAINT fk_rv2_exec_operatie FOREIGN KEY (operatie_id) REFERENCES revizii_v2_operatii (id)
            ) $engine",
            "CREATE TABLE IF NOT EXISTS revizii_v2_documente (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                lucrare_id INT UNSIGNED NOT NULL,
                document_path VARCHAR(255) NOT NULL,
                nume_original VARCHAR(255) NOT NULL,
                mime VARCHAR(120) NULL,
                marime INT UNSIGNED NULL,
                uploaded_by INT UNSIGNED NULL,
                uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                KEY idx_rv2_doc_lucrare (lucrare_id)
            ) $engine",
            "CREATE TABLE IF NOT EXISTS revizii_v2_setari (
                setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) $engine",
        ];
        // DDL doar cand lipsesc tabele (CREATE TABLE face COMMIT implicit — niciodata intr-o tranzactie).
        $existing = (int) $this->db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'revizii\\_v2\\_%'"
        )->fetchColumn();
        if ($existing < count($statements)) {
            if ($this->db->inTransaction()) {
                throw new RuntimeException('Schema Revizii V2 lipsește; nu poate fi creată în interiorul unei tranzacții.');
            }
            foreach ($statements as $sql) {
                $this->db->exec($sql);
            }
        }
        self::$schemaReady = true;
        $this->seed();
    }

    /** Catalogul si sabloanele implicite — o singura data (tabela goala). Fara planuri, fara intervale. */
    private function seed(): void
    {
        if ((int) $this->db->query('SELECT COUNT(*) FROM revizii_v2_operatii')->fetchColumn() > 0) {
            return;
        }
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->exec("INSERT INTO revizii_v2_sabloane (cod, nume, tip) VALUES ('x_standard', 'Revizia X — standard', 'X')");
            $xId = (int) $this->db->lastInsertId();
            $stmt = $this->db->prepare("INSERT INTO revizii_v2_sabloane (cod, nume, tip, parinte_id) VALUES ('y_standard', 'Revizia Y — standard (X + operațiuni suplimentare)', 'Y', :p)");
            $stmt->execute([':p' => $xId]);
            $yId = (int) $this->db->lastInsertId();

            $insert = $this->db->prepare(
                'INSERT INTO revizii_v2_operatii (cod, componenta, tip_operatie, operatie, categorie, sistem_fluid, aplicabil, factori_uzura, de_verificat, ordine)
                 VALUES (:cod, :comp, :tip, :op, :cat, :sist, :apl, :fact, :ver, :ord)'
            );
            $link = $this->db->prepare('INSERT INTO revizii_v2_sablon_operatii (sablon_id, operatie_id, ordine) VALUES (:s, :o, :ord)');
            foreach (self::SEED_OPERATIONS as $i => [$code, $component, $type, $operation, $category, $system, $applies, $factors, $review, $template]) {
                $insert->execute([
                    ':cod' => $code, ':comp' => $component, ':tip' => $type, ':op' => $operation, ':cat' => $category, ':sist' => $system,
                    ':apl' => $applies, ':fact' => $factors, ':ver' => $review, ':ord' => ($i + 1) * 10,
                ]);
                $link->execute([':s' => $template === 'Y' ? $yId : $xId, ':o' => (int) $this->db->lastInsertId(), ':ord' => ($i + 1) * 10]);
            }
            if ($own) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    // ================================================================= setari

    /** @return array<string, string> */
    public function settings(): array
    {
        $settings = self::DEFAULT_SETTINGS;
        foreach ($this->db->query('SELECT setting_key, setting_value FROM revizii_v2_setari')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists((string) $row['setting_key'], $settings)) {
                $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        }

        return $settings;
    }

    public function saveSetting(string $key, string $value, ?int $userId): void
    {
        if (!array_key_exists($key, self::DEFAULT_SETTINGS)) {
            throw new InvalidArgumentException('Setare necunoscută.');
        }
        $stmt = $this->db->prepare(
            'INSERT INTO revizii_v2_setari (setting_key, setting_value, updated_by) VALUES (:k, :v, :u)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([':k' => $key, ':v' => $value, ':u' => $userId]);
    }

    // ================================================================= vehicule

    /** @return array<int, array<string, mixed>> */
    public function vehicles(): array
    {
        $rows = $this->db->query(
            "SELECT v.id, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul, v.status, v.serie_sasiu, v.an_fabricatie,
                    COALESCE(v.km_bord, 0) AS km_bord, v.poza_stocata, v.garaj, v.formula_axelor, v.categorie_capacitate_id
             FROM vehicule v
             WHERE v.status = 'activ'
               AND v.tip_vehicul IN ('cap_tractor', 'camion', 'semiremorca', 'semiremorca_primar', 'semiremorca_distributie')
             ORDER BY v.nr_inmatriculare"
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $row['km_bord'] = (int) $row['km_bord'];
            $row['familie'] = self::vehicleFamily((string) $row['tip_vehicul']);
            $result[$row['id']] = $row;
        }

        return $result;
    }

    /** cap_tractor / camion / semiremorca (toate variantele de semi-remorca). */
    public static function vehicleFamily(string $type): string
    {
        return str_starts_with($type, 'semiremorca') ? 'semiremorca' : ($type === 'camion' ? 'camion' : 'cap_tractor');
    }

    /** @return array<int, array<string, mixed>> vehicle_id => asignare */
    public function assignments(): array
    {
        $result = [];
        foreach ($this->db->query('SELECT * FROM revizii_v2_vehicule')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['vehicle_id']] = $row;
        }

        return $result;
    }

    /** @return array<int, array<int, array<string, mixed>>> vehicle_id => operatie_id => exceptie */
    public function vehicleRules(): array
    {
        $result = [];
        foreach ($this->db->query('SELECT * FROM revizii_v2_vehicul_reguli')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['vehicle_id']][(int) $row['operatie_id']] = $row;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    public function saveAssignment(int $vehicleId, array $data, ?int $userId): void
    {
        $before = $this->assignments()[$vehicleId] ?? null;
        $stmt = $this->db->prepare(
            'INSERT INTO revizii_v2_vehicule (vehicle_id, plan_id, x_de_la_ultima_y, y_efectuate, referinta_data, referinta_km, referinta_ore,
                ultima_y_data, ultima_y_km, origine_grila_km, observatii, updated_by)
             VALUES (:v, :p, :xn, :yn, :rd, :rk, :ro, :yd, :yk, :og, :obs, :u)
             ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), x_de_la_ultima_y = VALUES(x_de_la_ultima_y), y_efectuate = VALUES(y_efectuate),
                referinta_data = VALUES(referinta_data), referinta_km = VALUES(referinta_km), referinta_ore = VALUES(referinta_ore),
                ultima_y_data = VALUES(ultima_y_data), ultima_y_km = VALUES(ultima_y_km), origine_grila_km = VALUES(origine_grila_km),
                observatii = VALUES(observatii), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([
            ':v' => $vehicleId, ':p' => $data['plan_id'], ':xn' => $data['x_de_la_ultima_y'], ':yn' => $data['y_efectuate'],
            ':rd' => $data['referinta_data'], ':rk' => $data['referinta_km'], ':ro' => $data['referinta_ore'], ':yd' => $data['ultima_y_data'],
            ':yk' => $data['ultima_y_km'], ':og' => $data['origine_grila_km'], ':obs' => $data['observatii'], ':u' => $userId,
        ]);
        $this->audit($vehicleId, 'asignare_vehicul', 'Asignare plan / referințe revizii vehicul', $before, $data, $userId);
    }

    /** @param array<int, array<string, mixed>> $rules operatie_id => exceptie (null = sterge) */
    public function saveVehicleRules(int $vehicleId, array $rules, ?int $userId): void
    {
        $before = $this->vehicleRules()[$vehicleId] ?? [];
        $delete = $this->db->prepare('DELETE FROM revizii_v2_vehicul_reguli WHERE vehicle_id = :v AND operatie_id = :o');
        $upsert = $this->db->prepare(
            'INSERT INTO revizii_v2_vehicul_reguli (vehicle_id, operatie_id, activ, interval_km, interval_ore, interval_luni, cantitate, motiv)
             VALUES (:v, :o, :a, :km, :ore, :luni, :q, :m)
             ON DUPLICATE KEY UPDATE activ = VALUES(activ), interval_km = VALUES(interval_km), interval_ore = VALUES(interval_ore),
                interval_luni = VALUES(interval_luni), cantitate = VALUES(cantitate), motiv = VALUES(motiv)'
        );
        foreach ($rules as $operationId => $rule) {
            if ($rule === null) {
                $delete->execute([':v' => $vehicleId, ':o' => $operationId]);
                continue;
            }
            $upsert->execute([
                ':v' => $vehicleId, ':o' => $operationId, ':a' => $rule['activ'], ':km' => $rule['interval_km'], ':ore' => $rule['interval_ore'],
                ':luni' => $rule['interval_luni'], ':q' => $rule['cantitate'], ':m' => $rule['motiv'],
            ]);
        }
        $this->audit($vehicleId, 'exceptii_vehicul', 'Excepții operațiuni pe vehicul', $before, $rules, $userId);
    }

    // ================================================================= catalog / sabloane / planuri

    /** @return array<int, array<string, mixed>> */
    public function operations(bool $activeOnly = false): array
    {
        $rows = $this->db->query('SELECT * FROM revizii_v2_operatii' . ($activeOnly ? ' WHERE activ = 1' : '') . ' ORDER BY ordine, id')->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $row['id'] = (int) $row['id'];
            $result[$row['id']] = $row;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    public function saveOperation(array $data, ?int $id, ?int $userId): int
    {
        $fields = ['componenta', 'tip_operatie', 'operatie', 'categorie', 'sistem_fluid', 'aplicabil', 'factori_uzura', 'criterii_candidate',
            'valori_candidate', 'component_key', 'lubrifiant', 'puncte_gresare', 'descriere', 'de_verificat', 'activ', 'ordine'];
        $params = [];
        foreach ($fields as $field) {
            $params[':' . $field] = $data[$field] ?? null;
        }
        $params[':de_verificat'] = (int) ($data['de_verificat'] ?? 0);
        $params[':activ'] = (int) ($data['activ'] ?? 1);
        $params[':ordine'] = (int) ($data['ordine'] ?? 0);
        if ($id === null) {
            $params[':cod'] = (string) $data['cod'];
            $stmt = $this->db->prepare('INSERT INTO revizii_v2_operatii (cod, ' . implode(', ', $fields) . ') VALUES (:cod, :' . implode(', :', $fields) . ')');
            $stmt->execute($params);
            $id = (int) $this->db->lastInsertId();
            $this->audit($id, 'operatie_noua', 'Operațiune nouă în catalog: ' . $data['operatie'], null, $data, $userId);

            return $id;
        }
        $before = $this->operations()[$id] ?? null;
        $sets = implode(', ', array_map(static fn (string $f): string => $f . ' = :' . $f, $fields));
        $params[':id'] = $id;
        $this->db->prepare('UPDATE revizii_v2_operatii SET ' . $sets . ' WHERE id = :id')->execute($params);
        $this->audit($id, 'operatie_modificata', 'Operațiune modificată: ' . $data['operatie'], $before, $data, $userId);

        return $id;
    }

    /** @return array<int, array<string, mixed>> cu 'operatii' => [operatie_id, ...] (doar cele proprii) */
    public function templates(): array
    {
        $result = [];
        foreach ($this->db->query('SELECT * FROM revizii_v2_sabloane ORDER BY tip, id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['parinte_id'] = $row['parinte_id'] !== null ? (int) $row['parinte_id'] : null;
            $row['operatii'] = [];
            $result[$row['id']] = $row;
        }
        foreach ($this->db->query('SELECT sablon_id, operatie_id FROM revizii_v2_sablon_operatii ORDER BY ordine, operatie_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($result[(int) $row['sablon_id']])) {
                $result[(int) $row['sablon_id']]['operatii'][] = (int) $row['operatie_id'];
            }
        }

        return $result;
    }

    /**
     * Salveaza un sablon. Y = parinte X + operatiunile proprii (nu copiaza X).
     * Versiunea creste la fiecare modificare a listei (istoricul pastreaza instantaneul).
     *
     * @param array<int, int> $operationIds
     */
    public function saveTemplate(?int $id, string $name, string $type, ?int $parentId, array $operationIds, bool $active, ?int $userId): int
    {
        $type = $type === 'Y' ? 'Y' : 'X';
        if ($type === 'Y' && $parentId === null) {
            throw new InvalidArgumentException('Șablonul Y trebuie să moștenească un șablon X.');
        }
        $before = $id !== null ? ($this->templates()[$id] ?? null) : null;
        if ($id === null) {
            $code = ($type === 'Y' ? 'y_' : 'x_') . substr(bin2hex(random_bytes(4)), 0, 8);
            $stmt = $this->db->prepare('INSERT INTO revizii_v2_sabloane (cod, nume, tip, parinte_id, activ) VALUES (:c, :n, :t, :p, :a)');
            $stmt->execute([':c' => $code, ':n' => $name, ':t' => $type, ':p' => $type === 'Y' ? $parentId : null, ':a' => $active ? 1 : 0]);
            $id = (int) $this->db->lastInsertId();
        } else {
            $changed = $before === null || array_values($before['operatii']) !== array_values($operationIds) || (int) ($before['parinte_id'] ?? 0) !== (int) $parentId;
            $stmt = $this->db->prepare('UPDATE revizii_v2_sabloane SET nume = :n, parinte_id = :p, activ = :a, versiune = versiune + :inc WHERE id = :id');
            $stmt->execute([':n' => $name, ':p' => $type === 'Y' ? $parentId : null, ':a' => $active ? 1 : 0, ':inc' => $changed ? 1 : 0, ':id' => $id]);
        }
        $this->db->prepare('DELETE FROM revizii_v2_sablon_operatii WHERE sablon_id = :s')->execute([':s' => $id]);
        $link = $this->db->prepare('INSERT INTO revizii_v2_sablon_operatii (sablon_id, operatie_id, ordine) VALUES (:s, :o, :ord)');
        foreach (array_values(array_unique($operationIds)) as $i => $operationId) {
            $link->execute([':s' => $id, ':o' => $operationId, ':ord' => ($i + 1) * 10]);
        }
        $this->audit($id, 'sablon', 'Șablon revizie ' . $type . ': ' . $name, $before, ['operatii' => $operationIds, 'parinte_id' => $parentId], $userId);

        return $id;
    }

    /** @return array<int, array<string, mixed>> cu 'reguli' => operatie_id => regula */
    public function plans(): array
    {
        $result = [];
        foreach ($this->db->query('SELECT * FROM revizii_v2_planuri ORDER BY activ DESC, nume')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['reguli'] = [];
            $result[$row['id']] = $row;
        }
        foreach ($this->db->query('SELECT * FROM revizii_v2_plan_reguli')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($result[(int) $row['plan_id']])) {
                $result[(int) $row['plan_id']]['reguli'][(int) $row['operatie_id']] = $row;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $rules operatie_id => regula
     */
    public function savePlan(?int $id, array $data, array $rules, ?int $userId): int
    {
        $fields = ['nume', 'tip_vehicul', 'marca', 'model', 'sablon_x_id', 'sablon_y_id', 'interval_km', 'interval_ore', 'interval_luni',
            'logica', 'y_prag_km', 'y_interval_km', 'y_toleranta_km', 'politica_x', 'sursa_km', 'prag_km', 'prag_ore', 'prag_zile', 'cost_estimat_x', 'cost_estimat_y', 'responsabili', 'activ'];
        $params = [':updated_by' => $userId];
        foreach ($fields as $field) {
            $params[':' . $field] = $data[$field] ?? null;
        }
        $before = $id !== null ? ($this->plans()[$id] ?? null) : null;
        if ($id === null) {
            $stmt = $this->db->prepare('INSERT INTO revizii_v2_planuri (' . implode(', ', $fields) . ', updated_by) VALUES (:' . implode(', :', $fields) . ', :updated_by)');
            $stmt->execute($params);
            $id = (int) $this->db->lastInsertId();
        } else {
            $params[':id'] = $id;
            $sets = implode(', ', array_map(static fn (string $f): string => $f . ' = :' . $f, $fields));
            $this->db->prepare('UPDATE revizii_v2_planuri SET ' . $sets . ', updated_by = :updated_by WHERE id = :id')->execute($params);
        }
        $this->db->prepare('DELETE FROM revizii_v2_plan_reguli WHERE plan_id = :p')->execute([':p' => $id]);
        $insert = $this->db->prepare(
            'INSERT INTO revizii_v2_plan_reguli (plan_id, operatie_id, activ, interval_km, interval_ore, interval_luni, logica, coef_ralanti_km, cantitate, um, produs_id, cost_estimat)
             VALUES (:p, :o, :a, :km, :ore, :luni, :log, :coef, :q, :um, :prod, :cost)'
        );
        foreach ($rules as $operationId => $rule) {
            $insert->execute([
                ':p' => $id, ':o' => $operationId, ':a' => (int) $rule['activ'], ':km' => $rule['interval_km'], ':ore' => $rule['interval_ore'],
                ':luni' => $rule['interval_luni'], ':log' => $rule['logica'], ':coef' => $rule['coef_ralanti_km'], ':q' => $rule['cantitate'],
                ':um' => $rule['um'], ':prod' => $rule['produs_id'], ':cost' => $rule['cost_estimat'],
            ]);
        }
        $this->audit($id, 'plan', 'Plan de mentenanță: ' . $data['nume'], $before, $data + ['reguli' => $rules], $userId);

        return $id;
    }

    // ================================================================= lucrari si executii

    /**
     * Reviziile finalizate complet (X si Y), in ordinea executiei, per vehicul — sursa secventei
     * reale si a referintelor (ultima revizie, ultima Y, X efectuate de la ultima Y).
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function completeRevisions(): array
    {
        $rows = $this->db->query(
            "SELECT id, vehicle_id, tip, nr_x, nr_y, data_executie, km_executie, ore_executie FROM revizii_v2_lucrari
             WHERE completa = 1 AND status = 'finalizata' ORDER BY vehicle_id, data_executie, id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['vehicle_id']][] = $row;
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> vehicle_id => lucrarea deschisa (programata / in desfasurare) */
    public function openWorkOrders(): array
    {
        $result = [];
        foreach ($this->db->query("SELECT * FROM revizii_v2_lucrari WHERE status IN ('programata', 'in_desfasurare')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['vehicle_id']] = $row;
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function workOrder(int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM revizii_v2_lucrari WHERE id = :id' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $items = $this->db->prepare('SELECT * FROM revizii_v2_lucrari_operatii WHERE lucrare_id = :id ORDER BY FIELD(sectiune, \'X\', \'Y\', \'S\'), ordine, id');
        $items->execute([':id' => $id]);
        $row['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
        $docs = $this->db->prepare('SELECT id, nume_original, mime, marime, uploaded_at FROM revizii_v2_documente WHERE lucrare_id = :id AND deleted_at IS NULL ORDER BY id');
        $docs->execute([':id' => $id]);
        $row['documente'] = $docs->fetchAll(PDO::FETCH_ASSOC);

        return $row;
    }

    /**
     * Ultima executie care reseteaza referinta, per vehicul × operatiune (neanulata).
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function lastResets(): array
    {
        $rows = $this->db->query(
            "SELECT e.* FROM revizii_v2_executii e
             JOIN (SELECT vehicle_id, operatie_id, MAX(CONCAT(DATE_FORMAT(data_executie, '%Y%m%d%H%i%s'), LPAD(id, 10, '0'))) AS k
                   FROM revizii_v2_executii WHERE reseteaza = 1 AND anulat_la IS NULL GROUP BY vehicle_id, operatie_id) x
               ON x.vehicle_id = e.vehicle_id AND x.operatie_id = e.operatie_id
              AND x.k = CONCAT(DATE_FORMAT(e.data_executie, '%Y%m%d%H%i%s'), LPAD(e.id, 10, '0'))"
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['vehicle_id']][(int) $row['operatie_id']] = $row;
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function executions(int $vehicleId, ?int $operationId = null, int $limit = 200): array
    {
        $sql = 'SELECT e.*, o.componenta, o.operatie, u.nume AS created_by_name, l.tip AS lucrare_tip, l.nr_x, l.nr_y
                FROM revizii_v2_executii e
                JOIN revizii_v2_operatii o ON o.id = e.operatie_id
                LEFT JOIN revizii_v2_lucrari l ON l.id = e.lucrare_id
                LEFT JOIN utilizatori u ON u.id = e.created_by
                WHERE e.vehicle_id = :v' . ($operationId !== null ? ' AND e.operatie_id = :o' : '') . '
                ORDER BY e.data_executie DESC, e.id DESC LIMIT ' . max(1, $limit);
        $stmt = $this->db->prepare($sql);
        $params = [':v' => $vehicleId];
        if ($operationId !== null) {
            $params[':o'] = $operationId;
        }
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $data */
    public function insertExecution(array $data): int
    {
        $existing = $this->db->prepare('SELECT id FROM revizii_v2_executii WHERE idempotency_key = :k');
        $existing->execute([':k' => $data['idempotency_key']]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO revizii_v2_executii (vehicle_id, operatie_id, lucrare_id, tip_executie, reseteaza, data_executie, km, km_sursa, ore, cantitate, um,
                produs_id, miscare_id, cost, tehnician, observatii, idempotency_key, created_by)
             VALUES (:v, :o, :l, :t, :r, :d, :km, :ks, :ore, :q, :um, :p, :m, :c, :teh, :obs, :k, :u)'
        );
        $stmt->execute([
            ':v' => $data['vehicle_id'], ':o' => $data['operatie_id'], ':l' => $data['lucrare_id'] ?? null, ':t' => $data['tip_executie'],
            ':r' => !empty($data['reseteaza']) ? 1 : 0, ':d' => $data['data_executie'], ':km' => $data['km'] ?? null, ':ks' => $data['km_sursa'] ?? null,
            ':ore' => $data['ore'] ?? null, ':q' => $data['cantitate'] ?? null, ':um' => $data['um'] ?? null, ':p' => $data['produs_id'] ?? null,
            ':m' => $data['miscare_id'] ?? null, ':c' => $data['cost'] ?? null, ':teh' => $data['tehnician'] ?? null, ':obs' => $data['observatii'] ?? null,
            ':k' => $data['idempotency_key'], ':u' => $data['created_by'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Costurile din luna curenta: lucrari finalizate + executii independente (fara lucrare). */
    public function monthCosts(string $monthStart, string $monthEnd): array
    {
        $orders = $this->db->prepare(
            "SELECT id, vehicle_id, tip, cost_manopera, cost_materiale, cost_total FROM revizii_v2_lucrari
             WHERE status IN ('finalizata', 'partiala') AND data_executie >= :f AND data_executie < :t"
        );
        $orders->execute([':f' => $monthStart, ':t' => $monthEnd]);
        $single = $this->db->prepare(
            'SELECT e.vehicle_id, e.cost, o.categorie, o.tip_operatie FROM revizii_v2_executii e JOIN revizii_v2_operatii o ON o.id = e.operatie_id
             WHERE e.lucrare_id IS NULL AND e.anulat_la IS NULL AND e.cost IS NOT NULL AND e.data_executie >= :f AND e.data_executie < :t'
        );
        $single->execute([':f' => $monthStart, ':t' => $monthEnd]);

        return ['lucrari' => $orders->fetchAll(PDO::FETCH_ASSOC), 'executii' => $single->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** @return array<string, float> 'X'/'Y' => cost mediu istoric per plan (lucrari finalizate cu cost) */
    public function averageCosts(): array
    {
        $result = [];
        foreach ($this->db->query("SELECT plan_id, tip, AVG(cost_total) AS c FROM revizii_v2_lucrari WHERE status = 'finalizata' AND cost_total > 0 GROUP BY plan_id, tip")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['plan_id'] . ':' . $row['tip']] = (float) $row['c'];
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function audit(int $recordId, string $action, string $description, ?array $before, ?array $after, ?int $userId): void
    {
        if (function_exists('audit_log_write')) {
            audit_log_write($this->db, 'revizii_v2', $recordId, $action, mb_substr($description, 0, 255), $before, $after, $userId);
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO audit_log (modul, record_id, actiune, descriere, before_data, after_data, user_id, created_at)
             VALUES (\'revizii_v2\', :r, :a, :d, :b, :af, :u, NOW())'
        );
        $stmt->execute([
            ':r' => $recordId, ':a' => $action, ':d' => mb_substr($description, 0, 255),
            ':b' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            ':af' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null, ':u' => $userId,
        ]);
    }
}
