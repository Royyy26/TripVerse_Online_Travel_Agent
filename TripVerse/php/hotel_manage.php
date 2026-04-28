<?php
session_start();
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'owner'])) {
    echo "<script>alert('Akses ditolak!'); window.location='home.php';</script>";
    exit;
}

require 'connect.php';
require 'activity_log_helper.php';

// Ensure hotel table has owner_id column
$checkCol = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hotel' AND COLUMN_NAME = 'owner_id'");
if ($checkCol) {
    $res = $checkCol->fetch_row();
    if ($res && (int)$res[0] === 0) {
        $conn->query("ALTER TABLE hotel ADD COLUMN owner_id VARCHAR(50) NOT NULL AFTER hotel_id");
    }
}

$message = '';
$id_user = $_SESSION['id_user'];

// Handle hotel creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_hotel'])) {
    $nama_hotel = trim($_POST['nama_hotel'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $kota = trim($_POST['kota'] ?? '');
    $harga_dasar = (float)($_POST['harga_dasar'] ?? 0);
    $info_hotel = trim($_POST['info_hotel'] ?? '');
    $maps_embed_url = trim($_POST['maps_embed_url'] ?? '');

    if ($nama_hotel && $alamat && $kota && $harga_dasar > 0) {
        $city_map = [
            'bekasi' => 'BKS',
            'jakarta' => 'JKT',
            'depok' => 'DPK',
            'tangerang' => 'TGR',
            'tanggerang' => 'TGR',
            'bogor' => 'BGR'
        ];

        $prefix = 'HTL';
        $kota_lower = strtolower($kota);
        foreach ($city_map as $key => $pref) {
            if (strpos($kota_lower, $key) !== false) {
                $prefix = $pref;
                break;
            }
        }

        if ($prefix !== 'HTL') {
            // Find latest hotel_id with this prefix and increment the numeric part
            $latest_num = 0;
            $stmt2 = $conn->prepare("SELECT hotel_id FROM hotel WHERE hotel_id LIKE CONCAT(?, '%') ORDER BY hotel_id DESC LIMIT 1");
            if ($stmt2) {
                $stmt2->bind_param('s', $prefix);
                $stmt2->execute();
                $res2 = $stmt2->get_result();
                if ($res2 && $row2 = $res2->fetch_assoc()) {
                    if (preg_match('/(\d+)$/', $row2['hotel_id'], $m)) {
                        $latest_num = (int)$m[1];
                    }
                }
                $stmt2->close();
            }

            $next_num = $latest_num + 1;
            $hotel_id = $prefix . str_pad($next_num, 3, '0', STR_PAD_LEFT);
        } else {
            // Fallback to previous random HTL id when city not matched
            $hotel_id = 'HTL' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        }

        // Handle file upload
        $foto_hotel = '';
        if (isset($_FILES['foto_hotel']) && $_FILES['foto_hotel']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../img/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_extension = pathinfo($_FILES['foto_hotel']['name'], PATHINFO_EXTENSION);
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];

            if (in_array(strtolower($file_extension), $allowed_extensions)) {
                $foto_hotel = $hotel_id . '_' . time() . '.' . $file_extension;
                $target_path = $upload_dir . $foto_hotel;

                if (move_uploaded_file($_FILES['foto_hotel']['tmp_name'], $target_path)) {
                    // File uploaded successfully
                } else {
                    $message = "Error uploading file! Path: " . $target_path;
                    $foto_hotel = '';
                }
            } else {
                $message = "Format file tidak didukung! Hanya JPG, JPEG, PNG, WEBP.";
                $foto_hotel = '';
            }
        }

        if (!$message) {
            $stmt = $conn->prepare("INSERT INTO hotel (hotel_id, owner_id, nama_hotel, alamat, kota, harga_dasar, info_hotel, maps_embed_url, foto_hotel) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sssssdsss", $hotel_id, $id_user, $nama_hotel, $alamat, $kota, $harga_dasar, $info_hotel, $maps_embed_url, $foto_hotel);
                if ($stmt->execute()) {
                    $message = "Hotel '$nama_hotel' berhasil ditambahkan dengan ID: $hotel_id";
                    
                    // Handle facilities
                    if (isset($_POST['fasilitas']) && is_array($_POST['fasilitas'])) {
                        $insert_fac = $conn->prepare("INSERT INTO hotel_fasilitas (hotel_id, fasilitas_id) VALUES (?, ?)");
                        if ($insert_fac) {
                            foreach ($_POST['fasilitas'] as $fasilitas_id) {
                                $fasilitas_id = trim($fasilitas_id);
                                $insert_fac->bind_param("ss", $hotel_id, $fasilitas_id);
                                $insert_fac->execute();
                            }
                            $insert_fac->close();
                        }
                    }
                    
                    // Log activity
                    logActivity($conn, $id_user, 'add_hotel', "Added new hotel: $nama_hotel (ID: $hotel_id) in $kota", 'hotel', $hotel_id, $nama_hotel, $hotel_id);
                } else {
                    $message = "Error: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = "Error preparing statement: " . $conn->error;
            }
        }
    } else {
        $message = "Nama hotel, alamat, kota, dan harga dasar wajib diisi!";
    }
}

