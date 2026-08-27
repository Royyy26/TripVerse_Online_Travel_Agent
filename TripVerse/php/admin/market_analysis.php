<?php
session_start();

// Check if user is logged in and is an admin (role is 'admin')
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

// --- Get Admin Data ---
$query = "SELECT username, email, first_name, last_name, profile_picture FROM user WHERE id_user = ?";
$stmt = $conn->prepare($query);
if ($stmt === false) {
    die("Error preparing query: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("s", $id_user);
if (!$stmt->execute()) {
    die("Error executing query: " . htmlspecialchars($stmt->error));
}
$result = $stmt->get_result();

if ($data = $result->fetch_assoc()) {
    $firstName = htmlspecialchars($data['first_name']);
    $lastName = htmlspecialchars($data['last_name']);
    $email = htmlspecialchars($data['email']);
    $foto = $data['profile_picture'] ? '../../uploads/' . htmlspecialchars($data['profile_picture']) : '../../images/default.jpg';
} else {
    $firstName = $lastName = "-";
    $email = "unknown@tripverse.com";
    $foto = "../../images/default.jpg";
}
$stmt->close();

// --- FILTER LOGIC (REVISI) ---
$current_year  = (int)date('Y');
$current_month = (int)date('m');

// 1) Ambil dari GET jika apply_filter, else dari session, else default
if (isset($_GET['apply_filter']) && $_GET['apply_filter'] == '1') {
    $filter_year  = isset($_GET['year']) ? (int)$_GET['year'] : $current_year;
    $filter_month = isset($_GET['month']) ? (int)$_GET['month'] : $current_month;
    $filter_city  = isset($_GET['city']) ? trim($_GET['city']) : 'all';
    $filter_hotel = isset($_GET['hotel']) ? trim($_GET['hotel']) : 'all';
} elseif (isset($_SESSION['market_analysis_filters'])) {
    $filters = $_SESSION['market_analysis_filters'];
    $filter_year  = isset($filters['year']) ? (int)$filters['year'] : $current_year;
    $filter_month = isset($filters['month']) ? (int)$filters['month'] : $current_month;
    $filter_city  = isset($filters['city']) ? (string)$filters['city'] : 'all';
    $filter_hotel = isset($filters['hotel']) ? (string)$filters['hotel'] : 'all';
} else {
    $filter_year  = $current_year;
    $filter_month = $current_month;
    $filter_city  = 'all';
    $filter_hotel = 'all';
}

// 2) Handle reset
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    $filter_year  = $current_year;
    $filter_month = $current_month;
    $filter_city  = 'all';
    $filter_hotel = 'all';
    unset($_SESSION['market_analysis_filters']);
}

// 3) Validasi year & month
if ($filter_month < 1 || $filter_month > 12) $filter_month = $current_month;
if ($filter_year < 2020 || $filter_year > $current_year + 1) $filter_year = $current_year;

// 4) Available years
$years_query = "SELECT DISTINCT YEAR(tanggal_booking) as year FROM booking_hotel ORDER BY year DESC";
$years_result = $conn->query($years_query);
$available_years = [];
if ($years_result) {
    while ($row = $years_result->fetch_assoc()) {
        $available_years[] = (int)$row['year'];
    }
}
if (empty($available_years)) {
    $available_years = [$current_year];
} elseif (!in_array($current_year, $available_years, true)) {
    $available_years[] = $current_year;
    rsort($available_years);
}

// 5) Available cities (berdasarkan booking tahun terpilih)
$cities_query = "
    SELECT DISTINCT h.kota
    FROM booking_hotel b
    JOIN hotel h ON b.hotel_id = h.hotel_id
    WHERE YEAR(b.tanggal_booking) = ?
    ORDER BY h.kota
";
$stmt = $conn->prepare($cities_query);
if ($stmt) {
    $stmt->bind_param("i", $filter_year);
    if ($stmt->execute()) {
        $cities_result = $stmt->get_result();
        $available_cities = [];
        while ($row = $cities_result->fetch_assoc()) {
            $available_cities[] = htmlspecialchars($row['kota']);
        }
    } else {
        $available_cities = [];
    }
    $stmt->close();
} else {
    $available_cities = [];
}

// 6) Available hotels (DEPENDENT: ikut kota + tahun/bulan)
$hotels_query = "
    SELECT DISTINCT h.hotel_id, h.nama_hotel
    FROM booking_hotel b
    JOIN hotel h ON b.hotel_id = h.hotel_id
    WHERE YEAR(b.tanggal_booking) = ?
    AND MONTH(b.tanggal_booking) = ?
";
$hotel_params = [$filter_year, $filter_month];
$hotel_types  = "ii";

if ($filter_city !== 'all') {
    $hotels_query .= " AND h.kota = ?";
    $hotel_params[] = $filter_city;
    $hotel_types   .= "s";
}

$hotels_query .= " ORDER BY h.nama_hotel";

$available_hotels = [];
$stmt = $conn->prepare($hotels_query);
if ($stmt) {
    $stmt->bind_param($hotel_types, ...$hotel_params);
    if ($stmt->execute()) {
        $hotels_result = $stmt->get_result();
        while ($row = $hotels_result->fetch_assoc()) {
            $available_hotels[(string)$row['hotel_id']] = htmlspecialchars($row['nama_hotel']);
        }
    }
    $stmt->close();
}

// 7) VALIDASI: kalau hotel terpilih tidak ada di available_hotels -> reset ke all
if ($filter_hotel !== 'all' && !array_key_exists((string)$filter_hotel, $available_hotels)) {
    $filter_hotel = 'all';
}

// 8) Simpan ke session (setelah semua validasi)
$_SESSION['market_analysis_filters'] = [
    'year'  => $filter_year,
    'month' => $filter_month,
    'city'  => $filter_city,
    'hotel' => $filter_hotel
];

// --- ANALISIS TREN PEMESANAN FUNCTIONS ---

// 1. Analisis Kinerja Kota (DIPERBAIKI)
function get_city_performance($conn, $year, $month, $filter_city = 'all', $filter_hotel = 'all')
{
    $year  = (int)$year;
    $month = (int)$month;

    $query = "SELECT 
                h.kota,
                COUNT(b.booking_id) as total_bookings,
                COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed_bookings,
                COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancelled_bookings,
                COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue,
                COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0) as avg_booking_value,
                COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN DATEDIFF(b.check_out, b.check_in) ELSE NULL END), 0) as avg_stay_duration,
                COUNT(DISTINCT h.hotel_id) as total_hotels,
                COUNT(DISTINCT b.customer_id) as unique_customers
             FROM hotel h
             LEFT JOIN booking_hotel b 
               ON h.hotel_id = b.hotel_id
              AND YEAR(b.tanggal_booking) = ?
              AND MONTH(b.tanggal_booking) = ?
              AND b.status IN ('Completed', 'Cancelled', 'Confirmed')
             WHERE 1=1";

    $params = [$year, $month];
    $types  = "ii";

    if ($filter_city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $filter_city;
        $types .= "s";
    }

    if ($filter_hotel !== 'all') {
        $query .= " AND h.hotel_id = ?";
        $params[] = $filter_hotel;
        $types .= "i";
    }

    $query .= " GROUP BY h.kota
                ORDER BY total_revenue DESC, total_bookings DESC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Error preparing city performance query: " . $conn->error);
        return [];
    }

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        error_log("Error executing city performance query: " . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $row['kota'] = htmlspecialchars($row['kota']);
        $data[] = $row;
    }
    $stmt->close();

    return $data;
}

