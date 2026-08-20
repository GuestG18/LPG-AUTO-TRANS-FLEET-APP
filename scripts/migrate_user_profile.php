<?php
declare(strict_types=1);

/**
 * Migration runner for the "Profilul meu" personalization feature.
 *
 *   php scripts/migrate_user_profile.php [--dry-run]
 *
 * Idempotent and additive. Safe to run repeatedly and safe for production.
 *
 * WHAT IT DOES
 *   1. Adds avatar_type / avatar_value / avatar_color to `utilizatori`.
 *   2. Adds profile_status (presence) + status_message to `utilizatori`.
 *   3. Creates htdocs/uploads/avatare/ with a hardening .htaccess.
 *
 * WHAT IT NEVER DOES
 *   - touch `utilizatori.status` (the security/authorization enum)
 *   - drop or alter an existing column
 *   - modify any user row
 *   - recreate the users table
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/htdocs/config/config.php';
require_once $root . '/htdocs/config/database.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$log = static fn (string $m): int => print('[' . date('H:i:s') . '] ' . $m . PHP_EOL);

$columnExists = static function (PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int) $stmt->fetchColumn() > 0;
};

$log('Migrare profil utilizator — start' . ($dryRun ? ' (DRY RUN)' : ''));

$columns = [
    'avatar_type' => "ALTER TABLE utilizatori ADD COLUMN avatar_type ENUM('none','image','emoji') NOT NULL DEFAULT 'none' AFTER telefon",
    'avatar_value' => 'ALTER TABLE utilizatori ADD COLUMN avatar_value VARCHAR(255) NULL AFTER avatar_type',
    'avatar_color' => 'ALTER TABLE utilizatori ADD COLUMN avatar_color VARCHAR(20) NULL AFTER avatar_value',
    'profile_status' => "ALTER TABLE utilizatori ADD COLUMN profile_status ENUM('activ','ocupat','indisponibil') NOT NULL DEFAULT 'activ' AFTER avatar_color",
    'status_message' => 'ALTER TABLE utilizatori ADD COLUMN status_message VARCHAR(255) NULL AFTER profile_status',
    // Emoji-ul este un BADGE independent, afisat peste poza (nu o inlocuieste).
    'avatar_emoji' => 'ALTER TABLE utilizatori ADD COLUMN avatar_emoji VARCHAR(16) NULL AFTER avatar_value',
];

foreach ($columns as $column => $sql) {
    if ($columnExists($db, 'utilizatori', $column)) {
        $log("  = utilizatori.{$column} exista deja");
        continue;
    }
    if ($dryRun) {
        $log("  + [dry-run] as adauga utilizatori.{$column}");
        continue;
    }
    $db->exec($sql);
    $log("  + utilizatori.{$column} adaugat");
}

// ---------------------------------------------------------------------
// Data migration: emoji stored as an avatar REPLACEMENT becomes a badge.
// Idempotent — after the first pass no row matches any more.
// ---------------------------------------------------------------------
if ($columnExists($db, 'utilizatori', 'avatar_emoji')) {
    if ($dryRun) {
        $stmt = $db->query("SELECT COUNT(*) FROM utilizatori WHERE avatar_type = 'emoji' AND avatar_value IS NOT NULL");
        $log('  ~ [dry-run] as muta ' . (int) $stmt->fetchColumn() . ' avatare emoji catre badge');
    } else {
        $moved = $db->exec("
            UPDATE utilizatori
            SET avatar_emoji = avatar_value,
                avatar_type = 'none',
                avatar_value = NULL
            WHERE avatar_type = 'emoji' AND avatar_value IS NOT NULL
        ");
        $log("  ~ avatare emoji convertite in badge: {$moved}");
    }
}

// ---------------------------------------------------------------------
// Upload directory for avatars
// ---------------------------------------------------------------------
$avatarDir = $root . '/htdocs/uploads/avatare';
if (!is_dir($avatarDir)) {
    if ($dryRun) {
        $log('  + [dry-run] as crea ' . $avatarDir);
    } elseif (mkdir($avatarDir, 0775, true) || is_dir($avatarDir)) {
        $log('  + director creat: uploads/avatare');
    } else {
        $log('  ! nu s-a putut crea uploads/avatare — creeaza-l manual');
    }
} else {
    $log('  = uploads/avatare exista deja');
}

// Defence in depth: the parent uploads/.htaccess already denies PHP, but the
// avatar folder gets its own copy so it stays hardened if moved.
$htaccess = $avatarDir . '/.htaccess';
if (is_dir($avatarDir) && !is_file($htaccess) && !$dryRun) {
    file_put_contents($htaccess, "Options -Indexes\n\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar|cgi|pl|py|sh)$\">\n    Require all denied\n</FilesMatch>\n");
    $log('  + uploads/avatare/.htaccess scris');
}

$gitkeep = $avatarDir . '/.gitkeep';
if (is_dir($avatarDir) && !is_file($gitkeep) && !$dryRun) {
    file_put_contents($gitkeep, '');
}

// ---------------------------------------------------------------------
$log('Verificare finala');
$stmt = $db->query("
    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilizatori'
      AND COLUMN_NAME IN ('avatar_type','avatar_value','avatar_emoji','avatar_color','profile_status','status_message','status')
    ORDER BY ORDINAL_POSITION
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $log(sprintf(
        '  · %-16s %-42s null=%-3s default=%s',
        $row['COLUMN_NAME'],
        $row['COLUMN_TYPE'],
        $row['IS_NULLABLE'],
        $row['COLUMN_DEFAULT'] ?? 'NULL'
    ));
}

$log('Migrare profil utilizator — gata' . ($dryRun ? ' (nimic nu a fost scris)' : ''));
exit(0);
