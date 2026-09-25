<?php
declare(strict_types=1);

/**
 * Pagina "Categorii capacitate": catalogul centralizat de etichete dupa care se
 * grupeaza vehiculele in selectoare (Configurare transport, Carburanti, filtre).
 *
 * Aceasta pagina NU atinge capacitatea tehnica reala a niciunui vehicul.
 * Capacitatea reala se editeaza doar din fisa vehiculului si este singura
 * valoare folosita in calcule.
 *
 * Tot aici sta raportul "capacitati de verificat": vehiculele a caror valoare
 * de capacitate provine din perioada in care campul servea si la grupare.
 */
class VehicleCapacityCategoryController
{
    private const PAGE = 'categorii_capacitate';

    private VehicleCapacityCategoryModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new VehicleCapacityCategoryModel($db);
    }

    public function handle(string $action): void
    {
        require_page_or_403(self::PAGE);

        switch ($action) {
            case 'store':
                $this->requireManage();
                $this->saveAction(null);
                return;
            case 'update':
                $this->requireManage();
                $this->saveAction((int) ($_POST['id'] ?? 0));
                return;
            case 'delete':
                $this->requireManage();
                $this->deleteAction();
                return;
            case 'index':
            case 'list':
            default:
                $this->indexAction();
                return;
        }
    }

    private function indexAction(array $formData = [], array $formErrors = []): void
    {
        $editId = (int) ($_GET['edit'] ?? ($formData['id'] ?? 0));
        $editing = $editId > 0 ? $this->model->findById($editId) : null;
        if ($formData === [] && $editing !== null) {
            $formData = $editing;
        }

        $verification = $this->model->getVerificationReport();

        render('categorii_capacitate/index.php', [
            'pageTitle' => 'Categorii capacitate',
            'currentPage' => self::PAGE,
            'categories' => $this->model->getCategories(),
            'migrationReport' => $this->model->getMigrationReport(),
            'verificationRows' => $verification['rows'],
            'verificationSummary' => $verification['summary'],
            'auditTrail' => $this->model->getAuditTrail(25),
            'formData' => $formData,
            'formErrors' => $formErrors,
            'editingId' => $editing !== null ? (int) $editing['id'] : 0,
            'canManage' => can(self::PAGE, 'manage'),
        ]);
    }

    private function saveAction(?int $id): void
    {
        $this->requirePostWithCsrf();

        if ($id !== null && ($id <= 0 || $this->model->findById($id) === null)) {
            flash_set('warning', 'Categoria nu a fost gasita.');
            redirect(build_query_url(['page' => self::PAGE]));
        }

        [$ok, $errors] = $id === null
            ? $this->model->createCategory($_POST)
            : $this->model->updateCategory($id, $_POST);

        if (!$ok) {
            http_response_code(422);
            $this->indexAction(array_merge($_POST, ['id' => $id ?? 0]), $errors);
            return;
        }

        flash_set('success', $id === null ? 'Categoria a fost adaugata.' : 'Categoria a fost actualizata.');
        redirect(build_query_url(['page' => self::PAGE]));
    }

    private function deleteAction(): void
    {
        $this->requirePostWithCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        $reassignRaw = trim((string) ($_POST['reassign_to'] ?? ''));
        // "" = nu s-a ales nimic; "0" = scoate vehiculele din orice categorie.
        $allowDetach = $reassignRaw === '0';
        $reassignTo = ($reassignRaw === '' || $allowDetach) ? null : (int) $reassignRaw;

        try {
            [$ok, $message] = $this->model->deleteCategory($id, $reassignTo, $this->currentUserId(), $allowDetach);
        } catch (Throwable $exception) {
            error_log('[VehicleCapacityCategoryController][delete] ' . $exception->getMessage());
            flash_set('danger', 'Categoria nu a putut fi stearsa.');
            redirect(build_query_url(['page' => self::PAGE]));
            return;
        }

        flash_set($ok ? 'success' : 'warning', $message);
        redirect(build_query_url(['page' => self::PAGE]));
    }

    private function requireManage(): void
    {
        if (!can(self::PAGE, 'manage')) {
            access_deny_403();
        }
    }

    private function requirePostWithCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(build_query_url(['page' => self::PAGE]));
        }
        ensure_csrf_or_redirect(build_query_url(['page' => self::PAGE]));
    }

    private function currentUserId(): ?int
    {
        $userId = (int) (current_user()['id'] ?? 0);

        return $userId > 0 ? $userId : null;
    }
}
