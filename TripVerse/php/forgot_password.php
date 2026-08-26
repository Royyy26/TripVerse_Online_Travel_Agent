<?php session_start(); require_once __DIR__ . '/_lang.php'; ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Lupa Password') ?> | TripVerse</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">

    <link href="../css/login.css?v=2.0" rel="stylesheet">
    <link href="../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>" rel="stylesheet">

    <style>
        /* =========================================
           STYLE GLOBAL (SESUAI login.php)
        ========================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Heebo', sans-serif;
        }

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

        body > * {
            position: relative;
            z-index: 1;
        }

        .container {
            background: rgba(255, 255, 255, 0.97);
            width: 100%;
            max-width: 480px;
            padding: 44px 34px;
            border-radius: 24px;
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.45), 0 0 0 1px rgba(255, 255, 255, 0.08);
            text-align: center;
            position: relative;
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

        .form-title {
            font-size: 28px;
            font-weight: 700;
            color: #FEA116;
            margin-bottom: 30px;
            position: relative;
        }

        .input-group {
            position: relative;
            text-align: left;
            margin-bottom: 20px;
        }

        .input-group i {
            position: absolute;
            top: 50%;
            left: 12px;
            transform: translateY(-50%);
            color: #FEA116;
        }

        .input-group input {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border-radius: 8px;
            border: 1px solid #ddd;
            font-size: 16px;
        }

        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #FEA116, #FF7A3D);
            color: white;
            font-size: 16px;
            font-weight: 600;
            margin-top: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn:hover {
            background: linear-gradient(135deg, #FF7A3D, #FEA116);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(254, 161, 22, 0.2);
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .logo img {
            height: 40px;
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

        .otp-input-wrapper {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .otp-field {
            width: 48px;
            height: 55px;
            font-size: 26px;
            border: 2px solid #ddd;
            border-radius: 10px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .otp-field:focus {
            border-color: #FEA116;
            outline: none;
            box-shadow: 0 0 5px rgba(254, 161, 22, 0.3);
        }

        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: none;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .links {
            margin-top: 20px;
            text-align: center;
        }

        .links p {
            color: #666;
            margin-bottom: 10px;
        }

        .links button {
            background: none;
            border: none;
            color: #FEA116;
            font-weight: 600;
            cursor: pointer;
            text-decoration: underline;
        }

        .links button:hover {
            color: #E8890A;
        }

        .resend-link {
            margin-top: 15px;
            text-align: center;
        }

        .resend-link a {
            color: #FEA116;
            text-decoration: none;
            cursor: pointer;
        }

        .resend-link a:hover {
            text-decoration: underline;
        }

        .resend-link.disabled {
            color: #999;
        }

        .resend-link.disabled a {
            color: #999;
            cursor: not-allowed;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div class="tv-aurora tv-aurora-1"></div>
    <div class="tv-aurora tv-aurora-2"></div>

    <!-- =========================================
     CONTAINER 1 — REQUEST OTP
    ========================================= -->
    <div class="container" id="boxRequest">
        <div class="logo">
            <img src="../img/logo.png">
            <h3>Trip<span>Verse</span></h3>
        </div>

        <h1 class="form-title"><?= te('Lupa Password') ?></h1>

        <div id="alertRequest" class="alert"></div>

        <p><?= te('Masukkan nomor WhatsApp Anda') ?></p>

        <div class="input-group">
            <i class="fas fa-phone"></i>
            <input type="text" id="phone" placeholder="+628123456789">
        </div>

        <button class="btn" id="sendOtpBtn"><?= te('Minta OTP') ?></button>

        <div class="links">
            <p><?= te('Ingat password Anda?') ?></p>
            <button onclick="window.location.href='login.php'"><?= te('Masuk') ?></button>
        </div>
    </div>

    <!-- =========================================
     CONTAINER 2 — VERIFY OTP
    ========================================= -->
    <div class="container" id="boxOtp" style="display:none;">
        <div class="logo">
            <img src="../img/logo.png">
            <h3>Trip<span>Verse</span></h3>
        </div>

        <h1 class="form-title"><?= te('Verifikasi OTP') ?></h1>

        <div id="alertOtp" class="alert"></div>

        <p><?= te('Masukkan 6 digit OTP yang dikirim ke WhatsApp') ?></p>
        <p id="phoneDisplay" style="color: #666; margin-bottom: 15px;"></p>

        <div class="otp-input-wrapper">
            <input type="text" maxlength="1" class="otp-field" data-index="1">
            <input type="text" maxlength="1" class="otp-field" data-index="2">
            <input type="text" maxlength="1" class="otp-field" data-index="3">
            <input type="text" maxlength="1" class="otp-field" data-index="4">
            <input type="text" maxlength="1" class="otp-field" data-index="5">
            <input type="text" maxlength="1" class="otp-field" data-index="6">
        </div>

        <div class="resend-link" id="resendLink">
            <?= te('Tidak menerima kode?') ?> <a id="resendOtpLink"><?= te('Kirim Ulang OTP') ?></a>
            <span id="countdown"></span>
        </div>

        <button class="btn" id="verifyOtpBtn"><?= te('Verifikasi OTP') ?></button>
    </div>

    <!-- =========================================
     CONTAINER 3 — RESET PASSWORD
    ========================================= -->
    <div class="container" id="boxReset" style="display:none;">
        <div class="logo">
            <img src="../img/logo.png">
            <h3>Trip<span>Verse</span></h3>
        </div>

        <h1 class="form-title"><?= te('Atur Ulang Password') ?></h1>

        <div id="alertReset" class="alert"></div>

        <p><?= te('Buat password baru') ?></p>

        <div class="input-group">
            <i class="fas fa-lock"></i>
            <input type="password" id="newPass" placeholder="<?= te('Password Baru') ?>">
        </div>

        <div class="input-group">
            <i class="fas fa-lock"></i>
            <input type="password" id="confirmPass" placeholder="<?= te('Konfirmasi Password') ?>">
        </div>

        <div id="passwordRequirements" style="text-align: left; margin: 10px 0; font-size: 12px; color: #666;">
            <p><?= te('Password harus mengandung:') ?></p>
            <ul style="padding-left: 20px; margin: 5px 0;">
                <li><?= te('Minimal 8 karakter') ?></li>
                <li><?= te('Minimal satu huruf kapital') ?></li>
                <li><?= te('Minimal satu angka') ?></li>
            </ul>
        </div>

        <button class="btn" id="savePassBtn"><?= te('Simpan Password') ?></button>
    </div>

    <!-- =========================================
     JAVASCRIPT LOGIC
    ========================================= -->
    <script>
        let currentPhone = "";
        let resendTimer = null;
        let canResend = false;

        /* -----------------------------------------
           ALERT FUNCTION
        ------------------------------------------ */
        function showAlert(id, msg, type = "danger") {
            const el = document.getElementById(id);
            el.className = "alert alert-" + type;
            el.innerHTML = msg;
            el.style.display = "block";
            setTimeout(() => {
                el.style.display = "none";
            }, 4000);
        }

        /* -----------------------------------------
           +62 AUTO FORMAT
        ------------------------------------------ */
        document.getElementById("phone").addEventListener("input", function() {
            let v = this.value.replace(/\D/g, "");
            if (v.startsWith("0")) v = "62" + v.slice(1);
            if (!v.startsWith("62")) v = "62" + v;
            this.value = "+62" + v.substring(2);
        });

        /* -----------------------------------------
           OTP INPUT HANDLING
        ------------------------------------------ */
        const otpFields = document.querySelectorAll('.otp-field');
        otpFields.forEach((field, index) => {
            field.addEventListener('input', (e) => {
                const value = e.target.value;
                // Only allow numbers
                if (value && !/^\d+$/.test(value)) {
                    e.target.value = '';
                    return;
                }

                // Auto focus next field
                if (value && index < otpFields.length - 1) {
                    otpFields[index + 1].focus();
                }
            });

            field.addEventListener('keydown', (e) => {
                // Handle backspace
                if (e.key === 'Backspace' && !field.value && index > 0) {
                    otpFields[index - 1].focus();
                }

                // Handle arrow keys
                if (e.key === 'ArrowLeft' && index > 0) {
                    otpFields[index - 1].focus();
                }
                if (e.key === 'ArrowRight' && index < otpFields.length - 1) {
                    otpFields[index + 1].focus();
                }
            });
        });

        /* -----------------------------------------
           START RESEND COUNTDOWN
        ------------------------------------------ */
        function startResendCountdown() {
            const resendLink = document.getElementById('resendLink');
            const countdownEl = document.getElementById('countdown');
            const resendOtpLink = document.getElementById('resendOtpLink');

            resendLink.classList.add('disabled');
            resendOtpLink.style.pointerEvents = 'none';
            canResend = false;

            let timeLeft = 60;
            countdownEl.textContent = ` (${timeLeft}s)`;

            resendTimer = setInterval(() => {
                timeLeft--;
                countdownEl.textContent = ` (${timeLeft}s)`;

                if (timeLeft <= 0) {
                    clearInterval(resendTimer);
                    resendLink.classList.remove('disabled');
                    resendOtpLink.style.pointerEvents = 'auto';
                    countdownEl.textContent = '';
                    canResend = true;
                }
            }, 1000);
        }

        /* -----------------------------------------
            STEP 1: REQUEST OTP
        ------------------------------------------ */
        document.getElementById("sendOtpBtn").addEventListener("click", function() {
            let phone = document.getElementById("phone").value.replace(/\D/g, "");

            if (!phone) {
                showAlert("alertRequest", "<?= t('Silakan masukkan nomor telepon') ?>");
                return;
            }

            currentPhone = phone;

            // Tampilkan OTP UI langsung
            document.getElementById("boxRequest").style.display = "none";
            document.getElementById("boxOtp").style.display = "block";

            document.querySelector(".otp-field").focus();

            // Kirim OTP - DENGAN DEBUG
            fetch("send_otp_forgot.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "no_hp=" + phone
                })
                .then(r => {
                    console.log("Response status:", r.status);
                    console.log("Response headers:", r.headers);
                    return r.text();
                })
                .then(res => {
                    console.log("RAW RESPONSE:", res);
                    console.log("Response length:", res.length);
                    console.log("Response char codes:", Array.from(res).map(c => c.charCodeAt(0)));

                    // TRIM response untuk handle spasi/newline
                    const trimmedRes = res.trim();
                    console.log("TRIMMED RESPONSE:", trimmedRes);

                    if (trimmedRes === "sent") {
                        console.log("✓ OTP sent successfully!");
                        showAlert("alertOtp", "<?= t('OTP berhasil dikirim!') ?>", "success");
                    } else {
                        console.log("✗ Expected 'sent' but got:", trimmedRes);
                        showAlert("alertOtp", "<?= t('Gagal mengirim OTP. Server merespons:') ?> " + trimmedRes, "danger");
                    }
                })
                .catch(error => {
                    console.error("Fetch error:", error);
                    showAlert("alertOtp", "<?= t('Kesalahan jaringan:') ?> " + error.message, "danger");
                });
        });

        /* -----------------------------------------
           STEP 2: VERIFY OTP (FIXED)
        ------------------------------------------ */
        document.getElementById("verifyOtpBtn").addEventListener("click", function() {
            let otp = "";
            document.querySelectorAll(".otp-field")
                .forEach(x => otp += x.value);

            if (otp.length !== 6) {
                showAlert("alertOtp", "<?= t('Silakan masukkan OTP lengkap') ?>", "danger");
                // Highlight empty fields
                document.querySelectorAll(".otp-field").forEach(field => {
                    if (!field.value) {
                        field.style.borderColor = "#ff0000";
                        field.style.backgroundColor = "#fff5f5";
                    }
                });
                return;
            }

            // Reset field styles
            document.querySelectorAll(".otp-field").forEach(field => {
                field.style.borderColor = "";
                field.style.backgroundColor = "";
            });

            // Show loading state
            const btn = this;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <?= t('Memverifikasi...') ?>';
            btn.disabled = true;

            console.log("Verifying OTP for:", currentPhone, "OTP:", otp);

            // PERBAIKAN: Gunakan verify_otp_forgot.php bukan verify_otp.php
            fetch("verify_otp_forgot.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "no_hp=" + currentPhone + "&otp=" + otp
                })
                .then(r => r.text())
                .then(res => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;

                    console.log("Server response:", res);

                    const trimmedRes = res.trim();

                    // Handle semua kemungkinan response
                    if (trimmedRes === "success") {
                        console.log("✓ OTP verification SUCCESS");
                        document.getElementById("boxOtp").style.display = "none";
                        document.getElementById("boxReset").style.display = "block";
                        // Focus password field
                        setTimeout(() => {
                            document.getElementById("newPass").focus();
                        }, 100);
                        showAlert("alertReset", "✓ <?= t('OTP berhasil diverifikasi!') ?>", "success");
                    } else if (trimmedRes === "expired") {
                        console.log("✗ OTP EXPIRED");
                        showAlert("alertOtp", "<?= t('OTP telah kedaluwarsa. Silakan minta yang baru.') ?>", "danger");
                        // Clear OTP fields
                        document.querySelectorAll(".otp-field").forEach(f => f.value = '');
                        document.querySelector(".otp-field").focus();
                    } else if (trimmedRes === "failed" || trimmedRes === "wrong") {
                        console.log("✗ WRONG OTP");
                        showAlert("alertOtp", "<?= t('OTP salah. Silakan coba lagi.') ?>", "danger");
                        // Clear OTP fields
                        document.querySelectorAll(".otp-field").forEach(f => f.value = '');
                        document.querySelector(".otp-field").focus();
                    } else if (trimmedRes === "missing_data") {
                        console.log("✗ MISSING DATA");
                        showAlert("alertOtp", "<?= t('Permintaan tidak valid. Silakan ulangi proses.') ?>", "danger");
                    } else {
                        console.log("✗ UNKNOWN RESPONSE:", trimmedRes);
                        showAlert("alertOtp", "<?= t('Verifikasi gagal. Silakan coba lagi.') ?>", "danger");
                    }
                })
                .catch(error => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    console.error("Fetch error:", error);
                    showAlert("alertOtp", "<?= t('Kesalahan jaringan. Silakan periksa koneksi Anda.') ?>", "danger");
                });
        });
        /* -----------------------------------------
           STEP 3: RESET PASSWORD
        ------------------------------------------ */
        document.getElementById("savePassBtn").addEventListener("click", function() {
            let p1 = document.getElementById("newPass").value;
            let p2 = document.getElementById("confirmPass").value;

            if (p1 !== p2) return showAlert("alertReset", "<?= t('Password tidak cocok') ?>");

            fetch("reset_password_process.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "phone=" + currentPhone + "&password=" + p1 + "&confirm_password=" + p2
                })
                .then(r => r.text())
                .then(res => {
                    if (res === "success") {
                        showAlert("alertReset", "<?= t('Password berhasil diperbarui! Mengalihkan...') ?>", "success");
                        setTimeout(() => {
                            window.location = "login.php";
                        }, 1500);
                    } else {
                        showAlert("alertReset", "<?= t('Gagal memperbarui password') ?>", "danger");
                    }
                });
        });

        /* -----------------------------------------
           ENTER KEY SUPPORT
        ------------------------------------------ */
        document.getElementById("phone").addEventListener("keypress", function(e) {
            if (e.key === "Enter") {
                document.getElementById("sendOtpBtn").click();
            }
        });

        otpFields.forEach(field => {
            field.addEventListener("keypress", function(e) {
                if (e.key === "Enter") {
                    document.getElementById("verifyOtpBtn").click();
                }
            });
        });

        document.getElementById("newPass").addEventListener("keypress", function(e) {
            if (e.key === "Enter") {
                document.getElementById("confirmPass").focus();
            }
        });

        document.getElementById("confirmPass").addEventListener("keypress", function(e) {
            if (e.key === "Enter") {
                document.getElementById("savePassBtn").click();
            }
        });
    </script>

    <script src="../js/tv-modern.js?v=<?= @filemtime(__DIR__ . '/../js/tv-modern.js') ?>"></script>
</body>

</html>