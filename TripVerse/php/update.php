<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require 'connect.php';

function jsonError($message)
{
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Invalid request method');
}

$required_fields = ['hotel_id', 'tipe_id', 'harga', 'stok_total'];
foreach ($required_fields as $field) {
    if (empty($_POST[$field]) && $_POST[$field] !== '0') {
        jsonError("Field $field tidak boleh kosong");
    }
}

// Ambil dan sanitasi data
$hotel_id = trim($_POST['hotel_id']);    
$tipe_id = trim($_POST['tipe_id']);       
$harga = filter_var($_POST['harga'], FILTER_VALIDATE_FLOAT);
$stok_total = filter_var($_POST['stok_total'], FILTER_VALIDATE_INT);

if ($harga === false || $stok_total === false || $hotel_id === '' || $tipe_id === '') {
    jsonError('Data tidak valid: ' . json_encode([
        'hotel_id' => $hotel_id,
        'tipe_id' => $tipe_id,
        'harga' => $harga,
        'stok_total' => $stok_total,
        'raw_post' => $_POST
    ]));
}

if ($harga < 0 || $stok_total < 0) {
    jsonError('Harga dan stok tidak boleh negatif');
}

// Update database
$query = "UPDATE jadwal_hotel SET harga = ?, stok_total = ? 
          WHERE hotel_id = ? AND tipe_id = ?";

$stmt = $conn->prepare($query);
if (!$stmt) {
    jsonError('Prepare error: ' . $conn->error);
}

// Gunakan: double, int, string, string
$stmt->bind_param("diss", $harga, $stok_total, $hotel_id, $tipe_id);

if (!$stmt->execute()) {
    jsonError('Execute error: ' . $stmt->error);
}

if ($stmt->affected_rows === 0) {
    jsonError('Tidak ada data yang diperbarui. Periksa apakah data sudah sesuai.');
}

$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'message' => 'Data berhasil diperbarui']);
