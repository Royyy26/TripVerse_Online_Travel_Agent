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

$id_user = $_SESSION['id_user'];

// Get admin data dengan prepared statement
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
    error_log("Error preparing admin query: " . $conn->error);
    die("System error. Please try again later.");
}

$stmt->bind_param("s", $id_user);
if (!$stmt->execute()) {
    error_log("Error executing admin query: " . $stmt->error);
    die("System error. Please try again later.");
}

$result = $stmt->get_result();

if ($data = $result->fetch_assoc()) {
    $username   = htmlspecialchars($data['username']);
    $email      = htmlspecialchars($data['email']);
    $firstName  = htmlspecialchars($data['first_name']);
    $lastName   = htmlspecialchars($data['last_name']);
    $mobile     = htmlspecialchars($data['no_hp']);
    $gender     = htmlspecialchars($data['gender']);
    $foto       = $data['profile_picture'] ? '../uploads/' . basename($data['profile_picture']) : '../images/default.jpg';
} else {
    $username = "Unknown";
    $email = "unknown@tripverse.com";
    $firstName = $lastName = $mobile = $gender = "-";
    $foto = "../images/default.jpg";
}

// Get filter parameters dengan validasi
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : 0;
$hotel_id = isset($_GET['hotel_id']) ? trim($_GET['hotel_id']) : 'all';
$room_type = isset($_GET['room_type']) ? trim($_GET['room_type']) : 'all';

// Get search parameter for hotel highlighting
$hotel_search = isset($_GET['hotel_search']) ? trim($_GET['hotel_search']) : '';

// Validate inputs
if ($year < 2000 || $year > 2100) $year = date('Y');
if ($month < 0 || $month > 12) $month = 0;

// Security: Validate hotel_id and room_type format if not 'all'
if ($hotel_id !== 'all' && !preg_match('/^[a-zA-Z0-9_-]+$/', $hotel_id)) {
    $hotel_id = 'all';
}

if ($room_type !== 'all' && !preg_match('/^[a-zA-Z0-9_-]+$/', $room_type)) {
    $room_type = 'all';
}

// Sanitize hotel search term
$hotel_search = htmlspecialchars($hotel_search);

// Build WHERE clause for filters
$where_conditions = ["YEAR(bh.tanggal_booking) = ?", "bh.status = 'Completed'"];
$params = [$year];
$param_types = "i";

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

if ($room_type !== 'all') {
    $where_conditions[] = "bh.tipe_id = ?";
    $params[] = $room_type;
    $param_types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

// Function to execute prepared statements safely
function executeQuery($conn, $query, $param_types, $params)
{
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        error_log("Error preparing query: " . $conn->error);
        return false;
    }

    if ($param_types && $params) {
        $stmt->bind_param($param_types, ...$params);
    }

    if (!$stmt->execute()) {
        error_log("Error executing query: " . $stmt->error);
        return false;
    }

    return $stmt;
}

// ALOS ANALYSIS DATA - INTEGRATED WITH DATABASE

// Overall ALOS Statistics
$alos_stats_query = "SELECT 
    COUNT(*) as total_bookings,
    COALESCE(AVG(DATEDIFF(bh.check_out, bh.check_in)), 0) as avg_alos,
    COALESCE(MIN(DATEDIFF(bh.check_out, bh.check_in)), 0) as min_alos,
    COALESCE(MAX(DATEDIFF(bh.check_out, bh.check_in)), 0) as max_alos,
    COALESCE(SUM(DATEDIFF(bh.check_out, bh.check_in)), 0) as total_nights,
    COALESCE(CAST(SUM(bh.total_harga) AS DECIMAL(15,2)), 0) as total_revenue
    FROM booking_hotel bh
    WHERE $where_clause";

$stmt_stats = executeQuery($conn, $alos_stats_query, $param_types, $params);
if ($stmt_stats) {
    $alos_stats_result = $stmt_stats->get_result();
    $alos_stats = $alos_stats_result->fetch_assoc() ?? [
        'total_bookings' => 0,
        'avg_alos' => 0,
        'min_alos' => 0,
        'max_alos' => 0,
        'total_nights' => 0,
        'total_revenue' => 0
    ];
} else {
    $alos_stats = [
        'total_bookings' => 0,
        'avg_alos' => 0,
        'min_alos' => 0,
        'max_alos' => 0,
        'total_nights' => 0,
        'total_revenue' => 0
    ];
}

// Monthly ALOS Trends - FIXED: Remove duplicate MONTHNAME
$monthly_alos_query = "SELECT 
    MONTH(bh.tanggal_booking) as month_num,
    COUNT(*) as bookings,
    COALESCE(AVG(DATEDIFF(bh.check_out, bh.check_in)), 0) as avg_alos,
    COALESCE(SUM(DATEDIFF(bh.check_out, bh.check_in)), 0) as total_nights,
    COALESCE(CAST(SUM(bh.total_harga) AS DECIMAL(15,2)), 0) as revenue,
    COALESCE(CAST(AVG(bh.total_harga) AS DECIMAL(15,2)), 0) as avg_revenue_per_booking
    FROM booking_hotel bh
    WHERE $where_clause
    GROUP BY MONTH(bh.tanggal_booking)
    ORDER BY month_num";

$stmt_monthly = executeQuery($conn, $monthly_alos_query, $param_types, $params);

$monthly_alos_data = [
    'months' => [],
    'bookings' => [],
    'avg_alos' => [],
    'total_nights' => [],
    'revenue' => [],
    'avg_revenue' => []
];

if ($stmt_monthly) {
    $monthly_alos_result = $stmt_monthly->get_result();
    while ($row = $monthly_alos_result->fetch_assoc()) {
        $monthly_alos_data['months'][] = getIndonesianMonth($row['month_num']);
        $monthly_alos_data['bookings'][] = (int)$row['bookings'];
        $monthly_alos_data['avg_alos'][] = round((float)$row['avg_alos'], 2);
        $monthly_alos_data['total_nights'][] = (int)$row['total_nights'];
        $monthly_alos_data['revenue'][] = (float)$row['revenue'];
        $monthly_alos_data['avg_revenue'][] = (float)$row['avg_revenue_per_booking'];
    }
}

// ALOS by Hotel - FIXED: Move WHERE conditions to JOIN properly
$alos_by_hotel_query = "SELECT 
    h.hotel_id,
    h.nama_hotel,
    h.kota,
    COUNT(bh.booking_id) as bookings,
    COALESCE(AVG(DATEDIFF(bh.check_out, bh.check_in)), 0) as avg_alos,
    COALESCE(SUM(DATEDIFF(bh.check_out, bh.check_in)), 0) as total_nights,
    COALESCE(CAST(SUM(bh.total_harga) AS DECIMAL(15,2)), 0) as revenue,
    COALESCE(CAST(AVG(bh.total_harga) AS DECIMAL(15,2)), 0) as avg_revenue_per_booking,
    COALESCE(CAST((SUM(bh.total_harga) / NULLIF(SUM(DATEDIFF(bh.check_out, bh.check_in)), 0)) AS DECIMAL(15,2)), 0) as rev_per_night
    FROM hotel h
    LEFT JOIN booking_hotel bh ON h.hotel_id = bh.hotel_id 
        AND YEAR(bh.tanggal_booking) = ? 
        AND bh.status = 'Completed'";

