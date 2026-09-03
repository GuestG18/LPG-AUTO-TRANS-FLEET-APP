<?php
declare(strict_types=1);

/**
 * Controller pentru Dashboard Analitic V2.
 *
 * Fata de V1: filtrele accepta selectii multiple (vehicule, soferi, beneficiari,
 * tipuri de transport, capacitati, statusuri), iar datele vin din
 * DashboardAnaliticV2Model, care pastreaza aceleasi formule de calcul.
 */
class DashboardAnaliticV2Controller
{
    private DashboardAnaliticV2Model $model;
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->model = new DashboardAnaliticV2Model($db);
    }

    public function index(): void
    {
        // Interogarile de dashboard depind de coloanele de refacturare / soft-delete.
        (new DispecerCurseModel($this->db))->ensureDashboardSchemaReady();

        $filters = $this->resolveFilters($_GET);
        $options = $this->model->getFilterOptions();

        render('dashboard/analitic_v2.php', [
            'pageTitle' => 'Dashboard Analitic V2',
            'currentPage' => 'dashboard_analitic_v2',
            'filters' => $filters,
            'filterOptions' => $options,
        ]);
    }

    public function data(): void
    {
        $filters = $this->resolveFilters($_GET);

        try {
            (new DispecerCurseModel($this->db))->ensureDashboardSchemaReady();

            $payload = $this->model->getData($filters);
            $payload['applied_filters'] = $filters;
            $payload['meta'] = ['generated_at' => date('c')];

            $this->sendJson($payload);
        } catch (Throwable $exception) {
            error_log('[DashboardAnaliticV2Controller][data] ' . $exception->getMessage());

            $payload = $this->model->emptyPayload($filters);
            $payload['applied_filters'] = $filters;
            $payload['meta'] = ['generated_at' => date('c')];
            $payload['error'] = 'Nu s-au putut incarca datele dashboard-ului.';

            http_response_code(500);
            $this->sendJson($payload);
        }
    }

    /** Detaliul unui vehicul / sofer / beneficiar, pe filtrele curente. */
    public function entity(): void
    {
        $type = trim((string) ($_GET['entity_type'] ?? ''));
        $id = (int) ($_GET['entity_id'] ?? 0);

        if (!DashboardAnaliticV2Model::isEntityType($type)) {
            http_response_code(400);
            $this->sendJson(['error' => 'Tip de entitate necunoscut.']);
        }

        $filters = $this->resolveFilters($_GET);

        try {
            (new DispecerCurseModel($this->db))->ensureDashboardSchemaReady();

            $payload = $this->model->getEntityProfile($type, $id, $filters);
            $payload['meta'] = ['generated_at' => date('c')];

            $this->sendJson($payload);
        } catch (Throwable $exception) {
            error_log('[DashboardAnaliticV2Controller][entity] ' . $exception->getMessage());

            http_response_code(500);
            $this->sendJson(['error' => 'Nu s-au putut incarca detaliile.']);
        }
    }

    // ---------------------------------------------------------------- filtre

    private function resolveFilters(array $input): array
    {
        $today = new DateTimeImmutable('today');
        $defaultStart = $today->modify('first day of this month')->format('Y-m-d');
        $defaultEnd = $today->format('Y-m-d');

        $dateStart = $this->normalizeDate(
            array_key_exists('date_start', $input) ? (string) $input['date_start'] : $defaultStart
        );
        $dateEnd = $this->normalizeDate(
            array_key_exists('date_end', $input) ? (string) $input['date_end'] : $defaultEnd
        );

        if ($dateStart !== null && $dateEnd !== null && $dateStart > $dateEnd) {
            [$dateStart, $dateEnd] = [$dateEnd, $dateStart];
        }

        return [
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'vehicle_ids' => $this->parseIntList($input['vehicle_ids'] ?? ($input['vehicle_id'] ?? '')),
            'driver_ids' => $this->parseIntList($input['driver_ids'] ?? ($input['driver_id'] ?? '')),
            'beneficiary_ids' => $this->parseIntList($input['beneficiary_ids'] ?? ($input['beneficiar_id'] ?? '')),
            'transport_types' => $this->parseStringList($input['transport_types'] ?? ($input['tip_transport'] ?? '')),
            'transport_capacities' => $this->parseDecimalList($input['transport_capacities'] ?? ($input['capacitate_transport'] ?? '')),
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

        return ($date && $date->format('Y-m-d') === $trimmed) ? $date->format('Y-m-d') : null;
    }

    private function parseIntList(mixed $value): array
    {
        $result = [];
        foreach ($this->splitList($value, '/[,\s]+/') as $item) {
            if (!is_numeric((string) $item)) {
                continue;
            }

            $number = (int) $item;
            if ($number > 0) {
                $result[$number] = $number;
            }
        }

        return array_values($result);
    }

    private function parseStringList(mixed $value): array
    {
        $result = [];
        foreach ($this->splitList($value, '/[,\n]+/') as $item) {
            $normalized = trim((string) $item);
            if ($normalized !== '') {
                $result[$normalized] = $normalized;
            }
        }

        return array_values($result);
    }

    private function parseDecimalList(mixed $value): array
    {
        $result = [];
        foreach ($this->splitList($value, '/[,\s]+/') as $item) {
            $normalized = str_replace(',', '.', trim((string) $item));
            if ($normalized === '' || !is_numeric($normalized) || (float) $normalized <= 0) {
                continue;
            }

            $key = number_format((float) $normalized, 2, '.', '');
            $result[$key] = $key;
        }

        return array_values($result);
    }

    /** Accepta atat array-uri (name[]=...) cat si liste separate prin virgula. */
    private function splitList(mixed $value, string $pattern): array
    {
        if (is_array($value)) {
            return $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }

        return preg_split($pattern, $raw) ?: [];
    }

    private function sendJson(array $payload): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
