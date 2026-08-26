<?php
// reset_password_process.php
session_start();

// Debug
$debug = "[" . date('Y-m-d H:i:s') . "] ";
$debug .= "POST: " . print_r($_POST, true) . "\n";
$debug .= "SESSION: " . print_r($_SESSION, true) . "\n";
file_put_contents('reset_debug.txt', $debug, FILE_APPEND);

// ========================== VALIDASI SESSION =============================
if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
    $debug = "[" . date('Y-m-d H:i:s') . "] ";
    $debug .= "Session expired or not verified\n";
    file_put_contents('reset_debug.txt', $debug, FILE_APPEND);
    
    echo "session_expired";
    exit;
}

// ========================== VALIDASI INPUT =============================
$new_password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if (empty($new_password) || empty($confirm_password)) {
    echo "missing_fields";
    exit;
}

if ($new_password !== $confirm_password) {
    echo "password_mismatch";
    exit;
}

// Gunakan nomor HP dari session (sudah diverifikasi)
$phone = $_SESSION['verified_phone'] ?? '';
$phone_raw = $_SESSION['reset_phone_raw'] ?? '';

$debug = "[" . date('Y-m-d H:i:s') . "] ";
$debug .= "Using phone from session: $phone (raw: $phone_raw)\n";
file_put_contents('reset_debug.txt', $debug, FILE_APPEND);

// ========================== DATABASE CONNECTION ===========================
require_once __DIR__ . '/db_config.php';

// ========================== CARI USER BERDASARKAN NOMOR HP ================
// Cari dengan berbagai format kemungkinan
$sql = "SELECT c.customer_id, c.nama, c.email, c.no_hp, c.id_user 
        FROM customer c 
        WHERE c.no_hp LIKE ? OR c.no_hp LIKE ? OR c.no_hp LIKE ? OR c.no_hp LIKE ?";
$stmt = $conn->prepare($sql);

// Format pencarian:
$format1 = '%' . substr($phone, 2) . '%';    // Tanpa 62 (081...)
$format2 = '0' . substr($phone, 2) . '%';    // 081...
$format3 = '+62' . substr($phone, 2) . '%';  // +6281...
$format4 = $phone . '%';                     // 6281...

$debug = "[" . date('Y-m-d H:i:s') . "] ";
$debug .= "Searching formats: $format1, $format2, $format3, $format4\n";
file_put_contents('reset_debug.txt', $debug, FILE_APPEND);

$stmt->bind_param("ssss", $format1, $format2, $format3, $format4);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $debug = "[" . date('Y-m-d H:i:s') . "] ";
    $debug .= "No customer found with phone\n";
    file_put_contents('reset_debug.txt', $debug, FILE_APPEND);
    
    echo "user_not_found";
    $conn->close();
    exit;
}

// Ambil data customer pertama yang ditemukan
$customer = $result->fetch_assoc();
$customer_id = $customer['customer_id'];
$user_id = $customer['id_user'];
$nama = $customer['nama'];
$no_hp = $customer['no_hp'];

$debug = "[" . date('Y-m-d H:i:s') . "] ";
$debug .= "Found customer: ID=$customer_id, Name=$nama, Phone=$no_hp, UserID=$user_id\n";
file_put_contents('reset_debug.txt', $debug, FILE_APPEND);

if (empty($user_id)) {
    echo "user_id_not_found";
    $conn->close();
    exit;
}

$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

$update_sql = "UPDATE user SET password = ? WHERE id_user = ?";
$update_stmt = $conn->prepare($update_sql);
$update_stmt->bind_param("ss", $hashed_password, $user_id);

if ($update_stmt->execute()) {
    $debug = "[" . date('Y-m-d H:i:s') . "] ";
    $debug .= "Password updated SUCCESSFULLY for user_id: $user_id\n";
    file_put_contents('reset_debug.txt', $debug, FILE_APPEND);
    
    // Log aktivitas (opsional)
    $log_sql = "INSERT INTO activity_log (user_id, action_type, action_description, created_at) 
                VALUES (?, 'reset_password', 'Password reset via forgot password', NOW())";
    $log_stmt = $conn->prepare($log_sql);
    $log_user_id = "USR" . str_pad($user_id, 3, '0', STR_PAD_LEFT);
    $log_stmt->bind_param("s", $log_user_id);
    $log_stmt->execute();
    
    // Clear semua session
    session_destroy();
    
    echo "success";
} else {
    $debug = "[" . date('Y-m-d H:i:s') . "] ";
    $debug .= "UPDATE FAILED: " . $conn->error . "\n";
    file_put_contents('reset_debug.txt', $debug, FILE_APPEND);
    
    echo "update_failed: " . $conn->error;
}

$conn->close();
?>