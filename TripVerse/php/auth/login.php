<?php
session_start();
require_once __DIR__ . '/../_lang.php';

// Include database connection
require __DIR__ . '/../connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['signIn'])) {
    $email = $_POST['email'];
    $password = $_POST['password'];

    // Cek user di database
    $query = "SELECT * FROM user WHERE email = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            // Jika user adalah supplier (owner), cek status approval
            if ($user['role'] == 'owner') {
                $approvedStatus = $user['approved'];

                // Jika status rejected, blokir login
                if ($approvedStatus == 'rejected' || $approvedStatus == 2) {
                    $_SESSION['error'] = t("Akun supplier Anda telah ditolak. Silakan hubungi administrator.");
                    header("Location: login.php");
                    exit;
                }

                // Jika status pending atau NULL, beri pesan informasi
                if ($approvedStatus === null || $approvedStatus == '' || $approvedStatus == 'pending' || $approvedStatus == 0) {
                    $_SESSION['pending_approval'] = true;
                    $_SESSION['pending_message'] = t("Akun supplier Anda sedang menunggu persetujuan. Anda mungkin memiliki akses terbatas hingga disetujui.");
                    header("Location: login.php");
                    exit;
                }
            }

            // Set session variables
            $_SESSION['id_user'] = $user['id_user'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['username'] = $user['username'];

            // Redirect berdasarkan role
            if ($user['role'] == 'admin') {
                header("Location: ../admin/dashboard.php");
            } elseif ($user['role'] == 'owner') {
                // Redirect ke halaman supplier/
                header("Location: ../supplier/owner_dashboard.php");
            } else {
                // Redirect ke halaman customer/
                header("Location: ../customer/home.php");
            }
            exit;
        } else {
            $_SESSION['error'] = t("Email atau password salah!");
            header("Location: login.php");
            exit;
        }
    } else {
        $_SESSION['error'] = t("Pengguna tidak ditemukan!");
        header("Location: login.php");
        exit;
    }

    $stmt->close();
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= te('Daftar & Masuk') ?> | TripVerse</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>" />
    <style>
        /* Reset dan Font */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Heebo', sans-serif;
        }

        /* Body dan Container Utama */
        body,
        html {
            height: 100%;
            width: 100%;
            background: radial-gradient(circle at 15% 20%, #1c2a4a 0%, #0F172B 45%, #0a0f1e 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            min-height: 100vh;
            position: relative;
            overflow: hidden;
        }

        .tv-aurora {
            position: fixed;
            border-radius: 50%;
            filter: blur(90px);
            z-index: 0;
            pointer-events: none;
            animation: tv-float 9s ease-in-out infinite;
        }

        .tv-aurora-1 {
            width: 480px;
            height: 480px;
            top: -120px;
            left: -100px;
            background: radial-gradient(circle, rgba(254, 161, 22, .55) 0%, rgba(254, 161, 22, 0) 70%);
        }

        .tv-aurora-2 {
            width: 520px;
            height: 520px;
            bottom: -160px;
            right: -140px;
            background: radial-gradient(circle, rgba(255, 122, 61, .45) 0%, rgba(255, 122, 61, 0) 70%);
            animation-delay: -3s;
        }

        .tv-aurora-3 {
            width: 380px;
            height: 380px;
            top: 40%;
            right: 8%;
            background: radial-gradient(circle, rgba(59, 130, 246, .30) 0%, rgba(59, 130, 246, 0) 70%);
            animation-delay: -5.5s;
        }

        body > * {
            position: relative;
            z-index: 1;
        }

        /* Error message styling */
        .error-message {
            background-color: #ffebee;
            border: 1px solid #ffcdd2;
            color: #DC2626;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.5s ease;
        }

        .error-message i {
            font-size: 18px;
        }

        .success-message {
            background-color: #e8f5e9;
            border: 1px solid #c8e6c9;
            color: #16A34A;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.5s ease;
        }

        .success-message i {
            font-size: 18px;
        }

        .info-message {
            background-color: #e3f2fd;
            border: 1px solid #bbdefb;
            color: #1565c0;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.5s ease;
        }

        .info-message i {
            font-size: 18px;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .container {
            background-color: rgba(255, 255, 255, 0.97);
            width: 100%;
            max-width: 480px;
            padding: 44px 34px;
            border-radius: 24px;
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.08);
            text-align: center;
            transition: all 0.3s ease-in-out;
            position: relative;
            overflow: hidden;
            animation: tv-card-in .7s cubic-bezier(.22, 1, .36, 1);
        }

        @keyframes tv-card-in {
            from {
                opacity: 0;
                transform: translateY(24px) scale(.96);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        #signupSupplier {
            max-width: 650px;
            overflow-y: auto;
            max-height: 90vh;
        }

        #signupSupplier::-webkit-scrollbar {
            width: 8px;
        }

        #signupSupplier::-webkit-scrollbar-track {
            background: transparent;
        }

        #signupSupplier::-webkit-scrollbar-thumb {
            background: #FEA116;
            border-radius: 10px;
        }

        #signupSupplier::-webkit-scrollbar-thumb:hover {
            background: #FEA116;
        }

        /* Animasi background */
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(135deg, #FEA116, #FF7A3D);
        }

        /* Judul Form */
        .form-title {
            font-size: 28px;
            font-weight: 700;
            color: #FEA116;
            margin-bottom: 30px;
            position: relative;
        }

        .form-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 3px;
            background: linear-gradient(135deg, #FEA116, #FF7A3D);
            border-radius: 3px;
        }

        /* Grup Input */
        .input-group {
            position: relative;
            margin-bottom: 20px;
            text-align: left;
        }

        .input-group label {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            display: block;
        }

        .input-group .required {
            color: #FEA116;
        }

        .input-group i {
            position: absolute;
            top: 50%;
            left: 12px;
            transform: translateY(-50%);
            color: #FEA116;
            transition: all 0.3s ease;
            font-size: 16px;
        }

        .input-group input,
        .input-group textarea {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            font-family: 'Heebo', sans-serif;
        }

        .input-group textarea {
            min-height: 70px;
            resize: vertical;
            padding: 12px 12px 12px 40px;
        }

        .input-group input:focus,
        .input-group textarea:focus {
            outline: none;
            border-color: #FEA116;
            box-shadow: 0 0 0 3px rgba(254, 161, 22, 0.1);
        }

        .input-group input:focus+i,
        .input-group textarea:focus+i {
            color: #E8890A;
            transform: translateY(-50%) scale(1.1);
        }

        /* Toggle Password Eye Icon. Needs to out-rank ".input-group i"
           (class + element beats a lone class) or the leading-icon rules
           pin it to the left as well, stretching it across the field. */
        .input-group i.toggle-password {
            position: absolute;
            left: auto;
            right: 12px;
            top: 50%;
            width: auto;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            z-index: 2;
            font-size: 16px;
        }

        .input-group i.toggle-password:hover,
        .input-group i.toggle-password.active {
            color: #FEA116;
        }

        /* leave room for the eye so long values don't slide under it */
        .input-group input[type="password"] {
            padding-right: 42px;
        }

        /* Tombol Submit */
        .btn {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: .01em;
            border: none;
            border-radius: 999px;
            background: linear-gradient(135deg, #FEA116 0%, #FF7A3D 100%);
            color: white;
            cursor: pointer;
            transition: transform .3s cubic-bezier(.22, 1, .36, 1), box-shadow .3s ease, filter .3s ease;
            margin-top: 10px;
            box-shadow: 0 10px 24px rgba(254, 161, 22, 0.35);
        }

        .btn:hover {
            filter: brightness(1.06);
            box-shadow: 0 14px 30px rgba(254, 161, 22, 0.5);
            transform: translateY(-3px);
        }

        .btn:active {
            transform: translateY(-1px) scale(.98);
        }

        /* Link Sign In/Up */
        .links {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 25px;
            font-size: 14px;
            color: #666;
        }

        .links button {
            color: #FEA116;
            border: none;
            background-color: transparent;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 5px;
            border-radius: 4px;
        }

        .links button:hover {
            color: #E8890A;
            background-color: rgba(254, 161, 22, 0.1);
            text-decoration: none;
        }

        /* Logo TripVerse */
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            margin-bottom: 20px;
        }

        .logo img {
            height: 40px;
            transition: transform 0.3s ease;
        }

        .logo:hover img {
            transform: rotate(-15deg);
        }

        .logo h3 {
            font-weight: 700;
            font-size: 28px;
            background: linear-gradient(90deg, #0F172B, #FEA116);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo h3 span {
            background: linear-gradient(135deg, #FEA116, #FF7A3D);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Password Strength Meter */
        .password-strength {
            width: 100%;
            height: 5px;
            background-color: #eee;
            border-radius: 5px;
            margin-top: 5px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        /* Password Requirements */
        .password-requirements {
            margin: 15px 0 5px 0;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 8px;
            list-style: none;
            font-size: 13px;
            color: #666;
            text-align: left;
            border: 1px solid #eee;
        }

        .password-requirements li {
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
        }

        .password-requirements li i {
            margin-right: 8px;
            font-size: 12px;
            transition: all 0.3s ease;
        }

        .requirement-met {
            color: #16A34A;
        }

        .requirement-not-met {
            color: #DC2626;
        }

        /* Pilihan User Type */
        .user-type {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
            gap: 10px;
        }

        .user-type-option {
            flex: 1;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .user-type-option:hover {
            border-color: #FEA116;
        }

        .user-type-option.selected {
            border-color: #FEA116;
            background-color: rgba(254, 161, 22, 0.05);
        }

        .user-type-option i {
            font-size: 24px;
            margin-bottom: 8px;
            color: #FEA116;
        }

        .user-type-option p {
            font-size: 14px;
            font-weight: 500;
            margin: 0;
        }

        .user-type-option p:last-child {
            font-size: 12px;
            color: #666;
            font-weight: 400;
        }

        /* Form Layout Grid untuk Supplier */
        .form-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .input-group.form-full {
            grid-column: 1 / -1;
        }

        /* Form Divider */
        .form-divider {
            grid-column: 1 / -1;
            margin: 15px 0;
            padding: 12px 0;
            border-top: none;
            border-bottom: 2px solid #f3f4f6;
            font-size: 13px;
            font-weight: 700;
            color: #1f2937;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-divider i {
            color: #FEA116;
            font-size: 16px;
        }

        /* Form Subtitle */
        .form-subtitle {
            font-size: 14px;
            color: #666;
            margin-bottom: 25px;
        }

        /* Responsive adjustments */
        @media (max-width: 650px) {
            .form-layout {
                grid-template-columns: 1fr;
            }

            #signupSupplier {
                max-width: 100%;
                padding: 30px 20px;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 30px 20px;
            }

            .form-title {
                font-size: 24px;
                margin-bottom: 20px;
            }

            .logo h3 {
                font-size: 24px;
            }

            .user-type {
                flex-direction: column;
            }
        }

        /* Wrapper untuk OTP */
        .otp-input-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin-top: 20px;
        }

        /* Kotak OTP */
        .otp-field {
            width: 48px;
            height: 55px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 26px;
            text-align: center;
            font-weight: bold;
            background: #fff;
            transition: all 0.2s ease-in-out;
        }

        .otp-field:focus {
            border-color: #FEA116;
            box-shadow: 0 0 8px rgba(254, 161, 22, 0.3);
            outline: none;
            transform: scale(1.05);
        }

        @media (max-width: 400px) {
            .otp-field {
                width: 40px;
                height: 48px;
                font-size: 22px;
            }
        }
    </style>
</head>

<body>
    <div class="tv-aurora tv-aurora-1"></div>
    <div class="tv-aurora tv-aurora-2"></div>
    <div class="tv-aurora tv-aurora-3"></div>

    <!-- Login Container -->
    <div class="container" id="signIn">
        <div class="logo">
            <img src="../../img/logo.png" alt="TripVerse Logo" />
            <h3>Trip<span>Verse</span></h3>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($_SESSION['error']);
                unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['pending_approval'])): ?>
            <div class="info-message">
                <i class="fas fa-info-circle"></i>
                <?php
                echo isset($_SESSION['pending_message']) ? htmlspecialchars($_SESSION['pending_message']) :
                    t("Akun supplier Anda sedang menunggu persetujuan. Anda mungkin memiliki akses terbatas hingga disetujui.");
                unset($_SESSION['pending_approval']);
                unset($_SESSION['pending_message']);
                ?>
            </div>
        <?php endif; ?>

        <h1 class="form-title"><?= te('Masuk') ?></h1>
        <form id="loginForm" method="post" action="login.php">
            <div class="input-group">
                <i class="fas fa-envelope"></i>
                <input type="email" name="email" id="emailLogin" placeholder="<?= te('Email') ?>" required />
            </div>

            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" id="passwordLogin" placeholder="<?= te('Password') ?>" required />
                <i class="fas fa-eye toggle-password" role="button" tabindex="0"
                    aria-label="<?= te('Tampilkan password') ?>"
                    onclick="togglePassword('passwordLogin', this)"></i>
            </div>

            <input type="submit" class="btn" value="<?= te('Masuk') ?>" name="signIn" />
        </form>

        <div class="links">
            <p><?= te('Belum punya akun?') ?></p>
            <button id="signUpButton"><?= te('Daftar') ?></button>
        </div>

        <!-- Forgot Password Link -->
        <div style="text-align: center; margin-top: 15px;">
            <a href="forgot_password.php" style="color: #FEA116; text-decoration: none; font-size: 14px;">
                <i class="fas fa-key"></i> <?= te('Lupa Password?') ?>
            </a>
        </div>
    </div>

    <!-- Register Container - Customer -->
    <div class="container" id="signupCustomer" style="display: none;">
        <div class="logo">
            <img src="../../img/logo.png" alt="TripVerse Logo" />
            <h3>Trip<span>Verse</span></h3>
        </div>

        <h1 class="form-title"><?= te('Daftar Sebagai Customer') ?></h1>
        <form id="registerCustomerForm" method="post" action="register.php">
            <input type="hidden" name="signUp" value="1">

            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="fName" id="fNameCustomer" placeholder="<?= te('Nama Depan') ?>" required />
            </div>

            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="lName" id="lNameCustomer" placeholder="<?= te('Nama Belakang') ?>" />
            </div>

            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="uName" id="uNameCustomer" placeholder="<?= te('Username') ?>" required />
            </div>

            <div class="input-group">
                <i class="fas fa-envelope"></i>
                <input type="email" name="email" id="emailCustomer" placeholder="<?= te('Email') ?>" required />
            </div>

            <div class="input-group">
                <i class="fas fa-phone"></i>
                <input type="text"
                    name="no_hp"
                    id="phoneCustomer"
                    placeholder="+628123456789"
                    pattern="\+62[0-9]{8,11}"
                    title="<?= te('Nomor harus diawali +62') ?>"
                    required />
            </div>

            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" id="passwordCustomer" placeholder="<?= te('Password') ?>" required />
                <i class="fas fa-eye toggle-password" role="button" tabindex="0"
                    aria-label="<?= te('Tampilkan password') ?>"
                    onclick="togglePassword('passwordCustomer', this)"></i>
                <div class="password-strength">
                    <div class="password-strength-bar" id="passwordStrengthBarCustomer"></div>
                </div>
            </div>

            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="confirmPassword" id="confirmPasswordCustomer" placeholder="<?= te('Konfirmasi Password') ?>" required />
                <i class="fas fa-eye toggle-password" role="button" tabindex="0"
                    aria-label="<?= te('Tampilkan password') ?>"
                    onclick="togglePassword('confirmPasswordCustomer', this)"></i>
            </div>

            <input type="hidden" name="userType" value="customer" />

            <button type="button" class="btn" id="openOtpPopupCustomer"><?= te('Daftar Sebagai Customer') ?></button>
        </form>

        <div class="links">
            <p><?= te('Sudah punya akun?') ?></p>
            <button id="signInButtonCustomer"><?= te('Masuk') ?></button>
        </div>
    </div>

    <!-- ========== OTP PAGE ========== -->
    <div class="container" id="otpPageCustomer" style="display:none;">
        <div class="logo">
            <img src="../../img/logo.png" alt="TripVerse Logo" />
            <h3>Trip<span>Verse</span></h3>
        </div>

        <h1 class="form-title"><?= te('Verifikasi OTP') ?></h1>
        <p style="color:#666; margin-bottom:20px;">
            <?= te('Kami mengirim kode 6 digit ke Email Anda.') ?>
        </p>

        <button id="requestOtpBtnCustomer" class="btn" style="margin-bottom:15px;">
            <?= te('Minta OTP') ?>
        </button>

        <div class="otp-input-wrapper">
            <input type="text" maxlength="1" class="otp-field">
            <input type="text" maxlength="1" class="otp-field">
            <input type="text" maxlength="1" class="otp-field">
            <input type="text" maxlength="1" class="otp-field">
            <input type="text" maxlength="1" class="otp-field">
            <input type="text" maxlength="1" class="otp-field">
        </div>

        <button class="btn" id="verifyOtpBtnCustomer" style="margin-top:20px;">
            <?= te('Verifikasi OTP') ?>
        </button>
    </div>

    <!-- Register Container - Supplier -->
    <div class="container" id="signupSupplier" style="display: none;">
        <div class="logo">
            <img src="../../img/logo.png" alt="TripVerse Logo" />
            <h3>Trip<span>Verse</span></h3>
        </div>

        <h1 class="form-title"><?= te('Daftar Supplier') ?></h1>
        <p class="form-subtitle"><?= te('Bergabunglah sebagai mitra bisnis profesional kami') ?></p>

        <div class="links">
            <p><?= te('Sudah punya akun?') ?></p>
            <button id="signInButtonSupplier"><?= te('Masuk di sini') ?></button>
        </div>
    </div>

    <!-- User Type Selection Container -->
    <div class="container" id="userTypeSelection" style="display: none;">
        <div class="logo">
            <img src="../../img/logo.png" alt="TripVerse Logo" />
            <h3>Trip<span>Verse</span></h3>
        </div>

        <h1 class="form-title"><?= te('Pilih Tipe Akun') ?></h1>

        <div class="user-type">
            <div class="user-type-option" id="customerOption">
                <i class="fas fa-user"></i>
                <p><?= te('Customer') ?></p>
                <p><?= te('Pesan perjalanan dan pengalaman') ?></p>
            </div>

            <a href="register_supplier.php" style="text-decoration: none; color: inherit;">
                <div class="user-type-option" id="supplierOption">
                    <i class="fas fa-store"></i>
                    <p><?= te('Supplier') ?></p>
                    <p><?= te('Tawarkan layanan perjalanan') ?></p>
                </div>
            </a>

        </div>

        <div class="links">
            <p><?= te('Sudah punya akun?') ?></p>
            <button id="signInButtonFromSelection"><?= te('Masuk') ?></button>
        </div>
    </div>

    <script>
        // Toggle Password Visibility
        function togglePassword(inputId, iconElement) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                iconElement.classList.remove('fa-eye');
                iconElement.classList.add('fa-eye-slash');
                iconElement.classList.add('active');
            } else {
                input.type = 'password';
                iconElement.classList.remove('fa-eye-slash');
                iconElement.classList.add('fa-eye');
                iconElement.classList.remove('active');
            }
        }

        // Toggle between forms
        document.getElementById('signUpButton').addEventListener('click', function() {
            document.getElementById('signIn').style.display = 'none';
            document.getElementById('userTypeSelection').style.display = 'block';
        });

        document.getElementById('signInButtonCustomer').addEventListener('click', function() {
            document.getElementById('signupCustomer').style.display = 'none';
            document.getElementById('signIn').style.display = 'block';
        });

        document.getElementById('signInButtonSupplier').addEventListener('click', function() {
            document.getElementById('signupSupplier').style.display = 'none';
            document.getElementById('signIn').style.display = 'block';
        });

        document.getElementById('signInButtonFromSelection').addEventListener('click', function() {
            document.getElementById('userTypeSelection').style.display = 'none';
            document.getElementById('signIn').style.display = 'block';
        });

        // User type selection
        document.getElementById('customerOption').addEventListener('click', function() {
            document.getElementById('userTypeSelection').style.display = 'none';
            document.getElementById('signupCustomer').style.display = 'block';
        });

        document.getElementById('supplierOption').addEventListener('click', function() {
            document.getElementById('userTypeSelection').style.display = 'none';
            document.getElementById('signupSupplier').style.display = 'block';
        });

        // Password strength indicator functions
        function checkPasswordStrength(password, strengthBarId, requirementsPrefix = '') {
            const strengthBar = document.getElementById(strengthBarId);
            let strength = 0;

            // Check length
            const hasLength = password.length >= 8;
            if (hasLength) strength += 1;
            if (requirementsPrefix) {
                const lengthIcon = document.getElementById(`${requirementsPrefix}lengthRequirement`);
                if (lengthIcon) lengthIcon.className = hasLength ? 'fas fa-check-circle requirement-met' : 'fas fa-circle requirement-not-met';
            }

            // Check for uppercase
            const hasUppercase = /[A-Z]/.test(password);
            if (hasUppercase) strength += 1;
            if (requirementsPrefix) {
                const uppercaseIcon = document.getElementById(`${requirementsPrefix}uppercaseRequirement`);
                if (uppercaseIcon) uppercaseIcon.className = hasUppercase ? 'fas fa-check-circle requirement-met' : 'fas fa-circle requirement-not-met';
            }

            // Check for numbers
            const hasNumber = /[0-9]/.test(password);
            if (hasNumber) strength += 1;
            if (requirementsPrefix) {
                const numberIcon = document.getElementById(`${requirementsPrefix}numberRequirement`);
                if (numberIcon) numberIcon.className = hasNumber ? 'fas fa-check-circle requirement-met' : 'fas fa-circle requirement-not-met';
            }

            // Check for special chars
            const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
            if (hasSpecial) strength += 1;
            if (requirementsPrefix) {
                const specialIcon = document.getElementById(`${requirementsPrefix}specialRequirement`);
                if (specialIcon) specialIcon.className = hasSpecial ? 'fas fa-check-circle requirement-met' : 'fas fa-circle requirement-not-met';
            }

            // Update strength bar
            switch (strength) {
                case 0:
                case 1:
                    strengthBar.style.width = '25%';
                    strengthBar.style.backgroundColor = '#DC2626';
                    break;
                case 2:
                    strengthBar.style.width = '50%';
                    strengthBar.style.backgroundColor = '#fd7e14';
                    break;
                case 3:
                    strengthBar.style.width = '75%';
                    strengthBar.style.backgroundColor = '#ffc107';
                    break;
                case 4:
                    strengthBar.style.width = '100%';
                    strengthBar.style.backgroundColor = '#16A34A';
                    break;
            }

            return strength === 4;
        }

        function checkPasswordStrengthSupplier(password) {
            const strengthBar = document.getElementById('passwordStrengthBarSupplier');
            let strength = 0;

            if (password.length >= 6) strength += 1;
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) strength += 1;

            switch (strength) {
                case 0:
                case 1:
                    strengthBar.style.width = '25%';
                    strengthBar.style.backgroundColor = '#DC2626';
                    break;
                case 2:
                    strengthBar.style.width = '50%';
                    strengthBar.style.backgroundColor = '#fd7e14';
                    break;
                case 3:
                    strengthBar.style.width = '75%';
                    strengthBar.style.backgroundColor = '#ffc107';
                    break;
                case 4:
                    strengthBar.style.width = '100%';
                    strengthBar.style.backgroundColor = '#16A34A';
                    break;
            }
        }

        // Event listeners for password fields
        document.getElementById('passwordCustomer').addEventListener('input', function(e) {
            checkPasswordStrength(e.target.value, 'passwordStrengthBarCustomer', 'Customer');
        });

        if (document.getElementById('passwordSupplier')) {
            document.getElementById('passwordSupplier').addEventListener('input', function(e) {
                checkPasswordStrengthSupplier(e.target.value);
            });
        }

        // Form validation for customer registration
        document.getElementById('registerCustomerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('passwordCustomer').value;
            const confirmPassword = document.getElementById('confirmPasswordCustomer').value;

            if (password !== confirmPassword) {
                e.preventDefault();
                alert('<?= t('Password tidak cocok!') ?>');
                return false;
            }

            const isStrongPassword = checkPasswordStrength(password, 'passwordStrengthBarCustomer', 'Customer');
            if (!isStrongPassword) {
                e.preventDefault();
                alert('<?= t('Password tidak memenuhi semua persyaratan!') ?>');
                return false;
            }

            return true;
        });

        // Form validation for supplier registration
        if (document.getElementById('registerSupplierForm')) {
            document.getElementById('registerSupplierForm').addEventListener('submit', function(e) {
                const password = document.getElementById('passwordSupplier').value;
                const confirmPassword = document.getElementById('confirmPasswordSupplier').value;
                const phone = document.getElementById('phoneSupplier').value;

                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('<?= t('Password dan Konfirmasi Password tidak cocok!') ?>');
                    return false;
                }

                if (password.length < 6) {
                    e.preventDefault();
                    alert('<?= t('Password harus minimal 6 karakter!') ?>');
                    return false;
                }

                if (phone.length < 10) {
                    e.preventDefault();
                    alert('<?= t('Nomor telepon harus minimal 10 digit!') ?>');
                    return false;
                }

                return true;
            });
        }

        // Phone number format for customer
        document.getElementById("phoneCustomer").addEventListener("input", function() {
            let v = this.value.replace(/[^0-9]/g, "");
            if (v.startsWith("0")) v = "62" + v.slice(1);
            if (!v.startsWith("62")) v = "62" + v;
            let angka = v.substring(2, 13);
            this.value = "+62" + angka;
        });

        // ========== OTP FUNCTIONALITY ==========
        document.getElementById("openOtpPopupCustomer").addEventListener("click", () => {
            if (document.getElementById("phoneCustomer").value.trim() === "") {
                alert("<?= t('Nomor telepon wajib diisi.') ?>");
                return;
            }
            if (document.getElementById("emailCustomer").value.trim() === "") {
                alert("<?= t('Email wajib diisi.') ?>");
                return;
            }
            document.getElementById("signupCustomer").style.display = "none";
            document.getElementById("otpPageCustomer").style.display = "block";
        });

        document.getElementById("requestOtpBtnCustomer").addEventListener("click", () => {
            const email = document.getElementById("emailCustomer").value;
            fetch("send_otp_register.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "email=" + encodeURIComponent(email)
                })
                .then(res => res.text())
                .then(result => {
                    if (result === "sent") {
                        alert("<?= t('OTP terkirim ke Email Anda!') ?>");
                    } else {
                        alert("<?= t('Gagal mengirim OTP. Silakan coba lagi.') ?>");
                    }
                });
        });

        // OTP Auto Focus
        const otpFields = document.querySelectorAll(".otp-field");
        otpFields.forEach((fld, i) => {
            fld.addEventListener("input", () => {
                fld.value = fld.value.replace(/[^0-9]/g, "");
                if (fld.value && i < otpFields.length - 1) otpFields[i + 1].focus();
            });
        });

        document.getElementById("verifyOtpBtnCustomer").addEventListener("click", () => {
            let otp = "";
            otpFields.forEach(f => otp += f.value);
            const email = document.getElementById("emailCustomer").value;

            fetch("verify_otp_register.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "email=" + encodeURIComponent(email) + "&otp=" + otp
                })
                .then(res => res.text())
                .then(result => {
                    if (result === "success") {
                        alert("<?= t('OTP valid! Membuat akun...') ?>");
                        document.getElementById("registerCustomerForm").submit();
                        setTimeout(() => {
                            window.location.href = "login.php";
                        }, 600);
                    } else if (result === "expired") {
                        alert("<?= t('OTP kedaluwarsa. Silakan minta lagi.') ?>");
                    } else {
                        alert("<?= t('OTP salah!') ?>");
                    }
                });
        });
    </script>
    <script src="../../js/tv-modern.js?v=<?= @filemtime(__DIR__ . '/../js/tv-modern.js') ?>"></script>
</body>

</html>