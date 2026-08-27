<?php
declare(strict_types=1);

/**
 * Model pentru pagina "Cost operațional / km".
 *
 * Rol: STRATUL DE REZOLVARE A SURSELOR (Financial Source Resolver).
 * Citește exclusiv date deja existente în aplicație (fuel_fillups, mentenanta,
 * soferi, configurare_costuri_documente_*, inventar_dotari_*, office_expenses,
 * administrative_expenses, curse_dispecer, curse_cheltuieli, vehicle_authorizations,
 * anvelope + anvelope_alocari) plus tabelele proprii de CONFIGURARE
 * (cost_operational_settings, cost_operational_elemente).
 *
 * NU scrie niciodată în datele tranzacționale. Simularea nu trece pe aici.
 */
class OperationalCostModel extends BaseModel
{
    /** Tipurile de vehicule considerate operaționale (grele) în modelul de cost. */
    public const HEAVY_TYPES = ['cap_tractor', 'semiremorca', 'semiremorca_primar', 'semiremorca_distributie', 'camion'];

    private const DEFAULT_SETTINGS = [
        'eur_ron_rate'          => '5.00',
        'salariu_multiplicator' => '1.75',
        'tva_carburant_fallback'=> '21.00',
        'management_alocare'    => 'vehicule_active',
        'diurna_tarif_zi'       => '',
        'km_source'             => 'curse_reali',
    ];

    private static ?bool $schemaReady = null;

    // ------------------------------------------------------------------
    // Schema proprie (config only)
    // ------------------------------------------------------------------

