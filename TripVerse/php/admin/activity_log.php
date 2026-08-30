<?php
session_start();
if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit;
}
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['owner', 'admin'])) {
    echo "<script>alert('Akses ditolak!'); window.location='../home.php';</script>";
    exit;
}

require __DIR__ . '/../connect.php';
require_once __DIR__ . '/../_lang.php';
require __DIR__ . '/../activity_log_helper.php';

$id_user = $_SESSION['id_user'];
$user_role = $_SESSION['role'] ?? '';

// Filter parameters
$action_filter = $_GET['action_type'] ?? '';
$hotel_filter = $_GET['hotel_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query
$whereClause = "al.user_id = ?";
$params = [$id_user];
$types = 's';

if (!empty($action_filter)) {
    $whereClause .= " AND al.action_type = ?";
    $params[] = $action_filter;
    $types .= 's';
}

if (!empty($hotel_filter)) {
    $whereClause .= " AND al.hotel_id = ?";
    $params[] = $hotel_filter;
    $types .= 's';
}

if (!empty($date_from)) {
    $whereClause .= " AND DATE(al.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if (!empty($date_to)) {
    $whereClause .= " AND DATE(al.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

// Get activity logs
$logs_query = "SELECT al.*, u.first_name, u.last_name, u.email, h.nama_hotel
               FROM activity_log al
               LEFT JOIN user u ON al.user_id = u.id_user
               LEFT JOIN hotel h ON al.hotel_id = h.hotel_id
               WHERE $whereClause
               ORDER BY al.created_at DESC
               LIMIT 500";

$logs_stmt = $conn->prepare($logs_query);
$activity_logs = [];
if ($logs_stmt) {
    if (!empty($params)) {
        $logs_stmt->bind_param($types, ...$params);
    }
    $logs_stmt->execute();
    $logs_result = $logs_stmt->get_result();
    while ($row = $logs_result->fetch_assoc()) {
        $activity_logs[] = $row;
    }
    $logs_stmt->close();
}

// Get distinct action types for filter
$action_types_query = "SELECT DISTINCT action_type FROM activity_log WHERE user_id = ? ORDER BY action_type";
$action_types_stmt = $conn->prepare($action_types_query);
$action_types = [];
if ($action_types_stmt) {
    $action_types_stmt->bind_param("s", $id_user);
    $action_types_stmt->execute();
    $action_types_result = $action_types_stmt->get_result();
    while ($row = $action_types_result->fetch_assoc()) {
        $action_types[] = $row['action_type'];
    }
    $action_types_stmt->close();
}
// Ambil info owner untuk header
$id_user = $_SESSION['id_user'];
$stmt = $conn->prepare("SELECT username, email, first_name, last_name, profile_picture FROM user WHERE id_user = ?");
$profile_picture = null;
$user_initials = 'U';

if ($stmt) {
    $stmt->bind_param("s", $id_user);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    
    // Generate initials dari nama
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
    
    // Check for profile picture
    if ($u && !empty($u['profile_picture'])) {
        // Check multiple possible locations
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
        
        // If not found in specific folders, check root uploads
        if ($profile_picture === null && file_exists(__DIR__ . '/../../uploads/' . $u['profile_picture'])) {
            $profile_picture = $u['profile_picture'];
        }
    }
    
    $stmt->close();
}

// Generate fallback avatar SVG
$avatar_colors = ['#eb6834', '#2a78d6', '#1baf7a', '#eda100', '#e87ba4', '#4a3aa7'];
$color_index = abs(crc32($user_initials ?? 'U')) % count($avatar_colors);
$fallback_color = $avatar_colors[$color_index];
$fallback_avatar = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 200 200%22%3E%3Crect fill=%22' . urlencode($fallback_color) . '%22 width=%22200%22 height=%22200%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-size=%2280%22 font-weight=%22bold%22 fill=%22white%22 text-anchor=%22middle%22 dy=%22.35em%22 font-family=%22Arial, sans-serif%22%3E' . urlencode($user_initials) . '%3C/text%3E%3C/svg%3E';

// Get hotels for filter
if ($user_role === 'admin') {
    $hotels_query = "SELECT DISTINCT h.hotel_id, h.nama_hotel 
                     FROM hotel h
                     INNER JOIN activity_log al ON h.hotel_id = al.hotel_id
                     WHERE al.user_id = ?
                     ORDER BY h.nama_hotel";
    $hotels_stmt = $conn->prepare($hotels_query);
    $hotels = [];
    if ($hotels_stmt) {
        $hotels_stmt->bind_param("s", $id_user);
        $hotels_stmt->execute();
        $hotels_result = $hotels_stmt->get_result();
        while ($row = $hotels_result->fetch_assoc()) {
            $hotels[] = $row;
        }
        $hotels_stmt->close();
    }
} else {
    $hotels_query = "SELECT DISTINCT h.hotel_id, h.nama_hotel 
                     FROM hotel h
                     INNER JOIN activity_log al ON h.hotel_id = al.hotel_id
                     WHERE h.owner_id = ? AND al.user_id = ?
                     ORDER BY h.nama_hotel";
    $hotels_stmt = $conn->prepare($hotels_query);
    $hotels = [];
    if ($hotels_stmt) {
        $hotels_stmt->bind_param("ss", $id_user, $id_user);
        $hotels_stmt->execute();
        $hotels_result = $hotels_stmt->get_result();
        while ($row = $hotels_result->fetch_assoc()) {
            $hotels[] = $row;
        }
        $hotels_stmt->close();
    }
}

$conn->close();

// Action type labels
$action_labels = [
    'add_hotel' => 'Add Hotel',
    'edit_hotel' => 'Edit Hotel',
    'delete_hotel' => 'Delete Hotel',
    'add_room' => 'Add Room',
    'edit_room' => 'Edit Room',
    'delete_room' => 'Delete Room',
    'add_room_type' => 'Add Room Type',
    'edit_room_type' => 'Edit Room Type',
    'delete_room_type' => 'Delete Room Type',
    'update_booking_status' => 'Update Booking Status',
    'confirm_booking' => 'Confirm Booking',
    'cancel_booking' => 'Cancel Booking',
];

// Action icons
$action_icons = [
    'add_hotel' => 'add_business',
    'edit_hotel' => 'edit',
    'delete_hotel' => 'delete',
    'add_room' => 'bed',
    'edit_room' => 'edit',
    'delete_room' => 'delete',
    'add_room_type' => 'category',
    'edit_room_type' => 'edit',
    'delete_room_type' => 'delete',
    'update_booking_status' => 'update',
    'confirm_booking' => 'check_circle',
    'cancel_booking' => 'cancel',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - TripVerse Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../../css/dashboard.css?v=2.1.2">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .filter-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            margin-left: 30px;
            margin-right: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            color: #FF7A3D;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .filter-group select,
        .filter-group input {
            padding: 10px 12px;
            border: 2px solid rgba(15, 23, 43, 0.2);
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #FF7A3D;
            box-shadow: 0 0 0 3px rgba(15, 23, 43, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
        }

        .filter-btn {
            padding: 10px 20px;
            background: linear-gradient(135deg, #ffa726 0%, #FF7A3D 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 167, 38, 0.3);
        }

        .clear-btn {
            padding: 10px 20px;
            background: rgba(15, 23, 43, 0.1);
            color: #FF7A3D;
            border: 2px solid rgba(15, 23, 43, 0.2);
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .clear-btn:hover {
            background: rgba(15, 23, 43, 0.2);
        }

        .activity-logs-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-left: 30px;
            margin-right: 30px;
        }

        .activity-log-item {
            display: flex;
            gap: 15px;
            padding: 15px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            transition: background 0.2s ease;
        }

        .activity-log-item:hover {
            background: rgba(15, 23, 43, 0.02);
        }

        .activity-log-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 43, 0.1);
            color: #FF7A3D;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .activity-title {
            font-weight: 600;
            color: #FF7A3D;
            font-size: 1rem;
        }

        .activity-time {
            font-size: 0.85rem;
            color: #666;
        }

        .activity-description {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 8px;
        }

        .activity-details {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 0.85rem;
            color: #888;
        }

        .activity-detail-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .activity-detail-item .material-icons {
            font-size: 1rem;
        }

        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            margin-left: 30px;
            margin-right: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .stat-label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #FF7A3D 0%, #E8672B 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="../../img/logo.png" alt="TripVerse Logo" class="sidebar-brand-logo" />
            <div class="sidebar-brand-text">
                <span class="sidebar-brand-title">TripVerse</span>
                <span class="sidebar-brand-subtitle"><?= te('Dasbor Admin') ?></span>
            </div>
        </div>

        <div class="sidebar-brand-lang">
            <?php include __DIR__ . '/../_lang_switch_inner.php'; ?>
        </div>

        <div class="profile-header">
            <div class="profile-photo-section">
                <div class="profile-photo-container">
                    <?php if ($profile_picture): ?>
                        <img src="../../uploads/<?php echo htmlspecialchars($profile_picture); ?>"
                            alt="Profile Photo"
                            class="profile-photo"
                            id="profilePhoto"
                            onerror="this.src='<?php echo $fallback_avatar; ?>'">
                    <?php else: ?>
                        <img src="<?php echo $fallback_avatar; ?>"
                            alt="Profile Photo"
                            class="profile-photo"
                            id="profilePhoto">
                    <?php endif; ?>

                    <div class="profile-overlay">
                        <span class="material-icons">edit</span>
                    </div>

                    <form id="uploadForm" action="dashboard.php" method="POST" enctype="multipart/form-data" style="display:none;">
                        <input type="file" name="profile_photo" id="profileUpload" accept="image/*" />
                    </form>
                </div>

                <div class="profile-info">
                    <h2><?php echo htmlspecialchars(($u['first_name'] ?? 'User') . ' ' . ($u['last_name'] ?? '')); ?></h2>
                    <p><?php echo htmlspecialchars($u['email'] ?? ''); ?></p>

                    <div class="user-dropdown">
                        <button class="user-info" aria-haspopup="true" aria-expanded="false" onclick="toggleDropdown(this)">
                            <span class="dropdown-text"><?= te('Kelola Akun') ?></span>
                            <span class="material-icons dropdown-arrow">expand_more</span>
                        </button>

                        <div class="dropdown-content" role="menu" aria-hidden="true">
                            <a href="profile.php" class="dropdown-item">
                                <span class="material-icons">person</span>
                                <span>Edit Profile</span>
                            </a>
                            <a href="../auth/logout.php" class="dropdown-item">
                                <span class="material-icons">logout</span>
                                <span><?= te('Keluar') ?></span>
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
                <span><?= te('Ringkasan Eksekutif') ?></span>
            </a>

            <!-- SUPPLIER APPROVAL -->
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="supplier_approvals.php">
                    <span class="material-icons">approval</span>
                    <span><?= te('Manajemen Supplier') ?></span>
                </a>
            <?php endif; ?>

            <!-- PROMO MANAGEMENT -->
            <a href="promo_management.php">
                <span class="material-icons">campaign</span>
                <span><?= te('Manajemen Promo') ?></span>
            </a>

            <!-- ANALYTICS & INSIGHTS -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="analyticsDropdown">
                    <span class="material-icons">monitor</span>
                    <span><?= te('Monitoring Performa') ?></span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="analyticsDropdown">
                    <a href="performance_analytics.php">
                        <span class="material-icons">bar_chart</span>
                        <span><?= te('Statistik Performa') ?></span>
                    </a>
                    <a href="market_analysis.php">
                        <span class="material-icons">trending_up</span>
                        <span><?= te('Tren Booking') ?></span>
                    </a>
                </div>
            </div>

            <!-- DECISION SUPPORT MODULES -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="decisionDropdown">
                    <span class="material-icons">analytics</span>
                    <span><?= te('Analisis Statistik') ?></span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="decisionDropdown">
                    <a href="revenue_optimization.php">
                        <span class="material-icons">attach_money</span>
                        <span><?= te('Statistik Pendapatan') ?></span>
                    </a>
                    <a href="occupancy_analysis.php">
                        <span class="material-icons">king_bed</span>
                        <span><?= te('Statistik Okupansi') ?></span>
                    </a>
                    <a href="alos_analysis.php">
                        <span class="material-icons">calendar_today</span>
                        <span><?= te('Statistik ALOS') ?></span>
                    </a>
                </div>
            </div>

            <!-- CUSTOMER INTELLIGENCE -->
            <a href="customerdss.php">
                <span class="material-icons">people</span>
                <span><?= te('Statistik Pelanggan') ?></span>
            </a>

            <!-- LOGOUT -->
            <a href="../auth/logout.php">
                <span class="material-icons">exit_to_app</span>
                <span><?= te('Keluar') ?></span>
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
                    <span class="notification-badge" id="notificationCount"><?= count($activity_logs) ?></span>
                </div>
            </div>
        </header>

        <div style="padding: 0 30px; margin-bottom: 20px;">
            <h1 style="font-size: 24px; color: #0F172B; margin-bottom: 5px;"><?= te('Log Aktivitas') ?></h1>
            <p style="color: #6b7280; font-size: 14px;"><?= te('Lacak semua tindakan dan aktivitas Anda') ?></p>
        </div>

        <!-- Statistics -->
        <section class="stats-section">
            <div class="stat-card">
                <div class="stat-label">Total Activities</div>
                <div class="stat-value"><?= count($activity_logs) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Today's Activities</div>
                <div class="stat-value"><?= count(array_filter($activity_logs, function($log) { return date('Y-m-d', strtotime($log['created_at'])) === date('Y-m-d'); })) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">This Week</div>
                <div class="stat-value"><?= count(array_filter($activity_logs, function($log) { 
                    $logDate = strtotime($log['created_at']);
                    $weekStart = strtotime('monday this week');
                    return $logDate >= $weekStart;
                })) ?></div>
            </div>
        </section>

        <!-- Filters -->
        <section class="filter-section">
            <form method="get" class="filter-form">
                <div class="filter-group">
                    <label for="action_type">Action Type</label>
                    <select id="action_type" name="action_type">
                        <option value="">All Actions</option>
                        <?php foreach ($action_types as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>" <?= $action_filter === $type ? 'selected' : '' ?>>
                                <?= htmlspecialchars($action_labels[$type] ?? ucfirst(str_replace('_', ' ', $type))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="hotel_id">Hotel</label>
                    <select id="hotel_id" name="hotel_id">
                        <option value="">All Hotels</option>
                        <?php foreach ($hotels as $hotel): ?>
                            <option value="<?= htmlspecialchars($hotel['hotel_id']) ?>" <?= $hotel_filter === $hotel['hotel_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($hotel['nama_hotel']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="date_from">From Date</label>
                    <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>

                <div class="filter-group">
                    <label for="date_to">To Date</label>
                    <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>

                <div class="filter-actions">
                    <button type="submit" class="filter-btn">
                        <span class="material-icons">filter_list</span>
                        Filter
                    </button>
                    <a href="activity_log.php" class="clear-btn">
                        <span class="material-icons">clear</span>
                        Clear
                    </a>
                </div>
            </form>
        </section>

        <!-- Activity Logs -->
        <section class="activity-logs-section">
            <?php if (empty($activity_logs)): ?>
                <div class="empty-state">
                    <span class="material-icons">history</span>
                    <h3><?= te('Belum ada aktivitas') ?></h3>
                    <p><?= te('Aktivitas Anda akan muncul di sini saat Anda melakukan tindakan') ?></p>
                </div>
            <?php else: ?>
                <div class="activity-logs-list">
                    <?php foreach ($activity_logs as $log): ?>
                    <div class="activity-log-item">
                        <div class="activity-icon">
                            <span class="material-icons"><?= $action_icons[$log['action_type']] ?? 'info' ?></span>
                        </div>
                        <div class="activity-content">
                            <div class="activity-header">
                                <div class="activity-title">
                                    <?= htmlspecialchars($action_labels[$log['action_type']] ?? ucfirst(str_replace('_', ' ', $log['action_type']))) ?>
                                </div>
                                <div class="activity-time">
                                    <?= date('M d, Y H:i:s', strtotime($log['created_at'])) ?>
                                </div>
                            </div>
                            <div class="activity-description">
                                <?= htmlspecialchars($log['action_description']) ?>
                            </div>
                            <div class="activity-details">
                                <?php if ($log['entity_name']): ?>
                                    <div class="activity-detail-item">
                                        <span class="material-icons">label</span>
                                        <span><?= htmlspecialchars($log['entity_name']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($log['nama_hotel']): ?>
                                    <div class="activity-detail-item">
                                        <span class="material-icons">hotel</span>
                                        <span><?= htmlspecialchars($log['nama_hotel']) ?></span>
                                    </div>
                                <?php endif; ?>
                                
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
        // Sidebar toggle
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

        // Dropdown toggle
        function toggleDropdown(btn) {
            const dropdown = btn.nextElementSibling;
            const isOpen = dropdown.classList.contains('show');
            document.querySelectorAll('.dropdown-content').forEach(d => d.classList.remove('show'));
            if (!isOpen) dropdown.classList.add('show');
            btn.setAttribute('aria-expanded', !isOpen);
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.user-dropdown')) {
                document.querySelectorAll('.dropdown-content').forEach(d => d.classList.remove('show'));
            }
        });

        // Submenu toggle for sidebar
        document.querySelectorAll('.booking-toggle').forEach(function(toggle) {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                const targetId = this.getAttribute('data-target');
                const submenu = document.getElementById(targetId);
                const icon = this.querySelector('.toggle-icon');
                if (submenu) {
                    submenu.classList.toggle('hidden');
                    if (icon) icon.style.transform = submenu.classList.contains('hidden') ? '' : 'rotate(180deg)';
                }
            });
        });
    </script>
</body>
</html>
