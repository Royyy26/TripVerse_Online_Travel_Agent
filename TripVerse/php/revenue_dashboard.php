<?php
session_start();

// Check if user is logged in and is an admin (role is 'admin')
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Akses ditolak! Halaman ini hanya untuk admin.'); window.location='home.php';</script>";
    exit;
}

require 'connect.php';
require_once __DIR__ . '/_lang.php';

$id_user = $_SESSION['id_user'];

// =================================================================
// 1. DATA PENGGUNA (ADMIN)
// =================================================================
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
$stmt->close();

if ($data = $result->fetch_assoc()) {
    $firstName  = $data['first_name'];
    $lastName   = $data['last_name'];
    $email      = $data['email'];
    $foto       = $data['profile_picture'] ?: '../images/default.jpg';
} else {
    $firstName = $lastName = "Admin";
    $email = "admin@tripverse.com";
    $foto = "../images/default.jpg";
}

// =================================================================
// 2. FILTER LOGIC
// =================================================================
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : 0;
$hotel_id = isset($_GET['hotel_id']) ? $_GET['hotel_id'] : 'all';
$time_period = isset($_GET['time_period']) ? $_GET['time_period'] : 'monthly';

$where_conditions = ["bh.status = 'Completed'"];
$params = [];
$param_types = "";

if ($year > 0) {
    $where_conditions[] = "YEAR(bh.tanggal_booking) = ?";
    $params[] = $year;
    $param_types .= "i";
}

if ($month > 0) {
    $where_conditions[] = "MONTH(bh.tanggal_booking) = ?";
    $params[] = $month;
    $param_types .= "i";
}

