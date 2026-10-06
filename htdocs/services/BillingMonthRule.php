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
    public const DEFAULT_RULE = self::START;

    public const RULE_LABELS = [
        self::START => 'Data inceput cursa',
        self::END => 'Data sfarsit cursa',
    ];

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

    /** Coloanele cursei care se impart intre partea de km si cea de tone. */
    private const KM_COLUMNS = ['km_cursa', 'km_totali'];
    private const TON_COLUMNS = ['cantitate_incarcata', 'tona_livrata', 'nr_clienti'];

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
                regula ENUM(\'data_inceput\', \'data_sfarsit\') NOT NULL DEFAULT \'data_inceput\',
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
        self::$schemaReady = true;
    }

    public static function normalizeRule(mixed $value): string
    {
        return trim((string) $value) === self::END ? self::END : self::START;
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
     * Salveaza regulile cu efect de la luna data: pentru cursele INCEPUTE din luna aceea.
     * Cursele mai vechi raman pe regula valabila la data lor. Se scrie o versiune noua doar
     * pentru componentele a caror regula se schimba fata de cea valabila in luna aceea.
     *
     * @param array<string, mixed> $rules tip => [componenta => regula]
     * @return int numarul de reguli modificate
     */
    public static function saveRulesForBeneficiary(PDO $db, int $beneficiaryId, array $rules, ?int $userId = null, ?string $fromMonth = null): int
    {
        if ($beneficiaryId <= 0) {
            return 0;
        }

        $since = $fromMonth === null ? self::SINCE_ALWAYS : (self::normalizeMonth($fromMonth) ?? self::SINCE_ALWAYS);
        self::ensureSchema($db);
        $stmt = $db->prepare('
            INSERT INTO ' . self::TABLE . ' (beneficiar_id, tip_transport, componenta, valabil_de_la, regula, updated_by, updated_at)
            VALUES (:beneficiar_id, :tip_transport, :componenta, :valabil_de_la, :regula, :updated_by, :updated_at)
            ON DUPLICATE KEY UPDATE regula = VALUES(regula), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)
        ');
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
                $versions = self::allRules($db)[$beneficiaryId][$type][$component] ?? [];
                // Neschimbata in luna aleasa (si fara versiune proprie in luna aceea): nimic de scris.
                if (!isset($versions[$since]) && self::ruleFor($db, $beneficiaryId, $type, $component, $since) === $rule) {
                    continue;
                }
                if (isset($versions[$since]) && $versions[$since] === $rule) {
                    continue;
                }
                $stmt->bindValue(':beneficiar_id', $beneficiaryId, PDO::PARAM_INT);
                $stmt->bindValue(':tip_transport', $type, PDO::PARAM_STR);
                $stmt->bindValue(':componenta', $component, PDO::PARAM_STR);
                $stmt->bindValue(':valabil_de_la', $since, PDO::PARAM_STR);
                $stmt->bindValue(':regula', $rule, PDO::PARAM_STR);
                $stmt->bindValue(':updated_by', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindValue(':updated_at', date('Y-m-d H:i:s'), PDO::PARAM_STR);
                $stmt->execute();
                $changed++;
            }
        }
        self::$cache = null;

        return $changed;
    }

    /**
     * Istoricul modificarilor unui beneficiar, cele mai noi primele.
     *
     * @return list<array{valabil_de_la: string, tip_transport: string, componenta: string, regula: string, updated_at: string, updated_by_name: string}>
     */
    public static function history(PDO $db, int $beneficiaryId): array
    {
        if ($beneficiaryId <= 0 || $db->query("SHOW TABLES LIKE '" . self::TABLE . "'")->fetchColumn() === false
            || !self::hasColumn($db, 'valabil_de_la')) {
            return [];
        }

        $stmt = $db->prepare('
            SELECT r.valabil_de_la, r.tip_transport, r.componenta, r.regula, r.updated_at,
                   COALESCE(u.nume, \'\') AS updated_by_name
            FROM ' . self::TABLE . ' r
            LEFT JOIN utilizatori u ON u.id = r.updated_by
            WHERE r.beneficiar_id = :beneficiar_id
            ORDER BY r.valabil_de_la DESC, r.updated_at DESC, r.tip_transport, r.componenta
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
     */
    public static function periodTripsSql(PDO $db, string $from, string $toExclusive): string
    {
        $from = self::cleanDate($from, '1000-01-01');
        $toExclusive = self::cleanDate($toExclusive, '9999-12-31');
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
        $part = "(CASE WHEN p.id IN ({$inList($kmIds)}) THEN 'km' WHEN p.id IN ({$inList($tonIds)}) THEN 'tone' ELSE 'full' END)";
        $shareCase = 'CASE p.id';
        foreach ($shares as $id => $share) {
            $shareCase .= ' WHEN ' . (int) $id . ' THEN ' . sprintf('%.6F', $share);
        }
        $shareCase = $shares === [] ? '1' : $shareCase . ' ELSE 1 END';
        $countCase = 'CASE p.id';
        foreach ($counts as $id => $count) {
            $countCase .= ' WHEN ' . (int) $id . ' THEN ' . $count;
        }
        $countCase = $counts === [] ? '1' : $countCase . ' ELSE 1 END';

        $select = [];
        foreach (self::raceColumns($db) as $column) {
            $quoted = 'p.`' . $column . '`';
            if (in_array($column, self::KM_COLUMNS, true)) {
                $select[] = "CASE WHEN {$part} = 'tone' THEN 0 ELSE {$quoted} END AS `{$column}`";
            } elseif (in_array($column, self::TON_COLUMNS, true)) {
                $select[] = "CASE WHEN {$part} = 'km' THEN 0 ELSE {$quoted} END AS `{$column}`";
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
        $select[] = "(CASE {$part} WHEN 'km' THEN {$kmDate} WHEN 'tone' THEN {$tonDate} ELSE {$mainDate} END) AS billing_date";

        return '(SELECT ' . implode(",\n ", $select) . "
            FROM curse_dispecer p
            WHERE ({$inKm} OR {$inTon}) AND p.id NOT IN ({$inList($excluded)}))";
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
