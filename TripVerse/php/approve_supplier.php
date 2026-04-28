<?php
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log for debugging
error_log("=== APPROVE SUPPLIER REQUEST START ===");
error_log("Session data: " . print_r($_SESSION, true));
error_log("POST data: " . print_r($_POST, true));

if (!isset($_SESSION['id_user']) || $_SESSION['role'] !== 'admin') {
    error_log("Access denied: User not admin or not logged in");
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden: Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Invalid method: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

include 'connect.php';

$admin = $_SESSION['id_user'];
$supplierId = $_POST['id'] ?? null;

error_log("Admin ID: $admin");
error_log("Supplier ID: " . ($supplierId ?: 'NULL'));

if (!$supplierId) {
    error_log("Supplier ID is missing");
    echo json_encode(['success' => false, 'message' => 'Supplier ID is required']);
    exit;
}

// Validate supplier exists and is owner
$checkSql = "SELECT id_user, first_name, last_name, approved FROM user WHERE id_user = ? AND role = 'owner'";
$checkStmt = $conn->prepare($checkSql);

if (!$checkStmt) {
    error_log("Prepare failed: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$checkStmt->bind_param('s', $supplierId);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows === 0) {
    error_log("Supplier not found: $supplierId");
    $checkStmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
    exit;
}

$supplier = $checkResult->fetch_assoc();
$currentStatus = $supplier['approved'];
$supplierName = $supplier['first_name'] . ' ' . $supplier['last_name'];
$checkStmt->close();

error_log("Supplier found: $supplierName");
error_log("Current status: " . ($currentStatus === null ? 'NULL' : $currentStatus));

// Check if already approved (string 'approved' atau numeric 1)
if ($currentStatus == 'approved' || $currentStatus == 1) {
    error_log("Supplier already approved: $supplierId");
    echo json_encode(['success' => false, 'message' => 'Supplier is already approved']);
    $conn->close();
    exit;
}

// Check if already rejected (string 'rejected' atau numeric 2)
if ($currentStatus == 'rejected' || $currentStatus == 2) {
    error_log("Supplier already rejected: $supplierId");
    echo json_encode(['success' => false, 'message' => 'Supplier is already rejected. Please contact admin to change status.']);
    $conn->close();
    exit;
}

// Update supplier status to approved
$sql = "UPDATE user SET approved = 'approved', approved_by = ?, approved_at = NOW() WHERE id_user = ? AND role = 'owner'";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log("Prepare update failed: " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    $conn->close();
    exit;
}

$stmt->bind_param('ss', $admin, $supplierId);

if ($stmt->execute()) {
    $affectedRows = $stmt->affected_rows;
    error_log("Update successful. Affected rows: $affectedRows");
    
    // Check if activity_log table exists
    $logCheck = $conn->query("SHOW TABLES LIKE 'activity_log'");
    if ($logCheck && $logCheck->num_rows > 0) {
        // Log the approval action
        $logSql = "INSERT INTO activity_log (user_id, action_type, action_description, entity_type, entity_id, entity_name, ip_address, user_agent) 
                   VALUES (?, 'approve_supplier', ?, 'user', ?, ?, ?, ?)";
        $logStmt = $conn->prepare($logSql);
        
        if ($logStmt) {
            $actionDesc = "Approved supplier: " . $supplierName . " (ID: " . $supplierId . ")";
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            $logStmt->bind_param('ssssss', $admin, $actionDesc, $supplierId, $supplierName, $ip, $agent);
            $logStmt->execute();
            $logStmt->close();
            error_log("Activity log created");
        } else {
            error_log("Failed to prepare activity log: " . $conn->error);
        }
    } else {
        error_log("Activity log table doesn't exist, skipping logging");
    }
    
    error_log("=== APPROVE SUPPLIER REQUEST SUCCESS ===");
    echo json_encode([
        'success' => true,
        'message' => 'Supplier approved successfully',
        'supplier_id' => $supplierId,
        'supplier_name' => $supplierName
    ]);
} else {
    error_log("Update failed: " . $stmt->error);
    echo json_encode(['success' => false, 'message' => 'Failed to approve supplier: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>