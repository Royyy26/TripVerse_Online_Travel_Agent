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
    die("Error preparing query: " . $conn->error);
}
$stmt->bind_param("s", $id_user);
if (!$stmt->execute()) {
    die("Error executing query: " . $stmt->error);
}
$result = $stmt->get_result();

if ($data = $result->fetch_assoc()) {
    $firstName = $data['first_name'];
    $lastName = $data['last_name'];
    $email = $data['email'];
    $foto = $data['profile_picture'] ?: '../../images/default.jpg';
} else {
    $firstName = $lastName = "-";
    $email = "unknown@tripverse.com";
    $foto = "../../images/default.jpg";
}
$stmt->close();

// --- FILTER LOGIC ---
$filter_type = $_GET['type'] ?? 'overview';
$filter_period = $_GET['period'] ?? 'monthly';
$filter_year = $_GET['year'] ?? date('Y');
$filter_month = $_GET['month'] ?? date('m');
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_city = $_GET['city'] ?? '';
$filter_hotel = $_GET['hotel'] ?? '';
$filter_room_type = $_GET['room_type'] ?? '';

// Validate parameters
if (!is_numeric($filter_month) || $filter_month < 1 || $filter_month > 12) {
    $filter_month = date('m');
}
if (!is_numeric($filter_year) || $filter_year < 2020 || $filter_year > date('Y') + 1) {
    $filter_year = date('Y');
}

// Get list of available years for filter dropdown
$years_query = "SELECT DISTINCT YEAR(check_in) as year FROM booking_hotel ORDER BY year DESC";
$years_result = $conn->query($years_query);
$available_years = [];
if ($years_result) {
    while ($row = $years_result->fetch_assoc()) {
        $available_years[] = $row['year'];
    }
} else {
    $available_years = [date('Y')];
}

if (!in_array(date('Y'), $available_years)) {
    $available_years[] = date('Y');
    rsort($available_years);
}

// --- Get filter options data ---
// Get cities
$cities_query = "SELECT DISTINCT kota FROM hotel ORDER BY kota";
$cities_result = $conn->query($cities_query);
$cities = [];
if ($cities_result) {
    while ($row = $cities_result->fetch_assoc()) {
        $cities[] = $row['kota'];
    }
}

// Get hotels (filtered by city if selected)
$hotels_query = "SELECT hotel_id, nama_hotel, kota FROM hotel";
$hotel_params = [];
if ($filter_city) {
    $hotels_query .= " WHERE kota = ?";
    $hotel_params[] = $filter_city;
}
$hotels_query .= " ORDER BY nama_hotel";

$hotels_stmt = $conn->prepare($hotels_query);
if ($hotel_params) {
    $hotels_stmt->bind_param("s", ...$hotel_params);
}
$hotels_stmt->execute();
$hotels_result = $hotels_stmt->get_result();
$hotels = [];
while ($row = $hotels_result->fetch_assoc()) {
    $hotels[] = $row;
}
$hotels_stmt->close();

// Get room types
$room_types_query = "SELECT tipe_id, nama_tipe FROM tipe_kamar ORDER BY nama_tipe";
$room_types_result = $conn->query($room_types_query);
$room_types = [];
if ($room_types_result) {
    while ($row = $room_types_result->fetch_assoc()) {
        $room_types[] = $row;
    }
}

// --- Build filter conditions for analytics functions ---
$filter_conditions = "";
$filter_params = [];

// Add city filter if selected
if ($filter_city) {
    $filter_conditions .= " AND h.kota = ?";
    $filter_params[] = $filter_city;
}

// Add hotel filter if selected
if ($filter_hotel) {
    $filter_conditions .= " AND b.hotel_id = ?";
    $filter_params[] = $filter_hotel;
}

// Add room type filter if selected
if ($filter_room_type) {
    $filter_conditions .= " AND b.tipe_id = ?";
    $filter_params[] = $filter_room_type;
}

// --- ANALYTICS FUNCTIONS ---

// 1. Occupancy Analytics - Visualisasi Hunian Kamar
function get_occupancy_analytics($conn, $year, $month, $period, $filter_conditions = "", $filter_params = [])
{
    $data = [];

    // Ambil filter utama dari global (karena signature fungsi tidak menerima ini)
    $filter_city  = $GLOBALS['filter_city']  ?? '';
    $filter_hotel = $GLOBALS['filter_hotel'] ?? '';
    $filter_date  = $GLOBALS['filter_date']  ?? (date('Y-m-d'));

    // Normalisasi tipe data
    $year  = (int)$year;
    $month = (int)$month;

    // Helper untuk bind param aman (types selalu pas)
    $bind = function ($stmt, $types, $params) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
    };

    // =========================================================
    // A) Total rooms available (jadwal_hotel) - filter: city + hotel
    // =========================================================
    $query_total_rooms = "SELECT COALESCE(SUM(jh.stok_total), 0) as total_rooms
                          FROM jadwal_hotel jh
                          JOIN hotel h ON jh.hotel_id = h.hotel_id
                          WHERE 1=1";

    $room_filter_conditions = "";
    $room_filter_params = [];
    $room_filter_types = "";

    if (!empty($filter_city)) {
        $room_filter_conditions .= " AND h.kota = ?";
        $room_filter_params[] = $filter_city;
        $room_filter_types .= "s";
    }

    if (!empty($filter_hotel)) {
        $room_filter_conditions .= " AND jh.hotel_id = ?";
        $room_filter_params[] = $filter_hotel;
        $room_filter_types .= "s";
    }

    $query_total_rooms .= $room_filter_conditions;

    $stmt_rooms = $conn->prepare($query_total_rooms);
    if ($stmt_rooms === false) {
        die("Prepare failed (total rooms): " . $conn->error);
    }
    $bind($stmt_rooms, $room_filter_types, $room_filter_params);

    $stmt_rooms->execute();
    $result_rooms = $stmt_rooms->get_result();
    $total_rooms = (int)($result_rooms->fetch_assoc()['total_rooms'] ?? 0);
    $data['total_rooms'] = $total_rooms;
    $stmt_rooms->close();

    // Hindari pembagian 0
    if ($total_rooms <= 0) {
        $total_rooms = 1;
    }

    // =========================================================
    // B) Occupancy data utama
    // =========================================================
    if ($period === 'daily') {
        $query = "SELECT 
                    DATE(b.check_in) as date,
                    SUM(b.jumlah_kamar) as rooms_occupied,
                    ROUND((SUM(b.jumlah_kamar) / ?) * 100, 2) as occupancy_rate
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  WHERE b.status = 'Completed'
                    AND DATE(b.check_in) = ?
                    $filter_conditions
                  GROUP BY DATE(b.check_in)";

        $stmt_occ = $conn->prepare($query);
        if ($stmt_occ === false) {
            die("Prepare failed (occupancy daily): " . $conn->error);
        }

        $all_params = array_merge([$total_rooms, $filter_date], $filter_params);
        $types = "is" . str_repeat("s", count($filter_params));
        $bind($stmt_occ, $types, $all_params);
    } elseif ($period === 'weekly') {
        $query = "SELECT 
                    DAYNAME(b.check_in) as day_name,
                    DAYOFWEEK(b.check_in) as day_num,
                    SUM(b.jumlah_kamar) as rooms_occupied,
                    ROUND((SUM(b.jumlah_kamar) / ?) * 100, 2) as occupancy_rate
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  WHERE b.status = 'Completed'
                    AND YEARWEEK(b.check_in, 1) = YEARWEEK(CURDATE(), 1)
                    $filter_conditions
                  GROUP BY DAYOFWEEK(b.check_in), DAYNAME(b.check_in)
                  ORDER BY day_num";

        $stmt_occ = $conn->prepare($query);
        if ($stmt_occ === false) {
            die("Prepare failed (occupancy weekly): " . $conn->error);
        }

        $all_params = array_merge([$total_rooms], $filter_params);
        $types = "i" . str_repeat("s", count($filter_params));
        $bind($stmt_occ, $types, $all_params);
    } elseif ($period === 'yearly') {
        $query = "SELECT 
                    MONTH(b.check_in) as month_num,
                    MONTHNAME(b.check_in) as month_name,
                    SUM(b.jumlah_kamar) as rooms_occupied,
                    ROUND((SUM(b.jumlah_kamar) / ?) * 100, 2) as occupancy_rate
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  WHERE b.status = 'Completed'
                    AND YEAR(b.check_in) = ?
                    $filter_conditions
                  GROUP BY MONTH(b.check_in), MONTHNAME(b.check_in)
                  ORDER BY month_num";

        $stmt_occ = $conn->prepare($query);
        if ($stmt_occ === false) {
            die("Prepare failed (occupancy yearly): " . $conn->error);
        }

        $all_params = array_merge([$total_rooms, $year], $filter_params);
        $types = "ii" . str_repeat("s", count($filter_params));
        $bind($stmt_occ, $types, $all_params);
    } else { // monthly
        $query = "SELECT 
                    DAY(b.check_in) as day,
                    SUM(b.jumlah_kamar) as rooms_occupied,
                    ROUND((SUM(b.jumlah_kamar) / ?) * 100, 2) as occupancy_rate
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  WHERE b.status = 'Completed'
                    AND YEAR(b.check_in) = ?
                    AND MONTH(b.check_in) = ?
                    $filter_conditions
                  GROUP BY DAY(b.check_in)
                  ORDER BY day";

        $stmt_occ = $conn->prepare($query);
        if ($stmt_occ === false) {
            die("Prepare failed (occupancy monthly): " . $conn->error);
        }

        $all_params = array_merge([$total_rooms, $year, $month], $filter_params);
        $types = "iii" . str_repeat("s", count($filter_params));
        $bind($stmt_occ, $types, $all_params);
    }

    $stmt_occ->execute();
    $result = $stmt_occ->get_result();

    $occupancy_data = [];
    $total_occupied = 0;
    $days_count = 0;

    while ($row = $result->fetch_assoc()) {
        $occupancy_data[] = $row;
        $total_occupied += (int)($row['rooms_occupied'] ?? 0);
        $days_count++;
    }

    $data['occupancy_data'] = $occupancy_data;

    // Avg occupancy rate
    if ($days_count > 0) {
        $total_room_days = $total_rooms * $days_count;
        $data['avg_occupancy_rate'] = $total_room_days > 0 ? round(($total_occupied / $total_room_days) * 100, 1) : 0;
    } else {
        $data['avg_occupancy_rate'] = 0;
    }

    $stmt_occ->close();

    // =========================================================
    // C) Most booked room types (FIX mismatch parameter)
    // =========================================================
    if ($period === 'yearly') {
        $query_types = "SELECT 
                          t.nama_tipe,
                          COUNT(b.booking_id) as booking_count,
                          ROUND((COUNT(b.booking_id) / NULLIF((
                              SELECT COUNT(*)
                              FROM booking_hotel b2
                              JOIN hotel h2 ON b2.hotel_id = h2.hotel_id
                              WHERE YEAR(b2.check_in) = ? $filter_conditions
                          ), 0)) * 100, 2) as percentage
                        FROM tipe_kamar t
                        LEFT JOIN booking_hotel b ON t.tipe_id = b.tipe_id
                        JOIN hotel h ON b.hotel_id = h.hotel_id
                        WHERE YEAR(b.check_in) = ?
                          AND b.status = 'Completed'
                          $filter_conditions
                        GROUP BY t.tipe_id, t.nama_tipe
                        HAVING COUNT(b.booking_id) > 0
                        ORDER BY booking_count DESC
                        LIMIT 5";

        $stmt_types = $conn->prepare($query_types);
        if ($stmt_types === false) {
            die("Prepare failed (occupancy room types yearly): " . $conn->error);
        }

        $all_params = array_merge([$year], $filter_params, [$year], $filter_params);

        // Aman: types = jumlah param (hindari mismatch)
        $types = str_repeat('s', count($all_params));
        $bind($stmt_types, $types, $all_params);
    } else {
        $query_types = "SELECT 
                          t.nama_tipe,
                          COUNT(b.booking_id) as booking_count,
                          ROUND((COUNT(b.booking_id) / NULLIF((
                              SELECT COUNT(*)
                              FROM booking_hotel b2
                              JOIN hotel h2 ON b2.hotel_id = h2.hotel_id
                              WHERE YEAR(b2.check_in) = ? AND MONTH(b2.check_in) = ? $filter_conditions
                          ), 0)) * 100, 2) as percentage
                        FROM tipe_kamar t
                        LEFT JOIN booking_hotel b ON t.tipe_id = b.tipe_id
                        JOIN hotel h ON b.hotel_id = h.hotel_id
                        WHERE YEAR(b.check_in) = ? 
                          AND MONTH(b.check_in) = ?
                          AND b.status = 'Completed'
                          $filter_conditions
                        GROUP BY t.tipe_id, t.nama_tipe
                        HAVING COUNT(b.booking_id) > 0
                        ORDER BY booking_count DESC
                        LIMIT 5";

        $stmt_types = $conn->prepare($query_types);
        if ($stmt_types === false) {
            die("Prepare failed (occupancy room types monthly): " . $conn->error);
        }

        $all_params = array_merge([$year, $month], $filter_params, [$year, $month], $filter_params);

        // Aman: types = jumlah param (hindari mismatch)
        $types = str_repeat('s', count($all_params));
        $bind($stmt_types, $types, $all_params);
    }

    $stmt_types->execute();
    $result_types = $stmt_types->get_result();

    $room_types = [];
    while ($row = $result_types->fetch_assoc()) {
        $room_types[] = $row;
    }
    $data['top_room_types'] = $room_types;
    $stmt_types->close();

    // =========================================================
    // D) Occupancy by location
    // =========================================================
    if ($period === 'yearly') {
        $query_location = "SELECT 
                             h.kota,
                             SUM(b.jumlah_kamar) as total_occupied,
                             COUNT(b.booking_id) as booking_count,
                             COALESCE(ROUND((SUM(b.jumlah_kamar) / NULLIF((
                                 SELECT SUM(jh.stok_total)
                                 FROM jadwal_hotel jh
                                 JOIN hotel h2 ON jh.hotel_id = h2.hotel_id
                                 WHERE h2.kota = h.kota
                             ), 0)) * 100, 2), 0) as occupancy_rate
                           FROM hotel h
                           LEFT JOIN booking_hotel b ON h.hotel_id = b.hotel_id
                           WHERE YEAR(b.check_in) = ?
                             AND b.status = 'Completed'
                             $filter_conditions
                           GROUP BY h.kota
                           HAVING SUM(b.jumlah_kamar) > 0
                           ORDER BY occupancy_rate DESC";

        $stmt_loc = $conn->prepare($query_location);
        if ($stmt_loc === false) {
            die("Prepare failed (occupancy by location yearly): " . $conn->error);
        }

        $all_params = array_merge([$year], $filter_params);
        $types = "i" . str_repeat("s", count($filter_params));
        $bind($stmt_loc, $types, $all_params);
    } else {
        $query_location = "SELECT 
                             h.kota,
                             SUM(b.jumlah_kamar) as total_occupied,
                             COUNT(b.booking_id) as booking_count,
                             COALESCE(ROUND((SUM(b.jumlah_kamar) / NULLIF((
                                 SELECT SUM(jh.stok_total)
                                 FROM jadwal_hotel jh
                                 JOIN hotel h2 ON jh.hotel_id = h2.hotel_id
                                 WHERE h2.kota = h.kota
                             ), 0)) * 100, 2), 0) as occupancy_rate
                           FROM hotel h
                           LEFT JOIN booking_hotel b ON h.hotel_id = b.hotel_id
                           WHERE YEAR(b.check_in) = ? AND MONTH(b.check_in) = ?
                             AND b.status = 'Completed'
                             $filter_conditions
                           GROUP BY h.kota
                           HAVING SUM(b.jumlah_kamar) > 0
                           ORDER BY occupancy_rate DESC";

        $stmt_loc = $conn->prepare($query_location);
        if ($stmt_loc === false) {
            die("Prepare failed (occupancy by location monthly): " . $conn->error);
        }

        $all_params = array_merge([$year, $month], $filter_params);
        $types = "ii" . str_repeat("s", count($filter_params));
        $bind($stmt_loc, $types, $all_params);
    }

    $stmt_loc->execute();
    $result_loc = $stmt_loc->get_result();

    $location_data = [];
    while ($row = $result_loc->fetch_assoc()) {
        $location_data[] = $row;
    }
    $data['occupancy_by_location'] = $location_data;

    $stmt_loc->close();

    return $data;
}


