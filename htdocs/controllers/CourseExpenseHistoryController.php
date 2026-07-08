<?php
declare(strict_types=1);

class CourseExpenseHistoryController
{
    private DispecerCurseModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new DispecerCurseModel($db);
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;
            case 'export':
                $this->exportAction();
                return;
            case 'store_expense':
                $this->storeExpenseAction();
                return;
            case 'store_category':
                $this->storeCategoryAction();
                return;
            case 'update_category':
                $this->updateCategoryAction();
                return;
            case 'archive_category':
                $this->archiveCategoryAction();
                return;
            case 'delete_category':
                $this->deleteCategoryAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'istoric_cheltuieli_curse',
                ]);
                return;
        }
    }

    private function indexAction(): void
    {
        $filters = $this->collectFilters(true);
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $perPage = max(5, min(100, (int) ($_GET['per_page'] ?? 10)));

        $history = $this->model->getCourseExpenseHistoryData($filters, $page, $perPage);
        $options = $this->model->getCourseExpenseHistoryOptions();

        render('dispecer_curse/istoric_cheltuieli.php', [
            'pageTitle' => 'Istoric cheltuieli curse',
            'currentPage' => 'istoric_cheltuieli_curse',
            'filters' => $filters,
            'rows' => $history['rows'],
            'summary' => $history['summary'],
            'charts' => $history['charts'],
            'pagination' => $history['pagination'],
            'options' => $options,
            'canManageCategories' => is_admin(),
        ]);
    }

    private function exportAction(): void
    {
        $filters = $this->collectFilters(true);
        $rows = $this->model->getCourseExpenseHistoryExportRows($filters);

        $filename = 'istoric_cheltuieli_curse_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            return;
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'Data',
            'Cursa',
            'Vehicul',
            'Sofer',
            'Venit cursa',
            'Cheltuieli totale',
            'Profit',
            'Marja profit',
            'Top categorie cheltuiala',
        ]);

        foreach ($rows as $row) {
            $topCategory = is_array($row['top_categorie'] ?? null) ? $row['top_categorie'] : null;
            $topLabel = $topCategory !== null
                ? ((string) ($topCategory['nume'] ?? '-') . ' (' . number_format((float) ($topCategory['suma'] ?? 0), 2, ',', '.') . ' lei)')
                : '-';

            fputcsv($out, [
                (string) ($row['data_cursa'] ?? ''),
                $this->model->formatRaceCode((int) ($row['id'] ?? 0), (string) ($row['data_cursa'] ?? '')),
                (string) ($row['nr_inmatriculare'] ?? ''),
                (string) ($row['sofer_nume'] ?? ''),
                number_format((float) ($row['venit_cursa'] ?? 0), 2, ',', '.'),
                number_format((float) ($row['total_cheltuieli'] ?? 0), 2, ',', '.'),
                number_format((float) ($row['profit'] ?? 0), 2, ',', '.'),
                number_format((float) ($row['marja_profit'] ?? 0), 2, ',', '.') . '%',
                $topLabel,
            ]);
        }

        fclose($out);
        exit;
    }

    private function storeExpenseAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'istoric_cheltuieli_curse']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'istoric_cheltuieli_curse']));

        $raceId = (int) ($_POST['race_id'] ?? 0);
        $categoryId = (int) ($_POST['categorie_id'] ?? 0);
        $amount = $this->normalizeDecimal((string) ($_POST['suma'] ?? ''));
        $expenseDate = $this->normalizeDate((string) ($_POST['data_cheltuiala'] ?? ''));
        $notes = trim((string) ($_POST['observatii'] ?? ''));
        $errors = [];

        if ($raceId <= 0 || !$this->model->existsRace($raceId)) {
            $errors[] = 'Selecteaza o cursa valida.';
        }

        $category = $categoryId > 0 ? $this->model->resolveExpenseCategorySelection((string) $categoryId, true) : null;
        if ($category === null) {
            $errors[] = 'Selecteaza o categorie activa.';
        }

        if ($amount === null || $amount <= 0) {
            $errors[] = 'Suma trebuie sa fie mai mare decat 0.';
        }

        if ($expenseDate === null) {
            $errors[] = 'Data cheltuielii este invalida.';
        }

        if (mb_strlen($notes) > 5000) {
            $errors[] = 'Observatiile sunt prea lungi.';
        }

        [$uploadedDocument, $uploadError] = $this->storeUploadedExpenseDocument($_FILES['document_upload'] ?? null);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }

        if ($errors !== []) {
            if ($uploadedDocument !== null) {
                $this->deleteExpensePhysicalFile((string) $uploadedDocument['file_path']);
            }
            flash_set('danger', implode(' ', $errors));
            redirect($this->historyReturnUrl());
        }

        $now = date('Y-m-d H:i:s');
        try {
            $expenseId = $this->model->createExpense([
                'cursa_id' => $raceId,
                'tip_cheltuiala' => $this->model->legacyExpenseTypeForCategory($category),
                'categorie_id' => $categoryId,
                'refacturare_tip_cheltuiala' => null,
                'refacturare_detalii' => null,
                'refacturare_suma' => null,
                'refacturare_data' => null,
                'refacturare_observatii' => null,
                'suma' => round((float) $amount, 2),
                'data_cheltuiala' => $expenseDate,
                'observatii' => $notes !== '' ? $notes : null,
                'added_by' => $this->currentUserId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($uploadedDocument !== null) {
                $this->model->addExpenseDocument([
                    'cheltuiala_id' => $expenseId,
                    'file_path' => $uploadedDocument['file_path'],
                    'original_name' => $uploadedDocument['original_name'],
                    'mime_type' => $uploadedDocument['mime_type'],
                    'file_size' => $uploadedDocument['file_size'],
                    'created_at' => $now,
                ]);
            }

            flash_set('success', 'Cheltuiala a fost adaugata.');
        } catch (Throwable $exception) {
            if ($uploadedDocument !== null) {
                $this->deleteExpensePhysicalFile((string) $uploadedDocument['file_path']);
            }
            error_log('[CourseExpenseHistoryController][store_expense] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut salva cheltuiala.');
        }

        redirect($this->historyReturnUrl());
    }

    private function storeCategoryAction(): void
    {
        $this->requireAdminForCategoryAction();
        ensure_csrf_or_redirect(build_query_url(['page' => 'istoric_cheltuieli_curse']));

        $name = trim((string) ($_POST['nume'] ?? ''));
        $description = trim((string) ($_POST['descriere'] ?? ''));
        $active = isset($_POST['activ']) && (string) $_POST['activ'] === '1';

        if ($name === '' || mb_strlen($name) > 150) {
            flash_set('danger', 'Completeaza un nume valid pentru categorie.');
            redirect($this->historyReturnUrl());
        }

        try {
            $this->model->createExpenseCategory($name, $description !== '' ? $description : null, $active);
            flash_set('success', 'Categoria a fost adaugata.');
        } catch (Throwable $exception) {
            error_log('[CourseExpenseHistoryController][store_category] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut adauga categoria. Verifica daca numele nu exista deja.');
        }

        redirect($this->historyReturnUrl());
    }

    private function updateCategoryAction(): void
    {
        $this->requireAdminForCategoryAction();
        ensure_csrf_or_redirect(build_query_url(['page' => 'istoric_cheltuieli_curse']));

        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['nume'] ?? ''));
        $description = trim((string) ($_POST['descriere'] ?? ''));
        $active = isset($_POST['activ']) && (string) $_POST['activ'] === '1';

        if ($id <= 0 || $name === '' || mb_strlen($name) > 150) {
            flash_set('danger', 'Datele categoriei sunt invalide.');
            redirect($this->historyReturnUrl());
        }

        try {
            $this->model->updateExpenseCategory($id, $name, $description !== '' ? $description : null, $active);
            flash_set('success', 'Categoria a fost actualizata.');
        } catch (Throwable $exception) {
            error_log('[CourseExpenseHistoryController][update_category] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut actualiza categoria.');
        }

        redirect($this->historyReturnUrl());
    }

    private function archiveCategoryAction(): void
    {
        $this->requireAdminForCategoryAction();
        ensure_csrf_or_redirect(build_query_url(['page' => 'istoric_cheltuieli_curse']));

        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->model->archiveExpenseCategory($id);
            flash_set('success', 'Categoria a fost arhivata.');
        }

        redirect($this->historyReturnUrl());
    }

    private function deleteCategoryAction(): void
    {
        $this->requireAdminForCategoryAction();
        ensure_csrf_or_redirect(build_query_url(['page' => 'istoric_cheltuieli_curse']));

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('danger', 'Categoria este invalida.');
            redirect($this->historyReturnUrl());
        }

        if ($this->model->deleteExpenseCategoryIfUnused($id)) {
            flash_set('success', 'Categoria a fost stearsa.');
        } else {
            flash_set('warning', 'Categoria este folosita si poate fi doar arhivata.');
        }

        redirect($this->historyReturnUrl());
    }

    private function collectFilters(bool $withDefaults): array
    {
        $today = date('Y-m-d');
        $firstDay = date('Y-m-01');

        $dateFrom = $this->normalizeDate((string) ($_GET['date_from'] ?? ''));
        $dateTo = $this->normalizeDate((string) ($_GET['date_to'] ?? ''));

        if ($withDefaults) {
            $dateFrom ??= $firstDay;
            $dateTo ??= $today;
        }

        return [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'category_id' => max(0, (int) ($_GET['category_id'] ?? 0)),
            'vehicle_id' => max(0, (int) ($_GET['vehicle_id'] ?? 0)),
            'driver_id' => max(0, (int) ($_GET['driver_id'] ?? 0)),
            'race_id' => max(0, (int) ($_GET['race_id'] ?? 0)),
            'document_state' => in_array((string) ($_GET['document_state'] ?? ''), ['cu', 'fara'], true)
                ? (string) $_GET['document_state']
                : '',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
    }

    private function normalizeDecimal(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function storeUploadedExpenseDocument(?array $file): array
    {
        if ($file === null || !is_array($file)) {
            return [null, null];
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return [null, 'Fisierul nu a putut fi incarcat.'];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return [null, 'Fisierul incarcat nu este valid.'];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return [null, 'Fisierul depaseste limita maxima de 5 MB.'];
        }

        $originalName = $this->sanitizeUploadedFileName((string) ($file['name'] ?? 'document'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];
        if (!in_array($extension, $allowedExtensions, true)) {
            return [null, 'Tipul fisierului nu este permis.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo !== false ? (string) (finfo_file($finfo, $tmpName) ?: '') : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        $allowedMimeTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/octet-stream',
            'application/zip',
        ];
        if ($mimeType !== '' && !in_array($mimeType, $allowedMimeTypes, true)) {
            return [null, 'Tipul MIME al fisierului nu este permis.'];
        }

        $uploadDir = BASE_PATH . '/uploads/curse_cheltuieli';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return [null, 'Nu s-a putut crea folderul de upload.'];
        }

        try {
            $storedName = 'cheltuiala_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8));
        } catch (Throwable) {
            $storedName = 'cheltuiala_' . date('Ymd_His') . '_' . uniqid('', true);
        }
        $storedName .= '.' . $extension;

        if (!move_uploaded_file($tmpName, $uploadDir . '/' . $storedName)) {
            return [null, 'Fisierul nu a putut fi salvat pe server.'];
        }

        return [[
            'file_path' => $storedName,
            'original_name' => $originalName,
            'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'file_size' => $size,
        ], null];
    }

    private function sanitizeUploadedFileName(string $name): string
    {
        $name = trim(basename($name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'document';
        return substr($name, 0, 180);
    }

    private function deleteExpensePhysicalFile(string $storedFile): void
    {
        $storedFile = basename($storedFile);
        if ($storedFile === '') {
            return;
        }

        $path = BASE_PATH . '/uploads/curse_cheltuieli/' . $storedFile;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function currentUserId(): ?int
    {
        $userId = current_user()['id'] ?? null;
        if (!is_int($userId) && !is_numeric((string) $userId)) {
            return null;
        }

        $userId = (int) $userId;
        return $userId > 0 ? $userId : null;
    }

    private function requireAdminForCategoryAction(): void
    {
        if (!is_admin()) {
            http_response_code(403);
            render('errors/403.php', [
                'pageTitle' => 'Acces interzis',
                'currentPage' => 'istoric_cheltuieli_curse',
            ]);
            exit;
        }
    }

    private function historyReturnUrl(): string
    {
        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));
        if ($returnUrl !== '' && str_starts_with($returnUrl, '/')) {
            return $returnUrl;
        }

        return build_query_url(['page' => 'istoric_cheltuieli_curse']);
    }
}
