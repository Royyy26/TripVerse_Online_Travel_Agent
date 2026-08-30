<?php
/**
 * Entry point for the site root.
 *
 * Without this file the document root has no index, and hosts that disable
 * directory listing (InfinityFree among them) answer a bare "/" request with
 * 403 Forbidden rather than 404 — which reads like a permissions problem when
 * it is really just a missing landing page.
 *
 * The upload layout differs depending on whether the TripVerse folder was
 * copied in whole or its contents were dropped straight into htdocs, so this
 * checks both before redirecting.
 */

$candidates = [
    'TripVerse/php/auth/login.php', // whole TripVerse/ folder uploaded
    'php/auth/login.php',           // contents of TripVerse/ uploaded
];

foreach ($candidates as $target) {
    if (file_exists(__DIR__ . '/' . $target)) {
        header('Location: ' . $target);
        exit;
    }
}

// Nothing matched — say so plainly instead of leaving a blank page.
http_response_code(500);
header('Content-Type: text/plain; charset=utf-8');
echo "TripVerse: login page not found.\n\n";
echo "Expected one of these, relative to this file:\n";
foreach ($candidates as $target) {
    echo "  - $target\n";
}
echo "\nCheck that the PHP folder was uploaded and kept its structure.\n";
