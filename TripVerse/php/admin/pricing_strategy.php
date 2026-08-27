<?php
session_start();

// Cek login dan role admin
if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Akses ditolak! Hanya admin.'); window.location='../home.php';</script>";
    exit;
}

require __DIR__ . '/../connect.php';
require_once __DIR__ . '/../_lang.php';

$id_user = $_SESSION['id_user'];

// Ambil data admin
$query = "SELECT username, email, first_name, last_name, no_hp, gender, profile_picture FROM user WHERE id_user = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $id_user);
$stmt->execute();
$result = $stmt->get_result();

if ($data = $result->fetch_assoc()) {
    $username   = $data['username'];
    $email      = $data['email'];
    $firstName  = $data['first_name'];
    $lastName   = $data['last_name'];
    $mobile     = $data['no_hp'];
    $gender     = $data['gender'];
    $foto       = $data['profile_picture'] ?: '../../images/default.jpg';
} else {
    $username = "Unknown";
    $email = "unknown@tripverse.com";
    $firstName = $lastName = $mobile = $gender = "-";
    $foto = "../../images/default.jpg";
}
$stmt->close();

// --- FILTER ANALISIS HARGA ---
$filter_year = $_GET['year'] ?? date('Y');
$filter_month = $_GET['month'] ?? date('m');
$filter_city = $_GET['city'] ?? 'all';
$filter_hotel = $_GET['hotel'] ?? 'all';
$filter_room_type = $_GET['room_type'] ?? 'all';

// Validasi
if (!is_numeric($filter_month) || $filter_month < 1 || $filter_month > 12) {
    $filter_month = date('m');
}
if (!is_numeric($filter_year) || $filter_year < 2020 || $filter_year > date('Y') + 1) {
    $filter_year = date('Y');
}

// Daftar tahun untuk filter
$years_query = "SELECT DISTINCT YEAR(tanggal_booking) as year FROM booking_hotel ORDER BY year DESC";
$years_result = $conn->query($years_query);
$available_years = [];
if ($years_result && $years_result->num_rows > 0) {
    while ($row = $years_result->fetch_assoc()) {
        $available_years[] = $row['year'];
    }
}

if (!in_array(date('Y'), $available_years)) {
    $available_years[] = date('Y');
    rsort($available_years);
}

// Daftar kota
$cities_query = "SELECT DISTINCT kota FROM hotel ORDER BY kota";
$cities_result = $conn->query($cities_query);
$available_cities = [];
if ($cities_result && $cities_result->num_rows > 0) {
    while ($row = $cities_result->fetch_assoc()) {
        $available_cities[] = $row['kota'];
    }
}

// Daftar hotel berdasarkan kota
$hotels_query = "SELECT hotel_id, nama_hotel FROM hotel";
if ($filter_city !== 'all') {
    $hotels_query .= " WHERE kota = ?";
}
$hotels_query .= " ORDER BY nama_hotel";

$available_hotels = [];
if ($filter_city !== 'all') {
    $stmt = $conn->prepare($hotels_query);
    $stmt->bind_param("s", $filter_city);
    $stmt->execute();
    $hotels_result = $stmt->get_result();
} else {
    $hotels_result = $conn->query($hotels_query);
}

if ($hotels_result && $hotels_result->num_rows > 0) {
    while ($row = $hotels_result->fetch_assoc()) {
        $available_hotels[$row['hotel_id']] = $row['nama_hotel'];
    }
}

// Daftar tipe kamar
$room_types_query = "SELECT tipe_id, nama_tipe FROM tipe_kamar ORDER BY nama_tipe";
$room_types_result = $conn->query($room_types_query);
$available_room_types = [];
if ($room_types_result && $room_types_result->num_rows > 0) {
    while ($row = $room_types_result->fetch_assoc()) {
        $available_room_types[$row['tipe_id']] = $row['nama_tipe'];
    }
}

// --- FUNGSI ANALISIS HARGA ---

// 1. Analisis Positioning Harga
function get_price_positioning_analysis($conn, $year, $month, $city = 'all', $hotel = 'all', $room_type = 'all')
{
    $query = "SELECT 
        h.hotel_id,
        h.nama_hotel,
        h.kota,
        t.tipe_id,
        t.nama_tipe,
        jh.harga as rack_rate,
        AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga / b.jumlah_kamar / GREATEST(DATEDIFF(b.check_out, b.check_in), 1) ELSE NULL END) as avg_achieved_rate,
        MIN(CASE WHEN b.status = 'Completed' THEN b.total_harga / b.jumlah_kamar / GREATEST(DATEDIFF(b.check_out, b.check_in), 1) ELSE NULL END) as min_achieved_rate,
        MAX(CASE WHEN b.status = 'Completed' THEN b.total_harga / b.jumlah_kamar / GREATEST(DATEDIFF(b.check_out, b.check_in), 1) ELSE NULL END) as max_achieved_rate,
        COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as successful_bookings,
        jh.stok_total as capacity,
        jh.terbooking as booked,
        (SELECT AVG(jh2.harga) FROM jadwal_hotel jh2 
         LEFT JOIN hotel h2 ON jh2.hotel_id = h2.hotel_id 
         WHERE h2.kota = h.kota AND jh2.tipe_id = t.tipe_id) as market_avg_price,
        (SELECT MIN(jh2.harga) FROM jadwal_hotel jh2 
         LEFT JOIN hotel h2 ON jh2.hotel_id = h2.hotel_id 
         WHERE h2.kota = h.kota AND jh2.tipe_id = t.tipe_id) as market_min_price,
        (SELECT MAX(jh2.harga) FROM jadwal_hotel jh2 
         LEFT JOIN hotel h2 ON jh2.hotel_id = h2.hotel_id 
         WHERE h2.kota = h.kota AND jh2.tipe_id = t.tipe_id) as market_max_price
        FROM jadwal_hotel jh
        LEFT JOIN hotel h ON jh.hotel_id = h.hotel_id
        LEFT JOIN tipe_kamar t ON jh.tipe_id = t.tipe_id
        LEFT JOIN booking_hotel b ON jh.hotel_id = b.hotel_id AND jh.tipe_id = b.tipe_id
            AND YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
        WHERE 1=1";

    $params = [$year, $month];
    $types = "ss";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    if ($hotel !== 'all') {
        $query .= " AND h.hotel_id = ?";
        $params[] = $hotel;
        $types .= "s";
    }

    if ($room_type !== 'all') {
        $query .= " AND t.tipe_id = ?";
        $params[] = $room_type;
        $types .= "s";
    }

    $query .= " GROUP BY h.hotel_id, h.nama_hotel, h.kota, t.tipe_id, t.nama_tipe, jh.harga, jh.stok_total, jh.terbooking
                ORDER BY h.kota, h.nama_hotel, t.nama_tipe";

    $stmt = $conn->prepare($query);
    $data = [];

    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            // Metrik Analisis Harga
            $row['pricing_efficiency'] = $row['rack_rate'] > 0 ?
                round(($row['avg_achieved_rate'] / $row['rack_rate']) * 100, 2) : 0;
            $row['price_position_vs_market'] = $row['market_avg_price'] > 0 ?
                round(($row['rack_rate'] / $row['market_avg_price']) * 100, 2) : 0;
            $row['discount_rate'] = $row['rack_rate'] > 0 ?
                round((($row['rack_rate'] - $row['avg_achieved_rate']) / $row['rack_rate']) * 100, 2) : 0;
            $row['price_range_ratio'] = $row['max_achieved_rate'] > 0 && $row['min_achieved_rate'] > 0 ?
                round(($row['max_achieved_rate'] - $row['min_achieved_rate']) / $row['avg_achieved_rate'] * 100, 2) : 0;
            $row['market_position'] = get_market_position($row['rack_rate'], $row['market_min_price'], $row['market_max_price']);
            $row['price_elasticity_score'] = calculate_price_elasticity($row['successful_bookings'], $row['rack_rate'], $row['avg_achieved_rate']);
            $row['optimal_price_suggestion'] = get_optimal_price_suggestion($row);
            $row['price_adjustment_recommendation'] = get_price_adjustment_recommendation($row);
            
            $data[] = $row;
        }
        $stmt->close();
    }
    return $data;
}

