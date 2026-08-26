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

// Get and sanitize search parameters
$tujuan = isset($_GET['tujuan']) ? $conn->real_escape_string($_GET['tujuan']) : 'Jakarta';
$checkin = isset($_GET['checkin']) ? $_GET['checkin'] : date('Y-m-d');
$checkout = isset($_GET['checkout']) ? $_GET['checkout'] : date('Y-m-d', strtotime('+1 day'));
$durasi = isset($_GET['durasi']) ? intval($_GET['durasi']) : 1;
$dewasa = isset($_GET['dewasa']) ? intval($_GET['dewasa']) : 2;
$anak = isset($_GET['anak']) ? intval($_GET['anak']) : 0;
$kamar = isset($_GET['kamar']) ? intval($_GET['kamar']) : 1;

// Validate dates
if (!strtotime($checkin) || !strtotime($checkout)) {
    $checkin = date('Y-m-d');
    $checkout = date('Y-m-d', strtotime('+1 day'));
}

// Format dates for display
$checkinDisplay = date('d M Y', strtotime($checkin));
$checkoutDisplay = date('d M Y', strtotime($checkout));

// Get all hotels count by city
$sqlAllHotels = "SELECT kota, COUNT(*) as jumlah FROM hotel GROUP BY kota";
$resultAllHotels = $conn->query($sqlAllHotels);
$hotelsCountByCity = [];
while ($row = $resultAllHotels->fetch_assoc()) {
    $hotelsCountByCity[$row['kota']] = $row['jumlah'];
}

// Get hotels for the selected destination using prepared statement
$sql = "SELECT * FROM hotel WHERE kota = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error in prepared statement: " . $conn->error);
}
$stmt->bind_param("s", $tujuan);
$stmt->execute();
$result = $stmt->get_result();
$hotels = $result->fetch_all(MYSQLI_ASSOC);

// Get cities for filter
$cities = ['Jakarta', 'Bogor', 'Depok', 'Tangerang', 'Bekasi'];

