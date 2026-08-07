<?php
declare(strict_types=1);

class OfficeExpenseController
{
    private OfficeExpenseModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new OfficeExpenseModel($db);
    }

    public function handle(string $action): void
    {
        require_page_or_403('cheltuieli_birou');

        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;
            case 'export':
                $this->exportAction();
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
            case 'download_document':
                $this->downloadDocumentAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'cheltuieli_birou',
                ]);
                return;
        }
    }

    private function indexAction(): void
    {
        $filters = $this->collectFilters();
        $sort = trim((string) ($_GET['sort'] ?? 'data'));
        $direction = strtolower(trim((string) ($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($_GET['p'] ?? 1));

        try {
            $result = $this->model->getPaginatedExpenses($filters, $sort, $direction, $page, ITEMS_PER_PAGE);
            $rows = $result['rows'];
            $viewData = [
                'pageTitle' => 'Cheltuieli Birou',
                'currentPage' => 'cheltuieli_birou',
                'subtitle' => 'Evidența cheltuielilor administrative și de birou',
                'dashboard' => $this->model->getDashboardData(),
                'rows' => $rows,
                'documentsByExpense' => $this->model->getDocumentsForRows($rows),
                'categories' => $this->model->getCategories(true, true),
                'manualCategories' => $this->model->getCategories(false, true),
                'filters' => $filters,
                'sort' => $sort,
                'direction' => $direction,
                'pagination' => [
                    'page' => $result['page'],
                    'total_pages' => $result['total_pages'],
                    'total_rows' => $result['total_rows'],
                    'per_page' => $result['per_page'],
                ],
                'paymentMethods' => OfficeExpenseModel::PAYMENT_METHODS,
                'documentTypes' => OfficeExpenseModel::DOCUMENT_TYPES,
                'rentPaymentStatuses' => OfficeExpenseModel::RENT_PAYMENT_STATUSES,
            ];
        } catch (Throwable $exception) {
            error_log('[OfficeExpenseController][index] ' . $exception->getMessage());
            flash_set('danger', 'Modulul Cheltuieli Birou necesita actualizarea bazei de date. Ruleaza database/update_cheltuieli_birou.sql.');
            $viewData = [
                'pageTitle' => 'Cheltuieli Birou',
                'currentPage' => 'cheltuieli_birou',
                'subtitle' => 'Evidența cheltuielilor administrative și de birou',
                'dashboard' => [
                    'kpis' => ['total_lunar' => 0, 'total_an_curent' => 0, 'numar_cheltuieli' => 0, 'ultima_cheltuiala' => null],
                    'category_totals' => [],
                    'monthly_evolution' => [],
                    'type_totals' => ['administrative' => ['total' => 0, 'percent' => 0], 'operational' => ['total' => 0, 'percent' => 0], 'grand_total' => 0],
                ],
                'rows' => [],
                'documentsByExpense' => [],
                'categories' => [],
                'manualCategories' => [],
                'filters' => $filters,
                'sort' => $sort,
                'direction' => $direction,
                'pagination' => ['page' => 1, 'total_pages' => 1, 'total_rows' => 0, 'per_page' => ITEMS_PER_PAGE],
                'paymentMethods' => OfficeExpenseModel::PAYMENT_METHODS,
                'documentTypes' => OfficeExpenseModel::DOCUMENT_TYPES,
                'rentPaymentStatuses' => OfficeExpenseModel::RENT_PAYMENT_STATUSES,
            ];
        }

        render('cheltuieli_birou/index.php', $viewData);
    }

    private function exportAction(): void
    {
        $filters = $this->collectFilters();
        $sort = trim((string) ($_GET['sort'] ?? 'data'));
        $direction = strtolower(trim((string) ($_GET['dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $rows = $this->model->getExpensesForExport($filters, $sort, $direction);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="cheltuieli_birou_' . date('Ymd_His') . '.csv"');
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            exit;
        }

        fputcsv($output, [
            '#',
            'Data',
            'Categorie',
            'Descriere',
            'Furnizor',
            'Suma fara TVA',
            'TVA',
            'Total cu TVA',
            'Metoda plata',
            'Numar factura / bon',
            'Documente',
            'Adaugat de',
        ], ';');

        foreach ($rows as $index => $row) {
            fputcsv($output, [
                (string) ($index + 1),
                !empty($row['expense_date']) ? format_date_ro((string) $row['expense_date']) : '',
                (string) ($row['category_name'] ?? ''),
                (string) ($row['description'] ?? ''),
                (string) ($row['supplier'] ?? ''),
                format_number_ro($row['amount_net'] ?? 0, 2),
                format_number_ro($row['vat_amount'] ?? 0, 2),
                format_number_ro($row['amount_total'] ?? 0, 2),
                $this->paymentMethodLabel((string) ($row['payment_method'] ?? '')),
                (string) ($row['invoice_number'] ?? ''),
                (string) ($row['document_count'] ?? 0),
                (string) ($row['added_by_name'] ?? ''),
            ], ';');
        }

        fclose($output);
        exit;
    }

    private function storeAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->indexUrl());

        $data = $this->collectExpenseInput($_POST);
        $errors = $this->validateExpenseInput($data);
        [$documentData, $uploadError] = $this->storeUploadedDocument($_FILES['document_upload'] ?? null, (string) ($_POST['document_type'] ?? 'factura'));
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }

        if ($errors !== []) {
            if ($documentData !== null) {
                $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
            }
            flash_set('danger', implode(' ', $errors));
            redirect($this->indexUrl());
        }

        try {
            $this->model->createExpense($data, $documentData, $this->currentUserId());
            flash_set('success', 'Cheltuiala de birou a fost adăugată.');
        } catch (Throwable $exception) {
            if ($documentData !== null) {
                $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
            }
            error_log('[OfficeExpenseController][store] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva cheltuiala.');
        }

        redirect($this->indexUrl());
    }

    private function updateAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->indexUrl());

        $id = (int) ($_POST['id'] ?? 0);
        $data = $this->collectExpenseInput($_POST);
        $errors = $this->validateExpenseInput($data);
        if ($id <= 0) {
            $errors[] = 'Cheltuiala selectată este invalidă.';
        }

        [$documentData, $uploadError] = $this->storeUploadedDocument($_FILES['document_upload'] ?? null, (string) ($_POST['document_type'] ?? 'factura'));
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }

        if ($errors !== []) {
            if ($documentData !== null) {
                $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
            }
            flash_set('danger', implode(' ', $errors));
            redirect($this->indexUrl());
        }

        try {
            if (!$this->model->updateExpense($id, $data, $documentData, $this->currentUserId())) {
                flash_set('warning', 'Cheltuiala nu a fost găsită.');
            } else {
                flash_set('success', 'Cheltuiala a fost actualizată.');
            }
        } catch (Throwable $exception) {
            if ($documentData !== null) {
                $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
            }
            error_log('[OfficeExpenseController][update] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut actualiza cheltuiala.');
        }

        redirect($this->indexUrl());
    }

    private function deleteAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->indexUrl());

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'Cheltuiala selectată este invalidă.');
            redirect($this->indexUrl());
        }

        try {
            $documents = $this->model->deleteExpense($id);
            foreach ($documents as $document) {
                $this->deletePhysicalDocument((string) ($document['stored_name'] ?? ''));
            }
            flash_set('success', 'Cheltuiala a fost ștearsă.');
        } catch (Throwable $exception) {
            error_log('[OfficeExpenseController][delete] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut șterge cheltuiala.');
        }

        redirect($this->indexUrl());
    }

    private function downloadDocumentAction(): void
    {
        $documentId = (int) ($_GET['document_id'] ?? 0);
        $document = $this->model->findDocument($documentId);
        if ($document === null || trim((string) ($document['stored_name'] ?? '')) === '') {
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => 'Document inexistent',
                'currentPage' => 'cheltuieli_birou',
            ]);
            return;
        }

        $path = BASE_PATH . '/uploads/documente/' . basename((string) $document['stored_name']);
        if (!is_file($path)) {
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => 'Document inexistent',
                'currentPage' => 'cheltuieli_birou',
            ]);
            return;
        }

        $downloadName = trim((string) ($document['original_name'] ?? ''));
        if ($downloadName === '') {
            $downloadName = basename($path);
        }

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
        readfile($path);
        exit;
    }

    private function collectFilters(): array
    {
        $today = new DateTimeImmutable('today');
        $defaultStart = $today->modify('first day of this month')->format('Y-m-d');
        $defaultEnd = $today->modify('last day of this month')->format('Y-m-d');

        return [
            'date_start' => $this->normalizeDate((string) ($_GET['date_start'] ?? $defaultStart)) ?: $defaultStart,
            'date_end' => $this->normalizeDate((string) ($_GET['date_end'] ?? $defaultEnd)) ?: $defaultEnd,
            'category_id' => (int) ($_GET['category_id'] ?? 0),
            'payment_method' => trim((string) ($_GET['payment_method'] ?? '')),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
    }

    private function collectExpenseInput(array $input): array
    {
        $net = $this->parseMoney($input['amount_net'] ?? null) ?? 0.0;
        $vat = $this->parseMoney($input['vat_amount'] ?? null) ?? 0.0;
        $total = $this->parseMoney($input['amount_total'] ?? null);
        if ($total === null || $total <= 0) {
            $total = $net + $vat;
        }

        return [
            'category_id' => (int) ($input['category_id'] ?? 0),
            'expense_date' => $this->normalizeDate((string) ($input['expense_date'] ?? '')) ?: date('Y-m-d'),
            'description' => trim((string) ($input['description'] ?? '')),
            'supplier' => trim((string) ($input['supplier'] ?? '')),
            'amount_net' => $net,
            'vat_amount' => $vat,
            'amount_total' => $total,
            'payment_method' => trim((string) ($input['payment_method'] ?? 'transfer_bancar')),
            'invoice_number' => trim((string) ($input['invoice_number'] ?? '')),
            'notes' => trim((string) ($input['notes'] ?? '')),
            'monthly_rent_amount' => $this->parseMoney($input['monthly_rent_amount'] ?? null),
            'contract_number' => trim((string) ($input['contract_number'] ?? '')),
            'rent_period_start' => $this->normalizeDate((string) ($input['rent_period_start'] ?? '')),
            'rent_period_end' => $this->normalizeDate((string) ($input['rent_period_end'] ?? '')),
            'due_date' => $this->normalizeDate((string) ($input['due_date'] ?? '')),
            'payment_status' => trim((string) ($input['payment_status'] ?? '')),
            'landlord_name' => trim((string) ($input['landlord_name'] ?? '')),
        ];
    }

    private function validateExpenseInput(array $data): array
    {
        $errors = [];
        $category = $this->model->findCategory((int) ($data['category_id'] ?? 0));
        if ($category === null || (string) ($category['status'] ?? '') !== 'activ') {
            $errors[] = 'Selectează o categorie validă.';
        } elseif ((int) ($category['is_automatic'] ?? 0) === 1) {
            $errors[] = 'Categoria Salarii birou este calculată automat din Contabilitate Personal și nu poate fi adăugată manual.';
        }

        if ((string) ($data['description'] ?? '') === '') {
            $errors[] = 'Descrierea este obligatorie.';
        }
        if ((float) ($data['amount_total'] ?? 0) <= 0) {
            $errors[] = 'Totalul cu TVA trebuie să fie mai mare decât zero.';
        }
        if (!array_key_exists((string) ($data['payment_method'] ?? ''), OfficeExpenseModel::PAYMENT_METHODS)) {
            $errors[] = 'Metoda de plată este invalidă.';
        }
        $paymentStatus = (string) ($data['payment_status'] ?? '');
        if ($paymentStatus !== '' && !array_key_exists($paymentStatus, OfficeExpenseModel::RENT_PAYMENT_STATUSES)) {
            $errors[] = 'Statusul chiriei este invalid.';
        }

        return $errors;
    }

    private function storeUploadedDocument(?array $file, string $documentType): array
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return [null, 'Documentul nu a putut fi încărcat.'];
        }

        $documentType = array_key_exists($documentType, OfficeExpenseModel::DOCUMENT_TYPES) ? $documentType : 'factura';
        $maxSize = 5242880;
        if ((int) ($file['size'] ?? 0) > $maxSize) {
            return [null, 'Documentul depășește limita de 5 MB.'];
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];
        if (!in_array($extension, $allowed, true)) {
            return [null, 'Formatul documentului nu este permis.'];
        }

        $uploadDir = BASE_PATH . '/uploads/documente';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return [null, 'Directorul de upload nu poate fi creat.'];
        }

        try {
            $storedName = 'office_expense_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        } catch (Throwable) {
            $storedName = 'office_expense_' . date('YmdHis') . '_' . str_replace('.', '', uniqid('', true)) . '.' . $extension;
        }

        $destination = $uploadDir . '/' . $storedName;
        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
            return [null, 'Documentul nu a putut fi salvat.'];
        }

        return [[
            'document_type' => $documentType,
            'original_name' => $originalName,
            'stored_name' => $storedName,
        ], null];
    }

    private function deletePhysicalDocument(string $storedFile): void
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

    private function paymentMethodLabel(string $method): string
    {
        return OfficeExpenseModel::PAYMENT_METHODS[$method] ?? $method;
    }

    private function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
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
        return build_query_url(['page' => 'cheltuieli_birou']);
    }
}
