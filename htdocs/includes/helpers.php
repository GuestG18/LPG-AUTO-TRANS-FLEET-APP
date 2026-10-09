<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars(normalize_romanian_text((string) $value), ENT_QUOTES, 'UTF-8');
}

function normalize_romanian_text(string $value): string
{
    if ($value === '') {
        return '';
    }

    static $replacements = [
        // Single mojibake (UTF-8 interpreted as latin1/win1252).
        "\u{00C3}\u{0082}" => "\u{00C2}", // Ã‚ => Â
        "\u{00C3}\u{00A2}" => "\u{00E2}", // Ã¢ => â
        "\u{00C3}\u{017D}" => "\u{00CE}", // ÃŽ => Î
        "\u{00C3}\u{00AE}" => "\u{00EE}", // Ã® => î
        "\u{00C4}\u{201A}" => "\u{0102}", // Ä‚ => Ă
        "\u{00C4}\u{0192}" => "\u{0103}", // Äƒ => ă
        "\u{00C8}\u{02DC}" => "\u{0218}", // È˜ => Ș
        "\u{00C8}\u{2122}" => "\u{0219}", // È™ => ș
        "\u{00C8}\u{0161}" => "\u{021A}", // Èš => Ț
        "\u{00C8}\u{203A}" => "\u{021B}", // È› => ț

        // Double mojibake variants seen in old files.
        "\u{00C3}\u{0192}\u{00E2}\u{20AC}\u{0161}" => "\u{00C2}",
        "\u{00C3}\u{0192}\u{00C2}\u{00A2}" => "\u{00E2}",
        "\u{00C3}\u{0192}\u{00C5}\u{00BD}" => "\u{00CE}",
        "\u{00C3}\u{0192}\u{00C2}\u{00AE}" => "\u{00EE}",
        "\u{00C3}\u{201E}\u{20AC}\u{0161}" => "\u{0102}",
        "\u{00C3}\u{201E}\u{00C6}\u{2019}" => "\u{0103}",
        "\u{00C3}\u{02C6}\u{02DC}" => "\u{0218}",
        "\u{00C3}\u{02C6}\u{2122}" => "\u{0219}",
        "\u{00C3}\u{02C6}\u{0161}" => "\u{021A}",
        "\u{00C3}\u{02C6}\u{203A}" => "\u{021B}",
        "\u{00C3}\u{2026}\u{00C5}\u{00BE}" => "\u{0218}",
        "\u{00C3}\u{2026}\u{0178}" => "\u{0219}",
        "\u{00C3}\u{2026}\u{00C2}\u{00A2}" => "\u{021A}",
        "\u{00C3}\u{2026}\u{00C2}\u{00A3}" => "\u{021B}",
    ];

    $normalized = strtr($value, $replacements);

    if (!has_mojibake_markers($normalized) || contains_romanian_diacritics($normalized)) {
        return $normalized;
    }

    return strtr(repair_utf8_mojibake($normalized), $replacements);
}

function repair_utf8_mojibake(string $value): string
{
    if (!has_mojibake_markers($value) || !function_exists('iconv')) {
        return $value;
    }

    $current = $value;
    $previousScore = mojibake_marker_score($current);

    for ($i = 0; $i < 6; $i++) {
        if ($previousScore === 0) {
            break;
        }

        // Reverse one accidental UTF-8 -> cp1252 reinterpretation layer.
        $bytes = @iconv('UTF-8', 'Windows-1252//IGNORE', $current);
        if (!is_string($bytes) || $bytes === '') {
            break;
        }

        // Scrub invalid UTF-8 bytes similarly to decode(..., errors='ignore').
        $candidate = @iconv('UTF-8', 'UTF-8//IGNORE', $bytes);
        if (!is_string($candidate) || $candidate === '' || $candidate === $current) {
            break;
        }

        $candidateScore = mojibake_marker_score($candidate);
        if ($candidateScore > $previousScore) {
            break;
        }

        $current = $candidate;
        $previousScore = $candidateScore;
    }

    return $current;
}

function has_mojibake_markers(string $value): bool
{
    return str_contains($value, 'Ã')
        || str_contains($value, 'Ä')
        || str_contains($value, 'Å')
        || str_contains($value, 'È')
        || str_contains($value, 'â€')
        || str_contains($value, 'â‚')
        || str_contains($value, 'â„')
        || str_contains($value, 'â€¦');
}

function contains_romanian_diacritics(string $value): bool
{
    return (bool) preg_match('/[ĂăÂâÎîȘșȚț]/u', $value);
}

function mojibake_marker_score(string $value): int
{
    $score = 0;
    $score += substr_count($value, 'Ã');
    $score += substr_count($value, 'Ä');
    $score += substr_count($value, 'Å');
    $score += substr_count($value, 'È');
    $score += substr_count($value, 'â€');
    $score += substr_count($value, 'â‚');
    $score += substr_count($value, 'â„');
    $score += substr_count($value, 'â€¦');

    return $score;
}

function url(string $path = ''): string
{
    $base = BASE_URL;

    if ($path === '') {
        return $base;
    }

    return ($base !== '' ? $base . '/' : '') . ltrim($path, '/');
}

function absolute_url(string $path = ''): string
{
    $base = APP_URL;

    if ($path === '') {
        return $base;
    }

    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function redirect(string $location): void
{
    header('Location: ' . $location);
    exit;
}

/**
 * Mesaj flash, optional cu un link catre inregistrarea la care se refera
 * (de exemplu cursa care blocheaza salvarea). Linkul se transmite structurat,
 * nu ca HTML in mesaj: sablonul il scrie escapat.
 *
 * @param array{url: string, label: string}|null $link
 */
function flash_set(string $type, string $message, ?array $link = null): void
{
    $url = trim((string) ($link['url'] ?? ''));
    $label = trim((string) ($link['label'] ?? ''));
    $_SESSION['_flash_messages'][$type][] = $url !== '' && $label !== ''
        ? ['message' => $message, 'url' => $url, 'label' => $label]
        : $message;
}

function flash_messages(): array
{
    $messages = $_SESSION['_flash_messages'] ?? [];
    unset($_SESSION['_flash_messages']);

    return $messages;
}

function set_form_flash(array $old, array $errors): void
{
    $_SESSION['_form_old'] = $old;
    $_SESSION['_form_errors'] = $errors;
}

function consume_form_flash(): array
{
    $data = [
        'old' => $_SESSION['_form_old'] ?? [],
        'errors' => $_SESSION['_form_errors'] ?? [],
    ];

    unset($_SESSION['_form_old'], $_SESSION['_form_errors']);

    return $data;
}

function render(string $view, array $data = []): void
{
    $viewFile = BASE_PATH . '/views/' . $view;

    if (!file_exists($viewFile)) {
        throw new RuntimeException('View inexistent: ' . $view);
    }

    extract($data, EXTR_SKIP);

    $showSidebar = $showSidebar ?? (function_exists('is_logged_in') ? is_logged_in() : false);
    $pageTitle = $pageTitle ?? APP_NAME;
    $currentPage = $currentPage ?? '';

    ob_start();
    require BASE_PATH . '/views/layout/header.php';
    require $viewFile;
    require BASE_PATH . '/views/layout/footer.php';
    $content = (string) ob_get_clean();

    // Paginile mari (ex. Dispecer curse, cateva MB de HTML) se trimit comprimate.
    // ob_gzhandler negociaza singur Accept-Encoding; sarim daca raspunsul e deja
    // comprimat sau daca render() e capturat intr-un buffer al apelantului.
    if (
        !headers_sent()
        && extension_loaded('zlib')
        && !filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)
        && ob_get_level() <= 1
    ) {
        ob_start('ob_gzhandler');
    }

    // Final pass to correct legacy mojibake in static template strings.
    echo normalize_romanian_text($content);
}

function format_date_ro(?string $value): string
{
    if (empty($value)) {
        return '-';
    }

    try {
        return (new DateTime($value))->format('d.m.Y');
    } catch (Exception) {
        return (string) $value;
    }
}

