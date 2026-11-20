<?php
declare(strict_types=1);

/**
 * Date DEMO pentru pagina „Istoric Financiar Vehicul” (dosare de achizitie + un proiect GPL), create
 * prin serviciile reale (aceleasi validari si audit ca din pagina). Doar pentru baza locala de dezvoltare.
 *
 *   php scripts/seed_istoric_financiar_demo.php           # creeaza (o singura data)
 *   php scripts/seed_istoric_financiar_demo.php --remove  # sterge tot ce a creat (marcaj DEMO-IFV)
 *
 * Nu creeaza randuri in `mentenanta` (nu afecteaza costul / km).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/services/VehicleAcquisitionService.php';
require_once $root . '/htdocs/services/InvestmentProjectService.php';
require_once $root . '/htdocs/services/FinancialClassificationService.php';

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$marker = 'DEMO-IFV';
$ids = $db->query("SELECT id FROM achizitii_vehicule WHERE observatii LIKE '$marker%'")->fetchAll(PDO::FETCH_COLUMN);

if (in_array('--remove', $argv, true)) {
    $db->beginTransaction();
    $projects = $db->query("SELECT id FROM investitii_proiecte WHERE observatii LIKE '$marker%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($projects as $p) {
        $db->prepare("DELETE FROM financiar_audit WHERE entitate = 'proiect_investitie' AND entitate_id = ?")->execute([$p]);
        $db->prepare('DELETE FROM investitii_proiecte WHERE id = ?')->execute([$p]);
    }
    $uploadDir = $root . '/htdocs/uploads/achizitii_vehicule/';
    foreach ($ids as $a) {
        foreach (['SELECT fisier_stocat FROM achizitii_vehicule_documente WHERE achizitie_id = ?', 'SELECT document_fisier FROM achizitii_vehicule_cheltuieli WHERE achizitie_id = ?'] as $sql) {
            $files = $db->prepare($sql);
            $files->execute([$a]);
            foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $file) {
                if ($file && is_file($uploadDir . basename((string) $file))) {
                    @unlink($uploadDir . basename((string) $file));
                }
            }
        }
        $lines = $db->prepare('SELECT id FROM achizitii_vehicule_cheltuieli WHERE achizitie_id = ?');
        $lines->execute([$a]);
        foreach ($lines->fetchAll(PDO::FETCH_COLUMN) as $l) {
            $db->prepare("DELETE FROM financiar_audit WHERE entitate = 'achizitie_cheltuiala' AND entitate_id = ?")->execute([$l]);
        }
        foreach (['achizitii_vehicule_plati', 'achizitii_vehicule_transport', 'achizitii_vehicule_evenimente', 'achizitii_vehicule_documente'] as $t) {
            $db->prepare("DELETE FROM $t WHERE achizitie_id = ?")->execute([$a]);
        }
        $db->prepare('UPDATE achizitii_vehicule_cheltuieli SET inclus_in_linie_id = NULL WHERE achizitie_id = ?')->execute([$a]);
        $db->prepare('DELETE FROM achizitii_vehicule_cheltuieli WHERE achizitie_id = ?')->execute([$a]);
        $db->prepare("DELETE FROM financiar_audit WHERE entitate = 'achizitie' AND entitate_id = ?")->execute([$a]);
        $db->prepare('DELETE FROM achizitii_vehicule WHERE id = ?')->execute([$a]);
    }
    $db->commit();
    echo 'Sterse: ' . count($ids) . ' dosare, ' . count($projects) . " proiecte demo.\n";
    exit(0);
}
if ($ids !== []) {
    echo "Datele demo exista deja (" . count($ids) . " dosare). Folositi --remove pentru a le sterge.\n";
    exit(0);
}

$user = (int) $db->query('SELECT id FROM utilizatori ORDER BY id LIMIT 1')->fetchColumn();
$acq = new VehicleAcquisitionService($db);
$cls = new FinancialClassificationService($db);
$vehicle = static function (string $plate) use ($db): int {
    $s = $db->prepare('SELECT id FROM vehicule WHERE nr_inmatriculare = ?');
    $s->execute([$plate]);
    return (int) $s->fetchColumn() ?: throw new RuntimeException("Vehicul lipsa: $plate");
};
$approve = static function (int $line, string $treatment) use ($cls, $user): void {
    $cls->setTreatment('achizitie_cheltuiala', $line, $treatment, $user, $treatment === 'capitalizabil' ? 'Cost de achiziție direct atribuibil (demo)' : 'Cheltuială de exploatare (demo)');
};

// 1. Cap tractor din Germania, finalizat
$a1 = $acq->createDossier(['tip_vehicul' => 'cap_tractor', 'stare' => 'rulat', 'modalitate_achizitie' => 'plata_integrala', 'vehicle_id' => $vehicle('B 665 NET'),
    'marca' => 'DAF', 'model' => 'XF 480', 'an_fabricatie' => 2019, 'vanzator_nume' => 'DAF Trucks GmbH', 'vanzator_tara' => 'DE', 'tara_achizitie' => 'DE',
    'locatie_vehicul' => 'Köln', 'data_contract' => '2020-03-10', 'data_factura' => '2020-03-15', 'data_transfer_proprietate' => '2020-03-15',
    'pret_achizitie' => 90000, 'moneda' => 'RON', 'regim_tva' => 'intracomunitar', 'utilizare_prevazuta' => 'Transport GPL — tractare cisternă',
    'observatii' => "$marker Dosar demonstrativ"], $user);
$acq->saveTransport($a1, ['modalitate' => 'transportator', 'transportator' => 'TransLog SRL', 'comanda_transport' => 'CMD-2020-118', 'loc_preluare' => 'Köln', 'loc_destinatie' => 'Ploiești',
    'data_plecare' => '2020-03-15', 'data_sosire' => '2020-03-16', 'livrare_confirmata_la' => '2020-03-16'], $user);
$l = $acq->addCost($a1, ['categorie' => 'pret_achizitie', 'descriere' => 'Preț cumpărare DAF XF 480', 'furnizor' => 'DAF Trucks GmbH', 'data_tranzactie' => '2020-03-15', 'suma_neta' => 90000, 'tva' => 0, 'tva_deductibil' => 'nu', 'status_verificare' => 'confirmat', 'document_tip' => 'Factură', 'document_numar' => '1254'], $user);
$approve($l, 'capitalizabil');
$acq->addPayment($a1, ['data_plata' => '2020-03-15', 'suma' => 90000, 'metoda' => 'transfer_bancar', 'referinta' => 'OP 412', 'cheltuiala_linie_id' => $l], $user);
foreach ([
    ['transport_extern', 'Transport Germania – România', 'TransLog SRL', '2020-03-16', 2500, 475, 'Factură', '1260', 'capitalizabil'],
    ['rar_verificare', 'RAR, verificări, documente', 'RAR', '2020-03-17', 800, 0, 'Chitanță', '3487', 'capitalizabil'],
    ['pregatire_tehnica', 'Pregătire tehnică', 'Service Auto SRL', '2020-03-17', 700, 133, 'Factură', '1275', 'cheltuiala'],
    ['echipare_initiala', 'GPS, tahograf, accesorii', 'Fleet Equip SRL', '2020-03-18', 1500, 285, 'Factură', '1281', 'capitalizabil'],
] as [$cat, $desc, $sup, $date, $sum, $vat, $docType, $docNo, $treat]) {
    $id = $acq->addCost($a1, ['categorie' => $cat, 'descriere' => $desc, 'furnizor' => $sup, 'data_tranzactie' => $date, 'suma_neta' => $sum, 'tva' => $vat, 'tva_deductibil' => 'da', 'status_verificare' => 'confirmat', 'document_tip' => $docType, 'document_numar' => $docNo], $user);
    $approve($id, $treat);
    $acq->addPayment($a1, ['data_plata' => $date, 'suma' => $sum + $vat, 'metoda' => 'transfer_bancar', 'cheltuiala_linie_id' => $id], $user);
}
foreach (['achizitie' => '2020-03-15', 'transfer_proprietate' => '2020-03-15', 'preluare' => '2020-03-15', 'sosire_ro' => '2020-03-16', 'receptie_tehnica' => '2020-03-17', 'inmatriculare' => '2020-03-19', 'punere_in_functiune' => '2020-03-20'] as $ev => $d) {
    $acq->recordEvent($a1, $ev, $d, $user);
}
$acq->setStatus($a1, 'pus_in_functiune', $user);

// 2. Camion din România, finalizat
$a2 = $acq->createDossier(['tip_vehicul' => 'camion', 'stare' => 'rulat', 'modalitate_achizitie' => 'leasing_financiar', 'vehicle_id' => $vehicle('B 775 NET'),
    'marca' => 'Mercedes-Benz', 'model' => 'Actros', 'vanzator_nume' => 'Trucks Center SRL', 'vanzator_tara' => 'RO', 'tara_achizitie' => 'RO', 'data_factura' => '2021-11-17',
    'pret_achizitie' => 84000, 'regim_tva' => 'normal', 'observatii' => "$marker Dosar demonstrativ"], $user);
$acq->saveTransport($a2, ['modalitate' => 'condus', 'sofer_nume' => 'Șofer flotă', 'loc_preluare' => 'București', 'loc_destinatie' => 'Ploiești', 'livrare_confirmata_la' => '2021-11-18'], $user);
foreach ([['pret_achizitie', 'Preț cumpărare Actros', 84000, 15960, 'capitalizabil'], ['combustibil_preluare', 'Combustibil preluare', 300, 57, 'cheltuiala'],
    ['inmatriculare', 'Înmatriculare și numere', 1200, 0, 'capitalizabil'], ['pregatire_tehnica', 'Revizie inițială', 2300, 437, 'cheltuiala'], ['echipare_initiala', 'Kit ADR și extinctoare', 1500, 285, 'capitalizabil']] as [$cat, $desc, $sum, $vat, $treat]) {
    $id = $acq->addCost($a2, ['categorie' => $cat, 'descriere' => $desc, 'furnizor' => 'Trucks Center SRL', 'data_tranzactie' => '2021-11-17', 'suma_neta' => $sum, 'tva' => $vat, 'tva_deductibil' => 'da', 'status_verificare' => 'confirmat', 'document_tip' => 'Factură', 'document_numar' => (string) random_int(2000, 2999)], $user);
    $approve($id, $treat);
}
$acq->addPayment($a2, ['data_plata' => '2021-11-17', 'suma' => 108039, 'metoda' => 'leasing', 'referinta' => 'Contract leasing'], $user);
foreach (['achizitie' => '2021-11-17', 'sosire_ro' => '2021-11-18', 'receptie_tehnica' => '2021-11-18', 'inmatriculare' => '2021-11-20', 'punere_in_functiune' => '2021-11-22'] as $ev => $d) {
    $acq->recordEvent($a2, $ev, $d, $user);
}
$acq->setStatus($a2, 'pus_in_functiune', $user);

// 3. Semiremorcă, în adaptare GPL (în derulare)
$tr = $db->query("SELECT id, nr_inmatriculare FROM vehicule WHERE tip_vehicul = 'semiremorca_primar' AND status = 'activ' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$a3 = $acq->createDossier(['tip_vehicul' => 'semiremorca_primar', 'stare' => 'nou', 'modalitate_achizitie' => 'credit', 'vehicle_id' => (int) $tr['id'],
    'marca' => 'Schwarzmüller', 'model' => 'Cisternă GPL', 'vanzator_nume' => 'Cisterne Est SRL', 'vanzator_tara' => 'HU', 'tara_achizitie' => 'HU', 'data_contract' => '2026-09-02',
    'data_factura' => '2026-09-10', 'pret_achizitie' => 52000, 'moneda' => 'EUR', 'curs_schimb' => 5.0751, 'curs_data' => '2026-09-10', 'curs_sursa' => 'BNR',
    'regim_tva' => 'intracomunitar', 'status' => 'in_adaptare', 'observatii' => "$marker Dosar demonstrativ"], $user);
$acq->saveTransport($a3, ['modalitate' => 'condus', 'sofer_nume' => 'Șofer flotă', 'numere_provizorii' => 'B-123456', 'asigurare_tranzit_ref' => 'AT-55/2026', 'loc_preluare' => 'Debrecen', 'loc_destinatie' => 'Ploiești', 'livrare_confirmata_la' => '2026-09-14'], $user);
$p3 = $acq->addCost($a3, ['categorie' => 'pret_achizitie', 'descriere' => 'Preț cumpărare cisternă', 'furnizor' => 'Cisterne Est SRL', 'data_tranzactie' => '2026-09-10', 'suma_neta' => 52000, 'moneda' => 'EUR', 'curs_schimb' => 5.0751, 'tva_deductibil' => 'nu', 'status_verificare' => 'confirmat', 'document_tip' => 'Factură', 'document_numar' => 'HU-8841'], $user);
$approve($p3, 'capitalizabil');
$acq->addPayment($a3, ['data_plata' => '2026-09-12', 'suma' => 30000, 'moneda' => 'EUR', 'curs_schimb' => 5.0751, 'cheltuiala_linie_id' => $p3], $user);
foreach ([['combustibil_preluare', 'Motorină preluare', 650, 123.5], ['taxe_drum', 'Taxe drum HU + RO', 420, 0], ['cazare_sofer', 'Cazare șofer', 380, 34.2], ['asigurare_tranzit', 'Asigurare tranzit', 260, 0], ['numere_provizorii', 'Numere provizorii', 300, 0]] as [$cat, $desc, $sum, $vat]) {
    $acq->addCost($a3, ['categorie' => $cat, 'descriere' => $desc, 'data_tranzactie' => '2026-09-13', 'suma_neta' => $sum, 'tva' => $vat, 'status_verificare' => $cat === 'cazare_sofer' ? 'estimat' : 'confirmat'], $user);
}
foreach (['achizitie' => '2026-09-10', 'transfer_proprietate' => '2026-09-12', 'preluare' => '2026-09-13', 'sosire_ro' => '2026-09-14', 'receptie_tehnica' => '2026-09-15'] as $ev => $d) {
    $acq->recordEvent($a3, $ev, $d, $user);
}
$projects = new InvestmentProjectService($db);
$proj = $projects->create(['vehicle_id' => (int) $tr['id'], 'denumire' => 'Adaptare semiremorcă pentru transport GPL', 'categorie' => 'adaptare_gpl', 'buget_planificat' => 65000,
    'data_start' => '2026-09-20', 'status' => 'in_executie', 'achizitie_id' => $a3, 'observatii' => "$marker Proiect demonstrativ"], $user);
$projects->confirmStage($proj, 'lucrari_finalizate', '2026-10-30', $user);
$projects->confirmStage($proj, 'echipamente_montate', '2026-10-30', $user);

// 4. Cap tractor provizoriu (neînmatriculat), în transport
$a4 = $acq->createDossier(['tip_vehicul' => 'cap_tractor', 'stare' => 'rulat', 'modalitate_achizitie' => 'plata_esalonata', 'vin' => 'WMA06XZZ9KM812345', 'marca' => 'MAN', 'model' => 'TGX 18.470',
    'an_fabricatie' => 2019, 'vanzator_nume' => 'Truck Trade BV', 'vanzator_tara' => 'NL', 'tara_achizitie' => 'NL', 'locatie_vehicul' => 'Venlo', 'data_contract' => '2026-11-04',
    'pret_achizitie' => 41000, 'moneda' => 'EUR', 'curs_schimb' => 5.0912, 'curs_data' => '2026-11-04', 'curs_sursa' => 'BNR', 'regim_tva' => 'intracomunitar', 'status' => 'in_transport',
    'observatii' => "$marker Dosar demonstrativ"], $user);
$acq->saveTransport($a4, ['modalitate' => 'transportator', 'transportator' => 'EuroCar Carrier', 'comanda_transport' => 'EC-7781', 'loc_preluare' => 'Venlo', 'loc_destinatie' => 'Ploiești',
    'servicii_incluse' => 'numere_provizorii,asigurare_tranzit', 'data_plecare' => '2026-11-16'], $user);
$p4 = $acq->addCost($a4, ['categorie' => 'pret_achizitie', 'descriere' => 'Preț cumpărare MAN TGX', 'furnizor' => 'Truck Trade BV', 'data_tranzactie' => '2026-11-04', 'suma_neta' => 41000, 'moneda' => 'EUR', 'curs_schimb' => 5.0912, 'tva_deductibil' => 'nu', 'status_verificare' => 'confirmat', 'document_tip' => 'Factură', 'document_numar' => 'TT-2026-311'], $user);
$acq->addCost($a4, ['categorie' => 'transport_extern', 'descriere' => 'Transport Olanda – România', 'furnizor' => 'EuroCar Carrier', 'data_tranzactie' => '2026-11-16', 'suma_neta' => 1650, 'moneda' => 'EUR', 'curs_schimb' => 5.0912, 'status_verificare' => 'estimat'], $user);
$acq->addPayment($a4, ['data_plata' => '2026-11-05', 'suma' => 20500, 'moneda' => 'EUR', 'curs_schimb' => 5.0912, 'cheltuiala_linie_id' => $p4, 'referinta' => 'Avans 50%'], $user);
$acq->recordEvent($a4, 'achizitie', '2026-11-04', $user);

echo "Creat: 4 dosare demo (#$a1, #$a2, #$a3, #$a4) si proiectul #$proj.\n";
