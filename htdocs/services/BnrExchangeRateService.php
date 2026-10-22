<?php
declare(strict_types=1);

/**
 * Sincronizarea cursului EUR/RON din fișierele XML oficiale BNR.
 *
 *   https://curs.bnr.ro/nbrfxrates.xml                    ultima zi publicată
 *   https://curs.bnr.ro/nbrfxrates10days.xml              ultimele 10 zile publicate
 *   https://curs.bnr.ro/files/xml/years/nbrfxrates{AN}.xml tot anul
 *
 * Structura verificată la 21.10.2026: <DataSet xmlns="https://www.bnr.ro/xsd">
 * … <Body><Cube date="Y-m-d"><Rate currency="EUR" [multiplier="100"]>5.3285</Rate>.
 * Parserul ignoră namespace-ul (local-name), ca o schimbare http/https să nu-l rupă.
 *
 * Reguli:
 *   - doar zilele publicate de BNR; weekendul / sărbătorile NU se completează;
 *   - o zi deja salvată nu se suprascrie (INSERT IGNORE), diferențele se semnalează;
 *   - eșecul BNR nu atinge datele existente — se scrie doar în jurnal;
 *   - o singură rulare simultan (GET_LOCK în MariaDB, funcționează și pe Windows);
 *   - fiecare cerere HTTP are 3 încercări cu pauză crescătoare.
 */
class BnrExchangeRateService
{
    public const BASE_URL = 'https://curs.bnr.ro';
    public const CURRENCIES = ['EUR'];
    /** Câtă istorie ținem la zi: graficul „1A” + fereastra de calcul. */
    public const HISTORY_MONTHS = 13;
    private const LOCK_NAME = 'fleet_bnr_sync';

    private FuelFxAdjustmentModel $model;
    private PDO $db;
    /** @var callable|null fn(string $url): string — injectabil în teste */
    private $fetcher;

    public function __construct(PDO $db, ?FuelFxAdjustmentModel $model = null, ?callable $fetcher = null)
    {
        $this->db = $db;
        $this->model = $model ?? new FuelFxAdjustmentModel($db);
        $this->fetcher = $fetcher;
    }

    public static function yearUrl(int $year): string
    {
        return self::BASE_URL . '/files/xml/years/nbrfxrates' . $year . '.xml';
    }

