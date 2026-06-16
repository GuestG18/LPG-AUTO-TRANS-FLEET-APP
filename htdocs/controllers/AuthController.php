<?php
declare(strict_types=1);

class AuthController
{
    private UserModel $userModel;
    private LoginEmailCodeModel $loginEmailCodeModel;
    private EmailService $emailService;
    private ?array $lastLoginDeliveryResult = null;

    private const VERIFY_SESSION_KEY = '_auth_login_verification';
    private const VERIFY_CODE_TTL_SECONDS = 600;
    private const VERIFY_CODE_RESEND_COOLDOWN_SECONDS = 30;
    private const VERIFY_MAX_ATTEMPTS = 5;

    public function __construct(PDO $db)
    {
        $this->userModel = new UserModel($db);
        $this->loginEmailCodeModel = new LoginEmailCodeModel($db);
        $this->emailService = new EmailService($db);
    }

    public function index(): void
    {
        render('auth/login.php', [
            'pageTitle' => 'Autentificare',
            'showSidebar' => false,
            'currentPage' => '',
        ]);
    }

    public function autentificare(): void
    {
        ensure_csrf_or_redirect($this->loginPageUrl());

        $email = trim((string) ($_POST['email'] ?? ''));
        $parola = (string) ($_POST['parola'] ?? '');

        if ($email === '' || $parola === '') {
            flash_set('danger', 'Completeaza emailul si parola.');
            redirect($this->loginPageUrl());
        }

        $user = $this->userModel->findAuthUserByEmail($email);
        if (!$user || !password_verify($parola, (string) ($user['parola'] ?? ''))) {
            flash_set('danger', 'Date de autentificare invalide.');
            redirect($this->loginPageUrl());
        }

        if ((string) ($user['status'] ?? 'inactiv') !== 'activ') {
            flash_set('warning', 'Contul este inactiv. Contacteaza administratorul.');
            redirect($this->loginPageUrl());
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            flash_set('danger', 'Cont invalid. Contacteaza administratorul.');
            redirect($this->loginPageUrl());
        }

        if (!$this->isEmailVerificationRequired()) {
            login_user($user);
            flash_set('success', 'Bine ai venit, ' . (string) ($user['nume'] ?? 'utilizator') . '!');
            redirect(url('index.php?page=dashboard'));
        }

        $this->clearPendingVerification();
        $this->loginEmailCodeModel->invalidateActiveCodesForUser($userId);

        $pending = $this->createVerificationCodeForUser($user);
        if ($pending === null) {
            flash_set('danger', $this->formatDeliveryFailureMessage($this->lastLoginDeliveryResult));
            redirect($this->loginPageUrl());
        }

        $this->savePendingVerification($pending);
        $this->flashDeliveryStatus($pending);
        redirect($this->verifyPageUrl());
    }

    public function verifyCodePage(): void
    {
        $pending = $this->getPendingVerification();
        if ($pending === null) {
            flash_set('warning', 'Sesiunea de verificare nu exista sau a expirat. Autentifica-te din nou.');
            redirect($this->loginPageUrl());
        }

        $codeRow = $this->getActivePendingCode($pending);
        if ($codeRow === null) {
            $this->clearPendingVerification();
            flash_set('danger', 'Cod invalid sau expirat.');
            redirect($this->loginPageUrl());
        }

        if ($this->isCodeExpired($codeRow)) {
            $this->loginEmailCodeModel->markCodeUsed((int) $codeRow['id']);
            $this->clearPendingVerification();
            flash_set('danger', 'Cod invalid sau expirat.');
            redirect($this->loginPageUrl());
        }

        $attempts = (int) ($codeRow['attempts'] ?? 0);
        $maxAttempts = max(1, (int) ($codeRow['max_attempts'] ?? self::VERIFY_MAX_ATTEMPTS));
        if ($attempts >= $maxAttempts) {
            $this->loginEmailCodeModel->markCodeUsed((int) $codeRow['id']);
            $this->clearPendingVerification();
            flash_set('danger', 'Cod invalid sau expirat.');
            redirect($this->loginPageUrl());
        }

        render('auth/verify_code.php', [
            'pageTitle' => 'Verificare email',
            'showSidebar' => false,
            'currentPage' => '',
            'emailMasked' => $this->maskEmail((string) ($pending['email'] ?? '')),
            'expiresInSeconds' => max(0, strtotime((string) $codeRow['expires_at']) - time()),
            'resendWaitSeconds' => $this->secondsUntilResend($codeRow),
            'deliverySent' => (bool) ($pending['delivery_sent'] ?? true),
            'deliveryProvider' => (string) ($pending['delivery_provider'] ?? 'smtp'),
            'deliveryError' => (string) ($pending['delivery_error'] ?? ''),
            'deliveryWarnings' => (array) ($pending['delivery_warnings'] ?? []),
            'localFallbackCode' => (string) ($pending['local_fallback_code'] ?? ''),
            'deliveryLogId' => (int) ($pending['delivery_log_id'] ?? 0),
        ]);
    }

