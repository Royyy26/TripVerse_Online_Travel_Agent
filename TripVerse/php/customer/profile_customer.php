<?php
session_start();
require_once __DIR__ . '/../_lang.php';
require __DIR__ . '/../connect.php';

// Cek apakah user sudah login
if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit();
}

$id_user = $_SESSION['id_user'];

// ---------------------------------------------------------------------------
// Ganti kata sandi
// The settings screen used to show a "Ganti Kata Sandi" button that was not
// wired to anything. This is the real implementation: it verifies the current
// password, enforces the same strength rules as registration, and stores the
// new one hashed.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $pwError = '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $pwError = t('Semua kolom kata sandi wajib diisi.');
    } elseif ($newPassword !== $confirmPassword) {
        $pwError = t('Konfirmasi kata sandi tidak cocok.');
    } elseif (strlen($newPassword) < 8) {
        $pwError = t('Kata sandi baru minimal 8 karakter.');
    } elseif (!preg_match('/[A-Z]/', $newPassword)) {
        $pwError = t('Kata sandi baru harus memuat minimal satu huruf kapital.');
    } elseif (!preg_match('/[0-9]/', $newPassword)) {
        $pwError = t('Kata sandi baru harus memuat minimal satu angka.');
    } elseif (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        $pwError = t('Kata sandi baru harus memuat minimal satu karakter spesial.');
    } elseif ($newPassword === $currentPassword) {
        $pwError = t('Kata sandi baru harus berbeda dari kata sandi lama.');
    }

    if ($pwError === '') {
        $pwStmt = $conn->prepare("SELECT password FROM user WHERE id_user = ? LIMIT 1");
        $pwStmt->bind_param('s', $id_user);
        $pwStmt->execute();
        $pwRow = $pwStmt->get_result()->fetch_assoc();
        $pwStmt->close();

        if (!$pwRow || !password_verify($currentPassword, $pwRow['password'])) {
            $pwError = t('Kata sandi saat ini salah.');
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updStmt = $conn->prepare("UPDATE user SET password = ? WHERE id_user = ?");
            $updStmt->bind_param('ss', $newHash, $id_user);

            if ($updStmt->execute()) {
                $_SESSION['password_notification'] = t('Kata sandi berhasil diperbarui.');
                $_SESSION['password_notification_ok'] = true;
            } else {
                $_SESSION['password_notification'] = t('Gagal memperbarui kata sandi. Silakan coba lagi.');
                $_SESSION['password_notification_ok'] = false;
            }
            $updStmt->close();

            header('Location: profile_customer.php?section=security');
            exit();
        }
    }

    $_SESSION['password_notification'] = $pwError;
    $_SESSION['password_notification_ok'] = false;
    header('Location: profile_customer.php?section=security');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $mobile = trim($_POST['no_hp']);
    $gender = $_POST['gender'];

    $profile_picture = null;
    $upload_success = true;
    $upload_message = "";

    // Handle file upload
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../../uploads/';

        // Create uploads directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = uniqid() . '_' . basename($_FILES['foto']['name']);
        $targetPath = $uploadDir . $fileName;

        $imageFileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
        $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        // Check file size (max 5MB)
        if ($_FILES['foto']['size'] > 5 * 1024 * 1024) {
            $upload_success = false;
            $upload_message = t("Ukuran file terlalu besar. Maksimal 5MB.");
        }
        // Check file type
        elseif (!in_array($imageFileType, $allowedTypes)) {
            $upload_success = false;
            $upload_message = t("Tipe file tidak valid. Hanya JPG, PNG, GIF, WEBP yang diizinkan.");
        }
        // Try to upload
        else {
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $targetPath)) {
                $profile_picture = $fileName;

                // Delete old profile picture if exists
                $oldPhotoQuery = "SELECT profile_picture FROM user WHERE id_user = ?";
                $oldStmt = $conn->prepare($oldPhotoQuery);
                $oldStmt->bind_param("s", $id_user);
                $oldStmt->execute();
                $oldResult = $oldStmt->get_result();

                if ($oldData = $oldResult->fetch_assoc()) {
                    $oldPhoto = $oldData['profile_picture'];
                    if ($oldPhoto && file_exists($uploadDir . $oldPhoto) && $oldPhoto !== 'default.jpg') {
                        unlink($uploadDir . $oldPhoto);
                    }
                }
                $oldStmt->close();
                $upload_message = t("Foto profil berhasil diupload.");
            } else {
                $upload_success = false;
                $upload_message = t("Gagal mengupload foto.");
            }
        }
    } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Handle other upload errors
        $upload_success = false;
        switch ($_FILES['foto']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $upload_message = t("Ukuran file terlalu besar.");
                break;
            case UPLOAD_ERR_PARTIAL:
                $upload_message = t("File hanya terupload sebagian.");
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $upload_message = t("Folder temporary tidak ditemukan.");
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $upload_message = t("Gagal menulis file ke disk.");
                break;
            case UPLOAD_ERR_EXTENSION:
                $upload_message = t("Upload dihentikan oleh extension.");
                break;
            default:
                $upload_message = t("Error unknown saat upload file.");
        }
    }

    // Handle delete avatar checkbox
    if (isset($_POST['delete_avatar']) && $_POST['delete_avatar'] == '1') {
        $uploadDir = '../../uploads/';

        // Get current photo
        $currentPhotoQuery = "SELECT profile_picture FROM user WHERE id_user = ?";
        $currentStmt = $conn->prepare($currentPhotoQuery);
        $currentStmt->bind_param("s", $id_user);
        $currentStmt->execute();
        $currentResult = $currentStmt->get_result();

        if ($currentData = $currentResult->fetch_assoc()) {
            $currentPhoto = $currentData['profile_picture'];
            if ($currentPhoto && file_exists($uploadDir . $currentPhoto) && $currentPhoto !== 'default.jpg') {
                unlink($uploadDir . $currentPhoto);
            }
        }
        $currentStmt->close();

        $profile_picture = 'default.jpg';
        $upload_message = t("Foto profil berhasil dihapus.");
    }

    // Only proceed with profile update if file upload was successful or no file was uploaded
    if ($upload_success) {
        // Update user data
        if ($profile_picture !== null) {
            $query = "UPDATE user SET 
                      first_name = ?, 
                      last_name = ?, 
                      email = ?, 
                      no_hp = ?, 
                      gender = ?, 
                      profile_picture = ? 
                      WHERE id_user = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sssssss", $first_name, $last_name, $email, $mobile, $gender, $profile_picture, $id_user);
        } else {
            $query = "UPDATE user SET 
                      first_name = ?, 
                      last_name = ?, 
                      email = ?, 
                      no_hp = ?, 
                      gender = ? 
                      WHERE id_user = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ssssss", $first_name, $last_name, $email, $mobile, $gender, $id_user);
        }

        if ($stmt->execute()) {
            $_SESSION['upload_notification'] = t("Profil berhasil diperbarui!") . ($upload_message ? " " . $upload_message : "");
            $_SESSION['upload_notification_ok'] = true;

            // Update session data
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['email'] = $email;
            if ($profile_picture) {
                $_SESSION['profile_picture'] = $profile_picture;
            }

            // Refresh the page to show updated data
            header("Location: profile_customer.php?section=profile");
            exit();
        } else {
            $_SESSION['upload_notification'] = t("Gagal memperbarui profil: ") . $stmt->error;
            $_SESSION['upload_notification_ok'] = false;
        }

        $stmt->close();
    } else {
        $_SESSION['upload_notification'] = $upload_message;
        $_SESSION['upload_notification_ok'] = false;
    }
}

