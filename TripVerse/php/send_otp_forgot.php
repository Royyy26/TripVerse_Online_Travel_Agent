<?php
require_once __DIR__ . '/smtp_mailer.php';

// Pastikan email diterima
if (!isset($_POST['email'])) {
    echo "email_missing";
    exit;
}

$email = trim($_POST['email']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "invalid_email";
    exit;
}

// Generate OTP 6 digit
$otp = random_int(100000, 999999);

// Lokasi file JSON
$jsonFile = "otp_storage_forgot.json";

// Load JSON
$data = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];

// Simpan OTP
$data[$email] = [
    "otp" => $otp,
    "expires_at" => date("Y-m-d H:i:s", strtotime("+5 minutes"))
];

// Save kembali
file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

// Isi email
$subject = "Kode OTP TripVerse Anda";
$body = "Kode OTP TripVerse Anda: $otp\n\n"
      . "Berlaku selama 5 menit.\n"
      . "JANGAN berikan kode ini kepada siapapun.";

// Kirim email
$result = sendOtpEmail($email, '', $subject, $body);

// Debug respon
error_log("[OTP-EMAIL] SEND TO $email | OTP $otp | RESULT: " . (($result === true) ? 'ok' : $result));

if ($result === true) {
    echo "sent";
} else {
    echo "send_failed";
}