    public function verificaCod(): void
    {
        ensure_csrf_or_redirect($this->verifyPageUrl());

        $pending = $this->getPendingVerification();
        if ($pending === null) {
            flash_set('warning', 'Sesiunea de verificare nu exista sau a expirat. Autentifica-te din nou.');
            redirect($this->loginPageUrl());
        }

        $codeRow = $this->getActivePendingCode($pending);
        if ($codeRow === null) {
            $this->clearPendingVerification();
            flash_set('danger', 'Cod invalid sau expirat.');
            redirect($this->loginPageUrl());
        }

        if ($this->isCodeExpired($codeRow)) {
            $this->loginEmailCodeModel->markCodeUsed((int) $codeRow['id']);
            $this->clearPendingVerification();
            flash_set('danger', 'Cod invalid sau expirat.');
            redirect($this->loginPageUrl());
        }

        $attempts = (int) ($codeRow['attempts'] ?? 0);
        $maxAttempts = max(1, (int) ($codeRow['max_attempts'] ?? self::VERIFY_MAX_ATTEMPTS));
        if ($attempts >= $maxAttempts) {
            $this->loginEmailCodeModel->markCodeUsed((int) $codeRow['id']);
            $this->clearPendingVerification();
            flash_set('danger', 'Cod invalid sau expirat.');
            redirect($this->loginPageUrl());
        }

        $code = trim((string) ($_POST['cod_verificare'] ?? ''));
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            flash_set('danger', 'Cod invalid sau expirat.');
            redirect($this->verifyPageUrl());
        }

        if (!password_verify($code, (string) ($codeRow['code_hash'] ?? ''))) {
            $attempts = $this->loginEmailCodeModel->incrementAttempts((int) $codeRow['id']);
            $attemptsLeft = $maxAttempts - $attempts;

            if ($attemptsLeft <= 0) {
                $this->loginEmailCodeModel->markCodeUsed((int) $codeRow['id']);
                $this->clearPendingVerification();
                flash_set('danger', 'Cod invalid sau expirat.');
                redirect($this->loginPageUrl());
            }

            flash_set('danger', 'Cod invalid sau expirat.');
            redirect($this->verifyPageUrl());
        }

        $this->loginEmailCodeModel->markCodeUsed((int) $codeRow['id']);

        $user = $this->userModel->findAuthUserById((int) $pending['user_id']);
        if ($user === null || (string) ($user['status'] ?? 'inactiv') !== 'activ') {
            $this->clearPendingVerification();
            flash_set('warning', 'Contul nu mai este activ. Contacteaza administratorul.');
            redirect($this->loginPageUrl());
        }

