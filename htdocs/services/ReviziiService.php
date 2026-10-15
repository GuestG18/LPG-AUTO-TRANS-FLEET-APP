<?php
declare(strict_types=1);

require_once __DIR__ . '/ReviziiCalculator.php';
require_once dirname(__DIR__) . '/models/ReviziiModel.php';

/**
 * Revizii (schimb ulei, placute frana, anvelope): asamblarea paginii si simularea pe
 * date istorice. Fiecare componenta urmarita (ReviziiModel::TAB_ITEMS) are plan, T0 si
 * istoric proprii; orele motor se convertesc doar la ulei.
 *
 * Un singur calcul (calculate()) serveste atat productia (de la T0-ul planului pana
 * acum, doar din BD) cat si simulatorul (interval ales, BD + completare read-only din
 * SasFleet pentru zilele nesincronizate). Nimic din simulare nu se salveaza.
 *
 * Surse per componenta:
 *  - cap tractor / camion: segmentele SasFleet ale propriului carId (km) + orele eligibile
 *    din sursa configurata (ore aspirare din curse / motor pe loc SAS);
 *  - semi-remorca: SAS nu are telemetrie pentru remorci; km se aloca din segmentele capului
 *    tractor DOAR pe intervalele din istoricul de cuplare (vehicule_cuplaje), proportional
 *    cu suprapunerea in timp. Orele motor nu se aloca niciodata remorcii.
 */
class ReviziiService
{
    /** Peste acest interval fara sincronizare reusita, datele sunt marcate ca vechi. */
    public const STALE_AFTER_SECONDS = 36 * 3600;
    /** Simularea: interval maxim si buget de cereri SAS pentru zilele nesincronizate. */
    public const SIMULATION_MAX_DAYS = 92;
    public const SIMULATION_MAX_CALLS = 20;

    private ReviziiModel $model;

    /** @var array<string, string> */
    private array $settings = [];
    /** @var array<int, array<string, mixed>> */
    private array $vehicles = [];
    /** @var array<int, array<string, mixed>> active + inactive (simularea istorica) */
    private array $allVehicles = [];
    /** @var array<string, array<int, array<string, mixed>>> tip_revizie => vehicle_id => plan */
    private array $plans = [];
    /** @var array<int, int> */
    private array $sasMap = [];
    /** @var array<int, array<string, mixed>> */
    private array $couplings = [];
    private ?string $couplingHistoryStart = null;
    /** @var array<int, array<string, mixed>> */
    private array $syncState = [];
    /** @var array<int, array{from:int, segments:array, ok:array<string,bool>}> carId => telemetrie din BD */
    private array $telemetry = [];
    /** @var array{from:?int, events:array, missing:array} */
    private array $aspiration = ['from' => null, 'events' => [], 'missing' => []];
    private bool $loaded = false;

    public function __construct(PDO $db)
    {
        $this->model = new ReviziiModel($db);
    }

    public function model(): ReviziiModel
    {
        return $this->model;
    }

    /** @return array<string, string> */
    public function settings(): array
    {
        $this->load();
        return $this->settings;
    }

    public function factor(): float
    {
        $this->load();
        return max(0.0, (float) $this->settings['factor_km_ora']);
    }

    public function hourSource(): string
    {
        $this->load();
        $source = (string) $this->settings['sursa_ore_motor'];
        return isset(ReviziiModel::HOUR_SOURCES[$source]) ? $source : 'ore_aspirare';
    }

    /** Toate vehiculele (si inactive), pentru selectorul simulatorului. @return array<int, array<string, mixed>> */
    public function vehicles(): array
    {
        $this->load();
        return $this->allVehicles;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->settings = $this->model->getSettings();
        $this->vehicles = $this->model->getVehicles();
        $this->allVehicles = $this->model->getVehicles(true);
        $this->plans = $this->model->getAllPlans();
        $this->sasMap = $this->model->getSasMapping();
        $this->couplings = $this->model->getCouplings();
        $this->couplingHistoryStart = $this->model->getCouplingHistoryStart();
        $this->syncState = $this->model->getSyncState();
        $this->telemetry = [];
        $this->aspiration = ['from' => null, 'events' => [], 'missing' => []];

        // Pagina: telemetria tuturor vehiculelor de la cel mai vechi T0, intr-o singura interogare.
        $from = null;
        foreach ($this->plans as $plansOfType) {
            foreach ($plansOfType as $plan) {
                $baseline = (int) strtotime((string) $plan['baseline_at']);
                $from = $from === null ? $baseline : min($from, $baseline);
            }
        }
        if ($from !== null) {
            $this->preloadTelemetry(array_values(array_unique($this->sasMap)), $from);
            $this->preloadAspiration($from);
        }
        $this->loaded = true;
    }

    /** @param array<int, int> $cars */
    private function preloadTelemetry(array $cars, int $fromTs): void
    {
        if ($cars === []) {
            return;
        }
        $segments = $this->model->getSegments($cars, date('Y-m-d H:i:s', $fromTs));
        $okDays = $this->model->getOkDays($cars, date('Y-m-d', $fromTs));
        foreach ($cars as $carId) {
            $this->telemetry[$carId] = ['from' => $fromTs, 'segments' => $segments[$carId] ?? [], 'ok' => $okDays[$carId] ?? []];
        }
    }

    /** @return array{from:int, segments:array, ok:array<string,bool>} */
    private function telemetryFor(int $carId, int $fromTs): array
    {
        if (!isset($this->telemetry[$carId]) || $this->telemetry[$carId]['from'] > $fromTs) {
            $this->preloadTelemetry([$carId], $fromTs);
        }

        return $this->telemetry[$carId];
    }

