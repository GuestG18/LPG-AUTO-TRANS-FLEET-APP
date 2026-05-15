<?php
declare(strict_types=1);

class ProgramareConcediiController
{
    private ProgramareConcediiModel $model;

    private const TIPURI_CONCEDIU = [
        'odihna' => 'Concediu de odihnă',
        'personal' => 'Concediu personal',
        'medical' => 'Concediu medical',
        'fara_plata' => 'Concediu fără plată',
    ];

    private const STATUSURI = [
        'aprobat' => 'Aprobat',
        'respins' => 'Respins',
        'in_asteptare' => 'În așteptare',
        'in_asteptare_aprobare' => 'În așteptare aprobare',
    ];

    private const VIZUALIZARI_CALENDAR = [
        'luna' => 'Lună',
        'saptamana' => 'Săptămână',
        'zi' => 'Zi',
    ];

    private const DEFAULT_STATUS = 'in_asteptare';
    private const AUTO_RECHECK_STATUS = 'in_asteptare_aprobare';
    private const ENFORCE_FUTURE_START_DATE = false;
    private const UNAVAILABLE_RATIO_WARNING = 0.35;
    private const UNAVAILABLE_MIN_WARNING = 3;

    public function __construct(PDO $db)
    {
        $this->model = new ProgramareConcediiModel($db);
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;
            case 'store':
                $this->storeAction();
                return;
            case 'update':
                $this->updateAction();
                return;
            case 'delete':
                $this->deleteAction();
                return;
            case 'update_status':
                $this->updateStatusAction();
                return;
            case 'api_collection':
                $this->apiCollectionAction();
                return;
            case 'api_item':
                $this->apiItemAction();
                return;
            case 'api_calendar':
                $this->apiCalendarAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Acțiune inexistentă',
                    'currentPage' => 'programare_concedii',
                ]);
                return;
        }
    }

    private function indexAction(): void
    {
        $activeTab = $this->normalizeTab((string) ($_GET['tab'] ?? 'calendar'));
        $calendarView = $this->normalizeCalendarView((string) ($_GET['calendar_view'] ?? 'luna'));
        $driverSearch = trim((string) ($_GET['driver_q'] ?? ''));
        $tableSearch = trim((string) ($_GET['table_q'] ?? ''));
        $statusFilter = trim((string) ($_GET['status_filter'] ?? ''));
        $tipFilter = trim((string) ($_GET['tip_filter'] ?? ''));
        $page = max(1, (int) ($_GET['p'] ?? 1));

        if (!array_key_exists($statusFilter, self::STATUSURI)) {
            $statusFilter = '';
        }
        if (!array_key_exists($tipFilter, self::TIPURI_CONCEDIU)) {
            $tipFilter = '';
        }

        $focusDate = $this->resolveFocusDate(
            (string) ($_GET['calendar_month'] ?? ''),
            (string) ($_GET['focus_date'] ?? '')
        );
        $monthStart = (clone $focusDate)->modify('first day of this month');
        $monthEnd = (clone $focusDate)->modify('last day of this month');
        $eventsRangeStart = (clone $monthStart)->modify('-14 day')->format('Y-m-d');
        $eventsRangeEnd = (clone $monthEnd)->modify('+14 day')->format('Y-m-d');

        $filters = [
            'status' => $statusFilter,
            'tip' => $tipFilter,
            'only_pending' => $activeTab === 'aprobari',
        ];

        if ($activeTab === 'aprobari' && !is_admin()) {
            $filters['created_by'] = $this->currentUserId();
        }

        $editId = (int) ($_GET['edit_id'] ?? 0);
        $editingRecord = $editId > 0 ? $this->model->getRequestById($editId) : null;
        if ($editId > 0 && $editingRecord === null) {
            flash_set('warning', 'Cererea selectată pentru editare nu există.');
        }

        $formFlash = $this->consumeFormFlash();
        $formData = $this->defaultFormData();
        if (is_array($editingRecord)) {
            $formData = $this->mapRequestToFormData($editingRecord);
        }
        if ($formFlash['old'] !== []) {
            $formData = array_merge($formData, $formFlash['old']);
        }

        try {
            $listResult = $this->model->getPaginatedRequests(
                $filters,
                $tableSearch,
                $page,
                ITEMS_PER_PAGE
            );
            $drivers = $this->model->getActiveDrivers();
            $stats = $this->model->getStatsSnapshot();
            $calendarEvents = $this->model->getCalendarEvents($eventsRangeStart, $eventsRangeEnd, $driverSearch);
        } catch (Throwable $exception) {
            error_log('[ProgramareConcediiController][index] ' . $exception->getMessage());
            flash_set(
                'danger',
                'Structura bazei de date pentru modulul Programare Concedii nu este actualizată. Rulează scriptul database/update_programare_concedii.sql.'
            );
            $listResult = [
                'rows' => [],
                'total_rows' => 0,
                'total_pages' => 1,
                'page' => 1,
            ];
            $drivers = [];
            $stats = [
                'total_drivers' => 0,
                'available_drivers' => 0,
                'available_percentage' => 0,
                'on_leave' => 0,
                'pending' => 0,
                'conflicts' => 0,
            ];
            $calendarEvents = [];
        }
        $toastFlash = $this->consumeToastFlash();

        $calendarEventsJson = json_encode($calendarEvents, JSON_UNESCAPED_UNICODE);
        if (!is_string($calendarEventsJson)) {
            $calendarEventsJson = '[]';
        }

        render('programare_concedii/index.php', [
            'pageTitle' => 'Programare Concedii',
            'currentPage' => 'programare_concedii',
            'activeTab' => $activeTab,
            'calendarView' => $calendarView,
            'calendarFocusDate' => $focusDate->format('Y-m-d'),
            'calendarMonthValue' => $focusDate->format('Y-m'),
            'calendarMonthStart' => $monthStart->format('Y-m-d'),
            'driverSearch' => $driverSearch,
            'tableSearch' => $tableSearch,
            'statusFilter' => $statusFilter,
            'tipFilter' => $tipFilter,
            'tipuriConcediu' => self::TIPURI_CONCEDIU,
            'statusuri' => self::STATUSURI,
            'vizualizariCalendar' => self::VIZUALIZARI_CALENDAR,
            'rows' => $listResult['rows'],
            'pagination' => [
                'page' => $listResult['page'],
                'total_pages' => $listResult['total_pages'],
                'total_rows' => $listResult['total_rows'],
                'per_page' => ITEMS_PER_PAGE,
            ],
            'drivers' => $drivers,
            'formData' => $formData,
            'formErrors' => $formFlash['errors'],
            'isEditing' => $editingRecord !== null,
            'editingId' => $editingRecord !== null ? (int) ($editingRecord['id'] ?? 0) : 0,
            'stats' => $stats,
            'calendarEventsJson' => $calendarEventsJson,
            'toastFlash' => $toastFlash,
            'timezoneLabel' => 'UTC+03:00',
        ]);
    }

    private function storeAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'programare_concedii']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'programare_concedii']));

        [$data, $errors, $old] = $this->validateRequestInput($_POST, null, false);
        if ($errors !== []) {
            $this->setFormFlash($old, $errors);
            redirect(build_query_url(['page' => 'programare_concedii', 'tab' => 'cereri']));
        }

        $now = date('Y-m-d H:i:s');
        $data['status'] = self::DEFAULT_STATUS;
        $data['created_by'] = $this->currentUserId();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        try {
            $requestId = $this->model->createRequest($data);
            $created = $this->model->getRequestById($requestId);
            $this->logAuditSafe('create', $requestId, $created, null);

            $this->queueUnavailableWarning($data['data_inceput'], $data['data_sfarsit']);
            $this->setToastFlash('success', 'Cererea de concediu a fost trimisă cu succes.');
            flash_set('success', 'Cererea de concediu a fost trimisă cu status „În așteptare”.');
        } catch (Throwable $exception) {
            error_log('[ProgramareConcediiController][store] ' . $exception->getMessage());
            $this->setFormFlash($old, []);
            flash_set('danger', $this->buildPersistenceErrorMessage($exception));
        }

        redirect(build_query_url(['page' => 'programare_concedii', 'tab' => 'cereri']));
    }

    private function updateAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'programare_concedii']));
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'ID cerere invalid.');
            redirect(build_query_url(['page' => 'programare_concedii']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'programare_concedii', 'edit_id' => $id]));

        $existing = $this->model->getRequestById($id);
        if ($existing === null) {
            flash_set('warning', 'Cererea de concediu nu există.');
            redirect(build_query_url(['page' => 'programare_concedii']));
        }

        [$data, $errors, $old] = $this->validateRequestInput($_POST, $id, true);
        if ($errors !== []) {
            $this->setFormFlash($old, $errors);
            redirect(build_query_url([
                'page' => 'programare_concedii',
                'tab' => 'cereri',
                'edit_id' => $id,
            ]));
        }

        $existingStatus = trim((string) ($existing['status'] ?? self::DEFAULT_STATUS));
        $requestedStatus = trim((string) ($_POST['status'] ?? ''));
        if (!array_key_exists($requestedStatus, self::STATUSURI)) {
            $requestedStatus = $existingStatus;
        }

        $coreFieldsChanged = $this->hasApprovalRelevantChanges($existing, $data);
        $autoRecheckTriggered = $existingStatus === 'aprobat' && $coreFieldsChanged;
        if ($autoRecheckTriggered) {
            $requestedStatus = self::AUTO_RECHECK_STATUS;
        }

        $data['status'] = $requestedStatus;
        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            $this->model->updateRequest($id, $data);
            $updated = $this->model->getRequestById($id);
            $this->logAuditSafe('update', $id, $updated, $existing);
            $this->queueUnavailableWarning($data['data_inceput'], $data['data_sfarsit']);
            if ($autoRecheckTriggered) {
                $this->setToastFlash('warning', 'Cererea aprobată a fost modificată și a revenit în așteptare aprobare.');
                flash_set('warning', 'Cererea aprobată a fost modificată și a revenit în status „În așteptare aprobare”.');
            } else {
                $this->setToastFlash('success', 'Cererea de concediu a fost actualizată.');
                flash_set('success', 'Cererea de concediu a fost actualizată cu succes.');
            }
        } catch (Throwable $exception) {
            error_log('[ProgramareConcediiController][update] ' . $exception->getMessage());
            $this->setFormFlash($old, []);
            flash_set('danger', $this->buildPersistenceErrorMessage($exception));
            redirect(build_query_url([
                'page' => 'programare_concedii',
                'tab' => 'cereri',
                'edit_id' => $id,
            ]));
        }

        redirect(build_query_url(['page' => 'programare_concedii', 'tab' => 'cereri']));
    }

    private function deleteAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'programare_concedii']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'programare_concedii']));

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'ID cerere invalid pentru ștergere.');
            redirect(build_query_url(['page' => 'programare_concedii', 'tab' => 'cereri']));
        }

        $existing = $this->model->getRequestById($id);
        if ($existing === null) {
            flash_set('warning', 'Cererea de concediu nu există.');
            redirect(build_query_url(['page' => 'programare_concedii', 'tab' => 'cereri']));
        }

        if (!$this->model->isDeleteAllowed($id)) {
            flash_set('warning', 'Cererea nu poate fi ștearsă în statusul curent.');
            redirect(build_query_url(['page' => 'programare_concedii', 'tab' => 'cereri']));
        }

        try {
            $this->model->deleteRequest($id);
            $this->logAuditSafe('delete', $id, null, $existing);
            $this->setToastFlash('success', 'Cererea a fost ștearsă.');
            flash_set('success', 'Cererea de concediu a fost ștearsă.');
        } catch (Throwable $exception) {
            error_log('[ProgramareConcediiController][delete] ' . $exception->getMessage());
            flash_set('danger', $this->buildPersistenceErrorMessage($exception));
        }

        redirect(build_query_url(['page' => 'programare_concedii', 'tab' => 'cereri']));
    }

    private function updateStatusAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'programare_concedii']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'programare_concedii']));

        $id = (int) ($_POST['id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? ''));

        if ($id <= 0 || !array_key_exists($status, self::STATUSURI)) {
            flash_set('warning', 'Date invalide pentru actualizarea statusului.');
            redirect(build_query_url(['page' => 'programare_concedii', 'tab' => 'aprobari']));
        }

        $existing = $this->model->getRequestById($id);
        if ($existing === null) {
            flash_set('warning', 'Cererea nu mai există.');
            redirect(build_query_url(['page' => 'programare_concedii', 'tab' => 'aprobari']));
        }

        if ($status === 'aprobat') {
            $overlaps = $this->model->getOverlappingApprovedRequests(
                (int) ($existing['driver_id'] ?? 0),
                (string) ($existing['data_inceput'] ?? ''),
                (string) ($existing['data_sfarsit'] ?? ''),
                $id
            );
            if ($overlaps !== []) {
                flash_set('danger', 'Nu poți aproba cererea: șoferul are deja un concediu aprobat pe aceeași perioadă.');
                redirect(build_query_url(['page' => 'programare_concedii', 'tab' => 'aprobari']));
            }
        }

        try {
            $this->model->updateStatus($id, $status);
            $updated = $this->model->getRequestById($id);
            $this->logAuditSafe('update_status', $id, $updated, $existing);
            $this->setToastFlash('success', 'Statusul cererii a fost actualizat.');
            flash_set('success', 'Status actualizat: ' . (self::STATUSURI[$status] ?? $status) . '.');
        } catch (Throwable $exception) {
            error_log('[ProgramareConcediiController][update_status] ' . $exception->getMessage());
            flash_set('danger', $this->buildPersistenceErrorMessage($exception));
        }

        redirect(build_query_url(['page' => 'programare_concedii', 'tab' => 'aprobari']));
    }

    private function apiCollectionAction(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'GET') {
            $search = trim((string) ($_GET['q'] ?? ''));
            $status = trim((string) ($_GET['status'] ?? ''));
            $tip = trim((string) ($_GET['tip'] ?? ''));
            $page = max(1, (int) ($_GET['page_no'] ?? 1));
            $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 20)));
            $filters = [
                'status' => array_key_exists($status, self::STATUSURI) ? $status : '',
                'tip' => array_key_exists($tip, self::TIPURI_CONCEDIU) ? $tip : '',
            ];

            $result = $this->model->getPaginatedRequests($filters, $search, $page, $perPage);
            $this->jsonResponse(200, [
                'data' => $result['rows'],
                'pagination' => [
                    'page' => $result['page'],
                    'total_pages' => $result['total_pages'],
                    'total_rows' => $result['total_rows'],
                    'per_page' => $perPage,
                ],
            ]);
            return;
        }

        if ($method === 'POST') {
            $payload = $this->parseRequestPayload();
            [$data, $errors] = $this->validateApiPayload($payload, null, false);
            if ($errors !== []) {
                $this->jsonResponse(422, ['errors' => $errors]);
                return;
            }

            $now = date('Y-m-d H:i:s');
            $data['status'] = self::DEFAULT_STATUS;
            $data['created_by'] = $this->currentUserId();
            $data['created_at'] = $now;
            $data['updated_at'] = $now;

            try {
                $id = $this->model->createRequest($data);
                $row = $this->model->getRequestById($id);
                $this->logAuditSafe('create', $id, $row, null);
                $this->jsonResponse(201, ['data' => $row]);
            } catch (Throwable $exception) {
                error_log('[ProgramareConcediiController][api_store] ' . $exception->getMessage());
                $this->jsonResponse(500, ['message' => $this->buildPersistenceErrorMessage($exception)]);
            }
            return;
        }

        $this->jsonResponse(405, ['message' => 'Metodă nepermisă.']);
    }

    private function apiItemAction(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonResponse(400, ['message' => 'ID invalid.']);
            return;
        }

        $existing = $this->model->getRequestById($id);
        if ($existing === null) {
            $this->jsonResponse(404, ['message' => 'Cererea nu a fost găsită.']);
            return;
        }

        if ($method === 'GET') {
            $this->jsonResponse(200, ['data' => $existing]);
            return;
        }

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            $payload = $this->parseRequestPayload();
            [$data, $errors] = $this->validateApiPayload($payload, $id, true);
            if ($errors !== []) {
                $this->jsonResponse(422, ['errors' => $errors]);
                return;
            }

            $existingStatus = trim((string) ($existing['status'] ?? self::DEFAULT_STATUS));
            $status = trim((string) ($payload['status'] ?? $existingStatus));
            if (!array_key_exists($status, self::STATUSURI)) {
                $status = $existingStatus;
            }

            $coreFieldsChanged = $this->hasApprovalRelevantChanges($existing, $data);
            if ($existingStatus === 'aprobat' && $coreFieldsChanged) {
                $status = self::AUTO_RECHECK_STATUS;
            }
            $data['status'] = $status;
            $data['updated_at'] = date('Y-m-d H:i:s');

            try {
                $this->model->updateRequest($id, $data);
                $updated = $this->model->getRequestById($id);
                $this->logAuditSafe('update', $id, $updated, $existing);
                $this->jsonResponse(200, ['data' => $updated]);
            } catch (Throwable $exception) {
                error_log('[ProgramareConcediiController][api_update] ' . $exception->getMessage());
                $this->jsonResponse(500, ['message' => $this->buildPersistenceErrorMessage($exception)]);
            }
            return;
        }

        if ($method === 'DELETE') {
            if (!$this->model->isDeleteAllowed($id)) {
                $this->jsonResponse(409, [
                    'message' => 'Cererea nu poate fi ștearsă în statusul curent.',
                ]);
                return;
            }

            try {
                $this->model->deleteRequest($id);
                $this->logAuditSafe('delete', $id, null, $existing);
                $this->jsonResponse(200, ['success' => true]);
            } catch (Throwable $exception) {
                error_log('[ProgramareConcediiController][api_delete] ' . $exception->getMessage());
                $this->jsonResponse(500, ['message' => $this->buildPersistenceErrorMessage($exception)]);
            }
            return;
        }

        $this->jsonResponse(405, ['message' => 'Metodă nepermisă.']);
    }

    private function apiCalendarAction(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            $this->jsonResponse(405, ['message' => 'Metodă nepermisă.']);
            return;
        }

        $month = trim((string) ($_GET['month'] ?? date('Y-m')));
        if (!$this->isValidMonth($month)) {
            $month = date('Y-m');
        }
        $monthDate = DateTime::createFromFormat('Y-m-d', $month . '-01');
        if (!$monthDate) {
            $monthDate = new DateTime('first day of this month');
        }

        $rangeStart = (clone $monthDate)->modify('first day of this month')->format('Y-m-d');
        $rangeEnd = (clone $monthDate)->modify('last day of this month')->format('Y-m-d');
        $driverSearch = trim((string) ($_GET['driver_q'] ?? ''));

        $events = $this->model->getCalendarEvents($rangeStart, $rangeEnd, $driverSearch);
        $this->jsonResponse(200, ['data' => $events]);
    }

    private function validateRequestInput(array $input, ?int $excludeId, bool $isUpdate): array
    {
        $data = [];
        $errors = [];

        $driverId = (int) ($input['driver_id'] ?? 0);
        $tipConcediu = trim((string) ($input['tip_concediu'] ?? ''));
        $dataInceput = trim((string) ($input['data_inceput'] ?? ''));
        $dataSfarsit = trim((string) ($input['data_sfarsit'] ?? ''));
        $inlocuitorIdRaw = trim((string) ($input['inlocuitor_id'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));

        if ($driverId <= 0 || !$this->model->driverExistsAndActive($driverId)) {
            $errors['driver_id'] = 'Selectează un șofer activ.';
        }

        if (!array_key_exists($tipConcediu, self::TIPURI_CONCEDIU)) {
            $errors['tip_concediu'] = 'Selectează un tip de concediu valid.';
        }

        if (!$this->isValidDate($dataInceput)) {
            $errors['data_inceput'] = 'Data de început este invalidă.';
        }

        if (!$this->isValidDate($dataSfarsit)) {
            $errors['data_sfarsit'] = 'Data de sfârșit este invalidă.';
        }

        if (!isset($errors['data_inceput']) && !isset($errors['data_sfarsit'])) {
            if ($dataSfarsit < $dataInceput) {
                $errors['data_sfarsit'] = 'Data de sfârșit nu poate fi înainte de data de început.';
            }

            if (self::ENFORCE_FUTURE_START_DATE && $dataInceput < date('Y-m-d')) {
                $errors['data_inceput'] = 'Data de început nu poate fi în trecut.';
            }
        }

        $inlocuitorId = null;
        if ($inlocuitorIdRaw !== '') {
            $inlocuitorId = (int) $inlocuitorIdRaw;
            if ($inlocuitorId <= 0 || !$this->model->driverExistsAndActive($inlocuitorId)) {
                $errors['inlocuitor_id'] = 'Înlocuitorul trebuie să fie un șofer activ.';
            } elseif ($inlocuitorId === $driverId) {
                $errors['inlocuitor_id'] = 'Înlocuitorul nu poate fi același cu șoferul selectat.';
            }
        }

        if (!isset($errors['driver_id']) && !isset($errors['data_inceput']) && !isset($errors['data_sfarsit'])) {
            $overlaps = $this->model->getOverlappingApprovedRequests(
                $driverId,
                $dataInceput,
                $dataSfarsit,
                $excludeId
            );
            if ($overlaps !== []) {
                $errors['data_inceput'] = 'Există deja un concediu aprobat pe perioada selectată pentru acest șofer.';
                $errors['data_sfarsit'] = 'Alege o perioadă care nu se suprapune peste concedii aprobate.';
            }
        }

        if (mb_strlen($note) > 2000) {
            $errors['note'] = 'Notele nu pot depăși 2000 de caractere.';
        }

        $data['driver_id'] = $driverId;
        $data['tip_concediu'] = $tipConcediu;
        $data['data_inceput'] = $dataInceput;
        $data['data_sfarsit'] = $dataSfarsit;
        $data['inlocuitor_id'] = $inlocuitorId;
        $data['note'] = $note;

        if ($isUpdate) {
            $status = trim((string) ($input['status'] ?? ''));
            $data['status'] = array_key_exists($status, self::STATUSURI) ? $status : self::DEFAULT_STATUS;
        }

        $old = [
            'id' => (int) ($input['id'] ?? 0),
            'driver_id' => (string) $driverId,
            'tip_concediu' => $tipConcediu,
            'data_inceput' => $dataInceput,
            'data_sfarsit' => $dataSfarsit,
            'inlocuitor_id' => $inlocuitorIdRaw,
            'note' => $note,
            'status' => trim((string) ($input['status'] ?? self::DEFAULT_STATUS)),
        ];

        return [$data, $errors, $old];
    }

    private function validateApiPayload(array $payload, ?int $excludeId, bool $isUpdate): array
    {
        $normalized = [
            'id' => (int) ($payload['id'] ?? 0),
            'driver_id' => (int) ($payload['driver_id'] ?? 0),
            'tip_concediu' => (string) ($payload['tip_concediu'] ?? ''),
            'data_inceput' => (string) ($payload['data_inceput'] ?? ''),
            'data_sfarsit' => (string) ($payload['data_sfarsit'] ?? ''),
            'inlocuitor_id' => isset($payload['inlocuitor_id']) ? (string) $payload['inlocuitor_id'] : '',
            'note' => (string) ($payload['note'] ?? ''),
            'status' => (string) ($payload['status'] ?? self::DEFAULT_STATUS),
        ];

        $validation = $this->validateRequestInput($normalized, $excludeId, $isUpdate);
        $data = is_array($validation[0] ?? null) ? $validation[0] : [];
        $errors = is_array($validation[1] ?? null) ? $validation[1] : [];

        return [$data, $errors];
    }

    private function logAuditSafe(string $action, int $id, ?array $after, ?array $before): void
    {
        try {
            $label = $after['sofer_nume'] ?? $before['sofer_nume'] ?? 'șofer necunoscut';
            $description = match ($action) {
                'create' => 'Cerere concediu creată pentru ' . $label,
                'update' => 'Cerere concediu actualizată pentru ' . $label,
                'delete' => 'Cerere concediu ștearsă pentru ' . $label,
                'update_status' => 'Status cerere concediu actualizat pentru ' . $label,
                default => 'Acțiune concediu pentru ' . $label,
            };

            $this->model->logAudit(
                $action,
                $id,
                $description,
                $this->currentUserId(),
                $before,
                $after
            );
        } catch (Throwable $exception) {
            error_log('[ProgramareConcediiController][audit] ' . $exception->getMessage());
        }
    }

    private function queueUnavailableWarning(string $startDate, string $endDate): void
    {
        $stats = $this->model->getStatsSnapshot();
        $activeDrivers = (int) ($stats['total_drivers'] ?? 0);
        if ($activeDrivers <= 0) {
            return;
        }

        $maxUnavailable = $this->model->getMaxUnavailableDriversInRange($startDate, $endDate);
        $ratio = $maxUnavailable / $activeDrivers;
        if ($maxUnavailable >= self::UNAVAILABLE_MIN_WARNING && $ratio >= self::UNAVAILABLE_RATIO_WARNING) {
            flash_set(
                'warning',
                'Atenție: în perioada selectată indisponibilitatea șoferilor ajunge la '
                . $maxUnavailable . ' din ' . $activeDrivers . '.'
            );
        }
    }

    private function hasApprovalRelevantChanges(array $existing, array $newData): bool
    {
        $existingDriverId = (int) ($existing['driver_id'] ?? 0);
        $newDriverId = (int) ($newData['driver_id'] ?? 0);

        $existingTip = trim((string) ($existing['tip_concediu'] ?? ''));
        $newTip = trim((string) ($newData['tip_concediu'] ?? ''));

        $existingStart = trim((string) ($existing['data_inceput'] ?? ''));
        $newStart = trim((string) ($newData['data_inceput'] ?? ''));

        $existingEnd = trim((string) ($existing['data_sfarsit'] ?? ''));
        $newEnd = trim((string) ($newData['data_sfarsit'] ?? ''));

        $existingReplacement = $existing['inlocuitor_id'] === null ? null : (int) $existing['inlocuitor_id'];
        $newReplacement = $newData['inlocuitor_id'] === null ? null : (int) $newData['inlocuitor_id'];

        return $existingDriverId !== $newDriverId
            || $existingTip !== $newTip
            || $existingStart !== $newStart
            || $existingEnd !== $newEnd
            || $existingReplacement !== $newReplacement;
    }

    private function defaultFormData(): array
    {
        return [
            'id' => 0,
            'driver_id' => '',
            'tip_concediu' => 'odihna',
            'data_inceput' => '',
            'data_sfarsit' => '',
            'inlocuitor_id' => '',
            'note' => '',
            'status' => self::DEFAULT_STATUS,
        ];
    }

    private function mapRequestToFormData(array $request): array
    {
        return [
            'id' => (int) ($request['id'] ?? 0),
            'driver_id' => (string) ($request['driver_id'] ?? ''),
            'tip_concediu' => (string) ($request['tip_concediu'] ?? 'odihna'),
            'data_inceput' => (string) ($request['data_inceput'] ?? ''),
            'data_sfarsit' => (string) ($request['data_sfarsit'] ?? ''),
            'inlocuitor_id' => (string) ($request['inlocuitor_id'] ?? ''),
            'note' => (string) ($request['note'] ?? ''),
            'status' => (string) ($request['status'] ?? self::DEFAULT_STATUS),
        ];
    }

    private function normalizeTab(string $tab): string
    {
        $tab = strtolower(trim($tab));
        if (in_array($tab, ['calendar', 'cereri', 'aprobari'], true)) {
            return $tab;
        }

        return 'calendar';
    }

    private function normalizeCalendarView(string $view): string
    {
        $view = strtolower(trim($view));
        if (array_key_exists($view, self::VIZUALIZARI_CALENDAR)) {
            return $view;
        }

        return 'luna';
    }

    private function resolveFocusDate(string $month, string $focusDate): DateTime
    {
        if ($this->isValidMonth($month)) {
            $date = DateTime::createFromFormat('Y-m-d', $month . '-01');
            if ($date instanceof DateTime) {
                return $date;
            }
        }

        if ($this->isValidDate($focusDate)) {
            $date = DateTime::createFromFormat('Y-m-d', $focusDate);
            if ($date instanceof DateTime) {
                return $date;
            }
        }

        return new DateTime('first day of this month');
    }

    private function isValidDate(string $date): bool
    {
        if ($date === '') {
            return false;
        }

        $dateTime = DateTime::createFromFormat('Y-m-d', $date);

        return $dateTime !== false && $dateTime->format('Y-m-d') === $date;
    }

    private function isValidMonth(string $value): bool
    {
        if (!preg_match('/^\d{4}\-\d{2}$/', $value)) {
            return false;
        }

        $month = (int) substr($value, 5, 2);

        return $month >= 1 && $month <= 12;
    }

    private function parseRequestPayload(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $raw = file_get_contents('php://input');
        if (!is_string($raw)) {
            return [];
        }

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        $parsed = [];
        parse_str($raw, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    private function jsonResponse(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function buildPersistenceErrorMessage(Throwable $exception): string
    {
        $sqlState = strtoupper((string) $exception->getCode());
        $message = strtolower($exception->getMessage());

        if (
            $sqlState === '42S02'
            || str_contains($message, 'concedii')
            || str_contains($message, 'driver_id')
            || str_contains($message, 'tip_concediu')
            || str_contains($message, 'inlocuitor_id')
        ) {
            return 'Structura bazei de date pentru modulul Programare Concedii nu este actualizată. Rulează scriptul database/update_programare_concedii.sql.';
        }

        return 'A apărut o eroare la salvare. Te rugăm să reîncerci.';
    }

    private function currentUserId(): ?int
    {
        $userId = current_user()['id'] ?? null;
        if (!is_int($userId) && !is_numeric((string) $userId)) {
            return null;
        }

        $id = (int) $userId;

        return $id > 0 ? $id : null;
    }

    private function setFormFlash(array $old, array $errors): void
    {
        $_SESSION['_concedii_form_flash'] = [
            'old' => $old,
            'errors' => $errors,
        ];
    }

    private function consumeFormFlash(): array
    {
        $flash = $_SESSION['_concedii_form_flash'] ?? null;
        unset($_SESSION['_concedii_form_flash']);

        if (!is_array($flash)) {
            return [
                'old' => [],
                'errors' => [],
            ];
        }

        return [
            'old' => is_array($flash['old'] ?? null) ? $flash['old'] : [],
            'errors' => is_array($flash['errors'] ?? null) ? $flash['errors'] : [],
        ];
    }

    private function setToastFlash(string $type, string $message): void
    {
        $_SESSION['_concedii_toast_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    private function consumeToastFlash(): ?array
    {
        $flash = $_SESSION['_concedii_toast_flash'] ?? null;
        unset($_SESSION['_concedii_toast_flash']);

        if (!is_array($flash)) {
            return null;
        }

        $type = trim((string) ($flash['type'] ?? ''));
        $message = trim((string) ($flash['message'] ?? ''));
        if ($message === '') {
            return null;
        }

        if (!in_array($type, ['success', 'danger', 'warning', 'info'], true)) {
            $type = 'info';
        }

        return [
            'type' => $type,
            'message' => $message,
        ];
    }
}
