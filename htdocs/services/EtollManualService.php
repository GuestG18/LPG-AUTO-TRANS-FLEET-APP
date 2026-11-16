<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/EtollStatementModel.php';
require_once __DIR__ . '/../models/InvoiceModel.php';
require_once __DIR__ . '/EtollPdfMerger.php';
require_once __DIR__ . '/EtollImportService.php';
require_once __DIR__ . '/EtollStatementSyncService.php';
require_once __DIR__ . '/InvoiceStorageService.php';
require_once __DIR__ . '/InvoiceTripMatcher.php';

/**
 * Inregistrarile eToll adaugate manual (?page=etoll, "Adaugă manual"): o sesiune inchisa sau o
 * alimentare a portofelului pe care API-ul eToll nu a adus-o (API indisponibil mai mult decat
 * fereastra cron-ului, sesiune lipsa din lista etc.).
 *
 * Se scriu in aceleasi tabele ca datele sincronizate, cu id-ul "manual-<hex>" (tx_id = session_id),
 * deci intra in pagina, totaluri, verificarea soldului si export ca orice sesiune:
 *   sesiune     etoll_tranzactii + etoll_documente (ore, traseu, km, TVA, PDF-urile incarcate)
 *               + etoll_sesiuni + factura Toll in Facturi (sursa 'etoll', sursa_key 'etoll:manual-...'),
 *               asociata automat la cursa de EtollTripMatcher, exact ca o sesiune importata;
 *   alimentare  etoll_tranzactii + (cu numar sau PDF) etoll_alimentari_facturi, invoice_id = tx_id.
 *
 * Sincronizarea si importul nu le ating (filtrul "manual-%"). Daca eToll aduce mai tarziu aceeasi
 * miscare (acelasi camion, aceeasi suma, ora inchiderii la cel mult MATCH_HOURS distanta), reconcile()
 * o marcheaza inlocuita_de = id-ul real: iese din calcule, iar factura ei manuala se sterge logic,
 * ca taxa sa nu apara de doua ori pe cursa.
 *
 * Copia de referinta a coloanelor: database/migrations/2026_11_13_000002_etoll_manual.sql.
 */
class EtollManualService
{
    public const STORAGE_DIR = 'storage/etoll/manual';
    private const MAX_PDF_BYTES = 10485760;
    /** O miscare eToll reala la cel mult atatea ore de cea manuala (acelasi camion, aceeasi suma) e aceeasi. */
    public const MATCH_HOURS = 6;
    private const DEFAULT_VAT = 21.0;

    private InvoiceModel $invoices;
    private InvoiceStorageService $storage;

    public function __construct(private PDO $db, private string $root, ?InvoiceModel $invoices = null, ?InvoiceStorageService $storage = null)
    {
        $this->invoices = $invoices ?? new InvoiceModel($db);
        $this->storage = $storage ?? new InvoiceStorageService($root);
    }

    // -------------------------------------------------------------------------
    // Sesiune
    // -------------------------------------------------------------------------