// Get user data
$query = "SELECT 
            username,
            email,
            first_name,
            last_name,
            no_hp,
            gender,
            profile_picture
          FROM user
          WHERE id_user = ?";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    die("Error preparing query: " . $conn->error);
}

$stmt->bind_param("s", $id_user);
if (!$stmt->execute()) {
    die("Error executing query: " . $stmt->error);
}

$result = $stmt->get_result();

if ($data = $result->fetch_assoc()) {
    $username   = $data['username'];
    $email      = $data['email'];
    $firstName  = $data['first_name'];
    $lastName   = $data['last_name'];
    $mobile     = $data['no_hp'];
    $gender     = $data['gender'];
    $foto       = $data['profile_picture'] ? '../../uploads/' . $data['profile_picture'] : '../../images/default.jpg';

    // Store in session for use in header
    $_SESSION['first_name'] = $firstName;
    $_SESSION['last_name'] = $lastName;
    $_SESSION['email'] = $email;
    $_SESSION['profile_picture'] = $data['profile_picture'];
} else {
    // Fallback if data not found
    $data = [];
    $username = "Unknown";
    $email = "unknown@tripverse.com";
    $firstName = $lastName = $mobile = $gender = "-";
    $foto = "../../images/default.jpg";
}

$stmt->close();
// NOTE: $conn stays open here — the account summary below still needs it.
// It is closed once that query has run.

// Determine active section
$active_section = isset($_GET['section']) ? $_GET['section'] : 'profile';

