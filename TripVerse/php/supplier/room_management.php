<?php
session_start();
if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit;
}
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['owner', 'admin'])) {
    echo "<script>alert('Akses ditolak!'); window.location='../home.php';</script>";
    exit;
}

require __DIR__ . '/../connect.php';
require_once __DIR__ . '/../_lang.php';
require __DIR__ . '/../activity_log_helper.php';

$id_user = $_SESSION['id_user'];
$role = $_SESSION['role'] ?? 'user';
$is_admin = ($role === 'admin');
$message = '';
$message_type = 'success';

$view_options = ['City', 'Garden', 'Pool', 'Sea', 'Mountain'];
$status_options = ['Available', 'Maintenance', 'Booked'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? '';

    if ($action === 'add_type') {
        $tipe_id = strtoupper(trim($_POST['tipe_id'] ?? ''));
        $nama_tipe = trim($_POST['nama_tipe'] ?? '');
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        $kapasitas = (int)($_POST['kapasitas_standar'] ?? 0);
        $ukuran = trim($_POST['ukuran_standar'] ?? '');

        try {
            if (!$tipe_id || !$nama_tipe || !$kapasitas || !$ukuran) {
                throw new Exception('Semua field bertanda * wajib diisi.');
            }

            if (!preg_match('/^[A-Z0-9_]{2,10}$/', $tipe_id)) {
                throw new Exception('Tipe ID harus 2-10 karakter, huruf/angka/underscore, tanpa spasi.');
            }

            if ($kapasitas < 1 || $kapasitas > 10) {
                throw new Exception('Kapasitas standar harus antara 1 hingga 10.');
            }

            $stmt = $conn->prepare("SELECT 1 FROM tipe_kamar WHERE tipe_id = ?");
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan pengecekan tipe kamar.');
            }
            $stmt->bind_param('s', $tipe_id);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row();
            $stmt->close();

            if ($exists) {
                throw new Exception('Tipe ID sudah terdaftar.');
            }

            $stmt = $conn->prepare("INSERT INTO tipe_kamar (tipe_id, nama_tipe, deskripsi, kapasitas_standar, ukuran_standar) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan penyimpanan tipe kamar.');
            }
            $stmt->bind_param('sssds', $tipe_id, $nama_tipe, $deskripsi, $kapasitas, $ukuran);
            if (!$stmt->execute()) {
                throw new Exception('Gagal menyimpan tipe kamar: ' . $stmt->error);
            }
            $stmt->close();

            // Log activity
            logActivity($conn, $id_user, 'add_room_type', "Added new room type: $nama_tipe (ID: $tipe_id, Capacity: $kapasitas)", 'room_type', $tipe_id, $nama_tipe, null);

            $_SESSION['room_message'] = 'Tipe kamar baru berhasil ditambahkan.';
            $_SESSION['room_message_type'] = 'success';
        } catch (Exception $e) {
            $_SESSION['room_message'] = $e->getMessage();
            $_SESSION['room_message_type'] = 'error';
        }

        header('Location: room_management.php');
        exit;
    }

    $hotel_id = trim($_POST['hotel_id'] ?? '');
    $redirect_hotel = $hotel_id ? '?hotel_id=' . urlencode($hotel_id) : '';

    try {
        if (!$hotel_id) {
            throw new Exception('Hotel tidak valid.');
        }

        if ($is_admin) {
            $stmt = $conn->prepare("SELECT 1 FROM hotel WHERE hotel_id = ?");
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan validasi hotel.');
            }
            $stmt->bind_param('s', $hotel_id);
            $stmt->execute();
            $hotel_exists = $stmt->get_result()->fetch_row();
            $stmt->close();
            if (!$hotel_exists) {
                throw new Exception('Hotel tidak ditemukan.');
            }
        } else {
            // Pastikan hotel milik owner
            $stmt = $conn->prepare("SELECT 1 FROM hotel WHERE hotel_id = ? AND owner_id = ?");
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan validasi hotel.');
            }
            $stmt->bind_param('ss', $hotel_id, $id_user);
            $stmt->execute();
            $owns_hotel = $stmt->get_result()->fetch_row();
            $stmt->close();

            if (!$owns_hotel) {
                throw new Exception('Anda tidak memiliki akses ke hotel ini.');
            }
        }

        if ($action === 'add_room') {
            $tipe_id = trim($_POST['tipe_id'] ?? '');
            $harga = (float)($_POST['harga'] ?? 0);
            $stok_total = (int)($_POST['stok_total'] ?? 0);
            $view = trim($_POST['view'] ?? 'City');
            $status = trim($_POST['status'] ?? 'Available');

            if (!$tipe_id || $harga <= 0 || $stok_total <= 0) {
                throw new Exception('Data kamar tidak lengkap atau tidak valid.');
            }

            if (!in_array($view, $view_options, true) || !in_array($status, $status_options, true)) {
                throw new Exception('View atau status tidak valid.');
            }

            // Pastikan tipe kamar ada
            $stmt = $conn->prepare("SELECT 1 FROM tipe_kamar WHERE tipe_id = ?");
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan validasi tipe kamar.');
            }
            $stmt->bind_param('s', $tipe_id);
            $stmt->execute();
            $tipe_exists = $stmt->get_result()->fetch_row();
            $stmt->close();

            if (!$tipe_exists) {
                throw new Exception('Tipe kamar tidak ditemukan.');
            }

            // Pastikan kombinasi hotel - tipe belum ada
            $stmt = $conn->prepare("SELECT 1 FROM jadwal_hotel WHERE hotel_id = ? AND tipe_id = ?");
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan pengecekan kamar.');
            }
            $stmt->bind_param('ss', $hotel_id, $tipe_id);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_row();
            $stmt->close();

            if ($exists) {
                throw new Exception('Tipe kamar ini sudah terdaftar untuk hotel tersebut.');
            }

            $conn->begin_transaction();

            $stmt = $conn->prepare(
                "INSERT INTO jadwal_hotel (hotel_id, tipe_id, harga, stok_total, terbooking)
                 VALUES (?, ?, ?, ?, 0)"
            );
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan penyimpanan jadwal kamar.');
            }
            $stmt->bind_param('ssdi', $hotel_id, $tipe_id, $harga, $stok_total);
            if (!$stmt->execute()) {
                throw new Exception('Gagal menyimpan jadwal kamar: ' . $stmt->error);
            }
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO kamar (hotel_id, tipe_id, view, status)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE view = VALUES(view), status = VALUES(status)"
            );
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan penyimpanan kamar.');
            }
            $stmt->bind_param('ssss', $hotel_id, $tipe_id, $view, $status);
            if (!$stmt->execute()) {
                throw new Exception('Gagal menyimpan data kamar: ' . $stmt->error);
            }
            $stmt->close();

            // Handle room facilities
            $room_facilities = $_POST['room_facilities'] ?? [];
            if (!empty($room_facilities) && is_array($room_facilities)) {
                // First, delete existing room facilities
                $stmt = $conn->prepare("DELETE FROM kamar_fasilitas WHERE hotel_id = ? AND tipe_id = ?");
                if ($stmt) {
                    $stmt->bind_param('ss', $hotel_id, $tipe_id);
                    $stmt->execute();
                    $stmt->close();
                }

                // Insert selected facilities
                $stmt = $conn->prepare("INSERT INTO kamar_fasilitas (hotel_id, tipe_id, fasilitas_id) VALUES (?, ?, ?)");
                if ($stmt) {
                    foreach ($room_facilities as $fac_id) {
                        $fac_id = trim($fac_id);
                        if ($fac_id) {
                            $stmt->bind_param('sss', $hotel_id, $tipe_id, $fac_id);
                            $stmt->execute();
                        }
                    }
                    $stmt->close();
                }
            }

            $conn->commit();
            // Log activity
            $room_name = "Room type {$tipe_id} at {$hotel_id}";
            logActivity($conn, $id_user, 'add_room', "Added new room: $room_name (Price: Rp " . number_format($harga, 0, ',', '.') . ", Stock: $stok_total)", 'room', $tipe_id, $room_name, $hotel_id);
            $_SESSION['room_message'] = 'Kamar baru berhasil ditambahkan.';
            $_SESSION['room_message_type'] = 'success';
            header("Location: room_management.php{$redirect_hotel}");
            exit;
        }

        if ($action === 'update_room') {
            $tipe_id = trim($_POST['tipe_id'] ?? '');
            $original_tipe_id = trim($_POST['original_tipe_id'] ?? '');
            $harga = (float)($_POST['harga'] ?? 0);
            $stok_total = (int)($_POST['stok_total'] ?? 0);
            $view = trim($_POST['view'] ?? 'City');
            $status = trim($_POST['status'] ?? 'Available');

            if (!$tipe_id || !$original_tipe_id || $harga <= 0 || $stok_total <= 0) {
                throw new Exception('Data kamar tidak lengkap atau tidak valid.');
            }

            if ($tipe_id !== $original_tipe_id) {
                throw new Exception('Tipe kamar tidak dapat diubah.');
            }

            if (!in_array($view, $view_options, true) || !in_array($status, $status_options, true)) {
                throw new Exception('View atau status tidak valid.');
            }

            // Ambil data existing untuk validasi terbooking
            $stmt = $conn->prepare(
                "SELECT terbooking FROM jadwal_hotel WHERE hotel_id = ? AND tipe_id = ?"
            );
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan pengambilan data kamar.');
            }
            $stmt->bind_param('ss', $hotel_id, $tipe_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$result) {
                throw new Exception('Data kamar tidak ditemukan.');
            }

            $terbooking = (int)($result['terbooking'] ?? 0);
            if ($stok_total < $terbooking) {
                throw new Exception('Stok total tidak boleh kurang dari jumlah kamar yang sudah dibooking.');
            }

            $conn->begin_transaction();

            $stmt = $conn->prepare(
                "UPDATE jadwal_hotel SET harga = ?, stok_total = ? WHERE hotel_id = ? AND tipe_id = ?"
            );
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan pembaruan jadwal kamar.');
            }
            $stmt->bind_param('diss', $harga, $stok_total, $hotel_id, $tipe_id);
            if (!$stmt->execute()) {
                throw new Exception('Gagal memperbarui jadwal kamar: ' . $stmt->error);
            }
            $stmt->close();

            $stmt = $conn->prepare(
                "INSERT INTO kamar (hotel_id, tipe_id, view, status)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE view = VALUES(view), status = VALUES(status)"
            );
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan pembaruan data kamar.');
            }
            $stmt->bind_param('ssss', $hotel_id, $tipe_id, $view, $status);
            if (!$stmt->execute()) {
                throw new Exception('Gagal memperbarui data kamar: ' . $stmt->error);
            }
            $stmt->close();

            // Handle room facilities
            $room_facilities = $_POST['room_facilities'] ?? [];
            // Delete existing room facilities
            $stmt = $conn->prepare("DELETE FROM kamar_fasilitas WHERE hotel_id = ? AND tipe_id = ?");
            if ($stmt) {
                $stmt->bind_param('ss', $hotel_id, $tipe_id);
                $stmt->execute();
                $stmt->close();
            }

            // Insert selected facilities
            if (!empty($room_facilities) && is_array($room_facilities)) {
                $stmt = $conn->prepare("INSERT INTO kamar_fasilitas (hotel_id, tipe_id, fasilitas_id) VALUES (?, ?, ?)");
                if ($stmt) {
                    foreach ($room_facilities as $fac_id) {
                        $fac_id = trim($fac_id);
                        if ($fac_id) {
                            $stmt->bind_param('sss', $hotel_id, $tipe_id, $fac_id);
                            $stmt->execute();
                        }
                    }
                    $stmt->close();
                }
            }

            $conn->commit();
            // Log activity
            $room_name = "Room type {$tipe_id} at {$hotel_id}";
            logActivity($conn, $id_user, 'edit_room', "Updated room: $room_name (New Price: Rp " . number_format($harga, 0, ',', '.') . ", New Stock: $stok_total)", 'room', $tipe_id, $room_name, $hotel_id);
            $_SESSION['room_message'] = 'Kamar berhasil diperbarui.';
            $_SESSION['room_message_type'] = 'success';
            header("Location: room_management.php{$redirect_hotel}");
            exit;
        }

        if ($action === 'delete_room') {
            $tipe_id = trim($_POST['tipe_id'] ?? '');
            if (!$tipe_id) {
                throw new Exception('Tipe kamar tidak valid.');
            }

            $conn->begin_transaction();

            $stmt = $conn->prepare("DELETE FROM jadwal_hotel WHERE hotel_id = ? AND tipe_id = ?");
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan penghapusan jadwal kamar.');
            }
            $stmt->bind_param('ss', $hotel_id, $tipe_id);
            if (!$stmt->execute()) {
                throw new Exception('Gagal menghapus jadwal kamar: ' . $stmt->error);
            }
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM kamar WHERE hotel_id = ? AND tipe_id = ?");
            if (!$stmt) {
                throw new Exception('Gagal mempersiapkan penghapusan data kamar.');
            }
            $stmt->bind_param('ss', $hotel_id, $tipe_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            // Log activity
            $room_name = "Room type {$tipe_id} at {$hotel_id}";
            logActivity($conn, $id_user, 'delete_room', "Deleted room: $room_name", 'room', $tipe_id, $room_name, $hotel_id);
            $_SESSION['room_message'] = 'Kamar berhasil dihapus.';
            $_SESSION['room_message_type'] = 'success';
            header("Location: room_management.php{$redirect_hotel}");
            exit;
        }

        throw new Exception('Aksi tidak dikenal.');
    } catch (Exception $e) {
        if ($conn->errno) {
            $conn->rollback();
        }
        $message = $e->getMessage();
        $message_type = 'error';
    }
}

