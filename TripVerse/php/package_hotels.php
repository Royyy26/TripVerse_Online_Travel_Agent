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

// Handle hotel package submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nama_hotel'])) {
    // Process file upload
    $foto_hotel = '';
    if (isset($_FILES['foto_hotel']) && $_FILES['foto_hotel']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['foto_hotel']['tmp_name'];
        $namaFile = time() . '-' . basename($_FILES['foto_hotel']['name']);
        $uploadDir = '../img/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $targetPath = $uploadDir . $namaFile;

        if (move_uploaded_file($tmpName, $targetPath)) {
            $foto_hotel = $targetPath;
        }
    }

    // Get form data
    $nama_hotel     = $_POST['nama_hotel'];
    $alamat         = $_POST['alamat'];
    $kota           = $_POST['kota'];
    $harga_dasar    = $_POST['harga_dasar'];
    $info_hotel     = $_POST['info_hotel'];
    $maps_embed_url = $_POST['maps_embed_url'];

    // Process selected room types
    $selectedRoomTypes = isset($_POST['selected_room_types']) ? explode(',', $_POST['selected_room_types']) : [];
    $harga_kamar = isset($_POST['harga_kamar']) ? $_POST['harga_kamar'] : [];
    $stok_kamar = isset($_POST['stok_kamar']) ? $_POST['stok_kamar'] : [];

    // Process selected facilities
    $fasilitas = isset($_POST['fasilitas']) ? $_POST['fasilitas'] : [];

    // Generate hotel ID based on city
    $cityPrefixes = [
        'Jakarta' => 'JKT',
        'Bogor' => 'BGR',
        'Depok' => 'DPK',
        'Tangerang' => 'TGR',
        'Bekasi' => 'BKS' // Diubah dari BEK ke BKS untuk konsistensi dengan data contoh
    ];

    $prefix = $cityPrefixes[$kota] ?? 'HTL'; // Default prefix if city not found

    // Get the last ID for this city
    $resultId = $conn->prepare("SELECT hotel_id FROM hotel WHERE hotel_id LIKE CONCAT(?, '%') ORDER BY hotel_id DESC LIMIT 1");
    $resultId->bind_param("s", $prefix);
    $resultId->execute();
    $res = $resultId->get_result()->fetch_assoc();

    if ($res) {
        // Extract the numeric part and increment
        $lastId = $res['hotel_id'];
        $numericPart = substr($lastId, strlen($prefix));
        $newNumber = str_pad(intval($numericPart) + 1, 3, '0', STR_PAD_LEFT);
        $newId = $prefix . $newNumber;
    } else {
        // First hotel for this city
        $newId = $prefix . '001';
    }

    // Insert hotel data
    $insertHotel = $conn->prepare("INSERT INTO hotel 
        (hotel_id, nama_hotel, alamat, kota, foto_hotel, info_hotel, maps_embed_url, harga_dasar) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    $insertHotel->bind_param(
        "sssssssd",
        $newId,
        $nama_hotel,
        $alamat,
        $kota,
        $foto_hotel,
        $info_hotel,
        $maps_embed_url,
        $harga_dasar
    );

    if ($insertHotel->execute()) {
        // Insert room types only for selected ones
        foreach ($selectedRoomTypes as $tipe) {
            $harga = $harga_kamar[$tipe];
            $stok = $stok_kamar[$tipe];

            if ($harga > 0 && $stok > 0) {
                // Insert into jadwal_hotel
                $insertJadwal = $conn->prepare("INSERT INTO jadwal_hotel 
                    (hotel_id, tipe_id, harga, stok_total) 
                    VALUES (?, ?, ?, ?)");

                $insertJadwal->bind_param("ssdi", $newId, $tipe, $harga, $stok);
                $insertJadwal->execute();
                $insertJadwal->close();

                // Insert individual rooms
                for ($i = 0; $i < $stok; $i++) {
                    $insertKamar = $conn->prepare("INSERT INTO kamar 
                        (hotel_id, tipe_id, view, status) 
                        VALUES (?, ?, 'City', 'Available')");

                    $insertKamar->bind_param("ss", $newId, $tipe);
                    $insertKamar->execute();
                    $insertKamar->close();
                }
            }
        }

        // Insert facilities
        foreach ($fasilitas as $fasilitas_id) {
            $insertFasilitas = $conn->prepare("INSERT INTO hotel_fasilitas 
                (hotel_id, fasilitas_id) 
                VALUES (?, ?)");

            $insertFasilitas->bind_param("ss", $newId, $fasilitas_id);
            $insertFasilitas->execute();
            $insertFasilitas->close();
        }

        $_SESSION['success'] = "Hotel berhasil ditambahkan dengan ID: $newId!";
    } else {
        $_SESSION['error'] = "Gagal menambahkan hotel: " . $conn->error;
    }

    $insertHotel->close();
    header("Location: package_hotels.php");
    exit;
}

// Get all room types for the form
$roomTypes = [];
$result = $conn->query("SELECT * FROM tipe_kamar");
if ($result) {
    $roomTypes = $result->fetch_all(MYSQLI_ASSOC);
}

// Get all facilities for the form
$facilities = [];
$result = $conn->query("SELECT * FROM fasilitas_hotel");
if ($result) {
    $facilities = $result->fetch_all(MYSQLI_ASSOC);
}

$conn->close();
?>  

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Package Hotel | TripVerse</title>
    <link rel="stylesheet" href="../css/dashboard.css?v=1.2.3">
    <link rel="stylesheet" href="../css/formshotel.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
    <style>
        .user-dropdown {
            position: relative;
            margin-top: 10px;
        }

        .dropdown-content {
            position: absolute;
            background-color: white;
            min-width: 160px;
            box-shadow: 0px 8px 16px rgba(0, 0, 0, 0.1);
            border-radius: 4px;
            z-index: 1;
            display: none;
            left: 50%;
            transform: translateX(-50%);
        }

        .dropdown-content a {
            color: #333;
            padding: 10px 15px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dropdown-content a:hover {
            background-color: #f5f5f5;
        }

        .dropdown-content.show {
            display: block;
        }

        /* Main Container */
        .form-container {
            max-width: 1000px;
            margin: 20px auto;
            padding: 40px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 30px rgba(0, 0, 0, 0.08);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* Form Sections */
        .form-section {
            margin-bottom: 40px;
            padding-bottom: 25px;
            border-bottom: 1px solid #eaeaea;
        }

        /* Headings */
        .form-container h2 {
            color: #2c3e50;
            font-size: 28px;
            margin-bottom: 30px;
            text-align: center;
            font-weight: 600;
        }

        .form-section h3 {
            color: #3498db;
            font-size: 20px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
        }

        .form-section h3::before {
            content: "";
            display: inline-block;
            width: 6px;
            height: 20px;
            background: #3498db;
            margin-right: 10px;
            border-radius: 3px;
        }

        /* Room Type Selection */
        .room-type-selection {
            background: #f8fafc;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 1px solid #e2e8f0;
        }

        .room-type-options {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 20px 0;
        }

        .room-type-option {
            padding: 10px 20px;
            background: #edf2f7;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            user-select: none;
            font-size: 14px;
            font-weight: 500;
            color: #4a5568;
            border: 1px solid #e2e8f0;
        }

        .room-type-option:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .room-type-option.selected {
            background: #3498db;
            color: white;
            border-color: #3498db;
            box-shadow: 0 4px 6px rgba(50, 152, 219, 0.2);
        }

        .room-type-details {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            animation: fadeIn 0.3s ease-out;
        }

        .room-type-details h4 {
            color: #2d3748;
            margin-bottom: 15px;
            font-size: 16px;
            font-weight: 600;
        }

        .room-type-details.active {
            display: block;
        }

        /* Facilities */
        .facility-checkbox {
            display: inline-block;
            width: 220px;
            margin-bottom: 12px;
            padding: 8px;
            background: #f8fafc;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .facility-checkbox:hover {
            background: #edf2f7;
        }

        .facility-checkbox label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-size: 14px;
            color: #4a5568;
        }

        .facility-checkbox input[type="checkbox"] {
            margin-right: 10px;
            accent-color: #3498db;
            width: 16px;
            height: 16px;
        }

        /* Form Layout */
        .form-group {
            margin-bottom: 25px;
        }

        .form-row {
            display: flex;
            gap: 25px;
        }

        .form-row .form-group {
            flex: 1;
        }

        /* Labels */
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #4a5568;
            font-size: 14px;
        }

        /* Input Styles */
        input[type="text"],
        input[type="number"],
        input[type="url"],
        select,
        textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 15px;
            transition: all 0.3s;
            background: #f8fafc;
            color: #2d3748;
        }

        input[type="text"]:focus,
        input[type="number"]:focus,
        input[type="url"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
            background: white;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
            line-height: 1.5;
        }

        select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 16px;
        }

        /* File Input */
        input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px dashed #cbd5e0;
            border-radius: 6px;
            background: #f8fafc;
        }

        input[type="file"]::file-selector-button {
            padding: 8px 12px;
            background: #edf2f7;
            border: none;
            border-radius: 4px;
            margin-right: 12px;
            cursor: pointer;
            transition: background 0.2s;
        }

        input[type="file"]::file-selector-button:hover {
            background: #e2e8f0;
        }

        /* Image Preview */
        .preview-image {
            max-width: 100%;
            max-height: 250px;
            margin-top: 15px;
            display: none;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        /* Button Styles */
        .btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-block;
            box-shadow: 0 4px 6px rgba(50, 152, 219, 0.2);
        }

        .btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(50, 152, 219, 0.25);
        }

        .btn:active {
            transform: translateY(0);
        }

        /* Notification Styles */
        .notif {
            padding: 15px 20px;
            margin-bottom: 25px;
            border-radius: 6px;
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            animation: slideIn 0.3s ease-out;
        }

        .notif.success {
            background: #2ecc71;
        }

        .notif.error {
            background: #e74c3c;
        }

        .notif span.material-icons {
            margin-right: 10px;
        }

        /* Required field indicator */
        label[required]::after {
            content: " *";
            color: #e74c3c;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .form-container {
                padding: 25px;
            }

            .form-row {
                flex-direction: column;
                gap: 0;
            }

            .room-type-options {
                gap: 8px;
            }

            .facility-checkbox {
                width: 100%;
            }
        }

        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
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
                    <a href="package_hotels.php" class="active"><span class="material-icons">hotel</span><span>Add Package Hotel</span></a>
                    <a href="reporting_hotels.php"><span class="material-icons">hotel</span><span>Data Hotel</span></a>
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

        <div class="form-container">
            <h2>Tambah Paket Hotel</h2>

            <?php if (isset($_SESSION['success'])) : ?>
                <div class="notif success"><?= htmlspecialchars($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])) : ?>
                <div class="notif error"><?= htmlspecialchars($_SESSION['error']) ?></div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data">
                <!-- Basic Information Section -->
                <div class="form-section">
                    <h3>Informasi Dasar Hotel</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="nama_hotel">Nama Hotel*</label>
                            <input type="text" name="nama_hotel" id="nama_hotel" required>
                        </div>
                        <div class="form-group">
                            <label for="kota">Kota*</label>
                            <select name="kota" id="kota" required>
                                <option value="">-- Pilih Kota --</option>
                                <option value="Jakarta">Jakarta</option>
                                <option value="Bogor">Bogor</option>
                                <option value="Depok">Depok</option>
                                <option value="Tangerang">Tangerang</option>
                                <option value="Bekasi">Bekasi</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="alamat">Alamat Lengkap*</label>
                        <textarea name="alamat" id="alamat" rows="3" required></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="harga_dasar">Harga Dasar (Rp)*</label>
                            <input type="number" name="harga_dasar" id="harga_dasar" min="0" step="1000" required>
                        </div>
                        <div class="form-group">
                            <label for="foto_hotel">Foto Hotel*</label>
                            <input type="file" name="foto_hotel" id="foto_hotel" accept="image/*" required onchange="previewImage(this)">
                            <img id="foto_preview" class="preview-image" alt="Preview Gambar">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="info_hotel">Informasi Hotel*</label>
                        <textarea name="info_hotel" id="info_hotel" rows="4" required></textarea>
                    </div>
                </div>

                <!-- Location Information Section -->
                <div class="form-section">
                    <h3>Informasi Lokasi</h3>

                    <div class="form-row">

                        <div class="form-group">
                            <label for="maps_embed_url">Google Maps Embed URL*</label>
                            <input type="url" name="maps_embed_url" id="maps_embed_url" required>
                        </div>
                    </div>
                </div>

                <!-- Room Type Selection Section -->
                <div class="form-section room-type-selection">
                    <h3>Tipe Kamar</h3>
                    <p>Pilih tipe kamar yang tersedia di hotel ini:</p>

                    <div class="room-type-options">
                        <?php foreach ($roomTypes as $roomType): ?>
                            <div class="room-type-option"
                                data-type-id="<?= htmlspecialchars($roomType['tipe_id']) ?>"
                                onclick="toggleRoomType(this)">
                                <?= htmlspecialchars($roomType['nama_tipe']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <input type="hidden" name="selected_room_types" id="selected_room_types" value="">

                    <?php foreach ($roomTypes as $roomType): ?>
                        <div class="room-type-details" id="details-<?= htmlspecialchars($roomType['tipe_id']) ?>">
                            <h4><?= htmlspecialchars($roomType['nama_tipe']) ?></h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="harga_<?= htmlspecialchars($roomType['tipe_id']) ?>">Harga per Kamar (Rp)*</label>
                                    <input type="number" name="harga_kamar[<?= htmlspecialchars($roomType['tipe_id']) ?>]"
                                        id="harga_<?= htmlspecialchars($roomType['tipe_id']) ?>"
                                        min="0" step="1000" value="0">
                                </div>
                                <div class="form-group">
                                    <label for="stok_<?= htmlspecialchars($roomType['tipe_id']) ?>">Jumlah Kamar*</label>
                                    <input type="number" name="stok_kamar[<?= htmlspecialchars($roomType['tipe_id']) ?>]"
                                        id="stok_<?= htmlspecialchars($roomType['tipe_id']) ?>"
                                        min="0" value="0">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Facilities Section -->
                <div class="form-section">
                    <h3>Fasilitas Hotel</h3>
                    <p>Pilih fasilitas yang tersedia:</p>
                    <div id="facilities-container">
                        <?php foreach ($facilities as $facility): ?>
                            <div class="facility-checkbox">
                                <label>
                                    <input type="checkbox" name="fasilitas[]" value="<?= htmlspecialchars($facility['fasilitas_id']) ?>">
                                    <?= htmlspecialchars($facility['nama_fasilitas']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="form-group" style="text-align: center; margin-top: 30px;">
                    <button type="submit" class="btn">Simpan Paket Hotel</button>
                </div>
            </form>
        </div>
    </main>

    <script>
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

        // Profile photo upload
        document.querySelector('.profile-photo-container').addEventListener('click', () => {
            document.getElementById('profileUpload').click();
        });

        document.getElementById('profileUpload').addEventListener('change', () => {
            document.getElementById('uploadForm').submit();
        });

        // User dropdown toggle
        function toggleDropdown(button) {
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

        // User dropdown toggle
        function toggleDropdown(event, button) {
            event.stopPropagation(); // Perbaiki typo dari stopPropagation
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
        // Room type selection functionality
        function toggleRoomType(element) {
            element.classList.toggle('selected');
            const typeId = element.getAttribute('data-type-id');
            const detailsDiv = document.getElementById('details-' + typeId);
            detailsDiv.classList.toggle('active');

            updateSelectedRoomTypes();

            // If unselecting, reset the price and stock
            if (!element.classList.contains('selected')) {
                document.getElementById('harga_' + typeId).value = 0;
                document.getElementById('stok_' + typeId).value = 0;
            }
        }

        function updateSelectedRoomTypes() {
            const selectedTypes = [];
            document.querySelectorAll('.room-type-option.selected').forEach(el => {
                selectedTypes.push(el.getAttribute('data-type-id'));
            });
            document.getElementById('selected_room_types').value = selectedTypes.join(',');
        }

        // Image preview
        function previewImage(input) {
            const preview = document.getElementById('foto_preview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
            }
        }

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const selectedTypes = document.getElementById('selected_room_types').value;
            if (!selectedTypes) {
                e.preventDefault();
                alert('Pilih minimal satu tipe kamar untuk hotel ini');
                return false;
            }

            // Validate that selected room types have price and stock
            const selectedIds = selectedTypes.split(',');
            let valid = true;
            let errorMessage = '';

            selectedIds.forEach(id => {
                const price = parseFloat(document.getElementById('harga_' + id).value);
                const stock = parseInt(document.getElementById('stok_' + id).value);

                if (price <= 0) {
                    errorMessage += `- Harga untuk tipe kamar ${id} harus lebih dari 0\n`;
                    valid = false;
                    document.getElementById('harga_' + id).style.borderColor = 'red';
                } else {
                    document.getElementById('harga_' + id).style.borderColor = '';
                }

                if (stock <= 0) {
                    errorMessage += `- Stok untuk tipe kamar ${id} harus lebih dari 0\n`;
                    valid = false;
                    document.getElementById('stok_' + id).style.borderColor = 'red';
                } else {
                    document.getElementById('stok_' + id).style.borderColor = '';
                }
            });

            if (!valid) {
                e.preventDefault();
                alert('Harap perbaiki kesalahan berikut:\n\n' + errorMessage);
                return false;
            }

            return true;
        });

        // Initialize form
        document.addEventListener('DOMContentLoaded', function() {
            // Hide notifications after 5 seconds
            const notif = document.querySelector('.notif');
            if (notif) {
                setTimeout(() => {
                    notif.style.display = 'none';
                }, 5000);
            }

            // If repopulating form after error, you would add logic here
            // to mark previously selected room types as selected
            // Example:
            // const previouslySelected = ['TYPE1', 'TYPE2'];
            // previouslySelected.forEach(id => {
            //     const element = document.querySelector(`[data-type-id="${id}"]`);
            //     if (element) {
            //         element.classList.add('selected');
            //         document.getElementById('details-' + id).classList.add('active');
            //     }
            // });
            // updateSelectedRoomTypes();
        });

        // Sidebar toggle functionality
        document.getElementById('toggleSidebar').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
            document.getElementById('main-content').classList.toggle('expanded');
        });
    </script>
</body>

</html>