// 2. Analisis Kompetisi Harga
function get_price_competition_analysis($conn, $year, $month, $city = 'all')
{
    $query = "SELECT 
        h.kota,
        t.tipe_id,
        t.nama_tipe,
        COUNT(DISTINCT h.hotel_id) as hotel_count,
        AVG(jh.harga) as avg_rack_rate,
        MIN(jh.harga) as min_rack_rate,
        MAX(jh.harga) as max_rack_rate,
        STDDEV(jh.harga) as price_std_dev,
        AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga / b.jumlah_kamar / GREATEST(DATEDIFF(b.check_out, b.check_in), 1) ELSE NULL END) as avg_achieved_rate,
        COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as total_bookings,
        SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) as total_revenue
        FROM jadwal_hotel jh
        LEFT JOIN hotel h ON jh.hotel_id = h.hotel_id
        LEFT JOIN tipe_kamar t ON jh.tipe_id = t.tipe_id
        LEFT JOIN booking_hotel b ON jh.hotel_id = b.hotel_id AND jh.tipe_id = b.tipe_id
            AND YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
        WHERE 1=1";

    $params = [$year, $month];
    $types = "ss";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    $query .= " GROUP BY h.kota, t.tipe_id, t.nama_tipe
                HAVING hotel_count >= 1
                ORDER BY h.kota, avg_rack_rate DESC";

    $stmt = $conn->prepare($query);
    $data = [];

    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $row['price_range'] = $row['max_rack_rate'] - $row['min_rack_rate'];
            $row['price_variation_coefficient'] = $row['avg_rack_rate'] > 0 ?
                round(($row['price_std_dev'] / $row['avg_rack_rate']) * 100, 2) : 0;
            $row['market_concentration'] = calculate_market_concentration($row['hotel_count'], $row['price_std_dev']);
            $row['price_competition_level'] = get_competition_level($row['price_variation_coefficient'], $row['hotel_count']);
            $row['optimal_price_band'] = calculate_optimal_price_band($row);
            $row['competitive_strategy'] = get_competitive_price_strategy($row);
            
            $data[] = $row;
        }
        $stmt->close();
    }
    return $data;
}

// 3. Analisis Elasticity Harga
function get_price_elasticity_analysis($conn, $year, $month, $city = 'all', $hotel = 'all')
{
    $query = "SELECT 
        h.hotel_id,
        h.nama_hotel,
        h.kota,
        t.tipe_id,
        t.nama_tipe,
        CASE 
            WHEN jh.harga <= 500000 THEN 'Budget (<500k)'
            WHEN jh.harga <= 1000000 THEN 'Mid-Range (500k-1M)'
            WHEN jh.harga <= 2000000 THEN 'Premium (1M-2M)'
            ELSE 'Luxury (>2M)'
        END as price_segment,
        jh.harga as rack_rate,
        COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed_bookings,
        COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancelled_bookings,
        AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga / b.jumlah_kamar / GREATEST(DATEDIFF(b.check_out, b.check_in), 1) ELSE NULL END) as avg_achieved_rate,
        AVG(CASE WHEN b.status = 'Completed' THEN DATEDIFF(b.check_out, b.check_in) ELSE NULL END) as avg_stay_duration,
        SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) as segment_revenue
        FROM booking_hotel b
        LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
        LEFT JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
        LEFT JOIN jadwal_hotel jh ON b.hotel_id = jh.hotel_id AND b.tipe_id = jh.tipe_id
        WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
        AND b.status IN ('Completed', 'Cancelled')";

    $params = [$year, $month];
    $types = "ss";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    if ($hotel !== 'all') {
        $query .= " AND h.hotel_id = ?";
        $params[] = $hotel;
        $types .= "s";
    }

    $query .= " GROUP BY h.hotel_id, h.nama_hotel, h.kota, t.tipe_id, t.nama_tipe, 
                CASE 
                    WHEN jh.harga <= 500000 THEN 'Budget (<500k)'
                    WHEN jh.harga <= 1000000 THEN 'Mid-Range (500k-1M)'
                    WHEN jh.harga <= 2000000 THEN 'Premium (1M-2M)'
                    ELSE 'Luxury (>2M)'
                END, jh.harga
                HAVING completed_bookings >= 2
                ORDER BY price_segment, avg_achieved_rate DESC";

    $stmt = $conn->prepare($query);
    $data = [];

    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $row['cancellation_rate'] = ($row['completed_bookings'] + $row['cancelled_bookings']) > 0 ?
                round(($row['cancelled_bookings'] / ($row['completed_bookings'] + $row['cancelled_bookings'])) * 100, 2) : 0;
            $row['price_gap_percentage'] = $row['rack_rate'] > 0 ?
                round(($row['rack_rate'] - $row['avg_achieved_rate']) / $row['rack_rate'] * 100, 2) : 0;
            $row['revenue_per_booking'] = $row['completed_bookings'] > 0 ?
                round($row['segment_revenue'] / $row['completed_bookings'], 2) : 0;
            $row['price_sensitivity'] = calculate_price_sensitivity($row['cancellation_rate'], $row['price_gap_percentage']);
            $row['elasticity_category'] = get_elasticity_category($row['price_sensitivity']);
            $row['segment_performance'] = evaluate_segment_performance($row);
            $row['dynamic_pricing_recommendation'] = get_dynamic_pricing_recommendation($row);
            
            $data[] = $row;
        }
        $stmt->close();
    }
    return $data;
}

// 4. Analisis Seasonal Pricing
function get_seasonal_pricing_analysis($conn, $year, $city = 'all')
{
    $query = "SELECT 
        MONTH(b.tanggal_booking) as month_number,
        MONTHNAME(b.tanggal_booking) as month_name,
        AVG(jh.harga) as avg_rack_rate_monthly,
        AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga / b.jumlah_kamar / GREATEST(DATEDIFF(b.check_out, b.check_in), 1) ELSE NULL END) as avg_achieved_rate_monthly,
        COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed_bookings_monthly,
        COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancelled_bookings_monthly,
        AVG(CASE WHEN b.status = 'Completed' THEN DATEDIFF(b.check_out, b.check_in) ELSE NULL END) as avg_stay_duration_monthly,
        (SELECT AVG(jh2.harga) FROM jadwal_hotel jh2 
         LEFT JOIN hotel h2 ON jh2.hotel_id = h2.hotel_id 
         WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = m.month_number" . ($city !== 'all' ? " AND h2.kota = ?" : "") . ") as market_avg_monthly
        FROM booking_hotel b
        LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
        LEFT JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
        LEFT JOIN jadwal_hotel jh ON b.hotel_id = jh.hotel_id AND b.tipe_id = jh.tipe_id
        CROSS JOIN (SELECT DISTINCT MONTH(tanggal_booking) as month_number FROM booking_hotel WHERE YEAR(tanggal_booking) = ?) m
        WHERE YEAR(b.tanggal_booking) = ?";

    $params = [$year];
    $types = "s";
    
    if ($city !== 'all') {
        $params[] = $city;
        $types .= "s";
    }
    
    $params[] = $year;
    $params[] = $year;
    $types .= "ss";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    $query .= " GROUP BY MONTH(b.tanggal_booking), MONTHNAME(b.tanggal_booking), m.month_number
                ORDER BY month_number";

    $stmt = $conn->prepare($query);
    $data = [];

    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $previous_month_data = null;
        while ($row = $result->fetch_assoc()) {
            $row['seasonal_discount_rate'] = $row['avg_rack_rate_monthly'] > 0 ?
                round(($row['avg_rack_rate_monthly'] - $row['avg_achieved_rate_monthly']) / $row['avg_rack_rate_monthly'] * 100, 2) : 0;
            $row['booking_conversion_monthly'] = ($row['completed_bookings_monthly'] + $row['cancelled_bookings_monthly']) > 0 ?
                round($row['completed_bookings_monthly'] / ($row['completed_bookings_monthly'] + $row['cancelled_bookings_monthly']) * 100, 2) : 0;
            $row['price_premium_vs_market'] = $row['market_avg_monthly'] > 0 ?
                round(($row['avg_rack_rate_monthly'] / $row['market_avg_monthly'] - 1) * 100, 2) : 0;
            $row['monthly_price_trend'] = calculate_monthly_price_trend($previous_month_data, $row);
            $row['seasonal_pattern'] = identify_seasonal_price_pattern($row['month_number'], $row['avg_achieved_rate_monthly'], $row['completed_bookings_monthly']);
            $row['seasonal_adjustment_factor'] = calculate_seasonal_adjustment($row);
            $row['seasonal_pricing_recommendation'] = get_seasonal_pricing_recommendation($row);
            
            $data[] = $row;
            $previous_month_data = $row;
        }
        $stmt->close();
    }
    return $data;
}

// 5. Algoritma Helper Functions untuk Price Analysis
function get_market_position($rack_rate, $market_min, $market_max)
{
    if ($market_max <= $market_min) return "Undefined";
    
    $position = ($rack_rate - $market_min) / ($market_max - $market_min) * 100;
    
    if ($position >= 80) return "Premium";
    elseif ($position >= 60) return "High";
    elseif ($position >= 40) return "Mid";
    elseif ($position >= 20) return "Low";
    else return "Budget";
}

