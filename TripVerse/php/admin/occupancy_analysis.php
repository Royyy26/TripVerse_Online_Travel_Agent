<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Akses ditolak! Halaman ini hanya untuk admin.'); window.location='../home.php';</script>";
    exit;
}

require __DIR__ . '/../connect.php';
require_once __DIR__ . '/../_lang.php';

$id_user = $_SESSION['id_user'];

// Ambil Data Admin
$stmt = $conn->prepare("SELECT username, email, first_name, last_name, profile_picture FROM user WHERE id_user = ?");
$stmt->bind_param("s", $id_user);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

$firstName = $admin_data['first_name'] ?? '-';
$lastName = $admin_data['last_name'] ?? '-';
$email = $admin_data['email'] ?? 'unknown@tripverse.com';
$foto = !empty($admin_data['profile_picture']) ?
    '../../uploads/' . basename($admin_data['profile_picture']) :
    '../../images/default.jpg';

// Filter parameters dengan validasi lebih ketat
$filter_period = $_GET['period'] ?? 'monthly'; // daily, weekly, monthly, yearly
$filter_year = $_GET['year'] ?? date('Y');
$filter_month = $_GET['month'] ?? date('m');
$filter_week = $_GET['week'] ?? date('W');
$filter_city = $_GET['city'] ?? 'all';
$filter_hotel = $_GET['hotel'] ?? 'all';
$filter_room = $_GET['room'] ?? 'all'; // NEW: Filter tipe kamar

// Validasi parameters
if (!is_numeric($filter_month) || $filter_month < 1 || $filter_month > 12) {
    $filter_month = date('m');
}
if (!is_numeric($filter_year) || $filter_year < 2020 || $filter_year > date('Y') + 1) {
    $filter_year = date('Y');
}
if (!is_numeric($filter_week) || $filter_week < 1 || $filter_week > 53) {
    $filter_week = date('W');
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

// Get list of cities
$cities_query = "SELECT DISTINCT kota FROM hotel WHERE kota IS NOT NULL AND kota != '' ORDER BY kota";
$cities_result = $conn->query($cities_query);
$available_cities = [];
if ($cities_result) {
    while ($row = $cities_result->fetch_assoc()) {
        $available_cities[] = $row['kota'];
    }
}

// Get list of hotels
$hotels_query = "SELECT hotel_id, nama_hotel FROM hotel WHERE 1=1";
if ($filter_city !== 'all') {
    $hotels_query .= " AND kota = '$filter_city'";
}
$hotels_query .= " ORDER BY nama_hotel";

$hotels_result = $conn->query($hotels_query);
$available_hotels = [];
if ($hotels_result) {
    while ($row = $hotels_result->fetch_assoc()) {
        $available_hotels[$row['hotel_id']] = $row['nama_hotel'];
    }
}

// NEW: Get list of room types
$rooms_query = "SELECT DISTINCT t.tipe_id, t.nama_tipe 
                FROM tipe_kamar t
                LEFT JOIN hotel h ON t.hotel_id = h.hotel_id
                WHERE 1=1";

if ($filter_city !== 'all') {
    $rooms_query .= " AND h.kota = '$filter_city'";
}
if ($filter_hotel !== 'all') {
    $rooms_query .= " AND t.hotel_id = '$filter_hotel'";
}
$rooms_query .= " ORDER BY t.nama_tipe";

$rooms_result = $conn->query($rooms_query);
$available_rooms = [];
if ($rooms_result) {
    while ($row = $rooms_result->fetch_assoc()) {
        $available_rooms[$row['tipe_id']] = $row['nama_tipe'];
    }
}

// Fungsi untuk menghitung tanggal berdasarkan periode
function getDateRange($period, $year, $month, $week)
{
    $date_range = [];

    switch ($period) {
        case 'daily':
            $date_range['start'] = date("$year-$month-01");
            $date_range['end'] = date("$year-$month-t", strtotime("$year-$month-01"));
            break;

        case 'weekly':
            // Hitung tanggal awal dan akhir minggu
            $date = new DateTime();
            $date->setISODate($year, $week);
            $date_range['start'] = $date->format('Y-m-d');
            $date->modify('+6 days');
            $date_range['end'] = $date->format('Y-m-d');
            break;

        case 'monthly':
            $date_range['start'] = "$year-$month-01";
            $date_range['end'] = date("Y-m-t", strtotime("$year-$month-01"));
            break;

        case 'yearly':
            $date_range['start'] = "$year-01-01";
            $date_range['end'] = "$year-12-31";
            break;

        default:
            $date_range['start'] = "$year-$month-01";
            $date_range['end'] = date("Y-m-t", strtotime("$year-$month-01"));
    }

    return $date_range;
}

// 1. Get Overall Occupancy Statistics (REVISED)
function get_occupancy_statistics($conn, $period, $year, $month, $week, $city = 'all', $hotel = 'all', $room = 'all')
{
    $date_range = getDateRange($period, $year, $month, $week);
    $start_date = $date_range['start'];
    $end_date = $date_range['end'];

    // Query yang diperbaiki untuk menghindari duplikasi data
    $query = "SELECT 
        -- Capacity Metrics dari jadwal_hotel
        COALESCE(SUM(jh.stok_total), 0) as total_capacity,
        COALESCE(SUM(jh.terbooking), 0) as total_booked,
        COALESCE(SUM(jh.stok_total - jh.terbooking), 0) as total_available,
        
        -- Hotel Counts
        COUNT(DISTINCT h.hotel_id) as total_hotels,
        COUNT(DISTINCT jh.tipe_id) as total_room_types,
        
        -- Booking Performance dari booking_hotel
        COUNT(DISTINCT b.booking_id) as total_bookings,
        COUNT(DISTINCT CASE WHEN b.status = 'Completed' THEN b.booking_id END) as completed_bookings,
        COUNT(DISTINCT CASE WHEN b.status = 'Cancelled' THEN b.booking_id END) as cancelled_bookings,
        
        -- Revenue
        COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue
        
        FROM jadwal_hotel jh
        INNER JOIN hotel h ON jh.hotel_id = h.hotel_id
        LEFT JOIN booking_hotel b ON jh.hotel_id = b.hotel_id 
            AND jh.tipe_id = b.tipe_id
            AND b.tanggal_booking BETWEEN ? AND ?
        WHERE 1=1";

    $params = [$start_date, $end_date];
    $types = "ss";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    if ($hotel !== 'all') {
        $query .= " AND h.hotel_id = ?";
        $params[] = $hotel;
        $types .= "s";
    }

    if ($room !== 'all') {
        $query .= " AND jh.tipe_id = ?";
        $params[] = $room;
        $types .= "s";
    }

    $stmt = $conn->prepare($query);
    $metrics = [];

    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $metrics = $result->fetch_assoc();
        $stmt->close();
    }

    // Calculate derived metrics
    if ($metrics) {
        $metrics['occupancy_rate'] = $metrics['total_capacity'] > 0 ?
            round(($metrics['total_booked'] / $metrics['total_capacity']) * 100, 2) : 0;

        $metrics['success_rate'] = $metrics['total_bookings'] > 0 ?
            round(($metrics['completed_bookings'] / $metrics['total_bookings']) * 100, 2) : 0;

        $metrics['cancellation_rate'] = $metrics['total_bookings'] > 0 ?
            round(($metrics['cancelled_bookings'] / $metrics['total_bookings']) * 100, 2) : 0;

        $metrics['revpar'] = $metrics['total_capacity'] > 0 ?
            round($metrics['total_revenue'] / $metrics['total_capacity'], 2) : 0;

        $metrics['adr'] = $metrics['completed_bookings'] > 0 ?
            round($metrics['total_revenue'] / $metrics['completed_bookings'], 2) : 0;
    }

    return $metrics ?: [];
}

