<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

// Check if user is admin (role is 'admin')
if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Akses ditolak! Halaman ini hanya untuk admin.'); window.location='home.php';</script>";
    exit;
}

require 'connect.php';

$id_user = $_SESSION['id_user'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $mobile = trim($_POST['mobile']);
    $gender = $_POST['gender'];

    $profile_picture = null;

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

        if (in_array($imageFileType, $allowedTypes)) {
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
            } else {
                $_SESSION['upload_notification'] = "Failed to upload photo.";
            }
        } else {
            $_SESSION['upload_notification'] = "Invalid file type. Only JPG, PNG, GIF, WEBP allowed.";
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

        $profile_picture = null; // Set to NULL to use default
    }

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
        $_SESSION['upload_notification'] = "Profile updated successfully!";

        // Update session data
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['email'] = $email;

        // Refresh the page to show updated data
        header("Location: profile.php");
        exit();
    } else {
        $_SESSION['upload_notification'] = "Failed to update profile: " . $stmt->error;
    }

    $stmt->close();
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
} else {
    // Fallback if data not found
    $username = "Unknown";
    $email = "unknown@tripverse.com";
    $firstName = $lastName = $mobile = $gender = "-";
    $foto = "../images/default.jpg";
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Profile Settings - TripVerse Admin</title>
    <link rel="stylesheet" href="../css/dashboard.css?v=1.8.0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            overflow-x: hidden;
        }

        .profile-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 10px;
        }

        .profile-photo-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .profile-photo-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin-bottom: 15px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid rgba(255, 255, 255, 0.2);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .profile-photo-container:hover {
            border-color: rgba(255, 255, 255, 0.4);
            transform: scale(1.05);
        }

        .profile-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .profile-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            border-radius: 50%;
        }

        .profile-photo-container:hover .profile-overlay {
            opacity: 1;
        }

        .profile-overlay .material-icons {
            color: white;
            font-size: 20px;
        }

        .profile-info {
            width: 100%;
        }

        .profile-info h2 {
            margin: 0 0 5px 0;
            font-size: 16px;
            font-weight: 600;
            color: white;
            line-height: 1.3;
        }

        .profile-info p {
            margin: 0 0 15px 0;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.7);
            line-height: 1.4;
        }

        .user-dropdown {
            position: relative;
            width: 100%;
            z-index: 1001;
        }

        .user-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 13px;
        }

        .user-info:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.3);
        }

        .dropdown-text {
            font-weight: 500;
        }

        .dropdown-arrow {
            font-size: 18px;
            transition: transform 0.3s ease;
        }

        .user-info[aria-expanded="true"] .dropdown-arrow {
            transform: rotate(180deg);
        }

        .dropdown-content {
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            display: none;
            margin-top: 8px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
            transform: none !important;
            z-index: 1000 !important;
            position: absolute !important;
        }

        .dropdown-content.show {
            display: block !important;
            opacity: 1 !important;
            visibility: visible !important;
        }

        @keyframes dropdownFadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            color: #333;
            text-decoration: none;
            transition: all 0.2s ease;
            border-bottom: 1px solid #f5f5f5;
            font-size: 14px;
            transform: none !important;
        }

        .dropdown-item:last-child {
            border-bottom: none;
        }

        .dropdown-item:hover {
            background: #f8f9fa;
            color: #000;
        }

        .dropdown-item .material-icons {
            font-size: 18px;
            color: #666;
            width: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dropdown-item:hover .material-icons {
            color: #000;
        }

        /* Arrow for dropdown */
        .dropdown-content::before {
            content: '';
            position: absolute;
            top: -6px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-bottom: 6px solid white;
            z-index: 1001;
        }

        .dropdown-content::after {
            content: '';
            position: absolute;
            top: -7px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 7px solid transparent;
            border-right: 7px solid transparent;
            border-bottom: 7px solid #e0e0e0;
            z-index: 1000;
        }

        /* Profile Settings Styles - CENTERED */
        .profile-settings {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            padding: 40px;
            margin: 20px auto;
            max-width: 900px;
            width: 90%;
        }

        .profile-settings h1 {
            margin-bottom: 30px;
            color: #2c3e50;
            font-size: 28px;
            font-weight: 600;
            text-align: center;
            border-bottom: 3px solid #3498db;
            padding-bottom: 15px;
        }

        .profile-form {
            width: 100%;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }

        .form-group label span {
            color: #e74c3c;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"] {
            width: 100%;
            padding: 14px;
            border: 2px solid #e8ecef;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: #fafbfc;
        }

        .form-group input:focus {
            outline: none;
            border-color: #3498db;
            background: white;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }

        .radio-group {
            display: flex;
            gap: 25px;
            margin-top: 10px;
        }

        .radio-group label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: normal;
            cursor: pointer;
            padding: 10px 15px;
            border: 2px solid #e8ecef;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .radio-group label:hover {
            border-color: #3498db;
            background: #f8fafc;
        }

        .radio-group input[type="radio"] {
            margin: 0;
            transform: scale(1.2);
        }

        /* Centered Photo Upload */
        .profile-photo-upload {
            text-align: center;
            border: 2px dashed #dce1e7;
            padding: 40px 30px;
            border-radius: 15px;
            margin-bottom: 40px;
            background: #fafbfc;
            transition: all 0.3s ease;
        }

        .profile-photo-upload:hover {
            border-color: #3498db;
            background: #f8fafc;
        }

        .profile-photo-preview {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #3498db;
            margin: 0 auto 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: block;
        }

        .profile-photo-preview:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .profile-photo-upload input[type="file"] {
            display: none;
        }

        .file-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #3498db;
            color: white;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            margin: 10px 0;
        }

        .file-label:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }

        .delete-photo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 15px;
            color: #e74c3c;
            cursor: pointer;
            font-size: 14px;
        }

        .delete-photo input[type="checkbox"] {
            transform: scale(1.1);
        }

        .btn-primary {
            display: block;
            width: 200px;
            margin: 30px auto 0;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(52, 152, 219, 0.4);
            background: linear-gradient(135deg, #2980b9, #3498db);
        }

        .error-message {
            color: #e74c3c;
            font-size: 12px;
            margin-top: 8px;
            display: none;
        }

        .notif {
            padding: 15px 25px;
            margin-bottom: 25px;
            border-radius: 10px;
            font-size: 14px;
            text-align: center;
            font-weight: 500;
        }

        .notif.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .notif.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Main Content Adjustment */
        .main-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .profile-settings {
                padding: 25px;
                margin: 15px auto;
                width: 95%;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .profile-photo-preview {
                width: 140px;
                height: 140px;
            }

            .radio-group {
                flex-direction: column;
                gap: 10px;
            }

            .btn-primary {
                width: 100%;
            }

            .dropdown-content {
                left: -10px;
                right: -10px;
            }
        }

        @media (max-width: 480px) {
            .profile-settings {
                padding: 20px;
                margin: 10px auto;
            }

            .profile-settings h1 {
                font-size: 24px;
            }

            .profile-photo-preview {
                width: 120px;
                height: 120px;
            }
        }

        /* Fix for glitch - Smooth transitions */
        .sidebar,
        .main-content,
        .profile-settings {
            transition: all 0.3s ease;
        }

        /* Ensure proper image loading */
        img {
            transition: opacity 0.3s ease;
        }

        img[src*="default.jpg"] {
            background: #f0f0f0;
            border: 1px solid #ddd;
        }

        /* Additional CSS reset untuk mencegah transform tidak diinginkan */
        .sidebar * {
            box-sizing: border-box;
        }

        .dropdown-content,
        .dropdown-item,
        .dropdown-item span {
            transform: none !important;
            rotate: none !important;
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
            <a href="dashboard.php" class="active">
                <span class="material-icons">dashboard</span>
                <span>Executive Overview</span>
            </a>

            <!-- SUPPLIER APPROVAL -->
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="supplier_approvals.php">
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
            <button id="toggleSidebar" class="menu-toggle">
                <span class="material-icons">menu</span>
            </button>
        </header>

        <section class="profile-settings">
            <h1>Profile Settings</h1>

            <?php if (isset($_SESSION['upload_notification'])) : ?>
                <div class="notif <?php echo strpos($_SESSION['upload_notification'], 'Failed') === false ? 'success' : 'error'; ?>">
                    <?= htmlspecialchars($_SESSION['upload_notification']); ?>
                </div>
                <?php unset($_SESSION['upload_notification']); ?>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data" class="profile-form" id="profileForm">
                <div class="form-group profile-photo-upload">
                    <label>Profile Photo</label>
                    <img src="<?= htmlspecialchars($foto); ?>" alt="Profile Photo" class="profile-photo-preview" id="photoPreview" onclick="document.getElementById('foto').click()">
                    <input type="file" name="foto" id="foto" accept="image/*" onchange="previewImage(this)">
                    <div class="file-label" onclick="document.getElementById('foto').click()">
                        <span class="material-icons">photo_camera</span> Change Photo
                    </div>
                    <div class="delete-photo">
                        <input type="checkbox" name="delete_avatar" id="delete_avatar" value="1">
                        <label for="delete_avatar">Delete current photo</label>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name <span>*</span></label>
                        <input type="text" name="first_name" id="first_name" value="<?= htmlspecialchars($firstName); ?>" required>
                        <div class="error-message" id="first_name_error">First name is required</div>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name <span>*</span></label>
                        <input type="text" name="last_name" id="last_name" value="<?= htmlspecialchars($lastName); ?>" required>
                        <div class="error-message" id="last_name_error">Last name is required</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email <span>*</span></label>
                        <input type="email" name="email" id="email" value="<?= htmlspecialchars($email); ?>" required>
                        <div class="error-message" id="email_error">Valid email is required</div>
                    </div>
                    <div class="form-group">
                        <label for="mobile">Mobile Number <span>*</span></label>
                        <input type="tel" name="mobile" id="mobile" value="<?= htmlspecialchars($mobile); ?>" required>
                        <div class="error-message" id="mobile_error">Mobile number is required</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Gender <span>*</span></label>
                        <div class="radio-group">
                            <label><input type="radio" name="gender" value="Male" <?= $gender === 'Male' ? 'checked' : ''; ?>> Male</label>
                            <label><input type="radio" name="gender" value="Female" <?= $gender === 'Female' ? 'checked' : ''; ?>> Female</label>
                        </div>
                    </div>
                </div>

                <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
            </form>
        </section>
    </main>

    <script>
        // Toggle sidebar
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        const mainContent = document.getElementById('main-content');

        // Load sidebar state
        const sidebarState = localStorage.getItem('sidebarState');
        if (sidebarState === 'collapsed') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }

        // Toggle sidebar
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                localStorage.setItem('sidebarState', sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded');
            });
        }

        // Image preview function
        function previewImage(input) {
            const preview = document.getElementById('photoPreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Form validation
        const form = document.getElementById('profileForm');
        if (form) {
            form.addEventListener('submit', function(e) {
                let isValid = true;

                // Clear previous errors
                document.querySelectorAll('.error-message').forEach(error => {
                    error.style.display = 'none';
                });

                // Validate required fields
                const requiredFields = ['first_name', 'last_name', 'email', 'mobile'];
                requiredFields.forEach(field => {
                    const input = document.getElementById(field);
                    const error = document.getElementById(field + '_error');

                    if (!input.value.trim()) {
                        error.style.display = 'block';
                        isValid = false;
                    }
                });

                // Validate email format
                const emailInput = document.getElementById('email');
                const emailError = document.getElementById('email_error');
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

                if (emailInput.value && !emailRegex.test(emailInput.value)) {
                    emailError.textContent = 'Please enter a valid email address';
                    emailError.style.display = 'block';
                    isValid = false;
                }

                if (!isValid) {
                    e.preventDefault();
                }
            });
        }

        // Dropdown functionality - SAME AS DASHBOARD.PHP
        function toggleDropdown(button) {
            event.stopPropagation();
            const dropdown = button.nextElementSibling;
            const isExpanded = button.getAttribute('aria-expanded') === 'true';

            // Close all other dropdowns first
            document.querySelectorAll('.dropdown-content').forEach(d => {
                d.classList.remove('show');
                d.setAttribute('aria-hidden', 'true');
                d.previousElementSibling.setAttribute('aria-expanded', 'false');
            });

            // Toggle current dropdown
            if (!isExpanded) {
                dropdown.style.transform = 'none';
                dropdown.classList.add('show');
                button.setAttribute('aria-expanded', 'true');
                dropdown.setAttribute('aria-hidden', 'false');
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.user-dropdown')) {
                document.querySelectorAll('.dropdown-content').forEach(d => {
                    d.classList.remove('show');
                    d.setAttribute('aria-hidden', 'true');
                    d.previousElementSibling.setAttribute('aria-expanded', 'false');
                });
            }
        });

        // Profile photo upload functionality
        document.addEventListener('DOMContentLoaded', function() {
            const profilePhotoContainer = document.querySelector('.profile-photo-container');
            const profileUpload = document.getElementById('profileUpload');

            if (profilePhotoContainer) {
                profilePhotoContainer.addEventListener('click', function() {
                    profileUpload.click();
                });
            }

            if (profileUpload) {
                profileUpload.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        // Preview image
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            document.getElementById('photoPreview').src = e.target.result;
                        }
                        reader.readAsDataURL(this.files[0]);
                    }
                });
            }

            // Handle image loading errors
            document.querySelectorAll('img').forEach(img => {
                img.addEventListener('error', function() {
                    this.src = '../images/default.jpg';
                });
            });

            // Reset transform on dropdown elements
            document.querySelectorAll('.dropdown-content, .dropdown-item').forEach(el => {
                el.style.transform = 'none';
            });
        });

        // Dropdown menus for sidebar - SAME AS DASHBOARD.PHP
        document.querySelectorAll('.booking-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const parentMenu = toggle.closest('.user-menu');
                const dropdownId = toggle.getAttribute('data-target');
                const dropdown = document.getElementById(dropdownId);
                const isExpanded = parentMenu.getAttribute('aria-expanded') === 'true';

                // Close all other dropdowns
                document.querySelectorAll('.user-menu').forEach(menu => {
                    menu.setAttribute('aria-expanded', 'false');
                });
                document.querySelectorAll('.booking-submenu').forEach(sub => {
                    sub.classList.remove('show');
                    sub.classList.add('hidden');
                    sub.setAttribute('aria-hidden', 'true');
                });

                // Toggle current dropdown
                if (!isExpanded) {
                    parentMenu.setAttribute('aria-expanded', 'true');
                    dropdown.classList.remove('hidden');
                    dropdown.classList.add('show');
                    dropdown.setAttribute('aria-hidden', 'false');
                }
            });
        });

        // Close dropdowns when clicking outside - SAME AS DASHBOARD.PHP
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

        // Additional fix untuk memastikan tidak ada transform yang tidak diinginkan
        window.addEventListener('load', function() {
            const dropdowns = document.querySelectorAll('.dropdown-content, .dropdown-item');
            dropdowns.forEach(dropdown => {
                dropdown.style.transform = 'none';
                dropdown.style.webkitTransform = 'none';
                dropdown.style.msTransform = 'none';
            });
        });
    </script>
</body>

</html>