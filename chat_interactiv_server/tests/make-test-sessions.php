<?php
declare(strict_types=1);

/**
 * DOAR PENTRU TESTARE LOCALĂ: creează două sesiuni PHP pentru două conturi REALE (fără codul 2FA
 * pe email), ca testul de acceptanță să ruleze cu două identități distincte.
 *
 *   php make-test-sessions.php <user_id_A> <user_id_B> [director_sesiuni]
 *
 * Afișează JSON {"a": "<sid>", "b": "<sid>"}. Refuză să ruleze dacă APP_ENV=production.
 * Sesiunile se șterg cu: php make-test-sessions.php --clean [director_sesiuni]
 */
$root = dirname(__DIR__, 2);
$env = [];
foreach (@file($root . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
        $env[$m[1]] = trim($m[2], " \t\"'");
    }
}
if (strtolower($env['APP_ENV'] ?? 'development') === 'production') {
    fwrite(STDERR, "Refuzat: APP_ENV=production.\n");
    exit(1);
}
$dir = (($argv[1] ?? '') === '--clean' ? ($argv[2] ?? null) : ($argv[3] ?? null)) ?? (ini_get('session.save_path') ?: 'C:/laragon/tmp');
$dir = rtrim(preg_replace('/^\d+;/', '', $dir), '/\\');
if (($argv[1] ?? '') === '--clean') {
    foreach (glob($dir . '/sess_lpgconnecttest*') ?: [] as $f) {
        unlink($f);
    }
    echo "ok\n";
    exit(0);
}
$ids = [(int) ($argv[1] ?? 0), (int) ($argv[2] ?? 0)];
if ($ids[0] <= 0 || $ids[1] <= 0 || $ids[0] === $ids[1]) {
    fwrite(STDERR, "Folosire: php make-test-sessions.php <user_id_A> <user_id_B>\n");
    exit(1);
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_NAME'] ?? ''),
    $env['DB_USER'] ?? 'root',
    $env['DB_PASS'] ?? ''
);
$out = [];
foreach (['a' => $ids[0], 'b' => $ids[1]] as $k => $uid) {
    $stmt = $pdo->prepare("SELECT id, nume, email, rol FROM utilizatori WHERE id = ? AND status = 'activ'");
    $stmt->execute([$uid]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        fwrite(STDERR, "Utilizatorul $uid nu există sau e inactiv.\n");
        exit(1);
    }
    $sid = 'lpgconnecttest' . $k . bin2hex(random_bytes(9));
    $data = 'session_initialized|i:' . time() . ';auth_user|' . serialize(['id' => (int) $u['id'], 'nume' => $u['nume'], 'email' => $u['email'], 'rol' => $u['rol']]);
    file_put_contents($dir . '/sess_' . $sid, $data);
    $out[$k] = $sid;
    $out[$k . '_user'] = $u['nume'] . ' (' . $u['rol'] . ')';
}
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