    private function preloadAspiration(int $fromTs): void
    {
        $data = $this->model->getAspirationEvents(array_keys($this->vehicles), date('Y-m-d', $fromTs));
        $this->aspiration = ['from' => $fromTs] + $data;
    }

    /** @return array{events:array, missing:array} pentru un vehicul */
    private function aspirationFor(int $vehicleId, int $fromTs): array
    {
        if ($this->aspiration['from'] === null || $this->aspiration['from'] > $fromTs) {
            $this->preloadAspiration($fromTs);
        }

        return [
            'events' => $this->aspiration['events'][$vehicleId] ?? [],
            'missing' => $this->aspiration['missing'][$vehicleId] ?? [],
        ];
    }

    // ================================================================ calcul comun

    /**
     * Calculul complet pentru o componenta pe intervalul [$fromTs, $toTs].
     *
     * @param array{interval:int, warn_km:int, factor:float, source:string, initial_km:float, ore?:bool} $params
     *        ore = false: fara conversia orelor motor (placute, anvelope — uzura doar din km)
     * @param callable|null $fill completare pentru zilele nesincronizate:
     *        fn(int $carId, array $missingDays): array{segments: array, ok_days: array<int,string>, errors: array<int,string>, calls:int}
     * @return array<string, mixed>
     */
    public function calculate(int $vehicleId, int $fromTs, int $toTs, array $params, ?callable $fill = null): array
    {
        $this->load();
        $vehicle = $this->allVehicles[$vehicleId] ?? null;
        if ($vehicle === null) {
            throw new InvalidArgumentException('Vehicul inexistent sau inactiv.');
        }
        $isTrailer = is_trailer_vehicle_type((string) $vehicle['tip_vehicul']);
        $source = isset(ReviziiModel::HOUR_SOURCES[$params['source']]) ? $params['source'] : 'ore_aspirare';
        $withHours = ($params['ore'] ?? true) && !$isTrailer;
        $toTs = max($fromTs, $toTs);

        $notes = [];
        $sources = [];
        $missing = [];
        $hasSource = false;
        $lastSync = null;
        /** @var array<int, array<int, array{0:int,1:int}>> $carWindows carId => ferestre */
        $carWindows = [];

        if (!$isTrailer) {
            $carId = $this->sasMap[$vehicleId] ?? null;
            if ($carId !== null) {
                $hasSource = true;
                $carWindows[$carId][] = [$fromTs, $toTs];
                $sources[] = 'SasFleet travelsheet: distance pe segmente (carId ' . $carId . ')';
            } else {
                $notes[] = 'Vehiculul nu este găsit în SasFleet (după numărul de înmatriculare) — km nu pot fi calculați automat.';
            }
        } else {
            $sources[] = 'Istoric cuplare (vehicule_cuplaje) × km SasFleet ai capului tractor';
            $hasSource = $this->couplingHistoryStart !== null;
            $historyStartTs = $this->couplingHistoryStart !== null ? (int) strtotime($this->couplingHistoryStart) : $toTs;
            if ($fromTs < $historyStartTs) {
                $missing = array_merge($missing, ReviziiCalculator::missingDays([[$fromTs, min($historyStartTs, $toTs)]], []));
                $notes[] = 'Istoricul de cuplare începe la ' . date('d.m.Y', $historyStartTs) . ' — înainte nu se știe cu ce cap tractor a circulat.';
            }
            $coupledAny = false;
            foreach ($this->couplings as $coupling) {
                if ($coupling['semiremorca_id'] !== $vehicleId) {
                    continue;
                }
                $from = max($fromTs, (int) strtotime($coupling['data_start']));
                $to = min($toTs, $coupling['data_end'] !== null ? (int) strtotime($coupling['data_end']) : $toTs);
                if ($to <= $from) {
                    continue;
                }
                $coupledAny = true;
                $tractorNr = (string) ($this->allVehicles[$coupling['tractor_id']]['nr_inmatriculare'] ?? ('#' . $coupling['tractor_id']));
                $carId = $this->sasMap[$coupling['tractor_id']] ?? null;
                if ($carId === null) {
                    $missing = array_merge($missing, ReviziiCalculator::missingDays([[$from, $to]], []));
                    $notes[] = 'Cuplată cu ' . $tractorNr . ' (' . date('d.m H:i', $from) . ' – ' . date('d.m H:i', $to) . '), care nu are telemetrie SasFleet.';
                    continue;
                }
                $carWindows[$carId][] = [$from, $to];
                $notes[] = 'Cuplată cu ' . $tractorNr . ': ' . date('d.m.Y H:i', $from) . ' – ' . date('d.m.Y H:i', $to) . '.';
            }
            if (!$coupledAny) {
                $notes[] = 'Nicio cuplare înregistrată în perioadă — 0 km alocați din istoricul de cuplare.';
            }
        }

        $drivenKm = 0.0;
        $idleSeconds = 0.0;
        $engineSeconds = 0.0;
        $segmentCount = 0;
        $rejected = 0;
        $localDays = 0;
        $apiDays = 0;
        $apiCalls = 0;
        foreach ($carWindows as $carId => $windows) {
            $data = $this->telemetryFor($carId, min(array_column($windows, 0)));
            $rawSegments = $data['segments'];
            $okDays = $data['ok'];
            $carMissing = ReviziiCalculator::missingDays($windows, $okDays);
            $localDays += count(ReviziiCalculator::missingDays($windows, [])) - count($carMissing);

            if ($fill !== null && $carMissing !== []) {
                $filled = $fill($carId, $carMissing);
                $rawSegments = array_merge($rawSegments, $filled['segments']);
                foreach ($filled['ok_days'] as $day) {
                    $okDays[$day] = true;
                }
                $apiDays += count($filled['ok_days']);
                $apiCalls += (int) ($filled['calls'] ?? 0);
                foreach ($filled['errors'] as $error) {
                    $notes[] = 'SasFleet: ' . $error;
                }
                $carMissing = ReviziiCalculator::missingDays($windows, $okDays);
            }

            // Deduplicarea (suprapuneri BD/API sau intre ferestre) se face pe setul complet.
            $normalized = ReviziiCalculator::normalizeSegments($rawSegments);
            $rejected += $normalized['rejected'];
            $usage = ReviziiCalculator::usage($normalized['segments'], $windows, !$isTrailer);
            $drivenKm += $usage['km'];
            $idleSeconds += $usage['idle_seconds'];
            $engineSeconds += $usage['engine_seconds'];
            foreach ($normalized['segments'] as $segment) {
                foreach ($windows as [$from, $to]) {
                    if ($segment['end'] > $from && $segment['start'] < $to) {
                        $segmentCount++;
                        break;
                    }
                }
            }
            $missing = array_merge($missing, $carMissing);

            $state = $this->syncState[$carId] ?? null;
            if ($state !== null && $state['last_ok'] !== null && ($lastSync === null || $state['last_ok'] > $lastSync)) {
                $lastSync = $state['last_ok'];
            }
            if (!empty($state['last_error']) && $carMissing !== []) {
                $notes[] = 'Ultima eroare SasFleet: ' . $state['last_error'];
            }
        }
        if ($rejected > 0) {
            $notes[] = $rejected . ' segmente SAS respinse ca implauzibile (viteză / distanță) — nu sunt numărate.';
        }

        // Ore motor eligibile.
        $sasIdleHours = $idleSeconds / 3600;
        $aspirationHours = 0.0;
        $racesWithoutHours = [];
        if (!$isTrailer) {
            $aspiration = $this->aspirationFor($vehicleId, $fromTs);
            $aspirationHours = ReviziiCalculator::eventHours($aspiration['events'], [[$fromTs, $toTs]]);
            foreach ($aspiration['missing'] as $race) {
                if ($race['end'] > $fromTs && $race['start'] < $toTs) {
                    $racesWithoutHours[] = $race['race_id'];
                }
            }
        }
        $hoursMissing = false;
        if (!$withHours) {
            $eligibleHours = 0.0;
        } elseif ($source === 'sas_pe_loc') {
            $eligibleHours = $sasIdleHours;
            $hoursMissing = !$hasSource;
        } else {
            $eligibleHours = $aspirationHours;
            if ($racesWithoutHours !== []) {
                $hoursMissing = true;
                $notes[] = count($racesWithoutHours) . ' curse de compresor fără ore de aspirare completate (#' . implode(', #', array_slice($racesWithoutHours, 0, 5)) . ') — orele lor nu pot fi convertite.';
            }
        }

        $missing = array_values(array_unique($missing));
        sort($missing);
        $calc = ReviziiCalculator::evaluate(
            (int) $params['interval'],
            (float) $params['initial_km'],
            $drivenKm,
            $eligibleHours,
            (float) $params['factor'],
            (int) $params['warn_km'],
            $hasSource
        );

        // Nicio zi acoperita de telemetrie: 0 km ar fi o valoare inventata. Doar o depasire
        // deja certa (ex. din consumul initial) poate fi afirmata.
        $periodDays = count(ReviziiCalculator::missingDays([[$fromTs, $toTs]], []));
        if ($periodDays > 0 && count($missing) >= $periodDays && $calc['status'] !== ReviziiCalculator::STATUS_DUE) {
            $calc['status'] = ReviziiCalculator::STATUS_NO_DATA;
        }

        $detail = [
            'km_gps' => round($drivenKm, 1),
            'ore_sas_pe_loc' => $isTrailer ? null : round($sasIdleHours, 2),
            'ore_motor_pornit' => $isTrailer ? null : round($engineSeconds / 3600, 2),
            'ore_aspirare' => $isTrailer ? null : round($aspirationHours, 2),
            'sursa_ore' => $withHours ? $source : null,
            'ore_aplicate' => $withHours,
            'segmente' => $segmentCount,
            'segmente_respinse' => $rejected,
            'zile_locale' => $localDays,
            'zile_api' => $apiDays,
            'cereri_api' => $apiCalls,
            'curse_fara_ore' => $racesWithoutHours,
        ];

        return [
            'calc' => $calc,
            'detaliu' => $detail,
            'complet' => $hasSource && $missing === [] && !$hoursMissing,
            'zile_lipsa' => $missing,
            'ore_incomplete' => $hoursMissing,
            'surse' => $sources,
            'note' => $notes,
            'pasi' => $this->steps($fromTs, $toTs, $calc, $detail, $params, $isTrailer, $withHours, $missing),
            'ultima_sincronizare' => $lastSync,
            'date_vechi' => $hasSource && $carWindows !== [] && ($lastSync === null || time() - (int) strtotime($lastSync) > self::STALE_AFTER_SECONDS),
            'has_source' => $hasSource,
        ];
    }

