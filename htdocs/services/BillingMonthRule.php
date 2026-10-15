<?php

declare(strict_types=1);

/**
 * Luna de facturare a unei curse: regula negociata cu fiecare beneficiar, pe tip de
 * transport si pe COMPONENTA facturata (km / tone).
 *
 *  - data_inceput: componenta intra in luna in care a inceput cursa (implicit);
 *  - data_sfarsit: componenta intra in luna in care s-a incheiat cursa.
 *
 * Componentele fiecarui tip (COMPONENTS):
 *  - Primar km      -> doar km;   Primar tona -> doar tone (tipuri separate de cursa);
 *  - Distributie, Primar+Distributie -> tone + km pe aceeasi cursa: cu reguli diferite,
 *    o cursa 27.07 - 01.08 isi poate factura km-ii in iulie si tonele in august;
 *  - Compresor      -> toata cursa.
 *
 * Cursa impartita apare in ambele luni, fiecare cu partea ei: km-ii si partea de valoare
 * a km-ilor intr-una, tonele si partea de valoare a tonelor in cealalta. Impartirea
 * valorii salvate (total_facturare) urmeaza ponderea componentelor din motorul de
 * tarifare. Cursa se numara (si costurile ei intra) o singura data, in luna partii
 * principale (tonele, la Distributie / P+D). Cursa cu pret fix sau cu ruta doar pe tona
 * / doar pe km nu are ce imparti: urmeaza luna componentei ei.
 *
 * Folosita de Centralizator facturare, Dashboard Analitic V2 si Istoric activitati sofer.
 */
final class BillingMonthRule
{
    public const START = 'data_inceput';
    public const END = 'data_sfarsit';
    /**
     * Cursa care trece in luna urmatoare se imparte pe fazele ei de la miezul noptii
     * (RaceMonthSplitService): fiecare luna primeste partea fazelor ei, dupa km (ex. Flaga).
     * Fara faze in luni diferite (ex. cursa fara ore) se comporta ca Data inceput.
     */
    public const SPLIT = 'faze';
    public const DEFAULT_RULE = self::START;

    public const RULE_LABELS = [
        self::START => 'Data inceput cursa',
        self::END => 'Data sfarsit cursa',
        self::SPLIT => 'Impartit pe faze (la miezul noptii)',
    ];

    /** Per beneficiar: din ce luna refacturarile urmeaza luna cursei (vezi sqlExpenseInPeriod). */
    private const REINVOICE_TABLE = 'configurare_beneficiari_refacturari';

    /** Componentele facturate ale fiecarui tip; prima este componenta principala. */
    public const COMPONENTS = [
        'primar' => ['km'],
        'primar_tona' => ['tone'],
        'distributie' => ['tone', 'km'],
        'primar_distributie' => ['tone', 'km'],
        'compresor' => ['total'],
    ];

    public const COMPONENT_LABELS = [
        'km' => 'Km',
        'tone' => 'Tone',
        'total' => 'Toata cursa',
    ];

    public const TRANSPORT_TYPES = ['primar', 'primar_tona', 'distributie', 'primar_distributie', 'compresor'];

    /** Componentele motorului de tarifare care tin de km (restul tin de tone / cursa). */
    private const KM_PRICE_COMPONENTS = ['pret_km', 'cost_extra_km'];

    private const TABLE = 'configurare_beneficiari_luna_facturare';
    /** Jurnalul schimbarilor (istoricul afisat), pastrat si cand versiunile se inlocuiesc. */
    private const LOG_TABLE = 'configurare_beneficiari_luna_facturare_jurnal';

    /** Coloanele cursei care se impart intre partea de km si cea de tone. */
    private const KM_COLUMNS = ['km_cursa', 'km_totali'];
    private const TON_COLUMNS = ['cantitate_incarcata', 'tona_livrata', 'nr_clienti'];
    /** Celelalte coloane aditive, impartite doar pe faze (billing_part = 'phase'). */
    private const PHASE_SCALED_COLUMNS = [
        'km_dislocare', 'ore_functionare', 'ore_aspirare', 'cantitate_prelevata',
        'tona_aspirata_lichida', 'tona_aspirata_gazoasa',
    ];

    private static bool $schemaReady = false;

    /** Prima luna posibila: versiunea „dintotdeauna” (reguli salvate fara luna de start). */
    public const SINCE_ALWAYS = '1000-01-01';

    /**
     * Versiunile regulilor, crescator dupa luna de start:
     * beneficiar => tip => componenta => [valabil_de_la (Y-m-d) => regula].
     *
     * @var array<int, array<string, array<string, array<string, string>>>>|null
     */
    private static ?array $cache = null;

    /** @var array<int, float|null> cursa_id => ponderea km-ilor in valoare */
    private static array $kmFractionCache = [];

    /** @var list<string>|null */
    private static ?array $raceColumns = null;

    private static ?TransportPricingService $pricing = null;

    // ------------------------------------------------------------------ schema / reguli

