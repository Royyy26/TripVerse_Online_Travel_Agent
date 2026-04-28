<?php
session_start();

if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

require 'connect.php';

$id_user = $_SESSION['id_user'];

// Ambil data user berdasarkan ID user (tanpa JOIN dengan admin)
$query = "SELECT 
            username,
            email,
            first_name,
            last_name,
            no_hp,
            gender,
            profile_picture
          FROM user
          WHERE id_user = ?";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    die("Error preparing query: " . $conn->error);
}

$stmt->bind_param("s", $id_user);
if (!$stmt->execute()) {
    die("Error executing query: " . $stmt->error);
}

$result = $stmt->get_result();

if ($data = $result->fetch_assoc()) {
    $username   = $data['username'];
    $email      = $data['email'];
    $firstName  = $data['first_name'];
    $lastName   = $data['last_name'];
    $mobile     = $data['no_hp'];
    $gender     = $data['gender'];
    $foto       = $data['profile_picture'] ?: 'images/default.jpg';
    $bio        = ''; // Tidak ada bio karena tabel admin dihapus
} else {
    $username = "Unknown";
    $email = "unknown@tripverse.com";
    $firstName = $lastName = $mobile = $gender = $bio = "-";
    $foto = "images/default.jpg";
}
$stmt->close();

// Handle hotel deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_hotel'])) {
    $hotel_id = $_POST['hotel_id'];

    // First delete related records in hotel_fasilitas
    $delete_facilities = $conn->prepare("DELETE FROM hotel_fasilitas WHERE hotel_id = ?");
    $delete_facilities->bind_param("s", $hotel_id);
    $delete_facilities->execute();
    $delete_facilities->close();

    // Then delete from jadwal_hotel
    $delete_schedule = $conn->prepare("DELETE FROM jadwal_hotel WHERE hotel_id = ?");
    $delete_schedule->bind_param("s", $hotel_id);
    $delete_schedule->execute();
    $delete_schedule->close();

    // Then delete from kamar
    $delete_rooms = $conn->prepare("DELETE FROM kamar WHERE hotel_id = ?");
    $delete_rooms->bind_param("s", $hotel_id);
    $delete_rooms->execute();
    $delete_rooms->close();

    // Finally delete the hotel
    $delete_hotel = $conn->prepare("DELETE FROM hotel WHERE hotel_id = ?");
    $delete_hotel->bind_param("s", $hotel_id);

    if ($delete_hotel->execute()) {
        $_SESSION['notification'] = "Hotel berhasil dihapus.";
    } else {
        $_SESSION['notification'] = "Gagal menghapus hotel: " . $conn->error;
    }
    $delete_hotel->close();

    header("Location: reporting_hotels.php");
    exit;
}

// Handle hotel update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_hotel'], $_POST['hotel_id'])) {
    $hotel_id = $_POST['hotel_id'];

    // Pastikan ada field yang diubah
    $hasChanges = false;
    $update_fields = [];
    $params = [];
    $types = '';

    $fields = [
        'nama_hotel' => 's',
        'alamat' => 's',
        'kota' => 's',
        'harga_dasar' => 'd',
        'info_hotel' => 's',
        'maps_embed_url' => 's',
    ];

    foreach ($fields as $field => $type) {
        if (isset($_POST[$field])) {
            $update_fields[] = "$field = ?";
            $params[] = $_POST[$field];
            $types .= $type;
            $hasChanges = true;
        }
    }

    // Handle file upload
    if (isset($_FILES['foto_hotel']) && $_FILES['foto_hotel']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['foto_hotel']['tmp_name'];
        $namaFile = time() . '-' . basename($_FILES['foto_hotel']['name']);
        $uploadDir = 'uploads/hotels/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $targetPath = $uploadDir . $namaFile;

        if (move_uploaded_file($tmpName, $targetPath)) {
            $update_fields[] = "foto_hotel = ?";
            $params[] = $targetPath;
            $types .= 's';
            $hasChanges = true;
        }
    }

    // Handle photo deletion
    if (!empty($_POST['delete_foto']) && $_POST['delete_foto'] == 1) {
        $update_fields[] = "foto_hotel = NULL";
        $hasChanges = true;
    }

    // Execute update if there are changes
    if ($hasChanges) {
        $update_query = "UPDATE hotel SET " . implode(', ', $update_fields) . " WHERE hotel_id = ?";
        $params[] = $hotel_id;
        $types .= 's';

        $stmt = $conn->prepare($update_query);
        if ($stmt === false) {
            $_SESSION['notification'] = "Error preparing statement: " . $conn->error;
            error_log("Update error: " . $conn->error); // Log error
        } else {
            // Debug: Log the query and parameters
            error_log("Update query: " . $update_query);
            error_log("Types: " . $types);
            error_log("Params: " . print_r($params, true));

            if ($stmt->bind_param($types, ...$params)) {
                if ($stmt->execute()) {
                    $_SESSION['notification'] = "Hotel berhasil diperbarui.";
                } else {
                    $_SESSION['notification'] = "Gagal memperbarui hotel: " . $stmt->error;
                    error_log("Execute error: " . $stmt->error);
                }
            } else {
                $_SESSION['notification'] = "Gagal binding parameter: " . $stmt->error;
                error_log("Bind param error: " . $stmt->error);
            }
            $stmt->close();
        }
    } else {
        $_SESSION['notification'] = "Tidak ada perubahan yang dilakukan.";
    }

    header("Location: reporting_hotels.php");
    exit;
}

