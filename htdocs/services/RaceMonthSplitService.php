<?php

declare(strict_types=1);

/**
 * Impartirea automata pe luni a curselor care trec peste miezul noptii de la
 * sfarsitul lunii (ex. B 652 NET: 31.08 10:36 -> 01.09 19:56).
 *
 * Cursa primeste faze care se opresc la granita de luna: faza 1 = 31.08 10:36 ->
 * 23:59:59, faza 2 = 01.09 00:00 -> 19:56. Km-ii cursei raman aceiasi (deci si
 * tariful): GPS-ul SAS da doar PONDEREA fiecarei faze (km parcursi in intervalul
 * ei); fara GPS, ponderea este durata fazei. Cantitatea incarcata merge pe prima
 * faza, tonele livrate si clientii pe ultima, orele de functionare dupa pondere.
 *
 * Fazele operatorului (Reia cursa) care trec peste granita se taie si ele. Fazele
 * automate se refac la fiecare salvare (cursa sau faze), din valorile cursei; daca
 * operatorul corecteaza o faza, toate devin ale lui (auto_luna = 0).
 *
 * Folosit de Dashboard Analitic V2 (BillingMonthRule::periodTripsSql cu $byPhases):
 * fiecare luna primeste partea fazelor ei. Centralizatorul ramane pe regula de facturare.
 */
final class RaceMonthSplitService
{
    public const AUTO_NONE = 0;
    public const AUTO_CONTINUATION = 1;
    public const AUTO_BASE = 2;

    private const NOTE_PREFIX = 'Impartire automata pe luni';

    /** @var array<int, int>|null vehicul local => vehicul SAS */
    private ?array $sasCars = null;
    private ?SasDashboardService $sas = null;
    private bool $sasUnavailable = false;

    public function __construct(
        private PDO $db,
        private DispecerCurseModel $model,
        private bool $useGps = true
    ) {
    }

