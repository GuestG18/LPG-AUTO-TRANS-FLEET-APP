<?php
declare(strict_types=1);

/**
 * Catalogul de componente din Reparatii Auto (?page=mentenanta&action=auto):
 * categoriile 1-17 (fixe, in cod) si componentele lor (tabela mentenanta_auto_componente).
 *
 * Tabela se umple O SINGURA DATA din data/componente critice aplicatie.xlsx, cu aceleasi chei
 * ca inainte; de atunci componentele se adauga / redenumesc / elimina din pagina Reparatii
 * Auto, iar Excel-ul nu mai e citit. Cheia "<categorie>-<nr>" (ex. "1-3") nu se schimba si
 * nu se refoloseste: o folosesc configurarile pe vehicul, Registrul de piese si invatarea.
 * Eliminarea e logica (activ = 0): istoricul ramane, componenta dispare din liste.
 *
 * Folosit de pagina Reparatii Auto (MaintenanceController) si de Registrul de piese
 * (?page=ocr_piese), care clasifica articolele facturilor pe aceleasi componente.
 */
class AutoComponentCatalogService
{
    /** Subcategoriile din arbore si categoriile lor (ca pe pagina Reparatii Auto). */
    public const CATEGORY_GROUPS = [
        'sasiu' => [1, 2, 3, 4, 5, 6, 7, 8, 9],
        'hidraulic' => [10],
        'livrare_gaz' => [11, 12, 13, 14, 15, 16, 17],
    ];

    public const PRIMARY_LABELS = ['sasiu' => 'Sasiu', 'rezervor' => 'Rezervor'];

    public const SUBCATEGORY_LABELS = ['sasiu' => 'Sasiu', 'hidraulic' => 'Hidraulic', 'livrare_gaz' => 'Livrare Gaz'];

    public const CATEGORY_NAMES = [
        1 => 'Suspensie', 2 => 'Rulare', 3 => 'Franare', 4 => 'Racire', 5 => 'Electrica', 6 => 'Motor',
        7 => 'Comfort', 8 => 'Evacuare', 9 => 'Directie', 10 => 'Hidraulic', 11 => 'Livrare Gaz',
        12 => 'Calculator Livrare', 13 => 'Imprimare Bon', 14 => 'Corp Masurator', 15 => 'Degazor',
        16 => 'Valva Diferentiala', 17 => 'Rezervor Tank',
    ];

    private const CATEGORY_ICONS = [
        1 => 'bi-truck-flatbed', 2 => 'bi-disc', 3 => 'bi-record-circle', 4 => 'bi-thermometer-snow',
        5 => 'bi-lightning-charge', 6 => 'bi-gear-wide-connected', 7 => 'bi-sliders', 8 => 'bi-wind',
        9 => 'bi-sign-turn-right', 10 => 'bi-droplet-half', 11 => 'bi-truck', 12 => 'bi-calculator',
        13 => 'bi-printer-fill', 14 => 'bi-speedometer2', 15 => 'bi-filter-circle-fill',
        16 => 'bi-diagram-3-fill', 17 => 'bi-hdd-fill',
    ];

    /** @var array<int, array<string, mixed>>|null categoriile cu componentele active */
    private static ?array $cache = null;

    /** @var array<int, array<string, mixed>>|null toate randurile (inclusiv eliminate) */
    private static ?array $rowsCache = null;

    private static bool $tableReady = false;