function format_datetime_ro(?string $value): string
{
    if (empty($value)) {
        return '-';
    }

    try {
        return (new DateTime($value))->format('d.m.Y H:i');
    } catch (Exception) {
        return (string) $value;
    }
}

function format_number_ro(mixed $value, int $decimals = 2): string
{
    if (($value === null || $value === '') && $type === 'expiry') {
        return expiry_badge_html(null);
    }

    if ($value === null || $value === '') {
        return '-';
    }

    return number_format((float) $value, $decimals, ',', '.');
}

/**
 * Tarif unitar (lei/km, lei/tonă …): minim 2 zecimale, până la 4 fără zerouri inutile
 * — 1,24 rămâne 1,24, iar 1,239 nu mai e rotunjit la 1,24.
 */
function format_rate_ro(mixed $value, int $maxDecimals = 4): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    $formatted = number_format((float) $value, $maxDecimals, ',', '.');
    if ($maxDecimals > 2) {
        $formatted = preg_replace('/(,\d{2}\d*?)0+$/', '$1', $formatted) ?? $formatted;
    }

    return $formatted;
}

/** Valoare de tarif pentru câmpuri de formular: punct zecimal, 2–4 zecimale. */
function format_rate_input(mixed $value, int $maxDecimals = 4): string
{
    $formatted = number_format((float) $value, $maxDecimals, '.', '');
    if ($maxDecimals > 2) {
        $formatted = preg_replace('/(\.\d{2}\d*?)0+$/', '$1', $formatted) ?? $formatted;
    }

    return $formatted;
}

function format_year_ro(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return (string) (int) round((float) $value);
}

function yes_no_badge_html(mixed $value): string
{
    $normalized = (string) $value;
    $isEnabled = in_array($normalized, ['1', 'true', 'da', 'yes'], true);

    if ($isEnabled) {
        return '<span class="badge text-bg-success">Da</span>';
    }

    return '<span class="badge text-bg-secondary">Nu</span>';
}

function status_badge_html(string $status): string
{
    $normalized = strtolower(trim($status));

    if ($normalized === 'activ') {
        return '<span class="badge text-bg-success">Activ</span>';
    }

    if ($normalized === 'inactiv') {
        return '<span class="badge text-bg-secondary">Inactiv</span>';
    }

    if ($normalized === 'terminated') {
        return '<span class="badge text-bg-danger">Fost angajat</span>';
    }

    return '<span class="badge text-bg-light border">' . e($status) . '</span>';
}

function assembly_status_badge_html(?string $status): string
{
    if ($status === null || trim($status) === '') {
        return '';
    }

    $normalized = strtolower(trim($status));

    if ($normalized === 'activ') {
        return '<span class="badge text-bg-info">ANSAMBLU ACTIV</span>';
    }

    if ($normalized === 'inactiv') {
        return '<span class="badge text-bg-danger">ANSAMBLU INACTIV</span>';
    }

    return '<span class="badge text-bg-light border">ANSAMBLU ' . e(strtoupper($status)) . '</span>';
}

function uncoupled_tractor_badge_html(array $row): string
{
    $vehicleType = strtolower(trim((string) ($row['tip_vehicul'] ?? '')));
    if ($vehicleType !== 'cap_tractor') {
        return '';
    }

    $assemblyStatus = strtolower(trim((string) ($row['ansamblu_status'] ?? '')));
    if ($assemblyStatus !== '') {
        return '';
    }

    return '<span class="badge text-bg-warning text-dark">NECUPLAT</span>';
}

function role_badge_html(string $rol): string
{
    $normalized = strtolower(trim($rol));

    if ($normalized === 'admin') {
        return '<span class="badge text-bg-primary">Admin</span>';
    }

    if ($normalized === 'contabilitate') {
        return '<span class="badge text-bg-success">Contabilitate</span>';
    }

    return '<span class="badge text-bg-info text-dark">Operator</span>';
}

function role_display_name(string $rol): string
{
    $normalized = strtolower(trim($rol));

    return match ($normalized) {
        'admin' => 'admin',
        'contabilitate' => 'contabilitate',
        default => 'operator',
    };
}

function normalize_vehicle_type(string $type): string
{
    $normalized = strtolower(trim($type));

    return match ($normalized) {
        'autoturism', 'autovehicul', 'autoutilitara' => 'autovehicul',
        'camion' => 'camion',
        'cap_tractor' => 'cap_tractor',
        'semiremorca', 'semiremorca_primar', 'semiremorca_distributie' => 'semiremorca',
        default => $normalized !== '' ? $normalized : 'autovehicul',
    };
}

function normalize_vehicle_type_for_form_select(string $type): string
{
    $normalized = strtolower(trim($type));

    return match ($normalized) {
        'autoturism', 'autovehicul' => 'autovehicul',
        'autoutilitara' => 'autoutilitara',
        'camion' => 'camion',
        'cap_tractor' => 'cap_tractor',
        'semiremorca' => 'semiremorca_primar',
        'semiremorca_primar' => 'semiremorca_primar',
        'semiremorca_distributie' => 'semiremorca_distributie',
        default => 'autovehicul',
    };
}

function is_trailer_vehicle_type(string $type): bool
{
    return normalize_vehicle_type($type) === 'semiremorca';
}

function vehicle_type_label(string $type): string
{
    return match (strtolower(trim($type))) {
        'universal' => 'Universal',
        'cap_tractor' => 'Cap tractor',
        'semiremorca', 'semiremorca_primar' => 'Semi-remorca primar',
        'semiremorca_distributie' => 'Semi-remorca distributie',
        'camion' => 'Camion',
        'autovehicul', 'autoturism' => 'Autoturism',
        'autoutilitara' => 'Autoutilitara',
        default => '-',
    };
}

function getVehicleDocumentDailyCost(int $vehicleId): float
{
    if ($vehicleId <= 0 || !function_exists('get_pdo')) {
        return 0.0;
    }

    try {
        $db = get_pdo();
        $sqlWithOverride = '
            SELECT COALESCE(SUM(
                CASE
                    WHEN COALESCE(o.validity_days, c.validity_days) > 0
                        THEN COALESCE(o.document_cost, c.document_cost) / COALESCE(o.validity_days, c.validity_days)
                    ELSE 0
                END
            ), 0) AS daily_cost
            FROM vehicule v
            LEFT JOIN configurare_costuri_documente_vehicule c
              ON c.vehicle_type = (
                    CASE
                        WHEN v.tip_vehicul = "autoturism" THEN "autovehicul"
                        WHEN v.tip_vehicul = "semiremorca" THEN "semiremorca_primar"
                        ELSE v.tip_vehicul
                    END
                )
            LEFT JOIN configurare_costuri_documente_vehicule_override o
              ON o.vehicle_id = v.id
             AND o.document_type = c.document_type
            WHERE v.id = :vehicle_id
        ';

        $sqlByVehicleTypeOnly = '
            SELECT COALESCE(SUM(
                CASE
                    WHEN c.validity_days > 0
                        THEN c.document_cost / c.validity_days
                    ELSE 0
                END
            ), 0) AS daily_cost
            FROM vehicule v
            LEFT JOIN configurare_costuri_documente_vehicule c
              ON c.vehicle_type = (
                    CASE
                        WHEN v.tip_vehicul = "autoturism" THEN "autovehicul"
                        WHEN v.tip_vehicul = "semiremorca" THEN "semiremorca_primar"
                        ELSE v.tip_vehicul
                    END
                )
            WHERE v.id = :vehicle_id
        ';

        try {
            $stmt = $db->prepare($sqlWithOverride);
            $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
            $stmt->execute();
            $value = $stmt->fetchColumn();
        } catch (Throwable) {
            $stmt = $db->prepare($sqlByVehicleTypeOnly);
            $stmt->bindValue(':vehicle_id', $vehicleId, PDO::PARAM_INT);
            $stmt->execute();
            $value = $stmt->fetchColumn();
        }

        if (!is_numeric((string) $value)) {
            return 0.0;
        }

        return (float) $value;
    } catch (Throwable) {
        return 0.0;
    }
}

