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

// Function untuk mendapatkan data customer
function getCustomerDetails($customerId)
{
    global $conn;

    $sql = "SELECT 
                c.customer_id,
                c.nama,
                c.email,
                c.no_hp,
                c.gender,
                c.address,
                c.created_at,
                u.username,
                u.first_name,
                u.last_name,
                u.profile_picture,
                COUNT(b.booking_id) as total_bookings,
                SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) as total_spent,
                SUM(CASE WHEN b.status = 'Cancelled' THEN b.total_harga ELSE 0 END) as cancelled_value
            FROM customer c
            LEFT JOIN user u ON c.id_user = u.id_user
            LEFT JOIN booking_hotel b ON c.customer_id = b.customer_id
            WHERE c.customer_id = ?
            GROUP BY c.customer_id";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $customerId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

// Function untuk update customer
function updateCustomer($customerData)
{
    global $conn;

    $sql = "UPDATE customer SET 
                nama = ?,
                email = ?,
                no_hp = ?,
                gender = ?,
                address = ?
            WHERE customer_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "ssssss",
        $customerData['nama'],
        $customerData['email'],
        $customerData['no_hp'],
        $customerData['gender'],
        $customerData['address'],
        $customerData['customer_id']
    );

    return $stmt->execute();
}

// Function untuk delete customer
function deleteCustomer($customerId)
{
    global $conn;

    // Cek apakah customer memiliki booking
    $checkSql = "SELECT COUNT(*) as booking_count FROM booking_hotel WHERE customer_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("s", $customerId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $row = $result->fetch_assoc();

    if ($row['booking_count'] > 0) {
        return array('success' => false, 'message' => 'Cannot delete customer with existing bookings');
    }

    // Delete customer
    $sql = "DELETE FROM customer WHERE customer_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $customerId);

    if ($stmt->execute()) {
        return array('success' => true, 'message' => 'Customer deleted successfully');
    } else {
        return array('success' => false, 'message' => 'Failed to delete customer');
    }
}

// Function untuk format Rupiah
function formatRupiah($angka)
{
    if (empty($angka)) return 'Rp 0';
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Function untuk format tanggal
function formatDate($dateString)
{
    if (empty($dateString)) return '-';
    return date('d M Y', strtotime($dateString));
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
        case 'get_customer_details':
            if (isset($_GET['customer_id'])) {
                $customerDetails = getCustomerDetails($_GET['customer_id']);
                if ($customerDetails) {
                    echo json_encode([
                        'success' => true,
                        'data' => $customerDetails
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Customer not found'
                    ]);
                }
            }
            break;

        case 'update_customer':
            if ($_POST) {
                $result = updateCustomer([
                    'customer_id' => $_POST['customer_id'],
                    'nama' => $_POST['nama'],
                    'email' => $_POST['email'],
                    'no_hp' => $_POST['no_hp'],
                    'gender' => $_POST['gender'],
                    'address' => $_POST['address']
                ]);

                echo json_encode([
                    'success' => $result,
                    'message' => $result ? 'Customer updated successfully' : 'Failed to update customer'
                ]);
            }
            break;

        case 'delete_customer':
            if (isset($_POST['customer_id'])) {
                $result = deleteCustomer($_POST['customer_id']);
                echo json_encode($result);
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

// --- CUSTOMER STATISTICS ---
$customer_stats = [
    'total_customers' => 0,
    'active_customers' => 0,
    'new_customers_today' => 0,
    'new_customers_week' => 0,
    'avg_booking_per_customer' => 0,
    'avg_spent_per_customer' => 0,
    'top_spending_customers' => []
];

// Dapatkan total customers
$query = "SELECT COUNT(*) as total FROM customer";
$result = $conn->query($query);
if ($result && $row = $result->fetch_assoc()) {
    $customer_stats['total_customers'] = $row['total'];
}

// Dapatkan active customers (customers dengan booking completed)
$query = "SELECT COUNT(DISTINCT c.customer_id) as active 
          FROM customer c 
          JOIN booking_hotel b ON c.customer_id = b.customer_id 
          WHERE b.status = 'Completed'";
$result = $conn->query($query);
if ($result && $row = $result->fetch_assoc()) {
    $customer_stats['active_customers'] = $row['active'];
} else {
    $customer_stats['active_customers'] = 0;
}

// Dapatkan new customers today
$query = "SELECT COUNT(*) as new_today 
          FROM customer 
          WHERE DATE(created_at) = CURDATE()";
$result = $conn->query($query);
if ($result && $row = $result->fetch_assoc()) {
    $customer_stats['new_customers_today'] = $row['new_today'];
} else {
    $customer_stats['new_customers_today'] = 0;
}

// Dapatkan new customers this week
$query = "SELECT COUNT(*) as new_week 
          FROM customer 
          WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
$result = $conn->query($query);
if ($result && $row = $result->fetch_assoc()) {
    $customer_stats['new_customers_week'] = $row['new_week'];
} else {
    $customer_stats['new_customers_week'] = 0;
}

// Dapatkan average bookings per customer
$query = "SELECT 
            COUNT(b.booking_id) as total_bookings,
            COUNT(DISTINCT c.customer_id) as total_customers,
            AVG(bookings_per_customer) as avg_bookings
          FROM (
            SELECT 
                c.customer_id,
                COUNT(b.booking_id) as bookings_per_customer
            FROM customer c
            LEFT JOIN booking_hotel b ON c.customer_id = b.customer_id
            GROUP BY c.customer_id
          ) as customer_stats";
$result = $conn->query($query);
if ($result && $row = $result->fetch_assoc()) {
    $customer_stats['avg_booking_per_customer'] = round($row['avg_bookings'] ?? 0, 1);
} else {
    $customer_stats['avg_booking_per_customer'] = 0;
}

// Dapatkan average spent per customer
$query = "SELECT 
            AVG(total_spent) as avg_spent
          FROM (
            SELECT 
                c.customer_id,
                SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) as total_spent
            FROM customer c
            LEFT JOIN booking_hotel b ON c.customer_id = b.customer_id
            GROUP BY c.customer_id
          ) as customer_spending";
$result = $conn->query($query);
if ($result && $row = $result->fetch_assoc()) {
    $customer_stats['avg_spent_per_customer'] = round($row['avg_spent'] ?? 0, 2);
} else {
    $customer_stats['avg_spent_per_customer'] = 0;
}
// Dapatkan top spending customers
$query = "SELECT 
            c.customer_id,
            c.nama,
            c.email,
            c.gender,
            COUNT(b.booking_id) as total_bookings,
            SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) as total_spent,
            MAX(b.tanggal_booking) as last_booking
          FROM customer c
          LEFT JOIN booking_hotel b ON c.customer_id = b.customer_id
          GROUP BY c.customer_id, c.nama, c.email, c.gender
          HAVING total_spent > 0
          ORDER BY total_spent DESC
          LIMIT 5";
$result = $conn->query($query);
$top_customers = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $top_customers[] = $row;
    }
}
$customer_stats['top_spending_customers'] = $top_customers;

