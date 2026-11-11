<?php
declare(strict_types=1);

/**
 * Km service: drumurile unui vehicul la / de la service si perioada in care a stat
 * acolo (Dispecer curse -> "Km service").
 *
 * Tabelul este separat de curse_dispecer intentionat: km-ii de service nu intra in
 * tarifare, facturare, diurne sau in km-ii vehiculului sincronizati din curse. Ei
 * servesc doar ca justificare: vehiculul a fost inactiv pentru ca era la reparat,
 * iar km-ii GPS rulati pana la service nu sunt km pierduti.
 *
 * Un rand fara data_intoarcere inseamna ca vehiculul este inca in service.
 */
class VehicleServiceKmModel extends BaseModel
{
    private static bool $schemaReady = false;

    /**
     * Tipurile cheltuielilor de pe o perioada de service (service_km_cheltuieli.tip). Cheile sunt
     * aceleasi ca la facturi (InvoiceModel::TYPES), ca o factura asociata unei intrari (ex. eToll)
     * sa ajunga aici cu tipul ei. Motorina nu e aici: vine din Carburanti (fuel_trip_links).
     */
    public const EXPENSE_TYPES = [
        'service' => 'Reparații / piese',
        'toll' => 'Toll (eToll)',
        'taxa_acces' => 'Taxă acces',
        'trece' => 'Trecere',
        'port' => 'Port',
        'cazare' => 'Cazare',
        'diurna' => 'Diurnă',
        'spalatorie' => 'Spălătorie',
        'vulcanizare' => 'Vulcanizare',
        'alte' => 'Alte cheltuieli',
    ];

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    /**
     * Intrarile afisate pe Dispecer curse: toate vehiculele aflate acum in service
     * plus cele intoarse in ultimele $days zile, cele mai noi primele.
     */
    public function getRecentEntries(int $days = 90): array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, v.nr_inmatriculare, v.marca, v.model, d.nume AS sofer_nume, u.nume AS created_by_name
            FROM vehicule_service_km s
            INNER JOIN vehicule v ON v.id = s.vehicle_id
            LEFT JOIN soferi d ON d.id = s.driver_id
            LEFT JOIN utilizatori u ON u.id = s.created_by
            WHERE s.data_intoarcere IS NULL
               OR s.data_intoarcere >= :since
            ORDER BY (s.data_intoarcere IS NULL) DESC, s.data_plecare DESC, s.id DESC
        ");
        $stmt->execute([':since' => date('Y-m-d', strtotime('-' . max(1, $days) . ' days'))]);
        $rows = array_map([self::class, 'withDuration'], $stmt->fetchAll(PDO::FETCH_ASSOC));

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $fuel = $this->fuelByEntryId($ids);
        $expenses = $this->expenseTotalsByEntryId($ids);
        foreach ($rows as &$row) {
            $row['alimentari'] = $fuel[(int) $row['id']] ?? null;
            $row['cheltuieli'] = $expenses[(int) $row['id']] ?? null;
        }
        unset($row);

