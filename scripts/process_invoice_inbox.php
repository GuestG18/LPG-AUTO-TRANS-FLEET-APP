<?php
declare(strict_types=1);

/**
 * Pasul 2 din fluxul facturilor scanate: coada locala -> Facturi -> citire cu Claude.
 *
 *   php scripts/process_invoice_inbox.php                 preia inbox-ul si citeste cel mult 10 scanari
 *   php scripts/process_invoice_inbox.php --limit=3
 *   php scripts/process_invoice_inbox.php --no-ocr        doar preia fisierele (fara apeluri la Claude)
 *   php scripts/process_invoice_inbox.php --file=x.pdf    doar citeste un fisier si afiseaza JSON-ul
 *                                                         (test; nu scrie nimic in baza)
 *   php scripts/process_invoice_inbox.php --file=x.pdf --piese   la fel, cu citirea pentru registrul de piese
 *
 * 1. Preluare: fiecare pereche <fisier> + <fisier>.json lasata de fetch_invoice_emails.py
 *    in storage/invoices/inbox/ devine un rand in Facturi (sursa "scan", status
 *    "in_procesare"), cu fisierul mutat in storage/invoices/YYYY/MM/<sha256>.<ext>.
 *    Aceeasi scanare (acelasi sha256) nu se dubleaza.
 * 2. Citire: randurile "in_procesare" se trimit la Claude (InvoiceOcrService). Datele citite
 *    completeaza factura, care trece prin asocierea automata la cursa ca orice factura.
 *    O scanare cu mai multe documente devine mai multe facturi, cu acelasi fisier.
 *    Erorile trecatoare se reincearca de cel mult 3 ori; apoi factura ramane "de verificat".
 * 3. Piese: scanarile cu subiectul "piese" / "reparatii" / "service" / "revizie" nu intra in
 *    Facturi, ci in registrul de piese (?page=ocr_piese), citite cu PartsInvoiceOcrService:
 *    antet + articole + vehicule / km / garantie, cat apare pe factura (OcrPartsScanService).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/InvoiceModel.php';
require_once $root . '/htdocs/services/InvoiceStorageService.php';
require_once $root . '/htdocs/services/InvoiceOcrService.php';
require_once $root . '/htdocs/models/OcrPartsModel.php';
require_once $root . '/htdocs/services/PartsInvoiceOcrService.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';
require_once $root . '/htdocs/services/OcrPartsScanService.php';

$limit = 10;
$runOcr = true;
$testFile = null;
$testParts = false;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=(\d+)$/', $argument, $match) === 1) {
        $limit = max(1, (int) $match[1]);
    } elseif ($argument === '--no-ocr') {
        $runOcr = false;
    } elseif (str_starts_with($argument, '--file=')) {
        $testFile = substr($argument, 7);
    } elseif ($argument === '--piese') {
        $testParts = true;
    } else {
        fwrite(STDERR, "Argument necunoscut: $argument\n");
        exit(2);
    }
}

$log = static fn(string $message) => print('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");

// -----------------------------------------------------------------------------
// Mod test: un singur fisier, fara baza de date
// -----------------------------------------------------------------------------
if ($testFile !== null) {
    $path = realpath($testFile);
    if ($path === false || !is_file($path)) {
        fwrite(STDERR, "Fisierul nu exista: $testFile\n");
        exit(2);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string) finfo_file($finfo, $path);
    finfo_close($finfo);

    $ocr = $testParts ? new PartsInvoiceOcrService() : new InvoiceOcrService();
    $log('Citesc ' . basename($path) . " ($mime) cu " . $ocr->model() . '...');
    try {
        $started = microtime(true);
        $result = $ocr->extract($path, $mime);
        echo json_encode($result['facturi'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $log(sprintf('%d document(e) | tokeni: %d intrare, %d iesire | %.1f s',
            count($result['facturi']), $result['usage']['input_tokens'], $result['usage']['output_tokens'], microtime(true) - $started));
        exit(0);
    } catch (InvoiceOcrException $exception) {
        fwrite(STDERR, 'EROARE: ' . $exception->getMessage() . ($exception->retryable ? ' (se poate reincerca)' : '') . "\n");
        exit(1);
    }
}

// -----------------------------------------------------------------------------
// Rulare normala
// -----------------------------------------------------------------------------
$db = get_pdo();
$model = new InvoiceModel($db);
$model->ensureFacturiSchema();
$storage = new InvoiceStorageService($root);
$inboxDir = $root . '/storage/invoices/inbox';
if (!is_dir($inboxDir)) {
    @mkdir($inboxDir, 0750, true);
}

$partsModel = new OcrPartsModel($db);
$partsScans = new OcrPartsScanService($partsModel);

$summary = ['rulat_la' => date('c'), 'preluate' => 0, 'duplicate' => 0, 'citite' => 0, 'facturi_create' => 0, 'esecuri' => 0,
    'piese_preluate' => 0, 'piese_citite' => 0, 'piese_esecuri' => 0, 'erori' => []];

// 1. Preluare din inbox
foreach (glob($inboxDir . '/*.json') ?: [] as $metaPath) {
    $meta = json_decode((string) file_get_contents($metaPath), true);
    $filePath = is_array($meta) ? $inboxDir . '/' . basename((string) ($meta['fisier'] ?? '')) : '';
    if (!is_array($meta) || !is_file($filePath)) {
        $summary['erori'][] = basename($metaPath) . ': pereche incompleta, sarita';
        continue;
    }

    try {
        // Subiect "piese" / "reparatii" / "service" -> registrul de piese, nu Facturi.
        if (OcrPartsScanService::isPartsSubject($meta['email_subiect'] ?? null)) {
            [$outcome, $partsId] = $partsScans->ingest($filePath, $meta);
            if ($outcome === 'duplicat') {
                $summary['duplicate']++;
                $log('Duplicat in registrul de piese: ' . ($meta['email_subiect'] ?? basename($filePath)));
            } else {
                $summary['piese_preluate']++;
                $log("Preluata pentru registrul de piese #$partsId: " . ($meta['email_subiect'] ?? '') . ' (' . ($meta['nume_original'] ?? '') . ')');
            }
            @unlink($filePath);
            @unlink($metaPath);
            continue;
        }

        [$document, $error] = $storage->storeFromPath($filePath, (string) ($meta['nume_original'] ?? basename($filePath)));
        if ($document === null) {
            throw new RuntimeException((string) $error);
        }

        $sourceKey = 'scan:' . $document['document_sha256'];
        if ($model->findBySourceKey($sourceKey) !== null) {
            $summary['duplicate']++;
            $log('Duplicat (aceeasi scanare exista deja): ' . ($meta['email_subiect'] ?? basename($filePath)));
        } else {
            $received = !empty($meta['email_primit_la']) ? date('Y-m-d H:i:s', strtotime((string) $meta['email_primit_la'])) : null;
            $id = $model->create($document + [
                // Tipul real il stabileste citirea; pana atunci randul e "in procesare".
                'tip' => 'alte',
                'status' => 'in_procesare',
                'sursa' => 'scan',
                'sursa_key' => $sourceKey,
                'email_message_id' => mb_substr((string) ($meta['email_message_id'] ?? ''), 0, 255) ?: null,
                'email_subiect' => mb_substr((string) ($meta['email_subiect'] ?? ''), 0, 255) ?: null,
                'email_primit_la' => $received,
            ]);
            $summary['preluate']++;
            $log("Preluata scanarea #$id: " . ($meta['email_subiect'] ?? '') . ' (' . ($meta['nume_original'] ?? '') . ')');
        }

        // Fisierul e acum in storage/invoices/YYYY/MM (sau era deja): perechea din inbox pleaca.
        @unlink($filePath);
        @unlink($metaPath);
    } catch (Throwable $exception) {
        $summary['erori'][] = basename($filePath) . ': ' . $exception->getMessage();
        $log('EROARE la preluare ' . basename($filePath) . ': ' . $exception->getMessage());
    }
}

// 2. Citire cu Claude
if ($runOcr) {
    $ocr = new InvoiceOcrService();

    foreach ($model->getPendingOcr($limit) as $row) {
        $id = (int) $row['id'];
        $path = $storage->resolveReadablePath($row['document_path'] ?? null);
        if ($path === null) {
            $model->recordOcrFailure($id, 'fisierul scanat lipseste de pe server.', true);
            $summary['esecuri']++;
            continue;
        }

        try {
            $result = $ocr->extract($path, (string) $row['document_mime'], ['email_subiect' => $row['email_subiect'] ?? null]);
        } catch (InvoiceOcrException $exception) {
            $model->recordOcrFailure($id, $exception->getMessage(), !$exception->retryable);
            $summary['esecuri']++;
            $log("EROARE la citirea #$id: " . $exception->getMessage());
            continue;
        }

        $summary['citite']++;
        $ids = $model->applyOcrResult($id, $result['facturi'], [
            'model' => $result['model'],
            'usage' => $result['usage'],
        ]);

        if ($result['facturi'] === []) {
            $log("#$id: nicio factura in scanare -> respinsa (se poate anula din pagina facturii)");
            continue;
        }
        $summary['facturi_create'] += count($ids) - 1;
        foreach ($ids as $targetId) {
            $saved = $model->getById($targetId);
            $log(sprintf('#%d %s | %s | %s %s | %s | %s: %s',
                $targetId, $saved['tip'] ?? '?', $saved['furnizor'] ?? '-', $saved['valoare_cu_tva'] ?? '-', $saved['moneda'] ?? '',
                $saved['data_document'] ?? '-', $saved['status'] ?? '?', $saved['match_reason'] ?? ''));
        }
    }
}

// 3. Registrul de piese
if ($runOcr) {
    $partsResult = $partsScans->processPending(new PartsInvoiceOcrService(), $limit, $log);
    $summary['piese_citite'] = $partsResult['citite'];
    $summary['piese_esecuri'] = $partsResult['esecuri'];
}

@file_put_contents($inboxDir . '/.last_ocr.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$log(sprintf('Preluate: %d | duplicate: %d | citite: %d | facturi suplimentare: %d | esecuri: %d | piese: %d preluate, %d citite, %d esecuri',
    $summary['preluate'], $summary['duplicate'], $summary['citite'], $summary['facturi_create'], $summary['esecuri'],
    $summary['piese_preluate'], $summary['piese_citite'], $summary['piese_esecuri']));
exit($summary['erori'] === [] ? 0 : 1);
