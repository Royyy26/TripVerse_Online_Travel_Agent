<?php
session_start();

if (!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified'] !== true) {
    echo "session_expired";
    exit;
}

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

$email = $_SESSION['verified_email'] ?? '';

require_once __DIR__ . '/db_config.php';

$sql = "SELECT c.customer_id, c.nama, c.email, c.id_user FROM customer c WHERE c.email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "user_not_found";
    $conn->close();
    exit;
}

$customer = $result->fetch_assoc();
$user_id = $customer['id_user'];

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
    $log_sql = "INSERT INTO activity_log (user_id, action_type, action_description, created_at)
                VALUES (?, 'reset_password', 'Password reset via forgot password', NOW())";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("s", $user_id);
    $log_stmt->execute();

    session_destroy();
    echo "success";
} else {
    echo "update_failed: " . $conn->error;
}

$conn->close();
