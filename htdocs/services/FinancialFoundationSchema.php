<?php
declare(strict_types=1);

/**
 * Aplica migrarea database/migrations/2026_11_18_000001_fundatie_financiara.sql (sursa unica a schemei),
 * instructiune cu instructiune si doar ce lipseste (verificat in information_schema / financiar_migrari).
 *
 * - DDL face COMMIT implicit in MySQL: ensure() NU ruleaza nimic intr-o tranzactie deschisa.
 * - Dupa o trecere completa se marcheaza `schema_v1_complet`; cererile urmatoare fac o singura interogare.
 * - Instructiunile marcate „-- @once:<cheie>” (completari de date) ruleaza o singura data.
 */
final class FinancialFoundationSchema
{
    public const MIGRATION = '2026_11_18_000001_fundatie_financiara.sql';
    private const DONE_KEY = 'schema_v3_complet';

    private static ?bool $ready = null;

    public static function migrationPath(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations/' . self::MIGRATION;
    }

    /** True cand toata schema financiara exista (fara DDL). */
    public static function isReady(PDO $db): bool
    {
        if (self::$ready === true) {
            return true;
        }
        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM financiar_migrari WHERE cheie = :k');
            $stmt->execute([':k' => self::DONE_KEY]);
            self::$ready = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            self::$ready = false;
        }
        return self::$ready;
    }

    /**
     * @return array{applied:list<string>, skipped:int, pending:list<string>, complete:bool}
     */
    public static function ensure(PDO $db, bool $verbose = false): array
    {
        $report = ['applied' => [], 'skipped' => 0, 'pending' => [], 'complete' => false];
        if (self::isReady($db)) {
            $report['complete'] = true;
            return $report;
        }
        if ($db->inTransaction()) {
            return $report; // DDL ar comite tranzactia apelantului; revenim la urmatoarea cerere
        }

        foreach (self::statements() as [$sql, $onceKey]) {
            $label = self::label($sql, $onceKey);
            $state = self::state($db, $sql, $onceKey);
            if ($state === 'done') {
                $report['skipped']++;
                continue;
            }
            if ($state === 'blocked') {
                $report['pending'][] = $label;
                continue;
            }
            $rows = $db->exec($sql);
            if ($onceKey !== null) {
                $db->prepare('INSERT INTO financiar_migrari (cheie, aplicat_la, randuri) VALUES (:k, NOW(), :r)')
                    ->execute([':k' => $onceKey, ':r' => (int) $rows]);
            }
            $report['applied'][] = $label . ($onceKey !== null ? ' (' . (int) $rows . ' randuri)' : '');
            if ($verbose) {
                echo '  + ' . end($report['applied']) . "\n";
            }
        }

        if ($report['pending'] === []) {
            $db->prepare('INSERT IGNORE INTO financiar_migrari (cheie, aplicat_la, randuri) VALUES (:k, NOW(), 0)')
                ->execute([':k' => self::DONE_KEY]);
            self::$ready = true;
            $report['complete'] = true;
        }

        return $report;
    }

    /** Doar pentru teste / scriptul de migrare: uita starea memorata. */
    public static function resetCache(): void
    {
        self::$ready = null;
    }

    /** @return list<array{0:string,1:?string}> [sql, cheie @once] */
    public static function statements(): array
    {
        $raw = (string) file_get_contents(self::migrationPath());
        $statements = [];
        $buffer = '';
        $onceKey = null;
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $trimmed = trim($line);
            if (preg_match('/^--\s*@once:([a-z0-9_]+)/i', $trimmed, $match)) {
                $onceKey = $match[1];
                continue;
            }
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with($trimmed, ';')) {
                $statements[] = [rtrim(trim($buffer), ';'), $onceKey];
                $buffer = '';
                $onceKey = null;
            }
        }
        return $statements;
    }

    /** 'todo' | 'done' | 'blocked' (tabela tinta lipseste; o creeaza alt modul) */
    private static function state(PDO $db, string $sql, ?string $onceKey): string
    {
        if ($onceKey !== null) {
            if (!self::tableExists($db, 'financiar_migrari')) {
                return 'blocked';
            }
            $stmt = $db->prepare('SELECT COUNT(*) FROM financiar_migrari WHERE cheie = :k');
            $stmt->execute([':k' => $onceKey]);
            return (int) $stmt->fetchColumn() > 0 ? 'done' : 'todo';
        }
        if (preg_match('/^CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', $sql, $m)) {
            return self::tableExists($db, $m[1]) ? 'done' : 'todo';
        }
        if (preg_match('/^ALTER TABLE\s+`?(\w+)`?\s+ADD\s+(.*)$/is', $sql, $m)) {
            $table = $m[1];
            if (!self::tableExists($db, $table)) {
                return 'blocked';
            }
            $rest = ltrim($m[2]);
            if (preg_match('/^COLUMN\s+`?(\w+)`?/i', $rest, $c)) {
                return self::columnExists($db, $table, $c[1]) ? 'done' : 'todo';
            }
            if (preg_match('/^CONSTRAINT\s+`?(\w+)`?/i', $rest, $c)) {
                return self::constraintExists($db, $table, $c[1]) ? 'done' : 'todo';
            }
            if (preg_match('/^(?:UNIQUE\s+)?(?:INDEX|KEY)\s+`?(\w+)`?/i', $rest, $c)) {
                return self::indexExists($db, $table, $c[1]) ? 'done' : 'todo';
            }
        }
        throw new RuntimeException('Instructiune nesuportata in migrarea financiara: ' . mb_substr($sql, 0, 120));
    }

    private static function label(string $sql, ?string $onceKey): string
    {
        if ($onceKey !== null) {
            return '@once ' . $onceKey;
        }
        return (string) preg_replace('/\s+/', ' ', mb_substr($sql, 0, 110));
    }

    public static function tableExists(PDO $db, string $table): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
        $stmt->execute([':t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function columnExists(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $stmt->execute([':t' => $table, ':c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function indexExists(PDO $db, string $table, string $index): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i');
        $stmt->execute([':t' => $table, ':i' => $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function constraintExists(PDO $db, string $table, string $constraint): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND CONSTRAINT_NAME = :c');
        $stmt->execute([':t' => $table, ':c' => $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
