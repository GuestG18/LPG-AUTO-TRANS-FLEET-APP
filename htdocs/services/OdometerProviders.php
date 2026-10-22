<?php
declare(strict_types=1);

/**
 * Adaptoarele de furnizor ale Centrului Kilometraj. Fiecare intoarce citiri
 * NORMALIZATE, cu aceleasi chei:
 *   source_reading_id, measured_at (momentul masurarii la sursa), segment_started_at,
 *   original_value, original_unit, odometer_km (normalizat), measurement_type.
 * Credentialele raman pe server (SasFleetClient citeste .env); nimic nu ajunge in JS.
 */
interface SasOdometerSource
{
    public function credentialsAvailable(): bool;

    /** @return list<array{sas_vehicle_id:int, registration:string, local_vehicle_id:?int, disabled:bool}> */
    public function fleetCars(): array;

    /**
     * Citirile de odometru (kmIndex) din foaia de parcurs intre $from si $to (ora locala),
     * plus toate deplasarile (si cele fara kmIndex), ca sa se stie sigur cand a stat parcat.
     *
     * @return array{readings: list<array<string, mixed>>, movements: list<array{start:string, end:string, km:float}>}
     */
    public function fetchReadings(int $carId, string $from, string $to): array;
}

interface CardOilOdometerSource
{
    /** @return array<string, list<array<string, mixed>>> [plate_key] => alimentari normalizate, crescator */
    public function fillupsByPlate(?array $plateKeys = null): array;

    /** @return array{last_success_at:?string, status:?string, error_message:?string}|null */
    public function lastSync(): ?array;
}

/**
 * SAS Fleet: POST reports/travelsheet. Fiecare segment are kmIndex = odometrul GPS
 * la SFARSITUL segmentului (verificat: kmIndex(n) = kmIndex(n−1) + distance(n)).
 * Odometrul GPS nu e calibrat pe bord, deci valoarea absoluta difera de bord; se
 * folosesc doar diferentele. Unele vehicule nu raporteaza kmIndex deloc: atunci
 * nu exista citire (nu se deduce din distance).
 * Observat 22.10.2026: kmIndex lipseste cand fereastra ceruta e in aceeasi zi
 * calendaristica (ex. azi 00:00 -> acum); apare cand fereastra incepe cel tarziu ieri.
 * Limitele segmentelor depind si ele de fereastra (un segment taiat de inceputul
 * ferestrei vine scurtat), iar kmIndex variaza cu fractiuni de metru intre cereri.
 */
final class SasFleetOdometerSource implements SasOdometerSource
{
    /** Pauza intre cereri: SAS limiteaza la ~60 cereri/minut (ca la Revizii). */
    private const PAUSE_MICROSECONDS = 650000;
    private const RETRY_PAUSE_SECONDS = 2;

    private SasFleetClient $client;
    private PDO $db;
    private string $sessionFile;
    private bool $sessionRestored = false;
    private float $lastCallAt = 0.0;

    public function __construct(PDO $db, ?SasFleetClient $client = null)
    {
        $this->db = $db;
        $this->client = $client ?? new SasFleetClient();
        $this->sessionFile = dirname(BASE_PATH) . '/storage/cache/sas_session.json';
    }

    public function credentialsAvailable(): bool
    {
        return $this->client->credentialsAvailable();
    }

    public function fleetCars(): array
    {
        $cars = (new FleetLivePositionService($this->db, $this->client))->getFleetHierarchy()['cars'] ?? [];
        $result = [];
        foreach ((array) $cars as $car) {
            $result[] = [
                'sas_vehicle_id' => (int) ($car['sas_vehicle_id'] ?? 0),
                'registration' => (string) ($car['registration'] ?? ''),
                'local_vehicle_id' => isset($car['local_vehicle_id']) ? (int) $car['local_vehicle_id'] : null,
                'disabled' => !empty($car['disabled']),
            ];
        }

        return $result;
    }

    public function fetchReadings(int $carId, string $from, string $to): array
    {
        $this->restoreSession();
        $closed = strtotime($to) < strtotime('today');
        $sheet = $this->callWithRetry(fn (): array => $this->client->getTravelSheet(
            $carId,
            date('Y-m-d\TH:i:s', (int) strtotime($from)),
            date('Y-m-d\TH:i:s', (int) strtotime($to)),
            $closed
        ));
        $this->persistSession();

        return self::parseTravelSheet($carId, $sheet);
    }