    public function schemaReady(): bool
    {
        if (self::$schemaReady !== null) {
            return self::$schemaReady;
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN ("cost_operational_settings", "cost_operational_elemente")'
            );
            $stmt->execute();
            self::$schemaReady = ((int) $stmt->fetchColumn()) === 2;
        } catch (Throwable $e) {
            self::$schemaReady = false;
        }
        return self::$schemaReady;
    }

    // ------------------------------------------------------------------
    // Setări (parametri de calcul)
    // ------------------------------------------------------------------

    /** @return array<string,string> */
    public function getSettings(): array
    {
        $settings = self::DEFAULT_SETTINGS;
        if (!$this->schemaReady()) {
            return $settings;
        }
        foreach ($this->db->query('SELECT setting_key, setting_value FROM cost_operational_settings')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
        return $settings;
    }

    public function setSetting(string $key, string $value, ?int $userId = null): void
    {
        if (!$this->schemaReady()) {
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO cost_operational_settings (setting_key, setting_value, updated_by, updated_at)
             VALUES (:k, :v, :u, :t)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                     updated_by = VALUES(updated_by),
                                     updated_at = VALUES(updated_at)'
        );
        $stmt->execute(['k' => $key, 'v' => $value, 'u' => $userId, 't' => date('Y-m-d H:i:s')]);
    }

    // ------------------------------------------------------------------
    // Registrul elementelor financiare (configurare, nu date tranzacționale)
    // ------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public function getElements(bool $onlyActive = false): array
    {
        if (!$this->schemaReady()) {
            return [];
        }
        $sql = 'SELECT * FROM cost_operational_elemente';
        if ($onlyActive) {
            $sql .= ' WHERE activ = 1';
        }
        $sql .= ' ORDER BY tip ASC, ordine ASC, id ASC';
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getElementById(int $id): ?array
    {
        if (!$this->schemaReady()) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM cost_operational_elemente WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveElement(array $data, ?int $id = null): int
    {
        $now = date('Y-m-d H:i:s');
        $fields = [
            'cod' => (string) $data['cod'],
            'nume' => (string) $data['nume'],
            'tip' => in_array($data['tip'] ?? '', ['fix', 'variabil'], true) ? $data['tip'] : 'fix',
            'clasa_sursa' => in_array($data['clasa_sursa'] ?? '', ['auto', 'derived', 'config', 'missing'], true) ? $data['clasa_sursa'] : 'config',
            'sursa_referinta' => (string) ($data['sursa_referinta'] ?? 'manual'),
            'sursa_filtru' => ($data['sursa_filtru'] ?? null) !== null && $data['sursa_filtru'] !== '' ? (string) $data['sursa_filtru'] : null,
            'scop' => in_array($data['scop'] ?? '', ['company', 'vehicle_category', 'vehicle', 'driver', 'beneficiary'], true) ? $data['scop'] : 'vehicle',
            'periodicitate' => in_array($data['periodicitate'] ?? '', ['lunar', 'anual', 'per_eveniment', 'per_km', 'per_100000km', 'per_zi'], true) ? $data['periodicitate'] : 'anual',
            'alocare' => in_array($data['alocare'] ?? '', ['direct', 'by_km', 'by_vehicle_count', 'by_category', 'by_driver', 'by_beneficiary'], true) ? $data['alocare'] : 'direct',
            'valoare_config' => ($data['valoare_config'] ?? null) !== null && $data['valoare_config'] !== '' ? (float) $data['valoare_config'] : null,
            'valoare_moneda' => in_array($data['valoare_moneda'] ?? '', ['RON', 'EUR'], true) ? $data['valoare_moneda'] : 'RON',
            'amortizare_ani' => ($data['amortizare_ani'] ?? null) !== null && $data['amortizare_ani'] !== '' ? (float) $data['amortizare_ani'] : null,
            'regim_tva' => in_array($data['regim_tva'] ?? '', ['net', 'brut', 'necunoscut_net'], true) ? $data['regim_tva'] : 'net',
            'tipuri_vehicul' => ($data['tipuri_vehicul'] ?? null) !== null && $data['tipuri_vehicul'] !== '' ? (string) $data['tipuri_vehicul'] : null,
            'activ' => !empty($data['activ']) ? 1 : 0,
            'ordine' => (int) ($data['ordine'] ?? 0),
            'observatii' => ($data['observatii'] ?? null) !== null && $data['observatii'] !== '' ? (string) $data['observatii'] : null,
        ];

        if ($id !== null && $id > 0) {
            $set = [];
            foreach ($fields as $col => $val) {
                $set[] = "$col = :$col";
            }
            $fields['id'] = $id;
            $sql = 'UPDATE cost_operational_elemente SET ' . implode(', ', $set) . ', updated_at = "' . $now . '" WHERE id = :id';
            $this->db->prepare($sql)->execute($fields);
            return $id;
        }

        $cols = array_keys($fields);
        $sql = 'INSERT INTO cost_operational_elemente (' . implode(', ', $cols) . ', created_at, updated_at)
                VALUES (:' . implode(', :', $cols) . ', "' . $now . '", "' . $now . '")';
        $this->db->prepare($sql)->execute($fields);
        return (int) $this->db->lastInsertId();
    }

    public function toggleElement(int $id, bool $active): void
    {
        $stmt = $this->db->prepare('UPDATE cost_operational_elemente SET activ = :a, updated_at = :t WHERE id = :id');
        $stmt->execute(['a' => $active ? 1 : 0, 't' => date('Y-m-d H:i:s'), 'id' => $id]);
    }

    public function deleteElement(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM cost_operational_elemente WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // ------------------------------------------------------------------
    // Flota operațională + cuplaje + categorii
    // ------------------------------------------------------------------

    /**
     * Vehiculele grele — TOATE, inclusiv cele inactive: un vehicul inactiv poate
     * avea totuși curse/alimentări în perioada analizată (verificat pe datele
     * live), iar acele costuri și km sunt reale. Costurile "de stare" (documente,
     * salarii alocate, management) se aplică doar celor active — filtrarea se
     * face la nivelul fiecărui resolver.
     *
     * @return array<int,array<string,mixed>> indexat după id
     */
    public function getHeavyVehicles(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::HEAVY_TYPES), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, nr_inmatriculare, marca, model, tip_vehicul, capacitate_transport, status
               FROM vehicule
              WHERE tip_vehicul IN ($placeholders)
              ORDER BY nr_inmatriculare ASC"
        );
        $stmt->execute(self::HEAVY_TYPES);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /**
     * Cuplajele active tractor -> semiremorcă (ansamblu). Un tractor poartă
     * costurile semiremorcii cuplate; km-ul semiremorcii = km-ul tractorului.
     *
     * @return array<int,int> tractor_id => semiremorca_id
     */
    public function getActiveCouplings(): array
    {
        $rows = $this->db->query(
            'SELECT tractor_id, semiremorca_id FROM vehicule_cuplaje WHERE activ = 1'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['tractor_id']] = (int) $row['semiremorca_id'];
        }
        return $map;
    }

    // ------------------------------------------------------------------
    // Activitate (km / curse / venit) — numitorul modelului
    // ------------------------------------------------------------------

    /**
     * Activitatea din curse per vehicul pentru perioadă.
     * km_real  = COALESCE(NULLIF(km_totali,0), NULLIF(km_cursa,0), 0)   (expresia „kmEffective" din Dashboard Analitic)
     * km_facturat = COALESCE(NULLIF(km_cursa,0), NULLIF(km_totali,0), 0)
     * Venit = total_facturare (RON, fără TVA — snapshot tarifar).
     *
     * @return array<int,array{vehicle_id:int,curse:int,km_real:int,km_facturat:int,venit:float}>
     */
    public function getActivityByVehicle(string $dateStart, string $dateEnd): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.vehicle_id,
                    COUNT(*) AS curse,
                    SUM(CASE WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                             WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa
                             ELSE 0 END) AS km_real,
                    SUM(CASE WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa
                             WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                             ELSE 0 END) AS km_facturat,
                    SUM(COALESCE(c.total_facturare, 0)) AS venit
               FROM curse_dispecer c
              WHERE c.deleted_at IS NULL
                AND c.data_inceput BETWEEN :ds AND :de
              GROUP BY c.vehicle_id'
        );
        $stmt->execute(['ds' => $dateStart, 'de' => $dateEnd]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['vehicle_id']] = [
                'vehicle_id' => (int) $row['vehicle_id'],
                'curse' => (int) $row['curse'],
                'km_real' => (int) $row['km_real'],
                'km_facturat' => (int) $row['km_facturat'],
                'venit' => (float) $row['venit'],
            ];
        }
        return $out;
    }

    /**
     * Activitate defalcată vehicul × șofer × beneficiar — baza alocărilor BY_KM
     * pentru vederile Pe șofer / Pe beneficiar (aceeași sursă, alt grupaj).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getActivityMatrix(string $dateStart, string $dateEnd): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.vehicle_id, c.driver_id, c.beneficiar_id, c.tip_transport,
                    COUNT(*) AS curse,
                    SUM(CASE WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                             WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa
                             ELSE 0 END) AS km_real,
                    SUM(CASE WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa
                             WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali
                             ELSE 0 END) AS km_facturat,
                    SUM(COALESCE(c.total_facturare, 0)) AS venit
               FROM curse_dispecer c
              WHERE c.deleted_at IS NULL
                AND c.data_inceput BETWEEN :ds AND :de
              GROUP BY c.vehicle_id, c.driver_id, c.beneficiar_id, c.tip_transport'
        );
        $stmt->execute(['ds' => $dateStart, 'de' => $dateEnd]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Lista beneficiarilor activi (pentru filtre + etichete). @return array<int,string> */
    public function getBeneficiaries(): array
    {
        $rows = $this->db->query(
            'SELECT id, nume FROM configurare_beneficiari_transport ORDER BY nume ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) $row['nume'];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Șoferi + salarii
    // ------------------------------------------------------------------

    /**
     * Șoferii activi cu salariul lunii selectate (salary_history la sfârșit de
     * lună, fallback soferi.salariu) + vehiculul asociat (soferi_vehicule
     * is_primary, fallback soferi.vehicle_id).
     *
     * @return array<int,array<string,mixed>>
     */
    public function getActiveDriversWithSalary(string $monthEnd): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.nume, s.vehicle_id,
                    (SELECT sv.vehicle_id FROM soferi_vehicule sv
                      WHERE sv.driver_id = s.id AND sv.is_primary = 1 LIMIT 1) AS primary_vehicle_id,
                    COALESCE(
                        (SELECT sh.current_salary FROM salary_history sh
                          WHERE sh.subject_type = "driver" AND sh.driver_id = s.id
                            AND sh.effective_date <= :me
                          ORDER BY sh.effective_date DESC, sh.id DESC LIMIT 1),
                        s.salariu
                    ) AS salariu_luna
               FROM soferi s
              WHERE s.status = "activ"
              ORDER BY s.nume ASC'
        );
        $stmt->execute(['me' => $monthEnd]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'nume' => (string) $row['nume'],
                'vehicle_id' => $row['primary_vehicle_id'] !== null ? (int) $row['primary_vehicle_id'] : ($row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null),
                'salariu_luna' => $row['salariu_luna'] !== null ? (float) $row['salariu_luna'] : null,
            ];
        }
        return $out;
    }

    /**
     * Costurile documentelor per șofer (avize medicale/psihologice etc.) din
     * configurare_costuri_documente_soferi: lei/zi = cost/validity_days.
     *
     * @return array<int,array{driver_id:int,annual:float,items:int}>
     */
    public function getDriverDocumentAnnualCosts(): array
    {
        $rows = $this->db->query(
            'SELECT driver_id,
                    COUNT(*) AS items,
                    SUM(CASE WHEN validity_days > 0 THEN document_cost * 365.0 / validity_days ELSE 0 END) AS annual
               FROM configurare_costuri_documente_soferi
              WHERE document_cost > 0
              GROUP BY driver_id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['driver_id']] = [
                'driver_id' => (int) $row['driver_id'],
                'annual' => (float) $row['annual'],
                'items' => (int) $row['items'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Documente vehicule (RCA, CASCO, ITP, Rovinietă, IPROCHIM, Tahograf, Metrologie...)
    // ------------------------------------------------------------------

    /**
     * Costul anualizat al unui tip de document per vehicul, cu precedența
     * override-per-vehicul > config-per-tip (aceeași logică precum
     * DocumentModel::getVehicleDocumentDailyCost, dar per tip de document).
     *
     * @return array<int,array{vehicle_id:int,annual:float,cost:float,validity_days:int,source:string}>
     */
    public function getVehicleDocumentAnnualCosts(string $documentType): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.id AS vehicle_id,
                    COALESCE(o.document_cost, c.document_cost) AS cost,
                    COALESCE(o.validity_days, c.validity_days) AS validity_days,
                    CASE WHEN o.id IS NOT NULL THEN "override" ELSE "config_tip" END AS source
               FROM vehicule v
               LEFT JOIN configurare_costuri_documente_vehicule c
                 ON c.vehicle_type = (CASE WHEN v.tip_vehicul = "autoturism" THEN "autovehicul"
                                           WHEN v.tip_vehicul = "semiremorca" THEN "semiremorca_primar"
                                           ELSE v.tip_vehicul END)
                AND UPPER(c.document_type) = UPPER(:dt)
               LEFT JOIN configurare_costuri_documente_vehicule_override o
                 ON o.vehicle_id = v.id AND UPPER(o.document_type) = UPPER(:dt2)
              WHERE v.status = "activ"
                AND (c.id IS NOT NULL OR o.id IS NOT NULL)'
        );
        $stmt->execute(['dt' => $documentType, 'dt2' => $documentType]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cost = (float) ($row['cost'] ?? 0);
            $validity = (int) ($row['validity_days'] ?? 0);
            $out[(int) $row['vehicle_id']] = [
                'vehicle_id' => (int) $row['vehicle_id'],
                'annual' => $validity > 0 ? $cost * 365.0 / $validity : 0.0,
                'cost' => $cost,
                'validity_days' => $validity,
                'source' => (string) $row['source'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Autorizații zone (taxe drum)
    // ------------------------------------------------------------------

    /**
     * Costul autorizațiilor pe fereastra suprapusă cu perioada:
     * cost / zile_autorizație × zile_suprapunere.
     *
     * @return array<int,array{vehicle_id:int,period_value:float,items:int}>
     */
    public function getAuthorizationPeriodCosts(string $dateStart, string $dateEnd): array
    {
        $stmt = $this->db->prepare(
            'SELECT vehicle_id,
                    COUNT(*) AS items,
                    SUM(
                        cost / GREATEST(DATEDIFF(end_date, start_date) + 1, 1)
                        * (DATEDIFF(LEAST(end_date, :de1), GREATEST(start_date, :ds1)) + 1)
                    ) AS period_value
               FROM vehicle_authorizations
              WHERE cost > 0
                AND start_date <= :de2 AND end_date >= :ds2
              GROUP BY vehicle_id'
        );
        $stmt->execute(['de1' => $dateEnd, 'ds1' => $dateStart, 'de2' => $dateEnd, 'ds2' => $dateStart]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['vehicle_id']] = [
                'vehicle_id' => (int) $row['vehicle_id'],
                'period_value' => (float) $row['period_value'],
                'items' => (int) $row['items'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Inventar dotări (echipamente, trusă ADR, extinctoare)
    // ------------------------------------------------------------------

    /**
     * Cost lunar dotări per vehicul: cost alocare (fallback cost_implicit
     * catalog) / interval de inspecție în luni (fallback interval catalog,
     * fallback 12 luni).
     *
     * @return array<int,array{vehicle_id:int,monthly:float,items:int}>
     */
    public function getEquipmentMonthlyCosts(): array
    {
        $rows = $this->db->query(
            'SELECT idv.vehicle_id,
                    COUNT(*) AS items,
                    SUM(
                        (CASE WHEN idv.cost > 0 THEN idv.cost ELSE COALESCE(cat.cost_implicit, 0) END)
                        / GREATEST(COALESCE(idv.interval_inspectie_luni, cat.interval_implicit_inspectie_luni, 12), 1)
                    ) AS monthly
               FROM inventar_dotari_vehicule idv
               LEFT JOIN inventar_dotari_catalog cat ON cat.id = idv.catalog_id
              GROUP BY idv.vehicle_id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['vehicle_id']] = [
                'vehicle_id' => (int) $row['vehicle_id'],
                'monthly' => (float) $row['monthly'],
                'items' => (int) $row['items'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Management / Office (firm-level)
    // ------------------------------------------------------------------

    /**
     * Costul lunar firm-level: cheltuielile administrative din modulul unificat
     * Cheltuieli (valoare = net, fără TVA) + salariile de birou (automat, din
     * staff_members + salary_history).
     *
     * Sursa primară este tabela `cheltuieli` (pagina unificată); tabelele
     * legacy office_expenses / administrative_expenses sunt folosite DOAR ca
     * fallback dacă noul modul nu este încă instalat — datele legacy sunt
     * migrate în `cheltuieli`, deci citirea ambelor ar dubla sumele.
     *
     * @return array{office_net:float,admin_net:float,office_salaries:float,total:float,has_rows:bool}
     */
    public function getManagementMonthlyCost(string $monthStart, string $monthEnd): array
    {
        $officeNet = 0.0;
        $adminNet = 0.0;
        $hasRows = false;
        $unifiedAvailable = false;

        try {
            // valoare = totalul documentului; valoarea neta (fara TVA) are
            // prioritate cand a fost completata.
            $stmt = $this->db->prepare(
                'SELECT COALESCE(SUM(COALESCE(c.valoare_neta, c.valoare)), 0) AS total, COUNT(*) AS cnt
                   FROM cheltuieli c
                  WHERE c.categorie = "administrativa"
                    AND c.data_cheltuiala BETWEEN :ms AND :me'
            );
            $stmt->execute(['ms' => $monthStart, 'me' => $monthEnd]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'cnt' => 0];
            $adminNet = (float) $row['total'];
            $hasRows = (int) $row['cnt'] > 0;
            $unifiedAvailable = true;
        } catch (Throwable $e) {
            // Modulul unificat nu e instalat încă — fallback pe tabelele legacy.
        }

        if (!$unifiedAvailable) {
            try {
                $stmt = $this->db->prepare(
                    'SELECT COALESCE(SUM(oe.amount_net), 0) AS total, COUNT(*) AS cnt
                       FROM office_expenses oe
                       JOIN office_expense_categories oc ON oc.id = oe.category_id
                      WHERE oe.expense_date BETWEEN :ms AND :me
                        AND oc.is_automatic = 0'
                );
                $stmt->execute(['ms' => $monthStart, 'me' => $monthEnd]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'cnt' => 0];
                $officeNet = (float) $row['total'];
                $hasRows = $hasRows || ((int) $row['cnt'] > 0);
            } catch (Throwable $e) {
                // modulul nu e instalat încă — rămâne 0, raportat ca parțial
            }

            try {
                $stmt = $this->db->prepare(
                    'SELECT COALESCE(SUM(amount_net), 0) AS total, COUNT(*) AS cnt
                       FROM administrative_expenses
                      WHERE expense_date BETWEEN :ms AND :me'
                );
                $stmt->execute(['ms' => $monthStart, 'me' => $monthEnd]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'cnt' => 0];
                $adminNet = (float) $row['total'];
                $hasRows = $hasRows || ((int) $row['cnt'] > 0);
            } catch (Throwable $e) {
                // idem
            }
        }

        $officeSalaries = 0.0;
        try {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(SUM(
                            COALESCE(
                                (SELECT sh.current_salary FROM salary_history sh
                                  WHERE sh.subject_type = "staff" AND sh.staff_member_id = sm.id
                                    AND sh.effective_date <= :me
                                  ORDER BY sh.effective_date DESC, sh.id DESC LIMIT 1),
                                sm.salariu, 0
                            )
                        ), 0) AS total
                   FROM staff_members sm
                   JOIN staff_types st ON st.id = sm.staff_type_id
                  WHERE st.category = "office" AND sm.status = "activ"
                    AND (sm.data_angajare IS NULL OR sm.data_angajare <= :me2)'
            );
            $stmt->execute(['me' => $monthEnd, 'me2' => $monthEnd]);
            $officeSalaries = (float) $stmt->fetchColumn();
        } catch (Throwable $e) {
            // structura de personal poate lipsi în instalări parțiale
        }

        return [
            'office_net' => $officeNet,
            'admin_net' => $adminNet,
            'office_salaries' => $officeSalaries,
            'total' => $officeNet + $adminNet + $officeSalaries,
            'has_rows' => $hasRows || $officeSalaries > 0,
        ];
    }

    // ------------------------------------------------------------------
    // Carburant + AdBlue (CardOil) — valori NETE (de-TVA la nivel de rând)
    // ------------------------------------------------------------------

    /**
     * Cheltuiala reală de combustibil per vehicul în perioadă, netă de TVA.
     * De-TVA-izarea folosește cota reală din raw_payload.cota_tva per rând
     * (21% pe datele live), cu fallback pe parametrul configurat.
     * Se numără DOAR rândurile source_type='api' (precauția §H.3 din analiză).
     *
     * @return array<string,array{net:float,brut:float,litri:float,fillups:int}> cheie: nr. înmatriculare normalizat (fără spații, uppercase)
     */
    public function getFuelPeriodCosts(string $dateStart, string $dateEnd, string $fuelType, float $fallbackVatPercent): array
    {
        $stmt = $this->db->prepare(
            'SELECT REPLACE(UPPER(f.vehicle_registration), " ", "") AS reg_key,
                    COUNT(*) AS fillups,
                    SUM(f.quantity_liters) AS litri,
                    SUM(f.total_value) AS brut,
                    SUM(f.total_value / (1 + COALESCE(
                        CAST(JSON_UNQUOTE(JSON_EXTRACT(f.raw_payload, "$.cota_tva")) AS DECIMAL(6,2)),
                        :vat
                    ) / 100)) AS net
               FROM fuel_fillups f
              WHERE f.source_type = "api"
                AND f.fuel_type = :ft
                AND f.quantity_liters > 0
                AND f.fillup_datetime >= :ds AND f.fillup_datetime <= :de
              GROUP BY reg_key'
        );
        $stmt->execute([
            'vat' => $fallbackVatPercent,
            'ft' => $fuelType,
            'ds' => $dateStart . ' 00:00:00',
            'de' => $dateEnd . ' 23:59:59',
        ]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(string) $row['reg_key']] = [
                'net' => (float) $row['net'],
                'brut' => (float) $row['brut'],
                'litri' => (float) $row['litri'],
                'fillups' => (int) $row['fillups'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Mentenanță (revizii / reparații) + registru piese OCR
    // ------------------------------------------------------------------

    /**
     * Costurile de mentenanță per vehicul în perioadă.
     * Exclude rândurile 'Anvelopa - %' (cost 0 hard-codat de TireModel) și
     * intervențiile anulate. Folosește `cost` (totalul), NU manoperă+piese
     * (backfill-ul le face redundante — risc de dublă numărare).
     *
     * @return array<int,array{total:float,items:int}>
     */
    public function getMaintenancePeriodCosts(string $dateStart, string $dateEnd, string $recordType): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.vehicle_id,
                    COUNT(*) AS items,
                    COALESCE(SUM(m.cost), 0) AS total
               FROM mentenanta m
              WHERE m.record_type = :rt
                AND m.tip_interventie NOT LIKE "Anvelopa - %"
                AND (m.status_interventie IS NULL OR m.status_interventie <> "anulata")
                AND m.data_interventie BETWEEN :ds AND :de
              GROUP BY m.vehicle_id'
        );
        $stmt->execute(['rt' => $recordType, 'ds' => $dateStart, 'de' => $dateEnd]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['vehicle_id']] = [
                'total' => (float) $row['total'],
                'items' => (int) $row['items'],
            ];
        }
        return $out;
    }

    /**
     * Registrul de piese OCR (ledger PARALEL cu mentenanța — element dezactivat
     * implicit tocmai pentru riscul de dublă numărare).
     *
     * @return array<int,array{total:float,items:int}>
     */
    public function getOcrPartsPeriodCosts(string $dateStart, string $dateEnd): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT vehicle_id,
                        COUNT(*) AS items,
                        COALESCE(SUM(COALESCE(pret, 0) + COALESCE(pret_manopera, 0)), 0) AS total
                   FROM ocr_piese_registru
                  WHERE vehicle_id IS NOT NULL
                    AND data_interventie BETWEEN :ds AND :de
                  GROUP BY vehicle_id'
            );
            $stmt->execute(['ds' => $dateStart, 'de' => $dateEnd]);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['vehicle_id']] = [
                'total' => (float) $row['total'],
                'items' => (int) $row['items'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Anvelope — tarif lei/km din preț achiziție / durata de viață
    // ------------------------------------------------------------------

    /**
     * Rata lei/km a anvelopelor montate în perioadă per vehicul:
     * Σ(purchase_price / estimated_life_km) pentru alocările active care se
     * suprapun cu perioada. Anvelopele fără preț sunt raportate separat
     * (missing_price) — NU sunt tratate ca 0 în tăcere.
     *
     * @return array<int,array{rate_per_km:float,tires_priced:int,tires_unpriced:int}>
     */
    public function getTireRatePerKm(string $dateStart, string $dateEnd): array
    {
        $stmt = $this->db->prepare(
            'SELECT aa.vehicle_id,
                    SUM(CASE WHEN a.purchase_price > 0 AND COALESCE(a.estimated_life_km, 0) > 0
                             THEN a.purchase_price / a.estimated_life_km ELSE 0 END) AS rate_per_km,
                    SUM(CASE WHEN a.purchase_price > 0 AND COALESCE(a.estimated_life_km, 0) > 0 THEN 1 ELSE 0 END) AS tires_priced,
                    SUM(CASE WHEN a.purchase_price IS NULL OR a.purchase_price <= 0 OR COALESCE(a.estimated_life_km, 0) <= 0 THEN 1 ELSE 0 END) AS tires_unpriced
               FROM anvelope_alocari aa
               JOIN anvelope a ON a.id = aa.tire_id
              WHERE aa.data_start <= :de
                AND (aa.data_end IS NULL OR aa.data_end >= :ds)
              GROUP BY aa.vehicle_id'
        );
        $stmt->execute(['de' => $dateEnd, 'ds' => $dateStart]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['vehicle_id']] = [
                'rate_per_km' => (float) $row['rate_per_km'],
                'tires_priced' => (int) $row['tires_priced'],
                'tires_unpriced' => (int) $row['tires_unpriced'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Cheltuieli pe cursă (diurnă, taxe drum realizate)
    // ------------------------------------------------------------------

    /**
     * Cheltuielile realizate pe curse, de un anumit tip, grupate pe vehicul și
     * șofer (prin cursă). Nete de refacturările deja facturate (același
     * tratament ca Dashboard-ul Analitic).
     *
     * @return array<int,array<string,mixed>> rânduri {vehicle_id, driver_id, total, items}
     */
    public function getCourseExpensesByType(string $dateStart, string $dateEnd, string $expenseType): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.vehicle_id, c.driver_id,
                    COUNT(*) AS items,
                    COALESCE(SUM(GREATEST(0, ce.suma - CASE WHEN ce.refacturare_facturata = 1 THEN COALESCE(ce.refacturare_suma, 0) ELSE 0 END)), 0) AS total
               FROM curse_cheltuieli ce
               JOIN curse_dispecer c ON c.id = ce.cursa_id
              WHERE ce.tip_cheltuiala = :tip
                AND c.deleted_at IS NULL
                AND ce.data_cheltuiala BETWEEN :ds AND :de
              GROUP BY c.vehicle_id, c.driver_id'
        );
        $stmt->execute(['tip' => $expenseType, 'ds' => $dateStart, 'de' => $dateEnd]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
