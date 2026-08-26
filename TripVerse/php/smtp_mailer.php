<?php
/**
 * Minimal SMTP client (STARTTLS + AUTH LOGIN) for sending OTP emails via
 * Gmail, without pulling in a library/Composer. Only handles what the OTP
 * flow needs: one plain-auth send to one recipient.
 */
require_once __DIR__ . '/mail_config.php';

function tv_smtp_read($socket)
{
    $data = '';
    while ($line = fgets($socket, 515)) {
        $data .= $line;
        // A response is done once a line has a space (not a dash) after the code.
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function tv_smtp_command($socket, $command, $expectedCode)
{
    fwrite($socket, $command . "\r\n");
    $response = tv_smtp_read($socket);
    $code = substr($response, 0, 3);
    if ($code !== (string) $expectedCode) {
        throw new Exception("SMTP command failed ($command): $response");
    }
    return $response;
}

/**
 * Sends a plain-text email through the configured Gmail account.
 * Returns true on success, or a string with the error on failure (so
 * callers can log/display something useful instead of a silent false).
 */
function sendOtpEmail(string $toEmail, string $toName, string $subject, string $bodyText)
{
    $socket = @stream_socket_client(
        'tcp://' . TV_SMTP_HOST . ':' . TV_SMTP_PORT,
        $errno,
        $errstr,
        15
    );

    if (!$socket) {
        return "Connection failed: $errstr ($errno)";
    }

    try {
        tv_smtp_read($socket); // 220 greeting
        tv_smtp_command($socket, 'EHLO tripverse.local', 250);
        tv_smtp_command($socket, 'STARTTLS', 220);

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception('TLS handshake failed');
        }

        tv_smtp_command($socket, 'EHLO tripverse.local', 250);
        tv_smtp_command($socket, 'AUTH LOGIN', 334);
        tv_smtp_command($socket, base64_encode(TV_SMTP_USER), 334);
        tv_smtp_command($socket, base64_encode(TV_SMTP_PASS), 235);

        tv_smtp_command($socket, 'MAIL FROM:<' . TV_SMTP_USER . '>', 250);
        tv_smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', 250);
        tv_smtp_command($socket, 'DATA', 354);

        $headers = [
            'From: ' . TV_SMTP_FROM_NAME . ' <' . TV_SMTP_USER . '>',
            'To: ' . ($toName !== '' ? "$toName <$toEmail>" : $toEmail),
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Date: ' . date('r'),
        ];

        // Dot-stuff any line that starts with a lone "." per RFC 5321.
        $escapedBody = preg_replace('/^\./m', '..', $bodyText);

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $escapedBody . "\r\n.";
        tv_smtp_command($socket, $message, 250);

        tv_smtp_command($socket, 'QUIT', 221);
        fclose($socket);
        return true;
    } catch (Exception $e) {
        fclose($socket);
        return $e->getMessage();
    }
}
