<?php
session_start();

// Redirect jika user belum login
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "tripverse";

// Create database connection
$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to cancel expired bookings
function cancelExpiredBookings($conn)
{
    $sql = "UPDATE booking_hotel SET status = 'Cancelled' 
            WHERE status = 'Pending' 
            AND TIMESTAMPDIFF(MINUTE, tanggal_booking, NOW()) > 2";

    return $conn->query($sql);
}

// Cancel any expired bookings first
cancelExpiredBookings($conn);

$user_id = $_SESSION['id_user'];
$bookings = [];
$error = null;

// Ambil parameter filter dari URL
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';

try {
    // Query dasar untuk mendapatkan booking hotel user
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

    // Tambahkan filter status jika bukan 'all'
    if ($filter_status != 'all') {
        $sql .= " AND bh.status = ?";
    }

    $sql .= " ORDER BY bh.check_in DESC";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    // Bind parameter berdasarkan filter
    if ($filter_status != 'all') {
        $stmt->bind_param("ss", $user_id, $filter_status);
    } else {
        $stmt->bind_param("s", $user_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Check if pending booking has expired (should be already handled by cancelExpiredBookings)
            if ($row['status'] == 'Pending' && $row['minutes_since_booking'] > 2) {
                $row['status'] = 'Cancelled';
            }
            $bookings[] = $row;
        }
    }
    $stmt->close();
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Pesanan - TripVerse</title>
    <!-- Favicon -->
    <link href="img/favicon.ico" rel="icon">

    <!-- Google Web Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap"
        rel="stylesheet">

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
    <link href="../css/style.css" rel="stylesheet">

    <style>
        .booking-card {
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            margin-bottom: 20px;
        }

        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
        }

        .hotel-img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 8px 8px 0 0;
        }

        .status-badge {
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 20px;
        }

        .status-completed {
            background-color: #28a745;
            color: white;
        }

        .status-pending {
            background-color: #ffc107;
            color: black;
        }

        .status-cancelled {
            background-color: #dc3545;
            color: white;
        }

        .status-expired {
            background-color: #6c757d;
            color: white;
        }

        .price-tag {
            font-weight: bold;
            color: #ff6b00;
        }

        .empty-state {
            text-align: center;
            padding: 50px 0;
        }

        .empty-icon {
            font-size: 5rem;
            color: #6c757d;
            margin-bottom: 20px;
        }

        .filter-btn {
            border-radius: 20px;
            margin-right: 8px;
            margin-bottom: 8px;
        }

        .filter-btn.active {
            background-color: #ff6b00;
            color: white;
            border-color: #ff6b00;
        }

        .time-remaining {
            font-size: 0.8rem;
            color: #dc3545;
            font-weight: bold;
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

        <!-- Header Start -->
        <header class="container-fluid bg-dark px-0">
            <div class="row gx-0">
                <!-- Logo -->
                <div class="col-lg-3 bg-dark d-none d-lg-flex align-items-center justify-content-center">
                    <a href="about.php" class="d-flex align-items-center text-decoration-none">
                        <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 50px;">
                        <h1 class="m-0 text-primary text-uppercase">TripVerse</h1>
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
                                <a href="riwayat.php" class="nav-item nav-link active">Riwayat</a>
                                <a href="logout.php" class="nav-item nav-link">Logout</a>
                            </div>
                            <?php if (isset($_SESSION['username'])): ?>
                                <span class="navbar-text fw-bold me-3"
                                    style="background: linear-gradient(to right, #FFA500, #FF6347);
                                    -webkit-background-clip: text; background-clip: text;
                                    -webkit-text-fill-color: transparent; text-decoration: underline;">
                                    Hi <?= htmlspecialchars($_SESSION['username']); ?>, selamat datang di TripVerse
                                </span>
                            <?php endif; ?>
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
                    <h1 class="display-3 text-white mb-3 animated slideInDown">Riwayat</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-center text-uppercase">
                            <li class="breadcrumb-item"><a href="#">Beranda</a></li>
                            <li class="breadcrumb-item text-white active" aria-current="page">Riwayat</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
        <!-- Page Header End -->

        <div class="container py-5">
            <div class="row mb-4">
                <div class="col">
                    <h2><i class="fas fa-history me-2"></i> Riwayat Pesanan Hotel</h2>
                    <p class="text-muted">Daftar semua pemesanan hotel yang pernah Anda lakukan</p>

                    <!-- Filter Status -->
                    <div class="d-flex flex-wrap mb-4">
                        <a href="riwayat.php?status=all"
                            class="btn btn-outline-secondary filter-btn <?= $filter_status == 'all' ? 'active' : '' ?>">
                            Semua
                        </a>
                        <a href="riwayat.php?status=Completed"
                            class="btn btn-outline-secondary filter-btn <?= $filter_status == 'Completed' ? 'active' : '' ?>">
                            <i class="fas fa-check-circle me-1"></i> Completed
                        </a>
                        <a href="riwayat.php?status=Pending"
                            class="btn btn-outline-secondary filter-btn <?= $filter_status == 'Pending' ? 'active' : '' ?>">
                            <i class="fas fa-clock me-1"></i> Pending
                        </a>
                        <a href="riwayat.php?status=Cancelled"
                            class="btn btn-outline-secondary filter-btn <?= $filter_status == 'Cancelled' ? 'active' : '' ?>">
                            <i class="fas fa-times-circle me-1"></i> Cancelled
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <i class="fas fa-hotel empty-icon"></i>
                    <h3>Belum Ada Riwayat Pesanan</h3>
                    <?php if ($filter_status != 'all'): ?>
                        <p class="text-muted">Tidak ada pesanan dengan status <?= htmlspecialchars($filter_status) ?></p>
                    <?php else: ?>
                        <p class="text-muted">Anda belum pernah melakukan pemesanan hotel melalui TripVerse</p>
                    <?php endif; ?>
                    <a href="hotel.php" class="btn btn-primary mt-3">
                        <i class="fas fa-search me-2"></i> Cari Hotel
                    </a>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($bookings as $booking): ?>
                        <?php
                        // Hitung durasi menginap
                        $check_in = new DateTime($booking['check_in']);
                        $check_out = new DateTime($booking['check_out']);
                        $durasi = $check_out->diff($check_in)->days;

                        // Format tanggal
                        $checkin_display = $check_in->format('d M Y');
                        $checkout_display = $check_out->format('d M Y');
                        $tanggal_pesan = date('d M Y H:i', strtotime($booking['tanggal_booking']));

                        // Tentukan kelas status
                        $status_class = '';
                        if ($booking['status'] == 'Completed') {
                            $status_class = 'status-completed';
                        } elseif ($booking['status'] == 'Pending') {
                            $status_class = 'status-pending';
                        } else {
                            $status_class = 'status-cancelled';
                        }

                        // Hitung waktu tersisa untuk pending bookings
                        $time_remaining = '';
                        if ($booking['status'] == 'Pending') {
                            $minutes_left = 2 - $booking['minutes_since_booking'];
                            if ($minutes_left > 0) {
                                $time_remaining = '<div class="time-remaining mt-2"><i class="fas fa-clock"></i> Waktu tersisa: ' . $minutes_left . ' menit</div>';
                            } else {
                                // This should have been caught by cancelExpiredBookings()
                                $booking['status'] = 'Cancelled';
                                $status_class = 'status-cancelled';
                            }
                        }
                        ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="booking-card bg-white">
                                <?php if (!empty($booking['foto_hotel'])): ?>
                                    <img src="../img/<?= htmlspecialchars($booking['foto_hotel']) ?>" class="hotel-img" alt="<?= htmlspecialchars($booking['nama_hotel']) ?>">
                                <?php else: ?>
                                    <div class="hotel-img bg-light d-flex align-items-center justify-content-center">
                                        <i class="fas fa-hotel fa-3x text-muted"></i>
                                    </div>
                                <?php endif; ?>

                                <div class="p-3">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h5><?= htmlspecialchars($booking['nama_hotel']) ?></h5>
                                        <span class="badge <?= $status_class ?>"><?= $booking['status'] ?></span>
                                    </div>

                                    <p class="text-muted mb-1">
                                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($booking['kota']) ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-bed"></i> <?= htmlspecialchars($booking['nama_tipe']) ?>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-calendar-alt"></i> <?= $checkin_display ?> - <?= $checkout_display ?>
                                        <small>(<?= $durasi ?> malam)</small>
                                    </p>
                                    <p class="mb-1">
                                        <i class="fas fa-door-open"></i> <?= $booking['jumlah_kamar'] ?> Kamar
                                    </p>

                                    <?php if ($booking['status'] == 'Pending' && !empty($time_remaining)): ?>
                                        <?= $time_remaining ?>
                                    <?php endif; ?>

                                    <hr>

                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <small class="text-muted">Tanggal Pesan:</small>
                                            <p class="mb-0"><?= $tanggal_pesan ?></p>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted">Total</small>
                                            <p class="mb-0 price-tag">Rp <?= number_format($booking['total_harga'], 0, ',', '.') ?></p>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between mt-3">
                                        <a href="booking_detail.php?id=<?= $booking['booking_id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-info-circle"></i> Detail
                                        </a>
                                        <?php if ($booking['status'] == 'Completed'): ?>
                                            <a href="booking_confirmation.php?booking_id=<?= $booking['booking_id'] ?>" class="btn btn-sm btn-outline-success">
                                                <i class="fas fa-receipt"></i> Invoice
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($booking['status'] == 'Pending'): ?>
                                            <a href="payment.php?booking_id=<?= $booking['booking_id'] ?>" class="btn btn-sm btn-outline-warning">
                                                <i class="fas fa-money-bill-wave"></i> Bayar
                                            </a>
                                            <a href="cancel_booking.php?id=<?= $booking['booking_id'] ?>" class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Apakah Anda yakin ingin membatalkan pesanan ini?')">
                                                <i class="fas fa-times"></i> Batalkan
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

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
                                <h1 class="text-white text-uppercase mb-0">TripVerse</h1>
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

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        <script>
            window.addEventListener('load', function() {
                const spinner = document.getElementById('spinner');
                if (spinner) {
                    spinner.classList.remove('show');
                }
            });
            // Auto-refresh page for pending bookings
            <?php if ($filter_status == 'Pending' || $filter_status == 'all'): ?>
                setTimeout(function() {
                    location.reload();
                }, 60000); // Refresh every 60 seconds to check for expired bookings
            <?php endif; ?>
        </script>
</body>

</html>
<?php
$conn->close();
?>