function tire_status_label(string $status): string
{
    return match (strtolower(trim($status))) {
        'in_stock' => 'In stoc',
        'active' => 'Montata',
        'spare' => 'Rezerva',
        'removed' => 'Scoasa din uz',
        'damaged' => 'Deteriorata',
        'missing' => 'Lipsa',
        'scrapped' => 'Casata',
        'retreaded' => 'Resapata',
        default => '-',
    };
}

function tire_status_badge_html(string $status): string
{
    $normalized = strtolower(trim($status));

    if ($normalized === 'in_stock') {
        return '<span class="badge text-bg-light border">In stoc</span>';
    }

    if ($normalized === 'active') {
        return '<span class="badge text-bg-success">Montata</span>';
    }

    if ($normalized === 'spare') {
        return '<span class="badge text-bg-primary">Rezerva</span>';
    }

    if ($normalized === 'removed') {
        return '<span class="badge text-bg-secondary">Scoasa din uz</span>';
    }

    if ($normalized === 'damaged') {
        return '<span class="badge text-bg-danger">Deteriorata</span>';
    }

    if ($normalized === 'missing') {
        return '<span class="badge text-bg-danger">Lipsa</span>';
    }

    if ($normalized === 'scrapped') {
        return '<span class="badge text-bg-dark">Casata</span>';
    }

    if ($normalized === 'retreaded') {
        return '<span class="badge text-bg-warning text-dark">Resapata</span>';
    }

    return '<span class="badge text-bg-light border">' . e($status) . '</span>';
}

function expiry_badge_html(?string $date): string
{
    if (empty($date)) {
        return '<span class="badge text-bg-secondary">Fără expirare</span>';
    }

    try {
        $today = new DateTime('today');
        $expiry = new DateTime($date);
        $days = (int) $today->diff($expiry)->format('%r%a');
        $labelDate = $expiry->format('d.m.Y');

        if ($days < 0) {
            return '<span class="badge text-bg-danger">Expirat: ' . e($labelDate) . '</span>';
        }

        if ($days <= 7) {
            return '<span class="badge text-bg-danger">Expir&#259; &icirc;n ' . e((string) $days) . ' zile (' . e($labelDate) . ')</span>';
        }

        if ($days <= 30) {
            return '<span class="badge text-bg-warning text-dark">Expir&#259; &icirc;n ' . e((string) $days) . ' zile (' . e($labelDate) . ')</span>';
        }

        return '<span class="badge text-bg-success">Valabil p&acirc;n&#259; la ' . e($labelDate) . '</span>';
    } catch (Exception) {
        return e($date);
    }
}

function document_file_url(?string $storedFile): ?string
{
    if ($storedFile === null || trim($storedFile) === '') {
        return null;
    }

    return url('uploads/documente/' . rawurlencode($storedFile));
}

function document_file_link_html(?string $originalFile, ?string $storedFile): string
{
    $fileUrl = document_file_url($storedFile);

    if ($fileUrl === null) {
        return '<span class="badge text-bg-light border">Fără fișier</span>';
    }

    $label = $originalFile !== null && trim($originalFile) !== '' ? $originalFile : 'Descarca fisier';

    return '<a class="btn btn-sm btn-outline-secondary" href="' . e($fileUrl) . '" target="_blank" rel="noopener">' . e($label) . '</a>';
}

function document_preview_available(?string $storedFile): bool
{
    if ($storedFile === null || trim($storedFile) === '') {
        return false;
    }

    $extension = strtolower(pathinfo($storedFile, PATHINFO_EXTENSION));

    return in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'], true);
}

function document_preview_html(?string $originalFile, ?string $storedFile): string
{
    $fileUrl = document_file_url($storedFile);

    if ($fileUrl === null) {
        return '<div class="alert alert-light border mb-0">Nu există fișier încărcat pentru acest document.</div>';
    }

    $safeUrl = e($fileUrl);
    $safeLabel = e($originalFile !== null && trim($originalFile) !== '' ? $originalFile : 'document');
    $extension = strtolower(pathinfo((string) $storedFile, PATHINFO_EXTENSION));

    if ($extension === 'pdf') {
        return '<div class="ratio ratio-4x3 border rounded overflow-hidden"><iframe src="' . $safeUrl . '" title="Previzualizare ' . $safeLabel . '" loading="lazy"></iframe></div>';
    }

    if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return '<div class="text-center border rounded p-3 bg-light"><img src="' . $safeUrl . '" alt="' . $safeLabel . '" class="img-fluid rounded"></div>';
    }

    return '<div class="alert alert-secondary mb-0">Previzualizarea directă este disponibilă pentru PDF și imagini. Pentru acest fișier folosește butonul de deschidere.</div>';
}

function vehicle_image_url(?string $storedFile): ?string
{
    if ($storedFile === null || trim($storedFile) === '') {
        return null;
    }

    return url('uploads/vehicule/' . rawurlencode($storedFile));
}

function driver_image_url(?string $storedFile): ?string
{
    if ($storedFile === null || trim($storedFile) === '') {
        return null;
    }

    return url('uploads/soferi/' . rawurlencode($storedFile));
}

/**
 * URL-ul unei miniaturi (latime maxima $maxWidth) pentru o poza din
 * uploads/<folder>/, generata o singura data cu GD in uploads/<folder>/thumbs/
 * si regenerata doar daca originalul e mai nou. Daca GD lipseste sau
 * generarea esueaza, se intoarce URL-ul originalului.
 */
function upload_image_thumb_url(string $folder, ?string $storedFile, int $maxWidth): ?string
{
    if ($storedFile === null || trim($storedFile) === '' || !preg_match('/^[a-z_]+$/', $folder)) {
        return null;
    }

    $storedFile = basename($storedFile);
    $originalUrl = url('uploads/' . $folder . '/' . rawurlencode($storedFile));
    $sourcePath = __DIR__ . '/../uploads/' . $folder . '/' . $storedFile;
    if (!is_file($sourcePath)) {
        return null;
    }

    $thumbName = $maxWidth . '_' . pathinfo($storedFile, PATHINFO_FILENAME) . '.jpg';
    $thumbDir = __DIR__ . '/../uploads/' . $folder . '/thumbs';
    $thumbPath = $thumbDir . '/' . $thumbName;
    $thumbUrl = url('uploads/' . $folder . '/thumbs/' . rawurlencode($thumbName));

    if (is_file($thumbPath) && filemtime($thumbPath) >= filemtime($sourcePath)) {
        return $thumbUrl;
    }
    if (!function_exists('imagecreatetruecolor')) {
        return $originalUrl;
    }

    try {
        $info = @getimagesize($sourcePath);
        if (!is_array($info) || $info[0] <= 0 || $info[1] <= 0) {
            return $originalUrl;
        }
        [$width, $height] = $info;
        if ($width <= $maxWidth) {
            return $originalUrl;
        }

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($sourcePath),
            default => false,
        };
        if ($source === false) {
            return $originalUrl;
        }

        $targetHeight = max(1, (int) round($height * $maxWidth / $width));
        $target = imagecreatetruecolor($maxWidth, $targetHeight);
        // Fundal alb pentru PNG/GIF transparente (JPEG nu are canal alfa).
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $maxWidth, $targetHeight, $width, $height);

        if (!is_dir($thumbDir) && !@mkdir($thumbDir, 0775, true) && !is_dir($thumbDir)) {
            return $originalUrl;
        }
        $saved = @imagejpeg($target, $thumbPath, 82);

        return $saved ? $thumbUrl : $originalUrl;
    } catch (Throwable $exception) {
        error_log('[upload_image_thumb_url] ' . $exception->getMessage());
        return $originalUrl;
    }
}

function inventory_equipment_image_url(?string $storedFile): ?string
{
    if ($storedFile === null || trim($storedFile) === '') {
        return null;
    }

    return url('uploads/inventar_dotari/' . rawurlencode($storedFile));
}

