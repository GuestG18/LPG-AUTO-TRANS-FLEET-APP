<?php
declare(strict_types=1);

/**
 * Stratul de autorizare per-pagina / per-actiune.
 *
 * Reguli:
 *   - Adminul are ACCES la tot (bypass total).
 *   - Actiunile 'admin_only' raman DOAR ale adminului: nu pot fi acordate altcuiva.
 *   - Un utilizator neconfigurat mosteneste accesul implicit al rolului sau
 *     (scope-ul modulului + flag-urile default_admin / default_accountancy).
 *   - Un utilizator configurat este guvernat STRICT de drepturile salvate; o actiune
 *     cere in plus accesul la pagina ('view') — pagina oprita blocheaza tot modulul.
 *
 * Sursa metadatelor: PermissionRegistry (permissions/modules/*.php).
 * Depinde de: models/AccessRightsModel.php, get_pdo() si helper-ele din includes/auth.php.
 */

require_once __DIR__ . '/../services/PermissionRegistry.php';

function permission_registry(): PermissionRegistry
{
    return PermissionRegistry::instance();
}

/**
 * Catalogul in forma istorica ['groups' => ..., 'pages' => ...], construit din registru.
 */
function permission_catalog(): array
{
    return permission_registry()->legacyCatalog();
}

/** @return array<string,array<string,mixed>> */
function permission_pages(): array
{
    return permission_catalog()['pages'];
}

/** @return array<string,array<string,mixed>> */
function permission_groups(): array
{
    return permission_catalog()['groups'];
}

/**
 * Mapeaza o ruta reala ?page=... pe (prima) cheie de modul corespunzatoare.
 * Intoarce null daca ruta nu este guvernata (pagina publica sau nemapata -> fail-open).
 */
function route_to_permission_key(string $routePage): ?string
{
    return permission_registry()->modulesForRoute($routePage)[0] ?? null;
}

function permission_page_scope(string $pageKey): string
{
    return (string) (permission_registry()->module($pageKey)['scope'] ?? 'all');
}

/**
 * Permite rolul, implicit (fara configurare explicita), pagina / actiunea data?
 *
 * @param array<string,mixed>      $module
 * @param array<string,mixed>|null $action null = actiune nedeclarata (conteaza doar pagina)
 */
function access_role_allows(string $role, array $module, ?array $action): bool
{
    $role = strtolower(trim($role));
    if ($role === 'admin') {
        return true;
    }
    if (is_array($action) && ($action['admin_only'] ?? false) === true) {
        return false;
    }
    $isAccountancy = $role === 'contabilitate';

    $pageAllowed = match ((string) ($module['scope'] ?? 'all')) {
        'admin'       => false,
        'accountancy' => $isAccountancy,
        default       => true,
    };
    if (!$pageAllowed || $action === null) {
        return $pageAllowed;
    }
    if (($action['default_admin'] ?? false) === true) {
        return false;
    }
    if (($action['default_accountancy'] ?? false) === true) {
        return $isAccountancy;
    }

    return true;
}

/**
 * Drepturile implicite ale unui rol — ce mosteneste un utilizator neconfigurat
 * si fata de ce se calculeaza "personalizarile" in Drepturi de acces.
 *
 * @return array<string,array<string,bool>> [module][action] => true
 */
function access_role_defaults(string $role): array
{
    $granted = [];
    foreach (permission_registry()->modules() as $moduleKey => $module) {
        foreach ($module['actions'] as $actionKey => $action) {
            if (access_role_allows($role, $module, $action)) {
                $granted[$moduleKey][$actionKey] = true;
            }
        }
    }

    return $granted;
}

/**
 * Regula unica de decizie, folosita de can() (sesiune) si user_can() (server-to-server).
 *
 * @param array<string,array<string,bool>> $perms drepturile salvate ale utilizatorului
 */
function access_evaluate(string $role, bool $configured, array $perms, string $pageKey, string $action = 'view'): bool
{
    if (strtolower(trim($role)) === 'admin') {
        return true;
    }

    $registry = permission_registry();
    $module = $registry->module($pageKey);
    $meta = $module['actions'][$action] ?? null;
    if (is_array($meta) && $meta['admin_only']) {
        return false; // nu se poate acorda prin drepturi, nici macar din BD
    }

    if (!$configured) {
        // Neconfigurat -> accesul implicit al rolului. Modul nedeclarat -> fail-open (legacy).
        return $module === null ? true : access_role_allows($role, $module, $meta);
    }

    if ($action !== PermissionRegistry::VIEW && !isset($perms[$pageKey][PermissionRegistry::VIEW])) {
        return false; // pagina oprita -> actiunile ei sunt inactive (dar raman salvate)
    }

    return isset($perms[$pageKey][$action]);
}

