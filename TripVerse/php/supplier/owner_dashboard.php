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

// Get comprehensive dashboard statistics
$stats = [];

// Count total hotels owned by this owner
$hotel_count_stmt = $conn->prepare("SELECT COUNT(*) as total_hotels FROM hotel WHERE owner_id = ?");
if ($hotel_count_stmt) {
    $hotel_count_stmt->bind_param("s", $id_user);
    $hotel_count_stmt->execute();
    $hotel_count_result = $hotel_count_stmt->get_result()->fetch_assoc();
    $stats['total_hotels'] = $hotel_count_result['total_hotels'];
    $hotel_count_stmt->close();
}

// Count total rooms across all owned hotels
$room_count_stmt = $conn->prepare("SELECT COUNT(*) as total_rooms FROM kamar k 
                                  INNER JOIN hotel h ON k.hotel_id = h.hotel_id 
                                  WHERE h.owner_id = ?");
if ($room_count_stmt) {
    $room_count_stmt->bind_param("s", $id_user);
    $room_count_stmt->execute();
    $room_count_result = $room_count_stmt->get_result()->fetch_assoc();
    $stats['total_rooms'] = $room_count_result['total_rooms'];
    $room_count_stmt->close();
}

// Count total bookings for owned hotels
$booking_count_stmt = $conn->prepare("SELECT COUNT(*) as total_bookings FROM booking_hotel b
                                     INNER JOIN hotel h ON b.hotel_id = h.hotel_id 
                                     WHERE h.owner_id = ?");
if ($booking_count_stmt) {
    $booking_count_stmt->bind_param("s", $id_user);
    $booking_count_stmt->execute();
    $booking_count_result = $booking_count_stmt->get_result()->fetch_assoc();
    $stats['total_bookings'] = $booking_count_result['total_bookings'];
    $booking_count_stmt->close();
}

// Calculate total revenue (net after 5% admin fee)
$revenue_stmt = $conn->prepare("SELECT SUM(b.total_harga) as gross_revenue FROM booking_hotel b
                               INNER JOIN hotel h ON b.hotel_id = h.hotel_id 
                               WHERE h.owner_id = ? AND status = 'Completed'");
if ($revenue_stmt) {
    $revenue_stmt->bind_param("s", $id_user);
    $revenue_stmt->execute();
    $revenue_result = $revenue_stmt->get_result()->fetch_assoc();
    $gross_revenue = $revenue_result['gross_revenue'] ?? 0;
    // Deduct 5% admin fee
    $admin_fee = $gross_revenue * 0.05;
    $stats['total_revenue'] = $gross_revenue - $admin_fee;
    $stats['admin_fee'] = $admin_fee;
    $stats['gross_revenue'] = $gross_revenue;
    $revenue_stmt->close();
}

// Get recent hotels (last 5)
$recent_hotels_stmt = $conn->prepare("SELECT hotel_id, nama_hotel, kota, foto_hotel, harga_dasar 
                                     FROM hotel WHERE owner_id = ? 
                                     ORDER BY hotel_id DESC LIMIT 5");
$recent_hotels = [];
if ($recent_hotels_stmt) {
    $recent_hotels_stmt->bind_param("s", $id_user);
    $recent_hotels_stmt->execute();
    $recent_hotels_result = $recent_hotels_stmt->get_result();
    while ($row = $recent_hotels_result->fetch_assoc()) {
        $recent_hotels[] = $row;
    }
    $recent_hotels_stmt->close();
}

// Get recent bookings
$recent_bookings_stmt = $conn->prepare("SELECT b.booking_id, b.tanggal_checkin, b.tanggal_checkout, b.status, 
                                      h.nama_hotel, u.first_name, u.last_name
                                      FROM booking b
                                      INNER JOIN hotel h ON b.hotel_id = h.hotel_id
                                      INNER JOIN user u ON b.id_user = u.id_user
                                      WHERE h.owner_id = ?
                                      ORDER BY b.booking_id DESC LIMIT 5");
$recent_bookings = [];
if ($recent_bookings_stmt) {
    $recent_bookings_stmt->bind_param("s", $id_user);
    $recent_bookings_stmt->execute();
    $recent_bookings_result = $recent_bookings_stmt->get_result();
    while ($row = $recent_bookings_result->fetch_assoc()) {
        $recent_bookings[] = $row;
    }
    $recent_bookings_stmt->close();
}

// Get booking status breakdown per hotel
$status_breakdown_stmt = $conn->prepare("SELECT h.hotel_id, h.nama_hotel, b.status, COUNT(*) as count 
                                        FROM booking_hotel b
                                        INNER JOIN hotel h ON b.hotel_id = h.hotel_id
                                        WHERE h.owner_id = ?
                                        GROUP BY h.hotel_id, h.nama_hotel, b.status");
$status_breakdown_by_hotel = [];
$status_breakdown = []; // Overall status
if ($status_breakdown_stmt) {
    $status_breakdown_stmt->bind_param("s", $id_user);
    $status_breakdown_stmt->execute();
    $status_breakdown_result = $status_breakdown_stmt->get_result();
    while ($row = $status_breakdown_result->fetch_assoc()) {
        $hotel_id = $row['hotel_id'];
        $status = $row['status'];
        $count = $row['count'];
        
        if (!isset($status_breakdown_by_hotel[$hotel_id])) {
            $status_breakdown_by_hotel[$hotel_id] = [
                'nama_hotel' => $row['nama_hotel'],
                'statuses' => []
            ];
        }
        $status_breakdown_by_hotel[$hotel_id]['statuses'][$status] = $count;
        
        // Also accumulate overall status
        if (!isset($status_breakdown[$status])) {
            $status_breakdown[$status] = 0;
        }
        $status_breakdown[$status] += $count;
    }
    $status_breakdown_stmt->close();
}

// Get top performing hotels
$top_hotels_stmt = $conn->prepare("SELECT h.nama_hotel, h.kota, COUNT(b.booking_id) as total_bookings,
                                   SUM(b.total_harga) as total_revenue
                                   FROM hotel h
                                   LEFT JOIN booking_hotel b ON h.hotel_id = b.hotel_id AND b.status = 'Completed'
                                   WHERE h.owner_id = ?
                                   GROUP BY h.hotel_id
                                   ORDER BY total_bookings DESC
                                   LIMIT 5");
$top_hotels = [];
if ($top_hotels_stmt) {
    $top_hotels_stmt->bind_param("s", $id_user);
    $top_hotels_stmt->execute();
    $top_hotels_result = $top_hotels_stmt->get_result();
    while ($row = $top_hotels_result->fetch_assoc()) {
        $top_hotels[] = $row;
    }
    $top_hotels_stmt->close();
}

// Get occupancy over next 7 days using room-nights from booking_hotel and stock from jadwal_hotel
$occupancy_hotels_stmt = $conn->prepare("SELECT 
    h.hotel_id,
    h.nama_hotel,
    COALESCE(SUM(j.stok_total), 0) AS total_stock,
    COALESCE(SUM(j.terbooking), 0) AS current_booked_rooms,
    COALESCE((
        SELECT SUM(
            GREATEST(0, DATEDIFF(
                LEAST(b.check_out, DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
                GREATEST(b.check_in, CURDATE())
            )) * b.jumlah_kamar
        )
        FROM booking_hotel b
        WHERE b.hotel_id = h.hotel_id
          AND b.status IN ('Confirmed', 'Completed')
          AND b.check_in < DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND b.check_out > CURDATE()
    ), 0) AS occupied_room_nights
    FROM hotel h
    LEFT JOIN jadwal_hotel j ON h.hotel_id = j.hotel_id
    WHERE h.owner_id = ?
    GROUP BY h.hotel_id, h.nama_hotel
    HAVING total_stock > 0
    ORDER BY h.nama_hotel");
$occupancy_hotels = [];
if ($occupancy_hotels_stmt) {
    $occupancy_hotels_stmt->bind_param("s", $id_user);
    $occupancy_hotels_stmt->execute();
    $occupancy_result = $occupancy_hotels_stmt->get_result();
    
    while ($row = $occupancy_result->fetch_assoc()) {
        $total_stock = (int)($row['total_stock'] ?? 0);
        if ($total_stock < 0) { $total_stock = 0; }

        $occupied_room_nights = (int)($row['occupied_room_nights'] ?? 0);
        if ($occupied_room_nights < 0) { $occupied_room_nights = 0; }

        $days = 7; // window length in days
        $total_room_nights = $total_stock * $days;
        $avg_occupied = $days > 0 ? ($occupied_room_nights / $days) : 0;
        $occupancy_rate = $total_room_nights > 0 ? ($occupied_room_nights / $total_room_nights) * 100 : 0;
        $avg_available = max(0, $total_stock - $avg_occupied);

        // Current snapshot counts based on stock across room types (jadwal_hotel)
        $current_booked = (int)($row['current_booked_rooms'] ?? 0);
        if ($current_booked < 0) { $current_booked = 0; }
        if ($current_booked > $total_stock) { $current_booked = $total_stock; }
        $current_available = max(0, $total_stock - $current_booked);

        // Map UI counts to current snapshot (not percentages/averages)
        $row['occupied_rooms'] = $current_booked;
        $row['available_rooms'] = $current_available;
        $row['occupancy_rate'] = $occupancy_rate;
        $row['time_window_days'] = $days;
        // Also expose averages if needed later
        $row['avg_occupied_rooms_per_day'] = round($avg_occupied, 1);
        $row['avg_available_rooms_per_day'] = round($avg_available, 1);

        // Keep fields for compatibility
        $row['active_bookings_count'] = 0;
        $row['has_active_bookings'] = false;

        $occupancy_hotels[] = $row;
    }
    
    $occupancy_hotels_stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - TripVerse</title>
    <link rel="stylesheet" href="../../css/owner_dashboard.css?v=2.1.1">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Modern Dashboard Stats Cards */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            border-color: var(--card-color);
        }

        .stat-card:nth-child(1) {
            --card-color: #667eea;
        }

        .stat-card:nth-child(2) {
            --card-color: #f5576c;
        }

        .stat-card:nth-child(3) {
            --card-color: #4facfe;
        }

        .stat-card:nth-child(4) {
            --card-color: #43e97b;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #FF7A3D 0%, #E8672B 100%);
            color: white;
            box-shadow: 0 8px 25px rgba(15, 23, 43, 0.3);
        }

        .stat-icon .material-icons {
            font-size: 32px;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
        }

        .stat-content {
            flex: 1;
        }

        .stat-content h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 6px 0;
            line-height: 1.4;
        }

        .stat-content p {
            font-size: 1.75rem;
            color: #0f1724;
            margin: 0;
            font-weight: 700;
        }

        .stat-content small {
            display: block;
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 6px;
        }

        .stat-arrow {
            margin-left: auto;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(107, 114, 128, 0.1);
            transition: all 0.3s ease;
        }

        .stat-arrow .material-icons {
            font-size: 20px;
            color: #6b7280;
            transition: all 0.3s ease;
        }

        .stat-card:hover .stat-arrow {
            background: var(--card-color);
        }

        .stat-card:hover .stat-arrow .material-icons {
            color: white;
            transform: translateX(3px);
        }

        /* Dashboard Grid Layout */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Performance Chart Card */
        .performance-card {
            background: white;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(15, 23, 43, 0.08);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f1724;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title .material-icons {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            padding: 8px;
            border-radius: 10px;
            font-size: 20px;
        }

        /* Top Hotels List */
        .top-hotels-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .hotel-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 12px;
            transition: all 0.3s ease;
            border: 1px solid rgba(15, 23, 43, 0.06);
        }

        .hotel-item:hover {
            background: #eef2ff;
            transform: translateX(4px);
            border-color: #667eea;
        }

        .hotel-rank {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .hotel-item:nth-child(1) .hotel-rank {
            background: #f3f4f6;
            color: #1f2937;
            border: 2px solid #fbbf24;
        }

        .hotel-item:nth-child(2) .hotel-rank {
            background: #f3f4f6;
            color: #1f2937;
            border: 2px solid #9ca3af;
        }

        .hotel-item:nth-child(3) .hotel-rank {
            background: #f3f4f6;
            color: #1f2937;
            border: 2px solid #b97e00;
        }

        .hotel-item:nth-child(n+4) .hotel-rank {
            background: #f9fafb;
            color: #6b7280;
            border: 1px solid #e5e7eb;
        }

        .hotel-details {
            flex: 1;
        }

        .hotel-details h4 {
            margin: 0 0 4px 0;
            font-size: 0.95rem;
            font-weight: 600;
            color: #0f1724;
        }

        .hotel-details p {
            margin: 0;
            font-size: 0.8rem;
            color: #6b7280;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .hotel-stats {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
        }

        .hotel-bookings {
            font-size: 1.1rem;
            font-weight: 700;
            color: #667eea;
        }

        .hotel-revenue {
            font-size: 0.8rem;
            color: #6b7280;
        }

        /* Status Distribution */
        .status-distribution {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .status-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            flex-shrink: 0;
        }

        .status-color.completed {
            background: #0f9d58;
        }

        .status-color.confirmed {
            background: #1f6feb;
        }

        .status-color.pending {
            background: #ff9900;
        }

        .status-color.cancelled {
            background: #d93025;
        }

        .status-info {
            flex: 1;
        }

        .status-name {
            font-size: 0.85rem;
            color: #6b7280;
            font-weight: 500;
        }

        .status-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 6px;
        }

        .status-bar-fill {
            height: 100%;
            transition: width 0.6s ease;
        }

        .status-bar-fill.completed {
            background: #0f9d58;
        }

        .status-bar-fill.confirmed {
            background: #1f6feb;
        }

        .status-bar-fill.pending {
            background: #ff9900;
        }

        .status-bar-fill.cancelled {
            background: #d93025;
        }

        .status-count {
            font-size: 1rem;
            font-weight: 700;
            color: #0f1724;
        }

        /* Occupancy Meter */
        .occupancy-meter {
            margin-top: 20px;
            text-align: center;
        }

        .occupancy-circle {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: conic-gradient(
                #6b7280 0% var(--occupancy-percent),
                #e5e7eb var(--occupancy-percent) 100%
            );
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            position: relative;
        }

        .occupancy-circle::before {
            content: '';
            width: 110px;
            height: 110px;
            background: white;
            border-radius: 50%;
            position: absolute;
        }

        .occupancy-value {
            font-size: 2rem;
            font-weight: 700;
            color: #0f1724;
            position: relative;
            z-index: 1;
        }

        .occupancy-label {
            font-size: 0.9rem;
            color: #6b7280;
            font-weight: 500;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }

        .empty-state .material-icons {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: 12px;
        }

        .empty-state h4 {
            font-size: 1rem;
            color: #4b5563;
            margin: 0 0 8px 0;
        }

        .empty-state p {
            font-size: 0.85rem;
            margin: 0;
        }
    </style>
</head>

<body>
    <!-- Owner-specific sidebar -->
    <div class="owner-sidebar" id="owner-sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <img src="../../img/logo.png" alt="TripVerse Logo" class="logo-img" />
                <div class="logo-text-group">
                    <span class="logo-text">TripVerse</span>
                    <span class="logo-subtitle"><?= te('Dasbor Supplier') ?></span>
                </div>
            </div>
            <button id="toggleSidebar" class="sidebar-toggle" aria-label="Toggle sidebar">
                <span class="material-icons">menu</span>
            </button>
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
                <p class="profile-role">Hotel Owner</p>
                <p class="profile-email"><?php echo htmlspecialchars($u['email'] ?? ''); ?></p>
            </div>
        </div>

        <nav class="owner-nav">
            <a href="owner_dashboard.php" class="nav-item active">
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
            <a href="extra_facilities_manage.php" class="nav-item">
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
                <h1><?= te('Selamat datang kembali,') ?> <?php echo htmlspecialchars($u['first_name'] ?? 'Owner'); ?>!</h1>
                <p class="header-subtitle"><?= te('Kelola hotel Anda dan pantau bisnis Anda') ?></p>
            </div>
            <div class="header-right">
                <div class="header-actions">
                    <button class="action-btn" onclick="window.location.href='hotel_manage.php'">
                        <span class="material-icons">add</span>
                        <?= te('Tambah Hotel') ?>
                    </button>
                </div>
            </div>
        </header>

        <!-- Dashboard Stats -->
        <section class="dashboard-stats">
            <div class="stat-card" onclick="window.location.href='hotel_manage.php'">
                <div class="stat-icon">
                    <span class="material-icons">hotel</span>
                </div>
                <div class="stat-content">
                    <h3><?= te('Total Hotel') ?></h3>
                    <p><?php echo $stats['total_hotels'] ?? 0; ?></p>
                    <small><?= te('Properti Aktif') ?></small>
                </div>
                <div class="stat-arrow">
                    <span class="material-icons">arrow_forward</span>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='room_management.php'">
                <div class="stat-icon">
                    <span class="material-icons">bed</span>
                </div>
                <div class="stat-content">
                    <h3><?= te('Total Kamar') ?></h3>
                    <p><?php echo $stats['total_rooms'] ?? 0; ?></p>
                    <small><?= te('Unit Tersedia') ?></small>
                </div>
                <div class="stat-arrow">
                    <span class="material-icons">arrow_forward</span>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='booking_management.php'">
                <div class="stat-icon">
                    <span class="material-icons">book_online</span>
                </div>
                <div class="stat-content">
                    <h3><?= te('Total Booking') ?></h3>
                    <p><?php echo $stats['total_bookings'] ?? 0; ?></p>
                    <small><?= te('Semua Booking') ?></small>
                </div>
                <div class="stat-arrow">
                    <span class="material-icons">arrow_forward</span>
                </div>
            </div>

            <div class="stat-card" onclick="window.location.href='booking_management.php'">
                <div class="stat-icon">
                    <span class="material-icons">payments</span>
                </div>
                <div class="stat-content">
                    <h3><?= te('Pendapatan Bersih') ?></h3>
                    <p>Rp <?php echo number_format($stats['total_revenue'] ?? 0, 0, ',', '.'); ?></p>
                    <?php if (isset($stats['gross_revenue']) && $stats['gross_revenue'] > 0): ?>
                    <small><?= te('Setelah potongan biaya admin 5%') ?></small>
                    <?php else: ?>
                    <small><?= te('Hanya booking selesai') ?></small>
                    <?php endif; ?>
                </div>
                <div class="stat-arrow">
                    <span class="material-icons">arrow_forward</span>
                </div>
            </div>
        </section>

        <!-- Dashboard Grid: Performance & Status -->
        <div class="dashboard-grid">
            <!-- Top Performing Hotels -->
            <div class="performance-card" style="margin-left: 30px;">
                <div class="card-header">
                    <div class="card-title">
                        <span class="material-icons">emoji_events</span>
                        <?= te('Hotel Berperforma Terbaik') ?>
                    </div>
                </div>
                <?php if (!empty($top_hotels)): ?>
                <div class="top-hotels-list">
                    <?php foreach ($top_hotels as $index => $hotel): ?>
                    <div class="hotel-item" 
                         onclick="selectHotel('<?php echo $hotel['nama_hotel']; ?>')"
                         data-hotel-name="<?php echo htmlspecialchars($hotel['nama_hotel']); ?>"
                         style="cursor: pointer;">
                        <div class="hotel-rank"><?php echo $index + 1; ?></div>
                        <div class="hotel-details">
                            <h4><?php echo htmlspecialchars($hotel['nama_hotel']); ?></h4>
                            <p>
                                <span class="material-icons" style="font-size: 14px;">location_on</span>
                                <?php echo htmlspecialchars($hotel['kota']); ?>
                            </p>
                        </div>
                        <div class="hotel-stats">
                            <div class="hotel-bookings"><?php echo $hotel['total_bookings'] ?? 0; ?> bookings</div>
                            <div class="hotel-revenue">Rp <?php echo number_format(($hotel['total_revenue'] ?? 0) * 0.95, 0, ',', '.'); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <span class="material-icons">emoji_events</span>
                    <h4>No Performance Data</h4>
                    <p>Performance data will appear here once you have bookings</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Booking Status & Occupancy -->
            <div style="margin-right: 30px;"> 
                <!-- Booking Status Distribution -->
                <div class="performance-card" style="margin-bottom: 24px;" id="bookingStatusCard">
                    <div class="card-header">
                        <div class="card-title">
                            <span class="material-icons">pie_chart</span>
                            <span id="bookingStatusTitle">Booking Status - All Hotels</span>
                        </div>
                    </div>
                    <div id="statusDistributionContainer">
                    <?php 
                    $total_status = array_sum($status_breakdown);
                    if ($total_status > 0):
                    ?>
                    <div class="status-distribution">
                        <?php 
                        $status_config = [
                            'Completed' => ['color' => 'completed', 'icon' => 'check_circle'],
                            'Confirmed' => ['color' => 'confirmed', 'icon' => 'verified'],
                            'Pending' => ['color' => 'pending', 'icon' => 'pending'],
                            'Cancelled' => ['color' => 'cancelled', 'icon' => 'cancel']
                        ];
                        foreach ($status_config as $status => $config): 
                            $count = $status_breakdown[$status] ?? 0;
                            $percentage = ($count / $total_status) * 100;
                        ?>
                        <div class="status-item">
                            <div class="status-color <?php echo $config['color']; ?>"></div>
                            <div class="status-info">
                                <div class="status-name"><?php echo $status; ?></div>
                                <div class="status-bar">
                                    <div class="status-bar-fill <?php echo $config['color']; ?>" style="width: <?php echo $percentage; ?>%;"></div>
                                </div>
                            </div>
                            <div class="status-count"><?php echo $count; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <span class="material-icons">pie_chart</span>
                        <h4>No Booking Data</h4>
                        <p>Status distribution will show here</p>
                    </div>
                    <?php endif; ?>
                    </div>
                    
                    <!-- Hidden data for JavaScript -->
                    <script>
                    window.statusByHotel = <?php echo json_encode($status_breakdown_by_hotel); ?>;
                    window.overallStatus = <?php echo json_encode($status_breakdown); ?>;
                    </script>
                </div>

                <!-- Occupancy Rate per Hotel -->
                <div class="performance-card" id="occupancyCard">
                    <div class="card-header">
                        <div class="card-title">
                            <span class="material-icons">hotel</span>
                            <span id="occupancyTitle">Room Occupancy</span>
                        </div>
                    </div>
                    
                    <!-- Occupancy Details Container -->
                    <div id="occupancyDetailsContainer">
                        <div class="empty-state">
                            <span class="material-icons">hotel</span>
                            <h4><?= te('Pilih Hotel') ?></h4>
                            <p><?= te('Klik hotel dari Hotel Berperforma Terbaik untuk melihat data okupansi') ?></p>
                        </div>
                    </div>
                    
                    <!-- Hidden data for JavaScript -->
                    <script>
                    window.occupancyData = <?php echo json_encode($occupancy_hotels); ?>;
                    </script>
                </div>
            </div>
        </div>


        <!-- Recent Hotels -->
        <?php if (!empty($recent_hotels)): ?>
        <section class="recent-hotels">
            <h2 class="section-title"><?= te('Hotel Terbaru') ?></h2>
            <div class="hotels-grid">
                <?php foreach ($recent_hotels as $hotel): ?>
                <div class="hotel-card">
                    <div class="hotel-image">
                        <img src="../../img/<?php echo htmlspecialchars($hotel['foto_hotel'] ?? 'default_hotel.jpg'); ?>" alt="<?php echo htmlspecialchars($hotel['nama_hotel']); ?>">
                    </div>
                    <div class="hotel-info">
                        <h3><?php echo htmlspecialchars($hotel['nama_hotel']); ?></h3>
                        <p class="hotel-location">
                            <span class="material-icons">location_on</span>
                            <?php echo htmlspecialchars($hotel['kota']); ?>
                        </p>
                        <p class="hotel-price">Rp <?php echo number_format($hotel['harga_dasar'], 0, ',', '.'); ?>/night</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Recent Bookings -->
        <?php if (!empty($recent_bookings)): ?>
        <section class="recent-bookings">
            <h2 class="section-title"><?= te('Booking Terbaru') ?></h2>
            <div class="bookings-table">
                <div class="table-header">
                    <div class="table-cell">Customer</div>
                    <div class="table-cell">Hotel</div>
                    <div class="table-cell">Check-in</div>
                    <div class="table-cell">Check-out</div>
                    <div class="table-cell">Status</div>
                </div>
                <?php foreach ($recent_bookings as $booking): ?>
                <div class="table-row">
                    <div class="table-cell">
                        <span class="customer-name"><?php echo htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']); ?></span>
                    </div>
                    <div class="table-cell"><?php echo htmlspecialchars($booking['nama_hotel']); ?></div>
                    <div class="table-cell"><?php echo date('M d, Y', strtotime($booking['tanggal_checkin'])); ?></div>
                    <div class="table-cell"><?php echo date('M d, Y', strtotime($booking['tanggal_checkout'])); ?></div>
                    <div class="table-cell">
                        <span class="status-badge status-<?php echo strtolower($booking['status']); ?>">
                            <?php echo ucfirst($booking['status']); ?>
                        </span>
            </div>
            </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </main>
    <script>
        const sidebar = document.getElementById('owner-sidebar');
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

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stat cards on load
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Animate action cards on hover
            const actionCards = document.querySelectorAll('.action-card');
            actionCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                });
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // No need for dropdown event listener anymore
        });

        // Function to select hotel from top performing list
        function selectHotel(hotelName) {
            // Update booking status for selected hotel
            updateBookingStatus(hotelName);
            
            // Update occupancy for selected hotel
            updateOccupancyDisplay(hotelName);
            
            // Update occupancy title
            document.getElementById('occupancyTitle').textContent = `Room Occupancy - ${hotelName}`;
            
            // Scroll to occupancy section
            document.getElementById('occupancyCard').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            
            // Highlight selected hotel item
            document.querySelectorAll('.hotel-item').forEach(item => {
                item.style.background = '#f8f9fa';
                item.style.borderColor = 'rgba(15, 23, 43, 0.06)';
            });
            
            const selectedItem = document.querySelector(`[data-hotel-name="${hotelName}"]`);
            if (selectedItem) {
                selectedItem.style.background = '#eef2ff';
                selectedItem.style.borderColor = '#667eea';
            }
        }

        // Function to update occupancy display
        function updateOccupancyDisplay(hotelName) {
            const occupancyContainer = document.getElementById('occupancyDetailsContainer');
            
            if (!window.occupancyData || window.occupancyData.length === 0) {
                occupancyContainer.innerHTML = `
                    <div class="empty-state">
                        <span class="material-icons">hotel</span>
                        <h4>No Occupancy Data</h4>
                        <p>No occupancy data available. Please ensure hotels have rooms with stock.</p>
                    </div>
                `;
                return;
            }
            
            // Find hotel occupancy data by name (exact match or contains)
            let hotelOccupancy = window.occupancyData.find(h => h.nama_hotel === hotelName);
            
            // If exact match not found, try partial match
            if (!hotelOccupancy) {
                hotelOccupancy = window.occupancyData.find(h => 
                    h.nama_hotel.includes(hotelName) || hotelName.includes(h.nama_hotel)
                );
            }
            
            if (!hotelOccupancy) {
                occupancyContainer.innerHTML = `
                    <div class="empty-state">
                        <span class="material-icons">hotel</span>
                        <h4>No Occupancy Data</h4>
                        <p>No occupancy data found for ${hotelName}</p>
                    </div>
                `;
                return;
            }
            
            // Render occupancy data with chart
            const occupiedPercent = hotelOccupancy.occupancy_rate;
            const availablePercent = 100 - occupiedPercent;
            const rangeText = `Avg per day (next ${hotelOccupancy.time_window_days || 7} days)`;
            
            occupancyContainer.innerHTML = `
                <div style="padding: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 16px; color: white; margin-bottom: 16px; box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h4 style="font-size: 1.1rem; font-weight: 600; margin: 0 0 4px 0; opacity: 0.9;">Occupancy Rate</h4>
                            <h3 style="font-size: 2.5rem; font-weight: 700; margin: 0;">${occupiedPercent.toFixed(1)}%</h3>
                            <p style="font-size: 0.85rem; margin: 8px 0 0 0; opacity: 0.9;">
                                📅 ${rangeText}
                            </p>
                        </div>
                        <div style="width: 100px; height: 100px; border-radius: 50%; background: rgba(255,255,255,0.2); backdrop-filter: blur(10px); display: flex; align-items: center; justify-content: center; border: 4px solid rgba(255,255,255,0.3);">
                            <span style="font-size: 2rem;">📊</span>
                        </div>
                    </div>
                </div>

                <!-- Pie Chart Style -->
                <div style="display: flex; gap: 20px; margin-bottom: 20px; align-items: center;">
                    <div style="position: relative; width: 120px; height: 120px; flex-shrink: 0;">
                        <svg viewBox="0 0 36 36" style="width: 100%; height: 100%; transform: rotate(-90deg);">
                            <!-- Background circle -->
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#e5e7eb" stroke-width="3.8"/>
                            <!-- Occupied circle -->
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#0f9d58" stroke-width="3.8"
                                stroke-dasharray="${occupiedPercent} ${100 - occupiedPercent}"
                                stroke-linecap="round"/>
                        </svg>
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                            <div style="font-size: 1.5rem; font-weight: 700; color: #0f1724;">${occupiedPercent.toFixed(0)}%</div>
                        </div>
                    </div>
                    
                    <div style="flex: 1;">
                        <div style="margin-bottom: 12px;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                <div style="width: 12px; height: 12px; background: #0f9d58; border-radius: 3px;"></div>
                                <span style="font-size: 0.85rem; color: #6b7280; font-weight: 500;">Occupied</span>
                                <span style="margin-left: auto; font-weight: 700; color: #0f9d58;">${hotelOccupancy.occupied_rooms}</span>
                            </div>
                            <div style="height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                                <div style="height: 100%; background: #0f9d58; width: ${occupiedPercent}%; transition: width 0.6s ease;"></div>
                            </div>
                        </div>
                        
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                                <div style="width: 12px; height: 12px; background: #4facfe; border-radius: 3px;"></div>
                                <span style="font-size: 0.85rem; color: #6b7280; font-weight: 500;">Available</span>
                                <span style="margin-left: auto; font-weight: 700; color: #4facfe;">${hotelOccupancy.available_rooms}</span>
                            </div>
                            <div style="height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                                <div style="height: 100%; background: #4facfe; width: ${availablePercent}%; transition: width 0.6s ease;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                    <div style="background: linear-gradient(135deg, #0f9d58 0%, #0a7a43 100%); padding: 16px; border-radius: 12px; color: white; box-shadow: 0 4px 12px rgba(15, 157, 88, 0.3);">
                        <div style="font-size: 0.75rem; opacity: 0.9; margin-bottom: 6px;">🟢 Occupied</div>
                        <div style="font-size: 1.8rem; font-weight: 700;">${hotelOccupancy.occupied_rooms}</div>
                        <div style="font-size: 0.7rem; opacity: 0.8; margin-top: 2px;">rooms booked</div>
                    </div>
                    
                    <div style="background: linear-gradient(135deg, #4facfe 0%, #0088cc 100%); padding: 16px; border-radius: 12px; color: white; box-shadow: 0 4px 12px rgba(79, 172, 254, 0.3);">
                        <div style="font-size: 0.75rem; opacity: 0.9; margin-bottom: 6px;">🔵 Available</div>
                        <div style="font-size: 1.8rem; font-weight: 700;">${hotelOccupancy.available_rooms}</div>
                        <div style="font-size: 0.7rem; opacity: 0.8; margin-top: 2px;">rooms free</div>
                    </div>
                    
                    <div style="background: linear-gradient(135deg, #667eea 0%, #4d3f9e 100%); padding: 16px; border-radius: 12px; color: white; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);">
                        <div style="font-size: 0.75rem; opacity: 0.9; margin-bottom: 6px;">📦 Total Stock</div>
                        <div style="font-size: 1.8rem; font-weight: 700;">${hotelOccupancy.total_stock}</div>
                        <div style="font-size: 0.7rem; opacity: 0.8; margin-top: 2px;">total capacity</div>
                    </div>
                </div>
            `;
        }

        // Function to update booking status display
        function updateBookingStatus(hotelName) {
            const statusContainer = document.getElementById('statusDistributionContainer');
            const statusTitle = document.getElementById('bookingStatusTitle');
            
            if (hotelName === null) {
                // Show overall status
                statusTitle.textContent = 'Booking Status - All Hotels';
                renderStatusDistribution(window.overallStatus);
            } else {
                // Find hotel data by name
                let hotelData = null;
                for (const hotelId in window.statusByHotel) {
                    if (window.statusByHotel[hotelId].nama_hotel === hotelName) {
                        hotelData = window.statusByHotel[hotelId];
                        break;
                    }
                }
                
                if (hotelData) {
                    statusTitle.textContent = `Booking Status - ${hotelName}`;
                    renderStatusDistribution(hotelData.statuses);
                } else {
                    statusTitle.textContent = `Booking Status - ${hotelName}`;
                    statusContainer.innerHTML = `
                        <div class="empty-state">
                            <span class="material-icons">pie_chart</span>
                            <h4>No Booking Data</h4>
                            <p>No bookings found for this hotel</p>
                        </div>
                    `;
                }
            }
        }

        // Function to render status distribution
        function renderStatusDistribution(statusData) {
            const statusContainer = document.getElementById('statusDistributionContainer');
            const statusConfig = {
                'Completed': { color: 'completed', icon: 'check_circle' },
                'Confirmed': { color: 'confirmed', icon: 'verified' },
                'Pending': { color: 'pending', icon: 'pending' },
                'Cancelled': { color: 'cancelled', icon: 'cancel' }
            };
            
            let totalStatus = 0;
            for (const status in statusData) {
                totalStatus += statusData[status];
            }
            
            if (totalStatus === 0) {
                statusContainer.innerHTML = `
                    <div class="empty-state">
                        <span class="material-icons">pie_chart</span>
                        <h4>No Booking Data</h4>
                        <p>Status distribution will show here</p>
                    </div>
                `;
                return;
            }
            
            let html = '<div class="status-distribution">';
            for (const status in statusConfig) {
                const count = statusData[status] || 0;
                const percentage = (count / totalStatus) * 100;
                const config = statusConfig[status];
                
                html += `
                    <div class="status-item">
                        <div class="status-color ${config.color}"></div>
                        <div class="status-info">
                            <div class="status-name">${status}</div>
                            <div class="status-bar">
                                <div class="status-bar-fill ${config.color}" style="width: ${percentage}%;"></div>
                            </div>
                        </div>
                        <div class="status-count">${count}</div>
                    </div>
                `;
            }
            html += '</div>';
            
            statusContainer.innerHTML = html;
        }
    </script>
</body>

</html>