<?php
declare(strict_types=1);

require_once __DIR__ . '/EtollApiClient.php';
require_once __DIR__ . '/InvoiceTripMatcher.php';
require_once __DIR__ . '/../models/EtollStatementModel.php';

/**
 * Sincronizarea situatiei eToll (scripts/sync_etoll_situatie.php, cron). Doar citeste din eToll.
 *
 * La fiecare rulare:
 *   1. Registrul portofelului: tranzactiile din ultimele N zile (alimentari PLUS, sesiuni MINUS,
 *      inclusiv cele in curs) -> etoll_tranzactii. Fereastra se extinde pana la cea mai veche
 *      sesiune ramasa "in curs", ca inchiderea ei sa fie vazuta.
 *   2. Facturile alimentarilor (paymentMethod EUPLATESC) cu PDF-ul lor -> etoll_alimentari_facturi.
 *   3. Pentru fiecare sesiune inchisa: detaliul (ore, adrese, km, total), desfasuratorul
 *      (pagina "Servicii"), documentul CC-P si anexa lui TA_10 (pagina "Plăți") -> etoll_documente,
 *      PDF-urile in storage/etoll/. Documentul CC-P al sesiunii se ia din etoll_sesiuni (importul
 *      eToll -> Facturi il leaga); o sesiune fara document inca se reia la rularea urmatoare.
 *   4. Soldul raportat de eToll, langa sumele din registru -> etoll_sold.
 */
class EtollStatementSyncService
{
    public const STORAGE_DIR = 'storage/etoll';

    /** Dupa atatea incercari esuate, o sesiune nu mai e reluata automat. */
    private const MAX_ATTEMPTS = 20;
    /** Cereri pastrate pentru pasul soldului. */
    private const RESERVED_REQUESTS = 2;

    /** @var callable(string): void */
    private $log;

    public function __construct(private PDO $db, private EtollApiClient $client, private string $root, ?callable $log = null)
    {
        $this->log = $log ?? static function (string $message): void {
        };
    }

    /** @return array{tranzactii: int, facturi_alimentare: int, documente: int, erori: int, sold: ?float, cereri: int} */
    public function run(int $days): array
    {
        $stats = ['tranzactii' => 0, 'facturi_alimentare' => 0, 'documente' => 0, 'erori' => 0, 'sold' => null, 'cereri' => 0];

        $stats['tranzactii'] = $this->syncTransactions($days);
        [$stats['facturi_alimentare'], $errors] = $this->syncTopupInvoices($days);
        $stats['erori'] += $errors;
        [$stats['documente'], $errors] = $this->syncSessionDocuments();
        $stats['erori'] += $errors;
        $stats['sold'] = $this->snapshotBalance();
        $stats['cereri'] = $this->client->requestCount();

        return $stats;
    }

    // -------------------------------------------------------------------------
    // 1. Registrul portofelului
    // -------------------------------------------------------------------------

