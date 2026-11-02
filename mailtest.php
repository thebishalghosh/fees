<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Display errors directly on-screen to catch connection issues
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// 1. Locate and load Composer autoloader
$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    '/home1/a1782a4c/demo.gupimess.in/task/vendor/autoload.php',
];

$autoloaderFound = false;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        require_once $candidate;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    die('[FATAL] vendor/autoload.php not found.');
}

// 2. Native .env loader function (No external packages required)
function loadEnvFile(string $filePath): array
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return [];
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Split key and value on the first '='
        if (str_contains($line, '=')) {
            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value);

            // Strip enclosing quotes (single or double)
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $env[$name] = $value;
        }
    }
    return $env;
}

// 3. Locate and parse the .env file
$envCandidates = [
    '/home1/a1782a4c/demo.gupimess.in/task/.env',
    __DIR__ . '/.env',
    dirname(__DIR__) . '/.env',
];

$loadedEnvPath = null;
$config = [];
foreach ($envCandidates as $envPath) {
    if (is_file($envPath) && is_readable($envPath)) {
        $config = loadEnvFile($envPath);
        $loadedEnvPath = $envPath;
        break;
    }
}

// 4. Extract configuration values
$smtpHost       = $config['MAIL_HOST'] ?? '';
$smtpPort       = (int)($config['MAIL_PORT'] ?? 465);
$smtpUser       = $config['MAIL_USERNAME'] ?? '';
$smtpPass       = $config['MAIL_PASSWORD'] ?? '';
$smtpEncryption = strtolower((string)($config['MAIL_ENCRYPTION'] ?? 'ssl'));
$smtpTimeout    = (int)($config['MAIL_TIMEOUT'] ?? 10);
$fromAddress    = $config['MAIL_FROM_ADDRESS'] ?? $smtpUser;
$fromName       = trim((string)($config['MAIL_FROM_NAME'] ?? 'FeesPortal'), '"\'');
$toAddress      = $config['MAIL_TEST_TO'] ?? $fromAddress;

// Map encryption mode to PHPMailer constants
$encryptionConstant = match ($smtpEncryption) {
    'ssl', 'smtps'    => PHPMailer::ENCRYPTION_SMTPS,
    'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
    default           => '',
};

$sentSuccessfully = false;
$errorMessage = '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FeesPortal - SMTP Dispatcher Test</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 32px 16px;
            margin: 0;
            line-height: 1.5;
        }
        .container { max-width: 820px; margin: 0 auto; }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
        }
        h1 { color: #38bdf8; font-size: 22px; margin-top: 0; margin-bottom: 8px; }
        .subtitle { color: #94a3b8; font-size: 14px; margin-bottom: 20px; }
        .config-box {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 6px;
            padding: 14px;
            margin-bottom: 20px;
            font-size: 13px;
            font-family: monospace;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 13px;
        }
        .badge-success { background: #166534; color: #bbf7d0; }
        .badge-error { background: #991b1b; color: #fecaca; }
        pre.debug-log {
            background: #020617;
            border: 1px solid #1e293b;
            color: #38bdf8;
            padding: 16px;
            border-radius: 6px;
            font-size: 12px;
            font-family: Consolas, Monaco, "Courier New", monospace;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 400px;
            overflow-y: auto;
        }
        code {
            background: #334155;
            color: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>FeesPortal SMTP Dispatcher Test</h1>
        <div class="subtitle">Validating live connection and mail dispatch via PHPMailer</div>

        <div class="config-box">
            <strong>Loaded Configuration:</strong><br>
            • Environment file: <?= $loadedEnvPath ? htmlspecialchars($loadedEnvPath) : '<span style="color:#f87171;">Not loaded</span>' ?><br>
            • SMTP Host: <code><?= htmlspecialchars($smtpHost ?: '[EMPTY]') ?>:<?= $smtpPort ?></code><br>
            • SMTP User: <code><?= htmlspecialchars($smtpUser ?: '[EMPTY]') ?></code><br>
            • Security: <code><?= htmlspecialchars($smtpEncryption) ?> (<?= $encryptionConstant === PHPMailer::ENCRYPTION_SMTPS ? 'SSL/SMTPS' : ($encryptionConstant === PHPMailer::ENCRYPTION_STARTTLS ? 'STARTTLS' : 'None') ?>)</code><br>
            • From: <code><?= htmlspecialchars($fromName) ?> &lt;<?= htmlspecialchars($fromAddress) ?>&gt;</code><br>
            • Recipient: <code><?= htmlspecialchars($toAddress) ?></code>
        </div>

        <h3 style="font-size: 15px; margin-bottom: 8px;">SMTP Debug Stream:</h3>
        <pre class="debug-log"><?php
        if (!$loadedEnvPath) {
            echo "[ERROR] .env file could not be found or read.\n";
            $errorMessage = ".env file missing or unreadable.";
        } elseif (empty($smtpHost) || empty($smtpUser) || empty($smtpPass)) {
            echo "[ERROR] MAIL_HOST, MAIL_USERNAME, or MAIL_PASSWORD is empty in .env.\n";
            $errorMessage = "Essential SMTP configuration keys are missing.";
        } else {
            $mail = new PHPMailer(true);
            try {
                $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
                $mail->Debugoutput = function ($str, $level) {
                    echo htmlspecialchars($str) . "\n";
                };

                $mail->isSMTP();
                $mail->Host        = $smtpHost;
                $mail->SMTPAuth    = true;
                $mail->Username    = $smtpUser;
                $mail->Password    = $smtpPass;
                if ($encryptionConstant !== '') {
                    $mail->SMTPSecure = $encryptionConstant;
                }
                $mail->Port        = $smtpPort;
                $mail->Timeout     = $smtpTimeout;

                $mail->setFrom($fromAddress, $fromName);
                $mail->addAddress($toAddress);

                $mail->isHTML(false);
                $mail->Subject = 'FeesPortal SMTP Test - ' . date('Y-m-d H:i:s');
                $mail->Body    = "Hello,\r\n\r\nThis is a test notification confirming that FeesPortal can connect and authenticate via {$smtpHost}:{$smtpPort} ({$smtpEncryption}).";

                $mail->send();
                $sentSuccessfully = true;
            } catch (Exception $e) {
                $sentSuccessfully = false;
                $errorMessage = $mail->ErrorInfo ?: $e->getMessage();
            }
        }
        ?></pre>

        <div style="margin-top: 20px;">
            <?php if ($sentSuccessfully): ?>
                <span class="badge badge-success">✓ EMAIL SENT SUCCESSFULLY</span>
                <p style="margin-top: 10px; color: #86efac;">
                    The test message was accepted by <strong><?= htmlspecialchars($smtpHost) ?></strong> and queued for delivery to <strong><?= htmlspecialchars($toAddress) ?></strong>.
                </p>
            <?php else: ?>
                <span class="badge badge-error">✕ DISPATCH FAILED</span>
                <p style="margin-top: 10px; color: #fca5a5;">
                    <strong>Details:</strong> <?= htmlspecialchars($errorMessage) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>