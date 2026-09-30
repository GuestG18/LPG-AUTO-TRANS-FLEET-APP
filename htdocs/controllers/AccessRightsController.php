<?php
declare(strict_types=1);

/**
 * Pagina "Drepturi de acces".
 *
 * Nu contine NICIO lista de pagini / actiuni: tot ce afiseaza si tot ce accepta
 * la salvare vine din PermissionRegistry (permissions/modules/*.php). Un modul nou
 * inregistrat acolo apare aici automat.
 */
class AccessRightsController
{
    private AccessRightsModel $model;
    private PermissionRegistry $registry;

    public function __construct(PDO $db)
    {
        $this->model = new AccessRightsModel($db);
        $this->registry = permission_registry();
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
        $deprecated = [];
        try {
            // Catalogul din BD urmeaza registrul (idempotent: fara scrieri daca nu s-a schimbat nimic).
            $this->model->syncCatalog($this->registry->flatPermissions());
            $deprecated = $this->model->deprecatedPermissions();
        } catch (Throwable $exception) {
            error_log('[AccessRightsController][sync] ' . $exception->getMessage());
        }

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
        $roleDefaults = [];
        $isConfigured = false;
        if ($selectedUser !== null) {
            $roleDefaults = access_role_defaults((string) $selectedUser['rol']);
            $isConfigured = (int) ($selectedUser['is_configured'] ?? 0) === 1;
            $granted = $isConfigured
                ? $this->model->getUserPermissions((int) $selectedUser['id'])
                : $roleDefaults;
        }

        render('drepturi_acces/index.php', [
            'pageTitle'     => 'Drepturi de acces',
            'currentPage'   => 'drepturi_acces',
            'sections'      => $this->registry->sections(),
            'modules'       => $this->registry->modules(),
            'users'         => $users,
            'selectedUser'  => $selectedUser,
            'granted'       => $granted,
            'roleDefaults'  => $roleDefaults,
            'isConfigured'  => $isConfigured,
            'templates'     => $this->templatesWithSeed(),
            'deprecated'    => $deprecated,
        ]);
    }

    private function saveAction(): void
    {
        $this->requirePost();
        $this->requireCsrf();

        $userId = (int) ($_POST['user_id'] ?? 0);
        $user = $userId > 0 ? $this->model->findUser($userId) : null;
        if ($user === null) {
            $this->fail('Utilizatorul selectat este invalid.', 422, $userId);
        }
        if ((string) $user['rol'] === 'admin') {
            $this->fail('Adminul are acces total; drepturile lui nu se configurează.', 422, $userId);
        }

        $rejected = [];
        $granted = $this->collectGranted($_POST['perm'] ?? [], $rejected);
        if ($rejected !== []) {
            // Cheie inexistenta sau rezervata adminului: cererea a fost modificata manual.
            error_log('[AccessRightsController][save] chei respinse pentru user ' . $userId . ': ' . implode(', ', $rejected));
            $this->fail('Cererea conține permisiuni inexistente sau rezervate administratorului: ' . implode(', ', array_slice($rejected, 0, 5)), 422, $userId);
        }

        try {
            $this->model->saveUserPermissions($userId, $granted, $this->currentUserId(), $this->managedPermissions());
        } catch (Throwable $exception) {
            error_log('[AccessRightsController][save] ' . $exception->getMessage());
            $this->fail('Nu am putut salva drepturile. Nicio modificare nu a fost aplicată.', 500, $userId);
        }

        $this->succeed('Drepturile au fost salvate.', $userId, [
            'granted'    => $this->model->getUserPermissions($userId),
            'configured' => true,
        ]);
    }

