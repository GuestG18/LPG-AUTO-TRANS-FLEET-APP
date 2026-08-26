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

    private static function nullIfEmpty(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