// 2. Revenue Analytics - Visualisasi Pendapatan (OPSI B - FIXED)
function get_revenue_analytics($conn, $year, $month, $period, $filter_conditions = "", $filter_params = [])
{
    $data = [];

    // Ambil filter utama dari global
    $filter_city  = $GLOBALS['filter_city']  ?? '';
    $filter_hotel = $GLOBALS['filter_hotel'] ?? '';
    $filter_date  = $GLOBALS['filter_date']  ?? ($_GET['date'] ?? date('Y-m-d'));

    // Normalisasi tipe
    $year  = (int)$year;
    $month = (int)$month;

    // Helper bind aman
    $bind = function ($stmt, $types, $params) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
    };

    // =========================================================
    // A) Revenue trend utama
    // =========================================================
    if ($period === 'daily') {
        $query = "SELECT 
                    DATE(b.check_in) as date,
                    COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as revenue,
                    COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed_bookings,
                    COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0) as avg_booking_value
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  WHERE DATE(b.check_in) = ?
                    $filter_conditions
                  GROUP BY DATE(b.check_in)";

        $stmt_main = $conn->prepare($query);
        if ($stmt_main === false) die("Prepare failed (revenue daily): " . $conn->error);

        $all_params = array_merge([$filter_date], $filter_params);
        $types = "s" . str_repeat("s", count($filter_params));
        $bind($stmt_main, $types, $all_params);
    } elseif ($period === 'yearly') {
        $query = "SELECT 
                    MONTH(b.check_in) as month_num,
                    MONTHNAME(b.check_in) as month_name,
                    COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as revenue,
                    COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed_bookings,
                    COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0) as avg_booking_value
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  WHERE YEAR(b.check_in) = ?
                    $filter_conditions
                  GROUP BY MONTH(b.check_in), MONTHNAME(b.check_in)
                  ORDER BY month_num";

        $stmt_main = $conn->prepare($query);
        if ($stmt_main === false) die("Prepare failed (revenue yearly): " . $conn->error);

        $all_params = array_merge([$year], $filter_params);
        $types = "i" . str_repeat("s", count($filter_params));
        $bind($stmt_main, $types, $all_params);
    } else { // monthly
        $query = "SELECT 
                    DATE(b.check_in) as date,
                    COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as revenue,
                    COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed_bookings,
                    COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0) as avg_booking_value
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  WHERE YEAR(b.check_in) = ? AND MONTH(b.check_in) = ?
                    $filter_conditions
                  GROUP BY DATE(b.check_in)
                  ORDER BY date";

        $stmt_main = $conn->prepare($query);
        if ($stmt_main === false) die("Prepare failed (revenue monthly): " . $conn->error);

        $all_params = array_merge([$year, $month], $filter_params);
        $types = "ii" . str_repeat("s", count($filter_params));
        $bind($stmt_main, $types, $all_params);
    }

    $stmt_main->execute();
    $result = $stmt_main->get_result();

    $revenue_data = [];
    $total_revenue = 0;
    $total_bookings = 0;

    while ($row = $result->fetch_assoc()) {
        $revenue_data[] = $row;
        $total_revenue  += (float)($row['revenue'] ?? 0);
        $total_bookings += (int)($row['completed_bookings'] ?? 0);
    }

    $data['revenue_data']   = $revenue_data;
    $data['total_revenue']  = $total_revenue;
    $data['total_bookings'] = $total_bookings;

    $stmt_main->close();

    // =========================================================
    // B) Revenue by room type (FIX: bookings hanya Completed)
    // =========================================================
    if ($period === 'yearly') {
        $query_types = "SELECT 
                          t.nama_tipe,
                          COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as revenue,
                          COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as bookings,
                          COALESCE(ROUND(
                              SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) 
                              / NULLIF(COUNT(CASE WHEN b.status = 'Completed' THEN 1 END), 0)
                          , 0), 0) as avg_revenue_per_booking
                        FROM tipe_kamar t
                        LEFT JOIN booking_hotel b ON t.tipe_id = b.tipe_id
                        JOIN hotel h ON b.hotel_id = h.hotel_id
                        WHERE YEAR(b.check_in) = ?
                          $filter_conditions
                        GROUP BY t.tipe_id, t.nama_tipe
                        HAVING COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) > 0
                        ORDER BY revenue DESC";

        $stmt_types = $conn->prepare($query_types);
        if ($stmt_types === false) die("Prepare failed (revenue by type yearly): " . $conn->error);

        $all_params = array_merge([$year], $filter_params);
        $types = "i" . str_repeat("s", count($filter_params));
        $bind($stmt_types, $types, $all_params);
    } else {
        $query_types = "SELECT 
                          t.nama_tipe,
                          COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as revenue,
                          COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as bookings,
                          COALESCE(ROUND(
                              SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) 
                              / NULLIF(COUNT(CASE WHEN b.status = 'Completed' THEN 1 END), 0)
                          , 0), 0) as avg_revenue_per_booking
                        FROM tipe_kamar t
                        LEFT JOIN booking_hotel b ON t.tipe_id = b.tipe_id
                        JOIN hotel h ON b.hotel_id = h.hotel_id
                        WHERE YEAR(b.check_in) = ? AND MONTH(b.check_in) = ?
                          $filter_conditions
                        GROUP BY t.tipe_id, t.nama_tipe
                        HAVING COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) > 0
                        ORDER BY revenue DESC";

        $stmt_types = $conn->prepare($query_types);
        if ($stmt_types === false) die("Prepare failed (revenue by type monthly): " . $conn->error);

        $all_params = array_merge([$year, $month], $filter_params);
        $types = "ii" . str_repeat("s", count($filter_params));
        $bind($stmt_types, $types, $all_params);
    }

    $stmt_types->execute();
    $result_types = $stmt_types->get_result();

    $revenue_by_type = [];
    while ($row = $result_types->fetch_assoc()) {
        $revenue_by_type[] = $row;
    }
    $data['revenue_by_type'] = $revenue_by_type;

    $stmt_types->close();

    // =========================================================
    // C) ADR
    // =========================================================
    if ($period === 'yearly') {
        $query_adr = "SELECT 
                        COALESCE(ROUND(
                            SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END)
                            / NULLIF(SUM(DATEDIFF(b.check_out, b.check_in)), 0)
                        , 0), 0) as adr
                      FROM booking_hotel b
                      JOIN hotel h ON b.hotel_id = h.hotel_id
                      WHERE YEAR(b.check_in) = ?
                        AND b.status = 'Completed'
                        AND DATEDIFF(b.check_out, b.check_in) > 0
                        $filter_conditions";

        $stmt_adr = $conn->prepare($query_adr);
        if ($stmt_adr === false) die("Prepare failed (ADR yearly): " . $conn->error);

        $all_params = array_merge([$year], $filter_params);
        $types = "i" . str_repeat("s", count($filter_params));
        $bind($stmt_adr, $types, $all_params);
    } else {
        $query_adr = "SELECT 
                        COALESCE(ROUND(
                            SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END)
                            / NULLIF(SUM(DATEDIFF(b.check_out, b.check_in)), 0)
                        , 0), 0) as adr
                      FROM booking_hotel b
                      JOIN hotel h ON b.hotel_id = h.hotel_id
                      WHERE YEAR(b.check_in) = ? AND MONTH(b.check_in) = ?
                        AND b.status = 'Completed'
                        AND DATEDIFF(b.check_out, b.check_in) > 0
                        $filter_conditions";

        $stmt_adr = $conn->prepare($query_adr);
        if ($stmt_adr === false) die("Prepare failed (ADR monthly): " . $conn->error);

        $all_params = array_merge([$year, $month], $filter_params);
        $types = "ii" . str_repeat("s", count($filter_params));
        $bind($stmt_adr, $types, $all_params);
    }

    $stmt_adr->execute();
    $result_adr = $stmt_adr->get_result();
    $adr_data = $result_adr->fetch_assoc();
    $data['adr'] = $adr_data['adr'] ?? 0;
    $stmt_adr->close();

    // =========================================================
    // D) RevPAR (Revenue Per Available Room)
    // total rooms query TIDAK pakai $filter_conditions mentah
    // =========================================================
    $query_total_rooms = "SELECT COALESCE(SUM(jh.stok_total), 0) as total_rooms
                          FROM jadwal_hotel jh
                          JOIN hotel h ON jh.hotel_id = h.hotel_id
                          WHERE 1=1";

    $room_filter_conditions = "";
    $room_filter_params = [];
    $room_filter_types = "";

    if (!empty($filter_city)) {
        $room_filter_conditions .= " AND h.kota = ?";
        $room_filter_params[] = $filter_city;
        $room_filter_types .= "s";
    }
    if (!empty($filter_hotel)) {
        $room_filter_conditions .= " AND jh.hotel_id = ?";
        $room_filter_params[] = $filter_hotel; // boleh string, aman
        $room_filter_types .= "s";
    }

    $query_total_rooms .= $room_filter_conditions;

    $stmt_rooms = $conn->prepare($query_total_rooms);
    if ($stmt_rooms === false) die("Prepare failed (RevPAR total rooms): " . $conn->error);

    $bind($stmt_rooms, $room_filter_types, $room_filter_params);

    $stmt_rooms->execute();
    $result_rooms = $stmt_rooms->get_result();
    $total_rooms = (int)($result_rooms->fetch_assoc()['total_rooms'] ?? 0);
    $stmt_rooms->close();

    if ($total_rooms <= 0) $total_rooms = 1;

    if ($period === 'yearly') {
        $days_in_year = date('L', strtotime($year . '-01-01')) ? 366 : 365;
        $data['revpar'] = round($total_revenue / ($total_rooms * $days_in_year), 0);
    } else {
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $data['revpar'] = round($total_revenue / ($total_rooms * $days_in_month), 0);
    }

    // =========================================================
    // E) Revenue by hotel location (FIX: bookings hanya Completed)
    // =========================================================
    if ($period === 'yearly') {
        $query_location_revenue = "SELECT 
                                     h.kota,
                                     COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as revenue,
                                     COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as bookings,
                                     COALESCE(ROUND(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0), 0) as avg_revenue
                                   FROM hotel h
                                   LEFT JOIN booking_hotel b ON h.hotel_id = b.hotel_id
                                   WHERE YEAR(b.check_in) = ?
                                     $filter_conditions
                                   GROUP BY h.kota
                                   HAVING COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) > 0
                                   ORDER BY revenue DESC";

        $stmt_loc = $conn->prepare($query_location_revenue);
        if ($stmt_loc === false) die("Prepare failed (revenue by location yearly): " . $conn->error);

        $all_params = array_merge([$year], $filter_params);
        $types = "i" . str_repeat("s", count($filter_params));
        $bind($stmt_loc, $types, $all_params);
    } else {
        $query_location_revenue = "SELECT 
                                     h.kota,
                                     COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as revenue,
                                     COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as bookings,
                                     COALESCE(ROUND(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0), 0) as avg_revenue
                                   FROM hotel h
                                   LEFT JOIN booking_hotel b ON h.hotel_id = b.hotel_id
                                   WHERE YEAR(b.check_in) = ? AND MONTH(b.check_in) = ?
                                     $filter_conditions
                                   GROUP BY h.kota
                                   HAVING COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) > 0
                                   ORDER BY revenue DESC";

        $stmt_loc = $conn->prepare($query_location_revenue);
        if ($stmt_loc === false) die("Prepare failed (revenue by location monthly): " . $conn->error);

        $all_params = array_merge([$year, $month], $filter_params);
        $types = "ii" . str_repeat("s", count($filter_params));
        $bind($stmt_loc, $types, $all_params);
    }
    // =========================================================
    // F) Top Revenue Bookings (booking completed dengan nilai terbesar)
    // =========================================================
    if ($period === 'yearly') {
        $query_top_bookings = "SELECT
                             b.booking_id,
                             h.nama_hotel,
                             h.kota,
                             t.nama_tipe,
                             b.check_in,
                             b.check_out,
                             b.total_harga
                           FROM booking_hotel b
                           JOIN hotel h ON b.hotel_id = h.hotel_id
                           JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
                           WHERE YEAR(b.check_in) = ?
                             AND b.status = 'Completed'
                             $filter_conditions
                           ORDER BY b.total_harga DESC
                           LIMIT 10";

        $stmt_top = $conn->prepare($query_top_bookings);
        if ($stmt_top === false) {
            die("Prepare failed (top revenue bookings yearly): " . $conn->error);
        }

        $all_params = array_merge([$year], $filter_params);
        $types = "i" . str_repeat("s", count($filter_params));
        $bind($stmt_top, $types, $all_params);
    } else {
        $query_top_bookings = "SELECT
                             b.booking_id,
                             h.nama_hotel,
                             h.kota,
                             t.nama_tipe,
                             b.check_in,
                             b.check_out,
                             b.total_harga
                           FROM booking_hotel b
                           JOIN hotel h ON b.hotel_id = h.hotel_id
                           JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
                           WHERE YEAR(b.check_in) = ?
                             AND MONTH(b.check_in) = ?
                             AND b.status = 'Completed'
                             $filter_conditions
                           ORDER BY b.total_harga DESC
                           LIMIT 10";

        $stmt_top = $conn->prepare($query_top_bookings);
        if ($stmt_top === false) {
            die("Prepare failed (top revenue bookings monthly): " . $conn->error);
        }

        $all_params = array_merge([$year, $month], $filter_params);
        $types = "ii" . str_repeat("s", count($filter_params));
        $bind($stmt_top, $types, $all_params);
    }

    $stmt_top->execute();
    $result_top = $stmt_top->get_result();

    $top_revenue_bookings = [];
    while ($row = $result_top->fetch_assoc()) {
        $top_revenue_bookings[] = $row;
    }
    $data['top_revenue_bookings'] = $top_revenue_bookings;

    $stmt_top->close();

    $stmt_loc->execute();
    $result_loc = $stmt_loc->get_result();

    $location_revenue = [];
    while ($row = $result_loc->fetch_assoc()) {
        $location_revenue[] = $row;
    }
    $data['revenue_by_location'] = $location_revenue;

    $stmt_loc->close();

    return $data;
}

