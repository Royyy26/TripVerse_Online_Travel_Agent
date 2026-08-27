<?php
session_start();

// Periksa apakah pengguna sudah login dan memiliki role 'admin'
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

// Function untuk mendapatkan detail booking
function getBookingDetails($bookingId)
{
    global $conn;

    $sql = "SELECT 
                bh.booking_id,
                bh.customer_name,
                c.email,
                c.no_hp as phone,
                h.nama_hotel,
                tk.nama_tipe as room_type,
                bh.check_in,
                bh.check_out,
                bh.jumlah_kamar as guests,
                bh.total_harga,
                bh.status,
                bh.tanggal_booking as booking_date,
                bh.catatan as special_requests,
                bh.metode_pembayaran,
                bh.jadwal_id
            FROM booking_hotel bh
            LEFT JOIN customer c ON bh.customer_id = c.customer_id
            LEFT JOIN hotel h ON bh.hotel_id = h.hotel_id
            LEFT JOIN tipe_kamar tk ON bh.tipe_id = tk.tipe_id
            WHERE bh.booking_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

// Function untuk update booking
function updateBooking($bookingData)
{
    global $conn;

    $sql = "UPDATE booking_hotel SET 
                customer_name = ?,
                check_in = ?,
                check_out = ?,
                jumlah_kamar = ?,
                total_harga = ?,
                status = ?,
                catatan = ?
            WHERE booking_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sssidsss",
        $bookingData['customer_name'],
        $bookingData['check_in'],
        $bookingData['check_out'],
        $bookingData['jumlah_kamar'],
        $bookingData['total_harga'],
        $bookingData['status'],
        $bookingData['catatan'],
        $bookingData['booking_id']
    );

    return $stmt->execute();
}

// Function untuk format Rupiah
function formatRupiah($angka)
{
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Function untuk format bulan Indonesia
function formatBulanIndonesia($monthName)
{
    $bulan = [
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    ];
    return $bulan[$monthName] ?? $monthName;
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'get_booking_details':
            if (isset($_GET['booking_id'])) {
                $bookingDetails = getBookingDetails($_GET['booking_id']);
                if ($bookingDetails) {
                    echo json_encode([
                        'success' => true,
                        'data' => $bookingDetails
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Booking not found'
                    ]);
                }
            }
            break;

        case 'update_booking':
            if ($_POST) {
                $result = updateBooking([
                    'booking_id' => $_POST['booking_id'],
                    'customer_name' => $_POST['customer_name'],
                    'check_in' => $_POST['check_in'],
                    'check_out' => $_POST['check_out'],
                    'jumlah_kamar' => $_POST['jumlah_kamar'],
                    'total_harga' => $_POST['total_harga'],
                    'status' => $_POST['status'],
                    'catatan' => $_POST['catatan']
                ]);

                echo json_encode([
                    'success' => $result,
                    'message' => $result ? 'Booking updated successfully' : 'Failed to update booking'
                ]);
            }
            break;
    }
    exit;
}

// Ambil data admin
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
    $foto       = $data['profile_picture'] ?: '../../images/default.jpg';
} else {
    $username = "Unknown";
    $email = "unknown@tripverse.com";
    $firstName = $lastName = $mobile = $gender = "-";
    $foto = "../../images/default.jpg";
}

// --- DSS STATISTICS (KPIs) ---
$dss_stats = [
    // Booking Trends
    'total_bookings' => 0,
    'completed_bookings' => 0,
    'cancelled_bookings' => 0,
    'pending_bookings' => 0,

    // Financial Metrics
    'total_revenue' => 0,
    'monthly_revenue' => 0,
    'average_booking_value' => 0,

    // Hotel Performance
    'total_hotels' => 0,
    'active_hotels' => 0,
    'total_customers' => 0,

    // ALOS (Average Length of Stay)
    'average_los' => 0,

    // Cancellation Analysis
    'cancellation_rate' => 0,
    'revenue_loss' => 0
];

// Dapatkan statistik booking untuk KPI
$query = "SELECT 
            COUNT(*) as total_bookings,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'Completed' THEN total_harga ELSE 0 END) as total_revenue,
            AVG(CASE WHEN status = 'Completed' THEN DATEDIFF(check_out, check_in) ELSE NULL END) as avg_los
          FROM booking_hotel";

$result = $conn->query($query);
if ($row = $result->fetch_assoc()) {
    $dss_stats['total_bookings'] = $row['total_bookings'];
    $dss_stats['completed_bookings'] = $row['completed'];
    $dss_stats['cancelled_bookings'] = $row['cancelled'];
    $dss_stats['pending_bookings'] = $row['pending'];
    $dss_stats['total_revenue'] = $row['total_revenue'] ?? 0;
    $dss_stats['average_los'] = round($row['avg_los'] ?? 0, 1);
    $dss_stats['cancellation_rate'] = $row['total_bookings'] > 0 ? round(($row['cancelled'] / $row['total_bookings']) * 100, 2) : 0;
    $dss_stats['average_booking_value'] = $row['completed'] > 0 ? round($row['total_revenue'] / $row['completed'], 2) : 0;
}

// Dapatkan monthly revenue
$query = "SELECT SUM(total_harga) as revenue 
          FROM booking_hotel 
          WHERE status = 'Completed' 
          AND MONTH(tanggal_booking) = MONTH(CURRENT_DATE())
          AND YEAR(tanggal_booking) = YEAR(CURRENT_DATE())";

$result = $conn->query($query);
if ($row = $result->fetch_assoc()) {
    $dss_stats['monthly_revenue'] = $row['revenue'] ?? 0;
}

// Dapatkan revenue loss
$query = "SELECT SUM(total_harga) as lost_revenue 
          FROM booking_hotel 
          WHERE status = 'Cancelled'";

$result = $conn->query($query);
if ($row = $result->fetch_assoc()) {
    $dss_stats['revenue_loss'] = $row['lost_revenue'] ?? 0;
}

// Dapatkan total customers
$query = "SELECT COUNT(*) as total FROM customer";
$result = $conn->query($query);
if ($row = $result->fetch_assoc()) {
    $dss_stats['total_customers'] = $row['total'];
}

// Dapatkan total hotels
$query = "SELECT COUNT(DISTINCT hotel_id) as total FROM hotel";
$result = $conn->query($query);
if ($row = $result->fetch_assoc()) {
    $dss_stats['total_hotels'] = $row['total'];
}

// Dapatkan active hotels
$query = "SELECT COUNT(DISTINCT h.hotel_id) as active
          FROM hotel h
          JOIN jadwal_hotel j ON h.hotel_id = j.hotel_id
          WHERE j.stok_total > j.terbooking";
$result = $conn->query($query);
if ($row = $result->fetch_assoc()) {
    $dss_stats['active_hotels'] = $row['active'];
}

// Dapatkan monthly booking trends untuk chart
$monthly_bookings_query = "SELECT 
    MONTHNAME(tanggal_booking) as month,
    COUNT(*) as bookings,
    SUM(CASE WHEN status = 'Completed' THEN total_harga ELSE 0 END) as revenue,
    SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancellations
    FROM booking_hotel 
    WHERE YEAR(tanggal_booking) = YEAR(CURRENT_DATE())
    GROUP BY MONTH(tanggal_booking), MONTHNAME(tanggal_booking)
    ORDER BY MONTH(tanggal_booking)";

