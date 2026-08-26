<?php
session_start();
require_once __DIR__ . '/_lang.php';

/*
 * Try to require connect.php with a couple of common relative paths.
 */
if (file_exists('../connect.php')) {
    require_once '../connect.php';
} elseif (file_exists('connect.php')) {
    require_once 'connect.php';
} else {
    die('Database connection file not found. Periksa path connect.php');
}

// pastikan user sudah login
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

$id_user = $_SESSION['id_user'];

// Optional: ambil username/email dari session jika ada
$username = $_SESSION['username'] ?? '';

// Cek format id_user (awalan 'USR') — jika kamu memang pakai format ini
if (!str_starts_with($id_user, 'USR')) {
    echo "<script>alert('" . t('Akses ditolak! Halaman ini hanya untuk user.') . "'); window.location='dashboard.php';</script>";
    exit;
}

/* Ambil data user dari database (first_name, last_name, email, profile_picture) */
$firstName = '';
$lastName = '';
$email = '';
$foto = null;
$is_new_user = false;
$days_since_registration = 0;
$remaining_days = 0;

$query = "SELECT u.first_name, u.last_name, u.email, u.profile_picture, 
                 c.created_at 
          FROM user u 
          LEFT JOIN customer c ON u.id_user = c.id_user 
          WHERE u.id_user = ? LIMIT 1";
$stmt = $conn->prepare($query);

if ($stmt === false) {
    die("Error in query preparation: " . $conn->error);
}

$stmt->bind_param("s", $id_user);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $firstName = $row['first_name'] ?? '';
    $lastName = $row['last_name'] ?? '';
    $email = $row['email'] ?? '';
    $foto = $row['profile_picture'] ?? null;
    
    // Tentukan apakah user baru (dibuat dalam 7 hari terakhir)
    $created_at = $row['created_at'] ?? null;
    if ($created_at) {
        $created_date = new DateTime($created_at);
        $now = new DateTime();
        $interval = $created_date->diff($now);
        $days_since_registration = $interval->days;
        
        // Jika kurang dari 7 hari, voucher masih berlaku
        if ($days_since_registration < 7) {
            $is_new_user = true;
            $remaining_days = 7 - $days_since_registration;
        }
    }
} else {
    $email = $username;
}

$stmt->close();

// ==========================================
// AMBIL DATA DISKON PENGGUNA BARU DARI DATABASE
// ==========================================
$diskon_pengguna_baru = null;
$show_popup = false;
$new_user_notification = '';

if ($is_new_user) {
    // Query untuk mengambil data diskon pengguna baru dari database
    $query_diskon = "SELECT * FROM diskon_promo 
                    WHERE kode_promo = 'NEWUSER25' 
                    AND status = 'active'
                    AND tanggal_mulai <= CURDATE() 
                    AND tanggal_berakhir >= CURDATE() 
                    LIMIT 1";
    
    $result_diskon = $conn->query($query_diskon);
    
    if ($result_diskon && $result_diskon->num_rows > 0) {
        $diskon_pengguna_baru = $result_diskon->fetch_assoc();
        $show_popup = true;
        
        // Buat notifikasi untuk navbar
        $new_user_notification = t('Diskon') . " {$diskon_pengguna_baru['nilai_diskon']}% " . t('berlaku') . " {$remaining_days} " . t('hari lagi');
    }
}

// Format data diskon untuk JavaScript
$diskon_data_json = json_encode([
    'is_new_user' => $is_new_user,
    'remaining_days' => $remaining_days,
    'days_since_registration' => $days_since_registration,
    'show_popup' => $show_popup,
    'diskon_data' => $diskon_pengguna_baru,
    'new_user_notification' => $new_user_notification
]);

