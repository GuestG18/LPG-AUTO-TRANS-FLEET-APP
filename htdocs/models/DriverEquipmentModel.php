<?php
declare(strict_types=1);

/**
 * Model pentru pagina "Echipamente șoferi".
 *
 * Ține evidența articolelor predate șoferilor (echipament fizic + comunicații)
 * și a stocului nealocat. Regula de bază a modulului:
 *   - un articol este ori alocat unui șofer (alocare activă), ori în stoc;
 *   - înlocuirea consumă stoc dacă există, altfel semnalează aprovizionare;
 *   - articolele returnabile și încă utilizabile se întorc în stoc la returnare,
 *     cele deteriorate / pierdute NU redevin stoc disponibil.
 *
 * Logica per articol vine din catalog (tip_logic) și nu este aceeași pentru tot:
 *   stare | stare_periodic | periodic | asset | service_asset | consumabil
 */
class DriverEquipmentModel extends BaseModel
{
    /** Statusuri considerate alocare activă (articolul este la șofer). */
    public const ACTIVE_STATUSES = ['in_uz', 'activ', 'de_inlocuit', 'necesita_inlocuire'];

    /** Statusuri care marchează un articol ca având nevoie de înlocuire. */
    public const REPLACEMENT_STATUSES = ['de_inlocuit', 'necesita_inlocuire'];

    /**
     * Categoriile de pornire. NU sunt o listă închisă: utilizatorul poate scrie
     * o categorie nouă în formularul de catalog, iar `getCategories()` o adaugă
     * de atunci înainte în filtre și în sugestii.
     */
    public const DEFAULT_CATEGORIES = [
        'PPE',
        'Îmbrăcăminte',
        'Scule',
        'Electronică',
        'Comunicații',
        'Carduri',
    ];

    /** Gestiunea folosită când articolul nu are alta configurată. */
    public const DEFAULT_LOCATION = 'Depozit principal';

    public const LOGIC_TYPES = [
        'stare' => 'stare',
        'stare_periodic' => 'stare + periodic',
        'periodic' => 'periodic',
        'asset' => 'asset',
        'service_asset' => 'service asset',
        'consumabil' => 'consumabil',
    ];

    public const CONDITIONS = [
        'noua' => 'Nouă',
        'buna' => 'Bună',
        'uzata' => 'Uzată',
        'deteriorata' => 'Deteriorată',
        'pierduta' => 'Pierdută',
    ];

    public const STATUSES = [
        'in_uz' => 'În uz',
        'activ' => 'Activ',
        'de_inlocuit' => 'De înlocuit',
        'necesita_inlocuire' => 'Necesită înlocuire',
        'returnat' => 'Returnat',
        'pierdut' => 'Pierdut',
        'inlocuit' => 'Înlocuit',
    ];

    /** Opțiunile filtrului "Status" din interfață (mix status + stare fizică). */
    public const STATUS_FILTERS = [
        'in_uz' => 'În uz',
        'activ' => 'Activ',
        'bun' => 'Bun',
        'deteriorat' => 'Deteriorat',
        'de_inlocuit' => 'De înlocuit',
        'returnat' => 'Returnat',
        'pierdut' => 'Pierdut',
    ];

    public const ASSET_TYPE_FILTERS = [
        'alocat' => 'Alocat șoferului',
        'stoc' => 'În stoc',
        'returnat' => 'Returnat',
        'necesita_inlocuire' => 'Necesită înlocuire',
    ];

    /**
     * Tipurile de deținător. Inventarul (catalog + stoc) este COMUN; se schimbă
     * doar cine ține articolul: un șofer din `soferi` sau o persoană TESA din
     * `staff_members` (tip de personal cu categoria „office”).
     */
    public const OWNER_DRIVER = 'sofer';
    public const OWNER_STAFF = 'tesa';

    public const OWNER_TYPES = [
        self::OWNER_DRIVER => 'Șofer',
        self::OWNER_STAFF => 'TESA',
    ];

    /** Destinația implicită a articolului — sugestie la predare, nu restricție. */
    public const DESTINATIONS = [
        'sofer' => 'Șofer',
        'tesa' => 'TESA',
        'ambele' => 'Ambele',
    ];

    /**
     * Grupele de articole decid în ce tabel apare articolul la deținător:
     *   șofer -> „Echipamente fizice” (tot ce nu e comunicații) + „Comunicații”
     *   TESA  -> „IT & Birou” + „Comunicații” + „Acces & alte active”
     */
    public const GROUPS = [
        'fizic' => 'Echipament fizic',
        'it_birou' => 'IT & Birou',
        'comunicatii' => 'Comunicații',
        'acces' => 'Acces & alte active',
    ];

    /** Motivele de ieșire din stoc. Stocul nu se șterge, se mișcă. */
    public const REMOVAL_REASONS = [
        'atribuit' => 'Atribuit unui șofer',
        'deteriorat' => 'Deteriorat',
        'pierdut' => 'Pierdut',
        'casat' => 'Casat',
        'consum' => 'Consum',
        'transfer' => 'Transfer',
        'corectie' => 'Corecție inventar',
    ];

    /** Starea unei unități identificabile individual (telefon, SIM, tabletă). */
    public const UNIT_STATUSES = [
        'disponibil' => 'Disponibil',
        'rezervat' => 'Rezervat',
        'alocat' => 'Alocat',
        'deteriorat' => 'Deteriorat',
        'pierdut' => 'Pierdut',
        'casat' => 'Casat',
        'transferat' => 'Transferat',
    ];

    public const STOCK_STATUS_FILTERS = [
        'in_stoc' => 'În stoc',
        'scazut' => 'Stoc scăzut',
        'epuizat' => 'Epuizat',
        'rezervat' => 'Rezervat',
    ];

    public const STOCK_TYPE_FILTERS = [
        'returnabil' => 'Returnabil',
        'consumabil' => 'Consumabil',
        'asset' => 'Asset',
        'service_asset' => 'Service asset',
    ];

    public const SUBSCRIPTION_TYPES = [
        'n/a' => '—',
        'abonament' => 'Abonament',
        'prepay' => 'Prepay',
    ];

