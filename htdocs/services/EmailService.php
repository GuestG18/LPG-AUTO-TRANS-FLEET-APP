<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

class EmailService
{
    public function sendLoginVerificationCode(
        string $recipientEmail,
        string $recipientName,
        string $code,
        int $ttlSeconds
    ): bool {
        $recipientEmail = trim($recipientEmail);
        if ($recipientEmail === '' || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->logEmailFailure('Destinatar invalid pentru codul de verificare.', $recipientEmail);
            return false;
        }

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            $this->logEmailFailure('Codul de verificare are format invalid.', $recipientEmail);
            return false;
        }

        $displayName = trim($recipientName) !== '' ? trim($recipientName) : 'utilizator';
        $minutes = max(1, (int) ceil($ttlSeconds / 60));
        $subject = 'Cod verificare autentificare - ' . APP_NAME;
        $body = "Salut, {$displayName},\n\n";
        $body .= "Codul tau de verificare pentru autentificare este: {$code}\n";
        $body .= "Codul este valabil {$minutes} minute.\n\n";
        $body .= "Daca nu ai incercat sa te autentifici, ignora acest mesaj.\n\n";
        $body .= APP_NAME;

        return $this->sendTextEmail($recipientEmail, $recipientName, $subject, $body);
    }

    public function sendTextEmail(string $toEmail, string $toName, string $subject, string $body): bool
    {
        if (!class_exists(PHPMailer::class)) {
            $this->logEmailFailure('Lipseste PHPMailer (vendor/autoload.php).', $toEmail);
            return false;
        }

        $host = trim((string) (defined('MAIL_HOST') ? MAIL_HOST : ''));
        $port = (int) (defined('MAIL_PORT') ? MAIL_PORT : 587);
        $username = trim((string) (defined('MAIL_USERNAME') ? MAIL_USERNAME : ''));
        $password = (string) (defined('MAIL_PASSWORD') ? MAIL_PASSWORD : '');
        $encryption = strtolower(trim((string) (defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : 'tls')));
        $fromAddress = trim((string) (defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : ''));
        $fromName = trim((string) (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : APP_NAME));
        $timeout = max(10, (int) (defined('MAIL_TIMEOUT') ? MAIL_TIMEOUT : 30));
        $connectTimeout = max(3, (int) (defined('MAIL_CONNECT_TIMEOUT') ? MAIL_CONNECT_TIMEOUT : 8));
        $retryAttempts = max(1, (int) (defined('MAIL_RETRY_ATTEMPTS') ? MAIL_RETRY_ATTEMPTS : 2));
        $retryDelayMs = max(0, (int) (defined('MAIL_RETRY_DELAY_MS') ? MAIL_RETRY_DELAY_MS : 400));

        if ($host === '' || $port <= 0 || $username === '' || $password === '') {
            $this->logEmailFailure('Configuratia SMTP este incompleta.', $toEmail);
            return false;
        }

        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            $this->logEmailFailure('Adresa expeditorului este invalida.', $toEmail);
            return false;
        }

        if ($this->isProductionEnv() && $this->isMailpitConfiguration($host, $port)) {
            $this->logEmailFailure('Mailpit este blocat in productie.', $toEmail);
            return false;
        }

        $encryption = in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls';

        $lastError = 'Unknown SMTP error';
        for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
            $mailer = null;
            try {
                $startedAt = microtime(true);
                $mailer = new PHPMailer(true);
                $mailer->CharSet = 'UTF-8';
                $mailer->isSMTP();
                $mailer->Host = $host;
                $mailer->Port = $port;
                $mailer->SMTPAuth = true;
                $mailer->Username = $username;
                $mailer->Password = $password;
                $mailer->Timeout = $connectTimeout;
                $mailer->getSMTPInstance()->Timelimit = $timeout;

                if ($encryption === 'ssl') {
                    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($encryption === 'none') {
                    $mailer->SMTPSecure = '';
                    $mailer->SMTPAutoTLS = false;
                } else {
                    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                }

                $mailer->setFrom($fromAddress, $fromName);
                $mailer->addAddress($toEmail, trim($toName));
                $mailer->Subject = $subject;
                $mailer->Body = $body;
                $mailer->isHTML(false);

                // Pre-connect to avoid waiting the full message send path on transient SMTP connect drops.
                if (!$mailer->smtpConnect()) {
                    throw new PHPMailerException('SMTP connect failed before send.');
                }

                $sent = $mailer->send();
                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
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

                return $sent;
            } catch (PHPMailerException $exception) {
                $lastError = $exception->getMessage();
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            } finally {
                if ($mailer instanceof PHPMailer) {
                    $mailer->smtpClose();
                }
            }

            if ($attempt < $retryAttempts && $this->isTransientSmtpConnectionFailure($lastError)) {
                if ($retryDelayMs > 0) {
                    usleep($retryDelayMs * 1000);
                }
                continue;
            }

            break;
        }

        $this->logEmailFailure('Trimiterea emailului a esuat: ' . $lastError, $toEmail);
        return false;
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

    private function maskEmailForLog(string $email): string
    {
        $email = trim($email);
        if ($email === '' || !str_contains($email, '@')) {
            return '(invalid)';
        }

        [$localPart, $domainPart] = explode('@', $email, 2);
        if (strlen($localPart) <= 2) {
            $maskedLocalPart = str_repeat('*', max(1, strlen($localPart)));
        } else {
            $maskedLocalPart = substr($localPart, 0, 2) . str_repeat('*', strlen($localPart) - 2);
        }

        return $maskedLocalPart . '@' . $domainPart;
    }

    private function isTransientSmtpConnectionFailure(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'could not connect to smtp host')
            || str_contains($message, 'connection timed out')
            || str_contains($message, 'no response received from smtp server')
            || str_contains($message, 'failed to connect to server');
    }
}