function inventory_equipment_status_badge_html(string $status): string
{
    $normalized = strtolower(trim($status));

    return match ($normalized) {
        'valid' => '<span class="badge text-bg-success">Valid</span>',
        'expira_curand' => '<span class="badge text-bg-warning text-dark">Expiră Curând</span>',
        'expirat' => '<span class="badge text-bg-danger">Expirat</span>',
        'lipsa_date' => '<span class="badge text-bg-secondary">Lipsă Date</span>',
        default => '<span class="badge text-bg-light border">' . e($status) . '</span>',
    };
}

function vehicle_image_thumb_html(?string $originalFile, ?string $storedFile): string
{
    $imageUrl = vehicle_image_url($storedFile);

    if ($imageUrl === null) {
        return '<div class="vehicle-thumb vehicle-thumb-placeholder">Fără poză</div>';
    }

    $safeUrl = e($imageUrl);
    $safeAlt = e($originalFile !== null && trim($originalFile) !== '' ? $originalFile : 'Poza vehicul');

    return '<a class="vehicle-thumb" href="' . $safeUrl . '" target="_blank" rel="noopener">'
        . '<img src="' . $safeUrl . '" alt="' . $safeAlt . '" loading="lazy">'
        . '</a>';
}

function driver_image_thumb_html(?string $originalFile, ?string $storedFile): string
{
    $imageUrl = driver_image_url($storedFile);

    if ($imageUrl === null) {
        return '<div class="vehicle-thumb vehicle-thumb-placeholder">Fără poză</div>';
    }

    $safeUrl = e($imageUrl);
    $safeAlt = e($originalFile !== null && trim($originalFile) !== '' ? $originalFile : 'Poză șofer');

    return '<a class="vehicle-thumb" href="' . $safeUrl . '" target="_blank" rel="noopener">'
        . '<img src="' . $safeUrl . '" alt="' . $safeAlt . '" loading="lazy">'
        . '</a>';
}

function vehicle_image_preview_html(?string $originalFile, ?string $storedFile): string
{
    $imageUrl = vehicle_image_url($storedFile);

    if ($imageUrl === null) {
        return '<div class="vehicle-photo-card vehicle-photo-empty">Nu există poză încărcată pentru acest vehicul.</div>';
    }

    $safeUrl = e($imageUrl);
    $safeAlt = e($originalFile !== null && trim($originalFile) !== '' ? $originalFile : 'Poza vehicul');
    $downloadLabel = $safeAlt !== '' ? $safeAlt : 'Deschide imaginea';

    return '<div class="vehicle-photo-card">'
        . '<img src="' . $safeUrl . '" alt="' . $safeAlt . '" class="img-fluid rounded" loading="lazy">'
        . '<div class="mt-3"><a class="btn btn-sm btn-outline-secondary" href="' . $safeUrl . '" target="_blank" rel="noopener">' . $downloadLabel . '</a></div>'
        . '</div>';
}

function driver_image_preview_html(?string $originalFile, ?string $storedFile): string
{
    $imageUrl = driver_image_url($storedFile);

    if ($imageUrl === null) {
        return '<div class="vehicle-photo-card vehicle-photo-empty">Nu există poză încărcată pentru acest șofer.</div>';
    }

    $safeUrl = e($imageUrl);
    $safeAlt = e($originalFile !== null && trim($originalFile) !== '' ? $originalFile : 'Poză șofer');
    $downloadLabel = $safeAlt !== '' ? $safeAlt : 'Deschide imaginea';

    return '<div class="vehicle-photo-card">'
        . '<img src="' . $safeUrl . '" alt="' . $safeAlt . '" class="img-fluid rounded" loading="lazy">'
        . '<div class="mt-3"><a class="btn btn-sm btn-outline-secondary" href="' . $safeUrl . '" target="_blank" rel="noopener">' . $downloadLabel . '</a></div>'
        . '</div>';
}

function audit_action_badge_html(string $action): string
{
    $normalized = strtolower(trim($action));

    return match ($normalized) {
        'create' => '<span class="badge text-bg-success">Creat</span>',
        'update' => '<span class="badge text-bg-warning text-dark">Actualizat</span>',
        'delete' => '<span class="badge text-bg-danger">Sters</span>',
        default => '<span class="badge text-bg-secondary">' . e($action) . '</span>',
    };
}

function notification_channel_badge_html(string $channel): string
{
    return match (strtolower(trim($channel))) {
        'email' => '<span class="badge text-bg-primary">Email</span>',
        'sms' => '<span class="badge text-bg-warning text-dark">SMS</span>',
        'whatsapp' => '<span class="badge text-bg-success">WhatsApp</span>',
        default => '<span class="badge text-bg-secondary">' . e($channel) . '</span>',
    };
}

function notification_status_badge_html(string $status): string
{
    return match (strtolower(trim($status))) {
        'trimis' => '<span class="badge text-bg-success">Trimis</span>',
        'esuat' => '<span class="badge text-bg-danger">Esuat</span>',
        'sarit' => '<span class="badge text-bg-secondary">Sarit</span>',
        default => '<span class="badge text-bg-light border">' . e($status) . '</span>',
    };
}

function notification_cycle_badge_html(string $status): string
{
    return match (strtolower(trim($status))) {
        'ciclu_activ' => '<span class="badge text-bg-warning text-dark">Ciclu activ</span>',
        'declansare_noua' => '<span class="badge text-bg-primary">Declansare noua</span>',
        default => '<span class="badge text-bg-light border">' . e($status) . '</span>',
    };
}

function normalize_phone_ro(?string $phone): ?string
{
    if ($phone === null) {
        return null;
    }

    $normalized = preg_replace('/\s+/', '', trim($phone));
    $normalized = preg_replace('/[^0-9+]/', '', (string) $normalized);

    if ($normalized === '') {
        return null;
    }

    if (str_starts_with($normalized, '+')) {
        return $normalized;
    }

    if (str_starts_with($normalized, '00')) {
        return '+' . substr($normalized, 2);
    }

    if (str_starts_with($normalized, '40')) {
        return '+' . $normalized;
    }

    if (str_starts_with($normalized, '0')) {
        return '+4' . $normalized;
    }

    return '+' . $normalized;
}

function notification_threshold_label(int $daysUntilExpiry): string
{
    if ($daysUntilExpiry < 0) {
        return 'Expirat';
    }

    if ($daysUntilExpiry === 0) {
        return 'Expira astazi';
    }

    if ($daysUntilExpiry === 1) {
        return 'Expira maine';
    }

    return 'Expira in ' . $daysUntilExpiry . ' zile';
}

function format_value_html(mixed $value, array $meta = [], array $row = []): string
{
    $type = $meta['type'] ?? 'text';

    if ($type === 'document_file') {
        return document_file_link_html(
            $value !== null ? (string) $value : null,
            isset($row['fisier_stocat']) ? (string) $row['fisier_stocat'] : null
        );
    }

    if ($type === 'vehicle_photo') {
        return vehicle_image_thumb_html(
            $value !== null ? (string) $value : null,
            isset($row['poza_stocata']) ? (string) $row['poza_stocata'] : null
        );
    }

    if ($type === 'vehicle_photo_detail') {
        return vehicle_image_preview_html(
            $value !== null ? (string) $value : null,
            isset($row['poza_stocata']) ? (string) $row['poza_stocata'] : null
        );
    }

    if ($type === 'driver_photo') {
        return driver_image_thumb_html(
            $value !== null ? (string) $value : null,
            isset($row['poza_stocata']) ? (string) $row['poza_stocata'] : null
        );
    }

    if ($type === 'driver_photo_detail') {
        return driver_image_preview_html(
            $value !== null ? (string) $value : null,
            isset($row['poza_stocata']) ? (string) $row['poza_stocata'] : null
        );
    }

    // Marcajul "capacitatea reala a fost verificata de un om". 0 este o valoare
    // reala (= de verificat), deci se trateaza inainte de scurtatura pentru gol.
    if ($type === 'capacity_verified') {
        return (string) $value === '1'
            ? '<span class="badge bg-success-subtle text-success-emphasis">Verificata</span>'
            : '<span class="badge bg-warning-subtle text-warning-emphasis">De verificat</span>';
    }

    if ($value === null || $value === '') {
        return '-';
    }

    switch ($type) {
        case 'date':
            return e(format_date_ro((string) $value));
        case 'datetime':
            return e(format_datetime_ro((string) $value));
        case 'year':
            return e(format_year_ro($value));
        case 'integer':
            return e(number_format((float) $value, 0, ',', '.'));
        case 'number':
            return e(format_number_ro($value, (int) ($meta['decimals'] ?? 2)));
        case 'currency':
            return e(format_number_ro($value, 2)) . ' lei';
        case 'status':
            $statusHtml = status_badge_html((string) $value);
            $assemblyStatus = $row['ansamblu_status'] ?? null;
            $assemblyHtml = assembly_status_badge_html(is_string($assemblyStatus) ? $assemblyStatus : null);
            $uncoupledHtml = uncoupled_tractor_badge_html($row);

            if ($assemblyHtml !== '') {
                $statusHtml .= ' ' . $assemblyHtml;
            }

            if ($uncoupledHtml !== '') {
                $statusHtml .= ' ' . $uncoupledHtml;
            }

            return $statusHtml;
        case 'vehicle_type':
            return e(vehicle_type_label((string) $value));
        case 'role':
            return role_badge_html((string) $value);
        case 'yes_no':
            return yes_no_badge_html($value);
        case 'expiry':
            return expiry_badge_html((string) $value);
        case 'textarea':
            return nl2br(e((string) $value));
        default:
            return e((string) $value);
    }
}

