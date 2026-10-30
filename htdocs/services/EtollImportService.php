<?php
declare(strict_types=1);

require_once __DIR__ . '/EtollApiClient.php';
require_once __DIR__ . '/EtollTripMatcher.php';
require_once __DIR__ . '/InvoiceStorageService.php';
require_once __DIR__ . '/InvoiceTripMatcher.php';

/**
 * Importul eToll: sesiunile de taxare per km inchise -> facturi de tip Toll in pagina
 * "Facturi" (sursa 'etoll'), cu PDF-ul CNAIR, asociate automat la cursa.
 *
 * Pasi la fiecare rulare (scripts/import_etoll.php):
 *   1. Sesiuni: tranzactiile din ultimele N zile; cele inchise (isOngoing = false, sign MINUS,
 *      cu nr. auto) se pastreaza in etoll_sesiuni. Alimentarile de portofel (PLUS) se ignora.
 *   2. Facturi eToll: la inchiderea sesiunii eToll emite un document CC-P (total 0, platit din
 *      portofel). Detaliul lui are sessionIds[], deci il legam de sesiune. Documentele fara
 *      numar inca (primele minute) se iau la rularea urmatoare.
 *   3. Pentru fiecare sesiune cu document: PDF-ul se salveaza ca orice factura
 *      (InvoiceStorageService) si se creeaza randul in facturi:
 *        tip Toll, valoare cu TVA = suma sesiunii (PDF-ul arata 0, fiind stornat din avans),
 *        data = ziua inchiderii, nr. auto = camionul, sursa_key = 'etoll:<session_id>'.
 *      InvoiceModel::create() ruleaza asocierea (EtollTripMatcher) si, la potrivire, randul
 *      din curse_cheltuieli apare pe cursa, cu PDF-ul atasat.
 *   4. Facturile eToll inca neasociate se reverifica (cursa poate fi adaugata mai tarziu).
 *      Aceeasi reverificare o face si pagina Facturi / scripts/rematch_facturi.php.
 */
class EtollImportService
{
    public const SUPPLIER = 'CNAIR - eToll';
    /** Cat de vechi poate fi o sesiune care inca isi cauta documentul la fiecare rulare. */
    private const PENDING_LOOKBACK_DAYS = 35;
    /** Pagini de 50 de documente citite pe rulare (~25 documente pe zi). */
    private const MAX_DOCUMENT_PAGES = 40;

    private PDO $db;
    private EtollApiClient $client;
    private InvoiceModel $invoices;
    private InvoiceStorageService $storage;

    /** @var callable(string): void */
    private $log;

    public function __construct(PDO $db, EtollApiClient $client, InvoiceModel $invoices, InvoiceStorageService $storage, ?callable $log = null)
    {
        $this->db = $db;
        $this->client = $client;
        $this->invoices = $invoices;
        $this->storage = $storage;
        $this->log = $log ?? static function (string $message): void {
        };
    }

