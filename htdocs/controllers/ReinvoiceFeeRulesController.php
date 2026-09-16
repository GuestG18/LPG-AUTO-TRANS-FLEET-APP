<?php
declare(strict_types=1);

/**
 * Pagina "Reguli taxe refacturare": pe ce rute se cere taxa acces, port, trecere
 * sau taxe drum. Regulile active alimenteaza avertizarile "Taxe de refacturat lipsa"
 * din panoul de aprobari. Vezi ReinvoiceFeeExpectationModel.
 */
class ReinvoiceFeeRulesController
{
    private const PAGE = 'reguli_taxe_refacturare';

    private ReinvoiceFeeExpectationModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new ReinvoiceFeeExpectationModel($db);
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
            case 'toggle':
                $this->requireManage();
                $this->toggleAction();
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
        $editingRule = $editId > 0 ? $this->model->getRule($editId) : null;
        if ($formData === []) {
            if ($editingRule !== null) {
                $formData = $editingRule;
            } elseif (isset($_GET['zona_distributie_id'])) {
                // Pre-completare din "Sugestii din istoric".
                $formData = [
                    'zona_distributie_id' => (int) $_GET['zona_distributie_id'],
                    'loc_incarcare_id' => (int) ($_GET['loc_incarcare_id'] ?? 0),
                    'tip_transport' => (string) ($_GET['tip_transport'] ?? ''),
                    'tip_cheltuiala' => (string) ($_GET['tip_cheltuiala'] ?? ''),
                    'suma_uzuala' => (string) ($_GET['suma_uzuala'] ?? ''),
                    'activ' => 1,
                ];
            }
        }

        render('reguli_taxe_refacturare/index.php', [
            'pageTitle' => 'Reguli taxe refacturare',
            'currentPage' => self::PAGE,
            'rules' => $this->model->getRules(),
            'suggestions' => $this->model->getSuggestions(),
            'places' => $this->model->getPlaceOptions(),
            'feeTypes' => ReinvoiceFeeExpectationModel::FEE_TYPES,
            'transportTypes' => ReinvoiceFeeExpectationModel::TRANSPORT_TYPES,
            'formData' => $formData,
            'formErrors' => $formErrors,
            'editingId' => $editingRule !== null ? (int) $editingRule['id'] : 0,
            'canManage' => can(self::PAGE, 'manage'),
        ]);
    }

    private function saveAction(?int $id): void
    {
        $this->requirePostWithCsrf();

        if ($id !== null && ($id <= 0 || $this->model->getRule($id) === null)) {
            flash_set('warning', 'Regula nu a fost gasita.');
            redirect(build_query_url(['page' => self::PAGE]));
        }

        [$savedId, $errors] = $this->model->saveRule($_POST, $id, $this->currentUserId());
        if ($errors !== []) {
            http_response_code(422);
            $this->indexAction(array_merge($_POST, ['id' => $id ?? 0]), $errors);
            return;
        }

        flash_set('success', $id === null ? 'Regula a fost adaugata.' : 'Regula a fost actualizata.');
        redirect(build_query_url(['page' => self::PAGE]) . '#regula-' . (int) $savedId);
    }

    private function toggleAction(): void
    {
        $this->requirePostWithCsrf();
        $ok = $this->model->toggleRule((int) ($_POST['id'] ?? 0));
        flash_set($ok ? 'success' : 'warning', $ok ? 'Starea regulii a fost schimbata.' : 'Regula nu a fost gasita.');
        redirect(build_query_url(['page' => self::PAGE]));
    }

    private function deleteAction(): void
    {
        $this->requirePostWithCsrf();
        $ok = $this->model->deleteRule((int) ($_POST['id'] ?? 0));
        flash_set($ok ? 'success' : 'warning', $ok ? 'Regula a fost stearsa.' : 'Regula nu a fost gasita.');
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
