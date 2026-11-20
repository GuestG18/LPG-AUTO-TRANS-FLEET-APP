<?php
declare(strict_types=1);

/**
 * Fotografia unui articol din Catalog echipamente.
 *
 * Aceleași garanții ca UserAvatarService: tipul real se citește cu getimagesize()
 * (ce declară browserul e ignorat), imaginea se re-codează cu GD (orice conținut
 * ascuns în fișier dispare), numele pe disc e aleator și nu provine de la
 * utilizator. Spre deosebire de avatar, imaginea NU se decupează pătrat și
 * transparența PNG / WEBP se păstrează (fotografii decupate pe fundal transparent
 * arată bine în bulele de pe siluetă).
 *
 * Fișierele stau în uploads/echipamente (directorul uploads/ interzice execuția PHP).
 */
class EquipmentPhotoService
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
    private const MAX_OUTPUT_EDGE = 900;
    private const MAX_SOURCE_EDGE = 8000;
    private const JPEG_QUALITY = 86;
    private const NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png)$/';

    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? (BASE_PATH . '/uploads/echipamente');
    }

    /**
     * Salvează fișierul încărcat; întoarce numele fișierului salvat.
     *
     * @throws InvalidArgumentException cu mesaj pentru utilizator
     */
    public function store(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('Fotografia depășește limita de 5 MB.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Fotografia nu a putut fi încărcată.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new InvalidArgumentException('Fișierul încărcat nu este valid.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Fotografia trebuie să aibă între 1 octet și 5 MB.');
        }

        $info = @getimagesize($tmpPath);
        if (!is_array($info) || !isset($info[0], $info[1], $info[2])) {
            throw new InvalidArgumentException('Fișierul nu este o imagine validă.');
        }
        [$width, $height, $type] = [(int) $info[0], (int) $info[1], (int) $info[2]];
        if ($width < 32 || $height < 32) {
            throw new InvalidArgumentException('Fotografia este prea mică (minim 32×32 px).');
        }
        if ($width > self::MAX_SOURCE_EDGE || $height > self::MAX_SOURCE_EDGE) {
            throw new InvalidArgumentException('Fotografia are dimensiuni prea mari.');
        }

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmpPath),
            IMAGETYPE_PNG => @imagecreatefrompng($tmpPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
            default => false,
        };
        if (!$source instanceof GdImage) {
            throw new InvalidArgumentException('Format neacceptat. Folosește JPG, PNG sau WEBP.');
        }

        // Transparența contează doar pentru PNG / WEBP; JPG rămâne JPG.
        $keepAlpha = $type !== IMAGETYPE_JPEG;
        $scale = min(1, self::MAX_OUTPUT_EDGE / max($width, $height));
        $outW = max(1, (int) round($width * $scale));
        $outH = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($outW, $outH);
        if ($keepAlpha) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        } else {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        }
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $outW, $outH, $width, $height);
        imagedestroy($source);

        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
            imagedestroy($canvas);
            throw new RuntimeException('Directorul pentru fotografii nu este disponibil.');
        }

        $filename = bin2hex(random_bytes(16)) . ($keepAlpha ? '.png' : '.jpg');
        $target = $this->storageDir . '/' . $filename;
        $written = $keepAlpha ? imagepng($canvas, $target, 7) : imagejpeg($canvas, $target, self::JPEG_QUALITY);
        imagedestroy($canvas);

        if (!$written || !is_file($target)) {
            throw new RuntimeException('Fotografia nu a putut fi salvată pe server.');
        }
        @chmod($target, 0644);

        return $filename;
    }

    /** Șterge doar fișiere produse de acest serviciu (nume aleator hex). */
    public function delete(?string $filename): void
    {
        $safe = basename(trim((string) $filename));
        if ($safe === '' || preg_match(self::NAME_PATTERN, $safe) !== 1) {
            return;
        }
        $path = $this->storageDir . '/' . $safe;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function publicUrl(?string $filename): ?string
    {
        $safe = basename(trim((string) $filename));
        if ($safe === '' || preg_match(self::NAME_PATTERN, $safe) !== 1 || !is_file($this->storageDir . '/' . $safe)) {
            return null;
        }

        return url('uploads/echipamente/' . rawurlencode($safe) . '?v=' . (string) filemtime($this->storageDir . '/' . $safe));
    }
}