    /** Tabela sesiunilor. Copia de referinta: database/migrations/2026_10_29_000001_etoll_import.sql. */
    public static function ensureSchema(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS etoll_sesiuni (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                session_id VARCHAR(64) NOT NULL,
                nr_inmatriculare VARCHAR(20) NOT NULL,
                remorci VARCHAR(100) NULL,
                inchisa_la DATETIME NOT NULL,
                suma DECIMAL(12,2) NOT NULL,
                invoice_id VARCHAR(64) NULL,
                numar_document VARCHAR(60) NULL,
                cota_tva DECIMAL(5,2) NULL,
                factura_id INT UNSIGNED NULL,
                eroare VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_etoll_sesiuni_session (session_id),
                KEY idx_etoll_sesiuni_invoice (invoice_id),
                KEY idx_etoll_sesiuni_inchisa (inchisa_la),
                KEY idx_etoll_sesiuni_factura (factura_id),
                CONSTRAINT fk_etoll_sesiuni_factura FOREIGN KEY (factura_id) REFERENCES facturi(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * @return array{sesiuni_noi: int, documente_legate: int, facturi_create: int, asociate: int, reverificate: int, erori: int, cereri: int}
     */
    public function run(int $days): array
    {
        $stats = ['sesiuni_noi' => 0, 'documente_legate' => 0, 'facturi_create' => 0, 'asociate' => 0, 'reverificate' => 0, 'erori' => 0, 'cereri' => 0];
        $to = new DateTimeImmutable('now');
        $from = $to->modify('-' . max(1, $days) . ' days');

        $stats['sesiuni_noi'] = $this->syncSessions($from, $to);
        $stats['documente_legate'] = $this->linkDocuments($from);
        [$stats['facturi_create'], $created, $errors] = $this->createInvoices();
        $stats['erori'] += $errors;
        [$stats['reverificate'], $rematched] = $this->rematchPending();
        $stats['asociate'] = $created + $rematched;
        $stats['cereri'] = $this->client->requestCount();

        return $stats;
    }

    // -------------------------------------------------------------------------
    // 1. Sesiuni
    // -------------------------------------------------------------------------

    private function syncSessions(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $local = new DateTimeZone(date_default_timezone_get());
        $insert = $this->db->prepare("
            INSERT IGNORE INTO etoll_sesiuni (session_id, nr_inmatriculare, remorci, inchisa_la, suma, created_at, updated_at)
            VALUES (:session_id, :nr_inmatriculare, :remorci, :inchisa_la, :suma, :created_at, :updated_at)
        ");

        $new = 0;
        $closed = 0;
        foreach ($this->client->transactions($from, $to) as $transaction) {
            $plate = InvoiceTripMatcher::normalizePlate((string) ($transaction['plateNumber'] ?? ''));
            if (!empty($transaction['isOngoing']) || ($transaction['sign'] ?? '') !== 'MINUS' || $plate === '') {
                continue;
            }
            $closed++;

            $sessionId = (string) (($transaction['sessionId'] ?? '') ?: ($transaction['id'] ?? ''));
            $closedAt = (new DateTimeImmutable((string) $transaction['date']))->setTimezone($local)->format('Y-m-d H:i:s');
            $trailers = array_filter(array_map('strval', is_array($transaction['trailers'] ?? null) ? $transaction['trailers'] : []));
            $now = date('Y-m-d H:i:s');

            $insert->bindValue(':session_id', $sessionId, PDO::PARAM_STR);
            $insert->bindValue(':nr_inmatriculare', $plate, PDO::PARAM_STR);
            $insert->bindValue(':remorci', $trailers === [] ? null : mb_substr(implode(', ', $trailers), 0, 100), $trailers === [] ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $insert->bindValue(':inchisa_la', $closedAt, PDO::PARAM_STR);
            $insert->bindValue(':suma', number_format((float) ($transaction['amount'] ?? 0), 2, '.', ''), PDO::PARAM_STR);
            $insert->bindValue(':created_at', $now, PDO::PARAM_STR);
            $insert->bindValue(':updated_at', $now, PDO::PARAM_STR);
            $insert->execute();
            $new += $insert->rowCount();
        }

        ($this->log)('Sesiuni inchise in eToll: ' . $closed . ', noi: ' . $new . '.');

        return $new;
    }

    // -------------------------------------------------------------------------
    // 2. Documentele CC-P ale sesiunilor
    // -------------------------------------------------------------------------

    private function linkDocuments(DateTimeImmutable $from): int
    {
        // Sesiunile inca fara document, si cele mai vechi decat fereastra rularii (documentul
        // poate intarzia zile), dar nu mai vechi de PENDING_LOOKBACK_DAYS.
        $cutoff = (new DateTimeImmutable('now'))->modify('-' . self::PENDING_LOOKBACK_DAYS . ' days');
        $stmt = $this->db->prepare('SELECT session_id, inchisa_la FROM etoll_sesiuni WHERE invoice_id IS NULL AND inchisa_la >= :cutoff ORDER BY inchisa_la ASC');
        $stmt->bindValue(':cutoff', $cutoff->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        if ($rows === []) {
            return 0;
        }
        $pending = array_fill_keys(array_map('strval', array_keys($rows)), true);
        $known = array_fill_keys(array_map('strval', $this->db->query('SELECT DISTINCT invoice_id FROM etoll_sesiuni WHERE invoice_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        // Documentul vine la secunde dupa inchidere; o zi de rezerva pentru intarzieri.
        $oldestPending = new DateTimeImmutable((string) reset($rows));
        $oldest = min($from, $oldestPending)->modify('-1 day');

        $update = $this->db->prepare("
            UPDATE etoll_sesiuni
            SET invoice_id = :invoice_id, numar_document = :numar_document, cota_tva = :cota_tva, eroare = NULL, updated_at = :updated_at
            WHERE session_id = :session_id AND invoice_id IS NULL
        ");

        $linked = 0;
        for ($page = 0; $page < self::MAX_DOCUMENT_PAGES && $pending !== []; $page++) {
            $result = $this->client->invoicesPage($page);
            $reachedOldest = false;
            foreach ($result['invoices'] as $invoice) {
                $issuedAt = new DateTimeImmutable((string) ($invoice['paymentDate'] ?? 'now'));
                if ($issuedAt < $oldest) {
                    $reachedOldest = true;
                    break;
                }

                $id = (string) ($invoice['id'] ?? '');
                $isSessionDocument = ($invoice['paymentMethod'] ?? null) === null && (float) ($invoice['total']['totalPrice'] ?? 0) == 0.0;
                if ($id === '' || isset($known[$id]) || !$isSessionDocument || ($invoice['externalNumber'] ?? null) === null) {
                    continue;
                }

                $detail = $this->client->invoice($id);
                $known[$id] = true;
                $number = trim(($detail['externalSeries'] ?? $invoice['externalSeries'] ?? '') . ' ' . ($detail['externalNumber'] ?? $invoice['externalNumber'] ?? ''));
                $vat = $detail['tolls'][0]['price']['vatPercentage'] ?? null;

                foreach (is_array($detail['sessionIds'] ?? null) ? $detail['sessionIds'] : [] as $sessionId) {
                    $sessionId = (string) $sessionId;
                    if (!isset($pending[$sessionId])) {
                        continue;
                    }
                    $update->bindValue(':invoice_id', $id, PDO::PARAM_STR);
                    $update->bindValue(':numar_document', mb_substr($number, 0, 60), PDO::PARAM_STR);
                    $update->bindValue(':cota_tva', $vat === null ? null : number_format((float) $vat, 2, '.', ''), $vat === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $update->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
                    $update->bindValue(':session_id', $sessionId, PDO::PARAM_STR);
                    $update->execute();
                    $linked += $update->rowCount();
                    unset($pending[$sessionId]);
                }
            }
            if ($reachedOldest || $result['last']) {
                break;
            }
        }

        ($this->log)('Documente eToll legate de sesiuni: ' . $linked . ($pending !== [] ? ', sesiuni inca fara document: ' . count($pending) : '') . '.');

        return $linked;
    }

    // -------------------------------------------------------------------------
    // 3. Facturi + PDF
    // -------------------------------------------------------------------------

    /** @return array{0: int, 1: int, 2: int} [create, asociate automat, erori] */
    private function createInvoices(): array
    {
        $sessions = $this->db->query("
            SELECT * FROM etoll_sesiuni
            WHERE invoice_id IS NOT NULL AND factura_id IS NULL
            ORDER BY inchisa_la ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $vehicles = new InvoiceTripMatcher($this->db);
        $pdfs = [];
        $created = 0;
        $associated = 0;
        $errors = 0;

        foreach ($sessions as $session) {
            $sourceKey = 'etoll:' . $session['session_id'];
            try {
                // Factura exista deja (de ex. sesiunea a fost reimportata): doar refacem legatura.
                $existing = $this->invoices->findBySourceKey($sourceKey);
                if ($existing !== null) {
                    $this->setInvoiceOf((string) $session['session_id'], (int) $existing['id'], null);
                    continue;
                }

                $invoiceId = (string) $session['invoice_id'];
                $pdfs[$invoiceId] ??= $this->client->invoicePdf($invoiceId);
                $document = $this->storePdf($pdfs[$invoiceId], $session);

                $amount = (float) $session['suma'];
                $vat = $session['cota_tva'] !== null ? (float) $session['cota_tva'] : 21.0;
                $id = $this->invoices->create([
                    'tip' => 'toll',
                    'furnizor' => self::SUPPLIER,
                    'numar_document' => $session['numar_document'],
                    'data_document' => substr((string) $session['inchisa_la'], 0, 10),
                    'valoare_cu_tva' => number_format($amount, 2, '.', ''),
                    'valoare_fara_tva' => number_format(round($amount / (1 + $vat / 100), 2), 2, '.', ''),
                    'moneda' => 'RON',
                    'nr_inmatriculare_extras' => $session['nr_inmatriculare'],
                    'vehicle_id' => $vehicles->resolveVehicleId((string) $session['nr_inmatriculare']),
                    'sursa' => 'etoll',
                    'sursa_key' => $sourceKey,
                    'document_path' => $document['document_path'],
                    'document_original_name' => $document['document_original_name'],
                    'document_mime' => $document['document_mime'],
                    'document_size' => $document['document_size'],
                    'document_sha256' => $document['document_sha256'],
                    'observatii' => $this->notes($session),
                ]);
                $this->setInvoiceOf((string) $session['session_id'], $id, null);
                $created++;

                $row = $this->invoices->getById($id);
                $status = (string) ($row['status'] ?? '');
                if ($status === 'asociata_auto') {
                    $associated++;
                }
                ($this->log)(sprintf(
                    'Factura #%d: %s %s, %s lei, inchisa %s -> %s%s',
                    $id,
                    $session['nr_inmatriculare'],
                    $session['numar_document'],
                    number_format($amount, 2, '.', ''),
                    $session['inchisa_la'],
                    $status,
                    $row['cursa_id'] !== null ? ' (cursa #' . (int) $row['cursa_id'] . ')' : ''
                ));
            } catch (Throwable $exception) {
                $errors++;
                $this->setInvoiceOf((string) $session['session_id'], null, mb_substr($exception->getMessage(), 0, 255));
                ($this->log)('EROARE sesiunea ' . $session['session_id'] . ': ' . $exception->getMessage());
                if ($this->client->budgetLeft() <= 0) {
                    break;
                }
            }
        }

        return [$created, $associated, $errors];
    }

    /** @return array{document_path: string, document_original_name: string, document_mime: string, document_size: int, document_sha256: string} */
    private function storePdf(string $content, array $session): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'etoll');
        if ($tmp === false || file_put_contents($tmp, $content) === false) {
            throw new RuntimeException('Nu s-a putut scrie fisierul temporar pentru PDF.');
        }

        try {
            $name = sprintf(
                'eToll_%s_%s_%s.pdf',
                $session['nr_inmatriculare'],
                substr((string) $session['inchisa_la'], 0, 10),
                preg_replace('/[^A-Za-z0-9]+/', '', (string) $session['numar_document'])
            );
            [$document, $error] = $this->storage->storeFromPath($tmp, $name);
        } finally {
            @unlink($tmp);
        }
        if ($document === null) {
            throw new RuntimeException('PDF-ul nu a putut fi salvat: ' . $error);
        }

        return $document;
    }

    private function notes(array $session): string
    {
        $parts = [
            'Sesiune eToll ' . $session['session_id'],
            'inchisa la ' . date('d.m.Y H:i', strtotime((string) $session['inchisa_la'])),
        ];
        if (!empty($session['remorci'])) {
            $parts[] = 'remorca ' . $session['remorci'];
        }
        $parts[] = 'documentul CC-P arata total 0 (platit din avansul eToll); valoarea e suma sesiunii';

        return implode(', ', $parts) . '.';
    }

    private function setInvoiceOf(string $sessionId, ?int $invoiceId, ?string $error): void
    {
        $stmt = $this->db->prepare('
            UPDATE etoll_sesiuni
            SET factura_id = COALESCE(:factura_id, factura_id), eroare = :eroare, updated_at = :updated_at
            WHERE session_id = :session_id
        ');
        $stmt->bindValue(':factura_id', $invoiceId, $invoiceId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':eroare', $error, $error === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':session_id', $sessionId, PDO::PARAM_STR);
        $stmt->execute();
    }

    // -------------------------------------------------------------------------
    // 4. Reverificare
    // -------------------------------------------------------------------------

    /** @return array{0: int, 1: int} [reverificate, asociate] */
    private function rematchPending(): array
    {
        $ids = $this->db->query("
            SELECT id FROM facturi
            WHERE sursa = 'etoll' AND deleted_at IS NULL AND fara_asociere_auto = 0
              AND status IN ('neasociata', 'de_verificat')
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $associated = 0;
        foreach ($ids as $id) {
            $result = $this->invoices->runMatching((int) $id);
            if ($result['status'] === 'asociata_auto') {
                $associated++;
                ($this->log)('Factura #' . (int) $id . ' asociata acum la cursa #' . (int) $result['cursa_id'] . '.');
            }
        }

        return [count($ids), $associated];
    }
}
