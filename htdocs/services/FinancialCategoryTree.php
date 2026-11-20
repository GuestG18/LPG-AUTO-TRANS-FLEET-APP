<?php
declare(strict_types=1);

/**
 * Categoriile financiare ierarhice (tabela `financiar_categorii`, adancime nelimitata).
 *
 * Regula de baza: o categorie parinte NU are tranzactii proprii. Totalul unui nod =
 * SUMA tranzactiilor DISTINCTE din frunzele descendente — fiecare tranzactie se adauga o singura
 * data pe fiecare nod de pe drumul frunza → radacina, deci parintele = suma copiilor, fara dubluri.
 *
 * aggregate() este pur (primeste liniile deja citite), ca sa poata fi testat fara baza de date.
 */
final class FinancialCategoryTree
{
    /** Frunza folosita cand un tip de cost nu are inca subcategorie (tip nou, nemapat). */
    public const FALLBACK_LEAF = 'ach_alte_directe';

    /** @var array<int,array{id:int,parent_id:?int,cod:string,denumire:string,descriere:?string,ordine:int,activ:bool}> */
    private array $nodes = [];
    /** @var array<int,list<int>> */
    private array $children = [];

    public function __construct(private PDO $db)
    {
        foreach ($db->query('SELECT id, parent_id, cod, denumire, descriere, ordine, activ FROM financiar_categorii ORDER BY ordine, denumire, id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $this->nodes[$id] = [
                'id' => $id,
                'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'cod' => (string) $row['cod'],
                'denumire' => (string) $row['denumire'],
                'descriere' => $row['descriere'],
                'ordine' => (int) $row['ordine'],
                'activ' => (int) $row['activ'] === 1,
            ];
        }
        foreach ($this->nodes as $id => $node) {
            if ($node['parent_id'] !== null && isset($this->nodes[$node['parent_id']])) {
                $this->children[$node['parent_id']][] = $id;
            }
        }
    }