    /**
     * Creeaza sau modifica o sesiune manuala.
     *
     * @param array<string, mixed> $input campurile formularului
     * @param array<string, mixed> $files $_FILES (factura / anexa / desfasurator)
     * @return string tx_id (= session_id)
     */
    public function saveSession(array $input, array $files, ?int $userId, ?string $txId = null): string
    {
        $existing = $txId !== null ? $this->existing($txId, 'sesiune') : null;

        $plate = InvoiceTripMatcher::normalizePlate((string) ($input['nr_inmatriculare'] ?? ''));
        if ($plate === '' || strlen($plate) > 20) {
            throw new InvalidArgumentException('Alege numărul de înmatriculare al camionului.');
        }
        $closedAt = self::dateTime($input['inchisa_la'] ?? null, 'Data și ora închiderii');
        if ($closedAt === null) {
            throw new InvalidArgumentException('Completează data și ora închiderii sesiunii.');
        }
        if ($closedAt > date('Y-m-d H:i:s', time() + 3600)) {
            throw new InvalidArgumentException('Sesiunea nu poate fi închisă în viitor.');
        }
        $startedAt = self::dateTime($input['inceput'] ?? null, 'Data și ora începutului');
        if ($startedAt !== null && $startedAt > $closedAt) {
            throw new InvalidArgumentException('Începutul sesiunii este după închidere.');
        }
        $amount = self::amount($input['suma'] ?? null);
        if ($amount === null || $amount <= 0 || $amount >= 1000000) {
            throw new InvalidArgumentException('Completează suma sesiunii cu TVA (lei), mai mare decât 0.');
        }
        [$net, $vat, $rate] = self::vatSplit($amount, $input['cota_tva'] ?? null, $input['fara_tva'] ?? null);
        $km = self::amount($input['km_tarifabil'] ?? null);
        if ($km !== null && ($km < 0 || $km > 100000)) {
            throw new InvalidArgumentException('Km tarifabili nu sunt valizi.');
        }
        $trailers = self::text($input['remorci'] ?? null, 100);
        $trailers = $trailers !== null ? (preg_replace('/[^A-Z0-9, ]+/', '', strtoupper($trailers)) ?: null) : null;
        $number = self::text($input['numar_document'] ?? null, 60);
        $origin = self::text($input['origine'] ?? null, 255);
        $destination = self::text($input['destinatie'] ?? null, 255);
        $notes = self::text($input['observatii'] ?? null, 255);

        $id = $existing !== null ? (string) $existing['tx_id'] : self::newId();
        $this->assertNotDuplicate('sesiune', $id, $amount, $closedAt, $plate);

        // PDF-urile: cele noi se salveaza; "sterge_<doc>" scoate unul existent.
        $folder = substr($closedAt, 0, 7);
        $paths = [];
        $changedFiles = false;
        foreach (array_keys(EtollStatementModel::DOCUMENTS) as $kind) {
            $old = $existing[$kind . '_path'] ?? null;
            $new = $this->storePdf($files[$kind] ?? null, $folder, $id . '_' . $kind, self::kindLabel($kind));
            if ($new !== null) {
                $paths[$kind] = $new;
                $changedFiles = true;
                if ($old !== null && $old !== $new) {
                    $this->removeFile($old);
                }
            } elseif ($old !== null && !empty($input['sterge_' . $kind])) {
                $paths[$kind] = null;
                $changedFiles = true;
                $this->removeFile($old);
            } else {
                $paths[$kind] = $old;
            }
        }

        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->prepare('
                INSERT INTO etoll_tranzactii (tx_id, tip, suma, data, in_curs, nr_inmatriculare, remorci, session_id, observatii, adaugat_de, created_at, updated_at)
                VALUES (:id, \'sesiune\', :suma, :data, 0, :nr, :remorci, :sid, :obs, :user, :c, :u)
                ON DUPLICATE KEY UPDATE suma = VALUES(suma), data = VALUES(data), nr_inmatriculare = VALUES(nr_inmatriculare),
                    remorci = VALUES(remorci), observatii = VALUES(observatii), updated_at = VALUES(updated_at)
            ')->execute([':id' => $id, ':suma' => self::money($amount), ':data' => $closedAt, ':nr' => $plate, ':remorci' => $trailers,
                ':sid' => $id, ':obs' => $notes, ':user' => $userId, ':c' => $now, ':u' => $now]);

            $this->db->prepare('
                INSERT INTO etoll_documente (session_id, numar_document, inceput, sfarsit, origine, destinatie, km_tarifabil, total_etoll,
                    fara_tva, tva, cota_tva, factura_path, anexa_path, desfasurator_path, incercari, eroare, created_at, updated_at)
                VALUES (:id, :nr, :inceput, :sfarsit, :origine, :destinatie, :km, NULL, :net, :vat, :rate, :f, :a, :d, 0, NULL, :c, :u)
                ON DUPLICATE KEY UPDATE numar_document = VALUES(numar_document), inceput = VALUES(inceput), sfarsit = VALUES(sfarsit),
                    origine = VALUES(origine), destinatie = VALUES(destinatie), km_tarifabil = VALUES(km_tarifabil),
                    fara_tva = VALUES(fara_tva), tva = VALUES(tva), cota_tva = VALUES(cota_tva), factura_path = VALUES(factura_path),
                    anexa_path = VALUES(anexa_path), desfasurator_path = VALUES(desfasurator_path), updated_at = VALUES(updated_at)
            ')->execute([':id' => $id, ':nr' => $number, ':inceput' => $startedAt, ':sfarsit' => $closedAt, ':origine' => $origin,
                ':destinatie' => $destination, ':km' => $km === null ? null : number_format($km, 2, '.', ''), ':net' => self::money($net),
                ':vat' => self::money($vat), ':rate' => number_format($rate, 2, '.', ''), ':f' => $paths['factura'], ':a' => $paths['anexa'],
                ':d' => $paths['desfasurator'], ':c' => $now, ':u' => $now]);