    /** Aplicarea directa (fara formular) a unui sablon — pastrata pentru compatibilitate. */
    private function applyTemplateAction(): void
    {
        $this->requirePost();
        $this->requireCsrf();

        $userId = (int) ($_POST['user_id'] ?? 0);
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $user = $userId > 0 ? $this->model->findUser($userId) : null;
        if ($user === null || $templateId <= 0 || (string) $user['rol'] === 'admin') {
            $this->fail('Selectează un utilizator (non-admin) și un șablon valide.', 422, $userId);
        }

        try {
            $ignored = [];
            $granted = $this->collectGranted($this->toCheckboxShape($this->model->getTemplatePermissions($templateId)), $ignored);
            $this->model->saveUserPermissions($userId, $granted, $this->currentUserId(), $this->managedPermissions());
        } catch (Throwable $exception) {
            error_log('[AccessRightsController][apply_template] ' . $exception->getMessage());
            $this->fail('Nu am putut aplica șablonul.', 500, $userId);
        }

        $this->succeed('Șablonul a fost aplicat utilizatorului.', $userId);
    }

    private function saveTemplateAction(): void
    {
        $this->requirePost();
        $this->requireCsrf();

        $name = trim((string) ($_POST['template_name'] ?? ''));
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($name === '' || mb_strlen($name) > 80) {
            $this->fail('Dă șablonului un nume (maximum 80 de caractere).', 422, $userId);
        }

        $rejected = [];
        $granted = $this->collectGranted($_POST['perm'] ?? [], $rejected);
        if ($rejected !== []) {
            $this->fail('Cererea conține permisiuni inexistente sau rezervate administratorului.', 422, $userId);
        }

        try {
            $savedId = $this->model->saveTemplate($name, $granted, $templateId > 0 ? $templateId : null);
        } catch (Throwable $exception) {
            error_log('[AccessRightsController][save_template] ' . $exception->getMessage());
            $this->fail('Nu am putut salva șablonul (poate există deja unul cu același nume).', 500, $userId);
        }

        $this->succeed('Șablonul „' . $name . '” a fost salvat.', $userId, [
            'template' => ['id' => $savedId, 'name' => $name, 'created' => $templateId <= 0],
        ]);
    }

