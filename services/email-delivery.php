<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class OtpEmailDeliveryException extends RuntimeException
{
}

function mailpit_runtime_configuration_status(): array
{
    $config = app_config();
    $mail = $config['mail'];

    $status = [
        'app_env_development' => $config['env'] === 'development',
        'mail_provider_mailpit' => $mail['provider'] === 'mailpit',
        'smtp_host_expected' => $mail['host'] === '127.0.0.1',
        'smtp_port_expected' => $mail['port'] === 1025,
        'smtp_encryption_none' => $mail['encryption'] === 'none',
        'mail_transport_valid' => otp_email_delivery_available(),
        'mail_sender_valid' => filter_var($mail['from_address'], FILTER_VALIDATE_EMAIL) !== false
            && trim($mail['from_name']) !== ''
            && preg_match('/[\r\n]/', $mail['from_name']) !== 1,
        'smtp_auth_config_valid' => ($mail['username'] === '') === ($mail['password'] === ''),
        'smtp_timeout_valid' => $mail['timeout_seconds'] >= 1,
    ];
    $status['mail_config_match'] = !in_array(false, $status, true);

    return $status;
}

function log_mailpit_delivery_failure(string $reason, ?Throwable $exception = null, string $recipient = ''): void
{
    $config = app_config();
    if ($config['env'] !== 'development') {
        return;
    }

    $mail = $config['mail'];
    $entry = [
        'event' => 'mailpit_delivery_failed',
        'reason' => $reason,
    ];

    $entry['host'] = preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $mail['host']) === 1 ? $mail['host'] : 'invalid';
    $entry['port'] = $mail['port'];
    $entry['encryption'] = in_array($mail['encryption'], ['none', 'tls', 'ssl'], true)
        ? $mail['encryption']
        : 'invalid';

    if ($exception !== null) {
        $entry['error_type'] = get_class($exception);
        $entry['error_code'] = ctype_digit((string) $exception->getCode())
            ? (string) $exception->getCode()
            : '0';
        $message = str_replace(
            array_filter([$mail['username'], $mail['password'], $recipient], static fn(string $value): bool => $value !== ''),
            '[redacted]',
            $exception->getMessage()
        );
        $message = preg_replace('/\b[0-9]{6}\b/', '[redacted]', $message) ?? '';
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
        $entry['transport_error'] = substr(trim($message), 0, 300);
    }

    error_log('[FeesApp] ' . json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
}

function otp_email_delivery_available(): bool
{
    $config = app_config();
    $mail = $config['mail'];

    return $config['env'] === 'development'
        && $mail['provider'] === 'mailpit'
        && $mail['host'] !== ''
        && preg_match('/^[A-Za-z0-9.-]+$/D', $mail['host']) === 1
        && $mail['port'] >= 1
        && $mail['port'] <= 65535
        && filter_var($mail['from_address'], FILTER_VALIDATE_EMAIL) !== false;
}

function send_otp_email(
    string $recipient,
    string $subject,
    string $body,
    ?callable $mockTransport = null,
    ?string $correlationId = null,
    ?string $altBody = null
): bool {
    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
        throw new OtpEmailDeliveryException('The registered email address is unavailable.');
    }

    if ($mockTransport !== null) {
        try {
            if ($mockTransport($recipient, $subject, $body, $altBody) !== true) {
                throw new OtpEmailDeliveryException('The email transport did not confirm delivery.');
            }
            return true;
        } catch (OtpEmailDeliveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OtpEmailDeliveryException('The email transport failed.', 0, $exception);
        }
    }

    $config = app_config();
    $mail = $config['mail'];
    if ($config['env'] !== 'development' || $mail['provider'] !== 'mailpit') {
        if ($correlationId === null) {
            log_mailpit_delivery_failure('delivery_failed', null, $recipient);
        }
        throw new OtpEmailDeliveryException('No email provider is configured for this environment.');
    }
    if (!otp_email_delivery_available()
        || !in_array($mail['encryption'], ['none', 'tls', 'ssl'], true)
        || $mail['timeout_seconds'] < 1
        || trim($mail['from_name']) === ''
        || preg_match('/[\r\n]/', $mail['from_name']) === 1
        || (($mail['username'] === '') !== ($mail['password'] === ''))) {
        if ($correlationId === null) {
            log_mailpit_delivery_failure('delivery_failed', null, $recipient);
        }
        throw new OtpEmailDeliveryException('Mailpit SMTP configuration is incomplete.');
    }

    $autoloadPath = APP_ROOT . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!is_file($autoloadPath)) {
        if ($correlationId === null) {
            log_mailpit_delivery_failure('delivery_failed', null, $recipient);
        }
        throw new OtpEmailDeliveryException('The email delivery library is unavailable.');
    }
    require_once $autoloadPath;
    if (!class_exists(PHPMailer::class)) {
        if ($correlationId === null) {
            log_mailpit_delivery_failure('delivery_failed', null, $recipient);
        }
        throw new OtpEmailDeliveryException('The email delivery library is unavailable.');
    }

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $mail['host'];
        $mailer->Port = $mail['port'];
        $mailer->Timeout = $mail['timeout_seconds'];
        $mailer->SMTPDebug = 0;
        $mailer->SMTPAuth = $mail['username'] !== '';
        if ($mailer->SMTPAuth) {
            $mailer->Username = $mail['username'];
            $mailer->Password = $mail['password'];
        }
        if ($mail['encryption'] === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($mail['encryption'] === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mailer->SMTPAutoTLS = false;
        }

        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($mail['from_address'], $mail['from_name']);
        $mailer->addAddress($recipient);
        $mailer->Subject = $subject;
        $mailer->isHTML($altBody !== null);
        $mailer->Body = $body;
        $mailer->AltBody = $altBody ?? $body;
        if (!$mailer->send()) {
            if ($correlationId === null) {
                log_mailpit_delivery_failure('delivery_failed', null, $recipient);
            }
            throw new OtpEmailDeliveryException('Mailpit did not confirm email delivery.');
        }
    } catch (PHPMailerException $exception) {
        if ($correlationId === null) {
            log_mailpit_delivery_failure('delivery_failed', $exception, $recipient);
        }
        throw new OtpEmailDeliveryException('Mailpit SMTP delivery failed.', 0, $exception);
    } catch (OtpEmailDeliveryException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        if ($correlationId === null) {
            log_mailpit_delivery_failure('delivery_failed', $exception, $recipient);
        }
        throw new OtpEmailDeliveryException('Mailpit SMTP delivery failed.', 0, $exception);
    }

    return true;
}
