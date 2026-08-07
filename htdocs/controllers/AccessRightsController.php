<?php
declare(strict_types=1);

class AccessRightsController
{
    private AccessRightsModel $model;

    public function __construct(PDO $db)
    {
        $this->model = new AccessRightsModel($db);
    }

    public function handle(string $action): void
    {
        require_admin_or_403();

        switch ($action) {
            case 'index':
            case 'list':
                $this->indexAction();
                return;
            case 'save':
                $this->saveAction();
                return;
            case 'apply_template':
                $this->applyTemplateAction();
                return;
            case 'save_template':
                $this->saveTemplateAction();
                return;
            case 'delete_template':
                $this->deleteTemplateAction();
                return;
            case 'reset_user':
                $this->resetUserAction();
                return;
            default:
                http_response_code(404);
                render('errors/404.php', [
                    'pageTitle' => 'Actiune inexistenta',
                    'currentPage' => 'drepturi_acces',
                ]);
                return;
        }
    }

    private function indexAction(): void
    {
        try {
            $users = $this->model->getUsers();
        } catch (Throwable $exception) {
            error_log('[AccessRightsController][index] ' . $exception->getMessage());
            flash_set('danger', 'Modulul Drepturi de acces necesită actualizarea bazei de date. Rulează database/update_drepturi_acces.sql.');
            $users = [];
        }

        $selectedUserId = (int) ($_GET['user'] ?? 0);
        $selectedUser = null;
        foreach ($users as $user) {
            if ((int) $user['id'] === $selectedUserId) {
                $selectedUser = $user;
                break;
            }
        }
        if ($selectedUser === null) {
            // implicit: primul utilizator non-admin, altfel primul din lista
            foreach ($users as $user) {
                if ((string) $user['rol'] !== 'admin') {
                    $selectedUser = $user;
                    break;
                }
            }
            $selectedUser = $selectedUser ?? ($users[0] ?? null);
        }

        $granted = [];
        $isConfigured = false;
        if ($selectedUser !== null) {
            $uid = (int) $selectedUser['id'];
            $isConfigured = (int) ($selectedUser['is_configured'] ?? 0) === 1;
            $granted = $isConfigured
                ? $this->model->getUserPermissions($uid)
                : $this->legacyDefaultsForRole((string) $selectedUser['rol']);
        }

        render('drepturi_acces/index.php', [
            'pageTitle'     => 'Drepturi de acces',
            'currentPage'   => 'drepturi_acces',
            'subtitle'      => 'Control fin al accesului utilizatorilor, la nivel de pagină și de acțiune.',
            'groups'        => permission_groups(),
            'pages'         => permission_pages(),
            'users'         => $users,
            'selectedUser'  => $selectedUser,
            'granted'       => $granted,
            'isConfigured'  => $isConfigured,
            'templates'     => $this->templatesWithSeed(),
        ]);
    }

