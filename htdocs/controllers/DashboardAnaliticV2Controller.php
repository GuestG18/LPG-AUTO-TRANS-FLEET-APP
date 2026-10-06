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
            'cardLayout' => $this->cardLayoutForCurrentUser(),
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

    // ------------------------------------------- cardurile KPI ale utilizatorului

    /** Cheile cardurilor KPI, in ordinea implicita (aceleasi ca in kpiCards() din JS). */
    private const CARD_KEYS = ['curse', 'facturare', 'cheltuieli', 'profit', 'km', 'tone', 'incarcare', 'folosinta', 'medii'];

    /**
     * Ce carduri vede utilizatorul curent si in ce ordine: {order: [chei], hidden: [chei]}.
     * Fara alegere salvata (sau fara coloana inca) -> toate, in ordinea implicita.
     *
     * @return array{order: list<string>, hidden: list<string>}
     */
    public function cardLayoutForCurrentUser(): array
    {
        try {
            $stmt = $this->db->prepare('SELECT dashboard_v2_carduri FROM utilizatori WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int) (current_user()['id'] ?? 0)]);
            $decoded = json_decode((string) $stmt->fetchColumn(), true);
        } catch (Throwable $exception) {
            // coloana apare la prima salvare; pana atunci, aspectul implicit
            return $this->normalizeCardLayout([]);
        }

        return $this->normalizeCardLayout(is_array($decoded) ? $decoded : []);
    }

    /** POST JSON {csrf, order: [chei], hidden: [chei]}; `reset: true` revine la aspectul implicit. */
    public function saveCards(): void
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : [];
        if (!verify_csrf_token((string) ($body['csrf'] ?? ''))) {
            http_response_code(403);
            $this->sendJson(['ok' => false, 'error' => 'Token CSRF invalid. Reîncarcă pagina.']);
        }

        $layout = $this->normalizeCardLayout(!empty($body['reset']) ? [] : $body);
        $isDefault = $layout['order'] === self::CARD_KEYS && $layout['hidden'] === [];

        try {
            $this->ensureCardsColumn();
            $stmt = $this->db->prepare('UPDATE utilizatori SET dashboard_v2_carduri = :v WHERE id = :id');
            $stmt->execute([
                ':v' => $isDefault ? null : json_encode($layout, JSON_UNESCAPED_UNICODE),
                ':id' => (int) current_user()['id'],
            ]);
        } catch (Throwable $exception) {
            error_log('[DashboardAnaliticV2Controller][cards] ' . $exception->getMessage());
            http_response_code(500);
            $this->sendJson(['ok' => false, 'error' => 'Alegerea cardurilor nu a putut fi salvată.']);
        }

        $this->sendJson(['ok' => true, 'layout' => $layout]);
    }

    /**
     * Pastreaza doar cheile cunoscute, fara dubluri; cardurile lipsa din ordine
     * (ex. un card nou) se adauga la final, ca sa nu dispara.
     *
     * @return array{order: list<string>, hidden: list<string>}
     */
    private function normalizeCardLayout(array $data): array
    {
        $order = [];
        foreach ((array) ($data['order'] ?? []) as $key) {
            if (is_string($key) && in_array($key, self::CARD_KEYS, true) && !in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        foreach (self::CARD_KEYS as $key) {
            if (!in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        $hidden = [];
        foreach ((array) ($data['hidden'] ?? []) as $key) {
            if (is_string($key) && in_array($key, self::CARD_KEYS, true) && !in_array($key, $hidden, true)) {
                $hidden[] = $key;
            }
        }

        return ['order' => $order, 'hidden' => $hidden];
    }

    /** Vezi database/migrations/2026_10_06_000001_utilizatori_dashboard_v2_carduri.sql. */
    private function ensureCardsColumn(): void
    {
        $exists = $this->db->query("SHOW COLUMNS FROM utilizatori LIKE 'dashboard_v2_carduri'")->fetch();
        if (!$exists) {
            $this->db->exec('ALTER TABLE utilizatori ADD COLUMN dashboard_v2_carduri TEXT NULL');
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
            // Filtru de GRUPARE pe categoria de capacitate a vehiculului. Alege ce
            // curse intra in raport; nu schimba numitorul gradului de umplere.
            'capacity_categories' => $this->parseIntList($input['capacity_categories'] ?? ($input['categorie_capacitate_id'] ?? '')),
            'statuses' => $this->parseStringList($input['statuses'] ?? ($input['status'] ?? '')),
            // pragurile intervalelor de km; modelul le valideaza si revine la cele implicite
            'km_bands' => $this->parseIntList($input['km_bands'] ?? ''),
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
