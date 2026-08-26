<?php
session_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create connection
require_once __DIR__ . '/db_config.php';

// Verify user is logged in
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

// Validate POST data
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_booking'])) {
    $_SESSION['error'] = "Invalid request method";
    header("Location: hotel.php");
    exit;
}

// Validasi parameter penting
$required_params = ['hotel_id', 'tipe_id', 'checkin', 'checkout', 'kamar', 'customer_name', 'no_hp', 'email'];
foreach ($required_params as $param) {
    if (empty($_POST[$param])) {
        $_SESSION['error'] = "Parameter $param tidak boleh kosong";
        header("Location: " . (!empty($_POST['hotel_id']) ? "hotel_detail.php?id=" . $_POST['hotel_id'] : "hotel.php"));
        exit;
    }
}

// Sanitize inputs
$hotel_id = $conn->real_escape_string($_POST['hotel_id']);
$tipe_id = $conn->real_escape_string($_POST['tipe_id']);
$checkin = $conn->real_escape_string($_POST['checkin']);
$checkout = $conn->real_escape_string($_POST['checkout']);
$kamar = (int)$_POST['kamar'];
$customer_name = $conn->real_escape_string($_POST['customer_name']);
$no_hp = $conn->real_escape_string($_POST['no_hp']);
$email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
$catatan = isset($_POST['catatan']) ? $conn->real_escape_string($_POST['catatan']) : '';
$total_harga = (float)$_POST['total_harga'];
$user_id = $_SESSION['id_user'];

// Validasi tanggal
try {
    $date1 = new DateTime($checkin);
    $date2 = new DateTime($checkout);
    if ($date2 <= $date1) {
        throw new Exception("Tanggal check-out harus setelah check-in");
    }
    $durasi = $date2->diff($date1)->days;
} catch (Exception $e) {
    $_SESSION['error'] = "Format tanggal tidak valid: " . $e->getMessage();
    header("Location: hotel_detail.php?id=" . $hotel_id);
    exit;
}

// Dapatkan customer_id
$customer_id = null;
$sql = "SELECT customer_id FROM customer WHERE id_user = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $customer_id = $result->num_rows > 0 ? $result->fetch_assoc()['customer_id'] : null;
    $stmt->close();
}

// Jika customer tidak ada, buat baru
if (!$customer_id) {
    $customer_id = 'CUST' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    $sql = "INSERT INTO customer (customer_id, id_user, email, nama, no_hp, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sssss", $customer_id, $user_id, $email, $customer_name, $no_hp);
        if (!$stmt->execute()) {
            $_SESSION['error'] = "Gagal membuat record customer";
            header("Location: hotel_detail.php?id=" . $hotel_id);
            exit;
        }
        $stmt->close();
    }
}

// Verifikasi ketersediaan kamar
$sql_room = "SELECT jh.harga, (jh.stok_total - jh.terbooking) as available
             FROM jadwal_hotel jh
             WHERE jh.hotel_id = ? AND jh.tipe_id = ?";
$stmt_room = $conn->prepare($sql_room);
if ($stmt_room) {
    $stmt_room->bind_param("ss", $hotel_id, $tipe_id);
    $stmt_room->execute();
    $room = $stmt_room->get_result()->fetch_assoc();
    $stmt_room->close();
}

if (!$room || $room['available'] < $kamar) {
    $_SESSION['error'] = "Kamar tidak tersedia atau stok habis";
    header("Location: hotel_detail.php?id=" . $hotel_id);
    exit;
}

// Generate IDs
$booking_id = 'BOOK' . date('YmdHis') . strtoupper(substr(uniqid(), -5));
$transaksi_id = 'TRX' . date('YmdHis') . strtoupper(substr(uniqid(), -5));
$transaksi_hotel_id = 'HTRX' . date('YmdHis') . strtoupper(substr(uniqid(), -5));
$jadwal_id = $hotel_id . '-' . $tipe_id;

// Mulai transaksi
$conn->begin_transaction();

try {
    // 1. Insert booking
    $sql_booking = "INSERT INTO booking_hotel (
        booking_id, customer_id, customer_name, 
        jadwal_id, hotel_id, tipe_id,
        check_in, check_out, jumlah_kamar, 
        total_harga, status, tanggal_booking, 
        catatan, metode_pembayaran
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?, 'QRIS')";

    $stmt_booking = $conn->prepare($sql_booking);
    if (!$stmt_booking) throw new Exception("Gagal prepare booking: " . $conn->error);

    $stmt_booking->bind_param(
        "ssssssssids",
        $booking_id,
        $customer_id,
        $customer_name,
        $jadwal_id,
        $hotel_id,
        $tipe_id,
        $checkin,
        $checkout,
        $kamar,
        $total_harga,
        $catatan
    );

    if (!$stmt_booking->execute()) throw new Exception("Gagal execute booking: " . $stmt_booking->error);
    $stmt_booking->close();

    // 2. Insert transaksi (status Pending)
    $sql_transaksi = "INSERT INTO transaksi (
        id_transaksi, jenis_transaksi, tanggal_transaksi, total_harga, status_transaksi
    ) VALUES (?, 'Hotel', NOW(), ?, 'Pending')";

    $stmt_transaksi = $conn->prepare($sql_transaksi);
    if (!$stmt_transaksi) throw new Exception("Gagal prepare transaksi");
    $stmt_transaksi->bind_param("sd", $transaksi_id, $total_harga);
    if (!$stmt_transaksi->execute()) throw new Exception("Gagal execute transaksi");
    $stmt_transaksi->close();

    // 3. Insert transaksi_hotel (status Pending)
    $sql_transaksi_hotel = "INSERT INTO transaksi_hotel (
        transaksi_id_hotel, id_transaksi, booking_id, status
    ) VALUES (?, ?, ?, 'Pending')";

    $stmt_transaksi_hotel = $conn->prepare($sql_transaksi_hotel);
    if (!$stmt_transaksi_hotel) throw new Exception("Gagal prepare transaksi hotel");
    $stmt_transaksi_hotel->bind_param("sss", $transaksi_hotel_id, $transaksi_id, $booking_id);
    if (!$stmt_transaksi_hotel->execute()) throw new Exception("Gagal execute transaksi hotel");
    $stmt_transaksi_hotel->close();

    // 4. Update ketersediaan kamar (HANYA DI SINI)
    $sql_update = "UPDATE jadwal_hotel 
                  SET terbooking = terbooking + ? 
                  WHERE hotel_id = ? AND tipe_id = ?";

    $stmt_update = $conn->prepare($sql_update);
    if (!$stmt_update) throw new Exception("Gagal prepare update");
    $stmt_update->bind_param("iss", $kamar, $hotel_id, $tipe_id);
    if (!$stmt_update->execute()) throw new Exception("Gagal execute update");
    $stmt_update->close();

    // Commit transaksi
    $conn->commit();

    // Set session untuk payment
    $_SESSION['current_booking'] = [
        'id' => $booking_id,
        'amount' => $total_harga,
        'type' => 'hotel',
        'customer_id' => $customer_id,
        'transaksi_id' => $transaksi_id
    ];

    $_SESSION['booking_start_time'] = time(); // Catat waktu booking dibuat
    $_SESSION['booking_id'] = $booking_id; // Simpan booking ID di session
    header("Location: payment.php?booking_id=" . urlencode($booking_id));
    exit;
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = "Gagal melakukan pemesanan: " . $e->getMessage();
    error_log("Booking Error: " . $e->getMessage());
    header("Location: hotel_detail.php?id=" . $hotel_id);
    exit;
}

$conn->close();