// 2. Get Hotel-wise Occupancy (REVISED)
function get_hotel_occupancy($conn, $period, $year, $month, $week, $city = 'all', $hotel = 'all', $room = 'all')
{
    $date_range = getDateRange($period, $year, $month, $week);
    $start_date = $date_range['start'];
    $end_date = $date_range['end'];

    $query = "SELECT 
        h.hotel_id,
        h.nama_hotel,
        h.kota,
        h.alamat,
        COALESCE(SUM(jh.stok_total), 0) as total_capacity,
        COALESCE(SUM(jh.terbooking), 0) as total_booked,
        
        -- Booking Performance
        COUNT(DISTINCT b.booking_id) as total_bookings,
        COUNT(DISTINCT CASE WHEN b.status = 'Completed' THEN b.booking_id END) as completed_bookings,
        
        -- Revenue
        COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue
        
        FROM hotel h
        INNER JOIN jadwal_hotel jh ON h.hotel_id = jh.hotel_id
        LEFT JOIN booking_hotel b ON h.hotel_id = b.hotel_id 
            AND jh.tipe_id = b.tipe_id
            AND b.tanggal_booking BETWEEN ? AND ?
        WHERE 1=1";

    $params = [$start_date, $end_date];
    $types = "ss";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    if ($hotel !== 'all') {
        $query .= " AND h.hotel_id = ?";
        $params[] = $hotel;
        $types .= "s";
    }

    if ($room !== 'all') {
        $query .= " AND jh.tipe_id = ?";
        $params[] = $room;
        $types .= "s";
    }

    $query .= " GROUP BY h.hotel_id, h.nama_hotel, h.kota, h.alamat
                HAVING total_capacity > 0
                ORDER BY total_revenue DESC";

    $stmt = $conn->prepare($query);
    $data = [];

    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $row['occupancy_rate'] = $row['total_capacity'] > 0 ?
                round(($row['total_booked'] / $row['total_capacity']) * 100, 2) : 0;

            $row['success_rate'] = $row['total_bookings'] > 0 ?
                round(($row['completed_bookings'] / $row['total_bookings']) * 100, 2) : 0;

            $row['revpar'] = $row['total_capacity'] > 0 ?
                round($row['total_revenue'] / $row['total_capacity'], 2) : 0;

            $row['adr'] = $row['completed_bookings'] > 0 ?
                round($row['total_revenue'] / $row['completed_bookings'], 2) : 0;

            $data[] = $row;
        }
        $stmt->close();
    }

    return $data;
}

// 3. Get Daily Occupancy Trends (REVISED)
function get_daily_occupancy_trends($conn, $period, $year, $month, $week, $city = 'all', $hotel = 'all', $room = 'all')
{
    $date_range = getDateRange($period, $year, $month, $week);
    $start_date = $date_range['start'];
    $end_date = $date_range['end'];

    $query = "SELECT 
        DATE(b.tanggal_booking) as booking_date,
        DAY(b.tanggal_booking) as day_of_month,
        DAYNAME(b.tanggal_booking) as day_name,
        COUNT(DISTINCT b.booking_id) as daily_bookings,
        COUNT(DISTINCT CASE WHEN b.status = 'Completed' THEN b.booking_id END) as completed_bookings,
        COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as daily_revenue
        
        FROM booking_hotel b
        LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
        WHERE b.tanggal_booking BETWEEN ? AND ?";

    $params = [$start_date, $end_date];
    $types = "ss";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    if ($hotel !== 'all') {
        $query .= " AND h.hotel_id = ?";
        $params[] = $hotel;
        $types .= "s";
    }

    if ($room !== 'all') {
        $query .= " AND b.tipe_id = ?";
        $params[] = $room;
        $types .= "s";
    }

    $query .= " GROUP BY DATE(b.tanggal_booking), DAY(b.tanggal_booking), DAYNAME(b.tanggal_booking)
                ORDER BY booking_date";

    $stmt = $conn->prepare($query);
    $daily_data = [];

    // Initialize all days in the date range
    $current = strtotime($start_date);
    $end = strtotime($end_date);

    while ($current <= $end) {
        $day = date('j', $current);
        $daily_data[$day] = [
            'day_of_month' => $day,
            'day_name' => date('l', $current),
            'booking_date' => date('Y-m-d', $current),
            'daily_bookings' => 0,
            'completed_bookings' => 0,
            'daily_revenue' => 0
        ];
        $current = strtotime('+1 day', $current);
    }

    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        if ($stmt->execute()) {
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $day = $row['day_of_month'];
                if (isset($daily_data[$day])) {
                    $daily_data[$day] = $row;
                }
            }
        }
        $stmt->close();
    }

    return array_values($daily_data);
}

