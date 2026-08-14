<?php
declare(strict_types=1);

/**
 * Test de conexiune SAS Fleet API (doar citire, fara modificari in baza de date).
 *
 * Utilizare:
 *   php scripts/test_sas_connection.php            - test complet (host, login, masini, pozitii)
 *   php scripts/test_sas_connection.php --limit=3  - afiseaza doar primele 3 masini/pozitii
 *   php scripts/test_sas_connection.php --check-db - compara numerele de inmatriculare SAS cu tabela vehicule
 *   php scripts/test_sas_connection.php --raw      - afiseaza si raspunsul SAS brut (nenormalizat)
 *
 * Scriptul nu afiseaza niciodata credentiale sau tokenul de autentificare.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/services/SasFleetClient.php';

function sas_cli_arg(string $name, array $argv): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string) $arg, $prefix)) {
            return substr((string) $arg, strlen($prefix));
        }
    }

    return null;
}

function sas_cli_flag(string $name, array $argv): bool
{
    foreach ($argv as $arg) {
        if ((string) $arg === '--' . $name) {
            return true;
        }
    }

    return false;
}

function sas_print(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

function sas_print_json(mixed $data): void
{
    sas_print((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

$limit = max(1, (int) (sas_cli_arg('limit', $argv) ?? 10));
$checkDb = sas_cli_flag('check-db', $argv);
$showRaw = sas_cli_flag('raw', $argv);

sas_print('=== Test conexiune SAS Fleet API ===');
sas_print('Data rulare: ' . date('Y-m-d H:i:s'));
sas_print();

$client = new SasFleetClient();

// 1. Credentiale
if (!$client->credentialsAvailable()) {
    sas_print('[EROARE] Credentialele SAS lipsesc.');
    sas_print('Completeaza in .env: SAS_API_USERNAME si SAS_API_PASSWORD (vezi .env.example).');
    exit(1);
}
sas_print('[1/5] Credentiale SAS gasite in mediu (nu se afiseaza).');

// 2. Descoperire host
try {
    $host = $client->resolveHost();
    sas_print('[2/5] Host SAS rezolvat: ' . $host);
} catch (Throwable $e) {
    sas_print('[EROARE] Rezolvarea host-ului SAS a esuat: ' . $e->getMessage());
    exit(1);
}

// 3. Autentificare
try {
    $client->login();
    sas_print('[3/5] Autentificare reusita. Token primit in header (nu se afiseaza).');
} catch (Throwable $e) {
    sas_print('[EROARE] Autentificarea SAS a esuat: ' . $e->getMessage());
    exit(1);
}

// 4. Structura flotei / lista masinilor
try {
    $info = $client->getCompanyInfo();
} catch (Throwable $e) {
    sas_print('[EROARE] /api/info a esuat: ' . $e->getMessage());
    exit(1);
}

$cars = is_array($info['cars'] ?? null) ? array_values(array_filter($info['cars'], 'is_array')) : [];
sas_print('[4/5] /api/info OK.');
sas_print('  Utilizator: ' . trim((string) ($info['firstName'] ?? '') . ' ' . (string) ($info['lastName'] ?? '')));
sas_print('  Companii: ' . count($info['companies'] ?? []) . ' | Sucursale: ' . count($info['branches'] ?? [])
    . ' | Puncte de lucru: ' . count($info['workPoints'] ?? []) . ' | Masini: ' . count($cars));
sas_print();
sas_print('  Masini SAS (primele ' . min($limit, count($cars)) . ' din ' . count($cars) . '):');
foreach (array_slice($cars, 0, $limit) as $car) {
    sas_print(sprintf(
        '   - carId=%s | %s | driver=%s | disabled=%s',
        (string) ($car['carId'] ?? '?'),
        (string) ($car['licensePlate'] ?? '?'),
        (string) ($car['driver'] ?? '-'),
        !empty($car['disabled']) ? 'da' : 'nu'
    ));
}
sas_print();

if ($cars === []) {
    sas_print('[AVERTISMENT] Niciun vehicul vizibil pentru acest utilizator SAS. Testul de pozitii este sarit.');
    exit(0);
}

// 5. Ultimele pozitii GPS
$carIds = array_slice(array_values(array_filter(array_map(
    static fn (array $car) => (int) ($car['carId'] ?? 0),
    $cars
), static fn (int $id) => $id > 0)), 0, $limit);

try {
    $rawPositions = $client->getCurrentPositions($carIds);
} catch (Throwable $e) {
    sas_print('[EROARE] currentpositions a esuat: ' . $e->getMessage());
    exit(1);
}

sas_print('[5/5] currentpositions OK. Pozitii primite: ' . count($rawPositions) . ' pentru ' . count($carIds) . ' masini cerute.');
sas_print();

if ($showRaw) {
    sas_print('--- Raspuns SAS brut (nenormalizat) ---');
    sas_print_json($rawPositions);
    sas_print();
}

sas_print('--- Pozitii normalizate (format intern) ---');
$carsById = [];
foreach ($cars as $car) {
    $carsById[(int) ($car['carId'] ?? 0)] = $car;
}
$normalized = [];
foreach ($rawPositions as $position) {
    $carId = (int) ($position['CarID'] ?? $position['carId'] ?? 0);
    $normalized[] = $client->normalizePosition($position, $carsById[$carId] ?? []);
}
sas_print_json($normalized);
sas_print();

// Optional: verificare mapare cu tabela vehicule (doar citire)
if ($checkDb) {
    sas_print('--- Verificare mapare cu tabela vehicule (read-only) ---');
    try {
        require_once $projectRoot . '/htdocs/config/database.php';
        $pdo = get_pdo();
        $stmt = $pdo->query('SELECT id, nr_inmatriculare FROM vehicule');
        $localVehicles = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $normalizePlate = static fn (string $plate) => strtoupper(preg_replace('/[^A-Z0-9]/i', '', $plate) ?? $plate);
        $localByPlate = [];
        foreach ($localVehicles as $vehicle) {
            $localByPlate[$normalizePlate((string) $vehicle['nr_inmatriculare'])] = $vehicle;
        }

        $matched = 0;
        $unmatched = [];
        foreach ($cars as $car) {
            $plate = $normalizePlate((string) ($car['licensePlate'] ?? ''));
            if ($plate !== '' && isset($localByPlate[$plate])) {
                $matched++;
            } else {
                $unmatched[] = (string) ($car['licensePlate'] ?? '?') . ' (carId=' . (string) ($car['carId'] ?? '?') . ')';
            }
        }

        sas_print('Vehicule locale in DB: ' . count($localVehicles));
        sas_print('Masini SAS cu numar de inmatriculare gasit in DB: ' . $matched . ' / ' . count($cars));
        if ($unmatched !== []) {
            sas_print('Masini SAS fara corespondent local (dupa numar normalizat):');
            foreach ($unmatched as $entry) {
                sas_print('   - ' . $entry);
            }
        }
    } catch (Throwable $e) {
        sas_print('[AVERTISMENT] Verificarea DB a esuat: ' . $e->getMessage());
    }
    sas_print();
}

sas_print('=== Test incheiat cu succes ===');
exit(0);
