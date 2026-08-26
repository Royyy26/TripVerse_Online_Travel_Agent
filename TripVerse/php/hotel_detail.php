<?php
session_start();
require_once __DIR__ . '/_lang.php';
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

// Database connection
require_once __DIR__ . '/db_config.php';

// Get hotel ID from URL
$hotel_id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$hotel_id) {
    header("Location: hotel.php");
    exit;
}

// Get hotel details
$sql = "SELECT * FROM hotel WHERE hotel_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $hotel_id);
$stmt->execute();
$result = $stmt->get_result();
$hotel = $result->fetch_assoc();

if (!$hotel) {
    header("Location: hotel.php");
    exit;
}

// Simpan hotel ke riwayat saat halaman detail diakses
if (!isset($_SESSION['hotel_history'])) {
    $_SESSION['hotel_history'] = [];
}

// Hapus hotel yang sama jika sudah ada
$_SESSION['hotel_history'] = array_filter($_SESSION['hotel_history'], function ($item) use ($hotel_id) {
    return $item['hotel_id'] !== $hotel_id;
});

// Tambahkan ke history hotel
array_unshift($_SESSION['hotel_history'], [
    "hotel_id" => $hotel['hotel_id'],
    "nama_hotel" => $hotel['nama_hotel'],
    "kota" => $hotel['kota'],
    "harga" => $hotel['harga_dasar'],
    "foto" => $hotel['foto_hotel'],
    "waktu" => date('Y-m-d H:i:s')
]);

// Batasi hanya 3 hotel terakhir
$_SESSION['hotel_history'] = array_slice($_SESSION['hotel_history'], 0, 3);

// Get search parameters
$checkin = isset($_GET['checkin']) ? $_GET['checkin'] : date('Y-m-d');
$checkout = isset($_GET['checkout']) ? $_GET['checkout'] : date('Y-m-d', strtotime('+1 day'));
$dewasa = isset($_GET['dewasa']) ? $_GET['dewasa'] : 2;
$anak = isset($_GET['anak']) ? $_GET['anak'] : 0;
$kamar = isset($_GET['kamar']) ? $_GET['kamar'] : 1;

// Calculate duration of stay
$date1 = new DateTime($checkin);
$date2 = new DateTime($checkout);
$durasi = $date2->diff($date1)->days;

// Format dates for display
$checkinDisplay = date('d M Y', strtotime($checkin));
$checkoutDisplay = date('d M Y', strtotime($checkout));

// Get available room types with stock information
$sql_rooms = "SELECT 
                k.hotel_id, 
                k.tipe_id,  
                k.view, 
                tk.nama_tipe as tipe_kamar,
                h.info_hotel as deskripsi_hotel,
                tk.kapasitas_standar as kapasitas, 
                tk.ukuran_standar as ukuran_kamar,
                jh.harga,
                jh.stok_total as stok,
                jh.terbooking,
                (jh.stok_total - jh.terbooking) as available,
                CONCAT(jh.stok_total - jh.terbooking, '/', jh.stok_total) as stok_info,
                MAX(CASE WHEN fh.fasilitas_id = 'BF' THEN 1 ELSE 0 END) as sarapan,
                MAX(CASE WHEN fh.fasilitas_id = 'WIFI' THEN 1 ELSE 0 END) as wifi,
                GROUP_CONCAT(DISTINCT fh.nama_fasilitas SEPARATOR ', ') as fasilitas_list
              FROM kamar k
              JOIN tipe_kamar tk ON k.tipe_id = tk.tipe_id
              JOIN jadwal_hotel jh ON k.hotel_id = jh.hotel_id AND k.tipe_id = jh.tipe_id
              JOIN hotel h ON k.hotel_id = h.hotel_id
              LEFT JOIN hotel_fasilitas hf ON k.hotel_id = hf.hotel_id
              LEFT JOIN fasilitas_hotel fh ON hf.fasilitas_id = fh.fasilitas_id
              WHERE k.hotel_id = ? 
                AND k.status = 'Available'
                AND jh.stok_total > jh.terbooking
              GROUP BY k.hotel_id, k.tipe_id, k.view, 
                       tk.nama_tipe, h.info_hotel, tk.kapasitas_standar, tk.ukuran_standar,
                       jh.harga, jh.stok_total, jh.terbooking";

