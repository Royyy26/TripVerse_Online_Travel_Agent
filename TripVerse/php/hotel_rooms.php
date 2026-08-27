<?php
session_start();
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

require 'connect.php';

$hotel_id = $_GET['hotel_id'] ?? '';
$check_in = $_GET['check_in'] ?? '';
$check_out = $_GET['check_out'] ?? '';
$guests = $_GET['guests'] ?? 1;

// Get hotel information
$hotel_info = null;
if ($hotel_id) {
    $hotel_query = "SELECT * FROM hotel WHERE hotel_id = ?";
    $hotel_stmt = $conn->prepare($hotel_query);
    if ($hotel_stmt) {
        $hotel_stmt->bind_param("s", $hotel_id);
        $hotel_stmt->execute();
        $hotel_info = $hotel_stmt->get_result()->fetch_assoc();
        $hotel_stmt->close();
    }
}

// Get available rooms for the hotel
$rooms = [];
if ($hotel_id && $hotel_info) {
    $guests = max(1, (int)$guests);
    $rooms_query = "
        SELECT jh.hotel_id, jh.tipe_id, jh.harga, jh.stok_total, jh.terbooking,
               tk.nama_tipe, tk.deskripsi, tk.kapasitas_standar,
               COALESCE(k.view, 'City') AS view,
               COALESCE(k.status, 'Available') AS status
        FROM jadwal_hotel jh
        INNER JOIN tipe_kamar tk ON tk.tipe_id = jh.tipe_id
        LEFT JOIN kamar k ON k.hotel_id = jh.hotel_id AND k.tipe_id = jh.tipe_id
        WHERE jh.hotel_id = ?
          AND tk.kapasitas_standar >= ?
          AND (k.status IS NULL OR k.status = 'Available')
          AND (jh.stok_total - jh.terbooking) > 0
        ORDER BY jh.harga ASC";

    $rooms_stmt = $conn->prepare($rooms_query);
    if ($rooms_stmt) {
        $rooms_stmt->bind_param("si", $hotel_id, $guests);
        $rooms_stmt->execute();
        $rooms_result = $rooms_stmt->get_result();
        while ($row = $rooms_result->fetch_assoc()) {
            $row['available_qty'] = max(0, (int)$row['stok_total'] - (int)$row['terbooking']);
            $rooms[] = $row;
        }
        $rooms_stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Rooms - TripVerse</title>
    <link rel="stylesheet" href="../css/owner_dashboard.css?v=2.0.0">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .rooms-page {
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 50%, #ffcc02 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .rooms-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .hotel-header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(255, 107, 107, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 107, 107, 0.2);
        }
        
        .hotel-title {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
        }
        
        .hotel-location {
            color: #1a237e;
            font-size: 1.1rem;
            margin-bottom: 20px;
        }
        
        .search-info {
            background: rgba(255, 107, 107, 0.1);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .search-info h3 {
            color: #1a237e;
            margin-bottom: 10px;
        }
        
        .search-details {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        
        .search-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #666;
        }
        
        .rooms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
        }
        
        .room-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(255, 107, 107, 0.15);
            border: 1px solid rgba(255, 107, 107, 0.2);
            backdrop-filter: blur(20px);
            transition: all 0.3s ease;
        }
        
        .room-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(255, 107, 107, 0.2);
        }
        
        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .room-type {
            font-size: 1.3rem;
            font-weight: 700;
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .room-price {
            font-size: 1.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .room-details {
            margin-bottom: 20px;
        }
        
        .room-capacity {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #1a237e;
            font-weight: 500;
            margin-bottom: 10px;
        }
        
        .room-description {
            color: #666;
            line-height: 1.5;
            margin-bottom: 15px;
        }
        
        .room-facilities {
            background: rgba(255, 107, 107, 0.1);
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .facilities-title {
            font-weight: 600;
            color: #1a237e;
            margin-bottom: 5px;
        }
        
        .facilities-list {
            color: #666;
            font-size: 0.9rem;
        }
        
        .book-button {
            width: 100%;
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 100%);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .book-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 167, 38, 0.3);
        }
        
        .no-rooms {
            text-align: center;
            padding: 60px 20px;
            color: #1a237e;
        }
        
        .no-rooms .material-icons {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
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
    </style>
</head>
<body>
    <div class="rooms-page">
        <div class="rooms-container">
            <a href="home.php" class="back-button">
                <span class="material-icons">arrow_back</span>
                Back to Hotels
            </a>
            
            <?php if ($hotel_info): ?>
            <div class="hotel-header">
                <h1 class="hotel-title"><?= htmlspecialchars($hotel_info['nama_hotel']) ?></h1>
                <p class="hotel-location">
                    <span class="material-icons">location_on</span>
                    <?= htmlspecialchars($hotel_info['alamat']) ?>, <?= htmlspecialchars($hotel_info['kota']) ?>
                </p>
                
                <div class="search-info">
                    <h3>Your Search Details</h3>
                    <div class="search-details">
                        <div class="search-detail">
                            <span class="material-icons">event</span>
                            <span>Check-in: <?= $check_in ? date('M d, Y', strtotime($check_in)) : 'Not specified' ?></span>
                        </div>
                        <div class="search-detail">
                            <span class="material-icons">event</span>
                            <span>Check-out: <?= $check_out ? date('M d, Y', strtotime($check_out)) : 'Not specified' ?></span>
                        </div>
                        <div class="search-detail">
                            <span class="material-icons">people</span>
                            <span>Guests: <?= $guests ?> person(s)</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($hotel_info && empty($rooms)): ?>
                <div class="no-rooms">
                    <span class="material-icons">bed</span>
                    <h3>No rooms available</h3>
                    <p>Sorry, there are no rooms available for your selected dates and guest count.</p>
                </div>
            <?php else: ?>
                <div class="rooms-grid">
                    <?php foreach ($rooms as $room): ?>
                    <div class="room-card">
                        <div class="room-header">
                            <h3 class="room-type"><?= htmlspecialchars($room['nama_tipe']) ?> (<?= htmlspecialchars($room['tipe_id']) ?>)</h3>
                            <div class="room-price">Rp <?= number_format($room['harga'], 0, ',', '.') ?>/night</div>
                        </div>
                        
                        <div class="room-details">
                            <div class="room-capacity">
                                <span class="material-icons">people</span>
                                <span>Capacity: <?= (int)$room['kapasitas_standar'] ?> person(s)</span>
                            </div>
                            
                            <div class="room-capacity">
                                <span class="material-icons">inventory</span>
                                <span>Available: <?= (int)$room['available_qty'] ?> of <?= (int)$room['stok_total'] ?></span>
                            </div>

                            <div class="room-capacity">
                                <span class="material-icons">visibility</span>
                                <span>View: <?= htmlspecialchars($room['view']) ?> | Status: <?= htmlspecialchars($room['status']) ?></span>
                            </div>

                            <?php if (!empty($room['deskripsi'])): ?>
                                <div class="room-description">
                                    <?= nl2br(htmlspecialchars($room['deskripsi'])) ?>
                                </div>
                            <?php endif; ?>
                            
                        </div>
                        
                        <button class="book-button" onclick="bookRoom('<?= htmlspecialchars($room['hotel_id']) ?>','<?= htmlspecialchars($room['tipe_id']) ?>','<?= addslashes($room['nama_tipe']) ?>', <?= (float)$room['harga'] ?>, <?= (int)$room['available_qty'] ?>)">
                            <span class="material-icons">book_online</span>
                            Book This Room
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php else: ?>
                <div class="no-rooms">
                    <span class="material-icons">hotel</span>
                    <h3>Hotel not found</h3>
                    <p>The hotel you're looking for doesn't exist or has been removed.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function bookRoom(hotelId, tipeId, roomType, price, available) {
            if (available <= 0) {
                alert('Maaf, kamar ini sudah penuh.');
                return;
            }

            const checkIn = '<?= $check_in ?>';
            const checkOut = '<?= $check_out ?>';
            const guests = <?= (int)$guests ?>;
            
            if (!checkIn || !checkOut) {
                alert('Please select check-in and check-out dates first.');
                return;
            }
            
            // Redirect to booking page with room details
            const bookingUrl = `booking_room.php?hotel_id=${encodeURIComponent(hotelId)}&tipe_id=${encodeURIComponent(tipeId)}&room_type=${encodeURIComponent(roomType)}&price=${price}&check_in=${checkIn}&check_out=${checkOut}&guests=${guests}`;
            window.location.href = bookingUrl;
        }
    </script>
</body>
</html>