    /**
     * Segmentele cu kmIndex -> citiri normalizate. Identitatea citirii = carId + sfarsitul
     * segmentului: aceeasi citire adusa de doua ori nu se dubleaza. Deplasarile (distance > 0)
     * se intorc separat, inclusiv cele fara kmIndex.
     *
     * @return array{readings: list<array<string, mixed>>, movements: list<array{start:string, end:string, km:float}>}
     */
    public static function parseTravelSheet(int $carId, array $sheet): array
    {
        $readings = [];
        $movements = [];
        foreach ((array) ($sheet['segments'] ?? []) as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            $end = self::localDateTime($segment['dateEnd'] ?? null);
            $start = self::localDateTime($segment['dateStart'] ?? null);
            if ($end === null) {
                continue;
            }
            if (is_numeric($segment['distance'] ?? null) && (float) $segment['distance'] > 0.05) {
                $movements[] = ['start' => $start ?? $end, 'end' => $end, 'km' => round((float) $segment['distance'], 1)];
            }
            if (!is_numeric($segment['kmIndex'] ?? null)) {
                continue;
            }
            $value = (float) $segment['kmIndex'];
            if ($value <= 0) {
                continue;
            }
            $readings[] = [
                'source_reading_id' => $carId . '@' . $end,
                'segment_started_at' => $start,
                'measured_at' => $end,
                'original_value' => $value,
                'original_unit' => 'km',
                'odometer_km' => round($value, 1),
                'measurement_type' => 'gps_km_index',
            ];
        }
        usort($readings, static fn (array $a, array $b): int => strcmp($a['measured_at'], $b['measured_at']));
        usort($movements, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        return ['readings' => $readings, 'movements' => $movements];
    }

    private static function localDateTime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime(str_replace('T', ' ', substr($value, 0, 19)));

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    /** O singura reincercare dupa o pauza (limita de cereri / eroare tranzitorie). */
    private function callWithRetry(callable $call): array
    {
        $this->throttle();
        try {
            return $call();
        } catch (SasUnauthorizedException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            sleep(self::RETRY_PAUSE_SECONDS);
            $this->throttle();
            return $call();
        }
    }

    private function throttle(): void
    {
        $elapsed = (microtime(true) - $this->lastCallAt) * 1_000_000;
        if ($this->lastCallAt > 0 && $elapsed < self::PAUSE_MICROSECONDS) {
            usleep((int) (self::PAUSE_MICROSECONDS - $elapsed));
        }
        $this->lastCallAt = microtime(true);
    }

    private function restoreSession(): void
    {
        if ($this->sessionRestored) {
            return;
        }
        $raw = is_file($this->sessionFile) ? @file_get_contents($this->sessionFile) : false;
        $session = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($session)) {
            $this->client->restoreState($session);
        }
        $this->sessionRestored = true;
    }

    private function persistSession(): void
    {
        @file_put_contents(
            $this->sessionFile,
            json_encode($this->client->exportState(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}

/**
 * CardOil Refill: alimentarile sunt deja importate de sincronizarea existenta
 * (scripts/sync_cardoil_alimentari.php -> fuel_fillups). Odometrul e cel tastat de
 * sofer la pompa (campul odometer_km / km_alimentare din API), la momentul
 * alimentarii — o citire pe EVENIMENT, nu telemetrie continua. Centrul nu face
 * apeluri CardOil proprii.
 */
final class FuelFillupOdometerSource implements CardOilOdometerSource
{
    public function __construct(private OdometerCenterModel $model)
    {
    }

    public function fillupsByPlate(?array $plateKeys = null): array
    {
        $result = [];
        foreach ($this->model->getCardOilFillups($plateKeys) as $plate => $rows) {
            foreach ($rows as $row) {
                $result[$plate][] = [
                    'id' => 'fillup:' . (int) $row['id'],
                    'fillup_id' => (int) $row['id'],
                    'measured_at' => (string) $row['fillup_datetime'],
                    'km' => (float) $row['odometer_km'],
                    'original_value' => (float) $row['odometer_km'],
                    'corrected' => $row['odometer_km_manual'] !== null,
                    'vehicle_corrected' => (bool) $row['vehicle_corrected'],
                    'fuel_type' => (string) $row['fuel_type'],
                    'liters' => (float) $row['quantity_liters'],
                    'station' => $row['station_name'],
                    'driver' => $row['driver_name'],
                ];
            }
        }

        return $result;
    }

    public function lastSync(): ?array
    {
        return $this->model->getLastCardOilSync();
    }
}
