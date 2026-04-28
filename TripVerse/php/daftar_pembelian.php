<?php
session_start();

if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

require 'connect.php'; // MySQLi connection

$id_user = $_SESSION['id_user'];

// Ambil data user untuk header
$userQuery = $conn->prepare("SELECT first_name, last_name, username, email, profile_picture FROM user WHERE id_user = ?");
$userQuery->bind_param("s", $id_user);
$userQuery->execute();
$userResult = $userQuery->get_result();
$userData = $userResult->fetch_assoc();
$userQuery->close();

// Set variabel untuk header
$firstName = $userData['first_name'] ?? '';
$lastName = $userData['last_name'] ?? '';
$username = $userData['username'] ?? '';
$email = $userData['email'] ?? '';
$profilePicture = $userData['profile_picture'] ?? '';

// DEBUG: Tampilkan informasi foto profil
error_log("Profile Picture Data: " . $profilePicture);
error_log("User ID: " . $id_user);

// Function untuk menangani path gambar dengan benar
function getImagePath($imagePath, $defaultPath = '../img/default.jpg') {
    if (empty($imagePath)) {
        return $defaultPath;
    }
    
    // Jika path sudah lengkap (http:// atau https://), gunakan langsung
    if (strpos($imagePath, 'http') === 0 || strpos($imagePath, 'https://') === 0) {
        return $imagePath;
    } 
    // Jika path dimulai dengan ../ atau img/, gunakan langsung
    else if (strpos($imagePath, '../') === 0 || strpos($imagePath, 'img/') === 0) {
        return $imagePath;
    }
    // Jika hanya nama file, tambahkan path default
    else {
        // Cek apakah file ada di berbagai lokasi yang mungkin
        $possiblePaths = [
            '../img/' . $imagePath,
            'img/' . $imagePath,
            '../uploads/' . $imagePath,
            'uploads/' . $imagePath,
            '../' . $imagePath,
            $imagePath
        ];
        
        // Gunakan path pertama yang valid
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        // Jika tidak ditemukan, gunakan path default
        return $defaultPath;
    }
}

// Handle profile picture path - PERBAIKAN UTAMA
$fotoPath = getImagePath($profilePicture, '../img/default.jpg');

// Validasi dan bersihkan path
$fotoPath = htmlspecialchars($fotoPath);

// DEBUG: Log path akhir
error_log("Final Foto Path: " . $fotoPath);

// Function untuk mendapatkan history booking dengan filter
function getBookingHistory($user_id, $filter_type = '90_hari', $start_date = null, $end_date = null)
{
    global $conn;

    $sql = "SELECT 
            bh.booking_id, 
            bh.check_in, 
            bh.check_out, 
            bh.jumlah_kamar, 
            bh.total_harga, 
            bh.status,
            bh.tanggal_booking,
            h.nama_hotel, 
            h.kota, 
            h.foto_hotel,
            t.nama_tipe,
            tr.tanggal_transaksi, 
            tr.status_transaksi,
            TIMESTAMPDIFF(MINUTE, bh.tanggal_booking, NOW()) as minutes_since_booking
        FROM booking_hotel bh
        JOIN hotel h ON bh.hotel_id = h.hotel_id
        JOIN tipe_kamar t ON bh.tipe_id = t.tipe_id
        JOIN customer c ON bh.customer_id = c.customer_id
        LEFT JOIN transaksi_hotel th ON bh.booking_id = th.booking_id
        LEFT JOIN transaksi tr ON th.id_transaksi = tr.id_transaksi
        WHERE c.id_user = ?";

    $params = [];
    $types = 's'; // Untuk tipe parameter - user_id adalah string

    // Parameter pertama: user_id
    $params[] = $user_id;

    // Tambahkan filter berdasarkan jenis
    switch ($filter_type) {
        case '90_hari':
            $sql .= " AND bh.tanggal_booking >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
            break;

        case '30_hari':
            $sql .= " AND bh.tanggal_booking >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;

        case 'custom':
            if ($start_date && $end_date) {
                $sql .= " AND DATE(bh.tanggal_booking) BETWEEN ? AND ?";
                $params[] = $start_date;
                $params[] = $end_date;
                $types .= 'ss';
            } else {
                // Jika tanggal custom tidak lengkap, default ke 30 hari terakhir
                $sql .= " AND bh.tanggal_booking >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            }
            break;

        case 'bulan_ini':
            $sql .= " AND MONTH(bh.tanggal_booking) = MONTH(CURDATE()) 
                     AND YEAR(bh.tanggal_booking) = YEAR(CURDATE())";
            break;

        case 'bulan_lalu':
            $sql .= " AND MONTH(bh.tanggal_booking) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
                     AND YEAR(bh.tanggal_booking) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))";
            break;

        case 'tahun_ini':
            $sql .= " AND YEAR(bh.tanggal_booking) = YEAR(CURDATE())";
            break;
    }

    $sql .= " ORDER BY bh.tanggal_booking DESC";

    try {
        // Persiapkan statement
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }

        // Bind parameters jika ada
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        // Eksekusi query
        $stmt->execute();

        // Dapatkan hasil
        $result = $stmt->get_result();
        $bookings = [];

        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }

        $stmt->close();
        return $bookings;
    } catch (Exception $exception) {
        error_log("Error getBookingHistory: " . $exception->getMessage());
        return [];
    }
}

