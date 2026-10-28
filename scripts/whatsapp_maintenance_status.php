<?php
declare(strict_types=1);

/**
 * Starea programarilor service pe WhatsApp, pentru testul de pe telefon (doar citire).
 *
 *   php scripts/whatsapp_maintenance_status.php
 *
 * Arata ultimele mesaje primite / trimise, conversatiile in curs si interventiile cerute din WhatsApp.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';

$db = get_pdo();
$short = static fn(?string $text, int $max = 70): string => mb_strimwidth(str_replace("\n", ' | ', (string) $text), 0, $max, '…', 'UTF-8');
$table = static function (string $title, array $rows): void {
    echo "\n== $title\n";
    if ($rows === []) {
        echo "   (nimic)\n";
        return;
    }
    foreach ($rows as $row) {
        echo '   ' . implode('  ', array_map(static fn($k, $v): string => $k . '=' . $v, array_keys($row), $row)) . "\n";
    }
};
$hasTable = static fn(string $name): bool => $db->query("SHOW TABLES LIKE " . $db->quote($name))->fetchColumn() !== false;

echo 'Baza: ' . $db->query('SELECT DATABASE()')->fetchColumn() . '   ' . date('Y-m-d H:i:s') . "\n";
echo 'MAINT_WA_ENABLED=' . (getenv('MAINT_WA_ENABLED') ?: '0') . '  MAINT_WA_USER_IDS=' . (getenv('MAINT_WA_USER_IDS') ?: '') . "\n";

$table('Mesaje primite (ultimele 8)', array_map(static fn(array $r): array => [
    'id' => $r['id'], 'la' => substr($r['created_at'], 11), 'tip' => $r['kind'], 'buton' => $r['action_id'] ?? '-', 'text' => $short($r['text_content'], 40), 'rezultat' => $r['outcome'],
], $db->query('SELECT * FROM whatsapp_inbound_messages ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC)));

$table('Mesaje trimise (ultimele 8)', array_map(static fn(array $r): array => [
    'id' => $r['id'], 'la' => substr($r['created_at'], 11), 'scop' => $r['purpose'], 'status' => $r['status'] . ($r['last_error'] ? ' (' . $r['last_error'] . ')' : ''), 'text' => $short($r['body']),
], $db->query('SELECT * FROM whatsapp_outbox ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC)));

if ($hasTable('whatsapp_maintenance_sessions')) {
    $table('Conversatii service in curs', array_map(static fn(array $r): array => [
        'rol' => $r['role'], 'id' => $r['actor_id'], 'flux' => $r['flow'], 'pas' => $r['step'], 'expira' => substr($r['expires_at'], 11),
    ], $db->query('SELECT * FROM whatsapp_maintenance_sessions')->fetchAll(PDO::FETCH_ASSOC)));
}
if ($hasTable('whatsapp_maintenance_requests')) {
    $table('Interventii cerute din WhatsApp', array_map(static fn(array $r): array => [
        '#' => $r['id'], 'vehicul' => $r['nr_inmatriculare'], 'status' => $r['status_interventie'], 'data' => $r['data_programata'] . ($r['ora_programata'] ? ' ' . substr($r['ora_programata'], 0, 5) : ''),
        'responsabil' => $r['responsabil_user_id'] ?? '-', 'lucrari' => $r['lucrari'], 'descriere' => $short($r['descriere'], 40),
    ], $db->query('
        SELECT i.*, v.nr_inmatriculare, (SELECT COUNT(*) FROM mentenanta_interventii_lucrari l WHERE l.intervention_id = i.id) AS lucrari
        FROM whatsapp_maintenance_requests r
        JOIN mentenanta_interventii_programate i ON i.id = r.intervention_id
        JOIN vehicule v ON v.id = i.vehicle_id
        ORDER BY r.intervention_id DESC LIMIT 8
    ')->fetchAll(PDO::FETCH_ASSOC)));
}
echo "\n";
