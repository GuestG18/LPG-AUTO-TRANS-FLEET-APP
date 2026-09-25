<?php
declare(strict_types=1);

/**
 * Model dedicat pentru Dashboard Analitic V2.
 *
 * Pastreaza IDENTIC logica de calcul din DispecerCurseModel::getDashboardAnalyticData()
 * (expresiile SQL pentru km, tone, grad de incarcare, facturare / refacturare / cheltuieli)
 * si adauga peste ea informatiile care lipseau:
 *
 *   - grad de folosinta (zile active / zile lucratoare) per vehicul, sofer si flota,
 *     calculat pe perioada selectata, nu pe luna calendaristica;
 *   - agregare per beneficiar (client), inexistenta in versiunea 1;
 *   - raport sumar: medii de km si tone pe tip de transport, per client si media pe client;
 *   - metrici derivate (km/cursa, tone/cursa, marja %, puncte client livrate).
 */
class DashboardAnaliticV2Model extends BaseModel
{
    /** Tipurile de transport grupate in "buckets" - identic cu V1. */
    private const TRANSPORT_BUCKETS = [
        'primar' => 'Primar',
        'distributie' => 'Distributie',
        'primar_distributie' => 'Primar + Distributie',
        'compresor' => 'Compresor',
    ];

    private const STATUS_LABELS = [
        'in_curs_facturare' => 'In curs de facturare',
        'facturat' => 'Facturat',
        'nefacturat' => 'Nefacturat',
    ];

    /** Exista tabela de segmente de cursa? (verificata o singura data pe request) */
    private static ?bool $segmentsAvailable = null;

    /** Modelul de carburant, refolosit ca sa nu-si reverifice schema la fiecare apel. */
    private ?FuelModel $fuelModel = null;

    private const TRANSPORT_TYPE_LABELS = [
        'primar' => 'Primar km',
        'primar_tona' => 'Primar tone',
        'distributie' => 'Distributie',
        'primar_distributie' => 'Primar + Distributie',
        'compresor' => 'Compresor',
    ];

    // ----------------------------------------------------------- optiuni filtre