// 4. Get Period Trends for Chart (REVISED)
function get_period_trends($conn, $period, $year, $city = 'all', $hotel = 'all', $room = 'all')
{
    $data = [];

    switch ($period) {
        case 'yearly':
            // Yearly trends - last 5 years
            $query = "SELECT 
                YEAR(b.tanggal_booking) as period,
                COUNT(DISTINCT b.booking_id) as period_bookings,
                COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as period_revenue
                
                FROM booking_hotel b
                LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
                WHERE YEAR(b.tanggal_booking) BETWEEN ? AND ?";

            $start_year = $year - 4;
            $params = [$start_year, $year];
            $types = "ii";

            $group_by = "YEAR(b.tanggal_booking)";
            $order_by = "period";
            break;

        case 'monthly':
            // Monthly trends for selected year
            $query = "SELECT 
                MONTH(b.tanggal_booking) as period,
                COUNT(DISTINCT b.booking_id) as period_bookings,
                COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as period_revenue
                
                FROM booking_hotel b
                LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
                WHERE YEAR(b.tanggal_booking) = ?";

            $params = [$year];
            $types = "i";

            $group_by = "MONTH(b.tanggal_booking)";
            $order_by = "period";
            break;

        case 'weekly':
            // Weekly trends for selected year
            $query = "SELECT 
                WEEK(b.tanggal_booking, 1) as period,
                COUNT(DISTINCT b.booking_id) as period_bookings,
                COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as period_revenue
                
                FROM booking_hotel b
                LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
                WHERE YEAR(b.tanggal_booking) = ?";

            $params = [$year];
            $types = "i";

            $group_by = "WEEK(b.tanggal_booking, 1)";
            $order_by = "period";
            break;

        default:
            // Default to monthly
            $query = "SELECT 
                MONTH(b.tanggal_booking) as period,
                COUNT(DISTINCT b.booking_id) as period_bookings,
                COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as period_revenue
                
                FROM booking_hotel b
                LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
                WHERE YEAR(b.tanggal_booking) = ?";

            $params = [$year];
            $types = "i";

            $group_by = "MONTH(b.tanggal_booking)";
            $order_by = "period";
    }

    // Add filters
    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    if ($hotel !== 'all') {
        $query .= " AND h.hotel_id = ?";
        $params[] = $hotel;
        $types .= "s";
    }

    if ($room !== 'all') {
        $query .= " AND b.tipe_id = ?";
        $params[] = $room;
        $types .= "s";
    }

    $query .= " GROUP BY $group_by ORDER BY $order_by";

    $stmt = $conn->prepare($query);

    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
    }

    // Fill missing periods
    $full_data = [];

    if ($period === 'yearly') {
        for ($y = $year - 4; $y <= $year; $y++) {
            $found = false;
            foreach ($data as $item) {
                if ($item['period'] == $y) {
                    $full_data[] = $item;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $full_data[] = [
                    'period' => $y,
                    'period_bookings' => 0,
                    'period_revenue' => 0
                ];
            }
        }
    } elseif ($period === 'monthly') {
        for ($m = 1; $m <= 12; $m++) {
            $found = false;
            foreach ($data as $item) {
                if ($item['period'] == $m) {
                    $full_data[] = $item;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $full_data[] = [
                    'period' => $m,
                    'period_bookings' => 0,
                    'period_revenue' => 0
                ];
            }
        }
    } elseif ($period === 'weekly') {
        for ($w = 1; $w <= 52; $w++) {
            $found = false;
            foreach ($data as $item) {
                if ($item['period'] == $w) {
                    $full_data[] = $item;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $full_data[] = [
                    'period' => $w,
                    'period_bookings' => 0,
                    'period_revenue' => 0
                ];
            }
        }
    }

    return $full_data;
}

// Execute functions with all parameters
$occupancy_stats = get_occupancy_statistics($conn, $filter_period, $filter_year, $filter_month, $filter_week, $filter_city, $filter_hotel, $filter_room);
$hotel_occupancy = get_hotel_occupancy($conn, $filter_period, $filter_year, $filter_month, $filter_week, $filter_city, $filter_hotel, $filter_room);
$daily_trends = get_daily_occupancy_trends($conn, $filter_period, $filter_year, $filter_month, $filter_week, $filter_city, $filter_hotel, $filter_room);
$period_trends = get_period_trends($conn, $filter_period, $filter_year, $filter_city, $filter_hotel, $filter_room);

// Get date range for display
$date_range = getDateRange($filter_period, $filter_year, $filter_month, $filter_week);

// Get system notifications
$query_notif = "SELECT COUNT(*) as notifications FROM booking_hotel WHERE status = 'Pending'";
$result_notif = $conn->query($query_notif);
if ($result_notif) {
    $notificationCount = $result_notif->fetch_assoc()['notifications'] ?? 0;
} else {
    $notificationCount = 0;
}

// Close connection
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

// Function to get Indonesian period name
function getPeriodName($period)
{
    $periods = [
        'monthly' => 'Bulanan',
        'yearly' => 'Tahunan'
    ];
    return $periods[$period] ?? 'Bulanan';
}

// Function to format currency in Rupiah
function formatRupiah($amount)
{
    if ($amount >= 1000000000) {
        return 'Rp ' . number_format($amount / 1000000000, 1, ',', '.') . ' Miliar';
    } elseif ($amount >= 1000000) {
        return 'Rp ' . number_format($amount / 1000000, 1, ',', '.') . ' Juta';
    } elseif ($amount >= 1000) {
        return 'Rp ' . number_format($amount / 1000, 1, ',', '.') . ' Ribu';
    } else {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}

// Function to get week range
function getWeekRange($year, $week)
{
    $date = new DateTime();
    $date->setISODate($year, $week);
    $start = $date->format('d M');
    $date->modify('+6 days');
    $end = $date->format('d M Y');
    return "$start - $end";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Occupancy Statistics | Dashboard Admin TripVerse</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../../css/dashboard.css?v=2.0.0" />
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
            --border-radius: 12px;
            --box-shadow: 0 4px 12px rgba(15, 23, 43, 0.08);
            --box-shadow-hover: 0 8px 24px rgba(15, 23, 43, 0.14);
            --transition: all 0.3s ease;
            --gradient-primary: linear-gradient(135deg, #FEA116, #FF7A3D);
            --gradient-success: linear-gradient(135deg, #1baf7a, #3fcf9c);
            --gradient-warning: linear-gradient(135deg, #eda100, #f4b73a);
            --gradient-danger: linear-gradient(135deg, #e34948, #ef6e6d);
            --gradient-info: linear-gradient(135deg, #2a78d6, #4f92e3);
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
        .stats-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
            margin-bottom: 30px;
            border-left: 4px solid var(--primary-color);
            transition: var(--transition);
        }

        .stats-section:hover {
            box-shadow: var(--box-shadow-hover);
        }

        .stats-section h2 {
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

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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
            background: var(--gradient-primary);
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
            background: var(--gradient-primary);
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

        /* Filter Controls */
        .filter-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            border: 1px solid #dee2e6;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-light);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-select {
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            width: 100%;
            background: white;
            box-sizing: border-box;
            font-family: inherit;
        }

        .filter-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 122, 61, 0.1);
            outline: none;
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-actions button,
        .filter-actions .reset-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            height: fit-content;
        }

        .filter-actions button:hover {
            background: #E8672B;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px #FF7A3D4d;
        }

        .filter-actions .reset-btn {
            background: #6c757d;
        }

        .filter-actions .reset-btn:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
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
            background: rgba(255, 122, 61, 0.05);
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-color);
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
            overflow-x: auto;
        }

        .performance-card:hover {
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
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
        }

        .performance-table td {
            font-size: 14px;
            color: var(--text-color);
            padding: 14px;
            border-bottom: 1px solid #f0f0f0;
        }

        /* Occupancy Indicators */
        .occupancy-indicator {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .occupancy-high {
            background: #e8f5e8;
            color: var(--success-color);
            border: 1px solid #c8e6c9;
        }

        .occupancy-medium {
            background: #fff3e0;
            color: var(--warning-color);
            border: 1px solid #ffcc80;
        }

        .occupancy-low {
            background: #ffebee;
            color: var(--danger-color);
            border: 1px solid #ffcdd2;
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

        /* Sidebar Dropdown Improvements */
        .sidebar .user-menu {
            position: relative;
        }

        /* Warna untuk ikon KPI Cards */
        .kpi-card:nth-child(1) .kpi-icon {
            background: linear-gradient(135deg, #1baf7a, #3fcf9c) !important;
        }

        .kpi-card:nth-child(2) .kpi-icon {
            background: linear-gradient(135deg, #2a78d6, #42a5f5) !important;
        }

        .kpi-card:nth-child(3) .kpi-icon {
            background: linear-gradient(135deg, #eda100, #ffa726) !important;
        }

        .kpi-card:nth-child(4) .kpi-icon {
            background: linear-gradient(135deg, #9c27b0, #ab47bc) !important;
        }

        /* Warna untuk ikon filter */
        .filter-group label .material-icons {
            color: #FF7A3D;
            background: rgba(255, 122, 61, 0.1);
            padding: 6px;
            border-radius: 6px;
            margin-right: 8px;
        }

        /* Warna untuk header sections */
        .stats-section h2 .material-icons {
            background: linear-gradient(135deg, var(--primary-color), #FF7A3D);
            color: white;
            padding: 8px;
            border-radius: 8px;
            margin-right: 10px;
        }

        /* Performance card icons */
        .performance-card h3 .material-icons {
            background: rgba(0, 0, 0, 0.1);
            color: var(--primary-color);
            padding: 6px;
            border-radius: 6px;
            margin-right: 8px;
        }

        /* Responsive Adjustments */
        @media (max-width: 1200px) {
            .performance-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .kpi-grid {
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

            .stats-section {
                padding: 20px;
                margin-bottom: 20px;
            }

            .period-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            /* Mobile sidebar adjustments */
            .sidebar {
                position: fixed;
                left: -280px;
                transition: left 0.3s ease;
                z-index: 1000;
                height: 100vh;
                overflow-y: auto;
            }

            .sidebar.active {
                left: 0;
            }

            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }

            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 999;
            }

            .sidebar-overlay.active {
                display: block;
            }
        }

        /* New styles for additional filter */
        .filter-controls {
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        }

        /* Week selector for weekly period */
        .week-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .week-navigation {
            display: flex;
            gap: 5px;
        }

        .week-nav-btn {
            background: #f0f0f0;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .week-nav-btn:hover {
            background: #e0e0e0;
        }

        /* Period selector styling */
        .period-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .period-tab {
            padding: 10px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .period-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .period-tab:hover:not(.active) {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        /* Dynamic filter visibility */
        .dynamic-filter {
            transition: all 0.3s ease;
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            display: none;
        }

        .loading-spinner {
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary-color);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Responsive adjustments */
        @media (max-width: 992px) {
            .filter-controls {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .filter-controls {
                grid-template-columns: 1fr;
            }

            .period-tabs {
                justify-content: center;
            }

            .period-tab {
                flex: 1;
                min-width: 120px;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .period-tabs {
                flex-direction: column;
            }

            .period-tab {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

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
                    <img src="<?php echo htmlspecialchars($foto); ?>"
                        alt="Profile Photo"
                        class="profile-photo"
                        id="profilePhoto"
                        onerror="this.src='../../images/default.jpg'">

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

                <div class="booking-submenu" id="analyticsDropdown">
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

                <div class="booking-submenu" id="decisionDropdown">
                    <a href="revenue_optimization.php">
                        <span class="material-icons">attach_money</span>
                        <span><?= te('Statistik Pendapatan') ?></span>
                    </a>
                    <a href="occupancy_analysis.php" class="active">
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
                <div class="notification-bell" id="notificationBell">
                    <span class="material-icons bell-icon">notifications</span>
                    <?php if ($notificationCount > 0): ?>
                        <span class="notification-badge" id="notificationCount"><?= $notificationCount ?></span>
                    <?php endif; ?>
                </div>

                <div class="user-menu">
                    <img src="<?php echo htmlspecialchars($foto); ?>" alt="User Avatar" class="user-avatar" />
                </div>
            </div>
        </header>

        <div class="stats-section fade-in">
            <h2><i class="material-icons">analytics</i> <?= te('Dasbor Statistik Okupansi') ?></h2>
            <p class="period-info">
                Visualisasi data okupansi untuk analisis performa pemesanan hotel TripVerse
            </p>

            <!-- Period Tabs -->
            <div class="period-tabs">
                <div class="period-tab <?= $filter_period == 'monthly' ? 'active' : '' ?>" data-period="monthly">
                    <i class="material-icons">calendar_view_month</i>
                    Bulanan
                </div>
                <div class="period-tab <?= $filter_period == 'yearly' ? 'active' : '' ?>" data-period="yearly">
                    <i class="material-icons">calendar_today</i>
                    Tahunan
                </div>
            </div>

            <form method="GET" action="occupancy_analysis.php" class="filter-controls" id="filterForm">
                <!-- Hidden period field -->
                <input type="hidden" name="period" id="hiddenPeriod" value="<?= $filter_period ?>">

                <!-- Dynamic filters based on period -->
                <div class="filter-group <?= $filter_period == 'monthly' || $filter_period == 'daily' ? '' : 'dynamic-filter' ?>"
                    style="<?= $filter_period == 'monthly' || $filter_period == 'daily' ? '' : 'display: none;' ?>">
                    <label for="filter_month"><i class="material-icons">calendar_today</i> Bulan</label>
                    <select id="filter_month" name="month" class="filter-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <?php $month_padded = str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                            <option value="<?= $month_padded ?>" <?= $filter_month == $month_padded ? 'selected' : '' ?>>
                                <?= getIndonesianMonth($m) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-group <?= $filter_period == 'weekly' ? '' : 'dynamic-filter' ?>"
                    style="<?= $filter_period == 'weekly' ? '' : 'display: none;' ?>">
                    <label for="filter_week"><i class="material-icons">date_range</i> Minggu ke-</label>
                    <div class="week-selector">
                        <select id="filter_week" name="week" class="filter-select">
                            <?php for ($w = 1; $w <= 52; $w++): ?>
                                <option value="<?= $w ?>" <?= $filter_week == $w ? 'selected' : '' ?>>
                                    Minggu <?= $w ?> (<?= getWeekRange($filter_year, $w) ?>)
                                </option>
                            <?php endfor; ?>
                        </select>
                        <div class="week-navigation">
                            <button type="button" class="week-nav-btn" id="prevWeek">
                                <i class="material-icons">chevron_left</i>
                            </button>
                            <button type="button" class="week-nav-btn" id="nextWeek">
                                <i class="material-icons">chevron_right</i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="filter-group">
                    <label for="filter_year"><i class="material-icons">event</i> Tahun</label>
                    <select id="filter_year" name="year" class="filter-select">
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?= $year ?>" <?= $filter_year == $year ? 'selected' : '' ?>>
                                <?= $year ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_city"><i class="material-icons">location_city</i> Kota</label>
                    <select id="filter_city" name="city" class="filter-select">
                        <option value="all" <?= $filter_city == 'all' ? 'selected' : '' ?>>Semua Kota</option>
                        <?php foreach ($available_cities as $city): ?>
                            <option value="<?= $city ?>" <?= $filter_city == $city ? 'selected' : '' ?>>
                                <?= htmlspecialchars($city) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_hotel"><i class="material-icons">hotel</i> Hotel</label>
                    <select id="filter_hotel" name="hotel" class="filter-select">
                        <option value="all" <?= $filter_hotel == 'all' ? 'selected' : '' ?>>Semua Hotel</option>
                        <?php foreach ($available_hotels as $id => $name): ?>
                            <option value="<?= $id ?>" <?= $filter_hotel == $id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- NEW: Room Type Filter -->
                <div class="filter-group">
                    <label for="filter_room"><i class="material-icons">bed</i> Tipe Kamar</label>
                    <select id="filter_room" name="room" class="filter-select">
                        <option value="all" <?= $filter_room == 'all' ? 'selected' : '' ?>>Semua Tipe Kamar</option>
                        <?php foreach ($available_rooms as $id => $name): ?>
                            <option value="<?= $id ?>" <?= $filter_room == $id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" id="applyFilter"><i class="material-icons">filter_alt</i> Terapkan Filter</button>
                    <a href="occupancy_analysis.php?period=monthly&month=<?= date('m') ?>&year=<?= date('Y') ?>&city=all&hotel=all&room=all" class="reset-btn">
                        <i class="material-icons">refresh</i> Reset
                    </a>
                </div>
            </form>

            <div class="period-info">
                <i class="material-icons">calendar_today</i>
                <span>Periode Analisis: <strong><?= getPeriodName($filter_period) ?></strong></span>
                <span>• Rentang: <strong>
                        <?php
                        if ($filter_period == 'weekly') {
                            echo getWeekRange($filter_year, $filter_week);
                        } else {
                            echo date('d M Y', strtotime($date_range['start'])) . ' - ' . date('d M Y', strtotime($date_range['end']));
                        }
                        ?>
                    </strong></span>
                <?php if ($filter_city !== 'all'): ?>
                    <span>• Kota: <strong><?= htmlspecialchars($filter_city) ?></strong></span>
                <?php endif; ?>
                <?php if ($filter_hotel !== 'all'): ?>
                    <span>• Hotel: <strong><?= htmlspecialchars($available_hotels[$filter_hotel] ?? 'Selected Hotel') ?></strong></span>
                <?php endif; ?>
                <?php if ($filter_room !== 'all'): ?>
                    <span>• Kamar: <strong><?= htmlspecialchars($available_rooms[$filter_room] ?? 'Selected Room') ?></strong></span>
                <?php endif; ?>
            </div>

            <!-- KPI Cards Section -->
            <div class="kpi-grid">
                <div class="kpi-card fade-in">
                    <div class="kpi-icon">
                        <i class="material-icons">hotel</i>
                    </div>
                    <div class="kpi-label"><?= te('Tingkat Okupansi') ?></div>
                    <div class="kpi-value"><?= $occupancy_stats['occupancy_rate'] ?? 0 ?>%</div>
                    <div class="kpi-label">
                        <?= number_format($occupancy_stats['total_booked'] ?? 0) ?> / <?= number_format($occupancy_stats['total_capacity'] ?? 0) ?> Kamar
                    </div>
                </div>

                <div class="kpi-card fade-in">
                    <div class="kpi-icon">
                        <i class="material-icons">check_circle</i>
                    </div>
                    <div class="kpi-label"><?= te('Tingkat Keberhasilan') ?></div>
                    <div class="kpi-value"><?= $occupancy_stats['success_rate'] ?? 0 ?>%</div>
                    <div class="kpi-label">
                        <?= number_format($occupancy_stats['completed_bookings'] ?? 0) ?> / <?= number_format($occupancy_stats['total_bookings'] ?? 0) ?> Bookings
                    </div>
                </div>

                <div class="kpi-card fade-in">
                    <div class="kpi-icon">
                        <i class="material-icons">attach_money</i>
                    </div>
                    <div class="kpi-label"><?= te('Total Pendapatan') ?></div>
                    <div class="kpi-value"><?= formatRupiah($occupancy_stats['total_revenue'] ?? 0) ?></div>
                    <div class="kpi-label">
                        RevPAR: <?= formatRupiah($occupancy_stats['revpar'] ?? 0) ?>
                    </div>
                </div>

                <div class="kpi-card fade-in">
                    <div class="kpi-icon">
                        <i class="material-icons">apartment</i>
                    </div>
                    <div class="kpi-label"><?= te('Hotel & Kamar') ?></div>
                    <div class="kpi-value"><?= number_format($occupancy_stats['total_hotels'] ?? 0) ?></div>
                    <div class="kpi-label">
                        <?= number_format($occupancy_stats['total_room_types'] ?? 0) ?> Tipe Kamar
                    </div>
                </div>
            </div>
        </div>

        <!-- Hotel Performance Section -->
        <div class="stats-section fade-in">
            <h2><i class="material-icons">analytics</i> <?= te('Performa Okupansi Hotel') ?></h2>

            <div class="performance-grid">
                <div class="performance-card fade-in">
                    <h3><i class="material-icons">pie_chart</i> Occupancy Distribution</h3>
                    <div class="chart-container">
                        <canvas id="occupancyDistributionChart"></canvas>
                    </div>
                </div>

                <div class="performance-card fade-in">
                    <h3><i class="material-icons">show_chart</i> <?= getPeriodName($filter_period) ?> Booking Trends</h3>
                    <div class="chart-container">
                        <canvas id="trendsChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Hotel Performance Table -->
            <div class="performance-card fade-in" style="margin-top: 20px;">
                <h3><i class="material-icons">table_chart</i> Hotel Performance Details</h3>
                <table class="performance-table">
                    <thead>
                        <tr>
                            <th>Hotel</th>
                            <th>Kota</th>
                            <th>Kapasitas</th>
                            <th>Occupancy Rate</th>
                            <th>Success Rate</th>
                            <th>Total Revenue</th>
                            <th>RevPAR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($hotel_occupancy)): ?>
                            <?php foreach ($hotel_occupancy as $hotel): ?>
                                <tr class="fade-in">
                                    <td><strong><?= htmlspecialchars($hotel['nama_hotel']) ?></strong></td>
                                    <td><?= htmlspecialchars($hotel['kota']) ?></td>
                                    <td><?= number_format($hotel['total_capacity']) ?></td>
                                    <td>
                                        <?php
                                        $occupancy_rate = $hotel['occupancy_rate'];
                                        if ($occupancy_rate >= 80): ?>
                                            <span class="occupancy-indicator occupancy-high"><?= round($occupancy_rate, 1) ?>%</span>
                                        <?php elseif ($occupancy_rate >= 60): ?>
                                            <span class="occupancy-indicator occupancy-medium"><?= round($occupancy_rate, 1) ?>%</span>
                                        <?php else: ?>
                                            <span class="occupancy-indicator occupancy-low"><?= round($occupancy_rate, 1) ?>%</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= round($hotel['success_rate'], 1) ?>%</td>
                                    <td><strong><?= formatRupiah($hotel['total_revenue']) ?></strong></td>
                                    <td><?= formatRupiah($hotel['revpar']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    <i class="material-icons">hotel</i>
                                    <h3>Tidak ada data okupansi hotel untuk filter yang dipilih</h3>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Period Trends Section -->
        <div class="stats-section fade-in">
            <h2><i class="material-icons">trending_up</i> <?= getPeriodName($filter_period) ?> <?= te('Tren Okupansi') ?></h2>

            <div class="performance-card fade-in">
                <div class="chart-container">
                    <canvas id="periodTrendsChart"></canvas>
                </div>
            </div>
        </div>

    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Charts
            initializeCharts();

            // Initialize sidebar dropdowns based on current page
            initializeSidebarDropdowns();

            // Initialize mobile sidebar functionality
            initializeMobileSidebar();

            // Period Tabs functionality
            document.querySelectorAll('.period-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const period = this.getAttribute('data-period');

                    // Update active tab
                    document.querySelectorAll('.period-tab').forEach(t => {
                        t.classList.remove('active');
                    });
                    this.classList.add('active');

                    // Update hidden period field
                    document.getElementById('hiddenPeriod').value = period;

                    // Show/hide dynamic filters
                    updateFilterVisibility(period);
                });
            });

            // City filter change - update hotels and rooms
            document.getElementById('filter_city').addEventListener('change', function() {
                const city = this.value;
                showLoading();

                // Reset hotel and room filters
                document.getElementById('filter_hotel').innerHTML = '<option value="all">Semua Hotel</option>';
                document.getElementById('filter_room').innerHTML = '<option value="all">Semua Tipe Kamar</option>';

                // Fetch hotels for selected city
                if (city !== 'all') {
                    fetch(`get_hotels.php?city=${encodeURIComponent(city)}`)
                        .then(response => response.json())
                        .then(data => {
                            const hotelSelect = document.getElementById('filter_hotel');
                            data.forEach(hotel => {
                                const option = document.createElement('option');
                                option.value = hotel.hotel_id;
                                option.textContent = hotel.nama_hotel;
                                hotelSelect.appendChild(option);
                            });

                            // Fetch rooms for the first hotel if exists
                            if (data.length > 0) {
                                fetch(`get_rooms.php?hotel=${data[0].hotel_id}`)
                                    .then(response => response.json())
                                    .then(roomData => {
                                        const roomSelect = document.getElementById('filter_room');
                                        roomData.forEach(room => {
                                            const option = document.createElement('option');
                                            option.value = room.tipe_id;
                                            option.textContent = room.nama_tipe;
                                            roomSelect.appendChild(option);
                                        });
                                        hideLoading();
                                    })
                                    .catch(error => {
                                        console.error('Error fetching rooms:', error);
                                        hideLoading();
                                    });
                            } else {
                                hideLoading();
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching hotels:', error);
                            hideLoading();
                        });
                } else {
                    hideLoading();
                }
            });

            // Hotel filter change - update rooms
            document.getElementById('filter_hotel').addEventListener('change', function() {
                const hotel = this.value;
                showLoading();

                // Reset room filter
                document.getElementById('filter_room').innerHTML = '<option value="all">Semua Tipe Kamar</option>';

                if (hotel !== 'all') {
                    fetch(`get_rooms.php?hotel=${hotel}`)
                        .then(response => response.json())
                        .then(data => {
                            const roomSelect = document.getElementById('filter_room');
                            data.forEach(room => {
                                const option = document.createElement('option');
                                option.value = room.tipe_id;
                                option.textContent = room.nama_tipe;
                                roomSelect.appendChild(option);
                            });
                            hideLoading();
                        })
                        .catch(error => {
                            console.error('Error fetching rooms:', error);
                            hideLoading();
                        });
                } else {
                    hideLoading();
                }
            });

            // Week navigation
            document.getElementById('prevWeek').addEventListener('click', function() {
                const weekSelect = document.getElementById('filter_week');
                const currentWeek = parseInt(weekSelect.value);
                if (currentWeek > 1) {
                    weekSelect.value = currentWeek - 1;
                    updateWeekInfo();
                }
            });

            document.getElementById('nextWeek').addEventListener('click', function() {
                const weekSelect = document.getElementById('filter_week');
                const currentWeek = parseInt(weekSelect.value);
                if (currentWeek < 52) {
                    weekSelect.value = currentWeek + 1;
                    updateWeekInfo();
                }
            });

            // Form submission loading state
            document.getElementById('filterForm').addEventListener('submit', function(e) {
                e.preventDefault();
                showLoading();

                // Submit form after a short delay to show loading
                setTimeout(() => {
                    this.submit();
                }, 500);
            });

            // Profile photo upload (same as before)
            const profileUpload = document.getElementById('profileUpload');
            if (profileUpload) {
                profileUpload.addEventListener('change', function(e) {
                    if (this.files && this.files[0]) {
                        uploadProfilePhoto(this.files[0]);
                    }
                });
            }

            // Click on profile overlay to trigger file input
            const profileOverlay = document.querySelector('.profile-overlay');
            if (profileOverlay) {
                profileOverlay.addEventListener('click', function() {
                    document.getElementById('profileUpload').click();
                });
            }

            // Notification bell click
            const notificationBell = document.getElementById('notificationBell');
            if (notificationBell) {
                notificationBell.addEventListener('click', function() {
                    window.location.href = 'pending_bookings.php';
                });
            }
        });

        function updateFilterVisibility(period) {
            const monthFilter = document.querySelector('.filter-group:nth-child(1)');
            const weekFilter = document.querySelector('.filter-group:nth-child(2)');

            if (period === 'weekly') {
                monthFilter.style.display = 'none';
                weekFilter.style.display = 'flex';
            } else if (period === 'monthly' || period === 'daily') {
                monthFilter.style.display = 'flex';
                weekFilter.style.display = 'none';
            } else {
                // yearly - hide both
                monthFilter.style.display = 'none';
                weekFilter.style.display = 'none';
            }
        }

        function updateWeekInfo() {
            const week = document.getElementById('filter_week').value;
            const year = document.getElementById('filter_year').value;

            // This would ideally fetch week range from server
            // For now, we'll just update the form
            document.getElementById('filterForm').requestSubmit();
        }

        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        function initializeSidebarDropdowns() {
            // Set active dropdown based on current page
            const currentPage = window.location.pathname.split('/').pop();

            // Check which dropdown should be open
            if (currentPage.includes('performance_analytics') || currentPage.includes('booking_trends')) {
                toggleDropdownMenu('analyticsDropdown');
            }

            if (currentPage.includes('revenue_optimization') ||
                currentPage.includes('pricing_strategy') ||
                currentPage.includes('occupancy_analysis') ||
                currentPage.includes('alos_analysis')) {
                toggleDropdownMenu('decisionDropdown');
            }

            // Add click handlers for dropdown toggles
            document.querySelectorAll('.booking-toggle').forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('data-target');
                    toggleDropdownMenu(targetId);
                });
            });
        }

        function toggleDropdownMenu(targetId) {
            const target = document.getElementById(targetId);
            const toggle = document.querySelector(`[data-target="${targetId}"]`);
            const icon = toggle.querySelector('.toggle-icon');

            // Toggle current
            target.classList.toggle('show');
            toggle.classList.toggle('active');

            // Update icon
            if (target.classList.contains('show')) {
                icon.textContent = 'expand_less';
            } else {
                icon.textContent = 'expand_more';
            }

            // Close other dropdowns if needed
            document.querySelectorAll('.booking-submenu').forEach(submenu => {
                if (submenu !== target && submenu.classList.contains('show')) {
                    submenu.classList.remove('show');
                    const otherToggle = submenu.previousElementSibling;
                    if (otherToggle) {
                        otherToggle.classList.remove('active');
                        const otherIcon = otherToggle.querySelector('.toggle-icon');
                        if (otherIcon) {
                            otherIcon.textContent = 'expand_more';
                        }
                    }
                }
            });
        }

        function initializeMobileSidebar() {
            const sidebar = document.getElementById('sidebar');
            const toggleButton = document.getElementById('toggleSidebar');
            const overlay = document.getElementById('sidebarOverlay');

            if (window.innerWidth <= 768) {
                // Toggle sidebar
                toggleButton.addEventListener('click', function() {
                    sidebar.classList.add('active');
                    overlay.classList.add('active');
                });

                // Close sidebar when clicking overlay
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                });

                // Close sidebar when clicking links on mobile
                document.querySelectorAll('.sidebar a').forEach(link => {
                    link.addEventListener('click', function() {
                        sidebar.classList.remove('active');
                        overlay.classList.remove('active');
                    });
                });
            }
        }

        function uploadProfilePhoto(file) {
            // Implementation remains the same
        }

        function showNotification(message, type) {
            // Implementation remains the same
        }

        function initializeCharts() {
            // Occupancy Distribution Chart
            const occupancyDistributionData = {
                labels: ['Excellent (>80%)', 'Good (60-80%)', 'Fair (40-60%)', 'Low (<40%)'],
                datasets: [{
                    data: [
                        <?= count(array_filter($hotel_occupancy, fn($h) => $h['occupancy_rate'] >= 80)) ?>,
                        <?= count(array_filter($hotel_occupancy, fn($h) => $h['occupancy_rate'] >= 60 && $h['occupancy_rate'] < 80)) ?>,
                        <?= count(array_filter($hotel_occupancy, fn($h) => $h['occupancy_rate'] >= 40 && $h['occupancy_rate'] < 60)) ?>,
                        <?= count(array_filter($hotel_occupancy, fn($h) => $h['occupancy_rate'] < 40)) ?>
                    ],
                    backgroundColor: ['#1baf7a', '#2a78d6', '#eda100', '#e34948'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            };

            // Daily/Period Trends Chart
            const trendsData = {
                labels: <?= json_encode(array_column($daily_trends, 'day_of_month')) ?>,
                datasets: [{
                    label: 'Daily Bookings',
                    data: <?= json_encode(array_column($daily_trends, 'daily_bookings')) ?>,
                    backgroundColor: '#FF7A3D',
                    borderColor: '#FF7A3D',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            };

            // Period Trends Chart
            const periodTrendsData = {
                labels: <?= json_encode(array_map(function ($period) use ($filter_period) {
                            if ($filter_period === 'monthly') {
                                $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                                return $monthNames[($period['period'] ?? 1) - 1] ?? 'Unknown';
                            } else if ($filter_period === 'yearly') {
                                return $period['period'] ?? 'Unknown';
                            } else {
                                return 'Minggu ' . ($period['period'] ?? 0);
                            }
                        }, $period_trends)) ?>,
                datasets: [{
                    label: 'Bookings',
                    data: <?= json_encode(array_column($period_trends, 'period_bookings')) ?>,
                    backgroundColor: '#1baf7a',
                    borderColor: '#1baf7a',
                    borderWidth: 2,
                    fill: true
                }, {
                    label: 'Revenue (Juta Rp)',
                    data: <?= json_encode(array_map(function ($period) {
                                return ($period['period_revenue'] ?? 0) / 1000000;
                            }, $period_trends)) ?>,
                    backgroundColor: '#eda100',
                    borderColor: '#eda100',
                    borderWidth: 2,
                    fill: false,
                    yAxisID: 'y1'
                }]
            };

            // Initialize all charts
            if (document.getElementById('occupancyDistributionChart')) {
                new Chart(document.getElementById('occupancyDistributionChart'), {
                    type: 'doughnut',
                    data: occupancyDistributionData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            title: {
                                display: true,
                                text: 'Hotel Occupancy Distribution'
                            }
                        }
                    }
                });
            }

            if (document.getElementById('trendsChart')) {
                new Chart(document.getElementById('trendsChart'), {
                    type: 'line',
                    data: trendsData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Bookings'
                                }
                            }
                        },
                        plugins: {
                            title: {
                                display: true,
                                text: '<?= getPeriodName($filter_period) ?> Booking Trends'
                            }
                        }
                    }
                });
            }

            if (document.getElementById('periodTrendsChart')) {
                new Chart(document.getElementById('periodTrendsChart'), {
                    type: 'bar',
                    data: periodTrendsData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Bookings'
                                }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Revenue (Juta Rp)'
                                }
                            }
                        },
                        plugins: {
                            title: {
                                display: true,
                                text: '<?= getPeriodName($filter_period) ?> Trends'
                            }
                        }
                    }
                });
            }
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            initializeMobileSidebar();
        });
    </script>
</body>

</html>