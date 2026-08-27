<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['id_user']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

require 'connect.php';

$id_user = $_SESSION['id_user'];

// Manually create an owner account (merged from the former standalone owner_manage.php,
// which sat unreachable from any nav link — admins now do this from the "Manage Owners" tab).
$ownerCreateMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_owner'])) {
    $newFirst = trim($_POST['first_name'] ?? '');
    $newLast = trim($_POST['last_name'] ?? '');
    $newUsername = trim($_POST['username'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    $newPhone = trim($_POST['no_hp'] ?? '');
    $newPassword = trim($_POST['password'] ?? '');

    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $ownerCreateMessage = ['type' => 'error', 'text' => 'Format email tidak valid untuk owner. Gunakan format yang benar (contoh: owner@domain.com).'];
    } elseif ($newFirst && $newUsername && $newEmail && $newPassword) {
        $emailCheck = $conn->prepare("SELECT id_user FROM user WHERE email = ?");
        $emailCheck->bind_param("s", $newEmail);
        $emailCheck->execute();
        $emailTaken = $emailCheck->get_result()->num_rows > 0;
        $emailCheck->close();

        if ($emailTaken) {
            $ownerCreateMessage = ['type' => 'error', 'text' => 'Email sudah digunakan. Silakan gunakan email lain.'];
        } else {
            $lastIdRes = $conn->query("SELECT id_user FROM user WHERE id_user LIKE 'OWN%' ORDER BY id_user DESC LIMIT 1");
            $newOwnerId = 'OWN001';
            if ($lastIdRes && $lastRow = $lastIdRes->fetch_assoc()) {
                $newOwnerId = 'OWN' . str_pad((int)substr($lastRow['id_user'], 3) + 1, 3, '0', STR_PAD_LEFT);
            }

            $createStmt = $conn->prepare("INSERT INTO user (id_user, first_name, last_name, username, no_hp, email, password, role, approved, approved_by, approved_at) VALUES (?,?,?,?,?,?,?, 'owner', 'approved', ?, NOW())");
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $createStmt->bind_param('ssssssss', $newOwnerId, $newFirst, $newLast, $newUsername, $newPhone, $newEmail, $hashedPassword, $id_user);
            if ($createStmt->execute()) {
                $ownerCreateMessage = ['type' => 'success', 'text' => 'Owner berhasil dibuat: ' . htmlspecialchars($newOwnerId) . ' | Email: ' . htmlspecialchars($newEmail)];
            } else {
                $ownerCreateMessage = ['type' => 'error', 'text' => 'Gagal membuat owner: ' . $createStmt->error];
            }
            $createStmt->close();
        }
    } else {
        $ownerCreateMessage = ['type' => 'error', 'text' => 'Lengkapi data wajib (nama depan, username, email, password).'];
    }
}

// All owner accounts, however they were approved, for the "Manage Owners" tab list
$allOwnersRes = $conn->query("SELECT id_user, username, email, first_name, last_name FROM user WHERE role = 'owner' ORDER BY id_user DESC");

// Get admin data
$query = "SELECT username, email, first_name, last_name, profile_picture FROM user WHERE id_user = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $id_user);
$stmt->execute();
$result = $stmt->get_result();

if ($data = $result->fetch_assoc()) {
    $username = $data['username'];
    $email = $data['email'];
    $firstName = $data['first_name'];
    $lastName = $data['last_name'];
    $foto = $data['profile_picture'] ?: '../images/default.jpg';
} else {
    $username = "Unknown";
    $email = "unknown@tripverse.com";
    $firstName = $lastName = "-";
    $foto = "../images/default.jpg";
}

// Count statistics
$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;

// Fetch pending suppliers (approved is NULL, empty string, 'pending', or numeric 0)
$pendingSql = "SELECT id_user, first_name, last_name, username, email, no_hp, approved, approved_at 
               FROM user 
               WHERE role = 'owner' 
               AND (approved IS NULL OR approved = '' OR approved = 'pending' OR approved = '0') 
               ORDER BY id_user DESC";
$pendingRes = $conn->query($pendingSql);
$pendingCount = $pendingRes ? $pendingRes->num_rows : 0;

// Fetch approved suppliers (approved = 'approved' or numeric 1)
$approvedSql = "SELECT u.id_user, u.first_name, u.last_name, u.username, u.email, u.no_hp, 
                       u.approved_at, u.approved,
                       approver.username as approved_by_name,
                       approver.first_name as approver_first,
                       approver.last_name as approver_last
                FROM user u
                LEFT JOIN user approver ON u.approved_by = approver.id_user
                WHERE u.role = 'owner' AND (u.approved = 'approved' OR u.approved = '1')
                ORDER BY u.approved_at DESC 
                LIMIT 20";
$approvedRes = $conn->query($approvedSql);
$approvedCount = $approvedRes ? $approvedRes->num_rows : 0;

// Fetch rejected suppliers (approved = 'rejected' or numeric 2)
$rejectedSql = "SELECT u.id_user, u.first_name, u.last_name, u.username, u.email, u.no_hp, 
                       u.approved_at, u.approved,
                       approver.username as rejected_by_name,
                       approver.first_name as rejecter_first,
                       approver.last_name as rejecter_last
                FROM user u
                LEFT JOIN user approver ON u.approved_by = approver.id_user
                WHERE u.role = 'owner' AND (u.approved = 'rejected' OR u.approved = '2')
                ORDER BY u.approved_at DESC 
                LIMIT 20";
$rejectedRes = $conn->query($rejectedSql);
$rejectedCount = $rejectedRes ? $rejectedRes->num_rows : 0;

// Notifikasi (sama seperti dashboard.php: jumlah booking berstatus Pending)
$notifCountResult = $conn->query("SELECT COUNT(*) as notifications FROM booking_hotel WHERE status = 'Pending'");
$notificationCount = $notifCountResult ? ($notifCountResult->fetch_assoc()['notifications'] ?? 0) : 0;

