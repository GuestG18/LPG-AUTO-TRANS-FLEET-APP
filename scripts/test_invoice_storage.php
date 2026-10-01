<?php
declare(strict_types=1);

/**
 * Test pentru stocarea facturilor (InvoiceStorageService).
 *
 *   php scripts/test_invoice_storage.php
 *
 * Lucreaza intr-o radacina temporara (sys_get_temp_dir), nu in storage/ al
 * proiectului, si o sterge la final. Fisierele de test vin din test_facturi_ocr/.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/services/InvoiceStorageService.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "\033[32m  PASS\033[0m  $name\n";
    } else {
        $failed++;
        echo "\033[31m  FAIL\033[0m  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n";
    }
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            remove_tree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

$tempRoot = str_replace('\\', '/', sys_get_temp_dir()) . '/fleet_invoice_storage_' . bin2hex(random_bytes(4));
mkdir($tempRoot . '/htdocs/uploads/curse_cheltuieli', 0777, true);
mkdir($tempRoot . '/htdocs/config', 0777, true);
file_put_contents($tempRoot . '/.env', 'SECRET=1');
file_put_contents($tempRoot . '/htdocs/config/config.php', '<?php');
file_put_contents($tempRoot . '/htdocs/uploads/curse_cheltuieli/cazare_test.pdf', '%PDF-1.4 test');

$pdf = $root . '/test_facturi_ocr/01_simplu_un_vehicul_piese.pdf';
$png = $root . '/test_facturi_ocr/10_scan_piese_manopera.png';
$storage = new InvoiceStorageService($tempRoot);

try {
    echo "\nSalvare\n";
    [$doc, $error] = $storage->storeFromPath($pdf, 'Factura vama #1.pdf');
    check('PDF salvat fara eroare', $doc !== null && $error === null, (string) $error);
    $sha = hash_file('sha256', $pdf);
    check('cale storage/invoices/YYYY/MM/<sha256>.pdf', $doc !== null && $doc['document_path'] === 'storage/invoices/' . date('Y') . '/' . date('m') . '/' . $sha . '.pdf', (string) ($doc['document_path'] ?? ''));
    check('fisierul exista pe disc', $doc !== null && is_file($tempRoot . '/' . $doc['document_path']));
    check('mime, marime, sha256, nume curatat', $doc !== null && $doc['document_mime'] === 'application/pdf' && $doc['document_size'] === filesize($pdf) && $doc['document_sha256'] === $sha && $doc['document_original_name'] === 'Factura_vama_1.pdf');

    [$again] = $storage->storeFromPath($pdf, 'alt nume.pdf');
    check('acelasi continut -> acelasi fisier (fara dublura pe disc)', $again !== null && $again['document_path'] === $doc['document_path'] && count(glob($tempRoot . '/storage/invoices/*/*/*') ?: []) === 1);

    [$image, $error] = $storage->storeFromPath($png, 'scan.png');
    check('PNG acceptat', $image !== null && $image['document_mime'] === 'image/png', (string) $error);

    echo "\nRespinse\n";
    $txt = $tempRoot . '/nota.txt';
    file_put_contents($txt, 'text');
    [$doc2, $error] = $storage->storeFromPath($txt, 'nota.txt');
    check('.txt respins', $doc2 === null && $error !== null);
    [$doc2, $error] = $storage->storeFromPath($png, 'deghizat.pdf');
    check('PNG redenumit .pdf respins (continut != extensie)', $doc2 === null && str_contains((string) $error, 'nu corespunde'), (string) $error);
    $empty = $tempRoot . '/gol.pdf';
    file_put_contents($empty, '');
    [$doc2, $error] = $storage->storeFromPath($empty, 'gol.pdf');
    check('fisier gol respins', $doc2 === null && $error !== null);
    [$doc2, $error] = $storage->storeFromPath($tempRoot . '/nu-exista.pdf');
    check('fisier inexistent respins', $doc2 === null && $error !== null);
    [$doc2, $error] = $storage->storeUpload(null);
    check('upload lipsa -> mesaj', $doc2 === null && $error !== null);

    echo "\nCitire (ruta de document)\n";
    check('factura salvata se rezolva', $storage->resolveReadablePath($doc['document_path']) !== null);
    check('fisierul de cazare din uploads/curse_cheltuieli se rezolva', $storage->resolveReadablePath(InvoiceStorageService::legacyCazarePath('cazare_test.pdf')) !== null);
    check('"../" catre .env -> refuzat', $storage->resolveReadablePath('storage/invoices/../../.env') === null);
    check('.env direct -> refuzat', $storage->resolveReadablePath('.env') === null);
    check('htdocs/config/config.php -> refuzat', $storage->resolveReadablePath('htdocs/config/config.php') === null);
    check('cale absoluta -> tratata relativ, refuzata', $storage->resolveReadablePath($tempRoot . '/.env') === null);
    check('cale goala / inexistenta -> null', $storage->resolveReadablePath('') === null && $storage->resolveReadablePath('storage/invoices/lipsa.pdf') === null);
    check('basename taie "../" la fisierele de cazare', InvoiceStorageService::legacyCazarePath('../../.env') === 'htdocs/uploads/curse_cheltuieli/.env');
} finally {
    remove_tree($tempRoot);
}

echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m") . "Rezultat: $passed trecute, $failed picate\033[0m\n";
exit($failed === 0 ? 0 : 1);