    private function syncTransactions(int $days): int
    {
        $to = new DateTimeImmutable('now');
        $from = $to->modify('-' . max(1, $days) . ' days');
        $oldestOngoing = $this->db->query("SELECT MIN(data) FROM etoll_tranzactii WHERE in_curs = 1")->fetchColumn();
        if ($oldestOngoing !== false && $oldestOngoing !== null) {
            $from = min($from, (new DateTimeImmutable((string) $oldestOngoing))->modify('-1 day'));
        }

        $upsert = $this->db->prepare("
            INSERT INTO etoll_tranzactii (tx_id, tip, suma, data, in_curs, nr_inmatriculare, remorci, session_id, created_at, updated_at)
            VALUES (:tx_id, :tip, :suma, :data, :in_curs, :nr, :remorci, :session_id, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE suma = VALUES(suma), data = VALUES(data), in_curs = VALUES(in_curs),
                nr_inmatriculare = VALUES(nr_inmatriculare), remorci = VALUES(remorci), updated_at = VALUES(updated_at)
        ");
        $local = new DateTimeZone(date_default_timezone_get());
        $count = 0;
        $ongoingSeen = [];
        foreach ($this->client->transactions($from, $to) as $transaction) {
            $id = (string) ($transaction['id'] ?? '');
            $sign = (string) ($transaction['sign'] ?? '');
            if ($id === '' || !in_array($sign, ['PLUS', 'MINUS'], true)) {
                continue;
            }
            $isTopup = $sign === 'PLUS';
            $plate = InvoiceTripMatcher::normalizePlate((string) ($transaction['plateNumber'] ?? ''));
            $trailers = array_filter(array_map('strval', is_array($transaction['trailers'] ?? null) ? $transaction['trailers'] : []));
            $ongoing = !$isTopup && !empty($transaction['isOngoing']);
            if ($ongoing) {
                $ongoingSeen[$id] = true;
            }
            $now = date('Y-m-d H:i:s');
            $upsert->execute([
                ':tx_id' => mb_substr($id, 0, 80),
                ':tip' => $isTopup ? 'alimentare' : 'sesiune',
                ':suma' => number_format((float) ($transaction['amount'] ?? 0), 2, '.', ''),
                ':data' => (new DateTimeImmutable((string) $transaction['date']))->setTimezone($local)->format('Y-m-d H:i:s'),
                ':in_curs' => $ongoing ? 1 : 0,
                ':nr' => $isTopup || $plate === '' ? null : $plate,
                ':remorci' => $trailers === [] ? null : mb_substr(implode(', ', $trailers), 0, 100),
                ':session_id' => $isTopup ? null : (string) (($transaction['sessionId'] ?? '') ?: $id),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $count++;
        }

        ($this->log)('Registru eToll: ' . $count . ' tranzactii citite din ' . $from->format('d.m.Y H:i') . ', ' . count($ongoingSeen) . ' sesiuni in curs.');

        return $count;
    }

    // -------------------------------------------------------------------------
    // 2. Facturile alimentarilor
    // -------------------------------------------------------------------------

    /** @return array{0: int, 1: int} [noi, erori] */
    private function syncTopupInvoices(int $days): array
    {
        $known = array_fill_keys(array_map('strval', $this->db->query('SELECT invoice_id FROM etoll_alimentari_facturi')->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        // Facturile salvate inainte de coloanele de TVA: valorile vin din aceeasi lista, fara cereri in plus.
        $withoutVat = array_fill_keys(array_map('strval', $this->db->query('SELECT invoice_id FROM etoll_alimentari_facturi WHERE fara_tva IS NULL')->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        // Prima rulare (sau TVA de completat): tot istoricul. Apoi doar fereastra rularii (lista vine cele mai noi intai).
        $oldest = $known === [] || $withoutVat !== [] ? null : (new DateTimeImmutable('now'))->modify('-' . (max(1, $days) + 1) . ' days');
        $insert = $this->db->prepare('
            INSERT IGNORE INTO etoll_alimentari_facturi (invoice_id, numar_document, data, total, fara_tva, tva, pdf_path, created_at)
            VALUES (:id, :nr, :data, :total, :fara_tva, :tva, :pdf, :created_at)
        ');
        $fillVat = $this->db->prepare('UPDATE etoll_alimentari_facturi SET fara_tva = :fara_tva, tva = :tva WHERE invoice_id = :id');
        $amount = static fn(mixed $value): ?string => is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
        $local = new DateTimeZone(date_default_timezone_get());

        $new = 0;
        $errors = 0;
        for ($page = 0; $page < 80; $page++) {
            $result = $this->client->invoicesPage($page);
            $reachedOldest = false;
            foreach ($result['invoices'] as $invoice) {
                $issuedAt = new DateTimeImmutable((string) ($invoice['paymentDate'] ?? 'now'));
                if ($oldest !== null && $issuedAt < $oldest) {
                    $reachedOldest = true;
                    break;
                }
                $id = (string) ($invoice['id'] ?? '');
                if (isset($withoutVat[$id])) {
                    $fillVat->execute([':fara_tva' => $amount($invoice['total']['priceWithoutVat'] ?? null), ':tva' => $amount($invoice['total']['vat'] ?? null), ':id' => $id]);
                    unset($withoutVat[$id]);
                }
                if ($id === '' || isset($known[$id]) || ($invoice['paymentMethod'] ?? null) === null) {
                    continue;
                }
                $number = trim(($invoice['externalSeries'] ?? '') . ' ' . ($invoice['externalNumber'] ?? ''));
                try {
                    $path = $this->storeFile($this->client->invoicePdf($id), 'alimentari/' . $issuedAt->setTimezone($local)->format('Y-m') . '/' . self::safeName($id) . '.pdf');
                } catch (Throwable $exception) {
                    $errors++;
                    ($this->log)('EROARE factura alimentare ' . $number . ': ' . $exception->getMessage());
                    if ($this->client->budgetLeft() <= self::RESERVED_REQUESTS) {
                        return [$new, $errors];
                    }
                    continue;
                }
                $insert->execute([
                    ':id' => $id,
                    ':nr' => $number !== '' ? mb_substr($number, 0, 60) : null,
                    ':data' => $issuedAt->setTimezone($local)->format('Y-m-d H:i:s'),
                    ':total' => number_format((float) ($invoice['total']['totalPrice'] ?? 0), 2, '.', ''),
                    ':fara_tva' => $amount($invoice['total']['priceWithoutVat'] ?? null),
                    ':tva' => $amount($invoice['total']['vat'] ?? null),
                    ':pdf' => $path,
                    ':created_at' => date('Y-m-d H:i:s'),
                ]);
                $known[$id] = true;
                $new++;
            }
            if ($reachedOldest || $result['last'] || $this->client->budgetLeft() <= self::RESERVED_REQUESTS) {
                break;
            }
        }
        ($this->log)('Facturi de alimentare noi: ' . $new . '.');

        return [$new, $errors];
    }

    // -------------------------------------------------------------------------
    // 3. Documentele sesiunilor
    // -------------------------------------------------------------------------

    /** @return array{0: int, 1: int} [sesiuni completate, erori] */
    private function syncSessionDocuments(): array
    {
        $pending = $this->db->prepare("
            SELECT t.session_id, t.data, t.suma, t.nr_inmatriculare, s.invoice_id, s.annex_id, s.numar_document,
                   d.session_id AS doc_exista, d.inceput, d.fara_tva, d.factura_path, d.anexa_path, d.desfasurator_path, d.annex_id AS doc_annex_id
            FROM etoll_tranzactii t
            LEFT JOIN etoll_documente d ON d.session_id = t.session_id
            LEFT JOIN etoll_sesiuni s ON s.session_id = t.session_id
            WHERE t.tip = 'sesiune' AND t.in_curs = 0 AND t.session_id IS NOT NULL
              AND (d.session_id IS NULL OR d.inceput IS NULL OR d.factura_path IS NULL OR d.anexa_path IS NULL OR d.desfasurator_path IS NULL
                   OR (d.fara_tva IS NULL AND s.invoice_id IS NOT NULL))
              AND (d.incercari IS NULL OR d.incercari < :max)
            ORDER BY t.data DESC
        ");
        $pending->execute([':max' => self::MAX_ATTEMPTS]);
        $rows = $pending->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $completed = 0;
        $errors = 0;
        foreach ($rows as $row) {
            // Detaliu sesiune + desfasurator + detaliu document CC-P (TVA, anexa) + factura + anexa.
            if ($this->client->budgetLeft() < 5 + self::RESERVED_REQUESTS) {
                ($this->log)('Plafonul de cereri: ' . (count($rows) - $completed) . ' sesiuni raman pentru rularea urmatoare.');
                break;
            }
            $sessionId = (string) $row['session_id'];
            $folder = substr((string) $row['data'], 0, 7) . '/';
            $fields = [];
            $problems = [];

            try {
                if ($row['inceput'] === null) {
                    $detail = $this->client->sessionDetails($sessionId);
                    $fields += [
                        'inceput' => self::localTime($detail['startDate'] ?? null),
                        'sfarsit' => self::localTime($detail['endDate'] ?? null),
                        'origine' => self::text($detail['startAddress'] ?? null),
                        'destinatie' => self::text($detail['endAddress'] ?? null),
                        'km_tarifabil' => is_numeric($detail['totalTaxableDistance'] ?? null) ? number_format((float) $detail['totalTaxableDistance'], 2, '.', '') : null,
                        'total_etoll' => is_numeric($detail['totalPrice'] ?? null) ? number_format((float) $detail['totalPrice'], 2, '.', '') : null,
                    ];
                }
            } catch (Throwable $exception) {
                $problems[] = 'detaliu: ' . $exception->getMessage();
            }

            if ($row['desfasurator_path'] === null) {
                try {
                    $fields['desfasurator_path'] = $this->storeFile($this->client->sessionBreakdownPdf($sessionId), $folder . self::safeName($sessionId) . '_desfasurator.pdf');
                } catch (Throwable $exception) {
                    $problems[] = 'desfasurator: ' . $exception->getMessage();
                }
            }

            $invoiceId = (string) ($row['invoice_id'] ?? '');
            // Detaliul documentului CC-P, citit cel mult o data: imparteala TVA si id-ul anexei.
            $invoiceDetail = null;
            $detailOf = function () use ($invoiceId, &$invoiceDetail): array {
                return $invoiceDetail ??= $this->client->invoice($invoiceId);
            };
            if ($row['fara_tva'] === null && $invoiceId !== '') {
                try {
                    $fields += self::vatOf($detailOf(), (float) $row['suma']);
                } catch (Throwable $exception) {
                    $problems[] = 'TVA: ' . $exception->getMessage();
                }
            }
            if ($row['factura_path'] === null && $invoiceId !== '') {
                try {
                    $fields['factura_path'] = $this->storeFile($this->client->invoicePdf($invoiceId), $folder . self::safeName($sessionId) . '_factura.pdf');
                } catch (Throwable $exception) {
                    $problems[] = 'factura: ' . $exception->getMessage();
                }
            }
            if ($row['anexa_path'] === null && $invoiceId !== '') {
                try {
                    $annexId = (string) (($row['doc_annex_id'] ?? '') ?: ($row['annex_id'] ?? ''));
                    if ($annexId === '') {
                        $annexId = (string) self::annexIdOf($detailOf());
                    }
                    if ($annexId === '') {
                        throw new RuntimeException('documentul CC-P nu are anexa.');
                    }
                    $fields['annex_id'] = $annexId;
                    $fields['anexa_path'] = $this->storeFile($this->client->annexPdf($invoiceId, $annexId), $folder . self::safeName($sessionId) . '_anexa.pdf');
                } catch (Throwable $exception) {
                    $problems[] = 'anexa: ' . $exception->getMessage();
                }
            } elseif ($row['anexa_path'] === null || $row['factura_path'] === null) {
                $problems[] = 'factura si anexa: documentul CC-P nu e inca legat de sesiune';
            }

            if ($invoiceId !== '') {
                $fields['invoice_id'] = $invoiceId;
                $fields['numar_document'] = $row['numar_document'];
            }
            $this->saveDocument($sessionId, $fields, $problems);

            // "Documentul CC-P nu e inca legat" nu e o eroare: importul il leaga la rularea lui.
            $realProblems = array_filter($problems, static fn(string $p): bool => !str_contains($p, 'nu e inca legat'));
            if ($realProblems !== []) {
                $errors++;
                ($this->log)('Sesiunea ' . $sessionId . ' (' . $row['nr_inmatriculare'] . '): ' . implode('; ', $realProblems));
            } elseif ($problems === []) {
                $completed++;
            }
        }
        ($this->log)('Documente de sesiune completate: ' . $completed . ' din ' . count($rows) . ' in asteptare.');

        return [$completed, $errors];
    }

    /** @param array<string, mixed> $fields @param array<int, string> $problems */
    private function saveDocument(string $sessionId, array $fields, array $problems): void
    {
        $now = date('Y-m-d H:i:s');
        $columns = ['invoice_id', 'annex_id', 'numar_document', 'inceput', 'sfarsit', 'origine', 'destinatie', 'km_tarifabil', 'total_etoll', 'fara_tva', 'tva', 'cota_tva', 'factura_path', 'anexa_path', 'desfasurator_path'];
        $fields = array_intersect_key($fields, array_flip($columns));

        $this->db->prepare('INSERT IGNORE INTO etoll_documente (session_id, created_at, updated_at) VALUES (:id, :now, :now2)')
            ->execute([':id' => $sessionId, ':now' => $now, ':now2' => $now]);

        // Valorile deja salvate nu se pierd (COALESCE); doar esecurile reale cresc incercarile,
        // asteptarea documentului CC-P nu.
        $failed = array_filter($problems, static fn(string $p): bool => !str_contains($p, 'nu e inca legat')) !== [];
        $sets = ['incercari = incercari + :failed', 'eroare = :eroare', 'updated_at = :now'];
        $params = [
            ':id' => $sessionId,
            ':failed' => $failed ? 1 : 0,
            ':eroare' => $problems === [] ? null : mb_substr(implode('; ', $problems), 0, 255),
            ':now' => $now,
        ];
        foreach ($fields as $column => $value) {
            $sets[] = "$column = COALESCE(:$column, $column)";
            $params[":$column"] = $value;
        }
        $this->db->prepare('UPDATE etoll_documente SET ' . implode(', ', $sets) . ' WHERE session_id = :id')->execute($params);
    }

    /**
     * Valoarea fara TVA, TVA-ul si cota din detaliul documentului CC-P (tolls[0].price), exact cum le
     * tipareste anexa. Un document cu mai multe sesiuni are suma lor: atunci se imparte suma sesiunii
     * dupa cota documentului.
     *
     * @return array{fara_tva: string, tva: string, cota_tva: ?string}
     */
    private static function vatOf(array $detail, float $sessionAmount): array
    {
        $price = is_array($detail['tolls'][0]['price'] ?? null) ? $detail['tolls'][0]['price'] : [];
        $rate = is_numeric($price['vatPercentage'] ?? null) ? (float) $price['vatPercentage'] : null;
        $sessions = is_array($detail['sessionIds'] ?? null) ? count($detail['sessionIds']) : 0;

        if ($sessions <= 1 && is_numeric($price['priceWithoutVat'] ?? null) && is_numeric($price['vat'] ?? null)) {
            $net = (float) $price['priceWithoutVat'];
            $vat = (float) $price['vat'];
        } else {
            $net = round($sessionAmount / (1 + ($rate ?? 21.0) / 100), 2);
            $vat = $sessionAmount - $net;
        }

        return [
            'fara_tva' => number_format($net, 2, '.', ''),
            'tva' => number_format($vat, 2, '.', ''),
            'cota_tva' => $rate === null ? null : number_format($rate, 2, '.', ''),
        ];
    }

    private static function annexIdOf(array $detail): ?string
    {
        foreach (is_array($detail['annexes'] ?? null) ? $detail['annexes'] : [] as $annex) {
            if (!empty($annex['annexId']) && ($annex['type'] ?? 'PAY_PER_DISTANCE') === 'PAY_PER_DISTANCE') {
                return (string) $annex['annexId'];
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // 4. Soldul
    // -------------------------------------------------------------------------

    private function snapshotBalance(): ?float
    {
        try {
            $account = $this->client->account();
        } catch (Throwable $exception) {
            ($this->log)('Soldul eToll nu a putut fi citit: ' . $exception->getMessage());

            return null;
        }
        $balance = $account['price']['totalPrice'] ?? null;
        if (!is_numeric($balance)) {
            ($this->log)('Soldul eToll lipseste din raspuns (price.totalPrice).');

            return null;
        }

        $totals = $this->db->query("
            SELECT
                COALESCE(SUM(CASE WHEN tip = 'alimentare' THEN suma END), 0) alimentari,
                COALESCE(SUM(CASE WHEN tip = 'sesiune' AND in_curs = 0 THEN suma END), 0) consum_inchis,
                COALESCE(SUM(CASE WHEN tip = 'sesiune' AND in_curs = 1 THEN suma END), 0) consum_in_curs,
                COALESCE(SUM(tip = 'sesiune' AND in_curs = 1), 0) sesiuni_in_curs
            FROM etoll_tranzactii
        ")->fetch(PDO::FETCH_ASSOC);
        $this->db->prepare('
            INSERT INTO etoll_sold (verificat_la, sold_etoll, alimentari, consum_inchis, consum_in_curs, sesiuni_in_curs)
            VALUES (:at, :sold, :alimentari, :inchis, :in_curs, :n)
        ')->execute([
            ':at' => date('Y-m-d H:i:s'),
            ':sold' => number_format((float) $balance, 2, '.', ''),
            ':alimentari' => $totals['alimentari'],
            ':inchis' => $totals['consum_inchis'],
            ':in_curs' => $totals['consum_in_curs'],
            ':n' => (int) $totals['sesiuni_in_curs'],
        ]);
        $computed = (float) $totals['alimentari'] - (float) $totals['consum_inchis'] - (float) $totals['consum_in_curs'];
        ($this->log)(sprintf('Sold eToll %.2f, calculat %.2f (alimentari %.2f - consum %.2f - in curs %.2f, %d sesiuni in curs).',
            (float) $balance, $computed, (float) $totals['alimentari'], (float) $totals['consum_inchis'], (float) $totals['consum_in_curs'], (int) $totals['sesiuni_in_curs']));

        return (float) $balance;
    }

    // -------------------------------------------------------------------------
    // Fisiere
    // -------------------------------------------------------------------------

    /** Salveaza PDF-ul sub storage/etoll/ si intoarce calea relativa la radacina aplicatiei. */
    private function storeFile(string $content, string $relative): string
    {
        $path = self::STORAGE_DIR . '/' . ltrim($relative, '/');
        $absolute = $this->root . '/' . $path;
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nu s-a putut crea folderul ' . $dir . '.');
        }
        if (file_put_contents($absolute, $content) === false) {
            throw new RuntimeException('Nu s-a putut scrie ' . $path . '.');
        }

        return $path;
    }

    /** Calea absoluta a unui fisier din storage/etoll, sau null (si pentru incercari de iesire din folder). */
    public static function resolvePath(string $root, ?string $relative): ?string
    {
        if ($relative === null || $relative === '' || !str_starts_with($relative, self::STORAGE_DIR . '/')) {
            return null;
        }
        $base = realpath($root . '/' . self::STORAGE_DIR);
        $path = realpath($root . '/' . $relative);
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }

        return $path;
    }

    private static function safeName(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '', $value) ?: 'document';
    }

    private static function localTime(mixed $iso): ?string
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : null;
    }
}