    /**
     * Pasii calculului, verificabili independent.
     *
     * @return array<int, string>
     */
    private function steps(int $fromTs, int $toTs, array $calc, array $detail, array $params, bool $isTrailer, bool $withHours, array $missing): array
    {
        $km = static fn (float $v): string => number_format($v, 1, ',', '.');
        $steps = [];
        $steps[] = sprintf('Perioada: %s → %s (%s zile).', date('d.m.Y H:i', $fromTs), date('d.m.Y H:i', $toTs), number_format(($toTs - $fromTs) / 86400, 1, ',', '.'));
        $steps[] = sprintf(
            'Km GPS efectuați = Σ distanța segmentelor SasFleet din perioadă (proporțional la capete, suprapuneri eliminate) = %s km din %d segmente%s.',
            $km($detail['km_gps']),
            $detail['segmente'],
            $isTrailer ? ', doar pe intervalele de cuplare' : ''
        );
        if ($isTrailer) {
            $steps[] = 'Ore motor eligibile = 0 h (semi-remorca nu are motor; orele capului tractor nu se transferă).';
        } elseif (!$withHours) {
            $steps[] = 'Ore motor: nu se aplică — uzura plăcuțelor și a anvelopelor depinde doar de km parcurși, nu de motorul pornit pe loc.';
        } else {
            $label = ReviziiModel::HOUR_SOURCES[$detail['sursa_ore']] ?? $detail['sursa_ore'];
            $steps[] = sprintf(
                'Ore motor eligibile (%s) = %s h. SasFleet: motor pornit total %s h, din care pe loc %s h și în mers %s h (în mers nu se convertește — e deja în km). Ore aspirare din curse = %s h.',
                $label,
                number_format($calc['ore_motor'], 2, ',', '.'),
                number_format((float) $detail['ore_motor_pornit'], 2, ',', '.'),
                number_format((float) $detail['ore_sas_pe_loc'], 2, ',', '.'),
                number_format(max(0, (float) $detail['ore_motor_pornit'] - (float) $detail['ore_sas_pe_loc']), 2, ',', '.'),
                number_format((float) $detail['ore_aspirare'], 2, ',', '.')
            );
        }
        if ($withHours) {
            $steps[] = sprintf('Km echivalenți din ore = %s h × %s km/oră = %s km.', number_format($calc['ore_motor'], 2, ',', '.'), $km((float) $params['factor']), $km($calc['km_din_ore']));
        }
        if ((float) $params['initial_km'] > 0) {
            $steps[] = sprintf('Km echivalenți = %s (consum inițial din odometru) + %s + %s = %s km.', $km($calc['consum_initial_km']), $km($calc['km_efectuati']), $km($calc['km_din_ore']), $km($calc['km_total']));
        } else {
            $steps[] = sprintf('Km echivalenți = %s + %s = %s km.', $km($calc['km_efectuati']), $km($calc['km_din_ore']), $km($calc['km_total']));
        }
        $steps[] = sprintf('Km rămași = %s − %s = %s km.', $km((float) $calc['interval_km']), $km($calc['km_total']), $km($calc['km_ramasi']));
        $steps[] = sprintf('Progres = %s / %s × 100 = %s%%.', $km($calc['km_total']), $km((float) $calc['interval_km']), number_format($calc['progres'], 1, ',', '.'));
        $steps[] = sprintf(
            'Status: km rămași ≤ 0 → Necesită revizie; sub pragul de %s km → Se apropie; altfel În regulă.%s',
            number_format((int) $params['warn_km'], 0, ',', '.'),
            $missing !== [] ? ' Atenție: ' . (count($missing) === 1 ? '1 zi' : count($missing) . ' zile') . ' fără telemetrie — km echivalenți sunt o limită inferioară, km rămași una superioară.' : ''
        );

        return $steps;
    }