$stmt_rooms = $conn->prepare($sql_rooms);
if (!$stmt_rooms) {
    die("Error preparing query: " . $conn->error);
}

$stmt_rooms->bind_param("s", $hotel_id);
if (!$stmt_rooms->execute()) {
    die("Execute error: " . $stmt_rooms->error);
}

$rooms = $stmt_rooms->get_result()->fetch_all(MYSQLI_ASSOC);

// Get hotel facilities
$sql_facilities = "SELECT fh.nama_fasilitas, fh.icon 
                   FROM fasilitas_hotel fh
                   JOIN hotel_fasilitas hf ON fh.fasilitas_id = hf.fasilitas_id
                   WHERE hf.hotel_id = ?";

$stmt_facilities = $conn->prepare($sql_facilities);
if (!$stmt_facilities) {
    die("Error preparing query: " . $conn->error);
}

$stmt_facilities->bind_param("s", $hotel_id);
if (!$stmt_facilities->execute()) {
    die("Execute error: " . $stmt_facilities->error);
}

$facilities = $stmt_facilities->get_result()->fetch_all(MYSQLI_ASSOC);

// Get hotel images
$main_image = !empty($hotel['foto_hotel']) ? $hotel['foto_hotel'] : '../img/default-hotel.jpg';
$detail_images = [];

if (!empty($hotel['gambar_detail'])) {
    $detail_images = explode(',', $hotel['gambar_detail']);
} else {
    $detail_images = [$main_image];
}