$city_performance = get_city_performance($conn, $filter_year, $filter_month, $filter_city, $filter_hotel);

// 2. Analisis Kinerja Hotel (REVISI - lebih konsisten untuk filter + occupancy)
function get_hotel_performance($conn, $year, $month, $city = 'all', $hotel = 'all')
{
    $year  = (int)$year;
    $month = (int)$month;

    // days in selected month (biar occupancy bener)
    $days_in_period = cal_days_in_month(CAL_GREGORIAN, $month, $year);

    $query = "
        SELECT 
            h.hotel_id,
            h.nama_hotel,
            h.kota,
            h.alamat,
            h.harga_dasar,

            COUNT(b.booking_id) AS total_bookings,
            SUM(CASE WHEN b.status = 'Completed' THEN 1 ELSE 0 END) AS completed_bookings,
            SUM(CASE WHEN b.status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings,

            COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) AS total_revenue,
            COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0) AS avg_booking_value,
            COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN DATEDIFF(b.check_out, b.check_in) ELSE NULL END), 0) AS avg_stay_duration,

            COUNT(DISTINCT b.customer_id) AS unique_customers,

            (SELECT COALESCE(SUM(j.stok_total), 0)
             FROM jadwal_hotel j
             WHERE j.hotel_id = h.hotel_id
            ) AS total_capacity

        FROM hotel h
        LEFT JOIN booking_hotel b
            ON h.hotel_id = b.hotel_id
            AND YEAR(b.tanggal_booking) = ?
            AND MONTH(b.tanggal_booking) = ?
            AND b.status IN ('Completed', 'Cancelled', 'Confirmed')

        WHERE 1=1
    ";

    $params = [$year, $month];
    $types  = "ii";

    // Filter kota (opsional)
    if ($city !== 'all' && $city !== '' && $city !== null) {
        $query .= " AND h.kota = ? ";
        $params[] = $city;
        $types .= "s";
    }

    // Filter hotel (opsional)
    if ($hotel !== 'all' && $hotel !== '' && $hotel !== null) {
        $query .= " AND h.hotel_id = ? ";
        $params[] = (int)$hotel;
        $types .= "i";
    }

    $query .= "
        GROUP BY h.hotel_id, h.nama_hotel, h.kota, h.alamat, h.harga_dasar
        ORDER BY total_revenue DESC, total_bookings DESC
        LIMIT 50
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed (hotel performance): " . $conn->error);
        return [];
    }

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        error_log("Execute failed (hotel performance): " . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $data = [];

    while ($row = $result->fetch_assoc()) {
        // Sanitize data
        $row['nama_hotel'] = htmlspecialchars($row['nama_hotel']);
        $row['kota'] = htmlspecialchars($row['kota']);
        $row['alamat'] = htmlspecialchars($row['alamat']);

        $completed = (int)($row['completed_bookings'] ?? 0);
        $total     = (int)($row['total_bookings'] ?? 0);
        $cancelled = (int)($row['cancelled_bookings'] ?? 0);

        $avg_stay = (float)($row['avg_stay_duration'] ?? 0);
        $room_nights_sold = $completed * max($avg_stay, 1);

        $capacity = (int)($row['total_capacity'] ?? 0);
        $room_nights_available = $capacity * $days_in_period;

        $row['occupancy_rate'] = $room_nights_available > 0
            ? round(($room_nights_sold / $room_nights_available) * 100, 2)
            : 0;

        $row['success_rate'] = $total > 0
            ? round(($completed / $total) * 100, 2)
            : 0;

        $row['cancellation_rate'] = $total > 0
            ? round(($cancelled / $total) * 100, 2)
            : 0;

        $row['days_in_period'] = $days_in_period;

        $data[] = $row;
    }

    $stmt->close();
    return $data;
}

$hotel_performance = get_hotel_performance($conn, $filter_year, $filter_month, $filter_city, $filter_hotel);

// 3. Perbandingan Kota
function get_city_comparison($conn, $year, $month, $city = 'all', $hotel = 'all')
{
    $year  = (int)$year;
    $month = (int)$month;

    $query = "SELECT 
                h.kota,
                COUNT(DISTINCT h.hotel_id) as hotel_count,
                COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue,
                COUNT(b.booking_id) as total_bookings,
                COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0) as avg_revenue_per_booking,
                COUNT(DISTINCT b.customer_id) as unique_customers
              FROM hotel h
              LEFT JOIN booking_hotel b 
                     ON h.hotel_id = b.hotel_id 
                    AND YEAR(b.tanggal_booking) = ? 
                    AND MONTH(b.tanggal_booking) = ?
                    AND b.status IN ('Completed', 'Cancelled', 'Confirmed')
              WHERE 1=1";

    $params = [$year, $month];
    $types  = "ii";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    if ($hotel !== 'all') {
        $query .= " AND h.hotel_id = ?";
        $params[] = $hotel;
        $types .= "i";
    }

    $query .= " GROUP BY h.kota
                ORDER BY total_revenue DESC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Error preparing city comparison query: " . $conn->error);
        return [];
    }

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        error_log("Error executing city comparison query: " . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $row['kota'] = htmlspecialchars($row['kota']);
        $data[] = $row;
    }
    $stmt->close();

    return $data;
}

$city_comparison = get_city_comparison($conn, $filter_year, $filter_month, $filter_city, $filter_hotel);