$monthly_data = $conn->query($monthly_bookings_query);
$monthly_bookings = [];
$monthly_revenue = [];
$monthly_cancellations = [];
$months = [];

while ($row = $monthly_data->fetch_assoc()) {
    $months[] = formatBulanIndonesia($row['month']);
    $monthly_bookings[] = $row['bookings'];
    $monthly_revenue[] = $row['revenue'];
    $monthly_cancellations[] = $row['cancellations'];
}

// --- ROOM AVAILABILITY LOGIC ---
// Dapatkan total ketersediaan kamar
$room_availability_query = "SELECT 
    SUM(stok_total) as total_rooms, 
    SUM(terbooking) as booked_rooms
    FROM jadwal_hotel";

$room_result = $conn->query($room_availability_query);
$room_data = $room_result->fetch_assoc();
$total_rooms = $room_data['total_rooms'] ?? 0;
$booked_rooms = $room_data['booked_rooms'] ?? 0;
$available_rooms = $total_rooms - $booked_rooms;

// Untuk chart ketersediaan per Tipe Kamar
$room_by_type_query = "SELECT 
    t.nama_tipe,
    SUM(j.stok_total) as total,
    SUM(j.terbooking) as booked
    FROM tipe_kamar t
    JOIN jadwal_hotel j ON t.tipe_id = j.tipe_id
    GROUP BY t.nama_tipe";

$room_type_data = $conn->query($room_by_type_query);
$room_type_labels = [];
$room_type_available = [];
$room_type_booked = [];

while ($row = $room_type_data->fetch_assoc()) {
    $room_type_labels[] = $row['nama_tipe'];
    $room_type_booked[] = $row['booked'];
    $room_type_available[] = $row['total'] - $row['booked'];
}

// Dapatkan hotel performance data
$hotel_performance_query = "SELECT 
    h.nama_hotel,
    COUNT(b.booking_id) as total_bookings,
    SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) as revenue,
    AVG(CASE WHEN b.status = 'Completed' THEN DATEDIFF(b.check_out, b.check_in) ELSE NULL END) as avg_los
    FROM hotel h
    LEFT JOIN booking_hotel b ON h.hotel_id = b.hotel_id
    GROUP BY h.hotel_id, h.nama_hotel
    ORDER BY revenue DESC
    LIMIT 5";

$hotel_performance = $conn->query($hotel_performance_query);

// PERBAIKAN QUERY: Dapatkan SEMUA bookings untuk Booking List
$all_bookings_query = "SELECT b.booking_id, b.customer_name, b.check_in, b.check_out, b.total_harga, 
                      b.tanggal_booking, b.status, b.jumlah_kamar,
                      h.nama_hotel, t.nama_tipe
                      FROM booking_hotel b
                      JOIN hotel h ON b.hotel_id = h.hotel_id
                      JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
                      ORDER BY b.tanggal_booking DESC";
$allBookings = $conn->query($all_bookings_query);

// Dapatkan system notifications
$query = "SELECT COUNT(*) as notifications 
          FROM booking_hotel 
          WHERE status = 'Pending'";
$result = $conn->query($query);
$notificationCount = $result->fetch_assoc()['notifications'] ?? 0;

$stmt->close();
$conn->close();