function calculate_price_elasticity($bookings, $rack_rate, $achieved_rate)
{
    if ($bookings < 5 || $rack_rate <= 0) return 0;
    
    $price_change = ($rack_rate - $achieved_rate) / $rack_rate * 100;
    $quantity_change = $bookings; // Simplified elasticity calculation
    
    if ($price_change == 0) return 0;
    
    $elasticity = abs($quantity_change / $price_change);
    
    if ($elasticity > 2) return "Elastic";
    elseif ($elasticity > 1) return "Unit Elastic";
    elseif ($elasticity > 0.5) return "Inelastic";
    else return "Highly Inelastic";
}

function get_optimal_price_suggestion($data)
{
    $pricing_efficiency = $data['pricing_efficiency'] ?? 100;
    $price_position = $data['price_position_vs_market'] ?? 100;
    $discount_rate = $data['discount_rate'] ?? 0;
    
    if ($pricing_efficiency > 95 && $price_position > 110) {
        return "Maintain Premium Position";
    } elseif ($pricing_efficiency < 85 && $price_position > 100) {
        return "Reduce 5-10%";
    } elseif ($pricing_efficiency > 90 && $price_position < 90) {
        return "Increase 5-8%";
    } elseif ($discount_rate > 20) {
        return "Reduce Discounts";
    } else {
        return "Optimal Range";
    }
}

function get_price_adjustment_recommendation($data)
{
    $market_position = $data['market_position'] ?? 'Mid';
    $pricing_efficiency = $data['pricing_efficiency'] ?? 100;
    
    if ($market_position == 'Premium' && $pricing_efficiency >= 90) {
        return "Maintain Premium Pricing";
    } elseif ($market_position == 'Premium' && $pricing_efficiency < 85) {
        return "Adjust to High-Mid Range";
    } elseif ($market_position == 'Mid' && $pricing_efficiency > 95) {
        return "Test Premium Positioning";
    } elseif ($market_position == 'Low' && $pricing_efficiency > 90) {
        return "Increase to Mid-Range";
    } else {
        return "Monitor Performance";
    }
}

function calculate_market_concentration($hotel_count, $price_std_dev)
{
    if ($hotel_count <= 1) return "Monopoly";
    
    $concentration_index = $price_std_dev > 0 ? 1 / ($hotel_count * $price_std_dev) : 1;
    
    if ($concentration_index > 0.8) return "High Concentration";
    elseif ($concentration_index > 0.5) return "Medium Concentration";
    else return "Low Concentration";
}

function get_competition_level($price_variation, $hotel_count)
{
    if ($hotel_count <= 2) return "Low Competition";
    
    if ($price_variation > 30) return "High Variation";
    elseif ($price_variation > 15) return "Moderate Competition";
    else return "Price War";
}

function calculate_optimal_price_band($data)
{
    $avg_rate = $data['avg_rack_rate'] ?? 0;
    $min_rate = $data['min_rack_rate'] ?? 0;
    $max_rate = $data['max_rack_rate'] ?? 0;
    
    if ($avg_rate <= 0) return "N/A";
    
    $lower_band = $avg_rate * 0.85;
    $upper_band = $avg_rate * 1.15;
    
    return formatRupiah($lower_band) . " - " . formatRupiah($upper_band);
}

function get_competitive_price_strategy($data)
{
    $competition_level = $data['price_competition_level'] ?? 'Moderate Competition';
    $market_concentration = $data['market_concentration'] ?? 'Medium Concentration';
    
    if ($competition_level == 'Price War' && $market_concentration == 'High Concentration') {
        return "Differentiate Value Proposition";
    } elseif ($competition_level == 'High Variation') {
        return "Focus on Niche Segment";
    } elseif ($competition_level == 'Moderate Competition') {
        return "Competitive Positioning";
    } else {
        return "Market Penetration Pricing";
    }
}

function calculate_price_sensitivity($cancellation_rate, $price_gap)
{
    $sensitivity = ($cancellation_rate * 0.6) + ($price_gap * 0.4);
    
    if ($sensitivity > 25) return "High";
    elseif ($sensitivity > 15) return "Medium";
    elseif ($sensitivity > 5) return "Low";
    else return "Very Low";
}

function get_elasticity_category($sensitivity)
{
    switch($sensitivity) {
        case 'High': return "Elastic - Price Sensitive";
        case 'Medium': return "Unit Elastic";
        case 'Low': return "Inelastic";
        default: return "Highly Inelastic";
    }
}

function evaluate_segment_performance($data)
{
    $revenue_per_booking = $data['revenue_per_booking'] ?? 0;
    $cancellation_rate = $data['cancellation_rate'] ?? 0;
    
    if ($revenue_per_booking > 2000000 && $cancellation_rate < 10) {
        return "Excellent";
    } elseif ($revenue_per_booking > 1000000 && $cancellation_rate < 20) {
        return "Good";
    } elseif ($revenue_per_booking > 500000 && $cancellation_rate < 30) {
        return "Fair";
    } else {
        return "Poor";
    }
}

function get_dynamic_pricing_recommendation($data)
{
    $price_segment = $data['price_segment'] ?? '';
    $elasticity = $data['elasticity_category'] ?? '';
    $performance = $data['segment_performance'] ?? '';
    
    if ($price_segment == 'Luxury (>2M)' && $elasticity == 'Inelastic' && $performance == 'Excellent') {
        return "Increase 10-15%";
    } elseif ($price_segment == 'Premium (1M-2M)' && $elasticity == 'Unit Elastic' && $performance == 'Good') {
        return "Maintain with Seasonal Adjustments";
    } elseif ($price_segment == 'Mid-Range (500k-1M)' && $elasticity == 'Elastic - Price Sensitive') {
        return "Competitive Pricing";
    } elseif ($price_segment == 'Budget (<500k)' && $performance == 'Poor') {
        return "Reposition or Discontinue";
    } else {
        return "Monitor and Adjust";
    }
}

function calculate_monthly_price_trend($previous_data, $current_data)
{
    if (!$previous_data || !isset($previous_data['avg_achieved_rate_monthly'])) {
        return "New Data";
    }
    
    $previous_rate = $previous_data['avg_achieved_rate_monthly'];
    $current_rate = $current_data['avg_achieved_rate_monthly'];
    
    if ($previous_rate <= 0) return "N/A";
    
    $change = (($current_rate - $previous_rate) / $previous_rate) * 100;
    
    if ($change > 5) return "Increasing";
    elseif ($change < -5) return "Decreasing";
    else return "Stable";
}

function identify_seasonal_price_pattern($month, $achieved_rate, $bookings)
{
    $peak_months = [1, 6, 7, 12]; // Jan, Jun, Jul, Dec
    $low_months = [2, 9, 10]; // Feb, Sep, Oct
    
    $is_peak = in_array($month, $peak_months);
    $is_low = in_array($month, $low_months);
    
    if ($is_peak && $achieved_rate > 1500000 && $bookings > 20) {
        return "Peak Season - Premium Pricing";
    } elseif ($is_low && $achieved_rate < 800000 && $bookings < 10) {
        return "Low Season - Discounted";
    } elseif (!$is_peak && !$is_low && $achieved_rate > 1000000 && $bookings > 15) {
        return "Shoulder Season - Standard";
    } else {
        return "Regular Season";
    }
}

function calculate_seasonal_adjustment($data)
{
    $pattern = $data['seasonal_pattern'] ?? '';
    $price_premium = $data['price_premium_vs_market'] ?? 0;
    
    if (strpos($pattern, 'Peak Season') !== false && $price_premium < 15) {
        return "+10-20%";
    } elseif (strpos($pattern, 'Low Season') !== false && $price_premium > -10) {
        return "-15-25%";
    } elseif (strpos($pattern, 'Shoulder Season') !== false) {
        return "±5%";
    } else {
        return "No Adjustment";
    }
}

function get_seasonal_pricing_recommendation($data)
{
    $pattern = $data['seasonal_pattern'] ?? '';
    $adjustment = $data['seasonal_adjustment_factor'] ?? '';
    $conversion = $data['booking_conversion_monthly'] ?? 0;
    
    if ($pattern == 'Peak Season - Premium Pricing' && $conversion > 80) {
        return "Implement Peak Pricing Strategy";
    } elseif ($pattern == 'Low Season - Discounted' && $conversion < 60) {
        return "Enhance Value-Added Packages";
    } elseif ($adjustment == "+10-20%" && $conversion > 75) {
        return "Optimize Peak Season Revenue";
    } elseif ($adjustment == "-15-25%" && $conversion < 50) {
        return "Review Discount Strategy";
    } else {
        return "Maintain Current Strategy";
    }
}

