<?php
session_start();
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>TripVerse</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

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
    <link href="lib/animate/animate.min.css" rel="stylesheet">
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet" />

    <!-- Customized Bootstrap Stylesheet -->
    <link href="../css/bootstrap.min.css" rel="stylesheet">

    <!-- Template Stylesheet -->
    <link href="../css/style.css" rel="stylesheet">

    <!-- Template Stylesheet -->
    <link href="../css/team.css" rel="stylesheet">
</head>

<body>
    <div class="container-fluid" style="background-color: #ffffff;">
        <!-- Spinner Start -->
        <div id="spinner"
            class="show bg-white position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="sr-only">Loading...</span>
            </div>
        </div>
        <!-- Spinner End -->

        <!-- Header Start -->
        <div class="container-fluid bg-dark px-0">
            <div class="row gx-0">
                <!-- Logo & Brand -->
                <div class="col-lg-3 bg-dark d-none d-lg-flex align-items-center justify-content-center">
                    <a href="about.php" class="d-flex align-items-center text-decoration-none">
                        <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 50px;">
                        <h1 class="m-0 text-primary text-uppercase">TripVerse</h1>
                    </a>
                </div>

                <!-- Contact & Social Media -->
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

                    <!-- Navbar -->
                    <nav class="navbar navbar-expand-lg bg-dark navbar-dark p-3 p-lg-0">
                        <a href="home.php" class="navbar-brand d-block d-lg-none">
                            <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 40px;">
                            <h1 class="m-0 text-primary text-uppercase">TripVerse</h1>
                        </a>
                        <button type="button" class="navbar-toggler" data-bs-toggle="collapse"
                            data-bs-target="#navbarCollapse">
                            <span class="navbar-toggler-icon"></span>
                        </button>
                        <div class="collapse navbar-collapse justify-content-between" id="navbarCollapse">
                            <div class="navbar-nav mr-auto py-0">
                                <a href="home.php" class="nav-item nav-link">Beranda</a>
                                <a href="about.php" class="nav-item nav-link">Tentang Kami</a>
                                <a href="hotel.php" class="nav-item nav-link">Hotel</a>
                                <a href="service.php" class="nav-item nav-link">Fitur</a>
                                <a href="team.php" class="nav-item nav-link active">Tim Kami</a>
                                <a href="contact.php" class="nav-item nav-link">Kontak</a>
                                <a href="riwayat.php" class="nav-item nav-link">Riwayat</a>
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
        </div>
        <!-- Header End -->

        <!-- Page Header Start -->
        <div class="container-fluid page-header mb-5 p-0" style="background-image: url(../img/carousel-1.jpg);">
            <div class="container-fluid page-header-inner py-5">
                <div class="container text-center pb-5">
                    <h1 class="display-3 text-white mb-3 animated slideInDown">Tim Kami</h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-center text-uppercase">
                            <li class="breadcrumb-item"><a href="home.php">Beranda</a></li>
                            <li class="breadcrumb-item text-white active" aria-current="page">Tim Kami</li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>
        <!-- Page Header End -->

        <!-- Team Start -->
        <div class="container-xxl py-5">
            <div class="container">
                <div class="text-center wow fadeInUp" data-wow-delay="0.1s">
                    <h6 class="section-title text-center text-primary text-uppercase">Tim Kami</h6>
                    <h1 class="mb-5">Temui <span class="text-primary text-uppercase">Tim</span> Kami</h1>
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
        <!-- Team End -->

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
                                <h1 class="text-white text-uppercase mb-0">TripVerse</h1>
                            </a>
                        </div>
                    </div>

                    <!-- Contact Information -->
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

                    <!-- Company & Services -->
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
                                <a class="btn btn-link" href="#">Flight Tickets</a>
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
    </div>
    <!-- Footer End -->

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="lib/wow/wow.min.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>
    <script src="lib/counterup/counterup.min.js"></script>
    <script src="lib/owlcarousel/owl.carousel.min.js"></script>
    <script src="lib/tempusdominus/js/moment.min.js"></script>
    <script src="lib/tempusdominus/js/moment-timezone.min.js"></script>
    <script src="lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>

    <!-- Template Javascript -->
    <script src="js/main.js"></script>

    <script>
        window.addEventListener('load', function() {
            const spinner = document.getElementById('spinner');
            if (spinner) {
                spinner.classList.remove('show');
            }
        });
    </script>

</body>

</html>