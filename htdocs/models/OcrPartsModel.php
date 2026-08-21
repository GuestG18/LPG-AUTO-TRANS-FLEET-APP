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
