<?php
session_start();
include 'connect.php';

if (isset($_POST['signIn'])) {
    $email = $_POST['email'];
    $password = $_POST['password'];


    // Validasi format email yang lebih ketat
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "<script>alert('Format email tidak valid. Silakan masukkan email yang benar (contoh: user@domain.com)'); window.location='login.php';</script>";
        exit;
    }
    
    // Validasi khusus untuk owner: email harus mengandung domain yang valid
    $email_parts = explode('@', $email);
    if (count($email_parts) !== 2 || empty($email_parts[0]) || empty($email_parts[1])) {
        echo "<script>alert('Format email tidak valid. Email harus memiliki format: username@domain.com'); window.location='login.php';</script>";
        exit;
    }

    // Query ambil data dari tabel users
    $query = "SELECT * FROM user WHERE email = ?";
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        echo "<script>alert('Login error: Query gagal dipersiapkan'); window.location='login.php';</script>";
        exit;
    }
    if (!$stmt->bind_param("s", $email)) {
        $stmt->close();
        echo "<script>alert('Login error: Parameter gagal dibind'); window.location='login.php';</script>";
        exit;
    }
    if (!$stmt->execute()) {
        $stmt->close();
        echo "<script>alert('Login error: Eksekusi query gagal'); window.location='login.php';</script>";
        exit;
    }
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    // Jika user ditemukan dan password cocok
    if ($user && password_verify($password, $user['password'])) {
        // Validasi khusus untuk owner: pastikan email valid dan tidak kosong
        if ($user['role'] === 'owner') {
            if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                echo "<script>alert('Akun owner harus menggunakan email yang valid. Silakan hubungi admin untuk memperbaiki data akun.'); window.location='login.php';</script>";
                exit;
            }
        }

        // Simpan data session
        $_SESSION['id_user'] = $user['id_user'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];

        $id_user = $user['id_user'];

        // Route berdasarkan role
        if ($user['role'] === 'admin') {
            header("Location: dashboard.php");
        } elseif ($user['role'] === 'owner') {
            header("Location: owner_dashboard.php");
        } else {
            header("Location: home.php");
        }
        exit;
    } else {
        echo "<script>alert('Email atau password salah. Pastikan email menggunakan format yang benar (contoh: user@domain.com)'); window.location='login.php';</script>";
    }
    
    $stmt->close();
}
?>