    private ?PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db;
    }

    private function db(): PDO
    {
        return $this->db ??= get_pdo();
    }

    /** @return array<int, array<string, mixed>> categoriile cu componentele lor ACTIVE */
    public function categories(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $categories = [];
        foreach (self::CATEGORY_NAMES as $id => $name) {
            $categories[$id] = [
                'id' => $id,
                'title' => mb_strtoupper($name, 'UTF-8'),
                'name' => $name,
                'count' => 0,
                'icon' => self::CATEGORY_ICONS[$id] ?? 'bi-circle',
                'components' => [],
            ];
        }
        foreach ($this->rows() as $row) {
            $categoryId = (int) $row['category_id'];
            if ((int) $row['activ'] !== 1 || !isset($categories[$categoryId])) {
                continue;
            }
            $categories[$categoryId]['components'][] = $this->componentFromRow($row, (string) $categories[$categoryId]['name']);
        }
        foreach ($categories as &$category) {
            $category['count'] = count($category['components']);
        }
        unset($category);

        return self::$cache = $categories;
    }

    /**
     * Componentele dupa cheie (implicit doar cele active; cu $includeRemoved si cele
     * eliminate, pentru referintele vechi: articole trimise, configurari).
     *
     * @return array<string, array{key:string, category_id:int, category:string, name:string, code:string, active:bool}>
     */
    public function components(bool $includeRemoved = false): array
    {
        $result = [];
        foreach ($this->rows() as $row) {
            if (!$includeRemoved && (int) $row['activ'] !== 1) {
                continue;
            }
            $categoryId = (int) $row['category_id'];
            $result[(string) $row['component_key']] = [
                'key' => (string) $row['component_key'],
                'category_id' => $categoryId,
                'category' => self::CATEGORY_NAMES[$categoryId] ?? '',
                'name' => (string) $row['nume'],
                'code' => (string) $row['cod'],
                'active' => (int) $row['activ'] === 1,
            ];
        }

        return $result;
    }

    /** @return array<int, array{key:string, name:string, code:string}> componentele eliminate dintr-o categorie */
    public function removedComponents(int $categoryId): array
    {
        $result = [];
        foreach ($this->rows() as $row) {
            if ((int) $row['activ'] === 0 && (int) $row['category_id'] === $categoryId) {
                $result[] = ['key' => (string) $row['component_key'], 'name' => (string) $row['nume'], 'code' => (string) $row['cod']];
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Modificari (pagina Reparatii Auto)
    // -------------------------------------------------------------------------

    /**
     * Componenta noua intr-o categorie. Cheia si codul nu se refolosesc niciodata.
     *
     * @return array{key:string, code:string}
     */
    public function addComponent(int $categoryId, string $name, string $details = ''): array
    {
        $name = $this->cleanName($name);
        if (!isset(self::CATEGORY_NAMES[$categoryId])) {
            throw new InvalidArgumentException('Categorie necunoscută.');
        }
        $this->assertUniqueName($categoryId, $name, null);

        $stmt = $this->db()->prepare('SELECT COALESCE(MAX(nr), 0) FROM mentenanta_auto_componente WHERE category_id = :c');
        $stmt->execute([':c' => $categoryId]);
        $nr = (int) $stmt->fetchColumn() + 1;

        // Codul devine si codul piesei din stoc (unic acolo): sarim peste codurile ocupate.
        $codeNr = $nr;
        do {
            $code = $this->buildAutoComponentCode(self::CATEGORY_NAMES[$categoryId], $codeNr++);
            $taken = $this->db()->prepare(
                'SELECT (SELECT COUNT(*) FROM mentenanta_piese WHERE cod_piesa = :c1)
                      + (SELECT COUNT(*) FROM mentenanta_auto_componente WHERE cod = :c2)'
            );
            $taken->execute([':c1' => $code, ':c2' => $code]);
        } while ((int) $taken->fetchColumn() > 0);

        $now = date('Y-m-d H:i:s');
        $this->db()->prepare(
            "INSERT INTO mentenanta_auto_componente
                (component_key, category_id, nr, nume, nume_original, cod, detalii, note, monitorizare, activ, sursa, created_at, updated_at)
             VALUES (:k, :c, :nr, :nume, :orig, :cod, :detalii, NULL, NULL, 1, 'manual', :created, :updated)"
        )->execute([
            ':k' => $categoryId . '-' . $nr, ':c' => $categoryId, ':nr' => $nr, ':nume' => $name, ':orig' => $name,
            ':cod' => $code, ':detalii' => trim($details) !== '' ? mb_substr(trim($details), 0, 2000) : null,
            ':created' => $now, ':updated' => $now,
        ]);
        self::resetCache();

        return ['key' => $categoryId . '-' . $nr, 'code' => $code];
    }

    /**
     * Redenumire. Piesa din stoc a componentei (legata prin cod / nume) si numele salvate in
     * configurari si in Registrul de piese se actualizeaza, ca legatura sa nu se rupa.
     */
    public function renameComponent(string $key, string $newName): void
    {
        $row = $this->row($key);
        $newName = $this->cleanName($newName);
        if ($newName === $row['nume']) {
            return;
        }
        $this->assertUniqueName((int) $row['category_id'], $newName, $key);
        $categoryName = self::CATEGORY_NAMES[(int) $row['category_id']] ?? '';
        $now = date('Y-m-d H:i:s');

        $db = $this->db();
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            // Piesa din stoc: dupa cod (asa a fost creata), altfel dupa categorie + nume vechi.
            $parts = $db->prepare('SELECT id, cod_piesa, denumire, categorie FROM mentenanta_piese WHERE cod_piesa = :cod OR categorie = :cat');
            $parts->execute([':cod' => $row['cod'], ':cat' => $categoryName]);
            $partId = null;
            foreach ($parts->fetchAll(PDO::FETCH_ASSOC) ?: [] as $part) {
                if ($part['cod_piesa'] === $row['cod']) {
                    $partId = (int) $part['id'];
                    break;
                }
                if ($partId === null && self::lookupKey((string) $part['denumire']) === self::lookupKey((string) $row['nume'])) {
                    $partId = (int) $part['id'];
                }
            }
            if ($partId !== null) {
                $db->prepare('UPDATE mentenanta_piese SET denumire = :n, updated_at = :u WHERE id = :id')
                    ->execute([':n' => $newName, ':u' => $now, ':id' => $partId]);
            }
            $db->prepare('UPDATE mentenanta_auto_componente SET nume = :n, updated_at = :u WHERE component_key = :k')
                ->execute([':n' => $newName, ':u' => $now, ':k' => $key]);
            $db->prepare('UPDATE mentenanta_auto_configurari SET component_name = :n WHERE component_key = :k')
                ->execute([':n' => $newName, ':k' => $key]);
            if ($this->tableExists('ocr_reparatii_articole')) {
                $db->prepare('UPDATE ocr_reparatii_articole SET auto_component_name = :n WHERE auto_component_key = :k')
                    ->execute([':n' => mb_substr($newName, 0, 190), ':k' => $key]);
            }
            if ($own) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
        self::resetCache();
    }

    /** Eliminare logica / restaurare. Istoricul (montari, configurari, facturi) ramane. */
    public function setActive(string $key, bool $active): void
    {
        $row = $this->row($key);
        if ($active) {
            $this->assertUniqueName((int) $row['category_id'], (string) $row['nume'], $key);
        }
        $this->db()->prepare('UPDATE mentenanta_auto_componente SET activ = :a, updated_at = :u WHERE component_key = :k')
            ->execute([':a' => $active ? 1 : 0, ':u' => date('Y-m-d H:i:s'), ':k' => $key]);
        self::resetCache();
    }

    /** @return array{configurari:int, montari:int} cat istoric are o componenta (pentru mesajul de eliminare) */
    public function usage(string $key): array
    {
        $row = $this->row($key);
        $configs = $this->db()->prepare('SELECT COUNT(*) FROM mentenanta_auto_configurari WHERE component_key = :k');
        $configs->execute([':k' => $key]);
        $mounts = $this->db()->prepare(
            'SELECT COUNT(*) FROM mentenanta_piese_utilizari u JOIN mentenanta_piese p ON p.id = u.part_id WHERE p.cod_piesa = :cod'
        );
        $mounts->execute([':cod' => $row['cod']]);

        return ['configurari' => (int) $configs->fetchColumn(), 'montari' => (int) $mounts->fetchColumn()];
    }

    public static function resetCache(): void
    {
        self::$cache = null;
        self::$rowsCache = null;
    }

    // -------------------------------------------------------------------------
    // Tabela (creata si umpluta din Excel la prima folosire)
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function rows(): array
    {
        if (self::$rowsCache !== null) {
            return self::$rowsCache;
        }
        $this->ensureTable();
        $rows = $this->db()->query(
            'SELECT * FROM mentenanta_auto_componente ORDER BY category_id, nr'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            $this->seedFromExcel();
            $rows = $this->db()->query(
                'SELECT * FROM mentenanta_auto_componente ORDER BY category_id, nr'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        return self::$rowsCache = $rows;
    }

    /** @return array<string, mixed> */
    private function row(string $key): array
    {
        foreach ($this->rows() as $row) {
            if ((string) $row['component_key'] === $key) {
                return $row;
            }
        }
        throw new InvalidArgumentException('Componentă inexistentă.');
    }

    /**
     * CREATE doar daca tabela lipseste (verificare fara DDL): un CREATE TABLE face COMMIT
     * implicit, iar catalogul se citeste si din interiorul tranzactiilor.
     */
    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        if (!$this->tableExists('mentenanta_auto_componente')) {
            $this->db()->exec(
                "CREATE TABLE mentenanta_auto_componente (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    component_key VARCHAR(10) NOT NULL,
                    category_id TINYINT UNSIGNED NOT NULL,
                    nr SMALLINT UNSIGNED NOT NULL,
                    nume VARCHAR(190) NOT NULL,
                    nume_original VARCHAR(190) NULL,
                    cod VARCHAR(30) NOT NULL,
                    detalii TEXT NULL,
                    note TEXT NULL,
                    monitorizare JSON NULL,
                    activ TINYINT(1) NOT NULL DEFAULT 1,
                    sursa VARCHAR(10) NOT NULL DEFAULT 'manual',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE KEY uq_mac_key (component_key),
                    UNIQUE KEY uq_mac_cat_nr (category_id, nr),
                    KEY idx_mac_cat (category_id, activ)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        self::$tableReady = true;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute([':t' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Umplerea initiala, cu exact cheile / codurile pe care le dadea Excel-ul. */
    private function seedFromExcel(): void
    {
        $insert = $this->db()->prepare(
            "INSERT IGNORE INTO mentenanta_auto_componente
                (component_key, category_id, nr, nume, nume_original, cod, detalii, note, monitorizare, activ, sursa, created_at, updated_at)
             VALUES (:k, :c, :nr, :nume, :orig, :cod, :detalii, :note, :mon, 1, 'excel', :created, :updated)"
        );
        $now = date('Y-m-d H:i:s');
        foreach ($this->loadFromExcel() as $category) {
            foreach ($category['components'] as $component) {
                $insert->execute([
                    ':k' => $component['id'], ':c' => $component['category_id'], ':nr' => $component['nr'],
                    ':nume' => $component['name'], ':orig' => $component['raw_name'], ':cod' => $component['code'],
                    ':detalii' => $component['details'] !== '' ? $component['details'] : null,
                    ':note' => $component['notes'] !== '' ? $component['notes'] : null,
                    ':mon' => json_encode($component['monitoring_by_vehicle'], JSON_UNESCAPED_UNICODE),
                    ':created' => $now, ':updated' => $now,
                ]);
            }
        }
    }

    /** Aceeasi forma ca inainte (Excel), ca pagina Reparatii Auto sa nu se schimbe. */
    private function componentFromRow(array $row, string $categoryName): array
    {
        $details = trim((string) ($row['detalii'] ?? ''));
        $monitoring = json_decode((string) ($row['monitorizare'] ?? ''), true);

        return [
            'id' => (string) $row['component_key'],
            'nr' => (int) $row['nr'],
            'category_id' => (int) $row['category_id'],
            'name' => (string) $row['nume'],
            'raw_name' => (string) ($row['nume_original'] ?? $row['nume']),
            'code' => (string) $row['cod'],
            'description' => $details !== ''
                ? $this->formatAutoSentence($details)
                : 'Componenta configurabila pentru categoria ' . $categoryName . '.',
            'details' => $details,
            'notes' => (string) ($row['note'] ?? ''),
            // MySQL reordoneaza cheile JSON: refacem ordinea fixa (camion, cap tractor, semiremorca, rezervor).
            'monitoring_by_vehicle' => [
                'camion' => (string) ($monitoring['camion'] ?? ''),
                'cap_tractor' => (string) ($monitoring['cap_tractor'] ?? ''),
                'semiremorca' => (string) ($monitoring['semiremorca'] ?? ''),
                'rezervor' => (string) ($monitoring['rezervor'] ?? ''),
            ],
            'interval' => '30.000',
            'warning' => '25.000',
            'critical' => '28.000',
            'lifetime' => '35.000',
            'wear' => null,
            'configured' => false,
            'photo_url' => '',
            'photo_original' => '',
            'garantie_piesa' => '',
            'garantie_manopera' => '',
            'warranty_status' => 'red',
            'warranty_label' => 'Fara garantie',
        ];
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (mb_strlen($name) < 2) {
            throw new InvalidArgumentException('Scrie numele componentei.');
        }
        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Numele componentei e prea lung (max. 120 caractere).');
        }

        return $name;
    }

    private function assertUniqueName(int $categoryId, string $name, ?string $exceptKey): void
    {
        foreach ($this->rows() as $row) {
            if ((int) $row['category_id'] === $categoryId && (int) $row['activ'] === 1
                && (string) $row['component_key'] !== $exceptKey
                && self::lookupKey((string) $row['nume']) === self::lookupKey($name)) {
                throw new InvalidArgumentException('Există deja componenta „' . $row['nume'] . '” în această categorie.');
            }
        }
    }

    /** Ca MaintenanceModel::autoPartLookupKey: doar litere mici si cifre. */
    private static function lookupKey(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/iu', '', mb_strtolower(trim($value), 'UTF-8')) ?? '';
    }

    /** Subcategoria (din arbore) a unei categorii. */
    public static function subcategoryFor(int $categoryId): ?string
    {
        foreach (self::CATEGORY_GROUPS as $subcategory => $ids) {
            if (in_array($categoryId, $ids, true)) {
                return $subcategory;
            }
        }

        return null;
    }

    /**
     * Ramura implicita: Livrare Gaz exista doar sub Rezervor; restul pe Sasiu
     * (operatorul o poate muta pe Rezervor, unde exista si Sasiu / Hidraulic).
     *
     * @return array{0:string,1:string}|null [primary, subcategory]
     */
    public static function defaultPlacement(int $categoryId): ?array
    {
        $subcategory = self::subcategoryFor($categoryId);
        if ($subcategory === null) {
            return null;
        }

        return [$subcategory === 'livrare_gaz' ? 'rezervor' : 'sasiu', $subcategory];
    }

    /** Ramura + subcategorie compatibile (Livrare Gaz nu exista sub Sasiu). */
    public static function isValidPlacement(string $primary, string $subcategory): bool
    {
        return isset(self::PRIMARY_LABELS[$primary], self::SUBCATEGORY_LABELS[$subcategory])
            && !($primary === 'sasiu' && $subcategory === 'livrare_gaz');
    }

    private function loadFromExcel(): array
    {
        $categoryNames = [
            1 => 'Suspensie',
            2 => 'Rulare',
            3 => 'Franare',
            4 => 'Racire',
            5 => 'Electrica',
            6 => 'Motor',
            7 => 'Comfort',
            8 => 'Evacuare',
            9 => 'Directie',
            10 => 'Hidraulic',
            11 => 'Livrare Gaz',
            12 => 'Calculator Livrare',
            13 => 'Imprimare Bon',
            14 => 'Corp Masurator',
            15 => 'Degazor',
            16 => 'Valva Diferentiala',
            17 => 'Rezervor Tank',
        ];
        $icons = [
            1 => 'bi-truck-flatbed',
            2 => 'bi-disc',
            3 => 'bi-record-circle',
            4 => 'bi-thermometer-snow',
            5 => 'bi-lightning-charge',
            6 => 'bi-gear-wide-connected',
            7 => 'bi-sliders',
            8 => 'bi-wind',
            9 => 'bi-sign-turn-right',
            10 => 'bi-droplet-half',
            11 => 'bi-truck',
            12 => 'bi-calculator',
            13 => 'bi-printer-fill',
            14 => 'bi-speedometer2',
            15 => 'bi-filter-circle-fill',
            16 => 'bi-diagram-3-fill',
            17 => 'bi-hdd-fill',
        ];

        $categories = [];
        foreach ($categoryNames as $id => $name) {
            $categories[$id] = [
                'id' => $id,
                'title' => mb_strtoupper($name, 'UTF-8'),
                'name' => $name,
                'count' => 0,
                'icon' => $icons[$id] ?? 'bi-circle',
                'components' => [],
            ];
        }

        $rows = $this->readAutoExcelRows();
        $currentCategory = 0;
        $categoryCounters = [];
        foreach ($rows as $row) {
            $rawCategory = trim((string) ($row['A'] ?? ''));
            if ($rawCategory !== '' && preg_match('/^(\d+)\.?\s*(.+)$/u', $rawCategory, $matches)) {
                $currentCategory = (int) $matches[1];
            }
            if ($currentCategory <= 0 || !isset($categories[$currentCategory])) {
                continue;
            }

            $rawName = trim((string) ($row['B'] ?? ''));
            if ($rawName === '') {
                continue;
            }

            $categoryCounters[$currentCategory] = ($categoryCounters[$currentCategory] ?? 0) + 1;
            $nr = (int) $categoryCounters[$currentCategory];
            $displayName = $this->formatAutoComponentName($rawName);
            $monitoringByVehicle = [
                'camion' => trim((string) ($row['C'] ?? '')),
                'cap_tractor' => trim((string) ($row['D'] ?? '')),
                'semiremorca' => trim((string) ($row['E'] ?? '')),
                'rezervor' => trim((string) ($row['F'] ?? '')),
            ];
            $details = trim((string) ($row['G'] ?? ''));
            $notes = trim((string) ($row['H'] ?? ''));

            $categories[$currentCategory]['components'][] = [
                'id' => $currentCategory . '-' . $nr,
                'nr' => $nr,
                'category_id' => $currentCategory,
                'name' => $displayName,
                'raw_name' => $rawName,
                'code' => $this->buildAutoComponentCode((string) $categories[$currentCategory]['name'], $nr),
                'description' => $details !== ''
                    ? $this->formatAutoSentence($details)
                    : 'Componenta configurabila pentru categoria ' . (string) $categories[$currentCategory]['name'] . '.',
                'details' => $details,
                'notes' => $notes,
                'monitoring_by_vehicle' => $monitoringByVehicle,
                'interval' => '30.000',
                'warning' => '25.000',
                'critical' => '28.000',
                'lifetime' => '35.000',
                'wear' => null,
                'configured' => false,
                'photo_url' => '',
                'photo_original' => '',
                'garantie_piesa' => '',
                'garantie_manopera' => '',
                'warranty_status' => 'red',
                'warranty_label' => 'Fara garantie',
            ];
        }

        foreach ($categories as &$category) {
            $category['count'] = count($category['components']);
        }
        unset($category);

        return $categories;
    }

    private function readAutoExcelRows(): array
    {
        $path = dirname(BASE_PATH) . '/data/componente critice aplicatie.xlsx';
        if (!is_file($path) || !class_exists(ZipArchive::class)) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml) && $sharedXml !== '') {
            $xml = simplexml_load_string($sharedXml);
            if ($xml instanceof SimpleXMLElement) {
                foreach ($xml->si as $item) {
                    $text = '';
                    if (isset($item->t)) {
                        $text = (string) $item->t;
                    } else {
                        foreach ($item->r as $run) {
                            $text .= (string) $run->t;
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!is_string($sheetXml) || $sheetXml === '') {
            return [];
        }

        $sheet = simplexml_load_string($sheetXml);
        if (!$sheet instanceof SimpleXMLElement) {
            return [];
        }

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $rowIndex = (int) ($row['r'] ?? 0);
            if ($rowIndex < 2) {
                continue;
            }

            $values = [];
            foreach ($row->c as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $column = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?? '';
                if ($column === '') {
                    continue;
                }
                $values[$column] = $this->excelCellValue($cell, $sharedStrings);
            }
            $rows[] = $values;
        }

        return $rows;
    }

    private function excelCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        if ($type === 's') {
            $index = (int) ($cell->v ?? -1);
            return trim((string) ($sharedStrings[$index] ?? ''));
        }
        if ($type === 'inlineStr') {
            return trim((string) ($cell->is->t ?? ''));
        }

        return trim((string) ($cell->v ?? ''));
    }

    private function formatAutoComponentName(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        $value = mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        $replacements = [
            'Abs' => 'ABS',
            'Cpu' => 'CPU',
            'Gpl' => 'GPL',
            'Pto' => 'PTO',
            'Adr' => 'ADR',
            'A/C' => 'A/C',
            'Pg' => 'PG',
            'Cm' => 'CM',
        ];
        return strtr($value, $replacements);
    }

    private function formatAutoSentence(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        if ($value === '') {
            return '';
        }
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8') . '.';
    }

    private function buildAutoComponentCode(string $categoryName, int $nr): string
    {
        $parts = preg_split('/\s+/', mb_strtoupper($categoryName, 'UTF-8')) ?: [];
        $codeParts = [];
        foreach ($parts as $part) {
            $part = preg_replace('/[^A-Z0-9]/', '', $part) ?? '';
            if ($part !== '') {
                $codeParts[] = mb_substr($part, 0, 3, 'UTF-8');
            }
        }
        $prefix = implode('-', array_slice($codeParts, 0, 2));
        return ($prefix !== '' ? $prefix : 'CMP') . '-' . str_pad((string) $nr, 3, '0', STR_PAD_LEFT);
    }
}
