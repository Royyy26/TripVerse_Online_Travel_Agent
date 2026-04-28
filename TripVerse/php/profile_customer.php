<?php
session_start();
require 'connect.php';

// Cek apakah user sudah login
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit();
}

$id_user = $_SESSION['id_user'];

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
        $uploadDir = '../uploads/';

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
            $upload_message = "Ukuran file terlalu besar. Maksimal 5MB.";
        }
        // Check file type
        elseif (!in_array($imageFileType, $allowedTypes)) {
            $upload_success = false;
            $upload_message = "Tipe file tidak valid. Hanya JPG, PNG, GIF, WEBP yang diizinkan.";
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
                $upload_message = "Foto profil berhasil diupload.";
            } else {
                $upload_success = false;
                $upload_message = "Gagal mengupload foto.";
            }
        }
    } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Handle other upload errors
        $upload_success = false;
        switch ($_FILES['foto']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $upload_message = "Ukuran file terlalu besar.";
                break;
            case UPLOAD_ERR_PARTIAL:
                $upload_message = "File hanya terupload sebagian.";
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $upload_message = "Folder temporary tidak ditemukan.";
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $upload_message = "Gagal menulis file ke disk.";
                break;
            case UPLOAD_ERR_EXTENSION:
                $upload_message = "Upload dihentikan oleh extension.";
                break;
            default:
                $upload_message = "Error unknown saat upload file.";
        }
    }

    // Handle delete avatar checkbox
    if (isset($_POST['delete_avatar']) && $_POST['delete_avatar'] == '1') {
        $uploadDir = '../uploads/';

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
        $upload_message = "Foto profil berhasil dihapus.";
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
            $_SESSION['upload_notification'] = "Profil berhasil diperbarui!" . ($upload_message ? " " . $upload_message : "");

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
            $_SESSION['upload_notification'] = "Gagal memperbarui profil: " . $stmt->error;
        }

        $stmt->close();
    } else {
        $_SESSION['upload_notification'] = $upload_message;
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
    $foto       = $data['profile_picture'] ? '../uploads/' . $data['profile_picture'] : '../images/default.jpg';

    // Store in session for use in header
    $_SESSION['first_name'] = $firstName;
    $_SESSION['last_name'] = $lastName;
    $_SESSION['email'] = $email;
    $_SESSION['profile_picture'] = $data['profile_picture'];
} else {
    // Fallback if data not found
    $username = "Unknown";
    $email = "unknown@tripverse.com";
    $firstName = $lastName = $mobile = $gender = "-";
    $foto = "../images/default.jpg";
}

$stmt->close();
$conn->close();