            // etoll_sesiuni: EtollTripMatcher ia de aici camionul si ora inchiderii pentru asocierea la cursa.
            $this->db->prepare('
                INSERT INTO etoll_sesiuni (session_id, nr_inmatriculare, remorci, inchisa_la, suma, numar_document, cota_tva, created_at, updated_at)
                VALUES (:id, :nr, :remorci, :data, :suma, :doc, :rate, :c, :u)
                ON DUPLICATE KEY UPDATE nr_inmatriculare = VALUES(nr_inmatriculare), remorci = VALUES(remorci), inchisa_la = VALUES(inchisa_la),
                    suma = VALUES(suma), numar_document = VALUES(numar_document), cota_tva = VALUES(cota_tva), updated_at = VALUES(updated_at)
            ')->execute([':id' => $id, ':nr' => $plate, ':remorci' => $trailers, ':data' => $closedAt, ':suma' => self::money($amount),
                ':doc' => $number, ':rate' => number_format($rate, 2, '.', ''), ':c' => $now, ':u' => $now]);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        // Factura Toll din Facturi (si cheltuiala de pe cursa), in afara tranzactiei: create() / update()
        // ruleaza asocierea, care isi deschide singura tranzactiile.
        $session = ['session_id' => $id, 'nr_inmatriculare' => $plate, 'remorci' => $trailers, 'inchisa_la' => $closedAt, 'observatii' => $notes];
        $this->saveInvoice($session, $amount, $net, $number, $paths, $changedFiles || $existing === null, $existing !== null && (string) $existing['data'] !== $closedAt, $userId);

        return $id;
    }

    /** @param array<string, ?string> $paths */
    private function saveInvoice(array $session, float $amount, float $net, ?string $number, array $paths, bool $filesChanged, bool $momentChanged, ?int $userId): void
    {
        $sourceKey = 'etoll:' . $session['session_id'];
        $vehicleId = (new InvoiceTripMatcher($this->db))->resolveVehicleId((string) $session['nr_inmatriculare']);
        $fields = [
            'numar_document' => $number,
            'data_document' => substr((string) $session['inchisa_la'], 0, 10),
            'valoare_cu_tva' => self::money($amount),
            'valoare_fara_tva' => self::money($net),
            'nr_inmatriculare_extras' => $session['nr_inmatriculare'],
            'vehicle_id' => $vehicleId,
            'observatii' => $this->invoiceNotes($session),
        ];
        $existing = $this->invoices->findBySourceKey($sourceKey);
        $document = $filesChanged ? $this->invoiceDocument($session, $number, $paths) : null;

        if ($existing === null) {
            $id = $this->invoices->create($fields + [
                'tip' => 'toll',
                'furnizor' => EtollImportService::SUPPLIER,
                'moneda' => 'RON',
                'sursa' => 'etoll',
                'sursa_key' => $sourceKey,
                'created_by' => $userId,
            ] + ($document ?? []));
        } else {
            $id = (int) $existing['id'];
            if ($existing['deleted_at'] !== null) {
                // Stearsa intre timp din Facturi: alegerea operatorului ramane.
                return;
            }
            $this->invoices->update($id, $fields);
            if ($filesChanged) {
                $this->setInvoiceDocument($id, $document);
            }
            $current = $this->invoices->getById($id);
            if ($momentChanged && $current !== null && !in_array((string) $current['status'], ['asociata_manual', 'respinsa'], true)) {
                // Ora inchiderii decide cursa; update() reface asocierea doar cand se schimba ziua.
                $this->invoices->runMatching($id, true);
            }
        }
        $this->db->prepare('UPDATE etoll_sesiuni SET factura_id = :f WHERE session_id = :id')->execute([':f' => $id, ':id' => $session['session_id']]);
    }

    /**
     * Un singur PDF pentru Facturi (factura + anexa + desfasurator), ca la import; null fara PDF-uri.
     *
     * @param array<string, ?string> $paths
     * @return array<string, mixed>|null coloanele document_* ale facturii
     */
    private function invoiceDocument(array $session, ?string $number, array $paths): ?array
    {
        $parts = [];
        foreach ($paths as $relative) {
            $absolute = EtollStatementSyncService::resolvePath($this->root, $relative);
            if ($absolute !== null) {
                $parts[] = (string) file_get_contents($absolute);
            }
        }
        if ($parts === []) {
            return null;
        }
        try {
            $content = count($parts) === 1 ? $parts[0] : EtollPdfMerger::merge($parts);
        } catch (Throwable) {
            $content = $parts[0];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'etm');
        if ($tmp === false || file_put_contents($tmp, $content) === false) {
            return null;
        }
        try {
            $name = sprintf('eToll_%s_%s_%s.pdf', $session['nr_inmatriculare'], substr((string) $session['inchisa_la'], 0, 10), preg_replace('/[^A-Za-z0-9]+/', '', (string) $number) ?: 'manual');
            [$document] = $this->storage->storeFromPath($tmp, $name);
        } finally {
            @unlink($tmp);
        }

        return $document;
    }

    /** @param array<string, mixed>|null $document */
    private function setInvoiceDocument(int $invoiceId, ?array $document): void
    {
        $this->db->prepare('
            UPDATE facturi SET document_path = :p, document_original_name = :n, document_mime = :m, document_size = :s, document_sha256 = :h, updated_at = :u
            WHERE id = :id
        ')->execute([
            ':p' => $document['document_path'] ?? null,
            ':n' => $document['document_original_name'] ?? null,
            ':m' => $document['document_mime'] ?? null,
            ':s' => $document['document_size'] ?? null,
            ':h' => $document['document_sha256'] ?? null,
            ':u' => date('Y-m-d H:i:s'),
            ':id' => $invoiceId,
        ]);
        $this->invoices->syncMirror($invoiceId);
    }

    private function invoiceNotes(array $session): string
    {
        $parts = ['Sesiune eToll adăugată manual (lipsă din sincronizarea eToll)', 'închisă la ' . date('d.m.Y H:i', strtotime((string) $session['inchisa_la']))];
        if (!empty($session['remorci'])) {
            $parts[] = 'remorca ' . $session['remorci'];
        }

        return implode(', ', $parts) . '.' . (!empty($session['observatii']) ? ' ' . $session['observatii'] : '');
    }

    // -------------------------------------------------------------------------
    // Alimentare
    // -------------------------------------------------------------------------

    /**
     * Creeaza sau modifica o alimentare manuala a portofelului (cu factura ei, daca are numar sau PDF).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $files $_FILES['factura_alimentare']
     */
    public function saveTopup(array $input, array $files, ?int $userId, ?string $txId = null): string
    {
        $existing = $txId !== null ? $this->existing($txId, 'alimentare') : null;

        $paidAt = self::dateTime($input['data_plata'] ?? null, 'Data și ora plății');
        if ($paidAt === null) {
            throw new InvalidArgumentException('Completează data și ora plății.');
        }
        if ($paidAt > date('Y-m-d H:i:s', time() + 3600)) {
            throw new InvalidArgumentException('Plata nu poate fi în viitor.');
        }
        $amount = self::amount($input['suma_alimentare'] ?? null);
        if ($amount === null || $amount <= 0 || $amount >= 10000000) {
            throw new InvalidArgumentException('Completează suma alimentării (lei), mai mare decât 0.');
        }
        [$net, $vat] = self::vatSplit($amount, $input['cota_tva_alimentare'] ?? null, $input['fara_tva_alimentare'] ?? null);
        $number = self::text($input['numar_factura_alimentare'] ?? null, 60);
        $notes = self::text($input['observatii_alimentare'] ?? null, 255);

        $id = $existing !== null ? (string) $existing['tx_id'] : self::newId();
        $this->assertNotDuplicate('alimentare', $id, $amount, $paidAt, null);

        $oldPdf = $existing['af_pdf_path'] ?? null;
        $pdf = $this->storePdf($files['factura_alimentare'] ?? null, 'alimentari/' . substr($paidAt, 0, 7), $id, 'Factura alimentării');
        if ($pdf !== null) {
            if ($oldPdf !== null && $oldPdf !== $pdf) {
                $this->removeFile($oldPdf);
            }
        } elseif ($oldPdf !== null && !empty($input['sterge_factura_alimentare'])) {
            $this->removeFile($oldPdf);
        } else {
            $pdf = $oldPdf;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->prepare('
                INSERT INTO etoll_tranzactii (tx_id, tip, suma, data, in_curs, nr_inmatriculare, remorci, session_id, observatii, adaugat_de, created_at, updated_at)
                VALUES (:id, \'alimentare\', :suma, :data, 0, NULL, NULL, NULL, :obs, :user, :c, :u)
                ON DUPLICATE KEY UPDATE suma = VALUES(suma), data = VALUES(data), observatii = VALUES(observatii), updated_at = VALUES(updated_at)
            ')->execute([':id' => $id, ':suma' => self::money($amount), ':data' => $paidAt, ':obs' => $notes, ':user' => $userId, ':c' => $now, ':u' => $now]);

            // Factura: aceeasi ora si suma ca plata, deci topups() o pune pe acelasi rand.
            $this->db->prepare('DELETE FROM etoll_alimentari_facturi WHERE invoice_id = :id')->execute([':id' => $id]);
            if ($number !== null || $pdf !== null) {
                $this->db->prepare('
                    INSERT INTO etoll_alimentari_facturi (invoice_id, numar_document, data, total, fara_tva, tva, pdf_path, created_at)
                    VALUES (:id, :nr, :data, :total, :net, :vat, :pdf, :c)
                ')->execute([':id' => $id, ':nr' => $number, ':data' => $paidAt, ':total' => self::money($amount), ':net' => self::money($net),
                    ':vat' => self::money($vat), ':pdf' => $pdf, ':c' => $now]);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        return $id;
    }

    // -------------------------------------------------------------------------
    // Stergere
    // -------------------------------------------------------------------------

    /** Sterge o inregistrare manuala (si factura ei din Facturi, logic) cu PDF-urile ei. */
    public function delete(string $txId): void
    {
        $row = (new EtollStatementModel($this->db))->manualRecord($txId);
        if ($row === null) {
            throw new InvalidArgumentException('Înregistrarea manuală nu mai există.');
        }
        $files = array_filter([$row['factura_path'] ?? null, $row['anexa_path'] ?? null, $row['desfasurator_path'] ?? null, $row['af_pdf_path'] ?? null]);

        if ((string) $row['tip'] === 'sesiune') {
            $invoice = $this->invoices->findBySourceKey('etoll:' . $txId);
            if ($invoice !== null && $invoice['deleted_at'] === null) {
                $this->invoices->softDelete((int) $invoice['id']);
            }
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM etoll_documente WHERE session_id = :id')->execute([':id' => $txId]);
            $this->db->prepare('DELETE FROM etoll_sesiuni WHERE session_id = :id')->execute([':id' => $txId]);
            $this->db->prepare('DELETE FROM etoll_alimentari_facturi WHERE invoice_id = :id')->execute([':id' => $txId]);
            $this->db->prepare('DELETE FROM etoll_tranzactii WHERE tx_id = :id')->execute([':id' => $txId]);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        foreach ($files as $file) {
            $this->removeFile((string) $file);
        }
    }

    // -------------------------------------------------------------------------
    // Inlocuirea cu datele eToll
    // -------------------------------------------------------------------------

    /**
     * Inregistrarile manuale pe care eToll le-a adus intre timp: aceeasi suma (la un ban), acelasi
     * camion (sesiuni), la cel mult MATCH_HOURS de ora introdusa; cea mai apropiata castiga si fiecare
     * miscare reala inlocuieste o singura inregistrare manuala. Cea manuala ramane in baza (istoric),
     * dar iese din pagina si din calcule; factura ei din Facturi se sterge logic, iar factura manuala
     * a unei alimentari pleaca (vine cea reala).
     *
     * Ruleaza la sincronizarea situatiei (etoll_tranzactii) si la importul in Facturi (etoll_sesiuni).
     *
     * @return array<int, string> randurile de log
     */
    public static function reconcile(PDO $db, ?InvoiceModel $invoices = null): array
    {
        $manual = $db->query("
            SELECT tx_id, tip, suma, data, nr_inmatriculare FROM etoll_tranzactii
            WHERE tx_id LIKE 'manual-%' AND inlocuita_de IS NULL AND in_curs = 0
            ORDER BY data ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($manual === []) {
            return [];
        }
        $claimed = array_fill_keys(array_map('strval', $db->query('SELECT inlocuita_de FROM etoll_tranzactii WHERE inlocuita_de IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) ?: []), true);

        $sessionCandidates = $db->prepare("
            SELECT session_id AS id, data FROM etoll_tranzactii
            WHERE tip = 'sesiune' AND in_curs = 0 AND tx_id NOT LIKE 'manual-%' AND session_id IS NOT NULL
              AND nr_inmatriculare = :nr1 AND ABS(suma - :suma1) <= :tol1 AND data BETWEEN :a1 AND :b1
            UNION
            SELECT session_id AS id, inchisa_la AS data FROM etoll_sesiuni
            WHERE session_id NOT LIKE 'manual-%' AND nr_inmatriculare = :nr2 AND ABS(suma - :suma2) <= :tol2 AND inchisa_la BETWEEN :a2 AND :b2
        ");
        $topupCandidates = $db->prepare("
            SELECT tx_id AS id, data FROM etoll_tranzactii
            WHERE tip = 'alimentare' AND tx_id NOT LIKE 'manual-%' AND ABS(suma - :suma1) <= :tol1 AND data BETWEEN :a1 AND :b1
        ");
        $mark = $db->prepare('UPDATE etoll_tranzactii SET inlocuita_de = :real, inlocuita_la = :at WHERE tx_id = :id AND inlocuita_de IS NULL');

        $log = [];
        foreach ($manual as $row) {
            $at = strtotime((string) $row['data']);
            $window = [':a1' => date('Y-m-d H:i:s', $at - self::MATCH_HOURS * 3600), ':b1' => date('Y-m-d H:i:s', $at + self::MATCH_HOURS * 3600)];
            $amount = number_format((float) $row['suma'], 2, '.', '');
            if ((string) $row['tip'] === 'sesiune') {
                $sessionCandidates->execute($window + [
                    ':nr1' => (string) $row['nr_inmatriculare'], ':suma1' => $amount, ':tol1' => EtollStatementModel::TOLERANCE,
                    ':nr2' => (string) $row['nr_inmatriculare'], ':suma2' => $amount, ':tol2' => EtollStatementModel::TOLERANCE,
                    ':a2' => $window[':a1'], ':b2' => $window[':b1'],
                ]);
                $candidates = $sessionCandidates->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $topupCandidates->execute($window + [':suma1' => $amount, ':tol1' => EtollStatementModel::TOLERANCE]);
                $candidates = $topupCandidates->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            $best = null;
            foreach ($candidates as $candidate) {
                $gap = abs(strtotime((string) $candidate['data']) - $at);
                if (!isset($claimed[(string) $candidate['id']]) && ($best === null || $gap < $best[1])) {
                    $best = [(string) $candidate['id'], $gap];
                }
            }
            if ($best === null) {
                continue;
            }

            $mark->execute([':real' => $best[0], ':at' => date('Y-m-d H:i:s'), ':id' => $row['tx_id']]);
            if ($mark->rowCount() === 0) {
                continue;
            }
            $claimed[$best[0]] = true;
            if ((string) $row['tip'] === 'sesiune') {
                $invoices ??= new InvoiceModel($db);
                $invoice = $invoices->findBySourceKey('etoll:' . $row['tx_id']);
                if ($invoice !== null && $invoice['deleted_at'] === null) {
                    $invoices->softDelete((int) $invoice['id']);
                }
            } else {
                $db->prepare('DELETE FROM etoll_alimentari_facturi WHERE invoice_id = :id')->execute([':id' => $row['tx_id']]);
            }
            $log[] = sprintf('Inregistrarea manuala %s (%s%s, %s lei, %s) e inlocuita de eToll: %s.', $row['tx_id'], $row['tip'],
                $row['nr_inmatriculare'] ? ' ' . $row['nr_inmatriculare'] : '', $amount, $row['data'], $best[0]);
        }

        return $log;
    }

    // -------------------------------------------------------------------------
    // Ajutatoare
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function existing(string $txId, string $type): array
    {
        $row = (new EtollStatementModel($this->db))->manualRecord($txId);
        if ($row === null || (string) $row['tip'] !== $type) {
            throw new InvalidArgumentException('Înregistrarea manuală nu mai există.');
        }
        if ($row['inlocuita_de'] !== null) {
            throw new InvalidArgumentException('Înregistrarea a fost înlocuită de datele eToll și nu se mai poate modifica.');
        }

        return $row;
    }

    /**
     * Nu lasa o inregistrare manuala peste o miscare pe care eToll a adus-o deja (sau peste alta manuala
     * identica): ar dubla consumul.
     */
    private function assertNotDuplicate(string $type, string $selfId, float $amount, string $at, ?string $plate): void
    {
        $sql = "SELECT tx_id, data FROM etoll_tranzactii WHERE tip = :tip AND tx_id <> :self AND inlocuita_de IS NULL
                AND ABS(suma - :suma) <= :tol AND data BETWEEN :a AND :b";
        $params = [
            ':tip' => $type, ':self' => $selfId, ':suma' => self::money($amount), ':tol' => EtollStatementModel::TOLERANCE,
            ':a' => date('Y-m-d H:i:s', strtotime($at) - self::MATCH_HOURS * 3600), ':b' => date('Y-m-d H:i:s', strtotime($at) + self::MATCH_HOURS * 3600),
        ];
        if ($plate !== null) {
            $sql .= ' AND nr_inmatriculare = :nr';
            $params[':nr'] = $plate;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY ABS(TIMESTAMPDIFF(SECOND, data, :at)) LIMIT 1');
        $stmt->execute($params + [':at' => $at]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }
        $what = $type === 'sesiune' ? 'o sesiune ' . $plate : 'o alimentare';
        $source = EtollStatementModel::isManual((string) $row['tx_id']) ? 'adăugată manual' : 'adusă din eToll';
        throw new InvalidArgumentException(sprintf('Există deja %s de %s lei %s, la %s. Nu o adăuga a doua oară.',
            $what, number_format($amount, 2, ',', '.'), $source, date('d.m.Y H:i', strtotime((string) $row['data']))));
    }

    /** Salveaza un PDF incarcat sub storage/etoll/manual/; null daca nu s-a incarcat nimic. */
    private function storePdf(mixed $file, string $folder, string $name, string $label): ?string
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($label . ': fișierul nu a putut fi încărcat' . (in_array((int) $file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? ' (prea mare).' : '.'));
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException($label . ': fișierul încărcat nu este valid.');
        }
        $size = (int) @filesize($tmp);
        if ($size <= 0 || $size > self::MAX_PDF_BYTES) {
            throw new InvalidArgumentException($label . ': fișierul trebuie să aibă între 1 B și 10 MB.');
        }
        $mime = class_exists(finfo::class) ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : '';
        $head = (string) file_get_contents($tmp, false, null, 0, 5);
        if ($head !== '%PDF-' || ($mime !== '' && $mime !== 'application/pdf')) {
            throw new InvalidArgumentException($label . ': se acceptă doar PDF.');
        }

        $relative = self::STORAGE_DIR . '/' . trim($folder, '/') . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '', $name) . '.pdf';
        $absolute = $this->root . '/' . $relative;
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nu s-a putut crea folderul ' . $dir . '.');
        }
        if (!move_uploaded_file($tmp, $absolute)) {
            throw new RuntimeException($label . ': fișierul nu a putut fi salvat pe server.');
        }

        return $relative;
    }

    private function removeFile(string $relative): void
    {
        // Doar fisierele manuale; PDF-urile sincronizate din eToll nu se sterg de aici.
        if (str_starts_with($relative, self::STORAGE_DIR . '/')) {
            $path = EtollStatementSyncService::resolvePath($this->root, $relative);
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    private static function kindLabel(string $kind): string
    {
        return EtollStatementModel::DOCUMENTS[$kind][0] ?? $kind;
    }

    private static function newId(): string
    {
        return EtollStatementModel::MANUAL_PREFIX . bin2hex(random_bytes(10));
    }

    /**
     * Fara TVA / TVA / cota: din cota (implicit 21%) sau din valoarea fara TVA scrisa de operator.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function vatSplit(float $gross, mixed $rateInput, mixed $netInput): array
    {
        $rate = self::amount($rateInput);
        $rate = $rate === null || $rate < 0 || $rate > 50 ? self::DEFAULT_VAT : $rate;
        $net = self::amount($netInput);
        if ($net !== null) {
            if ($net <= 0 || $net > $gross + EtollStatementModel::TOLERANCE) {
                throw new InvalidArgumentException('Valoarea fără TVA trebuie să fie pozitivă și cel mult egală cu suma cu TVA.');
            }
            $net = round($net, 2);
        } else {
            $net = round($gross / (1 + $rate / 100), 2);
        }

        return [$net, round($gross - $net, 2), $rate];
    }

    /** "1.234,56" / "1234.56" / "1234,5" -> float; gol -> null. */
    public static function amount(mixed $value): ?float
    {
        $text = str_replace([' ', "\u{00A0}", 'lei'], '', trim((string) $value));
        if ($text === '') {
            return null;
        }
        if (str_contains($text, ',')) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        }
        if (!is_numeric($text)) {
            throw new InvalidArgumentException('Valoare numerică invalidă: ' . (string) $value . '.');
        }

        return (float) $text;
    }

    /** "2026-11-05T14:28" (datetime-local) -> "2026-11-05 14:28:00"; gol -> null. */
    private static function dateTime(mixed $value, string $label): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/', $text) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr(str_replace('T', ' ', $text), 0, 16))
            : false;
        if ($date === false || (int) $date->format('Y') < 2020) {
            throw new InvalidArgumentException($label . ': dată invalidă.');
        }

        return $date->format('Y-m-d H:i:00');
    }

    private static function text(mixed $value, int $max): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
