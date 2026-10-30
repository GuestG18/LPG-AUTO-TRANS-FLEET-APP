<?php
declare(strict_types=1);

/**
 * Client minimal pentru portalul eToll (portal.etoll.ro), doar citire.
 *
 * Autentificare: Keycloak (sso.etoll.ro), grant "password" cu clientul public al
 * portalului (ddcm-authz-client). Token-ul (30 min) se tine doar in memorie; la 401
 * se reface login-ul o singura data.
 *
 * Endpoint-uri folosite (aceleasi ca in portal):
 *   GET /api/tolls/{tollId}/transactions   sesiunile (o tranzactie = o sesiune; isOngoing)
 *   GET /api/invoices?page=&size=          facturile, cele mai noi intai
 *   GET /api/invoices/{id}                 detaliu, cu sessionIds[]
 *   GET /api/invoices/{id}/pdf             PDF-ul (vine cu content-type gresit: application/json)
 *
 * Toate cererile sunt rarite la cel mult una pe secunda si plafonate pe rulare.
 */
class EtollApiClient
{
    private const TOKEN_URL = 'https://sso.etoll.ro/auth/realms/external/protocol/openid-connect/token';
    private const API_BASE = 'https://portal.etoll.ro/api/';
    private const CLIENT_ID = 'ddcm-authz-client';
    private const MIN_INTERVAL_SECONDS = 1.0;

    private string $username;
    private string $password;
    private string $tollId;
    private int $maxRequests;
    private int $requests = 0;
    private float $lastRequestAt = 0.0;
    private ?string $token = null;

    public function __construct(string $username, string $password, string $tollId, int $maxRequests = 300)
    {
        if ($username === '' || $password === '' || $tollId === '') {
            throw new InvalidArgumentException('Lipsesc ETOLL_USER / ETOLL_PASS / ETOLL_TOLL_ID in .env.');
        }
        $this->username = $username;
        $this->password = $password;
        $this->tollId = $tollId;
        $this->maxRequests = max(1, $maxRequests);
    }

    public static function fromEnv(int $maxRequests = 300): self
    {
        return new self(
            trim((string) getenv('ETOLL_USER')),
            (string) getenv('ETOLL_PASS'),
            trim((string) getenv('ETOLL_TOLL_ID')),
            $maxRequests
        );
    }

    public function requestCount(): int
    {
        return $this->requests;
    }

    public function budgetLeft(): int
    {
        return $this->maxRequests - $this->requests;
    }

    /**
     * Sesiunile (tranzactiile) dintr-un interval, toate paginile.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transactions(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $utc = new DateTimeZone('UTC');
        $format = 'Y-m-d\TH:i:s.v\Z';
        $all = [];
        $page = 0;
        do {
            $data = $this->getJson('tolls/' . rawurlencode($this->tollId) . '/transactions?' . http_build_query([
                'orderBy' => 'DSC',
                'size' => 50, // serverul nu da mai mult de 50 pe pagina
                'page' => $page,
                'startDate' => DateTimeImmutable::createFromInterface($from)->setTimezone($utc)->format($format),
                'endDate' => DateTimeImmutable::createFromInterface($to)->setTimezone($utc)->format($format),
                'minAmount' => 0,
                'maxAmount' => 100000,
            ]));
            $batch = is_array($data['transactions'] ?? null) ? $data['transactions'] : [];
            $all = array_merge($all, $batch);
            $total = (int) ($data['totalCount'] ?? 0);
            $page++;
        } while ($batch !== [] && count($all) < $total && $page < 40);

        return $all;
    }

    /**
     * O pagina din lista de facturi (cele mai noi intai).
     *
     * @return array{invoices: array<int, array<string, mixed>>, last: bool}
     */
    public function invoicesPage(int $page, int $size = 50): array
    {
        $data = $this->getJson('invoices?' . http_build_query(['page' => $page, 'size' => $size]));

        return [
            'invoices' => is_array($data['invoices'] ?? null) ? $data['invoices'] : [],
            'last' => (bool) ($data['page']['last'] ?? true),
        ];
    }

    /** @return array<string, mixed> */
    public function invoice(string $invoiceId): array
    {
        return $this->getJson('invoices/' . rawurlencode($invoiceId));
    }

    /** Continutul PDF-ului facturii. */
    public function invoicePdf(string $invoiceId): string
    {
        [$status, $body] = $this->get('invoices/' . rawurlencode($invoiceId) . '/pdf', 'application/pdf, */*');
        if ($status !== 200 || !str_starts_with($body, '%PDF')) {
            throw new RuntimeException('eToll: PDF-ul facturii ' . $invoiceId . ' nu a venit (HTTP ' . $status . ').');
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function getJson(string $path): array
    {
        [$status, $body] = $this->get($path, 'application/json');
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data)) {
            throw new RuntimeException('eToll: GET ' . strtok($path, '?') . ' a raspuns HTTP ' . $status . '.');
        }

        return $data;
    }

    /** @return array{0: int, 1: string} */
    private function get(string $path, string $accept): array
    {
        $this->token ??= $this->login();
        [$status, $body] = $this->send('GET', self::API_BASE . $path, ['Authorization: Bearer ' . $this->token, 'Accept: ' . $accept]);
        if ($status === 401) {
            $this->token = $this->login();
            [$status, $body] = $this->send('GET', self::API_BASE . $path, ['Authorization: Bearer ' . $this->token, 'Accept: ' . $accept]);
        }

        return [$status, $body];
    }

    private function login(): string
    {
        [$status, $body] = $this->send('POST', self::TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'password',
            'client_id' => self::CLIENT_ID,
            'username' => $this->username,
            'password' => $this->password,
            'scope' => 'openid',
        ]));
        $token = json_decode($body, true)['access_token'] ?? null;
        if ($status !== 200 || !is_string($token) || $token === '') {
            throw new RuntimeException('eToll: autentificarea a esuat (HTTP ' . $status . '). Verifica ETOLL_USER / ETOLL_PASS.');
        }

        return $token;
    }

    /** @return array{0: int, 1: string} */
    private function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        if ($this->requests >= $this->maxRequests) {
            throw new RuntimeException('eToll: limita de ' . $this->maxRequests . ' cereri pe rulare a fost atinsa.');
        }
        $wait = self::MIN_INTERVAL_SECONDS - (microtime(true) - $this->lastRequestAt);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        $this->lastRequestAt = microtime(true);
        $this->requests++;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('eToll: eroare de retea: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string) $response];
    }
}