    /**
     * Reface fazele lunare ale cursei. Intoarce null daca nu s-a schimbat nimic,
     * altfel planul scris (fazele, cu sursa ponderii).
     *
     * @return array{segments: list<array<string, mixed>>, source: string}|null
     */
    public function sync(int $raceId, ?int $userId = null, bool $dryRun = false): ?array
    {
        $race = $this->model->getRaceById($raceId);
        if ($race === null || !empty($race['deleted_at'])) {
            return null;
        }

        $current = $this->model->getRaceSegments($raceId);
        $plan = $this->plan($race, $current);
        if ($plan === null) {
            return null;
        }
        if (!$dryRun) {
            $this->model->applyMonthSplitPlan($raceId, $plan['segments'], $userId);
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $race
     * @param list<array<string, mixed>> $current
     * @return array{segments: list<array<string, mixed>>, source: string}|null
     */
    public function plan(array $race, array $current): ?array
    {
        $hasAuto = false;
        foreach ($current as $segment) {
            $hasAuto = $hasAuto || (int) ($segment['auto_luna'] ?? 0) > 0;
        }

        // 1. Fazele de dinainte de impartire: continuarile se lipesc la faza dinainte.
        $units = [];
        foreach ($current as $segment) {
            if ((int) ($segment['auto_luna'] ?? 0) === self::AUTO_CONTINUATION && $units !== []) {
                $units[count($units) - 1] = $this->merge($units[count($units) - 1], $segment);
                continue;
            }
            $units[] = $segment;
        }
        // Cursa fara faze proprii: valorile ei (actuale, poate editate) sunt baza.
        if ($units === [] || (count($units) === 1 && (int) ($units[0]['auto_luna'] ?? 0) === self::AUTO_BASE)) {
            $units = [$this->raceAsSegment($race)];
        }

        // 2. Intervalele dorite.
        $pieces = [];
        $splits = false;
        foreach ($units as $unit) {
            $intervals = $this->monthIntervals($unit);
            $splits = $splits || count($intervals) > 1;
            $pieces[] = ['unit' => $unit, 'intervals' => $intervals];
        }
        if (!$splits) {
            // Nimic de impartit: doar fazele automate vechi (daca exista) dispar.
            if (!$hasAuto) {
                return null;
            }
            // Faza-baza (cursa insasi) ramane faza cat timp cursa are si faze ale operatorului.
            $segments = array_values(array_filter($units, static fn (array $unit): bool => (int) ($unit['id'] ?? 0) > 0));

            return ['segments' => count($segments) > 1 ? $segments : [], 'source' => 'none'];
        }

        // Aceleasi intervale ca acum: ponderile raman cele deja calculate (fara GPS din nou).
        $reuse = $hasAuto ? $this->currentWeights($pieces, $current) : [];

        // 3. Bucatile, cu km-ii impartiti dupa GPS (sau durata).
        $segments = [];
        $sources = [];
        foreach ($pieces as $unitIndex => $piece) {
            $unit = $piece['unit'];
            $intervals = $piece['intervals'];
            if (count($intervals) === 1) {
                $segments[] = $unit;
                continue;
            }
            [$weights, $source, $gpsKm] = $reuse[$unitIndex] ?? $this->weights((int) ($unit['vehicle_id'] ?? 0), $intervals);
            $sources[] = $source;
            $km = $this->splitInt($unit['km'] ?? null, $weights);
            $hours = $this->splitDecimal($unit['ore_functionare'] ?? null, $weights);
            $last = count($intervals) - 1;
            $isBase = (int) ($unit['id'] ?? 0) <= 0 || (int) ($unit['auto_luna'] ?? 0) === self::AUTO_BASE;
            foreach ($intervals as $index => [$start, $end]) {
                $note = is_string($gpsKm[$index] ?? null) ? $gpsKm[$index] : self::NOTE_PREFIX . ' - ' . ($source === 'gps'
                    ? 'km dupa GPS SAS (' . number_format((float) $gpsKm[$index], 1, ',', '.') . ' km in interval)'
                    : 'km estimati dupa durata (fara GPS)');
                $segment = $unit;
                $segment['data_inceput'] = substr($start, 0, 10);
                $segment['ora_inceput'] = substr($start, 11, 8);
                $segment['data_sfarsit'] = substr($end, 0, 10);
                $segment['ora_sfarsit'] = substr($end, 11, 8);
                // Capetele cursei raman cum le-a scris operatorul (si fara ora): fazele
                // dau inapoi cursei inceputul primei si sfarsitul ultimei.
                if ($index === 0) {
                    $segment['ora_inceput'] = $unit['ora_inceput'] ?? null;
                }
                if ($index === $last) {
                    $segment['ora_sfarsit'] = $unit['ora_sfarsit'] ?? null;
                }
                $segment['km'] = $km[$index];
                $segment['ore_functionare'] = $hours[$index];
                $segment['cantitate_incarcata'] = $index === 0 ? ($unit['cantitate_incarcata'] ?? null) : null;
                $segment['tona_livrata'] = $index === $last ? ($unit['tona_livrata'] ?? null) : null;
                $segment['nr_clienti'] = $index === $last ? ($unit['nr_clienti'] ?? null) : null;
                if ($index === 0) {
                    // Faza operatorului ramane a lui (cu observatiile ei); cursa insasi devine faza „baza”.
                    $segment['auto_luna'] = $isBase ? self::AUTO_BASE : self::AUTO_NONE;
                    if ($isBase) {
                        $segment['id'] = 0;
                        $segment['observatii'] = $note;
                    }
                } else {
                    $segment['id'] = 0;
                    $segment['auto_luna'] = self::AUTO_CONTINUATION;
                    $segment['observatii'] = $note;
                }
                $segments[] = $segment;
            }
        }

        if ($hasAuto && $this->samePlan($segments, $current)) {
            return null;
        }

        return ['segments' => $segments, 'source' => in_array('durata', $sources, true) ? 'durata' : 'gps'];
    }

    // ------------------------------------------------------------------ intervale

    /**
     * Intervalele [inceput, sfarsit] ale fazei taiate la granitele de luna.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function monthIntervals(array $unit): array
    {
        $startDate = trim((string) ($unit['data_inceput'] ?? ''));
        $endDate = trim((string) ($unit['data_sfarsit'] ?? ''));
        if ($startDate === '' || $endDate === '') {
            return [['', '']];
        }
        $start = substr($startDate, 0, 10) . ' ' . $this->time($unit['ora_inceput'] ?? null, '00:00:00');
        $end = substr($endDate, 0, 10) . ' ' . $this->time($unit['ora_sfarsit'] ?? null, '23:59:59');
        // Fara ore nu stim cat din cursa a fost in fiecare luna (si faze fara ora ar da
        // cursei o durata / diurna pe care nu o avea): se imparte cand se completeaza orele.
        if ($end <= $start || $this->time($unit['ora_inceput'] ?? null, '') === '' || $this->time($unit['ora_sfarsit'] ?? null, '') === '') {
            return [[$start, $end]];
        }

        $intervals = [];
        $cursor = $start;
        while (true) {
            $nextMonth = (new DateTimeImmutable(substr($cursor, 0, 10)))->modify('first day of next month')->format('Y-m-d') . ' 00:00:00';
            // Sfarsitul exact la miezul noptii nu deschide o faza goala in luna urmatoare.
            if ($end <= $nextMonth) {
                $intervals[] = [$cursor, $end];
                break;
            }
            $intervals[] = [$cursor, (new DateTimeImmutable($nextMonth))->modify('-1 day')->format('Y-m-d') . ' 23:59:59'];
            $cursor = $nextMonth;
        }

        return $intervals;
    }

    private function time(mixed $value, string $fallback): string
    {
        $value = trim((string) ($value ?? ''));
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $match) !== 1) {
            return $fallback;
        }

        return sprintf('%02d:%02d:%02d', (int) $match[1], (int) $match[2], (int) ($match[3] ?? 0));
    }

    private function seconds(string $start, string $end): float
    {
        $from = strtotime($start);
        $to = strtotime($end);
        if ($from === false || $to === false || $to <= $from) {
            return 0.0;
        }

        return (float) ($to - $from + (substr($end, 11, 8) === '23:59:59' ? 1 : 0));
    }

    // ------------------------------------------------------------------ ponderi

    /**
     * Ponderea fiecarui interval: km GPS SAS cand toate intervalele au raspuns si
     * suma e pozitiva, altfel durata.
     *
     * @param list<array{0: string, 1: string}> $intervals
     * @return array{0: list<float>, 1: string, 2: list<float>}
     */
    private function weights(int $vehicleId, array $intervals): array
    {
        $gps = $this->gpsKm($vehicleId, $intervals);
        if ($gps !== null && array_sum($gps) > 0) {
            return [$gps, 'gps', $gps];
        }

        $durations = array_map(fn (array $interval): float => $this->seconds($interval[0], $interval[1]), $intervals);
        if (array_sum($durations) <= 0) {
            $durations = array_fill(0, count($intervals), 1.0);
        }

        return [$durations, 'durata', array_fill(0, count($intervals), 0.0)];
    }

    /**
     * @param list<array{0: string, 1: string}> $intervals
     * @return list<float>|null
     */
    private function gpsKm(int $vehicleId, array $intervals): ?array
    {
        if (!$this->useGps || $this->sasUnavailable || $vehicleId <= 0) {
            return null;
        }

        try {
            if ($this->sasCars === null) {
                $this->sas = new SasDashboardService($this->db);
                if (!$this->sas->credentialsAvailable()) {
                    $this->sasUnavailable = true;
                    return null;
                }
                $this->sasCars = [];
                foreach ($this->sas->getFleetVehicles() as $car) {
                    $local = (int) ($car['local_vehicle_id'] ?? 0);
                    if ($local > 0) {
                        $this->sasCars[$local] = (int) ($car['sas_vehicle_id'] ?? 0);
                    }
                }
            }
            $carId = $this->sasCars[$vehicleId] ?? 0;
            if ($carId <= 0 || $this->sas === null) {
                return null;
            }

            $km = [];
            foreach ($intervals as [$start, $end]) {
                $distance = $this->sas->getGpsDistance($carId, $start, $end);
                if ($distance === null) {
                    return null;
                }
                $km[] = max(0.0, $distance);
            }

            return $km;
        } catch (Throwable $exception) {
            error_log('[RaceMonthSplitService][gps] ' . $exception->getMessage());
            // SAS cazut: restul curselor din aceeasi cerere merg direct pe durata.
            $this->sasUnavailable = true;

            return null;
        }
    }

    /**
     * Imparte un intreg dupa ponderi (cele mai mari resturi), suma ramane exacta.
     *
     * @param list<float> $weights
     * @return list<int|null>
     */
    private function splitInt(mixed $total, array $weights): array
    {
        if ($total === null || trim((string) $total) === '') {
            return array_fill(0, count($weights), null);
        }

        return array_map('intval', $this->largestRemainder((float) (int) $total, $weights, 1));
    }

    /**
     * @param list<float> $weights
     * @return list<string|null>
     */
    private function splitDecimal(mixed $total, array $weights): array
    {
        if ($total === null || trim((string) $total) === '') {
            return array_fill(0, count($weights), null);
        }

        return array_map(
            static fn (float $value): string => number_format($value, 2, '.', ''),
            $this->largestRemainder((float) $total, $weights, 100)
        );
    }

    /**
     * @param list<float> $weights
     * @return list<float>
     */
    private function largestRemainder(float $total, array $weights, int $unitsPerOne): array
    {
        $sum = array_sum($weights);
        $units = (int) round($total * $unitsPerOne);
        $raw = array_map(static fn (float $weight): float => $sum > 0 ? $units * $weight / $sum : 0.0, $weights);
        $parts = array_map(static fn (float $value): int => (int) floor($value), $raw);
        $left = $units - array_sum($parts);
        $order = array_keys($raw);
        usort($order, static fn (int $a, int $b): int => ($raw[$b] - $parts[$b]) <=> ($raw[$a] - $parts[$a]) ?: $a <=> $b);
        foreach ($order as $index) {
            if ($left <= 0) {
                break;
            }
            $parts[$index]++;
            $left--;
        }

        return array_map(static fn (int $value): float => $value / $unitsPerOne, $parts);
    }

    // ------------------------------------------------------------------ utilitare

    /** Cursa ca faza unica: valorile ei, cu km-ii din campul pe care il scriu fazele. */
    private function raceAsSegment(array $race): array
    {
        $agreedKmType = in_array((string) ($race['tip_transport'] ?? ''), ['primar', 'primar_distributie'], true);
        $km = $race[$agreedKmType ? 'km_totali' : 'km_cursa'] ?? null;

        return [
            'id' => 0,
            'auto_luna' => self::AUTO_BASE,
            'vehicle_id' => $race['vehicle_id'] ?? null,
            'driver_id' => $race['driver_id'] ?? null,
            'loc_incarcare_id' => $race['loc_incarcare_id'] ?? null,
            'zona_distributie_id' => $race['zona_distributie_id'] ?? null,
            'loc_plecare' => $race['loc_plecare'] ?? null,
            'loc_livrare' => $race['loc_livrare'] ?? null,
            'data_inceput' => $race['data_inceput'] ?? null,
            'ora_inceput' => $race['ora_inceput'] ?? null,
            'data_sfarsit' => $race['data_sfarsit'] ?? null,
            'ora_sfarsit' => $race['ora_sfarsit'] ?? null,
            'km' => ($km === null || trim((string) $km) === '') ? null : (int) $km,
            'cantitate_incarcata' => $race['cantitate_incarcata'] ?? null,
            // Pe Primar tonele livrate stau doar pe faze (formularul cursei nu le are).
            'tona_livrata' => in_array((string) ($race['tip_transport'] ?? ''), ['compresor', 'distributie', 'primar_distributie'], true)
                ? ($race['tona_livrata'] ?? null) : null,
            'nr_clienti' => $race['nr_clienti'] ?? null,
            'ore_functionare' => $race['ore_functionare'] ?? null,
            'observatii' => '',
        ];
    }

    /** Lipeste continuarea (auto) la faza dinainte: sfarsitul ei, km si cantitati adunate. */
    private function merge(array $into, array $piece): array
    {
        $into['data_sfarsit'] = $piece['data_sfarsit'] ?? $into['data_sfarsit'] ?? null;
        $into['ora_sfarsit'] = $piece['ora_sfarsit'] ?? $into['ora_sfarsit'] ?? null;
        foreach (['km', 'cantitate_incarcata', 'tona_livrata', 'nr_clienti', 'ore_functionare'] as $field) {
            $a = $into[$field] ?? null;
            $b = $piece[$field] ?? null;
            $hasA = $a !== null && trim((string) $a) !== '';
            $hasB = $b !== null && trim((string) $b) !== '';
            if (!$hasA && !$hasB) {
                continue;
            }
            $into[$field] = in_array($field, ['km', 'nr_clienti'], true)
                ? (int) $a + (int) $b
                : number_format((float) $a + (float) $b, 2, '.', '');
        }

        return $into;
    }

    /**
     * Cand fazele actuale au exact intervalele dorite, ponderile lor (km-ii bucatilor)
     * se refolosesc: o salvare care nu muta datele nu mai intreaba GPS-ul si nu muta km.
     *
     * @param list<array{unit: array<string, mixed>, intervals: list<array{0: string, 1: string}>}> $pieces
     * @param list<array<string, mixed>> $current
     * @return array<int, array{0: list<float>, 1: string, 2: list<string>}> index faza-baza => ponderi
     */
    private function currentWeights(array $pieces, array $current): array
    {
        $wanted = [];
        foreach ($pieces as $piece) {
            foreach ($piece['intervals'] as [$start, $end]) {
                $wanted[] = $start . '|' . $end;
            }
        }
        $have = array_map(fn (array $segment): string => $this->intervalKey($segment), $current);
        if ($wanted !== $have) {
            return [];
        }

        $reuse = [];
        $position = 0;
        foreach ($pieces as $unitIndex => $piece) {
            $count = count($piece['intervals']);
            $group = array_slice($current, $position, $count);
            $position += $count;
            $weights = array_map(static fn (array $segment): float => max(0.0, (float) ($segment['km'] ?? 0)), $group);
            if ($count < 2 || array_sum($weights) <= 0) {
                continue;
            }
            $notes = array_map(static fn (array $segment): string => (string) ($segment['observatii'] ?? ''), $group);
            $notes[0] = (int) ($group[0]['auto_luna'] ?? 0) === self::AUTO_NONE ? '' : $notes[0];
            $source = str_contains(implode(' ', $notes), 'GPS') ? 'gps' : 'durata';
            $reuse[$unitIndex] = [$weights, $source, array_map(
                static fn (string $note): ?string => str_starts_with($note, self::NOTE_PREFIX) ? $note : null,
                $notes
            )];
        }

        return $reuse;
    }

    private function intervalKey(array $segment): string
    {
        return substr((string) ($segment['data_inceput'] ?? ''), 0, 10) . ' ' . $this->time($segment['ora_inceput'] ?? null, '00:00:00')
            . '|' . substr((string) ($segment['data_sfarsit'] ?? ''), 0, 10) . ' ' . $this->time($segment['ora_sfarsit'] ?? null, '23:59:59');
    }

    /** Planul nu schimba nimic fata de fazele actuale? */
    private function samePlan(array $plan, array $current): bool
    {
        if (count($plan) !== count($current)) {
            return false;
        }
        $ints = ['vehicle_id', 'driver_id', 'loc_incarcare_id', 'zona_distributie_id', 'km', 'nr_clienti', 'auto_luna'];
        $strings = ['loc_plecare', 'loc_livrare'];
        $decimals = ['cantitate_incarcata', 'tona_livrata', 'ore_functionare'];
        foreach ($plan as $index => $segment) {
            $existing = $current[$index];
            if ($this->intervalKey($segment) !== $this->intervalKey($existing)) {
                return false;
            }
            foreach ($ints as $field) {
                if ((int) ($segment[$field] ?? 0) !== (int) ($existing[$field] ?? 0)) {
                    return false;
                }
            }
            foreach ($strings as $field) {
                if (trim((string) ($segment[$field] ?? '')) !== trim((string) ($existing[$field] ?? ''))) {
                    return false;
                }
            }
            foreach ($decimals as $field) {
                if (round((float) ($segment[$field] ?? 0), 2) !== round((float) ($existing[$field] ?? 0), 2)) {
                    return false;
                }
            }
        }

        return true;
    }
}
