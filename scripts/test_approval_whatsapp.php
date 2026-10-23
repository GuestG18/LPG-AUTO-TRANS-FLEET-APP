<?php
declare(strict_types=1);

/**
 * Teste pentru aprobarile pe WhatsApp (ApprovalWhatsAppService), fara WhatsApp si fara email.
 *
 *   DB_NAME=fleet_wa_test php scripts/test_approval_whatsapp.php   (recomandat: copie de test)
 *
 * Partea cu baza de date ruleaza intr-o tranzactie anulata la final (ROLLBACK). Aplicarea rutelor
 * dupa aprobare e inlocuita in test cu un contor (aplicarea reala are propriul test, test_approval_live.php).
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
require_once $root . '/htdocs/services/ApprovalWhatsAppService.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "\033[32m  PASS\033[0m  $name\n";
    } else {
        $failed++;
        echo "\033[31m  FAIL\033[0m  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n";
    }
}

echo "\nDecizia din mesaj\n";
$P = ApprovalWhatsAppService::class;
check('buton aprobare', $P::parseDecision('approval:123:approve', 'x') === ['approval_id' => 123, 'decision' => 'approve']);
check('buton respingere', $P::parseDecision('approval:7:reject', null) === ['approval_id' => 7, 'decision' => 'reject']);
check('"APROB 45"', $P::parseDecision(null, 'APROB 45') === ['approval_id' => 45, 'decision' => 'approve']);
check('"resping #45"', $P::parseDecision(null, ' resping #45 ') === ['approval_id' => 45, 'decision' => 'reject']);
check('"Respins 9"', $P::parseDecision(null, 'Respins 9') === ['approval_id' => 9, 'decision' => 'reject']);
check('"1" (raspuns sofer) nu e aprobare', $P::parseDecision(null, '1') === null);
check('buton sofer nu e aprobare', $P::parseDecision('driver_app_confirmed', 'Am deschis') === null);
check('"aprob" fara numar nu e aprobare', $P::parseDecision(null, 'aprob') === null);
check('optiunile: Aprob / Resping cu ID-ul cererii', array_column($P::options(5), 'id') === ['approval:5:approve', 'approval:5:reject']);

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$outbox = new DriverWhatsAppModel($db);
$approvals = new InactiveResourceApprovalModel($db);
$admin = (int) $db->query("SELECT id FROM utilizatori WHERE rol = 'admin' AND status = 'activ' AND telefon <> '' ORDER BY id LIMIT 1")->fetchColumn();
$nonAdmin = (int) $db->query("SELECT id FROM utilizatori WHERE rol <> 'admin' AND status = 'activ' AND telefon <> '' ORDER BY id LIMIT 1")->fetchColumn();
$config = ['enabled' => true, 'user_ids' => [$admin, $nonAdmin], 'lookback_days' => 3];
$applied = 0;
$service = new ApprovalWhatsAppService($db, $outbox, $approvals, $config, function () use (&$applied): void {
    $applied++;
});
$service->ensureSchema();
echo "\nBaza: " . $db->query('SELECT DATABASE()')->fetchColumn() . "\n";

$reviewers = $service->reviewers();
$adminPhone = $reviewers[0]['phone'] ?? null;
check('aprobator: doar administratorul (dreptul inactive_approvals.review), nu si utilizatorul obisnuit',
    array_column($reviewers, 'id') === [$admin], json_encode(array_column($reviewers, 'id')));
$counts = static fn(): array => [
    (int) $db->query('SELECT COUNT(*) FROM inactive_resource_approvals')->fetchColumn(),
    (int) $db->query('SELECT COUNT(*) FROM whatsapp_outbox')->fetchColumn(),
    (int) $db->query('SELECT COUNT(*) FROM approval_whatsapp_messages')->fetchColumn(),
];
$before = $counts();

$db->beginTransaction();
try {
    // Doar cererile create aici sunt "noi" (ziua de azi); cele vechi raman in afara ferestrei.
    $db->exec("UPDATE inactive_resource_approvals SET requested_at = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE status = 'pending'");
    $vehicle = $db->query("SELECT id, nr_inmatriculare FROM vehicule ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $driver = $db->query("SELECT id, nume FROM soferi WHERE status = 'activ' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $requester = $nonAdmin ?: $admin;
    $a1 = (int) $approvals->createPendingForRequesterResource([
        'resource_type' => 'vehicle', 'resource_id' => (int) $vehicle['id'], 'resource_label' => '[TEST] ' . $vehicle['nr_inmatriculare'],
        'is_inactive' => true, 'reason_key' => 'expired_documents', 'reason_label' => 'Documente expirate', 'usage_context' => 'Dispecer curse',
        'documents' => [['document_type' => 'ITP', 'document_name' => 'ITP', 'document_status' => 'expired', 'expiry_date' => date('Y-m-d', strtotime('-3 days'))]],
    ], $requester)['id'];
    $a2 = (int) $approvals->createPendingForRequesterResource([
        'resource_type' => 'driver', 'resource_id' => (int) $driver['id'], 'resource_label' => '[TEST] ' . $driver['nume'],
        'is_inactive' => true, 'reason_key' => 'medical_leave', 'reason_label' => 'Concediu medical', 'usage_context' => 'Dispecer curse', 'documents' => [],
        'leave' => ['start_date' => date('Y-m-d'), 'end_date' => date('Y-m-d', strtotime('+5 days')), 'detail' => 'Certificat medical'],
    ], $requester)['id'];

    echo "\nTrimitere\n";
    $queued = $service->enqueuePending();
    check('doua cereri noi -> doua mesaje (o data fiecare, doar administratorului)', $queued === 2, (string) $queued);
    check('a doua rulare nu mai trimite nimic', $service->enqueuePending() === 0);
    $row = $db->query("SELECT * FROM whatsapp_outbox WHERE related_type = 'approval' AND related_id = $a1")->fetch(PDO::FETCH_ASSOC);
    check('mesajul e pentru utilizator (nu sofer), cu butoanele cererii', (int) $row['user_id'] === $admin && $row['driver_id'] === null
        && array_column(json_decode((string) $row['options_json'], true), 'id') === ["approval:$a1:approve", "approval:$a1:reject"]);
    check('titluri butoane: ✅ Aprob / ❌ Resping', array_column(json_decode((string) $row['options_json'], true), 'title') === ['✅ Aprob', '❌ Resping']);
    $body = (string) $row['body'];
    check('textul: numarul, tipul, eticheta, motivul si detaliile din aplicatie', str_contains($body, "*Solicitare de aprobare #$a1*")
        && str_contains($body, '[TEST] ' . $vehicle['nr_inmatriculare']) && str_contains($body, 'Documente expirate') && str_contains($body, '• '), $body);
    $claim = $outbox->claimOutbox(20);
    $mine = array_values(array_filter($claim['messages'], static fn(array $m): bool => $m['id'] === (int) $row['id']));
    check('botul primeste telefonul administratorului (din utilizatori)', ($mine[0]['phone'] ?? null) === $adminPhone && ($mine[0]['user_id'] ?? null) === $admin);

    echo "\nDecizia pe WhatsApp\n";
    $msg = static fn(string $wa, ?string $action, ?string $text, ?string $phone = null): array => ['wa_message_id' => $wa, 'phone' => $phone ?? $adminPhone, 'kind' => $action ? 'button' : 'text', 'action_id' => $action, 'text' => $text];
    $replyFor = static fn(string $wa): ?string => ($v = $db->query('SELECT body FROM whatsapp_outbox WHERE dedupe_key = ' . $db->quote('reply:' . $wa))->fetchColumn()) === false ? null : (string) $v;

    $r = $service->handleInbound($msg('AP-1', "approval:$a1:approve", '✅ Aprob'));
    $a = $approvals->getById($a1);
    check('buton Aprob -> cererea aprobata de administrator, cu nota', $r['outcome'] === 'approved' && $a['status'] === 'approved'
        && (int) $a['reviewed_by_user_id'] === $admin && str_contains((string) $a['review_note'], 'prin WhatsApp'), json_encode($r) . ' ' . ($a['review_note'] ?? ''));
    check('dupa aprobare se aplica rutele aprobate (ca pagina web)', $applied === 1);
    check('raspuns catre administrator: aprobata', str_starts_with((string) $replyFor('AP-1'), "✅ Solicitarea #$a1 a fost aprobată."));
    check('acelasi mesaj primit din nou -> duplicat, fara a doua decizie', $service->handleInbound($msg('AP-1', "approval:$a1:approve", 'x'))['outcome'] === 'duplicate' && $applied === 1);
    $r = $service->handleInbound($msg('AP-2', "approval:$a1:reject", '❌ Resping'));
    check('Resping pe o cerere deja aprobata -> "nu mai este in asteptare", ramane aprobata', $r['outcome'] === 'already_decided'
        && $approvals->getById($a1)['status'] === 'approved' && str_contains((string) $replyFor('AP-2'), 'nu mai este în așteptare'), (string) $replyFor('AP-2'));
    $r = $service->handleInbound($msg('AP-3', null, "RESPING $a2"));
    check('"RESPING <nr>" scris -> respinsa', $r['outcome'] === 'rejected' && $approvals->getById($a2)['status'] === 'rejected' && $applied === 1);
    check('nota de respingere', str_contains((string) $approvals->getById($a2)['review_note'], 'Respins prin WhatsApp'));
    $r = $service->handleInbound($msg('AP-4', 'approval:99999999:approve', 'x'));
    check('cerere inexistenta -> raspuns, nimic modificat', $r['outcome'] === 'approval_not_found');

    $a3 = (int) $approvals->createPendingForRequesterResource([
        'resource_type' => 'vehicle', 'resource_id' => (int) $vehicle['id'] + 1, 'resource_label' => '[TEST] reparatie',
        'is_inactive' => true, 'reason_key' => 'repair', 'reason_label' => 'In reparatie', 'usage_context' => 'Dispecer curse', 'documents' => [],
        'repair' => ['status' => 'In lucru', 'start_date' => date('Y-m-d'), 'supplier' => 'Service test', 'detail' => 'Frane'],
    ], $requester)['id'];
    check('reparatia e de tip "repair" (tab-ul Reparatii)', $approvals->getById($a3)['resource_type'] === 'repair');
    check('o cerere noua aparuta mai tarziu -> trimisa la urmatoarea citire', $service->enqueuePending() === 1);
    $driverPhone = NotificationRuleModel::normalizePhone((string) $db->query("SELECT s.telefon FROM soferi s WHERE s.status = 'activ' AND s.telefon <> '' AND s.telefon NOT IN (SELECT telefon FROM utilizatori) LIMIT 1")->fetchColumn());
    $r = $service->handleInbound($msg('AP-5', "approval:$a3:approve", 'x', $driverPhone));
    check('un numar care nu e aprobator nu poate decide', $r['outcome'] === 'not_a_reviewer' && $approvals->getById($a3)['status'] === 'pending');
    $nonAdminPhone = NotificationRuleModel::normalizePhone((string) $db->query("SELECT telefon FROM utilizatori WHERE id = $nonAdmin")->fetchColumn());
    if ($nonAdminPhone !== null && $nonAdminPhone !== $adminPhone) {
        $r = $service->handleInbound($msg('AP-6', "approval:$a3:approve", 'x', $nonAdminPhone));
        check('utilizator fara dreptul de aprobare (chiar daca e in lista) -> refuzat', $r['outcome'] === 'not_a_reviewer' && $approvals->getById($a3)['status'] === 'pending');
    }

    $off = new ApprovalWhatsAppService($db, $outbox, $approvals, ['enabled' => false] + $config, fn() => null);
    check('dezactivat -> nu trimite nimic', $off->enqueuePending() === 0);
    check('dezactivat -> nu decide', $off->handleInbound($msg('AP-7', "approval:$a3:approve", 'x'))['outcome'] === 'approvals_disabled' && $approvals->getById($a3)['status'] === 'pending');
    $none = new ApprovalWhatsAppService($db, $outbox, $approvals, ['user_ids' => []] + $config, fn() => null);
    check('lista de aprobatori goala -> nimeni', $none->reviewers() === [] && $none->enqueuePending() === 0);

    $ids = implode(',', [$a1, $a2, $a3]);
    check('fiecare mesaj trimis e legat de cererea lui', (int) $db->query("SELECT COUNT(*) FROM approval_whatsapp_messages WHERE approval_id IN ($ids)")->fetchColumn() === 3);
    check('decizia e salvata pe mesajul WhatsApp', $db->query("SELECT CONCAT(status, '/', decision) FROM approval_whatsapp_messages WHERE approval_id = $a1")->fetchColumn() === 'decided/approved');
} finally {
    $db->rollBack();
}

check('ROLLBACK: nicio linie ramasa', $counts() === $before, json_encode([$before, $counts()]));
echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m") . "$passed trecute, $failed esuate\033[0m\n";
exit($failed === 0 ? 0 : 1);
