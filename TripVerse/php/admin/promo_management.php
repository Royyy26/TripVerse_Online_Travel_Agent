<?php
session_start();

// Periksa apakah pengguna sudah login dan memiliki role 'admin'
if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Akses ditolak! Halaman ini hanya untuk admin.'); window.location='../home.php';</script>";
    exit;
}

require __DIR__ . '/../connect.php';
require_once __DIR__ . '/../_lang.php';

$id_user = $_SESSION['id_user'];

// Function untuk format Rupiah
function formatRupiah($angka)
{
    if ($angka === null || $angka === '') return 'Rp 0';
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Function untuk format angka ke format Indonesia (dengan titik sebagai pemisah ribuan)
function formatAngka($angka)
{
    if ($angka === null || $angka === '') return '0';
    return number_format($angka, 0, ',', '.');
}

// Function untuk parse input angka dari format Indonesia (menghapus titik)
function parseAngka($input)
{
    if (empty($input)) return 0;
    // Hapus semua karakter kecuali angka, titik, dan koma
    $clean = preg_replace('/[^\d.,]/', '', $input);
    
    // Jika ada koma desimal, ganti dengan titik
    if (strpos($clean, ',') !== false) {
        $clean = str_replace('.', '', $clean); // Hapus titik pemisah ribuan
        $clean = str_replace(',', '.', $clean); // Ganti koma desimal dengan titik
    } else {
        // Jika tidak ada koma, hapus semua titik (pemisah ribuan)
        $clean = str_replace('.', '', $clean);
    }
    
    return floatval($clean);
}

// Function untuk format tanggal
function formatDate($dateString)
{
    if (empty($dateString) || $dateString == '0000-00-00') return '-';
    return date('d M Y', strtotime($dateString));
}

// Function untuk format tanggal waktu
function formatDateTime($dateTimeString)
{
    if (empty($dateTimeString) || $dateTimeString == '0000-00-00 00:00:00') return '-';
    return date('d M Y H:i', strtotime($dateTimeString));
}

// Handle form submission untuk CRUD promo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $response = ['success' => false, 'message' => ''];

        try {
            switch ($action) {
                case 'add_promo':
                    $data = [
                        'diskon_id' => strtoupper(trim($_POST['diskon_id'])),
                        'nama_diskon' => trim($_POST['nama_diskon']),
                        'kode_promo' => trim($_POST['kode_promo']),
                        'tipe_diskon' => $_POST['tipe_diskon'],
                        'nilai_diskon' => $_POST['tipe_diskon'] == 'percentage' ? floatval($_POST['nilai_diskon']) : parseAngka($_POST['nilai_diskon']),
                        'minimal_pembelian' => parseAngka($_POST['minimal_pembelian']),
                        'maksimal_diskon' => !empty($_POST['maksimal_diskon']) ? parseAngka($_POST['maksimal_diskon']) : null,
                        'tanggal_mulai' => $_POST['tanggal_mulai'],
                        'tanggal_berakhir' => $_POST['tanggal_berakhir'],
                        'kuota' => !empty($_POST['kuota']) ? intval($_POST['kuota']) : null,
                        'status' => $_POST['status']
                    ];

                    // Validasi
                    if (empty($data['diskon_id']) || empty($data['nama_diskon'])) {
                        throw new Exception('ID Promo dan Nama Promo harus diisi');
                    }

                    if ($data['tanggal_mulai'] >= $data['tanggal_berakhir']) {
                        throw new Exception('Tanggal mulai harus sebelum tanggal berakhir');
                    }

                    if ($data['tipe_diskon'] == 'percentage' && ($data['nilai_diskon'] < 1 || $data['nilai_diskon'] > 100)) {
                        throw new Exception('Diskon persentase harus antara 1-100%');
                    }

                    if ($data['tipe_diskon'] == 'fixed' && $data['nilai_diskon'] <= 0) {
                        throw new Exception('Nilai diskon fixed harus lebih dari 0');
                    }

                    // Cek apakah ID promo sudah ada
                    $check_sql = "SELECT COUNT(*) as count FROM diskon_promo WHERE diskon_id = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("s", $data['diskon_id']);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result()->fetch_assoc();

                    if ($check_result['count'] > 0) {
                        throw new Exception('ID Promo sudah digunakan');
                    }

                    // Insert promo baru
                    $sql = "INSERT INTO diskon_promo (diskon_id, nama_diskon, kode_promo, tipe_diskon, nilai_diskon, 
                            minimal_pembelian, maksimal_diskon, tanggal_mulai, tanggal_berakhir, kuota, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param(
                        "ssssddddssi",
                        $data['diskon_id'],
                        $data['nama_diskon'],
                        $data['kode_promo'],
                        $data['tipe_diskon'],
                        $data['nilai_diskon'],
                        $data['minimal_pembelian'],
                        $data['maksimal_diskon'],
                        $data['tanggal_mulai'],
                        $data['tanggal_berakhir'],
                        $data['kuota'],
                        $data['status']
                    );

                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'Promo berhasil ditambahkan';
                        $response['data'] = $data;
                    } else {
                        throw new Exception('Gagal menambahkan promo: ' . $stmt->error);
                    }
                    $stmt->close();
                    break;

                case 'edit_promo':
                    $diskon_id = $_POST['diskon_id'];
                    $data = [
                        'nama_diskon' => trim($_POST['nama_diskon']),
                        'kode_promo' => trim($_POST['kode_promo']),
                        'tipe_diskon' => $_POST['tipe_diskon'],
                        'nilai_diskon' => $_POST['tipe_diskon'] == 'percentage' ? floatval($_POST['nilai_diskon']) : parseAngka($_POST['nilai_diskon']),
                        'minimal_pembelian' => parseAngka($_POST['minimal_pembelian']),
                        'maksimal_diskon' => !empty($_POST['maksimal_diskon']) ? parseAngka($_POST['maksimal_diskon']) : null,
                        'tanggal_mulai' => $_POST['tanggal_mulai'],
                        'tanggal_berakhir' => $_POST['tanggal_berakhir'],
                        'kuota' => !empty($_POST['kuota']) ? intval($_POST['kuota']) : null,
                        'status' => $_POST['status']
                    ];

                    // Validasi
                    if (empty($data['nama_diskon'])) {
                        throw new Exception('Nama Promo harus diisi');
                    }

                    if ($data['tanggal_mulai'] >= $data['tanggal_berakhir']) {
                        throw new Exception('Tanggal mulai harus sebelum tanggal berakhir');
                    }

                    if ($data['tipe_diskon'] == 'percentage' && ($data['nilai_diskon'] < 1 || $data['nilai_diskon'] > 100)) {
                        throw new Exception('Diskon persentase harus antara 1-100%');
                    }

                    if ($data['tipe_diskon'] == 'fixed' && $data['nilai_diskon'] <= 0) {
                        throw new Exception('Nilai diskon fixed harus lebih dari 0');
                    }

                    // Update promo
                    $sql = "UPDATE diskon_promo SET 
                            nama_diskon = ?,
                            kode_promo = ?,
                            tipe_diskon = ?,
                            nilai_diskon = ?,
                            minimal_pembelian = ?,
                            maksimal_diskon = ?,
                            tanggal_mulai = ?,
                            tanggal_berakhir = ?,
                            kuota = ?,
                            status = ?
                            WHERE diskon_id = ?";

                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param(
                        "sssddddssis",
                        $data['nama_diskon'],
                        $data['kode_promo'],
                        $data['tipe_diskon'],
                        $data['nilai_diskon'],
                        $data['minimal_pembelian'],
                        $data['maksimal_diskon'],
                        $data['tanggal_mulai'],
                        $data['tanggal_berakhir'],
                        $data['kuota'],
                        $data['status'],
                        $diskon_id
                    );

                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'Promo berhasil diperbarui';
                    } else {
                        throw new Exception('Gagal memperbarui promo: ' . $stmt->error);
                    }
                    $stmt->close();
                    break;

                case 'delete_promo':
                    $diskon_id = $_POST['diskon_id'];

                    // Cek apakah promo pernah digunakan
                    $check_sql = "SELECT COUNT(*) as count FROM penggunaan_diskon WHERE diskon_id = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("s", $diskon_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result()->fetch_assoc();

                    if ($check_result['count'] > 0) {
                        throw new Exception('Tidak dapat menghapus promo yang sudah pernah digunakan');
                    }

                    // Delete promo
                    $sql = "DELETE FROM diskon_promo WHERE diskon_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("s", $diskon_id);

                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'Promo berhasil dihapus';
                    } else {
                        throw new Exception('Gagal menghapus promo: ' . $stmt->error);
                    }
                    $stmt->close();
                    break;

                case 'toggle_status':
                    $diskon_id = $_POST['diskon_id'];
                    $current_status = $_POST['current_status'];
                    $new_status = ($current_status == 'active') ? 'inactive' : 'active';

                    $sql = "UPDATE diskon_promo SET status = ? WHERE diskon_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ss", $new_status, $diskon_id);

                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'Status promo berhasil diubah';
                        $response['new_status'] = $new_status;
                    } else {
                        throw new Exception('Gagal mengubah status promo: ' . $stmt->error);
                    }
                    $stmt->close();
                    break;
            }
        } catch (Exception $e) {
            $response['message'] = $e->getMessage();
        }

        echo json_encode($response);
        exit;
    }
}

