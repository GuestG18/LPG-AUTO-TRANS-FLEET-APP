<?php
declare(strict_types=1);

require_once __DIR__ . '/OperationalProfitabilityService.php';
require_once __DIR__ . '/VehicleFinancialRegisterService.php';
require_once __DIR__ . '/CostNature.php';

/**
 * Modelul de citire al paginii „Rentabilitate Operationala” (?page=rentabilitate).
 *
 * Sumele vin din OperationalProfitabilityService (motorul Cost / km pe luni + alocari datate). Aici se
 * construiesc randurile (Vehicule / Soferi), filtrele, paginarea si datele tab-urilor, la cerere.
 *
 * Filtrele Tip transport / Beneficiar restrang ACTIVITATEA: km-ii si venitul vin doar din cursele care
 * corespund; costul se aloca proportional cu acesti km (costul / km al vehiculului pe interval × km filtrati).
 */
final class ProfitabilityRegisterService
{
    private const SEMI_TYPES = ['semiremorca', 'semiremorca_primar', 'semiremorca_distributie'];
    private const HEAVY_TYPES = ['cap_tractor', 'camion', 'semiremorca', 'semiremorca_primar', 'semiremorca_distributie'];
    private const TYPE_LABELS = ['cap_tractor' => 'Cap tractor', 'camion' => 'Camion', 'semiremorca' => 'Semiremorcă', 'semiremorca_primar' => 'Semiremorcă primar',
        'semiremorca_distributie' => 'Semiremorcă distribuție', 'autoutilitara' => 'Autoutilitară', 'autovehicul' => 'Autovehicul'];
    private const CODE_LABELS = ['salariu_soferi' => 'Salariu și contribuții', 'diurna' => 'Diurnă', 'analize_soferi' => 'Documente / analize șofer',
        'cursuri_soferi' => 'Cursuri șofer', 'ssm_su' => 'SSM / SU'];

    private OperationalProfitabilityService $engine;
    private array $analysisMemo = [];

    public function __construct(private PDO $db, ?string $cacheDir = null)
    {
        $this->engine = new OperationalProfitabilityService($db, $cacheDir);
    }

    public function engine(): OperationalProfitabilityService
    {
        return $this->engine;
    }

    public function analysis(string $from, string $to): array
    {
        return $this->analysisMemo[$from . '|' . $to] ??= $this->engine->analyze($from, $to);
    }

    /** @return array{0:string,1:string} interval valid (implicit: ultimele 3 luni — luna curenta si cele doua dinainte) */
    public static function range(?string $from, ?string $to): array
    {
        $ok = static fn (?string $d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
        $from = $ok($from) ? $from : date('Y-m-01', strtotime(date('Y-m-01') . ' -2 months'));
        $to = $ok($to) ? $to : date('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        // cel mult 8 ani (calcul lunar)
        if ((int) substr($to, 0, 4) - (int) substr($from, 0, 4) > 8) {
            $from = ((int) substr($to, 0, 4) - 8) . substr($to, 4);
        }
        return [$from, $to];
    }

    // ================================================================== lista

    /**
     * Randurile tabelului pentru filtrele date (perioada, mod, tip, beneficiar, vehicul, sofer, cautare, status).
     * O singura implementare: o folosesc tabelul (list), cardurile KPI (kpiBlock) si defalcarile din carduri (kpiBreakdown).
     *
     * @return array{0:list<array>,1:array} [randuri, context: a, mode, from, to, type, benef, vehicle_id, driver_id, status, q, dropped, options]
     */
    private function scopeRows(array $f): array
    {
        [$from, $to] = self::range($f['from'] ?? null, $f['to'] ?? null);
        $a = $this->analysis($from, $to);
        $mode = ($f['mode'] ?? 'vehicule') === 'soferi' ? 'soferi' : 'vehicule';
        $type = (string) ($f['tip'] ?? '');
        $benef = (int) ($f['beneficiar'] ?? 0);
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')), 'UTF-8');
        $status = (string) ($f['status'] ?? '');
        $benefNames = $this->beneficiaries();
        $drivers = $this->engine->driverNames();

        // Vehicul / sofer (filtrele cardurilor KPI): doar combinatii care au activitate in perioada si in tipul /
        // beneficiarul ales; o selectie incompatibila se scoate aici (si se raporteaza), nu da un tabel gol.
        $vehicleId = (int) ($f['vehicle_id'] ?? 0);
        $driverId = (int) ($f['driver_id'] ?? 0);
        $options = $this->filterOptions($a, $type, $benef, $vehicleId, $driverId, (string) ($f['keep'] ?? ''));
        $dropped = [];
        if ($vehicleId > 0 && !isset($options['vehicule_valid'][$vehicleId])) {
            $dropped[] = 'vehicle_id';
            $vehicleId = 0;
        }
        if ($driverId > 0 && !isset($options['soferi_valid'][$driverId])) {
            $dropped[] = 'driver_id';
            $driverId = 0;
        }

        $rows = $mode === 'vehicule' ? $this->vehicleRows($a, $type, $benef, $benefNames, $drivers, $vehicleId, $driverId)
            : $this->driverRows($a, $type, $benef, $benefNames, $vehicleId, $driverId);
        if ($q !== '') {
            $tripQuery = preg_match('/^#?(\d+)$/', $q, $m) === 1 ? (int) $m[1] : null;
            $rows = array_values(array_filter($rows, static fn (array $r): bool => str_contains($r['_search'], $q) || ($tripQuery !== null && in_array($tripQuery, $r['_trips'], true))));
        }
        if ($status !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => match ($status) {
                'profitabil' => $r['km'] > 0 && $r['result_km'] !== null && $r['result_km'] >= 0,
                'pierdere' => $r['km'] > 0 && $r['result_km'] !== null && $r['result_km'] < 0,
                'incomplet' => $r['incomplet'] !== [],
                'fara_activitate' => $r['km'] <= 0,
                default => true,
            }));
        }
        return [$rows, ['a' => $a, 'mode' => $mode, 'from' => $from, 'to' => $to, 'type' => $type, 'benef' => $benef, 'vehicle_id' => $vehicleId,
            'driver_id' => $driverId, 'status' => $status, 'q' => (string) ($f['q'] ?? ''), 'dropped' => $dropped, 'options' => $options]];
    }

