<?php
declare(strict_types=1);

require_once __DIR__ . '/OdometerCalculator.php';
require_once dirname(__DIR__) . '/models/BaseModel.php';
require_once dirname(__DIR__) . '/models/OdometerCenterModel.php';

/**
 * Kilometrajul unui vehicul pentru fișa „Detalii vehicul” — DOAR CITIRE.
 *
 * Trei surse independente, niciodată adunate sau amestecate între ele:
 *   sas     = odometrul GPS SAS (kmIndex, nu e calibrat pe bord)      → „Km desfășurare”
 *   can     = odometrul de bord din CAN (raportul SAS)                 → „Km CAN”
 *   cardoil = odometrul tastat la pompă (eveniment, la alimentare)     → „Km bord”
 *
 * Folosește aceleași date și reguli ca Centru Kilometraj: citirile salvate
 * (vehicle_odometer_readings, fuel_fillups), maparea (vehicle_odometer_sources),
 * setările (km_center_setari) și regulile din OdometerCalculator (seria validă
 * flagAnomalies, sasReadingAt, sasFreshness). NU apelează API-uri, NU rulează DDL
 * (nici OdometerCenterModel::ensureSchema, nici TrailerSasKmService) și NU scrie nimic:
 * nici vehicule.km_bord, nici T0, nici cuplaje.
 *
 * Perioade: „Până în prezent” = ultima citire validă (absolut) pentru vehicule cu sursă
 * proprie; pentru o perioadă = km parcurși (citire finală − citire inițială, aceeași sursă).
 * Semiremorca nu are sursă proprie: km = suma km ai capului tractor pe fiecare interval
 * de cuplare (vehicule_cuplaje, tot istoricul), calculați separat pe fiecare sursă.
 */
final class VehicleMileageService
{
    public const PROVIDERS = ['sas', 'can', 'cardoil'];

    public const PROVIDER_LABELS = ['sas' => 'SAS GPS', 'can' => 'SAS CAN', 'cardoil' => 'CardOil'];

    /** Eticheta stării din carusel: [absolut, distanță]. */
    private const STATE_LABELS = [
        'sas' => ['Km desfășurare', 'Km parcurși GPS'],
        'can' => ['Km CAN', 'Km parcurși CAN'],
        'cardoil' => ['Km bord', 'Km parcurși bord'],
    ];
    private const TRAILER_LABELS = ['sas' => 'Km desfășurare', 'can' => 'Km CAN', 'cardoil' => 'Km parcurși'];

    private const MEASUREMENT_TYPES = [
        'sas' => 'gps_km_index',
        'can' => 'can_odometer',
        'cardoil' => 'pump_odometer',
    ];

    public const PERIODS = [
        'all' => 'Până în prezent',
        'month' => 'Luna curentă',
        'prev_month' => 'Luna precedentă',
        'year' => 'Anul curent',
        'custom' => 'Interval personalizat',
    ];

    /** Câte citiri recente intră în seria „curentă” (aceeași fereastră ca buildDashboard). */
    private const RECENT_READINGS = 80;
    /** Context înaintea începutului perioadei: citirea de referință + lanțul de validare. */
    private const CONTEXT_DAYS = 35;

    private PDO $db;
    private OdometerCenterModel $odometerModel;
    private ?int $nowTs;
    private ?array $settings = null;
    /** @var array<string, array<int, array<string, string>>> */
    private array $historyCache = [];
    /** Număr de interogări SQL (raport de performanță / teste). */
    public int $queries = 0;

    public function __construct(PDO $db, ?int $nowTs = null)
    {
        $this->db = $db;
        // BaseModel nu rulează DDL; metodele folosite aici nu apelează ensureSchema().
        $this->odometerModel = new OdometerCenterModel($db);
        $this->nowTs = $nowTs;
    }

    private function now(): int
    {
        return $this->nowTs ?? time();
    }

    private function nowSql(): string
    {
        return date('Y-m-d H:i:s', $this->now());
    }

    // ================================================================ perioada

    /**
     * Limitele perioadei, în fusul orar al aplicației. Sfârșitul include toată ziua
     * aleasă, dar nu trece de momentul de acum.
     *
     * @return array{code:string, label:string, start:?string, end:string, absolute:bool, short:string}
     */
    public static function resolvePeriod(string $code, ?string $from, ?string $to, int $nowTs): array
    {
        $now = date('Y-m-d H:i:s', $nowTs);
        $code = array_key_exists($code, self::PERIODS) ? $code : 'all';
        switch ($code) {
            case 'month':
                $start = date('Y-m-01 00:00:00', $nowTs);
                $end = $now;
                break;
            case 'prev_month':
                $first = strtotime(date('Y-m-01 00:00:00', $nowTs));
                $start = date('Y-m-01 00:00:00', strtotime('-1 month', $first));
                $end = date('Y-m-d H:i:s', $first - 1);
                break;
            case 'year':
                $start = date('Y-01-01 00:00:00', $nowTs);
                $end = $now;
                break;
            case 'custom':
                // Data simplă = toată ziua (00:00 → 23:59:59); cu oră = exact minutul ales (inclusiv).
                $fromParsed = self::parseDateTime($from);
                $toParsed = self::parseDateTime($to);
                if ($fromParsed === null || $toParsed === null) {
                    throw new InvalidArgumentException('Alege data (și ora) de început și de sfârșit.');
                }
                $start = $fromParsed['date'] . ' ' . ($fromParsed['time'] ?? '00:00') . ':00';
                $end = $toParsed['date'] . ' ' . ($toParsed['time'] !== null ? $toParsed['time'] . ':59' : '23:59:59');
                if ($start >= $end) {
                    throw new InvalidArgumentException('Începutul intervalului trebuie să fie înainte de sfârșit.');
                }
                if ($start > $now) {
                    throw new InvalidArgumentException('Intervalul începe în viitor.');
                }
                if ($fromParsed['date'] < '2000-01-01') {
                    throw new InvalidArgumentException('Interval invalid.');
                }
                $end = min($end, $now);
                $withTime = $fromParsed['time'] !== null || $toParsed['time'] !== null;
                break;
            default:
                $start = null;
                $end = $now;
        }

        $short = self::PERIODS[$code];
        if ($code === 'custom' || $code === 'prev_month') {
            $format = !empty($withTime) ? 'd.m.Y H:i' : 'd.m.Y';
            $short = date($format, (int) strtotime((string) $start)) . ' – ' . date($format, (int) strtotime($end));
        }

        return ['code' => $code, 'label' => self::PERIODS[$code], 'start' => $start, 'end' => $end,
            'absolute' => $code === 'all', 'short' => $short];
    }

