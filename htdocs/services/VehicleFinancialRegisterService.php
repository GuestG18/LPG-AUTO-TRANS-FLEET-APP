<?php
declare(strict_types=1);

require_once __DIR__ . '/FinancialFoundationSchema.php';
require_once __DIR__ . '/CostNature.php';
require_once __DIR__ . '/FinancialAuditLog.php';
require_once __DIR__ . '/VehicleAcquisitionService.php';
require_once __DIR__ . '/FinancialCategoryTree.php';
require_once __DIR__ . '/InvestmentProjectService.php';
require_once __DIR__ . '/VehicleFinancialHistoryService.php';

/**
 * Modelul de citire al paginii „Istoric Financiar Vehicul” (?page=istoric_financiar).
 *
 * Nu calculeaza nimic propriu: toate sumele vin din VehicleAcquisitionService::summary()
 * (dosare), InvestmentProjectService::totals() (proiecte) si VehicleFinancialHistoryService (istoric).
 * Lista = dosarele de achizitie + vehiculele flotei fara dosar (marcate „Fără dosar”).
 */
final class VehicleFinancialRegisterService
{
    public const STATUS_LABELS = [
        'in_analiza' => 'În analiză',
        'contractat' => 'Contractat',
        'in_plata' => 'În plată',
        'in_transport' => 'În transport',
        'receptionat' => 'Recepționat',
        'inmatriculat' => 'Înmatriculat',
        'in_adaptare' => 'În adaptare',
        'pus_in_functiune' => 'Finalizat',
        'anulat' => 'Anulat',
        'fara_dosar' => 'Fără dosar',
    ];
    public const TYPE_LABELS = [
        'cap_tractor' => 'Cap tractor', 'camion' => 'Camion', 'semiremorca' => 'Semiremorcă',
        'semiremorca_primar' => 'Semiremorcă primar', 'semiremorca_distributie' => 'Semiremorcă distribuție',
        'remorca' => 'Remorcă', 'autoutilitara' => 'Autoutilitară', 'autovehicul' => 'Autovehicul', 'altul' => 'Alt tip',
    ];
    public const STAGES = [
        'vanzator' => 'Vânzător & Vehicul',
        'contract' => 'Contract & Factură',
        'plati' => 'Plăți',
        'transport' => 'Transport',
        'receptie' => 'Recepție',
        'inmatriculare' => 'RAR & Înmatriculare',
        'exploatare' => 'Punere în exploatare',
    ];
    public const TREATMENT_LABELS = ['capitalizabil' => 'Capitalizabil', 'cheltuiala' => 'Chelt. exploatare', 'de_confirmat' => 'De confirmat'];
    private const FINISHED = 'pus_in_functiune';

    private VehicleAcquisitionService $acquisitions;
    private InvestmentProjectService $projects;

    public function __construct(private PDO $db)
    {
        $this->acquisitions = new VehicleAcquisitionService($db);
        $this->projects = new InvestmentProjectService($db);
    }

    // ------------------------------------------------------------------ lista