    // ------------------------------------------------------------------
    // Citire
    // ------------------------------------------------------------------
    /**
     * Indicatorii din capul paginii, pentru un tip de deținător.
     *
     * Numărătoarea de persoane și de obiecte este specifică paginii (șoferi sau
     * TESA), dar stocul rămâne comun: aceleași bucăți pot pleca la oricare.
     */
    public function getKpis(string $ownerType = self::OWNER_DRIVER): array
    {
        $ownerType = $this->normalizeOwnerType($ownerType);
        $activeIn = $this->inList(self::ACTIVE_STATUSES);
        $replaceIn = $this->inList(self::REPLACEMENT_STATUSES);
        $isDriver = $ownerType === self::OWNER_DRIVER;

        $people = $isDriver
            ? $this->db->query("
                SELECT COUNT(*) FROM soferi
                WHERE status = 'activ' AND COALESCE(employment_status, 'active') <> 'terminated'
            ")->fetchColumn()
            : $this->db->query("
                SELECT COUNT(*)
                FROM staff_members sm
                INNER JOIN staff_types st ON st.id = sm.staff_type_id
                WHERE st.category = 'office'
                  AND sm.status = 'activ' AND COALESCE(sm.employment_status, 'active') <> 'terminated'
            ")->fetchColumn();

        $equipped = $this->prepared("
            SELECT COUNT(DISTINCT COALESCE(a.driver_id, a.staff_id))
            FROM echipamente_alocari a
            WHERE a.status IN ($activeIn) AND a.detinator_tip = :tip
        ", ['tip' => $ownerType])->fetchColumn();

        $objects = $this->prepared("
            SELECT COALESCE(SUM(a.cantitate), 0)
            FROM echipamente_alocari a
            WHERE a.status IN ($activeIn) AND a.detinator_tip = :tip
        ", ['tip' => $ownerType])->fetchColumn();

        $sims = $this->prepared("
            SELECT COALESCE(SUM(a.cantitate), 0)
            FROM echipamente_alocari a
            INNER JOIN echipamente_catalog c ON c.id = a.catalog_id
            WHERE a.status IN ($activeIn) AND a.detinator_tip = :tip AND c.tip_logic = 'service_asset'
        ", ['tip' => $ownerType])->fetchColumn();

        $value = $this->prepared("
            SELECT COALESCE(SUM(a.cantitate * a.cost_unitar), 0)
            FROM echipamente_alocari a
            WHERE a.status IN ($activeIn) AND a.detinator_tip = :tip
        ", ['tip' => $ownerType])->fetchColumn();

        $toReplace = $this->prepared("
            SELECT COUNT(*) FROM echipamente_alocari a
            WHERE a.status IN ($replaceIn) AND a.detinator_tip = :tip
        ", ['tip' => $ownerType])->fetchColumn();

        // Nereturnate: articole returnabile rămase la persoane care nu mai sunt active.
        $notReturned = $isDriver
            ? $this->db->query("
                SELECT COUNT(*)
                FROM echipamente_alocari a
                INNER JOIN echipamente_catalog c ON c.id = a.catalog_id
                INNER JOIN soferi s ON s.id = a.driver_id
                WHERE a.status IN ($activeIn) AND a.detinator_tip = 'sofer' AND c.returnabil = 1
                  AND (s.status = 'inactiv' OR COALESCE(s.employment_status, 'active') = 'terminated')
            ")->fetchColumn()
            : $this->db->query("
                SELECT COUNT(*)
                FROM echipamente_alocari a
                INNER JOIN echipamente_catalog c ON c.id = a.catalog_id
                INNER JOIN staff_members sm ON sm.id = a.staff_id
                WHERE a.status IN ($activeIn) AND a.detinator_tip = 'tesa' AND c.returnabil = 1
                  AND (sm.status = 'inactiv' OR COALESCE(sm.employment_status, 'active') = 'terminated')
            ")->fetchColumn();

        // De returnat: articole returnabile încă active care trebuie aduse înapoi —
        // fie sunt marcate pentru înlocuire (bucata veche revine), fie deținătorul
        // nu mai este activ. O alocare se numără o singură dată.
        $replaceList = $this->inList(self::REPLACEMENT_STATUSES);
        $toReturn = $this->prepared("
            SELECT COUNT(*)
            FROM echipamente_alocari a
            INNER JOIN echipamente_catalog c ON c.id = a.catalog_id
            LEFT JOIN soferi s ON s.id = a.driver_id
            LEFT JOIN staff_members sm ON sm.id = a.staff_id
            WHERE a.status IN ($activeIn) AND a.detinator_tip = :tip AND c.returnabil = 1
              AND (
                  a.status IN ($replaceList)
                  OR COALESCE(s.status, sm.status) = 'inactiv'
                  OR COALESCE(s.employment_status, sm.employment_status, 'active') = 'terminated'
              )
        ", ['tip' => $ownerType])->fetchColumn();

        // Stocul se ține pe articol + gestiune + mărime și este COMUN celor
        // două pagini, deci nu se filtrează după tipul de deținător.
        $stock = $this->db->query("
            SELECT COALESCE(SUM(st.disponibil), 0), COUNT(DISTINCT st.catalog_id)
            FROM echipamente_stoc st
            INNER JOIN echipamente_catalog c ON c.id = st.catalog_id
            WHERE c.activ = 1
        ")->fetch(PDO::FETCH_NUM);

        return [
            'soferi_echipati' => (int) $equipped,
            'soferi_activi' => (int) $people,
            'obiecte_active' => (int) $objects,
            'sim_active' => (int) $sims,
            'valoare_active' => (float) $value,
            'de_inlocuit' => (int) $toReplace,
            'nereturnate' => (int) $notReturned,
            'de_returnat' => (int) $toReturn,
            'in_stoc' => (int) ($stock[0] ?? 0),
            'articole_in_stoc' => (int) ($stock[1] ?? 0),
        ];
    }

    /**
     * Rândurile tabelului principal: un rând per deținător, cu totalurile lui și
     * cu articolele desfășurate pe grupe.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOwnerRows(string $ownerType = self::OWNER_DRIVER, array $filters = []): array
    {
        $ownerType = $this->normalizeOwnerType($ownerType);
        $filters['detinator_tip'] = $ownerType;

        $items = $this->getAllocations($filters);
        $owners = $this->getOwnerBase($ownerType, $filters, $items);

        $grouped = [];
        foreach ($items as $item) {
            $grouped[(int) $item['detinator_id']][] = $item;
        }

        // Numărătoarea este pe bucăți, nu pe linii: 2 perechi de pantaloni
        // predate pe o singură linie înseamnă 2 obiecte la deținător.
        $countActive = static function (array $items): int {
            $total = 0;
            foreach ($items as $item) {
                if (!empty($item['activa'])) {
                    $total += (int) $item['cantitate'];
                }
            }

            return $total;
        };

        $rows = [];
        foreach ($owners as $owner) {
            $ownerId = (int) $owner['id'];
            $ownerItems = $grouped[$ownerId] ?? [];

            $byGroup = ['fizic' => [], 'it_birou' => [], 'comunicatii' => [], 'acces' => []];
            $obiecte = 0;
            $valoare = 0.0;
            $deReturnat = 0;
            $deInlocuit = 0;
            $deteriorate = 0;
            $ultimaPredare = null;

            foreach ($ownerItems as $item) {
                if (!empty($item['activa'])) {
                    $obiecte += (int) $item['cantitate'];
                    $valoare += (float) $item['valoare_totala'];
                    if (!empty($item['de_returnat'])) {
                        $deReturnat++;
                    }
                    if (in_array((string) $item['status'], self::REPLACEMENT_STATUSES, true)) {
                        $deInlocuit++;
                    }
                    if ((string) $item['stare'] === 'deteriorata') {
                        $deteriorate++;
                    }
                    if ($ultimaPredare === null || (string) $item['data_predarii'] > $ultimaPredare) {
                        $ultimaPredare = (string) $item['data_predarii'];
                    }
                }

                $group = (string) $item['grupa'];
                $byGroup[array_key_exists($group, $byGroup) ? $group : 'fizic'][] = $item;
            }

            // Șoferul vede un singur tabel de echipamente fizice (tot ce nu e
            // comunicații); TESA vede separat IT & Birou și Acces & alte active.
            $fizice = $ownerType === self::OWNER_DRIVER
                ? array_merge($byGroup['fizic'], $byGroup['it_birou'], $byGroup['acces'])
                : $byGroup['it_birou'];
            $altele = $ownerType === self::OWNER_DRIVER
                ? []
                : array_merge($byGroup['acces'], $byGroup['fizic']);

            $rows[] = [
                'id' => $ownerId,
                'detinator_tip' => $ownerType,
                // Secțiunile panoului extins sunt construite din date, nu fixate
                // în șablon: o grupă nouă în catalog devine automat o secțiune.
                'sectiuni' => $this->buildSections($ownerType, $byGroup, $countActive),
                'nume' => (string) $owner['nume'],
                'telefon' => (string) ($owner['telefon'] ?? ''),
                'functie' => (string) ($owner['functie'] ?? ''),
                'departament' => (string) ($owner['departament'] ?? ''),
                'initiale' => $this->initials((string) $owner['nume']),
                'sofer_activ' => (string) ($owner['status'] ?? 'activ') === 'activ'
                    && (string) ($owner['employment_status'] ?? 'active') !== 'terminated',
                'vehicul' => trim((string) ($owner['vehicul'] ?? '')),
                'obiecte_active' => $obiecte,
                'nr_fizice' => $countActive($fizice),
                'nr_comunicatii' => $countActive($byGroup['comunicatii']),
                'nr_altele' => $countActive($altele),
                'valoare_totala' => $valoare,
                'de_returnat' => $deReturnat,
                'de_inlocuit' => $deInlocuit,
                'deteriorate' => $deteriorate,
                'ultima_predare' => $ultimaPredare,
                'status' => $this->driverStatus($deInlocuit, $deteriorate, $obiecte),
                'echipamente_fizice' => $fizice,
                'comunicatii' => $byGroup['comunicatii'],
                'alte_active' => $altele,
            ];
        }

        return $rows;
    }

    /**
     * Secțiunile de echipament ale unui deținător, în ordinea de citire a paginii.
     *
     * Fiecare secțiune își aduce propriul titlu, propriul set de coloane
     * (`layout`) și propriile articole. Randarea este comună celor două pagini
     * și pur verticală: o grupă nouă adaugă o secțiune sub cele existente, fără
     * nicio modificare de șablon.
     *
     * @param array<string, array<int, array<string, mixed>>> $byGroup
     * @return array<int, array<string, mixed>>
     */
    private function buildSections(string $ownerType, array $byGroup, callable $countActive): array
    {
        // Ordinea diferă doar prin ce interesează pe fiecare pagină: șoferul
        // începe cu echipamentul de lucru, biroul cu tehnica IT.
        $order = $ownerType === self::OWNER_STAFF
            ? ['it_birou', 'comunicatii', 'acces', 'fizic']
            : ['fizic', 'comunicatii', 'it_birou', 'acces'];

        // Grupele necunoscute (adăugate ulterior) intră după cele cunoscute.
        foreach (array_keys($byGroup) as $group) {
            if (!in_array($group, $order, true)) {
                $order[] = (string) $group;
            }
        }

        $meta = [
            'fizic' => ['titlu' => 'Echipamente fizice', 'icon' => 'bi-box-seam', 'layout' => 'fizic'],
            'it_birou' => ['titlu' => 'IT & Birou', 'icon' => 'bi-laptop', 'layout' => 'it_birou'],
            'comunicatii' => ['titlu' => 'Comunicații', 'icon' => 'bi-broadcast', 'layout' => 'comunicatii'],
            'acces' => ['titlu' => 'Acces & alte active', 'icon' => 'bi-key', 'layout' => 'acces'],
        ];

        $sections = [];
        foreach ($order as $group) {
            $items = $byGroup[$group] ?? [];
            if ($items === []) {
                continue;
            }

            $sections[] = [
                'cheie' => (string) $group,
                'titlu' => $meta[$group]['titlu'] ?? (self::GROUPS[$group] ?? 'Alte echipamente'),
                'icon' => $meta[$group]['icon'] ?? 'bi-bag',
                'layout' => $meta[$group]['layout'] ?? 'fizic',
                'items' => $items,
                'total' => $countActive($items),
            ];
        }

        return $sections;
    }

    /** Compatibilitate: pagina de șoferi folosește aceeași construcție de rânduri. */
    public function getDriverRows(array $filters = []): array
    {
        return $this->getOwnerRows(self::OWNER_DRIVER, $filters);
    }

    /**
     * Rezultatele căutării după cuvânt cheie, ORIENTATE PE OBIECT.
     *
     * Navigarea normală răspunde la „ce are persoana X”, deci e grupată pe
     * deținător. Căutarea răspunde la „ce obiecte se potrivesc”, deci întoarce
     * fiecare articol pe rândul lui, cu deținătorul ca simplă coloană.
     *
     * Reutilizează exact aceleași filtre și aceeași decorare ca lista normală;
     * se schimbă doar forma rezultatului. Câmpul `detinator` este normalizat
     * (tip + nume + inițiale), deci partiala de randare e comună șoferilor și
     * personalului TESA.
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     total: int,
     *     detinatori: int,
     *     page: int,
     *     per_page: int,
     *     total_pages: int
     * }
     */
    public function getSearchResults(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $items = $this->getAllocations($filters);

        // Obiectele identice se citesc mai ușor grupate: același articol, apoi
        // deținătorii lui în ordine alfabetică.
        usort($items, static function (array $a, array $b): int {
            return [$a['denumire'], $a['detinator_nume'], $a['marime'] ?? '']
                <=> [$b['denumire'], $b['detinator_nume'], $b['marime'] ?? ''];
        });

        $owners = [];
        foreach ($items as $index => $item) {
            $ownerKey = (string) $item['detinator_tip'] . ':' . (int) $item['detinator_id'];
            $owners[$ownerKey] = true;
            $items[$index]['detinator'] = [
                'id' => (int) $item['detinator_id'],
                'nume' => (string) $item['detinator_nume'],
                'tip' => (string) $item['detinator_tip'],
                'tip_label' => self::OWNER_TYPES[(string) $item['detinator_tip']] ?? 'Deținător',
                'initiale' => $this->initials((string) $item['detinator_nume']),
            ];
        }

        $total = count($items);
        $perPage = max(5, min(200, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));

        return [
            'rows' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'detinatori' => count($owners),
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Alocările, îmbogățite cu logica din catalog (termen, stare, stoc) și cu
     * deținătorul normalizat, indiferent dacă e șofer sau persoană TESA.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllocations(array $filters = []): array
    {
        [$where, $params] = $this->allocationFilters($filters);

        $sql = "
            SELECT
                a.*,
                c.denumire, c.categorie, c.grupa, c.tip_logic, c.returnabil, c.destinatie,
                c.urmareste_stare, c.inlocuire_periodica, c.durata_standard_luni,
                c.urmareste_expirare, c.necesita_marime, c.necesita_identificator,
                c.locatie_implicita, c.imagine,
                COALESCE(s.nume, sm.nume_complet) AS detinator_nume,
                COALESCE(a.driver_id, a.staff_id) AS detinator_id,
                sm.functie AS detinator_functie,
                stp.name AS detinator_departament,
                COALESCE(s.nume, sm.nume_complet) AS sofer_nume,
                COALESCE(st.disponibil, 0) AS stoc_disponibil,
                COALESCE(st.utilizabil_inlocuire, 0) AS stoc_utilizabil,
                COALESCE(stt.total, 0) AS stoc_total_articol
            FROM echipamente_alocari a
            INNER JOIN echipamente_catalog c ON c.id = a.catalog_id
            LEFT JOIN soferi s ON s.id = a.driver_id
            LEFT JOIN staff_members sm ON sm.id = a.staff_id
            LEFT JOIN staff_types stp ON stp.id = sm.staff_type_id
            -- Stocul relevant este cel de pe MĂRIMEA alocării; potrivirea pe
            -- articol ar înmulți rândurile (o linie de stoc per mărime).
            LEFT JOIN echipamente_stoc st
                   ON st.catalog_id = a.catalog_id
                  AND st.locatie = c.locatie_implicita
                  AND st.marime = COALESCE(a.marime, '')
            LEFT JOIN (
                SELECT catalog_id, SUM(disponibil) AS total
                FROM echipamente_stoc
                GROUP BY catalog_id
            ) stt ON stt.catalog_id = a.catalog_id
            " . ($where !== '' ? 'WHERE ' . $where : '') . "
            ORDER BY c.grupa ASC, c.denumire ASC, a.id ASC
        ";

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        $today = new DateTimeImmutable('today');
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = $this->decorateAllocation($row, $today);
        }

        return $rows;
    }

    /**
     * Persoanele afișate în tabel: șoferii activi sau personalul TESA activ.
     * Cei inactivi apar doar dacă mai au echipament nereturnat, altfel s-ar
     * pierde din evidență exact articolele care trebuie recuperate.
     */
    private function getOwnerBase(string $ownerType, array $filters, array $items): array
    {
        $ownerId = (int) ($filters['driver_id'] ?? 0);
        $hasItemFilters = $this->hasItemFilters($filters);
        $isDriver = $ownerType === self::OWNER_DRIVER;

        $params = [];
        if ($isDriver) {
            $conditions = ["(s.status = 'activ' AND COALESCE(s.employment_status, 'active') <> 'terminated')"];
            if ($ownerId > 0) {
                $conditions = ['s.id = :owner_id'];
                $params['owner_id'] = $ownerId;
            }

            $sql = "
                SELECT s.id, s.nume, s.telefon, s.status, s.employment_status,
                       '' AS functie, '' AS departament, v.nr_inmatriculare AS vehicul
                FROM soferi s
                LEFT JOIN vehicule v ON v.id = s.vehicle_id
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY s.nume ASC
            ";
        } else {
            $conditions = [
                "stp.category = 'office'",
                "(sm.status = 'activ' AND COALESCE(sm.employment_status, 'active') <> 'terminated')",
            ];
            if ($ownerId > 0) {
                $conditions = ['sm.id = :owner_id'];
                $params['owner_id'] = $ownerId;
            }

            $departament = trim((string) ($filters['departament'] ?? ''));
            if ($departament !== '') {
                $conditions[] = 'stp.name = :departament';
                $params['departament'] = $departament;
            }

            $functie = trim((string) ($filters['functie'] ?? ''));
            if ($functie !== '') {
                $conditions[] = 'sm.functie = :functie';
                $params['functie'] = $functie;
            }

            $sql = "
                SELECT sm.id, sm.nume_complet AS nume, sm.telefon, sm.status, sm.employment_status,
                       sm.functie, stp.name AS departament, '' AS vehicul
                FROM staff_members sm
                INNER JOIN staff_types stp ON stp.id = sm.staff_type_id
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY sm.nume_complet ASC
            ";
        }

        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $owners = $statement->fetchAll();

        $withItems = [];
        foreach ($items as $item) {
            $withItems[(int) $item['detinator_id']] = true;
        }
        $known = [];
        foreach ($owners as $owner) {
            $known[(int) $owner['id']] = true;
        }
        $missing = array_values(array_diff(array_keys($withItems), array_keys($known)));

        if ($missing !== [] && $ownerId <= 0) {
            $placeholders = implode(',', array_fill(0, count($missing), '?'));
            $statement = $this->db->prepare($isDriver
                ? "
                    SELECT s.id, s.nume, s.telefon, s.status, s.employment_status,
                           '' AS functie, '' AS departament, v.nr_inmatriculare AS vehicul
                    FROM soferi s
                    LEFT JOIN vehicule v ON v.id = s.vehicle_id
                    WHERE s.id IN ($placeholders)
                "
                : "
                    SELECT sm.id, sm.nume_complet AS nume, sm.telefon, sm.status, sm.employment_status,
                           sm.functie, stp.name AS departament, '' AS vehicul
                    FROM staff_members sm
                    INNER JOIN staff_types stp ON stp.id = sm.staff_type_id
                    WHERE sm.id IN ($placeholders)
                ");
            $statement->execute($missing);
            $owners = array_merge($owners, $statement->fetchAll());
            usort($owners, static fn(array $a, array $b): int => strcmp((string) $a['nume'], (string) $b['nume']));
        }

        if ($hasItemFilters) {
            $owners = array_values(array_filter(
                $owners,
                static fn(array $o): bool => isset($withItems[(int) $o['id']])
            ));
        }

        return $owners;
    }

    /** Interogare pregătită scurtă, pentru indicatorii parametrizați. */
    private function prepared(string $sql, array $params): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    private function normalizeOwnerType(mixed $ownerType): string
    {
        return (string) $ownerType === self::OWNER_STAFF ? self::OWNER_STAFF : self::OWNER_DRIVER;
    }

    /**
     * Adaugă pe alocare informațiile derivate din logica articolului: eticheta
     * de stare/termen, zilele rămase și disponibilitatea din stoc.
     */
    private function decorateAllocation(array $row, DateTimeImmutable $today): array
    {
        $row['cantitate'] = (int) $row['cantitate'];
        $row['cost_unitar'] = (float) $row['cost_unitar'];
        $row['cost_lunar'] = $row['cost_lunar'] !== null ? (float) $row['cost_lunar'] : null;
        $row['valoare_totala'] = $row['cantitate'] * $row['cost_unitar'];
        $row['stoc_disponibil'] = (int) $row['stoc_disponibil'];
        $row['stoc_total_articol'] = (int) ($row['stoc_total_articol'] ?? 0);
        $row['poate_inlocui_din_stoc'] = $row['stoc_disponibil'] > 0 && (int) $row['stoc_utilizabil'] === 1;

        $isPeriodic = in_array((string) $row['tip_logic'], ['periodic', 'stare_periodic'], true)
            || (int) $row['inlocuire_periodica'] === 1;

        $deadline = (string) ($row['data_inlocuirii_planificata'] ?? '') !== ''
            ? (string) $row['data_inlocuirii_planificata']
            : (string) ($row['data_expirarii'] ?? '');

        $daysLeft = null;
        if ($deadline !== '') {
            $target = DateTimeImmutable::createFromFormat('Y-m-d', $deadline);
            if ($target instanceof DateTimeImmutable) {
                $daysLeft = (int) $today->diff($target->setTime(0, 0))->format('%r%a');
            }
        }

        $row['periodic'] = $isPeriodic;
        $row['termen'] = $deadline !== '' ? $deadline : null;
        $row['zile_ramase'] = $daysLeft;
        $row['activa'] = in_array((string) $row['status'], self::ACTIVE_STATUSES, true);
        $row['de_returnat'] = (int) $row['returnabil'] === 1
            && in_array((string) $row['status'], self::REPLACEMENT_STATUSES, true);
        $row['stare_termen'] = $this->conditionLabel($row, $isPeriodic, $daysLeft);

        return $row;
    }

    /**
     * Coloana "Stare / Termen". Articolele pe logică de stare NU primesc termen
     * calendaristic, iar cele periodice arată starea plus scadența.
     *
     * @return array{label: string, tone: string, note: string}
     */
    private function conditionLabel(array $row, bool $isPeriodic, ?int $daysLeft): array
    {
        $status = (string) $row['status'];
        $stare = (string) $row['stare'];

        if ($status === 'returnat') {
            return ['label' => 'Returnat', 'tone' => 'muted', 'note' => ''];
        }
        if ($status === 'pierdut' || $stare === 'pierduta') {
            return ['label' => 'Pierdut', 'tone' => 'danger', 'note' => ''];
        }
        if ($status === 'inlocuit') {
            return ['label' => 'Înlocuit', 'tone' => 'muted', 'note' => ''];
        }
        if ($stare === 'deteriorata' || $status === 'necesita_inlocuire') {
            $size = trim((string) ($row['marime'] ?? ''));

            // Nota spune dacă înlocuirea se poate face pe mărimea cerută; un
            // total mare pe alte mărimi nu rezolvă nimic pentru acest șofer.
            if (!empty($row['poate_inlocui_din_stoc'])) {
                $note = 'stoc: ' . (int) $row['stoc_disponibil'] . ' buc.'
                    . ($size !== '' ? ' (mărimea ' . $size . ')' : '');
            } elseif ($size !== '' && (int) $row['stoc_total_articol'] > 0) {
                $note = 'fără stoc pe mărimea ' . $size;
            } else {
                $note = 'fără stoc';
            }

            return [
                'label' => $stare === 'deteriorata' ? 'Deteriorată' : 'Necesită înlocuire',
                'tone' => 'danger',
                'note' => $note,
            ];
        }

        if ($isPeriodic && $daysLeft !== null) {
            if ($daysLeft < 0) {
                return ['label' => 'Termen depășit', 'tone' => 'danger', 'note' => format_date_ro((string) $row['termen'])];
            }
            if ($daysLeft <= 30) {
                return ['label' => 'Aproape de înlocuire', 'tone' => 'warning', 'note' => format_date_ro((string) $row['termen'])];
            }

            return [
                'label' => self::CONDITIONS[$stare] ?? 'Bună',
                'tone' => 'success',
                'note' => 'termen ' . format_date_ro((string) $row['termen']),
            ];
        }

        if ($status === 'de_inlocuit') {
            return ['label' => 'De înlocuit', 'tone' => 'warning', 'note' => ''];
        }

        // Articol fără urmărire de stare (asset / serviciu): contează statusul.
        if ((int) $row['urmareste_stare'] === 0) {
            return ['label' => self::STATUSES[$status] ?? 'Activ', 'tone' => 'success', 'note' => ''];
        }

        return [
            'label' => self::CONDITIONS[$stare] ?? 'Bună',
            'tone' => in_array($stare, ['noua', 'buna'], true) ? 'success' : 'warning',
            'note' => '',
        ];
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function driverStatus(int $deInlocuit, int $deteriorate, int $obiecte): array
    {
        if ($obiecte === 0) {
            return ['label' => 'Fără echipament', 'tone' => 'muted'];
        }
        if ($deteriorate > 0) {
            return ['label' => 'Necesită înlocuire', 'tone' => 'danger'];
        }
        if ($deInlocuit > 0) {
            return ['label' => 'De înlocuit', 'tone' => 'warning'];
        }

        return ['label' => 'În uz', 'tone' => 'success'];
    }
    // ------------------------------------------------------------------
    // Stoc
    // ------------------------------------------------------------------

    /**
     * Rândurile paginii de stoc: o linie per articol și gestiune, cu mărimile
     * desfășurate dedesubt și, pentru articolele serializate, unitățile reale.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStockRows(array $filters = []): array
    {
        $conditions = ['c.activ = 1'];
        $params = [];

        $categorie = trim((string) ($filters['categorie'] ?? ''));
        if ($categorie === 'Echipamente fizice') {
            $conditions[] = "c.grupa = 'fizic'";
        } elseif ($categorie === 'Comunicații') {
            $conditions[] = "(c.grupa = 'comunicatii' OR c.categorie = 'Comunicații')";
        } elseif ($categorie !== '') {
            $conditions[] = 'c.categorie = :categorie';
            $params['categorie'] = $categorie;
        }

        $returnabil = (string) ($filters['returnabil'] ?? '');
        if ($returnabil === 'da' || $returnabil === 'nu') {
            $conditions[] = 'c.returnabil = :returnabil';
            $params['returnabil'] = $returnabil === 'da' ? 1 : 0;
        }

        $locatie = trim((string) ($filters['locatie'] ?? ''));
        if ($locatie !== '') {
            $conditions[] = 'st.locatie = :locatie';
            $params['locatie'] = $locatie;
        }

        // Tipul se citește din logica articolului, nu dintr-o coloană separată.
        switch ((string) ($filters['tip'] ?? '')) {
            case 'returnabil':
                $conditions[] = 'c.returnabil = 1';
                break;
            case 'consumabil':
                $conditions[] = "(c.tip_logic = 'consumabil' OR c.returnabil = 0)";
                break;
            case 'asset':
                $conditions[] = "c.tip_logic = 'asset'";
                break;
            case 'service_asset':
                $conditions[] = "c.tip_logic = 'service_asset'";
                break;
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $conditions[] = $this->likeClause(
                [
                    'c.denumire',
                    'c.categorie',
                    'st.locatie',
                    'st.marime',
                ],
                $search,
                $params,
                'sq'
            ) . ' OR EXISTS (SELECT 1 FROM echipamente_stoc_unitati u WHERE u.catalog_id = c.id AND '
              . $this->likeClause(['u.serie', 'u.iccid', 'u.numar_telefon', 'u.operator'], $search, $params, 'uq') . ')';

            // Parantezele exterioare țin cele două alternative împreună.
            $conditions[count($conditions) - 1] = '(' . $conditions[count($conditions) - 1] . ')';
        }

        $statement = $this->db->prepare("
            SELECT st.*, c.denumire, c.categorie, c.grupa, c.tip_logic, c.returnabil,
                   c.serializat, c.necesita_marime, c.cost_lunar, c.durata_standard_luni,
                   c.prag_minim_stoc, c.imagine
            FROM echipamente_stoc st
            INNER JOIN echipamente_catalog c ON c.id = st.catalog_id
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY c.grupa ASC, c.denumire ASC, st.locatie ASC, st.marime ASC
        ");
        $statement->execute($params);

        // Un articol poate avea mai multe linii (o mărime = o linie). Pagina
        // arată un rând per articol + gestiune, cu mărimile în detaliu.
        $groups = [];
        foreach ($statement->fetchAll() as $row) {
            $key = (int) $row['catalog_id'] . '|' . (string) $row['locatie'];

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'catalog_id' => (int) $row['catalog_id'],
                    'denumire' => (string) $row['denumire'],
                    'categorie' => (string) $row['categorie'],
                    'grupa' => (string) $row['grupa'],
                    'tip_logic' => (string) $row['tip_logic'],
                    'returnabil' => (int) $row['returnabil'],
                    'serializat' => (int) $row['serializat'] === 1,
                    'necesita_marime' => (int) $row['necesita_marime'] === 1,
                    'locatie' => (string) $row['locatie'],
                    'cost_unitar' => (float) $row['cost_unitar'],
                    'cost_lunar' => $row['cost_lunar'] !== null ? (float) $row['cost_lunar'] : null,
                    'disponibil' => 0,
                    'rezervat' => 0,
                    // Pragul rândului agregat este ținta articolului din catalog;
                    // pragurile pe mărimi sunt cele fine, din liniile de stoc.
                    'prag_minim' => (int) $row['prag_minim_stoc'],
                    'utilizabil_inlocuire' => 0,
                    'stoc_ids' => [],
                    'marimi' => [],
                    'unitati' => [],
                ];
            }

            $groups[$key]['disponibil'] += (int) $row['disponibil'];
            $groups[$key]['rezervat'] += (int) $row['rezervat'];
            if ((int) $row['necesita_marime'] !== 1) {
                $groups[$key]['prag_minim'] = max((int) $groups[$key]['prag_minim'], (int) $row['prag_minim']);
            }
            $groups[$key]['utilizabil_inlocuire'] = max((int) $groups[$key]['utilizabil_inlocuire'], (int) $row['utilizabil_inlocuire']);
            $groups[$key]['cost_unitar'] = max((float) $groups[$key]['cost_unitar'], (float) $row['cost_unitar']);
            $groups[$key]['stoc_ids'][] = (int) $row['id'];

            if ((string) $row['marime'] !== '') {
                $groups[$key]['marimi'][] = [
                    'stoc_id' => (int) $row['id'],
                    'marime' => (string) $row['marime'],
                    'disponibil' => (int) $row['disponibil'],
                    'rezervat' => (int) $row['rezervat'],
                    'prag_minim' => (int) $row['prag_minim'],
                ];
            }
        }

        $units = $this->getStockUnits(array_values(array_unique(array_map(
            static fn(array $g): int => $g['catalog_id'],
            $groups
        ))));

        $statusFilter = (string) ($filters['status'] ?? '');
        $rows = [];
        foreach ($groups as $key => $group) {
            $group['total'] = $group['disponibil'] + $group['rezervat'];
            $group['liber'] = max(0, $group['disponibil'] - $group['rezervat']);
            $group['valoare'] = (string) $group['tip_logic'] === 'service_asset'
                ? null
                : $group['disponibil'] * $group['cost_unitar'];
            $group['status'] = $this->stockStatus($group);
            $group['unitati'] = array_values(array_filter(
                $units[$group['catalog_id']] ?? [],
                static fn(array $u): bool => (string) $u['locatie'] === (string) $group['locatie']
            ));

            usort(
                $group['marimi'],
                static fn(array $a, array $b): int => strnatcmp((string) $a['marime'], (string) $b['marime'])
            );

            if ($statusFilter !== '') {
                $matches = $statusFilter === 'rezervat'
                    ? (int) $group['rezervat'] > 0
                    : (string) $group['status']['key'] === $statusFilter;
                if (!$matches) {
                    continue;
                }
            }

            $rows[] = $group;
        }

        return $rows;
    }

    /**
     * Unitățile individuale, grupate pe articol. Doar articolele serializate au
     * astfel de rânduri; restul se urmăresc pe cantitate.
     *
     * @param array<int, int> $catalogIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getStockUnits(array $catalogIds = [], bool $onlyAvailable = false): array
    {
        if ($catalogIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($catalogIds), '?'));
        $sql = "
            SELECT u.*, COALESCE(s.nume, sm.nume_complet) AS sofer_nume
            FROM echipamente_stoc_unitati u
            LEFT JOIN echipamente_alocari a ON a.id = u.alocare_id
            LEFT JOIN soferi s ON s.id = a.driver_id
            LEFT JOIN staff_members sm ON sm.id = a.staff_id
            WHERE u.catalog_id IN ($placeholders)
        ";
        if ($onlyAvailable) {
            $sql .= " AND u.status = 'disponibil'";
        }
        $sql .= ' ORDER BY u.status ASC, u.serie ASC, u.id ASC';

        $statement = $this->db->prepare($sql);
        $statement->execute(array_values($catalogIds));

        $grouped = [];
        foreach ($statement->fetchAll() as $unit) {
            $unit['cost_achizitie'] = (float) $unit['cost_achizitie'];
            $unit['cost_lunar'] = $unit['cost_lunar'] !== null ? (float) $unit['cost_lunar'] : null;
            $unit['status_label'] = self::UNIT_STATUSES[(string) $unit['status']] ?? (string) $unit['status'];
            $unit['stare_label'] = self::CONDITIONS[(string) $unit['stare']] ?? (string) $unit['stare'];
            $grouped[(int) $unit['catalog_id']][] = $unit;
        }

        return $grouped;
    }

    /**
     * @return array{label: string, tone: string, key: string}
     */
    private function stockStatus(array $row): array
    {
        if ((int) $row['disponibil'] <= 0) {
            return ['label' => 'Epuizat', 'tone' => 'danger', 'key' => 'epuizat'];
        }
        if ((int) $row['disponibil'] <= (int) $row['prag_minim']) {
            return ['label' => 'Stoc scăzut', 'tone' => 'warning', 'key' => 'scazut'];
        }

        return ['label' => 'În stoc', 'tone' => 'success', 'key' => 'in_stoc'];
    }

    /**
     * Indicatorii paginii de stoc, calculați din rândurile deja filtrate.
     */
    public function getStockSummary(array $stockRows): array
    {
        $articole = 0;
        $disponibileInlocuire = 0;
        $scazut = 0;
        $epuizat = 0;
        $rezervat = 0;
        $valoare = 0.0;
        $tipuri = [];

        foreach ($stockRows as $row) {
            $articole += (int) $row['disponibil'];
            $valoare += (int) $row['disponibil'] * (float) $row['cost_unitar'];
            $tipuri[(int) $row['catalog_id']] = true;
            $rezervat += (int) $row['rezervat'];

            if ((int) $row['utilizabil_inlocuire'] === 1) {
                $disponibileInlocuire += (int) $row['liber'];
            }
            if ($row['status']['key'] === 'scazut') {
                $scazut++;
            }
            if ($row['status']['key'] === 'epuizat') {
                $epuizat++;
            }
        }

        return [
            'articole' => $articole,
            'tipuri' => count($tipuri),
            'disponibile_inlocuire' => $disponibileInlocuire,
            'stoc_scazut' => $scazut,
            'epuizate' => $epuizat,
            'rezervate' => $rezervat,
            'valoare' => $valoare,
        ];
    }

    /**
     * Categoriile disponibile: cele implicite plus tot ce a introdus
     * utilizatorul în catalog. Lista crește singură, fără intervenție în cod.
     *
     * @return array<int, string>
     */
    public function getCategories(): array
    {
        $used = $this->db->query("
            SELECT DISTINCT categorie FROM echipamente_catalog
            WHERE TRIM(COALESCE(categorie, '')) <> ''
        ")->fetchAll(PDO::FETCH_COLUMN);

        $categories = array_unique(array_merge(self::DEFAULT_CATEGORIES, array_map('strval', $used)));
        usort($categories, static fn(string $a, string $b): int => strcoll($a, $b));

        return array_values($categories);
    }

    /**
     * Personalul TESA activ: staff_members al căror tip are categoria „office”.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStaffOptions(): array
    {
        return $this->db->query("
            SELECT sm.id, sm.nume_complet AS nume, sm.functie, stp.name AS departament
            FROM staff_members sm
            INNER JOIN staff_types stp ON stp.id = sm.staff_type_id
            WHERE stp.category = 'office'
              AND sm.status = 'activ' AND COALESCE(sm.employment_status, 'active') <> 'terminated'
            ORDER BY sm.nume_complet ASC
        ")->fetchAll();
    }

    /**
     * Departamentele TESA. Aplicația nu are un câmp separat de departament, așa
     * că folosește tipul de personal (Contabil, Administrator, HR...), care este
     * exact gruparea existentă în modulul de personal.
     *
     * @return array<int, string>
     */
    public function getStaffDepartments(): array
    {
        return array_map('strval', $this->db->query("
            SELECT DISTINCT stp.name
            FROM staff_types stp
            WHERE stp.category = 'office' AND stp.status = 'activ'
            ORDER BY stp.name ASC
        ")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int, string> */
    public function getStaffFunctions(): array
    {
        return array_map('strval', $this->db->query("
            SELECT DISTINCT sm.functie
            FROM staff_members sm
            INNER JOIN staff_types stp ON stp.id = sm.staff_type_id
            WHERE stp.category = 'office' AND TRIM(COALESCE(sm.functie, '')) <> ''
            ORDER BY sm.functie ASC
        ")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Verifică faptul că deținătorul există și este de tipul așteptat. */
    private function requireOwner(string $ownerType, int $ownerId): void
    {
        $statement = $ownerType === self::OWNER_STAFF
            ? $this->db->prepare('SELECT COUNT(*) FROM staff_members WHERE id = :id')
            : $this->db->prepare('SELECT COUNT(*) FROM soferi WHERE id = :id');
        $statement->execute(['id' => $ownerId]);

        if ((int) $statement->fetchColumn() === 0) {
            throw new InvalidArgumentException($ownerType === self::OWNER_STAFF
                ? 'Persoana TESA selectată nu există.'
                : 'Șoferul selectat nu există.');
        }
    }

    /** Gestiunile existente, pentru filtrul de locație. */
    public function getLocations(): array
    {
        $locations = $this->db->query("
            SELECT DISTINCT locatie FROM echipamente_stoc WHERE TRIM(locatie) <> ''
            UNION
            SELECT DISTINCT locatie_implicita FROM echipamente_catalog WHERE TRIM(locatie_implicita) <> ''
            ORDER BY locatie ASC
        ")->fetchAll(PDO::FETCH_COLUMN);

        $locations = array_map('strval', $locations);
        $locations[] = self::DEFAULT_LOCATION;
        $locations = array_values(array_unique(array_filter($locations, static fn(string $l): bool => trim($l) !== '')));
        usort($locations, static fn(string $a, string $b): int => strcoll($a, $b));

        return $locations;
    }

    /**
     * Harta articol -> disponibilitate, folosită de dialogul de înlocuire ca să
     * spună imediat dacă se rezolvă din stoc sau e nevoie de aprovizionare.
     *
     * Disponibilul este defalcat PE MĂRIME: bocancii de 42 nu înlocuiesc
     * bocancii de 43, deci interfața nu are voie să însumeze mărimile.
     */
    public function getStockAvailabilityMap(): array
    {
        $sql = "
            SELECT c.id AS catalog_id, c.denumire, c.categorie, c.grupa, c.tip_logic,
                   c.necesita_marime, c.necesita_identificator, c.serializat, c.returnabil,
                   c.cost_implicit, c.locatie_implicita,
                   st.marime, st.locatie,
                   COALESCE(st.disponibil, 0) AS disponibil,
                   COALESCE(st.rezervat, 0) AS rezervat,
                   COALESCE(st.utilizabil_inlocuire, 0) AS utilizabil,
                   COALESCE(st.cost_unitar, c.cost_implicit) AS cost_unitar
            FROM echipamente_catalog c
            LEFT JOIN echipamente_stoc st ON st.catalog_id = c.id
            WHERE c.activ = 1
            ORDER BY c.denumire ASC
        ";

        $map = [];
        foreach ($this->db->query($sql)->fetchAll() as $row) {
            $catalogId = (int) $row['catalog_id'];

            if (!isset($map[$catalogId])) {
                $map[$catalogId] = [
                    'catalog_id' => $catalogId,
                    'denumire' => (string) $row['denumire'],
                    'categorie' => (string) $row['categorie'],
                    'grupa' => (string) $row['grupa'],
                    'tip_logic' => (string) $row['tip_logic'],
                    'necesita_marime' => (int) $row['necesita_marime'] === 1,
                    'necesita_identificator' => (int) $row['necesita_identificator'] === 1,
                    'serializat' => (int) $row['serializat'] === 1,
                    'returnabil' => (int) $row['returnabil'] === 1,
                    'disponibil' => 0,
                    'rezervat' => 0,
                    'liber' => 0,
                    'utilizabil' => false,
                    'locatie' => (string) ($row['locatie'] ?? $row['locatie_implicita']),
                    'cost_unitar' => (float) $row['cost_unitar'],
                    'pe_marime' => [],
                ];
            }

            $available = (int) $row['disponibil'];
            $reserved = (int) $row['rezervat'];
            $free = max(0, $available - $reserved);

            $map[$catalogId]['disponibil'] += $available;
            $map[$catalogId]['rezervat'] += $reserved;
            $map[$catalogId]['liber'] += $free;
            $map[$catalogId]['utilizabil'] = $map[$catalogId]['utilizabil'] || (int) $row['utilizabil'] === 1;

            $size = (string) ($row['marime'] ?? '');
            if ($size !== '') {
                $map[$catalogId]['pe_marime'][$size] = ($map[$catalogId]['pe_marime'][$size] ?? 0) + $free;
            }
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCatalog(bool $onlyActive = false, string $search = ''): array
    {
        $conditions = [];
        $params = [];

        if ($onlyActive) {
            $conditions[] = 'c.activ = 1';
        }

        $search = trim($search);
        if ($search !== '') {
            $conditions[] = $this->likeClause(
                ['c.denumire', 'c.categorie', 'c.tip_logic', 'c.locatie_implicita', 'c.observatii'],
                $search,
                $params,
                'cq'
            );
        }

        $statement = $this->db->prepare("
            SELECT c.*, COALESCE(SUM(st.disponibil), 0) AS stoc_total
            FROM echipamente_catalog c
            LEFT JOIN echipamente_stoc st ON st.catalog_id = c.id
            " . ($conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '') . "
            GROUP BY c.id
            ORDER BY c.grupa ASC, c.categorie ASC, c.denumire ASC
        ");
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Ce conține concret fiecare articol din catalog: liniile de stoc (gestiune
     * + mărime), unitățile individuale ale articolelor serializate și alocările
     * active către șoferi.
     *
     * Catalogul definește tipul, dar utilizatorul care vede „stoc 5” vrea să
     * afle care sunt cele 5 bucăți și unde se află restul.
     *
     * @param array<int, int> $catalogIds
     * @return array<int, array{
     *     stoc: array<int, array<string, mixed>>,
     *     unitati: array<int, array<string, mixed>>,
     *     alocari: array<int, array<string, mixed>>,
     *     total_stoc: int,
     *     total_alocat: int
     * }>
     */
    public function getCatalogBreakdown(array $catalogIds): array
    {
        $catalogIds = array_values(array_unique(array_map('intval', $catalogIds)));
        if ($catalogIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($catalogIds), '?'));
        $breakdown = [];
        foreach ($catalogIds as $id) {
            $breakdown[$id] = [
                'stoc' => [],
                'unitati' => [],
                'alocari' => [],
                'total_stoc' => 0,
                'total_alocat' => 0,
            ];
        }

        // 1. Liniile de stoc: o linie per gestiune și mărime.
        $statement = $this->db->prepare("
            SELECT st.*, c.prag_minim_stoc
            FROM echipamente_stoc st
            INNER JOIN echipamente_catalog c ON c.id = st.catalog_id
            WHERE st.catalog_id IN ($placeholders)
            ORDER BY st.locatie ASC, st.marime ASC
        ");
        $statement->execute($catalogIds);
        foreach ($statement->fetchAll() as $row) {
            $row['disponibil'] = (int) $row['disponibil'];
            $row['rezervat'] = (int) $row['rezervat'];
            $row['prag_minim'] = (int) $row['prag_minim'];
            $row['status'] = $this->stockStatus($row);
            $breakdown[(int) $row['catalog_id']]['stoc'][] = $row;
            $breakdown[(int) $row['catalog_id']]['total_stoc'] += $row['disponibil'];
        }

        // 2. Unitățile individuale (doar articolele serializate au așa ceva).
        foreach ($this->getStockUnits($catalogIds) as $catalogId => $units) {
            $breakdown[$catalogId]['unitati'] = $units;
        }

        // 3. Alocările active: unde se află bucățile care nu mai sunt în stoc.
        $activeIn = $this->inList(self::ACTIVE_STATUSES);
        $statement = $this->db->prepare("
            SELECT a.id, a.catalog_id, a.detinator_tip, a.cantitate, a.marime, a.identificator,
                   a.data_predarii, a.stare, a.status, a.cost_unitar,
                   COALESCE(s.nume, sm.nume_complet) AS sofer_nume
            FROM echipamente_alocari a
            LEFT JOIN soferi s ON s.id = a.driver_id
            LEFT JOIN staff_members sm ON sm.id = a.staff_id
            WHERE a.catalog_id IN ($placeholders) AND a.status IN ($activeIn)
            ORDER BY sofer_nume ASC
        ");
        $statement->execute($catalogIds);
        foreach ($statement->fetchAll() as $row) {
            $row['cantitate'] = (int) $row['cantitate'];
            $row['cost_unitar'] = (float) $row['cost_unitar'];
            $row['stare_label'] = self::CONDITIONS[(string) $row['stare']] ?? (string) $row['stare'];
            $row['status_label'] = self::STATUSES[(string) $row['status']] ?? (string) $row['status'];
            $row['necesita_inlocuire'] = in_array((string) $row['status'], self::REPLACEMENT_STATUSES, true);
            $breakdown[(int) $row['catalog_id']]['alocari'][] = $row;
            $breakdown[(int) $row['catalog_id']]['total_alocat'] += $row['cantitate'];
        }

        return $breakdown;
    }

    public function getDriverOptions(): array
    {
        return $this->db->query("
            SELECT s.id, s.nume
            FROM soferi s
            WHERE s.status = 'activ' AND COALESCE(s.employment_status, 'active') <> 'terminated'
            ORDER BY s.nume ASC
        ")->fetchAll();
    }

    public function getMonthOptions(): array
    {
        $values = $this->db->query("
            SELECT DISTINCT DATE_FORMAT(data_predarii, '%Y-%m') AS luna
            FROM echipamente_alocari
            ORDER BY luna DESC
        ")->fetchAll(PDO::FETCH_COLUMN);

        $months = [];
        foreach ($values as $value) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', $value . '-01');
            if ($date instanceof DateTimeImmutable) {
                $months[(string) $value] = $this->monthLabel($date);
            }
        }

        return $months;
    }

    private function monthLabel(DateTimeImmutable $date): string
    {
        $names = [1 => 'Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie',
            'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie'];

        return ($names[(int) $date->format('n')] ?? '') . ' ' . $date->format('Y');
    }

    /**
     * Ultimele mișcări din registrul de echipamente (predări, returnări, stoc).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentMovements(int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));

        return $this->db->query("
            SELECT m.*, c.denumire, COALESCE(s.nume, sm.nume_complet) AS sofer_nume
            FROM echipamente_miscari m
            INNER JOIN echipamente_catalog c ON c.id = m.catalog_id
            LEFT JOIN soferi s ON s.id = m.driver_id
            LEFT JOIN staff_members sm ON sm.id = m.staff_id
            ORDER BY m.created_at DESC, m.id DESC
            LIMIT $limit
        ")->fetchAll();
    }

    // ------------------------------------------------------------------
    // Operațiuni
    // ------------------------------------------------------------------

    /**
     * Predă un articol unui deținător (șofer sau persoană TESA). Dacă articolul
     * vine din stoc (implicit), stocul comun scade cu cantitatea predată.
     */
    public function assign(array $data, bool $fromStock = true): int
    {
        $catalog = $this->requireCatalogItem((int) ($data['catalog_id'] ?? 0));
        $ownerType = $this->normalizeOwnerType($data['detinator_tip'] ?? self::OWNER_DRIVER);
        $ownerId = (int) ($data['driver_id'] ?? 0);

        if ($ownerId <= 0) {
            throw new InvalidArgumentException($ownerType === self::OWNER_STAFF
                ? 'Selectează persoana TESA care primește echipamentul.'
                : 'Selectează șoferul care primește echipamentul.');
        }

        $this->requireOwner($ownerType, $ownerId);
        $driverId = $ownerType === self::OWNER_DRIVER ? $ownerId : null;
        $staffId = $ownerType === self::OWNER_STAFF ? $ownerId : null;

        $quantity = max(1, (int) ($data['cantitate'] ?? 1));
        $handoverDate = $this->normalizeDate($data['data_predarii'] ?? null) ?? date('Y-m-d');
        $costRaw = (string) ($data['cost_unitar'] ?? '');
        $cost = $costRaw !== '' ? (float) $costRaw : (float) $catalog['cost_implicit'];
        $size = $this->normalizeSize($data['marime'] ?? null, $catalog);
        $location = (string) $catalog['locatie_implicita'];
        $identifier = $this->nullable($data['identificator'] ?? null);
        $operator = $this->nullable($data['operator'] ?? null);

        // Articolele serializate pleacă dintr-o bucată anume: cea aleasă sau
        // prima disponibilă din gestiune, cu mărimea cerută.
        $unit = null;
        if ($fromStock && (int) $catalog['serializat'] === 1) {
            $unit = $this->pickAvailableUnit((int) $catalog['id'], $location, $size, (int) ($data['unitate_id'] ?? 0));
            if ($unit === null) {
                throw new RuntimeException($this->stockShortageMessage($catalog, $size));
            }
            $identifier = $identifier ?? $this->unitIdentifier($unit);
            $operator = $operator ?? $this->nullable($unit['operator'] ?? null);
        }

        if ($fromStock) {
            $this->requireStock($catalog, $location, $size, $quantity);
            $this->decrementStock((int) $catalog['id'], $location, $size, $quantity);
        }

        $statement = $this->db->prepare("
            INSERT INTO echipamente_alocari
                (detinator_tip, driver_id, staff_id, catalog_id, unitate_id, cantitate, marime, identificator,
                 operator, data_predarii, cost_unitar, cost_lunar, stare, status, data_inlocuirii_planificata,
                 data_expirarii, inlocuieste_alocare_id, observatii, created_at, updated_at)
            VALUES
                (:detinator_tip, :driver_id, :staff_id, :catalog_id, :unitate_id, :cantitate, :marime, :identificator,
                 :operator, :data_predarii, :cost_unitar, :cost_lunar, :stare, :status, :deadline,
                 :expirare, :inlocuieste, :observatii, NOW(), NOW())
        ");
        $statement->execute([
            'detinator_tip' => $ownerType,
            'driver_id' => $driverId,
            'staff_id' => $staffId,
            'catalog_id' => (int) $catalog['id'],
            'unitate_id' => $unit !== null ? (int) $unit['id'] : null,
            'cantitate' => $quantity,
            'marime' => $size !== '' ? $size : null,
            'identificator' => $identifier,
            'operator' => $operator,
            'data_predarii' => $handoverDate,
            'cost_unitar' => $cost,
            'cost_lunar' => $unit !== null && $unit['cost_lunar'] !== null
                ? (float) $unit['cost_lunar']
                : ($catalog['cost_lunar'] !== null ? (float) $catalog['cost_lunar'] : null),
            'stare' => array_key_exists((string) ($data['stare'] ?? ''), self::CONDITIONS)
                ? (string) $data['stare']
                : 'noua',
            'status' => (string) $catalog['grupa'] === 'comunicatii' ? 'activ' : 'in_uz',
            'deadline' => $this->computeDeadline($catalog, $handoverDate),
            'expirare' => $this->normalizeDate($data['data_expirarii'] ?? null),
            'inlocuieste' => (int) ($data['inlocuieste_alocare_id'] ?? 0) > 0
                ? (int) $data['inlocuieste_alocare_id']
                : null,
            'observatii' => $this->nullable($data['observatii'] ?? null),
        ]);

        $allocationId = (int) $this->db->lastInsertId();

        if ($unit !== null) {
            $this->setUnitStatus((int) $unit['id'], 'alocat', ['alocare_id' => $allocationId]);
        }

        $this->logMovement(
            $allocationId,
            (int) $catalog['id'],
            $ownerId,
            'predare',
            $quantity,
            $location,
            (string) ($data['observatii'] ?? ''),
            [
                'marime' => $size,
                'motiv' => 'atribuit',
                'unitate_id' => $unit !== null ? (int) $unit['id'] : null,
                'cost_unitar' => $cost,
                'detinator_tip' => $ownerType,
            ]
        );

        return $allocationId;
    }

    /**
     * Returnează un articol. Doar articolele returnabile și încă utilizabile se
     * întorc în stocul disponibil; cele deteriorate sau pierdute nu.
     */
    public function returnItem(int $allocationId, string $outcome = 'stoc', string $notes = ''): void
    {
        $allocation = $this->requireAllocation($allocationId);
        // O alocare se returnează o singură dată: al doilea submit (alt tab, formular retrimis)
        // ar crește stocul de două ori. Verificarea se repetă atomic în UPDATE-ul de mai jos.
        if (!in_array((string) $allocation['status'], self::ACTIVE_STATUSES, true)) {
            throw new InvalidArgumentException('Articolul "' . (string) $allocation['denumire'] . '" a fost deja returnat sau închis.');
        }

        // Statusul alocării, intrarea în stoc, unitatea și mișcarea se scriu împreună sau deloc.
        $this->db->beginTransaction();
        try {
            $condition = match ($outcome) {
                'deteriorat' => 'deteriorata',
                'pierdut' => 'pierduta',
                default => (string) $allocation['stare'],
            };

            $statement = $this->db->prepare("
                UPDATE echipamente_alocari
                SET status = :status, stare = :stare, data_returnarii = CURDATE(),
                    observatii = COALESCE(NULLIF(:observatii, ''), observatii), updated_at = NOW()
                WHERE id = :id AND status IN (" . $this->inList(self::ACTIVE_STATUSES) . ")
            ");
            $statement->execute([
                'status' => $outcome === 'pierdut' ? 'pierdut' : 'returnat',
                'stare' => $condition,
                'observatii' => $notes,
                'id' => $allocationId,
            ]);
            if ($statement->rowCount() === 0) {
                throw new InvalidArgumentException('Articolul "' . (string) $allocation['denumire'] . '" a fost deja returnat sau închis.');
            }

            $unitId = (int) ($allocation['unitate_id'] ?? 0);
            $backToStock = (int) $allocation['returnabil'] === 1 && $outcome === 'stoc';

            if ($backToStock) {
                $this->incrementStock(
                    (int) $allocation['catalog_id'],
                    (string) $allocation['locatie_implicita'],
                    (string) ($allocation['marime'] ?? ''),
                    (int) $allocation['cantitate']
                );
            }

            // Unitatea identificabilă redevine disponibilă doar dacă e utilizabilă;
            // deteriorată sau pierdută rămâne în evidență, dar în afara stocului.
            if ($unitId > 0) {
                $this->setUnitStatus($unitId, match ($outcome) {
                    'pierdut' => 'pierdut',
                    'deteriorat' => 'deteriorat',
                    default => $backToStock ? 'disponibil' : 'casat',
                }, [
                    'alocare_id' => null,
                    'stare' => $condition,
                ]);
            }

            $this->logMovement(
                $allocationId,
                (int) $allocation['catalog_id'],
                (int) $allocation['detinator_id'],
                match ($outcome) {
                    'pierdut' => 'pierdere',
                    'deteriorat' => 'deteriorare',
                    default => 'returnare',
                },
                (int) $allocation['cantitate'],
                (string) $allocation['locatie_implicita'],
                $notes,
                [
                    'marime' => (string) ($allocation['marime'] ?? ''),
                    'motiv' => $outcome === 'stoc' ? 'corectie' : $outcome,
                    'unitate_id' => $unitId > 0 ? $unitId : null,
                    'detinator_tip' => (string) $allocation['detinator_tip'],
                ]
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** Marchează starea unui articol aflat la șofer (deteriorat / de înlocuit / pierdut). */
    public function markAllocation(int $allocationId, string $mark, string $notes = ''): void
    {
        $allocation = $this->requireAllocation($allocationId);

        [$status, $condition] = match ($mark) {
            'deteriorat' => ['necesita_inlocuire', 'deteriorata'],
            'de_inlocuit' => ['de_inlocuit', (string) $allocation['stare']],
            'pierdut' => ['pierdut', 'pierduta'],
            'bun' => [(string) $allocation['grupa'] === 'comunicatii' ? 'activ' : 'in_uz', 'buna'],
            default => throw new InvalidArgumentException('Marcaj necunoscut.'),
        };

        $statement = $this->db->prepare("
            UPDATE echipamente_alocari
            SET status = :status, stare = :stare,
                observatii = COALESCE(NULLIF(:observatii, ''), observatii), updated_at = NOW()
            WHERE id = :id
        ");
        $statement->execute([
            'status' => $status,
            'stare' => $condition,
            'observatii' => $notes,
            'id' => $allocationId,
        ]);

        $this->logMovement(
            $allocationId,
            (int) $allocation['catalog_id'],
            (int) $allocation['detinator_id'],
            match ($mark) {
                'pierdut' => 'pierdere',
                'deteriorat' => 'deteriorare',
                default => 'ajustare',
            },
            (int) $allocation['cantitate'],
            (string) $allocation['locatie_implicita'],
            $notes,
            ['detinator_tip' => (string) $allocation['detinator_tip']]
        );
    }

    /**
     * Înlocuiește un articol alocat cu unul din stoc: scade stocul, închide
     * alocarea veche și creează una nouă legată de ea. Fără stoc disponibil
     * aruncă excepție — semnalul de aprovizionare.
     *
     * @return array{alocare_noua: int, stoc_ramas: int, denumire: string}
     */
    public function replaceFromStock(int $allocationId, array $data = []): array
    {
        $allocation = $this->requireAllocation($allocationId);
        $catalogId = (int) ($data['catalog_id'] ?? 0) > 0
            ? (int) $data['catalog_id']
            : (int) $allocation['catalog_id'];
        $catalog = $this->requireCatalogItem($catalogId);
        $quantity = max(1, (int) ($data['cantitate'] ?? $allocation['cantitate']));
        $location = (string) $catalog['locatie_implicita'];
        $size = $this->normalizeSize($data['marime'] ?? $allocation['marime'] ?? null, $catalog);

        // O alocare se înlocuiește o singură dată: un al doilea submit ar închide
        // din nou alocarea veche și ar preda încă o bucată din stoc.
        if (!in_array((string) $allocation['status'], self::ACTIVE_STATUSES, true)) {
            throw new InvalidArgumentException('Articolul "' . (string) $allocation['denumire'] . '" a fost deja înlocuit sau închis.');
        }

        // Verificarea se face pe mărimea cerută: stocul de 42 nu rezolvă o
        // înlocuire de 43, oricât de mare ar fi totalul articolului.
        $this->requireStock($catalog, $location, $size, $quantity);

        $this->db->beginTransaction();
        try {
            // Re-verificare sub blocare: două cereri simultane nu pot trece amândouă.
            $lock = $this->db->prepare('SELECT status FROM echipamente_alocari WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $allocationId]);
            if (!in_array((string) $lock->fetchColumn(), self::ACTIVE_STATUSES, true)) {
                throw new InvalidArgumentException('Articolul "' . (string) $allocation['denumire'] . '" a fost deja înlocuit sau închis.');
            }

            $this->closeReplacedAllocation(
                $allocationId,
                $allocation,
                (string) ($data['motiv'] ?? 'deteriorat'),
                (string) ($data['observatii'] ?? '')
            );

            $newId = $this->assign([
                'detinator_tip' => (string) $allocation['detinator_tip'],
                'driver_id' => (int) $allocation['detinator_id'],
                'catalog_id' => $catalogId,
                'cantitate' => $quantity,
                'marime' => $size,
                'identificator' => $data['identificator'] ?? null,
                'operator' => $data['operator'] ?? $allocation['operator'],
                'data_predarii' => $data['data_predarii'] ?? date('Y-m-d'),
                'cost_unitar' => $data['cost_unitar'] ?? '',
                'stare' => 'noua',
                'observatii' => 'Înlocuiește alocarea #' . $allocationId,
                'inlocuieste_alocare_id' => $allocationId,
            ], true);

            $this->logMovement(
                $newId,
                $catalogId,
                (int) $allocation['detinator_id'],
                'inlocuire',
                $quantity,
                $location,
                'Înlocuire pentru alocarea #' . $allocationId,
                ['marime' => $size, 'motiv' => 'atribuit', 'detinator_tip' => (string) $allocation['detinator_tip']]
            );

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return [
            'alocare_noua' => $newId,
            'stoc_ramas' => $this->availableStock($catalogId, $location, $size),
            'denumire' => (string) $catalog['denumire'],
            'marime' => $size,
        ];
    }

    /**
     * Închide alocarea înlocuită. Articolul returnabil și încă bun se întoarce
     * în stoc; cel deteriorat sau pierdut nu redevine disponibil.
     */
    private function closeReplacedAllocation(int $allocationId, array $allocation, string $outcome, string $notes): void
    {
        $condition = match ($outcome) {
            'pierdut' => 'pierduta',
            'uzat' => 'uzata',
            'bun' => 'buna',
            default => 'deteriorata',
        };

        $statement = $this->db->prepare("
            UPDATE echipamente_alocari
            SET status = :status, stare = :stare, data_returnarii = CURDATE(),
                observatii = COALESCE(NULLIF(:observatii, ''), observatii), updated_at = NOW()
            WHERE id = :id
        ");
        $statement->execute([
            'status' => $outcome === 'pierdut' ? 'pierdut' : 'inlocuit',
            'stare' => $condition,
            'observatii' => $notes,
            'id' => $allocationId,
        ]);

        $reusable = (int) $allocation['returnabil'] === 1 && in_array($outcome, ['bun', 'uzat'], true);
        if ($reusable) {
            $this->incrementStock(
                (int) $allocation['catalog_id'],
                (string) $allocation['locatie_implicita'],
                (string) ($allocation['marime'] ?? ''),
                (int) $allocation['cantitate']
            );
        }

        // Unitatea identificabilă urmează soarta articolului înlocuit.
        $unitId = (int) ($allocation['unitate_id'] ?? 0);
        if ($unitId > 0) {
            $this->setUnitStatus($unitId, match ($outcome) {
                'pierdut' => 'pierdut',
                'bun', 'uzat' => $reusable ? 'disponibil' : 'casat',
                default => 'deteriorat',
            }, ['alocare_id' => null, 'stare' => $condition]);
        }
    }

    /**
     * Intrare în stoc (recepție marfă). Pentru articolele serializate creează
     * și unitățile individuale, cu serie / IMEI / ICCID acolo unde există.
     *
     * @return array{cantitate: int, unitati: int}
     */
    public function stockIn(array $data): array
    {
        $catalog = $this->requireCatalogItem((int) ($data['catalog_id'] ?? 0));
        $quantity = max(1, (int) ($data['cantitate'] ?? 1));
        $size = $this->normalizeSize($data['marime'] ?? null, $catalog);
        $location = trim((string) ($data['locatie'] ?? '')) !== ''
            ? trim((string) $data['locatie'])
            : (string) $catalog['locatie_implicita'];
        $cost = trim((string) ($data['cost_unitar'] ?? '')) !== ''
            ? (float) $data['cost_unitar']
            : (float) $catalog['cost_implicit'];
        $entryDate = $this->normalizeDate($data['data_intrarii'] ?? null) ?? date('Y-m-d');
        $serialized = (int) $catalog['serializat'] === 1;

        // O serie identifică o singură bucată: intrarea serializată cu serie
        // completată se face bucată cu bucată.
        $serial = $this->nullable($data['serie'] ?? null);
        if ($serialized && $serial !== null && $quantity > 1) {
            throw new InvalidArgumentException('Seria / IMEI identifică o singură bucată. Fă intrări separate sau lasă seria goală.');
        }

        $this->db->beginTransaction();
        try {
            $stockId = $this->ensureStockRow(
                (int) $catalog['id'],
                $location,
                $size,
                $cost,
                (int) $catalog['prag_minim_stoc']
            );
            $this->incrementStock((int) $catalog['id'], $location, $size, $quantity);

            $units = 0;
            if ($serialized) {
                for ($i = 0; $i < $quantity; $i++) {
                    $this->createUnit([
                        'catalog_id' => (int) $catalog['id'],
                        'stoc_id' => $stockId,
                        'serie' => $serial,
                        'iccid' => $this->nullable($data['iccid'] ?? null),
                        'numar_telefon' => $this->nullable($data['numar_telefon'] ?? null),
                        'operator' => $this->nullable($data['operator'] ?? null),
                        'tip_abonament' => (string) ($data['tip_abonament'] ?? 'n/a'),
                        'cost_lunar' => $data['cost_lunar'] ?? $catalog['cost_lunar'],
                        'marime' => $size,
                        'locatie' => $location,
                        'data_intrarii' => $entryDate,
                        'cost_achizitie' => $cost,
                        'furnizor' => $this->nullable($data['furnizor'] ?? null),
                        'document' => $this->nullable($data['document'] ?? null),
                        'observatii' => $this->nullable($data['observatii'] ?? null),
                    ]);
                    $units++;
                }
            }

            $this->logMovement(null, (int) $catalog['id'], null, 'intrare_stoc', $quantity, $location, (string) ($data['observatii'] ?? ''), [
                'marime' => $size,
                'furnizor' => $this->nullable($data['furnizor'] ?? null),
                'document' => $this->nullable($data['document'] ?? null),
                'cost_unitar' => $cost,
            ]);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return ['cantitate' => $quantity, 'unitati' => $units];
    }

    /**
     * Ieșire din stoc cu motiv. Nu se șterge nimic: scade cantitatea, unitatea
     * primește un status final și mișcarea rămâne în istoric.
     */
    public function stockOut(array $data): int
    {
        $catalog = $this->requireCatalogItem((int) ($data['catalog_id'] ?? 0));
        $quantity = max(1, (int) ($data['cantitate'] ?? 1));
        $reason = array_key_exists((string) ($data['motiv'] ?? ''), self::REMOVAL_REASONS)
            ? (string) $data['motiv']
            : 'corectie';
        $unitId = (int) ($data['unitate_id'] ?? 0);
        $size = $this->normalizeSize($data['marime'] ?? null, $catalog);
        $location = trim((string) ($data['locatie'] ?? '')) !== ''
            ? trim((string) $data['locatie'])
            : (string) $catalog['locatie_implicita'];

        $unit = null;
        if ($unitId > 0) {
            $unit = $this->findUnit($unitId);
            $quantity = 1;
            $size = (string) $unit['marime'];
            $location = (string) $unit['locatie'];
        }

        $this->requireStock($catalog, $location, $size, $quantity, 'ajustare');

        $this->db->beginTransaction();
        try {
            $this->decrementStock((int) $catalog['id'], $location, $size, $quantity);

            if ($unit !== null) {
                $this->setUnitStatus($unitId, match ($reason) {
                    'deteriorat' => 'deteriorat',
                    'pierdut' => 'pierdut',
                    'transfer' => 'transferat',
                    'atribuit' => 'alocat',
                    default => 'casat',
                }, ['observatii' => $this->nullable($data['observatii'] ?? null)]);
            }

            $this->logMovement(null, (int) $catalog['id'], null, 'iesire_stoc', $quantity, $location, (string) ($data['observatii'] ?? ''), [
                'marime' => $size,
                'motiv' => $reason,
                'unitate_id' => $unitId > 0 ? $unitId : null,
            ]);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return $quantity;
    }

    public function saveStockSettings(int $stockId, array $data): void
    {
        $statement = $this->db->prepare("
            UPDATE echipamente_stoc
            SET prag_minim = :prag_minim, cost_unitar = :cost_unitar,
                utilizabil_inlocuire = :utilizabil, observatii = :observatii, updated_at = NOW()
            WHERE id = :id
        ");
        $statement->execute([
            'prag_minim' => max(0, (int) ($data['prag_minim'] ?? 0)),
            'cost_unitar' => (float) ($data['cost_unitar'] ?? 0),
            'utilizabil' => !empty($data['utilizabil_inlocuire']) ? 1 : 0,
            'observatii' => $this->nullable($data['observatii'] ?? null),
            'id' => $stockId,
        ]);
    }

    public function saveCatalogItem(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $logic = (string) ($data['tip_logic'] ?? 'stare');

        $fields = [
            'denumire' => trim((string) ($data['denumire'] ?? '')),
            // Categoria este text liber: o valoare nouă devine automat opțiune
            // în filtre și în sugestiile formularului.
            'categorie' => trim((string) ($data['categorie'] ?? '')) !== ''
                ? mb_substr(trim((string) $data['categorie']), 0, 60)
                : 'PPE',
            'grupa' => array_key_exists((string) ($data['grupa'] ?? ''), self::GROUPS)
                ? (string) $data['grupa']
                : 'fizic',
            'destinatie' => array_key_exists((string) ($data['destinatie'] ?? ''), self::DESTINATIONS)
                ? (string) $data['destinatie']
                : 'ambele',
            'tip_logic' => array_key_exists($logic, self::LOGIC_TYPES) ? $logic : 'stare',
            'returnabil' => !empty($data['returnabil']) ? 1 : 0,
            'urmareste_stare' => !empty($data['urmareste_stare']) ? 1 : 0,
            'inlocuire_periodica' => !empty($data['inlocuire_periodica']) ? 1 : 0,
            'durata_standard_luni' => (int) ($data['durata_standard_luni'] ?? 0) > 0
                ? (int) $data['durata_standard_luni']
                : null,
            'urmareste_expirare' => !empty($data['urmareste_expirare']) ? 1 : 0,
            'necesita_marime' => !empty($data['necesita_marime']) ? 1 : 0,
            'necesita_identificator' => !empty($data['necesita_identificator']) ? 1 : 0,
            'serializat' => !empty($data['serializat']) ? 1 : 0,
            'cost_implicit' => (float) ($data['cost_implicit'] ?? 0),
            'cost_lunar' => trim((string) ($data['cost_lunar'] ?? '')) !== '' ? (float) $data['cost_lunar'] : null,
            'prag_minim_stoc' => max(0, (int) ($data['prag_minim_stoc'] ?? 0)),
            'locatie_implicita' => trim((string) ($data['locatie_implicita'] ?? '')) !== ''
                ? mb_substr(trim((string) $data['locatie_implicita']), 0, 120)
                : self::DEFAULT_LOCATION,
            'observatii' => $this->nullable($data['observatii'] ?? null),
            'activ' => !empty($data['activ']) ? 1 : 0,
        ];

        if ($fields['denumire'] === '') {
            throw new InvalidArgumentException('Denumirea articolului este obligatorie.');
        }

        // Observațiile se rescriu doar dacă formularul le trimite: un formular fără
        // acest câmp nu trebuie să șteargă notițele existente ale articolului.
        if ($id > 0 && !array_key_exists('observatii', $data)) {
            unset($fields['observatii']);
        }

        if ($id > 0) {
            $sets = [];
            foreach (array_keys($fields) as $key) {
                $sets[] = "`$key` = :$key";
            }
            $statement = $this->db->prepare(
                'UPDATE echipamente_catalog SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id'
            );
            $statement->execute($fields + ['id' => $id]);
        } else {
            $columns = array_keys($fields);
            $statement = $this->db->prepare(
                'INSERT INTO echipamente_catalog (`' . implode('`, `', $columns) . '`, created_at, updated_at)'
                . ' VALUES (:' . implode(', :', $columns) . ', NOW(), NOW())'
            );
            $statement->execute($fields);
            $id = (int) $this->db->lastInsertId();
        }

        // Articolele fără mărimi primesc linia de stoc implicită; cele cu mărimi
        // își creează linia la prima intrare, pe mărimea respectivă.
        if ((int) $fields['necesita_marime'] === 0) {
            $this->ensureStockRow($id, (string) $fields['locatie_implicita'], '', (float) $fields['cost_implicit'], (int) $fields['prag_minim_stoc']);
        }

        return $id;
    }

    /** Articolul folosit deja într-o alocare se dezactivează, nu se șterge. */
    /**
     * Coloana `imagine` (fotografia articolului) — adăugată la nevoie, o dată pe
     * cerere, ca pagina să meargă și pe un server unde migrarea nu a rulat.
     * Vezi database/update_echipamente_catalog_imagine.sql.
     */
    public function ensurePhotoColumn(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        $exists = $this->db->query("SHOW COLUMNS FROM echipamente_catalog LIKE 'imagine'")->fetch();
        if (!$exists) {
            $this->db->exec('ALTER TABLE echipamente_catalog ADD COLUMN imagine VARCHAR(64) DEFAULT NULL AFTER observatii');
        }
    }

    public function getCatalogPhoto(int $id): ?string
    {
        $statement = $this->db->prepare('SELECT imagine FROM echipamente_catalog WHERE id = :id');
        $statement->execute(['id' => $id]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setCatalogPhoto(int $id, ?string $filename): void
    {
        $statement = $this->db->prepare('UPDATE echipamente_catalog SET imagine = :imagine, updated_at = NOW() WHERE id = :id');
        $statement->execute(['imagine' => $filename, 'id' => $id]);
    }

    public function catalogItemExists(int $id): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM echipamente_catalog WHERE id = :id');
        $statement->execute(['id' => $id]);

        return (bool) $statement->fetchColumn();
    }

    public function deleteCatalogItem(int $id): void
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM echipamente_alocari WHERE catalog_id = :id');
        $statement->execute(['id' => $id]);

        if ((int) $statement->fetchColumn() > 0) {
            $statement = $this->db->prepare('UPDATE echipamente_catalog SET activ = 0, updated_at = NOW() WHERE id = :id');
            $statement->execute(['id' => $id]);

            return;
        }

        $statement = $this->db->prepare('DELETE FROM echipamente_catalog WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    // ------------------------------------------------------------------
    // Helpers stoc
    // ------------------------------------------------------------------

    public function ensureStockRow(int $catalogId, string $location, string $size = '', float $cost = 0.0, int $threshold = 0): int
    {
        $statement = $this->db->prepare('SELECT id FROM echipamente_stoc WHERE catalog_id = :catalog_id AND locatie = :locatie AND marime = :marime');
        $statement->execute(['catalog_id' => $catalogId, 'locatie' => $location, 'marime' => $size]);
        $id = (int) $statement->fetchColumn();
        if ($id > 0) {
            return $id;
        }

        $statement = $this->db->prepare("
            INSERT INTO echipamente_stoc (catalog_id, locatie, marime, disponibil, rezervat, prag_minim, cost_unitar, created_at, updated_at)
            VALUES (:catalog_id, :locatie, :marime, 0, 0, :prag, :cost, NOW(), NOW())
        ");
        $statement->execute([
            'catalog_id' => $catalogId,
            'locatie' => $location,
            'marime' => $size,
            'prag' => $threshold,
            'cost' => $cost,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Disponibilul pentru o mărime anume (mărimea goală = articol fără mărimi). */
    public function availableStock(int $catalogId, string $location, string $size = ''): int
    {
        $statement = $this->db->prepare('
            SELECT COALESCE(disponibil, 0) FROM echipamente_stoc
            WHERE catalog_id = :catalog_id AND locatie = :locatie AND marime = :marime
        ');
        $statement->execute(['catalog_id' => $catalogId, 'locatie' => $location, 'marime' => $size]);

        return (int) $statement->fetchColumn();
    }

    /** Disponibilul pe toate mărimile unui articol, pentru mesajele de context. */
    public function availableStockTotal(int $catalogId): int
    {
        $statement = $this->db->prepare('SELECT COALESCE(SUM(disponibil), 0) FROM echipamente_stoc WHERE catalog_id = :catalog_id');
        $statement->execute(['catalog_id' => $catalogId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Garda de stoc pentru orice ieșire. Mesajul spune explicit când problema
     * este mărimea, nu articolul, ca utilizatorul să nu caute degeaba.
     */
    private function requireStock(array $catalog, string $location, string $size, int $quantity, string $context = 'iesire'): void
    {
        $available = $this->availableStock((int) $catalog['id'], $location, $size);
        if ($available >= $quantity) {
            return;
        }

        // La o ieșire manuală problema este cantitatea cerută, nu aprovizionarea.
        if ($context === 'ajustare') {
            throw new RuntimeException(
                'Stoc insuficient pentru "' . (string) $catalog['denumire'] . '"'
                . ($size !== '' ? ' mărimea ' . $size : '') . ': ' . $available . ' buc. disponibile, ai cerut ' . $quantity . '.'
            );
        }

        throw new RuntimeException($this->stockShortageMessage($catalog, $size));
    }

    private function stockShortageMessage(array $catalog, string $size): string
    {
        $name = (string) $catalog['denumire'];

        if ($size === '') {
            return 'Nu există stoc disponibil pentru "' . $name . '". Este necesară aprovizionarea.';
        }

        $others = $this->availableStockTotal((int) $catalog['id']);
        $message = 'Nu există stoc disponibil pentru "' . $name . '" mărimea ' . $size . '.';

        return $others > 0
            ? $message . ' Există ' . $others . ' buc. în alte mărimi. Este necesară aprovizionarea pe mărimea cerută.'
            : $message . ' Este necesară aprovizionarea.';
    }

    /** Normalizează mărimea: articolele fără mărimi folosesc mereu șirul gol. */
    private function normalizeSize(mixed $size, array $catalog): string
    {
        if ((int) ($catalog['necesita_marime'] ?? 0) !== 1) {
            return '';
        }

        return mb_substr(trim((string) ($size ?? '')), 0, 20);
    }

    private function incrementStock(int $catalogId, string $location, string $size, int $quantity): void
    {
        $this->ensureStockRow($catalogId, $location, $size);
        $statement = $this->db->prepare("
            UPDATE echipamente_stoc SET disponibil = disponibil + :qty, updated_at = NOW()
            WHERE catalog_id = :catalog_id AND locatie = :locatie AND marime = :marime
        ");
        $statement->execute(['qty' => $quantity, 'catalog_id' => $catalogId, 'locatie' => $location, 'marime' => $size]);
    }

    private function decrementStock(int $catalogId, string $location, string $size, int $quantity): void
    {
        $this->ensureStockRow($catalogId, $location, $size);
        $statement = $this->db->prepare("
            UPDATE echipamente_stoc SET disponibil = GREATEST(disponibil - :qty, 0), updated_at = NOW()
            WHERE catalog_id = :catalog_id AND locatie = :locatie AND marime = :marime
        ");
        $statement->execute(['qty' => $quantity, 'catalog_id' => $catalogId, 'locatie' => $location, 'marime' => $size]);
    }

    // ------------------------------------------------------------------
    // Unități individuale (articole serializate)
    // ------------------------------------------------------------------

    private function createUnit(array $data): int
    {
        $statement = $this->db->prepare("
            INSERT INTO echipamente_stoc_unitati
                (catalog_id, stoc_id, serie, iccid, numar_telefon, operator, tip_abonament, cost_lunar,
                 marime, locatie, stare, status, data_intrarii, cost_achizitie, furnizor, document,
                 observatii, created_at, updated_at)
            VALUES
                (:catalog_id, :stoc_id, :serie, :iccid, :numar_telefon, :operator, :tip_abonament, :cost_lunar,
                 :marime, :locatie, 'noua', 'disponibil', :data_intrarii, :cost_achizitie, :furnizor, :document,
                 :observatii, NOW(), NOW())
        ");
        $statement->execute([
            'catalog_id' => (int) $data['catalog_id'],
            'stoc_id' => (int) ($data['stoc_id'] ?? 0) > 0 ? (int) $data['stoc_id'] : null,
            'serie' => $data['serie'] ?? null,
            'iccid' => $data['iccid'] ?? null,
            'numar_telefon' => $data['numar_telefon'] ?? null,
            'operator' => $data['operator'] ?? null,
            'tip_abonament' => array_key_exists((string) ($data['tip_abonament'] ?? ''), self::SUBSCRIPTION_TYPES)
                ? (string) $data['tip_abonament']
                : 'n/a',
            'cost_lunar' => $data['cost_lunar'] !== null && (string) $data['cost_lunar'] !== '' ? (float) $data['cost_lunar'] : null,
            'marime' => (string) ($data['marime'] ?? ''),
            'locatie' => (string) ($data['locatie'] ?? 'Depozit principal'),
            'data_intrarii' => $data['data_intrarii'] ?? date('Y-m-d'),
            'cost_achizitie' => (float) ($data['cost_achizitie'] ?? 0),
            'furnizor' => $data['furnizor'] ?? null,
            'document' => $data['document'] ?? null,
            'observatii' => $data['observatii'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Prima unitate disponibilă din gestiune, cu mărimea cerută. */
    private function pickAvailableUnit(int $catalogId, string $location, string $size, int $preferredId = 0): ?array
    {
        if ($preferredId > 0) {
            $statement = $this->db->prepare("
                SELECT * FROM echipamente_stoc_unitati
                WHERE id = :id AND catalog_id = :catalog_id AND status = 'disponibil'
            ");
            $statement->execute(['id' => $preferredId, 'catalog_id' => $catalogId]);
            $unit = $statement->fetch();
            if (is_array($unit)) {
                return $unit;
            }
        }

        $conditions = ['catalog_id = :catalog_id', "status = 'disponibil'", 'locatie = :locatie'];
        $params = ['catalog_id' => $catalogId, 'locatie' => $location];
        if ($size !== '') {
            $conditions[] = 'marime = :marime';
            $params['marime'] = $size;
        }

        $statement = $this->db->prepare(
            'SELECT * FROM echipamente_stoc_unitati WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id ASC LIMIT 1'
        );
        $statement->execute($params);
        $unit = $statement->fetch();

        return is_array($unit) ? $unit : null;
    }

    public function findUnit(int $unitId): array
    {
        $statement = $this->db->prepare('SELECT * FROM echipamente_stoc_unitati WHERE id = :id');
        $statement->execute(['id' => $unitId]);
        $unit = $statement->fetch();
        if (!is_array($unit)) {
            throw new RuntimeException('Unitatea de stoc nu există.');
        }

        return $unit;
    }

    private function setUnitStatus(int $unitId, string $status, array $extra = []): void
    {
        $fields = ['status = :status'];
        $params = ['status' => $status, 'id' => $unitId];

        if (array_key_exists('alocare_id', $extra)) {
            $fields[] = 'alocare_id = :alocare_id';
            $params['alocare_id'] = $extra['alocare_id'] !== null ? (int) $extra['alocare_id'] : null;
        }
        if (!empty($extra['stare']) && array_key_exists((string) $extra['stare'], self::CONDITIONS)) {
            $fields[] = 'stare = :stare';
            $params['stare'] = (string) $extra['stare'];
        }
        if (!empty($extra['observatii'])) {
            $fields[] = 'observatii = :observatii';
            $params['observatii'] = mb_substr((string) $extra['observatii'], 0, 255);
        }

        $statement = $this->db->prepare(
            'UPDATE echipamente_stoc_unitati SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id'
        );
        $statement->execute($params);
    }

    /** Eticheta cea mai utilă pentru identificarea unei unități. */
    private function unitIdentifier(array $unit): ?string
    {
        foreach (['numar_telefon', 'serie', 'iccid'] as $field) {
            $value = trim((string) ($unit[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extra marime | motiv | furnizor | document | cost_unitar | unitate_id
     */
    private function logMovement(
        ?int $allocationId,
        int $catalogId,
        ?int $driverId,
        string $type,
        int $quantity,
        string $location,
        string $notes,
        array $extra = []
    ): void {
        $userId = null;
        if (function_exists('current_user')) {
            $user = current_user();
            $userId = is_array($user) && (int) ($user['id'] ?? 0) > 0 ? (int) $user['id'] : null;
        }

        // Deținătorul se scrie pe coloana lui, ca istoricul să rămână corect
        // indiferent dacă articolul a plecat la un șofer sau la o persoană TESA.
        $ownerType = $this->normalizeOwnerType($extra['detinator_tip'] ?? self::OWNER_DRIVER);
        $isDriver = $ownerType === self::OWNER_DRIVER;

        $statement = $this->db->prepare("
            INSERT INTO echipamente_miscari
                (alocare_id, unitate_id, catalog_id, driver_id, detinator_tip, staff_id, tip, cantitate,
                 marime, locatie, motiv, furnizor, document, cost_unitar, user_id, observatii, created_at)
            VALUES
                (:alocare_id, :unitate_id, :catalog_id, :driver_id, :detinator_tip, :staff_id, :tip, :cantitate,
                 :marime, :locatie, :motiv, :furnizor, :document, :cost_unitar, :user_id, :observatii, NOW())
        ");
        $statement->execute([
            'alocare_id' => $allocationId,
            'unitate_id' => isset($extra['unitate_id']) && (int) $extra['unitate_id'] > 0 ? (int) $extra['unitate_id'] : null,
            'catalog_id' => $catalogId,
            'driver_id' => $driverId !== null && $isDriver ? $driverId : null,
            'detinator_tip' => $driverId !== null ? $ownerType : null,
            'staff_id' => $driverId !== null && !$isDriver ? $driverId : null,
            'tip' => $type,
            'cantitate' => $quantity,
            'marime' => trim((string) ($extra['marime'] ?? '')) !== '' ? (string) $extra['marime'] : null,
            'locatie' => $location,
            'motiv' => $this->nullable($extra['motiv'] ?? null),
            'furnizor' => $this->nullable($extra['furnizor'] ?? null),
            'document' => $this->nullable($extra['document'] ?? null),
            'cost_unitar' => isset($extra['cost_unitar']) && (string) $extra['cost_unitar'] !== '' ? (float) $extra['cost_unitar'] : null,
            'user_id' => $userId,
            'observatii' => trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null,
        ]);
    }

    // ------------------------------------------------------------------
    // Utilitare
    // ------------------------------------------------------------------

    public function requireAllocation(int $id): array
    {
        $statement = $this->db->prepare("
            SELECT a.*, c.denumire, c.categorie, c.grupa, c.tip_logic, c.returnabil,
                   c.locatie_implicita, c.necesita_marime,
                   COALESCE(s.nume, sm.nume_complet) AS sofer_nume,
                   COALESCE(a.driver_id, a.staff_id) AS detinator_id
            FROM echipamente_alocari a
            INNER JOIN echipamente_catalog c ON c.id = a.catalog_id
            LEFT JOIN soferi s ON s.id = a.driver_id
            LEFT JOIN staff_members sm ON sm.id = a.staff_id
            WHERE a.id = :id
        ");
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Alocarea nu mai există.');
        }

        return $row;
    }

    public function requireCatalogItem(int $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM echipamente_catalog WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Articolul din catalog nu există.');
        }

        return $row;
    }

    private function computeDeadline(array $catalog, string $handoverDate): ?string
    {
        $months = (int) ($catalog['durata_standard_luni'] ?? 0);
        if ($months <= 0 || (int) $catalog['inlocuire_periodica'] !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $handoverDate);

        return $date instanceof DateTimeImmutable
            ? $date->modify('+' . $months . ' months')->format('Y-m-d')
            : null;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function allocationFilters(array $filters): array
    {
        $conditions = [];
        $params = [];

        $tipActiv = (string) ($filters['tip_activ'] ?? '');
        if ($tipActiv === 'alocat') {
            $conditions[] = 'a.status IN (' . $this->inList(self::ACTIVE_STATUSES) . ')';
        } elseif ($tipActiv === 'returnat') {
            $conditions[] = "a.status = 'returnat'";
        } elseif ($tipActiv === 'necesita_inlocuire') {
            $conditions[] = 'a.status IN (' . $this->inList(self::REPLACEMENT_STATUSES) . ')';
        } elseif ($tipActiv === 'stoc') {
            // "În stoc" nu descrie articole alocate: lista de șoferi rămâne goală,
            // iar utilizatorul citește doar secțiunea de stoc.
            $conditions[] = '1 = 0';
        } else {
            $conditions[] = "a.status <> 'inlocuit'";
        }

        $ownerType = trim((string) ($filters['detinator_tip'] ?? ''));
        if ($ownerType !== '') {
            $conditions[] = 'a.detinator_tip = :detinator_tip';
            $params['detinator_tip'] = $this->normalizeOwnerType($ownerType);
        }

        $ownerId = (int) ($filters['driver_id'] ?? 0);
        if ($ownerId > 0) {
            $conditions[] = 'COALESCE(a.driver_id, a.staff_id) = :owner_id';
            $params['owner_id'] = $ownerId;
        }

        // Filtrele proprii personalului TESA (funcție și departament) se aplică
        // pe fișa persoanei, nu pe articol.
        $departament = trim((string) ($filters['departament'] ?? ''));
        if ($departament !== '') {
            $conditions[] = 'stp.name = :departament';
            $params['departament'] = $departament;
        }

        $functie = trim((string) ($filters['functie'] ?? ''));
        if ($functie !== '') {
            $conditions[] = 'sm.functie = :functie';
            $params['functie'] = $functie;
        }

        $categorie = trim((string) ($filters['categorie'] ?? ''));
        if ($categorie === 'Echipamente fizice') {
            $conditions[] = "c.grupa = 'fizic'";
        } elseif ($categorie === 'Comunicații') {
            $conditions[] = "(c.grupa = 'comunicatii' OR c.categorie = 'Comunicații')";
        } elseif ($categorie !== '') {
            $conditions[] = 'c.categorie = :categorie';
            $params['categorie'] = $categorie;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            switch ($status) {
                case 'bun':
                    $conditions[] = "a.stare IN ('noua', 'buna')";
                    break;
                case 'deteriorat':
                    $conditions[] = "a.stare = 'deteriorata'";
                    break;
                case 'de_inlocuit':
                    $conditions[] = 'a.status IN (' . $this->inList(self::REPLACEMENT_STATUSES) . ')';
                    break;
                default:
                    $conditions[] = 'a.status = :status';
                    $params['status'] = $status;
                    break;
            }
        }

        $returnabil = (string) ($filters['returnabil'] ?? '');
        if ($returnabil === 'da' || $returnabil === 'nu') {
            $conditions[] = 'c.returnabil = :returnabil';
            $params['returnabil'] = $returnabil === 'da' ? 1 : 0;
        }

        $luna = trim((string) ($filters['luna'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $luna) === 1) {
            $conditions[] = "DATE_FORMAT(a.data_predarii, '%Y-%m') = :luna";
            $params['luna'] = $luna;
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            // Căutarea acoperă obiectul (denumire, categorie, mărime, serie /
            // IMEI / număr din fișa unității) și deținătorul lui.
            $onAllocation = $this->likeClause(
                ['s.nume', 'sm.nume_complet', 'sm.functie', 'c.denumire', 'c.categorie',
                 'a.identificator', 'a.marime', 'a.operator', 'a.observatii'],
                $search,
                $params,
                'aq'
            );
            $onUnit = $this->likeClause(
                ['u.serie', 'u.iccid', 'u.numar_telefon', 'u.operator'],
                $search,
                $params,
                'auq'
            );

            $conditions[] = '(' . $onAllocation
                . ' OR EXISTS (SELECT 1 FROM echipamente_stoc_unitati u WHERE u.id = a.unitate_id AND ' . $onUnit . '))';
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function hasItemFilters(array $filters): bool
    {
        foreach (['tip_activ', 'categorie', 'status', 'returnabil', 'luna', 'q', 'departament', 'functie'] as $key) {
            if (trim((string) ($filters[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function inList(array $values): string
    {
        return "'" . implode("', '", $values) . "'";
    }

    /**
     * Construiește un OR de LIKE-uri peste mai multe coloane.
     *
     * Conexiunea rulează cu prepared statements reale (fără emulare), unde un
     * placeholder numit poate apărea O SINGURĂ dată — de aceea fiecare coloană
     * primește propriul parametru.
     *
     * @param array<int, string>   $columns
     * @param array<string, mixed> $params  se completează cu valorile legate
     */
    private function likeClause(array $columns, string $search, array &$params, string $prefix): string
    {
        $parts = [];
        foreach (array_values($columns) as $index => $column) {
            $name = $prefix . $index;
            $parts[] = $column . ' LIKE :' . $name;
            $params[$name] = '%' . $search . '%';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : null;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : '?';
    }

    // ------------------------------------------------------------------
    // Catalog — vederea pe siluetă
    // ------------------------------------------------------------------

    /**
     * Zonele de pe siluetă, în procente din pânza imaginilor generate de
     * scripts/generate_equipment_silhouettes.php (viewBox 360 × 860).
     * „dispozitiv” diferă: la șofer e stația de pe piept, la TESA tableta din mână.
     */
    public const SILHOUETTE_ANCHORS = [
        'sofer' => [
            'cap' => [50.0, 4.7], 'trunchi' => [44.0, 35.0], 'maini' => [25.6, 54.7],
            'picioare' => [57.0, 72.7], 'talpi' => [42.5, 94.8], 'dispozitiv' => [58.9, 23.7],
            'legitimatie' => [38.6, 30.0], 'alte' => [50.0, 50.0],
        ],
        'tesa' => [
            'cap' => [50.0, 6.5], 'trunchi' => [44.0, 35.0], 'maini' => [25.6, 54.7],
            'picioare' => [57.0, 72.7], 'talpi' => [42.5, 94.8], 'dispozitiv' => [74.5, 50.6],
            'legitimatie' => [36.7, 29.7], 'alte' => [50.0, 50.0],
        ],
    ];

    /**
     * Zona + iconița unei categorii. Categoriile sunt text liber, deci potrivirea
     * se face pe cuvinte-cheie; ce nu se recunoaște merge la „alte”.
     */
    private const CATEGORY_VISUALS = [
        ['/protec|ppe|casc|ochelar|antifon/', 'cap', 'bi-shield-check'],
        ['/pantal|salopet/', 'picioare', 'bi-person-standing'],
        ['/incalt|bocanc|pantof|ghet|sandal/', 'talpi', 'bi-person-walking'],
        ['/imbrac|tricou|geac|camas|vest|uniform|haina|hanorac/', 'trunchi', 'bi-person-vcard'],
        ['/accesor|manus|scul|unelt/', 'maini', 'bi-tools'],
        ['/comunic|electron|telefon|tablet|radio|sim|laptop|\bit\b/', 'dispozitiv', 'bi-phone'],
        ['/card|document|legitim|acces|ecuson|cheie/', 'legitimatie', 'bi-person-badge'],
    ];

    /** Ordinea casetelor în jurul siluetei, pe zone (prima liberă câștigă). */
    private const SLOT_PREFERENCES = [
        'cap' => ['L1', 'R1', 'L2', 'R2'],
        'dispozitiv' => ['R1', 'R2', 'L1', 'L2'],
        'trunchi' => ['L2', 'R2', 'L1', 'R1'],
        'legitimatie' => ['R2', 'L2', 'R1', 'L1'],
        'maini' => ['L3', 'L2', 'R3', 'R2'],
        'picioare' => ['R3', 'R4', 'L3', 'L4'],
        'talpi' => ['L4', 'R4', 'L3', 'R3'],
        'alte' => ['R4', 'L4', 'R3', 'L3'],
    ];

    /**
     * Pregătește pagina de catalog pe siluetă din datele deja încărcate
     * (getCatalog + getCatalogBreakdown) — fără interogări în plus.
     *
     * Valoarea de stoc folosește aceeași regulă ca pagina Stoc: disponibil ×
     * costul liniei de stoc. Bucățile alocate sunt numărate separat și NU intră
     * în valoarea stocului.
     *
     * @param array<int, array<string, mixed>> $catalogItems
     * @param array<int, array<string, mixed>> $breakdown
     */
    public function buildCatalogOverview(array $catalogItems, array $breakdown): array
    {
        $categories = [];
        $items = [];

        foreach ($catalogItems as $item) {
            $id = (int) $item['id'];
            $lines = $breakdown[$id]['stoc'] ?? [];
            $sizes = [];
            $stockQty = 0;
            $reserved = 0;
            $value = 0.0;
            $worst = null;
            $hasPricedLine = false;
            foreach ($lines as $line) {
                $qty = (int) $line['disponibil'];
                $stockQty += $qty;
                $reserved += (int) $line['rezervat'];
                $value += $qty * (float) $line['cost_unitar'];
                $hasPricedLine = $hasPricedLine || (float) $line['cost_unitar'] > 0;
                $label = (string) $line['marime'] !== '' ? (string) $line['marime'] : 'Unică';
                $sizes[$label] = ($sizes[$label] ?? 0) + $qty;
                $rank = ['epuizat' => 3, 'scazut' => 2, 'in_stoc' => 1][(string) $line['status']['key']] ?? 0;
                if ($worst === null || $rank > $worst['rank']) {
                    $worst = ['rank' => $rank] + $line['status'];
                }
            }
            if ($stockQty > 0 && $worst !== null && $worst['key'] === 'epuizat') {
                // O mărime epuizată nu face tot articolul „epuizat” dacă alte mărimi au stoc.
                $worst = ['rank' => 2, 'label' => 'Stoc parțial', 'tone' => 'warning', 'key' => 'partial'];
            }
            $assigned = (int) ($breakdown[$id]['total_alocat'] ?? 0);
            $price = (float) $item['cost_implicit'];
            $status = (int) $item['activ'] !== 1
                ? ['label' => 'Inactiv', 'tone' => 'muted', 'key' => 'inactiv']
                : ($worst ?? ['label' => 'Fără stoc', 'tone' => 'muted', 'key' => 'fara_stoc']);

            $name = trim((string) $item['categorie']) !== '' ? trim((string) $item['categorie']) : 'Fără categorie';
            $visual = $this->categoryVisual($name);
            $key = $visual['slug'];
            $destination = (string) ($item['destinatie'] ?? 'ambele');

            $items[$id] = [
                'id' => $id,
                'categorie' => $key,
                'destinatie' => $destination,
                'stoc' => $stockQty,
                'rezervat' => $reserved,
                'alocat' => $assigned,
                'valoare' => $value,
                // Preț lipsă ≠ preț zero: îl marcăm ca nesetat, nu ca 0 lei.
                'pret' => $price > 0 || $hasPricedLine ? $price : null,
                'marimi' => $sizes,
                'status' => $status,
            ];

            if (!isset($categories[$key])) {
                $categories[$key] = [
                    'key' => $key,
                    'name' => $name,
                    'anchor' => $visual['anchor'],
                    'icon' => $visual['icon'],
                    'roles' => [],
                ];
            }
            foreach (['sofer', 'tesa'] as $role) {
                if ($destination !== 'ambele' && $destination !== $role) {
                    continue;
                }
                $stats = $categories[$key]['roles'][$role] ?? ['articole' => 0, 'stoc' => 0, 'alocat' => 0, 'valoare' => 0.0];
                $stats['articole']++;
                $stats['stoc'] += $stockQty;
                $stats['alocat'] += $assigned;
                $stats['valoare'] += $value;
                $categories[$key]['roles'][$role] = $stats;
            }
            $all = $categories[$key]['total'] ?? ['articole' => 0, 'stoc' => 0, 'alocat' => 0, 'valoare' => 0.0];
            $all['articole']++;
            $all['stoc'] += $stockQty;
            $all['alocat'] += $assigned;
            $all['valoare'] += $value;
            $categories[$key]['total'] = $all;
        }

        uasort($categories, static fn(array $a, array $b): int => strcoll($a['name'], $b['name']));

        $stages = [];
        foreach (['sofer', 'tesa'] as $role) {
            $taken = [];
            $cards = [];
            $overflow = [];
            foreach ($categories as $key => $category) {
                if (!isset($category['roles'][$role])) {
                    continue;
                }
                $slot = null;
                foreach (array_merge(self::SLOT_PREFERENCES[$category['anchor']] ?? [], ['L1', 'R1', 'L2', 'R2', 'L3', 'R3', 'L4', 'R4']) as $candidate) {
                    if (!isset($taken[$candidate])) {
                        $slot = $candidate;
                        break;
                    }
                }
                if ($slot === null) {
                    $overflow[] = $key;
                    continue;
                }
                $taken[$slot] = true;
                $anchor = self::SILHOUETTE_ANCHORS[$role][$category['anchor']] ?? self::SILHOUETTE_ANCHORS[$role]['alte'];
                $cards[] = ['key' => $key, 'slot' => $slot, 'anchor' => $anchor];
            }
            $stages[$role] = ['cards' => $cards, 'overflow' => $overflow];
        }

        $totals = ['articole' => 0, 'stoc' => 0, 'alocat' => 0, 'valoare' => 0.0];
        foreach ($items as $row) {
            $totals['articole']++;
            $totals['stoc'] += $row['stoc'];
            $totals['alocat'] += $row['alocat'];
            $totals['valoare'] += $row['valoare'];
        }

        return ['categories' => $categories, 'items' => $items, 'stages' => $stages, 'totals' => $totals];
    }

    /** @return array{slug: string, anchor: string, icon: string} */
    public function categoryVisual(string $name): array
    {
        $plain = mb_strtolower($name);
        $plain = strtr($plain, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $plain), '-');

        foreach (self::CATEGORY_VISUALS as [$pattern, $anchor, $icon]) {
            if (preg_match($pattern, $plain)) {
                return ['slug' => $slug !== '' ? $slug : 'categorie', 'anchor' => $anchor, 'icon' => $icon];
            }
        }

        return ['slug' => $slug !== '' ? $slug : 'categorie', 'anchor' => 'alte', 'icon' => 'bi-box-seam'];
    }

    // ------------------------------------------------------------------
    // Cardul „Valoare echipamente alocate” (Echipamente șoferi / TESA)
    // ------------------------------------------------------------------

    /**
     * Cele trei niveluri ale cardului (total -> pe persoană -> pe articole),
     * construite din rândurile deja încărcate de getOwnerRows() — fără interogări
     * în plus și în aceeași perioadă/filtre ca tabelul de sub card.
     *
     * Valoarea unei linii = cantitate × cost_unitar al ALOCĂRII (prețul de la
     * predare), nu prețul curent din catalog. Doar alocările active intră în
     * total; cele returnate / înlocuite nu. Un cost 0 este marcat „preț lipsă”,
     * nu tratat ca valoare confirmată.
     *
     * @param array<int, array<string, mixed>> $ownerRows
     */
    public function buildValueOverview(array $ownerRows): array
    {
        $owners = [];
        $allCatalog = [];
        $totals = ['valoare' => 0.0, 'bucati' => 0, 'persoane' => 0, 'pret_lipsa' => 0];

        foreach ($ownerRows as $row) {
            $items = array_merge(
                $row['echipamente_fizice'] ?? [],
                $row['comunicatii'] ?? [],
                $row['alte_active'] ?? []
            );
            $lines = [];
            $categories = [];
            $catalogIds = [];
            $valoare = 0.0;
            $bucati = 0;
            $missing = 0;

            foreach ($items as $item) {
                if (empty($item['activa'])) {
                    continue;
                }
                $qty = (int) $item['cantitate'];
                $unit = (float) $item['cost_unitar'];
                $lineValue = $qty * $unit;
                $name = trim((string) $item['categorie']) !== '' ? trim((string) $item['categorie']) : 'Fără categorie';
                $visual = $this->categoryVisual($name);
                $key = $visual['slug'];

                $lines[] = [
                    'id' => (int) $item['id'],
                    'denumire' => (string) $item['denumire'],
                    'categorie' => $name,
                    'cat' => $key,
                    'grupa' => self::GROUPS[(string) $item['grupa']] ?? '',
                    'cantitate' => $qty,
                    'marime' => (string) ($item['marime'] ?? ''),
                    'pret' => $unit > 0 ? $unit : null,
                    'valoare' => $lineValue,
                    'status' => self::STATUSES[(string) $item['status']] ?? (string) $item['status'],
                    'de_inlocuit' => in_array((string) $item['status'], self::REPLACEMENT_STATUSES, true),
                    'catalog_id' => (int) $item['catalog_id'],
                    'imagine' => $item['imagine'] ?? null,
                ];

                if (!isset($categories[$key])) {
                    $categories[$key] = ['key' => $key, 'name' => $name, 'icon' => $visual['icon'], 'anchor' => $visual['anchor'], 'valoare' => 0.0, 'bucati' => 0];
                }
                $categories[$key]['valoare'] += $lineValue;
                $categories[$key]['bucati'] += $qty;

                $catalogIds[(int) $item['catalog_id']] = true;
                $allCatalog[(int) $item['catalog_id']] = true;
                $valoare += $lineValue;
                $bucati += $qty;
                $missing += $unit > 0 ? 0 : 1;
            }

            foreach ($categories as &$category) {
                $category['procent'] = $valoare > 0 ? round($category['valoare'] / $valoare * 100, 1) : 0.0;
            }
            unset($category);
            uasort($categories, static fn(array $a, array $b): int => $b['valoare'] <=> $a['valoare'] ?: strcoll($a['name'], $b['name']));
            usort($lines, static fn(array $a, array $b): int => strcoll($a['categorie'], $b['categorie']) ?: $b['valoare'] <=> $a['valoare']);

            $meta = (string) $row['detinator_tip'] === self::OWNER_STAFF
                ? implode(' · ', array_filter([(string) ($row['functie'] ?? ''), (string) ($row['departament'] ?? '')]))
                : (string) ($row['vehicul'] ?? '');

            $owners[] = [
                'id' => (int) $row['id'],
                'nume' => (string) $row['nume'],
                'initiale' => (string) $row['initiale'],
                'meta' => $meta,
                'valoare' => $valoare,
                'articole' => count($catalogIds),
                'bucati' => $bucati,
                'pret_lipsa' => $missing,
                'categorii' => array_values($categories),
                'linii' => $lines,
            ];

            $totals['valoare'] += $valoare;
            $totals['bucati'] += $bucati;
            $totals['persoane'] += $bucati > 0 ? 1 : 0;
            $totals['pret_lipsa'] += $missing;
        }

        usort($owners, static fn(array $a, array $b): int => $b['valoare'] <=> $a['valoare'] ?: strcoll($a['nume'], $b['nume']));
        $totals['articole'] = count($allCatalog);

        return ['owners' => $owners, 'totals' => $totals];
    }

    /**
     * Cardul „În stoc” (Echipamente șoferi / TESA): total -> categorii -> articole.
     *
     * Pornește din getStockRows() — exact rândurile paginii Stoc echipamente — deci
     * statusul, rezervările, pragurile și valoarea (disponibil × cost linie; null
     * pentru abonamente) sunt aceleași. Stocul este comun celor două pagini și nu
     * se filtrează pe destinație (destinația din catalog e doar o sugestie).
     * Liniile se desfac pe mărime acolo unde articolul se ține pe mărimi.
     */
    public function buildStockOverview(): array
    {
        $categories = [];
        $totals = ['disponibil' => 0, 'rezervat' => 0, 'valoare' => 0.0, 'articole' => 0, 'pret_lipsa' => 0];
        $availableArticles = [];

        foreach ($this->getStockRows([]) as $row) {
            $name = trim((string) $row['categorie']) !== '' ? trim((string) $row['categorie']) : 'Fără categorie';
            $visual = $this->categoryVisual($name);
            $catKey = $visual['slug'];
            $articleId = (int) $row['catalog_id'];
            $isSubscription = $row['valoare'] === null;
            $unit = (float) $row['cost_unitar'];
            $missingPrice = !$isSubscription && $unit <= 0;

            if (!isset($categories[$catKey])) {
                $categories[$catKey] = [
                    'key' => $catKey, 'name' => $name, 'icon' => $visual['icon'], 'anchor' => $visual['anchor'],
                    'disponibil' => 0, 'rezervat' => 0, 'valoare' => 0.0, 'pret_lipsa' => 0, 'articole' => [],
                ];
            }
            $category = &$categories[$catKey];
            if (!isset($category['articole'][$articleId])) {
                $category['articole'][$articleId] = [
                    'id' => $articleId, 'denumire' => (string) $row['denumire'],
                    'disponibil' => 0, 'rezervat' => 0, 'valoare' => 0.0, 'praguri' => [], 'linii' => [],
                ];
            }
            $article = &$category['articole'][$articleId];

            // O linie per mărime (dacă articolul are mărimi), altfel una per gestiune.
            $parts = $row['marimi'] !== [] ? $row['marimi'] : [[
                'marime' => '', 'disponibil' => (int) $row['disponibil'], 'rezervat' => (int) $row['rezervat'], 'prag_minim' => (int) $row['prag_minim'],
            ]];
            foreach ($parts as $part) {
                $available = (int) $part['disponibil'];
                $threshold = (int) $part['prag_minim'];
                $article['linii'][] = [
                    'locatie' => (string) $row['locatie'],
                    'marime' => (string) $part['marime'],
                    'disponibil' => $available,
                    'rezervat' => (int) $part['rezervat'],
                    'prag' => $threshold,
                    'pret' => $isSubscription || $missingPrice ? null : $unit,
                    'valoare' => $isSubscription || $missingPrice ? null : $available * $unit,
                    'abonament' => $isSubscription,
                    'status' => $this->stockStatus(['disponibil' => $available, 'prag_minim' => $threshold]),
                ];
                $article['praguri'][$threshold] = true;
            }

            $value = $isSubscription || $missingPrice ? 0.0 : (float) $row['valoare'];
            foreach ([&$article, &$category] as &$bucket) {
                $bucket['disponibil'] += (int) $row['disponibil'];
                $bucket['rezervat'] += (int) $row['rezervat'];
                $bucket['valoare'] += $value;
            }
            unset($bucket);
            $category['pret_lipsa'] += $missingPrice ? 1 : 0;

            $totals['disponibil'] += (int) $row['disponibil'];
            $totals['rezervat'] += (int) $row['rezervat'];
            $totals['valoare'] += $value;
            $totals['pret_lipsa'] += $missingPrice ? 1 : 0;
            if ((int) $row['disponibil'] > 0) {
                $availableArticles[$articleId] = true;
            }
            unset($article, $category);
        }

        foreach ($categories as &$category) {
            $thresholds = [];
            foreach ($category['articole'] as &$article) {
                $article['praguri'] = array_keys($article['praguri']);
                sort($article['praguri']);
                $thresholds = array_merge($thresholds, $article['praguri']);
            }
            unset($article);
            $thresholds = array_values(array_unique($thresholds));
            sort($thresholds);
            // Pragurile diferă pe articol / mărime: nu inventăm un prag unic al categoriei.
            $category['praguri'] = $thresholds;
            $category['sub_prag'] = 0;
            foreach ($category['articole'] as $article) {
                foreach ($article['linii'] as $line) {
                    $category['sub_prag'] += $line['status']['key'] !== 'in_stoc' ? 1 : 0;
                }
            }
            uasort($category['articole'], static fn(array $a, array $b): int => $b['valoare'] <=> $a['valoare'] ?: strcoll($a['denumire'], $b['denumire']));
            $category['articole'] = array_values($category['articole']);
        }
        unset($category);
        uasort($categories, static fn(array $a, array $b): int => $b['valoare'] <=> $a['valoare'] ?: strcoll($a['name'], $b['name']));
        $totals['articole'] = count($availableArticles);

        return ['categories' => array_values($categories), 'totals' => $totals];
    }

    /**
     * Cardul „De returnat”: persoane -> articolele pe care trebuie să le aducă înapoi.
     *
     * Aceeași regulă ca indicatorul `de_returnat` din getKpis(): alocare activă,
     * articol returnabil și fie marcată pentru înlocuire (bucata veche revine), fie
     * deținătorul nu mai este activ. Valoarea = cantitate × costul de la predare —
     * valoare înregistrată de recuperat, NU pierdere și nici sumă recuperată.
     * Returnarea se face pe toată alocarea (backend-ul nu are returnări parțiale).
     */
    public function buildReturnOverview(string $ownerType = self::OWNER_DRIVER): array
    {
        $ownerType = $this->normalizeOwnerType($ownerType);
        $activeIn = $this->inList(self::ACTIVE_STATUSES);
        $replaceIn = $this->inList(self::REPLACEMENT_STATUSES);
        $isDriver = $ownerType === self::OWNER_DRIVER;

        $rows = $this->prepared("
            SELECT a.id, a.catalog_id, a.cantitate, a.marime, a.cost_unitar, a.data_predarii, a.status, a.stare,
                   c.denumire, c.categorie, c.grupa, c.returnabil, c.imagine,
                   COALESCE(a.driver_id, a.staff_id) AS detinator_id,
                   COALESCE(s.nume, sm.nume_complet) AS detinator_nume,
                   COALESCE(s.status, sm.status) AS detinator_status,
                   COALESCE(s.employment_status, sm.employment_status, 'active') AS detinator_angajare,
                   v.nr_inmatriculare AS vehicul, sm.functie, stp.name AS departament
            FROM echipamente_alocari a
            INNER JOIN echipamente_catalog c ON c.id = a.catalog_id
            LEFT JOIN soferi s ON s.id = a.driver_id
            LEFT JOIN vehicule v ON v.id = s.vehicle_id
            LEFT JOIN staff_members sm ON sm.id = a.staff_id
            LEFT JOIN staff_types stp ON stp.id = sm.staff_type_id
            WHERE a.status IN ($activeIn) AND a.detinator_tip = :tip AND c.returnabil = 1
              AND (
                  a.status IN ($replaceIn)
                  OR COALESCE(s.status, sm.status) = 'inactiv'
                  OR COALESCE(s.employment_status, sm.employment_status, 'active') = 'terminated'
              )
            ORDER BY detinator_nume ASC, a.data_predarii ASC
        ", ['tip' => $ownerType])->fetchAll();

        $owners = [];
        $catalogIds = [];
        $totals = ['bucati' => 0, 'valoare' => 0.0, 'persoane' => 0, 'pret_lipsa' => 0];

        foreach ($rows as $row) {
            $ownerId = (int) $row['detinator_id'];
            $inactiveOwner = (string) $row['detinator_status'] === 'inactiv' || (string) $row['detinator_angajare'] === 'terminated';
            $forReplacement = in_array((string) $row['status'], self::REPLACEMENT_STATUSES, true);
            $qty = (int) $row['cantitate'];
            $unit = (float) $row['cost_unitar'];
            $name = trim((string) $row['categorie']) !== '' ? trim((string) $row['categorie']) : 'Fără categorie';
            $visual = $this->categoryVisual($name);

            if (!isset($owners[$ownerId])) {
                $owners[$ownerId] = [
                    'id' => $ownerId,
                    'nume' => (string) $row['detinator_nume'],
                    'initiale' => $this->initials((string) $row['detinator_nume']),
                    'meta' => $isDriver
                        ? trim((string) ($row['vehicul'] ?? ''))
                        : implode(' · ', array_filter([(string) ($row['functie'] ?? ''), (string) ($row['departament'] ?? '')])),
                    'inactiv' => $inactiveOwner,
                    'bucati' => 0, 'valoare' => 0.0, 'pret_lipsa' => 0,
                    'articole' => [], 'categorii' => [], 'linii' => [],
                ];
            }
            $owner = &$owners[$ownerId];
            $owner['linii'][] = [
                'id' => (int) $row['id'],
                'catalog_id' => (int) $row['catalog_id'],
                'denumire' => (string) $row['denumire'],
                'categorie' => $name,
                'cat' => $visual['slug'],
                'icon' => $visual['icon'],
                'marime' => (string) ($row['marime'] ?? ''),
                'cantitate' => $qty,
                'pret' => $unit > 0 ? $unit : null,
                'valoare' => $unit > 0 ? $qty * $unit : null,
                'data_predarii' => (string) $row['data_predarii'],
                // Motivul derivă din datele existente — nu există o coloană „motiv returnare”.
                'motiv' => $inactiveOwner && !$forReplacement
                    ? ($isDriver ? 'Șofer inactiv / plecat' : 'Persoană inactivă / plecată')
                    : 'Marcat pentru înlocuire',
                'status' => self::STATUSES[(string) $row['status']] ?? (string) $row['status'],
                'status_key' => (string) $row['status'],
                'stare' => self::CONDITIONS[(string) $row['stare']] ?? (string) $row['stare'],
                'returnabil' => (int) $row['returnabil'],
                'status_raw' => (string) $row['status'],
                'stare_raw' => (string) $row['stare'],
                'imagine' => $row['imagine'] ?? null,
            ];
            if (!isset($owner['categorii'][$visual['slug']])) {
                $owner['categorii'][$visual['slug']] = [
                    'key' => $visual['slug'], 'name' => $name, 'icon' => $visual['icon'], 'anchor' => $visual['anchor'],
                    'articole' => [], 'bucati' => 0, 'valoare' => 0.0,
                ];
            }
            $owner['categorii'][$visual['slug']]['articole'][(int) $row['catalog_id']] = true;
            $owner['categorii'][$visual['slug']]['bucati'] += $qty;
            $owner['categorii'][$visual['slug']]['valoare'] += $unit > 0 ? $qty * $unit : 0;
            $owner['articole'][(int) $row['catalog_id']] = true;
            $owner['bucati'] += $qty;
            $owner['valoare'] += $unit > 0 ? $qty * $unit : 0;
            $owner['pret_lipsa'] += $unit > 0 ? 0 : 1;
            unset($owner);

            $catalogIds[(int) $row['catalog_id']] = true;
            $totals['bucati'] += $qty;
            $totals['valoare'] += $unit > 0 ? $qty * $unit : 0;
            $totals['pret_lipsa'] += $unit > 0 ? 0 : 1;
        }

        foreach ($owners as &$owner) {
            $owner['articole'] = count($owner['articole']);
            foreach ($owner['categorii'] as &$category) {
                $category['articole'] = count($category['articole']);
            }
            unset($category);
            $owner['categorii'] = array_values($owner['categorii']);
        }
        unset($owner);
        usort($owners, static fn(array $a, array $b): int => $b['valoare'] <=> $a['valoare'] ?: strcoll($a['nume'], $b['nume']));

        $totals['persoane'] = count($owners);
        $totals['articole'] = count($catalogIds);
        $totals['linii'] = count($rows);

        return ['owners' => $owners, 'totals' => $totals];
    }

    /**
     * Cardul „De înlocuit”: persoane -> articolele care trebuie înlocuite.
     *
     * Regula este cea a indicatorului `de_inlocuit` și a filtrului Status „De înlocuit”:
     * alocări cu status de_inlocuit / necesita_inlocuire (getAllocations filtrează exact așa).
     * Termenul, zilele rămase și stocul pentru înlocuire vin din decorateAllocation() — același
     * rând de stoc (gestiunea implicită + mărimea) pe care îl verifică replaceFromStock().
     *
     * Valoarea estimată = cantitate × prețul de ÎNLOCUIRE: costul liniei de stoc din care s-ar
     * preda (gestiune implicită + mărime), altfel costul implicit din catalog. Fără niciunul,
     * linia e marcată „preț lipsă” — nu se inventează.
     */
    public function buildReplacementOverview(string $ownerType = self::OWNER_DRIVER): array
    {
        $ownerType = $this->normalizeOwnerType($ownerType);
        $isDriver = $ownerType === self::OWNER_DRIVER;
        $items = $this->getAllocations(['detinator_tip' => $ownerType, 'status' => 'de_inlocuit']);

        $catalogIds = array_values(array_unique(array_map(static fn(array $i): int => (int) $i['catalog_id'], $items)));
        $catalogCost = [];
        $stockCost = [];
        if ($catalogIds !== []) {
            $in = implode(',', array_map('intval', $catalogIds));
            foreach ($this->db->query("SELECT id, cost_implicit FROM echipamente_catalog WHERE id IN ($in)")->fetchAll() as $c) {
                $catalogCost[(int) $c['id']] = (float) $c['cost_implicit'];
            }
            foreach ($this->db->query("SELECT catalog_id, locatie, marime, cost_unitar FROM echipamente_stoc WHERE catalog_id IN ($in)")->fetchAll() as $s) {
                $stockCost[(int) $s['catalog_id'] . '|' . (string) $s['locatie'] . '|' . (string) $s['marime']] = (float) $s['cost_unitar'];
            }
        }
        $vehicles = [];
        if ($isDriver && $items !== []) {
            foreach ($this->db->query('SELECT s.id, v.nr_inmatriculare FROM soferi s LEFT JOIN vehicule v ON v.id = s.vehicle_id')->fetchAll() as $v) {
                $vehicles[(int) $v['id']] = (string) ($v['nr_inmatriculare'] ?? '');
            }
        }

        $owners = [];
        $articleIds = [];
        $totals = ['bucati' => 0, 'valoare' => 0.0, 'pret_lipsa' => 0, 'fara_stoc' => 0];

        foreach ($items as $item) {
            $ownerId = (int) $item['detinator_id'];
            $qty = (int) $item['cantitate'];
            $size = (string) ($item['marime'] ?? '');
            $stockKey = (int) $item['catalog_id'] . '|' . (string) $item['locatie_implicita'] . '|' . $size;
            $unit = ($stockCost[$stockKey] ?? 0) > 0 ? $stockCost[$stockKey] : (float) ($catalogCost[(int) $item['catalog_id']] ?? 0);
            $value = $unit > 0 ? $qty * $unit : null;
            $name = trim((string) $item['categorie']) !== '' ? trim((string) $item['categorie']) : 'Fără categorie';
            $visual = $this->categoryVisual($name);
            $inStock = (int) $item['stoc_disponibil'];
            $daysLeft = $item['zile_ramase'];

            // Motivul din datele existente (marcaj, stare fizică, termen) — fără coduri noi.
            $reason = match (true) {
                (string) $item['stare'] === 'deteriorata' => 'Deteriorat',
                (string) $item['stare'] === 'pierduta' => 'Pierdut',
                !empty($item['periodic']) && $daysLeft !== null && $daysLeft < 0 => 'Schimb periodic (termen depășit)',
                (string) $item['stare'] === 'uzata' => 'Uzură',
                (string) $item['status'] === 'necesita_inlocuire' => 'Necesită înlocuire',
                default => 'Marcat pentru înlocuire',
            };

            if (!isset($owners[$ownerId])) {
                $owners[$ownerId] = [
                    'id' => $ownerId,
                    'nume' => (string) $item['detinator_nume'],
                    'initiale' => $this->initials((string) $item['detinator_nume']),
                    'meta' => $isDriver
                        ? ($vehicles[$ownerId] ?? '')
                        : implode(' · ', array_filter([(string) ($item['detinator_functie'] ?? ''), (string) ($item['detinator_departament'] ?? '')])),
                    'articole' => [], 'bucati' => 0, 'valoare' => 0.0, 'pret_lipsa' => 0, 'fara_stoc' => 0,
                    'categorii' => [], 'linii' => [],
                ];
            }
            $owner = &$owners[$ownerId];
            $owner['linii'][] = [
                'item' => $item,
                'cat' => $visual['slug'],
                'icon' => $visual['icon'],
                'categorie' => $name,
                'cantitate' => $qty,
                'marime' => $size,
                'pret' => $unit > 0 ? $unit : null,
                'valoare' => $value,
                'motiv' => $reason,
                'nota' => trim((string) ($item['observatii'] ?? '')),
                'termen' => $item['termen'],
                'zile' => $daysLeft,
                'status' => self::STATUSES[(string) $item['status']] ?? (string) $item['status'],
                'stoc' => $inStock,
                'stoc_ok' => $inStock >= $qty,
                'imagine' => $item['imagine'] ?? null,
            ];
            if (!isset($owner['categorii'][$visual['slug']])) {
                $owner['categorii'][$visual['slug']] = [
                    'key' => $visual['slug'], 'name' => $name, 'icon' => $visual['icon'], 'anchor' => $visual['anchor'],
                    'articole' => [], 'bucati' => 0, 'valoare' => 0.0,
                ];
            }
            $owner['categorii'][$visual['slug']]['articole'][(int) $item['catalog_id']] = true;
            $owner['categorii'][$visual['slug']]['bucati'] += $qty;
            $owner['categorii'][$visual['slug']]['valoare'] += $value ?? 0;
            $owner['articole'][(int) $item['catalog_id']] = true;
            $owner['bucati'] += $qty;
            $owner['valoare'] += $value ?? 0;
            $owner['pret_lipsa'] += $value === null ? 1 : 0;
            $owner['fara_stoc'] += $inStock >= $qty ? 0 : 1;
            unset($owner);

            $articleIds[(int) $item['catalog_id']] = true;
            $totals['bucati'] += $qty;
            $totals['valoare'] += $value ?? 0;
            $totals['pret_lipsa'] += $value === null ? 1 : 0;
            $totals['fara_stoc'] += $inStock >= $qty ? 0 : 1;
        }

        foreach ($owners as &$owner) {
            $owner['articole'] = count($owner['articole']);
            foreach ($owner['categorii'] as &$category) {
                $category['articole'] = count($category['articole']);
            }
            unset($category);
            $owner['categorii'] = array_values($owner['categorii']);
        }
        unset($owner);
        usort($owners, static fn(array $a, array $b): int => $b['valoare'] <=> $a['valoare'] ?: strcoll($a['nume'], $b['nume']));

        $totals['persoane'] = count($owners);
        $totals['articole'] = count($articleIds);

        return ['owners' => $owners, 'totals' => $totals];
    }

    /**
     * Galeria pe categorii (clic pe un punct de pe siluetă): toate articolele din
     * catalog grupate după aceeași cheie de categorie ca punctele și casetele.
     * Prețul = costul implicit din catalog, stocul = bucățile disponibile (stoc_total
     * din getCatalog). URL-ul fotografiei se rezolvă în view (EquipmentPhotoService).
     *
     * @param array<int, array<string, mixed>> $catalogItems rânduri din getCatalog()
     * @return array<string, array{name: string, icon: string, items: array<int, array<string, mixed>>}>
     */
    public function buildCategoryGallery(array $catalogItems): array
    {
        $gallery = [];
        foreach ($catalogItems as $item) {
            $name = trim((string) $item['categorie']) !== '' ? trim((string) $item['categorie']) : 'Fără categorie';
            $visual = $this->categoryVisual($name);
            $key = $visual['slug'];
            if (!isset($gallery[$key])) {
                $gallery[$key] = ['name' => $name, 'icon' => $visual['icon'], 'items' => []];
            }
            $gallery[$key]['items'][] = [
                'id' => (int) $item['id'],
                'denumire' => (string) $item['denumire'],
                'imagine' => $item['imagine'] ?? null,
                'pret' => (float) $item['cost_implicit'] > 0 ? (float) $item['cost_implicit'] : null,
                'stoc' => (int) ($item['stoc_total'] ?? 0),
                'destinatie' => (string) ($item['destinatie'] ?? 'ambele'),
                'activ' => (int) $item['activ'] === 1,
                'marime' => (int) $item['necesita_marime'] === 1,
            ];
        }
        foreach ($gallery as &$category) {
            usort($category['items'], static fn(array $a, array $b): int => ($b['imagine'] !== null) <=> ($a['imagine'] !== null) ?: strcoll($a['denumire'], $b['denumire']));
        }
        unset($category);

        return $gallery;
    }
}
