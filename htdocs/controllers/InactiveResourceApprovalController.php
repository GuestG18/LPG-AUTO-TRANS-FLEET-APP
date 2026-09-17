<?php
declare(strict_types=1);

class InactiveResourceApprovalController
{
    private InactiveResourceApprovalModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new InactiveResourceApprovalModel($db);
    }

    public function handle(string $action): void
    {
        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;

            case 'show':
                $this->showAction();
                return;

            case 'approve':
                $this->reviewAction('approved');
                return;

            case 'reject':
                $this->reviewAction('rejected');
                return;

            case 'reopen':
                $this->reopenAction();
                return;

            case 'operator_activity':
                $this->operatorActivityAction();
                return;

            case 'missing_fees':
                $this->missingFeesAction();
                return;

            case 'dismiss_missing_fee':
                $this->dismissMissingFeeAction();
                return;

            case 'fee_not_bought':
                $this->feeNotBoughtAction();
                return;

            case 'fee_no_invoice_needed':
                $this->feeNoInvoiceNeededAction();
                return;

            case 'fee_attach_invoice':
                $this->feeAttachInvoiceAction();
                return;

            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'dashboard',
                ]);
                return;
        }
    }

    private function indexAction(): void
    {
        $canReviewApprovals = $this->canReview();
        if (!$canReviewApprovals) {
            $userId = $this->currentUserId();
            $selectedStatus = strtolower(trim((string) ($_GET['status'] ?? 'pending')));
            if (!in_array($selectedStatus, ['pending', 'approved', 'rejected'], true)) {
                $selectedStatus = 'pending';
            }

            render('inactive_approvals/user_index.php', [
                'pageTitle' => 'Solicitarile mele de aprobare',
                'currentPage' => 'dashboard',
                'summary' => $this->model->getRequesterSummary((int) ($userId ?? 0), 50),
                'selectedStatus' => $selectedStatus,
            ]);
            return;
        }

        $filters = $this->resolveFilters();
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $result = $this->model->search($filters, $page, 20);

        render('inactive_approvals/index.php', [
            'pageTitle' => 'Solicitari aprobare',
            'currentPage' => 'dashboard',
            'filters' => $filters,
            'result' => $result,
            'reasonOptions' => $this->model->getReasonOptions(),
            'canReviewApprovals' => $canReviewApprovals,
        ]);
    }

    private function showAction(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $approval = $this->model->getById($id);
        if ($approval === null) {
            flash_set('warning', 'Solicitarea de aprobare nu a fost gasita.');
            redirect(build_query_url(['page' => 'inactive_approvals']));
        }

        $canReviewApprovals = $this->canReview();
        if (!$canReviewApprovals && (int) ($approval['requested_by_user_id'] ?? 0) !== (int) ($this->currentUserId() ?? 0)) {
            http_response_code(403);
            render('errors/403.php', [
                'pageTitle' => 'Acces interzis',
                'currentPage' => '',
            ]);
            exit;
        }

        render('inactive_approvals/show.php', [
            'pageTitle' => 'Detalii solicitare aprobare',
            'currentPage' => 'dashboard',
            'approval' => $approval,
            'canReviewApprovals' => $canReviewApprovals,
        ]);
    }

    private function reviewAction(string $targetStatus): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'inactive_approvals']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'inactive_approvals']));
        $this->requireReviewAccess();

        $id = (int) ($_POST['id'] ?? 0);
        $note = trim((string) ($_POST['review_note'] ?? ''));
        if ($note === '') {
            $note = $this->defaultReviewNote($targetStatus, (string) ($_POST['decision_source'] ?? ''));
        }
        $ok = $targetStatus === 'approved'
            ? $this->model->approve($id, $this->currentUserId(), $note)
            : $this->model->reject($id, $this->currentUserId(), $note);

        $message = $targetStatus === 'approved'
            ? ($ok ? 'Solicitarea a fost aprobata.' : 'Solicitarea nu mai este in asteptare.')
            : ($ok ? 'Solicitarea a fost respinsa.' : 'Solicitarea nu mai este in asteptare.');

        if ($this->wantsJson()) {
            $payload = [
                'success' => $ok,
                'message' => $message,
                'approval_id' => $id,
                'summary' => $this->model->getPendingSummary(5),
            ];
            if (!$ok) {
                $current = $this->model->getById($id);
                if ($current !== null) {
                    $payload['current_status'] = (string) ($current['status'] ?? '');
                }
            }

            $this->sendJson($payload, $ok ? 200 : 409);
        }

        flash_set($ok ? 'success' : 'warning', $message);
        $this->redirectAfterAction();
    }

    private function reopenAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => 'inactive_approvals']));
        }

        ensure_csrf_or_redirect(build_query_url(['page' => 'inactive_approvals']));
        $this->requireReviewAccess();

        $id = (int) ($_POST['id'] ?? 0);
        $note = trim((string) ($_POST['review_note'] ?? ''));
        $ok = $this->model->reopen($id, $this->currentUserId(), $note);

        $message = $ok
            ? 'Solicitarea a fost repusa in asteptare.'
            : 'Solicitarea este deja in asteptare sau nu a fost gasita.';

        if ($this->wantsJson()) {
            $this->sendJson([
                'success' => $ok,
                'message' => $message,
            ], $ok ? 200 : 409);
        }

        flash_set($ok ? 'success' : 'warning', $message);
        $this->redirectAfterAction();
    }

    /**
     * JSON pentru tab-ul "Operatori" din panoul de aprobari: curse adaugate si
     * inchise pe zi, per operator. Apelat periodic din panou (actualizare live).
     */
    private function operatorActivityAction(): void
    {
        $this->requireReviewAccess();

        $date = trim((string) ($_GET['date'] ?? ''));
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $date = date('Y-m-d');
        }

        try {
            $db = get_pdo();
            $activityModel = new OperatorActivityModel($db);
            $incomplete = $activityModel->reconcile(new DispecerCurseModel($db));
            $this->sendJson([
                'success' => true,
                'activity' => $activityModel->getDailyActivity($date, $incomplete),
            ]);
        } catch (Throwable $exception) {
            error_log('[InactiveResourceApprovalController][operator_activity] ' . $exception->getMessage());
            $this->sendJson([
                'success' => false,
                'message' => 'Activitatea operatorilor nu a putut fi incarcata.',
            ], 500);
        }
    }

    /**
     * Taxe de refacturat care probabil lipsesc (taxa acces, port, trecere).
     * Adminul vede toate cursele; operatorul doar cursele adaugate de el.
     */
    private function missingFeesAction(): void
    {
        try {
            $model = new ReinvoiceFeeExpectationModel(get_pdo());
            $ownerFilter = $this->canReview() ? null : (int) ($this->currentUserId() ?? 0);
            $this->sendJson([
                'success' => true,
                'scope' => $this->canReview() ? 'all' : 'own',
                'missing_fees' => $model->getMissingFees($ownerFilter),
                'purchase_checks' => $model->getPurchaseChecks($ownerFilter),
            ]);
        } catch (Throwable $exception) {
            error_log('[InactiveResourceApprovalController][missing_fees] ' . $exception->getMessage());
            $this->sendJson([
                'success' => false,
                'message' => 'Taxele lipsa nu au putut fi incarcate.',
            ], 500);
        }
    }

    /**
     * "Nu se aplica": operatorul confirma ca o cursa nu are taxa asteptata.
     */
    private function dismissMissingFeeAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_token'] ?? null)) {
            $this->sendJson(['success' => false, 'message' => 'Cerere invalida. Reincarca pagina.'], 400);
        }

        $raceId = (int) ($_POST['race_id'] ?? 0);
        $feeType = trim((string) ($_POST['fee_type'] ?? ''));

        try {
            $model = new ReinvoiceFeeExpectationModel(get_pdo());
            $creator = $model->getRaceCreator($raceId);
            if ($creator === null) {
                $this->sendJson(['success' => false, 'message' => 'Cursa nu a fost gasita.'], 404);
            }
            if (!$this->canReview() && $creator !== (int) ($this->currentUserId() ?? 0)) {
                $this->sendJson(['success' => false, 'message' => 'Poti marca doar cursele adaugate de tine.'], 403);
            }

            $ok = $model->dismiss($raceId, $feeType, $this->currentUserId());
            $this->sendJson(['success' => $ok, 'message' => $ok ? 'Marcat ca nu se aplica.' : 'Tip de taxa invalid.'], $ok ? 200 : 422);
        } catch (Throwable $exception) {
            error_log('[InactiveResourceApprovalController][dismiss_missing_fee] ' . $exception->getMessage());
            $this->sendJson(['success' => false, 'message' => 'Nu am putut salva marcajul.'], 500);
        }
    }

    /**
     * Trecere refacturata, dar taxa nu a fost cumparata: ramane in lista cu motivul.
     */
    private function feeNotBoughtAction(): void
    {
        [$model, $expense] = $this->resolvePurchaseExpense();
        $ok = $model->markNotBought((int) $expense['id'], (string) ($_POST['reason'] ?? ''), $this->currentUserId());
        $this->sendJson(['success' => $ok, 'message' => $ok ? 'Marcat ca necumparata.' : 'Nu am putut salva marcajul.'], $ok ? 200 : 500);
    }

    /**
     * Taxa a fost cumparata, dar factura nu e cazul: trecerea iese din lista.
     */
    private function feeNoInvoiceNeededAction(): void
    {
        [$model, $expense] = $this->resolvePurchaseExpense();
        $ok = $model->markNoInvoiceNeeded((int) $expense['id'], $this->currentUserId());
        $this->sendJson(['success' => $ok, 'message' => $ok ? 'Marcat: cumparata, fara factura.' : 'Nu am putut salva marcajul.'], $ok ? 200 : 500);
    }

    /**
     * Taxa a fost cumparata: factura devine documentul refacturarii.
     */
    private function feeAttachInvoiceAction(): void
    {
        [$model, $expense] = $this->resolvePurchaseExpense();
        if (trim((string) ($expense['refacturare_document_path'] ?? '')) !== '') {
            $this->sendJson(['success' => false, 'message' => 'Refacturarea are deja un document atasat.'], 409);
        }

        [$document, $error] = $this->storeFeeInvoice($_FILES['invoice'] ?? null);
        if ($document === null) {
            $this->sendJson(['success' => false, 'message' => $error ?? 'Alege fisierul facturii.'], 422);
        }

        try {
            $db = get_pdo();
            (new DispecerCurseModel($db))->updateExpenseRefacturareDocument((int) $expense['id'], $document);
            $model->clearNotBought((int) $expense['id']);
        } catch (Throwable $exception) {
            @unlink(BASE_PATH . '/uploads/curse_cheltuieli/' . $document['file_path']);
            error_log('[InactiveResourceApprovalController][fee_attach_invoice] ' . $exception->getMessage());
            $this->sendJson(['success' => false, 'message' => 'Factura nu a putut fi salvata.'], 500);
        }

        $this->sendJson(['success' => true, 'message' => 'Factura a fost atasata.']);
    }

    /**
     * POST + CSRF + refacturarea de trecere; operatorul poate lucra doar pe cursele lui.
     *
     * @return array{0: ReinvoiceFeeExpectationModel, 1: array}
     */
    private function resolvePurchaseExpense(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['_token'] ?? null)) {
            $this->sendJson(['success' => false, 'message' => 'Cerere invalida. Reincarca pagina.'], 400);
        }

        try {
            $model = new ReinvoiceFeeExpectationModel(get_pdo());
            $expense = $model->getPurchaseExpense((int) ($_POST['expense_id'] ?? 0));
        } catch (Throwable $exception) {
            error_log('[InactiveResourceApprovalController][fee_purchase] ' . $exception->getMessage());
            $this->sendJson(['success' => false, 'message' => 'Nu am putut incarca refacturarea.'], 500);
        }

        if ($expense === null) {
            $this->sendJson(['success' => false, 'message' => 'Refacturarea nu a fost gasita.'], 404);
        }
        if (!$this->canReview() && (int) $expense['created_by'] !== (int) ($this->currentUserId() ?? 0)) {
            $this->sendJson(['success' => false, 'message' => 'Poti modifica doar cursele adaugate de tine.'], 403);
        }

        return [$model, $expense];
    }

    /** Aceleasi reguli ca documentele de cheltuieli din Dispecer curse (5 MB, PDF / imagine / Word). */
    private function storeFeeInvoice(?array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($file === null || $error === UPLOAD_ERR_NO_FILE) {
            return [null, 'Alege fisierul facturii.'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return [null, $error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? 'Fisierul depaseste limita permisa.'
                : 'Fisierul nu a putut fi incarcat.'];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return [null, 'Fisierul incarcat nu este valid.'];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return [null, 'Fisierul depaseste limita maxima de 5 MB.'];
        }

        $originalName = preg_replace('/[^A-Za-z0-9._-]/', '', preg_replace('/\s+/', '_', trim((string) ($file['name'] ?? ''))) ?? '') ?? '';
        if ($originalName === '' || $originalName === '.' || $originalName === '..') {
            $originalName = 'factura';
        }
        $originalName = substr($originalName, 0, 255);

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'], true)) {
            return [null, 'Tipul fisierului nu este permis (PDF, imagine sau Word).'];
        }

        $mimeType = (string) (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmpName) ?: '');
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
            return [null, 'Tipul fisierului nu este permis (PDF, imagine sau Word).'];
        }

        $uploadDir = BASE_PATH . '/uploads/curse_cheltuieli';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return [null, 'Nu s-a putut crea folderul de upload pentru cheltuieli.'];
        }

        $storedName = 'cheltuiala_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
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

    private function resolveFilters(): array
    {
        $status = strtolower(trim((string) ($_GET['status'] ?? 'pending')));
        if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $resourceType = strtolower(trim((string) ($_GET['resource_type'] ?? 'all')));
        if (!in_array($resourceType, ['vehicle', 'driver', 'repair', 'all'], true)) {
            $resourceType = 'all';
        }

        return [
            'status' => $status,
            'resource_type' => $resourceType === 'all' ? '' : $resourceType,
            'reason' => trim((string) ($_GET['reason'] ?? '')),
            'date_start' => trim((string) ($_GET['date_start'] ?? '')),
            'date_end' => trim((string) ($_GET['date_end'] ?? '')),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
    }

    private function canReview(): bool
    {
        if (function_exists('can')) {
            return can('inactive_approvals', 'review');
        }

        return function_exists('is_admin') && is_admin();
    }

    private function requireReviewAccess(): void
    {
        if ($this->canReview()) {
            return;
        }

        if ($this->wantsJson()) {
            $this->sendJson([
                'success' => false,
                'message' => 'Nu ai dreptul sa aprobi sau sa respingi solicitari.',
            ], 403);
        }

        http_response_code(403);
        render('errors/403.php', [
            'pageTitle' => 'Acces interzis',
            'currentPage' => '',
        ]);
        exit;
    }

    private function redirectAfterAction(): void
    {
        $returnUrl = trim((string) ($_POST['return_url'] ?? ''));
        if ($returnUrl !== '') {
            $parsed = parse_url($returnUrl);
            $path = (string) ($parsed['path'] ?? '');
            $query = (string) ($parsed['query'] ?? '');
            $isIndexPath = $path === ''
                || $path === 'index.php'
                || str_ends_with($path, '/index.php');
            if ($isIndexPath && $query !== '') {
                redirect($returnUrl);
            }
        }

        redirect(build_query_url(['page' => 'inactive_approvals']));
    }

    private function defaultReviewNote(string $targetStatus, string $source): string
    {
        $source = strtolower(trim($source));
        $sourceLabel = match ($source) {
            'popup' => 'popup-ul de aprobari',
            'approval_page' => 'pagina de detalii',
            default => 'aplicatie',
        };

        return ($targetStatus === 'approved' ? 'Aprobat' : 'Respins') . ' din ' . $sourceLabel . '.';
    }

    private function wantsJson(): bool
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    private function sendJson(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
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
}