        $this->clearPendingVerification();
        login_user($user);
        flash_set('success', 'Cod verificat cu succes.');
        redirect(url('index.php?page=dashboard'));
    }

    public function retrimiteCod(): void
    {
        ensure_csrf_or_redirect($this->verifyPageUrl());

        $pending = $this->getPendingVerification();
        if ($pending === null) {
            flash_set('warning', 'Sesiunea de verificare nu exista sau a expirat. Autentifica-te din nou.');
            redirect($this->loginPageUrl());
        }

        $user = $this->userModel->findAuthUserById((int) $pending['user_id']);
        if ($user === null || (string) ($user['status'] ?? 'inactiv') !== 'activ') {
            $this->clearPendingVerification();
            flash_set('warning', 'Contul nu mai este activ. Contacteaza administratorul.');
            redirect($this->loginPageUrl());
        }

        $currentCode = $this->getActivePendingCode($pending);
        if ($currentCode !== null && !$this->isCodeExpired($currentCode)) {
            $wait = $this->secondsUntilResend($currentCode);
            if ($wait > 0) {
                flash_set('warning', 'Poti retrimite codul peste ' . $wait . ' secunde.');
                redirect($this->verifyPageUrl());
            }
        }

        $newPending = $this->createVerificationCodeForUser($user);
        if ($newPending === null) {
            flash_set('danger', $this->formatDeliveryFailureMessage($this->lastLoginDeliveryResult));
            redirect($this->verifyPageUrl());
        }

        if ($currentCode !== null) {
            $this->loginEmailCodeModel->markCodeUsed((int) $currentCode['id']);
        }

        $this->savePendingVerification($newPending);
        $this->flashDeliveryStatus($newPending, true);
        redirect($this->verifyPageUrl());
    }

    public function logout(): void
    {
        $this->clearPendingVerification();
        logout_user();
        flash_set('info', 'Ai fost deconectat cu succes.');
        redirect($this->loginPageUrl());
    }

    private function loginPageUrl(): string
    {
        return url('index.php?page=login');
    }

    private function verifyPageUrl(): string
    {
        return url('index.php?page=login&action=verify');
    }

    private function isEmailVerificationRequired(): bool
    {
        return !defined('AUTH_REQUIRE_EMAIL_VERIFICATION') || (bool) AUTH_REQUIRE_EMAIL_VERIFICATION;
    }

    private function getPendingVerification(): ?array
    {
        $raw = $_SESSION[self::VERIFY_SESSION_KEY] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        $userId = (int) ($raw['user_id'] ?? 0);
        $email = trim((string) ($raw['email'] ?? ''));
        $name = trim((string) ($raw['nume'] ?? ''));
        $codeId = (int) ($raw['code_id'] ?? 0);

        if ($userId <= 0 || $email === '' || $codeId <= 0) {
            return null;
        }

        return [
            'user_id' => $userId,
            'email' => $email,
            'nume' => $name,
            'code_id' => $codeId,
            'delivery_sent' => (bool) ($raw['delivery_sent'] ?? true),
            'delivery_provider' => trim((string) ($raw['delivery_provider'] ?? 'smtp')),
            'delivery_error' => trim((string) ($raw['delivery_error'] ?? '')),
            'delivery_warnings' => array_values(array_filter(array_map('strval', (array) ($raw['delivery_warnings'] ?? [])))),
            'delivery_log_id' => (int) ($raw['delivery_log_id'] ?? 0),
            'local_fallback_code' => $this->canExposeEmailFailureCode()
                ? trim((string) ($raw['local_fallback_code'] ?? ''))
                : '',
        ];
    }

    private function savePendingVerification(array $pending): void
    {
        $_SESSION[self::VERIFY_SESSION_KEY] = $pending;
    }

    private function clearPendingVerification(): void
    {
        unset($_SESSION[self::VERIFY_SESSION_KEY]);
    }

    private function createVerificationCodeForUser(array $user): ?array
    {
        $userId = (int) ($user['id'] ?? 0);
        $email = trim((string) ($user['email'] ?? ''));
        $name = (string) ($user['nume'] ?? '');
        if ($userId <= 0 || $email === '') {
            return null;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        if ($codeHash === false) {
            return null;
        }

        $codeId = $this->loginEmailCodeModel->createCode(
            $userId,
            $email,
            $codeHash,
            self::VERIFY_CODE_TTL_SECONDS,
            self::VERIFY_MAX_ATTEMPTS
        );

        $delivery = $this->sendLoginVerificationCodeEmail($email, $name, $code, $userId);
        $this->lastLoginDeliveryResult = $delivery;
        $sent = (bool) ($delivery['sent'] ?? false);
        $localFallbackCode = '';
        if (!$sent && $this->canExposeEmailFailureCode()) {
            $localFallbackCode = $code;
        }

        if (!$sent && $localFallbackCode === '') {
            $this->loginEmailCodeModel->markCodeUsed($codeId);
            return null;
        }

        $this->loginEmailCodeModel->invalidateOtherActiveCodesForUser($userId, $codeId);

        return [
            'user_id' => $userId,
            'email' => $email,
            'nume' => $name,
            'code_id' => $codeId,
            'delivery_sent' => $sent,
            'delivery_provider' => (string) ($delivery['provider'] ?? 'smtp'),
            'delivery_error' => (string) ($delivery['error'] ?? ''),
            'delivery_warnings' => array_values(array_filter(array_map(
                'strval',
                (array) ($delivery['diagnostics']['warnings'] ?? [])
            ))),
            'delivery_log_id' => (int) ($delivery['log_id'] ?? 0),
            'local_fallback_code' => $localFallbackCode,
        ];
    }

    private function getActivePendingCode(array $pending): ?array
    {
        $userId = (int) ($pending['user_id'] ?? 0);
        $codeId = (int) ($pending['code_id'] ?? 0);
        if ($userId <= 0 || $codeId <= 0) {
            return null;
        }

        $row = $this->loginEmailCodeModel->findCodeByIdForUser($codeId, $userId);
        if ($row === null) {
            return null;
        }

        if (($row['used_at'] ?? null) !== null && trim((string) $row['used_at']) !== '') {
            return null;
        }

        return $row;
    }

    private function isCodeExpired(array $codeRow): bool
    {
        $expiresAt = strtotime((string) ($codeRow['expires_at'] ?? ''));
        if ($expiresAt === false) {
            return true;
        }

        return $expiresAt < time();
    }

    private function secondsUntilResend(array $codeRow): int
    {
        $sentAt = strtotime((string) ($codeRow['sent_at'] ?? ''));
        if ($sentAt === false) {
            return 0;
        }

        return max(0, ($sentAt + $this->resendCooldownSeconds()) - time());
    }

    private function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return $email;
        }

        [$localPart, $domainPart] = explode('@', $email, 2);
        $localLength = strlen($localPart);
        if ($localLength <= 2) {
            $maskedLocal = str_repeat('*', max(1, $localLength));
        } else {
            $maskedLocal = substr($localPart, 0, 2) . str_repeat('*', $localLength - 2);
        }

        return $maskedLocal . '@' . $domainPart;
    }

    private function sendLoginVerificationCodeEmail(string $toEmail, string $toName, string $code, int $userId): array
    {
        return $this->emailService->sendLoginVerificationCode(
            $toEmail,
            $toName,
            $code,
            self::VERIFY_CODE_TTL_SECONDS,
            $userId
        );
    }

    private function resendCooldownSeconds(): int
    {
        if (defined('AUTH_VERIFY_RESEND_COOLDOWN_SECONDS')) {
            return max(15, (int) AUTH_VERIFY_RESEND_COOLDOWN_SECONDS);
        }

        return self::VERIFY_CODE_RESEND_COOLDOWN_SECONDS;
    }

    private function canExposeEmailFailureCode(): bool
    {
        if (!defined('AUTH_SHOW_LOGIN_CODE_ON_EMAIL_FAILURE') || !(bool) AUTH_SHOW_LOGIN_CODE_ON_EMAIL_FAILURE) {
            return false;
        }

        $env = strtolower(trim((string) (defined('APP_ENV') ? APP_ENV : 'development')));
        return !in_array($env, ['production', 'prod'], true);
    }

    private function flashDeliveryStatus(array $pending, bool $resent = false): void
    {
        if ((bool) ($pending['delivery_sent'] ?? true)) {
            flash_set('info', $resent
                ? 'Codul de verificare a fost retrimis pe adresa ta de email.'
                : 'Codul de verificare a fost trimis pe adresa ta de email.'
            );
            return;
        }

        if ((string) ($pending['local_fallback_code'] ?? '') !== '') {
            flash_set('warning', 'Emailul nu a putut fi trimis. Pentru modul local, codul este afisat pe pagina de verificare.');
            return;
        }

        flash_set('danger', $this->formatDeliveryFailureMessage($pending));
    }

    private function formatDeliveryFailureMessage(?array $delivery): string
    {
        $message = 'Nu am putut trimite codul de verificare pe email. Verifica setarile SMTP si incearca din nou.';
        $provider = trim((string) ($delivery['provider'] ?? $delivery['delivery_provider'] ?? ''));
        $error = trim((string) ($delivery['error'] ?? $delivery['delivery_error'] ?? ''));

        if ($provider !== '' || $error !== '') {
            $details = trim(($provider !== '' ? 'Furnizor: ' . $provider . '. ' : '') . ($error !== '' ? 'Eroare: ' . $error : ''));
            if ($details !== '') {
                $message .= ' ' . $details;
            }
        }

        return $message;
    }
}
