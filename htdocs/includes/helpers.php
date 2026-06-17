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

    $normalized = repair_utf8_mojibake($value);

    return strtr($normalized, $replacements);
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

function flash_set(string $type, string $message): void
{
    $_SESSION['_flash_messages'][$type][] = $message;
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
        return '<span class="badge text-bg-secondary">Fara expirare</span>';
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
        return '<span class="badge text-bg-light border">Fara fisier</span>';
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
        return '<div class="alert alert-light border mb-0">Nu exista fisier incarcat pentru acest document.</div>';
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

    return '<div class="alert alert-secondary mb-0">Previzualizarea directa este disponibila pentru PDF si imagini. Pentru acest fisier foloseste butonul de deschidere.</div>';
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
        return '<div class="vehicle-thumb vehicle-thumb-placeholder">Fara poza</div>';
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
        return '<div class="vehicle-thumb vehicle-thumb-placeholder">Fara poza</div>';
    }

    $safeUrl = e($imageUrl);
    $safeAlt = e($originalFile !== null && trim($originalFile) !== '' ? $originalFile : 'Poza sofer');

    return '<a class="vehicle-thumb" href="' . $safeUrl . '" target="_blank" rel="noopener">'
        . '<img src="' . $safeUrl . '" alt="' . $safeAlt . '" loading="lazy">'
        . '</a>';
}

function vehicle_image_preview_html(?string $originalFile, ?string $storedFile): string
{
    $imageUrl = vehicle_image_url($storedFile);

    if ($imageUrl === null) {
        return '<div class="vehicle-photo-card vehicle-photo-empty">Nu exista poza incarcata pentru acest vehicul.</div>';
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
        return '<div class="vehicle-photo-card vehicle-photo-empty">Nu exista poza incarcata pentru acest sofer.</div>';
    }

    $safeUrl = e($imageUrl);
    $safeAlt = e($originalFile !== null && trim($originalFile) !== '' ? $originalFile : 'Poza sofer');
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

