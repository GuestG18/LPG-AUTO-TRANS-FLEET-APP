<?php
declare(strict_types=1);

require_once __DIR__ . '/OperationalCostService.php';
require_once __DIR__ . '/CostBreakEvenService.php';
require_once __DIR__ . '/CostNature.php';
require_once __DIR__ . '/FinancialFoundationSchema.php';

/**
 * Rentabilitate Operationala: cat costa efectiv vehiculul / ansamblul + soferul, cat venit produce,
 * pentru ce beneficiari si unde se castiga sau se pierd bani.
 *
 * Nu are ledger propriu. Pentru fiecare luna din interval:
 *  1. costurile pe vehicul FIZIC vin din motorul existent Cost operational / km, in modul istoric
 *     (OperationalCostService::computeHistoricalMonth): aceleasi ~30 de elemente, aceeasi excludere a
 *     investitiilor capitalizabile, fara lipirea semiremorcii de cuplajul de AZI, personalul pe sofer;
 *  2. activitatea (km reali, km facturabili, venit tarifar, beneficiar, tip) vine din curse_dispecer
 *     (+ curse_segmente), la nivel de „picior” de cursa;
 *  3. semiremorca: pentru fiecare picior se citeste cuplajul VALABIL la data cursei (vehicule_cuplaje
 *     data_start / data_end); costurile semiremorcii din luna se impart tractoarelor dupa km-ii facuti
 *     cu ea (fara km: dupa timpul de cuplare); partea necuplata ramane pe semiremorca — nimic de doua ori;
 *  4. soferul: costul lui din luna (Contabilitate Personal finalizat > salariu configurat × multiplicator,
 *     marcat „estimat”; + documente, diurna, alte costuri de personal) se imparte vehiculelor dupa km-ii lui
 *     reali pe fiecare; luna fara km = cost neocupat, vizibil doar in vederea Soferi.
 * Valorile exacte se pastreaza intern; rotunjirea se face doar la afisare.
 */
final class OperationalProfitabilityService
{
    public const CATEGORIES = [
        'combustibil' => ['Combustibil', 'bi-fuel-pump'],
        'reparatii' => ['Reparații & Mentenanță', 'bi-wrench-adjustable'],
        'anvelope' => ['Anvelope', 'bi-record-circle'],
        'taxe' => ['Taxe & Obligații', 'bi-file-earmark-text'],
        'amortizare' => ['Amortizare', 'bi-stack'],
        'sofer' => ['Costuri șofer', 'bi-person-badge'],
        'alte' => ['Alte costuri', 'bi-three-dots'],
    ];
    private const ELEMENT_CATEGORY = [
        'carburant' => 'combustibil', 'adblue' => 'combustibil',
        'reparatii' => 'reparatii', 'revizii' => 'reparatii', 'piese_ocr' => 'reparatii',
        'anvelope' => 'anvelope',
        'rca' => 'taxe', 'casco' => 'taxe', 'itp' => 'taxe', 'rovinieta' => 'taxe', 'iprochim' => 'taxe', 'metrologie' => 'taxe',
        'tahograf' => 'taxe', 'copie_conforma' => 'taxe', 'taxe_drum_autorizatii' => 'taxe', 'taxe_drum_realizate' => 'taxe',
        'impozit_auto' => 'taxe', 'agreare' => 'taxe', 'asigurare_risc' => 'taxe', 'tped' => 'taxe',
        'amortizare' => 'amortizare',
        'salariu_soferi' => 'sofer', 'analize_soferi' => 'sofer', 'cursuri_soferi' => 'sofer', 'ssm_su' => 'sofer', 'diurna' => 'sofer',
    ];
    public const TRANSPORT_LABELS = [
        'primar' => 'Primar km', 'primar_tona' => 'Primar tone', 'distributie' => 'Distribuție',
        'primar_distributie' => 'Primar + Distribuție', 'compresor' => 'Compresor',
    ];
    private const SEMI_TYPES = ['semiremorca', 'semiremorca_primar', 'semiremorca_distributie'];
    /** Elemente pe evenimente: „lipsa” inseamna doar ca nu au existat evenimente in luna, nu date lipsa. */
    private const EVENT_ELEMENTS = ['reparatii', 'revizii', 'carburant', 'adblue', 'diurna', 'taxe_drum_realizate', 'taxe_drum_autorizatii', 'piese_ocr'];
    private const CACHE_VERSION = 'v5'; // v4: by_seg (beneficiar|tip|loc|zona); v5: + tone / clienti / capacitate pe segment
    /** Valorile unui segment by_seg (beneficiar|tip|loc|zona): activitate + incarcare (tone, capacitate, clienti). */
    public const SEG_ZERO = ['km' => 0.0, 'trips' => 0, 'revenue' => 0.0, 'tone_inc' => 0.0, 'tone_liv' => 0.0, 'cap' => 0.0,
        'tone_lipsa' => 0.0, 'tone_peste' => 0.0, 'clienti' => 0.0, 'cap_neconf' => 0];

    private OperationalCostService $engine;
    private ?array $couplings = null;
    private ?string $fingerprint = null;
    /** @var array<string,array> */
    private array $monthMemo = [];

