<?php
declare(strict_types=1);

/**
 * Taxe de refacturat (taxa acces, port, trecere, taxe drum) pe rute.
 *
 * Regulile stau in reguli_taxe_refacturare si se administreaza din pagina
 * "Reguli taxe refacturare": loc descarcare (obligatoriu) + loc incarcare si tip
 * transport (optionale, gol = oricare) => taxa asteptata. La crearea tabelului,
 * regulile se completeaza automat din tiparele gasite in istoric (ex. orice cursa
 * Primar cu descarcare la Lugoj are Taxa acces); tiparele noi, neacoperite de o
 * regula, apar pe pagina ca sugestii.
 *
 * Cursele recente care se potrivesc unei reguli active si nu au taxa sunt aratate
 * operatorului in panoul de aprobari. Semnalarile pot fi ignorate per cursa si tip
 * ("Nu se aplica").
 */
class ReinvoiceFeeExpectationModel extends BaseModel
{
    public const FEE_TYPES = [
        'taxa_acces' => 'Taxa acces',
        'port' => 'Port',
        'trece' => 'Trecere',
        'taxe_drum' => 'Taxe drum',
    ];

    public const TRANSPORT_TYPES = [
        'primar' => 'Primar km',
        'primar_tona' => 'Primar tone',
        'distributie' => 'Distributie',
        'primar_distributie' => 'Primar+Distributie',
        'compresor' => 'Compresor',
    ];

    /** Istoricul folosit pentru sugestii si pentru statistica fiecarei reguli. */
    private const LEARN_DAYS = 180;
    /**
     * Cursele verificate: cele cu data de inceput in ultimele N zile SAU adaugate in ultimele
     * N zile, fara limita in viitor — operatorul trebuie avertizat chiar cand adauga cursa,
     * inclusiv pentru o cursa programata pe maine sau introdusa tarziu cu o data veche.
     */
    private const CHECK_DAYS = 45;
    /** Tiparele se invata doar din cursele incepute acum cel putin N zile (taxele sunt deja introduse). */
    private const SETTLE_DAYS = 3;
    /** Sugestie pe ruta: minim 3 curse, minim 80% cu taxa. */
    private const ROUTE_MIN_TRIPS = 3;
    private const ROUTE_MIN_SHARE = 0.8;
    /** Sugestie pe loc de descarcare (orice loc de incarcare): minim 5 curse, minim 90%. */
    private const ZONE_MIN_TRIPS = 5;
    private const ZONE_MIN_SHARE = 0.9;

