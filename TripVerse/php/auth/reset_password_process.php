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

// Validate password strength
if (strlen($new_password) < 8) {
    echo "password_too_short";
    exit;
}

if (!preg_match('/[A-Z]/', $new_password)) {
    echo "password_no_uppercase";
    exit;
}

if (!preg_match('/[0-9]/', $new_password)) {
    echo "password_no_number";
    exit;
}

$email = $_SESSION['verified_email'] ?? '';

if (empty($email)) {
    echo "session_expired";
    exit;
}

require_once __DIR__ . '/../db_config.php';

// Search in user table directly - works for ALL roles (admin, customer, supplier)
$sql = "SELECT id_user, email FROM user WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    // Fallback: check customer table email (some users may have different email)
    $sql2 = "SELECT u.id_user FROM customer c JOIN user u ON c.id_user = u.id_user WHERE c.email = ?";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("s", $email);
    $stmt2->execute();
    $result2 = $stmt2->get_result();

    if ($result2->num_rows == 0) {
        echo "user_not_found";
        $conn->close();
        exit;
    }
    $user = $result2->fetch_assoc();
    $user_id = $user['id_user'];
    $stmt2->close();
} else {
    $user = $result->fetch_assoc();
    $user_id = $user['id_user'];
}
$stmt->close();

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

    // Clear reset session data
    unset($_SESSION['otp_verified']);
    unset($_SESSION['verified_email']);
    unset($_SESSION['otp_forgot']);

    echo "success";
} else {
    echo "update_failed";
}

$update_stmt->close();
$conn->close();
