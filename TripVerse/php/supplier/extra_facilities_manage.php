<?php
session_start();
if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit;
}
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'owner'])) {
    echo "<script>alert('Akses ditolak!'); window.location='../home.php';</script>";
    exit;
}

require __DIR__ . '/../connect.php';
require_once __DIR__ . '/../_lang.php';
require __DIR__ . '/../activity_log_helper.php';

$id_user = $_SESSION['id_user'];
$role = $_SESSION['role'] ?? 'user';
$is_admin = ($role === 'admin');
$message = '';
$message_type = 'success';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $hotel_id = trim($_POST['hotel_id'] ?? '');

    try {
        // Verify hotel ownership
        if (!$is_admin) {
            $stmt = $conn->prepare("SELECT 1 FROM hotel WHERE hotel_id = ? AND owner_id = ?");
            if (!$stmt) throw new Exception('Database error');
            $stmt->bind_param('ss', $hotel_id, $id_user);
            $stmt->execute();
            $owns_hotel = $stmt->get_result()->fetch_row();
            $stmt->close();
            if (!$owns_hotel) throw new Exception('Hotel tidak ditemukan atau bukan milik Anda');
        }

        if ($action === 'toggle_facility') {
            $fasilitas_id = trim($_POST['fasilitas_id'] ?? '');
            $is_active = (int)($_POST['is_active'] ?? 0);

            // Check if relation exists
            $check_stmt = $conn->prepare("SELECT id FROM hotel_fasilitas_ekstra WHERE hotel_id = ? AND fasilitas_id = ?");
            $check_stmt->bind_param('ss', $hotel_id, $fasilitas_id);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->fetch_row();
            $check_stmt->close();

            if ($exists) {
                // Update existing
                $stmt = $conn->prepare("UPDATE hotel_fasilitas_ekstra SET is_active = ? WHERE hotel_id = ? AND fasilitas_id = ?");
                $stmt->bind_param('iss', $is_active, $hotel_id, $fasilitas_id);
                $stmt->execute();
                $stmt->close();
            } else {
                // Insert new
                $stmt = $conn->prepare("INSERT INTO hotel_fasilitas_ekstra (hotel_id, fasilitas_id, is_active) VALUES (?, ?, ?)");
                $stmt->bind_param('ssi', $hotel_id, $fasilitas_id, $is_active);
                $stmt->execute();
                $stmt->close();
            }

            $status_text = $is_active ? 'diaktifkan' : 'dinonaktifkan';
            logActivity($conn, $id_user, 'toggle_extra_facility', "Extra facility $fasilitas_id $status_text untuk hotel $hotel_id", 'hotel_facility', $hotel_id);
            
            $_SESSION['extra_fac_message'] = "Fasilitas berhasil $status_text";
            $_SESSION['extra_fac_message_type'] = 'success';
        }

        if ($action === 'update_price') {
            $fasilitas_id = trim($_POST['fasilitas_id'] ?? '');
            $harga_override = trim($_POST['harga_override'] ?? '');
            
            // Empty string or null means use default price
            $harga_value = ($harga_override === '' || $harga_override === null) ? null : (float)$harga_override;

            // Check if relation exists
            $check_stmt = $conn->prepare("SELECT id FROM hotel_fasilitas_ekstra WHERE hotel_id = ? AND fasilitas_id = ?");
            $check_stmt->bind_param('ss', $hotel_id, $fasilitas_id);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->fetch_row();
            $check_stmt->close();

            if ($exists) {
                // Update existing
                if ($harga_value === null) {
                    $stmt = $conn->prepare("UPDATE hotel_fasilitas_ekstra SET harga_override = NULL WHERE hotel_id = ? AND fasilitas_id = ?");
                    $stmt->bind_param('ss', $hotel_id, $fasilitas_id);
                } else {
                    $stmt = $conn->prepare("UPDATE hotel_fasilitas_ekstra SET harga_override = ? WHERE hotel_id = ? AND fasilitas_id = ?");
                    $stmt->bind_param('dss', $harga_value, $hotel_id, $fasilitas_id);
                }
                $stmt->execute();
                $stmt->close();
            } else {
                // Insert new
                if ($harga_value === null) {
                    $stmt = $conn->prepare("INSERT INTO hotel_fasilitas_ekstra (hotel_id, fasilitas_id, harga_override, is_active) VALUES (?, ?, NULL, 0)");
                    $stmt->bind_param('ss', $hotel_id, $fasilitas_id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO hotel_fasilitas_ekstra (hotel_id, fasilitas_id, harga_override, is_active) VALUES (?, ?, ?, 0)");
                    $stmt->bind_param('ssd', $hotel_id, $fasilitas_id, $harga_value);
                }
                $stmt->execute();
                $stmt->close();
            }

            logActivity($conn, $id_user, 'update_extra_facility_price', "Updated price for facility $fasilitas_id in hotel $hotel_id", 'hotel_facility', $hotel_id);
            
            $_SESSION['extra_fac_message'] = 'Harga fasilitas berhasil diperbarui';
            $_SESSION['extra_fac_message_type'] = 'success';
        }

        header("Location: extra_facilities_manage.php?hotel_id=" . urlencode($hotel_id));
        exit;
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'error';
    }
}

