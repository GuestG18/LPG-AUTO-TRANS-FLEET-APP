<?php
declare(strict_types=1);

class DashboardAnaliticController
{
    private DispecerCurseModel $model;

    private const TRANSPORT_TYPE_LABELS = [
        'primar' => 'Primar km',
        'primar_tona' => 'Primar tone',
        'distributie' => 'Distributie',
        'primar_distributie' => 'Primar + Distributie',
        'compresor' => 'Compresor',
    ];

    private const STATUS_LABELS = [
        'in_curs_facturare' => 'In curs de facturare',
        'facturat' => 'Facturat',
        'nefacturat' => 'Nefacturat',
    ];

    public function __construct(PDO $db)
    {
        $this->model = new DispecerCurseModel($db);
    }

    public function index(): void
    {
        $filters = $this->resolveFilters($_GET);
        $options = $this->model->getDashboardAnalyticFilterOptions();

        render('dashboard/analitic.php', [
            'pageTitle' => 'Dashboard Analitic',
            'currentPage' => 'dashboard_analitic',
            'filters' => $filters,
            'filterOptions' => $options,
            'transportTypeLabels' => self::TRANSPORT_TYPE_LABELS,
            'statusLabels' => self::STATUS_LABELS,
        ]);
    }

    public function data(): void
    {
        $filters = $this->resolveFilters($_GET);

        try {
            $payload = $this->model->getDashboardAnalyticData($filters);
            $payload['applied_filters'] = [
                'date_start' => $filters['date_start'],
                'date_end' => $filters['date_end'],
                'vehicle_ids' => $filters['vehicle_ids'],
                'driver_ids' => $filters['driver_ids'],
                'beneficiary_ids' => $filters['beneficiary_ids'],
                'transport_types' => $filters['transport_types'],
                'statuses' => $filters['statuses'],
            ];
            $payload['meta'] = [
                'generated_at' => date('c'),
            ];

            $this->sendJson($payload);
        } catch (Throwable $exception) {
            error_log('[DashboardAnaliticController][data] ' . $exception->getMessage());

            http_response_code(500);
            $this->sendJson([
                'fleet' => [
                    'total_curse' => 0,
                    'total_facturare' => 0,
                    'total_refacturare' => 0,
                    'total_cheltuieli' => 0,
                    'profit_total' => 0,
                    'total_km' => 0,
                    'tone_livrate' => 0,
                    'profit_km' => 0,
                    'grad_incarcare_mediu' => 0,
                    'km_nefacturati' => 0,
                    'km_facturati' => 0,
                ],
                'vehicles' => [],
                'drivers' => [],
                'charts' => [
                    'profit_evolution' => ['labels' => [], 'facturare' => [], 'refacturare' => [], 'cheltuieli' => [], 'profit' => []],
                    'km_billed_vs_unbilled' => ['labels' => ['Km'], 'km_facturati' => [0], 'km_nefacturati' => [0]],
                    'transport_distribution' => ['labels' => [], 'values' => []],
                    'top_vehicle_profit' => ['labels' => [], 'values' => []],
                    'vehicle_profit_per_km' => ['labels' => [], 'values' => []],
                    'rides_per_driver' => ['labels' => [], 'values' => []],
                    'tons_per_driver' => ['labels' => [], 'values' => []],
                    'driver_activity_matrix' => ['points' => []],
                ],
                'alerts' => [],
                'error' => 'Nu s-au putut incarca datele dashboard-ului.',
            ]);
        }
    }

    private function resolveFilters(array $input): array
    {
        $today = new DateTimeImmutable('today');
        $defaultStart = $today->modify('first day of this month')->format('Y-m-d');
        $defaultEnd = $today->format('Y-m-d');

        $hasDateStart = array_key_exists('date_start', $input);
        $hasDateEnd = array_key_exists('date_end', $input);

        $dateStart = $this->normalizeDate($hasDateStart ? (string) ($input['date_start'] ?? '') : $defaultStart);
        $dateEnd = $this->normalizeDate($hasDateEnd ? (string) ($input['date_end'] ?? '') : $defaultEnd);

        if ($dateStart !== null && $dateEnd !== null && $dateStart > $dateEnd) {
            [$dateStart, $dateEnd] = [$dateEnd, $dateStart];
        }

        return [
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'vehicle_ids' => $this->parseIntList($input['vehicle_ids'] ?? ($input['vehicle_id'] ?? '')),
            'driver_ids' => $this->parseIntList($input['driver_ids'] ?? ($input['driver_id'] ?? '')),
            'beneficiary_ids' => $this->parseIntList($input['beneficiary_ids'] ?? ($input['beneficiary_id'] ?? '')),
            'transport_types' => $this->parseStringList($input['transport_types'] ?? ($input['tip_transport'] ?? '')),
            'statuses' => $this->parseStringList($input['statuses'] ?? ($input['status'] ?? '')),
        ];
    }

    private function normalizeDate(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $trimmed);
        if (!$date || $date->format('Y-m-d') !== $trimmed) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function parseIntList(mixed $value): array
    {
        $items = [];

        if (is_array($value)) {
            $items = $value;
        } else {
            $raw = trim((string) $value);
            if ($raw !== '') {
                $items = preg_split('/[,\s]+/', $raw) ?: [];
            }
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_numeric((string) $item)) {
                continue;
            }

            $number = (int) $item;
            if ($number <= 0) {
                continue;
            }

            $result[$number] = $number;
        }

        return array_values($result);
    }

    private function parseStringList(mixed $value): array
    {
        $items = [];

        if (is_array($value)) {
            $items = $value;
        } else {
            $raw = trim((string) $value);
            if ($raw !== '') {
                $items = preg_split('/[,\n]+/', $raw) ?: [];
            }
        }

        $result = [];
        foreach ($items as $item) {
            $normalized = trim((string) $item);
            if ($normalized === '') {
                continue;
            }

            $result[$normalized] = $normalized;
        }

        return array_values($result);
    }

    private function sendJson(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