// Handle new hotel creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_hotel'])) {
    $hotel_id = strtoupper(substr($_POST['kota'], 0, 3)) . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    $nama_hotel = $_POST['nama_hotel'];
    $alamat = $_POST['alamat'];
    $kota = $_POST['kota'];
    $harga_dasar = $_POST['harga_dasar'];
    $info_hotel = $_POST['info_hotel'] ?? '';
    $maps_embed_url = $_POST['maps_embed_url'] ?? '';

    $foto_hotel = null;
    if (isset($_FILES['foto_hotel']) && $_FILES['foto_hotel']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['foto_hotel']['tmp_name'];
        $namaFile = time() . '-' . basename($_FILES['foto_hotel']['name']);
        $uploadDir = 'uploads/hotels/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $targetPath = $uploadDir . $namaFile;

        if (move_uploaded_file($tmpName, $targetPath)) {
            $foto_hotel = $targetPath;
        }
    }

    $insert_query = "INSERT INTO hotel (hotel_id, nama_hotel, alamat, kota, foto_hotel, info_hotel, maps_embed_url, harga_dasar) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("ssssssssddd", $hotel_id, $nama_hotel, $alamat, $kota, $foto_hotel, $info_hotel, $maps_embed_url, $harga_dasar);

    if ($stmt->execute()) {
        $_SESSION['notification'] = "Hotel baru berhasil ditambahkan.";

        // Add default room types
        $room_types = ['DEL', 'ESU', 'JSU'];
        foreach ($room_types as $type) {
            // Add to jadwal_hotel
            $schedule_stmt = $conn->prepare("INSERT INTO jadwal_hotel (hotel_id, tipe_id, harga, stok_total, terbooking) 
                                           VALUES (?, ?, ?, 10, 0)");
            $default_price = $harga_dasar * ($type === 'DEL' ? 1 : ($type === 'ESU' ? 1.5 : 1.2));
            $schedule_stmt->bind_param("ssd", $hotel_id, $type, $default_price);
            $schedule_stmt->execute();
            $schedule_stmt->close();

            // Add to kamar
            $room_stmt = $conn->prepare("INSERT INTO kamar (hotel_id, tipe_id, view, status) 
                                       VALUES (?, ?, 'City', 'Available')");
            $room_stmt->bind_param("ss", $hotel_id, $type);
            $room_stmt->execute();
            $room_stmt->close();
        }
    } else {
        $_SESSION['notification'] = "Gagal menambahkan hotel: " . $stmt->error;
    }
    $stmt->close();

    header("Location: reporting_hotels.php");
    exit;
}

// Search and filter functionality
$search_query = $_GET['search'] ?? '';
$kota_filter = $_GET['kota'] ?? '';

// Build dynamic query
$whereClause = '';
$params = [];
$types = '';

if (!empty($search_query)) {
    $whereClause .= " (nama_hotel LIKE ? OR alamat LIKE ? OR kota LIKE ?) ";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $types .= 'sss';
}

if (!empty($kota_filter)) {
    if (!empty($whereClause)) {
        $whereClause .= " AND ";
    }
    $whereClause .= " kota = ? ";
    $params[] = $kota_filter;
    $types .= 's';
}

$sql = "SELECT hotel_id, nama_hotel, alamat, kota, foto_hotel, harga_dasar, info_hotel, 
            maps_embed_url
        FROM hotel";

if ($whereClause) {
    $sql .= " WHERE $whereClause";
}

$sql .= " ORDER BY kota, nama_hotel";

// Execute query
$stmt = $conn->prepare($sql);

if ($types) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$hotels = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get distinct cities for filter
$cities = [];
$city_query = "SELECT DISTINCT kota FROM hotel ORDER BY kota";
$city_result = $conn->query($city_query);
while ($row = $city_result->fetch_assoc()) {
    $cities[] = $row['kota'];
}
$city_result->close();

function getHotelRoomTypes($conn, $hotel_id)
{
    $query = "SELECT j.tipe_id, t.nama_tipe, j.harga, t.kapasitas_standar, 
                     t.ukuran_standar, j.stok_total, t.deskripsi
              FROM jadwal_hotel j
              JOIN tipe_kamar t ON j.tipe_id = t.tipe_id
              WHERE j.hotel_id = ?";

    // Debug: Tampilkan query dan parameter
    error_log("Executing query: " . $query);
    error_log("With hotel_id: " . $hotel_id);

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        // Catat error lengkap
        $error = $conn->error;
        error_log("Prepare failed: " . $error);
        throw new Exception("Database error: " . $error);
    }

    if (!$stmt->bind_param("s", $hotel_id)) {
        $error = $stmt->error;
        error_log("Bind param failed: " . $error);
        throw new Exception("Database error: " . $error);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        error_log("Execute failed: " . $error);
        throw new Exception("Database error: " . $error);
    }

    $result = $stmt->get_result();
    $roomTypes = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $roomTypes;
}
function getHotelFacilities($conn, $hotel_id)
{
    $query = "SELECT f.fasilitas_id, f.nama_fasilitas, f.icon
              FROM hotel_fasilitas hf
              JOIN fasilitas_hotel f ON hf.fasilitas_id = f.fasilitas_id
              WHERE hf.hotel_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $hotel_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $facilities = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $facilities;
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Reporting</title>
    <link rel="stylesheet" href="../css/booking.css?v=1.2.3">
    <link rel="stylesheet" href="../css/dashboard.css?v=1.2.3">
    <link rel="stylesheet" href="../css/profile.css?v=1.2.3">
    <link rel="stylesheet" href="../css/formshotel.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Global Styles */
        :root {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --success-color: #2ecc71;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --white: #ffffff;
            --gray: #95a5a6;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            margin: 0;
            padding: 0;
            color: #333;
        }

        /* Main Content */
        .main-content {
            padding: 20px;
            margin-left: 250px;
            transition: margin-left 0.3s;
        }

        .main-content.expanded {
            margin-left: 80px;
        }

        /* Header */
        .main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            margin-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }

        .menu-toggle {
            background: none;
            border: none;
            color: var(--dark-color);
            font-size: 24px;
            cursor: pointer;
        }

        /* Table Styles */
        .table-container {
            background-color: var(--white);
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.8em;
            letter-spacing: 0.5px;
        }

        tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }

        /* Button Styles */
        .btn {
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 0.85em;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-edit {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-edit:hover {
            background-color: var(--secondary-color);
        }

        .delete-btn {
            background-color: var(--danger-color);
            color: white;
        }

        .delete-btn:hover {
            background-color: #c0392b;
        }

        .btn-add {
            background-color: var(--success-color);
            color: white;
            padding: 10px 15px;
            font-weight: 500;
        }

        .btn-add:hover {
            background-color: #27ae60;
        }

        /* Search and Filter */
        .search-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            min-width: 200px;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9em;
        }

        .search-btn,
        .filter-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 10px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .search-btn:hover,
        .filter-btn:hover {
            background-color: var(--secondary-color);
        }

        .filter-dropdown {
            position: absolute;
            background: white;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            z-index: 100;
            margin-top: 5px;
            width: 250px;
        }

        .filter-dropdown label {
            display: block;
            margin-bottom: 8px;
            cursor: pointer;
        }

        .filter-dropdown button {
            margin-top: 10px;
            width: 100%;
        }

        /* Notification */
        .notification {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            border-left: 4px solid;
        }

        .success {
            background-color: rgba(46, 204, 113, 0.1);
            border-left-color: var(--success-color);
            color: var(--success-color);
        }

        .error {
            background-color: rgba(231, 76, 60, 0.1);
            border-left-color: var(--danger-color);
            color: var(--danger-color);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow-y: auto;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 2% auto;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            width: 90%;
            max-width: 800px;
            animation: modalopen 0.3s;
        }

        @keyframes modalopen {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .modal-title {
            font-size: 1.5em;
            color: var(--dark-color);
            margin: 0;
        }

        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: var(--dark-color);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--dark-color);
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9em;
            transition: border 0.3s;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            outline: none;
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-col {
            flex: 1;
        }

        /* Image Preview */
        .preview-image {
            max-width: 200px;
            max-height: 150px;
            margin-top: 10px;
            display: block;
            border-radius: 4px;
            border: 1px solid #eee;
        }

        /* Button Container */
        .btn-container {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .btn-save {
            background-color: var(--success-color);
            color: white;
        }

        .btn-save:hover {
            background-color: #27ae60;
        }

        .btn-cancel {
            background-color: var(--danger-color);
            color: white;
        }

        .btn-cancel:hover {
            background-color: #c0392b;
        }

        /* Responsive */
        @media screen and (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .main-content.expanded {
                margin-left: 0;
            }

            .form-row {
                flex-direction: column;
                gap: 0;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
                padding: 15px;
            }

            .search-container {
                flex-direction: column;
            }

            .search-input {
                width: 100%;
            }
        }

        /* Status Badges */
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 500;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-pending {
            background-color: #fef9e7;
            color: #f39c12;
        }

        .status-confirmed {
            background-color: #e8f8f5;
            color: #1abc9c;
        }

        .status-cancelled {
            background-color: #fdedec;
            color: #e74c3c;
        }

        .status-completed {
            background-color: #eaf2f8;
            color: #3498db;
        }

        /* Hotel Image Thumbnail */
        .hotel-thumbnail {
            width: 80px;
            height: 60px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #eee;
        }

        /* Price Format */
        .price {
            font-weight: 600;
            color: var(--dark-color);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
        }

        .details-dropdown {
            display: none;
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-top: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .details-dropdown.active {
            display: block;
        }

        .room-types-container {
            margin-bottom: 20px;
        }

        .room-type {
            background-color: white;
            border: 1px solid #eee;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 10px;
        }

        .room-type h4 {
            margin-top: 0;
            color: var(--primary-color);
        }

        .facilities-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .facility-item {
            background-color: white;
            border: 1px solid #eee;
            border-radius: 4px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .facility-item i {
            color: var(--primary-color);
        }

        .toggle-details {
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 5px 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .toggle-details:hover {
            background-color: var(--secondary-color);
        }

        .toggle-details i {
            transition: transform 0.3s;
        }

        .toggle-details.active i {
            transform: rotate(180deg);
        }

        .room-type-actions {
            margin-top: 10px;
        }

        .btn-edit,
        .delete-btn,
        .btn-add {
            padding: 5px 10px;
            margin-right: 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-edit {
            background-color: #4CAF50;
            color: white;
        }

        .delete-btn {
            background-color: #f44336;
            color: white;
        }

        .btn-add {
            background-color: #2196F3;
            color: white;
            margin-top: 10px;
            padding: 8px 15px;
        }

        .modal {
            display: block;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 50%;
            max-width: 500px;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 8px;
            box-sizing: border-box;
        }

        .btn-save {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .user-dropdown {
            position: relative;
            display: flex;
            justify-content: center;
            /* Ini yang membuat button berada di tengah */
            width: 100%;
        }

        .user-info {
            background: none;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            border-radius: 50%;
            transition: background-color 0.3s;
        }

        .user-info:hover {
            background-color: #f0f0f0;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            top: 100%;
            background-color: white;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            border-radius: 4px;
            z-index: 1;
        }

        .dropdown-content a {
            color: black;
            padding: 12px 16px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dropdown-content a:hover {
            background-color: #f1f1f1;
        }

        .user-dropdown.show .dropdown-content {
            display: block;
        }
    </style>
</head>

<body>
    <div class="sidebar" id="sidebar">
        <div class="profile-header">
            <div class="profile-photo-container" style="position:relative; cursor:pointer;">
                <img src="../uploads/<?php echo htmlspecialchars($foto); ?>" alt="Profile Photo" class="profile-photo" id="profilePhoto">
                <div class="profile-overlay">
                    <span class="material-icons">edit</span>
                </div>
                <form id="uploadForm" action="dashboard.php" method="POST" enctype="multipart/form-data" style="display:none;">
                    <input type="file" name="profile_photo" id="profileUpload" accept="image/*" />
                </form>
            </div>

            <h2><?= htmlspecialchars($firstName . ' ' . $lastName); ?></h2>
            <p><?php echo htmlspecialchars($email); ?></p>

            <div class="user-dropdown">
                <button class="user-info" aria-haspopup="true" aria-expanded="false" onclick="toggleDropdown(event, this)">
                    <span class="material-icons">expand_more</span>
                </button>
                <div class="dropdown-content" role="menu" aria-hidden="true">
                    <a href="profile.php"><span class="material-icons">person</span> Profile</a>
                    <a href="logout.php"><span class="material-icons">logout</span> Logout</a>
                </div>
            </div>
        </div>

        <nav>
            <a href="dashboard.php"><span class="material-icons">dashboard</span><span>Dashboard</span></a>

            <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                <a href="#" class="booking-toggle" data-target="bookingDropdown1">
                    <span class="material-icons">home</span>
                    <span>Package Hotel</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>
                <div class="booking-submenu hidden" id="bookingDropdown1" role="menu" aria-hidden="true">
                    <a href="package_hotels.php"><span class="material-icons">hotel</span><span>Add Package Hotel</span></a>
                    <a href="reporting_hotels.php" class="active"><span class="material-icons">hotel</span><span>Data Hotel</span></a>
                </div>
            </div>

            <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                <a href="#" class="booking-toggle" data-target="bookingDropdown2">
                    <span class="material-icons">event</span>
                    <span>Reporting</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>
                <div class="booking-submenu hidden" id="bookingDropdown2" role="menu" aria-hidden="true">
                    <a href="booking_transaction.php"><span class="material-icons">money</span><span>Booking Transaction</span></a>
                    <a href="revenue_report.php"><span class="material-icons">bar_chart</span><span>Revenue Report</span></a>
                </div>
            </div>

            <a href="logout.php"><span class="material-icons">logout</span><span>Logout</span></a>
        </nav>
    </div>

    <main class="main-content" id="main-content">
        <header class="main-header">
            <button id="toggleSidebar" class="menu-toggle" aria-label="Toggle sidebar">
                <span class="material-icons">menu</span>
            </button>

            <div class="header-actions">
                <div class="notification-bell" id="notificationBell" tabindex="0" aria-haspopup="true" aria-expanded="false" aria-label="Notifications">
                    <span class="material-icons bell-icon">notifications</span>
                    <span class="notification-badge" id="notificationCount">0</span>
                </div>

                <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                    <img src="../uploads/<?php echo htmlspecialchars($foto); ?>" alt="User Avatar" class="user-avatar" id="headerUserAvatar" />
                </div>
            </div>
        </header>

        <?php if (isset($_SESSION['upload_notification'])) : ?>
            <div class="notification-message <?php echo strpos($_SESSION['upload_notification'], 'Failed') === false ? 'success' : 'error'; ?>">
                <?php
                echo htmlspecialchars($_SESSION['upload_notification']);
                unset($_SESSION['upload_notification']);
                ?>
            </div>
        <?php endif; ?>

        <section>
            <h1>Hotel List (Filter Kota: <?= htmlspecialchars($kota_filter ?: 'Semua'); ?>)</h1>

            <?php if (isset($_SESSION['notification'])): ?>
                <div class="notification <?= strpos($_SESSION['notification'], 'Error') !== false ? 'error' : 'success' ?>">
                    <?= $_SESSION['notification'] ?>
                </div>
                <?php unset($_SESSION['notification']); ?>
            <?php endif; ?>

            <div class="search-container">
                <form method="GET" action="" class="search-form">
                    <input
                        type="text"
                        name="search"
                        placeholder="Search by name, location, or city..."
                        value="<?= htmlspecialchars($search_query); ?>"
                        class="search-input">

                    <button type="submit" class="search-btn">
                        <span class="material-icons">search</span>
                    </button>

                    <button type="button" id="filterToggle" class="filter-btn">
                        <span class="material-icons">filter_list</span>
                    </button>

                    <div id="filterDropdown" class="filter-dropdown hidden">
                        <form method="GET" action="">
                            <label>
                                <input type="radio" name="kota" value="" <?= empty($kota_filter) ? 'checked' : '' ?>> All
                            </label>
                            <?php foreach ($cities as $city): ?>
                                <label>
                                    <input type="radio" name="kota" value="<?= htmlspecialchars($city); ?>"
                                        <?= $kota_filter === $city ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($city); ?>
                                </label>
                            <?php endforeach; ?>
                            <button type="submit" class="apply-btn">Apply Filter</button>
                        </form>
                    </div>
                </form>

                <button onclick="window.location.href='package_hotels.php'" class="btn-add">
                    <i class="fas fa-plus"></i> Add New Hotel
                </button>
            </div>

            <div class="table-container">
                <table border="1" cellpadding="8" cellspacing="0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nama Hotel</th>
                            <th>Alamat</th>
                            <th>Kota</th>
                            <th>Foto</th>
                            <th>Harga Dasar</th>
                            <th>Info</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($hotels)): ?>
                            <tr>
                                <td colspan="8">Tidak ada data hotel.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($hotels as $hotel):
                                $roomTypes = getHotelRoomTypes($conn, $hotel['hotel_id']);
                                $facilities = getHotelFacilities($conn, $hotel['hotel_id']);
                            ?>
                                <tr id="row-<?= htmlspecialchars($hotel['hotel_id']); ?>">
                                    <td><?= htmlspecialchars($hotel['hotel_id']); ?></td>
                                    <td><?= htmlspecialchars($hotel['nama_hotel']); ?></td>
                                    <td><?= htmlspecialchars($hotel['alamat']); ?></td>
                                    <td><?= htmlspecialchars($hotel['kota']); ?></td>
                                    <td>
                                        <?php if (!empty($hotel['foto_hotel'])): ?>
                                            <img src="<?= htmlspecialchars($hotel['foto_hotel']); ?>" alt="Foto Hotel" class="hotel-thumbnail">
                                        <?php else: ?>
                                            Tidak ada gambar
                                        <?php endif; ?>
                                    </td>
                                    <td>Rp <?= number_format($hotel['harga_dasar'], 0, ',', '.'); ?></td>
                                    <td><?= htmlspecialchars(substr($hotel['info_hotel'], 0, 50)) . (strlen($hotel['info_hotel']) > 50 ? '...' : ''); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick='editHotel(<?= json_encode($hotel); ?>)' class="btn-edit">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button onclick='deleteHotel("<?= $hotel['hotel_id']; ?>")' class="delete-btn">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                            <button onclick='toggleDetails("<?= $hotel['hotel_id']; ?>")' class="toggle-details" id="toggle-<?= $hotel['hotel_id']; ?>">
                                                <i class="fas fa-chevron-down"></i> Detail
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="8" style="padding: 0;">
                                        <div class="details-dropdown" id="details-<?= $hotel['hotel_id']; ?>">
                                            <div class="room-types-container">
                                                <h3>Tipe Kamar</h3>
                                                <?php foreach ($roomTypes as $room): ?>
                                                    <div class="room-type">
                                                        <h4><?= htmlspecialchars($room['nama_tipe']); ?></h4>
                                                        <p><strong>Harga:</strong> Rp <?= number_format($room['harga'], 0, ',', '.'); ?></p>
                                                        <p><strong>Kapasitas:</strong> <?= htmlspecialchars($room['kapasitas_standar']); ?> orang</p>
                                                        <p><strong>Ukuran:</strong> <?= htmlspecialchars($room['ukuran_standar']); ?></p>
                                                        <p><strong>Stok:</strong> <?= htmlspecialchars($room['stok_total']); ?></p>
                                                        <div class="room-type-actions">
                                                            <button onclick='editRoomType(<?= json_encode($room); ?>, "<?= $hotel['hotel_id']; ?>")' class="btn-edit">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </button>
                                                            <button onclick='deleteRoomType("<?= $hotel['hotel_id']; ?>", "<?= $room['tipe_id']; ?>")' class="delete-btn">
                                                                <i class="fas fa-trash"></i> Hapus
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <button onclick='addRoomType("<?= $hotel['hotel_id']; ?>")' class="btn-add">
                                                    <i class="fas fa-plus"></i> Tambah Tipe Kamar
                                                </button>
                                            </div>

                                            <div class="facilities-container">
                                                <h3>Fasilitas Hotel</h3>
                                                <?php if (!empty($facilities)): ?>
                                                    <?php foreach ($facilities as $facility): ?>
                                                        <div class="facility-item">
                                                            <?php if ($facility['icon']): ?>
                                                                <i class="<?= htmlspecialchars($facility['icon']); ?>"></i>
                                                            <?php endif; ?>
                                                            <span><?= htmlspecialchars($facility['nama_fasilitas']); ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p>Tidak ada fasilitas yang tercatat</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Edit Modal -->
            <div id="editModal" class="modal" style="display: none;">
                <div class="modal-content">
                    <span class="close" onclick="closeEditModal()">&times;</span>
                    <h2>Edit Hotel</h2>
                    <form id="editHotelForm" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="hotel_id" id="edit_hotel_id">
                        <input type="hidden" name="update_hotel" value="1">

                        <div class="form-row">
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="edit_nama_hotel">Nama Hotel:</label>
                                    <input type="text" id="edit_nama_hotel" name="nama_hotel" class="form-control" required>
                                </div>

                                <div class="form-group">
                                    <label for="edit_alamat">Alamat:</label>
                                    <textarea id="edit_alamat" name="alamat" class="form-control" required></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="edit_kota">Kota:</label>
                                    <input type="text" id="edit_kota" name="kota" class="form-control" required>
                                </div>

                                <div class="form-group">
                                    <label for="edit_harga_dasar">Harga Dasar (Rp):</label>
                                    <input type="number" id="edit_harga_dasar" name="harga_dasar" class="form-control" required>
                                </div>
                            </div>

                            <div class="form-col">
                                <div class="form-group">
                                    <label for="edit_info_hotel">Info Hotel:</label>
                                    <textarea id="edit_info_hotel" name="info_hotel" class="form-control"></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="edit_maps_embed">Maps Embed URL:</label>
                                    <input type="text" id="edit_maps_embed" name="maps_embed_url" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="edit_foto">Ganti Foto:</label>
                            <input type="file" id="edit_foto" name="foto_hotel" accept="image/*" class="form-control">
                            <img id="preview_foto" src="" class="preview-image" style="display: none;">
                        </div>

                        <div class="form-group">
                            <input type="checkbox" id="delete_foto" name="delete_foto" value="1">
                            <label for="delete_foto">Hapus Foto</label>
                        </div>

                        <div class="btn-container">
                            <button type="button" onclick="closeEditModal()" class="btn-cancel">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="btn-save">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Add Modal -->
            <div id="addModal" class="modal" style="display: none;">
                <div class="modal-content">
                    <span class="close" onclick="closeAddModal()">&times;</span>
                    <h2>Add New Hotel</h2>
                    <form id="addHotelForm" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="create_hotel" value="1">

                        <div class="form-row">
                            <div class="form-col">
                                <div class="form-group">
                                    <label for="add_nama_hotel">Nama Hotel:</label>
                                    <input type="text" id="add_nama_hotel" name="nama_hotel" class="form-control" required>
                                </div>

                                <div class="form-group">
                                    <label for="add_alamat">Alamat:</label>
                                    <textarea id="add_alamat" name="alamat" class="form-control" required></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="add_kota">Kota:</label>
                                    <select id="add_kota" name="kota" class="form-control" required>
                                        <?php foreach ($cities as $city): ?>
                                            <option value="<?= htmlspecialchars($city); ?>"><?= htmlspecialchars($city); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="add_harga_dasar">Harga Dasar (Rp):</label>
                                    <input type="number" id="add_harga_dasar" name="harga_dasar" class="form-control" required>
                                </div>
                            </div>

                            <div class="form-col">
                                <div class="form-group">
                                    <label for="add_info_hotel">Info Hotel:</label>
                                    <textarea id="add_info_hotel" name="info_hotel" class="form-control"></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="add_maps">Google Maps URL:</label>
                                    <input type="text" id="add_maps" name="maps" class="form-control">
                                </div>

                                <div class="form-group">
                                    <label for="add_maps_embed">Maps Embed URL:</label>
                                    <input type="text" id="add_maps_embed" name="maps_embed_url" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="add_foto">Foto Hotel:</label>
                            <input type="file" id="add_foto" name="foto_hotel" accept="image/*" class="form-control">
                            <img id="add_preview_foto" src="" class="preview-image" style="display: none;">
                        </div>

                        <div class="btn-container">
                            <button type="button" onclick="closeAddModal()" class="btn-cancel">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="btn-save">
                                <i class="fas fa-save"></i> Add Hotel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>
    </main>

    <script>
        function editHotel(hotel) {
            const modal = document.getElementById('editModal');
            document.getElementById('edit_hotel_id').value = hotel.hotel_id;
            document.getElementById('edit_nama_hotel').value = hotel.nama_hotel;
            document.getElementById('edit_alamat').value = hotel.alamat;
            document.getElementById('edit_kota').value = hotel.kota;
            document.getElementById('edit_harga_dasar').value = hotel.harga_dasar;
            document.getElementById('edit_info_hotel').value = hotel.info_hotel || '';
            document.getElementById('edit_maps_embed').value = hotel.maps_embed_url || '';

            const preview = document.getElementById('preview_foto');
            if (hotel.foto_hotel) {
                preview.src = hotel.foto_hotel;
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }

            document.getElementById('delete_foto').checked = false;
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function showAddModal() {
            document.getElementById('addModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function deleteHotel(hotelId) {
            if (confirm('Apakah Anda yakin ingin menghapus hotel ini?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'hotel_id';
                input.value = hotelId;
                form.appendChild(input);

                const deleteInput = document.createElement('input');
                deleteInput.type = 'hidden';
                deleteInput.name = 'delete_hotel';
                deleteInput.value = '1';
                form.appendChild(deleteInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        window.onclick = function(event) {
            const editModal = document.getElementById('editModal');
            const addModal = document.getElementById('addModal');

            if (event.target == editModal) {
                closeEditModal();
            }

            if (event.target == addModal) {
                closeAddModal();
            }
        }

        document.getElementById('filterToggle').addEventListener('click', function() {
            const dropdown = document.getElementById('filterDropdown');
            dropdown.classList.toggle('hidden');
        });
        // Toggle sidebar
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        const mainContent = document.getElementById('main-content');

        const sidebarState = localStorage.getItem('sidebarState');
        if (sidebarState === 'collapsed') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }

        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            localStorage.setItem('sidebarState', sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded');
        });

        // Profile photo upload
        document.querySelector('.profile-photo-container').addEventListener('click', () => {
            document.getElementById('profileUpload').click();
        });

        document.getElementById('profileUpload').addEventListener('change', () => {
            document.getElementById('uploadForm').submit();
        });

        // Dropdown menus
        document.querySelectorAll('.booking-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const parentMenu = toggle.closest('.user-menu');
                const dropdownId = toggle.getAttribute('data-target');
                const dropdown = document.getElementById(dropdownId);
                const isExpanded = parentMenu.getAttribute('aria-expanded') === 'true';

                // Close all other dropdowns
                document.querySelectorAll('.user-menu').forEach(menu => {
                    menu.setAttribute('aria-expanded', 'false');
                });
                document.querySelectorAll('.booking-submenu').forEach(sub => {
                    sub.classList.remove('show');
                    sub.classList.add('hidden');
                    sub.setAttribute('aria-hidden', 'true');
                });

                // Toggle current menu
                if (!isExpanded) {
                    parentMenu.setAttribute('aria-expanded', 'true');
                    dropdown.classList.remove('hidden');
                    dropdown.classList.add('show');
                    dropdown.setAttribute('aria-hidden', 'false');
                }
            });
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.user-menu')) {
                document.querySelectorAll('.user-menu').forEach(menu => {
                    menu.setAttribute('aria-expanded', 'false');
                });
                document.querySelectorAll('.booking-submenu').forEach(sub => {
                    sub.classList.remove('show');
                    sub.classList.add('hidden');
                    sub.setAttribute('aria-hidden', 'true');
                });
            }
        });
        // User dropdown toggle
        function toggleDropdown(event, button) {
            event.stopPropagation();
            const dropdown = button.nextElementSibling;
            const isExpanded = button.getAttribute('aria-expanded') === 'true';

            // Close all other dropdowns first
            document.querySelectorAll('.dropdown-content').forEach(d => {
                d.classList.remove('show');
                d.setAttribute('aria-hidden', 'true');
                d.previousElementSibling.setAttribute('aria-expanded', 'false');
            });

            // Toggle current dropdown
            if (!isExpanded) {
                dropdown.classList.add('show');
                button.setAttribute('aria-expanded', 'true');
                dropdown.setAttribute('aria-hidden', 'false');
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.user-dropdown')) {
                document.querySelectorAll('.dropdown-content').forEach(d => {
                    d.classList.remove('show');
                    d.setAttribute('aria-hidden', 'true');
                    d.previousElementSibling.setAttribute('aria-expanded', 'false');
                });
            }
        });

        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');

            if (sidebar.classList.contains('collapsed')) {
                localStorage.setItem('sidebarState', 'collapsed');
            } else {
                localStorage.setItem('sidebarState', 'expanded');
            }
        });

        document.getElementById('edit_foto').addEventListener('change', function(e) {
            const preview = document.getElementById('preview_foto');
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(this.files[0]);
            } else {
                preview.style.display = 'none';
            }
        });

        document.getElementById('add_foto').addEventListener('change', function(e) {
            const preview = document.getElementById('add_preview_foto');
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(this.files[0]);
            } else {
                preview.style.display = 'none';
            }
        });

        document.querySelectorAll('.booking-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                const target = this.getAttribute('data-target');
                const submenu = document.getElementById(target);
                const icon = this.querySelector('.toggle-icon');

                submenu.classList.toggle('hidden');
                if (submenu.classList.contains('hidden')) {
                    icon.textContent = 'expand_more';
                } else {
                    icon.textContent = 'expand_less';
                }
            });
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('#filterToggle') && !e.target.closest('#filterDropdown')) {
                document.getElementById('filterDropdown').classList.add('hidden');
            }
        });

        document.getElementById('addHotelForm')?.addEventListener('submit', function(e) {
            const hargaDasar = document.getElementById('add_harga_dasar').value;
            if (parseFloat(hargaDasar) <= 0) {
                alert('Harga dasar harus lebih besar dari 0');
                e.preventDefault();
            }
        });

        document.getElementById('editHotelForm').addEventListener('submit', function(e) {
            const hargaDasar = document.getElementById('edit_harga_dasar').value;
            if (parseFloat(hargaDasar) <= 0) {
                alert('Harga dasar harus lebih besar dari 0');
                e.preventDefault();
                return false;
            }
            return true;
        });

        function toggleDetails(hotelId) {
            const details = document.getElementById(`details-${hotelId}`);
            const toggleBtn = document.getElementById(`toggle-${hotelId}`);
            const icon = toggleBtn.querySelector('i');

            details.classList.toggle('active');
            toggleBtn.classList.toggle('active');

            // Scroll ke detail yang dibuka
            if (details.classList.contains('active')) {
                details.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
            }
        }

        function editRoomType(roomData, hotelId) {
            // Create a modal form for editing room type
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.innerHTML = `
        <div class="modal-content">
            <span class="close" onclick="this.parentElement.parentElement.remove()">&times;</span>
            <h3>Edit Tipe Kamar</h3>
            <form id="editRoomForm" onsubmit="updateRoomType(event, '${hotelId}')">
                <input type="hidden" name="hotel_id" value="${hotelId}">
                <input type="hidden" name="tipe_id" value="${roomData.tipe_id}">
                
                <div class="form-group">
                    <label>Harga:</label>
                    <input type="number" name="harga" value="${roomData.harga}" required>
                </div>
                
                <div class="form-group">
                    <label>Stok Total:</label>
                    <input type="number" name="stok_total" value="${roomData.stok_total}" required>
                </div>
                
                <button type="submit" class="btn-save">Simpan Perubahan</button>
            </form>
        </div>
    `;
            document.body.appendChild(modal);
        }

        function updateRoomType(event, hotelId) {
            event.preventDefault();
            const form = event.target;

            // Pastikan target adalah form
            if (form.tagName !== 'FORM') {
                console.error('Event target is not a form element');
                return;
            }

            const formData = new FormData(form);

            // Jika hotelId diperlukan, tambahkan ke formData
            if (hotelId) {
                formData.append('hotel_id', hotelId);
            }

            fetch('update.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                .then(async response => {
                    const data = await response.json();
                    if (!response.ok) {
                        throw new Error(data.message || 'Network response was not ok');
                    }
                    return data;
                })
                .then(data => {
                    if (data.success) {
                        alert('Tipe kamar berhasil diperbarui');
                        location.reload();
                    } else {
                        alert('Gagal memperbarui: ' + (data.message || 'Tidak ada pesan error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Terjadi kesalahan update room: ' + error);
                });
        }

        function deleteRoomType(hotelId, roomTypeId) {
            if (confirm('Apakah Anda yakin ingin menghapus tipe kamar ini?')) {
                fetch('delete.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            hotel_id: hotelId,
                            tipe_id: roomTypeId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Tipe kamar berhasil dihapus');
                            location.reload(); // Refresh to show changes
                        } else {
                            alert('Gagal menghapus: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Terjadi kesalahan delete room');
                    });
            }
        }

        function addRoomType(hotelId) {
            // Create a modal form for adding new room type
            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.innerHTML = `
        <div class="modal-content">
            <span class="close" onclick="this.parentElement.parentElement.remove()">&times;</span>
            <h3>Tambah Tipe Kamar Baru</h3>
            <form id="addRoomForm" onsubmit="createRoomType(event, '${hotelId}')">
                <input type="hidden" name="hotel_id" value="${hotelId}">
                
                <div class="form-group">
                    <label>Tipe Kamar:</label>
                    <select name="tipe_id" required>
                        <option value="DEL">Deluxe</option>
                        <option value="ESU">Executive Suite</option>
                        <option value="JSU">Junior Suite</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Harga:</label>
                    <input type="number" name="harga" required>
                </div>
                
                <div class="form-group">
                    <label>Stok Total:</label>
                    <input type="number" name="stok_total" required>
                </div>
                
                <button type="submit" class="btn-save">Tambah Kamar</button>
            </form>
        </div>
    `;
            document.body.appendChild(modal);
        }

        function createRoomType(event, hotelId) {
            event.preventDefault();
            const formData = new FormData(event.target);

            // Tambahkan hotel_id jika belum ada
            if (!formData.has('hotel_id')) {
                formData.append('hotel_id', hotelId);
            }

            fetch('add.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    // Cek jika response bukan JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        return response.text().then(text => {
                            throw new Error('Server mengembalikan non-JSON: ' + text.substring(0, 100));
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        alert('Tipe kamar berhasil ditambahkan');
                        location.reload();
                    } else {
                        alert('Gagal menambahkan: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Terjadi kesalahan: ' + error.message);
                });
        }
    </script>
</body>

</html>