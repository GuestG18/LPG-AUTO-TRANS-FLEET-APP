<?php
declare(strict_types=1);

/**
 * Populează modulul "Echipamente șoferi" cu un set de date realist:
 * catalog, stoc și alocări pentru șoferii activi din baza de date.
 *
 * Rulare:  php scripts/seed_echipamente_soferi_demo.php [--reset]
 *
 * --reset golește alocările, stocul și catalogul modulului înainte de seed.
 * Scriptul nu atinge niciun alt tabel al aplicației.
 */

require_once __DIR__ . '/../htdocs/config/config.php';
require_once __DIR__ . '/../htdocs/config/database.php';

$db = get_pdo();
$reset = in_array('--reset', $argv, true);

if ($reset) {
    $db->exec('DELETE FROM echipamente_miscari');
    $db->exec('DELETE FROM echipamente_stoc_unitati');
    $db->exec('DELETE FROM echipamente_alocari');
    $db->exec('DELETE FROM echipamente_stoc');
    $db->exec('DELETE FROM echipamente_catalog');
    echo "Tabelele modulului au fost golite.\n";
}

// ---------------------------------------------------------------------------
// 1. Catalog: fiecare articol își aduce propria logică de urmărire.
// ---------------------------------------------------------------------------
$catalog = [
    // denumire, categorie, grupa, destinatie, tip_logic, returnabil, urm_stare, periodic,
    // durata_luni, expirare, marime, identificator, serializat, cost, cost_lunar, prag, locatie
    ['Bocanci S3', 'PPE', 'fizic', 'sofer', 'stare_periodic', 0, 1, 1, 12, 0, 1, 0, 0, 320.00, null, 3, 'Depozit principal'],
    ['Vestă reflectorizantă', 'PPE', 'fizic', 'sofer', 'stare', 0, 1, 0, null, 0, 1, 0, 0, 45.00, null, 5, 'Depozit principal'],
    ['Cască protecție', 'PPE', 'fizic', 'sofer', 'stare_periodic', 1, 1, 1, 60, 1, 0, 0, 0, 110.00, null, 3, 'Depozit principal'],
    ['Geacă iarnă', 'Îmbrăcăminte', 'fizic', 'sofer', 'stare', 0, 1, 0, null, 0, 1, 0, 0, 280.00, null, 4, 'Depozit principal'],
    ['Pantaloni lucru', 'Îmbrăcăminte', 'fizic', 'sofer', 'stare', 0, 1, 0, null, 0, 1, 0, 0, 120.00, null, 6, 'Depozit principal'],
    ['Trusă scule', 'Scule', 'fizic', 'sofer', 'asset', 1, 1, 0, null, 0, 0, 1, 1, 450.00, null, 2, 'Depozit principal'],
    ['Telefon Samsung A15', 'Comunicații', 'comunicatii', 'ambele', 'asset', 1, 0, 0, null, 0, 0, 1, 1, 1100.00, null, 2, 'Birou'],
    ['SIM Orange', 'Comunicații', 'comunicatii', 'ambele', 'service_asset', 1, 0, 0, null, 0, 0, 1, 1, 0.00, 25.00, 2, 'Birou'],
    ['Tabletă', 'Electronică', 'comunicatii', 'ambele', 'asset', 1, 0, 0, null, 0, 0, 1, 1, 900.00, null, 1, 'Birou'],
    ['Stație radio', 'Comunicații', 'comunicatii', 'sofer', 'asset', 1, 0, 0, null, 0, 0, 1, 1, 380.00, null, 2, 'Birou'],
    ['Card combustibil', 'Carduri', 'comunicatii', 'ambele', 'service_asset', 1, 0, 0, null, 1, 0, 1, 1, 0.00, 0.00, 3, 'Birou'],

    // Articole de birou (TESA). Catalogul si stocul raman comune: destinatia
    // este doar sugestia din formularul de predare.
    ['Laptop Dell Latitude', 'IT', 'it_birou', 'tesa', 'asset', 1, 1, 0, null, 0, 0, 1, 1, 4200.00, null, 1, 'Sediu central'],
    ['Monitor 24"', 'IT', 'it_birou', 'tesa', 'asset', 1, 1, 0, null, 0, 0, 1, 1, 780.00, null, 2, 'Sediu central'],
    ['Docking station', 'IT', 'it_birou', 'tesa', 'asset', 1, 1, 0, null, 0, 0, 1, 1, 650.00, null, 1, 'Sediu central'],
    ['Headset birou', 'IT', 'it_birou', 'tesa', 'asset', 1, 1, 0, null, 0, 0, 0, 0, 320.00, null, 2, 'Sediu central'],
    ['Tastatură + mouse', 'IT', 'it_birou', 'tesa', 'stare', 1, 1, 0, null, 0, 0, 0, 0, 180.00, null, 3, 'Sediu central'],
    ['Badge acces', 'Acces', 'acces', 'tesa', 'asset', 1, 0, 0, null, 0, 0, 1, 1, 45.00, null, 5, 'Sediu central'],
    ['Token semnătură', 'Acces', 'acces', 'tesa', 'asset', 1, 0, 0, null, 1, 0, 1, 1, 220.00, null, 2, 'Sediu central'],
    ['Chei birou', 'Acces', 'acces', 'ambele', 'asset', 1, 0, 0, null, 0, 0, 1, 1, 25.00, null, 4, 'Sediu central'],
];

