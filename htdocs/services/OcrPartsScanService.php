<?php
declare(strict_types=1);

/**
 * Facturile de piese / service scanate, pentru registrul ?page=ocr_piese.
 *
 * Acelasi drum ca la Facturi (imprimanta -> Gmail -> fetch_invoice_emails.py ->
 * storage/invoices/inbox -> process_invoice_inbox.php), doar ca scanarile cu subiectul
 * "piese" / "reparatii" / "service" / "revizie" (aceleasi cuvinte ca tipul Reparatii din
 * Facturi) vin aici in loc de Facturi si se citesc cu PartsInvoiceOcrService.
 *
 * Fisierul ajunge in htdocs/uploads/ocr_piese (ca la recepția manuala), randul apare in
 * registru imediat ("Se citește"), iar dupa citire ramane "De verificat".
 */
class OcrPartsScanService
{
    private const UPLOAD_DIR = 'uploads/ocr_piese';

    private const EXTENSIONS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    public function __construct(private OcrPartsModel $model)
    {
    }

    /** Scanarea merge in registrul de piese (subiectul numeste tipul Reparatii)? */
    public static function isPartsSubject(?string $subject): bool
    {
        return InvoiceModel::typeFromSubject($subject) === 'service';
    }

    /**
     * Preia o scanare din inbox. Fisierul din inbox ramane; il sterge apelantul.
     *
     * @param array<string,mixed> $meta .json-ul lasat de fetch_invoice_emails.py
     * @return array{0:string,1:?int} ['creata' | 'duplicat', id ocr_piese_facturi]
     */
    public function ingest(string $filePath, array $meta): array
    {
        $this->model->ensureScanSchema();

        $sha = hash_file('sha256', $filePath);
        if ($sha === false) {
            throw new RuntimeException('Fisierul nu poate fi citit.');
        }
        $existing = $this->model->findScanBySourceKey('scan:' . $sha);
        if ($existing !== null) {
            return ['duplicat', (int) $existing['id']];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($finfo, $filePath);
        finfo_close($finfo);
        $extension = self::EXTENSIONS[$mime] ?? null;
        if ($extension === null) {
            throw new RuntimeException('Tip de fisier neacceptat: ' . $mime);
        }

        $directory = BASE_PATH . '/' . self::UPLOAD_DIR;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Directorul ' . self::UPLOAD_DIR . ' nu poate fi creat.');
        }
        $storedName = date('Ymd_His') . '_' . substr($sha, 0, 12) . '.' . $extension;
        if (!copy($filePath, $directory . '/' . $storedName)) {
            throw new RuntimeException('Fisierul nu a putut fi copiat in ' . self::UPLOAD_DIR . '.');
        }

        try {
            $received = !empty($meta['email_primit_la']) ? date('Y-m-d H:i:s', strtotime((string) $meta['email_primit_la'])) : null;
            $id = $this->model->createScanEntry([
                'sursa_key' => 'scan:' . $sha,
                'fisier_original' => (string) ($meta['nume_original'] ?? basename($filePath)),
                'fisier_stocat' => $storedName,
                'document_mime' => $mime,
                'email_subiect' => isset($meta['email_subiect']) ? (string) $meta['email_subiect'] : null,
                'email_primit_la' => $received,
            ]);
        } catch (Throwable $exception) {
            @unlink($directory . '/' . $storedName);
            throw $exception;
        }

        return ['creata', $id];
    }

    /**
     * Citeste scanarile "Se citește" cu Claude.
     *
     * @param callable(string):void $log
     * @return array{citite:int,esecuri:int}
     */
    public function processPending(PartsInvoiceOcrService $ocr, int $limit, callable $log): array
    {
        $this->model->ensureScanSchema();
        $summary = ['citite' => 0, 'esecuri' => 0];
        $fleet = null;

        foreach ($this->model->getPendingScans($limit) as $row) {
            $id = (int) $row['id'];
            $path = BASE_PATH . '/' . self::UPLOAD_DIR . '/' . basename((string) $row['fisier_stocat']);
            if (!is_file($path)) {
                $this->model->recordScanFailure($id, 'Fisierul scanat lipseste de pe server.', true);
                $summary['esecuri']++;
                continue;
            }

            $fleet ??= $this->model->getFleetPlates();
            try {
                $result = $ocr->extract($path, (string) $row['document_mime'], [
                    'email_subiect' => $row['email_subiect'] ?? null,
                    'flota' => $fleet,
                ]);
            } catch (InvoiceOcrException $exception) {
                $this->model->recordScanFailure($id, $exception->getMessage(), !$exception->retryable);
                $summary['esecuri']++;
                $log("Piese #$id: EROARE la citire: " . $exception->getMessage());
                continue;
            }

            $summary['citite']++;
            $eventIds = $this->model->applyScanResult($id, $result['facturi'], ['model' => $result['model']]);
            if ($result['facturi'] === []) {
                $log("Piese #$id: nicio factura in scanare -> Citire eșuată (se completeaza manual)");
                continue;
            }
            foreach ($result['facturi'] as $index => $invoice) {
                $log(sprintf('Piese registru #%s | %s | %s | %s %s | %d articole | vehicule: %s',
                    $eventIds[$index] ?? '?', $invoice['furnizor'] ?? '-', $invoice['data_document'] ?? '-',
                    $invoice['valoare_cu_tva'] ?? '-', $invoice['moneda'] ?? '', count($invoice['articole']),
                    $invoice['nr_inmatriculare'] !== [] ? implode(', ', $invoice['nr_inmatriculare']) : '-'));
            }
            $log(sprintf('  tokeni: %d intrare, %d iesire', $result['usage']['input_tokens'], $result['usage']['output_tokens']));
        }

        return $summary;
    }
}