    public function list(array $f): array
    {
        [$rows, $ctx] = $this->scopeRows($f);
        ['a' => $a, 'mode' => $mode, 'from' => $from, 'to' => $to, 'type' => $type, 'benef' => $benef, 'vehicle_id' => $vehicleId,
            'driver_id' => $driverId, 'status' => $status, 'dropped' => $dropped, 'options' => $options] = $ctx;
        $benefNames = $this->beneficiaries();
        // Totaluri fara dublari: semiremorcile apar si individual, dar costul lor alocat e deja in ansamblu.
        $totals = ['km' => 0.0, 'revenue' => 0.0, 'cost' => 0.0, 'cost_vehicle' => 0.0, 'cost_driver' => 0.0, 'unitati' => 0];
        foreach ($rows as $r) {
            if (!empty($r['is_semi'])) {
                $totals['cost'] += $r['unallocated'] ?? 0.0;
                $totals['cost_vehicle'] += $r['unallocated'] ?? 0.0;
                continue;
            }
            $totals['km'] += $r['km'];
            $totals['revenue'] += $r['revenue'];
            $totals['cost'] += $mode === 'vehicule' ? $r['cost_total'] : $r['cost_driver'];
            $totals['cost_vehicle'] += $r['cost_vehicle'] ?? 0.0;
            $totals['cost_driver'] += $r['cost_driver'] ?? 0.0;
            $totals['unitati']++;
        }
        $totals['result'] = $totals['revenue'] - $totals['cost'];
        $totals['cost_km'] = $totals['km'] > 0 ? $totals['cost'] / $totals['km'] : null;
        $totals['revenue_km'] = $totals['km'] > 0 ? $totals['revenue'] / $totals['km'] : null;
        $totals['coverage'] = $totals['cost'] > 0 ? $totals['revenue'] / $totals['cost'] * 100 : null;
        $kpi = $this->kpiBlock($rows, $a);

        $sort = (string) ($f['sort'] ?? '');
        if ($sort !== '' && preg_match('/^(-?)(km|cost_vehicle_km|cost_driver_km|cost_total_km|revenue_km|result_km|coverage|label)$/', $sort, $m) === 1) {
            $key = $m[2];
            $dir = $m[1] === '-' ? -1 : 1;
            usort($rows, static fn (array $x, array $y): int => $dir * (($x[$key] ?? -INF) <=> ($y[$key] ?? -INF)));
        }

        $perPage = (int) ($f['per_page'] ?? 25);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
        $pages = max(1, (int) ceil(count($rows) / $perPage));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $types = [];
        foreach ($a['vehicles'] as $v) {
            foreach (array_keys($v['by_type']) as $t) {
                $types[$t] = OperationalProfitabilityService::TRANSPORT_LABELS[$t] ?? $t;
            }
        }
        return [
            'mode' => $mode, 'from' => $from, 'to' => $to,
            'rows' => array_map(static function (array $r): array {
                unset($r['_search'], $r['_trips']);
                return $r;
            }, array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'total' => count($rows), 'page' => $page, 'pages' => $pages, 'per_page' => $perPage,
            'totals' => $totals,
            'kpi' => $kpi,
            'lipsa' => $a['missing_elements'],
            'optiuni' => ['tipuri' => $types, 'beneficiari' => $benefNames, 'vehicule' => $options['vehicule'], 'soferi' => $options['soferi']],
            // filtrele efectiv aplicate (sursa de adevar pentru pagina) + cele scoase ca incompatibile
            'aplicat' => ['tip' => $type, 'beneficiar' => $benef, 'vehicle_id' => $vehicleId, 'driver_id' => $driverId, 'status' => $status, 'q' => (string) ($f['q'] ?? '')],
            'anulate' => $dropped,
        ];
    }

    /**
     * Indicatorii cardurilor (Cost / Venit / Rezultat pe km, Grad de acoperire) din EXACT randurile tabelului filtrat:
     * agregate ponderate (suma costurilor / suma km-ilor), nu medii ale rapoartelor pe vehicul.
     * Cost eligibil = cost vehicul + cost sofer al randurilor; la semiremorci doar costul ramas nealocat vreunui tractor
     * (restul e deja in costul tractoarelor) — identic cu totalurile tabelului in modul Vehicule.
     */
    private function kpiBlock(array $rows, array $a): array
    {
        $km = 0.0;
        $revenue = 0.0;
        $cost = 0.0;
        $units = 0;
        $incomplete = 0;
        $estimated = 0;
        foreach ($rows as $r) {
            if (!empty($r['is_semi'])) {
                // ca totalurile tabelului: partea alocata tractoarelor e deja in costul lor; aici doar restul necuplat
                $cost += (float) ($r['unallocated'] ?? 0);
                continue;
            }
            $cost += (float) ($r['cost_vehicle'] ?? 0) + (float) ($r['cost_driver'] ?? 0);
            $km += (float) $r['km'];
            $revenue += (float) $r['revenue'];
            $units++;
            if (($r['incomplet'] ?? []) !== []) {
                $incomplete++;
            }
        }
        $costKm = $km > 0 ? $cost / $km : null;
        $revenueKm = $km > 0 ? $revenue / $km : null;
        return [
            'km' => $km, 'revenue' => $revenue, 'cost' => $cost, 'result' => $revenue - $cost,
            'cost_km' => $costKm, 'revenue_km' => $revenueKm,
            'result_km' => $costKm !== null && $revenueKm !== null ? $revenueKm - $costKm : null,
            'coverage' => $cost > 0 ? $revenue / $cost * 100 : null,
            'unitati' => $units, 'unitati_incomplete' => $incomplete,
            'elemente_lipsa' => count($a['missing_elements']),
        ];
    }

    /**
     * Optiunile Vehicul / Sofer compatibile cu perioada + tip + beneficiar (+ celalalt filtru).
     * Cand vehiculul si soferul ales nu au lucrat impreuna, ramane cel ales ultimul ($keep), celalalt se scoate.
     */
    private function filterOptions(array $a, string $type, int $benef, int $vehicleId, int $driverId, string $keep): array
    {
        $info = $this->engine->vehicleInfo();
        $names = $this->engine->driverNames();
        $pairs = []; // vid => [did => km in scop]
        foreach ($a['vehicles'] as $vid => $v) {
            $meta = $info[$vid] ?? null;
            if ($meta === null || !in_array($meta['tip_vehicul'], self::HEAVY_TYPES, true) || in_array($meta['tip_vehicul'], self::SEMI_TYPES, true)) {
                continue;
            }
            [$km] = $this->scoped($v, $type, $benef);
            if ($km <= 0) {
                continue;
            }
            $ratio = $v['km_real'] > 0 ? $km / $v['km_real'] : 0.0;
            $pairs[$vid] = [];
            foreach ($v['by_driver'] as $did => $d) {
                if ($d['km'] * $ratio > 0) {
                    $pairs[$vid][(int) $did] = $d['km'] * $ratio;
                }
            }
        }
        // o selectie fara activitate in scop nu restrange lista celuilalt filtru (list() o scoate)
        if (!isset($pairs[$vehicleId])) {
            $vehicleId = 0;
        }
        $driverActive = false;
        foreach ($pairs as $ds) {
            $driverActive = $driverActive || isset($ds[$driverId]);
        }
        if (!$driverActive) {
            $driverId = 0;
        }
        if ($vehicleId > 0 && $driverId > 0 && isset($pairs[$vehicleId]) && !isset($pairs[$vehicleId][$driverId])) {
            // perechea nu exista: pastram filtrul schimbat ultimul
            $keep === 'driver_id' ? $vehicleId = 0 : $driverId = 0;
        }
        $vehicles = [];
        $driversOut = [];
        foreach ($pairs as $vid => $ds) {
            if ($driverId <= 0 || isset($ds[$driverId])) {
                $vehicles[$vid] = $info[$vid]['nr'];
            }
            if ($vehicleId <= 0 || $vid === $vehicleId) {
                foreach (array_keys($ds) as $did) {
                    $driversOut[$did] = $names[$did]['nume'] ?? ('Șofer #' . $did);
                }
            }
        }
        natcasesort($vehicles);
        asort($driversOut, SORT_LOCALE_STRING);
        $list = static fn (array $m): array => array_map(static fn ($id, $label): array => ['id' => (int) $id, 'label' => (string) $label], array_keys($m), array_values($m));
        return ['vehicule' => $list($vehicles), 'soferi' => $list($driversOut), 'vehicule_valid' => $vehicles, 'soferi_valid' => $driversOut];
    }

    /**
     * Defalcarea unui card KPI (navigarea din card: Toata flota → Beneficiari → Tip transport → Vehicul / Sofer).
     * Fiecare rand = indicatorii (kpiBlock) din randurile tabelului cu filtrul de scop curent + valoarea randului,
     * deci exact ce ar arata pagina daca ai alege acel beneficiar / tip / vehicul. Fara medii ale rapoartelor.
     * Doar valori cu activitate (km sau cost) in scop; nivelurile fara date nu se ofera.
     *
     * @param string $by beneficiar | tip | vehicul | sofer
     */
    public function kpiBreakdown(array $f, string $by): array
    {
        if (!in_array($by, ['beneficiar', 'tip', 'vehicul', 'sofer'], true)) {
            throw new InvalidArgumentException('Defalcare necunoscută.');
        }
        [$baseRows, $ctx] = $this->scopeRows($f);
        $total = $this->kpiBlock($baseRows, $ctx['a']);
        $scope = ['mode' => $ctx['mode'], 'from' => $ctx['from'], 'to' => $ctx['to'], 'tip' => $ctx['type'], 'beneficiar' => $ctx['benef'],
            'vehicle_id' => $ctx['vehicle_id'], 'driver_id' => $ctx['driver_id'], 'status' => $ctx['status'], 'q' => $ctx['q']];

        // valorile candidate: doar cele cu activitate in scopul curent (fara pasi goi)
        $candidates = [];
        if ($by === 'beneficiar' || $by === 'tip') {
            $names = $this->beneficiaries();
            foreach ($ctx['a']['vehicles'] as $vid => $v) {
                if ($ctx['vehicle_id'] > 0 && (int) $vid !== $ctx['vehicle_id']) {
                    continue;
                }
                if ($ctx['driver_id'] > 0 && !isset($v['by_driver'][$ctx['driver_id']])) {
                    continue;
                }
                foreach ($v['by_bt'] as $key => $act) {
                    [$b, $t] = explode('|', (string) $key, 2);
                    if ((float) $act['km'] <= 0 || ($ctx['type'] !== '' && $t !== $ctx['type']) || ($ctx['benef'] > 0 && (int) $b !== $ctx['benef'])) {
                        continue;
                    }
                    $by === 'beneficiar'
                        ? $candidates[(string) (int) $b] = $names[(int) $b] ?? ('Beneficiar #' . $b)
                        : $candidates[$t] = OperationalProfitabilityService::TRANSPORT_LABELS[$t] ?? $t;
                }
            }
        } else {
            foreach ($ctx['options'][$by === 'vehicul' ? 'vehicule' : 'soferi'] as $o) {
                $candidates[(string) $o['id']] = $o['label'];
            }
        }

        $key = ['beneficiar' => 'beneficiar', 'tip' => 'tip', 'vehicul' => 'vehicle_id', 'sofer' => 'driver_id'][$by];
        $rows = [];
        foreach ($candidates as $value => $label) {
            [$r] = $this->scopeRows([$key => $by === 'tip' ? (string) $value : (int) $value] + $scope);
            $k = $this->kpiBlock($r, $ctx['a']);
            if ($k['km'] <= 0 && $k['cost'] <= 0) {
                continue;
            }
            $rows[] = ['value' => (string) $value, 'label' => (string) $label, 'kpi' => $k,
                'share_cost' => $total['cost'] > 0 ? $k['cost'] / $total['cost'] * 100 : null,
                'share_revenue' => $total['revenue'] > 0 ? $k['revenue'] / $total['revenue'] * 100 : null,
                'share_km' => $total['km'] > 0 ? $k['km'] / $total['km'] * 100 : null];
        }
        usort($rows, static fn (array $x, array $y): int => $y['kpi']['km'] <=> $x['kpi']['km'] ?: strcmp($x['label'], $y['label']));
        return ['by' => $by, 'rows' => $rows, 'total' => $total, 'aplicat' => $scope + ['vehicle_id' => $ctx['vehicle_id'], 'driver_id' => $ctx['driver_id']]];
    }

    /** Indicatorii cardurilor pentru Tablou de bord (aceeasi sursa ca pagina: list()). */
    public function kpiSummary(array $f): array
    {
        $out = $this->list(['per_page' => 10, 'page' => 1] + $f);
        return ['kpi' => $out['kpi'], 'optiuni' => $out['optiuni'], 'aplicat' => $out['aplicat'], 'anulate' => $out['anulate'],
            'from' => $out['from'], 'to' => $out['to'], 'mode' => $out['mode'], 'total' => $out['total']];
    }

    private function vehicleRows(array $a, string $type, int $benef, array $benefNames, array $drivers, int $vehicleId = 0, int $driverId = 0): array
    {
        $rows = [];
        $info = $this->engine->vehicleInfo();
        foreach ($info as $vid => $meta) {
            if (!in_array($meta['tip_vehicul'], self::HEAVY_TYPES, true)) {
                continue;
            }
            $v = $a['vehicles'][$vid] ?? null;
            $isSemi = in_array($meta['tip_vehicul'], self::SEMI_TYPES, true);
            if ($v === null && $meta['status'] !== 'activ') {
                continue;
            }
            if ($vehicleId > 0 && $vid !== $vehicleId) {
                continue;
            }
            $v ??= ['km_real' => 0.0, 'revenue' => 0.0, 'cost_vehicle' => 0.0, 'cost_driver' => 0.0, 'cost_total' => 0.0, 'by_type' => [], 'by_benef' => [], 'by_bt' => [],
                'by_driver' => [], 'trip_ids' => [], 'incomplet' => [], 'semi_km' => [], 'own_cost' => 0.0, 'unallocated_semi_cost' => 0.0, 'allocated_to' => [], 'trips' => 0, 'transport' => null];
            if ($isSemi) {
                // Semiremorca analizata individual: costurile ei, km-ii facuti cu ea (cu orice tractor).
                $km = 0.0;
                foreach ($a['vehicles'] as $other) {
                    $km += (float) ($other['semi_km'][$vid] ?? 0);
                }
                if ($type !== '' || $benef > 0 || $driverId > 0) {
                    continue; // activitatea (tip / beneficiar / sofer) e a tractorului
                }
                $tractors = array_map(static fn (int $t): string => $info[$t]['nr'] ?? '#' . $t, array_keys($v['allocated_to']));
                $rows[] = $this->row($vid, $meta, [
                    'km' => $km, 'revenue' => 0.0, 'cost_vehicle' => $v['own_cost'], 'cost_driver' => 0.0, 'trips' => 0,
                    'drivers' => $tractors !== [] ? 'Cu ' . implode(', ', $tractors) : 'Necuplată în perioadă',
                    'driver_count' => 0, 'transport' => null, 'incomplet' => $v['incomplet'], 'is_semi' => true,
                    'unallocated' => $v['unallocated_semi_cost'], 'trip_ids' => [],
                ]);
                continue;
            }
            [$km, $revenue, $trips, $typesUsed] = $this->scoped($v, $type, $benef);
            if (($type !== '' || $benef > 0) && $km <= 0) {
                continue;
            }
            $full = (float) $v['km_real'];
            $costVehicle = $full > 0 ? $v['cost_vehicle'] * $km / $full : ($type === '' && $benef === 0 ? $v['cost_vehicle'] : 0.0);
            $costDriver = $full > 0 ? $v['cost_driver'] * $km / $full : ($type === '' && $benef === 0 ? $v['cost_driver'] : 0.0);
            $driverIds = array_keys($v['by_driver']);
            if ($driverId > 0) {
                // Doar activitatea soferului ales pe vehicul (restransa proportional la tip / beneficiar, ca in modul Soferi):
                // costul vehiculului dupa km-ii lui, costul soferului = partea lui alocata acestui vehicul.
                $bd = $v['by_driver'][$driverId] ?? null;
                $ratio = $full > 0 ? $km / $full : 0.0;
                $dkm = $bd !== null ? (float) $bd['km'] * $ratio : 0.0;
                if ($dkm <= 0) {
                    continue;
                }
                $revenue = (float) $bd['revenue'] * $ratio;
                $trips = (int) round($bd['trips'] * $ratio);
                $costVehicle = $full > 0 ? $v['cost_vehicle'] * $dkm / $full : 0.0;
                $costDriver = (float) ($v['driver_costs'][$driverId] ?? 0) * ($bd['km'] > 0 ? $dkm / $bd['km'] : 0.0)
                    + (float) ($v['costs']['sofer'] ?? 0) * ($full > 0 ? $dkm / $full : 0.0);
                $km = $dkm;
                $driverIds = [$driverId];
            }
            $driverLabel = count($driverIds) === 1 ? ($drivers[$driverIds[0]]['nume'] ?? '—') : (count($driverIds) > 1 ? count($driverIds) . ' șoferi' : '—');
            $semis = array_map(static fn (int $s): string => $info[$s]['nr'] ?? '#' . $s, array_keys($v['semi_km']));
            $rows[] = $this->row($vid, $meta, [
                'km' => $km, 'revenue' => $revenue, 'cost_vehicle' => $costVehicle, 'cost_driver' => $costDriver, 'trips' => $trips,
                'drivers' => $driverLabel, 'driver_count' => count($driverIds), 'driver_names' => array_map(static fn (int $d): string => $drivers[$d]['nume'] ?? '#' . $d, $driverIds),
                'transport' => count($typesUsed) === 0 ? null : (count($typesUsed) === 1 ? $typesUsed[0] : 'mixt'),
                'incomplet' => $v['incomplet'], 'is_semi' => false, 'semis' => $semis, 'trip_ids' => $v['trip_ids'],
                'benef_names' => array_map(static fn (int $b): string => $benefNames[$b] ?? '', array_keys($v['by_benef'])),
            ]);
        }
        return $rows;
    }

    /** @return array{0:float,1:float,2:int,3:list<string>} km, venit, curse, tipuri — restranse la filtru */
    private function scoped(array $v, string $type, int $benef): array
    {
        if ($type === '' && $benef === 0) {
            return [(float) $v['km_real'], (float) $v['revenue'], (int) $v['trips'], array_keys($v['by_type'])];
        }
        $km = 0.0;
        $revenue = 0.0;
        $trips = 0;
        $types = [];
        foreach ($v['by_bt'] as $key => $act) {
            [$b, $t] = explode('|', (string) $key, 2);
            if (($type !== '' && $t !== $type) || ($benef > 0 && (int) $b !== $benef)) {
                continue;
            }
            $km += $act['km'];
            $revenue += $act['revenue'];
            $trips += $act['trips'];
            $types[$t] = true;
        }
        return [$km, $revenue, $trips, array_keys($types)];
    }

    private function row(int $vid, array $meta, array $d): array
    {
        $km = (float) $d['km'];
        $cost = $d['cost_vehicle'] + $d['cost_driver'];
        $result = $d['revenue'] - $cost;
        $semi = !empty($d['is_semi']);
        return [
            'key' => 'v' . $vid, 'id' => $vid, 'label' => $meta['nr'], 'model' => $meta['model'], 'tip_vehicul' => $meta['tip_vehicul'],
            'tip_label' => self::TYPE_LABELS[$meta['tip_vehicul']] ?? $meta['tip_vehicul'], 'status' => $meta['status'],
            'activitate' => $meta['status'] !== 'activ' ? 'inactiv' : ($km > 0 ? 'activ' : 'fara_activitate'),
            'drivers' => $d['drivers'], 'driver_count' => $d['driver_count'],
            'transport' => $d['transport'], 'transport_label' => $d['transport'] === null ? null : ($d['transport'] === 'mixt' ? 'Mixt' : (OperationalProfitabilityService::TRANSPORT_LABELS[$d['transport']] ?? $d['transport'])),
            'km' => $km, 'trips' => $d['trips'], 'revenue' => $d['revenue'],
            'cost_vehicle' => $d['cost_vehicle'], 'cost_driver' => $d['cost_driver'], 'cost_total' => $cost, 'result' => $result,
            'cost_vehicle_km' => $km > 0 ? $d['cost_vehicle'] / $km : null,
            'cost_driver_km' => $km > 0 && !$semi ? $d['cost_driver'] / $km : null,
            'cost_total_km' => $km > 0 ? $cost / $km : null,
            'revenue_km' => $km > 0 && !$semi ? $d['revenue'] / $km : null,
            'result_km' => $km > 0 && !$semi ? $result / $km : null,
            'coverage' => $cost > 0 && !$semi ? $d['revenue'] / $cost * 100 : null,
            'incomplet' => $d['incomplet'],
            'is_semi' => $semi, 'unallocated' => $d['unallocated'] ?? 0.0, 'semis' => $d['semis'] ?? [],
            '_search' => mb_strtolower(implode(' ', array_merge([$meta['nr'], $meta['model'], $d['drivers'],
                $d['transport'] !== null ? (OperationalProfitabilityService::TRANSPORT_LABELS[$d['transport']] ?? $d['transport']) : ''],
                $d['driver_names'] ?? [], $d['benef_names'] ?? [], $d['semis'] ?? [])), 'UTF-8'),
            '_trips' => $d['trip_ids'] ?? [],
        ];
    }

    private function driverRows(array $a, string $type, int $benef, array $benefNames, int $vehicleId = 0, int $driverId = 0): array
    {
        $info = $this->engine->vehicleInfo();
        $names = $this->engine->driverNames();
        $rows = [];
        $ids = array_keys($a['drivers']);
        foreach ($names as $did => $n) {
            if ($n['status'] === 'activ' && !in_array($did, $ids, true)) {
                $ids[] = $did;
            }
        }
        // filtrul de vehicul restrange activitatea (si costul, proportional cu km-ii) ca filtrul de tip / beneficiar
        $scopedFilter = $type !== '' || $benef > 0 || $vehicleId > 0;
        foreach ($ids as $did) {
            if ($driverId > 0 && (int) $did !== $driverId) {
                continue;
            }
            $d = $a['drivers'][$did] ?? null;
            $km = 0.0;
            $revenue = 0.0;
            $trips = 0;
            $vehicleCost = 0.0;
            $vehicles = [];
            $benefs = [];
            $tripIds = [];
            $types = [];
            foreach ($a['vehicles'] as $vid => $v) {
                if (!isset($v['by_driver'][$did]) || ($vehicleId > 0 && (int) $vid !== $vehicleId)) {
                    continue;
                }
                // activitatea soferului pe vehicul, restransa proportional la filtru (tip / beneficiar)
                [$skm, , , $styp] = $this->scoped($v, $type, $benef);
                $ratio = $v['km_real'] > 0 ? $skm / $v['km_real'] : 0.0;
                $dkm = $v['by_driver'][$did]['km'] * ($type !== '' || $benef > 0 ? $ratio : 1.0);
                if ($dkm <= 0 && ($type !== '' || $benef > 0)) {
                    continue;
                }
                $km += $dkm;
                $revenue += $v['by_driver'][$did]['revenue'] * ($type !== '' || $benef > 0 ? $ratio : 1.0);
                $trips += (int) round($v['by_driver'][$did]['trips'] * ($type !== '' || $benef > 0 ? $ratio : 1.0));
                $vehicleCost += $v['km_real'] > 0 ? $v['cost_vehicle'] / $v['km_real'] * $dkm : 0.0;
                $vehicles[] = $info[$vid]['nr'] ?? '#' . $vid;
                foreach (array_keys($v['by_benef']) as $b) {
                    $benefs[$b] = $benefNames[$b] ?? '';
                }
                foreach ($styp as $t) {
                    $types[$t] = true;
                }
                $tripIds = array_merge($tripIds, $v['trip_ids']);
            }
            if ($scopedFilter && $km <= 0) {
                continue;
            }
            $cost = $d !== null ? ($scopedFilter ? ($d['km'] > 0 ? $d['cost'] * $km / $d['km'] : 0.0) : $d['cost']) : 0.0;
            $contribution = $revenue - $vehicleCost - $cost;
            $typeKeys = array_keys($types);
            $source = $d !== null ? array_keys($d['cost_source']) : [];
            $incomplete = [];
            if ($d === null || in_array('lipsa', $source, true)) {
                $incomplete[] = 'Cost de personal lipsă (fără salariu / pontaj)';
            } elseif (!$d['cost_verified']) {
                $incomplete[] = 'Cost estimat din salariul configurat (fără pontaj finalizat)';
            }
            $rows[] = [
                'key' => 'd' . $did, 'id' => $did, 'label' => $names[$did]['nume'] ?? ('Șofer #' . $did), 'model' => '',
                'status' => $names[$did]['status'] ?? '', 'activitate' => ($names[$did]['status'] ?? '') !== 'activ' ? 'inactiv' : ($km > 0 ? 'activ' : 'fara_activitate'),
                'vehicles' => $vehicles, 'drivers' => $vehicles === [] ? '—' : (count($vehicles) === 1 ? $vehicles[0] : count($vehicles) . ' vehicule'),
                'transport' => count($typeKeys) === 0 ? null : (count($typeKeys) === 1 ? $typeKeys[0] : 'mixt'),
                'transport_label' => count($typeKeys) === 0 ? null : (count($typeKeys) === 1 ? (OperationalProfitabilityService::TRANSPORT_LABELS[$typeKeys[0]] ?? $typeKeys[0]) : 'Mixt'),
                'km' => $km, 'trips' => $trips, 'revenue' => $revenue,
                'cost_driver' => $cost, 'cost_vehicle' => $vehicleCost, 'cost_total' => $cost + $vehicleCost, 'unallocated' => $d['unallocated'] ?? 0.0,
                'cost_vehicle_km' => $km > 0 ? $vehicleCost / $km : null,
                'cost_driver_km' => $km > 0 ? $cost / $km : null,
                'cost_total_km' => $km > 0 ? ($cost + $vehicleCost) / $km : null,
                'revenue_km' => $km > 0 ? $revenue / $km : null,
                'result' => $contribution,
                'result_km' => $km > 0 ? $contribution / $km : null,
                'coverage' => ($cost + $vehicleCost) > 0 ? $revenue / ($cost + $vehicleCost) * 100 : null,
                'incomplet' => $incomplete, 'is_semi' => false,
                '_search' => mb_strtolower(implode(' ', array_merge([$names[$did]['nume'] ?? ''], $vehicles, array_values($benefs),
                    array_map(static fn (string $t): string => OperationalProfitabilityService::TRANSPORT_LABELS[$t] ?? $t, $typeKeys))), 'UTF-8'),
                '_trips' => array_map('intval', $tripIds),
            ];
        }
        return $rows;
    }

    // ================================================================== detaliu vehicul

    public function vehicleSummary(int $vid, string $from, string $to): array
    {
        $a = $this->analysis($from, $to);
        $info = $this->engine->vehicleInfo()[$vid] ?? throw new InvalidArgumentException('Vehiculul nu există.');
        $v = $a['vehicles'][$vid] ?? null;
        $isSemi = in_array($info['tip_vehicul'], self::SEMI_TYPES, true);
        $km = $v['km_real'] ?? 0.0;
        if ($isSemi) {
            $km = 0.0;
            foreach ($a['vehicles'] as $other) {
                $km += (float) ($other['semi_km'][$vid] ?? 0);
            }
        }
        $costs = $v['costs'] ?? [];
        $cats = [];
        foreach (OperationalProfitabilityService::CATEGORIES as $cat => [$label, $icon]) {
            $value = $cat === 'sofer' && !$isSemi ? (float) ($v['cost_driver'] ?? 0) : (float) ($costs[$cat] ?? 0);
            $cats[] = ['cod' => $cat, 'label' => $label, 'icon' => $icon, 'total' => $value, 'per_km' => $km > 0 ? $value / $km : null,
                'lipsa' => array_values(array_map(static fn (array $m): string => $m['nume'], array_filter($a['missing_elements'], static fn (array $m): bool => $m['categorie'] === $cat)))];
        }
        $drivers = $this->engine->driverNames();
        $driverIds = array_keys($v['by_driver'] ?? []);
        $costVehicle = $isSemi ? (float) ($v['own_cost'] ?? 0) : (float) ($v['cost_vehicle'] ?? 0);
        $costDriver = $isSemi ? 0.0 : (float) ($v['cost_driver'] ?? 0);
        $revenue = $isSemi ? 0.0 : (float) ($v['revenue'] ?? 0);
        return [
            'vehicle' => $info + ['tip_label' => self::TYPE_LABELS[$info['tip_vehicul']] ?? $info['tip_vehicul']],
            'is_semi' => $isSemi,
            'drivers' => array_map(static fn (int $d): array => ['id' => $d, 'nume' => $drivers[$d]['nume'] ?? '#' . $d, 'status' => $drivers[$d]['status'] ?? '',
                'km' => $v['by_driver'][$d]['km']], $driverIds),
            'semis' => array_map(fn (int $s): array => ['id' => $s, 'nr' => $this->engine->vehicleInfo()[$s]['nr'] ?? '#' . $s, 'km' => $v['semi_km'][$s] ?? 0.0,
                'cost' => $v['semi_costs'][$s] ?? 0.0], array_keys($v['semi_km'] ?? [])),
            'allocated_to' => array_map(fn (int $t): array => ['id' => $t, 'nr' => $this->engine->vehicleInfo()[$t]['nr'] ?? '#' . $t, 'cost' => $v['allocated_to'][$t]], array_keys($v['allocated_to'] ?? [])),
            'unallocated' => (float) ($v['unallocated_semi_cost'] ?? 0),
            'km' => $km, 'km_facturat' => (float) ($v['km_facturat'] ?? 0), 'km_dislocare' => (float) ($v['km_dislocare'] ?? 0), 'trips' => (int) ($v['trips'] ?? 0),
            'tone' => (float) ($v['tone'] ?? 0), 'revenue' => $revenue,
            'cost_vehicle' => $costVehicle, 'cost_driver' => $costDriver, 'cost_total' => $costVehicle + $costDriver,
            'cost_vehicle_km' => $km > 0 ? $costVehicle / $km : null,
            'cost_driver_km' => $km > 0 && !$isSemi ? $costDriver / $km : null,
            'cost_total_km' => $km > 0 ? ($costVehicle + $costDriver) / $km : null,
            'revenue_km' => $km > 0 && !$isSemi ? $revenue / $km : null,
            'result' => $revenue - $costVehicle - $costDriver,
            'result_km' => $km > 0 && !$isSemi ? ($revenue - $costVehicle - $costDriver) / $km : null,
            'coverage' => ($costVehicle + $costDriver) > 0 && !$isSemi ? $revenue / ($costVehicle + $costDriver) * 100 : null,
            'categorii' => $cats,
            'incomplet' => $v['incomplet'] ?? [],
            'venit_tarifar' => true, // toate cursele sunt „in_curs_facturare”: venit din tarif, nu incasat
        ];
    }

    /**
     * Inregistrarile unei categorii de cost pentru vehicul (si partea semiremorcilor / soferilor alocata lui).
     * Suma randurilor = totalul categoriei din rezumat (verificat in teste).
     */
    public function costLines(int $vid, string $cat, string $from, string $to): array
    {
        if (!isset(OperationalProfitabilityService::CATEGORIES[$cat])) {
            throw new InvalidArgumentException('Categorie necunoscută.');
        }
        $info = $this->engine->vehicleInfo();
        $isSemi = in_array($info[$vid]['tip_vehicul'] ?? '', self::SEMI_TYPES, true);
        $rows = [];
        foreach (OperationalProfitabilityService::months($from, $to) as $m) {
            $model = $this->engine->monthModel($m['month'], $m['start'], $m['end']);
            // sursele: vehiculul insusi (100%) + semiremorcile folosite (cota lor pe acest tractor)
            $sources = [[$vid, 1.0, null]];
            if (!$isSemi) {
                foreach ($model['semi_shares'] as $semiId => $sh) {
                    if (isset($sh['shares'][$vid])) {
                        $sources[] = [(int) $semiId, (float) $sh['shares'][$vid], $sh['basis']];
                    }
                }
            }
            if ($cat === 'sofer') {
                if (!$isSemi) {
                    foreach ($model['drivers'] as $did => $d) {
                        $kmTotal = array_sum($d['vehicles']);
                        $kmHere = (float) ($d['vehicles'][$vid] ?? 0);
                        if ($kmTotal <= 0 || $kmHere <= 0) {
                            continue;
                        }
                        $share = $kmHere / $kmTotal;
                        foreach ($d['cost_by_code'] as $code => $value) {
                            $rows[] = $this->line($m['end'], (self::CODE_LABELS[$code] ?? $code) . ' — ' . $d['nume'],
                                $code === 'salariu_soferi' ? ($d['cost_source'] === 'personal_luna' ? 'Contabilitate Personal (finalizat)' : 'Salariu configurat × multiplicator (estimat)') : 'Cheltuieli / configurare',
                                $d['nume'], $value * $share, [
                                    'Luna' => $m['month'], 'Cost lunar șofer' => $this->money($value), 'Km șofer pe vehicul' => $this->num($kmHere) . ' din ' . $this->num($kmTotal),
                                    'Cotă alocată' => $this->pct($share), 'Sursă' => $d['cost_source'] === 'personal_luna' ? 'personal_luna' : ($code === 'salariu_soferi' ? 'soferi.salariu / salary_history' : $code),
                                ], $code === 'salariu_soferi' ? 'contabilitate_personal' : null, $code === 'salariu_soferi' && $d['cost_source'] !== 'personal_luna');
                        }
                    }
                }
                // + costurile de personal ramase pe vehicul (ex. diurna fara sofer): bucla generica de mai jos
            }
            foreach ($sources as [$sourceId, $share, $basis]) {
                $who = $sourceId === $vid ? ($info[$vid]['nr'] ?? '') : ($info[$sourceId]['nr'] ?? '#' . $sourceId) . ' (' . $this->pct($share) . ', după ' . $basis . ')';
                if ($cat === 'combustibil' && $sourceId === $vid) {
                    array_push($rows, ...$this->fuelLines($vid, $m['start'], $m['end']));
                    continue;
                }
                if ($cat === 'reparatii') {
                    array_push($rows, ...$this->maintenanceLines($sourceId, $m['start'], $m['end'], $share, $who));
                    continue;
                }
                $elements = $model['engine_units'][$sourceId]['elements'] ?? [];
                foreach ($elements as $code => $el) {
                    if (OperationalProfitabilityService::categoryOf((string) $code) !== $cat || ($cat === 'combustibil')) {
                        continue;
                    }
                    foreach ($el['detail'] as $d) {
                        $rows[] = $this->line($m['end'], (string) $el['label'] . ' — ' . $m['month'], (string) ($d['sursa'] ?? 'Calcul'), $who, (float) ($d['valoare'] ?? 0) * $share, array_filter([
                            'Element' => (string) $el['label'], 'Sursă' => (string) ($d['sursa'] ?? ''), 'Valoare brută' => (string) ($d['brut'] ?? ''),
                            'Normalizare' => (string) ($d['normalizare'] ?? ''), 'Cotă' => $share < 1 ? $this->pct($share) : '',
                        ]), $cat === 'taxe' ? 'documente' : null);
                    }
                }
                if ($cat === 'anvelope' && isset($model['vehicles'][$sourceId]['tire_semi'])) {
                    $t = $model['vehicles'][$sourceId]['tire_semi'];
                    $rows[] = $this->line($m['end'], 'Anvelope semiremorcă — ' . $m['month'], 'Preț achiziție / durată viață (km)', $who, $t['rate'] * $t['km'] * $share, [
                        'Rată' => number_format($t['rate'], 4, ',', '.') . ' lei/km', 'Km cu semiremorca' => $this->num($t['km']), 'Cotă' => $this->pct($share)]);
                }
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['data'], (string) $a['data']));
        $total = array_sum(array_column($rows, 'suma'));
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * O singura inregistrare (fisa din panoul peste pagina): aceeasi lista ca costLines(), randul $index,
     * plus contextul pentru afisare (vehicul, categorie, km reali si totalul categoriei). Doar citire.
     */
    public function costLineDetail(int $vid, string $cat, int $index, string $from, string $to): ?array
    {
        $lines = $this->costLines($vid, $cat, $from, $to);
        $line = $lines['rows'][$index] ?? null;
        if ($line === null) {
            return null;
        }
        $summary = $this->vehicleSummary($vid, $from, $to);
        $category = null;
        foreach ($summary['categorii'] as $c) {
            if ($c['cod'] === $cat) {
                $category = $c;
            }
        }
        return [
            'linie' => $line,
            'index' => $index,
            'categorie' => $category ?? ['cod' => $cat, 'label' => OperationalProfitabilityService::CATEGORIES[$cat][0], 'total' => $lines['total'], 'per_km' => null, 'lipsa' => []],
            'vehicul' => $summary['vehicle'],
            'subiect' => ['label' => 'Vehicul', 'text' => trim((string) ($summary['vehicle']['nr'] ?? '') . (!empty($summary['vehicle']['model']) ? ' — ' . $summary['vehicle']['model'] : ''))],
            'km' => (float) $summary['km'],
            'km_label' => 'Km reali vehicul (perioadă)',
            'total_categorie' => (float) $lines['total'],
            'inregistrari_categorie' => count($lines['rows']),
        ];
    }

    private function fuelLines(int $vid, string $start, string $end): array
    {
        $info = $this->engine->vehicleInfo()[$vid] ?? null;
        if ($info === null) {
            return [];
        }
        $settings = (new OperationalCostModel($this->db))->getSettings();
        $vat = (float) ($settings['tva_carburant_fallback'] ?? 21) ?: 21.0;
        $stmt = $this->db->prepare(
            'SELECT f.id, f.fillup_datetime, f.fuel_type, f.quantity_liters, f.total_value, f.station_name, f.driver_name, f.odometer_km,
                    f.total_value / (1 + COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(f.raw_payload, "$.cota_tva")) AS DECIMAL(6,2)), :vat) / 100) AS net
               FROM fuel_fillups f
              WHERE f.source_type = "api" AND f.fuel_type IN ("motorina", "adblue") AND f.quantity_liters > 0
                AND REPLACE(UPPER(f.vehicle_registration), " ", "") = :reg
                AND f.fillup_datetime >= :ds AND f.fillup_datetime <= :de'
        );
        $stmt->execute([':vat' => $vat, ':reg' => str_replace(' ', '', strtoupper($info['nr'])), ':ds' => $start . ' 00:00:00', ':de' => $end . ' 23:59:59']);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $out[] = $this->line(substr((string) $f['fillup_datetime'], 0, 10), ($f['fuel_type'] === 'adblue' ? 'AdBlue ' : 'Motorină ') . number_format((float) $f['quantity_liters'], 2, ',', '.') . ' l',
                (string) ($f['station_name'] ?: 'CardOil'), (string) ($f['driver_name'] ?: $info['nr']), (float) $f['net'], [
                    'Brut (cu TVA)' => $this->money((float) $f['total_value']), 'Net' => $this->money((float) $f['net']), 'Litri' => number_format((float) $f['quantity_liters'], 2, ',', '.'),
                    'Odometru' => $f['odometer_km'] !== null ? $this->num((float) $f['odometer_km']) . ' km' : '—', 'Sursă' => 'CardOil (fuel_fillups #' . $f['id'] . ')',
                ], 'carburanti', false, (int) $f['id']);
        }
        return $out;
    }

    private function maintenanceLines(int $vid, string $start, string $end, float $share, string $who): array
    {
        $capitalized = CostNature::sqlIsCapitalized('m');
        $stmt = $this->db->prepare(
            "SELECT m.id, m.data_interventie, m.tip_interventie, m.descriere, m.cost, m.cost_piese, m.cost_manopera, m.atelier, m.furnizor_piesa, m.km_interventie,
                    m.natura_cost, m.tratament_contabil, m.fisier_stocat, m.sursa_tip, m.sursa_id, m.source_intervention_id,
                    (SELECT si.invoice_number FROM invoice_vehicle_repairs ivr JOIN supplier_invoices si ON si.id = ivr.invoice_id
                      WHERE ivr.source_maintenance_id = m.id ORDER BY si.id LIMIT 1) AS factura
               FROM mentenanta m
              WHERE m.vehicle_id = :v AND m.data_interventie BETWEEN :ds AND :de
                AND m.tip_interventie NOT LIKE 'Anvelopa - %'
                AND (m.status_interventie IS NULL OR m.status_interventie <> 'anulata')
                AND NOT $capitalized"
        );
        $stmt->execute([':v' => $vid, ':ds' => $start, ':de' => $end]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $out[] = $this->line((string) $m['data_interventie'], (string) $m['tip_interventie'], (string) ($m['atelier'] ?: $m['furnizor_piesa'] ?: '—'), $who, (float) $m['cost'] * $share, array_filter([
                'Piese' => $this->money((float) $m['cost_piese']), 'Manoperă' => $this->money((float) $m['cost_manopera']),
                'Natura costului' => CostNature::NATURES[(string) $m['natura_cost']] ?? 'Reparație',
                'Tratament contabil' => CostNature::TREATMENTS[(string) $m['tratament_contabil']] ?? '',
                'Cotă' => $share < 1 ? $this->pct($share) : '',
                'Sursă' => 'mentenanta #' . $m['id'] . ($m['source_intervention_id'] ? ' · intervenția #' . $m['source_intervention_id'] : '') . ($m['sursa_tip'] === 'ocr_reparatie' ? ' · factura OCR #' . $m['sursa_id'] : ''),
                'Descriere' => (string) ($m['descriere'] ?? ''),
            ]), 'mentenanta', false, (int) $m['id'], $m['km_interventie'] !== null ? (int) $m['km_interventie'] : null, !empty($m['fisier_stocat']))
                // numarul facturii din oglinda Reparatii (doar pentru afisare; oglinda nu se aduna niciodata)
                + ['document' => $m['factura'] !== null ? (string) $m['factura'] : null];
        }
        return $out;
    }

    private function line(string $date, string $desc, string $source, string $who, float $amount, array $details, ?string $link = null, bool $estimated = false, ?int $sourceId = null, ?int $km = null, bool $hasFile = false): array
    {
        return ['data' => $date, 'descriere' => $desc, 'sursa' => $source, 'pe' => $who, 'suma' => $amount, 'detalii' => $details,
            'link' => $link, 'estimat' => $estimated, 'source_id' => $sourceId, 'km' => $km, 'are_fisier' => $hasFile];
    }

    /** Cursele vehiculului (sau ale soferului) din interval, cu venitul si costul atribuit. */
    public function trips(string $scope, int $id, string $from, string $to, ?int $benef = null, int $page = 1, int $perPage = 15): array
    {
        $a = $this->analysis($from, $to);
        $legs = $scope === 'sofer' ? $this->engine->legs($from, $to, null, [$id]) : $this->engine->legs($from, $to, [$id]);
        if ($benef !== null) {
            $legs = array_values(array_filter($legs, static fn (array $l): bool => (int) $l['beneficiar_id'] === $benef));
        }
        $info = $this->engine->vehicleInfo();
        $drivers = $this->engine->driverNames();
        $benefs = $this->beneficiaries();
        $total = count($legs);
        $legs = array_reverse($legs);
        $slice = array_slice($legs, max(0, ($page - 1) * $perPage), $perPage);
        $rows = [];
        foreach ($slice as $l) {
            $vid = (int) $l['vehicle_id'];
            $v = $a['vehicles'][$vid] ?? null;
            $costKm = $v !== null && $v['km_real'] > 0 ? $v['cost_total'] / $v['km_real'] : null;
            $cost = $costKm !== null ? $costKm * (float) $l['km_real'] : null;
            $semi = $this->engine->semiAt($vid, (string) $l['moment']);
            $rows[] = [
                'cursa_id' => (int) $l['cursa_id'], 'data' => (string) $l['data_inceput'],
                'sofer' => $drivers[(int) $l['driver_id']]['nume'] ?? '—',
                'vehicul' => ($info[$vid]['nr'] ?? '#' . $vid) . ($semi !== null ? ' + ' . ($info[$semi]['nr'] ?? '#' . $semi) : ''),
                'tip' => OperationalProfitabilityService::TRANSPORT_LABELS[$l['tip_transport']] ?? $l['tip_transport'], 'tip_cod' => $l['tip_transport'],
                'beneficiar' => $benefs[(int) $l['beneficiar_id']] ?? '—',
                'km' => (float) $l['km_real'], 'km_facturat' => (float) $l['km_facturat'], 'tone' => (float) $l['tone'],
                'durata' => $l['durata_cursa_minute'] !== null ? (int) $l['durata_cursa_minute'] : null,
                'venit' => (float) $l['venit'], 'cost' => $cost, 'rezultat' => $cost !== null ? (float) $l['venit'] - $cost : null,
                'status_facturare' => (string) $l['status_facturare'],
            ];
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /** Rentabilitatea pe beneficiar a vehiculului / soferului (costul pe km al vehiculului × km-ii beneficiarului). */
    public function beneficiaryBreakdown(string $scope, int $id, string $from, string $to): array
    {
        $a = $this->analysis($from, $to);
        $benefs = $this->beneficiaries();
        $acc = [];
        $vehicleIds = $scope === 'sofer' ? array_keys(array_filter($a['vehicles'], static fn (array $v): bool => isset($v['by_driver'][$id]))) : [$id];
        foreach ($vehicleIds as $vid) {
            $v = $a['vehicles'][$vid] ?? null;
            if ($v === null || $v['km_real'] <= 0) {
                continue;
            }
            $costKm = $v['cost_total'] / $v['km_real'];
            $driverShare = $scope === 'sofer' ? ($v['by_driver'][$id]['km'] / $v['km_real']) : 1.0;
            foreach ($v['by_benef'] as $bid => $act) {
                $km = $act['km'] * $driverShare;
                $acc[$bid] ??= ['beneficiar_id' => $bid, 'nume' => $benefs[$bid] ?? 'Fără beneficiar', 'trips' => 0.0, 'km' => 0.0, 'revenue' => 0.0, 'cost' => 0.0];
                $acc[$bid]['trips'] += $act['trips'] * $driverShare;
                $acc[$bid]['km'] += $km;
                $acc[$bid]['revenue'] += $act['revenue'] * $driverShare;
                $acc[$bid]['cost'] += $costKm * $km;
            }
        }
        foreach ($acc as &$b) {
            $b['trips'] = (int) round($b['trips']);
            $b['cost_km'] = $b['km'] > 0 ? $b['cost'] / $b['km'] : null;
            $b['revenue_km'] = $b['km'] > 0 ? $b['revenue'] / $b['km'] : null;
            $b['result'] = $b['revenue'] - $b['cost'];
            $b['result_km'] = $b['km'] > 0 ? $b['result'] / $b['km'] : null;
        }
        unset($b);
        usort($acc, static fn (array $x, array $y): int => $y['revenue'] <=> $x['revenue']);
        return array_values($acc);
    }

    /** Investitiile: dosarul de achizitie + proiectele vehiculului si ale semiremorcilor folosite (din Istoric Financiar). */
    public function investments(int $vid, string $from, string $to): array
    {
        $summary = $this->vehicleSummary($vid, $from, $to);
        $ids = array_merge([$vid], array_column($summary['semis'], 'id'));
        $register = new VehicleFinancialRegisterService($this->db);
        $out = [];
        foreach ($ids as $id) {
            $stmt = $this->db->prepare("SELECT id FROM achizitii_vehicule WHERE vehicle_id = :v AND status <> 'anulat' ORDER BY id DESC LIMIT 1");
            $stmt->execute([':v' => $id]);
            $acq = $stmt->fetchColumn();
            $detail = $register->detail($acq !== false ? (int) $acq : null, $acq !== false ? null : $id);
            $capital = array_values(array_filter($detail['istoric_financiar'] ?? [], static fn (array $r): bool => in_array($r['clasa'], ['capital'], true)));
            $out[] = [
                'vehicle_id' => $id, 'nr' => $this->engine->vehicleInfo()[$id]['nr'] ?? '#' . $id, 'semiremorca' => $id !== $vid,
                'dosar' => $detail['dosar'] !== null ? ['id' => (int) $detail['dosar']['id'], 'cod' => $detail['dosar']['cod_dosar'], 'status' => $detail['dosar']['status']] : null,
                'stadii' => $detail['stadii'], 'sumar' => $detail['sumar'], 'valoare' => $detail['valoare'],
                'proiecte' => array_map(static fn (array $p): array => ['id' => (int) $p['proiect']['id'], 'denumire' => $p['proiect']['denumire'], 'categorie' => $p['categorie_label'],
                    'status' => $p['proiect']['status'], 'tratament' => $p['proiect']['tratament_contabil'], 'buget' => $p['totaluri']['buget_planificat'],
                    'cost_real' => $p['totaluri']['cost_real'], 'surse' => $p['totaluri']['surse'], 'neinregistrate' => $p['totaluri']['neinregistrate'],
                    'apt_legal' => $p['pregatire']['apt_legal'], 'pus_in_functiune' => $p['pregatire']['pus_in_functiune'], 'durata_utila_luni' => $p['proiect']['durata_utila_luni'],
                    'data_punere_in_functiune' => $p['proiect']['data_punere_in_functiune']], $detail['proiecte']),
                'capital_mentenanta' => $capital,
            ];
        }
        return $out;
    }

    /** Documentele vehiculului si ale semiremorcilor folosite (pagina Documente) + tipurile obligatorii lipsa. */
    public function documents(int $vid, string $from, string $to): array
    {
        $summary = $this->vehicleSummary($vid, $from, $to);
        $ids = array_merge([$vid], array_column($summary['semis'], 'id'));
        $info = $this->engine->vehicleInfo();
        $today = date('Y-m-d');
        $out = [];
        foreach ($ids as $id) {
            $docs = $this->db->prepare('SELECT id, tip_document, numar_document, data_expirare, fisier_stocat FROM documente WHERE vehicle_id = :v ORDER BY tip_document, data_expirare DESC');
            $docs->execute([':v' => $id]);
            $rows = [];
            $present = [];
            foreach ($docs->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $present[mb_strtoupper(trim((string) $d['tip_document']), 'UTF-8')] = true;
                $state = $d['data_expirare'] === null ? 'fara_expirare' : ((string) $d['data_expirare'] < $today ? 'expirat'
                    : ((string) $d['data_expirare'] <= date('Y-m-d', strtotime('+30 days')) ? 'expira_curand' : 'valabil'));
                $rows[] = ['id' => (int) $d['id'], 'tip' => $d['tip_document'], 'numar' => $d['numar_document'], 'expira' => $d['data_expirare'], 'stare' => $state,
                    'url' => !empty($d['fisier_stocat']) && function_exists('document_file_url') ? document_file_url((string) $d['fisier_stocat']) : null];
            }
            $missing = [];
            $type = (string) ($info[$id]['tip_vehicul'] ?? '');
            try {
                $req = $this->db->prepare('SELECT DISTINCT document_type FROM configurare_costuri_documente_vehicule WHERE vehicle_type = :t AND obligatoriu = 1');
                $req->execute([':t' => $type === 'semiremorca' ? 'semiremorca_primar' : $type]);
                foreach ($req->fetchAll(PDO::FETCH_COLUMN) as $t) {
                    if (!isset($present[mb_strtoupper(trim((string) $t), 'UTF-8')])) {
                        $missing[] = (string) $t;
                    }
                }
            } catch (Throwable) {
                $missing = [];
            }
            $out[] = ['vehicle_id' => $id, 'nr' => $info[$id]['nr'] ?? '#' . $id, 'semiremorca' => $id !== $vid, 'documente' => $rows, 'lipsa' => $missing];
        }
        return $out;
    }

    /**
     * Soferi / simulare: istoric (soferii care au condus vehiculul: costul lor real alocat) si simulare
     * (orice sofer activ: costul lui lunar / km-ii lunari ai vehiculului). Nu schimba nicio asignare.
     */
    public function driverSimulation(int $vid, string $from, string $to): array
    {
        $a = $this->analysis($from, $to);
        $summary = $this->vehicleSummary($vid, $from, $to);
        $v = $a['vehicles'][$vid] ?? null;
        $months = max(1, count($a['months']));
        $kmMonth = $summary['km'] / $months;
        $names = $this->engine->driverNames();
        $assigned = [];
        foreach ($this->db->query('SELECT driver_id, vehicle_id, is_primary FROM soferi_vehicule')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int) $r['vehicle_id'] === $vid) {
                $assigned[(int) $r['driver_id']] = (int) $r['is_primary'] === 1 ? 'principal' : 'secundar';
            }
        }
        foreach ($this->db->query('SELECT id FROM soferi WHERE vehicle_id = ' . (int) $vid)->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $assigned[(int) $d] ??= 'principal';
        }
        $expired = [];
        foreach ($this->db->query("SELECT driver_id, COUNT(*) AS c FROM documente_soferi WHERE data_expirare IS NOT NULL AND data_expirare < CURDATE() GROUP BY driver_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $expired[(int) $r['driver_id']] = (int) $r['c'];
        }
        $model = new OperationalCostModel($this->db);
        $salaries = $model->getActiveDriversWithSalary($to);
        $mult = (float) ($model->getSettings()['salariu_multiplicator'] ?? 1) ?: 1.0;
        $rows = [];
        foreach ($names as $did => $n) {
            $drove = isset($v['by_driver'][$did]);
            if (!$drove && $n['status'] !== 'activ') {
                continue;
            }
            $d = $a['drivers'][$did] ?? null;
            $monthlyCost = $d !== null && count($d['months']) > 0 ? $d['cost'] / count($d['months'])
                : (isset($salaries[$did]['salariu_luna']) && $salaries[$did]['salariu_luna'] > 0 ? $salaries[$did]['salariu_luna'] * $mult : null);
            $histKm = $drove ? $v['by_driver'][$did]['km'] : 0.0;
            $histCost = $drove ? (float) ($v['driver_costs'][$did] ?? 0) : null;
            $driverKm = $kmMonth > 0 && $monthlyCost !== null ? $monthlyCost / $kmMonth : null;
            $combined = $summary['cost_vehicle_km'] !== null && $driverKm !== null ? $summary['cost_vehicle_km'] + $driverKm : null;
            $rows[] = [
                'driver_id' => $did, 'nume' => $n['nume'], 'status' => $n['status'],
                'eligibilitate' => $drove ? 'A condus vehiculul în perioadă' : (isset($assigned[$did]) ? 'Asignat vehiculului (' . $assigned[$did] . ')' : 'Fără istoric pe acest vehicul'),
                'eligibil' => $n['status'] === 'activ' && empty($expired[$did]),
                'documente_expirate' => $expired[$did] ?? 0,
                'istoric' => $drove ? ['km' => $histKm, 'cost_km' => $histKm > 0 ? $histCost / $histKm : null, 'trips' => $v['by_driver'][$did]['trips'],
                    'revenue_km' => $histKm > 0 ? $v['by_driver'][$did]['revenue'] / $histKm : null] : null,
                'cost_lunar' => $monthlyCost,
                'cost_sursa' => $d !== null ? (isset($d['cost_source']['personal_luna']) ? 'pontaj' : 'salariu configurat') : ($monthlyCost !== null ? 'salariu configurat' : 'lipsă'),
                'sim_driver_km' => $driverKm, 'sim_combined_km' => $combined,
            ];
        }
        usort($rows, static fn (array $x, array $y): int => [$y['istoric'] !== null, $y['eligibil']] <=> [$x['istoric'] !== null, $x['eligibil']] ?: strcmp($x['nume'], $y['nume']));
        return [
            'vehicle_cost_km' => $summary['cost_vehicle_km'], 'revenue_km' => $summary['revenue_km'], 'km_luna' => $kmMonth,
            'rows' => $rows,
            'nota' => 'Simularea nu schimbă asignarea. Costul șoferului / km = costul lui lunar ÷ km lunari ai vehiculului în perioadă.',
        ];
    }

    // ================================================================== detaliu sofer

    public function driverSummary(int $did, string $from, string $to): array
    {
        $a = $this->analysis($from, $to);
        $names = $this->engine->driverNames();
        if (!isset($names[$did])) {
            throw new InvalidArgumentException('Șoferul nu există.');
        }
        $d = $a['drivers'][$did] ?? null;
        $rows = $this->driverRows($a, '', 0, $this->beneficiaries());
        $row = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] === $did))[0] ?? null;
        $info = $this->engine->vehicleInfo();
        $cats = [];
        foreach (($d['cost_by_code'] ?? []) as $code => $value) {
            $cats[] = ['cod' => $code, 'label' => self::CODE_LABELS[$code] ?? $code, 'total' => $value, 'per_km' => ($d['km'] ?? 0) > 0 ? $value / $d['km'] : null, 'lipsa' => []];
        }
        $vehicles = [];
        foreach (($d['vehicles'] ?? []) as $vid => $km) {
            $v = $a['vehicles'][$vid] ?? null;
            $vehicles[] = ['id' => (int) $vid, 'nr' => $info[$vid]['nr'] ?? '#' . $vid, 'km' => $km, 'trips' => $v['by_driver'][$did]['trips'] ?? 0,
                'revenue' => $v['by_driver'][$did]['revenue'] ?? 0.0, 'cost_vehicle_km' => $v !== null && $v['km_real'] > 0 ? $v['cost_vehicle'] / $v['km_real'] : null];
        }
        return ['driver' => ['id' => $did] + $names[$did], 'row' => $row, 'categorii' => $cats, 'vehicule' => $vehicles,
            'luni' => $d['months'] ?? [], 'neocupat' => $d['unallocated'] ?? 0.0, 'cost_total' => $d['cost'] ?? 0.0, 'km' => (float) ($d['km'] ?? 0)];
    }

    /**
     * Inregistrarile unei componente de cost a soferului (salariu, diurna, ...): cate una pe luna, exact
     * valoarea lunara din motor (aceeasi care se aduna in driverSummary → suma randurilor = totalul componentei).
     */
    public function driverCostLines(int $did, string $code, string $from, string $to): array
    {
        if (!isset($this->engine->driverNames()[$did])) {
            throw new InvalidArgumentException('Șoferul nu există.');
        }
        $info = $this->engine->vehicleInfo();
        $label = self::CODE_LABELS[$code] ?? $code;
        $rows = [];
        foreach (OperationalProfitabilityService::months($from, $to) as $m) {
            $d = $this->engine->monthModel($m['month'], $m['start'], $m['end'])['drivers'][$did] ?? null;
            if ($d === null || !array_key_exists($code, $d['cost_by_code'])) {
                continue;
            }
            $value = (float) $d['cost_by_code'][$code];
            $kmTotal = array_sum($d['vehicles']);
            $split = [];
            foreach ($d['vehicles'] as $vid => $km) {
                $split[] = ($info[$vid]['nr'] ?? '#' . $vid) . ' (' . $this->pct($kmTotal > 0 ? $km / $kmTotal : 0) . ')';
            }
            $verified = $d['cost_source'] === 'personal_luna';
            $isSalary = $code === 'salariu_soferi';
            $rows[] = $this->line($m['end'], $label . ' — ' . $m['month'],
                $isSalary ? ($verified ? 'Contabilitate Personal (finalizat)' : 'Salariu configurat × multiplicator (estimat)') : 'Cheltuieli / configurare',
                $split !== [] ? implode(', ', $split) : 'Fără curse (cost neocupat)', $value, array_filter([
                    'Luna' => $m['month'],
                    'Sursă' => $verified && $isSalary ? 'personal_luna' : ($isSalary ? 'soferi.salariu / salary_history' : $code),
                    'Km șofer în lună' => $this->num((float) $d['km']) . ' km',
                    'Alocat pe vehicule' => $kmTotal > 0 ? $this->money($value * min(1.0, $d['allocated'] / max($d['cost'], 0.000001))) : '',
                    'Cost neocupat (fără km)' => $kmTotal <= 0 ? $this->money($value) : '',
                ]), $isSalary ? 'contabilitate_personal' : null, $isSalary && !$verified);
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['data'], (string) $a['data']));
        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'suma'))];
    }

    /** Fisa unei inregistrari de cost a soferului (acelasi panou ca la vehicul). */
    public function driverCostLineDetail(int $did, string $code, int $index, string $from, string $to): ?array
    {
        $lines = $this->driverCostLines($did, $code, $from, $to);
        $line = $lines['rows'][$index] ?? null;
        if ($line === null) {
            return null;
        }
        $summary = $this->driverSummary($did, $from, $to);
        $category = ['cod' => $code, 'label' => self::CODE_LABELS[$code] ?? $code, 'total' => $lines['total'], 'per_km' => null, 'lipsa' => []];
        foreach ($summary['categorii'] as $c) {
            if ($c['cod'] === $code) {
                $category = $c;
            }
        }
        return [
            'linie' => $line,
            'index' => $index,
            'categorie' => $category,
            'subiect' => ['label' => 'Șofer', 'text' => (string) $summary['driver']['nume']],
            'km' => (float) $summary['km'],
            'km_label' => 'Km reali șofer (perioadă)',
            'total_categorie' => (float) $lines['total'],
            'inregistrari_categorie' => count($lines['rows']),
        ];
    }

    // ================================================================== export

    public function exportCsv(array $f): string
    {
        $f['per_page'] = 100;
        $f['page'] = 1;
        $first = $this->list($f);
        $rows = $first['rows'];
        for ($p = 2; $p <= $first['pages']; $p++) {
            $f['page'] = $p;
            $rows = array_merge($rows, $this->list($f)['rows']);
        }
        $fmt = static fn (?float $v, int $d = 2): string => $v === null ? '' : number_format($v, $d, ',', '');
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$first['mode'] === 'soferi' ? 'Șofer' : 'Vehicul', $first['mode'] === 'soferi' ? 'Vehicule' : 'Șofer', 'Tip transport', 'Km', 'Curse', 'Venit (lei)',
            'Cost vehicul (lei)', 'Cost șofer (lei)', 'Cost vehicul/km', 'Cost șofer/km', 'Cost total/km', 'Venit/km', 'Rezultat/km', 'Acoperire %', 'Date incomplete'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [$r['label'], $r['drivers'], $r['transport_label'] ?? '', $fmt($r['km'], 0), $r['trips'], $fmt($r['revenue']), $fmt($r['cost_vehicle']), $fmt($r['cost_driver']),
                $fmt($r['cost_vehicle_km']), $fmt($r['cost_driver_km']), $fmt($r['cost_total_km']), $fmt($r['revenue_km']), $fmt($r['result_km']), $fmt($r['coverage'], 1),
                implode(' | ', $r['incomplet'])], ';');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    // ================================================================== utilitare

    private ?array $benefCache = null;

    /** @return array<int,string> */
    public function beneficiaries(): array
    {
        return $this->benefCache ??= array_map('strval', array_column($this->db->query('SELECT id, nume FROM configurare_beneficiari_transport ORDER BY nume')->fetchAll(PDO::FETCH_ASSOC), 'nume', 'id'));
    }

    private function money(float $v): string
    {
        return number_format($v, 2, ',', '.') . ' lei';
    }

    private function num(float $v): string
    {
        return number_format($v, 0, ',', '.');
    }

    private function pct(float $v): string
    {
        return number_format($v * 100, 1, ',', '.') . '%';
    }
}
