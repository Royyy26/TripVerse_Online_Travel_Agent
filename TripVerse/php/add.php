<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require 'connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hotel_id = $_POST['hotel_id'];
    $tipe_id = $_POST['tipe_id'];
    $harga = $_POST['harga'];
    $stok_total = $_POST['stok_total'];

    // Add to jadwal_hotel
    $query1 = "INSERT INTO jadwal_hotel (hotel_id, tipe_id, harga, stok_total, terbooking) 
               VALUES (?, ?, ?, ?, 0)";
    $stmt1 = $conn->prepare($query1);
    $stmt1->bind_param("ssdi", $hotel_id, $tipe_id, $harga, $stok_total);

    // Add to kamar
    $query2 = "INSERT INTO kamar (hotel_id, tipe_id, view, status) 
               VALUES (?, ?, 'City', 'Available')";
    $stmt2 = $conn->prepare($query2);
    $stmt2->bind_param("ss", $hotel_id, $tipe_id);

    if ($stmt1->execute() && $stmt2->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $stmt1->error . " " . $stmt2->error]);
    }
    $stmt1->close();
    $stmt2->close();
} else {
    echo json_encode(['success' => true, 'message' => 'Invalid request']);
}