// ---------------------------------------------------------------------------
// Account summary shown at the top of the profile screen, so the page leads
// with something meaningful instead of an empty form.
// ---------------------------------------------------------------------------
$stats = ['total' => 0, 'completed' => 0, 'spent' => 0, 'since' => null];

if ($statStmt = $conn->prepare(
    "SELECT COUNT(*) AS total,
            SUM(bh.status = 'Completed') AS completed,
            SUM(CASE WHEN bh.status = 'Completed' THEN bh.total_harga ELSE 0 END) AS spent,
            MIN(c.created_at) AS since
     FROM customer c
     LEFT JOIN booking_hotel bh ON bh.customer_id = c.customer_id
     WHERE c.id_user = ?"
)) {
    $statStmt->bind_param('s', $id_user);
    if ($statStmt->execute()) {
        if ($row = $statStmt->get_result()->fetch_assoc()) {
            $stats['total']     = (int) ($row['total'] ?? 0);
            $stats['completed'] = (int) ($row['completed'] ?? 0);
            $stats['spent']     = (float) ($row['spent'] ?? 0);
            $stats['since']     = $row['since'] ?? null;
        }
    }
    $statStmt->close();
}

$memberSince = $stats['since'] ? date('M Y', strtotime($stats['since'])) : '-';

