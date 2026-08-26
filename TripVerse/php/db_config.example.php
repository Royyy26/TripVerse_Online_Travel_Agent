<?php
/**
 * Template for db_config.php (which is gitignored because it holds real
 * credentials). Copy this file to db_config.php and fill in your own local
 * and production database details.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    $tvIsLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);

    if ($tvIsLocal) {
        $host = 'localhost';
        $username = 'root';
        $password = '';
        $database = 'tripverse';
    } else {
        $host = 'your-production-db-host';
        $username = 'your-production-db-username';
        $password = 'your-production-db-password';
        $database = 'your-production-db-name';
    }

    $conn = new mysqli($host, $username, $password, $database);
    if ($conn->connect_error) {
        die('Koneksi gagal: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');

    $user = $username;
    $pass = $password;
    $dbname = $database;
}