// 3. Booking Analytics - Visualisasi Reservasi
function get_booking_analytics($conn, $year, $month, $period, $filter_conditions = "", $filter_params = [])
{
    // ====== Default keys supaya view gak warning ======
    $data = [
        'booking_data' => [],
        'booking_methods' => [],
        'leadtime_analysis' => [],
        'avg_lead_time' => 0,
        'booking_pattern' => [],
        'total_bookings' => 0,
        'total_completed' => 0,
        'total_cancelled' => 0,
        'success_rate' => 0,
        'cancellation_rate' => 0,
    ];

    // Ambil date dari global filter_date (lebih aman daripada $_GET langsung)
    $filter_date = $GLOBALS['filter_date'] ?? ($_GET['date'] ?? date('Y-m-d'));

    // Helper: bind param aman (types selalu pas jumlah param)
    $bind_stmt = function ($stmt, $params) {
        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
    };

    // ==========================================================
    // A) Booking trend (daily / monthly / yearly)
    // ==========================================================
    if ($period === 'daily') {
        $query = "SELECT 
                    HOUR(b.tanggal_booking) as hour,
                    COUNT(*) as total_bookings,
                    COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed,
                    COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancelled
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  WHERE DATE(b.tanggal_booking) = ?
                    $filter_conditions
                  GROUP BY HOUR(b.tanggal_booking)
                  ORDER BY hour";

        $stmt_main = $conn->prepare($query);
        if ($stmt_main === false) die("Prepare failed (booking trend daily): " . $conn->error);

        $all_params = array_merge([$filter_date], $filter_params);
        $bind_stmt($stmt_main, $all_params);
    } elseif ($period === 'yearly') {
        $query = "SELECT 
                    MONTH(b.tanggal_booking) as month_num,
                    MONTHNAME(b.tanggal_booking) as month_name,
                    COUNT(*) as total_bookings,
                    COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed,
                    COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancelled
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  WHERE YEAR(b.tanggal_booking) = ?
                    $filter_conditions
                  GROUP BY MONTH(b.tanggal_booking), MONTHNAME(b.tanggal_booking)
                  ORDER BY month_num";

        $stmt_main = $conn->prepare($query);
        if ($stmt_main === false) die("Prepare failed (booking trend yearly): " . $conn->error);

        $all_params = array_merge([$year], $filter_params);
        $bind_stmt($stmt_main, $all_params);
    } else { // monthly
        $query = "SELECT 
                    DAY(b.tanggal_booking) as day,
                    COUNT(*) as total_bookings,
                    COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed,
                    COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancelled
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
                    $filter_conditions
                  GROUP BY DAY(b.tanggal_booking)
                  ORDER BY day";

        $stmt_main = $conn->prepare($query);
        if ($stmt_main === false) die("Prepare failed (booking trend monthly): " . $conn->error);

        $all_params = array_merge([$year, $month], $filter_params);
        $bind_stmt($stmt_main, $all_params);
    }

    if (!$stmt_main->execute()) die("Execute failed (booking trend): " . $stmt_main->error);

    $result = $stmt_main->get_result();
    $total_bookings = 0;
    $total_completed = 0;
    $total_cancelled = 0;

    while ($row = $result->fetch_assoc()) {
        $data['booking_data'][] = $row;
        $total_bookings += (int)($row['total_bookings'] ?? 0);
        $total_completed += (int)($row['completed'] ?? 0);
        $total_cancelled += (int)($row['cancelled'] ?? 0);
    }

    $data['total_bookings'] = $total_bookings;
    $data['total_completed'] = $total_completed;
    $data['total_cancelled'] = $total_cancelled;
    $data['success_rate'] = $total_bookings > 0 ? round(($total_completed / $total_bookings) * 100, 1) : 0;
    $data['cancellation_rate'] = $total_bookings > 0 ? round(($total_cancelled / $total_bookings) * 100, 1) : 0;

    $stmt_main->close();

    // ==========================================================
    // B) Booking method distribution (yang sering mismatch)
    //    NOTE: YEAR/MONTH muncul 2x (subquery + main query) + filter_params 2x
    // ==========================================================
    if ($period === 'yearly') {
        $query_method = "SELECT 
                          COALESCE(b.metode_pembayaran, 'Unknown') as source,
                          COUNT(*) as count,
                          ROUND((COUNT(*) / NULLIF((
                              SELECT COUNT(*)
                              FROM booking_hotel b2 
                              JOIN hotel h2 ON b2.hotel_id = h2.hotel_id
                              WHERE YEAR(b2.tanggal_booking) = ? $filter_conditions
                          ), 0)) * 100, 1) as percentage
                        FROM booking_hotel b
                        JOIN hotel h ON b.hotel_id = h.hotel_id
                        WHERE YEAR(b.tanggal_booking) = ?
                          $filter_conditions
                        GROUP BY b.metode_pembayaran
                        ORDER BY count DESC";

        $stmt_method = $conn->prepare($query_method);
        if ($stmt_method === false) die("Prepare failed (booking methods yearly): " . $conn->error);

        $all_params = array_merge([$year], $filter_params, [$year], $filter_params);
        $bind_stmt($stmt_method, $all_params);
    } else {
        $query_method = "SELECT 
                          COALESCE(b.metode_pembayaran, 'Unknown') as source,
                          COUNT(*) as count,
                          ROUND((COUNT(*) / NULLIF((
                              SELECT COUNT(*)
                              FROM booking_hotel b2 
                              JOIN hotel h2 ON b2.hotel_id = h2.hotel_id
                              WHERE YEAR(b2.tanggal_booking) = ? 
                                AND MONTH(b2.tanggal_booking) = ? 
                                $filter_conditions
                          ), 0)) * 100, 1) as percentage
                        FROM booking_hotel b
                        JOIN hotel h ON b.hotel_id = h.hotel_id
                        WHERE YEAR(b.tanggal_booking) = ? 
                          AND MONTH(b.tanggal_booking) = ?
                          $filter_conditions
                        GROUP BY b.metode_pembayaran
                        ORDER BY count DESC";

        $stmt_method = $conn->prepare($query_method);
        if ($stmt_method === false) die("Prepare failed (booking methods monthly): " . $conn->error);

        $all_params = array_merge([$year, $month], $filter_params, [$year, $month], $filter_params);
        $bind_stmt($stmt_method, $all_params);
    }

    if (!$stmt_method->execute()) die("Execute failed (booking methods): " . $stmt_method->error);

    $result = $stmt_method->get_result();
    while ($row = $result->fetch_assoc()) {
        $data['booking_methods'][] = $row;
    }
    $stmt_method->close();

    // ==========================================================
    // C) Lead time analysis
    // ==========================================================
    if ($period === 'yearly') {
        $query_leadtime = "SELECT 
                            DATEDIFF(b.check_in, b.tanggal_booking) as lead_time_days,
                            COUNT(*) as booking_count
                          FROM booking_hotel b
                          JOIN hotel h ON b.hotel_id = h.hotel_id
                          WHERE YEAR(b.tanggal_booking) = ?
                            AND b.check_in > b.tanggal_booking
                            AND b.status = 'Completed'
                            $filter_conditions
                          GROUP BY DATEDIFF(b.check_in, b.tanggal_booking)
                          ORDER BY booking_count DESC
                          LIMIT 10";

        $stmt_lead = $conn->prepare($query_leadtime);
        if ($stmt_lead === false) die("Prepare failed (lead time yearly): " . $conn->error);

        $all_params = array_merge([$year], $filter_params);
        $bind_stmt($stmt_lead, $all_params);
    } else {
        $query_leadtime = "SELECT 
                            DATEDIFF(b.check_in, b.tanggal_booking) as lead_time_days,
                            COUNT(*) as booking_count
                          FROM booking_hotel b
                          JOIN hotel h ON b.hotel_id = h.hotel_id
                          WHERE YEAR(b.tanggal_booking) = ?
                            AND MONTH(b.tanggal_booking) = ?
                            AND b.check_in > b.tanggal_booking
                            AND b.status = 'Completed'
                            $filter_conditions
                          GROUP BY DATEDIFF(b.check_in, b.tanggal_booking)
                          ORDER BY booking_count DESC
                          LIMIT 10";

        $stmt_lead = $conn->prepare($query_leadtime);
        if ($stmt_lead === false) die("Prepare failed (lead time monthly): " . $conn->error);

        $all_params = array_merge([$year, $month], $filter_params);
        $bind_stmt($stmt_lead, $all_params);
    }

    if (!$stmt_lead->execute()) die("Execute failed (lead time): " . $stmt_lead->error);

    $result = $stmt_lead->get_result();
    while ($row = $result->fetch_assoc()) {
        $data['leadtime_analysis'][] = $row;
    }
    $stmt_lead->close();

    // ==========================================================
    // D) Average lead time
    // ==========================================================
    if ($period === 'yearly') {
        $query_avg = "SELECT 
                        COALESCE(ROUND(AVG(DATEDIFF(b.check_in, b.tanggal_booking)), 1), 0) as avg_lead_time
                      FROM booking_hotel b
                      JOIN hotel h ON b.hotel_id = h.hotel_id
                      WHERE YEAR(b.tanggal_booking) = ?
                        AND b.check_in > b.tanggal_booking
                        AND b.status = 'Completed'
                        $filter_conditions";

        $stmt_avg = $conn->prepare($query_avg);
        if ($stmt_avg === false) die("Prepare failed (avg lead time yearly): " . $conn->error);

        $all_params = array_merge([$year], $filter_params);
        $bind_stmt($stmt_avg, $all_params);
    } else {
        $query_avg = "SELECT 
                        COALESCE(ROUND(AVG(DATEDIFF(b.check_in, b.tanggal_booking)), 1), 0) as avg_lead_time
                      FROM booking_hotel b
                      JOIN hotel h ON b.hotel_id = h.hotel_id
                      WHERE YEAR(b.tanggal_booking) = ?
                        AND MONTH(b.tanggal_booking) = ?
                        AND b.check_in > b.tanggal_booking
                        AND b.status = 'Completed'
                        $filter_conditions";

        $stmt_avg = $conn->prepare($query_avg);
        if ($stmt_avg === false) die("Prepare failed (avg lead time monthly): " . $conn->error);

        $all_params = array_merge([$year, $month], $filter_params);
        $bind_stmt($stmt_avg, $all_params);
    }

    if (!$stmt_avg->execute()) die("Execute failed (avg lead time): " . $stmt_avg->error);

    $res_avg = $stmt_avg->get_result();
    $row_avg = $res_avg->fetch_assoc();
    $data['avg_lead_time'] = $row_avg['avg_lead_time'] ?? 0;

    $stmt_avg->close();

    // ==========================================================
    // E) Booking pattern by room type
    // ==========================================================
    if ($period === 'yearly') {
        $query_pattern = "SELECT 
                    t.nama_tipe,
                    CASE DAYOFWEEK(b.tanggal_booking)
                      WHEN 2 THEN 'Senin'
                      WHEN 3 THEN 'Selasa'
                      WHEN 4 THEN 'Rabu'
                      WHEN 5 THEN 'Kamis'
                      WHEN 6 THEN 'Jumat'
                      WHEN 7 THEN 'Sabtu'
                      WHEN 1 THEN 'Minggu'
                    END as day_of_week,
                    COUNT(b.booking_id) as booking_count,
                    COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_stay
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
                  WHERE YEAR(b.tanggal_booking) = ?
                    AND b.status = 'Completed'
                    $filter_conditions
                  GROUP BY t.nama_tipe, DAYOFWEEK(b.tanggal_booking)
                  HAVING COUNT(b.booking_id) > 0
                  ORDER BY t.nama_tipe,
                  FIELD(
                    CASE DAYOFWEEK(b.tanggal_booking)
                      WHEN 2 THEN 'Senin'
                      WHEN 3 THEN 'Selasa'
                      WHEN 4 THEN 'Rabu'
                      WHEN 5 THEN 'Kamis'
                      WHEN 6 THEN 'Jumat'
                      WHEN 7 THEN 'Sabtu'
                      WHEN 1 THEN 'Minggu'
                    END,
                    'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'
                  )";

        $stmt_pat = $conn->prepare($query_pattern);
        if ($stmt_pat === false) die("Prepare failed (pattern yearly): " . $conn->error);

        $all_params = array_merge([$year], $filter_params);
        $bind_stmt($stmt_pat, $all_params);
    } else {
        $query_pattern = "SELECT 
                    t.nama_tipe,
                    CASE DAYOFWEEK(b.tanggal_booking)
                      WHEN 2 THEN 'Senin'
                      WHEN 3 THEN 'Selasa'
                      WHEN 4 THEN 'Rabu'
                      WHEN 5 THEN 'Kamis'
                      WHEN 6 THEN 'Jumat'
                      WHEN 7 THEN 'Sabtu'
                      WHEN 1 THEN 'Minggu'
                    END as day_of_week,
                    COUNT(b.booking_id) as booking_count,
                    COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_stay
                  FROM booking_hotel b
                  JOIN hotel h ON b.hotel_id = h.hotel_id
                  JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
                  WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
                    AND b.status = 'Completed'
                    $filter_conditions
                  GROUP BY t.nama_tipe, DAYOFWEEK(b.tanggal_booking)
                  HAVING COUNT(b.booking_id) > 0
                  ORDER BY t.nama_tipe,
                  FIELD(
                    CASE DAYOFWEEK(b.tanggal_booking)
                      WHEN 2 THEN 'Senin'
                      WHEN 3 THEN 'Selasa'
                      WHEN 4 THEN 'Rabu'
                      WHEN 5 THEN 'Kamis'
                      WHEN 6 THEN 'Jumat'
                      WHEN 7 THEN 'Sabtu'
                      WHEN 1 THEN 'Minggu'
                    END,
                    'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'
                  )";

        $stmt_pat = $conn->prepare($query_pattern);
        if ($stmt_pat === false) die("Prepare failed (pattern monthly): " . $conn->error);

        $all_params = array_merge([$year, $month], $filter_params);
        $bind_stmt($stmt_pat, $all_params);
    }

    if (!$stmt_pat->execute()) die("Execute failed (pattern): " . $stmt_pat->error);

    $result = $stmt_pat->get_result();
    while ($row = $result->fetch_assoc()) {
        $data['booking_pattern'][] = $row;
    }
    $stmt_pat->close();

    return $data;
}