// Dapatkan customer growth data untuk chart
$growth_query = "SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    COUNT(*) as new_customers,
    SUM(COUNT(*)) OVER (ORDER BY DATE_FORMAT(created_at, '%Y-%m')) as cumulative_customers
    FROM customer 
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month";

$growth_data = $conn->query($growth_query);
$growth_months = [];
$new_customers = [];
$cumulative_customers = [];

if ($growth_data && $growth_data->num_rows > 0) {
    while ($row = $growth_data->fetch_assoc()) {
        $monthName = date('F', strtotime($row['month'] . '-01'));
        $indonesianMonth = formatBulanIndonesia($monthName);
        $year = date('Y', strtotime($row['month'] . '-01'));
        $growth_months[] = $indonesianMonth . ' ' . $year;
        $new_customers[] = $row['new_customers'];
        $cumulative_customers[] = $row['cumulative_customers'];
    }
} else {
    // Default data jika tidak ada hasil
    $currentMonth = formatBulanIndonesia(date('F'));
    $currentYear = date('Y');
    $growth_months = [$currentMonth . ' ' . $currentYear];
    $new_customers = [0];
    $cumulative_customers = [0];
}

// Dapatkan customer demographics (berdasarkan gender)
$demographics_query = "SELECT 
    CASE 
        WHEN gender IS NULL OR gender = '' THEN 'Not Specified'
        ELSE gender 
    END as gender,
    COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM customer), 2) as percentage
    FROM customer
    GROUP BY 
        CASE 
            WHEN gender IS NULL OR gender = '' THEN 'Not Specified'
            ELSE gender 
        END";

$demographics_data = $conn->query($demographics_query);
$demographics_labels = [];
$demographics_counts = [];

if ($demographics_data && $demographics_data->num_rows > 0) {
    while ($row = $demographics_data->fetch_assoc()) {
        $demographics_labels[] = $row['gender'] . ' (' . $row['percentage'] . '%)';
        $demographics_counts[] = $row['count'];
    }
} else {
    // Default data jika tidak ada hasil
    $demographics_labels = ['Not Specified (100%)'];
    $demographics_counts = [1];
}

// Dapatkan semua customers untuk Customer List
$all_customers_query = "SELECT 
    c.customer_id,
    c.nama,
    c.email,
    c.no_hp,
    c.gender,
    c.address,
    c.created_at,
    u.username,
    COUNT(b.booking_id) as total_bookings,
    SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) as total_spent,
    MAX(b.tanggal_booking) as last_booking_date
    FROM customer c
    LEFT JOIN user u ON c.id_user = u.id_user
    LEFT JOIN booking_hotel b ON c.customer_id = b.customer_id
    GROUP BY c.customer_id, c.nama, c.email, c.no_hp, c.gender, c.address, c.created_at, u.username
    ORDER BY c.created_at DESC";

$allCustomers = $conn->query($all_customers_query);
if (!$allCustomers) {
    die("Error in customer query: " . $conn->error);
}

// Dapatkan system notifications
$query = "SELECT COUNT(*) as notifications 
          FROM booking_hotel 
          WHERE status = 'Pending'";
