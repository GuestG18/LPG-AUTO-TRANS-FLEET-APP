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

        $vehicles = $this->db->query("
            SELECT DISTINCT c.vehicle_id AS id, v.nr_inmatriculare
            {$from}
            WHERE c.vehicle_id IS NOT NULL AND c.deleted_at IS NULL
            ORDER BY v.nr_inmatriculare ASC
        ")->fetchAll();

        $drivers = $this->db->query("
            SELECT DISTINCT c.driver_id AS id,
                   COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer') AS nume
            {$from}
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

        return [
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'beneficiaries' => $beneficiaries,
            'transport_types' => $transportTypes,
            'transport_capacities' => $capacities,
            'statuses' => $statuses,
            'transport_type_labels' => self::TRANSPORT_TYPE_LABELS,
            'status_labels' => self::STATUS_LABELS,
        ];
    }

    // ------------------------------------------------------------------- date

    public function getData(array $filters): array
    {
        $from = $this->fromSql();
        $whereData = $this->buildWhere($filters);
        $expr = $this->metricExpressions();

        $period = $this->resolvePeriod($filters);
        $usage = $this->calculateUsage($filters, $period);

        $fleetRow = $this->fetchOne($this->fleetSql($from, $whereData['where'], $expr), $whereData['params']);
        $daily = $this->fetchDailySeries($from, $whereData, $expr);
        $vehicles = $this->fetchVehicles($from, $whereData, $expr, $usage['vehicles'], $usage['zile_lucratoare']);
        $drivers = $this->fetchDrivers($from, $whereData, $expr, $usage['drivers'], $usage['zile_lucratoare']);
        $beneficiaries = $this->fetchBeneficiaries($from, $whereData, $expr);
        $matrixRows = $this->fetchClientTransportMatrix($from, $whereData, $expr);
        $transportRows = $this->fetchTransportTotals($from, $whereData, $expr);

        $fleet = $this->buildFleetKpis($fleetRow, $usage, $beneficiaries);
        $summary = $this->buildSummary($transportRows, $matrixRows);
        $alerts = $this->buildAlerts($vehicles, $drivers, $beneficiaries, $fleet);

        return [
            'fleet' => $fleet,
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'beneficiaries' => $beneficiaries,
            'summary' => $summary,
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
            'drivers' => [],
            'beneficiaries' => [],
            'summary' => $this->buildSummary([], []),
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
        $expr = $this->metricExpressions();
        $whereData = $this->buildWhere($filters);

        // restrangem la entitatea ceruta; id 0 inseamna "fara sofer" / "fara beneficiar"
        $column = self::ENTITY_COLUMNS[$type];
        if ($id > 0) {
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

        $row = $this->fetchOne("
            SELECT
                " . $this->aggregateColumns($expr) . ",
                MIN(" . $this->entityNameExpr($type) . ") AS nume,
                COUNT(DISTINCT c.vehicle_id) AS nr_vehicule,
                COUNT(DISTINCT c.driver_id) AS nr_soferi,
                COUNT(DISTINCT c.beneficiar_id) AS nr_beneficiari,
                MIN(c.data_inceput) AS prima_cursa,
                MAX(c.data_inceput) AS ultima_cursa
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
            'by_transport' => $this->groupedRows($expr['bucket'], $from, $whereData, $expr, self::TRANSPORT_BUCKETS),
            'by_partner' => $this->partnerBreakdowns($type, $from, $whereData, $expr),
            'daily' => $this->fetchDailySeries($from, $whereData, $expr),
            'trips' => $this->fetchTrips($from, $whereData, $expr),
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

    private function entityNameExpr(string $type): string
    {
        if ($type === 'vehicul') {
            return "COALESCE(NULLIF(TRIM(v.nr_inmatriculare), ''), 'Necunoscut')";
        }
        if ($type === 'sofer') {
            return "COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer')";
        }

        return "COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar')";
    }

    /** Defalcarile pe parteneri difera in functie de entitatea deschisa. */
    private function partnerBreakdowns(string $type, string $from, array $whereData, array $expr): array
    {
        $vehicule = ['key' => 'vehicule', 'label' => 'Vehicule', 'rows' => $this->groupedRows($this->entityNameExpr('vehicul'), $from, $whereData, $expr)];
        $soferi = ['key' => 'soferi', 'label' => 'Soferi', 'rows' => $this->groupedRows($this->entityNameExpr('sofer'), $from, $whereData, $expr)];
        $beneficiari = ['key' => 'beneficiari', 'label' => 'Beneficiari', 'rows' => $this->groupedRows($this->entityNameExpr('beneficiar'), $from, $whereData, $expr)];

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
    private function groupedRows(string $groupExpr, string $from, array $whereData, array $expr, array $labels = []): array
    {
        $rows = $this->fetchAll("
            SELECT
                " . $groupExpr . " AS grup,
                " . $this->aggregateColumns($expr) . "
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
    private function fetchTrips(string $from, array $whereData, array $expr): array
    {
        $rows = $this->fetchAll("
            SELECT
                c.id,
                c.data_inceput,
                c.data_sfarsit,
                c.tip_transport,
                c.status_facturare,
                c.capacitate_transport,
                COALESCE(c.nr_clienti, 0) AS nr_clienti,
                COALESCE(NULLIF(TRIM(v.nr_inmatriculare), ''), 'Necunoscut') AS vehicul,
                COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer') AS sofer,
                COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar') AS beneficiar,
                COALESCE(NULLIF(TRIM(c.loc_plecare), ''), '') AS loc_plecare,
                COALESCE(NULLIF(TRIM(c.loc_livrare), ''), NULLIF(TRIM(c.loc_livrare_cursa), ''), '') AS loc_livrare,
                (" . $expr['km_effective'] . ") AS km,
                (" . $expr['km_billed'] . ") AS km_facturati,
                (" . $expr['km_unbilled'] . ") AS km_nefacturati,
                (" . $expr['tons_delivered'] . ") AS tone,
                (" . $expr['facturare'] . ") AS facturare,
                (" . $expr['refacturare'] . ") AS refacturare,
                (" . $expr['cheltuieli'] . ") AS cheltuieli,
                (" . $expr['grad_incarcare_efectiv'] . ") AS grad_incarcare
            {$from}
            {$whereData['where']}
            ORDER BY c.data_inceput DESC, c.id DESC
            LIMIT 500
        ", $whereData['params']);

        $trips = [];
        foreach ($rows as $row) {
            $facturare = (float) ($row['facturare'] ?? 0);
            $cheltuieli = (float) ($row['cheltuieli'] ?? 0);

            $trips[] = [
                'id' => (int) ($row['id'] ?? 0),
                'data' => (string) ($row['data_inceput'] ?? ''),
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
                'profit' => round($facturare - $cheltuieli, 2),
                'grad_incarcare' => $row['grad_incarcare'] === null ? null : round((float) $row['grad_incarcare'], 2),
            ];
        }

        return $trips;
    }

    // --------------------------------------------------------- expresii metrice
    // Copiate 1:1 din DispecerCurseModel::getDashboardAnalyticData(), ca sa
    // garantam ca V2 raporteaza exact aceleasi valori ca pagina live.

    private function metricExpressions(): array
    {
        $kmEffective = "
            CASE
                WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa
                ELSE 0
            END
        ";
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
        $gradIncarcare = "
            CASE
                WHEN c.capacitate_transport IS NOT NULL AND c.capacitate_transport > 0
                THEN LEAST(100, GREATEST(0, ((" . $loadedTons . ") / c.capacitate_transport) * 100))
                ELSE 0
            END
        ";
        // Varianta corectata a gradului de incarcare:
        //   - cursele fara capacitate configurata sunt EXCLUSE din medie (NULL), nu numarate ca 0%;
        //   - cand nu exista cantitate incarcata (cazul curselor de compresor, unde se
        //     inregistreaza doar tona livrata), folosim tona livrata ca numarator.
        $gradIncarcareEfectiv = "
            CASE
                WHEN c.capacitate_transport IS NULL OR c.capacitate_transport <= 0 THEN NULL
                ELSE LEAST(100, GREATEST(0, (
                    COALESCE(NULLIF((" . $loadedTons . "), 0), (" . $deliveredTons . ")) / c.capacitate_transport
                ) * 100))
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
            'facturare' => "(COALESCE(c.total_facturare, 0) + COALESCE(exp.total_refacturare_facturata, 0))",
            'refacturare' => "COALESCE(exp.total_refacturare_pending, 0)",
            'cheltuieli' => "COALESCE(exp.total_cheltuieli, 0)",
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

    /** Blocul de agregari comun tuturor gruparilor (flota, vehicul, sofer, client). */
    private function aggregateColumns(array $e): string
    {
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
            COALESCE(SUM(" . $e['cheltuieli'] . "), 0) AS cheltuieli,
            COALESCE(SUM(" . $e['puncte_client'] . "), 0) AS puncte_client,
            COALESCE(AVG(" . $e['grad_incarcare'] . "), 0) AS grad_incarcare_mediu,
            SUM(CASE WHEN c.capacitate_transport IS NOT NULL AND c.capacitate_transport > 0 THEN 1 ELSE 0 END) AS curse_cu_capacitate,
            COALESCE(AVG(" . $e['grad_incarcare_efectiv'] . "), 0) AS grad_incarcare_efectiv
        ";
    }

    private function fleetSql(string $from, string $where, array $e): string
    {
        return "
            SELECT
                " . $this->aggregateColumns($e) . ",
                COALESCE(SUM(CASE WHEN c.tip_transport IN ('primar', 'primar_tona') THEN (" . $e['tons_delivered'] . ") ELSE 0 END), 0) AS tone_primar,
                COALESCE(SUM(CASE WHEN c.tip_transport IN ('distributie', 'primar_distributie') THEN (" . $e['tons_delivered'] . ") ELSE 0 END), 0) AS tone_distributie,
                COUNT(DISTINCT c.vehicle_id) AS nr_vehicule,
                COUNT(DISTINCT c.driver_id) AS nr_soferi,
                COUNT(DISTINCT c.beneficiar_id) AS nr_beneficiari
            {$from}
            {$where}
        ";
    }

    // -------------------------------------------------------------- interogari

    private function fetchDailySeries(string $from, array $whereData, array $e): array
    {
        $rows = $this->fetchAll("
            SELECT
                c.data_inceput AS zi,
                COALESCE(SUM(" . $e['facturare'] . "), 0) AS facturare,
                COALESCE(SUM(" . $e['refacturare'] . "), 0) AS refacturare,
                COALESCE(SUM(" . $e['cheltuieli'] . "), 0) AS cheltuieli,
                COALESCE(SUM(" . $e['km_effective'] . "), 0) AS km,
                COALESCE(SUM(" . $e['tons_delivered'] . "), 0) AS tone,
                COUNT(*) AS curse
            {$from}
            {$whereData['where']}
            GROUP BY c.data_inceput
            ORDER BY c.data_inceput ASC
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
        $rows = $this->fetchAll("
            SELECT
                c.vehicle_id,
                COALESCE(NULLIF(TRIM(v.nr_inmatriculare), ''), 'Necunoscut') AS nume,
                " . $this->aggregateColumns($e) . ",
                COUNT(DISTINCT c.driver_id) AS nr_soferi,
                COUNT(DISTINCT c.beneficiar_id) AS nr_beneficiari
            {$from}
            {$whereData['where']}
            GROUP BY c.vehicle_id, v.nr_inmatriculare
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
        $rows = $this->fetchAll("
            SELECT
                c.driver_id,
                COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer') AS nume,
                " . $this->aggregateColumns($e) . ",
                COUNT(DISTINCT c.vehicle_id) AS nr_vehicule,
                COUNT(DISTINCT c.beneficiar_id) AS nr_beneficiari
            {$from}
            {$whereData['where']}
            GROUP BY c.driver_id, COALESCE(NULLIF(TRIM(s.nume), ''), 'Fara sofer')
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
        $rows = $this->fetchAll("
            SELECT
                c.beneficiar_id,
                COALESCE(NULLIF(TRIM(bt.nume), ''), 'Fara beneficiar') AS nume,
                " . $this->aggregateColumns($e) . ",
                COUNT(DISTINCT c.vehicle_id) AS nr_vehicule,
                COUNT(DISTINCT c.driver_id) AS nr_soferi,
                COUNT(DISTINCT " . $e['bucket'] . ") AS nr_tipuri_transport
            {$from}
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
                COALESCE(AVG(" . $e['grad_incarcare'] . "), 0) AS grad_incarcare_mediu
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
                COALESCE(AVG(" . $e['grad_incarcare'] . "), 0) AS grad_incarcare_mediu,
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
        // Identic cu V1: raportarile pe km folosesc km facturati, cu fallback pe km totali.
        $kmBase = $kmBilled > 0 ? $kmBilled : $km;
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
            // Identic cu V1: media include si cursele fara capacitate configurata (numarate ca 0%).
            'grad_incarcare' => round((float) ($row['grad_incarcare_mediu'] ?? 0), 2),
            // Nou: aceeasi medie, dar doar peste cursele care chiar au capacitate configurata.
            'grad_incarcare_efectiv' => round((float) ($row['grad_incarcare_efectiv'] ?? 0), 2),
            'curse_cu_capacitate' => (int) ($row['curse_cu_capacitate'] ?? 0),
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

        $fleet['total_incasare'] = round($fleet['facturare'] + $fleet['refacturare'], 2);
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

    private function buildAlerts(array $vehicles, array $drivers, array $beneficiaries, array $fleet): array
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
                c.vehicle_id,
                c.driver_id,
                COALESCE(c.data_inceput, c.data_cursa) AS interval_start,
                COALESCE(c.data_sfarsit, c.data_inceput, c.data_cursa) AS interval_end
            " . $this->fromSql() . "
            {$whereData['where']}
        ", $whereData['params']);

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

    private function toDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value) ? $date : null;
    }

    // ------------------------------------------------------------------- infra

    private function fromSql(): string
    {
        return "
            FROM curse_dispecer c
            INNER JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN soferi s ON s.id = c.driver_id
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            LEFT JOIN (
                SELECT
                    cursa_id,
                    GREATEST(
                        0,
                        SUM(COALESCE(suma, 0)) - SUM(
                            CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN COALESCE(refacturare_suma, 0) ELSE 0 END
                        )
                    ) AS total_cheltuieli,
                    SUM(COALESCE(refacturare_suma, 0)) AS total_refacturare,
                    SUM(CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN COALESCE(refacturare_suma, 0) ELSE 0 END) AS total_refacturare_facturata,
                    SUM(CASE WHEN COALESCE(refacturare_facturata, 0) = 1 THEN 0 ELSE COALESCE(refacturare_suma, 0) END) AS total_refacturare_pending
                FROM curse_cheltuieli
                GROUP BY cursa_id
            ) exp ON exp.cursa_id = c.id
        ";
    }

    private function buildWhere(array $filters): array
    {
        $where = ['c.deleted_at IS NULL'];
        $params = [];

        if (($filters['date_start'] ?? null) !== null && $filters['date_start'] !== '') {
            $where[] = 'c.data_inceput >= :dash_date_start';
            $params[':dash_date_start'] = (string) $filters['date_start'];
        }

        if (($filters['date_end'] ?? null) !== null && $filters['date_end'] !== '') {
            $where[] = 'c.data_inceput <= :dash_date_end';
            $params[':dash_date_end'] = (string) $filters['date_end'];
        }

        $this->appendIntFilter($where, $params, 'c.vehicle_id', (array) ($filters['vehicle_ids'] ?? []), 'dash_vehicle');
        $this->appendIntFilter($where, $params, 'c.driver_id', (array) ($filters['driver_ids'] ?? []), 'dash_driver');
        $this->appendIntFilter($where, $params, 'c.beneficiar_id', (array) ($filters['beneficiary_ids'] ?? []), 'dash_beneficiary');
        $this->appendStringFilter($where, $params, 'c.tip_transport', (array) ($filters['transport_types'] ?? []), 'dash_transport');
        $this->appendDecimalFilter($where, $params, 'c.capacitate_transport', (array) ($filters['transport_capacities'] ?? []), 'dash_capacity');
        $this->appendStringFilter($where, $params, 'c.status_facturare', (array) ($filters['statuses'] ?? []), 'dash_status');

        return [
            'where' => ' WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
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
