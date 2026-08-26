<?php
/**
 * Template for mail_config.php (gitignored because it holds a real Gmail
 * App Password). Copy this file to mail_config.php and fill in your own
 * sending account. Generate an App Password at
 * https://myaccount.google.com/apppasswords (requires 2-Step Verification).
 */
if (!defined('TV_SMTP_HOST')) {
    define('TV_SMTP_HOST', 'smtp.gmail.com');
    define('TV_SMTP_PORT', 587);
    define('TV_SMTP_USER', 'your-sending-account@gmail.com');
    define('TV_SMTP_PASS', 'your-16-char-app-password');
    define('TV_SMTP_FROM_NAME', 'TripVerse');
}
