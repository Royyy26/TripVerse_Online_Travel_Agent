<?php
// === FILE: process_payment.php ===
session_start();

// Aktifkan error reporting & mysqli report
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Koneksi
require_once __DIR__ . '/db_config.php';

// Validasi login
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

// Validasi request method dan konfirmasi pembayaran
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['confirm_payment'])) {
    $_SESSION['error'] = "Konfirmasi pembayaran diperlukan";
    header("Location: payment.php?booking_id=" . urlencode($_SESSION['payment_data']['booking_id']));
    exit;
}

// Ambil data dari session
$payment_method = isset($_POST['payment_method']) ? $conn->real_escape_string($_POST['payment_method']) : 'QRIS';

$booking_id     = $_SESSION['payment_data']['booking_id'];
$customer_id    = $_SESSION['payment_data']['customer_id'];
$total_harga    = (float) $_SESSION['payment_data']['total_harga'];
$transaksi_id   = $_SESSION['payment_data']['transaksi_id'] ?? null;
$hotel_data     = $_SESSION['payment_data']['hotel_data'];
$room_data      = $_SESSION['payment_data']['room_data'];
$check_in       = $_SESSION['payment_data']['check_in'];
$check_out      = $_SESSION['payment_data']['check_out'];
$durasi         = $_SESSION['payment_data']['durasi'];
$jumlah_kamar   = $_SESSION['payment_data']['jumlah_kamar'];

// Validasi data
if (empty($booking_id) || empty($customer_id) || empty($transaksi_id)) {
    $_SESSION['error'] = "Data pembayaran tidak lengkap";
    header("Location: payment.php?booking_id=" . urlencode($booking_id));
    exit;
}

$conn->begin_transaction();

try {
    // Pastikan booking valid
    $sql_check_booking = "SELECT booking_id FROM booking_hotel WHERE booking_id = ? AND customer_id = ?";
    $stmt = $conn->prepare($sql_check_booking);
    $stmt->bind_param("ss", $booking_id, $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("Data booking tidak ditemukan");
    }
    $stmt->close();

    // Update status booking
    $sql_update_booking = "UPDATE booking_hotel SET status = 'Completed', metode_pembayaran = ?, tanggal_pembayaran = NOW() WHERE booking_id = ? AND customer_id = ?";
    $stmt = $conn->prepare($sql_update_booking);
    $stmt->bind_param("sss", $payment_method, $booking_id, $customer_id);
    $stmt->execute();
    $stmt->close();

    // Periksa transaksi utama
    $sql_check_transaksi = "SELECT id_transaksi FROM transaksi WHERE id_transaksi = ?";
    $stmt = $conn->prepare($sql_check_transaksi);
    $stmt->bind_param("s", $transaksi_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $sql_transaksi = "INSERT INTO transaksi (id_transaksi, jenis_transaksi, tanggal_transaksi, total_harga, status_transaksi) VALUES (?, 'Hotel', NOW(), ?, 'Completed')";
        $stmt = $conn->prepare($sql_transaksi);
        $stmt->bind_param("sd", $transaksi_id, $total_harga);
        $stmt->execute();
        $stmt->close();
    }

    // Periksa transaksi hotel
    $sql_check_transaksi_hotel = "SELECT transaksi_id_hotel FROM transaksi_hotel WHERE id_transaksi = ? AND booking_id = ?";
    $stmt = $conn->prepare($sql_check_transaksi_hotel);
    $stmt->bind_param("ss", $transaksi_id, $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $transaksi_hotel_id = 'HTRX' . date('YmdHis') . strtoupper(substr(uniqid(), -6));
        $sql_transaksi_hotel = "INSERT INTO transaksi_hotel (transaksi_id_hotel, id_transaksi, booking_id, status, tanggal_update) VALUES (?, ?, ?, 'Completed', NOW())";
        $stmt = $conn->prepare($sql_transaksi_hotel);
        $stmt->bind_param("sss", $transaksi_hotel_id, $transaksi_id, $booking_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $row = $result->fetch_assoc();
        $transaksi_hotel_id = $row['transaksi_id_hotel'];
    }

    // Update jumlah kamar terbooking
    $sql_update_kamar = "
        UPDATE jadwal_hotel jh
        JOIN booking_hotel bh ON jh.hotel_id = bh.hotel_id AND jh.tipe_id = bh.tipe_id
        SET jh.terbooking = jh.terbooking + bh.jumlah_kamar
        WHERE bh.booking_id = ?";
    $stmt = $conn->prepare($sql_update_kamar);
    $stmt->bind_param("s", $booking_id);
    $stmt->execute();
    $stmt->close();

    // Commit transaksi
    $conn->commit();

    // Simpan ke session untuk halaman konfirmasi
    $_SESSION['payment_confirmation'] = [
        'booking_id' => $booking_id,
        'transaksi_id' => $transaksi_id,
        'transaksi_hotel_id' => $transaksi_hotel_id,
        'total_harga' => $total_harga,
        'payment_method' => $payment_method,
        'hotel_data' => $hotel_data,
        'room_data' => $room_data,
        'check_in' => $check_in,
        'check_out' => $check_out,
        'durasi' => $durasi,
        'jumlah_kamar' => $jumlah_kamar
    ];

    // Hapus data pembayaran
    unset($_SESSION['payment_data']);

    // Redirect ke konfirmasi
    header("Location: booking_confirmation.php?booking_id=" . urlencode($booking_id));
    exit();

} catch (Exception $e) {
    $conn->rollback();
    error_log("Gagal proses pembayaran: " . $e->getMessage());
    $_SESSION['error'] = "Gagal memproses pembayaran: " . $e->getMessage();
    header("Location: payment.php?booking_id=" . urlencode($booking_id));
    exit;
}

$conn->close();
