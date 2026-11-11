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

    /** Tipurile de transport ale cursei (curse_dispecer.tip_transport), cu etichetele din Dispecer. */
    public const TRANSPORT_TYPES = [
        'primar' => 'Primar km',
        'primar_tona' => 'Primar tone',
        'distributie' => 'Distributie',
        'primar_distributie' => 'Primar+Distributie',
        'compresor' => 'Compresor',
        // Nu e o cursa: sesiunea e pe o perioada Km service (facturi.service_km_id).
        self::SERVICE_KM => 'Km service (drum la service)',
    ];

    /** Valoarea filtrului "Tip transport" pentru sesiunile asociate unei perioade Km service. */
    public const SERVICE_KM = 'service_km';

    /** Starea unei sesiuni: codurile din sessionCheck() + sesiunile inca pornite. */
    public const STATUSES = [
        'ok' => 'Complete (verificate)',
        'lipsa' => 'Documente lipsă',
        'diferenta' => 'Sumă diferită în document',
        'in_curs' => 'Sesiuni în curs',
    ];

    /** Valoarea de filtru pentru "fara" (fara beneficiar, fara categorie, fara cursa...). */
    public const NONE = '0';

    public function __construct(private PDO $db)
    {
    }

    public static function ensureSchema(PDO $db): void
    {
        foreach (self::schemaStatements() as $sql) {
            $db->exec($sql);
        }

        // Coloanele adaugate dupa prima versiune a tabelelor.
        $added = [
            ['etoll_documente', 'factura_path', 'VARCHAR(255) NULL AFTER total_etoll'],
            ['etoll_documente', 'fara_tva', 'DECIMAL(12,2) NULL AFTER total_etoll'],
            ['etoll_documente', 'tva', 'DECIMAL(12,2) NULL AFTER fara_tva'],
            ['etoll_documente', 'cota_tva', 'DECIMAL(5,2) NULL AFTER tva'],
            ['etoll_alimentari_facturi', 'fara_tva', 'DECIMAL(12,2) NULL AFTER total'],
            ['etoll_alimentari_facturi', 'tva', 'DECIMAL(12,2) NULL AFTER fara_tva'],
        ];
        $check = $db->prepare('
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
        ');
        foreach ($added as [$table, $column, $definition]) {
            $check->execute([':t' => $table, ':c' => $column]);
            if ((int) $check->fetchColumn() === 0) {
                $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            }
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
                fara_tva DECIMAL(12,2) NULL,
                tva DECIMAL(12,2) NULL,
                cota_tva DECIMAL(5,2) NULL,
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
                fara_tva DECIMAL(12,2) NULL,
                tva DECIMAL(12,2) NULL,
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
        $topupVat = $this->db->prepare('SELECT SUM(fara_tva) fara_tva, SUM(tva) tva FROM etoll_alimentari_facturi WHERE data >= :s AND data < :e');
        $topupVat->execute([':s' => $start, ':e' => $end]);
        $topupVatRow = $topupVat->fetch(PDO::FETCH_ASSOC) ?: [];

        $docs = $this->db->prepare("
            SELECT
                SUM(" . implode(' AND ', array_map(static fn(string $kind): string => "d.{$kind}_path IS NOT NULL", array_keys(self::documentKinds()))) . ") complete,
                SUM(d.factura_path IS NULL) fara_factura,
                SUM(d.anexa_path IS NULL) fara_anexa,
                SUM(d.desfasurator_path IS NULL) fara_desfasurator,
                SUM(d.total_etoll IS NOT NULL AND ABS(d.total_etoll - t.suma) > :tol) suma_diferita,
                SUM(d.fara_tva) fara_tva,
                SUM(d.tva) tva,
                SUM(d.fara_tva IS NULL) fara_tva_lipsa
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
            // TVA din documentele eToll (consumul: doar sesiunile al caror document CC-P a fost citit).
            'consum_fara_tva' => round((float) ($docRow['fara_tva'] ?? 0), 2),
            'consum_tva' => round((float) ($docRow['tva'] ?? 0), 2),
            'consum_fara_tva_lipsa' => (int) ($docRow['fara_tva_lipsa'] ?? 0),
            'alimentari_fara_tva' => round((float) ($topupVatRow['fara_tva'] ?? 0), 2),
            'alimentari_tva' => round((float) ($topupVatRow['tva'] ?? 0), 2),
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
    /** @param array<int, string> $plates nr. auto normalizate (B652NET); gol = toate */
    public function sessions(string $from, string $to, array $plates = []): array
    {
        $sql = "
            SELECT t.tx_id, t.session_id, t.suma, t.data AS inchisa_la, t.nr_inmatriculare, t.remorci,
                   d.numar_document, d.inceput, d.sfarsit, d.origine, d.destinatie, d.km_tarifabil,
                   d.total_etoll, d.fara_tva, d.tva, d.cota_tva, d.factura_path, d.anexa_path, d.desfasurator_path, d.eroare,
                   c.id AS cursa_id, c.beneficiar_id, c.tip_transport, bt.nume AS beneficiar,
                   f.service_km_id, sk.data_plecare AS service_km_plecare, sk.data_intoarcere AS service_km_intoarcere, sk.service_nume AS service_km_nume
            FROM etoll_tranzactii t
            LEFT JOIN etoll_documente d ON d.session_id = t.session_id
            -- Cursa si beneficiarul: dupa ce importul eToll -> Facturi a asociat sesiunea unei curse.
            LEFT JOIN facturi f ON f.sursa_key = CONCAT('etoll:', t.session_id)
                AND f.deleted_at IS NULL AND f.status IN ('asociata_auto', 'asociata_manual')
            LEFT JOIN curse_dispecer c ON c.id = f.cursa_id AND c.deleted_at IS NULL
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            -- Sau perioada Km service (drumul la / de la service), cand sesiunea nu e pe o cursa.
            LEFT JOIN vehicule_service_km sk ON sk.id = f.service_km_id
            WHERE t.tip = 'sesiune' AND t.in_curs = 0 AND t.data >= :s AND t.data < :e
        ";
        $params = [':s' => $from . ' 00:00:00', ':e' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
        if ($plates !== []) {
            $placeholders = [];
            foreach (array_values($plates) as $i => $plate) {
                $placeholders[] = ':plate' . $i;
                $params[':plate' . $i] = $plate;
            }
            $sql .= ' AND t.nr_inmatriculare IN (' . implode(', ', $placeholders) . ')';
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

    /**
     * Camioanele cu sesiuni in perioada, pentru filtrul grupat pe categoria de capacitate
     * (aceeasi regula ca la Carburanti: capul tractor ia categoria semiremorcii cuplate).
     * Valoarea de filtrare este nr. auto din eToll (B652NET); un camion necunoscut in flota
     * ajunge in grupa "Fara categorie".
     *
     * @return array<int, array<string, mixed>>
     */
    public function vehicles(string $from, string $to): array
    {
        $stmt = $this->db->prepare("
            SELECT
                p.nr AS etoll_plate,
                MAX(veh.nr_inmatriculare) AS nr_inmatriculare,
                MAX(CASE WHEN COALESCE(veh.capacitate_transport, 0) > 0 THEN veh.capacitate_transport ELSE semi.capacitate_transport END) AS capacitate_transport,
                MAX(CASE WHEN veh.tip_vehicul = 'cap_tractor' THEN COALESCE(semi.categorie_capacitate_id, veh.categorie_capacitate_id) ELSE veh.categorie_capacitate_id END) AS categorie_capacitate_id,
                MAX(cc.nume) AS categorie_capacitate,
                MAX(cc.ordine_afisare) AS categorie_capacitate_ordine,
                MAX(veh.marca) AS marca,
                MAX(veh.model) AS model
            FROM (
                SELECT DISTINCT nr_inmatriculare AS nr FROM etoll_tranzactii
                WHERE tip = 'sesiune' AND nr_inmatriculare IS NOT NULL AND data >= :s AND data < :e
            ) p
            LEFT JOIN vehicule veh ON REPLACE(UPPER(veh.nr_inmatriculare), ' ', '') = p.nr
            LEFT JOIN vehicule_cuplaje cuplaj ON cuplaj.tractor_id = veh.id AND cuplaj.activ = 1
            LEFT JOIN vehicule semi ON semi.id = cuplaj.semiremorca_id
            LEFT JOIN vehicule_categorii_capacitate cc ON cc.id = CASE
                WHEN veh.tip_vehicul = 'cap_tractor' THEN COALESCE(semi.categorie_capacitate_id, veh.categorie_capacitate_id)
                ELSE veh.categorie_capacitate_id
            END
            GROUP BY p.nr
            ORDER BY p.nr
        ");
        $stmt->execute([':s' => $from . ' 00:00:00', ':e' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Sesiunile inca pornite (in_curs = 1) din perioada: eToll le arata in registru inainte sa le inchida,
     * fara documente. Suma lor e consumul de pana acum.
     *
     * @return array<int, array<string, mixed>>
     */
    public function openSessions(string $from, string $to): array
    {
        $stmt = $this->db->prepare("
            SELECT t.tx_id, t.session_id, t.suma, t.data, t.nr_inmatriculare, t.remorci,
                   d.numar_document, d.inceput, d.sfarsit, d.origine, d.destinatie, d.km_tarifabil,
                   c.id AS cursa_id, c.beneficiar_id, c.tip_transport, bt.nume AS beneficiar,
                   f.service_km_id, sk.data_plecare AS service_km_plecare, sk.data_intoarcere AS service_km_intoarcere, sk.service_nume AS service_km_nume
            FROM etoll_tranzactii t
            LEFT JOIN etoll_documente d ON d.session_id = t.session_id
            LEFT JOIN facturi f ON f.sursa_key = CONCAT('etoll:', t.session_id)
                AND f.deleted_at IS NULL AND f.status IN ('asociata_auto', 'asociata_manual')
            LEFT JOIN curse_dispecer c ON c.id = f.cursa_id AND c.deleted_at IS NULL
            LEFT JOIN configurare_beneficiari_transport bt ON bt.id = c.beneficiar_id
            -- Sau perioada Km service (drumul la / de la service), cand sesiunea nu e pe o cursa.
            LEFT JOIN vehicule_service_km sk ON sk.id = f.service_km_id
            WHERE t.tip = 'sesiune' AND t.in_curs = 1 AND t.data >= :s AND t.data < :e
            ORDER BY t.data ASC, t.tx_id ASC
        ");
        $stmt->execute([':s' => $from . ' 00:00:00', ':e' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $row['verificare'] = ['cod' => 'in_curs', 'text' => 'În curs'];
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Beneficiarii pentru filtru: cei activi + cei care apar in sesiunile date (si daca sunt inactivi).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, string> id => nume
     */
    public function beneficiaries(array $rows = []): array
    {
        $present = [];
        foreach ($rows as $row) {
            if (!empty($row['beneficiar_id'])) {
                $present[(string) $row['beneficiar_id']] = true;
            }
        }
        $options = [];
        foreach ($this->db->query('SELECT id, nume, activ FROM configurare_beneficiari_transport ORDER BY nume')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if ((int) $row['activ'] === 1 || isset($present[(string) $row['id']])) {
                $options[(string) $row['id']] = (string) $row['nume'];
            }
        }

        return $options;
    }

    /**
     * Filtrele paginii pe sesiuni (inchise sau in curs). In acelasi camp valorile se leaga cu SAU,
     * intre campuri cu SI; un camp gol nu filtreaza. Capacitatea si categoria vin din flota
     * (vehicles(): capul tractor ia capacitatea/categoria semiremorcii cuplate), dupa nr. auto eToll.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<int, string>> $filters nr / cap / cat / ben / tip / status, plus
     *        inchisa_de / inchisa_pana (["2026-11-05 14:00:00"]): ora inchiderii sesiunii, capete incluse.
     *        Cu un capat ales, sesiunile in curs (fara ora de inchidere) nu trec.
     * @param array<string, array<string, mixed>> $vehiclesByPlate vehicles() indexat pe etoll_plate
     * @return array<int, array<string, mixed>>
     */
    public static function applyFilters(array $rows, array $filters, array $vehiclesByPlate): array
    {
        $closedFrom = (string) (($filters['inchisa_de'] ?? [])[0] ?? '');
        $closedTo = (string) (($filters['inchisa_pana'] ?? [])[0] ?? '');
        unset($filters['inchisa_de'], $filters['inchisa_pana']);
        $sets = array_map(static fn(array $values): array => array_flip(array_map('strval', $values)), $filters);
        $wants = static fn(string $key, string $value): bool => empty($sets[$key]) || isset($sets[$key][$value]);

        return array_values(array_filter($rows, static function (array $row) use ($wants, $vehiclesByPlate, $closedFrom, $closedTo): bool {
            $plate = (string) ($row['nr_inmatriculare'] ?? '');
            $vehicle = $vehiclesByPlate[$plate] ?? [];
            if ($closedFrom !== '' || $closedTo !== '') {
                $closedAt = (string) ($row['inchisa_la'] ?? '');
                if ($closedAt === '' || ($closedFrom !== '' && $closedAt < $closedFrom) || ($closedTo !== '' && $closedAt > $closedTo)) {
                    return false;
                }
            }

            return $wants('nr', $plate)
                && $wants('cap', self::capacityKey($vehicle['capacitate_transport'] ?? null))
                && $wants('cat', ((string) ($vehicle['categorie_capacitate_id'] ?? '')) ?: self::NONE)
                && $wants('ben', ((string) ($row['beneficiar_id'] ?? '')) ?: self::NONE)
                && $wants('tip', ((string) ($row['tip_transport'] ?? '')) ?: (!empty($row['service_km_id']) ? self::SERVICE_KM : self::NONE))
                && $wants('status', (string) ($row['verificare']['cod'] ?? ''));
        }));
    }

    /** Cheia capacitatii reale in filtru: "18.00"; necunoscuta = NONE. */
    public static function capacityKey(mixed $capacity): string
    {
        return $capacity !== null && $capacity !== '' && (float) $capacity > 0 ? number_format((float) $capacity, 2, '.', '') : self::NONE;
    }

    /**
     * Totalurile si verificarile documentelor pentru sesiunile inchise date: aceleasi reguli ca
     * in summary() (care le calculeaza in SQL pe toata perioada), aplicate pe sesiunile filtrate.
     *
     * @param array<int, array<string, mixed>> $rows sessions()
     * @return array<string, mixed>
     */
    /**
     * Totalurile pentru subsolul tabelului, pe tab: inchise, in curs, fara documente.
     * "Cu TVA" = toate sesiunile (suma din registrul eToll); "Fara TVA" / "TVA" = doar sesiunile cu
     * documentul CC-P citit (fara_tva / tva NULL nu se aduna ca 0 documentat: se numara separat).
     *
     * @param array<int, array<string, mixed>> $closed sessions()
     * @param array<int, array<string, mixed>> $open openSessions()
     * @return array<string, array{n: int, documentate: int, fara_tva: float, tva: float, suma: float}>
     */
    public static function tabTotals(array $closed, array $open): array
    {
        $sum = static function (array $rows): array {
            $totals = ['n' => count($rows), 'documentate' => 0, 'fara_tva' => 0.0, 'tva' => 0.0, 'suma' => 0.0];
            foreach ($rows as $row) {
                $totals['suma'] += (float) $row['suma'];
                if (($row['fara_tva'] ?? null) !== null) {
                    $totals['documentate']++;
                    $totals['fara_tva'] += (float) $row['fara_tva'];
                    $totals['tva'] += (float) ($row['tva'] ?? 0);
                }
            }
            foreach (['fara_tva', 'tva', 'suma'] as $key) {
                $totals[$key] = round($totals[$key], 2);
            }

            return $totals;
        };

        return [
            'closed' => $sum($closed),
            'open' => $sum($open),
            'missing' => $sum(array_values(array_filter($closed, static fn(array $row): bool => ($row['verificare']['cod'] ?? '') === 'lipsa'))),
        ];
    }

    public static function sessionTotals(array $rows): array
    {
        $active = array_keys(self::documentKinds());
        $totals = ['n' => count($rows), 'suma' => 0.0, 'fara_tva' => 0.0, 'tva' => 0.0, 'fara_tva_lipsa' => 0, 'documente_complete' => 0, 'suma_diferita' => 0];
        foreach (array_keys(self::DOCUMENTS) as $kind) {
            $totals['fara_' . $kind] = 0;
        }
        $plates = [];
        foreach ($rows as $row) {
            $totals['suma'] += (float) $row['suma'];
            if ($row['fara_tva'] === null) {
                $totals['fara_tva_lipsa']++;
            } else {
                $totals['fara_tva'] += (float) $row['fara_tva'];
            }
            $totals['tva'] += (float) ($row['tva'] ?? 0);
            $complete = true;
            foreach (array_keys(self::DOCUMENTS) as $kind) {
                if (empty($row[$kind . '_path'])) {
                    $totals['fara_' . $kind]++;
                    if (in_array($kind, $active, true)) {
                        $complete = false;
                    }
                }
            }
            $totals['documente_complete'] += $complete ? 1 : 0;
            if ($row['total_etoll'] !== null && abs((float) $row['total_etoll'] - (float) $row['suma']) > self::TOLERANCE) {
                $totals['suma_diferita']++;
            }
            $plates[(string) $row['nr_inmatriculare']] = true;
        }
        foreach (['suma', 'fara_tva', 'tva'] as $key) {
            $totals[$key] = round($totals[$key], 2);
        }
        $totals['vehicule'] = count($plates);

        return $totals;
    }

    /**
     * Documentele mai multor sesiuni (descarcarea celor selectate in pagina).
     *
     * @param array<int, string> $sessionIds
     * @return array<int, array<string, mixed>>
     */
    public function documents(array $sessionIds): array
    {
        $sessionIds = array_values(array_unique(array_filter(array_map('strval', $sessionIds))));
        if ($sessionIds === []) {
            return [];
        }
        $params = [];
        foreach ($sessionIds as $i => $id) {
            $params[':id' . $i] = $id;
        }
        $stmt = $this->db->prepare("SELECT d.*, t.nr_inmatriculare, t.data AS inchisa_la FROM etoll_documente d
            LEFT JOIN etoll_tranzactii t ON t.session_id = d.session_id AND t.tip = 'sesiune'
            WHERE d.session_id IN (" . implode(', ', array_keys($params)) . ') ORDER BY t.data ASC');
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
