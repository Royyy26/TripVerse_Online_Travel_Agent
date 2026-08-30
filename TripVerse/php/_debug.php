<?php
/**
 * TEMPORARY diagnostic — upload, open in a browser, read the error, DELETE IT.
 *
 * The live host runs with display_errors off, so a fatal in one of these pages
 * reaches the browser as a bare "HTTP ERROR 500" with nothing to act on. This
 * turns error output on for a single request and then runs the chosen page, so
 * the real message is printed instead of swallowed.
 *
 * Usage — pick a page with ?page=
 *   /TripVerse/php/_debug.php?page=supplier/owner_dashboard.php
 *   /TripVerse/php/_debug.php?page=admin/customerdss.php
 *   /TripVerse/php/_debug.php?page=admin/occupancy_analysis.php
 *
 * Only the pages listed below can be run, so this cannot be pointed at
 * arbitrary files. Prints no credentials.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$allowed = [
    'supplier/owner_dashboard.php',
    'admin/customerdss.php',
    'admin/occupancy_analysis.php',
    'customer/home.php',
];

$page = $_GET['page'] ?? '';

header('Content-Type: text/html; charset=utf-8');
echo "<pre style=\"background:#111;color:#0f0;padding:14px;font:13px/1.6 monospace;white-space:pre-wrap\">";

echo "PHP version   : " . PHP_VERSION . "\n";
echo "memory_limit  : " . ini_get('memory_limit') . "\n";
echo "max_execution : " . ini_get('max_execution_time') . "s\n";

foreach (['mysqli', 'json', 'mbstring', 'gd'] as $ext) {
    printf("ext %-9s : %s\n", $ext, extension_loaded($ext) ? 'loaded' : 'MISSING');
}

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
echo "disabled fns  : " . ($disabled ? implode(', ', $disabled) : '(none)') . "\n\n";

// Confirm the shared dependencies actually arrived on the server.
foreach (['db_config.php', 'connect.php', '_lang.php', '_lang_switch_inner.php',
          '_pagination.php', 'activity_log_helper.php'] as $dep) {
    $p = __DIR__ . '/' . $dep;
    printf("%-26s %s\n", $dep, is_file($p) ? 'ok (' . filesize($p) . ' bytes)' : 'MISSING');
}
echo "\n";

// Can we even reach the database? A failed connect is a very common cause of
// a 500 that only happens in production.
echo "--- database ---\n";
try {
    require_once __DIR__ . '/db_config.php';
    if (isset($conn) && $conn instanceof mysqli) {
        echo "connected ok, server: " . $conn->server_info . "\n";
        $r = $conn->query("SELECT COUNT(*) c FROM hotel");
        echo "hotel rows  : " . ($r ? $r->fetch_assoc()['c'] : 'query failed') . "\n";
    } else {
        echo "db_config.php ran but \$conn was not created\n";
    }
} catch (Throwable $e) {
    echo "DB ERROR: " . get_class($e) . ' — ' . $e->getMessage() . "\n";
}
echo "\n";

if ($page === '') {
    echo "--- pick a page ---\n";
    foreach ($allowed as $a) {
        echo "  ?page=$a\n";
    }
    echo "</pre>";
    exit;
}

if (!in_array($page, $allowed, true)) {
    echo "'$page' is not in the allowed list.</pre>";
    exit;
}

$target = __DIR__ . '/' . $page;
if (!is_file($target)) {
    echo "MISSING ON SERVER: $page  <-- this file did not upload.</pre>";
    exit;
}

printf("--- running %s (%d bytes) — any error appears below ---\n", $page, filesize($target));
echo "</pre>";

require $target;
