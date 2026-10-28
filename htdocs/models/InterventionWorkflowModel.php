<?php
declare(strict_types=1);

/**
 * Interventii V2 (?page=mentenanta&action=interventions): fluxul hibrid
 * dispecer (sesizare) -> coordonator tehnic (diagnostic, lucrari pe componente, finalizare).
 *
 * Tabela de baza ramane mentenanta_interventii_programate (ID-urile vechi raman valabile).
 * Statusurile vechi nu se reinterpreteaza in BD, ci se mapeaza explicit la afisare:
 * confirmata = Programata, in_lucru = In service (asa o pune si Km service).
 * Lucrarile stau in mentenanta_interventii_lucrari, pe componentele din catalogul
 * Reparatii Auto (mentenanta_auto_componente) — nu exista un al doilea catalog.
 */
class InterventionWorkflowModel extends BaseModel
{
    public const STATUS_LABELS = [
        'de_verificat' => 'De verificat',
        'programata' => 'Programată',
        'in_service' => 'În service',
        'in_asteptare' => 'În așteptare',
        'finalizata' => 'Finalizată',
        'anulata' => 'Anulată',
    ];

    /** Cheia de afisare -> valorile salvate (inclusiv cele vechi). */
    public const STATUS_STORED = [
        'de_verificat' => ['de_verificat'],
        'programata' => ['programata', 'confirmata'],
        'in_service' => ['in_lucru'],
        'in_asteptare' => ['in_asteptare'],
        'finalizata' => ['finalizata'],
        'anulata' => ['anulata'],
    ];

    public const PRIORITY_LABELS = [
        'scazuta' => 'Scăzută',
        'normala' => 'Normală',
        'ridicata' => 'Ridicată',
        'critica' => 'Critică',
    ];

    public const ASSESSMENT_LABELS = [
        'suspectata' => 'Suspectată',
        'confirmata' => 'Confirmată',
        'exclusa' => 'Exclusă',
    ];

    public const OPERATION_LABELS = [
        'verificare' => 'Verificare',
        'diagnosticare' => 'Diagnosticare',
        'reglare' => 'Reglare',
        'reparatie' => 'Reparație',
        'inlocuire' => 'Înlocuire',
    ];

    public const PROGRESS_LABELS = [
        'neinceputa' => 'Neîncepută',
        'in_lucru' => 'În lucru',
        'terminata' => 'Terminată',
    ];

    public const RESULT_LABELS = [
        'executata' => 'Executată',
        'amanata' => 'Amânată',
        'respinsa' => 'Respinsă',
        'nenecesara' => 'Nu a fost necesară',
    ];

    public const DOCUMENT_LABELS = [
        'deviz' => 'Deviz service',
        'factura' => 'Factură',
        'comanda' => 'Comandă de lucru',
        'foto' => 'Fotografie',
        'raport' => 'Raport reparație',
        'altul' => 'Alt document',
    ];

    public const AVAILABILITY_LABELS = [
        'disponibil' => 'Disponibil',
        'indisponibil' => 'Indisponibil',
    ];

    /** Statusurile in care un utilizator nu poate duce interventia fara responsabil tehnic. */
    private const STATUSES_REQUIRING_COORDINATOR = ['programata', 'confirmata', 'in_lucru', 'in_asteptare', 'finalizata'];

    private const LIST_SELECT = "
        SELECT i.id, i.vehicle_id, i.tip_interventie, i.prioritate, i.data_programata, i.ora_programata,
               i.furnizor, i.descriere, i.status_interventie, i.service_km_id, i.responsabil_user_id,
               i.cost_estimat, i.centru_cost, i.client, i.created_at, i.converted_maintenance_id,
               i.interventie_origine_id,
               v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul,
               u.nume AS responsabil_nume, s.nume AS sofer_nume,
               (SELECT COUNT(*) FROM mentenanta_interventii_lucrari l
                 WHERE l.intervention_id = i.id AND l.stare_evaluare <> 'exclusa') AS componente_count";

