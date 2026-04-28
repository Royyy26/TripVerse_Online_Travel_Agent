<?php
require "fonnte_api.php";

// Pastikan nomor diterima
if (!isset($_POST['no_hp'])) {
    echo "no_hp_missing";
    exit;
}

// Ambil nomor HP
$rawPhone = $_POST['no_hp'];

// Bersihkan nomor: hilangkan spasi, +, simbol, ketik salah
$phone = preg_replace("/[^0-9]/", "", $rawPhone);

// Jika user kirim format 08xxxxx → ubah ke 628xxxxx
if (strpos($phone, "08") === 0) {
    $phone = "62" . substr($phone, 1);
}

// Jika user kirim +62xxxx → preg_replace sudah hilangkan + → jadi 62xxxx
if (strpos($phone, "62") !== 0) {
    $phone = "62" . $phone;
}

// Validasi nomor minimal panjang 10 digit
if (strlen($phone) < 10) {
    echo "invalid_number";
    exit;
}

// Generate OTP 6 digit
$otp = random_int(100000, 999999);

// Lokasi file JSON
$jsonFile = "otp_storage_forgot.json";

// Load JSON
$data = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];

// Simpan OTP
$data[$phone] = [
    "otp" => $otp,
    "expires_at" => date("Y-m-d H:i:s", strtotime("+5 minutes"))
];

// Save kembali
file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

// Pesan untuk dikirim ke WA
$message = "
Kode OTP TripVerse Anda: *$otp*

Berlaku selama 5 menit.
JANGAN berikan kode ini kepada siapapun.
";

// Kirim melalui Fonnte
$response = sendWhatsAppMessage($phone, $message);

// Debug respon API
error_log("[OTP] SEND TO $phone | OTP $otp | RESP: " . json_encode($response));

// Output ke frontend
echo "sent";
?>