// Close connection
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>TripVerse - <?= te('Hasil Pencarian Hotel') ?></title>
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

    <style>
        :root {
            --primary-color: #FF6B00;
            --primary-hover: #E05D00;
            --secondary-color: #0066CC;
            --dark-color: #2A2D34;
            --light-color: #F8F9FA;
            --accent-color: #FFD166;
            --text-color: #333333;
            --text-light: #6C757D;
            --border-color: #E0E0E0;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.12);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.15);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        body {
            font-family: 'Montserrat', sans-serif;
            color: var(--text-color);
            background-color: #F5F7FA;
            line-height: 1.6;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-weight: 700;
            color: var(--dark-color);
        }

        .navbar {
            box-shadow: var(--shadow-sm);
        }

        .navbar-brand .text-primary {
            color: var(--primary-color) !important;
        }

        .nav-item .nav-link.active {
            color: var(--primary-color) !important;
            font-weight: 600;
        }

        .search-box {
            background: white;
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-md);
            margin-top: -80px;
            position: relative;
            z-index: 10;
            border: 1px solid var(--border-color);
        }

        .search-box form {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 16px;
        }

        .search-box input,
        .search-box .dropdown-toggle {
            height: 60px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0 20px;
            font-size: 16px;
            transition: var(--transition);
        }

        .search-box input:focus,
        .search-box .dropdown-toggle:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(255, 107, 0, 0.25);
        }

        .search-box .btn-primary {
            height: 60px;
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            font-size: 18px;
            font-weight: 600;
            transition: var(--transition);
        }

        .search-box .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            transform: translateY(-2px);
        }

        .search-summary {
            background-color: white;
            border-radius: var(--radius-md);
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary-color);
        }

        .search-summary h5 {
            color: var(--primary-color);
            margin-bottom: 16px;
        }

        .sidebar-box {
            background-color: white;
            border-radius: var(--radius-md);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
        }

        .sidebar-box h5 {
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .form-range::-webkit-slider-thumb {
            background: var(--primary-color);
        }

        .form-range::-moz-range-thumb {
            background: var(--primary-color);
        }

        .form-range::-ms-thumb {
            background: var(--primary-color);
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-outline-secondary {
            border-color: var(--border-color);
            color: var(--text-color);
        }

        .btn-outline-secondary:hover {
            background-color: var(--light-color);
            border-color: var(--border-color);
        }

        /* Improved Hotel Cards Section */
        .hotels-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }

        .hotel-card {
            transition: var(--transition);
            border: none;
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            background: white;
            display: flex;
            flex-direction: column;
            height: 100%;
            animation: fadeIn 0.5s ease forwards;
        }

        .hotel-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .hotel-img-container {
            height: 220px;
            overflow: hidden;
        }

        .hotel-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .hotel-card:hover .hotel-img {
            transform: scale(1.05);
        }

        .card-body {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--dark-color);
        }

        .card-location {
            color: var(--text-light);
            font-size: 14px;
            margin-bottom: 12px;
        }

        .card-location i {
            color: var(--primary-color);
            margin-right: 6px;
        }

        .facilities-container {
            margin: 12px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .facility-badge {
            background-color: var(--light-color);
            color: var(--dark-color);
            padding: 6px 10px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            display: inline-flex;
            align-items: center;
        }

        .facility-icon {
            color: var(--primary-color);
            margin-right: 5px;
            font-size: 12px;
        }

        .card-footer {
            margin-top: auto;
            background: transparent;
            border-top: none;
            padding: 0;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .price-container {
            display: flex;
            flex-direction: column;
        }

        .price {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-color);
        }

        .price-per-night {
            font-size: 12px;
            color: var(--text-light);
        }

        .btn-select-room {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            transition: var(--transition);
            white-space: nowrap;
        }

        .btn-select-room:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
            color: white;
        }

        .no-results {
            text-align: center;
            padding: 60px 20px;
            background-color: white;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            grid-column: 1 / -1;
        }

        .no-results i {
            font-size: 48px;
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .footer {
            background-color: var(--dark-color) !important;
        }

        .bg-primary {
            background-color: var(--primary-color) !important;
        }

        @media (max-width: 992px) {
            .search-box form {
                grid-template-columns: 1fr 1fr;
            }

            .hotels-container {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 20px;
            }
        }

        @media (max-width: 768px) {
            .search-box form {
                grid-template-columns: 1fr;
            }

            .hotel-card {
                margin-bottom: 20px;
            }

            .search-summary,
            .sidebar-box {
                padding: 16px;
            }

            .hotels-container {
                grid-template-columns: 1fr;
            }

            .hotel-img-container {
                height: 200px;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        #spinner {
            background-color: rgba(255, 255, 255, 0.9);
        }

        .spinner-border.text-primary {
            color: var(--primary-color) !important;
        }

        .destination-dropdown {
            position: absolute;
            width: 350px;
            max-height: 400px;
            overflow-y: auto;
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            z-index: 1000;
            margin-top: 8px;
            box-shadow: var(--shadow-lg);
            display: none;
            padding: 0;
        }

        .dest-item {
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 4px;
            padding: 8px 12px;
        }

        .dest-item:hover {
            background-color: #f8f9fa;
        }

        .dest-item:active {
            background-color: #e9ecef;
        }

        .dest-item>div:first-child {
            font-weight: 600;
            color: var(--dark-color);
        }

        .dest-item .text-muted {
            font-size: 12px;
        }

        .dropdown-menu {
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border-color);
            padding: 12px;
        }

        .dropdown-item {
            padding: 8px 12px;
            border-radius: var(--radius-sm);
        }

        input[type="range"] {
            height: 8px;
            background: var(--light-color);
            border-radius: 4px;
        }

        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            background: var(--primary-color);
            border-radius: 50%;
            cursor: pointer;
        }

        .page-header {
            position: relative;
            background-size: cover;
            background-position: center;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to right, rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.3));
        }

        .page-header-inner {
            position: relative;
            z-index: 1;
        }

        .breadcrumb {
            background-color: transparent;
            justify-content: center;
        }

        .breadcrumb-item a {
            color: rgba(255, 255, 255, 0.8);
        }

        .breadcrumb-item.active {
            color: white;
        }

        .breadcrumb-item+.breadcrumb-item::before {
            color: rgba(255, 255, 255, 0.5);
        }

        .footer {
            margin-top: auto;
            background-color: #0b1120;
            color: #eee;
            font-size: 14px;
            padding: 1.5rem 0;
        }

        .footer .section-title {
            font-weight: bold;
            font-size: 16px;
            position: relative;
            display: inline-block;
            margin-bottom: 15px;
        }

        .footer .btn-link {
            color: #eee;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 5px;
        }

        .footer .btn-link:hover {
            text-decoration: underline;
            color: #f5b70a;
        }

        .footer .btn-social {
            width: 30px;
            height: 30px;
            line-height: 30px;
            font-size: 14px;
            border-radius: 50%;
            background-color: #222;
            color: #eee;
            text-align: center;
            margin-right: 8px;
            display: inline-block;
        }

        .footer .btn-social:hover {
            background-color: #f5b70a;
            color: #000;
        }

        .footer-menu a {
            color: #eee;
            margin: 0 8px;
            text-decoration: none;
        }

        .footer-menu a:hover {
            text-decoration: underline;
            color: #f5b70a;
        }

        .footer hr {
            border-color: rgba(255, 255, 255, 0.1);
        }

        @media (max-width: 768px) {
            .footer .row {
                flex-direction: column;
                text-align: center;
                align-items: center;
            }

            .footer .btn-social {
                margin-bottom: 8px;
            }

            .footer .section-title::after {
                display: none;
            }
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

        <!-- Header -->
        <header class="container-fluid bg-dark px-0">
            <div class="row gx-0">
                <div class="col-lg-3 bg-dark d-none d-lg-flex align-items-center justify-content-center">
                    <a href="about.php" class="d-flex align-items-center text-decoration-none">
                        <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 50px;">
                        <span class="tv-wordmark tv-wordmark-header">TripVerse</span>
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
                                <a class="me-3" href="#"><i class="fab fa-facebook-f"></i></a>
                                <a class="me-3" href="#"><i class="fab fa-twitter"></i></a>
                                <a class="me-3" href="#"><i class="fab fa-linkedin-in"></i></a>
                                <a class="me-3" href="#"><i class="fab fa-instagram"></i></a>
                                <a class="" href="#"><i class="fab fa-youtube"></i></a>
                            </div>
                        </div>
                    </div>

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
                                <a href="home.php" class="nav-item nav-link"><?= te('Beranda') ?></a>
                                <a href="about.php" class="nav-item nav-link"><?= te('Tentang Kami') ?></a>
                                <a href="hotel.php" class="nav-item nav-link active"><?= te('Hotel') ?></a>
                                <a href="flights.php" class="nav-item nav-link"><?= te("Pesawat") ?></a>
                                <a href="service.php" class="nav-item nav-link"><?= te('Fitur') ?></a>
                                <a href="team.php" class="nav-item nav-link"><?= te('Tim Kami') ?></a>
                                <a href="contact.php" class="nav-item nav-link"><?= te('Kontak') ?></a>
                            </div>
                            <?php include __DIR__ . '/_lang_switch.php'; ?>
                            <?php if (isset($_SESSION['username'])): ?>
                                <span class="navbar-text fw-bold me-3"
                                    style="background: linear-gradient(to right, #FFA500, #FF6347);
                                    -webkit-background-clip: text; background-clip: text;
                                    -webkit-text-fill-color: transparent; text-decoration: underline;">
                                    Hi <?= htmlspecialchars($_SESSION['username']); ?>, <?= te('selamat datang di TripVerse') ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </nav>
                </div>
            </div>
        </header>

        <!-- Page Header Start -->
        <div class="container-fluid page-header mb-5 p-0" style="background-image: url(../img/carousel-1.jpg);">
            <div class="container-fluid page-header-inner py-5">
                <div class="container text-center pb-5">
                    <h1 class="display-3 text-white mb-3 animated slideInDown"><?= te('Hasil Pencarian Hotel') ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-center text-uppercase">
                            <li class="breadcrumb-item"><a href="home.php"><?= te('Beranda') ?></a></li>
                            <li class="breadcrumb-item"><a href="hotel.php"><?= te('Hotel') ?></a></li>
                            <li class="breadcrumb-item text-white active" aria-current="page"><?= te('Hasil Pencarian') ?></li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
        <!-- Page Header End -->

        <!-- Search Box -->
        <div class="container">
            <div class="search-box">
                <form id="searchForm">
                    <!-- Input Group dengan Position Relative untuk dropdown -->
                    <div class="position-relative">
                        <input type="text"
                            class="form-control"
                            placeholder="<?= te('Kota dan tujuan hotel') ?>"
                            id="searchLocation"
                            value="<?= htmlspecialchars($tujuan) ?>"
                            oninput="capitalizeInput(this)"
                            autocomplete="off"
                            aria-expanded="false"
                            aria-haspopup="true">

                        <!-- Dropdown Destinasi -->
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
                        </div>
                    </div>
                    <input type="date" id="checkIn" value="<?= $checkin ?>" onchange="updateCheckoutDate()">
                    <input type="date" id="checkOut" value="<?= $checkout ?>" readonly>

                    <div class="dropdown">
                        <button class="form-control text-start dropdown-toggle" type="button" id="guestToggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <span id="guestSummary"><?= $dewasa ?> <?= t('Dewasa') ?>, <?= $anak ?> <?= t('Anak') ?>, <?= $kamar ?> <?= te('Kamar') ?></span>
                        </button>
                        <ul class="dropdown-menu p-3" aria-labelledby="guestToggle" style="min-width: 250px;">
                            <li class="mb-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div><i class="fas fa-user"></i> <?= te('Dewasa') ?></div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="adjustGuest('adult', -1)">–</button>
                                        <span id="adultCount" class="mx-2"><?= $dewasa ?></span>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="adjustGuest('adult', 1)">+</button>
                                    </div>
                                </div>
                            </li>
                            <li class="mb-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div><i class="fas fa-child"></i> <?= te('Anak') ?></div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="adjustGuest('child', -1)">–</button>
                                        <span id="childCount" class="mx-2"><?= $anak ?></span>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="adjustGuest('child', 1)">+</button>
                                    </div>
                                </div>
                            </li>
                            <li class="mb-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div><i class="fas fa-door-open"></i> <?= te('Kamar') ?></div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="adjustGuest('room', -1)">–</button>
                                        <span id="roomCount" class="mx-2"><?= $kamar ?></span>
                                        <button class="btn btn-sm btn-outline-secondary" type="button" onclick="adjustGuest('room', 1)">+</button>
                                    </div>
                                </div>
                            </li>
                            <li class="text-end">
                                <button class="btn btn-primary btn-sm mt-2" type="button" onclick="closeGuestDropdown()"><?= tv_lang() === 'en' ? 'Done' : 'Selesai' ?></button>
                            </li>
                        </ul>
                    </div>

                    <button type="button" class="btn btn-primary" style="height: 60px; font-size: 18px;" onclick="cariHotel()">
                        <i class="fa fa-search me-2"></i><?= te('Cari Hotel') ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Search Results Start -->
        <div class="container-fluid py-5">
            <div class="container">
                <!-- Search Summary -->
                <div class="search-summary">
                    <h5 class="fw-bold mb-4"><?= te('Hasil Pencarian Hotel di') ?> <?= htmlspecialchars($tujuan) ?></h5>
                    <div class="row g-3">
                        <div class="col-lg-3 col-md-6">
                            <div class="d-flex align-items-center summary-item">
                                <i class="far fa-calendar-alt me-3 text-primary"></i>
                                <div>
                                    <div class="text-muted small"><?= te('Check-In:') ?></div>
                                    <div class="fw-medium"><?= $checkinDisplay ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="d-flex align-items-center summary-item">
                                <i class="far fa-calendar-alt me-3 text-primary"></i>
                                <div>
                                    <div class="text-muted small"><?= te('Check-Out:') ?></div>
                                    <div class="fw-medium"><?= $checkoutDisplay ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="d-flex align-items-center summary-item">
                                <i class="far fa-clock me-3 text-primary"></i>
                                <div>
                                    <div class="text-muted small"><?= te('Durasi:') ?></div>
                                    <div class="fw-medium"><?= $durasi ?> <?= t('malam') ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <div class="d-flex align-items-center summary-item">
                                <i class="fas fa-users me-3 text-primary"></i>
                                <div>
                                    <div class="text-muted small"><?= te('Tamu:') ?></div>
                                    <div class="fw-medium"><?= $dewasa ?> <?= t('Dewasa') ?>, <?= $anak ?> <?= t('Anak') ?>, <?= $kamar ?> <?= te('Kamar') ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Sidebar Filter -->
                    <div class="col-md-3">
                        <div class="sidebar-box">
                            <h5 class="fw-bold mb-3"><?= te('Filter Hotel') ?></h5>

                            <!-- Harga Maksimal -->
                            <div class="mb-4">
                                <label class="form-label fw-bold"><?= te('Harga Maksimal') ?></label>
                                <input type="range" class="form-range" id="hargaRange" min="400000" max="3000000" step="50000" value="3000000">
                                <div class="d-flex justify-content-between mt-2">
                                    <small>Rp 400rb</small>
                                    <small>Rp 3.0jt</small>
                                </div>
                                <div class="mt-2">
                                    <span class="badge bg-primary" style="color: white;"><?= te('Maks:') ?> Rp <span id="hargaValue">3.000.000</span></span>
                                </div>
                            </div>

                            <!-- Fasilitas -->
                            <div class="mb-4">
                                <label class="form-label fw-bold"><?= te('Fasilitas') ?></label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="wifi" id="wifi" checked>
                                    <label class="form-check-label" for="wifi">
                                        <i class="fas fa-wifi text-primary me-2"></i><?= te('Wi-Fi Gratis') ?>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="pool" id="pool">
                                    <label class="form-check-label" for="pool">
                                        <i class="fas fa-swimming-pool text-primary me-2"></i><?= te('Kolam Renang') ?>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="restaurant" id="restaurant" checked>
                                    <label class="form-check-label" for="restaurant">
                                        <i class="fas fa-utensils text-primary me-2"></i><?= te('Restoran') ?>
                                    </label>
                                </div>
                            </div>

                            <button class="btn btn-primary w-100 mb-2" id="applyFilter"><?= te('Terapkan Filter') ?></button>
                            <button class="btn btn-outline-secondary w-100" id="resetFilter"><?= te('Reset Filter') ?></button>
                        </div>
                    </div>

                    <!-- Konten Hotel -->
                    <div class="col-md-9">
                        <?php if (count($hotels) > 0): ?>
                            <div class="hotels-container" id="hotelsContainer">
                                <?php foreach ($hotels as $index => $hotel): ?>
                                    <div class="hotel-card" style="--order: <?= $index ?>"
                                        data-harga="<?= $hotel['harga_dasar'] ?>"
                                        data-fasilitas="wifi,restaurant<?= rand(0, 1) ? ',pool' : '' ?>">
                                        <div class="hotel-img-container">
                                            <?php
                                            $hotel_img = '../img/default-hotel.svg';
                                            if (!empty($hotel['foto_hotel'])) {
                                                $img_file = __DIR__ . '/../img/' . $hotel['foto_hotel'];
                                                if (file_exists($img_file)) {
                                                    $hotel_img = '../img/' . htmlspecialchars($hotel['foto_hotel']);
                                                }
                                            }
                                            ?>
                                            <img src="<?= $hotel_img ?>"
                                                class="hotel-img"
                                                alt="<?= htmlspecialchars($hotel['nama_hotel']) ?>">
                                        </div>
                                        <div class="card-body">
                                            <h5 class="card-title"><?= htmlspecialchars($hotel['nama_hotel']) ?></h5>
                                            <p class="card-location">
                                                <i class="fas fa-map-marker-alt"></i>
                                                <?= htmlspecialchars($hotel['alamat']) ?>
                                            </p>

                                            <div class="facilities-container">
                                                <span class="facility-badge">
                                                    <i class="fas fa-wifi facility-icon"></i> Wi-Fi
                                                </span>
                                                <span class="facility-badge">
                                                    <i class="fas fa-utensils facility-icon"></i> <?= te('Restoran') ?>
                                                </span>
                                                <?php if (rand(0, 1)): ?>
                                                    <span class="facility-badge">
                                                        <i class="fas fa-swimming-pool facility-icon"></i> <?= te('Kolam Renang') ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="card-footer">
                                                <div class="price-container">
                                                    <span class="price">Rp <?= number_format($hotel['harga_dasar'], 0, ',', '.') ?></span>
                                                    <span class="price-per-night"><?= te('per malam') ?></span>
                                                </div>
                                                <a href="hotel_detail.php?id=<?= $hotel['hotel_id'] ?>&checkin=<?= $checkin ?>&checkout=<?= $checkout ?>&dewasa=<?= $dewasa ?>&anak=<?= $anak ?>&kamar=<?= $kamar ?>"
                                                    class="btn-select-room">
                                                    <?= te('Pilih Kamar') ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-results">
                                <i class="fas fa-hotel fa-4x mb-3"></i>
                                <h4><?= te('Maaf, tidak ada hotel yang tersedia di') ?> <?= htmlspecialchars($tujuan) ?></h4>
                                <p><?= te('Coba cari dengan kriteria yang berbeda atau pilih kota lain.') ?></p>
                                <a href="hotel.php" class="btn btn-primary"><?= te('Kembali ke Pencarian Hotel') ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- Search Results End -->

        <!-- Footer -->
        <footer class="footer">
            <div class="container-fluid bg-dark text-light footer wow fadeIn" data-wow-delay="0.1s">
                <div class="container pb-5">
                    <div class="row g-5">
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

                        <div class="col-md-6 col-lg-3">
                            <h6 class="section-title text-start text-primary text-uppercase mb-4">Contact</h6>
                            <p class="mb-2"><i class="fa fa-map-marker-alt me-3"></i>Jl. Wisata No. 45, Jakarta, Indonesia</p>
                            <p class="mb-2"><i class="fa fa-phone-alt me-3"></i>+62 878 0677 6235</p>
                            <p class="mb-2"><i class="fa fa-envelope me-3"></i>tripverse@gmail.com</p>
                            <div class="d-flex pt-2">
                                <a class="btn btn-outline-light btn-social mx-1" href="#"><i class="fab fa-twitter"></i></a>
                                <a class="btn btn-outline-light btn-social mx-1" href="#"><i class="fab fa-facebook-f"></i></a>
                                <a class="btn btn-outline-light btn-social mx-1" href="#"><i class="fab fa-youtube"></i></a>
                                <a class="btn btn-outline-light btn-social mx-1" href="#"><i class="fab fa-linkedin-in"></i></a>
                                <a class="btn btn-outline-light btn-social mx-1" href="#"><i class="fab fa-instagram"></i></a>
                            </div>
                        </div>

                        <div class="col-lg-5 col-md-12">
                            <div class="row gy-5 g-4">
                                <div class="col-md-6">
                                    <h6 class="section-title text-start text-primary text-uppercase mb-4">Company</h6>
                                    <a class="btn btn-link" href="#">About Us</a>
                                    <a class="btn btn-link" href="#">Contact Us</a>
                                    <a class="btn btn-link" href="#">Privacy Policy</a>
                                    <a class="btn btn-link" href="#">Terms & Condition</a>
                                    <a class="btn btn-link" href="#">Support</a>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="section-title text-start text-primary text-uppercase mb-4">Services</h6>
                                    <a class="btn btn-link" href="#">Food & Restaurant</a>
                                    <a class="btn btn-link" href="#">Spa & Fitness</a>
                                    <a class="btn btn-link" href="#">Sports & Gaming</a>
                                    <a class="btn btn-link" href="#">Event & Party</a>
                                    <a class="btn btn-link" href="#">GYM & Yoga</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="container">
                    <div class="copyright">
                        <div class="row">
                            <div class="col-md-6 text-center text-md-start mb-3 mb-md-0">
                                &copy; <a class="border-bottom" href="#">TripVerse</a>, All Right Reserved.
                            </div>
                            <div class="col-md-6 text-center text-md-end">
                                <div class="footer-menu">
                                    <a href="home.php">Home</a>
                                    <a href="#">Cookies</a>
                                    <a href="#">Help</a>
                                    <a href="#">FQAs</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
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

    <script>
        // Fungsi untuk pencarian hotel
        function cariHotel() {
            const tujuan = document.getElementById("searchLocation").value.trim();
            const checkin = document.getElementById("checkIn").value;
            const checkout = document.getElementById("checkOut").value;

            // Validasi input
            if (!tujuan) {
                alert('<?= t('Silakan masukkan destinasi') ?>');
                return;
            }
            if (!checkin || !checkout) {
                alert('<?= t('Silakan pilih tanggal check-in dan check-out') ?>');
                return;
            }

            const params = new URLSearchParams({
                tujuan: tujuan,
                checkin: checkin,
                checkout: checkout,
                durasi: guestData.durasi,
                dewasa: guestData.adult,
                anak: guestData.child,
                kamar: guestData.room
            });

            // Tampilkan spinner sebelum redirect
            const spinner = document.getElementById('spinner');
            if (spinner) spinner.classList.add('show');

            window.location.href = `hotel_results.php?${params.toString()}`;
        }

        // Fungsi untuk menangani dropdown
        document.addEventListener('DOMContentLoaded', function() {
            const inputField = document.getElementById("searchLocation");
            const dropdown = document.getElementById("destinationDropdown");

            // Tampilkan dropdown saat input difokus
            inputField.addEventListener("focus", function() {
                dropdown.style.display = "block";
                this.setAttribute('aria-expanded', 'true');
            });

            // Sembunyikan dropdown saat klik di luar
            document.addEventListener("click", function(e) {
                if (!inputField.contains(e.target)) {
                    dropdown.style.display = "none";
                    inputField.setAttribute('aria-expanded', 'false');
                }
            });

            // Fungsi pilih destinasi
            window.selectDestination = function(destination) {
                inputField.value = destination;
                dropdown.style.display = "none";
                inputField.setAttribute('aria-expanded', 'false');
                inputField.focus();
            };

            // Fungsi kapitalisasi input
            window.capitalizeInput = function(el) {
                const cursorPos = el.selectionStart;
                el.value = el.value
                    .toLowerCase()
                    .split(' ')
                    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                    .join(' ');
                el.setSelectionRange(cursorPos, cursorPos);

                // Tampilkan dropdown saat mengetik
                if (el.value.length > 0) {
                    dropdown.style.display = "block";
                    el.setAttribute('aria-expanded', 'true');
                } else {
                    dropdown.style.display = "none";
                    el.setAttribute('aria-expanded', 'false');
                }
            };
        });

        // Fungsi untuk tamu dan kamar
        const guestData = {
            adult: <?= $dewasa ?>,
            child: <?= $anak ?>,
            room: <?= $kamar ?>,
            durasi: <?= $durasi ?>
        };

        function adjustGuest(type, change) {
            const newValue = guestData[type] + change;

            // Validasi jumlah minimum
            if (type === "adult" && newValue < 1) return;
            if (type === "room" && newValue < 1) return;

            // Validasi jumlah maksimum
            if (newValue > 10) {
                alert('<?= t('Maksimal 10 untuk setiap kategori') ?>');
                return;
            }

            // Validasi kamar tidak boleh lebih dari dewasa
            if (type === "adult" && newValue < guestData.room) {
                alert('<?= t('Jumlah kamar tidak boleh lebih dari jumlah dewasa') ?>');
                return;
            }

            guestData[type] = newValue;
            document.getElementById(`${type}Count`).textContent = guestData[type];
            updateGuestSummary();

            // Update durasi jika mengubah jumlah malam
            if (type === "durasi") {
                updateCheckoutDate();
            }
        }

        function updateGuestSummary() {
            const summary = [];
            if (guestData.adult > 0) summary.push(`${guestData.adult} <?= t('Dewasa') ?>`);
            if (guestData.child > 0) summary.push(`${guestData.child} <?= t('Anak') ?>`);
            if (guestData.room > 0) summary.push(`${guestData.room} <?= t('Kamar') ?>`);

            document.getElementById("guestSummary").textContent = summary.join(", ") || "<?= t('Pilih Tamu') ?>";
        }

        // Fungsi untuk tanggal
        function updateCheckoutDate() {
            const checkinInput = document.getElementById("checkIn");
            const checkoutInput = document.getElementById("checkOut");

            if (checkinInput.value) {
                const date = new Date(checkinInput.value);
                date.setDate(date.getDate() + guestData.durasi);

                // Format tanggal ke YYYY-MM-DD
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');

                checkoutInput.value = `${year}-${month}-${day}`;

                // Validasi tanggal check-out tidak boleh sebelum check-in
                const checkinDate = new Date(checkinInput.value);
                if (date <= checkinDate) {
                    checkoutInput.value = "";
                    alert('<?= t('Tanggal check-out harus setelah check-in') ?>');
                }
            }
        }

        // Fungsi untuk filter harga
        document.addEventListener('DOMContentLoaded', function() {
            const hargaRange = document.getElementById('hargaRange');
            const hargaValue = document.getElementById('hargaValue');
            const applyFilter = document.getElementById('applyFilter');
            const resetFilter = document.getElementById('resetFilter');
            const hotelsContainer = document.getElementById('hotelsContainer');
            const originalCards = Array.from(hotelsContainer.querySelectorAll('.hotel-card'));

            // Format harga ke Rupiah
            function formatRupiah(amount) {
                return new Intl.NumberFormat('id-ID', {
                    maximumFractionDigits: 0
                }).format(amount);
            }

            // Update tampilan range harga
            if (hargaRange && hargaValue) {
                hargaRange.addEventListener('input', function() {
                    hargaValue.textContent = formatRupiah(this.value);
                });

                // Set nilai awal
                hargaValue.textContent = formatRupiah(hargaRange.value);
            }

            // Fungsi apply filter
            if (applyFilter) {
                applyFilter.addEventListener('click', function() {
                    const maxHarga = parseInt(hargaRange.value);
                    const wifiChecked = document.getElementById('wifi').checked;
                    const poolChecked = document.getElementById('pool').checked;
                    const restaurantChecked = document.getElementById('restaurant').checked;

                    const filtered = originalCards.filter(card => {
                        const harga = parseInt(card.dataset.harga);
                        const fasilitas = card.dataset.fasilitas ? card.dataset.fasilitas.toLowerCase() : '';

                        // Filter harga
                        if (harga > maxHarga) return false;

                        // Filter fasilitas
                        if (wifiChecked && !fasilitas.includes('wifi')) return false;
                        if (poolChecked && !card.querySelector('.fa-swimming-pool')) return false;
                        if (restaurantChecked && !fasilitas.includes('restaurant')) return false;

                        return true;
                    });

                    hotelsContainer.innerHTML = '';
                    if (filtered.length === 0) {
                        hotelsContainer.innerHTML = '<div class="col-12"><div class="alert alert-info"><?= t('Tidak ada hotel yang sesuai dengan filter') ?></div></div>';
                    } else {
                        filtered.forEach(card => hotelsContainer.appendChild(card.cloneNode(true)));
                    }
                });
            }

            // Fungsi reset filter
            if (resetFilter) {
                resetFilter.addEventListener('click', function() {
                    hargaRange.value = 1500000;
                    hargaValue.textContent = formatRupiah(1500000);

                    // Reset semua checkbox
                    document.getElementById('wifi').checked = true;
                    document.getElementById('pool').checked = false;
                    document.getElementById('restaurant').checked = true;

                    // Reset tampilan hotel
                    hotelsContainer.innerHTML = '';
                    originalCards.forEach(card => hotelsContainer.appendChild(card.cloneNode(true)));
                });
            }
        });
    </script>
</body>

</html>