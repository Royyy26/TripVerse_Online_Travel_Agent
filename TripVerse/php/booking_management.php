<?php
session_start();
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['owner', 'admin'])) {
    echo "<script>alert('Akses ditolak!'); window.location='home.php';</script>";
    exit;
}

require 'connect.php';
require 'activity_log_helper.php';

$id_user = $_SESSION['id_user'];
$user_role = $_SESSION['role'] ?? '';
$message = '';

// Handle booking status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking_status'])) {
    $booking_id = $_POST['booking_id'];
    $new_status = $_POST['status'];

    // Verify booking ownership through hotel
    if ($user_role === 'admin') {
        // Admin can update any booking
        $booking_check = $conn->prepare("SELECT 1 FROM booking_hotel WHERE booking_id = ?");
        $booking_check->bind_param("s", $booking_id);
    } else {
        // Owner can only update their own hotel bookings
        $booking_check = $conn->prepare("SELECT 1 FROM booking_hotel bh 
                                        INNER JOIN hotel h ON bh.hotel_id = h.hotel_id 
                                        WHERE bh.booking_id = ? AND h.owner_id = ?");
        $booking_check->bind_param("ss", $booking_id, $id_user);
    }
    
    $booking_check->execute();
    $booking_exists = $booking_check->get_result()->fetch_row();
    $booking_check->close();

    if ($booking_exists) {
        $update_sql = "UPDATE booking_hotel SET status = ? WHERE booking_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if ($update_stmt) {
            $update_stmt->bind_param("ss", $new_status, $booking_id);
            if ($update_stmt->execute()) {
                $message = "Booking status updated successfully!";
                // Get booking details for logging
                $booking_detail = $conn->prepare("SELECT bh.*, h.nama_hotel FROM booking_hotel bh INNER JOIN hotel h ON bh.hotel_id = h.hotel_id WHERE bh.booking_id = ?");
                $booking_detail->bind_param("s", $booking_id);
                $booking_detail->execute();
                $booking_result = $booking_detail->get_result();
                if ($booking_row = $booking_result->fetch_assoc()) {
                    logActivity($conn, $id_user, 'update_booking_status', "Updated booking status: {$booking_id} to {$new_status} for hotel {$booking_row['nama_hotel']}", 'booking', $booking_id, "Booking {$booking_id}", $booking_row['hotel_id']);
                }
                $booking_detail->close();
            } else {
                $message = "Error: " . $update_stmt->error;
            }
            $update_stmt->close();
        }
    } else {
        $message = "Error: You don't have access to this booking!";
    }
}

// Search and filter parameters
$search_query = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$hotel_filter = $_GET['hotel_id'] ?? '';

// Build dynamic query based on role
if ($user_role === 'admin') {
    // Admin can see all bookings
    $whereClause = "1=1";
    $params = [];
    $types = '';
} else {
    // Owner can only see their own hotel bookings
    $whereClause = "h.owner_id = ?";
    $params = [$id_user];
    $types = 's';
}

