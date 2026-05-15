<?php
declare(strict_types=1);

class AuthController
{
    private UserModel $userModel;
    private LoginEmailCodeModel $loginEmailCodeModel;

    private const VERIFY_SESSION_KEY = '_auth_login_verification';
    private const VERIFY_CODE_TTL_SECONDS = 600;
    private const VERIFY_CODE_RESEND_COOLDOWN_SECONDS = 60;
    private const VERIFY_MAX_ATTEMPTS = 5;

    public function __construct(PDO $db)
    {
        $this->userModel = new UserModel($db);
        $this->loginEmailCodeModel = new LoginEmailCodeModel($db);
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

        $this->clearPendingVerification();
        $this->loginEmailCodeModel->invalidateActiveCodesForUser($userId);

        $pending = $this->createVerificationCodeForUser($user);
        if ($pending === null) {
            flash_set('danger', 'Nu am putut trimite codul de verificare pe email. Verifica setarile de email si incearca din nou.');
            redirect($this->loginPageUrl());
        }

        $this->savePendingVerification($pending);

        if ($this->isMailpitSendmailConfigured()) {
            flash_set('info', 'Am trimis codul de verificare in Mailpit (http://127.0.0.1:8025).');
        } else {
            flash_set('info', 'Am trimis codul de verificare pe emailul tau.');
        }
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
            flash_set('warning', 'Sesiunea de verificare nu mai este valida. Autentifica-te din nou.');
            redirect($this->loginPageUrl());
        }

        if ($this->isCodeExpired($codeRow)) {
            $this->loginEmailCodeModel->markCodeUsed((int) $codeRow['id']);
            $this->clearPendingVerification();
            flash_set('warning', 'Codul de verificare a expirat. Autentifica-te din nou.');
            redirect($this->loginPageUrl());
        }

        $attempts = (int) ($codeRow['attempts'] ?? 0);
        $maxAttempts = max(1, (int) ($codeRow['max_attempts'] ?? self::VERIFY_MAX_ATTEMPTS));
        if ($attempts >= $maxAttempts) {
            $this->loginEmailCodeModel->markCodeUsed((int) $codeRow['id']);
            $this->clearPendingVerification();
            flash_set('danger', 'Ai depasit numarul maxim de incercari. Autentifica-te din nou.');
            redirect($this->loginPageUrl());
        }

        render('auth/verify_code.php', [
            'pageTitle' => 'Verificare email',
            'showSidebar' => false,
            'currentPage' => '',
            'emailMasked' => $this->maskEmail((string) ($pending['email'] ?? '')),
            'expiresInSeconds' => max(0, strtotime((string) $codeRow['expires_at']) - time()),
            'resendWaitSeconds' => $this->secondsUntilResend($codeRow),
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
            flash_set('warning', 'Codul nu mai este valid. Autentifica-te din nou.');
            redirect($this->loginPageUrl());
        }

        if ($this->isCodeExpired($codeRow)) {
            $this->loginEmailCodeModel->markCodeUsed((int) $codeRow['id']);
            $this->clearPendingVerification();
            flash_set('warning', 'Codul de verificare a expirat. Autentifica-te din nou.');
            redirect($this->loginPageUrl());
        }

        $attempts = (int) ($codeRow['attempts'] ?? 0);
        $maxAttempts = max(1, (int) ($codeRow['max_attempts'] ?? self::VERIFY_MAX_ATTEMPTS));
        if ($attempts >= $maxAttempts) {
            $this->loginEmailCodeModel->markCodeUsed((int) $codeRow['id']);
            $this->clearPendingVerification();
            flash_set('danger', 'Ai depasit numarul maxim de incercari. Autentifica-te din nou.');
            redirect($this->loginPageUrl());
        }

        $code = trim((string) ($_POST['cod_verificare'] ?? ''));
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            flash_set('danger', 'Codul de verificare trebuie sa aiba exact 6 cifre.');
            redirect($this->verifyPageUrl());
        }

        if (!password_verify($code, (string) ($codeRow['code_hash'] ?? ''))) {
            $attempts = $this->loginEmailCodeModel->incrementAttempts((int) $codeRow['id']);
            $attemptsLeft = $maxAttempts - $attempts;

            if ($attemptsLeft <= 0) {
                $this->loginEmailCodeModel->markCodeUsed((int) $codeRow['id']);
                $this->clearPendingVerification();
                flash_set('danger', 'Ai depasit numarul maxim de incercari. Autentifica-te din nou.');
                redirect($this->loginPageUrl());
            }

            flash_set('danger', 'Cod invalid. Incercari ramase: ' . $attemptsLeft . '.');
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
        flash_set('success', 'Bine ai venit, ' . (string) ($user['nume'] ?? 'utilizator') . '!');
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
            flash_set('danger', 'Nu am putut retrimite codul pe email. Verifica setarile de email si incearca din nou.');
            redirect($this->verifyPageUrl());
        }