function build_query_url(array $params = []): string
{
    $cleanParams = [];

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }

        $cleanParams[$key] = $value;
    }

    $query = http_build_query($cleanParams);
    $base = url('index.php');

    return $query === '' ? $base : $base . '?' . $query;
}

/**
 * URL-ul unui document de cheltuiala de cursa (curse_cheltuieli_documente.file_path).
 *
 * Documentele venite din pagina Facturi stau in storage/invoices, in afara web
 * root-ului, si sunt marcate "facturi:<id>": se deschid prin ruta autentificata.
 * Restul stau, ca pana acum, in uploads/curse_cheltuieli.
 */
function trip_expense_document_url(string $filePath): ?string
{
    $filePath = trim($filePath);
    if ($filePath === '') {
        return null;
    }

    if (preg_match('/^facturi:(\d+)$/', $filePath, $match) === 1) {
        return build_query_url(['page' => 'facturi', 'action' => 'document', 'id' => (int) $match[1]]);
    }

    return url('uploads/curse_cheltuieli/' . rawurlencode($filePath));
}

function absolute_query_url(array $params = []): string
{
    $relative = build_query_url($params);

    return absolute_url(ltrim($relative, '/'));
}

function current_month_ro(): string
{
    $months = [
        1 => 'Ianuarie',
        2 => 'Februarie',
        3 => 'Martie',
        4 => 'Aprilie',
        5 => 'Mai',
        6 => 'Iunie',
        7 => 'Iulie',
        8 => 'August',
        9 => 'Septembrie',
        10 => 'Octombrie',
        11 => 'Noiembrie',
        12 => 'Decembrie',
    ];

    $month = (int) date('n');
    return ($months[$month] ?? '') . ' ' . date('Y');
}


/**
 * Rezumatul segmentelor unei curse: cine a condus fiecare portiune, cu ce vehicul,
 * in ce interval si cati km. Folosit ca tooltip in Desfasurator si in editare.
 */
function dispatcher_segments_summary(array $segments): string
{
    $lines = [];
    foreach ($segments as $index => $segment) {
        $vehicle = trim((string) ($segment['nr_inmatriculare'] ?? ''));
        $driver = trim((string) ($segment['sofer_nume'] ?? ''));
        $startDate = trim((string) ($segment['data_inceput'] ?? ''));
        $startTime = substr(trim((string) ($segment['ora_inceput'] ?? '')), 0, 5);
        $endDate = trim((string) ($segment['data_sfarsit'] ?? ''));
        $endTime = substr(trim((string) ($segment['ora_sfarsit'] ?? '')), 0, 5);
        $km = $segment['km'] ?? null;

        $interval = $startDate !== '' ? format_date_ro($startDate) : '-';
        if ($startTime !== '') {
            $interval .= ' ' . $startTime;
        }
        if ($endDate !== '') {
            $interval .= ' → ' . format_date_ro($endDate) . ($endTime !== '' ? ' ' . $endTime : '');
        }

        $lines[] = sprintf(
            'Segment %d: %s, %s, %s%s',
            (int) ($segment['ordine'] ?? ($index + 1)),
            $vehicle !== '' ? $vehicle : 'vehicul -',
            $driver !== '' ? $driver : 'sofer -',
            $interval,
            ($km !== null && $km !== '') ? ', ' . (int) $km . ' km' : ''
        );
    }

    return implode(' | ', $lines);
}

/**
 * Durata unui segment de cursa, in minute. Segmentele neinchise (fara sfarsit)
 * nu au durata cunoscuta, deci cantaresc 0.
 */
function dispatcher_segment_minutes(array $segment): int
{
    $startDate = trim((string) ($segment['data_inceput'] ?? ''));
    $endDate = trim((string) ($segment['data_sfarsit'] ?? ''));
    if ($startDate === '' || $endDate === '') {
        return 0;
    }

    $startTime = trim((string) ($segment['ora_inceput'] ?? ''));
    $endTime = trim((string) ($segment['ora_sfarsit'] ?? ''));
    $start = strtotime($startDate . ' ' . ($startTime !== '' ? $startTime : '00:00:00'));
    $end = strtotime($endDate . ' ' . ($endTime !== '' ? $endTime : '00:00:00'));
    if ($start === false || $end === false || $end <= $start) {
        return 0;
    }

    return (int) floor(($end - $start) / 60);
}

/**
 * Numarul de diurne pentru o durata in minute: prima diurna se castiga la 12h,
 * fiecare urmatoare dupa inca 24h (0–11:59 = 0, 12:00–35:59 = 1, 36:00–59:59 = 2 ...).
 * Singurul loc unde se aplica regula; restul aplicatiei o apeleaza de aici.
 */
function dispatcher_diurna_from_minutes(int $minutes): int
{
    if ($minutes < 720) {
        return 0;
    }

    return intdiv($minutes - 720, 1440) + 1;
}

/**
 * Diurnele unei curse, din "Data si ora inceput" (data_inceput + ora_inceput) si
 * "Data si ora sfarsit" (data_sfarsit + ora_sfarsit). Durata se calculeaza din
 * momentele complete, nu din coloana salvata, ca sa nu poata ramane in urma.
 * La o cursa reluata conteaza doar timpul fazelor ('diurna_minute_faze'), nu si
 * pauzele dintre ele.
 *
 * Status: 'ok', 'lipsa' (lipseste o data sau o ora) sau 'invalid' (sfarsitul
 * este inaintea inceputului). Doar la 'ok' exista minute si diurne.
 *
 * @return array{status: string, minute: ?int, diurne: ?int}
 */
