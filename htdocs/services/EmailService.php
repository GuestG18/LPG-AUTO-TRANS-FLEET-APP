<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

class EmailService
{
    private ?NotificationDeliveryModel $deliveryLog = null;

    public function __construct(?PDO $db = null)
    {
        if ($db instanceof PDO && class_exists(NotificationDeliveryModel::class)) {
            $this->deliveryLog = new NotificationDeliveryModel($db);
        }
    }

    public function sendLoginVerificationCode(
        string $recipientEmail,
        string $recipientName,
        string $code,
        int $ttlSeconds,
        ?int $userId = null
    ): array {
        $recipientEmail = trim($recipientEmail);
        $recipientName = trim($recipientName);
        $displayName = $recipientName !== '' ? $recipientName : 'utilizator';
        $minutes = max(1, (int) ceil($ttlSeconds / 60));
        $subject = 'Cod verificare autentificare - ' . APP_NAME;

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return $this->finishDeliveryResult(
                $this->baseDeliveryResult($this->buildMailConfig(), $recipientEmail, [
                    'context' => 'auth_login_code',
                    'context_id' => $userId !== null && $userId > 0 ? 'user:' . $userId : null,
                    'metadata' => ['user_id' => $userId, 'ttl_seconds' => $ttlSeconds],
                ]),
                $recipientEmail,
                $recipientName,
                $subject,
                'Cod de verificare autentificare redactat pentru securitate.',
                [
                    'context' => 'auth_login_code',
                    'context_id' => $userId !== null && $userId > 0 ? 'user:' . $userId : null,
                    'metadata' => ['user_id' => $userId, 'ttl_seconds' => $ttlSeconds],
                    'error' => 'Codul de verificare are format invalid.',
                ]
            );
        }

        $body = "Salut, {$displayName},\n\n";
        $body .= "Codul tau de verificare pentru autentificare este: {$code}\n";
        $body .= "Codul este valabil {$minutes} minute.\n\n";
        $body .= "Daca nu ai incercat sa te autentifici, ignora acest mesaj.\n\n";
        $body .= APP_NAME;

