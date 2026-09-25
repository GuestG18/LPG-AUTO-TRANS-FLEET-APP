<?php
declare(strict_types=1);

/**
 * Calendarul legal romanesc: sarbatori legale + zile lucratoare legale.
 *
 * Sursa sarbatorilor este Nager.Date (fara cheie API). API-ul da DOAR sarbatorile;
 * zilele lucratoare le calculeaza aplicatia: Luni-Vineri si nu sarbatoare legala.
 * O sarbatoare care cade in weekend ramane zi de weekend (nu se scade de doua ori).
 *
 * Cache-ul e local (legal_holidays) si sincronizarea e pe AN: cat timp anul are un
 * sync reusit, navigarea intre lunile lui nu mai face niciun request extern. Un sync
 * esuat nu sterge cache-ul; fara cache, calendarul e marcat incomplet si numarul de
 * zile lucratoare NU se afiseaza (nu presupunem zero sarbatori).
 *
 * Zilele lucratoare legale NU sunt zilele lucrate de un angajat.
 */
class LegalCalendarService
{
    public const COUNTRY = 'RO';
    public const SOURCE = 'nager_date';
    private const DEFAULT_API_BASE = 'https://date.nager.at/api/v3/PublicHolidays';
    /** Dupa un sync esuat nu mai incercam la fiecare refresh. */
    private const RETRY_AFTER_FAILURE_SECONDS = 1800;
    /** Anul curent / viitor se reverifica rar: guvernul poate adauga o zi libera. */
    private const REFRESH_AFTER_DAYS = 90;

    public const MONTH_NAMES = [
        1 => 'Ianuarie', 2 => 'Februarie', 3 => 'Martie', 4 => 'Aprilie', 5 => 'Mai', 6 => 'Iunie',
        7 => 'Iulie', 8 => 'August', 9 => 'Septembrie', 10 => 'Octombrie', 11 => 'Noiembrie', 12 => 'Decembrie',
    ];
    public const MONTH_SHORT = [
        1 => 'Ian', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mai', 6 => 'Iun',
        7 => 'Iul', 8 => 'Aug', 9 => 'Sept', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
    ];