function dispatcher_diurna_for_interval(array $row): array
{
    $moment = static function ($date, $time): ?DateTimeImmutable {
        $date = trim((string) ($date ?? ''));
        $time = substr(trim((string) ($time ?? '')), 0, 5);
        if ($date === '' || $time === '') {
            return null;
        }
        $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);

        return $value instanceof DateTimeImmutable ? $value : null;
    };

    $start = $moment($row['data_inceput'] ?? null, $row['ora_inceput'] ?? null);
    $end = $moment($row['data_sfarsit'] ?? null, $row['ora_sfarsit'] ?? null);
    if ($start === null || $end === null) {
        return ['status' => 'lipsa', 'minute' => null, 'diurne' => null];
    }

    $seconds = $end->getTimestamp() - $start->getTimestamp();
    if ($seconds < 0) {
        return ['status' => 'invalid', 'minute' => null, 'diurne' => null];
    }

    $minutes = intdiv($seconds, 60);
    // Cursa reluata: timpul pe drum e suma fazelor (pus pe rand de
    // dispatcher_attach_phase_minutes), fara pauzele dintre oprire si "Reia cursa".
    if (isset($row['diurna_minute_faze']) && is_int($row['diurna_minute_faze'])) {
        $minutes = $row['diurna_minute_faze'];
    }
    $days = dispatcher_diurna_from_minutes($minutes);
    // Curse inlantuite ale aceluiasi sofer (puse pe rand de
    // dispatcher_attach_same_day_diurna): diurnele castigate in plus prin
    // adunarea lor stau pe ultima cursa a lantului.
    $sameDay = is_array($row['diurna_zi'] ?? null) ? $row['diurna_zi'] : null;
    if ($sameDay !== null && !empty($sameDay['ultima'])) {
        $days += max(0, (int) ($sameDay['extra'] ?? 0));
    }
    $result = ['status' => 'ok', 'minute' => $minutes, 'diurne' => $days, 'calculat' => $days, 'ajustat' => false, 'ajustare_expirata' => false, 'zi' => $sameDay];

    // Modificare aprobata de admin (cerere "Modificare diurna", pusa pe rand de
    // dispatcher_attach_diurna_adjustments). Se aplica doar cat timp regula da
    // acelasi numar ca la solicitare: daca intervalul cursei s-a schimbat intre
    // timp, aprobarea nu mai corespunde si revine valoarea calculata.
    $adjustment = is_array($row['diurna_ajustare'] ?? null) ? $row['diurna_ajustare'] : null;
    if ($adjustment !== null) {
        if ((int) ($adjustment['calculat'] ?? -1) === $days) {
            $result['diurne'] = max(0, (int) ($adjustment['solicitat'] ?? $days));
            $result['ajustat'] = $result['diurne'] !== $days;
        } else {
            $result['ajustare_expirata'] = true;
        }
    }

    return $result;
}

/**
 * Pune pe fiecare cursa ('id') modificarea de diurna aprobata ('diurna_ajustare')
 * si cererea aflata in asteptare ('diurna_cerere'), ca dispatcher_diurna_for_interval
 * sa intoarca valoarea aprobata oriunde se afiseaza diurnele cursei.
 */
function dispatcher_attach_diurna_adjustments(PDO $db, array &$rows, string $idKey = 'id'): void
{
    if ($rows === []) {
        return;
    }

    dispatcher_attach_phase_minutes($db, $rows, $idKey);
    dispatcher_attach_same_day_diurna($db, $rows, $idKey);

    if (!class_exists('InactiveResourceApprovalModel')) {
        return;
    }

    try {
        $adjustments = (new InactiveResourceApprovalModel($db))->getDiurnaAdjustmentsForTrips(
            array_map(static fn (array $row): int => (int) ($row[$idKey] ?? 0), $rows)
        );
    } catch (Throwable $exception) {
        error_log('[diurna_adjustments] ' . $exception->getMessage());
        return;
    }

    foreach ($rows as &$row) {
        $entry = $adjustments[(int) ($row[$idKey] ?? 0)] ?? null;
        $row['diurna_ajustare'] = $entry['approved'] ?? null;
        $row['diurna_cerere'] = $entry['pending'] ?? null;
        // Cursa cu mai multi soferi: modificarile pe partea fiecaruia (driver_id => approved/pending),
        // aplicate de dispatcher_diurna_split.
        $row['diurna_soferi'] = $entry['drivers'] ?? [];
    }
    unset($row);
}

/**
 * Timpul pe drum al unei curse reluate = suma duratelor fazelor. Null cand cursa
 * nu are mai multe faze sau ultima faza nu e inchisa (atunci ramane intervalul).
 *
 * @param array<int,array<string,mixed>> $segments
 */
function dispatcher_phase_minutes(array $segments): ?int
{
    if (count($segments) < 2) {
        return null;
    }
    $last = $segments[count($segments) - 1];
    if (trim((string) ($last['data_sfarsit'] ?? '')) === '') {
        return null;
    }

    return array_sum(array_map('dispatcher_segment_minutes', $segments));
}

/**
 * Pune pe cursele reluate 'diurna_minute_faze' (suma fazelor), ca diurna sa se
 * calculeze din timpul pe drum, nu din inceputul primei faze -> sfarsitul ultimei.
 */
function dispatcher_attach_phase_minutes(PDO $db, array &$rows, string $idKey = 'id'): void
{
    $ids = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row[$idKey] ?? 0), $rows)));
    if ($ids === []) {
        return;
    }

    try {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT cursa_id, data_inceput, ora_inceput, data_sfarsit, ora_sfarsit
            FROM curse_segmente
            WHERE cursa_id IN ({$marks}) AND deleted_at IS NULL
            ORDER BY cursa_id ASC, ordine ASC, id ASC
        ");
        $stmt->execute($ids);
        $byRace = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $segment) {
            $byRace[(int) $segment['cursa_id']][] = $segment;
        }
    } catch (Throwable $exception) {
        error_log('[diurna_phase_minutes] ' . $exception->getMessage());
        return;
    }

    foreach ($rows as &$row) {
        $row['diurna_minute_faze'] = dispatcher_phase_minutes($byRace[(int) ($row[$idKey] ?? 0)] ?? []);
    }
    unset($row);
}

/**
 * Cursele inlantuite ale aceluiasi sofer: o cursa care incepe in ziua in care
 * s-a terminat cea dinainte (de ex. o cursa scurta dimineata si una care pleaca
 * dupa-amiaza, chiar daca aceasta continua in zilele urmatoare) formeaza un
 * lant. Regula ramane cea din dispatcher_diurna_from_minutes, aplicata pe
 * timpul total al lantului (suprapunerile se numara o singura data, pauzele
 * dintre curse nu se numara).
 *
 * Adunarea doar adauga, nu ia niciodata: daca lantul castiga mai multe diurne
 * decat suma curselor luate separat, diferenta se pune pe ultima cursa a
 * lantului (cea care se termina cel mai tarziu); altfel nu se schimba nimic.
 * Cursele cu mai multe faze (reluate) au soferii pe segmente, deci nu intra.
 *
 * Pune pe cursele lanturilor care castiga ceva 'diurna_zi' =
 * [extra, diurne_lant, minute, curse, ultima]; dispatcher_diurna_for_interval
 * il foloseste. Cursele soferului sunt citite din baza de date (fereastra de
 * +/- 31 de zile), nu doar din $rows, ca rezultatul sa nu depinda de filtre.
 */