    private static bool $schemaReady = false;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        self::ensureSchema($db);
    }

    // ------------------------------------------------------------------ schema

    /**
     * Migrarea database/migrations/2026_10_28_000001_interventii_v2.sql, aplicata la nevoie.
     * Doar DDL aditiv, verificat in information_schema; nu se apeleaza intr-o tranzactie.
     */
    public static function ensureSchema(PDO $db): void
    {
        if (self::$schemaReady) {
            return;
        }
        $table = 'mentenanta_interventii_programate';
        if (!self::hasTable($db, $table)) {
            return; // MaintenanceModel o creeaza; revenim la urmatoarea cerere
        }

        $type = self::columnType($db, $table, 'status_interventie');
        if ($type !== null && !str_contains($type, 'de_verificat')) {
            $db->exec(
                "ALTER TABLE mentenanta_interventii_programate
                 MODIFY status_interventie ENUM('de_verificat','programata','confirmata','in_lucru','in_asteptare','finalizata','anulata')
                 NOT NULL DEFAULT 'programata'"
            );
        }

        $columns = [
            'prioritate' => "ENUM('scazuta','normala','ridicata','critica') NOT NULL DEFAULT 'normala' AFTER tip_interventie",
            'ora_programata' => 'TIME NULL AFTER data_programata',
            'cost_suplimentar' => 'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER cost_estimat',
            'responsabil_user_id' => 'INT UNSIGNED NULL AFTER driver_id',
            'sesizare_initiala' => 'TEXT NULL AFTER descriere',
            'sesizare_la' => 'DATETIME NULL AFTER sesizare_initiala',
            'sesizare_de' => 'INT UNSIGNED NULL AFTER sesizare_la',
            'diagnostic_tehnic' => 'TEXT NULL AFTER sesizare_de',
            'diagnostic_la' => 'DATETIME NULL AFTER diagnostic_tehnic',
            'diagnostic_de' => 'INT UNSIGNED NULL AFTER diagnostic_la',
            'disponibilitate_vehicul' => "ENUM('disponibil','indisponibil') NULL AFTER status_interventie",
            'interventie_origine_id' => 'INT UNSIGNED NULL AFTER service_km_id',
            'data_executie' => 'DATE NULL AFTER interventie_origine_id',
            'finalizat_la' => 'DATETIME NULL AFTER data_executie',
            'finalizat_de' => 'INT UNSIGNED NULL AFTER finalizat_la',
            'updated_by' => 'INT UNSIGNED NULL AFTER created_by',
        ];
        foreach ($columns as $column => $definition) {
            if (self::columnType($db, $table, $column) === null) {
                $db->exec('ALTER TABLE mentenanta_interventii_programate ADD COLUMN `' . $column . '` ' . $definition);
                if ($column === 'responsabil_user_id') {
                    $db->exec('ALTER TABLE mentenanta_interventii_programate ADD INDEX idx_ment_prog_responsabil (responsabil_user_id)');
                    try {
                        $db->exec(
                            'ALTER TABLE mentenanta_interventii_programate
                             ADD CONSTRAINT fk_ment_prog_responsabil FOREIGN KEY (responsabil_user_id)
                             REFERENCES utilizatori (id) ON DELETE SET NULL'
                        );
                    } catch (Throwable $exception) {
                        error_log('[InterventionWorkflowModel] fk responsabil: ' . $exception->getMessage());
                    }
                }
                if ($column === 'interventie_origine_id') {
                    $db->exec('ALTER TABLE mentenanta_interventii_programate ADD INDEX idx_ment_prog_origine (interventie_origine_id)');
                }
            }
        }

        if (!self::hasTable($db, 'mentenanta_auto_componente')) {
            (new AutoComponentCatalogService($db))->components(true); // creeaza + populeaza catalogul
        }
        $componentFk = self::hasTable($db, 'mentenanta_auto_componente')
            ? ', CONSTRAINT fk_ment_lucr_component FOREIGN KEY (component_key)
                 REFERENCES mentenanta_auto_componente (component_key) ON UPDATE CASCADE'
            : '';
        // CREATE TABLE (chiar IF NOT EXISTS) face COMMIT implicit: doar cand lipseste.
        if (!self::hasTable($db, 'mentenanta_interventii_lucrari')) {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS mentenanta_interventii_lucrari (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    intervention_id INT UNSIGNED NOT NULL,
                    component_key VARCHAR(10) COLLATE utf8mb4_unicode_ci NULL,
                    ramura_principala VARCHAR(20) NULL,
                    denumire_libera VARCHAR(190) NULL,
                    pozitie VARCHAR(80) NULL,
                    stare_evaluare ENUM('suspectata','confirmata','exclusa') NOT NULL DEFAULT 'suspectata',
                    operatiune ENUM('verificare','diagnosticare','reglare','reparatie','inlocuire') NOT NULL DEFAULT 'verificare',
                    diagnostic TEXT NULL,
                    progres ENUM('neinceputa','in_lucru','terminata') NOT NULL DEFAULT 'neinceputa',
                    rezultat ENUM('executata','amanata','respinsa','nenecesara') NULL,
                    piesa_montata VARCHAR(190) NULL,
                    piesa_demontata VARCHAR(190) NULL,
                    data_montare DATE NULL,
                    km_montare INT UNSIGNED NULL,
                    cost_piese DECIMAL(12,2) NOT NULL DEFAULT 0,
                    cost_manopera DECIMAL(12,2) NOT NULL DEFAULT 0,
                    garantie_luni SMALLINT UNSIGNED NULL,
                    garantie_pana_la DATE NULL,
                    observatii TEXT NULL,
                    maintenance_id INT UNSIGNED NULL,
                    usage_id BIGINT UNSIGNED NULL,
                    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    created_by INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_ment_lucr_interventie (intervention_id),
                    INDEX idx_ment_lucr_component (component_key),
                    UNIQUE KEY uq_ment_lucr_usage (usage_id),
                    CONSTRAINT fk_ment_lucr_interventie FOREIGN KEY (intervention_id)
                        REFERENCES mentenanta_interventii_programate (id) ON DELETE CASCADE" . $componentFk . "
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        if (!self::hasTable($db, 'mentenanta_interventii_istoric')) {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS mentenanta_interventii_istoric (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    intervention_id INT UNSIGNED NOT NULL,
                    tip VARCHAR(40) NOT NULL,
                    descriere VARCHAR(500) NOT NULL,
                    detalii JSON NULL,
                    user_id INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_ment_ist_interventie (intervention_id, created_at),
                    CONSTRAINT fk_ment_ist_interventie FOREIGN KEY (intervention_id)
                        REFERENCES mentenanta_interventii_programate (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        if (!self::hasTable($db, 'mentenanta_interventii_documente')) {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS mentenanta_interventii_documente (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    intervention_id INT UNSIGNED NOT NULL,
                    tip ENUM('deviz','factura','comanda','foto','raport','altul') NOT NULL DEFAULT 'altul',
                    fisier_original VARCHAR(255) NOT NULL,
                    fisier_stocat VARCHAR(255) NOT NULL,
                    marime INT UNSIGNED NOT NULL DEFAULT 0,
                    uploaded_by INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_ment_doc_interventie (intervention_id),
                    CONSTRAINT fk_ment_doc_interventie FOREIGN KEY (intervention_id)
                        REFERENCES mentenanta_interventii_programate (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        // Km service V2: componenta poate fi pe semiremorca cuplata (vehicle_id NULL = vehiculul
        // interventiei); origine = cine a cerut lucrarea (km_service = dispecerul, din Km service).
        $itemColumns = [
            'vehicle_id' => 'INT UNSIGNED NULL AFTER intervention_id',
            'origine' => 'VARCHAR(20) NULL AFTER ramura_principala',
        ];
        foreach ($itemColumns as $column => $definition) {
            if (self::columnType($db, 'mentenanta_interventii_lucrari', $column) === null) {
                $db->exec('ALTER TABLE mentenanta_interventii_lucrari ADD COLUMN `' . $column . '` ' . $definition);
            }
        }

        self::$schemaReady = true;
    }

    private static function hasTable(PDO $db, string $table): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
        $stmt->execute([':t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnType(PDO $db, string $table, string $column): ?string
    {
        $stmt = $db->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute([':t' => $table, ':c' => $column]);
        $type = $stmt->fetchColumn();
        return $type === false ? null : (string) $type;
    }

    // ------------------------------------------------------------------ mapari

    public static function statusKey(string $stored): string
    {
        foreach (self::STATUS_STORED as $key => $values) {
            if (in_array($stored, $values, true)) {
                return $key;
            }
        }
        return 'de_verificat';
    }

    /** Cheia de afisare -> valoarea salvata pentru o schimbare noua de status. */
    public static function storedStatus(string $key): string
    {
        return match ($key) {
            'in_service' => 'in_lucru',
            'programata', 'de_verificat', 'in_asteptare', 'finalizata', 'anulata' => $key,
            default => 'de_verificat',
        };
    }

    // ------------------------------------------------------------------ lista + KPI

    /**
     * @return array{rows:array<int,array>, total:int, kpis:array<string,int>, page:int, per_page:int}
     */
    public function listInterventions(array $filters, int $page = 1, int $perPage = 10): array
    {
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
        [$where, $params] = $this->buildListWhere($filters, true);
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $from = "
            FROM mentenanta_interventii_programate i
            INNER JOIN vehicule v ON v.id = i.vehicle_id
            LEFT JOIN utilizatori u ON u.id = i.responsabil_user_id
            LEFT JOIN soferi s ON s.id = i.driver_id";

        $count = $this->db->prepare('SELECT COUNT(*) ' . $from . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            self::LIST_SELECT . $from . $whereSql . ' ORDER BY i.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $rows = array_map(fn (array $row): array => $this->decorateListRow($row), $stmt->fetchAll());

        return [
            'rows' => $rows,
            'total' => $total,
            'kpis' => $this->kpis($filters),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** Toate randurile filtrate (export CSV), fara paginare. */
    public function exportRows(array $filters): array
    {
        [$where, $params] = $this->buildListWhere($filters, true);
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare(
            self::LIST_SELECT . ", i.cost_suplimentar, i.diagnostic_tehnic,
                (SELECT GROUP_CONCAT(COALESCE(c.nume, l.denumire_libera) ORDER BY l.sort_order, l.id SEPARATOR ', ')
                   FROM mentenanta_interventii_lucrari l
                   LEFT JOIN mentenanta_auto_componente c ON c.component_key = l.component_key
                  WHERE l.intervention_id = i.id AND l.stare_evaluare <> 'exclusa') AS componente_lista
             FROM mentenanta_interventii_programate i
             INNER JOIN vehicule v ON v.id = i.vehicle_id
             LEFT JOIN utilizatori u ON u.id = i.responsabil_user_id
             LEFT JOIN soferi s ON s.id = i.driver_id" . $whereSql . ' ORDER BY i.id DESC'
        );
        $stmt->execute($params);
        return array_map(fn (array $row): array => $this->decorateListRow($row), $stmt->fetchAll());
    }

    /** KPI-urile cu aceleasi filtre ca tabelul, mai putin statusul (cardul ESTE filtrul de status). */
    public function kpis(array $filters): array
    {
        [$where, $params] = $this->buildListWhere($filters, false);
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $stmt = $this->db->prepare(
            "SELECT i.status_interventie AS status, COUNT(*) AS n
             FROM mentenanta_interventii_programate i
             INNER JOIN vehicule v ON v.id = i.vehicle_id" . $whereSql . '
             GROUP BY i.status_interventie'
        );
        $stmt->execute($params);
        $kpis = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach ($stmt->fetchAll() as $row) {
            $kpis[self::statusKey((string) $row['status'])] += (int) $row['n'];
        }
        return $kpis;
    }

    private function buildListWhere(array $filters, bool $withStatus): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['vehicle_id'])) {
            $where[] = 'i.vehicle_id = :f_vehicle';
            $params[':f_vehicle'] = (int) $filters['vehicle_id'];
        }
        if (in_array($filters['tip'] ?? '', ['intretinere', 'reparatie'], true)) {
            $where[] = 'i.tip_interventie = :f_tip';
            $params[':f_tip'] = (string) $filters['tip'];
        }
        if ($withStatus && isset(self::STATUS_STORED[$filters['status'] ?? ''])) {
            $placeholders = [];
            foreach (self::STATUS_STORED[$filters['status']] as $index => $value) {
                $placeholders[] = ':f_status' . $index;
                $params[':f_status' . $index] = $value;
            }
            $where[] = 'i.status_interventie IN (' . implode(', ', $placeholders) . ')';
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'i.data_programata >= :f_from';
            $params[':f_from'] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'i.data_programata <= :f_to';
            $params[':f_to'] = (string) $filters['date_to'];
        }
        $responsible = (string) ($filters['responsabil'] ?? '');
        if ($responsible === 'nealocat') {
            $where[] = 'i.responsabil_user_id IS NULL';
        } elseif ((int) $responsible > 0) {
            $where[] = 'i.responsabil_user_id = :f_resp';
            $params[':f_resp'] = (int) $responsible;
        }
        if (in_array($filters['sursa'] ?? '', ['km_service', 'manual'], true)) {
            $where[] = $filters['sursa'] === 'km_service' ? 'i.service_km_id IS NOT NULL' : 'i.service_km_id IS NULL';
        }

        // Fiecare cuvant cautat trebuie sa apara undeva: nr. auto, model, ID, componenta, cod, service, problema.
        $terms = preg_split('/\s+/u', trim((string) ($filters['search'] ?? ''))) ?: [];
        foreach (array_slice(array_values(array_filter($terms, static fn ($t) => $t !== '')), 0, 6) as $n => $term) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term, 'UTF-8')) . '%';
            $fields = [
                "LOWER(REPLACE(v.nr_inmatriculare, ' ', '')) LIKE :s{$n}a",
                "LOWER(CONCAT_WS(' ', v.marca, v.model)) LIKE :s{$n}b",
                "CONCAT('#', i.id) LIKE :s{$n}c",
                "LOWER(i.descriere) LIKE :s{$n}d",
                "LOWER(COALESCE(i.furnizor, '')) LIKE :s{$n}e",
                "LOWER(COALESCE(i.sesizare_initiala, '')) LIKE :s{$n}f",
                "EXISTS (SELECT 1 FROM mentenanta_interventii_lucrari sl
                          LEFT JOIN mentenanta_auto_componente sc ON sc.component_key = sl.component_key
                         WHERE sl.intervention_id = i.id
                           AND LOWER(CONCAT_WS(' ', sc.nume, sc.cod, sl.denumire_libera, sl.pozitie)) LIKE :s{$n}g)",
            ];
            $where[] = '(' . implode(' OR ', $fields) . ')';
            $plain = str_replace(' ', '', $like);
            foreach (['a' => $plain, 'b' => $like, 'c' => $like, 'd' => $like, 'e' => $like, 'f' => $like, 'g' => $like] as $suffix => $value) {
                $params[':s' . $n . $suffix] = $value;
            }
        }

        return [$where, $params];
    }

    private function decorateListRow(array $row): array
    {
        $statusKey = self::statusKey((string) $row['status_interventie']);
        $row['id'] = (int) $row['id'];
        $row['status_key'] = $statusKey;
        $row['status_label'] = self::STATUS_LABELS[$statusKey];
        $row['status_legacy'] = $row['status_interventie'] === 'confirmata' ? 'Confirmată' : null;
        $row['prioritate'] = isset(self::PRIORITY_LABELS[$row['prioritate'] ?? '']) ? (string) $row['prioritate'] : 'normala';
        $row['prioritate_label'] = self::PRIORITY_LABELS[$row['prioritate']];
        $row['sursa'] = !empty($row['service_km_id']) ? 'km_service' : 'manual';
        $row['data_sesizare'] = substr((string) ($row['created_at'] ?? ''), 0, 10);
        $row['ora_programata'] = $row['ora_programata'] !== null ? substr((string) $row['ora_programata'], 0, 5) : null;
        $row['vehicul_tip_label'] = self::vehicleTypeLabel((string) ($row['tip_vehicul'] ?? ''));
        $row['vehicul_model'] = trim((string) ($row['marca'] ?? '') . ' ' . (string) ($row['model'] ?? ''));
        $row['responsabil_scurt'] = self::shortName((string) ($row['responsabil_nume'] ?? ''));
        $row['componente_count'] = (int) ($row['componente_count'] ?? 0);
        return $row;
    }

    public static function vehicleTypeLabel(string $type): string
    {
        return match ($type) {
            'cap_tractor' => 'Cap tractor',
            'camion' => 'Camion',
            'semiremorca', 'semiremorca_primar', 'semiremorca_distributie' => 'Semiremorcă',
            'autoutilitara' => 'Autoutilitară',
            'autovehicul' => 'Autovehicul',
            default => $type !== '' ? ucfirst(str_replace('_', ' ', $type)) : '-',
        };
    }

    /** "Voinea Beniamin" -> "Voinea B." */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        if ($parts === [] || $parts[0] === '') {
            return '';
        }
        return count($parts) > 1 ? $parts[0] . ' ' . mb_substr($parts[1], 0, 1, 'UTF-8') . '.' : $parts[0];
    }

    // ------------------------------------------------------------------ optiuni

    /** Responsabilii tehnici posibili: utilizatori activi cu dreptul de coordonare a interventiilor. */
    public function getCoordinators(): array
    {
        $rows = $this->db->query("SELECT id, nume, rol, status FROM utilizatori WHERE status = 'activ' ORDER BY nume ASC")->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            try {
                $allowed = function_exists('user_can') ? user_can($row, 'mentenanta', 'interventions_technical') : true;
            } catch (Throwable $exception) {
                error_log('[InterventionWorkflowModel] coordinators: ' . $exception->getMessage());
                $allowed = (string) $row['rol'] === 'admin';
            }
            if ($allowed) {
                $result[] = ['id' => (int) $row['id'], 'nume' => (string) $row['nume'], 'scurt' => self::shortName((string) $row['nume'])];
            }
        }
        return $result;
    }

    public function isCoordinator(int $userId): bool
    {
        foreach ($this->getCoordinators() as $coordinator) {
            if ($coordinator['id'] === $userId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Catalogul de componente (cel din Reparatii Auto) pentru selector: categorii + componente active.
     * Filtrarea pe vehicul (tip -> ramuri / categorii posibile) se face in browser.
     */
    public function componentCatalog(): array
    {
        $service = new AutoComponentCatalogService($this->db);
        $categories = [];
        foreach ($service->categories() as $category) {
            $categoryId = (int) $category['id'];
            $components = [];
            foreach ((array) $category['components'] as $component) {
                $components[] = [
                    'key' => (string) ($component['id'] ?? $component['key'] ?? ''),
                    'name' => (string) ($component['name'] ?? ''),
                    'code' => (string) ($component['code'] ?? ''),
                    // "-" = nu se aplica tipului de vehicul (ex. Livrare Gaz pe cap tractor)
                    'mon' => (array) ($component['monitoring_by_vehicle'] ?? []),
                ];
            }
            $categories[] = [
                'id' => $categoryId,
                'name' => (string) $category['name'],
                'icon' => (string) ($category['icon'] ?? 'bi-circle'),
                'subcategory' => AutoComponentCatalogService::subcategoryFor($categoryId),
                'components' => $components,
            ];
        }

        return [
            'primary' => AutoComponentCatalogService::PRIMARY_LABELS,
            'subcategories' => AutoComponentCatalogService::SUBCATEGORY_LABELS,
            'groups' => AutoComponentCatalogService::CATEGORY_GROUPS,
            'categories' => $categories,
        ];
    }

    /** Cheile componentelor configurate pe vehicul in Reparatii Auto (mentenanta_auto_configurari). */
    public function configuredComponentKeys(int $vehicleId): array
    {
        if ($vehicleId <= 0 || !self::hasTable($this->db, 'mentenanta_auto_configurari')) {
            return [];
        }
        $stmt = $this->db->prepare('SELECT component_key, primary_category FROM mentenanta_auto_configurari WHERE vehicle_id = :v');
        $stmt->execute([':v' => $vehicleId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['component_key']] = (string) $row['primary_category'];
        }
        return $result;
    }

    // ------------------------------------------------------------------ detalii

    public function getIntervention(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mentenanta_interventii_programate WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function detail(int $id, bool $withCosts): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT i.*, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul, v.km_bord,
                    u.nume AS responsabil_nume, cu.nume AS creat_de_nume, su.nume AS sesizare_de_nume,
                    du.nume AS diagnostic_de_nume, fu.nume AS finalizat_de_nume, s.nume AS sofer_nume
             FROM mentenanta_interventii_programate i
             INNER JOIN vehicule v ON v.id = i.vehicle_id
             LEFT JOIN utilizatori u ON u.id = i.responsabil_user_id
             LEFT JOIN utilizatori cu ON cu.id = i.created_by
             LEFT JOIN utilizatori su ON su.id = i.sesizare_de
             LEFT JOIN utilizatori du ON du.id = i.diagnostic_de
             LEFT JOIN utilizatori fu ON fu.id = i.finalizat_de
             LEFT JOIN soferi s ON s.id = i.driver_id
             WHERE i.id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $statusKey = self::statusKey((string) $row['status_interventie']);
        $intervention = [
            'id' => (int) $row['id'],
            'vehicle_id' => (int) $row['vehicle_id'],
            'nr_inmatriculare' => (string) $row['nr_inmatriculare'],
            'vehicul_model' => trim((string) $row['marca'] . ' ' . (string) $row['model']),
            'vehicul_tip' => (string) $row['tip_vehicul'],
            'vehicul_tip_label' => self::vehicleTypeLabel((string) $row['tip_vehicul']),
            'km_bord' => $row['km_bord'] !== null ? (int) $row['km_bord'] : null,
            'tip_interventie' => (string) $row['tip_interventie'],
            'prioritate' => (string) ($row['prioritate'] ?? 'normala'),
            'data_programata' => (string) $row['data_programata'],
            'ora_programata' => $row['ora_programata'] !== null ? substr((string) $row['ora_programata'], 0, 5) : '',
            'furnizor' => (string) ($row['furnizor'] ?? ''),
            'driver_id' => $row['driver_id'] !== null ? (int) $row['driver_id'] : null,
            'sofer_nume' => (string) ($row['sofer_nume'] ?? ''),
            'client' => (string) ($row['client'] ?? ''),
            'centru_cost' => (string) ($row['centru_cost'] ?? ''),
            'descriere' => (string) $row['descriere'],
            'sesizare_initiala' => $row['sesizare_initiala'] !== null ? (string) $row['sesizare_initiala'] : null,
            'sesizare_la' => $row['sesizare_la'] ?? null,
            'sesizare_de_nume' => (string) ($row['sesizare_de_nume'] ?? ''),
            'diagnostic_tehnic' => (string) ($row['diagnostic_tehnic'] ?? ''),
            'diagnostic_la' => $row['diagnostic_la'] ?? null,
            'diagnostic_de_nume' => (string) ($row['diagnostic_de_nume'] ?? ''),
            'status' => (string) $row['status_interventie'],
            'status_key' => $statusKey,
            'status_label' => self::STATUS_LABELS[$statusKey],
            'disponibilitate_vehicul' => $row['disponibilitate_vehicul'] ?? null,
            'responsabil_user_id' => $row['responsabil_user_id'] !== null ? (int) $row['responsabil_user_id'] : null,
            'responsabil_nume' => (string) ($row['responsabil_nume'] ?? ''),
            'sursa' => !empty($row['service_km_id']) ? 'km_service' : 'manual',
            'service_km_id' => $row['service_km_id'] !== null ? (int) $row['service_km_id'] : null,
            'interventie_origine_id' => $row['interventie_origine_id'] !== null ? (int) $row['interventie_origine_id'] : null,
            'converted_maintenance_id' => $row['converted_maintenance_id'] !== null ? (int) $row['converted_maintenance_id'] : null,
            'data_executie' => $row['data_executie'] ?? null,
            'finalizat_la' => $row['finalizat_la'] ?? null,
            'finalizat_de_nume' => (string) ($row['finalizat_de_nume'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'creat_de_nume' => (string) ($row['creat_de_nume'] ?? ''),
            'updated_at' => (string) $row['updated_at'],
            'editabila' => !in_array($statusKey, ['finalizata', 'anulata'], true),
        ];

        $items = $this->items($id);
        $result = [
            'intervention' => $intervention,
            'km_service' => $intervention['service_km_id'] !== null ? $this->serviceKmEntry($intervention['service_km_id']) : null,
            'items' => $items,
            'documents' => $this->documents($id, $intervention['converted_maintenance_id']),
            'history' => $this->history($id),
            'follow_ups' => $this->followUps($id),
        ];
        if ($withCosts) {
            $result['costs'] = $this->costs($row, $items);
        }

        return $result;
    }

    private function serviceKmEntry(int $serviceKmId): ?array
    {
        if (!self::hasTable($this->db, 'vehicule_service_km')) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT sk.*, u.nume AS creat_de_nume, s.nume AS sofer_nume
             FROM vehicule_service_km sk
             LEFT JOIN utilizatori u ON u.id = sk.created_by
             LEFT JOIN soferi s ON s.id = sk.driver_id
             WHERE sk.id = :id'
        );
        $stmt->execute([':id' => $serviceKmId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'data_plecare' => (string) $row['data_plecare'],
            'ora_plecare' => $row['ora_plecare'] !== null ? substr((string) $row['ora_plecare'], 0, 5) : '',
            'data_intoarcere' => $row['data_intoarcere'] ?? null,
            'ora_intoarcere' => $row['ora_intoarcere'] !== null ? substr((string) $row['ora_intoarcere'], 0, 5) : '',
            'km' => (float) $row['km'],
            'service_nume' => (string) ($row['service_nume'] ?? ''),
            'motiv' => (string) ($row['motiv'] ?? ''),
            'observatii' => (string) ($row['observatii'] ?? ''),
            'sofer_nume' => (string) ($row['sofer_nume'] ?? ''),
            'creat_de_nume' => (string) ($row['creat_de_nume'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ];
    }

    public function items(int $interventionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*, c.nume AS component_nume, c.cod AS component_cod, c.category_id, c.activ AS component_activ,
                    lv.nr_inmatriculare AS component_vehicul, lv.tip_vehicul AS component_vehicul_tip
             FROM mentenanta_interventii_lucrari l
             LEFT JOIN mentenanta_auto_componente c ON c.component_key = l.component_key
             LEFT JOIN vehicule lv ON lv.id = l.vehicle_id
             WHERE l.intervention_id = :id
             ORDER BY l.sort_order ASC, l.id ASC'
        );
        $stmt->execute([':id' => $interventionId]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $categoryId = $row['category_id'] !== null ? (int) $row['category_id'] : null;
            $subcategory = $categoryId !== null ? AutoComponentCatalogService::subcategoryFor($categoryId) : null;
            $items[] = [
                'id' => (int) $row['id'],
                'component_key' => $row['component_key'],
                'nume' => (string) ($row['component_nume'] ?? $row['denumire_libera'] ?? 'Lucrare'),
                'cod' => (string) ($row['component_cod'] ?? ''),
                'denumire_libera' => (string) ($row['denumire_libera'] ?? ''),
                'categorie' => $categoryId !== null ? (AutoComponentCatalogService::CATEGORY_NAMES[$categoryId] ?? '') : '',
                'category_id' => $categoryId,
                'ramura' => (string) ($row['ramura_principala'] ?? ''),
                'ramura_label' => AutoComponentCatalogService::PRIMARY_LABELS[(string) ($row['ramura_principala'] ?? '')] ?? '',
                'subcategorie' => $subcategory !== null ? (AutoComponentCatalogService::SUBCATEGORY_LABELS[$subcategory] ?? '') : '',
                'componenta_eliminata' => $row['component_activ'] !== null && (int) $row['component_activ'] === 0,
                'pozitie' => (string) ($row['pozitie'] ?? ''),
                'stare_evaluare' => (string) $row['stare_evaluare'],
                'operatiune' => (string) $row['operatiune'],
                'diagnostic' => (string) ($row['diagnostic'] ?? ''),
                'progres' => (string) $row['progres'],
                'rezultat' => $row['rezultat'] !== null ? (string) $row['rezultat'] : '',
                'piesa_montata' => (string) ($row['piesa_montata'] ?? ''),
                'piesa_demontata' => (string) ($row['piesa_demontata'] ?? ''),
                'data_montare' => $row['data_montare'] ?? '',
                'km_montare' => $row['km_montare'] !== null ? (int) $row['km_montare'] : null,
                'cost_piese' => (float) $row['cost_piese'],
                'cost_manopera' => (float) $row['cost_manopera'],
                'garantie_luni' => $row['garantie_luni'] !== null ? (int) $row['garantie_luni'] : null,
                'garantie_pana_la' => $row['garantie_pana_la'] ?? '',
                'observatii' => (string) ($row['observatii'] ?? ''),
                'usage_id' => $row['usage_id'] !== null ? (int) $row['usage_id'] : null,
                'maintenance_id' => $row['maintenance_id'] !== null ? (int) $row['maintenance_id'] : null,
                // Componenta de pe semiremorca cuplata (null = vehiculul interventiei).
                'vehicle_id' => $row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null,
                'vehicul_componenta' => $row['vehicle_id'] !== null ? (string) $row['component_vehicul'] : '',
                'vehicul_componenta_tip' => $row['vehicle_id'] !== null ? self::vehicleTypeLabel((string) $row['component_vehicul_tip']) : '',
                'origine' => (string) ($row['origine'] ?? ''),
                'dispecer_editabila' => self::isDispatcherEditable($row),
            ];
        }
        return $items;
    }

    /**
     * Lucrarea ceruta de dispecer pe care coordonatorul nu a atins-o inca: suspectata,
     * neinceputa, fara diagnostic, rezultat, cost sau montare. Doar pe acestea le mai poate
     * modifica / scoate dispecerul din Km service; restul sunt scopul tehnic confirmat.
     */
    public static function isDispatcherEditable(array $row): bool
    {
        return ($row['origine'] ?? null) === 'km_service'
            && $row['stare_evaluare'] === 'suspectata'
            && $row['progres'] === 'neinceputa'
            && ($row['rezultat'] ?? null) === null
            && ($row['usage_id'] ?? null) === null
            && trim((string) ($row['diagnostic'] ?? '')) === ''
            && (float) ($row['cost_piese'] ?? 0) == 0.0
            && (float) ($row['cost_manopera'] ?? 0) == 0.0;
    }

    // ------------------------------------------------------------------ Km service (dispecer)

    /** Ramurile din Reparatii Auto posibile pe un tip de vehicul (cap tractor = doar Sasiu). */
    public static function allowedPrimaries(string $vehicleType): array
    {
        return $vehicleType === 'cap_tractor' ? ['sasiu'] : ['sasiu', 'rezervor'];
    }

    /** Tipul auto (camion / cap_tractor / semiremorca / '') ca in Reparatii Auto. */
    public static function autoVehicleType(string $type): string
    {
        return match (true) {
            $type === 'camion', $type === 'cap_tractor' => $type,
            str_starts_with($type, 'semiremorca') => 'semiremorca',
            default => '',
        };
    }

    /**
     * Vehiculele active cu semiremorca cuplata acum (vehicule_cuplaje), pentru selectorul
     * de componente din Km service: componenta poate fi pe capul tractor sau pe semiremorca.
     *
     * @return array<int, array{plate:string, model:string, type:string, trailer:?array}>
     */
    public function vehicleContext(): array
    {
        $vehicles = [];
        foreach ($this->db->query("SELECT id, nr_inmatriculare, marca, model, tip_vehicul FROM vehicule WHERE status = 'activ'")->fetchAll() as $row) {
            $vehicles[(int) $row['id']] = [
                'plate' => (string) $row['nr_inmatriculare'],
                'model' => trim((string) $row['marca'] . ' ' . (string) $row['model']),
                'type' => (string) $row['tip_vehicul'],
                'trailer' => null,
            ];
        }
        foreach ($this->activeCouplings() as $tractorId => $trailerId) {
            if (isset($vehicles[$tractorId], $vehicles[$trailerId])) {
                $vehicles[$tractorId]['trailer'] = ['id' => $trailerId] + array_diff_key($vehicles[$trailerId], ['trailer' => true]);
            }
        }
        return $vehicles;
    }

    /** @return array<int,int> tractor_id => semiremorca_id (cuplajele active) */
    private function activeCouplings(): array
    {
        if (!self::hasTable($this->db, 'vehicule_cuplaje')) {
            return [];
        }
        $map = [];
        $rows = $this->db->query('SELECT tractor_id, semiremorca_id FROM vehicule_cuplaje WHERE activ = 1 AND data_end IS NULL ORDER BY data_start ASC')->fetchAll();
        foreach ($rows as $row) {
            $map[(int) $row['tractor_id']] = (int) $row['semiremorca_id'];
        }
        return $map;
    }

    /**
     * Valideaza componentele cerute de dispecer pentru vehiculul Km service: cheia exista in
     * registru, vehiculul componentei e vehiculul insusi sau semiremorca lui cuplata, iar
     * ramura (Sasiu / Rezervor) exista pe tipul acelui vehicul.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{item_id:int,key:string,primary:string,position:string,operation:string,vehicle_id:?int}>
     */
    public function normalizeDispatcherComponents(int $vehicleId, array $rows): array
    {
        $catalog = (new AutoComponentCatalogService($this->db))->components(true);
        $context = $this->vehicleContext();
        $vehicle = $context[$vehicleId] ?? null;
        $result = [];
        foreach (array_slice($rows, 0, 50) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemId = (int) ($row['item_id'] ?? 0);
            $key = trim((string) ($row['key'] ?? ''));
            $component = $catalog[$key] ?? null;
            if ($component === null || (!$component['active'] && $itemId <= 0)) {
                throw new InvalidArgumentException('Componenta „' . $key . '” nu mai există în Registru piese & lucrări.');
            }
            $targetId = (int) ($row['vehicle_id'] ?? 0);
            $targetId = $targetId > 0 ? $targetId : $vehicleId;
            $trailerId = (int) ($vehicle['trailer']['id'] ?? 0);
            if ($targetId !== $vehicleId && $targetId !== $trailerId) {
                throw new InvalidArgumentException('„' . $component['name'] . '” este aleasă pentru alt vehicul decât cel din Km service sau semiremorca lui cuplată. Alege din nou componenta.');
            }
            $targetType = self::autoVehicleType((string) (($targetId === $vehicleId ? $vehicle['type'] ?? '' : $vehicle['trailer']['type'] ?? '')));
            $primary = (string) ($row['primary'] ?? 'sasiu');
            $allowed = self::allowedPrimaries($targetType);
            if (!in_array($primary, $allowed, true) || ($primary === 'sasiu' && AutoComponentCatalogService::subcategoryFor((int) $component['category_id']) === 'livrare_gaz')) {
                throw new InvalidArgumentException('„' . $component['name'] . '” (' . $component['category'] . ') nu se aplică vehiculului ' . ($targetId === $vehicleId ? ($vehicle['plate'] ?? '') : ($vehicle['trailer']['plate'] ?? '')) . '.');
            }
            $operation = (string) ($row['operation'] ?? 'verificare');
            $result[] = [
                'item_id' => $itemId,
                'key' => $key,
                'primary' => $primary,
                'position' => mb_substr(trim((string) ($row['position'] ?? '')), 0, 80),
                'operation' => isset(self::OPERATION_LABELS[$operation]) ? $operation : 'verificare',
                'vehicle_id' => $targetId !== $vehicleId ? $targetId : null,
            ];
        }
        return $result;
    }

    /**
     * Cererea dispecerului din Km service -> lucrarile interventiei. Adauga componentele noi
     * (Suspectata), actualizeaza / scoate doar lucrarile ei inca neatinse de coordonator;
     * scopul confirmat (evaluat, in lucru, cu rezultat sau cost) nu se schimba de aici.
     *
     * @return array{added:int, updated:int, removed:int, locked:int}
     */
    public function applyDispatcherRequest(int $interventionId, array $components, ?int $userId): array
    {
        $summary = ['added' => 0, 'updated' => 0, 'removed' => 0, 'locked' => 0];
        $intervention = $this->getIntervention($interventionId);
        if ($intervention === null || in_array($intervention['status_interventie'], ['finalizata', 'anulata'], true)) {
            $summary['locked'] = count($components);
            return $summary;
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare('SELECT * FROM mentenanta_interventii_lucrari WHERE intervention_id = :id FOR UPDATE');
            $stmt->execute([':id' => $interventionId]);
            $existing = [];
            foreach ($stmt->fetchAll() as $row) {
                $existing[(int) $row['id']] = $row;
            }
            $kept = [];
            foreach ($components as $component) {
                $itemId = (int) $component['item_id'];
                if ($itemId > 0 && isset($existing[$itemId])) {
                    $kept[$itemId] = true;
                    $row = $existing[$itemId];
                    if (!self::isDispatcherEditable($row)) {
                        $summary['locked']++;
                        continue;
                    }
                    $changes = [];
                    if ((string) $row['operatiune'] !== $component['operation']) {
                        $changes['operatiune'] = $component['operation'];
                    }
                    if ((string) ($row['pozitie'] ?? '') !== $component['position']) {
                        $changes['pozitie'] = $component['position'] !== '' ? $component['position'] : null;
                    }
                    if ($changes !== []) {
                        $set = [];
                        $params = [':id' => $itemId, ':u' => date('Y-m-d H:i:s')];
                        foreach ($changes as $column => $value) {
                            $set[] = $column . ' = :v_' . $column;
                            $params[':v_' . $column] = $value;
                        }
                        $this->db->prepare('UPDATE mentenanta_interventii_lucrari SET ' . implode(', ', $set) . ', updated_at = :u WHERE id = :id')->execute($params);
                        $this->logEvent($interventionId, 'lucrare', 'Km Service: ' . $this->itemLabel($itemId) . ' — cerere actualizată de dispecer ('
                            . (isset($changes['operatiune']) ? self::OPERATION_LABELS[$changes['operatiune']] : 'poziție') . ')', $changes, $userId);
                        $summary['updated']++;
                    }
                    continue;
                }
                $this->insertItem($interventionId, [
                    'component_key' => $component['key'],
                    'ramura_principala' => $component['primary'],
                    'pozitie' => $component['position'],
                    'operatiune' => $component['operation'],
                    'stare_evaluare' => 'suspectata',
                    'vehicle_id' => $component['vehicle_id'],
                    'origine' => 'km_service',
                ], $userId);
                $summary['added']++;
            }
            // Ce a scos dispecerul din formular: dispare doar daca e inca cererea lui neatinsa.
            foreach ($existing as $itemId => $row) {
                if (isset($kept[$itemId]) || !self::isDispatcherEditable($row)) {
                    continue;
                }
                $label = $this->itemLabel($itemId);
                $this->db->prepare('DELETE FROM mentenanta_interventii_lucrari WHERE id = :id')->execute([':id' => $itemId]);
                $this->logEvent($interventionId, 'componenta_eliminata', 'Km Service: componentă scoasă de dispecer: ' . $label, null, $userId);
                $summary['removed']++;
            }
            if ($summary['added'] + $summary['updated'] + $summary['removed'] > 0) {
                $this->touch($interventionId, $userId);
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
        return $summary;
    }

    /** Interventia are scop tehnic confirmat (lucrari atinse de coordonator sau e inchisa)? */
    public function hasTechnicalScope(int $interventionId): bool
    {
        $intervention = $this->getIntervention($interventionId);
        if ($intervention === null) {
            return false;
        }
        if (in_array($intervention['status_interventie'], ['finalizata', 'anulata'], true)) {
            return true;
        }
        foreach ($this->db->query('SELECT * FROM mentenanta_interventii_lucrari WHERE intervention_id = ' . $interventionId)->fetchAll() as $row) {
            if (!self::isDispatcherEditable($row)) {
                return true;
            }
        }
        return false;
    }

    /** Interventia legata de o intrare Km service (service_km_id e unic). */
    public function findByServiceKm(int $serviceKmId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mentenanta_interventii_programate WHERE service_km_id = :id LIMIT 1');
        $stmt->execute([':id' => $serviceKmId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Interventiile deschise ale vehiculului inca nelegate de un Km service: dispecerul poate
     * lega plecarea la service de una existenta in loc sa creeze alta.
     */
    public function openUnlinkedForVehicle(int $vehicleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, descriere, status_interventie, data_programata FROM mentenanta_interventii_programate
             WHERE vehicle_id = :v AND service_km_id IS NULL AND status_interventie NOT IN ('finalizata', 'anulata')
             ORDER BY id DESC LIMIT 20"
        );
        $stmt->execute([':v' => $vehicleId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'descriere' => mb_strimwidth((string) $row['descriere'], 0, 80, '…', 'UTF-8'),
            'status_label' => self::STATUS_LABELS[self::statusKey((string) $row['status_interventie'])],
            'data_programata' => (string) $row['data_programata'],
        ], $stmt->fetchAll());
    }

    /**
     * Pentru tabelul Km service: interventia legata, statusul ei si componentele (fara cele excluse).
     *
     * @param int[] $serviceKmIds
     * @return array<int, array{id:int, status_key:string, status_label:string, componente:int, items:array}>
     */
    public function serviceKmSummaries(array $serviceKmIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $serviceKmIds)));
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->query(
            'SELECT id, service_km_id, status_interventie, prioritate FROM mentenanta_interventii_programate
             WHERE service_km_id IN (' . implode(',', $ids) . ')'
        )->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $key = self::statusKey((string) $row['status_interventie']);
            $items = $this->items((int) $row['id']);
            $result[(int) $row['service_km_id']] = [
                'id' => (int) $row['id'],
                'status_key' => $key,
                'status_label' => self::STATUS_LABELS[$key],
                'prioritate' => (string) $row['prioritate'],
                'componente' => count(array_filter($items, static fn (array $item): bool => $item['stare_evaluare'] !== 'exclusa')),
                'items' => $items,
            ];
        }
        return $result;
    }

    private function documents(int $interventionId, ?int $maintenanceId): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.*, u.nume AS incarcat_de FROM mentenanta_interventii_documente d
             LEFT JOIN utilizatori u ON u.id = d.uploaded_by
             WHERE d.intervention_id = :id ORDER BY d.created_at DESC, d.id DESC'
        );
        $stmt->execute([':id' => $interventionId]);
        $documents = [];
        foreach ($stmt->fetchAll() as $row) {
            $documents[] = [
                'id' => (int) $row['id'],
                'sursa' => 'interventie',
                'tip' => (string) $row['tip'],
                'tip_label' => self::DOCUMENT_LABELS[(string) $row['tip']] ?? 'Document',
                'nume' => (string) $row['fisier_original'],
                'marime' => (int) $row['marime'],
                'incarcat_de' => (string) ($row['incarcat_de'] ?? ''),
                'created_at' => (string) $row['created_at'],
            ];
        }
        // Factura atasata inregistrarii finale din Reparatii / Intretinere (acelasi depozit de fisiere).
        if ($maintenanceId !== null) {
            $record = $this->db->prepare('SELECT id, fisier_original, fisier_stocat, data_interventie FROM mentenanta WHERE id = :id');
            $record->execute([':id' => $maintenanceId]);
            $row = $record->fetch();
            if ($row && trim((string) ($row['fisier_stocat'] ?? '')) !== '') {
                $documents[] = [
                    'id' => (int) $row['id'],
                    'sursa' => 'inregistrare',
                    'tip' => 'factura',
                    'tip_label' => 'Factură (înregistrarea finală)',
                    'nume' => (string) ($row['fisier_original'] ?: $row['fisier_stocat']),
                    'marime' => 0,
                    'incarcat_de' => '',
                    'created_at' => (string) $row['data_interventie'],
                ];
            }
        }
        return $documents;
    }

    public function documentFile(int $documentId, string $source): ?array
    {
        if ($source === 'inregistrare') {
            $stmt = $this->db->prepare('SELECT fisier_original, fisier_stocat FROM mentenanta WHERE id = :id');
            $stmt->execute([':id' => $documentId]);
            $row = $stmt->fetch();
            return $row && trim((string) $row['fisier_stocat']) !== ''
                ? ['original' => (string) ($row['fisier_original'] ?: $row['fisier_stocat']), 'stored' => (string) $row['fisier_stocat'], 'dir' => 'mentenanta_piese']
                : null;
        }
        $stmt = $this->db->prepare('SELECT fisier_original, fisier_stocat, intervention_id FROM mentenanta_interventii_documente WHERE id = :id');
        $stmt->execute([':id' => $documentId]);
        $row = $stmt->fetch();
        return $row ? ['original' => (string) $row['fisier_original'], 'stored' => (string) $row['fisier_stocat'], 'dir' => 'mentenanta_interventii', 'intervention_id' => (int) $row['intervention_id']] : null;
    }

    public function addDocument(int $interventionId, string $type, array $file, int $size, ?int $userId): int
    {
        $type = isset(self::DOCUMENT_LABELS[$type]) ? $type : 'altul';
        $this->db->prepare(
            'INSERT INTO mentenanta_interventii_documente
                (intervention_id, tip, fisier_original, fisier_stocat, marime, uploaded_by, created_at)
             VALUES (:i, :t, :o, :s, :m, :u, :c)'
        )->execute([
            ':i' => $interventionId,
            ':t' => $type,
            ':o' => mb_substr((string) $file['original'], 0, 255),
            ':s' => (string) $file['stored'],
            ':m' => $size,
            ':u' => $userId,
            ':c' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->logEvent($interventionId, 'document', 'Document încărcat: ' . self::DOCUMENT_LABELS[$type] . ' — ' . $file['original'], null, $userId);
        return $id;
    }

    public function deleteDocument(int $documentId, ?int $userId): ?array
    {
        $file = $this->documentFile($documentId, 'interventie');
        if ($file === null) {
            return null;
        }
        $this->db->prepare('DELETE FROM mentenanta_interventii_documente WHERE id = :id')->execute([':id' => $documentId]);
        $this->logEvent((int) $file['intervention_id'], 'document', 'Document șters: ' . $file['original'], null, $userId);
        return $file;
    }

    public function history(int $interventionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT h.*, u.nume AS user_nume FROM mentenanta_interventii_istoric h
             LEFT JOIN utilizatori u ON u.id = h.user_id
             WHERE h.intervention_id = :id ORDER BY h.created_at ASC, h.id ASC'
        );
        $stmt->execute([':id' => $interventionId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'tip' => (string) $row['tip'],
            'descriere' => (string) $row['descriere'],
            'detalii' => $row['detalii'] !== null ? json_decode((string) $row['detalii'], true) : null,
            'user_nume' => (string) ($row['user_nume'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ], $stmt->fetchAll());
    }

    private function followUps(int $interventionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, status_interventie, data_programata FROM mentenanta_interventii_programate
             WHERE interventie_origine_id = :id ORDER BY id'
        );
        $stmt->execute([':id' => $interventionId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'status_label' => self::STATUS_LABELS[self::statusKey((string) $row['status_interventie'])],
            'data_programata' => (string) $row['data_programata'],
        ], $stmt->fetchAll());
    }

    /**
     * Costurile: piese / manopera din lucrari, plus costuri suplimentare. Daca interventia
     * a fost convertita inainte de V2 (fara lucrari cu cost), costul real e cel din
     * inregistrarea finala — afisat o singura data, nu adunat peste lucrari.
     */
    private function costs(array $row, array $items): array
    {
        $parts = 0.0;
        $labor = 0.0;
        foreach ($items as $item) {
            if ($item['stare_evaluare'] === 'exclusa') {
                continue;
            }
            $parts += $item['cost_piese'];
            $labor += $item['cost_manopera'];
        }
        $extra = (float) ($row['cost_suplimentar'] ?? 0);
        $source = 'lucrari';
        $record = null;
        if (!empty($row['converted_maintenance_id'])) {
            $stmt = $this->db->prepare('SELECT id, record_type, cost, cost_manopera, cost_piese FROM mentenanta WHERE id = :id');
            $stmt->execute([':id' => (int) $row['converted_maintenance_id']]);
            $record = $stmt->fetch() ?: null;
            if ($record !== null && $parts + $labor + $extra <= 0) {
                $source = 'inregistrare';
                $parts = (float) $record['cost_piese'];
                $labor = (float) $record['cost_manopera'];
                $extra = max(0.0, (float) $record['cost'] - $parts - $labor);
            }
        }

        return [
            'estimat' => (float) $row['cost_estimat'],
            'piese' => round($parts, 2),
            'manopera' => round($labor, 2),
            'suplimentar' => round($extra, 2),
            'total' => round($parts + $labor + $extra, 2),
            'sursa' => $source,
            'inregistrare' => $record !== null ? [
                'id' => (int) $record['id'],
                'tip' => (string) $record['record_type'],
                'cost' => (float) $record['cost'],
            ] : null,
        ];
    }

    // ------------------------------------------------------------------ istoric

    public function logEvent(int $interventionId, string $type, string $description, ?array $details, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO mentenanta_interventii_istoric (intervention_id, tip, descriere, detalii, user_id, created_at)
             VALUES (:i, :t, :d, :j, :u, :c)'
        )->execute([
            ':i' => $interventionId,
            ':t' => $type,
            ':d' => mb_substr($description, 0, 500),
            ':j' => $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ':u' => $userId !== null && $userId > 0 ? $userId : null,
            ':c' => date('Y-m-d H:i:s'),
        ]);
    }

    // ------------------------------------------------------------------ salvare din formular

    /**
     * Formularul Adauga / Editeaza interventie (dispecer sau coordonator).
     * La creare, descrierea devine si sesizarea initiala (instantaneu care nu se mai schimba).
     *
     * @param array<int,array{key:string,primary?:string,position?:string,operation?:string}> $components
     */
    public function saveFromForm(array $data, int $id, array $components, ?int $userId): int
    {
        $existing = $id > 0 ? $this->getIntervention($id) : null;
        if ($id > 0 && $existing === null) {
            throw new InvalidArgumentException('Intervenția nu mai există.');
        }
        if ($existing !== null && $existing['status_interventie'] === 'finalizata') {
            throw new InvalidArgumentException('Intervenția #' . $id . ' este finalizată; înregistrarea finală se modifică din Întreținere / Reparații.');
        }

        $statusKey = (string) ($data['status'] ?? '');
        $status = $statusKey !== '' ? self::storedStatus($statusKey) : (string) ($existing['status_interventie'] ?? 'de_verificat');
        // Statusul vechi pastrat cand afisarea nu s-a schimbat (confirmata ramane confirmata).
        if ($existing !== null && self::statusKey((string) $existing['status_interventie']) === $statusKey) {
            $status = (string) $existing['status_interventie'];
        }
        if ($status === 'finalizata' && ($existing === null || $existing['status_interventie'] !== 'finalizata')) {
            throw new InvalidArgumentException('Finalizarea se face din panoul intervenției („Finalizează intervenția”), cu revizuirea fiecărei lucrări.');
        }
        $responsible = !empty($data['responsabil_user_id']) ? (int) $data['responsabil_user_id'] : null;
        if ($responsible !== null && !$this->isCoordinator($responsible)) {
            throw new InvalidArgumentException('Responsabilul tehnic ales nu are dreptul de coordonare a intervențiilor.');
        }
        $statusChanged = $existing === null || $existing['status_interventie'] !== $status;
        if ($statusChanged && $responsible === null && in_array($status, self::STATUSES_REQUIRING_COORDINATOR, true)) {
            throw new InvalidArgumentException('Alege responsabilul tehnic înainte de a trece intervenția în „' . self::STATUS_LABELS[self::statusKey($status)] . '”.');
        }

        $values = [
            'vehicle_id' => (int) $data['vehicle_id'],
            'tip_interventie' => ($data['tip_interventie'] ?? '') === 'reparatie' ? 'reparatie' : 'intretinere',
            'prioritate' => isset(self::PRIORITY_LABELS[$data['prioritate'] ?? '']) ? (string) $data['prioritate'] : 'normala',
            'data_programata' => (string) $data['data_programata'],
            'ora_programata' => $this->timeOrNull((string) ($data['ora_programata'] ?? '')),
            'cost_estimat' => max(0, (float) ($data['cost_estimat'] ?? 0)),
            'furnizor' => $this->nullIfEmpty((string) ($data['furnizor'] ?? ''), 190),
            'driver_id' => !empty($data['driver_id']) ? (int) $data['driver_id'] : null,
            'responsabil_user_id' => $responsible,
            'client' => $this->nullIfEmpty((string) ($data['client'] ?? ''), 190),
            'centru_cost' => $this->nullIfEmpty((string) ($data['centru_cost'] ?? ''), 80),
            'descriere' => trim((string) ($data['descriere'] ?? '')),
            'status_interventie' => $status,
        ];
        $now = date('Y-m-d H:i:s');

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            if ($existing === null) {
                $insert = $values + [
                    'sesizare_initiala' => $values['descriere'],
                    'sesizare_la' => $now,
                    'sesizare_de' => $userId,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $columns = array_keys($insert);
                $this->db->prepare(
                    'INSERT INTO mentenanta_interventii_programate (' . implode(', ', $columns) . ')
                     VALUES (:' . implode(', :', $columns) . ')'
                )->execute($this->prefixKeys($insert));
                $id = (int) $this->db->lastInsertId();
                $this->logEvent($id, 'creare', 'Intervenție creată manual', [
                    'status' => self::STATUS_LABELS[self::statusKey($status)],
                    'sesizare' => $values['descriere'],
                ], $userId);
                if ($responsible !== null) {
                    $this->logEvent($id, 'responsabil', 'Responsabil tehnic: ' . $this->userName($responsible), ['de_la' => null, 'la' => $responsible], $userId);
                }
            } else {
                $this->applyUpdate($existing, $values, $userId);
            }

            foreach ($components as $component) {
                $this->insertItem($id, [
                    'component_key' => (string) ($component['key'] ?? ''),
                    'ramura_principala' => (string) ($component['primary'] ?? ''),
                    'pozitie' => (string) ($component['position'] ?? ''),
                    'operatiune' => (string) ($component['operation'] ?? 'verificare'),
                    'stare_evaluare' => 'suspectata',
                ], $userId);
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

        return $id;
    }

    /**
     * Modificari din panoul lateral (doar campurile trimise). Sesizarea initiala nu se poate
     * modifica de aici; diagnosticul coordonatorului se salveaza separat de ea.
     */
    public function updateFromPanel(int $id, array $fields, ?int $userId, bool $canCosts): array
    {
        $existing = $this->getIntervention($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Intervenția nu mai există.');
        }
        $currentKey = self::statusKey((string) $existing['status_interventie']);
        if (in_array($currentKey, ['finalizata', 'anulata'], true) && !(($fields['status'] ?? '') === 'de_verificat' && $currentKey === 'anulata')) {
            throw new InvalidArgumentException('Intervenția este ' . mb_strtolower(self::STATUS_LABELS[$currentKey], 'UTF-8') . ' și nu mai poate fi modificată.');
        }

        $values = [];
        if (array_key_exists('data_programata', $fields)) {
            $date = (string) $fields['data_programata'];
            if (!$this->isDate($date)) {
                throw new InvalidArgumentException('Data programată nu este validă.');
            }
            $values['data_programata'] = $date;
        }
        if (array_key_exists('ora_programata', $fields)) {
            $values['ora_programata'] = $this->timeOrNull((string) $fields['ora_programata']);
        }
        if (array_key_exists('furnizor', $fields)) {
            $values['furnizor'] = $this->nullIfEmpty((string) $fields['furnizor'], 190);
        }
        if (array_key_exists('prioritate', $fields) && isset(self::PRIORITY_LABELS[$fields['prioritate']])) {
            $values['prioritate'] = (string) $fields['prioritate'];
        }
        if (array_key_exists('tip_interventie', $fields)) {
            $values['tip_interventie'] = $fields['tip_interventie'] === 'reparatie' ? 'reparatie' : 'intretinere';
        }
        if (array_key_exists('disponibilitate_vehicul', $fields)) {
            $values['disponibilitate_vehicul'] = isset(self::AVAILABILITY_LABELS[$fields['disponibilitate_vehicul']]) ? (string) $fields['disponibilitate_vehicul'] : null;
        }
        if (array_key_exists('responsabil_user_id', $fields)) {
            $responsible = (int) $fields['responsabil_user_id'] > 0 ? (int) $fields['responsabil_user_id'] : null;
            if ($responsible !== null && !$this->isCoordinator($responsible)) {
                throw new InvalidArgumentException('Utilizatorul ales nu are dreptul de coordonare a intervențiilor.');
            }
            $values['responsabil_user_id'] = $responsible;
        }
        if (array_key_exists('diagnostic_tehnic', $fields)) {
            $values['diagnostic_tehnic'] = $this->nullIfEmpty((string) $fields['diagnostic_tehnic'], 10000);
        }
        if ($canCosts && array_key_exists('cost_estimat', $fields)) {
            $values['cost_estimat'] = max(0, (float) $fields['cost_estimat']);
        }
        if ($canCosts && array_key_exists('cost_suplimentar', $fields)) {
            $values['cost_suplimentar'] = max(0, (float) $fields['cost_suplimentar']);
        }
        if (array_key_exists('status', $fields)) {
            $key = (string) $fields['status'];
            if (!isset(self::STATUS_LABELS[$key]) || $key === 'finalizata') {
                throw new InvalidArgumentException($key === 'finalizata'
                    ? 'Folosește „Finalizează intervenția” pentru a confirma rezultatul fiecărei lucrări.'
                    : 'Status necunoscut.');
            }
            if ($key !== $currentKey) {
                $values['status_interventie'] = self::storedStatus($key);
            }
        }
        $responsibleAfter = array_key_exists('responsabil_user_id', $values) ? $values['responsabil_user_id'] : ($existing['responsabil_user_id'] !== null ? (int) $existing['responsabil_user_id'] : null);
        if (isset($values['status_interventie']) && $responsibleAfter === null
            && in_array($values['status_interventie'], self::STATUSES_REQUIRING_COORDINATOR, true)) {
            throw new InvalidArgumentException('Intervenția nu are responsabil tehnic (Nealocat). Alege responsabilul înainte de schimbarea statusului.');
        }

        $this->db->beginTransaction();
        try {
            $changes = $this->applyUpdate($existing, $values, $userId);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        return $changes;
    }

    /** Scrie diferentele si le pune in istoric, cate un eveniment pe tip de schimbare. */
    private function applyUpdate(array $existing, array $values, ?int $userId): array
    {
        $id = (int) $existing['id'];
        $changes = [];
        foreach ($values as $column => $value) {
            $before = $existing[$column] ?? null;
            $normalizedBefore = $before === null ? null : (string) $before;
            $normalizedAfter = $value === null ? null : (string) $value;
            if (in_array($column, ['cost_estimat', 'cost_suplimentar'], true)) {
                $normalizedBefore = number_format((float) $before, 2, '.', '');
                $normalizedAfter = number_format((float) $value, 2, '.', '');
            }
            if ($column === 'ora_programata' && $normalizedBefore !== null) {
                $normalizedBefore = substr($normalizedBefore, 0, 5);
                $normalizedAfter = $normalizedAfter !== null ? substr($normalizedAfter, 0, 5) : null;
            }
            if ($normalizedBefore !== $normalizedAfter) {
                $changes[$column] = ['de_la' => $before, 'la' => $value];
            }
        }
        if ($changes === []) {
            return [];
        }

        $set = [];
        $params = [':id' => $id, ':updated_at' => date('Y-m-d H:i:s'), ':updated_by' => $userId];
        foreach (array_keys($changes) as $column) {
            $set[] = $column . ' = :v_' . $column;
            $params[':v_' . $column] = $values[$column];
        }
        if (isset($changes['diagnostic_tehnic'])) {
            $set[] = 'diagnostic_la = :diag_la';
            $set[] = 'diagnostic_de = :diag_de';
            $params[':diag_la'] = date('Y-m-d H:i:s');
            $params[':diag_de'] = $userId;
        }
        $this->db->prepare(
            'UPDATE mentenanta_interventii_programate SET ' . implode(', ', $set) . ', updated_at = :updated_at, updated_by = :updated_by WHERE id = :id'
        )->execute($params);

        $dateLabel = static fn ($value): string => $value ? date('d.m.Y', (int) strtotime((string) $value)) : '—';
        if (isset($changes['status_interventie'])) {
            $from = self::STATUS_LABELS[self::statusKey((string) $changes['status_interventie']['de_la'])];
            $to = self::STATUS_LABELS[self::statusKey((string) $changes['status_interventie']['la'])];
            $this->logEvent($id, $values['status_interventie'] === 'anulata' ? 'anulare' : 'status', 'Status: ' . $from . ' → ' . $to, $changes['status_interventie'], $userId);
        }
        if (isset($changes['responsabil_user_id'])) {
            $from = $changes['responsabil_user_id']['de_la'] !== null ? $this->userName((int) $changes['responsabil_user_id']['de_la']) : 'Nealocat';
            $to = $changes['responsabil_user_id']['la'] !== null ? $this->userName((int) $changes['responsabil_user_id']['la']) : 'Nealocat';
            $this->logEvent($id, 'responsabil', ($changes['responsabil_user_id']['de_la'] === null ? 'Responsabil tehnic alocat: ' : 'Responsabil tehnic schimbat: ' . $from . ' → ') . $to, $changes['responsabil_user_id'], $userId);
        }
        if (isset($changes['data_programata']) || isset($changes['ora_programata']) || isset($changes['furnizor'])) {
            $parts = [];
            if (isset($changes['data_programata'])) {
                $parts[] = 'data ' . $dateLabel($changes['data_programata']['de_la']) . ' → ' . $dateLabel($changes['data_programata']['la']);
            }
            if (isset($changes['ora_programata'])) {
                $parts[] = 'ora ' . ($changes['ora_programata']['de_la'] ? substr((string) $changes['ora_programata']['de_la'], 0, 5) : '—') . ' → ' . ($changes['ora_programata']['la'] ? substr((string) $changes['ora_programata']['la'], 0, 5) : '—');
            }
            if (isset($changes['furnizor'])) {
                $parts[] = 'service „' . ($changes['furnizor']['de_la'] ?: '—') . '” → „' . ($changes['furnizor']['la'] ?: '—') . '”';
            }
            $this->logEvent($id, 'programare', (isset($changes['data_programata']) ? 'Reprogramare: ' : 'Programare actualizată: ') . implode(', ', $parts), array_intersect_key($changes, array_flip(['data_programata', 'ora_programata', 'furnizor'])), $userId);
        }
        if (isset($changes['diagnostic_tehnic'])) {
            $this->logEvent($id, 'diagnostic', $changes['diagnostic_tehnic']['de_la'] === null ? 'Diagnostic tehnic adăugat' : 'Diagnostic tehnic modificat', ['text' => $values['diagnostic_tehnic']], $userId);
        }
        if (isset($changes['prioritate'])) {
            $this->logEvent($id, 'prioritate', 'Prioritate: ' . (self::PRIORITY_LABELS[(string) $changes['prioritate']['de_la']] ?? '—') . ' → ' . self::PRIORITY_LABELS[(string) $changes['prioritate']['la']], $changes['prioritate'], $userId);
        }
        if (isset($changes['cost_estimat']) || isset($changes['cost_suplimentar'])) {
            $this->logEvent($id, 'cost', 'Costuri actualizate', array_intersect_key($changes, array_flip(['cost_estimat', 'cost_suplimentar'])), $userId);
        }
        $other = array_diff_key($changes, array_flip(['status_interventie', 'responsabil_user_id', 'data_programata', 'ora_programata', 'furnizor', 'diagnostic_tehnic', 'prioritate', 'cost_estimat', 'cost_suplimentar']));
        if ($other !== []) {
            $labels = [
                'vehicle_id' => 'vehicul', 'tip_interventie' => 'tip intervenție', 'driver_id' => 'șofer', 'client' => 'client',
                'centru_cost' => 'centru de cost', 'descriere' => 'problemă raportată', 'disponibilitate_vehicul' => 'disponibilitate vehicul',
            ];
            $this->logEvent($id, 'editare', 'Modificat: ' . implode(', ', array_map(static fn (string $c): string => $labels[$c] ?? $c, array_keys($other))), $other, $userId);
        }

        return $changes;
    }

    // ------------------------------------------------------------------ lucrari

    public function saveItem(int $interventionId, int $itemId, array $data, ?int $userId, bool $canCosts): int
    {
        $intervention = $this->getIntervention($interventionId);
        if ($intervention === null) {
            throw new InvalidArgumentException('Intervenția nu mai există.');
        }
        if (in_array($intervention['status_interventie'], ['finalizata', 'anulata'], true)) {
            throw new InvalidArgumentException('Lucrările unei intervenții finalizate sau anulate nu se mai modifică.');
        }
        if (!$canCosts) {
            unset($data['cost_piese'], $data['cost_manopera']);
        }

        $this->db->beginTransaction();
        try {
            if ($itemId <= 0) {
                $itemId = $this->insertItem($interventionId, $data, $userId);
            } else {
                $this->updateItem($interventionId, $itemId, $data, $userId);
            }
            $this->touch($interventionId, $userId);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        return $itemId;
    }

    private function insertItem(int $interventionId, array $data, ?int $userId): int
    {
        $values = $this->itemValues($data, null);
        if ($values['component_key'] === null && $values['denumire_libera'] === null) {
            throw new InvalidArgumentException('Alege o componentă din registru sau scrie denumirea lucrării.');
        }
        if ($values['component_key'] !== null) {
            $check = $this->db->prepare('SELECT nume, activ FROM mentenanta_auto_componente WHERE component_key = :k');
            $check->execute([':k' => $values['component_key']]);
            $component = $check->fetch();
            if (!$component || (int) $component['activ'] !== 1) {
                throw new InvalidArgumentException('Componenta aleasă nu mai există în registru.');
            }
        }
        $order = $this->db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM mentenanta_interventii_lucrari WHERE intervention_id = :i');
        $order->execute([':i' => $interventionId]);
        $now = date('Y-m-d H:i:s');
        $insert = $values + [
            'intervention_id' => $interventionId,
            'vehicle_id' => !empty($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
            'origine' => ($data['origine'] ?? null) === 'km_service' ? 'km_service' : null,
            'sort_order' => (int) $order->fetchColumn(),
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $columns = array_keys($insert);
        $this->db->prepare(
            'INSERT INTO mentenanta_interventii_lucrari (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')'
        )->execute($this->prefixKeys($insert));
        $itemId = (int) $this->db->lastInsertId();
        $this->logEvent($interventionId, 'componenta_adaugata', 'Componentă adăugată: ' . $this->itemLabel($itemId)
            . ' — ' . self::OPERATION_LABELS[$values['operatiune']] . ' (' . mb_strtolower(self::ASSESSMENT_LABELS[$values['stare_evaluare']], 'UTF-8') . ')', null, $userId);
        return $itemId;
    }

    private function updateItem(int $interventionId, int $itemId, array $data, ?int $userId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM mentenanta_interventii_lucrari WHERE id = :id AND intervention_id = :i FOR UPDATE');
        $stmt->execute([':id' => $itemId, ':i' => $interventionId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            throw new InvalidArgumentException('Lucrarea nu mai există.');
        }
        $values = $this->itemValues($data, $existing);
        unset($values['component_key'], $values['ramura_principala']); // componenta nu se schimba; se adauga alta
        $changes = [];
        foreach ($values as $column => $value) {
            $before = $existing[$column];
            $a = $before === null ? null : (string) $before;
            $b = $value === null ? null : (string) $value;
            if (in_array($column, ['cost_piese', 'cost_manopera'], true)) {
                $a = number_format((float) $before, 2, '.', '');
                $b = number_format((float) $value, 2, '.', '');
            }
            if ($a !== $b) {
                $changes[$column] = ['de_la' => $before, 'la' => $value];
            }
        }
        if ($changes === []) {
            return;
        }
        $set = [];
        $params = [':id' => $itemId, ':updated_at' => date('Y-m-d H:i:s')];
        foreach (array_keys($changes) as $column) {
            $set[] = $column . ' = :v_' . $column;
            $params[':v_' . $column] = $values[$column];
        }
        $this->db->prepare('UPDATE mentenanta_interventii_lucrari SET ' . implode(', ', $set) . ', updated_at = :updated_at WHERE id = :id')->execute($params);

        $label = $this->itemLabel($itemId);
        if (isset($changes['stare_evaluare'])) {
            $this->logEvent($interventionId, 'evaluare', $label . ': ' . self::ASSESSMENT_LABELS[(string) $changes['stare_evaluare']['de_la']] . ' → ' . self::ASSESSMENT_LABELS[(string) $changes['stare_evaluare']['la']], $changes['stare_evaluare'], $userId);
        }
        if (isset($changes['diagnostic'])) {
            $this->logEvent($interventionId, 'diagnostic', 'Diagnostic actualizat pentru ' . $label, ['text' => $values['diagnostic']], $userId);
        }
        if (isset($changes['progres']) || isset($changes['rezultat'])) {
            $text = [];
            if (isset($changes['progres'])) {
                $text[] = 'progres ' . self::PROGRESS_LABELS[(string) $values['progres']];
            }
            if (isset($changes['rezultat'])) {
                $text[] = 'rezultat ' . ($values['rezultat'] !== null ? self::RESULT_LABELS[(string) $values['rezultat']] : '—');
            }
            $this->logEvent($interventionId, 'lucrare', $label . ': ' . implode(', ', $text), array_intersect_key($changes, array_flip(['progres', 'rezultat'])), $userId);
        }
        $other = array_diff_key($changes, array_flip(['stare_evaluare', 'diagnostic', 'progres', 'rezultat']));
        if ($other !== []) {
            $this->logEvent($interventionId, 'lucrare', $label . ': lucrare actualizată (' . implode(', ', array_map(
                static fn (string $c): string => str_replace('_', ' ', $c),
                array_keys($other)
            )) . ')', $other, $userId);
        }
    }

    private function itemValues(array $data, ?array $existing): array
    {
        $pick = static fn (string $key, mixed $default = null) => array_key_exists($key, $data) ? $data[$key] : ($existing[$key] ?? $default);
        $key = trim((string) $pick('component_key', ''));
        $primary = trim((string) $pick('ramura_principala', ''));
        $assessment = (string) $pick('stare_evaluare', 'suspectata');
        $operation = (string) $pick('operatiune', 'verificare');
        $progress = (string) $pick('progres', 'neinceputa');
        $result = (string) ($pick('rezultat', '') ?? '');
        $warrantyMonths = (string) ($pick('garantie_luni', '') ?? '');
        $km = (string) ($pick('km_montare', '') ?? '');
        $mountDate = (string) ($pick('data_montare', '') ?? '');
        $warrantyDate = (string) ($pick('garantie_pana_la', '') ?? '');

        return [
            'component_key' => $key !== '' ? mb_substr($key, 0, 10) : null,
            'ramura_principala' => isset(AutoComponentCatalogService::PRIMARY_LABELS[$primary]) ? $primary : null,
            'denumire_libera' => $this->nullIfEmpty((string) ($pick('denumire_libera', '') ?? ''), 190),
            'pozitie' => $this->nullIfEmpty((string) ($pick('pozitie', '') ?? ''), 80),
            'stare_evaluare' => isset(self::ASSESSMENT_LABELS[$assessment]) ? $assessment : 'suspectata',
            'operatiune' => isset(self::OPERATION_LABELS[$operation]) ? $operation : 'verificare',
            'diagnostic' => $this->nullIfEmpty((string) ($pick('diagnostic', '') ?? ''), 5000),
            'progres' => isset(self::PROGRESS_LABELS[$progress]) ? $progress : 'neinceputa',
            'rezultat' => isset(self::RESULT_LABELS[$result]) ? $result : null,
            'piesa_montata' => $this->nullIfEmpty((string) ($pick('piesa_montata', '') ?? ''), 190),
            'piesa_demontata' => $this->nullIfEmpty((string) ($pick('piesa_demontata', '') ?? ''), 190),
            'data_montare' => $this->isDate($mountDate) ? $mountDate : null,
            'km_montare' => $km !== '' && is_numeric($km) && (int) $km >= 0 ? (int) $km : null,
            'cost_piese' => max(0, round((float) $pick('cost_piese', 0), 2)),
            'cost_manopera' => max(0, round((float) $pick('cost_manopera', 0), 2)),
            'garantie_luni' => $warrantyMonths !== '' && is_numeric($warrantyMonths) && (int) $warrantyMonths > 0 ? min(600, (int) $warrantyMonths) : null,
            'garantie_pana_la' => $this->isDate($warrantyDate) ? $warrantyDate : null,
            'observatii' => $this->nullIfEmpty((string) ($pick('observatii', '') ?? ''), 5000),
        ];
    }

    public function deleteItem(int $interventionId, int $itemId, ?int $userId): void
    {
        $intervention = $this->getIntervention($interventionId);
        if ($intervention === null || in_array($intervention['status_interventie'], ['finalizata', 'anulata'], true)) {
            throw new InvalidArgumentException('Lucrările unei intervenții finalizate sau anulate nu se mai modifică.');
        }
        $label = $this->itemLabel($itemId);
        $stmt = $this->db->prepare('DELETE FROM mentenanta_interventii_lucrari WHERE id = :id AND intervention_id = :i AND usage_id IS NULL');
        $stmt->execute([':id' => $itemId, ':i' => $interventionId]);
        if ($stmt->rowCount() === 0) {
            throw new InvalidArgumentException('Lucrarea nu mai există sau a fost deja înregistrată în istoricul componentei.');
        }
        $this->logEvent($interventionId, 'componenta_eliminata', 'Componentă eliminată: ' . $label, null, $userId);
        $this->touch($interventionId, $userId);
    }

    private function itemLabel(int $itemId): string
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(c.nume, l.denumire_libera, \'Lucrare\') AS nume, l.pozitie
             FROM mentenanta_interventii_lucrari l
             LEFT JOIN mentenanta_auto_componente c ON c.component_key = l.component_key WHERE l.id = :id'
        );
        $stmt->execute([':id' => $itemId]);
        $row = $stmt->fetch();
        if (!$row) {
            return 'Lucrare #' . $itemId;
        }
        return (string) $row['nume'] . ($row['pozitie'] ? ' (' . $row['pozitie'] . ')' : '');
    }

    private function touch(int $interventionId, ?int $userId): void
    {
        $this->db->prepare('UPDATE mentenanta_interventii_programate SET updated_at = :u, updated_by = :b WHERE id = :id')
            ->execute([':u' => date('Y-m-d H:i:s'), ':b' => $userId, ':id' => $interventionId]);
    }

    // ------------------------------------------------------------------ finalizare

    /**
     * Finalizarea controlata: coordonatorul confirma rezultatul fiecarei lucrari.
     *  - verificare / reglare / reparatie executata: se pastreaza lucrarea, costul si observatiile;
     *    uzura componentei NU se reseteaza;
     *  - inlocuire executata si confirmata explicit: montare in mentenanta_piese_utilizari pe
     *    piesa-componenta din stoc (de aici Reparatii Auto calculeaza uzura de la km / data montarii);
     *  - amanata: ramane nerezolvata si, la cerere, trece intr-o interventie noua (De verificat).
     * Totul intr-o tranzactie; a doua apelare nu dubleaza nimic (converted_maintenance_id / usage_id).
     *
     * @param array<int,array<string,mixed>> $outcomes itemId => campuri confirmate
     * @return array{maintenance_id:int, montari:int, follow_up_id:?int, already:bool}
     */
    public function finalize(int $id, array $outcomes, array $options, ?int $userId, bool $canCosts, MaintenanceModel $maintenance): array
    {
        // Piesele-componenta din stoc trebuie sa existe (INSERT-uri DDL-free, dar in afara tranzactiei).
        $catalog = new AutoComponentCatalogService($this->db);
        $maintenance->syncAutoComponentsToStock($catalog->categories());
        $partIndex = $maintenance->getAutoComponentPartIndex();
        $components = $catalog->components(true);

        $executionDate = (string) ($options['data_executie'] ?? '');
        if (!$this->isDate($executionDate)) {
            throw new InvalidArgumentException('Completează data execuției.');
        }
        $executionKm = (string) ($options['km'] ?? '');
        $executionKm = $executionKm !== '' && is_numeric($executionKm) ? max(0, (int) $executionKm) : null;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM mentenanta_interventii_programate WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $intervention = $stmt->fetch();
            if (!$intervention) {
                throw new InvalidArgumentException('Intervenția nu mai există.');
            }
            if ($intervention['status_interventie'] === 'finalizata' && !empty($intervention['converted_maintenance_id'])) {
                $this->db->commit();
                return ['maintenance_id' => (int) $intervention['converted_maintenance_id'], 'montari' => 0, 'follow_up_id' => null, 'already' => true];
            }
            if ($intervention['status_interventie'] === 'anulata') {
                throw new InvalidArgumentException('Intervenția este anulată.');
            }
            if (empty($intervention['responsabil_user_id'])) {
                throw new InvalidArgumentException('Intervenția nu are responsabil tehnic. Alocă un responsabil înainte de finalizare.');
            }

            $itemsStmt = $this->db->prepare('SELECT * FROM mentenanta_interventii_lucrari WHERE intervention_id = :id ORDER BY sort_order, id FOR UPDATE');
            $itemsStmt->execute([':id' => $id]);
            $items = $itemsStmt->fetchAll();

            // 1. Rezultatul confirmat pe fiecare lucrare (componentele excluse nu cer rezultat).
            $now = date('Y-m-d H:i:s');
            $postponed = [];
            $replacements = [];
            $labor = 0.0;
            $parts = 0.0;
            $lines = [];
            $replacedNames = [];
            foreach ($items as &$item) {
                $itemId = (int) $item['id'];
                $outcome = is_array($outcomes[$itemId] ?? null) ? $outcomes[$itemId] : [];
                if ($item['stare_evaluare'] === 'exclusa') {
                    continue;
                }
                $result = (string) ($outcome['rezultat'] ?? $item['rezultat'] ?? '');
                if (!isset(self::RESULT_LABELS[$result])) {
                    throw new InvalidArgumentException('Alege rezultatul pentru „' . $this->itemLabel($itemId) . '”.');
                }
                $update = ['rezultat' => $result, 'progres' => $result === 'executata' ? 'terminata' : $item['progres']];
                foreach (['diagnostic', 'piesa_montata', 'piesa_demontata', 'observatii'] as $field) {
                    if (array_key_exists($field, $outcome)) {
                        $update[$field] = $this->nullIfEmpty((string) $outcome[$field], $field === 'diagnostic' || $field === 'observatii' ? 5000 : 190);
                    }
                }
                if ($canCosts) {
                    foreach (['cost_piese', 'cost_manopera'] as $field) {
                        if (array_key_exists($field, $outcome)) {
                            $update[$field] = max(0, round((float) $outcome[$field], 2));
                        }
                    }
                }
                foreach (['garantie_luni', 'km_montare'] as $field) {
                    if (array_key_exists($field, $outcome)) {
                        $raw = trim((string) $outcome[$field]);
                        $update[$field] = $raw !== '' && is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
                    }
                }
                foreach (['data_montare', 'garantie_pana_la'] as $field) {
                    if (array_key_exists($field, $outcome)) {
                        $update[$field] = $this->isDate((string) $outcome[$field]) ? (string) $outcome[$field] : null;
                    }
                }
                $item = array_merge($item, $update);

                $isReplacement = $item['operatiune'] === 'inlocuire' && $result === 'executata';
                if ($isReplacement && empty($outcome['confirma_inlocuire']) && empty($item['usage_id'])) {
                    throw new InvalidArgumentException('Confirmă înlocuirea pentru „' . $this->itemLabel($itemId) . '” (bifa „Confirm înlocuirea”).');
                }
                if ($isReplacement && empty($item['component_key'])) {
                    throw new InvalidArgumentException('„' . $this->itemLabel($itemId) . '” nu este o componentă din registru: înlocuirea nu poate fi trecută în istoricul componentei. Alege rezultatul „Executată” la o operațiune de reparație sau adaugă componenta din registru.');
                }
                if ($isReplacement) {
                    $update['data_montare'] = $item['data_montare'] ?: $executionDate;
                    $update['km_montare'] = $item['km_montare'] ?? $executionKm;
                    $item = array_merge($item, $update);
                    $replacements[] = $itemId;
                }
                if ($result === 'amanata') {
                    $postponed[] = $item;
                }
                if ($result === 'executata') {
                    $labor += (float) $item['cost_manopera'];
                    $parts += (float) $item['cost_piese'];
                    $lines[] = $this->itemLabel($itemId) . ' — ' . self::OPERATION_LABELS[(string) $item['operatiune']];
                    if ($isReplacement) {
                        $replacedNames[] = trim((string) ($item['piesa_montata'] ?: $this->itemLabel($itemId)));
                    }
                }

                $set = [];
                $params = [':id' => $itemId, ':upd' => $now];
                foreach (array_keys($update) as $column) {
                    $set[] = $column . ' = :v_' . $column;
                    $params[':v_' . $column] = $item[$column];
                }
                $this->db->prepare('UPDATE mentenanta_interventii_lucrari SET ' . implode(', ', $set) . ', updated_at = :upd WHERE id = :id')->execute($params);
            }
            unset($item);

            // 2. Inregistrarea finala in Intretinere / Reparatii (o singura data).
            $maintenanceId = !empty($intervention['converted_maintenance_id']) ? (int) $intervention['converted_maintenance_id'] : 0;
            $extra = (float) $intervention['cost_suplimentar'];
            if ($maintenanceId <= 0) {
                $diagnosis = trim((string) ($intervention['diagnostic_tehnic'] ?? ''));
                $this->db->prepare(
                    "INSERT INTO mentenanta
                        (vehicle_id, tip_interventie, record_type, centru_cost, descriere, status_interventie,
                         data_interventie, km_interventie, cost, cost_manopera, cost_piese, atelier,
                         piese_utilizate, observatii, source_intervention_id, created_at, updated_at)
                     VALUES
                        (:vehicle_id, :tip, :record_type, :center, :description, 'finalizata',
                         :date, :km, :cost_total, :cost_labor, :cost_parts, :supplier,
                         :parts_used, :notes, :source_id, :created_at, :updated_at)"
                )->execute([
                    ':vehicle_id' => (int) $intervention['vehicle_id'],
                    ':tip' => mb_substr(trim((string) $intervention['descriere']) ?: 'Intervenție programată', 0, 190),
                    ':record_type' => $intervention['tip_interventie'] === 'reparatie' ? 'reparatie' : 'intretinere',
                    ':center' => $this->nullIfEmpty((string) ($intervention['centru_cost'] ?? ''), 80),
                    ':description' => mb_substr(trim($diagnosis !== '' ? $diagnosis : (string) $intervention['descriere']), 0, 5000),
                    ':date' => $executionDate,
                    ':km' => $executionKm,
                    ':cost_total' => round($labor + $parts + $extra, 2),
                    ':cost_labor' => round($labor, 2),
                    ':cost_parts' => round($parts, 2),
                    ':supplier' => $this->nullIfEmpty((string) ($intervention['furnizor'] ?? ''), 190),
                    ':parts_used' => $replacedNames !== [] ? mb_substr(implode(', ', $replacedNames), 0, 2000) : null,
                    ':notes' => mb_substr('Din intervenția #' . $id . ($lines !== [] ? ': ' . implode('; ', $lines) : ''), 0, 5000),
                    ':source_id' => $id,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $maintenanceId = (int) $this->db->lastInsertId();
            }

            // 3. Inlocuirile confirmate -> istoricul componentei (montare), o singura data pe lucrare.
            $mounts = 0;
            $byId = array_column($items, null, 'id');
            foreach ($replacements as $itemId) {
                $item = $byId[$itemId];
                if (!empty($item['usage_id'])) {
                    continue;
                }
                $part = $this->stockPartFor($partIndex, $components, (string) $item['component_key']);
                if ($part === null) {
                    throw new InvalidArgumentException('Componenta „' . $this->itemLabel($itemId) . '” nu are piesă asociată în Stoc piese; deschide Reparații Auto o dată ca să fie creată, apoi reîncearcă.');
                }
                $warranty = [];
                if (!empty($item['garantie_luni'])) {
                    $warranty[] = $item['garantie_luni'] . ' luni';
                }
                if (!empty($item['garantie_pana_la'])) {
                    $warranty[] = 'până la ' . date('d.m.Y', (int) strtotime((string) $item['garantie_pana_la']));
                }
                $notes = 'Intervenția #' . $id
                    . ($item['pozitie'] ? ', ' . $item['pozitie'] : '')
                    . ($item['piesa_montata'] ? '; montat: ' . $item['piesa_montata'] : '')
                    . ($item['piesa_demontata'] ? '; demontat: ' . $item['piesa_demontata'] : '')
                    . ($warranty !== [] ? '; garanție ' . implode(', ', $warranty) : '');
                // Montare directa (piesa adusa de service): stocul nu scade, ca la Registrul de piese.
                $this->db->prepare(
                    'INSERT INTO mentenanta_piese_utilizari
                        (part_id, maintenance_id, scheduled_intervention_id, vehicle_id, cantitate,
                         cost_unitar, data_montare, km_montare, montata_de, observatii, direct_mount, created_at)
                     VALUES (:part, :maintenance, :scheduled, :vehicle, 1, :cost, :date, :km, :by, :notes, 1, :created_at)'
                )->execute([
                    ':part' => (int) $part['id'],
                    ':maintenance' => $maintenanceId,
                    ':scheduled' => $id,
                    // Componenta de pe semiremorca cuplata se monteaza pe semiremorca.
                    ':vehicle' => !empty($item['vehicle_id']) ? (int) $item['vehicle_id'] : (int) $intervention['vehicle_id'],
                    ':cost' => (float) $item['cost_piese'],
                    ':date' => (string) $item['data_montare'],
                    ':km' => $item['km_montare'] !== null ? (int) $item['km_montare'] : null,
                    ':by' => $this->nullIfEmpty((string) ($intervention['furnizor'] ?? ''), 190),
                    ':notes' => mb_substr($notes, 0, 2000),
                    ':created_at' => $now,
                ]);
                $usageId = (int) $this->db->lastInsertId();
                $this->db->prepare('UPDATE mentenanta_interventii_lucrari SET usage_id = :u, maintenance_id = :m WHERE id = :id')
                    ->execute([':u' => $usageId, ':m' => $maintenanceId, ':id' => $itemId]);
                $mounts++;
                $this->logEvent($id, 'inlocuire', 'Înlocuire înregistrată în istoricul componentei: ' . $this->itemLabel($itemId)
                    . ($item['km_montare'] !== null ? ' la ' . number_format((int) $item['km_montare'], 0, ',', '.') . ' km' : ''), ['usage_id' => $usageId], $userId);
            }
            $this->db->prepare('UPDATE mentenanta_interventii_lucrari SET maintenance_id = :m WHERE intervention_id = :i AND rezultat = \'executata\' AND maintenance_id IS NULL')
                ->execute([':m' => $maintenanceId, ':i' => $id]);

            // 4. Lucrarile amanate: raman nerezolvate aici si, la cerere, devin o interventie noua.
            $followUpId = null;
            if ($postponed !== [] && !empty($options['creeaza_continuare'])) {
                $this->db->prepare(
                    "INSERT INTO mentenanta_interventii_programate
                        (vehicle_id, tip_interventie, prioritate, data_programata, cost_estimat, furnizor, driver_id,
                         responsabil_user_id, client, centru_cost, descriere, sesizare_initiala, sesizare_la, sesizare_de,
                         status_interventie, interventie_origine_id, created_by, updated_by, created_at, updated_at)
                     VALUES
                        (:vehicle, :tip, :prio, :date, 0, :supplier, :driver,
                         :resp, :client, :center, :descr, :sesizare, :ses_la, :ses_de,
                         'de_verificat', :origin, :created_by, :updated_by, :created_at, :updated_at)"
                )->execute([
                    ':vehicle' => (int) $intervention['vehicle_id'],
                    ':tip' => $intervention['tip_interventie'],
                    ':prio' => $intervention['prioritate'] ?? 'normala',
                    ':date' => $this->isDate((string) ($options['data_continuare'] ?? '')) ? (string) $options['data_continuare'] : $executionDate,
                    ':supplier' => $intervention['furnizor'],
                    ':driver' => $intervention['driver_id'],
                    ':resp' => $intervention['responsabil_user_id'],
                    ':client' => $intervention['client'],
                    ':center' => $intervention['centru_cost'],
                    ':descr' => 'Lucrări amânate din intervenția #' . $id,
                    ':sesizare' => 'Lucrări amânate din intervenția #' . $id,
                    ':ses_la' => $now,
                    ':ses_de' => $userId,
                    ':origin' => $id,
                    ':created_by' => $userId,
                    ':updated_by' => $userId,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $followUpId = (int) $this->db->lastInsertId();
                foreach ($postponed as $item) {
                    $this->insertItem($followUpId, [
                        'component_key' => $item['component_key'] ?? '',
                        'ramura_principala' => $item['ramura_principala'] ?? '',
                        'denumire_libera' => $item['denumire_libera'] ?? '',
                        'pozitie' => $item['pozitie'] ?? '',
                        'operatiune' => $item['operatiune'],
                        'stare_evaluare' => $item['stare_evaluare'],
                        'diagnostic' => $item['diagnostic'] ?? '',
                        'vehicle_id' => $item['vehicle_id'] ?? null,
                        'observatii' => 'Amânată în intervenția #' . $id,
                    ], $userId);
                }
                $this->logEvent($followUpId, 'creare', 'Intervenție creată din lucrările amânate ale intervenției #' . $id, ['origine' => $id], $userId);
            }

            // 5. Statusul final. Disponibilitatea vehiculului NU se schimba automat.
            $this->db->prepare(
                "UPDATE mentenanta_interventii_programate
                 SET status_interventie = 'finalizata', converted_maintenance_id = :m, data_executie = :d,
                     finalizat_la = :now, finalizat_de = :u, updated_at = :now2, updated_by = :u2
                 WHERE id = :id"
            )->execute([':m' => $maintenanceId, ':d' => $executionDate, ':now' => $now, ':u' => $userId, ':now2' => $now, ':u2' => $userId, ':id' => $id]);
            $this->logEvent($id, 'finalizare', 'Intervenție finalizată'
                . ' — ' . count($lines) . ' lucrări executate'
                . ($postponed !== [] ? ', ' . count($postponed) . ' amânate' : '')
                . ($mounts > 0 ? ', ' . $mounts . ' înlocuiri în istoricul componentelor' : ''), [
                    'maintenance_id' => $maintenanceId,
                    'follow_up_id' => $followUpId,
                    'data_executie' => $executionDate,
                ], $userId);

            $this->db->commit();
            return ['maintenance_id' => $maintenanceId, 'montari' => $mounts, 'follow_up_id' => $followUpId, 'already' => false];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** Ca OcrPartsMaintenanceSyncService: piesa din stoc a componentei, dupa categorie + denumire. */
    private function stockPartFor(array $partIndex, array $components, string $componentKey): ?array
    {
        $component = $components[$componentKey] ?? null;
        if ($component === null) {
            return null;
        }
        $key = static fn (string $value): string => preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower(trim($value), 'UTF-8')) ?? '';
        $nameKey = $key((string) $component['name']);

        return $partIndex['by_category_name'][$key((string) $component['category']) . '|' . $nameKey]
            ?? $partIndex['by_name'][$nameKey]
            ?? null;
    }

    // ------------------------------------------------------------------ utilitare

    private function userName(int $userId): string
    {
        $stmt = $this->db->prepare('SELECT nume FROM utilizatori WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $name = $stmt->fetchColumn();
        return $name !== false ? (string) $name : 'Utilizator #' . $userId;
    }

    private function prefixKeys(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[':' . $key] = $value;
        }
        return $result;
    }

    private function timeOrNull(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value . ':00' : null;
    }

    private function isDate(string $value): bool
    {
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function nullIfEmpty(string $value, int $max): ?string
    {
        $value = trim($value);
        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}