    // ================================================================ productie

    /**
     * Uzura unei componente (vehicul + tip de revizie) de la T0-ul planului pana la $untilTs
     * (doar din BD).
     *
     * @return array<string, mixed>
     */
    public function computeComponent(int $vehicleId, int $untilTs, string $tip = ReviziiModel::TIP_SCHIMB_ULEI, string $subLabel = ''): array
    {
        $this->load();
        $vehicle = $this->vehicles[$vehicleId] ?? null;
        if ($vehicle === null) {
            throw new InvalidArgumentException('Vehicul inexistent sau inactiv.');
        }
        $tab = ReviziiModel::tabOfTip($tip) ?? ReviziiModel::TIP_SCHIMB_ULEI;
        $plan = $this->plans[$tip][$vehicleId] ?? null;
        $isTrailer = is_trailer_vehicle_type((string) $vehicle['tip_vehicul']);
        $warnKm = $plan !== null && $plan['prag_avertizare_km'] !== null ? (int) $plan['prag_avertizare_km'] : (int) $this->settings['prag_avertizare_km'];

        $base = [
            'vehicle_id' => $vehicleId,
            'nr' => (string) $vehicle['nr_inmatriculare'],
            'marca' => trim((string) $vehicle['marca'] . ' ' . (string) $vehicle['model']),
            'tip' => (string) $vehicle['tip_vehicul'],
            'is_trailer' => $isTrailer,
            'km_bord' => (int) $vehicle['km_bord'],
            'poza' => function_exists('vehicle_image_url') ? vehicle_image_url((string) ($vehicle['poza_stocata'] ?? '')) : null,
            'prag_km' => $warnKm,
            'tip_revizie' => $tip,
            'sub' => $subLabel,
            'ore_aplicabile' => ReviziiModel::TABS[$tab]['ore'] && !$isTrailer,
            'plan' => $plan === null ? null : [
                'id' => (int) $plan['id'],
                'interval_km' => (int) $plan['interval_km'],
                'km_ultima_revizie' => $plan['km_ultima_revizie'] !== null ? (int) $plan['km_ultima_revizie'] : null,
                'prag_avertizare_km' => $plan['prag_avertizare_km'] !== null ? (int) $plan['prag_avertizare_km'] : null,
                'baseline_at' => (string) $plan['baseline_at'],
                'consum_initial_sursa' => (string) $plan['consum_initial_sursa'],
            ],
        ];

        if ($plan === null) {
            $carId = $isTrailer ? null : ($this->sasMap[$vehicleId] ?? null);
            return $base + [
                'status' => ReviziiCalculator::STATUS_NOT_CONFIGURED,
                'calc' => null, 'detaliu' => null, 'complet' => true, 'zile_lipsa' => [], 'ore_incomplete' => false,
                'surse' => [], 'note' => [], 'pasi' => [], 'has_source' => $carId !== null,
                'ultima_sincronizare' => $carId !== null ? ($this->syncState[$carId]['last_ok'] ?? null) : null,
                'date_vechi' => false,
            ];
        }

        $result = $this->calculate($vehicleId, (int) strtotime((string) $plan['baseline_at']), $untilTs, [
            'interval' => (int) $plan['interval_km'],
            'warn_km' => $warnKm,
            'factor' => $this->factor(),
            'source' => $this->hourSource(),
            'initial_km' => (float) $plan['consum_initial_km'],
            'ore' => ReviziiModel::TABS[$tab]['ore'],
        ]);
        if ((float) $plan['consum_initial_km'] > 0) {
            $result['note'][] = 'Include ' . number_format((float) $plan['consum_initial_km'], 0, ',', '.') . ' km din odometru (km bord − km la ultima revizie) până la configurare, fără ore motor.';
        }

        return $base + ['status' => $result['calc']['status']] + $result;
    }

