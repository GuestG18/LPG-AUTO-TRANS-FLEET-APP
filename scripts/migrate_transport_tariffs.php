<?php
declare(strict_types=1);

/**
 * Migration runner for the "Administrare tarife transport" module.
 *
 * Idempotent and additive. Safe to run repeatedly.
 *
 *   php scripts/migrate_transport_tariffs.php [--dry-run] [--baseline=YYYY-MM-DD]
 *
 * WHAT IT DOES
 *   1. Creates the four new tariff tables (see the .sql file).
 *   2. Adds fuel_fillups.unit_price (DECIMAL(12,4)) and fuel_fillups.source_type.
 *   3. Backfills unit_price from raw_payload.pu_alimentare (authoritative, 4 dp);
 *      falls back to total_value / quantity_liters ONLY when the API value is absent.
 *   4. Classifies existing fuel rows into api / demo / test / manual.
 *   5. Adds curse_dispecer.tariff_version_id + tariff_breakdown_json (both NULL).
 *   6. Seeds transport_tariff_versions from the CURRENT live configuration.
 *   7. Seeds default module settings.
 *
 * WHAT IT NEVER DOES
 *   - touch curse_dispecer.pret_tarifare / total_facturare / cost_km_*
 *   - delete any row, including the known test fuel rows
 *   - rewrite fuel_fillups.raw_payload
 *   - drop or alter any legacy configurare_* column
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Acest script ruleaza doar din CLI.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/htdocs/config/config.php';
require_once $projectRoot . '/htdocs/config/database.php';

/**
 * Explicit, documented sentinel for migrated tariffs.
 *
 * The reports established that the current configuration carries NO historical
 * effective date — there is simply no evidence of when any rate started.
 * Rather than invent a plausible-looking date, migrated versions start at a
 * sentinel that provably predates every recorded trip, so that any historical
 * trip date resolves to the migrated version. It is intentionally artificial.
 */
const TARIFF_MIGRATION_BASELINE = '2000-01-01';

$argvList = $argv ?? [];
$dryRun = in_array('--dry-run', $argvList, true);
$baseline = TARIFF_MIGRATION_BASELINE;
foreach ($argvList as $arg) {
    if (str_starts_with((string) $arg, '--baseline=')) {
        $candidate = substr((string) $arg, 11);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) === 1) {
            $baseline = $candidate;
        }
    }
}

$db = get_pdo();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = date('Y-m-d H:i:s');
$log = static function (string $message): void {
    echo '[' . date('H:i:s') . '] ' . $message . PHP_EOL;
};

$columnExists = static function (PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
};

$tableExists = static function (PDO $db, string $table): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
    ");
    $stmt->execute(['t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
};

$log('Migrare tarife transport — start' . ($dryRun ? ' (DRY RUN)' : ''));
$log('Baseline migrare: ' . $baseline);

// ---------------------------------------------------------------------
// STEP 1 — create the new tables
// ---------------------------------------------------------------------
$sqlFile = $projectRoot . '/database/migrations/2026_08_18_000001_transport_tariff_versioning.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "Lipseste fisierul SQL: {$sqlFile}\n");
    exit(1);
}

$sql = (string) file_get_contents($sqlFile);
// Strip comments, then split on the statement terminator.
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
$statements = array_values(array_filter(array_map('trim', explode(';', $sql)), static fn (string $s): bool => $s !== ''));

foreach ($statements as $statement) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', $statement, $m) === 1) {
        $tableName = $m[1];
        if ($tableExists($db, $tableName)) {
            $log("  = tabela {$tableName} exista deja");
            continue;
        }
        if ($dryRun) {
            $log("  + [dry-run] as crea tabela {$tableName}");
            continue;
        }
        $db->exec($statement);
        $log("  + tabela {$tableName} creata");
    }
}

