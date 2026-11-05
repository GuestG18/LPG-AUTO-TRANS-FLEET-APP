<?php
declare(strict_types=1);

/**
 * Km din CAN (odometrul de bord citit de pe magistrala CAN) din SAS Fleet.
 *
 * API-ul public SAS NU are odometrul CAN (travelsheet da doar kmIndex = odometrul GPS,
 * necalibrat pe bord). Raportul web „Foaie de parcurs cu distanțe din CAN”
 * (SASGRecordSheetCan.aspx) il are: Index CAN pornire/oprire + Distanta CAN pe fiecare
 * tronson. Raportul e HTML; aici se cere cu SessionId-ul de rapoarte si se transforma in JSON.
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

        $html = $this->client->fetchWebReport('SASGRecordSheetCan.aspx', [
            'CarId' => $carId,
            'DateStartEx' => $start->format('H:i:s@m/d/Y'),
            'DateEndEx' => $end->format('H:i:s@m/d/Y'),
            'IsDateEx' => 'True',
            'RouteType' => 0,
        ]);

        $report = self::parseRecordSheetCan($html);
        $report['car_id'] = $carId;
        $report['from'] = $start->format('Y-m-d H:i:s');
        $report['to'] = $end->format('Y-m-d H:i:s');

        return $report;
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
