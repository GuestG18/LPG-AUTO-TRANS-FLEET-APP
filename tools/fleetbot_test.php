<?php
declare(strict_types=1);

/**
 * FleetBot CLI smoke test.
 *
 * Examples:
 *   php tools/fleetbot_test.php fuel "375 NET" 2026-09-11
 *   php tools/fleetbot_test.php document "405 NET" "Extinctor"
 *
 * This script is CLI-only and does not expose a public HTTP endpoint.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$htdocs = $root . '/htdocs';

require_once $htdocs . '/config/config.php';
require_once $htdocs . '/config/database.php';
require_once $htdocs . '/models/BaseModel.php';
require_once $htdocs . '/models/FuelModel.php';
require_once $htdocs . '/models/DocumentModel.php';
require_once $htdocs . '/services/FleetBotActionService.php';

$command = strtolower(trim((string) ($argv[1] ?? '')));
$vehicle = trim((string) ($argv[2] ?? ''));
$value = trim((string) ($argv[3] ?? ''));

if ($command === '' || $vehicle === '') {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tools/fleetbot_test.php fuel \"375 NET\" 2026-09-11\n");
    fwrite(STDERR, "  php tools/fleetbot_test.php document \"405 NET\" \"Extinctor\"\n");
    exit(2);
}

try {
    $service = new FleetBotActionService(get_pdo());

    $result = match ($command) {
        'fuel' => $service->execute('fuel_consumption', [
            'vehicle' => $vehicle,
            'date' => $value !== '' ? $value : date('Y-m-d'),
        ]),
        'document', 'doc' => $service->execute('vehicle_document_status', [
            'vehicle' => $vehicle,
            'document_type' => $value,
        ]),
        default => [
            'ok' => false,
            'error' => 'unknown_command',
            'message' => 'Comanda trebuie să fie fuel sau document.',
        ],
    };

    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Nu s-a putut serializa rezultatul.');
    }

    fwrite(STDOUT, $json . PHP_EOL);
    exit(($result['ok'] ?? false) ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FleetBot test] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
