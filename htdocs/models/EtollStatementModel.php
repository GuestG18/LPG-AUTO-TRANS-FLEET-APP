<?php
declare(strict_types=1);

/**
 * Situatia eToll (?page=etoll): registrul portofelului (alimentari si sesiuni), documentele
 * fiecarei sesiuni (factura CC-P si anexa TA_10 din "Plăți", desfasuratorul din "Servicii"), facturile de
 * alimentare si verificarea soldului. Datele vin din scripts/sync_etoll_situatie.php
 * (EtollStatementSyncService); pagina doar citeste.
 *
 * Separat de importul eToll -> Facturi -> cursa (EtollImportService): de acolo se citeste
 * doar legatura sesiune -> document CC-P (etoll_sesiuni), nimic nu se scrie inapoi.
 *
 * Tabele (copia de referinta: database/migrations/2026_11_05_000001_etoll_situatie.sql):
 *   etoll_tranzactii          fiecare miscare a portofelului: alimentare (+) sau sesiune (-)
 *   etoll_documente           pe sesiune: ore, origine/destinatie, km, total eToll, cele trei PDF-uri
 *   etoll_alimentari_facturi  facturile alimentarilor (EUPLATESC), cu PDF
 *   etoll_sold                soldul raportat de eToll la fiecare sincronizare, langa cel calculat
 */
class EtollStatementModel
{
    /** Diferentele sub un ban sunt rotunjiri. */
    public const TOLERANCE = 0.01;

    /** Documentele unei sesiuni: cheie => [eticheta, folderul din arhiva ZIP, pagina eToll]. */
    public const DOCUMENTS = [
        'factura' => ['Factură', 'facturi', 'Plăți'],
        'anexa' => ['Anexă', 'anexe', 'Plăți'],
        'desfasurator' => ['Desfășurător', 'desfasuratoare', 'Servicii'],
    ];

