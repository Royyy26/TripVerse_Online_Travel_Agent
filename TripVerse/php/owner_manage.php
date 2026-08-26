<?php
session_start();
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo "<script>alert('Akses ditolak! Hanya admin.'); window.location='home.php';</script>";
    exit;
}

require 'connect.php';

// Ensure role enum supports 'owner'
$col = $conn->query("SHOW COLUMNS FROM user LIKE 'role'");
if ($col && $row = $col->fetch_assoc()) {
    if (strpos($row['Type'], "'owner'") === false) {
        $conn->query("ALTER TABLE user MODIFY role ENUM('user','admin','owner') DEFAULT 'user'");
    }
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_owner'])) {
    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['no_hp'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Validasi email untuk owner
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Format email tidak valid untuk owner. Email harus menggunakan format yang benar (contoh: owner@domain.com)';
    } elseif ($first && $username && $email && $password) {
        // Cek apakah email sudah digunakan
        $email_check = $conn->prepare("SELECT id_user FROM user WHERE email = ?");
        if ($email_check) {
            $email_check->bind_param("s", $email);
            $email_check->execute();
            $email_result = $email_check->get_result();
            $email_check->close();
            
            if ($email_result->num_rows > 0) {
                $message = 'Email sudah digunakan. Silakan gunakan email lain.';
            } else {
                // Generate new owner id
                $res = $conn->query("SELECT id_user FROM user WHERE id_user LIKE 'OWN%' ORDER BY id_user DESC LIMIT 1");
                $newId = 'OWN001';
                if ($res && $lastRow = $res->fetch_assoc()) {
                    $num = (int)substr($lastRow['id_user'], 3);
                    $newId = 'OWN' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
                }

                $stmt = $conn->prepare("INSERT INTO user (id_user, first_name, last_name, username, no_hp, email, password, role) VALUES (?,?,?,?,?,?,?, 'owner')");
                if ($stmt) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt->bind_param('sssssss', $newId, $first, $last, $username, $phone, $email, $hashed_password);
                    if ($stmt->execute()) {
                        $message = 'Owner berhasil dibuat: ' . htmlspecialchars($newId) . ' | Email: ' . htmlspecialchars($email);
                    } else {
                        $message = 'Gagal membuat owner: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $message = 'Prepare gagal: ' . $conn->error;
                }
            }
        }
    } else {
        $message = 'Lengkapi data wajib (first_name, username, email, password)';
    }
}

// List owners
$owners = $conn->query("SELECT id_user, username, email, first_name, last_name FROM user WHERE role='owner' ORDER BY id_user DESC");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Owners</title>
    <link rel="stylesheet" href="../css/dashboard.css?v=1.2.3">
</head>
<body>
    <div class="sidebar" id="sidebar">
        <nav>
            <a href="dashboard.php"><span class="material-icons">dashboard</span><span>Dashboard</span></a>
            <a href="owner_manage.php" class="active"><span class="material-icons">group_add</span><span>Manage Owners</span></a>
            <a href="logout.php"><span class="material-icons">logout</span><span>Logout</span></a>
        </nav>
    </div>
    <main class="main-content" id="main-content">
        <h2>Buat Owner Baru</h2>
        <?php if ($message): ?>
            <div class="notification <?= strpos($message, 'Gagal') !== false || strpos($message, 'gagal') !== false ? 'error' : 'success' ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="create_owner" value="1">
            <div class="form-row">
                <div class="form-group">
                    <label>First Name*</label>
                    <input type="text" name="first_name" required>
                </div>
                <div class="form-group">
                    <label>Last Name</label>
                    <input type="text" name="last_name">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Username*</label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label>Email*</label>
                    <input type="email" name="email" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>No HP</label>
                    <input type="text" name="no_hp">
                </div>
                <div class="form-group">
                    <label>Password*</label>
                    <input type="password" name="password" required>
                </div>
            </div>
            <button type="submit" class="btn">Buat Owner</button>
        </form>

        <h2 style="margin-top:30px;">Daftar Owner</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th><th>Nama</th><th>Username</th><th>Email</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($owners && $owners->num_rows > 0): ?>
                        <?php while($o = $owners->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($o['id_user']) ?></td>
                                <td><?= htmlspecialchars(($o['first_name'] ?? '').' '.($o['last_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($o['username']) ?></td>
                                <td><?= htmlspecialchars($o['email']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="4">Belum ada owner</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>