// Get all time stats
$totalSuppliers = $pendingCount + $approvedCount + $rejectedCount;
$approvalRate = $totalSuppliers > 0 ? round(($approvedCount / $totalSuppliers) * 100, 1) : 0;

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Approvals - TripVerse Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../css/dashboard.css?v=1.8.0">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #FF7A3D;
            --primary-light: #FFB37A;
            --primary-dark: #C2530F;
            --primary-hover: #FF7A3D;
            --secondary-color: #0F172B;
            --secondary-light: #2a3a56;
            --secondary-dark: #060a14;
            --secondary-hover: #1a2438;
            --success-color: #1baf7a;
            --success-light: #3fcf9c;
            --success-dark: #128a5e;
            --success-hover: #17996b;
            --warning-color: #eda100;
            --warning-light: #f4b73a;
            --warning-dark: #b97e00;
            --warning-hover: #d69200;
            --danger-color: #e34948;
            --danger-light: #ef6e6d;
            --danger-dark: #b33130;
            --danger-hover: #cf3c3b;
            --info-color: #2a78d6;
            --info-light: #6fa8e8;
            --info-dark: #1c5aa3;
            --info-hover: #2468bd;
            --light-bg: #f8f9fa;
            --dark-bg: #0F172B;
            --border-color: #e0e0e0;
            --border-radius: 12px;
            --box-shadow: 0 4px 12px rgba(15, 23, 43, 0.08);
            --box-shadow-hover: 0 8px 24px rgba(15, 23, 43, 0.12);
            --box-shadow-elevated: 0 12px 32px rgba(15, 23, 43, 0.15);
            --text-dark: #1e2635;
            --text-medium: #4b5566;
            --text-light: #6b7280;
            --text-white: #ffffff;
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            --gradient-primary: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            --gradient-success: linear-gradient(135deg, var(--success-color) 0%, var(--success-dark) 100%);
            --gradient-warning: linear-gradient(135deg, var(--warning-color) 0%, var(--warning-dark) 100%);
            --gradient-danger: linear-gradient(135deg, var(--danger-color) 0%, var(--danger-dark) 100%);
            --gradient-info: linear-gradient(135deg, var(--info-color) 0%, var(--info-dark) 100%);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Roboto', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f2f5 100%);
            color: var(--text-dark);
            line-height: 1.5;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.98) translateY(5px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.02);
            }

            100% {
                transform: scale(1);
            }
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-5px);
            }
        }

        .landing-page {
            animation: fadeIn 0.4s ease-out;
            max-width: 1200px;
            margin: 0 auto;
            padding: 16px;
        }

        .page-header {
            background: var(--gradient-primary);
            color: var(--text-white);
            padding: 32px;
            border-radius: var(--border-radius);
            margin: 16px 0 32px;
            box-shadow: var(--box-shadow-elevated);
            animation: fadeIn 0.4s ease-out 0.1s both;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--secondary-color), var(--info-color), var(--success-color));
        }

        .page-header h1 {
            margin: 0 0 12px 0;
            font-size: 32px;
            display: flex;
            align-items: center;
            gap: 16px;
            font-weight: 700;
            position: relative;
            z-index: 1;
        }

        .page-header h1 i {
            font-size: 32px;
            background: rgba(255, 255, 255, 0.15);
            padding: 12px;
            border-radius: 50%;
            animation: float 3s ease-in-out infinite;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 16px;
            max-width: 600px;
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin: 0 0 40px;
        }

        .stat-card {
            background: var(--text-white);
            padding: 24px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            opacity: 0;
            animation: fadeInUp 0.4s ease-out forwards;
            cursor: pointer;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--box-shadow-hover);
        }

        .stat-card.pending {
            border-left-color: var(--warning-color);
            background: linear-gradient(135deg, #fff8e1 0%, #ffffff 100%);
        }

        .stat-card.approved {
            border-left-color: var(--success-color);
            background: linear-gradient(135deg, #e8f5e9 0%, #ffffff 100%);
        }

        .stat-card.rejected {
            border-left-color: var(--danger-color);
            background: linear-gradient(135deg, #ffebee 0%, #ffffff 100%);
        }

        .stat-card.info {
            border-left-color: var(--info-color);
            background: linear-gradient(135deg, #e3f2fd 0%, #ffffff 100%);
        }

        .stat-card h3 {
            margin: 0 0 12px 0;
            font-size: 13px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-card .value {
            font-size: 36px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 8px;
            line-height: 1;
        }

        .stat-card .sub-value {
            font-size: 13px;
            color: var(--text-light);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .tabs-container {
            background: var(--text-white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            margin: 0 0 40px;
            animation: fadeIn 0.4s ease-out 0.2s both;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .tabs-header {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            background: var(--light-bg);
            padding: 0 2px;
        }

        .tab-button {
            flex: 1;
            padding: 20px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            color: var(--text-light);
            transition: var(--transition);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border-bottom: 3px solid transparent;
            min-height: 60px;
        }

        .tab-button:hover {
            background: rgba(255, 122, 61, 0.05);
            color: var(--primary-color);
        }

        .tab-button.active {
            color: var(--primary-color);
            background: var(--text-white);
            border-bottom-color: var(--primary-color);
        }

        .tab-button .badge {
            background: rgba(0, 0, 0, 0.1);
            color: var(--text-dark);
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
            min-width: 24px;
            text-align: center;
        }

        .tab-button.active .badge {
            background: var(--primary-color);
            color: var(--text-white);
        }

        .tab-content {
            display: none;
            padding: 32px;
            animation: fadeIn 0.3s ease-out;
        }

        .tab-content.active {
            display: block;
        }

        .suppliers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 24px;
        }

        .supplier-card {
            background: var(--text-white);
            border-radius: var(--border-radius);
            padding: 24px;
            border: 1px solid var(--border-color);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            opacity: 0;
            animation: fadeInScale 0.3s ease-out forwards;
            box-shadow: var(--box-shadow);
        }

        .supplier-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-color);
        }

        .supplier-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--box-shadow-hover);
            border-color: var(--primary-color);
        }

        .supplier-card.pending::before {
            background: var(--warning-color);
        }

        .supplier-card.approved::before {
            background: var(--success-color);
        }

        .supplier-card.rejected::before {
            background: var(--danger-color);
        }

        .supplier-card:hover.pending {
            border-color: var(--warning-color);
        }

        .supplier-card:hover.approved {
            border-color: var(--success-color);
        }

        .supplier-card:hover.rejected {
            border-color: var(--danger-color);
        }

        .supplier-card:nth-child(1) {
            animation-delay: 0.05s;
        }

        .supplier-card:nth-child(2) {
            animation-delay: 0.1s;
        }

        .supplier-card:nth-child(3) {
            animation-delay: 0.15s;
        }

        .supplier-card:nth-child(4) {
            animation-delay: 0.2s;
        }

        .supplier-header {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .supplier-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-white);
            font-size: 24px;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(255, 122, 61, 0.3);
            border: 3px solid var(--text-white);
            transition: var(--transition);
        }

        .supplier-card:hover .supplier-avatar {
            transform: scale(1.05);
        }

        .supplier-info {
            flex: 1;
        }

        .supplier-name {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0 0 8px 0;
            line-height: 1.3;
        }

        .supplier-username {
            font-size: 13px;
            color: var(--text-light);
            background: var(--light-bg);
            padding: 6px 12px;
            border-radius: 20px;
            display: inline-block;
            font-weight: 500;
            border: 1px solid var(--border-color);
        }

        .pending-badge,
        .approved-badge,
        .rejected-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            text-transform: uppercase;
            border: 1px solid;
        }

        .pending-badge {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning-dark);
            border-color: rgba(255, 193, 7, 0.2);
        }

        .approved-badge {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success-dark);
            border-color: rgba(76, 175, 80, 0.2);
        }

        .rejected-badge {
            background: rgba(227, 73, 72, 0.1);
            color: var(--danger-dark);
            border-color: rgba(227, 73, 72, 0.2);
        }

        .supplier-details {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 20px;
        }

        .detail-row {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            color: var(--text-dark);
            padding: 8px 0;
            border-bottom: 1px dashed var(--border-color);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row .material-icons {
            font-size: 20px;
            color: var(--primary-color);
            width: 24px;
            text-align: center;
            flex-shrink: 0;
        }

        .supplier-actions,
        .supplier-actions-two,
        .supplier-actions-three {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .btn {
            flex: 1;
            padding: 14px 20px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            text-transform: uppercase;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-view {
            background: var(--gradient-info);
            color: var(--text-white);
        }

        .btn-approve {
            background: var(--gradient-success);
            color: var(--text-white);
        }

        .btn-reject {
            background: var(--gradient-danger);
            color: var(--text-white);
        }

        .btn-loading {
            animation: pulse 1.5s infinite;
        }

        .approval-info {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            font-size: 13px;
            color: var(--text-light);
            background: var(--light-bg);
            padding: 16px;
            border-radius: 10px;
        }

        .approval-info div {
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 32px;
            color: var(--text-light);
            animation: fadeIn 0.4s ease-out;
            background: var(--light-bg);
            border-radius: var(--border-radius);
            border: 2px dashed var(--border-color);
            margin: 32px 0;
            position: relative;
        }

        .empty-state .material-icons {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
            color: var(--primary-color);
            animation: float 3s ease-in-out infinite;
        }

        .empty-state h3 {
            margin: 0 0 12px 0;
            font-size: 22px;
            color: var(--text-dark);
            font-weight: 700;
        }

        .empty-state p {
            margin: 0;
            font-size: 15px;
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.6;
        }

        #notificationContainer {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 350px;
        }

        .notification {
            background: var(--text-white);
            padding: 16px 20px;
            border-radius: 10px;
            color: var(--success-color);
            font-weight: 600;
            box-shadow: var(--box-shadow-elevated);
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
            border-left: 4px solid;
            min-width: 280px;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .notification.success {
            border-left-color: var(--success-color);
            background: rgba(76, 175, 80, 0.05);
        }

        .notification.error {
            border-left-color: var(--danger-color);
            background: rgba(227, 73, 72, 0.05);
        }

        .notification .material-icons {
            font-size: 24px;
        }

        .modal,
        .supplier-detail-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10001;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
            padding: 16px;
        }

        .modal-content,
        .supplier-detail-content {
            background: var(--text-white);
            padding: 32px;
            border-radius: var(--border-radius);
            max-width: 500px;
            width: 100%;
            box-shadow: var(--box-shadow-elevated);
            animation: fadeInScale 0.3s ease-out;
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .supplier-detail-content {
            max-width: 700px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header,
        .supplier-detail-header {
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .modal-header h3,
        .supplier-detail-header h3 {
            margin: 0;
            color: var(--text-dark);
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
        }

        .close-modal,
        .close-detail-modal {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: var(--text-light);
            transition: var(--transition);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close-modal:hover,
        .close-detail-modal:hover {
            color: var(--danger-color);
            background: rgba(227, 73, 72, 0.1);
        }

        .modal-body {
            margin-bottom: 24px;
        }

        .modal-body p {
            font-size: 16px;
            color: var(--text-dark);
            line-height: 1.6;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .supplier-detail-body {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }

        .detail-section {
            background: var(--light-bg);
            padding: 24px;
            border-radius: 10px;
            border-left: 4px solid var(--primary-color);
            position: relative;
        }

        .detail-section h4 {
            margin: 0 0 20px 0;
            color: var(--primary-color);
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .detail-grid {
            display: grid;
            gap: 16px;
        }

        .detail-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        }

        .detail-item:last-child {
            border-bottom: none;
        }

        .detail-icon {
            color: var(--primary-color);
            font-size: 20px;
            width: 24px;
            text-align: center;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .detail-label {
            font-weight: 600;
            color: var(--text-dark);
            min-width: 120px;
            font-size: 14px;
            flex-shrink: 0;
        }

        .detail-value {
            color: var(--text-medium);
            flex: 1;
            font-size: 14px;
            word-break: break-word;
            line-height: 1.5;
        }

        .status-badge-large {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
            text-transform: uppercase;
            border: 2px solid;
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.1);
            color: var(--warning-dark);
            border-color: rgba(255, 193, 7, 0.2);
        }

        .status-approved {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success-dark);
            border-color: rgba(76, 175, 80, 0.2);
        }

        .status-rejected {
            background: rgba(227, 73, 72, 0.1);
            color: var(--danger-dark);
            border-color: rgba(227, 73, 72, 0.2);
        }

        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--light-bg);
            border-radius: 6px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--gradient-primary);
            border-radius: 6px;
        }

        .btn:focus,
        .tab-button:focus,
        .close-modal:focus,
        .close-detail-modal:focus {
            outline: 2px solid rgba(255, 122, 61, 0.3);
            outline-offset: 2px;
        }

        @media (max-width: 992px) {
            .suppliers-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .page-header {
                padding: 28px;
            }

            .page-header h1 {
                font-size: 28px;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                padding: 24px;
                margin: 12px 0 24px;
            }

            .page-header h1 {
                font-size: 24px;
                gap: 12px;
            }

            .page-header h1 i {
                font-size: 24px;
                padding: 10px;
            }

            .page-header p {
                font-size: 15px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .stat-card {
                padding: 20px;
            }

            .stat-card .value {
                font-size: 32px;
            }

            .tabs-container {
                margin: 0 0 32px;
            }

            .tabs-header {
                overflow-x: auto;
            }

            .tab-button {
                flex: 0 0 auto;
                padding: 16px 20px;
                min-height: 56px;
                border-right: 1px solid var(--border-color);
            }

            .tab-content {
                padding: 24px;
            }

            .suppliers-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .supplier-card {
                padding: 20px;
            }

            .supplier-header {
                gap: 16px;
            }

            .supplier-avatar {
                width: 56px;
                height: 56px;
                font-size: 20px;
            }

            .supplier-name {
                font-size: 18px;
            }

            .supplier-actions-three,
            .supplier-actions-two,
            .supplier-actions {
                flex-direction: column;
                gap: 10px;
            }

            .btn {
                width: 100%;
                min-height: 44px;
            }

            .modal-content,
            .supplier-detail-content {
                padding: 24px;
                margin: 8px;
            }

            .detail-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 6px;
                padding: 10px 0;
            }

            .detail-label {
                min-width: auto;
                width: 100%;
            }

            .notification {
                min-width: 240px;
                padding: 14px 16px;
            }
        }

        @media (max-width: 576px) {
            .page-header h1 {
                font-size: 22px;
            }

            .page-header p {
                font-size: 14px;
            }

            .stat-card .value {
                font-size: 28px;
            }

            .supplier-name {
                font-size: 18px;
            }

            .modal-content {
                padding: 20px;
            }

            .modal-actions {
                flex-direction: column;
            }

            .btn {
                padding: 12px 16px;
            }

            .notification {
                min-width: 220px;
                padding: 12px 14px;
            }

            .detail-section {
                padding: 20px;
            }
        }

        @media print {

            .tabs-header,
            .supplier-actions,
            .modal,
            .notification {
                display: none !important;
            }

            .page-header {
                background: none !important;
                color: #000 !important;
                box-shadow: none !important;
                border: 1px solid #000;
            }

            .supplier-card {
                break-inside: avoid;
                border: 1px solid #000;
                box-shadow: none !important;
            }

            body {
                background: none !important;
            }
        }

        /* Manage Owners tab (merged from the former standalone owner_manage.php) */
        .owner-manage-message {
            padding: 14px 18px;
            border-radius: var(--border-radius);
            font-weight: 600;
            margin-bottom: 20px;
        }

        .owner-manage-message.success {
            background-color: #e8f7f1;
            color: #128a5e;
        }

        .owner-manage-message.error {
            background-color: #fbecec;
            color: #b33130;
        }

        .owner-form-card,
        .owner-table-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
            border: 1px solid var(--border-color);
        }

        .owner-table-card {
            margin-top: 24px;
            padding: 0;
            overflow-x: auto;
        }

        .owner-form-card h3,
        .owner-table-card h3 {
            margin: 0 0 20px;
            padding: 0 0 0 0;
            font-size: 16px;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .owner-table-card h3 {
            padding: 20px 25px 0;
        }

        .owner-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 18px;
        }

        .owner-form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .owner-form-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-medium);
        }

        .owner-form-group input {
            padding: 12px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-family: inherit;
            font-size: 14px;
            transition: var(--transition);
        }

        .owner-form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 122, 61, 0.12);
        }

        .owner-manage-table {
            width: 100%;
            border-collapse: collapse;
        }

        .owner-manage-table thead th {
            text-align: left;
            padding: 16px 20px;
            background: var(--light-bg);
            color: var(--text-medium);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .owner-manage-table tbody td {
            padding: 14px 20px;
            border-top: 1px solid var(--border-color);
            font-size: 14px;
            color: var(--text-dark);
        }

        .owner-manage-table tbody tr:hover {
            background: var(--light-bg);
        }

        .btn-create-owner {
            flex: none;
            width: auto;
            background: var(--gradient-primary);
            color: var(--text-white);
            padding: 14px 28px;
            text-transform: none;
        }
    </style>
</head>

<body>
    <div class="sidebar" id="sidebar">
        <div class="profile-header">
            <div class="profile-photo-section">
                <div class="profile-photo-container">
                    <img src="../uploads/<?php echo htmlspecialchars($foto); ?>"
                        alt="Profile Photo"
                        class="profile-photo"
                        id="profilePhoto"
                        onerror="this.src='../images/default.jpg'">

                    <div class="profile-overlay">
                        <span class="material-icons">edit</span>
                    </div>

                    <form id="uploadForm" action="dashboard.php" method="POST" enctype="multipart/form-data" style="display:none;">
                        <input type="file" name="profile_photo" id="profileUpload" accept="image/*" />
                    </form>
                </div>

                <div class="profile-info">
                    <h2><?= htmlspecialchars($firstName . ' ' . $lastName); ?></h2>
                    <p><?= htmlspecialchars($email); ?></p>

                    <div class="user-dropdown">
                        <button class="user-info" aria-haspopup="true" aria-expanded="false" onclick="toggleDropdown(this)">
                            <span class="dropdown-text">Manage Account</span>
                            <span class="material-icons dropdown-arrow">expand_more</span>
                        </button>

                        <div class="dropdown-content" role="menu" aria-hidden="true">
                            <a href="profile.php" class="dropdown-item">
                                <span class="material-icons">person</span>
                                <span>Edit Profile</span>
                            </a>
                            <a href="logout.php" class="dropdown-item">
                                <span class="material-icons">logout</span>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <nav>
            <!-- EXECUTIVE OVERVIEW -->
            <a href="dashboard.php">
                <span class="material-icons">dashboard</span>
                <span>Executive Overview</span>
            </a>

            <!-- SUPPLIER APPROVAL -->
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="supplier_approvals.php" class="active">
                    <span class="material-icons">approval</span> <!-- atau groups, person_add -->
                    <span>Supplier Management</span>
                </a>
            <?php endif; ?>

            <!-- PROMO MANAGEMENT -->
            <a href="promo_management.php">
                <span class="material-icons">campaign</span> <!-- atau discount, local_offer -->
                <span>Promo Management</span>
            </a>

            <!-- ANALYTICS & INSIGHTS -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="analyticsDropdown">
                    <span class="material-icons">monitor</span> <!-- atau show_chart, trending_up -->
                    <span>Performance Monitoring</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="analyticsDropdown">
                    <a href="performance_analytics.php">
                        <span class="material-icons">bar_chart</span> <!-- atau assessment -->
                        <span>Performance Statistics</span>
                    </a>
                    <a href="market_analysis.php">
                        <span class="material-icons">trending_up</span> <!-- atau timeline -->
                        <span>Booking Trends</span>
                    </a>
                </div>
            </div>

            <!-- DECISION SUPPORT MODULES -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="decisionDropdown">
                    <span class="material-icons">analytics</span> <!-- atau calculate, functions -->
                    <span>Statistical Analysis</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="decisionDropdown">
                    <a href="revenue_optimization.php">
                        <span class="material-icons">attach_money</span> <!-- atau paid -->
                        <span>Revenue Statistics</span>
                    </a>
                    <a href="occupancy_analysis.php">
                        <span class="material-icons">king_bed</span> <!-- atau hotel -->
                        <span>Occupancy Statistics</span>
                    </a>
                    <a href="alos_analysis.php">
                        <span class="material-icons">calendar_today</span> <!-- atau date_range -->
                        <span>ALOS Statistics</span>
                    </a>
                </div>
            </div>

            <!-- CUSTOMER INTELLIGENCE -->
            <a href="customerdss.php">
                <span class="material-icons">people</span> <!-- atau sentiment_satisfied -->
                <span>Customer Statistics</span>
            </a>

            <!-- LOGOUT -->
            <a href="logout.php">
                <span class="material-icons">exit_to_app</span>
                <span>Logout</span>
            </a>
        </nav>
    </div>

    <main class="main-content" id="main-content">
        <header class="main-header">
            <button id="toggleSidebar" class="menu-toggle" aria-label="Toggle sidebar">
                <span class="material-icons">menu</span>
            </button>

            <div class="header-actions">
                <div class="notification-bell" id="notificationBell" tabindex="0" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                    <span class="material-icons bell-icon">notifications</span>
                    <span class="notification-badge" id="notificationCount"><?= $notificationCount ?></span>
                </div>

                <div class="user-menu">
                    <img src="../uploads/<?php echo htmlspecialchars($foto); ?>" alt="User Avatar" class="user-avatar" />
                </div>
            </div>
        </header>

        <div class="page-header">
            <h1>
                <i class="material-icons">how_to_reg</i>
                Supplier Approval Management
            </h1>
            <p>Review and manage supplier registration requests</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card pending">
                <h3>Pending Requests</h3>
                <div class="value"><?= $pendingCount ?></div>
                <div class="sub-value">Awaiting review</div>
            </div>
            <div class="stat-card approved">
                <h3>Approved</h3>
                <div class="value"><?= $approvedCount ?></div>
                <div class="sub-value">Active suppliers</div>
            </div>
            <div class="stat-card rejected">
                <h3>Rejected</h3>
                <div class="value"><?= $rejectedCount ?></div>
                <div class="sub-value">Declined applications</div>
            </div>
            <div class="stat-card info">
                <h3>Approval Rate</h3>
                <div class="value"><?= $approvalRate ?>%</div>
                <div class="sub-value">Total: <?= $totalSuppliers ?> suppliers</div>
            </div>
        </div>

        <?php $defaultTab = $ownerCreateMessage ? 'manageowners' : 'pending'; ?>
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-button<?= $defaultTab === 'pending' ? ' active' : '' ?>" onclick="switchTab('pending')">
                    <i class="material-icons">hourglass_empty</i>
                    Pending (<?= $pendingCount ?>)
                </button>
                <button class="tab-button" onclick="switchTab('approved')">
                    <i class="material-icons">check_circle</i>
                    Approved (<?= $approvedCount ?>)
                </button>
                <button class="tab-button" onclick="switchTab('rejected')">
                    <i class="material-icons">cancel</i>
                    Rejected (<?= $rejectedCount ?>)
                </button>
                <button class="tab-button<?= $defaultTab === 'manageowners' ? ' active' : '' ?>" onclick="switchTab('manageowners')">
                    <i class="material-icons">group_add</i>
                    Manage Owners
                </button>
            </div>

            <!-- Pending Tab -->
            <div class="tab-content<?= $defaultTab === 'pending' ? ' active' : '' ?>" id="pending-tab">
                <?php if ($pendingCount > 0): ?>
                    <div class="suppliers-grid">
                        <?php
                        if ($pendingRes) {
                            while ($supplier = $pendingRes->fetch_assoc()):
                                $initial = !empty($supplier['first_name']) ? strtoupper(substr($supplier['first_name'], 0, 1)) : '?';
                                $approvalDate = !empty($supplier['approved_at']) ? date('d M Y', strtotime($supplier['approved_at'])) : 'Not reviewed yet';
                                $fullName = trim($supplier['first_name'] . ' ' . $supplier['last_name']);
                                $fullName = !empty($fullName) ? $fullName : 'Unknown Supplier';
                                $statusText = $supplier['approved'] === null ? 'Not Reviewed' : 'Pending Review';
                                $supplierData = [
                                    'id' => $supplier['id_user'],
                                    'name' => $fullName,
                                    'first_name' => $supplier['first_name'],
                                    'last_name' => $supplier['last_name'],
                                    'username' => $supplier['username'],
                                    'email' => $supplier['email'],
                                    'phone' => $supplier['no_hp'],
                                    'status' => 'pending',
                                    'status_text' => $statusText,
                                    'approved_at' => $approvalDate
                                ];
                        ?>
                                <div class="supplier-card">
                                    <div class="supplier-header">
                                        <div class="supplier-avatar">
                                            <?= $initial ?>
                                        </div>
                                        <div class="supplier-info">
                                            <h3 class="supplier-name">
                                                <?= htmlspecialchars($fullName) ?>
                                            </h3>
                                            <span class="pending-badge">
                                                <i class="material-icons" style="font-size: 14px;">hourglass_empty</i>
                                                <?= $statusText ?>
                                            </span>
                                        </div>
                                    </div>

                                    <div class="supplier-details">
                                        <div class="detail-row">
                                            <i class="material-icons">email</i>
                                            <?= htmlspecialchars($supplier['email'] ?: 'No email') ?>
                                        </div>
                                        <div class="detail-row">
                                            <i class="material-icons">phone</i>
                                            <?= htmlspecialchars($supplier['no_hp'] ?: 'No phone') ?>
                                        </div>
                                        <div class="detail-row">
                                            <i class="material-icons">badge</i>
                                            ID: <?= htmlspecialchars($supplier['id_user']) ?>
                                        </div>
                                        <div class="detail-row">
                                            <i class="material-icons">calendar_today</i>
                                            Status: <?= $statusText ?>
                                        </div>
                                    </div>

                                    <div class="supplier-actions-three">
                                        <button class="btn btn-view" onclick="viewSupplierDetail(<?= htmlspecialchars(json_encode($supplierData)) ?>)">
                                            <i class="material-icons">visibility</i>
                                            View Details
                                        </button>
                                        <button class="btn btn-approve" onclick="approveSupplier('<?= htmlspecialchars($supplier['id_user']) ?>', '<?= htmlspecialchars(addslashes($fullName)) ?>')">
                                            <i class="material-icons">check</i>
                                            Approve
                                        </button>
                                        <button class="btn btn-reject" onclick="rejectSupplier('<?= htmlspecialchars($supplier['id_user']) ?>', '<?= htmlspecialchars(addslashes($fullName)) ?>')">
                                            <i class="material-icons">close</i>
                                            Reject
                                        </button>
                                    </div>
                                </div>
                        <?php endwhile;
                        } ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="material-icons">inbox</i>
                        <h3>No Pending Requests</h3>
                        <p>All supplier requests have been processed</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Approved Tab -->
            <div class="tab-content" id="approved-tab">
                <?php if ($approvedCount > 0): ?>
                    <div class="suppliers-grid">
                        <?php
                        if ($approvedRes) {
                            while ($supplier = $approvedRes->fetch_assoc()):
                                $initial = !empty($supplier['first_name']) ? strtoupper(substr($supplier['first_name'], 0, 1)) : '?';
                                $approvalDate = !empty($supplier['approved_at']) ? date('d M Y, H:i', strtotime($supplier['approved_at'])) : 'Unknown';
                                $approverName = '';
                                if (!empty($supplier['approver_first']) && !empty($supplier['approver_last'])) {
                                    $approverName = $supplier['approver_first'] . ' ' . $supplier['approver_last'];
                                } elseif (!empty($supplier['approved_by_name'])) {
                                    $approverName = $supplier['approved_by_name'];
                                } else {
                                    $approverName = 'Admin';
                                }
                                $fullName = trim($supplier['first_name'] . ' ' . $supplier['last_name']);
                                $fullName = !empty($fullName) ? $fullName : 'Unknown Supplier';
                                $supplierData = [
                                    'id' => $supplier['id_user'],
                                    'name' => $fullName,
                                    'first_name' => $supplier['first_name'],
                                    'last_name' => $supplier['last_name'],
                                    'username' => $supplier['username'],
                                    'email' => $supplier['email'],
                                    'phone' => $supplier['no_hp'],
                                    'status' => 'approved',
                                    'status_text' => 'Approved',
                                    'approved_by' => $approverName,
                                    'approved_at' => $approvalDate
                                ];
                        ?>
                                <div class="supplier-card">
                                    <div class="supplier-header">
                                        <div class="supplier-avatar" style="background: var(--gradient-success);">
                                            <?= $initial ?>
                                        </div>
                                        <div class="supplier-info">
                                            <h3 class="supplier-name">
                                                <?= htmlspecialchars($fullName) ?>
                                            </h3>
                                            <span class="approved-badge">
                                                <i class="material-icons" style="font-size: 14px;">check_circle</i>
                                                Approved
                                            </span>
                                        </div>
                                    </div>

                                    <div class="supplier-details">
                                        <div class="detail-row">
                                            <i class="material-icons">email</i>
                                            <?= htmlspecialchars($supplier['email'] ?: 'No email') ?>
                                        </div>
                                        <div class="detail-row">
                                            <i class="material-icons">phone</i>
                                            <?= htmlspecialchars($supplier['no_hp'] ?: 'No phone') ?>
                                        </div>
                                        <div class="detail-row">
                                            <i class="material-icons">badge</i>
                                            ID: <?= htmlspecialchars($supplier['id_user']) ?>
                                        </div>
                                    </div>

                                    <div class="approval-info">
                                        <div>Approved by: <strong><?= htmlspecialchars($approverName) ?></strong></div>
                                        <div>Approved on: <?= $approvalDate ?></div>
                                    </div>

                                    <div class="supplier-actions">
                                        <button class="btn btn-view" style="flex: 1;" onclick="viewSupplierDetail(<?= htmlspecialchars(json_encode($supplierData)) ?>)">
                                            <i class="material-icons">visibility</i>
                                            View Details
                                        </button>
                                    </div>
                                </div>
                        <?php endwhile;
                        } ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="material-icons">inbox</i>
                        <h3>No Approved Suppliers</h3>
                        <p>No suppliers have been approved yet</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Rejected Tab -->
            <div class="tab-content" id="rejected-tab">
                <?php if ($rejectedCount > 0): ?>
                    <div class="suppliers-grid">
                        <?php
                        if ($rejectedRes) {
                            while ($supplier = $rejectedRes->fetch_assoc()):
                                $initial = !empty($supplier['first_name']) ? strtoupper(substr($supplier['first_name'], 0, 1)) : '?';
                                $rejectionDate = !empty($supplier['approved_at']) ? date('d M Y, H:i', strtotime($supplier['approved_at'])) : 'Unknown';
                                $rejecterName = '';
                                if (!empty($supplier['rejecter_first']) && !empty($supplier['rejecter_last'])) {
                                    $rejecterName = $supplier['rejecter_first'] . ' ' . $supplier['rejecter_last'];
                                } elseif (!empty($supplier['rejected_by_name'])) {
                                    $rejecterName = $supplier['rejected_by_name'];
                                } else {
                                    $rejecterName = 'Admin';
                                }
                                $fullName = trim($supplier['first_name'] . ' ' . $supplier['last_name']);
                                $fullName = !empty($fullName) ? $fullName : 'Unknown Supplier';
                                $supplierData = [
                                    'id' => $supplier['id_user'],
                                    'name' => $fullName,
                                    'first_name' => $supplier['first_name'],
                                    'last_name' => $supplier['last_name'],
                                    'username' => $supplier['username'],
                                    'email' => $supplier['email'],
                                    'phone' => $supplier['no_hp'],
                                    'status' => 'rejected',
                                    'status_text' => 'Rejected',
                                    'rejected_by' => $rejecterName,
                                    'rejected_at' => $rejectionDate
                                ];
                        ?>
                                <div class="supplier-card">
                                    <div class="supplier-header">
                                        <div class="supplier-avatar" style="background: var(--gradient-danger);">
                                            <?= $initial ?>
                                        </div>
                                        <div class="supplier-info">
                                            <h3 class="supplier-name">
                                                <?= htmlspecialchars($fullName) ?>
                                            </h3>
                                            <span class="rejected-badge">
                                                <i class="material-icons" style="font-size: 14px;">cancel</i>
                                                Rejected
                                            </span>
                                        </div>
                                    </div>

                                    <div class="supplier-details">
                                        <div class="detail-row">
                                            <i class="material-icons">email</i>
                                            <?= htmlspecialchars($supplier['email'] ?: 'No email') ?>
                                        </div>
                                        <div class="detail-row">
                                            <i class="material-icons">phone</i>
                                            <?= htmlspecialchars($supplier['no_hp'] ?: 'No phone') ?>
                                        </div>
                                        <div class="detail-row">
                                            <i class="material-icons">badge</i>
                                            ID: <?= htmlspecialchars($supplier['id_user']) ?>
                                        </div>
                                    </div>

                                    <div class="approval-info">
                                        <div>Rejected by: <strong><?= htmlspecialchars($rejecterName) ?></strong></div>
                                        <div>Rejected on: <?= $rejectionDate ?></div>
                                    </div>

                                    <div class="supplier-actions">
                                        <button class="btn btn-view" style="flex: 1;" onclick="viewSupplierDetail(<?= htmlspecialchars(json_encode($supplierData)) ?>)">
                                            <i class="material-icons">visibility</i>
                                            View Details
                                        </button>
                                    </div>
                                </div>
                        <?php endwhile;
                        } ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="material-icons">inbox</i>
                        <h3>No Rejected Suppliers</h3>
                        <p>No suppliers have been rejected</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Manage Owners Tab -->
            <div class="tab-content<?= $defaultTab === 'manageowners' ? ' active' : '' ?>" id="manageowners-tab">
                <?php if ($ownerCreateMessage): ?>
                    <div class="owner-manage-message <?= $ownerCreateMessage['type'] ?>"><?= $ownerCreateMessage['text'] ?></div>
                <?php endif; ?>

                <div class="owner-form-card">
                    <h3><i class="material-icons">person_add</i> Buat Owner Baru</h3>
                    <form method="post">
                        <input type="hidden" name="create_owner" value="1">
                        <div class="owner-form-grid">
                            <div class="owner-form-group">
                                <label>First Name*</label>
                                <input type="text" name="first_name" required>
                            </div>
                            <div class="owner-form-group">
                                <label>Last Name</label>
                                <input type="text" name="last_name">
                            </div>
                            <div class="owner-form-group">
                                <label>Username*</label>
                                <input type="text" name="username" required>
                            </div>
                            <div class="owner-form-group">
                                <label>Email*</label>
                                <input type="email" name="email" required>
                            </div>
                            <div class="owner-form-group">
                                <label>No HP</label>
                                <input type="text" name="no_hp">
                            </div>
                            <div class="owner-form-group">
                                <label>Password*</label>
                                <input type="password" name="password" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-create-owner">Buat Owner</button>
                    </form>
                </div>

                <div class="owner-table-card">
                    <h3><i class="material-icons">list_alt</i> Semua Owner</h3>
                    <table class="owner-manage-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nama</th>
                                <th>Username</th>
                                <th>Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($allOwnersRes && $allOwnersRes->num_rows > 0): ?>
                                <?php while ($owner = $allOwnersRes->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($owner['id_user']) ?></td>
                                        <td><?= htmlspecialchars(trim($owner['first_name'] . ' ' . $owner['last_name'])) ?></td>
                                        <td><?= htmlspecialchars($owner['username']) ?></td>
                                        <td><?= htmlspecialchars($owner['email']) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4">Belum ada owner</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Confirmation Modal -->
        <div class="modal" id="confirmationModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="modalTitle">Confirm Action</h3>
                    <button class="close-modal" onclick="closeModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <p id="modalMessage">Are you sure you want to perform this action?</p>
                </div>
                <div class="modal-actions">
                    <button class="btn" onclick="closeModal()" style="background: var(--text-light);">Cancel</button>
                    <button class="btn" id="confirmActionBtn" onclick="confirmAction()">Confirm</button>
                </div>
            </div>
        </div>

        <!-- Supplier Detail Modal -->
        <div class="supplier-detail-modal" id="supplierDetailModal">
            <div class="supplier-detail-content">
                <div class="supplier-detail-header">
                    <h3>
                        <i class="material-icons">person</i>
                        Supplier Details
                    </h3>
                    <button class="close-detail-modal" onclick="closeDetailModal()">&times;</button>
                </div>

                <div class="supplier-detail-body" id="supplierDetailBody">
                    <!-- Detail content will be loaded here -->
                </div>
            </div>
        </div>

        <!-- Notification Container -->
        <div id="notificationContainer"></div>

        <script>
            let currentAction = null;
            let currentSupplierId = null;
            let currentSupplierName = null;

            function switchTab(tabName) {
                // Hide all tabs
                document.querySelectorAll('.tab-content').forEach(tab => {
                    tab.classList.remove('active');
                });

                // Remove active from all buttons
                document.querySelectorAll('.tab-button').forEach(btn => {
                    btn.classList.remove('active');
                });

                // Show selected tab
                document.getElementById(tabName + '-tab').classList.add('active');

                // Activate button
                event.target.closest('.tab-button').classList.add('active');
            }

            function showNotification(message, type = 'success') {
                const notification = document.createElement('div');
                notification.className = `notification ${type}`;
                notification.innerHTML = `
                    <i class="material-icons">${type === 'success' ? 'check_circle' : 'error'}</i>
                    <span>${message}</span>
                `;

                document.getElementById('notificationContainer').appendChild(notification);

                setTimeout(() => {
                    notification.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => notification.remove(), 300);
                }, 3000);
            }

            function viewSupplierDetail(supplierData) {
                console.log('Viewing supplier details:', supplierData);

                // Clean up status text - remove extra spaces and format properly
                let rawStatusText = supplierData.status_text || '';
                let cleanStatusText = rawStatusText
                    .replace(/\s+/g, ' ') // Replace multiple spaces with single space
                    .trim()
                    .split(' ')
                    .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
                    .join(' ');

                if (cleanStatusText === '') {
                    switch (supplierData.status) {
                        case 'pending':
                            cleanStatusText = 'Pending Review';
                            break;
                        case 'approved':
                            cleanStatusText = 'Approved';
                            break;
                        case 'rejected':
                            cleanStatusText = 'Rejected';
                            break;
                        default:
                            cleanStatusText = 'Unknown';
                    }
                }

                // Determine status class and icon
                let statusClass = '';
                let statusIcon = '';

                switch (supplierData.status) {
                    case 'pending':
                        statusClass = 'status-pending';
                        statusIcon = 'hourglass_empty';
                        if (!cleanStatusText.includes('Pending')) {
                            cleanStatusText = 'Pending Review';
                        }
                        break;
                    case 'approved':
                        statusClass = 'status-approved';
                        statusIcon = 'check_circle';
                        if (!cleanStatusText.includes('Approved')) {
                            cleanStatusText = 'Approved';
                        }
                        break;
                    case 'rejected':
                        statusClass = 'status-rejected';
                        statusIcon = 'cancel';
                        if (!cleanStatusText.includes('Rejected')) {
                            cleanStatusText = 'Rejected';
                        }
                        break;
                    default:
                        statusClass = 'status-pending';
                        statusIcon = 'help';
                        cleanStatusText = 'Unknown Status';
                }

                // Format dates properly
                let formattedDate = 'Not reviewed yet';
                if (supplierData.approved_at && supplierData.approved_at !== 'Not reviewed yet') {
                    const date = new Date(supplierData.approved_at);
                    if (!isNaN(date.getTime())) {
                        formattedDate = date.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                    } else {
                        formattedDate = supplierData.approved_at;
                    }
                }

                // Build detail HTML
                let detailHTML = `
        <div class="detail-section">
            <h4><i class="material-icons">person</i> Personal Information</h4>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">badge</i></div>
                    <div class="detail-label">Supplier ID:</div>
                    <div class="detail-value">${supplierData.id || 'N/A'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">person</i></div>
                    <div class="detail-label">Full Name:</div>
                    <div class="detail-value">${supplierData.name || 'Not provided'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">person_outline</i></div>
                    <div class="detail-label">First Name:</div>
                    <div class="detail-value">${supplierData.first_name || 'Not provided'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">person_outline</i></div>
                    <div class="detail-label">Last Name:</div>
                    <div class="detail-value">${supplierData.last_name || 'Not provided'}</div>
                </div>
            </div>
        </div>
        
        <div class="detail-section">
            <h4><i class="material-icons">contact_mail</i> Contact Information</h4>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">alternate_email</i></div>
                    <div class="detail-label">Username:</div>
                    <div class="detail-value">${supplierData.username || 'Not provided'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">email</i></div>
                    <div class="detail-label">Email:</div>
                    <div class="detail-value">${supplierData.email || 'Not provided'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">phone</i></div>
                    <div class="detail-label">Phone:</div>
                    <div class="detail-value">${supplierData.phone || 'Not provided'}</div>
                </div>
            </div>
        </div>
        
        <div class="detail-section">
            <h4><i class="material-icons">verified_user</i> Account Status</h4>
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">${statusIcon}</i></div>
                    <div class="detail-label">Status:</div>
                    <div class="detail-value">
                        <span class="status-badge-large ${statusClass}">
                            <i class="material-icons" style="font-size: 16px;">${statusIcon}</i>
                            ${cleanStatusText}
                        </span>
                    </div>
                </div>`;

                // Add approval/rejection details if applicable
                if (supplierData.status === 'approved' && supplierData.approved_by) {
                    detailHTML += `
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">how_to_reg</i></div>
                    <div class="detail-label">Approved By:</div>
                    <div class="detail-value">${supplierData.approved_by}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">event</i></div>
                    <div class="detail-label">Approved On:</div>
                    <div class="detail-value">${formattedDate}</div>
                </div>`;
                } else if (supplierData.status === 'rejected' && supplierData.rejected_by) {
                    detailHTML += `
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">block</i></div>
                    <div class="detail-label">Rejected By:</div>
                    <div class="detail-value">${supplierData.rejected_by}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">event</i></div>
                    <div class="detail-label">Rejected On:</div>
                    <div class="detail-value">${formattedDate}</div>
                </div>`;
                } else if (supplierData.status === 'pending') {
                    detailHTML += `
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">update</i></div>
                    <div class="detail-label">Registration Date:</div>
                    <div class="detail-value">${formattedDate}</div>
                </div>`;
                }

                // Add registration date if available
                if (supplierData.created_at) {
                    const regDate = new Date(supplierData.created_at);
                    if (!isNaN(regDate.getTime())) {
                        const formattedRegDate = regDate.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });
                        detailHTML += `
                <div class="detail-item">
                    <div class="detail-icon"><i class="material-icons">calendar_today</i></div>
                    <div class="detail-label">Registered:</div>
                    <div class="detail-value">${formattedRegDate}</div>
                </div>`;
                    }
                }

                detailHTML += `
            </div>
        </div>`;

                // Insert HTML into modal
                document.getElementById('supplierDetailBody').innerHTML = detailHTML;

                // Show modal
                document.getElementById('supplierDetailModal').style.display = 'flex';
                document.body.style.overflow = 'hidden'; // Prevent background scrolling
            }

            function closeDetailModal() {
                document.getElementById('supplierDetailModal').style.display = 'none';
            }

            function approveSupplier(supplierId, supplierName) {
                currentAction = 'approve';
                currentSupplierId = supplierId;
                currentSupplierName = supplierName;

                document.getElementById('modalTitle').textContent = 'Approve Supplier';
                document.getElementById('modalMessage').textContent = `Are you sure you want to approve ${supplierName} as a supplier?`;
                document.getElementById('confirmActionBtn').textContent = 'Approve';
                document.getElementById('confirmActionBtn').className = 'btn btn-approve';

                document.getElementById('confirmationModal').style.display = 'flex';
            }

            function rejectSupplier(supplierId, supplierName) {
                currentAction = 'reject';
                currentSupplierId = supplierId;
                currentSupplierName = supplierName;

                document.getElementById('modalTitle').textContent = 'Reject Supplier';
                document.getElementById('modalMessage').textContent = `Are you sure you want to reject ${supplierName}'s application?`;
                document.getElementById('confirmActionBtn').textContent = 'Reject';
                document.getElementById('confirmActionBtn').className = 'btn btn-reject';

                document.getElementById('confirmationModal').style.display = 'flex';
            }

            function closeModal() {
                document.getElementById('confirmationModal').style.display = 'none';
                currentAction = null;
                currentSupplierId = null;
                currentSupplierName = null;
            }

            // Close detail modal when clicking outside
            document.getElementById('supplierDetailModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeDetailModal();
                }
            });

            // Close detail modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (document.getElementById('supplierDetailModal').style.display === 'flex') {
                        closeDetailModal();
                    }
                }
            });

            function confirmAction() {
                if (!currentAction || !currentSupplierId) {
                    console.error('No action or supplier ID specified');
                    return;
                }

                const endpoint = currentAction === 'approve' ? 'approve_supplier.php' : 'reject_supplier.php';
                const actionText = currentAction === 'approve' ? 'approved' : 'rejected';

                console.log(`Sending ${currentAction} request for supplier: ${currentSupplierId} (${currentSupplierName})`);
                console.log(`Endpoint: ${endpoint}`);

                // Disable confirm button while processing
                const confirmBtn = document.getElementById('confirmActionBtn');
                const originalText = confirmBtn.textContent;
                confirmBtn.textContent = 'Processing...';
                confirmBtn.disabled = true;

                fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'id=' + encodeURIComponent(currentSupplierId)
                    })
                    .then(res => {
                        console.log(`Response status: ${res.status}`);
                        if (!res.ok) {
                            throw new Error(`HTTP error! status: ${res.status}`);
                        }
                        return res.json();
                    })
                    .then(data => {
                        console.log(`Response data:`, data);
                        if (data.success) {
                            showNotification(`Supplier ${actionText} successfully!`, 'success');
                            closeModal();
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showNotification(data.message || `Failed to ${currentAction} supplier`, 'error');
                            closeModal();
                        }
                    })
                    .catch(err => {
                        console.error('Fetch error:', err);
                        showNotification('Request failed. Please check console for details.', 'error');
                        closeModal();
                    })
                    .finally(() => {
                        // Re-enable button
                        confirmBtn.textContent = originalText;
                        confirmBtn.disabled = false;
                    });
            }

            // Close modal when clicking outside
            document.getElementById('confirmationModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal();
                }
            });

            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                }
            });

            // Dropdown functionality
            function toggleDropdown(button) {
                const dropdown = button.nextElementSibling;
                const isExpanded = button.getAttribute('aria-expanded') === 'true';

                // Close all dropdowns
                document.querySelectorAll('.user-info').forEach(btn => {
                    btn.setAttribute('aria-expanded', 'false');
                    const dd = btn.nextElementSibling;
                    if (dd) {
                        dd.classList.remove('show');
                        dd.setAttribute('aria-hidden', 'true');
                    }
                });

                // Toggle current dropdown
                if (!isExpanded) {
                    button.setAttribute('aria-expanded', 'true');
                    dropdown.classList.add('show');
                    dropdown.setAttribute('aria-hidden', 'false');
                }
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.user-dropdown')) {
                    document.querySelectorAll('.user-info').forEach(btn => {
                        btn.setAttribute('aria-expanded', 'false');
                        const dropdown = btn.nextElementSibling;
                        if (dropdown) {
                            dropdown.classList.remove('show');
                            dropdown.setAttribute('aria-hidden', 'true');
                        }
                    });
                }
            });

            // Profile photo upload functionality
            document.addEventListener('DOMContentLoaded', function() {
                const profilePhotoContainer = document.querySelector('.profile-photo-container');
                const profileUpload = document.getElementById('profileUpload');
                const uploadForm = document.getElementById('uploadForm');

                if (profilePhotoContainer && profileUpload) {
                    profilePhotoContainer.addEventListener('click', function() {
                        profileUpload.click();
                    });

                    profileUpload.addEventListener('change', function() {
                        if (this.files && this.files[0]) {
                            uploadForm.submit();
                        }
                    });
                }
            });

            // Sidebar functionality
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('toggleSidebar');
            const mainContent = document.getElementById('main-content');

            const sidebarState = localStorage.getItem('sidebarState');
            if (sidebarState === 'collapsed') {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            }

            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                localStorage.setItem('sidebarState', sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded');
            });

            // Dropdown menus for sidebar
            document.querySelectorAll('.booking-toggle').forEach(toggle => {
                toggle.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    const parentMenu = toggle.closest('.user-menu');
                    const dropdownId = toggle.getAttribute('data-target');
                    const dropdown = document.getElementById(dropdownId);
                    const isExpanded = parentMenu.getAttribute('aria-expanded') === 'true';

                    document.querySelectorAll('.user-menu').forEach(menu => {
                        if (menu !== parentMenu) {
                            menu.setAttribute('aria-expanded', 'false');
                        }
                    });
                    document.querySelectorAll('.booking-submenu').forEach(sub => {
                        if (sub !== dropdown) {
                            sub.classList.remove('show');
                            sub.classList.add('hidden');
                            sub.setAttribute('aria-hidden', 'true');
                        }
                    });

                    if (!isExpanded) {
                        parentMenu.setAttribute('aria-expanded', 'true');
                        dropdown.classList.remove('hidden');
                        dropdown.classList.add('show');
                        dropdown.setAttribute('aria-hidden', 'false');
                    } else {
                        parentMenu.setAttribute('aria-expanded', 'false');
                        dropdown.classList.remove('show');
                        dropdown.classList.add('hidden');
                        dropdown.setAttribute('aria-hidden', 'true');
                    }
                });
            });

            document.addEventListener('click', (e) => {
                if (!e.target.closest('.user-menu')) {
                    document.querySelectorAll('.user-menu').forEach(menu => {
                        menu.setAttribute('aria-expanded', 'false');
                    });
                    document.querySelectorAll('.booking-submenu').forEach(sub => {
                        sub.classList.remove('show');
                        sub.classList.add('hidden');
                        sub.setAttribute('aria-hidden', 'true');
                    });
                }
            });
        </script>
    </main>
</body>

</html>