// Prepare address for geocoding
$full_address = $hotel['alamat'] . ', ' . $hotel['kota'];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>TripVerse - <?= te('Detail Hotel') ?></title>
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
    <link href="../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>" rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">


    <style>
        :root {
            --primary-color: #FEA116;
            --secondary-color: #FEA116;
            --dark-color: #2c3e50;
            --light-color: #f8f9fa;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        .hotel-header {
            background-color: white;
            border-radius: 18px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
            animation: tv-card-in-up .6s cubic-bezier(.22, 1, .36, 1);
        }

        @keyframes tv-card-in-up {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .hotel-gallery {
            border-radius: 18px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .main-image {
            transition: transform .6s ease;
        }

        .hotel-gallery:hover .main-image {
            transform: scale(1.03);
        }

        .main-image {
            height: 400px;
            object-fit: cover;
            width: 100%;
        }

        .thumbnail-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-top: 10px;
        }

        .thumbnail {
            height: 80px;
            object-fit: cover;
            cursor: pointer;
            border-radius: 8px;
            transition: var(--transition);
        }

        .thumbnail:hover {
            transform: scale(1.05);
            opacity: 0.8;
        }

        .hotel-info {
            background-color: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }

        .facility-badge {
            background-color: var(--light-color);
            color: var(--dark-color);
            padding: 8px 12px;
            border-radius: 8px;
            margin-right: 8px;
            margin-bottom: 8px;
            display: inline-flex;
            align-items: center;
        }

        .facility-icon {
            margin-right: 6px;
            color: var(--primary-color);
        }

        .room-card {
            border: none;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: transform .4s cubic-bezier(.22, 1, .36, 1), box-shadow .4s ease;
            margin-bottom: 30px;
        }

        .room-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 24px 44px rgba(15, 23, 43, 0.16);
        }

        .room-img {
            height: 200px;
            object-fit: cover;
            transition: transform .5s ease;
        }

        .room-card:hover .room-img {
            transform: scale(1.06);
        }

        .price {
            font-size: 1.4rem;
            font-weight: bold;
            color: var(--primary-color);
        }

        .btn-primary {
            background: linear-gradient(135deg, #FEA116 0%, #FF7A3D 100%);
            border: none;
            padding: 11px 26px;
            border-radius: 999px;
            font-weight: 600;
            box-shadow: 0 10px 24px rgba(254, 161, 22, 0.35);
            transition: transform .3s cubic-bezier(.22, 1, .36, 1), box-shadow .3s ease, filter .3s ease;
        }

        .btn-primary:hover {
            filter: brightness(1.06);
            transform: translateY(-3px);
            box-shadow: 0 14px 30px rgba(254, 161, 22, 0.45);
        }

        .btn-outline-primary {
            border-radius: 999px;
            padding: 10px 25px;
            border-width: 2px;
            transition: transform .3s cubic-bezier(.22, 1, .36, 1);
        }

        .btn-outline-primary:hover {
            transform: translateY(-3px);
        }

        .star-rating {
            color: #FFD700;
        }

        .booking-summary {
            background-color: white;
            border-radius: 18px;
            padding: 25px;
            box-shadow: var(--shadow);
            position: sticky;
            top: 20px;
            border-top: 4px solid transparent;
            border-image: linear-gradient(135deg, #FEA116, #FF7A3D) 1;
        }

        .stock-info {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
        }

        .stock-progress {
            height: 8px;
            border-radius: 4px;
            margin-top: 5px;
        }

        .stock-label {
            font-size: 14px;
            margin-bottom: 5px;
            display: flex;
            justify-content: space-between;
        }

        .stock-value {
            font-weight: bold;
        }

        .map-container {
            height: 400px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
            position: relative;
        }

        #hotel-map,
        .map-iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .map-placeholder {
            position: relative;
            height: 100%;
        }

        .map-placeholder img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .map-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            transition: all 0.3s;
        }

        .map-overlay i {
            margin-right: 10px;
        }

        .map-placeholder:hover .map-overlay {
            background: rgba(0, 0, 0, 0.5);
            font-size: 1.6rem;
        }

        .map-fallback {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            background-color: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .map-container {
                height: 300px;
            }
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
            color: #FEA116;
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
            background-color: #FEA116;
            color: #000;
        }

        .footer-menu a {
            color: #eee;
            margin: 0 8px;
            text-decoration: none;
        }

        .footer-menu a:hover {
            text-decoration: underline;
            color: #FEA116;
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
                    <a href="home.php" class="d-flex align-items-center text-decoration-none">
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

                    <nav class="navbar navbar-expand-xl bg-dark navbar-dark p-3 p-lg-0">
                        <a href="home.php" class="navbar-brand d-block d-xl-none">
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

        <!-- Page Header Start -->
        <div class="container-fluid page-header mb-5 p-0" style="background-image: url(../img/carousel-1.jpg);">
            <div class="container-fluid page-header-inner py-5">
                <div class="container text-center pb-5">
                    <h1 class="display-3 text-white mb-3 animated slideInDown"><?= te('Detail Hotel') ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-center text-uppercase">
                            <li class="breadcrumb-item"><a href="home.php"><?= te('Beranda') ?></a></li>
                            <li class="breadcrumb-item"><a href="hotel.php"><?= te('Hotel') ?></a></li>
                            <li class="breadcrumb-item text-white active" aria-current="page"><?= te('Detail Hotel') ?></li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
        <!-- Page Header End -->

        <!-- Hotel Detail Start -->
        <div class="container-fluid py-5">
            <div class="container">
                <!-- Hotel Header -->
                <div class="hotel-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h1 class="mb-2"><?= htmlspecialchars($hotel['nama_hotel']) ?></h1>
                            <div class="d-flex align-items-center mb-3">
                                <span class="text-muted">
                                    <i class="fas fa-map-marker-alt text-secondary me-1"></i>
                                    <?= htmlspecialchars($hotel['alamat']) . ', ' . htmlspecialchars($hotel['kota']) ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="price mb-2">Rp <?= number_format($hotel['harga_dasar'], 0, ',', '.') ?> <small class="text-muted">/<?= t('malam') ?></small></div>
                            <div class="text-success">
                                <i class="fas fa-check-circle me-1"></i>
                                <?= te('Tersedia kamar') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Main Content -->
                    <div class="col-lg-8">
                        <!-- Gallery -->
                        <div class="hotel-gallery mb-4">
                            <img src="../img/<?= htmlspecialchars($hotel['foto_hotel']) ?>" class="main-image" alt="<?= htmlspecialchars($hotel['nama_hotel']) ?>" id="mainImage">
                        </div>

                        <!-- Hotel Info -->
                        <div class="hotel-info mb-4">
                            <h3 class="mb-4"><?= te('Tentang Hotel Ini') ?></h3>
                            <p><?= htmlspecialchars($hotel['info_hotel']) ?></p>

                            <h4 class="mt-5 mb-3"><?= te('Fasilitas Hotel') ?></h4>
                            <div>
                                <?php foreach ($facilities as $facility): ?>
                                    <span class="facility-badge">
                                        <i class="fas <?= $facility['icon'] ?> facility-icon"></i> <?= $facility['nama_fasilitas'] ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Location Map -->
                        <div class="hotel-info mb-4">
                            <h3 class="mb-4"><?= te('Lokasi Hotel') ?></h3>

                            <div class="map-container">
                                <?php if (!empty($hotel['maps_embed_url'])): ?>
                                    <iframe class="map-iframe"
                                        src="<?= htmlspecialchars($hotel['maps_embed_url'], ENT_QUOTES, 'UTF-8') ?>"
                                        width="100%"
                                        height="400"
                                        style="border:0;"
                                        allowfullscreen=""
                                        loading="lazy"
                                        referrerpolicy="no-referrer-when-downgrade"
                                        aria-label="<?= te('Peta Lokasi') ?> <?= htmlspecialchars($hotel['nama_hotel'], ENT_QUOTES, 'UTF-8') ?>">
                                    </iframe>
                                <?php else: ?>
                                    <div class="alert alert-info d-flex align-items-center">
                                        <i class="fas fa-info-circle me-3 fa-lg"></i>
                                        <div>
                                            <h5 class="alert-heading mb-1"><?= te('Informasi Peta Tidak Tersedia') ?></h5>
                                            <p class="mb-0"><?= te('Silakan hubungi hotel untuk petunjuk arah') ?></p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Room Types -->
                        <div class="hotel-info">
                            <h3 class="mb-4"><?= te('Pilihan Kamar') ?></h3>

                            <?php if (count($rooms) > 0): ?>
                                <?php foreach ($rooms as $room): ?>
                                    <div class="card room-card tv-reveal">
                                        <div class="row g-0">
                                            <div class="col-md-4">
                                                <?php
                                                $roomImage = '../img/hotel-1.jpg';
                                                if ($room['tipe_id'] == 'DEL') {
                                                    $roomImage = '../img/hotel-3.jpg';
                                                } elseif ($room['tipe_id'] == 'ESU') {
                                                    $roomImage = '../img/hotel-2.jpg';
                                                }
                                                ?>
                                                <img src="<?= $roomImage ?>" class="img-fluid room-img" alt="<?= htmlspecialchars($room['tipe_kamar']) ?>">
                                            </div>
                                            <div class="col-md-5">
                                                <div class="card-body">
                                                    <h5 class="card-title"><?= htmlspecialchars($room['tipe_kamar']) ?></h5>
                                                    <p class="card-text text-muted small"><?= htmlspecialchars($room['deskripsi_hotel']) ?></p>

                                                    <div class="mt-3">
                                                        <span class="badge bg-light text-dark me-1 mb-1">
                                                            <i class="fas fa-bed text-primary me-1"></i> <?= $room['kapasitas'] ?> <?= te('Orang') ?>
                                                        </span>
                                                        <span class="badge bg-light text-dark me-1 mb-1">
                                                            <i class="fas fa-ruler-combined text-primary me-1"></i> <?= htmlspecialchars($room['ukuran_kamar']) ?>
                                                        </span>
                                                        <?php if ($room['sarapan']): ?>
                                                            <span class="badge bg-light text-dark me-1 mb-1">
                                                                <i class="fas fa-coffee text-primary me-1"></i> <?= te('Sarapan') ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($room['wifi']): ?>
                                                            <span class="badge bg-light text-dark me-1 mb-1">
                                                                <i class="fas fa-wifi text-primary me-1"></i> Wi-Fi
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <!-- Stock Information -->
                                                    <div class="stock-info mt-3">
                                                        <div class="stock-label">
                                                            <span class="text-muted"><?= te('Ketersediaan:') ?></span>
                                                            <span class="stock-value"><?= $room['stok_info'] ?> <?= t('kamar') ?></span>
                                                        </div>
                                                        <div class="progress stock-progress">
                                                            <div class="progress-bar bg-<?= $room['available'] > 0 ? 'success' : 'danger' ?>"
                                                                role="progressbar"
                                                                style="width: <?= ($room['available'] / $room['stok']) * 100 ?>%"
                                                                aria-valuenow="<?= $room['available'] ?>"
                                                                aria-valuemin="0"
                                                                aria-valuemax="<?= $room['stok'] ?>">
                                                            </div>
                                                        </div>
                                                    </div>

                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="card-body d-flex flex-column h-100 justify-content-between">
                                                    <div class="text-end">
                                                        <div class="price mb-2">Rp <?= number_format($room['harga'], 0, ',', '.') ?></div>
                                                        <small class="text-muted d-block"><?= te('per malam') ?></small>
                                                        <small class="<?= $room['available'] > 0 ? 'text-success' : 'text-danger' ?> d-block">
                                                            <i class="fas <?= $room['available'] > 0 ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                                            <?= $room['available'] > 0 ? te('Tersedia') : te('Habis') ?>
                                                        </small>
                                                    </div>
                                                    <a href="booking.php?hotel_id=<?= $hotel['hotel_id'] ?>&tipe_id=<?= $room['tipe_id'] ?>&checkin=<?= $checkin ?>&checkout=<?= $checkout ?>&dewasa=<?= $dewasa ?>&anak=<?= $anak ?>&kamar=<?= $kamar ?>"
                                                        class="btn btn-primary mt-3 <?= $room['available'] == 0 ? 'disabled' : '' ?>">
                                                        <?= te('Pesan Sekarang') ?>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <?= te('Maaf, tidak ada kamar yang tersedia untuk periode ini.') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Booking Summary -->
                    <div class="col-lg-4">
                        <div class="booking-summary">
                            <h4 class="mb-4"><?= te('Ringkasan Pemesanan') ?></h4>

                            <div class="mb-3">
                                <h6><?= te('Detail Tamu') ?></h6>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted"><?= te('Check-In:') ?></span>
                                    <span><?= $checkinDisplay ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted"><?= te('Check-Out:') ?></span>
                                    <span><?= $checkoutDisplay ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted"><?= te('Durasi:') ?></span>
                                    <span><?= $durasi ?> <?= t('malam') ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted"><?= te('Tamu:') ?></span>
                                    <span><?= $dewasa ?> <?= t('Dewasa') ?>, <?= $anak ?> <?= t('Anak') ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted"><?= te('Kamar:') ?></span>
                                    <span><?= $kamar ?> <?= te('Kamar') ?></span>
                                </div>
                            </div>

                            <hr>

                            <div class="mb-3">
                                <h6><?= te('Detail Hotel') ?></h6>
                                <div class="d-flex align-items-start mb-2">
                                    <img src="../img/<?= htmlspecialchars($hotel['foto_hotel']) ?>" class="rounded me-3" width="60" height="60" style="object-fit: cover;">
                                    <div>
                                        <h6 class="mb-0"><?= htmlspecialchars($hotel['nama_hotel']) ?></h6>
                                        <small class="text-muted"><?= htmlspecialchars($hotel['kota']) ?></small>
                                    </div>
                                </div>
                            </div>

                            <hr>

                            <a href="hotel.php?city=<?= urlencode($hotel['kota']) ?>" class="btn btn-outline-primary w-100 mb-2">
                                <i class="fas fa-arrow-left me-2"></i> <?= te('Kembali ke Daftar') ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Hotel Detail End -->

        <!-- Footer -->
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
        // Hide spinner when page is loaded
        window.addEventListener('load', function() {
            const spinner = document.getElementById('spinner');
            if (spinner) {
                spinner.classList.remove('show');
            }
        });

        // Fungsi untuk thumbnail gallery
        document.querySelectorAll('.thumbnail').forEach(thumb => {
            thumb.addEventListener('click', function() {
                document.getElementById('mainImage').src = this.src;
            });
        });

    </script>
</body>

</html>
<?php $conn->close(); ?>