function dispatcher_attach_same_day_diurna(PDO $db, array &$rows, string $idKey = 'id'): void
{
    $driverIds = [];
    $minDate = null;
    $maxDate = null;
    foreach ($rows as $row) {
        $driverId = (int) ($row['driver_id'] ?? 0);
        $start = trim((string) ($row['data_inceput'] ?? ''));
        $end = trim((string) ($row['data_sfarsit'] ?? ''));
        if ($driverId <= 0 || $start === '' || $end === '') {
            continue;
        }
        $driverIds[$driverId] = true;
        $minDate = $minDate === null || $start < $minDate ? $start : $minDate;
        $maxDate = $maxDate === null || $end > $maxDate ? $end : $maxDate;
    }
    if ($driverIds === [] || $minDate === null) {
        return;
    }

    try {
        $from = (new DateTimeImmutable($minDate))->modify('-31 days')->format('Y-m-d');
        $to = (new DateTimeImmutable($maxDate))->modify('+31 days')->format('Y-m-d');
        $driverIds = array_keys($driverIds);
        $driverMarks = implode(',', array_fill(0, count($driverIds), '?'));
        $sql = "SELECT c.id, c.driver_id, c.data_inceput, c.ora_inceput, c.data_sfarsit, c.ora_sfarsit,
                    (SELECT COUNT(*) FROM curse_segmente seg WHERE seg.cursa_id = c.id AND seg.deleted_at IS NULL) AS segmente
                FROM curse_dispecer c
                WHERE c.deleted_at IS NULL
                  AND c.driver_id IN ({$driverMarks})
                  AND c.data_sfarsit >= ?
                  AND c.data_inceput <= ?";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($driverIds, [$from, $to]));
        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        error_log('[diurna_same_day] ' . $exception->getMessage());
        return;
    }

    $byDriver = [];
    foreach ($trips as $trip) {
        if ((int) $trip['segmente'] > 1) {
            continue;
        }
        $interval = dispatcher_diurna_for_interval($trip);
        if ($interval['status'] !== 'ok') {
            continue;
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $trip['data_inceput'] . ' ' . substr((string) $trip['ora_inceput'], 0, 5));
        if (!$start instanceof DateTimeImmutable) {
            continue;
        }
        $startTs = $start->getTimestamp();
        $byDriver[(int) $trip['driver_id']][] = [
            'id' => (int) $trip['id'],
            'start_date' => (string) $trip['data_inceput'],
            'end_date' => (string) $trip['data_sfarsit'],
            'start' => $startTs,
            'end' => $startTs + (int) $interval['minute'] * 60,
            'diurne' => (int) $interval['diurne'],
        ];
    }

    $byTrip = [];
    foreach ($byDriver as $driverTrips) {
        usort($driverTrips, static fn (array $a, array $b): int => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);

        // Lanturi: o cursa se leaga daca incepe cel tarziu in ziua in care se
        // termina lantul de pana atunci.
        $chains = [];
        $chainEndDate = null;
        foreach ($driverTrips as $trip) {
            if ($chainEndDate !== null && $trip['start_date'] <= $chainEndDate) {
                $chains[count($chains) - 1][] = $trip;
                $chainEndDate = max($chainEndDate, $trip['end_date']);
            } else {
                $chains[] = [$trip];
                $chainEndDate = $trip['end_date'];
            }
        }

        foreach ($chains as $chain) {
            if (count($chain) < 2) {
                continue;
            }

            // Pe langa lantul intreg se verifica si fiecare zi a lui (cursele
            // care incep in aceeasi zi): o cursa lunga care acopera ziua poate
            // ascunde doua curse scurte care, adunate, trec de prag. Se pastreaza
            // varianta care da mai mult; diferentele nu se cumuleaza.
            $groups = [dispatcher_diurna_chain_extra($chain)];
            $byStartDate = [];
            foreach ($chain as $trip) {
                $byStartDate[$trip['start_date']][] = $trip;
            }
            $dayGroups = [];
            $dayExtra = 0;
            foreach ($byStartDate as $dayTrips) {
                if (count($dayTrips) < 2) {
                    continue;
                }
                $dayGroup = dispatcher_diurna_chain_extra($dayTrips);
                if ($dayGroup['extra'] > 0) {
                    $dayGroups[] = $dayGroup;
                    $dayExtra += $dayGroup['extra'];
                }
            }
            if ($dayExtra > $groups[0]['extra']) {
                $groups = $dayGroups;
            }

            foreach ($groups as $group) {
                if ($group['extra'] <= 0) {
                    continue;
                }
                foreach ($group['trips'] as $trip) {
                    $isLast = $trip['id'] === $group['last_id'];
                    $byTrip[$trip['id']] = [
                        'extra' => $isLast ? $group['extra'] : 0,
                        'diurne_lant' => $group['together'],
                        'minute' => $group['minutes'],
                        'curse' => count($group['trips']),
                        'ultima' => $isLast,
                    ];
                }
            }
        }
    }

    foreach ($rows as &$row) {
        $row['diurna_zi'] = $byTrip[(int) ($row[$idKey] ?? 0)] ?? null;
    }
    unset($row);
}

/**
 * Diurnele castigate in plus de un grup de curse (sortate dupa inceput) fata de
 * suma curselor luate separat. Timpul grupului numara suprapunerile o data si
 * nu numara pauzele dintre curse. Ultima cursa = cea care se termina cel mai tarziu.
 *
 * @return array{trips: array, minutes: int, together: int, extra: int, last_id: int}
 */
function dispatcher_diurna_chain_extra(array $trips): array
{
    $seconds = 0;
    $coveredUntil = null;
    $separately = 0;
    $last = $trips[0];
    foreach ($trips as $trip) {
        $separately += $trip['diurne'];
        $from = $coveredUntil === null ? $trip['start'] : max($trip['start'], $coveredUntil);
        if ($trip['end'] > $from) {
            $seconds += $trip['end'] - $from;
        }
        $coveredUntil = $coveredUntil === null ? $trip['end'] : max($coveredUntil, $trip['end']);
        if ([$trip['end'], $trip['id']] > [$last['end'], $last['id']]) {
            $last = $trip;
        }
    }

    $minutes = intdiv($seconds, 60);
    $together = dispatcher_diurna_from_minutes($minutes);

    return [
        'trips' => $trips,
        'minutes' => $minutes,
        'together' => $together,
        'extra' => max(0, $together - $separately),
        'last_id' => (int) $last['id'],
    ];
}

/**
 * Diurnele cursei, impartite pe soferii care au condus-o.
 *
 * Diurna se cuvine celui care era plecat, deci zilele se impart dupa timpul
 * petrecut de fiecare segment pe drum. Numarul total ramane al cursei: resturile
 * se distribuie descrescator (metoda resturilor celor mai mari), ca suma sa dea
 * exact totalul, nu unul rotunjit in plus sau in minus.
 *
 * $driverAdjustments = 'diurna_soferi' de pe cursa (dispatcher_attach_diurna_adjustments):
 * modificarea aprobata pentru un sofer inlocuieste partea lui, cat timp regula ii da
 * aceeasi parte ca la solicitare (la fel ca modificarea pe toata cursa).
 *
 * @return array<int, array{driver_id:int, sofer:string, minute:int, zile:int, calculat:int, ajustat:bool, cerere:?array}>
 */
function dispatcher_diurna_split(int $totalDays, array $segments, array $driverAdjustments = []): array
{
    $rows = dispatcher_diurna_split_by_time($totalDays, $segments);
    foreach ($rows as &$row) {
        $row['calculat'] = $row['zile'];
        $row['ajustat'] = false;
        $row['cerere'] = null;
        $adjustment = $row['driver_id'] > 0 ? ($driverAdjustments[$row['driver_id']] ?? null) : null;
        if (!is_array($adjustment)) {
            continue;
        }
        $row['cerere'] = is_array($adjustment['pending'] ?? null) ? $adjustment['pending'] : null;
        $approved = is_array($adjustment['approved'] ?? null) ? $adjustment['approved'] : null;
        if ($approved !== null && (int) ($approved['calculat'] ?? -1) === $row['calculat']) {
            $row['zile'] = max(0, (int) ($approved['solicitat'] ?? $row['zile']));
            $row['ajustat'] = $row['zile'] !== $row['calculat'];
        }
    }
    unset($row);

    return $rows;
}

/**
 * Diurnele pe soferi, fara modificari aprobate.
 *
 * Un singur sofer: are diurnele cursei ($totalDays), ca pana acum. Mai multi soferi
 * (2026-10-08, cerinta utilizator): fiecare primeste regula diurnei
 * (dispatcher_diurna_from_minutes) aplicata pe timpul LUI - suma fazelor lui. Ex.
 * #807: Beznea 16h -> 1, Serban 42h -> 2, deci cursa are 3 (suma soferilor), nu 2
 * impartite proportional ca inainte.
 */
function dispatcher_diurna_split_by_time(int $totalDays, array $segments): array
{
    $rows = dispatcher_diurna_split_proportional($totalDays, $segments);
    // Fara durate pe faze (faze neinchise) ramane comportamentul de pana acum.
    if (count($rows) < 2 || array_sum(array_column($rows, 'minute')) <= 0) {
        return $rows;
    }

    foreach ($rows as &$row) {
        $row['zile'] = dispatcher_diurna_from_minutes((int) $row['minute']);
    }
    unset($row);

    return $rows;
}

