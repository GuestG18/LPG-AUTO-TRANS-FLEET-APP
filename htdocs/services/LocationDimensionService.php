<?php
declare(strict_types=1);

/**
 * Locatii fizice pentru rapoarte (Origine / Destinatie / Depozit).
 *
 * Problema: cursa are loc_incarcare_id si zona_distributie_id, dar
 *   - sensul depinde de tipul de transport: la Primar locul = rafinarie / terminal, zona = DEPOZITUL de destinatie;
 *     la Distributie locul = DEPOZITUL din care se incarca, zona = regiunea livrata;
 *   - locurile si zonele sunt definite per beneficiar, deci acelasi loc real are mai multe id-uri
 *     (ex. Contesti = loc 56 si 112, zona 59 si 124).
 *
 * Aici fiecare rand din configurare_locuri_incarcare / configurare_zone_distributie e legat de UN loc fizic
 * (locatii_fizice_surse), iar rolul pe cursa se deduce din tipul de transport (roles()). Doar strat de raportare:
 * nu schimba tarifele, facturarea sau configurarea transportului.
 *
 * Legarea automata: o sursa noua se leaga de locul fizic care are deja o sursa cu acelasi nume normalizat
 * (fara diacritice / punctuatie / ordine a cuvintelor); altfel se creeaza un loc nou. Unirile facute manual
 * raman (o sursa noua cu acelasi nume ajunge in locul unit). Numele asemanatoare (un nume continut in altul,
 * ex. „Tileagd” / „MOL Tileagd”) NU se unesc automat: apar ca sugestii in ecranul de gestionare.
 */
final class LocationDimensionService
{
    public const DIMENSIONS = ['origine' => 'Loc încărcare', 'destinatie' => 'Zonă distribuție', 'depozit' => 'Depozit / Bază'];
    public const DIMENSION_HINTS = [
        'origine' => 'Unde s-a încărcat: rafinăria / terminalul la Primar, depozitul la Distribuție.',
        'destinatie' => 'Unde a mers marfa: depozitul la Primar, regiunea la Distribuție.',
        'depozit' => 'Depozitul căruia îi aparține cursa: unde descarcă Primar și de unde încarcă Distribuție. Primar + Distribuție și Compresor nu trec prin depozit.',
    ];
    /** Tipurile la care zona = depozitul de destinatie (restul: zona = regiune de livrare). */
    private const PRIMARY_TYPES = ['primar', 'primar_tona'];
    private const STOPWORDS = ['sa', 'srl', 'oil', 'depozit', 'terminal'];

    private ?array $resolver = null;
    private bool $synced = false;

    public function __construct(private PDO $db)
    {
    }

    public static function ensureSchema(PDO $db): void
    {
        try {
            $db->query('SELECT 1 FROM locatii_fizice_surse LIMIT 1');
            return; // exista deja: fara DDL (DDL face COMMIT implicit)
        } catch (PDOException) {
        }
        $sql =file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_11_25_000001_locatii_fizice.sql');
        if ($sql === false) {
            throw new RuntimeException('Migrarea locatii_fizice lipsește.');
        }
        foreach (preg_split('/;\s*(?:\r?\n|$)/', preg_replace('/^--.*$/m', '', $sql)) as $stmt) {
            if (trim($stmt) !== '') {
                $db->exec($stmt);
            }
        }
    }

    // ================================================================== rolurile pe cursa

    /**
     * Locurile fizice ale unei curse pe cele trei dimensiuni (null = nespecificat / nu se aplica).
     *
     * @return array{origine:?int,destinatie:?int,depozit:?int}
     */
    public function roles(string $type, int $locId, int $zonaId): array
    {
        $r = $this->resolver();
        $origin = $locId > 0 ? ($r['loc'][$locId] ?? null) : null;
        $dest = $zonaId > 0 ? ($r['zona'][$zonaId] ?? null) : null;
        $depot = match (true) {
            in_array($type, self::PRIMARY_TYPES, true) => $dest,
            $type === 'distributie' => $origin,
            default => null, // Primar + Distributie (livrare directa din rafinarie), Compresor
        };
        return ['origine' => $origin, 'destinatie' => $dest, 'depozit' => $depot];
    }

    /** @return array<int,array{id:int,nume:string,tip:string}> */
    public function places(): array
    {
        return $this->resolver()['places'];
    }

    public function placeName(?int $id): string
    {
        return $id === null || $id <= 0 ? 'Nespecificat' : ($this->places()[$id]['nume'] ?? ('Locație #' . $id));
    }

