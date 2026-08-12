<?php
declare(strict_types=1);

class LeasingSchedulerController
{
    private LeasingSchedulerModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new LeasingSchedulerModel($db);
    }

    public function handle(string $action): void
    {
        require_page_or_403('scadentar_leasing');

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
            case 'mark_paid':
                $this->markPaidAction();
                return;
            case 'update_notifications':
                $this->updateNotificationsAction();
                return;
            case 'upload_document':
                $this->uploadDocumentAction();
                return;
            case 'download_document':
                $this->downloadDocumentAction();
                return;
            case 'close':
                $this->closeAction();
                return;
            case 'archive':
                $this->archiveAction();
                return;
            case 'export':
                $this->exportAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'scadentar_leasing',
                ]);
                return;
        }
    }

    private function indexAction(): void
    {
        try {
            $dashboard = $this->model->getDashboard($_GET);
        } catch (Throwable $exception) {
            error_log('[LeasingSchedulerController][index] ' . $exception->getMessage());
            flash_set('danger', 'Modulul Scadentar Leasing necesita actualizarea bazei de date. Ruleaza database/update_scadentar_leasing.sql.');
            $dashboard = [
                'filters' => [
                    'financier' => '',
                    'status' => '',
                    'vehicle_id' => 0,
                    'due_date' => '',
                    'q' => '',
                    'selected_id' => 0,
                ],
                'kpis' => [
                    'active_contracts' => 0,
                    'current_month_count' => 0,
                    'current_month_total' => 0,
                    'upcoming_count' => 0,
                    'upcoming_total' => 0,
                    'overdue_count' => 0,
                    'overdue_total' => 0,
                    'month_label' => current_month_ro(),
                ],
                'contracts' => [],
                'filterOptions' => ['financiers' => []],
                'vehicleOptions' => [],
                'statusOptions' => LeasingSchedulerModel::STATUS_LABELS,
                'frequencyOptions' => LeasingSchedulerModel::FREQUENCIES,
                'documentTypes' => LeasingSchedulerModel::DOCUMENT_TYPES,
            ];
        }

        render('scadentar_leasing/index.php', [
            'pageTitle' => 'Scadentar Leasing Auto',
            'currentPage' => 'scadentar_leasing',
            'dashboard' => $dashboard,
        ]);
    }

    private function storeAction(): void
    {
        $this->requirePost();
        $this->requireAction('create');
        ensure_csrf_or_redirect($this->indexUrl());

        [$data, $errors] = $this->collectContractInput($_POST);
        [$settings, $recipients] = $this->collectNotificationInput($_POST);
        if ($data !== null && $this->model->hasOverlappingActiveContract((int) $data['vehicle_id'], (string) $data['start_date'], (string) $data['end_date'])) {
            $errors[] = 'Vehiculul selectat are deja un contract de leasing activ in aceeasi perioada.';
        }

        [$documentData, $uploadError] = $this->storeUploadedDocument($_FILES['contract_document'] ?? null, (string) ($_POST['document_type'] ?? 'contract_leasing'));
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }

        if ($errors !== [] || $data === null) {
            if ($documentData !== null) {
                $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
            }
            flash_set('danger', implode(' ', $errors));
            redirect($this->indexUrl());
        }

        try {
            $contractId = $this->model->createContract($data, $settings, $recipients, $documentData, $this->currentUserId());
            flash_set('success', 'Contractul de leasing a fost adaugat.');
            redirect($this->indexUrl(['selected_id' => $contractId]));
        } catch (Throwable $exception) {
            if ($documentData !== null) {
                $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
            }
            error_log('[LeasingSchedulerController][store] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva contractul de leasing. Verifica numarul de contract si incearca din nou.');
            redirect($this->indexUrl());
        }
    }

    private function updateAction(): void
    {
        $this->requirePost();
        $this->requireAction('edit');
        ensure_csrf_or_redirect($this->indexUrl());

        $contractId = (int) ($_POST['id'] ?? 0);
        [$data, $errors] = $this->collectContractInput($_POST);
        [$settings, $recipients] = $this->collectNotificationInput($_POST);
        if ($contractId <= 0) {
            $errors[] = 'Contractul selectat este invalid.';
        }
        if ($data !== null && $contractId > 0 && $this->model->hasOverlappingActiveContract((int) $data['vehicle_id'], (string) $data['start_date'], (string) $data['end_date'], $contractId)) {
            $errors[] = 'Vehiculul selectat are deja un contract de leasing activ in aceeasi perioada.';
        }

        if ($errors !== [] || $data === null) {
            flash_set('danger', implode(' ', $errors));
            redirect($this->indexUrl(['selected_id' => $contractId]));
        }

        try {
            $updated = $this->model->updateContract($contractId, $data, $settings, $recipients, !empty($_POST['regenerate_schedule']), $this->currentUserId());
            flash_set($updated ? 'success' : 'warning', $updated ? 'Contractul de leasing a fost actualizat.' : 'Contractul nu a fost gasit.');
        } catch (Throwable $exception) {
            error_log('[LeasingSchedulerController][update] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut actualiza contractul.');
        }

        redirect($this->indexUrl(['selected_id' => $contractId]));
    }

    private function markPaidAction(): void
    {
        $this->requirePost();
        $this->requireAction('mark_paid');
        ensure_csrf_or_redirect($this->indexUrl());

        $installmentId = (int) ($_POST['installment_id'] ?? 0);
        $installment = $this->model->findInstallment($installmentId);
        if ($installment === null) {
            flash_set('warning', 'Rata selectata este invalida.');
            redirect($this->indexUrl());
        }

        $paymentDate = $this->normalizeDate((string) ($_POST['payment_date'] ?? '')) ?: date('Y-m-d');
        $amount = $this->parseMoney($_POST['amount_paid'] ?? null);
        if ($amount === null || $amount <= 0) {
            $amount = (float) ($installment['amount'] ?? 0);
        }

        [$proofData, $uploadError] = $this->storeUploadedDocument($_FILES['payment_proof'] ?? null, 'dovada_plata', 'leasing_payment');
        if ($uploadError !== null) {
            flash_set('danger', $uploadError);
            redirect($this->indexUrl(['selected_id' => (int) $installment['contract_id']]));
        }

        try {
            $this->model->markInstallmentPaid($installmentId, [
                'payment_date' => $paymentDate,
                'amount_paid' => $amount,
                'notes' => trim((string) ($_POST['notes'] ?? '')),
            ], $proofData, $this->currentUserId());
            flash_set('success', 'Rata de leasing a fost marcata ca platita.');
        } catch (Throwable $exception) {
            if ($proofData !== null) {
                $this->deletePhysicalDocument((string) ($proofData['stored_name'] ?? ''));
            }
            error_log('[LeasingSchedulerController][mark_paid] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut marca rata ca platita.');
        }

        redirect($this->indexUrl(['selected_id' => (int) $installment['contract_id']]));
    }

    private function updateNotificationsAction(): void
    {
        $this->requirePost();
        $this->requireAction('notifications');
        ensure_csrf_or_redirect($this->indexUrl());

        $contractId = (int) ($_POST['contract_id'] ?? 0);
        [$settings, $recipients] = $this->collectNotificationInput($_POST);
        try {
            $updated = $this->model->updateNotificationSettings($contractId, $settings, $recipients, $this->currentUserId());
            flash_set($updated ? 'success' : 'warning', $updated ? 'Setarile de notificare au fost actualizate.' : 'Contractul nu a fost gasit.');
        } catch (Throwable $exception) {
            error_log('[LeasingSchedulerController][notifications] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut actualiza notificarile.');
        }

        redirect($this->indexUrl(['selected_id' => $contractId]));
    }

    private function uploadDocumentAction(): void
    {
        $this->requirePost();
        $this->requireAction('documents');
        ensure_csrf_or_redirect($this->indexUrl());

        $contractId = (int) ($_POST['contract_id'] ?? 0);
        [$documentData, $uploadError] = $this->storeUploadedDocument($_FILES['document_upload'] ?? null, (string) ($_POST['document_type'] ?? 'alte_documente'));
        if ($uploadError !== null || $documentData === null) {
            flash_set('danger', $uploadError ?? 'Alege un document de incarcat.');
            redirect($this->indexUrl(['selected_id' => $contractId]));
        }
        $documentData['notes'] = trim((string) ($_POST['notes'] ?? ''));

        try {
            $documentId = $this->model->addDocument($contractId, $documentData, $this->currentUserId());
            if ($documentId <= 0) {
                $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
                flash_set('warning', 'Contractul nu a fost gasit.');
            } else {
                flash_set('success', 'Documentul a fost incarcat.');
            }
        } catch (Throwable $exception) {
            $this->deletePhysicalDocument((string) ($documentData['stored_name'] ?? ''));
            error_log('[LeasingSchedulerController][upload_document] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut incarca documentul.');
        }

        redirect($this->indexUrl(['selected_id' => $contractId]));
    }

    private function downloadDocumentAction(): void
    {
        $this->requireAction('documents');

        $documentId = (int) ($_GET['document_id'] ?? 0);
        $document = $this->model->findDocument($documentId);
        if ($document === null || trim((string) ($document['stored_name'] ?? '')) === '') {
            $this->notFoundDocument();
            return;
        }

        $path = BASE_PATH . '/uploads/documente/' . basename((string) $document['stored_name']);
        if (!is_file($path)) {
            $this->notFoundDocument();
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

    private function closeAction(): void
    {
        $this->lifecycleAction('close', 'closeContract', 'Contractul a fost inchis.');
    }

    private function archiveAction(): void
    {
        $this->lifecycleAction('archive', 'archiveContract', 'Contractul a fost arhivat.');
    }

    private function lifecycleAction(string $permission, string $method, string $successMessage): void
    {
        $this->requirePost();
        $this->requireAction($permission);
        ensure_csrf_or_redirect($this->indexUrl());

        $contractId = (int) ($_POST['contract_id'] ?? 0);
        try {
            $updated = $this->model->{$method}($contractId, $this->currentUserId());
            flash_set($updated ? 'success' : 'warning', $updated ? $successMessage : 'Contractul nu a fost gasit.');
        } catch (Throwable $exception) {
            error_log('[LeasingSchedulerController][' . $permission . '] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut actualiza contractul.');
        }

        redirect($this->indexUrl(['selected_id' => $contractId]));
    }

    private function exportAction(): void
    {
        $this->requireAction('export');
        $rows = $this->model->getContractsForExport($_GET);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="scadentar_leasing_' . date('Ymd_His') . '.csv"');
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            exit;
        }

        fputcsv($output, [
            'Nr. inmatriculare',
            'Vehicul',
            'Finantator',
            'Numar contract',
            'Data inceput',
            'Data final',
            'Rata lunara',
            'Urmatoarea scadenta',
            'Status',
            'Total achitat',
            'Sold ramas',
        ], ';');

        foreach ($rows as $row) {
            fputcsv($output, [
                (string) ($row['nr_inmatriculare'] ?? ''),
                trim((string) ($row['marca'] ?? '') . ' ' . (string) ($row['model'] ?? '')),
                (string) ($row['financier'] ?? ''),
                (string) ($row['contract_number'] ?? ''),
                format_date_ro((string) ($row['start_date'] ?? '')),
                format_date_ro((string) ($row['end_date'] ?? '')),
                format_number_ro((float) ($row['default_installment_amount'] ?? 0), 2) . ' ' . (string) ($row['currency'] ?? 'lei'),
                !empty($row['next_due_date']) ? format_date_ro((string) $row['next_due_date']) : '',
                (string) ($row['calculated_status_label'] ?? ''),
                format_number_ro((float) ($row['total_paid'] ?? 0), 2) . ' ' . (string) ($row['currency'] ?? 'lei'),
                format_number_ro((float) ($row['remaining_balance'] ?? 0), 2) . ' ' . (string) ($row['currency'] ?? 'lei'),
            ], ';');
        }

        fclose($output);
        exit;
    }

    private function collectContractInput(array $input): array
    {
        $data = [
            'vehicle_id' => max(0, (int) ($input['vehicle_id'] ?? 0)),
            'financier' => $this->limitString((string) ($input['financier'] ?? ''), 160),
            'contract_number' => $this->limitString((string) ($input['contract_number'] ?? ''), 100),
            'start_date' => $this->normalizeDate((string) ($input['start_date'] ?? '')),
            'end_date' => $this->normalizeDate((string) ($input['end_date'] ?? '')),
            'initial_value' => $this->parseMoney($input['initial_value'] ?? null),
            'advance_amount' => $this->parseMoney($input['advance_amount'] ?? null) ?? 0.0,
            'total_installments' => max(0, (int) ($input['total_installments'] ?? 0)),
            'default_installment_amount' => $this->parseMoney($input['default_installment_amount'] ?? null),
            'currency' => $this->limitString((string) ($input['currency'] ?? 'lei'), 12),
            'frequency' => (string) ($input['frequency'] ?? 'monthly'),
            'due_day' => max(1, min(31, (int) ($input['due_day'] ?? 15))),
            'notes' => trim((string) ($input['notes'] ?? '')),
        ];

        $errors = [];
        if ($data['vehicle_id'] <= 0) {
            $errors[] = 'Selecteaza vehiculul.';
        }
        if ($data['financier'] === '') {
            $errors[] = 'Finantatorul este obligatoriu.';
        }
        if ($data['contract_number'] === '') {
            $errors[] = 'Numarul contractului este obligatoriu.';
        }
        if ($data['start_date'] === null) {
            $errors[] = 'Data de inceput este invalida.';
        }
        if ($data['end_date'] === null) {
            $errors[] = 'Data finala este invalida.';
        }
        if ($data['start_date'] !== null && $data['end_date'] !== null && $data['start_date'] > $data['end_date']) {
            $errors[] = 'Data finala trebuie sa fie dupa data de inceput.';
        }
        if ($data['initial_value'] === null || $data['initial_value'] < 0) {
            $errors[] = 'Valoarea initiala este invalida.';
        }
        if ($data['total_installments'] <= 0) {
            $errors[] = 'Numarul de rate trebuie sa fie mai mare decat zero.';
        }
        if ($data['default_installment_amount'] === null || $data['default_installment_amount'] <= 0) {
            $errors[] = 'Rata implicita trebuie sa fie mai mare decat zero.';
        }
        if (!array_key_exists($data['frequency'], LeasingSchedulerModel::FREQUENCIES)) {
            $errors[] = 'Periodicitatea este invalida.';
        }
        if ($data['currency'] === '') {
            $data['currency'] = 'lei';
        }

        return [$errors === [] ? $data : null, $errors];
    }

    private function collectNotificationInput(array $input): array
    {
        $rawIntervals = (array) ($input['notification_intervals'] ?? []);
        $intervals = [];
        foreach ($rawIntervals as $value) {
            $value = (int) $value;
            if (in_array($value, [7, 3, 1], true)) {
                $intervals[] = $value;
            }
        }
        $recipients = [];
        $rawRecipients = str_replace(["\r", "\n", ';'], ',', (string) ($input['recipients_text'] ?? ''));
        foreach (explode(',', $rawRecipients) as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[$email] = ['email' => $email, 'name' => ''];
            }
        }

        return [
            [
                'enabled' => !empty($input['notifications_enabled']),
                'intervals' => array_values(array_unique($intervals)),
            ],
            array_values($recipients),
        ];
    }

    private function storeUploadedDocument(?array $file, string $documentType, string $prefix = 'leasing_document'): array
    {
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return [null, 'Documentul nu a putut fi incarcat.'];
        }
        if ((int) ($file['size'] ?? 0) > 5242880) {
            return [null, 'Documentul depaseste limita de 5 MB.'];
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
            $storedName = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        } catch (Throwable) {
            $storedName = $prefix . '_' . date('YmdHis') . '_' . str_replace('.', '', uniqid('', true)) . '.' . $extension;
        }

        $destination = $uploadDir . '/' . $storedName;
        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
            return [null, 'Documentul nu a putut fi salvat.'];
        }

        return [[
            'document_type' => array_key_exists($documentType, LeasingSchedulerModel::DOCUMENT_TYPES) ? $documentType : 'alte_documente',
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => (string) ($file['type'] ?? ''),
            'file_size' => (int) ($file['size'] ?? 0),
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
        $raw = trim(str_replace([' ', ','], ['', '.'], (string) $value));
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

    private function limitString(string $value, int $limit): string
    {
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }

    private function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }
    }

    private function requireAction(string $action): void
    {
        if (!function_exists('can') || can('scadentar_leasing', $action)) {
            return;
        }

        access_deny_403();
    }

    private function currentUserId(): ?int
    {
        $user = current_user();
        $id = (int) ($user['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function indexUrl(array $extra = []): string
    {
        return build_query_url(array_merge(['page' => 'scadentar_leasing'], $extra));
    }

    private function notFoundDocument(): void
    {
        http_response_code(404);
        render('errors/404.php', [
            'pageTitle' => 'Document inexistent',
            'currentPage' => 'scadentar_leasing',
        ]);
    }
}