    /**
     * Datele unui tab: setari, KPI, randuri (ansambluri / vehicule individuale), ordonate
     * cu cele critice si cele care se apropie primele. Fiecare rand are pe fiecare parte
     * (cap tractor / semi-remorca) lista componentelor urmarite in tab.
     *
     * @return array<string, mixed>
     */
    public function buildDashboard(string $tab = ReviziiModel::TIP_SCHIMB_ULEI): array
    {
        $this->load();
        if (!isset(ReviziiModel::TABS[$tab])) {
            $tab = ReviziiModel::TIP_SCHIMB_ULEI;
        }
        $now = time();

        $history = [];
        $tips = array_unique(array_merge(array_keys(ReviziiModel::itemsFor($tab, false)), array_keys(ReviziiModel::itemsFor($tab, true))));
        foreach ($tips as $tip) {
            $history[$tip] = $this->model->getHistory(array_keys($this->vehicles), 5, $tip);
        }

        $items = [];
        $withTrailers = ReviziiModel::tabHasTrailers($tab);
        foreach ($this->vehicles as $vehicleId => $vehicle) {
            $isTrailer = is_trailer_vehicle_type((string) $vehicle['tip_vehicul']);
            $items[$vehicleId] = [];
            foreach (ReviziiModel::itemsFor($tab, $isTrailer) as $tip => $label) {
                $component = $this->computeComponent($vehicleId, $now, $tip, $label);
                $component['istoric'] = $history[$tip][$vehicleId] ?? [];
                $items[$vehicleId][] = $component;
            }
        }

        $rows = [];
        $coupled = [];
        // Ansamblurile se formeaza doar in tab-urile care urmaresc si semi-remorci (placute,
        // anvelope); la ulei fiecare cap tractor / camion apare individual.
        foreach ($withTrailers ? $this->couplings : [] as $coupling) {
            if ($coupling['activ'] !== 1 || $coupling['data_end'] !== null) {
                continue;
            }
            $tractorId = $coupling['tractor_id'];
            $trailerId = $coupling['semiremorca_id'];
            if (!isset($this->vehicles[$tractorId], $this->vehicles[$trailerId]) || isset($coupled[$tractorId]) || isset($coupled[$trailerId])) {
                continue;
            }
            $coupled[$tractorId] = $coupled[$trailerId] = true;
            $rows[] = $this->row('ansamblu', $items[$tractorId], $items[$trailerId]);
        }
        foreach ($this->vehicles as $vehicleId => $vehicle) {
            if (isset($coupled[$vehicleId])) {
                continue;
            }
            if (is_trailer_vehicle_type((string) $vehicle['tip_vehicul'])) {
                if ($items[$vehicleId] !== []) {
                    $rows[] = $this->row('semiremorca', [], $items[$vehicleId]);
                }
            } else {
                $rows[] = $this->row($vehicle['tip_vehicul'] === 'camion' ? 'camion' : 'cap_tractor', $items[$vehicleId], []);
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['severity'], $a['min_ramasi'], $a['label']] <=> [$a['severity'], $b['min_ramasi'], $b['label']]);

        $kpi = ['in_regula' => 0, 'se_apropie' => 0, 'necesita' => 0, 'total' => count($rows)];
        foreach ($rows as $row) {
            if (isset($kpi[$row['status']])) {
                $kpi[$row['status']]++;
            }
        }

        $lastSync = null;
        foreach ($this->syncState as $state) {
            if ($state['last_ok'] !== null && ($lastSync === null || $state['last_ok'] > $lastSync)) {
                $lastSync = $state['last_ok'];
            }
        }

        return [
            'tab' => $tab,
            'with_trailers' => $withTrailers,
            'settings' => $this->settings,
            'hour_source' => $this->hourSource(),
            'kpi' => $kpi,
            'rows' => $rows,
            'last_sync' => $lastSync,
            'mapped_vehicles' => count($this->sasMap),
        ];
    }

