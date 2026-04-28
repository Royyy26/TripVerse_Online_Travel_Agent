<?php
session_start();

if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

require 'connect.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

try {
    // Validasi input
    if (!isset($_POST['hotel_id']) || !is_numeric($_POST['hotel_id'])) {
        throw new Exception('Invalid hotel ID');
    }

    $hotel_id = (int)$_POST['hotel_id'];

    // Ambil data foto sebelum dihapus
    $stmt = $conn->prepare("SELECT foto_hotel FROM hotel WHERE hotel_id = ?");
    $stmt->bind_param("i", $hotel_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $hotel = $result->fetch_assoc();
    $stmt->close();

    // Hapus data dari database
    $stmt = $conn->prepare("DELETE FROM hotel WHERE hotel_id = ?");
    $stmt->bind_param("i", $hotel_id);

    if ($stmt->execute()) {
        // Hapus file foto jika ada
        if (!empty($hotel['foto_hotel']) && file_exists($hotel['foto_hotel'])) {
            unlink($hotel['foto_hotel']);
        }

        $response['success'] = true;
        $response['message'] = 'Hotel berhasil dihapus';
    } else {
        throw new Exception('Gagal menghapus hotel: ' . $stmt->error);
    }

    $stmt->close();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
} finally {
    $conn->close();
    echo json_encode($response);
}