    /**
     * @param array{q?:string,tab?:string,status?:string,tara?:string,perioada?:string,page?:int,per_page?:int} $f
     */
    public function list(array $f): array
    {
        $rows = $this->allRows();
        $countries = [];
        foreach ($rows as $row) {
            if ($row['tara'] !== null) {
                $countries[$row['tara']] = true;
            }
        }

        $q = mb_strtolower(trim((string) ($f['q'] ?? '')), 'UTF-8');
        $status = (string) ($f['status'] ?? '');
        $country = strtoupper((string) ($f['tara'] ?? ''));
        [$from, $to] = $this->periodBounds((string) ($f['perioada'] ?? ''));
        $filtered = array_values(array_filter($rows, function (array $row) use ($q, $status, $country, $from, $to): bool {
            if ($q !== '' && !str_contains($row['_search'], $q)) {
                return false;
            }
            if ($status !== '' && $row['status'] !== $status) {
                return false;
            }
            if ($country !== '' && $row['tara'] !== $country) {
                return false;
            }
            if ($from !== null && ($row['data_achizitie'] === null || $row['data_achizitie'] < $from || $row['data_achizitie'] > $to)) {
                return false;
            }
            return true;
        }));

        $counts = ['toate' => count($filtered), 'derulare' => 0, 'finalizate' => 0];
        foreach ($filtered as $row) {
            if ($row['acquisition_id'] === null || $row['status'] === 'anulat') {
                continue;
            }
            $row['status'] === self::FINISHED ? $counts['finalizate']++ : $counts['derulare']++;
        }
        $tab = (string) ($f['tab'] ?? 'toate');
        $visible = array_values(array_filter($filtered, static fn (array $row): bool => match ($tab) {
            'derulare' => $row['acquisition_id'] !== null && !in_array($row['status'], [self::FINISHED, 'anulat'], true),
            'finalizate' => $row['status'] === self::FINISHED,
            default => true,
        }));
        // Categoria aleasa in cardul „Valoare initiala” restrange DOAR tabelul la dosarele cu costuri in ea;
        // cardurile KPI raman pe scopul paginii (cardul arata el insusi totalul categoriei).
        $finScope = null;
        $finNode = (int) ($f['fin_node'] ?? 0);
        $finType = (string) ($f['fin_tip'] ?? '');
        if ($finNode > 0) {
            $finScope = $this->categoryScope($visible, $finNode, $finType !== '' ? $finType : null);
        }

        $kpis = ['vehicule' => 0, 'valoare_initiala' => 0.0, 'capitalizat' => 0.0, 'necapitalizabile' => 0.0, 'derulare' => $counts['derulare'], 'dosare' => 0, 'tratament_neconfirmat' => 0.0, 'dosare_incomplete' => 0];
        $vehicles = [];
        foreach ($visible as $row) {
            if ($row['vehicle_id'] !== null) {
                $vehicles[$row['vehicle_id']] = true;
            }
            if ($row['acquisition_id'] === null || $row['status'] === 'anulat') {
                continue;
            }
            $kpis['dosare']++;
            if ((int) $row['incomplet'] > 0) {
                $kpis['dosare_incomplete']++; // completitudinea afisata in cardul „Valoare initiala”
            }
            $kpis['valoare_initiala'] += $row['cost_initial'];
            $kpis['capitalizat'] += $row['capitalizat'];
            $kpis['necapitalizabile'] += $row['necapitalizabile'];
            $kpis['tratament_neconfirmat'] += $row['tratament_neconfirmat'];
        }
        $kpis['vehicule'] = count($vehicles) + count(array_filter($visible, static fn ($r) => $r['vehicle_id'] === null));
        foreach (['valoare_initiala', 'capitalizat', 'necapitalizabile', 'tratament_neconfirmat'] as $key) {
            $kpis[$key] = round($kpis[$key], 2);
        }
        $kpis['capitalizat_vs_initial'] = $kpis['valoare_initiala'] > 0 ? round(($kpis['capitalizat'] - $kpis['valoare_initiala']) / $kpis['valoare_initiala'] * 100, 1) : null;

        $tableRows = $finScope !== null
            ? array_values(array_filter($visible, static fn (array $row): bool => isset($finScope['acquisitions'][(int) $row['acquisition_id']])))
            : $visible;
        $perPage = (int) ($f['per_page'] ?? 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
        $pages = max(1, (int) ceil(count($tableRows) / $perPage));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $slice = array_slice($tableRows, ($page - 1) * $perPage, $perPage);

        ksort($countries);
        return [
            'kpis' => $kpis,
            'counts' => $counts,
            'rows' => array_map(static function (array $row): array {
                unset($row['_search']);
                return $row;
            }, $slice),
            'total' => count($tableRows),
            '_visible' => $visible,
            'categorie' => $finScope !== null ? ['node' => $finNode, 'tip' => $finType, 'label' => $finScope['label'], 'total' => $finScope['total'], 'dosare' => count($finScope['acquisitions'])] : null,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'tari' => array_keys($countries),
        ];
    }

    /** Dosarele (cu totalurile lor) + vehiculele flotei fara dosar. */
    private function allRows(): array
    {
        $rows = [];
        $searchExtra = [];
        foreach ($this->db->query(
            "SELECT l.achizitie_id, GROUP_CONCAT(CONCAT_WS(' ', l.furnizor, l.document_numar, l.descriere) SEPARATOR ' ') AS t
               FROM achizitii_vehicule_cheltuieli l GROUP BY l.achizitie_id"
        )->fetchAll(PDO::FETCH_ASSOC) as $extra) {
            $searchExtra[(int) $extra['achizitie_id']] = (string) $extra['t'];
        }
        foreach ($this->db->query(
            "SELECT a.*, v.nr_inmatriculare, v.marca AS v_marca, v.model AS v_model, v.tip_vehicul AS v_tip, v.serie_sasiu,
                    (SELECT MIN(e.data_eveniment) FROM achizitii_vehicule_evenimente e WHERE e.achizitie_id = a.id AND e.tip = 'achizitie') AS ev_achizitie
               FROM achizitii_vehicule a LEFT JOIN vehicule v ON v.id = a.vehicle_id
              ORDER BY a.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $summary = $this->acquisitions->summary((int) $a['id']);
            $id = (int) $a['id'];
            $date = $a['data_factura'] ?: ($a['data_contract'] ?: ($a['ev_achizitie'] ?: null));
            $plate = $a['nr_inmatriculare'] ?? null;
            $rows[] = [
                'key' => 'a' . $id,
                'acquisition_id' => $id,
                'vehicle_id' => $a['vehicle_id'] !== null ? (int) $a['vehicle_id'] : null,
                'cod_dosar' => (string) $a['cod_dosar'],
                'nr' => $plate ?? $a['cod_dosar'],
                'provizoriu' => $plate === null,
                'model' => trim(($a['v_marca'] ?? $a['marca'] ?? '') . ' ' . ($a['v_model'] ?? $a['model'] ?? '')),
                'tip' => (string) ($a['v_tip'] ?? $a['tip_vehicul']),
                'tip_label' => self::TYPE_LABELS[(string) ($a['v_tip'] ?? $a['tip_vehicul'])] ?? (string) $a['tip_vehicul'],
                'vin' => $a['vin'] ?? $a['serie_sasiu'] ?? null,
                'tara' => $a['tara_achizitie'] ?? $a['vanzator_tara'] ?? null,
                'data_achizitie' => $date,
                'pret_achizitie' => $summary['pret_achizitie'],
                'costuri_aducere' => $summary['cheltuieli_asociate'],
                'cost_initial' => round($summary['pret_achizitie'] + $summary['cheltuieli_asociate'], 2),
                'capitalizat' => $summary['cost_initial_capitalizabil'],
                'necapitalizabile' => $summary['necapitalizabile'],
                'tratament_neconfirmat' => $summary['tratament_neconfirmat'],
                'incomplet' => count($summary['incomplet']),
                'status' => (string) $a['status'],
                'status_label' => self::STATUS_LABELS[(string) $a['status']] ?? (string) $a['status'],
                '_search' => mb_strtolower(implode(' ', [$plate, $a['cod_dosar'], $a['vin'], $a['serie_sasiu'], $a['vanzator_nume'], $a['marca'], $a['model'], $a['v_marca'], $a['v_model'], $searchExtra[$id] ?? '']), 'UTF-8'),
            ];
        }
        // Dosarele in ordinea achizitiei (cele fara data — inca in analiza — la final), apoi vehiculele fara dosar.
        usort($rows, static fn (array $x, array $y): int => [$x['data_achizitie'] === null, (string) $x['data_achizitie'], $x['acquisition_id']]
            <=> [$y['data_achizitie'] === null, (string) $y['data_achizitie'], $y['acquisition_id']]);
        foreach ($this->db->query(
            "SELECT v.id, v.nr_inmatriculare, v.marca, v.model, v.tip_vehicul, v.serie_sasiu
               FROM vehicule v
              WHERE v.status = 'activ' AND v.nr_inmatriculare <> 'STOC-ANVELOPE'
                AND NOT EXISTS (SELECT 1 FROM achizitii_vehicule a WHERE a.vehicle_id = v.id AND a.status <> 'anulat')
              ORDER BY FIELD(v.tip_vehicul, 'cap_tractor', 'camion', 'semiremorca', 'semiremorca_primar', 'semiremorca_distributie', 'autoutilitara', 'autovehicul'), v.nr_inmatriculare"
        )->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $rows[] = [
                'key' => 'v' . (int) $v['id'],
                'acquisition_id' => null,
                'vehicle_id' => (int) $v['id'],
                'cod_dosar' => null,
                'nr' => (string) $v['nr_inmatriculare'],
                'provizoriu' => false,
                'model' => trim($v['marca'] . ' ' . $v['model']),
                'tip' => (string) $v['tip_vehicul'],
                'tip_label' => self::TYPE_LABELS[(string) $v['tip_vehicul']] ?? (string) $v['tip_vehicul'],
                'vin' => $v['serie_sasiu'] ?: null,
                'tara' => null,
                'data_achizitie' => null,
                'pret_achizitie' => null,
                'costuri_aducere' => null,
                'cost_initial' => null,
                'capitalizat' => 0.0,
                'necapitalizabile' => 0.0,
                'tratament_neconfirmat' => 0.0,
                'incomplet' => 0,
                'status' => 'fara_dosar',
                'status_label' => self::STATUS_LABELS['fara_dosar'],
                '_search' => mb_strtolower(implode(' ', [$v['nr_inmatriculare'], $v['serie_sasiu'], $v['marca'], $v['model']]), 'UTF-8'),
            ];
        }
        return $rows;
    }

    /**
     * Defalcarea cardului „Valoare initiala (total)” (navigare in card, dimensiuni fixe):
     *   categorii principale → subcategorii (copiii din financiar_categorii) → tipuri de cost → tranzactii.
     * Scopul = dosarele vizibile cu filtrele paginii (ca KPI-ul valoare_initiala). Totalurile vin din
     * FinancialCategoryTree::aggregate peste liniile dosarelor (aceleasi excluderi ca summary(): anulate, incluse
     * in alta linie, TVA, fara curs), deci suma categoriilor se reconciliaza cu valoarea de la nivelul 0;
     * o diferenta se raporteaza explicit (nu se ajusteaza).
     *
     * @param ?int    $node nodul ales (null = nivelul de sus)
     * @param ?string $type tipul de cost ales in frunza (null = inca nu)
     */
    public function initialValueBreakdown(array $f, ?int $node = null, ?string $type = null): array
    {
        $visible = $this->visibleRows($f);
        $dossiers = array_values(array_filter($visible, static fn (array $r): bool => $r['acquisition_id'] !== null && $r['status'] !== 'anulat'));
        $level0 = round(array_sum(array_map(static fn (array $r): float => (float) $r['cost_initial'], $dossiers)), 2);
        $tree = new FinancialCategoryTree($this->db);
        $lines = $this->collectLines($dossiers, $tree);
        $flat = $tree->flatten($tree->aggregate($this->linesForTotals($lines)));
        $byId = array_column($flat, null, 'id');
        $rootsWithData = array_values(array_filter($flat, static fn (array $n): bool => $n['nivel'] === 0 && $n['totaluri']['linii'] > 0));
        $rootsTotal = round(array_sum(array_map(static fn (array $n): float => $n['totaluri']['total'], $rootsWithData)), 2);
        $noRate = [];
        foreach ($rootsWithData as $r) {
            foreach ($r['totaluri']['fara_curs'] as $cur => $amount) {
                $noRate[$cur] = round(($noRate[$cur] ?? 0) + $amount, 2);
            }
        }
        $out = [
            'valoare_initiala' => $level0,
            'reconciliere' => ['total_categorii' => $rootsTotal, 'diferenta' => round($level0 - $rootsTotal, 2), 'fara_curs' => $noRate],
            'dosare' => count($dossiers),
            'dosare_incomplete' => count(array_filter($dossiers, static fn (array $r): bool => (int) $r['incomplet'] > 0)),
        ];

        // nivelul de sus: o singura radacina cu date (ex. „Achizitie si punere in exploatare”) se sare transparent
        if ($node === null) {
            if (count($rootsWithData) === 1) {
                $node = (int) $rootsWithData[0]['id'];
                $out['radacina_sarita'] = $rootsWithData[0]['denumire'];
            } else {
                return $out + ['nivel' => 'categorii', 'titlu' => 'Valoare inițială', 'total' => $level0, 'cale' => [],
                    'randuri' => array_map(fn (array $n): array => $this->nodeRow($n, $level0), $rootsWithData)];
            }
        }
        $current = $byId[$node] ?? throw new InvalidArgumentException('Categoria nu există sau nu are costuri în scop.');
        $path = array_map(static fn (int $id): array => ['id' => $id, 'label' => (string) ($byId[$id]['denumire'] ?? '')], $tree->path($node));
        $total = (float) $current['totaluri']['total'];
        $out += ['node' => $node, 'cale' => $path, 'titlu' => $current['denumire'], 'total' => $total];

        if (!$current['frunza']) {
            $children = array_values(array_filter(array_map(static fn (int $id): ?array => $byId[$id] ?? null, $current['copii']),
                static fn (?array $n): bool => $n !== null && $n['totaluri']['linii'] > 0));
            return $out + ['nivel' => 'categorii', 'randuri' => array_map(fn (array $n): array => $this->nodeRow($n, $total), $children)];
        }

        // frunza: tipurile de cost ale liniilor ei (doar cele cu inregistrari); cu un singur tip → direct tranzactiile
        $leafLines = array_values(array_filter($lines, static fn (array $l): bool => in_array($node, $l['fin_cale'], true) && !$l['anulat']));
        $types = [];
        foreach ($leafLines as $l) {
            $types[$l['categorie']] ??= ['kind' => 'tip', 'id' => $l['categorie'], 'label' => $l['categorie_label'], 'total' => 0.0, 'count' => 0];
            $types[$l['categorie']]['count']++;
            if (self::countsInValue($l)) {
                $types[$l['categorie']]['total'] += (float) $l['suma'];
            }
        }
        if ($type === null && count($types) > 1) {
            $rows = array_values($types);
            usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcmp($a['label'], $b['label']));
            foreach ($rows as &$r) {
                $r['total'] = round($r['total'], 2);
                $r['share'] = $total > 0 ? $r['total'] / $total * 100 : null;
            }
            unset($r);
            return $out + ['nivel' => 'tipuri', 'randuri' => $rows];
        }
        if ($type !== null) {
            if (!isset($types[$type])) {
                throw new InvalidArgumentException('Tipul de cost nu are înregistrări în această categorie.');
            }
            $leafLines = array_values(array_filter($leafLines, static fn (array $l): bool => $l['categorie'] === $type));
            $out['tip'] = ['id' => $type, 'label' => $types[$type]['label']];
            $out['titlu'] = $types[$type]['label'];
            $out['total'] = round($types[$type]['total'], 2);
        } elseif (count($types) === 1) {
            $only = array_key_first($types);
            $out['tip'] = ['id' => $only, 'label' => $types[$only]['label'], 'unic' => true];
        }
        usort($leafLines, static fn (array $a, array $b): int => strcmp((string) $b['data'], (string) $a['data']) ?: $b['id'] <=> $a['id']);
        return $out + ['nivel' => 'tranzactii', 'randuri' => array_map(static fn (array $l): array => [
            'kind' => 'linie', 'id' => $l['id'], 'data' => $l['data'], 'descriere' => $l['descriere'] ?: $l['categorie_label'], 'furnizor' => $l['furnizor'],
            'suma' => $l['suma'], 'suma_originala' => $l['suma_originala'], 'moneda' => $l['moneda'], 'curs' => $l['curs'],
            'tratament' => $l['tratament'], 'tratament_label' => $l['tratament_label'], 'status_verificare' => $l['status_verificare'],
            'document' => trim(($l['document_tip'] ? $l['document_tip'] . ' ' : '') . (string) ($l['document_numar'] ?? '')), 'are_fisier' => $l['are_fisier'],
            'dosar' => $l['_dosar'], 'dosar_key' => 'a' . $l['_acq'],
            'in_valoare' => self::countsInValue($l),
            'nota' => $l['inclus_in'] ? 'inclusă în linia #' . $l['inclus_in'] : ($l['clasificare'] === 'tva' ? 'TVA — nu intră în valoare' : ($l['suma'] === null ? 'fără curs ' . $l['moneda'] : '')),
        ], $leafLines)];
    }

    /** O linie intra in valoarea initiala: neanulata, neinclusa in alta, nu e TVA, are curs (aceleasi reguli ca summary()). */
    private static function countsInValue(array $l): bool
    {
        return !$l['anulat'] && $l['inclus_in'] === null && $l['clasificare'] !== 'tva' && $l['suma'] !== null;
    }

    private function nodeRow(array $n, float $parentTotal): array
    {
        return ['kind' => 'node', 'id' => (int) $n['id'], 'label' => $n['denumire'], 'total' => $n['totaluri']['total'], 'count' => $n['totaluri']['linii'],
            'share' => $parentTotal > 0 ? $n['totaluri']['total'] / $parentTotal * 100 : null, 'frunza' => $n['frunza'],
            'fara_curs' => $n['totaluri']['fara_curs']];
    }

    /** Dosarele (din randurile date) cu costuri care intra in valoare in categoria / tipul ales + totalul lor. */
    private function categoryScope(array $rows, int $node, ?string $type): array
    {
        $dossiers = array_values(array_filter($rows, static fn (array $r): bool => $r['acquisition_id'] !== null && $r['status'] !== 'anulat'));
        $tree = new FinancialCategoryTree($this->db);
        $acq = [];
        $total = 0.0;
        foreach ($this->collectLines($dossiers, $tree) as $l) {
            if (!in_array($node, $l['fin_cale'], true) || ($type !== null && $l['categorie'] !== $type) || !self::countsInValue($l)) {
                continue;
            }
            $acq[$l['_acq']] = true;
            $total += (float) $l['suma'];
        }
        $label = (string) ($tree->node($node)['denumire'] ?? '');
        return ['acquisitions' => $acq, 'total' => round($total, 2), 'label' => $label];
    }

    /** Liniile tuturor dosarelor date (aceeasi citire ca fila Costuri), cu dosarul atasat. */
    private function collectLines(array $dossiers, FinancialCategoryTree $tree): array
    {
        $out = [];
        foreach ($dossiers as $d) {
            [, $lines] = $this->lines((int) $d['acquisition_id'], $tree);
            foreach ($lines as $l) {
                $out[] = $l + ['_acq' => (int) $d['acquisition_id'], '_dosar' => (string) $d['nr']];
            }
        }
        return $out;
    }

    /** Liniile in forma ceruta de FinancialCategoryTree::aggregate (aceeasi ca in lines()). */
    private function linesForTotals(array $lines): array
    {
        return array_map(static fn (array $l): array => [
            'id' => $l['id'], 'leaf_id' => (int) $l['fin_id'], 'net_ron' => $l['suma'], 'moneda' => $l['moneda'], 'suma_originala' => $l['suma_originala'],
            'tratament' => $l['tratament'], 'status_verificare' => $l['status_verificare'], 'clasificare' => $l['clasificare'],
            'inclus' => $l['inclus_in'] !== null, 'anulat' => $l['anulat'], 'platit_ron' => 0.0, 'data' => $l['data'],
        ], $lines);
    }

    /** Randurile vizibile (filtrele paginii + fila), fara paginare — aceeasi selectie ca list(). */
    private function visibleRows(array $f): array
    {
        $out = $this->list(['per_page' => 50, 'page' => 1, 'fin_node' => 0] + $f);
        return $out['_visible'];
    }

    /** @return array{0:?string,1:?string} */
    private function periodBounds(string $period): array
    {
        if (preg_match('/^\d{4}$/', $period) === 1) {
            return [$period . '-01-01', $period . '-12-31'];
        }
        return match ($period) {
            '12_luni' => [date('Y-m-d', strtotime('-12 months')), date('Y-m-d')],
            '3_ani' => [date('Y-m-d', strtotime('-3 years')), date('Y-m-d')],
            default => [null, null],
        };
    }

    // ------------------------------------------------------------------ detaliu

    public function detail(?int $acquisitionId, ?int $vehicleId): array
    {
        $dossier = $acquisitionId !== null ? $this->acquisitions->dossier($acquisitionId) : null;
        $vehicleId = $dossier !== null ? ($dossier['vehicle_id'] !== null ? (int) $dossier['vehicle_id'] : null) : $vehicleId;
        $vehicle = null;
        if ($vehicleId !== null) {
            $stmt = $this->db->prepare('SELECT id, nr_inmatriculare, marca, model, tip_vehicul, an_fabricatie, serie_sasiu FROM vehicule WHERE id = :id');
            $stmt->execute([':id' => $vehicleId]);
            $vehicle = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $type = (string) ($vehicle['tip_vehicul'] ?? $dossier['tip_vehicul'] ?? '');
        $out = [
            'acquisition_id' => $acquisitionId,
            'vehicle_id' => $vehicleId,
            'titlu' => (string) ($vehicle['nr_inmatriculare'] ?? $dossier['cod_dosar'] ?? ''),
            'vehicul' => $vehicle,
            'tip_label' => self::TYPE_LABELS[$type] ?? $type,
            'dosar' => $dossier,
            'stadii' => [],
            'categorii_financiare' => [],
            'linii' => [],
            'categorii' => [],
            'sumar' => null,
            'plati' => [],
            'evenimente' => [],
            'transport' => null,
            'documente' => [],
            'istoric' => [],
            'proiecte' => [],
            'valoare' => null,
        ];

        if ($dossier !== null) {
            $summary = $this->acquisitions->summary($acquisitionId);
            $out['sumar'] = $summary;
            $out['evenimente'] = $this->acquisitions->events($acquisitionId);
            $tree = new FinancialCategoryTree($this->db);
            $out['categorii'] = array_map(static fn (array $c): array => ['cod' => $c['cod'], 'denumire' => $c['denumire'], 'grup' => $c['grup'], 'clasificare' => $c['clasificare_implicita'],
                'fin_id' => $tree->resolveLeaf(null, $c['financiar_categorie_id'] !== null ? (int) $c['financiar_categorie_id'] : null)],
                $this->acquisitions->categories($acquisitionId));
            [$out['categorii_financiare'], $out['linii']] = $this->lines($acquisitionId, $tree);
            $payments = $this->db->prepare('SELECT * FROM achizitii_vehicule_plati WHERE achizitie_id = :a ORDER BY data_plata, id');
            $payments->execute([':a' => $acquisitionId]);
            $out['plati'] = $payments->fetchAll(PDO::FETCH_ASSOC);
            $transport = $this->db->prepare('SELECT t.*, s.nume AS sofer FROM achizitii_vehicule_transport t LEFT JOIN soferi s ON s.id = t.sofer_id WHERE t.achizitie_id = :a ORDER BY t.id LIMIT 1');
            $transport->execute([':a' => $acquisitionId]);
            $out['transport'] = $transport->fetch(PDO::FETCH_ASSOC) ?: null;
            $out['stadii'] = $this->stages($dossier, $summary, $out['evenimente'], $out['transport'], count($out['linii']));
            $out['istoric'] = $this->history($acquisitionId);
            $docs = $this->db->prepare('SELECT * FROM achizitii_vehicule_documente WHERE achizitie_id = :a ORDER BY id DESC');
            $docs->execute([':a' => $acquisitionId]);
            foreach ($docs->fetchAll(PDO::FETCH_ASSOC) as $doc) {
                $out['documente'][] = ['sursa' => 'dosar', 'id' => (int) $doc['id'], 'tip' => $doc['tip'], 'numar' => $doc['numar'], 'fisier' => $doc['fisier_original'],
                    'are_fisier' => !empty($doc['fisier_stocat']), 'data' => substr((string) $doc['created_at'], 0, 10), 'expira' => null];
            }
        }
        if ($vehicleId !== null) {
            $docs = $this->db->prepare('SELECT id, tip_document, numar_document, data_expirare, fisier_original, fisier_stocat, proiect_investitie_id FROM documente WHERE vehicle_id = :v ORDER BY data_expirare IS NULL, data_expirare DESC, id DESC');
            $docs->execute([':v' => $vehicleId]);
            foreach ($docs->fetchAll(PDO::FETCH_ASSOC) as $doc) {
                $out['documente'][] = ['sursa' => 'vehicul', 'id' => (int) $doc['id'], 'tip' => $doc['tip_document'], 'numar' => $doc['numar_document'], 'fisier' => $doc['fisier_original'],
                    'are_fisier' => !empty($doc['fisier_stocat']), 'url' => !empty($doc['fisier_stocat']) && function_exists('document_file_url') ? document_file_url((string) $doc['fisier_stocat']) : null,
                    'data' => null, 'expira' => $doc['data_expirare'], 'proiect_id' => $doc['proiect_investitie_id'] !== null ? (int) $doc['proiect_investitie_id'] : null];
            }
            $out['proiecte'] = $this->projectsFor($vehicleId, $type);
            $history = new VehicleFinancialHistoryService($this->db);
            $out['istoric_financiar'] = array_slice(array_reverse($history->forVehicle($vehicleId)), 0, 300);
            $out['istoric_totaluri'] = $history->totals($vehicleId);
        }
        $out['valoare'] = $this->value($out['sumar'], $out['proiecte']);
        return $out;
    }

    /**
     * Liniile dosarului + arborele categoriilor financiare cu totalurile calculate pe server
     * (parinte = suma tranzactiilor distincte din frunzele descendente; vezi FinancialCategoryTree).
     *
     * @return array{0:list<array>,1:list<array>} [arbore aplatizat cu totaluri, linii]
     */
    private function lines(int $acquisitionId, FinancialCategoryTree $tree): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.*, c.denumire AS categorie_label, c.financiar_categorie_id AS tip_fin_id
               FROM achizitii_vehicule_cheltuieli l JOIN achizitii_categorii_cheltuieli c ON c.cod = l.categorie
              WHERE l.achizitie_id = :a ORDER BY COALESCE(l.data_tranzactie, DATE(l.created_at)), l.id'
        );
        $stmt->execute([':a' => $acquisitionId]);

        // Platile pe linie, convertite fiecare cu cursul ei (o plata nu este o cheltuiala noua).
        $paidRon = [];
        $paidOriginal = [];
        $payments = $this->db->prepare('SELECT cheltuiala_linie_id, suma, moneda, curs_schimb FROM achizitii_vehicule_plati WHERE achizitie_id = :a AND cheltuiala_linie_id IS NOT NULL');
        $payments->execute([':a' => $acquisitionId]);
        foreach ($payments->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $lineId = (int) $p['cheltuiala_linie_id'];
            $paidOriginal[$lineId] = ($paidOriginal[$lineId] ?? 0.0) + (float) $p['suma'];
            $factor = $p['moneda'] === 'RON' ? 1.0 : ($p['curs_schimb'] !== null && (float) $p['curs_schimb'] > 0 ? (float) $p['curs_schimb'] : null);
            if ($factor !== null) {
                $paidRon[$lineId] = ($paidRon[$lineId] ?? 0.0) + (float) $p['suma'] * $factor;
            }
        }

        $lines = [];
        $forTotals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $id = (int) $l['id'];
            $factor = $l['moneda'] === 'RON' ? 1.0 : ($l['curs_schimb'] !== null && (float) $l['curs_schimb'] > 0 ? (float) $l['curs_schimb'] : null);
            $net = $factor !== null ? round((float) $l['suma_neta'] * $factor, 2) : null;
            $vat = $factor !== null && $l['tva'] !== null ? round((float) $l['tva'] * $factor, 2) : ($l['tva'] === null ? 0.0 : null);
            $included = $l['inclus_in_linie_id'] !== null;
            $cancelled = ($l['anulat_la'] ?? null) !== null;
            $leaf = $tree->resolveLeaf($l['financiar_categorie_id'] !== null ? (int) $l['financiar_categorie_id'] : null, $l['tip_fin_id'] !== null ? (int) $l['tip_fin_id'] : null);
            $leafNode = $leaf !== null ? $tree->node($leaf) : null;
            $forTotals[] = [
                'id' => $id, 'leaf_id' => (int) $leaf, 'net_ron' => $net, 'moneda' => $l['moneda'], 'suma_originala' => (float) $l['suma_neta'],
                'tratament' => $l['tratament_contabil'], 'status_verificare' => $l['status_verificare'], 'clasificare' => $l['clasificare'],
                'inclus' => $included, 'anulat' => $cancelled, 'platit_ron' => $paidRon[$id] ?? 0.0, 'data' => $l['data_tranzactie'],
            ];
            $lines[] = [
                'id' => $id,
                'data' => $l['data_tranzactie'],
                'categorie' => $l['categorie'],
                'categorie_label' => $l['categorie_label'],
                'fin_id' => $leaf,
                'fin_cale' => $leaf !== null ? $tree->path($leaf) : [],
                'fin_cale_text' => $leaf !== null ? implode(' › ', array_map(static fn (int $n): string => (string) $tree->node($n)['denumire'], $tree->path($leaf))) : '',
                'grup' => $leafNode['denumire'] ?? '—',
                'descriere' => $l['descriere'],
                'furnizor' => $l['furnizor'],
                'suma' => $net,
                'suma_originala' => (float) $l['suma_neta'],
                'moneda' => $l['moneda'],
                'curs' => $l['curs_schimb'] !== null ? (float) $l['curs_schimb'] : null,
                'tva' => $vat,
                'tva_deductibil' => $l['tva_deductibil'],
                'clasificare' => $l['clasificare'],
                'tratament' => $l['tratament_contabil'],
                'tratament_label' => self::TREATMENT_LABELS[$l['tratament_contabil']] ?? $l['tratament_contabil'],
                'status_verificare' => $l['status_verificare'],
                'document_tip' => $l['document_tip'],
                'document_numar' => $l['document_numar'],
                'are_fisier' => !empty($l['document_fisier']),
                'inclus_in' => $included ? (int) $l['inclus_in_linie_id'] : null,
                'cheltuiala_id' => $l['cheltuiala_id'] !== null ? (int) $l['cheltuiala_id'] : null,
                'observatii' => $l['observatii'],
                'sursa' => $l['sursa_tip'],
                'platit' => round($paidOriginal[$id] ?? 0.0, 2),
                'anulat' => $cancelled,
                'anulat_la' => $l['anulat_la'] ?? null,
                'anulat_motiv' => $l['anulat_motiv'] ?? null,
            ];
        }
        return [$tree->flatten($tree->aggregate($forTotals)), $lines];
    }

    /**
     * Fisa unei inregistrari de cost (panoul peste pagina, ca fisa cursei din Dispecer):
     * linia din lines() + dosarul, vehiculul, platile ei, liniile incluse in ea si istoricul din financiar_audit.
     * Doar citire — sumele sunt aceleasi ca in grila (aceeasi conversie, acelasi rand).
     */
    public function lineDetail(int $lineId): ?array
    {
        $stmt = $this->db->prepare('SELECT achizitie_id FROM achizitii_vehicule_cheltuieli WHERE id = :id');
        $stmt->execute([':id' => $lineId]);
        $acquisitionId = $stmt->fetchColumn();
        if ($acquisitionId === false) {
            return null;
        }
        $acquisitionId = (int) $acquisitionId;
        $tree = new FinancialCategoryTree($this->db);
        [, $lines] = $this->lines($acquisitionId, $tree);
        $line = null;
        $included = [];
        foreach ($lines as $l) {
            if ($l['id'] === $lineId) {
                $line = $l;
            } elseif ($l['inclus_in'] === $lineId) {
                $included[] = $l;
            }
        }
        if ($line === null) {
            return null;
        }

        $raw = $this->db->prepare('SELECT l.cui_furnizor, l.tva_recuperabil, l.cota_tva, l.created_at, l.updated_at, l.anulat_de,
                cu.nume AS creat_de, uu.nume AS modificat_de, au.nume AS anulat_de_nume
              FROM achizitii_vehicule_cheltuieli l
              LEFT JOIN utilizatori cu ON cu.id = l.created_by
              LEFT JOIN utilizatori uu ON uu.id = l.updated_by
              LEFT JOIN utilizatori au ON au.id = l.anulat_de
             WHERE l.id = :id');
        $raw->execute([':id' => $lineId]);
        $extra = $raw->fetch(PDO::FETCH_ASSOC) ?: [];

        $dossier = $this->acquisitions->dossier($acquisitionId);
        $vehicle = null;
        if ($dossier['vehicle_id'] !== null) {
            $v = $this->db->prepare('SELECT id, nr_inmatriculare, marca, model FROM vehicule WHERE id = :id');
            $v->execute([':id' => (int) $dossier['vehicle_id']]);
            $vehicle = $v->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $payments = $this->db->prepare('SELECT data_plata, suma, moneda, curs_schimb, metoda, referinta FROM achizitii_vehicule_plati WHERE cheltuiala_linie_id = :l ORDER BY data_plata, id');
        $payments->execute([':l' => $lineId]);

        $audit = $this->db->prepare(
            "SELECT f.actiune, f.camp, f.valoare_veche, f.valoare_noua, f.motiv, f.created_at, u.nume AS utilizator
               FROM financiar_audit f LEFT JOIN utilizatori u ON u.id = f.user_id
              WHERE f.entitate = 'achizitie_cheltuiala' AND f.entitate_id = :l ORDER BY f.created_at DESC, f.id DESC LIMIT 100"
        );
        $audit->execute([':l' => $lineId]);
        $history = $audit->fetchAll(PDO::FETCH_ASSOC);
        foreach ($history as &$entry) {
            if ($entry['camp'] === 'financiar_categorie_id') { // mutarea: numele subcategoriilor, nu id-urile
                foreach (['valoare_veche', 'valoare_noua'] as $field) {
                    $node = $entry[$field] !== null ? $tree->node((int) $entry[$field]) : null;
                    $entry[$field] = $node['denumire'] ?? $entry[$field];
                }
            }
        }
        unset($entry);

        return [
            'linie' => $line,
            'extra' => $extra,
            'dosar' => $dossier,
            'vehicul' => $vehicle,
            'plati' => $payments->fetchAll(PDO::FETCH_ASSOC),
            'incluse' => $included,
            'istoric' => $history,
        ];
    }

    /** Stadiile dosarului, din inregistrarile reale (nu din status). */
    private function stages(array $d, array $summary, array $events, ?array $transport, int $lineCount): array
    {
        $paymentsDone = $summary['total_document'] > 0 && $summary['sold_neachitat'] <= 0.01;
        $done = [
            'vanzator' => trim((string) $d['vanzator_nume']) !== '' && ($d['vin'] !== null || $d['vehicle_id'] !== null),
            'contract' => ($d['data_contract'] !== null || $d['data_factura'] !== null || isset($events['achizitie'])) && $summary['pret_achizitie'] > 0,
            'plati' => $paymentsDone,
            'transport' => ($transport !== null && $transport['livrare_confirmata_la'] !== null) || isset($events['sosire_ro']),
            'receptie' => isset($events['receptie_tehnica']),
            'inmatriculare' => isset($events['inmatriculare']),
            'exploatare' => isset($events['punere_in_functiune']),
        ];
        $dates = [
            'vanzator' => substr((string) $d['created_at'], 0, 10),
            'contract' => $d['data_factura'] ?? $d['data_contract'] ?? ($events['achizitie'] ?? null),
            'plati' => null,
            'transport' => $transport['livrare_confirmata_la'] ?? ($events['sosire_ro'] ?? null),
            'receptie' => $events['receptie_tehnica'] ?? null,
            'inmatriculare' => $events['inmatriculare'] ?? null,
            'exploatare' => $events['punere_in_functiune'] ?? null,
        ];
        $hints = [
            'plati' => $summary['total_document'] > 0 ? 'Plătit ' . number_format($summary['platit'], 2, ',', '.') . ' din ' . number_format($summary['total_document'], 2, ',', '.') . ' lei' : 'Nicio sumă înregistrată',
        ];
        $out = [];
        $currentSet = false;
        foreach (self::STAGES as $key => $label) {
            $state = $done[$key] ? 'done' : (!$currentSet && $d['status'] !== 'anulat' ? 'current' : 'todo');
            if ($state === 'current') {
                $currentSet = true;
            }
            $out[] = ['key' => $key, 'label' => $label, 'state' => $state, 'data' => $dates[$key], 'hint' => $hints[$key] ?? null];
        }
        return $out;
    }

    private function history(int $acquisitionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT f.*, u.nume AS utilizator FROM financiar_audit f LEFT JOIN utilizatori u ON u.id = f.user_id
              WHERE (f.entitate = 'achizitie' AND f.entitate_id = :a)
                 OR (f.entitate = 'achizitie_cheltuiala' AND f.entitate_id IN (SELECT id FROM achizitii_vehicule_cheltuieli WHERE achizitie_id = :a2))
              ORDER BY f.created_at DESC, f.id DESC LIMIT 200"
        );
        $stmt->execute([':a' => $acquisitionId, ':a2' => $acquisitionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Proiectele vehiculului fizic (GPL / modernizari) cu totalurile si pregatirea lor. */
    private function projectsFor(int $vehicleId, string $type): array
    {
        $required = [];
        $stmt = $this->db->prepare('SELECT document_type FROM configurare_costuri_documente_vehicule WHERE vehicle_type = :t AND obligatoriu = 1 ORDER BY document_type');
        try {
            $stmt->execute([':t' => $type]);
            $required = array_values(array_unique(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        } catch (Throwable) {
            $required = [];
        }
        $out = [];
        foreach ($this->projects->forVehicle($vehicleId) as $p) {
            $id = (int) $p['id'];
            $out[] = [
                'proiect' => $p,
                'categorie_label' => InvestmentProjectService::CATEGORIES[$p['categorie']] ?? $p['categorie'],
                'totaluri' => $this->projects->totals($id),
                'pregatire' => $this->projects->readiness($id, $required),
            ];
        }
        return $out;
    }

    /**
     * Valoare & amortizare: doar sume inregistrate. Valoarea actuala NU se estimeaza: lipseste politica de
     * amortizare (durata utila / metoda) aprobata.
     */
    private function value(?array $summary, array $projects): array
    {
        $capitalProjects = 0.0;
        $pendingProjects = 0.0;
        $missingLife = 0;
        foreach ($projects as $p) {
            $total = (float) $p['totaluri']['cost_real'];
            if ($p['proiect']['status'] === 'anulat') {
                continue;
            }
            foreach ($p['totaluri']['surse'] as $source) {
                if (($source['tratament_contabil'] ?? null) === 'capitalizabil') {
                    $capitalProjects += (float) $source['suma'];
                } else {
                    $pendingProjects += (float) $source['suma'];
                }
            }
            if ($p['proiect']['durata_utila_luni'] === null && $total > 0) {
                $missingLife++;
            }
        }
        return [
            'cost_initial' => $summary !== null ? round($summary['pret_achizitie'] + $summary['cheltuieli_asociate'], 2) : null,
            'capitalizat_achizitie' => $summary['cost_initial_capitalizabil'] ?? null,
            'necapitalizabile' => $summary['necapitalizabile'] ?? null,
            'investitii_capitalizate' => round($capitalProjects, 2),
            'investitii_neconfirmate' => round($pendingProjects, 2),
            'valoare_intrare_totala' => round((float) ($summary['cost_initial_capitalizabil'] ?? 0) + $capitalProjects, 2),
            'valoare_actuala' => null,
            'motiv_valoare_actuala' => 'Amortizarea nu se calculează până când contabilitatea aprobă durata utilă și metoda.',
            'proiecte_fara_durata' => $missingLife,
        ];
    }
}
