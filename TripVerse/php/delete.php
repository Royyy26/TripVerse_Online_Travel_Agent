<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require 'connect.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'owner'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $hotel_id = $data['hotel_id'];
    $tipe_id = $data['tipe_id'];

    // First delete from kamar table
    $delete1 = $conn->prepare("DELETE FROM kamar WHERE hotel_id = ? AND tipe_id = ?");
    $delete1->bind_param("ss", $hotel_id, $tipe_id);
    $delete1->execute();
    $delete1->close();

    // Then delete from jadwal_hotel
    $delete2 = $conn->prepare("DELETE FROM jadwal_hotel WHERE hotel_id = ? AND tipe_id = ?");
    $delete2->bind_param("ss", $hotel_id, $tipe_id);

    if ($delete2->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $delete2->error]);
    }
    $delete2->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