// 4. Room Performance Analytics - Visualisasi Performa Kamar
function get_room_performance_analytics($conn, $year, $month, $period, $filter_conditions = "", $filter_params = [])
{
    $data = [];

    // Helper kecil biar aman (optional)
    $bindAll = function ($stmt, $params) {
        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
    };

    // =========================
    // A) Most frequently booked rooms
    // =========================
    if ($period === 'yearly') {
        $query_popular_rooms = "SELECT 
                                  h.nama_hotel,
                                  t.nama_tipe,
                                  COUNT(b.booking_id) as booking_count,
                                  COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue,
                                  COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_length_of_stay,
                                  ROUND((COUNT(b.booking_id) / NULLIF((
                                      SELECT COUNT(*) 
                                      FROM booking_hotel b2 
                                      JOIN hotel h2 ON b2.hotel_id = h2.hotel_id 
                                      WHERE YEAR(b2.check_in) = ? 
                                        AND b2.status = 'Completed'
                                        $filter_conditions
                                  ), 0)) * 100, 1) as market_share
                                FROM hotel h
                                JOIN booking_hotel b ON h.hotel_id = b.hotel_id
                                JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
                                WHERE YEAR(b.check_in) = ?
                                  AND b.status = 'Completed'
                                  $filter_conditions
                                GROUP BY h.hotel_id, h.nama_hotel, t.tipe_id, t.nama_tipe
                                HAVING COUNT(b.booking_id) > 0
                                ORDER BY booking_count DESC
                                LIMIT 10";
        $stmt = $conn->prepare($query_popular_rooms);
        if (!$stmt) {
            die("Prepare failed (popular rooms yearly): " . $conn->error);
        }

        // Placeholder order:
        // 1) YEAR(b2.check_in) = ?
        // + filter_params (subquery)
        // 2) YEAR(b.check_in) = ?
        // + filter_params (main)
        $all_params = array_merge([$year], $filter_params, [$year], $filter_params);
        $bindAll($stmt, $all_params);
    } else {
        $query_popular_rooms = "SELECT 
                                  h.nama_hotel,
                                  t.nama_tipe,
                                  COUNT(b.booking_id) as booking_count,
                                  COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue,
                                  COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_length_of_stay,
                                  ROUND((COUNT(b.booking_id) / NULLIF((
                                      SELECT COUNT(*) 
                                      FROM booking_hotel b2 
                                      JOIN hotel h2 ON b2.hotel_id = h2.hotel_id 
                                      WHERE YEAR(b2.check_in) = ? 
                                        AND MONTH(b2.check_in) = ?
                                        AND b2.status = 'Completed'
                                        $filter_conditions
                                  ), 0)) * 100, 1) as market_share
                                FROM hotel h
                                JOIN booking_hotel b ON h.hotel_id = b.hotel_id
                                JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
                                WHERE YEAR(b.check_in) = ? 
                                  AND MONTH(b.check_in) = ?
                                  AND b.status = 'Completed'
                                  $filter_conditions
                                GROUP BY h.hotel_id, h.nama_hotel, t.tipe_id, t.nama_tipe
                                HAVING COUNT(b.booking_id) > 0
                                ORDER BY booking_count DESC
                                LIMIT 10";
        $stmt = $conn->prepare($query_popular_rooms);
        if (!$stmt) {
            die("Prepare failed (popular rooms monthly): " . $conn->error);
        }

        // Placeholder order:
        // subquery: YEAR ?, MONTH ? + filter_params
        // main:    YEAR ?, MONTH ? + filter_params
        $all_params = array_merge([$year, $month], $filter_params, [$year, $month], $filter_params);
        $bindAll($stmt, $all_params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $popular_rooms = [];
    while ($row = $result->fetch_assoc()) {
        $popular_rooms[] = $row;
    }
    $data['popular_rooms'] = $popular_rooms;
    $stmt->close();

    // =========================
    // B) Room types by performance metrics
    // =========================
    if ($period === 'yearly') {
        $query_roomtype_revenue = "SELECT 
                                     t.nama_tipe,
                                     COUNT(b.booking_id) as total_bookings,
                                     COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue,
                                     COALESCE(ROUND(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0), 0) as avg_revenue_per_booking,
                                     COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_length_of_stay,
                                     ROUND((COUNT(b.booking_id) * 100.0 / NULLIF((
                                         SELECT COUNT(*) 
                                         FROM booking_hotel b2 
                                         JOIN hotel h2 ON b2.hotel_id = h2.hotel_id 
                                         WHERE YEAR(b2.check_in) = ? 
                                           AND b2.status = 'Completed'
                                           $filter_conditions
                                     ), 0)), 1) as booking_percentage
                                   FROM tipe_kamar t
                                   LEFT JOIN booking_hotel b ON t.tipe_id = b.tipe_id 
                                   JOIN hotel h ON b.hotel_id = h.hotel_id
                                   WHERE YEAR(b.check_in) = ?
                                     AND b.status = 'Completed'
                                     $filter_conditions
                                   GROUP BY t.tipe_id, t.nama_tipe
                                   HAVING COUNT(b.booking_id) > 0
                                   ORDER BY total_revenue DESC";
        $stmt = $conn->prepare($query_roomtype_revenue);
        if (!$stmt) {
            die("Prepare failed (roomtype revenue yearly): " . $conn->error);
        }

        $all_params = array_merge([$year], $filter_params, [$year], $filter_params);
        $bindAll($stmt, $all_params);
    } else {
        $query_roomtype_revenue = "SELECT 
                                     t.nama_tipe,
                                     COUNT(b.booking_id) as total_bookings,
                                     COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue,
                                     COALESCE(ROUND(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0), 0) as avg_revenue_per_booking,
                                     COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_length_of_stay,
                                     ROUND((COUNT(b.booking_id) * 100.0 / NULLIF((
                                         SELECT COUNT(*) 
                                         FROM booking_hotel b2 
                                         JOIN hotel h2 ON b2.hotel_id = h2.hotel_id 
                                         WHERE YEAR(b2.check_in) = ? 
                                           AND MONTH(b2.check_in) = ?
                                           AND b2.status = 'Completed'
                                           $filter_conditions
                                     ), 0)), 1) as booking_percentage
                                   FROM tipe_kamar t
                                   LEFT JOIN booking_hotel b ON t.tipe_id = b.tipe_id 
                                   JOIN hotel h ON b.hotel_id = h.hotel_id
                                   WHERE YEAR(b.check_in) = ? 
                                     AND MONTH(b.check_in) = ?
                                     AND b.status = 'Completed'
                                     $filter_conditions
                                   GROUP BY t.tipe_id, t.nama_tipe
                                   HAVING COUNT(b.booking_id) > 0
                                   ORDER BY total_revenue DESC";
        $stmt = $conn->prepare($query_roomtype_revenue);
        if (!$stmt) {
            die("Prepare failed (roomtype revenue monthly): " . $conn->error);
        }

        $all_params = array_merge([$year, $month], $filter_params, [$year, $month], $filter_params);
        $bindAll($stmt, $all_params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $roomtype_revenue = [];
    while ($row = $result->fetch_assoc()) {
        $roomtype_revenue[] = $row;
    }
    $data['roomtype_revenue'] = $roomtype_revenue;
    $stmt->close();

    // =========================
    // C) Overall average length of stay
    // =========================
    if ($period === 'yearly') {
        $query_avg_stay = "SELECT 
                             COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_length_of_stay,
                             COALESCE(MIN(DATEDIFF(b.check_out, b.check_in)), 0) as min_stay,
                             COALESCE(MAX(DATEDIFF(b.check_out, b.check_in)), 0) as max_stay
                           FROM booking_hotel b
                           JOIN hotel h ON b.hotel_id = h.hotel_id
                           WHERE YEAR(b.check_in) = ? 
                             AND b.status = 'Completed'
                             AND DATEDIFF(b.check_out, b.check_in) > 0
                             $filter_conditions";
        $stmt = $conn->prepare($query_avg_stay);
        if (!$stmt) {
            die("Prepare failed (avg stay yearly): " . $conn->error);
        }

        $all_params = array_merge([$year], $filter_params);
        $bindAll($stmt, $all_params);
    } else {
        $query_avg_stay = "SELECT 
                             COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_length_of_stay,
                             COALESCE(MIN(DATEDIFF(b.check_out, b.check_in)), 0) as min_stay,
                             COALESCE(MAX(DATEDIFF(b.check_out, b.check_in)), 0) as max_stay
                           FROM booking_hotel b
                           JOIN hotel h ON b.hotel_id = h.hotel_id
                           WHERE YEAR(b.check_in) = ? 
                             AND MONTH(b.check_in) = ?
                             AND b.status = 'Completed'
                             AND DATEDIFF(b.check_out, b.check_in) > 0
                             $filter_conditions";
        $stmt = $conn->prepare($query_avg_stay);
        if (!$stmt) {
            die("Prepare failed (avg stay monthly): " . $conn->error);
        }

        $all_params = array_merge([$year, $month], $filter_params);
        $bindAll($stmt, $all_params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $stay_data = $result->fetch_assoc() ?: [];
    $data['overall_avg_stay'] = $stay_data['avg_length_of_stay'] ?? 0;
    $data['min_stay'] = $stay_data['min_stay'] ?? 0;
    $data['max_stay'] = $stay_data['max_stay'] ?? 0;
    $stmt->close();

    // =========================
    // D) Room utilization by day of week
    // =========================
    if ($period === 'yearly') {
        $query_utilization = "SELECT 
                        WEEKDAY(b.check_in) as day_num,
                        CASE WEEKDAY(b.check_in)
                          WHEN 0 THEN 'Senin'
                          WHEN 1 THEN 'Selasa'
                          WHEN 2 THEN 'Rabu'
                          WHEN 3 THEN 'Kamis'
                          WHEN 4 THEN 'Jumat'
                          WHEN 5 THEN 'Sabtu'
                          WHEN 6 THEN 'Minggu'
                        END as day_of_week,
                        SUM(b.jumlah_kamar) as rooms_occupied,
                        COUNT(b.booking_id) as booking_count,
                        COALESCE(ROUND(AVG(b.total_harga), 0), 0) as avg_revenue_per_booking
                      FROM booking_hotel b
                      JOIN hotel h ON b.hotel_id = h.hotel_id
                      WHERE YEAR(b.check_in) = ?
                        AND b.status = 'Completed'
                        $filter_conditions
                      GROUP BY day_num
                      HAVING COUNT(b.booking_id) > 0
                      ORDER BY day_num";

        $stmt = $conn->prepare($query_utilization);
        if (!$stmt) {
            die("Prepare failed (utilization yearly): " . $conn->error);
        }

        $all_params = array_merge([$year], $filter_params);
        $bindAll($stmt, $all_params);
    } else {
        $query_utilization = "SELECT 
                        WEEKDAY(b.check_in) as day_num,
                        CASE WEEKDAY(b.check_in)
                          WHEN 0 THEN 'Senin'
                          WHEN 1 THEN 'Selasa'
                          WHEN 2 THEN 'Rabu'
                          WHEN 3 THEN 'Kamis'
                          WHEN 4 THEN 'Jumat'
                          WHEN 5 THEN 'Sabtu'
                          WHEN 6 THEN 'Minggu'
                        END as day_of_week,
                        SUM(b.jumlah_kamar) as rooms_occupied,
                        COUNT(b.booking_id) as booking_count,
                        COALESCE(ROUND(AVG(b.total_harga), 0), 0) as avg_revenue_per_booking
                      FROM booking_hotel b
                      JOIN hotel h ON b.hotel_id = h.hotel_id
                      WHERE YEAR(b.check_in) = ? 
                        AND MONTH(b.check_in) = ?
                        AND b.status = 'Completed'
                        $filter_conditions
                      GROUP BY day_num
                      HAVING COUNT(b.booking_id) > 0
                      ORDER BY day_num";

        $stmt = $conn->prepare($query_utilization);
        if (!$stmt) {
            die("Prepare failed (utilization monthly): " . $conn->error);
        }

        $all_params = array_merge([$year, $month], $filter_params);
        $bindAll($stmt, $all_params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $utilization_data = [];
    while ($row = $result->fetch_assoc()) {
        $utilization_data[] = $row;
    }
    $data['room_utilization'] = $utilization_data;
    $stmt->close();

    return $data;
}

// 5. Customer Analytics - Visualisasi Pelanggan
function get_customer_analytics($conn, $year, $month, $period, $filter_conditions = "", $filter_params = [])
{
    $data = [];

    // Customer segmentation (New vs Repeat) with filters
    if ($period === 'yearly') {
        $query_customer_type = "SELECT 
                                  u.username,
                                  u.email,
                                  COUNT(b.booking_id) as total_bookings,
                                  CASE 
                                    WHEN COUNT(b.booking_id) > 1 THEN 'Repeat Customer'
                                    ELSE 'New Customer'
                                  END as customer_type,
                                  COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_spent,
                                  COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_stay_length
                                FROM user u
                                JOIN customer c ON u.id_user = c.id_user
                                JOIN booking_hotel b ON c.customer_id = b.customer_id
                                JOIN hotel h ON b.hotel_id = h.hotel_id
                                WHERE YEAR(b.tanggal_booking) = ?
                                  $filter_conditions
                                GROUP BY u.id_user, u.username, u.email
                                HAVING COUNT(b.booking_id) > 0
                                ORDER BY total_bookings DESC";
        $stmt = $conn->prepare($query_customer_type);
        $all_params = array_merge([$year], $filter_params);
        $types = "s" . str_repeat('s', count($filter_params));
        $stmt->bind_param($types, ...$all_params);
    } else {
        $query_customer_type = "SELECT 
                                  u.username,
                                  u.email,
                                  COUNT(b.booking_id) as total_bookings,
                                  CASE 
                                    WHEN COUNT(b.booking_id) > 1 THEN 'Repeat Customer'
                                    ELSE 'New Customer'
                                  END as customer_type,
                                  COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_spent,
                                  COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_stay_length
                                FROM user u
                                JOIN customer c ON u.id_user = c.id_user
                                JOIN booking_hotel b ON c.customer_id = b.customer_id
                                JOIN hotel h ON b.hotel_id = h.hotel_id
                                WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
                                  $filter_conditions
                                GROUP BY u.id_user, u.username, u.email
                                HAVING COUNT(b.booking_id) > 0
                                ORDER BY total_bookings DESC";
        $stmt = $conn->prepare($query_customer_type);
        $all_params = array_merge([$year, $month], $filter_params);
        $types = "ss" . str_repeat('s', count($filter_params));
        $stmt->bind_param($types, ...$all_params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $customer_segments = [];
    $new_customers = 0;
    $repeat_customers = 0;

    while ($row = $result->fetch_assoc()) {
        $customer_segments[] = $row;
        if ($row['customer_type'] === 'Repeat Customer') {
            $repeat_customers++;
        } else {
            $new_customers++;
        }
    }

    $data['customer_segments'] = $customer_segments;
    $data['new_customers'] = $new_customers;
    $data['repeat_customers'] = $repeat_customers;
    $data['total_customers'] = $new_customers + $repeat_customers;

    // Customer source analysis with filters
    if ($period === 'yearly') {
        $query_customer_source = "SELECT 
                                    COALESCE(b.metode_pembayaran, 'Unknown') as source,
                                    COUNT(*) as booking_count,
                                    COUNT(DISTINCT c.customer_id) as unique_customers,
                                    COALESCE(ROUND(AVG(b.total_harga), 0), 0) as avg_booking_value
                                  FROM booking_hotel b
                                  JOIN customer c ON b.customer_id = c.customer_id
                                  JOIN hotel h ON b.hotel_id = h.hotel_id
                                  WHERE YEAR(b.tanggal_booking) = ?
                                    $filter_conditions
                                  GROUP BY b.metode_pembayaran
                                  HAVING COUNT(*) > 0
                                  ORDER BY booking_count DESC";
        $stmt = $conn->prepare($query_customer_source);
        $all_params = array_merge([$year], $filter_params);
        $types = "s" . str_repeat('s', count($filter_params));
        $stmt->bind_param($types, ...$all_params);
    } else {
        $query_customer_source = "SELECT 
                                    COALESCE(b.metode_pembayaran, 'Unknown') as source,
                                    COUNT(*) as booking_count,
                                    COUNT(DISTINCT c.customer_id) as unique_customers,
                                    COALESCE(ROUND(AVG(b.total_harga), 0), 0) as avg_booking_value
                                  FROM booking_hotel b
                                  JOIN customer c ON b.customer_id = c.customer_id
                                  JOIN hotel h ON b.hotel_id = h.hotel_id
                                  WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
                                    $filter_conditions
                                  GROUP BY b.metode_pembayaran
                                  HAVING COUNT(*) > 0
                                  ORDER BY booking_count DESC";
        $stmt = $conn->prepare($query_customer_source);
        $all_params = array_merge([$year, $month], $filter_params);
        $types = "ss" . str_repeat('s', count($filter_params));
        $stmt->bind_param($types, ...$all_params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $customer_sources = [];
    while ($row = $result->fetch_assoc()) {
        $customer_sources[] = $row;
    }
    $data['customer_sources'] = $customer_sources;

    // Top customers by value with filters
    if ($period === 'yearly') {
        $query_top_customers = "SELECT 
                                  u.username,
                                  u.email,
                                  COALESCE(c.no_hp, '-') as no_hp,
                                  COUNT(b.booking_id) as total_bookings,
                                  COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_spent,
                                  COALESCE(ROUND(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0), 0) as avg_booking_value,
                                  COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_stay_length,
                                  MAX(b.tanggal_booking) as last_booking_date
                                FROM user u
                                JOIN customer c ON u.id_user = c.id_user
                                JOIN booking_hotel b ON c.customer_id = b.customer_id
                                JOIN hotel h ON b.hotel_id = h.hotel_id
                                WHERE YEAR(b.tanggal_booking) = ?
                                  $filter_conditions
                                GROUP BY u.id_user, u.username, u.email, c.no_hp
                                HAVING COUNT(b.booking_id) > 0
                                ORDER BY total_spent DESC
                                LIMIT 10";
        $stmt = $conn->prepare($query_top_customers);
        $all_params = array_merge([$year], $filter_params);
        $types = "s" . str_repeat('s', count($filter_params));
        $stmt->bind_param($types, ...$all_params);
    } else {
        $query_top_customers = "SELECT 
                                  u.username,
                                  u.email,
                                  COALESCE(c.no_hp, '-') as no_hp,
                                  COUNT(b.booking_id) as total_bookings,
                                  COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_spent,
                                  COALESCE(ROUND(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0), 0) as avg_booking_value,
                                  COALESCE(ROUND(AVG(DATEDIFF(b.check_out, b.check_in)), 1), 0) as avg_stay_length,
                                  MAX(b.tanggal_booking) as last_booking_date
                                FROM user u
                                JOIN customer c ON u.id_user = c.id_user
                                JOIN booking_hotel b ON c.customer_id = b.customer_id
                                JOIN hotel h ON b.hotel_id = h.hotel_id
                                WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
                                  $filter_conditions
                                GROUP BY u.id_user, u.username, u.email, c.no_hp
                                HAVING COUNT(b.booking_id) > 0
                                ORDER BY total_spent DESC
                                LIMIT 10";
        $stmt = $conn->prepare($query_top_customers);
        $all_params = array_merge([$year, $month], $filter_params);
        $types = "ss" . str_repeat('s', count($filter_params));
        $stmt->bind_param($types, ...$all_params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $top_customers = [];
    while ($row = $result->fetch_assoc()) {
        $top_customers[] = $row;
    }
    $data['top_customers'] = $top_customers;

    // Customer Lifetime Value (CLV) estimation with filters
    if ($period === 'yearly') {
        $query_clv = "SELECT 
                        u.username,
                        u.email,
                        COUNT(b.booking_id) as visit_count,
                        COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_value,
                        COALESCE(ROUND(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) / NULLIF(COUNT(b.booking_id), 0), 0), 0) as avg_value_per_visit
                      FROM user u
                      JOIN customer c ON u.id_user = c.id_user
                      JOIN booking_hotel b ON c.customer_id = b.customer_id
                      JOIN hotel h ON b.hotel_id = h.hotel_id
                      WHERE YEAR(b.tanggal_booking) = ?
                        $filter_conditions
                      GROUP BY u.id_user, u.username, u.email
                      HAVING COUNT(b.booking_id) > 1
                      ORDER BY total_value DESC
                      LIMIT 5";
        $stmt = $conn->prepare($query_clv);
        $all_params = array_merge([$year], $filter_params);
        $types = "s" . str_repeat('s', count($filter_params));
        $stmt->bind_param($types, ...$all_params);
    } else {
        $query_clv = "SELECT 
                        u.username,
                        u.email,
                        COUNT(b.booking_id) as visit_count,
                        COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_value,
                        COALESCE(ROUND(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) / NULLIF(COUNT(b.booking_id), 0), 0), 0) as avg_value_per_visit
                      FROM user u
                      JOIN customer c ON u.id_user = c.id_user
                      JOIN booking_hotel b ON c.customer_id = b.customer_id
                      JOIN hotel h ON b.hotel_id = h.hotel_id
                      WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
                        $filter_conditions
                      GROUP BY u.id_user, u.username, u.email
                      HAVING COUNT(b.booking_id) > 1
                      ORDER BY total_value DESC
                      LIMIT 5";
        $stmt = $conn->prepare($query_clv);
        $all_params = array_merge([$year, $month], $filter_params);
        $types = "ss" . str_repeat('s', count($filter_params));
        $stmt->bind_param($types, ...$all_params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $clv_data = [];
    while ($row = $result->fetch_assoc()) {
        $clv_data[] = $row;
    }
    $data['clv_analysis'] = $clv_data;

    $stmt->close();
    return $data;
}

// 6. Overview Dashboard - Dashboard Utama Visualisasi
function get_overview_analytics($conn, $year, $month, $period, $filter_conditions = "", $filter_params = [])
{
    $data = [];

    // Helper binder (anti mismatch)
    $bindAndExecute = function ($stmt, $params) {
        if (!empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result();
    };

    // =========================
    // 1) KPIs
    // =========================
    if ($period === 'yearly') {
        $query_kpis = "SELECT 
                         COUNT(*) as total_bookings,
                         COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed_bookings,
                         COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancelled_bookings,
                         COUNT(CASE WHEN b.status = 'Pending' THEN 1 END) as pending_bookings,
                         COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue,
                         COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0) as avg_booking_value,
                         CASE 
                           WHEN COUNT(*) > 0 THEN 
                             ROUND((COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) * 100.0 / COUNT(*)), 1)
                           ELSE 0 
                         END as success_rate
                       FROM booking_hotel b
                       JOIN hotel h ON b.hotel_id = h.hotel_id
                       WHERE YEAR(b.tanggal_booking) = ?
                         $filter_conditions";
        $stmt = $conn->prepare($query_kpis);
        if (!$stmt) die("Prepare failed (kpis yearly): " . $conn->error);

        $params = array_merge([$year], $filter_params);
        $result = $bindAndExecute($stmt, $params);
    } else {
        $query_kpis = "SELECT 
                         COUNT(*) as total_bookings,
                         COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed_bookings,
                         COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancelled_bookings,
                         COUNT(CASE WHEN b.status = 'Pending' THEN 1 END) as pending_bookings,
                         COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue,
                         COALESCE(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0) as avg_booking_value,
                         CASE 
                           WHEN COUNT(*) > 0 THEN 
                             ROUND((COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) * 100.0 / COUNT(*)), 1)
                           ELSE 0 
                         END as success_rate
                       FROM booking_hotel b
                       JOIN hotel h ON b.hotel_id = h.hotel_id
                       WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
                         $filter_conditions";
        $stmt = $conn->prepare($query_kpis);
        if (!$stmt) die("Prepare failed (kpis monthly): " . $conn->error);

        $params = array_merge([$year, $month], $filter_params);
        $result = $bindAndExecute($stmt, $params);
    }

    $data['kpis'] = $result ? ($result->fetch_assoc() ?: []) : [];
    $stmt->close();

    // =========================
    // 2) Monthly trend comparison
    // (FIX: basisnya dari $year, bukan date('Y'))
    // =========================
    $current_year = (int)$year;
    $prev_year = $current_year - 1;

    $query_trend = "SELECT 
                      MONTH(b.tanggal_booking) as month,
                      YEAR(b.tanggal_booking) as year,
                      COUNT(*) as bookings,
                      COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as revenue,
                      COALESCE(ROUND(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0), 0) as avg_revenue,
                      CASE 
                        WHEN COUNT(*) > 0 THEN 
                          ROUND((COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) * 100.0 / COUNT(*)), 1)
                        ELSE 0 
                      END as success_rate
                    FROM booking_hotel b
                    JOIN hotel h ON b.hotel_id = h.hotel_id
                    WHERE (YEAR(b.tanggal_booking) = ? OR YEAR(b.tanggal_booking) = ?)
                      $filter_conditions
                    GROUP BY YEAR(b.tanggal_booking), MONTH(b.tanggal_booking)
                    ORDER BY year, month";

    $stmt = $conn->prepare($query_trend);
    if (!$stmt) die("Prepare failed (trend): " . $conn->error);

    $params = array_merge([$prev_year, $current_year], $filter_params);
    $result = $bindAndExecute($stmt, $params);

    $monthly_trends = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $y = (string)$row['year'];
            $m = (string)$row['month'];
            if (!isset($monthly_trends[$y])) $monthly_trends[$y] = [];
            $monthly_trends[$y][$m] = $row;
        }
    }
    $data['monthly_trends'] = $monthly_trends;
    $stmt->close();

    // =========================
    // 3) Quick stats by room type
    // (SUDAH benar konsepnya: subquery + main query => params dobel)
    // =========================
    if ($period === 'yearly') {
        $query_quick_stats = "SELECT 
                                t.nama_tipe,
                                COUNT(b.booking_id) as bookings,
                                COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as revenue,
                                COALESCE(ROUND(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0), 0) as avg_revenue,
                                ROUND((COUNT(b.booking_id) * 100.0 / NULLIF((
                                    SELECT COUNT(*) 
                                    FROM booking_hotel b2 
                                    JOIN hotel h2 ON b2.hotel_id = h2.hotel_id 
                                    WHERE YEAR(b2.tanggal_booking) = ? $filter_conditions
                                ), 0)), 1) as market_share
                              FROM tipe_kamar t
                              LEFT JOIN booking_hotel b ON t.tipe_id = b.tipe_id 
                              JOIN hotel h ON b.hotel_id = h.hotel_id
                              WHERE YEAR(b.tanggal_booking) = ?
                                $filter_conditions
                              GROUP BY t.tipe_id, t.nama_tipe
                              HAVING COUNT(b.booking_id) > 0
                              ORDER BY revenue DESC
                              LIMIT 5";
        $stmt = $conn->prepare($query_quick_stats);
        if (!$stmt) die("Prepare failed (quick_stats yearly): " . $conn->error);

        $params = array_merge([$year], $filter_params, [$year], $filter_params);
        $result = $bindAndExecute($stmt, $params);
    } else {
        $query_quick_stats = "SELECT 
                                t.nama_tipe,
                                COUNT(b.booking_id) as bookings,
                                COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as revenue,
                                COALESCE(ROUND(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0), 0) as avg_revenue,
                                ROUND((COUNT(b.booking_id) * 100.0 / NULLIF((
                                    SELECT COUNT(*) 
                                    FROM booking_hotel b2 
                                    JOIN hotel h2 ON b2.hotel_id = h2.hotel_id 
                                    WHERE YEAR(b2.tanggal_booking) = ? AND MONTH(b2.tanggal_booking) = ? $filter_conditions
                                ), 0)), 1) as market_share
                              FROM tipe_kamar t
                              LEFT JOIN booking_hotel b ON t.tipe_id = b.tipe_id 
                              JOIN hotel h ON b.hotel_id = h.hotel_id
                              WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
                                $filter_conditions
                              GROUP BY t.tipe_id, t.nama_tipe
                              HAVING COUNT(b.booking_id) > 0
                              ORDER BY revenue DESC
                              LIMIT 5";
        $stmt = $conn->prepare($query_quick_stats);
        if (!$stmt) die("Prepare failed (quick_stats monthly): " . $conn->error);

        $params = array_merge([$year, $month], $filter_params, [$year, $month], $filter_params);
        $result = $bindAndExecute($stmt, $params);
    }

    $quick_stats = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) $quick_stats[] = $row;
    }
    $data['quick_stats'] = $quick_stats;
    $stmt->close();

    // =========================
    // 4) Hotel performance ranking
    // =========================
    if ($period === 'yearly') {
        $query_hotel_performance = "SELECT 
                                      h.nama_hotel,
                                      h.kota,
                                      COUNT(b.booking_id) as total_bookings,
                                      COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue,
                                      COALESCE(ROUND(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0), 0) as avg_booking_value,
                                      CASE 
                                        WHEN COUNT(b.booking_id) > 0 THEN 
                                          ROUND((COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) * 100.0 / COUNT(b.booking_id)), 1)
                                        ELSE 0 
                                      END as success_rate
                                    FROM hotel h
                                    LEFT JOIN booking_hotel b ON h.hotel_id = b.hotel_id 
                                    WHERE YEAR(b.tanggal_booking) = ?
                                      $filter_conditions
                                    GROUP BY h.hotel_id, h.nama_hotel, h.kota
                                    HAVING COUNT(b.booking_id) > 0
                                    ORDER BY total_revenue DESC
                                    LIMIT 5";
        $stmt = $conn->prepare($query_hotel_performance);
        if (!$stmt) die("Prepare failed (hotel_performance yearly): " . $conn->error);

        $params = array_merge([$year], $filter_params);
        $result = $bindAndExecute($stmt, $params);
    } else {
        $query_hotel_performance = "SELECT 
                                      h.nama_hotel,
                                      h.kota,
                                      COUNT(b.booking_id) as total_bookings,
                                      COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as total_revenue,
                                      COALESCE(ROUND(AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END), 0), 0) as avg_booking_value,
                                      CASE 
                                        WHEN COUNT(b.booking_id) > 0 THEN 
                                          ROUND((COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) * 100.0 / COUNT(b.booking_id)), 1)
                                        ELSE 0 
                                      END as success_rate
                                    FROM hotel h
                                    LEFT JOIN booking_hotel b ON h.hotel_id = b.hotel_id 
                                    WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
                                      $filter_conditions
                                    GROUP BY h.hotel_id, h.nama_hotel, h.kota
                                    HAVING COUNT(b.booking_id) > 0
                                    ORDER BY total_revenue DESC
                                    LIMIT 5";
        $stmt = $conn->prepare($query_hotel_performance);
        if (!$stmt) die("Prepare failed (hotel_performance monthly): " . $conn->error);

        $params = array_merge([$year, $month], $filter_params);
        $result = $bindAndExecute($stmt, $params);
    }

    $hotel_performance = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) $hotel_performance[] = $row;
    }
    $data['hotel_performance'] = $hotel_performance;
    $stmt->close();

    // =========================
    // 5) Revenue forecast (6 bulan terakhir)
    // =========================
    $query_forecast = "SELECT 
                         DATE_FORMAT(b.tanggal_booking, '%Y-%m') as month_year,
                         COALESCE(SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END), 0) as revenue,
                         COUNT(*) as bookings
                       FROM booking_hotel b
                       JOIN hotel h ON b.hotel_id = h.hotel_id
                       WHERE b.tanggal_booking >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                         $filter_conditions
                       GROUP BY DATE_FORMAT(b.tanggal_booking, '%Y-%m')
                       ORDER BY month_year";

    $stmt = $conn->prepare($query_forecast);
    if (!$stmt) die("Prepare failed (forecast): " . $conn->error);

    $params = $filter_params; // forecast tidak punya placeholder year/month, cuma filter
    $result = $bindAndExecute($stmt, $params);

    $revenue_forecast = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) $revenue_forecast[] = $row;
    }
    $data['revenue_forecast'] = $revenue_forecast;
    $stmt->close();

    return $data;
}