    /**
     * Prezentare generală: un singur set normalizat (vehicul × componentă) din care se
     * calculeaza KPI-urile flotei, situatia pe categorii, alertele si istoricul — aceleasi
     * calcule ca paginile pe categorii (computeComponent), fara apeluri SasFleet.
     *
     * Reguli de agregare:
     *  - un vehicul fizic se numara o singura data (ansamblurile nu dubleaza nimic);
     *  - starea unui vehicul (global sau pe categorie) = cea mai critica dintre componentele
     *    lui configurate: Necesită > Se apropie > Date insuficiente > În regulă;
     *  - fara nicio componenta configurata = Neconfigurat (niciodata „în regulă”).
     *
     * @return array<string, mixed>
     */
    public function buildOverview(): array
    {
        $this->load();
        $now = time();
        $severity = [
            ReviziiCalculator::STATUS_DUE => 4,
            ReviziiCalculator::STATUS_SOON => 3,
            ReviziiCalculator::STATUS_NO_DATA => 2,
            ReviziiCalculator::STATUS_OK => 1,
            ReviziiCalculator::STATUS_NOT_CONFIGURED => 0,
        ];
        $emptyCounts = static fn (): array => [
            ReviziiCalculator::STATUS_OK => 0,
            ReviziiCalculator::STATUS_SOON => 0,
            ReviziiCalculator::STATUS_DUE => 0,
            ReviziiCalculator::STATUS_NO_DATA => 0,
            ReviziiCalculator::STATUS_NOT_CONFIGURED => 0,
            'total' => 0,
        ];

        $global = $emptyCounts();
        $categories = [];
        foreach (ReviziiModel::TABS as $tab => $meta) {
            $categories[$tab] = ['label' => $meta['label'], 'ore' => $meta['ore'], 'counts' => $emptyCounts(), 'componente' => 0];
        }
        $alerts = [];
        $okRows = [];
        $simPlans = [];

        foreach ($this->vehicles as $vehicleId => $vehicle) {
            $isTrailer = is_trailer_vehicle_type((string) $vehicle['tip_vehicul']);
            $vehicleWorst = ReviziiCalculator::STATUS_NOT_CONFIGURED;
            $monitoredSomewhere = false;
            foreach (ReviziiModel::TABS as $tab => $meta) {
                $items = ReviziiModel::itemsFor($tab, $isTrailer);
                if ($items === []) {
                    continue; // ex. uleiul nu se aplica semi-remorcilor
                }
                $monitoredSomewhere = true;
                $categoryWorst = ReviziiCalculator::STATUS_NOT_CONFIGURED;
                foreach ($items as $tip => $label) {
                    $component = $this->computeComponent($vehicleId, $now, $tip, $label);
                    $categories[$tab]['componente']++;
                    if ($severity[$component['status']] > $severity[$categoryWorst]) {
                        $categoryWorst = $component['status'];
                    }
                    if ($component['plan'] !== null) {
                        $simPlans[$tab][$vehicleId] ??= [
                            'interval' => $component['plan']['interval_km'],
                            'warn' => $component['prag_km'],
                            't0' => $component['plan']['baseline_at'],
                        ];
                    }
                    $isAlert = in_array($component['status'], [ReviziiCalculator::STATUS_DUE, ReviziiCalculator::STATUS_SOON], true);
                    if ($isAlert || $component['status'] === ReviziiCalculator::STATUS_OK) {
                        $row = [
                            'vehicle_id' => $vehicleId,
                            'nr' => $component['nr'],
                            'marca' => $component['marca'],
                            'vehicul_tip' => vehicle_type_label((string) $vehicle['tip_vehicul']),
                            'categorie' => $tab,
                            'categorie_label' => $meta['label'],
                            'tip' => $tip,
                            'componenta' => ReviziiModel::componentName($tip, (string) $vehicle['tip_vehicul']),
                            'status' => $component['status'],
                            'km_ramasi' => (float) $component['calc']['km_ramasi'],
                            'interval_km' => (int) $component['calc']['interval_km'],
                            'prag_km' => (int) $component['prag_km'],
                            'complet' => (bool) $component['complet'],
                            'poza' => $component['poza'],
                            // Identitatea ciclului pentru notificari: planul + T0-ul curent.
                            'plan_id' => (int) $component['plan']['id'],
                            'baseline_at' => (string) $component['plan']['baseline_at'],
                        ];
                        // Cele in regula le foloseste doar raportul saptamanal filtrat pe vehicule.
                        if ($isAlert) {
                            $alerts[] = $row;
                        } else {
                            $okRows[] = $row;
                        }
                    }
                }
                $categories[$tab]['counts'][$categoryWorst]++;
                $categories[$tab]['counts']['total']++;
                if ($severity[$categoryWorst] > $severity[$vehicleWorst]) {
                    $vehicleWorst = $categoryWorst;
                }
            }
            if ($monitoredSomewhere) {
                $global[$vehicleWorst]++;
                $global['total']++;
            }
        }

        // Intai depasirile (cea mai mare depasire prima), apoi cele care se apropie (cei mai putini km).
        usort($alerts, static fn (array $a, array $b): int => [$severity[$b['status']], $a['km_ramasi'], $a['nr']] <=> [$severity[$a['status']], $b['km_ramasi'], $b['nr']]);

        $history = [];
        foreach ($this->model->getAllHistory() as $row) {
            $tab = ReviziiModel::tabOfTip((string) $row['tip_revizie']);
            $history[] = [
                'id' => (int) $row['id'],
                'data' => (string) $row['data_revizie'],
                'vehicle_id' => (int) $row['vehicle_id'],
                'nr' => (string) $row['nr_inmatriculare'],
                'categorie' => $tab ?? 'necunoscut',
                'categorie_label' => $tab !== null ? ReviziiModel::TABS[$tab]['label'] : (string) $row['tip_revizie'],
                'componenta' => ReviziiModel::componentName((string) $row['tip_revizie'], (string) $row['tip_vehicul']),
                'actiune' => ReviziiModel::actionName((string) $row['tip_revizie']),
                'observatii' => $row['observatii'] !== null ? (string) $row['observatii'] : '',
                'operator' => $row['created_by_name'] !== null ? (string) $row['created_by_name'] : null,
                // „Date incomplete” = ciclul anterior masurat avea zile fara telemetrie. La primul T0
                // nu exista ciclu anterior (km_echivalenti NULL) — inregistrarea e completa.
                'status' => (int) $row['date_complete'] === 1 || $row['km_echivalenti'] === null ? 'finalizat' : 'date_incomplete',
                'km_bord' => $row['km_bord'] !== null ? (int) $row['km_bord'] : null,
                'km_consumati' => $row['km_echivalenti'] !== null ? (float) $row['km_echivalenti'] : null,
            ];
        }

        $lastSync = null;
        foreach ($this->syncState as $state) {
            if ($state['last_ok'] !== null && ($lastSync === null || $state['last_ok'] > $lastSync)) {
                $lastSync = $state['last_ok'];
            }
        }

        return [
            'kpi' => $global,
            'categorii' => $categories,
            'alerte' => $alerts,
            'in_regula' => $okRows,
            'istoric' => $history,
            'sim_plans' => $simPlans,
            'settings' => $this->settings,
            'hour_source' => $this->hourSource(),
            'last_sync' => $lastSync,
        ];
    }