        return $rows;
    }

    /** O intrare cu vehiculul, soferul si autorul, pentru fisa din Dispecer. */
    public function findDetailed(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, v.nr_inmatriculare, v.marca, v.model, d.nume AS sofer_nume,
                   u.nume AS created_by_name, uu.nume AS updated_by_name
            FROM vehicule_service_km s
            INNER JOIN vehicule v ON v.id = s.vehicle_id
            LEFT JOIN soferi d ON d.id = s.driver_id
            LEFT JOIN utilizatori u ON u.id = s.created_by
            LEFT JOIN utilizatori uu ON uu.id = s.updated_by
            WHERE s.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::withDuration($row);
    }

    /**
     * Intrarile unui vehicul din jurul unei date (alegerea manuala din pagina Facturi).
     *
     * @return array<int, array<string, mixed>>
     */
    public function entriesAround(int $vehicleId, string $date, int $days): array
    {
        $stmt = $this->db->prepare("
            SELECT s.id, s.data_plecare, s.ora_plecare, s.data_intoarcere, s.ora_intoarcere, s.service_nume, v.nr_inmatriculare
            FROM vehicule_service_km s
            INNER JOIN vehicule v ON v.id = s.vehicle_id
            WHERE s.vehicle_id = :vehicle_id
              AND s.data_plecare <= DATE_ADD(:date_to, INTERVAL {$days} DAY)
              AND COALESCE(s.data_intoarcere, CURDATE()) >= DATE_SUB(:date_from, INTERVAL {$days} DAY)
            ORDER BY s.data_plecare DESC, s.id DESC
            LIMIT 20
        ");
        $stmt->execute([':vehicle_id' => $vehicleId, ':date_to' => $date, ':date_from' => $date]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Eticheta scurta a unei intrari: "Km service #7 · B 315 NET · 03.10.2026 → 12.10.2026 · Peco". */
    public static function label(array $row): string
    {
        $parts = ['Km service #' . (int) $row['id']];
        if (trim((string) ($row['nr_inmatriculare'] ?? '')) !== '') {
            $parts[] = (string) $row['nr_inmatriculare'];
        }
        $parts[] = date('d.m.Y', (int) strtotime((string) $row['data_plecare'])) . ' → '
            . (!empty($row['data_intoarcere']) ? date('d.m.Y', (int) strtotime((string) $row['data_intoarcere'])) : 'în service');
        if (trim((string) ($row['service_nume'] ?? '')) !== '') {
            $parts[] = (string) $row['service_nume'];
        }

        return implode(' · ', $parts);
    }

    // -------------------------------------------------------------------------
    // Cheltuielile perioadei de service (service_km_cheltuieli)
    // -------------------------------------------------------------------------

    /**
     * Cheltuielile intrarii: cele adaugate din fisa (factura_id NULL) si cele venite din
     * facturi asociate intrarii (ex. taxele eToll), cele mai noi primele.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getExpenses(int $serviceKmId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.*, u.nume AS added_by_name
            FROM service_km_cheltuieli c
            LEFT JOIN utilizatori u ON u.id = c.added_by
            WHERE c.service_km_id = :id
            ORDER BY c.data_cheltuiala DESC, c.id DESC
        ");
        $stmt->execute([':id' => $serviceKmId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findExpense(int $expenseId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM service_km_cheltuieli WHERE id = :id');
        $stmt->execute([':id' => $expenseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Cheltuiala adaugata din fisa intrarii. $data e deja validat de controller.
     *
     * @param array{tip: string, suma: string, data_cheltuiala: string, locatie: ?string, observatii: ?string} $data
     * @param array{file_path: string, original_name: string, mime_type: string, file_size: int}|null $document
     */
    public function addExpense(int $serviceKmId, array $data, ?array $document, ?int $userId): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO service_km_cheltuieli
                (service_km_id, tip, suma, data_cheltuiala, locatie, observatii,
                 document_path, document_original_name, document_mime, document_size, added_by, created_at)
            VALUES
                (:service_km_id, :tip, :suma, :data_cheltuiala, :locatie, :observatii,
                 :document_path, :document_original_name, :document_mime, :document_size, :added_by, :created_at)
        ');
        $stmt->execute([
            ':service_km_id' => $serviceKmId,
            ':tip' => $data['tip'],
            ':suma' => $data['suma'],
            ':data_cheltuiala' => $data['data_cheltuiala'],
            ':locatie' => $data['locatie'],
            ':observatii' => $data['observatii'],
            ':document_path' => $document['file_path'] ?? null,
            ':document_original_name' => $document['original_name'] ?? null,
            ':document_mime' => $document['mime_type'] ?? null,
            ':document_size' => $document['file_size'] ?? null,
            ':added_by' => $userId,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Sterge o cheltuiala adaugata manual; cele venite din facturi se scot din pagina Facturi. */
    public function deleteExpense(int $expenseId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM service_km_cheltuieli WHERE id = :id AND factura_id IS NULL');
        $stmt->execute([':id' => $expenseId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Randul-oglinda al unei facturi asociate intrarii (InvoiceModel::syncMirror): unul per factura,
     * mutat pe alta intrare / actualizat cand se schimba factura.
     *
     * @param array{tip: string, suma: string, data_cheltuiala: string, locatie: ?string, observatii: ?string, document_path: ?string, document_original_name: ?string, document_mime: ?string, document_size: ?int, added_by: ?int} $fields
     */
    public function upsertInvoiceExpense(int $invoiceId, int $serviceKmId, array $fields): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO service_km_cheltuieli
                (service_km_id, tip, suma, data_cheltuiala, locatie, observatii,
                 document_path, document_original_name, document_mime, document_size, factura_id, added_by, created_at)
            VALUES
                (:service_km_id, :tip, :suma, :data_cheltuiala, :locatie, :observatii,
                 :document_path, :document_original_name, :document_mime, :document_size, :factura_id, :added_by, :created_at)
            ON DUPLICATE KEY UPDATE
                service_km_id = VALUES(service_km_id), tip = VALUES(tip), suma = VALUES(suma),
                data_cheltuiala = VALUES(data_cheltuiala), locatie = VALUES(locatie), observatii = VALUES(observatii),
                document_path = VALUES(document_path), document_original_name = VALUES(document_original_name),
                document_mime = VALUES(document_mime), document_size = VALUES(document_size), updated_at = :updated_at
        ');
        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            ':service_km_id' => $serviceKmId,
            ':tip' => $fields['tip'],
            ':suma' => $fields['suma'],
            ':data_cheltuiala' => $fields['data_cheltuiala'],
            ':locatie' => $fields['locatie'],
            ':observatii' => $fields['observatii'],
            ':document_path' => $fields['document_path'],
            ':document_original_name' => $fields['document_original_name'],
            ':document_mime' => $fields['document_mime'],
            ':document_size' => $fields['document_size'],
            ':factura_id' => $invoiceId,
            ':added_by' => $fields['added_by'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    public function deleteInvoiceExpense(int $invoiceId): void
    {
        $stmt = $this->db->prepare('DELETE FROM service_km_cheltuieli WHERE factura_id = :factura_id');
        $stmt->execute([':factura_id' => $invoiceId]);
    }

    /**
     * Numarul si totalul cheltuielilor per intrare (coloana "Cheltuieli" din lista).
     *
     * @param int[] $ids
     * @return array<int, array{nr: int, suma: float}>
     */
    private function expenseTotalsByEntryId(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids)));
        if ($ids === []) {
            return [];
        }

        $stmt = $this->db->query('
            SELECT service_km_id, COUNT(*) AS nr, COALESCE(SUM(suma), 0) AS suma
            FROM service_km_cheltuieli
            WHERE service_km_id IN (' . implode(',', array_map('intval', $ids)) . ')
            GROUP BY service_km_id
        ');
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['service_km_id']] = ['nr' => (int) $row['nr'], 'suma' => (float) $row['suma']];
        }

        return $map;
    }

    /**
     * Alimentarile CardOil asociate intrarii in Carburanti (automat sau manual).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFuelFillups(int $serviceKmId): array
    {
        try {
            $stmt = $this->db->prepare('
                SELECT f.id, f.fillup_datetime, f.fuel_type, f.quantity_liters, f.total_value, f.station_name,
                       f.driver_name, l.match_type
                FROM fuel_trip_links l
                INNER JOIN fuel_fillups f ON f.id = l.fillup_id
                WHERE l.service_km_id = :id
                ORDER BY f.fillup_datetime ASC, f.id ASC
            ');
            $stmt->execute([':id' => $serviceKmId]);
        } catch (PDOException) {
            return []; // Carburanti inca nedeschis: fara coloana service_km_id
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Alimentarile asociate in Carburanti cu fiecare intrare (fuel_trip_links.service_km_id,
     * automat dupa interval ±12h sau manual). Coloana apare la prima deschidere a paginii
     * Carburanti / la sync; pana atunci nu exista asocieri.
     *
     * @param int[] $ids
     * @return array<int, array{nr: int, litri: float, valoare: float}>
     */
    private function fuelByEntryId(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids)));
        if ($ids === []) {
            return [];
        }

        try {
            $stmt = $this->db->query("
                SELECT l.service_km_id, COUNT(*) AS nr,
                       COALESCE(SUM(CASE WHEN f.fuel_type <> 'adblue' THEN f.quantity_liters ELSE 0 END), 0) AS litri,
                       COALESCE(SUM(f.total_value), 0) AS valoare
                FROM fuel_trip_links l
                INNER JOIN fuel_fillups f ON f.id = l.fillup_id
                WHERE l.service_km_id IN (" . implode(',', array_map('intval', $ids)) . ")
                GROUP BY l.service_km_id
            ");
        } catch (PDOException) {
            return [];
        }

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['service_km_id']] = [
                'nr' => (int) $row['nr'],
                'litri' => (float) $row['litri'],
                'valoare' => (float) $row['valoare'],
            ];
        }

        return $map;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM vehicule_service_km WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Km service per vehicul pentru Km pierduti. Ca la curse, o intrare intra in
     * perioada in care s-a incheiat (data intoarcerii; cea deschisa - data plecarii).
     *
     * @return array<int, array{nr: int, km: float, zile: int}>
     */
    public function kmByVehicleId(string $start, string $end): array
    {
        $stmt = $this->db->prepare("
            SELECT vehicle_id, COUNT(*) AS nr, COALESCE(SUM(km), 0) AS km,
                   COALESCE(SUM(DATEDIFF(COALESCE(data_intoarcere, CURDATE()), data_plecare) + 1), 0) AS zile
            FROM vehicule_service_km
            WHERE COALESCE(data_intoarcere, data_plecare) BETWEEN :start AND :end
            GROUP BY vehicle_id
        ");
        $stmt->execute([':start' => $start, ':end' => $end]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['vehicle_id']] = [
                'nr' => (int) $row['nr'],
                'km' => (float) $row['km'],
                'zile' => (int) $row['zile'],
            ];
        }

        return $map;
    }

    /**
     * O intrare noua sau modificarea uneia existente ($id > 0).
     * Cheile $data sunt deja validate de controller.
     */
    public function save(array $data, ?int $userId, int $id = 0): int
    {
        $params = [
            ':vehicle_id' => (int) $data['vehicle_id'],
            ':driver_id' => $data['driver_id'],
            ':data_plecare' => $data['data_plecare'],
            ':ora_plecare' => $data['ora_plecare'],
            ':data_intoarcere' => $data['data_intoarcere'],
            ':ora_intoarcere' => $data['ora_intoarcere'],
            ':km' => $data['km'],
            ':service_nume' => $data['service_nume'],
            ':motiv' => $data['motiv'],
            ':observatii' => $data['observatii'],
            ':prioritate' => $data['prioritate'] ?? 'normala',
        ];

        if ($id > 0) {
            $stmt = $this->db->prepare('
                UPDATE vehicule_service_km
                SET vehicle_id = :vehicle_id, driver_id = :driver_id,
                    data_plecare = :data_plecare, ora_plecare = :ora_plecare,
                    data_intoarcere = :data_intoarcere, ora_intoarcere = :ora_intoarcere,
                    km = :km, service_nume = :service_nume, motiv = :motiv, observatii = :observatii,
                    prioritate = :prioritate, updated_by = :updated_by, updated_at = :updated_at
                WHERE id = :id
            ');
            $params[':updated_by'] = $userId;
            $params[':updated_at'] = date('Y-m-d H:i:s');
            $params[':id'] = $id;
            $stmt->execute($params);

            return $id;
        }

        $stmt = $this->db->prepare('
            INSERT INTO vehicule_service_km
                (vehicle_id, driver_id, data_plecare, ora_plecare, data_intoarcere, ora_intoarcere,
                 km, service_nume, motiv, observatii, prioritate, created_by, created_at)
            VALUES
                (:vehicle_id, :driver_id, :data_plecare, :ora_plecare, :data_intoarcere, :ora_intoarcere,
                 :km, :service_nume, :motiv, :observatii, :prioritate, :created_by, :created_at)
        ');
        $params[':created_by'] = $userId;
        $params[':created_at'] = date('Y-m-d H:i:s');
        $stmt->execute($params);

        return (int) $this->db->lastInsertId();
    }

    /** Service-urile folosite deja (sugestii in formular), cele mai recente primele. */
    public function knownServiceNames(int $limit = 40): array
    {
        return $this->db->query(
            "SELECT service_nume FROM vehicule_service_km
             WHERE service_nume IS NOT NULL AND service_nume <> ''
             GROUP BY service_nume ORDER BY MAX(id) DESC LIMIT " . max(1, $limit)
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM vehicule_service_km WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** Alta intrare a aceluiasi vehicul care se suprapune peste interval (null = nicio suprapunere). */
    public function findOverlap(int $vehicleId, string $from, ?string $to, int $excludeId = 0): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM vehicule_service_km
            WHERE vehicle_id = :vehicle_id
              AND id <> :exclude_id
              AND data_plecare <= :to_date
              AND COALESCE(data_intoarcere, '9999-12-31') >= :from_date
            ORDER BY data_plecare ASC
            LIMIT 1
        ");
        $stmt->execute([
            ':vehicle_id' => $vehicleId,
            ':exclude_id' => $excludeId,
            ':to_date' => $to ?? '9999-12-31',
            ':from_date' => $from,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Cursele (sau fazele de pe acelasi vehicul) din Desfasurator care se suprapun peste
     * perioada de service: km-ii lor ar fi numarati de doua ori (o data pe cursa, o data
     * in km service din GPS). Suprapunere stricta: o cursa care se termina exact la plecare
     * nu conteaza. Ora lipsa = 00:00 la inceput / 23:59:59 la sfarsit; sfarsit lipsa = ziua
     * de inceput. $to null = vehiculul inca in service (interval deschis).
     * O cursa cu faze se verifica doar pe faze (faza 1 e si ea in curse_segmente): randul
     * cursei acopera de la prima faza la ultima, inclusiv pauza dintre ele, cand vehiculul
     * poate fi chiar in service.
     *
     * @return array<int, array{id: int, start: string, end: string}>
     */
    public function findOverlappingTrips(int $vehicleId, string $from, ?string $to): array
    {
        $to ??= '9999-12-31 23:59:59';
        $stmt = $this->db->prepare("
            SELECT trip_id, MIN(trip_start) AS trip_start, MAX(trip_end) AS trip_end
            FROM (
                SELECT c.id AS trip_id,
                       TIMESTAMP(c.data_inceput, COALESCE(c.ora_inceput, '00:00:00')) AS trip_start,
                       TIMESTAMP(COALESCE(c.data_sfarsit, c.data_inceput), COALESCE(c.ora_sfarsit, '23:59:59')) AS trip_end
                FROM curse_dispecer c
                WHERE c.deleted_at IS NULL AND c.vehicle_id = :vehicle_main AND c.data_inceput IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM curse_segmente sx WHERE sx.cursa_id = c.id AND sx.deleted_at IS NULL
                  )
                UNION ALL
                SELECT s.cursa_id,
                       TIMESTAMP(s.data_inceput, COALESCE(s.ora_inceput, '00:00:00')),
                       TIMESTAMP(COALESCE(s.data_sfarsit, s.data_inceput), COALESCE(s.ora_sfarsit, '23:59:59'))
                FROM curse_segmente s
                JOIN curse_dispecer cp ON cp.id = s.cursa_id AND cp.deleted_at IS NULL
                WHERE s.deleted_at IS NULL AND s.vehicle_id = :vehicle_segment AND s.data_inceput IS NOT NULL
            ) trips
            WHERE trip_start < :service_to AND trip_end > :service_from
            GROUP BY trip_id
            ORDER BY trip_start ASC
            LIMIT 20
        ");
        $stmt->execute([
            ':vehicle_main' => $vehicleId,
            ':vehicle_segment' => $vehicleId,
            ':service_to' => $to,
            ':service_from' => $from,
        ]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['trip_id'],
            'start' => (string) $row['trip_start'],
            'end' => (string) $row['trip_end'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Text scurt pentru mesaje: "#868 (11.10.2026 16:26 – 12.10.2026 17:53)". */
    public static function describeTrips(array $trips): string
    {
        return implode(', ', array_map(static fn (array $trip): string => sprintf(
            '#%d (%s – %s)',
            $trip['id'],
            date('d.m.Y H:i', (int) strtotime($trip['start'])),
            date('d.m.Y H:i', (int) strtotime($trip['end']))
        ), $trips));
    }

    /** Zilele in service (inclusiv ziua plecarii si a intoarcerii); cele deschise se numara pana azi. */
    private static function withDuration(array $row): array
    {
        $from = strtotime((string) $row['data_plecare']);
        $to = $row['data_intoarcere'] !== null ? strtotime((string) $row['data_intoarcere']) : strtotime(date('Y-m-d'));
        $row['zile_service'] = $from !== false && $to !== false ? max(1, (int) round(($to - $from) / 86400) + 1) : null;
        $row['in_service'] = $row['data_intoarcere'] === null;

        return $row;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        // Orice CREATE / ALTER (si CREATE TABLE IF NOT EXISTS pe o tabela existenta) face COMMIT
        // implicit in MySQL. Modelul se construieste si in tranzactii (InvoiceModel::syncMirror),
        // deci DDL-ul ruleaza doar cand chiar lipseste ceva; verificarea e un simplu SELECT.
        $state = $this->db->query("
            SELECT
                (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule_service_km') AS service_table,
                (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule_service_km' AND COLUMN_NAME = 'prioritate') AS priority_column,
                (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_km_cheltuieli') AS expense_table
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int) ($state['service_table'] ?? 0) > 0 && (int) ($state['priority_column'] ?? 0) > 0 && (int) ($state['expense_table'] ?? 0) > 0) {
            self::$schemaReady = true;
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS vehicule_service_km (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id INT UNSIGNED NOT NULL,
                driver_id INT UNSIGNED NULL,
                data_plecare DATE NOT NULL,
                ora_plecare TIME NULL,
                data_intoarcere DATE NULL,
                ora_intoarcere TIME NULL,
                km DECIMAL(10,2) NOT NULL DEFAULT 0,
                service_nume VARCHAR(150) NULL,
                motiv VARCHAR(255) NULL,
                observatii TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NULL,
                KEY idx_service_km_vehicle (vehicle_id, data_plecare),
                KEY idx_service_km_intoarcere (data_intoarcere),
                CONSTRAINT fk_service_km_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicule(id) ON DELETE CASCADE,
                CONSTRAINT fk_service_km_driver FOREIGN KEY (driver_id) REFERENCES soferi(id) ON DELETE SET NULL,
                CONSTRAINT fk_service_km_created_by FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL,
                CONSTRAINT fk_service_km_updated_by FOREIGN KEY (updated_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Km service V2: prioritatea ceruta de dispecer (cea din interventie o poate schimba coordonatorul).
        $column = $this->db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vehicule_service_km' AND COLUMN_NAME = 'prioritate'"
        )->fetchColumn();
        if ((int) $column === 0) {
            $this->db->exec(
                "ALTER TABLE vehicule_service_km
                 ADD COLUMN prioritate ENUM('scazuta','normala','ridicata','critica') NOT NULL DEFAULT 'normala' AFTER observatii"
            );
        }
        // Cheltuielile perioadei de service (ca "Adauga cheltuiala" de pe cursa). factura_id = randul
        // vine dintr-o factura asociata intrarii (ex. sesiune eToll), scris de InvoiceModel::syncMirror.
        // Documentul: fisier din uploads/curse_cheltuieli sau 'facturi:<id>' (trip_expense_document_url).
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS service_km_cheltuieli (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_km_id BIGINT UNSIGNED NOT NULL,
                tip VARCHAR(30) NOT NULL,
                suma DECIMAL(12,2) NOT NULL DEFAULT 0,
                data_cheltuiala DATE NOT NULL,
                locatie VARCHAR(190) NULL,
                observatii TEXT NULL,
                document_path VARCHAR(255) NULL,
                document_original_name VARCHAR(255) NULL,
                document_mime VARCHAR(150) NULL,
                document_size INT UNSIGNED NULL,
                factura_id INT UNSIGNED NULL,
                added_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uk_service_km_chelt_factura (factura_id),
                KEY idx_service_km_chelt (service_km_id, data_cheltuiala),
                CONSTRAINT fk_service_km_chelt_service FOREIGN KEY (service_km_id) REFERENCES vehicule_service_km(id) ON DELETE CASCADE,
                CONSTRAINT fk_service_km_chelt_added_by FOREIGN KEY (added_by) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        self::$schemaReady = true;
    }
}