// ---------------------------------------------------------------------
// STEP 2 — fuel_fillups: unit_price + source_type
// ---------------------------------------------------------------------
if (!$tableExists($db, 'fuel_fillups')) {
    $log('  ! fuel_fillups nu exista inca — se sare peste pasii de combustibil');
} else {
    if (!$columnExists($db, 'fuel_fillups', 'unit_price')) {
        if (!$dryRun) {
            $db->exec("ALTER TABLE fuel_fillups
                       ADD COLUMN unit_price DECIMAL(12,4) NULL AFTER quantity_liters");
        }
        $log('  + fuel_fillups.unit_price adaugat');
    } else {
        $log('  = fuel_fillups.unit_price exista deja');
    }

    if (!$columnExists($db, 'fuel_fillups', 'unit_price_source')) {
        if (!$dryRun) {
            $db->exec("ALTER TABLE fuel_fillups
                       ADD COLUMN unit_price_source ENUM('api','derived') NULL AFTER unit_price");
        }
        $log('  + fuel_fillups.unit_price_source adaugat');
    } else {
        $log('  = fuel_fillups.unit_price_source exista deja');
    }

    if (!$columnExists($db, 'fuel_fillups', 'source_type')) {
        if (!$dryRun) {
            $db->exec("ALTER TABLE fuel_fillups
                       ADD COLUMN source_type ENUM('api','manual','test','demo') NOT NULL DEFAULT 'api'
                       AFTER raw_payload");
            $db->exec("ALTER TABLE fuel_fillups
                       ADD INDEX idx_fuel_fillups_source_type (source_type, fuel_type, fillup_datetime)");
        }
        $log('  + fuel_fillups.source_type adaugat (+ index)');
    } else {
        $log('  = fuel_fillups.source_type exista deja');
    }

    // ---- STEP 3: backfill unit_price -----------------------------------
    if (!$dryRun) {
        // 3a. Authoritative value straight from the preserved API payload.
        $apiBackfill = $db->exec("
            UPDATE fuel_fillups
            SET unit_price = CAST(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.pu_alimentare')) AS DECIMAL(12,4)),
                unit_price_source = 'api'
            WHERE unit_price IS NULL
              AND raw_payload IS NOT NULL
              AND JSON_VALID(raw_payload)
              AND JSON_EXTRACT(raw_payload, '$.pu_alimentare') IS NOT NULL
        ");
        $log("  ~ unit_price completat din raw_payload.pu_alimentare: {$apiBackfill} randuri");

        // 3b. Fallback ONLY where the API value is genuinely unavailable.
        $derivedBackfill = $db->exec("
            UPDATE fuel_fillups
            SET unit_price = ROUND(total_value / quantity_liters, 4),
                unit_price_source = 'derived'
            WHERE unit_price IS NULL
              AND quantity_liters > 0
        ");
        $log("  ~ unit_price derivat (total/litri) acolo unde API-ul lipseste: {$derivedBackfill} randuri");
    } else {
        $log('  ~ [dry-run] backfill unit_price');
    }

    // ---- STEP 4: classify provenance -----------------------------------
    if (!$dryRun) {
        $testRows = $db->exec("
            UPDATE fuel_fillups SET source_type = 'test'
            WHERE source_type <> 'test'
              AND (
                    api_id LIKE 'test-compare-%'
                 OR api_id LIKE 'test-%'
                 OR (raw_payload IS NOT NULL AND JSON_VALID(raw_payload)
                     AND JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.source')) = 'test-compare')
              )
        ");
        $log("  ~ randuri marcate source_type='test': {$testRows}");

        $demoRows = $db->exec("
            UPDATE fuel_fillups SET source_type = 'demo'
            WHERE source_type <> 'demo'
              AND (
                    api_id LIKE 'demo-cardoil-%'
                 OR (raw_payload IS NOT NULL AND JSON_VALID(raw_payload)
                     AND JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.source')) = 'demo')
              )
        ");
        $log("  ~ randuri marcate source_type='demo': {$demoRows}");

        $apiRows = $db->exec("
            UPDATE fuel_fillups SET source_type = 'api'
            WHERE source_type NOT IN ('test','demo')
              AND raw_payload IS NOT NULL AND JSON_VALID(raw_payload)
              AND JSON_EXTRACT(raw_payload, '$.id_alimentare') IS NOT NULL
        ");
        $log("  ~ randuri confirmate source_type='api': {$apiRows}");

        $manualRows = $db->exec("
            UPDATE fuel_fillups SET source_type = 'manual'
            WHERE source_type = 'api'
              AND (raw_payload IS NULL
                   OR NOT JSON_VALID(raw_payload)
                   OR JSON_EXTRACT(raw_payload, '$.id_alimentare') IS NULL)
        ");
        $log("  ~ randuri marcate source_type='manual': {$manualRows}");
    } else {
        $log('  ~ [dry-run] clasificare source_type');
    }
}

// ---------------------------------------------------------------------
// STEP 5 — curse_dispecer traceability columns (nullable, never backfilled)
// ---------------------------------------------------------------------
if (!$columnExists($db, 'curse_dispecer', 'tariff_version_id')) {
    if (!$dryRun) {
        $db->exec("ALTER TABLE curse_dispecer
                   ADD COLUMN tariff_version_id INT UNSIGNED NULL AFTER total_facturare");
        $db->exec("ALTER TABLE curse_dispecer
                   ADD INDEX idx_curse_tariff_version (tariff_version_id)");
    }
    $log('  + curse_dispecer.tariff_version_id adaugat (NULL pentru cursele istorice)');
} else {
    $log('  = curse_dispecer.tariff_version_id exista deja');
}

if (!$columnExists($db, 'curse_dispecer', 'tariff_breakdown_json')) {
    if (!$dryRun) {
        $db->exec("ALTER TABLE curse_dispecer
                   ADD COLUMN tariff_breakdown_json LONGTEXT NULL AFTER tariff_version_id");
    }
    $log('  + curse_dispecer.tariff_breakdown_json adaugat');
} else {
    $log('  = curse_dispecer.tariff_breakdown_json exista deja');
}

// ---------------------------------------------------------------------
// STEP 6 — seed tariff versions from the CURRENT live configuration
// ---------------------------------------------------------------------
if (!$tableExists($db, 'transport_tariff_versions')) {
    $log('  ! transport_tariff_versions lipseste — seed omis');
} else {
    $existing = (int) $db->query('SELECT COUNT(*) FROM transport_tariff_versions')->fetchColumn();
    $log("  i versiuni de tarif existente: {$existing}");

    $insert = $db->prepare("
        INSERT INTO transport_tariff_versions (
            rule_signature, beneficiar_id, transport_type, component_key, unit,
            route_scope, route_ref_id, loc_incarcare_id, zona_distributie_id,
            value, valid_from, valid_to, fuel_weight,
            reference_fuel_price, reference_captured_at,
            source, reason, created_by, created_at, updated_at
        ) VALUES (
            :rule_signature, :beneficiar_id, :transport_type, :component_key, :unit,
            :route_scope, :route_ref_id, :loc_incarcare_id, :zona_distributie_id,
            :value, :valid_from, NULL, NULL,
            NULL, NULL,
            'migration', :reason, NULL, :created_at, :updated_at
        )
    ");
    $probe = $db->prepare('SELECT COUNT(*) FROM transport_tariff_versions WHERE rule_signature = :sig');

    $seed = static function (
        string $componentKey,
        string $transportType,
        string $unit,
        int $beneficiaryId,
        float $value,
        string $routeScope = 'none',
        ?int $routeRefId = null,
        ?int $locId = null,
        ?int $zoneId = null
    ) use ($insert, $probe, $baseline, $now, $dryRun, $log): int {
        $signature = $beneficiaryId . '|' . $componentKey . '|' . (int) $routeRefId;
        $probe->execute(['sig' => $signature]);
        if ((int) $probe->fetchColumn() > 0) {
            return 0;
        }
        if ($dryRun) {
            $log("    + [dry-run] {$signature} = {$value} {$unit}");
            return 1;
        }
        $insert->execute([
            'rule_signature' => $signature,
            'beneficiar_id' => $beneficiaryId,
            'transport_type' => $transportType,
            'component_key' => $componentKey,
            'unit' => $unit,
            'route_scope' => $routeScope,
            'route_ref_id' => $routeRefId,
            'loc_incarcare_id' => $locId,
            'zona_distributie_id' => $zoneId,
            'value' => $value,
            'valid_from' => $baseline,
            'reason' => 'Migrare din configurarea existenta (Configurare transport)',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return 1;
    };

    $seeded = 0;

    // --- 6a. Beneficiary-level components ------------------------------
    $beneficiaries = $db->query('
        SELECT id, nume, pret_km, pret_tona,
               pret_ora_aspirare, pret_km_dislocare, pret_tona_livrata,
               pret_tona_aspirata_lichida, pret_tona_aspirata_gazoasa,
               suporta_primar, suporta_distributie, suporta_primar_distributie, suporta_compresor
        FROM configurare_beneficiari_transport
    ')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($beneficiaries as $b) {
        $bid = (int) $b['id'];

        if (!empty($b['suporta_primar'])) {
            $seeded += $seed('pret_km', 'primar', 'lei/km', $bid, (float) $b['pret_km']);
            $seeded += $seed('pret_tona', 'primar_tona', 'lei/tona', $bid, (float) $b['pret_tona']);
        }

        if (!empty($b['suporta_compresor'])) {
            $seeded += $seed('pret_ora_aspirare', 'compresor', 'lei/ora', $bid, (float) $b['pret_ora_aspirare']);
            $seeded += $seed('pret_km_dislocare', 'compresor', 'lei/km', $bid, (float) $b['pret_km_dislocare']);
            $seeded += $seed('pret_tona_livrata', 'compresor', 'lei/tona', $bid, (float) $b['pret_tona_livrata']);
            $seeded += $seed('pret_tona_aspirata_lichida', 'compresor', 'lei/tona', $bid, (float) $b['pret_tona_aspirata_lichida']);
            $seeded += $seed('pret_tona_aspirata_gazoasa', 'compresor', 'lei/tona', $bid, (float) $b['pret_tona_aspirata_gazoasa']);
        }
    }

    // --- 6b. Distribution / P+D route components -----------------------
    $distributionRoutes = $db->query('
        SELECT id, beneficiar_id, loc_incarcare_id, zona_distributie_id,
               transport_scope, tarif_tona, cost_extra_km, cost_cursa, aplica_cost_cursa
        FROM configurare_rute_distributie
        WHERE activ = 1
    ')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($distributionRoutes as $r) {
        $scope = (string) $r['transport_scope'];
        $type = $scope === 'distributie' ? 'distributie' : 'primar_distributie';
        $rid = (int) $r['id'];
        $bid = (int) $r['beneficiar_id'];
        $loc = (int) $r['loc_incarcare_id'];
        $zone = (int) $r['zona_distributie_id'];

        $seeded += $seed('tarif_tona', $type, 'lei/tona', $bid, (float) $r['tarif_tona'], $scope, $rid, $loc, $zone);
        $seeded += $seed('cost_extra_km', $type, 'lei/km', $bid, (float) $r['cost_extra_km'], $scope, $rid, $loc, $zone);

        if ((float) $r['cost_cursa'] > 0) {
            $seeded += $seed('cost_cursa', $type, 'lei/cursa', $bid, (float) $r['cost_cursa'], $scope, $rid, $loc, $zone);
        }
    }

    // --- 6c. Primary route fixed overrides -----------------------------
    $primaryRoutes = $db->query('
        SELECT id, beneficiar_id, loc_incarcare_id, zona_distributie_id, cost_cursa
        FROM configurare_rute_primar
        WHERE activ = 1
    ')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($primaryRoutes as $r) {
        if ((float) $r['cost_cursa'] <= 0) {
            continue;
        }
        $seeded += $seed(
            'cost_cursa',
            'primar',
            'lei/cursa',
            (int) $r['beneficiar_id'],
            (float) $r['cost_cursa'],
            'primar',
            (int) $r['id'],
            (int) $r['loc_incarcare_id'],
            (int) $r['zona_distributie_id']
        );
    }

    $log("  + versiuni de tarif create din configurarea existenta: {$seeded}");
}

// ---------------------------------------------------------------------
// STEP 7 — default module settings
// ---------------------------------------------------------------------
if ($tableExists($db, 'transport_tariff_settings') && !$dryRun) {
    $settingsStmt = $db->prepare("
        INSERT INTO transport_tariff_settings (setting_key, setting_value, updated_at)
        VALUES (:k, :v, :t)
        ON DUPLICATE KEY UPDATE setting_key = setting_key
    ");
    // NOTE: fuel_review_threshold_percent is deliberately seeded EMPTY.
    // Section 13 of the brief forbids silently assuming a production threshold.
    foreach ([
        'fuel_review_threshold_percent' => '',
        'fuel_data_stale_days' => '7',
        'fuel_min_observations' => '5',
        'fuel_min_liters' => '500',
    ] as $key => $value) {
        $settingsStmt->execute(['k' => $key, 'v' => $value, 't' => $now]);
    }
    $log('  + setari implicite initializate (prag de revizuire lasat NECONFIGURAT intentionat)');
}

$log('Migrare tarife transport — gata' . ($dryRun ? ' (nimic nu a fost scris)' : ''));
exit(0);
