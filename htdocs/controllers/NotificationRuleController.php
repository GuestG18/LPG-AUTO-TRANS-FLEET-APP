<?php
declare(strict_types=1);

class NotificationRuleController
{
    private NotificationRuleModel $model;

    private PDO $db;

    private const ENTITY_LABELS = [
        'vehicle' => 'Vehicul',
        'driver' => 'Sofer',
        'tire' => 'Anvelopa',
        'leave' => 'Concediu',
        'equipment' => 'Dotare / Inventar',
        'approval' => 'Solicitare aprobare',
    ];

    /** Tipuri de resursa pentru regulile de aprobare. Gol = toate. */
    private const APPROVAL_RESOURCE_LABELS = [
        'vehicle' => 'Vehicul',
        'driver' => 'Sofer',
        'repair' => 'Reparatie',
    ];

    public const APPROVAL_EVENT = 'inactive_approval_pending';

    private const EVENT_LABELS = [
        'vehicle_document_expiry' => 'Expirare document',
        'driver_document_expiry' => 'Expirare document',
        'driver_birthday' => 'Zi de nastere',
        'leave_starts_soon' => 'Concediu incepe in curand',
        'tire_km_limit' => 'Limita kilometri anvelopa',
        'tire_tread_depth' => 'Adancime profil anvelopa',
        'tire_dot_expiry' => 'DOT anvelopa expira',
        'equipment_expiry' => 'Expirare dotare',
        'equipment_inspection' => 'Inspectie dotare',
        'document_missing' => 'Document lipsa',
        'inactive_approval_pending' => 'Cerere aprobare in asteptare',
    ];

    private const EVENT_ENTITY = [
        'vehicle_document_expiry' => 'vehicle',
        'driver_document_expiry' => 'driver',
        'driver_birthday' => 'driver',
        'leave_starts_soon' => 'leave',
        'tire_km_limit' => 'tire',
        'tire_tread_depth' => 'tire',
        'tire_dot_expiry' => 'tire',
        'equipment_expiry' => 'equipment',
        'equipment_inspection' => 'equipment',
        'document_missing' => 'vehicle',
        'inactive_approval_pending' => 'approval',
    ];

    private const RECIPIENT_LABELS = [
        'admins' => 'Administratori',
        'vehicle_responsible' => 'Responsabil vehicul',
        'drivers' => 'Sofer',
        'specific_users' => 'Utilizatori selectati',
    ];

    private const CHANNEL_LABELS = [
        'email' => 'Email',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->model = new NotificationRuleModel($db);
    }

