<?php
declare(strict_types=1);

require_once __DIR__ . '/FuelFxCalculator.php';

/**
 * „Ajustare tarife combustibil & curs BNR” — orchestrare.
 *
 * SEPARAREA OBLIGATORIE
 *   Indicatorii și simulările se recalculează automat (cron zilnic + la deschiderea
 *   paginii). Tarifele contractuale se schimbă DOAR prin applyApproval(), apelată
 *   explicit de un utilizator cu drept `ajustare_tarife.apply`, care creează versiuni
 *   noi prin TransportTariffModel::createVersion() — fără să atingă curse_dispecer,
 *   facturi sau versiunile anterioare.
 *
 * SURSE
 *   Curs EUR/RON  – bnr_exchange_rates (BNR XML), media aritmetică a zilelor publicate.
 *   Motorină lei/l – fuel_fillups CardOil, aceleași filtre ca FuelPriceIndexService
 *                    (doar motorină, doar source_type='api', litri și valoare > 0),
 *                    medie PONDERATĂ CU VOLUMUL: SUM(valoare)/SUM(litri). Datele sunt
 *                    tranzacții de flotă cu volume foarte diferite; media simplă ar da
 *                    aceeași greutate unei alimentări de 20 l și uneia de 600 l.
 *   Tarife        – versiunea în vigoare azi din transport_tariff_versions; fără
 *                    versiune, valoarea din Configurare transport (aceeași ordine ca
 *                    TransportPricingService). Toate tarifele existente sunt în lei.
 *
 * REGULA DE AJUSTARE (stabilită de beneficiarul aplicației la 21.10.2026)
 *   variație = (motorină EUR curentă / motorină EUR referință − 1)×100   [bază „diesel_eur”]
 *              sau (motorină lei curentă / referință − 1)×100              [bază „diesel_ron”]
 *   |variație| ≥ prag (implicit 2%, modificabil) ⇒ tarif nou = tarif × (1 + variație/100)
 *   Tariful se modifică cu ACELAȘI procent ca motorina (indexare integrală, fără pondere).
 */
class FuelFxAdjustmentService
{
    public const ADJUST_BASES = [
        'diesel_eur' => 'Motorină în EUR (motorină lei ÷ curs mediu)',
        'diesel_ron' => 'Motorină în lei',
    ];

    /** Pragul folosit când nu e setat altul în transport_tariff_settings. */
    public const DEFAULT_THRESHOLD = 2.0;

    /** Tariful se indexează integral: același procent ca motorina. */
    public const FUEL_SHARE = 1.0;

    public const STATUS_LABELS = [
        'simulated' => 'Simulat',
        'within' => 'Sub prag',
        'no_baseline' => 'Fără referință',
        'insufficient' => 'Date insuficiente',
        'stale' => 'Date neactualizate',
        'scheduled' => 'Versiune programată',
    ];

    /** Ordinea rândurilor din tabel (ca în machetă) + eticheta + unitatea afișată. */
    private const GROUPS = [
        'primar_km' => ['label' => 'Primar', 'order' => 10],
        'primar_cursa' => ['label' => 'Primar – cost / cursă', 'order' => 11],
        'primar_tona' => ['label' => 'Primar – tone', 'order' => 12],
        'dist_unic' => ['label' => 'Distribuție – preț unic', 'order' => 20],
        'dist_mic' => ['label' => 'Distribuție – preț mic', 'order' => 21],
        'dist_mare' => ['label' => 'Distribuție – preț mare', 'order' => 22],
        'dist_alt' => ['label' => 'Distribuție – alte tarife', 'order' => 23],
        'dist_km' => ['label' => 'Distribuție – km', 'order' => 24],
        'dist_cursa' => ['label' => 'Distribuție – cost / cursă', 'order' => 25],
        'pd_tona' => ['label' => 'P+D', 'order' => 30],
        'pd_km' => ['label' => 'P+D – km', 'order' => 31],
        'pd_cursa' => ['label' => 'P+D – cost / cursă', 'order' => 32],
        'comp_ora' => ['label' => 'Compresor', 'order' => 40],
        'comp_km' => ['label' => 'Compresor – km dislocare', 'order' => 41],
        'comp_tona' => ['label' => 'Compresor – tone', 'order' => 42],
    ];

    private PDO $db;
    private FuelFxAdjustmentModel $model;
    private TransportTariffModel $tariffs;
    private ?array $settingsCache = null;
    /** @var array<string,float>|null prețuri de test pe lună; null = date reale */
    private ?array $testMonths = null;
    private ?string $testReferenceEnd = null;

    public function __construct(PDO $db, ?FuelFxAdjustmentModel $model = null, ?TransportTariffModel $tariffs = null)
    {
        $this->db = $db;
        $this->model = $model ?? new FuelFxAdjustmentModel($db);
        $this->tariffs = $tariffs ?? new TransportTariffModel($db);
    }

    public function model(): FuelFxAdjustmentModel
    {
        return $this->model;
    }

    // ================================================================ mod test

