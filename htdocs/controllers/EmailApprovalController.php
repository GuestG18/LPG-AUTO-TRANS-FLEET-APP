<?php
declare(strict_types=1);

/**
 * Aprobare dintr-un link primit pe email, fara autentificare.
 *
 * Token-ul din URL tine loc de autentificare, deci ruta este publica.
 * GET-ul NU modifica nimic: doar arata cererea si un buton de confirmare.
 * Modificarea se face pe POST, pentru ca scanerele de securitate si unele
 * clienti de email deschid preventiv linkurile din mesaje - un GET care
 * decide ar aproba cereri fara ca nimeni sa fi apasat ceva.
 */
class EmailApprovalController
{
    private ApprovalEmailActionModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new ApprovalEmailActionModel($db);
    }

    public function handle(string $action): void
    {
        $token = trim((string) ($_REQUEST['t'] ?? ''));

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm') {
            $this->confirmAction($token);
            return;
        }

        $this->showAction($token);
    }

    private function showAction(string $token): void
    {
        $record = $this->model->findByToken($token);
        $blocked = $this->model->rejectionReason($record);

        if ($blocked !== null || $record === null) {
            $this->renderPage([
                'state' => 'blocked',
                'title' => 'Link indisponibil',
                'message' => $blocked ?? 'Linkul nu este valid.',
                'record' => $record,
            ]);
            return;
        }

        $this->renderPage([
            'state' => 'confirm',
            'title' => (string) $record['action'] === 'approve' ? 'Confirmi aprobarea?' : 'Confirmi respingerea?',
            'record' => $record,
            'documents' => $this->model->documentsFor((int) $record['approval_id']),
            'token' => $token,
        ]);
    }

    private function confirmAction(string $token): void
    {
        try {
            $result = $this->model->consume($token, 'link');
        } catch (Throwable $exception) {
            error_log('[EmailApproval] ' . $exception->getMessage());
            $this->renderPage([
                'state' => 'blocked',
                'title' => 'Eroare',
                'message' => 'Decizia nu a putut fi salvata. Incearca din nou sau intra in aplicatie.',
                'record' => null,
            ]);
            return;
        }

        $record = $this->model->findByToken($token);

        $this->renderPage([
            'state' => $result['applied'] ? 'done' : 'blocked',
            'title' => $result['applied']
                ? ((string) $result['status'] === 'approved' ? 'Aprobat' : 'Respins')
                : 'Nicio schimbare',
            'message' => $result['message'],
            'record' => $record,
        ]);
    }

    /**
     * Pagina este complet de sine statatoare: layout-ul aplicatiei presupune
     * utilizator autentificat si meniu lateral, care aici nu au ce cauta.
     */
    private function renderPage(array $data): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');

        extract($data, EXTR_SKIP);
        require BASE_PATH . '/views/email_approval/page.php';
        exit;
    }
}