    private bool $schemaEnsured = false;

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS curse_taxe_refacturare_ignorate (
                cursa_id INT UNSIGNED NOT NULL,
                tip_cheltuiala VARCHAR(30) NOT NULL,
                ignorata_de INT UNSIGNED NULL,
                ignorata_la DATETIME NOT NULL,
                PRIMARY KEY (cursa_id, tip_cheltuiala),
                CONSTRAINT fk_taxe_ignorate_cursa FOREIGN KEY (cursa_id) REFERENCES curse_dispecer(id) ON DELETE CASCADE,
                CONSTRAINT fk_taxe_ignorate_user FOREIGN KEY (ignorata_de) REFERENCES utilizatori(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $rulesTableExists = (int) $this->db->query("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reguli_taxe_refacturare'
        ")->fetchColumn() > 0;

        if (!$rulesTableExists) {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS reguli_taxe_refacturare (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    zona_distributie_id INT UNSIGNED NOT NULL,
                    loc_incarcare_id INT UNSIGNED NULL,
                    tip_transport VARCHAR(30) NULL,
                    tip_cheltuiala VARCHAR(30) NOT NULL,
                    suma_uzuala DECIMAL(12,2) NULL,
                    observatii VARCHAR(255) NULL,
                    activ TINYINT(1) NOT NULL DEFAULT 1,
                    sursa ENUM('manual', 'istoric') NOT NULL DEFAULT 'manual',
                    created_by INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_reguli_taxe_zona (zona_distributie_id),
                    CONSTRAINT fk_reguli_taxe_zona FOREIGN KEY (zona_distributie_id) REFERENCES configurare_zone_distributie(id) ON DELETE CASCADE,
                    CONSTRAINT fk_reguli_taxe_loc FOREIGN KEY (loc_incarcare_id) REFERENCES configurare_locuri_incarcare(id) ON DELETE CASCADE,
                    CONSTRAINT fk_reguli_taxe_user FOREIGN KEY (created_by) REFERENCES utilizatori(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $this->schemaEnsured = true;

        // Prima rulare: regulile existente in practica (din istoric) se adauga automat,
        // ca pagina sa nu porneasca goala. Doar la crearea tabelului, ca o regula
        // stearsa intentionat sa nu revina.
        if (!$rulesTableExists) {
            foreach ($this->learnPatterns() as $pattern) {
                $this->insertRule($pattern + ['observatii' => null, 'activ' => 1], 'istoric', null);
            }
        }
    }

    // ------------------------------------------------------------------
    // Reguli
    // ------------------------------------------------------------------

    public function getRules(): array
    {
        $this->ensureSchema();

        $rules = $this->db->query("
            SELECT r.*,
                   zd.nume AS zona_nume, bz.nume AS zona_beneficiar,
                   li.nume AS loc_nume, bl.nume AS loc_beneficiar,
                   u.nume AS created_by_name
            FROM reguli_taxe_refacturare r
            INNER JOIN configurare_zone_distributie zd ON zd.id = r.zona_distributie_id
            LEFT JOIN configurare_beneficiari_transport bz ON bz.id = zd.beneficiar_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = r.loc_incarcare_id
            LEFT JOIN configurare_beneficiari_transport bl ON bl.id = li.beneficiar_id
            LEFT JOIN utilizatori u ON u.id = r.created_by
            ORDER BY r.activ DESC, zd.nume, li.nume IS NOT NULL, li.nume, r.tip_cheltuiala
        ")->fetchAll();

        // Cat de des se respecta fiecare regula in istoric: curse potrivite / curse cu taxa.
        [$trips, $tripFees] = $this->loadHistory();
        foreach ($rules as &$rule) {
            $matched = 0;
            $withFee = 0;
            foreach ($trips as $trip) {
                if (!$this->ruleMatchesTrip($rule, $trip)) {
                    continue;
                }
                $matched++;
                if (isset($tripFees[(int) $trip['id']][(string) $rule['tip_cheltuiala']])) {
                    $withFee++;
                }
            }
            $rule['trips_matched'] = $matched;
            $rule['trips_with_fee'] = $withFee;
        }
        unset($rule);

        return $rules;
    }

    public function getRule(int $id): ?array
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('SELECT * FROM reguli_taxe_refacturare WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Valideaza si salveaza o regula. Returneaza [id, erori].
     */
    public function saveRule(array $input, ?int $id, ?int $userId): array
    {
        $this->ensureSchema();

        $data = [
            'zona_distributie_id' => (int) ($input['zona_distributie_id'] ?? 0),
            'loc_incarcare_id' => (int) ($input['loc_incarcare_id'] ?? 0) > 0 ? (int) $input['loc_incarcare_id'] : null,
            'tip_transport' => trim((string) ($input['tip_transport'] ?? '')) ?: null,
            'tip_cheltuiala' => trim((string) ($input['tip_cheltuiala'] ?? '')),
            'suma_uzuala' => null,
            'observatii' => trim((string) ($input['observatii'] ?? '')) ?: null,
            'activ' => !empty($input['activ']) ? 1 : 0,
        ];

        $errors = [];
        if ($data['zona_distributie_id'] <= 0 || !$this->exists('configurare_zone_distributie', $data['zona_distributie_id'])) {
            $errors['zona_distributie_id'] = 'Alege locul de descarcare.';
        }
        if ($data['loc_incarcare_id'] !== null && !$this->exists('configurare_locuri_incarcare', $data['loc_incarcare_id'])) {
            $errors['loc_incarcare_id'] = 'Locul de incarcare nu exista.';
        }
        if ($data['tip_transport'] !== null && !isset(self::TRANSPORT_TYPES[$data['tip_transport']])) {
            $errors['tip_transport'] = 'Tip de transport invalid.';
        }
        if (!isset(self::FEE_TYPES[$data['tip_cheltuiala']])) {
            $errors['tip_cheltuiala'] = 'Alege taxa.';
        }
        $amountRaw = str_replace(',', '.', trim((string) ($input['suma_uzuala'] ?? '')));
        if ($amountRaw !== '') {
            if (!is_numeric($amountRaw) || (float) $amountRaw < 0) {
                $errors['suma_uzuala'] = 'Suma trebuie sa fie un numar pozitiv.';
            } else {
                $data['suma_uzuala'] = round((float) $amountRaw, 2);
            }
        }
        if (mb_strlen((string) $data['observatii']) > 255) {
            $errors['observatii'] = 'Observatiile pot avea maxim 255 de caractere.';
        }

        if ($errors === [] && $this->findDuplicate($data, $id) !== null) {
            $errors['duplicate'] = 'Exista deja o regula pentru aceeasi ruta, acelasi tip de transport si aceeasi taxa.';
        }
        if ($errors !== []) {
            return [null, $errors];
        }

        if ($id === null) {
            return [$this->insertRule($data, 'manual', $userId), []];
        }

        $stmt = $this->db->prepare("
            UPDATE reguli_taxe_refacturare
            SET zona_distributie_id = ?, loc_incarcare_id = ?, tip_transport = ?, tip_cheltuiala = ?,
                suma_uzuala = ?, observatii = ?, activ = ?, updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['zona_distributie_id'], $data['loc_incarcare_id'], $data['tip_transport'], $data['tip_cheltuiala'],
            $data['suma_uzuala'], $data['observatii'], $data['activ'], date('Y-m-d H:i:s'), $id,
        ]);

        return [$id, []];
    }

    public function deleteRule(int $id): bool
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('DELETE FROM reguli_taxe_refacturare WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    public function toggleRule(int $id): bool
    {
        $this->ensureSchema();
        $stmt = $this->db->prepare('UPDATE reguli_taxe_refacturare SET activ = 1 - activ, updated_at = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Tipare din istoric neacoperite de nicio regula (activa sau nu), cu numele locurilor.
     */
    public function getSuggestions(): array
    {
        $this->ensureSchema();

        $rules = $this->db->query('SELECT * FROM reguli_taxe_refacturare')->fetchAll();
        $names = $this->placeNames();
        $suggestions = [];
        foreach ($this->learnPatterns() as $pattern) {
            foreach ($rules as $rule) {
                if ($this->ruleCoversPattern($rule, $pattern)) {
                    continue 2;
                }
            }
            $pattern['zona_nume'] = $names['zones'][$pattern['zona_distributie_id']] ?? '#' . $pattern['zona_distributie_id'];
            $pattern['loc_nume'] = $pattern['loc_incarcare_id'] !== null
                ? ($names['locations'][$pattern['loc_incarcare_id']] ?? '#' . $pattern['loc_incarcare_id'])
                : null;
            $suggestions[] = $pattern;
        }

        return $suggestions;
    }

    /** Locurile de descarcare / incarcare pentru formular, cu beneficiarul (numele se repeta intre beneficiari). */
    public function getPlaceOptions(): array
    {
        $names = $this->placeNames();
        asort($names['zones'], SORT_NATURAL | SORT_FLAG_CASE);
        asort($names['locations'], SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    // ------------------------------------------------------------------
    // Verificarea curselor
    // ------------------------------------------------------------------

    /**
     * Taxele lipsa pe cursele recente, dupa regulile active.
     * Cu $createdBy, doar cursele adaugate de acel utilizator.
     */
    public function getMissingFees(?int $createdBy = null): array
    {
        $this->ensureSchema();

        $rules = $this->db->query("
            SELECT r.*, zd.nume AS zona_nume, li.nume AS loc_nume
            FROM reguli_taxe_refacturare r
            INNER JOIN configurare_zone_distributie zd ON zd.id = r.zona_distributie_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = r.loc_incarcare_id
            WHERE r.activ = 1
        ")->fetchAll();
        if ($rules === []) {
            return ['count' => 0, 'rows' => []];
        }

        $checkFrom = date('Y-m-d', strtotime('-' . self::CHECK_DAYS . ' days'));
        [$trips, $tripFees] = $this->loadHistory($checkFrom);

        $dismissed = [];
        foreach ($this->db->query('SELECT cursa_id, tip_cheltuiala FROM curse_taxe_refacturare_ignorate')->fetchAll() as $row) {
            $dismissed[(int) $row['cursa_id'] . '|' . $row['tip_cheltuiala']] = true;
        }

        $rows = [];
        foreach ($trips as $trip) {
            $tripId = (int) $trip['id'];
            if ($createdBy !== null && (int) ($trip['created_by'] ?? 0) !== $createdBy) {
                continue;
            }

            $flagged = [];
            // Regula cea mai specifica (cu loc de incarcare / tip transport) castiga la afisare.
            foreach ($this->sortBySpecificity($rules) as $rule) {
                $type = (string) $rule['tip_cheltuiala'];
                if (isset($flagged[$type]) || !$this->ruleMatchesTrip($rule, $trip)) {
                    continue;
                }
                if (isset($tripFees[$tripId][$type]) || isset($dismissed[$tripId . '|' . $type])) {
                    $flagged[$type] = true;
                    continue;
                }
                $flagged[$type] = true;

                $loading = (string) $trip['loc_incarcare_nume'];
                $unloading = (string) $trip['zona_nume'];
                $ruleRoute = ($rule['loc_nume'] !== null ? (string) $rule['loc_nume'] : 'Orice loc') . ' → ' . (string) $rule['zona_nume'];
                if ($rule['tip_transport'] !== null) {
                    $ruleRoute .= ' (' . (self::TRANSPORT_TYPES[(string) $rule['tip_transport']] ?? $rule['tip_transport']) . ')';
                }

                $rows[] = [
                    'race_id' => $tripId,
                    'rule_id' => (int) $rule['id'],
                    'fee_type' => $type,
                    'fee_label' => self::FEE_TYPES[$type],
                    'plate' => (string) $trip['nr_inmatriculare'],
                    'date' => (string) $trip['data_inceput'],
                    'route' => $loading !== '' ? $loading . ' → ' . $unloading : $unloading,
                    'transport' => self::TRANSPORT_TYPES[(string) $trip['tip_transport']] ?? (string) $trip['tip_transport'],
                    'typical_amount' => $rule['suma_uzuala'] !== null ? (float) $rule['suma_uzuala'] : null,
                    'evidence' => 'Regula: ' . $ruleRoute . ' → ' . self::FEE_TYPES[$type]
                        . ($rule['observatii'] ? ' · ' . $rule['observatii'] : '') . '.',
                    'user_id' => (int) ($trip['created_by'] ?? 0),
                    'user_name' => (string) $trip['user_name'] !== '' ? (string) $trip['user_name'] : 'Utilizator necunoscut',
                    'url' => build_query_url(['page' => 'dispecer_curse', 'action' => 'edit', 'id' => $tripId, 'focus' => 'refacturare']),
                ];
            }
        }

        usort($rows, static fn(array $a, array $b): int => [$b['date'], $b['race_id']] <=> [$a['date'], $a['race_id']]);

        return ['count' => count($rows), 'rows' => $rows];
    }

    public function dismiss(int $raceId, string $feeType, ?int $userId): bool
    {
        $this->ensureSchema();
        if ($raceId <= 0 || !isset(self::FEE_TYPES[$feeType])) {
            return false;
        }

        $stmt = $this->db->prepare("
            INSERT IGNORE INTO curse_taxe_refacturare_ignorate (cursa_id, tip_cheltuiala, ignorata_de, ignorata_la)
            VALUES (?, ?, ?, ?)
        ");

        return $stmt->execute([$raceId, $feeType, $userId, date('Y-m-d H:i:s')]);
    }

    public function getRaceCreator(int $raceId): ?int
    {
        $stmt = $this->db->prepare('SELECT created_by FROM curse_dispecer WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$raceId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    /**
     * Cursele active cu loc de descarcare si taxele lor [tripId => [tip => [sume]]].
     * Fara $since: istoricul de LEARN_DAYS zile. Filtrul prinde si cursele adaugate
     * recent cu o data veche, si nu are limita in viitor.
     */
    private function loadHistory(?string $since = null): array
    {
        $since = $since ?? date('Y-m-d', strtotime('-' . self::LEARN_DAYS . ' days'));

        $tripsStmt = $this->db->prepare("
            SELECT c.id, c.tip_transport, c.loc_incarcare_id, c.zona_distributie_id, c.data_inceput, c.created_at, c.created_by,
                   COALESCE(v.nr_inmatriculare, '') AS nr_inmatriculare,
                   COALESCE(li.nume, '') AS loc_incarcare_nume,
                   COALESCE(zd.nume, '') AS zona_nume,
                   COALESCE(u.nume, '') AS user_name
            FROM curse_dispecer c
            LEFT JOIN vehicule v ON v.id = c.vehicle_id
            LEFT JOIN configurare_locuri_incarcare li ON li.id = c.loc_incarcare_id
            LEFT JOIN configurare_zone_distributie zd ON zd.id = c.zona_distributie_id
            LEFT JOIN utilizatori u ON u.id = c.created_by
            WHERE c.deleted_at IS NULL
              AND c.zona_distributie_id IS NOT NULL
              AND (c.data_inceput >= :since_start OR c.created_at >= :since_created)
        ");
        $tripsStmt->execute(['since_start' => $since, 'since_created' => $since . ' 00:00:00']);
        $trips = $tripsStmt->fetchAll();

        $typePlaceholders = implode(',', array_fill(0, count(self::FEE_TYPES), '?'));
        $feesStmt = $this->db->prepare("
            SELECT ch.cursa_id,
                   COALESCE(ch.refacturare_tip_cheltuiala, ch.tip_cheltuiala) AS fee_type,
                   COALESCE(NULLIF(ch.refacturare_suma, 0), ch.suma) AS amount
            FROM curse_cheltuieli ch
            INNER JOIN curse_dispecer c ON c.id = ch.cursa_id
            WHERE c.deleted_at IS NULL
              AND (c.data_inceput >= ? OR c.created_at >= ?)
              AND COALESCE(ch.refacturare_tip_cheltuiala, ch.tip_cheltuiala) IN ({$typePlaceholders})
        ");
        $feesStmt->execute(array_merge([$since, $since . ' 00:00:00'], array_keys(self::FEE_TYPES)));

        $tripFees = [];
        foreach ($feesStmt->fetchAll() as $fee) {
            $tripFees[(int) $fee['cursa_id']][(string) $fee['fee_type']][] = (float) $fee['amount'];
        }

        return [$trips, $tripFees];
    }

    /**
     * Tiparele din istoric: pe loc de descarcare (orice loc de incarcare) cand tine
     * aproape mereu, altfel pe ruta. Sugestiile pe ruta deja acoperite de un tipar
     * pe loc de descarcare nu se mai repeta.
     */
    private function learnPatterns(): array
    {
        [$trips, $tripFees] = $this->loadHistory();
        // Cursele foarte recente sau viitoare inca nu au taxele introduse: ar cobori tiparul.
        $settledBefore = date('Y-m-d', strtotime('-' . self::SETTLE_DAYS . ' days'));

        $stats = [];
        foreach ($trips as $trip) {
            if ((string) $trip['data_inceput'] > $settledBefore) {
                continue;
            }
            $tripId = (int) $trip['id'];
            $type = (string) $trip['tip_transport'];
            $zone = (int) $trip['zona_distributie_id'];
            $loading = (int) ($trip['loc_incarcare_id'] ?? 0);
            $keys = ['z|' . $type . '|' . $zone . '|0'];
            if ($loading > 0) {
                $keys[] = 'r|' . $type . '|' . $zone . '|' . $loading;
            }
            foreach ($keys as $key) {
                $stats[$key]['total'] = ($stats[$key]['total'] ?? 0) + 1;
                foreach ($tripFees[$tripId] ?? [] as $feeType => $amounts) {
                    $stats[$key]['hits'][$feeType] = ($stats[$key]['hits'][$feeType] ?? 0) + 1;
                    foreach ($amounts as $amount) {
                        $bucket = number_format($amount, 2, '.', '');
                        $stats[$key]['amounts'][$feeType][$bucket] = ($stats[$key]['amounts'][$feeType][$bucket] ?? 0) + 1;
                    }
                }
            }
        }

        $patterns = [];
        $zoneCovered = [];
        // Intai tiparele pe loc de descarcare, ca rutele deja acoperite de ele sa nu se repete.
        foreach (['z', 'r'] as $level) {
            foreach ($stats as $key => $stat) {
                [$keyLevel, $transport, $zone, $loading] = explode('|', $key);
                if ($keyLevel !== $level) {
                    continue;
                }
                foreach ($stat['hits'] ?? [] as $feeType => $hits) {
                    $total = (int) $stat['total'];
                    $minTrips = $level === 'z' ? self::ZONE_MIN_TRIPS : self::ROUTE_MIN_TRIPS;
                    $minShare = $level === 'z' ? self::ZONE_MIN_SHARE : self::ROUTE_MIN_SHARE;
                    if ($total < $minTrips || $hits / $total < $minShare) {
                        continue;
                    }
                    if ($level === 'r' && isset($zoneCovered[$transport . '|' . $zone . '|' . $feeType])) {
                        continue;
                    }
                    if ($level === 'z') {
                        $zoneCovered[$transport . '|' . $zone . '|' . $feeType] = true;
                    }

                    $amounts = $stat['amounts'][$feeType] ?? [];
                    arsort($amounts);
                    $typical = $amounts !== [] ? (float) array_key_first($amounts) : 0.0;

                    $patterns[] = [
                        'zona_distributie_id' => (int) $zone,
                        'loc_incarcare_id' => $level === 'r' ? (int) $loading : null,
                        'tip_transport' => $transport,
                        'tip_cheltuiala' => $feeType,
                        'suma_uzuala' => $typical > 0 ? $typical : null,
                        'hits' => (int) $hits,
                        'total' => $total,
                    ];
                }
            }
        }

        return $patterns;
    }

    private function insertRule(array $data, string $source, ?int $userId): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            INSERT INTO reguli_taxe_refacturare
                (zona_distributie_id, loc_incarcare_id, tip_transport, tip_cheltuiala, suma_uzuala, observatii, activ, sursa, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['zona_distributie_id'], $data['loc_incarcare_id'], $data['tip_transport'], $data['tip_cheltuiala'],
            $data['suma_uzuala'], $data['observatii'] ?? null, (int) ($data['activ'] ?? 1), $source, $userId, $now, $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function findDuplicate(array $data, ?int $excludeId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT id FROM reguli_taxe_refacturare
            WHERE zona_distributie_id = ?
              AND loc_incarcare_id <=> ?
              AND tip_transport <=> ?
              AND tip_cheltuiala = ?
              AND id <> ?
            LIMIT 1
        ");
        $stmt->execute([$data['zona_distributie_id'], $data['loc_incarcare_id'], $data['tip_transport'], $data['tip_cheltuiala'], $excludeId ?? 0]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function ruleMatchesTrip(array $rule, array $trip): bool
    {
        return (int) $rule['zona_distributie_id'] === (int) $trip['zona_distributie_id']
            && ($rule['loc_incarcare_id'] === null || (int) $rule['loc_incarcare_id'] === (int) ($trip['loc_incarcare_id'] ?? 0))
            && ($rule['tip_transport'] === null || (string) $rule['tip_transport'] === (string) $trip['tip_transport']);
    }

    private function ruleCoversPattern(array $rule, array $pattern): bool
    {
        return (int) $rule['zona_distributie_id'] === (int) $pattern['zona_distributie_id']
            && (string) $rule['tip_cheltuiala'] === (string) $pattern['tip_cheltuiala']
            && ($rule['loc_incarcare_id'] === null || (int) $rule['loc_incarcare_id'] === (int) ($pattern['loc_incarcare_id'] ?? 0))
            && ($rule['tip_transport'] === null || (string) $rule['tip_transport'] === (string) $pattern['tip_transport']);
    }

    private function sortBySpecificity(array $rules): array
    {
        usort($rules, static function (array $a, array $b): int {
            $score = static fn(array $rule): int => ($rule['loc_incarcare_id'] !== null ? 2 : 0) + ($rule['tip_transport'] !== null ? 1 : 0);

            return $score($b) <=> $score($a);
        });

        return $rules;
    }

    private function exists(string $table, int $id): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    private function placeNames(): array
    {
        $label = static fn(array $row): string => (string) $row['nume'] . ($row['beneficiar'] ? ' (' . $row['beneficiar'] . ')' : '');
        $zones = [];
        foreach ($this->db->query("
            SELECT zd.id, zd.nume, b.nume AS beneficiar
            FROM configurare_zone_distributie zd
            LEFT JOIN configurare_beneficiari_transport b ON b.id = zd.beneficiar_id
        ")->fetchAll() as $row) {
            $zones[(int) $row['id']] = $label($row);
        }
        $locations = [];
        foreach ($this->db->query("
            SELECT li.id, li.nume, b.nume AS beneficiar
            FROM configurare_locuri_incarcare li
            LEFT JOIN configurare_beneficiari_transport b ON b.id = li.beneficiar_id
        ")->fetchAll() as $row) {
            $locations[(int) $row['id']] = $label($row);
        }

        return ['zones' => $zones, 'locations' => $locations];
    }
}
