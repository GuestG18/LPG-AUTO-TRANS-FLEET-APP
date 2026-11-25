<?php
declare(strict_types=1);

/**
 * Km din CAN (odometrul de bord citit de pe magistrala CAN) din SAS Fleet.
 *
 * Sursa principala (din 2026-11-25): API-ul public api/reports/travelsheet, care are acum
 * pe fiecare tronson odometerCanStart / odometerCanEnd (pe langa kmIndex = odometrul GPS).
 * Rezerva: raportul web „Foaie de parcurs cu distanțe din CAN” (SASGRecordSheetCan.aspx, HTML
 * cerut cu SessionId-ul de rapoarte), folosit doar daca API-ul cade sau nu mai trimite campurile CAN.
 * Ambele surse dau acelasi format JSON (vezi fromApiTravelSheet / parseRecordSheetCan).
 *
 * CarId din raport = carId din API (ex. 9118 = B 105 NET).
 */
class SasCanKmService
{
    /** Raportul SAS pe intervale foarte lungi devine lent; apelantul imparte pe bucati. */
    public const MAX_DAYS = 31;

    private const MONTHS = [
        'ian' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mai' => 5, 'iun' => 6,
        'iul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'noi' => 11, 'nov' => 11, 'dec' => 12,
    ];

    /** Antetul din raport -> cheia din JSON. Coloanele se cauta dupa nume, nu dupa pozitie. */
    private const COLUMNS = [
        'tronson' => 'segment',
        'ora pornire' => 'start',
        'locatie pornire' => 'start_location',
        'ora oprire' => 'end',
        'locatie oprire' => 'end_location',
        'viteza med (km/h)' => 'avg_speed_kmh',
        'distanta parcursa (km)' => 'gps_km',
        'index gps oprire (km)' => 'gps_index_end_km',
        'index can pornire (km)' => 'can_index_start_km',
        'index can oprire (km)' => 'can_index_end_km',
        'distanta can (km)' => 'can_km',
        'durata functionare motor (min)' => 'engine_minutes',
        'durata stationare cu motor pornit (min)' => 'idle_minutes',
        'durata stationare dupa oprire (min)' => 'parked_after_minutes',
        'observatii' => 'notes',
    ];

    private const TEXT_COLUMNS = ['start_location', 'end_location', 'notes'];
    private const DATE_COLUMNS = ['start', 'end'];

    private SasFleetClient $client;

    public function __construct(?SasFleetClient $client = null)
    {
        $this->client = $client ?? new SasFleetClient();
    }

    /**
     * Foaia de parcurs CAN pentru o masina SAS intre $from si $to (ora locala, 'Y-m-d H:i[:s]').
     */
    public function travelSheet(int $carId, string $from, string $to): array
    {
        $start = self::parseInputDate($from, false);
        $end = self::parseInputDate($to, true);
        if ($carId <= 0) {
            throw new InvalidArgumentException('Vehiculul nu are corespondent în SAS Fleet.');
        }
        if ($end <= $start) {
            throw new InvalidArgumentException('Data de sfârșit trebuie să fie după data de început.');
        }
        if ($start->diff($end)->days >= self::MAX_DAYS) {
            throw new InvalidArgumentException('Intervalul maxim este de ' . self::MAX_DAYS . ' de zile.');
        }

        $report = null;
        $apiError = null;
        try {
            $sheet = $this->client->getTravelSheet($carId, $start->format('Y-m-d\TH:i:s'), $end->format('Y-m-d\TH:i:s'));
            if (self::apiHasCanOdometer($sheet)) {
                $report = self::fromApiTravelSheet($sheet);
                $report['source'] = 'api';
            }
        } catch (RuntimeException $e) {
            // 503 (prea multe cereri) si autentificarea esuata ajung la apelant: raportul web ar pica la fel.
            if ($e instanceof SasUnauthorizedException || str_starts_with($e->getMessage(), 'SAS HTTP 503')) {
                throw $e;
            }
            $apiError = $e;
        }

        if ($report === null) {
            try {
                $report = $this->fromWebReport($carId, $start, $end);
            } catch (RuntimeException $e) {
                throw $apiError ?? $e;
            }
            $report['source'] = 'web_report';
        }

        $report['car_id'] = $carId;
        $report['from'] = $start->format('Y-m-d H:i:s');
        $report['to'] = $end->format('Y-m-d H:i:s');

        return $report;
    }

