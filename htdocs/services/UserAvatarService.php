<?php
declare(strict_types=1);

/**
 * Avatar storage + image normalisation for "Profilul meu".
 *
 * SECURITY MODEL
 *   - The browser-supplied filename and MIME type are NEVER trusted. The real
 *     type is determined by getimagesize() on the uploaded bytes.
 *   - Every accepted image is fully DECODED and RE-ENCODED through GD to a
 *     square JPEG. That destroys any polyglot/embedded payload; no original
 *     bytes ever reach the filesystem.
 *   - Filenames are randomly generated; the stored value is always passed
 *     through basename() before touching the filesystem, so a tampered DB value
 *     cannot traverse out of the avatar directory.
 *   - The upload directory denies PHP execution (uploads/.htaccess plus its own).
 */
class UserAvatarService
{
    /** Final avatar edge length in pixels. */
    public const OUTPUT_SIZE = 512;

    /** Hard upload ceiling (bytes) — matches the 2 MB stated in the UI. */
    public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    /** Sanity ceiling on source dimensions, to avoid decompression bombs. */
    private const MAX_SOURCE_EDGE = 8000;

    private const JPEG_QUALITY = 88;

    /** Emoji palette offered in the UI. Persisted value is validated against it. */
    public const EMOJI_CHOICES = ['😊', '😎', '🚚', '👩‍💻', '🦊', '⚙️'];

    /** Background colours paired with an emoji avatar. */
    public const AVATAR_COLORS = ['#dbeafe', '#dcfce7', '#fef3c7', '#fae8ff', '#fee2e2', '#e2e8f0'];

    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? (BASE_PATH . '/uploads/avatare');
    }

    public function storageDir(): string
    {
        return $this->storageDir;
    }

    /**
     * Validate + normalise an uploaded (already client-cropped) image and store it.
     *
     * @param array<string,mixed> $file One entry from $_FILES
     * @return array{ok:bool, filename:?string, error:?string}
     */
    public function storeUploadedImage(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return $this->fail($this->uploadErrorMessage($error));
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return $this->fail('Fișierul încărcat nu este valid.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return $this->fail('Fișierul încărcat este gol.');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            return $this->fail('Imaginea depășește limita de 2 MB.');
        }

        // Authoritative type detection — the browser's claim is ignored.
        $info = @getimagesize($tmpPath);
        if (!is_array($info) || !isset($info[0], $info[1], $info[2])) {
            return $this->fail('Fișierul nu este o imagine validă.');
        }

        [$width, $height, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];

        if ($width < 32 || $height < 32) {
            return $this->fail('Imaginea este prea mică (minim 32×32 px).');
        }
        if ($width > self::MAX_SOURCE_EDGE || $height > self::MAX_SOURCE_EDGE) {
            return $this->fail('Imaginea are dimensiuni prea mari.');
        }

        $source = $this->decode($tmpPath, $type);
        if ($source === null) {
            return $this->fail('Formatul imaginii nu este acceptat (JPG, PNG sau WEBP).');
        }

        try {
            $canvas = $this->toSquareCanvas($source, $width, $height);
        } finally {
            imagedestroy($source);
        }

        if ($canvas === null) {
            return $this->fail('Nu s-a putut procesa imaginea.');
        }

        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            imagedestroy($canvas);
            return $this->fail('Directorul pentru avatare nu este disponibil.');
        }

        $filename = $this->generateFilename();
        $target = $this->storageDir . '/' . $filename;

        $written = imagejpeg($canvas, $target, self::JPEG_QUALITY);
        imagedestroy($canvas);

        if (!$written || !is_file($target)) {
            return $this->fail('Nu s-a putut salva imaginea pe server.');
        }

        @chmod($target, 0644);

        return ['ok' => true, 'filename' => $filename, 'error' => null];
    }

    /**
     * Delete a previously stored avatar file.
     * basename() guarantees the operation stays inside the avatar directory.
     */
    public function deleteStoredImage(?string $filename): void
    {
        $filename = trim((string) $filename);
        if ($filename === '') {
            return;
        }

        $safe = basename($filename);
        if ($safe === '' || $safe === '.' || $safe === '..') {
            return;
        }
        if (preg_match('/^[a-f0-9]{32}\.jpg$/i', $safe) !== 1) {
            // Not a filename this service produced — refuse to delete anything.
            return;
        }

        $path = $this->storageDir . '/' . $safe;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Public URL for a stored avatar file, or null when unavailable. */
    public function publicUrl(?string $filename): ?string
    {
        $filename = trim((string) $filename);
        if ($filename === '') {
            return null;
        }

        $safe = basename($filename);
        if (preg_match('/^[a-f0-9]{32}\.jpg$/i', $safe) !== 1) {
            return null;
        }
        if (!is_file($this->storageDir . '/' . $safe)) {
            return null;
        }

        return url('uploads/avatare/' . rawurlencode($safe));
    }

    public static function isAllowedEmoji(string $emoji): bool
    {
        return in_array($emoji, self::EMOJI_CHOICES, true);
    }

    public static function normalizeColor(string $color): ?string
    {
        $color = trim($color);

        return in_array($color, self::AVATAR_COLORS, true) ? $color : null;
    }

    // -----------------------------------------------------------------

    /** @return array{ok:false, filename:null, error:string} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'filename' => null, 'error' => $message];
    }

    private function generateFilename(): string
    {
        return bin2hex(random_bytes(16)) . '.jpg';
    }

    /** @return GdImage|null */
    private function decode(string $path, int $type)
    {
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        return $image instanceof GdImage ? $image : null;
    }

    /**
     * Centre-crop to a square and resample to OUTPUT_SIZE on a white background
     * (so transparent PNG/WEBP do not become black once flattened to JPEG).
     *
     * @param GdImage $source
     * @return GdImage|null
     */
    private function toSquareCanvas($source, int $width, int $height)
    {
        $edge = min($width, $height);
        $srcX = (int) max(0, floor(($width - $edge) / 2));
        $srcY = (int) max(0, floor(($height - $edge) / 2));

        $canvas = imagecreatetruecolor(self::OUTPUT_SIZE, self::OUTPUT_SIZE);
        if (!$canvas instanceof GdImage) {
            return null;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, self::OUTPUT_SIZE, self::OUTPUT_SIZE, $white);

        $ok = imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $srcX,
            $srcY,
            self::OUTPUT_SIZE,
            self::OUTPUT_SIZE,
            $edge,
            $edge
        );

        if (!$ok) {
            imagedestroy($canvas);
            return null;
        }

        return $canvas;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Imaginea depășește limita de 2 MB.',
            UPLOAD_ERR_PARTIAL => 'Încărcarea a fost întreruptă. Reîncearcă.',
            UPLOAD_ERR_NO_FILE => 'Nu a fost selectat niciun fișier.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Serverul nu a putut scrie fișierul temporar.',
            UPLOAD_ERR_EXTENSION => 'Încărcarea a fost blocată de server.',
            default => 'Fișierul nu a putut fi încărcat.',
        };
    }
}