if (!empty($search_query)) {
    $whereClause .= " AND (c.nama LIKE ? OR h.nama_hotel LIKE ? OR bh.booking_id LIKE ?)";
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

if (!empty($status_filter)) {
    $whereClause .= " AND bh.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($hotel_filter)) {
    $whereClause .= " AND bh.hotel_id = ?";
    $params[] = $hotel_filter;
    $types .= 's';
}

// Get bookings from booking_hotel table
$bookings_query = "SELECT bh.*, 
                          h.nama_hotel, h.kota, h.alamat,
                          c.nama AS customer_name, c.email AS customer_email, c.no_hp AS customer_phone,
                          tk.nama_tipe AS room_type_name, tk.deskripsi AS room_description
                   FROM booking_hotel bh
                   INNER JOIN hotel h ON bh.hotel_id = h.hotel_id
                   INNER JOIN customer c ON bh.customer_id = c.customer_id
                   LEFT JOIN tipe_kamar tk ON bh.tipe_id = tk.tipe_id
                   WHERE $whereClause
                   ORDER BY bh.tanggal_booking DESC";

$bookings_stmt = $conn->prepare($bookings_query);
$bookings = [];
$total_gross_revenue = 0;
$total_admin_fee = 0;
$total_net_revenue = 0;
$revenue_by_status = [
    'Confirmed' => 0,
    'Completed' => 0,
    'Pending' => 0,
    'Cancelled' => 0
];

if ($bookings_stmt) {
    if (!empty($params)) {
        $bookings_stmt->bind_param($types, ...$params);
    }
    $bookings_stmt->execute();
    $bookings_result = $bookings_stmt->get_result();
    while ($row = $bookings_result->fetch_assoc()) {
        $bookings[] = $row;
        
        // Calculate revenue
        $gross = (float)$row['total_harga'];
        $admin_fee = $gross * 0.05;
        $net = $gross - $admin_fee;
        
        $total_gross_revenue += $gross;
        $total_admin_fee += $admin_fee;
        $total_net_revenue += $net;
        
        // Track revenue by status
        $status = $row['status'] ?? 'Pending';
        if (isset($revenue_by_status[$status])) {
            $revenue_by_status[$status] += $net;
        }
    }
    $bookings_stmt->close();
}

// Get hotels for filter (all for admin, owner's hotels for owner)
if ($user_role === 'admin') {
    $hotels_query = "SELECT hotel_id, nama_hotel FROM hotel ORDER BY nama_hotel";
    $hotels_stmt = $conn->prepare($hotels_query);
    $hotels = [];
    if ($hotels_stmt) {
        $hotels_stmt->execute();
        $hotels_result = $hotels_stmt->get_result();
        while ($row = $hotels_result->fetch_assoc()) {
            $hotels[] = $row;
        }
        $hotels_stmt->close();
    }
} else {
    $hotels_query = "SELECT hotel_id, nama_hotel FROM hotel WHERE owner_id = ? ORDER BY nama_hotel";
    $hotels_stmt = $conn->prepare($hotels_query);
    $hotels = [];
    if ($hotels_stmt) {
        $hotels_stmt->bind_param("s", $id_user);
        $hotels_stmt->execute();
        $hotels_result = $hotels_stmt->get_result();
        while ($row = $hotels_result->fetch_assoc()) {
            $hotels[] = $row;
        }
        $hotels_stmt->close();
    }
}
// Ambil info owner untuk header
$id_user = $_SESSION['id_user'];
$stmt = $conn->prepare("SELECT username, email, first_name, last_name, profile_picture FROM user WHERE id_user = ?");
$profile_picture = null;
$user_initials = 'U';

if ($stmt) {
    $stmt->bind_param("s", $id_user);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    
    // Generate initials dari nama
    if ($u) {
        $first = $u['first_name'] ?? '';
        $last = $u['last_name'] ?? '';
        $user_initials = strtoupper(
            (empty($first) ? '' : $first[0]) . 
            (empty($last) ? '' : $last[0])
        );
        if (empty($user_initials)) {
            $user_initials = 'U';
        }
    }
    
    // Check for profile picture
    if ($u && !empty($u['profile_picture'])) {
        // Check multiple possible locations
        $possible_paths = [
            __DIR__ . '/../uploads/' . $u['profile_picture'],
            __DIR__ . '/../uploads/profiles/' . $u['profile_picture'],
            __DIR__ . '/../uploads/users/' . $u['profile_picture']
        ];
        
        foreach ($possible_paths as $check_path) {
            if (file_exists($check_path)) {
                $profile_picture = $u['profile_picture'];
                break;
            }
        }
        
        // If not found in specific folders, check root uploads
        if ($profile_picture === null && file_exists(__DIR__ . '/../uploads/' . $u['profile_picture'])) {
            $profile_picture = $u['profile_picture'];
        }
    }
    
    $stmt->close();
}

// Generate fallback avatar SVG
$avatar_colors = ['#1a237e', '#0277bd', '#00838f', '#00897b', '#283593', '#3949ab'];
$color_index = abs(crc32($user_initials ?? 'U')) % count($avatar_colors);
$fallback_color = $avatar_colors[$color_index];
$fallback_avatar = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 200 200%22%3E%3Crect fill=%22' . urlencode($fallback_color) . '%22 width=%22200%22 height=%22200%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 font-size=%2280%22 font-weight=%22bold%22 fill=%22white%22 text-anchor=%22middle%22 dy=%22.35em%22 font-family=%22Arial, sans-serif%22%3E' . urlencode($user_initials) . '%3C/text%3E%3C/svg%3E';

// AJAX handler: return booking detail snippet when requested
if (isset($_GET['ajax_view_booking']) && $_GET['ajax_view_booking'] == '1' && !empty($_GET['booking_id'])) {
    $bid = $_GET['booking_id'];
    $detail_sql = "SELECT bh.*, h.nama_hotel, h.alamat, h.kota, c.nama AS customer_name, c.email AS customer_email, c.no_hp AS customer_phone, tk.nama_tipe AS room_type_name
                   FROM booking_hotel bh
                   INNER JOIN hotel h ON bh.hotel_id = h.hotel_id
                   INNER JOIN customer c ON bh.customer_id = c.customer_id
                   LEFT JOIN tipe_kamar tk ON bh.tipe_id = tk.tipe_id
                   WHERE bh.booking_id = ? LIMIT 1";
    $dstmt = $conn->prepare($detail_sql);
    if ($dstmt) {
        $dstmt->bind_param('s', $bid);
        $dstmt->execute();
        $bres = $dstmt->get_result();
        if ($row = $bres->fetch_assoc()) {
            // Build HTML snippet with grid field-boxes
            $out  = '<div class="detail-section">';
            $out .= '<div class="section-title"><span class="material-icons">person</span><span>Customer Info</span></div>';
            $out .= '<div class="detail-grid">';
            $out .= '<div class="field-box"><div class="field-label">Customer Name</div><div class="field-value">' . htmlspecialchars($row['customer_name']) . '</div></div>';
            $out .= '<div class="field-box"><div class="field-label">Email</div><div class="field-value">' . htmlspecialchars($row['customer_email']) . '</div></div>';
            $out .= '<div class="field-box"><div class="field-label">Phone</div><div class="field-value">' . htmlspecialchars($row['customer_phone']) . '</div></div>';
            $out .= '<div class="field-box"><div class="field-label">Booking ID</div><div class="field-value">' . htmlspecialchars($row['booking_id']) . '</div></div>';
            $out .= '</div>';
            $out .= '</div>';

            // Booking details section
            $out .= '<div class="detail-section">';
            $out .= '<div class="section-title"><span class="material-icons">meeting_room</span><span>Booking Details</span></div>';
            $out .= '<div class="detail-grid">';
            $out .= '<div class="field-box"><div class="field-label">Hotel</div><div class="field-value">' . htmlspecialchars($row['nama_hotel']) . '</div></div>';
            $out .= '<div class="field-box"><div class="field-label">Room Type</div><div class="field-value">' . htmlspecialchars($row['room_type_name'] ?? $row['tipe_id']) . '</div></div>';
            $out .= '<div class="field-box"><div class="field-label">Check-In</div><div class="field-value">' . date('F d, Y', strtotime($row['check_in'])) . '</div></div>';
            $out .= '<div class="field-box"><div class="field-label">Check-Out</div><div class="field-value">' . date('F d, Y', strtotime($row['check_out'])) . '</div></div>';
            $out .= '<div class="field-box"><div class="field-label">Number of Rooms</div><div class="field-value">' . (int)$row['jumlah_kamar'] . '</div></div>';
            $gross_total = (float)$row['total_harga'];
            $admin_fee_detail = $gross_total * 0.05;
            $net_total = $gross_total - $admin_fee_detail;
            $out .= '<div class="field-box"><div class="field-label">Gross Price</div><div class="field-value">Rp ' . number_format($gross_total, 0, ',', '.') . '</div></div>';
            $out .= '<div class="field-box"><div class="field-label">Admin Fee (5%)</div><div class="field-value" style="color: #d93025;">- Rp ' . number_format($admin_fee_detail, 0, ',', '.') . '</div></div>';
            $out .= '<div class="field-box" style="background: #e8f2ff; border: 2px solid #1f6feb;"><div class="field-label" style="color: #1f6feb;">NET PRICE (Owner Receives)</div><div class="field-value" style="color: #1f6feb; font-size: 1.2rem;">Rp ' . number_format($net_total, 0, ',', '.') . '</div></div>';
            $out .= '<div class="field-box"><div class="field-label">Payment Method</div><div class="field-value">' . htmlspecialchars($row['payment_method'] ?? '-') . '</div></div>';
            $out .= '<div class="field-box"><div class="field-label">Hotel Address</div><div class="field-value">' . htmlspecialchars($row['alamat'] . ', ' . $row['kota']) . '</div></div>';
            $out .= '<div class="field-box" style="visibility:hidden;"></div>';
            $out .= '</div>';
            $out .= '</div>';

            // Booking status section
            $statusBgClass = '';
            if ($row['status'] === 'Completed') $statusBgClass = 'status-completed';
            elseif ($row['status'] === 'Cancelled') $statusBgClass = 'status-cancelled';
            elseif ($row['status'] === 'Confirmed') $statusBgClass = 'status-confirmed';
            else $statusBgClass = 'status-pending';
            
            $out .= '<div class="detail-section">';
            $out .= '<div class="section-title"><span class="material-icons">info</span><span>Booking Status</span></div>';
            $out .= '<div class="detail-grid">';
            $out .= '<div class="field-box"><div class="field-label">Status</div><div style="margin-top:8px;"><span class="status-badge ' . $statusBgClass . '">' . htmlspecialchars($row['status']) . '</span></div></div>';
            $out .= '<div class="field-box"><div class="field-label">Booking Date</div><div class="field-value">' . date('F d, Y \\a\\t h:i A', strtotime($row['tanggal_booking'])) . '</div></div>';
            $out .= '</div>';
            $out .= '</div>';

            echo $out;
        } else {
            echo '<div class="detail-section"><div>Booking tidak ditemukan.</div></div>';
        }
        $dstmt->close();
    } else {
        echo '<div class="detail-section"><div>Error preparing query.</div></div>';
    }
    // Close connection and exit to return only snippet
    $conn->close();
    exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Management - TripVerse</title>
    <link rel="stylesheet" href="../css/owner_dashboard.css?v=3.0.0">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --sidebar-width: 260px; --sidebar-collapsed-width: 80px; --page-padding: 24px; }
        
        /* Revenue Summary Cards */
        .revenue-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            margin-left: 30px;
            margin-right: 30px;
        }
        
        .revenue-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(15, 23, 34, 0.08);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .revenue-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #1a237e, #283593);
        }
        
        .revenue-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }
        
        .revenue-card.primary::before {
            background: linear-gradient(90deg, #0f9d58, #0d8a4c);
        }
        
        .revenue-card.secondary::before {
            background: linear-gradient(90deg, #1f6feb, #0d47a1);
        }
        
        .revenue-card.warning::before {
            background: linear-gradient(90deg, #ff9900, #ff6600);
        }
        
        .revenue-card.danger::before {
            background: linear-gradient(90deg, #d93025, #b71c1c);
        }
        
        .revenue-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .revenue-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .revenue-card.primary .revenue-icon {
            background: rgba(15, 157, 88, 0.1);
            color: #0f9d58;
        }
        
        .revenue-card.secondary .revenue-icon {
            background: rgba(31, 111, 235, 0.1);
            color: #1f6feb;
        }
        
        .revenue-card.warning .revenue-icon {
            background: rgba(255, 153, 0, 0.1);
            color: #ff9900;
        }
        
        .revenue-card.danger .revenue-icon {
            background: rgba(217, 48, 37, 0.1);
            color: #d93025;
        }
        
        .revenue-card-title {
            font-size: 0.85rem;
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .revenue-card-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #0f1724;
            margin-bottom: 8px;
        }
        
        .revenue-card-subtitle {
            font-size: 0.8rem;
            color: #9ca3af;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .revenue-breakdown {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(15, 23, 34, 0.08);
        }
        
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: #6b7280;
            margin-bottom: 6px;
        }
        
        .breakdown-item:last-child {
            margin-bottom: 0;
        }
        
        .breakdown-value {
            font-weight: 600;
            color: #0f1724;
        }
        
        /* Booking cards layout (matches provided screenshot) */
        .bookings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        .booking-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(16,24,40,0.06);
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            border-left: 4px solid transparent;
            position: relative;
        }
        .booking-card .card-top {
            display:flex;align-items:center;justify-content:space-between;gap:10px;
        }
        .booking-id { color:#7b7f88;font-size:13px; }
        .status-badge { padding:6px 10px;border-radius:20px;font-size:13px;font-weight:600;display:inline-block }
        .status-pending { background:#fff4e5;color:#b45b00;border:1px solid rgba(180,91,0,0.08); }
        .status-confirmed { background:#e8f2ff;color:#1f6feb;border:1px solid rgba(31,111,235,0.08); }
        .status-completed { background:#e8fdf1;color:#0f9d58;border:1px solid rgba(15,157,88,0.08); }
        .status-cancelled { background:#fff1f1;color:#d93025;border:1px solid rgba(217,48,37,0.08); }
        .booking-meta { color:#4b5563;font-size:14px;display:flex;flex-direction:column;gap:4px }
        .hotel-name { font-weight:600;color:#0f1724 }
        .room-type { color:#6b7280;font-size:13px }

        .check-block {
            background:#f8fafc;border-radius:10px;padding:12px;display:flex;gap:14px;align-items:center;border:1px solid rgba(15,23,34,0.04)
        }
        .check-item { display:flex;flex-direction:column;gap:6px }
        .check-item .label { font-size:12px;color:#6b7280 }
        .check-item .value { font-weight:600 }

        .mini-row { display:flex;gap:10px;margin-top:8px }
        .mini-card { flex:1;background:#fff;border-radius:8px;padding:10px;border:1px solid rgba(15,23,34,0.04);text-align:center }
        .mini-card .label { font-size:12px;color:#6b7280 }
        .mini-card .value { margin-top:6px;font-weight:700 }

        .card-bottom { display:flex;justify-content:space-between;align-items:flex-start;gap:10px }
        .total-price { color:#1f6feb;font-weight:700;flex:1 }
        .action-buttons { display:flex;gap:8px;flex-shrink:0 }
        .action-buttons button { border:0;background:#f3f4f6;padding:8px;border-radius:8px;cursor:pointer }
        .action-eye { background:#fff;border:1px solid rgba(15,23,34,0.04) }

        /* left border color by status */
        .booking-card.status-Completed { border-left-color: #0f9d58 }
        .booking-card.status-Cancelled { border-left-color: #d93025 }
        .booking-card.status-Confirmed { border-left-color: #1f6feb }
        .booking-card.status-Pending { border-left-color: #b45b00 }

        /* empty state */
        .empty-state { text-align:center;padding:40px;border-radius:12px;background:#fff;border:1px solid rgba(15,23,34,0.04) }
        @media (max-width:600px){ .check-block{flex-direction:column;align-items:flex-start} .mini-row{flex-direction:row} }
        /* Modal styles for detail view */
        .modal { display: none; position: fixed; inset: 0; background: rgba(2,6,23,0.45); align-items: center; justify-content: center; z-index: 9999; }
        .modal .modal-content { width: 95%; max-width: 980px; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 20px 50px rgba(2,6,23,0.2); max-height: 92vh; display:flex; flex-direction:column; }
        .modal .modal-header { background: linear-gradient(90deg,#2b6cb0,#6366f1); color: #fff; padding: 16px 20px; display:flex; align-items:center; justify-content:space-between; }
        .modal .modal-header h2 { margin:0; font-size:18px; }
        .modal .close-modal { background: rgba(255,255,255,0.15); color:#fff; border-radius:6px; padding:6px 10px; cursor:pointer; font-weight:700; border:none; }
        .modal .modal-body { padding: 18px; overflow:auto; }
        .modal .modal-footer { padding:12px 18px; border-top: 1px solid rgba(15,23,34,0.04); text-align:right; }
        /* Detail layout inside modal */
        .detail-section { background:#fff; border-radius:8px; padding:12px; margin-bottom:18px; }
        .detail-grid { display:grid; grid-template-columns: repeat(2, 1fr); gap:12px; }
        .field-box { background:#f8fafc; border-radius:8px; padding:12px; text-align:center; border:1px solid rgba(15,23,34,0.04); }
        .field-label { font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.6px; }
        .field-value { margin-top:8px; font-weight:700; color:#0f1724; word-break:break-word; }
        .section-title { display:flex; align-items:center; gap:8px; font-weight:700; margin-bottom:12px; color:#0f1724; }
        .section-title .material-icons { background:#eef2ff; color:#3b82f6; padding:8px; border-radius:6px; font-size:20px; }
        /* Status badge in modal */
        .status-badge.status-completed { background:#e8fdf1; color:#0f9d58; border:1px solid rgba(15,157,88,0.08); border-radius:20px; font-weight:600; font-size:13px; display:inline-block; }
        .status-badge.status-cancelled { background:#fff1f1; color:#d93025; border:1px solid rgba(217,48,37,0.08); border-radius:20px; font-weight:600; font-size:13px; display:inline-block; }
        .status-badge.status-confirmed { background:#e8f2ff; color:#1f6feb; border:1px solid rgba(31,111,235,0.08); border-radius:20px; font-weight:600; font-size:13px; display:inline-block; }
        .status-badge.status-pending { background:#fff4e5; color:#b45b00; border:1px solid rgba(180,91,0,0.08); border-radius:20px; font-weight:600; font-size:13px; display:inline-block; }
        .modal .close-modal { transition: all 0.2s; }
        .modal .close-modal:hover { background: rgba(255,255,255,0.25); transform: scale(1.1); }
        .modal .modal-footer .close-modal { background:#6b7280; color:#fff; padding:8px 16px; }
        .modal .modal-footer .close-modal:hover { background:#4b5563; }
        @media (max-width:720px) { .detail-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <!-- Owner-specific sidebar -->
    <div class="owner-sidebar" id="owner-sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <span class="material-icons">hotel</span>
                <span class="logo-text">TripVerse</span>
            </div>
            <button id="toggleSidebar" class="sidebar-toggle" aria-label="Toggle sidebar">
                <span class="material-icons">menu</span>
            </button>
        </div>
        
         <div class="profile-section">
            <div class="profile-avatar">
                <?php if ($profile_picture): ?>
                    <img src="../uploads/<?php echo htmlspecialchars($profile_picture); ?>" 
                         alt="<?php echo htmlspecialchars(($u['first_name'] ?? 'User') . ' ' . ($u['last_name'] ?? '')); ?>"
                         onerror="this.src='<?php echo $fallback_avatar; ?>'">
                <?php else: ?>
                    <img src="<?php echo $fallback_avatar; ?>" 
                         alt="<?php echo htmlspecialchars(($u['first_name'] ?? 'User') . ' ' . ($u['last_name'] ?? '')); ?>">
                <?php endif; ?>
            </div>
            <div class="profile-info">
                <h3><?php echo htmlspecialchars(($u['first_name'] ?? 'User') . ' ' . ($u['last_name'] ?? '')); ?></h3>
                <p class="profile-role">Hotel Owner</p>
                <p class="profile-email"><?php echo htmlspecialchars($u['email'] ?? ''); ?></p>
            </div>
        </div>

        <nav class="owner-nav">
            <a href="owner_dashboard.php" class="nav-item">
                <span class="material-icons">dashboard</span>
                <span>Dashboard</span>
            </a>
            <a href="hotel_manage.php" class="nav-item">
                <span class="material-icons">hotel</span>
                <span>Manage Hotels</span>
            </a>
            <a href="room_management.php" class="nav-item">
                <span class="material-icons">bed</span>
                <span>Manage Rooms</span>
            </a>
            <a href="extra_facilities_manage.php" class="nav-item">
                <span class="material-icons">room_service</span>
                <span>Extra Facilities</span>
            </a>
            <a href="booking_management.php" class="nav-item active">
                <span class="material-icons">book_online</span>
                <span>Bookings</span>
            </a>
            <a href="activity_log.php" class="nav-item">
                <span class="material-icons">history</span>
                <span>Activity Log</span>
            </a>
            <a href="logout.php" class="nav-item logout">
                <span class="material-icons">logout</span>
                <span>Logout</span>
            </a>
        </nav>
    </div>

    <main class="main-content" id="main-content">
        <header class="main-header">
            <div class="header-left">
                <h1>Booking Management</h1>
                <p class="header-subtitle">Manage customer bookings and reservations</p>
            </div>
            <div class="header-right">
                <div class="header-actions">
                    <span class="booking-count"><?= count($bookings) ?> Total Bookings</span>
                </div>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="notification <?= strpos($message, 'Error') !== false ? 'error' : 'success' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Revenue Summary -->
        <section class="revenue-summary">
            <div class="revenue-card primary">
                <div class="revenue-card-header">
                    <div class="revenue-icon">
                        <span class="material-icons">account_balance_wallet</span>
                    </div>
                    <div>
                        <div class="revenue-card-title">Net Revenue</div>
                    </div>
                </div>
                <div class="revenue-card-value">Rp <?= number_format($total_net_revenue, 0, ',', '.') ?></div>
                <div class="revenue-card-subtitle">
                    <span class="material-icons" style="font-size: 14px;">trending_up</span>
                    Revenue setelah admin fee 5%
                </div>
            </div>

            <div class="revenue-card secondary">
                <div class="revenue-card-header">
                    <div class="revenue-icon">
                        <span class="material-icons">payments</span>
                    </div>
                    <div>
                        <div class="revenue-card-title">Gross Revenue</div>
                    </div>
                </div>
                <div class="revenue-card-value">Rp <?= number_format($total_gross_revenue, 0, ',', '.') ?></div>
                <div class="revenue-card-subtitle">
                    <span class="material-icons" style="font-size: 14px;">receipt_long</span>
                    Total pendapatan kotor
                </div>
            </div>
<!-- 
            <div class="revenue-card warning">
                <div class="revenue-card-header">
                    <div class="revenue-icon">
                        <span class="material-icons">price_change</span>
                    </div>
                    <div>
                        <div class="revenue-card-title">Admin Fee</div>
                    </div>
                </div>
                <div class="revenue-card-value">Rp <?= number_format($total_admin_fee, 0, ',', '.') ?></div>
                <div class="revenue-card-subtitle">
                    <span class="material-icons" style="font-size: 14px;">info</span>
                    Biaya administrasi 5%
                </div>
            </div> -->

            <div class="revenue-card">
                <div class="revenue-card-header">
                    <div class="revenue-icon">
                        <span class="material-icons">bar_chart</span>
                    </div>
                    <div>
                        <div class="revenue-card-title">By Status</div>
                    </div>
                </div>
                <div class="revenue-breakdown">
                    <div class="breakdown-item">
                        <span><span class="material-icons" style="font-size: 14px; color: #0f9d58;">check_circle</span> Completed</span>
                        <span class="breakdown-value">Rp <?= number_format($revenue_by_status['Completed'], 0, ',', '.') ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span><span class="material-icons" style="font-size: 14px; color: #1f6feb;">verified</span> Confirmed</span>
                        <span class="breakdown-value">Rp <?= number_format($revenue_by_status['Confirmed'], 0, ',', '.') ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span><span class="material-icons" style="font-size: 14px; color: #ff9900;">pending</span> Pending</span>
                        <span class="breakdown-value">Rp <?= number_format($revenue_by_status['Pending'], 0, ',', '.') ?></span>
                    </div>
                    <div class="breakdown-item">
                        <span><span class="material-icons" style="font-size: 14px; color: #d93025;">cancel</span> Cancelled</span>
                        <span class="breakdown-value">Rp <?= number_format($revenue_by_status['Cancelled'], 0, ',', '.') ?></span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Search and Filter -->
        <section class="search-filter-section">
            <form method="get" class="search-filter-form">
                <div class="search-group">
                    <div class="search-input">
                        <span class="material-icons">search</span>
                        <input type="text" name="search" placeholder="Search by customer name or hotel..." value="<?= htmlspecialchars($search_query) ?>">
                    </div>
                </div>
                
                <div class="filter-group">
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="Pending" <?= $status_filter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Confirmed" <?= $status_filter === 'Confirmed' ? 'selected' : '' ?>>Confirmed</option>
                        <option value="Cancelled" <?= $status_filter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        <option value="Completed" <?= $status_filter === 'Completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                    
                    <select name="hotel_id">
                        <option value="">All Hotels</option>
                        <?php foreach ($hotels as $hotel): ?>
                            <option value="<?= $hotel['hotel_id'] ?>" <?= $hotel_filter === $hotel['hotel_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($hotel['nama_hotel']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" class="filter-btn">
                        <span class="material-icons">filter_list</span>
                        Filter
                    </button>
                    
                    <a href="booking_management.php" class="clear-btn">
                        <span class="material-icons">clear</span>
                        Clear
                    </a>
                </div>
            </form>
        </section>

        <!-- Bookings Table (card layout) -->
        <section class="bookings-section">
            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <span class="material-icons" style="font-size:40px;color:#9ca3af">book_online</span>
                    <h3 style="margin-top:12px">No bookings found</h3>
                    <p>Bookings will appear here when customers make reservations</p>
                </div>
            <?php else: ?>
                <div class="bookings-grid">
                    <?php foreach ($bookings as $booking):
                        $statusClass = htmlspecialchars($booking['status']);
                        $badgeClass = strtolower($booking['status']);
                    ?>
                    <div class="booking-card status-<?= $statusClass ?>">
                        <div class="card-top">
                            <div>
                                <div class="booking-id">#<?= htmlspecialchars($booking['booking_id']) ?></div>
                                <div class="booking-meta">
                                    <div class="customer-name"><?= htmlspecialchars($booking['customer_name']) ?></div>
                                    <div class="hotel-name"><?= htmlspecialchars($booking['nama_hotel']) ?></div>
                                    <div class="room-type"><?= htmlspecialchars($booking['room_type_name'] ?? $booking['tipe_id'] ?? 'Standard Room') ?></div>
                                </div>
                            </div>
                            <div>
                                <span class="status-badge status-<?= $badgeClass ?>"><?= htmlspecialchars($booking['status']) ?></span>
                            </div>
                        </div>

                        <div class="check-block">
                            <div class="check-item">
                                <span class="label"><span class="material-icons">arrow_circle_right</span> CHECK-IN</span>
                                <span class="value"><?= date('d M Y', strtotime($booking['check_in'])) ?></span>
                            </div>
                            <div class="check-item">
                                <span class="label"><span class="material-icons">arrow_circle_left</span> CHECK-OUT</span>
                                <span class="value"><?= date('d M Y', strtotime($booking['check_out'])) ?></span>
                            </div>
                        </div>

                        <div class="mini-row">
                            <div class="mini-card">
                                <div class="label">KAMAR</div>
                                <div class="value"><?= (int)$booking['jumlah_kamar'] ?></div>
                            </div>
                            <div class="mini-card">
                                <div class="label">TANGGAL BOOKING</div>
                                <div class="value"><?= date('d M Y', strtotime($booking['tanggal_booking'])) ?></div>
                            </div>
                        </div>

                        <div class="card-bottom">
                            <?php 
                            $gross_price = (float)$booking['total_harga'];
                            $admin_fee_amount = $gross_price * 0.05;
                            $net_price = $gross_price - $admin_fee_amount;
                            ?>
                            <div class="total-price">
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <div style="font-size: 1.1rem; font-weight: 700; color: #1f6feb;">
                                        Rp <?= number_format($net_price, 0, ',', '.') ?>
                                    </div>
                                    <div style="font-size: 0.7rem; color: #6b7280;">
                                        Net (Gross: Rp <?= number_format($gross_price, 0, ',', '.') ?> - Admin 5%)
                                    </div>
                                </div>
                            </div>
                            <div class="action-buttons">
                                <button title="Update status" onclick="showStatusModal('<?= htmlspecialchars($booking['booking_id']) ?>', '<?= htmlspecialchars($booking['status']) ?>')">
                                    <span class="material-icons">edit</span>
                                </button>
                                <button class="action-eye" title="View" onclick="viewBookingDetails('<?= htmlspecialchars($booking['booking_id']) ?>')">
                                    <span class="material-icons">visibility</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Status Update Modal -->
        <div id="statusModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Update Booking Status</h2>
                    <button class="close-btn" onclick="closeStatusModal()">
                        <span class="material-icons">close</span>
                    </button>
                </div>
                <form method="post">
                    <input type="hidden" name="booking_id" id="statusBookingId">
                    <input type="hidden" name="update_booking_status" value="1">
                    
                    <div class="form-group">
                        <label for="status">New Status</label>
                        <select id="status" name="status" required>
                            <option value="Pending">Pending</option>
                            <option value="Confirmed">Confirmed</option>
                            <option value="Cancelled">Cancelled</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="cancel-btn" onclick="closeStatusModal()">Cancel</button>
                        <button type="submit" class="submit-btn">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
        <!-- Modal View Detail -->
        <div id="viewDetailModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Booking Details</h2>
                    <button class="close-modal" onclick="closeViewDetailModal()">
                        <span class="material-icons">close</span>
                    </button>
                </div>
                <div class="modal-body" id="viewDetailContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="close-modal" onclick="closeViewDetailModal()">Close</button>
                </div>
            </div>
        </div>
    </main>

    <script>
        const sidebar = document.getElementById('owner-sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        const mainContent = document.getElementById('main-content');
        
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        });

        function showStatusModal(bookingId, currentStatus) {
            const modal = document.getElementById('statusModal');
            document.getElementById('statusBookingId').value = bookingId;
            document.getElementById('status').value = currentStatus;
            modal.style.display = 'flex';
        }

        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }

        function viewBookingDetails(bookingId) {
            const modal = document.getElementById('viewDetailModal');
            const container = document.getElementById('viewDetailContent');
            if (!modal || !container) return;
            container.innerHTML = '<div style="padding:18px;text-align:center;color:#6b7280">Loading...</div>';
            modal.style.display = 'flex';
            fetch('booking_management.php?ajax_view_booking=1&booking_id=' + encodeURIComponent(bookingId))
                .then(res => res.text())
                .then(html => {
                    container.innerHTML = html;
                }).catch(err => {
                    container.innerHTML = '<div style="padding:18px;color:#d9534f">Error loading details</div>';
                });
        }

        function closeViewDetailModal() {
            const modal = document.getElementById('viewDetailModal');
            modal.style.display = 'none';
            document.getElementById('viewDetailContent').innerHTML = '';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const statusModal = document.getElementById('statusModal');
            const viewModal = document.getElementById('viewDetailModal');
            
            if (event.target === statusModal) {
                closeStatusModal();
            }
            
            if (event.target === viewModal) {
                closeViewDetailModal();
            }
        }
    </script>
</body>
</html>
