<?php
declare(strict_types=1);

/**
 * Fisierele facturilor din pagina "Facturi".
 *
 * Facturile noi stau in storage/invoices/YYYY/MM/<sha256>.<ext>, IN AFARA web
 * root-ului (htdocs): pe VPS aplicatia ruleaza cu `php -S`, care ignora .htaccess,
 * deci orice fisier din htdocs/uploads e public. Se deschid doar prin
 * ?page=facturi&action=document&id=X (autentificat).
 *
 * In baza se tine calea RELATIVA la radacina proiectului (facturi.document_path).
 * Facturile de cazare raman unde erau (htdocs/uploads/curse_cheltuieli) si sunt
 * citite de acolo; nu se muta.
 */
class InvoiceStorageService
{
    public const MAX_SIZE = 10485760; // 10 MB

    public const STORAGE_DIR = 'storage/invoices';

    /** Radacinile din care ruta de document are voie sa serveasca fisiere. */
    private const READABLE_ROOTS = ['storage/invoices', 'htdocs/uploads/curse_cheltuieli'];

    /** extensie => tipuri MIME acceptate (finfo) */
    private const ALLOWED = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot ?? dirname(__DIR__, 2)), '/');
    }

    /**
     * Salveaza un fisier venit prin formular ($_FILES['...']).
     *
     * @return array{0: ?array{document_path: string, document_original_name: string, document_mime: string, document_size: int, document_sha256: string}, 1: ?string}
     */
    public function storeUpload(?array $file): array
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, 'Alege fisierul facturii.'];
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return [null, (int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'Fisierul depaseste limita permisa de server.'
                : 'Factura nu a putut fi incarcata.'];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return [null, 'Fisierul incarcat nu este valid.'];
        }

        return $this->store($tmpName, (string) ($file['name'] ?? 'factura'), true);
    }

    /**
     * Copiaza un fisier deja aflat pe server (pentru pipeline-ul de scanare).
     *
     * @return array{0: ?array{document_path: string, document_original_name: string, document_mime: string, document_size: int, document_sha256: string}, 1: ?string}
     */
    public function storeFromPath(string $sourcePath, ?string $originalName = null): array
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return [null, 'Fisierul sursa nu exista: ' . $sourcePath];
        }

        return $this->store($sourcePath, $originalName ?? basename($sourcePath), false);
    }

    /**
     * @return array{0: ?array{document_path: string, document_original_name: string, document_mime: string, document_size: int, document_sha256: string}, 1: ?string}
     */
    private function store(string $sourcePath, string $originalName, bool $isUpload): array
    {
        $size = (int) @filesize($sourcePath);
        if ($size <= 0) {
            return [null, 'Fisierul este gol.'];
        }
        if ($size > self::MAX_SIZE) {
            return [null, 'Fisierul depaseste limita de 10 MB.'];
        }

        $originalName = $this->sanitizeFileName($originalName);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$extension])) {
            return [null, 'Tipul fisierului nu este permis (PDF, JPG, PNG).'];
        }

        $mime = $this->detectMime($sourcePath);
        if (!in_array($mime, self::ALLOWED[$extension], true)) {
            return [null, 'Continutul fisierului nu corespunde extensiei .' . $extension . ' (detectat: ' . ($mime !== '' ? $mime : 'necunoscut') . ').'];
        }

        $sha256 = hash_file('sha256', $sourcePath);
        if ($sha256 === false) {
            return [null, 'Fisierul nu a putut fi citit.'];
        }

        $relativeDir = self::STORAGE_DIR . '/' . date('Y') . '/' . date('m');
        $absoluteDir = $this->projectRoot . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) {
            return [null, 'Nu s-a putut crea folderul ' . $relativeDir . '.'];
        }
        $this->ensureDenyFile();

        // Extensia normalizata: .jpeg si .jpg sunt acelasi tip.
        $storedExtension = $extension === 'jpeg' ? 'jpg' : $extension;
        $relativePath = $relativeDir . '/' . $sha256 . '.' . $storedExtension;
        $absolutePath = $this->projectRoot . '/' . $relativePath;

        // Acelasi continut deja salvat (de ex. a doua incarcare): nu il mai scriem.
        if (!is_file($absolutePath)) {
            $ok = $isUpload ? @move_uploaded_file($sourcePath, $absolutePath) : @copy($sourcePath, $absolutePath);
            if (!$ok) {
                return [null, 'Fisierul nu a putut fi salvat pe server.'];
            }
            @chmod($absolutePath, 0640);
        }

        return [[
            'document_path' => $relativePath,
            'document_original_name' => $originalName,
            'document_mime' => $mime,
            'document_size' => $size,
            'document_sha256' => $sha256,
        ], null];
    }

    /**
     * Calea absoluta a unui document, doar daca exista si sta intr-una din radacinile
     * permise (protejeaza de "../" si de cai absolute scrise in baza).
     */
    public function resolveReadablePath(?string $relativePath): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', trim((string) $relativePath)), '/');
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }

        $real = realpath($this->projectRoot . '/' . $relativePath);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $real = str_replace('\\', '/', $real);

        foreach (self::READABLE_ROOTS as $root) {
            $rootReal = realpath($this->projectRoot . '/' . $root);
            if ($rootReal === false) {
                continue;
            }
            $rootReal = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
            if (str_starts_with(strtolower($real), strtolower($rootReal))) {
                return $real;
            }
        }

        return null;
    }

    /** Calea relativa pentru un fisier de cazare existent (uploads/curse_cheltuieli). */
    public static function legacyCazarePath(string $storedFile): string
    {
        return 'htdocs/uploads/curse_cheltuieli/' . basename($storedFile);
    }

    /**
     * A doua linie de aparare pentru serverele Apache al caror DocumentRoot e radacina
     * repo-ului (ex. vhost-ul Laragon local): storage/invoices nu se serveste direct.
     * Pe VPS (php -S din htdocs) folderul e oricum in afara web root-ului.
     */
    private function ensureDenyFile(): void
    {
        $file = $this->projectRoot . '/' . self::STORAGE_DIR . '/.htaccess';
        if (!is_file($file)) {
            @file_put_contents($file, "Require all denied\n");
        }
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }
        $mime = (string) (finfo_file($finfo, $path) ?: '');
        finfo_close($finfo);

        return $mime;
    }

    private function sanitizeFileName(string $name): string
    {
        $name = trim(basename(str_replace('\\', '/', $name)));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'factura';

        return substr($name, 0, 180);
    }
}