        return $this->deliverTextEmail($recipientEmail, $recipientName, $subject, $body, [
            'context' => 'auth_login_code',
            'context_id' => $userId !== null && $userId > 0 ? 'user:' . $userId : null,
            'metadata' => [
                'user_id' => $userId,
                'ttl_seconds' => $ttlSeconds,
                'purpose' => 'login_verification',
            ],
            'log_message' => 'Cod de verificare autentificare redactat pentru securitate.',
        ]);
    }

    public function sendTextEmail(string $toEmail, string $toName, string $subject, string $body): bool
    {
        $result = $this->deliverTextEmail($toEmail, $toName, $subject, $body);
        return (bool) ($result['sent'] ?? false);
    }

    public function deliverTextEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $body,
        array $options = []
    ): array {
        $toEmail = trim($toEmail);
        $toName = trim($toName);
        $subject = trim($subject);
        $config = $this->buildMailConfig();
        $result = $this->baseDeliveryResult($config, $toEmail, $options);

        if (!class_exists(PHPMailer::class)) {
            $result['error'] = 'Lipseste PHPMailer (vendor/autoload.php).';
            return $this->finishDeliveryResult($result, $toEmail, $toName, $subject, $body, $options);
        }

        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            $result['error'] = 'Destinatar invalid pentru notificare.';
            return $this->finishDeliveryResult($result, $toEmail, $toName, $subject, $body, $options);
        }

        if ($subject === '') {
            $result['error'] = 'Subiectul notificarii este gol.';
            return $this->finishDeliveryResult($result, $toEmail, $toName, $subject, $body, $options);
        }

        $configError = $this->validateMailConfig($config);
        if ($configError !== null) {
            $result['error'] = $configError;
            return $this->finishDeliveryResult($result, $toEmail, $toName, $subject, $body, $options);
        }

        if ($this->isProductionEnv() && $this->isMailpitConfiguration($config['host'], $config['port'])) {
            $result['error'] = 'Mailpit este blocat in productie.';
            return $this->finishDeliveryResult($result, $toEmail, $toName, $subject, $body, $options);
        }

        $lastError = 'Unknown SMTP error';
        $lastErrorInfo = '';
        $retryAttempts = (int) $config['retry_attempts'];

        for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
            $mailer = null;
            try {
                $startedAt = microtime(true);
                $mailer = $this->buildMailer($config);
                $mailer->addAddress($toEmail, $toName);
                $mailer->Subject = $subject;
                $mailer->Body = $body;
                $mailer->isHTML(false);
                $mailer->addCustomHeader('X-Fleet-Notification-Context', (string) ($options['context'] ?? 'general'));

                $mailer->send();

                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $result['sent'] = true;
                $result['status'] = 'sent';
                $result['response'] = 'Email accepted by ' . $result['provider'] . ' SMTP on attempt ' . $attempt . '/' . $retryAttempts . '.';
                $result['error'] = null;
                $result['diagnostics']['attempts'] = $attempt;
                $result['diagnostics']['duration_ms'] = $durationMs;

                error_log(
                    '[EmailService] Email accepted by SMTP in '
                    . $durationMs
                    . ' ms (attempt '
                    . $attempt
                    . '/'
                    . $retryAttempts
                    . '). Destinatar: '
                    . $this->maskEmailForLog($toEmail)
                );

                return $this->finishDeliveryResult($result, $toEmail, $toName, $subject, $body, $options);
            } catch (PHPMailerException $exception) {
                $lastError = $exception->getMessage();
                $lastErrorInfo = $mailer instanceof PHPMailer ? (string) $mailer->ErrorInfo : '';
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
                $lastErrorInfo = $mailer instanceof PHPMailer ? (string) $mailer->ErrorInfo : '';
            } finally {
                if ($mailer instanceof PHPMailer) {
                    $mailer->smtpClose();
                }
            }

            $lastError = $this->sanitizeErrorMessage($lastErrorInfo !== '' ? $lastErrorInfo : $lastError);
            if ($attempt < $retryAttempts && $this->isTransientSmtpConnectionFailure($lastError)) {
                $delayMs = (int) $config['retry_delay_ms'];
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
                continue;
            }

            break;
        }

        $result['error'] = $lastError;
        $result['diagnostics']['attempts'] = $retryAttempts;

        return $this->finishDeliveryResult($result, $toEmail, $toName, $subject, $body, $options);
    }

    private function buildMailer(array $config): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'UTF-8';
        $mailer->isSMTP();
        $mailer->Host = (string) $config['host'];
        $mailer->Port = (int) $config['port'];
        $mailer->SMTPAuth = true;
        $mailer->Username = (string) $config['username'];
        $mailer->Password = (string) $config['password'];
        $mailer->Timeout = (int) $config['connect_timeout'];
        $mailer->getSMTPInstance()->Timelimit = (int) $config['timeout'];

        $encryption = (string) $config['encryption'];
        if ($encryption === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'none') {
            $mailer->SMTPSecure = '';
            $mailer->SMTPAutoTLS = false;
        } else {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mailer->setFrom((string) $config['from_address'], (string) $config['from_name']);
        $mailer->addReplyTo((string) $config['from_address'], (string) $config['from_name']);

        if (filter_var((string) $config['return_path'], FILTER_VALIDATE_EMAIL) !== false) {
            $mailer->Sender = (string) $config['return_path'];
        }

        return $mailer;
    }

    private function buildMailConfig(): array
    {
        $encryption = strtolower(trim((string) (defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : 'tls')));

        return [
            'host' => trim((string) (defined('MAIL_HOST') ? MAIL_HOST : '')),
            'port' => (int) (defined('MAIL_PORT') ? MAIL_PORT : 587),
            'username' => trim((string) (defined('MAIL_USERNAME') ? MAIL_USERNAME : '')),
            'password' => (string) (defined('MAIL_PASSWORD') ? MAIL_PASSWORD : ''),
            'encryption' => in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls',
            'from_address' => trim((string) (defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : '')),
            'from_name' => trim((string) (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : APP_NAME)),
            'return_path' => trim((string) (defined('MAIL_RETURN_PATH') ? MAIL_RETURN_PATH : '')),
            'timeout' => max(10, (int) (defined('MAIL_TIMEOUT') ? MAIL_TIMEOUT : 30)),
            'connect_timeout' => max(3, (int) (defined('MAIL_CONNECT_TIMEOUT') ? MAIL_CONNECT_TIMEOUT : 8)),
            'retry_attempts' => max(1, (int) (defined('MAIL_RETRY_ATTEMPTS') ? MAIL_RETRY_ATTEMPTS : 2)),
            'retry_delay_ms' => max(0, (int) (defined('MAIL_RETRY_DELAY_MS') ? MAIL_RETRY_DELAY_MS : 400)),
        ];
    }

    private function validateMailConfig(array $config): ?string
    {
        $missing = [];
        foreach (['host', 'username', 'password', 'from_address'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        if ((int) ($config['port'] ?? 0) <= 0) {
            $missing[] = 'port';
        }

        if ($missing !== []) {
            return 'Configuratia SMTP este incompleta: ' . implode(', ', $missing) . '.';
        }

        if (filter_var((string) $config['from_address'], FILTER_VALIDATE_EMAIL) === false) {
            return 'Adresa expeditorului este invalida.';
        }

        return null;
    }

    private function baseDeliveryResult(array $config, string $toEmail, array $options): array
    {
        $provider = $this->providerName((string) ($config['host'] ?? ''));

        return [
            'sent' => false,
            'status' => 'failed',
            'provider' => $provider,
            'response' => null,
            'error' => null,
            'log_id' => null,
            'diagnostics' => [
                'smtp_host' => (string) ($config['host'] ?? ''),
                'smtp_port' => (int) ($config['port'] ?? 0),
                'smtp_encryption' => (string) ($config['encryption'] ?? ''),
                'smtp_username' => $this->maskEmailForLog((string) ($config['username'] ?? '')),
                'from_address' => $this->maskEmailForLog((string) ($config['from_address'] ?? '')),
                'return_path' => $this->maskEmailForLog((string) ($config['return_path'] ?? '')),
                'recipient_domain' => $this->emailDomain($toEmail),
                'warnings' => $this->buildConfigWarnings($config, $provider),
            ],
            'metadata' => (array) ($options['metadata'] ?? []),
        ];
    }

    private function finishDeliveryResult(
        array $result,
        string $toEmail,
        string $toName,
        string $subject,
        string $body,
        array $options
    ): array {
        if (($options['error'] ?? null) !== null && ($result['error'] ?? null) === null) {
            $result['error'] = (string) $options['error'];
        }

        if (($result['error'] ?? null) !== null) {
            $result['error'] = $this->sanitizeErrorMessage((string) $result['error']);
            $this->logEmailFailure('Trimiterea emailului a esuat: ' . $result['error'], $toEmail);
        }

        $logMessage = (string) ($options['log_message'] ?? $body);
        if ($logMessage === '') {
            $logMessage = '(mesaj gol)';
        }

        if ($this->deliveryLog instanceof NotificationDeliveryModel) {
            $result['log_id'] = $this->deliveryLog->create([
                'context' => (string) ($options['context'] ?? 'general'),
                'context_id' => $options['context_id'] ?? null,
                'recipient_email' => $toEmail,
                'recipient_name' => $toName,
                'subject' => $subject,
                'message' => $logMessage,
                'status' => (string) ($result['status'] ?? 'failed'),
                'provider' => (string) ($result['provider'] ?? 'smtp'),
                'provider_response' => $result['response'] ?? null,
                'error_message' => $result['error'] ?? null,
                'diagnostics' => $result['diagnostics'] ?? [],
                'metadata' => $result['metadata'] ?? [],
            ]);
        }

        return $result;
    }

    private function buildConfigWarnings(array $config, string $provider): array
    {
        $warnings = [];
        $port = (int) ($config['port'] ?? 0);
        $encryption = (string) ($config['encryption'] ?? '');
        $username = strtolower(trim((string) ($config['username'] ?? '')));
        $fromAddress = strtolower(trim((string) ($config['from_address'] ?? '')));

        if ($port === 465 && $encryption !== 'ssl') {
            $warnings[] = 'Portul 465 foloseste de obicei MAIL_ENCRYPTION=ssl.';
        }

        if ($port === 587 && $encryption === 'ssl') {
            $warnings[] = 'Portul 587 foloseste de obicei MAIL_ENCRYPTION=tls.';
        }

        if ($provider === 'gmail' && $username !== '' && $fromAddress !== '' && $username !== $fromAddress) {
            $warnings[] = 'Gmail poate respinge MAIL_FROM_ADDRESS daca nu este configurat ca alias pentru MAIL_USERNAME.';
        }

        if ($provider === 'migadu' && $username !== '' && $fromAddress !== '' && $username !== $fromAddress) {
            $warnings[] = 'Migadu cere ca MAIL_FROM_ADDRESS sa fie casuta sau alias permis pentru MAIL_USERNAME.';
        }

        return $warnings;
    }

    private function isProductionEnv(): bool
    {
        $env = strtolower(trim((string) (defined('APP_ENV') ? APP_ENV : 'development')));
        return in_array($env, ['production', 'prod'], true);
    }

    private function isMailpitConfiguration(string $host, int $port): bool
    {
        $normalizedHost = strtolower(trim($host));
        if (str_contains($normalizedHost, 'mailpit')) {
            return true;
        }

        return in_array($normalizedHost, ['127.0.0.1', 'localhost'], true) && in_array($port, [1025, 8025], true);
    }

    private function logEmailFailure(string $message, string $recipientEmail): void
    {
        error_log(
            '[EmailService] '
            . $message
            . ' Destinatar: '
            . $this->maskEmailForLog($recipientEmail)
        );
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if (defined('MAIL_PASSWORD') && MAIL_PASSWORD !== '') {
            $message = str_replace((string) MAIL_PASSWORD, '[hidden-password]', $message);
        }

        if (strlen($message) > 1200) {
            $message = substr($message, 0, 1200) . '...';
        }

        return $message !== '' ? $message : 'Unknown SMTP error';
    }

    private function maskEmailForLog(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return $email === '' ? '(empty)' : '(invalid)';
        }

        [$localPart, $domainPart] = explode('@', $email, 2);
        if (strlen($localPart) <= 2) {
            $maskedLocalPart = str_repeat('*', max(1, strlen($localPart)));
        } else {
            $maskedLocalPart = substr($localPart, 0, 2) . str_repeat('*', strlen($localPart) - 2);
        }

        return $maskedLocalPart . '@' . $domainPart;
    }

    private function providerName(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_contains($host, 'migadu')) {
            return 'migadu';
        }

        if (str_contains($host, 'gmail')) {
            return 'gmail';
        }

        return $host !== '' ? $host : 'smtp';
    }

    private function emailDomain(string $email): ?string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        return strtolower(substr(strrchr($email, '@') ?: '', 1)) ?: null;
    }

    private function isTransientSmtpConnectionFailure(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'could not connect to smtp host')
            || str_contains($message, 'connection timed out')
            || str_contains($message, 'no response received from smtp server')
            || str_contains($message, 'failed to connect to server')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'temporarily unavailable');
    }
}