    public function __construct(private PDO $db, private ?string $cacheDir = null)
    {
        $this->engine = new OperationalCostService($db);
        $this->cacheDir = $cacheDir ?? dirname(__DIR__, 2) . '/storage/cache/rentabilitate';
    }

    public static function categoryOf(string $elementCode): string
    {
        return self::ELEMENT_CATEGORY[$elementCode] ?? 'alte';
    }

    // ================================================================== interval -> luni

    /** @return list<array{month:string,start:string,end:string,full:bool}> */
    public static function months(string $from, string $to): array
    {
        $out = [];
        $cursor = substr($from, 0, 7) . '-01';
        while ($cursor <= $to) {
            $monthEnd = date('Y-m-t', strtotime($cursor));
            $start = max($from, $cursor);
            $end = min($to, $monthEnd);
            $out[] = ['month' => substr($cursor, 0, 7), 'start' => $start, 'end' => $end, 'full' => $start === $cursor && $end === $monthEnd];
            $cursor = date('Y-m-d', strtotime($monthEnd . ' +1 day'));
        }
        return $out;
    }

    // ================================================================== analiza pe interval

    /**
     * Modelul complet pe interval: vehicule (fizice + ansambluri), soferi, beneficiari, calitatea datelor.
     */
    public function analyze(string $from, string $to): array
    {
        $vehicles = [];
        $drivers = [];
        $quality = [];
        $monthsMeta = [];
        foreach (self::months($from, $to) as $m) {
            $model = $this->monthModel($m['month'], $m['start'], $m['end']);
            $monthsMeta[] = ['month' => $m['month'], 'start' => $m['start'], 'end' => $m['end']];
            foreach ($model['elements'] as $el) {
                if ($el['activ'] && $el['quality'] === 'lipsa' && !in_array($el['cod'], self::EVENT_ELEMENTS, true)) {
                    $quality[$el['cod']] = ['cod' => $el['cod'], 'nume' => $el['nume'], 'categorie' => self::categoryOf($el['cod']), 'nota' => $el['quality_note']];
                }
            }
            foreach ($model['vehicles'] as $vid => $v) {
                $acc = &$vehicles[$vid];
                $acc ??= $this->emptyVehicle($v['info']);
                foreach (['km_real', 'km_facturat', 'km_dislocare', 'trips', 'revenue', 'tone'] as $k) {
                    $acc[$k] += $v[$k];
                }
                foreach ($v['costs'] as $cat => $value) {
                    $acc['costs'][$cat] = ($acc['costs'][$cat] ?? 0.0) + $value;
                }
                foreach ($v['driver_costs'] as $did => $value) {
                    $acc['driver_costs'][$did] = ($acc['driver_costs'][$did] ?? 0.0) + $value;
                }
                foreach ($v['semi_costs'] as $sid => $value) {
                    $acc['semi_costs'][$sid] = ($acc['semi_costs'][$sid] ?? 0.0) + $value;
                }
                foreach ($v['semi_km'] as $sid => $km) {
                    $acc['semi_km'][$sid] = ($acc['semi_km'][$sid] ?? 0.0) + $km;
                }
                foreach ($v['allocated_to'] as $tid => $value) {
                    $acc['allocated_to'][$tid] = ($acc['allocated_to'][$tid] ?? 0.0) + $value;
                }
                $acc['own_cost'] += $v['own_cost'];
                $acc['unallocated_semi_cost'] += $v['unallocated_semi_cost'];
                foreach ($v['by_driver'] as $did => $a) {
                    $acc['by_driver'][$did] ??= ['km' => 0.0, 'trips' => 0, 'revenue' => 0.0];
                    foreach ($a as $k => $val) {
                        $acc['by_driver'][$did][$k] += $val;
                    }
                }
                foreach ($v['by_benef'] as $bid => $a) {
                    $acc['by_benef'][$bid] ??= ['km' => 0.0, 'trips' => 0, 'revenue' => 0.0];
                    foreach ($a as $k => $val) {
                        $acc['by_benef'][$bid][$k] += $val;
                    }
                }
                foreach ($v['by_bt'] as $bt => $a) {
                    $acc['by_bt'][$bt] ??= ['km' => 0.0, 'trips' => 0, 'revenue' => 0.0];
                    foreach ($a as $k => $val) {
                        $acc['by_bt'][$bt][$k] += $val;
                    }
                }
                foreach ($v['by_seg'] ?? [] as $seg => $a) {
                    $acc['by_seg'][$seg] ??= self::SEG_ZERO;
                    foreach ($a as $k => $val) {
                        $acc['by_seg'][$seg][$k] = ($acc['by_seg'][$seg][$k] ?? 0) + $val;
                    }
                }
                foreach ($v['by_type'] as $type => $a) {
                    $acc['by_type'][$type] ??= ['km' => 0.0, 'trips' => 0, 'revenue' => 0.0];
                    foreach ($a as $k => $val) {
                        $acc['by_type'][$type][$k] += $val;
                    }
                }
                $acc['trip_ids'] += array_fill_keys($v['trip_ids'], true);
                $acc['flags'] += $v['flags'];
                unset($acc);
            }
            foreach ($model['drivers'] as $did => $d) {
                $acc = &$drivers[$did];
                $acc ??= ['driver_id' => $did, 'nume' => $d['nume'], 'status' => $d['status'], 'cost' => 0.0, 'cost_by_code' => [], 'allocated' => 0.0,
                    'unallocated' => 0.0, 'km' => 0.0, 'trips' => 0, 'revenue' => 0.0, 'vehicles' => [], 'cost_source' => [], 'months' => []];
                $acc['cost'] += $d['cost'];
                foreach ($d['cost_by_code'] as $code => $value) {
                    $acc['cost_by_code'][$code] = ($acc['cost_by_code'][$code] ?? 0.0) + $value;
                }
                $acc['allocated'] += $d['allocated'];
                $acc['unallocated'] += $d['unallocated'];
                $acc['km'] += $d['km'];
                $acc['trips'] += $d['trips'];
                $acc['revenue'] += $d['revenue'];
                foreach ($d['vehicles'] as $vid => $km) {
                    $acc['vehicles'][$vid] = ($acc['vehicles'][$vid] ?? 0.0) + $km;
                }
                $acc['cost_source'][$d['cost_source']] = true;
                $acc['months'][] = ['month' => $m['month'], 'cost' => $d['cost'], 'km' => $d['km'], 'trips' => $d['trips'], 'revenue' => $d['revenue'], 'sursa' => $d['cost_source']];
                unset($acc);
            }
        }

        foreach ($vehicles as $vid => &$v) {
            $v = $this->finalizeVehicle($v, $quality);
        }
        unset($v);
        foreach ($drivers as $did => &$d) {
            $d['cost_vehicle_on_km'] = 0.0; // costul vehiculelor pe km-ii soferului (pentru contributie)
            foreach ($d['vehicles'] as $vid => $km) {
                if (isset($vehicles[$vid]) && $vehicles[$vid]['km_real'] > 0) {
                    $d['cost_vehicle_on_km'] += $vehicles[$vid]['cost_vehicle'] / $vehicles[$vid]['km_real'] * $km;
                }
            }
            $d['contribution'] = $d['revenue'] - $d['cost_vehicle_on_km'] - $d['allocated'];
            $d['cost_per_km'] = $d['km'] > 0 ? $d['cost'] / $d['km'] : null;
            $d['revenue_per_km'] = $d['km'] > 0 ? $d['revenue'] / $d['km'] : null;
            $d['contribution_per_km'] = $d['km'] > 0 ? $d['contribution'] / $d['km'] : null;
            $d['cost_verified'] = isset($d['cost_source']['personal_luna']) && count($d['cost_source']) === 1;
        }
        unset($d);

        return ['from' => $from, 'to' => $to, 'months' => $monthsMeta, 'vehicles' => $vehicles, 'drivers' => $drivers, 'missing_elements' => array_values($quality)];
    }