    /**
     * Rezerva: raportul web HTML (SASGRecordSheetCan.aspx), sursa unica pana la 2026-11-25.
     */
    private function fromWebReport(int $carId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $html = $this->client->fetchWebReport('SASGRecordSheetCan.aspx', [
            'CarId' => $carId,
            'DateStartEx' => $start->format('H:i:s@m/d/Y'),
            'DateEndEx' => $end->format('H:i:s@m/d/Y'),
            'IsDateEx' => 'True',
            'RouteType' => 0,
        ]);

        return self::parseRecordSheetCan($html);
    }

    /**
     * API-ul trimite campurile CAN pe tronsoane? Fara tronsoane (masina a stat) raspunsul
     * e valid oricum: nu e nimic de citit nici din raportul web.
     */
    public static function apiHasCanOdometer(array $sheet): bool
    {
        $segments = (array) ($sheet['segments'] ?? []);
        if ($segments === []) {
            return true;
        }
        foreach ($segments as $segment) {
            if (is_array($segment) && (array_key_exists('odometerCanStart', $segment) || array_key_exists('odometerCanEnd', $segment))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Raspunsul api/reports/travelsheet -> acelasi format ca parseRecordSheetCan. Functie pura.
     */
    public static function fromApiTravelSheet(array $sheet): array
    {
        $segments = [];
        $workSeconds = 0;
        foreach ((array) ($sheet['segments'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $start = self::apiDate($item['dateStart'] ?? null);
            // Tronsoanele de durata 0 fara distanta (doar puncte „fake”) lipsesc si din raportul web.
            if ($start === null || ($item['distance'] === null && ($item['dateStart'] ?? null) === ($item['dateEnd'] ?? null))) {
                continue;
            }
            $canStart = self::positiveOrNull($item['odometerCanStart'] ?? null);
            $canEnd = self::positiveOrNull($item['odometerCanEnd'] ?? null);
            $workSeconds += (int) ($item['workSeconds'] ?? 0);
            $segments[] = [
                'segment' => isset($item['indexId']) ? (int) $item['indexId'] : count($segments) + 1,
                'start' => $start,
                'start_location' => self::apiLocation($item['locationDescriptionStart'] ?? null, $item['addressStart'] ?? null, $item['cityStart'] ?? null, $item['countyStart'] ?? null),
                'end' => self::apiDate($item['dateEnd'] ?? null),
                'end_location' => self::apiLocation($item['locationDescriptionEnd'] ?? null, $item['addressEnd'] ?? null, $item['cityEnd'] ?? null, $item['countyEnd'] ?? null),
                'avg_speed_kmh' => self::roundOrNull($item['averageSpeed'] ?? null, 1),
                'gps_km' => self::roundOrNull($item['distance'] ?? null, 1),
                'gps_index_end_km' => self::roundOrNull(self::positiveOrNull($item['kmIndex'] ?? null), 1),
                'can_index_start_km' => self::roundOrNull($canStart, 1),
                'can_index_end_km' => self::roundOrNull($canEnd, 1),
                // Ca in raportul web: diferenta neta, si negativa cand CAN-ul are sarituri (ex. B 430 NET).
                'can_km' => $canStart !== null && $canEnd !== null ? round($canEnd - $canStart, 1) : null,
                'engine_minutes' => isset($item['workSeconds']) ? (int) round($item['workSeconds'] / 60) : null,
                'idle_minutes' => isset($item['idleEngineSeconds']) ? (int) round($item['idleEngineSeconds'] / 60) : null,
                'parked_after_minutes' => isset($item['parkTimeSeconds']) ? (int) round($item['parkTimeSeconds'] / 60) : null,
                'notes' => null,
            ];
        }

        $canStarts = array_values(array_filter(array_column($segments, 'can_index_start_km'), static fn ($v) => $v !== null));
        $canEnds = array_values(array_filter(array_column($segments, 'can_index_end_km'), static fn ($v) => $v !== null));
        // „Total distanta CAN” din raportul web = ultimul index CAN - primul (nu suma tronsoanelor).
        $canKmFromIndex = $canStarts !== [] && $canEnds !== [] ? round(end($canEnds) - $canStarts[0], 1) : null;
        $fuel = is_numeric($sheet['CANFuelUsed'] ?? null) ? (float) $sheet['CANFuelUsed'] : null;
        $make = trim((string) ($sheet['makeName'] ?? ''));
        $modelName = trim((string) ($sheet['modelName'] ?? ''));

        return [
            'plate' => ($sheet['licensePlate'] ?? '') !== '' ? (string) $sheet['licensePlate'] : null,
            'model' => $make !== '' || $modelName !== '' ? $make . '/' . $modelName : null,
            'segments' => $segments,
            'totals' => [
                'gps_km' => self::roundOrNull($sheet['totalDistance'] ?? null, 1),
                'can_km' => $canKmFromIndex,
                'can_km_from_index' => $canKmFromIndex,
                'can_index_start_km' => $canStarts[0] ?? null,
                'can_index_end_km' => $canEnds !== [] ? end($canEnds) : null,
                'fuel_l' => $fuel,
                'fuel_l_per_100km' => self::roundOrNull($sheet['CANFuelUsedPer100Km'] ?? null, 2),
                'fuel_l_per_hour' => $fuel !== null && $workSeconds > 0 ? round($fuel / ($workSeconds / 3600), 2) : null,
                'segments' => count($segments),
            ],
            'has_can' => $canEnds !== [],
        ];
    }

    /** „2026-11-24T07:09:40” -> „2026-11-24 07:09:40” (ora locala, ca in raportul web). */
    private static function apiDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $value, $m)) {
            return null;
        }

        return $m[1] . ' ' . $m[2];
    }

    /** Ca in raportul web: „Nume POI sau adresa/oras/judet”. */
    private static function apiLocation(mixed $description, mixed $address, mixed $city, mixed $county): ?string
    {
        $first = trim((string) ($description ?? ''));
        if ($first === '') {
            $first = trim((string) ($address ?? ''));
        }
        $parts = [$first, trim((string) ($city ?? '')), trim((string) ($county ?? ''))];
        if (implode('', $parts) === '') {
            return null;
        }

        return implode('/', $parts);
    }

    private static function positiveOrNull(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private static function roundOrNull(mixed $value, int $precision): ?float
    {
        return is_numeric($value) ? round((float) $value, $precision) : null;
    }

    /**
     * Transforma HTML-ul SASGRecordSheetCan.aspx in date. Functie pura (testata pe fixturi).
     */
    public static function parseRecordSheetCan(string $html): array
    {
        if (stripos($html, 'id="tblReport"') === false) {
            // Pagina de eroare ASP.NET sau sesiune expirata: nu e un raport gol.
            $title = preg_match('~<title>(.*?)</title>~is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : '';
            throw new RuntimeException('Raportul CAN SAS nu a putut fi citit' . ($title !== '' ? ': ' . $title : '.'));
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);

        $title = trim((string) $xpath->evaluate('string(//title)'));
        $plate = preg_match('/autovehiculul\s+(.+?)\s*\(/u', $title, $m) ? trim($m[1]) : null;
        $model = preg_match('/marca\/model:\s*([^)]*)\)/u', $title, $m) ? trim($m[1]) : null;

        $headerKeys = [];
        foreach ($xpath->query('//table[@id="tblReport"]//tr[th][1]/th') as $index => $th) {
            $label = self::normalizeLabel($th->textContent);
            $headerKeys[$index] = self::COLUMNS[$label] ?? null;
        }
        if (!in_array('can_index_end_km', $headerKeys, true)) {
            throw new RuntimeException('Raportul SAS nu mai are coloana „Index CAN oprire” (format schimbat).');
        }

        $segments = [];
        foreach ($xpath->query('//table[@id="tblReport"]//tr[td]') as $tr) {
            $cells = $xpath->query('./td', $tr);
            if ($cells->length < count($headerKeys)) {
                continue;
            }
            $row = [];
            foreach ($headerKeys as $index => $key) {
                if ($key === null) {
                    continue;
                }
                $text = self::cleanText($cells->item($index)->textContent);
                $row[$key] = match (true) {
                    in_array($key, self::TEXT_COLUMNS, true) => $text !== '' ? $text : null,
                    in_array($key, self::DATE_COLUMNS, true) => self::parseRoDate($text),
                    default => self::parseRoNumber($text),
                };
            }
            if (($row['start'] ?? null) === null) {
                continue;
            }
            $row['segment'] = $row['segment'] !== null ? (int) $row['segment'] : count($segments) + 1;
            foreach (['engine_minutes', 'idle_minutes', 'parked_after_minutes'] as $minutes) {
                if (isset($row[$minutes])) {
                    $row[$minutes] = (int) round($row[$minutes]);
                }
            }
            $segments[] = $row;
        }

        $canStarts = array_values(array_filter(array_column($segments, 'can_index_start_km'), static fn ($v) => $v !== null && $v > 0));
        $canEnds = array_values(array_filter(array_column($segments, 'can_index_end_km'), static fn ($v) => $v !== null && $v > 0));

        return [
            'plate' => $plate,
            'model' => $model,
            'segments' => $segments,
            'totals' => [
                'gps_km' => self::spanNumber($xpath, 'lblTotalDistance'),
                'can_km' => self::spanNumber($xpath, 'lblTotalDistanceCan'),
                'can_km_from_index' => $canStarts !== [] && $canEnds !== [] ? round(end($canEnds) - $canStarts[0], 1) : null,
                'can_index_start_km' => $canStarts[0] ?? null,
                'can_index_end_km' => $canEnds !== [] ? end($canEnds) : null,
                'fuel_l' => self::spanNumber($xpath, 'lblByCANTotalFuelConsumptionIntegral'),
                'fuel_l_per_100km' => self::spanNumber($xpath, 'lblByCANTotalFuelConsumption'),
                'fuel_l_per_hour' => self::spanNumber($xpath, 'lblByCANFuelConsumptionPerHour'),
                'segments' => count($segments),
            ],
            'has_can' => $canEnds !== [],
        ];
    }

    /** Numerele din raport sunt in format ro-RO: 1.513.681,5. „-” sau gol = lipsa. */
    public static function parseRoNumber(string $text): ?float
    {
        $text = str_replace(["\u{00A0}", ' '], '', trim($text));
        if ($text === '' || !preg_match('/^-?[\d.]+(,\d+)?$/', $text)) {
            return null;
        }

        return (float) str_replace(['.', ','], ['', '.'], $text);
    }

    /** „30 oct. 2026 07:49:42” -> „2026-10-30 07:49:42”. */
    public static function parseRoDate(string $text): ?string
    {
        if (!preg_match('/^(\d{1,2})\s+([[:alpha:]ăâîșşțţ]+)\.?\s+(\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$/iu', trim($text), $m)) {
            return null;
        }
        $month = self::MONTHS[mb_substr(mb_strtolower($m[2]), 0, 3)] ?? null;
        if ($month === null) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int) $m[3], $month, (int) $m[1], (int) $m[4], (int) $m[5], (int) ($m[6] ?? 0));
    }

    private static function parseInputDate(string $value, bool $endOfDay): DateTimeImmutable
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date;
            }
        }
        throw new InvalidArgumentException('Dată invalidă: „' . $value . '” (format Y-m-d sau Y-m-d H:i).');
    }

    private static function spanNumber(DOMXPath $xpath, string $id): ?float
    {
        $text = (string) $xpath->evaluate('string(//*[@id="' . $id . '"])');
        // „Total distanta CAN: 55,1 km” / „Consum realizat citit: 45,05 l/100km” -> primul numar dupa „:”
        return preg_match('/:\s*(-?\d[\d.]*(?:,\d+)?)/u', $text, $m) ? self::parseRoNumber($m[1]) : null;
    }

    private static function normalizeLabel(string $text): string
    {
        return mb_strtolower(self::cleanText($text));
    }

    private static function cleanText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