    /**
     * Extrage cursurile monedelor urmărite dintr-un XML BNR.
     *
     * @return array<int,array{currency:string, date:string, rate:float, multiplier:int}>
     * @throws RuntimeException pe XML invalid sau fără niciun Cube
     */
    public static function parseXml(string $xml, array $currencies = self::CURRENCIES): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            throw new RuntimeException('Răspunsul BNR nu este un XML valid.');
        }

        $cubes = $doc->xpath('//*[local-name()="Cube"][@date]') ?: [];
        if ($cubes === []) {
            throw new RuntimeException('XML-ul BNR nu conține nicio zi publicată (Cube).');
        }

        $wanted = array_flip(array_map('strtoupper', $currencies));
        $rates = [];
        foreach ($cubes as $cube) {
            $date = (string) $cube['date'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }
            foreach ($cube->xpath('./*[local-name()="Rate"]') ?: [] as $rateNode) {
                $currency = strtoupper((string) $rateNode['currency']);
                if (!isset($wanted[$currency])) {
                    continue;
                }
                $raw = trim((string) $rateNode);
                if (!is_numeric($raw) || (float) $raw <= 0) {
                    continue;
                }
                $rates[] = [
                    'currency' => $currency,
                    'date' => $date,
                    'rate' => (float) $raw,
                    'multiplier' => max(1, (int) ((string) $rateNode['multiplier'] ?: 1)),
                ];
            }
        }

        return $rates;
    }

    /**
     * O rulare completă. Alege singur sursa: anii lipsă la prima rulare sau după
     * o pauză lungă, altfel fișierul pe 10 zile.
     *
     * @return array{status:string, message:string, inserted:int, received:int, latest_rate_date:?string}
     */
    public function sync(string $triggeredBy = 'cron', ?int $userId = null, bool $forceYears = false): array
    {
        $locked = (int) $this->db->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn() === 1;
        if (!$locked) {
            $logId = $this->model->startSyncLog($triggeredBy, $userId);
            $this->model->finishSyncLog($logId, 'skipped', ['message' => 'O altă sincronizare BNR este în curs.']);

            return ['status' => 'skipped', 'message' => 'O altă sincronizare BNR este în curs.', 'inserted' => 0, 'received' => 0, 'latest_rate_date' => null];
        }

        $logId = $this->model->startSyncLog($triggeredBy, $userId);
        $urls = $this->planUrls($forceYears);
        $received = 0;
        $inserted = 0;
        $conflicts = 0;
        $errors = [];

        try {
            foreach ($urls as $label => $url) {
                try {
                    $rates = self::parseXml($this->fetch($url));
                    $received += count($rates);
                    $stored = $this->model->storeRates($rates, $label);
                    $inserted += $stored['inserted'];
                    $conflicts += $stored['conflicts'];
                } catch (Throwable $exception) {
                    $errors[] = $label . ': ' . $exception->getMessage();
                    error_log('[BnrExchangeRateService] ' . $label . ': ' . $exception->getMessage());
                }
            }

            $latest = $this->model->getLatestRate('EUR');
            $status = $errors === [] ? 'success' : ($received > 0 ? 'success' : 'error');
            $message = $errors === []
                ? sprintf('%d cursuri primite, %d noi.', $received, $inserted)
                : implode(' | ', $errors);
            if ($conflicts > 0) {
                $message .= sprintf(' %d zile au alt curs decât cel salvat — valoarea salvată a fost păstrată.', $conflicts);
            }

            $this->model->finishSyncLog($logId, $status, [
                'sources' => implode(', ', array_keys($urls)),
                'received' => $received,
                'inserted' => $inserted,
                'conflicts' => $conflicts,
                'latest_rate_date' => $latest['rate_date'] ?? null,
                'message' => $message,
            ]);

            return [
                'status' => $status,
                'message' => $message,
                'inserted' => $inserted,
                'received' => $received,
                'latest_rate_date' => $latest['rate_date'] ?? null,
            ];
        } catch (Throwable $exception) {
            $this->model->finishSyncLog($logId, 'error', ['message' => $exception->getMessage()]);

            return ['status' => 'error', 'message' => $exception->getMessage(), 'inserted' => $inserted, 'received' => $received, 'latest_rate_date' => null];
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /** @return array<string,string> eticheta sursei → URL */
    private function planUrls(bool $forceYears): array
    {
        $today = new DateTimeImmutable('today');
        $needFrom = $today->modify('-' . self::HISTORY_MONTHS . ' months');
        $earliest = $this->model->getEarliestRateDate('EUR');
        $latest = $this->model->getLatestRate('EUR');

        $fromYear = null;
        if ($forceYears || $earliest === null || $earliest > $needFrom->format('Y-m-d')) {
            $fromYear = (int) $needFrom->format('Y');
        } elseif ($latest !== null && $latest['rate_date'] < $today->modify('-12 days')->format('Y-m-d')) {
            // Pauză mai lungă decât acoperă fișierul pe 10 zile publicate.
            $fromYear = (int) substr($latest['rate_date'], 0, 4);
        }

        $urls = [];
        if ($fromYear !== null) {
            for ($year = $fromYear; $year <= (int) $today->format('Y'); $year++) {
                $urls['an ' . $year] = self::yearUrl($year);
            }
        }
        $urls['10 zile'] = self::BASE_URL . '/nbrfxrates10days.xml';

        return $urls;
    }

    private function fetch(string $url): string
    {
        if ($this->fetcher !== null) {
            return (string) ($this->fetcher)($url);
        }

        $lastError = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            [$body, $status, $error] = $this->httpGet($url);
            if ($body !== null && $status === 200 && $body !== '') {
                return $body;
            }
            $lastError = $error !== '' ? $error : ('HTTP ' . $status);
            if ($attempt < 3) {
                usleep($attempt * 1_000_000);
            }
        }

        throw new RuntimeException('BNR indisponibil după 3 încercări (' . $lastError . ').');
    }

    /** @return array{0:?string, 1:int, 2:string} */
    private function httpGet(string $url): array
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'FleetApp-BNR-Sync/1.0',
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = curl_error($handle);
            curl_close($handle);

            return [is_string($body) ? $body : null, $status, $error];
        }

        $context = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'FleetApp-BNR-Sync/1.0']]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return [is_string($body) ? $body : null, $status, is_string($body) ? '' : 'cerere eșuată'];
    }
}
