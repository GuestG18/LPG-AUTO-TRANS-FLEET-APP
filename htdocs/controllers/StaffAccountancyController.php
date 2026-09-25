<?php
declare(strict_types=1);

class StaffAccountancyController
{
    private StaffAccountancyModel $model;
    private DriverDiurnaModel $diurnaModel;
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->model = new StaffAccountancyModel($db);
        $this->diurnaModel = new DriverDiurnaModel($db);
    }

    public function handle(string $action): void
    {
        require_page_or_403('contabilitate_personal');

        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;
            case 'export':
                $this->exportAction();
                return;
            case 'store_type':
                $this->storeTypeAction();
                return;
            case 'update_type':
                $this->updateTypeAction();
                return;
            case 'add_requirement':
                $this->addRequirementAction();
                return;
            case 'delete_requirement':
                $this->deleteRequirementAction();
                return;
            case 'store_staff':
                $this->storeStaffAction();
                return;
            case 'update_staff':
                $this->updateStaffAction();
                return;
            case 'end_activity':
                if (function_exists('can') && !can('contabilitate_personal', 'end_activity')) {
                    access_deny_403();
                }
                $this->endActivityAction();
                return;
            case 'delete_staff':
                $this->deleteStaffAction();
                return;
            case 'update_salary':
                $this->updateSalaryAction();
                return;
            case 'update_diurna':
                $this->updateDiurnaAction();
                return;
            case 'store_document':
                $this->storeDocumentAction();
                return;
            case 'delete_document':
                $this->deleteDocumentAction();
                return;
            // Luna contabila. Toate trec prin require_page_or_403 de mai sus; scrierile
            // de pontaj / cost cer in plus dreptul "Salarii & istoric salarial".
            case 'employee_detail':
                $this->employeeDetailAction();
                return;
            case 'save_month':
                $this->requireSalaryRight();
                $this->saveMonthAction();
                return;
            case 'finalize_month':
                $this->requireSalaryRight();
                $this->finalizeMonthAction();
                return;
            case 'reopen_month':
                if (!is_admin()) {
                    access_deny_403();
                }
                $this->reopenMonthAction();
                return;
            case 'update_work_regime':
                $this->updateWorkRegimeAction();
                return;
            case 'payroll_toggle':
                $this->requireAction('payroll_config');
                $this->payrollToggleAction();
                return;
            case 'payroll_calculate':
                $this->requireAction('payroll_calculate');
                $this->requireFiscalEnabled();
                $this->payrollCalculateAction();
                return;
            case 'payroll_calculate_month':
                $this->requireAction('payroll_calculate');
                $this->requireFiscalEnabled();
                $this->payrollCalculateMonthAction();
                return;
            case 'payroll_confirm':
                $this->requireAction('payroll_confirm');
                $this->requireFiscalEnabled();
                $this->payrollConfirmAction();
                return;
            case 'payroll_reopen':
                $this->requireAction('payroll_reopen');
                $this->payrollReopenAction();
                return;
            case 'payroll_profile_save':
                $this->requireAction('payroll_calculate');
                $this->requireFiscalEnabled();
                $this->payrollProfileSaveAction();
                return;
            case 'payroll_item_add':
                $this->requireAction('payroll_calculate');
                $this->requireFiscalEnabled();
                $this->payrollItemAddAction();
                return;
            case 'payroll_item_delete':
                $this->requireAction('payroll_calculate');
                $this->payrollItemDeleteAction();
                return;
            case 'payroll_rule_save':
                $this->requireAction('payroll_config');
                $this->payrollRuleSaveAction();
                return;
            case 'payroll_item_type_save':
                $this->requireAction('payroll_config');
                $this->payrollItemTypeSaveAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'contabilitate_personal',
                ]);
                return;
        }
    }

    /**
     * Punct de intrare pentru incheierea colaborarii declansata din lista Soferi
     * (?page=soferi&action=end_employment). Dreptul este validat de ModuleController
     * pe cheia 'soferi.end_employment', deci nu mai cerem acces la Contabilitate Personal.
     */
    public function endDriverEmployment(): void
    {
        $_POST['source_type'] = 'driver';
        $_POST['return_to'] = 'soferi';
        $this->endActivityAction();
    }

    public function handleFormerEmployees(string $action): void
    {
        require_page_or_403('fosti_angajati');

        switch ($action) {
            case 'index':
            case 'list':
                $this->formerEmployeesAction();
                return;
            case 'export':
                $this->exportFormerEmployeesAction();
                return;
            case 'history_sheet':
                $this->historySheetAction();
                return;
            case 'update_termination':
                $this->updateTerminationAction();
                return;
            case 'update_staff':
                $this->updateStaffAction();
                return;
            case 'rehire':
                $this->rehireAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'contabilitate_personal',
                ]);
                return;
        }
    }

    private function indexAction(): void
    {
        $filters = $this->collectFilters();
        $sort = trim((string) ($_GET['sort'] ?? 'updated_at'));
        $direction = strtolower(trim((string) ($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $period = StaffMonthlyAccountingService::parsePeriod((string) ($_GET['luna'] ?? ''));
        $calendarMonth = $this->legalMonth($period);

        try {
            // Toti angajatii intr-un singur tabel: filtrele din antet (ca in Desfasurator)
            // lucreaza in browser pe valorile din tabel, deci nu se mai pagineaza.
            $result = $this->model->getPaginatedStaff($filters, $sort, $direction, 1, 5000);
            $rows = $result['rows'];
            $salaryHistoryBySubject = $this->model->getSalaryHistoryForRows($rows);
            $monthService = new StaffMonthlyAccountingService($this->model);
            $payrollModel = $this->payrollModel();
            // Calculul fiscal este optional; implicit costul = salariul configurat.
            $fiscalEnabled = $payrollModel->isFiscalCalculationEnabled();
            $viewData = [
                'period' => $period,
                'calendarMonth' => $calendarMonth,
                'monthCost' => $this->model->getMonthCostSummary($period['period']),
                'fiscalEnabled' => $fiscalEnabled,
                'configuredSalaryCost' => $fiscalEnabled ? null : $this->model->getConfiguredSalaryCost($period['start'], $period['end']),
                'payrollStatusBySubject' => $fiscalEnabled ? $this->payrollService()->statusForRows($rows, $period) : [],
                'payrollProfiles' => $fiscalEnabled ? $payrollModel->getProfilesForSubjects(PayrollMonthService::subjectPairs($rows)) : [],
                'payrollTotals' => $fiscalEnabled ? $payrollModel->getMonthTotals($period['period']) : null,
                'payrollRuleLookup' => $fiscalEnabled ? $payrollModel->findRuleForPeriod($period['start'], $period['end'], $period['label']) : null,
                // Configurarea se vede de toti cei cu acces la pagina; se modifica doar cu payroll_config.
                'payrollConfig' => $fiscalEnabled ? [
                    'rules' => $payrollModel->listRules(),
                    'item_types' => $payrollModel->listItemTypes(),
                    'audit' => $payrollModel->getRuleAudit(40),
                ] : null,
                'payrollBulkSummary' => $this->pullBulkSummary(),
                'documentStatusCounts' => $this->model->countStaffByDocumentStatus($filters),
                'monthBySubject' => $monthService->buildForRows($rows, $salaryHistoryBySubject, $period, $calendarMonth['working_days'] ?? null),
                'pageTitle' => 'Contabilitate Personal',
                'currentPage' => 'contabilitate_personal',
                'summary' => $this->model->getSummary(),
                'staffTypes' => $this->model->getStaffTypesWithRequirements(),
                'staffTypeOptions' => $this->model->getStaffTypeOptions(true),
                'allStaffTypeOptions' => $this->model->getStaffTypeOptions(false),
                'driverOptions' => $this->model->getDriverOptions(),
                'vehicleOptions' => $this->model->getVehicleOptions(),
                'documentTypeOptionsByStaffType' => $this->model->getDocumentTypeOptionsByStaffType(),
                'rows' => $rows,
                'documentsBySubject' => $this->model->getDocumentsForRows($rows),
                'salaryHistoryBySubject' => $salaryHistoryBySubject,
                'diurnaHistoryByDriver' => $this->diurnaModel->getHistoryForDrivers(array_map(
                    static fn (array $row): int => (int) ($row['source_id'] ?? 0),
                    array_filter($rows, static fn (array $row): bool => (string) ($row['source_type'] ?? '') === 'driver')
                )),
                'filters' => $filters,
                'sort' => $sort,
                'direction' => $direction,
                'pagination' => [
                    'page' => $result['page'],
                    'total_pages' => $result['total_pages'],
                    'total_rows' => $result['total_rows'],
                    'per_page' => ITEMS_PER_PAGE,
                ],
            ];
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][index] ' . $exception->getMessage());
            flash_set('danger', 'Modulul Contabilitate Personal necesita actualizarea bazei de date. Ruleaza database/update_contabilitate_personal.sql.');
            $viewData = [
                'period' => $period,
                'calendarMonth' => $calendarMonth,
                'monthCost' => ['record_count' => 0, 'paid_count' => 0, 'finalized_count' => 0, 'total_cost' => null],
                'monthBySubject' => [],
                'pageTitle' => 'Contabilitate Personal',
                'currentPage' => 'contabilitate_personal',
                'summary' => ['total_personal' => 0, 'total_salarii' => 0, 'personal_operational' => 0, 'personal_birou' => 0],
                'staffTypes' => [],
                'staffTypeOptions' => [],
                'allStaffTypeOptions' => [],
                'driverOptions' => [],
                'documentTypeOptionsByStaffType' => [],
                'rows' => [],
                'documentsBySubject' => [],
                'salaryHistoryBySubject' => [],
                'filters' => $filters,
                'sort' => $sort,
                'direction' => $direction,
                'pagination' => ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => ITEMS_PER_PAGE],
            ];
        }

        render('contabilitate_personal/index.php', $viewData);
    }

    private function formerEmployeesAction(): void
    {
        $filters = $this->collectFormerFilters();
        $sort = trim((string) ($_GET['sort'] ?? 'data_plecare'));
        $direction = strtolower(trim((string) ($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($_GET['p'] ?? 1));

        try {
            $result = $this->model->getPaginatedFormerEmployees($filters, $sort, $direction, $page, ITEMS_PER_PAGE);
            $rows = $result['rows'];
            $viewData = [
                'pageTitle' => 'Foști angajați',
                'currentPage' => 'contabilitate_personal',
                'summary' => $this->model->getFormerSummary(),
                'rows' => $rows,
                'documentsBySubject' => $this->model->getDocumentsForRows($rows),
                'profilesBySubject' => $this->buildFormerProfilesForRows($rows),
                'terminationReasons' => $this->model->getTerminationReasons(),
                'staffTypeOptions' => $this->model->getStaffTypeOptions(true),
                'filters' => $filters,
                'sort' => $sort,
                'direction' => $direction,
                'pagination' => [
                    'page' => $result['page'],
                    'total_pages' => $result['total_pages'],
                    'total_rows' => $result['total_rows'],
                    'per_page' => ITEMS_PER_PAGE,
                ],
            ];
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][former] ' . $exception->getMessage());
            flash_set('danger', 'Pagina Foști angajați necesită actualizarea bazei de date. Rulează migrările pentru contabilitate personal.');
            $viewData = [
                'pageTitle' => 'Foști angajați',
                'currentPage' => 'contabilitate_personal',
                'summary' => ['total_former' => 0, 'former_operational' => 0, 'former_office' => 0, 'former_last_12_months' => 0],
                'rows' => [],
                'documentsBySubject' => [],
                'profilesBySubject' => [],
                'terminationReasons' => array_combine(StaffAccountancyModel::TERMINATION_REASONS, StaffAccountancyModel::TERMINATION_REASONS),
                'staffTypeOptions' => [],
                'filters' => $filters,
                'sort' => $sort,
                'direction' => $direction,
                'pagination' => ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => ITEMS_PER_PAGE],
            ];
        }

        render('fosti_angajati/index.php', $viewData);
    }

    private function exportFormerEmployeesAction(): void
    {
        $filters = $this->collectFormerFilters();
        $sort = trim((string) ($_GET['sort'] ?? 'data_plecare'));
        $direction = strtolower(trim((string) ($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $rows = $this->model->getAllFormerEmployeesForExport($filters, $sort, $direction);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="fosti_angajati_' . date('Ymd_His') . '.csv"');
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            exit;
        }

        fputcsv($output, [
            'Nume',
            'Tip personal',
            'Funcție',
            'Data angajării',
            'Data plecării',
            'Vechime',
            'Motiv plecare',
            'Sursa',
            'Număr documente',
            'Eligibil pentru reangajare',
        ], ';');

        foreach ($rows as $row) {
            fputcsv($output, [
                (string) ($row['nume'] ?? ''),
                $this->categoryLabel((string) ($row['category'] ?? '')),
                (string) ($row['functie'] ?? ''),
                !empty($row['data_angajare']) ? format_date_ro((string) $row['data_angajare']) : '',
                !empty($row['termination_effective_date']) ? format_date_ro((string) $row['termination_effective_date']) : '',
                $this->seniorityLabel((int) ($row['active_days'] ?? 0)),
                (string) ($row['termination_reason'] ?? ''),
                (string) ($row['source_label'] ?? ''),
                (string) ($row['document_count'] ?? 0),
                (int) ($row['rehire_eligible'] ?? 0) === 1 ? 'Da' : 'Nu',
            ], ';');
        }

        fclose($output);
        exit;
    }

    private function historySheetAction(): void
    {
        $sourceType = $this->normalizeSourceType((string) ($_GET['source_type'] ?? ''));
        $sourceId = (int) ($_GET['source_id'] ?? 0);
        $profile = $this->model->getFormerProfile($sourceType, $sourceId);
        if ($profile === null) {
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => 'Fost angajat negăsit',
                'currentPage' => 'contabilitate_personal',
            ]);
            return;
        }

        header('Content-Type: text/html; charset=UTF-8');
        $pageTitle = 'Fișă istoric angajat';
        $generatedBy = (string) (current_user()['nume'] ?? '-');
        ob_start();
        require BASE_PATH . '/views/fosti_angajati/history_sheet.php';
        $content = (string) ob_get_clean();
        echo function_exists('normalize_romanian_text') ? normalize_romanian_text($content) : $content;
        exit;
    }

    private function exportAction(): void
    {
        $filters = $this->collectFilters();
        $sort = trim((string) ($_GET['sort'] ?? 'updated_at'));
        $direction = strtolower(trim((string) ($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $rows = $this->model->getAllStaffForExport($filters, $sort, $direction);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="contabilitate_personal_' . date('Ymd_His') . '.csv"');
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            exit;
        }

        fputcsv($output, [
            'Nume',
            'Tip personal',
            'Categorie',
            'Funcție',
            'Telefon',
            'Salariu lunar',
            'Data angajării',
            'Data încetării',
            'Zile active',
            'Documente',
            'Status documente',
            'Status',
            'Actualizat la',
        ], ';');

        foreach ($rows as $row) {
            fputcsv($output, [
                (string) ($row['nume'] ?? ''),
                (string) ($row['staff_type_name'] ?? ''),
                $this->categoryLabel((string) ($row['category'] ?? '')),
                (string) ($row['functie'] ?? ''),
                (string) ($row['telefon'] ?? ''),
                $row['salariu'] !== null ? format_number_ro($row['salariu'], 2) : '',
                !empty($row['data_angajare']) ? format_date_ro((string) $row['data_angajare']) : '',
                !empty($row['data_incetare']) ? format_date_ro((string) $row['data_incetare']) : '',
                (string) ($row['active_days'] ?? ''),
                (string) ($row['document_count'] ?? 0),
                $this->documentStatusLabel((string) ($row['document_status'] ?? '')),
                (string) ($row['status'] ?? ''),
                !empty($row['updated_at']) ? format_datetime_ro((string) $row['updated_at']) : '',
            ], ';');
        }

        fclose($output);
        exit;
    }

    private function storeTypeAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $data = $this->collectStaffTypeInput($_POST);
        $requirements = $this->collectRequirementInput($_POST);
        $errors = $this->validateStaffTypeInput($data);
        if ($this->isReservedDriverTypeName((string) ($data['name'] ?? ''))) {
            $errors[] = 'Tipul Șofer este deja conectat la pagina Șoferi și nu poate fi duplicat.';
        }
        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($this->indexUrl());
        }

        try {
            $this->model->createStaffTypeWithRequirements($data, $requirements, $this->currentUserId());
            flash_set('success', $requirements === []
                ? 'Tipul de personal a fost adaugat.'
                : 'Tipul de personal si documentele obligatorii au fost adaugate.'
            );
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][store_type] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva tipul de personal.');
        }

        redirect($this->indexUrl());
    }

    private function updateTypeAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $id = (int) ($_POST['id'] ?? 0);
        $data = $this->collectStaffTypeInput($_POST);
        $errors = $this->validateStaffTypeInput($data);
        if ($id <= 0) {
            $errors[] = 'Tipul de personal este invalid.';
        }

        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($this->indexUrl());
        }

        try {
            if (!$this->model->updateStaffType($id, $data, $this->currentUserId())) {
                flash_set('warning', 'Tipul de personal nu a fost gasit.');
            } else {
                flash_set('success', 'Configurarea tipului de personal a fost actualizata.');
            }
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][update_type] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut actualiza configurarea.');
        }

        redirect($this->indexUrl());
    }

    private function addRequirementAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $staffTypeId = (int) ($_POST['staff_type_id'] ?? 0);
        $documentType = trim((string) ($_POST['document_type'] ?? ''));
        $requiresExpiry = (string) ($_POST['requires_expiry'] ?? '0') === '1';
        $warningDays = (int) ($_POST['warning_days'] ?? 30);

        if ($staffTypeId <= 0 || $documentType === '') {
            flash_set('danger', 'Completeaza tipul de document.');
            redirect($this->indexUrl());
        }

        try {
            $this->model->addRequirement($staffTypeId, $documentType, $requiresExpiry, $warningDays);
            flash_set('success', 'Documentul obligatoriu a fost salvat.');
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][add_requirement] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva documentul obligatoriu.');
        }

        redirect($this->indexUrl());
    }

    private function deleteRequirementAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'Document obligatoriu invalid.');
            redirect($this->indexUrl());
        }

        try {
            $deleted = $this->model->deleteRequirement($id);
            flash_set($deleted !== null ? 'success' : 'warning', $deleted !== null ? 'Documentul obligatoriu a fost eliminat.' : 'Documentul obligatoriu nu a fost gasit.');
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][delete_requirement] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut elimina documentul obligatoriu.');
        }

        redirect($this->indexUrl());
    }

    private function storeStaffAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $staffTypeId = (int) ($_POST['staff_type_id'] ?? 0);
        $type = $this->model->findStaffType($staffTypeId);
        if ($type === null || (string) ($type['status'] ?? '') !== 'activ') {
            flash_set('danger', 'Selectează un tip de personal activ.');
            redirect($this->indexUrl());
        }

        $preparedDocuments = [];
        try {
            if ((int) ($type['is_driver_linked'] ?? 0) === 1 && (string) ($_POST['driver_mode'] ?? '') === 'colaborator') {
                $this->storeCollaboratorDriverFromStaffForm();
            } elseif ((int) ($type['is_driver_linked'] ?? 0) === 1) {
                $this->storeDriverAccountingFromStaffForm();
            } else {
                $data = $this->collectStaffMemberInput($_POST, $staffTypeId, $type);
                $errors = $this->validateStaffMemberInput($data);
                if ($errors !== []) {
                    flash_set('danger', implode(' ', $errors));
                    redirect($this->indexUrl());
                }

                $preparedDocuments = $this->collectStaffDocumentInput($_POST, $_FILES);
                $this->model->createDirectStaffWithDocuments($data, $preparedDocuments, $this->currentUserId());
                flash_set('success', $preparedDocuments === []
                    ? 'Personalul a fost adaugat.'
                    : 'Personalul si documentele initiale au fost adaugate.'
                );
            }
        } catch (Throwable $exception) {
            $this->cleanupPreparedDocuments($preparedDocuments);
            error_log('[StaffAccountancyController][store_staff] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva personalul.');
        }

        redirect($this->indexUrl());
    }

    private function updateTerminationAction(): void
    {
        $this->requirePost('fosti_angajati');
        ensure_csrf_or_redirect($this->formerUrl());

        $sourceType = $this->normalizeSourceType((string) ($_POST['source_type'] ?? ''));
        $sourceId = (int) ($_POST['source_id'] ?? 0);
        [$data, $errors] = $this->collectTerminationInput($_POST);
        if ($sourceType === '' || $sourceId <= 0) {
            $errors[] = 'Persoana selectata este invalida.';
        }

        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($this->formerUrl());
        }

        [$fileData, $uploadError] = $this->storeUploadedDocumentFile($_FILES['termination_document'] ?? null);
        if ($uploadError !== null) {
            flash_set('danger', $uploadError);
            redirect($this->formerUrl());
        }

        try {
            if (!$this->model->updateTermination($sourceType, $sourceId, $data, $fileData, $this->currentUserId())) {
                flash_set('warning', 'Fostul angajat nu a fost găsit.');
            } else {
                flash_set('success', 'Datele plecării au fost actualizate.');
            }
        } catch (InvalidArgumentException $exception) {
            if (is_array($fileData)) {
                $this->deleteDocumentPhysicalFile((string) ($fileData['fisier_stocat'] ?? ''));
            }
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            if (is_array($fileData)) {
                $this->deleteDocumentPhysicalFile((string) ($fileData['fisier_stocat'] ?? ''));
            }
            error_log('[StaffAccountancyController][update_termination] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut actualiza datele plecării.');
        }

        redirect($this->formerUrl());
    }

    private function rehireAction(): void
    {
        $this->requirePost('fosti_angajati');
        ensure_csrf_or_redirect($this->formerUrl());

        $sourceType = $this->normalizeSourceType((string) ($_POST['source_type'] ?? ''));
        $sourceId = (int) ($_POST['source_id'] ?? 0);
        $staffTypeId = (int) ($_POST['staff_type_id'] ?? 0);
        $functionName = trim((string) ($_POST['function_name'] ?? ''));
        $hireDate = $this->normalizeDate((string) ($_POST['hire_date'] ?? ''));
        $salary = $this->parseMoney($_POST['salary'] ?? null);
        $notes = trim((string) ($_POST['rehire_notes'] ?? ''));

        $errors = [];
        if ($sourceType === '' || $sourceId <= 0) {
            $errors[] = 'Persoana selectata este invalida.';
        }
        if ($hireDate === null) {
            $errors[] = 'Noua data de angajare este obligatorie.';
        }
        if ($sourceType === 'staff' && $staffTypeId <= 0) {
            $errors[] = 'Tipul de personal este obligatoriu.';
        }
        if ($sourceType === 'staff' && $functionName === '') {
            $errors[] = 'Functia este obligatorie.';
        }
        if (isset($_POST['salary']) && trim((string) $_POST['salary']) !== '' && $salary === null) {
            $errors[] = 'Salariul trebuie sa fie numeric.';
        }

        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($this->formerUrl());
        }

        try {
            $ok = $this->model->rehireEmployee($sourceType, $sourceId, [
                'hire_date' => (string) $hireDate,
                'staff_type_id' => $staffTypeId,
                'function_name' => $functionName,
                'salary' => $salary,
                'rehire_notes' => $notes !== '' ? $notes : null,
            ], $this->currentUserId());

            flash_set($ok ? 'success' : 'warning', $ok ? 'Angajatul a fost reactivat.' : 'Fostul angajat nu a fost găsit.');
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][rehire] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut reangaja persoana selectata.');
        }

        redirect($this->formerUrl());
    }

    private function updateStaffAction(): void
    {
        $returnUrl = $this->staffEditReturnUrl();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($returnUrl);
        }
        ensure_csrf_or_redirect($returnUrl);

        $id = (int) ($_POST['id'] ?? 0);
        $staffTypeId = (int) ($_POST['staff_type_id'] ?? 0);
        $type = $this->model->findStaffType($staffTypeId);
        if ($id <= 0 || $type === null || (int) ($type['is_driver_linked'] ?? 0) === 1) {
            flash_set('danger', 'Inregistrarea selectata nu poate fi editata aici.');
            redirect($returnUrl);
        }

        $data = $this->collectStaffMemberInput($_POST, $staffTypeId, $type);
        $errors = $this->validateStaffMemberInput($data, false);
        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($returnUrl);
        }

        try {
            if (!$this->model->updateDirectStaff($id, $data, $this->currentUserId())) {
                flash_set('warning', 'Personalul nu a fost gasit.');
            } else {
                flash_set('success', 'Personalul a fost actualizat.');
            }
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][update_staff] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut actualiza personalul.');
        }

        redirect($returnUrl);
    }

    private function endActivityAction(): void
    {
        // Actiunea poate fi declansata si din lista Soferi, unde utilizatorul nu are
        // neaparat acces la Contabilitate Personal: intoarcerea se face pe pagina de origine.
        $returnUrl = $this->terminationReturnUrl();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($returnUrl);
        }
        ensure_csrf_or_redirect($returnUrl);

        $sourceType = $this->normalizeSourceType((string) ($_POST['source_type'] ?? ''));
        $sourceId = (int) ($_POST['source_id'] ?? 0);
        [$termination, $errors] = $this->collectTerminationInput($_POST);

        if ($sourceType === '' || $sourceId <= 0) {
            $errors[] = 'Selectează persoana pentru care vrei să închei colaborarea.';
        }

        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($this->terminationReturnUrl());
        }

        [$fileData, $uploadError] = $this->storeUploadedDocumentFile($_FILES['termination_document'] ?? null);
        if ($uploadError !== null) {
            flash_set('danger', $uploadError);
            redirect($this->terminationReturnUrl());
        }

        try {
            $termination['fileData'] = $fileData;
            if (!$this->model->endEmployment($sourceType, $sourceId, $termination, $this->currentUserId())) {
                flash_set('warning', 'Persoana selectata nu a fost gasita.');
            } else {
                flash_set('success', 'Colaborarea a fost încheiată. Persoana apare acum în Foști angajați.');
            }
        } catch (InvalidArgumentException $exception) {
            if (is_array($fileData)) {
                $this->deleteDocumentPhysicalFile((string) ($fileData['fisier_stocat'] ?? ''));
            }
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            if (is_array($fileData)) {
                $this->deleteDocumentPhysicalFile((string) ($fileData['fisier_stocat'] ?? ''));
            }
            error_log('[StaffAccountancyController][end_activity] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut incheia colaborarea persoanei selectate.');
        }

        redirect($this->terminationReturnUrl());
    }

    private function deleteStaffAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'Inregistrare invalida.');
            redirect($this->indexUrl());
        }

        try {
            if (!$this->model->deleteDirectStaff($id)) {
                flash_set('warning', 'Personalul nu a fost gasit.');
            } else {
                flash_set('success', 'Personalul a fost sters.');
            }
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][delete_staff] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut sterge personalul.');
        }

        redirect($this->indexUrl());
    }

    private function updateSalaryAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $sourceType = $this->normalizeSourceType((string) ($_POST['source_type'] ?? ''));
        $sourceId = (int) ($_POST['source_id'] ?? 0);
        $salary = $this->parseMoney($_POST['salariu'] ?? null);
        $effectiveDate = $this->normalizeDate((string) ($_POST['effective_date'] ?? '')) ?: date('Y-m-d');
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($sourceType === '' || $sourceId <= 0 || $salary === null) {
            flash_set('danger', 'Completeaza salariul si persoana selectata.');
            redirect($this->indexUrl());
        }

        try {
            if (!$this->model->updateSalary($sourceType, $sourceId, $salary, $effectiveDate, $notes !== '' ? $notes : null, $this->currentUserId())) {
                flash_set('warning', 'Persoana selectata nu a fost gasita.');
            } else {
                flash_set('success', 'Salariul a fost actualizat si istoricul a fost pastrat.');
            }
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][update_salary] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut actualiza salariul.');
        }

        redirect($this->indexUrl());
    }

    /**
     * Diurna soferului: primeste sau nu, si valoarea pe zi, de la o data.
     * Fiecare salvare adauga un rand in istoric (nu il rescrie pe cel vechi).
     */
    private function updateDiurnaAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $driverId = (int) ($_POST['driver_id'] ?? 0);
        $receives = (string) ($_POST['primeste_diurna'] ?? '1') === '1';
        $rawRate = trim((string) ($_POST['valoare_zi'] ?? ''));
        $rate = $rawRate !== '' ? $this->parseMoney($rawRate) : null;
        $effectiveDate = $this->normalizeDate((string) ($_POST['data_aplicare'] ?? '')) ?: date('Y-m-d');
        $notes = mb_substr(trim((string) ($_POST['observatii'] ?? '')), 0, 255);

        if ($driverId <= 0 || $this->model->findDriver($driverId) === null) {
            flash_set('danger', 'Selectează un șofer existent.');
            redirect($this->indexUrl());
        }
        if ($receives && ($rate === null || $rate <= 0)) {
            flash_set('danger', 'Completează valoarea diurnei pe zi (mai mare decât 0) sau alege „Nu primește diurnă”.');
            redirect($this->indexUrl());
        }

        try {
            $this->diurnaModel->save($driverId, $receives, $receives ? $rate : null, $effectiveDate, $notes !== '' ? $notes : null, $this->currentUserId());
            flash_set('success', $receives
                ? 'Diurna șoferului a fost stabilită: ' . format_number_ro((float) $rate, 2) . ' lei / zi, de la ' . format_date_ro($effectiveDate) . '.'
                : 'Șoferul nu primește diurnă de la ' . format_date_ro($effectiveDate) . '.');
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][update_diurna] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva diurna.');
        }

        redirect($this->indexUrl());
    }

    private function storeDocumentAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $sourceType = $this->normalizeSourceType((string) ($_POST['source_type'] ?? ''));
        $sourceId = (int) ($_POST['source_id'] ?? 0);
        $data = [
            'tip_document' => trim((string) ($_POST['tip_document'] ?? '')),
            'numar_document' => trim((string) ($_POST['numar_document'] ?? '')) ?: null,
            'data_emitere' => $this->normalizeDate((string) ($_POST['data_emitere'] ?? '')),
            'data_expirare' => $this->normalizeDate((string) ($_POST['data_expirare'] ?? '')),
            'observatii' => trim((string) ($_POST['observatii'] ?? '')) ?: null,
        ];

        if ($sourceType === '' || $sourceId <= 0 || $data['tip_document'] === '') {
            flash_set('danger', 'Selectează persoana și tipul documentului.');
            redirect($this->indexUrl());
        }

        if (!$this->model->subjectExists($sourceType, $sourceId)) {
            flash_set('warning', 'Persoana selectata nu a fost gasita.');
            redirect($this->indexUrl());
        }

        if (!$this->model->isDocumentTypeAllowedForSubject($sourceType, $sourceId, $data['tip_document'])) {
            flash_set('danger', 'Tipul documentului trebuie configurat la tipul de personal înainte de încărcare.');
            redirect($this->indexUrl());
        }

        [$fileData, $uploadError] = $this->storeUploadedDocumentFile($_FILES['fisier_upload'] ?? null);
        if ($uploadError !== null) {
            flash_set('danger', $uploadError);
            redirect($this->indexUrl());
        }

        try {
            $this->model->saveDocument($sourceType, $sourceId, $data, $fileData, $this->currentUserId());
            flash_set('success', 'Documentul a fost salvat.');
        } catch (Throwable $exception) {
            if ($fileData !== null) {
                $this->deleteDocumentPhysicalFile((string) ($fileData['fisier_stocat'] ?? ''));
            }
            error_log('[StaffAccountancyController][store_document] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva documentul.');
        }

        redirect($this->indexUrl());
    }

    private function deleteDocumentAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $sourceType = $this->normalizeSourceType((string) ($_POST['source_type'] ?? ''));
        $documentId = (int) ($_POST['document_id'] ?? 0);
        if ($sourceType === '' || $documentId <= 0) {
            flash_set('warning', 'Document invalid.');
            redirect($this->indexUrl());
        }

        try {
            $document = $this->model->deleteDocument($sourceType, $documentId);
            if ($document === null) {
                flash_set('warning', 'Documentul nu a fost gasit.');
            } else {
                $this->deleteDocumentPhysicalFile((string) ($document['fisier_stocat'] ?? ''));
                flash_set('success', 'Documentul a fost sters.');
            }
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][delete_document] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut sterge documentul.');
        }

        redirect($this->indexUrl());
    }

    /**
     * Detaliul lunar al unui angajat (randul extins), incarcat la cerere: expandarea
     * unui rand nu incarca detaliile tuturor angajatilor.
     */
    private function employeeDetailAction(): void
    {
        $sourceType = $this->normalizeSourceType((string) ($_GET['source_type'] ?? ''));
        $sourceId = (int) ($_GET['source_id'] ?? 0);
        $row = $sourceType !== '' && $sourceId > 0 ? $this->model->findSubject($sourceType, $sourceId) : null;
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        if ($row === null) {
            http_response_code(404);
            echo '<div class="alert alert-warning m-3">Angajatul nu a fost găsit.</div>';
            exit;
        }

        $period = StaffMonthlyAccountingService::parsePeriod((string) ($_GET['luna'] ?? ''));
        $calendarMonth = $this->legalMonth($period);
        $key = $sourceType . '-' . $sourceId;
        $salaryHistory = $this->model->getSalaryHistoryForRows([$row])[$key] ?? [];
        $documents = $this->model->getDocumentsForRows([$row])[$key] ?? [];
        $month = (new StaffMonthlyAccountingService($this->model))->buildForSubject($row, $salaryHistory, $period, $calendarMonth['working_days'] ?? null);
        $diurnaPolicy = $sourceType === 'driver'
            ? DriverDiurnaModel::policyAt($this->diurnaModel->getHistoryForDrivers([$sourceId])[$sourceId] ?? [], null)
            : null;
        $canEditSalary = can('contabilitate_personal', 'salaries');
        $canReopen = is_admin();
        $canOpenLeavePlanning = can('programare_concedii');
        $payrollModel = $this->payrollModel();
        $fiscalEnabled = $payrollModel->isFiscalCalculationEnabled();
        $payrollService = $this->payrollService();
        $payrollStatus = $fiscalEnabled ? $payrollService->statusForRows([$row], $period)[$key] : ['record' => null, 'stale' => false];
        $payrollRecord = $payrollStatus['record'];
        $payrollAudit = $payrollRecord !== null ? $payrollModel->getPayrollAudit((int) $payrollRecord['id']) : [];
        $payrollProfile = $fiscalEnabled ? ($payrollModel->getProfilesForSubjects([$sourceType => [$sourceId]])[$key] ?? null) : null;
        $payrollItems = $fiscalEnabled ? ($payrollModel->getItemsForSubjects([$sourceType => [$sourceId]], $period['period'])[$key] ?? []) : [];
        $payrollItemTypes = $fiscalEnabled ? $payrollModel->listItemTypes(null, true) : [];
        $payrollRuleLookup = $fiscalEnabled ? $payrollModel->findRuleForPeriod($period['start'], $period['end'], $period['label']) : ['rule' => null, 'error' => null];
        // Inainte de primul calcul: ce ar lipsi (previzualizare, nu se salveaza nimic).
        $payrollPreview = null;
        if ($fiscalEnabled && $payrollRecord === null) {
            $previewInput = $payrollService->buildInputs([$row], $period)['inputs'][$key] ?? null;
            $payrollPreview = $previewInput !== null ? $payrollService->calculate($previewInput) : null;
        }
        $canPayrollCalculate = can('contabilitate_personal', 'payroll_calculate');
        $canPayrollConfirm = can('contabilitate_personal', 'payroll_confirm');
        $canPayrollReopen = can('contabilitate_personal', 'payroll_reopen');
        $birthDate = null;
        if ($sourceType === 'driver') {
            $driver = $this->model->findDriver($sourceId);
            $birthDate = !empty($driver['data_nasterii']) ? substr((string) $driver['data_nasterii'], 0, 10) : null;
        }

        ob_start();
        require BASE_PATH . '/views/contabilitate_personal/_employee_detail.php';
        $content = (string) ob_get_clean();
        echo function_exists('normalize_romanian_text') ? normalize_romanian_text($content) : $content;
        exit;
    }

    private function saveMonthAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        [$sourceType, $sourceId, $period] = $this->monthTargetFromPost();
        $row = $this->model->findSubject($sourceType, $sourceId);
        if ($row === null) {
            flash_set('warning', 'Angajatul nu a fost găsit.');
            redirect($this->indexUrl());
        }

        $errors = [];
        $maxDays = (int) (new DateTimeImmutable($period['period']))->format('t');
        $days = function (string $field) use (&$errors, $maxDays): ?float {
            $raw = trim(str_replace(',', '.', (string) ($_POST[$field] ?? '')));
            if ($raw === '') {
                return null;
            }
            if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > $maxDays || fmod((float) $raw * 2, 1.0) !== 0.0) {
                $errors[] = 'Numărul de zile trebuie să fie între 0 și ' . $maxDays . ' (pas de 0,5).';
                return null;
            }
            return (float) $raw;
        };
        $money = function (string $field, bool $allowNegative = false) use (&$errors): ?float {
            $raw = trim(str_replace(',', '.', (string) ($_POST[$field] ?? '')));
            if ($raw === '') {
                return null;
            }
            if (!is_numeric($raw) || (!$allowNegative && (float) $raw < 0)) {
                $errors[] = $allowNegative ? 'Sumele trebuie să fie numerice.' : 'Sumele trebuie să fie numerice și pozitive.';
                return null;
            }
            return round((float) $raw, 2);
        };

        $data = [
            'zile_lucrate' => $days('zile_lucrate'),
            'zile_co' => $days('zile_co'),
            'zile_cm' => $days('zile_cm'),
            'zile_absente' => $days('zile_absente'),
            'salariu_baza' => $money('salariu_baza'),
            'sporuri' => $money('sporuri'),
            'retineri' => $money('retineri'),
            'alte_ajustari' => $money('alte_ajustari', true),
            'observatii' => mb_substr(trim((string) ($_POST['observatii'] ?? '')), 0, 2000),
        ];
        if ($data['salariu_baza'] === null && ($data['sporuri'] !== null || $data['retineri'] !== null || $data['alte_ajustari'] !== null)) {
            $errors[] = 'Completează salariul de bază înainte de sporuri, rețineri sau ajustări.';
        }

        if ($errors !== []) {
            flash_set('danger', implode(' ', array_unique($errors)));
            redirect($this->indexUrl());
        }

        if ($this->payrollModel()->isConfirmed($sourceType, $sourceId, $period['period'])) {
            flash_set('danger', 'Calculul salarial pentru ' . $period['label'] . ' este confirmat: pontajul nu se mai modifică decât după redeschiderea calculului.');
            redirect($this->indexUrl());
        }

        try {
            $this->model->saveMonthlyRecord($sourceType, $sourceId, $period['period'], $data, $this->currentUserId());
            flash_set('success', 'Luna ' . $period['label'] . ' a fost salvată pentru ' . (string) $row['nume'] . '.');
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][save_month] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva luna.');
        }

        redirect($this->indexUrl());
    }

    private function finalizeMonthAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        [$sourceType, $sourceId, $period] = $this->monthTargetFromPost();
        $row = $this->model->findSubject($sourceType, $sourceId);
        if ($row === null) {
            flash_set('warning', 'Angajatul nu a fost găsit.');
            redirect($this->indexUrl());
        }

        $calendarMonth = $this->legalMonth($period);
        if (empty($calendarMonth['complete'])) {
            flash_set('danger', 'Calendarul legal pentru ' . $period['label'] . ' nu este complet (sărbătorile nu au putut fi descărcate). Luna nu poate fi finalizată încă.');
            redirect($this->indexUrl());
        }

        try {
            $snapshot = [
                'regim_lucru' => StaffAccountancyModel::workRegimeLabel($row['regim_lucru'] ?? null, $row['regim_lucru_detalii'] ?? null),
                'zile_lucratoare_ro' => $calendarMonth['working_days'],
            ];
            if ($sourceType === 'driver') {
                $leaves = $this->model->getApprovedLeavesForDrivers([$sourceId], $period['start'], $period['end'])[$sourceId] ?? [];
                $totals = StaffAccountancyModel::leaveDaysInMonth($leaves, $period['start'], $period['end'])['totals'];
                $snapshot['zile_co'] = $totals['CO'];
                $snapshot['zile_cm'] = $totals['CM'];
            }
            $this->model->finalizeMonthlyRecord($sourceType, $sourceId, $period['period'], $snapshot, $this->currentUserId());
            flash_set('success', 'Luna ' . $period['label'] . ' a fost finalizată pentru ' . (string) $row['nume'] . '. Modificările ulterioare nu o mai rescriu.');
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][finalize_month] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut finaliza luna.');
        }

        redirect($this->indexUrl());
    }

    private function reopenMonthAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        [$sourceType, $sourceId, $period] = $this->monthTargetFromPost();
        try {
            $ok = $this->model->reopenMonthlyRecord($sourceType, $sourceId, $period['period'], $this->currentUserId());
            flash_set($ok ? 'success' : 'warning', $ok ? 'Luna a fost redeschisă pentru corecții.' : 'Luna nu era finalizată.');
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][reopen_month] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut redeschide luna.');
        }

        redirect($this->indexUrl());
    }

    private function updateWorkRegimeAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $sourceType = $this->normalizeSourceType((string) ($_POST['source_type'] ?? ''));
        $sourceId = (int) ($_POST['source_id'] ?? 0);
        $regime = trim((string) ($_POST['regim_lucru'] ?? ''));
        $details = trim((string) ($_POST['regim_lucru_detalii'] ?? ''));
        if ($sourceType === '' || $sourceId <= 0 || !$this->model->subjectExists($sourceType, $sourceId)) {
            flash_set('warning', 'Angajatul nu a fost găsit.');
            redirect($this->indexUrl());
        }
        if ($regime === 'personalizat' && $details === '') {
            flash_set('danger', 'Descrie regimul personalizat (ex.: 2 zile lucru / 2 zile liber).');
            redirect($this->indexUrl());
        }

        try {
            $this->model->updateWorkRegime($sourceType, $sourceId, $regime !== '' ? $regime : null, $details, $this->currentUserId());
            flash_set('success', 'Regimul de lucru a fost actualizat.');
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][update_work_regime] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut actualiza regimul de lucru.');
        }

        redirect($this->indexUrl());
    }

    /** @return array{0: string, 1: int, 2: array} */
    private function monthTargetFromPost(): array
    {
        $sourceType = $this->normalizeSourceType((string) ($_POST['source_type'] ?? ''));
        $sourceId = (int) ($_POST['source_id'] ?? 0);
        if ($sourceType === '' || $sourceId <= 0) {
            flash_set('warning', 'Angajat invalid.');
            redirect($this->indexUrl());
        }

        return [$sourceType, $sourceId, StaffMonthlyAccountingService::parsePeriod((string) ($_POST['luna'] ?? ''))];
    }

    /**
     * Calendarul legal al lunii. Nager.Date nu are voie sa blocheze pagina: la orice
     * eroare neprevazuta calendarul e marcat incomplet, iar pagina se incarca.
     */
    private function legalMonth(array $period): array
    {
        try {
            return (new LegalCalendarService($this->db))->getMonthSummary($period['year'], $period['month']);
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][legal_calendar] ' . $exception->getMessage());
            $first = new DateTimeImmutable($period['start']);
            return [
                'year' => $period['year'],
                'month' => $period['month'],
                'label' => $period['label'],
                'short_label' => $period['short_label'],
                'complete' => false,
                'working_days' => null,
                'holiday_days' => null,
                'holidays_on_weekend' => null,
                'calendar_days' => (int) $first->format('t'),
                'weekend_days' => null,
                'holidays' => [],
                'days' => [],
                'first_weekday' => (int) $first->format('N'),
                'sync' => ['complete' => false, 'source' => 'none', 'synced_at' => null, 'error' => 'Calendarul legal nu a putut fi încărcat.'],
            ];
        }
    }

    private function pullBulkSummary(): ?array
    {
        $summary = $_SESSION['payroll_bulk_summary'] ?? null;
        unset($_SESSION['payroll_bulk_summary']);

        return is_array($summary) ? $summary : null;
    }

    private function requireSalaryRight(): void
    {
        if (!can('contabilitate_personal', 'salaries')) {
            access_deny_403();
        }
    }

    // ------------------------------------------------------------------
    // Calcul salarial
    // ------------------------------------------------------------------

    private function payrollModel(): PayrollModel
    {
        return new PayrollModel($this->db);
    }

    private function payrollService(): PayrollMonthService
    {
        return new PayrollMonthService($this->model, $this->payrollModel());
    }

    /** Calculul fiscal e optional: cu el dezactivat, actiunile de calcul nu ruleaza. */
    private function requireFiscalEnabled(): void
    {
        if (!$this->payrollModel()->isFiscalCalculationEnabled()) {
            flash_set('warning', 'Calculul fiscal al salariilor este dezactivat. Costul salarial se ia din salariul configurat.');
            redirect($this->indexUrl());
        }
    }

    private function payrollToggleAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());
        $enable = (string) ($_POST['enabled'] ?? '0') === '1';

        try {
            $this->payrollModel()->setFiscalCalculationEnabled($enable, $this->currentUserId());
            flash_set('success', $enable
                ? 'Calculul fiscal al salariilor (brut/net, contribuții, cost firmă) a fost activat.'
                : 'Calculul fiscal a fost dezactivat. Costul salarial al lunii se ia din salariul configurat. Calculele existente se păstrează.');
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][payroll_toggle] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut schimba setarea.');
        }

        redirect($this->indexUrl());
    }

    private function requireAction(string $action): void
    {
        if (!can('contabilitate_personal', $action)) {
            access_deny_403();
        }
    }

    /** Recalculeaza un angajat pentru luna data (nu atinge un stat confirmat). */
    private function payrollCalculateAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());
        [$sourceType, $sourceId, $period] = $this->monthTargetFromPost();
        $row = $this->model->findSubject($sourceType, $sourceId);
        if ($row === null) {
            flash_set('warning', 'Angajatul nu a fost găsit.');
            redirect($this->indexUrl());
        }

        try {
            $summary = $this->payrollService()->calculateAndSave([$row], $period, $this->currentUserId());
            if ($summary['confirmed_skipped'] !== []) {
                flash_set('warning', 'Calculul pentru ' . $period['label'] . ' este confirmat. Redeschideți-l înainte de recalculare.');
            } elseif ($summary['calculated'] !== [] || $summary['to_review'] !== []) {
                flash_set('success', 'Calculul salarial pentru ' . (string) $row['nume'] . ' (' . $period['label'] . ') a fost actualizat' . ($summary['to_review'] !== [] ? ' — are atenționări de verificat.' : '.'));
            } else {
                flash_set('warning', 'Calculul pentru ' . (string) $row['nume'] . ' nu s-a putut finaliza: vedeți motivele în „Calcul salarial”.');
            }
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][payroll_calculate] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut calcula salariul.');
        }

        redirect($this->indexUrl());
    }

    /** "Calculează luna": toți angajații activi, cu rezumat — nimeni nu este sărit în tăcere. */
    private function payrollCalculateMonthAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());
        $period = StaffMonthlyAccountingService::parsePeriod((string) ($_POST['luna'] ?? ''));

        try {
            $rows = $this->model->getAllStaffForExport(['q' => '', 'staff_type_id' => 0, 'category' => '', 'status' => '', 'functie' => '', 'salary_min' => '', 'salary_max' => '', 'document_status' => ''], 'nume', 'asc');
            $summary = $this->payrollService()->calculateAndSave($rows, $period, $this->currentUserId());
            $summary['period_label'] = $period['label'];
            $_SESSION['payroll_bulk_summary'] = $summary;
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][payroll_calculate_month] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut calcula luna.');
        }

        redirect($this->indexUrl());
    }

    private function payrollConfirmAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());
        [$sourceType, $sourceId, $period] = $this->monthTargetFromPost();
        $row = $this->model->findSubject($sourceType, $sourceId);

        try {
            // Confirmarea se face doar pe un calcul actual: daca intrarile s-au schimbat, se cere recalcul.
            if ($row !== null) {
                $status = $this->payrollService()->statusForRows([$row], $period)[$sourceType . '-' . $sourceId] ?? null;
                if (!empty($status['stale'])) {
                    throw new InvalidArgumentException('Datele s-au schimbat după calcul (salariu, profil, pontaj, sporuri sau regulă fiscală). Recalculați înainte de confirmare.');
                }
            }
            $this->payrollModel()->confirmPayroll($sourceType, $sourceId, $period['period'], $this->currentUserId());
            flash_set('success', 'Calculul salarial pentru ' . $period['label'] . ' a fost confirmat contabil. Modificările ulterioare nu îl mai schimbă.');
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][payroll_confirm] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut confirma calculul.');
        }

        redirect($this->indexUrl());
    }

    private function payrollReopenAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());
        [$sourceType, $sourceId, $period] = $this->monthTargetFromPost();
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            flash_set('danger', 'Scrieți motivul redeschiderii (minimum 5 caractere).');
            redirect($this->indexUrl());
        }

        try {
            $this->payrollModel()->reopenPayroll($sourceType, $sourceId, $period['period'], mb_substr($reason, 0, 1000), $this->currentUserId());
            flash_set('success', 'Calculul a fost redeschis. Rezultatul confirmat anterior a rămas în jurnal; recalculați și confirmați din nou.');
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][payroll_reopen] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut redeschide calculul.');
        }

        redirect($this->indexUrl());
    }

    private function payrollProfileSaveAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());
        [$sourceType, $sourceId] = $this->monthTargetFromPost();
        if (!$this->model->subjectExists($sourceType, $sourceId)) {
            flash_set('warning', 'Angajatul nu a fost găsit.');
            redirect($this->indexUrl());
        }

        // Un camp lasat gol ramane NECONFIGURAT (NULL): nu se completeaza implicit.
        $enum = static function (string $field, array $allowed): ?string {
            $value = trim((string) ($_POST[$field] ?? ''));
            return in_array($value, $allowed, true) ? $value : null;
        };
        $bool = static function (string $field): ?int {
            $value = (string) ($_POST[$field] ?? '');
            return $value === '1' ? 1 : ($value === '0' ? 0 : null);
        };
        $int = static function (string $field, int $max): ?int {
            $value = trim((string) ($_POST[$field] ?? ''));
            return $value !== '' && ctype_digit($value) && (int) $value <= $max ? (int) $value : null;
        };
        $hours = trim(str_replace(',', '.', (string) ($_POST['hours_per_day'] ?? '')));

        $data = [
            'contract_type' => $enum('contract_type', ['cim', 'colaborare', 'altul']),
            'norm_type' => $enum('norm_type', ['full', 'part']),
            'hours_per_day' => is_numeric($hours) && (float) $hours > 0 && (float) $hours <= 12 ? round((float) $hours, 2) : null,
            'is_basic_function' => $bool('is_basic_function'),
            'salary_input_type' => $enum('salary_input_type', ['net', 'gross']),
            'dependents_count' => $int('dependents_count', 20),
            'children_in_school' => $int('children_in_school', 20),
            'under_26' => $bool('under_26'),
            'work_conditions' => $enum('work_conditions', ['normale', 'deosebite', 'speciale']),
            'tax_exemption' => $enum('tax_exemption', ['niciuna', 'handicap', 'it', 'constructii', 'agricol', 'altele']),
            'min_base_exemption' => $enum('min_base_exemption', ['niciuna', 'elev_student', 'pensionar', 'handicap', 'ucenic', 'alt_contract', 'altele']),
            'notes' => mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000) ?: null,
        ];

        try {
            $this->payrollModel()->saveProfile($sourceType, $sourceId, $data, $this->currentUserId());
            flash_set('success', 'Profilul de salarizare a fost salvat.');
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][payroll_profile_save] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva profilul de salarizare.');
        }

        redirect($this->indexUrl());
    }

    private function payrollItemAddAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());
        [$sourceType, $sourceId, $period] = $this->monthTargetFromPost();
        $type = $this->payrollModel()->findItemType((int) ($_POST['item_type_id'] ?? 0));
        $rawAmount = trim(str_replace(',', '.', (string) ($_POST['amount'] ?? '')));
        $amount = $rawAmount !== '' && is_numeric($rawAmount) && (float) $rawAmount >= 0 ? round((float) $rawAmount, 2) : null;

        $errors = [];
        if ($type === null || (int) $type['active'] !== 1) {
            $errors[] = 'Alegeți un tip activ.';
        } elseif ($type['valid_from'] > $period['end'] || ($type['valid_to'] !== null && $type['valid_to'] < $period['start'])) {
            $errors[] = 'Tipul „' . $type['name'] . '” nu este valabil în ' . $period['label'] . '.';
        } elseif ($amount === null && !($type['calculation_type'] === 'procent_din_baza' && $type['default_value'] !== null)) {
            $errors[] = 'Completați suma (tipul nu are un procent configurat).';
        }
        if ($type !== null && $type['category'] === 'retinere' && trim((string) ($_POST['reason'] ?? '')) === '') {
            $errors[] = 'Reținerea necesită motivul (ex.: poprire nr./dată, avans).';
        }
        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($this->indexUrl());
        }

        try {
            $this->payrollModel()->addEmployeeItem($sourceType, $sourceId, $period['period'], (int) $type['id'], $amount,
                mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255) ?: null, mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000) ?: null, $this->currentUserId());
            flash_set('success', '„' . $type['name'] . '” a fost adăugat pentru ' . $period['label'] . '. Recalculați luna.');
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][payroll_item_add] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut adăuga elementul.');
        }

        redirect($this->indexUrl());
    }

    private function payrollItemDeleteAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());
        [$sourceType, $sourceId] = $this->monthTargetFromPost();

        try {
            $ok = $this->payrollModel()->deleteEmployeeItem((int) ($_POST['item_id'] ?? 0), $sourceType, $sourceId);
            flash_set($ok ? 'success' : 'warning', $ok ? 'Elementul a fost șters. Recalculați luna.' : 'Elementul nu a fost găsit.');
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][payroll_item_delete] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut șterge elementul.');
        }

        redirect($this->indexUrl());
    }

    /** Salvare regula fiscala (noua sau necofirmata) ori versiune noua de la o data. */
    private function payrollRuleSaveAction(): void
    {
        $this->requirePost('contabilitate_personal');
        $returnUrl = $this->configUrl('fiscal');
        ensure_csrf_or_redirect($returnUrl);

        $reason = trim((string) ($_POST['reason'] ?? ''));
        $mode = (string) ($_POST['mode'] ?? 'save');
        $ruleId = (int) ($_POST['rule_id'] ?? 0);

        try {
            $data = $this->collectRuleInput();
            if ($reason === '') {
                throw new InvalidArgumentException('Motivul / sursa modificării este obligatorie (ex.: „HG nr. X/2027”).');
            }
            $model = $this->payrollModel();
            if ($mode === 'version') {
                $newFrom = $this->normalizeDate((string) ($_POST['new_valid_from'] ?? ''));
                if ($newFrom === null || $ruleId <= 0) {
                    throw new InvalidArgumentException('Alegeți data de la care se aplică noua versiune.');
                }
                $id = $model->closeRuleAndCreateVersion($ruleId, $newFrom, $data, $reason, $this->currentUserId());
            } else {
                $id = $model->saveRule($ruleId > 0 ? $ruleId : null, $data, $reason, $this->currentUserId());
            }
            flash_set('success', 'Configurația fiscală a fost salvată.');
            $returnUrl = $this->configUrl('fiscal', $id);
        } catch (InvalidArgumentException $exception) {
            flash_set('danger', $exception->getMessage());
            $returnUrl = $this->configUrl('fiscal', $ruleId > 0 ? $ruleId : null);
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][payroll_rule_save] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva configurația fiscală.');
        }

        redirect($returnUrl);
    }

    private function collectRuleInput(): array
    {
        $num = static function (string $field, bool $required = true): ?float {
            $raw = trim(str_replace(',', '.', (string) ($_POST[$field] ?? '')));
            if ($raw === '') {
                if ($required) {
                    throw new InvalidArgumentException('Completați „' . $field . '”.');
                }
                return null;
            }
            if (!is_numeric($raw) || (float) $raw < 0) {
                throw new InvalidArgumentException('„' . $field . '” trebuie să fie un număr pozitiv.');
            }
            return (float) $raw;
        };
        $validFrom = $this->normalizeDate((string) ($_POST['valid_from'] ?? ''));
        if ($validFrom === null) {
            throw new InvalidArgumentException('Data „De la” este obligatorie.');
        }
        $percents = [];
        foreach ([0, 1, 2, 3, 4] as $dependents) {
            $percents[] = $num('pd_percent_' . $dependents);
        }
        $rounding = (string) ($_POST['rounding_mode'] ?? 'ro_salarii');
        if (!in_array($rounding, ['ro_salarii', 'leu_half_down', 'leu_half_up', 'bani'], true)) {
            throw new InvalidArgumentException('Mod de rotunjire invalid.');
        }

        return [
            'name' => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120),
            'valid_from' => $validFrom,
            'valid_to' => $this->normalizeDate((string) ($_POST['valid_to'] ?? '')),
            'status' => ($_POST['status'] ?? '') === 'activ' ? 'activ' : 'inactiv',
            'cas_employee_rate' => $num('cas_employee_rate'),
            'cass_employee_rate' => $num('cass_employee_rate'),
            'income_tax_rate' => $num('income_tax_rate'),
            'cam_employer_rate' => $num('cam_employer_rate'),
            'minimum_gross_salary' => $num('minimum_gross_salary'),
            'monthly_hours_norm' => $num('monthly_hours_norm'),
            'minimum_hourly_salary' => $num('minimum_hourly_salary'),
            'non_taxable_minimum_salary_amount' => $num('non_taxable_minimum_salary_amount', false) ?? 0.0,
            'non_taxable_income_limit' => $num('non_taxable_income_limit', false),
            'non_taxable_requires_eligibility' => 1,
            'non_taxable_excludes_cam' => ($_POST['non_taxable_excludes_cam'] ?? '1') === '1' ? 1 : 0,
            'minimum_contribution_base_enabled' => ($_POST['minimum_contribution_base_enabled'] ?? '1') === '1' ? 1 : 0,
            'personal_deduction_config' => [
                'base_percent_by_dependents' => $percents,
                'income_window_above_minimum' => $num('pd_window'),
                'step_lei' => $num('pd_step_lei'),
                'step_percent' => $num('pd_step_percent'),
                'youth_percent' => $num('pd_youth_percent'),
                'youth_max_age' => 26,
                'child_in_school_amount' => $num('pd_child_amount'),
            ],
            'rounding_mode' => $rounding,
            'legal_reference' => mb_substr(trim((string) ($_POST['legal_reference'] ?? '')), 0, 4000) ?: null,
            'notes' => mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 4000) ?: null,
        ];
    }

    private function payrollItemTypeSaveAction(): void
    {
        $this->requirePost('contabilitate_personal');
        $category = in_array($_POST['category'] ?? '', ['spor', 'retinere', 'beneficiu'], true) ? (string) $_POST['category'] : 'spor';
        $tab = ['spor' => 'sporuri', 'retinere' => 'retineri', 'beneficiu' => 'beneficii'][$category];
        $returnUrl = $this->configUrl($tab);
        ensure_csrf_or_redirect($returnUrl);

        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        $calc = in_array($_POST['calculation_type'] ?? '', ['suma_fixa', 'procent_din_baza', 'manual'], true) ? (string) $_POST['calculation_type'] : 'manual';
        $rawValue = trim(str_replace(',', '.', (string) ($_POST['default_value'] ?? '')));
        $validFrom = $this->normalizeDate((string) ($_POST['valid_from'] ?? '')) ?? date('Y-01-01');
        $flag = static fn (string $f): int => ($_POST[$f] ?? '0') === '1' ? 1 : 0;

        if ($name === '') {
            flash_set('danger', 'Denumirea este obligatorie.');
            redirect($returnUrl);
        }
        if ($calc === 'procent_din_baza' && (!is_numeric($rawValue) || (float) $rawValue <= 0)) {
            flash_set('danger', 'Pentru „procent din bază” completați procentul (stabilit prin contract / regulament, nu implicit).');
            redirect($returnUrl);
        }

        try {
            $this->payrollModel()->saveItemType((int) ($_POST['id'] ?? 0) ?: null, [
                'category' => $category,
                'name' => $name,
                'calculation_type' => $calc,
                'default_value' => is_numeric($rawValue) ? (string) $rawValue : null,
                // Retinerile scad din net; nu au tratament fiscal propriu.
                'paid_in_cash' => $category === 'retinere' ? 1 : $flag('paid_in_cash'),
                'subject_to_cas' => $category === 'retinere' ? 0 : $flag('subject_to_cas'),
                'subject_to_cass' => $category === 'retinere' ? 0 : $flag('subject_to_cass'),
                'subject_to_income_tax' => $category === 'retinere' ? 0 : $flag('subject_to_income_tax'),
                'subject_to_cam' => $category === 'retinere' ? 0 : $flag('subject_to_cam'),
                'excluded_from_facility_ceiling' => $category === 'retinere' ? 0 : $flag('excluded_from_facility_ceiling'),
                'valid_from' => $validFrom,
                'valid_to' => $this->normalizeDate((string) ($_POST['valid_to'] ?? '')),
                'active' => $flag('active'),
                'legal_reference' => mb_substr(trim((string) ($_POST['legal_reference'] ?? '')), 0, 255) ?: null,
                'notes' => mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000) ?: null,
            ], $this->currentUserId());
            flash_set('success', 'Tipul „' . $name . '” a fost salvat.');
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][payroll_item_type_save] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva tipul.');
        }

        redirect($returnUrl);
    }

    private function configUrl(string $tab, ?int $ruleId = null): string
    {
        $params = ['page' => 'contabilitate_personal', 'config' => $tab];
        $luna = (string) ($_POST['luna'] ?? $_GET['luna'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $luna)) {
            $params['luna'] = $luna;
        }
        if ($ruleId !== null) {
            $params['rule'] = $ruleId;
        }

        return build_query_url($params);
    }

    private function storeDriverAccountingFromStaffForm(): void
    {
        $driverId = (int) ($_POST['driver_id'] ?? 0);
        $driver = $this->model->findDriver($driverId);
        if ($driver === null) {
            flash_set('danger', 'Selectează un șofer existent.');
            return;
        }

        $postedSalary = trim((string) ($_POST['driver_salariu'] ?? ''));
        $salary = $postedSalary !== ''
            ? $this->parseMoney($postedSalary)
            : ($driver['salariu'] !== null ? (float) $driver['salariu'] : null);
        $hireDate = $this->normalizeDate((string) ($_POST['driver_data_angajare'] ?? '')) ?: ($driver['data_angajare'] ?? null);
        $notes = trim((string) ($_POST['driver_observatii'] ?? ''));

        if ($postedSalary !== '' && $salary === null) {
            flash_set('danger', 'Introdu un salariu valid pentru șofer.');
            return;
        }

        $this->model->updateDriverAccounting($driverId, $salary, $hireDate ?: null, $notes !== '' ? $notes : null, $this->currentUserId());
        flash_set('success', 'Datele contabile ale șoferului au fost actualizate fără a crea un șofer duplicat.');
    }

    private function storeCollaboratorDriverFromStaffForm(): void
    {
        $name = trim((string) ($_POST['colaborator_nume'] ?? ''));
        $phone = trim((string) ($_POST['colaborator_telefon'] ?? ''));
        $postedSalary = trim((string) ($_POST['colaborator_salariu'] ?? ''));
        $salary = $postedSalary !== '' ? $this->parseMoney($postedSalary) : null;
        $vehicleIds = is_array($_POST['colaborator_vehicle_ids'] ?? null) ? $_POST['colaborator_vehicle_ids'] : [];

        $errors = [];
        if ($name === '') {
            $errors[] = 'Numele șoferului colaborator este obligatoriu.';
        }
        if (mb_strlen($name) > 100) {
            $errors[] = 'Numele poate avea maximum 100 de caractere.';
        }
        if (mb_strlen($phone) > 20) {
            $errors[] = 'Telefonul poate avea maximum 20 de caractere.';
        }
        if ($postedSalary !== '' && $salary === null) {
            $errors[] = 'Introdu o remunerație validă.';
        }
        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            return;
        }

        $this->model->createCollaboratorDriver([
            'nume' => $name,
            'telefon' => $phone,
            'salariu' => $salary,
            'data_angajare' => $this->normalizeDate((string) ($_POST['colaborator_data_inceput'] ?? '')),
            'observatii' => trim((string) ($_POST['colaborator_observatii'] ?? '')),
        ], $vehicleIds, $this->currentUserId());

        flash_set('success', $vehicleIds === []
            ? 'Șoferul colaborator a fost adăugat. Asociază-i un vehicul din modulul Șoferi ca să poată fi ales în Dispecer curse.'
            : 'Șoferul colaborator a fost adăugat și poate fi ales în Dispecer curse.'
        );
    }

    private function collectFilters(): array
    {
        return [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'staff_type_id' => (int) ($_GET['staff_type_id'] ?? 0),
            'category' => trim((string) ($_GET['category'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'functie' => trim((string) ($_GET['functie'] ?? '')),
            'salary_min' => trim((string) ($_GET['salary_min'] ?? '')),
            'salary_max' => trim((string) ($_GET['salary_max'] ?? '')),
            'document_status' => trim((string) ($_GET['document_status'] ?? '')),
            'regim' => trim((string) ($_GET['regim'] ?? '')),
            'luna_status' => trim((string) ($_GET['luna_status'] ?? '')),
            // Luna pentru filtrul luna_status (aceeasi logica ca parsePeriod din indexAction).
            'perioada' => StaffMonthlyAccountingService::parsePeriod((string) ($_GET['luna'] ?? ''))['period'],
        ];
    }

    private function collectFormerFilters(): array
    {
        $period = trim((string) ($_GET['period'] ?? ''));
        if (!in_array($period, ['', 'last_30_days', 'last_3_months', 'last_6_months', 'last_12_months', 'current_year', 'previous_year', 'custom'], true)) {
            $period = '';
        }

        $tab = trim((string) ($_GET['tab'] ?? 'all'));
        if (!in_array($tab, ['all', 'operational', 'office'], true)) {
            $tab = 'all';
        }

        $personnelType = trim((string) ($_GET['personnel_type'] ?? ''));
        if (!in_array($personnelType, ['', 'operational', 'office'], true)) {
            $personnelType = '';
        }

        return [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'personnel_type' => $personnelType,
            'reason' => trim((string) ($_GET['reason'] ?? '')),
            'period' => $period,
            'date_start' => $this->normalizeDate((string) ($_GET['date_start'] ?? '')) ?? '',
            'date_end' => $this->normalizeDate((string) ($_GET['date_end'] ?? '')) ?? '',
            'tab' => $tab,
        ];
    }

    private function collectTerminationInput(array $input): array
    {
        $terminationDate = $this->normalizeDate((string) ($input['termination_date'] ?? $input['data_plecarii'] ?? $input['data_incetare'] ?? ''));
        $lastWorkingDay = $this->normalizeDate((string) ($input['last_working_day'] ?? $input['ultima_zi_lucrata'] ?? ''));
        $reason = trim((string) ($input['termination_reason'] ?? $input['motiv_plecare'] ?? ''));
        $customReason = trim((string) ($input['termination_reason_custom'] ?? ''));
        if ($reason === 'Alte motive' && $customReason !== '') {
            $reason = $customReason;
        }

        $errors = [];
        if ($terminationDate === null) {
            $errors[] = 'Data plecării este obligatorie.';
        } elseif ($terminationDate > date('Y-m-d')) {
            $errors[] = 'Data plecării nu poate fi în viitor.';
        }
        if ($lastWorkingDay !== null && $terminationDate !== null && $lastWorkingDay > $terminationDate) {
            $errors[] = 'Ultima zi lucrată nu poate fi după data plecării.';
        }
        if ($reason === '') {
            $errors[] = 'Motivul plecării este obligatoriu.';
        }

        $notes = trim((string) ($input['termination_notes'] ?? $input['notes'] ?? ''));

        return [[
            'termination_date' => $terminationDate ?? '',
            'last_working_day' => $lastWorkingDay,
            'termination_reason' => $reason,
            'termination_notes' => $notes !== '' ? $notes : null,
            'rehire_eligible' => (string) ($input['rehire_eligible'] ?? '1') === '1',
            'termination_assets_returned' => isset($input['termination_assets_returned']) && (string) $input['termination_assets_returned'] === '1',
        ], $errors];
    }

    private function buildFormerProfilesForRows(array $rows): array
    {
        $profiles = [];
        foreach ($rows as $row) {
            $sourceType = $this->normalizeSourceType((string) ($row['source_type'] ?? ''));
            $sourceId = (int) ($row['source_id'] ?? 0);
            if ($sourceType === '' || $sourceId <= 0) {
                continue;
            }

            try {
                $profile = $this->model->getFormerProfile($sourceType, $sourceId);
            } catch (Throwable $exception) {
                error_log('[StaffAccountancyController][former_profile] ' . $exception->getMessage());
                $profile = null;
            }

            if ($profile !== null) {
                $profiles[$sourceType . '-' . $sourceId] = $profile;
            }
        }

        return $profiles;
    }

    private function collectStaffTypeInput(array $input): array
    {
        return [
            'name' => trim((string) ($input['name'] ?? '')),
            'category' => trim((string) ($input['category'] ?? 'operational')),
            'description' => trim((string) ($input['description'] ?? '')),
            'status' => trim((string) ($input['status'] ?? 'activ')),
            'salary_required' => (string) ($input['salary_required'] ?? '0') === '1',
            'vehicle_required' => (string) ($input['vehicle_required'] ?? '0') === '1',
            'mandatory_documents_enabled' => (string) ($input['mandatory_documents_enabled'] ?? '0') === '1',
            'can_create_employees' => (string) ($input['can_create_employees'] ?? '0') === '1',
            'can_delete_employees' => (string) ($input['can_delete_employees'] ?? '0') === '1',
            'document_warning_days' => (int) ($input['document_warning_days'] ?? 30),
        ];
    }

    private function collectRequirementInput(array $input): array
    {
        $rows = $input['requirements'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $requirements = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $documentType = trim((string) ($row['document_type'] ?? ''));
            if ($documentType === '') {
                continue;
            }

            $requirements[] = [
                'document_type' => $documentType,
                'requires_expiry' => (string) ($row['requires_expiry'] ?? '1') === '1',
                'warning_days' => (int) ($row['warning_days'] ?? 30),
            ];
        }

        return $requirements;
    }

    private function validateStaffTypeInput(array $data): array
    {
        $errors = [];
        if ((string) ($data['name'] ?? '') === '') {
            $errors[] = 'Denumirea tipului de personal este obligatorie.';
        }
        if (!in_array((string) ($data['category'] ?? ''), ['operational', 'office'], true)) {
            $errors[] = 'Categoria tipului de personal este invalida.';
        }
        if (!in_array((string) ($data['status'] ?? ''), ['activ', 'inactiv'], true)) {
            $errors[] = 'Statusul tipului de personal este invalid.';
        }

        return $errors;
    }

    private function isReservedDriverTypeName(string $name): bool
    {
        $name = trim($name);
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : false;
        $normalized = strtolower(is_string($ascii) && trim($ascii) !== '' ? $ascii : $name);
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '', $normalized);

        return $normalized === 'sofer';
    }

    private function collectStaffMemberInput(array $input, int $staffTypeId, ?array $staffType = null): array
    {
        $function = trim((string) ($staffType['name'] ?? ''));
        if ($function === '') {
            $function = trim((string) ($input['functie'] ?? ''));
        }

        return [
            'staff_type_id' => $staffTypeId,
            'nume_complet' => trim((string) ($input['nume_complet'] ?? '')),
            'telefon' => trim((string) ($input['telefon'] ?? '')),
            'email' => trim((string) ($input['email'] ?? '')),
            'functie' => $function,
            'salariu' => $this->parseMoney($input['salariu'] ?? null),
            'data_angajare' => $this->normalizeDate((string) ($input['data_angajare'] ?? '')),
            'status' => trim((string) ($input['status'] ?? 'activ')),
            'observatii' => trim((string) ($input['observatii'] ?? '')),
        ];
    }

    private function collectStaffDocumentInput(array $input, array $files): array
    {
        $rows = $input['staff_documents'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $documents = [];
        $storedFiles = [];

        try {
            foreach ($rows as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $documentType = trim((string) ($row['tip_document'] ?? ''));
                $number = trim((string) ($row['numar_document'] ?? ''));
                $issueDateRaw = trim((string) ($row['data_emitere'] ?? ''));
                $expiryDateRaw = trim((string) ($row['data_expirare'] ?? ''));
                $notes = trim((string) ($row['observatii'] ?? ''));
                $uploadedFile = $this->extractGroupedUpload($files['staff_document_files'] ?? null, $index);
                $hasFile = is_array($uploadedFile) && (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                $hasContent = $number !== '' || $issueDateRaw !== '' || $expiryDateRaw !== '' || $notes !== '' || $hasFile;

                if (!$hasContent) {
                    continue;
                }

                if ($documentType === '') {
                    throw new RuntimeException('Un document initial nu are tipul completat.');
                }

                [$fileData, $uploadError] = $this->storeUploadedDocumentFile($uploadedFile);
                if ($uploadError !== null) {
                    throw new RuntimeException($uploadError);
                }

                if ($fileData !== null && !empty($fileData['fisier_stocat'])) {
                    $storedFiles[] = (string) $fileData['fisier_stocat'];
                }

                $documents[] = [
                    'data' => [
                        'tip_document' => $documentType,
                        'numar_document' => $number !== '' ? $number : null,
                        'data_emitere' => $this->normalizeDate($issueDateRaw),
                        'data_expirare' => $this->normalizeDate($expiryDateRaw),
                        'observatii' => $notes !== '' ? $notes : null,
                    ],
                    'fileData' => $fileData,
                ];
            }
        } catch (Throwable $exception) {
            foreach ($storedFiles as $storedFile) {
                $this->deleteDocumentPhysicalFile($storedFile);
            }

            throw $exception;
        }

        return $documents;
    }

    private function extractGroupedUpload(mixed $groupedFiles, int|string $index): ?array
    {
        if (!is_array($groupedFiles) || !isset($groupedFiles['name'][$index])) {
            return null;
        }

        return [
            'name' => $groupedFiles['name'][$index] ?? '',
            'type' => $groupedFiles['type'][$index] ?? '',
            'tmp_name' => $groupedFiles['tmp_name'][$index] ?? '',
            'error' => $groupedFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $groupedFiles['size'][$index] ?? 0,
        ];
    }

    private function cleanupPreparedDocuments(array $documents): void
    {
        foreach ($documents as $document) {
            $storedFile = trim((string) ($document['fileData']['fisier_stocat'] ?? ''));
            if ($storedFile !== '') {
                $this->deleteDocumentPhysicalFile($storedFile);
            }
        }
    }

    private function validateStaffMemberInput(array $data, bool $validateSalary = true): array
    {
        $errors = [];
        if ((int) ($data['staff_type_id'] ?? 0) <= 0) {
            $errors[] = 'Tipul de personal este obligatoriu.';
        }
        if ((string) ($data['nume_complet'] ?? '') === '') {
            $errors[] = 'Numele complet este obligatoriu.';
        }
        if ((string) ($data['functie'] ?? '') === '') {
            $errors[] = 'Functia este obligatorie.';
        }
        if (!in_array((string) ($data['status'] ?? ''), ['activ', 'inactiv'], true)) {
            $errors[] = 'Statusul este invalid.';
        }
        if ($validateSalary && isset($_POST['salariu']) && trim((string) $_POST['salariu']) !== '' && $data['salariu'] === null) {
            $errors[] = 'Salariul trebuie sa fie numeric.';
        }

        return $errors;
    }

    private function storeUploadedDocumentFile(?array $file): array
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return [null, 'Fișierul nu a putut fi încărcat.'];
        }

        $maxSize = 5242880;
        if ((int) ($file['size'] ?? 0) > $maxSize) {
            return [null, 'Fișierul depășește limita de 5 MB.'];
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];
        if (!in_array($extension, $allowed, true)) {
            return [null, 'Formatul fisierului nu este permis.'];
        }

        $uploadDir = BASE_PATH . '/uploads/documente';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return [null, 'Directorul de upload nu poate fi creat.'];
        }

        try {
            $storedName = 'staff_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        } catch (Throwable) {
            $storedName = 'staff_' . date('YmdHis') . '_' . str_replace('.', '', uniqid('', true)) . '.' . $extension;
        }

        $destination = $uploadDir . '/' . $storedName;
        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
            return [null, 'Fișierul nu a putut fi salvat.'];
        }

        return [[
            'fisier_original' => $originalName,
            'fisier_stocat' => $storedName,
        ], null];
    }

    private function deleteDocumentPhysicalFile(string $storedFile): void
    {
        $storedFile = trim($storedFile);
        if ($storedFile === '') {
            return;
        }

        $path = BASE_PATH . '/uploads/documente/' . basename($storedFile);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function parseMoney(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $raw = trim(str_replace(',', '.', (string) $value));
        if ($raw === '') {
            return null;
        }

        if (!is_numeric($raw) || (float) $raw < 0) {
            return null;
        }

        return round((float) $raw, 2);
    }

    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return $date;
    }

    private function normalizeSourceType(string $sourceType): string
    {
        $sourceType = strtolower(trim($sourceType));
        return in_array($sourceType, ['driver', 'staff'], true) ? $sourceType : '';
    }

    private function categoryLabel(string $category): string
    {
        return $category === 'office' ? 'Personal de birou' : 'Personal operațional';
    }

    private function documentStatusLabel(string $status): string
    {
        return match ($status) {
            'expirat' => 'Expirat',
            'expira_curand' => 'Expiră curând',
            'valid' => 'Valid',
            default => 'Fără documente',
        };
    }

    private function seniorityLabel(int $activeDays): string
    {
        $days = max(0, $activeDays);
        if ($days <= 0) {
            return '-';
        }

        $monthsTotal = intdiv($days, 30);
        $years = intdiv($monthsTotal, 12);
        $months = $monthsTotal % 12;
        if ($monthsTotal === 0) {
            return $days === 1 ? '1 zi' : $days . ' zile';
        }

        $parts = [];
        if ($years > 0) {
            $parts[] = $years === 1 ? '1 an' : $years . ' ani';
        }
        if ($months > 0) {
            $parts[] = $months === 1 ? '1 lună' : $months . ' luni';
        }

        return $parts !== [] ? implode(' ', $parts) : '0 luni';
    }

    private function requirePost(string $page): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => $page]));
        }
    }

    private function currentUserId(): ?int
    {
        $user = current_user();
        $id = (int) ($user['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * Intoarcerea pe pagina pastreaza luna selectata si, la nevoie, redeschide randul
     * angajatului (return_open=driver-12), ca utilizatorul sa nu piarda contextul.
     */
    private function indexUrl(): string
    {
        $params = ['page' => 'contabilitate_personal'];
        $luna = (string) ($_POST['luna'] ?? $_GET['luna'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $luna)) {
            $params['luna'] = $luna;
        }
        $open = (string) preg_replace('/[^a-z0-9-]/', '', (string) ($_POST['return_open'] ?? ''));
        if ($open !== '') {
            $params['open'] = $open;
            $tab = (string) preg_replace('/[^a-z_]/', '', (string) ($_POST['return_tab'] ?? ''));
            if ($tab !== '') {
                $params['tab'] = $tab;
            }
        }

        return build_query_url($params);
    }

    private function formerUrl(): string
    {
        return build_query_url(['page' => 'fosti_angajati']);
    }

    private function staffEditReturnUrl(): string
    {
        return trim((string) ($_POST['return_to'] ?? '')) === 'fosti_angajati'
            ? $this->formerUrl()
            : $this->indexUrl();
    }

    private function terminationReturnUrl(): string
    {
        $returnTo = trim((string) ($_POST['return_to'] ?? ''));
        return match ($returnTo) {
            'soferi' => build_query_url(['page' => 'soferi']),
            'fosti_angajati' => $this->formerUrl(),
            default => $this->indexUrl(),
        };
    }
}