    /** DDL: se apeleaza doar la salvare (CREATE / ALTER fac COMMIT implicit). */
    public static function ensureSchema(PDO $db): void
    {
        if (self::$schemaReady) {
            return;
        }

        $db->exec('
            CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                beneficiar_id INT UNSIGNED NOT NULL,
                tip_transport VARCHAR(32) NOT NULL,
                componenta VARCHAR(16) NOT NULL DEFAULT \'total\',
                valabil_de_la DATE NOT NULL DEFAULT \'' . self::SINCE_ALWAYS . '\',
                regula ENUM(\'data_inceput\', \'data_sfarsit\', \'faze\') NOT NULL DEFAULT \'data_inceput\',
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (beneficiar_id, tip_transport, componenta, valabil_de_la)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        // Versiuni vechi ale tabelei: fara componenta (o regula pe tip), apoi fara luna de start.
        if (!self::hasColumn($db, 'componenta')) {
            $db->exec('ALTER TABLE ' . self::TABLE . "
                ADD COLUMN componenta VARCHAR(16) NOT NULL DEFAULT 'total' AFTER tip_transport,
                DROP PRIMARY KEY,
                ADD PRIMARY KEY (beneficiar_id, tip_transport, componenta)");
        }
        if (!self::hasColumn($db, 'valabil_de_la')) {
            $db->exec('ALTER TABLE ' . self::TABLE . "
                ADD COLUMN valabil_de_la DATE NOT NULL DEFAULT '" . self::SINCE_ALWAYS . "' AFTER componenta,
                DROP PRIMARY KEY,
                ADD PRIMARY KEY (beneficiar_id, tip_transport, componenta, valabil_de_la)");
        }
        // Regula „Impartit pe faze” (migrarea 2026_10_15_000003).
        $ruleType = (string) ($db->query('SHOW COLUMNS FROM ' . self::TABLE . " LIKE 'regula'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '');
        if ($ruleType !== '' && !str_contains($ruleType, "'faze'")) {
            $db->exec('ALTER TABLE ' . self::TABLE . " MODIFY regula ENUM('data_inceput', 'data_sfarsit', 'faze') NOT NULL DEFAULT 'data_inceput'");
        }
        // Jurnalul: la creare preia versiunile existente, ca istoricul vechi sa ramana vizibil.
        if ($db->query("SHOW TABLES LIKE '" . self::LOG_TABLE . "'")->fetchColumn() === false) {
            $db->exec('
                CREATE TABLE ' . self::LOG_TABLE . ' (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    beneficiar_id INT UNSIGNED NOT NULL,
                    tip_transport VARCHAR(32) NOT NULL,
                    componenta VARCHAR(16) NOT NULL,
                    valabil_de_la DATE NOT NULL,
                    regula VARCHAR(16) NOT NULL,
                    updated_by INT UNSIGNED NULL,
                    updated_at DATETIME NOT NULL,
                    KEY idx_jurnal_beneficiar (beneficiar_id, updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
            $db->exec('
                INSERT INTO ' . self::LOG_TABLE . ' (beneficiar_id, tip_transport, componenta, valabil_de_la, regula, updated_by, updated_at)
                SELECT beneficiar_id, tip_transport, componenta, valabil_de_la, regula, updated_by, updated_at FROM ' . self::TABLE . '
            ');
        }
        self::$schemaReady = true;
    }

    public static function normalizeRule(mixed $value): string
    {
        $value = trim((string) $value);

        return $value === self::END || $value === self::SPLIT ? $value : self::START;
    }

    /** Cursa urmeaza fazele (regula „Impartit pe faze” pe oricare componenta a tipului ei)? */
    public static function usesPhases(PDO $db, int $beneficiaryId, string $transportType, ?string $tripStart): bool
    {
        foreach (self::COMPONENTS[$transportType] ?? [] as $component) {
            if (self::ruleFor($db, $beneficiaryId, $transportType, $component, $tripStart) === self::SPLIT) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ refacturari

    /** Refacturarile beneficiarului urmeaza luna cursei (optiune din Configurare transport)? */
    public static function reinvoiceFollowsTrip(PDO $db, int $beneficiaryId): bool
    {
        return in_array($beneficiaryId, self::reinvoiceFollowsTripBeneficiaries($db), true);
    }

    /** @return list<int> beneficiarii ale caror refacturari urmeaza luna cursei */
    public static function reinvoiceFollowsTripBeneficiaries(PDO $db): array
    {
        if ($db->query("SHOW TABLES LIKE '" . self::REINVOICE_TABLE . "'")->fetchColumn() === false) {
            return [];
        }

        return array_map('intval', $db->query('SELECT beneficiar_id FROM ' . self::REINVOICE_TABLE . ' WHERE urmeaza_cursa = 1')->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** Porneste / opreste optiunea pentru beneficiar. DDL: in afara tranzactiilor. */
    public static function saveReinvoiceFollowsTrip(PDO $db, int $beneficiaryId, bool $follows, ?int $userId = null): void
    {
        if ($beneficiaryId <= 0) {
            return;
        }
        $db->exec('
            CREATE TABLE IF NOT EXISTS ' . self::REINVOICE_TABLE . ' (
                beneficiar_id INT UNSIGNED NOT NULL PRIMARY KEY,
                urmeaza_cursa TINYINT(1) NOT NULL DEFAULT 0,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        $stmt = $db->prepare('
            INSERT INTO ' . self::REINVOICE_TABLE . ' (beneficiar_id, urmeaza_cursa, updated_by, updated_at) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE urmeaza_cursa = VALUES(urmeaza_cursa), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)
        ');
        $stmt->execute([$beneficiaryId, $follows ? 1 : 0, $userId, date('Y-m-d H:i:s')]);
    }

    /**
     * Conditia SQL „refacturarea intra in perioada [from, toExclusive)” pentru Centralizator.
     *
     * Implicit refacturarea intra dupa data ei. Beneficiarii cu optiunea „Refacturarile
     * urmeaza luna cursei” (Configurare transport) o au, pentru toate cursele lor, in luna cursei:
     *  - Data inceput / Data sfarsit: luna de facturare a cursei (partea principala);
     *  - Impartit pe faze (cursa cu faze in luni diferite): linia ramane intreaga si
     *    intra in luna datei ei, adusa in intervalul cursei (o taxa platita dupa
     *    sfarsitul cursei merge pe ultima zi a cursei).
     * Doar literali validati, deci se poate repeta in interogare.
     */
    public static function sqlExpenseInPeriod(PDO $db, string $expenseAlias, string $tripAlias, string $from, string $toExclusive): string
    {
        $e = self::cleanAlias($expenseAlias);
        $c = self::cleanAlias($tripAlias);
        $from = self::cleanDate($from, '1000-01-01');
        $toExclusive = self::cleanDate($toExclusive, '9999-12-31');
        $expenseDate = "COALESCE({$e}.refacturare_data, {$e}.data_cheltuiala)";
        $periodDate = $expenseDate;

        $beneficiaries = self::reinvoiceFollowsTripBeneficiaries($db);
        if ($beneficiaries !== []) {
            $tripStart = "COALESCE({$c}.data_inceput, {$c}.data_cursa)";
            $tripEnd = "COALESCE({$c}.data_sfarsit, {$c}.data_inceput, {$c}.data_cursa)";
            $splitIds = self::phaseShares($db, '1000-01-01', '9999-12-31', true)['all'];
            $tripDate = self::sqlDateExpr($db, $c);
            if ($splitIds !== []) {
                $tripDate = "(CASE WHEN {$c}.id IN (" . implode(', ', array_map('intval', $splitIds)) . ")
                    THEN LEAST(GREATEST(COALESCE({$expenseDate}, {$tripStart}), {$tripStart}), {$tripEnd})
                    ELSE {$tripDate} END)";
            }
            $periodDate = "(CASE WHEN {$c}.beneficiar_id IN (" . implode(', ', array_map('intval', $beneficiaries))
                . ") THEN {$tripDate} ELSE {$expenseDate} END)";
        }

        return "({$periodDate} >= '{$from}' AND {$periodDate} < '{$toExclusive}')";
    }

    public static function mainComponent(string $transportType): string
    {
        return self::COMPONENTS[$transportType][0] ?? 'total';
    }

    /**
     * Luna de start a unei modificari (YYYY-MM sau Y-m-d) -> prima zi a lunii, sau null.
     */
    public static function normalizeMonth(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})(?:-\d{2})?$/', $value, $match) !== 1) {
            return null;
        }
        $month = (int) $match[2];
        if ($month < 1 || $month > 12 || (int) $match[1] < 2000) {
            return null;
        }

        return sprintf('%04d-%02d-01', (int) $match[1], $month);
    }

    /**
     * @return array<int, array<string, array<string, array<string, string>>>>
     */
    public static function allRules(PDO $db): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        // Citirea nu ruleaza DDL: tabela se creeaza la prima salvare din Configurare transport.
        $rules = [];
        if ($db->query("SHOW TABLES LIKE '" . self::TABLE . "'")->fetchColumn() === false) {
            return self::$cache = $rules;
        }

        $componentSelect = self::hasColumn($db, 'componenta') ? 'componenta' : "'total' AS componenta";
        $sinceSelect = self::hasColumn($db, 'valabil_de_la') ? 'valabil_de_la' : "'" . self::SINCE_ALWAYS . "' AS valabil_de_la";
        $stmt = $db->query("SELECT beneficiar_id, tip_transport, {$componentSelect}, {$sinceSelect}, regula FROM " . self::TABLE);
        $legacy = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $type = (string) $row['tip_transport'];
            if (!isset(self::COMPONENTS[$type])) {
                continue;
            }
            $component = (string) $row['componenta'];
            $since = substr((string) $row['valabil_de_la'], 0, 10);
            $rule = self::normalizeRule($row['regula']);
            if (in_array($component, self::COMPONENTS[$type], true)) {
                $rules[(int) $row['beneficiar_id']][$type][$component][$since] = $rule;
            } elseif ($component === 'total') {
                $legacy[(int) $row['beneficiar_id']][$type][$since] = $rule;
            }
        }
        // O regula veche „pe tot tipul” se aplica fiecarei componente fara regula proprie.
        foreach ($legacy as $beneficiaryId => $types) {
            foreach ($types as $type => $versions) {
                foreach (self::COMPONENTS[$type] as $component) {
                    $rules[$beneficiaryId][$type][$component] ??= $versions;
                }
            }
        }
        foreach ($rules as &$types) {
            foreach ($types as &$components) {
                foreach ($components as &$versions) {
                    ksort($versions);
                }
            }
        }
        unset($types, $components, $versions);

        return self::$cache = $rules;
    }

    /**
     * Regulile afisate in Configurare transport: cele valabile la luna data (implicit
     * ultima versiune salvata, inclusiv una programata pentru o luna viitoare).
     *
     * @return array<string, array<string, string>> tip => componenta => regula, pentru toate
     */
    public static function rulesForBeneficiary(PDO $db, int $beneficiaryId, ?string $atDate = null): array
    {
        $rules = [];
        foreach (self::COMPONENTS as $type => $components) {
            foreach ($components as $component) {
                $rules[$type][$component] = self::ruleFor($db, $beneficiaryId, $type, $component, $atDate ?? '9999-12-31');
            }
        }

        return $rules;
    }

    /**
     * Salveaza regulile (din 2026-10-15 fara luna de start, cerinta utilizatorului): o
     * regula SCHIMBATA in formular se aplica tuturor curselor beneficiarului pe tipul
     * acela, inclusiv celor vechi — versiunile ei anterioare se inlocuiesc cu una singura
     * („toate cursele”). Un select neatins (aceeasi regula ca cea afisata) nu se scrie,
     * deci versiunile lui vechi raman cum erau. Fiecare schimbare intra in jurnal (history).
     *
     * @param array<string, mixed> $rules tip => [componenta => regula]
     * @return int numarul de reguli modificate
     */
    public static function saveRulesForBeneficiary(PDO $db, int $beneficiaryId, array $rules, ?int $userId = null): int
    {
        if ($beneficiaryId <= 0) {
            return 0;
        }

        self::ensureSchema($db);
        $delete = $db->prepare('DELETE FROM ' . self::TABLE . ' WHERE beneficiar_id = ? AND tip_transport = ? AND componenta = ?');
        $insert = $db->prepare('
            INSERT INTO ' . self::TABLE . ' (beneficiar_id, tip_transport, componenta, valabil_de_la, regula, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $log = $db->prepare('
            INSERT INTO ' . self::LOG_TABLE . ' (beneficiar_id, tip_transport, componenta, valabil_de_la, regula, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $now = date('Y-m-d H:i:s');
        $changed = 0;
        foreach (self::COMPONENTS as $type => $components) {
            $typeRules = $rules[$type] ?? null;
            if (!is_array($typeRules)) {
                continue;
            }
            foreach ($components as $component) {
                if (!array_key_exists($component, $typeRules)) {
                    continue;
                }
                $rule = self::normalizeRule($typeRules[$component]);
                // Neschimbata fata de ce arata formularul (ultima versiune): nimic de scris.
                if (self::ruleFor($db, $beneficiaryId, $type, $component, '9999-12-31') === $rule) {
                    continue;
                }
                $delete->execute([$beneficiaryId, $type, $component]);
                $insert->execute([$beneficiaryId, $type, $component, self::SINCE_ALWAYS, $rule, $userId, $now]);
                $log->execute([$beneficiaryId, $type, $component, self::SINCE_ALWAYS, $rule, $userId, $now]);
                $changed++;
            }
        }
        self::$cache = null;

        return $changed;
    }

    /**
     * Istoricul modificarilor unui beneficiar (jurnalul), cele mai noi primele.
     *
     * @return list<array{valabil_de_la: string, tip_transport: string, componenta: string, regula: string, updated_at: string, updated_by_name: string}>
     */
    public static function history(PDO $db, int $beneficiaryId): array
    {
        if ($beneficiaryId <= 0) {
            return [];
        }
        // Inainte de prima salvare jurnalul nu exista inca: istoricul sunt versiunile salvate.
        $source = self::LOG_TABLE;
        if ($db->query("SHOW TABLES LIKE '" . self::LOG_TABLE . "'")->fetchColumn() === false) {
            if ($db->query("SHOW TABLES LIKE '" . self::TABLE . "'")->fetchColumn() === false || !self::hasColumn($db, 'valabil_de_la')) {
                return [];
            }
            $source = self::TABLE;
        }

        $stmt = $db->prepare('
            SELECT r.valabil_de_la, r.tip_transport, r.componenta, r.regula, r.updated_at,
                   COALESCE(u.nume, \'\') AS updated_by_name
            FROM ' . $source . ' r
            LEFT JOIN utilizatori u ON u.id = r.updated_by
            WHERE r.beneficiar_id = :beneficiar_id
            ORDER BY r.updated_at DESC, ' . ($source === self::LOG_TABLE ? 'r.id DESC' : 'r.valabil_de_la DESC') . '
        ');
        $stmt->execute([':beneficiar_id' => $beneficiaryId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Regula unei componente pentru o cursa inceputa la data data (implicit: azi).
     * Componenta pe care tipul nu o are (ex. tone la Primar km) urmeaza componenta
     * principala a tipului, ca sa nu imparta niciodata cursa.
     */
    public static function ruleFor(PDO $db, int $beneficiaryId, string $transportType, string $component = 'main', ?string $tripStart = null): string
    {
        $components = self::COMPONENTS[$transportType] ?? null;
        if ($components === null) {
            return self::DEFAULT_RULE;
        }
        if (!in_array($component, $components, true)) {
            $component = $components[0];
        }

        $date = substr(trim((string) ($tripStart ?? '')), 0, 10);
        if ($date === '') {
            $date = date('Y-m-d');
        }
        $rule = self::DEFAULT_RULE;
        foreach (self::allRules($db)[$beneficiaryId][$transportType][$component] ?? [] as $since => $versionRule) {
            if ($since > $date) {
                break;
            }
            $rule = $versionRule;
        }

        return $rule;
    }

    // ------------------------------------------------------------------ date

    /**
     * Expresia SQL a datei de facturare a unei componente ('km', 'tone' sau 'main').
     * Regula se alege dupa luna in care a INCEPUT cursa (versiunile din Configurare
     * transport). Doar literali validati (fara parametri), deci se poate repeta in
     * aceeasi interogare (PDO ruleaza cu EMULATE_PREPARES=false).
     */
    public static function sqlComponentDateExpr(PDO $db, string $alias, string $component): string
    {
        $alias = self::cleanAlias($alias);
        $start = "COALESCE({$alias}.data_inceput, {$alias}.data_cursa)";
        $end = "COALESCE({$alias}.data_sfarsit, {$alias}.data_inceput, {$alias}.data_cursa)";

        // Intervalele [de la, pana la) in care regula componentei este Data sfarsit.
        $conditions = [];
        foreach (self::allRules($db) as $beneficiaryId => $types) {
            foreach ($types as $type => $components) {
                $own = in_array($component, self::COMPONENTS[$type], true) ? $component : self::COMPONENTS[$type][0];
                $versions = $components[$own] ?? [];
                $sinceList = array_keys($versions);
                foreach ($sinceList as $index => $since) {
                    if ($versions[$since] !== self::END) {
                        continue;
                    }
                    $until = $sinceList[$index + 1] ?? null;
                    $condition = "{$alias}.beneficiar_id = " . (int) $beneficiaryId
                        . " AND {$alias}.tip_transport = '" . $type . "'";
                    if ($since > self::SINCE_ALWAYS) {
                        $condition .= " AND {$start} >= '" . $since . "'";
                    }
                    if ($until !== null) {
                        $condition .= " AND {$start} < '" . $until . "'";
                    }
                    $conditions[] = '(' . $condition . ')';
                }
            }
        }

        if ($conditions === []) {
            return $start;
        }

        return '(CASE WHEN ' . implode(' OR ', $conditions) . " THEN {$end} ELSE {$start} END)";
    }

    /** Data de facturare a partii principale a cursei (luna in care cursa se numara). */
    public static function sqlDateExpr(PDO $db, string $alias = 'c'): string
    {
        return self::sqlComponentDateExpr($db, $alias, 'main');
    }

    /**
     * Data de facturare (Y-m-d) a unei componente pentru un rand de cursa deja incarcat.
     *
     * @param array<string, mixed> $row beneficiar_id, tip_transport, data_inceput, data_sfarsit, data_cursa
     */
    public static function dateForRow(PDO $db, array $row, string $component = 'main'): string
    {
        $start = substr((string) (($row['data_inceput'] ?? '') ?: ($row['data_cursa'] ?? '')), 0, 10);
        $rule = self::ruleFor($db, (int) ($row['beneficiar_id'] ?? 0), (string) ($row['tip_transport'] ?? ''), $component, $start);
        if ($rule === self::END) {
            $end = trim((string) ($row['data_sfarsit'] ?? ''));
            if ($end !== '') {
                return substr($end, 0, 10);
            }
        }

        return $start;
    }

    // ------------------------------------------------------------------ partea din perioada

    /**
     * Ce parte a cursei intra in perioada [from, toExclusive).
     *
     * @return array{part: string, value_share: float, km: bool, tons: bool, counts: bool,
     *               km_month: string, tons_month: string}
     *         part = 'full' | 'km' | 'tone' | 'none'
     */
    public static function periodShare(PDO $db, array $row, string $from, string $toExclusive): array
    {
        $kmDate = self::dateForRow($db, $row, 'km');
        $tonDate = self::dateForRow($db, $row, 'tone');
        $inPeriod = static fn (string $date): bool => $date !== ''
            && ($from === '' || $date >= $from)
            && ($toExclusive === '' || $date < $toExclusive);
        $inKm = $inPeriod($kmDate);
        $inTon = $inPeriod($tonDate);
        $base = [
            'km_month' => $kmDate !== '' ? substr($kmDate, 0, 7) : '',
            'tons_month' => $tonDate !== '' ? substr($tonDate, 0, 7) : '',
        ];
        $full = ['part' => 'full', 'value_share' => 1.0, 'km' => true, 'tons' => true, 'counts' => true] + $base;
        $none = ['part' => 'none', 'value_share' => 0.0, 'km' => false, 'tons' => false, 'counts' => false] + $base;

        if ($inKm && $inTon) {
            return $full;
        }
        if (!$inKm && !$inTon) {
            return $none;
        }

        // Componentele cad in luni diferite: valoarea se imparte dupa motorul de tarifare.
        $kmFraction = self::kmFraction($db, $row);
        if ($kmFraction === null || $kmFraction <= 0.0) {
            return $inTon ? $full : $none;   // pret fix / doar pe tona: urmeaza tonele
        }
        if ($kmFraction >= 1.0) {
            return $inKm ? $full : $none;    // ruta doar pe km: urmeaza km-ii
        }

        $main = self::mainComponent((string) ($row['tip_transport'] ?? ''));
        if ($inKm) {
            return ['part' => 'km', 'value_share' => $kmFraction, 'km' => true, 'tons' => false, 'counts' => $main === 'km'] + $base;
        }

        return ['part' => 'tone', 'value_share' => 1.0 - $kmFraction, 'km' => false, 'tons' => true, 'counts' => $main === 'tone'] + $base;
    }

    /**
     * Ponderea km-ilor in valoarea cursei, din motorul de tarifare (doar citiri).
     * null = cursa nu are componente separabile (pret fix, tarif lipsa).
     */
    public static function kmFraction(PDO $db, array $row): ?float
    {
        $tripId = (int) ($row['id'] ?? 0);
        if ($tripId > 0 && array_key_exists($tripId, self::$kmFractionCache)) {
            return self::$kmFractionCache[$tripId];
        }

        $fraction = null;
        try {
            self::$pricing ??= new TransportPricingService($db);
            $quote = self::$pricing->quote([
                'beneficiar_id' => (int) ($row['beneficiar_id'] ?? 0),
                'tip_transport' => (string) ($row['tip_transport'] ?? ''),
                'data_cursa' => (string) (($row['data_cursa'] ?? '') ?: ($row['data_inceput'] ?? '')),
                'vehicle_id' => (int) ($row['vehicle_id'] ?? 0),
                'loc_incarcare_id' => (int) ($row['loc_incarcare_id'] ?? 0),
                'zona_distributie_id' => (int) ($row['zona_distributie_id'] ?? 0),
                'loc_plecare' => (string) ($row['loc_plecare'] ?? ''),
                'loc_intoarcere' => (string) ($row['loc_intoarcere'] ?? ''),
                'cantitate_incarcata' => (float) ($row['cantitate_incarcata'] ?? 0),
                'km_cursa' => (float) ($row['km_cursa'] ?? 0),
                'km_totali' => (float) ($row['km_totali'] ?? 0),
                'tona_livrata' => (float) ($row['tona_livrata'] ?? 0),
            ]);
            $kmAmount = 0.0;
            $total = 0.0;
            foreach ((array) ($quote['components'] ?? []) as $component) {
                $amount = max(0.0, (float) ($component['amount'] ?? 0));
                $total += $amount;
                if (in_array((string) ($component['key'] ?? ''), self::KM_PRICE_COMPONENTS, true)) {
                    $kmAmount += $amount;
                }
            }
            if (!empty($quote['ok']) && $total > 0) {
                $fraction = round($kmAmount / $total, 6);
            }
        } catch (Throwable $exception) {
            error_log('[BillingMonthRule][kmFraction] ' . $exception->getMessage());
        }

        if ($tripId > 0) {
            self::$kmFractionCache[$tripId] = $fraction;
        }

        return $fraction;
    }

    /**
     * Tabela derivata „cursele perioadei”, de pus in locul lui `curse_dispecer`:
     * aceleasi coloane, doar cursele cu cel putin o componenta in [from, toExclusive),
     * iar la cursele impartite coloanele partii care nu e in perioada sunt 0 si
     * total_facturare este doar partea perioadei. Coloane in plus:
     *  - billing_part   'full' | 'km' | 'tone'
     *  - billing_counts 1 daca cursa se numara (si costurile ei intra) in perioada
     *  - billing_date   data de facturare a partii din perioada
     *  - billing_share  partea din cursa care intra in perioada (1 = toata; < 1 doar pe faze)
     *
     * $byPhases (Dashboard Analitic V2): cursele cu faze in luni diferite (ex. 31.08 ->
     * 01.09, impartita automat la miezul noptii, vezi RaceMonthSplitService) nu urmeaza
     * regula de facturare: fiecare perioada primeste partea fazelor ei (dupa km-ii lor),
     * din km, tone, valoare si cheltuieli (billing_part = 'phase'). Cursa se numara o
     * data, in perioada primei faze. Fara $byPhases (Centralizator) se imparte asa doar
     * cursa al carei beneficiar are regula „Impartit pe faze” (SPLIT) pentru tipul ei.
     */
    public static function periodTripsSql(PDO $db, string $from, string $toExclusive, bool $byPhases = false): string
    {
        $from = self::cleanDate($from, '1000-01-01');
        $toExclusive = self::cleanDate($toExclusive, '9999-12-31');
        $phases = self::phaseShares($db, $from, $toExclusive, !$byPhases);
        $kmDate = self::sqlComponentDateExpr($db, 'p', 'km');
        $tonDate = self::sqlComponentDateExpr($db, 'p', 'tone');
        $mainDate = self::sqlComponentDateExpr($db, 'p', 'main');
        $inKm = "({$kmDate} >= '{$from}' AND {$kmDate} < '{$toExclusive}')";
        $inTon = "({$tonDate} >= '{$from}' AND {$tonDate} < '{$toExclusive}')";

        // Cursele cu doar o componenta in perioada: decizia se ia in PHP (motorul de tarifare).
        $kmIds = [];
        $tonIds = [];
        $excluded = [];
        $shares = [];
        $counts = [];
        if ($kmDate !== $tonDate) {
            $stmt = $db->query("SELECT p.* FROM curse_dispecer p WHERE p.deleted_at IS NULL AND ({$inKm} XOR {$inTon})");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
                $id = (int) $candidate['id'];
                $share = self::periodShare($db, $candidate, $from, $toExclusive);
                if ($share['part'] === 'none') {
                    $excluded[] = $id;
                } elseif ($share['part'] === 'km') {
                    $kmIds[] = $id;
                } elseif ($share['part'] === 'tone') {
                    $tonIds[] = $id;
                }
                if ($share['part'] === 'km' || $share['part'] === 'tone') {
                    $shares[$id] = $share['value_share'];
                    $counts[$id] = $share['counts'] ? 1 : 0;
                }
            }
        }

        // Lista goala = 0 (id-urile sunt pozitive). NU NULL: `id NOT IN (NULL)` e NULL si ar
        // elimina toate cursele.
        $inList = static fn (array $ids): string => $ids === [] ? '0' : implode(', ', array_map('intval', $ids));
        $phaseDates = [];
        foreach ($phases['in'] as $id => $phase) {
            $shares[$id] = $phase['share'];
            $counts[$id] = $phase['counts'] ? 1 : 0;
            $phaseDates[$id] = $phase['date'];
        }
        $phaseIn = array_keys($phases['in']);
        $part = "(CASE WHEN p.id IN ({$inList($phaseIn)}) THEN 'phase' WHEN p.id IN ({$inList($kmIds)}) THEN 'km' WHEN p.id IN ({$inList($tonIds)}) THEN 'tone' ELSE 'full' END)";
        $shareCase = 'CASE p.id';
        foreach ($shares as $id => $share) {
            $shareCase .= ' WHEN ' . (int) $id . ' THEN ' . sprintf('%.10F', $share);
        }
        $shareCase = $shares === [] ? '1' : $shareCase . ' ELSE 1 END';
        $countCase = 'CASE p.id';
        foreach ($counts as $id => $count) {
            $countCase .= ' WHEN ' . (int) $id . ' THEN ' . $count;
        }
        $countCase = $counts === [] ? '1' : $countCase . ' ELSE 1 END';

        $phaseDateCase = "'1000-01-01'";
        if ($phaseDates !== []) {
            $phaseDateCase = 'CASE p.id';
            foreach ($phaseDates as $id => $date) {
                $phaseDateCase .= ' WHEN ' . (int) $id . " THEN '" . self::cleanDate($date, $from) . "'";
            }
            $phaseDateCase .= " ELSE '1000-01-01' END";
        }

        $select = [];
        foreach (self::raceColumns($db) as $column) {
            $quoted = 'p.`' . $column . '`';
            $phaseScaled = "{$quoted} * ({$shareCase})";
            if (in_array($column, self::KM_COLUMNS, true)) {
                $select[] = "CASE WHEN {$part} = 'tone' THEN 0 WHEN {$part} = 'phase' THEN {$phaseScaled} ELSE {$quoted} END AS `{$column}`";
            } elseif (in_array($column, self::TON_COLUMNS, true)) {
                $select[] = "CASE WHEN {$part} = 'km' THEN 0 WHEN {$part} = 'phase' THEN {$phaseScaled} ELSE {$quoted} END AS `{$column}`";
            } elseif (in_array($column, self::PHASE_SCALED_COLUMNS, true)) {
                $select[] = "CASE WHEN {$part} = 'phase' THEN {$phaseScaled} ELSE {$quoted} END AS `{$column}`";
            } elseif ($column === 'capacitate_transport') {
                // Partea doar de km nu intra in gradul de incarcare (nu are tone).
                $select[] = "CASE WHEN {$part} = 'km' THEN NULL ELSE {$quoted} END AS `{$column}`";
            } elseif ($column === 'total_facturare') {
                $select[] = "ROUND({$quoted} * ({$shareCase}), 2) AS `{$column}`";
            } else {
                $select[] = $quoted;
            }
        }
        $select[] = "{$part} AS billing_part";
        $select[] = "({$countCase}) AS billing_counts";
        $select[] = "(CASE {$part} WHEN 'km' THEN {$kmDate} WHEN 'tone' THEN {$tonDate} WHEN 'phase' THEN ({$phaseDateCase}) ELSE {$mainDate} END) AS billing_date";
        $select[] = "(CASE WHEN {$part} = 'phase' THEN ({$shareCase}) ELSE 1 END) AS billing_share";

        return '(SELECT ' . implode(",\n ", $select) . "
            FROM curse_dispecer p
            WHERE (({$inKm} OR {$inTon}) AND p.id NOT IN ({$inList($excluded)}) AND p.id NOT IN ({$inList($phases['all'])}))
               OR p.id IN ({$inList($phaseIn)}))";
    }

    /**
     * Cursele cu faze in mai multe luni si partea lor din perioada [from, toExclusive).
     * O faza intra in perioada dupa data ei de inceput; ponderea ei = km-ii fazei (sau
     * durata, cand fazele nu au km). $onlySplitRule: doar cursele cu regula „Impartit pe
     * faze” (SPLIT) la beneficiarul si tipul lor, valabila la inceputul cursei.
     *
     * @return array{all: list<int>, in: array<int, array{share: float, counts: bool, date: string}>}
     */
    public static function phaseShares(PDO $db, string $from, string $toExclusive, bool $onlySplitRule = false): array
    {
        $result = ['all' => [], 'in' => []];
        if ($db->query("SHOW TABLES LIKE 'curse_segmente'")->fetchColumn() === false) {
            return $result;
        }
        if ($onlySplitRule && !self::anySplitRule($db)) {
            return $result;
        }

        $stmt = $db->query("
            SELECT seg.cursa_id, seg.data_inceput, seg.ora_inceput, seg.data_sfarsit, seg.ora_sfarsit, seg.km,
                   p.beneficiar_id AS trip_beneficiar_id, p.tip_transport AS trip_tip_transport,
                   COALESCE(p.data_inceput, p.data_cursa) AS trip_start
            FROM curse_segmente seg
            INNER JOIN curse_dispecer p ON p.id = seg.cursa_id AND p.deleted_at IS NULL
            WHERE seg.deleted_at IS NULL
              AND seg.cursa_id IN (
                  SELECT cursa_id FROM curse_segmente
                  WHERE deleted_at IS NULL AND data_inceput IS NOT NULL
                  GROUP BY cursa_id
                  HAVING COUNT(DISTINCT DATE_FORMAT(data_inceput, '%Y-%m')) > 1
              )
            ORDER BY seg.cursa_id, seg.data_inceput, seg.ora_inceput, seg.ordine
        ");
        $byTrip = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $byTrip[(int) $row['cursa_id']][] = $row;
        }

        foreach ($byTrip as $tripId => $segments) {
            if ($onlySplitRule && !self::usesPhases(
                $db,
                (int) $segments[0]['trip_beneficiar_id'],
                (string) $segments[0]['trip_tip_transport'],
                (string) $segments[0]['trip_start']
            )) {
                continue;
            }
            $result['all'][] = $tripId;
            $kmTotal = 0.0;
            foreach ($segments as $segment) {
                $kmTotal += max(0.0, (float) ($segment['km'] ?? 0));
            }
            $weightTotal = 0.0;
            $weightIn = 0.0;
            $date = '';
            $first = true;
            $counts = false;
            foreach ($segments as $segment) {
                $start = substr((string) ($segment['data_inceput'] ?? ''), 0, 10);
                $weight = $kmTotal > 0 ? max(0.0, (float) ($segment['km'] ?? 0)) : self::segmentSeconds($segment);
                $weightTotal += $weight;
                $in = $start !== '' && $start >= $from && $start < $toExclusive;
                if ($in) {
                    $weightIn += $weight;
                    $end = substr((string) (($segment['data_sfarsit'] ?? '') ?: $start), 0, 10);
                    $date = max($date, $end);
                    $counts = $counts || $first;
                }
                $first = false;
            }
            if ($date === '') {
                continue;
            }
            $share = $weightTotal > 0 ? $weightIn / $weightTotal : 1.0 / count($segments);
            $result['in'][$tripId] = ['share' => round($share, 10), 'counts' => $counts, 'date' => $date];
        }

        return $result;
    }

    /** Exista macar o regula „Impartit pe faze” (fara ea Centralizatorul nu citeste fazele)? */
    private static function anySplitRule(PDO $db): bool
    {
        foreach (self::allRules($db) as $types) {
            foreach ($types as $components) {
                foreach ($components as $versions) {
                    if (in_array(self::SPLIT, $versions, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Durata unei faze in secunde (23:59:59 = pana la miezul noptii). */
    private static function segmentSeconds(array $segment): float
    {
        $start = strtotime(trim((string) ($segment['data_inceput'] ?? '')) . ' ' . (trim((string) ($segment['ora_inceput'] ?? '')) ?: '00:00:00'));
        $endTime = trim((string) ($segment['ora_sfarsit'] ?? '')) ?: '00:00:00';
        $end = strtotime(trim((string) ($segment['data_sfarsit'] ?? '')) . ' ' . $endTime);
        if ($start === false || $end === false || $end <= $start) {
            return 0.0;
        }

        return (float) ($end - $start + (substr($endTime, 0, 8) === '23:59:59' ? 1 : 0));
    }

    // ------------------------------------------------------------------ utilitare

    /** Doar pentru teste: uita regulile si ponderile citite in cererea curenta. */
    public static function resetCache(): void
    {
        self::$cache = null;
        self::$kmFractionCache = [];
    }

    private static function hasColumn(PDO $db, string $column): bool
    {
        $column = preg_replace('/[^a-z_]/', '', $column);

        return $db->query('SHOW COLUMNS FROM ' . self::TABLE . " LIKE '" . $column . "'")->fetchColumn() !== false;
    }

    /** @return list<string> */
    private static function raceColumns(PDO $db): array
    {
        if (self::$raceColumns === null) {
            self::$raceColumns = array_map('strval', $db->query('SHOW COLUMNS FROM curse_dispecer')->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        return self::$raceColumns;
    }

    private static function cleanAlias(string $alias): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'c';
    }

    private static function cleanDate(string $date, string $fallback): string
    {
        $date = substr(trim($date), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : $fallback;
    }
}