    /** @return array{loc:array<int,int>,zona:array<int,int>,places:array<int,array>} */
    private function resolver(): array
    {
        if ($this->resolver !== null) {
            return $this->resolver;
        }
        $this->sync();
        $out = ['loc' => [], 'zona' => [], 'places' => []];
        foreach ($this->db->query('SELECT id, nume, tip FROM locatii_fizice ORDER BY nume')->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $out['places'][(int) $p['id']] = ['id' => (int) $p['id'], 'nume' => (string) $p['nume'], 'tip' => (string) $p['tip']];
        }
        foreach ($this->db->query('SELECT sursa, sursa_id, locatie_id FROM locatii_fizice_surse')->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $out[$s['sursa']][(int) $s['sursa_id']] = (int) $s['locatie_id'];
        }
        return $this->resolver = $out;
    }

    // ================================================================== legarea automata

    public static function normalize(string $name): string
    {
        $s = mb_strtolower(trim($name), 'UTF-8');
        $s = strtr($s, ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't', 'é' => 'e', 'ö' => 'o', 'ü' => 'u']);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
        $tokens = array_values(array_filter(explode(' ', $s), static fn (string $t): bool => $t !== ''));
        sort($tokens);
        return implode(' ', $tokens);
    }

    /** @return list<string> cuvintele semnificative (pentru sugestii) */
    private static function tokens(string $name): array
    {
        $key = self::normalize($name);
        return $key === '' ? [] : array_values(array_diff(explode(' ', $key), self::STOPWORDS));
    }

    /**
     * Leaga sursele noi (locuri / zone fara loc fizic). Idempotent; o singura data pe instanta.
     * @return int cate surse noi au fost legate
     */
    public function sync(): int
    {
        if ($this->synced) {
            return 0;
        }
        $this->synced = true;
        self::ensureSchema($this->db);
        $sources = $this->sources();
        $pending = array_filter($sources, static fn (array $s): bool => $s['locatie_id'] === null);
        if ($pending === []) {
            return 0;
        }
        // cheie normalizata -> loc fizic, din sursele deja legate (respecta unirile manuale)
        $byKey = [];
        foreach ($sources as $s) {
            if ($s['locatie_id'] !== null) {
                $byKey[self::normalize($s['nume'])] ??= $s['locatie_id'];
            }
        }
        $groups = [];
        foreach ($pending as $s) {
            $groups[self::normalize($s['nume'])][] = $s;
        }
        $usage = $this->usage();
        $insPlace = $this->db->prepare('INSERT INTO locatii_fizice (nume, tip) VALUES (:n, :t)');
        $insSource = $this->db->prepare('INSERT IGNORE INTO locatii_fizice_surse (sursa, sursa_id, locatie_id, manual) VALUES (:s, :id, :l, 0)');
        $count = 0;
        foreach ($groups as $key => $list) {
            $placeId = $byKey[$key] ?? null;
            if ($placeId === null) {
                $insPlace->execute([':n' => $this->displayName($list), ':t' => $this->guessType($list, $usage)]);
                $placeId = (int) $this->db->lastInsertId();
                $byKey[$key] = $placeId;
            }
            foreach ($list as $s) {
                $insSource->execute([':s' => $s['sursa'], ':id' => $s['sursa_id'], ':l' => $placeId]);
                $count += $insSource->rowCount();
            }
        }
        $this->resolver = null;
        return $count;
    }

    /** Numele afisat: varianta cea mai folosita (cu diacritice / majuscule) dintre sursele grupului. */
    private function displayName(array $list): string
    {
        $names = [];
        foreach ($list as $s) {
            $names[trim($s['nume'])] = ($names[trim($s['nume'])] ?? 0) + 1;
        }
        arsort($names);
        return (string) array_key_first($names);
    }

    /**
     * Regiune = folosita pe curse doar ca zona de Distributie (Sud, Moldova, All RO...); orice folosire
     * ca loc de incarcare sau ca destinatie Primar inseamna un punct fizic (rafinarie, depozit).
     */
    private function guessType(array $list, array $usage): string
    {
        $asRegion = false;
        foreach ($list as $s) {
            $u = $usage[$s['sursa']][$s['sursa_id']] ?? [];
            if ($s['sursa'] === 'loc' && array_sum($u) > 0) {
                return 'punct';
            }
            if ($s['sursa'] === 'zona') {
                foreach ($u as $type => $n) {
                    if (in_array($type, self::PRIMARY_TYPES, true) && $n > 0) {
                        return 'punct';
                    }
                    $asRegion = $asRegion || $n > 0;
                }
            }
        }
        $allZones = array_filter($list, static fn (array $s): bool => $s['sursa'] !== 'zona') === [];
        return $asRegion || $allZones ? 'regiune' : 'punct';
    }

    /** @return list<array{sursa:string,sursa_id:int,nume:string,beneficiar_id:int,activ:int,locatie_id:?int,manual:int}> */
    private function sources(): array
    {
        $rows = $this->db->query(
            "SELECT 'loc' AS sursa, l.id AS sursa_id, l.nume, l.beneficiar_id, l.activ, s.locatie_id, COALESCE(s.manual, 0) AS manual
               FROM configurare_locuri_incarcare l
               LEFT JOIN locatii_fizice_surse s ON s.sursa = 'loc' AND s.sursa_id = l.id
             UNION ALL
             SELECT 'zona', z.id, z.nume, z.beneficiar_id, z.activ, s.locatie_id, COALESCE(s.manual, 0)
               FROM configurare_zone_distributie z
               LEFT JOIN locatii_fizice_surse s ON s.sursa = 'zona' AND s.sursa_id = z.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => [
            'sursa' => (string) $r['sursa'], 'sursa_id' => (int) $r['sursa_id'], 'nume' => (string) $r['nume'], 'beneficiar_id' => (int) $r['beneficiar_id'],
            'activ' => (int) $r['activ'], 'locatie_id' => $r['locatie_id'] === null ? null : (int) $r['locatie_id'], 'manual' => (int) $r['manual'],
        ], $rows);
    }

    /** @return array{loc:array<int,array<string,int>>,zona:array<int,array<string,int>>} curse pe sursa si tip transport */
    private function usage(): array
    {
        $out = ['loc' => [], 'zona' => []];
        $rows = $this->db->query(
            "SELECT 'loc' AS sursa, loc_incarcare_id AS id, tip_transport, COUNT(*) AS n FROM curse_dispecer
              WHERE deleted_at IS NULL AND loc_incarcare_id IS NOT NULL GROUP BY loc_incarcare_id, tip_transport
             UNION ALL
             SELECT 'zona', zona_distributie_id, tip_transport, COUNT(*) FROM curse_dispecer
              WHERE deleted_at IS NULL AND zona_distributie_id IS NOT NULL GROUP BY zona_distributie_id, tip_transport"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out[$r['sursa']][(int) $r['id']][(string) $r['tip_transport']] = (int) $r['n'];
        }
        return $out;
    }

    // ================================================================== gestionare (ecranul „Locații fizice”)

    /**
     * Locurile fizice cu sursele lor (loc / zona, beneficiar, curse) + sugestii de unire (nume continut in altul).
     */
    public function overview(): array
    {
        $this->sync();
        $benef = array_column($this->db->query('SELECT id, nume FROM configurare_beneficiari_transport')->fetchAll(PDO::FETCH_ASSOC), 'nume', 'id');
        $usage = $this->usage();
        $places = [];
        foreach ($this->places() as $id => $p) {
            $places[$id] = $p + ['surse' => [], 'curse' => 0, 'roluri' => []];
        }
        foreach ($this->sources() as $s) {
            if ($s['locatie_id'] === null || !isset($places[$s['locatie_id']])) {
                continue;
            }
            $u = $usage[$s['sursa']][$s['sursa_id']] ?? [];
            $places[$s['locatie_id']]['surse'][] = [
                'sursa' => $s['sursa'], 'sursa_id' => $s['sursa_id'], 'nume' => $s['nume'], 'activ' => $s['activ'], 'manual' => $s['manual'],
                'beneficiar' => (string) ($benef[$s['beneficiar_id']] ?? ('#' . $s['beneficiar_id'])), 'curse' => array_sum($u),
                'sursa_label' => $s['sursa'] === 'loc' ? 'Loc încărcare' : 'Zonă distribuție',
            ];
            $places[$s['locatie_id']]['curse'] += array_sum($u);
            foreach ($u as $type => $n) {
                if ($n <= 0) {
                    continue;
                }
                $role = $s['sursa'] === 'loc'
                    ? ($type === 'distributie' ? 'Depozit (încărcare Distribuție)' : 'Origine Primar')
                    : (in_array($type, self::PRIMARY_TYPES, true) ? 'Depozit (descărcare Primar)' : 'Regiune Distribuție');
                $places[$s['locatie_id']]['roluri'][$role] = true;
            }
        }
        foreach ($places as &$p) {
            $p['roluri'] = array_keys($p['roluri']);
            usort($p['surse'], static fn (array $a, array $b): int => $b['curse'] <=> $a['curse'] ?: strcmp($a['beneficiar'], $b['beneficiar']));
        }
        unset($p);

        // sugestii: cuvintele unui loc sunt toate in numele altui loc (Tileagd ⊂ MOL Tileagd)
        $suggestions = [];
        $tokens = array_map(static fn (array $p): array => self::tokens($p['nume']), $places);
        foreach ($places as $a => $pa) {
            foreach ($places as $b => $pb) {
                if ($a === $b || $tokens[$a] === [] || $pa['tip'] !== $pb['tip']) {
                    continue;
                }
                // submultime stricta, sau aceleasi cuvinte semnificative („Galati Unicom” / „Unicom Oil Galati”) o singura data
                $subset = array_diff($tokens[$a], $tokens[$b]) === [];
                $same = $subset && array_diff($tokens[$b], $tokens[$a]) === [];
                if ($subset && (!$same || $a < $b)) {
                    $suggestions[] = ['from' => $a, 'into' => $b, 'text' => '„' . $pa['nume'] . '” poate fi același loc cu „' . $pb['nume'] . '”'];
                }
            }
        }
        uasort($places, static fn (array $x, array $y): int => $y['curse'] <=> $x['curse'] ?: strcmp($x['nume'], $y['nume']));
        return ['places' => array_values($places), 'suggestions' => $suggestions];
    }

    public function updatePlace(int $id, string $name, string $type): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Numele locației este obligatoriu (cel mult 120 de caractere).');
        }
        if (!in_array($type, ['punct', 'regiune'], true)) {
            throw new InvalidArgumentException('Tip de locație necunoscut.');
        }
        $this->requirePlace($id);
        $this->db->prepare('UPDATE locatii_fizice SET nume = :n, tip = :t WHERE id = :id')->execute([':n' => $name, ':t' => $type, ':id' => $id]);
        $this->resolver = null;
    }

    /**
     * Muta o sursa (loc / zona) la alt loc fizic; $target = 0 o scoate intr-un loc nou, cu numele ei.
     * Locul ramas fara surse se sterge. @return int locul fizic tinta
     */
    public function moveSource(string $source, int $sourceId, int $target): int
    {
        if (!in_array($source, ['loc', 'zona'], true)) {
            throw new InvalidArgumentException('Sursă necunoscută.');
        }
        $this->sync();
        $cur = $this->db->prepare('SELECT locatie_id FROM locatii_fizice_surse WHERE sursa = :s AND sursa_id = :id');
        $cur->execute([':s' => $source, ':id' => $sourceId]);
        $old = $cur->fetchColumn();
        if ($old === false) {
            throw new InvalidArgumentException('Locul / zona nu există.');
        }
        $this->db->beginTransaction();
        try {
            if ($target <= 0) {
                $table = $source === 'loc' ? 'configurare_locuri_incarcare' : 'configurare_zone_distributie';
                $name = $this->db->prepare("SELECT nume FROM $table WHERE id = :id");
                $name->execute([':id' => $sourceId]);
                $type = $this->db->prepare('SELECT tip FROM locatii_fizice WHERE id = :id');
                $type->execute([':id' => (int) $old]);
                $this->db->prepare('INSERT INTO locatii_fizice (nume, tip) VALUES (:n, :t)')
                    ->execute([':n' => trim((string) $name->fetchColumn()), ':t' => (string) ($type->fetchColumn() ?: 'punct')]);
                $target = (int) $this->db->lastInsertId();
            } else {
                $this->requirePlace($target);
            }
            $this->db->prepare('UPDATE locatii_fizice_surse SET locatie_id = :l, manual = 1 WHERE sursa = :s AND sursa_id = :id')
                ->execute([':l' => $target, ':s' => $source, ':id' => $sourceId]);
            $this->dropIfEmpty((int) $old);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->resolver = null;
        return $target;
    }

    /** Uneste locul $from in $into (toate sursele lui trec la $into; $from se sterge). */
    public function merge(int $from, int $into): void
    {
        if ($from === $into) {
            throw new InvalidArgumentException('Alege două locații diferite.');
        }
        $this->requirePlace($from);
        $this->requirePlace($into);
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE locatii_fizice_surse SET locatie_id = :into, manual = 1 WHERE locatie_id = :from')->execute([':into' => $into, ':from' => $from]);
            $this->db->prepare('DELETE FROM locatii_fizice WHERE id = :id')->execute([':id' => $from]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        $this->resolver = null;
    }

    private function requirePlace(int $id): void
    {
        $st = $this->db->prepare('SELECT 1 FROM locatii_fizice WHERE id = :id');
        $st->execute([':id' => $id]);
        if ($st->fetchColumn() === false) {
            throw new InvalidArgumentException('Locația nu există.');
        }
    }

    private function dropIfEmpty(int $id): void
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM locatii_fizice_surse WHERE locatie_id = :id');
        $st->execute([':id' => $id]);
        if ((int) $st->fetchColumn() === 0) {
            $this->db->prepare('DELETE FROM locatii_fizice WHERE id = :id')->execute([':id' => $id]);
        }
    }
}
