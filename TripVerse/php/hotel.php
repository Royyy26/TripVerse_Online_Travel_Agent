<?php
session_start();
require_once __DIR__ . '/_lang.php';
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

// Database connection
$host = "localhost";
$username = "root";
$password = "";
$database = "tripverse";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$cities = ['Jakarta', 'Bogor', 'Depok', 'Tangerang', 'Bekasi'];

// Di bagian atas file, setelah $cities didefinisikan
$hotelsCountByCity = [];
foreach ($cities as $city) {
    $countSql = "SELECT COUNT(*) as hotel_count FROM hotel WHERE kota = ?";
    $countStmt = $conn->prepare($countSql);
    $countStmt->bind_param("s", $city);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $countData = $countResult->fetch_assoc();
    $hotelsCountByCity[$city] = $countData['hotel_count'];
}

// Get selected city from URL parameter
$selectedCity = isset($_GET['city']) ? $_GET['city'] : 'Jakarta';

// Get top 3 hotels for selected city
$sql = "SELECT * FROM hotel 
        WHERE kota = ? 
        ORDER BY harga_dasar ASC 
        LIMIT 3";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $selectedCity);
$stmt->execute();
$result = $stmt->get_result();
$topHotels = $result->fetch_all(MYSQLI_ASSOC);

// Get all hotels for all cities (for the filter buttons)
$allHotels = [];
foreach ($cities as $city) {
    $sql = "SELECT * FROM hotel 
            WHERE kota = ? 
            ORDER BY harga_dasar ASC 
            LIMIT 3";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $city);
    $stmt->execute();
    $result = $stmt->get_result();
    $allHotels[$city] = $result->fetch_all(MYSQLI_ASSOC);
}

// ============== HISTORY MANAGEMENT ============== //

// History Pencarian Kota
if (!isset($_SESSION['search_history'])) {
    $_SESSION['search_history'] = [];
}

// History Hotel yang Diklik
if (!isset($_SESSION['hotel_history'])) {
    $_SESSION['hotel_history'] = [];
}

// Handle clear city history
if (isset($_GET['clear_history']) && $_GET['clear_history'] == '1') {
    $_SESSION['search_history'] = [];
    $_SESSION['hotel_history'] = []; // Juga hapus hotel history kalau mau
    // Redirect tanpa parameter untuk hindari refresh issues
    header('Location: hotel.php');
    exit;
}

// Handle clear hotel history - TAMBAHKAN DI SINI
if (isset($_GET['clear_hotel_history']) && $_GET['clear_hotel_history'] == '1') {
    $_SESSION['hotel_history'] = [];
    header('Location: hotel.php');
    exit;
}

// Kalau user memilih kota (di-select di dropdown)
if (isset($_GET['city'])) {
    $city = $_GET['city'];

    // Hapus kota yang sama (hindari duplikasi)
    $_SESSION['search_history'] = array_filter($_SESSION['search_history'], function ($item) use ($city) {
        return $item['kota'] !== $city;
    });

    // Masukkan data baru ke posisi paling atas (index 0)
    array_unshift($_SESSION['search_history'], [
        "kota" => $city,
        "info" => t("Terakhir dicari")
    ]);
}

// Simpan hotel yang diklik ke riwayat - TAMBAHKAN DI SINI JUGA
if (isset($_GET['hotel_clicked'])) {
    $hotel_id = $_GET['hotel_clicked'];

    // Query untuk mendapatkan info hotel
    $hotelSql = "SELECT hotel_id, nama_hotel, kota, harga_dasar, foto_hotel FROM hotel WHERE hotel_id = ?";
    $hotelStmt = $conn->prepare($hotelSql);
    $hotelStmt->bind_param("s", $hotel_id);
    $hotelStmt->execute();
    $hotelResult = $hotelStmt->get_result();

    if ($hotelData = $hotelResult->fetch_assoc()) {
        // Hapus hotel yang sama jika sudah ada
        $_SESSION['hotel_history'] = array_filter($_SESSION['hotel_history'], function ($item) use ($hotel_id) {
            return $item['hotel_id'] !== $hotel_id;
        });

        // Tambahkan ke history hotel
        array_unshift($_SESSION['hotel_history'], [
            "hotel_id" => $hotelData['hotel_id'],
            "nama_hotel" => $hotelData['nama_hotel'],
            "kota" => $hotelData['kota'],
            "harga" => $hotelData['harga_dasar'],
            "foto" => $hotelData['foto_hotel'],
            "waktu" => date('Y-m-d H:i:s')
        ]);

        // Batasi hanya 3 hotel terakhir
        $_SESSION['hotel_history'] = array_slice($_SESSION['hotel_history'], 0, 3);

        // Redirect ke hotel_detail.php
        header("Location: hotel_detail.php?id=" . $hotel_id);
        exit;
    }
    $hotelStmt->close();
}