    private function deleteTemplateAction(): void
    {
        $this->requirePost();
        $this->requireCsrf();

        $templateId = (int) ($_POST['template_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($templateId > 0) {
            try {
                $this->model->deleteTemplate($templateId);
            } catch (Throwable $exception) {
                error_log('[AccessRightsController][delete_template] ' . $exception->getMessage());
                $this->fail('Nu am putut șterge șablonul.', 500, $userId);
            }
        }

        $this->succeed('Șablonul a fost șters.', $userId);
    }

    /** Readuce utilizatorul la drepturile rolului. Rolul si sabloanele raman neatinse. */
    private function resetUserAction(): void
    {
        $this->requirePost();
        $this->requireCsrf();

        $userId = (int) ($_POST['user_id'] ?? 0);
        $user = $userId > 0 ? $this->model->findUser($userId) : null;
        if ($user === null) {
            $this->fail('Utilizatorul selectat este invalid.', 422, $userId);
        }

        try {
            $this->model->resetUser($userId);
        } catch (Throwable $exception) {
            error_log('[AccessRightsController][reset_user] ' . $exception->getMessage());
            $this->fail('Nu am putut reseta utilizatorul.', 500, $userId);
        }

        $this->succeed('Utilizatorul a revenit la drepturile rolului său.', $userId, [
            'granted'    => access_role_defaults((string) $user['rol']),
            'configured' => false,
        ]);
    }

    /**
     * Transforma input-ul ?perm[modul][actiune]=1 in [modul => [actiune, ...]], validand
     * FIECARE cheie contra registrului. Cheile necunoscute si cele 'admin_only' ajung in
     * $rejected — nu se acorda niciodata, indiferent ce trimite browserul.
     *
     * Actiunile unei pagini oprite se pastreaza (can() le ignora cat timp pagina e oprita),
     * ca reactivarea paginii sa readuca configuratia anterioara.
     *
     * @param list<string> $rejected
     * @return array<string,array<int,string>>
     */
    private function collectGranted(mixed $perm, array &$rejected): array
    {
        $granted = [];
        foreach (is_array($perm) ? $perm : [] as $pageKey => $actions) {
            $pageKey = (string) $pageKey;
            $module = $this->registry->module($pageKey);
            if ($module === null || !is_array($actions)) {
                $rejected[] = $pageKey;
                continue;
            }
            foreach ($actions as $actionKey => $value) {
                $actionKey = (string) $actionKey;
                $meta = $module['actions'][$actionKey] ?? null;
                if ($meta === null || $meta['admin_only']) {
                    $rejected[] = PermissionRegistry::permissionKey($pageKey, $actionKey);
                    continue;
                }
                if ((string) $value === '1') {
                    $granted[$pageKey][] = $actionKey;
                }
            }
        }

        return $granted;
    }

    /**
     * Permisiunile pe care formularul le gestioneaza (active in registru, fara 'admin_only').
     * Tot ce e in afara lor ramane neatins in access_permissions la salvare.
     *
     * @return array<string,array<string,bool>>
     */
    private function managedPermissions(): array
    {
        $managed = [];
        foreach ($this->registry->modules() as $moduleKey => $module) {
            foreach ($module['actions'] as $actionKey => $action) {
                if (!$action['admin_only']) {
                    $managed[$moduleKey][$actionKey] = true;
                }
            }
        }

        return $managed;
    }

    /** @param array<string,array<string,bool>> $granted */
    private function toCheckboxShape(array $granted): array
    {
        $shape = [];
        foreach ($granted as $pageKey => $actions) {
            foreach ($actions as $actionKey => $_) {
                $shape[$pageKey][$actionKey] = '1';
            }
        }

        return $shape;
    }

    /**
     * Sabloanele, fiecare cu permisiunile lui (o singura interogare pentru toate).
     * Daca nu exista niciunul, se creeaza cele de sistem (Operator, Contabil) din
     * drepturile implicite ale rolurilor.
     *
     * @return list<array<string,mixed>>
     */
    private function templatesWithSeed(): array
    {
        try {
            $templates = $this->model->listTemplates();
            if ($templates === []) {
                $this->model->ensureSystemTemplate('Operator', 'operator', $this->flatten(access_role_defaults('utilizator')));
                $this->model->ensureSystemTemplate('Contabil', 'contabil', $this->flatten(access_role_defaults('contabilitate')));
                $templates = $this->model->listTemplates();
            }
            $perms = $this->model->getAllTemplatePermissions();
            foreach ($templates as &$template) {
                $template['perms'] = $perms[(int) $template['id']] ?? [];
            }
            unset($template);

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

    // ------------------------------------------------------------- raspunsuri

    private function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect($this->indexUrl());
        }
    }

    private function requireCsrf(): void
    {
        if (access_request_wants_json()) {
            if (!verify_csrf_token($_POST['_token'] ?? null)) {
                $this->json(['success' => false, 'message' => 'Sesiunea formularului a expirat. Reîncarcă pagina.'], 403);
            }
            return;
        }
        ensure_csrf_or_redirect($this->indexUrl((int) ($_POST['user_id'] ?? 0)));
    }

    /** @param array<string,mixed> $extra */
    private function succeed(string $message, int $userId, array $extra = []): never
    {
        if (access_request_wants_json()) {
            $this->json(['success' => true, 'message' => $message] + $extra);
        }
        flash_set('success', $message);
        redirect($this->indexUrl($userId));
        exit;
    }

    private function fail(string $message, int $status, int $userId): never
    {
        if (access_request_wants_json()) {
            $this->json(['success' => false, 'message' => $message], $status);
        }
        flash_set($status >= 500 ? 'danger' : 'warning', $message);
        redirect($this->indexUrl($userId));
        exit;
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
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