    private function emptyVehicle(array $info): array
    {
        return $info + ['km_real' => 0.0, 'km_facturat' => 0.0, 'km_dislocare' => 0.0, 'trips' => 0, 'revenue' => 0.0, 'tone' => 0.0, 'costs' => [], 'driver_costs' => [],
            'semi_costs' => [], 'semi_km' => [], 'allocated_to' => [], 'own_cost' => 0.0, 'unallocated_semi_cost' => 0.0, 'by_driver' => [], 'by_benef' => [],
            'by_type' => [], 'by_bt' => [], 'by_seg' => [], 'trip_ids' => [], 'flags' => []];
    }

    private function finalizeVehicle(array $v, array $missing): array
    {
        $costVehicle = 0.0;
        foreach ($v['costs'] as $cat => $value) {
            if ($cat !== 'sofer') {
                $costVehicle += $value;
            }
        }
        $costDriver = array_sum($v['driver_costs']) + ($v['costs']['sofer'] ?? 0.0);
        $v['cost_vehicle'] = $costVehicle;
        $v['cost_driver'] = $costDriver;
        $v['cost_total'] = $costVehicle + $costDriver;
        $km = $v['km_real'];
        $v['cost_vehicle_km'] = $km > 0 ? $costVehicle / $km : null;
        $v['cost_driver_km'] = $km > 0 ? $costDriver / $km : null;
        $v['cost_total_km'] = $km > 0 ? $v['cost_total'] / $km : null;
        $v['revenue_km'] = $km > 0 ? $v['revenue'] / $km : null;
        $v['result'] = $v['revenue'] - $v['cost_total'];
        $v['result_km'] = $km > 0 ? $v['result'] / $km : null;
        $v['coverage'] = $v['cost_total'] > 0 ? $v['revenue'] / $v['cost_total'] * 100 : null;
        $types = array_keys($v['by_type']);
        $v['transport'] = count($types) === 0 ? null : (count($types) === 1 ? $types[0] : 'mixt');
        // Ce lipseste pentru un cost complet (fara a trata lipsa drept 0).
        $reasons = [];
        foreach ($missing as $m) {
            if ($m['categorie'] === 'amortizare' || $m['categorie'] === 'sofer' && $costDriver <= 0) {
                $reasons[$m['cod']] = $m['nume'];
            }
        }
        if ($km > 0 && ($v['costs']['combustibil'] ?? 0) <= 0 && !in_array($v['tip_vehicul'], self::SEMI_TYPES, true)) {
            $reasons['combustibil'] = 'Combustibil (nicio alimentare pe vehicul)';
        }
        foreach ($v['flags'] as $flag => $label) {
            $reasons[$flag] = $label;
        }
        $v['incomplet'] = array_values($reasons);
        $v['trip_ids'] = array_keys($v['trip_ids']);
        return $v;
    }

