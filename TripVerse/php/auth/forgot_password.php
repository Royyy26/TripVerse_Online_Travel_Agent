<?php session_start(); require_once __DIR__ . '/../_lang.php'; ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Lupa Password') ?> | TripVerse</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700&family=Poppins:wght@300;500;700&family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">

    <link href="../../css/login.css?v=2.0" rel="stylesheet">
    <link href="../../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>" rel="stylesheet">

    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins','Heebo',sans-serif; }
        body,html { height:100%; width:100%; background:radial-gradient(ellipse at 20% 50%,#1c2a4a 0%,#0F172B 40%,#080d1a 100%); display:flex; align-items:center; justify-content:center; padding:40px 20px; min-height:100vh; position:relative; overflow-x:hidden; overflow-y:auto; }
        body>* { position:relative; z-index:1; }

        /* Floating orbs */
        .tv-orb { position:fixed; border-radius:50%; filter:blur(80px); opacity:.18; pointer-events:none; z-index:0; }
        .tv-orb-1 { width:400px; height:400px; background:#FEA116; top:-120px; left:-100px; animation:orbFloat 8s ease-in-out infinite; }
        .tv-orb-2 { width:300px; height:300px; background:#FF7A3D; bottom:-80px; right:-60px; animation:orbFloat 10s ease-in-out infinite reverse; }
        .tv-orb-3 { width:200px; height:200px; background:#6366f1; top:50%; left:60%; animation:orbFloat 12s ease-in-out infinite 2s; }
        @keyframes orbFloat { 0%,100%{ transform:translate(0,0) scale(1); } 50%{ transform:translate(30px,-20px) scale(1.1); } }

        /* Container card */
        .container { background:rgba(255,255,255,0.95); backdrop-filter:blur(20px); width:100%; max-width:480px; padding:0; border-radius:24px; box-shadow:0 25px 60px rgba(0,0,0,0.4),0 0 0 1px rgba(255,255,255,0.1),inset 0 1px 0 rgba(255,255,255,0.6); text-align:center; position:relative; overflow:hidden; animation:cardIn .7s cubic-bezier(.22,1,.36,1); }
        @keyframes cardIn { from{opacity:0;transform:translateY(30px) scale(.95)} to{opacity:1;transform:none} }
        .container-inner { padding:40px 36px 36px; }

        /* Step indicator bar */
        .step-bar { display:flex; gap:6px; padding:0 36px; margin-bottom:0; background:linear-gradient(135deg,#0F172B,#1e3a5f); padding:20px 36px 16px; }
        .step-dot { flex:1; height:4px; border-radius:4px; background:rgba(255,255,255,0.15); transition:all .5s ease; position:relative; }
        .step-dot.active { background:linear-gradient(135deg,#FEA116,#FF7A3D); box-shadow:0 0 12px rgba(254,161,22,0.4); }
        .step-dot.done { background:#22c55e; }
        .step-label { display:flex; justify-content:space-between; padding:0 36px 16px; background:linear-gradient(135deg,#0F172B,#1e3a5f); }
        .step-label span { font-size:10px; color:rgba(255,255,255,0.4); font-weight:500; text-transform:uppercase; letter-spacing:.5px; transition:color .3s; }
        .step-label span.active { color:#FEA116; }

        /* Logo */
        .logo { display:flex; align-items:center; justify-content:center; gap:10px; margin-bottom:8px; }
        .logo img { height:40px; }
        .logo h3 { font-weight:700; font-size:28px; background:linear-gradient(90deg,#0F172B,#FEA116); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
        .logo h3 span { background:linear-gradient(135deg,#FEA116,#FF7A3D); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }

        /* Icon circle */
        .step-icon { width:64px; height:64px; border-radius:50%; background:linear-gradient(135deg,#fff5e6,#fff0db); display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:28px; border:2px solid rgba(254,161,22,0.2); }

        .form-title { font-size:24px; font-weight:700; color:#0F172B; margin-bottom:6px; }
        .form-subtitle { font-size:14px; color:#64748b; margin-bottom:28px; line-height:1.6; }

        /* Input group */
        .input-group { position:relative; text-align:left; margin-bottom:18px; }
        .input-group label { display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:6px; text-transform:uppercase; letter-spacing:.5px; }
        .input-group i.field-icon { position:absolute; bottom:14px; left:14px; color:#94a3b8; font-size:16px; transition:color .3s; }
        .input-group input { width:100%; padding:13px 14px 13px 42px; border-radius:12px; border:2px solid #e2e8f0; font-size:15px; font-family:inherit; background:#f8fafc; transition:all .3s ease; }
        .input-group input:focus { outline:none; border-color:#FEA116; background:#fff; box-shadow:0 0 0 4px rgba(254,161,22,0.1); }
        .input-group input:focus ~ .field-icon, .input-group input:focus + .field-icon { color:#FEA116; }
        .input-group i.toggle-password { position:absolute; bottom:14px; left:auto; right:14px; color:#94a3b8; cursor:pointer; z-index:2; font-size:16px; transition:color .3s; }
        .input-group i.toggle-password:hover,.input-group i.toggle-password.active { color:#FEA116; }
        .input-group input[type="password"] { padding-right:42px; }

        /* Button */
        .btn { width:100%; padding:14px; border:none; border-radius:12px; background:linear-gradient(135deg,#FEA116,#FF7A3D); color:#fff; font-size:15px; font-weight:600; font-family:inherit; cursor:pointer; transition:all .3s ease; position:relative; overflow:hidden; }
        .btn:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(254,161,22,0.35); }
        .btn:active { transform:translateY(0); }
        .btn:disabled { opacity:.6; cursor:not-allowed; transform:none; }
        .btn::after { content:''; position:absolute; top:50%; left:50%; width:0; height:0; background:rgba(255,255,255,0.2); border-radius:50%; transform:translate(-50%,-50%); transition:width .4s,height .4s; }
        .btn:hover::after { width:300px; height:300px; }

        /* OTP fields */
        .otp-input-wrapper { display:flex; justify-content:center; gap:10px; margin:24px 0; }
        .otp-field { width:50px; height:58px; font-size:24px; font-weight:700; font-family:inherit; border:2px solid #e2e8f0; border-radius:14px; text-align:center; background:#f8fafc; transition:all .3s ease; color:#0F172B; }
        .otp-field:focus { border-color:#FEA116; outline:none; box-shadow:0 0 0 4px rgba(254,161,22,0.15); background:#fff; transform:translateY(-2px); }
        .otp-field.filled { border-color:#FEA116; background:linear-gradient(135deg,#fff8f0,#fff); }
        .otp-field.error { border-color:#ef4444; background:#fef2f2; animation:shake .4s; }
        @keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-4px)} 75%{transform:translateX(4px)} }

        /* Alert */
        .alert { padding:14px 16px; border-radius:12px; margin-bottom:18px; display:none; font-size:14px; font-weight:500; text-align:left; animation:alertIn .3s ease; }
        @keyframes alertIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:none} }
        .alert-success { background:linear-gradient(135deg,#ecfdf5,#d1fae5); color:#065f46; border:1px solid #a7f3d0; }
        .alert-danger { background:linear-gradient(135deg,#fef2f2,#fee2e2); color:#991b1b; border:1px solid #fecaca; }
        .alert-warning { background:linear-gradient(135deg,#fffbeb,#fef3c7); color:#92400e; border:1px solid #fde68a; }

        /* Links */
        .links { margin-top:24px; padding-top:20px; border-top:1px solid #f1f5f9; }
        .links p { color:#64748b; margin-bottom:8px; font-size:14px; }
        .links button { background:none; border:none; color:#FEA116; font-weight:600; cursor:pointer; text-decoration:none; font-size:14px; font-family:inherit; transition:color .3s; }
        .links button:hover { color:#e8890a; }

        /* Resend */
        .resend-link { margin-top:18px; text-align:center; font-size:13px; color:#64748b; }
        .resend-link a { color:#FEA116; text-decoration:none; cursor:pointer; font-weight:600; transition:color .3s; }
        .resend-link a:hover { color:#e8890a; }
        .resend-link.disabled a { color:#94a3b8; cursor:not-allowed; }

        /* Password requirements */
        .pw-reqs { text-align:left; margin:12px 0 20px; padding:16px 20px; background:linear-gradient(135deg,#f8fafc,#f1f5f9); border-radius:14px; border:1px solid #e2e8f0; }
        .pw-reqs p { font-size:12px; font-weight:600; color:#475569; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
        .pw-reqs p::before { content:'🔒'; font-size:14px; }
        .pw-reqs ul { padding:0; margin:0; }
        .pw-reqs li { font-size:13px; color:#94a3b8; margin-bottom:6px; list-style:none; padding-left:26px; position:relative; transition:all .3s ease; }
        .pw-reqs li::before { content:''; position:absolute; left:0; top:2px; width:16px; height:16px; border-radius:50%; border:2px solid #cbd5e1; transition:all .3s ease; }
        .pw-reqs li.valid { color:#059669; font-weight:500; }
        .pw-reqs li.valid::before { content:'✓'; border-color:#059669; background:#059669; color:#fff; font-size:10px; font-weight:700; display:flex; align-items:center; justify-content:center; line-height:16px; text-align:center; }

        /* Password strength bar */
        .pw-strength { height:6px; border-radius:3px; background:#e2e8f0; margin:0 0 16px; overflow:hidden; }
        .pw-strength-fill { height:100%; width:0; border-radius:3px; transition:all .4s ease; }
        .pw-strength-fill.weak { width:33%; background:linear-gradient(90deg,#ef4444,#f97316); }
        .pw-strength-fill.medium { width:66%; background:linear-gradient(90deg,#f97316,#eab308); }
        .pw-strength-fill.strong { width:100%; background:linear-gradient(90deg,#22c55e,#10b981); }
        .pw-strength-text { font-size:11px; font-weight:600; text-align:right; margin-top:4px; margin-bottom:12px; }
        .pw-strength-text.weak { color:#ef4444; }
        .pw-strength-text.medium { color:#eab308; }
        .pw-strength-text.strong { color:#10b981; }

        /* Email display chip */
        .email-chip { display:inline-flex; align-items:center; gap:8px; background:linear-gradient(135deg,#f0f9ff,#e0f2fe); padding:10px 20px; border-radius:24px; font-size:13px; color:#0369a1; font-weight:500; margin-bottom:8px; border:1px solid #bae6fd; box-shadow:0 2px 8px rgba(3,105,161,0.08); }
        .email-chip i { font-size:13px; opacity:.7; }

        /* Success celebration */
        .btn.success-state { background:linear-gradient(135deg,#22c55e,#10b981) !important; pointer-events:none; }
        @keyframes confetti { 0%{transform:translateY(0) rotate(0)} 100%{transform:translateY(-20px) rotate(360deg); opacity:0} }
    </style>
</head>

<body>
    <div class="tv-orb tv-orb-1"></div>
    <div class="tv-orb tv-orb-2"></div>
    <div class="tv-orb tv-orb-3"></div>

    <!-- =========================================
     CONTAINER 1 — REQUEST OTP
    ========================================= -->
    <div class="container" id="boxRequest">
        <div class="step-bar"><div class="step-dot active"></div><div class="step-dot"></div><div class="step-dot"></div></div>
        <div class="step-label"><span class="active"><?= te('Email') ?></span><span><?= te('OTP') ?></span><span><?= te('Password') ?></span></div>
        <div class="container-inner">
            <div class="logo">
                <img src="../../img/logo.png">
                <h3>Trip<span>Verse</span></h3>
            </div>
            <div class="step-icon">📧</div>
            <h1 class="form-title"><?= te('Lupa Password?') ?></h1>
            <p class="form-subtitle"><?= te('Jangan khawatir! Masukkan email yang terdaftar dan kami akan mengirimkan kode verifikasi.') ?></p>

            <div id="alertRequest" class="alert"></div>

            <div class="input-group">
                <label><?= te('Alamat Email') ?></label>
                <input type="email" id="email" placeholder="nama@email.com">
                <i class="fas fa-envelope field-icon"></i>
            </div>

            <button class="btn" id="sendOtpBtn"><i class="fas fa-paper-plane"></i> <?= te('Kirim Kode OTP') ?></button>

            <div class="links">
                <p><?= te('Ingat password Anda?') ?></p>
                <button onclick="window.location.href='login.php'"><i class="fas fa-arrow-left"></i> <?= te('Kembali ke Login') ?></button>
            </div>
        </div>
    </div>

    <!-- =========================================
     CONTAINER 2 — VERIFY OTP
    ========================================= -->
    <div class="container" id="boxOtp" style="display:none;">
        <div class="step-bar"><div class="step-dot done"></div><div class="step-dot active"></div><div class="step-dot"></div></div>
        <div class="step-label"><span>✓ <?= te('Email') ?></span><span class="active"><?= te('OTP') ?></span><span><?= te('Password') ?></span></div>
        <div class="container-inner">
            <div class="logo">
                <img src="../../img/logo.png">
                <h3>Trip<span>Verse</span></h3>
            </div>
            <div class="step-icon">🔐</div>
            <h1 class="form-title"><?= te('Verifikasi OTP') ?></h1>
            <p class="form-subtitle"><?= te('Masukkan 6 digit kode yang telah dikirim ke') ?></p>
            <div class="email-chip" id="emailDisplay"><i class="fas fa-envelope"></i> <span></span></div>

            <div id="alertOtp" class="alert"></div>

            <div class="otp-input-wrapper">
                <input type="text" maxlength="1" class="otp-field" data-index="1" inputmode="numeric">
                <input type="text" maxlength="1" class="otp-field" data-index="2" inputmode="numeric">
                <input type="text" maxlength="1" class="otp-field" data-index="3" inputmode="numeric">
                <input type="text" maxlength="1" class="otp-field" data-index="4" inputmode="numeric">
                <input type="text" maxlength="1" class="otp-field" data-index="5" inputmode="numeric">
                <input type="text" maxlength="1" class="otp-field" data-index="6" inputmode="numeric">
            </div>

            <div class="resend-link" id="resendLink">
                <?= te('Tidak menerima kode?') ?> <a id="resendOtpLink"><?= te('Kirim Ulang') ?></a>
                <span id="countdown"></span>
            </div>

            <button class="btn" id="verifyOtpBtn"><i class="fas fa-shield-halved"></i> <?= te('Verifikasi OTP') ?></button>
        </div>
    </div>

    <!-- =========================================
     CONTAINER 3 — RESET PASSWORD
    ========================================= -->
    <div class="container" id="boxReset" style="display:none;">
        <div class="step-bar"><div class="step-dot done"></div><div class="step-dot done"></div><div class="step-dot active"></div></div>
        <div class="step-label"><span>✓ <?= te('Email') ?></span><span>✓ <?= te('OTP') ?></span><span class="active"><?= te('Password') ?></span></div>
        <div class="container-inner">
            <div class="logo">
                <img src="../../img/logo.png">
                <h3>Trip<span>Verse</span></h3>
            </div>
            <div class="step-icon">🔑</div>
            <h1 class="form-title"><?= te('Password Baru') ?></h1>
            <p class="form-subtitle"><?= te('Buat password baru yang kuat untuk melindungi akun Anda.') ?></p>

            <div id="alertReset" class="alert"></div>

            <div class="input-group">
                <label><?= te('Password Baru') ?></label>
                <input type="password" id="newPass" placeholder="<?= te('Minimal 8 karakter') ?>">
                <i class="fas fa-lock field-icon"></i>
                <i class="fas fa-eye toggle-password" role="button" tabindex="0"
                    aria-label="<?= te('Tampilkan password') ?>"
                    onclick="togglePassword('newPass', this)"></i>
            </div>

            <div class="pw-strength"><div class="pw-strength-fill" id="pwStrengthBar"></div></div>
            <div class="pw-strength-text" id="pwStrengthText"></div>

            <div class="input-group">
                <label><?= te('Konfirmasi Password') ?></label>
                <input type="password" id="confirmPass" placeholder="<?= te('Ketik ulang password') ?>">
                <i class="fas fa-lock field-icon"></i>
                <i class="fas fa-eye toggle-password" role="button" tabindex="0"
                    aria-label="<?= te('Tampilkan password') ?>"
                    onclick="togglePassword('confirmPass', this)"></i>
            </div>

            <div class="pw-reqs">
                <p><?= te('Password harus mengandung:') ?></p>
                <ul>
                    <li id="reqLen"><?= te('Minimal 8 karakter') ?></li>
                    <li id="reqUpper"><?= te('Minimal satu huruf kapital') ?></li>
                    <li id="reqNum"><?= te('Minimal satu angka') ?></li>
                    <li id="reqMatch"><?= te('Kedua password cocok') ?></li>
                </ul>
            </div>

            <button class="btn" id="savePassBtn"><i class="fas fa-check-circle"></i> <?= te('Simpan Password Baru') ?></button>
        </div>
    </div>

    <!-- =========================================
     JAVASCRIPT LOGIC
    ========================================= -->
    <script>
        // togglePassword() comes from js/tv-modern.js, loaded at the bottom.
        let currentEmail = "";
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
           OTP INPUT HANDLING
        ------------------------------------------ */
        const otpFields = document.querySelectorAll('.otp-field');
        otpFields.forEach((field, index) => {
            field.addEventListener('input', (e) => {
                const value = e.target.value;
                if (value && !/^\d+$/.test(value)) { e.target.value = ''; return; }
                field.classList.toggle('filled', !!value);
                if (value && index < otpFields.length - 1) otpFields[index + 1].focus();
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
            let email = document.getElementById("email").value.trim();

            if (!email) {
                showAlert("alertRequest", "<?= t('Silakan masukkan email') ?>");
                return;
            }

            currentEmail = email;

            // Tampilkan OTP UI langsung
            document.getElementById("boxRequest").style.display = "none";
            document.getElementById("boxOtp").style.display = "block";
            document.getElementById("emailDisplay").querySelector('span').textContent = email;

            document.querySelector(".otp-field").focus();

            fetch("send_otp_forgot.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "email=" + encodeURIComponent(email)
                })
                .then(r => r.text())
                .then(res => {
                    const trimmedRes = res.trim();

                    if (trimmedRes === "sent") {
                        showAlert("alertOtp", "<?= t('OTP berhasil dikirim!') ?>", "success");
                    } else {
                        showAlert("alertOtp", "<?= t('Gagal mengirim OTP. Server merespons:') ?> " + trimmedRes, "danger");
                    }
                })
                .catch(error => {
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

            fetch("verify_otp_forgot.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "email=" + encodeURIComponent(currentEmail) + "&otp=" + otp
                })
                .then(r => r.text())
                .then(res => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;

                    const trimmedRes = res.trim();

                    if (trimmedRes === "success") {
                        document.getElementById("boxOtp").style.display = "none";
                        document.getElementById("boxReset").style.display = "block";
                        setTimeout(() => {
                            document.getElementById("newPass").focus();
                        }, 100);
                        showAlert("alertReset", "✓ <?= t('OTP berhasil diverifikasi!') ?>", "success");
                    } else if (trimmedRes === "expired") {
                        showAlert("alertOtp", "<?= t('OTP telah kedaluwarsa. Silakan minta yang baru.') ?>", "danger");
                        document.querySelectorAll(".otp-field").forEach(f => f.value = '');
                        document.querySelector(".otp-field").focus();
                    } else if (trimmedRes === "failed" || trimmedRes === "wrong") {
                        showAlert("alertOtp", "<?= t('OTP salah. Silakan coba lagi.') ?>", "danger");
                        document.querySelectorAll(".otp-field").forEach(f => f.value = '');
                        document.querySelector(".otp-field").focus();
                    } else if (trimmedRes === "missing_data") {
                        showAlert("alertOtp", "<?= t('Permintaan tidak valid. Silakan ulangi proses.') ?>", "danger");
                    } else {
                        showAlert("alertOtp", "<?= t('Verifikasi gagal. Silakan coba lagi.') ?>", "danger");
                    }
                })
                .catch(error => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    showAlert("alertOtp", "<?= t('Kesalahan jaringan. Silakan periksa koneksi Anda.') ?>", "danger");
                });
        });
        /* -----------------------------------------
           STEP 3: RESET PASSWORD
        ------------------------------------------ */
        document.getElementById("savePassBtn").addEventListener("click", function() {
            let p1 = document.getElementById("newPass").value;
            let p2 = document.getElementById("confirmPass").value;

            if (!p1 || !p2) return showAlert("alertReset", "<?= t('Harap isi semua field') ?>");
            if (p1.length < 8) return showAlert("alertReset", "<?= t('Password minimal 8 karakter') ?>");
            if (!/[A-Z]/.test(p1)) return showAlert("alertReset", "<?= t('Password harus mengandung huruf kapital') ?>");
            if (!/[0-9]/.test(p1)) return showAlert("alertReset", "<?= t('Password harus mengandung angka') ?>");
            if (p1 !== p2) return showAlert("alertReset", "<?= t('Password tidak cocok') ?>");

            const btn = this;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <?= t('Menyimpan...') ?>';
            btn.disabled = true;

            fetch("reset_password_process.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "password=" + encodeURIComponent(p1) + "&confirm_password=" + encodeURIComponent(p2)
                })
                .then(r => r.text())
                .then(res => {
                    const trimmedRes = res.trim();
                    btn.innerHTML = originalText;
                    btn.disabled = false;

                    if (trimmedRes === "success") {
                        showAlert("alertReset", "✓ <?= t('Password berhasil diperbarui! Mengalihkan ke login...') ?>", "success");
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-check"></i> <?= t('Berhasil!') ?>';
                        setTimeout(() => {
                            window.location = "login.php";
                        }, 2000);
                    } else if (trimmedRes === "session_expired") {
                        showAlert("alertReset", "<?= t('Sesi telah berakhir. Silakan ulangi proses dari awal.') ?>", "danger");
                        setTimeout(() => { window.location.reload(); }, 2000);
                    } else if (trimmedRes === "user_not_found") {
                        showAlert("alertReset", "<?= t('Akun tidak ditemukan. Silakan ulangi proses.') ?>", "danger");
                    } else if (trimmedRes === "password_mismatch") {
                        showAlert("alertReset", "<?= t('Password tidak cocok') ?>", "danger");
                    } else {
                        showAlert("alertReset", "<?= t('Gagal memperbarui password. Silakan coba lagi.') ?>", "danger");
                    }
                })
                .catch(error => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    showAlert("alertReset", "<?= t('Kesalahan jaringan. Silakan coba lagi.') ?>", "danger");
                });
        });

        /* -----------------------------------------
           ENTER KEY SUPPORT
        ------------------------------------------ */
        document.getElementById("email").addEventListener("keypress", function(e) {
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
        /* -----------------------------------------
           LIVE PASSWORD REQUIREMENTS
        ------------------------------------------ */
        function checkPwReqs() {
            const p1 = document.getElementById('newPass').value;
            const p2 = document.getElementById('confirmPass').value;
            const toggle = (id, ok) => { const el = document.getElementById(id); if(el) el.classList.toggle('valid', ok); };
            toggle('reqLen', p1.length >= 8);
            toggle('reqUpper', /[A-Z]/.test(p1));
            toggle('reqNum', /[0-9]/.test(p1));
            toggle('reqMatch', p1.length > 0 && p1 === p2);
        }
        document.getElementById('newPass').addEventListener('input', checkPwReqs);
        document.getElementById('confirmPass').addEventListener('input', checkPwReqs);
    </script>

    <script src="../../js/tv-modern.js?v=<?= @filemtime(__DIR__ . '/../js/tv-modern.js') ?>"></script>
</body>

</html>