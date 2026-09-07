<?php
declare(strict_types=1);

/**
 * Pagina "Cazare".
 *
 * Cheltuiala de cazare se introduce cu 4 campuri (Data / Sofer / Total / Total cu TVA),
 * iar cursa se determina automat din Dispecer curse: cursa soferului a carei perioada
 * acopera data cazarii. Vezi AccommodationExpenseModel pentru regulile de asociere.
 */
class AccommodationExpenseController
{
    private const PER_PAGE_OPTIONS = [10, 20, 50, 100];
    private const MAX_UPLOAD_SIZE = 5242880; // 5 MB, ca la documentele de cheltuiala cursa
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'];

    /**
     * Facturile de cazare stau in acelasi folder ca documentele de cheltuiala cursa,
     * fiindca randul-oglinda din curse_cheltuieli trimite catre acelasi fisier.
     */
    private const UPLOAD_DIR = 'uploads/curse_cheltuieli';

    private AccommodationExpenseModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new AccommodationExpenseModel($db);
    }

    public function handle(string $action): void
    {
        require_page_or_403('cazare');

        try {
            $this->model->ensureSchema();
        } catch (Throwable $exception) {
            error_log('[AccommodationExpenseController][schema] ' . $exception->getMessage());
            flash_set('danger', 'Modulul Cazare necesita actualizarea bazei de date. Ruleaza database/update_cheltuieli_cazare.sql.');
        }

        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;
            case 'store':
                $this->requireAction('create');
                $this->storeAction();
                return;
            case 'update':
                $this->requireAction('edit');
                $this->updateAction();
                return;
            case 'delete':
                $this->requireAction('delete');
                $this->deleteAction();
                return;
            case 'link':
                $this->requireAction('link');
                $this->linkAction();
                return;
            case 'unlink':
                $this->requireAction('link');
                $this->unlinkAction();
                return;
            case 'rematch':
                $this->requireAction('link');
                $this->rematchAction();
                return;
            case 'delete_document':
                $this->requireAction('edit');
                $this->deleteDocumentAction();
                return;
            case 'download_document':
                $this->downloadDocumentAction();
                return;
            case 'export':
                $this->requireAction('export');
                $this->exportAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'cazare',
                ]);
                return;
        }
    }

    // -------------------------------------------------------------------------
    // Listare
    // -------------------------------------------------------------------------

    private function indexAction(): void
    {
        $filters = $this->collectFilters();
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $perPage = (int) ($_GET['pp'] ?? 20);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 20;
        }

        // Cazarile in asteptare se reincearca la fiecare deschidere a paginii:
        // o cursa introdusa ulterior le poate prelua fara interventie manuala.
        try {
            $this->model->rematchPending();
        } catch (Throwable $exception) {
            error_log('[AccommodationExpenseController][rematch] ' . $exception->getMessage());
        }

        try {
            $result = $this->model->getPaginated($filters, $page, $perPage);
            $summary = $this->model->getSummary($filters);
            $drivers = $this->model->getDrivers();
        } catch (Throwable $exception) {
            error_log('[AccommodationExpenseController][index] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-au putut incarca cheltuielile de cazare.');
            $result = ['rows' => [], 'page' => 1, 'per_page' => $perPage, 'total_rows' => 0, 'total_pages' => 1];
            $summary = ['count' => 0, 'total' => 0.0, 'total_cu_tva' => 0.0, 'asociate' => 0, 'neasociate' => 0, 'ambigue' => 0];
            $drivers = [];
        }

        render('cazare/index.php', [
            'pageTitle' => 'Cazare',
            'currentPage' => 'cazare',
            'filters' => $filters,
            'rows' => $result['rows'],
            'summary' => $summary,
            'drivers' => $drivers,
            'statuses' => AccommodationExpenseModel::STATUSES,
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total_rows' => $result['total_rows'],
                'total_pages' => $result['total_pages'],
            ],
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'canCreate' => can('cazare', 'create'),
            'canEdit' => can('cazare', 'edit'),
            'canDelete' => can('cazare', 'delete'),
            'canLink' => can('cazare', 'link'),
            'canExport' => can('cazare', 'export'),
        ]);
    }

    /** @return array<string, mixed> */
    private function collectFilters(): array
    {
        return [
            'data_start' => $this->normalizeDate((string) ($_GET['data_start'] ?? '')),
            'data_end' => $this->normalizeDate((string) ($_GET['data_end'] ?? '')),
            'sofer_id' => (int) ($_GET['sofer_id'] ?? 0) > 0 ? (int) $_GET['sofer_id'] : null,
            'status' => trim((string) ($_GET['status'] ?? '')),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
    }

    // -------------------------------------------------------------------------
    // Scriere
    // -------------------------------------------------------------------------

    private function storeAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->returnUrl());

        [$data, $errors] = $this->collectFormData();
        [$uploaded, $uploadError] = $this->storeUploadedDocument($_FILES['document_upload'] ?? null);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }

        if ($errors !== []) {
            $this->deletePhysicalFile($uploaded['file_path'] ?? null);
            flash_set('danger', implode(' ', $errors));
            redirect($this->returnUrl());
        }

        try {
            $id = $this->model->create($data);
            if ($uploaded !== null) {
                $this->model->addDocument($id, $uploaded);
            }
            $row = $this->model->getById($id);
            flash_set('success', 'Cazarea a fost salvata. ' . $this->associationMessage($row));
        } catch (Throwable $exception) {
            $this->deletePhysicalFile($uploaded['file_path'] ?? null);
            error_log('[AccommodationExpenseController][store] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut salva cazarea.');
        }

        redirect($this->returnUrl());
    }

    private function updateAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->returnUrl());

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0 || $this->model->getById($id) === null) {
            flash_set('warning', 'Inregistrarea de cazare nu exista.');
            redirect($this->returnUrl());
        }

        [$data, $errors] = $this->collectFormData();
        [$uploaded, $uploadError] = $this->storeUploadedDocument($_FILES['document_upload'] ?? null);
        if ($uploadError !== null) {
            $errors[] = $uploadError;
        }

        if ($errors !== []) {
            $this->deletePhysicalFile($uploaded['file_path'] ?? null);
            flash_set('danger', implode(' ', $errors));
            redirect($this->returnUrl());
        }

        try {
            $this->model->update($id, $data);
            if ($uploaded !== null) {
                $this->model->addDocument($id, $uploaded);
            }
            $row = $this->model->getById($id);
            flash_set('success', 'Cazarea a fost actualizata. ' . $this->associationMessage($row));
        } catch (Throwable $exception) {
            $this->deletePhysicalFile($uploaded['file_path'] ?? null);
            error_log('[AccommodationExpenseController][update] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut actualiza cazarea.');
        }

        redirect($this->returnUrl());
    }

    private function deleteAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->returnUrl());

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0 || $this->model->getById($id) === null) {
            flash_set('warning', 'Inregistrarea de cazare nu exista.');
            redirect($this->returnUrl());
        }

        try {
            foreach ($this->model->delete($id) as $filePath) {
                $this->deletePhysicalFile($filePath);
            }
            flash_set('success', 'Cazarea a fost stearsa.');
        } catch (Throwable $exception) {
            error_log('[AccommodationExpenseController][delete] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut sterge cazarea.');
        }

        redirect($this->returnUrl());
    }

    // -------------------------------------------------------------------------
    // Asociere
    // -------------------------------------------------------------------------

    private function linkAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->returnUrl());

        $id = (int) ($_POST['id'] ?? 0);
        $raceId = (int) ($_POST['cursa_id'] ?? 0);

        if ($id <= 0 || $raceId <= 0) {
            flash_set('warning', 'Selecteaza o cursa valida.');
            redirect($this->returnUrl());
        }

        try {
            if ($this->model->linkManually($id, $raceId)) {
                flash_set('success', 'Cazarea a fost asociata cursei selectate.');
            } else {
                flash_set('warning', 'Cursa selectata nu mai este disponibila.');
            }
        } catch (Throwable $exception) {
            error_log('[AccommodationExpenseController][link] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut face asocierea.');
        }

        redirect($this->returnUrl());
    }

    private function unlinkAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->returnUrl());

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_set('warning', 'Inregistrarea de cazare nu exista.');
            redirect($this->returnUrl());
        }

        try {
            $this->model->unlink($id);
            $row = $this->model->getById($id);
            flash_set('success', 'Alegerea manuala a fost anulata. ' . $this->associationMessage($row));
        } catch (Throwable $exception) {
            error_log('[AccommodationExpenseController][unlink] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut anula asocierea.');
        }

        redirect($this->returnUrl());
    }

    private function rematchAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->returnUrl());

        try {
            $result = $this->model->rematchPending();
            flash_set('success', sprintf(
                'Reverificare finalizata: %d inregistrari analizate, %d asociate la cursa.',
                $result['procesate'],
                $result['asociate']
            ));
        } catch (Throwable $exception) {
            error_log('[AccommodationExpenseController][rematch] ' . $exception->getMessage());
            flash_set('danger', 'Reverificarea asocierilor a esuat.');
        }

        redirect($this->returnUrl());
    }

    // -------------------------------------------------------------------------
    // Documente (facturi)
    // -------------------------------------------------------------------------

    private function deleteDocumentAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->returnUrl());

        $documentId = (int) ($_POST['document_id'] ?? 0);
        if ($documentId <= 0) {
            flash_set('warning', 'Documentul nu exista.');
            redirect($this->returnUrl());
        }

        try {
            $filePath = $this->model->deleteDocument($documentId);
            if ($filePath === null) {
                flash_set('warning', 'Documentul nu exista.');
            } else {
                $this->deletePhysicalFile($filePath);
                flash_set('success', 'Documentul a fost sters.');
            }
        } catch (Throwable $exception) {
            error_log('[AccommodationExpenseController][delete_document] ' . $exception->getMessage());
            flash_set('danger', 'Nu s-a putut sterge documentul.');
        }

        redirect($this->returnUrl());
    }

    /**
     * Serveste fisierul prin PHP, ca sa nu depindem de accesul direct la folderul
     * de upload si sa pastram documentul in spatele autentificarii.
     */
    private function downloadDocumentAction(): void
    {
        $documentId = (int) ($_GET['document_id'] ?? 0);
        $document = $documentId > 0 ? $this->model->getDocumentById($documentId) : null;

        if ($document === null) {
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => 'Document inexistent',
                'currentPage' => 'cazare',
            ]);
            return;
        }

        $path = BASE_PATH . '/' . self::UPLOAD_DIR . '/' . basename((string) $document['file_path']);
        if (!is_file($path)) {
            http_response_code(404);
            render('errors/404.php', [
                'pageTitle' => 'Fisier inexistent',
                'currentPage' => 'cazare',
            ]);
            return;
        }

        $mime = trim((string) ($document['mime_type'] ?? ''));
        // Documentele se deschid inline (preview in tab nou); descarcarea ramane
        // la latitudinea browserului.
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . basename((string) $document['original_name']) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /**
     * @return array{0: ?array{file_path: string, original_name: string, mime_type: string, file_size: int}, 1: ?string}
     */
    private function storeUploadedDocument(?array $file): array
    {
        if (!is_array($file)) {
            return [null, null];
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return [null, null];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return [null, 'Factura nu a putut fi incarcata.'];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return [null, 'Fisierul incarcat nu este valid.'];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_SIZE) {
            return [null, 'Fisierul depaseste limita maxima de 5 MB.'];
        }

        $originalName = $this->sanitizeUploadedFileName((string) ($file['name'] ?? 'document'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return [null, 'Tipul fisierului nu este permis (PDF, JPG, PNG, WEBP, DOC, DOCX).'];
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

        $uploadDir = BASE_PATH . '/' . self::UPLOAD_DIR;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return [null, 'Nu s-a putut crea folderul de upload.'];
        }

        try {
            $storedName = 'cazare_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8));
        } catch (Throwable) {
            $storedName = 'cazare_' . date('Ymd_His') . '_' . uniqid('', true);
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

    private function deletePhysicalFile(?string $storedFile): void
    {
        $storedFile = basename((string) $storedFile);
        if ($storedFile === '' || $storedFile === '.') {
            return;
        }

        $path = BASE_PATH . '/' . self::UPLOAD_DIR . '/' . $storedFile;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // -------------------------------------------------------------------------
    // Export
    // -------------------------------------------------------------------------

    private function exportAction(): void
    {
        $filters = $this->collectFilters();

        try {
            $rows = $this->model->getExportRows($filters);
        } catch (Throwable $exception) {
            error_log('[AccommodationExpenseController][export] ' . $exception->getMessage());
            flash_set('danger', 'Exportul a esuat.');
            redirect($this->returnUrl());
            return;
        }

        $filename = 'cazare_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            return;
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Data', 'Sofer', 'Total', 'Total cu TVA', 'Status', 'Cursa', 'Perioada cursa', 'Vehicul', 'Factura', 'Observatii']);

        foreach ($rows as $row) {
            $period = $row['data_inceput'] !== null
                ? format_date_ro((string) $row['data_inceput']) . ' - ' . format_date_ro((string) $row['data_sfarsit'])
                : '-';

            fputcsv($out, [
                format_date_ro((string) $row['data']),
                (string) $row['sofer_nume'],
                number_format((float) $row['total'], 2, ',', '.'),
                number_format((float) $row['total_cu_tva'], 2, ',', '.'),
                AccommodationExpenseModel::STATUSES[(string) $row['status']] ?? (string) $row['status'],
                $row['cursa_id'] !== null ? '#' . (int) $row['cursa_id'] : '-',
                $period,
                (string) ($row['nr_inmatriculare'] ?? '-'),
                (string) ($row['documente'] ?? ''),
                (string) ($row['observatii'] ?? ''),
            ]);
        }

        fclose($out);
        exit;
    }

    // -------------------------------------------------------------------------
    // Helperi
    // -------------------------------------------------------------------------

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function collectFormData(): array
    {
        $errors = [];

        $date = $this->normalizeDate((string) ($_POST['data'] ?? ''));
        if ($date === null) {
            $errors[] = 'Data cazarii este obligatorie si trebuie sa fie o data valida.';
        }

        $driverId = (int) ($_POST['sofer_id'] ?? 0);
        if ($driverId <= 0) {
            $errors[] = 'Selecteaza soferul.';
        }

        $total = $this->normalizeDecimal((string) ($_POST['total'] ?? ''));
        if ($total === null || $total < 0) {
            $errors[] = 'Totalul trebuie sa fie un numar pozitiv.';
        }

        $totalWithVat = $this->normalizeDecimal((string) ($_POST['total_cu_tva'] ?? ''));
        if ($totalWithVat === null || $totalWithVat <= 0) {
            $errors[] = 'Totalul cu TVA trebuie sa fie mai mare decat 0.';
        }

        if ($total !== null && $totalWithVat !== null && $totalWithVat + 0.001 < $total) {
            $errors[] = 'Totalul cu TVA nu poate fi mai mic decat totalul fara TVA.';
        }

        $notes = trim((string) ($_POST['observatii'] ?? ''));
        if (mb_strlen($notes) > 5000) {
            $errors[] = 'Observatiile sunt prea lungi.';
        }

        return [
            [
                'data' => (string) $date,
                'sofer_id' => $driverId,
                'total' => (float) $total,
                'total_cu_tva' => (float) $totalWithVat,
                'observatii' => $notes !== '' ? $notes : null,
                'created_by' => $this->currentUserId(),
            ],
            $errors,
        ];
    }

    private function associationMessage(?array $row): string
    {
        if ($row === null) {
            return '';
        }

        return match ((string) $row['status']) {
            'asociat' => 'Asociata automat cursei #' . (int) $row['cursa_id']
                . ' (' . format_date_ro((string) $row['data_inceput']) . ' - ' . format_date_ro((string) $row['data_sfarsit']) . ').',
            'ambiguu' => 'Exista mai multe curse care acopera data respectiva: alege cursa corecta din tabel.',
            default => 'Nu exista inca o cursa care sa acopere data respectiva; ramane in asteptare si se asociaza automat cand cursa este introdusa.',
        };
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTime::createFromFormat('Y-m-d', $value);

        return ($date !== false && $date->format('Y-m-d') === $value) ? $value : null;
    }

    private function normalizeDecimal(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = str_replace([' ', ','], ['', '.'], $value);

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function currentUserId(): ?int
    {
        $userId = current_user()['id'] ?? null;
        if (!is_int($userId) && !is_numeric((string) $userId)) {
            return null;
        }

        return (int) $userId > 0 ? (int) $userId : null;
    }

    private function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'cazare']));
        }
    }

    private function requireAction(string $action): void
    {
        if (!can('cazare', $action)) {
            access_deny_403();
        }
    }

    private function returnUrl(): string
    {
        $params = ['page' => 'cazare'];

        foreach (['data_start', 'data_end', 'sofer_id', 'status', 'q', 'p', 'pp'] as $key) {
            $value = trim((string) ($_POST[$key] ?? $_GET[$key] ?? ''));
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return build_query_url($params);
    }
}