        if ($currentCode !== null) {
            $this->loginEmailCodeModel->markCodeUsed((int) $currentCode['id']);
        }

        $this->savePendingVerification($newPending);

        if ($this->isMailpitSendmailConfigured()) {
            flash_set('info', 'Am retrimis codul de verificare in Mailpit (http://127.0.0.1:8025).');
        } else {
            flash_set('info', 'Am retrimis codul de verificare pe email.');
        }
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

        $sent = $this->sendLoginVerificationCodeEmail($email, $name, $code);
        if (!$sent) {
            $this->loginEmailCodeModel->markCodeUsed($codeId);
            return null;
        }

        $this->loginEmailCodeModel->invalidateOtherActiveCodesForUser($userId, $codeId);

        return [
            'user_id' => $userId,
            'email' => $email,
            'nume' => $name,
            'code_id' => $codeId,
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

        return max(0, ($sentAt + self::VERIFY_CODE_RESEND_COOLDOWN_SECONDS) - time());
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

    private function sendLoginVerificationCodeEmail(string $toEmail, string $toName, string $code): bool
    {
        $toEmail = trim($toEmail);
        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $subject = 'Cod verificare autentificare - ' . APP_NAME;
        $displayName = trim($toName) !== '' ? trim($toName) : 'utilizator';
        $message = "Salut, {$displayName},\n\n";
        $message .= "Codul tau de verificare pentru autentificare este: {$code}\n";
        $message .= "Codul este valabil " . (int) (self::VERIFY_CODE_TTL_SECONDS / 60) . " minute.\n\n";
        $message .= "Daca nu ai incercat sa te autentifici, ignora acest mesaj.\n\n";
        $message .= APP_NAME;

        $primaryTransport = $this->getEmailTransport();
        if ($this->sendEmailUsingTransport($primaryTransport, $toEmail, $toName, $subject, $message)) {
            return true;
        }

        $fallbackTransport = $this->getFallbackEmailTransport();
        if ($fallbackTransport !== '' && $fallbackTransport !== $primaryTransport) {
            error_log('[AuthController][email] Primary transport failed (' . $primaryTransport . '), trying fallback ' . $fallbackTransport . '.');
            return $this->sendEmailUsingTransport($fallbackTransport, $toEmail, $toName, $subject, $message);
        }

        return false;
    }

    private function sendEmailUsingTransport(
        string $transport,
        string $toEmail,
        string $toName,
        string $subject,
        string $message
    ): bool {
        if ($transport === 'smtp') {
            return $this->sendEmailUsingSmtp($toEmail, $toName, $subject, $message);
        }

        if ($transport === 'resend') {
            return $this->sendEmailUsingResendApi($toEmail, $toName, $subject, $message);
        }

        if ($transport === 'mail') {
            return $this->sendEmailUsingPhpMail($toEmail, $subject, $message);
        }

        return false;
    }

    private function sendEmailUsingPhpMail(string $toEmail, string $subject, string $message): bool
    {
        $fromAddress = $this->getFromAddress();
        $fromName = $this->getFromName();
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromAddress . '>',
        ];

        return @mail($toEmail, $subject, $message, implode("\r\n", $headers));
    }

    private function sendEmailUsingResendApi(string $toEmail, string $toName, string $subject, string $message): bool
    {
        $apiKey = trim((string) (defined('RESEND_API_KEY') ? RESEND_API_KEY : ''));
        $baseUrl = rtrim(trim((string) (defined('RESEND_API_BASE_URL') ? RESEND_API_BASE_URL : 'https://api.resend.com')), '/');
        $timeout = max(5, (int) (defined('RESEND_TIMEOUT') ? RESEND_TIMEOUT : 15));
        $fromAddress = $this->getResendFromAddress();
        $fromName = $this->getFromName();

        if ($apiKey === '') {
            error_log('[AuthController][resend] API key is missing.');
            return false;
        }

        if ($baseUrl === '') {
            error_log('[AuthController][resend] API base URL is missing.');
            return false;
        }

        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[AuthController][resend] Sender address is invalid.');
            return false;
        }

        if (!function_exists('curl_init')) {
            error_log('[AuthController][resend] cURL extension is not available.');
            return false;
        }

        $fromHeader = trim($fromName) !== ''
            ? $fromName . ' <' . $fromAddress . '>'
            : $fromAddress;

        $payload = [
            'from' => $fromHeader,
            'to' => [$toEmail],
            'subject' => $subject,
            'text' => $message,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            error_log('[AuthController][resend] Unable to encode request payload.');
            return false;
        }