/**
 * Starea de acces a utilizatorului curent (cache per-request).
 *
 * @return array{configured:bool,perms:array<string,array<string,bool>>}
 */
function user_access_state(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $default = ['configured' => false, 'perms' => []];
    $user = function_exists('current_user') ? current_user() : null;
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        return $cache = $default;
    }

    try {
        $model = new AccessRightsModel(get_pdo());
        $perms = $model->getUserPermissions($userId);
        $configured = $perms !== [] ? true : $model->isConfigured($userId);
        $cache = ['configured' => $configured, 'perms' => $perms];
    } catch (Throwable $exception) {
        error_log('[access] user_access_state: ' . $exception->getMessage());
        $cache = $default;
    }

    return $cache;
}

/**
 * Poate utilizatorul curent sa acceseze pagina $pageKey (implicit actiunea 'view'),
 * respectiv o anumita actiune de pe acea pagina?
 */
function can(string $pageKey, string $action = 'view'): bool
{
    if (function_exists('is_admin') && is_admin()) {
        return true;
    }
    if (!is_logged_in()) {
        return false;
    }

    $state = user_access_state();

    return access_evaluate((string) ($_SESSION['auth_user']['rol'] ?? ''), $state['configured'], $state['perms'], $pageKey, $action);
}

/**
 * Aceleasi reguli ca can(), dar pentru un utilizator dat explicit (fara sesiune).
 * Folosit de apelurile server-to-server (ex. Fleet Assistant), unde nu exista
 * utilizator logat: rolul si drepturile se citesc din utilizatori / access_*.
 *
 * @param array{id:int|string,rol?:string,status?:string} $user
 */
function user_can(array $user, string $pageKey, string $action = 'view'): bool
{
    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0 || (string) ($user['status'] ?? 'inactiv') !== 'activ') {
        return false;
    }

    $role = (string) ($user['rol'] ?? '');
    if ($role === 'admin') {
        return true;
    }

    $model = new AccessRightsModel(get_pdo());
    $perms = $model->getUserPermissions($userId);
    $configured = $perms !== [] ? true : $model->isConfigured($userId);

    return access_evaluate($role, $configured, $perms, $pageKey, $action);
}

/** Varianta care primeste direct ruta ?page=... */
function can_route(string $routePage, string $action = 'view'): bool
{
    $keys = permission_registry()->modulesForRoute($routePage);
    if ($keys === []) {
        return true; // ruta nemapata -> fail-open
    }
    foreach ($keys as $key) {
        if (can($key, $action)) {
            return true;
        }
    }

    return false;
}

/**
 * Garda centrala pe router: 403 daca utilizatorul nu are acces la ruta sau la
 * actiunea ceruta. Endpoint-urile (?action=...) declarate de modul in 'endpoints'
 * cer permisiunea corespunzatoare — autorizare reala, nu doar butoane ascunse.
 * O ruta comuna mai multor module trece daca cel putin unul o permite.
 */
function require_route_access(string $routePage, ?string $routeAction = null): void
{
    $registry = permission_registry();
    $keys = $registry->modulesForRoute($routePage);
    if ($keys === []) {
        return; // pagina publica / nemapata
    }

    foreach ($keys as $key) {
        if (!can($key, PermissionRegistry::VIEW)) {
            continue;
        }
        $required = $routeAction !== null ? $registry->endpointAction($key, $routeAction) : null;
        if ($required === null || can($key, $required)) {
            return;
        }
    }

    access_deny_403();
}

/**
 * Garda la nivel de controller, primind direct cheia de catalog.
 */
function require_page_or_403(string $pageKey): void
{
    if (can($pageKey, 'view')) {
        return;
    }

    access_deny_403();
}

function access_request_wants_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function access_deny_403(): void
{
    http_response_code(403);
    if (access_request_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'ok'      => false,
            'message' => 'Nu ai dreptul să efectuezi această acțiune.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    render('errors/403.php', [
        'pageTitle' => 'Acces interzis',
        'currentPage' => '',
    ]);
    exit;
}
