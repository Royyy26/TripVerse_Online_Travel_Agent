<?php
session_start();
if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit;
}

require __DIR__ . '/../connect.php';

// Get parameters from URL
$hotel_id = $_GET['hotel_id'] ?? '';
$tipe_id = $_GET['tipe_id'] ?? '';
$room_type = $_GET['room_type'] ?? '';
$price = (float)($_GET['price'] ?? 0);
$check_in = $_GET['check_in'] ?? '';
$check_out = $_GET['check_out'] ?? '';
$guests = max(1, (int)($_GET['guests'] ?? 1));

$message = '';
$message_type = 'success';
$room_info = null;
$hotel_info = null;
$available_qty = 0;
$night_count = 0;

if ($check_in && $check_out) {
    try {
        $check_in_dt = new DateTime($check_in);
        $check_out_dt = new DateTime($check_out);
        $night_count = max(1, $check_out_dt->diff($check_in_dt)->days);
    } catch (Exception $e) {
        $night_count = 0;
    }
}

// Get room and hotel information
if ($hotel_id && $tipe_id) {
    $room_query = "
        SELECT h.hotel_id, h.nama_hotel, h.alamat, h.kota, h.maps_embed_url, h.info_hotel,
               jh.harga, jh.stok_total, jh.terbooking,
               tk.tipe_id, tk.nama_tipe, tk.deskripsi, tk.kapasitas_standar,
               COALESCE(k.view, 'City') AS view,
               COALESCE(k.status, 'Available') AS status
        FROM jadwal_hotel jh
        INNER JOIN hotel h ON h.hotel_id = jh.hotel_id
        INNER JOIN tipe_kamar tk ON tk.tipe_id = jh.tipe_id
        LEFT JOIN kamar k ON k.hotel_id = jh.hotel_id AND k.tipe_id = jh.tipe_id
        WHERE jh.hotel_id = ? AND jh.tipe_id = ?
        LIMIT 1";

    $room_stmt = $conn->prepare($room_query);
    if ($room_stmt) {
        $room_stmt->bind_param("ss", $hotel_id, $tipe_id);
        $room_stmt->execute();
        $result = $room_stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $room_info = $row;
            $hotel_info = $row;
            $price = (float)$row['harga'];
            $room_type = $row['nama_tipe'];
            $available_qty = max(0, (int)$row['stok_total'] - (int)$row['terbooking']);
        }
        $room_stmt->close();
    }
}