    /**
     * „Y-m-d”, „Y-m-d H:i”, „Y-m-dTH:i”, „dd.mm.yyyy [H:i]”, „dd/mm/yyyy [H:i]”.
     *
     * @return array{date:string, time:?string}|null
     */
    private static function parseDateTime(?string $value): ?array
    {
        $value = trim((string) $value);
        if (!preg_match('/^(?:(\d{4})-(\d{2})-(\d{2})|(\d{2})[.\/](\d{2})[.\/](\d{4}))(?:[ T](\d{1,2}):(\d{2})(?::\d{2})?)?$/', $value, $m)) {
            return null;
        }
        [$year, $month, $day] = ($m[1] ?? '') !== '' ? [$m[1], $m[2], $m[3]] : [$m[6], $m[5], $m[4]];
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }
        $time = null;
        if (isset($m[7]) && $m[7] !== '') {
            if ((int) $m[7] > 23 || (int) $m[8] > 59) {
                return null;
            }
            $time = sprintf('%02d:%02d', (int) $m[7], (int) $m[8]);
        }

        return ['date' => $year . '-' . $month . '-' . $day, 'time' => $time];
    }

    // ================================================================ intrare

    /**
     * Cele trei surse pentru un vehicul, pe perioada cerută, într-un singur răspuns.
     *
     * @return array<string, mixed>
     */
    public function forVehicle(int $vehicleId, string $periodCode = 'all', ?string $from = null, ?string $to = null): array
    {
        $vehicle = $this->vehicle($vehicleId);
        if ($vehicle === null) {
            throw new InvalidArgumentException('Vehicul inexistent.');
        }
        $period = self::resolvePeriod($periodCode, $from, $to, $this->now());
        $isTrailer = str_starts_with((string) $vehicle['tip_vehicul'], 'semiremorca');

        $sources = $isTrailer ? $this->trailerSources($vehicle, $period) : $this->directSources($vehicle, $period);

        return [
            'ok' => true,
            'generated_at' => $this->nowSql(),
            'vehicle' => [
                'id' => (int) $vehicle['id'],
                'plate' => (string) $vehicle['nr_inmatriculare'],
                'type' => (string) $vehicle['tip_vehicul'],
                'is_trailer' => $isTrailer,
            ],
            'period' => $period,
            'order' => self::PROVIDERS,
            'sources' => $sources,
        ];
    }

    // ================================================================ vehicul cu sursă proprie

    /** @return array<string, array<string, mixed>> */
    private function directSources(array $vehicle, array $period): array
    {
        $id = (int) $vehicle['id'];
        $settings = $this->settings();
        $mappings = $this->mappings([$id])[$id] ?? [];
        $result = [];

        foreach (['sas', 'can'] as $provider) {
            $base = $this->baseResult($provider, $period, false);
            $source = $mappings[$provider] ?? null;
            if ($source === null || (int) $source['enabled'] !== 1) {
                $sasMapped = isset($mappings['sas']) && (int) $mappings['sas']['enabled'] === 1;
                $result[$provider] = $this->unavailable($base, $provider === 'can' && $source === null && $sasMapped
                    ? 'CAN încă nesincronizat: rulează actualizarea din Centru Kilometraj.'
                    : 'Sursă indisponibilă pentru acest vehicul.');
                continue;
            }
            $freshness = OdometerCalculator::sasFreshness($source['last_checked_at'], $source['last_status'], $this->now(), (int) $settings['sas_prospetime_ore']);
            $base['freshness'] = $freshness;
            $base['last_checked_at'] = $source['last_checked_at'];

            if ($period['absolute']) {
                $rows = $this->odometerModel->getReadings($id, $provider, null, null, self::RECENT_READINGS);
                $this->queries++;
                if ($rows === []) {
                    $result[$provider] = $this->unavailable($base, $source['last_checked_at'] === null
                        ? ($provider === 'can' ? 'CAN încă nesincronizat: rulează actualizarea din Centru Kilometraj.' : 'Încă nesincronizat din SAS Fleet.')
                        : ($provider === 'can'
                            ? 'Vehiculul nu raportează odometru CAN în SAS.'
                            : 'SAS Fleet nu raportează odometru (kmIndex) pentru acest vehicul.'));
                    continue;
                }
                $result[$provider] = self::absoluteFromSeries($base, self::telemetrySeries($rows));
                continue;
            }
            $series = self::telemetrySeries($this->telemetryWindow($id, $provider, (string) $period['start'], $period['end']));
            $coverageEnd = $this->coverageEnd($source, $freshness);
            $calc = self::telemetryDistance($series, (string) $period['start'], $period['end'], (int) $settings['sas_toleranta_minute'], $coverageEnd,
                $this->historyStart([$id])[$id][$provider] ?? null);
            $result[$provider] = self::applyDistance($base, $calc);
        }

        $cardBase = $this->baseResult('cardoil', $period, false);
        $fillups = $this->fillups([self::plateKey((string) $vehicle['nr_inmatriculare'])]);
        $series = $fillups[self::plateKey((string) $vehicle['nr_inmatriculare'])] ?? [];
        $cardBase['freshness'] = $this->cardOilFreshness();
        if ($series === []) {
            $result['cardoil'] = $this->unavailable($cardBase, 'Fără citiri disponibile: nicio alimentare CardOil cu odometru pentru acest număr.');
        } elseif ($period['absolute']) {
            $result['cardoil'] = self::absoluteFromSeries($cardBase, OdometerCalculator::flagAnomalies($series));
        } else {
            $calc = self::eventDistance(OdometerCalculator::flagAnomalies($series), (string) $period['start'], $period['end'], (int) $settings['cardoil_varsta_max_ore']);
            $result['cardoil'] = self::applyDistance($cardBase, $calc);
        }

        return ['sas' => $result['sas'], 'can' => $result['can'], 'cardoil' => $result['cardoil']];
    }

    /** Ultima citire validă din serie (aceeași regulă ca Centru Kilometraj). */
    private static function absoluteFromSeries(array $base, array $series): array
    {
        $current = null;
        $anomalies = 0;
        foreach ($series as $reading) {
            if ($reading['anomaly'] === null) {
                $current = $reading;
            } else {
                $anomalies++;
            }
        }
        $base['anomalies'] = $anomalies;
        if ($current === null) {
            return self::markUnavailable($base, $series === []
                ? 'Fără citiri disponibile.'
                : 'Toate citirile recente sunt marcate ca anomalie.');
        }
        $base['value'] = (float) $current['km'];
        $base['measured_at'] = (string) $current['measured_at'];
        $base['interval'] = ['from' => (string) $current['measured_at'], 'to' => (string) $current['measured_at']];
        $base['status'] = 'ok';
        $base['coverage'] = 'complete';
        if (in_array($base['freshness'], ['neactualizat', 'eroare', 'nesincronizat'], true)) {
            $base['status'] = 'stale';
            $base['reason'] = 'Date neactualizate: ultima citire validă este din ' . date('d.m.Y H:i', (int) strtotime((string) $current['measured_at'])) . '.';
        }
        if ($anomalies > 0) {
            $base['notes'][] = sprintf('%d citiri recente marcate ca anomalie au fost excluse.', $anomalies);
        }

        return $base;
    }

    // ================================================================ calcul pe interval (pur, testat)

    /**
     * Km parcurși pe [start, end] dintr-o serie telemetrică (SAS GPS sau CAN) a UNUI vehicul.
     * Capetele: OdometerCalculator::sasReadingAt (exact dacă vehiculul stătea parcat, altfel
     * toleranța de minute). Când un capăt nu e sigur, se folosește prima / ultima citire
     * din interval (nimic din afara lui) și rezultatul e PARȚIAL. Nu se extrapolează.
     *
     * @param list<array<string, mixed>> $series toată seria (cu 'anomaly'), crescător
     * @return array<string, mixed>
     */
    public static function telemetryDistance(array $series, string $start, string $end, int $toleranceMinutes, ?string $coverageEnd, ?string $historyFrom = null): array
    {
        // Istoricul salvat local începe după începutul intervalului: explicația exactă, nu doar „insuficiente”.
        $historyNote = $historyFrom !== null && OdometerCalculator::ts($historyFrom) > OdometerCalculator::ts($start)
            ? 'Istoricul de citiri salvat începe la ' . date('d.m.Y H:i', OdometerCalculator::ts($historyFrom)) . '.'
            : null;
        $valid = array_values(array_filter($series, static fn (array $r): bool => $r['anomaly'] === null));
        $startTs = OdometerCalculator::ts($start);
        $endTs = OdometerCalculator::ts($end);
        $notes = [];
        $partial = false;

        $startMatch = OdometerCalculator::sasReadingAt($valid, $start, $toleranceMinutes, $coverageEnd);
        $startReading = null;
        $startPrecision = null;
        if ($startMatch['status'] === OdometerCalculator::STATUS_OK) {
            $startReading = $startMatch['reading'];
            $startPrecision = (string) $startMatch['precision'];
        } else {
            $startPrecision = 'prima_citire';
            foreach ($valid as $reading) {
                $ts = OdometerCalculator::ts((string) $reading['measured_at']);
                if ($ts > $startTs && $ts <= $endTs) {
                    $startReading = $reading;
                    break;
                }
            }
            if ($startReading !== null) {
                $partial = true;
                $notes[] = $startMatch['status'] === OdometerCalculator::STATUS_UNAVAILABLE
                    ? ($historyNote !== null
                        ? $historyNote . ' Calculul pornește de la prima citire disponibilă.'
                        : 'Nu există citire înainte de începutul intervalului: calculul pornește de la prima citire disponibilă.')
                    : 'Vehiculul era în mers la începutul intervalului: calculul pornește de la prima citire din interval.';
            }
        }

        $endMatch = OdometerCalculator::sasReadingAt($valid, $end, $toleranceMinutes, $coverageEnd);
        $endReading = null;
        $endPrecision = null;
        if ($endMatch['status'] === OdometerCalculator::STATUS_OK) {
            $endReading = $endMatch['reading'];
            $endPrecision = (string) $endMatch['precision'];
        } elseif ($endMatch['reading'] !== null) {
            $endPrecision = 'ultima_citire';
            // Ultima citire de dinainte de sfârșit: km de după ea nu se presupun.
            $endReading = $endMatch['reading'];
            $partial = true;
            $notes[] = 'Ultima citire disponibilă este din ' . date('d.m.Y H:i', (int) strtotime((string) $endReading['measured_at'])) . ': km de după ea nu sunt incluși.';
        }

        if ($startReading === null || $endReading === null
            || OdometerCalculator::ts((string) $endReading['measured_at']) < OdometerCalculator::ts((string) $startReading['measured_at'])) {
            return ['km' => null, 'status' => 'unavailable', 'reason' => $historyNote !== null
                    ? $historyNote . ' Citiri insuficiente pentru intervalul selectat.'
                    : 'Citiri insuficiente pentru intervalul selectat.',
                'start' => null, 'end' => null, 'notes' => $notes, 'anomalies' => self::anomaliesBetween($series, $startTs, $endTs)];
        }

        // Discontinuitate: citiri respinse DUPĂ capătul ales (ex. resetare de odometru) = km necunoscuți.
        $endChosenTs = OdometerCalculator::ts((string) $endReading['measured_at']);
        foreach ($series as $reading) {
            $ts = OdometerCalculator::ts((string) $reading['measured_at']);
            if ($reading['anomaly'] !== null && $ts > $endChosenTs && $ts <= $endTs) {
                $partial = true;
                $notes[] = 'Discontinuitate de odometru după ' . date('d.m.Y H:i', $endChosenTs) . ': citirile următoare nu se potrivesc cu seria validă.';
                break;
            }
        }

        $km = round((float) $endReading['km'] - (float) $startReading['km'], 1);
        if ($km < 0) {
            return ['km' => null, 'status' => 'unavailable', 'reason' => 'Diferență negativă de odometru: calcul respins.',
                'start' => null, 'end' => null, 'notes' => $notes, 'anomalies' => self::anomaliesBetween($series, $startTs, $endTs)];
        }

        return [
            'km' => $km,
            'status' => $partial ? 'partial' : 'complete',
            'reason' => $partial ? 'Acoperire parțială.' : null,
            'start' => ['km' => (float) $startReading['km'], 'at' => (string) $startReading['measured_at'], 'precision' => $startPrecision],
            'end' => ['km' => (float) $endReading['km'], 'at' => (string) $endReading['measured_at'], 'precision' => $endPrecision],
            'notes' => $notes,
            'anomalies' => self::anomaliesBetween($series, $startTs, $endTs),
        ];
    }

    /**
     * Km parcurși pe [start, end] din citiri pe EVENIMENT (CardOil): prima și ultima
     * alimentare validă DIN interval (nicio alimentare din afara lui). Complet doar dacă
     * ambele sunt la cel mult $toleranceHours de capetele intervalului.
     *
     * @return array<string, mixed>
     */
    public static function eventDistance(array $series, string $start, string $end, int $toleranceHours): array
    {
        $startTs = OdometerCalculator::ts($start);
        $endTs = OdometerCalculator::ts($end);
        $inside = [];
        foreach ($series as $reading) {
            $ts = OdometerCalculator::ts((string) $reading['measured_at']);
            if ($reading['anomaly'] === null && $ts >= $startTs && $ts <= $endTs) {
                $inside[] = $reading;
            }
        }
        $anomalies = self::anomaliesBetween($series, $startTs, $endTs);
        if (count($inside) < 2) {
            return ['km' => null, 'status' => 'unavailable', 'start' => null, 'end' => null, 'notes' => [], 'anomalies' => $anomalies,
                'reason' => $inside === []
                    ? 'Nicio alimentare cu odometru valid în interval.'
                    : 'O singură alimentare în interval: distanța nu se poate măsura.'];
        }
        $first = $inside[0];
        $last = $inside[count($inside) - 1];
        $km = round((float) $last['km'] - (float) $first['km'], 1);
        if ($km < 0) {
            return ['km' => null, 'status' => 'unavailable', 'start' => null, 'end' => null, 'notes' => [], 'anomalies' => $anomalies,
                'reason' => 'Diferență negativă de odometru: calcul respins.'];
        }
        $gapStart = OdometerCalculator::ts((string) $first['measured_at']) - $startTs;
        $gapEnd = $endTs - OdometerCalculator::ts((string) $last['measured_at']);
        $complete = $gapStart <= $toleranceHours * 3600 && $gapEnd <= $toleranceHours * 3600;
        $notes = ['Măsurat între alimentări: ' . date('d.m.Y H:i', (int) strtotime((string) $first['measured_at']))
            . ' → ' . date('d.m.Y H:i', (int) strtotime((string) $last['measured_at'])) . '.'];
        if (!$complete) {
            $notes[] = sprintf('Alimentările nu acoperă capetele intervalului (toleranță %d h): km din afara lor nu sunt incluși.', $toleranceHours);
        }

        return [
            'km' => $km,
            'status' => $complete ? 'complete' : 'partial',
            'reason' => $complete ? null : 'Acoperire parțială.',
            'start' => ['km' => (float) $first['km'], 'at' => (string) $first['measured_at'], 'precision' => 'eveniment'],
            'end' => ['km' => (float) $last['km'], 'at' => (string) $last['measured_at'], 'precision' => 'eveniment'],
            'notes' => $notes,
            'anomalies' => $anomalies,
        ];
    }

    private static function anomaliesBetween(array $series, int $fromTs, int $toTs): int
    {
        $count = 0;
        foreach ($series as $reading) {
            $ts = OdometerCalculator::ts((string) $reading['measured_at']);
            if ($reading['anomaly'] !== null && $ts >= $fromTs && $ts <= $toTs) {
                $count++;
            }
        }

        return $count;
    }

    private static function applyDistance(array $base, array $calc): array
    {
        $base['anomalies'] = (int) $calc['anomalies'];
        $base['notes'] = array_merge($base['notes'], $calc['notes']);
        if ($calc['anomalies'] > 0) {
            $base['notes'][] = sprintf('%d citiri anomale din interval au fost excluse.', $calc['anomalies']);
        }
        if ($calc['km'] === null) {
            return self::markUnavailable($base, (string) $calc['reason']);
        }
        $base['value'] = (float) $calc['km'];
        $base['measured_at'] = $calc['end']['at'];
        $base['interval'] = ['from' => $calc['start']['at'], 'to' => $calc['end']['at']];
        $base['boundaries'] = ['start' => $calc['start'], 'end' => $calc['end']];
        $base['coverage'] = $calc['status'] === 'complete' ? 'complete' : 'partial';
        $base['status'] = $calc['status'] === 'complete' ? 'ok' : 'partial';
        $base['reason'] = $calc['reason'];
        $base['confirmed_zero'] = $calc['status'] === 'complete' && (float) $calc['km'] === 0.0;

        return $base;
    }

    // ================================================================ semiremorcă (istoric de cuplare)

    /** @return array<string, array<string, mixed>> */
    private function trailerSources(array $vehicle, array $period): array
    {
        $trailerId = (int) $vehicle['id'];
        $settings = $this->settings();
        $couplings = $this->couplingHistory($trailerId);
        $result = [];
        foreach (self::PROVIDERS as $provider) {
            $result[$provider] = $this->baseResult($provider, $period, true);
        }
        if ($couplings === []) {
            foreach (self::PROVIDERS as $provider) {
                $result[$provider] = self::markUnavailable($result[$provider], 'Istoric de cuplare indisponibil: semiremorca nu are cuplaje înregistrate.');
            }
            return $result;
        }

        $intervals = self::buildCouplingIntervals($couplings, $period['start'], $period['end'], $this->tractorConflicts($trailerId, $couplings));
        $analysable = array_values(array_filter($intervals, static fn (array $i): bool => $i['status'] === 'ok'));
        if ($intervals === []) {
            foreach (self::PROVIDERS as $provider) {
                $r = $result[$provider];
                // Fără cuplaj în perioadă: semiremorca nu a fost tractată = 0 km confirmați de istoricul de cuplare.
                $r['value'] = 0.0;
                $r['status'] = 'ok';
                $r['coverage'] = 'complete';
                $r['confirmed_zero'] = true;
                $r['reason'] = 'Semiremorca nu a fost cuplată în perioada selectată.';
                $r['trailer'] = self::trailerSummary([], $provider);
                $result[$provider] = $r;
            }
            return $result;
        }

        // Citirile capetelor tractor, o singura data per tractor si sursa (fara N+1 pe intervale).
        $tractorIds = array_values(array_unique(array_map(static fn (array $i): int => $i['tractor_id'], $analysable)));
        $mappings = $this->mappings($tractorIds);
        $plates = $this->plates($tractorIds);
        $fillups = $this->fillups(array_values(array_map([self::class, 'plateKey'], $plates)));
        $cardOilFreshness = $this->cardOilFreshness();

        $seriesByTractor = [];
        foreach ($tractorIds as $tractorId) {
            $windowStart = null;
            $windowEnd = null;
            foreach ($analysable as $interval) {
                if ($interval['tractor_id'] === $tractorId) {
                    $windowStart = $windowStart === null ? $interval['effective_start'] : min($windowStart, $interval['effective_start']);
                    $windowEnd = $windowEnd === null ? $interval['effective_end'] : max($windowEnd, $interval['effective_end']);
                }
            }
            foreach (['sas', 'can'] as $provider) {
                $source = $mappings[$tractorId][$provider] ?? null;
                $seriesByTractor[$tractorId][$provider] = $source !== null && (int) $source['enabled'] === 1
                    ? self::telemetrySeries($this->telemetryWindow($tractorId, $provider, (string) $windowStart, (string) $windowEnd))
                    : null;
            }
            $plateKey = self::plateKey($plates[$tractorId] ?? '');
            $seriesByTractor[$tractorId]['cardoil'] = isset($fillups[$plateKey]) ? OdometerCalculator::flagAnomalies($fillups[$plateKey]) : null;
        }

        foreach (self::PROVIDERS as $provider) {
            $rows = [];
            foreach ($intervals as $interval) {
                $row = $interval + ['km' => null, 'calc_status' => 'unavailable', 'reason' => null, 'start_reading' => null, 'end_reading' => null, 'notes' => []];
                if ($interval['status'] !== 'ok') {
                    $row['calc_status'] = $interval['status'];
                    $row['reason'] = $interval['note'];
                    $rows[] = $row;
                    continue;
                }
                $series = $seriesByTractor[$interval['tractor_id']][$provider] ?? null;
                if ($series === null) {
                    $row['reason'] = $provider === 'cardoil'
                        ? 'Capul tractor nu are alimentări CardOil cu odometru.'
                        : 'Capul tractor nu are sursă ' . self::PROVIDER_LABELS[$provider] . '.';
                    $rows[] = $row;
                    continue;
                }
                if ($provider === 'cardoil') {
                    $calc = self::eventDistance($series, $interval['effective_start'], $interval['effective_end'], (int) $settings['cardoil_varsta_max_ore']);
                } else {
                    $source = $mappings[$interval['tractor_id']][$provider];
                    $freshness = OdometerCalculator::sasFreshness($source['last_checked_at'], $source['last_status'], $this->now(), (int) $settings['sas_prospetime_ore']);
                    $calc = self::telemetryDistance($series, $interval['effective_start'], $interval['effective_end'],
                        (int) $settings['sas_toleranta_minute'], $this->coverageEnd($source, $freshness),
                        $this->historyStart($tractorIds)[$interval['tractor_id']][$provider] ?? null);
                }
                $row['km'] = $calc['km'];
                $row['calc_status'] = $calc['status'];
                $row['reason'] = $calc['reason'];
                $row['start_reading'] = $calc['start'];
                $row['end_reading'] = $calc['end'];
                $row['notes'] = $calc['notes'];
                $rows[] = $row;
            }

            $summary = self::trailerSummary($rows, $provider);
            $r = $result[$provider];
            $r['trailer'] = $summary;
            $r['freshness'] = $provider === 'cardoil' ? $cardOilFreshness : null;
            if ($summary['total_km'] === null) {
                // Motivul concret al primului interval (ex. „Istoricul … începe la …”), nu doar unul generic.
                $firstReason = null;
                foreach ($rows as $row) {
                    if ($row['reason'] !== null && $row['reason'] !== '') {
                        $firstReason = (string) $row['reason'];
                        break;
                    }
                }
                $r = self::markUnavailable($r, $summary['calculated'] === 0 && $summary['analysed'] > 0
                    ? ($firstReason !== null && str_starts_with($firstReason, 'Istoricul')
                        ? $firstReason
                        : 'Citiri insuficiente pentru intervalele de cuplare din perioada selectată.')
                    : 'Istoric de cuplare indisponibil.');
                $r['trailer'] = $summary;
            } else {
                $r['value'] = $summary['total_km'];
                $r['coverage'] = $summary['coverage'];
                $r['status'] = $summary['coverage'] === 'complete' ? 'ok' : 'partial';
                $r['reason'] = $summary['coverage'] === 'complete' ? null : sprintf(
                    'Acoperire parțială: %d din %d intervale de cuplare calculate complet.', $summary['complete'], $summary['analysed']);
                $r['measured_at'] = $summary['latest_measurement'];
                foreach ($rows as $row) {
                    foreach ($row['notes'] as $note) {
                        if (str_starts_with((string) $note, 'Istoricul') && !in_array($note, $r['notes'], true)) {
                            $r['notes'][] = (string) $note;
                        }
                    }
                }
                $r['interval'] = ['from' => $summary['earliest_coverage'], 'to' => $summary['latest_measurement']];
                $r['confirmed_zero'] = $summary['coverage'] === 'complete' && $summary['total_km'] === 0.0;
            }
            $result[$provider] = $r;
        }

        return $result;
    }

    /**
     * Intervalele de cuplare ale semiremorcii, tăiate la perioadă și la „acum”, fără
     * dublă numărare: suprapunerile se taie (cel de-al doilea începe unde s-a terminat
     * primul), înregistrările invalide sau conflictuale sunt păstrate în listă cu motivul.
     *
     * @param list<array<string, mixed>> $couplings id, tractor_id, tractor_plate, data_start, data_end, activ
     * @param array<int, string> $conflicts coupling_id => motivul conflictului de tractor
     * @return list<array<string, mixed>>
     */
    public static function buildCouplingIntervals(array $couplings, ?string $periodStart, string $periodEnd, array $conflicts = []): array
    {
        usort($couplings, static fn (array $a, array $b): int => [$a['data_start'], (int) $a['id']] <=> [$b['data_start'], (int) $b['id']]);
        $intervals = [];
        $coveredUntil = null;
        foreach ($couplings as $c) {
            $start = (string) $c['data_start'];
            $rawEnd = $c['data_end'] !== null && $c['data_end'] !== '' ? (string) $c['data_end'] : null;
            $row = [
                'coupling_id' => (int) $c['id'],
                'tractor_id' => (int) $c['tractor_id'],
                'tractor_plate' => (string) ($c['tractor_plate'] ?? ''),
                'coupling_start' => $start,
                'coupling_end' => $rawEnd,
                'active' => $rawEnd === null,
                'effective_start' => null,
                'effective_end' => null,
                'status' => 'ok',
                'note' => null,
            ];
            $end = $rawEnd ?? $periodEnd;
            if ($rawEnd === null && (int) ($c['activ'] ?? 1) !== 1) {
                // Cuplaj închis fără dată de decuplare: nu se poate stabili unde se oprește.
                $row['status'] = 'invalid';
                $row['note'] = 'Cuplaj inactiv fără dată de decuplare.';
            } elseif ($start === '' || ($rawEnd !== null && $rawEnd < $start)) {
                $row['status'] = 'invalid';
                $row['note'] = 'Date de cuplare invalide (decuplare înainte de cuplare).';
            }

            $effStart = $periodStart !== null ? max($start, $periodStart) : $start;
            $effEnd = min($end, $periodEnd);
            if ($row['status'] === 'ok' && $effEnd <= $effStart) {
                continue; // nu se suprapune cu perioada
            }
            if ($row['status'] !== 'ok') {
                // Invalid: intră în raport doar dacă poate atinge perioada.
                if ($periodStart !== null && $start !== '' && $start > $periodEnd) {
                    continue;
                }
                $intervals[] = $row;
                continue;
            }
            if ($coveredUntil !== null && $effStart < $coveredUntil) {
                if ($effEnd <= $coveredUntil) {
                    $row['status'] = 'duplicate';
                    $row['note'] = 'Interval acoperit deja de alt cuplaj (înregistrare suprapusă).';
                    $row['effective_start'] = $effStart;
                    $row['effective_end'] = $effEnd;
                    $intervals[] = $row;
                    continue;
                }
                $row['note'] = 'Suprapunere cu cuplajul anterior: se numără doar de la ' . date('d.m.Y H:i', (int) strtotime($coveredUntil)) . '.';
                $effStart = $coveredUntil;
            }
            $row['effective_start'] = $effStart;
            $row['effective_end'] = $effEnd;
            if (isset($conflicts[(int) $c['id']])) {
                $row['status'] = 'conflict';
                $row['note'] = $conflicts[(int) $c['id']];
            }
            $coveredUntil = $coveredUntil === null ? $effEnd : max($coveredUntil, $effEnd);
            $intervals[] = $row;
        }

        return $intervals;
    }

    /**
     * Totalul unei surse pe intervale: doar km validați (complet sau parțial) se adună;
     * intervalele fără calcul, conflictuale sau invalide fac totalul PARȚIAL, nu dispar.
     *
     * @return array<string, mixed>
     */
    public static function trailerSummary(array $rows, string $provider): array
    {
        $total = 0.0;
        $calculated = 0;
        $complete = 0;
        $conflicts = 0;
        $earliest = null;
        $latest = null;
        foreach ($rows as $row) {
            if (in_array($row['calc_status'], ['conflict', 'invalid', 'duplicate'], true)) {
                $conflicts++;
            }
            if ($row['km'] === null) {
                continue;
            }
            $total += (float) $row['km'];
            $calculated++;
            $complete += $row['calc_status'] === 'complete' ? 1 : 0;
            $from = $row['start_reading']['at'] ?? null;
            $to = $row['end_reading']['at'] ?? null;
            if ($from !== null && ($earliest === null || $from < $earliest)) {
                $earliest = $from;
            }
            if ($to !== null && ($latest === null || $to > $latest)) {
                $latest = $to;
            }
        }
        $analysed = count(array_filter($rows, static fn (array $r): bool => $r['calc_status'] !== 'duplicate'));
        $coverage = $calculated === 0 ? 'unavailable' : ($complete === $analysed ? 'complete' : 'partial');

        return [
            'provider' => $provider,
            'analysed' => $analysed,
            'calculated' => $calculated,
            'complete' => $complete,
            'incomplete' => $analysed - $complete,
            'conflicts' => $conflicts,
            'earliest_coverage' => $earliest,
            'latest_measurement' => $latest,
            'total_km' => $calculated > 0 ? round($total, 1) : null,
            'coverage' => $coverage,
            'intervals' => array_map(static fn (array $r): array => [
                'coupling_id' => $r['coupling_id'],
                'tractor_id' => $r['tractor_id'],
                'tractor_plate' => $r['tractor_plate'],
                'coupling_start' => $r['coupling_start'],
                'coupling_end' => $r['coupling_end'],
                'active' => $r['active'],
                'effective_start' => $r['effective_start'],
                'effective_end' => $r['effective_end'],
                'start' => $r['start_reading'],
                'end' => $r['end_reading'],
                'km' => $r['km'],
                'status' => $r['calc_status'],
                'reason' => $r['reason'] ?? $r['note'],
                'notes' => array_values(array_filter(array_merge([$r['note']], $r['notes'] ?? []))),
            ], $rows),
        ];
    }

    // ================================================================ date (doar SELECT)

    private function vehicle(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, nr_inmatriculare, tip_vehicul FROM vehicule WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $this->queries++;
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<int, string> */
    private function plates(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->query('SELECT id, nr_inmatriculare FROM vehicule WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll(PDO::FETCH_ASSOC);
        $this->queries++;
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = (string) $row['nr_inmatriculare'];
        }

        return $result;
    }

    /** Setările Centru Kilometraj (fără ensureSchema: tabelul poate lipsi → implicite). */
    private function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        $settings = OdometerCenterModel::DEFAULT_SETTINGS;
        try {
            foreach ($this->db->query('SELECT setting_key, setting_value FROM km_center_setari')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (array_key_exists((string) $row['setting_key'], $settings)) {
                    $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
                }
            }
            $this->queries++;
        } catch (Throwable) {
            // Centru Kilometraj neinițializat: valorile implicite.
        }

        return $this->settings = $settings;
    }

    /** @return array<int, array<string, array<string, mixed>>> */
    private function mappings(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }
        try {
            $rows = $this->db->query('SELECT vehicle_id, provider_code, external_provider_vehicle_id, enabled, last_checked_at, last_status
                FROM vehicle_odometer_sources WHERE vehicle_id IN (' . implode(',', array_map('intval', $vehicleIds)) . ')')->fetchAll(PDO::FETCH_ASSOC);
            $this->queries++;
        } catch (Throwable) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['vehicle_id']][(string) $row['provider_code']] = $row;
        }

        return $result;
    }

    /**
     * Citirile unui vehicul pentru [start, end]: context înainte (citirea de referință),
     * tot intervalul și câteva citiri după (pentru „parcat la capăt”).
     */
    private function telemetryWindow(int $vehicleId, string $provider, string $start, string $end): array
    {
        $from = date('Y-m-d H:i:s', OdometerCalculator::ts($start) - self::CONTEXT_DAYS * 86400);
        $stmt = $this->db->prepare(
            '(SELECT source_reading_id, segment_started_at, measured_at, odometer_km FROM vehicle_odometer_readings
               WHERE vehicle_id = :v AND provider_code = :p AND measured_at >= :f AND measured_at <= :e)
             UNION ALL
             (SELECT source_reading_id, segment_started_at, measured_at, odometer_km FROM vehicle_odometer_readings
               WHERE vehicle_id = :v2 AND provider_code = :p2 AND measured_at > :e2 ORDER BY measured_at ASC LIMIT 3)
             ORDER BY measured_at ASC'
        );
        try {
            $stmt->execute([':v' => $vehicleId, ':p' => $provider, ':f' => $from, ':e' => $end, ':v2' => $vehicleId, ':p2' => $provider, ':e2' => $end]);
            $this->queries++;
        } catch (Throwable) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Prima citire validă salvată local, per vehicul și sursă telemetrică (de unde începe istoricul).
     *
     * @return array<int, array<string, string>>
     */
    private function historyStart(array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_map('intval', $vehicleIds)));
        $key = implode(',', $vehicleIds);
        if ($vehicleIds === []) {
            return [];
        }
        if (isset($this->historyCache[$key])) {
            return $this->historyCache[$key];
        }
        $result = [];
        try {
            $rows = $this->db->query("SELECT vehicle_id, provider_code, MIN(measured_at) AS first_at FROM vehicle_odometer_readings
                WHERE vehicle_id IN ({$key}) AND provider_code IN ('sas', 'can') AND validation_status = 'valid'
                GROUP BY vehicle_id, provider_code")->fetchAll(PDO::FETCH_ASSOC);
            $this->queries++;
            foreach ($rows as $row) {
                $result[(int) $row['vehicle_id']][(string) $row['provider_code']] = (string) $row['first_at'];
            }
        } catch (Throwable) {
            // fără istoric cunoscut: mesajul generic
        }

        return $this->historyCache[$key] = $result;
    }

    /** Seria telemetrică în forma OdometerCalculator, cu anomaliile marcate. */
    private static function telemetrySeries(array $rows): array
    {
        return OdometerCalculator::flagAnomalies(array_map(static fn (array $row): array => [
            'id' => (string) $row['source_reading_id'],
            'measured_at' => (string) $row['measured_at'],
            'segment_started_at' => $row['segment_started_at'],
            'km' => (float) $row['odometer_km'],
        ], $rows));
    }

    /**
     * Până unde e acoperit odometrul fără segmente noi: momentul ultimei verificări SAS;
     * dacă verificarea e proaspătă (prospețimea Centrului), se consideră acoperit până acum.
     */
    private function coverageEnd(array $source, string $freshness): ?string
    {
        if ($freshness === 'actual') {
            return $this->nowSql();
        }

        return $source['last_checked_at'] ?? null;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function fillups(array $plateKeys): array
    {
        $plateKeys = array_values(array_unique(array_filter($plateKeys)));
        if ($plateKeys === []) {
            return [];
        }
        $result = [];
        foreach ($this->odometerModel->getCardOilFillupsForPlates($plateKeys) as $plate => $rows) {
            foreach ($rows as $row) {
                $result[$plate][] = [
                    'id' => 'fillup:' . (int) $row['id'],
                    'measured_at' => (string) $row['fillup_datetime'],
                    'km' => (float) $row['odometer_km'],
                    'corrected' => $row['odometer_km_manual'] !== null,
                ];
            }
        }
        $this->queries++;

        return $result;
    }

    private function cardOilFreshness(): string
    {
        $settings = $this->settings();
        $sync = $this->odometerModel->getLastCardOilSync();
        $this->queries += 2;
        $syncAt = $sync['last_success_at'] ?? null;

        return $syncAt !== null && ($this->now() - OdometerCalculator::ts((string) $syncAt)) <= (int) $settings['cardoil_sync_max_ore'] * 3600
            ? 'eveniment' : 'neactualizat';
    }

    /** Toate cuplajele semiremorcii, cu numărul capului tractor. */
    private function couplingHistory(int $trailerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.tractor_id, c.data_start, c.data_end, c.activ, v.nr_inmatriculare AS tractor_plate
             FROM vehicule_cuplaje c LEFT JOIN vehicule v ON v.id = c.tractor_id
             WHERE c.semiremorca_id = :s ORDER BY c.data_start ASC, c.id ASC'
        );
        $stmt->execute([':s' => $trailerId]);
        $this->queries++;

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cuplajele în care capul tractor era, în același timp, cuplat la ALTĂ semiremorcă
     * (atribuire conflictuală): o singură interogare pentru toți tractorii implicați.
     *
     * @return array<int, string> coupling_id => motiv
     */
    private function tractorConflicts(int $trailerId, array $couplings): array
    {
        $tractorIds = array_values(array_unique(array_map(static fn (array $c): int => (int) $c['tractor_id'], $couplings)));
        if ($tractorIds === []) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT c.tractor_id, c.data_start, c.data_end, v.nr_inmatriculare AS trailer_plate
             FROM vehicule_cuplaje c LEFT JOIN vehicule v ON v.id = c.semiremorca_id
             WHERE c.semiremorca_id <> :s AND c.tractor_id IN (' . implode(',', array_map('intval', $tractorIds)) . ')'
        );
        $stmt->execute([':s' => $trailerId]);
        $this->queries++;
        $others = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $now = $this->nowSql();
        $conflicts = [];
        foreach ($couplings as $c) {
            $cEnd = $c['data_end'] ?? $now;
            foreach ($others as $o) {
                $oEnd = $o['data_end'] ?? $now;
                if ((int) $o['tractor_id'] === (int) $c['tractor_id'] && $o['data_start'] < $cEnd && $oEnd > $c['data_start']) {
                    $conflicts[(int) $c['id']] = 'Atribuire conflictuală: capul tractor era cuplat simultan și la ' . ($o['trailer_plate'] ?? '?') . '.';
                    break;
                }
            }
        }

        return $conflicts;
    }

    // ================================================================ utilitare

    private function baseResult(string $provider, array $period, bool $trailer): array
    {
        return [
            'provider' => $provider,
            'provider_label' => self::PROVIDER_LABELS[$provider],
            'label' => $trailer ? self::TRAILER_LABELS[$provider] : self::STATE_LABELS[$provider][$period['absolute'] ? 0 : 1],
            'measurement_type' => self::MEASUREMENT_TYPES[$provider],
            'mode' => $period['absolute'] && !$trailer ? 'absolute' : 'distance',
            'via_coupling' => $trailer,
            'value' => null,
            'status' => 'unavailable',
            'coverage' => 'unavailable',
            'freshness' => null,
            'last_checked_at' => null,
            'measured_at' => null,
            'interval' => null,
            'boundaries' => null,
            'confirmed_zero' => false,
            'anomalies' => 0,
            'reason' => null,
            'notes' => [],
        ];
    }

    private function unavailable(array $base, string $reason): array
    {
        return self::markUnavailable($base, $reason);
    }

    private static function markUnavailable(array $base, string $reason): array
    {
        $base['value'] = null;
        $base['status'] = 'unavailable';
        $base['coverage'] = 'unavailable';
        $base['reason'] = $reason;

        return $base;
    }

    public static function plateKey(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate) ?? '');
    }
}