// Build query dynamically based on filters
$hotel_bind_params = [$year];
$hotel_bind_types = "i";

if ($month > 0) {
    $alos_by_hotel_query .= " AND MONTH(bh.tanggal_booking) = ?";
    $hotel_bind_params[] = $month;
    $hotel_bind_types .= "i";
}
if ($hotel_id !== 'all') {
    $alos_by_hotel_query .= " AND bh.hotel_id = ?";
    $hotel_bind_params[] = $hotel_id;
    $hotel_bind_types .= "s";
}
if ($room_type !== 'all') {
    $alos_by_hotel_query .= " AND bh.tipe_id = ?";
    $hotel_bind_params[] = $room_type;
    $hotel_bind_types .= "s";
}

// Tambahkan pencarian hotel jika ada search term
if (!empty($hotel_search)) {
    $alos_by_hotel_query .= " AND (h.nama_hotel LIKE ? OR h.kota LIKE ?)";
    $search_term = "%" . $hotel_search . "%";
    $hotel_bind_params[] = $search_term;
    $hotel_bind_params[] = $search_term;
    $hotel_bind_types .= "ss";
}

$alos_by_hotel_query .= " GROUP BY h.hotel_id, h.nama_hotel, h.kota
    ORDER BY avg_alos DESC";

$stmt_hotel = $conn->prepare($alos_by_hotel_query);
if ($stmt_hotel) {
    $stmt_hotel->bind_param($hotel_bind_types, ...$hotel_bind_params);
    $stmt_hotel->execute();
    $alos_by_hotel_result = $stmt_hotel->get_result();
} else {
    $alos_by_hotel_result = false;
}

// Store hotel data for later use
$hotel_data = [];
$hotel_counter = 0;
if ($alos_by_hotel_result && $alos_by_hotel_result->num_rows > 0) {
    while ($hotel = $alos_by_hotel_result->fetch_assoc()) {
        $hotel_data[] = $hotel;
        $hotel_counter++;
    }
}

// ALOS by Room Type - FIXED: Move WHERE conditions properly
$alos_by_room_type_query = "SELECT 
    t.tipe_id,
    t.nama_tipe,
    COUNT(bh.booking_id) as bookings,
    COALESCE(AVG(DATEDIFF(bh.check_out, bh.check_in)), 0) as avg_alos,
    COALESCE(SUM(DATEDIFF(bh.check_out, bh.check_in)), 0) as total_nights,
    COALESCE(CAST(SUM(bh.total_harga) AS DECIMAL(15,2)), 0) as revenue,
    COALESCE(CAST(AVG(bh.total_harga) AS DECIMAL(15,2)), 0) as avg_revenue_per_booking,
    COALESCE(CAST((SUM(bh.total_harga) / NULLIF(SUM(DATEDIFF(bh.check_out, bh.check_in)), 0)) AS DECIMAL(15,2)), 0) as rev_per_night
    FROM tipe_kamar t
    LEFT JOIN booking_hotel bh ON t.tipe_id = bh.tipe_id 
        AND YEAR(bh.tanggal_booking) = ? 
        AND bh.status = 'Completed'";

$room_bind_params = [$year];
$room_bind_types = "i";

if ($month > 0) {
    $alos_by_room_type_query .= " AND MONTH(bh.tanggal_booking) = ?";
    $room_bind_params[] = $month;
    $room_bind_types .= "i";
}
if ($hotel_id !== 'all') {
    $alos_by_room_type_query .= " AND bh.hotel_id = ?";
    $room_bind_params[] = $hotel_id;
    $room_bind_types .= "s";
}
if ($room_type !== 'all') {
    $alos_by_room_type_query .= " AND bh.tipe_id = ?";
    $room_bind_params[] = $room_type;
    $room_bind_types .= "s";
}

$alos_by_room_type_query .= " GROUP BY t.tipe_id, t.nama_tipe
    ORDER BY avg_alos DESC";

$stmt_room_type = $conn->prepare($alos_by_room_type_query);
if ($stmt_room_type) {
    $stmt_room_type->bind_param($room_bind_types, ...$room_bind_params);
    $stmt_room_type->execute();
    $alos_by_room_type_result = $stmt_room_type->get_result();
} else {
    $alos_by_room_type_result = false;
}

// ALOS Distribution (Frequency of different stay durations)
$alos_distribution_query = "SELECT 
    DATEDIFF(bh.check_out, bh.check_in) as stay_duration,
    COUNT(*) as frequency,
    COALESCE(CAST(AVG(bh.total_harga) AS DECIMAL(15,2)), 0) as avg_revenue
    FROM booking_hotel bh
    WHERE $where_clause
    GROUP BY DATEDIFF(bh.check_out, bh.check_in)
    ORDER BY stay_duration";

$stmt_distribution = executeQuery($conn, $alos_distribution_query, $param_types, $params);

$alos_distribution_data = [
    'stay_durations' => [],
    'frequencies' => [],
    'avg_revenues' => []
];

if ($stmt_distribution) {
    $alos_distribution_result = $stmt_distribution->get_result();
    while ($row = $alos_distribution_result->fetch_assoc()) {
        $alos_distribution_data['stay_durations'][] = $row['stay_duration'] . ' days';
        $alos_distribution_data['frequencies'][] = (int)$row['frequency'];
        $alos_distribution_data['avg_revenues'][] = (float)$row['avg_revenue'];
    }
}

// Peak ALOS Segments
$alos_segments_query = "SELECT 
    CASE 
        WHEN DATEDIFF(bh.check_out, bh.check_in) = 1 THEN '1 Day'
        WHEN DATEDIFF(bh.check_out, bh.check_in) = 2 THEN '2 Days'
        WHEN DATEDIFF(bh.check_out, bh.check_in) = 3 THEN '3 Days'
        WHEN DATEDIFF(bh.check_out, bh.check_in) BETWEEN 4 AND 7 THEN '4-7 Days'
        ELSE '8+ Days'
    END as alos_segment,
    COUNT(*) as bookings,
    COALESCE(AVG(DATEDIFF(bh.check_out, bh.check_in)), 0) as avg_alos,
    COALESCE(CAST(SUM(bh.total_harga) AS DECIMAL(15,2)), 0) as total_revenue,
    COALESCE(CAST(AVG(bh.total_harga) AS DECIMAL(15,2)), 0) as avg_revenue
    FROM booking_hotel bh
    WHERE $where_clause
    GROUP BY alos_segment
    ORDER BY 
        CASE alos_segment
            WHEN '1 Day' THEN 1
            WHEN '2 Days' THEN 2
            WHEN '3 Days' THEN 3
            WHEN '4-7 Days' THEN 4
            ELSE 5
        END";

$stmt_segments = executeQuery($conn, $alos_segments_query, $param_types, $params);

$alos_segments_data = [
    'segments' => [],
    'bookings' => [],
    'avg_alos' => [],
    'total_revenue' => [],
    'avg_revenue' => []
];

if ($stmt_segments) {
    $alos_segments_result = $stmt_segments->get_result();
    while ($row = $alos_segments_result->fetch_assoc()) {
        $alos_segments_data['segments'][] = $row['alos_segment'];
        $alos_segments_data['bookings'][] = (int)$row['bookings'];
        $alos_segments_data['avg_alos'][] = round((float)$row['avg_alos'], 2);
        $alos_segments_data['total_revenue'][] = (float)$row['total_revenue'];
        $alos_segments_data['avg_revenue'][] = (float)$row['avg_revenue'];
    }
}