// Function untuk opsi filter
function getFilterOptions()
{
    return [
        '90_hari' => '90 Hari Terakhir',
        '30_hari' => '30 Hari Terakhir',
        'bulan_ini' => 'Bulan Ini',
        'bulan_lalu' => 'Bulan Lalu',
        'tahun_ini' => 'Tahun Ini',
        'custom' => 'Tanggal Custom'
    ];
}

// Ambil parameter filter
$filter_periode = $_GET['filter_periode'] ?? '90_hari';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Jika filter custom dipilih tapi tanggal tidak lengkap, tetap gunakan custom
if ($filter_periode == 'custom' && (empty($start_date) || empty($end_date))) {
    // Set default values untuk custom date range
    if (empty($end_date)) {
        $end_date = date('Y-m-d');
    }
    if (empty($start_date)) {
        $start_date = date('Y-m-d', strtotime('-30 days'));
    }
}

// Debug: Cek apakah koneksi database berhasil
if (!isset($conn) || $conn->connect_error) {
    die("Error: Koneksi database tidak tersedia. Periksa file connect.php");
}

// Ambil data booking berdasarkan filter
$bookings = getBookingHistory($id_user, $filter_periode, $start_date, $end_date);
$filter_options = getFilterOptions();

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Pembelian - TripVerse</title>

    <!-- Favicon -->
    <link href="../img/favicon.ico" rel="icon">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

    <!-- Libraries -->
    <link href="../lib/animate/animate.min.css" rel="stylesheet">
    <link href="../lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="../lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet" />

    <!-- Bootstrap & Custom CSS -->
    <link href="../css/bootstrap.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <link href="../css/wa.css" rel="stylesheet">
    <link href="../css/home.css" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f8f9fa;
            color: #333;
            line-height: 1.6;
        }

        .main-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 20px;
        }

        .content-wrapper {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 30px;
            margin-top: 20px;
        }

        .sidebar {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
            height: fit-content;
            position: sticky;
            top: 20px;
        }

        .sidebar-title {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #3498db;
        }

        .menu-list {
            list-style: none;
        }

        .menu-item {
            margin-bottom: 8px;
        }

        .menu-link {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            text-decoration: none;
            color: #555;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .menu-link:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateX(5px);
        }

        .menu-link.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .menu-icon {
            margin-right: 12px;
            font-size: 18px;
            width: 20px;
            text-align: center;
        }

        .content-area {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        }

        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .page-subtitle {
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 16px;
        }

        .filter-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 1px solid #e9ecef;
        }

        .filter-form {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .filter-form select,
        .filter-form input {
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            min-width: 160px;
            transition: all 0.3s ease;
        }

        .filter-form select:focus,
        .filter-form input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filter-form button {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .filter-form button:hover {
            background: #5a6fd8;
            transform: translateY(-2px);
        }

        .btn-reset {
            background: #6c757d !important;
        }

        .btn-reset:hover {
            background: #5a6268 !important;
        }

        .booking-list {
            display: grid;
            gap: 20px;
        }

        .booking-item {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 25px;
            display: flex;
            gap: 25px;
            transition: all 0.3s ease;
            background: white;
        }

        .booking-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-color: #667eea;
        }

        .hotel-image {
            width: 140px;
            height: 120px;
            border-radius: 10px;
            object-fit: cover;
            flex-shrink: 0;
        }

        .booking-details {
            flex: 1;
        }

        .hotel-name {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .hotel-location {
            color: #7f8c8d;
            margin-bottom: 15px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .booking-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .meta-item {
            font-size: 14px;
        }

        .meta-label {
            font-weight: 600;
            color: #555;
            display: block;
            margin-bottom: 4px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-value {
            color: #2c3e50;
            font-weight: 500;
        }

        .booking-status {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-confirmed,
        .status-completed,
        .status-success {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-cancelled,
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }

        .booking-price {
            text-align: right;
            min-width: 180px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .price-amount {
            font-size: 24px;
            font-weight: 700;
            color: #667eea;
        }

        .price-label {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }

        .booking-time {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 10px;
        }

        .new-booking {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .new-booking:hover {
            background: #218838;
            transform: translateY(-2px);
            color: white;
            text-decoration: none;
        }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }

        .no-data h3 {
            font-size: 22px;
            margin-bottom: 15px;
            color: #2c3e50;
        }

        .no-data p {
            font-size: 16px;
            margin-bottom: 25px;
        }

        .transaction-info {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px dashed #e9ecef;
            font-size: 13px;
            color: #7f8c8d;
        }

        .badge-new {
            background: #28a745;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            margin-left: 8px;
        }

        /* Profile Dropdown Styles - PERBAIKAN */
        .profile-dropdown-container {
            position: relative;
        }

        .profile-photo-container {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .profile-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-text {
            line-height: 1.2;
        }

        .profile-text .name {
            font-size: 14px;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .profile-text .email {
            font-size: 12px;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Dropdown Menu Styling */
        .dropdown-menu {
            min-width: 250px;
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .dropdown-header {
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 12px 12px 0 0;
        }

        .dropdown-item {
            padding: 10px 16px;
            border-radius: 8px;
            margin: 2px 8px;
            width: auto;
            transition: all 0.3s ease;
        }

        .dropdown-item:hover {
            background-color: #667eea;
            color: white;
            transform: translateX(5px);
        }

        .dropdown-divider {
            margin: 8px 0;
        }

        @media (max-width: 991px) {
            .content-wrapper {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .sidebar {
                position: relative;
                top: 0;
            }
        }

        @media (max-width: 768px) {
            .booking-item {
                flex-direction: column;
                gap: 20px;
            }

            .hotel-image {
                width: 100%;
                height: 200px;
            }

            .booking-price {
                text-align: left;
                min-width: auto;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-form select,
            .filter-form input {
                min-width: auto;
            }
        }

        @media (max-width: 576px) {
            .main-container {
                padding: 0 15px;
            }

            .content-area {
                padding: 20px;
            }

            .booking-meta {
                grid-template-columns: 1fr;
            }

            .page-title {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>
    <!-- Spinner -->
    <div id="spinner" class="show bg-white position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="sr-only">Loading...</span>
        </div>
    </div>

    <!-- Header -->
    <div class="container-fluid bg-dark px-0">
        <div class="row gx-0">
            <div class="col-lg-3 bg-dark d-none d-lg-flex align-items-center justify-content-center">
                <a href="about.php" class="d-flex align-items-center text-decoration-none">
                    <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 50px;">
                    <h1 class="m-0 text-primary text-uppercase">TripVerse</h1>
                </a>
            </div>

            <div class="col-lg-9">
                <div class="row gx-0 bg-white d-none d-lg-flex align-items-center py-2 px-5">
                    <div class="col-lg-7 text-start">
                        <div class="d-inline-flex align-items-center me-4">
                            <i class="fa fa-envelope text-primary me-2"></i>
                            <p class="mb-0">tripverse@gmail.com</p>
                        </div>
                        <div class="d-inline-flex align-items-center">
                            <i class="fa fa-phone-alt text-primary me-2"></i>
                            <p class="mb-0">+62 878 0677 6235</p>
                        </div>
                    </div>
                    <div class="col-lg-5 text-end">
                        <div class="d-inline-flex align-items-center">
                            <a class="me-3" href="https://www.facebook.com/"><i class="fab fa-facebook-f"></i></a>
                            <a class="me-3" href="https://twitter.com/"><i class="fab fa-twitter"></i></a>
                            <a class="me-3" href="https://id.linkedin.com/"><i class="fab fa-linkedin-in"></i></a>
                            <a class="me-3" href="https://www.instagram.com/"><i class="fab fa-instagram"></i></a>
                            <a class="" href="https://www.youtube.com/"><i class="fab fa-youtube"></i></a>
                        </div>
                    </div>
                </div>

                <nav class="navbar navbar-expand-lg bg-dark navbar-dark p-3 p-lg-0">
                    <a href="home.php" class="navbar-brand d-block d-lg-none">
                        <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 40px;">
                        <h1 class="m-0 text-primary text-uppercase">TripVerse</h1>
                    </a>
                    <button type="button" class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#navbarCollapse">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse justify-content-between" id="navbarCollapse">
                        <div class="navbar-nav mr-auto py-0">
                            <a href="home.php" class="nav-item nav-link">Beranda</a>
                            <a href="about.php" class="nav-item nav-link">Tentang Kami</a>
                            <a href="hotel.php" class="nav-item nav-link">Hotel</a>
                            <a href="service.php" class="nav-item nav-link">Fitur</a>
                            <a href="team.php" class="nav-item nav-link">Tim Kami</a>
                            <a href="contact.php" class="nav-item nav-link">Kontak</a>
                            <a href="riwayat.php" class="nav-item nav-link">Riwayat</a>
                        </div>

                        <!-- PERBAIKAN: Profile Dropdown yang Benar -->
                        <div class="ms-auto me-2 dropdown profile-dropdown-container">
                            <a class="nav-link p-0 d-flex align-items-center" href="#" id="profileDropdownToggle" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <div class="profile-photo-container">
                                    <img src="<?= $fotoPath ?>"
                                        alt="Profile Photo"
                                        class="profile-photo"
                                        id="profilePhoto"
                                        onerror="this.onerror=null; this.src='../img/default.jpg';">
                                </div>
                                <div class="profile-text d-none d-lg-block ms-2 me-2">
                                    <div class="name fw-semibold text-white">
                                        <?= htmlspecialchars(trim($firstName . ' ' . $lastName)) ?: htmlspecialchars($username) ?>
                                    </div>
                                    <div class="email small text-light"><?= htmlspecialchars($email) ?></div>
                                </div>
                                <span class="material-icons text-white d-none d-lg-block me-3">expand_more</span>
                            </a>

                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="profileDropdownToggle">
                                <li>
                                    <div class="dropdown-header text-truncate">
                                        <span class="fw-bold"><?= htmlspecialchars(trim($firstName . ' ' . $lastName)) ?: htmlspecialchars($username) ?></span><br>
                                        <span class="small text-muted"><?= htmlspecialchars($email) ?></span>
                                    </div>
                                </li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center" href="profile_customer.php">
                                        <span class="material-icons me-2">person</span> Profil Saya
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center" href="riwayat.php">
                                        <span class="material-icons me-2">receipt_long</span> Pesanan Saya
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center" href="daftar_pembelian.php">
                                        <span class="material-icons me-2">shopping_bag</span> Daftar Pembelian
                                    </a>
                                </li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center" href="logout.php">
                                        <span class="material-icons me-2">logout</span> Logout
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </nav>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-container">
        <div class="content-wrapper">
            <!-- Sidebar Menu -->
            <div class="sidebar">
                <h3 class="sidebar-title">Akun Saya</h3>
                <ul class="menu-list">
                    <li class="menu-item">
                    <a href="profile_customer.php" class="menu-link <?php echo $active_section == 'profile' ? 'active' : ''; ?>">
                        <span class="menu-icon">👤</span>
                        <span>Profil Saya</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="?section=orders" class="menu-link <?php echo $active_section == 'orders' ? 'active' : ''; ?>">
                        <span class="menu-icon">📦</span>
                        <span>Pesanan Saya</span>
                    </a>
                </li>
                <li class="menu-item">
                    <a href="daftar_pembelian.php" class="menu-link active ">
                        <i class="menu-icon material-icons">shopping_bag</i> Daftar Pembelian</a>
                </li>
                <li class="menu-item">
                    <a href="?section=settings" class="menu-link <?php echo $active_section == 'settings' ? 'active' : ''; ?>">
                        <span class="menu-icon">⚙️</span>
                        <span>Pengaturan</span><!--Optional-->
                    </a>
                </li>
                <li>
                    <a class="dropdown-item d-flex align-items-center" href="logout.php">
                        <span class="material-icons me-2">logout</span> Logout
                    </a>
                </li>
                </ul>
            </div>

            <!-- Content Area -->
            <div class="content-area">
                <h1 class="page-title">Daftar Pembelian</h1>
                <p class="page-subtitle">Kelola dan lihat riwayat pembelian hotel Anda</p>

                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="GET" class="filter-form" id="filterForm">
                        <select name="filter_periode" id="filter_periode">
                            <?php foreach ($filter_options as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($filter_periode == $value) ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div id="custom_date_range" style="display: <?= ($filter_periode == 'custom') ? 'flex' : 'none'; ?>; gap: 10px; align-items: center;">
                            <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>">
                            <span>s/d</span>
                            <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>">
                        </div>

                        <button type="submit">Terapkan Filter</button>
                        <?php if ($filter_periode != '90_hari'): ?>
                            <button type="button" onclick="resetFilter()" class="btn-reset">Reset</button>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Booking List -->
                <div class="booking-list">
                    <?php if (empty($bookings)): ?>
                        <div class="no-data">
                            <h3>
                                Tidak ada pembelian dalam
                                <?=
                                $filter_periode == '90_hari' ? '90' : ($filter_periode == '30_hari' ? '30' : ($filter_periode == 'bulan_ini' ? 'bulan ini' : ($filter_periode == 'bulan_lalu' ? 'bulan lalu' : ($filter_periode == 'tahun_ini' ? 'tahun ini' : ($filter_periode == 'custom' ? 'rentang tanggal yang dipilih' : 'periode yang dipilih')))))
                                ?> hari terakhir
                            </h3>
                            <p>Jika Anda pernah melakukan pembelian sebelumnya, silakan gunakan Filter untuk melihatnya.</p>
                            <a href="hotel.php" class="new-booking">
                                <i class="fas fa-plus"></i> Cari Hotel
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($bookings as $booking): ?>
                            <?php
                            // PERBAIKAN: Gunakan function getImagePath untuk foto hotel
                            $hotelImagePath = getImagePath($booking['foto_hotel'], '../img/default-hotel.jpg');
                            ?>
                            <div class="booking-item">
                                <img src="<?= htmlspecialchars($hotelImagePath) ?>"
                                    alt="<?= htmlspecialchars($booking['nama_hotel']) ?>"
                                    class="hotel-image"
                                    onerror="this.onerror=null; this.src='../img/default-hotel.jpg';">

                                <div class="booking-details">
                                    <div class="hotel-name">
                                        <?= htmlspecialchars($booking['nama_hotel']) ?>
                                        <?php if (isset($booking['minutes_since_booking']) && $booking['minutes_since_booking'] < 1440): ?>
                                            <span class="badge-new">BARU</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="hotel-location">
                                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($booking['kota']) ?>
                                    </div>

                                    <div class="booking-meta">
                                        <div class="meta-item">
                                            <span class="meta-label">Check-in</span>
                                            <div class="meta-value"><?= date('d M Y', strtotime($booking['check_in'])) ?></div>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Check-out</span>
                                            <div class="meta-value"><?= date('d M Y', strtotime($booking['check_out'])) ?></div>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Tipe Kamar</span>
                                            <div class="meta-value"><?= htmlspecialchars($booking['nama_tipe']) ?></div>
                                        </div>
                                        <div class="meta-item">
                                            <span class="meta-label">Jumlah Kamar</span>
                                            <div class="meta-value"><?= $booking['jumlah_kamar'] ?> Kamar</div>
                                        </div>
                                    </div>

                                    <div class="meta-item">
                                        <span class="meta-label">Status Booking</span>
                                        <span class="booking-status status-<?= strtolower($booking['status']) ?>">
                                            <?= $booking['status'] ?>
                                        </span>
                                    </div>

                                    <?php if ($booking['tanggal_transaksi']): ?>
                                        <div class="transaction-info">
                                            <span class="meta-label">Transaksi:</span>
                                            <?= date('d M Y H:i', strtotime($booking['tanggal_transaksi'])) ?> -
                                            <span class="booking-status status-<?= strtolower($booking['status_transaksi']) ?>">
                                                <?= $booking['status_transaksi'] ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="booking-price">
                                    <div>
                                        <div class="price-amount">
                                            Rp <?= number_format($booking['total_harga'], 0, ',', '.') ?>
                                        </div>
                                        <div class="price-label">Total Pembayaran</div>
                                    </div>
                                    <div class="booking-time">
                                        <?= date('d M Y H:i', strtotime($booking['tanggal_booking'])) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleCustomDate() {
            const filterSelect = document.getElementById('filter_periode');
            const customDateRange = document.getElementById('custom_date_range');

            if (filterSelect.value === 'custom') {
                customDateRange.style.display = 'flex';
                // Set default values jika kosong
                const today = new Date().toISOString().split('T')[0];
                const startDateInput = document.getElementById('start_date');
                const endDateInput = document.getElementById('end_date');

                if (!endDateInput.value) {
                    endDateInput.value = today;
                }
                if (!startDateInput.value) {
                    const thirtyDaysAgo = new Date();
                    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                    startDateInput.value = thirtyDaysAgo.toISOString().split('T')[0];
                }
            } else {
                customDateRange.style.display = 'none';
            }
        }

        function resetFilter() {
            window.location.href = '<?= basename($_SERVER['PHP_SELF']) ?>';
        }

        // Event listener untuk perubahan select
        document.getElementById('filter_periode').addEventListener('change', toggleCustomDate);

        // Set min/max dates for date inputs dan inisialisasi
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const dateInputs = document.querySelectorAll('input[type="date"]');

            dateInputs.forEach(input => {
                input.setAttribute('max', today);
            });

            // Inisialisasi tampilan custom date range
            toggleCustomDate();

            // Hide spinner when page is loaded
            setTimeout(() => {
                const spinner = document.getElementById('spinner');
                if (spinner) {
                    spinner.style.display = 'none';
                }
            }, 500);
        });

        function sendWhatsApp() {
            const phone = '6287806776235';
            const message = 'Halo TripVerse, saya ingin bertanya tentang...';
            const url = `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
            window.open(url, '_blank');
        }
    </script>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../lib/wow/wow.min.js"></script>
    <script src="../lib/easing/easing.min.js"></script>
    <script src="../lib/waypoints/waypoints.min.js"></script>
    <script src="../lib/counterup/counterup.min.js"></script>
    <script src="../lib/owlcarousel/owl.carousel.min.js"></script>
    <script src="../lib/tempusdominus/js/moment.min.js"></script>
    <script src="../lib/tempusdominus/js/moment-timezone.min.js"></script>
    <script src="../lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>

    <!-- Template Javascript -->
    <script src="../js/main.js"></script>
</body>

</html>