if (!$room_info) {
    $message = "Kamar tidak ditemukan atau tidak tersedia.";
    $message_type = 'error';
}

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_room']) && $room_info) {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_email = trim($_POST['customer_email'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $special_requests = trim($_POST['special_requests'] ?? '');
    
    if ($customer_name && $customer_email && $customer_phone && $check_in && $check_out) {
        try {
            $check_in_date = new DateTime($check_in);
            $check_out_date = new DateTime($check_out);
        } catch (Exception $e) {
            $check_in_date = null;
            $check_out_date = null;
        }

        if ($check_in_date && $check_out_date && $check_out_date > $check_in_date) {
            $nights = $check_out_date->diff($check_in_date)->days;
            $total_price = $price * $nights;
            $jumlah_kamar = 1;

            if ($available_qty < $jumlah_kamar) {
                $message = "Kamar tidak lagi tersedia.";
                $message_type = 'error';
            } else {
                // Ambil customer_id dari tabel customer
                $customer_result = [];
                $customer_stmt = $conn->prepare("SELECT customer_id FROM customer WHERE id_user = ?");
                if ($customer_stmt) {
                    $customer_stmt->bind_param("s", $_SESSION['id_user']);
                    $customer_stmt->execute();
                    $customer_result = $customer_stmt->get_result()->fetch_assoc();
                    $customer_stmt->close();
                }

                $customer_id = $customer_result['customer_id'] ?? null;

                if (!$customer_id) {
                    $message = "Profil customer tidak ditemukan.";
                    $message_type = 'error';
                } else {
                    $booking_id = 'BOOK' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                    $transaksi_id = 'TRX' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                    $transaksi_hotel_id = 'HTRX' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
                    $jadwal_id = $hotel_id . '-' . $tipe_id;

                    $conn->begin_transaction();

                    try {
                        // Insert booking
                        $booking_sql = "INSERT INTO booking_hotel (
                            booking_id, customer_id, customer_name,
                            jadwal_id, hotel_id, tipe_id,
                            check_in, check_out, jumlah_kamar,
                            total_harga, status, tanggal_booking,
                            catatan, metode_pembayaran
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?, 'QRIS'
                        )";

                        $booking_stmt = $conn->prepare($booking_sql);
                        if (!$booking_stmt) {
                            throw new Exception("Gagal mempersiapkan booking: " . $conn->error);
                        }

                        $booking_stmt->bind_param(
                            "ssssssssids",
                            $booking_id,
                            $customer_id,
                            $customer_name,
                            $jadwal_id,
                            $hotel_id,
                            $tipe_id,
                            $check_in,
                            $check_out,
                            $jumlah_kamar,
                            $total_price,
                            $special_requests
                        );

                        if (!$booking_stmt->execute()) {
                            throw new Exception("Gagal menyimpan booking: " . $booking_stmt->error);
                        }
                        $booking_stmt->close();

                        // Insert transaksi
                        $trans_sql = "INSERT INTO transaksi (id_transaksi, jenis_transaksi, tanggal_transaksi, total_harga, status_transaksi)
                                      VALUES (?, 'Hotel', NOW(), ?, 'Pending')";
                        $trans_stmt = $conn->prepare($trans_sql);
                        if (!$trans_stmt) {
                            throw new Exception("Gagal mempersiapkan transaksi: " . $conn->error);
                        }
                        $trans_stmt->bind_param("sd", $transaksi_id, $total_price);
                        if (!$trans_stmt->execute()) {
                            throw new Exception("Gagal menyimpan transaksi: " . $trans_stmt->error);
                        }
                        $trans_stmt->close();

                        // Insert transaksi_hotel
                        $trans_hotel_sql = "INSERT INTO transaksi_hotel (transaksi_id_hotel, id_transaksi, booking_id, status)
                                            VALUES (?, ?, ?, 'Pending')";
                        $trans_hotel_stmt = $conn->prepare($trans_hotel_sql);
                        if (!$trans_hotel_stmt) {
                            throw new Exception("Gagal mempersiapkan transaksi hotel: " . $conn->error);
                        }
                        $trans_hotel_stmt->bind_param("sss", $transaksi_hotel_id, $transaksi_id, $booking_id);
                        if (!$trans_hotel_stmt->execute()) {
                            throw new Exception("Gagal menyimpan transaksi hotel: " . $trans_hotel_stmt->error);
                        }
                        $trans_hotel_stmt->close();

                        // Update availability
                        $update_sql = "UPDATE jadwal_hotel 
                                       SET terbooking = terbooking + ? 
                                       WHERE hotel_id = ? AND tipe_id = ?";
                        $update_stmt = $conn->prepare($update_sql);
                        if (!$update_stmt) {
                            throw new Exception("Gagal mempersiapkan update ketersediaan: " . $conn->error);
                        }
                        $update_stmt->bind_param("iss", $jumlah_kamar, $hotel_id, $tipe_id);
                        if (!$update_stmt->execute()) {
                            throw new Exception("Gagal memperbarui ketersediaan kamar: " . $update_stmt->error);
                        }
                        $update_stmt->close();

                        $conn->commit();

                        $_SESSION['current_booking'] = [
                            'id' => $booking_id,
                            'amount' => $total_price,
                            'type' => 'hotel',
                            'customer_id' => $customer_id,
                            'transaksi_id' => $transaksi_id
                        ];
                        $_SESSION['booking_start_time'] = time();
                        $_SESSION['booking_id'] = $booking_id;

                        header("Location: payment.php?booking_id=" . urlencode($booking_id));
                        exit;
                    } catch (Exception $e) {
                        $conn->rollback();
                        $message = "Gagal melakukan booking: " . $e->getMessage();
                        $message_type = 'error';
                    }
                }
            }
        } else {
            $message = "Tanggal check-in/check-out tidak valid.";
            $message_type = 'error';
        }
    } else {
        $message = "Please fill in all required fields.";
        $message_type = 'error';
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Room - TripVerse</title>
    <link rel="stylesheet" href="../../css/owner_dashboard.css?v=2.0.0">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .booking-page {
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 50%, #ffcc02 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .booking-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .booking-header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(255, 107, 107, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 107, 107, 0.2);
        }
        
        .booking-title {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
        }
        
        .room-summary {
            background: rgba(255, 107, 107, 0.1);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
        }
        
        .room-type {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1a237e;
            margin-bottom: 10px;
        }
        
        .hotel-name {
            font-size: 1.1rem;
            color: #666;
            margin-bottom: 15px;
        }
        
        .booking-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .booking-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
        }
        
        .price-summary {
            background: rgba(255, 107, 107, 0.1);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }
        
        .price-label {
            color: #666;
            margin-bottom: 5px;
        }
        
        .price-amount {
            font-size: 1.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .booking-form {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(255, 107, 107, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 107, 107, 0.2);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #1a237e;
            margin-bottom: 8px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid rgba(255, 107, 107, 0.2);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .form-input:focus {
            outline: none;
            border-color: #1a237e;
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .submit-button {
            width: 100%;
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .submit-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.3);
        }
        
        .back-button {
            background: rgba(255, 255, 255, 0.9);
            color: #1a237e;
            border: 2px solid #1a237e;
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .back-button:hover {
            background: #1a237e;
            color: white;
            transform: translateY(-2px);
        }
        
        .notification {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .notification.success {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .notification.error {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
    </style>
</head>
<body>
    <div class="booking-page">
        <div class="booking-container">
            <a href="hotel_rooms.php?hotel_id=<?= $hotel_info['hotel_id'] ?? '' ?>" class="back-button">
                <span class="material-icons">arrow_back</span>
                Back to Rooms
            </a>
            
            <div class="booking-header">
                <h1 class="booking-title">Book Your Room</h1>
                
                <?php if ($room_info): ?>
                <div class="room-summary">
                    <div class="room-type"><?= htmlspecialchars($room_info['nama_tipe']) ?> (<?= htmlspecialchars($room_info['tipe_id']) ?>)</div>
                    <div class="hotel-name"><?= htmlspecialchars($hotel_info['nama_hotel']) ?></div>
                    
                    <div class="booking-details">
                        <div class="booking-detail">
                            <span class="material-icons">event</span>
                            <span>Check-in: <?= date('M d, Y', strtotime($check_in)) ?></span>
                        </div>
                        <div class="booking-detail">
                            <span class="material-icons">event</span>
                            <span>Check-out: <?= date('M d, Y', strtotime($check_out)) ?></span>
                        </div>
                        <div class="booking-detail">
                            <span class="material-icons">people</span>
                            <span>Guests: <?= $guests ?> person(s)</span>
                        </div>
                        <div class="booking-detail">
                            <span class="material-icons">nights_stay</span>
                            <span>Nights: <?= $night_count ?></span>
                        </div>
                    </div>
                    
                    <div class="price-summary">
                        <div class="price-label">Total Price</div>
                        <div class="price-amount">Rp <?= number_format($price * max(1, $night_count), 0, ',', '.') ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($message): ?>
                <div class="notification <?= $message_type === 'error' ? 'error' : 'success' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <div class="booking-form">
                <h2 style="color: #1a237e; margin-bottom: 20px;">Guest Information</h2>
                
                <form method="post">
                    <div class="form-group">
                        <label class="form-label" for="customer_name">Full Name *</label>
                        <input type="text" id="customer_name" name="customer_name" class="form-input" 
                               value="<?= htmlspecialchars($_SESSION['first_name'] ?? '') . ' ' . htmlspecialchars($_SESSION['last_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="customer_email">Email Address *</label>
                        <input type="email" id="customer_email" name="customer_email" class="form-input" 
                               value="<?= htmlspecialchars($_SESSION['email'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="customer_phone">Phone Number *</label>
                        <input type="tel" id="customer_phone" name="customer_phone" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="special_requests">Special Requests</label>
                        <textarea id="special_requests" name="special_requests" class="form-input form-textarea" 
                                  placeholder="Any special requests or notes for your stay..."></textarea>
                    </div>
                    
                    <button type="submit" name="book_room" class="submit-button">
                        <span class="material-icons">book_online</span>
                        Confirm Booking
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
