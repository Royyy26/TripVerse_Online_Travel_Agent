<?php
/**
 * Supplier Folder - TripVerse
 * 
 * This folder contains all supplier/owner pages.
 * Direct access redirects to dashboard or login.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['id_user']) && in_array($_SESSION['role'] ?? '', ['owner', 'admin'])) {
    header('Location: owner_dashboard.php');
} else {
    header('Location: ../auth/login.php');
}
exit;