// Get analytics data based on selected type
switch ($filter_type) {
    case 'occupancy':
        $analytics_data = get_occupancy_analytics($conn, $filter_year, $filter_month, $filter_period, $filter_conditions, $filter_params);
        $page_title = te("Analitik Okupansi - Visualisasi Hunian Kamar");
        break;
    case 'revenue':
        $analytics_data = get_revenue_analytics($conn, $filter_year, $filter_month, $filter_period, $filter_conditions, $filter_params);
        $page_title = te("Analitik Pendapatan - Visualisasi Pendapatan");
        break;
    case 'booking':
        $analytics_data = get_booking_analytics($conn, $filter_year, $filter_month, $filter_period, $filter_conditions, $filter_params);
        $page_title = te("Analitik Booking - Visualisasi Reservasi");
        break;
    case 'room':
        $analytics_data = get_room_performance_analytics($conn, $filter_year, $filter_month, $filter_period, $filter_conditions, $filter_params);
        $page_title = te("Analitik Performa Kamar - Visualisasi Performa Kamar");
        break;
    case 'customer':
        $analytics_data = get_customer_analytics($conn, $filter_year, $filter_month, $filter_period, $filter_conditions, $filter_params);
        $page_title = te("Analitik Pelanggan - Visualisasi Pelanggan");
        break;
    default:
        $analytics_data = get_overview_analytics($conn, $filter_year, $filter_month, $filter_period, $filter_conditions, $filter_params);
        $page_title = te("Dasbor Analitik Performa - Visualisasi Data TripVerse");
        break;
}

