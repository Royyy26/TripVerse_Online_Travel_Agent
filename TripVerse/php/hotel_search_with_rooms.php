<?php
session_start();
require 'connect.php';

// Get search parameters
$city = $_GET['city'] ?? '';
$check_in = $_GET['check_in'] ?? '';
$check_out = $_GET['check_out'] ?? '';
$guests = $_GET['guests'] ?? 1;

// Search hotels with available rooms
$hotels = [];
$search_conditions = [];
$search_params = [];
$param_types = '';

if ($city) {
    $search_conditions[] = "h.kota LIKE ?";
    $search_params[] = "%$city%";
    $param_types .= 's';
}

$guests_int = max(1, (int)$guests);
if ($guests_int > 1) {
    $search_conditions[] = "tk.kapasitas_standar >= ?";
    $search_params[] = $guests_int;
    $param_types .= 'i';
}

$sql = "SELECT DISTINCT h.*, 
        COUNT(jh.tipe_id) as total_rooms,
        MIN(jh.harga) as min_price,
        MAX(jh.harga) as max_price
        FROM hotel h 
        INNER JOIN jadwal_hotel jh ON jh.hotel_id = h.hotel_id
        INNER JOIN tipe_kamar tk ON tk.tipe_id = jh.tipe_id
        LEFT JOIN kamar k ON k.hotel_id = jh.hotel_id AND k.tipe_id = jh.tipe_id
        WHERE h.owner_id IS NOT NULL AND h.owner_id <> ''
          AND (k.status IS NULL OR k.status = 'Available')
          AND (jh.stok_total - jh.terbooking) > 0";

if (!empty($search_conditions)) {
    $sql .= " AND " . implode(" AND ", $search_conditions);
}

$sql .= " GROUP BY h.hotel_id 
          HAVING total_rooms > 0
          ORDER BY min_price ASC";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($search_params)) {
        $stmt->bind_param($param_types, ...$search_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $hotels[] = $row;
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Search - TripVerse</title>
    <link rel="stylesheet" href="../css/owner_dashboard.css?v=3.0.0">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .search-page {
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 50%, #ffcc02 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .search-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .search-header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(26, 35, 126, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(26, 35, 126, 0.2);
        }
        
        .search-title {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
        }
        
        .search-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-weight: 600;
            color: #1a237e;
            margin-bottom: 8px;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 12px 15px;
            border: 2px solid rgba(26, 35, 126, 0.2);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #1a237e;
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.1);
        }
        
        .search-btn {
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 167, 38, 0.3);
        }
        
        .hotels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
        }
        
        .hotel-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(26, 35, 126, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(26, 35, 126, 0.2);
            transition: all 0.3s ease;
        }
        
        .hotel-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(26, 35, 126, 0.2);
        }
        
        .hotel-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .hotel-name {
            font-size: 1.3rem;
            font-weight: 700;
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .hotel-location {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #1a237e;
            font-size: 1rem;
            margin-bottom: 15px;
        }
        
        .hotel-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
            background: rgba(26, 35, 126, 0.1);
            border-radius: 10px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 5px;
        }
        
        .stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .hotel-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-secondary {
            background: rgba(26, 35, 126, 0.1);
            color: #1a237e;
            border: 2px solid rgba(26, 35, 126, 0.2);
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary:hover,
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: #1a237e;
        }
        
        .no-results .material-icons {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="search-page">
        <div class="search-container">
            <div class="search-header">
                <h1 class="search-title">Find Your Perfect Hotel</h1>
                
                <form method="GET" class="search-filters">
                    <div class="filter-group">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city" value="<?= htmlspecialchars($city) ?>" placeholder="Enter city name">
                    </div>
                    
                    <div class="filter-group">
                        <label for="check_in">Check-in Date</label>
                        <input type="date" id="check_in" name="check_in" value="<?= htmlspecialchars($check_in) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="check_out">Check-out Date</label>
                        <input type="date" id="check_out" name="check_out" value="<?= htmlspecialchars($check_out) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="guests">Guests</label>
                        <select id="guests" name="guests">
                            <option value="1" <?= $guests == 1 ? 'selected' : '' ?>>1 Guest</option>
                            <option value="2" <?= $guests == 2 ? 'selected' : '' ?>>2 Guests</option>
                            <option value="3" <?= $guests == 3 ? 'selected' : '' ?>>3 Guests</option>
                            <option value="4" <?= $guests == 4 ? 'selected' : '' ?>>4+ Guests</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <button type="submit" class="search-btn">
                            <span class="material-icons">search</span>
                            Search Hotels
                        </button>
                    </div>
                </form>
            </div>
            
            <?php if (empty($hotels)): ?>
                <div class="no-results">
                    <span class="material-icons">hotel</span>
                    <h3>No hotels found</h3>
                    <p>Try adjusting your search criteria</p>
                </div>
            <?php else: ?>
                <div class="hotels-grid">
                    <?php foreach ($hotels as $hotel): ?>
                    <div class="hotel-card">
                        <div class="hotel-header">
                            <h3 class="hotel-name"><?= htmlspecialchars($hotel['nama_hotel']) ?></h3>
                        </div>
                        
                        <div class="hotel-location">
                            <span class="material-icons">location_on</span>
                            <span><?= htmlspecialchars($hotel['alamat']) ?>, <?= htmlspecialchars($hotel['kota']) ?></span>
                        </div>
                        
                        <div class="hotel-stats">
                            <div class="stat-item">
                                <div class="stat-label">Available Rooms</div>
                                <div class="stat-value"><?= $hotel['total_rooms'] ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-label">Price Range</div>
                                <div class="stat-value">Rp <?= number_format($hotel['min_price'], 0, ',', '.') ?> - <?= number_format($hotel['max_price'], 0, ',', '.') ?></div>
                            </div>
                        </div>
                        
                        <?php if ($hotel['info_hotel']): ?>
                            <p style="color: #666; margin-bottom: 20px; line-height: 1.5;">
                                <?= htmlspecialchars(substr($hotel['info_hotel'], 0, 150)) ?><?= strlen($hotel['info_hotel']) > 150 ? '...' : '' ?>
                            </p>
                        <?php endif; ?>
                        
                        <div class="hotel-actions">
                            <a href="hotel_rooms.php?hotel_id=<?= $hotel['hotel_id'] ?>&check_in=<?= $check_in ?>&check_out=<?= $check_out ?>&guests=<?= $guests ?>" class="btn-primary">
                                <span class="material-icons">bed</span>
                                View Rooms
                            </a>
                            <a href="hotel_detail.php?id=<?= $hotel['hotel_id'] ?>" class="btn-secondary">
                                <span class="material-icons">info</span>
                                Details
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