// 4. Hotel Berkinerja Terbaik
function get_top_performing_hotels($conn, $year, $month, $limit = 10, $city = 'all', $hotel = 'all')
{
    $year  = (int)$year;
    $month = (int)$month;
    $limit = (int)$limit;

    $days_in_period = cal_days_in_month(CAL_GREGORIAN, $month, $year);

    $query = "SELECT 
                h.hotel_id,
                h.nama_hotel,
                h.kota,
                COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue,
                COUNT(b.booking_id) as total_bookings,
                COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed_bookings,
                COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0) as avg_booking_value,
                (SELECT COALESCE(SUM(j.stok_total), 0) 
                   FROM jadwal_hotel j 
                  WHERE j.hotel_id = h.hotel_id) as total_capacity,
                COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN DATEDIFF(b.check_out, b.check_in) ELSE NULL END), 0) as avg_stay_duration
              FROM hotel h
              LEFT JOIN booking_hotel b 
                     ON h.hotel_id = b.hotel_id 
                    AND YEAR(b.tanggal_booking) = ? 
                    AND MONTH(b.tanggal_booking) = ?
                    AND b.status IN ('Completed', 'Cancelled', 'Confirmed')
              WHERE 1=1";

    $params = [$year, $month];
    $types  = "ii";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    if ($hotel !== 'all') {
        $query .= " AND h.hotel_id = ?";
        $params[] = $hotel;
        $types .= "i";
    }

    $query .= " GROUP BY h.hotel_id, h.nama_hotel, h.kota
                HAVING total_bookings > 0
                ORDER BY total_revenue DESC, total_bookings DESC
                LIMIT ?";

    $params[] = $limit;
    $types .= "i";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed (top hotels): " . $conn->error);
        return [];
    }

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        error_log("Error executing top hotels query: " . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $data = [];

    while ($row = $result->fetch_assoc()) {
        $row['nama_hotel'] = htmlspecialchars($row['nama_hotel']);
        $row['kota'] = htmlspecialchars($row['kota']);

        $total_capacity = (float)($row['total_capacity'] ?? 0);
        $avg_stay       = (float)($row['avg_stay_duration'] ?? 0);
        if ($avg_stay <= 0) $avg_stay = 1;

        $room_nights_sold = (float)($row['completed_bookings'] ?? 0) * $avg_stay;
        $room_nights_available = $total_capacity * $days_in_period;

        $row['occupancy_rate'] = $room_nights_available > 0
            ? round(($room_nights_sold / $room_nights_available) * 100, 2)
            : 0;

        $total_bookings = (int)($row['total_bookings'] ?? 0);
        $completed = (int)($row['completed_bookings'] ?? 0);
        $row['success_rate'] = $total_bookings > 0
            ? round(($completed / $total_bookings) * 100, 2)
            : 0;

        $data[] = $row;
    }

    $stmt->close();
    return $data;
}

$top_hotels = get_top_performing_hotels($conn, $filter_year, $filter_month, 10, $filter_city, $filter_hotel);

// 5. Analisis Market Share
function get_market_share($conn, $year, $month, $city = 'all', $hotel = 'all')
{
    $query = "SELECT 
                h.kota,
                COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as city_revenue
              FROM hotel h
              LEFT JOIN booking_hotel b 
                ON h.hotel_id = b.hotel_id 
               AND YEAR(b.tanggal_booking) = ? 
               AND MONTH(b.tanggal_booking) = ?
               AND b.status IN ('Completed', 'Cancelled', 'Confirmed')
              WHERE 1=1";

    $params = [(int)$year, (int)$month];
    $types  = "ii";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    if ($hotel !== 'all') {
        $query .= " AND h.hotel_id = ?";
        $params[] = (int)$hotel;
        $types .= "i";
    }

    $query .= " GROUP BY h.kota
                HAVING city_revenue > 0
                ORDER BY city_revenue DESC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed (market share): " . $conn->error);
        return [];
    }

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        error_log("Error executing market share query: " . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $data = [];
    $total_revenue = 0.0;

    while ($row = $result->fetch_assoc()) {
        $row['kota'] = htmlspecialchars($row['kota']);
        $rev = (float)($row['city_revenue'] ?? 0);
        $data[] = $row;
        $total_revenue += $rev;
    }
    $stmt->close();

    foreach ($data as &$cityRow) {
        $rev = (float)($cityRow['city_revenue'] ?? 0);
        $cityRow['total_revenue'] = $total_revenue;
        $cityRow['market_share']  = $total_revenue > 0
            ? round(($rev / $total_revenue) * 100, 2)
            : 0;
    }
    unset($cityRow);

    return $data;
}

$market_share = get_market_share($conn, $filter_year, $filter_month, $filter_city, $filter_hotel);

// 6. Analisis Tren 6 Bulan Terakhir
function get_market_analysis_from_db($conn, $months_back = 6, $city = 'all', $hotel = 'all')
{
    $end_date = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime("-{$months_back} months"));

    $query = "SELECT 
                DATE_FORMAT(b.tanggal_booking, '%Y-%m') as bulan_tahun,
                DATE_FORMAT(b.tanggal_booking, '%M') as nama_bulan,
                COUNT(b.booking_id) as jumlah_pemesanan,
                COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_pendapatan,
                COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0) as avg_nilai_pemesanan,
                COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN DATEDIFF(b.check_out, b.check_in) ELSE NULL END), 0) as avg_lama_inap,
                COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed_bookings,
                COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancelled_bookings
            FROM booking_hotel b
            JOIN hotel h ON b.hotel_id = h.hotel_id
            WHERE b.tanggal_booking BETWEEN ? AND ?
              AND b.status IN ('Completed', 'Cancelled', 'Confirmed')";

    $params = [$start_date, $end_date];
    $types  = "ss";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }
    if ($hotel !== 'all') {
        $query .= " AND h.hotel_id = ?";
        $params[] = $hotel;
        $types .= "i";
    }

    $query .= " GROUP BY DATE_FORMAT(b.tanggal_booking, '%Y-%m'), DATE_FORMAT(b.tanggal_booking, '%M')
                ORDER BY bulan_tahun";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed (market analysis): " . $conn->error);
        return [];
    }

    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        error_log("Execute failed (market analysis): " . $stmt->error);
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $trends = [];

    while ($row = $result->fetch_assoc()) {
        $jumlah = (int)($row['jumlah_pemesanan'] ?? 0);
        $completed = (int)($row['completed_bookings'] ?? 0);
        $cancelled = (int)($row['cancelled_bookings'] ?? 0);

        $row['success_rate'] = ($jumlah > 0) ? round(($completed / $jumlah) * 100, 2) : 0;
        $row['cancellation_rate'] = ($jumlah > 0) ? round(($cancelled / $jumlah) * 100, 2) : 0;

        $trends[] = $row;
    }
    $stmt->close();

    return $trends;
}

$market_analysis = get_market_analysis_from_db($conn, 6, $filter_city, $filter_hotel);

// 7. Get system notifications
$query_notif = "SELECT COUNT(*) as notifications FROM booking_hotel WHERE status = 'Pending'";
$result_notif = $conn->query($query_notif);
if ($result_notif) {
    $notificationCount = $result_notif->fetch_assoc()['notifications'] ?? 0;
} else {
    $notificationCount = 0;
}

$conn->close();

// Handle profile photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    require __DIR__ . '/../connect.php';
    $uploadDir = '../../uploads/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileName = uniqid() . '_' . basename($_FILES['profile_photo']['name']);
    $targetPath = $uploadDir . $fileName;

    $imageFileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
    $maxFileSize = 5 * 1024 * 1024; // 5MB

    if (!in_array($imageFileType, $allowedTypes)) {
        $_SESSION['upload_notification'] = "Invalid file type. Only JPG, PNG, GIF allowed.";
    } elseif ($_FILES['profile_photo']['size'] > $maxFileSize) {
        $_SESSION['upload_notification'] = "File size too large. Maximum 5MB allowed.";
    } elseif (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
        $update = $conn->prepare("UPDATE user SET profile_picture = ? WHERE id_user = ?");
        if ($update) {
            $update->bind_param("ss", $fileName, $id_user);
            if ($update->execute()) {
                $_SESSION['upload_notification'] = "Profile photo updated successfully!";
                $foto = '../../uploads/' . $fileName;
            } else {
                $_SESSION['upload_notification'] = "Error updating database.";
            }
            $update->close();
        } else {
            $_SESSION['upload_notification'] = "Error preparing update query.";
        }
    } else {
        $_SESSION['upload_notification'] = "Failed to upload photo.";
    }

    $conn->close();
    header("Location: market_analysis.php?apply_filter=1&year=$filter_year&month=$filter_month&city=$filter_city&hotel=$filter_hotel");
    exit();
}

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
    $amount = (float)$amount;
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