// Kirim ke search_history.php
$history = $_SESSION['search_history'] = array_slice($_SESSION['search_history'], 0, 3);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>TripVerse - <?= te('Hotel') ?></title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicon -->
    <link href="../img/favicon.ico" rel="icon">

    <!-- Google Web Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Libraries Stylesheet -->
    <link href="../lib/animate/animate.min.css" rel="stylesheet">
    <link href="../lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="../lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet" />

    <!-- Customized Bootstrap Stylesheet -->
    <link href="../css/bootstrap.min.css" rel="stylesheet">

    <!-- Template Stylesheet -->
    <link href="../css/style.css?v=2.0" rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <!-- Tambahkan CSS Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="../css/search_history.css?v=2.0" rel="stylesheet">
    <link href="../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>" rel="stylesheet">

    <style>
        html,
        body {
            font-family: 'Heebo', sans-serif;
            height: 100%;
            margin: 0;
            padding: 0;
            background-color: #ffffff;
        }

        /* Warna Oranye Utama */
        :root {
            --orange-dark: #E8890A;
            --orange-primary: #FEA116;
            --orange-very-light: #FFF5E6;
        }

        .page-header {
            position: relative;
        }

        .page-header::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(15, 23, 43, .35) 0%, rgba(15, 23, 43, .78) 100%);
        }

        .page-header .container {
            position: relative;
            z-index: 2;
        }

        .search-container {
            max-width: 800px;
            background-color: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            padding: 28px 26px;
            border-radius: 22px;
            box-shadow: 0 30px 60px rgba(15, 23, 43, 0.25);
            margin: -95px auto 50px;
            position: relative;
            z-index: 10;
            border-top: 4px solid transparent;
            border-image: linear-gradient(135deg, #FEA116, #FF7A3D) 1;
            animation: tv-card-in-up .7s cubic-bezier(.22, 1, .36, 1);
        }

        @keyframes tv-card-in-up {
            from {
                opacity: 0;
                transform: translateY(36px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            color: var(--orange-primary);
        }

        .title .icon {
            margin-right: 8px;
            color: var(--orange-primary);
        }

        /* Tombol utama - warna oranye */
        .btn-search,
        .btn-primary,
        .room-button,
        .city-btn.active {
            background: linear-gradient(135deg, #FEA116 0%, #FF7A3D 100%);
            color: white;
            border-radius: 999px;
            font-weight: bold;
            transition: transform .3s cubic-bezier(.22, 1, .36, 1), box-shadow .3s ease, filter .3s ease;
            border: none;
        }

        .btn-search {
            padding: .85rem 1.5rem;
            box-shadow: 0 12px 26px rgba(254, 161, 22, 0.4);
        }

        .btn-search:hover,
        .btn-primary:hover,
        .room-button:hover {
            filter: brightness(1.06);
            transform: translateY(-3px);
            box-shadow: 0 14px 28px rgba(254, 161, 22, 0.45);
        }

        .form-control,
        .form-select {
            border-radius: 8px;
            min-height: 40px;
        }

        .input-group .form-control {
            border-left: none;
        }

        .input-group .input-group-text {
            background-color: #f5f5f5;
            border-right: none;
        }

        .destination-dropdown {
            background-color: #ffffff;
            border: 1px solid #ddd;
            border-radius: 8px;
            position: absolute;
            width: 45%;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-top: 4px;
            max-height: 300px;
            overflow-y: auto;
            padding: 12px 16px;
            font-family: 'Heebo', sans-serif;
        }

        .dest-item {
            padding: 10px 8px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.2s;
            margin-bottom: 4px;
        }

        .dest-item:last-child {
            margin-bottom: 0;
        }

        .dest-item:hover {
            background-color: var(--orange-very-light);
        }

        .dest-item>div:first-child {
            font-size: 14px;
            font-weight: 500;
            color: #212529;
        }

        .dest-item .text-muted {
            font-size: 12px;
            color: #6c757d;
        }

        .dest-item .badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            background-color: #f0f0f0;
            color: var(--orange-primary);
        }

        .dropdown-menu {
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
        }

        .dropdown-menu li {
            padding: 6px 12px;
        }

        .btn-outline-secondary {
            border-radius: 50%;
            width: 30px;
            height: 30px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        input[readonly] {
            background-color: #f8f9fa;
            cursor: not-allowed;
            opacity: 0.8;
        }

        .container-xxl.bg-white.p-0 {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .footer {
            margin-top: auto;
            padding: 1.5rem 0;
            background-color: var(--orange-primary);
        }

        .container-fluid.bg-dark.p-0 {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .header {
            margin-top: auto;
        }

        .room-item {
            position: relative;
            overflow: hidden;
            border-radius: 10px;
            height: 250px;
        }

        .room-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .room-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            color: #fff;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 20px;
            transition: background 0.3s ease;
        }

        .room-overlay h5,
        .room-overlay p {
            margin: 0;
            z-index: 2;
        }

        .room-button {
            margin-top: 10px;
            padding: 8px 14px;
            color: white;
            border: 1px solid white;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease;
            text-align: center;
            border-radius: 3px;
        }

        .room-item:hover .room-button {
            opacity: 1;
            transform: translateY(0);
        }

        .hotel-card {
            transition: transform .4s cubic-bezier(.22, 1, .36, 1), box-shadow .4s ease;
            height: 100%;
            border: none;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 6px 18px rgba(15, 23, 43, 0.08);
        }

        .hotel-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 24px 44px rgba(15, 23, 43, 0.18);
        }

        .city-filter {
            margin-bottom: 30px;
        }

        .city-btn {
            margin: 5px;
            min-width: 120px;
            border: 2px solid #eee;
            border-radius: 999px !important;
            font-weight: 600;
            transition: all .3s cubic-bezier(.22, 1, .36, 1);
        }

        .city-btn:hover {
            transform: translateY(-2px);
            border-color: var(--orange-primary);
        }

        .city-btn.active {
            background: linear-gradient(135deg, #FEA116 0%, #FF7A3D 100%);
            color: white;
            border-color: transparent;
            box-shadow: 0 10px 22px rgba(254, 161, 22, 0.4);
        }

        .hotel-img {
            height: 200px;
            object-fit: cover;
            transition: transform .5s ease;
        }

        .hotel-card:hover .hotel-img {
            transform: scale(1.06);
        }

        .price {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--orange-dark);
        }

        .facility-icon {
            font-size: 1.2rem;
            margin-right: 5px;
            color: var(--orange-primary);
        }

        .nav-item.nav-link.active {
            color: var(--orange-primary) !important;
        }

        .section-title.text-primary {
            color: var(--orange-primary) !important;
        }

        .badge.bg-primary {
            background-color: var(--orange-primary) !important;
        }

        .bg-dark.footer {
            background-color: #212529 !important;
        }

        .footer .section-title {
            color: var(--orange-primary) !important;
        }

        .footer .btn-social {
            color: var(--orange-primary);
            border-color: var(--orange-primary);
        }

        .footer .btn-social:hover {
            background-color: var(--orange-primary);
            color: white;
        }

        .spinner-border.text-primary {
            color: var(--orange-primary) !important;
        }

        .page-header h1 {
            color: white;
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.5);
        }

        .breadcrumb-item.active {
            color: var(--orange-light);
        }

        .card:hover {
            border-color: var(--orange-light);
        }

        .btn-outline-primary {
            color: var(--orange-primary);
            border-color: var(--orange-primary);
        }

        .btn-outline-primary:hover {
            background-color: var(--orange-primary);
            color: white;
        }

        .btn-clear-history {
            background: #DC2626;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            float: right;
        }

        .btn-clear-history:hover {
            background: #c82333;
        }

        /* Riwayat Hotel Styles */
        .hotel-history-container {
            background-color: white;
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        }

        .history-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--orange-primary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .history-hotel-card {
            display: flex;
            align-items: center;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            background-color: #f8f9fa;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid #e9ecef;
            text-decoration: none;
            color: inherit;
        }

        .history-hotel-card:hover {
            background-color: var(--orange-very-light);
            border-color: var(--orange-primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 153, 51, 0.15);
        }

        .hotel-history-img {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
            margin-right: 15px;
        }

        .hotel-history-info {
            flex: 1;
        }

        .hotel-history-name {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #333;
        }

        .hotel-history-location {
            font-size: 12px;
            color: #666;
            display: flex;
            align-items: center;
            margin-bottom: 4px;
        }

        .hotel-history-location i {
            margin-right: 5px;
            color: var(--orange-primary);
        }

        .hotel-history-price {
            font-size: 13px;
            font-weight: 600;
            color: var(--orange-dark);
        }

        .hotel-history-time {
            font-size: 10px;
            color: #999;
            text-align: right;
        }

        .history-section {
            margin-top: 40px;
        }

        .clear-all-history {
            background-color: #DC2626;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .clear-all-history:hover {
            background-color: #c82333;
        }
    </style>
</head>

<body>
    <div class="container-fluid" style="background-color: #ffffff;">

        <!-- Spinner Start -->
        <div id="spinner" class="show bg-white position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
        <!-- Spinner End -->

        <!-- Header Start -->
        <header class="container-fluid bg-dark px-0">
            <div class="row gx-0">
                <!-- Logo -->
                <div class="col-lg-3 bg-dark d-none d-lg-flex align-items-center justify-content-center">
                    <a href="about.php" class="d-flex align-items-center text-decoration-none">
                        <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 50px;">
                        <span class="tv-wordmark tv-wordmark-header">TripVerse</span>
                    </a>
                </div>

                <!-- Contact -->
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
                                <a class="me-3" href="#"><i class="fab fa-facebook-f"></i></a>
                                <a class="me-3" href="#"><i class="fab fa-twitter"></i></a>
                                <a class="me-3" href="#"><i class="fab fa-linkedin-in"></i></a>
                                <a class="me-3" href="#"><i class="fab fa-instagram"></i></a>
                                <a class="" href="#"><i class="fab fa-youtube"></i></a>
                            </div>
                        </div>
                    </div>

                    <!-- Navbar -->
                    <nav class="navbar navbar-expand-lg bg-dark navbar-dark p-3 p-lg-0">
                        <a href="home.php" class="navbar-brand d-block d-lg-none">
                            <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 40px;">
                            <span class="tv-wordmark tv-wordmark-header">TripVerse</span>
                        </a>
                        <button type="button" class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#navbarCollapse">
                            <span class="navbar-toggler-icon"></span>
                        </button>
                        <div class="collapse navbar-collapse justify-content-between" id="navbarCollapse">
                            <div class="navbar-nav mr-auto py-0">
                                <a href="home.php" class="nav-item nav-link"><?= te("Beranda") ?></a>
                                <a href="about.php" class="nav-item nav-link"><?= te("Tentang Kami") ?></a>
                                <a href="hotel.php" class="nav-item nav-link active"><?= te("Hotel") ?></a>
                                <a href="flights.php" class="nav-item nav-link"><?= te("Pesawat") ?></a>
                                <a href="service.php" class="nav-item nav-link"><?= te("Fitur") ?></a>
                                <a href="team.php" class="nav-item nav-link"><?= te("Tim Kami") ?></a>
                                <a href="contact.php" class="nav-item nav-link"><?= te("Kontak") ?></a>
                                <a href="history.php" class="nav-item nav-link"><?= te("Riwayat") ?></a>
                            </div>
                            <?php include __DIR__ . '/_lang_switch.php'; ?><?php include __DIR__ . '/_account_menu.php'; ?>
                        </div>
                    </nav>
                </div>
            </div>
        </header>
        <!-- Header End -->

        <!-- Page Header Start -->
        <div class="container-fluid page-header mb-5 p-0" style="background-image: url(../img/carousel-1.jpg);">
            <div class="container-fluid page-header-inner py-5">
                <div class="container text-center pb-5">
                    <h1 class="display-3 text-white mb-3 animated slideInDown"><?= te('Hotel') ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-center text-uppercase">
                            <li class="breadcrumb-item"><a href="#"><?= te('Beranda') ?></a></li>
                            <li class="breadcrumb-item text-white active" aria-current="page"><?= te('Hotel') ?></li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
        <!-- Page Header End -->

        <!-- Booking Start -->
        <div class="container">
            <div class="search-container">
                <!-- Input Tujuan -->
                <div class="mb-3">
                    <label class="form-label"><?= te('Kota dan tujuan') ?></label>
                    <input type="text" class="form-control" id="cityInput" placeholder="<?= te('Kota dan tujuan hotel') ?>" value="<?= $selectedCity ?>">
                </div>

                <!-- Dropdown Tujuan -->
                <div class="destination-dropdown shadow-lg" id="destinationDropdown" style="display: none;">
                    <div class="p-3">
                        <div class="mb-2 text-primary"><strong><?= te('Destinasi Populer (Jabodetabek)') ?></strong></div>

                        <?php foreach ($cities as $city): ?>
                            <div class="dest-item py-2 px-3"
                                onclick="selectDestination('<?= $city ?>')"
                                onmouseover="this.style.backgroundColor='#f8f9fa'"
                                onmouseout="this.style.backgroundColor='transparent'">
                                <div class="fw-bold"><?= $city ?></div>
                                <div class="text-muted small">
                                    Indonesia -
                                    <span class='badge bg-light text-primary ms-1'>
                                        <?= ($hotelsCountByCity[$city] ?? 0) ?> hotel
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- HISTORY -->
                    <div class="city-dropdown-wrapper">
                        <?php include __DIR__ . '/search_history.php'; ?>
                    </div>

                </div>


                <!-- Script Dropdown Tujuan -->
                <script>
                    const inputField = document.getElementById("cityInput");
                    const dropdown = document.getElementById("destinationDropdown");

                    inputField.addEventListener("focus", () => {
                        dropdown.style.display = "block";
                    });

                    document.addEventListener("click", (e) => {
                        if (!dropdown.contains(e.target) && e.target !== inputField) {
                            dropdown.style.display = "none";
                        }
                    });

                    function selectDestination(destination) {
                        inputField.value = destination;
                        dropdown.style.display = "none";
                        window.location.href = `hotel.php?city=${encodeURIComponent(destination)}`;
                        document.getElementById("destinationInput").value = city;
                        document.getElementById("destinationDropdown").style.display = "none";
                    }
                </script>

                <!-- Tanggal dan Durasi -->
                <div class="row">
                    <!-- Check-In -->
                    <div class="col-md-4 mb-3">
                        <label class="form-label"><?= te('Check-In:') ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-calendar"></i></span>
                            <input type="date" id="checkin" class="form-control">
                        </div>
                    </div>

                    <!-- Durasi -->
                    <div class="col-md-4 mb-3">
                        <label class="form-label"><?= te('Durasi:') ?></label>
                        <select id="durasi" class="form-select">
                            <option value="1" selected>1 <?= te('malam') ?></option>
                            <option value="2">2 <?= te('malam') ?></option>
                            <option value="3">3 <?= te('malam') ?></option>
                            <option value="4">4 <?= te('malam') ?></option>
                            <option value="5">5 <?= te('malam') ?></option>
                            <option value="6">6 <?= te('malam') ?></option>
                            <option value="7">7 <?= te('malam') ?></option>
                        </select>
                    </div>

                    <!-- Check-Out (otomatis) -->
                    <div class="col-md-4 mb-3">
                        <label class="form-label"><?= te('Check-Out:') ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-calendar"></i></span>
                            <input type="text" id="checkout" class="form-control" readonly>
                        </div>
                    </div>
                </div>

                <script>
                    function updateCheckout() {
                        const checkin = document.getElementById('checkin').value;
                        const durasi = parseInt(document.getElementById('durasi').value);
                        const checkoutInput = document.getElementById('checkout');

                        if (checkin && durasi) {
                            const checkinDate = new Date(checkin);
                            checkinDate.setDate(checkinDate.getDate() + durasi);
                            const options = {
                                weekday: 'short',
                                day: '2-digit',
                                month: 'short',
                                year: 'numeric'
                            };
                            const formatted = checkinDate.toLocaleDateString('id-ID', options);
                            checkoutInput.value = formatted;
                        } else {
                            checkoutInput.value = '';
                        }
                    }

                    document.getElementById('checkin').addEventListener('change', updateCheckout);
                    document.getElementById('durasi').addEventListener('change', updateCheckout);
                </script>

                <!-- Tamu dan Kamar -->
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?= te('Tamu dan Kamar:') ?></label>
                    <div class="dropdown">
                        <button class="form-control text-start dropdown-toggle" type="button" id="guestToggle"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            <span id="guestSummary">2 <?= t('Dewasa') ?>, 0 <?= t('Anak') ?>, 1 <?= t('Kamar') ?></span>
                        </button>
                        <ul class="dropdown-menu p-3" aria-labelledby="guestToggle" style="min-width: 250px;">
                            <li class="mb-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div><i class="fas fa-user"></i> <?= te('Dewasa') ?></div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-secondary"
                                            onclick="adjustGuest('adult', -1)">–</button>
                                        <span id="adultCount" class="mx-2">2</span>
                                        <button class="btn btn-sm btn-outline-secondary"
                                            onclick="adjustGuest('adult', 1)">+</button>
                                    </div>
                                </div>
                            </li>
                            <li class="mb-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div><i class="fas fa-child"></i> <?= te('Anak') ?></div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-secondary"
                                            onclick="adjustGuest('child', -1)">–</button>
                                        <span id="childCount" class="mx-2">0</span>
                                        <button class="btn btn-sm btn-outline-secondary"
                                            onclick="adjustGuest('child', 1)">+</button>
                                    </div>
                                </div>
                            </li>
                            <li class="mb-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div><i class="fas fa-door-open"></i> <?= te('Kamar') ?></div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-secondary"
                                            onclick="adjustGuest('room', -1)">–</button>
                                        <span id="roomCount" class="mx-2">1</span>
                                        <button class="btn btn-sm btn-outline-secondary"
                                            onclick="adjustGuest('room', 1)">+</button>
                                    </div>
                                </div>
                            </li>
                            <li class="text-end">
                                <button class="btn btn-primary btn-sm mt-2"
                                    onclick="closeGuestDropdown()"><?= tv_lang() === 'en' ? 'Done' : 'Selesai' ?></button>
                            </li>
                        </ul>
                    </div>
                </div>

                <script>
                    let guestData = {
                        adult: 2,
                        child: 0,
                        room: 1
                    };

                    function adjustGuest(type, delta) {
                        guestData[type] = Math.max(0, guestData[type] + delta);
                        if (type === "adult" && guestData.adult < 1) guestData.adult = 1;
                        if (type === "room" && guestData.room < 1) guestData.room = 1;

                        document.getElementById("adultCount").innerText = guestData.adult;
                        document.getElementById("childCount").innerText = guestData.child;
                        document.getElementById("roomCount").innerText = guestData.room;
                        updateGuestSummary();
                    }

                    function updateGuestSummary() {
                        const summary = `${guestData.adult} <?= t('Dewasa') ?>, ${guestData.child} <?= t('Anak') ?>, ${guestData.room} <?= t('Kamar') ?>`;
                        document.getElementById("guestSummary").innerText = summary;
                    }

                    function closeGuestDropdown() {
                        const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('guestToggle'));
                        dropdown.hide();
                    }

                    document.addEventListener("DOMContentLoaded", updateGuestSummary);
                </script>

                <!-- Tombol Pencarian -->
                <button class="btn btn-search w-100" onclick="cariHotel()">
                    <i class="fa fa-search"></i> <?= te('Cari Hotel') ?>
                </button>
                <script>
                    function cariHotel() {
                        const tujuan = document.getElementById("cityInput").value.trim();
                        const checkin = document.getElementById("checkin").value;
                        const durasi = parseInt(document.getElementById("durasi").value);

                        let checkout = '';
                        if (checkin && durasi) {
                            const checkinDate = new Date(checkin);
                            checkinDate.setDate(checkinDate.getDate() + durasi);
                            const year = checkinDate.getFullYear();
                            const month = String(checkinDate.getMonth() + 1).padStart(2, '0');
                            const day = String(checkinDate.getDate()).padStart(2, '0');
                            checkout = `${year}-${month}-${day}`;
                        }

                        const params = new URLSearchParams({
                            tujuan: tujuan,
                            checkin: checkin,
                            checkout: checkout,
                            durasi: durasi,
                            dewasa: guestData.adult,
                            anak: guestData.child,
                            kamar: guestData.room
                        });

                        window.location.href = `hotel_results.php?${params.toString()}`;
                    }
                </script>
            </div>
        </div>

        <!-- Hotel Recommendations Section -->
        <div class="container-fluid py-5">
            <div class="container">
                <div class="text-center wow fadeInUp" data-wow-delay="0.1s">
                    <h6 class="section-title text-center text-primary text-uppercase"><?= te('Hotel') ?></h6>
                    <h2 class="mb-5"><?= te('Rekomendasi') ?> <span class="text-primary text-uppercase"><?= te('Hotel') ?> <?= $selectedCity ?></span></h2>
                </div>

                <!-- City Filter Buttons -->
                <div class="city-filter text-center mb-5">
                    <?php foreach ($cities as $city): ?>
                        <a href="hotel.php?city=<?= urlencode($city) ?>"
                            class="btn btn-outline-primary city-btn <?= ($selectedCity == $city) ? 'active' : '' ?>">
                            <?= $city ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Hotels Grid -->
                <div class="row row-cols-1 row-cols-md-3 g-4 tv-stagger">
                    <?php if (count($topHotels) > 0): ?>
                        <?php foreach ($topHotels as $hotel): ?>
                            <div class="col tv-reveal">
                                <div class="card hotel-card h-100">
                                    <img src="../img/<?= $hotel['foto_hotel'] ?>" class="card-img-top hotel-img" alt="<?= $hotel['nama_hotel'] ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h5 class="card-title mb-0"><?= $hotel['nama_hotel'] ?></h5>
                                        </div>
                                        <p class="text-muted">
                                            <i class="fas fa-map-marker-alt text-secondary me-1"></i>
                                            <?= $hotel['alamat'] ?>
                                        </p>

                                        <!-- Facilities (simplified for this view) -->
                                        <div class="mb-3">
                                            <span class="badge bg-light text-dark me-1 mb-1">
                                                <i class="fas fa-wifi facility-icon"></i> Wi-Fi
                                            </span>
                                            <span class="badge bg-light text-dark me-1 mb-1">
                                                <i class="fas fa-utensils facility-icon"></i> <?= te('Restoran') ?>
                                            </span>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center mt-auto">
                                            <div>
                                                <span class="price">Rp <?= number_format($hotel['harga_dasar'], 0, ',', '.') ?></span>
                                                <small class="text-muted d-block"><?= te('per malam') ?></small>
                                            </div>
                                            <a href="hotel_detail.php?id=<?= $hotel['hotel_id'] ?>" class="btn btn-primary">
                                                <?= te('Lihat Detail') ?>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="alert alert-info"><?= te('Tidak ada hotel yang tersedia di kota ini.') ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Other Cities Section -->
        <div class="container-fluid py-5">
            <div class="container">
                <div class="text-center wow fadeInUp" data-wow-delay="0.1s">
                    <h6 class="section-title text-center text-primary text-uppercase"><?= te('Hotel Lainnya') ?></h6>
                    <h2 class="mb-5"><?= te('Rekomendasi') ?> <span class="text-primary text-uppercase"><?= te('Hotel di Kota Lain') ?></span></h2>
                </div>

                <div class="row tv-stagger">
                    <?php foreach ($cities as $city): ?>
                        <?php if ($city != $selectedCity): ?>
                            <div class="col-md-4 mb-4 tv-reveal">
                                <div class="card h-100 tv-card tv-card-hover">
                                    <div class="card-body">
                                        <h5 class="card-title text-center">
                                            <i class="fas fa-map-marker-alt text-primary me-2"></i>
                                            <?= $city ?>
                                        </h5>
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($allHotels[$city] as $index => $hotel): ?>
                                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong><?= $index + 1 ?>. <?= $hotel['nama_hotel'] ?></strong>
                                                        <div class="text-muted small">Rp <?= number_format($hotel['harga_dasar'], 0, ',', '.') ?>/malam</div>
                                                    </div>
                                                    <a href="hotel_detail.php?id=<?= $hotel['hotel_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <?= te('Lihat') ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <a href="hotel.php?city=<?= urlencode($city) ?>" class="btn btn-primary mt-2 w-100">
                                            <?= te('Lihat Semua di') ?> <?= $city ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        
        <!-- Footer Start -->
        <div class="container-fluid bg-dark text-light footer wow fadeIn" data-wow-delay="0.1s">
            <div class="container pb-5">
                <div class="row g-5">
                    <!-- Logo & Brand -->
                    <div class="col-md-6 col-lg-4">
                        <div class="bg-primary rounded p-4 d-flex align-items-center">
                            <a href="home.php">
                                <img src="../img/logo.png" alt="TripVerse Logo" width="50" class="me-3">
                            </a>
                            <a href="home.php">
                                <span class="tv-wordmark tv-wordmark-footer">TripVerse</span>
                            </a>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="col-md-6 col-lg-3">
                        <h6 class="section-title text-start text-primary text-uppercase mb-4">Contact</h6>
                        <p class="mb-2"><i class="fa fa-map-marker-alt me-3"></i>Jl. Wisata No. 45, Jakarta, Indonesia</p>
                        <p class="mb-2"><i class="fa fa-phone-alt me-3"></i>+62 878 0677 6235</p>
                        <p class="mb-2"><i class="fa fa-envelope me-3"></i>tripverse@gmail.com</p>
                        <div class="d-flex pt-2">
                            <a class="btn btn-outline-light btn-social mx-1" href="https://twitter.com/"><i class="fab fa-twitter"></i></a>
                            <a class="btn btn-outline-light btn-social mx-1" href="https://facebook.com/"><i class="fab fa-facebook-f"></i></a>
                            <a class="btn btn-outline-light btn-social mx-1" href="https://youtube.com/"><i class="fab fa-youtube"></i></a>
                            <a class="btn btn-outline-light btn-social mx-1" href="https://linkedin.com/company/"><i class="fab fa-linkedin-in"></i></a>
                            <a class="btn btn-outline-light btn-social mx-1" href="https://instagram.com/"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>

                    <!-- Company & Services -->
                    <div class="col-lg-5 col-md-12">
                        <div class="row gy-5 g-4">
                            <div class="col-md-6">
                                <h6 class="section-title text-start text-primary text-uppercase mb-4">Company</h6>
                                <a class="btn btn-link" href="#">About TripVerse</a>
                                <a class="btn btn-link" href="#">Contact Us</a>
                                <a class="btn btn-link" href="#">Privacy Policy</a>
                                <a class="btn btn-link" href="#">Terms & Conditions</a>
                                <a class="btn btn-link" href="#">Support</a>
                            </div>
                            <div class="col-md-6">
                                <h6 class="section-title text-start text-primary text-uppercase mb-4">Services</h6>
                                <a class="btn btn-link" href="#">Hotel Booking</a>
                                <a class="btn btn-link" href="#">Event & Activities</a>
                                <a class="btn btn-link" href="#">Spa & Wellness</a>
                                <a class="btn btn-link" href="#">Travel Insurance</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Copyright Section -->
            <div class="container">
                <div class="copyright">
                    <div class="row">
                        <div class="col-md-12 text-end">
                            <div class="footer-menu">
                                <a href="#">Home</a>
                                <a href="#">Cookies</a>
                                <a href="#">Help</a>
                                <a href="#">FAQs</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Footer End -->
    </div>

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
    <script src="../js/main.js?v=2.0"></script>
    <script src="../js/tv-modern.js?v=<?= @filemtime(__DIR__ . '/../js/tv-modern.js') ?>"></script>

    <script>
        window.addEventListener('load', function() {
            const spinner = document.getElementById('spinner');
            if (spinner) {
                spinner.classList.remove('show');
            }
        });

        // Fungsi untuk menghapus riwayat hotel
        function clearHotelHistory() {
            if (confirm('<?= t('Apakah Anda yakin ingin menghapus semua riwayat hotel?') ?>')) {
                window.location.href = 'hotel.php?clear_hotel_history=1';
            }
        }

        // Tambahkan di DOMContentLoaded atau di bagian JavaScript yang ada
        document.addEventListener('DOMContentLoaded', function() {
            // Handle clear hotel history via GET parameter
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('clear_hotel_history')) {
                // Logic untuk clear hotel history (akan dihandle di PHP bagian atas)
            }
        });
    </script>



</body>

</html>

<?php $conn->close(); ?>