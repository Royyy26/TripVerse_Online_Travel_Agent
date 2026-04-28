<?php

if (!isset($_POST['no_hp']) || !isset($_POST['otp'])) {
    echo "missing_data";
    exit;
}

$phone = preg_replace("/[^0-9]/", "", $_POST['no_hp']);
$otp_user = trim($_POST['otp']);

// Normalisasi nomor
if (strpos($phone, "08") === 0) {
    $phone = "62" . substr($phone, 1);
}
if (strpos($phone, "62") !== 0) {
    $phone = "62" . $phone;
}

$jsonFile = "otp_storage.json";

if (!file_exists($jsonFile)) {
    echo "failed";
    exit;
}

$data = json_decode(file_get_contents($jsonFile), true);

// Nomor tidak ada dalam JSON
if (!isset($data[$phone])) {
    echo "failed";
    exit;
}

$storedOtp = $data[$phone]["otp"];
$expiresAt = $data[$phone]["expires_at"];

// OTP expired?
if (time() > strtotime($expiresAt)) {
    unset($data[$phone]); // hapus OTP

    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

    echo "expired";
    exit;
}

// Cek OTP benar?
if ($otp_user == $storedOtp) {

    unset($data[$phone]); // hapus OTP setelah sukses
    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

    echo "success";

} else {
    echo "failed";
}
?>
