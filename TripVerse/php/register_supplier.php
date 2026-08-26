<?php
session_start();
require_once __DIR__ . '/_lang.php';
include 'connect.php';

function sanitize($data, $conn) {
    return mysqli_real_escape_string($conn, trim($data));
}

// Fungsi untuk generate ID Supplier baru
function generateSupplierId($conn) {
    $query = "SELECT MAX(id_user) AS max_id FROM user WHERE id_user LIKE 'OWN%'";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    
    if ($row && $row['max_id']) {
        $lastId = $row['max_id'];
        $num = (int)substr($lastId, 3); // Ambil angka setelah 'OWN'
        $newNum = $num + 1;
        return "OWN" . str_pad($newNum, 3, "0", STR_PAD_LEFT);
    } else {
        return "OWN001"; 
    }
}

$successMessage = '';
$errorMessage = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['signUp'])) {
    $firstName = sanitize($_POST['fName'] ?? '', $conn);
    $lastName = sanitize($_POST['lName'] ?? '', $conn);
    $username = sanitize($_POST['uName'] ?? '', $conn);
    $email = sanitize($_POST['email'] ?? '', $conn);
    $phone = sanitize($_POST['phone'] ?? '', $conn);
    $companyName = sanitize($_POST['companyName'] ?? '', $conn);
    $companyAddress = sanitize($_POST['companyAddress'] ?? '', $conn);
    $taxId = sanitize($_POST['taxId'] ?? '', $conn);
    $password = sanitize($_POST['password'] ?? '', $conn);
    $confirmPassword = sanitize($_POST['confirmPassword'] ?? '', $conn);
    $role = 'owner';

    // Validasi field wajib
    if (!$firstName || !$username || !$email || !$phone || !$companyName || !$companyAddress || !$password) {
        $errorMessage = t("Mohon isi semua field yang wajib (ditandai *)!");
    } elseif ($password !== $confirmPassword) {
        $errorMessage = t("Password dan Konfirmasi Password tidak cocok!");
    } elseif (strlen($password) < 6) {
        $errorMessage = t("Password harus minimal 6 karakter!");
    } else {
        // Cek apakah email sudah digunakan sebagai supplier
        $checkEmail = "SELECT * FROM user WHERE email = ? AND role = ?";
        $stmt = $conn->prepare($checkEmail);
        $stmt->bind_param("ss", $email, $role);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $errorMessage = t("Email sudah terdaftar sebagai supplier!");
        } else {
            // Cek apakah username sudah digunakan
            $checkUsername = "SELECT * FROM user WHERE username = ?";
            $stmtUser = $conn->prepare($checkUsername);
            $stmtUser->bind_param("s", $username);
            $stmtUser->execute();
            $resultUser = $stmtUser->get_result();

            if ($resultUser && $resultUser->num_rows > 0) {
                $errorMessage = t("Username sudah digunakan!");
            } else {
                // Generate ID baru
                $id_supplier = generateSupplierId($conn);

                // Simpan data supplier
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $insertQuery = "INSERT INTO user (id_user, first_name, last_name, username, email, no_hp, password, role)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($insertQuery);
                $stmt->bind_param("ssssssss", $id_supplier, $firstName, $lastName, $username, $email, $phone, $hashed_password, $role);

                if ($stmt->execute()) {
                    // Simpan informasi supplier tambahan jika ada tabel supplier
                    // Anda dapat menambahkan tabel supplier untuk menyimpan info spesifik supplier
                    $successMessage = t("Registrasi supplier berhasil! Silakan login.");
                    // Redirect ke login setelah 2 detik
                    echo "<script>
                        setTimeout(function() {
                            window.location.href='login.php';
                        }, 2000);
                    </script>";
                } else {
                    $errorMessage = t("Error saat registrasi: ") . $conn->error;
                }
            }
            $stmtUser->close();
        }
        $stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= te('Daftar Supplier') ?> | TripVerse</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet" />
    <link href="../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>" rel="stylesheet" />
    <style>
        /* Reset dan Font */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Heebo', sans-serif;
        }

        /* Body dan Container Utama — disamakan dengan login.php dan
           forgot_password.php. Bedanya form ini panjang, jadi kartunya
           diratakan ke atas dan halaman tetap bisa di-scroll. */
        body,
        html {
            width: 100%;
            min-height: 100%;
            background: radial-gradient(circle at 15% 20%, #1c2a4a 0%, #0F172B 45%, #0a0f1e 100%);
            background-attachment: fixed;
        }

        body {
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 20px;
            min-height: 100vh;
        }

        .container {
            background: rgba(255, 255, 255, 0.97);
            width: 100%;
            max-width: 680px;
            padding: 44px 40px;
            border-radius: 24px;
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.08);
            position: relative;
            overflow: hidden;
            animation: tv-card-in .7s cubic-bezier(.22, 1, .36, 1);
        }

        @keyframes tv-card-in {
            from { opacity: 0; transform: translateY(24px) scale(.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(135deg, #FEA116, #FF7A3D);
        }

        /* Header dan Logo */
        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            margin-bottom: 15px;
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
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .logo h3 span {
            background: linear-gradient(135deg, #FEA116, #FF7A3D);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Judul Form */
        .form-title {
            font-size: 28px;
            font-weight: 700;
            color: #FEA116;
            margin-bottom: 10px;
        }

        .form-subtitle {
            font-size: 14px;
            color: #999;
            margin-bottom: 25px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-size: 14px;
            animation: slideDown 0.3s ease;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Form Layout */
        .form-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px 20px;
        }

        /* The markup tags most fields ".full" to span the whole row, but
           only ".form-layout.full" existed, so those fields silently stayed
           in the two-column grid — leaving Phone Number and Tax ID stranded
           beside an empty cell and squashing the address box. */
        .input-group.full,
        .form-layout.full {
            grid-column: 1 / -1;
        }

        /* Grup Input */
        .input-group {
            position: relative;
            text-align: left;
        }

        .input-group label {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 7px;
        }

        .input-group label .required {
            color: #e74c3c;
        }

        .input-group {
            position: relative;
        }

        .input-group i {
            position: absolute;
            top: 41px;
            left: 14px;
            color: #FEA116;
            transition: all 0.3s ease;
            font-size: 14px;
            pointer-events: none;
        }

        .input-group input,
        .input-group textarea {
            width: 100%;
            padding: 12px 14px 12px 40px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            color: #1e293b;
            background: #fff;
            transition: all 0.3s ease;
            font-family: 'Heebo', sans-serif;
        }

        .input-group input::placeholder,
        .input-group textarea::placeholder {
            color: #94a3b8;
        }

        /* The textarea used to reset padding to 12px all round, which put
           the absolutely-positioned icon straight on top of the first line
           of the address. Keep the icon's gutter and pin it to the top. */
        .input-group textarea {
            resize: vertical;
            min-height: 92px;
            line-height: 1.5;
        }

        .input-group input:focus,
        .input-group textarea:focus {
            outline: none;
            border-color: #FEA116;
            box-shadow: 0 0 0 3px rgba(254, 161, 22, 0.15);
        }

        /* The icon sits before the field in the markup, so the old
           "input:focus + i" sibling rule could never match it. */
        .input-group:focus-within i {
            color: #FF7A3D;
            transform: scale(1.1);
        }

        /* Toggle Password Eye Icon. Selector has to out-rank ".input-group i"
           (class + element beats a lone class), otherwise the leading-icon
           rules pin this to the left as well — left:14px and right:14px at
           once stretched it across the whole field and dropped it on top of
           the padlock. */
        .input-group i.toggle-password {
            left: auto;
            right: 14px;
            top: 41px;
            width: auto;
            color: #94a3b8;
            cursor: pointer;
            pointer-events: auto;
            z-index: 2;
            font-size: 15px;
            transform: none;
        }

        .input-group i.toggle-password:hover,
        .input-group i.toggle-password.active {
            color: #FEA116;
        }

        /* keep room for the eye so long values don't run under it */
        .input-group input[type="password"],
        .input-group input.has-toggle {
            padding-right: 42px;
        }

        /* Section heading — was a cramped strip of centred text pinched
           between two grey rules; now a proper left-aligned section label. */
        .form-divider {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 26px 0 2px;
            padding: 0;
            border: 0;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #FEA116;
            text-align: left;
        }

        .form-divider:first-child {
            margin-top: 8px;
        }

        .form-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, rgba(254, 161, 22, .45), rgba(254, 161, 22, 0));
        }

        /* Tombol Submit */
        .btn {
            grid-column: 1 / -1;
            width: 100%;
            padding: 14px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #FEA116, #FF7A3D);
            color: white;
            cursor: pointer;
            transition: all 0.4s ease;
            margin-top: 10px;
            box-shadow: 0 4px 15px rgba(254, 161, 22, 0.3);
        }

        .btn:hover {
            background: linear-gradient(135deg, #FF7A3D, #FEA116);
            box-shadow: 0 6px 20px rgba(254, 161, 22, 0.4);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: translateY(0);
        }

        /* Link Sign In */
        .links {
            grid-column: 1 / -1;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            font-size: 14px;
            color: #666;
        }

        .links a {
            color: #FEA116;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .links a:hover {
            color: #FF7A3D;
            text-decoration: underline;
        }

        /* Back to Home */
        .back-home {
            text-align: center;
            margin-top: 20px;
        }

        .back-home a {
            color: #FEA116;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .back-home a:hover {
            color: #FF7A3D;
            text-decoration: underline;
        }

        /* Password strength meter */
        .password-strength {
            width: 100%;
            height: 4px;
            background-color: #e0e0e0;
            border-radius: 5px;
            margin-top: 5px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        /* Responsive adjustments */
        @media (max-width: 600px) {
            .container {
                padding: 30px 20px;
            }

            .form-title {
                font-size: 24px;
            }

            .form-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="tv-lang tv-lang-light tv-lang-floating"><?php include __DIR__ . "/_lang_switch_inner.php"; ?></div>
    <div class="container">
        <div class="header">
            <div class="logo">
                <img src="../img/logo.png" alt="TripVerse Logo" />
                <h3>Trip<span style="color: #FEA116;">Verse</span></h3>
            </div>
            <h1 class="form-title"><?= te('Daftar Supplier') ?></h1>
            <p class="form-subtitle"><?= te('Bergabunglah dengan jaringan supplier profesional kami') ?></p>
        </div>

        <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form id="registerSupplierForm" method="post" action="register_supplier.php">
            <div class="form-layout">
                <!-- Informasi Pribadi -->
                <div class="form-divider" style="grid-column: 1 / -1;">
                    <i class="fas fa-user me-2"></i><?= te('Informasi Pribadi') ?>
                </div>

                <div class="input-group">
                    <label for="fName"><?= te('Nama Depan') ?> <span class="required">*</span></label>
                    <i class="fas fa-user"></i>
                    <input type="text" name="fName" id="fName" placeholder="<?= te('Nama depan Anda') ?>" required />
                </div>

                <div class="input-group">
                    <label for="lName"><?= te('Nama Belakang') ?></label>
                    <i class="fas fa-user"></i>
                    <input type="text" name="lName" id="lName" placeholder="<?= te('Nama belakang Anda') ?>" />
                </div>

                <div class="input-group full">
                    <label for="uName"><?= te('Username') ?> <span class="required">*</span></label>
                    <i class="fas fa-at"></i>
                    <input type="text" name="uName" id="uName" placeholder="<?= te('Username unik untuk login') ?>" required />
                </div>

                <div class="input-group full">
                    <label for="email"><?= te('Email') ?> <span class="required">*</span></label>
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" id="email" placeholder="<?= te('Email bisnis Anda') ?>" required />
                </div>

                <div class="input-group full">
                    <label for="phone"><?= te('Nomor Telepon') ?> <span class="required">*</span></label>
                    <i class="fas fa-phone"></i>
                    <input type="text" name="phone" id="phone" placeholder="<?= te('Nomor telepon bisnis') ?>" pattern="[0-9]+" title="<?= te('Hanya angka') ?>" required />
                </div>

                <!-- Informasi Perusahaan -->
                <div class="form-divider" style="grid-column: 1 / -1;">
                    <i class="fas fa-building me-2"></i><?= te('Informasi Perusahaan') ?>
                </div>

                <div class="input-group full">
                    <label for="companyName"><?= te('Nama Perusahaan') ?> <span class="required">*</span></label>
                    <i class="fas fa-briefcase"></i>
                    <input type="text" name="companyName" id="companyName" placeholder="<?= te('Nama perusahaan atau bisnis') ?>" required />
                </div>

                <div class="input-group full">
                    <label for="companyAddress"><?= te('Alamat Perusahaan') ?> <span class="required">*</span></label>
                    <i class="fas fa-map-marker-alt"></i>
                    <textarea name="companyAddress" id="companyAddress" placeholder="<?= te('Alamat lengkap perusahaan') ?>" required></textarea>
                </div>

                <div class="input-group full">
                    <label for="taxId"><?= te('NPWP / Tax ID') ?> <span class="required">*</span></label>
                    <i class="fas fa-file-invoice"></i>
                    <input type="text" name="taxId" id="taxId" placeholder="<?= te('Nomor NPWP atau Tax ID') ?>" required />
                </div>

                <!-- Keamanan -->
                <div class="form-divider" style="grid-column: 1 / -1;">
                    <i class="fas fa-lock me-2"></i><?= te('Keamanan Akun') ?>
                </div>

                <div class="input-group full">
                    <label for="password"><?= te('Password') ?> <span class="required">*</span></label>
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" id="password" placeholder="<?= te('Buat password yang kuat') ?>" required onkeyup="checkPasswordStrength(this.value)" />
                    <i class="fas fa-eye toggle-password" role="button" tabindex="0"
                        aria-label="<?= te('Tampilkan password') ?>"
                        onclick="togglePassword('password', this)"></i>
                    <div class="password-strength">
                        <div class="password-strength-bar" id="passwordStrengthBar"></div>
                    </div>
                    <small style="color: #999; display: block; margin-top: 5px;"><?= te('Minimal 6 karakter') ?></small>
                </div>

                <div class="input-group full">
                    <label for="confirmPassword"><?= te('Konfirmasi Password') ?> <span class="required">*</span></label>
                    <i class="fas fa-lock"></i>
                    <input type="password" name="confirmPassword" id="confirmPassword" placeholder="<?= te('Ulangi password Anda') ?>" required />
                    <i class="fas fa-eye toggle-password" role="button" tabindex="0"
                        aria-label="<?= te('Tampilkan password') ?>"
                        onclick="togglePassword('confirmPassword', this)"></i>
                </div>

                <!-- Submit Button -->
                <input type="submit" class="btn" value="<?= te('Daftar Sebagai Supplier') ?>" name="signUp" />
            </div>
        </form>

        <div class="links">
            <p><?= te('Sudah punya akun?') ?></p>
            <a href="login.php"><?= te('Masuk di sini') ?></a>
        </div>

        <div class="back-home">
            <a href="home.php"><i class="fas fa-arrow-left me-2"></i><?= te('Kembali ke Beranda') ?></a>
        </div>
    </div>

    <script>
        // Toggle Password Visibility
        function togglePassword(inputId, iconElement) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                iconElement.classList.remove('fa-eye');
                iconElement.classList.add('fa-eye-slash', 'active');
            } else {
                input.type = 'password';
                iconElement.classList.remove('fa-eye-slash', 'active');
                iconElement.classList.add('fa-eye');
            }
        }

        // keyboard support for the eye toggle
        document.querySelectorAll('.toggle-password').forEach(function (el) {
            el.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    el.click();
                }
            });
        });

        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrengthBar');
            let strength = 0;
            
            if (password.length >= 8) strength += 1;
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[!@#$%^&*(),.?":{}|<>]/.test(password)) strength += 1;
            
            switch(strength) {
                case 0:
                case 1:
                    strengthBar.style.width = '25%';
                    strengthBar.style.backgroundColor = '#e74c3c';
                    break;
                case 2:
                    strengthBar.style.width = '50%';
                    strengthBar.style.backgroundColor = '#f39c12';
                    break;
                case 3:
                    strengthBar.style.width = '75%';
                    strengthBar.style.backgroundColor = '#f1c40f';
                    break;
                case 4:
                    strengthBar.style.width = '100%';
                    strengthBar.style.backgroundColor = '#27ae60';
                    break;
            }
        }

        // Form validation
        document.getElementById('registerSupplierForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const phone = document.getElementById('phone').value;
            
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
    </script>
</body>

</html>
