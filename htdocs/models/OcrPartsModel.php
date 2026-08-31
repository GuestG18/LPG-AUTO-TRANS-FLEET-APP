<?php
declare(strict_types=1);

/**
 * Persistenta trackerului EXPERIMENTAL de piese receptionate din facturi OCR.
 * Tabele dedicate (ocr_piese_facturi / ocr_piese_articole) - complet separate
 * de stocul de productie mentenanta_piese.
 */
class OcrPartsModel
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array<int,array<string,mixed>> facturi cu articolele lor, cele mai noi primele */
    public function getInvoicesWithLines(int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            'SELECT f.*, u.nume AS creat_de,
                    (SELECT COUNT(*) FROM ocr_piese_articole a WHERE a.factura_id = f.id) AS numar_articole,
                    (SELECT COALESCE(SUM(a.valoare), 0) FROM ocr_piese_articole a WHERE a.factura_id = f.id) AS valoare_articole
             FROM ocr_piese_facturi f
             LEFT JOIN utilizatori u ON u.id = f.created_by
             ORDER BY f.created_at DESC, f.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($invoices === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $invoices);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $linesStmt = $this->db->prepare(
            "SELECT * FROM ocr_piese_articole WHERE factura_id IN ($placeholders) ORDER BY factura_id, id"
        );
        $linesStmt->execute($ids);

        $linesByInvoice = [];
        foreach ($linesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $line) {
            $linesByInvoice[(int) $line['factura_id']][] = $line;
        }

        foreach ($invoices as &$invoice) {
            $invoice['articole'] = $linesByInvoice[(int) $invoice['id']] ?? [];
        }
        unset($invoice);

        return $invoices;
    }

    /** @return array{facturi:int,articole:int,valoare:float,furnizori:int} */
    public function getKpis(): array
    {
        $row = $this->db->query(
            'SELECT
                (SELECT COUNT(*) FROM ocr_piese_facturi) AS facturi,
                (SELECT COUNT(*) FROM ocr_piese_articole) AS articole,
                (SELECT COALESCE(SUM(valoare), 0) FROM ocr_piese_articole) AS valoare,
                (SELECT COUNT(DISTINCT furnizor) FROM ocr_piese_facturi WHERE COALESCE(furnizor, "") <> "") AS furnizori'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'facturi' => (int) ($row['facturi'] ?? 0),
            'articole' => (int) ($row['articole'] ?? 0),
            'valoare' => (float) ($row['valoare'] ?? 0),
            'furnizori' => (int) ($row['furnizori'] ?? 0),
        ];
    }

    /**
     * Salveaza factura + articolele intr-o tranzactie. Intoarce id-ul facturii.
     *
     * @param array<string,mixed> $header
     * @param array<int,array<string,mixed>> $lines
     */
    public function saveInvoice(array $header, array $lines, ?int $createdBy): int
    {
        $this->db->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $this->db->prepare(
                'INSERT INTO ocr_piese_facturi
                    (numar_factura, data_facturii, furnizor, cui_furnizor, moneda, total_factura,
                     fisier_original, fisier_stocat, ocr_text, ocr_durata_ms, observatii,
                     created_by, created_at, updated_at)
                 VALUES
                    (:numar, :data, :furnizor, :cui, :moneda, :total,
                     :fisier_original, :fisier_stocat, :ocr_text, :ocr_durata, :observatii,
                     :created_by, :created_at, :updated_at)'
            )->execute([
                ':numar' => self::nullIfEmpty($header['numar_factura'] ?? ''),
                ':data' => self::nullIfEmpty($header['data_facturii'] ?? ''),
                ':furnizor' => self::nullIfEmpty($header['furnizor'] ?? ''),
                ':cui' => self::nullIfEmpty($header['cui_furnizor'] ?? ''),
                ':moneda' => trim((string) ($header['moneda'] ?? 'RON')) ?: 'RON',
                ':total' => $header['total_factura'] !== null && $header['total_factura'] !== ''
                    ? (float) $header['total_factura'] : null,
                ':fisier_original' => self::nullIfEmpty($header['fisier_original'] ?? ''),
                ':fisier_stocat' => self::nullIfEmpty($header['fisier_stocat'] ?? ''),
                ':ocr_text' => self::nullIfEmpty($header['ocr_text'] ?? ''),
                ':ocr_durata' => isset($header['ocr_durata_ms']) && $header['ocr_durata_ms'] !== ''
                    ? (int) $header['ocr_durata_ms'] : null,
                ':observatii' => self::nullIfEmpty($header['observatii'] ?? ''),
                ':created_by' => $createdBy,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $invoiceId = (int) $this->db->lastInsertId();

            $lineStmt = $this->db->prepare(
                'INSERT INTO ocr_piese_articole
                    (factura_id, denumire, cod_piesa, categorie, unitate_masura,
                     cantitate, pret_unitar, valoare, din_ocr, observatii, created_at)
                 VALUES
                    (:factura_id, :denumire, :cod, :categorie, :um,
                     :cantitate, :pret, :valoare, :din_ocr, :observatii, :created_at)'
            );
            foreach ($lines as $line) {
                $lineStmt->execute([
                    ':factura_id' => $invoiceId,
                    ':denumire' => trim((string) $line['denumire']),
                    ':cod' => self::nullIfEmpty($line['cod_piesa'] ?? ''),
                    ':categorie' => self::nullIfEmpty($line['categorie'] ?? ''),
                    ':um' => trim((string) ($line['unitate_masura'] ?? 'buc')) ?: 'buc',
                    ':cantitate' => max(0, (float) ($line['cantitate'] ?? 1)),
                    ':pret' => max(0, (float) ($line['pret_unitar'] ?? 0)),
                    ':valoare' => max(0, (float) ($line['valoare'] ?? 0)),
                    ':din_ocr' => !empty($line['din_ocr']) ? 1 : 0,
                    ':observatii' => self::nullIfEmpty($line['observatii'] ?? ''),
                    ':created_at' => $now,
                ]);
            }

            $this->db->commit();
            return $invoiceId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    // ------------------------------------------------------------------
    // Registrul in stil Excel (ocr_piese_registru): o linie = o piesa/lucrare.
    // ------------------------------------------------------------------

    /** Campurile editabile inline din grila si tipul lor. */
    public const REGISTRY_FIELDS = [
        'vehicle_id' => 'int',
        'data_interventie' => 'date',
        'reparatii' => 'text',
        'inlocuiri' => 'text',
        'imbunatatiri' => 'text',
        'pret' => 'decimal',
        'furnizor' => 'string',
        'pret_manopera' => 'decimal',
        'furnizor_manopera' => 'string',
        'km_bord' => 'int',
    ];

    /** @return array<int,array<string,mixed>> randuri in ordine cronologica, ca in Excel */
    public function getRegistryRows(?int $vehicleId = null): array
    {
        $sql = 'SELECT r.*, v.nr_inmatriculare AS vehicul,
                       f.numar_factura, f.fisier_stocat AS factura_fisier
                FROM ocr_piese_registru r
                LEFT JOIN vehicule v ON v.id = r.vehicle_id
                LEFT JOIN ocr_piese_facturi f ON f.id = r.factura_id';
        $params = [];
        if ($vehicleId !== null && $vehicleId > 0) {
            $sql .= ' WHERE r.vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }
        $sql .= ' ORDER BY r.data_interventie IS NULL, r.data_interventie, r.id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int,array{id:int,nr_inmatriculare:string}> */
    public function getVehicleOptions(): array
    {
        return $this->db->query(
            "SELECT id, nr_inmatriculare FROM vehicule ORDER BY nr_inmatriculare"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array{randuri:int,total_piese:float,total_manopera:float} */
    public function getRegistryKpis(?int $vehicleId = null): array
    {
        $sql = 'SELECT COUNT(*) AS randuri,
                       COALESCE(SUM(pret), 0) AS total_piese,
                       COALESCE(SUM(pret_manopera), 0) AS total_manopera
                FROM ocr_piese_registru';
        $params = [];
        if ($vehicleId !== null && $vehicleId > 0) {
            $sql .= ' WHERE vehicle_id = :vehicle_id';
            $params[':vehicle_id'] = $vehicleId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'randuri' => (int) ($row['randuri'] ?? 0),
            'total_piese' => (float) ($row['total_piese'] ?? 0),
            'total_manopera' => (float) ($row['total_manopera'] ?? 0),
        ];
    }

    /** Creeaza un rand gol (ca "insert row" in Excel) si intoarce id-ul. */
    public function addRegistryRow(?int $vehicleId, ?int $createdBy): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO ocr_piese_registru (vehicle_id, data_interventie, created_by, created_at, updated_at)
             VALUES (:vehicle_id, :data, :created_by, :created_at, :updated_at)'
        )->execute([
            ':vehicle_id' => $vehicleId !== null && $vehicleId > 0 ? $vehicleId : null,
            ':data' => date('Y-m-d'),
            ':created_by' => $createdBy,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Actualizeaza o singura celula (edit inline). Intoarce valoarea normalizata
     * salvata, pentru reafisare. Arunca InvalidArgumentException la date invalide.
     */
    public function updateRegistryCell(int $rowId, string $field, ?string $rawValue): ?string
    {
        $type = self::REGISTRY_FIELDS[$field] ?? null;
        if ($type === null) {
            throw new InvalidArgumentException('Câmp needitabil: ' . $field);
        }

        $value = $rawValue !== null ? trim($rawValue) : '';
        $normalized = null;

        if ($value !== '') {
            switch ($type) {
                case 'int':
                    if (!preg_match('/^\d{1,9}$/', $value)) {
                        throw new InvalidArgumentException('Valoarea trebuie să fie un număr întreg.');
                    }
                    $normalized = (string) (int) $value;
                    break;
                case 'decimal':
                    // Acceptam "1.234,56", "1234,56" si "1234.56".
                    $clean = preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $value)
                        ? str_replace('.', '', $value)
                        : $value;
                    $clean = str_replace(',', '.', $clean);
                    if (!is_numeric($clean) || (float) $clean < 0 || (float) $clean > 9999999999.99) {
                        throw new InvalidArgumentException('Valoarea nu este un număr valid.');
                    }
                    $normalized = number_format((float) $clean, 2, '.', '');
                    break;
                case 'date':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        throw new InvalidArgumentException('Data trebuie să fie în format AAAA-LL-ZZ.');
                    }
                    $normalized = $value;
                    break;
                default:
                    $maxLength = in_array($field, ['furnizor', 'furnizor_manopera'], true) ? 190 : 2000;
                    $normalized = mb_substr($value, 0, $maxLength);
            }
        }

        if ($field === 'vehicle_id' && $normalized !== null && (int) $normalized <= 0) {
            $normalized = null;
        }

        $stmt = $this->db->prepare(
            "UPDATE ocr_piese_registru SET $field = :value, updated_at = :updated_at WHERE id = :id"
        );
        $stmt->execute([':value' => $normalized, ':updated_at' => date('Y-m-d H:i:s'), ':id' => $rowId]);

        if ($stmt->rowCount() === 0) {
            $check = $this->db->prepare('SELECT COUNT(*) FROM ocr_piese_registru WHERE id = :id');
            $check->execute([':id' => $rowId]);
            if ((int) $check->fetchColumn() === 0) {
                throw new InvalidArgumentException('Rândul nu mai există (a fost șters).');
            }
        }

        return $normalized;
    }

    public function deleteRegistryRow(int $rowId): void
    {
        $this->db->prepare('DELETE FROM ocr_piese_registru WHERE id = :id')->execute([':id' => $rowId]);
    }

    /**
     * Salveaza factura (dovada + text OCR) si randurile de registru confirmate,
     * intr-o singura tranzactie. Intoarce id-ul facturii.
     *
     * @param array<string,mixed> $header
     * @param array<int,array<string,mixed>> $registryRows randuri gata mapate pe coloanele registrului
     */
    public function saveInvoiceToRegistry(array $header, array $registryRows, ?int $createdBy): int
    {
        $this->db->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $this->db->prepare(
                'INSERT INTO ocr_piese_facturi
                    (numar_factura, data_facturii, furnizor, cui_furnizor, moneda, total_factura,
                     fisier_original, fisier_stocat, ocr_text, ocr_durata_ms, observatii,
                     created_by, created_at, updated_at)
                 VALUES
                    (:numar, :data, :furnizor, :cui, :moneda, :total,
                     :fisier_original, :fisier_stocat, :ocr_text, :ocr_durata, :observatii,
                     :created_by, :created_at, :updated_at)'
            )->execute([
                ':numar' => self::nullIfEmpty($header['numar_factura'] ?? ''),
                ':data' => self::nullIfEmpty($header['data_facturii'] ?? ''),
                ':furnizor' => self::nullIfEmpty($header['furnizor'] ?? ''),
                ':cui' => self::nullIfEmpty($header['cui_furnizor'] ?? ''),
                ':moneda' => trim((string) ($header['moneda'] ?? 'RON')) ?: 'RON',
                ':total' => $header['total_factura'] !== null && $header['total_factura'] !== ''
                    ? (float) $header['total_factura'] : null,
                ':fisier_original' => self::nullIfEmpty($header['fisier_original'] ?? ''),
                ':fisier_stocat' => self::nullIfEmpty($header['fisier_stocat'] ?? ''),
                ':ocr_text' => self::nullIfEmpty($header['ocr_text'] ?? ''),
                ':ocr_durata' => isset($header['ocr_durata_ms']) && $header['ocr_durata_ms'] !== ''
                    ? (int) $header['ocr_durata_ms'] : null,
                ':observatii' => self::nullIfEmpty($header['observatii'] ?? ''),
                ':created_by' => $createdBy,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $invoiceId = (int) $this->db->lastInsertId();

            $rowStmt = $this->db->prepare(
                'INSERT INTO ocr_piese_registru
                    (vehicle_id, data_interventie, reparatii, inlocuiri, imbunatatiri,
                     pret, furnizor, pret_manopera, furnizor_manopera, km_bord,
                     factura_id, created_by, created_at, updated_at)
                 VALUES
                    (:vehicle_id, :data, :reparatii, :inlocuiri, :imbunatatiri,
                     :pret, :furnizor, :pret_manopera, :furnizor_manopera, :km_bord,
                     :factura_id, :created_by, :created_at, :updated_at)'
            );
            foreach ($registryRows as $row) {
                $rowStmt->execute([
                    ':vehicle_id' => !empty($row['vehicle_id']) ? (int) $row['vehicle_id'] : null,
                    ':data' => self::nullIfEmpty($row['data_interventie'] ?? ''),
                    ':reparatii' => self::nullIfEmpty($row['reparatii'] ?? ''),
                    ':inlocuiri' => self::nullIfEmpty($row['inlocuiri'] ?? ''),
                    ':imbunatatiri' => self::nullIfEmpty($row['imbunatatiri'] ?? ''),
                    ':pret' => isset($row['pret']) && $row['pret'] !== '' && $row['pret'] !== null
                        ? (float) $row['pret'] : null,
                    ':furnizor' => self::nullIfEmpty($row['furnizor'] ?? ''),
                    ':pret_manopera' => isset($row['pret_manopera']) && $row['pret_manopera'] !== '' && $row['pret_manopera'] !== null
                        ? (float) $row['pret_manopera'] : null,
                    ':furnizor_manopera' => self::nullIfEmpty($row['furnizor_manopera'] ?? ''),
                    ':km_bord' => !empty($row['km_bord']) ? (int) $row['km_bord'] : null,
                    ':factura_id' => $invoiceId,
                    ':created_by' => $createdBy,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }

            $this->db->commit();
            return $invoiceId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** Sterge factura (articolele cad prin FK cascade). Intoarce numele fisierului stocat, pentru curatare. */
    public function deleteInvoice(int $invoiceId): ?string
    {
        $stmt = $this->db->prepare('SELECT fisier_stocat FROM ocr_piese_facturi WHERE id = :id');
        $stmt->execute([':id' => $invoiceId]);
        $storedFile = $stmt->fetchColumn();

        $this->db->prepare('DELETE FROM ocr_piese_facturi WHERE id = :id')->execute([':id' => $invoiceId]);

        return is_string($storedFile) && $storedFile !== '' ? $storedFile : null;
    }

    // ------------------------------------------------------------------
    // Model parinte/copil: un eveniment de reparatie (ocr_reparatii) cu
    // piese (ocr_reparatii_piese) si manopera (ocr_reparatii_manopera).
    // ------------------------------------------------------------------

    public const TIP_LUCRARE_OPTIONS = [
        'reparatie' => 'Reparație',
        'inlocuire' => 'Înlocuire',
        'intretinere' => 'Întreținere',
        'imbunatatire' => 'Îmbunătățire',
    ];

    public const WARRANTY_OPTIONS = [6, 12, 24, 36];

    /** Campurile editabile inline pe randul parinte. */
    public const EVENT_FIELDS = [
        'vehicle_id' => 'int',
        'data_interventie' => 'date',
        'document' => 'string',
        'furnizor' => 'string',
        'tip_lucrare' => 'tip',
        'km_bord' => 'int',
        'observatii' => 'text',
    ];

    public const PART_FIELDS = [
        'denumire' => 'string',
        'cod_piesa' => 'string',
        'cantitate' => 'decimal',
        'pret_unitar' => 'decimal',
        'garantie_luni' => 'warranty',
    ];

    public const LABOR_FIELDS = [
        'denumire' => 'string',
        'norma_ore' => 'decimal',
        'pret_ora' => 'decimal',
        'garantie_luni' => 'warranty',
    ];

    /**
     * Conditia WHERE + parametrii pentru filtrele paginii.
     * Cautarea acopera parintele si copiii (piese + manopera).
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildEventFilterWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['vehicle_id'])) {
            $where[] = 'r.vehicle_id = :f_vehicle';
            $params[':f_vehicle'] = (int) $filters['vehicle_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'r.data_interventie >= :f_from';
            $params[':f_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'r.data_interventie <= :f_to';
            $params[':f_to'] = $filters['date_to'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(r.document LIKE :f_q OR r.furnizor LIKE :f_q OR r.observatii LIKE :f_q
                OR v.nr_inmatriculare LIKE :f_q
                OR EXISTS (SELECT 1 FROM ocr_reparatii_piese p WHERE p.reparatie_id = r.id
                           AND (p.denumire LIKE :f_q OR p.cod_piesa LIKE :f_q))
                OR EXISTS (SELECT 1 FROM ocr_reparatii_manopera m WHERE m.reparatie_id = r.id
                           AND m.denumire LIKE :f_q))';
            $params[':f_q'] = '%' . $filters['q'] . '%';
        }

        return [$where === [] ? '1=1' : implode(' AND ', $where), $params];
    }

    /**
     * Evenimentele filtrate + paginate, cu copiii si totalurile lor.
     *
     * @return array{rows:array<int,array<string,mixed>>,total_count:int,totals:array{piese:float,manopera:float,general:float}}
     */
    public function getRepairEvents(array $filters, int $page = 1, int $perPage = 10): array
    {
        [$whereSql, $params] = $this->buildEventFilterWhere($filters);

        // Numarul total + totalurile pe intregul set filtrat (nu doar pagina).
        $aggStmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM((SELECT COALESCE(SUM(p.cantitate * p.pret_unitar), 0)
                                  FROM ocr_reparatii_piese p WHERE p.reparatie_id = r.id)), 0) AS total_piese,
                    COALESCE(SUM((SELECT COALESCE(SUM(m.norma_ore * m.pret_ora), 0)
                                  FROM ocr_reparatii_manopera m WHERE m.reparatie_id = r.id)), 0) AS total_manopera
             FROM ocr_reparatii r
             LEFT JOIN vehicule v ON v.id = r.vehicle_id
             WHERE $whereSql"
        );
        $aggStmt->execute($params);
        $agg = $aggStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalCount = (int) ($agg['cnt'] ?? 0);
        $totalPiese = (float) ($agg['total_piese'] ?? 0);
        $totalManopera = (float) ($agg['total_manopera'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->prepare(
            "SELECT r.*, v.nr_inmatriculare AS vehicul, v.tip_vehicul,
                    f.fisier_stocat AS factura_fisier, f.numar_factura,
                    (SELECT COALESCE(SUM(p.cantitate * p.pret_unitar), 0)
                     FROM ocr_reparatii_piese p WHERE p.reparatie_id = r.id) AS total_piese,
                    (SELECT COALESCE(SUM(m.norma_ore * m.pret_ora), 0)
                     FROM ocr_reparatii_manopera m WHERE m.reparatie_id = r.id) AS total_manopera
             FROM ocr_reparatii r
             LEFT JOIN vehicule v ON v.id = r.vehicle_id
             LEFT JOIN ocr_piese_facturi f ON f.id = r.factura_id
             WHERE $whereSql
             ORDER BY r.data_interventie IS NULL, r.data_interventie DESC, r.id DESC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows !== []) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $partsByEvent = [];
            $partsStmt = $this->db->prepare(
                "SELECT * FROM ocr_reparatii_piese WHERE reparatie_id IN ($placeholders) ORDER BY reparatie_id, id"
            );
            $partsStmt->execute($ids);
            foreach ($partsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $part) {
                $partsByEvent[(int) $part['reparatie_id']][] = $part;
            }

            $laborByEvent = [];
            $laborStmt = $this->db->prepare(
                "SELECT * FROM ocr_reparatii_manopera WHERE reparatie_id IN ($placeholders) ORDER BY reparatie_id, id"
            );
            $laborStmt->execute($ids);
            foreach ($laborStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $labor) {
                $laborByEvent[(int) $labor['reparatie_id']][] = $labor;
            }

            foreach ($rows as &$row) {
                $row['piese'] = $partsByEvent[(int) $row['id']] ?? [];
                $row['manopera'] = $laborByEvent[(int) $row['id']] ?? [];
            }
            unset($row);
        }

        return [
            'rows' => $rows,
            'total_count' => $totalCount,
            'totals' => [
                'piese' => $totalPiese,
                'manopera' => $totalManopera,
                'general' => $totalPiese + $totalManopera,
            ],
        ];
    }

    /** Totalurile recalculate ale unui eveniment (dupa un edit de copil). */
    public function getEventTotals(int $eventId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                (SELECT COALESCE(SUM(cantitate * pret_unitar), 0) FROM ocr_reparatii_piese WHERE reparatie_id = :id1) AS piese,
                (SELECT COALESCE(SUM(norma_ore * pret_ora), 0) FROM ocr_reparatii_manopera WHERE reparatie_id = :id2) AS manopera'
        );
        $stmt->execute([':id1' => $eventId, ':id2' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $piese = (float) ($row['piese'] ?? 0);
        $manopera = (float) ($row['manopera'] ?? 0);

        return ['piese' => $piese, 'manopera' => $manopera, 'general' => $piese + $manopera];
    }

    public function addEvent(?int $vehicleId, ?int $createdBy): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO ocr_reparatii (vehicle_id, data_interventie, tip_lucrare, created_by, created_at, updated_at)
             VALUES (:vehicle_id, :data, :tip, :created_by, :created_at, :updated_at)'
        )->execute([
            ':vehicle_id' => $vehicleId !== null && $vehicleId > 0 ? $vehicleId : null,
            ':data' => date('Y-m-d'),
            ':tip' => 'reparatie',
            ':created_by' => $createdBy,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function deleteEvent(int $eventId): void
    {
        $this->db->prepare('DELETE FROM ocr_reparatii WHERE id = :id')->execute([':id' => $eventId]);
    }

    public function updateEventField(int $eventId, string $field, ?string $rawValue): ?string
    {
        return $this->updateChildField('ocr_reparatii', self::EVENT_FIELDS, $eventId, $field, $rawValue);
    }

    public function addPart(int $eventId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO ocr_reparatii_piese (reparatie_id, denumire, cantitate, pret_unitar, created_at, updated_at)
             VALUES (:event_id, "", 1, 0, :created_at, :updated_at)'
        )->execute([':event_id' => $eventId, ':created_at' => $now, ':updated_at' => $now]);

        return (int) $this->db->lastInsertId();
    }

    public function updatePartField(int $partId, string $field, ?string $rawValue): ?string
    {
        return $this->updateChildField('ocr_reparatii_piese', self::PART_FIELDS, $partId, $field, $rawValue);
    }

    public function deletePart(int $partId): ?int
    {
        return $this->deleteChildRow('ocr_reparatii_piese', $partId);
    }

    public function addLabor(int $eventId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->prepare(
            'INSERT INTO ocr_reparatii_manopera (reparatie_id, denumire, norma_ore, pret_ora, created_at, updated_at)
             VALUES (:event_id, "", 0, 0, :created_at, :updated_at)'
        )->execute([':event_id' => $eventId, ':created_at' => $now, ':updated_at' => $now]);

        return (int) $this->db->lastInsertId();
    }

    public function updateLaborField(int $laborId, string $field, ?string $rawValue): ?string
    {
        return $this->updateChildField('ocr_reparatii_manopera', self::LABOR_FIELDS, $laborId, $field, $rawValue);
    }

    public function deleteLabor(int $laborId): ?int
    {
        return $this->deleteChildRow('ocr_reparatii_manopera', $laborId);
    }

    /** Intoarce reparatie_id (pentru recalcul totaluri) sau null daca randul nu exista. */
    private function deleteChildRow(string $table, int $rowId): ?int
    {
        $stmt = $this->db->prepare("SELECT reparatie_id FROM $table WHERE id = :id");
        $stmt->execute([':id' => $rowId]);
        $eventId = $stmt->fetchColumn();
        if ($eventId === false) {
            return null;
        }
        $this->db->prepare("DELETE FROM $table WHERE id = :id")->execute([':id' => $rowId]);

        return (int) $eventId;
    }

    /** Parintele unui rand copil (pentru raspunsul cu totaluri). */
    public function getChildEventId(string $type, int $rowId): ?int
    {
        $table = $type === 'labor' ? 'ocr_reparatii_manopera' : 'ocr_reparatii_piese';
        $stmt = $this->db->prepare("SELECT reparatie_id FROM $table WHERE id = :id");
        $stmt->execute([':id' => $rowId]);
        $eventId = $stmt->fetchColumn();

        return $eventId === false ? null : (int) $eventId;
    }

    /** Validare + UPDATE pe un singur camp, cu whitelist-ul de campuri dat. */
    private function updateChildField(string $table, array $fieldTypes, int $rowId, string $field, ?string $rawValue): ?string
    {
        $type = $fieldTypes[$field] ?? null;
        if ($type === null) {
            throw new InvalidArgumentException('Câmp needitabil: ' . $field);
        }

        $value = $rawValue !== null ? trim($rawValue) : '';
        $normalized = null;

        if ($value !== '') {
            switch ($type) {
                case 'int':
                    if (!preg_match('/^\d{1,9}$/', $value)) {
                        throw new InvalidArgumentException('Valoarea trebuie să fie un număr întreg.');
                    }
                    $normalized = (string) (int) $value;
                    break;
                case 'decimal':
                    $clean = preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $value)
                        ? str_replace('.', '', $value)
                        : $value;
                    $clean = str_replace(',', '.', $clean);
                    if (!is_numeric($clean) || (float) $clean < 0 || (float) $clean > 9999999999.99) {
                        throw new InvalidArgumentException('Valoarea nu este un număr valid.');
                    }
                    $normalized = number_format((float) $clean, 2, '.', '');
                    break;
                case 'date':
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        throw new InvalidArgumentException('Data trebuie să fie în format AAAA-LL-ZZ.');
                    }
                    $normalized = $value;
                    break;
                case 'tip':
                    if (!isset(self::TIP_LUCRARE_OPTIONS[$value])) {
                        throw new InvalidArgumentException('Tip de lucrare invalid.');
                    }
                    $normalized = $value;
                    break;
                case 'warranty':
                    if (!in_array((int) $value, self::WARRANTY_OPTIONS_V2, true)) {
                        throw new InvalidArgumentException('Garanție invalidă.');
                    }
                    $normalized = (string) (int) $value;
                    break;
                case 'item_tip':
                    if (!in_array($value, ['piesa', 'manopera'], true)) {
                        throw new InvalidArgumentException('Tip de articol invalid.');
                    }
                    $normalized = $value;
                    break;
                case 'destinatie':
                    if (!in_array($value, ['vehicul', 'stoc'], true)) {
                        throw new InvalidArgumentException('Destinație invalidă.');
                    }
                    $normalized = $value;
                    break;
                case 'text':
                    $normalized = mb_substr($value, 0, 2000);
                    break;
                default:
                    $normalized = mb_substr($value, 0, $field === 'denumire' ? 255 : 190);
            }
        }

        if ($field === 'vehicle_id' && $normalized !== null && (int) $normalized <= 0) {
            $normalized = null;
        }
        // Campurile-selector nu pot fi goale.
        if ($normalized === null && in_array($field, ['tip_lucrare', 'tip', 'destinatie'], true)) {
            throw new InvalidArgumentException('Câmpul „' . $field . '" este obligatoriu.');
        }

        $stmt = $this->db->prepare(
            "UPDATE $table SET $field = :value, updated_at = :updated_at WHERE id = :id"
        );
        $stmt->execute([':value' => $normalized, ':updated_at' => date('Y-m-d H:i:s'), ':id' => $rowId]);

        if ($stmt->rowCount() === 0) {
            $check = $this->db->prepare("SELECT COUNT(*) FROM $table WHERE id = :id");
            $check->execute([':id' => $rowId]);
            if ((int) $check->fetchColumn() === 0) {
                throw new InvalidArgumentException('Rândul nu mai există (a fost șters).');
            }
        }

        return $normalized;
    }

    /**
     * Salveaza o factura OCR ca UN SINGUR eveniment parinte cu piese[] si manopera[].
     *
     * @param array<string,mixed> $header antetul facturii (si dovada OCR)
     * @param array<int,array<string,mixed>> $parts   {denumire, cod_piesa, cantitate, pret_unitar}
     * @param array<int,array<string,mixed>> $labor   {denumire, norma_ore, pret_ora}
     */
    public function saveInvoiceAsEvent(array $header, array $parts, array $labor, ?int $createdBy): int
    {
        $this->db->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $this->db->prepare(
                'INSERT INTO ocr_piese_facturi
                    (numar_factura, data_facturii, furnizor, cui_furnizor, moneda, total_factura,
                     fisier_original, fisier_stocat, ocr_text, ocr_durata_ms, observatii,
                     created_by, created_at, updated_at)
                 VALUES
                    (:numar, :data, :furnizor, :cui, :moneda, :total,
                     :fisier_original, :fisier_stocat, :ocr_text, :ocr_durata, :observatii,
                     :created_by, :created_at, :updated_at)'
            )->execute([
                ':numar' => self::nullIfEmpty($header['numar_factura'] ?? ''),
                ':data' => self::nullIfEmpty($header['data_facturii'] ?? ''),
                ':furnizor' => self::nullIfEmpty($header['furnizor'] ?? ''),
                ':cui' => self::nullIfEmpty($header['cui_furnizor'] ?? ''),
                ':moneda' => trim((string) ($header['moneda'] ?? 'RON')) ?: 'RON',
                ':total' => $header['total_factura'] !== null && $header['total_factura'] !== ''
                    ? (float) $header['total_factura'] : null,
                ':fisier_original' => self::nullIfEmpty($header['fisier_original'] ?? ''),
                ':fisier_stocat' => self::nullIfEmpty($header['fisier_stocat'] ?? ''),
                ':ocr_text' => self::nullIfEmpty($header['ocr_text'] ?? ''),
                ':ocr_durata' => isset($header['ocr_durata_ms']) && $header['ocr_durata_ms'] !== ''
                    ? (int) $header['ocr_durata_ms'] : null,
                ':observatii' => self::nullIfEmpty($header['observatii'] ?? ''),
                ':created_by' => $createdBy,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $invoiceId = (int) $this->db->lastInsertId();

            $document = self::nullIfEmpty($header['numar_factura'] ?? '');
            $this->db->prepare(
                'INSERT INTO ocr_reparatii
                    (vehicle_id, data_interventie, document, furnizor, tip_lucrare, km_bord,
                     observatii, factura_id, created_by, created_at, updated_at)
                 VALUES
                    (:vehicle_id, :data, :document, :furnizor, :tip, :km,
                     :observatii, :factura_id, :created_by, :created_at, :updated_at)'
            )->execute([
                ':vehicle_id' => !empty($header['vehicle_id']) ? (int) $header['vehicle_id'] : null,
                ':data' => self::nullIfEmpty($header['data_facturii'] ?? ''),
                ':document' => $document !== null ? 'Factura ' . $document : null,
                ':furnizor' => self::nullIfEmpty($header['furnizor'] ?? ''),
                ':tip' => isset(self::TIP_LUCRARE_OPTIONS[$header['tip_lucrare'] ?? '']) ? (string) $header['tip_lucrare'] : 'reparatie',
                ':km' => !empty($header['km_bord']) ? (int) $header['km_bord'] : null,
                ':observatii' => self::nullIfEmpty($header['observatii'] ?? ''),
                ':factura_id' => $invoiceId,
                ':created_by' => $createdBy,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $eventId = (int) $this->db->lastInsertId();

            $partStmt = $this->db->prepare(
                'INSERT INTO ocr_reparatii_piese (reparatie_id, denumire, cod_piesa, cantitate, pret_unitar, created_at, updated_at)
                 VALUES (:event_id, :denumire, :cod, :cantitate, :pret, :created_at, :updated_at)'
            );
            foreach ($parts as $part) {
                $partStmt->execute([
                    ':event_id' => $eventId,
                    ':denumire' => mb_substr(trim((string) ($part['denumire'] ?? '')), 0, 255),
                    ':cod' => self::nullIfEmpty($part['cod_piesa'] ?? ''),
                    ':cantitate' => max(0, (float) ($part['cantitate'] ?? 1)),
                    ':pret' => max(0, (float) ($part['pret_unitar'] ?? 0)),
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }

            $laborStmt = $this->db->prepare(
                'INSERT INTO ocr_reparatii_manopera (reparatie_id, denumire, norma_ore, pret_ora, created_at, updated_at)
                 VALUES (:event_id, :denumire, :norma, :pret, :created_at, :updated_at)'
            );
            foreach ($labor as $work) {
                $laborStmt->execute([
                    ':event_id' => $eventId,
                    ':denumire' => mb_substr(trim((string) ($work['denumire'] ?? '')), 0, 255),
                    ':norma' => max(0, (float) ($work['norma_ore'] ?? 0)),
                    ':pret' => max(0, (float) ($work['pret_ora'] ?? 0)),
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }

            $this->db->commit();
            return $eventId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Export CSV aplatizat: o linie per copil, cu datele parintelui repetate.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getExportRows(array $filters): array
    {
        [$whereSql, $params] = $this->buildEventFilterWhere($filters);
        $stmt = $this->db->prepare(
            "SELECT r.id, v.nr_inmatriculare AS vehicul, r.data_interventie, r.document, r.furnizor,
                    r.tip_lucrare, r.km_bord, r.observatii,
                    c.tip_linie, c.denumire, c.cod_piesa, c.cantitate, c.pret_unitar, c.garantie_luni
             FROM ocr_reparatii r
             LEFT JOIN vehicule v ON v.id = r.vehicle_id
             LEFT JOIN (
                 SELECT reparatie_id, 'piesa' AS tip_linie, denumire, cod_piesa, cantitate, pret_unitar, garantie_luni, id
                 FROM ocr_reparatii_piese
                 UNION ALL
                 SELECT reparatie_id, 'manopera', denumire, NULL, norma_ore, pret_ora, garantie_luni, id
                 FROM ocr_reparatii_manopera
             ) c ON c.reparatie_id = r.id
             WHERE $whereSql
             ORDER BY r.data_interventie IS NULL, r.data_interventie DESC, r.id DESC, c.tip_linie, c.id"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ------------------------------------------------------------------
    // API v2: factura = parinte multi-vehicul, articole unificate
    // (ocr_reparatii_articole) + asocieri vehicule (ocr_reparatii_vehicule).
    // ------------------------------------------------------------------

    /** Campurile editabile inline pe factura (parinte). Tip lucrare / KM / vehicul au coborat pe articol. */
    public const INVOICE_FIELDS = [
        'data_interventie' => 'date',
        'document' => 'string',
        'furnizor' => 'string',
        'observatii' => 'text',
    ];

    public const ITEM_FIELDS = [
        'tip' => 'item_tip',
        'denumire' => 'string',
        'cod_piesa' => 'string',
        'cantitate' => 'decimal',
        'pret_unitar' => 'decimal',
        'tip_lucrare' => 'tip',
        'garantie_luni' => 'warranty',
        'garantie_pana_la' => 'date',
        'destinatie' => 'destinatie',
        'vehicle_id' => 'int',
        'data_referinta' => 'date',
        'km_bord' => 'int',
        'depozit' => 'string',
        'cant_alocata' => 'decimal',
    ];

    public const WARRANTY_OPTIONS_V2 = [6, 12, 18, 24, 36];

    /**
     * WHERE + parametri pentru filtre. Filtrul de vehicul acopera facturile
     * multi-vehicul: potriveste orice articol alocat sau asociere explicita.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildInvoiceFilterWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['vehicle_id'])) {
            $where[] = '(EXISTS (SELECT 1 FROM ocr_reparatii_articole a WHERE a.reparatie_id = r.id AND a.vehicle_id = :f_vehicle)
                OR EXISTS (SELECT 1 FROM ocr_reparatii_vehicule rv WHERE rv.reparatie_id = r.id AND rv.vehicle_id = :f_vehicle))';
            $params[':f_vehicle'] = (int) $filters['vehicle_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'r.data_interventie >= :f_from';
            $params[':f_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'r.data_interventie <= :f_to';
            $params[':f_to'] = $filters['date_to'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(r.document LIKE :f_q OR r.furnizor LIKE :f_q OR r.observatii LIKE :f_q
                OR EXISTS (SELECT 1 FROM ocr_reparatii_articole a
                           LEFT JOIN vehicule av ON av.id = a.vehicle_id
                           WHERE a.reparatie_id = r.id
                             AND (a.denumire LIKE :f_q OR a.cod_piesa LIKE :f_q OR av.nr_inmatriculare LIKE :f_q)))';
            $params[':f_q'] = '%' . $filters['q'] . '%';
        }

        return [$where === [] ? '1=1' : implode(' AND ', $where), $params];
    }

    private const ITEM_TOTAL_PIESE_SQL = "(SELECT COALESCE(SUM(a.cantitate * a.pret_unitar), 0)
        FROM ocr_reparatii_articole a WHERE a.reparatie_id = r.id AND a.tip = 'piesa')";
    private const ITEM_TOTAL_MANOPERA_SQL = "(SELECT COALESCE(SUM(a.cantitate * a.pret_unitar), 0)
        FROM ocr_reparatii_articole a WHERE a.reparatie_id = r.id AND a.tip = 'manopera')";

    /**
     * Facturile filtrate + paginate, cu articolele si vehiculele lor.
     *
     * @return array{rows:array<int,array<string,mixed>>,total_count:int,totals:array{piese:float,manopera:float,general:float}}
     */
    public function getInvoiceEvents(array $filters, int $page = 1, int $perPage = 10): array
    {
        [$whereSql, $params] = $this->buildInvoiceFilterWhere($filters);
        $pieseSql = self::ITEM_TOTAL_PIESE_SQL;
        $manoperaSql = self::ITEM_TOTAL_MANOPERA_SQL;

        $aggStmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM($pieseSql), 0) AS total_piese,
                    COALESCE(SUM($manoperaSql), 0) AS total_manopera
             FROM ocr_reparatii r WHERE $whereSql"
        );
        $aggStmt->execute($params);
        $agg = $aggStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalPiese = (float) ($agg['total_piese'] ?? 0);
        $totalManopera = (float) ($agg['total_manopera'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->prepare(
            "SELECT r.*, f.fisier_stocat AS factura_fisier, f.numar_factura,
                    $pieseSql AS total_piese, $manoperaSql AS total_manopera
             FROM ocr_reparatii r
             LEFT JOIN ocr_piese_facturi f ON f.id = r.factura_id
             WHERE $whereSql
             ORDER BY r.data_interventie IS NULL, r.data_interventie DESC, r.id DESC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows !== []) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $itemsByEvent = [];
            $itemsStmt = $this->db->prepare(
                "SELECT a.*, v.nr_inmatriculare AS vehicul
                 FROM ocr_reparatii_articole a
                 LEFT JOIN vehicule v ON v.id = a.vehicle_id
                 WHERE a.reparatie_id IN ($placeholders)
                 ORDER BY a.reparatie_id, a.tip, a.id"
            );
            $itemsStmt->execute($ids);
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
                $itemsByEvent[(int) $item['reparatie_id']][] = $item;
            }

            // Vehiculele facturii: asocieri explicite UNION vehicule din articole.
            $vehStmt = $this->db->prepare(
                "SELECT x.reparatie_id, v.id AS vehicle_id, v.nr_inmatriculare, v.tip_vehicul
                 FROM (
                     SELECT reparatie_id, vehicle_id FROM ocr_reparatii_vehicule WHERE reparatie_id IN ($placeholders)
                     UNION
                     SELECT reparatie_id, vehicle_id FROM ocr_reparatii_articole
                     WHERE reparatie_id IN ($placeholders) AND vehicle_id IS NOT NULL
                 ) x
                 JOIN vehicule v ON v.id = x.vehicle_id
                 ORDER BY v.nr_inmatriculare"
            );
            $vehStmt->execute(array_merge($ids, $ids));
            $vehiclesByEvent = [];
            foreach ($vehStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $veh) {
                $vehiclesByEvent[(int) $veh['reparatie_id']][] = $veh;
            }

            foreach ($rows as &$row) {
                $row['articole'] = $itemsByEvent[(int) $row['id']] ?? [];
                $row['vehicule'] = $vehiclesByEvent[(int) $row['id']] ?? [];
            }
            unset($row);
        }

        return [
            'rows' => $rows,
            'total_count' => (int) ($agg['cnt'] ?? 0),
            'totals' => [
                'piese' => $totalPiese,
                'manopera' => $totalManopera,
                'general' => $totalPiese + $totalManopera,
            ],
        ];
    }

    /** Totalurile recalculate ale unei facturi din articolele unificate. */
    public function getInvoiceTotals(int $eventId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN tip = 'piesa' THEN cantitate * pret_unitar ELSE 0 END), 0) AS piese,
                COALESCE(SUM(CASE WHEN tip = 'manopera' THEN cantitate * pret_unitar ELSE 0 END), 0) AS manopera
             FROM ocr_reparatii_articole WHERE reparatie_id = :id"
        );
        $stmt->execute([':id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $piese = (float) ($row['piese'] ?? 0);
        $manopera = (float) ($row['manopera'] ?? 0);

        return ['piese' => $piese, 'manopera' => $manopera, 'general' => $piese + $manopera];
    }

    public function updateInvoiceField(int $eventId, string $field, ?string $rawValue): ?string
    {
        $normalized = $this->updateChildField('ocr_reparatii', self::INVOICE_FIELDS, $eventId, $field, $rawValue);

        // Data facturii e punctul de start implicit al garantiei pentru articolele
        // fara data proprie: le recalculam pe cele necorectate manual.
        if ($field === 'data_interventie') {
            $this->recalcWarrantiesForInvoiceDate($eventId);
        }

        return $normalized;
    }

    public function addVehicleToInvoice(int $eventId, int $vehicleId): bool
    {
        if ($vehicleId <= 0) {
            throw new InvalidArgumentException('Vehicul invalid.');
        }
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO ocr_reparatii_vehicule (reparatie_id, vehicle_id, created_at)
             VALUES (:event_id, :vehicle_id, :created_at)'
        );
        $stmt->execute([':event_id' => $eventId, ':vehicle_id' => $vehicleId, ':created_at' => date('Y-m-d H:i:s')]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Elimina un vehicul DE PE FACTURA (doar asocierea + rezolvarea articolelor lui).
     * Nu atinge niciodata inregistrarea vehiculului din flota.
     *
     * Moduri:
     *  - remove:       doar daca vehiculul NU are articole pe factura;
     *  - reassign:     muta articolele pe $targetVehicleId, apoi elimina asocierea;
     *  - to_stock:     muta piesele in stoc (fara vehicul); esueaza daca ramane manopera;
     *  - delete_items: sterge articolele vehiculului de pe ACEASTA factura, apoi asocierea.
     *
     * @return array{piese:float,manopera:float,general:float} totalurile facturii dupa operatie
     */
    public function removeVehicleFromInvoice(int $eventId, int $vehicleId, string $mode, ?int $targetVehicleId = null): array
    {
        if ($eventId <= 0 || $vehicleId <= 0) {
            throw new InvalidArgumentException('Factură sau vehicul invalid.');
        }

        $countStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(tip = 'piesa'), 0) AS piese, COALESCE(SUM(tip = 'manopera'), 0) AS manopera
             FROM ocr_reparatii_articole WHERE reparatie_id = :event_id AND vehicle_id = :vehicle_id"
        );
        $countStmt->execute([':event_id' => $eventId, ':vehicle_id' => $vehicleId]);
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: ['piese' => 0, 'manopera' => 0];
        $partCount = (int) $counts['piese'];
        $laborCount = (int) $counts['manopera'];

        $this->db->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            switch ($mode) {
                case 'remove':
                    if ($partCount + $laborCount > 0) {
                        throw new InvalidArgumentException('Vehiculul are articole asociate pe această factură — rezolvă-le întâi (mutare / ștergere).');
                    }
                    break;

                case 'reassign':
                    if ($targetVehicleId === null || $targetVehicleId <= 0 || $targetVehicleId === $vehicleId) {
                        throw new InvalidArgumentException('Alege un alt vehicul valid pentru mutare.');
                    }
                    $this->db->prepare(
                        'UPDATE ocr_reparatii_articole SET vehicle_id = :target, updated_at = :now
                         WHERE reparatie_id = :event_id AND vehicle_id = :vehicle_id'
                    )->execute([':target' => $targetVehicleId, ':now' => $now, ':event_id' => $eventId, ':vehicle_id' => $vehicleId]);
                    $this->db->prepare(
                        'INSERT IGNORE INTO ocr_reparatii_vehicule (reparatie_id, vehicle_id, created_at) VALUES (:e, :v, :c)'
                    )->execute([':e' => $eventId, ':v' => $targetVehicleId, ':c' => $now]);
                    break;

                case 'to_stock':
                    if ($laborCount > 0) {
                        throw new InvalidArgumentException('Manopera nu poate fi mutată în stoc — mut-o pe alt vehicul sau șterge-o întâi.');
                    }
                    $this->db->prepare(
                        "UPDATE ocr_reparatii_articole
                         SET destinatie = 'stoc', vehicle_id = NULL, km_bord = NULL, updated_at = :now
                         WHERE reparatie_id = :event_id AND vehicle_id = :vehicle_id AND tip = 'piesa'"
                    )->execute([':now' => $now, ':event_id' => $eventId, ':vehicle_id' => $vehicleId]);
                    break;

                case 'delete_items':
                    // Sterge DOAR articolele acestui vehicul de pe ACEASTA factura;
                    // istoricul altor facturi si vehiculul din flota raman neatinse.
                    $this->db->prepare(
                        'DELETE FROM ocr_reparatii_articole WHERE reparatie_id = :event_id AND vehicle_id = :vehicle_id'
                    )->execute([':event_id' => $eventId, ':vehicle_id' => $vehicleId]);
                    break;

                default:
                    throw new InvalidArgumentException('Mod de eliminare invalid.');
            }

            $this->db->prepare(
                'DELETE FROM ocr_reparatii_vehicule WHERE reparatie_id = :event_id AND vehicle_id = :vehicle_id'
            )->execute([':event_id' => $eventId, ':vehicle_id' => $vehicleId]);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return $this->getInvoiceTotals($eventId);
    }

    public function addItem(int $eventId, string $tip, ?int $vehicleId): int
    {
        $tip = $tip === 'manopera' ? 'manopera' : 'piesa';
        $now = date('Y-m-d H:i:s');
        // Data de referinta porneste din data facturii (baza corecta pentru garantie).
        $stmt = $this->db->prepare('SELECT data_interventie FROM ocr_reparatii WHERE id = :id');
        $stmt->execute([':id' => $eventId]);
        $invoiceDate = $stmt->fetchColumn();

        $this->db->prepare(
            'INSERT INTO ocr_reparatii_articole
                (reparatie_id, tip, denumire, cantitate, pret_unitar, tip_lucrare, destinatie,
                 vehicle_id, data_referinta, created_at, updated_at)
             VALUES (:event_id, :tip, "", :cantitate, 0, :tip_lucrare, "vehicul",
                 :vehicle_id, :data_ref, :created_at, :updated_at)'
        )->execute([
            ':event_id' => $eventId,
            ':tip' => $tip,
            ':cantitate' => 1,
            ':tip_lucrare' => $tip === 'manopera' ? 'reparatie' : 'inlocuire',
            ':vehicle_id' => $vehicleId !== null && $vehicleId > 0 ? $vehicleId : null,
            ':data_ref' => is_string($invoiceDate) && $invoiceDate !== '' ? $invoiceDate : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function deleteItem(int $itemId): ?int
    {
        return $this->deleteChildRow('ocr_reparatii_articole', $itemId);
    }

    public function getItemEventId(int $itemId): ?int
    {
        $stmt = $this->db->prepare('SELECT reparatie_id FROM ocr_reparatii_articole WHERE id = :id');
        $stmt->execute([':id' => $itemId]);
        $eventId = $stmt->fetchColumn();

        return $eventId === false ? null : (int) $eventId;
    }

    /**
     * Editare inline articol, cu regulile de garantie:
     *  - garantie_pana_la editata direct => override manual (pastrata la recalcul);
     *  - garantie_luni / data_referinta schimbate => recalcul automat daca nu e override;
     *  - startul garantiei = data_referinta ?? data facturii, NICIODATA created_at.
     *
     * @return array{value:?string,garantie_pana_la:?string,garantie_manuala:bool}
     */
    public function updateItemField(int $itemId, string $field, ?string $rawValue): array
    {
        if ($field === 'garantie_pana_la') {
            $value = $rawValue !== null ? trim($rawValue) : '';
            if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw new InvalidArgumentException('Data trebuie să fie în format AAAA-LL-ZZ.');
            }
            // Data aleasa manual devine override; golirea revine la calcul automat.
            $this->db->prepare(
                'UPDATE ocr_reparatii_articole
                 SET garantie_pana_la = :value, garantie_manuala = :manual, updated_at = :updated_at
                 WHERE id = :id'
            )->execute([
                ':value' => $value !== '' ? $value : null,
                ':manual' => $value !== '' ? 1 : 0,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => $itemId,
            ]);
            if ($value === '') {
                $this->recalcItemWarranty($itemId);
            }

            return $this->itemWarrantyState($itemId, $value !== '' ? $value : null);
        }

        $normalized = $this->updateChildField('ocr_reparatii_articole', self::ITEM_FIELDS, $itemId, $field, $rawValue);

        if (in_array($field, ['garantie_luni', 'data_referinta'], true)) {
            $this->recalcItemWarranty($itemId);
        }

        return $this->itemWarrantyState($itemId, $normalized);
    }

    /** @return array{value:?string,garantie_pana_la:?string,garantie_manuala:bool} */
    private function itemWarrantyState(int $itemId, ?string $normalizedValue): array
    {
        $stmt = $this->db->prepare('SELECT garantie_pana_la, garantie_manuala FROM ocr_reparatii_articole WHERE id = :id');
        $stmt->execute([':id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'value' => $normalizedValue,
            'garantie_pana_la' => $row['garantie_pana_la'] ?? null,
            'garantie_manuala' => (bool) ($row['garantie_manuala'] ?? false),
        ];
    }

    /** Recalculeaza garantie_pana_la pentru un articol fara override manual. */
    private function recalcItemWarranty(int $itemId): void
    {
        $stmt = $this->db->prepare(
            'SELECT a.garantie_luni, a.garantie_manuala, a.data_referinta, r.data_interventie
             FROM ocr_reparatii_articole a
             JOIN ocr_reparatii r ON r.id = a.reparatie_id
             WHERE a.id = :id'
        );
        $stmt->execute([':id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['garantie_manuala'] === 1) {
            return;
        }

        $endDate = null;
        $start = $row['data_referinta'] ?? $row['data_interventie'];
        if ($row['garantie_luni'] !== null && is_string($start) && $start !== '') {
            $endDate = date('Y-m-d', strtotime($start . ' +' . (int) $row['garantie_luni'] . ' months'));
        }

        $this->db->prepare(
            'UPDATE ocr_reparatii_articole SET garantie_pana_la = :end, updated_at = :updated_at WHERE id = :id'
        )->execute([':end' => $endDate, ':updated_at' => date('Y-m-d H:i:s'), ':id' => $itemId]);
    }

    /** Dupa schimbarea datei facturii: recalcul pentru articolele fara data proprie si fara override. */
    private function recalcWarrantiesForInvoiceDate(int $eventId): void
    {
        $ids = $this->db->prepare(
            'SELECT id FROM ocr_reparatii_articole
             WHERE reparatie_id = :id AND garantie_manuala = 0 AND data_referinta IS NULL AND garantie_luni IS NOT NULL'
        );
        $ids->execute([':id' => $eventId]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) ?: [] as $itemId) {
            $this->recalcItemWarranty((int) $itemId);
        }
    }

    /**
     * Salvarea OCR v2: O factura parinte + articole unificate + asocieri vehicule.
     *
     * @param array<string,mixed> $header
     * @param array<int,array<string,mixed>> $items {tip, denumire, cod_piesa, cantitate, pret_unitar, tip_lucrare, destinatie, vehicle_id}
     */
    public function saveInvoiceAsEventV2(array $header, array $items, ?int $createdBy): int
    {
        $this->db->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $this->db->prepare(
                'INSERT INTO ocr_piese_facturi
                    (numar_factura, data_facturii, furnizor, cui_furnizor, moneda, total_factura,
                     fisier_original, fisier_stocat, ocr_text, ocr_durata_ms, observatii,
                     created_by, created_at, updated_at)
                 VALUES
                    (:numar, :data, :furnizor, :cui, :moneda, :total,
                     :fisier_original, :fisier_stocat, :ocr_text, :ocr_durata, :observatii,
                     :created_by, :created_at, :updated_at)'
            )->execute([
                ':numar' => self::nullIfEmpty($header['numar_factura'] ?? ''),
                ':data' => self::nullIfEmpty($header['data_facturii'] ?? ''),
                ':furnizor' => self::nullIfEmpty($header['furnizor'] ?? ''),
                ':cui' => self::nullIfEmpty($header['cui_furnizor'] ?? ''),
                ':moneda' => trim((string) ($header['moneda'] ?? 'RON')) ?: 'RON',
                ':total' => isset($header['total_factura']) && $header['total_factura'] !== ''
                    ? (float) $header['total_factura'] : null,
                ':fisier_original' => self::nullIfEmpty($header['fisier_original'] ?? ''),
                ':fisier_stocat' => self::nullIfEmpty($header['fisier_stocat'] ?? ''),
                ':ocr_text' => self::nullIfEmpty($header['ocr_text'] ?? ''),
                ':ocr_durata' => isset($header['ocr_durata_ms']) && $header['ocr_durata_ms'] !== ''
                    ? (int) $header['ocr_durata_ms'] : null,
                ':observatii' => self::nullIfEmpty($header['observatii'] ?? ''),
                ':created_by' => $createdBy,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $invoiceId = (int) $this->db->lastInsertId();

            $document = self::nullIfEmpty($header['numar_factura'] ?? '');
            $invoiceDate = self::nullIfEmpty($header['data_facturii'] ?? '');
            $this->db->prepare(
                'INSERT INTO ocr_reparatii
                    (data_interventie, document, furnizor, observatii, factura_id, created_by, created_at, updated_at)
                 VALUES (:data, :document, :furnizor, :observatii, :factura_id, :created_by, :created_at, :updated_at)'
            )->execute([
                ':data' => $invoiceDate,
                ':document' => $document !== null ? 'Factura ' . $document : null,
                ':furnizor' => self::nullIfEmpty($header['furnizor'] ?? ''),
                ':observatii' => self::nullIfEmpty($header['observatii'] ?? ''),
                ':factura_id' => $invoiceId,
                ':created_by' => $createdBy,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $eventId = (int) $this->db->lastInsertId();

            $kmBord = !empty($header['km_bord']) ? (int) $header['km_bord'] : null;
            $itemStmt = $this->db->prepare(
                'INSERT INTO ocr_reparatii_articole
                    (reparatie_id, tip, denumire, cod_piesa, cantitate, pret_unitar, tip_lucrare,
                     destinatie, vehicle_id, data_referinta, km_bord, created_at, updated_at)
                 VALUES (:event_id, :tip, :denumire, :cod, :cantitate, :pret, :tip_lucrare,
                     :destinatie, :vehicle_id, :data_ref, :km, :created_at, :updated_at)'
            );
            $vehicleIds = [];
            foreach ($items as $item) {
                $vehicleId = !empty($item['vehicle_id']) ? (int) $item['vehicle_id'] : null;
                $destinatie = ($item['destinatie'] ?? 'vehicul') === 'stoc' ? 'stoc' : 'vehicul';
                if ($destinatie === 'stoc') {
                    $vehicleId = null;
                }
                if ($vehicleId !== null) {
                    $vehicleIds[$vehicleId] = true;
                }
                $itemStmt->execute([
                    ':event_id' => $eventId,
                    ':tip' => ($item['tip'] ?? 'piesa') === 'manopera' ? 'manopera' : 'piesa',
                    ':denumire' => mb_substr(trim((string) ($item['denumire'] ?? '')), 0, 255),
                    ':cod' => self::nullIfEmpty($item['cod_piesa'] ?? ''),
                    ':cantitate' => max(0, (float) ($item['cantitate'] ?? 1)),
                    ':pret' => max(0, (float) ($item['pret_unitar'] ?? 0)),
                    ':tip_lucrare' => isset(self::TIP_LUCRARE_OPTIONS[$item['tip_lucrare'] ?? '']) ? (string) $item['tip_lucrare'] : 'reparatie',
                    ':destinatie' => $destinatie,
                    ':vehicle_id' => $vehicleId,
                    ':data_ref' => $invoiceDate,
                    ':km' => $destinatie === 'vehicul' ? $kmBord : null,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }

            $assocStmt = $this->db->prepare(
                'INSERT IGNORE INTO ocr_reparatii_vehicule (reparatie_id, vehicle_id, created_at) VALUES (:e, :v, :c)'
            );
            foreach (array_keys($vehicleIds) as $vehicleId) {
                $assocStmt->execute([':e' => $eventId, ':v' => $vehicleId, ':c' => $now]);
            }

            $this->db->commit();
            return $eventId;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Export aplatizat v2: o linie per articol, cu datele facturii repetate.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getExportRowsV2(array $filters): array
    {
        [$whereSql, $params] = $this->buildInvoiceFilterWhere($filters);
        $stmt = $this->db->prepare(
            "SELECT r.id, r.data_interventie, r.document, r.furnizor, r.observatii,
                    a.tip, a.denumire, a.cod_piesa, a.cantitate, a.pret_unitar, a.tip_lucrare,
                    a.garantie_luni, a.garantie_pana_la, a.destinatie, a.data_referinta,
                    a.km_bord, a.depozit, v.nr_inmatriculare AS vehicul
             FROM ocr_reparatii r
             LEFT JOIN ocr_reparatii_articole a ON a.reparatie_id = r.id
             LEFT JOIN vehicule v ON v.id = a.vehicle_id
             WHERE $whereSql
             ORDER BY r.data_interventie IS NULL, r.data_interventie DESC, r.id DESC, a.tip, a.id"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
