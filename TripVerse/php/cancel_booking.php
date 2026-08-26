<?php
session_start();
header('Content-Type: application/json');

// Aktifkan error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log untuk debugging
file_put_contents('cancel_log.txt', "\n".date('Y-m-d H:i:s')." - Memulai pembatalan\n", FILE_APPEND);

// Database connection
require 'connect.php';

// Validasi user
if (!isset($_SESSION['id_user'])) {
    file_put_contents('cancel_log.txt', "Error: User tidak login\n", FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Validasi input
if (!isset($_GET['id']) || empty($_GET['id'])) {
    file_put_contents('cancel_log.txt', "Error: ID booking tidak valid\n", FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit;
}

$booking_id = $conn->real_escape_string($_GET['id']);
$user_id = $_SESSION['id_user'];

try {
    $conn->begin_transaction();
    file_put_contents('cancel_log.txt', "Transaksi dimulai\n", FILE_APPEND);

    // 1. Dapatkan detail booking
    $sql = "SELECT bh.hotel_id, bh.tipe_id, bh.jumlah_kamar, jh.terbooking
            FROM booking_hotel bh
            JOIN jadwal_hotel jh ON bh.hotel_id = jh.hotel_id AND bh.tipe_id = jh.tipe_id
            JOIN customer c ON bh.customer_id = c.customer_id
            WHERE bh.booking_id = ? AND c.id_user = ? AND bh.status = 'Pending'
            FOR UPDATE";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Prepare error: ".$conn->error);
    
    $stmt->bind_param("ss", $booking_id, $user_id);
    if (!$stmt->execute()) throw new Exception("Execute error: ".$stmt->error);
    
    $result = $stmt->get_result();
    if ($result->num_rows === 0) throw new Exception("Booking tidak ditemukan");
    
    $data = $result->fetch_assoc();
    $stmt->close();

    file_put_contents('cancel_log.txt', "Data booking: ".print_r($data, true)."\n", FILE_APPEND);

    // 2. Kurangi terbooking dengan jumlah kamar yang dibatalkan
    $new_terbooking = $data['terbooking'] - $data['jumlah_kamar'];
    if ($new_terbooking < 0) $new_terbooking = 0; // Pastikan tidak negatif

    $sql = "UPDATE jadwal_hotel 
            SET terbooking = ?
            WHERE hotel_id = ? AND tipe_id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Prepare error: ".$conn->error);
    
    $stmt->bind_param("iss", $new_terbooking, $data['hotel_id'], $data['tipe_id']);
    if (!$stmt->execute()) throw new Exception("Execute error: ".$stmt->error);
    
    if ($stmt->affected_rows === 0) throw new Exception("Stok tidak terupdate");
    $stmt->close();
    // 3. Update status booking
    $sql = "UPDATE booking_hotel SET status = 'Cancelled' WHERE booking_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Prepare error: ".$conn->error);
    
    $stmt->bind_param("s", $booking_id);
    if (!$stmt->execute()) throw new Exception("Execute error: ".$stmt->error);
    
    if ($stmt->affected_rows === 0) throw new Exception("Status booking tidak terupdate");
    $stmt->close();

    // 4. Update status transaksi jika ada
    $sql = "UPDATE transaksi t
           JOIN transaksi_hotel th ON t.id_transaksi = th.id_transaksi
           SET t.status_transaksi = 'Cancelled'
           WHERE th.booking_id = ?";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("s", $booking_id);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
    header("Location: history.php");
    exit();
    // file_put_contents('cancel_log.txt', "Transaksi berhasil\n", FILE_APPEND);
    
    // echo json_encode([
    //     'success' => true,
    //     'message' => 'Booking dibatalkan dan stok dikembalikan',
    //     'terbooking_sebelum' => $data['terbooking'],
    //     'terbooking_sesudah' => $new_terbooking
    // ]);

} catch (Exception $e) {
    $conn->rollback();
    file_put_contents('cancel_log.txt', "Error: ".$e->getMessage()."\n", FILE_APPEND);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>