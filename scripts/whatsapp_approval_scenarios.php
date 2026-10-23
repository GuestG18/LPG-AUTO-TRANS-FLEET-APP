<?php
declare(strict_types=1);

/**
 * Situatii de test pentru aprobarile pe WhatsApp: cate o solicitare reala "in asteptare" pentru
 * fiecare tip care cere aprobarea administratorului (Vehicule, Soferi, Reparatii, Diurne, Rute).
 * Tab-ul "Operatori" nu e o aprobare (activitatea operatorilor) si nu are scenariu.
 *
 *   php scripts/whatsapp_approval_scenarios.php --create            toate cele 5 tipuri, date alese aleator
 *   php scripts/whatsapp_approval_scenarios.php --create=diurna     doar un tip (vehicle|driver|repair|diurna|route_config)
 *   php scripts/whatsapp_approval_scenarios.php --list              starea solicitarilor create
 *   php scripts/whatsapp_approval_scenarios.php --cleanup           sterge tot ce a creat scriptul si reface ruta
 *
 * Solicitarile se creeaza cu metodele aplicatiei (InactiveResourceApprovalModel), fara cursa
 * (nu blocheaza nicio cursa din Desfasurator); eticheta incepe cu "[TEST WhatsApp]". Starea
 * (id-urile + valoarea initiala a regulii de ruta) sta in storage/cache/wa_approval_scenarios.json,
 * ca --cleanup sa stearga doar ce a creat si sa refaca regula daca ruta a fost aprobata.
 * BaileyBot le trimite aprobatorilor din APPROVAL_WA_USER_IDS la urmatoarea citire a outbox-ului.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';
require_once $root . '/htdocs/models/BaseModel.php';
require_once $root . '/htdocs/models/InactiveResourceApprovalModel.php';

const TEST_PREFIX = '[TEST WhatsApp] ';
const TYPES = ['vehicle', 'driver', 'repair', 'diurna', 'route_config'];

$stateFile = $root . '/storage/cache/wa_approval_scenarios.json';
$mode = null;
$only = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--create(?:=(\w+))?$/', $argument, $m) === 1) {
        $mode = 'create';
        $only = $m[1] ?? null;
    } elseif ($argument === '--list' || $argument === '--cleanup') {
        $mode = substr($argument, 2);
    } else {
        fwrite(STDERR, "Argument necunoscut: $argument\n");
        exit(2);
    }
}
if ($mode === null || ($only !== null && !in_array($only, TYPES, true))) {
    fwrite(STDERR, "Folosire: --create[=" . implode('|', TYPES) . "] | --list | --cleanup\n");
    exit(2);
}

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$approvals = new InactiveResourceApprovalModel($db);
$approvals->ensureSchema();
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$state += ['approval_ids' => [], 'route_rules' => []];
$saveState = static function () use (&$state, $stateFile): void {
    @mkdir(dirname($stateFile), 0775, true);
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
};
$one = static function (string $sql, array $params = []) use ($db): mixed {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};
$log = static fn(string $m) => print('[' . date('H:i:s') . "] $m\n");

// -----------------------------------------------------------------------------
if ($mode === 'list') {
    if ($state['approval_ids'] === []) {
        $log('Nicio solicitare de test creata.');
        exit(0);
    }
    $in = implode(',', array_map('intval', $state['approval_ids']));
    foreach ($db->query("SELECT a.id, a.resource_type, a.status, a.resource_label, a.review_note, w.status AS wa_status
        FROM inactive_resource_approvals a LEFT JOIN approval_whatsapp_messages w ON w.approval_id = a.id
        WHERE a.id IN ($in) ORDER BY a.id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $log(sprintf('#%d %-12s %-9s %s | WhatsApp: %s%s', $row['id'], $row['resource_type'], $row['status'], $row['resource_label'],
            $row['wa_status'] ?? 'netrimis', $row['review_note'] ? ' | ' . $row['review_note'] : ''));
    }
    exit(0);
}

// -----------------------------------------------------------------------------
if ($mode === 'cleanup') {
    $ids = array_map('intval', $state['approval_ids']);
    $db->beginTransaction();
    if ($ids !== []) {
        $in = implode(',', $ids);
        // Doar randurile create de acest script (lista din fisierul de stare, eticheta de test).
        $db->exec("DELETE FROM whatsapp_outbox WHERE related_type = 'approval' AND related_id IN ($in)");
        $db->exec("DELETE FROM approval_whatsapp_messages WHERE approval_id IN ($in)");
        try {
            $db->exec("DELETE FROM approval_email_actions WHERE approval_id IN ($in)");
        } catch (PDOException) {
        }
        $deleted = $db->exec("DELETE FROM inactive_resource_approvals WHERE id IN ($in) AND resource_label LIKE '" . TEST_PREFIX . "%'");
        $log("Solicitari de test sterse: $deleted.");
    }
    foreach ($state['route_rules'] as $rule) {
        // O ruta aprobata adauga vehiculul in regula: o readucem la valoarea de dinainte.
        $db->prepare("UPDATE {$rule['table']} SET vehicle_ids = :v WHERE id = :id")->execute([':v' => $rule['vehicle_ids'], ':id' => $rule['id']]);
        $log("Regula {$rule['table']} #{$rule['id']} readusa la vehiculele initiale.");
    }
    $db->commit();
    @unlink($stateFile);
    exit(0);
}

// -----------------------------------------------------------------------------
// --create
$admin = $one("SELECT id FROM utilizatori WHERE rol = 'admin' AND status = 'activ' ORDER BY id LIMIT 1");
$requester = $one("SELECT id FROM utilizatori WHERE status = 'activ' AND rol <> 'admin' ORDER BY RAND() LIMIT 1") ?? $admin;
$requesterId = (int) $requester['id'];
$vehicle = static fn() => $one("SELECT id, nr_inmatriculare FROM vehicule WHERE nr_inmatriculare IS NOT NULL AND nr_inmatriculare <> '' ORDER BY RAND() LIMIT 1");
$created = [];

foreach ($only !== null ? [$only] : TYPES as $type) {
    switch ($type) {
        case 'vehicle':
            $v = $vehicle();
            $situations = [
                ['expired_documents', 'Documente expirate', [['document_type' => 'ITP', 'document_name' => 'ITP', 'document_status' => 'expired', 'expiry_date' => date('Y-m-d', strtotime('-' . random_int(2, 20) . ' days'))]]],
                ['missing_documents', 'Documente lipsa', [['document_type' => 'RCA', 'document_name' => 'RCA', 'document_status' => 'missing', 'expiry_date' => null]]],
                ['manual_inactive', 'Dezactivat manual / Inactiv', []],
            ];
            [$key, $label, $documents] = $situations[array_rand($situations)];
            $row = $approvals->createPendingForRequesterResource([
                'resource_type' => 'vehicle', 'resource_id' => (int) $v['id'], 'resource_label' => TEST_PREFIX . $v['nr_inmatriculare'],
                'is_inactive' => true, 'reason_key' => $key, 'reason_label' => $label, 'usage_context' => 'Dispecer curse', 'documents' => $documents,
            ], $requesterId);
            $created[] = [(int) ($row['id'] ?? 0), "Vehicul {$v['nr_inmatriculare']} — $label"];
            break;

        case 'driver':
            $d = $one("SELECT id, nume FROM soferi WHERE status = 'activ' ORDER BY RAND() LIMIT 1");
            $start = date('Y-m-d', strtotime('-' . random_int(0, 3) . ' days'));
            $situations = [
                ['medical_leave', 'Concediu medical', ['start_date' => $start, 'end_date' => date('Y-m-d', strtotime($start . ' +7 days')), 'detail' => 'Certificat medical']],
                ['leave', 'Concediu', ['start_date' => $start, 'end_date' => date('Y-m-d', strtotime($start . ' +5 days')), 'detail' => 'Concediu de odihna']],
                ['expired_documents', 'Documente expirate', null],
            ];
            [$key, $label, $leave] = $situations[array_rand($situations)];
            $resource = [
                'resource_type' => 'driver', 'resource_id' => (int) $d['id'], 'resource_label' => TEST_PREFIX . $d['nume'],
                'is_inactive' => true, 'reason_key' => $key, 'reason_label' => $label, 'usage_context' => 'Dispecer curse',
                'documents' => $leave === null ? [['document_type' => 'Permis', 'document_name' => 'Permis de conducere', 'document_status' => 'expired', 'expiry_date' => date('Y-m-d', strtotime('-5 days'))]] : [],
            ];
            if ($leave !== null) {
                $resource['leave'] = $leave;
            }
            $row = $approvals->createPendingForRequesterResource($resource, $requesterId);
            $created[] = [(int) ($row['id'] ?? 0), "Sofer {$d['nume']} — $label"];
            break;

        case 'repair':
            $v = $vehicle();
            $row = $approvals->createPendingForRequesterResource([
                'resource_type' => 'vehicle', 'resource_id' => (int) $v['id'], 'resource_label' => TEST_PREFIX . $v['nr_inmatriculare'],
                'is_inactive' => true, 'reason_key' => 'repair', 'reason_label' => 'In reparatie', 'usage_context' => 'Dispecer curse', 'documents' => [],
                'repair' => ['status' => 'In lucru', 'start_date' => date('Y-m-d', strtotime('-' . random_int(1, 4) . ' days')),
                    'supplier' => ['Service TIR Expert', 'Auto Piese Camion', 'Truck Center Ilfov'][random_int(0, 2)],
                    'detail' => ['Schimb ambreiaj', 'Reparatie frane', 'Revizie motor', 'Inlocuire turbina'][random_int(0, 3)]],
            ], $requesterId);
            $created[] = [(int) ($row['id'] ?? 0), "Reparatie {$v['nr_inmatriculare']}"];
            break;

        case 'diurna':
            $trip = $one("SELECT c.id, c.data_inceput, c.data_sfarsit, v.nr_inmatriculare, s.nume FROM curse_dispecer c
                LEFT JOIN vehicule v ON v.id = c.vehicle_id LEFT JOIN soferi s ON s.id = c.driver_id
                WHERE c.deleted_at IS NULL AND c.driver_id IS NOT NULL ORDER BY RAND() LIMIT 1");
            $calc = random_int(1, 4);
            $id = $approvals->createDiurnaRequest([
                'trip_id' => (int) $trip['id'], 'calculat' => $calc, 'curent' => $calc, 'solicitat' => $calc + random_int(1, 2),
                'motiv' => ['Cursa prelungita peste noapte', 'Asteptare la descarcare', 'Intarziere la vama'][random_int(0, 2)],
                'sofer' => TEST_PREFIX . (string) $trip['nume'], 'vehicul' => (string) $trip['nr_inmatriculare'],
                'interval' => date('d.m', strtotime((string) $trip['data_inceput'])) . ' - ' . date('d.m', strtotime((string) $trip['data_sfarsit'])),
            ], $requesterId, false);
            // Eticheta de test (cleanup-ul sterge doar etichetele cu prefixul).
            $db->prepare('UPDATE inactive_resource_approvals SET resource_label = CONCAT(:p, resource_label) WHERE id = :id')->execute([':p' => TEST_PREFIX, ':id' => $id]);
            $created[] = [$id, "Diurna cursa #{$trip['id']} ({$trip['nume']})"];
            break;

        case 'route_config':
            $rule = $one("SELECT id, beneficiar_id, loc_incarcare_id, zona_distributie_id, vehicle_ids FROM configurare_rute_distributie
                WHERE beneficiar_id IS NOT NULL AND loc_incarcare_id IS NOT NULL AND zona_distributie_id IS NOT NULL ORDER BY RAND() LIMIT 1");
            if ($rule === null) {
                $log('Rute: nicio regula de distributie, scenariu sarit.');
                break;
            }
            $inRule = array_filter(array_map('intval', explode(',', (string) $rule['vehicle_ids'])));
            $v = $one('SELECT id, nr_inmatriculare FROM vehicule WHERE id NOT IN (' . (implode(',', $inRule) ?: '0') . ") AND nr_inmatriculare <> '' ORDER BY RAND() LIMIT 1");
            $trip = $one('SELECT id FROM curse_dispecer WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
            $names = $one('SELECT b.nume AS beneficiar, l.nume AS loc, z.nume AS zona FROM configurare_beneficiari_transport b
                LEFT JOIN configurare_locuri_incarcare l ON l.id = :l LEFT JOIN configurare_zone_distributie z ON z.id = :z WHERE b.id = :b',
                [':b' => $rule['beneficiar_id'], ':l' => $rule['loc_incarcare_id'], ':z' => $rule['zona_distributie_id']]) ?? [];
            $id = $approvals->createRouteConfigRequest([
                'vehicle_id' => (int) $v['id'], 'beneficiar_id' => (int) $rule['beneficiar_id'], 'tip_transport' => 'distributie',
                'loc_incarcare_id' => (int) $rule['loc_incarcare_id'], 'zona_distributie_id' => (int) $rule['zona_distributie_id'],
            ], (int) $trip['id'], $requesterId, TEST_PREFIX . $v['nr_inmatriculare'],
                trim(($names['loc'] ?? 'Loc incarcare') . ' → ' . ($names['zona'] ?? 'Zona')), (string) ($names['beneficiar'] ?? 'Beneficiar'));
            $db->prepare("UPDATE inactive_resource_approvals SET resource_label = CONCAT(:p, resource_label) WHERE id = :id AND resource_label NOT LIKE :like")
                ->execute([':p' => TEST_PREFIX, ':id' => $id, ':like' => TEST_PREFIX . '%']);
            // Regula, asa cum era: --cleanup o reface daca ruta e aprobata.
            $state['route_rules'][] = ['table' => 'configurare_rute_distributie', 'id' => (int) $rule['id'], 'vehicle_ids' => (string) $rule['vehicle_ids']];
            $created[] = [$id, "Ruta: {$v['nr_inmatriculare']} → " . ($names['beneficiar'] ?? '')];
            break;
    }
}

foreach ($created as [$id, $what]) {
    if ($id > 0) {
        $state['approval_ids'][] = $id;
        $log("Creata solicitarea #$id: $what");
    }
}
$state['approval_ids'] = array_values(array_unique($state['approval_ids']));
$saveState();
$log('Gata. BaileyBot le trimite pe WhatsApp in cateva secunde (APPROVAL_WA_ENABLED=1, APPROVAL_WA_USER_IDS).');
