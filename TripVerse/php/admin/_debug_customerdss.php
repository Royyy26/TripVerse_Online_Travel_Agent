<?php
/**
 * TEMPORARY diagnostic — upload, open in a browser, read the error, DELETE IT.
 *
 * Production hosts run with display_errors off, so a fatal error in a page
 * reaches the browser as a bare "HTTP ERROR 500" with no explanation. This
 * turns error output back on for one request and then runs the real page, so
 * whatever is failing is printed instead of swallowed.
 *
 * Open it exactly like the page it wraps (you must already be logged in as
 * admin, since the wrapped page enforces that itself):
 *     /TripVerse/php/admin/_debug_customerdss.php
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Report the environment first — if the page dies, this part still printed.
header('Content-Type: text/html; charset=utf-8');
echo "<pre style=\"background:#111;color:#0f0;padding:12px;font:13px/1.5 monospace\">";
echo "PHP version   : " . PHP_VERSION . "\n";
echo "memory_limit  : " . ini_get('memory_limit') . "\n";
echo "max_execution : " . ini_get('max_execution_time') . "s\n";
echo "post_max_size : " . ini_get('post_max_size') . "\n";

$needed = ['mysqli', 'json', 'mbstring', 'curl'];
foreach ($needed as $ext) {
    printf("ext %-10s: %s\n", $ext, extension_loaded($ext) ? 'loaded' : 'MISSING');
}

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
echo "disabled fns  : " . ($disabled ? implode(', ', $disabled) : '(none)') . "\n";

// Are the files the page depends on actually present after upload?
foreach ([
    __DIR__ . '/../db_config.php',
    __DIR__ . '/../connect.php',
    __DIR__ . '/../_lang.php',
    __DIR__ . '/../_lang_switch_inner.php',
    __DIR__ . '/customerdss.php',
] as $f) {
    printf("%-42s %s\n", basename($f), is_file($f) ? 'ok' : 'MISSING');
}

echo "\n--- now running customerdss.php, any error appears below ---\n";
echo "</pre>";

require __DIR__ . '/customerdss.php';