        $curl = curl_init($baseUrl . '/emails');
        if ($curl === false) {
            error_log('[AuthController][resend] Unable to initialize cURL.');
            return false;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: FleetManagementMVP/1.0',
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($curl);
        $curlErrorNo = curl_errno($curl);
        $curlError = curl_error($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false || $curlErrorNo !== 0) {
            error_log('[AuthController][resend] Request failed: #' . $curlErrorNo . ' ' . $curlError);
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $responsePreview = trim((string) $response);
            if (strlen($responsePreview) > 500) {
                $responsePreview = substr($responsePreview, 0, 500) . '...';
            }
            error_log('[AuthController][resend] API rejected request: HTTP ' . $httpCode . ' - ' . $responsePreview);
            return false;
        }

        return true;
    }

    private function sendEmailUsingSmtp(string $toEmail, string $toName, string $subject, string $message): bool
    {
        $host = trim((string) (defined('SMTP_HOST') ? SMTP_HOST : ''));
        $port = (int) (defined('SMTP_PORT') ? SMTP_PORT : 587);
        $encryption = strtolower(trim((string) (defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls')));
        $username = trim((string) (defined('SMTP_USERNAME') ? SMTP_USERNAME : ''));
        $password = (string) (defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '');
        $timeout = max(20, (int) (defined('SMTP_TIMEOUT') ? SMTP_TIMEOUT : 90));
        $allowSelfSigned = (bool) (defined('SMTP_ALLOW_SELF_SIGNED') ? SMTP_ALLOW_SELF_SIGNED : false);
        $fromAddress = $this->getFromAddress();
        $fromName = $this->getFromName();

        if ($host === '' || $port <= 0) {
            error_log('[AuthController][smtp] SMTP host/port invalid.');
            return false;
        }

        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        if ($username === '' || $password === '') {
            error_log('[AuthController][smtp] SMTP credentials are missing.');
            return false;
        }

        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[AuthController][smtp] Sender address is invalid.');
            return false;
        }

        $sslContext = [
            'verify_peer' => !$allowSelfSigned,
            'verify_peer_name' => !$allowSelfSigned,
            'allow_self_signed' => $allowSelfSigned,
        ];
        $context = stream_context_create(['ssl' => $sslContext]);

        $scheme = $encryption === 'ssl' ? 'ssl' : 'tcp';
        $target = $scheme . '://' . $host . ':' . $port;
        $connection = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($connection)) {
            error_log('[AuthController][smtp] Connection failed: ' . $errstr . ' (' . $errno . ').');
            return false;
        }

        stream_set_timeout($connection, $timeout);

        try {
            $this->smtpReadAndExpect($connection, [220], 'connect');
            $this->smtpCommandAndExpect($connection, 'EHLO ' . $this->smtpClientHostname(), [250], 'EHLO');

            if ($encryption === 'tls') {
                $this->smtpCommandAndExpect($connection, 'STARTTLS', [220], 'STARTTLS');
                $cryptoEnabled = @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($cryptoEnabled !== true) {
                    throw new RuntimeException('TLS negotiation failed.');
                }
                $this->smtpCommandAndExpect($connection, 'EHLO ' . $this->smtpClientHostname(), [250], 'EHLO after STARTTLS');
            }

            $this->smtpCommandAndExpect($connection, 'AUTH LOGIN', [334], 'AUTH LOGIN');
            $this->smtpCommandAndExpect($connection, base64_encode($username), [334], 'SMTP username');
            $this->smtpCommandAndExpect($connection, base64_encode($password), [235], 'SMTP password');
            $this->smtpCommandAndExpect($connection, 'MAIL FROM:<' . $fromAddress . '>', [250], 'MAIL FROM');
            $this->smtpCommandAndExpect($connection, 'RCPT TO:<' . $toEmail . '>', [250, 251], 'RCPT TO');
            $this->smtpCommandAndExpect($connection, 'DATA', [354], 'DATA');

            $rawEmail = $this->buildSmtpMessage($toEmail, $toName, $fromAddress, $fromName, $subject, $message);
            $this->smtpWrite($connection, $rawEmail . "\r\n.\r\n");
            $this->smtpReadAndExpect($connection, [250], 'message body');

            $this->smtpCommandAndExpect($connection, 'QUIT', [221], 'QUIT');
            fclose($connection);

            return true;
        } catch (Throwable $exception) {
            error_log('[AuthController][smtp] Send failed: ' . $exception->getMessage());
            @fwrite($connection, "QUIT\r\n");
            @fclose($connection);
            return false;
        }
    }

    private function buildSmtpMessage(
        string $toEmail,
        string $toName,
        string $fromAddress,
        string $fromName,
        string $subject,
        string $message
    ): string {
        $toHeader = trim($toName) !== ''
            ? $this->encodeEmailHeader(trim($toName)) . ' <' . $toEmail . '>'
            : $toEmail;
        $encodedFromName = $this->encodeEmailHeader($fromName);
        $encodedSubject = $this->encodeEmailHeader($subject);
        $normalizedMessage = str_replace(["\r\n", "\r"], "\n", $message);
        $normalizedMessage = str_replace("\n", "\r\n", $normalizedMessage);
        $encodedBody = rtrim(chunk_split(base64_encode($normalizedMessage), 76, "\r\n"));

        $headers = [
            'Date: ' . date('r'),
            'Message-ID: <' . uniqid('fleet-', true) . '@' . $this->smtpClientHostname() . '>',
            'From: ' . $encodedFromName . ' <' . $fromAddress . '>',
            'To: ' . $toHeader,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
        $payload = preg_replace('/(?<!\r)\n/', "\r\n", $payload);
        if ($payload === null) {
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
        }

        return preg_replace('/^\./m', '..', $payload) ?? $payload;
    }

    private function smtpCommandAndExpect($connection, string $command, array $expectedCodes, string $context): void
    {
        $this->smtpWrite($connection, $command . "\r\n");
        $this->smtpReadAndExpect($connection, $expectedCodes, $context);
    }

    private function smtpReadAndExpect($connection, array $expectedCodes, string $context): void
    {
        $response = $this->smtpReadResponse($connection);
        if (!in_array($response['code'], $expectedCodes, true)) {
            throw new RuntimeException(
                $context . ' failed. SMTP response: ' . $response['code'] . ' ' . $response['message']
            );
        }
    }

    private function smtpReadResponse($connection): array
    {
        $lines = [];
        $code = 0;
        while (($line = fgets($connection, 515)) !== false) {
            $line = rtrim($line, "\r\n");
            $lines[] = $line;

            if (preg_match('/^(\d{3})([\s-])/', $line, $matches) !== 1) {
                continue;
            }

            $code = (int) $matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        }

        if ($lines === []) {
            throw new RuntimeException('No response received from SMTP server.');
        }

        return [
            'code' => $code,
            'message' => implode(' | ', $lines),
        ];
    }

    private function smtpWrite($connection, string $payload): void
    {
        $length = strlen($payload);
        $writtenTotal = 0;

        while ($writtenTotal < $length) {
            $written = @fwrite($connection, substr($payload, $writtenTotal));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write SMTP payload.');
            }
            $writtenTotal += $written;
        }
    }

    private function encodeEmailHeader(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function smtpClientHostname(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = gethostname() ?: 'localhost';
        }
        $host = strtolower($host);
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return $host !== '' ? $host : 'localhost';
    }

    private function getEmailTransport(): string
    {
        $transport = strtolower(trim((string) (defined('AUTH_EMAIL_TRANSPORT') ? AUTH_EMAIL_TRANSPORT : 'mail')));
        return in_array($transport, ['smtp', 'mail', 'resend'], true) ? $transport : 'mail';
    }

    private function getFallbackEmailTransport(): string
    {
        $transport = strtolower(trim((string) (defined('AUTH_EMAIL_FALLBACK_TRANSPORT') ? AUTH_EMAIL_FALLBACK_TRANSPORT : '')));
        return in_array($transport, ['smtp', 'mail', 'resend'], true) ? $transport : '';
    }

    private function getFromAddress(): string
    {
        $configured = trim((string) (defined('AUTH_EMAIL_FROM') ? AUTH_EMAIL_FROM : ''));
        $smtpUser = trim((string) (defined('SMTP_USERNAME') ? SMTP_USERNAME : ''));

        if (
            $this->getEmailTransport() === 'smtp'
            && $smtpUser !== ''
            && filter_var($smtpUser, FILTER_VALIDATE_EMAIL) !== false
            && ($configured === '' || str_ends_with(strtolower($configured), '.local'))
        ) {
            return $smtpUser;
        }

        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL) !== false) {
            return $configured;
        }

        if ($smtpUser !== '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL) !== false) {
            return $smtpUser;
        }

        return 'no-reply@fleet.local';
    }

    private function getFromName(): string
    {
        $configured = trim((string) (defined('AUTH_EMAIL_FROM_NAME') ? AUTH_EMAIL_FROM_NAME : ''));
        return $configured !== '' ? $configured : APP_NAME;
    }

    private function getResendFromAddress(): string
    {
        $configured = trim((string) (defined('RESEND_FROM') ? RESEND_FROM : ''));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL) !== false) {
            return $configured;
        }

        return $this->getFromAddress();
    }

    private function isMailpitSendmailConfigured(): bool
    {
        if ($this->getEmailTransport() !== 'mail') {
            return false;
        }

        $sendmailPath = strtolower((string) ini_get('sendmail_path'));
        if ($sendmailPath === '') {
            return false;
        }

        return str_contains($sendmailPath, 'mailpit');
    }
}