    // ================================================================== modelul unei luni

    /**
     * O luna (sau partea ei din interval): costurile motorului + activitatea + alocari. Cache pe amprenta datelor.
     */
    public function monthModel(string $month, string $start, string $end): array
    {
        $key = $month . '_' . $start . '_' . $end;
        if (isset($this->monthMemo[$key])) {
            return $this->monthMemo[$key];
        }
        $file = $this->cacheFile($key);
        if ($file !== null && is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $this->monthMemo[$key] = $this->restoreIntKeys($cached);
            }
        }
        $model = $this->buildMonth($month, $start, $end);
        if ($file !== null) {
            @file_put_contents($file, json_encode($model, JSON_UNESCAPED_UNICODE));
        }
        return $this->monthMemo[$key] = $model;
    }

    private function buildMonth(string $month, string $start, string $end): array
    {
        $legs = $this->legs($start, $end);
        $driverIds = array_values(array_unique(array_filter(array_map(static fn (array $l): int => (int) $l['driver_id'], $legs))));
        $full = $start === $month . '-01' && $end === date('Y-m-t', strtotime($month . '-01'));
        $engine = $this->engine->computeHistoricalMonth($month, $full ? null : $start, $full ? null : $end, $driverIds);
        $info = $this->vehicleInfo();

        // ---- 1. activitatea pe vehiculul care a facut cursa + semiremorca cuplata la data cursei
        $vehicles = [];
        $ensure = function (int $vid) use (&$vehicles, $info): void {
            if (!isset($vehicles[$vid])) {
                $vehicles[$vid] = ['info' => $info[$vid] ?? ['vehicle_id' => $vid, 'nr' => '#' . $vid, 'model' => '', 'tip_vehicul' => '', 'status' => ''],
                    'km_real' => 0.0, 'km_facturat' => 0.0, 'km_dislocare' => 0.0, 'trips' => 0, 'revenue' => 0.0, 'tone' => 0.0, 'costs' => [], 'driver_costs' => [],
                    'semi_costs' => [], 'semi_km' => [], 'allocated_to' => [], 'own_cost' => 0.0, 'unallocated_semi_cost' => 0.0,
                    'by_driver' => [], 'by_benef' => [], 'by_type' => [], 'by_bt' => [], 'by_seg' => [], 'trip_ids' => [], 'flags' => []];
            }
        };
        $semiKmByTractor = [];   // semi => tractor => km
        $driverKm = [];          // sofer => vehicul => km
        $driverAct = [];
        foreach ($legs as $l) {
            $vid = (int) $l['vehicle_id'];
            $ensure($vid);
            $v = &$vehicles[$vid];
            $v['km_real'] += (float) $l['km_real'];
            $v['km_facturat'] += (float) $l['km_facturat'];
            $v['km_dislocare'] += (float) $l['km_dislocare'];
            $v['trips'] += (int) $l['cursa'];
            $v['revenue'] += (float) $l['venit'];
            $v['tone'] += (float) $l['tone'];
            $v['trip_ids'][] = (int) $l['cursa_id'];
            $type = (string) $l['tip_transport'];
            $v['by_type'][$type] ??= ['km' => 0.0, 'trips' => 0, 'revenue' => 0.0];
            $v['by_type'][$type]['km'] += (float) $l['km_real'];
            $v['by_type'][$type]['trips'] += (int) $l['cursa'];
            $v['by_type'][$type]['revenue'] += (float) $l['venit'];
            $bid = (int) ($l['beneficiar_id'] ?? 0);
            $v['by_benef'][$bid] ??= ['km' => 0.0, 'trips' => 0, 'revenue' => 0.0];
            $v['by_benef'][$bid]['km'] += (float) $l['km_real'];
            $v['by_benef'][$bid]['trips'] += (int) $l['cursa'];
            $v['by_benef'][$bid]['revenue'] += (float) $l['venit'];
            $btKey = $bid . '|' . $type;
            $v['by_bt'][$btKey] ??= ['km' => 0.0, 'trips' => 0, 'revenue' => 0.0];
            $v['by_bt'][$btKey]['km'] += (float) $l['km_real'];
            $v['by_bt'][$btKey]['trips'] += (int) $l['cursa'];
            $v['by_bt'][$btKey]['revenue'] += (float) $l['venit'];
            // locurile brute ale cursei (rezolvate in locuri fizice la citire: LocationDimensionService)
            $segKey = $btKey . '|' . (int) ($l['loc_incarcare_id'] ?? 0) . '|' . (int) ($l['zona_distributie_id'] ?? 0);
            $v['by_seg'][$segKey] ??= self::SEG_ZERO;
            $v['by_seg'][$segKey]['km'] += (float) $l['km_real'];
            $v['by_seg'][$segKey]['trips'] += (int) $l['cursa'];
            $v['by_seg'][$segKey]['revenue'] += (float) $l['venit'];
            // incarcarea (tone incarcate / livrate, capacitatea cursei, tone lipsa / peste capacitate, clienti)
            foreach (['tone_inc', 'tone_liv', 'cap', 'tone_lipsa', 'tone_peste', 'clienti'] as $k) {
                $v['by_seg'][$segKey][$k] += (float) $l[$k];
            }
            $v['by_seg'][$segKey]['cap_neconf'] += (int) $l['cap_neconf'];
            $did = (int) ($l['driver_id'] ?? 0);
            if ($did > 0) {
                $v['by_driver'][$did] ??= ['km' => 0.0, 'trips' => 0, 'revenue' => 0.0];
                $v['by_driver'][$did]['km'] += (float) $l['km_real'];
                $v['by_driver'][$did]['trips'] += (int) $l['cursa'];
                $v['by_driver'][$did]['revenue'] += (float) $l['venit'];
                $driverKm[$did][$vid] = ($driverKm[$did][$vid] ?? 0.0) + (float) $l['km_real'];
                $driverAct[$did] ??= ['km' => 0.0, 'trips' => 0, 'revenue' => 0.0];
                $driverAct[$did]['km'] += (float) $l['km_real'];
                $driverAct[$did]['trips'] += (int) $l['cursa'];
                $driverAct[$did]['revenue'] += (float) $l['venit'];
            }
            $semi = $this->semiAt($vid, (string) $l['moment']);
            if ($semi !== null) {
                $semiKmByTractor[$semi][$vid] = ($semiKmByTractor[$semi][$vid] ?? 0.0) + (float) $l['km_real'];
                $v['semi_km'][$semi] = ($v['semi_km'][$semi] ?? 0.0) + (float) $l['km_real'];
            }
            unset($v);
        }

        // ---- 2. costurile pe vehicul fizic (motorul existent, modul istoric)
        $tireRates = null;
        foreach ($engine['units'] as $vid => $unit) {
            $vid = (int) $vid;
            $ensure($vid);
            foreach ($unit['elements'] as $code => $el) {
                $cat = self::categoryOf((string) $code);
                $vehicles[$vid]['costs'][$cat] = ($vehicles[$vid]['costs'][$cat] ?? 0.0) + (float) $el['value'];
                $vehicles[$vid]['own_cost'] += (float) $el['value'];
            }
            // Anvelopele semiremorcii: motorul le inmulteste cu km-ii unitatii (0 pentru semiremorca);
            // aici se folosesc km-ii facuti cu ea in luna.
            $isSemi = in_array((string) $unit['tip_vehicul'], self::SEMI_TYPES, true);
            $semiKm = array_sum($semiKmByTractor[$vid] ?? []);
            if ($isSemi && $semiKm > 0 && !isset($unit['elements']['anvelope'])) {
                $tireRates ??= $this->engine->model()->getTireRatePerKm($start, $end);
                $rate = (float) ($tireRates[$vid]['rate_per_km'] ?? 0);
                if ($rate > 0) {
                    $vehicles[$vid]['costs']['anvelope'] = ($vehicles[$vid]['costs']['anvelope'] ?? 0.0) + $rate * $semiKm;
                    $vehicles[$vid]['own_cost'] += $rate * $semiKm;
                    $vehicles[$vid]['tire_semi'] = ['rate' => $rate, 'km' => $semiKm];
                }
            }
        }

        // Luna cu costuri de exploatare dar fara curse inregistrate: costul / km al intervalului ar fi umflat.
        foreach ($vehicles as $vid => $v) {
            if (!in_array((string) $v['info']['tip_vehicul'], self::SEMI_TYPES, true) && $v['km_real'] <= 0 && ($v['costs']['combustibil'] ?? 0) > 0) {
                $vehicles[$vid]['flags']['fara_curse_' . $month] = 'Costuri fără curse înregistrate în ' . $month;
            }
        }

        // ---- 3. semiremorca -> tractoarele care au folosit-o (km; fallback timp), restul ramane pe ea
        $semiShares = [];
        foreach ($vehicles as $vid => $v) {
            if (!in_array((string) $v['info']['tip_vehicul'], self::SEMI_TYPES, true) || $v['own_cost'] <= 0) {
                continue;
            }
            $shares = [];
            $kmTotal = array_sum($semiKmByTractor[$vid] ?? []);
            if ($kmTotal > 0) {
                foreach ($semiKmByTractor[$vid] as $tid => $km) {
                    $shares[$tid] = $km / $kmTotal;
                }
            } else {
                $shares = $this->timeShares($vid, $start, $end);
            }
            $semiShares[$vid] = ['shares' => $shares, 'basis' => $kmTotal > 0 ? 'km' : 'timp'];
            $allocated = 0.0;
            foreach ($shares as $tid => $share) {
                $ensure((int) $tid);
                foreach ($v['costs'] as $cat => $value) {
                    $vehicles[$tid]['costs'][$cat] = ($vehicles[$tid]['costs'][$cat] ?? 0.0) + $value * $share;
                }
                $part = $v['own_cost'] * $share;
                $vehicles[$tid]['semi_costs'][$vid] = ($vehicles[$tid]['semi_costs'][$vid] ?? 0.0) + $part;
                $vehicles[$vid]['allocated_to'][$tid] = ($vehicles[$vid]['allocated_to'][$tid] ?? 0.0) + $part;
                $allocated += $part;
            }
            $vehicles[$vid]['unallocated_semi_cost'] += max(0.0, $v['own_cost'] - $allocated);
        }

        // ---- 4. soferii: cost lunar (verificat / estimat) impartit dupa km-ii reali pe vehicule
        $payroll = $this->payroll($month);
        $driverNames = $this->driverNames();
        $drivers = [];
        $ledgerIds = array_unique(array_merge(array_keys($engine['drivers']), array_keys($driverAct)));
        foreach ($ledgerIds as $did) {
            $did = (int) $did;
            $byCode = [];
            foreach ($engine['drivers'][$did] ?? [] as $code => $el) {
                $byCode[$code] = (float) $el['value'];
            }
            $source = isset($byCode['salariu_soferi']) ? 'salariu_configurat' : 'lipsa';
            if (isset($payroll[$did])) {
                // Contabilitate Personal finalizata: costul real al lunii inlocuieste salariul configurat.
                $byCode['salariu_soferi'] = $full ? $payroll[$did] : $payroll[$did] * $this->dayRatio($start, $end);
                $source = 'personal_luna';
            }
            $cost = array_sum($byCode);
            $kmTotal = array_sum($driverKm[$did] ?? []);
            $allocated = 0.0;
            if ($kmTotal > 0) {
                foreach ($driverKm[$did] as $vid => $km) {
                    $part = $cost * $km / $kmTotal;
                    $vehicles[$vid]['driver_costs'][$did] = ($vehicles[$vid]['driver_costs'][$did] ?? 0.0) + $part;
                    $allocated += $part;
                }
            }
            $drivers[$did] = [
                'nume' => $driverNames[$did]['nume'] ?? ('Șofer #' . $did),
                'status' => $driverNames[$did]['status'] ?? '',
                'cost' => $cost, 'cost_by_code' => $byCode, 'allocated' => $allocated, 'unallocated' => $cost - $allocated,
                'km' => $driverAct[$did]['km'] ?? 0.0, 'trips' => $driverAct[$did]['trips'] ?? 0, 'revenue' => $driverAct[$did]['revenue'] ?? 0.0,
                'vehicles' => $driverKm[$did] ?? [], 'cost_source' => $source,
            ];
            if ($source !== 'personal_luna' && $kmTotal > 0) {
                foreach (array_keys($driverKm[$did]) as $vid) {
                    $vehicles[$vid]['flags'][$source === 'lipsa' ? 'sofer_lipsa' : 'sofer_estimat'] = $source === 'lipsa'
                        ? 'Cost șofer lipsă (fără salariu / pontaj)' : 'Cost șofer estimat (salariu configurat, fără pontaj finalizat)';
                }
            }
        }

        return [
            'month' => $month, 'start' => $start, 'end' => $end,
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'semi_shares' => $semiShares,
            'engine_units' => array_map(static fn (array $u): array => ['elements' => $u['elements'], 'tip_vehicul' => $u['tip_vehicul']], $engine['units']),
            'engine_drivers' => $engine['drivers'],
            'elements' => array_map(static fn (array $e): array => ['cod' => $e['cod'], 'nume' => $e['nume'], 'activ' => $e['activ'], 'quality' => $e['quality'], 'quality_note' => $e['quality_note']], $engine['elements']),
            'company' => $engine['company'],
        ];
    }

    /**
     * Picioarele de cursa (o cursa reluata pe segmente imparte km si venitul proportional, ca in motorul
     * Cost / km), cu momentul de start pentru citirea cuplajului.
     */
    public function legs(string $start, string $end, ?array $vehicleIds = null, ?array $driverIds = null): array
    {
        $kmReal = 'CASE WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa ELSE 0 END';
        $kmBill = 'CASE WHEN c.km_cursa IS NOT NULL AND c.km_cursa > 0 THEN c.km_cursa WHEN c.km_totali IS NOT NULL AND c.km_totali > 0 THEN c.km_totali ELSE 0 END';
        $share = 'COALESCE(COALESCE(s.km, 0) / seg.km_total, 1)';
        $where = '';
        $params = [':ds' => $start, ':de' => $end];
        if ($vehicleIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $vehicleIds)));
            $where .= $ids === [] ? ' AND 1 = 0' : ' AND COALESCE(s.vehicle_id, c.vehicle_id) IN (' . implode(',', $ids) . ')';
        }
        if ($driverIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $driverIds)));
            $where .= $ids === [] ? ' AND 1 = 0' : ' AND COALESCE(s.driver_id, c.driver_id) IN (' . implode(',', $ids) . ')';
        }
        $stmt = $this->db->prepare(
            "SELECT c.id AS cursa_id,
                    COALESCE(s.vehicle_id, c.vehicle_id) AS vehicle_id,
                    COALESCE(s.driver_id, c.driver_id) AS driver_id,
                    c.beneficiar_id, c.tip_transport, c.status_facturare, c.data_inceput, c.loc_incarcare_id, c.zona_distributie_id,
                    CONCAT(COALESCE(s.data_inceput, c.data_inceput), ' ', COALESCE(s.ora_inceput, c.ora_inceput, '12:00:00')) AS moment,
                    CASE WHEN seg.cursa_id IS NULL OR s.ordine = 1 THEN 1 ELSE 0 END AS cursa,
                    ($kmReal) * $share AS km_real,
                    ($kmBill) * $share AS km_facturat,
                    COALESCE(c.km_dislocare, 0) * $share AS km_dislocare,
                    COALESCE(c.total_facturare, 0) * $share AS venit,
                    COALESCE(c.tona_livrata, c.cantitate_incarcata, 0) * $share AS tone,
                    COALESCE(c.cantitate_incarcata, 0) * $share AS tone_inc,
                    COALESCE(c.tona_livrata, c.cantitate_incarcata, 0) * $share AS tone_liv,
                    COALESCE(c.nr_clienti, 0) * $share AS clienti,
                    -- capacitatea = instantaneul cursei (nu fisa de azi a vehiculului); doar cursele cu incarcare si capacitate
                    CASE WHEN c.capacitate_transport > 0 AND c.cantitate_incarcata > 0 THEN c.capacitate_transport ELSE 0 END * $share AS cap,
                    CASE WHEN c.capacitate_transport > 0 AND c.cantitate_incarcata > 0 THEN GREATEST(c.capacitate_transport - c.cantitate_incarcata, 0) ELSE 0 END * $share AS tone_lipsa,
                    CASE WHEN c.capacitate_transport > 0 AND c.cantitate_incarcata > 0 THEN GREATEST(c.cantitate_incarcata - c.capacitate_transport, 0) ELSE 0 END * $share AS tone_peste,
                    CASE WHEN (seg.cursa_id IS NULL OR s.ordine = 1) AND c.capacitate_transport > 0 AND c.cantitate_incarcata > 0
                              AND COALESCE(c.capacitate_transport_confirmata, 0) = 0 THEN 1 ELSE 0 END AS cap_neconf,
                    c.durata_cursa_minute
               FROM curse_dispecer c
               LEFT JOIN (SELECT cursa_id, SUM(COALESCE(km, 0)) AS km_total FROM curse_segmente
                           GROUP BY cursa_id HAVING COUNT(*) >= 2 AND SUM(COALESCE(km, 0)) > 0) seg ON seg.cursa_id = c.id
               LEFT JOIN curse_segmente s ON s.cursa_id = seg.cursa_id
              WHERE c.deleted_at IS NULL AND c.data_inceput BETWEEN :ds AND :de $where
              ORDER BY c.data_inceput, c.id"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================== cuplaje datate

    private function loadCouplings(): array
    {
        if ($this->couplings === null) {
            $this->couplings = [];
            foreach ($this->db->query('SELECT tractor_id, semiremorca_id, data_start, data_end FROM vehicule_cuplaje ORDER BY data_start, id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $this->couplings[] = ['t' => (int) $row['tractor_id'], 's' => (int) $row['semiremorca_id'], 'a' => (string) $row['data_start'], 'b' => $row['data_end'] !== null ? (string) $row['data_end'] : null];
            }
        }
        return $this->couplings;
    }

    /** Semiremorca cuplata la tractor la momentul dat (cuplajul valabil atunci, nu cel de azi). */
    public function semiAt(int $tractorId, string $moment): ?int
    {
        $found = null;
        foreach ($this->loadCouplings() as $c) {
            if ($c['t'] === $tractorId && $c['a'] <= $moment && ($c['b'] === null || $c['b'] > $moment)) {
                $found = $c['s'];
            }
        }
        return $found;
    }

    /** @return array<int,float> tractor => pondere din timpul perioadei (fallback cand semiremorca n-a facut km) */
    private function timeShares(int $semiId, string $start, string $end): array
    {
        $a0 = strtotime($start . ' 00:00:00');
        $b0 = strtotime($end . ' 23:59:59');
        $total = max(1, $b0 - $a0);
        $shares = [];
        foreach ($this->loadCouplings() as $c) {
            if ($c['s'] !== $semiId) {
                continue;
            }
            $a = max($a0, strtotime($c['a']));
            $b = min($b0, $c['b'] !== null ? strtotime($c['b']) : $b0);
            if ($b > $a) {
                $shares[$c['t']] = ($shares[$c['t']] ?? 0.0) + ($b - $a) / $total;
            }
        }
        return array_map(static fn (float $v): float => min(1.0, $v), $shares);
    }

    // ================================================================== surse auxiliare

    private ?array $infoCache = null;

    /** @return array<int,array{vehicle_id:int,nr:string,model:string,tip_vehicul:string,status:string}> */
    public function vehicleInfo(): array
    {
        if ($this->infoCache === null) {
            $this->infoCache = [];
            foreach ($this->db->query('SELECT id, nr_inmatriculare, marca, model, tip_vehicul, status FROM vehicule')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $this->infoCache[(int) $r['id']] = ['vehicle_id' => (int) $r['id'], 'nr' => (string) $r['nr_inmatriculare'], 'model' => trim($r['marca'] . ' ' . $r['model']),
                    'tip_vehicul' => (string) $r['tip_vehicul'], 'status' => (string) $r['status']];
            }
        }
        return $this->infoCache;
    }

    private ?array $driverCache = null;

    /** @return array<int,array{nume:string,status:string}> */
    public function driverNames(): array
    {
        if ($this->driverCache === null) {
            $this->driverCache = [];
            foreach ($this->db->query('SELECT id, nume, status FROM soferi')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $this->driverCache[(int) $r['id']] = ['nume' => (string) $r['nume'], 'status' => (string) $r['status']];
            }
        }
        return $this->driverCache;
    }

    /** Costul salarial finalizat din Contabilitate Personal (personal_luna), pe sofer, pentru luna. */
    private function payroll(string $month): array
    {
        try {
            $stmt = $this->db->prepare("SELECT driver_id, SUM(cost_total) AS c FROM personal_luna
                                         WHERE perioada = :p AND driver_id IS NOT NULL AND status = 'finalizat' AND cost_total IS NOT NULL GROUP BY driver_id");
            $stmt->execute([':p' => $month]);
            return array_map('floatval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'c', 'driver_id'));
        } catch (Throwable) {
            return [];
        }
    }

    private function dayRatio(string $start, string $end): float
    {
        $days = (int) round((strtotime($end) - strtotime($start)) / 86400) + 1;
        return $days / (int) date('t', strtotime($start));
    }

    // ================================================================== cache

    /**
     * Amprenta datelor sursa: orice modificare (curse, alimentari, mentenanta, cuplaje, salarii, documente,
     * configurarea costurilor...) schimba amprenta si invalideaza automat cache-ul lunilor.
     */
    public function fingerprint(): string
    {
        if ($this->fingerprint !== null) {
            return $this->fingerprint;
        }
        $parts = [self::CACHE_VERSION];
        $tables = ['curse_dispecer' => 'updated_at', 'curse_segmente' => 'updated_at', 'curse_cheltuieli' => 'updated_at', 'fuel_fillups' => 'updated_at',
            'mentenanta' => 'updated_at', 'vehicule_cuplaje' => 'updated_at', 'vehicule' => 'updated_at', 'soferi' => 'updated_at', 'salary_history' => null,
            'personal_luna' => 'updated_at', 'documente' => 'updated_at', 'cost_operational_elemente' => 'updated_at', 'cost_operational_settings' => 'updated_at',
            'anvelope' => 'updated_at', 'anvelope_alocari' => 'updated_at', 'cheltuieli' => 'updated_at', 'configurare_costuri_documente_vehicule' => null,
            'configurare_costuri_documente_vehicule_override' => null, 'vehicle_authorizations' => null, 'inventar_dotari_vehicule' => null, 'soferi_vehicule' => 'updated_at'];
        foreach ($tables as $table => $col) {
            try {
                $sql = $col !== null && FinancialFoundationSchema::columnExists($this->db, $table, $col)
                    ? "SELECT CONCAT(COUNT(*), '|', COALESCE(MAX(`$col`), ''), '|', COALESCE(MAX(id), 0)) FROM `$table`"
                    : "SELECT CONCAT(COUNT(*), '|', COALESCE(MAX(id), 0)) FROM `$table`";
                $parts[] = $table . ':' . (string) $this->db->query($sql)->fetchColumn();
            } catch (Throwable) {
                $parts[] = $table . ':-';
            }
        }
        return $this->fingerprint = substr(md5(implode(';', $parts)), 0, 16);
    }

    private function cacheFile(string $key): ?string
    {
        if ($this->cacheDir === null || $this->cacheDir === '') {
            return null;
        }
        $dir = $this->cacheDir . '/' . $this->fingerprint();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return null;
            }
            // Amprentele vechi nu mai sunt valabile: le stergem.
            foreach (glob($this->cacheDir . '/*', GLOB_ONLYDIR) ?: [] as $old) {
                if ($old !== $dir) {
                    array_map('unlink', glob($old . '/*.json') ?: []);
                    @rmdir($old);
                }
            }
        }
        return $dir . '/' . preg_replace('/[^0-9A-Za-z_-]/', '', $key) . '.json';
    }

    /** JSON transforma cheile numerice in siruri; le refacem ca intregi unde sunt id-uri. */
    private function restoreIntKeys(array $data): array
    {
        $fix = static function (array $a) use (&$fix): array {
            $out = [];
            foreach ($a as $k => $v) {
                $out[is_string($k) && ctype_digit($k) ? (int) $k : $k] = is_array($v) ? $fix($v) : $v;
            }
            return $out;
        };
        return $fix($data);
    }

    public function clearCache(): void
    {
        foreach (glob(($this->cacheDir ?? '') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            array_map('unlink', glob($dir . '/*.json') ?: []);
            @rmdir($dir);
        }
        $this->monthMemo = [];
        $this->fingerprint = null;
    }
}
