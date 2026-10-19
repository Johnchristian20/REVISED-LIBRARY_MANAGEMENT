<?php
function ensurePasswordResetTable(mysqli $conn): void
{
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS password_reset_codes (
        reset_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (reset_id),
        KEY idx_reset_user_created (user_id,created_at),
        CONSTRAINT fk_reset_code_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function smtpRead($socket, array $expected): string
{
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) throw new RuntimeException('The email server closed the connection.');
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');
    if (!in_array((int) substr($line, 0, 3), $expected, true)) throw new RuntimeException('The email server rejected a command (' . trim($line) . ').');
    return $response;
}

function smtpCommand($socket, string $command, array $expected): string
{
    fwrite($socket, $command . "\r\n");
    return smtpRead($socket, $expected);
}

function sendPasswordResetCode(string $recipient, string $code, string &$error): bool
{
    $config = [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => '',
        'password' => '',
        'encryption' => 'tls',
        'from' => '',
        'from_name' => 'The Reading Room'
    ];
    $exampleConfig = __DIR__ . '/mail_config.example.php';
    if (is_file($exampleConfig)) {
        $example = require $exampleConfig;
        if (is_array($example)) $config = array_merge($config, $example);
    }
    $localConfig = __DIR__ . '/mail_config.local.php';
    if (is_file($localConfig)) {
        $local = require $localConfig;
        if (is_array($local)) $config = array_merge($config, $local);
    }
    $env = static function (string $key, string $setting, $fallback = '') use ($config) {
        $value = getenv($key);
        return $value !== false && $value !== '' ? $value : ($config[$setting] ?? $fallback);
    };
    $host = trim((string) $env('LIBRARY_MAIL_HOST', 'host'));
    $port = (int) $env('LIBRARY_MAIL_PORT', 'port', 587);
    $username = (string) $env('LIBRARY_MAIL_USERNAME', 'username');
    $password = (string) $env('LIBRARY_MAIL_PASSWORD', 'password');
    $encryption = strtolower(trim((string) $env('LIBRARY_MAIL_ENCRYPTION', 'encryption', 'tls')));
    $from = trim((string) $env('LIBRARY_MAIL_FROM', 'from', $username));
    $fromName = trim((string) $env('LIBRARY_MAIL_FROM_NAME', 'from_name', 'The Reading Room'));
    if ($host === '' || $username === '' || $password === '' || $from === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email sending is not configured. Add the library SMTP settings before requesting a reset code.';
        return false;
    }
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) { $error = 'Email encryption setting must be tls, ssl, or none.'; return false; }
    $target = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$socket) { $error = 'Could not connect to the configured email server.'; return false; }
    stream_set_timeout($socket, 15);
    try {
        smtpRead($socket, [220]);
        smtpCommand($socket, 'EHLO thereadingroom.local', [250]);
        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Could not establish a secure email connection.');
            smtpCommand($socket, 'EHLO thereadingroom.local', [250]);
        }
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($username), [334]);
        smtpCommand($socket, base64_encode($password), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);
        $safeName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $subject = '=?UTF-8?B?' . base64_encode('Your Reading Room password reset code') . '?=';
        $body = "Hello,\r\n\r\nYour password reset code is: {$code}\r\n\r\nThis code expires in 15 minutes. If you did not request this, you can ignore this email.\r\n\r\nThe Reading Room";
        $message = 'From: ' . $safeName . ' <' . $from . ">\r\n" . 'To: <' . $recipient . ">\r\n" . 'Subject: ' . $subject . "\r\nDate: " . date(DATE_RFC2822) . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $body;
        $message = preg_replace('/(?m)^\./', '..', $message);
        fwrite($socket, $message . "\r\n.\r\n");
        smtpRead($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        fclose($socket);
        return false;
    }
}