// Function to format large Rupiah amounts
function formatRupiahLarge($amount)
{
    $amount = (float)$amount;
    if ($amount >= 1000000000) {
        return 'Rp ' . number_format($amount / 1000000000, 2, ',', '.') . ' Miliar';
    } elseif ($amount >= 1000000) {
        return 'Rp ' . number_format($amount / 1000000, 2, ',', '.') . ' Juta';
    } else {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}

// --- KPI BASED ON FILTER ---
if ($filter_hotel !== 'all') {
    // Cari data hotel terpilih
    $selectedHotel = null;
    foreach ($hotel_performance as $h) {
        if ((string)$h['hotel_id'] === (string)$filter_hotel) {
            $selectedHotel = $h;
            break;
        }
    }

    $kpi_total_bookings = $selectedHotel['total_bookings'] ?? 0;
    $kpi_completed = $selectedHotel['completed_bookings'] ?? 0;
    $kpi_revenue = $selectedHotel['total_revenue'] ?? 0;
    $kpi_avg_stay = $selectedHotel['avg_stay_duration'] ?? 0;
} else {
    // Default (semua kota & hotel)
    $kpi_total_bookings = array_sum(array_column($city_performance, 'total_bookings'));
    $kpi_completed = array_sum(array_column($city_performance, 'completed_bookings'));
    $kpi_revenue = array_sum(array_column($city_performance, 'total_revenue'));

    $total_stay = 0;
    $total_completed = 0;
    foreach ($city_performance as $city) {
        $total_stay += $city['avg_stay_duration'] * $city['completed_bookings'];
        $total_completed += $city['completed_bookings'];
    }
    $kpi_avg_stay = $total_completed > 0 ? $total_stay / $total_completed : 0;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Analisis Tren Pemesanan Hotel | TripVerse Admin</title>
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

        body {
            background-color: #f8fafc;
            font-family: 'Heebo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .performance-section {
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

        .performance-section::before {
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

        .performance-section:hover::before {
            transform: scaleX(1);
        }

        .performance-section:hover {
            box-shadow: var(--box-shadow-hover);
            transform: translateY(-5px);
        }

        .performance-section h2 {
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

        .period-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            font-size: 15px;
            color: var(--primary-color);
            font-weight: 600;
            padding: 12px 18px;
            background: linear-gradient(135deg, #e8eaf6 0%, #f3e5f5 100%);
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-color);
            flex-wrap: wrap;
        }

        .period-info span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Filter Controls */
        .filter-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-light);
            margin-bottom: 8px;
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
            cursor: pointer;
        }

        .filter-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 122, 61, 0.1);
            outline: none;
            transform: translateY(-1px);
        }

        .filter-select:disabled {
            background-color: #f8f9fa;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
            white-space: nowrap;
            justify-content: center;
            height: fit-content;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #E8672B;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 122, 61, 0.3);
        }

        .btn-primary:disabled {
            background: #9fa8da;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }

        /* KPI Cards */
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

        /* Performance Grid */
        .performance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(480px, 1fr));
            gap: 25px;
        }

        .performance-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: var(--transition);
            overflow-x: auto;
        }

        .performance-card:hover {
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transform: translateY(-3px);
        }

        .performance-card h3 {
            font-size: 17px;
            margin: 0 0 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f3f4;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--dark-color);
        }

        /* Table Styling */
        .performance-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #e1e5e9;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .performance-table thead th {
            background: linear-gradient(135deg, var(--primary-color), #FF7A3D);
            color: white;
            padding: 16px 15px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            text-align: left;
            font-weight: 600;
        }

        .performance-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .performance-table tbody tr:hover {
            background-color: #e8eaf6;
            cursor: pointer;
            transition: var(--transition);
        }

        .performance-table td {
            font-size: 14px;
            color: var(--text-color);
            padding: 14px 15px;
            border-bottom: 1px solid #e9ecef;
        }

        /* Chart Containers */
        .chart-container {
            position: relative;
            height: 380px;
            width: 100%;
            background: white;
            border-radius: var(--border-radius);
            padding: 15px;
            border: 1px solid #e9ecef;
        }

        .trend-chart-container {
            height: 300px;
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
        }

        /* Enhanced City Cards */
        .city-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }

        .city-card {
            background: linear-gradient(145deg, #ffffff, #f5f7fa);
            border-radius: var(--border-radius);
            padding: 30px 25px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 122, 61, 0.1);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            min-height: 280px;
            display: flex;
            flex-direction: column;
        }

        .city-card:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 15px 35px rgba(255, 122, 61, 0.15);
        }

        .city-card h4 {
            margin: 0 0 15px 0;
            color: var(--dark-color);
            font-size: 20px;
            font-weight: 700;
            position: relative;
            padding-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .city-card h4::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 3px;
            background: var(--primary-color);
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .city-card:hover h4::after {
            width: 80px;
        }

        .city-card .location {
            color: var(--text-light);
            font-size: 15px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: rgba(255, 122, 61, 0.05);
            border-radius: 8px;
            border-left: 3px solid var(--primary-color);
        }

        .city-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: auto;
        }

        .stat-item {
            text-align: center;
            padding: 18px 15px;
            background: white;
            border-radius: var(--border-radius);
            transition: var(--transition);
            border: 1px solid #e9ecef;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .stat-item:hover {
            background: #f8f9fa;
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }

        .stat-value {
            font-size: 24px;
            font-weight: 800;
            color: var(--dark-color);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        /* Rank Badges */
        .rank-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            background: var(--primary-color);
            color: white;
            border-radius: 50%;
            font-weight: 700;
            font-size: 14px;
            margin-right: 10px;
            transition: var(--transition);
        }

        .rank-badge:hover {
            transform: scale(1.1);
        }

        .rank-1 {
            background: linear-gradient(135deg, #FFD700, #FFA000);
            box-shadow: 0 2px 8px rgba(255, 215, 0, 0.4);
        }

        .rank-2 {
            background: linear-gradient(135deg, #C0C0C0, #A0A0A0);
            box-shadow: 0 2px 8px rgba(192, 192, 192, 0.4);
        }

        .rank-3 {
            background: linear-gradient(135deg, #CD7F32, #A56C27);
            box-shadow: 0 2px 8px rgba(205, 127, 50, 0.4);
        }

        /* Empty State Styling */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--text-light);
        }

        .empty-state .material-icons {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state p {
            font-size: 16px;
            margin: 0;
        }

        /* Status Indicators */
        .status-excellent {
            color: var(--success-color);
            font-weight: 700;
        }

        .status-good {
            color: var(--info-color);
            font-weight: 600;
        }

        .status-warning {
            color: var(--warning-color);
            font-weight: 600;
        }

        .status-poor {
            color: var(--danger-color);
            font-weight: 600;
        }

        /* Notification */
        .notification-message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: slideIn 0.5s ease-out;
        }

        .notification-message.success {
            background: linear-gradient(135deg, #1baf7a, #3fcf9c);
            color: white;
        }

        .notification-message.error {
            background: linear-gradient(135deg, #e34948, #ef6e6d);
            color: white;
        }

        @keyframes slideIn {
            from {
                transform: translateX(-100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .performance-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .performance-section {
                padding: 20px;
                margin-bottom: 20px;
            }

            .kpi-grid,
            .city-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .filter-controls {
                grid-template-columns: 1fr;
                padding: 15px;
            }

            .filter-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }

            .chart-container,
            .trend-chart-container {
                height: 250px;
            }

            .performance-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .performance-section {
                padding: 15px;
            }

            .performance-card {
                padding: 15px;
            }

            .chart-container,
            .trend-chart-container {
                height: 200px;
                padding: 10px;
            }

            .city-stats {
                grid-template-columns: 1fr;
            }
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

        .performance-section {
            animation: fadeIn 0.6s ease-out;
        }

        .kpi-card,
        .city-card {
            animation: fadeIn 0.8s ease-out;
        }

        /* Custom styling for performance-by-city-grid */
        .performance-by-city-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary-color);
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .highlight-hotel {
            background: linear-gradient(135deg, #e3f2fd, #e8eaf6) !important;
            border-left: 5px solid #FF7A3D;
        }

        .highlight-badge {
            display: inline-block;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 700;
            color: white;
            background: #FF7A3D;
            border-radius: 12px;
            margin-left: 6px;
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
                    <img src="<?php echo htmlspecialchars($foto); ?>"
                        alt="Profile Photo"
                        class="profile-photo"
                        id="profilePhoto"
                        onerror="this.src='../../images/default.jpg'">

                    <div class="profile-overlay">
                        <span class="material-icons">edit</span>
                    </div>

                    <form id="uploadForm" action="market_analysis.php" method="POST" enctype="multipart/form-data" style="display:none;">
                        <input type="file" name="profile_photo" id="profileUpload" accept="image/*" />
                    </form>
                </div>

                <div class="profile-info">
                    <h2><?= $firstName . ' ' . $lastName; ?></h2>
                    <p><?= $email; ?></p>

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
                    <a href="market_analysis.php" class="active">
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
                    <span class="notification-badge" id="notificationCount"><?= $notificationCount ?></span>
                </div>

                <div class="user-menu">
                    <img src="<?php echo htmlspecialchars($foto); ?>" alt="User Avatar" class="user-avatar" />
                </div>
            </div>
        </header>

        <?php if (isset($_SESSION['upload_notification'])) : ?>
            <div class="notification-message <?php echo strpos($_SESSION['upload_notification'], 'Failed') === false && strpos($_SESSION['upload_notification'], 'Error') === false ? 'success' : 'error'; ?>">
                <?php
                echo htmlspecialchars($_SESSION['upload_notification']);
                unset($_SESSION['upload_notification']);
                ?>
            </div>
        <?php endif; ?>

        <div class="performance-section">
            <h2><i class="material-icons">trending_up</i> <?= te('Analisis Tren Pemesanan Hotel - TripVerse') ?></h2>

            <form method="GET" action="market_analysis.php" id="filterForm" class="filter-controls">
                <input type="hidden" name="apply_filter" value="1">

                <div class="filter-group">
                    <label for="filter_month"><?= te('Bulan') ?></label>
                    <select id="filter_month" name="month" class="filter-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <?php $month_padded = str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                            <option value="<?= $month_padded ?>" <?= (int)$filter_month == $m ? 'selected' : '' ?>>
                                <?= getIndonesianMonth($m) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filter_year">Tahun</label>
                    <select id="filter_year" name="year" class="filter-select">
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?= $year ?>" <?= $filter_year == $year ? 'selected' : '' ?>>
                                <?= $year ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filter_city">Kota</label>
                    <select id="filter_city" name="city" class="filter-select">
                        <option value="all" <?= $filter_city == 'all' ? 'selected' : '' ?>>Semua Kota</option>
                        <?php foreach ($available_cities as $city): ?>
                            <option value="<?= $city ?>" <?= $filter_city == $city ? 'selected' : '' ?>>
                                <?= $city ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filter_hotel">Hotel</label>
                    <select id="filter_hotel" name="hotel" class="filter-select" <?= empty($available_hotels) ? 'disabled' : '' ?>>
                        <option value="all" <?= $filter_hotel == 'all' ? 'selected' : '' ?>>Semua Hotel</option>
                        <?php foreach ($available_hotels as $id => $name): ?>
                            <option value="<?= $id ?>" <?= $filter_hotel == $id ? 'selected' : '' ?>>
                                <?= $name ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($available_hotels)): ?>
                        <small style="color: #666; margin-top: 5px;">Tidak ada hotel tersedia untuk filter ini</small>
                    <?php endif; ?>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary" id="applyFilterBtn">
                        <i class="material-icons">filter_alt</i> Terapkan Filter
                    </button>
                    <a href="market_analysis.php?reset=1" class="btn btn-secondary">
                        <i class="material-icons">refresh</i> Reset
                    </a>
                </div>
            </form>

            <div class="period-info">
                <i class="material-icons">calendar_today</i>
                <span>Periode Analisis: <strong><?= getIndonesianMonth($filter_month) . ' ' . $filter_year ?></strong></span>
                <?php if ($filter_city !== 'all'): ?>
                    <span>• <i class="material-icons">place</i> Kota: <strong><?= htmlspecialchars($filter_city) ?></strong></span>
                <?php endif; ?>
                <?php if ($filter_hotel !== 'all' && isset($available_hotels[$filter_hotel])): ?>
                    <span>• <i class="material-icons">hotel</i> Hotel: <strong><?= htmlspecialchars($available_hotels[$filter_hotel]) ?></strong></span>
                <?php endif; ?>
                <?php if (isset($_GET['apply_filter']) && $_GET['apply_filter'] == '1'): ?>
                    <span style="color: #1baf7a; margin-left: 10px;">
                        <i class="material-icons">check_circle</i> Filter diterapkan
                    </span>
                <?php endif; ?>
            </div>

            <!-- Overview KPIs -->
            <div class="kpi-grid">
                <?php
                $total_bookings = array_sum(array_column($city_performance, 'total_bookings'));
                $completed_bookings = array_sum(array_column($city_performance, 'completed_bookings'));
                $total_revenue = array_sum(array_column($city_performance, 'total_revenue'));
                ?>

                <div class="kpi-card primary">
                    <div class="kpi-icon">
                        <i class="material-icons">receipt</i>
                    </div>
                    <div class="kpi-label"><?= te('Total Booking') ?></div>
                    <div class="kpi-value"><?= number_format($kpi_total_bookings) ?></div>
                    <div class="kpi-change change-neutral">
                        <i class="material-icons" style="font-size: 14px;">trending_up</i>
                        Semua status
                    </div>
                </div>

                <div class="kpi-card success">
                    <div class="kpi-icon">
                        <i class="material-icons">check_circle</i>
                    </div>
                    <div class="kpi-label"><?= te('Booking Berhasil') ?></div>
                    <div class="kpi-value"><?= number_format($kpi_completed) ?></div>
                    <div class="kpi-change change-neutral">
                        <i class="material-icons" style="font-size: 14px;">done_all</i>
                        Status: Completed
                    </div>
                </div>

                <div class="kpi-card info">
                    <div class="kpi-icon">
                        <i class="material-icons">attach_money</i>
                    </div>
                    <div class="kpi-label"><?= te('Total Pendapatan') ?></div>
                    <div class="kpi-value"><?= formatRupiahLarge($kpi_revenue) ?></div>
                    <div class="kpi-change change-neutral">
                        <i class="material-icons" style="font-size: 14px;">trending_up</i>
                        Semua kota & hotel
                    </div>
                </div>

                <div class="kpi-card warning">
                    <div class="kpi-icon">
                        <i class="material-icons">schedule</i>
                    </div>
                    <div class="kpi-label"><?= te('Rata-rata Lama Menginap') ?></div>
                    <div class="kpi-value"><?= round($kpi_avg_stay, 1) ?> hari</div>
                    <div class="kpi-change change-neutral">
                        <i class="material-icons" style="font-size: 14px;">night_shelter</i>
                        Rata-rata semua kota
                    </div>
                </div>
            </div>
        </div>

        <!-- TREN PEMESANAN SECTION -->
        <div class="performance-section">
            <h2><i class="material-icons">timeline</i> <?= te('Analisis Tren Pemesanan (6 Bulan Terakhir)') ?></h2>

            <div class="trend-chart-container">
                <canvas id="bookingTrendsChart"></canvas>
            </div>
        </div>

        <!-- MARKET SHARE SECTION -->
        <div class="performance-section">
            <h2><i class="material-icons">pie_chart</i> <?= te('Analisis Pangsa Pasar') ?></h2>

            <div class="performance-grid">
                <div class="performance-card">
                    <h3><i class="material-icons">donut_large</i> Market Share Berdasarkan Kota</h3>
                    <div class="chart-container">
                        <canvas id="marketShareChart"></canvas>
                    </div>
                </div>

                <div class="performance-card">
                    <h3><i class="material-icons">bar_chart</i> Perbandingan Pendapatan per Kota</h3>
                    <div class="chart-container">
                        <canvas id="revenueByCityChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- TOP PERFORMING HOTELS -->
        <div class="performance-section">
            <h2><i class="material-icons">leaderboard</i> <?= te('10 Hotel Berkinerja Terbaik') ?></h2>

            <div class="performance-card">
                <?php if (!empty($top_hotels)): ?>
                    <table class="performance-table">
                        <thead>
                            <tr>
                                <th><?= te('Peringkat') ?></th>
                                <th>Hotel</th>
                                <th><?= te('Kota') ?></th>
                                <th><?= te('Pendapatan') ?></th>
                                <th><?= te('Pemesanan') ?></th>
                                <th><?= te('Tingkat Okupansi') ?></th>
                                <th><?= te('Tingkat Keberhasilan') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; ?>
                            <?php foreach ($top_hotels as $hotel): ?>
                                <tr>
                                    <td>
                                        <div class="rank-badge rank-<?= $rank <= 3 ? $rank : 'default' ?>">
                                            <?= $rank ?>
                                        </div>
                                    </td>
                                    <td><strong><?= $hotel['nama_hotel'] ?></strong></td>
                                    <td><?= $hotel['kota'] ?></td>
                                    <td><strong><?= formatRupiah($hotel['total_revenue']) ?></strong></td>
                                    <td><?= number_format($hotel['total_bookings']) ?></td>
                                    <td>
                                        <?php
                                        $occupancy_class = '';
                                        if ($hotel['occupancy_rate'] >= 70) $occupancy_class = 'status-excellent';
                                        elseif ($hotel['occupancy_rate'] >= 50) $occupancy_class = 'status-good';
                                        elseif ($hotel['occupancy_rate'] >= 30) $occupancy_class = 'status-warning';
                                        else $occupancy_class = 'status-poor';
                                        ?>
                                        <span class="<?= $occupancy_class ?>">
                                            <?= $hotel['occupancy_rate'] ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $success_class = '';
                                        if ($hotel['success_rate'] >= 80) $success_class = 'status-excellent';
                                        elseif ($hotel['success_rate'] >= 60) $success_class = 'status-good';
                                        elseif ($hotel['success_rate'] >= 40) $success_class = 'status-warning';
                                        else $success_class = 'status-poor';
                                        ?>
                                        <span class="<?= $success_class ?>">
                                            <?= $hotel['success_rate'] ?>%
                                        </span>
                                    </td>
                                </tr>
                                <?php $rank++; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="material-icons">hotel</i>
                        <p><?= te('Tidak ada data hotel untuk periode ini') ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- PERFORMANCE BY CITY -->
        <div class="performance-section">
            <h2><i class="material-icons">location_city</i> <?= te('Performa berdasarkan Kota') ?></h2>

            <div class="period-info" style="margin-bottom: 30px;">
                <i class="material-icons">info</i>
                <span>Analisis kinerja berdasarkan kota dengan metrik pendapatan, pemesanan, dan durasi menginap</span>
            </div>

            <div class="performance-by-city-grid">
                <?php if (!empty($city_performance)): ?>
                    <?php foreach ($city_performance as $city): ?>
                        <div class="city-card">
                            <h4>
                                <i class="material-icons" style="color: var(--primary-color);">place</i>
                                <?= $city['kota'] ?>
                            </h4>
                            <div class="location">
                                <i class="material-icons">hotel</i>
                                <?= $city['total_hotels'] ?> Hotel •
                                <i class="material-icons">people</i>
                                <?= $city['unique_customers'] ?> Pelanggan •
                                <i class="material-icons">star</i>
                                <?= formatRupiah($city['avg_booking_value'] ?? 0) ?> /booking
                            </div>
                            <div class="city-stats">
                                <div class="city-stat-item">
                                    <div class="city-stat-value">
                                        <i class="material-icons" style="color: #1baf7a;">attach_money</i>
                                        <?= formatRupiah($city['total_revenue']) ?>
                                    </div>
                                    <div class="city-stat-label">Total Pendapatan</div>
                                </div>
                                <div class="city-stat-item">
                                    <div class="city-stat-value">
                                        <i class="material-icons" style="color: #2a78d6;">receipt</i>
                                        <?= number_format($city['total_bookings']) ?>
                                    </div>
                                    <div class="city-stat-label">Total Pemesanan</div>
                                </div>
                                <div class="city-stat-item">
                                    <div class="city-stat-value">
                                        <i class="material-icons" style="color: #1baf7a;">check_circle</i>
                                        <?= number_format($city['completed_bookings']) ?>
                                        <span style="font-size: 12px; color: #1baf7a;">
                                            (<?= $city['total_bookings'] > 0 ? round(($city['completed_bookings'] / $city['total_bookings']) * 100, 1) : 0 ?>%)
                                        </span>
                                    </div>
                                    <div class="city-stat-label">Pemesanan Selesai</div>
                                </div>
                                <div class="city-stat-item">
                                    <div class="city-stat-value">
                                        <i class="material-icons" style="color: #eda100;">schedule</i>
                                        <?= round($city['avg_stay_duration'] ?? 0, 1) ?> hari
                                    </div>
                                    <div class="city-stat-label">Rata-rata Lama Inap</div>
                                </div>
                            </div>
                            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #eee; font-size: 13px; color: #666;">
                                <i class="material-icons" style="font-size: 14px; vertical-align: middle; color: #FF7A3D;">insights</i>
                                <?php
                                $revenue_per_hotel = $city['total_hotels'] > 0 ? $city['total_revenue'] / $city['total_hotels'] : 0;
                                ?>
                                Pendapatan per Hotel: <strong><?= formatRupiah($revenue_per_hotel) ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="city-card" style="grid-column: 1 / -1; text-align: center; min-height: 200px;">
                        <div class="empty-state">
                            <i class="material-icons" style="font-size: 48px; color: #bdbdbd;">location_off</i>
                            <p style="font-size: 16px; margin-top: 15px;">Tidak ada data kinerja kota untuk periode ini</p>
                            <p style="font-size: 14px; color: #9e9e9e; margin-top: 5px;">Coba pilih periode atau filter yang berbeda</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- DETAILED HOTEL PERFORMANCE -->
        <div class="performance-section">
            <h2><i class="material-icons">hotel</i> <?= te('Performa Hotel Detail') ?></h2>

            <div class="performance-card">
                <?php if (!empty($hotel_performance)): ?>
                    <table class="performance-table">
                        <thead>
                            <tr>
                                <th>Hotel</th>
                                <th>Kota</th>
                                <th>Pendapatan</th>
                                <th>Pemesanan</th>
                                <th>Success Rate</th>
                                <th>Occupancy Rate</th>
                                <th>Rata-rata Menginap</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hotel_performance as $hotel): ?>
                                <?php $isSelected = ($filter_hotel !== 'all' && (string)$filter_hotel === (string)$hotel['hotel_id']); ?>
                                <tr class="<?= $isSelected ? 'highlight-hotel' : '' ?>">
                                    <td>
                                        <strong><?= $hotel['nama_hotel'] ?></strong>
                                        <?php if ($isSelected): ?>
                                            <span class="highlight-badge">HOTEL DIPILIH</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $hotel['kota'] ?></td>
                                    <td><strong><?= formatRupiah($hotel['total_revenue']) ?></strong></td>
                                    <td><?= number_format($hotel['total_bookings']) ?></td>
                                    <td>
                                        <?php
                                        if ($hotel['success_rate'] >= 80) $success_class = 'status-excellent';
                                        elseif ($hotel['success_rate'] >= 60) $success_class = 'status-good';
                                        elseif ($hotel['success_rate'] >= 40) $success_class = 'status-warning';
                                        else $success_class = 'status-poor';
                                        ?>
                                        <span class="<?= $success_class ?>">
                                            <?= $hotel['success_rate'] ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        if ($hotel['occupancy_rate'] >= 70) $occupancy_class = 'status-excellent';
                                        elseif ($hotel['occupancy_rate'] >= 50) $occupancy_class = 'status-good';
                                        elseif ($hotel['occupancy_rate'] >= 30) $occupancy_class = 'status-warning';
                                        else $occupancy_class = 'status-poor';
                                        ?>
                                        <span class="<?= $occupancy_class ?>">
                                            <?= $hotel['occupancy_rate'] ?>%
                                        </span>
                                    </td>
                                    <td><?= round($hotel['avg_stay_duration'] ?? 0, 1) ?> hari</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="material-icons">hotel</i>
                        <p>Tidak ada data kinerja hotel untuk filter yang dipilih</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>

    <script>
        // --- JAVASCRIPT LOGIC ---

        function toggleDropdown(button) {
            event.stopPropagation();
            const dropdown = button.nextElementSibling;
            const isExpanded = button.getAttribute('aria-expanded') === 'true';

            document.querySelectorAll('.user-dropdown .dropdown-content').forEach(d => {
                d.classList.remove('show');
                d.setAttribute('aria-hidden', 'true');
                d.previousElementSibling.setAttribute('aria-expanded', 'false');
            });

            if (!isExpanded) {
                dropdown.style.transform = 'none';
                dropdown.classList.add('show');
                button.setAttribute('aria-expanded', 'true');
                dropdown.setAttribute('aria-hidden', 'false');
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.user-dropdown')) {
                document.querySelectorAll('.user-dropdown .dropdown-content').forEach(d => {
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

        // Initialize sidebar state
        const savedState = localStorage.getItem('sidebarState');
        if (savedState === 'collapsed' && window.innerWidth > 768) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }

        // Initialize charts on page load
        document.addEventListener('DOMContentLoaded', function() {
            // --- LOGIC FOR SIDEBAR SUBMENUS ---
            document.querySelectorAll('.booking-toggle').forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    const targetId = this.getAttribute('data-target');
                    const submenu = document.getElementById(targetId);
                    const isHidden = submenu.classList.contains('hidden') || submenu.style.display === 'none';
                    const toggleIcon = this.querySelector('.toggle-icon');

                    // Close all other submenus
                    document.querySelectorAll('.booking-submenu').forEach(sub => {
                        if (sub.id !== targetId) {
                            sub.classList.add('hidden');
                            sub.style.display = 'none';
                            sub.previousElementSibling.querySelector('.toggle-icon').textContent = 'expand_more';
                        }
                    });

                    // Toggle the clicked submenu
                    if (isHidden) {
                        submenu.classList.remove('hidden');
                        submenu.style.display = 'block';
                        toggleIcon.textContent = 'expand_less';
                    } else {
                        submenu.classList.add('hidden');
                        submenu.style.display = 'none';
                        toggleIcon.textContent = 'expand_more';
                    }
                });
            });

            // Define colors based on the blue palette
            const primaryColor = '#FF7A3D';
            const secondaryColor = '#eda100';
            const accentColor = '#4fc3f7';
            const successColor = '#1baf7a';
            const warningColor = '#eda100';
            const dangerColor = '#e34948';
            const infoColor = '#2a78d6';

            // Color palette for charts
            const chartColors = [
                primaryColor, secondaryColor, accentColor, infoColor,
                '#01579b', '#0288d1', '#03a9f4', '#4fc3f7', '#81d4fa', '#b3e5fc'
            ];

            // 1. Booking Trends Chart
            const bookingTrendsCtx = document.getElementById('bookingTrendsChart');
            if (bookingTrendsCtx) {
                const trendsData = <?= json_encode($market_analysis) ?>;

                if (trendsData.length > 0) {
                    // Prepare data for chart
                    const months = trendsData.map(item => {
                        const date = new Date(item.bulan_tahun + '-01');
                        return date.toLocaleDateString('id-ID', {
                            month: 'short',
                            year: 'numeric'
                        });
                    });

                    const bookingData = trendsData.map(item => item.jumlah_pemesanan);
                    const revenueData = trendsData.map(item => item.total_pendapatan);
                    const successRateData = trendsData.map(item => item.success_rate);

                    new Chart(bookingTrendsCtx.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: months,
                            datasets: [{
                                    label: 'Jumlah Pemesanan',
                                    data: bookingData,
                                    borderColor: primaryColor,
                                    backgroundColor: primaryColor + '20',
                                    tension: 0.4,
                                    fill: true,
                                    borderWidth: 2,
                                    yAxisID: 'yBookings'
                                },
                                {
                                    label: 'Success Rate (%)',
                                    data: successRateData,
                                    borderColor: successColor,
                                    backgroundColor: 'transparent',
                                    borderDash: [5, 5],
                                    tension: 0.4,
                                    fill: false,
                                    borderWidth: 2,
                                    yAxisID: 'ySuccessRate'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Tren Pemesanan dan Success Rate (6 Bulan Terakhir)',
                                    font: {
                                        size: 16
                                    }
                                },
                                legend: {
                                    position: 'bottom'
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: false
                                }
                            },
                            scales: {
                                x: {
                                    grid: {
                                        display: false
                                    }
                                },
                                yBookings: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    title: {
                                        display: true,
                                        text: 'Jumlah Pemesanan'
                                    },
                                    beginAtZero: true
                                },
                                ySuccessRate: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    title: {
                                        display: true,
                                        text: 'Success Rate (%)'
                                    },
                                    grid: {
                                        drawOnChartArea: false
                                    },
                                    min: 0,
                                    max: 100
                                }
                            }
                        }
                    });
                } else {
                    bookingTrendsCtx.parentElement.innerHTML = `
                        <div class="empty-state">
                            <i class="material-icons">timeline</i>
                            <p>Tidak ada data tren untuk periode ini</p>
                        </div>
                    `;
                }
            }

            // 2. Market Share Chart (Doughnut Chart)
            const marketShareCtx = document.getElementById('marketShareChart');
            if (marketShareCtx) {
                const marketData = <?= json_encode($market_share) ?>;

                if (marketData.length > 0) {
                    const labels = marketData.map(item => item.kota);
                    const data = marketData.map(item => item.market_share);

                    new Chart(marketShareCtx.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: data,
                                backgroundColor: chartColors.slice(0, labels.length),
                                borderWidth: 2,
                                borderColor: '#ffffff',
                                hoverOffset: 15
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Market Share Berdasarkan Kota',
                                    font: {
                                        size: 16
                                    }
                                },
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        usePointStyle: true,
                                        padding: 20
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.raw || 0;
                                            return `${label}: ${value}%`;
                                        }
                                    }
                                }
                            },
                            cutout: '60%',
                        }
                    });
                } else {
                    marketShareCtx.parentElement.innerHTML = `
                        <div class="empty-state">
                            <i class="material-icons">pie_chart</i>
                            <p>Tidak ada data market share untuk periode ini</p>
                        </div>
                    `;
                }
            }

            // 3. Revenue by City Chart (Bar Chart)
            const revenueCityCtx = document.getElementById('revenueByCityChart');
            if (revenueCityCtx) {
                const cityData = <?= json_encode($city_comparison) ?>;

                if (cityData.length > 0) {
                    const labels = cityData.map(item => item.kota);
                    const revenueData = cityData.map(item => item.total_revenue || 0);
                    const bookingData = cityData.map(item => item.total_bookings || 0);

                    new Chart(revenueCityCtx.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                    label: 'Pendapatan (IDR)',
                                    data: revenueData,
                                    backgroundColor: primaryColor,
                                    yAxisID: 'yRevenue',
                                    order: 2
                                },
                                {
                                    label: 'Jumlah Pemesanan',
                                    data: bookingData,
                                    backgroundColor: accentColor,
                                    yAxisID: 'yBookings',
                                    order: 1
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Perbandingan Pendapatan & Pemesanan per Kota',
                                    font: {
                                        size: 16
                                    }
                                },
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        usePointStyle: true
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            if (context.dataset.label === 'Pendapatan (IDR)') {
                                                return 'Pendapatan: Rp ' + (context.raw || 0).toLocaleString('id-ID');
                                            } else {
                                                return 'Pemesanan: ' + (context.raw || 0);
                                            }
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: {
                                        display: false
                                    }
                                },
                                yRevenue: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    title: {
                                        display: true,
                                        text: 'Pendapatan (IDR)',
                                        font: {
                                            weight: 'bold'
                                        }
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            if (value >= 1000000) {
                                                return 'Rp ' + (value / 1000000).toFixed(1) + 'M';
                                            } else if (value >= 1000) {
                                                return 'Rp ' + (value / 1000).toFixed(0) + 'K';
                                            }
                                            return 'Rp ' + value;
                                        }
                                    },
                                    beginAtZero: true
                                },
                                yBookings: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    title: {
                                        display: true,
                                        text: 'Jumlah Pemesanan',
                                        font: {
                                            weight: 'bold'
                                        }
                                    },
                                    grid: {
                                        drawOnChartArea: false
                                    },
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                } else {
                    revenueCityCtx.parentElement.innerHTML = `
                        <div class="empty-state">
                            <i class="material-icons">bar_chart</i>
                            <p>Tidak ada data perbandingan kota untuk periode ini</p>
                        </div>
                    `;
                }
            }

            // Profile photo upload functionality
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
                        document.getElementById('uploadForm').submit();
                    }
                });
            }

            // Handle image loading errors
            const profilePhoto = document.getElementById('profilePhoto');
            if (profilePhoto) {
                profilePhoto.addEventListener('error', function() {
                    this.src = '../../images/default.jpg';
                });
            }

            // Dynamic filter updates
            const filterYear = document.getElementById('filter_year');
            const filterCity = document.getElementById('filter_city');
            const filterHotel = document.getElementById('filter_hotel');
            const applyBtn = document.getElementById('applyFilterBtn');

            // Form submission loading state
            const filterForm = document.getElementById('filterForm');
            if (filterForm) {
                filterForm.addEventListener('submit', function() {
                    applyBtn.innerHTML = '<div class="loading-spinner"></div> Menerapkan...';
                    applyBtn.disabled = true;
                });
            }

            // Prevent unwanted transforms (Safety Fix)
            document.querySelectorAll('.dropdown-content, .dropdown-item').forEach(el => {
                el.style.transform = 'none';
            });
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && sidebar && !sidebar.contains(e.target) && toggleBtn && !toggleBtn.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>

</html>