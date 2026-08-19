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
