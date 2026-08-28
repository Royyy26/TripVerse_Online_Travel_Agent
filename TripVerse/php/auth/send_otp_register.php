<?php
require_once __DIR__ . '/../smtp_mailer.php';

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
$otpDigits = str_split((string)$otp);

// Lokasi file JSON (terpisah dari OTP lupa password agar kedua alur tidak
// saling menimpa jika dipakai bersamaan untuk email yang sama)
$jsonFile = __DIR__ . "/../otp_storage_register.json";

// Load JSON
$data = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];

// Simpan OTP
$data[$email] = [
    "otp" => $otp,
    "expires_at" => date("Y-m-d H:i:s", strtotime("+5 minutes"))
];

// Save kembali
file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

// Build beautiful HTML email
$subject = "🎉 Verifikasi Pendaftaran Akun TripVerse";
$body = '<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#f0f2f5;font-family:\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f2f5;padding:40px 20px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,0.08);">

<!-- Header Gradient -->
<tr>
<td style="background:linear-gradient(135deg,#0F172B 0%,#1e3a5f 50%,#2d5a87 100%);padding:40px 32px 32px;text-align:center;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center" style="padding-bottom:16px;">
        <div style="width:64px;height:64px;background:linear-gradient(135deg,#FEA116,#FF7A3D);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:28px;line-height:64px;">🎉</div>
    </td></tr>
    <tr><td align="center">
        <h1 style="margin:0;font-size:26px;font-weight:700;color:#ffffff;letter-spacing:-0.5px;">Trip<span style="color:#FEA116;">Verse</span></h1>
        <p style="margin:8px 0 0;font-size:14px;color:rgba(255,255,255,0.7);font-weight:400;">Verifikasi Pendaftaran Akun</p>
    </td></tr>
    </table>
</td>
</tr>

<!-- Body Content -->
<tr>
<td style="padding:36px 32px;">
    <p style="margin:0 0 8px;font-size:16px;color:#1a1a2e;font-weight:600;">Selamat datang! 👋</p>
    <p style="margin:0 0 28px;font-size:14px;color:#64748b;line-height:1.7;">
        Terima kasih sudah mendaftar di TripVerse. Gunakan kode OTP di bawah ini untuk menyelesaikan pendaftaran akun Anda.
    </p>

    <!-- OTP Code Box -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
    <tr><td align="center">
        <div style="background:linear-gradient(135deg,#fff8f0,#fff3e6);border:2px dashed #FEA116;border-radius:16px;padding:24px 20px;text-align:center;">
            <p style="margin:0 0 12px;font-size:12px;color:#b07d10;font-weight:600;text-transform:uppercase;letter-spacing:2px;">Kode Verifikasi Anda</p>
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
            <tr>
                <td style="padding:0 4px;"><div style="width:44px;height:54px;background:#ffffff;border:2px solid #FEA116;border-radius:10px;font-size:26px;font-weight:700;color:#0F172B;line-height:54px;text-align:center;box-shadow:0 2px 8px rgba(254,161,22,0.15);">' . $otpDigits[0] . '</div></td>
                <td style="padding:0 4px;"><div style="width:44px;height:54px;background:#ffffff;border:2px solid #FEA116;border-radius:10px;font-size:26px;font-weight:700;color:#0F172B;line-height:54px;text-align:center;box-shadow:0 2px 8px rgba(254,161,22,0.15);">' . $otpDigits[1] . '</div></td>
                <td style="padding:0 4px;"><div style="width:44px;height:54px;background:#ffffff;border:2px solid #FEA116;border-radius:10px;font-size:26px;font-weight:700;color:#0F172B;line-height:54px;text-align:center;box-shadow:0 2px 8px rgba(254,161,22,0.15);">' . $otpDigits[2] . '</div></td>
                <td style="padding:0 8px;font-size:20px;color:#ccc;">-</td>
                <td style="padding:0 4px;"><div style="width:44px;height:54px;background:#ffffff;border:2px solid #FEA116;border-radius:10px;font-size:26px;font-weight:700;color:#0F172B;line-height:54px;text-align:center;box-shadow:0 2px 8px rgba(254,161,22,0.15);">' . $otpDigits[3] . '</div></td>
                <td style="padding:0 4px;"><div style="width:44px;height:54px;background:#ffffff;border:2px solid #FEA116;border-radius:10px;font-size:26px;font-weight:700;color:#0F172B;line-height:54px;text-align:center;box-shadow:0 2px 8px rgba(254,161,22,0.15);">' . $otpDigits[4] . '</div></td>
                <td style="padding:0 4px;"><div style="width:44px;height:54px;background:#ffffff;border:2px solid #FEA116;border-radius:10px;font-size:26px;font-weight:700;color:#0F172B;line-height:54px;text-align:center;box-shadow:0 2px 8px rgba(254,161,22,0.15);">' . $otpDigits[5] . '</div></td>
            </tr>
            </table>
        </div>
    </td></tr>
    </table>

    <!-- Timer Warning -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
    <tr>
        <td width="36" valign="top" style="padding-right:12px;">
            <div style="width:36px;height:36px;background:#fff3e6;border-radius:10px;text-align:center;line-height:36px;font-size:18px;">⏱️</div>
        </td>
        <td>
            <p style="margin:0;font-size:13px;color:#b07d10;font-weight:600;">Berlaku 5 menit</p>
            <p style="margin:2px 0 0;font-size:12px;color:#94a3b8;">Kode ini akan kedaluwarsa pada ' . date("H:i", strtotime("+5 minutes")) . ' WIB</p>
        </td>
    </tr>
    </table>

    <!-- Security Notice -->
    <div style="background:#f8fafc;border-radius:12px;padding:16px 20px;border-left:4px solid #ef4444;">
        <p style="margin:0 0 4px;font-size:13px;color:#dc2626;font-weight:600;">⚠️ Peringatan Keamanan</p>
        <p style="margin:0;font-size:12px;color:#64748b;line-height:1.6;">
            Jangan pernah membagikan kode OTP ini kepada siapapun, termasuk pihak yang mengaku dari TripVerse. Tim kami tidak akan pernah meminta kode ini.
        </p>
    </div>
</td>
</tr>

<!-- Divider -->
<tr><td style="padding:0 32px;"><hr style="border:none;border-top:1px solid #f1f5f9;margin:0;"></td></tr>

<!-- Footer -->
<tr>
<td style="padding:24px 32px 32px;text-align:center;">
    <p style="margin:0 0 4px;font-size:13px;color:#94a3b8;">Tidak merasa mendaftar?</p>
    <p style="margin:0 0 20px;font-size:12px;color:#94a3b8;">Abaikan email ini. Tidak ada akun yang akan dibuat.</p>
    <p style="margin:0;font-size:11px;color:#cbd5e1;">© ' . date("Y") . ' TripVerse. All rights reserved.</p>
</td>
</tr>

</table>
</td></tr>
</table>
</body>
</html>';

// Kirim email
$result = sendOtpEmail($email, '', $subject, $body);

// Debug respon
error_log("[OTP-REGISTER] SEND TO $email | OTP $otp | RESULT: " . (($result === true) ? 'ok' : $result));

if ($result === true) {
    echo "sent";
} else {
    echo "send_failed";
}