/** Impartirea totalului cursei pe soferi, proportional cu timpul (folosita la un singur sofer). */
function dispatcher_diurna_split_proportional(int $totalDays, array $segments): array
{
    if ($segments === []) {
        return [];
    }

    $rows = [];
    $minutesTotal = 0;
    foreach ($segments as $index => $segment) {
        $minutes = dispatcher_segment_minutes($segment);
        $minutesTotal += $minutes;
        $driverId = (int) ($segment['driver_id'] ?? 0);
        $driverName = trim((string) ($segment['sofer_nume'] ?? ''));
        $key = $driverId > 0 ? $driverId : -($index + 1);
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'driver_id' => $driverId,
                'sofer' => $driverName !== '' ? $driverName : 'Sofer -',
                'minute' => 0,
                'zile' => 0,
            ];
        }
        $rows[$key]['minute'] += $minutes;
    }

    if ($totalDays <= 0) {
        return array_values($rows);
    }

    if ($minutesTotal <= 0) {
        // Fara durate nu avem dupa ce imparti: diurnele raman ale primului segment.
        $first = array_key_first($rows);
        $rows[$first]['zile'] = $totalDays;

        return array_values($rows);
    }

    $remainders = [];
    $allocated = 0;
    foreach ($rows as $key => $row) {
        $exact = $totalDays * $row['minute'] / $minutesTotal;
        $whole = (int) floor($exact);
        $rows[$key]['zile'] = $whole;
        $allocated += $whole;
        $remainders[$key] = $exact - $whole;
    }

    arsort($remainders);
    foreach (array_keys($remainders) as $key) {
        if ($allocated >= $totalDays) {
            break;
        }
        $rows[$key]['zile']++;
        $allocated++;
    }

    return array_values($rows);
}

/**
 * Initialele unui nume pentru afisare compacta: "Beznea Cristian-Gheorghe" -> "B-C-G",
 * "Șerban Marian" -> "Ș-M". Diacriticele raman; spatiile multiple si cratimele separa
 * componentele. Doar pentru afisare - identificarea se face mereu dupa id.
 */
function dispatcher_name_initials(string $name): string
{
    $parts = preg_split('/[\s\-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = array_map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)), $parts);

    return $initials !== [] ? implode('-', $initials) : '-';
}

/**
 * Repartizarea incarcaturii cursei pe soferi, proportional cu km-ii efectuati de fiecare:
 *   tone_sofer = (km_sofer / km_total) × tone_incarcate.
 * Doar informativ (citire): nu modifica cantitatile, facturarea sau costurile.
 *
 * - Km-ii sunt `km` de pe fiecare faza (Km efectuati), adunati pe id-ul soferului;
 *   fiecare faza (id) se numara o singura data.
 * - Tonele sunt cantitatea incarcata a CURSEI (sursa autoritara), nu suma fazelor.
 * - Rotunjirea se face pe sutimi, cu metoda resturilor celor mai mari (ordine stabila),
 *   ca suma randurilor sa dea exact totalul afisat.
 * - O faza fara sofer sau fara km valizi NU se redistribuie: status 'incomplete'.
 * - Fara km total sau fara cantitate: status 'unavailable' (fara impartire la zero).
 *
 * @return array{status:string, message:string, rows:array<int,array{driver_id:int,sofer:string,km:int,tone:float}>, km_total:int, tone_total:float}
 */
function dispatcher_load_allocation_by_km(array $segments, ?float $loadedTonnes): array
{
    $result = ['status' => 'unavailable', 'message' => 'Repartizare indisponibilă', 'rows' => [], 'km_total' => 0, 'tone_total' => 0.0];

    $byDriver = [];
    $seen = [];
    $missing = [];
    foreach (array_values($segments) as $index => $segment) {
        $segmentId = (int) ($segment['id'] ?? 0);
        if ($segmentId > 0) {
            if (isset($seen[$segmentId])) {
                continue;
            }
            $seen[$segmentId] = true;
        }
        $driverId = (int) ($segment['driver_id'] ?? 0);
        $km = is_numeric($segment['km'] ?? null) ? (float) $segment['km'] : 0.0;
        if ($driverId <= 0 || $km <= 0) {
            $missing[] = 'F' . ($index + 1) . ($driverId <= 0 ? ' fără șofer' : ' fără km');
            continue;
        }
        $byDriver[$driverId] ??= ['driver_id' => $driverId, 'sofer' => trim((string) ($segment['sofer_nume'] ?? '')) ?: 'Sofer -', 'km' => 0.0];
        $byDriver[$driverId]['km'] += $km;
    }

    if ($missing !== []) {
        $result['status'] = 'incomplete';
        $result['message'] = 'Repartizare incompletă: ' . implode(', ', $missing);

        return $result;
    }

    $kmTotal = array_sum(array_column($byDriver, 'km'));
    if ($kmTotal <= 0 || $loadedTonnes === null || $loadedTonnes <= 0) {
        return $result;
    }

    // Sutimi de tona: partea intreaga pentru fiecare, apoi sutimile ramase merg la
    // cele mai mari resturi (la egalitate, in ordinea fazelor).
    $totalUnits = (int) round($loadedTonnes * 100);
    $allocated = 0;
    $remainders = [];
    $rows = [];
    foreach (array_values($byDriver) as $position => $driver) {
        $exactUnits = $totalUnits * $driver['km'] / $kmTotal;
        $units = (int) floor($exactUnits + 1e-9);
        $allocated += $units;
        $remainders[$position] = $exactUnits - $units;
        $rows[$position] = $driver + ['units' => $units];
    }
    $order = array_keys($remainders);
    usort($order, static fn (int $a, int $b): int => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);
    foreach ($order as $position) {
        if ($allocated >= $totalUnits) {
            break;
        }
        $rows[$position]['units']++;
        $allocated++;
    }

    $result['status'] = 'ok';
    $result['message'] = '';
    $result['km_total'] = (int) round($kmTotal);
    $result['tone_total'] = $totalUnits / 100;
    $result['rows'] = array_map(static fn (array $row): array => [
        'driver_id' => $row['driver_id'],
        'sofer' => $row['sofer'],
        'km' => (int) round($row['km']),
        'tone' => $row['units'] / 100,
    ], $rows);

    return $result;
}

/** Rezumatul diurnelor pe soferi, pentru tooltip: "Ion: 2 | Vasile: 1". */
function dispatcher_diurna_summary(int $totalDays, array $segments, array $driverAdjustments = []): string
{
    $parts = [];
    foreach (dispatcher_diurna_split($totalDays, $segments, $driverAdjustments) as $row) {
        $parts[] = $row['sofer'] . ': ' . $row['zile'];
    }

    return implode(' | ', $parts);
}

/** Durata unei faze, formatata scurt ("8h", "18h 30m", "-" cand nu e inchisa). */
function dispatcher_segment_duration_label(array $segment): string
{
    $minutes = dispatcher_segment_minutes($segment);
    if ($minutes <= 0) {
        return 'durată -';
    }

    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;

    return $rest > 0 ? $hours . 'h ' . $rest . 'm' : $hours . 'h';
}

/**
 * Traseul unei faze, pe scurt: de unde a plecat si unde a ajuns. Locurile scrise
 * de mana bat id-urile configurate, pentru ca ele sunt cele completate pe faza.
 */
function dispatcher_segment_route_label(array $segment, array $loadLocations = [], array $zones = []): string
{
    $nameById = static function (array $options, int $id): string {
        foreach ($options as $option) {
            if ((int) ($option['id'] ?? 0) === $id) {
                return trim((string) ($option['nume'] ?? ''));
            }
        }

        return '';
    };

    $from = trim((string) ($segment['loc_plecare'] ?? ''));
    if ($from === '') {
        $from = $nameById($loadLocations, (int) ($segment['loc_incarcare_id'] ?? 0));
    }

    $to = trim((string) ($segment['loc_livrare'] ?? ''));
    if ($to === '') {
        $to = $nameById($zones, (int) ($segment['zona_distributie_id'] ?? 0));
    }

    if ($from === '' && $to === '') {
        return '';
    }

    return trim(($from !== '' ? $from : '?') . ' → ' . ($to !== '' ? $to : '?'));
}
