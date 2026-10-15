<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/services/ReviziiWeeklyReportService.php';

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
        'revizie' => 'Revizie (km)',
    ];

    /**
     * Evenimente Revizii: pragul de km ramasi vine din Revizii (per componenta), nu din regula.
     * In document_type se pastreaza doar filtrul de stare: '' = Se apropie + Necesita.
     */
    public const REVIZIE_EVENTS = ['revizie_schimb_ulei', 'revizie_placute_frana', 'revizie_anvelope'];

    public const REVIZIE_STATUS_FILTERS = [
        '' => 'Se apropie sau Necesită (prag din Revizii)',
        'necesita' => 'Doar Necesită (interval depășit)',
    ];

    /** Tipuri de resursa pentru regulile de aprobare. Gol = toate. */
    private const APPROVAL_RESOURCE_LABELS = [
        'vehicle' => 'Vehicul',
        'driver' => 'Sofer',
        'repair' => 'Reparatie',
        'diurna' => 'Diurna',
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
        'revizie_schimb_ulei' => 'Schimb ulei',
        'revizie_placute_frana' => 'Plăcuțe frână',
        'revizie_anvelope' => 'Anvelope (revizie km)',
        'revizie_raport_saptamanal' => 'Raport saptamanal revizii (email)',
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
        'revizie_schimb_ulei' => 'revizie',
        'revizie_placute_frana' => 'revizie',
        'revizie_anvelope' => 'revizie',
        'revizie_raport_saptamanal' => 'revizie',
    ];

    /**
     * Ce conditii se aplica fiecarui eveniment in formular:
     * days = foloseste „zile inainte” (valoarea e cea implicita), docs = familia de tipuri
     * filtrabile prin document_type, km / tread = praguri, revizie / approval = filtre proprii.
     */
    private const EVENT_CONDITIONS = [
        'vehicle_document_expiry' => ['days' => 30, 'docs' => 'vehicle'],
        'driver_document_expiry' => ['days' => 30, 'docs' => 'driver'],
        'driver_birthday' => ['days' => 7],
        'leave_starts_soon' => ['days' => 7],
        'tire_km_limit' => ['km' => true],
        'tire_tread_depth' => ['tread' => true],
        'tire_dot_expiry' => ['days' => 90],
        'equipment_expiry' => ['days' => 30, 'docs' => 'equipment'],
        'equipment_inspection' => ['days' => 30, 'docs' => 'equipment'],
        'document_missing' => ['docs' => 'vehicle'],
        'inactive_approval_pending' => ['approval' => true],
        'revizie_schimb_ulei' => ['revizie' => true],
        'revizie_placute_frana' => ['revizie' => true],
        'revizie_anvelope' => ['revizie' => true],
        // Raportul saptamanal: il trimite scripts/revizii_raport_saptamanal.php (cron), doar pe email.
        'revizie_raport_saptamanal' => ['revizie' => true, 'report' => true],
    ];

    private const EVENT_HINTS = [
        'vehicle_document_expiry' => 'RCA, ITP, rovinietă, CASCO… înainte de expirare',
        'driver_document_expiry' => 'Permis, atestat, card tahograf, fișă medicală…',
        'driver_birthday' => 'Cu câteva zile înainte de ziua șoferului',
        'leave_starts_soon' => 'Concediu care începe în curând',
        'tire_km_limit' => 'Anvelopa se apropie de limita de km',
        'tire_tread_depth' => 'Profilul a scăzut sub prag',
        'tire_dot_expiry' => 'Vechimea DOT ajunge la limită',
        'equipment_expiry' => 'Trusă, extinctor, vestă… înainte de expirare',
        'equipment_inspection' => 'Următoarea inspecție a dotării',
        'document_missing' => 'Nu este verificat încă de worker',
        'inactive_approval_pending' => 'Cereri de aprobare nerezolvate',
        'revizie_schimb_ulei' => 'Km rămași până la schimbul de ulei',
        'revizie_placute_frana' => 'Km rămași până la plăcuțe',
        'revizie_anvelope' => 'Km rămași până la anvelope',
        'revizie_raport_saptamanal' => 'Email săptămânal cu situația reviziilor',
    ];

    /** Evenimente / destinatari pe care worker-ul nu ii trateaza: apar doar daca o regula veche ii foloseste. */
    private const HIDDEN_EVENTS = ['document_missing'];
    private const HIDDEN_RECIPIENT_MODES = ['vehicle_responsible'];

    private const MAX_RULES_PER_GROUP = 60;

    private const RECIPIENT_LABELS = [
        'admins' => 'Administratori',
        'vehicle_responsible' => 'Responsabil vehicul',
        'drivers' => 'Doar soferul (SMS)',
        'specific_users' => 'Utilizatori selectati',
    ];

    /** SMS-ul pleaca prin SMSAlert din worker-ul Python (SMSALERT_* in .env). */
    private const CHANNEL_LABELS = [
        'email' => 'Email',
        'sms' => 'SMS',
        'email_sms' => 'Email + SMS',
    ];

    public const SMS_CHANNELS = ['sms', 'email_sms'];

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
            case 'send_test_sms':
                $this->sendTestSmsAction();
                return;
            case 'report_preview':
                $this->reportPreviewAction();
                return;
            case 'report_test':
                $this->reportTestAction();
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
        $editingGroup = [];
        $formData = $this->defaultFormData();
        $formErrors = $formFlash['errors'];

        if ($formFlash['old'] !== []) {
            $formData = array_merge($formData, $formFlash['old']);
        }

        if ($action === 'edit') {
            $id = max(0, (int) ($_GET['id'] ?? 0));
            $editingGroup = $id > 0 ? $this->model->getRuleGroupFor($id) : [];
            foreach ($editingGroup as $member) {
                if ((int) $member['id'] === $id) {
                    $editingRule = $member;
                }
            }
            if ($editingRule === null) {
                flash_set('warning', 'Regula selectata nu exista.');
                redirect($this->indexUrl());
            }

            if ($formFlash['old'] === []) {
                $formData = $this->mapRulesToFormData($editingRule, $editingGroup);
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
            'editingGroupSize' => count($editingGroup),
            'entityLabels' => self::ENTITY_LABELS,
            'eventLabels' => self::EVENT_LABELS,
            'eventEntityMap' => self::EVENT_ENTITY,
            'eventConditions' => self::EVENT_CONDITIONS,
            'eventHints' => self::EVENT_HINTS,
            'hiddenEvents' => self::HIDDEN_EVENTS,
            'hiddenRecipientModes' => self::HIDDEN_RECIPIENT_MODES,
            'maxRulesPerGroup' => self::MAX_RULES_PER_GROUP,
            'recipientLabels' => self::RECIPIENT_LABELS,
            'channelLabels' => self::CHANNEL_LABELS,
            'users' => $this->model->getActiveEmailUsers(),
            'drivers' => $this->model->getActiveDrivers(),
            'reportDays' => ReviziiWeeklyReportService::DAYS,
            'reportCategories' => array_map(static fn (array $tab): string => (string) $tab['label'], ReviziiModel::TABS),
            'reportVehicles' => (new ReviziiModel($this->db))->getVehicles(),
            'vehicleDocumentTypes' => $vehicleDocumentTypes,
            'driverDocumentTypes' => $driverDocumentTypes,
            'equipmentTypes' => $equipmentTypes,
            'approvalEvent' => self::APPROVAL_EVENT,
            'smsChannels' => self::SMS_CHANNELS,
            'revizieEvents' => self::REVIZIE_EVENTS,
            'revizieStatusFilters' => self::REVIZIE_STATUS_FILTERS,
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
        [$items, $recipientUserIds, $errors, $old] = $this->validateRuleInput($_POST);

        if ($errors !== []) {
            set_form_flash($old, $errors);
            redirect(build_query_url(['page' => 'notificari', 'action' => 'create']));
        }

        $group = count($items) > 1 ? $this->newGroupId() : null;
        $counts = $this->model->syncRuleGroup([], $items, $recipientUserIds, $group);
        flash_set('success', $counts['created'] === 1
            ? 'Regula a fost adaugata.'
            : 'Au fost adaugate ' . $counts['created'] . ' reguli (cate una pe eveniment / tip de document).');
        redirect($this->indexUrl());
    }

    private function updateAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        ensure_csrf_or_redirect($this->indexUrl());
        $id = max(0, (int) ($_POST['id'] ?? 0));
        $existing = $id > 0 ? $this->model->getRuleGroupFor($id) : [];
        if ($existing === []) {
            flash_set('danger', 'Regula selectata nu exista.');
            redirect($this->indexUrl());
        }

        [$items, $recipientUserIds, $errors, $old] = $this->validateRuleInput($_POST);
        if ($errors !== []) {
            set_form_flash($old, $errors);
            redirect(build_query_url(['page' => 'notificari', 'action' => 'edit', 'id' => $id]));
        }

        $group = trim((string) ($existing[0]['rule_group'] ?? ''));
        if ($group === '') {
            $group = count($items) > 1 ? $this->newGroupId() : null;
        }

        $counts = $this->model->syncRuleGroup($existing, $items, $recipientUserIds, $group);
        $parts = [];
        if ($counts['updated'] > 0) {
            $parts[] = $counts['updated'] . ' actualizate';
        }
        if ($counts['created'] > 0) {
            $parts[] = $counts['created'] . ' adaugate';
        }
        if ($counts['deleted'] > 0) {
            $parts[] = $counts['deleted'] . ' sterse';
        }
        flash_set('success', count($items) === 1 && $counts['updated'] === 1 && $counts['deleted'] === 0
            ? 'Regula a fost actualizata.'
            : 'Grupul de reguli a fost salvat: ' . implode(', ', $parts) . '.');
        redirect($this->indexUrl());
    }

    /** Setarile raportului din formular (nesalvate), pentru previzualizare si test. */
    private function reportConfigFromInput(array $input): array
    {
        return ReviziiWeeklyReportService::normalizeConfig([
            'day' => $input['report_day'] ?? 1,
            'hour' => $input['report_hour'] ?? 8,
            'categories' => (array) ($input['report_categories'] ?? []),
            'history' => isset($input['report_history']),
            'vehicles' => (array) ($input['report_vehicles'] ?? []),
            'notify_driver' => isset($input['report_notify_driver']),
        ], trim((string) ($input['revizie_status'] ?? '')));
    }

    /** Previzualizarea raportului saptamanal, cu setarile din formular (fara salvare, fara email). */
    private function reportPreviewAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_token'] ?? null)) {
            http_response_code(400);
            echo 'Cerere invalida. Reincarca formularul si incearca din nou.';
            return;
        }

        $report = (new ReviziiWeeklyReportService($this->db))->build($this->reportConfigFromInput($_POST));
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex');
        echo $report['html'];
    }

    /**
     * Trimite acum raportul (setarile din formular) catre utilizatorul curent sau catre destinatarii
     * din formular. Se inregistreaza ca test, deci nu consuma trimiterea programata a saptamanii.
     */
    private function reportTestAction(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_token'] ?? null)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Token CSRF invalid. Reîncarcă pagina.']);
            return;
        }

        $service = new ReviziiWeeklyReportService($this->db);
        $target = (string) ($_POST['report_test_target'] ?? 'me');
        if ($target === 'rule') {
            $mode = (string) ($_POST['recipient_mode'] ?? 'admins');
            if (!in_array($mode, ['admins', 'specific_users'], true)) {
                echo json_encode(['ok' => false, 'error' => 'Raportul se trimite pe email: alege Administratori sau Utilizatori selectați.']);
                return;
            }
            $recipients = $service->resolveRecipients($mode, (array) ($_POST['recipient_user_ids'] ?? []));
        } else {
            $user = current_user() ?? [];
            $recipients = trim((string) ($user['email'] ?? '')) !== ''
                ? [['id' => (int) ($user['id'] ?? 0), 'nume' => (string) ($user['nume'] ?? ''), 'email' => (string) $user['email']]]
                : [];
        }

        if ($recipients === []) {
            echo json_encode(['ok' => false, 'error' => 'Nu există niciun destinatar cu adresă de email.']);
            return;
        }

        $config = $this->reportConfigFromInput($_POST);
        $report = $service->build($config);
        $contextId = 'test:' . date('YmdHis');
        $sent = [];
        $failed = [];
        foreach ($recipients as $recipient) {
            $result = $service->send($recipient, $report, ReviziiWeeklyReportService::CONTEXT_TEST, $contextId);
            if (!empty($result['sent'])) {
                $sent[] = (string) $recipient['email'];
            } else {
                $failed[] = (string) $recipient['email'] . ': ' . (string) ($result['error'] ?? 'eroare necunoscută');
            }
        }

        // „SMS șoferilor” bifat: si testul pune in coada SMS-urile, cu destinatarul testului drept contact.
        $sms = null;
        if ($config['notify_driver']) {
            $sms = $service->queueDriverSms($report, $service->contactFor($recipients), ReviziiWeeklyReportService::CONTEXT_TEST, $contextId);
            foreach ($sms['failed'] as $error) {
                $failed[] = 'SMS ' . $error;
            }
        }

        echo json_encode([
            'ok' => $failed === [],
            'sent' => $sent,
            'failed' => $failed,
            'subject' => $report['subject'],
            'sms' => $sms,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function newGroupId(): string
    {
        return bin2hex(random_bytes(8));
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

    private function sendTestSmsAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }

        $statusUrl = build_query_url(['page' => 'notificari', 'tab' => 'status']);
        ensure_csrf_or_redirect($statusUrl);
        $currentUser = current_user() ?? [];
        $phone = trim((string) ($_POST['test_phone'] ?? ''));

        $result = $this->model->queueSms(
            $phone,
            trim((string) ($currentUser['nume'] ?? '')),
            'Test SMS notificari',
            'Test notificari ' . APP_NAME . ': SMS-ul functioneaza.',
            'notification_test',
            'manual-sms:' . date('YmdHis'),
            ['triggered_by_user_id' => (int) ($currentUser['id'] ?? 0)]
        );

        if ((bool) ($result['queued'] ?? false)) {
            flash_set('success', 'SMS-ul de test catre ' . (string) $result['phone'] . ' a fost pus in coada. Pleaca la urmatoarea rulare a worker-ului.');
            redirect(build_query_url(['page' => 'notificari', 'tab' => 'queue']));
        }

        flash_set('danger', 'SMS-ul de test nu a putut fi pus in coada: ' . (string) ($result['error'] ?? 'eroare necunoscuta'));
        redirect($statusUrl);
    }

    /**
     * Formularul poate bifa mai multe evenimente si mai multe tipuri de document.
     * Worker-ul lucreaza pe o regula = un eveniment, deci aici selectia se desface
     * in cate o regula pentru fiecare pereche eveniment x tip document.
     *
     * @return array{0: array<int, array>, 1: int[], 2: array<string, string>, 3: array}
     */
    private function validateRuleInput(array $input): array
    {
        $eventTypes = $input['event_types'] ?? ($input['event_type'] ?? []);
        $eventTypes = array_values(array_unique(array_filter(
            array_map(static fn ($value): string => trim((string) $value), (array) $eventTypes),
            static fn (string $value): bool => $value !== ''
        )));

        $docTypes = [];
        $docAll = [];
        foreach (['vehicle', 'driver', 'equipment'] as $family) {
            $docAll[$family] = !empty($input['doc_all'][$family]) ? 1 : 0;
            $docTypes[$family] = array_values(array_unique(array_filter(
                array_map(static fn ($value): string => trim((string) $value), (array) ($input['doc_types'][$family] ?? [])),
                static fn (string $value): bool => $value !== ''
            )));
        }

        $shared = [
            'name' => trim((string) ($input['name'] ?? '')),
            'days_before' => max(0, (int) ($input['days_before'] ?? 30)),
            'threshold_km' => $this->nullablePositiveInt($input['threshold_km'] ?? null),
            'threshold_tread_depth' => $this->nullablePositiveFloat($input['threshold_tread_depth'] ?? null),
            'revizie_status' => trim((string) ($input['revizie_status'] ?? '')),
            'channel' => trim((string) ($input['channel'] ?? 'email')),
            'recipient_mode' => trim((string) ($input['recipient_mode'] ?? 'admins')),
            'enabled' => isset($input['enabled']) ? 1 : 0,
            'repeat_until_resolved' => isset($input['repeat_until_resolved']) ? 1 : 0,
            'daily_limit_enabled' => isset($input['daily_limit_enabled']) ? 1 : 0,
            'notify_driver' => isset($input['notify_driver']) ? 1 : 0,
        ];
        if (!array_key_exists($shared['revizie_status'], self::REVIZIE_STATUS_FILTERS)) {
            $shared['revizie_status'] = '';
        }

        $approvalMetadata = [
            'approval_resource_types' => array_values(array_intersect(
                array_map('strval', (array) ($input['approval_resource_types'] ?? [])),
                array_keys(self::APPROVAL_RESOURCE_LABELS)
            )),
            'approval_reasons' => array_values(array_unique(array_filter(
                array_map(static fn ($value): string => trim((string) $value), (array) ($input['approval_reasons'] ?? [])),
                static fn (string $value): bool => $value !== ''
            ))),
            'repeat_days' => max(1, min(30, (int) ($input['approval_repeat_days'] ?? 1))),
        ];

        // Raportul saptamanal: ziua, ora, categoriile si istoricul merg in metadata_json.report.
        $reportMetadata = ReviziiWeeklyReportService::metadataFromConfig($this->reportConfigFromInput($input));

        $recipientUserIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($input['recipient_user_ids'] ?? [])
        ), static fn (int $value): bool => $value > 0)));

        // „Doar soferul”: soferii alesi primesc mereu SMS-ul; gol = soferii alocati vehiculului.
        $activeDriverIds = array_map(static fn (array $driver): int => (int) $driver['id'], $this->model->getActiveDrivers());
        $recipientDriverIds = array_values(array_intersect(
            array_values(array_unique(array_filter(array_map('intval', (array) ($input['recipient_driver_ids'] ?? [])), static fn (int $value): bool => $value > 0))),
            $activeDriverIds
        ));

        $old = array_merge($shared, [
            'recipient_driver_ids' => $recipientDriverIds,
            'event_types' => $eventTypes,
            'doc_types' => $docTypes,
            'doc_all' => $docAll,
            'recipient_user_ids' => $recipientUserIds,
            'approval_resource_types' => $approvalMetadata['approval_resource_types'],
            'approval_reasons' => $approvalMetadata['approval_reasons'],
            'approval_repeat_days' => $approvalMetadata['repeat_days'],
            'report_day' => $reportMetadata['day'],
            'report_hour' => $reportMetadata['hour'],
            'report_categories' => $reportMetadata['categories'],
            'report_history' => $reportMetadata['history'] ? 1 : 0,
            'report_vehicles' => $reportMetadata['vehicles'],
            'report_notify_driver' => $reportMetadata['notify_driver'] ? 1 : 0,
        ]);

        $errors = [];
        if (strlen($shared['name']) > 190) {
            $errors['name'] = 'Numele poate avea maxim 190 de caractere.';
        }

        if ($eventTypes === []) {
            $errors['event_types'] = 'Bifeaza cel putin un eveniment.';
        }
        foreach ($eventTypes as $eventType) {
            if (!array_key_exists($eventType, self::EVENT_LABELS)) {
                $errors['event_types'] = 'Evenimentul „' . $eventType . '” nu exista.';
            }
        }

        $usesCondition = fn (string $key): bool => array_filter(
            $eventTypes,
            static fn (string $event): bool => !empty(self::EVENT_CONDITIONS[$event][$key])
        ) !== [];

        if ($usesCondition('days') && $shared['days_before'] > 365) {
            $errors['days_before'] = 'Alege cel mult 365 de zile.';
        }
        if (in_array('tire_km_limit', $eventTypes, true) && (int) ($shared['threshold_km'] ?? 0) <= 0) {
            $errors['threshold_km'] = 'Completeaza pragul de kilometri.';
        }
        if (in_array('tire_tread_depth', $eventTypes, true) && (float) ($shared['threshold_tread_depth'] ?? 0) <= 0) {
            $errors['threshold_tread_depth'] = 'Completeaza pragul de profil.';
        }

        foreach (['vehicle', 'driver', 'equipment'] as $family) {
            $familyUsed = array_filter(
                $eventTypes,
                static fn (string $event): bool => (self::EVENT_CONDITIONS[$event]['docs'] ?? '') === $family
            ) !== [];
            if (!$familyUsed || $docAll[$family] === 1) {
                $docTypes[$family] = [];
                continue;
            }
            if ($docTypes[$family] === []) {
                $errors['doc_types_' . $family] = 'Bifeaza cel putin un tip sau alege „Toate”.';
            }
            foreach ($docTypes[$family] as $docType) {
                if (strlen($docType) > 120) {
                    $errors['doc_types_' . $family] = 'Tipul de document poate avea maxim 120 de caractere.';
                }
            }
        }

        if (!array_key_exists($shared['channel'], self::CHANNEL_LABELS)) {
            $errors['channel'] = 'Selecteaza canalul.';
        }

        $hasApproval = in_array(self::APPROVAL_EVENT, $eventTypes, true);
        $hasReport = $usesCondition('report');
        if (!array_key_exists($shared['recipient_mode'], self::RECIPIENT_LABELS)) {
            $errors['recipient_mode'] = 'Selecteaza destinatarii.';
        } elseif ($shared['recipient_mode'] === 'specific_users' && $recipientUserIds === []) {
            $errors['recipient_user_ids'] = 'Selecteaza cel putin un utilizator.';
        } elseif ($shared['recipient_mode'] === 'drivers') {
            if ($hasReport) {
                $errors['recipient_mode'] = 'Raportul saptamanal se trimite pe email: alege Administratori sau Utilizatori selectati.';
            } elseif ($hasApproval) {
                $errors['recipient_mode'] = 'Cererile de aprobare nu au un sofer destinatar: scoate-le din selectie sau alege alti destinatari.';
            } elseif ($shared['channel'] !== 'sms') {
                // Soferii nu au email in aplicatie, doar telefon.
                $errors['channel'] = 'Pentru „Doar soferul” alege canalul SMS.';
            }
        }

        $baseName = $shared['name'] !== '' ? $shared['name'] : $this->suggestRuleName($eventTypes, $docTypes);
        $items = [];
        foreach ($eventTypes as $eventType) {
            if (!isset(self::EVENT_LABELS[$eventType])) {
                continue;
            }
            $conditions = self::EVENT_CONDITIONS[$eventType] ?? [];
            $family = (string) ($conditions['docs'] ?? '');
            $documentTypes = [''];
            if (!empty($conditions['revizie'])) {
                $documentTypes = [$shared['revizie_status']];
            } elseif ($family !== '' && $docTypes[$family] !== []) {
                $documentTypes = $docTypes[$family];
            }

            foreach ($documentTypes as $documentType) {
                $metadata = [];
                $isReport = !empty($conditions['report']);
                if ($isReport) {
                    $metadata = ['report' => $reportMetadata];
                } elseif ($eventType === self::APPROVAL_EVENT) {
                    $metadata = $approvalMetadata;
                } elseif (in_array($shared['channel'], self::SMS_CHANNELS, true) && $shared['notify_driver'] === 1) {
                    // SMS si catre soferul vehiculului (sau soferul insusi, la evenimentele de sofer).
                    $metadata['notify_driver'] = true;
                }
                if (!$isReport && $shared['recipient_mode'] === 'drivers' && $recipientDriverIds !== []) {
                    $metadata['driver_ids'] = $recipientDriverIds;
                }

                $items[] = [
                    'event_type' => $eventType,
                    'entity_type' => self::EVENT_ENTITY[$eventType] ?? 'vehicle',
                    'document_type' => $documentType,
                    'days_before' => isset($conditions['days']) ? $shared['days_before'] : 0,
                    'threshold_km' => $eventType === 'tire_km_limit' ? $shared['threshold_km'] : null,
                    'threshold_tread_depth' => $eventType === 'tire_tread_depth' ? $shared['threshold_tread_depth'] : null,
                    // Raportul pleaca doar pe email, indiferent de canalul ales pentru celelalte evenimente.
                    'channel' => $isReport ? 'email' : $shared['channel'],
                    'recipient_mode' => $shared['recipient_mode'],
                    'enabled' => $shared['enabled'],
                    'repeat_until_resolved' => $shared['repeat_until_resolved'],
                    'daily_limit_enabled' => $shared['daily_limit_enabled'],
                    'metadata' => $metadata,
                ];
            }
        }

        if (count($items) > self::MAX_RULES_PER_GROUP) {
            $errors['event_types'] = 'Selectia ar crea ' . count($items) . ' reguli; maximul este ' . self::MAX_RULES_PER_GROUP . '. Imparte-o in mai multe reguli.';
        }

        // Numele fiecarei reguli: numele grupului + ce o deosebeste de celelalte.
        $isGroup = count($items) > 1;
        foreach ($items as &$item) {
            $name = $baseName;
            if ($isGroup) {
                $name .= ' — ' . $this->eventDisplayLabel($item['event_type']);
                if ($item['document_type'] !== '' && empty(self::EVENT_CONDITIONS[$item['event_type']]['revizie'])) {
                    $name .= ' · ' . $item['document_type'];
                }
                $item['metadata']['group_name'] = $baseName;
            }
            $item['name'] = $this->truncate($name, 190);
        }
        unset($item);

        return [$items, $recipientUserIds, $errors, $old];
    }

    private function suggestRuleName(array $eventTypes, array $docTypes): string
    {
        $labels = [];
        foreach ($eventTypes as $eventType) {
            if (!isset(self::EVENT_LABELS[$eventType])) {
                continue;
            }
            $family = (string) (self::EVENT_CONDITIONS[$eventType]['docs'] ?? '');
            $docs = $family !== '' ? ($docTypes[$family] ?? []) : [];
            $labels[] = $docs !== [] && count($docs) <= 3
                ? $this->eventDisplayLabel($eventType) . ' ' . implode(', ', $docs)
                : $this->eventDisplayLabel($eventType);
        }

        return $labels === [] ? 'Regula notificare' : $this->truncate(implode(' + ', $labels), 150);
    }

    private function eventDisplayLabel(string $eventType): string
    {
        $entity = self::EVENT_ENTITY[$eventType] ?? 'vehicle';
        return (self::ENTITY_LABELS[$entity] ?? $entity) . ': ' . (self::EVENT_LABELS[$eventType] ?? $eventType);
    }

    private function truncate(string $value, int $limit): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    private function defaultFormData(): array
    {
        return [
            'name' => '',
            'event_types' => [],
            'doc_types' => ['vehicle' => [], 'driver' => [], 'equipment' => []],
            'doc_all' => ['vehicle' => 1, 'driver' => 1, 'equipment' => 1],
            'days_before' => 30,
            'threshold_km' => null,
            'threshold_tread_depth' => null,
            'revizie_status' => '',
            'channel' => 'email',
            'recipient_mode' => 'admins',
            'enabled' => 1,
            'repeat_until_resolved' => 1,
            'daily_limit_enabled' => 1,
            'recipient_user_ids' => [],
            'recipient_driver_ids' => [],
            'report_day' => 1,
            'report_hour' => 8,
            'report_categories' => [],
            'report_history' => 1,
            'report_vehicles' => [],
            'report_notify_driver' => 0,
            'approval_resource_types' => [],
            'approval_reasons' => [],
            'approval_repeat_days' => 1,
            'notify_driver' => 0,
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

    /**
     * Formularul unui grup: evenimentele si tipurile de document sunt reuniunea regulilor,
     * restul setarilor vin de la regula pe care s-a dat click.
     */
    private function mapRulesToFormData(array $rule, array $group): array
    {
        $metadata = $this->decodeMetadata($rule);
        $approvalMetadata = $metadata;
        $eventTypes = [];
        $docTypes = ['vehicle' => [], 'driver' => [], 'equipment' => []];
        $docAll = ['vehicle' => 1, 'driver' => 1, 'equipment' => 1];
        $revizieStatus = '';
        $daysBefore = null;
        $thresholdKm = null;
        $thresholdTread = null;
        $notifyDriver = false;
        $anyEnabled = false;

        foreach ($group as $member) {
            $eventType = (string) ($member['event_type'] ?? '');
            $documentType = trim((string) ($member['document_type'] ?? ''));
            $conditions = self::EVENT_CONDITIONS[$eventType] ?? [];
            $memberMetadata = $this->decodeMetadata($member);

            if (!in_array($eventType, $eventTypes, true)) {
                $eventTypes[] = $eventType;
            }
            $anyEnabled = $anyEnabled || (int) ($member['enabled'] ?? 0) === 1;
            $notifyDriver = $notifyDriver || !empty($memberMetadata['notify_driver']);

            $family = (string) ($conditions['docs'] ?? '');
            if ($family !== '' && $documentType !== '' && !in_array($documentType, $docTypes[$family], true)) {
                $docTypes[$family][] = $documentType;
            }
            if (!empty($conditions['revizie'])) {
                $revizieStatus = $documentType;
            }
            if (isset($conditions['days']) && ($daysBefore === null || (int) $member['id'] === (int) $rule['id'])) {
                $daysBefore = (int) ($member['days_before'] ?? $conditions['days']);
            }
            if ($eventType === 'tire_km_limit') {
                $thresholdKm = $member['threshold_km'] ?? null;
            }
            if ($eventType === 'tire_tread_depth') {
                $thresholdTread = $member['threshold_tread_depth'] ?? null;
            }
            if ($eventType === self::APPROVAL_EVENT) {
                $approvalMetadata = $memberMetadata;
            }
            if (!empty($conditions['report']) && is_array($memberMetadata['report'] ?? null)) {
                $reportConfig = ReviziiWeeklyReportService::normalizeConfig($memberMetadata['report']);
            }
        }
        $reportConfig ??= ReviziiWeeklyReportService::normalizeConfig(['history' => true]);

        // „Toate” = nicio regula pe un tip anume din familia respectiva.
        foreach (array_keys($docAll) as $family) {
            $docAll[$family] = $docTypes[$family] === [] ? 1 : 0;
        }

        $name = count($group) > 1
            ? (string) ($metadata['group_name'] ?? ($rule['name'] ?? ''))
            : (string) ($rule['name'] ?? '');

        return [
            'name' => $name,
            'event_types' => $eventTypes,
            'doc_types' => $docTypes,
            'doc_all' => $docAll,
            'days_before' => $daysBefore ?? 30,
            'threshold_km' => $thresholdKm,
            'threshold_tread_depth' => $thresholdTread,
            'revizie_status' => $revizieStatus,
            'channel' => (string) ($rule['channel'] ?? 'email'),
            'recipient_mode' => (string) ($rule['recipient_mode'] ?? 'admins'),
            'enabled' => $anyEnabled ? 1 : 0,
            'repeat_until_resolved' => (int) ($rule['repeat_until_resolved'] ?? 1),
            'daily_limit_enabled' => (int) ($rule['daily_limit_enabled'] ?? 1),
            'recipient_user_ids' => (array) ($rule['recipient_user_ids'] ?? []),
            'recipient_driver_ids' => array_values(array_map('intval', (array) ($metadata['driver_ids'] ?? []))),
            'report_day' => $reportConfig['day'],
            'report_hour' => $reportConfig['hour'],
            'report_categories' => $reportConfig['categories'],
            'report_history' => $reportConfig['history'] ? 1 : 0,
            'report_vehicles' => $reportConfig['vehicles'],
            'report_notify_driver' => $reportConfig['notify_driver'] ? 1 : 0,
            'approval_resource_types' => array_map('strval', (array) ($approvalMetadata['approval_resource_types'] ?? [])),
            'approval_reasons' => array_map('strval', (array) ($approvalMetadata['approval_reasons'] ?? [])),
            'approval_repeat_days' => max(1, (int) ($approvalMetadata['repeat_days'] ?? 1)),
            'notify_driver' => $notifyDriver ? 1 : 0,
        ];
    }

    private function decodeMetadata(array $rule): array
    {
        $metadata = $rule['metadata'] ?? $rule['metadata_json'] ?? [];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
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