// Handle AJAX request untuk get promo details
if (isset($_GET['action']) && $_GET['action'] == 'get_promo_details') {
    if (isset($_GET['diskon_id'])) {
        $diskon_id = $_GET['diskon_id'];

        $sql = "SELECT * FROM diskon_promo WHERE diskon_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $diskon_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $promo = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'data' => $promo
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Promo tidak ditemukan'
            ]);
        }
        exit;
    }
}

// Handle AJAX request untuk get promo usage
if (isset($_GET['action']) && $_GET['action'] == 'get_promo_usage') {
    if (isset($_GET['diskon_id'])) {
        $diskon_id = $_GET['diskon_id'];

        // Query untuk mendapatkan penggunaan diskon
        $sql = "SELECT 
                pd.id_penggunaan,
                pd.booking_id,
                pd.id_user,
                pd.tanggal_digunakan,
                pd.nilai_diskon,
                c.nama as customer_name,
                c.email as customer_email,
                c.no_hp as customer_phone,
                bh.total_harga,
                bh.check_in,
                bh.check_out,
                h.nama_hotel
                FROM penggunaan_diskon pd
                LEFT JOIN booking_hotel bh ON pd.booking_id = bh.booking_id
                LEFT JOIN customer c ON bh.customer_id = c.customer_id
                LEFT JOIN hotel h ON bh.hotel_id = h.hotel_id
                WHERE pd.diskon_id = ?
                ORDER BY pd.tanggal_digunakan DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $diskon_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $usage_data = [];
        $total_discount = 0;
        $total_bookings = 0;

        while ($row = $result->fetch_assoc()) {
            $usage_data[] = $row;
            $total_discount += $row['nilai_diskon'];
            $total_bookings++;
        }

        echo json_encode([
            'success' => true,
            'data' => $usage_data,
            'stats' => [
                'total_usage' => $total_bookings,
                'total_discount' => $total_discount,
                'avg_discount' => $total_bookings > 0 ? $total_discount / $total_bookings : 0
            ]
        ]);
        exit;
    }
}

// Ambil data admin
$query = "SELECT username, email, first_name, last_name, profile_picture FROM user WHERE id_user = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $id_user);
$stmt->execute();
$result = $stmt->get_result();

if ($data = $result->fetch_assoc()) {
    $username   = $data['username'];
    $email      = $data['email'];
    $firstName  = $data['first_name'];
    $lastName   = $data['last_name'];
    $foto       = $data['profile_picture'] ?: '../../images/default.jpg';
} else {
    $username = "Unknown";
    $email = "unknown@tripverse.com";
    $firstName = $lastName = "-";
    $foto = "../../images/default.jpg";
}

// Notifikasi (sama seperti dashboard.php: jumlah booking berstatus Pending)
$notifCountResult = $conn->query("SELECT COUNT(*) as notifications FROM booking_hotel WHERE status = 'Pending'");
$notificationCount = $notifCountResult ? ($notifCountResult->fetch_assoc()['notifications'] ?? 0) : 0;

// Ambil semua promo
$promo_query = "SELECT * FROM diskon_promo ORDER BY 
                CASE WHEN status = 'active' THEN 1 ELSE 2 END,
                tanggal_berakhir DESC";
$promo_result = $conn->query($promo_query);

