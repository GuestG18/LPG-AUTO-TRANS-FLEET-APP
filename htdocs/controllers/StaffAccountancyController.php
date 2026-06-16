<?php
declare(strict_types=1);

class StaffAccountancyController
{
    private StaffAccountancyModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new StaffAccountancyModel($db);
    }

    public function handle(string $action): void
    {
        require_accountancy_or_403();

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
            case 'delete_staff':
                $this->deleteStaffAction();
                return;
            case 'update_salary':
                $this->updateSalaryAction();
                return;
            case 'store_document':
                $this->storeDocumentAction();
                return;
            case 'delete_document':
                $this->deleteDocumentAction();
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

        try {
            $result = $this->model->getPaginatedStaff($filters, $sort, $direction, $page, ITEMS_PER_PAGE);
            $rows = $result['rows'];
            $viewData = [
                'pageTitle' => 'Contabilitate Personal',
                'currentPage' => 'contabilitate_personal',
                'summary' => $this->model->getSummary(),
                'staffTypes' => $this->model->getStaffTypesWithRequirements(),
                'staffTypeOptions' => $this->model->getStaffTypeOptions(true),
                'allStaffTypeOptions' => $this->model->getStaffTypeOptions(false),
                'driverOptions' => $this->model->getDriverOptions(),
                'documentTypeOptionsByStaffType' => $this->model->getDocumentTypeOptionsByStaffType(),
                'uploadSubjectOptions' => $this->model->getAllStaffForExport([], 'nume', 'asc'),
                'rows' => $rows,
                'documentsBySubject' => $this->model->getDocumentsForRows($rows),
                'salaryHistoryBySubject' => $this->model->getSalaryHistoryForRows($rows),
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
                'pageTitle' => 'Contabilitate Personal',
                'currentPage' => 'contabilitate_personal',
                'summary' => ['total_personal' => 0, 'total_salarii' => 0, 'personal_operational' => 0, 'personal_birou' => 0],
                'staffTypes' => [],
                'staffTypeOptions' => [],
                'allStaffTypeOptions' => [],
                'driverOptions' => [],
                'documentTypeOptionsByStaffType' => [],
                'uploadSubjectOptions' => [],
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
            'Functie',
            'Telefon',
            'Salariu lunar',
            'Data angajarii',
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
        $errors = $this->validateStaffTypeInput($data);
        if ($this->isReservedDriverTypeName((string) ($data['name'] ?? ''))) {
            $errors[] = 'Tipul Șofer este deja conectat la pagina Șoferi și nu poate fi duplicat.';
        }
        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($this->indexUrl());
        }

        try {
            $this->model->createStaffType($data, $this->currentUserId());
            flash_set('success', 'Tipul de personal a fost adaugat.');
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
            flash_set('danger', 'Selecteaza un tip de personal activ.');
            redirect($this->indexUrl());
        }

        try {
            if ((int) ($type['is_driver_linked'] ?? 0) === 1) {
                $this->storeDriverAccountingFromStaffForm();
            } else {
                $data = $this->collectStaffMemberInput($_POST, $staffTypeId);
                $errors = $this->validateStaffMemberInput($data);
                if ($errors !== []) {
                    flash_set('danger', implode(' ', $errors));
                    redirect($this->indexUrl());
                }

                $this->model->createDirectStaff($data, $this->currentUserId());
                flash_set('success', 'Personalul a fost adaugat.');
            }
        } catch (Throwable $exception) {
            error_log('[StaffAccountancyController][store_staff] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva personalul.');
        }

        redirect($this->indexUrl());
    }

    private function updateStaffAction(): void
    {
        $this->requirePost('contabilitate_personal');
        ensure_csrf_or_redirect($this->indexUrl());

        $id = (int) ($_POST['id'] ?? 0);
        $staffTypeId = (int) ($_POST['staff_type_id'] ?? 0);
        $type = $this->model->findStaffType($staffTypeId);
        if ($id <= 0 || $type === null || (int) ($type['is_driver_linked'] ?? 0) === 1) {
            flash_set('danger', 'Inregistrarea selectata nu poate fi editata aici.');
            redirect($this->indexUrl());
        }

        $data = $this->collectStaffMemberInput($_POST, $staffTypeId);
        $errors = $this->validateStaffMemberInput($data, false);
        if ($errors !== []) {
            flash_set('danger', implode(' ', $errors));
            redirect($this->indexUrl());
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

        redirect($this->indexUrl());
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
            flash_set('danger', 'Selecteaza persoana si tipul documentului.');
            redirect($this->indexUrl());
        }

        if (!$this->model->subjectExists($sourceType, $sourceId)) {
            flash_set('warning', 'Persoana selectata nu a fost gasita.');
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

    private function storeDriverAccountingFromStaffForm(): void
    {
        $driverId = (int) ($_POST['driver_id'] ?? 0);
        $driver = $this->model->findDriver($driverId);
        if ($driver === null) {
            flash_set('danger', 'Selecteaza un sofer existent.');
            return;
        }

        $postedSalary = trim((string) ($_POST['driver_salariu'] ?? ''));
        $salary = $postedSalary !== ''
            ? $this->parseMoney($postedSalary)
            : ($driver['salariu'] !== null ? (float) $driver['salariu'] : null);
        $hireDate = $this->normalizeDate((string) ($_POST['driver_data_angajare'] ?? '')) ?: ($driver['data_angajare'] ?? null);
        $notes = trim((string) ($_POST['driver_observatii'] ?? ''));

        if ($postedSalary !== '' && $salary === null) {
            flash_set('danger', 'Introdu un salariu valid pentru sofer.');
            return;
        }

        $this->model->updateDriverAccounting($driverId, $salary, $hireDate ?: null, $notes !== '' ? $notes : null, $this->currentUserId());
        flash_set('success', 'Datele contabile ale soferului au fost actualizate fara a crea un sofer duplicat.');
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
        ];
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

    private function collectStaffMemberInput(array $input, int $staffTypeId): array
    {
        return [
            'staff_type_id' => $staffTypeId,
            'nume_complet' => trim((string) ($input['nume_complet'] ?? '')),
            'telefon' => trim((string) ($input['telefon'] ?? '')),
            'email' => trim((string) ($input['email'] ?? '')),
            'functie' => trim((string) ($input['functie'] ?? '')),
            'salariu' => $this->parseMoney($input['salariu'] ?? null),
            'data_angajare' => $this->normalizeDate((string) ($input['data_angajare'] ?? '')),
            'status' => trim((string) ($input['status'] ?? 'activ')),
            'observatii' => trim((string) ($input['observatii'] ?? '')),
        ];
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
            return [null, 'Fisierul nu a putut fi incarcat.'];
        }

        $maxSize = 5242880;
        if ((int) ($file['size'] ?? 0) > $maxSize) {
            return [null, 'Fisierul depaseste limita de 5 MB.'];
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
            return [null, 'Fisierul nu a putut fi salvat.'];
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
        return $category === 'office' ? 'Personal birou' : 'Personal operational';
    }

    private function documentStatusLabel(string $status): string
    {
        return match ($status) {
            'expirat' => 'Expirat',
            'expira_curand' => 'Expira curand',
            'valid' => 'Valid',
            default => 'Fara documente',
        };
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

    private function indexUrl(): string
    {
        return build_query_url(['page' => 'contabilitate_personal']);
    }
}