// Handle hotel update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_hotel'])) {
    $hotel_id = trim($_POST['hotel_id'] ?? '');
    $nama_hotel = trim($_POST['nama_hotel'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $kota = trim($_POST['kota'] ?? '');
    $harga_dasar = (float)($_POST['harga_dasar'] ?? 0);
    $info_hotel = trim($_POST['info_hotel'] ?? '');
    $maps_embed_url = trim($_POST['maps_embed_url'] ?? '');

    if ($hotel_id && $nama_hotel && $alamat && $kota && $harga_dasar > 0) {
        // Check ownership
        $ownCheck = $conn->prepare("SELECT 1 FROM hotel WHERE hotel_id = ? AND owner_id = ?");
        if ($ownCheck) {
            $ownCheck->bind_param("ss", $hotel_id, $id_user);
            $ownCheck->execute();
            $own = $ownCheck->get_result()->fetch_row();
            $ownCheck->close();

            if ($own) {
                // Handle file upload if new photo provided
                $foto_sql = "";
                $foto_hotel = null;
                if (isset($_FILES['foto_hotel']) && $_FILES['foto_hotel']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/../img/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    $file_extension = pathinfo($_FILES['foto_hotel']['name'], PATHINFO_EXTENSION);
                    $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];

                    if (in_array(strtolower($file_extension), $allowed_extensions)) {
                        $foto_hotel = $hotel_id . '_' . time() . '.' . $file_extension;
                        $target_path = $upload_dir . $foto_hotel;

                        if (move_uploaded_file($_FILES['foto_hotel']['tmp_name'], $target_path)) {
                            $foto_sql = ", foto_hotel = ?";
                        } else {
                            $message = "Error uploading file! Target path: " . $target_path;
                            $foto_hotel = null;
                        }
                    } else {
                        $message = "Format file tidak didukung! Hanya JPG, JPEG, PNG, WEBP.";
                        $foto_hotel = null;
                    }
                } else if (isset($_FILES['foto_hotel']) && $_FILES['foto_hotel']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $message = "Error dalam upload: " . $_FILES['foto_hotel']['error'];
                }

                if (!$message) {
                    $sql = "UPDATE hotel SET nama_hotel = ?, alamat = ?, kota = ?, harga_dasar = ?, info_hotel = ?, maps_embed_url = ?" . $foto_sql . " WHERE hotel_id = ? AND owner_id = ?";

                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        if ($foto_sql) {
                            // 9 parameters: nama_hotel(s), alamat(s), kota(s), harga_dasar(d), info_hotel(s), maps_embed_url(s), foto_hotel(s), hotel_id(s), id_user(s)
                            $stmt->bind_param("sssdsssss", $nama_hotel, $alamat, $kota, $harga_dasar, $info_hotel, $maps_embed_url, $foto_hotel, $hotel_id, $id_user);
                        } else {
                            // 8 parameters: nama_hotel(s), alamat(s), kota(s), harga_dasar(d), info_hotel(s), maps_embed_url(s), hotel_id(s), id_user(s)
                            $stmt->bind_param("sssdssss", $nama_hotel, $alamat, $kota, $harga_dasar, $info_hotel, $maps_embed_url, $hotel_id, $id_user);
                        }

                        if ($stmt->execute()) {
                            $message = "Hotel '$nama_hotel' berhasil diperbarui!";
                            
                            // Handle facilities update
                            if (isset($_POST['fasilitas']) && is_array($_POST['fasilitas'])) {
                                // Delete existing facilities for this hotel
                                $delete_stmt = $conn->prepare("DELETE FROM hotel_fasilitas WHERE hotel_id = ?");
                                if ($delete_stmt) {
                                    $delete_stmt->bind_param("s", $hotel_id);
                                    $delete_stmt->execute();
                                    $delete_stmt->close();
                                }
                                
                                // Insert new facilities
                                $insert_fac = $conn->prepare("INSERT INTO hotel_fasilitas (hotel_id, fasilitas_id) VALUES (?, ?)");
                                if ($insert_fac) {
                                    foreach ($_POST['fasilitas'] as $fasilitas_id) {
                                        $fasilitas_id = trim($fasilitas_id);
                                        $insert_fac->bind_param("ss", $hotel_id, $fasilitas_id);
                                        $insert_fac->execute();
                                    }
                                    $insert_fac->close();
                                }
                            }
                            
                            // Log activity
                            logActivity($conn, $id_user, 'edit_hotel', "Updated hotel: $nama_hotel (ID: $hotel_id)", 'hotel', $hotel_id, $nama_hotel, $hotel_id);
                        } else {
                            $message = "Error: " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
            } else {
                $message = "Error: Anda tidak memiliki akses ke hotel ini!";
            }
        }
    } else {
        $message = "Semua field wajib diisi!";
    }
}

// Get hotels owned by current user
$hotels_query = "SELECT * FROM hotel WHERE owner_id = ? ORDER BY hotel_id DESC";
$stmt = $conn->prepare($hotels_query);
$hotels = [];
if ($stmt) {
    $stmt->bind_param("s", $id_user);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Get facilities for this hotel
        $fac_query = $conn->prepare("SELECT f.fasilitas_id, f.nama_fasilitas FROM hotel_fasilitas hf 
                                    JOIN fasilitas_hotel f ON hf.fasilitas_id = f.fasilitas_id 
                                    WHERE hf.hotel_id = ? LIMIT 3");
        $row['facilities'] = [];
        if ($fac_query) {
            $fac_query->bind_param("s", $row['hotel_id']);
            $fac_query->execute();
            $fac_result = $fac_query->get_result();
            while ($fac_row = $fac_result->fetch_assoc()) {
                $row['facilities'][] = $fac_row;
            }
            $fac_query->close();
        }
        $hotels[] = $row;
    }
    $stmt->close();
}

// Get hotel for editing
$edit_hotel = null;
if (isset($_GET['edit']) && $_GET['edit']) {
    $edit_id = $_GET['edit'];
    $edit_stmt = $conn->prepare("SELECT * FROM hotel WHERE hotel_id = ? AND owner_id = ?");
    if ($edit_stmt) {
        $edit_stmt->bind_param("ss", $edit_id, $id_user);
        $edit_stmt->execute();
        $edit_result = $edit_stmt->get_result();
        if ($edit_result->num_rows > 0) {
            $edit_hotel = $edit_result->fetch_assoc();
        }
        $edit_stmt->close();
    }
}

$is_edit_mode = $edit_hotel !== null;

$facilities = [];
$facilities_query = $conn->query("SELECT fasilitas_id, nama_fasilitas FROM fasilitas_hotel ORDER BY nama_fasilitas");
if ($facilities_query) {
    while ($fac = $facilities_query->fetch_assoc()) {
        $facilities[] = $fac;
    }
}

$selected_facilities = [];
if ($is_edit_mode) {
    $selected_stmt = $conn->prepare("SELECT fasilitas_id FROM hotel_fasilitas WHERE hotel_id = ?");
    if ($selected_stmt) {
        $selected_stmt->bind_param("s", $edit_hotel['hotel_id']);
        $selected_stmt->execute();
        $selected_result = $selected_stmt->get_result();
        while ($row = $selected_result->fetch_assoc()) {
            $selected_facilities[] = $row['fasilitas_id'];
        }
        $selected_stmt->close();
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

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Management</title>
    <link rel="stylesheet" href="../css/owner_dashboard.css?v=3.0.0">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
         /* Main Layout */
        .management-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
            margin-right: 30px;
            margin-left: 30px;
        }

        /* Left Section - Add Hotel Form */
        .add-hotel-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(26, 35, 126, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(26, 35, 126, 0.2);
            height: fit-content;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 25px 0;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(26, 35, 126, 0.1);
        }

        .hotel-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: #1a237e;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group textarea {
            padding: 12px 15px;
            border: 2px solid rgba(26, 35, 126, 0.2);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #1a237e;
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
        }

        .form-group textarea {
            min-height: 80px;
            resize: vertical;
        }

        .facilities-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 12px;
            padding: 15px;
            background: rgba(26, 35, 126, 0.02);
            border: 2px solid rgba(26, 35, 126, 0.1);
            border-radius: 10px;
        }

        .facility-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            cursor: pointer;
            border: 2px solid rgba(26, 35, 126, 0.1);
            border-radius: 8px;
            background: white;
            transition: all 0.2s ease;
            font-size: 0.9rem;
            color: #333;
        }

        .facility-checkbox:hover {
            border-color: #1a237e;
            background: rgba(26, 35, 126, 0.02);
        }

        .facility-checkbox input[type="checkbox"] {
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: #1a237e;
        }

        .facility-checkbox input[type="checkbox"]:checked + span {
            color: #1a237e;
            font-weight: 600;
        }

        .file-upload-container {
            border: 2px dashed rgba(26, 35, 126, 0.3);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            background: rgba(26, 35, 126, 0.02);
        }

        .file-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .file-upload-text {
            color: #1a237e;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .file-upload-subtext {
            color: #666;
            font-size: 0.8rem;
        }

        .file-input {
            display: none;
        }

        .form-actions {
            margin-top: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            justify-content: center;
            pointer-events: auto;
            z-index: 10;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 167, 38, 0.3);
        }

        /* Right Section - My Hotels */
        .my-hotels-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(26, 35, 126, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(26, 35, 126, 0.2);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(26, 35, 126, 0.1);
        }

        .section-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
        }

        .add-hotel-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 100%);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .add-hotel-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 167, 38, 0.3);
        }

        .hotels-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
            max-height: none;
            overflow-y: auto;
        }

        .hotel-card {
            background: rgba(255, 255, 255, 0.9);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(26, 35, 126, 0.1);
            border: 1px solid rgba(26, 35, 126, 0.1);
            transition: all 0.3s ease;
        }

        .hotel-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(26, 35, 126, 0.15);
        }

        .hotel-card-layout {
            display: flex;
            gap: 0;
        }

        .hotel-image-wrapper {
            flex-shrink: 0;
            width: 180px;
            height: 220px;
            overflow: hidden;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }

        .hotel-thumbnail {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .hotel-card:hover .hotel-thumbnail {
            transform: scale(1.05);
        }

        .hotel-content-wrapper {
            flex: 1;
            padding: 20px 25px;
            display: flex;
            flex-direction: column;
        }

        .hotel-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .hotel-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1a237e;
            margin: 0 0 5px 0;
        }

        .hotel-id {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
        }

        .hotel-status {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-active {
            background: rgba(76, 175, 80, 0.1);
            color: #4caf50;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .hotel-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 15px 0;
            flex: 1;
        }

        .detail-item.full-width {
            grid-column: 1 / -1;
        }

        .detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
            font-size: 0.9rem;
        }

        .detail-item .material-icons {
            font-size: 1rem;
            color: #1a237e;
        }

        .facilities-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 12px;
        }

        .facility-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background: rgba(26, 35, 126, 0.08);
            border: 1px solid rgba(26, 35, 126, 0.15);
            border-radius: 15px;
            font-size: 0.75rem;
            color: #1a237e;
            font-weight: 500;
            white-space: nowrap;
        }

        .facility-badge .material-icons {
            font-size: 0.9rem;
            color: #1a237e;
        }

        .hotel-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: auto;
            padding-top: 15px;
            border-top: 1px solid rgba(26, 35, 126, 0.1);
        }

        .btn-small {
            padding: 10px 18px;
            font-size: 0.85rem;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            transition: all 0.3s ease;
            gap: 8px;
            display: inline-flex;
            align-items: center;
            pointer-events: auto;
            z-index: 10;
            font-weight: 600;
            min-width: 100px;
            justify-content: center;
        }

        .btn-edit {
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 100%);
            color: white;
            border: none;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 167, 38, 0.3);
        }

        .btn-secondary {
            background: rgba(26, 35, 126, 0.1);
            color: #1a237e;
            border: 1px solid rgba(26, 35, 126, 0.2);
        }

        .btn-secondary:hover {
            background: rgba(26, 35, 126, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(26, 35, 126, 0.15);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #1a237e;
        }

        .empty-state .material-icons {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #666;
            margin-bottom: 20px;
        }

        /* Notification */
        .notification {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .notification.success {
            background: rgba(76, 175, 80, 0.1);
            color: #4caf50;
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .notification.error {
            background: rgba(244, 67, 54, 0.1);
            color: #f44336;
            border: 1px solid rgba(244, 67, 54, 0.2);
        }

.notification.info {
    background: rgba(33, 150, 243, 0.1);
    color: #2196f3;
    border: 1px solid rgba(33, 150, 243, 0.2);
    display: flex;
    align-items: center;
    gap: 10px;
}

        /* Modal View Detail */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.5);
            backdrop-filter: blur(4px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            width: 100%;
            max-width: 900px;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(2, 6, 23, 0.3);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            animation: modalFadeIn 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            color: white;
            padding: 20px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 25px;
            overflow-y: auto;
            flex: 1;
        }

        .detail-hero {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 25px;
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 2px solid rgba(26, 35, 126, 0.1);
        }

        .detail-image {
            width: 100%;
            height: 250px;
            border-radius: 12px;
            object-fit: cover;
            box-shadow: 0 8px 20px rgba(26, 35, 126, 0.15);
        }

        .detail-main-info h3 {
            font-size: 1.5rem;
            color: #1a237e;
            margin: 0 0 8px 0;
        }

        .detail-hotel-id {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 15px;
        }

        .detail-quick-info {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .detail-info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: rgba(26, 35, 126, 0.05);
            border-radius: 8px;
        }

        .detail-info-item .material-icons {
            color: #1a237e;
            font-size: 1.2rem;
        }

        .detail-info-item span {
            color: #333;
            font-size: 0.95rem;
        }

        .detail-section {
            margin-bottom: 25px;
        }

        .detail-section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1a237e;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .detail-section-title .material-icons {
            background: rgba(26, 35, 126, 0.1);
            padding: 8px;
            border-radius: 8px;
            font-size: 1.3rem;
        }

        .detail-description {
            background: rgba(26, 35, 126, 0.03);
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid #1a237e;
            color: #333;
            line-height: 1.6;
        }

        .detail-facilities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }

        .detail-facility-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            background: white;
            border: 2px solid rgba(26, 35, 126, 0.1);
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .detail-facility-item:hover {
            border-color: #1a237e;
            background: rgba(26, 35, 126, 0.02);
            transform: translateY(-2px);
        }

        .detail-facility-item .material-icons {
            color: #1a237e;
            background: rgba(26, 35, 126, 0.1);
            padding: 8px;
            border-radius: 8px;
            font-size: 1.1rem;
        }

        .detail-map-container {
            width: 100%;
            height: 300px;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid rgba(26, 35, 126, 0.1);
        }

        .detail-map-container iframe {
            width: 100%;
            height: 100%;
            border: none;
        }

        .modal-footer {
            padding: 15px 25px;
            background: rgba(26, 35, 126, 0.03);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .management-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .hotel-details {
                grid-template-columns: 1fr;
            }

            .hotel-card-layout {
                flex-direction: column;
            }

            .hotel-image-wrapper {
                width: 100%;
                height: 200px;
            }

            .detail-hero {
                grid-template-columns: 1fr;
            }

            .detail-facilities-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .section-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .hotel-actions {
                flex-direction: column;
            }
            
            .add-hotel-section,
            .my-hotels-section {
                padding: 20px;
            }

            .btn-small {
                width: 100%;
            }
        }
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
            <a href="hotel_manage.php" class="nav-item active">
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
            <a href="booking_management.php" class="nav-item">
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
                <h1>Hotel Management</h1>
                <p class="header-subtitle">Manage your hotels and properties</p>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="notification <?= strpos($message, 'Error') !== false || strpos($message, 'error') !== false ? 'error' : 'success' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Main Management Container -->
        <div class="management-container">
            <!-- Left Section - Add / Edit Hotel Form -->
            <section class="add-hotel-section" id="hotelFormSection">
                <h2 class="section-title"><?= $is_edit_mode ? 'Edit Hotel' : 'Tambah Hotel Baru' ?></h2>
                <?php if ($is_edit_mode): ?>
                    <div class="notification info">
                        <span class="material-icons">info</span>
                        <span>Sedang mengedit hotel <strong><?= htmlspecialchars($edit_hotel['nama_hotel']) ?></strong>. Setelah selesai, klik "Batal" untuk kembali ke mode tambah.</span>
                    </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="hotel-form">
                    <?php if ($is_edit_mode): ?>
                        <input type="hidden" name="update_hotel" value="1">
                        <input type="hidden" name="hotel_id" value="<?= htmlspecialchars($edit_hotel['hotel_id']) ?>">
                    <?php else: ?>
                        <input type="hidden" name="add_hotel" value="1">
                    <?php endif; ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nama Hotel *</label>
                            <input type="text" name="nama_hotel" value="<?= htmlspecialchars($is_edit_mode ? $edit_hotel['nama_hotel'] : '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Kota *</label>
                            <input type="text" name="kota" value="<?= htmlspecialchars($is_edit_mode ? $edit_hotel['kota'] : '') ?>" required>
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label>Alamat *</label>
                        <textarea name="alamat" required><?= htmlspecialchars($is_edit_mode ? $edit_hotel['alamat'] : '') ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Harga Dasar *</label>
                            <input type="number" name="harga_dasar" step="0.01" min="0" value="<?= htmlspecialchars($is_edit_mode ? $edit_hotel['harga_dasar'] : '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Google Maps embed URL *</label>
                            <input type="url" name="maps_embed_url" value="<?= htmlspecialchars($is_edit_mode ? $edit_hotel['maps_embed_url'] : '') ?>" required placeholder="https://www.google.com/maps/embed/...">
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label>Deskripsi Hotel *</label>
                        <textarea name="info_hotel" required placeholder="Deskripsikan fasilitas dan keunggulan hotel..."><?= htmlspecialchars($is_edit_mode ? $edit_hotel['info_hotel'] : '') ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label>Fasilitas Hotel</label>
                        <div class="facilities-container">
                            <?php foreach ($facilities as $facility): ?>
                                <label class="facility-checkbox">
                                    <input type="checkbox" name="fasilitas[]" value="<?= htmlspecialchars($facility['fasilitas_id']) ?>" 
                                        <?= in_array($facility['fasilitas_id'], $selected_facilities) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($facility['nama_fasilitas']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label>Foto Hotel <?= $is_edit_mode ? '(opsional)' : '*' ?></label>
                        <div class="file-upload-container">
                            <label class="file-upload-label">
                                <input type="file" id="foto_hotel_input" name="foto_hotel" class="file-input" accept="image/*" <?= $is_edit_mode ? '' : 'required' ?>>
                                <span class="material-icons">cloud_upload</span>
                                <span class="file-upload-text">Choose a file or drag & drop it here</span>
                                <span class="file-upload-subtext">JPEG, PNG, WEBP formats, up to 50 MB</span>
                            </label>
                        </div>
                        <?php if ($is_edit_mode && !empty($edit_hotel['foto_hotel'])): ?>
                            <div style="margin-top:10px;display:flex;align-items:center;gap:12px;">
                                <div>
                                    <img id="current_foto_preview" src="../img/<?= htmlspecialchars($edit_hotel['foto_hotel']) ?>" alt="Current Photo" style="max-width:140px;border-radius:8px;border:1px solid rgba(26,35,126,0.08);">
                                </div>
                                <div style="font-size:0.85rem;color:#666;">File saat ini: <?= htmlspecialchars($edit_hotel['foto_hotel']) ?></div>
                            </div>
                        <?php else: ?>
                            <div id="current_foto_preview_container" style="margin-top:10px;display:none;"></div>
                        <?php endif; ?>
                        <div id="new_foto_preview" style="margin-top:10px;display:none;"></div>
                    </div>

                    <div class="form-actions">
                        <div style="display:flex;gap:10px;flex-wrap:wrap;">
                            <button type="submit" class="btn btn-primary" style="width:auto;">
                                <span class="material-icons"><?= $is_edit_mode ? 'save' : 'add' ?></span>
                                <?= $is_edit_mode ? 'Update Hotel' : 'Tambah Hotel' ?>
                            </button>
                            <?php if ($is_edit_mode): ?>
                                <a href="hotel_manage.php" class="btn btn-secondary" style="width:auto;">
                                    <span class="material-icons">close</span>
                                    Batal
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </section>

            <!-- Right Section - My Hotels -->
            <section class="my-hotels-section">
                <div class="section-header">
                    <h2>Hotel Saya</h2>
                    <button class="add-hotel-btn" onclick="scrollToForm()">
                        <span class="material-icons">add</span>
                        + Add Hotel
                    </button>
                </div>
                
                <div class="hotels-list">
                    <?php if (empty($hotels)): ?>
                        <div class="empty-state">
                            <span class="material-icons">hotel</span>
                            <h3>Belum ada hotel</h3>
                            <p>Mulai dengan menambahkan hotel pertama Anda</p>
                            <button class="add-hotel-btn" onclick="scrollToForm()">
                                <span class="material-icons">add</span>
                                Tambah Hotel Pertama
                            </button>
                        </div>
                        <?php else: ?>
                        <?php foreach ($hotels as $hotel): ?>
                            <div class="hotel-card">
                                <div class="hotel-card-layout">
                                    <?php 
                                    $hotel_img_path = '../img/default-hotel.svg';
                                    if (!empty($hotel['foto_hotel'])) {
                                        // Check if foto_hotel already contains path prefix
                                        $foto_manage = $hotel['foto_hotel'];
                                        if (strpos($foto_manage, '../img/') === 0 || strpos($foto_manage, 'img/') === 0) {
                                            // Path already included in database
                                            $img_file = __DIR__ . '/../' . ltrim($foto_manage, './');
                                        } else {
                                            // Just filename, add path
                                            $img_file = __DIR__ . '/../img/' . $foto_manage;
                                        }
                                        
                                        if (file_exists($img_file)) {
                                            // Return the correct relative path for browser
                                            if (strpos($foto_manage, '../img/') === 0) {
                                                $hotel_img_path = $foto_manage;
                                            } elseif (strpos($foto_manage, 'img/') === 0) {
                                                $hotel_img_path = '../' . $foto_manage;
                                            } else {
                                                $hotel_img_path = '../img/' . htmlspecialchars($foto_manage);
                                            }
                                        }
                                    }
                                    ?>
                                    
                                    <div class="hotel-image-wrapper">
                                        <img src="<?= $hotel_img_path ?>" alt="<?= htmlspecialchars($hotel['nama_hotel']) ?>" class="hotel-thumbnail">
                                    </div>
                                    
                                    <div class="hotel-content-wrapper">
                                        <div class="hotel-header">
                                            <div>
                                                <h3 class="hotel-name"><?= htmlspecialchars($hotel['nama_hotel']) ?></h3>
                                                <div class="hotel-id">ID: <?= htmlspecialchars($hotel['hotel_id']) ?></div>
                                            </div>
                                            <div class="hotel-status status-active">Active</div>
                                        </div>
                                        
                                        <div class="hotel-details">
                                    <div class="detail-item">
                                        <span class="material-icons">location_on</span>
                                        <span><?= htmlspecialchars($hotel['kota']) ?></span>
                                    </div>
                                    
                                    <div class="detail-item">
                                        <span class="material-icons">attach_money</span>
                                        <span>Rp <?= number_format($hotel['harga_dasar'], 0, ',', '.') ?></span>
                                    </div>
                                    
                                    <?php if ($hotel['info_hotel']): ?>
                                        <div class="detail-item full-width">
                                            <span class="material-icons">info</span>
                                            <span><?= htmlspecialchars(substr($hotel['info_hotel'], 0, 100)) ?><?= strlen($hotel['info_hotel']) > 100 ? '...' : '' ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($hotel['facilities'])): ?>
                                        <div class="detail-item full-width" style="margin-top: 8px;">
                                            <span class="material-icons" style="margin-right: 4px;">star</span>
                                            <div class="facilities-badges">
                                                <?php foreach ($hotel['facilities'] as $fac): ?>
                                                    <span class="facility-badge">
                                                        <span><?= htmlspecialchars($fac['nama_fasilitas']) ?></span>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                        </div>
                                        
                                        <div class="hotel-actions">
                                    <a href="hotel_manage.php?edit=<?= htmlspecialchars($hotel['hotel_id']) ?>" class="btn btn-small btn-edit">
                                        <span class="material-icons">edit</span>
                                        <span>Edit</span>
                                    </a>
                                    <a href="room_management.php?hotel_id=<?= htmlspecialchars($hotel['hotel_id']) ?>" class="btn btn-small btn-secondary">
                                        <span class="material-icons">bed</span>
                                        <span>Rooms</span>
                                    </a>
                                    <button onclick="viewHotelDetail('<?= htmlspecialchars($hotel['hotel_id']) ?>')" class="btn btn-small btn-secondary">
                                        <span class="material-icons">visibility</span>
                                        <span>View</span>
                                    </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>

    <!-- Modal View Detail Hotel -->
    <div id="viewHotelModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <span class="material-icons">hotel</span>
                    Detail Hotel
                </h2>
                <button class="modal-close" onclick="closeHotelModal()">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body" id="hotelDetailContent">
                <div style="text-align: center; padding: 40px; color: #666;">
                    <span class="material-icons" style="font-size: 3rem; color: #1a237e;">hotel</span>
                    <p>Loading hotel details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeHotelModal()">
                    <span class="material-icons">close</span>
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        const sidebar = document.getElementById('owner-sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        const mainContent = document.getElementById('main-content');

        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        });

        function scrollToForm() {
            const formSection = document.querySelector('.add-hotel-section');
            formSection.scrollIntoView({ behavior: 'smooth' });
        }

        <?php if ($is_edit_mode): ?>
        // Scroll to form automatically when editing
        scrollToForm();
        <?php endif; ?>

        // Auto-hide notification after 5 seconds
        <?php if ($message): ?>
            setTimeout(() => {
                const notification = document.querySelector('.notification');
                if (notification) {
                    notification.style.display = 'none';
                }
                // Reload page after success update to show new photo
                <?php if (strpos($message, 'berhasil diperbarui') !== false): ?>
                    setTimeout(() => {
                        window.location.href = 'hotel_manage.php';
                    }, 1500);
                <?php endif; ?>
            }, 5000);
        <?php endif; ?>
        
        // Client-side image preview for foto_hotel input
        (function(){
            const input = document.getElementById('foto_hotel_input');
            if (!input) return;

            const newPreview = document.getElementById('new_foto_preview');
            const currentPreview = document.getElementById('current_foto_preview');
            const currentContainer = document.getElementById('current_foto_preview_container');

            input.addEventListener('change', function(e){
                const file = input.files && input.files[0];
                if (!file) {
                    if (newPreview) newPreview.style.display = 'none';
                    return;
                }

                if (!file.type.startsWith('image/')) {
                    alert('Hanya file gambar yang diperbolehkan.');
                    input.value = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(ev){
                    const imgHtml = `<img src="${ev.target.result}" alt="Preview" style="max-width:140px;border-radius:8px;border:1px solid rgba(26,35,126,0.08);">`;
                    if (newPreview) {
                        newPreview.innerHTML = imgHtml;
                        newPreview.style.display = 'block';
                    }
                    if (currentPreview) {
                        currentPreview.style.opacity = '0.5';
                    }
                    if (currentContainer) {
                        currentContainer.style.display = 'none';
                    }
                };
                reader.readAsDataURL(file);
            });
        })();

        // Hotel Detail Modal Functions
        const hotelsData = <?= json_encode($hotels) ?>;

        function viewHotelDetail(hotelId) {
            const hotel = hotelsData.find(h => h.hotel_id === hotelId);
            if (!hotel) {
                alert('Hotel tidak ditemukan');
                return;
            }

            const modal = document.getElementById('viewHotelModal');
            const content = document.getElementById('hotelDetailContent');
            
            // Build hotel image path
            let hotelImgPath = '../img/default-hotel.svg';
            if (hotel.foto_hotel) {
                const fotoManage = hotel.foto_hotel;
                if (fotoManage.startsWith('../img/') || fotoManage.startsWith('img/')) {
                    hotelImgPath = fotoManage.startsWith('../') ? fotoManage : '../' + fotoManage;
                } else {
                    hotelImgPath = '../img/' + fotoManage;
                }
            }

            // Build facilities HTML
            let facilitiesHtml = '';
            if (hotel.facilities && hotel.facilities.length > 0) {
                facilitiesHtml = hotel.facilities.map(fac => `
                    <div class="detail-facility-item">
                        <span class="material-icons">check_circle</span>
                        <span>${escapeHtml(fac.nama_fasilitas)}</span>
                    </div>
                `).join('');
            } else {
                facilitiesHtml = '<p style="color: #666; font-style: italic;">Tidak ada fasilitas yang tersedia</p>';
            }

            // Build map embed
            let mapHtml = '';
            if (hotel.maps_embed_url) {
                mapHtml = `
                    <div class="detail-map-container">
                        <iframe src="${escapeHtml(hotel.maps_embed_url)}" allowfullscreen="" loading="lazy"></iframe>
                    </div>
                `;
            } else {
                mapHtml = '<p style="color: #666; font-style: italic;">Lokasi map tidak tersedia</p>';
            }

            content.innerHTML = `
                <div class="detail-hero">
                    <div>
                        <img src="${hotelImgPath}" alt="${escapeHtml(hotel.nama_hotel)}" class="detail-image">
                    </div>
                    <div class="detail-main-info">
                        <h3>${escapeHtml(hotel.nama_hotel)}</h3>
                        <div class="detail-hotel-id">ID: ${escapeHtml(hotel.hotel_id)}</div>
                        <div class="detail-quick-info">
                            <div class="detail-info-item">
                                <span class="material-icons">location_on</span>
                                <span>${escapeHtml(hotel.kota)}</span>
                            </div>
                            <div class="detail-info-item">
                                <span class="material-icons">home</span>
                                <span>${escapeHtml(hotel.alamat)}</span>
                            </div>
                            <div class="detail-info-item">
                                <span class="material-icons">attach_money</span>
                                <span>Rp ${formatNumber(hotel.harga_dasar)} / malam</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="detail-section">
                    <div class="detail-section-title">
                        <span class="material-icons">description</span>
                        Deskripsi Hotel
                    </div>
                    <div class="detail-description">
                        ${escapeHtml(hotel.info_hotel || 'Tidak ada deskripsi')}
                    </div>
                </div>

                <div class="detail-section">
                    <div class="detail-section-title">
                        <span class="material-icons">star</span>
                        Fasilitas Hotel
                    </div>
                    <div class="detail-facilities-grid">
                        ${facilitiesHtml}
                    </div>
                </div>

                <div class="detail-section">
                    <div class="detail-section-title">
                        <span class="material-icons">map</span>
                        Lokasi Hotel
                    </div>
                    ${mapHtml}
                </div>
            `;

            modal.classList.add('active');
        }

        function closeHotelModal() {
            const modal = document.getElementById('viewHotelModal');
            modal.classList.remove('active');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatNumber(num) {
            return parseFloat(num).toLocaleString('id-ID');
        }

        // Close modal when clicking outside
        document.getElementById('viewHotelModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeHotelModal();
            }
        });

        // Close modal with ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeHotelModal();
            }
        });
    </script>
</body>
</html>