// Statistik promo
$stats_query = "SELECT 
                COUNT(*) as total_promo,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_promo,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_promo,
                SUM(terpakai) as total_used,
                AVG(CASE WHEN tipe_diskon = 'percentage' THEN nilai_diskon ELSE NULL END) as avg_percentage_discount,
                AVG(CASE WHEN tipe_diskon = 'fixed' THEN nilai_diskon ELSE NULL END) as avg_fixed_discount
                FROM diskon_promo";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>TripVerse Admin - Promo & Discount Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../../css/dashboard.css?v=2.0.0" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #FF7A3D;
            --secondary-color: #0F172B;
            --success-color: #1baf7a;
            --info-color: #2a78d6;
            --warning-color: #eda100;
            --danger-color: #e34948;
            --light-color: #f5f6f8;
            --dark-color: #0F172B;
            --text-color: #1e2635;
            --text-light: #6b7280;
            --border-radius: 12px;
            --box-shadow: 0 4px 12px rgba(15, 23, 43, 0.08);
            --box-shadow-hover: 0 8px 24px rgba(15, 23, 43, 0.14);
            --transition: all 0.3s ease;
            --gradient-primary: linear-gradient(135deg, #FEA116, #FF7A3D);
            --gradient-success: linear-gradient(135deg, #1baf7a, #3fcf9c);
            --gradient-warning: linear-gradient(135deg, #eda100, #f4b73a);
            --gradient-danger: linear-gradient(135deg, #e34948, #ef6e6d);
            --gradient-info: linear-gradient(135deg, #2a78d6, #4f92e3);
        }

        /* Animasi Keyframes */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                /* No trailing transform: a lingering non-"none" transform (even a
                   no-op translateY(0)) creates a stacking context on .main-header
                   that broke the fixed sidebar's ability to overlay it. */
            }
        }

        @keyframes fadeInLeft {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.98);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes fadeInStagger {
            0% {
                opacity: 0;
                transform: translateY(15px);
            }

            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Main Content Styles dengan Animasi */
        .main-content {
            margin-left: 280px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            background: #f8f9fa;
            animation: fadeIn 0.4s ease-out;
        }

        .main-content.expanded {
            margin-left: 80px;
        }

        /* Main Header dengan Animasi */
        .main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 30px;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
            /* No position:sticky here: no other admin page pins its header while
               scrolling, so this scrolls away with the page like everywhere else. */
        }

        /* .menu-toggle intentionally has no override here: it now falls back to
           dashboard.css's shared dark-navy-square button, so it looks identical
           to every other admin page instead of the mismatched white circle this
           page used to have. .header-actions still needs margin-left:auto since
           the shared button is position:fixed and out of the header's flex flow. */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-left: auto;
            animation: fadeInLeft 0.4s ease-out 0.2s both;
            opacity: 0;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary-color);
            animation: fadeInScale 0.3s ease-out 0.3s both;
            opacity: 0;
        }

        /* Promo Management Section dengan Animasi */
        .promo-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
            margin-bottom: 30px;
            border-left: 4px solid var(--primary-color);
            transition: var(--transition);
            animation: fadeIn 0.4s ease-out 0.2s both;
            opacity: 0;
        }

        .promo-section:hover {
            box-shadow: var(--box-shadow-hover);
        }

        .promo-section h2 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 20px;
            color: var(--dark-color);
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeInLeft 0.3s ease-out 0.3s both;
            opacity: 0;
        }

        /* Stats Cards dengan Animasi */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
            border: none;
            opacity: 0;
            animation: fadeInStagger 0.3s ease-out forwards;
        }

        /* Stagger animation untuk stat cards */
        .stat-card:nth-child(1) {
            animation-delay: 0.25s;
        }

        .stat-card:nth-child(2) {
            animation-delay: 0.3s;
        }

        .stat-card:nth-child(3) {
            animation-delay: 0.35s;
        }

        .stat-card:nth-child(4) {
            animation-delay: 0.4s;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
        }

        .stat-card:nth-child(2)::before {
            background: var(--gradient-success);
        }

        .stat-card:nth-child(3)::before {
            background: var(--gradient-warning);
        }

        .stat-card:nth-child(4)::before {
            background: var(--gradient-info);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-hover);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 20px;
            color: white;
            background: var(--gradient-primary);
            animation: fadeInScale 0.3s ease-out 0.5s both;
            opacity: 0;
        }

        .stat-card:nth-child(2) .stat-icon {
            background: var(--gradient-success);
        }

        .stat-card:nth-child(3) .stat-icon {
            background: var(--gradient-warning);
        }

        .stat-card:nth-child(4) .stat-icon {
            background: var(--gradient-info);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin: 10px 0;
            color: var(--dark-color);
            animation: fadeIn 0.3s ease-out 0.6s both;
            opacity: 0;
        }

        .stat-label {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 5px;
            font-weight: 500;
            animation: fadeIn 0.3s ease-out 0.7s both;
            opacity: 0;
        }

        /* Action Bar dengan Animasi */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            border: 1px solid #dee2e6;
            animation: fadeIn 0.4s ease-out 0.3s both;
            opacity: 0;
        }

        .search-container {
            flex: 1;
            max-width: 400px;
            animation: fadeInLeft 0.3s ease-out 0.4s both;
            opacity: 0;
        }

        .search-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
            animation: fadeInScale 0.3s ease-out 0.5s both;
            opacity: 0;
        }

        .search-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 122, 61, 0.1);
            outline: none;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            animation: fadeIn 0.3s ease-out 0.4s both;
            opacity: 0;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #E8672B;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 122, 61, 0.3);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #17996b;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #cf3c3b;
            transform: translateY(-2px);
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn-info:hover {
            background: #2468bd;
            transform: translateY(-2px);
        }

        /* Promo Table Container dengan Animasi */
        .promo-table-container {
            overflow-x: auto;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            animation: fadeIn 0.4s ease-out 0.4s both;
            opacity: 0;
        }

        .promo-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .promo-table th,
        .promo-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .promo-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--text-light);
            position: sticky;
            top: 0;
        }

        .promo-table tr {
            animation: fadeInStagger 0.3s ease-out forwards;
            opacity: 0;
        }

        /* Stagger animation untuk table rows */
        .promo-table tr:nth-child(1) {
            animation-delay: 0.45s;
        }

        .promo-table tr:nth-child(2) {
            animation-delay: 0.5s;
        }

        .promo-table tr:nth-child(3) {
            animation-delay: 0.55s;
        }

        .promo-table tr:nth-child(4) {
            animation-delay: 0.6s;
        }

        .promo-table tr:nth-child(5) {
            animation-delay: 0.65s;
        }

        .promo-table tr:nth-child(6) {
            animation-delay: 0.7s;
        }

        .promo-table tr:nth-child(7) {
            animation-delay: 0.75s;
        }

        .promo-table tr:nth-child(8) {
            animation-delay: 0.8s;
        }

        .promo-table tr:hover {
            background: #f8f9fa;
        }

        /* Status Badge */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            animation: fadeInScale 0.2s ease-out;
        }

        .status-active {
            background: rgba(76, 175, 80, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .status-inactive {
            background: rgba(227, 73, 72, 0.1);
            color: var(--danger-color);
            border: 1px solid rgba(227, 73, 72, 0.2);
        }

        /* Discount Type Badge */
        .discount-type {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            animation: fadeInScale 0.2s ease-out;
        }

        .discount-percentage {
            background: rgba(33, 150, 243, 0.1);
            color: var(--info-color);
            border: 1px solid rgba(33, 150, 243, 0.2);
        }

        .discount-fixed {
            background: rgba(255, 152, 0, 0.1);
            color: var(--warning-color);
            border: 1px solid rgba(255, 152, 0, 0.2);
        }

        /* Action Buttons dengan Animasi */
        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            color: var(--text-light);
            border: 1px solid #e9ecef;
            cursor: pointer;
            transition: all 0.3s ease;
            animation: fadeInScale 0.2s ease-out;
        }

        .action-btn:hover {
            transform: translateY(-2px);
        }

        .edit-btn:hover {
            background: var(--info-color);
            color: white;
            border-color: var(--info-color);
        }

        .delete-btn:hover {
            background: var(--danger-color);
            color: white;
            border-color: var(--danger-color);
        }

        .toggle-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .usage-btn:hover {
            background: var(--success-color);
            color: white;
            border-color: var(--success-color);
        }

        /* Empty State dengan Animasi */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
            animation: fadeIn 0.4s ease-out;
        }

        .empty-state .material-icons {
            font-size: 64px;
            margin-bottom: 15px;
            color: #ccc;
            animation: fadeInScale 0.4s ease-out 0.2s both;
            opacity: 0;
        }

        .empty-state h3 {
            margin: 0 0 10px 0;
            font-size: 18px;
            color: var(--dark-color);
            font-weight: 600;
            animation: fadeIn 0.4s ease-out 0.3s both;
            opacity: 0;
        }

        .empty-state p {
            margin: 0;
            font-size: 14px;
            color: var(--text-light);
            animation: fadeIn 0.4s ease-out 0.4s both;
            opacity: 0;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-content {
            background-color: #fff;
            margin: 3% auto;
            border-radius: 16px;
            width: 90%;
            max-width: 800px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow: hidden;
        }

        .modal-content.large {
            max-width: 1000px;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-60px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 30px;
            background: linear-gradient(135deg, var(--primary-color), #FF7A3D);
            color: white;
            position: relative;
        }

        .modal-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.5), transparent);
        }

        .modal-header h2 {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeInLeft 0.3s ease-out;
        }

        .close-modal {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: white;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            padding: 0;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
            animation: fadeInScale 0.3s ease-out;
        }

        .close-modal:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
            max-height: 70vh;
            overflow-y: auto;
            animation: fadeIn 0.4s ease-out 0.1s both;
            opacity: 0;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 20px 30px;
            border-top: 1px solid #f0f0f0;
            background: #f8f9fa;
            animation: fadeIn 0.4s ease-out 0.2s both;
            opacity: 0;
        }

        /* Form Styles dengan Animasi */
        .form-group {
            margin-bottom: 20px;
            animation: fadeInStagger 0.3s ease-out forwards;
            opacity: 0;
        }

        /* Stagger animation untuk form groups */
        .form-group:nth-child(1) {
            animation-delay: 0.15s;
        }

        .form-group:nth-child(2) {
            animation-delay: 0.2s;
        }

        .form-group:nth-child(3) {
            animation-delay: 0.25s;
        }

        .form-group:nth-child(4) {
            animation-delay: 0.3s;
        }

        .form-group:nth-child(5) {
            animation-delay: 0.35s;
        }

        .form-group:nth-child(6) {
            animation-delay: 0.4s;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-color);
            font-size: 14px;
        }

        .form-group label .required {
            color: var(--danger-color);
            margin-left: 2px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background-color: #fff;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 122, 61, 0.1);
            outline: none;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-helper {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 4px;
        }

        /* Currency Input Styles */
        .currency-input-container {
            position: relative;
        }

        .currency-prefix {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            font-weight: 500;
            pointer-events: none;
        }

        .currency-input {
            padding-left: 40px !important;
        }

        .percentage-input {
            padding-right: 40px !important;
        }

        .percentage-suffix {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            font-weight: 500;
            pointer-events: none;
        }

        /* Usage Table Styles dengan Animasi */
        .usage-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            border: 1px solid #dee2e6;
            animation: fadeIn 0.4s ease-out 0.1s both;
            opacity: 0;
        }

        .usage-stat {
            text-align: center;
            padding: 15px;
            animation: fadeInStagger 0.3s ease-out forwards;
            opacity: 0;
        }

        /* Stagger animation untuk usage stats */
        .usage-stat:nth-child(1) {
            animation-delay: 0.15s;
        }

        .usage-stat:nth-child(2) {
            animation-delay: 0.2s;
        }

        .usage-stat:nth-child(3) {
            animation-delay: 0.25s;
        }

        .usage-stat:nth-child(4) {
            animation-delay: 0.3s;
        }

        .usage-stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .usage-stat-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .usage-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            animation: fadeIn 0.4s ease-out 0.2s both;
            opacity: 0;
        }

        .usage-table th,
        .usage-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }

        .usage-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--text-light);
            position: sticky;
            top: 0;
        }

        .usage-table tr {
            animation: fadeInStagger 0.3s ease-out forwards;
            opacity: 0;
        }

        /* Stagger animation untuk usage table rows */
        .usage-table tr:nth-child(1) {
            animation-delay: 0.25s;
        }

        .usage-table tr:nth-child(2) {
            animation-delay: 0.3s;
        }

        .usage-table tr:nth-child(3) {
            animation-delay: 0.35s;
        }

        .usage-table tr:nth-child(4) {
            animation-delay: 0.4s;
        }

        .usage-table tr:hover {
            background: #f8f9fa;
        }

        .customer-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .customer-name {
            font-weight: 600;
            color: var(--dark-color);
        }

        .customer-email {
            font-size: 12px;
            color: var(--text-light);
        }

        .hotel-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .hotel-name {
            font-weight: 500;
            color: var(--primary-color);
        }

        .booking-date {
            font-size: 12px;
            color: var(--text-light);
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            animation: slideInRight 0.3s ease-out;
        }

        .notification-success {
            background: var(--success-color);
        }

        .notification-error {
            background: var(--danger-color);
        }

        .notification-info {
            background: var(--info-color);
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        /* Loading State */
        .loading {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
            animation: fadeIn 0.3s ease-out;
        }

        .loading .material-icons {
            font-size: 48px;
            margin-bottom: 15px;
            color: #ccc;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Tabs dengan Animasi */
        .tabs {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 20px;
            animation: fadeIn 0.4s ease-out 0.3s both;
            opacity: 0;
        }

        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }

        .tab:hover {
            color: var(--primary-color);
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease-out;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }

            .main-content.expanded {
                margin-left: 0;
            }

            .action-bar {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }

            .search-container {
                max-width: 100%;
            }

            .promo-table th,
            .promo-table td {
                padding: 10px;
                font-size: 13px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal-content {
                margin: 5% auto;
                width: 95%;
            }

            .modal-body {
                padding: 20px;
            }

            .modal-header {
                padding: 20px;
            }

            .modal-footer {
                flex-direction: column;
                padding: 15px 20px;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .action-buttons {
                flex-wrap: wrap;
            }

            .tabs {
                overflow-x: auto;
                white-space: nowrap;
            }

            .tab {
                padding: 10px 16px;
                font-size: 13px;
            }

            .usage-table th,
            .usage-table td {
                padding: 8px 10px;
                font-size: 12px;
            }

            .usage-stats {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="../../img/logo.png" alt="TripVerse Logo" class="sidebar-brand-logo" />
            <div class="sidebar-brand-text">
                <span class="sidebar-brand-title">TripVerse</span>
                <span class="sidebar-brand-subtitle"><?= te('Dasbor Admin') ?></span>
            </div>
        </div>

        <div class="sidebar-brand-lang">
            <?php include __DIR__ . '/../_lang_switch_inner.php'; ?>
        </div>

        <div class="profile-header">
            <div class="profile-photo-section">
                <div class="profile-photo-container">
                    <img src="../../uploads/<?php echo htmlspecialchars($foto); ?>"
                        alt="Profile Photo"
                        class="profile-photo"
                        id="profilePhoto"
                        onerror="this.src='../../images/default.jpg'">

                    <div class="profile-overlay">
                        <span class="material-icons">edit</span>
                    </div>
                </div>

                <div class="profile-info">
                    <h2><?= htmlspecialchars($firstName . ' ' . $lastName); ?></h2>
                    <p><?= htmlspecialchars($email); ?></p>

                    <div class="user-dropdown">
                        <button class="user-info" aria-haspopup="true" aria-expanded="false" onclick="toggleDropdown(this)">
                            <span class="dropdown-text"><?= te('Kelola Akun') ?></span>
                            <span class="material-icons dropdown-arrow">expand_more</span>
                        </button>

                        <div class="dropdown-content" role="menu" aria-hidden="true">
                            <a href="profile.php" class="dropdown-item">
                                <span class="material-icons">person</span>
                                <span>Edit Profile</span>
                            </a>
                            <a href="../auth/logout.php" class="dropdown-item">
                                <span class="material-icons">logout</span>
                                <span><?= te('Keluar') ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <nav>
            <!-- EXECUTIVE OVERVIEW -->
            <a href="dashboard.php">
                <span class="material-icons">dashboard</span>
                <span><?= te('Ringkasan Eksekutif') ?></span>
            </a>

            <!-- SUPPLIER APPROVAL -->
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="supplier_approvals.php">
                    <span class="material-icons">approval</span> <!-- atau groups, person_add -->
                    <span><?= te('Manajemen Supplier') ?></span>
                </a>
            <?php endif; ?>

            <!-- PROMO MANAGEMENT -->
            <a href="promo_management.php" class="active">
                <span class="material-icons">campaign</span> <!-- atau discount, local_offer -->
                <span><?= te('Manajemen Promo') ?></span>
            </a>

            <!-- ANALYTICS & INSIGHTS -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="analyticsDropdown">
                    <span class="material-icons">monitor</span> <!-- atau show_chart, trending_up -->
                    <span><?= te('Monitoring Performa') ?></span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="analyticsDropdown">
                    <a href="performance_analytics.php">
                        <span class="material-icons">bar_chart</span> <!-- atau assessment -->
                        <span><?= te('Statistik Performa') ?></span>
                    </a>
                    <a href="market_analysis.php">
                        <span class="material-icons">trending_up</span> <!-- atau timeline -->
                        <span><?= te('Tren Booking') ?></span>
                    </a>
                </div>
            </div>

            <!-- DECISION SUPPORT MODULES -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="decisionDropdown">
                    <span class="material-icons">analytics</span> <!-- atau calculate, functions -->
                    <span><?= te('Analisis Statistik') ?></span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="decisionDropdown">
                    <a href="revenue_optimization.php">
                        <span class="material-icons">attach_money</span> <!-- atau paid -->
                        <span><?= te('Statistik Pendapatan') ?></span>
                    </a>
                    <a href="occupancy_analysis.php">
                        <span class="material-icons">king_bed</span> <!-- atau hotel -->
                        <span><?= te('Statistik Okupansi') ?></span>
                    </a>
                    <a href="alos_analysis.php">
                        <span class="material-icons">calendar_today</span> <!-- atau date_range -->
                        <span><?= te('Statistik ALOS') ?></span>
                    </a>
                </div>
            </div>

            <!-- CUSTOMER INTELLIGENCE -->
            <a href="customerdss.php">
                <span class="material-icons">people</span> <!-- atau sentiment_satisfied -->
                <span><?= te('Statistik Pelanggan') ?></span>
            </a>

            <!-- LOGOUT -->
            <a href="../auth/logout.php">
                <span class="material-icons">exit_to_app</span>
                <span><?= te('Keluar') ?></span>
            </a>
        </nav>
    </div>

    <main class="main-content" id="main-content">
        <!-- Outside <header>: this page's header is position:sticky with its own
             z-index, which traps any fixed-position descendant inside that local
             stacking context regardless of the descendant's own z-index — the
             button would render below the sidebar overlay. Living as a sibling
             lets it compete for stacking at the top level like on every other page. -->
        <button id="toggleSidebar" class="menu-toggle" aria-label="Toggle sidebar">
            <span class="material-icons">menu</span>
        </button>

        <header class="main-header">
            <div class="header-actions">
                <div class="notification-bell" id="notificationBell" tabindex="0" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                    <span class="material-icons bell-icon">notifications</span>
                    <span class="notification-badge" id="notificationCount"><?= $notificationCount ?></span>
                </div>

                <div class="user-menu">
                    <img src="../../uploads/<?php echo htmlspecialchars($foto); ?>" alt="User Avatar" class="user-avatar" />
                </div>
            </div>
        </header>

        <!-- Statistics Section -->
        <div class="promo-section">
            <h2><i class="material-icons">assessment</i> <?= te('Ringkasan Statistik Promo') ?></h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="material-icons">local_offer</i>
                    </div>
                    <div class="stat-label">Total Promo</div>
                    <div class="stat-value"><?= number_format($stats['total_promo'] ?? 0) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="material-icons">check_circle</i>
                    </div>
                    <div class="stat-label"><?= te('Promo Aktif') ?></div>
                    <div class="stat-value"><?= number_format($stats['active_promo'] ?? 0) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="material-icons">cancel</i>
                    </div>
                    <div class="stat-label"><?= te('Promo Tidak Aktif') ?></div>
                    <div class="stat-value"><?= number_format($stats['inactive_promo'] ?? 0) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="material-icons">shopping_cart</i>
                    </div>
                    <div class="stat-label"><?= te('Total Digunakan') ?></div>
                    <div class="stat-value"><?= number_format($stats['total_used'] ?? 0) ?></div>
                </div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <div class="search-container">
                <input type="text" id="searchPromo" class="search-input" placeholder="<?= te('Cari promo berdasarkan nama atau kode...') ?>"
                    onkeyup="searchPromos()">
            </div>
            <button class="btn btn-primary" onclick="openAddPromoModal()" id="addNewPromoBtn">
                <i class="material-icons">add</i>
                <span><?= te('Tambah Promo Baru') ?></span>
            </button>
        </div>

        <!-- Promo List -->
        <div class="promo-section">
            <h2><i class="material-icons">list</i> <?= te('Daftar Promo') ?></h2>

            <div class="promo-table-container">
                <table class="promo-table" id="promoTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th><?= te('Nama Promo') ?></th>
                            <th><?= te('Kode Promo') ?></th>
                            <th><?= te('Tipe Diskon') ?></th>
                            <th><?= te('Nilai') ?></th>
                            <th><?= te('Min. Pembelian') ?></th>
                            <th><?= te('Tanggal Mulai') ?></th>
                            <th><?= te('Tanggal Berakhir') ?></th>
                            <th><?= te('Kuota') ?></th>
                            <th><?= te('Terpakai') ?></th>
                            <th>Status</th>
                            <th><?= te('Aksi') ?></th>
                        </tr>
                    </thead>
                    <tbody id="promoTableBody">
                        <?php if ($promo_result && $promo_result->num_rows > 0): ?>
                            <?php while ($promo = $promo_result->fetch_assoc()):
                                $isExpired = strtotime($promo['tanggal_berakhir']) < time();
                            ?>
                                <tr data-promo-id="<?= $promo['diskon_id'] ?>" data-promo-name="<?= htmlspecialchars($promo['nama_diskon']) ?>">
                                    <td><?= $promo['diskon_id'] ?></td>
                                    <td><?= htmlspecialchars($promo['nama_diskon']) ?></td>
                                    <td><strong><?= $promo['kode_promo'] ?></strong></td>
                                    <td>
                                        <span class="discount-type <?= $promo['tipe_diskon'] == 'percentage' ? 'discount-percentage' : 'discount-fixed' ?>">
                                            <i class="material-icons">
                                                <?= $promo['tipe_diskon'] == 'percentage' ? 'percent' : 'attach_money' ?>
                                            </i>
                                            <?= $promo['tipe_diskon'] == 'percentage' ? te('Persentase') : te('Tetap') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($promo['tipe_diskon'] == 'percentage'): ?>
                                            <?= number_format($promo['nilai_diskon'], 1) ?>%
                                        <?php else: ?>
                                            <?= formatRupiah($promo['nilai_diskon']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatRupiah($promo['minimal_pembelian']) ?></td>
                                    <td><?= formatDate($promo['tanggal_mulai']) ?></td>
                                    <td>
                                        <span class="<?= $isExpired ? 'text-danger' : '' ?>">
                                            <?= formatDate($promo['tanggal_berakhir']) ?>
                                            <?php if ($isExpired): ?>
                                                <br><small class="text-danger">(<?= te('Kedaluwarsa') ?>)</small>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $promo['kuota'] ? number_format($promo['kuota']) : te('Tanpa Batas') ?>
                                    </td>
                                    <td>
                                        <a href="javascript:void(0);" onclick="openUsageModal('<?= $promo['diskon_id'] ?>', '<?= htmlspecialchars($promo['nama_diskon']) ?>')" class="text-primary" title="<?= te('Lihat detail penggunaan') ?>">
                                            <?= number_format($promo['terpakai']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $promo['status'] == 'active' ? 'status-active' : 'status-inactive' ?>">
                                            <i class="material-icons">
                                                <?= $promo['status'] == 'active' ? 'check_circle' : 'cancel' ?>
                                            </i>
                                            <?= $promo['status'] == 'active' ? te('Aktif') : te('Tidak Aktif') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit-btn" onclick="openEditPromoModal('<?= $promo['diskon_id'] ?>')" title="Edit">
                                                <i class="material-icons">edit</i>
                                            </button>
                                            <button class="action-btn usage-btn" onclick="openUsageModal('<?= $promo['diskon_id'] ?>', '<?= htmlspecialchars($promo['nama_diskon']) ?>')" title="<?= te('Lihat Penggunaan') ?>">
                                                <i class="material-icons">people</i>
                                            </button>
                                            <button class="action-btn toggle-btn" onclick="togglePromoStatus('<?= $promo['diskon_id'] ?>', '<?= $promo['status'] ?>')" title="<?= $promo['status'] == 'active' ? te('Nonaktifkan') : te('Aktifkan') ?>">
                                                <i class="material-icons">
                                                    <?= $promo['status'] == 'active' ? 'toggle_off' : 'toggle_on' ?>
                                                </i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12">
                                    <div class="empty-state">
                                        <i class="material-icons">local_offer</i>
                                        <h3>No Promo Found</h3>
                                        <p>Click "Add New Promo" to create your first discount offer.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Add Promo Modal -->
    <div id="addPromoModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="material-icons">add_circle</i> Add New Promo</h2>
                <span class="close-modal">&times;</span>
            </div>
            <form id="addPromoForm">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_diskon_id">Promo ID <span class="required">*</span></label>
                            <input type="text" id="add_diskon_id" name="diskon_id" class="form-control"
                                placeholder="e.g., DISC001" required
                                pattern="[A-Z0-9]{3,10}"
                                title="3-10 characters, uppercase letters and numbers only">
                            <div class="form-helper">Unique ID for the promo (e.g., DISC001)</div>
                        </div>
                        <div class="form-group">
                            <label for="add_nama_diskon">Promo Name <span class="required">*</span></label>
                            <input type="text" id="add_nama_diskon" name="nama_diskon" class="form-control"
                                placeholder="e.g., Summer Sale Discount" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_kode_promo">Promo Code <span class="required">*</span></label>
                            <input type="text" id="add_kode_promo" name="kode_promo" class="form-control"
                                placeholder="e.g., SUMMER2024" required>
                            <div class="form-helper">Code that customers will enter</div>
                        </div>
                        <div class="form-group">
                            <label for="add_tipe_diskon">Discount Type <span class="required">*</span></label>
                            <select id="add_tipe_diskon" name="tipe_diskon" class="form-control" required onchange="handleDiscountTypeChange('add')">
                                <option value="">Select Type</option>
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (Rp)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_nilai_diskon">Discount Value <span class="required">*</span></label>
                            <div class="currency-input-container">
                                <span id="add_diskon_prefix" class="currency-prefix" style="display: none;">Rp</span>
                                <input type="text" id="add_nilai_diskon" name="nilai_diskon" class="form-control currency-input"
                                    placeholder="e.g., 10 or 100.000" required
                                    oninput="formatCurrencyInput('add_nilai_diskon', 'add_diskon_prefix')">
                                <span id="add_diskon_suffix" class="percentage-suffix" style="display: none;">%</span>
                            </div>
                            <div class="form-helper" id="add_discount_helper">Enter discount value</div>
                        </div>
                        <div class="form-group">
                            <label for="add_minimal_pembelian">Minimum Purchase <span class="required">*</span></label>
                            <div class="currency-input-container">
                                <span class="currency-prefix">Rp</span>
                                <input type="text" id="add_minimal_pembelian" name="minimal_pembelian" class="form-control currency-input"
                                    placeholder="e.g., 100.000" required
                                    oninput="formatCurrencyInput('add_minimal_pembelian', null)">
                            </div>
                            <div class="form-helper">Minimum booking amount to use this promo</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_maksimal_diskon">Max Discount (Optional)</label>
                            <div class="currency-input-container">
                                <span class="currency-prefix">Rp</span>
                                <input type="text" id="add_maksimal_diskon" name="maksimal_diskon" class="form-control currency-input"
                                    placeholder="e.g., 50.000"
                                    oninput="formatCurrencyInput('add_maksimal_diskon', null)">
                            </div>
                            <div class="form-helper">Maximum discount amount (leave empty for no limit)</div>
                        </div>
                        <div class="form-group">
                            <label for="add_kuota">Usage Quota (Optional)</label>
                            <input type="number" id="add_kuota" name="kuota" class="form-control"
                                min="1" step="1" placeholder="e.g., 100">
                            <div class="form-helper">Maximum number of times this promo can be used</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="add_tanggal_mulai">Start Date <span class="required">*</span></label>
                            <input type="date" id="add_tanggal_mulai" name="tanggal_mulai" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="add_tanggal_berakhir">End Date <span class="required">*</span></label>
                            <input type="date" id="add_tanggal_berakhir" name="tanggal_berakhir" class="form-control" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="add_status">Status <span class="required">*</span></label>
                        <select id="add_status" name="status" class="form-control" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Promo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Promo Modal -->
    <div id="editPromoModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="material-icons">edit</i> Edit Promo</h2>
                <span class="close-modal">&times;</span>
            </div>
            <form id="editPromoForm">
                <input type="hidden" id="edit_diskon_id" name="diskon_id">
                <div class="modal-body" id="editPromoContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Promo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Usage History Modal -->
    <div id="usageModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h2><i class="material-icons">people</i> <span id="usageModalTitle">Promo Usage Details</span></h2>
                <span class="close-modal">&times;</span>
            </div>
            <div class="modal-body">
                <div class="tabs">
                    <button class="tab active" onclick="switchTab('usageStatsTab')">Usage Statistics</button>
                    <button class="tab" onclick="switchTab('usageDetailsTab')">Usage Details</button>
                </div>

                <div id="usageStatsTab" class="tab-content active">
                    <div class="usage-stats" id="usageStats">
                        <div class="loading">
                            <i class="material-icons">hourglass_empty</i>
                            <p>Loading usage statistics...</p>
                        </div>
                    </div>
                </div>

                <div id="usageDetailsTab" class="tab-content">
                    <div class="usage-table-container">
                        <table class="usage-table">
                            <thead>
                                <tr>
                                    <th>Usage ID</th>
                                    <th>Customer</th>
                                    <th>Hotel</th>
                                    <th>Booking Date</th>
                                    <th>Booking Value</th>
                                    <th>Discount</th>
                                    <th>Used Date</th>
                                </tr>
                            </thead>
                            <tbody id="usageTableBody">
                                <tr>
                                    <td colspan="7">
                                        <div class="loading">
                                            <i class="material-icons">hourglass_empty</i>
                                            <p>Loading usage details...</p>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary close-modal">Close</button>
                <button type="button" class="btn btn-info" onclick="exportUsageData()">
                    <i class="material-icons">download</i>
                    Export Data
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="material-icons">warning</i> Delete Confirmation</h2>
                <span class="close-modal">&times;</span>
            </div>
            <div class="modal-body">
                <p id="deleteConfirmationText">Are you sure you want to delete this promo?</p>
                <input type="hidden" id="delete_diskon_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary close-modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <script>
        // Sidebar functionality - SEDERHANA dan KONSISTEN dengan dashboard.php
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar collapse/expand
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('toggleSidebar');
            const mainContent = document.getElementById('main-content');

            // Restore sidebar state
            const sidebarState = localStorage.getItem('sidebarState');
            if (sidebarState === 'collapsed') {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            }

            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                localStorage.setItem('sidebarState', sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded');
            });

            // Dropdown menus untuk Analytics & Insights dan Decision Support
            document.querySelectorAll('.booking-toggle').forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    const target = this.getAttribute('data-target');
                    const dropdown = document.getElementById(target);

                    if (!dropdown) return;

                    const isHidden = dropdown.classList.contains('hidden');

                    // Close all other dropdowns
                    document.querySelectorAll('.booking-submenu').forEach(sub => {
                        if (sub !== dropdown) {
                            sub.classList.remove('show');
                            sub.classList.add('hidden');
                        }
                    });

                    // Update toggle icons
                    document.querySelectorAll('.toggle-icon').forEach(icon => {
                        icon.textContent = 'expand_more';
                    });

                    // Toggle current dropdown
                    if (isHidden) {
                        dropdown.classList.remove('hidden');
                        dropdown.classList.add('show');
                        this.querySelector('.toggle-icon').textContent = 'expand_less';
                    } else {
                        dropdown.classList.remove('show');
                        dropdown.classList.add('hidden');
                        this.querySelector('.toggle-icon').textContent = 'expand_more';
                    }
                });
            });

            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.user-menu')) {
                    document.querySelectorAll('.booking-submenu').forEach(sub => {
                        sub.classList.remove('show');
                        sub.classList.add('hidden');
                    });
                    document.querySelectorAll('.toggle-icon').forEach(icon => {
                        icon.textContent = 'expand_more';
                    });
                }
            });

            // User dropdown in sidebar (Manage Account)
            function toggleDropdown(button) {
                const dropdown = button.nextElementSibling;
                const isExpanded = button.getAttribute('aria-expanded') === 'true';

                // Close all other dropdowns
                document.querySelectorAll('.dropdown-content').forEach(d => {
                    if (d !== dropdown) {
                        d.classList.remove('show');
                        d.setAttribute('aria-hidden', 'true');
                        d.previousElementSibling.setAttribute('aria-expanded', 'false');
                    }
                });

                // Toggle current dropdown
                if (!isExpanded) {
                    dropdown.classList.add('show');
                    button.setAttribute('aria-expanded', 'true');
                    dropdown.setAttribute('aria-hidden', 'false');
                } else {
                    dropdown.classList.remove('show');
                    button.setAttribute('aria-expanded', 'false');
                    dropdown.setAttribute('aria-hidden', 'true');
                }
            }

            // Assign dropdown click events
            document.querySelectorAll('.user-info').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleDropdown(this);
                });
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.user-dropdown')) {
                    document.querySelectorAll('.dropdown-content').forEach(d => {
                        d.classList.remove('show');
                        d.setAttribute('aria-hidden', 'true');
                        d.previousElementSibling.setAttribute('aria-expanded', 'false');
                    });
                }
            });

            // Set minimum dates to today
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('add_tanggal_mulai').min = today;
            document.getElementById('add_tanggal_berakhir').min = today;

            document.getElementById('add_tanggal_mulai').addEventListener('change', function() {
                document.getElementById('add_tanggal_berakhir').min = this.value;
            });

            // Responsive behavior
            function handleResponsive() {
                if (window.innerWidth <= 768) {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('expanded');
                }
            }

            handleResponsive();
            window.addEventListener('resize', handleResponsive);
            
            // Fix: Make sure Add New Promo button works
            document.getElementById('addNewPromoBtn').addEventListener('click', openAddPromoModal);
        });

        // Modal functionality
        const modals = document.querySelectorAll('.modal');
        const closeButtons = document.querySelectorAll('.close-modal');

        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModals() {
            modals.forEach(modal => {
                modal.style.display = 'none';
            });
            document.body.style.overflow = 'auto';
        }

        closeButtons.forEach(btn => {
            btn.addEventListener('click', closeModals);
        });

        window.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                closeModals();
            }
        });

        // Tab functionality
        function switchTab(tabId) {
            // Hide all tab content
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById(tabId).classList.add('active');

            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Format currency input
        function formatCurrencyInput(inputId, prefixId = null) {
            const input = document.getElementById(inputId);
            let value = input.value.replace(/[^\d,]/g, '');
            
            // If value is empty, don't format
            if (value === '') {
                input.value = '';
                return;
            }

            // Convert to number for formatting
            const numValue = parseInt(value, 10);
            
            // Format with thousand separators
            const formattedValue = numValue.toLocaleString('id-ID');
            input.value = formattedValue;

            // Update helper text if it's a discount value
            if (inputId === 'add_nilai_diskon' || inputId === 'edit_nilai_diskon') {
                const discountType = inputId.includes('add') ? 
                    document.getElementById('add_tipe_diskon').value : 
                    document.getElementById('edit_tipe_diskon').value;
                
                const helper = document.getElementById(inputId.includes('add') ? 'add_discount_helper' : 'edit_discount_helper');
                if (discountType === 'percentage') {
                    helper.textContent = `Diskon: ${numValue}%`;
                } else {
                    helper.textContent = `Diskon: Rp ${formattedValue}`;
                }
            }
        }

        // Handle discount type change
        function handleDiscountTypeChange(type) {
            const prefixId = type === 'add' ? 'add_diskon_prefix' : 'edit_diskon_prefix';
            const suffixId = type === 'add' ? 'add_diskon_suffix' : 'edit_diskon_suffix';
            const inputId = type === 'add' ? 'add_nilai_diskon' : 'edit_nilai_diskon';
            const helperId = type === 'add' ? 'add_discount_helper' : 'edit_discount_helper';
            
            const discountType = document.getElementById(type === 'add' ? 'add_tipe_diskon' : 'edit_tipe_diskon').value;
            const prefix = document.getElementById(prefixId);
            const suffix = document.getElementById(suffixId);
            const input = document.getElementById(inputId);
            const helper = document.getElementById(helperId);

            // Clear current value
            input.value = '';
            input.placeholder = '';

            if (discountType === 'percentage') {
                prefix.style.display = 'none';
                suffix.style.display = 'block';
                input.classList.remove('currency-input');
                input.classList.add('percentage-input');
                input.placeholder = 'e.g., 10';
                helper.textContent = 'Enter percentage (1-100%)';
            } else {
                prefix.style.display = 'block';
                suffix.style.display = 'none';
                input.classList.remove('percentage-input');
                input.classList.add('currency-input');
                input.placeholder = 'e.g., 100.000';
                helper.textContent = 'Enter fixed amount in Rupiah';
            }
        }

        // Parse formatted currency to number (FIXED: properly handle 00 at the end)
        function parseFormattedCurrency(formattedValue) {
            if (!formattedValue || formattedValue.trim() === '') return 0;
            
            // Remove all non-digit characters except commas for decimals
            let cleanValue = formattedValue.toString();
            
            // Remove thousand separators (dots)
            cleanValue = cleanValue.replace(/\./g, '');
            
            // Handle decimal comma
            if (cleanValue.includes(',')) {
                // Replace comma with dot for decimal point
                cleanValue = cleanValue.replace(',', '.');
            }
            
            // Parse as float
            const numValue = parseFloat(cleanValue);
            
            // Return 0 if parsing fails
            return isNaN(numValue) ? 0 : numValue;
        }

        // Search functionality
        function searchPromos() {
            const searchTerm = document.getElementById('searchPromo').value.toLowerCase();
            const rows = document.querySelectorAll('#promoTableBody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                const promoName = row.getAttribute('data-promo-name').toLowerCase();
                const promoId = row.querySelector('td:nth-child(1)').textContent.toLowerCase();
                const promoCode = row.querySelector('td:nth-child(3)').textContent.toLowerCase();

                if (promoName.includes(searchTerm) ||
                    promoId.includes(searchTerm) ||
                    promoCode.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            // Show empty state if no results
            const emptyState = document.querySelector('.empty-state');
            if (emptyState && emptyState.closest('tr')) {
                const emptyRow = emptyState.closest('tr');
                if (visibleCount === 0 && searchTerm === '') {
                    emptyRow.style.display = '';
                } else {
                    emptyRow.style.display = 'none';
                }
            }
        }

        // Open Add Promo Modal
        function openAddPromoModal() {
            // Reset form
            document.getElementById('addPromoForm').reset();
            
            // Reset currency displays
            document.getElementById('add_diskon_prefix').style.display = 'none';
            document.getElementById('add_diskon_suffix').style.display = 'none';
            document.getElementById('add_nilai_diskon').classList.remove('currency-input', 'percentage-input');

            // Set default values
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('add_tanggal_mulai').value = today;
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 30);
            document.getElementById('add_tanggal_berakhir').value = tomorrow.toISOString().split('T')[0];
            document.getElementById('add_status').value = 'active';
            document.getElementById('add_discount_helper').textContent = 'Enter discount value';

            openModal('addPromoModal');
        }

        // Add Promo Form Submission
        document.getElementById('addPromoForm').addEventListener('submit', function(e) {
            e.preventDefault();

            // Parse currency values before submission
            const nilaiDiskonInput = document.getElementById('add_nilai_diskon');
            const minimalPembelianInput = document.getElementById('add_minimal_pembelian');
            const maksimalDiskonInput = document.getElementById('add_maksimal_diskon');
            
            const tipeDiskon = document.getElementById('add_tipe_diskon').value;
            
            if (tipeDiskon === 'percentage') {
                // For percentage, just get the number (no formatting)
                nilaiDiskonInput.value = nilaiDiskonInput.value.replace(/[^\d,.]/g, '').replace(',', '.');
            } else {
                // For fixed amount, parse formatted currency
                nilaiDiskonInput.value = parseFormattedCurrency(nilaiDiskonInput.value);
            }
            
            minimalPembelianInput.value = parseFormattedCurrency(minimalPembelianInput.value);
            if (maksimalDiskonInput.value) {
                maksimalDiskonInput.value = parseFormattedCurrency(maksimalDiskonInput.value);
            }

            const formData = new FormData(this);
            formData.append('action', 'add_promo');

            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="material-icons">hourglass_empty</i> Saving...';
            submitBtn.disabled = true;

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        closeModals();
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification(data.message, 'error');
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Failed to save promo. Please try again.', 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        });

        // Format number to Indonesian format
        function formatToIndonesianNumber(number) {
            if (!number && number !== 0) return '';
            return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        }

        // Open Edit Promo Modal
        function openEditPromoModal(diskonId) {
            const modalContent = document.getElementById('editPromoContent');
            modalContent.innerHTML = `
                <div class="loading">
                    <i class="material-icons">hourglass_empty</i>
                    <p>Loading promo details...</p>
                </div>
            `;

            openModal('editPromoModal');

            fetch(`?action=get_promo_details&diskon_id=${encodeURIComponent(diskonId)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const promo = data.data;
                        
                        // Format values for display
                        const minimalPembelianFormatted = formatToIndonesianNumber(promo.minimal_pembelian);
                        const nilaiDiskonFormatted = promo.tipe_diskon === 'fixed' ? 
                            formatToIndonesianNumber(promo.nilai_diskon) : promo.nilai_diskon;
                        const maksimalDiskonFormatted = promo.maksimal_diskon ? 
                            formatToIndonesianNumber(promo.maksimal_diskon) : '';

                        modalContent.innerHTML = `
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit_nama_diskon">Promo Name <span class="required">*</span></label>
                                    <input type="text" id="edit_nama_diskon" name="nama_diskon" class="form-control" 
                                           value="${escapeHtml(promo.nama_diskon)}" required>
                                </div>
                                <div class="form-group">
                                    <label for="edit_kode_promo">Promo Code <span class="required">*</span></label>
                                    <input type="text" id="edit_kode_promo" name="kode_promo" class="form-control" 
                                           value="${escapeHtml(promo.kode_promo)}" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit_tipe_diskon">Discount Type <span class="required">*</span></label>
                                    <select id="edit_tipe_diskon" name="tipe_diskon" class="form-control" required onchange="handleDiscountTypeChange('edit')">
                                        <option value="percentage" ${promo.tipe_diskon == 'percentage' ? 'selected' : ''}>Percentage (%)</option>
                                        <option value="fixed" ${promo.tipe_diskon == 'fixed' ? 'selected' : ''}>Fixed Amount (Rp)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit_nilai_diskon">Discount Value <span class="required">*</span></label>
                                    <div class="currency-input-container">
                                        <span id="edit_diskon_prefix" class="currency-prefix" style="${promo.tipe_diskon == 'fixed' ? '' : 'display: none;'}">Rp</span>
                                        <input type="text" id="edit_nilai_diskon" name="nilai_diskon" class="form-control ${promo.tipe_diskon == 'percentage' ? 'percentage-input' : 'currency-input'}" 
                                               value="${nilaiDiskonFormatted}" required
                                               oninput="formatCurrencyInput('edit_nilai_diskon', 'edit_diskon_prefix')">
                                        <span id="edit_diskon_suffix" class="percentage-suffix" style="${promo.tipe_diskon == 'percentage' ? '' : 'display: none;'}">%</span>
                                    </div>
                                    <div class="form-helper" id="edit_discount_helper">
                                        ${promo.tipe_diskon == 'percentage' ? 'Enter percentage (1-100%)' : 'Enter fixed amount in Rupiah'}
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit_minimal_pembelian">Minimum Purchase <span class="required">*</span></label>
                                    <div class="currency-input-container">
                                        <span class="currency-prefix">Rp</span>
                                        <input type="text" id="edit_minimal_pembelian" name="minimal_pembelian" class="form-control currency-input" 
                                               value="${minimalPembelianFormatted}" required
                                               oninput="formatCurrencyInput('edit_minimal_pembelian', null)">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="edit_maksimal_diskon">Max Discount (Optional)</label>
                                    <div class="currency-input-container">
                                        <span class="currency-prefix">Rp</span>
                                        <input type="text" id="edit_maksimal_diskon" name="maksimal_diskon" class="form-control currency-input" 
                                               value="${maksimalDiskonFormatted}"
                                               oninput="formatCurrencyInput('edit_maksimal_diskon', null)">
                                    </div>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit_tanggal_mulai">Start Date <span class="required">*</span></label>
                                    <input type="date" id="edit_tanggal_mulai" name="tanggal_mulai" class="form-control" 
                                           value="${promo.tanggal_mulai}" required>
                                </div>
                                <div class="form-group">
                                    <label for="edit_tanggal_berakhir">End Date <span class="required">*</span></label>
                                    <input type="date" id="edit_tanggal_berakhir" name="tanggal_berakhir" class="form-control" 
                                           value="${promo.tanggal_berakhir}" required>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="edit_kuota">Usage Quota (Optional)</label>
                                    <input type="number" id="edit_kuota" name="kuota" class="form-control" 
                                           value="${promo.kuota || ''}" min="1" step="1">
                                </div>
                                <div class="form-group">
                                    <label for="edit_status">Status <span class="required">*</span></label>
                                    <select id="edit_status" name="status" class="form-control" required>
                                        <option value="active" ${promo.status == 'active' ? 'selected' : ''}>Active</option>
                                        <option value="inactive" ${promo.status == 'inactive' ? 'selected' : ''}>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        `;

                        document.getElementById('edit_diskon_id').value = diskonId;

                        // Set min date for end date based on start date
                        document.getElementById('edit_tanggal_mulai').addEventListener('change', function() {
                            document.getElementById('edit_tanggal_berakhir').min = this.value;
                        });

                    } else {
                        modalContent.innerHTML = `
                            <div class="error-message">
                                <i class="material-icons">error</i>
                                <p>${data.message || 'Failed to load promo details'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalContent.innerHTML = `
                        <div class="error-message">
                            <i class="material-icons">error</i>
                            <p>Failed to load promo details. Please try again.</p>
                        </div>
                    `;
                });
        }

        // Edit Promo Form Submission
        document.getElementById('editPromoForm').addEventListener('submit', function(e) {
            e.preventDefault();

            // Parse currency values before submission
            const nilaiDiskonInput = document.getElementById('edit_nilai_diskon');
            const minimalPembelianInput = document.getElementById('edit_minimal_pembelian');
            const maksimalDiskonInput = document.getElementById('edit_maksimal_diskon');
            
            const tipeDiskon = document.getElementById('edit_tipe_diskon').value;
            
            if (tipeDiskon === 'percentage') {
                // For percentage, just get the number (no formatting)
                nilaiDiskonInput.value = nilaiDiskonInput.value.replace(/[^\d,.]/g, '').replace(',', '.');
            } else {
                // For fixed amount, parse formatted currency
                nilaiDiskonInput.value = parseFormattedCurrency(nilaiDiskonInput.value);
            }
            
            minimalPembelianInput.value = parseFormattedCurrency(minimalPembelianInput.value);
            if (maksimalDiskonInput.value) {
                maksimalDiskonInput.value = parseFormattedCurrency(maksimalDiskonInput.value);
            }

            const formData = new FormData(this);
            formData.append('action', 'edit_promo');

            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="material-icons">hourglass_empty</i> Updating...';
            submitBtn.disabled = true;

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        closeModals();
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification(data.message, 'error');
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Failed to update promo. Please try again.', 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        });

        // Open Usage Modal
        function openUsageModal(diskonId, promoName) {
            document.getElementById('usageModalTitle').textContent = `Usage Details: ${promoName}`;

            // Load usage data
            loadUsageData(diskonId);

            openModal('usageModal');
        }

        // Load usage data
        function loadUsageData(diskonId) {
            const usageStats = document.getElementById('usageStats');
            const usageTableBody = document.getElementById('usageTableBody');

            // Show loading state
            usageStats.innerHTML = `
                <div class="loading">
                    <i class="material-icons">hourglass_empty</i>
                    <p>Loading usage statistics...</p>
                </div>
            `;

            usageTableBody.innerHTML = `
                <tr>
                    <td colspan="7">
                        <div class="loading">
                            <i class="material-icons">hourglass_empty</i>
                            <p>Loading usage details...</p>
                        </div>
                    </td>
                </tr>
            `;

            fetch(`?action=get_promo_usage&diskon_id=${encodeURIComponent(diskonId)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const stats = data.stats;
                        const usageData = data.data;

                        // Update statistics
                        usageStats.innerHTML = `
                            <div class="usage-stat">
                                <div class="usage-stat-value">${stats.total_usage}</div>
                                <div class="usage-stat-label">Total Usage</div>
                            </div>
                            <div class="usage-stat">
                                <div class="usage-stat-value">${formatRupiah(stats.total_discount)}</div>
                                <div class="usage-stat-label">Total Discount Given</div>
                            </div>
                            <div class="usage-stat">
                                <div class="usage-stat-value">${formatRupiah(stats.avg_discount)}</div>
                                <div class="usage-stat-label">Average Discount</div>
                            </div>
                            <div class="usage-stat">
                                <div class="usage-stat-value">${usageData.length > 0 ? formatDateTime(usageData[0].tanggal_digunakan) : '-'}</div>
                                <div class="usage-stat-label">Last Used</div>
                            </div>
                        `;

                        // Update usage table
                        if (usageData.length > 0) {
                            let tableHTML = '';
                            usageData.forEach(usage => {
                                tableHTML += `
                                    <tr>
                                        <td>${usage.id_penggunaan || '-'}</td>
                                        <td>
                                            <div class="customer-info">
                                                <span class="customer-name">${escapeHtml(usage.customer_name || 'Unknown')}</span>
                                                <span class="customer-email">${escapeHtml(usage.customer_email || '-')}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="hotel-info">
                                                <span class="hotel-name">${escapeHtml(usage.nama_hotel || '-')}</span>
                                                <span class="booking-date">${formatDate(usage.check_in)} - ${formatDate(usage.check_out)}</span>
                                            </div>
                                        </td>
                                        <td>${formatDateTime(usage.tanggal_digunakan)}</td>
                                        <td>${formatRupiah(usage.total_harga || 0)}</td>
                                        <td><span class="text-danger">-${formatRupiah(usage.nilai_diskon || 0)}</span></td>
                                        <td>${formatDateTime(usage.tanggal_digunakan)}</td>
                                    </tr>
                                `;
                            });
                            usageTableBody.innerHTML = tableHTML;
                        } else {
                            usageTableBody.innerHTML = `
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <i class="material-icons">people</i>
                                            <h3>No Usage Data</h3>
                                            <p>This promo has not been used yet.</p>
                                        </div>
                                    </td>
                                </tr>
                            `;
                        }
                    } else {
                        usageStats.innerHTML = `
                            <div class="error-message">
                                <i class="material-icons">error</i>
                                <p>Failed to load usage statistics</p>
                            </div>
                        `;
                        usageTableBody.innerHTML = `
                            <tr>
                                <td colspan="7">
                                    <div class="error-message">
                                        <i class="material-icons">error</i>
                                        <p>Failed to load usage details</p>
                                    </div>
                                </td>
                            </tr>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    usageStats.innerHTML = `
                        <div class="error-message">
                            <i class="material-icons">error</i>
                            <p>Error loading usage data</p>
                        </div>
                    `;
                    usageTableBody.innerHTML = `
                        <tr>
                            <td colspan="7">
                                <div class="error-message">
                                    <i class="material-icons">error</i>
                                    <p>Error loading usage details</p>
                                </div>
                            </td>
                        </tr>
                    `;
                });
        }

        // Export usage data
        function exportUsageData() {
            const diskonId = document.getElementById('usageModalTitle').textContent.split(': ')[1];
            const table = document.querySelector('.usage-table');

            // Create CSV content
            let csv = [];
            const rows = table.querySelectorAll('tr');

            for (let i = 0; i < rows.length; i++) {
                const row = [];
                const cols = rows[i].querySelectorAll('td, th');

                for (let j = 0; j < cols.length; j++) {
                    let text = cols[j].innerText;
                    // Remove extra whitespace and line breaks
                    text = text.replace(/\n/g, ' ').replace(/\s+/g, ' ').trim();
                    // Wrap in quotes if contains comma
                    if (text.includes(',')) {
                        text = `"${text}"`;
                    }
                    row.push(text);
                }
                csv.push(row.join(','));
            }

            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], {
                type: 'text/csv'
            });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.setAttribute('hidden', '');
            a.setAttribute('href', url);
            a.setAttribute('download', `promo_usage_${diskonId}_${new Date().toISOString().split('T')[0]}.csv`);
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);

            showNotification('Usage data exported successfully', 'success');
        }

        // Toggle Promo Status
        function togglePromoStatus(diskonId, currentStatus) {
            if (!confirm(`Are you sure you want to ${currentStatus === 'active' ? 'deactivate' : 'activate'} this promo?`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('diskon_id', diskonId);
            formData.append('current_status', currentStatus);

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Failed to update promo status. Please try again.', 'error');
                });
        }

        // Delete Promo
        function deletePromo(diskonId, promoName) {
            document.getElementById('delete_diskon_id').value = diskonId;
            document.getElementById('deleteConfirmationText').textContent =
                `Are you sure you want to delete promo "${promoName}"? This action cannot be undone.`;
            openModal('deleteConfirmationModal');
        }

        function confirmDelete() {
            const diskonId = document.getElementById('delete_diskon_id').value;

            const formData = new FormData();
            formData.append('action', 'delete_promo');
            formData.append('diskon_id', diskonId);

            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        closeModals();
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showNotification(data.message, 'error');
                        closeModals();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Failed to delete promo. Please try again.', 'error');
                    closeModals();
                });
        }

        // Notification System
        function showNotification(message, type = 'info') {
            // Remove existing notifications
            document.querySelectorAll('.notification').forEach(n => n.remove());

            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <i class="material-icons">${type === 'success' ? 'check_circle' : type === 'error' ? 'error' : 'info'}</i>
                <span>${message}</span>
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.3s ease-in';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Utility functions
        function escapeHtml(unsafe) {
            if (!unsafe) return '';
            return unsafe
                .toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function formatRupiah(angka) {
            if (!angka && angka !== 0) return 'Rp 0';
            return 'Rp ' + Math.round(angka).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        }

        function formatDate(dateString) {
            if (!dateString || dateString === '0000-00-00') return '-';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        }

        function formatDateTime(dateTimeString) {
            if (!dateTimeString || dateTimeString === '0000-00-00 00:00:00') return '-';
            const date = new Date(dateTimeString);
            return date.toLocaleDateString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    </script>
</body>

</html>