function calculate_overall_price_score($positioning_analysis, $competition_analysis)
{
    $total_score = 0;
    $components = 0;

    if (!empty($positioning_analysis)) {
        $efficiencies = array_column($positioning_analysis, 'pricing_efficiency');
        $valid_efficiencies = array_filter($efficiencies, function ($val) {
            return is_numeric($val) && $val > 0;
        });

        if (!empty($valid_efficiencies)) {
            $avg_efficiency = array_sum($valid_efficiencies) / count($valid_efficiencies);
            $total_score += $avg_efficiency * 0.4;
            $components += 0.4;
        }
        
        $positions = array_column($positioning_analysis, 'price_position_vs_market');
        $valid_positions = array_filter($positions, function ($val) {
            return is_numeric($val) && $val > 0;
        });

        if (!empty($valid_positions)) {
            $avg_position = array_sum($valid_positions) / count($valid_positions);
            $position_score = $avg_position >= 90 && $avg_position <= 110 ? 100 : 
                            ($avg_position >= 80 && $avg_position <= 120 ? 80 : 60);
            $total_score += $position_score * 0.3;
            $components += 0.3;
        }
    }

    if (!empty($competition_analysis)) {
        $variations = array_column($competition_analysis, 'price_variation_coefficient');
        $valid_variations = array_filter($variations, function ($val) {
            return is_numeric($val);
        });

        if (!empty($valid_variations)) {
            $avg_variation = array_sum($valid_variations) / count($valid_variations);
            $variation_score = $avg_variation >= 10 && $avg_variation <= 25 ? 100 : 
                              ($avg_variation >= 5 && $avg_variation <= 30 ? 80 : 60);
            $total_score += $variation_score * 0.3;
            $components += 0.3;
        }
    }

    return $components > 0 ? round($total_score / $components, 1) : 0;
}

// Eksekusi fungsi analisis harga
$positioning_analysis = get_price_positioning_analysis($conn, $filter_year, $filter_month, $filter_city, $filter_hotel, $filter_room_type);
$competition_analysis = get_price_competition_analysis($conn, $filter_year, $filter_month, $filter_city);
$elasticity_analysis = get_price_elasticity_analysis($conn, $filter_year, $filter_month, $filter_city, $filter_hotel);
$seasonal_analysis = get_seasonal_pricing_analysis($conn, $filter_year, $filter_city);

$overall_price_score = calculate_overall_price_score($positioning_analysis, $competition_analysis);

// Notifikasi
$query_notif = "SELECT COUNT(*) as notifications FROM booking_hotel WHERE status = 'Pending'";
$result_notif = $conn->query($query_notif);
$notificationCount = $result_notif ? $result_notif->fetch_assoc()['notifications'] ?? 0 : 0;

$conn->close();

// Helper functions
function getIndonesianMonth($monthNumber)
{
    $months = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];
    return $months[(int)$monthNumber] ?? 'Unknown';
}