if ($hotel_id !== 'all') {
    $where_conditions[] = "bh.hotel_id = ?";
    $params[] = $hotel_id;
    $param_types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// =================================================================
// 3. FUNGSI UTILITY DATABASE
// =================================================================
function executePreparedQuery($conn, $query, $param_types = "", $params = []) {
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        error_log("Error preparing query: " . $conn->error);
        return false;
    }
    
    if (!empty($params) && !empty($param_types)) {
        $bind_params = array();
        $bind_params[] = & $param_types;
        for ($i = 0; $i < count($params); $i++) {
            $bind_params[] = & $params[$i];
        }
        if (!call_user_func_array(array($stmt, 'bind_param'), $bind_params)) {
             error_log("Error binding parameters: " . $stmt->error);
             $stmt->close();
             return false;
        }
    }
    
    if (!$stmt->execute()) {
        error_log("Error executing query: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

// =================================================================
// 4. PENGAMBILAN DATA UNTUK KPI & CHART
// =================================================================

// 4.1. Overall Revenue Statistics (KPI)
$revenue_stats_query = "SELECT 
    COUNT(*) as total_bookings,
    SUM(bh.total_harga) as total_revenue,
    AVG(bh.total_harga) as avg_revenue_per_booking,
    SUM(DATEDIFF(bh.check_out, bh.check_in)) as total_nights,
    (SUM(bh.total_harga) / NULLIF(SUM(DATEDIFF(bh.check_out, bh.check_in)), 0)) as revenue_per_night,
    MAX(bh.total_harga) as max_booking_value,
    MIN(bh.total_harga) as min_booking_value
    FROM booking_hotel bh
    WHERE " . $where_clause;

$revenue_stats_result = executePreparedQuery($conn, $revenue_stats_query, $param_types, $params);
$revenue_stats = $revenue_stats_result ? $revenue_stats_result->fetch_assoc() : [];

// 4.2. Revenue Trends (Chart)
if ($time_period === 'weekly') {
    $revenue_trends_query = "SELECT 
        YEARWEEK(bh.tanggal_booking) as period,
        CONCAT('Week ', WEEK(bh.tanggal_booking), ' - ', YEAR(bh.tanggal_booking)) as period_name,
        COUNT(*) as bookings,
        SUM(bh.total_harga) as revenue,
        AVG(bh.total_harga) as avg_booking_value
        FROM booking_hotel bh
        WHERE " . $where_clause . "
        GROUP BY YEARWEEK(bh.tanggal_booking), WEEK(bh.tanggal_booking), YEAR(bh.tanggal_booking)
        ORDER BY period DESC
        LIMIT 12";
} else {
    $revenue_trends_query = "SELECT 
        YEAR(bh.tanggal_booking) as period_year,
        MONTH(bh.tanggal_booking) as period_num,
        CONCAT(MONTHNAME(bh.tanggal_booking), ' ', YEAR(bh.tanggal_booking)) as period_name,
        COUNT(*) as bookings,
        SUM(bh.total_harga) as revenue,
        AVG(bh.total_harga) as avg_booking_value
        FROM booking_hotel bh
        WHERE " . $where_clause . "
        GROUP BY YEAR(bh.tanggal_booking), MONTH(bh.tanggal_booking), MONTHNAME(bh.tanggal_booking)
        ORDER BY period_year, period_num";
}

$revenue_trends_result = executePreparedQuery($conn, $revenue_trends_query, $param_types, $params);

$revenue_trends_data = ['periods' => [], 'bookings' => [], 'revenue' => [], 'avg_booking_value' => []];
if ($revenue_trends_result) {
    while ($row = $revenue_trends_result->fetch_assoc()) {
        $revenue_trends_data['periods'][] = $row['period_name'];
        $revenue_trends_data['bookings'][] = $row['bookings'];
        $revenue_trends_data['revenue'][] = $row['revenue'] / 1000000; // Convert to millions
        $revenue_trends_data['avg_booking_value'][] = $row['avg_booking_value'];
    }
    if ($time_period === 'weekly') {
        $revenue_trends_data['periods'] = array_reverse($revenue_trends_data['periods']);
        $revenue_trends_data['bookings'] = array_reverse($revenue_trends_data['bookings']);
        $revenue_trends_data['revenue'] = array_reverse($revenue_trends_data['revenue']);
        $revenue_trends_data['avg_booking_value'] = array_reverse($revenue_trends_data['avg_booking_value']);
    }
}

// 4.3. Revenue by Hotel (Chart & Table)
$revenue_by_hotel_query = "SELECT 
    h.hotel_id, h.nama_hotel, h.kota,
    COUNT(bh.booking_id) as bookings,
    SUM(bh.total_harga) as revenue,
    AVG(bh.total_harga) as avg_booking_value
    FROM hotel h
    LEFT JOIN booking_hotel bh ON h.hotel_id = bh.hotel_id
    WHERE " . $where_clause . "
    GROUP BY h.hotel_id, h.nama_hotel, h.kota
    HAVING SUM(bh.total_harga) > 0
    ORDER BY revenue DESC";

$revenue_by_hotel_result = executePreparedQuery($conn, $revenue_by_hotel_query, $param_types, $params);
$hotelLabels = [];
$hotelData = [];
$revenue_by_hotel_data_table = [];
if ($revenue_by_hotel_result) {
    while ($hotel = $revenue_by_hotel_result->fetch_assoc()) {
        $hotelLabels[] = $hotel['nama_hotel'];
        $hotelData[] = $hotel['revenue'];
        $revenue_by_hotel_data_table[] = $hotel;
    }
}

// 4.4. Total Revenue (for Share Percentage)
$total_revenue = $revenue_stats['total_revenue'] ?? 0;

// 4.5. Revenue by Room Type (Chart & Table)
$revenue_by_room_type_query = "SELECT 
    t.tipe_id, t.nama_tipe,
    COUNT(bh.booking_id) as bookings,
    SUM(bh.total_harga) as revenue,
    AVG(bh.total_harga) as avg_booking_value
    FROM tipe_kamar t
    LEFT JOIN booking_hotel bh ON t.tipe_id = bh.tipe_id 
    WHERE " . $where_clause . " 
    GROUP BY t.tipe_id, t.nama_tipe
    HAVING SUM(bh.total_harga) > 0
    ORDER BY revenue DESC";

$revenue_by_room_type_result = executePreparedQuery($conn, $revenue_by_room_type_query, $param_types, $params);
$roomTypeLabels = [];
$roomTypeData = [];
$revenue_by_room_type_data_table = [];
if ($revenue_by_room_type_result) {
    while ($room_type = $revenue_by_room_type_result->fetch_assoc()) {
        $roomTypeLabels[] = $room_type['nama_tipe'];
        $roomTypeData[] = $room_type['revenue'];
        $revenue_by_room_type_data_table[] = $room_type;
    }
}

// 4.6. Revenue by Customer Type (Chart & Table)
$customer_type_query = "SELECT 
    CASE 
        WHEN customer_count > 1 THEN 'Repeat Customer'
        ELSE 'New Customer'
    END as customer_type,
    COUNT(*) as bookings,
    SUM(total_harga) as revenue,
    AVG(total_harga) as avg_booking_value
    FROM (
        SELECT 
            c.customer_id,
            COUNT(bh.booking_id) as customer_count,
            bh.total_harga
        FROM customer c
        JOIN booking_hotel bh ON c.customer_id = bh.customer_id
        WHERE " . $where_clause . "
        GROUP BY c.customer_id, bh.booking_id, bh.total_harga
    ) as customer_stats
    GROUP BY customer_type
    ORDER BY revenue DESC";

$customer_type_result = executePreparedQuery($conn, $customer_type_query, $param_types, $params);
$customerTypes = [];
$customerBookings = [];
$customerRevenue = [];
$customer_type_data_table = [];
if ($customer_type_result) {
    while ($customer_type = $customer_type_result->fetch_assoc()) {
        $customerTypes[] = $customer_type['customer_type'];
        $customerBookings[] = $customer_type['bookings'];
        $customerRevenue[] = $customer_type['revenue'];
        $customer_type_data_table[] = $customer_type;
    }
}

// 4.7. Daily Revenue Performance (Chart)
$daily_revenue_query = "SELECT 
    DATE(bh.tanggal_booking) as date,
    COUNT(*) as bookings,
    SUM(bh.total_harga) as revenue,
    AVG(bh.total_harga) as avg_booking_value
    FROM booking_hotel bh
    WHERE bh.tanggal_booking >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    AND " . $where_clause . "
    GROUP BY DATE(bh.tanggal_booking)
    ORDER BY date DESC
    LIMIT 30";

$daily_revenue_result = executePreparedQuery($conn, $daily_revenue_query, $param_types, $params);
$daily_revenue_data = ['dates' => [], 'bookings' => [], 'revenue' => [], 'avg_booking_value' => []];

if ($daily_revenue_result) {
    while ($row = $daily_revenue_result->fetch_assoc()) {
        $daily_revenue_data['dates'][] = date('M j', strtotime($row['date']));
        $daily_revenue_data['bookings'][] = $row['bookings'];
        $daily_revenue_data['revenue'][] = $row['revenue'];
        $daily_revenue_data['avg_booking_value'][] = $row['avg_booking_value'];
    }

    $daily_revenue_data['dates'] = array_reverse($daily_revenue_data['dates']);
    $daily_revenue_data['bookings'] = array_reverse($daily_revenue_data['bookings']);
    $daily_revenue_data['revenue'] = array_reverse($daily_revenue_data['revenue']);
    $daily_revenue_data['avg_booking_value'] = array_reverse($daily_revenue_data['avg_booking_value']);
}

// 4.8. Revenue Growth Comparison (KPIs)
$current_revenue = $revenue_stats['total_revenue'] ?? 0;
$current_bookings = $revenue_stats['total_bookings'] ?? 0;

$prev_year = $year > 0 ? $year - 1 : date('Y') - 1;
$prev_where_conditions = ["status = 'Completed'"];
$prev_params = [];
$prev_param_types = "";

if ($year > 0) {
    $prev_where_conditions[] = "YEAR(tanggal_booking) = ?";
    $prev_params[] = $prev_year;
    $prev_param_types .= "i";
}
if ($month > 0) {
    $prev_where_conditions[] = "MONTH(tanggal_booking) = ?";
    $prev_params[] = $month;
    $prev_param_types .= "i";
}
if ($hotel_id !== 'all') {
    $prev_where_conditions[] = "hotel_id = ?";
    $prev_params[] = $hotel_id;
    $prev_param_types .= "s";
}

$prev_where_clause = implode(" AND ", $prev_where_conditions);
$previous_period_query = "SELECT COUNT(*) as bookings, SUM(total_harga) as revenue FROM booking_hotel WHERE " . $prev_where_clause;
$previous_result = executePreparedQuery($conn, $previous_period_query, $prev_param_types, $prev_params);
$previous_data = $previous_result ? $previous_result->fetch_assoc() : ['bookings' => 0, 'revenue' => 0];

$previous_revenue = $previous_data['revenue'] ?? 0;
$previous_bookings = $previous_data['bookings'] ?? 0;

$revenue_growth = $previous_revenue > 0 ? (($current_revenue - $previous_revenue) / $previous_revenue) * 100 : ($current_revenue > 0 ? 100 : 0);
$bookings_growth = $previous_bookings > 0 ? (($current_bookings - $previous_bookings) / $previous_bookings) * 100 : ($current_bookings > 0 ? 100 : 0);

// 4.9. Filter Dropdown Data
$years_query = "SELECT DISTINCT YEAR(tanggal_booking) as year FROM booking_hotel WHERE status = 'Completed' ORDER BY year DESC";
$years_result = $conn->query($years_query);

$hotels_query = "SELECT hotel_id, nama_hotel, kota FROM hotel ORDER BY nama_hotel";
$hotels_result = $conn->query($hotels_query);

// 4.10. Notification Count
$query = "SELECT COUNT(*) as notifications FROM booking_hotel WHERE status = 'Pending'";
$result = $conn->query($query);
$notificationCount = $result->fetch_assoc()['notifications'] ?? 0;

// =================================================================
// 5. TUTUP KONEKSI
// =================================================================
$conn->close();

// =================================================================
// 6. HANDLE UPLOAD FOTO PROFIL
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    require 'connect.php'; 
    
    $uploadDir = '../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileName = uniqid() . '_' . basename($_FILES['profile_photo']['name']);
    $targetPath = $uploadDir . $fileName;

    $imageFileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];

    if (in_array($imageFileType, $allowedTypes)) {
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
            $update = $conn->prepare("UPDATE user SET profile_picture = ? WHERE id_user = ?");
            $update->bind_param("ss", $fileName, $id_user); 
            $update->execute();
            $update->close();
            
            $_SESSION['upload_notification'] = "Profile photo updated successfully!";
        } else {
            $_SESSION['upload_notification'] = "Failed to upload photo.";
        }
    } else {
        $_SESSION['upload_notification'] = "Invalid file type. Only JPG, PNG, GIF allowed.";
    }

    $conn->close();
    header("Location: revenue_dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Revenue Dashboard - TripVerse Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../css/dashboard.css?v=2.0.0" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #FF7A3D;
            --secondary-color: #0F172B;
            --success-color: #1baf7a;
            --info-color: #2a78d6;
            --warning-color: #eda100;
            --danger-color: #e34948;
            --light-color: #f5f6f8;
            --dark-color: #0F172B;
            --text-color: #1e2635;
            --text-light: #6b7280;
            --border-radius: 8px;
            --box-shadow: 0 4px 6px rgba(15, 23, 43, 0.1);
            --transition: all 0.3s ease;
        }

        /* Revenue Dashboard Specific Styles */
        .revenue-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
            margin-bottom: 30px;
            border-left: 4px solid var(--primary-color);
        }

        .revenue-section h2 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 18px;
            color: var(--dark-color);
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .revenue-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .revenue-card {
            background: #f8f9fa;
            border-radius: var(--border-radius);
            padding: 20px;
            border: 1px solid #e9ecef;
        }

        .revenue-card h3 {
            margin: 0 0 15px;
            font-size: 16px;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-container {
            width: 100%;
            height: 300px;
            margin: 20px 0;
        }

        /* Filter Controls */
        .filter-controls {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-light);
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            background: white;
            font-size: 14px;
            min-width: 150px;
        }

        .filter-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: var(--border-radius);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            transition: var(--transition);
            margin-top: 20px;
        }

        .filter-btn:hover {
            background: #E8672B;
        }

        .export-btn {
            background: var(--success-color);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: var(--border-radius);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            transition: var(--transition);
            margin-top: 20px;
        }

        .export-btn:hover {
            background: #17996b;
        }

        /* Revenue Table */
        .revenue-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .revenue-table th,
        .revenue-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .revenue-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--text-light);
        }

        .revenue-table tr:hover {
            background: #f8f9fa;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .kpi-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
            border-top: 4px solid;
            transition: var(--transition);
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .kpi-card.primary {
            border-top-color: var(--primary-color);
        }

        .kpi-card.success {
            border-top-color: var(--success-color);
        }

        .kpi-card.warning {
            border-top-color: var(--warning-color);
        }

        .kpi-card.danger {
            border-top-color: var(--danger-color);
        }

        .kpi-card.info {
            border-top-color: var(--info-color);
        }

        .kpi-value {
            font-size: 28px;
            font-weight: 700;
            margin: 10px 0;
            color: var(--dark-color);
        }

        .kpi-label {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .kpi-trend {
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .trend-up {
            color: var(--success-color);
        }

        .trend-down {
            color: var(--danger-color);
        }

        /* Metric Highlights */
        .metric-highlights {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .metric-item {
            background: white;
            padding: 15px;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            text-align: center;
        }

        .metric-label {
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .metric-value {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark-color);
        }

        /* Growth Indicators */
        .growth-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .growth-positive {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success-color);
        }

        .growth-negative {
            background: rgba(227, 73, 72, 0.1);
            color: var(--danger-color);
        }

        .growth-neutral {
            background: rgba(158, 158, 158, 0.1);
            color: #9e9e9e;
        }
        
        /* Profile Section Styles */
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
            width: 80px;
            height: 80px;
            margin-bottom: 15px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid rgba(255, 255, 255, 0.2);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        @media (min-width: 769px) {
            .profile-photo-container {
                width: 150px;
                height: 150px;
            }
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
        
        /* Fix sidebar elements */
        .sidebar * {
            box-sizing: border-box;
        }

        .dropdown-content,
        .dropdown-item,
        .dropdown-item span {
            transform: none !important;
            rotate: none !important;
        }

        @media (max-width: 768px) {
            .revenue-grid {
                grid-template-columns: 1fr;
            }

            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .metric-highlights {
                grid-template-columns: repeat(2, 1fr);
            }

            .profile-photo-container {
                width: 60px;
                height: 60px;
            }

            .profile-info h2 {
                font-size: 14px;
            }

            .profile-info p {
                font-size: 12px;
            }

            .user-info {
                padding: 6px 10px;
                font-size: 12px;
            }

            .dropdown-content {
                left: -10px;
                right: -10px;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="../img/logo.png" alt="TripVerse Logo" class="sidebar-brand-logo" />
            <div class="sidebar-brand-text">
                <span class="sidebar-brand-title">TripVerse</span>
                <span class="sidebar-brand-subtitle"><?= te('Dasbor Admin') ?></span>
            </div>
        </div>

        <div class="sidebar-brand-lang">
            <?php include __DIR__ . '/_lang_switch_inner.php'; ?>
        </div>

        <div class="profile-header">
            <div class="profile-photo-section">
                <div class="profile-photo-container">
                    <img src="../uploads/<?php echo htmlspecialchars($foto); ?>" alt="Profile Photo" class="profile-photo" id="profilePhoto" onerror="this.src='../images/default.jpg'">
                    <div class="profile-overlay">
                        <span class="material-icons">edit</span>
                    </div>
                    <form id="uploadForm" action="revenue_dashboard.php" method="POST" enctype="multipart/form-data" style="display:none;">
                        <input type="file" name="profile_photo" id="profileUpload" accept="image/*" />
                    </form>
                </div>

                <div class="profile-info">
                    <h2><?= htmlspecialchars($firstName . ' ' . $lastName); ?></h2>
                    <p><?php echo htmlspecialchars($email); ?></p>

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
                            <a href="logout.php" class="dropdown-item">
                                <span class="material-icons">logout</span>
                                <span><?= te('Keluar') ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <nav>
            <a href="dashboard.php"><span class="material-icons">dashboard</span><span>Dashboard DSS</span></a>
            
            <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                <a href="#" class="booking-toggle" data-target="performanceDropdown">
                    <span class="material-icons">analytics</span>
                    <span>Performance Analysis</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>
                <div class="booking-submenu hidden" id="performanceDropdown" role="menu" aria-hidden="true">
                    <a href="performance_snapshot.php"><span class="material-icons">speed</span><span>Performance Snapshot</span></a>
                    <a href="booking_trends.php"><span class="material-icons">trending_up</span><span><?= te('Tren Booking') ?></span></a>
                    <a href="alos_analysis.php"><span class="material-icons">hotel</span><span>ALOS Analysis</span></a>
                </div>
            </div>

            <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                <a href="#" class="booking-toggle" data-target="financialDropdown">
                    <span class="material-icons">account_balance</span>
                    <span>Financial Analysis</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>
                <div class="booking-submenu hidden" id="financialDropdown" role="menu" aria-hidden="true">
                    <a href="revenue_dashboard.php" class="active"><span class="material-icons">bar_chart</span><span>Revenue Dashboard</span></a>
                    <a href="hotel_contribution.php"><span class="material-icons">pie_chart</span><span>Hotel Contribution</span></a>
                </div>
            </div>

            <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                <a href="#" class="booking-toggle" data-target="cancellationDropdown">
                    <span class="material-icons">cancel</span>
                    <span>Cancellation Analysis</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>
                <div class="booking-submenu hidden" id="cancellationDropdown" role="menu" aria-hidden="true">
                    <a href="cancellation_trends.php"><span class="material-icons">show_chart</span><span>Cancellation Trends</span></a>
                    <a href="revenue_loss.php"><span class="material-icons">money_off</span><span>Revenue Loss Analysis</span></a>
                </div>
            </div>

            <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                <a href="#" class="booking-toggle" data-target="customerDropdown">
                    <span class="material-icons">people</span>
                    <span>Customer Analysis</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>
                <div class="booking-submenu hidden" id="customerDropdown" role="menu" aria-hidden="true">
                    <a href="customer_segmentation.php"><span class="material-icons">group</span><span>Customer Segmentation</span></a>
                    <a href="repeat_customers.php"><span class="material-icons">loyalty</span><span>Repeat Customers</span></a>
                </div>
            </div>

            <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                <a href="#" class="booking-toggle" data-target="packageDropdown">
                    <span class="material-icons">home</span>
                    <span>Package Hotel</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>
                <div class="booking-submenu hidden" id="packageDropdown" role="menu" aria-hidden="true">
                    <a href="package_hotels.php"><span class="material-icons">add</span><span>Add Package Hotel</span></a>
                    <a href="reporting_hotels.php"><span class="material-icons">list</span><span>Data Hotel</span></a>
                </div>
            </div>

            <a href="booking_management.php"><span class="material-icons">event_note</span><span>Booking Management</span></a>
            <a href="hotel_management.php"><span class="material-icons">business</span><span>Hotel Management</span></a>
            <a href="customer_management.php"><span class="material-icons">people</span><span>Customer Management</span></a>
            <a href="room_management.php"><span class="material-icons">meeting_room</span><span>Room Management</span></a>
            <a href="promo_management.php"><span class="material-icons">local_offer</span><span><?= te('Manajemen Promo') ?></span></a>
            <a href="report.php"><span class="material-icons">summarize</span><span>Reports</span></a>
            
            <a href="logout.php"><span class="material-icons">logout</span><span><?= te('Keluar') ?></span></a>
        </nav>
    </div>

    <main class="main-content" id="main-content">
        <header class="main-header">
            <button class="menu-toggle" id="toggleSidebar">
                <span class="material-icons">menu</span>
            </button>
            <h1>Revenue Dashboard</h1>

            <div class="header-actions">
                <div class="notification-bell" id="notificationBell" tabindex="0" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                    <span class="material-icons bell-icon">notifications</span>
                    <span class="notification-badge" id="notificationCount"><?php echo $notificationCount; ?></span>
                </div>

                <div class="user-menu">
                    <img src="../uploads/<?php echo htmlspecialchars($foto); ?>" alt="Profile" class="user-avatar" onerror="this.src='../images/default.jpg'">
                </div>
            </div>
        </header>

        <div class="content">
            <?php if (isset($_SESSION['upload_notification'])): ?>
                <div class="notification-message <?php echo strpos($_SESSION['upload_notification'], 'Failed') === false ? 'success' : 'error'; ?>">
                    <?php
                    echo htmlspecialchars($_SESSION['upload_notification']);
                    unset($_SESSION['upload_notification']);
                    ?>
                </div>
            <?php endif; ?>
            
            <div class="filter-controls">
                <div class="filter-group">
                    <label for="year">Year</label>
                    <select id="year" class="filter-select">
                        <option value="0">All Years</option>
                        <?php 
                        if ($years_result) {
                            $years_result->data_seek(0);
                            while ($year_row = $years_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $year_row['year']; ?>" <?php echo $year_row['year'] == $year ? 'selected' : ''; ?>>
                                <?php echo $year_row['year']; ?>
                            </option>
                        <?php 
                            endwhile; 
                        }
                        ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="month">Month</label>
                    <select id="month" class="filter-select">
                        <option value="0">All Months</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $i == $month ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="hotel">Hotel</label>
                    <select id="hotel" class="filter-select">
                        <option value="all">All Hotels</option>
                        <?php 
                        if ($hotels_result) {
                            $hotels_result->data_seek(0);
                            while ($hotel_row = $hotels_result->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $hotel_row['hotel_id']; ?>" <?php echo $hotel_row['hotel_id'] == $hotel_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($hotel_row['nama_hotel'] . ' - ' . $hotel_row['kota']); ?>
                            </option>
                        <?php 
                            endwhile; 
                        }
                        ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="time_period">Time Period</label>
                    <select id="time_period" class="filter-select">
                        <option value="monthly" <?php echo $time_period == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                        <option value="weekly" <?php echo $time_period == 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                    </select>
                </div>

                <button class="filter-btn" onclick="applyFilters()">
                    <span class="material-icons">filter_alt</span>
                    Apply Filters
                </button>

                <button class="export-btn" onclick="exportToExcel()">
                    <span class="material-icons">download</span>
                    Export Report
                </button>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card primary">
                    <div class="kpi-label">Total Revenue</div>
                    <div class="kpi-value">Rp <?php echo number_format($current_revenue ?? 0, 0, ',', '.'); ?></div>
                    <div class="kpi-trend <?php echo $revenue_growth >= 0 ? 'trend-up' : 'trend-down'; ?>">
                        <span class="material-icons"><?php echo $revenue_growth >= 0 ? 'trending_up' : 'trending_down'; ?></span>
                        <?php echo number_format(abs($revenue_growth), 1); ?>% vs Previous Year
                    </div>
                </div>

                <div class="kpi-card success">
                    <div class="kpi-label">Total Bookings</div>
                    <div class="kpi-value"><?php echo number_format($current_bookings ?? 0, 0, ',', '.'); ?></div>
                    <div class="kpi-trend <?php echo $bookings_growth >= 0 ? 'trend-up' : 'trend-down'; ?>">
                        <span class="material-icons"><?php echo $bookings_growth >= 0 ? 'trending_up' : 'trending_down'; ?></span>
                        <?php echo number_format(abs($bookings_growth), 1); ?>% vs Previous Year
                    </div>
                </div>

                <div class="kpi-card warning">
                    <div class="kpi-label">Avg Booking Value</div>
                    <div class="kpi-value">Rp <?php echo number_format($revenue_stats['avg_revenue_per_booking'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="kpi-trend">
                        <span class="material-icons">insights</span>
                        Per Booking
                    </div>
                </div>

                <div class="kpi-card info">
                    <div class="kpi-label">Total Nights</div>
                    <div class="kpi-value"><?php echo number_format($revenue_stats['total_nights'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="kpi-trend">
                        <span class="material-icons">hotel</span>
                        Booked Nights
                    </div>
                </div>

                <div class="kpi-card danger">
                    <div class="kpi-label">Revenue per Night</div>
                    <div class="kpi-value">Rp <?php echo number_format($revenue_stats['revenue_per_night'] ?? 0, 0, ',', '.'); ?></div>
                    <div class="kpi-trend">
                        <span class="material-icons">nightlight</span>
                        Per Night Average
                    </div>
                </div>
            </div>

            <div class="metric-highlights">
                <div class="metric-item">
                    <div class="metric-label">Max Booking Value</div>
                    <div class="metric-value">Rp <?php echo number_format($revenue_stats['max_booking_value'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Min Booking Value</div>
                    <div class="metric-value">Rp <?php echo number_format($revenue_stats['min_booking_value'] ?? 0, 0, ',', '.'); ?></div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Avg Nights per Booking</div>
                    <div class="metric-value"><?php echo $current_bookings > 0 ? number_format(($revenue_stats['total_nights'] ?? 0) / $current_bookings, 1) : '0.0'; ?></div>
                </div>
                <div class="metric-item">
                    <div class="metric-label">Booking Completion Rate</div>
                    <div class="metric-value">100%</div>
                </div>
            </div>

            <div class="revenue-section">
                <h2>
                    <span class="material-icons">trending_up</span>
                    Revenue Trends (<?php echo $time_period === 'weekly' ? 'Weekly' : 'Monthly'; ?>)
                </h2>
                <div class="chart-container">
                    <canvas id="revenueTrendsChart"></canvas>
                </div>
            </div>

            <div class="revenue-section">
                <h2>
                    <span class="material-icons">calendar_today</span>
                    Daily Revenue Performance (Last 30 Days)
                </h2>
                <div class="chart-container">
                    <canvas id="dailyRevenueChart"></canvas>
                </div>
            </div>

            <div class="revenue-section">
                <h2>
                    <span class="material-icons">business</span>
                    Revenue by Hotel
                </h2>
                <div class="chart-container">
                    <canvas id="hotelRevenueChart"></canvas>
                </div>
                <div class="table-responsive">
                    <table class="revenue-table">
                        <thead>
                            <tr>
                                <th>Hotel</th>
                                <th>City</th>
                                <th>Bookings</th>
                                <th>Revenue</th>
                                <th>Avg Booking</th>
                                <th>Revenue Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (!empty($revenue_by_hotel_data_table)) {
                                foreach ($revenue_by_hotel_data_table as $hotel): 
                                    $revenue_share = $total_revenue > 0 ? ($hotel['revenue'] / $total_revenue) * 100 : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($hotel['nama_hotel']); ?></td>
                                    <td><?php echo htmlspecialchars($hotel['kota']); ?></td>
                                    <td><?php echo number_format($hotel['bookings'], 0, ',', '.'); ?></td>
                                    <td>Rp <?php echo number_format($hotel['revenue'], 0, ',', '.'); ?></td>
                                    <td>Rp <?php echo number_format($hotel['avg_booking_value'], 0, ',', '.'); ?></td>
                                    <td>
                                        <span class="growth-indicator <?php echo $revenue_share > 10 ? 'growth-positive' : 'growth-neutral'; ?>">
                                            <?php echo number_format($revenue_share, 1); ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php 
                                endforeach;
                            } else {
                                echo '<tr><td colspan="6" style="text-align: center;">No data available</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="revenue-section">
                <h2>
                    <span class="material-icons">meeting_room</span>
                    Revenue by Room Type
                </h2>
                <div class="chart-container">
                    <canvas id="roomTypeRevenueChart"></canvas>
                </div>
                <div class="table-responsive">
                    <table class="revenue-table">
                        <thead>
                            <tr>
                                <th>Room Type</th>
                                <th>Bookings</th>
                                <th>Revenue</th>
                                <th>Avg Booking</th>
                                <th>Revenue Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (!empty($revenue_by_room_type_data_table)) {
                                foreach ($revenue_by_room_type_data_table as $room_type): 
                                    $room_revenue_share = $total_revenue > 0 ? ($room_type['revenue'] / $total_revenue) * 100 : 0;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($room_type['nama_tipe']); ?></td>
                                    <td><?php echo number_format($room_type['bookings'], 0, ',', '.'); ?></td>
                                    <td>Rp <?php echo number_format($room_type['revenue'], 0, ',', '.'); ?></td>
                                    <td>Rp <?php echo number_format($room_type['avg_booking_value'], 0, ',', '.'); ?></td>
                                    <td>
                                        <span class="growth-indicator <?php echo $room_revenue_share > 15 ? 'growth-positive' : 'growth-neutral'; ?>">
                                            <?php echo number_format($room_revenue_share, 1); ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php 
                                endforeach;
                            } else {
                                echo '<tr><td colspan="5" style="text-align: center;">No data available</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="revenue-section">
                <h2>
                    <span class="material-icons">people</span>
                    Customer Type Analysis
                </h2>
                <div class="revenue-grid">
                    <div class="revenue-card">
                        <h3><span class="material-icons">group</span> Customer Distribution</h3>
                        <div class="chart-container">
                            <canvas id="customerTypeChart"></canvas>
                        </div>
                    </div>
                    <div class="revenue-card">
                        <h3><span class="material-icons">bar_chart</span> Revenue by Customer Type</h3>
                        <div class="chart-container">
                            <canvas id="customerRevenueChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="revenue-table">
                        <thead>
                            <tr>
                                <th>Customer Type</th>
                                <th>Bookings</th>
                                <th>Revenue</th>
                                <th>Avg Booking Value</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (!empty($customer_type_data_table)) {
                                foreach ($customer_type_data_table as $customer_type): ?>
                                <tr>
                                    <td>
                                        <span class="material-icons" style="vertical-align: middle; margin-right: 5px;">
                                            <?php echo $customer_type['customer_type'] == 'Repeat Customer' ? 'loyalty' : 'person_add'; ?>
                                        </span>
                                        <?php echo htmlspecialchars($customer_type['customer_type']); ?>
                                    </td>
                                    <td><?php echo number_format($customer_type['bookings'], 0, ',', '.'); ?></td>
                                    <td>Rp <?php echo number_format($customer_type['revenue'], 0, ',', '.'); ?></td>
                                    <td>Rp <?php echo number_format($customer_type['avg_booking_value'], 0, ',', '.'); ?></td>
                                    <td>
                                        <span class="growth-indicator growth-positive">
                                            <?php echo $customer_type['customer_type'] == 'Repeat Customer' ? 'High Value' : 'Growth Potential'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php 
                                endforeach;
                            } else {
                                echo '<tr><td colspan="5" style="text-align: center;">No data available</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        // =================================================================
        // 1. PROFILE PHOTO UPLOAD
        // =================================================================
        document.getElementById('profilePhoto').addEventListener('click', function() {
            document.getElementById('profileUpload').click();
        });

        document.getElementById('profileUpload').addEventListener('change', function() {
            document.getElementById('uploadForm').submit();
        });

        // =================================================================
        // 2. DROPDOWN FUNCTIONS
        // =================================================================
        function toggleDropdown(button) {
            event.stopPropagation();
            const dropdown = button.nextElementSibling;
            const isExpanded = button.getAttribute('aria-expanded') === 'true';

            // Close all other dropdowns first
            document.querySelectorAll('.user-dropdown .dropdown-content').forEach(d => {
                d.classList.remove('show');
                d.setAttribute('aria-hidden', 'true');
                d.previousElementSibling.setAttribute('aria-expanded', 'false');
            });

            // Toggle current dropdown
            if (!isExpanded) {
                dropdown.classList.add('show');
                button.setAttribute('aria-expanded', 'true');
                dropdown.setAttribute('aria-hidden', 'false');
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.user-dropdown')) {
                document.querySelectorAll('.user-dropdown .dropdown-content').forEach(d => {
                    d.classList.remove('show');
                    d.setAttribute('aria-hidden', 'true');
                    d.previousElementSibling.setAttribute('aria-expanded', 'false');
                });
            }
        });

        // =================================================================
        // 3. SIDEBAR TOGGLE
        // =================================================================
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

        // =================================================================
        // 4. MENU TOGGLE FUNCTIONALITY
        // =================================================================
        document.querySelectorAll('.booking-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const parentMenu = this.closest('.user-menu');
                const dropdownId = this.getAttribute('data-target');
                const dropdown = document.getElementById(dropdownId);
                const isExpanded = parentMenu.getAttribute('aria-expanded') === 'true';
                
                // Close all other menus
                document.querySelectorAll('.user-menu').forEach(menu => {
                    if (menu !== parentMenu) {
                        menu.setAttribute('aria-expanded', 'false');
                        const otherDropdownId = menu.querySelector('.booking-toggle').getAttribute('data-target');
                        const otherDropdown = document.getElementById(otherDropdownId);
                        if (otherDropdown) {
                            otherDropdown.classList.remove('show');
                            otherDropdown.classList.add('hidden');
                            otherDropdown.setAttribute('aria-hidden', 'true');
                        }
                    }
                });

                // Toggle current menu
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

        // Close menus when clicking outside
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

        // =================================================================
        // 5. FILTER FUNCTIONS
        // =================================================================
        function applyFilters() {
            const year = document.getElementById('year').value;
            const month = document.getElementById('month').value;
            const hotel = document.getElementById('hotel').value;
            const timePeriod = document.getElementById('time_period').value;
            
            let url = `revenue_dashboard.php?year=${year}&month=${month}&hotel_id=${hotel}&time_period=${timePeriod}`;
            window.location.href = url;
        }

        function exportToExcel() {
            alert('Export functionality would be implemented here. This would generate an Excel report with current filtered data.');
        }

        // =================================================================
        // 6. CHART INITIALIZATION
        // =================================================================
        document.addEventListener('DOMContentLoaded', function() {
            // Set Financial Analysis menu to open by default
            const financialDropdown = document.getElementById('financialDropdown');
            const financialMenu = document.querySelector('.user-menu a[data-target="financialDropdown"]')?.closest('.user-menu');
            
            if (financialDropdown && financialMenu) {
                const isActive = financialDropdown.querySelector('a.active');
                if (isActive) {
                    financialDropdown.classList.add('show');
                    financialDropdown.classList.remove('hidden');
                    financialMenu.setAttribute('aria-expanded', 'true');
                }
            }
            
            // Data from PHP
            const revenueTrendsData = <?php echo json_encode($revenue_trends_data); ?>;
            const dailyRevenueData = <?php echo json_encode($daily_revenue_data); ?>;
            const hotelLabels = <?php echo json_encode($hotelLabels); ?>;
            const hotelData = <?php echo json_encode($hotelData); ?>;
            const roomTypeLabels = <?php echo json_encode($roomTypeLabels); ?>;
            const roomTypeData = <?php echo json_encode($roomTypeData); ?>;
            const customerTypes = <?php echo json_encode($customerTypes); ?>;
            const customerBookings = <?php echo json_encode($customerBookings); ?>;
            const customerRevenue = <?php echo json_encode($customerRevenue); ?>;
            
            const colors = [
                '#FF7A3D', '#eda100', '#1baf7a', '#e34948', '#2a78d6',
                '#eda100', '#9c27b0', '#607d8b', '#795548', '#009688'
            ];

            // Revenue Trends Chart
            if (document.getElementById('revenueTrendsChart') && revenueTrendsData.periods.length > 0) {
                const trendsCtx = document.getElementById('revenueTrendsChart').getContext('2d');
                new Chart(trendsCtx, {
                    type: 'line',
                    data: {
                        labels: revenueTrendsData.periods,
                        datasets: [{
                            label: 'Revenue (in Millions IDR)',
                            data: revenueTrendsData.revenue,
                            borderColor: colors[0],
                            backgroundColor: 'rgba(255, 122, 61, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }, {
                            label: 'Number of Bookings',
                            data: revenueTrendsData.bookings,
                            borderColor: colors[1],
                            backgroundColor: 'rgba(255, 152, 0, 0.1)',
                            borderWidth: 2,
                            fill: false,
                            tension: 0.4,
                            yAxisID: 'y1'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        scales: {
                            y: { 
                                position: 'left', 
                                title: { display: true, text: 'Revenue (Millions IDR)' } 
                            },
                            y1: { 
                                position: 'right', 
                                title: { display: true, text: 'Number of Bookings' },
                                grid: { drawOnChartArea: false },
                            }
                        }
                    }
                });
            }

            // Daily Revenue Chart
            if (document.getElementById('dailyRevenueChart') && dailyRevenueData.dates.length > 0) {
                const dailyCtx = document.getElementById('dailyRevenueChart').getContext('2d');
                new Chart(dailyCtx, {
                    type: 'bar',
                    data: {
                        labels: dailyRevenueData.dates,
                        datasets: [{
                            label: 'Daily Revenue (IDR)',
                            data: dailyRevenueData.revenue,
                            backgroundColor: 'rgba(76, 175, 80, 0.6)',
                            borderColor: 'rgba(76, 175, 80, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        scales: { 
                            y: { 
                                beginAtZero: true, 
                                title: { display: true, text: 'Revenue (IDR)' },
                                ticks: {
                                    callback: function(value) {
                                        return 'Rp ' + value.toLocaleString();
                                    }
                                }
                            } 
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'Revenue: Rp ' + context.raw.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Hotel Revenue Chart
            if (document.getElementById('hotelRevenueChart') && hotelLabels.length > 0) {
                const hotelCtx = document.getElementById('hotelRevenueChart').getContext('2d');
                new Chart(hotelCtx, {
                    type: 'doughnut',
                    data: {
                        labels: hotelLabels,
                        datasets: [{ 
                            data: hotelData, 
                            backgroundColor: colors.slice(0, hotelLabels.length) 
                        }]
                    },
                    options: {
                        responsive: true, 
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'right' },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `${label}: Rp ${value.toLocaleString()} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Room Type Revenue Chart
            if (document.getElementById('roomTypeRevenueChart') && roomTypeLabels.length > 0) {
                const roomTypeCtx = document.getElementById('roomTypeRevenueChart').getContext('2d');
                new Chart(roomTypeCtx, {
                    type: 'pie',
                    data: {
                        labels: roomTypeLabels,
                        datasets: [{ 
                            data: roomTypeData, 
                            backgroundColor: colors.slice(0, roomTypeLabels.length) 
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        plugins: { 
                            legend: { position: 'right' },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        return `${label}: Rp ${value.toLocaleString()}`;
                                    }
                                }
                            }
                        } 
                    }
                });
            }

            // Customer Type Chart
            if (document.getElementById('customerTypeChart') && customerTypes.length > 0) {
                const customerTypeCtx = document.getElementById('customerTypeChart').getContext('2d');
                new Chart(customerTypeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: customerTypes,
                        datasets: [{ 
                            data: customerBookings, 
                            backgroundColor: [colors[0], colors[1]] 
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `${label}: ${value} bookings (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Customer Revenue Chart
            if (document.getElementById('customerRevenueChart') && customerTypes.length > 0) {
                const customerRevenueCtx = document.getElementById('customerRevenueChart').getContext('2d');
                new Chart(customerRevenueCtx, {
                    type: 'bar',
                    data: {
                        labels: customerTypes,
                        datasets: [{
                            label: 'Revenue by Customer Type',
                            data: customerRevenue,
                            backgroundColor: [colors[0], colors[1]]
                        }]
                    },
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        scales: { 
                            y: { 
                                beginAtZero: true, 
                                title: { display: true, text: 'Revenue (IDR)' },
                                ticks: {
                                    callback: function(value) {
                                        return 'Rp ' + value.toLocaleString();
                                    }
                                }
                            } 
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'Revenue: Rp ' + context.raw.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });

        // =================================================================
        // 7. NOTIFICATION HANDLING
        // =================================================================
        <?php if (isset($_SESSION['upload_notification'])): ?>
            setTimeout(() => {
                const notification = document.querySelector('.notification-message');
                if(notification) {
                    notification.style.opacity = '1';
                    setTimeout(() => {
                        notification.style.opacity = '0';
                        setTimeout(() => notification.remove(), 500);
                    }, 5000);
                }
            }, 100);
            <?php unset($_SESSION['upload_notification']); ?>
        <?php endif; ?>
    </script>
</body>
</html>