// Profile completeness gives the user a concrete reason to finish the form.
$profileFields = [
    t('Nama depan')     => $firstName,
    t('Nama belakang')  => $lastName,
    t('Email')          => $email,
    t('Nomor HP')       => $mobile,
    t('Jenis kelamin')  => $gender,
    t('Foto profil')    => (!empty($data['profile_picture'] ?? '') ? 'y' : ''),
];
$filledCount = count(array_filter($profileFields, function ($v) { return trim((string) $v) !== '' && trim((string) $v) !== '-'; }));
$profileCompletion = (int) round($filledCount / count($profileFields) * 100);
$missingFields = array_keys(array_filter($profileFields, function ($v) { return trim((string) $v) === '' || trim((string) $v) === '-'; }));

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Profil Customer') ?> - TripVerse</title>
    <meta content="" name="keywords">
    <meta content="" name="description">
    <link href="../../css/wa.css?v=2.0" rel="stylesheet">

    <link href="../../img/favicon.ico" rel="icon">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

    <link href="../../lib/animate/animate.min.css" rel="stylesheet">
    <link href="../../lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="../../lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet" />

    <link href="../../css/bootstrap.min.css" rel="stylesheet">
    <link href="../../css/style.css?v=2.0" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="../../css/home.css?v=2.0" rel="stylesheet">

    <link href="../../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>" rel="stylesheet">

    <style>
        :root {
            --primary-color: #FEA116;
            --secondary-color: #FF7A3D;
            --text-color: #333;
            --light-gray: #f5f5f5;
            --border-color: #e0e0e0;
            --silver-color: #c0c0c0;
            --sidebar-width: 280px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Heebo', Tahoma, Geneva, sans-serif;
        }

        body {
            background-color: #f9f9f9;
            color: var(--text-color);
            line-height: 1.6;
        }

        .container-main {
            display: flex;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            gap: 30px;
        }

        .content-wrapper {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 30px;
            margin-top: 20px;
        }
        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            padding: 25px;
            position: sticky;
            top: 20px;
            height: fit-content;
            transition: none;
        }
        .sidebar-title {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #3498db;
        }

        .main-content {
            flex: 1;
            padding: 0;
        }

        .profile-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .profile-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            align-items: center;
            color: var(--primary-color);
        }

        .tier-section {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .tier-item {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--light-gray);
            padding: 8px 15px;
            border-radius: 20px;
        }

        .tier-checkbox {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
        }

        .tier-silver {
            color: var(--silver-color);
            font-weight: 500;
        }

        .points {
            font-weight: 500;
            color: var(--primary-color);
        }

        .profile-edit-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            margin-top: 20px;
        }

        .section-title {
            font-size: 24px;
            margin-bottom: 25px;
            color: var(--text-color);
            font-weight: 700;
            border-bottom: 2px solid var(--primary-color);
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: var(--text-color);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            width: 100%;
            padding: 15px 20px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            background: #fff;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(58, 134, 255, 0.1);
            transform: translateY(-2px);
        }

        .btn {
            display: inline-block;
            padding: 15px 30px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn:hover {
            background: var(--secondary-color);
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .btn-block {
            display: block;
            width: 100%;
            margin-top: 20px;
        }

        .profile-avatar {
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 30px;
            padding: 20px;
            background: var(--light-gray);
            border-radius: 15px;
        }

        .avatar-image {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-color);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .avatar-upload {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .avatar-upload-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            text-align: center;
        }

        .avatar-upload-btn:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
        }

        .delete-avatar {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #666;
        }

        .notification {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 600;
            border-left: 4px solid;
        }

        .notification.success {
            background-color: rgba(76, 175, 80, 0.1);
            color: #16A34A;
            border-left-color: #16A34A;
        }

        .notification.error {
            background-color: rgba(244, 67, 54, 0.1);
            color: #DC2626;
            border-left-color: #DC2626;
        }

        .sidebar-menu {
            list-style: none;
            margin-top: 20px;
        }

        .menu-item {
            border-bottom: 1px solid var(--border-color);
        }

        .menu-item:last-child {
            border-bottom: none;
        }

        .menu-link {
            display: flex;
            align-items: center;
            padding: 18px 25px;
            text-decoration: none;
            color: var(--text-color);
            transition: all 0.3s;
            font-weight: 500;
        }

        .menu-link:hover,
        .menu-link.active {
            background: linear-gradient(135deg, #FEA116, #FF7A3D);
            color: white;
            border-right: 4px solid var(--primary-color);
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(254, 161, 22, 0.35);
        }

        .menu-icon {
            margin-right: 15px;
            font-size: 20px;
            width: 25px;
            text-align: center;
        }

        /* PERBAIKAN SPINNER - HILANGKAN DARI AWAL */
        #spinner {
            display: none !important;
        }

        .contact-info {
            margin: 20px 0;
            padding: 15px;
            background-color: var(--light-gray);
            border-radius: 8px;
        }

        .contact-item {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .contact-item:last-child {
            margin-bottom: 0;
        }

        .account-center {
            margin-top: 25px;
        }

        .account-links {
            list-style: none;
            margin-top: 15px;
        }

        .account-link {
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .account-link:last-child {
            border-bottom: none;
        }

        .account-link a {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--text-color);
            padding: 8px 15px;
            border-radius: 6px;
            transition: all 0.3s;
        }

        .account-link a:hover,
        .account-link a.active {
            background-color: var(--light-gray);
            color: var(--primary-color);
        }

        .account-link-checkbox {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
        }

        /* PERBAIKAN DROPDOWN HEADER */
        .profile-dropdown-container {
            position: relative;
        }

        .profile-photo-container {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid #fff;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .profile-dropdown-container:hover .profile-photo-container,
        .profile-dropdown-container .dropdown-toggle[aria-expanded="true"] .profile-photo-container {
            border-color: var(--primary-color);
        }

        .profile-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-text {
            color: white;
            text-align: left;
            margin-left: 10px;
        }

        .profile-text .name {
            font-size: 14px;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .profile-text .email {
            font-size: 12px;
            opacity: 0.8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* PERBAIKAN DROPDOWN MENU */
        .navbar .dropdown-menu {
            z-index: 1060;
            border: none;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            margin-top: 5px;
        }

        .dropdown-menu a.dropdown-item {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            text-decoration: none;
            color: #333;
            transition: background-color 0.3s ease;
            border-bottom: 1px solid #f0f0f0;
        }

        .dropdown-menu a.dropdown-item:last-child {
            border-bottom: none;
        }

        .dropdown-menu a.dropdown-item:hover {
            background-color: #f8f9fa;
            color: var(--primary-color);
        }

        .dropdown-menu .material-icons {
            margin-right: 10px;
            font-size: 18px;
        }

        .dropdown-header {
            padding: 10px 15px;
            white-space: normal;
            line-height: 1.2;
            font-size: 14px;
        }

        .dropdown-menu hr.dropdown-divider {
            margin: 5px 0;
            border: none;
            border-top: 1px solid #e9ecef;
        }

        /* Style untuk dropdown toggle arrow */
        .navbar .dropdown-toggle::after {
            display: inline-block;
            margin-left: 0.255em;
            vertical-align: 0.255em;
            content: "";
            border-top: 0.3em solid;
            border-right: 0.3em solid transparent;
            border-bottom: 0;
            border-left: 0.3em solid transparent;
        }

        /* Responsive Design */
        @media (max-width: 991px) {

            .profile-text,
            .profile-dropdown-container .dropdown-toggle::after {
                display: none !important;
            }

            .profile-dropdown-container {
                margin-left: 0 !important;
            }

            .navbar-collapse .dropdown-menu {
                position: absolute;
                left: auto !important;
                right: 10px;
                top: 100%;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }

            .container-main {
                flex-direction: column;
            }

            .profile-avatar {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }

            .avatar-image {
                width: 140px;
                height: 140px;
            }

            .section-title {
                font-size: 20px;
            }
        }

        /* Additional improvements */
        .form-row {
            display: flex;
            gap: 20px;
        }

        .form-row .form-group {
            flex: 1;
        }

        @media (max-width: 576px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header class="container-fluid bg-dark px-0">
        <div class="row gx-0">
            <!-- Logo -->
            <div class="col-lg-3 bg-dark d-none d-lg-flex align-items-center justify-content-center">
                <a href="home.php" class="d-flex align-items-center text-decoration-none">
                    <img src="../../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 50px;">
                    <span class="tv-wordmark tv-wordmark-header">TripVerse</span>
                </a>
            </div>

            <!-- Contact -->
            <div class="col-lg-9">
                <div class="row gx-0 bg-white d-none d-lg-flex align-items-center py-2 px-5">
                    <div class="col-lg-7 text-start">
                        <div class="d-inline-flex align-items-center me-4">
                            <i class="fa fa-envelope text-primary me-2"></i>
                            <p class="mb-0">tripverse@gmail.com</p>
                        </div>
                        <div class="d-inline-flex align-items-center">
                            <i class="fa fa-phone-alt text-primary me-2"></i>
                            <p class="mb-0">+62 878 0677 6235</p>
                        </div>
                    </div>
                    <div class="col-lg-5 text-end">
                        <div class="d-inline-flex align-items-center">
                            <a class="me-3" href="#"><i class="fab fa-facebook-f"></i></a>
                            <a class="me-3" href="#"><i class="fab fa-twitter"></i></a>
                            <a class="me-3" href="#"><i class="fab fa-linkedin-in"></i></a>
                            <a class="me-3" href="#"><i class="fab fa-instagram"></i></a>
                            <a class="" href="#"><i class="fab fa-youtube"></i></a>
                        </div>
                    </div>
                </div>

                <!-- Navbar -->
                <nav class="navbar navbar-expand-xl bg-dark navbar-dark p-3 p-lg-0">
                    <a href="home.php" class="navbar-brand d-block d-xl-none">
                        <img src="../../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 40px;">
                        <span class="tv-wordmark tv-wordmark-header">TripVerse</span>
                    </a>
                    <button type="button" class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#navbarCollapse">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse justify-content-between" id="navbarCollapse">
                        <div class="navbar-nav mr-auto py-0">
                            <a href="home.php" class="nav-item nav-link"><?= te("Beranda") ?></a>
                            <a href="about.php" class="nav-item nav-link"><?= te("Tentang Kami") ?></a>
                            <a href="hotel.php" class="nav-item nav-link"><?= te("Hotel") ?></a>
                                <a href="flights.php" class="nav-item nav-link"><?= te("Pesawat") ?></a>
                            <a href="service.php" class="nav-item nav-link"><?= te("Fitur") ?></a>
                            <a href="team.php" class="nav-item nav-link"><?= te("Tim Kami") ?></a>
                            <a href="contact.php" class="nav-item nav-link"><?= te("Kontak") ?></a>
                            <a href="history.php" class="nav-item nav-link"><?= te("Riwayat") ?></a>
                        </div>

                        <!-- PERBAIKAN: Profile Dropdown yang Benar -->
                        <?php include __DIR__ . "/_lang_switch.php"; ?><?php include __DIR__ . "/_account_menu.php"; ?>
                    </div>
                </nav>
            </div>
        </div>
    </header>

    <!-- Main Layout -->
    <div class="container-main">
        <!-- Sidebar Navigation -->
        <div class="sidebar">
            <div class="profile-header">
                <h3 class="profile-name" style="text-align: center;"><?php echo htmlspecialchars($firstName . ' ' . $lastName); ?></h3>
            </div>

            <ul class="sidebar-menu">
                <li class="menu-item">
                    <a href="profile_customer.php" class="menu-link <?php echo $active_section == 'profile' ? 'active' : ''; ?>">
                        <i class="menu-icon fas fa-user"></i>
                        <span><?= te('Profil Saya') ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="history.php" class="menu-link">
                        <i class="menu-icon fas fa-receipt"></i>
                        <span><?= te('Pesanan Saya') ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="purchase_history.php" class="menu-link">
                        <i class="menu-icon fas fa-shopping-bag"></i>
                        <span><?= te('Daftar Pembelian') ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="?section=security" class="menu-link <?php echo $active_section == 'security' ? 'active' : ''; ?>">
                        <i class="menu-icon fas fa-lock"></i>
                        <span><?= te('Keamanan') ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="?section=settings" class="menu-link <?php echo $active_section == 'settings' ? 'active' : ''; ?>">
                        <i class="menu-icon fas fa-cog"></i>
                        <span><?= te('Pengaturan') ?></span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="../auth/logout.php" class="menu-link menu-link-logout">
                        <i class="menu-icon fas fa-sign-out-alt"></i>
                        <span><?= te('Logout') ?></span>
                    </a>
                </li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <?php if ($active_section == 'profile'): ?>
                <!-- Account overview: gives the page something to say before
                     it asks the user to fill in a form. -->
                <div class="tv-account-overview">
                    <div class="tv-overview-id">
                        <span class="tv-account-avatar tv-overview-avatar">
                            <img src="<?php echo htmlspecialchars($foto); ?>" alt=""
                                onerror="this.style.display='none'">
                            <span class="tv-account-initial"><?php
                                echo htmlspecialchars(mb_strtoupper(mb_substr(trim($firstName ?: $username), 0, 1)));
                            ?></span>
                        </span>
                        <div class="tv-overview-text">
                            <h2><?php echo htmlspecialchars(trim($firstName . ' ' . $lastName)) ?: htmlspecialchars($username); ?></h2>
                            <p><?php echo htmlspecialchars($email); ?></p>
                            <span class="tv-member-badge">
                                <i class="fas fa-star"></i> <?= te('Member sejak') ?> <?php echo htmlspecialchars($memberSince); ?>
                            </span>
                        </div>
                    </div>

                    <div class="tv-overview-stats">
                        <div class="tv-stat">
                            <span class="tv-stat-value"><?php echo (int) $stats['total']; ?></span>
                            <span class="tv-stat-label"><?= te('Total Pesanan') ?></span>
                        </div>
                        <div class="tv-stat">
                            <span class="tv-stat-value"><?php echo (int) $stats['completed']; ?></span>
                            <span class="tv-stat-label"><?= te('Selesai') ?></span>
                        </div>
                        <div class="tv-stat">
                            <span class="tv-stat-value">Rp <?php echo number_format($stats['spent'], 0, ',', '.'); ?></span>
                            <span class="tv-stat-label"><?= te('Total Transaksi') ?></span>
                        </div>
                    </div>

                    <?php if ($profileCompletion < 100): ?>
                        <div class="tv-completion">
                            <div class="tv-completion-head">
                                <span><?= te('Kelengkapan profil') ?></span>
                                <strong><?php echo $profileCompletion; ?>%</strong>
                            </div>
                            <div class="tv-completion-bar">
                                <span style="width: <?php echo $profileCompletion; ?>%"></span>
                            </div>
                            <small><?= te('Lengkapi:') ?> <?php echo htmlspecialchars(implode(', ', $missingFields)); ?></small>
                        </div>
                    <?php else: ?>
                        <div class="tv-completion tv-completion-done">
                            <i class="fas fa-check-circle"></i> <?= te('Profil kamu sudah lengkap.') ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Profile Edit Section -->
                <div class="profile-edit-section">
                    <h2 class="section-title"><?= te('Edit Profil') ?></h2>

                    <?php if (isset($_SESSION['upload_notification'])): ?>
                        <div class="notification <?php echo ($_SESSION['upload_notification_ok'] ?? (strpos($_SESSION['upload_notification'], 'berhasil') !== false)) ? 'success' : 'error'; ?>">
                            <?php echo $_SESSION['upload_notification']; ?>
                        </div>
                        <?php unset($_SESSION['upload_notification'], $_SESSION['upload_notification_ok']); ?>
                    <?php endif; ?>

                    <form action="profile_customer.php?section=profile" method="POST" enctype="multipart/form-data">
                        <div class="profile-avatar">
                            <img src="<?php echo $foto; ?>" alt="Profile Picture" class="avatar-image" id="avatar-preview">
                            <div class="avatar-upload">
                                <label for="foto" class="avatar-upload-btn"><?= te('Unggah Foto Baru') ?></label>
                                <input type="file" id="foto" name="foto" accept="image/*" onchange="previewImage(this)" style="display: none;">

                                <div class="delete-avatar">
                                    <input type="checkbox" id="delete_avatar" name="delete_avatar" value="1">
                                    <label for="delete_avatar"><?= te('Hapus avatar saat ini') ?></label>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name"><?= te('Nama Depan') ?></label>
                                <input type="text" id="first_name" name="first_name" class="form-control" value="<?php echo htmlspecialchars($firstName); ?>" required placeholder="<?= te('Masukkan nama depan') ?>">
                            </div>

                            <div class="form-group">
                                <label for="last_name"><?= te('Nama Belakang') ?></label>
                                <input type="text" id="last_name" name="last_name" class="form-control" value="<?php echo htmlspecialchars($lastName); ?>" required placeholder="<?= te('Masukkan nama belakang') ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email"><?= te('Email') ?></label>
                            <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" required placeholder="<?= te('Masukkan alamat email') ?>">
                        </div>

                        <div class="form-group">
                            <label for="mobile"><?= te('Nomor HP') ?></label>
                            <input type="tel" id="mobile" name="no_hp" class="form-control" value="<?php echo htmlspecialchars($mobile); ?>" placeholder="<?= te('Masukkan nomor handphone') ?>">
                        </div>

                        <div class="form-group">
                            <label for="gender"><?= te('Jenis Kelamin') ?></label>
                            <select id="gender" name="gender" class="form-control">
                                <option value=""><?= te('Pilih Jenis Kelamin') ?></option>
                                <option value="Male" <?php echo $gender == 'Male' ? 'selected' : ''; ?>><?= te('Laki-laki') ?></option>
                                <option value="Female" <?php echo $gender == 'Female' ? 'selected' : ''; ?>><?= te('Perempuan') ?></option>
                                <option value="Other" <?php echo $gender == 'Other' ? 'selected' : ''; ?>><?= te('Lainnya') ?></option>
                            </select>
                        </div>

                        <input type="hidden" name="update_profile" value="1">
                        <button type="submit" class="btn btn-primary"><?= te('SIMPAN PERUBAHAN') ?></button>
                    </form>
                </div>

            <?php elseif ($active_section == 'security'): ?>
                <!-- Security / change password -->
                <div class="profile-edit-section">
                    <h2 class="section-title"><?= te('Keamanan Akun') ?></h2>

                    <?php if (isset($_SESSION['password_notification'])): ?>
                        <div class="notification <?php echo ($_SESSION['password_notification_ok'] ?? (strpos($_SESSION['password_notification'], 'berhasil') !== false)) ? 'success' : 'error'; ?>">
                            <?php echo htmlspecialchars($_SESSION['password_notification']); ?>
                        </div>
                        <?php unset($_SESSION['password_notification'], $_SESSION['password_notification_ok']); ?>
                    <?php endif; ?>

                    <p class="tv-section-note">
                        <?= te('Gunakan kata sandi yang kuat dan tidak dipakai di layanan lain. Setelah diganti, kata sandi lama langsung tidak berlaku.') ?>
                    </p>

                    <form action="profile_customer.php?section=security" method="POST" id="passwordForm">
                        <div class="form-group">
                            <label for="current_password"><?= te('Kata Sandi Saat Ini') ?></label>
                            <div class="tv-pw-field">
                                <input type="password" id="current_password" name="current_password"
                                    class="form-control" required autocomplete="current-password"
                                    placeholder="<?= te('Masukkan kata sandi saat ini') ?>">
                                <i class="fas fa-eye toggle-password" role="button" tabindex="0"
                                    aria-label="<?= te('Tampilkan password') ?>"
                                    onclick="togglePassword('current_password', this)"></i>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="new_password"><?= te('Kata Sandi Baru') ?></label>
                                <div class="tv-pw-field">
                                    <input type="password" id="new_password" name="new_password"
                                        class="form-control" required autocomplete="new-password"
                                        placeholder="<?= te('Minimal 8 karakter') ?>">
                                    <i class="fas fa-eye toggle-password" role="button" tabindex="0"
                                        aria-label="<?= te('Tampilkan password') ?>"
                                        onclick="togglePassword('new_password', this)"></i>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password"><?= te('Ulangi Kata Sandi Baru') ?></label>
                                <div class="tv-pw-field">
                                    <input type="password" id="confirm_password" name="confirm_password"
                                        class="form-control" required autocomplete="new-password"
                                        placeholder="<?= te('Ketik ulang kata sandi baru') ?>">
                                    <i class="fas fa-eye toggle-password" role="button" tabindex="0"
                                        aria-label="<?= te('Tampilkan password') ?>"
                                        onclick="togglePassword('confirm_password', this)"></i>
                                </div>
                            </div>
                        </div>

                        <div class="tv-pw-strength">
                            <div class="tv-pw-bar"><span id="pwBar"></span></div>
                            <span class="tv-pw-label" id="pwLabel"><?= te('Kekuatan kata sandi') ?></span>
                        </div>

                        <ul class="tv-pw-rules" id="pwRules">
                            <li data-rule="len"><i class="fas fa-circle"></i> <?= te('Minimal 8 karakter') ?></li>
                            <li data-rule="upper"><i class="fas fa-circle"></i> <?= te('Satu huruf kapital') ?></li>
                            <li data-rule="num"><i class="fas fa-circle"></i> <?= te('Satu angka') ?></li>
                            <li data-rule="spec"><i class="fas fa-circle"></i> <?= te('Satu karakter spesial') ?></li>
                            <li data-rule="match"><i class="fas fa-circle"></i> <?= te('Konfirmasi cocok') ?></li>
                        </ul>

                        <input type="hidden" name="change_password" value="1">
                        <button type="submit" class="btn btn-primary" id="pwSubmit"><?= te('PERBARUI KATA SANDI') ?></button>
                    </form>
                </div>

            <?php elseif ($active_section == 'settings'): ?>
                <!-- Settings Section -->
                <div class="profile-edit-section">
                    <h2 class="section-title"><?= te('Pengaturan') ?></h2>

                    <p class="tv-section-note">
                        <?= te('Preferensi tampilan akun kamu. Untuk mengubah kata sandi, buka') ?>
                        <a href="?section=security"><?= te('Keamanan Akun') ?></a>.
                    </p>

                    <div class="form-group">
                        <label for="language"><?= te('Bahasa') ?></label>
                        <select id="language" class="form-control" disabled>
                            <option value="id" selected>Indonesia</option>
                        </select>
                        <small class="tv-field-hint"><?= te('Ubah bahasa TripVerse kapan saja lewat pengalih ID/EN di bagian atas halaman.') ?></small>
                    </div>

                    <div class="form-group">
                        <label for="country"><?= te('Negara atau Wilayah') ?></label>
                        <select id="country" class="form-control" disabled>
                            <option value="id" selected>Indonesia</option>
                        </select>
                        <small class="tv-field-hint"><?= te('Pemesanan hotel tersedia untuk wilayah Jabodetabek.') ?></small>
                    </div>

                    <div class="tv-settings-links">
                        <a href="?section=security" class="tv-settings-link">
                            <i class="fas fa-lock"></i>
                            <span>
                                <strong><?= te('Keamanan Akun') ?></strong>
                                <small><?= te('Ganti kata sandi kamu') ?></small>
                            </span>
                            <i class="fas fa-chevron-right tv-settings-arrow"></i>
                        </a>
                        <a href="history.php" class="tv-settings-link">
                            <i class="fas fa-receipt"></i>
                            <span>
                                <strong><?= te('Riwayat Pesanan') ?></strong>
                                <small><?= te('Lihat semua pemesanan kamu') ?></small>
                            </span>
                            <i class="fas fa-chevron-right tv-settings-arrow"></i>
                        </a>
                    </div>
                </div>

            <?php else: ?>
                <!-- Default Content for other sections -->
                <div class="profile-edit-section">
                    <h2 class="section-title"><?php echo ucfirst($active_section); ?></h2>
                    <p class="account-description">
                        <?= te('Halaman ini sedang dalam pengembangan. Fitur') ?> <?php echo $active_section; ?> <?= te('akan segera tersedia.') ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();

                reader.onload = function(e) {
                    document.getElementById('avatar-preview').src = e.target.result;
                }

                reader.readAsDataURL(input.files[0]);
            }
        }

        // Auto-hide notifications after 5 seconds
        setTimeout(function() {
            const notifications = document.querySelectorAll('.notification');
            notifications.forEach(notification => {
                notification.style.display = 'none';
            });
        }, 5000);

        // Fungsi untuk upload foto profil
        document.querySelector('.avatar-upload-btn').addEventListener('click', function() {
            document.getElementById('foto').click();
        });

        // Fungsi untuk hapus foto profil
        document.getElementById('delete_avatar').addEventListener('change', function() {
            if (this.checked) {
                if (!confirm('<?= t('Apakah Anda yakin ingin menghapus foto profil?') ?>')) {
                    this.checked = false;
                }
            }
        });

        // Debug untuk memastikan Bootstrap dropdown berfungsi
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Profile page loaded successfully');

            // Test Bootstrap dropdown functionality
            const dropdownElement = document.querySelector('#profileDropdown');
            if (dropdownElement) {
                console.log('Dropdown element found:', dropdownElement);
            }
        });
    </script>

    <!-- Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/tv-modern.js?v=<?= @filemtime(__DIR__ . '/../js/tv-modern.js') ?>"></script>
</body>

</html>