    public function getFilterOptions(): array
    {
        $from = $this->fromSql();

        // Listele contin si soferii / vehiculele care au facut doar un segment dintr-o
        // cursa reluata din pauza — altfel nu i-ai putea alege in filtre.
        $legsFrom = $this->legsFromSql();
        $vehicleExpr = $this->legVehicleIdExpr();
        $vehicleNameExpr = $this->legVehicleNameExpr();
        $vehicles = $this->db->query("
            SELECT DISTINCT {$vehicleExpr} AS id, {$vehicleNameExpr} AS nr_inmatriculare
            {$legsFrom}
            WHERE {$vehicleExpr} IS NOT NULL AND c.deleted_at IS NULL
            ORDER BY nr_inmatriculare ASC
        ")->fetchAll();

        $driverExpr = $this->legDriverExpr();
        $drivers = $this->db->query("
            SELECT DISTINCT {$driverExpr} AS id,
                   COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer') AS nume
            {$legsFrom}
            WHERE c.deleted_at IS NULL
            ORDER BY nume ASC
        ")->fetchAll();

        $beneficiaries = $this->db->query("
            SELECT DISTINCT c.beneficiar_id AS id,
                   COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar') AS nume
            {$from}
            WHERE c.deleted_at IS NULL
            ORDER BY nume ASC
        ")->fetchAll();

        $transportTypes = $this->db->query("
            SELECT DISTINCT c.tip_transport
            FROM curse_dispecer c
            WHERE COALESCE(TRIM(c.tip_transport), '') <> '' AND c.deleted_at IS NULL
            ORDER BY c.tip_transport ASC
        ")->fetchAll();

        $statuses = $this->db->query("
            SELECT DISTINCT c.status_facturare
            FROM curse_dispecer c
            WHERE COALESCE(TRIM(c.status_facturare), '') <> '' AND c.deleted_at IS NULL
            ORDER BY c.status_facturare ASC
        ")->fetchAll();

        $capacities = $this->db->query("
            SELECT DISTINCT c.capacitate_transport
            FROM curse_dispecer c
            WHERE c.capacitate_transport IS NOT NULL
              AND c.capacitate_transport > 0
              AND c.deleted_at IS NULL
            ORDER BY c.capacitate_transport ASC
        ")->fetchAll();

        /*
         * Categoriile de capacitate ale vehiculelor care au curse. Filtru de
         * GRUPARE: schimba ce curse intra in raport, nu cu ce se imparte gradul
         * de umplere (acela ramane capacitatea reala din snapshot-ul cursei).
         */
        $capacityCategories = $this->db->query("
            SELECT DISTINCT cc.id, cc.nume, cc.ordine_afisare
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN (
                SELECT vc1.tractor_id, vc1.semiremorca_id
                FROM vehicule_cuplaje vc1
                INNER JOIN (
                    SELECT tractor_id, MAX(id) AS max_id
                    FROM vehicule_cuplaje
                    WHERE activ = 1
                    GROUP BY tractor_id
                ) latest ON latest.max_id = vc1.id
            ) vc ON vc.tractor_id = v.id
            LEFT JOIN vehicule s ON s.id = vc.semiremorca_id
            INNER JOIN vehicule_categorii_capacitate cc
                    ON cc.id = CASE
                         WHEN v.tip_vehicul = 'cap_tractor' THEN COALESCE(s.categorie_capacitate_id, v.categorie_capacitate_id)
                         ELSE v.categorie_capacitate_id
                       END
            WHERE c.deleted_at IS NULL
            ORDER BY cc.ordine_afisare ASC, cc.nume ASC
        ")->fetchAll();

        return [
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'beneficiaries' => $beneficiaries,
            'transport_types' => $transportTypes,
            'transport_capacities' => $capacities,
            'capacity_categories' => $capacityCategories,
            'statuses' => $statuses,
            'transport_type_labels' => self::TRANSPORT_TYPE_LABELS,
            'status_labels' => self::STATUS_LABELS,
        ];
    }

    // ------------------------------------------------------------------- date

    public function getData(array $filters): array
    {
        $period = $this->resolvePeriod($filters);
        $from = $this->fromSql();
        $whereData = $this->buildWhere($filters);
        $expr = $this->metricExpressions($period);

        $usage = $this->calculateUsage($filters, $period);

        $fleetRow = $this->fetchOne(
            $this->fleetSql($this->legsFromSql(), $whereData['where'], $expr, $this->legShareExpr()),
            $whereData['params']
        );
        $daily = $this->fetchDailySeries($from, $whereData, $expr);
        $vehicles = $this->fetchVehicles($from, $whereData, $expr, $usage['vehicles'], $usage['zile_lucratoare']);
        $drivers = $this->fetchDrivers($from, $whereData, $expr, $usage['drivers'], $usage['zile_lucratoare']);
        $beneficiaries = $this->fetchBeneficiaries($from, $whereData, $expr);
        $matrixRows = $this->fetchClientTransportMatrix($from, $whereData, $expr);
        $transportRows = $this->fetchTransportTotals($from, $whereData, $expr);

        $fleet = $this->buildFleetKpis($fleetRow, $usage, $beneficiaries);

        $summary = $this->buildSummary($transportRows, $matrixRows);
        $distribution = $this->buildDistribution($from, $whereData, $expr, $this->kmThresholds($filters));
        $alerts = $this->buildAlerts($vehicles, $drivers, $beneficiaries, $fleet, $filters);

        return [
            'fleet' => $fleet,
            'vehicles' => $vehicles,
            'light_vehicles' => $this->getLightVehicleCosts($period),
            'drivers' => $drivers,
            'beneficiaries' => $beneficiaries,
            'summary' => $summary,
            'distribution' => $distribution + ['km_thresholds' => $this->kmThresholds($filters)],
            'daily' => $daily,
            'alerts' => $alerts,
            'labels' => [
                'transport_buckets' => self::TRANSPORT_BUCKETS,
                'transport_types' => self::TRANSPORT_TYPE_LABELS,
                'statuses' => self::STATUS_LABELS,
            ],
            'period' => [
                'start' => $period['start']->format('Y-m-d'),
                'end' => $period['end']->format('Y-m-d'),
                'zile_calendaristice' => $period['zile'],
                'zile_lucratoare' => $usage['zile_lucratoare'],
            ],
        ];
    }

    /** Structura goala, folosita cand interogarea esueaza, ca UI-ul sa nu ramana blocat. */
    public function emptyPayload(array $filters): array
    {
        $period = $this->resolvePeriod($filters);
        $emptyUsage = [
            'zile_active_total' => 0,
            'zile_lucratoare' => 0,
            'vehicule_active' => 0,
            'vehicles' => [],
            'drivers' => [],
        ];

        return [
            'fleet' => $this->buildFleetKpis([], $emptyUsage, []),
            'vehicles' => [],
            'light_vehicles' => ['rows' => [], 'totals' => [], 'error' => null],
            'drivers' => [],
            'beneficiaries' => [],
            'summary' => $this->buildSummary([], []),
            'distribution' => [
                'bands' => [], 'capacities' => [], 'cells' => [], 'trips' => [],
                'km_thresholds' => $this->kmThresholds($filters),
            ],
            'daily' => [
                'labels' => [], 'facturare' => [], 'refacturare' => [], 'cheltuieli' => [],
                'profit' => [], 'km' => [], 'tone' => [], 'curse' => [],
            ],
            'alerts' => [],
            'labels' => [
                'transport_buckets' => self::TRANSPORT_BUCKETS,
                'transport_types' => self::TRANSPORT_TYPE_LABELS,
                'statuses' => self::STATUS_LABELS,
            ],
            'period' => [
                'start' => $period['start']->format('Y-m-d'),
                'end' => $period['end']->format('Y-m-d'),
                'zile_calendaristice' => $period['zile'],
                'zile_lucratoare' => 0,
            ],
        ];
    }

    // ------------------------------------------- distributie km x capacitate

    /** Pragurile implicite pentru intervalele de km (limite superioare, exclusive). */
    private const KM_THRESHOLDS_DEFAULT = [100, 250, 500, 750, 1000];

    /** Cate praguri acceptam, ca sa nu se ajunga la zeci de intervale. */
    private const KM_THRESHOLDS_MAX = 8;

    /** Cate curse pot fi trimise pentru graficul cu puncte individuale. */
    private const TRIP_POINTS_LIMIT = 2000;

    /**
     * Pragurile cerute de utilizator, validate: numere pozitive, crescatoare, fara duplicate.
     * Orice valoare invalida duce la pragurile implicite, ca graficul sa nu ramana gol.
     */
    private function kmThresholds(array $filters): array
    {
        $raw = $filters['km_bands'] ?? [];
        if (!is_array($raw)) {
            $raw = preg_split('/[,\s]+/', trim((string) $raw)) ?: [];
        }

        $clean = [];
        foreach ($raw as $value) {
            $normalized = str_replace(',', '.', trim((string) $value));
            if ($normalized === '' || !is_numeric($normalized)) {
                continue;
            }

            $number = (int) round((float) $normalized);
            if ($number > 0) {
                $clean[$number] = $number;
            }
        }

        $clean = array_values($clean);
        sort($clean);

        if ($clean === []) {
            return self::KM_THRESHOLDS_DEFAULT;
        }

        return array_slice($clean, 0, self::KM_THRESHOLDS_MAX);
    }

    /**
     * Intervalele derivate din praguri: "Fără km", apoi cate un interval intre
     * praguri consecutive si unul deschis la final.
     *
     * @return array<int,array{key:string,label:string}>
     */
    private function kmBandDefinitions(array $thresholds): array
    {
        $bands = [['key' => 'fara_km', 'label' => 'Fără km']];

        foreach ($thresholds as $index => $threshold) {
            $lower = $index === 0 ? 1 : $thresholds[$index - 1];
            $bands[] = [
                'key' => 'band_' . $index,
                'label' => $index === 0
                    ? 'sub ' . format_number_ro($threshold, 0) . ' km'
                    : format_number_ro($lower, 0) . ' – ' . format_number_ro($threshold - 1, 0) . ' km',
            ];
        }

        $last = $thresholds[count($thresholds) - 1];
        $bands[] = ['key' => 'band_max', 'label' => 'de la ' . format_number_ro($last, 0) . ' km'];

        return $bands;
    }

    private function kmBandExpr(array $e, array $thresholds): string
    {
        $km = '(' . $e['km_effective'] . ')';
        $sql = "CASE WHEN {$km} <= 0 THEN 'fara_km'";

        foreach ($thresholds as $index => $threshold) {
            $sql .= " WHEN {$km} < " . (int) $threshold . " THEN 'band_" . (int) $index . "'";
        }

        return $sql . " ELSE 'band_max' END";
    }

    /**
     * Curse grupate pe interval de km si capacitate de transport, plus punctele
     * individuale pentru graficul de dispersie. Cursele fara capacitate configurata
     * nu sunt aruncate, ci grupate separat, ca sa se vada cate sunt.
     */
    private function buildDistribution(string $from, array $whereData, array $e, array $thresholds): array
    {
        $bandDefinitions = $this->kmBandDefinitions($thresholds);

        $rows = $this->fetchAll("
            SELECT
                " . $this->kmBandExpr($e, $thresholds) . " AS km_band,
                c.capacitate_transport AS capacitate,
                COUNT(*) AS curse,
                COALESCE(SUM(" . $e['km_effective'] . "), 0) AS km,
                COALESCE(SUM(" . $e['tons_delivered'] . "), 0) AS tone,
                COALESCE(SUM(" . $e['facturare'] . "), 0) AS facturare,
                COALESCE(SUM(" . $e['cheltuieli'] . "), 0) AS cheltuieli,
                COALESCE(SUM(" . $e['tone_pentru_grad'] . "), 0) AS tone_grad,
                COALESCE(SUM(" . $e['capacitate_aplicabila'] . "), 0) AS capacitate_grad
            {$from}
            {$whereData['where']}
            GROUP BY km_band, c.capacitate_transport
        ", $whereData['params']);

        $bandsUsed = [];
        $capacitiesUsed = [];
        $cells = [];

        foreach ($rows as $row) {
            $band = (string) ($row['km_band'] ?? 'fara_km');
            $rawCapacity = $row['capacitate'];
            $hasCapacity = $rawCapacity !== null && (float) $rawCapacity > 0;
            $capacityKey = $hasCapacity ? number_format((float) $rawCapacity, 2, '.', '') : 'fara_capacitate';

            $bandsUsed[$band] = true;
            $capacitiesUsed[$capacityKey] = $hasCapacity ? (float) $rawCapacity : -1.0;

            $facturare = (float) ($row['facturare'] ?? 0);
            $cheltuieli = (float) ($row['cheltuieli'] ?? 0);
            $curse = (int) ($row['curse'] ?? 0);
            $km = max(0.0, (float) ($row['km'] ?? 0));
            $tone = max(0.0, (float) ($row['tone'] ?? 0));

            // Mai multe randuri pot cadea in aceeasi celula (de exemplu capacitate NULL
            // si capacitate 0 inseamna amandoua "fara capacitate"), deci acumulam.
            $cellKey = $band . '|' . $capacityKey;
            if (!isset($cells[$cellKey])) {
                $cells[$cellKey] = [
                    'band' => $band,
                    'capacitate' => $capacityKey,
                    'curse' => 0,
                    'km' => 0.0,
                    'tone' => 0.0,
                    'facturare' => 0.0,
                    'cheltuieli' => 0.0,
                    'tone_grad' => 0.0,
                    'capacitate_grad' => 0.0,
                ];
            }

            $cells[$cellKey]['curse'] += $curse;
            $cells[$cellKey]['km'] += $km;
            $cells[$cellKey]['tone'] += $tone;
            $cells[$cellKey]['facturare'] += $facturare;
            $cells[$cellKey]['cheltuieli'] += $cheltuieli;
            if ($hasCapacity) {
                // Grad ponderat pe capacitate: acumulam tonele si capacitatile,
                // nu procentele. Impartirea se face o singura data, la final.
                $cells[$cellKey]['tone_grad'] += (float) ($row['tone_grad'] ?? 0);
                $cells[$cellKey]['capacitate_grad'] += (float) ($row['capacitate_grad'] ?? 0);
            }
        }

        foreach ($cells as $key => $cell) {
            $curse = (int) $cell['curse'];
            $cells[$key] = [
                'band' => $cell['band'],
                'capacitate' => $cell['capacitate'],
                'curse' => $curse,
                'km' => round((float) $cell['km'], 2),
                'tone' => round((float) $cell['tone'], 2),
                'facturare' => round((float) $cell['facturare'], 2),
                'profit' => round((float) $cell['facturare'] - (float) $cell['cheltuieli'], 2),
                'grad_incarcare' => $cell['capacitate_grad'] > 0
                    ? round(((float) $cell['tone_grad'] / (float) $cell['capacitate_grad']) * 100, 2)
                    : 0.0,
                // Capetele fractiei raman expuse, ca sa poata fi reagregate ponderat
                // in interfata (o medie de procente ar denatura rezultatul).
                'tone_grad' => round((float) $cell['tone_grad'], 3),
                'capacitate_grad' => round((float) $cell['capacitate_grad'], 3),
                'km_per_cursa' => $curse > 0 ? round((float) $cell['km'] / $curse, 2) : 0.0,
                'tone_per_cursa' => $curse > 0 ? round((float) $cell['tone'] / $curse, 2) : 0.0,
            ];
        }

        // pastram ordinea logica a intervalelor, nu pe cea din baza de date
        $bands = [];
        foreach ($bandDefinitions as $definition) {
            if (isset($bandsUsed[$definition['key']])) {
                $bands[] = $definition;
            }
        }

        // capacitatile crescator, cu "fara capacitate" la final
        asort($capacitiesUsed);
        $capacities = [];
        foreach ($capacitiesUsed as $key => $value) {
            if ($key === 'fara_capacitate') {
                continue;
            }
            $capacities[] = ['key' => $key, 'label' => format_number_ro($value, 2) . ' t', 'value' => $value];
        }
        if (isset($capacitiesUsed['fara_capacitate'])) {
            $capacities[] = ['key' => 'fara_capacitate', 'label' => 'Fără capacitate', 'value' => 0.0];
        }

        return [
            'bands' => $bands,
            'capacities' => $capacities,
            'cells' => array_values($cells),
            'trips' => $this->fetchTripPoints($from, $whereData, $e),
        ];
    }

    /** Puncte individuale (o cursa = un punct) pentru graficul km vs. capacitate. */
    private function fetchTripPoints(string $from, array $whereData, array $e): array
    {
        $limit = self::TRIP_POINTS_LIMIT;

        $rows = $this->fetchAll("
            SELECT
                " . $this->reportingDateExpr() . " AS data,
                COALESCE(NULLIF(TRIM(v.nr_inmatriculare), ''), 'Necunoscut') AS vehicul,
                COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar') AS beneficiar,
                " . $e['bucket'] . " AS bucket,
                c.capacitate_transport AS capacitate,
                (" . $e['km_effective'] . ") AS km,
                (" . $e['tons_delivered'] . ") AS tone,
                (" . $e['grad_incarcare_efectiv'] . ") AS grad_incarcare
            {$from}
            {$whereData['where']}
            ORDER BY " . $this->reportingDateExpr() . " DESC, c.id DESC
            LIMIT {$limit}
        ", $whereData['params']);

        $points = [];
        foreach ($rows as $row) {
            $capacity = $row['capacitate'];

            $points[] = [
                'data' => (string) ($row['data'] ?? ''),
                'vehicul' => (string) ($row['vehicul'] ?? ''),
                'beneficiar' => (string) ($row['beneficiar'] ?? ''),
                'bucket' => (string) ($row['bucket'] ?? 'necunoscut'),
                'capacitate' => ($capacity === null || (float) $capacity <= 0) ? null : round((float) $capacity, 2),
                'km' => round((float) ($row['km'] ?? 0), 2),
                'tone' => round((float) ($row['tone'] ?? 0), 2),
                'grad_incarcare' => $row['grad_incarcare'] === null ? null : round((float) $row['grad_incarcare'], 2),
            ];
        }

        return $points;
    }

    // ------------------------------------------------------ detaliu pe entitate

    /** Coloana pe care se filtreaza fiecare tip de entitate. */
    private const ENTITY_COLUMNS = [
        'vehicul' => 'c.vehicle_id',
        'sofer' => 'c.driver_id',
        'beneficiar' => 'c.beneficiar_id',
    ];

    public static function isEntityType(string $type): bool
    {
        return isset(self::ENTITY_COLUMNS[$type]);
    }

    /**
     * Profilul complet al unui vehicul / sofer / beneficiar, pe aceleasi filtre
     * ca pagina: totaluri, defalcare pe tip de transport, pe partenerii cu care
     * a lucrat, evolutie zilnica si lista curselor.
     */
    public function getEntityProfile(string $type, int $id, array $filters): array
    {
        if (!self::isEntityType($type)) {
            throw new InvalidArgumentException('Tip de entitate necunoscut: ' . $type);
        }

        $from = $this->fromSql();
        $expr = $this->metricExpressions($this->resolvePeriod($filters));
        $whereData = $this->buildWhere($filters);

        // restrangem la entitatea ceruta; id 0 inseamna "fara sofer" / "fara beneficiar"
        $column = self::ENTITY_COLUMNS[$type];
        // Profilul unui sofer citeste cursele desfacute pe segmente si retine doar
        // segmentele lui: cifrele sunt partea care i se cuvine dintr-o cursa pe
        // care a impartit-o cu altcineva. Cursele fara segmente raman intregi.
        $share = null;
        if (($type === 'sofer' || $type === 'vehicul') && $id > 0 && $this->segmentsAvailable()) {
            $from = $type === 'sofer'
                ? $this->legsFromSql(':entity_id')
                : $this->legsFromSql(null, ':entity_id');
            $share = $this->legShareExpr();
            $raceColumn = $type === 'sofer' ? 'c.driver_id' : 'c.vehicle_id';
            $whereData['where'] .= ' AND (sg.id IS NOT NULL OR (sgt.cursa_id IS NULL AND ' . $raceColumn . ' = :entity_race_id))';
            $whereData['params'][':entity_id'] = $id;
            $whereData['params'][':entity_race_id'] = $id;
        } elseif ($id > 0) {
            $whereData['where'] .= ' AND ' . $column . ' = :entity_id';
            $whereData['params'][':entity_id'] = $id;
        } else {
            $whereData['where'] .= ' AND ' . $column . ' IS NULL';
        }

        $period = $this->resolvePeriod($filters);
        $usage = $this->calculateUsage(
            array_merge($filters, $this->entityFilterOverride($type, $id)),
            $period
        );

        $vehicleIdExpr = $share !== null ? $this->legVehicleIdExpr() : 'c.vehicle_id';
        $driverIdExpr = $share !== null ? $this->legDriverExpr() : 'c.driver_id';
        $row = $this->fetchOne("
            SELECT
                " . $this->aggregateColumns($expr, $share) . ",
                MIN(" . $this->entityNameExpr($type, $share !== null) . ") AS nume,
                COUNT(DISTINCT {$vehicleIdExpr}) AS nr_vehicule,
                COUNT(DISTINCT {$driverIdExpr}) AS nr_soferi,
                COUNT(DISTINCT c.beneficiar_id) AS nr_beneficiari,
                MIN(" . $this->reportingDateExpr() . ") AS prima_cursa,
                MAX(" . $this->reportingDateExpr() . ") AS ultima_cursa
            {$from}
            {$whereData['where']}
        ", $whereData['params']);

        $totals = $this->baseEntityMetrics($row);
        $totals['tip'] = $type;
        $totals['id'] = $id;
        $totals['nr_vehicule'] = (int) ($row['nr_vehicule'] ?? 0);
        $totals['nr_soferi'] = (int) ($row['nr_soferi'] ?? 0);
        $totals['nr_beneficiari'] = (int) ($row['nr_beneficiari'] ?? 0);
        $totals['prima_cursa'] = (string) ($row['prima_cursa'] ?? '');
        $totals['ultima_cursa'] = (string) ($row['ultima_cursa'] ?? '');

        $zileActive = $type === 'sofer'
            ? (int) array_sum($usage['drivers'])
            : (int) $usage['zile_active_total'];
        $totals = $this->withUsageMetrics($totals, $zileActive, (int) $usage['zile_lucratoare']);

        return [
            'entity' => $totals,
            'by_transport' => $this->groupedRows($expr['bucket'], $from, $whereData, $expr, self::TRANSPORT_BUCKETS, $share),
            'by_partner' => $this->partnerBreakdowns($type, $from, $whereData, $expr, $share),
            'daily' => $this->fetchDailySeries($from, $whereData, $expr, $share),
            'trips' => $this->fetchTrips($from, $whereData, $expr, $share),
            'period' => [
                'start' => $period['start']->format('Y-m-d'),
                'end' => $period['end']->format('Y-m-d'),
                'zile_lucratoare' => (int) $usage['zile_lucratoare'],
            ],
        ];
    }

    /** Filtrul echivalent, ca sa calculam zilele active doar pentru entitatea ceruta. */
    private function entityFilterOverride(string $type, int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        if ($type === 'vehicul') {
            return ['vehicle_ids' => [$id]];
        }
        if ($type === 'sofer') {
            return ['driver_ids' => [$id]];
        }

        return ['beneficiary_ids' => [$id]];
    }

    private function entityNameExpr(string $type, bool $useLegs = false): string
    {
        if ($type === 'vehicul') {
            return $useLegs
                ? $this->legVehicleNameExpr()
                : "COALESCE(NULLIF(TRIM(v.nr_inmatriculare), ''), 'Necunoscut')";
        }
        if ($type === 'sofer') {
            return "COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer')";
        }

        return "COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar')";
    }

    /** Defalcarile pe parteneri difera in functie de entitatea deschisa. */
    private function partnerBreakdowns(string $type, string $from, array $whereData, array $expr, ?string $share = null): array
    {
        // Pe profilul desfacut pe segmente, partenerii sunt cei de pe segmente: masina
        // cu care soferul chiar a condus, respectiv soferii care au dus chiar masina.
        $vehicleNameExpr = $share !== null ? $this->legVehicleNameExpr() : $this->entityNameExpr('vehicul');
        $vehicule = ['key' => 'vehicule', 'label' => 'Vehicule', 'rows' => $this->groupedRows($vehicleNameExpr, $from, $whereData, $expr, [], $share)];
        $soferi = ['key' => 'soferi', 'label' => 'Soferi', 'rows' => $this->groupedRows($this->entityNameExpr('sofer'), $from, $whereData, $expr, [], $share)];
        $beneficiari = ['key' => 'beneficiari', 'label' => 'Beneficiari', 'rows' => $this->groupedRows($this->entityNameExpr('beneficiar'), $from, $whereData, $expr, [], $share)];

        if ($type === 'vehicul') {
            return [$beneficiari, $soferi];
        }
        if ($type === 'sofer') {
            return [$vehicule, $beneficiari];
        }

        return [$vehicule, $soferi];
    }

    /**
     * Agregare generica pe o expresie de grupare, cu aceleasi formule ca restul paginii.
     *
     * @param array<string,string> $labels etichete prietenoase pentru chei (optional)
     */
    private function groupedRows(string $groupExpr, string $from, array $whereData, array $expr, array $labels = [], ?string $share = null): array
    {
        $rows = $this->fetchAll("
            SELECT
                " . $groupExpr . " AS grup,
                " . $this->aggregateColumns($expr, $share) . "
            {$from}
            {$whereData['where']}
            GROUP BY grup
            ORDER BY curse DESC
        ", $whereData['params']);

        $items = [];
        foreach ($rows as $row) {
            $key = (string) ($row['grup'] ?? '-');
            $item = $this->baseEntityMetrics(['nume' => $labels[$key] ?? $key] + $row);
            $item['key'] = $key;
            $items[] = $item;
        }

        return $items;
    }

    /** Lista curselor din spatele cifrelor, ca sa se poata verifica orice total. */
    private function fetchTrips(string $from, array $whereData, array $expr, ?string $share = null): array
    {
        // In profilul unui sofer, valorile din lista sunt partea lui din cursa, ca
        // suma randurilor sa dea exact totalul de deasupra. Consumul, pretul
        // motorinei si gradul de incarcare raman rapoarte, deci nu se impart.
        $w = static fn (string $expr): string => $share === null
            ? "(" . $expr . ")"
            : "((" . $expr . ") * (" . $share . "))";
        $vehicleExpr = $share !== null ? $this->legVehicleNameExpr() : "COALESCE(NULLIF(TRIM(v.nr_inmatriculare), ''), 'Necunoscut')";

        $rows = $this->fetchAll("
            SELECT
                c.id,
                c.data_inceput,
                c.data_sfarsit,
                " . $this->reportingDateExpr() . " AS data_raportare,
                c.tip_transport,
                c.status_facturare,
                c.capacitate_transport,
                COALESCE(c.nr_clienti, 0) AS nr_clienti,
                {$vehicleExpr} AS vehicul,
                COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer') AS sofer,
                COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar') AS beneficiar,
                COALESCE(NULLIF(TRIM(c.loc_plecare), ''), '') AS loc_plecare,
                COALESCE(NULLIF(TRIM(c.loc_livrare), ''), NULLIF(TRIM(c.loc_livrare_cursa), ''), '') AS loc_livrare,
                " . $w($expr['km_effective']) . " AS km,
                " . $w($expr['km_billed']) . " AS km_facturati,
                " . $w($expr['km_unbilled']) . " AS km_nefacturati,
                " . $w($expr['tons_delivered']) . " AS tone,
                " . $w($expr['facturare']) . " AS facturare,
                " . $w($expr['refacturare']) . " AS refacturare,
                " . $w($expr['cheltuieli']) . " AS cheltuieli,
                " . $w($expr['carburant']) . " AS carburant,
                (" . $expr['consum_l100'] . ") AS consum_l100,
                (" . $expr['pret_motorina'] . ") AS pret_motorina,
                (" . $expr['data_pret_motorina'] . ") AS data_pret_motorina,
                (" . $expr['grad_incarcare_efectiv'] . ") AS grad_incarcare
            {$from}
            {$whereData['where']}
            ORDER BY " . $this->reportingDateExpr() . " DESC, c.id DESC
            LIMIT 500
        ", $whereData['params']);

        $trips = [];
        foreach ($rows as $row) {
            $facturare = (float) ($row['facturare'] ?? 0);
            $cheltuieli = (float) ($row['cheltuieli'] ?? 0);
            $carburant = (float) ($row['carburant'] ?? 0);

            $trips[] = [
                'id' => (int) ($row['id'] ?? 0),
                'data' => (string) ($row['data_raportare'] ?? ''),
                'data_inceput' => (string) ($row['data_inceput'] ?? ''),
                'data_sfarsit' => (string) ($row['data_sfarsit'] ?? ''),
                'tip_transport' => (string) ($row['tip_transport'] ?? ''),
                'tip_label' => self::TRANSPORT_TYPE_LABELS[(string) ($row['tip_transport'] ?? '')] ?? (string) ($row['tip_transport'] ?? ''),
                'status' => (string) ($row['status_facturare'] ?? ''),
                'status_label' => self::STATUS_LABELS[(string) ($row['status_facturare'] ?? '')] ?? (string) ($row['status_facturare'] ?? ''),
                'vehicul' => (string) ($row['vehicul'] ?? ''),
                'sofer' => (string) ($row['sofer'] ?? ''),
                'beneficiar' => (string) ($row['beneficiar'] ?? ''),
                'ruta' => trim(((string) ($row['loc_plecare'] ?? '')) . ' → ' . ((string) ($row['loc_livrare'] ?? '')), ' →'),
                'km' => round((float) ($row['km'] ?? 0), 2),
                'km_nefacturati' => round((float) ($row['km_nefacturati'] ?? 0), 2),
                'tone' => round((float) ($row['tone'] ?? 0), 2),
                'capacitate' => round((float) ($row['capacitate_transport'] ?? 0), 2),
                'nr_clienti' => (int) ($row['nr_clienti'] ?? 0),
                'facturare' => round($facturare, 2),
                'refacturare' => round((float) ($row['refacturare'] ?? 0), 2),
                'cheltuieli' => round($cheltuieli, 2),
                'carburant' => round($carburant, 2),
                'consum_l100' => round((float) ($row['consum_l100'] ?? 0), 2),
                'pret_motorina' => round((float) ($row['pret_motorina'] ?? 0), 4),
                'data_pret_motorina' => (string) ($row['data_pret_motorina'] ?? ''),
                // restul cheltuielilor cursei, ca defalcarea sa se poata citi direct
                'cheltuieli_altele' => round(max(0.0, $cheltuieli - $carburant), 2),
                'cheltuieli_detaliu' => [],
                'profit' => round($facturare - $cheltuieli, 2),
                'grad_incarcare' => $row['grad_incarcare'] === null ? null : round((float) $row['grad_incarcare'], 2),
            ];
        }

        return $this->attachExpenseBreakdown($trips);
    }

    /** Etichetele categoriilor de cheltuiala, ca in formularul de cursa. */
    private const EXPENSE_TYPE_LABELS = [
        'motorina' => 'Motorina',
        'taxa_acces' => 'Taxa acces',
        'port' => 'Port',
        'trece' => 'Trecere',
        'diurna' => 'Diurna',
        'service' => 'Reparatii',
        'alte' => 'Alte cheltuieli',
        'taxe_drum' => 'Taxe drum',
    ];

    /**
     * Defalcarea pe categorii a cheltuielilor inregistrate pe cursa, ca sa se poata
     * citi in detaliul cursei ce compune totalul.
     *
     * Se face intr-o interogare separata, pe id-urile deja selectate: un JOIN in
     * lista de curse ar inmulti randurile si ar dubla sumele agregate.
     *
     * @param array<int,array<string,mixed>> $trips
     * @return array<int,array<string,mixed>>
     */
    private function attachExpenseBreakdown(array $trips): array
    {
        $ids = [];
        foreach ($trips as $trip) {
            if ((int) $trip['id'] > 0) {
                $ids[] = (int) $trip['id'];
            }
        }
        if ($ids === []) {
            return $trips;
        }

        try {
            $rows = $this->fetchAll("
                SELECT
                    ce.cursa_id,
                    ce.tip_cheltuiala AS tip,
                    SUM(CASE WHEN COALESCE(ce.suma, 0) > 0 THEN ce.suma ELSE COALESCE(ce.refacturare_suma, 0) END) AS suma
                FROM curse_cheltuieli ce
                WHERE ce.cursa_id IN (" . implode(',', $ids) . ")
                GROUP BY ce.cursa_id, ce.tip_cheltuiala
                HAVING suma <> 0
            ", []);
        } catch (Throwable $exception) {
            error_log('[DashboardAnaliticV2Model][attachExpenseBreakdown] ' . $exception->getMessage());
            return $trips;
        }

        $peCursa = [];
        foreach ($rows as $row) {
            $tip = (string) ($row['tip'] ?? '');
            $peCursa[(int) $row['cursa_id']][] = [
                'tip' => $tip,
                'label' => self::EXPENSE_TYPE_LABELS[$tip] ?? ($tip !== '' ? $tip : 'Fara categorie'),
                'suma' => round((float) ($row['suma'] ?? 0), 2),
            ];
        }

        foreach ($trips as $index => $trip) {
            $trips[$index]['cheltuieli_detaliu'] = $peCursa[(int) $trip['id']] ?? [];
        }

        return $trips;
    }

    /** @var array<string,string> Expresia SQL a consumului, per perioada rezolvata. */
    private array $consumptionSqlCache = [];

    /**
     * Consumul de motorina (L/100 km) al vehiculului cursei, in luna in care a inceput
     * cursa - exact cifra din pagina Carburanti pentru vehiculul si luna respectiva
     * (FuelModel::getConsumptionByVehicle, doar citire). Daca vehiculul nu are consum
     * calculat in luna aceea, se foloseste media flotei din aceeasi luna; fara date
     * deloc, consumul este 0.
     *
     * Rezultatul este o expresie CASE cu valori literale, calculata o singura data pe
     * perioada, ca sa poata fi folosita in toate agregarile fara interogari in plus.
     */
    private function consumptionSql(array $period): string
    {
        $cheie = $period['start']->format('Y-m') . '|' . $period['end']->format('Y-m');
        if (isset($this->consumptionSqlCache[$cheie])) {
            return $this->consumptionSqlCache[$cheie];
        }

        if (!class_exists('FuelModel')) {
            require_once __DIR__ . '/FuelModel.php';
        }
        // Modelul de carburant isi verifica schema la prima folosire, iar un obiect
        // nou ar reface verificarea (si comitul implicit adus de DDL) la fiecare
        // apel. Il pastram pe toata durata cererii.
        if ($this->fuelModel === null) {
            $this->fuelModel = new FuelModel($this->db);
        }
        $fuel = $this->fuelModel;

        $peVehicul = [];
        $flota = [];
        // O luna in plus la inceput: cursele raportate in perioada pot incepe inainte.
        $luna = $period['start']->modify('first day of this month')->modify('-1 month')->setTime(0, 0);
        $ultima = $period['end']->modify('first day of this month')->setTime(0, 0);
        while ($luna <= $ultima) {
            $cheieLuna = $luna->format('Y-m');
            try {
                $randuri = $fuel->getConsumptionByVehicle([
                    'date_from' => $luna->format('Y-m-d'),
                    'date_to' => $luna->modify('last day of this month')->format('Y-m-d'),
                    'vehicle' => '',
                    'vehicles' => [],
                    'transport_group' => '',
                    'fuel_type' => '',
                    'brand' => '',
                ]);
            } catch (Throwable $exception) {
                error_log('[DashboardAnaliticV2Model][consumptionSql] ' . $exception->getMessage());
                $randuri = [];
            }

            $kmLuna = 0.0;
            $litriLuna = 0.0;
            foreach ($randuri as $rand) {
                $consum = (float) ($rand['consum_motorina'] ?? 0);
                $km = (float) ($rand['km'] ?? 0);
                if ($consum <= 0.0 || $km <= 0.0) {
                    continue;
                }
                $vehicul = str_replace(' ', '', strtoupper((string) ($rand['vehicle_registration'] ?? '')));
                $peVehicul[$cheieLuna . '|' . $vehicul] = $consum;
                $kmLuna += $km;
                $litriLuna += $consum * $km / 100;
            }
            if ($kmLuna > 0.0) {
                $flota[$cheieLuna] = $litriLuna / $kmLuna * 100;
            }
            $luna = $luna->modify('+1 month');
        }

        $lunaCursa = "DATE_FORMAT(c.data_inceput, '%Y-%m')";
        $sql = '0';
        if ($flota !== []) {
            $ramuri = [];
            foreach ($flota as $cheieLuna => $consum) {
                $ramuri[] = 'WHEN ' . $this->db->quote($cheieLuna) . ' THEN ' . sprintf('%.4F', $consum);
            }
            $sql = "CASE {$lunaCursa} " . implode(' ', $ramuri) . ' ELSE 0 END';
        }
        if ($peVehicul !== []) {
            $ramuri = [];
            foreach ($peVehicul as $cheieVehicul => $consum) {
                $ramuri[] = 'WHEN ' . $this->db->quote($cheieVehicul) . ' THEN ' . sprintf('%.4F', $consum);
            }
            $sql = "CASE CONCAT({$lunaCursa}, '|', REPLACE(UPPER(v.nr_inmatriculare), ' ', '')) "
                . implode(' ', $ramuri) . " ELSE {$sql} END";
        }

        return $this->consumptionSqlCache[$cheie] = $sql;
    }

    // --------------------------------------------------------- expresii metrice
    // Copiate 1:1 din DispecerCurseModel::getDashboardAnalyticData(), ca sa
    // garantam ca V2 raporteaza exact aceleasi valori ca pagina live.

    private function metricExpressions(array $period): array
    {
        $kmEffective = "
            CASE
                WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa
                ELSE 0
            END
        ";
        /*
         * Pretul motorinei pentru cursa: pretul unitar (lei/L) din ziua alimentarii
         * asociate cursei in modulul Carburanti (fuel_trip_links). Daca sunt mai multe
         * alimentari cu motorina asociate, media ponderata cu litrii. Fara alimentare
         * asociata, pretul si carburantul cursei sunt 0.
         *
         * Carburantul cursei = km parcursi (Dispecer) x consum L/100 km / 100 x pret.
         * Fara consum, km x pret ar insemna 1 litru pe km - de ~3 ori mai mult decat
         * consuma real un camion.
         */
        $motorinaAsociata = "
            FROM fuel_trip_links l
            INNER JOIN fuel_fillups f ON f.id = l.fillup_id
            WHERE l.trip_id = c.id
              AND f.fuel_type = 'motorina'
              AND f.source_type NOT IN ('test', 'demo')
              AND f.unit_price > 0
              AND f.quantity_liters > 0
        ";
        $pretMotorina = "COALESCE((SELECT SUM(f.unit_price * f.quantity_liters) / SUM(f.quantity_liters) {$motorinaAsociata}), 0)";
        $consum = '(' . $this->consumptionSql($period) . ')';
        $carburant = "(({$kmEffective}) * {$consum} / 100 * {$pretMotorina})";

        $kmBilled = "
            CASE
                WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa
                WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                ELSE 0
            END
        ";
        $kmUnbilled = "
            CASE
                WHEN c.km_totali IS NOT NULL AND c.km_totali > 0
                     AND c.km_cursa IS NOT NULL AND c.km_cursa > 0
                     AND c.km_totali >= c.km_cursa
                THEN c.km_totali - c.km_cursa
                ELSE 0
            END
        ";
        $kmMixed = "
            CASE
                WHEN c.km_totali IS NOT NULL AND c.km_cursa IS NOT NULL THEN c.km_totali - c.km_cursa
                WHEN c.km_totali IS NOT NULL THEN c.km_totali
                WHEN c.km_cursa IS NOT NULL THEN -c.km_cursa
                ELSE 0
            END
        ";
        $kmMixedPositive = "GREATEST(0, (" . $kmMixed . "))";
        $kmPrimar = "
            CASE
                WHEN c.tip_transport IN ('primar', 'primar_tona', 'primar_km', 'primar_distributie', 'mixt')
                THEN (" . $kmBilled . ")
                ELSE 0
            END
        ";
        $kmDistributie = "
            CASE
                WHEN c.tip_transport = 'distributie' THEN (" . $kmBilled . ")
                WHEN c.tip_transport IN ('primar_distributie', 'mixt') THEN (" . $kmMixedPositive . ")
                ELSE 0
            END
        ";
        $kmSaved = "
            CASE
                WHEN c.tip_transport IN ('primar', 'primar_tona', 'primar_km')
                     AND c.km_cursa IS NOT NULL AND c.km_cursa > 0
                     AND c.km_totali IS NOT NULL AND c.km_totali > 0
                     AND c.km_cursa > c.km_totali
                THEN c.km_cursa - c.km_totali
                ELSE 0
            END
        ";
        $kmExcess = "
            CASE
                WHEN c.tip_transport IN ('primar', 'primar_tona', 'primar_km')
                     AND c.km_cursa IS NOT NULL AND c.km_cursa > 0
                     AND c.km_totali IS NOT NULL AND c.km_totali > 0
                     AND c.km_totali > c.km_cursa
                THEN c.km_totali - c.km_cursa
                ELSE 0
            END
        ";
        $loadedTons = "
            CASE
                WHEN c.cantitate_incarcata IS NULL OR c.cantitate_incarcata <= 0 THEN 0
                WHEN c.capacitate_transport IS NOT NULL AND c.capacitate_transport > 0
                     AND c.cantitate_incarcata > (c.capacitate_transport * 3)
                THEN c.cantitate_incarcata / 1000
                WHEN c.cantitate_incarcata >= 1000 THEN c.cantitate_incarcata / 1000
                ELSE c.cantitate_incarcata
            END
        ";
        $deliveredTons = "
            CASE
                WHEN c.tip_transport = 'compresor' THEN COALESCE(c.tona_livrata, 0)
                WHEN c.tona_livrata IS NOT NULL AND c.tona_livrata > 0 THEN c.tona_livrata
                ELSE (" . $loadedTons . ")
            END
        ";
        /*
         * GRAD DE INCARCARE
         * -----------------
         * Numitorul este intotdeauna `c.capacitate_transport` = snapshot-ul
         * capacitatii tehnice REALE a vehiculului la momentul cursei. Categoria
         * de capacitate (eticheta de grupare) nu intra niciodata in acest calcul:
         * gruparea pe categorie decide doar CE curse intra in grup, nu cu ce se imparte.
         *
         * Nu se mai aplica plafonul LEAST(100, ...): o supraincarcare reala
         * (21 t intr-un vehicul de 20 t) trebuie sa se vada ca 105%, nu ca 100%.
         */
        $tonsForGrad = "
            CASE
                WHEN c.capacitate_transport IS NULL OR c.capacitate_transport <= 0 THEN 0
                ELSE GREATEST(0, COALESCE(NULLIF((" . $loadedTons . "), 0), (" . $deliveredTons . ")))
            END
        ";
        // Capacitatea "aplicabila": numai a curselor care au un snapshot de
        // capacitate reala. Este numitorul agregarii ponderate.
        $capacityForGrad = "
            CASE
                WHEN c.capacitate_transport IS NULL OR c.capacitate_transport <= 0 THEN 0
                ELSE c.capacitate_transport
            END
        ";
        // Gradul la nivel de cursa. 0 pentru cursele fara capacitate, ca sa nu
        // se schimbe forma coloanelor vechi care se asteapta la un numar.
        $gradIncarcare = "
            CASE
                WHEN c.capacitate_transport IS NOT NULL AND c.capacitate_transport > 0
                THEN GREATEST(0, ((" . $loadedTons . ") / c.capacitate_transport) * 100)
                ELSE 0
            END
        ";
        // Varianta care nu minte:
        //   - cursele fara capacitate sunt EXCLUSE (NULL), nu numarate ca 0%;
        //   - cand nu exista cantitate incarcata (cazul compresorului, unde se
        //     inregistreaza doar tona livrata), folosim tona livrata ca numarator.
        $gradIncarcareEfectiv = "
            CASE
                WHEN c.capacitate_transport IS NULL OR c.capacitate_transport <= 0 THEN NULL
                ELSE GREATEST(0, (
                    COALESCE(NULLIF((" . $loadedTons . "), 0), (" . $deliveredTons . ")) / c.capacitate_transport
                ) * 100)
            END
        ";

        return [
            'km_effective' => $kmEffective,
            'km_billed' => $kmBilled,
            'km_unbilled' => $kmUnbilled,
            'km_primar' => $kmPrimar,
            'km_distributie' => $kmDistributie,
            'km_saved' => $kmSaved,
            'km_excess' => $kmExcess,
            'tons_delivered' => $deliveredTons,
            'grad_incarcare' => $gradIncarcare,
            'grad_incarcare_efectiv' => $gradIncarcareEfectiv,
            'tone_pentru_grad' => $tonsForGrad,
            'capacitate_aplicabila' => $capacityForGrad,
            'facturare' => "(COALESCE(c.total_facturare, 0) + COALESCE(exp.total_refacturare_facturata, 0))",
            'refacturare' => "COALESCE(exp.total_refacturare_pending, 0)",
            // Cheltuiala totala a cursei: ce s-a inregistrat pe cursa + carburantul
            // calculat din km parcursi x consum x pretul motorinei la alimentare.
            'cheltuieli' => "(COALESCE(exp.total_cheltuieli, 0) + {$carburant})",
            'carburant' => $carburant,
            'consum_l100' => $consum,
            'pret_motorina' => $pretMotorina,
            'data_pret_motorina' => "(SELECT DATE(MIN(f.fillup_datetime)) {$motorinaAsociata})",
            /*
             * Defalcarea refacturarilor nefacturate, calculata pe cursa din aceeasi
             * subinterogare `exp` (fara interogare noua):
             *   - partea acoperita de un cost inregistrat pe cursa => este DEJA in `cheltuieli`
             *   - partea fara cost inregistrat (randuri cu suma = 0) => NU apare in `cheltuieli`
             * Suma celor doua da exact `refacturare`.
             */
            'cheltuieli_proprii' => "COALESCE(exp.total_cheltuieli_proprii, 0)",
            'refacturare_fara_cost' => "COALESCE(exp.total_refacturare_fara_cost, 0)",
            'refacturare_in_cheltuieli' => "GREATEST(0, COALESCE(exp.total_refacturare_pending, 0) - COALESCE(exp.total_refacturare_fara_cost, 0))",
            'bucket' => "
                CASE
                    WHEN c.tip_transport IN ('primar', 'primar_tona', 'primar_km') THEN 'primar'
                    WHEN c.tip_transport IN ('primar_distributie', 'mixt') THEN 'primar_distributie'
                    WHEN c.tip_transport = 'distributie' THEN 'distributie'
                    WHEN c.tip_transport = 'compresor' THEN 'compresor'
                    ELSE COALESCE(NULLIF(TRIM(c.tip_transport), ''), 'necunoscut')
                END
            ",
            'puncte_client' => "COALESCE(c.nr_clienti, 0)",
        ];
    }

    /**
     * Blocul de agregari comun tuturor gruparilor (flota, vehicul, sofer, client).
     *
     * $share este cota randului din cursa, folosita in vederile pe sofer: o cursa
     * oprita si reluata se imparte intre soferii ei, proportional cu km-ii fiecarui
     * segment, deci fiecare suma se inmulteste cu acea cota. Cursele se numara
     * distinct (COUNT(DISTINCT c.id)), ca o cursa impartita sa ramana o cursa.
     */
    private function aggregateColumns(array $e, ?string $share = null): string
    {
        if ($share !== null) {
            return $this->weightedAggregateColumns($e, $share);
        }

        return "
            COUNT(*) AS curse,
            COALESCE(SUM(" . $e['km_effective'] . "), 0) AS km_totali,
            COALESCE(SUM(" . $e['km_billed'] . "), 0) AS km_facturati,
            COALESCE(SUM(" . $e['km_unbilled'] . "), 0) AS km_nefacturati,
            COALESCE(SUM(" . $e['km_primar'] . "), 0) AS km_primar,
            COALESCE(SUM(" . $e['km_distributie'] . "), 0) AS km_distributie,
            COALESCE(SUM(" . $e['km_saved'] . "), 0) AS km_salvati,
            COALESCE(SUM(" . $e['km_excess'] . "), 0) AS km_exces,
            COALESCE(SUM(" . $e['tons_delivered'] . "), 0) AS tone_livrate,
            COALESCE(SUM(" . $e['facturare'] . "), 0) AS facturare,
            COALESCE(SUM(" . $e['refacturare'] . "), 0) AS refacturare,
            COALESCE(SUM(" . $e['refacturare_in_cheltuieli'] . "), 0) AS refacturare_in_cheltuieli,
            COALESCE(SUM(" . $e['refacturare_fara_cost'] . "), 0) AS refacturare_fara_cost,
            COALESCE(SUM(" . $e['cheltuieli_proprii'] . "), 0) AS cheltuieli_proprii,
            COALESCE(SUM(" . $e['carburant'] . "), 0) AS carburant,
            COALESCE(SUM(" . $e['cheltuieli'] . "), 0) AS cheltuieli,
            COALESCE(SUM(" . $e['puncte_client'] . "), 0) AS puncte_client,
            -- Grad de incarcare PONDERAT pe capacitate: total tone / total capacitati
            -- aplicabile. Nu media procentelor: o cursa de 2 t si una de 20 t nu
            -- cantaresc la fel. Curse fara capacitate => nu intra nici la numarator,
            -- nici la numitor.
            COALESCE(
                SUM(" . $e['tone_pentru_grad'] . ") / NULLIF(SUM(" . $e['capacitate_aplicabila'] . "), 0) * 100,
                0
            ) AS grad_incarcare_mediu,
            SUM(CASE WHEN c.capacitate_transport IS NOT NULL AND c.capacitate_transport > 0 THEN 1 ELSE 0 END) AS curse_cu_capacitate,
            -- Cate dintre ele s-au calculat pe o capacitate inca neverificata.
            SUM(CASE WHEN c.capacitate_transport IS NOT NULL AND c.capacitate_transport > 0
                          AND COALESCE(c.capacitate_transport_confirmata, 0) = 0
                     THEN 1 ELSE 0 END) AS curse_capacitate_neconfirmata,
            COALESCE(
                SUM(" . $e['tone_pentru_grad'] . ") / NULLIF(SUM(" . $e['capacitate_aplicabila'] . "), 0) * 100,
                0
            ) AS grad_incarcare_efectiv
        ";
    }

    /**
     * Aceleasi formule, dar fiecare suma inmultita cu cota randului din cursa.
     * Gradul de incarcare ramane un raport: si numaratorul, si numitorul se
     * pondereaza, deci procentul nu se deformeaza.
     */
    private function weightedAggregateColumns(array $e, string $share): string
    {
        $w = static fn (string $expr): string => "COALESCE(SUM((" . $expr . ") * (" . $share . ")), 0)";

        return "
            COUNT(DISTINCT c.id) AS curse,
            " . $w($e['km_effective']) . " AS km_totali,
            " . $w($e['km_billed']) . " AS km_facturati,
            " . $w($e['km_unbilled']) . " AS km_nefacturati,
            " . $w($e['km_primar']) . " AS km_primar,
            " . $w($e['km_distributie']) . " AS km_distributie,
            " . $w($e['km_saved']) . " AS km_salvati,
            " . $w($e['km_excess']) . " AS km_exces,
            " . $w($e['tons_delivered']) . " AS tone_livrate,
            " . $w($e['facturare']) . " AS facturare,
            " . $w($e['refacturare']) . " AS refacturare,
            " . $w($e['refacturare_in_cheltuieli']) . " AS refacturare_in_cheltuieli,
            " . $w($e['refacturare_fara_cost']) . " AS refacturare_fara_cost,
            " . $w($e['cheltuieli_proprii']) . " AS cheltuieli_proprii,
            " . $w($e['carburant']) . " AS carburant,
            " . $w($e['cheltuieli']) . " AS cheltuieli,
            " . $w($e['puncte_client']) . " AS puncte_client,
            COALESCE(
                SUM((" . $e['tone_pentru_grad'] . ") * (" . $share . "))
                    / NULLIF(SUM((" . $e['capacitate_aplicabila'] . ") * (" . $share . ")), 0) * 100,
                0
            ) AS grad_incarcare_mediu,
            COUNT(DISTINCT CASE WHEN c.capacitate_transport IS NOT NULL AND c.capacitate_transport > 0
                                THEN c.id END) AS curse_cu_capacitate,
            COUNT(DISTINCT CASE WHEN c.capacitate_transport IS NOT NULL AND c.capacitate_transport > 0
                                     AND COALESCE(c.capacitate_transport_confirmata, 0) = 0
                                THEN c.id END) AS curse_capacitate_neconfirmata,
            COALESCE(
                SUM((" . $e['tone_pentru_grad'] . ") * (" . $share . "))
                    / NULLIF(SUM((" . $e['capacitate_aplicabila'] . ") * (" . $share . ")), 0) * 100,
                0
            ) AS grad_incarcare_efectiv
        ";
    }

    /**
     * Totalul flotei se citeste tot din cursele desfacute pe segmente, ca numarul de
     * masini si de soferi sa-i cuprinda si pe cei care au facut doar o portiune.
     * Sumele nu se schimba: cotele unei curse dau impreuna exact cursa intreaga.
     */
    private function fleetSql(string $from, string $where, array $e, ?string $share = null): string
    {
        $weight = $share !== null ? ' * (' . $share . ')' : '';
        $vehicleExpr = $share !== null ? $this->legVehicleIdExpr() : 'c.vehicle_id';
        $driverExpr = $share !== null ? $this->legDriverExpr() : 'c.driver_id';

        return "
            SELECT
                " . $this->aggregateColumns($e, $share) . ",
                COALESCE(SUM(CASE WHEN c.tip_transport IN ('primar', 'primar_tona') THEN (" . $e['tons_delivered'] . ") ELSE 0 END{$weight}), 0) AS tone_primar,
                COALESCE(SUM(CASE WHEN c.tip_transport IN ('distributie', 'primar_distributie') THEN (" . $e['tons_delivered'] . ") ELSE 0 END{$weight}), 0) AS tone_distributie,
                COUNT(DISTINCT {$vehicleExpr}) AS nr_vehicule,
                COUNT(DISTINCT {$driverExpr}) AS nr_soferi,
                COUNT(DISTINCT c.beneficiar_id) AS nr_beneficiari
            {$from}
            {$where}
        ";
    }

    // -------------------------------------------------------------- interogari

    private function fetchDailySeries(string $from, array $whereData, array $e, ?string $share = null): array
    {
        // In profilul unui sofer, ziua arata doar partea lui din cursele impartite.
        $w = static fn (string $expr): string => $share === null
            ? "COALESCE(SUM(" . $expr . "), 0)"
            : "COALESCE(SUM((" . $expr . ") * (" . $share . ")), 0)";
        $curse = $share === null ? 'COUNT(*)' : 'COUNT(DISTINCT c.id)';

        $rows = $this->fetchAll("
            SELECT
                " . $this->reportingDateExpr() . " AS zi,
                " . $w($e['facturare']) . " AS facturare,
                " . $w($e['refacturare']) . " AS refacturare,
                " . $w($e['cheltuieli']) . " AS cheltuieli,
                " . $w($e['km_effective']) . " AS km,
                " . $w($e['tons_delivered']) . " AS tone,
                {$curse} AS curse
            {$from}
            {$whereData['where']}
            GROUP BY zi
            ORDER BY zi ASC
        ", $whereData['params']);

        $series = [
            'labels' => [], 'facturare' => [], 'refacturare' => [], 'cheltuieli' => [],
            'profit' => [], 'km' => [], 'tone' => [], 'curse' => [],
        ];

        foreach ($rows as $row) {
            $facturare = (float) ($row['facturare'] ?? 0);
            $cheltuieli = (float) ($row['cheltuieli'] ?? 0);

            $series['labels'][] = (string) ($row['zi'] ?? '');
            $series['facturare'][] = round($facturare, 2);
            $series['refacturare'][] = round((float) ($row['refacturare'] ?? 0), 2);
            $series['cheltuieli'][] = round($cheltuieli, 2);
            $series['profit'][] = round($facturare - $cheltuieli, 2);
            $series['km'][] = round((float) ($row['km'] ?? 0), 2);
            $series['tone'][] = round((float) ($row['tone'] ?? 0), 2);
            $series['curse'][] = (int) ($row['curse'] ?? 0);
        }

        return $series;
    }

    private function fetchVehicles(string $from, array $whereData, array $e, array $usageByVehicle, int $zileLucratoare): array
    {
        // Ca si vederea pe sofer, vederea pe vehicul citeste cursele desfacute pe
        // segmente: daca marfa a fost mutata pe alta masina, fiecare masina primeste
        // partea ei de km, tone si bani, proportional cu km-ii segmentului.
        $legsFrom = $this->legsFromSql();
        $share = $this->legShareExpr();
        $vehicleExpr = $this->legVehicleIdExpr();
        $driverExpr = $this->legDriverExpr();
        $vehicleNameExpr = $this->legVehicleNameExpr();

        $rows = $this->fetchAll("
            SELECT
                {$vehicleExpr} AS vehicle_id,
                {$vehicleNameExpr} AS nume,
                " . $this->aggregateColumns($e, $share) . ",
                COUNT(DISTINCT {$driverExpr}) AS nr_soferi,
                COUNT(DISTINCT c.beneficiar_id) AS nr_beneficiari
            {$legsFrom}
            {$whereData['where']}
            GROUP BY {$vehicleExpr}, {$vehicleNameExpr}
            ORDER BY nume ASC
        ", $whereData['params']);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['vehicle_id'] ?? 0);
            $zileActive = (int) ($usageByVehicle[$id] ?? 0);

            $item = $this->baseEntityMetrics($row);
            $item['id'] = $id;
            $item['tip'] = 'vehicul';
            $item['nr_soferi'] = (int) ($row['nr_soferi'] ?? 0);
            $item['nr_beneficiari'] = (int) ($row['nr_beneficiari'] ?? 0);
            $items[] = $this->withUsageMetrics($item, $zileActive, $zileLucratoare);
        }

        return $items;
    }

    private function fetchDrivers(string $from, array $whereData, array $e, array $usageByDriver, int $zileLucratoare): array
    {
        // Vederea pe sofer citeste cursele desfacute pe segmente: cine a condus
        // fiecare portiune primeste partea lui de km, tone si bani. Cursa ramane
        // una singura (COUNT DISTINCT), asa cum este si in facturare.
        $legsFrom = $this->legsFromSql();
        $share = $this->legShareExpr();
        $driverExpr = $this->legDriverExpr();
        $vehicleExpr = $this->legVehicleIdExpr();

        $rows = $this->fetchAll("
            SELECT
                {$driverExpr} AS driver_id,
                COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer') AS nume,
                " . $this->aggregateColumns($e, $share) . ",
                COUNT(DISTINCT {$vehicleExpr}) AS nr_vehicule,
                COUNT(DISTINCT c.beneficiar_id) AS nr_beneficiari
            {$legsFrom}
            {$whereData['where']}
            GROUP BY {$driverExpr}, COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer')
            ORDER BY nume ASC
        ", $whereData['params']);

        $items = [];
        foreach ($rows as $row) {
            $id = (int) ($row['driver_id'] ?? 0);
            $zileActive = (int) ($usageByDriver[$id] ?? 0);

            $item = $this->baseEntityMetrics($row);
            $item['id'] = $id;
            $item['tip'] = 'sofer';
            $item['nr_vehicule'] = (int) ($row['nr_vehicule'] ?? 0);
            $item['nr_beneficiari'] = (int) ($row['nr_beneficiari'] ?? 0);
            $items[] = $this->withUsageMetrics($item, $zileActive, $zileLucratoare);
        }

        return $items;
    }

    private function fetchBeneficiaries(string $from, array $whereData, array $e): array
    {
        // Beneficiarul este al cursei intregi, dar masinile si soferii lui se numara
        // pe segmente: o cursa preluata de alta masina aduce si acea masina la socoteala.
        $legsFrom = $this->legsFromSql();
        $share = $this->legShareExpr();
        $vehicleExpr = $this->legVehicleIdExpr();
        $driverExpr = $this->legDriverExpr();

        $rows = $this->fetchAll("
            SELECT
                c.beneficiar_id,
                COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar') AS nume,
                " . $this->aggregateColumns($e, $share) . ",
                COUNT(DISTINCT {$vehicleExpr}) AS nr_vehicule,
                COUNT(DISTINCT {$driverExpr}) AS nr_soferi,
                COUNT(DISTINCT " . $e['bucket'] . ") AS nr_tipuri_transport
            {$legsFrom}
            {$whereData['where']}
            GROUP BY c.beneficiar_id, COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar')
            ORDER BY nume ASC
        ", $whereData['params']);

        $items = [];
        foreach ($rows as $row) {
            $item = $this->baseEntityMetrics($row);
            $item['id'] = (int) ($row['beneficiar_id'] ?? 0);
            $item['tip'] = 'beneficiar';
            $item['nr_vehicule'] = (int) ($row['nr_vehicule'] ?? 0);
            $item['nr_soferi'] = (int) ($row['nr_soferi'] ?? 0);
            $item['nr_tipuri_transport'] = (int) ($row['nr_tipuri_transport'] ?? 0);
            $item['zile_active'] = 0;
            $item['zile_disponibile'] = 0;
            $item['grad_folosinta'] = 0.0;
            $item['curse_per_zi_activa'] = 0.0;
            $items[] = $item;
        }

        return $items;
    }

    private function fetchClientTransportMatrix(string $from, array $whereData, array $e): array
    {
        return $this->fetchAll("
            SELECT
                c.beneficiar_id,
                COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar') AS nume,
                " . $e['bucket'] . " AS bucket,
                COUNT(*) AS curse,
                COALESCE(SUM(" . $e['km_effective'] . "), 0) AS km,
                COALESCE(SUM(" . $e['tons_delivered'] . "), 0) AS tone,
                COALESCE(SUM(" . $e['facturare'] . "), 0) AS facturare,
                COALESCE(SUM(" . $e['cheltuieli'] . "), 0) AS cheltuieli,
                COALESCE(SUM(" . $e['puncte_client'] . "), 0) AS puncte_client,
                COALESCE(
                    SUM(" . $e['tone_pentru_grad'] . ") / NULLIF(SUM(" . $e['capacitate_aplicabila'] . "), 0) * 100,
                    0
                ) AS grad_incarcare_mediu
            {$from}
            {$whereData['where']}
            GROUP BY c.beneficiar_id, nume, bucket
            ORDER BY nume ASC
        ", $whereData['params']);
    }

    private function fetchTransportTotals(string $from, array $whereData, array $e): array
    {
        return $this->fetchAll("
            SELECT
                " . $e['bucket'] . " AS bucket,
                COUNT(*) AS curse,
                COALESCE(SUM(" . $e['km_effective'] . "), 0) AS km,
                COALESCE(SUM(" . $e['tons_delivered'] . "), 0) AS tone,
                COALESCE(SUM(" . $e['facturare'] . "), 0) AS facturare,
                COALESCE(SUM(" . $e['refacturare'] . "), 0) AS refacturare,
                COALESCE(SUM(" . $e['cheltuieli'] . "), 0) AS cheltuieli,
                COALESCE(SUM(" . $e['puncte_client'] . "), 0) AS puncte_client,
                COALESCE(
                    SUM(" . $e['tone_pentru_grad'] . ") / NULLIF(SUM(" . $e['capacitate_aplicabila'] . "), 0) * 100,
                    0
                ) AS grad_incarcare_mediu,
                COUNT(DISTINCT c.beneficiar_id) AS nr_beneficiari,
                COUNT(DISTINCT c.vehicle_id) AS nr_vehicule
            {$from}
            {$whereData['where']}
            GROUP BY bucket
            ORDER BY curse DESC
        ", $whereData['params']);
    }

    // ----------------------------------------------------------------- calcule

    /** Metricile comune oricarei entitati agregate (vehicul / sofer / beneficiar). */
    private function baseEntityMetrics(array $row): array
    {
        $curse = (int) ($row['curse'] ?? 0);
        $km = max(0.0, (float) ($row['km_totali'] ?? 0));
        $kmBilled = max(0.0, (float) ($row['km_facturati'] ?? 0));
        $kmUnbilled = max(0.0, (float) ($row['km_nefacturati'] ?? 0));
        $tone = max(0.0, (float) ($row['tone_livrate'] ?? 0));
        $facturare = (float) ($row['facturare'] ?? 0);
        $refacturare = (float) ($row['refacturare'] ?? 0);
        $cheltuieli = (float) ($row['cheltuieli'] ?? 0);
        $profit = $facturare - $cheltuieli;
        /*
         * Raportarile pe km (venit / cost / profit pe km) se impart la km PARCURSI,
         * nu la km facturati: motorina si uzura se consuma pe toti kilometrii, inclusiv
         * pe cei nefacturati, iar impartirea la km facturati readucea practic tariful.
         *
         * DIFERENTA INTENTIONATA FATA DE V1, care imparte la km facturati. Restul
         * metricilor raman identice cu pagina live.
         */
        $kmBase = $km > 0 ? $km : $kmBilled;
        $puncte = (int) ($row['puncte_client'] ?? 0);

        return [
            'nume' => (string) ($row['nume'] ?? '-'),
            'curse' => $curse,
            'km_totali' => round($km, 2),
            'km_facturati' => round($kmBilled, 2),
            'km_nefacturati' => round($kmUnbilled, 2),
            'km_primar' => round(max(0.0, (float) ($row['km_primar'] ?? 0)), 2),
            'km_distributie' => round(max(0.0, (float) ($row['km_distributie'] ?? 0)), 2),
            'km_salvati' => round(max(0.0, (float) ($row['km_salvati'] ?? 0)), 2),
            'km_exces' => round(max(0.0, (float) ($row['km_exces'] ?? 0)), 2),
            'tone_livrate' => round($tone, 2),
            'facturare' => round($facturare, 2),
            'refacturare' => round($refacturare, 2),
            // din care este deja numarat in `cheltuieli` si cat nu are cost inregistrat pe cursa
            'cheltuieli_proprii' => round((float) ($row['cheltuieli_proprii'] ?? 0), 2),
            // km parcursi x pretul motorinei la alimentare, deja inclus in `cheltuieli`
            'carburant' => round((float) ($row['carburant'] ?? 0), 2),
            'refacturare_in_cheltuieli' => round((float) ($row['refacturare_in_cheltuieli'] ?? 0), 2),
            'refacturare_fara_cost' => round((float) ($row['refacturare_fara_cost'] ?? 0), 2),
            'cheltuieli' => round($cheltuieli, 2),
            'profit' => round($profit, 2),
            'venit_km' => $kmBase > 0 ? round($facturare / $kmBase, 4) : 0.0,
            'cost_km' => $kmBase > 0 ? round($cheltuieli / $kmBase, 4) : 0.0,
            'profit_km' => $kmBase > 0 ? round($profit / $kmBase, 4) : 0.0,
            'venit_tona' => $tone > 0 ? round($facturare / $tone, 2) : 0.0,
            'profit_tona' => $tone > 0 ? round($profit / $tone, 2) : 0.0,
            'km_per_cursa' => $curse > 0 ? round($km / $curse, 2) : 0.0,
            'tone_per_cursa' => $curse > 0 ? round($tone / $curse, 2) : 0.0,
            'km_nefacturati_percent' => $km > 0 ? round(($kmUnbilled / $km) * 100, 2) : 0.0,
            /*
             * Grad de incarcare PONDERAT pe capacitate (total tone / total capacitati
             * aplicabile), identic cu V1. Cursele fara snapshot de capacitate nu intra
             * in niciun capat al fractiei, deci `grad_incarcare` si
             * `grad_incarcare_efectiv` coincid; diferenta dintre ele s-a pierdut odata
             * cu media aritmetica si se citeste acum din `curse_cu_capacitate`.
             */
            'grad_incarcare' => round((float) ($row['grad_incarcare_mediu'] ?? 0), 2),
            'grad_incarcare_efectiv' => round((float) ($row['grad_incarcare_efectiv'] ?? 0), 2),
            'curse_cu_capacitate' => (int) ($row['curse_cu_capacitate'] ?? 0),
            // Cate curse s-au calculat pe o capacitate reala inca neverificata.
            'curse_capacitate_neconfirmata' => (int) ($row['curse_capacitate_neconfirmata'] ?? 0),
            'marja_percent' => $facturare > 0 ? round(($profit / $facturare) * 100, 2) : 0.0,
            'puncte_client' => $puncte,
            'km_per_punct' => $puncte > 0 ? round($km / $puncte, 2) : 0.0,
            'tone_per_punct' => $puncte > 0 ? round($tone / $puncte, 2) : 0.0,
        ];
    }

    private function withUsageMetrics(array $item, int $zileActive, int $zileLucratoare): array
    {
        $item['zile_active'] = $zileActive;
        $item['zile_disponibile'] = $zileLucratoare;
        $item['grad_folosinta'] = $zileLucratoare > 0
            ? round(min(100, ($zileActive / $zileLucratoare) * 100), 2)
            : 0.0;
        $item['curse_per_zi_activa'] = $zileActive > 0 ? round($item['curse'] / $zileActive, 2) : 0.0;
        $item['km_per_zi_activa'] = $zileActive > 0 ? round($item['km_totali'] / $zileActive, 2) : 0.0;

        return $item;
    }

    private function buildFleetKpis(array $row, array $usage, array $beneficiaries): array
    {
        $fleet = $this->baseEntityMetrics($row + ['nume' => 'Flota']);

        $zileActive = (int) ($usage['zile_active_total'] ?? 0);
        $zileLucratoare = (int) ($usage['zile_lucratoare'] ?? 0);
        $vehiculeActive = (int) ($usage['vehicule_active'] ?? 0);
        $zileDisponibile = $vehiculeActive * $zileLucratoare;

        /*
         * Refacturarile NEfacturate nu apartin Facturarii: sunt bani avansati de firma
         * si nerecuperati inca, deci se raporteaza la Cheltuieli.
         *
         * `cheltuieli` numara banii chiar scosi: `suma` acolo unde este completata,
         * `refacturare_suma` pe randurile introduse doar ca refacturare (suma = 0).
         * Cele doua coloane nu se aduna pe acelasi rand - ar dubla acelasi cost.
         *
         * Cand o refacturare devine facturata, costul RAMANE in cheltuieli (banii chiar
         * au fost cheltuiti), iar suma recuperata intra in Facturare. Efectul net asupra
         * profitului este zero, cum si trebuie.
         */
        $fleet['tone_primar'] = round((float) ($row['tone_primar'] ?? 0), 2);
        $fleet['tone_distributie'] = round((float) ($row['tone_distributie'] ?? 0), 2);
        $fleet['nr_vehicule'] = (int) ($row['nr_vehicule'] ?? 0);
        $fleet['nr_soferi'] = (int) ($row['nr_soferi'] ?? 0);
        // Numaram randurile efective de beneficiari: COUNT(DISTINCT) ignora cursele fara beneficiar,
        // care apar totusi ca rand separat ("Fara beneficiar") in tabele.
        $fleet['nr_beneficiari'] = $beneficiaries !== []
            ? count($beneficiaries)
            : (int) ($row['nr_beneficiari'] ?? 0);
        $fleet['km_tona'] = $fleet['tone_livrate'] > 0 ? round($fleet['km_totali'] / $fleet['tone_livrate'], 4) : 0.0;
        $fleet['tona_km'] = $fleet['km_totali'] > 0 ? round($fleet['tone_livrate'] / $fleet['km_totali'], 4) : 0.0;

        $fleet['zile_active'] = $zileActive;
        $fleet['zile_lucratoare'] = $zileLucratoare;
        $fleet['zile_disponibile'] = $zileDisponibile;
        $fleet['vehicule_active'] = $vehiculeActive;
        $fleet['grad_folosinta'] = $zileDisponibile > 0 ? round(($zileActive / $zileDisponibile) * 100, 2) : 0.0;
        $fleet['curse_per_zi_activa'] = $zileActive > 0 ? round($fleet['curse'] / $zileActive, 2) : 0.0;

        return $fleet;
    }

    /**
     * Raport sumar: medii de km si tone pe tip de transport, cu defalcare pe client
     * si media pe client (media aritmetica a totalurilor clientilor, nu media pe cursa).
     */
    private function buildSummary(array $transportRows, array $matrixRows): array
    {
        $buckets = [];
        foreach ($transportRows as $row) {
            $key = (string) ($row['bucket'] ?? 'necunoscut');
            $curse = (int) ($row['curse'] ?? 0);
            $km = max(0.0, (float) ($row['km'] ?? 0));
            $tone = max(0.0, (float) ($row['tone'] ?? 0));
            $facturare = (float) ($row['facturare'] ?? 0);
            $cheltuieli = (float) ($row['cheltuieli'] ?? 0);
            $puncte = (int) ($row['puncte_client'] ?? 0);
            $nrClienti = (int) ($row['nr_beneficiari'] ?? 0);

            $buckets[$key] = [
                'key' => $key,
                'label' => self::TRANSPORT_BUCKETS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'curse' => $curse,
                'km' => round($km, 2),
                'tone' => round($tone, 2),
                'facturare' => round($facturare, 2),
                'refacturare' => round((float) ($row['refacturare'] ?? 0), 2),
                'cheltuieli' => round($cheltuieli, 2),
                'profit' => round($facturare - $cheltuieli, 2),
                'grad_incarcare' => round((float) ($row['grad_incarcare_mediu'] ?? 0), 2),
                'nr_clienti' => $nrClienti,
                'nr_vehicule' => (int) ($row['nr_vehicule'] ?? 0),
                'puncte_client' => $puncte,
                // Media pe cursa
                'km_per_cursa' => $curse > 0 ? round($km / $curse, 2) : 0.0,
                'tone_per_cursa' => $curse > 0 ? round($tone / $curse, 2) : 0.0,
                // Media pe client: totalul impartit la numarul de clienti care au avut acest tip
                'km_per_client' => $nrClienti > 0 ? round($km / $nrClienti, 2) : 0.0,
                'tone_per_client' => $nrClienti > 0 ? round($tone / $nrClienti, 2) : 0.0,
                'curse_per_client' => $nrClienti > 0 ? round($curse / $nrClienti, 2) : 0.0,
                // Media pe punct de livrare (nr_clienti inregistrat pe cursa)
                'km_per_punct' => $puncte > 0 ? round($km / $puncte, 2) : 0.0,
                'tone_per_punct' => $puncte > 0 ? round($tone / $puncte, 2) : 0.0,
            ];
        }

        // Matricea client x tip de transport
        $clients = [];
        foreach ($matrixRows as $row) {
            $clientId = (int) ($row['beneficiar_id'] ?? 0);
            $bucket = (string) ($row['bucket'] ?? 'necunoscut');
            $curse = (int) ($row['curse'] ?? 0);
            $km = max(0.0, (float) ($row['km'] ?? 0));
            $tone = max(0.0, (float) ($row['tone'] ?? 0));
            $facturare = (float) ($row['facturare'] ?? 0);
            $cheltuieli = (float) ($row['cheltuieli'] ?? 0);
            $puncte = (int) ($row['puncte_client'] ?? 0);

            if (!isset($clients[$clientId])) {
                $clients[$clientId] = [
                    'id' => $clientId,
                    'nume' => (string) ($row['nume'] ?? 'Fara beneficiar'),
                    'buckets' => [],
                    'total' => [
                        'curse' => 0, 'km' => 0.0, 'tone' => 0.0,
                        'facturare' => 0.0, 'cheltuieli' => 0.0, 'puncte_client' => 0,
                    ],
                ];
            }

            $clients[$clientId]['buckets'][$bucket] = [
                'curse' => $curse,
                'km' => round($km, 2),
                'tone' => round($tone, 2),
                'facturare' => round($facturare, 2),
                'profit' => round($facturare - $cheltuieli, 2),
                'puncte_client' => $puncte,
                'grad_incarcare' => round((float) ($row['grad_incarcare_mediu'] ?? 0), 2),
                'km_per_cursa' => $curse > 0 ? round($km / $curse, 2) : 0.0,
                'tone_per_cursa' => $curse > 0 ? round($tone / $curse, 2) : 0.0,
                'km_per_punct' => $puncte > 0 ? round($km / $puncte, 2) : 0.0,
                'tone_per_punct' => $puncte > 0 ? round($tone / $puncte, 2) : 0.0,
            ];

            $clients[$clientId]['total']['curse'] += $curse;
            $clients[$clientId]['total']['km'] += $km;
            $clients[$clientId]['total']['tone'] += $tone;
            $clients[$clientId]['total']['facturare'] += $facturare;
            $clients[$clientId]['total']['cheltuieli'] += $cheltuieli;
            $clients[$clientId]['total']['puncte_client'] += $puncte;
        }

        foreach ($clients as $id => $client) {
            $curse = (int) $client['total']['curse'];
            $km = (float) $client['total']['km'];
            $tone = (float) $client['total']['tone'];
            $facturare = (float) $client['total']['facturare'];
            $cheltuieli = (float) $client['total']['cheltuieli'];
            $puncte = (int) $client['total']['puncte_client'];

            $clients[$id]['total'] = [
                'curse' => $curse,
                'km' => round($km, 2),
                'tone' => round($tone, 2),
                'facturare' => round($facturare, 2),
                'cheltuieli' => round($cheltuieli, 2),
                'profit' => round($facturare - $cheltuieli, 2),
                'puncte_client' => $puncte,
                'km_per_cursa' => $curse > 0 ? round($km / $curse, 2) : 0.0,
                'tone_per_cursa' => $curse > 0 ? round($tone / $curse, 2) : 0.0,
                'km_per_punct' => $puncte > 0 ? round($km / $puncte, 2) : 0.0,
                'tone_per_punct' => $puncte > 0 ? round($tone / $puncte, 2) : 0.0,
                'nr_tipuri' => count($client['buckets']),
            ];
        }

        $clients = array_values($clients);
        usort($clients, static fn(array $a, array $b): int => $b['total']['km'] <=> $a['total']['km']);

        // Media pe client: media aritmetica a totalurilor per client (fiecare client cantareste la fel)
        $nrClienti = count($clients);
        $mediaClient = [
            'nr_clienti' => $nrClienti,
            'curse' => 0.0, 'km' => 0.0, 'tone' => 0.0,
            'facturare' => 0.0, 'profit' => 0.0,
            'km_per_cursa' => 0.0, 'tone_per_cursa' => 0.0,
        ];

        if ($nrClienti > 0) {
            $sumCurse = $sumKm = $sumTone = $sumFacturare = $sumProfit = 0.0;
            foreach ($clients as $client) {
                $sumCurse += (float) $client['total']['curse'];
                $sumKm += (float) $client['total']['km'];
                $sumTone += (float) $client['total']['tone'];
                $sumFacturare += (float) $client['total']['facturare'];
                $sumProfit += (float) $client['total']['profit'];
            }

            $mediaClient['curse'] = round($sumCurse / $nrClienti, 2);
            $mediaClient['km'] = round($sumKm / $nrClienti, 2);
            $mediaClient['tone'] = round($sumTone / $nrClienti, 2);
            $mediaClient['facturare'] = round($sumFacturare / $nrClienti, 2);
            $mediaClient['profit'] = round($sumProfit / $nrClienti, 2);
            $mediaClient['km_per_cursa'] = $sumCurse > 0 ? round($sumKm / $sumCurse, 2) : 0.0;
            $mediaClient['tone_per_cursa'] = $sumCurse > 0 ? round($sumTone / $sumCurse, 2) : 0.0;
        }

        // Media pe client pentru fiecare tip de transport (doar clientii care au acel tip)
        $bucketClientAverages = [];
        foreach (array_keys($buckets) as $key) {
            $values = [];
            foreach ($clients as $client) {
                if (isset($client['buckets'][$key])) {
                    $values[] = $client['buckets'][$key];
                }
            }

            $n = count($values);
            $bucketClientAverages[$key] = [
                'nr_clienti' => $n,
                'km' => $n > 0 ? round(array_sum(array_column($values, 'km')) / $n, 2) : 0.0,
                'tone' => $n > 0 ? round(array_sum(array_column($values, 'tone')) / $n, 2) : 0.0,
                'curse' => $n > 0 ? round(array_sum(array_column($values, 'curse')) / $n, 2) : 0.0,
                'km_per_cursa' => $n > 0 ? round(array_sum(array_column($values, 'km_per_cursa')) / $n, 2) : 0.0,
                'tone_per_cursa' => $n > 0 ? round(array_sum(array_column($values, 'tone_per_cursa')) / $n, 2) : 0.0,
            ];
        }

        return [
            'transport' => array_values($buckets),
            'clients' => $clients,
            'media_client' => $mediaClient,
            'media_client_per_transport' => $bucketClientAverages,
            'bucket_labels' => self::TRANSPORT_BUCKETS,
        ];
    }

    private function buildAlerts(array $vehicles, array $drivers, array $beneficiaries, array $fleet, array $filters = []): array
    {
        $alerts = [];

        foreach ($vehicles as $vehicle) {
            if ($vehicle['profit'] < 0) {
                $alerts[] = $this->alert('danger', 'vehicul', $vehicle['nume'], 'Profit negativ pe perioada selectata', $vehicle['profit'], 'lei');
            }
            if ($vehicle['profit_km'] <= 0 && $vehicle['km_totali'] > 0) {
                $alerts[] = $this->alert('warning', 'vehicul', $vehicle['nume'], 'Profit/km sub sau egal cu 0', $vehicle['profit_km'], 'lei/km');
            }
            if ($vehicle['km_nefacturati_percent'] > 20) {
                $alerts[] = $this->alert('warning', 'vehicul', $vehicle['nume'], 'Km nefacturati peste pragul de 20%', $vehicle['km_nefacturati_percent'], '%');
            }
            // Nu semnalam gradul de incarcare cand nicio cursa nu are capacitate configurata:
            // in V1 astfel de vehicule apareau mereu cu 0% si generau alerte false.
            if ($vehicle['curse_cu_capacitate'] > 0 && $vehicle['grad_incarcare_efectiv'] < 50) {
                $alerts[] = $this->alert('warning', 'vehicul', $vehicle['nume'], 'Grad de incarcare mediu sub 50%', $vehicle['grad_incarcare_efectiv'], '%');
            }
            if ($vehicle['curse_cu_capacitate'] === 0 && $vehicle['curse'] > 0) {
                $alerts[] = $this->alert('info', 'vehicul', $vehicle['nume'], 'Fara capacitate de transport configurata pe curse - gradul de incarcare nu poate fi calculat', $vehicle['curse'], 'curse');
            }
            // Pragul este relativ la flota: pe perioade lungi toate vehiculele coboara sub 40%,
            // asa ca semnalam doar vehiculele ramase clar in urma fata de restul flotei.
            $pragFolosinta = min(40.0, (float) $fleet['grad_folosinta'] * 0.6);
            if ($vehicle['zile_disponibile'] > 0 && $pragFolosinta > 0 && $vehicle['grad_folosinta'] < $pragFolosinta) {
                $alerts[] = $this->alert(
                    'info',
                    'vehicul',
                    $vehicle['nume'],
                    'Grad de folosinta mult sub media flotei (' . number_format((float) $fleet['grad_folosinta'], 2, '.', '') . '%)',
                    $vehicle['grad_folosinta'],
                    '%'
                );
            }
        }

        foreach ($drivers as $driver) {
            if ($driver['profit'] < 0) {
                $alerts[] = $this->alert('danger', 'sofer', $driver['nume'], 'Profit generat negativ', $driver['profit'], 'lei');
            }
            if ($driver['curse_cu_capacitate'] > 0 && $driver['grad_incarcare_efectiv'] < 50) {
                $alerts[] = $this->alert('warning', 'sofer', $driver['nume'], 'Grad de incarcare mediu sub 50%', $driver['grad_incarcare_efectiv'], '%');
            }
        }

        foreach ($beneficiaries as $client) {
            if ($client['profit'] < 0) {
                $alerts[] = $this->alert('danger', 'beneficiar', $client['nume'], 'Client pe pierdere in perioada selectata', $client['profit'], 'lei');
            }
            if ($client['marja_percent'] > 0 && $client['marja_percent'] < 10) {
                $alerts[] = $this->alert('warning', 'beneficiar', $client['nume'], 'Marja sub 10%', $client['marja_percent'], '%');
            }
        }

        if (($fleet['grad_folosinta'] ?? 0) > 0 && $fleet['grad_folosinta'] < 50) {
            $alerts[] = $this->alert('info', 'flota', 'Flota', 'Grad de folosinta al flotei sub 50%', $fleet['grad_folosinta'], '%');
        }

        return $alerts;
    }

    private function alert(string $severity, string $type, string $target, string $message, float $value, string $unit): array
    {
        return [
            'severity' => $severity,
            'type' => $type,
            'target' => $target,
            'message' => $message,
            'value' => round($value, 2),
            'unit' => $unit,
        ];
    }

    // --------------------------------------------------------- grad de folosinta

    /**
     * Zile active distincte pe perioada selectata, pentru flota / vehicul / sofer.
     *
     * Fata de V1 (care raporta intotdeauna la luna calendaristica a datei de start),
     * aici numaratorul si numitorul folosesc EXACT perioada filtrata, deci o filtrare
     * pe jumatate de luna nu mai injumatateste artificial gradul de folosinta.
     */
    private function calculateUsage(array $filters, array $period): array
    {
        $whereData = $this->buildWhere($filters);

        $rows = $this->fetchAll("
            SELECT
                c.id,
                c.vehicle_id,
                c.driver_id,
                COALESCE(c.data_inceput, c.data_cursa) AS interval_start,
                COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) AS interval_end
            " . $this->fromSql() . "
            {$whereData['where']}
        ", $whereData['params']);

        // Zilele active urmeaza segmentele: intr-o cursa oprita si reluata, fiecare
        // sofer si fiecare masina sunt active doar in zilele segmentului lor.
        $segmentIntervals = $this->raceSegmentIntervals($rows);

        $vehicleDays = [];
        $driverDays = [];
        $fleetDays = [];
        $activeVehicles = [];

        foreach ($rows as $row) {
            $start = $this->toDate((string) ($row['interval_start'] ?? ''));
            $end = $this->toDate((string) ($row['interval_end'] ?? ''));
            if ($start === null || $end === null) {
                continue;
            }

            if ($end < $start) {
                [$start, $end] = [$end, $start];
            }
            if ($start < $period['start']) {
                $start = $period['start'];
            }
            if ($end > $period['end']) {
                $end = $period['end'];
            }
            if ($end < $start) {
                continue;
            }

            $vehicleId = (int) ($row['vehicle_id'] ?? 0);
            $driverId = (int) ($row['driver_id'] ?? 0);
            $raceSegments = $segmentIntervals[(int) ($row['id'] ?? 0)] ?? [];

            if ($raceSegments === []) {
                if ($vehicleId > 0) {
                    $activeVehicles[$vehicleId] = $vehicleId;
                }

                for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
                    $day = $cursor->format('Y-m-d');
                    if ($vehicleId > 0) {
                        $vehicleDays[$vehicleId][$day] = true;
                        $fleetDays[$vehicleId . '|' . $day] = true;
                    }
                    if ($driverId > 0) {
                        $driverDays[$driverId][$day] = true;
                    }
                }

                continue;
            }

            foreach ($raceSegments as $segment) {
                $segmentStart = $this->toDate($segment['start']);
                $segmentEnd = $this->toDate($segment['end']);
                if ($segmentStart === null || $segmentEnd === null) {
                    continue;
                }
                if ($segmentEnd < $segmentStart) {
                    [$segmentStart, $segmentEnd] = [$segmentEnd, $segmentStart];
                }
                if ($segmentStart < $period['start']) {
                    $segmentStart = $period['start'];
                }
                if ($segmentEnd > $period['end']) {
                    $segmentEnd = $period['end'];
                }
                if ($segmentEnd < $segmentStart) {
                    continue;
                }

                if ($segment['vehicle_id'] > 0) {
                    $activeVehicles[$segment['vehicle_id']] = $segment['vehicle_id'];
                }

                for ($cursor = $segmentStart; $cursor <= $segmentEnd; $cursor = $cursor->modify('+1 day')) {
                    $day = $cursor->format('Y-m-d');
                    if ($segment['vehicle_id'] > 0) {
                        $vehicleDays[$segment['vehicle_id']][$day] = true;
                        $fleetDays[$segment['vehicle_id'] . '|' . $day] = true;
                    }
                    if ($segment['driver_id'] > 0) {
                        $driverDays[$segment['driver_id']][$day] = true;
                    }
                }
            }
        }

        return [
            'vehicles' => array_map('count', $vehicleDays),
            'drivers' => array_map('count', $driverDays),
            'zile_active_total' => count($fleetDays),
            'vehicule_active' => count($activeVehicles),
            'zile_lucratoare' => $this->countWeekdays($period['start'], $period['end']),
        ];
    }

    private function resolvePeriod(array $filters): array
    {
        $today = new DateTimeImmutable('today');

        $start = $this->toDate((string) ($filters['date_start'] ?? ''));
        $end = $this->toDate((string) ($filters['date_end'] ?? ''));

        if ($start === null && $end === null) {
            $start = $today->modify('first day of this month');
            $end = $today;
        } elseif ($start === null) {
            $start = $end->modify('first day of this month');
        } elseif ($end === null) {
            $end = $start->modify('last day of this month');
        }

        if ($end < $start) {
            [$start, $end] = [$end, $start];
        }

        return [
            'start' => $start,
            'end' => $end,
            'zile' => (int) $start->diff($end)->days + 1,
        ];
    }

    // ---------------------------------------------------------- vehicule usoare

    /**
     * Costurile vehiculelor usoare (autoturisme, autoutilitare) in perioada.
     *
     * Vehiculele usoare nu fac curse, deci nu apar in restul dashboard-ului, care
     * e construit pe curse. Aici se aduna costurile lor reale, direct din surse,
     * fara legatura cu cursele si fara filtrele de cursa (doar perioada):
     *  - carburant: fuel_fillups pe numar de inmatriculare normalizat, toate
     *    tipurile, total_value CU TVA (ca restul dashboard-ului);
     *  - cheltuieli: alocarile pe vehicul din modulul Cheltuieli (suma alocata);
     *  - mentenanta: revizii + reparatii, cu aceleasi excluderi ca modelul de cost/km.
     * Registrul de piese OCR NU se aduna: e un registru paralel cu mentenanta.
     * Sunt incluse si vehiculele inactive - pot avea costuri in perioada.
     */
    private function getLightVehicleCosts(array $period): array
    {
        $empty = ['rows' => [], 'totals' => [], 'error' => null];

        try {
            $vehicles = $this->fetchAll(
                "SELECT id, nr_inmatriculare, marca, model, tip_vehicul, status, capacitate_rezervor
                   FROM vehicule
                  WHERE tip_vehicul IN ('autovehicul', 'autoturism', 'autoutilitara')
                  ORDER BY nr_inmatriculare",
                []
            );
            if ($vehicles === []) {
                return $empty;
            }

            $start = $period['start']->format('Y-m-d');
            $end = $period['end']->format('Y-m-d');

            $rows = [];
            $byPlate = [];
            foreach ($vehicles as $vehicle) {
                $id = (int) $vehicle['id'];
                $plate = (string) $vehicle['nr_inmatriculare'];
                $rows[$id] = [
                    'vehicle_id' => $id,
                    'vehicul' => $plate,
                    'marca_model' => trim((string) ($vehicle['marca'] ?? '') . ' ' . (string) ($vehicle['model'] ?? '')),
                    'tip_vehicul' => (string) $vehicle['tip_vehicul'],
                    'status' => (string) ($vehicle['status'] ?? ''),
                    'carburant' => 0.0,
                    'litri' => 0.0,
                    'alimentari' => 0,
                    'alimentari_peste_rezervor' => 0,
                    'cheltuieli' => 0.0,
                    'cheltuieli_nr' => 0,
                    'mentenanta' => 0.0,
                    'mentenanta_nr' => 0,
                    'total' => 0.0,
                ];
                $byPlate[strtoupper(str_replace(' ', '', $plate))] = [
                    'id' => $id,
                    // o alimentare peste capacitatea rezervorului e aproape sigur pe alt vehicul
                    'rezervor' => (float) ($vehicle['capacitate_rezervor'] ?? 0),
                ];
            }

            if ($this->tableExists('fuel_fillups')) {
                $fuelRows = $this->fetchAll(
                    "SELECT REPLACE(UPPER(f.vehicle_registration), ' ', '') AS reg_key,
                            f.quantity_liters, f.total_value
                       FROM fuel_fillups f
                      WHERE f.source_type NOT IN ('test', 'demo')
                        AND f.quantity_liters > 0
                        AND f.fillup_datetime >= :light_fuel_start
                        AND f.fillup_datetime <= :light_fuel_end",
                    [':light_fuel_start' => $start . ' 00:00:00', ':light_fuel_end' => $end . ' 23:59:59']
                );
                foreach ($fuelRows as $fuel) {
                    $match = $byPlate[(string) $fuel['reg_key']] ?? null;
                    if ($match === null) {
                        continue;
                    }
                    $row = &$rows[$match['id']];
                    $liters = (float) $fuel['quantity_liters'];
                    $row['carburant'] += (float) $fuel['total_value'];
                    $row['litri'] += $liters;
                    $row['alimentari']++;
                    if ($match['rezervor'] > 0 && $liters > $match['rezervor']) {
                        $row['alimentari_peste_rezervor']++;
                    }
                    unset($row);
                }
            }

            if ($this->tableExists('cheltuieli_alocari')) {
                $expenseRows = $this->fetchAll(
                    "SELECT a.vehicul_id, COUNT(DISTINCT a.cheltuiala_id) AS nr, COALESCE(SUM(a.suma), 0) AS total
                       FROM cheltuieli_alocari a
                       INNER JOIN cheltuieli ch ON ch.id = a.cheltuiala_id
                      WHERE a.tip_alocare = 'vehicul'
                        AND ch.data_cheltuiala BETWEEN :light_exp_start AND :light_exp_end
                      GROUP BY a.vehicul_id",
                    [':light_exp_start' => $start, ':light_exp_end' => $end]
                );
                foreach ($expenseRows as $expense) {
                    $id = (int) $expense['vehicul_id'];
                    if (isset($rows[$id])) {
                        $rows[$id]['cheltuieli'] = (float) $expense['total'];
                        $rows[$id]['cheltuieli_nr'] = (int) $expense['nr'];
                    }
                }
            }

            if ($this->tableExists('mentenanta')) {
                $maintenanceRows = $this->fetchAll(
                    "SELECT m.vehicle_id, COUNT(*) AS nr, COALESCE(SUM(m.cost), 0) AS total
                       FROM mentenanta m
                      WHERE m.tip_interventie NOT LIKE 'Anvelopa - %'
                        AND (m.status_interventie IS NULL OR m.status_interventie <> 'anulata')
                        AND m.data_interventie BETWEEN :light_mnt_start AND :light_mnt_end
                      GROUP BY m.vehicle_id",
                    [':light_mnt_start' => $start, ':light_mnt_end' => $end]
                );
                foreach ($maintenanceRows as $maintenance) {
                    $id = (int) $maintenance['vehicle_id'];
                    if (isset($rows[$id])) {
                        $rows[$id]['mentenanta'] = (float) $maintenance['total'];
                        $rows[$id]['mentenanta_nr'] = (int) $maintenance['nr'];
                    }
                }
            }

            $totals = ['carburant' => 0.0, 'litri' => 0.0, 'alimentari' => 0, 'cheltuieli' => 0.0, 'mentenanta' => 0.0, 'total' => 0.0, 'vehicule' => count($rows), 'vehicule_cu_cost' => 0];
            foreach ($rows as &$row) {
                $row['total'] = $row['carburant'] + $row['cheltuieli'] + $row['mentenanta'];
                foreach (['carburant', 'litri', 'cheltuieli', 'mentenanta', 'total'] as $key) {
                    $row[$key] = round($row[$key], 2);
                    $totals[$key] += $row[$key];
                }
                $totals['alimentari'] += $row['alimentari'];
                if ($row['total'] > 0) {
                    $totals['vehicule_cu_cost']++;
                }
            }
            unset($row);

            $rows = array_values($rows);
            usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcmp($a['vehicul'], $b['vehicul']));

            return ['rows' => $rows, 'totals' => array_map(static fn ($v) => is_float($v) ? round($v, 2) : $v, $totals), 'error' => null];
        } catch (Throwable $exception) {
            // Tabul de vehicule usoare nu trebuie sa blocheze restul dashboard-ului.
            error_log('[DashboardAnaliticV2Model][light_vehicles] ' . $exception->getMessage());

            return ['rows' => [], 'totals' => [], 'error' => 'Nu s-au putut calcula costurile vehiculelor ușoare.'];
        }
    }

    private function countWeekdays(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        if ($end < $start) {
            return 0;
        }

        $count = 0;
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            if ((int) $cursor->format('N') <= 5) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Intervalele segmentelor pentru cursele date, grupate pe cursa. Cursele fara
     * segmente nu apar, deci ele isi pastreaza calculul pe soferul si vehiculul cursei.
     *
     * @param array<int,array<string,mixed>> $raceRows
     * @return array<int, array<int, array{driver_id:int, vehicle_id:int, start:string, end:string}>>
     */
    private function raceSegmentIntervals(array $raceRows): array
    {
        if (!$this->segmentsAvailable() || $raceRows === []) {
            return [];
        }

        $ids = [];
        foreach ($raceRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $idList = array_values($ids);
        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $stmt = $this->db->prepare("
            SELECT cursa_id, driver_id, vehicle_id, data_inceput, data_sfarsit
              FROM curse_segmente
             WHERE cursa_id IN ($placeholders)
        ");
        $stmt->execute($idList);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $start = trim((string) ($row['data_inceput'] ?? ''));
            $end = trim((string) ($row['data_sfarsit'] ?? ''));
            if ($start === '') {
                continue;
            }

            $grouped[(int) $row['cursa_id']][] = [
                'driver_id' => (int) ($row['driver_id'] ?? 0),
                'vehicle_id' => (int) ($row['vehicle_id'] ?? 0),
                'start' => $start,
                // Segmentul inca neinchis acopera cel putin ziua in care a inceput.
                'end' => $end !== '' ? $end : $start,
            ];
        }

        return $grouped;
    }

    private function toDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // "!" fixeaza ora la 00:00:00. Fara el, createFromFormat completeaza ora
        // curenta, iar numaratoarea zilelor active depindea de secunda in care era
        // citita fiecare data: ultima zi a unui interval intra sau nu, dupa noroc.
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value) ? $date : null;
    }

    // ------------------------------------------------------------------- infra

    /**
     * Segmentele de cursa (cursa oprita si reluata, cu alt sofer / alt vehicul)
     * exista doar dupa migrarea 2026_09_18_000002. Pana atunci, vederile pe sofer
     * lucreaza ca inainte, cu soferul cursei.
     */
    private function segmentsAvailable(): bool
    {
        if (self::$segmentsAvailable !== null) {
            return self::$segmentsAvailable;
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curse_segmente'"
            );
            $stmt->execute();
            self::$segmentsAvailable = ((int) $stmt->fetchColumn()) > 0;
        } catch (Throwable $exception) {
            self::$segmentsAvailable = false;
        }

        return self::$segmentsAvailable;
    }

    /** Modulele optionale (Carburanti, Cheltuieli, Mentenanta) pot lipsi pe unele instalari. */
    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name"
        );
        $stmt->execute([':table_name' => $table]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * FROM-ul vederilor pe sofer: cursa se desface pe segmentele ei, ca fiecare
     * sofer sa primeasca partea lui din km, tone si bani (proportional cu km-ii
     * segmentului sau). O cursa fara segmente da exact un rand, ca inainte.
     *
     * $driverParam restrange segmentele la un singur sofer (profilul soferului).
     */
    private function legsFromSql(?string $driverParam = null, ?string $vehicleParam = null): string
    {
        if (!$this->segmentsAvailable()) {
            return $this->fromSql();
        }

        $conditions = ['sg.cursa_id = sgt.cursa_id'];
        if ($driverParam !== null) {
            $conditions[] = 'sg.driver_id = ' . $driverParam;
        }
        if ($vehicleParam !== null) {
            $conditions[] = 'sg.vehicle_id = ' . $vehicleParam;
        }
        $segmentCondition = implode(' AND ', $conditions);

        return "
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN (
                SELECT cursa_id, SUM(COALESCE(km, 0)) AS weight_total
                  FROM curse_segmente
                 GROUP BY cursa_id
                HAVING COUNT(*) >= 2 AND SUM(COALESCE(km, 0)) > 0
            ) sgt ON sgt.cursa_id = c.id
            LEFT JOIN curse_segmente sg ON {$segmentCondition}
            LEFT JOIN soferi s ON s.id = COALESCE(sg.driver_id, c.driver_id)
            LEFT JOIN vehicule lv ON lv.id = COALESCE(sg.vehicle_id, c.vehicle_id)
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
        " . $this->expenseJoinSql();
    }

    /** Partea de cursa care ii revine randului curent (1 = toata cursa). */
    private function legShareExpr(): string
    {
        if (!$this->segmentsAvailable()) {
            return '1';
        }

        return 'COALESCE(COALESCE(sg.km, 0) / sgt.weight_total, 1)';
    }

    /** Soferul randului: cel al segmentului, altfel cel al cursei. */
    private function legDriverExpr(): string
    {
        return $this->segmentsAvailable() ? 'COALESCE(sg.driver_id, c.driver_id)' : 'c.driver_id';
    }

    /** Vehiculul randului: cel al segmentului, altfel cel al cursei. */
    private function legVehicleIdExpr(): string
    {
        return $this->segmentsAvailable() ? 'COALESCE(sg.vehicle_id, c.vehicle_id)' : 'c.vehicle_id';
    }

    private function legVehicleNameExpr(): string
    {
        return $this->segmentsAvailable()
            ? "COALESCE(NULLIF(TRIM(lv.nr_inmatriculare), ''), NULLIF(TRIM(v.nr_inmatriculare), ''), 'Necunoscut')"
            : $this->entityNameExpr('vehicul');
    }

    private function fromSql(): string
    {
        return "
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
        " . $this->expenseJoinSql();
    }

    /** Cheltuielile cursei, agregate o data si refolosite de toate FROM-urile. */
    private function expenseJoinSql(): string
    {
        return "
            LEFT JOIN (
                SELECT
                    cursa_id,
                    /*
                     * Banii scosi efectiv de firma pe fiecare linie de cheltuiala.
                     * O linie poate fi introdusa in doua feluri:
                     *   - cost propriu in `suma` (cu sau fara bifa de refacturare);
                     *   - doar refacturare, cu `suma` = 0 - atunci costul este in
                     *     `refacturare_suma`, altfel banii aceia nu ar aparea nicaieri.
                     * Nu se aduna cele doua coloane: pe randurile unde `suma` este
                     * completata, refacturarea priveste acelasi cost.
                     */
                    SUM(CASE WHEN COALESCE(suma, 0) > 0 THEN suma ELSE COALESCE(refacturare_suma, 0) END) AS total_cheltuieli,
                    SUM(CASE WHEN COALESCE(suma, 0) > 0 THEN suma ELSE 0 END) AS total_cheltuieli_proprii,
                    SUM(COALESCE(refacturare_suma, 0)) AS total_refacturare,
                    SUM(CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN COALESCE(refacturare_suma, 0) ELSE 0 END) AS total_refacturare_facturata,
                    SUM(CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN 0 ELSE COALESCE(refacturare_suma, 0) END) AS total_refacturare_pending,
                    /* partea din refacturarile nefacturate care NU are cost propriu: ea mareste totalul */
                    SUM(CASE
                            WHEN COALESCE(refacturare_facturata, 0) = 0 AND COALESCE(suma, 0) = 0
                            THEN COALESCE(refacturare_suma, 0) ELSE 0
                        END) AS total_refacturare_fara_cost
                FROM curse_cheltuieli
                GROUP BY cursa_id
            ) exp ON exp.cursa_id = c.id
        ";
    }

    /**
     * Data la care cursa este raportata in dashboard: data la care s-a INCHIS.
     *
     * O cursa inceputa pe 31 iulie si incheiata pe 2 august apartine lunii august,
     * indiferent de tipul de transport. Fallback pe data de inceput, apoi pe data
     * cursei, ca sa nu dispara cursele inca neinchise.
     */
    private function reportingDateExpr(): string
    {
        return 'COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa)';
    }

    private function buildWhere(array $filters): array
    {
        $where = ['c.deleted_at IS NULL'];
        $params = [];
        $ziRaportare = $this->reportingDateExpr();

        if (($filters['date_start'] ?? null) !== null && $filters['date_start'] !== '') {
            $where[] = $ziRaportare . ' >= :dash_date_start';
            $params[':dash_date_start'] = (string) $filters['date_start'];
        }

        if (($filters['date_end'] ?? null) !== null && $filters['date_end'] !== '') {
            $where[] = $ziRaportare . ' <= :dash_date_end';
            $params[':dash_date_end'] = (string) $filters['date_end'];
        }

        $this->appendLegFilter($where, $params, 'c.vehicle_id', 'vehicle_id', (array) ($filters['vehicle_ids'] ?? []), 'dash_vehicle');
        $this->appendLegFilter($where, $params, 'c.driver_id', 'driver_id', (array) ($filters['driver_ids'] ?? []), 'dash_driver');
        $this->appendIntFilter($where, $params, 'c.beneficiar_id', (array) ($filters['beneficiary_ids'] ?? []), 'dash_beneficiary');
        $this->appendStringFilter($where, $params, 'c.tip_transport', (array) ($filters['transport_types'] ?? []), 'dash_transport');
        $this->appendDecimalFilter($where, $params, 'c.capacitate_transport', (array) ($filters['transport_capacities'] ?? []), 'dash_capacity');
        $this->appendCapacityCategoryFilter($where, $params, (array) ($filters['capacity_categories'] ?? []), 'dash_capacity_category');
        $this->appendStringFilter($where, $params, 'c.status_facturare', (array) ($filters['statuses'] ?? []), 'dash_status');

        return [
            'where' => ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    /**
     * Filtru pe CATEGORIE de capacitate a vehiculului cursei.
     *
     * Este un filtru de apartenenta la grup. Gradul de umplere continua sa se
     * calculeze din capacitatea reala salvata pe cursa.
     */
    private function appendCapacityCategoryFilter(array &$where, array &$params, array $values, string $prefix): void
    {
        $normalized = [];
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        if ($normalized === []) {
            return;
        }

        $placeholders = [];
        $index = 0;
        foreach (array_values($normalized) as $id) {
            $placeholder = ':' . $prefix . '_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
            $index++;
        }

        $where[] = "
            EXISTS (
                SELECT 1
                FROM vehicule fv
                LEFT JOIN (
                    SELECT vc1.tractor_id, vc1.semiremorca_id
                    FROM vehicule_cuplaje vc1
                    INNER JOIN (
                        SELECT tractor_id, MAX(id) AS max_id
                        FROM vehicule_cuplaje
                        WHERE activ = 1
                        GROUP BY tractor_id
                    ) latest ON latest.max_id = vc1.id
                ) fvc ON fvc.tractor_id = fv.id
                LEFT JOIN vehicule fs ON fs.id = fvc.semiremorca_id
                WHERE fv.id = c.vehicle_id
                  AND CASE
                        WHEN fv.tip_vehicul = 'cap_tractor' THEN COALESCE(fs.categorie_capacitate_id, fv.categorie_capacitate_id)
                        ELSE fv.categorie_capacitate_id
                      END IN (" . implode(', ', $placeholders) . ")
            )
        ";
    }

    private function appendIntFilter(array &$where, array &$params, string $column, array $values, string $prefix): void
    {
        $clean = [];
        foreach ($values as $value) {
            if (is_numeric((string) $value) && (int) $value > 0) {
                $clean[(int) $value] = (int) $value;
            }
        }

        $this->appendInClause($where, $params, $column, $clean, $prefix);
    }

    /**
     * Filtrul pe sofer / vehicul prinde si cursele in care soferul sau masina au
     * facut doar un segment (cursa reluata din pauza). Altfel, alegand al doilea
     * sofer sau a doua masina a unei curse, pagina ar ramane goala desi chiar au
     * facut-o.
     */
    private function appendLegFilter(
        array &$where,
        array &$params,
        string $raceColumn,
        string $segmentColumn,
        array $values,
        string $prefix
    ): void {
        $clean = [];
        foreach ($values as $value) {
            if (is_numeric((string) $value) && (int) $value > 0) {
                $clean[(int) $value] = (int) $value;
            }
        }
        if ($clean === []) {
            return;
        }

        if (!$this->segmentsAvailable()) {
            $this->appendInClause($where, $params, $raceColumn, $clean, $prefix);

            return;
        }

        // Fiecare placeholder apare o singura data: PDO ruleaza cu EMULATE_PREPARES=false.
        $raceKeys = [];
        $segmentKeys = [];
        $index = 0;
        foreach ($clean as $value) {
            $raceKey = ':' . $prefix . '_' . $index;
            $segmentKey = ':' . $prefix . '_seg_' . $index;
            $index++;
            $raceKeys[] = $raceKey;
            $segmentKeys[] = $segmentKey;
            $params[$raceKey] = $value;
            $params[$segmentKey] = $value;
        }

        $where[] = '(' . $raceColumn . ' IN (' . implode(', ', $raceKeys) . ')'
            . ' OR EXISTS (SELECT 1 FROM curse_segmente sgf'
            . ' WHERE sgf.cursa_id = c.id AND sgf.' . $segmentColumn . ' IN (' . implode(', ', $segmentKeys) . ')))';
    }

    private function appendStringFilter(array &$where, array &$params, string $column, array $values, string $prefix): void
    {
        $clean = [];
        foreach ($values as $value) {
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                $clean[$normalized] = $normalized;
            }
        }

        $this->appendInClause($where, $params, $column, $clean, $prefix);
    }

    private function appendDecimalFilter(array &$where, array &$params, string $column, array $values, string $prefix): void
    {
        $clean = [];
        foreach ($values as $value) {
            $normalized = str_replace(',', '.', trim((string) $value));
            if ($normalized === '' || !is_numeric($normalized) || (float) $normalized <= 0) {
                continue;
            }

            $key = number_format((float) $normalized, 2, '.', '');
            $clean[$key] = $key;
        }

        $this->appendInClause($where, $params, $column, $clean, $prefix);
    }

    private function appendInClause(array &$where, array &$params, string $column, array $values, string $prefix): void
    {
        if ($values === []) {
            return;
        }

        $placeholders = [];
        $index = 0;
        foreach ($values as $value) {
            $key = ':' . $prefix . '_' . $index++;
            $placeholders[] = $key;
            $params[$key] = $value;
        }

        $where[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    private function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    private function fetchOne(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->execute();

        return $stmt->fetch() ?: [];
    }

    private function bind(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            if ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
                continue;
            }

            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
    }
}
