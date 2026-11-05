<?php
declare(strict_types=1);

/**
 * Date de TEST pentru exploratorul de componente din Stoc Anvelope (doar baza locala).
 *
 *   php scripts/seed_explorator_componente_demo.php --apply    adauga datele de test
 *   php scripts/seed_explorator_componente_demo.php --remove   le sterge si reface stocul
 *
 * Ce adauga, pe 5 vehicule (B 665 NET + B 405 NET, B 605 NET + B 705 NET, B 235 NET, B 325 NET,
 * B 112 NET): stoc pe piesele folosite, configurari in Reparatii Auto (interval / avertizare),
 * montari pe pozitie (din stoc si montaj direct, o inlocuire, o demontare cu retur in stoc),
 * inspectii (OK / Atentie / Necesita inlocuire) si atributele axelor (o axa ridicabila).
 * Montarile, demontarile si inspectiile trec prin VehicleComponentModel, ca in aplicatie.
 *
 * Totul e marcat TEST-EXPLORATOR; stocul initial al pieselor atinse e salvat in
 * storage/seed_explorator_componente_backup.json si refacut la --remove.
 * Scriptul refuza sa ruleze daca APP_ENV nu e „local” sau baza nu e pe 127.0.0.1 / localhost.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/includes/helpers.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/TireModel.php';
require_once $root . '/htdocs/models/MaintenanceModel.php';
require_once $root . '/htdocs/models/VehicleComponentModel.php';
require_once $root . '/htdocs/services/AutoComponentStateService.php';
require_once $root . '/htdocs/services/AutoComponentCatalogService.php';

const MARKER = 'TEST-EXPLORATOR';
$backupFile = $root . '/storage/seed_explorator_componente_backup.json';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['--apply', '--remove'], true)) {
    fwrite(STDERR, "Folosire: php scripts/seed_explorator_componente_demo.php --apply | --remove\n");
    exit(1);
}
$dbHost = (string) (getenv('DB_HOST') ?: '');
if (APP_ENV !== 'local' || !in_array($dbHost, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Refuzat: scriptul ruleaza doar local (APP_ENV=local, DB_HOST=127.0.0.1). Acum: APP_ENV=" . APP_ENV . ", DB_HOST={$dbHost}\n");
    exit(1);
}

$db = get_pdo();

if ($mode === '--remove') {
    $removed = [
        'montari' => $db->exec("DELETE FROM mentenanta_piese_utilizari WHERE observatii LIKE '" . MARKER . "%'"),
        'configurari' => $db->exec("DELETE FROM mentenanta_auto_configurari WHERE notes = '" . MARKER . "'"),
        'inspectii' => $db->exec("DELETE FROM vehicule_componente_inspectii WHERE observatii LIKE '" . MARKER . "%'"),
        'atribute axe' => $db->exec("DELETE FROM vehicule_axe_atribute WHERE observatii = '" . MARKER . "'"),
    ];
    if (is_file($backupFile)) {
        $backup = json_decode((string) file_get_contents($backupFile), true) ?: [];
        $restore = $db->prepare('UPDATE mentenanta_piese SET stoc_curent = :stoc, stoc_minim = :minim, furnizor = :furnizor, producator = :producator, locatie_depozit = :locatie, pret_achizitie = :pret WHERE id = :id');
        foreach ($backup as $row) {
            $restore->execute([':stoc' => $row['stoc_curent'], ':minim' => $row['stoc_minim'], ':furnizor' => $row['furnizor'], ':producator' => $row['producator'], ':locatie' => $row['locatie_depozit'], ':pret' => $row['pret_achizitie'], ':id' => $row['id']]);
        }
        $removed['piese stoc refacute'] = count($backup);
        unlink($backupFile);
    }
    foreach ($removed as $label => $count) {
        echo str_pad($label, 22) . (int) $count . "\n";
    }
    exit(0);
}

if (is_file($backupFile)) {
    fwrite(STDERR, "Datele de test exista deja. Ruleaza intai --remove.\n");
    exit(1);
}

$maintenance = new MaintenanceModel($db);
$components = new VehicleComponentModel($db);
$catalog = (new AutoComponentCatalogService($db))->components();
$partIndex = $maintenance->getAutoComponentPartIndex();

// Componenta din catalog dupa categoria Reparatii Auto + nume.
$component = static function (int $categoryId, string $name) use ($catalog): array {
    $wanted = AutoComponentStateService::lookupKey($name);
    foreach ($catalog as $item) {
        if ((int) $item['category_id'] === $categoryId && AutoComponentStateService::lookupKey((string) $item['name']) === $wanted) {
            return $item;
        }
    }
    throw new RuntimeException("Componenta {$name} (categoria {$categoryId}) nu exista in catalog.");
};
$partOf = static function (array $item) use ($partIndex): array {
    $part = AutoComponentStateService::findStockPart($partIndex, (string) $item['category'], (string) $item['name']);
    if ($part === null) {
        throw new RuntimeException("Piesa din stoc pentru {$item['name']} lipseste (deschide Reparatii Auto o data, pentru sincronizare).");
    }
    return $part;
};

$vehicles = [];
foreach ($db->query('SELECT id, nr_inmatriculare, tip_vehicul, km_bord FROM vehicule WHERE id IN (6, 11, 12, 24, 28, 56, 76)')->fetchAll() as $row) {
    $vehicles[(int) $row['id']] = $row;
}

// Piesele folosite: stoc de test (valorile initiale se salveaza pentru --remove).
$stockPlan = [
    [3, 'Placute Frana', 12, 4, 'Autoparts Test SRL', 'Knorr-Bremse', 'Raft A1', 420.00],
    [3, 'Disc Frana', 6, 2, 'Autoparts Test SRL', 'Knorr-Bremse', 'Raft A2', 760.00],
    [3, 'Etrieri', 2, 1, 'Frane Pro Test', 'WABCO', 'Raft A2', 1850.00],
    [3, 'Senzor ABS', 8, 2, 'Electro Truck Test', 'WABCO', 'Sertar E1', 210.00],
    [2, 'Rulmenti', 10, 4, 'Rulmenti Test SRL', 'SKF', 'Raft B1', 330.00],
    [2, 'Butuci', 2, 1, 'Rulmenti Test SRL', 'SKF', 'Raft B2', 1250.00],
    [1, 'Perne Aer', 6, 2, 'Suspensii Test SRL', 'Contitech', 'Raft C1', 690.00],
    [1, 'Amortizoare', 1, 2, 'Suspensii Test SRL', 'Sachs', 'Raft C2', 540.00],
    [6, 'Curea', 9, 3, 'Motor Parts Test', 'Gates', 'Raft D1', 180.00],
    [6, 'Turbina', 1, 1, 'Motor Parts Test', 'Garrett', 'Raft D3', 6400.00],
    [6, 'Alternator', 2, 1, 'Electro Truck Test', 'Bosch', 'Raft D2', 2300.00],
    [6, 'Compresor Aer', 1, 1, 'Motor Parts Test', 'Knorr-Bremse', 'Raft D3', 3900.00],
    [4, 'Pompa Apa', 3, 1, 'Motor Parts Test', 'Febi', 'Raft D1', 980.00],
    [4, 'Termostast', 5, 2, 'Motor Parts Test', 'Wahler', 'Sertar D4', 160.00],
    [5, 'Acumulatori', 4, 2, 'Electro Truck Test', 'Varta', 'Zona baterii', 1450.00],
    [5, 'Faruri', 2, 2, 'Electro Truck Test', 'Hella', 'Raft E2', 1100.00],
    [5, 'Stopuri', 6, 2, 'Electro Truck Test', 'Hella', 'Raft E2', 260.00],
    [9, 'Caseta De Directie', 1, 1, 'Directie Test SRL', 'ZF', 'Raft F1', 5200.00],
    [9, 'Bara Transversala', 3, 1, 'Directie Test SRL', 'Lemforder', 'Raft F1', 640.00],
    [8, 'Unitate Ad-Blue', 1, 1, 'Motor Parts Test', 'Bosch', 'Raft G1', 3100.00],
];

$backup = [];
$keyOf = [];
$db->beginTransaction();
try {
    $select = $db->prepare('SELECT id, stoc_curent, stoc_minim, furnizor, producator, locatie_depozit, pret_achizitie FROM mentenanta_piese WHERE id = :id');
    $update = $db->prepare('UPDATE mentenanta_piese SET stoc_curent = :stoc, stoc_minim = :minim, furnizor = :furnizor, producator = :producator, locatie_depozit = :locatie, pret_achizitie = :pret WHERE id = :id');
    foreach ($stockPlan as [$categoryId, $name, $stock, $minimum, $supplier, $producer, $location, $price]) {
        $item = $component($categoryId, $name);
        $part = $partOf($item);
        $select->execute([':id' => (int) $part['id']]);
        $backup[] = $select->fetch();
        $update->execute([':stoc' => $stock, ':minim' => $minimum, ':furnizor' => $supplier, ':producator' => $producer, ':locatie' => $location, ':pret' => $price, ':id' => (int) $part['id']]);
        $keyOf[$name] = $item;
    }
    $db->commit();
} catch (Throwable $exception) {
    $db->rollBack();
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT));

// Configurari in Reparatii Auto (dau uzura); cele existente nu se ating (INSERT IGNORE).
$configure = $db->prepare(
    'INSERT IGNORE INTO mentenanta_auto_configurari
        (vehicle_id, vehicle_type, primary_category, subcategory, category_id, component_key, stock_part_id, component_name, component_code,
         description, monitoring_type, monitoring_methods, interval_value, warning_value, critical_value, lifetime_value, unit, notes, created_at, updated_at)
     VALUES (:vehicle_id, :vehicle_type, "sasiu", "sasiu", :category_id, :component_key, :stock_part_id, :component_name, :component_code,
         "Configurare de test pentru exploratorul de componente.", :monitoring_type, :methods, :interval, :warning, :critical, :lifetime, :unit, :notes, :created_at, :updated_at)'
);
$configs = 0;
$config = static function (int $vehicleId, string $name, int $interval, string $unit = 'km', string $createdAt = '2025-11-01 08:00:00') use ($configure, $vehicles, $keyOf, $partOf, &$configs): void {
    $item = $keyOf[$name];
    $type = (string) $vehicles[$vehicleId]['tip_vehicul'];
    $format = static fn (float $value): string => number_format($value, 0, ',', '.');
    $configure->execute([
        ':vehicle_id' => $vehicleId,
        ':vehicle_type' => str_starts_with($type, 'semiremorca') ? 'semiremorca' : ($type === 'cap_tractor' ? 'cap_tractor' : 'camion'),
        ':category_id' => (int) $item['category_id'],
        ':component_key' => (string) $item['key'],
        ':stock_part_id' => (int) $partOf($item)['id'],
        ':component_name' => (string) $item['name'],
        ':component_code' => (string) $item['code'],
        ':monitoring_type' => $unit === 'luni' ? 'Luni' : 'Kilometri',
        ':methods' => json_encode([$unit === 'luni' ? 'Luni' : 'Kilometri']),
        ':interval' => $format($interval),
        ':warning' => $format($interval * 0.8),
        ':critical' => $format($interval * 0.95),
        ':lifetime' => $format($interval * 1.2),
        ':unit' => $unit,
        ':notes' => MARKER,
        ':created_at' => $createdAt,
        ':updated_at' => $createdAt,
    ]);
    $configs += $configure->rowCount();
};

// Montare pe pozitie prin modelul aplicatiei (din stoc scade stocul; direct nu).
$mounts = [];
$mount = static function (int $vehicleId, string $name, string $location, string $source, string $date, int $kmBefore, ?int $replace = null, float $quantity = 1) use ($components, $vehicles, $keyOf, $partOf, &$mounts): int {
    $item = $keyOf[$name];
    preg_match('/^A(\d+)(?:-([LR]))?$/', $location, $match);
    $km = max(0, (int) $vehicles[$vehicleId]['km_bord'] - $kmBefore);
    $id = $components->mount([
        'vehicle_id' => $vehicleId,
        'part_id' => (int) $partOf($item)['id'],
        'component_key' => (string) $item['key'],
        'location' => $location,
        'axle' => isset($match[1]) ? (int) $match[1] : null,
        'side' => isset($match[2]) && $match[2] !== '' ? $match[2] : null,
        'source' => $source,
        'quantity' => $quantity,
        'date' => $date,
        'km' => $km,
        'notes' => MARKER . ' · ' . ($source === 'stoc' ? 'montat din stocul de test' : 'montaj direct (service)'),
    ], $replace, null, 'Atelier test');
    $mounts[] = $id;
    return $id;
};
$inspect = static function (int $vehicleId, string $category, string $location, string $result, string $date, int $kmBefore, string $note) use ($components, $vehicles): void {
    $components->inspect($vehicleId, $category, $location, null, $date, max(0, (int) $vehicles[$vehicleId]['km_bord'] - $kmBefore), $result, MARKER . ' · ' . $note, null);
};
$axle = static function (int $vehicleId, int $axleNo, array $data) use ($components): void {
    $components->saveAxleAttributes($vehicleId, $axleNo, $data + ['observatii' => MARKER], null);
};

try {
    // Ansamblu B 665 NET (cap tractor 11) + B 405 NET (semiremorca 12).
    foreach ([['Curea', 120000], ['Turbina', 400000], ['Alternator', 250000], ['Pompa Apa', 300000], ['Placute Frana', 90000], ['Disc Frana', 250000], ['Rulmenti', 350000], ['Perne Aer', 300000], ['Caseta De Directie', 600000], ['Unitate Ad-Blue', 400000]] as [$name, $interval]) {
        $config(11, $name, $interval);
    }
    $config(11, 'Acumulatori', 36, 'luni', '2024-03-01 08:00:00');
    $mount(11, 'Curea', 'vehicul', 'stoc', '2026-03-10', 9000);
    $mount(11, 'Pompa Apa', 'vehicul', 'direct', '2025-12-02', 11000);
    $mount(11, 'Acumulatori', 'vehicul', 'stoc', '2024-03-01', 12000, null, 2);
    $old = $mount(11, 'Placute Frana', 'A1-L', 'stoc', '2025-10-05', 12000);
    $mount(11, 'Placute Frana', 'A1-L', 'stoc', '2026-06-18', 4000, $old);
    $mount(11, 'Placute Frana', 'A1-R', 'stoc', '2026-06-18', 4000);
    $mount(11, 'Disc Frana', 'A2-L', 'direct', '2025-11-20', 10500);
    $mount(11, 'Perne Aer', 'A2', 'stoc', '2026-02-14', 7000, null, 2);
    $mount(11, 'Faruri', 'fata', 'direct', '2026-05-03', 3000);
    $inspect(11, 'franare', 'A2-L', 'atentie', '2026-10-28', 300, 'plăcuțe la 4 mm, de urmărit');
    $inspect(11, 'motor', 'vehicul', 'ok', '2026-10-15', 600, 'fără scurgeri, curea întinsă corect');
    $inspect(11, 'directie', 'caseta', 'ok', '2026-09-30', 1200, 'joc normal la volan');
    $axle(11, 1, ['ridicabila' => '0', 'portanta' => '1', 'tip_suspensie' => 'arcuri', 'tip_franare' => 'disc']);
    $axle(11, 2, ['ridicabila' => '0', 'portanta' => '1', 'tip_suspensie' => 'pneumatica', 'tip_franare' => 'disc']);

    foreach ([['Placute Frana', 120000], ['Rulmenti', 400000], ['Perne Aer', 300000], ['Senzor ABS', 300000], ['Stopuri', 200000]] as [$name, $interval]) {
        $config(12, $name, $interval);
    }
    $mount(12, 'Placute Frana', 'A1-L', 'stoc', '2026-04-22', 6000);
    $mount(12, 'Placute Frana', 'A2-R', 'stoc', '2026-04-22', 6000);
    $mount(12, 'Rulmenti', 'A3-L', 'direct', '2025-09-12', 12000);
    $mount(12, 'Perne Aer', 'A1', 'stoc', '2025-12-15', 9000, null, 2);
    $mount(12, 'Senzor ABS', 'vehicul', 'stoc', '2026-07-01', 2500);
    $temp = $mount(12, 'Stopuri', 'spate', 'stoc', '2026-08-02', 2000);
    $components->unmount($temp, 12, '2026-08-20', max(0, (int) $vehicles[12]['km_bord'] - 1500), 'retur_stoc', 'Lampă greșită, returnată în stoc');
    $inspect(12, 'suspensie', 'A2', 'inlocuire', '2026-10-30', 200, 'pernă A2 dreapta fisurată');
    $inspect(12, 'franare', 'A1-L', 'ok', '2026-10-30', 200, 'plăcuțe noi');
    foreach ([1, 2, 3] as $no) {
        $axle(12, $no, ['ridicabila' => $no === 3 ? '1' : '0', 'portanta' => '1', 'tip_suspensie' => 'pneumatica', 'tip_franare' => 'disc']);
    }

    // Ansamblu B 605 NET (28) + B 705 NET (56): km mari, uzura vizibila.
    foreach ([['Curea', 120000], ['Turbina', 400000], ['Compresor Aer', 500000], ['Placute Frana', 90000], ['Rulmenti', 350000], ['Amortizoare', 250000], ['Termostast', 200000]] as [$name, $interval]) {
        $config(28, $name, $interval);
    }
    $mount(28, 'Curea', 'vehicul', 'stoc', '2025-08-04', 105000);
    $mount(28, 'Turbina', 'vehicul', 'stoc', '2024-11-12', 240000);
    $mount(28, 'Placute Frana', 'A1-R', 'stoc', '2026-01-09', 70000);
    $mount(28, 'Amortizoare', 'A1', 'stoc', '2025-05-20', 160000);
    $inspect(28, 'franare', 'A1-R', 'inlocuire', '2026-10-25', 800, 'plăcuțe sub limita minimă');
    $inspect(28, 'motor', 'vehicul', 'atentie', '2026-10-25', 800, 'curea cu fisuri fine');
    $config(56, 'Placute Frana', 120000);
    $mount(56, 'Placute Frana', 'A3-R', 'direct', '2026-02-02', 9000);

    // Camioane: B 235 NET (24, 4x2) si B 325 NET (6, 6x2).
    foreach ([['Curea', 120000], ['Placute Frana', 80000], ['Disc Frana', 240000], ['Rulmenti', 300000], ['Pompa Apa', 300000], ['Bara Transversala', 300000]] as [$name, $interval]) {
        $config(24, $name, $interval);
    }
    $config(24, 'Acumulatori', 48, 'luni', '2023-09-01 08:00:00');
    $mount(24, 'Curea', 'vehicul', 'stoc', '2026-01-15', 15000);
    $mount(24, 'Placute Frana', 'A1-L', 'stoc', '2026-05-30', 9000);
    $mount(24, 'Placute Frana', 'A1-R', 'stoc', '2026-05-30', 9000);
    $mount(24, 'Disc Frana', 'A1-L', 'direct', '2025-10-10', 18000);
    $mount(24, 'Rulmenti', 'A2-R', 'stoc', '2025-07-07', 21000);
    $mount(24, 'Acumulatori', 'vehicul', 'direct', '2023-09-01', 22000, null, 2);
    $mount(24, 'Bara Transversala', 'caseta', 'stoc', '2026-03-03', 12000);
    $inspect(24, 'franare', 'A2-L', 'atentie', '2026-10-20', 900, 'disc cu urme de supraîncălzire');
    $inspect(24, 'racire', 'vehicul', 'ok', '2026-10-01', 1500, 'antigel la nivel');
    $axle(24, 1, ['ridicabila' => '0', 'portanta' => '1', 'tip_suspensie' => 'arcuri', 'tip_franare' => 'disc']);
    $axle(24, 2, ['ridicabila' => '0', 'portanta' => '1', 'tip_suspensie' => 'arcuri', 'tip_franare' => 'tambur']);

    $config(6, 'Placute Frana', 80000);
    $config(6, 'Etrieri', 300000);
    $mount(6, 'Placute Frana', 'A3-L', 'stoc', '2026-06-06', 5000);
    $mount(6, 'Etrieri', 'A1-R', 'direct', '2026-04-14', 7000);
    $axle(6, 3, ['ridicabila' => '1', 'portanta' => '1', 'tip_suspensie' => 'pneumatica', 'tip_franare' => 'disc']);
    $inspect(6, 'suspensie', 'A3', 'ok', '2026-10-12', 700, 'axa ridicabilă funcțională');

    // Autoutilitara B 112 NET (76).
    $config(76, 'Curea', 90000);
    $config(76, 'Placute Frana', 50000);
    $mount(76, 'Curea', 'vehicul', 'stoc', '2026-02-11', 6000);
    $mount(76, 'Placute Frana', 'A1-L', 'stoc', '2026-07-19', 2500);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Eroare: ' . $exception->getMessage() . "\nRuleaza --remove pentru a curata ce s-a adaugat pana aici.\n");
    exit(1);
}

$inspections = (int) $db->query("SELECT COUNT(*) FROM vehicule_componente_inspectii WHERE observatii LIKE '" . MARKER . "%'")->fetchColumn();
$axles = (int) $db->query("SELECT COUNT(*) FROM vehicule_axe_atribute WHERE observatii = '" . MARKER . "'")->fetchColumn();
echo "Piese cu stoc de test:   " . count($backup) . "\n";
echo "Configurari adaugate:    {$configs}\n";
echo "Montari (cu inlocuire):  " . count($mounts) . " (1 inlocuire, 1 demontare cu retur in stoc)\n";
echo "Inspectii:               {$inspections}\n";
echo "Atribute axe:            {$axles}\n";
echo "Sterge totul cu: php scripts/seed_explorator_componente_demo.php --remove\n";
