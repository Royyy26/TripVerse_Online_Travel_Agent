<?php
/**
 * Activity Log Helper Functions
 * Functions to log activities performed by suppliers/owners
 */

/**
 * Log an activity
 * 
 * @param mysqli $conn Database connection
 * @param string $user_id User ID performing the action
 * @param string $action_type Type of action (e.g., 'add_hotel', 'edit_hotel', 'delete_hotel', 'add_room', etc.)
 * @param string $action_description Description of the action
 * @param string|null $entity_type Type of entity (e.g., 'hotel', 'room', 'booking', 'room_type')
 * @param string|null $entity_id ID of the entity
 * @param string|null $entity_name Name of the entity
 * @param string|null $hotel_id Hotel ID if related to a hotel
 * @return bool True on success, false on failure
 */
function logActivity($conn, $user_id, $action_type, $action_description, $entity_type = null, $entity_id = null, $entity_name = null, $hotel_id = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $sql = "INSERT INTO activity_log (user_id, action_type, action_description, entity_type, entity_id, entity_name, hotel_id, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare activity log statement: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("sssssssss", 
        $user_id, 
        $action_type, 
        $action_description, 
        $entity_type, 
        $entity_id, 
        $entity_name, 
        $hotel_id, 
        $ip_address, 
        $user_agent
    );
    
    $result = $stmt->execute();
    if (!$result) {
        error_log("Failed to log activity: " . $stmt->error);
    }
    
    $stmt->close();
    return $result;
}

/**
 * Get activity logs for a user
 * 
 * @param mysqli $conn Database connection
 * @param string $user_id User ID
 * @param int $limit Number of logs to retrieve
 * @param string|null $action_type Filter by action type
 * @param string|null $hotel_id Filter by hotel ID
 * @return array Array of activity logs
 */
function getActivityLogs($conn, $user_id, $limit = 100, $action_type = null, $hotel_id = null) {
    $whereClause = "al.user_id = ?";
    $params = [$user_id];
    $types = 's';
    
    if ($action_type) {
        $whereClause .= " AND al.action_type = ?";
        $params[] = $action_type;
        $types .= 's';
    }
    
    if ($hotel_id) {
        $whereClause .= " AND al.hotel_id = ?";
        $params[] = $hotel_id;
        $types .= 's';
    }
    
    $sql = "SELECT al.*, u.first_name, u.last_name, u.email, h.nama_hotel
            FROM activity_log al
            LEFT JOIN user u ON al.user_id = u.id_user
            LEFT JOIN hotel h ON al.hotel_id = h.hotel_id
            WHERE $whereClause
            ORDER BY al.created_at DESC
            LIMIT ?";
    
    $params[] = $limit;
    $types .= 'i';
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    
    $stmt->close();
    return $logs;
}