// Get system notifications
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

    if (in_array($imageFileType, $allowedTypes)) {
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
            $update = $conn->prepare("UPDATE user SET profile_picture = ? WHERE id_user = ?");
            if ($update) {
                $update->bind_param("ss", $fileName, $id_user);
                $update->execute();
                $update->close();

                $_SESSION['upload_notification'] = "Profile photo updated successfully!";
                $foto = $fileName;
            } else {
                $_SESSION['upload_notification'] = "Error preparing update query.";
            }
        } else {
            $_SESSION['upload_notification'] = "Failed to upload photo.";
        }
    } else {
        $_SESSION['upload_notification'] = "Invalid file type. Only JPG, PNG, GIF allowed.";
    }

    $conn->close();
    $url_params = http_build_query([
        'type' => $filter_type,
        'year' => $filter_year,
        'month' => $filter_month,
        'period' => $filter_period,
        'date' => $filter_date,
        'city' => $filter_city,
        'hotel' => $filter_hotel,
        'room_type' => $filter_room_type
    ]);
    header("Location: performance_analytics.php?$url_params");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= $page_title ?> | TripVerse Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../../css/dashboard.css?v=2.1.1" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
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
        }

        .dashboard-insight {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border-left: 4px solid var(--primary-color);
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .dashboard-insight h4 {
            margin: 0 0 10px 0;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dashboard-insight p {
            margin: 0;
            color: var(--text-color);
            font-size: 14px;
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
            overflow-x: auto;
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
        }

        /* Filter Controls */
        .filter-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            align-items: end;
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
        }

        .filter-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 122, 61, 0.1);
            outline: none;
            transform: translateY(-1px);
        }

        .filter-controls button,
        .filter-controls .reset-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            white-space: nowrap;
            justify-content: center;
            height: fit-content;
            text-decoration: none;
            min-height: 48px;
            box-shadow: 0 3px 10px rgba(255, 122, 61, 0.2);
        }

        .filter-controls button:hover {
            background: #E8672B;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 122, 61, 0.3);
        }

        .filter-controls .reset-btn {
            background: #6c757d;
            box-shadow: 0 3px 10px rgba(108, 117, 125, 0.2);
        }

        .filter-controls .reset-btn:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
        }

        /* Analytics Type Tabs */
        .analytics-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 25px;
            padding: 15px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .analytics-tab {
            padding: 12px 24px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            background: white;
            color: var(--text-color);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .analytics-tab:hover {
            border-color: var(--primary-color);
            background: #f8f9fa;
            transform: translateY(-2px);
        }

        .analytics-tab.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
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

        .kpi-change {
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            margin-top: 8px;
        }

        .change-positive {
            color: var(--success-color);
        }

        .change-negative {
            color: var(--danger-color);
        }

        .change-neutral {
            color: var(--text-light);
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
            transform: scale(1.01);
            transition: var(--transition);
        }

        .performance-table td {
            font-size: 14px;
            color: var(--text-color);
            padding: 14px 15px;
            border-bottom: 1px solid #e9ecef;
        }

        .performance-table td strong {
            font-weight: 700;
            color: var(--dark-color);
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

        /* Alert Colors */
        .alert-high {
            color: var(--danger-color);
            font-weight: bold;
        }

        .alert-medium {
            color: var(--warning-color);
            font-weight: bold;
        }

        .alert-low {
            color: var(--success-color);
            font-weight: bold;
        }

        /* Responsive Adjustments */
        @media (max-width: 1200px) {
            .performance-grid {
                grid-template-columns: 1fr;
            }

            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-controls {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .performance-section {
                padding: 20px;
                margin-bottom: 20px;
            }

            .kpi-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .filter-controls {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .chart-container {
                height: 300px;
            }

            .performance-grid {
                grid-template-columns: 1fr;
            }

            .kpi-value {
                font-size: 28px;
            }

            .filter-controls button,
            .filter-controls .reset-btn {
                padding: 16px 20px;
                font-size: 15px;
                min-height: 52px;
            }
        }

        @media (max-width: 480px) {
            .performance-section {
                padding: 15px;
            }

            .performance-card {
                padding: 15px;
            }

            .chart-container {
                height: 250px;
                padding: 10px;
            }

            .filter-controls {
                padding: 15px;
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

        .kpi-card {
            animation: fadeIn 0.8s ease-out;
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
                    <img src="../../uploads/<?php echo htmlspecialchars($foto); ?>"
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

                <div class="booking-submenu hidden" id="analyticsDropdown">
                    <a href="performance_analytics.php" class="active">
                        <span class="material-icons">bar_chart</span>
                        <span><?= te('Statistik Performa') ?></span>
                    </a>
                    <a href="market_analysis.php">
                        <span class="material-icons">trending_up</span>
                        <span><?= te('Tren Booking') ?></span>
                    </a>
                </div>
            </div>

            <!-- STATISTICAL ANALYSIS -->
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
                    <img src="../../uploads/<?php echo htmlspecialchars($foto); ?>" alt="User Avatar" class="user-avatar" />
                </div>
            </div>
        </header>

        <?php if (isset($_SESSION['upload_notification'])) : ?>
            <div class="notification-message <?php echo strpos($_SESSION['upload_notification'], 'Failed') === false ? 'success' : 'error'; ?>">
                <?php
                echo htmlspecialchars($_SESSION['upload_notification']);
                unset($_SESSION['upload_notification']);
                ?>
            </div>
        <?php endif; ?>

        <div class="performance-section">
            <h2><i class="material-icons">analytics</i> <?= $page_title ?></h2>

            <!-- Dashboard Insights Section -->
            <div class="dashboard-insight">
                <h4><i class="material-icons">insights</i> Performance Insights</h4>
                <p>Dashboard ini menyediakan visualisasi data performa pemesanan hotel untuk analisis dan monitoring.</p>
                <?php if ($filter_city || $filter_hotel || $filter_room_type): ?>
                    <p style="margin-top: 10px; color: var(--primary-color); font-weight: 600;">
                        <i class="material-icons">filter_alt</i> Filter aktif:
                        <?php
                        $filters = [];
                        if ($filter_city) $filters[] = "Kota: " . htmlspecialchars($filter_city);
                        if ($filter_hotel) {
                            $hotel_name = '';
                            foreach ($hotels as $h) {
                                if ($h['hotel_id'] == $filter_hotel) {
                                    $hotel_name = $h['nama_hotel'];
                                    break;
                                }
                            }
                            $filters[] = "Hotel: " . htmlspecialchars($hotel_name ?: $filter_hotel);
                        }
                        if ($filter_room_type) {
                            $room_type_name = '';
                            foreach ($room_types as $rt) {
                                if ($rt['tipe_id'] == $filter_room_type) {
                                    $room_type_name = $rt['nama_tipe'];
                                    break;
                                }
                            }
                            $filters[] = "Tipe Kamar: " . htmlspecialchars($room_type_name ?: $filter_room_type);
                        }
                        echo implode(' • ', $filters);
                        ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Analytics Type Tabs -->
            <div class="analytics-tabs">
                <div class="analytics-tab <?= $filter_type === 'overview' ? 'active' : '' ?>" onclick="changeAnalyticsType('overview')">
                    <i class="material-icons">dashboard</i>
                    <span><?= te('Ikhtisar') ?></span>
                </div>
                <div class="analytics-tab <?= $filter_type === 'occupancy' ? 'active' : '' ?>" onclick="changeAnalyticsType('occupancy')">
                    <i class="material-icons">hotel</i>
                    <span><?= te('Okupansi') ?></span>
                </div>
                <div class="analytics-tab <?= $filter_type === 'revenue' ? 'active' : '' ?>" onclick="changeAnalyticsType('revenue')">
                    <i class="material-icons">attach_money</i>
                    <span><?= te('Pendapatan') ?></span>
                </div>
                <div class="analytics-tab <?= $filter_type === 'booking' ? 'active' : '' ?>" onclick="changeAnalyticsType('booking')">
                    <i class="material-icons">book_online</i>
                    <span><?= te('Pemesanan') ?></span>
                </div>
                <div class="analytics-tab <?= $filter_type === 'room' ? 'active' : '' ?>" onclick="changeAnalyticsType('room')">
                    <i class="material-icons">room_service</i>
                    <span><?= te('Performa Kamar') ?></span>
                </div>
                <div class="analytics-tab <?= $filter_type === 'customer' ? 'active' : '' ?>" onclick="changeAnalyticsType('customer')">
                    <i class="material-icons">people</i>
                    <span><?= te('Statistik Pelanggan') ?></span>
                </div>
            </div>

            <form method="GET" action="performance_analytics.php" class="filter-controls">
                <input type="hidden" name="type" value="<?= $filter_type ?>">

                <div class="filter-group">
                    <label for="filter_period">Periode Analisis</label>
                    <select id="filter_period" name="period" class="filter-select" onchange="updateFilterFields()">
                        <option value="monthly" <?= $filter_period === 'monthly' ? 'selected' : '' ?>>Bulanan</option>
                        <option value="yearly" <?= $filter_period === 'yearly' ? 'selected' : '' ?>>Tahunan</option>
                    </select>
                </div>

                <div class="filter-group" id="dateField" style="<?= $filter_period === 'daily' ? '' : 'display: none;' ?>">
                    <label for="filter_date">Tanggal</label>
                    <input type="date" id="filter_date" name="date" class="filter-select" value="<?= $filter_date ?>">
                </div>

                <div class="filter-group" id="monthField" style="<?= $filter_period === 'monthly' || $filter_period === 'yearly' ? '' : 'display: none;' ?>">
                    <label for="filter_month">Bulan</label>
                    <select id="filter_month" name="month" class="filter-select">
                        <?php
                        $monthNames = [
                            '01' => 'Januari',
                            '02' => 'Februari',
                            '03' => 'Maret',
                            '04' => 'April',
                            '05' => 'Mei',
                            '06' => 'Juni',
                            '07' => 'Juli',
                            '08' => 'Agustus',
                            '09' => 'September',
                            '10' => 'Oktober',
                            '11' => 'November',
                            '12' => 'Desember'
                        ];
                        foreach ($monthNames as $num => $name): ?>
                            <option value="<?= $num ?>" <?= $filter_month == $num ? 'selected' : '' ?>>
                                <?= $name ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group" id="yearField">
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
                    <select id="filter_city" name="city" class="filter-select" onchange="updateHotelOptions()">
                        <option value="">Semua Kota</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?= htmlspecialchars($city) ?>" <?= $filter_city == $city ? 'selected' : '' ?>>
                                <?= htmlspecialchars($city) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_hotel">Hotel</label>
                    <select id="filter_hotel" name="hotel" class="filter-select">
                        <option value="">Semua Hotel</option>
                        <?php foreach ($hotels as $hotel): ?>
                            <option value="<?= htmlspecialchars($hotel['hotel_id']) ?>" <?= $filter_hotel == $hotel['hotel_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($hotel['nama_hotel']) ?> (<?= htmlspecialchars($hotel['kota']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_room_type">Tipe Kamar</label>
                    <select id="filter_room_type" name="room_type" class="filter-select">
                        <option value="">Semua Tipe</option>
                        <?php foreach ($room_types as $room_type): ?>
                            <option value="<?= htmlspecialchars($room_type['tipe_id']) ?>" <?= $filter_room_type == $room_type['tipe_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($room_type['nama_tipe']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="material-icons">filter_alt</i> Terapkan Filter
                </button>
                <a href="performance_analytics.php" class="btn btn-secondary reset-btn">
                    <i class="material-icons">refresh</i> Reset
                </a>
            </form>

            <div class="period-info">
                <i class="material-icons">calendar_today</i>
                <span>Periode Analisis: <strong>
                        <?php
                        $periodText = '';
                        switch ($filter_period) {
                            case 'daily':
                                $periodText = date('d F Y', strtotime($filter_date));
                                break;
                            case 'weekly':
                                $periodText = 'Minggu ini';
                                break;
                            case 'yearly':
                                $periodText = 'Tahun ' . $filter_year;
                                break;
                            default:
                                $periodText = isset($monthNames[$filter_month])
                                    ? $monthNames[$filter_month] . ' ' . $filter_year
                                    : date('F Y', strtotime($filter_year . '-' . $filter_month . '-01'));
                                break;
                        }
                        echo $periodText;
                        ?>
                    </strong></span>
            </div>

            <?php if ($filter_type === 'overview'): ?>
                <!-- OVERVIEW DASHBOARD -->
                <div class="kpi-grid">
                    <div class="kpi-card primary">
                        <div class="kpi-icon">
                            <i class="material-icons">attach_money</i>
                        </div>
                        <div class="kpi-label">Total Revenue</div>
                        <div class="kpi-value">Rp <?= number_format($analytics_data['kpis']['total_revenue'] ?? 0, 0, ',', '.') ?></div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">trending_up</i>
                            Pendapatan <?= $filter_period === 'yearly' ? 'Tahun Ini' : 'Bulan Ini' ?>
                        </div>
                    </div>

                    <div class="kpi-card success">
                        <div class="kpi-icon">
                            <i class="material-icons">check_circle</i>
                        </div>
                        <div class="kpi-label">Total Bookings</div>
                        <div class="kpi-value"><?= number_format($analytics_data['kpis']['total_bookings'] ?? 0) ?></div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">book_online</i>
                            Pemesanan <?= $filter_period === 'yearly' ? 'Tahun Ini' : 'Bulan Ini' ?>
                        </div>
                    </div>

                    <div class="kpi-card info">
                        <div class="kpi-icon">
                            <i class="material-icons">hotel</i>
                        </div>
                        <div class="kpi-label">Completed Bookings</div>
                        <div class="kpi-value"><?= number_format($analytics_data['kpis']['completed_bookings'] ?? 0) ?></div>
                        <div class="kpi-change <?= ($analytics_data['kpis']['success_rate'] ?? 0) >= 80 ? 'change-positive' : (($analytics_data['kpis']['success_rate'] ?? 0) >= 60 ? 'change-medium' : 'change-negative') ?>">
                            <i class="material-icons" style="font-size: 14px;">check_circle</i>
                            Success Rate: <?= number_format($analytics_data['kpis']['success_rate'] ?? 0, 1) ?>%
                        </div>
                    </div>

                    <div class="kpi-card warning">
                        <div class="kpi-icon">
                            <i class="material-icons">trending_up</i>
                        </div>
                        <div class="kpi-label">Avg. Booking Value</div>
                        <div class="kpi-value">Rp <?= number_format($analytics_data['kpis']['avg_booking_value'] ?? 0, 0, ',', '.') ?></div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">trending_up</i>
                            Per Booking
                        </div>
                    </div>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">trending_up</i> Tren Bulanan</h2>
                    <div class="performance-card">
                        <h3><i class="material-icons">bar_chart</i> Perbandingan Tahun <?= date('Y') - 1 ?> vs <?= date('Y') ?> - Analisis Tren</h3>
                        <div class="chart-container">
                            <canvas id="monthlyTrendChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">leaderboard</i> <?= te("Peringkat Performa Hotel") ?></h2>
                    <?php if (!empty($analytics_data['hotel_performance'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Hotel</th>
                                    <th>Lokasi</th>
                                    <th>Total Booking</th>
                                    <th>Total Revenue</th>
                                    <th>Avg. Booking Value</th>
                                    <th>Success Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['hotel_performance'] as $index => $hotel): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($hotel['nama_hotel']) ?></strong></td>
                                        <td><?= htmlspecialchars($hotel['kota']) ?></td>
                                        <td><?= number_format($hotel['total_bookings']) ?></td>
                                        <td>Rp <?= number_format($hotel['total_revenue'] ?? 0, 0, ',', '.') ?></td>
                                        <td>Rp <?= number_format($hotel['avg_booking_value'] ?? 0, 0, ',', '.') ?></td>
                                        <td class="<?= ($hotel['success_rate'] ?? 0) >= 80 ? 'alert-low' : (($hotel['success_rate'] ?? 0) >= 60 ? 'alert-medium' : 'alert-high') ?>">
                                            <?= number_format($hotel['success_rate'] ?? 0, 1) ?>%
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">info</i>
                            <p>Tidak ada data hotel untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">leaderboard</i> <?= te('Statistik Cepat per Tipe Kamar') ?></h2>
                    <?php if (!empty($analytics_data['quick_stats'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Tipe Kamar</th>
                                    <th>Pemesanan</th>
                                    <th>Pendapatan</th>
                                    <th>Revenue per Booking</th>
                                    <th>Market Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['quick_stats'] as $stat): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($stat['nama_tipe']) ?></strong></td>
                                        <td><?= number_format($stat['bookings']) ?></td>
                                        <td>Rp <?= number_format($stat['revenue'] ?? 0, 0, ',', '.') ?></td>
                                        <td>Rp <?= number_format($stat['avg_revenue'] > 0 ? round($stat['avg_revenue'], 0) : 0, 0, ',', '.') ?></td>
                                        <td><?= number_format($stat['market_share'] ?? 0, 1) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">info</i>
                            <p>Tidak ada data untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($filter_type === 'occupancy'): ?>
                <!-- OCCUPANCY ANALYTICS -->
                <div class="kpi-grid">
                    <div class="kpi-card primary">
                        <div class="kpi-icon">
                            <i class="material-icons">hotel</i>
                        </div>
                        <div class="kpi-label">Total Kamar Tersedia</div>
                        <div class="kpi-value"><?= number_format($analytics_data['total_rooms'] ?? 0) ?></div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">meeting_room</i>
                            Kapasitas Total
                        </div>
                    </div>

                    <div class="kpi-card success">
                        <div class="kpi-icon">
                            <i class="material-icons">bed</i>
                        </div>
                        <div class="kpi-label">Rata-rata Occupancy Rate</div>
                        <div class="kpi-value"><?= number_format($analytics_data['avg_occupancy_rate'] ?? 0, 1) ?>%</div>
                        <div class="kpi-change <?= ($analytics_data['avg_occupancy_rate'] ?? 0) >= 80 ? 'change-positive' : (($analytics_data['avg_occupancy_rate'] ?? 0) >= 60 ? 'change-medium' : 'change-negative') ?>">
                            <i class="material-icons" style="font-size: 14px;">trending_up</i>
                            <?= ($analytics_data['avg_occupancy_rate'] ?? 0) >= 80 ? 'Optimal' : (($analytics_data['avg_occupancy_rate'] ?? 0) >= 60 ? 'Cukup' : 'Perlu Perbaikan') ?>
                        </div>
                    </div>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">trending_up</i> Tren Tingkat Hunian</h2>
                    <div class="performance-card">
                        <h3><i class="material-icons">bar_chart</i> Occupancy Rate
                            <?= $filter_period === 'monthly' ? 'per Hari' : ($filter_period === 'weekly' ? 'per Hari dalam Minggu' : ($filter_period === 'yearly' ? 'per Bulan' : '')) ?>
                        </h3>
                        <div class="chart-container">
                            <canvas id="occupancyChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">location_city</i> <?= te('Okupansi berdasarkan Lokasi') ?></h2>
                    <?php if (!empty($analytics_data['occupancy_by_location'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Lokasi (Kota)</th>
                                    <th>Kamar Terpakai</th>
                                    <th>Jumlah Booking</th>
                                    <th>Occupancy Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['occupancy_by_location'] as $location): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($location['kota']) ?></strong></td>
                                        <td><?= number_format($location['total_occupied']) ?></td>
                                        <td><?= number_format($location['booking_count']) ?></td>
                                        <td class="<?= ($location['occupancy_rate'] ?? 0) >= 80 ? 'alert-low' : (($location['occupancy_rate'] ?? 0) >= 60 ? 'alert-medium' : 'alert-high') ?>">
                                            <?= number_format($location['occupancy_rate'] ?? 0, 1) ?>%
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">location_city</i>
                            <p>Tidak ada data lokasi untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">category</i> Top 5 Tipe Kamar Paling Sering Dipakai</h2>
                    <?php if (!empty($analytics_data['top_room_types'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Tipe Kamar</th>
                                    <th>Jumlah Pemesanan</th>
                                    <th>Persentase</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['top_room_types'] as $type): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($type['nama_tipe']) ?></strong></td>
                                        <td><?= number_format($type['booking_count']) ?></td>
                                        <td><?= number_format($type['percentage'] ?? 0, 1) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">hotel</i>
                            <p>Tidak ada data kamar untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($filter_type === 'revenue'): ?>
                <!-- REVENUE ANALYTICS -->
                <div class="kpi-grid">
                    <div class="kpi-card primary">
                        <div class="kpi-icon">
                            <i class="material-icons">attach_money</i>
                        </div>
                        <div class="kpi-label">Total Revenue</div>
                        <div class="kpi-value">Rp <?= number_format($analytics_data['total_revenue'] ?? 0, 0, ',', '.') ?></div>
                        <div class="kpi-change <?= ($analytics_data['total_revenue'] ?? 0) > 10000000 ? 'change-positive' : (($analytics_data['total_revenue'] ?? 0) > 5000000 ? 'change-medium' : 'change-negative') ?>">
                            <i class="material-icons" style="font-size: 14px;">trending_up</i>
                            Periode <?= $filter_period ?>
                        </div>
                    </div>

                    <div class="kpi-card success">
                        <div class="kpi-icon">
                            <i class="material-icons">receipt</i>
                        </div>
                        <div class="kpi-label">Total Bookings</div>
                        <div class="kpi-value"><?= number_format($analytics_data['total_bookings'] ?? 0) ?></div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">book_online</i>
                            Booking Completed
                        </div>
                    </div>

                    <div class="kpi-card info">
                        <div class="kpi-icon">
                            <i class="material-icons">trending_up</i>
                        </div>
                        <div class="kpi-label">Average Daily Rate (ADR)</div>
                        <div class="kpi-value">Rp <?= number_format($analytics_data['adr'] ?? 0, 0, ',', '.') ?></div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">monetization_on</i>
                            Tarif Harian Rata-rata
                        </div>
                    </div>

                    <div class="kpi-card warning">
                        <div class="kpi-icon">
                            <i class="material-icons">meeting_room</i>
                        </div>
                        <div class="kpi-label">Revenue per Available Room (RevPAR)</div>
                        <div class="kpi-value">Rp <?= number_format($analytics_data['revpar'] ?? 0, 0, ',', '.') ?></div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">insights</i>
                            Pendapatan per Kamar
                        </div>
                    </div>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">trending_up</i> Tren Pendapatan - Visualisasi Data</h2>
                    <div class="performance-card">
                        <h3><i class="material-icons">bar_chart</i> Revenue
                            <?= $filter_period === 'monthly' ? 'per Hari' : ($filter_period === 'yearly' ? 'per Bulan' : '') ?>
                        </h3>
                        <div class="chart-container">
                            <canvas id="revenueTrendChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">location_city</i> <?= te('Pendapatan berdasarkan Lokasi - Analisis Geografis') ?></h2>
                    <?php if (!empty($analytics_data['revenue_by_location'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Lokasi (Kota)</th>
                                    <th>Pendapatan</th>
                                    <th>Bookings</th>
                                    <th>Avg. Revenue per Booking</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['revenue_by_location'] as $location): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($location['kota']) ?></strong></td>
                                        <td>Rp <?= number_format($location['revenue'] ?? 0, 0, ',', '.') ?></td>
                                        <td><?= number_format($location['bookings']) ?></td>
                                        <td>Rp <?= number_format($location['avg_revenue'] ?? 0, 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">location_city</i>
                            <p>Tidak ada data pendapatan lokasi untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">pie_chart</i> Distribusi Pendapatan Berdasarkan Tipe Kamar</h2>
                    <?php if (!empty($analytics_data['revenue_by_type'])): ?>
                        <div class="performance-grid">
                            <div class="performance-card">
                                <h3><i class="material-icons">donut_large</i> <?= te('Diagram Pai Distribusi Pendapatan') ?></h3>
                                <div class="chart-container">
                                    <canvas id="revenueByTypeChart"></canvas>
                                </div>
                            </div>
                            <div class="performance-card">
                                <h3><i class="material-icons">table_chart</i> <?= te('Detail Data Visualisasi') ?></h3>
                                <table class="performance-table">
                                    <thead>
                                        <tr>
                                            <th>Tipe Kamar</th>
                                            <th>Pendapatan</th>
                                            <th>Bookings</th>
                                            <th>Avg per Booking</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (($analytics_data['revenue_by_type'] ?? []) as $type): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($type['nama_tipe'] ?? '-') ?></strong></td>
                                                <td>Rp <?= number_format((float)($type['revenue'] ?? 0), 0, ',', '.') ?></td>
                                                <td><?= number_format((int)($type['bookings'] ?? 0)) ?></td>
                                                <td>Rp <?= number_format((float)($type['avg_revenue_per_booking'] ?? 0), 0, ',', '.') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">book_online</i>
                            <p>Tidak ada data metode booking untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">star</i> <?= te('Booking Pendapatan Tertinggi') ?></h2>

                    <?php if (!empty($analytics_data['top_revenue_bookings'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Booking ID</th>
                                    <th>Hotel</th>
                                    <th>Kota</th>
                                    <th>Tipe Kamar</th>
                                    <th>Check-in</th>
                                    <th>Check-out</th>
                                    <th>Total Harga</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['top_revenue_bookings'] as $b): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($b['booking_id']) ?></strong></td>
                                        <td><?= htmlspecialchars($b['nama_hotel']) ?></td>
                                        <td><?= htmlspecialchars($b['kota']) ?></td>
                                        <td><?= htmlspecialchars($b['nama_tipe']) ?></td>
                                        <td><?= htmlspecialchars($b['check_in']) ?></td>
                                        <td><?= htmlspecialchars($b['check_out']) ?></td>
                                        <td>Rp <?= number_format((float)$b['total_harga'], 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">star</i>
                            <p>Tidak ada data top revenue bookings untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($filter_type === 'booking'): ?>
                <!-- BOOKING ANALYTICS -->
                <div class="kpi-grid">
                    <div class="kpi-card primary">
                        <div class="kpi-icon">
                            <i class="material-icons">book_online</i>
                        </div>
                        <div class="kpi-label">Total Bookings</div>
                        <div class="kpi-value"><?= number_format((int)($analytics_data['total_bookings'] ?? 0)) ?></div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">date_range</i>
                            Periode <?= htmlspecialchars($filter_period) ?>
                        </div>
                    </div>

                    <div class="kpi-card success">
                        <div class="kpi-icon">
                            <i class="material-icons">check_circle</i>
                        </div>
                        <div class="kpi-label">Completed</div>
                        <div class="kpi-value"><?= number_format((int)($analytics_data['total_completed'] ?? 0)) ?></div>
                        <div class="kpi-change change-positive">
                            <i class="material-icons" style="font-size: 14px;">verified</i>
                            Success Rate <?= number_format((float)($analytics_data['success_rate'] ?? 0), 1) ?>%
                        </div>
                    </div>

                    <div class="kpi-card warning">
                        <div class="kpi-icon">
                            <i class="material-icons">cancel</i>
                        </div>
                        <div class="kpi-label">Cancelled</div>
                        <div class="kpi-value"><?= number_format((int)($analytics_data['total_cancelled'] ?? 0)) ?></div>
                        <div class="kpi-change <?= ((float)($analytics_data['cancellation_rate'] ?? 0) <= 10) ? 'change-positive' : (((float)($analytics_data['cancellation_rate'] ?? 0) <= 25) ? 'change-medium' : 'change-negative') ?>">
                            <i class="material-icons" style="font-size: 14px;">trending_down</i>
                            Cancel Rate <?= number_format((float)($analytics_data['cancellation_rate'] ?? 0), 1) ?>%
                        </div>
                    </div>

                    <div class="kpi-card info">
                        <div class="kpi-icon">
                            <i class="material-icons">schedule</i>
                        </div>
                        <div class="kpi-label">Avg Lead Time</div>
                        <div class="kpi-value"><?= number_format((float)($analytics_data['avg_lead_time'] ?? 0), 1) ?> hari</div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">timelapse</i>
                            Rata-rata jarak booking ke check-in
                        </div>
                    </div>
                </div>

                <!-- Trend Booking -->
                <div class="performance-section">
                    <h2><i class="material-icons">trending_up</i> Tren Booking - Visualisasi Data</h2>
                    <div class="performance-card">
                        <h3>
                            <i class="material-icons">bar_chart</i>
                            Booking
                            <?= $filter_period === 'daily' ? 'per Jam' : ($filter_period === 'monthly' ? 'per Hari' : 'per Bulan') ?>
                        </h3>
                        <div class="chart-container">
                            <canvas id="bookingTrendChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Booking Methods -->
                <div class="performance-section">
                    <h2><i class="material-icons">pie_chart</i> <?= te('Distribusi Metode Pembayaran / Booking') ?></h2>

                    <?php if (!empty($analytics_data['booking_methods'])): ?>
                        <div class="performance-grid">
                            <div class="performance-card">
                                <h3><i class="material-icons">donut_large</i> <?= te('Diagram Pai Metode Booking') ?></h3>
                                <div class="chart-container">
                                    <canvas id="bookingMethodsChart"></canvas>
                                </div>
                            </div>

                            <div class="performance-card">
                                <h3><i class="material-icons">table_chart</i> Detail Data</h3>
                                <table class="performance-table">
                                    <thead>
                                        <tr>
                                            <th>Metode</th>
                                            <th>Jumlah</th>
                                            <th>Persentase</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (($analytics_data['booking_methods'] ?? []) as $m): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($m['source'] ?? '-') ?></strong></td>
                                                <td><?= number_format((int)($m['count'] ?? 0)) ?></td>
                                                <td><?= number_format((float)($m['percentage'] ?? 0), 1) ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">pie_chart</i>
                            <p>Tidak ada data metode booking untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Lead Time (opsional tapi berguna untuk Booking) -->
                <div class="performance-section">
                    <h2><i class="material-icons">schedule</i> <?= te('Analisis Lead Time') ?></h2>
                    <?php if (!empty($analytics_data['leadtime_analysis'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Lead Time (hari)</th>
                                    <th>Jumlah Booking</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (($analytics_data['leadtime_analysis'] ?? []) as $lt): ?>
                                    <tr>
                                        <td><strong><?= number_format((int)($lt['lead_time_days'] ?? 0)) ?> hari</strong></td>
                                        <td><?= number_format((int)($lt['booking_count'] ?? 0)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">schedule</i>
                            <p>Tidak ada data lead time untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Booking Pattern -->
                <div class="performance-section">
                    <h2><i class="material-icons">event</i> Pola Booking Berdasarkan Tipe Kamar & Hari</h2>

                    <?php if (!empty($analytics_data['booking_pattern'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Tipe Kamar</th>
                                    <th>Hari</th>
                                    <th>Jumlah Booking</th>
                                    <th>Rata-rata Lama Menginap</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (($analytics_data['booking_pattern'] ?? []) as $p): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($p['nama_tipe'] ?? '-') ?></strong></td>
                                        <td><?= htmlspecialchars($p['day_of_week'] ?? '-') ?></td>
                                        <td><?= number_format((int)($p['booking_count'] ?? 0)) ?></td>
                                        <td><?= number_format((float)($p['avg_stay'] ?? 0), 1) ?> malam</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">event_busy</i>
                            <p>Tidak ada data pola booking untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($filter_type === 'room'): ?>

                <!-- ROOM PERFORMANCE ANALYTICS -->
                <div class="kpi-grid">
                    <div class="kpi-card primary">
                        <div class="kpi-icon">
                            <i class="material-icons">hotel</i>
                        </div>
                        <div class="kpi-label">Average Length of Stay</div>
                        <div class="kpi-value"><?= number_format($analytics_data['overall_avg_stay'] ?? 0, 1) ?> hari</div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">nightlight</i>
                            Rata-rata lama menginap
                        </div>
                    </div>

                    <div class="kpi-card success">
                        <div class="kpi-icon">
                            <i class="material-icons">king_bed</i>
                        </div>
                        <div class="kpi-label">Minimum Stay</div>
                        <div class="kpi-value"><?= number_format($analytics_data['min_stay'] ?? 0, 1) ?> hari</div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">minimize</i>
                            Stay terpendek
                        </div>
                    </div>

                    <div class="kpi-card info">
                        <div class="kpi-icon">
                            <i class="material-icons">king_bed</i>
                        </div>
                        <div class="kpi-label">Maximum Stay</div>
                        <div class="kpi-value"><?= number_format($analytics_data['max_stay'] ?? 0, 1) ?> hari</div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">maximize</i>
                            Stay terpanjang
                        </div>
                    </div>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">star</i> Top 10 Performa Kamar - Visualisasi Analisis</h2>
                    <?php if (!empty($analytics_data['popular_rooms'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Hotel</th>
                                    <th>Tipe Kamar</th>
                                    <th>Jumlah Booking</th>
                                    <th>Total Revenue</th>
                                    <th>Avg Length of Stay</th>
                                    <th>Market Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['popular_rooms'] as $room): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($room['nama_hotel']) ?></strong></td>
                                        <td><?= htmlspecialchars($room['nama_tipe']) ?></td>
                                        <td><?= number_format($room['booking_count']) ?></td>
                                        <td>Rp <?= number_format($room['total_revenue'] ?? 0, 0, ',', '.') ?></td>
                                        <td><?= number_format($room['avg_length_of_stay'] ?? 0, 1) ?> hari</td>
                                        <td><?= number_format($room['market_share'] ?? 0, 1) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">room_service</i>
                            <p>Tidak ada data kamar untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">leaderboard</i> Performa Berdasarkan Tipe Kamar - Visualisasi Produk</h2>
                    <?php if (!empty($analytics_data['roomtype_revenue'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Tipe Kamar</th>
                                    <th>Total Bookings</th>
                                    <th>Total Revenue</th>
                                    <th>Avg Revenue per Booking</th>
                                    <th>Avg Length of Stay</th>
                                    <th>Booking %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['roomtype_revenue'] as $type): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($type['nama_tipe']) ?></strong></td>
                                        <td><?= number_format($type['total_bookings']) ?></td>
                                        <td>Rp <?= number_format($type['total_revenue'] ?? 0, 0, ',', '.') ?></td>
                                        <td>Rp <?= number_format($type['avg_revenue_per_booking'] ?? 0, 0, ',', '.') ?></td>
                                        <td><?= number_format($type['avg_length_of_stay'] ?? 0, 1) ?> hari</td>
                                        <td><?= number_format($type['booking_percentage'] ?? 0, 1) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">category</i>
                            <p>Tidak ada data tipe kamar untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">calendar_today</i> <?= te('Utilisasi Kamar berdasarkan Hari - Visualisasi Jadwal') ?></h2>
                    <?php if (!empty($analytics_data['room_utilization'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Day of Week</th>
                                    <th>Rooms Occupied</th>
                                    <th>Booking Count</th>
                                    <th>Avg Revenue per Booking</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['room_utilization'] as $util): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($util['day_of_week']) ?></strong></td>
                                        <td><?= number_format($util['rooms_occupied']) ?></td>
                                        <td><?= number_format($util['booking_count']) ?></td>
                                        <td>Rp <?= number_format($util['avg_revenue_per_booking'] ?? 0, 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">calendar_today</i>
                            <p>Tidak ada data utilisasi untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($filter_type === 'customer'): ?>
                <!-- CUSTOMER ANALYTICS -->
                <div class="kpi-grid">
                    <div class="kpi-card primary">
                        <div class="kpi-icon">
                            <i class="material-icons">people</i>
                        </div>
                        <div class="kpi-label">Total Customers</div>
                        <div class="kpi-value"><?= number_format($analytics_data['total_customers'] ?? 0) ?></div>
                        <div class="kpi-change change-neutral">
                            <i class="material-icons" style="font-size: 14px;">group</i>
                            Unique Customers
                        </div>
                    </div>

                    <div class="kpi-card success">
                        <div class="kpi-icon">
                            <i class="material-icons">person_add</i>
                        </div>
                        <div class="kpi-label">New Customers</div>
                        <div class="kpi-value"><?= number_format($analytics_data['new_customers'] ?? 0) ?></div>
                        <div class="kpi-change change-positive">
                            <i class="material-icons" style="font-size: 14px;">add</i>
                            <?= $analytics_data['total_customers'] > 0 ? number_format(($analytics_data['new_customers'] / $analytics_data['total_customers']) * 100, 1) : 0 ?>% of total
                        </div>
                    </div>

                    <div class="kpi-card info">
                        <div class="kpi-icon">
                            <i class="material-icons">repeat</i>
                        </div>
                        <div class="kpi-label">Repeat Customers</div>
                        <div class="kpi-value"><?= number_format($analytics_data['repeat_customers'] ?? 0) ?></div>
                        <div class="kpi-change change-positive">
                            <i class="material-icons" style="font-size: 14px;">autorenew</i>
                            <?= $analytics_data['total_customers'] > 0 ? number_format(($analytics_data['repeat_customers'] / $analytics_data['total_customers']) * 100, 1) : 0 ?>% of total
                        </div>
                    </div>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">people</i> <?= te('Analisis Segmentasi Pelanggan - Visualisasi Segmentasi') ?></h2>
                    <?php if (!empty($analytics_data['customer_segments'])): ?>
                        <div class="performance-grid">
                            <div class="performance-card">
                                <h3><i class="material-icons">donut_large</i> <?= te('Distribusi Tipe Pelanggan') ?></h3>
                                <div class="chart-container">
                                    <canvas id="customerTypeChart"></canvas>
                                </div>
                            </div>
                            <div class="performance-card">
                                <h3><i class="material-icons">table_chart</i> <?= te('Detail Segmentasi Pelanggan') ?></h3>
                                <table class="performance-table">
                                    <thead>
                                        <tr>
                                            <th>Username</th>
                                            <th>Type</th>
                                            <th>Total Bookings</th>
                                            <th>Total Spent</th>
                                            <th>Avg Stay (days)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($analytics_data['customer_segments'], 0, 10) as $segment): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($segment['username']) ?></strong></td>
                                                <td class="<?= $segment['customer_type'] === 'Repeat Customer' ? 'alert-low' : 'alert-medium' ?>">
                                                    <?= htmlspecialchars($segment['customer_type']) ?>
                                                </td>
                                                <td><?= number_format($segment['total_bookings']) ?></td>
                                                <td>Rp <?= number_format($segment['total_spent'] ?? 0, 0, ',', '.') ?></td>
                                                <td><?= number_format($segment['avg_stay_length'] ?? 0, 1) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">people</i>
                            <p>Tidak ada data pelanggan untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">source</i> <?= te('Analisis Sumber Pelanggan - Visualisasi Channel') ?></h2>
                    <?php if (!empty($analytics_data['customer_sources'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Sumber/Channel</th>
                                    <th>Jumlah Booking</th>
                                    <th>Unique Customers</th>
                                    <th>Avg Booking Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['customer_sources'] as $source): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($source['source']) ?></strong></td>
                                        <td><?= number_format($source['booking_count']) ?></td>
                                        <td><?= number_format($source['unique_customers']) ?></td>
                                        <td>Rp <?= number_format($source['avg_booking_value'] ?? 0, 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">source</i>
                            <p>Tidak ada data sumber pelanggan untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">star</i> <?= te('Top 10 Pelanggan berdasarkan Nilai - Visualisasi VIP') ?></h2>
                    <?php if (!empty($analytics_data['top_customers'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Total Bookings</th>
                                    <th>Total Spent</th>
                                    <th>Avg Stay</th>
                                    <th>Last Booking</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['top_customers'] as $customer): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($customer['username']) ?></strong></td>
                                        <td><?= htmlspecialchars($customer['email']) ?></td>
                                        <td><?= htmlspecialchars($customer['no_hp'] ?? '-') ?></td>
                                        <td><?= number_format($customer['total_bookings']) ?></td>
                                        <td>Rp <?= number_format($customer['total_spent'] ?? 0, 0, ',', '.') ?></td>
                                        <td><?= number_format($customer['avg_stay_length'] ?? 0, 1) ?> hari</td>
                                        <td><?= $customer['last_booking_date'] ? date('d M Y', strtotime($customer['last_booking_date'])) : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">star</i>
                            <p>Tidak ada data top pelanggan untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="performance-section">
                    <h2><i class="material-icons">attach_money</i> <?= te('Analisis Customer Lifetime Value (CLV) - Visualisasi Nilai') ?></h2>
                    <?php if (!empty($analytics_data['clv_analysis'])): ?>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Visit Count</th>
                                    <th>Total Value</th>
                                    <th>Avg Value per Visit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analytics_data['clv_analysis'] as $clv): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($clv['username']) ?></strong></td>
                                        <td><?= htmlspecialchars($clv['email']) ?></td>
                                        <td><?= number_format($clv['visit_count']) ?></td>
                                        <td>Rp <?= number_format($clv['total_value'] ?? 0, 0, ',', '.') ?></td>
                                        <td>Rp <?= number_format($clv['avg_value_per_visit'] ?? 0, 0, ',', '.') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">attach_money</i>
                            <p>Tidak ada data CLV untuk periode ini</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </main>

    <script>
        // --- JAVASCRIPT LOGIC UNTUK VISUALISASI DATA DAN FILTER ---

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

        // Restore the saved state, then toggle the same way at every width:
        // .collapsed is position:fixed + translateX, so it works on mobile too.
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

        // Change analytics type
        function changeAnalyticsType(type) {
            const url = new URL(window.location.href);
            url.searchParams.set('type', type);

            // Preserve all existing filter parameters
            const params = ['period', 'year', 'month', 'date', 'city', 'hotel', 'room_type'];
            params.forEach(param => {
                const value = document.getElementById(`filter_${param}`)?.value;
                if (value) {
                    url.searchParams.set(param, value);
                }
            });

            window.location.href = url.toString();
        }

        // Update filter fields based on period selection
        function updateFilterFields() {
            const period = document.getElementById('filter_period').value;
            const dateField = document.getElementById('dateField');
            const monthField = document.getElementById('monthField');
            const yearField = document.getElementById('yearField');

            if (period === 'daily') {
                dateField.style.display = 'flex';
                monthField.style.display = 'none';
                yearField.style.display = 'flex';
            } else if (period === 'weekly') {
                dateField.style.display = 'none';
                monthField.style.display = 'none';
                yearField.style.display = 'flex';
            } else if (period === 'monthly') {
                dateField.style.display = 'none';
                monthField.style.display = 'flex';
                yearField.style.display = 'flex';
            } else if (period === 'yearly') {
                dateField.style.display = 'none';
                monthField.style.display = 'none';
                yearField.style.display = 'flex';
            }
        }

        // Update hotel options based on selected city
        function updateHotelOptions() {
            const city = document.getElementById('filter_city').value;
            const hotelSelect = document.getElementById('filter_hotel');

            if (city) {
                // In a real application, you would fetch hotels via AJAX
                // For now, we'll just show all hotels and let the server handle filtering
                console.log('City selected:', city);
                // You can implement AJAX call here to fetch hotels for the selected city
            }
        }

        // Initialize Charts and Sidebar Dropdowns
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize filter fields
            updateFilterFields();

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

            // Define colors for charts
            const primaryColor = '#FF7A3D';
            const secondaryColor = '#eda100';
            const accentColor = '#4fc3f7';
            const successColor = '#1baf7a';
            const warningColor = '#eda100';
            const dangerColor = '#e34948';
            const infoColor = '#2a78d6';

            <?php if ($filter_type === 'overview' && isset($analytics_data['monthly_trends'])): ?>
                // Monthly Trend Chart for Overview
                const monthlyTrendCtx = document.getElementById('monthlyTrendChart');
                if (monthlyTrendCtx) {
                    const monthlyTrends = <?= json_encode($analytics_data['monthly_trends']) ?>;
                    const currentYear = <?= date('Y') ?>;
                    const prevYear = currentYear - 1;

                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    const currentYearData = [];
                    const prevYearData = [];

                    for (let i = 1; i <= 12; i++) {
                        currentYearData.push(monthlyTrends[currentYear] && monthlyTrends[currentYear][i] ? monthlyTrends[currentYear][i].bookings || 0 : 0);
                        prevYearData.push(monthlyTrends[prevYear] && monthlyTrends[prevYear][i] ? monthlyTrends[prevYear][i].bookings || 0 : 0);
                    }

                    new Chart(monthlyTrendCtx.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: months,
                            datasets: [{
                                    label: 'Tahun ' + prevYear,
                                    data: prevYearData,
                                    borderColor: primaryColor,
                                    backgroundColor: 'rgba(255, 122, 61, 0.1)',
                                    borderWidth: 2,
                                    tension: 0.4,
                                    fill: true
                                },
                                {
                                    label: 'Tahun ' + currentYear,
                                    data: currentYearData,
                                    borderColor: successColor,
                                    backgroundColor: 'rgba(76, 175, 80, 0.1)',
                                    borderWidth: 3,
                                    tension: 0.4,
                                    fill: true
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Perbandingan Tren Booking Tahunan - Visualisasi Data',
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
                                    mode: 'index',
                                    intersect: false
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
                                        drawBorder: false
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            },
                            interaction: {
                                intersect: false,
                                mode: 'nearest'
                            }
                        }
                    });
                }
            <?php endif; ?>

            <?php if ($filter_type === 'occupancy' && isset($analytics_data['occupancy_data'])): ?>
                // Occupancy Chart
                const occupancyCtx = document.getElementById('occupancyChart');
                if (occupancyCtx) {
                    const occupancyData = <?= json_encode($analytics_data['occupancy_data']) ?>;

                    let labels = [];
                    let data = [];

                    if (occupancyData.length > 0) {
                        <?php if ($filter_period === 'weekly'): ?>
                            const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                            occupancyData.forEach(item => {
                                labels.push(item.day_name);
                                data.push(item.occupancy_rate);
                            });
                        <?php elseif ($filter_period === 'yearly'): ?>
                            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                            occupancyData.forEach(item => {
                                labels.push(monthNames[parseInt(item.month_num) - 1]);
                                data.push(item.occupancy_rate);
                            });
                        <?php else: ?>
                            occupancyData.forEach(item => {
                                labels.push('Hari ' + item.day);
                                data.push(item.occupancy_rate);
                            });
                        <?php endif; ?>
                    }

                    new Chart(occupancyCtx.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Occupancy Rate (%)',
                                data: data,
                                backgroundColor: primaryColor,
                                borderColor: '#FF7A3D',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Tren Tingkat Hunian - Visualisasi Data',
                                    font: {
                                        size: 16
                                    }
                                },
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return 'Occupancy Rate: ' + context.raw + '%';
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 100,
                                    title: {
                                        display: true,
                                        text: 'Occupancy Rate (%)',
                                        font: {
                                            weight: 'bold'
                                        }
                                    },
                                    grid: {
                                        drawBorder: false
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }
            <?php endif; ?>

            <?php if ($filter_type === 'revenue' && isset($analytics_data['revenue_data'])): ?>
                // Revenue Trend Chart
                const revenueTrendCtx = document.getElementById('revenueTrendChart');
                if (revenueTrendCtx) {
                    const revenueData = <?= json_encode($analytics_data['revenue_data']) ?>;

                    let labels = [];
                    let data = [];

                    if (revenueData.length > 0) {
                        <?php if ($filter_period === 'yearly'): ?>
                            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                            revenueData.forEach(item => {
                                labels.push(monthNames[parseInt(item.month_num) - 1]);
                                data.push(item.revenue || 0);
                            });
                        <?php else: ?>
                            revenueData.forEach(item => {
                                labels.push('Tanggal ' + item.date.split('-')[2]);
                                data.push(item.revenue || 0);
                            });
                        <?php endif; ?>
                    }

                    new Chart(revenueTrendCtx.getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Pendapatan (IDR)',
                                data: data,
                                borderColor: primaryColor,
                                backgroundColor: 'rgba(255, 122, 61, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Tren Pendapatan - Visualisasi Data',
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
                                            return 'Pendapatan: Rp ' + context.raw.toLocaleString('id-ID');
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
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
                                    grid: {
                                        drawBorder: false
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }

                // Revenue by Type Chart
                const revenueByTypeCtx = document.getElementById('revenueByTypeChart');
                if (revenueByTypeCtx) {
                    const revenueByType = <?= json_encode($analytics_data['revenue_by_type']) ?>;
                    const labels = revenueByType.map(item => item.nama_tipe);
                    const data = revenueByType.map(item => item.revenue || 0);

                    new Chart(revenueByTypeCtx.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: data,
                                backgroundColor: [
                                    primaryColor,
                                    secondaryColor,
                                    accentColor,
                                    infoColor,
                                    successColor,
                                    warningColor
                                ],
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
                                    text: 'Distribusi Pendapatan Berdasarkan Tipe Kamar',
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
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                            return `${label}: Rp ${value.toLocaleString('id-ID')} (${percentage}%)`;
                                        }
                                    }
                                }
                            },
                            cutout: '60%',
                        }
                    });
                }
            <?php endif; ?>

            <?php if ($filter_type === 'booking' && isset($analytics_data['booking_data'])): ?>
                // Booking Trend Chart
                const bookingTrendCtx = document.getElementById('bookingTrendChart');
                if (bookingTrendCtx) {
                    const bookingData = <?= json_encode($analytics_data['booking_data']) ?>;

                    let labels = [];
                    let totalData = [];
                    let completedData = [];
                    let cancelledData = [];

                    if (bookingData.length > 0) {
                        <?php if ($filter_period === 'daily'): ?>
                            bookingData.forEach(item => {
                                labels.push(item.hour + ':00');
                                totalData.push(item.total_bookings || 0);
                                completedData.push(item.completed || 0);
                                cancelledData.push(item.cancelled || 0);
                            });
                        <?php elseif ($filter_period === 'yearly'): ?>
                            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                            bookingData.forEach(item => {
                                labels.push(monthNames[parseInt(item.month_num) - 1]);
                                totalData.push(item.total_bookings || 0);
                                completedData.push(item.completed || 0);
                                cancelledData.push(item.cancelled || 0);
                            });
                        <?php else: ?>
                            bookingData.forEach(item => {
                                labels.push('Hari ' + item.day);
                                totalData.push(item.total_bookings || 0);
                                completedData.push(item.completed || 0);
                                cancelledData.push(item.cancelled || 0);
                            });
                        <?php endif; ?>
                    }

                    new Chart(bookingTrendCtx.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                    label: 'Total Bookings',
                                    data: totalData,
                                    backgroundColor: primaryColor,
                                    order: 3
                                },
                                {
                                    label: 'Completed',
                                    data: completedData,
                                    backgroundColor: successColor,
                                    order: 2
                                },
                                {
                                    label: 'Cancelled',
                                    data: cancelledData,
                                    backgroundColor: dangerColor,
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
                                    text: '<?= $filter_period === 'daily' ? 'Tren Booking per Jam' : ($filter_period === 'yearly' ? 'Tren Booking per Bulan' : 'Tren Booking per Hari') ?> - Visualisasi Data',
                                    font: {
                                        size: 16
                                    }
                                },
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        usePointStyle: true
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
                                        drawBorder: false
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    }
                                }
                            }
                        }
                    });
                }

                // Booking Method Chart
                const bookingMethodCtx = document.getElementById('bookingMethodChart');
                if (bookingMethodCtx) {
                    const bookingMethods = <?= json_encode($analytics_data['booking_methods']) ?>;
                    const labels = bookingMethods.map(item => item.source);
                    const data = bookingMethods.map(item => item.count);

                    new Chart(bookingMethodCtx.getContext('2d'), {
                        type: 'pie',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: data,
                                backgroundColor: [
                                    primaryColor,
                                    secondaryColor,
                                    accentColor,
                                    successColor,
                                    warningColor
                                ],
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
                                    text: 'Distribusi Metode Booking - Visualisasi Data',
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
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                            return `${label}: ${value} (${percentage}%)`;
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            <?php endif; ?>

            <?php if ($filter_type === 'customer' && isset($analytics_data['customer_segments'])): ?>
                // Customer Type Chart
                const customerTypeCtx = document.getElementById('customerTypeChart');
                if (customerTypeCtx) {
                    const customerSegments = <?= json_encode($analytics_data['customer_segments']) ?>;

                    // Aggregate by customer type
                    const newCustomers = customerSegments.filter(c => c.customer_type === 'New Customer').length;
                    const repeatCustomers = customerSegments.filter(c => c.customer_type === 'Repeat Customer').length;

                    const labels = ['New Customers', 'Repeat Customers'];
                    const data = [newCustomers, repeatCustomers];

                    new Chart(customerTypeCtx.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: data,
                                backgroundColor: [
                                    primaryColor,
                                    successColor
                                ],
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
                                    text: 'Distribusi Tipe Pelanggan - Visualisasi Data',
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
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                            return `${label}: ${value} customers (${percentage}%)`;
                                        }
                                    }
                                }
                            },
                            cutout: '60%',
                        }
                    });
                }
            <?php endif; ?>

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

            // Prevent unwanted transforms (Safety Fix)
            document.querySelectorAll('.dropdown-content, .dropdown-item').forEach(el => {
                el.style.transform = 'none';
            });
        });

    </script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Data dari PHP (AMAN: kalau kosong jadi [])
            const bookingMethods = <?= json_encode($analytics_data['booking_methods'] ?? []) ?>;

            // Kalau data kosong, stop supaya tidak error
            if (!Array.isArray(bookingMethods) || bookingMethods.length === 0) {
                console.warn("booking_methods kosong, chart tidak dibuat");
                return;
            }

            // Ambil label & value sesuai field dari query kamu: source, count
            const labels = bookingMethods.map(x => x.source ?? 'Unknown');
            const values = bookingMethods.map(x => Number(x.count ?? 0));

            const canvas = document.getElementById("bookingMethodsChart");
            if (!canvas) {
                console.error("Canvas bookingMethodsChart tidak ditemukan");
                return;
            }

            // Kalau chart di-render ulang (misal ganti filter), destroy dulu biar tidak bentrok
            if (window.bookingMethodsChartInstance) {
                window.bookingMethodsChartInstance.destroy();
            }

            window.bookingMethodsChartInstance = new Chart(canvas, {
                type: "doughnut",
                data: {
                    labels: labels,
                    datasets: [{
                        data: values
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: "bottom"
                        },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    const total = values.reduce((a, b) => a + b, 0) || 1;
                                    const v = ctx.raw || 0;
                                    const p = (v * 100 / total).toFixed(1);
                                    return `${ctx.label}: ${v} (${p}%)`;
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>

</body>

</html>