<?php
declare(strict_types=1);

/**
 * Date pentru „Ajustare tarife combustibil & curs BNR” (?page=ajustare_tarife).
 *
 * Tabele proprii (migrarea oficială: database/migrations/2026_10_21_000001_ajustare_tarife_bnr.sql;
 * ensureSchema() doar ține pagina în picioare pe o bază nemigrată):
 *   bnr_exchange_rates         cursurile publicate de BNR, o linie / monedă / zi publicată
 *   bnr_sync_log               fiecare încercare de sincronizare (încercare ≠ succes)
 *   fx_tariff_baselines        referințele salvate (F0, E0, P0) — imuabile
 *   fx_tariff_rule_baselines   referința fiecărei reguli tarifare (rule_signature)
 *   fx_tariff_snapshots        instantanee de simulare (zilnice automate + salvate manual)
 *   fx_tariff_approvals        aprobările manuale
 *   fx_tariff_approval_items   tarif cu tarif: vechi → nou, versiunea creată
 *
 * Tarifele contractuale NU stau aici: rămân în transport_tariff_versions și se
 * scriu exclusiv prin TransportTariffModel::createVersion().
 * Setările se păstrează în transport_tariff_settings (cheile fx_*), lângă pragul
 * existent fuel_review_threshold_percent.
 */
class FuelFxAdjustmentModel extends BaseModel
{
    /** Instantaneele automate mai vechi de atât se șterg; cele salvate manual rămân. */
    public const AUTO_SNAPSHOT_RETENTION_DAYS = 400;

    public const DEFAULT_SETTINGS = [
        'fx_analysis_months' => '3',
        'fx_adjust_basis' => 'diesel_eur',
        'fx_min_observations' => '20',
        'fx_stale_days' => '4',
        // Mod test: sfârșitul ferestrei de referință calculate din prețurile de test.
        'fx_test_reference_end' => '',
    ];