// ALOS vs Revenue Correlation
$alos_revenue_correlation_query = "SELECT 
    DATEDIFF(bh.check_out, bh.check_in) as alos,
    COALESCE(CAST(bh.total_harga AS DECIMAL(15,2)), 0) as revenue,
    h.nama_hotel,
    t.nama_tipe
    FROM booking_hotel bh
    JOIN hotel h ON bh.hotel_id = h.hotel_id
    JOIN tipe_kamar t ON bh.tipe_id = t.tipe_id
    WHERE $where_clause
    ORDER BY alos";

$stmt_correlation = executeQuery($conn, $alos_revenue_correlation_query, $param_types, $params);

$alos_revenue_data = [
    'alos_values' => [],
    'revenue_values' => [],
    'hotels' => [],
    'room_types' => []
];

if ($stmt_correlation) {
    $alos_revenue_correlation_result = $stmt_correlation->get_result();
    while ($row = $alos_revenue_correlation_result->fetch_assoc()) {
        $alos_revenue_data['alos_values'][] = (int)$row['alos'];
        $alos_revenue_data['revenue_values'][] = (float)$row['revenue'];
        $alos_revenue_data['hotels'][] = htmlspecialchars($row['nama_hotel']);
        $alos_revenue_data['room_types'][] = htmlspecialchars($row['nama_tipe']);
    }
}

// Get list of available years
$years_query = "SELECT DISTINCT YEAR(tanggal_booking) as year FROM booking_hotel 
                WHERE tanggal_booking IS NOT NULL 
                ORDER BY year DESC";
$years_result = $conn->query($years_query);
$available_years = [];
if ($years_result) {
    while ($row = $years_result->fetch_assoc()) {
        $available_years[] = $row['year'];
    }
}
if (!in_array(date('Y'), $available_years)) {
    $available_years[] = date('Y');
    rsort($available_years);
}

// Get hotels for filter
$hotels_query = "SELECT hotel_id, nama_hotel, kota FROM hotel ORDER BY nama_hotel";
$hotels_result = $conn->query($hotels_query);

// Get room types for filter
$room_types_query = "SELECT tipe_id, nama_tipe FROM tipe_kamar ORDER BY nama_tipe";
$room_types_result = $conn->query($room_types_query);

// Get system notifications
$query = "SELECT COUNT(*) as notifications 
          FROM booking_hotel 
          WHERE status = 'Pending'";
$result = $conn->query($query);
$notificationCount = 0;
if ($result) {
    $row = $result->fetch_assoc();
    $notificationCount = $row['notifications'] ?? 0;
}

// Close statements if they exist
$statements = [
    $stmt,
    $stmt_stats,
    $stmt_monthly,
    $stmt_hotel,
    $stmt_room_type,
    $stmt_distribution,
    $stmt_segments,
    $stmt_correlation
];
foreach ($statements as $s) {
    if (isset($s) && $s instanceof mysqli_stmt) {
        $s->close();
    }
}
$conn->close();

// Function to get Indonesian month names
function getIndonesianMonth($monthNumber)
{
    $months = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];
    return $months[(int)$monthNumber] ?? 'Unknown';
}