// Ambil pesan dari sesi jika ada (hasil redirect)
if (isset($_SESSION['room_message'])) {
    $message = $_SESSION['room_message'];
    $message_type = $_SESSION['room_message_type'] ?? 'success';
    unset($_SESSION['room_message'], $_SESSION['room_message_type']);
}

// Ambil daftar hotel milik owner
$hotels = [];
$hotels = [];
if ($is_admin) {
    $stmt = $conn->prepare("SELECT hotel_id, nama_hotel, kota FROM hotel ORDER BY nama_hotel");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $hotels[] = $row;
        }
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare("SELECT hotel_id, nama_hotel, kota FROM hotel WHERE owner_id = ? ORDER BY nama_hotel");
    if ($stmt) {
        $stmt->bind_param('s', $id_user);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $hotels[] = $row;
        }
        $stmt->close();
    }
}

// Ambil daftar tipe kamar
$room_types = [];
$result = $conn->query("SELECT tipe_id, nama_tipe, kapasitas_standar FROM tipe_kamar ORDER BY nama_tipe");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $room_types[] = $row;
    }
}

$selected_hotel = null;
$rooms = [];
$selected_hotel_id = $_GET['hotel_id'] ?? '';

if ($selected_hotel_id) {
    if ($is_admin) {
        $stmt = $conn->prepare("SELECT * FROM hotel WHERE hotel_id = ?");
        if ($stmt) {
            $stmt->bind_param('s', $selected_hotel_id);
            $stmt->execute();
            $selected_hotel = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("SELECT * FROM hotel WHERE hotel_id = ? AND owner_id = ?");
        if ($stmt) {
            $stmt->bind_param('ss', $selected_hotel_id, $id_user);
            $stmt->execute();
            $selected_hotel = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }

    if ($selected_hotel) {
        $stmt = $conn->prepare(
            "SELECT jh.hotel_id, jh.tipe_id, jh.harga, jh.stok_total, jh.terbooking,
                    tk.nama_tipe, tk.deskripsi, tk.kapasitas_standar,
                    COALESCE(k.view, 'City') AS view,
                    COALESCE(k.status, 'Available') AS status
             FROM jadwal_hotel jh
             INNER JOIN tipe_kamar tk ON tk.tipe_id = jh.tipe_id
             LEFT JOIN kamar k ON k.hotel_id = jh.hotel_id AND k.tipe_id = jh.tipe_id
             WHERE jh.hotel_id = ?
             ORDER BY tk.nama_tipe"
        );
        if ($stmt) {
            $stmt->bind_param('s', $selected_hotel_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                // Get room facilities
                $fac_stmt = $conn->prepare(
                    "SELECT kf.fasilitas_id, fh.nama_fasilitas, fh.icon
                     FROM kamar_fasilitas kf
                     JOIN fasilitas_hotel fh ON kf.fasilitas_id = fh.fasilitas_id
                     WHERE kf.hotel_id = ? AND kf.tipe_id = ?
                     ORDER BY fh.nama_fasilitas"
                );
                $row['facilities'] = [];
                if ($fac_stmt) {
                    $fac_stmt->bind_param('ss', $row['hotel_id'], $row['tipe_id']);
                    $fac_stmt->execute();
                    $fac_result = $fac_stmt->get_result();
                    while ($fac_row = $fac_result->fetch_assoc()) {
                        $row['facilities'][] = $fac_row;
                    }
                    $fac_stmt->close();
                }
                $rooms[] = $row;
            }
            $stmt->close();
        }
    }
}

$prefill_room = null;
if (!empty($_GET['edit']) && $selected_hotel) {
    [$edit_tipe_id] = explode('|', $_GET['edit'] . '|', 2);
    $edit_tipe_id = trim($edit_tipe_id);
    foreach ($rooms as $room) {
        if ($room['tipe_id'] === $edit_tipe_id) {
            $prefill_room = $room;
            break;
        }
    }
}

// Get hotel facilities (available for room selection)
$hotel_facilities = [];
if ($selected_hotel_id) {
    $stmt = $conn->prepare(
        "SELECT fh.fasilitas_id, fh.nama_fasilitas, fh.icon
         FROM hotel_fasilitas hf
         JOIN fasilitas_hotel fh ON hf.fasilitas_id = fh.fasilitas_id
         WHERE hf.hotel_id = ?
         ORDER BY fh.nama_fasilitas"
    );
    if ($stmt) {
        $stmt->bind_param('s', $selected_hotel_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $hotel_facilities[] = $row;
        }
        $stmt->close();
    }
}
// Ambil info owner untuk header
$id_user = $_SESSION['id_user'];
$stmt = $conn->prepare("SELECT username, email, first_name, last_name, profile_picture FROM user WHERE id_user = ?");
$profile_picture = null;
$user_initials = 'U';

if ($stmt) {
    $stmt->bind_param("s", $id_user);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    
    // Generate initials dari nama
    if ($u) {
        $first = $u['first_name'] ?? '';
        $last = $u['last_name'] ?? '';
        $user_initials = strtoupper(
            (empty($first) ? '' : $first[0]) . 
            (empty($last) ? '' : $last[0])
        );
        if (empty($user_initials)) {
            $user_initials = 'U';
        }
    }
    
    // Check for profile picture
    if ($u && !empty($u['profile_picture'])) {
        // Check multiple possible locations
        $possible_paths = [
            __DIR__ . '/../uploads/' . $u['profile_picture'],
            __DIR__ . '/../uploads/profiles/' . $u['profile_picture'],
            __DIR__ . '/../uploads/users/' . $u['profile_picture']
        ];
        
        foreach ($possible_paths as $check_path) {
            if (file_exists($check_path)) {
                $profile_picture = $u['profile_picture'];
                break;
            }
        }
        
        // If not found in specific folders, check root uploads
        if ($profile_picture === null && file_exists(__DIR__ . '/../uploads/' . $u['profile_picture'])) {
            $profile_picture = $u['profile_picture'];
        }
    }
    
    $stmt->close();
}

// Generate fallback avatar SVG
$avatar_colors = ['#eb6834', '#2a78d6', '#1baf7a', '#eda100', '#e87ba4', '#4a3aa7'];
$color_index = abs(crc32($user_initials ?? 'U')) % count($avatar_colors);
$fallback_color = $avatar_colors[$color_index];
$fallback_avatar = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 200 200%22%3E%3Crect fill=%22' . urlencode($fallback_color) . '%22 width=%22200%22 height=%22200%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-size=%2280%22 font-weight=%22bold%22 fill=%22white%22 text-anchor=%22middle%22 dy=%22.35em%22 font-family=%22Arial, sans-serif%22%3E' . urlencode($user_initials) . '%3C/text%3E%3C/svg%3E';
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Management - TripVerse</title>
    <link rel="stylesheet" href="../../css/owner_dashboard.css?v=2.0.0">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .facilities-checkboxes {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
            padding: 15px;
            background: rgba(15, 23, 43, 0.02);
            border: 2px solid rgba(15, 23, 43, 0.1);
            border-radius: 10px;
            max-height: 200px;
            overflow-y: auto;
        }

        .facility-checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: white;
            border: 2px solid rgba(15, 23, 43, 0.1);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .facility-checkbox-item:hover {
            border-color: #FF7A3D;
            background: rgba(15, 23, 43, 0.02);
        }

        .facility-checkbox-item input[type="checkbox"] {
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: #FF7A3D;
        }

        .facility-checkbox-item .material-icons {
            font-size: 18px;
            color: #FF7A3D;
        }

        .facility-checkbox-item label {
            cursor: pointer;
            margin: 0;
            font-size: 0.9rem;
            flex: 1;
        }

        .facilities-info {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            background: rgba(33, 150, 243, 0.1);
            border: 1px solid rgba(33, 150, 243, 0.2);
            border-radius: 8px;
            color: #1976d2;
        }

        .facilities-info .material-icons {
            font-size: 20px;
        }

        .facilities-info p {
            margin: 0;
            font-size: 0.9rem;
        }

        .room-facilities-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .facility-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: rgba(15, 23, 43, 0.08);
            border: 1px solid rgba(15, 23, 43, 0.15);
            border-radius: 15px;
            font-size: 0.85rem;
            color: #FF7A3D;
        }

        .facility-tag .material-icons {
            font-size: 16px;
        }

        .room-card .room-facilities {
            margin-top: 10px;
        }

        .facilities-label-main {
            font-weight: 600;
            color: #FF7A3D;
            margin-bottom: 5px;
            display: block;
        }
    </style>
</head>

<body>
    <!-- Owner-specific sidebar -->
    <div class="owner-sidebar" id="owner-sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <img src="../../img/logo.png" alt="TripVerse Logo" class="logo-img" />
                <div class="logo-text-group">
                    <span class="logo-text">TripVerse</span>
                    <span class="logo-subtitle"><?= te('Dasbor Admin') ?></span>
                </div>
            </div>
            <button id="toggleSidebar" class="sidebar-toggle" aria-label="Toggle sidebar">
                <span class="material-icons">menu</span>
            </button>
        </div>

        <div class="sidebar-brand-lang">
            <?php include __DIR__ . '/../_lang_switch_inner.php'; ?>
        </div>

         <div class="profile-section">
            <div class="profile-avatar">
                <?php if ($profile_picture): ?>
                    <img src="../../uploads/<?php echo htmlspecialchars($profile_picture); ?>" 
                         alt="<?php echo htmlspecialchars(($u['first_name'] ?? 'User') . ' ' . ($u['last_name'] ?? '')); ?>"
                         onerror="this.src='<?php echo $fallback_avatar; ?>'">
                <?php else: ?>
                    <img src="<?php echo $fallback_avatar; ?>" 
                         alt="<?php echo htmlspecialchars(($u['first_name'] ?? 'User') . ' ' . ($u['last_name'] ?? '')); ?>">
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars(($u['first_name'] ?? 'User') . ' ' . ($u['last_name'] ?? '')); ?></h3>
                <p class="profile-role">Hotel Owner</p>
                <p class="profile-email"><?php echo htmlspecialchars($u['email'] ?? ''); ?></p>
            </div>
        </div>

        <nav class="owner-nav">
            <a href="owner_dashboard.php" class="nav-item">
                <span class="material-icons">dashboard</span>
                <span><?= te('Dashboard') ?></span>
            </a>
            <a href="hotel_manage.php" class="nav-item">
                <span class="material-icons">hotel</span>
                <span><?= te('Kelola Hotel') ?></span>
            </a>
            <a href="room_management.php" class="nav-item active">
                <span class="material-icons">bed</span>
                <span><?= te('Kelola Kamar') ?></span>
            </a>
            <a href="extra_facilities_manage.php" class="nav-item">
                <span class="material-icons">room_service</span>
                <span><?= te('Fasilitas Tambahan') ?></span>
            </a>
            <a href="booking_management.php" class="nav-item">
                <span class="material-icons">book_online</span>
                <span><?= te('Pemesanan') ?></span>
            </a>
            <a href="activity_log.php" class="nav-item">
                <span class="material-icons">history</span>
                <span><?= te('Log Aktivitas') ?></span>
            </a>
            <a href="../auth/logout.php" class="nav-item logout">
                <span class="material-icons">logout</span>
                <span><?= te('Keluar') ?></span>
            </a>
        </nav>
    </div>

    <main class="main-content" id="main-content">
        <header class="main-header">
            <div class="header-left">
                <h1><?= te('Manajemen Kamar') ?></h1>
                <p class="header-subtitle"><?= te('Kelola tipe kamar dan harga untuk hotel Anda') ?></p>
            </div>
            <div class="header-right">
                <button class="action-btn secondary" onclick="showAddTypeModal()">
                    <span class="material-icons">category</span>
                    <?= te('Tambah Tipe Kamar') ?>
                </button>
                <div class="header-actions">
                    <button class="action-btn" onclick="showAddRoomModal()">
                        <span class="material-icons">add</span>
                        Add Room
                    </button>
                </div>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="notification <?= $message_type === 'error' ? 'error' : 'success' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Hotel Selection -->
        <section class="hotel-selection">
            <h2 class="section-title">Select Hotel</h2>
            <div class="hotel-selector">
                <select id="hotelSelect" onchange="loadHotelRooms()">
                    <option value="">Choose a hotel...</option>
                    <?php foreach ($hotels as $hotel): ?>
                        <option value="<?= htmlspecialchars($hotel['hotel_id']) ?>" <?= ($selected_hotel_id === $hotel['hotel_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($hotel['nama_hotel']) ?> - <?= htmlspecialchars($hotel['kota']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </section>

        <!-- Rooms List -->
        <?php if ($selected_hotel): ?>
            <section class="rooms-section">
                <h2 class="section-title">Rooms in <?= htmlspecialchars($selected_hotel['nama_hotel']) ?></h2>
                <div class="rooms-grid">
                    <?php if (empty($rooms)): ?>
                        <div class="empty-state">
                            <span class="material-icons">bed</span>
                            <h3><?= te('Belum ada kamar') ?></h3>
                            <p><?= te('Tambahkan kamar pertama Anda untuk memulai') ?></p>
                            <button class="action-btn" onclick="showAddRoomModal()">
                                <span class="material-icons">add</span>
                                <?= te('Tambah Kamar') ?>
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($rooms as $room): ?>
                            <?php
                            $available = max(0, (int)$room['stok_total'] - (int)$room['terbooking']);
                            ?>
                            <?php
                            $room_facility_ids = [];
                            if (!empty($room['facilities'])) {
                                foreach ($room['facilities'] as $fac) {
                                    $room_facility_ids[] = $fac['fasilitas_id'];
                                }
                            }
                            ?>
                            <div class="room-card"
                                data-hotel-id="<?= htmlspecialchars($room['hotel_id']) ?>"
                                data-tipe-id="<?= htmlspecialchars($room['tipe_id']) ?>"
                                data-nama-tipe="<?= htmlspecialchars($room['nama_tipe']) ?>"
                                data-harga="<?= htmlspecialchars($room['harga']) ?>"
                                data-stok="<?= htmlspecialchars($room['stok_total']) ?>"
                                data-terbooking="<?= htmlspecialchars($room['terbooking']) ?>"
                                data-view="<?= htmlspecialchars($room['view']) ?>"
                                data-status="<?= htmlspecialchars($room['status']) ?>"
                                data-facilities="<?= htmlspecialchars(json_encode($room_facility_ids)) ?>">
                                <div class="room-header">
                                    <h3><?= htmlspecialchars($room['nama_tipe']) ?> (<?= htmlspecialchars($room['tipe_id']) ?>)</h3>
                                    <div class="room-actions">
                                        <button class="edit-btn" onclick="editRoom(this)">
                                            <span class="material-icons">edit</span>
                                        </button>
                                        <button class="delete-btn" onclick="deleteRoom('<?= htmlspecialchars($room['hotel_id']) ?>','<?= htmlspecialchars($room['tipe_id']) ?>')">
                                            <span class="material-icons">delete</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="room-details">
                                    <div class="room-price">
                                        <span class="price-label">Price:</span>
                                        <span class="price-value">Rp <?= number_format($room['harga'], 0, ',', '.') ?>/night</span>
                                    </div>
                                    <div class="room-capacity">
                                        <span class="material-icons">people</span>
                                        <span><?= (int)$room['kapasitas_standar'] ?> person(s)</span>
                                    </div>
                                    <div class="room-stock">
                                        <span class="material-icons">inventory</span>
                                        <span>Stok: <?= (int)$room['stok_total'] ?> | Dibooking: <?= (int)$room['terbooking'] ?> | Tersedia: <?= $available ?></span>
                                    </div>
                                    <div class="room-facilities">
                                        <span class="facilities-label">View:</span>
                                        <span><?= htmlspecialchars($room['view']) ?> | Status: <?= htmlspecialchars($room['status']) ?></span>
                                    </div>
                                    <?php if (!empty($room['deskripsi'])): ?>
                                        <div class="room-description">
                                            <p><?= htmlspecialchars($room['deskripsi']) ?></p>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($room['facilities'])): ?>
                                        <div class="room-facilities">
                                            <span class="facilities-label-main">Room Facilities:</span>
                                            <div class="room-facilities-tags">
                                                <?php foreach ($room['facilities'] as $fac): ?>
                                                    <span class="facility-tag">
                                                        <?php if (!empty($fac['icon'])): ?>
                                                            <span class="material-icons"><?= htmlspecialchars($fac['icon']) ?></span>
                                                        <?php endif; ?>
                                                        <?= htmlspecialchars($fac['nama_fasilitas']) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        <?php else: ?>
            <section class="rooms-section">
                <div class="empty-state">
                    <span class="material-icons">hotel</span>
                    <h3><?= te('Pilih hotel terlebih dahulu') ?></h3>
                    <p><?= te('Silakan pilih hotel untuk mengelola tipe kamar.') ?></p>
                </div>
            </section>
        <?php endif; ?>

        <!-- Add/Edit Room Modal -->
        <div id="roomModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="modalTitle">Add New Room</h2>
                    <button class="close-btn" onclick="closeRoomModal()">
                        <span class="material-icons">close</span>
                    </button>
                </div>
                <form id="roomForm" method="post" autocomplete="off">
                    <input type="hidden" name="form_action" id="formAction" value="add_room">
                    <input type="hidden" name="hotel_id" id="formHotelId" value="<?= htmlspecialchars($selected_hotel['hotel_id'] ?? '') ?>">
                    <input type="hidden" name="original_tipe_id" id="formOriginalTipeId" value="">

                    <div class="form-group">
                        <label for="tipe_kamar">Room Type</label>
                        <select id="tipe_id" name="tipe_id" required>
                            <option value="">-- Select room type --</option>
                            <?php foreach ($room_types as $type): ?>
                                <option value="<?= htmlspecialchars($type['tipe_id']) ?>">
                                    <?= htmlspecialchars($type['nama_tipe']) ?> (<?= htmlspecialchars($type['tipe_id']) ?>) - Kapasitas <?= (int)$type['kapasitas_standar'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="tipeDisplay" class="readonly-field" style="display:none;"></div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="harga">Price per Night (Rp)</label>
                            <input type="number" id="harga" name="harga" min="0" step="1000" required>
                        </div>
                        <div class="form-group">
                            <label for="stok_total">Room Stock</label>
                            <input type="number" id="stok_total" name="stok_total" min="1" max="999" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="view">Default View</label>
                        <select id="view" name="view" required>
                            <?php foreach ($view_options as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" required>
                            <?php foreach ($status_options as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-width" id="facilitiesSection">
                        <label>Room Facilities</label>
                        <div class="facilities-info" id="noHotelFacilitiesMsg">
                            <span class="material-icons">info</span>
                            <p>Pilih hotel terlebih dahulu atau pastikan hotel memiliki fasilitas yang sudah ditambahkan.</p>
                        </div>
                        <div class="facilities-checkboxes" id="facilitiesContainer" style="display: none;">
                            <!-- Facilities will be loaded here via JavaScript -->
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="cancel-btn" onclick="closeRoomModal()">Cancel</button>
                        <button type="submit" class="submit-btn">Save Room</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add Room Type Modal -->
        <div id="typeModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><?= te('Tambah Tipe Kamar') ?></h2>
                    <button class="close-btn" onclick="closeTypeModal()">
                        <span class="material-icons">close</span>
                    </button>
                </div>
                <form id="typeForm" method="post" autocomplete="off">
                    <input type="hidden" name="form_action" value="add_type">

                    <div class="form-group">
                        <label for="tipe_id_input">Room Type ID*</label>
                        <input type="text" id="tipe_id_input" name="tipe_id" maxlength="10" placeholder="Contoh: DELUXE" required>
                        <small>ID 2-10 karakter, huruf/angka/underscore.</small>
                    </div>

                    <div class="form-group">
                        <label for="nama_tipe_input">Room Type Name*</label>
                        <input type="text" id="nama_tipe_input" name="nama_tipe" maxlength="50" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="kapasitas_input">Standard Capacity*</label>
                            <input type="number" id="kapasitas_input" name="kapasitas_standar" min="1" max="10" required>
                        </div>
                        <div class="form-group">
                            <label for="ukuran_input">Standard Size*</label>
                            <input type="text" id="ukuran_input" name="ukuran_standar" placeholder="Contoh: 32m²" maxlength="20" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="deskripsi_input">Description</label>
                        <textarea id="deskripsi_input" name="deskripsi" rows="3" placeholder="Deskripsi singkat fasilitas / keunggulan"></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="cancel-btn" onclick="closeTypeModal()">Cancel</button>
                        <button type="submit" class="submit-btn">Save Type</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        const sidebar = document.getElementById('owner-sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        const mainContent = document.getElementById('main-content');

        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        });

        // Hotel facilities data from PHP
        const hotelFacilities = <?= json_encode($hotel_facilities) ?>;

        function loadHotelRooms() {
            const hotelId = document.getElementById('hotelSelect').value;
            if (hotelId) {
                window.location.href = `room_management.php?hotel_id=${hotelId}`;
            }
        }

        function loadFacilitiesCheckboxes(selectedFacilities = []) {
            const container = document.getElementById('facilitiesContainer');
            const noFacilitiesMsg = document.getElementById('noHotelFacilitiesMsg');

            if (!hotelFacilities || hotelFacilities.length === 0) {
                container.style.display = 'none';
                noFacilitiesMsg.style.display = 'flex';
                return;
            }

            container.style.display = 'grid';
            noFacilitiesMsg.style.display = 'none';
            container.innerHTML = '';

            hotelFacilities.forEach(facility => {
                const isChecked = selectedFacilities.includes(facility.fasilitas_id);
                const checkboxItem = document.createElement('div');
                checkboxItem.className = 'facility-checkbox-item';
                checkboxItem.innerHTML = `
                    <input type="checkbox" 
                           name="room_facilities[]" 
                           id="fac_${facility.fasilitas_id}" 
                           value="${facility.fasilitas_id}"
                           ${isChecked ? 'checked' : ''}>
                    ${facility.icon ? `<span class="material-icons">${facility.icon}</span>` : ''}
                    <label for="fac_${facility.fasilitas_id}">${facility.nama_fasilitas}</label>
                `;
                container.appendChild(checkboxItem);
            });
        }

        function showAddRoomModal() {
            const modal = document.getElementById('roomModal');
            const form = document.getElementById('roomForm');
            const title = document.getElementById('modalTitle');
            const selectedHotel = document.getElementById('hotelSelect').value;

            if (!selectedHotel) {
                alert('Please select a hotel first.');
                return;
            }

            title.textContent = 'Add New Room';
            form.reset();
            document.getElementById('formAction').value = 'add_room';
            document.getElementById('formOriginalTipeId').value = '';
            document.getElementById('formHotelId').value = selectedHotel;
            document.getElementById('tipe_id').style.display = '';
            document.getElementById('tipeDisplay').style.display = 'none';

            // Load facilities checkboxes (no pre-selected)
            loadFacilitiesCheckboxes([]);

            modal.style.display = 'flex';
        }

        function showAddTypeModal() {
            document.getElementById('typeForm').reset();
            document.getElementById('typeModal').style.display = 'flex';
        }

        function editRoom(button) {
            const modal = document.getElementById('roomModal');
            const form = document.getElementById('roomForm');
            const title = document.getElementById('modalTitle');
            const card = button.closest('.room-card');
            const dataset = card.dataset;

            form.reset();
            title.textContent = 'Update Room';
            document.getElementById('formAction').value = 'update_room';
            document.getElementById('formHotelId').value = dataset.hotelId;
            document.getElementById('formOriginalTipeId').value = dataset.tipeId;

            const tipeSelect = document.getElementById('tipe_id');
            tipeSelect.value = dataset.tipeId;
            tipeSelect.style.display = 'none';

            const tipeDisplay = document.getElementById('tipeDisplay');
            tipeDisplay.textContent = `${dataset.namaTipe} (${dataset.tipeId})`;
            tipeDisplay.style.display = 'block';

            document.getElementById('harga').value = dataset.harga;
            document.getElementById('stok_total').value = dataset.stok;
            document.getElementById('view').value = dataset.view;
            document.getElementById('status').value = dataset.status;

            // Load facilities with pre-selected ones
            const selectedFacilities = dataset.facilities ? JSON.parse(dataset.facilities) : [];
            loadFacilitiesCheckboxes(selectedFacilities);

            modal.style.display = 'flex';
        }

        function deleteRoom(hotelId, tipeId) {
            if (confirm('Are you sure you want to delete this room?')) {
                const form = document.createElement('form');
                form.method = 'post';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'form_action';
                actionInput.value = 'delete_room';
                form.appendChild(actionInput);

                const hotelInput = document.createElement('input');
                hotelInput.type = 'hidden';
                hotelInput.name = 'hotel_id';
                hotelInput.value = hotelId;
                form.appendChild(hotelInput);

                const tipeInput = document.createElement('input');
                tipeInput.type = 'hidden';
                tipeInput.name = 'tipe_id';
                tipeInput.value = tipeId;
                form.appendChild(tipeInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        function closeRoomModal() {
            document.getElementById('roomModal').style.display = 'none';
        }

        function closeTypeModal() {
            document.getElementById('typeModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('roomModal');
            const typeModal = document.getElementById('typeModal');
            if (event.target === modal) {
                closeRoomModal();
            }
            if (event.target === typeModal) {
                closeTypeModal();
            }
        }

        <?php if ($prefill_room): ?>
            document.addEventListener('DOMContentLoaded', () => {
                const modal = document.getElementById('roomModal');
                const form = document.getElementById('roomForm');
                const title = document.getElementById('modalTitle');

                form.reset();
                title.textContent = 'Update Room';
                document.getElementById('formAction').value = 'update_room';
                document.getElementById('formHotelId').value = '<?= htmlspecialchars($prefill_room['hotel_id']) ?>';
                document.getElementById('formOriginalTipeId').value = '<?= htmlspecialchars($prefill_room['tipe_id']) ?>';

                const tipeSelect = document.getElementById('tipe_id');
                tipeSelect.value = '<?= htmlspecialchars($prefill_room['tipe_id']) ?>';
                tipeSelect.style.display = 'none';

                const tipeDisplay = document.getElementById('tipeDisplay');
                tipeDisplay.textContent = '<?= htmlspecialchars($prefill_room['nama_tipe']) ?> (<?= htmlspecialchars($prefill_room['tipe_id']) ?>)';
                tipeDisplay.style.display = 'block';

                document.getElementById('harga').value = '<?= htmlspecialchars($prefill_room['harga']) ?>';
                document.getElementById('stok_total').value = '<?= htmlspecialchars($prefill_room['stok_total']) ?>';
                document.getElementById('view').value = '<?= htmlspecialchars($prefill_room['view']) ?>';
                document.getElementById('status').value = '<?= htmlspecialchars($prefill_room['status']) ?>';

                // Load facilities with pre-selected ones
                <?php
                $prefill_facility_ids = [];
                if (!empty($prefill_room['facilities'])) {
                    foreach ($prefill_room['facilities'] as $fac) {
                        $prefill_facility_ids[] = $fac['fasilitas_id'];
                    }
                }
                ?>
                const selectedFacilities = <?= json_encode($prefill_facility_ids) ?>;
                loadFacilitiesCheckboxes(selectedFacilities);

                modal.style.display = 'flex';
            });
        <?php endif; ?>
    </script>
</body>

</html>