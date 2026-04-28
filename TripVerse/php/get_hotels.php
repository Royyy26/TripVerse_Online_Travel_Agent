<?php
require_once 'connect.php';

header('Content-Type: application/json');

$city = $_GET['city'] ?? '';

$query = "SELECT hotel_id, nama_hotel FROM hotel WHERE kota = ? ORDER BY nama_hotel";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $city);
$stmt->execute();
$result = $stmt->get_result();

$hotels = [];
while ($row = $result->fetch_assoc()) {
    $hotels[] = $row;
}

echo json_encode($hotels);
$conn->close();
?>