// Check for session messages
if (isset($_SESSION['extra_fac_message'])) {
    $message = $_SESSION['extra_fac_message'];
    $message_type = $_SESSION['extra_fac_message_type'] ?? 'success';
    unset($_SESSION['extra_fac_message'], $_SESSION['extra_fac_message_type']);
}

// Get list of hotels
$hotels = [];
if ($is_admin) {
    $stmt = $conn->prepare("SELECT hotel_id, nama_hotel, kota FROM hotel ORDER BY nama_hotel");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $hotels[] = $row;
        }
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare("SELECT hotel_id, nama_hotel, kota FROM hotel WHERE owner_id = ? ORDER BY nama_hotel");
    if ($stmt) {
        $stmt->bind_param('s', $id_user);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $hotels[] = $row;
        }
        $stmt->close();
    }
}

// Get selected hotel
$selected_hotel = null;
$selected_hotel_id = $_GET['hotel_id'] ?? '';

if ($selected_hotel_id) {
    if ($is_admin) {
        $stmt = $conn->prepare("SELECT * FROM hotel WHERE hotel_id = ?");
        if ($stmt) {
            $stmt->bind_param('s', $selected_hotel_id);
            $stmt->execute();
            $selected_hotel = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("SELECT * FROM hotel WHERE hotel_id = ? AND owner_id = ?");
        if ($stmt) {
            $stmt->bind_param('ss', $selected_hotel_id, $id_user);
            $stmt->execute();
            $selected_hotel = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
}

// Get all available extra facilities with hotel-specific settings
$facilities = [];
if ($selected_hotel) {
    $sql = "SELECT 
                fe.*,
                hfe.is_active,
                hfe.harga_override,
                COALESCE(hfe.harga_override, fe.harga) as harga_final
            FROM fasilitas_ekstra fe
            LEFT JOIN hotel_fasilitas_ekstra hfe 
                ON fe.fasilitas_id = hfe.fasilitas_id 
                AND hfe.hotel_id = ?
            WHERE fe.status = 'Available'
            ORDER BY fe.kategori, fe.nama_fasilitas";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $selected_hotel_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $facilities[] = $row;
        }
        $stmt->close();
    }
}

// Get user profile for header
$stmt = $conn->prepare("SELECT username, email, first_name, last_name, profile_picture FROM user WHERE id_user = ?");
$profile_picture = null;
$user_initials = 'U';

if ($stmt) {
    $stmt->bind_param("s", $id_user);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    
    if ($u) {
        $first = $u['first_name'] ?? '';
        $last = $u['last_name'] ?? '';
        $user_initials = strtoupper(
            (empty($first) ? '' : $first[0]) . 
            (empty($last) ? '' : $last[0])
        );
        if (empty($user_initials)) {
            $user_initials = 'U';
        }
    }
    
    if ($u && !empty($u['profile_picture'])) {
        $possible_paths = [
            __DIR__ . '/../../uploads/' . $u['profile_picture'],
            __DIR__ . '/../../uploads/profiles/' . $u['profile_picture'],
            __DIR__ . '/../../uploads/users/' . $u['profile_picture']
        ];
        
        foreach ($possible_paths as $check_path) {
            if (file_exists($check_path)) {
                $profile_picture = $u['profile_picture'];
                break;
            }
        }
        
        if ($profile_picture === null && file_exists(__DIR__ . '/../../uploads/' . $u['profile_picture'])) {
            $profile_picture = $u['profile_picture'];
        }
    }
    
    $stmt->close();
}

$avatar_colors = ['#eb6834', '#2a78d6', '#1baf7a', '#eda100', '#e87ba4', '#4a3aa7'];
$color_index = abs(crc32($user_initials ?? 'U')) % count($avatar_colors);
$fallback_color = $avatar_colors[$color_index];
$fallback_avatar = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 200 200%22%3E%3Crect fill=%22' . urlencode($fallback_color) . '%22 width=%22200%22 height=%22200%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-size=%2280%22 font-weight=%22bold%22 fill=%22white%22 text-anchor=%22middle%22 dy=%22.35em%22 font-family=%22Arial, sans-serif%22%3E' . urlencode($user_initials) . '%3C/text%3E%3C/svg%3E';

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Fasilitas Tambahan - TripVerse</title>
    <link rel="stylesheet" href="../../css/owner_dashboard.css?v=2.1.2">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .facility-management-container {
            padding: 30px;
        }

        .hotel-selection {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(15, 23, 43, 0.1);
        }

        .hotel-selection h2 {
            color: #0f1724;
            font-size: 1.4rem;
            margin-bottom: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .hotel-selection h2 .material-icons {
            color: #FF7A3D;
        }

        .hotel-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e3e8ef;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s;
            background: white;
        }

        .hotel-select:focus {
            outline: none;
            border-color: #FF7A3D;
            box-shadow: 0 0 0 3px rgba(15, 23, 43, 0.08);
        }

        .facilities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .facility-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .facility-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }

        .facility-card.active {
            border-color: #0f9d58;
            background: linear-gradient(135deg, #e8fdf1 0%, #ffffff 100%);
        }

        .facility-card.inactive {
            background: #f8f9fa;
        }

        .facility-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .facility-info h3 {
            color: #0f1724;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .facility-category {
            display: inline-block;
            padding: 4px 12px;
            background: #eef2ff;
            color: #3b82f6;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .facility-description {
            color: #666;
            font-size: 0.9rem;
            margin: 12px 0;
            line-height: 1.5;
        }

        .price-section {
            margin: 16px 0;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e3e8ef;
        }

        .default-price {
            color: #6b7280;
            font-size: 0.85rem;
            margin-bottom: 8px;
        }

        .default-price strong {
            color: #0f1724;
        }

        .price-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 8px;
        }

        .price-input {
            flex: 1;
            padding: 8px 12px;
            border: 2px solid #e3e8ef;
            border-radius: 8px;
            font-size: 0.9rem;
            background: white;
            transition: all 0.3s;
        }

        .price-input:focus {
            outline: none;
            border-color: #FF7A3D;
            box-shadow: 0 0 0 3px rgba(15, 23, 43, 0.08);
        }

        .btn-update-price {
            padding: 8px 16px;
            background: #FF7A3D;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s;
            font-weight: 500;
        }

        .btn-update-price:hover {
            background: #0d47a1;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(15, 23, 43, 0.2);
        }

        .btn-reset-price {
            padding: 8px 12px;
            background: #f8f9fa;
            color: #6b7280;
            border: 1px solid #e3e8ef;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s;
        }

        .btn-reset-price:hover {
            background: #e3e8ef;
            color: #4b5563;
        }

        .toggle-switch {
            position: relative;
            width: 70px;
            height: 35px;
            flex-shrink: 0;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #e5e7eb;
            transition: 0.4s;
            border-radius: 35px;
            border: 3px solid #9ca3af;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }

        .toggle-slider:hover {
            background-color: #d1d5db;
            transform: scale(1.05);
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.3s;
            border-radius: 50%;
            box-shadow: 0 3px 6px rgba(0,0,0,0.3);
        }

        .toggle-switch input:checked + .toggle-slider {
            background-color: #0f9d58;
            border-color: #0d7a45;
            box-shadow: 0 2px 6px rgba(15, 157, 88, 0.3);
        }

        .toggle-switch input:checked + .toggle-slider:hover {
            background-color: #0d8a4c;
            transform: scale(1.05);
        }

        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(35px);
        }

        /* Label untuk toggle */
        .toggle-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px;
        }

        .toggle-label {
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 12px;
        }

        .status-badge.active {
            background: #e8fdf1;
            color: #0f9d58;
            border: 1px solid rgba(15, 157, 88, 0.08);
        }

        .status-badge.inactive {
            background: #fff1f1;
            color: #d93025;
            border: 1px solid rgba(217, 48, 37, 0.08);
        }

        .status-badge .material-icons {
            font-size: 16px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state .material-icons {
            font-size: 64px;
            color: #ddd;
            margin-bottom: 16px;
        }

        .notification {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        }

        .notification.success {
            background: #e8fdf1;
            color: #0f9d58;
            border: 1px solid rgba(15, 157, 88, 0.2);
        }

        .notification.error {
            background: #fff1f1;
            color: #d93025;
            border: 1px solid rgba(217, 48, 37, 0.2);
        }

        .notification .material-icons {
            font-size: 24px;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .facilities-grid {
                grid-template-columns: 1fr;
            }

            .facility-management-container {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Owner-specific sidebar -->
    <!-- Kept outside .owner-sidebar on purpose: the sidebar uses a transform
         when collapsed, and a transformed ancestor becomes the containing
         block for position:fixed children -- so a button inside it slid off
         screen with the sidebar and could never be clicked again. -->
    <button id="toggleSidebar" class="sidebar-toggle" aria-label="Toggle sidebar">
        <span class="material-icons">menu</span>
    </button>

    <div class="owner-sidebar" id="owner-sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <img src="../../img/logo.png" alt="TripVerse Logo" class="logo-img" />
                <div class="logo-text-group">
                    <span class="logo-text">TripVerse</span>
                    <span class="logo-subtitle"><?= te('Dasbor Supplier') ?></span>
                </div>
            </div>
        </div>

        <div class="sidebar-brand-lang">
            <?php include __DIR__ . '/../_lang_switch_inner.php'; ?>
        </div>

        <div class="profile-section">
            <div class="profile-avatar">
                <?php if ($profile_picture): ?>
                    <img src="../../uploads/<?php echo htmlspecialchars($profile_picture); ?>" 
                         alt="<?php echo htmlspecialchars(($u['first_name'] ?? 'User') . ' ' . ($u['last_name'] ?? '')); ?>"
                         onerror="this.src='<?php echo $fallback_avatar; ?>'">
                <?php else: ?>
                    <img src="<?php echo $fallback_avatar; ?>" 
                         alt="<?php echo htmlspecialchars(($u['first_name'] ?? 'User') . ' ' . ($u['last_name'] ?? '')); ?>">
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars(($u['first_name'] ?? 'User') . ' ' . ($u['last_name'] ?? '')); ?></h3>
                <p class="profile-role"><?php echo $is_admin ? 'Administrator' : 'Hotel Owner'; ?></p>
                <p class="profile-email"><?php echo htmlspecialchars($u['email'] ?? ''); ?></p>
            </div>
        </div>

        <nav class="owner-nav">
            <a href="owner_dashboard.php" class="nav-item">
                <span class="material-icons">dashboard</span>
                <span><?= te('Dashboard') ?></span>
            </a>
            <a href="hotel_manage.php" class="nav-item">
                <span class="material-icons">hotel</span>
                <span><?= te('Kelola Hotel') ?></span>
            </a>
            <a href="room_management.php" class="nav-item">
                <span class="material-icons">bed</span>
                <span><?= te('Kelola Kamar') ?></span>
            </a>
            <a href="extra_facilities_manage.php" class="nav-item active">
                <span class="material-icons">room_service</span>
                <span><?= te('Fasilitas Tambahan') ?></span>
            </a>
            <a href="booking_management.php" class="nav-item">
                <span class="material-icons">book_online</span>
                <span><?= te('Pemesanan') ?></span>
            </a>
            <a href="../admin/activity_log.php" class="nav-item">
                <span class="material-icons">history</span>
                <span><?= te('Log Aktivitas') ?></span>
            </a>
            <a href="../auth/logout.php" class="nav-item logout">
                <span class="material-icons">logout</span>
                <span><?= te('Keluar') ?></span>
            </a>
        </nav>
    </div>


    <main class="main-content" id="main-content">
        <header class="main-header">
            <div class="header-left">
                <h1><?= te('Kelola Fasilitas Tambahan') ?></h1>
                <p class="header-subtitle"><?= te('Atur fasilitas berbayar untuk setiap hotel Anda') ?></p>
            </div>
        </header>

        <div class="facility-management-container">
            <?php if ($message): ?>
                <div class="notification <?= $message_type ?>">
                    <span class="material-icons"><?= $message_type === 'success' ? 'check_circle' : 'error' ?></span>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <!-- Hotel Selection -->
            <section class="hotel-selection">
                <h2>
                    <span class="material-icons">hotel</span>
                    <?= te('Pilih Hotel') ?>
                </h2>
                <select class="hotel-select" onchange="if(this.value) window.location.href='extra_facilities_manage.php?hotel_id=' + this.value">
                    <option value="">-- Pilih Hotel --</option>
                    <?php foreach ($hotels as $hotel): ?>
                        <option value="<?= htmlspecialchars($hotel['hotel_id']) ?>" 
                                <?= $selected_hotel_id === $hotel['hotel_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($hotel['nama_hotel']) ?> - <?= htmlspecialchars($hotel['kota']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </section>

            <!-- Facilities List -->
            <?php if ($selected_hotel): ?>
                <section>
                    <h2 style="color: #0f1724; margin-bottom: 20px; font-size: 1.5rem; display: flex; align-items: center; gap: 10px;">
                        <span class="material-icons" style="color: #FF7A3D;">room_service</span>
                        Fasilitas Tambahan untuk <?= htmlspecialchars($selected_hotel['nama_hotel']) ?>
                    </h2>
                    
                    <?php if (empty($facilities)): ?>
                        <div class="empty-state">
                            <span class="material-icons">inbox</span>
                            <h3><?= te('Tidak ada fasilitas tambahan tersedia') ?></h3>
                            <p>Hubungi admin untuk menambahkan fasilitas tambahan</p>
                        </div>
                    <?php else: ?>
                        <div class="facilities-grid">
                            <?php 
                            $current_category = '';
                            foreach ($facilities as $facility): 
                                $is_active = (int)($facility['is_active'] ?? 0);
                                $has_override = !empty($facility['harga_override']);
                            ?>
                                <div class="facility-card <?= $is_active ? 'active' : 'inactive' ?>">
                                    <div class="facility-header">
                                        <div class="facility-info">
                                            <h3><?= htmlspecialchars($facility['nama_fasilitas']) ?></h3>
                                            <span class="facility-category"><?= htmlspecialchars($facility['kategori']) ?></span>
                                        </div>
                                        <form method="POST" style="margin: 0;">
                                            <input type="hidden" name="action" value="toggle_facility">
                                            <input type="hidden" name="hotel_id" value="<?= htmlspecialchars($selected_hotel_id) ?>">
                                            <input type="hidden" name="fasilitas_id" value="<?= htmlspecialchars($facility['fasilitas_id']) ?>">
                                            <input type="hidden" name="is_active" value="<?= $is_active ? 0 : 1 ?>">
                                            <div class="toggle-container">
                                                <div style="font-size: 0.7rem; color: #6b7280; font-weight: 600; margin-bottom: -2px;">AKTIFKAN</div>
                                                <label class="toggle-switch" title="Klik untuk <?= $is_active ? 'menonaktifkan' : 'mengaktifkan' ?> fasilitas">
                                                    <input type="checkbox" <?= $is_active ? 'checked' : '' ?> 
                                                           onchange="this.form.submit()">
                                                    <span class="toggle-slider"></span>
                                                </label>
                                                <div class="toggle-label <?= $is_active ? 'active' : 'inactive' ?>">
                                                </div>
                                            </div>
                                        </form>
                                    </div>

                                    <p class="facility-description">
                                        <?= htmlspecialchars($facility['deskripsi'] ?? 'Tidak ada deskripsi') ?>
                                    </p>

                                    <div class="price-section">
                                        <div class="default-price">
                                            <strong>Harga Default:</strong> Rp <?= number_format($facility['harga'], 0, ',', '.') ?>
                                        </div>
                                        
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update_price">
                                            <input type="hidden" name="hotel_id" value="<?= htmlspecialchars($selected_hotel_id) ?>">
                                            <input type="hidden" name="fasilitas_id" value="<?= htmlspecialchars($facility['fasilitas_id']) ?>">
                                            
                                            <div class="price-input-group">
                                                <input type="number" 
                                                       name="harga_override" 
                                                       class="price-input" 
                                                       placeholder="Harga khusus (opsional)"
                                                       value="<?= $has_override ? $facility['harga_override'] : '' ?>"
                                                       min="0"
                                                       step="1000">
                                                <button type="submit" class="btn-update-price">Simpan</button>
                                                <?php if ($has_override): ?>
                                                    <button type="submit" class="btn-reset-price" 
                                                            onclick="this.form.harga_override.value=''; return true;">
                                                        Reset
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </form>

                                        <?php if ($has_override): ?>
                                            <small style="color: #4caf50; display: block; margin-top: 8px;">
                                                ✓ Menggunakan harga khusus: Rp <?= number_format($facility['harga_override'], 0, ',', '.') ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>

                                    <div class="status-badge <?= $is_active ? 'active' : 'inactive' ?>">
                                        <span class="material-icons"><?= $is_active ? 'check_circle' : 'cancel' ?></span>
                                        <span><?= $is_active ? 'Aktif - Ditampilkan ke tamu' : 'Nonaktif - Disembunyikan' ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-icons">location_city</span>
                    <h3><?= te('Pilih Hotel') ?></h3>
                    <p>Silakan pilih hotel terlebih dahulu untuk mengelola fasilitas tambahan</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        const sidebar = document.getElementById('owner-sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        const mainContent = document.getElementById('main-content');

        const sidebarState = localStorage.getItem('sidebarState');
        if (sidebarState === 'collapsed') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            document.body.classList.add('sidebar-collapsed');
        }

        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            document.body.classList.toggle('sidebar-collapsed', sidebar.classList.contains('collapsed'));
            localStorage.setItem('sidebarState', sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded');
        });

        // Auto-hide notification after 5 seconds
        <?php if ($message): ?>
            setTimeout(() => {
                const notification = document.querySelector('.notification');
                if (notification) {
                    notification.style.transition = 'opacity 0.5s, transform 0.5s';
                    notification.style.opacity = '0';
                    notification.style.transform = 'translateY(-20px)';
                    setTimeout(() => notification.remove(), 500);
                }
            }, 5000);
        <?php endif; ?>
    </script>
</body>
</html>
