<?php
session_start();
if (!isset($_SESSION['id_user'])) {
    header("Location: auth/login.php");
    exit;
}

// Database connection
require_once __DIR__ . '/db_config.php';

// Function to increment discount usage
function incrementDiscountUsage($conn, $diskon_id) {
    $sql = "UPDATE diskon_promo 
            SET terpakai = terpakai + 1 
            WHERE diskon_id = ? 
            AND (kuota IS NULL OR terpakai < kuota)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $diskon_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

// Function to decrement discount usage (if booking cancelled)
function decrementDiscountUsage($conn, $diskon_id) {
    $sql = "UPDATE diskon_promo 
            SET terpakai = GREATEST(terpakai - 1, 0) 
            WHERE diskon_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $diskon_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

// Process promo usage after successful booking
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['booking_id'])) {
    $booking_id = $conn->real_escape_string($_POST['booking_id']);
    $diskon_id = $conn->real_escape_string($_POST['diskon_id']);
    
    // Increment discount usage
    if (!empty($diskon_id)) {
        incrementDiscountUsage($conn, $diskon_id);
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// Process promo rollback if booking cancelled
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = $conn->real_escape_string($_POST['booking_id']);
    
    // Get discount ID from booking
    $sql = "SELECT diskon_id FROM booking_hotel WHERE booking_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $diskon_id = $row['diskon_id'];
        
        // Decrement discount usage
        if (!empty($diskon_id)) {
            decrementDiscountUsage($conn, $diskon_id);
        }
    }
    
    $stmt->close();
    
    echo json_encode(['success' => true]);
    exit;
}

$conn->close();
?>