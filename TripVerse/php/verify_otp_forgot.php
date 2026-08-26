<?php
session_start();

if (!isset($_POST['email']) || !isset($_POST['otp'])) {
    echo "missing_data";
    exit;
}

$email = trim($_POST['email']);
$otp_user = trim($_POST['otp']);

$jsonFile = "otp_storage_forgot.json";

if (!file_exists($jsonFile)) {
    echo "failed";
    exit;
}

$data = json_decode(file_get_contents($jsonFile), true);

if (!isset($data[$email])) {
    echo "failed";
    exit;
}

$storedOtp = $data[$email]["otp"];
$expiresAt = $data[$email]["expires_at"];

if (time() > strtotime($expiresAt)) {
    unset($data[$email]);
    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));
    echo "expired";
    exit;
}

if ($otp_user == $storedOtp) {
    unset($data[$email]);
    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

    $_SESSION['otp_verified'] = true;
    $_SESSION['verified_email'] = $email;
    $_SESSION['verified_time'] = time();

    echo "success";
} else {
    echo "failed";
}
