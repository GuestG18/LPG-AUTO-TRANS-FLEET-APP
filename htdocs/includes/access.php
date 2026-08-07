<?php
declare(strict_types=1);

/**
 * Stratul de autorizare per-pagina / per-actiune.
 *
 * Reguli:
 *   - Adminul are ACCES la tot (bypass total).
 *   - Un utilizator neconfigurat foloseste comportamentul legacy bazat pe rol
 *     (scope-ul din config/permissions.php), deci nimic nu se schimba pentru el.
 *   - Un utilizator configurat este guvernat STRICT de drepturile salvate.
 *
 * Depinde de: config/permissions.php, models/AccessRightsModel.php, get_pdo(),
 * si helper-ele din includes/auth.php (is_admin, is_logged_in, is_accountancy_user, current_user).
 */

function permission_catalog(): array
{
    static $catalog = null;
    if ($catalog === null) {
        $file = __DIR__ . '/../config/permissions.php';
        $catalog = is_file($file) ? (require $file) : ['groups' => [], 'pages' => []];
        if (!is_array($catalog)) {
            $catalog = ['groups' => [], 'pages' => []];
        }
    }

    return $catalog;
}

/** @return array<string,array<string,mixed>> */
function permission_pages(): array
{
    return permission_catalog()['pages'] ?? [];
}

/** @return array<string,array<string,mixed>> */
function permission_groups(): array
{
    return permission_catalog()['groups'] ?? [];
}

/**
 * Mapeaza o ruta reala ?page=... pe cheia de catalog corespunzatoare.
 * Intoarce null daca ruta nu este guvernata (pagina publica sau nemapata -> fail-open).
 */
function route_to_permission_key(string $routePage): ?string
{
    static $routeMap = null;
    if ($routeMap === null) {
        $routeMap = [];
        foreach (permission_pages() as $key => $page) {
            $routeMap[$key] = $key;
            foreach ((array) ($page['routes'] ?? []) as $route) {
                $routeMap[(string) $route] = $key;
            }
        }
    }

    return $routeMap[$routePage] ?? null;
}

function permission_page_scope(string $pageKey): string
{
    $page = permission_pages()[$pageKey] ?? null;

    return is_array($page) ? (string) ($page['scope'] ?? 'all') : 'all';
}

/**
 * Comportamentul implicit (legacy) pentru un utilizator inca neconfigurat.
 */
function access_legacy_allows(string $pageKey): bool
{
    switch (permission_page_scope($pageKey)) {
        case 'admin':
            return is_admin();
        case 'accountancy':
            return function_exists('is_accountancy_user') ? is_accountancy_user() : is_admin();
        case 'all':
        default:
            return is_logged_in();
    }
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
    if (!$state['configured']) {
        // Neconfigurat -> comportament legacy bazat pe rol.
        if ($action !== 'view') {
            $meta = permission_pages()[$pageKey]['actions'][$action] ?? null;
            if (is_array($meta) && ($meta['admin'] ?? false) === true) {
                return is_admin();
            }
        }

        return access_legacy_allows($pageKey);
    }

    return isset($state['perms'][$pageKey][$action]);
}

/** Varianta care primeste direct ruta ?page=... */
function can_route(string $routePage, string $action = 'view'): bool
{
    $key = route_to_permission_key($routePage);
    if ($key === null) {
        return true; // ruta nemapata -> fail-open
    }

    return can($key, $action);
}

/**
 * Garda centrala pe router: 403 daca utilizatorul nu are acces la ruta.
 */
function require_route_access(string $routePage): void
{
    $key = route_to_permission_key($routePage);
    if ($key === null) {
        return; // pagina publica / nemapata
    }
    if (can($key, 'view')) {
        return;
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

function access_deny_403(): void
{
    http_response_code(403);
    render('errors/403.php', [
        'pageTitle' => 'Acces interzis',
        'currentPage' => '',
    ]);
    exit;
}