function formatRupiah($amount)
{
    if ($amount >= 1000000) {
        return 'Rp ' . number_format($amount / 1000000, 1, ',', '.') . ' Juta';
    } elseif ($amount >= 1000) {
        return 'Rp ' . number_format($amount / 1000, 1, ',', '.') . ' Ribu';
    } else {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}

function get_overall_price_recommendation($score)
{
    if ($score >= 85) return "Strategi harga optimal, pertahankan!";
    elseif ($score >= 70) return "Strategi harga baik, fokus pada optimasi";
    elseif ($score >= 55) return "Butuh perbaikan strategi harga";
    else return "Perlu evaluasi mendalam terhadap pricing strategy";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Price Analysis Dashboard | TripVerse Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../../css/dashboard.css?v=2.0.0" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #FF7A3D;
            --secondary-color: #0F172B;
            --success-color: #1baf7a;
            --info-color: #2a78d6;
            --warning-color: #eda100;
            --danger-color: #e34948;
            --light-color: #f5f6f8;
            --dark-color: #0F172B;
            --text-color: #1e2635;
            --text-light: #6b7280;
            --border-radius: 12px;
            --box-shadow: 0 4px 12px rgba(15, 23, 43, 0.08);
            --box-shadow-hover: 0 8px 24px rgba(15, 23, 43, 0.14);
            --transition: all 0.3s ease;
            --gradient-primary: linear-gradient(135deg, #FEA116, #FF7A3D);
            --gradient-success: linear-gradient(135deg, #1baf7a, #3fcf9c);
            --gradient-warning: linear-gradient(135deg, #eda100, #f4b73a);
            --gradient-danger: linear-gradient(135deg, #e34948, #ef6e6d);
            --gradient-info: linear-gradient(135deg, #2a78d6, #4f92e3);
        }

        .user-avatar img {
            width: 32px !important;
            height: 32px !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            border: 3px solid #9c27b0 !important;
        }

        /* Modern Card Design untuk Price Analysis */
        .price-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
            margin-bottom: 30px;
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .price-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(135deg, var(--primary-color), #ba68c8);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.5s ease;
        }

        .price-section:hover::before {
            transform: scaleX(1);
        }

        .price-section:hover {
            box-shadow: var(--box-shadow-hover);
            transform: translateY(-5px);
        }

        .price-section h2 {
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 18px;
            color: var(--dark-color);
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Filter Controls */
        .filter-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-light);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .filter-select {
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
            cursor: pointer;
        }

        .filter-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(156, 39, 176, 0.1);
            outline: none;
            transform: translateY(-1px);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            grid-column: -1;
        }

        .filter-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .filter-btn:hover {
            background: #7b1fa2;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(156, 39, 176, 0.3);
        }

        .reset-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            white-space: nowrap;
        }

        .reset-btn:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }

        /* KPI Cards untuk Price Analysis */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .kpi-card {
            background: white;
            padding: 25px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            text-align: center;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            border: none;
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }

        .kpi-card.primary::before {
            background: linear-gradient(135deg, var(--primary-color), #ba68c8);
        }

        .kpi-card.success::before {
            background: linear-gradient(135deg, var(--success-color), #3fcf9c);
        }

        .kpi-card.warning::before {
            background: linear-gradient(135deg, var(--warning-color), #ffa726);
        }

        .kpi-card.danger::before {
            background: linear-gradient(135deg, var(--danger-color), #ef6e6d);
        }

        .kpi-card.info::before {
            background: linear-gradient(135deg, var(--info-color), #42a5f5);
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-hover);
        }

        .kpi-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 20px;
            color: white;
        }

        .kpi-card.primary .kpi-icon {
            background: linear-gradient(135deg, var(--primary-color), #ba68c8);
        }

        .kpi-card.success .kpi-icon {
            background: linear-gradient(135deg, var(--success-color), #3fcf9c);
        }

        .kpi-card.warning .kpi-icon {
            background: linear-gradient(135deg, var(--warning-color), #ffa726);
        }

        .kpi-card.danger .kpi-icon {
            background: linear-gradient(135deg, var(--danger-color), #ef6e6d);
        }

        .kpi-card.info .kpi-icon {
            background: linear-gradient(135deg, var(--info-color), #42a5f5);
        }

        .kpi-value {
            font-size: 28px;
            font-weight: 700;
            margin: 10px 0;
            color: var(--dark-color);
        }

        .kpi-label {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 5px;
            font-weight: 500;
        }

        .kpi-note {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 8px;
        }

        /* Period Info */
        .period-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            font-size: 14px;
            color: var(--primary-color);
            font-weight: 500;
            padding: 15px 20px;
            background: rgba(156, 39, 176, 0.05);
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-color);
            flex-wrap: wrap;
        }

        .period-info span {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 12px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        /* Table Styling */
        .price-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .price-table thead th {
            background: var(--primary-color);
            color: white;
            padding: 15px 12px;
            font-size: 14px;
            text-align: left;
            font-weight: 600;
        }

        .price-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .price-table tbody tr:hover {
            background-color: #f3e5f5;
        }

        .price-table td {
            font-size: 14px;
            color: var(--text-color);
            padding: 12px;
            vertical-align: middle;
        }

        /* Badges untuk Price Analysis */
        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-success {
            background: #e8f5e8;
            color: var(--success-color);
            border: 1px solid #1baf7a;
        }

        .badge-warning {
            background: #fff3e0;
            color: var(--warning-color);
            border: 1px solid #eda100;
        }

        .badge-danger {
            background: #ffebee;
            color: var(--danger-color);
            border: 1px solid #e34948;
        }

        .badge-info {
            background: #e3f2fd;
            color: var(--info-color);
            border: 1px solid #2a78d6;
        }

        .badge-primary {
            background: #f3e5f5;
            color: var(--primary-color);
            border: 1px solid #9c27b0;
        }

        .badge-premium {
            background: linear-gradient(135deg, #9c27b0, #ba68c8);
            color: white;
            border: none;
        }

        .badge-competitive {
            background: linear-gradient(135deg, #673ab7, #9575cd);
            color: white;
            border: none;
        }

        .badge-budget {
            background: linear-gradient(135deg, #1baf7a, #3fcf9c);
            color: white;
            border: none;
        }

        /* Chart Container */
        .chart-container {
            height: 300px;
            width: 100%;
            margin: 20px 0;
            position: relative;
        }

        /* Price Analysis Header */
        .price-header {
            background: linear-gradient(135deg, var(--primary-color), #ba68c8);
            color: white;
            padding: 30px;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            box-shadow: 0 8px 20px rgba(156, 39, 176, 0.25);
            position: relative;
            overflow: hidden;
        }

        .price-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 1px, transparent 1px);
            background-size: 20px 20px;
            opacity: 0.3;
        }

        .price-header h1 {
            margin: 0 0 10px 0;
            font-size: 24px;
            font-weight: 700;
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .price-header p {
            margin: 0;
            font-size: 16px;
            opacity: 0.9;
            max-width: 600px;
            position: relative;
        }

        /* Progress Bars untuk Price Metrics */
        .price-progress {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 5px 0;
        }

        .price-progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }

        .progress-optimal {
            background: linear-gradient(135deg, #1baf7a, #3fcf9c);
        }

        .progress-good {
            background: linear-gradient(135deg, #2a78d6, #64b5f6);
        }

        .progress-fair {
            background: linear-gradient(135deg, #eda100, #ffb74d);
        }

        .progress-poor {
            background: linear-gradient(135deg, #e34948, #ef6e6d);
        }

        /* Price Score Circle */
        .price-score-circle {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: conic-gradient(#1baf7a 0deg <?php echo $overall_price_score * 3.6; ?>deg,
                    rgba(255, 255, 255, 0.2) <?php echo $overall_price_score * 3.6; ?>deg 360deg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: bold;
            color: white;
            margin: 20px auto;
            position: relative;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        .price-score-circle::before {
            content: '';
            position: absolute;
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .price-score-circle span {
            position: relative;
            z-index: 1;
        }

        .price-score-circle::after {
            content: 'Price Score';
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 14px;
            font-weight: 500;
            opacity: 0.9;
        }

        /* Summary Stats */
        .summary-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .stat-box {
            background: white;
            padding: 15px 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 200px;
            transition: all 0.3s ease;
        }

        .stat-box:hover {
            transform: translateY(-3px);
            box-shadow: var(--box-shadow-hover);
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-light);
        }

        /* Status Indicators untuk Price Analysis */
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-premium {
            background: #f3e5f5;
            color: #9c27b0;
        }

        .status-high {
            background: #e3f2fd;
            color: #2a78d6;
        }

        .status-mid {
            background: #e8f5e8;
            color: #1baf7a;
        }

        .status-low {
            background: #fff3e0;
            color: #eda100;
        }

        .status-budget {
            background: #f5f5f5;
            color: #757575;
        }

        /* Price Segment Colors */
        .segment-luxury {
            color: #9c27b0;
            font-weight: 600;
        }

        .segment-premium {
            color: #2a78d6;
            font-weight: 600;
        }

        .segment-midrange {
            color: #1baf7a;
            font-weight: 600;
        }

        .segment-budget {
            color: #eda100;
            font-weight: 600;
        }

        /* Trend Arrows */
        .trend-up {
            color: #1baf7a;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .trend-down {
            color: #e34948;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .trend-stable {
            color: #eda100;
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .filter-controls {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .summary-stats {
                flex-direction: column;
            }

            .stat-box {
                min-width: 100%;
            }
        }

        @media (max-width: 480px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }

            .period-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="../../img/logo.png" alt="TripVerse Logo" class="sidebar-brand-logo" />
            <div class="sidebar-brand-text">
                <span class="sidebar-brand-title">TripVerse</span>
                <span class="sidebar-brand-subtitle"><?= te('Dasbor Admin') ?></span>
            </div>
        </div>

        <div class="sidebar-brand-lang">
            <?php include __DIR__ . '/../_lang_switch_inner.php'; ?>
        </div>

        <div class="profile-header">
            <div class="profile-photo-section">
                <div class="profile-photo-container">
                    <img src="../../uploads/<?php echo htmlspecialchars($foto); ?>"
                        alt="Profile Photo"
                        class="profile-photo"
                        id="profilePhoto"
                        onerror="this.src='../../images/default.jpg'">

                    <div class="profile-overlay">
                        <span class="material-icons">edit</span>
                    </div>

                    <form id="uploadForm" action="dashboard.php" method="POST" enctype="multipart/form-data" style="display:none;">
                        <input type="file" name="profile_photo" id="profileUpload" accept="image/*" />
                    </form>
                </div>

                <div class="profile-info">
                    <h2><?= htmlspecialchars($firstName . ' ' . $lastName); ?></h2>
                    <p><?= htmlspecialchars($email); ?></p>

                    <div class="user-dropdown">
                        <button class="user-info" aria-haspopup="true" aria-expanded="false" onclick="toggleDropdown(this)">
                            <span class="dropdown-text"><?= te('Kelola Akun') ?></span>
                            <span class="material-icons dropdown-arrow">expand_more</span>
                        </button>

                        <div class="dropdown-content" role="menu" aria-hidden="true">
                            <a href="profile.php" class="dropdown-item">
                                <span class="material-icons">person</span>
                                <span>Edit Profile</span>
                            </a>
                            <a href="../auth/logout.php" class="dropdown-item">
                                <span class="material-icons">logout</span>
                                <span><?= te('Keluar') ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <nav>
            <!-- EXECUTIVE OVERVIEW -->
            <a href="dashboard.php" class="active">
                <span class="material-icons">dashboard</span>
                <span><?= te('Ringkasan Eksekutif') ?></span>
            </a>

            <!-- SUPPLIER APPROVAL -->
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="supplier_approvals.php">
                    <span class="material-icons">approval</span>
                    <span><?= te('Manajemen Supplier') ?></span>
                </a>
            <?php endif; ?>

            <!-- PROMO MANAGEMENT -->
            <a href="promo_management.php">
                <span class="material-icons">campaign</span>
                <span><?= te('Manajemen Promo') ?></span>
            </a>

            <!-- ANALYTICS & INSIGHTS -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="analyticsDropdown">
                    <span class="material-icons">monitor</span>
                    <span><?= te('Monitoring Performa') ?></span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="analyticsDropdown">
                    <a href="performance_analytics.php">
                        <span class="material-icons">bar_chart</span>
                        <span><?= te('Statistik Performa') ?></span>
                    </a>
                    <a href="market_analysis.php">
                        <span class="material-icons">trending_up</span>
                        <span><?= te('Tren Booking') ?></span>
                    </a>
                </div>
            </div>

            <!-- DECISION SUPPORT MODULES -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="decisionDropdown">
                    <span class="material-icons">analytics</span>
                    <span><?= te('Analisis Statistik') ?></span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="decisionDropdown">
                    <a href="revenue_optimization.php">
                        <span class="material-icons">attach_money</span>
                        <span><?= te('Statistik Pendapatan') ?></span>
                    </a>
                    <a href="pricing_strategy.php">
                        <span class="material-icons">currency_exchange</span>
                        <span>Price Analysis</span>
                    </a>
                    <a href="occupancy_analysis.php">
                        <span class="material-icons">king_bed</span>
                        <span><?= te('Statistik Okupansi') ?></span>
                    </a>
                    <a href="alos_analysis.php">
                        <span class="material-icons">calendar_today</span>
                        <span><?= te('Statistik ALOS') ?></span>
                    </a>
                </div>
            </div>

            <!-- CUSTOMER INTELLIGENCE -->
            <a href="customerdss.php">
                <span class="material-icons">people</span>
                <span><?= te('Statistik Pelanggan') ?></span>
            </a>

            <!-- LOGOUT -->
            <a href="../auth/logout.php">
                <span class="material-icons">exit_to_app</span>
                <span><?= te('Keluar') ?></span>
            </a>
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
                    <span class="notification-badge" id="notificationCount"><?= $notificationCount ?></span>
                </div>

                <div class="user-avatar">
                    <img src="../../uploads/<?php echo htmlspecialchars($foto); ?>" alt="User Avatar" />
                </div>
            </div>
        </header>

        <!-- PRICE ANALYSIS HEADER -->
        <div class="price-header">
            <h1><i class="material-icons">currency_exchange</i> Price Analysis Dashboard</h1>
            <p>Analisis harga kompetitif, positioning, dan strategi pricing untuk optimalisasi revenue</p>

            <div class="price-score-circle">
                <span><?php echo $overall_price_score; ?>%</span>
            </div>
            <p style="margin-top: 40px; font-weight: 500; font-size: 18px; position: relative; z-index: 1;">
                <?php echo get_overall_price_recommendation($overall_price_score); ?>
            </p>
        </div>

        <!-- PERIOD INFO -->
        <div class="period-info">
            <span>
                <i class="material-icons">calendar_today</i>
                Periode Analisis: <strong><?= getIndonesianMonth($filter_month) . ' ' . $filter_year ?></strong>
            </span>
            <?php if ($filter_city !== 'all'): ?>
                <span>
                    <i class="material-icons">location_on</i>
                    Kota: <strong><?= htmlspecialchars($filter_city) ?></strong>
                </span>
            <?php endif; ?>
            <?php if ($filter_hotel !== 'all'): ?>
                <span>
                    <i class="material-icons">business</i>
                    Hotel: <strong><?= htmlspecialchars($available_hotels[$filter_hotel] ?? 'Selected Hotel') ?></strong>
                </span>
            <?php endif; ?>
            <?php if ($filter_room_type !== 'all'): ?>
                <span>
                    <i class="material-icons">category</i>
                    Tipe Kamar: <strong><?= htmlspecialchars($available_room_types[$filter_room_type] ?? 'Selected Type') ?></strong>
                </span>
            <?php endif; ?>
        </div>

        <!-- FILTER ANALISIS HARGA -->
        <div class="price-section">
            <h2><i class="material-icons">filter_alt</i> Filter Analisis Harga</h2>

            <form method="GET" action="pricing_strategy.php" class="filter-controls">
                <div class="filter-group">
                    <label for="filter_month"><i class="material-icons">calendar_today</i> Bulan</label>
                    <select id="filter_month" name="month" class="filter-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= sprintf('%02d', $m) ?>" <?= $filter_month == sprintf('%02d', $m) ? 'selected' : '' ?>>
                                <?= getIndonesianMonth($m) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_year"><i class="material-icons">event</i> Tahun</label>
                    <select id="filter_year" name="year" class="filter-select">
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?= $year ?>" <?= $filter_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_city"><i class="material-icons">location_city</i> Kota</label>
                    <select id="filter_city" name="city" class="filter-select">
                        <option value="all">Semua Kota</option>
                        <?php foreach ($available_cities as $city): ?>
                            <option value="<?= $city ?>" <?= $filter_city == $city ? 'selected' : '' ?>><?= $city ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_hotel"><i class="material-icons">hotel</i> Hotel</label>
                    <select id="filter_hotel" name="hotel" class="filter-select">
                        <option value="all">Semua Hotel</option>
                        <?php foreach ($available_hotels as $id => $name): ?>
                            <option value="<?= $id ?>" <?= $filter_hotel == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_room_type"><i class="material-icons">category</i> Tipe Kamar</label>
                    <select id="filter_room_type" name="room_type" class="filter-select">
                        <option value="all">Semua Tipe</option>
                        <?php foreach ($available_room_types as $id => $name): ?>
                            <option value="<?= $id ?>" <?= $filter_room_type == $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="filter-btn">
                        <i class="material-icons">filter_alt</i> Terapkan Filter
                    </button>
                    <a href="pricing_strategy.php?month=<?= date('m') ?>&year=<?= date('Y') ?>&city=all&hotel=all&room_type=all" class="reset-btn">
                        <i class="material-icons">refresh</i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- KPI DASHBOARD UNTUK PRICE ANALYSIS -->
        <div class="kpi-grid">
            <?php
            // Hitung metrik KPI Price Analysis
            $avg_pricing_efficiency = !empty($positioning_analysis) ? 
                array_sum(array_column($positioning_analysis, 'pricing_efficiency')) / count($positioning_analysis) : 0;
            $avg_price_position = !empty($positioning_analysis) ? 
                array_sum(array_column($positioning_analysis, 'price_position_vs_market')) / count($positioning_analysis) : 100;
            $avg_discount_rate = !empty($positioning_analysis) ? 
                array_sum(array_column($positioning_analysis, 'discount_rate')) / count($positioning_analysis) : 0;
            $avg_price_variation = !empty($competition_analysis) ? 
                array_sum(array_column($competition_analysis, 'price_variation_coefficient')) / count($competition_analysis) : 0;
            $total_hotels_analyzed = count(array_unique(array_column($positioning_analysis, 'hotel_id')));
            $total_segments = count(array_unique(array_column($elasticity_analysis, 'price_segment')));
            ?>

            <div class="kpi-card <?= $avg_pricing_efficiency >= 90 ? 'success' : ($avg_pricing_efficiency >= 75 ? 'warning' : 'danger') ?>">
                <div class="kpi-icon">
                    <i class="material-icons">trending_up</i>
                </div>
                <div class="kpi-label">Pricing Efficiency</div>
                <div class="kpi-value"><?= round($avg_pricing_efficiency, 1) ?>%</div>
                <div class="kpi-note">
                    Efisiensi harga vs rack rate
                    <div class="price-progress">
                        <div class="price-progress-fill <?= $avg_pricing_efficiency >= 90 ? 'progress-optimal' : ($avg_pricing_efficiency >= 75 ? 'progress-good' : 'progress-poor') ?>"
                            style="width: <?= min(100, $avg_pricing_efficiency) ?>%"></div>
                    </div>
                </div>
            </div>

            <div class="kpi-card <?= $avg_price_position >= 90 && $avg_price_position <= 110 ? 'success' : ($avg_price_position >= 80 && $avg_price_position <= 120 ? 'warning' : 'danger') ?>">
                <div class="kpi-icon">
                    <i class="material-icons">compare</i>
                </div>
                <div class="kpi-label">Market Position</div>
                <div class="kpi-value"><?= round($avg_price_position, 1) ?>%</div>
                <div class="kpi-note">
                    vs Rata-rata Pasar
                    <div class="price-progress">
                        <div class="price-progress-fill <?= $avg_price_position >= 90 && $avg_price_position <= 110 ? 'progress-optimal' : ($avg_price_position >= 80 && $avg_price_position <= 120 ? 'progress-good' : 'progress-poor') ?>"
                            style="width: <?= min(150, $avg_price_position) ?>%"></div>
                    </div>
                </div>
            </div>

            <div class="kpi-card <?= $avg_discount_rate <= 10 ? 'success' : ($avg_discount_rate <= 20 ? 'warning' : 'danger') ?>">
                <div class="kpi-icon">
                    <i class="material-icons">percent</i>
                </div>
                <div class="kpi-label">Avg Discount Rate</div>
                <div class="kpi-value"><?= round($avg_discount_rate, 1) ?>%</div>
                <div class="kpi-note">
                    Rata-rata diskon diberikan
                    <div class="price-progress">
                        <div class="price-progress-fill <?= $avg_discount_rate <= 10 ? 'progress-optimal' : ($avg_discount_rate <= 20 ? 'progress-good' : 'progress-poor') ?>"
                            style="width: <?= min(100, $avg_discount_rate) ?>%"></div>
                    </div>
                </div>
            </div>

            <div class="kpi-card <?= $avg_price_variation >= 10 && $avg_price_variation <= 25 ? 'success' : ($avg_price_variation >= 5 && $avg_price_variation <= 30 ? 'warning' : 'danger') ?>">
                <div class="kpi-icon">
                    <i class="material-icons">scatter_plot</i>
                </div>
                <div class="kpi-label">Price Variation</div>
                <div class="kpi-value"><?= round($avg_price_variation, 1) ?>%</div>
                <div class="kpi-note">
                    Koefisien variasi harga
                    <div class="price-progress">
                        <div class="price-progress-fill <?= $avg_price_variation >= 10 && $avg_price_variation <= 25 ? 'progress-optimal' : ($avg_price_variation >= 5 && $avg_price_variation <= 30 ? 'progress-good' : 'progress-poor') ?>"
                            style="width: <?= min(50, $avg_price_variation) ?>%"></div>
                    </div>
                </div>
            </div>

            <div class="kpi-card info">
                <div class="kpi-icon">
                    <i class="material-icons">business</i>
                </div>
                <div class="kpi-label">Hotels Analyzed</div>
                <div class="kpi-value"><?= $total_hotels_analyzed ?></div>
                <div class="kpi-note">
                    Hotel dalam analisis
                </div>
            </div>

            <div class="kpi-card primary">
                <div class="kpi-icon">
                    <i class="material-icons">category</i>
                </div>
                <div class="kpi-label">Price Segments</div>
                <div class="kpi-value"><?= $total_segments ?></div>
                <div class="kpi-note">
                    Segmentasi harga teridentifikasi
                </div>
            </div>
        </div>

        <!-- PRICE POSITIONING ANALYSIS -->
        <div class="price-section">
            <h2><i class="material-icons">bar_chart</i> Price Positioning Analysis</h2>

            <div class="chart-container">
                <canvas id="priceChart"></canvas>
            </div>

            <table class="price-table">
                <thead>
                    <tr>
                        <th>Hotel</th>
                        <th>Tipe</th>
                        <th>Rack Rate</th>
                        <th>Achieved Rate</th>
                        <th>Efficiency</th>
                        <th>Market Position</th>
                        <th>Recommendation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($positioning_analysis)): ?>
                        <?php foreach (array_slice($positioning_analysis, 0, 8) as $pos): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars(substr($pos['nama_hotel'], 0, 20)) ?></strong></td>
                                <td>
                                    <span class="badge badge-primary"><?= htmlspecialchars($pos['nama_tipe']) ?></span>
                                </td>
                                <td><strong><?= formatRupiah($pos['rack_rate']) ?></strong></td>
                                <td><?= formatRupiah($pos['avg_achieved_rate']) ?></td>
                                <td>
                                    <span style="color: <?= $pos['pricing_efficiency'] >= 90 ? '#1baf7a' : ($pos['pricing_efficiency'] >= 80 ? '#eda100' : '#e34948') ?>; font-weight: 600;">
                                        <?= $pos['pricing_efficiency'] ?>%
                                    </span>
                                    <div class="price-progress">
                                        <div class="price-progress-fill <?= $pos['pricing_efficiency'] >= 90 ? 'progress-optimal' : ($pos['pricing_efficiency'] >= 80 ? 'progress-good' : 'progress-poor') ?>"
                                            style="width: <?= min(100, $pos['pricing_efficiency']) ?>%"></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-indicator 
                                        <?= $pos['market_position'] == 'Premium' ? 'status-premium' : 
                                           ($pos['market_position'] == 'High' ? 'status-high' : 
                                           ($pos['market_position'] == 'Mid' ? 'status-mid' : 
                                           ($pos['market_position'] == 'Low' ? 'status-low' : 'status-budget'))) ?>">
                                        <?= $pos['market_position'] ?>
                                    </span>
                                    <small style="display: block; font-size: 11px; color: #666;">
                                        <?= round($pos['price_position_vs_market'], 1) ?>% vs market
                                    </small>
                                </td>
                                <td>
                                    <span class="badge 
                                        <?= strpos($pos['price_adjustment_recommendation'], 'Premium') !== false ? 'badge-premium' : 
                                           (strpos($pos['price_adjustment_recommendation'], 'Increase') !== false ? 'badge-success' : 
                                           (strpos($pos['price_adjustment_recommendation'], 'Reduce') !== false ? 'badge-warning' : 
                                           (strpos($pos['price_adjustment_recommendation'], 'Maintain') !== false ? 'badge-info' : 'badge-primary'))) ?>">
                                        <?= htmlspecialchars($pos['optimal_price_suggestion']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-light);">
                                <i class="material-icons" style="font-size: 48px; margin-bottom: 15px; color: var(--primary-color);">bar_chart</i>
                                <h3 style="color: var(--primary-color);">Tidak Ada Data Positioning</h3>
                                <p>Tidak ada data analisis positioning harga untuk periode ini.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PRICE COMPETITION ANALYSIS -->
        <div class="price-section">
            <h2><i class="material-icons">compare</i> Price Competition Analysis</h2>

            <table class="price-table">
                <thead>
                    <tr>
                        <th>Kota</th>
                        <th>Tipe Kamar</th>
                        <th>Hotel Count</th>
                        <th>Avg Price</th>
                        <th>Price Range</th>
                        <th>Competition Level</th>
                        <th>Strategy</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($competition_analysis)): ?>
                        <?php foreach ($competition_analysis as $comp): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($comp['kota']) ?></strong></td>
                                <td>
                                    <span class="badge badge-info"><?= htmlspecialchars($comp['nama_tipe']) ?></span>
                                </td>
                                <td><?= $comp['hotel_count'] ?></td>
                                <td><strong><?= formatRupiah($comp['avg_rack_rate']) ?></strong></td>
                                <td><?= formatRupiah($comp['price_range']) ?></td>
                                <td>
                                    <span class="badge 
                                        <?= $comp['price_competition_level'] == 'Price War' ? 'badge-danger' : 
                                           ($comp['price_competition_level'] == 'High Variation' ? 'badge-warning' : 
                                           ($comp['price_competition_level'] == 'Moderate Competition' ? 'badge-info' : 'badge-primary')) ?>">
                                        <?= $comp['price_competition_level'] ?>
                                    </span>
                                    <small style="display: block; font-size: 11px; color: #666;">
                                        Variation: <?= $comp['price_variation_coefficient'] ?>%
                                    </small>
                                </td>
                                <td>
                                    <span class="badge 
                                        <?= strpos($comp['competitive_strategy'], 'Differentiate') !== false ? 'badge-premium' : 
                                           (strpos($comp['competitive_strategy'], 'Niche') !== false ? 'badge-success' : 
                                           (strpos($comp['competitive_strategy'], 'Competitive') !== false ? 'badge-info' : 'badge-budget')) ?>">
                                        <?= htmlspecialchars($comp['competitive_strategy']) ?>
                                    </span>
                                    <small style="display: block; font-size: 11px; color: #666;">
                                        Optimal: <?= $comp['optimal_price_band'] ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-light);">
                                <i class="material-icons" style="font-size: 48px; margin-bottom: 15px; color: var(--primary-color);">compare</i>
                                <h3 style="color: var(--primary-color);">Tidak Ada Data Kompetisi</h3>
                                <p>Tidak ada data analisis kompetisi harga untuk periode ini.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PRICE ELASTICITY ANALYSIS -->
        <div class="price-section">
            <h2><i class="material-icons">trending_up</i> Price Elasticity Analysis</h2>

            <table class="price-table">
                <thead>
                    <tr>
                        <th>Hotel</th>
                        <th>Price Segment</th>
                        <th>Bookings</th>
                        <th>Cancellation</th>
                        <th>Price Gap</th>
                        <th>Elasticity</th>
                        <th>Recommendation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($elasticity_analysis)): ?>
                        <?php foreach (array_slice($elasticity_analysis, 0, 10) as $elastic): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars(substr($elastic['nama_hotel'], 0, 15)) ?></strong></td>
                                <td>
                                    <span class="
                                        <?= $elastic['price_segment'] == 'Luxury (>2M)' ? 'segment-luxury' : 
                                           ($elastic['price_segment'] == 'Premium (1M-2M)' ? 'segment-premium' : 
                                           ($elastic['price_segment'] == 'Mid-Range (500k-1M)' ? 'segment-midrange' : 'segment-budget')) ?>">
                                        <?= htmlspecialchars($elastic['price_segment']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge 
                                        <?= $elastic['completed_bookings'] > 10 ? 'badge-success' : 
                                           ($elastic['completed_bookings'] > 5 ? 'badge-warning' : 'badge-danger') ?>">
                                        <?= $elastic['completed_bookings'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="color: <?= $elastic['cancellation_rate'] < 10 ? '#1baf7a' : ($elastic['cancellation_rate'] < 20 ? '#eda100' : '#e34948') ?>; font-weight: 600;">
                                        <?= $elastic['cancellation_rate'] ?>%
                                    </span>
                                </td>
                                <td>
                                    <span style="color: <?= $elastic['price_gap_percentage'] < 5 ? '#1baf7a' : ($elastic['price_gap_percentage'] < 15 ? '#eda100' : '#e34948') ?>; font-weight: 600;">
                                        <?= $elastic['price_gap_percentage'] ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="badge 
                                        <?= $elastic['elasticity_category'] == 'Elastic - Price Sensitive' ? 'badge-danger' : 
                                           ($elastic['elasticity_category'] == 'Unit Elastic' ? 'badge-warning' : 
                                           ($elastic['elasticity_category'] == 'Inelastic' ? 'badge-info' : 'badge-success')) ?>">
                                        <?= $elastic['elasticity_category'] ?>
                                    </span>
                                    <small style="display: block; font-size: 11px; color: #666;">
                                        Sensitivity: <?= $elastic['price_sensitivity'] ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge 
                                        <?= strpos($elastic['dynamic_pricing_recommendation'], 'Increase') !== false ? 'badge-success' : 
                                           (strpos($elastic['dynamic_pricing_recommendation'], 'Maintain') !== false ? 'badge-info' : 
                                           (strpos($elastic['dynamic_pricing_recommendation'], 'Competitive') !== false ? 'badge-warning' : 'badge-danger')) ?>">
                                        <?= htmlspecialchars($elastic['dynamic_pricing_recommendation']) ?>
                                    </span>
                                    <small style="display: block; font-size: 11px; color: #666;">
                                        Performance: <?= $elastic['segment_performance'] ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-light);">
                                <i class="material-icons" style="font-size: 48px; margin-bottom: 15px; color: var(--primary-color);">trending_up</i>
                                <h3 style="color: var(--primary-color);">Tidak Ada Data Elasticity</h3>
                                <p>Tidak ada data analisis elastisitas harga untuk periode ini.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- SEASONAL PRICING ANALYSIS -->
        <div class="price-section">
            <h2><i class="material-icons">calendar_today</i> Seasonal Pricing Analysis</h2>

            <table class="price-table">
                <thead>
                    <tr>
                        <th>Bulan</th>
                        <th>Avg Rack Rate</th>
                        <th>Avg Achieved Rate</th>
                        <th>Bookings</th>
                        <th>Conversion</th>
                        <th>Seasonal Pattern</th>
                        <th>Recommendation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($seasonal_analysis)): ?>
                        <?php foreach ($seasonal_analysis as $season): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($season['month_name']) ?></strong></td>
                                <td><strong><?= formatRupiah($season['avg_rack_rate_monthly']) ?></strong></td>
                                <td><?= formatRupiah($season['avg_achieved_rate_monthly']) ?></td>
                                <td><?= $season['completed_bookings_monthly'] ?></td>
                                <td>
                                    <span style="color: <?= $season['booking_conversion_monthly'] >= 80 ? '#1baf7a' : ($season['booking_conversion_monthly'] >= 60 ? '#eda100' : '#e34948') ?>; font-weight: 600;">
                                        <?= $season['booking_conversion_monthly'] ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="badge 
                                        <?= strpos($season['seasonal_pattern'], 'Peak') !== false ? 'badge-premium' : 
                                           (strpos($season['seasonal_pattern'], 'Low') !== false ? 'badge-warning' : 
                                           (strpos($season['seasonal_pattern'], 'Shoulder') !== false ? 'badge-info' : 'badge-primary')) ?>">
                                        <?= $season['seasonal_pattern'] ?>
                                    </span>
                                    <small style="display: block; font-size: 11px; color: #666;">
                                        Trend: <?= $season['monthly_price_trend'] ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge 
                                        <?= strpos($season['seasonal_pricing_recommendation'], 'Peak') !== false ? 'badge-success' : 
                                           (strpos($season['seasonal_pricing_recommendation'], 'Enhance') !== false ? 'badge-warning' : 
                                           (strpos($season['seasonal_pricing_recommendation'], 'Optimize') !== false ? 'badge-info' : 'badge-primary')) ?>">
                                        <?= htmlspecialchars($season['seasonal_pricing_recommendation']) ?>
                                    </span>
                                    <small style="display: block; font-size: 11px; color: #666;">
                                        Adjustment: <?= $season['seasonal_adjustment_factor'] ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-light);">
                                <i class="material-icons" style="font-size: 48px; margin-bottom: 15px; color: var(--primary-color);">calendar_today</i>
                                <h3 style="color: var(--primary-color);">Tidak Ada Data Musiman</h3>
                                <p>Tidak ada data analisis harga musiman untuk periode ini.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Price Chart
            const priceCtx = document.getElementById('priceChart');
            if (priceCtx) {
                const priceData = <?= !empty($positioning_analysis) ?
                                        json_encode(array_slice($positioning_analysis, 0, 8)) :
                                        '[]' ?>;

                if (priceData.length > 0) {
                    const labels = priceData.map(p => {
                        const hotelName = p.nama_hotel || '';
                        const words = hotelName.split(' ');
                        if (words.length > 2) {
                            return words.slice(0, 2).join(' ') + '...';
                        }
                        return hotelName.substring(0, 12);
                    });

                    const rackRates = priceData.map(p => p.rack_rate || 0);
                    const achieved = priceData.map(p => p.avg_achieved_rate || 0);
                    const marketAvg = priceData.map(p => p.market_avg_price || 0);

                    new Chart(priceCtx.getContext('2d'), {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Rack Rate',
                                data: rackRates.map(r => r / 1000000),
                                backgroundColor: 'rgba(156, 39, 176, 0.8)',
                                borderColor: '#9c27b0',
                                borderWidth: 1,
                                borderRadius: 6,
                                borderSkipped: false,
                            }, {
                                label: 'Achieved Rate',
                                data: achieved.map(a => a / 1000000),
                                backgroundColor: 'rgba(76, 175, 80, 0.8)',
                                borderColor: '#1baf7a',
                                borderWidth: 1,
                                borderRadius: 6,
                                borderSkipped: false,
                            }, {
                                label: 'Market Avg',
                                data: marketAvg.map(m => m / 1000000),
                                backgroundColor: 'rgba(33, 150, 243, 0.6)',
                                borderColor: '#2a78d6',
                                borderWidth: 1,
                                borderRadius: 6,
                                borderSkipped: false,
                                type: 'line',
                                fill: false
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        pointStyle: 'circle'
                                    }
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: false,
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) {
                                                label += ': ';
                                            }
                                            label += 'Rp ' + context.parsed.y.toFixed(1) + ' Juta';
                                            return label;
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Harga (Juta Rupiah)',
                                        font: {
                                            weight: 'bold'
                                        }
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            return 'Rp ' + value.toFixed(1) + ' Jt';
                                        }
                                    }
                                },
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Hotel',
                                        font: {
                                            weight: 'bold'
                                        }
                                    }
                                }
                            },
                            interaction: {
                                intersect: false,
                                mode: 'index',
                            }
                        }
                    });
                } else {
                    priceCtx.parentElement.innerHTML = `
                        <div style="text-align: center; padding: 40px; color: var(--text-light);">
                            <i class="material-icons" style="font-size: 48px; margin-bottom: 15px; color: var(--primary-color);">bar_chart</i>
                            <h3 style="color: var(--primary-color);">Tidak Ada Data Chart</h3>
                            <p>Tidak ada data untuk periode ini.</p>
                        </div>
                    `;
                }
            }

            // Sidebar functionality
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

            // Dropdown functionality
            function toggleDropdown(button) {
                event.stopPropagation();
                const dropdown = button.nextElementSibling;
                const isExpanded = button.getAttribute('aria-expanded') === 'true';

                document.querySelectorAll('.user-dropdown .dropdown-content').forEach(d => {
                    d.classList.remove('show');
                    d.setAttribute('aria-hidden', 'true');
                    d.previousElementSibling.setAttribute('aria-expanded', 'false');
                });

                if (!isExpanded) {
                    dropdown.classList.add('show');
                    button.setAttribute('aria-expanded', 'true');
                    dropdown.setAttribute('aria-hidden', 'false');
                }
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.user-dropdown')) {
                    document.querySelectorAll('.user-dropdown .dropdown-content').forEach(d => {
                        d.classList.remove('show');
                        d.setAttribute('aria-hidden', 'true');
                        d.previousElementSibling.setAttribute('aria-expanded', 'false');
                    });
                }
            });

            // Profile photo upload functionality
            const profilePhotoContainer = document.querySelector('.profile-photo-container');
            const profileUpload = document.getElementById('profileUpload');

            if (profilePhotoContainer) {
                profilePhotoContainer.addEventListener('click', function() {
                    profileUpload.click();
                });
            }

            if (profileUpload) {
                profileUpload.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        document.getElementById('uploadForm').submit();
                    }
                });
            }

            // Dropdown menus for sidebar
            document.querySelectorAll('.booking-toggle').forEach(toggle => {
                toggle.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    const parentMenu = toggle.closest('.user-menu');
                    const dropdownId = toggle.getAttribute('data-target');
                    const dropdown = document.getElementById(dropdownId);
                    const isExpanded = parentMenu.getAttribute('aria-expanded') === 'true';

                    document.querySelectorAll('.user-menu').forEach(menu => {
                        if (menu !== parentMenu) {
                            menu.setAttribute('aria-expanded', 'false');
                        }
                    });
                    document.querySelectorAll('.booking-submenu').forEach(sub => {
                        if (sub !== dropdown) {
                            sub.classList.remove('show');
                            sub.classList.add('hidden');
                            sub.setAttribute('aria-hidden', 'true');
                        }
                    });

                    if (!isExpanded) {
                        parentMenu.setAttribute('aria-expanded', 'true');
                        dropdown.classList.remove('hidden');
                        dropdown.classList.add('show');
                        dropdown.setAttribute('aria-hidden', 'false');
                    } else {
                        parentMenu.setAttribute('aria-expanded', 'false');
                        dropdown.classList.remove('show');
                        dropdown.classList.add('hidden');
                        dropdown.setAttribute('aria-hidden', 'true');
                    }
                });
            });

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
        });
    </script>
</body>

</html>