$result = $conn->query($query);
$notificationCount = 0;
if ($result && $row = $result->fetch_assoc()) {
    $notificationCount = $row['notifications'] ?? 0;
}

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

    header("Location: customerdss.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>TripVerse Admin - Customer Decision Support System</title>
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

        /* Customer List Section */
        .customer-list-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
            margin-bottom: 30px;
            transition: var(--transition);
            border: 1px solid #e0e0e0;
        }

        .customer-list-section:hover {
            box-shadow: var(--box-shadow-hover);
            transform: translateY(-2px);
        }

        .customer-list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }

        .customer-list-header h2 {
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
            background: linear-gradient(135deg, var(--primary-color), #FF7A3D);
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

        .customers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        /* Customer Card Styles */
        .customer-card {
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

        .customer-card::before {
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

        .customer-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        /* Customer Types */
        .customer-card.premium {
            border-left-color: #eda100;
        }

        .customer-card.premium .customer-type {
            background: rgba(255, 152, 0, 0.1);
            color: #eda100;
        }

        .customer-card.regular {
            border-left-color: #2a78d6;
        }

        .customer-card.regular .customer-type {
            background: rgba(33, 150, 243, 0.1);
            color: #2a78d6;
        }

        .customer-card.new {
            border-left-color: #1baf7a;
        }

        .customer-card.new .customer-type {
            background: rgba(76, 175, 80, 0.1);
            color: #1baf7a;
        }

        .customer-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .customer-meta {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .customer-id {
            font-size: 11px;
            color: var(--text-light);
            background: #f5f5f5;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .customer-type {
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 10px;
            display: inline-block;
            margin: 2px 0;
        }

        .customer-date {
            font-size: 10px;
            color: var(--text-light);
            background: rgba(108, 117, 125, 0.1);
            padding: 2px 6px;
            border-radius: 8px;
            font-weight: 500;
            margin-top: 2px;
        }

        .customer-status {
            font-size: 11px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 20px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 4px;
            letter-spacing: 0.5px;
            background: rgba(255, 122, 61, 0.1);
            color: var(--primary-color);
            border: 1px solid rgba(255, 122, 61, 0.2);
        }

        .customer-name {
            font-weight: 600;
            font-size: 18px;
            color: var(--dark-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .customer-name .material-icons {
            color: var(--primary-color);
            font-size: 20px;
        }

        .customer-contact {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin: 15px 0;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .contact-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 122, 61, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
        }

        .contact-info {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .contact-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .contact-value {
            font-size: 14px;
            font-weight: 500;
            color: var(--dark-color);
            word-break: break-all;
        }

        .customer-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
            padding: 15px;
            background: rgba(255, 122, 61, 0.03);
            border-radius: 8px;
            border: 1px solid rgba(255, 122, 61, 0.1);
        }

        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .stat-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            margin-bottom: 4px;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--dark-color);
        }

        .customer-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #f0f0f0;
            margin-top: 10px;
        }

        .customer-lifetime-value {
            display: flex;
            flex-direction: column;
        }

        .value-label {
            font-size: 11px;
            color: var(--text-light);
            text-transform: uppercase;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .value-amount {
            font-weight: 700;
            font-size: 16px;
            color: var(--dark-color);
            background: linear-gradient(135deg, var(--primary-color), #FF7A3D);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .customer-actions {
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
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 122, 61, 0.3);
        }

        .view-detail-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .edit-customer-btn:hover {
            background: var(--info-color);
            color: white;
            border-color: var(--info-color);
        }

        .delete-customer-btn:hover {
            background: var(--danger-color);
            color: white;
            border-color: var(--danger-color);
        }

        .no-customers-message {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            grid-column: 1 / -1;
        }

        .no-customers-message h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: var(--dark-color);
            font-weight: 600;
        }

        .no-customers-message p {
            margin: 0;
            font-size: 14px;
            color: var(--text-light);
        }

        /* Top Customers Table */
        .top-customers-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .top-customers-table th,
        .top-customers-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .top-customers-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--text-light);
        }

        .top-customers-table tr:hover {
            background: #f8f9fa;
        }

        .customer-rank {
            font-weight: bold;
            color: var(--primary-color);
            font-size: 16px;
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
            background: linear-gradient(135deg, var(--primary-color), #FF7A3D);
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

        /* Confirmation Modal Styles */
        .confirmation-modal .modal-content {
            max-width: 500px;
        }

        .confirmation-icon {
            text-align: center;
            margin-bottom: 20px;
        }

        .confirmation-icon .material-icons {
            font-size: 64px;
            color: var(--warning-color);
        }

        .confirmation-title {
            text-align: center;
            margin: 0 0 10px 0;
            font-size: 20px;
            color: var(--dark-color);
            font-weight: 600;
        }

        .confirmation-message {
            text-align: center;
            color: var(--text-light);
            font-size: 16px;
            line-height: 1.5;
            margin: 0 0 20px 0;
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

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #cf3c3b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(227, 73, 72, 0.3);
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

        /* Additional Styles */
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
            .customer-list-header {
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

            .customers-grid {
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

            .customer-stats {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .customer-footer {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }

            .customer-lifetime-value {
                text-align: center;
            }

            .customer-actions {
                justify-content: center;
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

            .customer-card {
                padding: 15px;
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

            .top-customers-table {
                font-size: 12px;
            }

            .top-customers-table th,
            .top-customers-table td {
                padding: 8px;
            }
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
            <a href="customerdss.php" class="active">
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
            <h2><i class="material-icons">dashboard</i> Customer Decision Support System</h2>

            <div class="kpi-grid">
                <div class="kpi-card primary">
                    <div class="kpi-icon">
                        <i class="material-icons">people</i>
                    </div>
                    <div class="kpi-label">Total Customers</div>
                    <div class="kpi-value"><?= number_format($customer_stats['total_customers']) ?></div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">trending_up</i> Active
                    </div>
                </div>
                <div class="kpi-card success">
                    <div class="kpi-icon">
                        <i class="material-icons">check_circle</i>
                    </div>
                    <div class="kpi-label">Active Customers</div>
                    <div class="kpi-value"><?= number_format($customer_stats['active_customers']) ?></div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">check_circle</i> <?= $customer_stats['total_customers'] > 0 ? round(($customer_stats['active_customers'] / $customer_stats['total_customers']) * 100, 1) : 0 ?>%
                    </div>
                </div>
                <div class="kpi-card warning">
                    <div class="kpi-icon">
                        <i class="material-icons">add_circle</i>
                    </div>
                    <div class="kpi-label">New This Week</div>
                    <div class="kpi-value"><?= number_format($customer_stats['new_customers_week']) ?></div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">trending_up</i> Growth
                    </div>
                </div>
                <div class="kpi-card info">
                    <div class="kpi-icon">
                        <i class="material-icons">shopping_cart</i>
                    </div>
                    <div class="kpi-label">Avg. Bookings/Customer</div>
                    <div class="kpi-value"><?= $customer_stats['avg_booking_per_customer'] ?></div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">trending_up</i> Engagement
                    </div>
                </div>
                <div class="kpi-card danger">
                    <div class="kpi-icon">
                        <i class="material-icons">attach_money</i>
                    </div>
                    <div class="kpi-label">Avg. Spent/Customer</div>
                    <div class="kpi-value"><?= formatRupiah($customer_stats['avg_spent_per_customer']) ?></div>
                    <div class="kpi-trend trend-up">
                        <i class="material-icons">trending_up</i> Value
                    </div>
                </div>
            </div>
        </div>

        <div class="dss-section">
            <h2><i class="material-icons">trending_up</i> Customer Growth & Demographics</h2>
            <div class="dss-grid">
                <div class="dss-card">
                    <h3><i class="material-icons">show_chart</i> Customer Growth</h3>
                    <div class="chart-container">
                        <canvas id="customerGrowthChart"></canvas>
                    </div>
                </div>

                <div class="dss-card">
                    <h3><i class="material-icons">pie_chart</i> Customer Demographics (Gender)</h3>
                    <div class="chart-container">
                        <canvas id="demographicsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="dss-section">
            <h2><i class="material-icons">stars</i> Top Spending Customers</h2>
            <table class="top-customers-table">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Customer Name</th>
                        <th>Email</th>
                        <th>Gender</th>
                        <th>Total Bookings</th>
                        <th>Total Spent</th>
                        <th>Last Booking</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($customer_stats['top_spending_customers'])): ?>
                        <?php foreach ($customer_stats['top_spending_customers'] as $index => $customer): ?>
                            <tr>
                                <td class="customer-rank">#<?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($customer['nama']) ?></td>
                                <td><?= htmlspecialchars($customer['email']) ?></td>
                                <td><?= htmlspecialchars($customer['gender'] ?? 'Not Specified') ?></td>
                                <td><?= number_format($customer['total_bookings']) ?></td>
                                <td><?= formatRupiah($customer['total_spent']) ?></td>
                                <td><?= formatDate($customer['last_booking']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">No customer spending data available</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- CUSTOMER LIST SECTION -->
        <div class="customer-list-section">
            <div class="customer-list-header">
                <h2><i class="material-icons">list_alt</i> Customer Management</h2>
                <div class="header-actions">
                </div>
            </div>

            <!-- Enhanced Search and Filter Section -->
            <div class="search-filter-section">
                <div class="form-group">
                    <input type="text" id="searchCustomer" placeholder="Search by name or email..."
                        onkeyup="applyFilters()" onfocus="this.placeholder=''"
                        onblur="this.placeholder='Search by name or email...'">
                </div>
                <div class="form-group">
                    <select id="filterGender" onchange="applyFilters()">
                        <option value="">All Genders</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <select id="filterMonth" onchange="applyFilters()">
                        <option value="">All Months</option>
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
                <button class="clear-all-btn" onclick="clearAllFilters()" type="button">
                    <i class="material-icons">clear_all</i>
                    Clear All
                </button>
            </div>

            <!-- Filter Indicators -->
            <div class="filter-indicators" id="filterIndicators">
                <div class="results-counter" id="resultsCounter"></div>
                <!-- Filter indicators will appear here dynamically -->
            </div>

            <div class="customers-grid" id="customersGrid">
                <?php if ($allCustomers && $allCustomers->num_rows > 0): ?>
                    <?php while ($customer = $allCustomers->fetch_assoc()):
                        // Determine customer type based on spending
                        $totalSpent = $customer['total_spent'] ?? 0;
                        $totalBookings = $customer['total_bookings'] ?? 0;

                        $customerType = 'regular';
                        $customerTypeText = 'Regular';
                        if ($totalSpent > 5000000) {
                            $customerType = 'premium';
                            $customerTypeText = 'Premium';
                        } elseif ($totalBookings == 0) {
                            $customerType = 'new';
                            $customerTypeText = 'New';
                        }

                        $customerMonth = date('m', strtotime($customer['created_at']));
                        $customerYear = date('Y', strtotime($customer['created_at']));
                        $customerDate = formatDate($customer['created_at']);
                        $lastBooking = $customer['last_booking_date'] ? formatDate($customer['last_booking_date']) : 'Never';
                    ?>
                        <div class="customer-card <?= $customerType ?>"
                            data-name="<?= htmlspecialchars($customer['nama']) ?>"
                            data-email="<?= htmlspecialchars($customer['email']) ?>"
                            data-gender="<?= $customer['gender'] ?? '' ?>"
                            data-month="<?= $customerMonth ?>"
                            data-year="<?= $customerYear ?>"
                            data-spent="<?= $totalSpent ?>">

                            <div class="customer-card-header">
                                <div class="customer-meta">
                                    <span class="customer-id">#<?= $customer['customer_id'] ?></span>
                                    <span class="customer-type"><?= $customerTypeText ?> Customer</span>
                                    <span class="customer-date">Joined: <?= $customerDate ?></span>
                                </div>
                                <span class="customer-status">
                                    <i class="material-icons">
                                        <?php
                                        switch ($customerType) {
                                            case 'premium':
                                                echo 'star';
                                                break;
                                            case 'new':
                                                echo 'fiber_new';
                                                break;
                                            default:
                                                echo 'person';
                                        }
                                        ?>
                                    </i>
                                    <?= $customerTypeText ?>
                                </span>
                            </div>

                            <div class="customer-name">
                                <i class="material-icons">person</i>
                                <?= htmlspecialchars($customer['nama']) ?>
                            </div>

                            <div class="customer-contact">
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="material-icons">email</i>
                                    </div>
                                    <div class="contact-info">
                                        <span class="contact-label">Email</span>
                                        <span class="contact-value"><?= htmlspecialchars($customer['email']) ?></span>
                                    </div>
                                </div>
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="material-icons">phone</i>
                                    </div>
                                    <div class="contact-info">
                                        <span class="contact-label">Phone</span>
                                        <span class="contact-value"><?= htmlspecialchars($customer['no_hp'] ?? 'N/A') ?></span>
                                    </div>
                                </div>
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="material-icons">wc</i>
                                    </div>
                                    <div class="contact-info">
                                        <span class="contact-label">Gender</span>
                                        <span class="contact-value"><?= htmlspecialchars($customer['gender'] ?? 'Not Specified') ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="customer-stats">
                                <div class="stat-item">
                                    <span class="stat-label">Total Bookings</span>
                                    <span class="stat-value"><?= $totalBookings ?></span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Last Booking</span>
                                    <span class="stat-value"><?= $lastBooking ?></span>
                                </div>
                            </div>

                            <div class="customer-footer">
                                <div class="customer-lifetime-value">
                                    <span class="value-label">Lifetime Value</span>
                                    <span class="value-amount"><?= formatRupiah($totalSpent) ?></span>
                                </div>
                                <div class="customer-actions">
                                    <button class="action-btn view-detail-btn" title="View Details" data-customer-id="<?= $customer['customer_id'] ?>">
                                        <i class="material-icons">visibility</i>
                                    </button>
                                    <button class="action-btn edit-customer-btn" title="Edit Customer" data-customer-id="<?= $customer['customer_id'] ?>">
                                        <i class="material-icons">edit</i>
                                    </button>
                                    <button class="action-btn delete-customer-btn" title="Delete Customer" data-customer-id="<?= $customer['customer_id'] ?>" data-customer-name="<?= htmlspecialchars($customer['nama']) ?>">
                                        <i class="material-icons">delete</i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="no-customers-message">
                        <i class="material-icons" style="font-size: 64px; color: #ccc; margin-bottom: 15px;">people</i>
                        <h3>No Customers Found</h3>
                        <p>There are no customers in the system.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Modal View Detail -->
            <div id="viewDetailModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>Customer Details</h2>
                        <span class="close-modal">&times;</span>
                    </div>
                    <div class="modal-body" id="viewDetailContent">
                        <!-- Content will be loaded via AJAX -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary close-modal">Close</button>
                    </div>
                </div>
            </div>

            <!-- Modal Edit Customer -->
            <div id="editCustomerModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>Edit Customer</h2>
                        <span class="close-modal">&times;</span>
                    </div>
                    <form id="editCustomerForm">
                        <div class="modal-body" id="editCustomerContent">
                            <!-- Content will be loaded via AJAX -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Delete Confirmation -->
            <div id="deleteConfirmationModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2>Confirm Delete</h2>
                        <span class="close-modal">&times;</span>
                    </div>
                    <div class="modal-body">
                        <p id="deleteConfirmationMessage"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                            <i class="material-icons">delete</i> Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Enhanced Filter Functionality for Customer List
        function applyFilters() {
            const searchVal = document.getElementById('searchCustomer').value.toLowerCase();
            const genderVal = document.getElementById('filterGender').value;
            const monthVal = document.getElementById('filterMonth').value;
            const customers = document.querySelectorAll('#customersGrid .customer-card');
            let visibleCount = 0;

            customers.forEach(card => {
                const customerName = card.getAttribute('data-name').toLowerCase();
                const customerEmail = card.getAttribute('data-email').toLowerCase();
                const customerGender = card.getAttribute('data-gender');
                const customerMonth = card.getAttribute('data-month');

                // Search Filter: Name or Email
                const isMatchSearch = (searchVal === '' ||
                    customerName.includes(searchVal) ||
                    customerEmail.includes(searchVal));

                // Gender Filter
                const isMatchGender = (genderVal === '' || customerGender === genderVal);

                // Month Filter
                const isMatchMonth = (monthVal === '' || customerMonth === monthVal);

                if (isMatchSearch && isMatchGender && isMatchMonth) {
                    card.style.display = 'block';
                    visibleCount++;

                    // Add animation
                    card.style.animation = 'fadeIn 0.5s ease';
                } else {
                    card.style.display = 'none';
                }
            });

            // Update results counter
            updateResultsCounter(visibleCount, customers.length);

            // Show/hide no results message
            showNoResultsMessage(visibleCount, searchVal, genderVal, monthVal);

            // Show filter active state
            updateFilterIndicators(searchVal, genderVal, monthVal);
        }

        // Function to show active filter indicators
        function updateFilterIndicators(searchVal, genderVal, monthVal) {
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

            // Add gender filter indicator
            if (genderVal) {
                const indicator = document.createElement('span');
                indicator.className = 'filter-indicator';
                indicator.innerHTML = `Gender: ${genderVal} <span class="clear-filter" onclick="clearGender()">×</span>`;
                indicatorsContainer.appendChild(indicator);
            }

            // Add month filter indicator
            if (monthVal) {
                const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
                ];
                const indicator = document.createElement('span');
                indicator.className = 'filter-indicator';
                indicator.innerHTML = `Joined: ${monthNames[parseInt(monthVal) - 1]} <span class="clear-filter" onclick="clearMonth()">×</span>`;
                indicatorsContainer.appendChild(indicator);
            }
        }

        // Helper functions to clear individual filters
        function clearSearch() {
            document.getElementById('searchCustomer').value = '';
            applyFilters();
        }

        function clearGender() {
            document.getElementById('filterGender').value = '';
            applyFilters();
        }

        function clearMonth() {
            document.getElementById('filterMonth').value = '';
            applyFilters();
        }

        // Clear all filters
        function clearAllFilters() {
            document.getElementById('searchCustomer').value = '';
            document.getElementById('filterGender').value = '';
            document.getElementById('filterMonth').value = '';
            applyFilters();
        }

        // Show no results message
        function showNoResultsMessage(visibleCount, searchVal, genderVal, monthVal) {
            const noCustomersMessage = document.querySelector('.no-customers-message');
            if (!noCustomersMessage) return;

            if (visibleCount > 0) {
                noCustomersMessage.style.display = 'none';
            } else {
                noCustomersMessage.style.display = 'block';

                // Customize message based on active filters
                const messageElement = noCustomersMessage.querySelector('p');
                if (!messageElement) return;

                if (searchVal || genderVal || monthVal) {
                    messageElement.textContent = 'No customers found with the current filters. Try adjusting your search criteria.';
                } else {
                    messageElement.textContent = 'No customers available in the system.';
                }
            }
        }

        // Update results counter
        function updateResultsCounter(visible, total) {
            const counter = document.getElementById('resultsCounter');
            if (counter) {
                counter.textContent = `Showing ${visible} of ${total} customers`;

                // Add color coding based on results
                if (visible === 0) {
                    counter.style.background = 'rgba(227, 73, 72, 0.1)';
                    counter.style.color = '#e34948';
                } else if (visible === total) {
                    counter.style.background = 'rgba(76, 175, 80, 0.1)';
                    counter.style.color = '#1baf7a';
                } else {
                    counter.style.background = 'rgba(255, 152, 0, 0.1)';
                    counter.style.color = '#eda100';
                }
            }
        }

        // Refresh customer list
        function refreshCustomerList() {
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
                showNotification('Customer list refreshed successfully!', 'success');
            }, 1000);
        }

        // Charts Initialization
        document.addEventListener('DOMContentLoaded', function() {
            // Data dari PHP
            const growthLabels = <?= json_encode($growth_months) ?>;
            const newCustomersData = <?= json_encode($new_customers) ?>;
            const cumulativeCustomersData = <?= json_encode($cumulative_customers) ?>;
            const demographicsLabels = <?= json_encode($demographics_labels) ?>;
            const demographicsData = <?= json_encode($demographics_counts) ?>;

            // Colors for charts
            const chartColors = {
                primary: '#2a78d6',
                success: '#1baf7a',
                warning: '#eda100',
                danger: '#e34948',
                info: '#9b59b6'
            };

            // 1. Customer Growth Chart
            const growthCtx = document.getElementById('customerGrowthChart');
            if (growthCtx) {
                new Chart(growthCtx, {
                    type: 'line',
                    data: {
                        labels: growthLabels,
                        datasets: [{
                            label: 'New Customers',
                            data: newCustomersData,
                            borderColor: chartColors.primary,
                            backgroundColor: 'rgba(42, 120, 214, 0.1)',
                            tension: 0.3,
                            fill: true,
                            borderWidth: 2
                        }, {
                            label: 'Total Customers',
                            data: cumulativeCustomersData,
                            borderColor: chartColors.success,
                            backgroundColor: 'rgba(46, 204, 113, 0.1)',
                            tension: 0.3,
                            fill: true,
                            borderWidth: 2,
                            borderDash: [5, 5]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            },
                            title: {
                                display: true,
                                text: 'Customer Growth (Last 6 Months)',
                                font: {
                                    size: 14
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Customers'
                                }
                            }
                        }
                    }
                });
            }

            // 2. Demographics Chart (Gender)
            const demographicsCtx = document.getElementById('demographicsChart');
            if (demographicsCtx) {
                new Chart(demographicsCtx, {
                    type: 'doughnut',
                    data: {
                        labels: demographicsLabels,
                        datasets: [{
                            data: demographicsData,
                            backgroundColor: [
                                chartColors.primary,
                                chartColors.success,
                                chartColors.warning,
                                chartColors.danger,
                                chartColors.info
                            ],
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
                                position: 'right',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `${label}: ${value} customers (${percentage}%)`;
                                    }
                                }
                            },
                            title: {
                                display: true,
                                text: 'Customer Demographics by Gender',
                                font: {
                                    size: 14
                                }
                            }
                        }
                    }
                });
            }

            // Initialize filters
            setTimeout(() => applyFilters(), 500);
        });

        // Modal functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Get modal elements
            const viewDetailModal = document.getElementById('viewDetailModal');
            const editCustomerModal = document.getElementById('editCustomerModal');
            const deleteConfirmationModal = document.getElementById('deleteConfirmationModal');
            const viewDetailContent = document.getElementById('viewDetailContent');
            const editCustomerContent = document.getElementById('editCustomerContent');
            const editCustomerForm = document.getElementById('editCustomerForm');
            const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
            const deleteConfirmationMessage = document.getElementById('deleteConfirmationMessage');

            let currentCustomerId = null;
            let currentCustomerName = null;

            // Close modal function
            function closeModals() {
                viewDetailModal.style.display = 'none';
                editCustomerModal.style.display = 'none';
                deleteConfirmationModal.style.display = 'none';
            }

            // Close modal when clicking on X or close button
            document.querySelectorAll('.close-modal').forEach(closeBtn => {
                closeBtn.addEventListener('click', closeModals);
            });

            // Close modal when clicking outside modal content
            window.addEventListener('click', function(event) {
                if (event.target === viewDetailModal) closeModals();
                if (event.target === editCustomerModal) closeModals();
                if (event.target === deleteConfirmationModal) closeModals();
            });

            // View Detail functionality
            document.querySelectorAll('.view-detail-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const customerId = this.getAttribute('data-customer-id');
                    loadCustomerDetails(customerId);
                });
            });

            // Edit Customer functionality
            document.querySelectorAll('.edit-customer-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const customerId = this.getAttribute('data-customer-id');
                    loadEditCustomerForm(customerId);
                });
            });

            // Delete Customer functionality
            document.querySelectorAll('.delete-customer-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentCustomerId = this.getAttribute('data-customer-id');
                    currentCustomerName = this.getAttribute('data-customer-name');
                    showDeleteConfirmation(currentCustomerName);
                });
            });

            // Confirm delete functionality
            confirmDeleteBtn.addEventListener('click', function() {
                if (currentCustomerId) {
                    deleteCustomer(currentCustomerId);
                }
            });

            // Form submission for edit customer
            editCustomerForm.addEventListener('submit', function(e) {
                e.preventDefault();
                saveCustomerChanges();
            });

            // Load customer details via AJAX
            function loadCustomerDetails(customerId) {
                viewDetailContent.innerHTML = `
                    <div class="loading">
                        <i class="material-icons">hourglass_empty</i>
                        <p>Loading customer details...</p>
                    </div>
                `;

                viewDetailModal.style.display = 'block';

                // AJAX call to get customer details
                fetch(`?action=get_customer_details&customer_id=${encodeURIComponent(customerId)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const customer = data.data;
                            viewDetailContent.innerHTML = `
                                <div class="detail-section">
                                    <h3><i class="material-icons">person</i> Customer Information</h3>
                                    <div class="detail-grid">
                                        <div class="detail-item">
                                            <span class="detail-label">Customer ID</span>
                                            <span class="detail-value">${escapeHtml(customer.customer_id)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Full Name</span>
                                            <span class="detail-value">${escapeHtml(customer.nama)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Username</span>
                                            <span class="detail-value">${escapeHtml(customer.username || 'N/A')}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Email</span>
                                            <span class="detail-value">${escapeHtml(customer.email)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Phone Number</span>
                                            <span class="detail-value">${escapeHtml(customer.no_hp || 'N/A')}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Gender</span>
                                            <span class="detail-value">${escapeHtml(customer.gender || 'Not Specified')}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Registration Date</span>
                                            <span class="detail-value">${formatDateTime(customer.created_at)}</span>
                                        </div>
                                    </div>
                                </div>

                                ${customer.address ? `
                                <div class="detail-section">
                                    <h3><i class="material-icons">location_on</i> Address</h3>
                                    <div class="detail-item">
                                        <span class="detail-value">${escapeHtml(customer.address)}</span>
                                    </div>
                                </div>
                                ` : ''}

                                <div class="detail-section">
                                    <h3><i class="material-icons">shopping_cart</i> Customer Activity</h3>
                                    <div class="detail-grid">
                                        <div class="detail-item">
                                            <span class="detail-label">Total Bookings</span>
                                            <span class="detail-value">${customer.total_bookings || 0}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Total Spent</span>
                                            <span class="detail-value">${formatCurrency(customer.total_spent || 0)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Cancelled Value</span>
                                            <span class="detail-value">${formatCurrency(customer.cancelled_value || 0)}</span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Customer Type</span>
                                            <span class="detail-value">
                                                ${(customer.total_spent || 0) > 5000000 ? 'Premium' : 
                                                  (customer.total_bookings || 0) > 0 ? 'Regular' : 'New'}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            `;
                        } else {
                            viewDetailContent.innerHTML = `
                                <div class="error-message">
                                    <i class="material-icons">error</i>
                                    <p>${data.message || 'Failed to load customer details'}</p>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        viewDetailContent.innerHTML = `
                            <div class="error-message">
                                <i class="material-icons">error</i>
                                <p>Failed to load customer details. Please try again.</p>
                            </div>
                        `;
                    });
            }

            // Load edit customer form via AJAX
            function loadEditCustomerForm(customerId) {
                editCustomerContent.innerHTML = `
                    <div class="loading">
                        <i class="material-icons">hourglass_empty</i>
                        <p>Loading customer form...</p>
                    </div>
                `;

                editCustomerModal.style.display = 'block';

                // AJAX call to get customer details for editing
                fetch(`?action=get_customer_details&customer_id=${encodeURIComponent(customerId)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const customer = data.data;
                            editCustomerContent.innerHTML = `
                                <input type="hidden" name="customer_id" value="${escapeHtml(customer.customer_id)}">
                                
                                <div class="form-group">
                                    <label for="nama">Full Name</label>
                                    <input type="text" class="form-control" id="nama" name="nama" value="${escapeHtml(customer.nama)}" required>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="email">Email Address</label>
                                        <input type="email" class="form-control" id="email" name="email" value="${escapeHtml(customer.email)}" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="no_hp">Phone Number</label>
                                        <input type="tel" class="form-control" id="no_hp" name="no_hp" value="${escapeHtml(customer.no_hp || '')}">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="gender">Gender</label>
                                        <select class="form-control" id="gender" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male" ${customer.gender === 'Male' ? 'selected' : ''}>Male</option>
                                            <option value="Female" ${customer.gender === 'Female' ? 'selected' : ''}>Female</option>
                                            <option value="Other" ${customer.gender === 'Other' ? 'selected' : ''}>Other</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="address">Address</label>
                                    <textarea class="form-control" id="address" name="address" rows="3">${escapeHtml(customer.address || '')}</textarea>
                                </div>

                                <div class="form-group">
                                    <label>Account Information (Read-only)</label>
                                    <div class="form-control" style="background: #f8f9fa;">
                                        Customer ID: ${escapeHtml(customer.customer_id)} | 
                                        Registered: ${formatDateTime(customer.created_at)}
                                    </div>
                                </div>
                            `;
                        } else {
                            editCustomerContent.innerHTML = `
                                <div class="error-message">
                                    <i class="material-icons">error</i>
                                    <p>${data.message || 'Failed to load customer form'}</p>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        editCustomerContent.innerHTML = `
                            <div class="error-message">
                                <i class="material-icons">error</i>
                                <p>Failed to load customer form. Please try again.</p>
                            </div>
                        `;
                    });
            }

            // Show delete confirmation modal
            function showDeleteConfirmation(customerName) {
                deleteConfirmationMessage.textContent = `Are you sure you want to delete customer "${customerName}"? This action cannot be undone and will remove all customer data from the system.`;
                deleteConfirmationModal.style.display = 'block';
            }

            // Save customer changes
            function saveCustomerChanges() {
                const formData = new FormData(editCustomerForm);

                // Show loading state
                const submitBtn = editCustomerForm.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="material-icons">hourglass_empty</i> Saving...';
                submitBtn.disabled = true;

                // AJAX call to update customer
                fetch('?action=update_customer', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification('Customer updated successfully!', 'success');
                            closeModals();
                            // Reload the page to reflect changes
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showNotification(data.message || 'Failed to update customer', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('Failed to update customer. Please try again.', 'error');
                    })
                    .finally(() => {
                        // Reset button
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    });
            }

            // Delete customer
            function deleteCustomer(customerId) {
                // Show loading state
                const deleteBtn = confirmDeleteBtn;
                const originalText = deleteBtn.innerHTML;
                deleteBtn.innerHTML = '<i class="material-icons">hourglass_empty</i> Deleting...';
                deleteBtn.disabled = true;

                // AJAX call to delete customer
                fetch('?action=delete_customer', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `customer_id=${encodeURIComponent(customerId)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            closeModals();
                            // Reload the page to reflect changes
                            setTimeout(() => {
                                location.reload();
                            }, 1500);
                        } else {
                            showNotification(data.message || 'Failed to delete customer', 'error');
                            deleteBtn.innerHTML = originalText;
                            deleteBtn.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('Failed to delete customer. Please try again.', 'error');
                        deleteBtn.innerHTML = originalText;
                        deleteBtn.disabled = false;
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
                if (!dateString) return '-';
                const options = {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                };
                return new Date(dateString).toLocaleDateString('en-US', options);
            }

            function formatDateTime(dateTimeString) {
                if (!dateTimeString) return '-';
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