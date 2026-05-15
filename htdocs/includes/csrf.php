<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf_token(?string $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals(csrf_token(), $token);
}

function ensure_csrf_or_redirect(string $fallbackUrl): void
{
    if (!verify_csrf_token($_POST['_token'] ?? null)) {
        flash_set('danger', 'Token CSRF invalid. Reincearca operatiunea.');
        redirect($fallbackUrl);
    }
}
