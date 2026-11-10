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

    /**
     * Tipul articolului: proprietate independenta de categorie (un O-ring din Livrare Gaz
     * poate fi „Consumabil” fara sa fie mutat din categoria lui).
     */
    public const ITEM_TYPES = [
        'componenta' => 'Componentă mecanică',
        'uzura' => 'Piesă de uzură',
        'consumabil' => 'Consumabil',
        'fluid' => 'Fluid tehnic',
        'ansamblu' => 'Ansamblu / echipament',
    ];

    /** Etichete scurte pentru selectorul din tabelul de componente. */
    public const ITEM_TYPES_SHORT = [
        'componenta' => 'Mecanică',
        'uzura' => 'Uzură',
        'consumabil' => 'Consumabil',
        'fluid' => 'Fluid tehnic',
        'ansamblu' => 'Ansamblu',
    ];

    /** Tipurile de vehicul pe care se poate afisa o ramura (categorie principala). */
    public const VEHICLE_TYPES = ['camion' => 'Camion', 'cap_tractor' => 'Cap tractor', 'semiremorca' => 'Semiremorcă'];

    /** Iconitele din care se alege la categoriile noi. */
    public const ICON_CHOICES = [
        'bi-droplet', 'bi-droplet-fill', 'bi-droplet-half', 'bi-moisture', 'bi-funnel', 'bi-funnel-fill', 'bi-record2',
        'bi-circle-square', 'bi-brush', 'bi-box-seam', 'bi-tools', 'bi-wrench', 'bi-gear', 'bi-gear-wide-connected',
        'bi-lightning-charge', 'bi-thermometer-half', 'bi-shield-check', 'bi-fire', 'bi-truck', 'bi-truck-front', 'bi-circle',
    ];

    /**
     * Arborele initial (umple tabelele mentenanta_auto_ramuri / _subcategorii / _categorii o singura data).
     * Ramurile, subcategoriile si categoriile 1-17 sunt „sistem”: se pot redenumi, dar nu dezactiva.
     * Ramura „Consumabile & Fluide Tehnice” e adaugata ca date, nu ca logica.
     */
    private const SEED_PRIMARIES = [
        'sasiu' => ['Sasiu', 'bi-truck-front', 1, 'camion,cap_tractor,semiremorca', 'sasiu,hidraulic', 1],
        'rezervor' => ['Rezervor', 'bi-droplet-half', 2, 'camion,semiremorca', 'sasiu,hidraulic,livrare_gaz', 1],
        'consumabile' => ['Consumabile & Fluide Tehnice', 'bi-droplet', 3, 'camion,cap_tractor,semiremorca',
            'uleiuri_lubrifianti,lichide_tehnice,vaseline_unsori,filtre_consumabile,garnituri_etansari', 0],
    ];

    private const SEED_SUBCATEGORIES = [
        'sasiu' => ['Sasiu', 'bi-truck-front', 1, 1],
        'hidraulic' => ['Hidraulic', 'bi-droplet', 2, 1],
        'livrare_gaz' => ['Livrare Gaz', 'bi-fire', 3, 1],
        'uleiuri_lubrifianti' => ['Uleiuri și lubrifianți', 'bi-droplet-fill', 10, 0],
        'lichide_tehnice' => ['Lichide tehnice', 'bi-moisture', 11, 0],
        'vaseline_unsori' => ['Vaseline și unsori', 'bi-brush', 12, 0],
        'filtre_consumabile' => ['Filtre și consumabile de service', 'bi-funnel', 13, 0],
        'garnituri_etansari' => ['Garnituri și etanșări', 'bi-record2', 14, 0],
    ];

    /** Categoriile noi (18+) ale ramurii Consumabile: [id => [subcategorie, nume, icon, tip articol implicit]]. */
    private const SEED_EXTRA_CATEGORIES = [
        18 => ['uleiuri_lubrifianti', 'Uleiuri și lubrifianți', 'bi-droplet-fill', 'fluid'],
        19 => ['lichide_tehnice', 'Lichide tehnice', 'bi-moisture', 'fluid'],
        20 => ['vaseline_unsori', 'Vaseline și unsori', 'bi-brush', 'consumabil'],
        21 => ['filtre_consumabile', 'Filtre și consumabile de service', 'bi-funnel', 'consumabil'],
        22 => ['garnituri_etansari', 'Garnituri și etanșări', 'bi-record2', 'consumabil'],
    ];

    /** @var array<string, mixed>|null arborele (ramuri / subcategorii / categorii), inclusiv inactive */
    private static ?array $taxonomyCache = null;

    private static bool $taxonomyReady = false;

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
        foreach (self::taxonomy($this->db)['categories'] as $id => $category) {
            if (!$category['active']) {
                continue;
            }
            $categories[$id] = [
                'id' => $id,
                'title' => mb_strtoupper((string) $category['name'], 'UTF-8'),
                'name' => (string) $category['name'],
                'count' => 0,
                'icon' => (string) $category['icon'],
                'subcategory' => (string) $category['subcategory'],
                'item_type' => (string) $category['item_type'],
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
        $names = self::categoryNames(true);
        foreach ($this->rows() as $row) {
            if (!$includeRemoved && (int) $row['activ'] !== 1) {
                continue;
            }
            $categoryId = (int) $row['category_id'];
            $result[(string) $row['component_key']] = [
                'key' => (string) $row['component_key'],
                'category_id' => $categoryId,
                'category' => $names[$categoryId] ?? '',
                'name' => (string) $row['nume'],
                'code' => (string) $row['cod'],
                'item_type' => (string) ($row['tip_articol'] ?? 'componenta'),
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
        $category = self::taxonomy($this->db)['categories'][$categoryId] ?? null;
        if ($category === null || !$category['active']) {
            throw new InvalidArgumentException('Categorie necunoscută.');
        }
        $categoryName = (string) $category['name'];
        $this->assertUniqueName($categoryId, $name, null);

        $stmt = $this->db()->prepare('SELECT COALESCE(MAX(nr), 0) FROM mentenanta_auto_componente WHERE category_id = :c');
        $stmt->execute([':c' => $categoryId]);
        $nr = (int) $stmt->fetchColumn() + 1;

        // Codul devine si codul piesei din stoc (unic acolo): sarim peste codurile ocupate.
        $codeNr = $nr;
        do {
            $code = $this->buildAutoComponentCode($categoryName, $codeNr++);
            $taken = $this->db()->prepare(
                'SELECT (SELECT COUNT(*) FROM mentenanta_piese WHERE cod_piesa = :c1)
                      + (SELECT COUNT(*) FROM mentenanta_auto_componente WHERE cod = :c2)'
            );
            $taken->execute([':c1' => $code, ':c2' => $code]);
        } while ((int) $taken->fetchColumn() > 0);

        $now = date('Y-m-d H:i:s');
        $this->db()->prepare(
            "INSERT INTO mentenanta_auto_componente
                (component_key, category_id, nr, nume, nume_original, cod, detalii, note, monitorizare, tip_articol, activ, sursa, created_at, updated_at)
             VALUES (:k, :c, :nr, :nume, :orig, :cod, :detalii, NULL, NULL, :tip, 1, 'manual', :created, :updated)"
        )->execute([
            ':k' => $categoryId . '-' . $nr, ':c' => $categoryId, ':nr' => $nr, ':nume' => $name, ':orig' => $name,
            ':tip' => (string) $category['item_type'],
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
        $categoryName = self::categoryNames(true)[(int) $row['category_id']] ?? '';
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

    /** Tipul articolului (independent de categorie). */
    public function setItemType(string $key, string $itemType): void
    {
        $this->row($key);
        if (!isset(self::ITEM_TYPES[$itemType])) {
            throw new InvalidArgumentException('Tip articol necunoscut.');
        }
        $this->db()->prepare('UPDATE mentenanta_auto_componente SET tip_articol = :t, updated_at = :u WHERE component_key = :k')
            ->execute([':t' => $itemType, ':u' => date('Y-m-d H:i:s'), ':k' => $key]);
        self::resetCache();
    }

    public static function resetCache(): void
    {
        self::$cache = null;
        self::$rowsCache = null;
        self::$taxonomyCache = null;
    }

    // -------------------------------------------------------------------------
    // Arborele de categorii (ramuri → subcategorii → categorii), administrat din pagina
    // -------------------------------------------------------------------------

    /**
     * Arborele complet, inclusiv elementele dezactivate (flag 'active').
     * Daca tabelele lipsesc si suntem intr-o tranzactie (DDL ar face COMMIT), se foloseste
     * arborele initial din cod, fara categoriile noi.
     *
     * @return array{primaries: array<string, array<string, mixed>>, subcategories: array<string, array<string, mixed>>, categories: array<int, array<string, mixed>>}
     */
    public static function taxonomy(?PDO $db = null): array
    {
        if (self::$taxonomyCache !== null) {
            return self::$taxonomyCache;
        }
        try {
            $db ??= get_pdo();
            if (!self::ensureTaxonomy($db)) {
                return self::seedTaxonomy();
            }
            $primaries = [];
            foreach ($db->query('SELECT * FROM mentenanta_auto_ramuri ORDER BY ordine, nume')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $primaries[(string) $row['cheie']] = [
                    'key' => (string) $row['cheie'],
                    'label' => (string) $row['nume'],
                    'icon' => (string) ($row['icon'] ?: 'bi-circle'),
                    'order' => (int) $row['ordine'],
                    'vehicle_types' => self::csv((string) $row['tipuri_vehicul']),
                    'subcategories' => self::csv((string) $row['subcategorii']),
                    'system' => (int) $row['sistem'] === 1,
                    'active' => (int) $row['activ'] === 1,
                ];
            }
            $subcategories = [];
            foreach ($db->query('SELECT * FROM mentenanta_auto_subcategorii ORDER BY ordine, nume')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $subcategories[(string) $row['cheie']] = [
                    'key' => (string) $row['cheie'],
                    'label' => (string) $row['nume'],
                    'icon' => (string) ($row['icon'] ?: 'bi-circle'),
                    'order' => (int) $row['ordine'],
                    'categories' => [],
                    'system' => (int) $row['sistem'] === 1,
                    'active' => (int) $row['activ'] === 1,
                ];
            }
            $categories = [];
            foreach ($db->query('SELECT * FROM mentenanta_auto_categorii ORDER BY ordine, id')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (int) $row['id'];
                $categories[$id] = [
                    'id' => $id,
                    'name' => (string) $row['nume'],
                    'icon' => (string) ($row['icon'] ?: 'bi-circle'),
                    'subcategory' => (string) $row['subcategorie'],
                    'item_type' => isset(self::ITEM_TYPES[(string) $row['tip_articol_implicit']]) ? (string) $row['tip_articol_implicit'] : 'componenta',
                    'order' => (int) $row['ordine'],
                    'system' => (int) $row['sistem'] === 1,
                    'active' => (int) $row['activ'] === 1,
                ];
                if ((int) $row['activ'] === 1 && isset($subcategories[(string) $row['subcategorie']])) {
                    $subcategories[(string) $row['subcategorie']]['categories'][] = $id;
                }
            }
            foreach ($primaries as &$primary) {
                $primary['subcategories'] = array_values(array_filter(
                    $primary['subcategories'],
                    static fn (string $sub): bool => isset($subcategories[$sub])
                ));
            }
            unset($primary);

            return self::$taxonomyCache = ['primaries' => $primaries, 'subcategories' => $subcategories, 'categories' => $categories];
        } catch (Throwable $exception) {
            error_log('[AutoComponentCatalogService][taxonomy] ' . $exception->getMessage());
            return self::seedTaxonomy();
        }
    }

    /** @return array<string, string> ramurile active */
    public static function primaryLabels(): array
    {
        $result = [];
        foreach (self::taxonomy()['primaries'] as $key => $primary) {
            if ($primary['active']) {
                $result[$key] = (string) $primary['label'];
            }
        }

        return $result;
    }

    /** @return array<string, string> subcategoriile (cu $all si cele dezactivate, pentru istoric) */
    public static function subcategoryLabels(bool $all = false): array
    {
        $result = [];
        foreach (self::taxonomy()['subcategories'] as $key => $subcategory) {
            if ($all || $subcategory['active']) {
                $result[$key] = (string) $subcategory['label'];
            }
        }

        return $result;
    }

    /** @return array<string, int[]> subcategorie → categoriile ei active */
    public static function categoryGroups(): array
    {
        $result = [];
        foreach (self::taxonomy()['subcategories'] as $key => $subcategory) {
            if ($subcategory['active']) {
                $result[$key] = array_values(array_map('intval', $subcategory['categories']));
            }
        }

        return $result;
    }

    /** @return array<int, string> numele categoriilor (cu $all si cele dezactivate, pentru istoric) */
    public static function categoryNames(bool $all = false): array
    {
        $result = [];
        foreach (self::taxonomy()['categories'] as $id => $category) {
            if ($all || $category['active']) {
                $result[$id] = (string) $category['name'];
            }
        }

        return $result;
    }

    /**
     * Ramurile (cu subcategoriile lor active) pe tip de vehicul, in ordinea afisarii.
     *
     * @return array<string, array<int, array{primary:string, label:string, icon:string, subs:string[]}>>
     */
    public static function branchesByVehicleType(): array
    {
        $taxonomy = self::taxonomy();
        $result = [];
        foreach (array_keys(self::VEHICLE_TYPES) as $type) {
            $result[$type] = [];
            foreach ($taxonomy['primaries'] as $key => $primary) {
                if (!$primary['active'] || !in_array($type, $primary['vehicle_types'], true)) {
                    continue;
                }
                $subs = array_values(array_filter(
                    $primary['subcategories'],
                    static fn (string $sub): bool => !empty($taxonomy['subcategories'][$sub]['active'])
                ));
                $result[$type][] = ['primary' => $key, 'label' => (string) $primary['label'], 'icon' => (string) $primary['icon'], 'subs' => $subs];
            }
        }

        return $result;
    }

    /** @return string[] ramurile active pentru un tip de vehicul (ansamblu = cap tractor + semiremorca) */
    public static function primariesForVehicleType(string $vehicleType): array
    {
        $branches = self::branchesByVehicleType();
        $types = $vehicleType === 'ansamblu' ? ['cap_tractor', 'semiremorca'] : [$vehicleType];
        $result = [];
        foreach ($types as $type) {
            foreach ($branches[$type] ?? [] as $branch) {
                $result[$branch['primary']] = true;
            }
        }

        return array_keys($result);
    }

    /** Pachetul trimis in paginile JS (selectoare de componente). */
    public static function structurePayload(): array
    {
        return [
            'primary_labels' => self::primaryLabels(),
            'subcategory_labels' => self::subcategoryLabels(true),
            'category_groups' => self::categoryGroups(),
            'branches' => self::branchesByVehicleType(),
            'item_types' => self::ITEM_TYPES,
        ];
    }

    // --- administrare arbore ------------------------------------------------

    /** @param string[] $vehicleTypes */
    public function addPrimary(string $name, string $icon, array $vehicleTypes): string
    {
        $name = $this->cleanLabel($name, 'categoriei principale');
        $types = $this->cleanVehicleTypes($vehicleTypes);
        $this->assertUniqueLabel('primaries', $name, null);
        $key = $this->uniqueKey('mentenanta_auto_ramuri', $name);
        $order = (int) $this->db()->query('SELECT COALESCE(MAX(ordine), 0) + 1 FROM mentenanta_auto_ramuri')->fetchColumn();
        $this->db()->prepare(
            'INSERT INTO mentenanta_auto_ramuri (cheie, nume, icon, ordine, tipuri_vehicul, subcategorii, sistem, activ, created_at, updated_at)
             VALUES (:k, :n, :i, :o, :t, \'\', 0, 1, NOW(), NOW())'
        )->execute([':k' => $key, ':n' => $name, ':i' => $this->cleanIcon($icon), ':o' => $order, ':t' => implode(',', $types)]);
        self::resetCache();

        return $key;
    }

    /** @param string[] $primaries ramurile sub care apare subcategoria */
    public function addSubcategory(string $name, string $icon, array $primaries): string
    {
        $name = $this->cleanLabel($name, 'subcategoriei');
        $taxonomy = self::taxonomy($this->db);
        $primaries = array_values(array_filter(array_unique($primaries), static fn ($p): bool => isset($taxonomy['primaries'][(string) $p])));
        if ($primaries === []) {
            throw new InvalidArgumentException('Alege cel puțin o categorie principală pentru subcategorie.');
        }
        $this->assertUniqueLabel('subcategories', $name, null);
        $key = $this->uniqueKey('mentenanta_auto_subcategorii', $name);
        $order = (int) $this->db()->query('SELECT COALESCE(MAX(ordine), 0) + 1 FROM mentenanta_auto_subcategorii')->fetchColumn();
        $this->db()->prepare(
            'INSERT INTO mentenanta_auto_subcategorii (cheie, nume, icon, ordine, sistem, activ, created_at, updated_at)
             VALUES (:k, :n, :i, :o, 0, 1, NOW(), NOW())'
        )->execute([':k' => $key, ':n' => $name, ':i' => $this->cleanIcon($icon), ':o' => $order]);
        foreach ($primaries as $primary) {
            $subs = $taxonomy['primaries'][$primary]['subcategories'];
            $subs[] = $key;
            $this->db()->prepare('UPDATE mentenanta_auto_ramuri SET subcategorii = :s, updated_at = NOW() WHERE cheie = :k')
                ->execute([':s' => implode(',', array_unique($subs)), ':k' => $primary]);
        }
        self::resetCache();

        return $key;
    }

    public function addCategory(string $name, string $subcategory, string $icon, string $itemType): int
    {
        $name = $this->cleanLabel($name, 'categoriei');
        $taxonomy = self::taxonomy($this->db);
        if (!isset($taxonomy['subcategories'][$subcategory])) {
            throw new InvalidArgumentException('Subcategorie necunoscută.');
        }
        if (!isset(self::ITEM_TYPES[$itemType])) {
            $itemType = 'componenta';
        }
        $this->assertUniqueLabel('categories', $name, null);
        $id = (int) $this->db()->query('SELECT COALESCE(MAX(id), 0) + 1 FROM mentenanta_auto_categorii')->fetchColumn();
        if ($id > 250) {
            throw new InvalidArgumentException('S-a atins numărul maxim de categorii.');
        }
        $this->db()->prepare(
            'INSERT INTO mentenanta_auto_categorii (id, subcategorie, nume, icon, ordine, tip_articol_implicit, sistem, activ, created_at, updated_at)
             VALUES (:id, :s, :n, :i, :o, :t, 0, 1, NOW(), NOW())'
        )->execute([':id' => $id, ':s' => $subcategory, ':n' => $name, ':i' => $this->cleanIcon($icon), ':o' => $id, ':t' => $itemType]);
        self::resetCache();

        return $id;
    }

    /**
     * Modificare nod: nume, iconita, iar dupa nivel tipurile de vehicul (ramura), ramurile
     * (subcategorie) sau tipul articolului implicit (categorie).
     *
     * @param array<string, mixed> $data
     */
    public function updateNode(string $level, string $key, array $data): void
    {
        $taxonomy = self::taxonomy($this->db);
        $map = ['primary' => ['primaries', 'mentenanta_auto_ramuri', 'cheie'], 'subcategory' => ['subcategories', 'mentenanta_auto_subcategorii', 'cheie'], 'category' => ['categories', 'mentenanta_auto_categorii', 'id']];
        if (!isset($map[$level])) {
            throw new InvalidArgumentException('Nivel necunoscut.');
        }
        [$bucket, $table, $column] = $map[$level];
        $nodeKey = $level === 'category' ? (int) $key : $key;
        $node = $taxonomy[$bucket][$nodeKey] ?? null;
        if ($node === null) {
            throw new InvalidArgumentException('Elementul nu mai există.');
        }
        $name = $this->cleanLabel((string) ($data['name'] ?? ''), 'elementului');
        $this->assertUniqueLabel($bucket, $name, (string) $nodeKey);
        $sets = ['nume = :n', 'icon = :i', 'updated_at = NOW()'];
        $params = [':n' => $name, ':i' => $this->cleanIcon((string) ($data['icon'] ?? $node['icon'])), ':k' => $nodeKey];
        if ($level === 'primary') {
            $sets[] = 'tipuri_vehicul = :t';
            $params[':t'] = implode(',', $this->cleanVehicleTypes((array) ($data['vehicle_types'] ?? [])));
        }
        if ($level === 'category' && isset($data['item_type']) && isset(self::ITEM_TYPES[(string) $data['item_type']])) {
            $sets[] = 'tip_articol_implicit = :t';
            $params[':t'] = (string) $data['item_type'];
        }
        $this->db()->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $column . ' = :k')->execute($params);

        if ($level === 'subcategory' && isset($data['primaries'])) {
            $wanted = array_values(array_filter((array) $data['primaries'], static fn ($p): bool => isset($taxonomy['primaries'][(string) $p])));
            if ($wanted === []) {
                throw new InvalidArgumentException('Subcategoria trebuie să rămână sub cel puțin o categorie principală.');
            }
            foreach ($taxonomy['primaries'] as $primaryKey => $primary) {
                $subs = $primary['subcategories'];
                $has = in_array($key, $subs, true);
                if (in_array($primaryKey, $wanted, true) && !$has) {
                    $subs[] = $key;
                } elseif (!in_array($primaryKey, $wanted, true) && $has) {
                    if ($node['system'] && $primary['system']) {
                        continue; // legaturile initiale (ex. Livrare Gaz sub Rezervor) raman
                    }
                    $subs = array_values(array_diff($subs, [$key]));
                } else {
                    continue;
                }
                $this->db()->prepare('UPDATE mentenanta_auto_ramuri SET subcategorii = :s, updated_at = NOW() WHERE cheie = :k')
                    ->execute([':s' => implode(',', $subs), ':k' => $primaryKey]);
            }
        }
        self::resetCache();
    }

    /** Dezactivare / reactivare. Elementele initiale (sistem) nu se dezactiveaza: de ele depind configurarile existente. */
    public function setNodeActive(string $level, string $key, bool $active): void
    {
        $taxonomy = self::taxonomy($this->db);
        $map = ['primary' => ['primaries', 'mentenanta_auto_ramuri', 'cheie'], 'subcategory' => ['subcategories', 'mentenanta_auto_subcategorii', 'cheie'], 'category' => ['categories', 'mentenanta_auto_categorii', 'id']];
        if (!isset($map[$level])) {
            throw new InvalidArgumentException('Nivel necunoscut.');
        }
        [$bucket, $table, $column] = $map[$level];
        $nodeKey = $level === 'category' ? (int) $key : $key;
        $node = $taxonomy[$bucket][$nodeKey] ?? null;
        if ($node === null) {
            throw new InvalidArgumentException('Elementul nu mai există.');
        }
        if (!$active && $node['system']) {
            throw new InvalidArgumentException('„' . ($node['label'] ?? $node['name']) . '” face parte din structura inițială și nu poate fi dezactivată.');
        }
        if (!$active && $level === 'category') {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM mentenanta_auto_componente WHERE category_id = :c AND activ = 1');
            $stmt->execute([':c' => $nodeKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new InvalidArgumentException('Categoria are componente active. Elimină-le întâi (istoricul lor rămâne).');
            }
        }
        $this->db()->prepare('UPDATE ' . $table . ' SET activ = :a, updated_at = NOW() WHERE ' . $column . ' = :k')
            ->execute([':a' => $active ? 1 : 0, ':k' => $nodeKey]);
        self::resetCache();
    }

    /** Tabelele arborelui: create si umplute fara DDL in tranzactie. false = indisponibile acum. */
    private static function ensureTaxonomy(PDO $db): bool
    {
        if (self::$taxonomyReady) {
            return true;
        }
        $stmt = $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('mentenanta_auto_ramuri', 'mentenanta_auto_subcategorii', 'mentenanta_auto_categorii')"
        );
        $existing = (int) $stmt->fetchColumn();
        if ($existing < 3) {
            if ($db->inTransaction()) {
                return false;
            }
            $db->exec(
                "CREATE TABLE IF NOT EXISTS mentenanta_auto_ramuri (
                    cheie VARCHAR(40) NOT NULL PRIMARY KEY,
                    nume VARCHAR(120) NOT NULL,
                    icon VARCHAR(40) NULL,
                    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    tipuri_vehicul VARCHAR(120) NOT NULL DEFAULT 'camion,cap_tractor,semiremorca',
                    subcategorii VARCHAR(1000) NOT NULL DEFAULT '',
                    sistem TINYINT(1) NOT NULL DEFAULT 0,
                    activ TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS mentenanta_auto_subcategorii (
                    cheie VARCHAR(40) NOT NULL PRIMARY KEY,
                    nume VARCHAR(120) NOT NULL,
                    icon VARCHAR(40) NULL,
                    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    sistem TINYINT(1) NOT NULL DEFAULT 0,
                    activ TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $db->exec(
                "CREATE TABLE IF NOT EXISTS mentenanta_auto_categorii (
                    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                    subcategorie VARCHAR(40) NOT NULL,
                    nume VARCHAR(120) NOT NULL,
                    icon VARCHAR(40) NULL,
                    ordine SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    tip_articol_implicit VARCHAR(20) NOT NULL DEFAULT 'componenta',
                    sistem TINYINT(1) NOT NULL DEFAULT 0,
                    activ TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    KEY idx_mac_sub (subcategorie, activ)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        // Umplerea initiala: INSERT IGNORE, deci elementele dezactivate / redenumite nu se refac.
        $count = (int) $db->query('SELECT COUNT(*) FROM mentenanta_auto_categorii')->fetchColumn();
        if ($count === 0) {
            self::seedTaxonomyTables($db);
        }
        self::$taxonomyReady = true;

        return true;
    }

    private static function seedTaxonomyTables(PDO $db): void
    {
        $now = date('Y-m-d H:i:s');
        $primary = $db->prepare(
            'INSERT IGNORE INTO mentenanta_auto_ramuri (cheie, nume, icon, ordine, tipuri_vehicul, subcategorii, sistem, activ, created_at, updated_at)
             VALUES (:k, :n, :i, :o, :t, :s, :sys, 1, :c, :u)'
        );
        foreach (self::SEED_PRIMARIES as $key => [$name, $icon, $order, $types, $subs, $system]) {
            $primary->execute([':k' => $key, ':n' => $name, ':i' => $icon, ':o' => $order, ':t' => $types, ':s' => $subs, ':sys' => $system, ':c' => $now, ':u' => $now]);
        }
        $sub = $db->prepare(
            'INSERT IGNORE INTO mentenanta_auto_subcategorii (cheie, nume, icon, ordine, sistem, activ, created_at, updated_at)
             VALUES (:k, :n, :i, :o, :sys, 1, :c, :u)'
        );
        foreach (self::SEED_SUBCATEGORIES as $key => [$name, $icon, $order, $system]) {
            $sub->execute([':k' => $key, ':n' => $name, ':i' => $icon, ':o' => $order, ':sys' => $system, ':c' => $now, ':u' => $now]);
        }
        $category = $db->prepare(
            'INSERT IGNORE INTO mentenanta_auto_categorii (id, subcategorie, nume, icon, ordine, tip_articol_implicit, sistem, activ, created_at, updated_at)
             VALUES (:id, :s, :n, :i, :o, :t, :sys, 1, :c, :u)'
        );
        foreach (self::CATEGORY_NAMES as $id => $name) {
            $subcategory = 'sasiu';
            foreach (self::CATEGORY_GROUPS as $group => $ids) {
                if (in_array($id, $ids, true)) {
                    $subcategory = $group;
                }
            }
            $category->execute([':id' => $id, ':s' => $subcategory, ':n' => $name, ':i' => self::CATEGORY_ICONS[$id] ?? 'bi-circle', ':o' => $id, ':t' => 'componenta', ':sys' => 1, ':c' => $now, ':u' => $now]);
        }
        foreach (self::SEED_EXTRA_CATEGORIES as $id => [$subcategory, $name, $icon, $itemType]) {
            $category->execute([':id' => $id, ':s' => $subcategory, ':n' => $name, ':i' => $icon, ':o' => $id, ':t' => $itemType, ':sys' => 0, ':c' => $now, ':u' => $now]);
        }
    }

    /** Arborele initial din cod (fallback cand tabelele nu se pot crea acum). */
    private static function seedTaxonomy(): array
    {
        $subcategories = [];
        foreach (self::SUBCATEGORY_LABELS as $key => $label) {
            $subcategories[$key] = ['key' => $key, 'label' => $label, 'icon' => self::SEED_SUBCATEGORIES[$key][1], 'order' => self::SEED_SUBCATEGORIES[$key][2],
                'categories' => self::CATEGORY_GROUPS[$key], 'system' => true, 'active' => true];
        }
        $categories = [];
        foreach (self::CATEGORY_NAMES as $id => $name) {
            $sub = 'sasiu';
            foreach (self::CATEGORY_GROUPS as $group => $ids) {
                if (in_array($id, $ids, true)) {
                    $sub = $group;
                }
            }
            $categories[$id] = ['id' => $id, 'name' => $name, 'icon' => self::CATEGORY_ICONS[$id] ?? 'bi-circle', 'subcategory' => $sub,
                'item_type' => 'componenta', 'order' => $id, 'system' => true, 'active' => true];
        }
        $primaries = [];
        foreach (['sasiu', 'rezervor'] as $key) {
            [$name, $icon, $order, $types, $subs] = self::SEED_PRIMARIES[$key];
            $primaries[$key] = ['key' => $key, 'label' => $name, 'icon' => $icon, 'order' => $order, 'vehicle_types' => self::csv($types),
                'subcategories' => self::csv($subs), 'system' => true, 'active' => true];
        }

        return ['primaries' => $primaries, 'subcategories' => $subcategories, 'categories' => $categories];
    }

    /** @return string[] */
    private static function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    private function cleanLabel(string $name, string $what): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (mb_strlen($name) < 2) {
            throw new InvalidArgumentException('Scrie numele ' . $what . '.');
        }
        if (mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Numele ' . $what . ' e prea lung (max. 100 caractere).');
        }

        return $name;
    }

    private function cleanIcon(string $icon): string
    {
        return in_array($icon, self::ICON_CHOICES, true) || preg_match('/^bi-[a-z0-9-]{2,36}$/', $icon) ? $icon : 'bi-circle';
    }

    /** @return string[] */
    private function cleanVehicleTypes(array $types): array
    {
        $types = array_values(array_intersect(array_keys(self::VEHICLE_TYPES), array_map('strval', $types)));
        if ($types === []) {
            throw new InvalidArgumentException('Alege cel puțin un tip de vehicul.');
        }

        return $types;
    }

    private function assertUniqueLabel(string $bucket, string $name, ?string $exceptKey): void
    {
        foreach (self::taxonomy($this->db)[$bucket] as $key => $node) {
            if ((string) $key !== $exceptKey && self::lookupKey((string) ($node['label'] ?? $node['name'])) === self::lookupKey($name)) {
                throw new InvalidArgumentException('Există deja „' . ($node['label'] ?? $node['name']) . '”.');
            }
        }
    }

    private function uniqueKey(string $table, string $name): string
    {
        $base = self::slug($name);
        $base = $base !== '' ? mb_substr($base, 0, 34) : 'categorie';
        $key = $base;
        $suffix = 2;
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE cheie = :k');
        while (true) {
            $stmt->execute([':k' => $key]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $key;
            }
            $key = $base . '_' . $suffix++;
        }
    }

    /** Fara diacritice, litere mici, cuvinte legate cu „_”. */
    public static function slug(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value), 'UTF-8'), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', '&' => ' ']);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';

        return trim($value, '_');
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
                    tip_articol VARCHAR(20) NOT NULL DEFAULT 'componenta',
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
        // Tipul articolului (Consumabile & Fluide): coloana adaugata ulterior, doar in afara tranzactiilor.
        if (!$this->columnExists('mentenanta_auto_componente', 'tip_articol')) {
            if ($this->db()->inTransaction()) {
                return;
            }
            $this->db()->exec("ALTER TABLE mentenanta_auto_componente ADD COLUMN tip_articol VARCHAR(20) NOT NULL DEFAULT 'componenta' AFTER monitorizare");
        }
        self::$tableReady = true;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute([':t' => $table, ':c' => $column]);

        return (int) $stmt->fetchColumn() > 0;
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
            'item_type' => isset(self::ITEM_TYPES[(string) ($row['tip_articol'] ?? '')]) ? (string) $row['tip_articol'] : 'componenta',
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
        $category = self::taxonomy()['categories'][$categoryId] ?? null;

        return $category !== null ? (string) $category['subcategory'] : null;
    }

    /**
     * Ramura implicita: prima ramura activa (in ordinea afisarii) care contine subcategoria.
     * Livrare Gaz exista doar sub Rezervor; Sasiu / Hidraulic implicit pe Sasiu.
     *
     * @return array{0:string,1:string}|null [primary, subcategory]
     */
    public static function defaultPlacement(int $categoryId): ?array
    {
        $subcategory = self::subcategoryFor($categoryId);
        if ($subcategory === null) {
            return null;
        }
        foreach (self::taxonomy()['primaries'] as $key => $primary) {
            if ($primary['active'] && in_array($subcategory, $primary['subcategories'], true)) {
                return [(string) $key, $subcategory];
            }
        }

        return null;
    }

    /** Ramura + subcategorie compatibile (ex. Livrare Gaz nu exista sub Sasiu). */
    public static function isValidPlacement(string $primary, string $subcategory): bool
    {
        $node = self::taxonomy()['primaries'][$primary] ?? null;

        return $node !== null && in_array($subcategory, $node['subcategories'], true);
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
        // Fara diacritice si fara cuvinte de legatura („Uleiuri și lubrifianți” → ULE-LUB); categoriile 1-17 dau aceleasi coduri.
        $plain = strtr($categoryName, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'Ă' => 'A', 'Â' => 'A', 'Î' => 'I', 'Ș' => 'S', 'Ş' => 'S', 'Ț' => 'T', 'Ţ' => 'T', '&' => ' ']);
        $parts = preg_split('/\s+/', mb_strtoupper($plain, 'UTF-8')) ?: [];
        $codeParts = [];
        foreach ($parts as $part) {
            $part = preg_replace('/[^A-Z0-9]/', '', $part) ?? '';
            if (in_array($part, ['SI', 'DE', 'CU', 'PENTRU', 'LA'], true)) {
                continue;
            }
            if ($part !== '') {
                $codeParts[] = mb_substr($part, 0, 3, 'UTF-8');
            }
        }
        $prefix = implode('-', array_slice($codeParts, 0, 2));
        return ($prefix !== '' ? $prefix : 'CMP') . '-' . str_pad((string) $nr, 3, '0', STR_PAD_LEFT);
    }
}
