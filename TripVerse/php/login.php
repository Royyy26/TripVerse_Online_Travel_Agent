<?php
session_start();

// Include database connection
require 'connect.php';

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

        // Verify password (plain text comparison for now, consider using password_verify() for hashed passwords)
        if ($password === $user['password']) {
            // Jika user adalah supplier (owner), cek status approval
            if ($user['role'] == 'owner') {
                $approvedStatus = $user['approved'];

                // Jika status rejected, blokir login
                if ($approvedStatus == 'rejected' || $approvedStatus == 2) {
                    $_SESSION['error'] = "Your supplier account has been rejected. Please contact administrator.";
                    header("Location: login.php");
                    exit;
                }

                // Jika status pending atau NULL, beri pesan informasi
                if ($approvedStatus === null || $approvedStatus == '' || $approvedStatus == 'pending' || $approvedStatus == 0) {
                    $_SESSION['pending_approval'] = true;
                    $_SESSION['pending_message'] = "Your supplier account is pending approval. You may have limited access until approved.";
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
                header("Location: dashboard.php");
            } elseif ($user['role'] == 'owner') {
                // Redirect ke halaman supplier/dashboard.php
                header("Location: owner_dashboard.php");
            } else {
                // Redirect ke halaman user/dashboard.php atau home.php
                header("Location: home.php");
            }
            exit;
        } else {
            $_SESSION['error'] = "Invalid email or password!";
            header("Location: login.php");
            exit;
        }
    } else {
        $_SESSION['error'] = "User not found!";
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
    <title>Register & Login | TripVerse</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet" />
    <style>
        /* Reset dan Font */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        /* Body dan Container Utama */
        body,
        html {
            height: 100%;
            width: 100%;
            background: linear-gradient(135deg, #fff5e6 0%, #ffe0b3 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            min-height: 100vh;
        }

        /* Error message styling */
        .error-message {
            background-color: #ffebee;
            border: 1px solid #ffcdd2;
            color: #c62828;
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
            color: #2e7d32;
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
            background-color: white;
            width: 100%;
            max-width: 480px;
            padding: 40px 30px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            text-align: center;
            transition: all 0.3s ease-in-out;
            position: relative;
            overflow: hidden;
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
            background: #ff6600;
            border-radius: 10px;
        }

        #signupSupplier::-webkit-scrollbar-thumb:hover {
            background: #ff9900;
        }

        /* Animasi background */
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(to right, #ff9900, #ff6600);
        }

        /* Judul Form */
        .form-title {
            font-size: 28px;
            font-weight: 700;
            color: #ff6600;
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
            background: linear-gradient(to right, #ff9900, #ff6600);
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
            color: #ff6600;
        }

        .input-group i {
            position: absolute;
            top: 50%;
            left: 12px;
            transform: translateY(-50%);
            color: #ff6600;
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
            font-family: 'Poppins', sans-serif;
        }

        .input-group textarea {
            min-height: 70px;
            resize: vertical;
            padding: 12px 12px 12px 40px;
        }

        .input-group input:focus,
        .input-group textarea:focus {
            outline: none;
            border-color: #ff6600;
            box-shadow: 0 0 0 3px rgba(255, 102, 0, 0.1);
        }

        .input-group input:focus+i,
        .input-group textarea:focus+i {
            color: #ff3300;
            transform: translateY(-50%) scale(1.1);
        }

        /* Toggle Password Eye Icon */
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            z-index: 2;
            font-size: 16px;
        }

        .toggle-password:hover {
            color: #ff6600;
        }

        .toggle-password.active {
            color: #ff6600;
        }

        /* Tombol Submit */
        .btn {
            width: 100%;
            padding: 14px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            background: linear-gradient(to right, #ff9900, #ff6600);
            color: white;
            cursor: pointer;
            transition: all 0.4s ease;
            margin-top: 10px;
            box-shadow: 0 4px 6px rgba(255, 102, 0, 0.2);
        }

        .btn:hover {
            background: linear-gradient(to right, #ff6600, #ff9900);
            box-shadow: 0 6px 8px rgba(255, 102, 0, 0.3);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: translateY(0);
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
            color: #ff6600;
            border: none;
            background-color: transparent;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            padding: 5px;
            border-radius: 4px;
        }

        .links button:hover {
            color: #e65100;
            background-color: rgba(255, 102, 0, 0.1);
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
            background: linear-gradient(to right, #000000, #ff6600);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .logo h3 span {
            background: linear-gradient(to right, #ff6600, #ffaa33);
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
            color: #28a745;
        }

        .requirement-not-met {
            color: #dc3545;
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
            border-color: #ff9900;
        }

        .user-type-option.selected {
            border-color: #ff6600;
            background-color: rgba(255, 102, 0, 0.05);
        }

        .user-type-option i {
            font-size: 24px;
            margin-bottom: 8px;
            color: #ff6600;
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
            color: #ff6600;
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
            border-color: #ff6600;
            box-shadow: 0 0 8px rgba(255, 102, 0, 0.3);
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
    <!-- Login Container -->
    <div class="container" id="signIn">
        <div class="logo">
            <img src="../img/logo.png" alt="TripVerse Logo" />
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
                    "Your supplier account is pending approval. You may have limited access until approved.";
                unset($_SESSION['pending_approval']);
                unset($_SESSION['pending_message']);
                ?>
            </div>
        <?php endif; ?>

        <h1 class="form-title">Sign In</h1>
        <form id="loginForm" method="post" action="login.php">
            <div class="input-group">
                <i class="fas fa-envelope"></i>
                <input type="email" name="email" id="emailLogin" placeholder="Email" required />
            </div>

            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" id="passwordLogin" placeholder="Password" required />
            </div>

            <input type="submit" class="btn" value="Sign In" name="signIn" />
        </form>

        <div class="links">
            <p>Don't have an account yet?</p>
            <button id="signUpButton">Sign Up</button>
        </div>

        <!-- Forgot Password Link -->
        <div style="text-align: center; margin-top: 15px;">
            <a href="forgot_password.php" style="color: #ff6600; text-decoration: none; font-size: 14px;">
                <i class="fas fa-key"></i> Forgot Password?
            </a>
        </div>
    </div>

    <!-- Register Container - Customer -->
    <div class="container" id="signupCustomer" style="display: none;">
        <div class="logo">
            <img src="../img/logo.png" alt="TripVerse Logo" />
            <h3>Trip<span>Verse</span></h3>
        </div>

        <h1 class="form-title">Register as Customer</h1>
        <form id="registerCustomerForm" method="post" action="register.php">
            <input type="hidden" name="signUp" value="1">

            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="fName" id="fNameCustomer" placeholder="First Name" required />
            </div>

            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="lName" id="lNameCustomer" placeholder="Last Name" />
            </div>

            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="uName" id="uNameCustomer" placeholder="Username" required />
            </div>

            <div class="input-group">
                <i class="fas fa-envelope"></i>
                <input type="email" name="email" id="emailCustomer" placeholder="Email" required />
            </div>

            <div class="input-group">
                <i class="fas fa-phone"></i>
                <input type="text"
                    name="no_hp"
                    id="phoneCustomer"
                    placeholder="+628123456789"
                    pattern="\+62[0-9]{8,11}"
                    title="Nomor harus diawali +62"
                    required />
            </div>

            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" id="passwordCustomer" placeholder="Password" required />
                <div class="password-strength">
                    <div class="password-strength-bar" id="passwordStrengthBarCustomer"></div>
                </div>
            </div>

            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="confirmPassword" id="confirmPasswordCustomer" placeholder="Confirm Password" required />
            </div>

            <input type="hidden" name="userType" value="customer" />

            <button type="button" class="btn" id="openOtpPopupCustomer">Sign Up as Customer</button>
        </form>

        <div class="links">
            <p>Already have an account?</p>
            <button id="signInButtonCustomer">Sign In</button>
        </div>
    </div>

    <!-- ========== OTP PAGE ========== -->
    <div class="container" id="otpPageCustomer" style="display:none;">
        <div class="logo">
            <img src="../img/logo.png" alt="TripVerse Logo" />
            <h3>Trip<span>Verse</span></h3>
        </div>

        <h1 class="form-title">Verify OTP</h1>
        <p style="color:#666; margin-bottom:20px;">
            We sent a 6-digit code to your WhatsApp.
        </p>

        <button id="requestOtpBtnCustomer" class="btn" style="margin-bottom:15px;">
            Request OTP
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
            Verify OTP
        </button>
    </div>

    <!-- Register Container - Supplier -->
    <div class="container" id="signupSupplier" style="display: none;">
        <div class="logo">
            <img src="../img/logo.png" alt="TripVerse Logo" />
            <h3>Trip<span>Verse</span></h3>
        </div>

        <h1 class="form-title">Daftar Supplier</h1>
        <p class="form-subtitle">Bergabunglah sebagai mitra bisnis profesional kami</p>

        <div class="links">
            <p>Sudah punya akun?</p>
            <button id="signInButtonSupplier">Masuk di sini</button>
        </div>
    </div>

    <!-- User Type Selection Container -->
    <div class="container" id="userTypeSelection" style="display: none;">
        <div class="logo">
            <img src="../img/logo.png" alt="TripVerse Logo" />
            <h3>Trip<span>Verse</span></h3>
        </div>

        <h1 class="form-title">Select Account Type</h1>

        <div class="user-type">
            <div class="user-type-option" id="customerOption">
                <i class="fas fa-user"></i>
                <p>Customer</p>
                <p>Book trips and experiences</p>
            </div>

            <a href="register_supplier.php" style="text-decoration: none; color: inherit;">
                <div class="user-type-option" id="supplierOption">
                    <i class="fas fa-store"></i>
                    <p>Supplier</p>
                    <p>Offer travel services</p>
                </div>
            </a>

        </div>

        <div class="links">
            <p>Already have an account?</p>
            <button id="signInButtonFromSelection">Sign In</button>
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
                    strengthBar.style.backgroundColor = '#dc3545';
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
                    strengthBar.style.backgroundColor = '#28a745';
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
                    strengthBar.style.backgroundColor = '#dc3545';
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
                    strengthBar.style.backgroundColor = '#28a745';
                    break;
            }
        }

        // Event listeners for password fields
        document.getElementById('passwordCustomer').addEventListener('input', function(e) {
            checkPasswordStrength(e.target.value, 'passwordStrengthBarCustomer', 'Customer');
        });

        document.getElementById('passwordSupplier').addEventListener('input', function(e) {
            checkPasswordStrengthSupplier(e.target.value);
        });

        // Form validation for customer registration
        document.getElementById('registerCustomerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('passwordCustomer').value;
            const confirmPassword = document.getElementById('confirmPasswordCustomer').value;

            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }

            const isStrongPassword = checkPasswordStrength(password, 'passwordStrengthBarCustomer', 'Customer');
            if (!isStrongPassword) {
                e.preventDefault();
                alert('Password does not meet all requirements!');
                return false;
            }

            return true;
        });

        // Form validation for supplier registration
        document.getElementById('registerSupplierForm').addEventListener('submit', function(e) {
            const password = document.getElementById('passwordSupplier').value;
            const confirmPassword = document.getElementById('confirmPasswordSupplier').value;
            const phone = document.getElementById('phoneSupplier').value;

            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Password dan Konfirmasi Password tidak cocok!');
                return false;
            }

            if (password.length < 6) {
                e.preventDefault();
                alert('Password harus minimal 6 karakter!');
                return false;
            }

            if (phone.length < 10) {
                e.preventDefault();
                alert('Nomor telepon harus minimal 10 digit!');
                return false;
            }

            return true;
        });

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
                alert("Phone number required.");
                return;
            }
            document.getElementById("signupCustomer").style.display = "none";
            document.getElementById("otpPageCustomer").style.display = "block";
        });

        document.getElementById("requestOtpBtnCustomer").addEventListener("click", () => {
            const phone = document.getElementById("phoneCustomer").value;
            fetch("send_otp.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "no_hp=" + encodeURIComponent(phone)
                })
                .then(res => res.text())
                .then(() => alert("OTP sent to WhatsApp!"));
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
            const phone = document.getElementById("phoneCustomer").value;

            fetch("verify_otp.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "no_hp=" + encodeURIComponent(phone) + "&otp=" + otp
                })
                .then(res => res.text())
                .then(result => {
                    if (result === "success") {
                        alert("OTP valid! Creating account...");
                        document.getElementById("registerCustomerForm").submit();
                        setTimeout(() => {
                            window.location.href = "login.php";
                        }, 600);
                    } else if (result === "expired") {
                        alert("OTP expired. Request again.");
                    } else {
                        alert("Wrong OTP!");
                    }
                });
        });
    </script>
</body>

</html>