    public function node(int $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function idByCode(string $code): ?int
    {
        foreach ($this->nodes as $id => $node) {
            if ($node['cod'] === $code) {
                return $id;
            }
        }
        return null;
    }

    public function isLeaf(int $id): bool
    {
        return isset($this->nodes[$id]) && empty($this->children[$id]);
    }

    /** Frunza activa (cu toti stramosii activi) — singurul nivel pe care se pot inregistra tranzactii. */
    public function assertAssignableLeaf(int $id): void
    {
        if (!isset($this->nodes[$id])) {
            throw new InvalidArgumentException('Subcategoria financiară nu există.');
        }
        if (!$this->isLeaf($id)) {
            throw new InvalidArgumentException('„' . $this->nodes[$id]['denumire'] . '” este o categorie principală: alege o subcategorie.');
        }
        foreach ($this->path($id) as $nodeId) {
            if (!$this->nodes[$nodeId]['activ']) {
                throw new InvalidArgumentException('Subcategoria „' . $this->nodes[$id]['denumire'] . '” nu este activă.');
            }
        }
    }

    /** @return list<int> drumul radacina → nod (inclusiv), protejat la cicluri */
    public function path(int $id): array
    {
        $path = [];
        $seen = [];
        while ($id !== null && isset($this->nodes[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($path, $id);
            $id = $this->nodes[$id]['parent_id'];
        }
        return $path;
    }

    /** @return list<int> nodul si toti descendentii lui */
    public function descendants(int $id): array
    {
        $out = [];
        $stack = [$id];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (isset($out[$current]) || !isset($this->nodes[$current])) {
                continue;
            }
            $out[$current] = true;
            foreach ($this->children[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }
        return array_keys($out);
    }

    /**
     * Totaluri pe fiecare nod, din liniile unui dosar.
     *
     * Fiecare linie: id, leaf_id, net_ron (null = moneda fara curs), moneda, suma_originala,
     * tratament, status_verificare, clasificare, inclus (bool), anulat (bool), platit_ron, data.
     * - liniile anulate si cele „incluse in alta linie” nu se aduna (sunt deja in linia gazda);
     * - liniile de TVA (clasificare = tva) nu sunt cost: raman doar in TVA-ul dosarului;
     * - fara curs de schimb: NU se convertesc si NU se amesteca — apar separat pe moneda.
     *
     * @param list<array> $lines
     * @param array{0:?string,1:?string} $period optional [de_la, pana_la] pe data tranzactiei
     * @return array<int,array> id nod => totaluri
     */
    public function aggregate(array $lines, array $period = [null, null]): array
    {
        $totals = [];
        foreach ($this->nodes as $id => $node) {
            $totals[$id] = self::emptyTotals();
        }
        [$from, $to] = $period;
        $counted = [];
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            if (isset($counted[$lineId]) || !empty($line['anulat']) || !empty($line['inclus']) || ($line['clasificare'] ?? '') === 'tva') {
                continue;
            }
            if (($from !== null || $to !== null) && ($line['data'] ?? null) !== null
                && (($from !== null && $line['data'] < $from) || ($to !== null && $line['data'] > $to))) {
                continue;
            }
            $counted[$lineId] = true;
            foreach ($this->path((int) $line['leaf_id']) as $nodeId) {
                $t = &$totals[$nodeId];
                $t['linii']++;
                if ($line['net_ron'] === null) {
                    $currency = (string) $line['moneda'];
                    $t['fara_curs'][$currency] = round(($t['fara_curs'][$currency] ?? 0.0) + (float) $line['suma_originala'], 2);
                    unset($t);
                    continue;
                }
                $net = (float) $line['net_ron'];
                $t['total'] += $net;
                match ($line['tratament']) {
                    'capitalizabil' => $t['capitalizabil'] += $net,
                    'cheltuiala' => $t['necapitalizabil'] += $net,
                    default => $t['de_confirmat'] += $net,
                };
                if ($line['status_verificare'] === 'estimat') {
                    $t['neverificat'] += $net;
                }
                $t['platit'] += (float) ($line['platit_ron'] ?? 0.0);
                unset($t);
            }
        }
        foreach ($totals as &$t) {
            foreach (['total', 'capitalizabil', 'necapitalizabil', 'de_confirmat', 'neverificat', 'platit'] as $key) {
                $t[$key] = round($t[$key], 2);
            }
        }
        unset($t);
        return $totals;
    }

    /**
     * Arborele aplatizat (ordine de afisare, parinte inaintea copiilor) cu totalurile fiecarui nod.
     * Nodurile inactive apar doar daca au tranzactii (istoric).
     *
     * @param array<int,array> $totals rezultatul aggregate()
     * @return list<array>
     */
    public function flatten(array $totals): array
    {
        $out = [];
        $walk = function (int $id, int $depth) use (&$walk, &$out, $totals): void {
            $node = $this->nodes[$id];
            $t = $totals[$id] ?? self::emptyTotals();
            if (!$node['activ'] && $t['linii'] === 0) {
                return;
            }
            $out[] = $node + [
                'nivel' => $depth,
                'frunza' => $this->isLeaf($id),
                'copii' => $this->children[$id] ?? [],
                'totaluri' => $t,
            ];
            foreach ($this->children[$id] ?? [] as $child) {
                $walk($child, $depth + 1);
            }
        };
        foreach ($this->nodes as $id => $node) {
            if ($node['parent_id'] === null) {
                $walk($id, 0);
            }
        }
        return $out;
    }

    /** Frunza efectiva a unei linii: cea salvata, altfel subcategoria tipului, altfel frunza de rezerva. */
    public function resolveLeaf(?int $lineLeaf, ?int $typeLeaf): ?int
    {
        foreach ([$lineLeaf, $typeLeaf, $this->idByCode(self::FALLBACK_LEAF)] as $candidate) {
            if ($candidate !== null && isset($this->nodes[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    public static function emptyTotals(): array
    {
        return ['total' => 0.0, 'capitalizabil' => 0.0, 'necapitalizabil' => 0.0, 'de_confirmat' => 0.0, 'neverificat' => 0.0, 'platit' => 0.0, 'linii' => 0, 'fara_curs' => []];
    }
}