$insertCatalog = $db->prepare("
    INSERT INTO echipamente_catalog
        (denumire, categorie, grupa, destinatie, tip_logic, returnabil, urmareste_stare, inlocuire_periodica,
         durata_standard_luni, urmareste_expirare, necesita_marime, necesita_identificator, serializat,
         cost_implicit, cost_lunar, prag_minim_stoc, locatie_implicita, activ, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        categorie = VALUES(categorie), grupa = VALUES(grupa), destinatie = VALUES(destinatie),
        tip_logic = VALUES(tip_logic),
        returnabil = VALUES(returnabil), urmareste_stare = VALUES(urmareste_stare),
        inlocuire_periodica = VALUES(inlocuire_periodica), durata_standard_luni = VALUES(durata_standard_luni),
        urmareste_expirare = VALUES(urmareste_expirare), necesita_marime = VALUES(necesita_marime),
        necesita_identificator = VALUES(necesita_identificator), serializat = VALUES(serializat),
        cost_implicit = VALUES(cost_implicit),
        cost_lunar = VALUES(cost_lunar), prag_minim_stoc = VALUES(prag_minim_stoc),
        locatie_implicita = VALUES(locatie_implicita), activ = 1, updated_at = NOW()
");

foreach ($catalog as $item) {
    $insertCatalog->execute($item);
}

$catalogIds = [];
$catalogRows = [];
foreach ($db->query('SELECT * FROM echipamente_catalog')->fetchAll() as $row) {
    $catalogIds[(string) $row['denumire']] = (int) $row['id'];
    $catalogRows[(string) $row['denumire']] = $row;
}
echo 'Catalog: ' . count($catalogIds) . " articole.\n";

// ---------------------------------------------------------------------------
// 2. Stoc nealocat, pe mărimi acolo unde articolul cere mărime.
//    Bocancii ilustrează exact regula de înlocuire: 43 se poate rezolva din
//    stoc, 45 nu, deși totalul articolului arată bucăți disponibile.
// ---------------------------------------------------------------------------
$stock = [
    // articol => [prag minim, cost, [mărime => disponibil], [mărime => rezervat]]
    'Bocanci S3' => [3, 320.00, ['42' => 2, '43' => 3, '44' => 1], ['43' => 1]],
    'Vestă reflectorizantă' => [5, 45.00, ['M' => 5, 'L' => 6, 'XL' => 3], []],
    'Cască protecție' => [3, 110.00, ['' => 7], []],
    'Geacă iarnă' => [4, 280.00, ['M' => 2, 'L' => 2, 'XL' => 1], ['L' => 1]],
    'Pantaloni lucru' => [6, 120.00, ['M' => 4, 'L' => 5, 'XL' => 2], []],
    'Trusă scule' => [2, 450.00, ['' => 2], []],
    'Telefon Samsung A15' => [2, 1100.00, ['' => 1], []],
    'SIM Orange' => [2, 25.00, ['' => 3], []],
    'Tabletă' => [1, 900.00, ['' => 0], []],
    'Stație radio' => [2, 380.00, ['' => 4], []],
    'Card combustibil' => [3, 0.00, ['' => 5], []],
    'Laptop Dell Latitude' => [1, 4200.00, ['' => 2], []],
    'Monitor 24"' => [2, 780.00, ['' => 3], []],
    'Docking station' => [1, 650.00, ['' => 2], []],
    'Headset birou' => [2, 320.00, ['' => 3], []],
    'Tastatură + mouse' => [3, 180.00, ['' => 4], []],
    'Badge acces' => [5, 45.00, ['' => 6], []],
    'Token semnătură' => [2, 220.00, ['' => 2], []],
    'Chei birou' => [4, 25.00, ['' => 5], []],
];

$insertStock = $db->prepare("
    INSERT INTO echipamente_stoc
        (catalog_id, locatie, marime, disponibil, rezervat, prag_minim, cost_unitar, utilizabil_inlocuire, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        disponibil = VALUES(disponibil), rezervat = VALUES(rezervat), prag_minim = VALUES(prag_minim),
        cost_unitar = VALUES(cost_unitar), updated_at = NOW()
");

$stockIds = [];
$stockLines = 0;
foreach ($stock as $name => [$threshold, $cost, $bySize, $reserved]) {
    if (!isset($catalogIds[$name])) {
        continue;
    }
    $location = (string) $catalogRows[$name]['locatie_implicita'];

    foreach ($bySize as $size => $available) {
        // Pragul pe mărime este mai mic decât ținta articolului: pe o mărime
        // anume ai nevoie de mai puține bucăți decât pe tot articolul.
        $sizeThreshold = (string) $size !== '' ? max(1, (int) floor($threshold / 3)) : $threshold;

        $insertStock->execute([
            $catalogIds[$name],
            $location,
            (string) $size,
            $available,
            (int) ($reserved[$size] ?? 0),
            $sizeThreshold,
            $cost,
        ]);
        $stockIds[$name][(string) $size] = (int) $db->lastInsertId();
        $stockLines++;
    }
}
echo 'Stoc: ' . $stockLines . " linii (articol + gestiune + mărime).\n";

// ---------------------------------------------------------------------------
// 3. Unități individuale pentru articolele serializate (telefon, SIM, tabletă,
//    stație radio, card, trusă). Fiecare bucată disponibilă are propria fișă.
// ---------------------------------------------------------------------------
$insertUnit = $db->prepare("
    INSERT INTO echipamente_stoc_unitati
        (catalog_id, stoc_id, alocare_id, serie, iccid, numar_telefon, operator, tip_abonament, cost_lunar,
         marime, locatie, stare, status, data_intrarii, cost_achizitie, furnizor, document, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");

$suppliers = ['Orange Business', 'Depozit PPE Vest', 'Flanco Business', 'Telekom Retail'];
$unitSequence = 0;

/** Construiește datele de identificare potrivite tipului de articol. */
$buildUnitIdentity = static function (string $name, int $index): array {
    $suffix = str_pad((string) (100000 + $index * 37), 6, '0', STR_PAD_LEFT);

    return match ($name) {
        'SIM Orange' => [
            'serie' => null,
            'iccid' => '8940' . str_pad((string) (11000000000 + $index * 7919), 15, '0', STR_PAD_LEFT),
            'numar_telefon' => '0740 ' . substr($suffix, 0, 3) . ' ' . substr($suffix, 3),
            'operator' => 'Orange',
            'tip_abonament' => 'abonament',
            'cost_lunar' => 25.00,
        ],
        'Telefon Samsung A15' => [
            'serie' => 'IMEI: 3567891' . str_pad((string) (20000000 + $index * 311), 8, '0', STR_PAD_LEFT),
            'iccid' => null,
            'numar_telefon' => null,
            'operator' => null,
            'tip_abonament' => 'n/a',
            'cost_lunar' => null,
        ],
        'Card combustibil' => [
            'serie' => 'CARD-' . str_pad((string) (4100 + $index), 6, '0', STR_PAD_LEFT),
            'iccid' => null,
            'numar_telefon' => null,
            'operator' => 'OMV',
            'tip_abonament' => 'n/a',
            'cost_lunar' => null,
        ],
        default => [
            'serie' => strtoupper(substr(md5($name . $index), 0, 4)) . '-' . str_pad((string) (1000 + $index * 13), 5, '0', STR_PAD_LEFT),
            'iccid' => null,
            'numar_telefon' => null,
            'operator' => null,
            'tip_abonament' => 'n/a',
            'cost_lunar' => null,
        ],
    };
};

$createUnit = static function (
    string $name,
    ?int $allocationId,
    string $status,
    string $condition = 'noua'
) use ($db, $insertUnit, $catalogIds, $catalogRows, $stockIds, $stock, $suppliers, $buildUnitIdentity, &$unitSequence): void {
    $unitSequence++;
    $identity = $buildUnitIdentity($name, $unitSequence);
    $entry = (new DateTimeImmutable('today'))->modify('-' . (30 + $unitSequence * 5) . ' days');

    $insertUnit->execute([
        $catalogIds[$name],
        $stockIds[$name][''] ?? null,
        $allocationId,
        $identity['serie'],
        $identity['iccid'],
        $identity['numar_telefon'],
        $identity['operator'],
        $identity['tip_abonament'],
        $identity['cost_lunar'],
        '',
        (string) $catalogRows[$name]['locatie_implicita'],
        $condition,
        $status,
        $entry->format('Y-m-d'),
        (float) ($stock[$name][1] ?? $catalogRows[$name]['cost_implicit']),
        $suppliers[$unitSequence % count($suppliers)],
        'FCT-' . (2600 + $unitSequence),
    ]);
};

$serializedItems = array_keys(array_filter(
    $catalogRows,
    static fn(array $row): bool => (int) $row['serializat'] === 1
));

$availableUnits = 0;
foreach ($serializedItems as $name) {
    $available = (int) ($stock[$name][2][''] ?? 0);
    for ($i = 0; $i < $available; $i++) {
        $createUnit($name, null, 'disponibil');
        $availableUnits++;
    }
}
echo 'Unități disponibile în stoc: ' . $availableUnits . ".\n";

// ---------------------------------------------------------------------------
// 4. Alocări pentru șoferii activi.
// ---------------------------------------------------------------------------
$drivers = $db->query("
    SELECT id, nume FROM soferi
    WHERE status = 'activ' AND COALESCE(employment_status, 'active') <> 'terminated'
    ORDER BY nume ASC
    LIMIT 12
")->fetchAll();

if ($drivers === []) {
    echo "Nu există șoferi activi; alocările au fost omise.\n";
    exit(0);
}

$existing = (int) $db->query('SELECT COUNT(*) FROM echipamente_alocari')->fetchColumn();
if ($existing > 0 && !$reset) {
    echo "Există deja $existing alocări; rulează cu --reset pentru a le reface.\n";
    exit(0);
}

$insertAllocation = $db->prepare("
    INSERT INTO echipamente_alocari
        (detinator_tip, driver_id, staff_id, catalog_id, cantitate, marime, identificator, operator,
         data_predarii, cost_unitar, cost_lunar, stare, status, data_inlocuirii_planificata, observatii,
         created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
");

$logMovement = $db->prepare("
    INSERT INTO echipamente_miscari (alocare_id, catalog_id, driver_id, detinator_tip, staff_id, tip,
                                     cantitate, marime, locatie, motiv, observatii, created_at)
    VALUES (?, ?, ?, ?, ?, 'predare', ?, ?, ?, 'atribuit', 'Seed date demonstrative', NOW())
");

// Fiecare linie: [articol, cantitate, mărime, stare, status]
$plans = [
    // Șofer complet echipat, totul în regulă.
    [
        ['Bocanci S3', 1, '43', 'buna', 'in_uz'],
        ['Geacă iarnă', 1, 'L', 'buna', 'in_uz'],
        ['Pantaloni lucru', 2, 'L', 'buna', 'in_uz'],
        ['Vestă reflectorizantă', 1, 'L', 'buna', 'in_uz'],
        ['Cască protecție', 1, '', 'buna', 'in_uz'],
        ['Telefon Samsung A15', 1, '', 'buna', 'activ'],
        ['SIM Orange', 1, '', 'buna', 'activ'],
    ],
    // Bocanci 43 deteriorați: înlocuirea se poate face din stoc.
    [
        ['Bocanci S3', 1, '43', 'deteriorata', 'necesita_inlocuire'],
        ['Geacă iarnă', 1, 'XL', 'buna', 'in_uz'],
        ['Vestă reflectorizantă', 1, 'XL', 'buna', 'in_uz'],
        ['Telefon Samsung A15', 1, '', 'buna', 'activ'],
        ['SIM Orange', 1, '', 'buna', 'activ'],
    ],
    // Pantaloni uzați marcați pentru înlocuire + card combustibil.
    [
        ['Bocanci S3', 1, '42', 'buna', 'in_uz'],
        ['Pantaloni lucru', 2, 'M', 'uzata', 'de_inlocuit'],
        ['Vestă reflectorizantă', 1, 'M', 'buna', 'in_uz'],
        ['Card combustibil', 1, '', 'buna', 'activ'],
        ['SIM Orange', 1, '', 'buna', 'activ'],
    ],
    // Tabletă fără stoc de rezervă (aprovizionare necesară la înlocuire).
    [
        ['Bocanci S3', 1, '44', 'buna', 'in_uz'],
        ['Geacă iarnă', 1, 'M', 'buna', 'in_uz'],
        ['Vestă reflectorizantă', 1, 'M', 'buna', 'in_uz'],
        ['Cască protecție', 1, '', 'buna', 'in_uz'],
        ['Tabletă', 1, '', 'deteriorata', 'necesita_inlocuire'],
        ['Stație radio', 1, '', 'buna', 'activ'],
    ],
    // Echipare minimă.
    [
        ['Vestă reflectorizantă', 1, 'L', 'buna', 'in_uz'],
        ['Pantaloni lucru', 1, 'L', 'buna', 'in_uz'],
        ['SIM Orange', 1, '', 'buna', 'activ'],
    ],
    // Bocanci mărimea 45 deteriorați: stocul are alte mărimi, dar nu 45.
    [
        ['Bocanci S3', 1, '45', 'deteriorata', 'necesita_inlocuire'],
        ['Trusă scule', 1, '', 'buna', 'in_uz'],
        ['Vestă reflectorizantă', 1, 'XL', 'buna', 'in_uz'],
        ['Geacă iarnă', 1, 'XL', 'buna', 'in_uz'],
        ['Telefon Samsung A15', 1, '', 'buna', 'activ'],
        ['Stație radio', 1, '', 'buna', 'activ'],
    ],
];

$allocated = 0;
$allocatedUnits = 0;

foreach ($drivers as $index => $driver) {
    $plan = $plans[$index % count($plans)];
    $handover = (new DateTimeImmutable('today'))->modify('-' . (20 + $index * 17) . ' days');

    foreach ($plan as [$name, $quantity, $size, $condition, $status]) {
        if (!isset($catalogIds[$name])) {
            continue;
        }
        $item = $catalogRows[$name];
        $catalogId = $catalogIds[$name];
        $serialized = (int) $item['serializat'] === 1;

        // Articolele serializate primesc întâi fișa de unitate, ca alocarea să
        // poată prelua din ea seria / numărul de telefon.
        $identity = $serialized ? $buildUnitIdentity($name, $unitSequence + 1) : null;
        $identifier = $identity !== null
            ? ($identity['numar_telefon'] ?? $identity['serie'])
            : null;

        $deadline = null;
        if ((int) $item['inlocuire_periodica'] === 1 && (int) $item['durata_standard_luni'] > 0) {
            $deadline = $handover->modify('+' . (int) $item['durata_standard_luni'] . ' months')->format('Y-m-d');
        }

        $insertAllocation->execute([
            'sofer',
            (int) $driver['id'],
            null,
            $catalogId,
            $quantity,
            (int) $item['necesita_marime'] === 1 && $size !== '' ? $size : null,
            $identifier,
            $identity['operator'] ?? null,
            $handover->format('Y-m-d'),
            (float) $item['cost_implicit'],
            $item['cost_lunar'] !== null ? (float) $item['cost_lunar'] : null,
            $condition,
            $status,
            $deadline,
            $name === 'SIM Orange' ? 'pentru contact dispecerat' : null,
        ]);

        $allocationId = (int) $db->lastInsertId();

        if ($serialized) {
            $createUnit($name, $allocationId, 'alocat', $condition);
            $db->prepare('UPDATE echipamente_alocari SET unitate_id = ? WHERE id = ?')
               ->execute([(int) $db->lastInsertId(), $allocationId]);
            $allocatedUnits++;
        }

        $logMovement->execute([
            $allocationId,
            $catalogId,
            (int) $driver['id'],
            'sofer',
            null,
            $quantity,
            $size !== '' ? $size : null,
            (string) $item['locatie_implicita'],
        ]);

        $allocated++;
    }
}

echo 'Alocări: ' . $allocated . ' linii pentru ' . count($drivers) . " șoferi.\n";
echo 'Unități alocate: ' . $allocatedUnits . ".\n";

// ---------------------------------------------------------------------------
// 5. Alocări pentru personalul TESA existent (staff_members cu tip „office”).
//    Scriptul NU inventează angajați: dacă nu există personal de birou activ,
//    pagina TESA rămâne goală până când este adăugat din Contabilitate Personal.
// ---------------------------------------------------------------------------
$staff = $db->query("
    SELECT sm.id, sm.nume_complet
    FROM staff_members sm
    INNER JOIN staff_types stp ON stp.id = sm.staff_type_id
    WHERE stp.category = 'office'
      AND sm.status = 'activ' AND COALESCE(sm.employment_status, 'active') <> 'terminated'
    ORDER BY sm.nume_complet ASC
    LIMIT 8
")->fetchAll();

$staffPlans = [
    [
        ['Laptop Dell Latitude', 1, '', 'buna', 'in_uz'],
        ['Monitor 24"', 1, '', 'buna', 'in_uz'],
        ['Tastatură + mouse', 1, '', 'buna', 'in_uz'],
        ['Telefon Samsung A15', 1, '', 'buna', 'activ'],
        ['SIM Orange', 1, '', 'buna', 'activ'],
        ['Badge acces', 1, '', 'buna', 'in_uz'],
    ],
    [
        ['Laptop Dell Latitude', 1, '', 'buna', 'in_uz'],
        ['Docking station', 1, '', 'buna', 'in_uz'],
        ['Headset birou', 1, '', 'uzata', 'de_inlocuit'],
        ['SIM Orange', 1, '', 'buna', 'activ'],
        ['Token semnătură', 1, '', 'buna', 'in_uz'],
        ['Chei birou', 1, '', 'buna', 'in_uz'],
    ],
];

$staffAllocated = 0;
foreach ($staff as $index => $person) {
    $plan = $staffPlans[$index % count($staffPlans)];
    $handover = (new DateTimeImmutable('today'))->modify('-' . (12 + $index * 9) . ' days');

    foreach ($plan as [$name, $quantity, $size, $condition, $status]) {
        if (!isset($catalogIds[$name])) {
            continue;
        }
        $item = $catalogRows[$name];
        $catalogId = $catalogIds[$name];
        $serialized = (int) $item['serializat'] === 1;

        $identity = $serialized ? $buildUnitIdentity($name, $unitSequence + 1) : null;
        $identifier = $identity !== null ? ($identity['numar_telefon'] ?? $identity['serie']) : null;

        $insertAllocation->execute([
            'tesa',
            null,
            (int) $person['id'],
            $catalogId,
            $quantity,
            $size !== '' ? $size : null,
            $identifier,
            $identity['operator'] ?? null,
            $handover->format('Y-m-d'),
            (float) $item['cost_implicit'],
            $item['cost_lunar'] !== null ? (float) $item['cost_lunar'] : null,
            $condition,
            $status,
            null,
            null,
        ]);

        $allocationId = (int) $db->lastInsertId();

        if ($serialized) {
            $createUnit($name, $allocationId, 'alocat', $condition);
            $db->prepare('UPDATE echipamente_alocari SET unitate_id = ? WHERE id = ?')
               ->execute([(int) $db->lastInsertId(), $allocationId]);
        }

        $logMovement->execute([
            $allocationId,
            $catalogId,
            null,
            'tesa',
            (int) $person['id'],
            $quantity,
            $size !== '' ? $size : null,
            (string) $item['locatie_implicita'],
        ]);

        $staffAllocated++;
    }
}

echo 'Alocări TESA: ' . $staffAllocated . ' linii pentru ' . count($staff) . " persoane de birou.\n";
if ($staff === []) {
    echo "  (nu există personal de birou activ; adaugă-l din Contabilitate Personal)\n";
}
echo "Gata.\n";