// Penanganan upload foto profil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    $uploadDir = '../../uploads/';
    $fileName = uniqid() . '_' . basename($_FILES['profile_photo']['name']);
    $targetPath = $uploadDir . $fileName;

    $imageFileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];

    if (in_array($imageFileType, $allowedTypes)) {
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
            include __DIR__ . '/../connect.php';
            $update = $conn->prepare("UPDATE user SET profile_picture = ? WHERE id_user = ?");
            $update->bind_param("ss", $fileName, $id_user);
            $update->execute();
            $update->close();
            $conn->close();

            $_SESSION['upload_notification'] = "Profile photo updated successfully!";
            $foto = $fileName;
        } else {
            $_SESSION['upload_notification'] = "Failed to upload photo.";
        }
    } else {
        $_SESSION['upload_notification'] = "Invalid file type. Only JPG, PNG, GIF allowed.";
    }

    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>TripVerse Admin - Decision Support System</title>
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
            overflow-x: auto;
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

        .dss-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .dss-card {
            background: #f8f9fa;
            border-radius: var(--border-radius);
            padding: 20px;
            border: 1px solid #e9ecef;
            transition: var(--transition);
        }

        .dss-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--box-shadow);
        }

        .dss-card h3 {
            margin: 0 0 10px;
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

        /* Enhanced Booking List Styles */
        .booking-list-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
            margin-bottom: 30px;
            transition: var(--transition);
            border: 1px solid #e0e0e0;
        }

        .booking-list-section:hover {
            box-shadow: var(--box-shadow-hover);
            transform: translateY(-2px);
        }

        .booking-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }

        .booking-list-header h2 {
            margin: 0;
            font-size: 20px;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        /* Enhanced Search and Filter Section */
        .search-filter-section {
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

        .search-filter-section .form-group {
            margin: 0;
        }

        .search-filter-section input,
        .search-filter-section select {
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

        .search-filter-section input:focus,
        .search-filter-section select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 122, 61, 0.1);
            outline: none;
            transform: translateY(-1px);
        }

        .search-filter-section input:not(:placeholder-shown),
        .search-filter-section select:not([value=""]) {
            border-color: var(--primary-color);
            background: rgba(255, 122, 61, 0.03);
        }

        .apply-filters-btn {
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
            white-space: nowrap;
            justify-content: center;
        }

        .apply-filters-btn:hover {
            background: #E8672B;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 122, 61, 0.3);
        }

        .clear-all-btn {
            background: #6c757d;
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
            white-space: nowrap;
            justify-content: center;
        }

        .clear-all-btn:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }

        /* Filter Indicators */
        .filter-indicators {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .filter-indicator {
            background: linear-gradient(135deg, var(--primary-color), #FFB37A);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(255, 122, 61, 0.3);
        }

        .clear-filter {
            cursor: pointer;
            font-weight: bold;
            padding: 2px;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            font-size: 14px;
            line-height: 1;
        }

        .clear-filter:hover {
            background: rgba(255, 255, 255, 0.5);
            transform: scale(1.1);
        }

        .results-counter {
            font-size: 14px;
            color: var(--dark-color);
            font-weight: 600;
            padding: 6px 12px;
            background: #e9ecef;
            border-radius: 6px;
            margin-right: auto;
        }

        .bookings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .booking-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            padding: 20px;
            border-left: 4px solid;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid #f0f0f0;
        }

        .booking-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(255, 122, 61, 0.03) 0%, rgba(255, 122, 61, 0.01) 100%);
            transform: translate(40px, -40px);
        }

        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .booking-card.completed {
            border-left-color: var(--success-color);
        }

        .booking-card.pending {
            border-left-color: var(--warning-color);
        }

        .booking-card.cancelled {
            border-left-color: var(--danger-color);
        }

        .booking-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .booking-meta {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .booking-id {
            font-size: 11px;
            color: var(--text-light);
            background: #f5f5f5;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .booking-nights {
            font-size: 11px;
            color: var(--primary-color);
            background: rgba(255, 122, 61, 0.1);
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 500;
        }

        .booking-date {
            font-size: 10px;
            color: var(--text-light);
            background: rgba(108, 117, 125, 0.1);
            padding: 2px 6px;
            border-radius: 8px;
            font-weight: 500;
            margin-top: 2px;
        }

        .booking-status {
            font-size: 11px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 20px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 4px;
            letter-spacing: 0.5px;
        }

        .status-completed {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .status-pending {
            background: rgba(255, 152, 0, 0.1);
            color: var(--warning-color);
            border: 1px solid rgba(255, 152, 0, 0.2);
        }

        .status-cancelled {
            background: rgba(227, 73, 72, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(227, 73, 72, 0.2);
        }

        .booking-customer,
        .booking-hotel,
        .booking-room {
            font-size: 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--dark-color);
        }

        .booking-customer {
            font-weight: 600;
            font-size: 16px;
            color: var(--dark-color);
        }

        .booking-customer .material-icons {
            color: var(--primary-color);
            font-size: 18px;
        }

        .booking-hotel .material-icons {
            color: var(--info-color);
            font-size: 16px;
        }

        .booking-room .material-icons {
            color: var(--success-color);
            font-size: 16px;
        }

        .booking-dates {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 15px 0;
            padding: 15px;
            background: rgba(255, 122, 61, 0.03);
            border-radius: 8px;
            border: 1px solid rgba(255, 122, 61, 0.1);
        }

        .date-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .date-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 122, 61, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
        }

        .date-info {
            display: flex;
            flex-direction: column;
        }

        .date-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .date-value {
            font-size: 13px;
            font-weight: 600;
            color: var(--dark-color);
        }

        .booking-details {
            display: flex;
            gap: 15px;
            margin: 15px 0;
            padding: 0 5px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
        }

        .detail-label {
            font-size: 10px;
            color: var(--text-light);
            text-transform: uppercase;
            margin-bottom: 4px;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 13px;
            font-weight: 600;
            color: var(--dark-color);
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 6px;
            min-width: 40px;
            text-align: center;
        }

        .booking-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
            margin-top: 10px;
        }

        .booking-amount {
            display: flex;
            flex-direction: column;
        }

        .amount-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .amount-value {
            font-weight: 700;
            font-size: 16px;
            color: var(--dark-color);
            background: linear-gradient(135deg, var(--primary-color), #FFB37A);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .booking-actions {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            color: var(--text-light);
            border: 1px solid #e9ecef;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            transition: left 0.5s ease;
        }

        .action-btn:hover::before {
            left: 100%;
        }

        .action-btn:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 122, 61, 0.3);
            border-color: var(--primary-color);
        }

        .no-bookings-message {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }

        .no-bookings-message h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: var(--dark-color);
            font-weight: 600;
        }

        .no-bookings-message p {
            margin: 0;
            font-size: 14px;
            color: var(--text-light);
        }

        /* Enhanced Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-content {
            background-color: #fff;
            margin: 3% auto;
            border-radius: 16px;
            width: 90%;
            max-width: 700px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-60px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 30px;
            background: linear-gradient(135deg, var(--primary-color), #FFB37A);
            color: white;
            position: relative;
        }

        .modal-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.5), transparent);
        }

        .modal-header h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-modal {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: white;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            padding: 0;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .close-modal:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
            max-height: 65vh;
            overflow-y: auto;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 20px 30px;
            border-top: 1px solid #f0f0f0;
            background: #f8f9fa;
        }

        /* Enhanced Detail View Styles */
        .detail-section {
            margin-bottom: 25px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 4px solid var(--primary-color);
        }

        .detail-section:last-child {
            margin-bottom: 0;
        }

        .detail-section h3 {
            margin: 0 0 18px 0;
            font-size: 16px;
            color: var(--dark-color);
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            padding: 12px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .detail-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-color: var(--primary-color);
        }

        .detail-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            margin-bottom: 6px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--dark-color);
            line-height: 1.4;
        }

        .detail-value.status-completed {
            color: var(--success-color);
            background: rgba(76, 175, 80, 0.1);
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }

        .detail-value.status-pending {
            color: var(--warning-color);
            background: rgba(255, 152, 0, 0.1);
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }

        .detail-value.status-cancelled {
            color: var(--danger-color);
            background: rgba(227, 73, 72, 0.1);
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }

        /* Enhanced Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-color);
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background-color: #fff;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 122, 61, 0.1);
            outline: none;
            transform: translateY(-1px);
        }

        .form-control:disabled {
            background-color: #f8f9fa;
            color: #6c757d;
            cursor: not-allowed;
            border-color: #dee2e6;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        /* Loading State */
        .loading {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }

        .loading .material-icons {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ccc;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Error Message */
        .error-message {
            text-align: center;
            padding: 40px 20px;
            color: var(--danger-color);
        }

        .error-message .material-icons {
            font-size: 48px;
            margin-bottom: 15px;
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            animation: slideInRight 0.3s ease-out;
        }

        .notification-success {
            background: var(--success-color);
        }

        .notification-error {
            background: var(--danger-color);
        }

        .notification-info {
            background: var(--info-color);
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        /* Button Styles */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
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

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .view-all-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
        }

        .view-all-btn:hover {
            background: #E8672B;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 122, 61, 0.3);
        }

        /* Additional Styles for other sections */
        .metric-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .metric-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .metric-item:last-child {
            border-bottom: none;
        }

        .metric-label {
            font-size: 14px;
            color: var(--text-light);
        }

        .metric-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark-color);
        }

        .performance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .performance-table th,
        .performance-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .performance-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--text-light);
        }

        .performance-table tr:hover {
            background: #f8f9fa;
        }

        /* Notification Message */
        .notification-message {
            padding: 12px 20px;
            margin: 15px 0;
            border-radius: 6px;
            font-weight: 500;
        }

        .notification-message.success {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .notification-message.error {
            background: rgba(227, 73, 72, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(227, 73, 72, 0.2);
        }

        /* Profile styles */
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
            text-align: center;
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
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: none;
            margin-top: 8px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
            text-align: center;
        }

        .dropdown-content.show {
            display: block;
            animation: dropdownFadeIn 0.2s ease;
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

        /* Animation for filter results */
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

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Responsive Design for Filters */
        @media (max-width: 1200px) {
            .search-filter-section {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .booking-list-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }

            .header-actions {
                flex-direction: column;
                gap: 15px;
            }

            .search-filter-section {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .bookings-grid {
                grid-template-columns: 1fr;
            }

            .filter-indicators {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .results-counter {
                margin-right: 0;
                order: -1;
            }

            .modal-content {
                margin: 5% auto;
                width: 95%;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal-footer {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .dss-grid {
                grid-template-columns: 1fr;
            }

            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .search-filter-section {
                padding: 15px;
            }

            .filter-indicator {
                font-size: 12px;
                padding: 6px 12px;
            }

            .booking-card {
                padding: 15px;
            }

            .booking-dates {
                flex-direction: column;
            }

            .booking-details {
                flex-direction: column;
                gap: 10px;
            }

            .modal-body {
                padding: 20px;
            }

            .modal-header {
                padding: 20px;
            }

            .modal-footer {
                padding: 15px 20px;
            }

            .kpi-grid {
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
                                <span><?= te('Edit Profil') ?></span>
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
            <a href="dashboard.php" class="active">
                <span class="material-icons">dashboard</span>
                <span><?= te('Ringkasan Eksekutif') ?></span>
            </a>

            <!-- SUPPLIER APPROVAL -->
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="supplier_approvals.php">
                    <span class="material-icons">approval</span> <!-- atau groups, person_add -->
                    <span><?= te('Manajemen Supplier') ?></span>
                </a>
            <?php endif; ?>

            <!-- PROMO MANAGEMENT -->
            <a href="promo_management.php">
                <span class="material-icons">campaign</span> <!-- atau discount, local_offer -->
                <span><?= te('Manajemen Promo') ?></span>
            </a>

            <!-- ANALYTICS & INSIGHTS -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="analyticsDropdown">
                    <span class="material-icons">monitor</span> <!-- atau show_chart, trending_up -->
                    <span><?= te('Monitoring Performa') ?></span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="analyticsDropdown">
                    <a href="performance_analytics.php">
                        <span class="material-icons">bar_chart</span> <!-- atau assessment -->
                        <span><?= te('Statistik Performa') ?></span>
                    </a>
                    <a href="market_analysis.php">
                        <span class="material-icons">trending_up</span> <!-- atau timeline -->
                        <span><?= te('Tren Booking') ?></span>
                    </a>
                </div>
            </div>

            <!-- DECISION SUPPORT MODULES -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="decisionDropdown">
                    <span class="material-icons">analytics</span> <!-- atau calculate, functions -->
                    <span><?= te('Analisis Statistik') ?></span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="decisionDropdown">
                    <a href="revenue_optimization.php">
                        <span class="material-icons">attach_money</span> <!-- atau paid -->
                        <span><?= te('Statistik Pendapatan') ?></span>
                    </a>
                    <a href="occupancy_analysis.php">
                        <span class="material-icons">king_bed</span> <!-- atau hotel -->
                        <span><?= te('Statistik Okupansi') ?></span>
                    </a>
                    <a href="alos_analysis.php">
                        <span class="material-icons">calendar_today</span> <!-- atau date_range -->
                        <span><?= te('Statistik ALOS') ?></span>
                    </a>
                </div>
            </div>

            <!-- CUSTOMER INTELLIGENCE -->
            <a href="customerdss.php">
                <span class="material-icons">people</span> <!-- atau sentiment_satisfied -->
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

        <div class="dss-section">
            <h2><i class="material-icons">dashboard</i> <?= te('Visualisasi Data - TripVerse Admin') ?></h2>

            <div class="kpi-grid">
                <div class="kpi-card primary">
                    <div class="kpi-icon">
                        <i class="material-icons">attach_money</i>
                    </div>
                    <div class="kpi-label"><?= te('Total Pendapatan') ?></div>
                    <div class="kpi-value"><?= formatRupiah($dss_stats['total_revenue']) ?></div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">trending_up</i> <?= te('Aktif') ?>
                    </div>
                </div>
                <div class="kpi-card success">
                    <div class="kpi-icon">
                        <i class="material-icons">check_circle</i>
                    </div>
                    <div class="kpi-label"><?= te('Booking Selesai') ?></div>
                    <div class="kpi-value"><?= number_format($dss_stats['completed_bookings']) ?></div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">check_circle</i> <?= $dss_stats['total_bookings'] > 0 ? round(($dss_stats['completed_bookings'] / $dss_stats['total_bookings']) * 100, 1) : 0 ?>%
                    </div>
                </div>
                <div class="kpi-card warning">
                    <div class="kpi-icon">
                        <i class="material-icons">hotel</i>
                    </div>
                    <div class="kpi-label"><?= te('Rata-rata Lama Menginap') ?></div>
                    <div class="kpi-value"><?= $dss_stats['average_los'] ?> <?= te('Hari') ?></div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">trending_up</i> ALOS
                    </div>
                </div>
                <div class="kpi-card danger">
                    <div class="kpi-icon">
                        <i class="material-icons">cancel</i>
                    </div>
                    <div class="kpi-label"><?= te('Tingkat Pembatalan') ?></div>
                    <div class="kpi-value"><?= $dss_stats['cancellation_rate'] ?>%</div>
                    <div class="kpi-trend trend-down">
                        <i class="material-icons">warning</i> <?= te('Perlu Perhatian') ?>
                    </div>
                </div>
                <div class="kpi-card info">
                    <div class="kpi-icon">
                        <i class="material-icons">business</i>
                    </div>
                    <div class="kpi-label"><?= te('Hotel Aktif') ?></div>
                    <div class="kpi-value"><?= number_format($dss_stats['active_hotels']) ?>/<?= number_format($dss_stats['total_hotels']) ?></div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">trending_up</i> <?= te('Operasional') ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="dss-section">
            <h2><i class="material-icons">trending_up</i> <?= te('Analisis Tren Booking & Pendapatan') ?></h2>
            <div class="dss-grid">
                <div class="dss-card">
                    <h3><i class="material-icons">show_chart</i> <?= te('Tren Booking Bulanan') ?></h3>
                    <div class="chart-container">
                        <canvas id="bookingTrendChart"></canvas>
                    </div>
                </div>

                <div class="dss-card">
                    <h3><i class="material-icons">bar_chart</i> <?= te('Pendapatan Bulanan') ?></h3>
                    <div class="chart-container">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="dss-section">
            <h2><i class="material-icons">room_service</i> <?= te('Analisis Ketersediaan Kamar') ?></h2>
            <div class="dss-grid">
                <div class="dss-card">
                    <h3><i class="material-icons">pie_chart</i> <?= te('Stok Kamar Keseluruhan') ?></h3>
                    <div class="chart-container">
                        <canvas id="overallRoomStockChart"></canvas>
                    </div>
                </div>

                <div class="dss-card">
                    <h3><i class="material-icons">stacked_bar_chart</i> <?= te('Ketersediaan Kamar per Tipe') ?></h3>
                    <div class="chart-container">
                        <canvas id="roomAvailabilityChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="dss-section">
            <h2><i class="material-icons">business</i> <?= te('Hotel dengan Performa Terbaik') ?></h2>
            <table class="performance-table">
                <thead>
                    <tr>
                        <th><?= te('Nama Hotel') ?></th>
                        <th><?= te('Total Booking') ?></th>
                        <th><?= te('Pendapatan') ?></th>
                        <th><?= te('Rata-rata Menginap') ?> (<?= te('Hari') ?>)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($hotel_performance && $hotel_performance->num_rows > 0): ?>
                        <?php while ($hotel = $hotel_performance->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($hotel['nama_hotel']) ?></td>
                                <td><?= number_format($hotel['total_bookings']) ?></td>
                                <td><?= formatRupiah($hotel['revenue'] ?? 0) ?></td>
                                <td><?= round($hotel['avg_los'] ?? 0, 1) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4"><?= te('Belum ada data performa hotel') ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- BOOKING LIST SECTION (Menggantikan Recent Bookings) -->
        <div class="booking-list-section">
            <div class="booking-list-header">
                <h2><i class="material-icons">list_alt</i> <?= te('Daftar Booking') ?></h2>
                <div class="header-actions">
                </div>
            </div>

            <!-- Enhanced Search and Filter Section -->
            <div class="search-filter-section">
                <div class="form-group">
                    <input type="text" id="searchHotel" placeholder="<?= te('Cari hotel atau pelanggan...') ?>"
                        onkeyup="applyFilters()" onfocus="this.placeholder=''"
                        onblur="this.placeholder='<?= te('Cari hotel atau pelanggan...') ?>'">
                </div>
                <div class="form-group">
                    <select id="filterStatus" onchange="applyFilters()">
                        <option value=""><?= te('Semua Status') ?></option>
                        <option value="Pending"><?= te('Menunggu') ?></option>
                        <option value="Completed"><?= te('Selesai') ?></option>
                        <option value="Cancelled"><?= te('Dibatalkan') ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <select id="filterMonth" onchange="applyFilters()">
                        <option value=""><?= te('Semua Bulan') ?></option>
                        <option value="01">Januari</option>
                        <option value="02">Februari</option>
                        <option value="03">Maret</option>
                        <option value="04">April</option>
                        <option value="05">Mei</option>
                        <option value="06">Juni</option>
                        <option value="07">Juli</option>
                        <option value="08">Agustus</option>
                        <option value="09">September</option>
                        <option value="10">Oktober</option>
                        <option value="11">November</option>
                        <option value="12">Desember</option>
                    </select>
                </div>
                <div class="form-group">
                    <select id="filterYear" onchange="applyFilters()">
                        <option value=""><?= te('Semua Tahun') ?></option>
                        <?php
                        $currentYear = date('Y');
                        for ($year = $currentYear; $year >= $currentYear - 5; $year--) {
                            echo "<option value='$year'>$year</option>";
                        }
                        ?>
                    </select>
                </div>
                <button class="clear-all-btn" onclick="clearAllFilters()" type="button">
                    <i class="material-icons">clear_all</i>
                    <?= te('Reset Filter') ?>
                </button>
            </div>

            <!-- Filter Indicators -->
            <div class="filter-indicators" id="filterIndicators">
                <div class="results-counter" id="resultsCounter"></div>
                <!-- Filter indicators will appear here dynamically -->
            </div>

            <div class="bookings-grid" id="recentBookingsGrid">
                <?php if ($allBookings && $allBookings->num_rows > 0): ?>
                    <?php while ($booking = $allBookings->fetch_assoc()):
                        $statusClass = strtolower($booking['status']);
                        $statusText = $booking['status'];
                        $nights = date_diff(date_create($booking['check_in']), date_create($booking['check_out']))->format('%a');
                        $bookingMonth = date('m', strtotime($booking['tanggal_booking']));
                        $bookingYear = date('Y', strtotime($booking['tanggal_booking']));
                        $bookingDate = date('M Y', strtotime($booking['tanggal_booking']));
                        // Konversi bulan ke bahasa Indonesia
                        $bookingDate = formatBulanIndonesia($bookingDate);
                    ?>
                        <div class="booking-card <?= $statusClass ?>"
                            data-hotel="<?= htmlspecialchars($booking['nama_hotel']) ?>"
                            data-customer="<?= htmlspecialchars($booking['customer_name']) ?>"
                            data-status="<?= $statusText ?>"
                            data-month="<?= $bookingMonth ?>"
                            data-year="<?= $bookingYear ?>"
                            data-date="<?= date('Y-m', strtotime($booking['tanggal_booking'])) ?>">

                            <div class="booking-card-header">
                                <div class="booking-meta">
                                    <span class="booking-id">#<?= $booking['booking_id'] ?></span>
                                    <span class="booking-nights"><?= $nights ?> <?= te('malam') ?></span>
                                    <span class="booking-date"><?= $bookingDate ?></span>
                                </div>
                                <span class="booking-status status-<?= $statusClass ?>">
                                    <i class="material-icons">
                                        <?php
                                        switch ($statusClass) {
                                            case 'completed':
                                                echo 'check_circle';
                                                break;
                                            case 'pending':
                                                echo 'schedule';
                                                break;
                                            case 'cancelled':
                                                echo 'cancel';
                                                break;
                                            default:
                                                echo 'info';
                                        }
                                        ?>
                                    </i>
                                    <?= $statusText ?>
                                </span>
                            </div>

                            <div class="booking-customer">
                                <i class="material-icons">person</i>
                                <?= htmlspecialchars($booking['customer_name']) ?>
                            </div>

                            <div class="booking-hotel">
                                <i class="material-icons">business</i>
                                <?= htmlspecialchars($booking['nama_hotel']) ?>
                            </div>

                            <div class="booking-room">
                                <i class="material-icons">meeting_room</i>
                                <?= htmlspecialchars($booking['nama_tipe']) ?>
                            </div>

                            <div class="booking-dates">
                                <div class="date-item">
                                    <div class="date-icon">
                                        <i class="material-icons">login</i>
                                    </div>
                                    <div class="date-info">
                                        <span class="date-label">Check-in</span>
                                        <span class="date-value"><?= date('d M Y', strtotime($booking['check_in'])) ?></span>
                                    </div>
                                </div>
                                <div class="date-item">
                                    <div class="date-icon">
                                        <i class="material-icons">logout</i>
                                    </div>
                                    <div class="date-info">
                                        <span class="date-label">Check-out</span>
                                        <span class="date-value"><?= date('d M Y', strtotime($booking['check_out'])) ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="booking-details">
                                <div class="detail-item">
                                    <span class="detail-label"><?= te('Kamar') ?></span>
                                    <span class="detail-value"><?= $booking['jumlah_kamar'] ?></span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label"><?= te('Tanggal Booking') ?></span>
                                    <span class="detail-value"><?= date('d M Y', strtotime($booking['tanggal_booking'])) ?></span>
                                </div>
                            </div>

                            <div class="booking-footer">
                                <div class="booking-amount">
                                    <span class="amount-label"><?= te('Total') ?></span>
                                    <span class="amount-value"><?= formatRupiah($booking['total_harga']) ?></span>
                                </div>
                                <div class="booking-actions">
                                    <button class="action-btn view-detail-btn" title="<?= te('Lihat Detail') ?>" data-booking-id="<?= $booking['booking_id'] ?>">
                                        <i class="material-icons">visibility</i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-bookings-message">
                        <i class="material-icons" style="font-size: 64px; color: #ccc; margin-bottom: 15px;">inbox</i>
                        <h3><?= te('Tidak Ada Booking') ?></h3>
                        <p><?= te('Belum ada booking dalam sistem.') ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Modal View Detail -->
            <div id="viewDetailModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2><?= te('Detail Booking') ?></h2>
                        <span class="close-modal">&times;</span>
                    </div>
                    <div class="modal-body" id="viewDetailContent">
                        <!-- Content will be loaded via AJAX -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary close-modal"><?= te('Tutup') ?></button>
                    </div>
                </div>
            </div>

            <!-- Modal Edit Booking -->
            <div id="editBookingModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2><?= te('Edit Booking') ?></h2>
                        <span class="close-modal">&times;</span>
                    </div>
                    <form id="editBookingForm">
                        <div class="modal-body" id="editBookingContent">
                            <!-- Content will be loaded via AJAX -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary close-modal"><?= te('Batal') ?></button>
                            <button type="submit" class="btn btn-primary"><?= te('Simpan Perubahan') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Enhanced Filter Functionality for Booking List
        function applyFilters() {
            const searchVal = document.getElementById('searchHotel').value.toLowerCase();
            const statusVal = document.getElementById('filterStatus').value;
            const monthVal = document.getElementById('filterMonth').value;
            const yearVal = document.getElementById('filterYear').value;
            const bookings = document.querySelectorAll('#recentBookingsGrid .booking-card');
            let visibleCount = 0;

            bookings.forEach(card => {
                const hotelName = card.getAttribute('data-hotel').toLowerCase();
                const customerName = card.getAttribute('data-customer').toLowerCase();
                const bookingStatus = card.getAttribute('data-status');
                const bookingMonth = card.getAttribute('data-month');
                const bookingYear = card.getAttribute('data-year');

                // Search Filter: Hotel or Customer Name
                const isMatchSearch = (searchVal === '' ||
                    hotelName.includes(searchVal) ||
                    customerName.includes(searchVal));

                // Status Filter - Case insensitive
                const isMatchStatus = (statusVal === '' ||
                    bookingStatus.toLowerCase() === statusVal.toLowerCase());

                // Month Filter
                const isMatchMonth = (monthVal === '' || bookingMonth === monthVal);

                // Year Filter
                const isMatchYear = (yearVal === '' || bookingYear === yearVal);

                if (isMatchSearch && isMatchStatus && isMatchMonth && isMatchYear) {
                    card.style.display = 'block';
                    visibleCount++;

                    // Add animation
                    card.style.animation = 'fadeIn 0.5s ease';
                } else {
                    card.style.display = 'none';
                }
            });

            // Update results counter
            updateResultsCounter(visibleCount, bookings.length);

            // Show/hide no results message
            showNoResultsMessage(visibleCount, searchVal, statusVal, monthVal, yearVal);

            // Show filter active state
            updateFilterIndicators(searchVal, statusVal, monthVal, yearVal);
        }

        // Function to show active filter indicators
        function updateFilterIndicators(searchVal, statusVal, monthVal, yearVal) {
            const indicatorsContainer = document.getElementById('filterIndicators');

            // Remove existing indicators (keep results counter)
            const existingIndicators = indicatorsContainer.querySelectorAll('.filter-indicator');
            existingIndicators.forEach(indicator => indicator.remove());

            // Add search filter indicator
            if (searchVal) {
                const indicator = document.createElement('span');
                indicator.className = 'filter-indicator';
                indicator.innerHTML = `Search: "${searchVal}" <span class="clear-filter" onclick="clearSearch()">×</span>`;
                indicatorsContainer.appendChild(indicator);
            }

            // Add status filter indicator
            if (statusVal) {
                const indicator = document.createElement('span');
                indicator.className = 'filter-indicator';
                indicator.innerHTML = `Status: ${statusVal} <span class="clear-filter" onclick="clearStatus()">×</span>`;
                indicatorsContainer.appendChild(indicator);
            }

            // Add month filter indicator
            if (monthVal) {
                const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
                ];
                const indicator = document.createElement('span');
                indicator.className = 'filter-indicator';
                indicator.innerHTML = `Bulan: ${monthNames[parseInt(monthVal) - 1]} <span class="clear-filter" onclick="clearMonth()">×</span>`;
                indicatorsContainer.appendChild(indicator);
            }

            // Add year filter indicator
            if (yearVal) {
                const indicator = document.createElement('span');
                indicator.className = 'filter-indicator';
                indicator.innerHTML = `Tahun: ${yearVal} <span class="clear-filter" onclick="clearYear()">×</span>`;
                indicatorsContainer.appendChild(indicator);
            }
        }

        // Helper functions to clear individual filters
        function clearSearch() {
            document.getElementById('searchHotel').value = '';
            applyFilters();
        }

        function clearStatus() {
            document.getElementById('filterStatus').value = '';
            applyFilters();
        }

        function clearMonth() {
            document.getElementById('filterMonth').value = '';
            applyFilters();
        }

        function clearYear() {
            document.getElementById('filterYear').value = '';
            applyFilters();
        }

        // Clear all filters
        function clearAllFilters() {
            document.getElementById('searchHotel').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterMonth').value = '';
            document.getElementById('filterYear').value = '';
            applyFilters();
        }

        // Show no results message
        function showNoResultsMessage(visibleCount, searchVal, statusVal, monthVal, yearVal) {
            const noBookingsMessage = document.querySelector('.no-bookings-message');
            if (!noBookingsMessage) return;

            if (visibleCount > 0) {
                noBookingsMessage.style.display = 'none';
            } else {
                noBookingsMessage.style.display = 'block';

                // Customize message based on active filters
                const messageElement = noBookingsMessage.querySelector('p');
                if (!messageElement) return;

                if (searchVal || statusVal || monthVal || yearVal) {
                    messageElement.textContent = 'No bookings found with the current filters. Try adjusting your search criteria.';
                } else {
                    messageElement.textContent = 'No bookings available in the system.';
                }
            }
        }

        // Update results counter
        function updateResultsCounter(visible, total) {
            const counter = document.getElementById('resultsCounter');
            if (counter) {
                counter.textContent = `Showing ${visible} of ${total} bookings`;

                // Add color coding based on results
                if (visible === 0) {
                    counter.style.background = 'rgba(227, 73, 72, 0.1)';
                    counter.style.color = '#f44336';
                } else if (visible === total) {
                    counter.style.background = 'rgba(76, 175, 80, 0.1)';
                    counter.style.color = '#4caf50';
                } else {
                    counter.style.background = 'rgba(255, 152, 0, 0.1)';
                    counter.style.color = '#ff9800';
                }
            }
        }

        // Refresh booking list
        function refreshBookingList() {
            // Show loading state
            const refreshBtn = document.querySelector('.view-all-btn');
            const originalText = refreshBtn.innerHTML;
            refreshBtn.innerHTML = '<i class="material-icons">refresh</i> Refreshing...';
            refreshBtn.disabled = true;

            // Simulate refresh (in real implementation, this would reload data from server)
            setTimeout(() => {
                applyFilters();
                refreshBtn.innerHTML = originalText;
                refreshBtn.disabled = false;

                // Show notification
                showNotification('Booking list refreshed successfully!', 'success');
            }, 1000);
        }

        // Initialize filters on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Set current month and year as default
            const now = new Date();
            const currentMonth = (now.getMonth() + 1).toString().padStart(2, '0');
            const currentYear = now.getFullYear().toString();

            document.getElementById('filterMonth').value = currentMonth;
            document.getElementById('filterYear').value = currentYear;

            // Apply filters after a short delay to ensure DOM is ready
            setTimeout(() => applyFilters(), 500);

            // Add real-time search with debouncing
            let searchTimeout;
            const searchInput = document.getElementById('searchHotel');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => applyFilters(), 300);
                });
            }
        });

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
        });

        // Modal functionality dengan koneksi database real
        document.addEventListener('DOMContentLoaded', function() {
            // Get modal elements
            const viewDetailModal = document.getElementById('viewDetailModal');
            const editBookingModal = document.getElementById('editBookingModal');
            const viewDetailContent = document.getElementById('viewDetailContent');
            const editBookingContent = document.getElementById('editBookingContent');
            const editBookingForm = document.getElementById('editBookingForm');

            // Close modal function
            function closeModals() {
                viewDetailModal.style.display = 'none';
                editBookingModal.style.display = 'none';
            }

            // Close modal when clicking on X or close button
            document.querySelectorAll('.close-modal').forEach(closeBtn => {
                closeBtn.addEventListener('click', closeModals);
            });

            // Close modal when clicking outside modal content
            window.addEventListener('click', function(event) {
                if (event.target === viewDetailModal) {
                    closeModals();
                }
                if (event.target === editBookingModal) {
                    closeModals();
                }
            });

            // View Detail functionality
            document.querySelectorAll('.view-detail-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const bookingId = this.getAttribute('data-booking-id');
                    loadBookingDetails(bookingId);
                });
            });

            // Edit Booking functionality
            document.querySelectorAll('.edit-booking-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const bookingId = this.getAttribute('data-booking-id');
                    loadEditBookingForm(bookingId);
                });
            });

            // Form submission for edit booking
            editBookingForm.addEventListener('submit', function(e) {
                e.preventDefault();
                saveBookingChanges();
            });

            // Load booking details via AJAX
            function loadBookingDetails(bookingId) {
                viewDetailContent.innerHTML = `
                    <div class="loading">
                        <i class="material-icons">hourglass_empty</i>
                        <p><?= te('Memuat detail booking...') ?></p>
                    </div>
                `;

                viewDetailModal.style.display = 'block';

                // AJAX call to get booking details
                fetch(`?action=get_booking_details&booking_id=${encodeURIComponent(bookingId)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const booking = data.data;
                            viewDetailContent.innerHTML = `
                                <div class="detail-section">
                                    <h3><i class="material-icons">person</i> <?= te('Informasi Pelanggan') ?></h3>
                                    <div class="detail-grid">
                                        <div class="detail-item">
                                            <span class="detail-label"><?= te('Nama Pelanggan') ?></span>
                                            <span class="detail-value">${escapeHtml(booking.customer_name)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Email</span>
                                            <span class="detail-value">${escapeHtml(booking.email || 'N/A')}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label"><?= te('Telepon') ?></span>
                                            <span class="detail-value">${escapeHtml(booking.phone || 'N/A')}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="detail-section">
                                    <h3><i class="material-icons">business</i> <?= te('Detail Booking') ?></h3>
                                    <div class="detail-grid">
                                        <div class="detail-item">
                                            <span class="detail-label">Hotel</span>
                                            <span class="detail-value">${escapeHtml(booking.nama_hotel)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label"><?= te('Tipe Kamar') ?></span>
                                            <span class="detail-value">${escapeHtml(booking.room_type)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Check-in</span>
                                            <span class="detail-value">${formatDate(booking.check_in)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Check-out</span>
                                            <span class="detail-value">${formatDate(booking.check_out)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label"><?= te('Jumlah Kamar') ?></span>
                                            <span class="detail-value">${booking.guests}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label"><?= te('Harga Total') ?></span>
                                            <span class="detail-value">${formatCurrency(booking.total_harga)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label"><?= te('Metode Pembayaran') ?></span>
                                            <span class="detail-value">${escapeHtml(booking.metode_pembayaran || 'N/A')}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label"><?= te('ID Booking') ?></span>
                                            <span class="detail-value">${escapeHtml(booking.booking_id)}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="detail-section">
                                    <h3><i class="material-icons">info</i> <?= te('Status Booking') ?></h3>
                                    <div class="detail-grid">
                                        <div class="detail-item">
                                            <span class="detail-label">Status</span>
                                            <span class="detail-value status-${booking.status.toLowerCase()}">${booking.status}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label"><?= te('Tanggal Booking') ?></span>
                                            <span class="detail-value">${formatDateTime(booking.booking_date)}</span>
                                        </div>
                                    </div>
                                </div>

                                ${booking.special_requests ? `
                                <div class="detail-section">
                                    <h3><i class="material-icons">notes</i> <?= te('Permintaan Khusus') ?></h3>
                                    <div class="detail-item">
                                        <span class="detail-value">${escapeHtml(booking.special_requests)}</span>
                                    </div>
                                </div>
                                ` : ''}
                            `;
                        } else {
                            viewDetailContent.innerHTML = `
                                <div class="error-message">
                                    <i class="material-icons">error</i>
                                    <p>${data.message || 'Failed to load booking details'}</p>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        viewDetailContent.innerHTML = `
                            <div class="error-message">
                                <i class="material-icons">error</i>
                                <p>Failed to load booking details. Please try again.</p>
                            </div>
                        `;
                    });
            }

            // Load edit booking form via AJAX
            function loadEditBookingForm(bookingId) {
                editBookingContent.innerHTML = `
                    <div class="loading">
                        <i class="material-icons">hourglass_empty</i>
                        <p>Loading booking form...</p>
                    </div>
                `;

                editBookingModal.style.display = 'block';

                // AJAX call to get booking details for editing
                fetch(`?action=get_booking_details&booking_id=${encodeURIComponent(bookingId)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const booking = data.data;
                            editBookingContent.innerHTML = `
                                <input type="hidden" name="booking_id" value="${escapeHtml(booking.booking_id)}">
                                
                                <div class="form-group">
                                    <label for="customer_name"><?= te('Nama Pelanggan') ?></label>
                                    <input type="text" class="form-control" id="customer_name" name="customer_name" value="${escapeHtml(booking.customer_name)}" required>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="check_in"><?= te('Tanggal Check-in') ?></label>
                                        <input type="date" class="form-control" id="check_in" name="check_in" value="${booking.check_in}" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="check_out"><?= te('Tanggal Check-out') ?></label>
                                        <input type="date" class="form-control" id="check_out" name="check_out" value="${booking.check_out}" required>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="jumlah_kamar"><?= te('Jumlah Kamar') ?></label>
                                        <select class="form-control" id="jumlah_kamar" name="jumlah_kamar" required>
                                            ${[1,2,3,4,5,6].map(num =>
                                                `<option value="${num}" ${num == booking.guests ? 'selected' : ''}>${num} Room${num > 1 ? 's' : ''}</option>`
                                            ).join('')}
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="status"><?= te('Status Booking') ?></label>
                                        <select class="form-control" id="status" name="status" required>
                                            <option value="Pending" ${booking.status === 'Pending' ? 'selected' : ''}><?= te('Menunggu') ?></option>
                                            <option value="Completed" ${booking.status === 'Completed' ? 'selected' : ''}><?= te('Selesai') ?></option>
                                            <option value="Cancelled" ${booking.status === 'Cancelled' ? 'selected' : ''}><?= te('Dibatalkan') ?></option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="total_harga"><?= te('Harga Total (IDR)') ?></label>
                                    <input type="number" class="form-control" id="total_harga" name="total_harga" value="${parseInt(booking.total_harga)}" required>
                                </div>

                                <div class="form-group">
                                    <label for="hotel_name"><?= te('Hotel (Hanya Baca)') ?></label>
                                    <input type="text" class="form-control" id="hotel_name" value="${escapeHtml(booking.nama_hotel)}" readonly>
                                </div>

                                <div class="form-group">
                                    <label for="room_type"><?= te('Tipe Kamar (Hanya Baca)') ?></label>
                                    <input type="text" class="form-control" id="room_type" value="${escapeHtml(booking.room_type)}" readonly>
                                </div>

                                <div class="form-group">
                                    <label for="catatan"><?= te('Permintaan Khusus / Catatan') ?></label>
                                    <textarea class="form-control" id="catatan" name="catatan" rows="3" placeholder="<?= te('Ada permintaan khusus atau catatan...') ?>">${escapeHtml(booking.special_requests || '')}</textarea>
                                </div>
                            `;
                        } else {
                            editBookingContent.innerHTML = `
                                <div class="error-message">
                                    <i class="material-icons">error</i>
                                    <p>${data.message || 'Failed to load booking form'}</p>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        editBookingContent.innerHTML = `
                            <div class="error-message">
                                <i class="material-icons">error</i>
                                <p>Failed to load booking form. Please try again.</p>
                            </div>
                        `;
                    });
            }

            // Save booking changes
            function saveBookingChanges() {
                const formData = new FormData(editBookingForm);

                // Show loading state
                const submitBtn = editBookingForm.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="material-icons">hourglass_empty</i> Saving...';
                submitBtn.disabled = true;

                // AJAX call to update booking
                fetch('?action=update_booking', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('Booking updated successfully!', 'success');
                            closeModals();
                            // Reload the page to reflect changes
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showNotification(data.message || 'Failed to update booking', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('Failed to update booking. Please try again.', 'error');
                    })
                    .finally(() => {
                        // Reset button
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    });
            }

            // Utility functions
            function escapeHtml(unsafe) {
                if (!unsafe) return '';
                return unsafe
                    .toString()
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }

            function formatDate(dateString) {
                const options = {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                };
                return new Date(dateString).toLocaleDateString('en-US', options);
            }

            function formatDateTime(dateTimeString) {
                const options = {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                };
                return new Date(dateTimeString).toLocaleDateString('en-US', options);
            }

            function formatCurrency(amount) {
                return 'Rp ' + parseInt(amount).toLocaleString('id-ID');
            }

            function showNotification(message, type = 'info') {
                // Create notification element
                const notification = document.createElement('div');
                notification.className = `notification notification-${type}`;
                notification.innerHTML = `
                    <i class="material-icons">${type === 'success' ? 'check_circle' : type === 'error' ? 'error' : 'info'}</i>
                    <span>${message}</span>
                `;

                document.body.appendChild(notification);

                // Remove after 3 seconds
                setTimeout(() => {
                    notification.style.animation = 'slideOutRight 0.3s ease-in';
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.parentNode.removeChild(notification);
                        }
                    }, 300);
                }, 3000);
            }
        });

        // Charts Initialization
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof Chart !== 'undefined') {
                Chart.defaults.font.family = "'Heebo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
                Chart.defaults.color = '#6b7280';
                Chart.defaults.plugins.legend.labels.usePointStyle = true;
                Chart.defaults.plugins.legend.labels.boxWidth = 8;
                Chart.defaults.plugins.legend.labels.boxHeight = 8;
            }

            // Data dari PHP
            const monthlyLabels = <?= json_encode($months) ?>;
            const monthlyBookingsData = <?= json_encode($monthly_bookings) ?>;
            const monthlyRevenueData = <?= json_encode($monthly_revenue) ?>;
            const roomTypeLabels = <?= json_encode($room_type_labels) ?>;
            const roomTypeBookedData = <?= json_encode($room_type_booked) ?>;
            const roomTypeAvailableData = <?= json_encode($room_type_available) ?>;
            const totalRooms = <?= $total_rooms ?>;
            const bookedRooms = <?= $booked_rooms ?>;
            const availableRooms = <?= $available_rooms ?>;

            // 1. Booking Trend Chart
            const bookingCtx = document.getElementById('bookingTrendChart').getContext('2d');
            new Chart(bookingCtx, {
                type: 'line',
                data: {
                    labels: monthlyLabels,
                    datasets: [{
                        label: 'Bookings',
                        data: monthlyBookingsData,
                        borderColor: '#2a78d6',
                        backgroundColor: 'rgba(42, 120, 214, 0.12)',
                        pointBackgroundColor: '#2a78d6',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 1.5,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0.3,
                        fill: true,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Bookings'
                            }
                        }
                    }
                }
            });

            // 2. Revenue Chart
            const revenueCtx = document.getElementById('revenueChart').getContext('2d');
            new Chart(revenueCtx, {
                type: 'bar',
                data: {
                    labels: monthlyLabels,
                    datasets: [{
                        label: 'Revenue (Rupiah)',
                        data: monthlyRevenueData,
                        backgroundColor: '#1baf7a',
                        borderColor: '#1baf7a',
                        borderRadius: 6,
                        maxBarThickness: 42,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Revenue (Rupiah)'
                            }
                        }
                    }
                }
            });

            // 3. Room Availability Chart
            const roomAvailabilityCtx = document.getElementById('roomAvailabilityChart').getContext('2d');
            new Chart(roomAvailabilityCtx, {
                type: 'bar',
                data: {
                    labels: roomTypeLabels,
                    datasets: [{
                        label: 'Booked Rooms',
                        data: roomTypeBookedData,
                        backgroundColor: '#eb6834',
                        borderRadius: 4,
                        stack: 'Stack 0'
                    }, {
                        label: 'Available Rooms',
                        data: roomTypeAvailableData,
                        backgroundColor: '#2a78d6',
                        borderRadius: 4,
                        stack: 'Stack 0'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                            title: {
                                display: true,
                                text: 'Room Type'
                            }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Total Rooms'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        }
                    }
                }
            });

            // 4. Overall Room Stock Donut Chart
            const overallRoomStockCtx = document.getElementById('overallRoomStockChart').getContext('2d');
            new Chart(overallRoomStockCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Available Rooms', 'Booked Rooms'],
                    datasets: [{
                        data: [availableRooms, bookedRooms],
                        backgroundColor: ['#2a78d6', '#eb6834'],
                        hoverOffset: 4,
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed !== null) {
                                        label += context.parsed.toFixed(0) + ' Rooms';
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        });

        // Sidebar functionality
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

        // Dropdown menus for sidebar
        document.querySelectorAll('.booking-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const parentMenu = toggle.closest('.user-menu');
                const dropdownId = toggle.getAttribute('data-target');
                const dropdown = document.getElementById(dropdownId);
                const isExpanded = parentMenu.getAttribute('aria-expanded') === 'true';

                document.querySelectorAll('.user-menu').forEach(menu => {
                    if (menu !== parentMenu) {
                        menu.setAttribute('aria-expanded', 'false');
                    }
                });
                document.querySelectorAll('.booking-submenu').forEach(sub => {
                    if (sub !== dropdown) {
                        sub.classList.remove('show');
                        sub.classList.add('hidden');
                        sub.setAttribute('aria-hidden', 'true');
                    }
                });

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
    </script>
</body>

</html>