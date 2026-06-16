<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['auth_user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['auth_user']['id']);
}

function is_admin(): bool
{
    return is_logged_in() && (($_SESSION['auth_user']['rol'] ?? '') === 'admin');
}

function is_accountancy_user(): bool
{
    if (!is_logged_in()) {
        return false;
    }

    return in_array((string) ($_SESSION['auth_user']['rol'] ?? ''), ['admin', 'contabilitate'], true);
}

function login_user(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['auth_user'] = [
        'id' => (int) $user['id'],
        'nume' => $user['nume'],
        'email' => $user['email'],
        'rol' => $user['rol'],
    ];
}

function logout_user(): void
{
    unset($_SESSION['auth_user'], $_SESSION['_csrf_token']);
    session_regenerate_id(true);
}

function require_auth(): void
{
    if (!is_logged_in()) {
        flash_set('warning', 'Te rugam sa te autentifici pentru a continua.');
        redirect(url('index.php?page=login'));
    }
}

function require_admin_or_403(): void
{
    if (is_admin()) {
        return;
    }

    http_response_code(403);
    render('errors/403.php', [
        'pageTitle' => 'Acces interzis',
        'currentPage' => '',
    ]);
    exit;
}

function require_accountancy_or_403(): void
{
    if (is_accountancy_user()) {
        return;
    }

    http_response_code(403);
    render('errors/403.php', [
        'pageTitle' => 'Acces interzis',
        'currentPage' => '',
    ]);
    exit;
}