// Function to format currency in Rupiah
function formatRupiah($amount)
{
    if ($amount == 0 || $amount == null) {
        return 'Rp 0';
    }

    // Ensure it's a number
    $amount = floatval($amount);

    if ($amount >= 1000000000000) {
        return 'Rp ' . number_format($amount / 1000000000000, 2, ',', '.') . ' Triliun';
    } elseif ($amount >= 1000000000) {
        return 'Rp ' . number_format($amount / 1000000000, 2, ',', '.') . ' Miliar';
    } elseif ($amount >= 1000000) {
        return 'Rp ' . number_format($amount / 1000000, 2, ',', '.') . ' Juta';
    } elseif ($amount >= 1000) {
        return 'Rp ' . number_format($amount / 1000, 1, ',', '.') . ' Ribu';
    } else {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}

// Function to convert M to Rp (for database values)
function convertMtoRp($value)
{
    if (empty($value) || $value == 0) return 0;

    // Jika nilai sudah angka, langsung return
    if (is_numeric($value)) {
        return floatval($value);
    }

    return floatval($value);
}

// Function to highlight search terms in text
function highlightSearchTerm($text, $searchTerm)
{
    if (empty($searchTerm) || empty($text)) {
        return $text;
    }

    $searchTerm = strtolower($searchTerm);
    $textLower = strtolower($text);

    $pos = stripos($textLower, $searchTerm);
    if ($pos !== false) {
        $highlighted = substr($text, $pos, strlen($searchTerm));
        $replacement = '<span class="search-highlight">' . $highlighted . '</span>';
        $result = substr_replace($text, $replacement, $pos, strlen($highlighted));
        return $result;
    }

    return $text;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>ALOS Analysis - Analisis Rata-rata Lama Menginap | TripVerse Admin</title>
    <link rel="stylesheet" href="../css/dashboard.css?v=1.4.0" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <style>
        :root {
            --primary-color: #3f51b5;
            --secondary-color: #ff9800;
            --success-color: #4caf50;
            --info-color: #2196f3;
            --warning-color: #ffc107;
            --danger-color: #f44336;
            --light-color: #f5f5f5;
            --dark-color: #212121;
            --text-color: #333;
            --text-light: #777;
            --border-radius: 12px;
            --box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --box-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s ease;
            --gradient-primary: linear-gradient(135deg, #3f51b5, #5c6bc0);
            --gradient-success: linear-gradient(135deg, #4caf50, #66bb6a);
            --gradient-warning: linear-gradient(135deg, #ff9800, #ffa726);
            --gradient-danger: linear-gradient(135deg, #f44336, #ef5350);
            --gradient-info: linear-gradient(135deg, #2196f3, #42a5f5);
        }

        /* Animation for loading */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        /* Modern Card Design */
        .dss-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
            margin-bottom: 30px;
            border-left: 4px solid var(--primary-color);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .dss-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.5s ease;
        }

        .dss-section:hover::before {
            transform: scaleX(1);
        }

        .dss-section:hover {
            box-shadow: var(--box-shadow-hover);
            transform: translateY(-5px);
        }

        .dss-section h2 {
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

        /* Enhanced KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .kpi-card {
            background: white;
            padding: 25px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
            border: none;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }

        .kpi-card.primary::before {
            background: var(--gradient-primary);
        }

        .kpi-card.success::before {
            background: var(--gradient-success);
        }

        .kpi-card.warning::before {
            background: var(--gradient-warning);
        }

        .kpi-card.danger::before {
            background: var(--gradient-danger);
        }

        .kpi-card.info::before {
            background: var(--gradient-info);
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-hover);
        }

        .kpi-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 20px;
            color: white;
        }

        .kpi-card.primary .kpi-icon {
            background: var(--gradient-primary);
        }

        .kpi-card.success .kpi-icon {
            background: var(--gradient-success);
        }

        .kpi-card.warning .kpi-icon {
            background: var(--gradient-warning);
        }

        .kpi-card.danger .kpi-icon {
            background: var(--gradient-danger);
        }

        .kpi-card.info .kpi-icon {
            background: var(--gradient-info);
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
            font-weight: 500;
        }

        .kpi-trend {
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            margin-top: 8px;
        }

        .trend-up {
            color: var(--success-color);
        }

        .trend-down {
            color: var(--danger-color);
        }

        .trend-neutral {
            color: var(--text-light);
        }

        /* Enhanced Filter Controls - Revised */
        .filter-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 16px;
            margin-bottom: 25px;
            padding: 18px;
            background: linear-gradient(135deg, #f8f9fa 0%, #eef1f4 100%);
            border-radius: 12px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            align-items: end;
        }

        /* Filter Group */
        .filter-group {
            display: flex;
            flex-direction: column;
        }

        /* Label */
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-light);
            margin-bottom: 5px;
            letter-spacing: 0.4px;
            text-transform: none;
        }

        /* Select */
        .filter-select {
            padding: 10px 12px;
            border: 1.8px solid #e1e5ea;
            border-radius: 8px;
            font-size: 14px;
            width: 100%;
            background: #fff;
            box-sizing: border-box;
            transition: all 0.25s ease;
            height: 40px;
        }

        .filter-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(63, 81, 181, 0.15);
            outline: none;
        }

        /* Action Buttons */
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: flex-start;
        }

        /* Button */
        .filter-actions button,
        .filter-actions .reset-btn {
            height: 40px;
            padding: 0 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.25s ease;
        }

        /* Apply */
        .filter-actions button {
            background: var(--primary-color);
            color: #fff;
            border: none;
        }

        .filter-actions button:hover {
            background: #303f9f;
            box-shadow: 0 3px 10px rgba(63, 81, 181, 0.25);
        }

        /* Reset */
        .filter-actions .reset-btn {
            background: #6c757d;
            color: #fff;
            border: none;
        }

        .filter-actions .reset-btn:hover {
            background: #5a6268;
            box-shadow: 0 3px 10px rgba(108, 117, 125, 0.25);
        }

        .period-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            font-size: 14px;
            color: var(--primary-color);
            font-weight: 500;
            padding: 15px 20px;
            background: rgba(63, 81, 181, 0.05);
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-color);
        }

        /* Search Container Styles */
        .search-container {
            position: relative;
            margin-bottom: 10px;
        }

        .hotel-search {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
            box-sizing: border-box;
        }

        .hotel-search:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(63, 81, 181, 0.1);
            outline: none;
        }

        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .table-search-container {
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .table-search {
            flex: 1;
            max-width: 300px;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
        }

        .hotel-count {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
        }

        /* Search Highlight Styles */
        .search-highlight {
            background-color: #FFF9C4;
            color: #333;
            font-weight: bold;
            padding: 1px 3px;
            border-radius: 3px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .hotel-row.highlighted {
            background-color: #E8F5E9 !important;
            border-left: 4px solid #4CAF50;
            animation: pulseHighlight 1.5s ease-in-out;
        }

        .hotel-row.highlighted td {
            background-color: #E8F5E9 !important;
        }

        @keyframes pulseHighlight {
            0% {
                background-color: #FFFFFF;
            }

            50% {
                background-color: #E8F5E9;
            }

            100% {
                background-color: #E8F5E9;
            }
        }

        .no-highlight-results {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
            background-color: #f8f9fa;
            border-radius: 8px;
            margin: 10px 0;
        }

        /* Performance Grid */
        .performance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(480px, 1fr));
            gap: 25px;
        }

        .performance-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: var(--border-radius);
            padding: 25px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: var(--transition);
        }

        .performance-card:hover {
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .performance-card h3 {
            font-size: 17px;
            margin: 0 0 18px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--dark-color);
            font-weight: 600;
        }

        /* Chart Containers */
        .chart-container {
            width: 100%;
            height: 300px;
            margin: 20px 0;
        }

        /* Table Styling */
        .performance-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }

        .performance-table thead th {
            background: var(--gradient-primary);
            color: white;
            padding: 16px 14px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid var(--primary-color);
        }

        .performance-table tbody tr {
            transition: all 0.3s ease;
        }

        .performance-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .performance-table tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }

        .performance-table tbody tr:hover {
            background-color: #e8eaf6;
            cursor: pointer;
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .performance-table td {
            font-size: 14px;
            color: var(--text-color);
            padding: 14px;
            border-bottom: 1px solid #f0f0f0;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }

        .empty-state .material-icons {
            font-size: 64px;
            margin-bottom: 15px;
            color: #ccc;
        }

        .empty-state h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: var(--dark-color);
            font-weight: 600;
        }

        .empty-state p {
            margin: 0;
            font-size: 14px;
            color: var(--text-light);
        }

        /* Insights Section */
        .insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .insight-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--info-color);
            transition: var(--transition);
        }

        .insight-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--box-shadow-hover);
        }

        .insight-card h4 {
            margin: 0 0 10px;
            font-size: 16px;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .insight-card p {
            margin: 0;
            font-size: 14px;
            color: var(--text-light);
            line-height: 1.5;
        }

        /* Responsive Adjustments */
        @media (max-width: 1200px) {
            .performance-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 992px) {
            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            }

            .filter-controls {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }

        @media (max-width: 768px) {

            .kpi-grid,
            .metric-grid {
                grid-template-columns: 1fr;
            }

            .filter-controls {
                grid-template-columns: 1fr;
                padding: 15px;
            }

            .chart-container {
                height: 250px;
            }

            .filter-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .filter-actions button,
            .filter-actions .reset-btn {
                width: 100%;
                justify-content: center;
            }

            .dss-section {
                padding: 20px;
                margin-bottom: 20px;
            }

            .period-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .table-search-container {
                flex-direction: column;
                align-items: stretch;
            }

            .table-search {
                max-width: 100%;
            }
        }

        @media (max-width: 480px) {
            .dss-section {
                padding: 15px;
            }

            .performance-card {
                padding: 15px;
            }

            .kpi-card {
                padding: 20px;
            }

            .chart-container {
                height: 250px;
                padding: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar" id="sidebar">
        <div class="profile-header">
            <div class="profile-photo-section">
                <div class="profile-photo-container">
                    <img src="<?php echo htmlspecialchars($foto); ?>"
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
                    <h2><?php echo htmlspecialchars($firstName . ' ' . $lastName); ?></h2>
                    <p><?php echo htmlspecialchars($email); ?></p>

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
                <a href="supplier_approvals.php">
                    <span class="material-icons">approval</span>
                    <span>Supplier Management</span>
                </a>
            <?php endif; ?>

            <!-- PROMO MANAGEMENT -->
            <a href="promo_management.php">
                <span class="material-icons">campaign</span>
                <span>Promo Management</span>
            </a>

            <!-- ANALYTICS & INSIGHTS -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="analyticsDropdown">
                    <span class="material-icons">monitor</span>
                    <span>Performance Monitoring</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="analyticsDropdown">
                    <a href="performance_analytics.php">
                        <span class="material-icons">bar_chart</span>
                        <span>Performance Statistics</span>
                    </a>
                    <a href="market_analysis.php">
                        <span class="material-icons">trending_up</span>
                        <span>Booking Trends</span>
                    </a>
                </div>
            </div>

            <!-- DECISION SUPPORT MODULES -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="decisionDropdown">
                    <span class="material-icons">analytics</span>
                    <span>Statistical Analysis</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="decisionDropdown">
                    <a href="revenue_optimization.php">
                        <span class="material-icons">attach_money</span>
                        <span>Revenue Statistics</span>
                    </a>
                    <a href="occupancy_analysis.php">
                        <span class="material-icons">king_bed</span>
                        <span>Occupancy Statistics</span>
                    </a>
                    <a href="alos_analysis.php" class="active">
                        <span class="material-icons">calendar_today</span>
                        <span>ALOS Statistics</span>
                    </a>
                </div>
            </div>

            <!-- CUSTOMER INTELLIGENCE -->
            <a href="customerdss.php">
                <span class="material-icons">people</span>
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
                    <?php if ($notificationCount > 0): ?>
                        <span class="notification-badge" id="notificationCount"><?php echo $notificationCount; ?></span>
                    <?php endif; ?>
                </div>

                <div class="user-menu">
                    <img src="<?php echo htmlspecialchars($foto); ?>" alt="User Avatar" class="user-avatar" />
                </div>
            </div>
        </header>

        <!-- ALOS Analysis Dashboard -->
        <div class="dss-section fade-in">
            <h2><i class="material-icons">nightlight_round</i> ALOS Analysis - Analisis Rata-rata Lama Menginap</h2>

            <!-- Filter Controls -->
            <form method="GET" action="alos_analysis.php" class="filter-controls" id="filterForm">
                <div class="filter-group">
                    <label for="year"><i class="material-icons">event</i> Tahun</label>
                    <select name="year" id="year" class="filter-select">
                        <?php foreach ($available_years as $year_option): ?>
                            <option value="<?php echo $year_option; ?>" <?php echo $year_option == $year ? 'selected' : ''; ?>>
                                <?php echo $year_option; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="month"><i class="material-icons">calendar_today</i> Bulan</label>
                    <select name="month" id="month" class="filter-select">
                        <option value="0">Semua Bulan</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $month == $m ? 'selected' : ''; ?>>
                                <?php echo getIndonesianMonth($m); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="hotel_id"><i class="material-icons">hotel</i> Hotel</label>
                    <select name="hotel_id" id="hotel_id" class="filter-select" style="margin-top: 10px;">
                        <option value="all">Semua Hotel</option>
                        <?php
                        if ($hotels_result) {
                            $hotels_result->data_seek(0);
                            while ($hotel_row = $hotels_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($hotel_row['hotel_id']); ?>"
                                    data-hotel-name="<?php echo htmlspecialchars($hotel_row['nama_hotel']); ?>"
                                    data-city="<?php echo htmlspecialchars($hotel_row['kota']); ?>"
                                    <?php echo $hotel_row['hotel_id'] == $hotel_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($hotel_row['nama_hotel'] . ' - ' . $hotel_row['kota']); ?>
                                </option>
                        <?php endwhile;
                        }
                        ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="room_type"><i class="material-icons">category</i> Tipe Kamar</label>
                    <select name="room_type" id="room_type" class="filter-select">
                        <option value="all">Semua Tipe Kamar</option>
                        <?php
                        if ($room_types_result) {
                            $room_types_result->data_seek(0);
                            while ($room_type_row = $room_types_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($room_type_row['tipe_id']); ?>" <?php echo $room_type_row['tipe_id'] == $room_type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($room_type_row['nama_tipe']); ?>
                                </option>
                        <?php endwhile;
                        }
                        ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" id="applyFilter">
                        <i class="material-icons">filter_alt</i> Terapkan Filter
                    </button>
                    <a href="alos_analysis.php" class="reset-btn">
                        <i class="material-icons">refresh</i> Reset
                    </a>
                </div>
            </form>

            <div class="period-info">
                <i class="material-icons">calendar_today</i>
                <span>Periode Analisis: <strong><?php echo $month > 0 ? getIndonesianMonth($month) . ' ' . $year : 'Tahun ' . $year; ?></strong></span>
                <?php if ($hotel_id !== 'all' && $hotels_result):
                    $hotels_result->data_seek(0);
                    while ($hotel = $hotels_result->fetch_assoc()):
                        if ($hotel['hotel_id'] == $hotel_id): ?>
                            <span>• Hotel: <strong><?php echo htmlspecialchars($hotel['nama_hotel']); ?></strong></span>
                <?php break;
                        endif;
                    endwhile;
                endif; ?>
                <?php if ($room_type !== 'all' && $room_types_result):
                    $room_types_result->data_seek(0);
                    while ($room = $room_types_result->fetch_assoc()):
                        if ($room['tipe_id'] == $room_type): ?>
                            <span>• Tipe Kamar: <strong><?php echo htmlspecialchars($room['nama_tipe']); ?></strong></span>
                <?php break;
                        endif;
                    endwhile;
                endif; ?>
                <?php if (!empty($hotel_search)): ?>
                    <span>• Pencarian: <strong>"<?php echo htmlspecialchars($hotel_search); ?>"</strong></span>
                <?php endif; ?>
            </div>

            <!-- ALOS Overview KPI -->
            <div class="kpi-grid">
                <div class="kpi-card primary fade-in">
                    <div class="kpi-icon">
                        <i class="material-icons">schedule</i>
                    </div>
                    <div class="kpi-label">Average ALOS</div>
                    <div class="kpi-value"><?php echo round($alos_stats['avg_alos'] ?? 0, 2); ?> Hari</div>
                    <div class="kpi-trend <?php echo ($alos_stats['avg_alos'] ?? 0) >= 3 ? 'trend-up' : (($alos_stats['avg_alos'] ?? 0) >= 2 ? 'trend-neutral' : 'trend-down'); ?>">
                        <i class="material-icons"><?php echo ($alos_stats['avg_alos'] ?? 0) >= 3 ? 'trending_up' : (($alos_stats['avg_alos'] ?? 0) >= 2 ? 'trending_flat' : 'trending_down'); ?></i>
                        Rata-rata Menginap
                    </div>
                </div>

                <div class="kpi-card success fade-in">
                    <div class="kpi-icon">
                        <i class="material-icons">hotel</i>
                    </div>
                    <div class="kpi-label">Total Nights</div>
                    <div class="kpi-value"><?php echo number_format($alos_stats['total_nights'] ?? 0); ?></div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">trending_up</i>
                        Malam Kamar
                    </div>
                </div>

                <div class="kpi-card warning fade-in">
                    <div class="kpi-icon">
                        <i class="material-icons">arrow_downward</i>
                    </div>
                    <div class="kpi-label">Shortest Stay</div>
                    <div class="kpi-value"><?php echo $alos_stats['min_alos'] ?? 0; ?> Hari</div>
                    <div class="kpi-trend trend-down">
                        <i class="material-icons">arrow_downward</i>
                        Minimum
                    </div>
                </div>

                <div class="kpi-card danger fade-in">
                    <div class="kpi-icon">
                        <i class="material-icons">arrow_upward</i>
                    </div>
                    <div class="kpi-label">Longest Stay</div>
                    <div class="kpi-value"><?php echo $alos_stats['max_alos'] ?? 0; ?> Hari</div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">arrow_upward</i>
                        Maksimum
                    </div>
                </div>

                <div class="kpi-card info fade-in">
                    <div class="kpi-icon">
                        <i class="material-icons">attach_money</i>
                    </div>
                    <div class="kpi-label">Revenue per Malam</div>
                    <div class="kpi-value"><?php echo $alos_stats['total_nights'] > 0 ? formatRupiah(($alos_stats['total_revenue'] ?? 0) / $alos_stats['total_nights']) : 'Rp 0'; ?></div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">trending_up</i>
                        Efisiensi
                    </div>
                </div>
            </div>

            <!-- Monthly ALOS Trends -->
            <div class="dss-section fade-in">
                <h2><i class="material-icons">trending_up</i> Tren ALOS Bulanan</h2>

                <div class="performance-grid">
                    <div class="performance-card fade-in">
                        <h3><i class="material-icons">show_chart</i> Tren ALOS</h3>
                        <div class="chart-container">
                            <canvas id="monthlyAlosChart"></canvas>
                        </div>
                    </div>

                    <div class="performance-card fade-in">
                        <h3><i class="material-icons">stacked_line_chart</i> ALOS vs Revenue</h3>
                        <div class="chart-container">
                            <canvas id="alosRevenueChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ALOS Distribution -->
            <div class="dss-section fade-in">
                <h2><i class="material-icons">bar_chart</i> Analisis Distribusi ALOS</h2>

                <div class="performance-grid">
                    <div class="performance-card fade-in">
                        <h3><i class="material-icons">pie_chart</i> Frekuensi Durasi Menginap</h3>
                        <div class="chart-container">
                            <canvas id="alosDistributionChart"></canvas>
                        </div>
                    </div>

                    <div class="performance-card fade-in">
                        <h3><i class="material-icons">category</i> Segmen ALOS</h3>
                        <div class="chart-container">
                            <canvas id="alosSegmentsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ALOS by Hotel -->
            <div class="dss-section fade-in">
                <h2><i class="material-icons">business</i> Analisis ALOS per Hotel</h2>

                <!-- Search Status -->
                <?php if (!empty($hotel_search)): ?>
                    <div class="period-info" style="margin-bottom: 15px;">
                        <i class="material-icons">search</i>
                        <span>Hasil pencarian untuk: <strong>"<?php echo htmlspecialchars($hotel_search); ?>"</strong></span>
                        <span>• Ditemukan: <strong><?php echo $hotel_counter; ?> hotel</strong></span>
                        <?php if ($hotel_counter == 0): ?>
                            <span style="color: var(--danger-color);">
                                <i class="material-icons">warning</i>
                                Tidak ditemukan hotel yang sesuai dengan kata kunci
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="table-search-container">
                    <div class="search-container" style="flex: 1;">
                        <input type="text"
                            id="hotelTableSearch"
                            class="table-search"
                            placeholder="Cari hotel dalam tabel ini..."
                            onkeyup="searchHotelTable()">
                        <span class="material-icons search-icon">search</span>
                    </div>
                    <div class="hotel-count" id="hotelCount">
                        Menampilkan <span id="visibleHotels"><?php echo $hotel_counter; ?></span> dari <span id="totalHotels"><?php echo $hotel_counter; ?></span> hotel
                    </div>
                </div>

                <div class="performance-card fade-in">
                    <table class="performance-table" id="hotelTable">
                        <thead>
                            <tr>
                                <th>Nama Hotel</th>
                                <th>Lokasi</th>
                                <th>Bookings</th>
                                <th>Avg ALOS</th>
                                <th>Total Malam</th>
                                <th>Revenue</th>
                                <th>Rev/Malam</th>
                            </tr>
                        </thead>
                        <tbody id="hotelTableBody">
                            <?php if (!empty($hotel_data)): ?>
                                <?php
                                $highlight_count = 0;
                                foreach ($hotel_data as $hotel):
                                    $should_highlight = !empty($hotel_search) &&
                                        (stripos($hotel['nama_hotel'], $hotel_search) !== false ||
                                            stripos($hotel['kota'], $hotel_search) !== false);
                                    if ($should_highlight) {
                                        $highlight_count++;
                                    }
                                ?>
                                    <tr class="fade-in hotel-row <?php echo $should_highlight ? 'highlighted' : ''; ?>"
                                        data-hotel-name="<?php echo htmlspecialchars(strtolower($hotel['nama_hotel'])); ?>"
                                        data-city="<?php echo htmlspecialchars(strtolower($hotel['kota'])); ?>">
                                        <td><strong>
                                                <?php
                                                if (!empty($hotel_search)) {
                                                    echo highlightSearchTerm(htmlspecialchars($hotel['nama_hotel']), $hotel_search);
                                                } else {
                                                    echo htmlspecialchars($hotel['nama_hotel']);
                                                }
                                                ?>
                                            </strong></td>
                                        <td>
                                            <?php
                                            if (!empty($hotel_search)) {
                                                echo highlightSearchTerm(htmlspecialchars($hotel['kota']), $hotel_search);
                                            } else {
                                                echo htmlspecialchars($hotel['kota']);
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo number_format($hotel['bookings']); ?></td>
                                        <td><strong><?php echo round($hotel['avg_alos'], 2); ?> hari</strong></td>
                                        <td><?php echo number_format($hotel['total_nights']); ?></td>
                                        <td><?php echo formatRupiah(convertMtoRp($hotel['revenue'] ?? 0)); ?></td>
                                        <td><?php echo formatRupiah(convertMtoRp($hotel['rev_per_night'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (!empty($hotel_search) && $highlight_count > 0): ?>
                                    <tr class="highlight-summary">
                                        <td colspan="7" style="text-align: center; padding: 10px; background-color: #E8F5E9; font-weight: bold;">
                                            <i class="material-icons" style="vertical-align: middle; color: #4CAF50;">check_circle</i>
                                            <?php echo $highlight_count; ?> hotel ditemukan dengan kata kunci "<?php echo htmlspecialchars($hotel_search); ?>"
                                        </td>
                                    </tr>
                                <?php endif; ?>

                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <i class="material-icons">hotel</i>
                                        <h3>Tidak ada data ALOS untuk filter yang dipilih</h3>
                                        <p>Ubah filter atau pilih periode lain untuk melihat data</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ALOS by Room Type -->
            <div class="dss-section fade-in">
                <h2><i class="material-icons">category</i> Analisis ALOS per Tipe Kamar</h2>

                <div class="performance-card fade-in">
                    <table class="performance-table">
                        <thead>
                            <tr>
                                <th>Tipe Kamar</th>
                                <th>Bookings</th>
                                <th>Avg ALOS</th>
                                <th>Total Malam</th>
                                <th>Revenue</th>
                                <th>Avg Revenue</th>
                                <th>Rev/Malam</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($alos_by_room_type_result && $alos_by_room_type_result->num_rows > 0): ?>
                                <?php while ($room_type = $alos_by_room_type_result->fetch_assoc()): ?>
                                    <tr class="fade-in">
                                        <td><strong><?php echo htmlspecialchars($room_type['nama_tipe']); ?></strong></td>
                                        <td><?php echo number_format($room_type['bookings']); ?></td>
                                        <td><strong><?php echo round($room_type['avg_alos'], 2); ?> hari</strong></td>
                                        <td><?php echo number_format($room_type['total_nights']); ?></td>
                                        <td><?php echo formatRupiah(convertMtoRp($room_type['revenue'] ?? 0)); ?></td>
                                        <td><?php echo formatRupiah(convertMtoRp($room_type['avg_revenue_per_booking'] ?? 0)); ?></td>
                                        <td><?php echo formatRupiah(convertMtoRp($room_type['rev_per_night'] ?? 0)); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="empty-state">
                                        <i class="material-icons">category</i>
                                        <h3>Tidak ada data ALOS untuk filter yang dipilih</h3>
                                        <p>Ubah filter atau pilih periode lain untuk melihat data</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ALOS vs Revenue Correlation -->
            <div class="dss-section fade-in">
                <h2><i class="material-icons">scatter_plot</i> Korelasi ALOS vs Revenue</h2>

                <div class="chart-container">
                    <canvas id="alosRevenueCorrelationChart"></canvas>
                </div>
            </div>

            <!-- ALOS Segments Detailed Analysis -->
            <div class="dss-section fade-in">
                <h2><i class="material-icons">analytics</i> Kinerja Segmen ALOS</h2>

                <div class="performance-card fade-in">
                    <table class="performance-table">
                        <thead>
                            <tr>
                                <th>Segmen ALOS</th>
                                <th>Bookings</th>
                                <th>Avg ALOS</th>
                                <th>Total Revenue</th>
                                <th>Avg Revenue</th>
                                <th>% dari Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($alos_segments_data['segments'])): ?>
                                <?php
                                $total_bookings = array_sum($alos_segments_data['bookings']);
                                $total_revenue = array_sum($alos_segments_data['total_revenue']);
                                ?>
                                <?php for ($i = 0; $i < count($alos_segments_data['segments']); $i++): ?>
                                    <tr class="fade-in">
                                        <td><strong><?php echo htmlspecialchars($alos_segments_data['segments'][$i]); ?></strong></td>
                                        <td><?php echo number_format($alos_segments_data['bookings'][$i]); ?></td>
                                        <td><?php echo $alos_segments_data['avg_alos'][$i]; ?> hari</td>
                                        <td><?php echo formatRupiah(convertMtoRp($alos_segments_data['total_revenue'][$i])); ?></td>
                                        <td><?php echo formatRupiah(convertMtoRp($alos_segments_data['avg_revenue'][$i])); ?></td>
                                        <td><strong><?php echo $total_bookings > 0 ? round(($alos_segments_data['bookings'][$i] / $total_bookings) * 100, 1) : 0; ?>%</strong></td>
                                    </tr>
                                <?php endfor; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="empty-state">
                                        <i class="material-icons">analytics</i>
                                        <h3>Tidak ada data segmen ALOS tersedia</h3>
                                        <p>Ubah filter atau pilih periode lain untuk melihat data</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
    </main>

    <script>
        // Dropdown functionality
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

        // Sidebar toggle logic
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        const mainContent = document.getElementById('main-content');

        toggleBtn.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('active');
            } else {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                localStorage.setItem('sidebarState', sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded');
            }
        });

        // Filter form submission with animation
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            e.preventDefault();

            // Add loading animation to sections
            const sections = document.querySelectorAll('.dss-section');
            sections.forEach(section => {
                section.classList.remove('fade-in');
                section.style.opacity = '0.5';
                section.style.transition = 'opacity 0.3s ease';
            });

            // Show loading state
            const submitButton = document.getElementById('applyFilter');
            const originalText = submitButton.innerHTML;
            submitButton.innerHTML = '<i class="material-icons">hourglass_empty</i> Memuat...';
            submitButton.disabled = true;

            // Submit the form after a short delay to show the loading state
            setTimeout(() => {
                this.submit();
            }, 500);
        });

        // Fungsi untuk search hotel di tabel
        function searchHotelTable() {
            const searchInput = document.getElementById('hotelTableSearch');
            const searchTerm = searchInput.value.toLowerCase().trim();
            const tbody = document.getElementById('hotelTableBody');
            const rows = Array.from(tbody.querySelectorAll('.hotel-row'));

            let matchedRow = null;

            // Reset semua row
            rows.forEach(row => {
                row.classList.remove('highlighted');
                row.style.display = '';
            });

            // Kalau search kosong → tampilkan semua & reset counter
            if (searchTerm === '') {
                document.getElementById('visibleHotels').textContent = rows.length;
                document.getElementById('totalHotels').textContent = rows.length;
                return;
            }

            // Cari FIRST MATCH saja (hotel / kota)
            for (let row of rows) {
                const hotelName = row.dataset.hotelName || '';
                const city = row.dataset.city || '';

                if (hotelName.includes(searchTerm) || city.includes(searchTerm)) {
                    matchedRow = row;
                    break;
                }
            }

            // Sembunyikan semua row
            rows.forEach(row => row.style.display = 'none');

            if (matchedRow) {
                // Tampilkan & highlight hanya 1 row
                matchedRow.style.display = '';
                matchedRow.classList.add('highlighted');

                // Pindahkan ke paling atas
                tbody.prepend(matchedRow);

                // Update counter
                document.getElementById('visibleHotels').textContent = 1;
                document.getElementById('totalHotels').textContent = rows.length;

                // Scroll ke row
                matchedRow.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });

                // Hapus pesan no result kalau ada
                const noResult = tbody.querySelector('.no-results-row');
                if (noResult) noResult.remove();

            } else {
                // Kalau tidak ketemu
                document.getElementById('visibleHotels').textContent = 0;
                document.getElementById('totalHotels').textContent = rows.length;

                if (!tbody.querySelector('.no-results-row')) {
                    const noResultsRow = document.createElement('tr');
                    noResultsRow.className = 'no-results-row';
                    noResultsRow.innerHTML = `
                <td colspan="7" class="empty-state">
                    <i class="material-icons">search_off</i>
                    <h3>Hotel tidak ditemukan</h3>
                    <p>Coba kata kunci lain</p>
                </td>
            `;
                    tbody.appendChild(noResultsRow);
                }
            }
        }

        // Fungsi format Rupiah di JavaScript
        function formatRupiahJS(amount) {
            if (!amount || amount == 0) return 'Rp 0';

            amount = parseFloat(amount);

            if (amount >= 1000000000) {
                return 'Rp ' + (amount / 1000000000).toFixed(2).replace('.', ',') + ' Miliar';
            } else if (amount >= 1000000) {
                return 'Rp ' + (amount / 1000000).toFixed(2).replace('.', ',') + ' Juta';
            } else if (amount >= 1000) {
                return 'Rp ' + (amount / 1000).toFixed(1).replace('.', ',') + ' Ribu';
            } else {
                return 'Rp ' + amount.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }
        }

        // Initialize ALOS Analysis Charts
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize hotel counter
            const rows = document.querySelectorAll('#hotelTableBody .hotel-row');
            const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none');
            document.getElementById('visibleHotels').textContent = visibleRows.length;
            document.getElementById('totalHotels').textContent = rows.length;

            // Add event listener for search input
            const searchInput = document.getElementById('hotelTableSearch');
            if (searchInput) {
                searchInput.addEventListener('input', searchHotelTable);
            }

            // Scroll to highlighted rows if search term exists
            const urlParams = new URLSearchParams(window.location.search);
            const hotelSearchParam = urlParams.get('hotel_search');
            if (hotelSearchParam) {
                setTimeout(() => {
                    const firstHighlighted = document.querySelector('.hotel-row.highlighted');
                    if (firstHighlighted) {
                        firstHighlighted.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    }
                }, 1000);
            }

            // Monthly ALOS Chart
            const monthlyAlosCtx = document.getElementById('monthlyAlosChart').getContext('2d');
            new Chart(monthlyAlosCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($monthly_alos_data['months']); ?>,
                    datasets: [{
                        label: 'Rata-rata ALOS (Hari)',
                        data: <?php echo json_encode($monthly_alos_data['avg_alos']); ?>,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        borderWidth: 3,
                        tension: 0.3,
                        fill: true,
                        pointBackgroundColor: '#3498db',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Rata-rata Lama Menginap Bulanan',
                            font: {
                                size: 16,
                                weight: 'bold'
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleFont: {
                                size: 14
                            },
                            bodyFont: {
                                size: 13
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'ALOS (Hari)',
                                font: {
                                    weight: 'bold'
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        }
                    },
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    }
                }
            });

            // ALOS vs Revenue Chart
            const alosRevenueCtx = document.getElementById('alosRevenueChart').getContext('2d');

            // Convert revenue to millions for chart
            const revenueInMillions = <?php echo json_encode($monthly_alos_data['revenue']); ?>.map(value => value / 1000000);

            new Chart(alosRevenueCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($monthly_alos_data['months']); ?>,
                    datasets: [{
                        label: 'Avg ALOS (Hari)',
                        data: <?php echo json_encode($monthly_alos_data['avg_alos']); ?>,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.6)',
                        type: 'line',
                        yAxisID: 'y',
                        order: 2,
                        borderWidth: 3,
                        pointBackgroundColor: '#3498db',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    }, {
                        label: 'Revenue (Juta Rp)',
                        data: revenueInMillions,
                        backgroundColor: 'rgba(46, 204, 113, 0.7)',
                        borderColor: '#27ae60',
                        borderWidth: 1,
                        yAxisID: 'y1',
                        order: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Perbandingan ALOS vs Revenue',
                            font: {
                                size: 16,
                                weight: 'bold'
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label.includes('Revenue')) {
                                        return label + ': ' + formatRupiahJS(context.raw * 1000000);
                                    }
                                    return label + ': ' + context.raw + ' hari';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'ALOS (Hari)',
                                font: {
                                    weight: 'bold'
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Revenue (Juta Rupiah)',
                                font: {
                                    weight: 'bold'
                                }
                            },
                            ticks: {
                                callback: function(value) {
                                    return formatRupiahJS(value * 1000000);
                                }
                            },
                            grid: {
                                drawOnChartArea: false,
                            },
                        },
                        x: {
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        }
                    }
                }
            });

            // ALOS Distribution Chart
            const alosDistributionCtx = document.getElementById('alosDistributionChart').getContext('2d');
            new Chart(alosDistributionCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($alos_distribution_data['stay_durations']); ?>,
                    datasets: [{
                        label: 'Jumlah Booking',
                        data: <?php echo json_encode($alos_distribution_data['frequencies']); ?>,
                        backgroundColor: 'rgba(155, 89, 182, 0.7)',
                        borderColor: '#8e44ad',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Distribusi Durasi Menginap',
                            font: {
                                size: 16,
                                weight: 'bold'
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Jumlah Booking',
                                font: {
                                    weight: 'bold'
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Durasi Menginap (Hari)',
                                font: {
                                    weight: 'bold'
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        }
                    }
                }
            });

            // ALOS Segments Chart
            const alosSegmentsCtx = document.getElementById('alosSegmentsChart').getContext('2d');
            new Chart(alosSegmentsCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($alos_segments_data['segments']); ?>,
                    datasets: [{
                        data: <?php echo json_encode($alos_segments_data['bookings']); ?>,
                        backgroundColor: [
                            'rgba(231, 76, 60, 0.8)',
                            'rgba(243, 156, 18, 0.8)',
                            'rgba(241, 196, 15, 0.8)',
                            'rgba(46, 204, 113, 0.8)',
                            'rgba(52, 152, 219, 0.8)'
                        ],
                        borderColor: [
                            '#c0392b',
                            '#d35400',
                            '#f39c12',
                            '#27ae60',
                            '#2980b9'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Distribusi Booking berdasarkan Segmen ALOS',
                            font: {
                                size: 16,
                                weight: 'bold'
                            }
                        },
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '50%'
                }
            });

            // ALOS vs Revenue Correlation Chart
            const alosRevenueCorrelationCtx = document.getElementById('alosRevenueCorrelationChart').getContext('2d');

            // Create data array manually dengan escaping
            const scatterData = [];
            <?php
            foreach ($alos_revenue_data['alos_values'] as $index => $alos) {
                $revenue = $alos_revenue_data['revenue_values'][$index] ?? 0;
                $hotel = addslashes($alos_revenue_data['hotels'][$index] ?? 'Unknown');
                $roomType = addslashes($alos_revenue_data['room_types'][$index] ?? 'Unknown');
                echo "scatterData.push({x: " . (int)$alos . ", y: " . (float)$revenue . ", hotel: '" . $hotel . "', roomType: '" . $roomType . "'});\n";
            }
            ?>

            new Chart(alosRevenueCorrelationCtx, {
                type: 'scatter',
                data: {
                    datasets: [{
                        label: 'ALOS vs Revenue',
                        data: scatterData,
                        backgroundColor: 'rgba(52, 152, 219, 0.6)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 1,
                        pointRadius: 6,
                        pointHoverRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Korelasi ALOS vs Revenue',
                            font: {
                                size: 16,
                                weight: 'bold'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const point = context.raw;
                                    return `ALOS: ${point.x} hari, Revenue: ${formatRupiahJS(point.y)}`;
                                },
                                afterLabel: function(context) {
                                    const point = context.raw;
                                    return `${point.hotel} - ${point.roomType}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'ALOS (Hari)',
                                font: {
                                    weight: 'bold'
                                }
                            },
                            min: 0,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Revenue (Rupiah)',
                                font: {
                                    weight: 'bold'
                                }
                            },
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return formatRupiahJS(value);
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)'
                            }
                        }
                    }
                }
            });

            // Handle image loading errors
            const profilePhoto = document.getElementById('profilePhoto');
            if (profilePhoto) {
                profilePhoto.addEventListener('error', function() {
                    this.src = '../images/default.jpg';
                });
            }

            // Sidebar dropdown menus
            document.querySelectorAll('.booking-toggle').forEach(toggle => {
                toggle.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    const parentMenu = toggle.closest('.user-menu');
                    const dropdownId = toggle.getAttribute('data-target');
                    const dropdown = document.getElementById(dropdownId);
                    const isExpanded = parentMenu.getAttribute('aria-expanded') === 'true';

                    document.querySelectorAll('.user-menu').forEach(menu => {
                        menu.setAttribute('aria-expanded', 'false');
                    });
                    document.querySelectorAll('.booking-submenu').forEach(sub => {
                        sub.classList.remove('show');
                        sub.classList.add('hidden');
                        sub.setAttribute('aria-hidden', 'true');
                    });

                    if (!isExpanded) {
                        parentMenu.setAttribute('aria-expanded', 'true');
                        dropdown.classList.remove('hidden');
                        dropdown.classList.add('show');
                        dropdown.setAttribute('aria-hidden', 'false');
                    }
                });
            });

            // Close dropdowns when clicking outside
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
        });
    </script>
</body>

</html>