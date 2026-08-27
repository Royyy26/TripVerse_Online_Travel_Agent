<?php
/**
 * Customer Folder - TripVerse
 * 
 * This folder contains all customer-facing pages.
 * Direct access redirects to home or login.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
if (isset($_SESSION['id_user'])) {
    header('Location: home.php');
} else {
    header('Location: ../auth/login.php');
}
exit;