    private function saveAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->indexUrl());

        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId <= 0 || $this->model->findUser($userId) === null) {
            flash_set('warning', 'Utilizatorul selectat este invalid.');
            redirect($this->indexUrl());
        }

        $granted = $this->collectGranted($_POST['perm'] ?? []);

        try {
            $this->model->saveUserPermissions($userId, $granted, $this->currentUserId());
            flash_set('success', 'Drepturile au fost salvate.');
        } catch (Throwable $exception) {
            error_log('[AccessRightsController][save] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva drepturile.');
        }

        redirect($this->indexUrl($userId));
    }

    private function applyTemplateAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->indexUrl());

        $userId = (int) ($_POST['user_id'] ?? 0);
        $templateId = (int) ($_POST['template_id'] ?? 0);
        if ($userId <= 0 || $templateId <= 0) {
            flash_set('warning', 'Selectează un utilizator și un șablon valide.');
            redirect($this->indexUrl($userId));
        }

        try {
            $perms = $this->model->getTemplatePermissions($templateId);
            $granted = [];
            foreach ($perms as $pageKey => $actions) {
                $granted[$pageKey] = array_keys($actions);
            }
            $granted = $this->collectGranted($this->toCheckboxShape($granted));
            $this->model->saveUserPermissions($userId, $granted, $this->currentUserId());
            flash_set('success', 'Șablonul a fost aplicat utilizatorului.');
        } catch (Throwable $exception) {
            error_log('[AccessRightsController][apply_template] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut aplica șablonul.');
        }

        redirect($this->indexUrl($userId));
    }

    private function saveTemplateAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->indexUrl());

        $name = trim((string) ($_POST['template_name'] ?? ''));
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($name === '') {
            flash_set('warning', 'Dă un nume șablonului.');
            redirect($this->indexUrl($userId));
        }

        $granted = $this->collectGranted($_POST['perm'] ?? []);

        try {
            $this->model->saveTemplate($name, $granted, $templateId > 0 ? $templateId : null);
            flash_set('success', 'Șablonul a fost salvat.');
        } catch (Throwable $exception) {
            error_log('[AccessRightsController][save_template] ' . $exception->getMessage());
            flash_set('danger', 'Nu am putut salva șablonul.');
        }

        redirect($this->indexUrl($userId));
    }

    private function deleteTemplateAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->indexUrl());

        $templateId = (int) ($_POST['template_id'] ?? 0);
        if ($templateId > 0) {
            try {
                $this->model->deleteTemplate($templateId);
                flash_set('success', 'Șablonul a fost șters.');
            } catch (Throwable $exception) {
                error_log('[AccessRightsController][delete_template] ' . $exception->getMessage());
                flash_set('danger', 'Nu am putut șterge șablonul.');
            }
        }

        redirect($this->indexUrl());
    }

    private function resetUserAction(): void
    {
        $this->requirePost();
        ensure_csrf_or_redirect($this->indexUrl());

        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            try {
                $this->model->resetUser($userId);
                flash_set('success', 'Utilizatorul a revenit la accesul implicit al rolului său.');
            } catch (Throwable $exception) {
                error_log('[AccessRightsController][reset_user] ' . $exception->getMessage());
                flash_set('danger', 'Nu am putut reseta utilizatorul.');
            }
        }

        redirect($this->indexUrl($userId));
    }

    /**
     * Transforma input-ul din formular in [page_key => [action_key, ...]],
     * filtrand pe catalog si garantand ca 'view' e prezent daca pagina are actiuni.
     *
     * @return array<string,array<int,string>>
     */
    private function collectGranted(mixed $perm): array
    {
        $pages = permission_pages();
        $granted = [];

        foreach ((array) $perm as $pageKey => $actions) {
            $pageKey = (string) $pageKey;
            if (!isset($pages[$pageKey])) {
                continue;
            }
            $validActions = array_keys((array) ($pages[$pageKey]['actions'] ?? []));
            $selected = [];
            foreach ((array) $actions as $actionKey => $value) {
                $actionKey = (string) $actionKey;
                if (in_array($actionKey, $validActions, true)) {
                    $selected[$actionKey] = true;
                }
            }
            if ($selected === []) {
                continue;
            }
            // orice actiune acordata implica accesul la pagina
            $selected['view'] = true;
            $granted[$pageKey] = array_keys($selected);
        }

        return $granted;
    }

    /** @param array<string,array<int,string>> $granted */
    private function toCheckboxShape(array $granted): array
    {
        $shape = [];
        foreach ($granted as $pageKey => $actions) {
            foreach ($actions as $actionKey) {
                $shape[$pageKey][$actionKey] = '1';
            }
        }

        return $shape;
    }

    /**
     * Ce poate face IMPLICIT (legacy) un utilizator cu rolul dat — starea inițială
     * a matricei înainte ca adminul să configureze explicit utilizatorul.
     *
     * @return array<string,array<string,bool>>
     */
    private function legacyDefaultsForRole(string $rol): array
    {
        $rol = strtolower(trim($rol));
        $granted = [];

        foreach (permission_pages() as $pageKey => $page) {
            $scope = (string) ($page['scope'] ?? 'all');
            $allowed = match ($scope) {
                'admin'       => $rol === 'admin',
                'accountancy' => in_array($rol, ['admin', 'contabilitate'], true),
                default       => true,
            };
            if (!$allowed) {
                continue;
            }

            foreach ((array) ($page['actions'] ?? []) as $actionKey => $meta) {
                // actiunile marcate admin raman doar pentru admin
                if (($meta['admin'] ?? false) === true && $rol !== 'admin') {
                    continue;
                }
                $granted[$pageKey][(string) $actionKey] = true;
            }
        }

        return $granted;
    }

    private function templatesWithSeed(): array
    {
        try {
            $templates = $this->model->listTemplates();
            if ($templates === []) {
                // sabloane de sistem implicite, derivate din comportamentul legacy pe rol
                $this->model->ensureSystemTemplate('Operator', 'operator', $this->flatten($this->legacyDefaultsForRole('utilizator')));
                $this->model->ensureSystemTemplate('Contabil', 'contabil', $this->flatten($this->legacyDefaultsForRole('contabilitate')));
                $templates = $this->model->listTemplates();
            }

            return $templates;
        } catch (Throwable $exception) {
            error_log('[AccessRightsController][templates] ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * @param array<string,array<string,bool>> $granted
     * @return array<string,array<int,string>>
     */
    private function flatten(array $granted): array
    {
        $out = [];
        foreach ($granted as $pageKey => $actions) {
            $out[$pageKey] = array_keys($actions);
        }

        return $out;
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

    private function indexUrl(int $userId = 0): string
    {
        $params = ['page' => 'drepturi_acces'];
        if ($userId > 0) {
            $params['user'] = $userId;
        }

        return build_query_url($params);
    }
}
