<?php
/**
 * TEMPORARY diagnostic — upload to the live server, open it in a browser,
 * read the result, then DELETE IT.
 *
 * It answers one question: can this host actually reach an external SMTP
 * server? Locally (XAMPP) everything passes. On restricted shared hosting
 * such as InfinityFree free plans, outbound SMTP is firewalled, so the OTP
 * email in forgot_password / register silently fails there while working
 * fine on your machine.
 *
 * It prints NO credentials — only host, port and pass/fail.
 */

header('Content-Type: text/plain; charset=utf-8');

echo "TripVerse mail diagnostic\n";
echo str_repeat('=', 46), "\n\n";

echo "PHP version : ", PHP_VERSION, "\n";
echo "Server      : ", ($_SERVER['SERVER_NAME'] ?? 'cli'), "\n\n";

// --- 1. Are the functions we rely on even available? -------------------
$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
foreach (['stream_socket_client', 'fsockopen', 'curl_init', 'mail'] as $fn) {
    $exists = function_exists($fn);
    $blocked = in_array($fn, $disabled, true);
    printf("%-22s %s\n", $fn, $blocked ? 'DISABLED by host' : ($exists ? 'available' : 'missing'));
}
echo "\n";

// --- 2. Can we open a socket to Gmail's SMTP port? ---------------------
$host = 'smtp.gmail.com';
foreach ([587, 465] as $port) {
    $label = "TCP {$host}:{$port}";
    if (!function_exists('stream_socket_client')) {
        printf("%-28s SKIPPED (function unavailable)\n", $label);
        continue;
    }
    $start = microtime(true);
    $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
    $ms = round((microtime(true) - $start) * 1000);
    if ($sock) {
        $greeting = trim((string) fgets($sock, 515));
        fclose($sock);
        printf("%-28s OK (%dms) %s\n", $label, $ms, $greeting);
    } else {
        printf("%-28s BLOCKED (%dms) errno=%d %s\n", $label, $ms, $errno, $errstr);
    }
}
echo "\n";

// --- 3. Is outbound HTTPS allowed? (the workaround path) --------------
if (function_exists('curl_init')) {
    $ch = curl_init('https://api.brevo.com/v3/account');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_NOBODY         => true,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    // 401 is a fine result here: it means we reached the API and it asked
    // for a key, which proves outbound HTTPS works.
    printf("%-28s %s\n", 'HTTPS out (port 443)',
        $code > 0 ? "OK (HTTP $code)" : "BLOCKED ($err)");
} else {
    echo "HTTPS out (port 443)         SKIPPED (cURL unavailable)\n";
}

echo "\n", str_repeat('-', 46), "\n";
echo "Reading this:\n";
echo "  TCP 587/465 OK        -> SMTP works here, OTP email will send.\n";
echo "  TCP blocked + HTTPS OK-> switch to an email HTTP API\n";
echo "                           (Brevo/SendGrid/Mailgun) over port 443.\n";
echo "  Both blocked          -> no email from this host at all.\n";
echo "\nDelete this file when you are done.\n";