    /**
     * MOD TEST (doar pentru verificarea simulării): motorina fiecărei luni este
     * prețul introdus manual, iar lunile fără preț de test păstrează media reală.
     * Cursul EUR/RON rămâne cel real BNR. Referința e calculată din aceleași prețuri
     * pe fereastra care se termină la data de referință de test. Nimic din modul
     * test nu se salvează ca indicator, instantaneu, referință sau tarif, iar
     * aplicarea tarifelor e refuzată.
     *
     * @param array<string,float>|null $months null = cele salvate
     */
    public function enableTestMode(?array $months = null, ?string $referenceEnd = null): void
    {
        $this->testMonths = $months ?? $this->model->getTestDieselMonths();
        if ($referenceEnd === null) {
            $raw = trim((string) ($this->model->getSettings($this->tariffs)['fx_test_reference_end'] ?? ''));
            $referenceEnd = $raw !== '' ? $raw : null;
        }
        $this->testReferenceEnd = $referenceEnd !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $referenceEnd) === 1
            ? $referenceEnd
            : null;
    }

    public function isTestMode(): bool
    {
        return $this->testMonths !== null;
    }

    /**
     * Salvează prețurile de test (lună goală = se șterge) și data de referință.
     *
     * @param array<string,mixed> $months 'YYYY-MM' → preț lei/l (text, acceptă virgulă)
     */
    public function saveTestMonths(array $months, string $referenceEnd, ?int $userId): int
    {
        $clean = [];
        foreach ($months as $month => $raw) {
            $month = (string) $month;
            $raw = str_replace(',', '.', trim((string) $raw));
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
                throw new RuntimeException('Lună invalidă: ' . $month);
            }
            if (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw >= 100) {
                throw new RuntimeException('Prețul pentru ' . $month . ' trebuie să fie un număr între 0 și 100 lei/l.');
            }
            $clean[$month] = (float) $raw;
        }
        $referenceEnd = trim($referenceEnd);
        if ($referenceEnd !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $referenceEnd) !== 1 || !$this->validDate($referenceEnd))) {
            throw new RuntimeException('Data de referință de test este invalidă.');
        }

        $this->model->replaceTestDieselMonths($clean, $userId);
        $this->tariffs->setSetting('fx_test_reference_end', $referenceEnd, $userId);

        return count($clean);
    }

    /**
     * Media de test pe o fereastră: Σ(preț lună × zile din fereastră) / Σ zile,
     * unde prețul lunii = prețul de test sau media reală ponderată a acelei părți
     * de lună. Zilele unei luni fără niciun preț nu intră în calcul.
     *
     * @return array{avg:?float, days:int, breakdown:array<int,array<string,mixed>>}
     */
    public function testDieselWindow(string $start, string $end): array
    {
        $breakdown = [];
        $weighted = 0.0;
        $days = 0;
        $cursor = (new DateTimeImmutable($start))->modify('first day of this month');
        $endDate = new DateTimeImmutable($end);
        while ($cursor <= $endDate) {
            $from = max($start, $cursor->format('Y-m-d'));
            $to = min($end, $cursor->format('Y-m-t'));
            $count = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
            $month = $cursor->format('Y-m');
            $real = $this->realDieselStats($from, $to);
            $test = $this->testMonths[$month] ?? null;
            $value = $test ?? $real['avg'];
            if ($value !== null) {
                $weighted += $value * $count;
                $days += $count;
            }
            $breakdown[] = [
                'month' => $month,
                'from' => $from,
                'to' => $to,
                'days' => $count,
                'real' => $real['avg'],
                'test' => $test,
                'value' => $value,
                'source' => $test !== null ? 'test' : ($real['avg'] !== null ? 'real' : 'none'),
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return ['avg' => $days > 0 ? $weighted / $days : null, 'days' => $days, 'breakdown' => $breakdown];
    }

    /** Media reală pe luni (pentru tabelul din modul test). @return array<string,array{avg:float, liters:float, count:int}> */
    public function realMonthlyDiesel(int $months = 13): array
    {
        $from = (new DateTimeImmutable('first day of this month'))->modify('-' . max(0, $months - 1) . ' months')->format('Y-m-d');
        $stmt = $this->db->prepare("
            SELECT DATE_FORMAT(fillup_datetime, '%Y-%m') AS m, SUM(total_value) AS v, SUM(quantity_liters) AS l, COUNT(*) AS n
            FROM fuel_fillups
            WHERE fuel_type = 'motorina' AND source_type = 'api'
              AND quantity_liters > 0 AND total_value > 0
              AND fillup_datetime >= :f
            GROUP BY m
        ");
        $stmt->execute(['f' => $from . ' 00:00:00']);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(string) $row['m']] = ['avg' => (float) $row['v'] / (float) $row['l'], 'liters' => (float) $row['l'], 'count' => (int) $row['n']];
        }

        return $map;
    }

    /** Referința de test (nu se salvează): aceeași formă ca un rând din fx_tariff_baselines. */
    private function testBaseline(): ?array
    {
        if ($this->testReferenceEnd === null) {
            return null;
        }
        $window = FuelFxCalculator::rollingWindow($this->testReferenceEnd, $this->settings()['months']);
        $fx = $this->fxWindowStats($window['start'], $window['end']);
        $diesel = $this->testDieselWindow($window['start'], $window['end']);
        if ($fx['avg'] === null || $diesel['avg'] === null) {
            return null;
        }

        return [
            'id' => 0,
            'label' => 'Referință test',
            'window_start' => $window['start'],
            'window_end' => $window['end'],
            'diesel_ron' => $diesel['avg'],
            'eur_ron' => $fx['avg'],
            'diesel_eur' => $diesel['avg'] / $fx['avg'],
            'fx_observations' => $fx['count'],
            'source' => 'test',
            'breakdown' => $diesel['breakdown'],
        ];
    }

    // ================================================================ setări

    /** @return array<string,mixed> */
    public function settings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $raw = $this->model->getSettings($this->tariffs);
        $basis = (string) ($raw['fx_adjust_basis'] ?? 'diesel_eur');

        return $this->settingsCache = [
            'months' => in_array((int) $raw['fx_analysis_months'], [1, 3, 6, 12], true) ? (int) $raw['fx_analysis_months'] : 3,
            'basis' => array_key_exists($basis, self::ADJUST_BASES) ? $basis : 'diesel_eur',
            'threshold' => $this->tariffs->getReviewThresholdPercent() ?? self::DEFAULT_THRESHOLD,
            'fx_min_observations' => max(1, (int) ($raw['fx_min_observations'] ?? 20)),
            'fx_stale_days' => max(1, (int) ($raw['fx_stale_days'] ?? 4)),
            'fuel_stale_days' => max(1, (int) (($raw['fuel_data_stale_days'] ?? '') !== '' ? $raw['fuel_data_stale_days'] : 7)),
            'fuel_min_observations' => max(0, (int) (($raw['fuel_min_observations'] ?? '') !== '' ? $raw['fuel_min_observations'] : 5)),
            'fuel_min_liters' => max(0.0, (float) (($raw['fuel_min_liters'] ?? '') !== '' ? $raw['fuel_min_liters'] : 500)),
        ];
    }

    public function saveSettings(array $input, ?int $userId): void
    {
        $months = (int) ($input['months'] ?? 3);
        if (!in_array($months, [1, 3, 6, 12], true)) {
            throw new RuntimeException('Interval de analiză invalid.');
        }
        $basis = (string) ($input['basis'] ?? 'diesel_eur');
        if (!array_key_exists($basis, self::ADJUST_BASES)) {
            throw new RuntimeException('Baza de ajustare este invalidă.');
        }
        $threshold = str_replace(',', '.', trim((string) ($input['threshold'] ?? '')));
        if ($threshold !== '' && (!is_numeric($threshold) || (float) $threshold <= 0 || (float) $threshold > 50)) {
            throw new RuntimeException('Pragul trebuie să fie un procent între 0 și 50.');
        }

        $this->tariffs->setSetting('fx_analysis_months', (string) $months, $userId);
        $this->tariffs->setSetting('fx_adjust_basis', $basis, $userId);
        if ($threshold !== '') {
            // Același prag ca monitorizarea motorinei din Administrare tarife.
            $this->tariffs->setSetting('fuel_review_threshold_percent', $threshold, $userId);
        }
        $this->settingsCache = null;
    }

    // ================================================================ surse

    /** @return array{avg:?float, count:int, first:?string, last:?string} */
    public function fxWindowStats(string $start, string $end): array
    {
        $rates = $this->model->getRates('EUR', $start, $end);

        return [
            'avg' => FuelFxCalculator::average(array_column($rates, 'rate')),
            'count' => count($rates),
            'first' => $rates[0]['rate_date'] ?? null,
            'last' => $rates !== [] ? $rates[count($rates) - 1]['rate_date'] : null,
        ];
    }

    /** @return array{avg:?float, liters:float, value:float, count:int, first:?string, last:?string} */
    public function dieselWindowStats(string $start, string $end): array
    {
        $real = $this->realDieselStats($start, $end);
        if ($this->isTestMode()) {
            $test = $this->testDieselWindow($start, $end);
            $real['avg'] = $test['avg'];
            $real['breakdown'] = $test['breakdown'];
        }

        return $real;
    }

    /** @return array{avg:?float, liters:float, value:float, count:int, first:?string, last:?string} */
    private function realDieselStats(string $start, string $end): array
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_value), 0) AS total_value,
                   COALESCE(SUM(quantity_liters), 0) AS total_liters,
                   COUNT(*) AS n, MIN(fillup_datetime) AS first_obs, MAX(fillup_datetime) AS last_obs
            FROM fuel_fillups
            WHERE fuel_type = 'motorina' AND source_type = 'api'
              AND quantity_liters > 0 AND total_value > 0
              AND fillup_datetime BETWEEN :s AND :e
        ");
        $stmt->execute(['s' => $start . ' 00:00:00', 'e' => $end . ' 23:59:59']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $liters = (float) ($row['total_liters'] ?? 0);
        $value = (float) ($row['total_value'] ?? 0);

        return [
            'avg' => $liters > 0 ? $value / $liters : null,
            'liters' => $liters,
            'value' => $value,
            'count' => (int) ($row['n'] ?? 0),
            'first' => $row['first_obs'] ?? null,
            'last' => $row['last_obs'] ?? null,
        ];
    }

    /** @return array{last_attempt:?string, last_success:?string, status:?string, age_days:?int} */
    private function fuelSyncStatus(): array
    {
        $result = ['last_attempt' => null, 'last_success' => null, 'status' => null, 'age_days' => null];
        try {
            $last = $this->db->query('SELECT sync_started_at, status FROM fuel_sync_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($last)) {
                $result['last_attempt'] = (string) $last['sync_started_at'];
                $result['status'] = (string) $last['status'];
            }
            $success = $this->db->query("SELECT sync_started_at FROM fuel_sync_logs WHERE status = 'success' ORDER BY id DESC LIMIT 1")->fetchColumn();
            if (is_string($success) && $success !== '') {
                $result['last_success'] = $success;
                $result['age_days'] = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable(substr($success, 0, 10)))->days;
            }
        } catch (Throwable $exception) {
            error_log('[FuelFxAdjustmentService][fuel_sync] ' . $exception->getMessage());
        }

        return $result;
    }

    // ================================================================ indicatori

    /**
     * Toți indicatorii paginii, calculați pe fereastra glisantă activă și comparați
     * cu referința implicită salvată.
     *
     * @return array<string,mixed>
     */
    public function indicators(?string $asOf = null): array
    {
        $settings = $this->settings();
        $asOf ??= date('Y-m-d');
        $window = FuelFxCalculator::rollingWindow($asOf, $settings['months']);

        $fx = $this->fxWindowStats($window['start'], $window['end']);
        $current = $this->model->getLatestRate('EUR', $asOf);
        $bnrSync = $this->model->getSyncStatus();
        $fxAgeDays = $current !== null
            ? (int) (new DateTimeImmutable($asOf))->diff(new DateTimeImmutable($current['rate_date']))->days
            : null;

        $diesel = $this->dieselWindowStats($window['start'], $window['end']);
        $fuelSync = $this->fuelSyncStatus();
        $dieselEur = FuelFxCalculator::dieselEur($diesel['avg'], $fx['avg']);

        $fxStale = $fxAgeDays === null || $fxAgeDays > $settings['fx_stale_days'];
        $fuelStale = $fuelSync['age_days'] === null || $fuelSync['age_days'] > $settings['fuel_stale_days'];
        $fxInsufficient = $fx['count'] < $settings['fx_min_observations'];
        $fuelInsufficient = $diesel['avg'] === null
            || $diesel['count'] < $settings['fuel_min_observations']
            || $diesel['liters'] < $settings['fuel_min_liters'];

        $test = null;
        if ($this->isTestMode()) {
            // În test prețurile sunt introduse de utilizator: vechimea / volumul
            // datelor reale nu blochează simularea (aplicarea e oricum refuzată).
            $fuelStale = false;
            $fxStale = false;
            $fuelInsufficient = $diesel['avg'] === null;
            $baseline = $this->testBaseline() ?? $this->model->getDefaultBaseline();
            $test = [
                'months' => $this->testMonths,
                'reference_end' => $this->testReferenceEnd,
                'current_breakdown' => $diesel['breakdown'] ?? [],
                'reference_breakdown' => $baseline['breakdown'] ?? [],
                'reference_is_test' => ($baseline['source'] ?? '') === 'test',
            ];
        } else {
            $baseline = $this->model->getDefaultBaseline();
        }
        $comparison = $baseline !== null ? $this->compareWithBaseline($baseline, $diesel['avg'], $fx['avg']) : null;
        $variation = $comparison['variation_pct'] ?? null;
        $thresholdState = FuelFxCalculator::thresholdState($variation, $settings['threshold']);

        $status = match (true) {
            $fx['avg'] === null || $diesel['avg'] === null => 'unavailable',
            $fxInsufficient || $fuelInsufficient => 'insufficient',
            $baseline === null => 'no_baseline',
            $settings['threshold'] === null => 'no_threshold',
            $fxStale || $fuelStale => 'stale',
            in_array($thresholdState, ['up', 'down'], true) => 'threshold_' . $thresholdState,
            default => 'within',
        };

        return [
            'as_of' => $asOf,
            'window' => $window,
            'settings' => $settings,
            'test' => $test,
            'fx' => [
                'avg' => $fx['avg'],
                'observations' => $fx['count'],
                'first' => $fx['first'],
                'last' => $fx['last'],
                'current' => $current['rate'] ?? null,
                'current_date' => $current['rate_date'] ?? null,
                'previous' => $current['previous_rate'] ?? null,
                'current_vs_avg_pct' => FuelFxCalculator::percentChange($current['rate'] ?? null, $fx['avg']),
                'age_days' => $fxAgeDays,
                'stale' => $fxStale,
                'insufficient' => $fxInsufficient,
                'last_success' => $bnrSync['last_success']['finished_at'] ?? null,
                'last_attempt' => $bnrSync['last_attempt']['started_at'] ?? null,
                'last_attempt_status' => $bnrSync['last_attempt']['status'] ?? null,
                'last_error' => $bnrSync['last_error'] !== null
                    && ($bnrSync['last_success'] === null || (int) $bnrSync['last_error']['id'] > (int) $bnrSync['last_success']['id'])
                    ? (string) $bnrSync['last_error']['message'] : null,
            ],
            'diesel' => [
                'avg_ron' => $diesel['avg'],
                'avg_eur' => $dieselEur,
                'liters' => $diesel['liters'],
                'observations' => $diesel['count'],
                'first' => $diesel['first'],
                'last' => $diesel['last'],
                'stale' => $fuelStale,
                'insufficient' => $fuelInsufficient,
                'last_success' => $fuelSync['last_success'],
                'last_attempt' => $fuelSync['last_attempt'],
                'last_attempt_status' => $fuelSync['status'],
                'method' => 'Medie ponderată cu volumul: Σ valoare ÷ Σ litri (CardOil, doar motorină, doar date API).',
            ],
            'baseline' => $baseline,
            'comparison' => $comparison,
            'variation_pct' => $variation,
            'threshold_state' => $thresholdState,
            'status' => $status,
        ];
    }

    /**
     * Compararea indicatorilor curenți cu o referință: descompunerea completă plus
     * variația folosită pentru prag / ajustare, după baza configurată.
     *
     * @return array<string,?float>|null
     */
    public function compareWithBaseline(array $baseline, ?float $dieselRon, ?float $eurRon): ?array
    {
        $parts = FuelFxCalculator::decompose(
            (float) $baseline['diesel_ron'],
            $dieselRon,
            (float) $baseline['eur_ron'],
            $eurRon
        );
        if ($parts === null) {
            return null;
        }

        $parts['variation_pct'] = $this->settings()['basis'] === 'diesel_ron'
            ? $parts['diesel_ron_pct']
            : $parts['combined_pct'];

        return $parts;
    }

    // ================================================================ serii grafice

    /** @return array<int,array{d:string, v:float}> */
    public function fxSeries(int $months): array
    {
        $window = FuelFxCalculator::rollingWindow(date('Y-m-d'), max(1, $months));

        return array_map(
            static fn (array $row): array => ['d' => $row['rate_date'], 'v' => $row['rate']],
            $this->model->getRates('EUR', $window['start'], $window['end'])
        );
    }

    /**
     * Motorina pe zile: preț ponderat al zilei în lei/l și echivalentul în EUR/l la
     * cursul BNR în vigoare în acea zi (ultimul publicat ≤ ziua — pentru grafic;
     * indicatorul principal folosește media pe fereastră, nu cursul zilnic).
     *
     * @return array<int,array{d:string, ron:float, eur:?float, l:float}>
     */
    public function dieselSeries(int $months): array
    {
        $window = FuelFxCalculator::rollingWindow(date('Y-m-d'), max(1, $months));
        $stmt = $this->db->prepare("
            SELECT DATE(fillup_datetime) AS d, SUM(total_value) AS v, SUM(quantity_liters) AS l
            FROM fuel_fillups
            WHERE fuel_type = 'motorina' AND source_type = 'api'
              AND quantity_liters > 0 AND total_value > 0
              AND fillup_datetime BETWEEN :s AND :e
            GROUP BY DATE(fillup_datetime)
            ORDER BY d ASC
        ");
        $stmt->execute(['s' => $window['start'] . ' 00:00:00', 'e' => $window['end'] . ' 23:59:59']);
        $days = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $rateStart = (new DateTimeImmutable($window['start']))->modify('-10 days')->format('Y-m-d');
        $rates = $this->model->getRates('EUR', $rateStart, $window['end']);

        $series = [];
        $index = 0;
        $rate = null;
        foreach ($days as $day) {
            while ($index < count($rates) && $rates[$index]['rate_date'] <= $day['d']) {
                $rate = $rates[$index]['rate'];
                $index++;
            }
            $ron = (float) $day['v'] / (float) $day['l'];
            $series[] = [
                'd' => (string) $day['d'],
                'ron' => round($ron, 4),
                'eur' => $rate !== null ? round($ron / $rate, 4) : null,
                'l' => round((float) $day['l'], 2),
            ];
        }

        return $series;
    }

    // ================================================================ catalog tarife

    /**
     * Toate regulile tarifare nenule, la granularitatea reală (beneficiar ×
     * componentă × rută), cu valoarea în vigoare azi.
     *
     * @return array<int,array<string,mixed>>
     */
    public function ruleCatalog(?string $date = null): array
    {
        $date ??= date('Y-m-d');
        $rules = [];

        $beneficiaries = $this->db->query('
            SELECT * FROM configurare_beneficiari_transport WHERE activ = 1 ORDER BY nume ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byId = [];
        foreach ($beneficiaries as $beneficiary) {
            $byId[(int) $beneficiary['id']] = $beneficiary;
        }

        $add = function (array $beneficiary, string $component, string $type, ?array $route, string $scope, float $legacy) use (&$rules): void {
            $routeId = $route !== null ? (int) $route['id'] : null;
            $rules[] = [
                'rule_signature' => TransportTariffModel::buildSignature((int) $beneficiary['id'], $component, $routeId),
                'beneficiar_id' => (int) $beneficiary['id'],
                'beneficiar_nume' => (string) $beneficiary['nume'],
                'transport_type' => $type,
                'transport_label' => TransportTariffModel::TRANSPORT_TYPES[$type] ?? $type,
                'component_key' => $component,
                'component_label' => TransportTariffModel::componentLabel($component),
                'unit' => TransportTariffModel::componentUnit($component),
                'route_ref_id' => $routeId,
                'route_scope' => $scope,
                'loc_incarcare_id' => $route !== null ? (int) $route['loc_incarcare_id'] : null,
                'zona_distributie_id' => $route !== null ? (int) $route['zona_distributie_id'] : null,
                'route_label' => $route !== null ? trim((string) $route['loc_nume'] . ' → ' . (string) $route['zona_nume']) : null,
                'legacy_value' => $legacy,
            ];
        };

        $beneficiaryComponents = [
            'pret_km' => ['primar', 'suporta_primar'],
            'pret_tona' => ['primar_tona', 'suporta_primar'],
            'pret_ora_aspirare' => ['compresor', 'suporta_compresor'],
            'pret_km_dislocare' => ['compresor', 'suporta_compresor'],
            'pret_tona_livrata' => ['compresor', 'suporta_compresor'],
            'pret_tona_aspirata_lichida' => ['compresor', 'suporta_compresor'],
            'pret_tona_aspirata_gazoasa' => ['compresor', 'suporta_compresor'],
        ];
        foreach ($beneficiaries as $beneficiary) {
            foreach ($beneficiaryComponents as $component => [$type, $flag]) {
                if ((int) ($beneficiary[$flag] ?? 0) !== 1) {
                    continue;
                }
                $add($beneficiary, $component, $type, null, 'none', (float) ($beneficiary[$component] ?? 0));
            }
        }

        $primaryRoutes = $this->db->query('
            SELECT r.*, l.nume AS loc_nume, z.nume AS zona_nume
            FROM configurare_rute_primar r
            INNER JOIN configurare_locuri_incarcare l ON l.id = r.loc_incarcare_id
            INNER JOIN configurare_zone_distributie z ON z.id = r.zona_distributie_id
            WHERE r.activ = 1
            ORDER BY l.nume, z.nume
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($primaryRoutes as $route) {
            $beneficiary = $byId[(int) $route['beneficiar_id']] ?? null;
            if ($beneficiary !== null) {
                $add($beneficiary, 'cost_cursa', 'primar', $route, 'primar', (float) $route['cost_cursa']);
            }
        }

        $distributionRoutes = $this->db->query('
            SELECT r.*, l.nume AS loc_nume, z.nume AS zona_nume
            FROM configurare_rute_distributie r
            INNER JOIN configurare_locuri_incarcare l ON l.id = r.loc_incarcare_id
            INNER JOIN configurare_zone_distributie z ON z.id = r.zona_distributie_id
            WHERE r.activ = 1
            ORDER BY l.nume, z.nume
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($distributionRoutes as $route) {
            $beneficiary = $byId[(int) $route['beneficiar_id']] ?? null;
            if ($beneficiary === null) {
                continue;
            }
            $scope = (string) $route['transport_scope'];
            $mode = (string) $route['tarif_mod'];
            if (in_array($mode, ['tona', 'tona_km'], true)) {
                $add($beneficiary, 'tarif_tona', $scope, $route, $scope, (float) $route['tarif_tona']);
            }
            if (in_array($mode, ['km', 'tona_km'], true)) {
                $add($beneficiary, 'cost_extra_km', $scope, $route, $scope, (float) $route['cost_extra_km']);
            }
            if ((int) ($route['aplica_cost_cursa'] ?? 0) === 1) {
                $add($beneficiary, 'cost_cursa', $scope, $route, $scope, (float) $route['cost_cursa']);
            }
        }

        // Valoarea în vigoare: versiunea care acoperă data, altfel configurarea.
        $signatures = array_column($rules, 'rule_signature');
        $versions = $this->tariffs->resolveVersionsAt($signatures, $date);
        $scheduled = $this->scheduledSignatures($signatures, $date);

        $result = [];
        foreach ($rules as $rule) {
            $version = $versions[$rule['rule_signature']] ?? null;
            $rule['version_id'] = $version !== null ? (int) $version['id'] : null;
            $rule['version_valid_from'] = $version['valid_from'] ?? null;
            $rule['value'] = $version !== null ? (float) $version['value'] : (float) $rule['legacy_value'];
            $rule['value_source'] = $version !== null ? 'version' : 'config';
            $rule['currency'] = str_starts_with((string) $rule['unit'], 'lei') ? 'RON' : 'EUR';
            $rule['scheduled_from'] = $scheduled[$rule['rule_signature']] ?? null;

            $rule['weight'] = self::FUEL_SHARE;

            if ($rule['value'] <= 0.0) {
                continue;
            }
            unset($rule['legacy_value']);
            $result[] = $rule;
        }

        return $this->assignGroups($result);
    }

    /** @return array<string,string> semnătură → prima dată programată după $date */
    private function scheduledSignatures(array $signatures, string $date): array
    {
        if ($signatures === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($signatures), '?'));
        $stmt = $this->db->prepare('
            SELECT rule_signature, MIN(valid_from) AS vf FROM transport_tariff_versions
            WHERE rule_signature IN (' . $placeholders . ') AND valid_from > ?
            GROUP BY rule_signature
        ');
        $stmt->execute(array_merge(array_values($signatures), [$date]));
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(string) $row['rule_signature']] = (string) $row['vf'];
        }

        return $map;
    }

    /**
     * Grupul din tabel. Distribuție tarif/tonă se împarte în preț mic / mare
     * per beneficiar, cu regula din Centralizator Facturare.
     */
    private function assignGroups(array $rules): array
    {
        $distRates = [];
        foreach ($rules as $rule) {
            if ($rule['transport_type'] === 'distributie' && $rule['component_key'] === 'tarif_tona') {
                $distRates[$rule['beneficiar_id']][] = (float) $rule['value'];
            }
        }
        $classes = [];
        foreach ($distRates as $beneficiaryId => $rates) {
            $classes[$beneficiaryId] = FuelFxCalculator::priceClasses($rates);
        }

        foreach ($rules as &$rule) {
            $component = $rule['component_key'];
            $rule['price_class'] = null;
            $group = match ($rule['transport_type']) {
                'primar' => $component === 'cost_cursa' ? 'primar_cursa' : 'primar_km',
                'primar_tona' => 'primar_tona',
                'primar_distributie' => match ($component) {
                    'tarif_tona' => 'pd_tona',
                    'cost_extra_km' => 'pd_km',
                    default => 'pd_cursa',
                },
                'compresor' => match ($component) {
                    'pret_ora_aspirare' => 'comp_ora',
                    'pret_km_dislocare' => 'comp_km',
                    default => 'comp_tona',
                },
                default => match ($component) {
                    'cost_extra_km' => 'dist_km',
                    'cost_cursa' => 'dist_cursa',
                    default => 'dist_tona',
                },
            };
            if ($group === 'dist_tona') {
                $class = $classes[$rule['beneficiar_id']][number_format((float) $rule['value'], 4, '.', '')] ?? 'unic';
                $rule['price_class'] = $class;
                $group = match ($class) {
                    'mic' => 'dist_mic',
                    'mare' => 'dist_mare',
                    'intermediar' => 'dist_alt',
                    default => 'dist_unic',
                };
            }
            $rule['group_key'] = $group;
            $rule['group_label'] = self::GROUPS[$group]['label'];
        }
        unset($rule);

        return $rules;
    }

    // ================================================================ simulare

    /**
     * Simularea fiecărei reguli față de referința ei (proprie sau implicită).
     *
     * @return array{rules:array<int,array<string,mixed>>, groups:array<int,array<string,mixed>>, summary:array<string,mixed>}
     */
    public function simulate(array $indicators, array $rules): array
    {
        $settings = $indicators['settings'];
        // În test toate tarifele se compară cu referința de test.
        $ruleBaselines = $this->isTestMode() ? [] : $this->model->getRuleBaselineMap();
        $default = $indicators['baseline'];
        $baselineCache = [];
        $dataBlocked = in_array($indicators['status'], ['unavailable', 'insufficient'], true);
        $stale = $indicators['fx']['stale'] || $indicators['diesel']['stale'];
        $f1 = $indicators['diesel']['avg_ron'];
        $e1 = $indicators['fx']['avg'];

        foreach ($rules as &$rule) {
            $baselineId = $ruleBaselines[$rule['rule_signature']] ?? null;
            if ($baselineId !== null) {
                $baselineCache[$baselineId] ??= $this->model->getBaseline($baselineId);
                $baseline = $baselineCache[$baselineId];
            } else {
                $baseline = $default;
            }

            $comparison = $baseline !== null ? $this->compareWithBaseline($baseline, $f1, $e1) : null;
            $variation = $comparison['variation_pct'] ?? null;
            $state = FuelFxCalculator::thresholdState($variation, $settings['threshold']);

            $weight = (float) $rule['weight'];
            $proposed = in_array($state, ['up', 'down'], true)
                ? FuelFxCalculator::adjustTariff((float) $rule['value'], $variation, $weight)
                : null;

            $status = match (true) {
                $dataBlocked => 'insufficient',
                $baseline === null => 'no_baseline',
                $state === 'within' || $state === null => 'within',
                $stale => 'stale',
                $rule['scheduled_from'] !== null => 'scheduled',
                default => 'simulated',
            };

            $rule['baseline_id'] = $baseline !== null ? (int) $baseline['id'] : null;
            $rule['baseline_label'] = $baseline['label'] ?? null;
            $rule['reference'] = $baseline !== null ? [
                'diesel_ron' => (float) $baseline['diesel_ron'],
                'eur_ron' => (float) $baseline['eur_ron'],
                'diesel_eur' => (float) $baseline['diesel_eur'],
                'window' => $baseline['window_start'] . ' – ' . $baseline['window_end'],
            ] : null;
            $rule['comparison'] = $comparison;
            $rule['variation_pct'] = $variation;
            $rule['threshold_state'] = $state;
            $rule['applied_weight'] = $weight;
            $rule['proposed'] = $proposed;
            $rule['change_pct'] = $proposed !== null ? FuelFxCalculator::percentChange($proposed, (float) $rule['value']) : null;
            $rule['status'] = $status;
            $rule['status_label'] = self::STATUS_LABELS[$status];
            $rule['eligible'] = $status === 'simulated' && !$this->isTestMode();
            $rule['formula'] = $variation !== null
                ? sprintf('%s × (1 %s %s%%)', $this->num((float) $rule['value'], 4), $variation < 0 ? '−' : '+', $this->num(abs($variation), 4))
                : null;
        }
        unset($rule);

        return ['rules' => $rules, 'groups' => $this->groupRows($rules), 'summary' => $this->summary($rules)];
    }

    /** @return array<int,array<string,mixed>> */
    private function groupRows(array $rules): array
    {
        $priority = ['simulated' => 1, 'scheduled' => 2, 'stale' => 3, 'within' => 4, 'no_baseline' => 5, 'insufficient' => 6];
        $groups = [];
        foreach ($rules as $rule) {
            $key = $rule['group_key'];
            $groups[$key] ??= [
                'key' => $key,
                'label' => $rule['group_label'],
                'order' => self::GROUPS[$key]['order'],
                'units' => [],
                'values' => [],
                'proposed' => [],
                'changes' => [],
                'count' => 0,
                'eligible' => 0,
                'beneficiaries' => [],
                'statuses' => [],
            ];
            $group = &$groups[$key];
            $group['units'][$rule['unit']] = true;
            $group['values'][] = (float) $rule['value'];
            if ($rule['proposed'] !== null) {
                $group['proposed'][] = (float) $rule['proposed'];
                $group['changes'][] = (float) $rule['change_pct'];
            }
            $group['count']++;
            $group['eligible'] += $rule['eligible'] ? 1 : 0;
            $group['beneficiaries'][$rule['beneficiar_nume']] = true;
            $group['statuses'][$rule['status']] = ($group['statuses'][$rule['status']] ?? 0) + 1;
            unset($group);
        }

        $rows = [];
        foreach ($groups as $group) {
            $statuses = array_keys($group['statuses']);
            usort($statuses, static fn (string $a, string $b): int => $priority[$a] <=> $priority[$b]);
            $rows[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'order' => $group['order'],
                'unit' => implode(', ', array_keys($group['units'])),
                'count' => $group['count'],
                'eligible' => $group['eligible'],
                'beneficiaries' => array_keys($group['beneficiaries']),
                'value_min' => min($group['values']),
                'value_max' => max($group['values']),
                'proposed_min' => $group['proposed'] !== [] ? min($group['proposed']) : null,
                'proposed_max' => $group['proposed'] !== [] ? max($group['proposed']) : null,
                'change_pct' => FuelFxCalculator::average($group['changes']),
                'status' => $statuses[0],
                'status_label' => self::STATUS_LABELS[$statuses[0]],
                'statuses' => $group['statuses'],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $rows;
    }

    /** @return array<string,mixed> */
    private function summary(array $rules): array
    {
        $changes = [];
        $counts = [];
        foreach ($rules as $rule) {
            $counts[$rule['status']] = ($counts[$rule['status']] ?? 0) + 1;
            if ($rule['change_pct'] !== null) {
                $changes[] = $rule['change_pct'];
            }
        }

        return [
            'total' => count($rules),
            'eligible' => $counts['simulated'] ?? 0,
            'counts' => $counts,
            'avg_change_pct' => FuelFxCalculator::average($changes),
        ];
    }

    /** Pachetul complet al paginii (indicatori + simulare). */
    public function build(): array
    {
        $indicators = $this->indicators();
        $simulation = $this->simulate($indicators, $this->ruleCatalog());

        return ['indicators' => $indicators, 'simulation' => $simulation];
    }

    // ================================================================ instantanee

    public function snapshotHash(array $indicators, array $simulation): string
    {
        $rules = array_map(static fn (array $r): array => [
            $r['rule_signature'], $r['value'], $r['applied_weight'], $r['baseline_id'], $r['proposed'], $r['status'],
        ], $simulation['rules']);

        return hash('sha256', (string) json_encode([
            $indicators['window'],
            $indicators['fx']['avg'], $indicators['fx']['observations'], $indicators['fx']['current_date'],
            $indicators['diesel']['avg_ron'], $indicators['diesel']['liters'], $indicators['diesel']['last'],
            $indicators['baseline']['id'] ?? null,
            $indicators['settings'],
            $rules,
        ]));
    }

    /**
     * Instantaneul zilnic automat: nu se dublează pentru aceleași intrări
     * (după hash), iar cele vechi se curăță după AUTO_SNAPSHOT_RETENTION_DAYS.
     *
     * @return array{id:int, created:bool, purged:int}
     */
    public function recordAutoSnapshot(): array
    {
        $this->refuseInTestMode();
        $data = $this->build();
        $stored = $this->model->storeSnapshot(
            'auto',
            $this->snapshotHash($data['indicators'], $data['simulation']),
            $data['indicators']['baseline']['id'] ?? null,
            $this->compactIndicators($data['indicators']),
            $this->compactSimulation($data['simulation']),
            null,
            null
        );

        return $stored + ['purged' => $this->model->purgeOldAutoSnapshots()];
    }

    /** @return array{id:int, created:bool} */
    public function saveSnapshot(?string $note, ?int $userId): array
    {
        $this->refuseInTestMode();
        $data = $this->build();

        return $this->model->storeSnapshot(
            'saved',
            $this->snapshotHash($data['indicators'], $data['simulation']),
            $data['indicators']['baseline']['id'] ?? null,
            $this->compactIndicators($data['indicators']),
            $this->compactSimulation($data['simulation']),
            $note,
            $userId
        );
    }

    private function compactIndicators(array $indicators): array
    {
        return [
            'as_of' => $indicators['as_of'],
            'window' => $indicators['window'],
            'eur_ron_avg' => $indicators['fx']['avg'],
            'eur_ron_observations' => $indicators['fx']['observations'],
            'eur_ron_current' => $indicators['fx']['current'],
            'eur_ron_current_date' => $indicators['fx']['current_date'],
            'diesel_ron_avg' => $indicators['diesel']['avg_ron'],
            'diesel_eur_avg' => $indicators['diesel']['avg_eur'],
            'diesel_liters' => $indicators['diesel']['liters'],
            'diesel_last_observation' => $indicators['diesel']['last'],
            'baseline_id' => $indicators['baseline']['id'] ?? null,
            'comparison' => $indicators['comparison'],
            'variation_pct' => $indicators['variation_pct'],
            'threshold' => $indicators['settings']['threshold'],
            'basis' => $indicators['settings']['basis'],
            'status' => $indicators['status'],
        ];
    }

    private function compactSimulation(array $simulation): array
    {
        return array_map(static fn (array $r): array => [
            'sig' => $r['rule_signature'],
            'beneficiar' => $r['beneficiar_nume'],
            'grup' => $r['group_label'],
            'unit' => $r['unit'],
            'value' => $r['value'],
            'proposed' => $r['proposed'],
            'weight' => $r['applied_weight'],
            'variation_pct' => $r['variation_pct'],
            'baseline_id' => $r['baseline_id'],
            'status' => $r['status'],
        ], $simulation['rules']);
    }

    // ================================================================ referințe

    /**
     * Salvează o referință din fereastra de N luni care se termină la $endDate.
     * Refuză o referință fără date suficiente — nu se inventează valori.
     */
    public function createBaselineFromWindow(string $endDate, string $label, ?string $note, ?int $userId, bool $makeDefault): int
    {
        $this->refuseInTestMode();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) !== 1 || $endDate > date('Y-m-d')) {
            throw new RuntimeException('Data de sfârșit a ferestrei de referință este invalidă (nu poate fi în viitor).');
        }
        $window = FuelFxCalculator::rollingWindow($endDate, $this->settings()['months']);

        return $this->createBaselineFromRange($window['start'], $window['end'], $label, $note, $userId, $makeDefault);
    }

    /** Referință salvată dintr-un interval explicit (ex. luni calendaristice din calculator). */
    public function createBaselineFromRange(string $start, string $end, string $label, ?string $note, ?int $userId, bool $makeDefault): int
    {
        $this->refuseInTestMode();
        if (!$this->validDate($start) || !$this->validDate($end) || $start > $end || $end > date('Y-m-d')) {
            throw new RuntimeException('Intervalul de referință este invalid (nu poate fi în viitor).');
        }
        $settings = $this->settings();
        // Pe intervale scurte (o lună) BNR publică ~21 de cursuri; cerința minimă se scalează.
        $days = (int) (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days + 1;
        $settings['fx_min_observations'] = max(1, min($settings['fx_min_observations'], (int) floor($days * 0.55)));
        $window = ['start' => $start, 'end' => $end];
        $fx = $this->fxWindowStats($window['start'], $window['end']);
        $diesel = $this->dieselWindowStats($window['start'], $window['end']);

        if ($fx['avg'] === null || $fx['count'] < $settings['fx_min_observations']) {
            throw new RuntimeException(sprintf(
                'Prea puține cursuri BNR în fereastra %s – %s (%d, minim %d). Rulează sincronizarea BNR.',
                $window['start'], $window['end'], $fx['count'], $settings['fx_min_observations']
            ));
        }
        if ($diesel['avg'] === null || $diesel['count'] < $settings['fuel_min_observations'] || $diesel['liters'] < $settings['fuel_min_liters']) {
            throw new RuntimeException(sprintf(
                'Date de motorină insuficiente în fereastra %s – %s (%d alimentări, %s l).',
                $window['start'], $window['end'], $diesel['count'], $this->num($diesel['liters'], 0)
            ));
        }

        $label = trim($label) !== '' ? trim($label) : 'Referință ' . $window['start'] . ' – ' . $window['end'];
        $id = $this->model->createBaseline([
            'label' => $label,
            'window_start' => $window['start'],
            'window_end' => $window['end'],
            'diesel_ron' => $diesel['avg'],
            'eur_ron' => $fx['avg'],
            'diesel_eur' => $diesel['avg'] / $fx['avg'],
            'fx_observations' => $fx['count'],
            'fuel_observations' => $diesel['count'],
            'fuel_liters' => $diesel['liters'],
            'source' => 'manual',
            'note' => $note,
            'created_by' => $userId,
        ]);

        if ($makeDefault || $this->model->getDefaultBaseline() === null) {
            $this->model->setDefaultBaseline($id, array_column($this->ruleCatalog(), 'rule_signature'));
        }

        return $id;
    }

    // ================================================================ calculator perioade

    /**
     * Intervalul de N luni calendaristice care se termină cu luna $month ('YYYY-MM'):
     * 2026-10, 3 → 01.07.2026 – 31.10.2026. Luna curentă se oprește la azi.
     *
     * @return array{start:string, end:string, months:int, label:string}
     */
    public static function calendarPeriod(string $month, int $length): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1 || !in_array($length, [1, 3], true)) {
            throw new RuntimeException('Perioadă invalidă.');
        }
        $last = new DateTimeImmutable($month . '-01');
        $first = $last->modify('-' . ($length - 1) . ' months');
        $end = min($last->format('Y-m-t'), date('Y-m-d'));
        $names = ['', 'ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'noi', 'dec'];
        $label = $length === 1
            ? $names[(int) $last->format('n')] . ' ' . $last->format('Y')
            : $names[(int) $first->format('n')] . ' ' . $first->format('Y') . ' – ' . $names[(int) $last->format('n')] . ' ' . $last->format('Y');

        return ['start' => $first->format('Y-m-d'), 'end' => $end, 'months' => $length, 'label' => $label];
    }

    /**
     * Calculatorul de comparație: perioada comparată (A) față de perioada de
     * referință (B), fiecare de 1 sau 3 luni calendaristice. Folosește aceleași
     * surse și formule ca restul paginii (în modul test, prețurile de test).
     *
     * @return array<string,mixed>
     */
    public function comparePeriods(string $monthA, int $lengthA, string $monthB, int $lengthB): array
    {
        $settings = $this->settings();
        $describe = function (array $period): array {
            $fx = $this->fxWindowStats($period['start'], $period['end']);
            $diesel = $this->dieselWindowStats($period['start'], $period['end']);

            return $period + [
                'diesel_ron' => $diesel['avg'],
                'eur_ron' => $fx['avg'],
                'diesel_eur' => FuelFxCalculator::dieselEur($diesel['avg'], $fx['avg']),
                'fx_observations' => $fx['count'],
                'liters' => $diesel['liters'],
                'fillups' => $diesel['count'],
                'breakdown' => $diesel['breakdown'] ?? null,
            ];
        };

        $a = $describe(self::calendarPeriod($monthA, $lengthA));
        $b = $describe(self::calendarPeriod($monthB, $lengthB));
        $parts = FuelFxCalculator::decompose($b['diesel_ron'], $a['diesel_ron'], $b['eur_ron'], $a['eur_ron']);
        $variation = $parts === null ? null
            : ($settings['basis'] === 'diesel_ron' ? $parts['diesel_ron_pct'] : $parts['combined_pct']);
        $state = FuelFxCalculator::thresholdState($variation, $settings['threshold']);

        return [
            'compared' => $a,
            'reference' => $b,
            'decomposition' => $parts,
            'variation_pct' => $variation,
            'basis' => $settings['basis'],
            'threshold' => $settings['threshold'],
            'threshold_state' => $state,
            'tariff_change_pct' => in_array($state, ['up', 'down'], true) ? $variation * self::FUEL_SHARE : 0.0,
            'test_mode' => $this->isTestMode(),
        ];
    }

    // ================================================================ aprobare

    /**
     * Aplicarea manuală. Recalculează totul pe server (valorile din browser nu sunt
     * de încredere), verifică eligibilitatea fiecărei reguli, apoi într-o singură
     * tranzacție: referință nouă din indicatorii curenți → versiuni noi de tarif →
     * istoric → elemente de aprobare. Un lock MariaDB serializează aprobările.
     *
     * @param array<string,float> $expected semnătură → tariful propus afișat utilizatorului
     * @return array{approval_id:int, items:int, beneficiaries:array<int,int>}
     */
    public function applyApproval(array $expected, string $effectiveFrom, ?string $note, ?int $userId, string $userName, bool $setNewDefault): array
    {
        $this->refuseInTestMode();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) !== 1 || !$this->validDate($effectiveFrom)) {
            throw new RuntimeException('Data intrării în vigoare este invalidă.');
        }
        if ($effectiveFrom <= date('Y-m-d')) {
            throw new RuntimeException('Data intrării în vigoare trebuie să fie în viitor — tarifele nu se aplică retroactiv.');
        }
        if ($expected === []) {
            throw new RuntimeException('Nu ai selectat niciun tarif.');
        }
        $note = $note !== null ? trim($note) : null;
        if ($note !== null && mb_strlen($note) > 250) {
            throw new RuntimeException('Nota poate avea cel mult 250 de caractere.');
        }

        if ((int) $this->db->query("SELECT GET_LOCK('fleet_fx_tariff_apply', 10)")->fetchColumn() !== 1) {
            throw new RuntimeException('O altă aplicare de tarife este în curs. Încearcă din nou peste câteva secunde.');
        }

        try {
            $data = $this->build();
            $indicators = $data['indicators'];
            $bySignature = [];
            foreach ($data['simulation']['rules'] as $rule) {
                $bySignature[$rule['rule_signature']] = $rule;
            }

            $selected = [];
            foreach ($expected as $signature => $shownProposed) {
                $rule = $bySignature[$signature] ?? null;
                if ($rule === null) {
                    throw new RuntimeException('Tariful ' . $signature . ' nu mai există.');
                }
                if (!$rule['eligible']) {
                    throw new RuntimeException(sprintf(
                        '%s · %s nu este eligibil pentru aplicare (%s).',
                        $rule['beneficiar_nume'], $rule['group_label'], $rule['status_label']
                    ));
                }
                if (abs((float) $rule['proposed'] - (float) $shownProposed) > 0.00005) {
                    throw new RuntimeException('Datele s-au actualizat între timp (tariful propus pentru '
                        . $rule['beneficiar_nume'] . ' s-a schimbat). Reîncarcă pagina și verifică din nou.');
                }
                $later = $this->db->prepare('SELECT COUNT(*) FROM transport_tariff_versions WHERE rule_signature = :s AND valid_from >= :d');
                $later->execute(['s' => $signature, 'd' => $effectiveFrom]);
                if ((int) $later->fetchColumn() > 0) {
                    throw new RuntimeException('Pentru ' . $rule['beneficiar_nume'] . ' · ' . $rule['group_label']
                        . ' există deja o versiune care începe la sau după ' . $effectiveFrom . '.');
                }
                $selected[] = $rule;
            }

            $reviews = new TariffReviewService($this->db, $this->tariffs);
            $fuelReference = $reviews->captureReferenceForNewVersion($effectiveFrom);
            $allSignatures = array_keys($bySignature);
            $now = date('Y-m-d H:i:s');

            // Se alătură unei tranzacții deja deschise (ca TariffRepriceService).
            $ownTransaction = !$this->db->inTransaction();
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }
            try {
                $newBaselineId = $this->model->createBaseline([
                    'label' => 'Aprobare ' . date('d.m.Y') . ' (în vigoare ' . $effectiveFrom . ')',
                    'window_start' => $indicators['window']['start'],
                    'window_end' => $indicators['window']['end'],
                    'diesel_ron' => $indicators['diesel']['avg_ron'],
                    'eur_ron' => $indicators['fx']['avg'],
                    'diesel_eur' => $indicators['diesel']['avg_eur'],
                    'fx_observations' => $indicators['fx']['observations'],
                    'fuel_observations' => $indicators['diesel']['observations'],
                    'fuel_liters' => $indicators['diesel']['liters'],
                    'source' => 'approval',
                    'note' => $note,
                    'created_by' => $userId,
                ]);

                $baseline = $indicators['baseline'];
                $approvalId = $this->model->createApproval([
                    'approved_by' => $userId,
                    'approved_by_name' => $userName,
                    'effective_from' => $effectiveFrom,
                    'note' => $note,
                    'baseline_id' => $baseline['id'] ?? null,
                    'new_baseline_id' => $newBaselineId,
                    'snapshot_id' => null,
                    'adjust_basis' => $indicators['settings']['basis'],
                    'threshold_percent' => $indicators['settings']['threshold'],
                    'variation_percent' => $indicators['variation_pct'],
                    'reference_diesel_ron' => $baseline['diesel_ron'] ?? null,
                    'reference_eur_ron' => $baseline['eur_ron'] ?? null,
                    'current_diesel_ron' => $indicators['diesel']['avg_ron'],
                    'current_eur_ron' => $indicators['fx']['avg'],
                    'items_count' => 0,
                    'indicators' => $this->compactIndicators($indicators),
                ]);

                $changes = [];
                $beneficiaries = [];
                foreach ($selected as $rule) {
                    $created = $this->tariffs->createVersion([
                        'beneficiar_id' => $rule['beneficiar_id'],
                        'transport_type' => $rule['transport_type'],
                        'component_key' => $rule['component_key'],
                        'route_scope' => $rule['route_scope'],
                        'route_ref_id' => $rule['route_ref_id'],
                        'loc_incarcare_id' => $rule['loc_incarcare_id'],
                        'zona_distributie_id' => $rule['zona_distributie_id'],
                        'value' => (float) $rule['proposed'],
                        'valid_from' => $effectiveFrom,
                        'valid_to' => null,
                        'fuel_weight' => $rule['applied_weight'],
                        'reference_fuel_price' => $fuelReference['price'],
                        'reference_captured_at' => $fuelReference['price'] !== null ? $fuelReference['captured_at'] : null,
                        'reason' => mb_substr('Ajustare combustibil & curs BNR #' . $approvalId . ($note ? ': ' . $note : ''), 0, 255),
                        'created_by' => $userId,
                    ]);

                    $this->tariffs->recordHistory([
                        'tariff_version_id' => $created['version_id'],
                        'rule_signature' => $rule['rule_signature'],
                        'beneficiar_id' => $rule['beneficiar_id'],
                        'transport_type' => $rule['transport_type'],
                        'component_key' => $rule['component_key'],
                        'route_ref_id' => $rule['route_ref_id'],
                        'route_label' => $rule['route_label'],
                        'action' => 'scheduled',
                        'old_value' => $rule['value'],
                        'new_value' => $rule['proposed'],
                        'unit' => $rule['unit'],
                        'effective_from' => $effectiveFrom,
                        'effective_to' => null,
                        'reference_fuel_price' => $rule['reference']['diesel_ron'] ?? null,
                        'observed_fuel_price' => $indicators['diesel']['avg_ron'],
                        'fuel_variation_percent' => $rule['variation_pct'] !== null ? round((float) $rule['variation_pct'], 4) : null,
                        'fuel_liters_analysed' => $indicators['diesel']['liters'],
                        'fuel_period_start' => $indicators['window']['start'],
                        'fuel_period_end' => $indicators['window']['end'],
                        'reason' => mb_substr('Ajustare combustibil & curs BNR #' . $approvalId . ($note ? ': ' . $note : ''), 0, 255),
                        'changed_by' => $userId,
                        'changed_by_name' => $userName,
                        'changed_at' => $now,
                    ]);

                    if ($rule['version_id'] !== null) {
                        $this->tariffs->markReviewResolved((int) $rule['version_id'], 'REVIEWED', $userId);
                    }

                    $this->model->addApprovalItem($approvalId, [
                        'rule_signature' => $rule['rule_signature'],
                        'beneficiar_id' => $rule['beneficiar_id'],
                        'beneficiar_nume' => $rule['beneficiar_nume'],
                        'transport_type' => $rule['transport_type'],
                        'component_key' => $rule['component_key'],
                        'route_ref_id' => $rule['route_ref_id'],
                        'route_label' => $rule['route_label'],
                        'group_label' => $rule['group_label'],
                        'unit' => $rule['unit'],
                        'old_value' => $rule['value'],
                        'new_value' => $rule['proposed'],
                        'change_percent' => $rule['change_pct'],
                        'fuel_weight' => $rule['applied_weight'],
                        'variation_percent' => $rule['variation_pct'],
                        'baseline_id' => $rule['baseline_id'],
                        'old_version_id' => $created['previous_id'],
                        'new_version_id' => $created['version_id'],
                    ]);

                    $changes[] = (float) $rule['change_pct'];
                    $beneficiaries[$rule['beneficiar_id']] = $rule['beneficiar_id'];
                }

                // Noua bază de comparație pentru regulile aprobate; celelalte își
                // păstrează referința (fixată înainte dacă se mută cea implicită).
                if ($setNewDefault) {
                    $this->model->setDefaultBaseline($newBaselineId, $allSignatures, true);
                }
                foreach ($selected as $rule) {
                    $this->model->assignRuleBaseline($rule['rule_signature'], $newBaselineId, null);
                }

                $this->model->updateApprovalTotals($approvalId, count($selected), FuelFxCalculator::average($changes));
                if ($ownTransaction) {
                    $this->db->commit();
                }
            } catch (Throwable $exception) {
                if ($ownTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $exception;
            }

            return ['approval_id' => $approvalId, 'items' => count($selected), 'beneficiaries' => array_values($beneficiaries)];
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('fleet_fx_tariff_apply')");
        }
    }

    /** Comparația indicatorilor curenți cu cei de la ultima aprobare. */
    public function compareWithLastApproval(): ?array
    {
        $approvals = $this->model->getApprovals(1);
        if ($approvals === []) {
            return null;
        }
        $last = $approvals[0];
        $indicators = $this->indicators();
        $dieselThen = $last['current_diesel_ron'] !== null ? (float) $last['current_diesel_ron'] : null;
        $fxThen = $last['current_eur_ron'] !== null ? (float) $last['current_eur_ron'] : null;

        return [
            'approval' => $last,
            'then' => [
                'diesel_ron' => $dieselThen,
                'eur_ron' => $fxThen,
                'diesel_eur' => FuelFxCalculator::dieselEur($dieselThen, $fxThen),
            ],
            'now' => [
                'diesel_ron' => $indicators['diesel']['avg_ron'],
                'eur_ron' => $indicators['fx']['avg'],
                'diesel_eur' => $indicators['diesel']['avg_eur'],
            ],
            'decomposition' => FuelFxCalculator::decompose($dieselThen, $indicators['diesel']['avg_ron'], $fxThen, $indicators['fx']['avg']),
        ];
    }

    /** Nimic calculat cu prețuri de test nu se salvează și nu se aplică. */
    private function refuseInTestMode(): void
    {
        if ($this->isTestMode()) {
            throw new RuntimeException('Operația nu este permisă în modul test.');
        }
    }

    private function validDate(string $date): bool
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));

        return checkdate($m, $d, $y);
    }

    private function num(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }
}