    private static bool $schemaReady = false;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS bnr_exchange_rates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                currency CHAR(3) NOT NULL,
                rate_date DATE NOT NULL,
                rate DECIMAL(14,6) NOT NULL,
                multiplier INT UNSIGNED NOT NULL DEFAULT 1,
                source VARCHAR(40) NOT NULL,
                fetched_at DATETIME NOT NULL,
                UNIQUE KEY uk_bnr_currency_date (currency, rate_date),
                KEY idx_bnr_date (rate_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS bnr_sync_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                status ENUM('running','success','error','skipped') NOT NULL DEFAULT 'running',
                sources VARCHAR(255) NULL,
                received INT UNSIGNED NOT NULL DEFAULT 0,
                inserted INT UNSIGNED NOT NULL DEFAULT 0,
                conflicts INT UNSIGNED NOT NULL DEFAULT 0,
                latest_rate_date DATE NULL,
                message VARCHAR(1000) NULL,
                triggered_by VARCHAR(20) NOT NULL DEFAULT 'cron',
                user_id INT UNSIGNED NULL,
                KEY idx_bnr_sync_status (status, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_tariff_baselines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                label VARCHAR(160) NOT NULL,
                window_start DATE NOT NULL,
                window_end DATE NOT NULL,
                diesel_ron DECIMAL(14,6) NOT NULL,
                eur_ron DECIMAL(14,6) NOT NULL,
                diesel_eur DECIMAL(14,6) NOT NULL,
                fx_observations INT UNSIGNED NOT NULL DEFAULT 0,
                fuel_observations INT UNSIGNED NOT NULL DEFAULT 0,
                fuel_liters DECIMAL(14,2) NOT NULL DEFAULT 0,
                source ENUM('manual','approval') NOT NULL DEFAULT 'manual',
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                note VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                KEY idx_fx_baseline_default (is_default, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_tariff_rule_baselines (
                rule_signature VARCHAR(120) NOT NULL PRIMARY KEY,
                baseline_id INT UNSIGNED NOT NULL,
                tariff_version_id INT UNSIGNED NULL,
                assigned_at DATETIME NOT NULL,
                KEY idx_fx_rule_baseline (baseline_id),
                CONSTRAINT fk_fx_rule_baseline FOREIGN KEY (baseline_id) REFERENCES fx_tariff_baselines(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_tariff_snapshots (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                kind ENUM('auto','saved') NOT NULL DEFAULT 'auto',
                snapshot_date DATE NOT NULL,
                input_hash CHAR(64) NOT NULL,
                baseline_id INT UNSIGNED NULL,
                indicators_json LONGTEXT NOT NULL,
                simulation_json LONGTEXT NOT NULL,
                note VARCHAR(255) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uk_fx_snapshot_kind_hash (kind, input_hash),
                KEY idx_fx_snapshot_date (snapshot_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_tariff_approvals (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                approved_at DATETIME NOT NULL,
                approved_by INT UNSIGNED NULL,
                approved_by_name VARCHAR(160) NULL,
                effective_from DATE NOT NULL,
                note VARCHAR(250) NULL,
                baseline_id INT UNSIGNED NULL,
                new_baseline_id INT UNSIGNED NULL,
                snapshot_id INT UNSIGNED NULL,
                adjust_basis VARCHAR(20) NOT NULL,
                threshold_percent DECIMAL(8,4) NULL,
                variation_percent DECIMAL(12,6) NULL,
                reference_diesel_ron DECIMAL(14,6) NULL,
                reference_eur_ron DECIMAL(14,6) NULL,
                current_diesel_ron DECIMAL(14,6) NULL,
                current_eur_ron DECIMAL(14,6) NULL,
                items_count INT UNSIGNED NOT NULL DEFAULT 0,
                avg_change_percent DECIMAL(12,6) NULL,
                indicators_json LONGTEXT NULL,
                KEY idx_fx_approval_date (approved_at),
                CONSTRAINT fk_fx_approval_baseline FOREIGN KEY (baseline_id) REFERENCES fx_tariff_baselines(id),
                CONSTRAINT fk_fx_approval_new_baseline FOREIGN KEY (new_baseline_id) REFERENCES fx_tariff_baselines(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_tariff_approval_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                approval_id INT UNSIGNED NOT NULL,
                rule_signature VARCHAR(120) NOT NULL,
                beneficiar_id INT UNSIGNED NOT NULL,
                beneficiar_nume VARCHAR(160) NULL,
                transport_type VARCHAR(30) NOT NULL,
                component_key VARCHAR(64) NOT NULL,
                route_ref_id INT UNSIGNED NULL,
                route_label VARCHAR(255) NULL,
                group_label VARCHAR(120) NULL,
                unit VARCHAR(20) NOT NULL,
                old_value DECIMAL(14,4) NOT NULL,
                new_value DECIMAL(14,4) NOT NULL,
                change_percent DECIMAL(12,6) NOT NULL,
                fuel_weight DECIMAL(5,4) NOT NULL,
                variation_percent DECIMAL(12,6) NOT NULL,
                baseline_id INT UNSIGNED NULL,
                old_version_id INT UNSIGNED NULL,
                new_version_id INT UNSIGNED NULL,
                KEY idx_fx_item_approval (approval_id),
                KEY idx_fx_item_signature (rule_signature),
                CONSTRAINT fk_fx_item_approval FOREIGN KEY (approval_id) REFERENCES fx_tariff_approvals(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_tariff_rule_weights (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_signature VARCHAR(120) NOT NULL,
                fuel_weight DECIMAL(5,4) NOT NULL,
                basis_note VARCHAR(255) NOT NULL,
                confirmed_by INT UNSIGNED NULL,
                confirmed_at DATETIME NOT NULL,
                KEY idx_fx_weight_signature (rule_signature, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Mod test: prețuri de motorină introduse manual pe lună. Folosite DOAR când
        // pagina e deschisă cu ?test=1; nu intră în indicatorii reali, cron sau aprobări.
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_test_diesel_months (
                month CHAR(7) NOT NULL PRIMARY KEY,
                diesel_ron DECIMAL(14,6) NOT NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }

    // ------------------------------------------------------------- mod test

    /** @return array<string,float> 'YYYY-MM' → lei/l */
    public function getTestDieselMonths(): array
    {
        $map = [];
        foreach ($this->db->query('SELECT month, diesel_ron FROM fx_test_diesel_months ORDER BY month')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(string) $row['month']] = (float) $row['diesel_ron'];
        }

        return $map;
    }

    /**
     * Înlocuiește toate prețurile de test (lunile lipsă din $months se șterg).
     *
     * @param array<string,float> $months
     */
    public function replaceTestDieselMonths(array $months, ?int $userId): void
    {
        $this->db->exec('DELETE FROM fx_test_diesel_months');
        $insert = $this->db->prepare('
            INSERT INTO fx_test_diesel_months (month, diesel_ron, updated_by, updated_at) VALUES (:m, :v, :u, :at)
        ');
        foreach ($months as $month => $value) {
            $insert->execute([
                'm' => $month,
                'v' => number_format($value, 6, '.', ''),
                'u' => $userId,
                'at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // ------------------------------------------------------------- ponderi confirmate

    /**
     * Ponderea motorinei confirmată pe regulă (ultima confirmare câștigă; istoricul
     * rămâne — rândurile nu se modifică și nu se șterg).
     *
     * @return array<string,array{fuel_weight:float, basis_note:string, confirmed_at:string, user_nume:?string}>
     */
    public function getConfirmedWeights(): array
    {
        $rows = $this->db->query('
            SELECT w.rule_signature, w.fuel_weight, w.basis_note, w.confirmed_at, u.nume AS user_nume
            FROM fx_tariff_rule_weights w
            INNER JOIN (SELECT rule_signature, MAX(id) AS mx FROM fx_tariff_rule_weights GROUP BY rule_signature) l
                    ON l.mx = w.id
            LEFT JOIN utilizatori u ON u.id = w.confirmed_by
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['rule_signature']] = [
                'fuel_weight' => (float) $row['fuel_weight'],
                'basis_note' => (string) $row['basis_note'],
                'confirmed_at' => (string) $row['confirmed_at'],
                'user_nume' => $row['user_nume'] !== null ? (string) $row['user_nume'] : null,
            ];
        }

        return $map;
    }

    public function confirmWeight(string $signature, float $weight, string $basisNote, ?int $userId): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO fx_tariff_rule_weights (rule_signature, fuel_weight, basis_note, confirmed_by, confirmed_at)
            VALUES (:sig, :w, :n, :u, :at)
        ');
        $stmt->execute([
            'sig' => $signature,
            'w' => number_format($weight, 4, '.', ''),
            'n' => mb_substr($basisNote, 0, 255),
            'u' => $userId,
            'at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ------------------------------------------------------------- BNR

    /**
     * Inserează cursurile fără a suprascrie: o zi publicată deja rămâne cum a
     * fost salvată. Întoarce câte s-au inserat și câte diferă de valoarea salvată
     * (BNR nu revizuiește cursurile — o diferență e semnalată în jurnal).
     *
     * @param array<int,array{currency:string, date:string, rate:float, multiplier:int}> $rates
     * @return array{inserted:int, conflicts:int}
     */
    public function storeRates(array $rates, string $source): array
    {
        $insert = $this->db->prepare('
            INSERT IGNORE INTO bnr_exchange_rates (currency, rate_date, rate, multiplier, source, fetched_at)
            VALUES (:currency, :rate_date, :rate, :multiplier, :source, :fetched_at)
        ');
        $existing = $this->db->prepare('
            SELECT rate, multiplier FROM bnr_exchange_rates WHERE currency = :currency AND rate_date = :rate_date
        ');

        $inserted = 0;
        $conflicts = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($rates as $rate) {
            $insert->execute([
                'currency' => $rate['currency'],
                'rate_date' => $rate['date'],
                'rate' => number_format($rate['rate'], 6, '.', ''),
                'multiplier' => max(1, (int) $rate['multiplier']),
                'source' => mb_substr($source, 0, 40),
                'fetched_at' => $now,
            ]);
            if ($insert->rowCount() > 0) {
                $inserted++;
                continue;
            }
            $existing->execute(['currency' => $rate['currency'], 'rate_date' => $rate['date']]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && abs((float) $row['rate'] - $rate['rate']) > 0.0000005) {
                $conflicts++;
            }
        }

        return ['inserted' => $inserted, 'conflicts' => $conflicts];
    }

    /** @return array<int,array{rate_date:string, rate:float}> crescător după dată */
    public function getRates(string $currency, string $from, string $to): array
    {
        $stmt = $this->db->prepare('
            SELECT rate_date, rate, multiplier FROM bnr_exchange_rates
            WHERE currency = :c AND rate_date BETWEEN :f AND :t
            ORDER BY rate_date ASC
        ');
        $stmt->execute(['c' => $currency, 'f' => $from, 't' => $to]);

        return array_map(static fn (array $row): array => [
            'rate_date' => (string) $row['rate_date'],
            'rate' => (float) $row['rate'] / max(1, (int) $row['multiplier']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Ultimul curs publicat la sau înainte de o dată. */
    public function getLatestRate(string $currency, ?string $onOrBefore = null): ?array
    {
        $stmt = $this->db->prepare('
            SELECT rate_date, rate, multiplier FROM bnr_exchange_rates
            WHERE currency = :c AND rate_date <= :d
            ORDER BY rate_date DESC LIMIT 2
        ');
        $stmt->execute(['c' => $currency, 'd' => $onOrBefore ?? date('Y-m-d')]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return null;
        }

        $rate = (float) $rows[0]['rate'] / max(1, (int) $rows[0]['multiplier']);
        $previous = isset($rows[1]) ? (float) $rows[1]['rate'] / max(1, (int) $rows[1]['multiplier']) : null;

        return [
            'rate_date' => (string) $rows[0]['rate_date'],
            'rate' => $rate,
            'previous_rate' => $previous,
            'previous_date' => isset($rows[1]) ? (string) $rows[1]['rate_date'] : null,
        ];
    }

    public function getEarliestRateDate(string $currency): ?string
    {
        $stmt = $this->db->prepare('SELECT MIN(rate_date) FROM bnr_exchange_rates WHERE currency = :c');
        $stmt->execute(['c' => $currency]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function startSyncLog(string $triggeredBy, ?int $userId): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO bnr_sync_log (started_at, status, triggered_by, user_id)
            VALUES (:s, "running", :t, :u)
        ');
        $stmt->execute(['s' => date('Y-m-d H:i:s'), 't' => mb_substr($triggeredBy, 0, 20), 'u' => $userId]);

        return (int) $this->db->lastInsertId();
    }

    public function finishSyncLog(int $id, string $status, array $data): void
    {
        $stmt = $this->db->prepare('
            UPDATE bnr_sync_log
            SET finished_at = :f, status = :st, sources = :src, received = :r, inserted = :i,
                conflicts = :c, latest_rate_date = :l, message = :m
            WHERE id = :id
        ');
        $stmt->execute([
            'f' => date('Y-m-d H:i:s'),
            'st' => $status,
            'src' => isset($data['sources']) ? mb_substr((string) $data['sources'], 0, 255) : null,
            'r' => (int) ($data['received'] ?? 0),
            'i' => (int) ($data['inserted'] ?? 0),
            'c' => (int) ($data['conflicts'] ?? 0),
            'l' => $data['latest_rate_date'] ?? null,
            'm' => isset($data['message']) ? mb_substr((string) $data['message'], 0, 1000) : null,
            'id' => $id,
        ]);
    }

    /** @return array{last_attempt:?array, last_success:?array, last_error:?array} */
    public function getSyncStatus(): array
    {
        $one = function (string $where): ?array {
            $row = $this->db->query('SELECT * FROM bnr_sync_log ' . $where . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        };

        return [
            'last_attempt' => $one("WHERE status <> 'skipped'"),
            'last_success' => $one("WHERE status = 'success'"),
            'last_error' => $one("WHERE status = 'error'"),
        ];
    }

    // ------------------------------------------------------------- setări

    /** @return array<string,string> */
    public function getSettings(TransportTariffModel $tariffs): array
    {
        return array_merge(self::DEFAULT_SETTINGS, array_intersect_key(
            $tariffs->getSettings(),
            self::DEFAULT_SETTINGS + ['fuel_review_threshold_percent' => '', 'fuel_data_stale_days' => '', 'fuel_min_observations' => '', 'fuel_min_liters' => '']
        ));
    }

    // ------------------------------------------------------------- referințe

    public function createBaseline(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO fx_tariff_baselines (
                label, window_start, window_end, diesel_ron, eur_ron, diesel_eur,
                fx_observations, fuel_observations, fuel_liters, source, is_default, note, created_by, created_at
            ) VALUES (
                :label, :ws, :we, :dr, :er, :de, :fo, :fu, :fl, :src, 0, :note, :by, :at
            )
        ');
        $stmt->execute([
            'label' => mb_substr((string) $data['label'], 0, 160),
            'ws' => $data['window_start'],
            'we' => $data['window_end'],
            'dr' => number_format((float) $data['diesel_ron'], 6, '.', ''),
            'er' => number_format((float) $data['eur_ron'], 6, '.', ''),
            'de' => number_format((float) $data['diesel_eur'], 6, '.', ''),
            'fo' => (int) ($data['fx_observations'] ?? 0),
            'fu' => (int) ($data['fuel_observations'] ?? 0),
            'fl' => number_format((float) ($data['fuel_liters'] ?? 0), 2, '.', ''),
            'src' => in_array($data['source'] ?? '', ['manual', 'approval'], true) ? $data['source'] : 'manual',
            'note' => isset($data['note']) && $data['note'] !== '' ? mb_substr((string) $data['note'], 0, 255) : null,
            'by' => $data['created_by'] ?? null,
            'at' => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Mută referința implicită.
     *
     * $pinOthers = true (doar la o APROBARE care devine referință implicită): regulile
     * neaprobate care foloseau vechea referință implicită sunt fixate pe ea, ca
     * variația lor acumulată să nu se „reseteze” la referința nouă.
     * $pinOthers = false (referință schimbată manual din Setări): toate regulile
     * urmează referința nouă, cu excepția celor cu referință proprie de la o aprobare;
     * fixările vechi pe referințe manuale se elimină.
     *
     * @param array<int,string> $allSignatures toate regulile tarifare cunoscute
     */
    public function setDefaultBaseline(int $baselineId, array $allSignatures, bool $pinOthers = false): void
    {
        if (!$pinOthers) {
            $this->db->exec("
                DELETE r FROM fx_tariff_rule_baselines r
                INNER JOIN fx_tariff_baselines b ON b.id = r.baseline_id
                WHERE b.source = 'manual'
            ");
        }

        $old = $this->getDefaultBaseline();
        if ($pinOthers && $old !== null && (int) $old['id'] !== $baselineId && $allSignatures !== []) {
            $mapped = $this->getRuleBaselineMap();
            $pin = $this->db->prepare('
                INSERT IGNORE INTO fx_tariff_rule_baselines (rule_signature, baseline_id, tariff_version_id, assigned_at)
                VALUES (:sig, :b, NULL, :at)
            ');
            foreach ($allSignatures as $signature) {
                if (!isset($mapped[$signature])) {
                    $pin->execute(['sig' => $signature, 'b' => (int) $old['id'], 'at' => date('Y-m-d H:i:s')]);
                }
            }
        }

        $this->db->exec('UPDATE fx_tariff_baselines SET is_default = 0 WHERE is_default = 1');
        $stmt = $this->db->prepare('UPDATE fx_tariff_baselines SET is_default = 1 WHERE id = :id');
        $stmt->execute(['id' => $baselineId]);
    }

    public function getDefaultBaseline(): ?array
    {
        $row = $this->db->query('SELECT * FROM fx_tariff_baselines WHERE is_default = 1 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function getBaseline(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM fx_tariff_baselines WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function getBaselines(int $limit = 50): array
    {
        $stmt = $this->db->prepare('
            SELECT b.*, u.nume AS user_nume FROM fx_tariff_baselines b
            LEFT JOIN utilizatori u ON u.id = b.created_by
            ORDER BY b.id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,int> rule_signature → baseline_id */
    public function getRuleBaselineMap(): array
    {
        $map = [];
        foreach ($this->db->query('SELECT rule_signature, baseline_id FROM fx_tariff_rule_baselines')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(string) $row['rule_signature']] = (int) $row['baseline_id'];
        }

        return $map;
    }

    public function assignRuleBaseline(string $signature, int $baselineId, ?int $versionId): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO fx_tariff_rule_baselines (rule_signature, baseline_id, tariff_version_id, assigned_at)
            VALUES (:sig, :b, :v, :at)
            ON DUPLICATE KEY UPDATE baseline_id = VALUES(baseline_id),
                                    tariff_version_id = VALUES(tariff_version_id),
                                    assigned_at = VALUES(assigned_at)
        ');
        $stmt->execute(['sig' => $signature, 'b' => $baselineId, 'v' => $versionId, 'at' => date('Y-m-d H:i:s')]);
    }

    // ------------------------------------------------------------- instantanee

    /**
     * Un instantaneu per set logic de intrări: același hash (aceleași date sursă,
     * aceeași referință, aceleași reguli) nu se dublează. Întoarce id-ul existent
     * sau pe cel nou și dacă a fost creat acum.
     *
     * @return array{id:int, created:bool}
     */
    public function storeSnapshot(string $kind, string $hash, ?int $baselineId, array $indicators, array $simulation, ?string $note, ?int $userId): array
    {
        $find = $this->db->prepare('SELECT id FROM fx_tariff_snapshots WHERE kind = :k AND input_hash = :h');
        $find->execute(['k' => $kind, 'h' => $hash]);
        $existing = $find->fetchColumn();
        if ($existing !== false) {
            return ['id' => (int) $existing, 'created' => false];
        }

        $stmt = $this->db->prepare('
            INSERT IGNORE INTO fx_tariff_snapshots (
                kind, snapshot_date, input_hash, baseline_id, indicators_json, simulation_json, note, created_by, created_at
            ) VALUES (:k, :d, :h, :b, :i, :s, :n, :u, :at)
        ');
        $stmt->execute([
            'k' => $kind,
            'd' => date('Y-m-d'),
            'h' => $hash,
            'b' => $baselineId,
            'i' => json_encode($indicators, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            's' => json_encode($simulation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'n' => $note !== null && $note !== '' ? mb_substr($note, 0, 255) : null,
            'u' => $userId,
            'at' => date('Y-m-d H:i:s'),
        ]);
        if ($stmt->rowCount() === 0) {
            $find->execute(['k' => $kind, 'h' => $hash]);

            return ['id' => (int) $find->fetchColumn(), 'created' => false];
        }

        return ['id' => (int) $this->db->lastInsertId(), 'created' => true];
    }

    public function getLatestSnapshot(?string $kind = null): ?array
    {
        $sql = 'SELECT * FROM fx_tariff_snapshots' . ($kind !== null ? ' WHERE kind = :k' : '') . ' ORDER BY id DESC LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($kind !== null ? ['k' => $kind] : []);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function purgeOldAutoSnapshots(): int
    {
        $stmt = $this->db->prepare("
            DELETE s FROM fx_tariff_snapshots s
            LEFT JOIN fx_tariff_approvals a ON a.snapshot_id = s.id
            WHERE s.kind = 'auto' AND s.snapshot_date < :limit AND a.id IS NULL
        ");
        $stmt->execute(['limit' => (new DateTimeImmutable('today'))->modify('-' . self::AUTO_SNAPSHOT_RETENTION_DAYS . ' days')->format('Y-m-d')]);

        return $stmt->rowCount();
    }

    // ------------------------------------------------------------- aprobări

    public function createApproval(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO fx_tariff_approvals (
                approved_at, approved_by, approved_by_name, effective_from, note, baseline_id, new_baseline_id,
                snapshot_id, adjust_basis, threshold_percent, variation_percent,
                reference_diesel_ron, reference_eur_ron, current_diesel_ron, current_eur_ron,
                items_count, avg_change_percent, indicators_json
            ) VALUES (
                :at, :by, :byn, :ef, :note, :b, :nb, :snap, :basis, :thr, :var, :rdr, :rer, :cdr, :cer, :n, :avg, :ind
            )
        ');
        $stmt->execute([
            'at' => date('Y-m-d H:i:s'),
            'by' => $data['approved_by'] ?? null,
            'byn' => $data['approved_by_name'] ?? null,
            'ef' => $data['effective_from'],
            'note' => isset($data['note']) && $data['note'] !== '' ? mb_substr((string) $data['note'], 0, 250) : null,
            'b' => $data['baseline_id'] ?? null,
            'nb' => $data['new_baseline_id'] ?? null,
            'snap' => $data['snapshot_id'] ?? null,
            'basis' => (string) $data['adjust_basis'],
            'thr' => $data['threshold_percent'] ?? null,
            'var' => $data['variation_percent'] ?? null,
            'rdr' => $data['reference_diesel_ron'] ?? null,
            'rer' => $data['reference_eur_ron'] ?? null,
            'cdr' => $data['current_diesel_ron'] ?? null,
            'cer' => $data['current_eur_ron'] ?? null,
            'n' => (int) ($data['items_count'] ?? 0),
            'avg' => $data['avg_change_percent'] ?? null,
            'ind' => isset($data['indicators']) ? json_encode($data['indicators'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateApprovalTotals(int $approvalId, int $count, ?float $avgChange): void
    {
        $stmt = $this->db->prepare('UPDATE fx_tariff_approvals SET items_count = :n, avg_change_percent = :a WHERE id = :id');
        $stmt->execute(['n' => $count, 'a' => $avgChange, 'id' => $approvalId]);
    }

    public function addApprovalItem(int $approvalId, array $item): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO fx_tariff_approval_items (
                approval_id, rule_signature, beneficiar_id, beneficiar_nume, transport_type, component_key,
                route_ref_id, route_label, group_label, unit, old_value, new_value, change_percent,
                fuel_weight, variation_percent, baseline_id, old_version_id, new_version_id
            ) VALUES (
                :a, :sig, :b, :bn, :tt, :ck, :rr, :rl, :gl, :unit, :ov, :nv, :cp, :fw, :vp, :bl, :ovid, :nvid
            )
        ');
        $stmt->execute([
            'a' => $approvalId,
            'sig' => $item['rule_signature'],
            'b' => (int) $item['beneficiar_id'],
            'bn' => $item['beneficiar_nume'] ?? null,
            'tt' => $item['transport_type'],
            'ck' => $item['component_key'],
            'rr' => $item['route_ref_id'] ?? null,
            'rl' => $item['route_label'] ?? null,
            'gl' => $item['group_label'] ?? null,
            'unit' => $item['unit'],
            'ov' => number_format((float) $item['old_value'], 4, '.', ''),
            'nv' => number_format((float) $item['new_value'], 4, '.', ''),
            'cp' => number_format((float) $item['change_percent'], 6, '.', ''),
            'fw' => number_format((float) $item['fuel_weight'], 4, '.', ''),
            'vp' => number_format((float) $item['variation_percent'], 6, '.', ''),
            'bl' => $item['baseline_id'] ?? null,
            'ovid' => $item['old_version_id'] ?? null,
            'nvid' => $item['new_version_id'] ?? null,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function getApprovals(int $limit = 20): array
    {
        $stmt = $this->db->prepare('
            SELECT a.*, u.nume AS user_nume FROM fx_tariff_approvals a
            LEFT JOIN utilizatori u ON u.id = a.approved_by
            ORDER BY a.approved_at DESC, a.id DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getApproval(int $id): ?array
    {
        $stmt = $this->db->prepare('
            SELECT a.*, u.nume AS user_nume FROM fx_tariff_approvals a
            LEFT JOIN utilizatori u ON u.id = a.approved_by WHERE a.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $items = $this->db->prepare('SELECT * FROM fx_tariff_approval_items WHERE approval_id = :id ORDER BY group_label, beneficiar_nume, id');
        $items->execute(['id' => $id]);
        $row['items'] = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $row;
    }
}
