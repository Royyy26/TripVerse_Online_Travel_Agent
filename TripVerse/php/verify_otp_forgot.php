<?php
// verify_otp_forgot.php
session_start(); // ⬅️ PASTIKAN di AWAL FILE!
error_reporting(0);

// Debug log
$debug = "[" . date('Y-m-d H:i:s') . "] ";
$debug .= "POST: " . print_r($_POST, true) . "\n";
file_put_contents('verify_debug.txt', $debug, FILE_APPEND);

if (!isset($_POST['no_hp']) || !isset($_POST['otp'])) {
    echo "missing_data";
    exit;
}

$phone = preg_replace("/[^0-9]/", "", $_POST['no_hp']);
$otp_user = trim($_POST['otp']);

// Debug
$debug = "[" . date('Y-m-d H:i:s') . "] ";
$debug .= "Phone raw: " . $phone . ", OTP: " . $otp_user . "\n";
file_put_contents('verify_debug.txt', $debug, FILE_APPEND);

// Normalisasi nomor (format yang konsisten)
if (strpos($phone, "08") === 0) {
    $phone = "62" . substr($phone, 1);
}
if (strpos($phone, "62") !== 0) {
    $phone = "62" . $phone;
}

$debug = "[" . date('Y-m-d H:i:s') . "] ";
$debug .= "Phone normalized: " . $phone . "\n";
file_put_contents('verify_debug.txt', $debug, FILE_APPEND);

// Simpan di session untuk digunakan nanti
$_SESSION['reset_phone_raw'] = $_POST['no_hp']; // Format asli dari user
$_SESSION['reset_phone_normalized'] = $phone;   // Format 62...

$jsonFile = "otp_storage_forgot.json";

if (!file_exists($jsonFile)) {
    $debug = "[" . date('Y-m-d H:i:s') . "] ";
    $debug .= "JSON file not found: " . $jsonFile . "\n";
    file_put_contents('verify_debug.txt', $debug, FILE_APPEND);
    
    echo "failed";
    exit;
}

$data = json_decode(file_get_contents($jsonFile), true);

// Debug data
$debug = "[" . date('Y-m-d H:i:s') . "] ";
$debug .= "JSON data for $phone: " . print_r(isset($data[$phone]) ? $data[$phone] : "NOT FOUND", true) . "\n";
file_put_contents('verify_debug.txt', $debug, FILE_APPEND);

// Cek jika nomor ada
if (!isset($data[$phone])) {
    $debug = "[" . date('Y-m-d H:i:s') . "] ";
    $debug .= "Phone $phone not found in JSON\n";
    file_put_contents('verify_debug.txt', $debug, FILE_APPEND);
    
    echo "failed";
    exit;
}

$storedOtp = $data[$phone]["otp"];
$expiresAt = $data[$phone]["expires_at"];

$debug = "[" . date('Y-m-d H:i:s') . "] ";
$debug .= "Stored OTP: " . $storedOtp . ", Expires: " . $expiresAt . "\n";
file_put_contents('verify_debug.txt', $debug, FILE_APPEND);

// Cek expiration
if (time() > strtotime($expiresAt)) {
    unset($data[$phone]);
    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));
    
    $debug = "[" . date('Y-m-d H:i:s') . "] ";
    $debug .= "OTP EXPIRED\n";
    file_put_contents('verify_debug.txt', $debug, FILE_APPEND);
    
    echo "expired";
    exit;
}

// Cek OTP
if ($otp_user == $storedOtp) {
    unset($data[$phone]);
    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));
    
    // Set session untuk reset password
    $_SESSION['otp_verified'] = true;
    $_SESSION['verified_phone'] = $phone;
    $_SESSION['verified_time'] = time();
    
    $debug = "[" . date('Y-m-d H:i:s') . "] ";
    $debug .= "OTP SUCCESS - Session started\n";
    $debug .= "Session data: " . print_r($_SESSION, true) . "\n";
    file_put_contents('verify_debug.txt', $debug, FILE_APPEND);
    
    echo "success";
} else {
    $debug = "[" . date('Y-m-d H:i:s') . "] ";
    $debug .= "OTP FAILED - Input: " . $otp_user . ", Stored: " . $storedOtp . "\n";
    file_put_contents('verify_debug.txt', $debug, FILE_APPEND);
    
    echo "failed";
}

exit;
?>