// Determine active section
$active_section = isset($_GET['section']) ? $_GET['section'] : 'profile';
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Customer - TripVerse</title>
    <meta content="" name="keywords">
    <meta content="" name="description">
    <link href="../css/wa.css" rel="stylesheet">

    <link href="img/favicon.ico" rel="icon">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

    <link href="../lib/animate/animate.min.css" rel="stylesheet">
    <link href="../lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="../lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet" />

    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="../css/home.css" rel="stylesheet">

    <style>
        :root {
            --primary-color: #3a86ff;
            --secondary-color: #8338ec;
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
            color: #4caf50;
            border-left-color: #4caf50;
        }

        .notification.error {
            background-color: rgba(244, 67, 54, 0.1);
            color: #f44336;
            border-left-color: #f44336;
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
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-right: 4px solid var(--primary-color);
            transform: translateX(5px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
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
            border-color: #0d6efd;
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
            color: #0d6efd;
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
                <a href="about.php" class="d-flex align-items-center text-decoration-none">
                    <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 50px;">
                    <h1 class="m-0 text-primary text-uppercase">TripVerse</h1>
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
                <nav class="navbar navbar-expand-lg bg-dark navbar-dark p-3 p-lg-0">
                    <a href="home.php" class="navbar-brand d-block d-lg-none">
                        <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 40px;">
                        <h1 class="m-0 text-primary text-uppercase">TripVerse</h1>
                    </a>
                    <button type="button" class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#navbarCollapse">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse justify-content-between" id="navbarCollapse">
                        <div class="navbar-nav mr-auto py-0">
                            <a href="home.php" class="nav-item nav-link">Beranda</a>
                            <a href="about.php" class="nav-item nav-link">Tentang Kami</a>
                            <a href="hotel.php" class="nav-item nav-link">Hotel</a>
                            <a href="service.php" class="nav-item nav-link">Fitur</a>
                            <a href="team.php" class="nav-item nav-link">Tim Kami</a>
                            <a href="contact.php" class="nav-item nav-link">Kontak</a>
                            <a href="riwayat.php" class="nav-item nav-link">Riwayat</a>
                            <a href="logout.php" class="nav-item nav-link">Logout</a>
                        </div>

                        <!-- PERBAIKAN: Profile Dropdown yang Benar -->
                        <div class="ms-auto me-2 dropdown profile-dropdown-container">
                            <a class="nav-link dropdown-toggle p-0 d-flex align-items-center text-decoration-none"
                                href="#"
                                id="profileDropdown"
                                role="button"
                                data-bs-toggle="dropdown"
                                aria-expanded="false">

                                <div class="profile-photo-container">
                                    <img src="<?= htmlspecialchars($foto) ?>"
                                        alt="Profile Photo"
                                        class="profile-photo"
                                        id="profilePhoto"
                                        onerror="this.src='../images/default.jpg'">
                                </div>

                                <div class="profile-text d-none d-lg-block ms-2 me-2">
                                    <div class="name fw-semibold text-white">
                                        <?= htmlspecialchars(trim($firstName . ' ' . $lastName)) ?: htmlspecialchars($username) ?>
                                    </div>
                                    <div class="email small text-light"><?= htmlspecialchars($email) ?></div>
                                </div>
                            </a>

                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="profileDropdown">
                                <li>
                                    <div class="dropdown-header text-truncate">
                                        <span class="fw-bold"><?= htmlspecialchars(trim($firstName . ' ' . $lastName)) ?: htmlspecialchars($username) ?></span><br>
                                        <span class="small text-muted"><?= htmlspecialchars($email) ?></span>
                                    </div>
                                </li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center" href="profile_customer.php">
                                        <span class="material-icons me-2">person</span> Profil Saya
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center" href="riwayat.php">
                                        <span class="material-icons me-2">receipt_long</span> Pesanan Saya
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center" href="daftar_pembelian.php">
                                        <span class="material-icons me-2">person</span> Daftar Pembelian
                                    </a>
                                </li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center" href="logout.php">
                                        <span class="material-icons me-2">logout</span> Logout
                                    </a>
                                </li>
                            </ul>
                        </div>
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
                        <span class="menu-icon">👤</span>
                        <span>Profil Saya</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="?section=orders" class="menu-link <?php echo $active_section == 'orders' ? 'active' : ''; ?>">
                        <span class="menu-icon">📦</span>
                        <span>Pesanan Saya</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="daftar_pembelian.php" class="menu-link ">
                        <i class="menu-icon material-icons">shopping_bag</i> Daftar Pembelian</a>
                </li>
                <li class="menu-item">
                    <a href="?section=settings" class="menu-link <?php echo $active_section == 'settings' ? 'active' : ''; ?>">
                        <span class="menu-icon">⚙️</span>
                        <span>Pengaturan</span>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item d-flex align-items-center" href="logout.php">
                        <span class="material-icons me-2">logout</span> Logout
                    </a>
                </li>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <?php if ($active_section == 'profile'): ?>
                <!-- Profile Edit Section -->
                <div class="profile-edit-section">
                    <h2 class="section-title">Edit Profil</h2>

                    <?php if (isset($_SESSION['upload_notification'])): ?>
                        <div class="notification <?php echo strpos($_SESSION['upload_notification'], 'berhasil') !== false ? 'success' : 'error'; ?>">
                            <?php echo $_SESSION['upload_notification']; ?>
                        </div>
                        <?php unset($_SESSION['upload_notification']); ?>
                    <?php endif; ?>

                    <form action="profile_customer.php?section=profile" method="POST" enctype="multipart/form-data">
                        <div class="profile-avatar">
                            <img src="<?php echo $foto; ?>" alt="Profile Picture" class="avatar-image" id="avatar-preview">
                            <div class="avatar-upload">
                                <label for="foto" class="avatar-upload-btn">Unggah Foto Baru</label>
                                <input type="file" id="foto" name="foto" accept="image/*" onchange="previewImage(this)" style="display: none;">

                                <div class="delete-avatar">
                                    <input type="checkbox" id="delete_avatar" name="delete_avatar" value="1">
                                    <label for="delete_avatar">Hapus avatar saat ini</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">Nama Depan</label>
                                <input type="text" id="first_name" name="first_name" class="form-control" value="<?php echo htmlspecialchars($firstName); ?>" required placeholder="Masukkan nama depan">
                            </div>

                            <div class="form-group">
                                <label for="last_name">Nama Belakang</label>
                                <input type="text" id="last_name" name="last_name" class="form-control" value="<?php echo htmlspecialchars($lastName); ?>" required placeholder="Masukkan nama belakang">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" required placeholder="Masukkan alamat email">
                        </div>

                        <div class="form-group">
                            <label for="mobile">Nomor HP</label>
                            <input type="tel" id="mobile" name="no_hp" class="form-control" value="<?php echo htmlspecialchars($mobile); ?>" placeholder="Masukkan nomor handphone">
                        </div>

                        <div class="form-group">
                            <label for="gender">Jenis Kelamin</label>
                            <select id="gender" name="gender" class="form-control">
                                <option value="">Pilih Jenis Kelamin</option>
                                <option value="Male" <?php echo $gender == 'Male' ? 'selected' : ''; ?>>Laki-laki</option>
                                <option value="Female" <?php echo $gender == 'Female' ? 'selected' : ''; ?>>Perempuan</option>
                                <option value="Other" <?php echo $gender == 'Other' ? 'selected' : ''; ?>>Lainnya</option>
                            </select>
                        </div>

                        <input type="hidden" name="update_profile" value="1">
                        <button type="submit" class="btn btn-primary">SIMPAN PERUBAHAN</button>
                    </form>
                </div>

            <?php elseif ($active_section == 'account'): ?>
                <!-- Account Information Section -->
                <div class="profile-edit-section">
                    <h2 class="section-title">Akun</h2>
                    <p class="account-description">
                        <strong>bibliē tiket</strong><br>
                        <strong>Pusat Akun</strong><br>
                        Untuk mengakses detail profilmu dan kategorī di bawah ini, masuk ke <strong>Pusat Akun Biblai Tiket</strong> aja, ya.
                    </p>

                    <div class="contact-info">
                        <div class="contact-item">
                            <strong><?php echo htmlspecialchars($firstName . ' ' . $lastName); ?></strong>
                        </div>
                        <div class="contact-item">
                            <span><?php echo htmlspecialchars($mobile); ?></span>
                        </div>
                        <div class="contact-item">
                            <span><?php echo htmlspecialchars($email); ?></span>
                        </div>
                    </div>

                    <div class="account-center">
                        <h3 class="section-title">Ke Pusat Akun</h3>
                        <ul class="account-links">
                            <li class="account-link">
                                <a href="?section=profile">
                                    <input type="checkbox" class="account-link-checkbox" checked disabled>
                                    <span>Akun</span>
                                </a>
                            </li>
                            <li class="account-link">
                                <a href="?section=payment">
                                    <input type="checkbox" class="account-link-checkbox" disabled>
                                    <span>Metode Pembayaran</span>
                                </a>
                            </li>
                            <li class="account-link">
                                <a href="?section=reviews">
                                    <input type="checkbox" class="account-link-checkbox" disabled>
                                    <span>Kumpulan Review Kamu</span>
                                </a>
                            </li>
                            <li class="account-link">
                                <a href="?section=wishlist">
                                    <input type="checkbox" class="account-link-checkbox" disabled>
                                    <span>Wishlist</span>
                                </a>
                            </li>
                            <li class="account-link">
                                <a href="?section=orders">
                                    <input type="checkbox" class="account-link-checkbox" disabled>
                                    <span>Your Orders</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

            <?php elseif ($active_section == 'settings'): ?>
                <!-- Settings Section -->
                <div class="profile-edit-section">
                    <h2 class="section-title">Pengaturan</h2>

                    <div class="form-group">
                        <label for="language">Bahasa</label>
                        <select id="language" class="form-control">
                            <option value="id" selected>Indonesia</option>
                            <option value="en">English</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="country">Negara atau Wilayah</label>
                        <select id="country" class="form-control">
                            <option value="id" selected>Indonesia</option>
                            <option value="my">Malaysia</option>
                            <option value="sg">Singapore</option>
                            <option value="th">Thailand</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <button class="btn btn-block">Ganti Kata Sandi</button>
                    </div>
                </div>

            <?php else: ?>
                <!-- Default Content for other sections -->
                <div class="profile-edit-section">
                    <h2 class="section-title"><?php echo ucfirst($active_section); ?></h2>
                    <p class="account-description">
                        Halaman ini sedang dalam pengembangan. Fitur <?php echo $active_section; ?> akan segera tersedia.
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
                if (!confirm('Apakah Anda yakin ingin menghapus foto profil?')) {
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
    <script src="../js/bootstrap.bundle.min.js"></script>
</body>

</html>