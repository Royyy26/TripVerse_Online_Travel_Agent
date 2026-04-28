<?php
require_once 'connect.php';

header('Content-Type: application/json');

$hotel = $_GET['hotel'] ?? '';

$query = "SELECT tipe_id, nama_tipe FROM tipe_kamar WHERE hotel_id = ? ORDER BY nama_tipe";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $hotel);
$stmt->execute();
$result = $stmt->get_result();

$rooms = [];
while ($row = $result->fetch_assoc()) {
    $rooms[] = $row;
}

echo json_encode($rooms);
$conn->close();
?>