    public function handle(string $action): void
    {
        require_admin_or_403();

        switch ($action) {
            case 'index':
            case 'list':
            case 'create':
            case 'edit':
                $this->indexAction($action);
                return;
            case 'store':
                $this->storeAction();
                return;
            case 'update':
                $this->updateAction();
                return;
            case 'toggle':
                $this->toggleAction();
                return;
            case 'delete':
                $this->deleteAction();
                return;
            case 'send_test':
            case 'run_test':
                $this->sendTestAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'notificari',
                ]);
                return;
        }
    }

    private function indexAction(string $action): void
    {
        $formFlash = consume_form_flash();
        $editingRule = null;
        $formData = $this->defaultFormData();
        $formErrors = $formFlash['errors'];

        if ($formFlash['old'] !== []) {
            $formData = array_merge($formData, $formFlash['old']);
        }

        if ($action === 'edit') {
            $id = max(0, (int) ($_GET['id'] ?? 0));
            $editingRule = $id > 0 ? $this->model->getRuleById($id) : null;
            if ($editingRule === null) {
                flash_set('warning', 'Regula selectata nu exista.');
                redirect($this->indexUrl());
            }

            if ($formFlash['old'] === []) {
                $formData = $this->mapRuleToFormData($editingRule);
            }
        }

        $allowedTabs = ['rules', 'queue', 'history', 'status'];
        $activeTab = (string) ($_GET['tab'] ?? 'rules');
        if (!in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'rules';
        }

        $ruleFilters = [
            'q' => trim((string) ($_GET['rule_q'] ?? '')),
            'entity_type' => trim((string) ($_GET['entity_type'] ?? '')),
            'event_type' => trim((string) ($_GET['event_type'] ?? '')),
            'status' => trim((string) ($_GET['rule_status'] ?? '')),
        ];

        $queueFilters = [
            'q' => trim((string) ($_GET['queue_q'] ?? '')),
            'status' => trim((string) ($_GET['queue_status'] ?? '')),
        ];

        $historyFilters = [
            'q' => trim((string) ($_GET['history_q'] ?? ($_GET['q'] ?? ''))),
            'status' => trim((string) ($_GET['history_status'] ?? ($_GET['status'] ?? ''))),
            'context' => trim((string) ($_GET['context'] ?? '')),
        ];

        $rulePage = max(1, (int) ($_GET['rp'] ?? 1));
        $queuePage = max(1, (int) ($_GET['qp'] ?? 1));
        $historyPage = max(1, (int) ($_GET['hp'] ?? ($_GET['p'] ?? 1)));

        $vehicleDocumentTypes = $this->model->getVehicleDocumentTypes();
        $driverDocumentTypes = $this->model->getDriverDocumentTypes();
        $equipmentTypes = $this->model->getEquipmentTypes();

        render('notificari/index.php', [
            'pageTitle' => 'Notificari',
            'currentPage' => 'notificari',
            'activeTab' => $activeTab,
            'rulesResult' => $this->model->getRules($ruleFilters, $rulePage, 10),
            'queueResult' => $this->model->getQueueRows($queueFilters, $queuePage, 10),
            'historyResult' => $this->model->getDeliveryHistory($historyFilters, $historyPage, 10),
            'ruleFilters' => $ruleFilters,
            'queueFilters' => $queueFilters,
            'historyFilters' => $historyFilters,
            'stats' => $this->model->getStats(),
            'serviceStatus' => $this->model->getServiceStatus(),
            'formData' => $formData,
            'formErrors' => $formErrors,
            'showForm' => in_array($action, ['create', 'edit'], true),
            'isEditing' => $editingRule !== null,
            'editingId' => $editingRule !== null ? (int) $editingRule['id'] : 0,
            'entityLabels' => self::ENTITY_LABELS,
            'eventLabels' => self::EVENT_LABELS,
            'eventEntityMap' => self::EVENT_ENTITY,
            'recipientLabels' => self::RECIPIENT_LABELS,
            'channelLabels' => self::CHANNEL_LABELS,
            'users' => $this->model->getActiveEmailUsers(),
            'vehicleDocumentTypes' => $vehicleDocumentTypes,
            'driverDocumentTypes' => $driverDocumentTypes,
            'equipmentTypes' => $equipmentTypes,
            'approvalEvent' => self::APPROVAL_EVENT,
            'approvalResourceLabels' => self::APPROVAL_RESOURCE_LABELS,
            'approvalReasonOptions' => $this->approvalReasonOptions(),
            'mailSummary' => $this->mailSummary(),
        ]);
    }

    private function storeAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        ensure_csrf_or_redirect($this->indexUrl());
        [$data, $recipientUserIds, $errors, $old] = $this->validateRuleInput($_POST);

        if ($errors !== []) {
            set_form_flash($old, $errors);
            redirect(build_query_url(['page' => 'notificari', 'action' => 'create']));
        }

        $this->model->createRule($data, $recipientUserIds);
        flash_set('success', 'Regula a fost adaugata.');
        redirect($this->indexUrl());
    }

    private function updateAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        ensure_csrf_or_redirect($this->indexUrl());
        $id = max(0, (int) ($_POST['id'] ?? 0));
        if ($id <= 0 || $this->model->getRuleById($id) === null) {
            flash_set('danger', 'Regula selectata nu exista.');
            redirect($this->indexUrl());
        }

        [$data, $recipientUserIds, $errors, $old] = $this->validateRuleInput($_POST);
        if ($errors !== []) {
            set_form_flash($old, $errors);
            redirect(build_query_url(['page' => 'notificari', 'action' => 'edit', 'id' => $id]));
        }

        $this->model->updateRule($id, $data, $recipientUserIds);
        flash_set('success', 'Regula a fost actualizata.');
        redirect($this->indexUrl());
    }

    private function toggleAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        ensure_csrf_or_redirect($this->indexUrl());
        $id = max(0, (int) ($_POST['id'] ?? 0));
        $enabled = (string) ($_POST['enabled'] ?? '') === '1';

        if ($id > 0) {
            $this->model->setRuleEnabled($id, $enabled);
            flash_set('success', $enabled ? 'Regula a fost activata.' : 'Regula a fost dezactivata.');
        }

        redirect($this->indexUrl());
    }

    private function deleteAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        ensure_csrf_or_redirect($this->indexUrl());
        $id = max(0, (int) ($_POST['id'] ?? 0));

        if ($id > 0) {
            $this->model->deleteRule($id);
            flash_set('success', 'Regula a fost stearsa.');
        }

        redirect($this->indexUrl());
    }

    private function sendTestAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        ensure_csrf_or_redirect($this->indexUrl());
        $currentUser = current_user() ?? [];
        $toEmail = trim((string) ($_POST['test_email'] ?? ($currentUser['email'] ?? '')));
        $toName = trim((string) ($currentUser['nume'] ?? ''));

        $result = $this->model->queueEmail(
            $toEmail,
            $toName,
            'Test notificari - ' . APP_NAME,
            "Salut,\n\nAcesta este un test al sistemului de notificari.\n\n" . APP_NAME,
            'notification_test',
            'manual:' . date('YmdHis'),
            ['triggered_by_user_id' => (int) ($currentUser['id'] ?? 0)]
        );

        if ((bool) ($result['queued'] ?? false)) {
            flash_set('success', 'Testul de notificare a fost pus in coada.');
        } else {
            flash_set('danger', 'Testul nu a putut fi pus in coada: ' . (string) ($result['error'] ?? 'eroare necunoscuta'));
        }

        redirect(build_query_url(['page' => 'notificari', 'tab' => 'queue']));
    }

    private function validateRuleInput(array $input): array
    {
        $eventType = trim((string) ($input['event_type'] ?? ''));
        $entityType = trim((string) ($input['entity_type'] ?? (self::EVENT_ENTITY[$eventType] ?? 'vehicle')));
        if (isset(self::EVENT_ENTITY[$eventType])) {
            $entityType = self::EVENT_ENTITY[$eventType];
        }

        $data = [
            'name' => trim((string) ($input['name'] ?? '')),
            'entity_type' => $entityType,
            'event_type' => $eventType,
            'document_type' => trim((string) ($input['document_type'] ?? '')),
            'days_before' => max(0, (int) ($input['days_before'] ?? 30)),
            'threshold_km' => $this->nullablePositiveInt($input['threshold_km'] ?? null),
            'threshold_tread_depth' => $this->nullablePositiveFloat($input['threshold_tread_depth'] ?? null),
            'channel' => trim((string) ($input['channel'] ?? 'email')),
            'recipient_mode' => trim((string) ($input['recipient_mode'] ?? 'admins')),
            'enabled' => isset($input['enabled']) ? 1 : 0,
            'repeat_until_resolved' => isset($input['repeat_until_resolved']) ? 1 : 0,
            'daily_limit_enabled' => isset($input['daily_limit_enabled']) ? 1 : 0,
            'metadata' => [],
        ];

        // Regula de aprobare nu foloseste tip document / zile inainte / praguri;
        // filtrele ei proprii merg in metadata_json, ca sa nu adaug coloane noi.
        if ($data['event_type'] === self::APPROVAL_EVENT) {
            $resourceTypes = array_values(array_intersect(
                array_map('strval', (array) ($input['approval_resource_types'] ?? [])),
                array_keys(self::APPROVAL_RESOURCE_LABELS)
            ));

            $reasons = array_values(array_unique(array_filter(
                array_map(static fn ($value): string => trim((string) $value), (array) ($input['approval_reasons'] ?? [])),
                static fn (string $value): bool => $value !== ''
            )));

            $data['metadata'] = [
                'approval_resource_types' => $resourceTypes,
                'approval_reasons' => $reasons,
                'repeat_days' => max(1, min(30, (int) ($input['approval_repeat_days'] ?? 1))),
            ];

            $data['document_type'] = '';
            $data['days_before'] = 0;
            $data['threshold_km'] = null;
            $data['threshold_tread_depth'] = null;
        }

        $recipientUserIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($input['recipient_user_ids'] ?? [])
        ), static fn (int $value): bool => $value > 0)));

        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'Completeaza numele regulii.';
        } elseif (strlen($data['name']) > 190) {
            $errors['name'] = 'Numele poate avea maxim 190 de caractere.';
        }

        if (!array_key_exists($data['entity_type'], self::ENTITY_LABELS)) {
            $errors['entity_type'] = 'Selecteaza entitatea.';
        }

        if (!array_key_exists($data['event_type'], self::EVENT_LABELS)) {
            $errors['event_type'] = 'Selecteaza evenimentul.';
        }

        if ($data['document_type'] !== '' && strlen($data['document_type']) > 120) {
            $errors['document_type'] = 'Tipul de document poate avea maxim 120 de caractere.';
        }

        if ($data['days_before'] > 365) {
            $errors['days_before'] = 'Alege cel mult 365 de zile.';
        }

        if ($data['event_type'] === 'tire_km_limit' && (int) ($data['threshold_km'] ?? 0) <= 0) {
            $errors['threshold_km'] = 'Completeaza pragul de kilometri.';
        }

        if ($data['event_type'] === 'tire_tread_depth' && (float) ($data['threshold_tread_depth'] ?? 0) <= 0) {
            $errors['threshold_tread_depth'] = 'Completeaza pragul de profil.';
        }

        if (!array_key_exists($data['channel'], self::CHANNEL_LABELS)) {
            $errors['channel'] = 'Selecteaza canalul.';
        }

        if (!array_key_exists($data['recipient_mode'], self::RECIPIENT_LABELS)) {
            $errors['recipient_mode'] = 'Selecteaza destinatarii.';
        } elseif ($data['recipient_mode'] === 'specific_users' && $recipientUserIds === []) {
            $errors['recipient_user_ids'] = 'Selecteaza cel putin un utilizator.';
        }

        return [$data, $recipientUserIds, $errors, array_merge($data, [
            'recipient_user_ids' => $recipientUserIds,
        ])];
    }

    private function defaultFormData(): array
    {
        return [
            'name' => '',
            'entity_type' => 'vehicle',
            'event_type' => 'vehicle_document_expiry',
            'document_type' => '',
            'days_before' => 30,
            'threshold_km' => null,
            'threshold_tread_depth' => null,
            'channel' => 'email',
            'recipient_mode' => 'admins',
            'enabled' => 1,
            'repeat_until_resolved' => 1,
            'daily_limit_enabled' => 1,
            'recipient_user_ids' => [],
            'approval_resource_types' => [],
            'approval_reasons' => [],
            'approval_repeat_days' => 1,
        ];
    }

    /** Optiunile de motiv sunt aceleasi cu cele din modulul de aprobari. */
    private function approvalReasonOptions(): array
    {
        if (!class_exists(InactiveResourceApprovalModel::class)) {
            return [];
        }

        return (new InactiveResourceApprovalModel($this->db))->getReasonOptions();
    }

    private function mapRuleToFormData(array $rule): array
    {
        $eventType = (string) ($rule['event_type'] ?? 'vehicle_document_expiry');

        $metadata = $rule['metadata'] ?? $rule['metadata_json'] ?? [];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }

        return [
            'approval_resource_types' => array_map('strval', (array) ($metadata['approval_resource_types'] ?? [])),
            'approval_reasons' => array_map('strval', (array) ($metadata['approval_reasons'] ?? [])),
            'approval_repeat_days' => max(1, (int) ($metadata['repeat_days'] ?? 1)),
            'name' => (string) ($rule['name'] ?? ''),
            'entity_type' => (string) ($rule['entity_type'] ?? (self::EVENT_ENTITY[$eventType] ?? 'vehicle')),
            'event_type' => $eventType,
            'document_type' => (string) ($rule['document_type'] ?? ''),
            'days_before' => (int) ($rule['days_before'] ?? 30),
            'threshold_km' => $rule['threshold_km'] ?? null,
            'threshold_tread_depth' => $rule['threshold_tread_depth'] ?? null,
            'channel' => (string) ($rule['channel'] ?? 'email'),
            'recipient_mode' => (string) ($rule['recipient_mode'] ?? 'admins'),
            'enabled' => (int) ($rule['enabled'] ?? 0),
            'repeat_until_resolved' => (int) ($rule['repeat_until_resolved'] ?? 1),
            'daily_limit_enabled' => (int) ($rule['daily_limit_enabled'] ?? 1),
            'recipient_user_ids' => (array) ($rule['recipient_user_ids'] ?? []),
        ];
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $raw = trim((string) ($value ?? ''));
        return $raw === '' ? null : max(0, (int) $raw);
    }

    private function nullablePositiveFloat(mixed $value): ?float
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        return max(0.0, (float) str_replace(',', '.', $raw));
    }

    private function mailSummary(): array
    {
        return [
            'host' => defined('MAIL_HOST') ? (string) MAIL_HOST : '',
            'port' => defined('MAIL_PORT') ? (int) MAIL_PORT : 0,
            'from' => defined('MAIL_FROM_ADDRESS') ? (string) MAIL_FROM_ADDRESS : '',
            'encryption' => defined('MAIL_ENCRYPTION') ? (string) MAIL_ENCRYPTION : '',
        ];
    }

    private function indexUrl(): string
    {
        return build_query_url(['page' => 'notificari']);
    }
}