// Tetapkan path foto
if (!empty($foto) && file_exists('../uploads/' . $foto)) {
    $fotoPath = '../uploads/' . $foto;
} else {
    $fotoPath = '../img/default.jpg';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title><?= te('Beranda') ?> - TripVerse</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">
    <link href="../css/wa.css?v=2.0" rel="stylesheet">

    <link href="../img/favicon.ico" rel="icon">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

    <link href="../lib/animate/animate.min.css" rel="stylesheet">
    <link href="../lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="../lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet" />

    <link href="../css/bootstrap.min.css" rel="stylesheet">

    <link href="../css/style.css?v=2.0" rel="stylesheet">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    <link href="../css/home.css?v=2.0" rel="stylesheet">
    <link href="../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>" rel="stylesheet">

    <style>
        /* PROFIL STYLES - DIPERBAIKI TOTAL UNTUK BOOTSTRAP DROPDOWN */

        /* Mengganti .profile-section menjadi .profile-dropdown-container */
        .profile-dropdown-container {
            display: flex;
            align-items: center;
            margin-left: auto !important;
            position: relative;
        }

        .profile-photo-container {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid #fff;
            /* Border default putih */
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        /* Mengatur border saat hover atau ketika dropdown aktif */
        .profile-dropdown-container:hover .profile-photo-container,
        .profile-dropdown-container .dropdown-toggle[aria-expanded="true"] .profile-photo-container {
            border-color: #FEA116;
        }

        .profile-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Menghilangkan .profile-overlay karena sudah tidak diperlukan di sini */

        .profile-text {
            color: white;
            text-align: left;
            margin-left: 10px;
        }

        .profile-text .name {
            font-size: 14px;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .profile-text .email {
            font-size: 12px;
            opacity: 0.8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Mengganti .user-dropdown dan .dropdown-content menjadi .dropdown-menu */
        .dropdown-menu {
            position: absolute;
            right: 0 !important;
            top: 100%;
            background-color: white;
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            z-index: 1050;
            margin-top: 5px;
            padding: 0;
        }

        /* Mengganti selector a di dalam dropdown-content */
        .dropdown-menu a.dropdown-item {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            text-decoration: none;
            color: #333;
            transition: background-color 0.3s ease;
            border-bottom: 1px solid #f0f0f0;
        }

        .dropdown-menu a.dropdown-item:last-child {
            border-bottom: none;
        }

        .dropdown-menu a.dropdown-item:hover {
            background-color: #f8f9fa;
            color: #FEA116;
        }

        .dropdown-menu .material-icons {
            margin-right: 10px;
            font-size: 18px;
        }

        .dropdown-header {
            padding: 10px 15px;
            white-space: normal;
            line-height: 1.2;
        }

        .dropdown-menu hr.dropdown-divider {
            margin: 5px 0;
            border: none;
            border-top: 1px solid #e9ecef;
        }

        /* POPUP DISKON PENGGUNA BARU */
        .popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(10px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 999999;
            opacity: 0;
            transition: opacity 0.3s ease;
            padding: 20px;
        }

        .popup-content {
            background: linear-gradient(145deg, #ffffff, #f8f9fa);
            width: 100%;
            max-width: 500px;
            border-radius: 20px;
            text-align: center;
            padding: 30px;
            animation: popIn 0.5s ease-out;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            border: 4px solid #FEA116;
            position: relative;
            overflow: hidden;
        }

        .popup-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, #FEA116, #FF7A3D, #FFC966, #FF7A3D, #FEA116);
            background-size: 200% 100%;
            animation: shimmer 3s infinite linear;
        }

        .popup-banner {
            width: 100%;
            max-height: 200px;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 3px solid #FEA116;
        }

        .promo-text {
            color: #E8890A;
            font-size: 2.2rem;
            font-weight: 900;
            margin: 15px 0;
            text-shadow: 2px 2px 0 rgba(0,0,0,0.1);
            animation: pulse 2s infinite;
        }

        .voucher-info {
            background: #fff8e6;
            border-radius: 12px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            border: 2px dashed #FEA116;
        }

        .voucher-detail {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            font-size: 0.95rem;
        }

        .voucher-detail:last-child {
            margin-bottom: 0;
        }

        .voucher-detail .material-icons {
            color: #FEA116;
            margin-right: 10px;
            font-size: 20px;
        }

        .popup-instruction {
            background: #e6f7ff;
            border-radius: 10px;
            padding: 12px;
            margin: 15px 0;
            border-left: 4px solid #1890ff;
        }

        .popup-btn {
            background: linear-gradient(135deg, #FEA116, #FF7A3D);
            border: none;
            padding: 15px 30px;
            width: 100%;
            color: white;
            font-size: 1.1rem;
            border-radius: 12px;
            cursor: pointer;
            font-weight: bold;
            margin: 10px 0;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .popup-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(254, 161, 22, 0.4);
        }

        .popup-secondary-btn {
            background: transparent;
            border: 2px solid #FEA116;
            padding: 12px 30px;
            width: 100%;
            color: #FEA116;
            font-size: 1rem;
            border-radius: 10px;
            cursor: pointer;
            font-weight: bold;
            margin-top: 10px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .popup-secondary-btn:hover {
            background: #FEA116;
            color: white;
        }

        /* Notifikasi Voucher di Navbar */
        .voucher-notification {
            background: rgba(254, 161, 22, 0.2);
            border: 1px solid rgba(254, 161, 22, 0.5);
            border-radius: 20px;
            padding: 5px 15px;
            margin-left: 10px;
            display: flex;
            align-items: center;
            animation: blink 2s infinite;
            font-size: 0.85rem;
        }

        .voucher-notification .material-icons {
            font-size: 18px;
            margin-right: 5px;
            color: #FEA116;
        }

        @keyframes popIn {
            0% { transform: scale(0.8); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        /* Responsive Design */
        @media (max-width: 991px) {

            /* Menyembunyikan teks dan ikon panah di mobile */
            .profile-text,
            .profile-dropdown-container .material-icons {
                display: none !important;
            }

            .profile-dropdown-container {
                margin-left: 0 !important;
            }

            .voucher-notification {
                display: none !important;
            }

            /* Mengatasi penempatan dropdown menu ketika navbar collapsable terbuka */
            .navbar-collapse .dropdown-menu {
                position: absolute;
                left: auto !important;
                right: 10px;
                top: 100%;
            }
        }

        @media (max-width: 480px) {
            .popup-content {
                padding: 20px;
                max-width: 90%;
            }
            
            .promo-text {
                font-size: 1.8rem;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid" style="background-color: #ffffff;">
        <div id="spinner"
            class="show bg-white position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="sr-only">Loading...</span>
            </div>
        </div>

        <div class="container-fluid bg-dark px-0">
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
                                <a class="me-3" href="https://www.facebook.com/"><i class="fab fa-facebook-f"></i></a>
                                <a class="me-3" href="https://twitter.com/"><i class="fab fa-twitter"></i></a>
                                <a class="me-3" href="https://id.linkedin.com/"><i class="fab fa-linkedin-in"></i></a>
                                <a class="me-3" href="https://www.instagram.com/"><i class="fab fa-instagram"></i></a>
                                <a class="" href="https://www.youtube.com/"><i class="fab fa-youtube"></i></a>
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
                                <a href="home.php" class="nav-item nav-link active"><?= te("Beranda") ?></a>
                                <a href="about.php" class="nav-item nav-link"><?= te("Tentang Kami") ?></a>
                                <a href="hotel.php" class="nav-item nav-link"><?= te("Hotel") ?></a>
                                <a href="flights.php" class="nav-item nav-link"><?= te("Pesawat") ?></a>
                                <a href="service.php" class="nav-item nav-link"><?= te("Fitur") ?></a>
                                <a href="team.php" class="nav-item nav-link"><?= te("Tim Kami") ?></a>
                                <a href="contact.php" class="nav-item nav-link"><?= te("Kontak") ?></a>
                                <a href="history.php" class="nav-item nav-link"><?= te("Riwayat") ?></a>
                            </div>

                            <div class="ms-auto me-2 d-flex align-items-center gap-2">
                                <?php if ($is_new_user && $show_popup && !empty($new_user_notification)): ?>
                                <div class="voucher-notification d-none d-lg-flex">
                                    <span class="material-icons">local_offer</span>
                                    <span style="color: white;">
                                        <?= htmlspecialchars($new_user_notification) ?>
                                    </span>
                                </div>
                                <?php endif; ?>

                                <?php include __DIR__ . '/_lang_switch.php'; ?><?php include __DIR__ . '/_account_menu.php'; ?>
                            </div>
                        </div>
                    </nav>
                </div>
            </div>
        </div>
        <!--end-->

        <div class="container-fluid p-0 mb-5">
            <div id="header-carousel" class="carousel slide" data-bs-ride="carousel">
                <div class="carousel-inner">
                    <div class="carousel-item active">
                        <img class="w-100" src="../img/carousel-3.jpg" alt="Image">
                        <div class="carousel-caption d-flex flex-column align-items-center justify-content-center">
                            <div class="p-3" style="max-width: 700px;">
                                <h6 class="section-title text-white text-uppercase mb-3 animated slideInDown"><?= te('Jelajahi Dunia, Ciptakan Cerita Anda!') ?></h6>
                                <h1 class="display-3 text-white mb-4 animated slideInDown"><?= te('Perjalanan Anda Dimulai di Sini!') ?>
                                </h1>
                            </div>
                        </div>
                    </div>
                    <div class="carousel-item">
                        <img class="w-100" src="../img/carousel-2.jpg" alt="Image">
                        <div class="carousel-caption d-flex flex-column align-items-center justify-content-center">
                            <div class="p-3" style="max-width: 700px;">
                                <h6 class="section-title text-white text-uppercase mb-3 animated slideInDown"><?= te('Jelajahi Dunia, Ciptakan Cerita Anda!') ?></h6>
                                <h1 class="display-3 text-white mb-4 animated slideInDown"><?= te('Tetap Nyaman, Bepergian dengan Kenangan!') ?></h1>
                            </div>
                        </div>
                    </div>
                    <div class="carousel-item">
                        <img class="w-100" src="../img/carousel-1.jpg" alt="Image">
                        <div class="carousel-caption d-flex flex-column align-items-center justify-content-center">
                            <div class="p-3" style="max-width: 700px;">
                                <h6 class="section-title text-white text-uppercase mb-3 animated slideInDown"><?= te('Hotel Pilihan Untuk Setiap Perjalanan') ?></h6>
                                <h1 class="display-3 text-white mb-4 animated slideInDown"><?= te('Kenyamanan yang Menyambut Anda') ?></h1>
                            </div>
                        </div>
                    </div>
                    <div class="carousel-item">
                        <img class="w-100" src="../img/carousel-4.jpg" alt="Image">
                        <div class="carousel-caption d-flex flex-column align-items-center justify-content-center">
                            <div class="p-3" style="max-width: 700px;">
                                <h6 class="section-title text-white text-uppercase mb-3 animated slideInDown"><?= te('Lebih dari Sekadar Hotel') ?></h6>
                                <h1 class="display-3 text-white mb-4 animated slideInDown"><?= te('Petualangan di Udara, Segera Hadir') ?></h1>
                            </div>
                        </div>
                    </div>
                    <button class="carousel-control-prev" type="button" data-bs-target="#header-carousel"
                        data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#header-carousel"
                        data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                </div>
            </div>
        </div>
        <div class="container">
            <div class="search-container">
                <div class="mb-3">
                    <label class="form-label"><?= te('Kota dan tujuan') ?></label>
                    <input type="text" class="form-control" id="cityInput" placeholder="<?= te('Kota dan tujuan hotel') ?>" value="">
                </div>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label"><?= te('Check-In:') ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-calendar"></i></span>
                            <input type="date" id="checkin" class="form-control">
                        </div>
                    </div>

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

                    <div class="col-md-4 mb-3">
                        <label class="form-label"><?= te('Check-Out:') ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-calendar"></i></span>
                            <input type="text" id="checkout" class="form-control" readonly>
                        </div>
                    </div>
                </div>

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

                <a href="hotel.php" class="text-decoration-none">
                    <button class="btn btn-search w-100" type="button">
                        <i class="fa fa-search"></i> <?= te('Cari Hotel') ?>
                    </button>
                </a>
            </div>
        </div>

        <div class="container-xxl py-5">
            <div class="container">
                <div class="row g-5 align-items-center">
                    <div class="col-lg-6">
                        <h6 class="section-title text-start text-primary text-uppercase"><?= te('Tentang Kami') ?></h6>
                        <h2 class="mb-4"><?= te('Selamat datang di') ?> <span
                                class="text-primary text-uppercase">TripVerse</span></h2>
                        <div class="about-container">
                            <p><?= te('Dalam era digital yang semakin berkembang, perencanaan perjalanan kini menjadi lebih mudah dan praktis. TripVerse hadir sebagai solusi inovatif berbasis web yang dirancang untuk memberikan pengalaman perjalanan yang seamless, nyaman, dan terjangkau.') ?>
                                <span class="dots">...</span>
                                <span class="more-text">
                                    <br><br>
                                    <?= te('Sebagai platform pemesanan perjalanan, TripVerse memungkinkan pengguna untuk mencari dan memesan akomodasi hotel dengan cepat dan efisien. Kami menawarkan transparansi harga, rekomendasi perjalanan berbasis preferensi, manajemen pemesanan yang fleksibel, serta kemudahan dalam pembatalan dan perubahan jadwal.') ?> <br><br>

                                    <?= te('Lebih dari sekadar platform reservasi, TripVerse adalah mitra perjalanan Anda. Dengan fitur unggulan seperti informasi harga yang jelas, kalender perjalanan, serta layanan pelanggan yang responsif, kami membantu Anda merancang perjalanan yang lebih terorganisir dan sesuai kebutuhan.') ?> <br><br>

                                    <?= te('Melalui TripVerse, kami berkomitmen untuk mendukung digitalisasi industri perjalanan dan meningkatkan kepuasan pengguna dalam mengelola perjalanan mereka. Bersama TripVerse, jelajahi dunia dengan lebih mudah, fleksibel, dan menyenangkan!') ?>
                                </span>
                            </p>
                            <span class="read-more" onclick="toggleReadMore()"><?= te('Selengkapnya...') ?></span>
                        </div>
                        <script>
                            function toggleReadMore() {
                                var moreText = document.querySelector(".more-text");
                                var dots = document.querySelector(".dots");
                                var btnText = document.querySelector(".read-more");

                                if (moreText.style.display === "none" || moreText.style.display === "") {
                                    moreText.style.display = "inline";
                                    dots.style.display = "none";
                                    btnText.innerHTML = "<?= t('Lebih Sedikit') ?>";
                                } else {
                                    moreText.style.display = "none";
                                    dots.style.display = "inline";
                                    btnText.innerHTML = "<?= t('Selengkapnya...') ?>";
                                }
                            }
                        </script>
                        <div class="row g-3 pb-4">
                            <div class="col-3 wow fadeIn" data-wow-delay="0.1s">
                                <div class="border rounded p-1">
                                    <div class="border rounded text-center p-3">
                                        <i class="fa fa-hotel fa-2x text-primary mb-2"></i>
                                        <h2 class="mb-1" data-toggle="counter-up">1000</h2>
                                        <p class="mb-0"><?= te('Hotel') ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-3 wow fadeIn" data-wow-delay="0.3s">
                                <div class="border rounded p-1">
                                    <div class="border rounded text-center p-3">
                                        <i class="fa fa-users-cog fa-2x text-primary mb-2"></i>
                                        <h2 class="mb-1" data-toggle="counter-up">500</h2>
                                        <p class="mb-0"><?= te('Pegawai') ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-3 wow fadeIn" data-wow-delay="0.5s">
                                <div class="border rounded p-1">
                                    <div class="border rounded text-center p-3">
                                        <i class="fa fa-users fa-2x text-primary mb-2"></i>
                                        <h2 class="mb-1" data-toggle="counter-up">10000</h2>
                                        <p class="mb-0"><?= te('Klien') ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <a class="btn btn-primary py-3 px-5 mt-2" href="about.php"><?= te('Jelajahi Lebih Lanjut') ?></a>
                    </div>
                    <div class="col-lg-6">
                        <div class="row g-3">
                            <div class="col-6 text-end">
                                <img class="img-fluid rounded w-75 wow zoomIn" data-wow-delay="0.1s"
                                    src="../img/about-1.jpg" style="margin-top: 25%;">
                            </div>
                            <div class="col-6 text-start">
                                <img class="img-fluid rounded w-100 wow zoomIn" data-wow-delay="0.3s"
                                    src="../img/about-2.jpg">
                            </div>
                            <div class="col-6 text-end">
                                <img class="img-fluid rounded w-75 wow zoomIn" data-wow-delay="0.5s"
                                    src="../img/hotel5.jpg">
                            </div>
                            <div class="col-6 text-start">
                                <img class="img-fluid rounded w-90 wow zoomIn" data-wow-delay="0.7s"
                                    src="../img/hotel6.jpg">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="container-xxl py-5">
            <div class="container">
                <div class="text-center wow fadeInUp" data-wow-delay="0.1s">
                    <h6 class="section-title text-center text-primary text-uppercase"><?= te('Hotel') ?></h6>
                    <h2 class="mb-5"><?php if (tv_lang() === 'en'): ?>Recommended <span class="text-primary text-uppercase">Hotels</span><?php else: ?>Rekomendasi <span class="text-primary text-uppercase">Hotel</span><?php endif; ?></h2>
                </div>
                <div class="row g-4">
                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="0.1s">
                        <div class="hotel-item shadow rounded overflow-hidden">
                            <div class="position-relative">
                                <img class="img-fluid" src="../img/hotel-1.jpg" alt="">
                                <small
                                    class="position-absolute start-0 top-100 translate-middle-y bg-primary text-white rounded py-1 px-3 ms-4"><?= te('Mulai dari') ?> Rp200.000/<?= t('malam') ?></small>
                            </div>
                            <div class="p-4 mt-2">
                                <div class="d-flex justify-content-between mb-3">
                                    <h5 class="mb-0">Junior Suite</h5>
                                </div>
                                <div class="d-flex mb-3">
                                    <small class="border-end me-3 pe-3"><i class="fa fa-bed text-primary me-2"></i>3
                                        <?= te('Tempat Tidur') ?></small>
                                    <small class="border-end me-3 pe-3"><i
                                            class="fa fa-bath text-primary me-2"></i>2 <?= te('Kamar Mandi') ?></small>
                                    <small><i class="fa fa-wifi text-primary me-2"></i>Wifi</small>
                                </div>
                                <p class="text-body mb-3"><?= te('Kamar nyaman dengan desain modern, cocok untuk keluarga kecil atau perjalanan bisnis.') ?></p>
                                <div class="d-flex justify-content-between">
                                    <a class="btn btn-sm btn-primary rounded py-2 px-4" href="hotel.php"><?= te('Lihat Detail') ?></a>
                                    <a class="btn btn-sm btn-dark rounded py-2 px-4" href="hotel.php"><?= te('Pesan Sekarang') ?></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="0.3s">
                        <div class="hotel-item shadow rounded overflow-hidden">
                            <div class="position-relative">
                                <img class="img-fluid" src="../img/hotel-2.jpg" alt="">
                                <small
                                    class="position-absolute start-0 top-100 translate-middle-y bg-primary text-white rounded py-1 px-3 ms-4"><?= te('Mulai dari') ?> Rp500.000/<?= t('malam') ?></small>
                            </div>
                            <div class="p-4 mt-2">
                                <div class="d-flex justify-content-between mb-3">
                                    <h5 class="mb-0">Executive Suite</h5>
                                </div>
                                <div class="d-flex mb-3">
                                    <small class="border-end me-3 pe-3"><i class="fa fa-bed text-primary me-2"></i>4
                                        <?= te('Tempat Tidur') ?></small>
                                    <small class="border-end me-3 pe-3"><i
                                            class="fa fa-bath text-primary me-2"></i>3 <?= te('Kamar Mandi') ?></small>
                                    <small><i class="fa fa-wifi text-primary me-2"></i>Wifi</small>
                                </div>
                                <p class="text-body mb-3"><?= te('Suite luas dengan fasilitas lengkap dan pemandangan kota yang menakjubkan.') ?></p>
                                <div class="d-flex justify-content-between">
                                    <a class="btn btn-sm btn-primary rounded py-2 px-4" href="hotel.php"><?= te('Lihat Detail') ?></a>
                                    <a class="btn btn-sm btn-dark rounded py-2 px-4" href="hotel.php"><?= te('Pesan Sekarang') ?></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="0.6s">
                        <div class="hotel-item shadow rounded overflow-hidden">
                            <div class="position-relative">
                                <img class="img-fluid" src="../img/hotel-3.jpg" alt="">
                                <small
                                    class="position-absolute start-0 top-100 translate-middle-y bg-primary text-white rounded py-1 px-3 ms-4"><?= te('Mulai dari') ?> Rp800.000/<?= t('malam') ?></small>
                            </div>
                            <div class="p-4 mt-2">
                                <div class="d-flex justify-content-between mb-3">
                                    <h5 class="mb-0">Super Deluxe</h5>
                                </div>
                                <div class="d-flex mb-3">
                                    <small class="border-end me-3 pe-3"><i class="fa fa-bed text-primary me-2"></i>5
                                        <?= te('Tempat Tidur') ?></small>
                                    <small class="border-end me-3 pe-3"><i
                                            class="fa fa-bath text-primary me-2"></i>4 <?= te('Kamar Mandi') ?></small>
                                    <small><i class="fa fa-wifi text-primary me-2"></i>Wifi</small>
                                </div>
                                <p class="text-body mb-3"><?= te('Kamar eksklusif dengan kenyamanan maksimal dan pelayanan bintang lima.') ?></p>
                                <div class="d-flex justify-content-between">
                                    <a class="btn btn-sm btn-primary rounded py-2 px-4" href="hotel.php"><?= te('Lihat Detail') ?></a>
                                    <a class="btn btn-sm btn-dark rounded py-2 px-4" href="hotel.php"><?= te('Pesan Sekarang') ?></a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-center mt-4 wow fadeInUp" data-wow-delay="0.9s">
                        <a class="btn btn-primary py-3 px-5" href="hotel.php"><?= te('Jelajahi Lebih Lanjut') ?></a>
                    </div>
                </div>
            </div>
        </div>
        <div class="container-xxl py-5 px-0 wow zoomIn" data-wow-delay="0.1s">
            <div class="row g-0">
                <div class="col-md-6 bg-dark d-flex align-items-center">
                    <div class="p-5">
                        <h6 class="section-title text-start text-white text-uppercase mb-3"><?= te('Jelajahi Dunia, Ciptakan Cerita Anda!') ?></h6>
                        <h2 class="text-white mb-4"><?= te('Perjalanan Anda Dimulai di Sini!') ?></h2>
                        <p class="text-white mb-4"><?= te('Pernah dengar tentang Online Travel Agent, tapi masih bingung apa itu sebenarnya? 🤔✨ Jangan khawatir! Di video ini, kita akan membahas semuanya—mulai dari cara kerja, manfaat, hingga bagaimana OTA bisa membantu perjalananmu jadi lebih mudah dan praktis! 🎥✈️ Yuk, tonton sekarang dan temukan jawabannya! 🚀🌍') ?></p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="video">
                        <button type="button" class="btn-play" data-bs-toggle="modal"
                            data-src="https://www.youtube.com/embed/dQw4w9WgXcQ" data-bs-target="#videoModal">
                            <span></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="videoModal" tabindex="-1" aria-labelledby="exampleModalLabel"
            aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content rounded-0">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLabel">Youtube Video</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="ratio ratio-16x9">
                            <iframe class="embed-responsive-item" src="" id="video" allowfullscreen
                                allowscriptaccess="always" allow="autoplay"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="container-xxl py-5">
            <div class="container">
                <div class="text-center wow fadeInUp" data-wow-delay="0.1s">
                    <h6 class="section-title text-center text-primary text-uppercase"><?= te('Fitur') ?></h6>
                    <h2 class="mb-5"><?php if (tv_lang() === 'en'): ?>Explore Our <span class="text-primary text-uppercase">Features</span><?php else: ?>Jelajahi <span class="text-primary text-uppercase">Fitur</span> Kami<?php endif; ?></h2>
                </div>
                <div class="row g-4">
                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="0.1s">
                        <a class="service-item rounded" href="">
                            <div class="service-icon bg-transparent border rounded p-1">
                                <div
                                    class="w-100 h-100 border rounded d-flex align-items-center justify-content-center">
                                    <i class="fa fa-search fa-2x text-primary"></i>
                                </div>
                            </div>
                            <h5 class="mb-3"><?= te('Pencarian & Pemesanan') ?></h5>
                            <p class="text-body mb-0"><?= te('Cari hotel dengan mudah berdasarkan tanggal dan lokasi tujuan Anda.') ?></p>
                        </a>
                    </div>
                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="0.2s">
                        <a class="service-item rounded" href="">
                            <div class="service-icon bg-transparent border rounded p-1">
                                <div
                                    class="w-100 h-100 border rounded d-flex align-items-center justify-content-center">
                                    <i class="fa fa-tags fa-2x text-primary"></i>
                                </div>
                            </div>
                            <h5 class="mb-3"><?= te('Informasi Harga Transparan') ?></h5>
                            <p class="text-body mb-0"><?= te('Harga yang ditampilkan sudah termasuk pajak & biaya layanan tanpa tambahan tersembunyi.') ?></p>
                        </a>
                    </div>

                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="0.4s">
                        <a class="service-item rounded" href="">
                            <div class="service-icon bg-transparent border rounded p-1">
                                <div
                                    class="w-100 h-100 border rounded d-flex align-items-center justify-content-center">
                                    <i class="fa fa-receipt fa-2x text-primary"></i>
                                </div>
                            </div>
                            <h5 class="mb-3"><?= te('Manajemen Pemesanan') ?></h5>
                            <p class="text-body mb-0"><?= te('Akses riwayat pemesanan, cetak tiket, dan lihat detail perjalanan dengan mudah.') ?></p>
                        </a>
                    </div>
                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="0.7s">
                        <a class="service-item rounded" href="">
                            <div class="service-icon bg-transparent border rounded p-1">
                                <div
                                    class="w-100 h-100 border rounded d-flex align-items-center justify-content-center">
                                    <i class="fa fa-gift fa-2x text-primary"></i>
                                </div>
                            </div>
                            <h5 class="mb-3"><?= te('Poin & Reward') ?></h5>
                            <p class="text-body mb-0"><?= te('Kumpulkan poin dari setiap pemesanan dan tukarkan dengan diskon eksklusif.') ?></p>
                        </a>
                    </div>
                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="0.9s">
                        <a class="service-item rounded" href="">
                            <div class="service-icon bg-transparent border rounded p-1">
                                <div
                                    class="w-100 h-100 border rounded d-flex align-items-center justify-content-center">
                                    <i class="fa fa-headset fa-2x text-primary"></i>
                                </div>
                            </div>
                            <h5 class="mb-3">Customer Service</h5>
                            <p class="text-body mb-0"><?= te('Dapatkan bantuan cepat melalui WhatsApp untuk segala kendala perjalanan.') ?></p>
                        </a>
                    </div>
                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="1.0s">
                        <a class="service-item rounded" href="">
                            <div class="service-icon bg-transparent border rounded p-1">
                                <div
                                    class="w-100 h-100 border rounded d-flex align-items-center justify-content-center">
                                    <i class="fa fa-calendar-check fa-2x text-primary"></i>
                                </div>
                            </div>
                            <h5 class="mb-3"><?= te('Pembatalan Fleksibel') ?></h5>
                            <p class="text-body mb-0"><?= te('Ubah atau batalkan pemesanan hotel Anda dengan mudah sesuai kebijakan yang berlaku.') ?></p>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="container-xxl py-5">
            <div class="container">
                <div class="text-center wow fadeInUp" data-wow-delay="0.1s">
                    <h6 class="section-title text-center text-primary text-uppercase"><?= te('Tim Kami') ?></h6>
                    <h2 class="mb-5"><?php if (tv_lang() === 'en'): ?>Meet Our <span class="text-primary text-uppercase">Team</span><?php else: ?>Temui <span class="text-primary text-uppercase">Tim</span> Kami<?php endif; ?></h2>
                </div>
                <div class="row g-4 justify-content-center">
                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="0.1s">
                        <div class="rounded shadow overflow-hidden">
                            <div class="position-relative">
                                <img class="img-fluid team-img" src="../img/team-1.jpg" alt="">
                                <div
                                    class="position-absolute start-50 top-100 translate-middle d-flex align-items-center">
                                    <a class="btn btn-square btn-primary mx-1" href="#"><i
                                            class="fab fa-facebook-f"></i></a>
                                    <a class="btn btn-square btn-primary mx-1" href="#"><i
                                            class="fab fa-twitter"></i></a>
                                    <a class="btn btn-square btn-primary mx-1"
                                        href="https://www.instagram.com/elroy_matthew_/">
                                        <i class="fab fa-instagram"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="text-center p-4 mt-3">
                                <h5 class="fw-bold mb-0">Elroy Matthew Wiyanto</h5>
                                <small>Chief Executive Officer (CEO)</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="0.3s">
                        <div class="rounded shadow overflow-hidden">
                            <div class="position-relative">
                                <img class="img-fluid team-img" src="../img/team-2.jpg" alt="">
                                <div
                                    class="position-absolute start-50 top-100 translate-middle d-flex align-items-center">
                                    <a class="btn btn-square btn-primary mx-1" href="#"><i
                                            class="fab fa-facebook-f"></i></a>
                                    <a class="btn btn-square btn-primary mx-1" href="#"><i
                                            class="fab fa-twitter"></i></a>
                                    <a class="btn btn-square btn-primary mx-1"
                                        href="https://www.instagram.com/jacee_ang/">
                                        <i class="fab fa-instagram"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="text-center p-4 mt-3">
                                <h5 class="fw-bold mb-0">Jacelyn Ang</h5>
                                <small>Chief Technology Officer (CTO)</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay="0.5s">
                        <div class="rounded shadow overflow-hidden">
                            <div class="position-relative">
                                <img class="img-fluid team-img" src="../img/team-3.jpg" alt="">
                                <div
                                    class="position-absolute start-50 top-100 translate-middle d-flex align-items-center">
                                    <a class="btn btn-square btn-primary mx-1" href="#"><i
                                            class="fab fa-facebook-f"></i></a>
                                    <a class="btn btn-square btn-primary mx-1" href="#"><i
                                            class="fab fa-twitter"></i></a>
                                    <a class="btn btn-square btn-primary mx-1"
                                        href="https://www.instagram.com/novanrafii/">
                                        <i class="fab fa-instagram"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="text-center p-4 mt-3">
                                <h5 class="fw-bold mb-0">Novan Rafiathadari</h5>
                                <small>Chief Marketing Officer (CMO)</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
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
                        <p class="mb-2"><i class="fa fa-map-marker-alt me-3"></i>Jl. Wisata No. 45, Jakarta,
                            Indonesia</p>
                        <p class="mb-2"><i class="fa fa-phone-alt me-3"></i>+62 878 0677 6235</p>
                        <p class="mb-2"><i class="fa fa-envelope me-3"></i>tripverse@gmail.com</p>
                        <div class="d-flex pt-2">
                            <a class="btn btn-outline-light btn-social mx-1" href="https://twitter.com/"><i
                                    class="fab fa-twitter"></i></a>
                            <a class="btn btn-outline-light btn-social mx-1" href="https://facebook.com/"><i
                                    class="fab fa-facebook-f"></i></a>
                            <a class="btn btn-outline-light btn-social mx-1" href="https://youtube.com/"><i
                                    class="fab fa-youtube"></i></a>
                            <a class="btn btn-outline-light btn-social mx-1" href="https://linkedin.com/company/"><i
                                    class="fab fa-linkedin-in"></i></a>
                            <a class="btn btn-outline-light btn-social mx-1" href="https://instagram.com/"><i
                                    class="fab fa-instagram"></i></a>
                        </div>
                    </div>

                    <div class="col-lg-5 col-md-12">
                        <div class="row gy-5 g-4">
                            <div class="col-md-6">
                                <h6 class="section-title text-start text-primary text-uppercase mb-4">Company
                                </h6>
                                <a class="btn btn-link" href="#">About TripVerse</a>
                                <a class="btn btn-link" href="#">Contact Us</a>
                                <a class="btn btn-link" href="#">Privacy Policy</a>
                                <a class="btn btn-link" href="#">Terms & Conditions</a>
                                <a class="btn btn-link" href="#">Support</a>
                            </div>
                            <div class="col-md-6">
                                <h6 class="section-title text-start text-primary text-uppercase mb-4">Services
                                </h6>
                                <a class="btn btn-link" href="#">Hotel Booking</a>
                                <a class="btn btn-link" href="#">Event & Activities</a>
                                <a class="btn btn-link" href="#">Spa & Wellness</a>
                                <a class="btn btn-link" href="#">Travel Insurance</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

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

        <!-- ================= POPUP DISKON PENGGUNA BARU ================= -->
        <?php if ($show_popup && $diskon_pengguna_baru): ?>
        <div id="newUserPopup" class="popup-overlay" style="display:none;">
            <div class="popup-content">
                <img src="../img/promo-new-user.png" class="popup-banner" alt="Promo Pengguna Baru">
                
                <h2><?= te('Selamat Datang di') ?> TripVerse! 🎉</h2>
                <p><?= te('Karena kamu adalah pengguna baru, kamu mendapatkan') ?></p>

                <?php if ($diskon_pengguna_baru['tipe_diskon'] == 'percentage'): ?>
                <h2 class="promo-text"><?= te('DISKON KHUSUS') ?> <?= $diskon_pengguna_baru['nilai_diskon'] ?>% 🔥</h2>
                <?php else: ?>
                <h2 class="promo-text"><?= te('DISKON KHUSUS') ?> Rp <?= number_format($diskon_pengguna_baru['nilai_diskon'], 0, ',', '.') ?> 🔥</h2>
                <?php endif; ?>

                <div class="voucher-info">
                    <div class="voucher-detail">
                        <span class="material-icons">confirmation_number</span>
                        <span><?= te('Kode:') ?> <strong><?= htmlspecialchars($diskon_pengguna_baru['kode_promo']) ?></strong></span>
                    </div>
                    <div class="voucher-detail">
                        <span class="material-icons">schedule</span>
                        <span id="daysText">
                            <?php if ($remaining_days == 1): ?>
                                <?= te('Voucher berlaku HARI INI SAJA!') ?>
                            <?php else: ?>
                                <?= te('Voucher berlaku') ?> <?= $remaining_days ?> <?= te('hari lagi') ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="voucher-detail">
                        <span class="material-icons">event</span>
                        <span>
                            <?= te('Berlaku hingga:') ?> <?= date('d M Y', strtotime($diskon_pengguna_baru['tanggal_berakhir'])) ?>
                        </span>
                    </div>
                    <?php if ($diskon_pengguna_baru['minimal_pembelian'] > 0): ?>
                    <div class="voucher-detail">
                        <span class="material-icons">shopping_cart</span>
                        <span><?= te('Min. pembelian:') ?> Rp <?= number_format($diskon_pengguna_baru['minimal_pembelian'], 0, ',', '.') ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($diskon_pengguna_baru['maksimal_diskon']): ?>
                    <div class="voucher-detail">
                        <span class="material-icons">trending_up</span>
                        <span><?= te('Maks. diskon:') ?> Rp <?= number_format($diskon_pengguna_baru['maksimal_diskon'], 0, ',', '.') ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="popup-instruction">
                    <p><strong><?= te('Cara pakai:') ?></strong> <?= te('Gunakan kode') ?> <strong><?= htmlspecialchars($diskon_pengguna_baru['kode_promo']) ?></strong> <?= te('saat checkout') ?></p>
                    <p class="small text-muted mb-0"><?= te('Voucher otomatis diterapkan untuk pemesanan pertama') ?></p>
                </div>

                <button class="popup-btn" onclick="closePopup()">
                    <span class="material-icons">check_circle</span>
                    <?= te('Oke, Mengerti!') ?>
                </button>

                <button class="popup-secondary-btn" onclick="window.location.href='hotel.php'">
                    <span class="material-icons">hotel</span>
                    <?= te('Cari Hotel') ?> <?= te('Sekarang') ?>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <?php // include 'wa.php'; 
        ?>
        <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
        <script src="../lib/wow/wow.min.js"></script>
        <script src="../lib/easing/easing.min.js"></script>
        <script src="../lib/waypoints/waypoints.min.js"></script>
        <script src="../lib/counterup/counterup.min.js"></script>
        <script src="../lib/owlcarousel/owl.carousel.min.js"></script>
        <script src="../lib/tempusdominus/js/moment.min.js"></script>
        <script src="../lib/tempusdominus/js/moment-timezone.min.js"></script>
        <script src="../lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script src="../js/main.js?v=2.0"></script>
        <script src="../js/tv-modern.js?v=<?= @filemtime(__DIR__ . '/../js/tv-modern.js') ?>"></script>

        <script>
            // HAPUS FUNGSI toggleDropdown() DAN document.addEventListener('click')
            // KARENA SUDAH DITANGANI OLEH BOOTSTRAP

            // Handle error pada gambar profil
            document.getElementById('profilePhoto').addEventListener('error', function() {
                this.src = '../img/default.jpg'; // Menggunakan '../img/default.jpg' agar konsisten
            });

            window.addEventListener('load', function() {
                const spinner = document.getElementById('spinner');
                if (spinner) {
                    spinner.classList.remove('show');
                    setTimeout(() => {
                        spinner.style.display = 'none';
                    }, 500);
                }
            });

            // Script untuk video modal (jika ada)
            const videoModal = document.getElementById('videoModal');
            if (videoModal) {
                const video = document.getElementById('video');
                videoModal.addEventListener('show.bs.modal', function(e) {
                    const button = e.relatedTarget;
                    const videoSrc = button.getAttribute('data-src');
                    video.setAttribute('src', videoSrc + "?autoplay=1&modestbranding=1&showinfo=0");
                });

                videoModal.addEventListener('hidden.bs.modal', function() {
                    video.setAttribute('src', '');
                });
            }

            // Fungsi WhatsApp
            function sendWhatsApp() {
                const phoneNumber = "6287806776235";
                const message = "<?= t('Halo TripVerse, saya ingin bertanya tentang...') ?>";
                const url = `https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`;
                window.open(url, '_blank');
            }
        </script>

        <script>
            // Data dari PHP
            const diskonData = <?= $diskon_data_json ?>;
            
            console.log('Diskon Data:', diskonData);
            
            // Fungsi untuk menutup popup
            function closePopup() {
                console.log('Closing popup for this session...');
                const popup = document.getElementById("newUserPopup");
                if (popup) {
                    popup.style.opacity = "0";
                    popup.style.transition = "opacity 0.3s ease";
                    setTimeout(() => {
                        popup.style.display = "none";
                    }, 300);
                }
                
                // Simpan di sessionStorage agar tidak muncul lagi dalam session ini
                sessionStorage.setItem('newUserPopupShownToday', 'true');
                
                // Juga simpan timestamp untuk hari ini
                const today = new Date().toDateString();
                localStorage.setItem('lastPopupDate', today);
            }

            // Tampilkan popup setelah halaman selesai load
            document.addEventListener('DOMContentLoaded', function() {
                console.log('DOM loaded. Checking popup conditions...');
                
                const popup = document.getElementById("newUserPopup");
                if (!popup) {
                    console.log('Popup not found (user not new or no discount data)');
                    return;
                }
                
                // Cek apakah user baru DAN ada data diskon
                if (diskonData.show_popup && diskonData.is_new_user && diskonData.remaining_days > 0) {
                    console.log(`User is new! Voucher valid for ${diskonData.remaining_days} more days.`);
                    
                    // Cek apakah sudah ditampilkan hari ini di session ini
                    const shownToday = sessionStorage.getItem('newUserPopupShownToday');
                    const lastPopupDate = localStorage.getItem('lastPopupDate');
                    const today = new Date().toDateString();
                    
                    console.log('Popup check:', {
                        shownToday,
                        lastPopupDate,
                        today
                    });
                    
                    // Tampilkan jika:
                    // 1. Belum ditampilkan di session ini, ATAU
                    // 2. Terakhir ditampilkan bukan hari ini
                    if (!shownToday || lastPopupDate !== today) {
                        console.log('Showing popup!');
                        
                        // Tampilkan popup
                        popup.style.display = 'flex';
                        
                        // Tambahkan delay untuk animasi
                        setTimeout(() => {
                            popup.style.opacity = '1';
                        }, 50);
                    } else {
                        console.log('Popup already shown today, skipping...');
                        popup.style.display = 'none';
                    }
                } else {
                    console.log('Not showing popup (voucher expired or not new user)');
                    if (popup) {
                        popup.style.display = 'none';
                    }
                }
            });

            // Optional: Tambahkan event listener untuk escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closePopup();
                }
            });
            
            // Reset sessionStorage ketika browser ditutup (agar besok muncul lagi)
            window.addEventListener('beforeunload', function() {
                // Hapus flag untuk session ini
                sessionStorage.removeItem('newUserPopupShownToday');
            });
        </script>

</body>

</html>