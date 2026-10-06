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
            // Placeholder-e unice: PDO-ul aplicatiei nu emuleaza prepare-urile.
            $where[] = '(r.document LIKE :f_q1 OR r.furnizor LIKE :f_q2 OR r.observatii LIKE :f_q3
                OR v.nr_inmatriculare LIKE :f_q4
                OR EXISTS (SELECT 1 FROM ocr_reparatii_piese p WHERE p.reparatie_id = r.id
                           AND (p.denumire LIKE :f_q5 OR p.cod_piesa LIKE :f_q6))
                OR EXISTS (SELECT 1 FROM ocr_reparatii_manopera m WHERE m.reparatie_id = r.id
                           AND m.denumire LIKE :f_q7))';
            $needle = '%' . $filters['q'] . '%';
            for ($i = 1; $i <= 7; $i++) {
                $params[':f_q' . $i] = $needle;
            }
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

    /**
     * Sterge randul din registru. La o factura scanata pleaca si factura (cheia scanarii),
     * ca aceeasi scanare sa poata fi trimisa din nou. Intoarce fisierul ramas fara
     * nicio factura (de sters de pe disc) sau null.
     */
    public function deleteEvent(int $eventId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT f.id, f.fisier_stocat FROM ocr_reparatii r
             JOIN ocr_piese_facturi f ON f.id = r.factura_id AND f.sursa = 'scan'
             WHERE r.id = :id"
        );
        $stmt->execute([':id' => $eventId]);
        $scan = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->db->prepare('DELETE FROM ocr_reparatii WHERE id = :id')->execute([':id' => $eventId]);
        if ($scan === false) {
            return null;
        }

        $this->db->prepare(
            'DELETE FROM ocr_piese_facturi WHERE id = :id AND NOT EXISTS (SELECT 1 FROM ocr_reparatii WHERE factura_id = :id2)'
        )->execute([':id' => (int) $scan['id'], ':id2' => (int) $scan['id']]);

        $file = (string) ($scan['fisier_stocat'] ?? '');
        if ($file === '') {
            return null;
        }
        $used = $this->db->prepare('SELECT COUNT(*) FROM ocr_piese_facturi WHERE fisier_stocat = :f');
        $used->execute([':f' => $file]);

        return (int) $used->fetchColumn() === 0 ? $file : null;
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

    public const WARRANTY_OPTIONS_V2 = [3, 6, 12, 18, 24, 36];

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

        // PDO-ul aplicatiei foloseste prepare-uri native (EMULATE_PREPARES=false),
        // deci acelasi placeholder NU poate aparea de doua ori - folosim nume unice.
        if (!empty($filters['vehicle_id'])) {
            $where[] = '(EXISTS (SELECT 1 FROM ocr_reparatii_articole a WHERE a.reparatie_id = r.id AND a.vehicle_id = :f_vehicle1)
                OR EXISTS (SELECT 1 FROM ocr_reparatii_vehicule rv WHERE rv.reparatie_id = r.id AND rv.vehicle_id = :f_vehicle2))';
            $params[':f_vehicle1'] = (int) $filters['vehicle_id'];
            $params[':f_vehicle2'] = (int) $filters['vehicle_id'];
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
            $where[] = '(r.document LIKE :f_q1 OR r.furnizor LIKE :f_q2 OR r.observatii LIKE :f_q3
                OR EXISTS (SELECT 1 FROM ocr_reparatii_articole a
                           LEFT JOIN vehicule av ON av.id = a.vehicle_id
                           WHERE a.reparatie_id = r.id
                             AND (a.denumire LIKE :f_q4 OR a.cod_piesa LIKE :f_q5 OR av.nr_inmatriculare LIKE :f_q6)))';
            $needle = '%' . $filters['q'] . '%';
            for ($i = 1; $i <= 6; $i++) {
                $params[':f_q' . $i] = $needle;
            }
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
                    f.status AS scan_status, f.ocr_eroare AS scan_eroare, f.email_subiect AS scan_subiect,
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

        if ($mode !== 'remove' && $partCount + $laborCount > 0) {
            $sentStmt = $this->db->prepare(
                'SELECT COUNT(*) FROM ocr_reparatii_articole
                 WHERE reparatie_id = :event_id AND vehicle_id = :vehicle_id AND mentenanta_trimis_la IS NOT NULL'
            );
            $sentStmt->execute([':event_id' => $eventId, ':vehicle_id' => $vehicleId]);
            if ((int) $sentStmt->fetchColumn() > 0) {
                throw new InvalidArgumentException('Vehiculul are articole trimise deja în Reparații — corecturile se fac acolo.');
            }
        }

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
        $this->assertItemNotSent($itemId);

        return $this->deleteChildRow('ocr_reparatii_articole', $itemId);
    }

    /** @return array<int,int> articolele facturii deja trimise in Reparatii */
    public function getSentItemIds(int $eventId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM ocr_reparatii_articole WHERE reparatie_id = :id AND mentenanta_trimis_la IS NOT NULL'
        );
        $stmt->execute([':id' => $eventId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** Un articol trimis in Reparatii se corecteaza acolo; aici ramane ca istoric. */
    private function assertItemNotSent(int $itemId): void
    {
        $stmt = $this->db->prepare('SELECT mentenanta_trimis_la FROM ocr_reparatii_articole WHERE id = :id');
        $stmt->execute([':id' => $itemId]);
        $sentAt = $stmt->fetchColumn();
        if (is_string($sentAt) && $sentAt !== '') {
            throw new InvalidArgumentException('Articolul a fost trimis în Reparații pe ' . date('d.m.Y', strtotime($sentAt))
                . ' — corecturile se fac acolo.');
        }
    }

    /** Cheile de invatare ale unei piese: codul (cel mai sigur) si denumirea, normalizate. @return array<int,string> */
    public static function learningKeys(string $name, ?string $code): array
    {
        $keys = [];
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', (string) $code) ?? '');
        if (strlen($code) >= 4) {
            $keys[] = 'cod:' . $code;
        }
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($name), 'UTF-8'));
        $normalized = trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii !== false ? $ascii : mb_strtolower($name)) ?? '');
        if (mb_strlen($normalized) >= 3) {
            $keys[] = 'nume:' . mb_substr($normalized, 0, 190);
        }

        return $keys;
    }

    /** Tine minte locul confirmat al unei piese (dupa cod si dupa denumire). */
    public function learnPlacement(string $name, ?string $code, string $componentKey, string $primary): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO ocr_piese_clasificari (cheie, auto_component_key, auto_primary, exemplu, created_at, updated_at)
             VALUES (:cheie, :k, :p, :ex, :c, :u)
             ON DUPLICATE KEY UPDATE
                confirmari = IF(auto_component_key = VALUES(auto_component_key), confirmari + 1, 1),
                auto_component_key = VALUES(auto_component_key), auto_primary = VALUES(auto_primary),
                exemplu = VALUES(exemplu), updated_at = VALUES(updated_at)'
        );
        foreach (self::learningKeys($name, $code) as $key) {
            $stmt->execute([':cheie' => $key, ':k' => $componentKey, ':p' => $primary, ':ex' => mb_substr($name, 0, 255), ':c' => $now, ':u' => $now]);
        }
    }

    /** Articolele clasificate ale unei facturi devin locuri confirmate (la salvare / trimitere). */
    public function learnFromEvent(int $eventId): void
    {
        $stmt = $this->db->prepare(
            "SELECT denumire, cod_piesa, auto_component_key, auto_primary FROM ocr_reparatii_articole
             WHERE reparatie_id = :id AND auto_component_key IS NOT NULL"
        );
        $stmt->execute([':id' => $eventId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $this->learnPlacement((string) $row['denumire'], $row['cod_piesa'], (string) $row['auto_component_key'], (string) ($row['auto_primary'] ?: 'sasiu'));
        }
    }

    /**
     * Componenta propusa pentru un articol citit: locul confirmat anterior pentru aceeasi
     * piesa (dupa cod, apoi dupa denumire). AI-ul doar citeste factura, nu clasifica
     * (decizia utilizatorului 2026-10-05, pentru tokeni); restul alege operatorul.
     *
     * @return array{auto_component_key:?string, auto_primary:?string, auto_sursa:?string}
     */
    public function suggestPlacement(string $name, ?string $code): array
    {
        $keys = self::learningKeys($name, $code);
        if ($keys !== []) {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $this->db->prepare(
                "SELECT cheie, auto_component_key, auto_primary FROM ocr_piese_clasificari WHERE cheie IN ($placeholders)"
            );
            $stmt->execute($keys);
            $found = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $found[$row['cheie']] = $row;
            }
            foreach ($keys as $key) {
                if (isset($found[$key])) {
                    return ['auto_component_key' => $found[$key]['auto_component_key'], 'auto_primary' => $found[$key]['auto_primary'], 'auto_sursa' => 'invatat'];
                }
            }
        }

        return ['auto_component_key' => null, 'auto_primary' => null, 'auto_sursa' => null];
    }

    /**
     * Locul in Reparatii Auto pentru o componenta: categoria si subcategoria vin din
     * catalog; ramura (Sasiu / Rezervor) e cea ceruta daca e compatibila, altfel implicita.
     *
     * @param array<string,array<string,mixed>> $components AutoComponentCatalogService::components()
     * @return array{auto_primary:?string,auto_subcategory:?string,auto_category_id:?int,auto_component_key:?string,auto_component_name:?string}
     */
    private function resolvePlacement(array $components, string $componentKey, string $primary): array
    {
        $component = $components[$componentKey] ?? null;
        if ($component === null) {
            return ['auto_primary' => null, 'auto_subcategory' => null, 'auto_category_id' => null,
                'auto_component_key' => null, 'auto_component_name' => null];
        }

        [$defaultPrimary, $subcategory] = AutoComponentCatalogService::defaultPlacement((int) $component['category_id']);

        return [
            'auto_primary' => AutoComponentCatalogService::isValidPlacement($primary, $subcategory) ? $primary : $defaultPrimary,
            'auto_subcategory' => $subcategory,
            'auto_category_id' => (int) $component['category_id'],
            'auto_component_key' => $componentKey,
            'auto_component_name' => mb_substr((string) $component['name'], 0, 190),
        ];
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
        $this->assertItemNotSent($itemId);

        if ($field === 'auto_component_key' || $field === 'auto_primary') {
            $stmt = $this->db->prepare('SELECT auto_component_key, auto_primary FROM ocr_reparatii_articole WHERE id = :id');
            $stmt->execute([':id' => $itemId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($current === false) {
                throw new InvalidArgumentException('Rândul nu mai există (a fost șters).');
            }
            $value = trim((string) $rawValue);
            $key = $field === 'auto_component_key' ? $value : (string) ($current['auto_component_key'] ?? '');
            $primary = $field === 'auto_primary' ? $value : (string) ($current['auto_primary'] ?? '');
            $components = (new AutoComponentCatalogService())->components();
            if ($key !== '' && !isset($components[$key])) {
                throw new InvalidArgumentException('Componentă necunoscută în Reparații Auto.');
            }
            $placement = $this->resolvePlacement($components, $key, $primary);
            if ($field === 'auto_primary' && $key !== '' && $placement['auto_primary'] !== $value) {
                throw new InvalidArgumentException('Livrare Gaz există doar sub Rezervor.');
            }
            $this->db->prepare(
                'UPDATE ocr_reparatii_articole
                 SET auto_primary = :p, auto_subcategory = :s, auto_category_id = :c,
                     auto_component_key = :k, auto_component_name = :n, auto_sursa = :src, updated_at = :now
                 WHERE id = :id'
            )->execute([
                ':p' => $placement['auto_primary'], ':s' => $placement['auto_subcategory'], ':c' => $placement['auto_category_id'],
                ':k' => $placement['auto_component_key'], ':n' => $placement['auto_component_name'],
                ':src' => $placement['auto_component_key'] !== null ? 'manual' : null,
                ':now' => date('Y-m-d H:i:s'), ':id' => $itemId,
            ]);
            // Corectura operatorului se tine minte pentru facturile urmatoare.
            if ($placement['auto_component_key'] !== null) {
                $nameStmt = $this->db->prepare('SELECT denumire, cod_piesa FROM ocr_reparatii_articole WHERE id = :id');
                $nameStmt->execute([':id' => $itemId]);
                $names = $nameStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $this->learnPlacement((string) ($names['denumire'] ?? ''), $names['cod_piesa'] ?? null,
                    (string) $placement['auto_component_key'], (string) $placement['auto_primary']);
            }
            $placement['auto_sursa'] = $placement['auto_component_key'] !== null ? 'manual' : null;

            return $this->itemWarrantyState($itemId, $field === 'auto_primary' ? $placement['auto_primary'] : $placement['auto_component_key'])
                + ['placement' => $placement];
        }

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

        // Corectia destinatiei ramane posibila si dupa salvare, dar cu reguli:
        // manopera nu poate merge in stoc, iar campurile devenite irelevante se
        // curata atomic (fara sa atingem garantia/pretul/codul/factura - §11).
        if ($field === 'destinatie' && trim((string) $rawValue) === 'stoc') {
            $tipStmt = $this->db->prepare('SELECT tip FROM ocr_reparatii_articole WHERE id = :id');
            $tipStmt->execute([':id' => $itemId]);
            if ($tipStmt->fetchColumn() === 'manopera') {
                throw new InvalidArgumentException('Manopera nu poate fi trimisă în stoc.');
            }
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $normalized = $this->updateChildField('ocr_reparatii_articole', self::ITEM_FIELDS, $itemId, $field, $rawValue);

            if ($field === 'destinatie') {
                if ($normalized === 'stoc') {
                    // Piesa intra in stoc: alocarea pe vehicul si KM-ul nu mai sunt valabile.
                    $this->db->prepare(
                        'UPDATE ocr_reparatii_articole SET vehicle_id = NULL, km_bord = NULL, updated_at = :now WHERE id = :id'
                    )->execute([':now' => date('Y-m-d H:i:s'), ':id' => $itemId]);
                } else {
                    // Piesa iese din stoc: depozitul nu mai este relevant.
                    $this->db->prepare(
                        'UPDATE ocr_reparatii_articole SET depozit = NULL, updated_at = :now WHERE id = :id'
                    )->execute([':now' => date('Y-m-d H:i:s'), ':id' => $itemId]);
                }
            }

            // Alocarea pe un vehicul care nu e inca pe factura creeaza automat
            // asocierea factura<->vehicul (integritate fara pasi manuali - §13).
            if ($field === 'vehicle_id' && $normalized !== null && (int) $normalized > 0) {
                $eventId = $this->getItemEventId($itemId);
                if ($eventId !== null) {
                    $this->db->prepare(
                        'INSERT IGNORE INTO ocr_reparatii_vehicule (reparatie_id, vehicle_id, created_at) VALUES (:e, :v, :c)'
                    )->execute([':e' => $eventId, ':v' => (int) $normalized, ':c' => date('Y-m-d H:i:s')]);
                }
            }

            if (in_array($field, ['garantie_luni', 'data_referinta'], true)) {
                $this->recalcItemWarranty($itemId);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
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
            $this->insertItems($eventId, $items, $invoiceDate, $kmBord, [], $now);

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

    /**
     * Articolele unei facturi + asocierile factura<->vehicul (in tranzactia apelantului).
     * Un articol poate avea garantie_luni si km_bord proprii (citirea automata); altfel
     * km-ul implicit al facturii, doar pentru articolele montate pe vehicul.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<int,int> $extraVehicleIds vehicule de pe factura fara articole alocate
     */
    private function insertItems(int $eventId, array $items, ?string $invoiceDate, ?int $defaultKm, array $extraVehicleIds, string $now): void
    {
        $itemStmt = $this->db->prepare(
            'INSERT INTO ocr_reparatii_articole
                (reparatie_id, tip, denumire, cod_piesa, cantitate, pret_unitar, tip_lucrare,
                 garantie_luni, garantie_pana_la, destinatie, vehicle_id, data_referinta, km_bord,
                 auto_primary, auto_subcategory, auto_category_id, auto_component_key, auto_component_name, auto_sursa,
                 created_at, updated_at)
             VALUES (:event_id, :tip, :denumire, :cod, :cantitate, :pret, :tip_lucrare,
                 :garantie_luni, :garantie_pana_la, :destinatie, :vehicle_id, :data_ref, :km,
                 :auto_primary, :auto_subcategory, :auto_category_id, :auto_component_key, :auto_component_name, :auto_sursa,
                 :created_at, :updated_at)'
        );
        $components = null;
        $vehicleIds = array_fill_keys(array_map('intval', $extraVehicleIds), true);
        foreach ($items as $item) {
            $vehicleId = !empty($item['vehicle_id']) ? (int) $item['vehicle_id'] : null;
            $tip = ($item['tip'] ?? 'piesa') === 'manopera' ? 'manopera' : 'piesa';
            $destinatie = $tip === 'piesa' && ($item['destinatie'] ?? 'vehicul') === 'stoc' ? 'stoc' : 'vehicul';
            if ($destinatie === 'stoc') {
                $vehicleId = null;
            }
            if ($vehicleId !== null) {
                $vehicleIds[$vehicleId] = true;
            }
            $km = array_key_exists('km_bord', $item) && $item['km_bord'] !== null ? (int) $item['km_bord'] : $defaultKm;
            $warranty = isset($item['garantie_luni']) && in_array((int) $item['garantie_luni'], self::WARRANTY_OPTIONS_V2, true)
                ? (int) $item['garantie_luni'] : null;
            $components ??= (new AutoComponentCatalogService())->components();
            $placement = $this->resolvePlacement($components, (string) ($item['auto_component_key'] ?? ''), (string) ($item['auto_primary'] ?? ''));

            $itemStmt->execute([
                ':event_id' => $eventId,
                ':tip' => $tip,
                ':denumire' => mb_substr(trim((string) ($item['denumire'] ?? '')), 0, 255),
                ':cod' => self::nullIfEmpty($item['cod_piesa'] ?? ''),
                ':cantitate' => max(0, (float) ($item['cantitate'] ?? 1)),
                ':pret' => max(0, (float) ($item['pret_unitar'] ?? 0)),
                ':tip_lucrare' => isset(self::TIP_LUCRARE_OPTIONS[$item['tip_lucrare'] ?? '']) ? (string) $item['tip_lucrare'] : 'reparatie',
                ':garantie_luni' => $warranty,
                // Startul garantiei = data facturii; fara data, se calculeaza cand o completeaza operatorul.
                ':garantie_pana_la' => $warranty !== null && $invoiceDate !== null
                    ? date('Y-m-d', strtotime($invoiceDate . ' +' . $warranty . ' months')) : null,
                ':destinatie' => $destinatie,
                ':vehicle_id' => $vehicleId,
                ':data_ref' => $invoiceDate,
                ':km' => $destinatie === 'vehicul' ? $km : null,
                ':auto_primary' => $placement['auto_primary'],
                ':auto_subcategory' => $placement['auto_subcategory'],
                ':auto_category_id' => $placement['auto_category_id'],
                ':auto_component_key' => $placement['auto_component_key'],
                ':auto_component_name' => $placement['auto_component_name'],
                ':auto_sursa' => $placement['auto_component_key'] !== null ? ($item['auto_sursa'] ?? 'manual') : null,
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
    }

    // ------------------------------------------------------------------
    // Facturi scanate: imprimanta -> Gmail -> Claude (scripts/process_invoice_inbox.php,
    // OcrPartsScanService). Scanarea intra imediat in registru ("Se citește"), iar
    // citirea completeaza antetul si articolele si o lasa "De verificat".
    // ------------------------------------------------------------------

    public const SCAN_MAX_ATTEMPTS = 3;

    public const SCAN_STATUSES = [
        'in_procesare' => 'Se citește',
        'de_verificat' => 'De verificat',
        'eroare' => 'Citire eșuată',
        'verificata' => 'Verificată',
    ];

    /** Coloanele pentru scanari pe ocr_piese_facturi (idempotent; DDL = COMMIT implicit). */
    public function ensureScanSchema(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $existing = $this->db->query('SHOW COLUMNS FROM ocr_piese_facturi')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $columns = [
            'sursa' => "VARCHAR(20) NOT NULL DEFAULT 'manual'",
            'sursa_key' => 'VARCHAR(120) NULL',
            'status' => 'VARCHAR(20) NULL',
            'document_mime' => 'VARCHAR(100) NULL',
            'document_pagini' => 'VARCHAR(20) NULL',
            'email_subiect' => 'VARCHAR(255) NULL',
            'email_primit_la' => 'DATETIME NULL',
            'ocr_model' => 'VARCHAR(60) NULL',
            'ocr_incercari' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'ocr_eroare' => 'VARCHAR(255) NULL',
        ];
        $clauses = [];
        foreach ($columns as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $clauses[] = "ADD COLUMN $column $definition";
            }
        }
        if (!in_array('sursa_key', $existing, true)) {
            $clauses[] = 'ADD UNIQUE KEY uq_ocr_pf_sursa_key (sursa_key)';
            $clauses[] = 'ADD KEY idx_ocr_pf_status (sursa, status)';
        }
        if ($clauses !== []) {
            $this->db->exec('ALTER TABLE ocr_piese_facturi ' . implode(', ', $clauses));
        }

        // Articole: locul in Reparatii Auto (ramura > subcategorie > categorie > componenta)
        // si ce s-a scris acolo la trimitere (idempotent: un articol se trimite o singura data).
        $itemColumns = $this->db->query('SHOW COLUMNS FROM ocr_reparatii_articole')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $itemClauses = [];
        foreach ([
            'auto_primary' => 'VARCHAR(20) NULL',
            'auto_subcategory' => 'VARCHAR(20) NULL',
            'auto_category_id' => 'TINYINT UNSIGNED NULL',
            'auto_component_key' => 'VARCHAR(10) NULL',
            'auto_component_name' => 'VARCHAR(190) NULL',
            'mentenanta_id' => 'INT UNSIGNED NULL',
            'mentenanta_utilizare_id' => 'INT UNSIGNED NULL',
            'mentenanta_piesa_id' => 'INT UNSIGNED NULL',
            'mentenanta_stoc_cant' => 'DECIMAL(10,2) NULL',
            'mentenanta_trimis_la' => 'DATETIME NULL',
            // De unde vine componenta: invatat (din alegerile anterioare) / manual.
            'auto_sursa' => 'VARCHAR(12) NULL',
        ] as $column => $definition) {
            if (!in_array($column, $itemColumns, true)) {
                $itemClauses[] = "ADD COLUMN $column $definition";
            }
        }
        if ($itemClauses !== []) {
            $this->db->exec('ALTER TABLE ocr_reparatii_articole ' . implode(', ', $itemClauses));
        }

        // Locurile confirmate de operator (corectate sau trimise in Reparatii), dupa codul
        // si dupa denumirea piesei: aceeasi piesa pe o factura noua se plaseaza la fel, fara AI.
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ocr_piese_clasificari (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cheie VARCHAR(200) NOT NULL,
                auto_component_key VARCHAR(10) NOT NULL,
                auto_primary VARCHAR(20) NOT NULL,
                exemplu VARCHAR(255) NULL,
                confirmari INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_ocr_pc_cheie (cheie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $ensured = true;
    }

    /** @return array<string,mixed>|null */
    public function findScanBySourceKey(string $sourceKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ocr_piese_facturi WHERE sursa_key = :k');
        $stmt->execute([':k' => $sourceKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Scanare noua: factura "in procesare" + randul ei din registru (gol pana la citire).
     *
     * @param array{sursa_key:string,fisier_original:?string,fisier_stocat:string,document_mime:string,email_subiect:?string,email_primit_la:?string} $data
     * @return int id-ul din ocr_piese_facturi
     */
    public function createScanEntry(array $data): int
    {
        // Tranzactie proprie doar daca apelantul nu are deja una (testele ruleaza intr-una).
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $now = date('Y-m-d H:i:s');
            $this->db->prepare(
                "INSERT INTO ocr_piese_facturi
                    (sursa, sursa_key, status, moneda, fisier_original, fisier_stocat, document_mime,
                     email_subiect, email_primit_la, created_at, updated_at)
                 VALUES ('scan', :sursa_key, 'in_procesare', 'RON', :fisier_original, :fisier_stocat, :mime,
                     :subiect, :primit, :created_at, :updated_at)"
            )->execute([
                ':sursa_key' => $data['sursa_key'],
                ':fisier_original' => self::nullIfEmpty(mb_substr((string) ($data['fisier_original'] ?? ''), 0, 255)),
                ':fisier_stocat' => $data['fisier_stocat'],
                ':mime' => $data['document_mime'],
                ':subiect' => self::nullIfEmpty(mb_substr((string) ($data['email_subiect'] ?? ''), 0, 255)),
                ':primit' => $data['email_primit_la'] ?? null,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $invoiceId = (int) $this->db->lastInsertId();
            $this->createScanEvent($invoiceId, 'Scanare primită pe email; se citește automat.', $now);

            if ($own) {
                $this->db->commit();
            }
            return $invoiceId;
        } catch (Throwable $exception) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function createScanEvent(int $invoiceId, string $note, string $now): int
    {
        $this->db->prepare(
            'INSERT INTO ocr_reparatii (factura_id, observatii, created_at, updated_at) VALUES (:f, :o, :c, :u)'
        )->execute([':f' => $invoiceId, ':o' => $note, ':c' => $now, ':u' => $now]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<int,array<string,mixed>> scanarile care asteapta citirea (cele mai vechi intai) */
    public function getPendingScans(int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM ocr_piese_facturi
             WHERE sursa = 'scan' AND status = 'in_procesare' AND ocr_incercari < :max
             ORDER BY id ASC LIMIT :lim"
        );
        $stmt->bindValue(':max', self::SCAN_MAX_ATTEMPTS, PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Numerele flotei, pentru indiciul trimis la citire. @return array<int,string> */
    public function getFleetPlates(): array
    {
        return array_map(static fn (array $row): string => (string) $row['nr_inmatriculare'], $this->getVehicleOptions());
    }

    /** @return array<string,int> cheie PartsInvoiceOcrService::plateKey() -> vehicle_id */
    public function vehicleIdsByPlateKey(): array
    {
        $map = [];
        foreach ($this->getVehicleOptions() as $vehicle) {
            $key = PartsInvoiceOcrService::plateKey((string) $vehicle['nr_inmatriculare']);
            if ($key !== '') {
                $map[$key] = (int) $vehicle['id'];
            }
        }

        return $map;
    }

    /**
     * Aplica citirea pe o scanare: primul document completeaza randul scanarii, fiecare
     * document in plus din aceeasi scanare devine o factura noua (acelasi fisier).
     * Vehiculele se recunosc dupa numarul de inmatriculare; ce nu e pe factura ramane gol.
     *
     * @param array<int,array<string,mixed>> $invoices PartsInvoiceOcrService::normalize()
     * @param array{model?:string,raw?:string} $meta
     * @return array<int,int> id-urile din registru (ocr_reparatii) completate / create
     */
    public function applyScanResult(int $invoiceId, array $invoices, array $meta = []): array
    {
        $stmt = $this->db->prepare("SELECT * FROM ocr_piese_facturi WHERE id = :id AND sursa = 'scan'");
        $stmt->execute([':id' => $invoiceId]);
        $scan = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($scan === false) {
            return [];
        }

        if ($invoices === []) {
            $this->recordScanFailure($invoiceId, 'Nu am găsit nicio factură în scanare.', true);
            $eventId = $this->findEventIdForInvoice($invoiceId);
            return $eventId !== null ? [$eventId] : [];
        }

        $plateMap = $this->vehicleIdsByPlateKey();
        $now = date('Y-m-d H:i:s');
        $eventIds = [];

        // Tranzactie proprie doar daca apelantul nu are deja una (testele ruleaza intr-una).
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            foreach (array_values($invoices) as $index => $invoice) {
                $targetInvoiceId = $invoiceId;
                if ($index > 0) {
                    $sourceKey = $scan['sursa_key'] . ':' . ($index + 1);
                    $existing = $this->findScanBySourceKey($sourceKey);
                    if ($existing !== null) {
                        // Rerulare dupa un esec partial: documentul exista deja.
                        $existingEvent = $this->findEventIdForInvoice((int) $existing['id']);
                        if ($existingEvent !== null) {
                            $eventIds[] = $existingEvent;
                        }
                        continue;
                    }
                    $this->db->prepare(
                        "INSERT INTO ocr_piese_facturi
                            (sursa, sursa_key, status, moneda, fisier_original, fisier_stocat, document_mime,
                             email_subiect, email_primit_la, created_at, updated_at)
                         VALUES ('scan', :sursa_key, 'in_procesare', 'RON', :fisier_original, :fisier_stocat, :mime,
                             :subiect, :primit, :created_at, :updated_at)"
                    )->execute([
                        ':sursa_key' => $sourceKey,
                        ':fisier_original' => $scan['fisier_original'],
                        ':fisier_stocat' => $scan['fisier_stocat'],
                        ':mime' => $scan['document_mime'],
                        ':subiect' => $scan['email_subiect'],
                        ':primit' => $scan['email_primit_la'],
                        ':created_at' => $now,
                        ':updated_at' => $now,
                    ]);
                    $targetInvoiceId = (int) $this->db->lastInsertId();
                }

                $eventIds[] = $this->fillScanInvoice($targetInvoiceId, $invoice, $plateMap, $meta, count($invoices), $now);
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

        return $eventIds;
    }

    /**
     * Completeaza o factura scanata si randul ei din registru cu un document citit.
     *
     * @param array<string,mixed> $invoice
     * @param array<string,int> $plateMap
     * @param array{model?:string,raw?:string} $meta
     */
    private function fillScanInvoice(int $invoiceId, array $invoice, array $plateMap, array $meta, int $documentCount, string $now): int
    {
        $invoiceDate = $invoice['data_document'] ?? null;
        $notes = [];

        // Vehiculele de pe factura: doar cele din flota; restul raman ca nota pentru operator.
        $invoiceVehicleIds = [];
        $unknownPlates = [];
        foreach ($invoice['nr_inmatriculare'] ?? [] as $plate) {
            $vehicleId = $plateMap[PartsInvoiceOcrService::plateKey((string) $plate)] ?? null;
            if ($vehicleId !== null) {
                $invoiceVehicleIds[$vehicleId] = $vehicleId;
            } else {
                $unknownPlates[(string) $plate] = true;
            }
        }
        $singleVehicleId = count($invoiceVehicleIds) === 1 ? (int) reset($invoiceVehicleIds) : null;

        $items = [];
        $itemsTotal = 0.0;
        $unverified = 0;
        $warrantyNotes = [];
        foreach ($invoice['articole'] ?? [] as $item) {
            $vehicleId = null;
            if (!empty($item['nr_inmatriculare'])) {
                $vehicleId = $plateMap[PartsInvoiceOcrService::plateKey((string) $item['nr_inmatriculare'])] ?? null;
                if ($vehicleId === null) {
                    $unknownPlates[(string) $item['nr_inmatriculare']] = true;
                }
            }
            $vehicleId ??= $singleVehicleId;
            $warranty = $item['garantie_luni'] ?? null;
            if ($warranty !== null && !in_array((int) $warranty, self::WARRANTY_OPTIONS_V2, true)) {
                $warrantyNotes[] = $item['denumire'] . ': ' . (int) $warranty . ' luni';
            }

            $items[] = [
                'tip' => $item['tip'] ?? 'piesa',
                'denumire' => $item['denumire'] ?? '',
                'cod_piesa' => $item['cod_piesa'] ?? '',
                'cantitate' => $item['cantitate'] ?? 1,
                'pret_unitar' => $item['pret_unitar'] ?? 0,
                'tip_lucrare' => $item['tip_lucrare'] ?? null,
                'garantie_luni' => $warranty,
                'destinatie' => !empty($item['pentru_stoc']) ? 'stoc' : 'vehicul',
                'vehicle_id' => $vehicleId,
                // Km pe articol (facturi multi-vehicul) sau km-ul facturii cand e un singur vehicul.
                'km_bord' => $item['km_bord'] ?? (count($invoiceVehicleIds) <= 1 ? ($invoice['km_bord'] ?? null) : null),
            // Componenta: doar din locurile confirmate anterior (citirea AI nu clasifica).
            ] + $this->suggestPlacement((string) ($item['denumire'] ?? ''), $item['cod_piesa'] ?? null);
            $itemsTotal += (float) ($item['cantitate'] ?? 1) * (float) ($item['pret_unitar'] ?? 0);
            if (($item['verificat'] ?? true) === false) {
                $unverified++;
            }
        }

        if ($invoice['observatii'] ?? null) {
            $notes[] = (string) $invoice['observatii'];
        }
        if ($unknownPlates !== []) {
            $notes[] = 'Nr. de pe factură care nu sunt în flotă: ' . implode(', ', array_keys($unknownPlates));
        }
        if ($items !== [] && array_filter($items, static fn (array $i): bool => empty($i['vehicle_id']) && $i['destinatie'] === 'vehicul') !== []) {
            $notes[] = 'Unele articole nu au vehicul (nu reiese de pe factură) — alocă-le din panoul facturii.';
        }
        if ($unverified > 0) {
            $notes[] = $unverified . ' articol(e) unde cantitate × preț ≠ valoarea de pe factură';
        }
        $netTotal = $invoice['valoare_fara_tva'] ?? null;
        if ($items !== [] && $netTotal !== null && abs($itemsTotal - (float) $netTotal) > 0.05) {
            $notes[] = sprintf('Suma articolelor %s ≠ total fără TVA %s', number_format($itemsTotal, 2, ',', '.'), number_format((float) $netTotal, 2, ',', '.'));
        }
        if ($warrantyNotes !== []) {
            $notes[] = 'Garanții nestandard (completează manual): ' . implode('; ', $warrantyNotes);
        }
        if ($items === []) {
            $notes[] = 'Nu am putut citi articolele — completează-le manual.';
        }
        if ($documentCount > 1 && !empty($invoice['pagini'])) {
            $notes[] = 'Paginile ' . $invoice['pagini'] . ' din scanare';
        }
        $notes[] = 'Citită automat, încredere ' . ($invoice['incredere'] ?? 'necunoscută');

        $this->db->prepare(
            "UPDATE ocr_piese_facturi
             SET numar_factura = :numar, data_facturii = :data, furnizor = :furnizor, cui_furnizor = :cui,
                 moneda = :moneda, total_factura = :total, ocr_text = :raw, ocr_model = :model,
                 document_pagini = :pagini, observatii = :observatii, status = 'de_verificat',
                 ocr_incercari = ocr_incercari + 1, ocr_eroare = NULL, updated_at = :now
             WHERE id = :id"
        )->execute([
            ':numar' => $invoice['numar_document'] ?? null,
            ':data' => $invoiceDate,
            ':furnizor' => $invoice['furnizor'] ?? null,
            ':cui' => $invoice['cui_furnizor'] ?? null,
            ':moneda' => $invoice['moneda'] ?? 'RON',
            ':total' => $invoice['valoare_cu_tva'] ?? null,
            ':raw' => json_encode($invoice, JSON_UNESCAPED_UNICODE),
            ':model' => isset($meta['model']) ? mb_substr((string) $meta['model'], 0, 60) : null,
            ':pagini' => $invoice['pagini'] ?? null,
            ':observatii' => $invoice['observatii'] ?? null,
            ':now' => $now,
            ':id' => $invoiceId,
        ]);

        $eventId = $this->findEventIdForInvoice($invoiceId);
        if ($eventId === null) {
            $eventId = $this->createScanEvent($invoiceId, '', $now);
        }
        // Rerulare: articolele / asocierile anterioare ale acestei scanari se inlocuiesc.
        $this->db->prepare('DELETE FROM ocr_reparatii_articole WHERE reparatie_id = :id')->execute([':id' => $eventId]);
        $this->db->prepare('DELETE FROM ocr_reparatii_vehicule WHERE reparatie_id = :id')->execute([':id' => $eventId]);

        $this->db->prepare(
            'UPDATE ocr_reparatii
             SET data_interventie = :data, document = :document, furnizor = :furnizor, observatii = :observatii, updated_at = :now
             WHERE id = :id'
        )->execute([
            ':data' => $invoiceDate,
            ':document' => !empty($invoice['numar_document']) ? mb_substr('Factura ' . $invoice['numar_document'], 0, 120) : null,
            ':furnizor' => $invoice['furnizor'] ?? null,
            ':observatii' => mb_substr(implode(' | ', $notes), 0, 2000),
            ':now' => $now,
            ':id' => $eventId,
        ]);

        $this->insertItems($eventId, $items, $invoiceDate, null, array_values($invoiceVehicleIds), $now);

        return $eventId;
    }

    private function findEventIdForInvoice(int $invoiceId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM ocr_reparatii WHERE factura_id = :id ORDER BY id LIMIT 1');
        $stmt->execute([':id' => $invoiceId]);
        $eventId = $stmt->fetchColumn();

        return $eventId === false ? null : (int) $eventId;
    }

    /**
     * Esec la citire: ramane "Se citește" (reincercare la rularea urmatoare) pana la
     * SCAN_MAX_ATTEMPTS sau, la o eroare definitiva, trece in "Citire eșuată".
     */
    public function recordScanFailure(int $invoiceId, string $message, bool $permanent): void
    {
        $stmt = $this->db->prepare('SELECT ocr_incercari FROM ocr_piese_facturi WHERE id = :id');
        $stmt->execute([':id' => $invoiceId]);
        $attempts = $stmt->fetchColumn();
        if ($attempts === false) {
            return;
        }

        $attempts = (int) $attempts + 1;
        $final = $permanent || $attempts >= self::SCAN_MAX_ATTEMPTS;
        $this->db->prepare(
            'UPDATE ocr_piese_facturi SET ocr_incercari = :n, status = :status, ocr_eroare = :err, updated_at = :now WHERE id = :id'
        )->execute([
            ':n' => $final ? max($attempts, self::SCAN_MAX_ATTEMPTS) : $attempts,
            ':status' => $final ? 'eroare' : 'in_procesare',
            ':err' => mb_substr($message, 0, 255),
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $invoiceId,
        ]);

        if ($final) {
            $this->db->prepare('UPDATE ocr_reparatii SET observatii = :o, updated_at = :now WHERE factura_id = :id')->execute([
                ':o' => 'Citirea automată a eșuat: ' . $message . ' Completează datele manual din factura atașată.',
                ':now' => date('Y-m-d H:i:s'),
                ':id' => $invoiceId,
            ]);
        }
    }

    /** "De verificat" / "Citire eșuată" -> "Verificată" (operatorul a confirmat datele). */
    public function markScanVerified(int $eventId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE ocr_piese_facturi f
             JOIN ocr_reparatii r ON r.factura_id = f.id
             SET f.status = 'verificata', f.updated_at = :now
             WHERE r.id = :id AND f.status IN ('de_verificat', 'eroare')"
        );
        $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $eventId]);

        return $stmt->rowCount() > 0;
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