    /**
     * Inregistreaza o revizie / un montaj (T0 nou) pentru componentele date. Fiecare
     * componenta (vehicul + tip) are propriul T0: cap tractor, semi-remorca, placute fata /
     * spate se schimba independent, la ore diferite.
     *
     * @param array<int, array{vehicle_id:int, tip:string, at:string, km:?int}> $entries
     */
    public function recordService(array $entries, ?string $notes, ?int $userId): int
    {
        $this->load();
        $default = max(1, (int) $this->settings['interval_implicit_km']);

        $db = $this->model->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            $count = 0;
            foreach ($entries as $entry) {
                $vehicleId = (int) ($entry['vehicle_id'] ?? 0);
                $tip = (string) ($entry['tip'] ?? ReviziiModel::TIP_SCHIMB_ULEI);
                if (!isset($this->vehicles[$vehicleId])) {
                    throw new InvalidArgumentException('Vehicul inexistent sau inactiv.');
                }
                $isTrailer = is_trailer_vehicle_type((string) $this->vehicles[$vehicleId]['tip_vehicul']);
                $tab = ReviziiModel::tabOfTip($tip);
                $itemLabel = $tab !== null ? (ReviziiModel::itemsFor($tab, $isTrailer)[$tip] ?? null) : null;
                if ($itemLabel === null) {
                    throw new InvalidArgumentException('Componentă necunoscută pentru acest vehicul.');
                }
                $label = trim((string) $this->vehicles[$vehicleId]['nr_inmatriculare'] . ' ' . ReviziiModel::TABS[$tab]['label'] . ' ' . $itemLabel);
                $serviceTs = self::parseServiceMoment((string) ($entry['at'] ?? ''), $label);
                $serviceAt = date('Y-m-d H:i:s', $serviceTs);

                $plan = $this->plans[$tip][$vehicleId] ?? null;
                if ($plan === null && $tip !== ReviziiModel::TIP_SCHIMB_ULEI) {
                    throw new InvalidArgumentException(sprintf('Configurează întâi intervalul pentru %s („Configurare revizie”).', $label));
                }
                // Ordinea se verifica fata de istoric (nu fata de T0-ul de configurare): o revizie
                // reala anterioara configurarii e permisa, una anterioara ultimei revizii nu.
                $lastService = $this->model->getLastServiceAt($vehicleId, $tip);
                if ($lastService !== null && $serviceTs < (int) strtotime($lastService)) {
                    throw new InvalidArgumentException(sprintf(
                        'T0 pentru %s nu poate fi înaintea ultimei revizii înregistrate (%s).',
                        $label,
                        date('d.m.Y H:i', (int) strtotime($lastService))
                    ));
                }
                $snapshot = $plan !== null && $serviceTs >= (int) strtotime((string) $plan['baseline_at'])
                    ? $this->computeComponent($vehicleId, $serviceTs, $tip, $itemLabel)
                    : null;
                $this->model->recordService(
                    $vehicleId,
                    $serviceAt,
                    $entry['km'] ?? null,
                    $plan !== null ? (int) $plan['interval_km'] : $default,
                    $snapshot !== null && $snapshot['calc'] !== null ? $snapshot['calc'] + ['complet' => $snapshot['complet']] : ['complet' => false],
                    ReviziiModel::TABS[$tab]['ore'] ? $this->factor() : 0.0,
                    ReviziiModel::TABS[$tab]['ore'] ? $this->hourSource() : 'fara_ore',
                    $notes,
                    $userId,
                    $tip
                );
                $count++;
            }
            if ($ownTransaction) {
                $db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw $exception;
        }
        $this->loaded = false;

        return $count;
    }