    /**
     * Documentele care intra in situatie (pagina, verificare, export), din ETOLL_SITUATIE_DOCUMENTE
     * (ex. "factura,anexa,desfasurator"). Implicit factura CC-P si anexa. Sincronizarea le descarca
     * oricum pe toate trei, ca un document reactivat sa fie gata si pentru sesiunile vechi.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function documentKinds(): array
    {
        $raw = getenv('ETOLL_SITUATIE_DOCUMENTE');
        $wanted = is_string($raw) && trim($raw) !== '' ? preg_split('/[\s,]+/', strtolower(trim($raw))) : ['factura', 'anexa'];
        $kinds = array_intersect_key(self::DOCUMENTS, array_flip($wanted ?: []));

        return $kinds !== [] ? $kinds : array_intersect_key(self::DOCUMENTS, array_flip(['factura', 'anexa']));
    }

    public function __construct(private PDO $db)
    {
    }

    public static function ensureSchema(PDO $db): void
    {
        foreach (self::schemaStatements() as $sql) {
            $db->exec($sql);
        }

        // Tabelele create inainte de factura CC-P pe sesiune.
        $hasInvoice = $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'etoll_documente' AND COLUMN_NAME = 'factura_path'
        ")->fetchColumn();
        if ((int) $hasInvoice === 0) {
            $db->exec('ALTER TABLE etoll_documente ADD COLUMN factura_path VARCHAR(255) NULL AFTER total_etoll');
        }
    }

    /** @return array<int, string> */
    public static function schemaStatements(): array
    {
        $table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return [
            "CREATE TABLE IF NOT EXISTS etoll_tranzactii (
                tx_id VARCHAR(80) NOT NULL PRIMARY KEY,
                tip ENUM('alimentare', 'sesiune') NOT NULL,
                suma DECIMAL(12,2) NOT NULL,
                data DATETIME NOT NULL,
                in_curs TINYINT(1) NOT NULL DEFAULT 0,
                nr_inmatriculare VARCHAR(20) NULL,
                remorci VARCHAR(100) NULL,
                session_id VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_etoll_tranzactii_data (tip, data),
                KEY idx_etoll_tranzactii_session (session_id)
            ) $table",
            "CREATE TABLE IF NOT EXISTS etoll_documente (
                session_id VARCHAR(64) NOT NULL PRIMARY KEY,
                invoice_id VARCHAR(64) NULL,
                annex_id VARCHAR(64) NULL,
                numar_document VARCHAR(60) NULL,
                inceput DATETIME NULL,
                sfarsit DATETIME NULL,
                origine VARCHAR(255) NULL,
                destinatie VARCHAR(255) NULL,
                km_tarifabil DECIMAL(10,2) NULL,
                total_etoll DECIMAL(12,2) NULL,
                factura_path VARCHAR(255) NULL,
                anexa_path VARCHAR(255) NULL,
                desfasurator_path VARCHAR(255) NULL,
                incercari INT UNSIGNED NOT NULL DEFAULT 0,
                eroare VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) $table",
            "CREATE TABLE IF NOT EXISTS etoll_alimentari_facturi (
                invoice_id VARCHAR(64) NOT NULL PRIMARY KEY,
                numar_document VARCHAR(60) NULL,
                data DATETIME NOT NULL,
                total DECIMAL(12,2) NOT NULL,
                pdf_path VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                KEY idx_etoll_alimentari_facturi_data (data)
            ) $table",
            "CREATE TABLE IF NOT EXISTS etoll_sold (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                verificat_la DATETIME NOT NULL,
                sold_etoll DECIMAL(12,2) NOT NULL,
                alimentari DECIMAL(12,2) NOT NULL,
                consum_inchis DECIMAL(12,2) NOT NULL,
                consum_in_curs DECIMAL(12,2) NOT NULL,
                sesiuni_in_curs INT UNSIGNED NOT NULL,
                KEY idx_etoll_sold_verificat (verificat_la)
            ) $table",
        ];
    }

    // -------------------------------------------------------------------------
    // Perioada
    // -------------------------------------------------------------------------

    /**
     * Intervalul ales in pagina: luna (YYYY-MM), an (YYYY) sau interval (de_la / pana_la).
     *
     * @return array{tip: string, de_la: string, pana_la: string, eticheta: string, luna: string, an: string}
     */
    public static function period(array $query): array
    {
        $type = in_array($query['perioada'] ?? '', ['luna', 'an', 'interval'], true) ? (string) $query['perioada'] : 'luna';
        $month = preg_match('/^\d{4}-\d{2}$/', (string) ($query['luna'] ?? '')) === 1 ? (string) $query['luna'] : date('Y-m');
        $year = preg_match('/^\d{4}$/', (string) ($query['an'] ?? '')) === 1 ? (string) $query['an'] : date('Y');
        $months = ['', 'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie'];

        if ($type === 'an') {
            return ['tip' => 'an', 'de_la' => "$year-01-01", 'pana_la' => "$year-12-31", 'eticheta' => "anul $year", 'luna' => $month, 'an' => $year];
        }
        if ($type === 'interval') {
            $from = self::validDate($query['de_la'] ?? null) ?? date('Y-m-01');
            $to = self::validDate($query['pana_la'] ?? null) ?? date('Y-m-d');
            if ($to < $from) {
                [$from, $to] = [$to, $from];
            }

            return ['tip' => 'interval', 'de_la' => $from, 'pana_la' => $to, 'eticheta' => date('d.m.Y', strtotime($from)) . ' – ' . date('d.m.Y', strtotime($to)), 'luna' => $month, 'an' => $year];
        }

        $start = $month . '-01';

        return ['tip' => 'luna', 'de_la' => $start, 'pana_la' => date('Y-m-t', strtotime($start)), 'eticheta' => $months[(int) substr($month, 5, 2)] . ' ' . substr($month, 0, 4), 'luna' => $month, 'an' => $year];
    }

    private static function validDate(mixed $value): ?string
    {
        $value = (string) $value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    // -------------------------------------------------------------------------
    // Situatia unei perioade
    // -------------------------------------------------------------------------

    /**
     * Soldul la inceput, alimentarile, consumul (sesiunile inchise, dupa ora inchiderii) si
     * soldul la final, plus verificarile perioadei.
     *
     * Soldul se calculeaza din tot istoricul (contul a pornit cu 0): sold = alimentari - consum.
     *
     * @return array<string, mixed>
     */
    public function summary(string $from, string $to): array
    {
        $start = $from . ' 00:00:00';
        $end = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';

        $sum = function (string $sql, array $params): array {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            return ['n' => (int) ($row['n'] ?? 0), 'suma' => round((float) ($row['suma'] ?? 0), 2)];
        };

        $topupsBefore = $sum("SELECT COUNT(*) n, SUM(suma) suma FROM etoll_tranzactii WHERE tip = 'alimentare' AND data < :s", [':s' => $start]);
        $usageBefore = $sum("SELECT COUNT(*) n, SUM(suma) suma FROM etoll_tranzactii WHERE tip = 'sesiune' AND in_curs = 0 AND data < :s", [':s' => $start]);
        $topups = $sum("SELECT COUNT(*) n, SUM(suma) suma FROM etoll_tranzactii WHERE tip = 'alimentare' AND data >= :s AND data < :e", [':s' => $start, ':e' => $end]);
        $usage = $sum("SELECT COUNT(*) n, SUM(suma) suma FROM etoll_tranzactii WHERE tip = 'sesiune' AND in_curs = 0 AND data >= :s AND data < :e", [':s' => $start, ':e' => $end]);
        $topupInvoices = $sum('SELECT COUNT(*) n, SUM(total) suma FROM etoll_alimentari_facturi WHERE data >= :s AND data < :e', [':s' => $start, ':e' => $end]);

        $docs = $this->db->prepare("
            SELECT
                SUM(" . implode(' AND ', array_map(static fn(string $kind): string => "d.{$kind}_path IS NOT NULL", array_keys(self::documentKinds()))) . ") complete,
                SUM(d.factura_path IS NULL) fara_factura,
                SUM(d.anexa_path IS NULL) fara_anexa,
                SUM(d.desfasurator_path IS NULL) fara_desfasurator,
                SUM(d.total_etoll IS NOT NULL AND ABS(d.total_etoll - t.suma) > :tol) suma_diferita
            FROM etoll_tranzactii t
            LEFT JOIN etoll_documente d ON d.session_id = t.session_id
            WHERE t.tip = 'sesiune' AND t.in_curs = 0 AND t.data >= :s AND t.data < :e
        ");
        $docs->execute([':s' => $start, ':e' => $end, ':tol' => self::TOLERANCE]);
        $docRow = $docs->fetch(PDO::FETCH_ASSOC) ?: [];

        $opening = round($topupsBefore['suma'] - $usageBefore['suma'], 2);
        $closing = round($opening + $topups['suma'] - $usage['suma'], 2);
        $coverage = $this->db->query("SELECT MIN(data) FROM etoll_tranzactii")->fetchColumn();

        return [
            'sold_inceput' => $opening,
            'alimentari' => $topups,
            'consum' => $usage,
            'sold_final' => $closing,
            'facturi_alimentare' => $topupInvoices,
            'alimentari_ok' => $topups['n'] === $topupInvoices['n'] && abs($topups['suma'] - $topupInvoices['suma']) < self::TOLERANCE,
            'documente_complete' => (int) ($docRow['complete'] ?? 0),
            'fara_factura' => (int) ($docRow['fara_factura'] ?? 0),
            'fara_anexa' => (int) ($docRow['fara_anexa'] ?? 0),
            'fara_desfasurator' => (int) ($docRow['fara_desfasurator'] ?? 0),
            'suma_diferita' => (int) ($docRow['suma_diferita'] ?? 0),
            'date_din' => $coverage !== false && $coverage !== null ? (string) $coverage : null,
        ];
    }

    /**
     * Ultima verificare a soldului: cat raporteaza eToll si cat iese din registru
     * (alimentari - sesiuni inchise - sesiuni in curs).
     *
     * @return array<string, mixed>|null
     */
    public function latestBalance(): ?array
    {
        $row = $this->db->query('SELECT * FROM etoll_sold ORDER BY verificat_la DESC, id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $computed = round((float) $row['alimentari'] - (float) $row['consum_inchis'] - (float) $row['consum_in_curs'], 2);
        $row['sold_calculat'] = $computed;
        $row['diferenta'] = round((float) $row['sold_etoll'] - $computed, 2);

        return $row;
    }

    /**
     * Ultima verificare fara nicio sesiune in curs: atunci soldul eToll si cel calculat
     * trebuie sa fie egale (cu sesiuni in curs, eToll actualizeaza soldul inaintea listei).
     *
     * @return array<string, mixed>|null
     */
    public function latestQuietBalance(): ?array
    {
        $row = $this->db->query('SELECT * FROM etoll_sold WHERE sesiuni_in_curs = 0 ORDER BY verificat_la DESC, id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['sold_calculat'] = round((float) $row['alimentari'] - (float) $row['consum_inchis'], 2);
        $row['diferenta'] = round((float) $row['sold_etoll'] - $row['sold_calculat'], 2);

        return $row;
    }

    /**
     * Sesiunile inchise din perioada, cu documentele si verificarea lor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessions(string $from, string $to, string $plate = ''): array
    {
        $sql = "
            SELECT t.tx_id, t.session_id, t.suma, t.data AS inchisa_la, t.nr_inmatriculare, t.remorci,
                   d.numar_document, d.inceput, d.sfarsit, d.origine, d.destinatie, d.km_tarifabil,
                   d.total_etoll, d.factura_path, d.anexa_path, d.desfasurator_path, d.eroare
            FROM etoll_tranzactii t
            LEFT JOIN etoll_documente d ON d.session_id = t.session_id
            WHERE t.tip = 'sesiune' AND t.in_curs = 0 AND t.data >= :s AND t.data < :e
        ";
        $params = [':s' => $from . ' 00:00:00', ':e' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
        if ($plate !== '') {
            $sql .= ' AND t.nr_inmatriculare = :plate';
            $params[':plate'] = $plate;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY t.data ASC, t.tx_id ASC');
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $row['verificare'] = self::sessionCheck($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Starea documentelor unei sesiuni.
     *
     * @return array{cod: string, text: string}
     */
    public static function sessionCheck(array $row): array
    {
        $missing = [];
        foreach (self::documentKinds() as $kind => [$label]) {
            if (empty($row[$kind . '_path'])) {
                $missing[] = mb_strtolower($label);
            }
        }
        if ($missing !== []) {
            return ['cod' => 'lipsa', 'text' => 'Lipsește ' . implode(', ', $missing)];
        }
        if ($row['total_etoll'] !== null && abs((float) $row['total_etoll'] - (float) $row['suma']) > self::TOLERANCE) {
            return ['cod' => 'diferenta', 'text' => 'Documentul arată ' . number_format((float) $row['total_etoll'], 2, ',', '.') . ' lei'];
        }

        return ['cod' => 'ok', 'text' => 'Complet'];
    }

    /** @return array<int, array<string, mixed>> alimentarile din perioada, cu factura lor (dupa ora platii) */
    public function topups(string $from, string $to): array
    {
        $params = [':s' => $from . ' 00:00:00', ':e' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
        $stmt = $this->db->prepare("SELECT tx_id, suma, data FROM etoll_tranzactii WHERE tip = 'alimentare' AND data >= :s AND data < :e ORDER BY data ASC");
        $stmt->execute($params);
        $topups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare('SELECT * FROM etoll_alimentari_facturi WHERE data >= :s AND data < :e ORDER BY data ASC');
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Factura se emite la secunde dupa plata: perechea = aceeasi suma, cea mai apropiata ora (sub o ora).
        $rows = [];
        foreach ($topups as $topup) {
            $best = null;
            foreach ($invoices as $key => $invoice) {
                $gap = abs(strtotime((string) $invoice['data']) - strtotime((string) $topup['data']));
                if (abs((float) $invoice['total'] - (float) $topup['suma']) < self::TOLERANCE && $gap <= 3600 && ($best === null || $gap < $best[1])) {
                    $best = [$key, $gap];
                }
            }
            $invoice = $best !== null ? $invoices[$best[0]] : null;
            if ($best !== null) {
                unset($invoices[$best[0]]);
            }
            $rows[] = $topup + ['factura' => $invoice];
        }
        // Facturi fara alimentare in registru (de verificat).
        foreach ($invoices as $invoice) {
            $rows[] = ['tx_id' => null, 'suma' => null, 'data' => $invoice['data'], 'factura' => $invoice];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['data'], (string) $b['data']));

        return $rows;
    }

    /** @return array<int, string> nr. auto cu sesiuni in perioada (filtrul paginii) */
    public function plates(string $from, string $to): array
    {
        $stmt = $this->db->prepare("SELECT DISTINCT nr_inmatriculare FROM etoll_tranzactii WHERE tip = 'sesiune' AND nr_inmatriculare IS NOT NULL AND data >= :s AND data < :e ORDER BY nr_inmatriculare");
        $stmt->execute([':s' => $from . ' 00:00:00', ':e' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array<string, mixed>|null */
    public function document(string $sessionId): ?array
    {
        $stmt = $this->db->prepare('SELECT d.*, t.nr_inmatriculare, t.data AS inchisa_la FROM etoll_documente d LEFT JOIN etoll_tranzactii t ON t.session_id = d.session_id AND t.tip = \'sesiune\' WHERE d.session_id = :id LIMIT 1');
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function topupInvoice(string $invoiceId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM etoll_alimentari_facturi WHERE invoice_id = :id');
        $stmt->execute([':id' => $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function lastSyncAt(): ?string
    {
        $value = $this->db->query('SELECT MAX(verificat_la) FROM etoll_sold')->fetchColumn();

        return $value !== false && $value !== null ? (string) $value : null;
    }
}