    private PDO $db;
    /** @var array<int, array<string, array<string, mixed>>> an => [Y-m-d => sarbatoare] */
    private array $holidaysByYear = [];
    /** @var array<int, array<string, mixed>> an => starea sincronizarii */
    private array $yearState = [];
    private static bool $schemaReady = false;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureSchema();
    }

    /**
     * Garanteaza ca anul are sarbatorile in cache (sincronizeaza doar daca e nevoie).
     *
     * @return array{complete: bool, source: string, synced_at: ?string, error: ?string}
     */
    public function ensureYear(int $year): array
    {
        if (isset($this->yearState[$year])) {
            return $this->yearState[$year];
        }

        $sync = $this->findSyncRow($year);
        $hasCache = $this->countCachedHolidays($year) > 0;
        $lastOkSync = $sync !== null && $sync['synced_at'] !== null ? (string) $sync['synced_at'] : null;

        $needsSync = !$hasCache;
        if ($hasCache && $lastOkSync !== null && $year >= (int) date('Y')) {
            $needsSync = strtotime($lastOkSync) < time() - self::REFRESH_AFTER_DAYS * 86400;
        }
        // Ultima incercare a esuat de curand: nu lovim API-ul la fiecare refresh.
        if ($needsSync && $sync !== null && ($sync['last_error'] ?? null) !== null
            && strtotime((string) $sync['last_attempt_at']) > time() - self::RETRY_AFTER_FAILURE_SECONDS) {
            $needsSync = false;
        }

        $error = $sync !== null && ($sync['last_error'] ?? null) !== null ? (string) $sync['last_error'] : null;
        $source = $hasCache ? 'cache' : 'none';

        if ($needsSync) {
            try {
                $holidays = $this->fetchFromApi($year);
                $this->storeYear($year, $holidays);
                $hasCache = true;
                $source = 'api';
                $error = null;
                $lastOkSync = date('Y-m-d H:i:s');
            } catch (Throwable $exception) {
                $error = mb_substr($exception->getMessage(), 0, 255);
                error_log('[LegalCalendarService] Sincronizare ' . self::COUNTRY . ' ' . $year . ' esuata: ' . $error);
                $this->recordFailure($year, $error);
            }
        }

        return $this->yearState[$year] = [
            'complete' => $hasCache,
            'source' => $source,
            'synced_at' => $lastOkSync,
            'error' => $hasCache ? null : ($error ?: 'Sărbătorile legale nu au fost încă descărcate.'),
        ];
    }

    public function isWeekend(string $date): bool
    {
        return (int) date('N', strtotime($date)) >= 6;
    }

    public function getHoliday(string $date): ?array
    {
        $year = (int) substr($date, 0, 4);
        return $this->holidaysForYear($year)[$date] ?? null;
    }

    public function isLegalHoliday(string $date): bool
    {
        return $this->getHoliday($date) !== null;
    }

    /** Zi lucratoare legala = Luni-Vineri si nu sarbatoare legala. */
    public function isWorkingDay(string $date): bool
    {
        return !$this->isWeekend($date) && !$this->isLegalHoliday($date);
    }

    /** Numarul de zile lucratoare legale; null cand calendarul anului nu e complet. */
    public function getWorkingDays(int $year, int $month): ?int
    {
        return $this->getMonthSummary($year, $month)['working_days'];
    }

    /**
     * Rezumatul unei luni, zi cu zi.
     *
     * working_days + weekend_days + holiday_days = calendar_days; o sarbatoare din
     * weekend se numara o singura data (ca weekend) si apare in holidays_on_weekend.
     */
    public function getMonthSummary(int $year, int $month): array
    {
        $state = $this->ensureYear($year);
        $holidays = $state['complete'] ? $this->holidaysForYear($year) : [];

        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $daysInMonth = (int) $first->format('t');
        $days = [];
        $working = 0;
        $weekend = 0;
        $holidayDays = 0;
        $holidaysOnWeekend = 0;
        $holidayList = [];

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = $first->setDate($year, $month, $d);
            $iso = $date->format('Y-m-d');
            $weekday = (int) $date->format('N');
            $isWeekend = $weekday >= 6;
            $holiday = $holidays[$iso] ?? null;

            if ($isWeekend) {
                $status = 'weekend';
                $weekend++;
                if ($holiday !== null) {
                    $holidaysOnWeekend++;
                }
            } elseif ($holiday !== null) {
                $status = 'holiday';
                $holidayDays++;
            } else {
                $status = 'working';
                $working++;
            }

            if ($holiday !== null) {
                $holidayList[] = ['date' => $iso, 'local_name' => (string) $holiday['local_name'], 'name' => (string) $holiday['name'], 'on_weekend' => $isWeekend];
            }

            $days[] = [
                'date' => $iso,
                'day' => $d,
                'weekday' => $weekday,
                'status' => $status,
                'is_weekend' => $isWeekend,
                'holiday' => $holiday !== null ? (string) $holiday['local_name'] : null,
            ];
        }

        return [
            'year' => $year,
            'month' => $month,
            'label' => self::MONTH_NAMES[$month] . ' ' . $year,
            'short_label' => self::MONTH_SHORT[$month] . ' ' . $year,
            'period' => $first->format('Y-m-01'),
            'first_weekday' => (int) $first->format('N'),
            'calendar_days' => $daysInMonth,
            'working_days' => $state['complete'] ? $working : null,
            'weekend_days' => $weekend,
            'holiday_days' => $state['complete'] ? $holidayDays : null,
            'holidays_on_weekend' => $state['complete'] ? $holidaysOnWeekend : null,
            'holidays' => $holidayList,
            'days' => $days,
            'complete' => $state['complete'],
            'sync' => $state,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function holidaysForYear(int $year): array
    {
        if (isset($this->holidaysByYear[$year])) {
            return $this->holidaysByYear[$year];
        }

        $stmt = $this->db->prepare('
            SELECT holiday_date, name, local_name
            FROM legal_holidays
            WHERE country_code = :country AND year = :year
        ');
        $stmt->execute([':country' => self::COUNTRY, ':year' => $year]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['holiday_date']] = $row;
        }

        return $this->holidaysByYear[$year] = $map;
    }

    /**
     * Descarca si valideaza sarbatorile unui an. Arunca exceptie la orice anomalie:
     * un raspuns suspect nu are voie sa inlocuiasca un cache bun.
     *
     * @return array<string, array{name: string, local_name: string}> Y-m-d => sarbatoare
     */
    private function fetchFromApi(int $year): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Extensia cURL nu este disponibilă.');
        }

        $base = rtrim((string) (getenv('NAGER_DATE_API_BASE') ?: self::DEFAULT_API_BASE), '/');
        $url = $base . '/' . $year . '/' . self::COUNTRY;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'FleetApp-ContabilitatePersonal/1.0',
        ]);
        $body = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Nager.Date indisponibil: ' . $curlError);
        }
        if ($httpCode !== 200) {
            throw new RuntimeException('Nager.Date a răspuns cu HTTP ' . $httpCode . '.');
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data) || $data === []) {
            throw new RuntimeException('Răspuns Nager.Date invalid (JSON gol sau corupt).');
        }

        $holidays = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Răspuns Nager.Date invalid (element neașteptat).');
            }
            $date = (string) ($item['date'] ?? '');
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date || (int) $parsed->format('Y') !== $year) {
                throw new RuntimeException('Răspuns Nager.Date invalid (dată: ' . mb_substr($date, 0, 20) . ').');
            }
            if (strtoupper((string) ($item['countryCode'] ?? '')) !== self::COUNTRY) {
                throw new RuntimeException('Răspuns Nager.Date pentru altă țară.');
            }

            // Doar sarbatorile nationale publice (fara cele regionale / de bancă).
            $types = is_array($item['types'] ?? null) ? $item['types'] : [];
            if (($item['global'] ?? true) !== true || ($types !== [] && !in_array('Public', $types, true))) {
                continue;
            }

            $localName = trim((string) ($item['localName'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($localName === '' && $name === '') {
                throw new RuntimeException('Răspuns Nager.Date invalid (sărbătoare fără nume).');
            }

            // Doua sarbatori in aceeasi zi (ex. 1 iunie: Ziua Copilului + Rusalii) = o singura zi libera.
            if (isset($holidays[$date])) {
                $holidays[$date]['local_name'] = $this->joinNames($holidays[$date]['local_name'], $localName);
                $holidays[$date]['name'] = $this->joinNames($holidays[$date]['name'], $name);
                continue;
            }
            $holidays[$date] = ['local_name' => $localName !== '' ? $localName : $name, 'name' => $name !== '' ? $name : $localName];
        }

        // Romania are in jur de 15-17 zile libere legale pe an; altceva inseamna date suspecte.
        if (count($holidays) < 5 || count($holidays) > 40) {
            throw new RuntimeException('Răspuns Nager.Date neplauzibil: ' . count($holidays) . ' sărbători pentru ' . $year . '.');
        }

        return $holidays;
    }

    private function joinNames(string $existing, string $extra): string
    {
        if ($extra === '' || mb_stripos($existing, $extra) !== false) {
            return $existing;
        }

        return mb_substr($existing . ' / ' . $extra, 0, 190);
    }

    /** Inlocuieste atomic anul in cache, doar dupa ce raspunsul a fost validat. */
    private function storeYear(int $year, array $holidays): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $upsert = $this->db->prepare('
                INSERT INTO legal_holidays (holiday_date, name, local_name, country_code, year, source, created_at, updated_at)
                VALUES (:holiday_date, :name, :local_name, :country, :year, :source, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    local_name = VALUES(local_name),
                    year = VALUES(year),
                    source = VALUES(source),
                    updated_at = VALUES(updated_at)
            ');
            foreach ($holidays as $date => $holiday) {
                $upsert->execute([
                    ':holiday_date' => $date,
                    ':name' => mb_substr($holiday['name'], 0, 190),
                    ':local_name' => mb_substr($holiday['local_name'], 0, 190),
                    ':country' => self::COUNTRY,
                    ':year' => $year,
                    ':source' => self::SOURCE,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }

            // Zilele scoase din lista oficiala dispar din cache (doar dupa un raspuns valid).
            $dates = array_keys($holidays);
            $placeholders = implode(',', array_fill(0, count($dates), '?'));
            $delete = $this->db->prepare("DELETE FROM legal_holidays WHERE country_code = ? AND year = ? AND holiday_date NOT IN ($placeholders)");
            $delete->execute(array_merge([self::COUNTRY, $year], $dates));

            $sync = $this->db->prepare("
                INSERT INTO legal_holiday_sync (country_code, year, status, holiday_count, synced_at, last_attempt_at, last_error)
                VALUES (:country, :year, 'ok', :holiday_count, :synced_at, :last_attempt_at, NULL)
                ON DUPLICATE KEY UPDATE
                    status = 'ok',
                    holiday_count = VALUES(holiday_count),
                    synced_at = VALUES(synced_at),
                    last_attempt_at = VALUES(last_attempt_at),
                    last_error = NULL
            ");
            $sync->execute([
                ':country' => self::COUNTRY,
                ':year' => $year,
                ':holiday_count' => count($holidays),
                ':synced_at' => $now,
                ':last_attempt_at' => $now,
            ]);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        unset($this->holidaysByYear[$year]);
    }

    /** Noteaza incercarea esuata fara sa atinga sarbatorile deja salvate. */
    private function recordFailure(int $year, string $error): void
    {
        try {
            $now = date('Y-m-d H:i:s');
            $stmt = $this->db->prepare("
                INSERT INTO legal_holiday_sync (country_code, year, status, holiday_count, synced_at, last_attempt_at, last_error)
                VALUES (:country, :year, 'error', 0, NULL, :last_attempt_at, :last_error)
                ON DUPLICATE KEY UPDATE
                    status = IF(synced_at IS NULL, 'error', status),
                    last_attempt_at = VALUES(last_attempt_at),
                    last_error = VALUES(last_error)
            ");
            $stmt->execute([
                ':country' => self::COUNTRY,
                ':year' => $year,
                ':last_attempt_at' => $now,
                ':last_error' => mb_substr($error, 0, 255),
            ]);
        } catch (Throwable $exception) {
            error_log('[LegalCalendarService] Nu am putut salva starea sync: ' . $exception->getMessage());
        }
    }

    private function findSyncRow(int $year): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM legal_holiday_sync WHERE country_code = :country AND year = :year LIMIT 1');
        $stmt->execute([':country' => self::COUNTRY, ':year' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function countCachedHolidays(int $year): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM legal_holidays WHERE country_code = :country AND year = :year');
        $stmt->execute([':country' => self::COUNTRY, ':year' => $year]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Migrarea oficiala: database/migrations/2026_09_23_000001_contabilitate_personal_calendar_luna.sql.
     * Aici doar tinem pagina in picioare pe o baza inca nemigrata.
     */
    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS legal_holidays (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                holiday_date DATE NOT NULL,
                name VARCHAR(190) NOT NULL,
                local_name VARCHAR(190) NOT NULL,
                country_code CHAR(2) NOT NULL,
                year SMALLINT UNSIGNED NOT NULL,
                source VARCHAR(30) NOT NULL DEFAULT 'nager_date',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uk_legal_holidays_country_date (country_code, holiday_date),
                KEY idx_legal_holidays_country_year (country_code, year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS legal_holiday_sync (
                country_code CHAR(2) NOT NULL,
                year SMALLINT UNSIGNED NOT NULL,
                status ENUM('ok','error') NOT NULL,
                holiday_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                synced_at DATETIME NULL,
                last_attempt_at DATETIME NOT NULL,
                last_error VARCHAR(255) NULL,
                PRIMARY KEY (country_code, year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }
}
