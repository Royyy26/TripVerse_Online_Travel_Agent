<?php
/**
 * Admin Folder - TripVerse
 * 
 * This folder contains all admin-only pages.
 * Direct access to this index returns to login.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['id_user']) && ($_SESSION['role'] ?? '') === 'admin') {
    header('Location: dashboard.php');
} else {
    header('Location: ../auth/login.php');
}
exit;