    /**
     * T0 = momentul exact al reviziei: ora e obligatorie (o data fara ora ar muta T0 la 00:00).
     */
    private static function parseServiceMoment(string $value, string $label): int
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value)) {
            throw new InvalidArgumentException(sprintf('Completează data și ora reviziei pentru %s (ex. 02.07.2026 15:01).', $label));
        }
        $timestamp = strtotime(str_replace('T', ' ', $value));
        if ($timestamp === false || $timestamp > time() + 300) {
            throw new InvalidArgumentException(sprintf('Data reviziei pentru %s nu poate fi în viitor.', $label));
        }

        return $timestamp;
    }

    // ================================================================ simulare

    /**
     * Simulare pe date istorice: acelasi calcul ca in productie, pe un T0 ales.
     * Zilele nesincronizate local se completeaza read-only din SasFleet prin $fetcher
     * (fn(int $carId, string $fromDate, string $toDate): array segmente brute cu timestamp);
     * nimic nu se scrie in BD — nici telemetrie, nici planuri, nici istoric.
     *
     * @param array{interval:int, warn_km:int, factor:float, source:string} $params
     * @return array<string, mixed>
     */
    public function simulate(int $vehicleId, string $from, string $to, array $params, ?callable $fetcher): array
    {
        $this->load();
        $fromTs = strtotime($from);
        $toTs = strtotime($to);
        if ($fromTs === false || $toTs === false || $toTs <= $fromTs) {
            throw new InvalidArgumentException('Intervalul simulării este invalid (sfârșitul trebuie să fie după T0).');
        }
        if ($toTs > time()) {
            throw new InvalidArgumentException('Simularea folosește doar date istorice: sfârșitul nu poate fi în viitor.');
        }
        if (($toTs - $fromTs) / 86400 > self::SIMULATION_MAX_DAYS) {
            throw new InvalidArgumentException('Intervalul maxim al simulării este de ' . self::SIMULATION_MAX_DAYS . ' de zile.');
        }
        if (!isset($this->allVehicles[$vehicleId])) {
            throw new InvalidArgumentException('Vehicul inexistent.');
        }

        $budget = self::SIMULATION_MAX_CALLS;
        $fill = $fetcher === null ? null : function (int $carId, array $missingDays) use ($fetcher, &$budget): array {
            $result = ['segments' => [], 'ok_days' => [], 'errors' => [], 'calls' => 0];
            foreach (self::chunkDays($missingDays, 7) as [$chunkFrom, $chunkTo]) {
                if ($budget <= 0) {
                    $result['errors'][] = 'limita de ' . self::SIMULATION_MAX_CALLS . ' cereri atinsă — zilele rămase sunt raportate ca lipsă.';
                    break;
                }
                $budget--;
                $result['calls']++;
                try {
                    foreach ($fetcher($carId, $chunkFrom, $chunkTo) as $segment) {
                        $result['segments'][] = $segment;
                    }
                    for ($day = $chunkFrom; $day <= $chunkTo; $day = date('Y-m-d', (int) strtotime($day . ' +1 day'))) {
                        $result['ok_days'][] = $day;
                    }
                } catch (Throwable $exception) {
                    $result['errors'][] = sprintf('%s – %s indisponibil (%s).', date('d.m', (int) strtotime($chunkFrom)), date('d.m', (int) strtotime($chunkTo)), $exception->getMessage());
                }
            }
            return $result;
        };

        $result = $this->calculate($vehicleId, (int) $fromTs, (int) $toTs, $params + ['initial_km' => 0.0], $fill);
        $vehicle = $this->allVehicles[$vehicleId];

        return $result + [
            'vehicul' => [
                'id' => $vehicleId,
                'nr' => (string) $vehicle['nr_inmatriculare'],
                'marca' => trim((string) $vehicle['marca'] . ' ' . (string) $vehicle['model']),
                'is_trailer' => is_trailer_vehicle_type((string) $vehicle['tip_vehicul']),
                'inactiv' => $vehicle['status'] !== 'activ',
            ],
            'perioada' => ['de_la' => date('Y-m-d H:i:s', (int) $fromTs), 'pana_la' => date('Y-m-d H:i:s', (int) $toTs)],
            'parametri' => $params,
            'fara_date' => !$result['has_source'] || ($result['detaliu']['segmente'] === 0 && $result['zile_lipsa'] !== []),
        ];
    }

    /**
     * Grupeaza zile (Y-m-d, sortate) in ferestre consecutive de maxim $max zile.
     *
     * @param array<int, string> $days
     * @return array<int, array{0:string, 1:string}>
     */
    public static function chunkDays(array $days, int $max): array
    {
        sort($days);
        $windows = [];
        $start = $prev = null;
        $length = 0;
        foreach ($days as $day) {
            $consecutive = $prev !== null && date('Y-m-d', (int) strtotime($prev . ' +1 day')) === $day;
            if ($start === null || !$consecutive || $length >= $max) {
                if ($start !== null) {
                    $windows[] = [$start, $prev];
                }
                $start = $day;
                $length = 0;
            }
            $prev = $day;
            $length++;
        }
        if ($start !== null) {
            $windows[] = [$start, $prev];
        }

        return $windows;
    }

    /**
     * @param array<int, array<string, mixed>> $tractorItems componentele capului tractor / camionului
     * @param array<int, array<string, mixed>> $semiItems componentele semi-remorcii
     * @return array<string, mixed>
     */
    private function row(string $kind, array $tractorItems, array $semiItems): array
    {
        $severity = [
            ReviziiCalculator::STATUS_DUE => 4,
            ReviziiCalculator::STATUS_SOON => 3,
            ReviziiCalculator::STATUS_NO_DATA => 2,
            ReviziiCalculator::STATUS_OK => 1,
            ReviziiCalculator::STATUS_NOT_CONFIGURED => 0,
        ];
        // Starea randului = componenta cea mai critica; neconfiguratele nu strica starea.
        $worst = ReviziiCalculator::STATUS_NOT_CONFIGURED;
        $minRemaining = PHP_FLOAT_MAX;
        foreach (array_merge($tractorItems, $semiItems) as $component) {
            if ($severity[$component['status']] > $severity[$worst]) {
                $worst = $component['status'];
            }
            if ($component['calc'] !== null) {
                $minRemaining = min($minRemaining, (float) $component['calc']['km_ramasi']);
            }
        }
        $tractor = $tractorItems[0] ?? null;
        $semi = $semiItems[0] ?? null;
        $lead = $tractor ?? $semi;

        return [
            'kind' => $kind,
            'label' => (string) ($lead['nr'] ?? ''),
            'tractor' => $tractor,
            'semi' => $semi,
            'items' => ['tractor' => $tractorItems, 'semi' => $semiItems],
            'status' => $worst,
            'severity' => $severity[$worst],
            'min_ramasi' => $minRemaining,
        ];
    }
}
