<?php
declare(strict_types=1);

use lbuchs\WebAuthn\WebAuthn;

/**
 * Configureaza si expune biblioteca WebAuthn (lbuchs/webauthn) pentru passkeys.
 *
 * RP ID = domeniul curent fara port (ex: "localhost" in dev, "app.exemplu.ro" in
 * productie). Passkey-urile sunt legate de acest domeniu; in productie e nevoie de HTTPS.
 */
class WebAuthnService
{
    /** Domeniul (relying party id) de care sunt legate passkey-urile. */
    public static function rpId(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = (string) preg_replace('/:\d+$/', '', $host); // scoate portul
        $host = strtolower($host);

        return $host !== '' ? $host : 'localhost';
    }

    public static function rpName(): string
    {
        return defined('APP_NAME') ? (string) APP_NAME : 'Fleet Management';
    }

    /**
     * Instanta WebAuthn configurata: format "none" (fara verificare de atestare) si
     * codare base64url pentru campurile binare din JSON-ul trimis catre browser.
     */
    public static function create(): WebAuthn
    {
        return new WebAuthn(self::rpName(), self::rpId(), ['none'], true);
    }

    public static function b64urlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $value): string
    {
        $value = strtr(trim($value), '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode($value, true);
    }
}
