<?php
session_start();

// Check if user is logged in and is an admin (role is 'admin')
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Akses ditolak! Halaman ini hanya untuk admin.'); window.location='home.php';</script>";
    exit;
}

require 'connect.php';

$id_user = $_SESSION['id_user'];

// --- Get Admin Data ---
$query = "SELECT username, email, first_name, last_name, profile_picture FROM user WHERE id_user = ?";
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
    $firstName = $data['first_name'];
    $lastName = $data['last_name'];
    $email = $data['email'];
    $foto = $data['profile_picture'] ?: '../images/default.jpg';
} else {
    $firstName = $lastName = "-";
    $email = "unknown@tripverse.com";
    $foto = "../images/default.jpg";
}
$stmt->close();

// --- SIMPLIFIED FILTER LOGIC ---
$filter_year = $_GET['year'] ?? date('Y');
$filter_month = $_GET['month'] ?? date('m');
$filter_city = $_GET['city'] ?? 'all';
$filter_hotel = $_GET['hotel'] ?? 'all';

// Validate parameters
if (!is_numeric($filter_month) || $filter_month < 1 || $filter_month > 12) {
    $filter_month = date('m');
}
if (!is_numeric($filter_year) || $filter_year < 2020 || $filter_year > date('Y') + 1) {
    $filter_year = date('Y');
}

// Get list of available years
$years_query = "SELECT DISTINCT YEAR(tanggal_booking) as year FROM booking_hotel ORDER BY year DESC";
$years_result = $conn->query($years_query);
$available_years = [];
if ($years_result) {
    while ($row = $years_result->fetch_assoc()) {
        $available_years[] = $row['year'];
    }
}

if (!in_array(date('Y'), $available_years)) {
    $available_years[] = date('Y');
    rsort($available_years);
}

// Get list of cities
$cities_query = "SELECT DISTINCT kota FROM hotel ORDER BY kota";
$cities_result = $conn->query($cities_query);
$available_cities = [];
if ($cities_result) {
    while ($row = $cities_result->fetch_assoc()) {
        $available_cities[] = $row['kota'];
    }
}

// Get list of hotels
$hotels_query = "SELECT hotel_id, nama_hotel FROM hotel ORDER BY nama_hotel";
$hotels_result = $conn->query($hotels_query);
$available_hotels = [];
if ($hotels_result) {
    while ($row = $hotels_result->fetch_assoc()) {
        $available_hotels[$row['hotel_id']] = $row['nama_hotel'];
    }
}

// =====================================================================
// PERBAIKAN 1: FUNGSI RISK CALCULATION YANG LEBIH DETAIL
// =====================================================================

/**
 * FUNGSI UTAMA: Hitung Hotel Risk Index
 * Formula: (Cancellation Rate * 0.4) + (Revenue Impact * 0.3) + (Booking Volume * 0.3)
 */
function calculate_hotel_risk_level($hotel_data)
{
    // Bobot untuk setiap faktor
    $cancellation_rate_weight = 0.4;
    $revenue_impact_weight = 0.3;
    $volume_weight = 0.3;
    
    // Normalisasi Cancellation Rate (0-100)
    // 50% cancellation rate = 100 points
    $normalized_cancellation = min(100, $hotel_data['cancellation_rate'] * 2);
    
    // Normalisasi Lost Revenue (0-100)
    // Asumsi maksimal kerugian 50jt per hotel per bulan
    $max_revenue_loss = 50000000;
    $normalized_revenue = $hotel_data['lost_revenue'] > 0 
        ? min(100, ($hotel_data['lost_revenue'] / $max_revenue_loss) * 100)
        : 0;
    
    // Normalisasi Booking Volume (0-100)
    // Asumsi maksimal 100 booking per hotel per bulan
    $max_bookings = 100;
    $normalized_volume = min(100, ($hotel_data['total_bookings'] / $max_bookings) * 100);
    
    // Calculate Hotel Risk Index (0-100)
    $hotel_risk_index = 
        ($normalized_cancellation * $cancellation_rate_weight) +
        ($normalized_revenue * $revenue_impact_weight) +
        ($normalized_volume * $volume_weight);
    
    // Tentukan level risiko berdasarkan threshold
    if ($hotel_risk_index >= 70) return 'critical';
    elseif ($hotel_risk_index >= 50) return 'high';
    elseif ($hotel_risk_index >= 30) return 'medium';
    else return 'low';
}

/**
 * FUNGSI UTAMA: Hitung Individual Booking Risk Score
 * Multi-factor calculation dengan 5 parameter
 */
function calculate_booking_risk_score($booking_data)
{
    $risk_score = 0;
    
    // 1. FACTOR: JARAK HARI MENUJU CHECK-IN (MAX 40 POINTS)
    $days_to_checkin = $booking_data['days_until_checkin'] ?? 30;
    
    if ($days_to_checkin <= 1) $risk_score += 40;
    elseif ($days_to_checkin <= 3) $risk_score += 35;
    elseif ($days_to_checkin <= 7) $risk_score += 25;
    elseif ($days_to_checkin <= 14) $risk_score += 15;
    else $risk_score += 5;
    
    // 2. FACTOR: NILAI BOOKING (MAX 30 POINTS)
    $total_harga = $booking_data['total_harga'] ?? 0;
    
    if ($total_harga > 5000000) $risk_score += 30;
    elseif ($total_harga > 2000000) $risk_score += 25;
    elseif ($total_harga > 1000000) $risk_score += 20;
    elseif ($total_harga > 500000) $risk_score += 15;
    else $risk_score += 10;
    
    // 3. FACTOR: JUMLAH KAMAR (MAX 15 POINTS)
    $jumlah_kamar = $booking_data['jumlah_kamar'] ?? 1;
    
    if ($jumlah_kamar >= 5) $risk_score += 15;
    elseif ($jumlah_kamar >= 3) $risk_score += 10;
    elseif ($jumlah_kamar >= 2) $risk_score += 5;
    
    // 4. FACTOR: METODE PEMBAYARAN (MAX 15 POINTS)
    $payment_method = strtolower($booking_data['metode_pembayaran'] ?? '');
    
    if (strpos($payment_method, 'transfer') !== false) $risk_score += 15;
    elseif (strpos($payment_method, 'kartu') !== false) $risk_score += 8;
    elseif (strpos($payment_method, 'qris') !== false) $risk_score += 5;
    else $risk_score += 10;
    
    // 5. FACTOR: WAKTU BOOKING (BONUS/MALUS 0-5 POINTS)
    if (isset($booking_data['tanggal_booking'])) {
        $booking_hour = date('H', strtotime($booking_data['tanggal_booking']));
        // Booking di luar jam kerja (18:00-06:00) lebih berisiko
        if ($booking_hour < 6 || $booking_hour > 18) $risk_score += 5;
    }
    
    // Pastikan tidak melebihi 100
    return min(100, $risk_score);
}

/**
 * FUNGSI UTAMA: Generate rekomendasi berdasarkan level risiko
 */
function generate_risk_recommendation($risk_level, $data = [])
{
    $recommendations = [];
    
    switch ($risk_level) {
        case 'critical':
            $recommendations = [
                "🚨 Segera hubungi customer dalam 1 jam untuk konfirmasi",
                "🔄 Siapkan kamar cadangan atau alternatif hotel",
                "📞 Escalate ke management untuk approval khusus",
                "📊 Review partnership dengan hotel jika terjadi berulang",
                "💰 Pertimbangkan offer discount untuk konfirmasi cepat"
            ];
            break;
            
        case 'high':
            $recommendations = [
                "⏰ Follow-up intensif dalam 4 jam",
                "🎯 Offer promo khusus untuk konfirmasi cepat",
                "📈 Monitor booking secara harian",
                "📋 Review cancellation policy dan terms",
                "📧 Kirim reminder email dan SMS"
            ];
            break;
            
        case 'medium':
            $recommendations = [
                "📅 Follow-up standar dalam 24 jam",
                "🔔 Kirim reminder check-in 3 hari sebelumnya",
                "📊 Review performa mingguan",
                "⚙️ Optimize confirmation process",
                "📝 Collect customer feedback"
            ];
            break;
            
        case 'low':
            $recommendations = [
                "✅ Maintain standard operating procedure",
                "📋 Monthly performance review",
                "⭐ Collect customer satisfaction feedback",
                "🔄 Continue quality service improvement",
                "📊 Monitor trends and patterns"
            ];
            break;
            
        default:
            $recommendations = ["📊 Monitor dan evaluasi secara berkala"];
    }
    
    // Tambahkan rekomendasi spesifik berdasarkan data
    if (!empty($data)) {
        if (isset($data['cancellation_rate']) && $data['cancellation_rate'] > 25) {
            $recommendations[] = "📉 Evaluasi penyebab pembatalan tinggi (≥25%)";
        }
        if (isset($data['lost_revenue']) && $data['lost_revenue'] > 5000000) {
            $recommendations[] = "💰 Review pricing strategy untuk mengurangi kerugian";
        }
    }
    
    return $recommendations;
}

/**
 * FUNGSI UTAMA: Tentukan level risiko berdasarkan threshold
 */
function determine_risk_level($data)
{
    // 1. Cek berdasarkan Hotel Risk Index jika ada
    if (isset($data['hotel_risk_index'])) {
        if ($data['hotel_risk_index'] >= 70) return 'critical';
        if ($data['hotel_risk_index'] >= 50) return 'high';
        if ($data['hotel_risk_index'] >= 30) return 'medium';
        return 'low';
    }
    
    // 2. Cek berdasarkan cancellation rate
    if (isset($data['cancellation_rate'])) {
        if ($data['cancellation_rate'] >= 30) return 'critical';
        if ($data['cancellation_rate'] >= 20) return 'high';
        if ($data['cancellation_rate'] >= 10) return 'medium';
        return 'low';
    }
    
    // 3. Cek berdasarkan risk score individual
    if (isset($data['risk_score'])) {
        if ($data['risk_score'] >= 70) return 'critical';
        if ($data['risk_score'] >= 50) return 'high';
        if ($data['risk_score'] >= 30) return 'medium';
        return 'low';
    }
    
    return 'low';
}

// =====================================================================
// PERBAIKAN 2: METRICS YANG LEBIH DETAIL
// =====================================================================

function get_improved_metrics($conn, $year, $month, $city = 'all', $hotel = 'all')
{
    $query = "SELECT 
        COUNT(*) as total_bookings,
        COUNT(CASE WHEN status = 'Cancelled' THEN 1 END) as cancelled_bookings,
        COUNT(CASE WHEN status = 'Completed' THEN 1 END) as completed_bookings,
        COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending_bookings,
        COUNT(CASE WHEN status = 'Confirmed' THEN 1 END) as confirmed_bookings,
        
        SUM(CASE WHEN status = 'Cancelled' THEN total_harga ELSE 0 END) as lost_revenue,
        SUM(CASE WHEN status = 'Completed' THEN total_harga ELSE 0 END) as realized_revenue,
        SUM(CASE WHEN status = 'Pending' THEN total_harga ELSE 0 END) as pending_revenue,
        SUM(CASE WHEN status = 'Confirmed' THEN total_harga ELSE 0 END) as confirmed_revenue,
        
        AVG(total_harga) as avg_booking_value,
        AVG(CASE WHEN status = 'Cancelled' THEN total_harga ELSE NULL END) as avg_cancelled_value,
        AVG(CASE WHEN status = 'Completed' THEN total_harga ELSE NULL END) as avg_completed_value,
        AVG(CASE WHEN status = 'Pending' THEN total_harga ELSE NULL END) as avg_pending_value,
        AVG(CASE WHEN status = 'Confirmed' THEN total_harga ELSE NULL END) as avg_confirmed_value,
        
        MIN(CASE WHEN status = 'Pending' THEN DATEDIFF(check_in, CURDATE()) ELSE NULL END) as min_pending_days,
        MAX(CASE WHEN status = 'Pending' THEN DATEDIFF(check_in, CURDATE()) ELSE NULL END) as max_pending_days,
        AVG(CASE WHEN status = 'Pending' THEN DATEDIFF(check_in, CURDATE()) ELSE NULL END) as avg_pending_days
        
        FROM booking_hotel b
        LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
        WHERE YEAR(tanggal_booking) = ? AND MONTH(tanggal_booking) = ?";

    $params = [$year, $month];
    $types = "ii";

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

    $stmt = $conn->prepare($query);
    $metrics = [];

    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $metrics = $result->fetch_assoc();
        $stmt->close();
    }

    // Calculate all metrics
    if ($metrics && $metrics['total_bookings'] > 0) {
        // 1. BASIC RATES
        $metrics['cancellation_rate'] = round(($metrics['cancelled_bookings'] / $metrics['total_bookings']) * 100, 1);
        $metrics['completion_rate'] = round(($metrics['completed_bookings'] / $metrics['total_bookings']) * 100, 1);
        $metrics['confirmation_rate'] = round((($metrics['completed_bookings'] + $metrics['confirmed_bookings']) / $metrics['total_bookings']) * 100, 1);
        $metrics['pending_rate'] = round(($metrics['pending_bookings'] / $metrics['total_bookings']) * 100, 1);
        
        // 2. REVENUE CALCULATIONS
        $metrics['total_potential_revenue'] = $metrics['realized_revenue'] + $metrics['lost_revenue'] + 
                                            $metrics['pending_revenue'] + $metrics['confirmed_revenue'];
        
        // Revenue at Risk = Pending + Lost (sudah hilang)
        $metrics['revenue_at_risk'] = $metrics['pending_revenue'] + $metrics['lost_revenue'];
        $metrics['revenue_at_risk_percentage'] = $metrics['total_potential_revenue'] > 0 
            ? round(($metrics['revenue_at_risk'] / $metrics['total_potential_revenue']) * 100, 1) 
            : 0;
        
        // 3. EFFICIENCY INDICES
        $metrics['booking_efficiency_index'] = round((($metrics['completed_bookings'] + $metrics['confirmed_bookings']) / $metrics['total_bookings']) * 100, 1);
        
        // 4. AVERAGE VALUES
        $metrics['avg_days_to_checkin_pending'] = $metrics['avg_pending_days'] ? round($metrics['avg_pending_days'], 1) : 0;
        
        // 5. RISK LEVEL BASED ON METRICS
        $risk_data = [
            'cancellation_rate' => $metrics['cancellation_rate'],
            'lost_revenue' => $metrics['lost_revenue'],
            'revenue_at_risk_percentage' => $metrics['revenue_at_risk_percentage']
        ];
        $metrics['overall_risk_level'] = determine_risk_level($risk_data);
        
        // 6. ADD RECOMMENDATIONS
        $metrics['recommendations'] = generate_risk_recommendation($metrics['overall_risk_level'], $metrics);
    } else {
        // Default values if no data
        $default_metrics = [
            'total_bookings' => 0,
            'cancelled_bookings' => 0,
            'completed_bookings' => 0,
            'pending_bookings' => 0,
            'confirmed_bookings' => 0,
            'lost_revenue' => 0,
            'realized_revenue' => 0,
            'pending_revenue' => 0,
            'confirmed_revenue' => 0,
            'cancellation_rate' => 0,
            'completion_rate' => 0,
            'confirmation_rate' => 0,
            'pending_rate' => 0,
            'total_potential_revenue' => 0,
            'revenue_at_risk' => 0,
            'revenue_at_risk_percentage' => 0,
            'booking_efficiency_index' => 0,
            'overall_risk_level' => 'low',
            'recommendations' => ['Tidak ada data untuk periode ini']
        ];
        $metrics = array_merge($default_metrics, $metrics ?: []);
    }

    return $metrics;
}

// Get metrics with improved calculation
$metrics = get_improved_metrics($conn, $filter_year, $filter_month, $filter_city, $filter_hotel);

// =====================================================================
// PERBAIKAN 3: TOP CANCELLING HOTELS DENGAN RISK INDEX
// =====================================================================

function get_top_cancelling_hotels($conn, $year, $month, $city = 'all')
{
    $query = "SELECT 
        h.hotel_id,
        h.nama_hotel,
        h.kota,
        COUNT(*) as total_bookings,
        COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancelled_bookings,
        COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completed_bookings,
        COUNT(CASE WHEN b.status = 'Pending' THEN 1 END) as pending_bookings,
        SUM(CASE WHEN b.status = 'Cancelled' THEN b.total_harga ELSE 0 END) as lost_revenue,
        SUM(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE 0 END) as realized_revenue,
        AVG(CASE WHEN b.status = 'Cancelled' THEN b.total_harga ELSE NULL END) as avg_cancelled_value,
        AVG(CASE WHEN b.status = 'Completed' THEN b.total_harga ELSE NULL END) as avg_completed_value
        
        FROM booking_hotel b
        LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
        WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?";

    $params = [$year, $month];
    $types = "ii";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    $query .= " GROUP BY h.hotel_id, h.nama_hotel, h.kota
                HAVING total_bookings >= 3 AND cancelled_bookings > 0
                ORDER BY lost_revenue DESC, cancellation_rate DESC
                LIMIT 8"; // Increased limit to see more hotels

    $stmt = $conn->prepare($query);
    $data = [];

    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            // Calculate cancellation rate
            $row['cancellation_rate'] = $row['total_bookings'] > 0 
                ? round(($row['cancelled_bookings'] / $row['total_bookings']) * 100, 1) 
                : 0;
            
            // Calculate completion rate
            $row['completion_rate'] = $row['total_bookings'] > 0 
                ? round(($row['completed_bookings'] / $row['total_bookings']) * 100, 1) 
                : 0;
            
            // Calculate Hotel Risk Index and Level
            $row['hotel_risk_index'] = calculate_hotel_risk_level($row);
            $row['risk_level'] = determine_risk_level($row);
            
            // Generate recommendations
            $row['recommendations'] = generate_risk_recommendation($row['risk_level'], $row);
            
            $data[] = $row;
        }
        $stmt->close();
    }

    // Sort by risk level (critical first)
    usort($data, function($a, $b) {
        $risk_order = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        return ($risk_order[$b['risk_level']] ?? 0) <=> ($risk_order[$a['risk_level']] ?? 0);
    });

    return $data;
}

$top_cancelling_hotels = get_top_cancelling_hotels($conn, $filter_year, $filter_month, $filter_city);

// =====================================================================
// PERBAIKAN 4: HIGH RISK PENDING BOOKINGS DENGAN SCORE DETAIL
// =====================================================================

function get_high_risk_pending($conn, $year, $month, $city = 'all')
{
    $query = "SELECT 
        b.booking_id,
        b.customer_name,
        b.customer_id,
        h.nama_hotel,
        t.nama_tipe,
        b.total_harga,
        b.check_in,
        b.check_out,
        b.tanggal_booking,
        DATEDIFF(b.check_in, CURDATE()) as days_until_checkin,
        b.jumlah_kamar,
        b.metode_pembayaran,
        h.kota
        
        FROM booking_hotel b
        LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
        LEFT JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
        WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
        AND b.status = 'Pending'
        AND DATEDIFF(b.check_in, CURDATE()) <= 21"; // Extended to 21 days for better insight

    $params = [$year, $month];
    $types = "ii";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    $query .= " ORDER BY days_until_checkin ASC, b.total_harga DESC
                LIMIT 20"; // Increased limit

    $stmt = $conn->prepare($query);
    $data = [];

    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            // Calculate detailed risk score
            $row['risk_score'] = calculate_booking_risk_score($row);
            
            // Determine risk priority with new thresholds
            if ($row['risk_score'] >= 70) $row['risk_priority'] = 'critical';
            elseif ($row['risk_score'] >= 50) $row['risk_priority'] = 'high';
            elseif ($row['risk_score'] >= 30) $row['risk_priority'] = 'medium';
            else $row['risk_priority'] = 'low';
            
            // Generate action recommendations
            $row['actions'] = generate_risk_recommendation($row['risk_priority']);
            
            // Calculate urgency level
            if ($row['days_until_checkin'] <= 3) $row['urgency'] = 'Sangat Mendesak';
            elseif ($row['days_until_checkin'] <= 7) $row['urgency'] = 'Mendesak';
            elseif ($row['days_until_checkin'] <= 14) $row['urgency'] = 'Prioritas';
            else $row['urgency'] = 'Normal';
            
            $data[] = $row;
        }
        $stmt->close();
    }

    // Sort by risk priority (critical first)
    usort($data, function($a, $b) {
        $priority_order = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        return ($priority_order[$b['risk_priority']] ?? 0) <=> ($priority_order[$a['risk_priority']] ?? 0);
    });

    return $data;
}

$high_risk_pending = get_high_risk_pending($conn, $filter_year, $filter_month, $filter_city);

// =====================================================================
// FUNGSI LAINNYA (TIDAK BANYAK PERUBAHAN)
// =====================================================================

function get_cancellation_by_room_type($conn, $year, $month, $city = 'all')
{
    $query = "SELECT 
        t.tipe_id,
        t.nama_tipe,
        COUNT(*) as total_bookings,
        COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancelled_bookings,
        SUM(CASE WHEN b.status = 'Cancelled' THEN b.total_harga ELSE 0 END) as lost_revenue,
        AVG(CASE WHEN b.status = 'Cancelled' THEN b.total_harga ELSE NULL END) as avg_cancelled_value
        
        FROM booking_hotel b
        LEFT JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
        LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
        WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?";

    $params = [$year, $month];
    $types = "ii";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    $query .= " GROUP BY t.tipe_id, t.nama_tipe
                HAVING total_bookings > 0
                ORDER BY lost_revenue DESC
                LIMIT 6";

    $stmt = $conn->prepare($query);
    $data = [];

    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $row['cancellation_rate'] = $row['total_bookings'] > 0 
                ? round(($row['cancelled_bookings'] / $row['total_bookings']) * 100, 1) 
                : 0;
            
            // Determine risk level for room type
            if ($row['cancellation_rate'] >= 30) $row['risk_level'] = 'critical';
            elseif ($row['cancellation_rate'] >= 20) $row['risk_level'] = 'high';
            elseif ($row['cancellation_rate'] >= 10) $row['risk_level'] = 'medium';
            else $row['risk_level'] = 'low';
            
            $data[] = $row;
        }
        $stmt->close();
    }

    return $data;
}

$cancellation_by_room_type = get_cancellation_by_room_type($conn, $filter_year, $filter_month, $filter_city);

function get_daily_cancellations($conn, $year, $month, $city = 'all')
{
    $query = "SELECT 
        DAY(b.tanggal_booking) as day,
        DAYNAME(b.tanggal_booking) as day_name,
        COUNT(*) as total_bookings,
        COUNT(CASE WHEN b.status = 'Cancelled' THEN 1 END) as cancellations,
        COUNT(CASE WHEN b.status = 'Completed' THEN 1 END) as completions,
        COUNT(CASE WHEN b.status = 'Pending' THEN 1 END) as pending,
        SUM(CASE WHEN b.status = 'Cancelled' THEN b.total_harga ELSE 0 END) as lost_revenue
        
        FROM booking_hotel b
        LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
        WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?";

    $params = [$year, $month];
    $types = "ii";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    $query .= " GROUP BY DAY(b.tanggal_booking), DAYNAME(b.tanggal_booking)
                ORDER BY day";

    $stmt = $conn->prepare($query);
    $data = [];

    // Initialize array with all days
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    for ($i = 1; $i <= $days_in_month; $i++) {
        $data[$i] = [
            'day' => $i,
            'day_name' => date('l', strtotime("$year-$month-$i")),
            'total_bookings' => 0,
            'cancellations' => 0,
            'completions' => 0,
            'pending' => 0,
            'lost_revenue' => 0,
            'cancellation_rate' => 0
        ];
    }

    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $day = $row['day'];
            $row['cancellation_rate'] = $row['total_bookings'] > 0 
                ? round(($row['cancellations'] / $row['total_bookings']) * 100, 1) 
                : 0;
            $data[$day] = $row;
        }
        $stmt->close();
    }

    return array_values($data);
}

$daily_data = get_daily_cancellations($conn, $filter_year, $filter_month, $filter_city);

function get_recent_cancellations($conn, $year, $month, $city = 'all')
{
    $query = "SELECT 
        b.booking_id,
        b.customer_name,
        h.nama_hotel,
        t.nama_tipe,
        b.total_harga,
        b.check_in,
        b.check_out,
        b.tanggal_booking,
        b.catatan,
        h.kota,
        DATEDIFF(b.check_in, b.tanggal_booking) as days_booking_to_checkin
        
        FROM booking_hotel b
        LEFT JOIN hotel h ON b.hotel_id = h.hotel_id
        LEFT JOIN tipe_kamar t ON b.tipe_id = t.tipe_id
        WHERE YEAR(b.tanggal_booking) = ? AND MONTH(b.tanggal_booking) = ?
        AND b.status = 'Cancelled'";

    $params = [$year, $month];
    $types = "ii";

    if ($city !== 'all') {
        $query .= " AND h.kota = ?";
        $params[] = $city;
        $types .= "s";
    }

    $query .= " ORDER BY b.tanggal_booking DESC
                LIMIT 12";

    $stmt = $conn->prepare($query);
    $data = [];

    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            // Categorize cancellation reason based on days
            if ($row['days_booking_to_checkin'] <= 3) {
                $row['cancellation_type'] = 'Last Minute';
            } elseif ($row['days_booking_to_checkin'] <= 7) {
                $row['cancellation_type'] = 'Short Notice';
            } else {
                $row['cancellation_type'] = 'Planned';
            }
            
            $data[] = $row;
        }
        $stmt->close();
    }

    return $data;
}

$recent_cancellations = get_recent_cancellations($conn, $filter_year, $filter_month, $filter_city);

// Get system notifications
$query_notif = "SELECT COUNT(*) as notifications FROM booking_hotel WHERE status = 'Pending'";
$result_notif = $conn->query($query_notif);
if ($result_notif) {
    $notificationCount = $result_notif->fetch_assoc()['notifications'] ?? 0;
} else {
    $notificationCount = 0;
}

// Close connection
$conn->close();

// Calculate summary statistics
$total_high_risk_pending = count(array_filter($high_risk_pending, function($item) {
    return $item['risk_priority'] == 'critical' || $item['risk_priority'] == 'high';
}));

$total_critical_hotels = count(array_filter($top_cancelling_hotels, function($item) {
    return $item['risk_level'] == 'critical';
}));

// Function to convert month number to Indonesian month name
function get_indonesian_month($month_number) {
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
    return $months[(int)$month_number] ?? 'Bulan tidak valid';
}

// Function to convert day name to Indonesian
function get_indonesian_day($day_name) {
    $days = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
    ];
    return $days[$day_name] ?? $day_name;
}

// Convert daily data day names to Indonesian
foreach ($daily_data as &$day_data) {
    $day_data['day_name_indonesian'] = get_indonesian_day($day_data['day_name']);
}

// Get current Indonesian month name
$current_indonesian_month = get_indonesian_month($filter_month);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Risk Management | TripVerse Admin</title>
    <link rel="stylesheet" href="../css/dashboard.css?v=1.4.0" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
    :root {
        --primary-color: #3f51b5;
        --secondary-color: #ff9800;
        --success-color: #4caf50;
        --danger-color: #f44336;
        --warning-color: #ffc107;
        --info-color: #2196f3;
        --light-color: #f5f5f5;
        --dark-color: #212121;
        --text-color: #333;
        --text-light: #777;
        --border-radius: 12px;
        --box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        --box-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.12);
    }

    /* Animasi Keyframes */
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

    @keyframes fadeInLeft {
        from {
            opacity: 0;
            transform: translateX(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    @keyframes fadeInRight {
        from {
            opacity: 0;
            transform: translateX(20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    @keyframes fadeInScale {
        from {
            opacity: 0;
            transform: scale(0.95);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }

    @keyframes fadeInStagger {
        0% {
            opacity: 0;
            transform: translateY(15px);
        }
        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes pulse {
        0% {
            transform: scale(1);
        }
        50% {
            transform: scale(1.05);
        }
        100% {
            transform: scale(1);
        }
    }

    /* Modern Card Design dengan Animasi */
    .risk-section {
        background: white;
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
        padding: 25px;
        margin-bottom: 30px;
        border-left: 4px solid var(--primary-color);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        animation: fadeIn 0.4s ease-out;
        opacity: 0;
        animation-fill-mode: forwards;
    }

    /* Stagger animation untuk sections */
    .risk-section:nth-child(1) { animation-delay: 0.1s; }
    .risk-section:nth-child(2) { animation-delay: 0.2s; }
    .risk-section:nth-child(3) { animation-delay: 0.3s; }

    .risk-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(135deg, var(--primary-color), #5c6bc0);
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 0.5s ease;
    }

    .risk-section:hover::before {
        transform: scaleX(1);
    }

    .risk-section:hover {
        box-shadow: var(--box-shadow-hover);
        transform: translateY(-5px);
    }

    .risk-section h2 {
        margin-top: 0;
        margin-bottom: 20px;
        font-size: 18px;
        color: var(--dark-color);
        padding-bottom: 15px;
        border-bottom: 1px solid #eee;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: fadeInLeft 0.3s ease-out 0.2s both;
        opacity: 0;
    }

    /* Filter Controls dengan Animasi */
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
        animation: fadeIn 0.4s ease-out 0.15s both;
        opacity: 0;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        animation: fadeInScale 0.3s ease-out forwards;
        opacity: 0;
    }

    /* Stagger animation untuk filter groups */
    .filter-group:nth-child(1) { animation-delay: 0.25s; }
    .filter-group:nth-child(2) { animation-delay: 0.3s; }
    .filter-group:nth-child(3) { animation-delay: 0.35s; }
    .filter-group:nth-child(4) { animation-delay: 0.4s; }

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
        box-shadow: 0 0 0 3px rgba(63, 81, 181, 0.1);
        outline: none;
        transform: translateY(-1px);
    }

    .filter-actions {
        display: flex;
        gap: 12px;
        align-items: flex-end;
        animation: fadeInRight 0.3s ease-out 0.45s both;
        opacity: 0;
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
        animation: fadeInScale 0.3s ease-out 0.5s both;
        opacity: 0;
    }

    .filter-btn:hover {
        background: #303f9f;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(63, 81, 181, 0.3);
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
        animation: fadeInScale 0.3s ease-out 0.55s both;
        opacity: 0;
    }

    .reset-btn:hover {
        background: #5a6268;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
    }

    /* KPI Cards dengan Animasi */
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
        animation: fadeInStagger 0.3s ease-out forwards;
        opacity: 0;
    }

    /* Stagger animation untuk KPI cards */
    .kpi-card:nth-child(1) { animation-delay: 0.25s; }
    .kpi-card:nth-child(2) { animation-delay: 0.3s; }
    .kpi-card:nth-child(3) { animation-delay: 0.35s; }
    .kpi-card:nth-child(4) { animation-delay: 0.4s; }
    .kpi-card:nth-child(5) { animation-delay: 0.45s; }

    .kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
    }

    .kpi-card.primary::before {
        background: linear-gradient(135deg, var(--primary-color), #5c6bc0);
    }

    .kpi-card.success::before {
        background: linear-gradient(135deg, var(--success-color), #66bb6a);
    }

    .kpi-card.warning::before {
        background: linear-gradient(135deg, var(--warning-color), #ffa726);
    }

    .kpi-card.danger::before {
        background: linear-gradient(135deg, var(--danger-color), #ef5350);
    }

    .kpi-card.info::before {
        background: linear-gradient(135deg, var(--info-color), #42a5f5);
    }

    .kpi-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--box-shadow-hover);
        animation: pulse 2s ease-in-out infinite;
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
        animation: fadeInScale 0.4s ease-out 0.6s both;
        opacity: 0;
    }

    .kpi-card.primary .kpi-icon {
        background: linear-gradient(135deg, var(--primary-color), #5c6bc0);
    }

    .kpi-card.success .kpi-icon {
        background: linear-gradient(135deg, var(--success-color), #66bb6a);
    }

    .kpi-card.warning .kpi-icon {
        background: linear-gradient(135deg, var(--warning-color), #ffa726);
    }

    .kpi-card.danger .kpi-icon {
        background: linear-gradient(135deg, var(--danger-color), #ef5350);
    }

    .kpi-card.info .kpi-icon {
        background: linear-gradient(135deg, var(--info-color), #42a5f5);
    }

    .kpi-value {
        font-size: 28px;
        font-weight: 700;
        margin: 10px 0;
        color: var(--dark-color);
        animation: fadeIn 0.3s ease-out 0.7s both;
        opacity: 0;
    }

    .kpi-label {
        font-size: 14px;
        color: var(--text-light);
        margin-bottom: 5px;
        font-weight: 500;
        animation: fadeIn 0.3s ease-out 0.8s both;
        opacity: 0;
    }

    .kpi-note {
        font-size: 12px;
        color: var(--text-light);
        margin-top: 8px;
        animation: fadeIn 0.3s ease-out 0.9s both;
        opacity: 0;
    }

    /* Period Info dengan Animasi */
    .period-info {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
        font-size: 14px;
        color: var(--primary-color);
        font-weight: 500;
        padding: 15px 20px;
        background: rgba(63, 81, 181, 0.05);
        border-radius: var(--border-radius);
        border-left: 4px solid var(--primary-color);
        flex-wrap: wrap;
        animation: fadeIn 0.4s ease-out 0.3s both;
        opacity: 0;
    }

    .period-info span {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 5px 12px;
        background: white;
        border-radius: 20px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        animation: fadeInScale 0.2s ease-out;
    }

    /* Table Styling dengan Animasi */
    .risk-table {
        width: 100%;
        border-collapse: collapse;
        border-radius: var(--border-radius);
        overflow: hidden;
        animation: fadeIn 0.4s ease-out 0.4s both;
        opacity: 0;
    }

    .risk-table thead th {
        background: var(--primary-color);
        color: white;
        padding: 15px 12px;
        font-size: 14px;
        text-align: left;
        font-weight: 600;
        animation: slideInUp 0.3s ease-out 0.45s both;
        opacity: 0;
    }

    .risk-table tbody tr {
        animation: fadeInStagger 0.3s ease-out forwards;
        opacity: 0;
    }

    /* Stagger animation untuk table rows */
    .risk-table tbody tr:nth-child(1) { animation-delay: 0.5s; }
    .risk-table tbody tr:nth-child(2) { animation-delay: 0.55s; }
    .risk-table tbody tr:nth-child(3) { animation-delay: 0.6s; }
    .risk-table tbody tr:nth-child(4) { animation-delay: 0.65s; }
    .risk-table tbody tr:nth-child(5) { animation-delay: 0.7s; }
    .risk-table tbody tr:nth-child(6) { animation-delay: 0.75s; }
    .risk-table tbody tr:nth-child(7) { animation-delay: 0.8s; }
    .risk-table tbody tr:nth-child(8) { animation-delay: 0.85s; }

    .risk-table tbody tr:nth-child(even) {
        background-color: #f8f9fa;
    }

    .risk-table tbody tr:hover {
        background-color: #e8eaf6;
        transform: translateX(5px);
        transition: all 0.3s ease;
    }

    .risk-table td {
        font-size: 14px;
        color: var(--text-color);
        padding: 12px;
        vertical-align: middle;
    }

    /* Badges dengan Animasi */
    .badge {
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        display: inline-block;
        animation: fadeInScale 0.2s ease-out;
    }

    .badge-critical {
        background: #ffcdd2;
        color: #b71c1c;
        border: 1px solid #f44336;
    }

    .badge-high {
        background: #ffebee;
        color: var(--danger-color);
        border: 1px solid #ff9800;
    }

    .badge-medium {
        background: #fff3e0;
        color: var(--warning-color);
        border: 1px solid #ffc107;
    }

    .badge-low {
        background: #e8f5e8;
        color: var(--success-color);
        border: 1px solid #4caf50;
    }

    .badge-info {
        background: #e3f2fd;
        color: var(--info-color);
        border: 1px solid #2196f3;
    }

    .badge-pending {
        background: #fff3e0;
        color: var(--warning-color);
    }

    .badge-cancelled {
        background: #ffebee;
        color: var(--danger-color);
    }

    .badge-completed {
        background: #e8f5e8;
        color: var(--success-color);
    }

    /* Chart Container dengan Animasi */
    .chart-container {
        height: 300px;
        width: 100%;
        margin: 20px 0;
        position: relative;
        animation: fadeInScale 0.4s ease-out 0.5s both;
        opacity: 0;
    }

    /* Insights Grid dengan Animasi */
    .insights-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-top: 20px;
        animation: fadeIn 0.4s ease-out 0.6s both;
        opacity: 0;
    }

    .insight-card {
        background: white;
        padding: 20px;
        border-radius: var(--border-radius);
        box-shadow: var(--box-shadow);
        border-left: 4px solid;
        transition: all 0.3s ease;
        animation: fadeInStagger 0.3s ease-out forwards;
        opacity: 0;
    }

    /* Stagger animation untuk insight cards */
    .insight-card:nth-child(1) { animation-delay: 0.65s; }
    .insight-card:nth-child(2) { animation-delay: 0.7s; }
    .insight-card:nth-child(3) { animation-delay: 0.75s; }
    .insight-card:nth-child(4) { animation-delay: 0.8s; }

    .insight-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
    }

    .insight-card.success {
        border-left-color: var(--success-color);
    }

    .insight-card.warning {
        border-left-color: var(--warning-color);
    }

    .insight-card.danger {
        border-left-color: var(--danger-color);
    }

    .insight-card.info {
        border-left-color: var(--info-color);
    }

    .insight-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 15px;
        animation: fadeInLeft 0.3s ease-out 0.2s both;
        opacity: 0;
    }

    .insight-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--dark-color);
        margin: 0;
    }

    .insight-content {
        color: var(--text-color);
        font-size: 14px;
        line-height: 1.5;
        animation: fadeIn 0.3s ease-out 0.3s both;
        opacity: 0;
    }

    /* Risk Matrix dengan Animasi */
    .risk-matrix {
        overflow-x: auto;
        margin-top: 20px;
        animation: fadeIn 0.4s ease-out 0.7s both;
        opacity: 0;
    }

    .risk-matrix table {
        min-width: 800px;
        animation: slideInUp 0.4s ease-out 0.75s both;
        opacity: 0;
    }

    .risk-matrix th {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        padding: 15px;
        text-align: center;
        font-weight: 600;
        border: 1px solid #dee2e6;
        animation: fadeInStagger 0.3s ease-out forwards;
        opacity: 0;
    }

    /* Stagger animation untuk table headers */
    .risk-matrix th:nth-child(1) { animation-delay: 0.8s; }
    .risk-matrix th:nth-child(2) { animation-delay: 0.85s; }
    .risk-matrix th:nth-child(3) { animation-delay: 0.9s; }
    .risk-matrix th:nth-child(4) { animation-delay: 0.95s; }

    .risk-matrix td {
        padding: 12px;
        border: 1px solid #dee2e6;
        vertical-align: top;
        animation: fadeInScale 0.2s ease-out;
    }

    /* Progress Bars dengan Animasi */
    .risk-progress {
        height: 8px;
        background: #e9ecef;
        border-radius: 4px;
        overflow: hidden;
        margin: 5px 0;
        position: relative;
    }

    .risk-progress-fill {
        height: 100%;
        border-radius: 4px;
        transition: width 1.5s ease-out;
        position: relative;
        overflow: hidden;
    }

    .risk-progress-fill::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(90deg, 
            transparent 0%, 
            rgba(255, 255, 255, 0.4) 50%, 
            transparent 100%);
        animation: shimmer 2s infinite;
    }

    @keyframes shimmer {
        0% {
            transform: translateX(-100%);
        }
        100% {
            transform: translateX(100%);
        }
    }

    .progress-critical {
        background: linear-gradient(135deg, #f44336, #ef5350);
    }

    .progress-high {
        background: linear-gradient(135deg, #ff9800, #ffb74d);
    }

    .progress-medium {
        background: linear-gradient(135deg, #ffc107, #ffd54f);
    }

    .progress-low {
        background: linear-gradient(135deg, #4caf50, #66bb6a);
    }

    /* Score Display dengan Animasi */
    .score-display {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-weight: 600;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 12px;
        animation: fadeInScale 0.2s ease-out;
    }

    .score-critical { 
        background: #ffebee; 
        color: #b71c1c;
        animation: pulse 1.5s ease-in-out infinite;
    }
    .score-high { 
        background: #fff3e0; 
        color: #e65100;
    }
    .score-medium { 
        background: #fff8e1; 
        color: #ff8f00;
    }
    .score-low { 
        background: #e8f5e9; 
        color: #1b5e20;
    }

    /* Summary Stats dengan Animasi */
    .summary-stats {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
        flex-wrap: wrap;
        animation: fadeIn 0.4s ease-out 0.35s both;
        opacity: 0;
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
        animation: fadeInStagger 0.3s ease-out forwards;
        opacity: 0;
    }

    /* Stagger animation untuk stat boxes */
    .stat-box:nth-child(1) { animation-delay: 0.4s; }
    .stat-box:nth-child(2) { animation-delay: 0.45s; }
    .stat-box:nth-child(3) { animation-delay: 0.5s; }
    .stat-box:nth-child(4) { animation-delay: 0.55s; }

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
        animation: fadeInScale 0.3s ease-out 0.6s both;
        opacity: 0;
    }

    .stat-content {
        flex: 1;
        animation: fadeInLeft 0.3s ease-out 0.7s both;
        opacity: 0;
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

        .insights-grid {
            grid-template-columns: 1fr;
        }

        .summary-stats {
            flex-direction: column;
        }

        .stat-box {
            min-width: 100%;
        }

        /* Adjust animation delays for mobile */
        .risk-section:nth-child(2) { animation-delay: 0.15s; }
        .risk-section:nth-child(3) { animation-delay: 0.2s; }
        
        .kpi-card:nth-child(2) { animation-delay: 0.2s; }
        .kpi-card:nth-child(3) { animation-delay: 0.25s; }
        .kpi-card:nth-child(4) { animation-delay: 0.3s; }
        .kpi-card:nth-child(5) { animation-delay: 0.35s; }
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
        <div class="profile-header">
            <div class="profile-photo-section">
                <div class="profile-photo-container">
                    <img src="../uploads/<?php echo htmlspecialchars($foto); ?>"
                        alt="Profile Photo"
                        class="profile-photo"
                        id="profilePhoto"
                        onerror="this.src='../images/default.jpg'">

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
                            <span class="dropdown-text">Manage Account</span>
                            <span class="material-icons dropdown-arrow">expand_more</span>
                        </button>

                        <div class="dropdown-content" role="menu" aria-hidden="true">
                            <a href="profile.php" class="dropdown-item">
                                <span class="material-icons">person</span>
                                <span>Edit Profile</span>
                            </a>
                            <a href="logout.php" class="dropdown-item">
                                <span class="material-icons">logout</span>
                                <span>Logout</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <nav>
            <!-- EXECUTIVE OVERVIEW -->
            <a href="dashboard.php">
                <span class="material-icons">dashboard</span>
                <span>Executive Overview</span>
            </a>

            <!-- SUPPLIER APPROVAL -->
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a href="supplier_approvals.php"><span class="material-icons">how_to_reg</span><span>Supplier Approvals</span></a>
            <?php endif; ?>

            <!-- PROMO MANAGEMENT -->
            <a href="promo_management.php">
                <span class="material-icons">local_offer</span>
                <span>Promo Management</span>
            </a>

            <!-- ANALYTICS & INSIGHTS -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="analyticsDropdown">
                    <span class="material-icons">insights</span>
                    <span>Analytics & Insights</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="analyticsDropdown">
                    <a href="performance_analytics.php">
                        <span class="material-icons">analytics</span>
                        <span>Performance Analytics</span>
                    </a>
                    <a href="market_analysis.php">
                        <span class="material-icons">travel_explore</span>
                        <span>Market Intelligence</span>
                    </a>
                </div>
            </div>

            <!-- DECISION SUPPORT MODULES -->
            <div class="user-menu">
                <a href="#" class="booking-toggle" data-target="decisionDropdown">
                    <span class="material-icons">psychology</span>
                    <span>Decision Support</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu hidden" id="decisionDropdown">
                    <a href="revenue_optimization.php">
                        <span class="material-icons">monetization_on</span>
                        <span>Revenue Optimization</span>
                    </a>
                    <a href="pricing_strategy.php">
                        <span class="material-icons">price_change</span>
                        <span>Pricing Strategy</span>
                    </a>
                    <a href="occupancy_analysis.php">
                        <span class="material-icons">bed</span>
                        <span>Occupancy & Utilization</span>
                    </a>
                    <a href="alos_analysis.php">
                        <span class="material-icons">event</span>
                        <span>ALOS Analysis</span>
                    </a>
                </div>
            </div>

            <!-- RISK & FORECASTING -->
            <a href="risk_management.php" class="active">
                <span class="material-icons">security</span>
                <span>Risk Assessment</span>
            </a>

            <!-- CUSTOMER INTELLIGENCE -->
            <a href="customerdss.php">
                <span class="material-icons">group</span>
                <span>Customer Intelligence</span>
            </a>

            <!-- LOGOUT -->
            <a href="logout.php">
                <span class="material-icons">logout</span>
                <span>Logout</span>
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

                <div class="user-menu">
                    <img src="../uploads/<?php echo htmlspecialchars($foto); ?>" alt="User Avatar" class="user-avatar" />
                </div>
            </div>
        </header>

        <div class="risk-section">
            <h2><i class="material-icons">security</i> Risk Assessment</h2>

            <!-- Filter Controls -->
            <form method="GET" action="risk_management.php" class="filter-controls">
                <div class="filter-group">
                    <label for="filter_month"><i class="material-icons">calendar_today</i> Bulan</label>
                    <select id="filter_month" name="month" class="filter-select">
                        <?php 
                        $indonesian_months = [
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
                        
                        for ($m = 1; $m <= 12; $m++): 
                            $month_padded = str_pad($m, 2, '0', STR_PAD_LEFT);
                        ?>
                            <option value="<?= $month_padded ?>" <?= $filter_month == $month_padded ? 'selected' : '' ?>>
                                <?= $indonesian_months[$m] ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_year"><i class="material-icons">event</i> Tahun</label>
                    <select id="filter_year" name="year" class="filter-select">
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?= $year ?>" <?= $filter_year == $year ? 'selected' : '' ?>>
                                <?= $year ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_city"><i class="material-icons">location_city</i> Kota</label>
                    <select id="filter_city" name="city" class="filter-select">
                        <option value="all" <?= $filter_city == 'all' ? 'selected' : '' ?>>Semua Kota</option>
                        <?php foreach ($available_cities as $city): ?>
                            <option value="<?= $city ?>" <?= $filter_city == $city ? 'selected' : '' ?>>
                                <?= htmlspecialchars($city) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_hotel"><i class="material-icons">hotel</i> Hotel</label>
                    <select id="filter_hotel" name="hotel" class="filter-select">
                        <option value="all" <?= $filter_hotel == 'all' ? 'selected' : '' ?>>Semua Hotel</option>
                        <?php foreach ($available_hotels as $id => $name): ?>
                            <option value="<?= $id ?>" <?= $filter_hotel == $id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="filter-btn">
                        <i class="material-icons">filter_alt</i> Terapkan Filter
                    </button>
                    <a href="risk_management.php?month=<?= date('m') ?>&year=<?= date('Y') ?>&city=all&hotel=all" class="reset-btn">
                        <i class="material-icons">refresh</i> Reset
                    </a>
                </div>
            </form>

            <div class="period-info">
                <span>
                    <i class="material-icons">calendar_today</i>
                    Periode Analisis: <strong><?= get_indonesian_month($filter_month) . ' ' . $filter_year ?></strong>
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
            </div>

            <!-- Summary Statistics -->
            <div class="summary-stats">
                <div class="stat-box">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f44336, #ef5350);">
                        <i class="material-icons">warning</i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $total_critical_hotels ?></div>
                        <div class="stat-label">Hotel dengan Risiko KRITIS</div>
                    </div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #ff9800, #ffb74d);">
                        <i class="material-icons">priority_high</i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $total_high_risk_pending ?></div>
                        <div class="stat-label">Booking Berisiko TINGGI</div>
                    </div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #ffc107, #ffd54f);">
                        <i class="material-icons">monetization_on</i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $metrics['cancellation_rate'] ?? 0 ?>%</div>
                        <div class="stat-label">Tingkat Pembatalan</div>
                    </div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #4caf50, #66bb6a);">
                        <i class="material-icons">check_circle</i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $metrics['booking_efficiency_index'] ?? 0 ?>%</div>
                        <div class="stat-label">Booking Efficiency Index</div>
                    </div>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="kpi-grid">
                <div class="kpi-card <?= ($metrics['cancellation_rate'] ?? 0) >= 30 ? 'danger' : (($metrics['cancellation_rate'] ?? 0) >= 20 ? 'warning' : (($metrics['cancellation_rate'] ?? 0) >= 10 ? 'info' : 'success')) ?>">
                    <div class="kpi-icon">
                        <i class="material-icons">close</i>
                    </div>
                    <div class="kpi-label">Tingkat Pembatalan</div>
                    <div class="kpi-value"><?= $metrics['cancellation_rate'] ?? 0 ?>%</div>
                    <div class="kpi-note">
                        <?= $metrics['cancelled_bookings'] ?? 0 ?> dari <?= $metrics['total_bookings'] ?? 0 ?> booking
                        <div class="risk-progress">
                            <div class="risk-progress-fill <?= 'progress-' . ($metrics['overall_risk_level'] ?? 'low') ?>" 
                                 style="width: <?= min(100, ($metrics['cancellation_rate'] ?? 0) * 2) ?>%"></div>
                        </div>
                    </div>
                </div>

                <div class="kpi-card success">
                    <div class="kpi-icon">
                        <i class="material-icons">check_circle</i>
                    </div>
                    <div class="kpi-label">Booking Efficiency</div>
                    <div class="kpi-value"><?= $metrics['booking_efficiency_index'] ?? 0 ?>%</div>
                    <div class="kpi-note">
                        Efisiensi konversi booking
                        <div class="risk-progress">
                            <div class="risk-progress-fill progress-low" 
                                 style="width: <?= $metrics['booking_efficiency_index'] ?? 0 ?>%"></div>
                        </div>
                    </div>
                </div>

                <div class="kpi-card <?= ($metrics['revenue_at_risk_percentage'] ?? 0) >= 30 ? 'danger' : (($metrics['revenue_at_risk_percentage'] ?? 0) >= 20 ? 'warning' : 'info') ?>">
                    <div class="kpi-icon">
                        <i class="material-icons">money_off</i>
                    </div>
                    <div class="kpi-label">Pendapatan Hilang</div>
                    <div class="kpi-value">Rp <?= number_format($metrics['lost_revenue'] ?? 0, 0, ',', '.') ?></div>
                    <div class="kpi-note">
                        Kerugian karena pembatalan
                        <div class="risk-progress">
                            <div class="risk-progress-fill <?= 'progress-' . ($metrics['overall_risk_level'] ?? 'low') ?>" 
                                 style="width: <?= min(100, ($metrics['revenue_at_risk_percentage'] ?? 0)) ?>%"></div>
                        </div>
                    </div>
                </div>

                <div class="kpi-card <?= ($metrics['revenue_at_risk_percentage'] ?? 0) >= 25 ? 'danger' : (($metrics['revenue_at_risk_percentage'] ?? 0) >= 15 ? 'warning' : 'info') ?>">
                    <div class="kpi-icon">
                        <i class="material-icons">warning</i>
                    </div>
                    <div class="kpi-label">Pendapatan Berisiko</div>
                    <div class="kpi-value"><?= $metrics['revenue_at_risk_percentage'] ?? 0 ?>%</div>
                    <div class="kpi-note">
                        Rp <?= number_format($metrics['revenue_at_risk'] ?? 0, 0, ',', '.') ?> berisiko
                        <div class="risk-progress">
                            <div class="risk-progress-fill <?= 'progress-' . ($metrics['overall_risk_level'] ?? 'low') ?>" 
                                 style="width: <?= $metrics['revenue_at_risk_percentage'] ?? 0 ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Risk Matrix -->
            <div class="risk-section">
                <h2><i class="material-icons">gradient</i> Matriks Level Risiko</h2>
                
                <div class="risk-matrix">
                    <table>
                        <thead>
                            <tr>
                                <th>LEVEL</th>
                                <th>TINGKAT PEMBATALAN</th>
                                <th>KERUGIAN FINANSIAL</th>
                                <th>BOOKING PRIORITAS</th>
                                <th>REVENUE AT RISK</th>
                                <th>ACTION REQUIRED</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- KRITIS -->
                            <tr style="background: rgba(244, 67, 54, 0.05);">
                                <td style="text-align: center;">
                                    <span class="badge badge-critical">KRITIS</span>
                                </td>
                                <td style="text-align: center;">≥ 30%</td>
                                <td style="text-align: center;">≥ Rp 10jt</td>
                                <td style="text-align: center;">Check-in ≤ 2 hari</td>
                                <td style="text-align: center;">≥ 30%</td>
                                <td>
                                    <span style="color: #f44336; font-weight: bold;">IMMEDIATE ACTION</span><br>
                                    • Follow-up dalam 1 jam<br>
                                    • Escalate ke management<br>
                                    • Sediakan alternatif
                                </td>
                            </tr>
                            
                            <!-- TINGGI -->
                            <tr style="background: rgba(255, 152, 0, 0.05);">
                                <td style="text-align: center;">
                                    <span class="badge badge-high">TINGGI</span>
                                </td>
                                <td style="text-align: center;">20% - 29.9%</td>
                                <td style="text-align: center;">Rp 5jt - 9.9jt</td>
                                <td style="text-align: center;">Check-in 3-7 hari</td>
                                <td style="text-align: center;">25% - 29.9%</td>
                                <td>
                                    <span style="color: #ff9800; font-weight: bold;">URGENT ACTION</span><br>
                                    • Follow-up dalam 4 jam<br>
                                    • Prioritas konfirmasi<br>
                                    • Monitoring harian
                                </td>
                            </tr>
                            
                            <!-- SEDANG -->
                            <tr style="background: rgba(255, 193, 7, 0.05);">
                                <td style="text-align: center;">
                                    <span class="badge badge-medium">SEDANG</span>
                                </td>
                                <td style="text-align: center;">10% - 19.9%</td>
                                <td style="text-align: center;">Rp 1jt - 4.9jt</td>
                                <td style="text-align: center;">Check-in 8-14 hari</td>
                                <td style="text-align: center;">15% - 24.9%</td>
                                <td>
                                    <span style="color: #ffc107; font-weight: bold;">MONITORING</span><br>
                                    • Follow-up dalam 24 jam<br>
                                    • Review mingguan<br>
                                    • Standard procedure
                                </td>
                            </tr>
                            
                            <!-- RENDAH -->
                            <tr style="background: rgba(76, 175, 80, 0.05);">
                                <td style="text-align: center;">
                                    <span class="badge badge-low">RENDAH</span>
                                </td>
                                <td style="text-align: center;">&lt; 10%</td>
                                <td style="text-align: center;">&lt; Rp 1jt</td>
                                <td style="text-align: center;">Check-in &gt; 14 hari</td>
                                <td style="text-align: center;">&lt; 15%</td>
                                <td>
                                    <span style="color: #4caf50; font-weight: bold;">NORMAL</span><br>
                                    • Follow-up rutin<br>
                                    • Monthly review<br>
                                    • Maintain quality
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Daily Cancellation Chart -->
            <div class="risk-section">
                <h2><i class="material-icons">trending_up</i> Tren Pembatalan Harian</h2>
                <div class="chart-container">
                    <canvas id="dailyChart"></canvas>
                </div>
            </div>

            <!-- Top Cancelling Hotels -->
            <div class="risk-section">
                <h2><i class="material-icons">warning</i> Hotel dengan Pembatalan Tertinggi</h2>
                <?php if (!empty($top_cancelling_hotels)): ?>
                    <table class="risk-table">
                        <thead>
                            <tr>
                                <th>Hotel</th>
                                <th>Kota</th>
                                <th>Total Booking</th>
                                <th>Pembatalan</th>
                                <th>Tingkat Pembatalan</th>
                                <th>Kerugian</th>
                                <th>Level Risiko</th>
                                <th>Rekomendasi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_cancelling_hotels as $hotel): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($hotel['nama_hotel']) ?></strong></td>
                                    <td><?= htmlspecialchars($hotel['kota']) ?></td>
                                    <td><?= $hotel['total_bookings'] ?></td>
                                    <td><?= $hotel['cancelled_bookings'] ?></td>
                                    <td>
                                        <span class="badge badge-<?= $hotel['risk_level'] ?>">
                                            <?= $hotel['cancellation_rate'] ?>%
                                        </span>
                                        <div class="risk-progress">
                                            <div class="risk-progress-fill progress-<?= $hotel['risk_level'] ?>" 
                                                 style="width: <?= min(100, $hotel['cancellation_rate']) ?>%"></div>
                                        </div>
                                    </td>
                                    <td><strong>Rp <?= number_format($hotel['lost_revenue'], 0, ',', '.') ?></strong></td>
                                    <td>
                                        <?php if ($hotel['risk_level'] == 'critical'): ?>
                                            <span class="badge badge-critical">KRITIS</span>
                                        <?php elseif ($hotel['risk_level'] == 'high'): ?>
                                            <span class="badge badge-high">TINGGI</span>
                                        <?php elseif ($hotel['risk_level'] == 'medium'): ?>
                                            <span class="badge badge-medium">SEDANG</span>
                                        <?php else: ?>
                                            <span class="badge badge-low">RENDAH</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small style="color: var(--text-light);">
                                            <?php if (!empty($hotel['recommendations'])): ?>
                                                <?= $hotel['recommendations'][0] ?? 'Monitor' ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-light);">
                        <i class="material-icons" style="font-size: 48px; margin-bottom: 15px; color: var(--success-color);">check_circle</i>
                        <h3 style="color: var(--success-color);">Tidak Ada Hotel dengan Pembatalan Tinggi</h3>
                        <p>Semua hotel memiliki tingkat pembatalan yang normal untuk periode ini.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- High Risk Pending Bookings -->
            <div class="risk-section">
                <h2><i class="material-icons">priority_high</i> Booking Berisiko Tinggi (Check-in ≤ 21 hari)</h2>
                <?php if (!empty($high_risk_pending)): ?>
                    <table class="risk-table">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Customer</th>
                                <th>Hotel</th>
                                <th>Total</th>
                                <th>Check-in</th>
                                <th>Hari Lagi</th>
                                <th>Risk Score</th>
                                <th>Prioritas</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($high_risk_pending as $booking): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($booking['booking_id']) ?></strong>
                                        <div style="font-size: 11px; color: var(--text-light);">
                                            <?= date('d M Y', strtotime($booking['tanggal_booking'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($booking['customer_name']) ?>
                                        <div style="font-size: 11px; color: var(--text-light);">
                                            <?= htmlspecialchars($booking['customer_id']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($booking['nama_hotel']) ?>
                                        <div style="font-size: 11px; color: var(--text-light);">
                                            <?= htmlspecialchars($booking['nama_tipe']) ?>
                                        </div>
                                    </td>
                                    <td><strong>Rp <?= number_format($booking['total_harga'], 0, ',', '.') ?></strong></td>
                                    <td>
                                        <?= date('d M', strtotime($booking['check_in'])) ?>
                                        <div style="font-size: 11px; color: var(--text-light);">
                                            <?= $booking['urgency'] ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($booking['days_until_checkin'] <= 3): ?>
                                            <span class="badge badge-critical"><?= $booking['days_until_checkin'] ?> hari</span>
                                        <?php elseif ($booking['days_until_checkin'] <= 7): ?>
                                            <span class="badge badge-high"><?= $booking['days_until_checkin'] ?> hari</span>
                                        <?php elseif ($booking['days_until_checkin'] <= 14): ?>
                                            <span class="badge badge-medium"><?= $booking['days_until_checkin'] ?> hari</span>
                                        <?php else: ?>
                                            <span class="badge badge-low"><?= $booking['days_until_checkin'] ?> hari</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="score-display score-<?= $booking['risk_priority'] ?>">
                                            <?= $booking['risk_score'] ?> / 100
                                        </div>
                                        <div class="risk-progress">
                                            <div class="risk-progress-fill progress-<?= $booking['risk_priority'] ?>" 
                                                 style="width: <?= $booking['risk_score'] ?>%"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($booking['risk_priority'] == 'critical'): ?>
                                            <span class="badge badge-critical">KRITIS</span>
                                        <?php elseif ($booking['risk_priority'] == 'high'): ?>
                                            <span class="badge badge-high">TINGGI</span>
                                        <?php elseif ($booking['risk_priority'] == 'medium'): ?>
                                            <span class="badge badge-medium">SEDANG</span>
                                        <?php else: ?>
                                            <span class="badge badge-low">RENDAH</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small style="color: var(--text-light);">
                                            <?php if (!empty($booking['actions'])): ?>
                                                <?= $booking['actions'][0] ?? 'Follow-up' ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-light);">
                        <i class="material-icons" style="font-size: 48px; margin-bottom: 15px; color: var(--success-color);">check_circle</i>
                        <h3 style="color: var(--success-color);">Tidak Ada Booking Berisiko Tinggi</h3>
                        <p>Tidak ada booking pending dengan check-in dalam 21 hari ke depan.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Cancellation by Room Type -->
            <div class="risk-section">
                <h2><i class="material-icons">category</i> Pembatalan Berdasarkan Tipe Kamar</h2>
                <?php if (!empty($cancellation_by_room_type)): ?>
                    <table class="risk-table">
                        <thead>
                            <tr>
                                <th>Tipe Kamar</th>
                                <th>Total Booking</th>
                                <th>Pembatalan</th>
                                <th>Tingkat Pembatalan</th>
                                <th>Kerugian</th>
                                <th>Rata-rata Nilai</th>
                                <th>Level Risiko</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cancellation_by_room_type as $room_type): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($room_type['nama_tipe']) ?></strong></td>
                                    <td><?= $room_type['total_bookings'] ?></td>
                                    <td><?= $room_type['cancelled_bookings'] ?></td>
                                    <td>
                                        <?php if ($room_type['cancellation_rate'] >= 30): ?>
                                            <span class="badge badge-critical"><?= $room_type['cancellation_rate'] ?>%</span>
                                        <?php elseif ($room_type['cancellation_rate'] >= 20): ?>
                                            <span class="badge badge-high"><?= $room_type['cancellation_rate'] ?>%</span>
                                        <?php elseif ($room_type['cancellation_rate'] >= 10): ?>
                                            <span class="badge badge-medium"><?= $room_type['cancellation_rate'] ?>%</span>
                                        <?php else: ?>
                                            <span class="badge badge-low"><?= $room_type['cancellation_rate'] ?>%</span>
                                        <?php endif; ?>
                                        <div class="risk-progress">
                                            <div class="risk-progress-fill progress-<?= $room_type['risk_level'] ?>" 
                                                 style="width: <?= min(100, $room_type['cancellation_rate']) ?>%"></div>
                                        </div>
                                    </td>
                                    <td><strong>Rp <?= number_format($room_type['lost_revenue'], 0, ',', '.') ?></strong></td>
                                    <td>Rp <?= number_format($room_type['avg_cancelled_value'] ?? 0, 0, ',', '.') ?></td>
                                    <td>
                                        <?php if ($room_type['risk_level'] == 'critical'): ?>
                                            <span class="badge badge-critical">KRITIS</span>
                                        <?php elseif ($room_type['risk_level'] == 'high'): ?>
                                            <span class="badge badge-high">TINGGI</span>
                                        <?php elseif ($room_type['risk_level'] == 'medium'): ?>
                                            <span class="badge badge-medium">SEDANG</span>
                                        <?php else: ?>
                                            <span class="badge badge-low">RENDAH</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-light);">
                        <i class="material-icons" style="font-size: 48px; margin-bottom: 15px; color: var(--success-color);">check_circle</i>
                        <h3 style="color: var(--success-color);">Tidak Ada Data Pembatalan Tipe Kamar</h3>
                        <p>Tidak ada pembatalan berdasarkan tipe kamar untuk periode ini.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Cancellations -->
            <div class="risk-section">
                <h2><i class="material-icons">history</i> Pembatalan Terbaru</h2>
                <?php if (!empty($recent_cancellations)): ?>
                    <table class="risk-table">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Customer</th>
                                <th>Hotel</th>
                                <th>Tipe Kamar</th>
                                <th>Total</th>
                                <th>Check-in</th>
                                <th>Tanggal Booking</th>
                                <th>Jenis Pembatalan</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_cancellations as $cancellation): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($cancellation['booking_id']) ?></strong></td>
                                    <td><?= htmlspecialchars($cancellation['customer_name']) ?></td>
                                    <td>
                                        <?= htmlspecialchars($cancellation['nama_hotel']) ?>
                                        <div style="font-size: 11px; color: var(--text-light);">
                                            <?= htmlspecialchars($cancellation['kota']) ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($cancellation['nama_tipe']) ?></td>
                                    <td><strong>Rp <?= number_format($cancellation['total_harga'], 0, ',', '.') ?></strong></td>
                                    <td><?= date('d M Y', strtotime($cancellation['check_in'])) ?></td>
                                    <td>
                                        <?= date('d', strtotime($cancellation['tanggal_booking'])) ?> 
                                        <?= get_indonesian_month(date('m', strtotime($cancellation['tanggal_booking']))) ?> 
                                        <?= date('Y H:i', strtotime($cancellation['tanggal_booking'])) ?>
                                    </td>
                                    <td>
                                        <?php if ($cancellation['cancellation_type'] == 'Last Minute'): ?>
                                            <span class="badge badge-critical">Last Minute</span>
                                        <?php elseif ($cancellation['cancellation_type'] == 'Short Notice'): ?>
                                            <span class="badge badge-high">Short Notice</span>
                                        <?php else: ?>
                                            <span class="badge badge-info">Planned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($cancellation['catatan'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-light);">
                        <i class="material-icons" style="font-size: 48px; margin-bottom: 15px; color: var(--success-color);">check_circle</i>
                        <h3 style="color: var(--success-color);">Tidak Ada Pembatalan Terbaru</h3>
                        <p>Tidak ada pembatalan untuk periode ini.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Insights & Recommendations -->
            <div class="risk-section">
                <h2><i class="material-icons">lightbulb</i> Insight & Rekomendasi</h2>
                <div class="insights-grid">
                    <div class="insight-card <?= $metrics['overall_risk_level'] == 'critical' ? 'danger' : ($metrics['overall_risk_level'] == 'high' ? 'warning' : 'success') ?>">
                        <div class="insight-header">
                            <i class="material-icons">security</i>
                            <h3 class="insight-title">Status Risiko Keseluruhan: <?= strtoupper($metrics['overall_risk_level'] ?? 'low') ?></h3>
                        </div>
                        <div class="insight-content">
                            <p><strong>Analisis:</strong> 
                                <?php if (($metrics['cancellation_rate'] ?? 0) >= 30): ?>
                                    Tingkat pembatalan sangat tinggi (<?= $metrics['cancellation_rate'] ?>%) memerlukan perhatian segera.
                                <?php elseif (($metrics['cancellation_rate'] ?? 0) >= 20): ?>
                                    Tingkat pembatalan tinggi (<?= $metrics['cancellation_rate'] ?>%) memerlukan monitoring intensif.
                                <?php elseif (($metrics['cancellation_rate'] ?? 0) >= 10): ?>
                                    Tingkat pembatalan sedang (<?= $metrics['cancellation_rate'] ?>%) dalam batas wajar.
                                <?php else: ?>
                                    Tingkat pembatalan rendah (<?= $metrics['cancellation_rate'] ?>%) menunjukkan performa baik.
                                <?php endif; ?>
                            </p>
                            <p><strong>Rekomendasi Utama:</strong></p>
                            <ul style="margin: 10px 0; padding-left: 20px;">
                                <?php foreach ($metrics['recommendations'] ?? ['Monitor performa'] as $rec): ?>
                                    <li><?= $rec ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>

                    <div class="insight-card <?= ($metrics['revenue_at_risk_percentage'] ?? 0) > 25 ? 'danger' : (($metrics['revenue_at_risk_percentage'] ?? 0) > 15 ? 'warning' : 'info') ?>">
                        <div class="insight-header">
                            <i class="material-icons">monetization_on</i>
                            <h3 class="insight-title">Manajemen Pendapatan</h3>
                        </div>
                        <div class="insight-content">
                            <p><strong>Pendapatan Berisiko:</strong> <?= $metrics['revenue_at_risk_percentage'] ?>% (Rp <?= number_format($metrics['revenue_at_risk'] ?? 0, 0, ',', '.') ?>)</p>
                            <p><strong>Kerugian Aktual:</strong> Rp <?= number_format($metrics['lost_revenue'] ?? 0, 0, ',', '.') ?></p>
                            <p><strong>Rekomendasi:</strong></p>
                            <ul style="margin: 10px 0; padding-left: 20px;">
                                <li>Fokus pada konfirmasi booking bernilai tinggi (> Rp 2jt)</li>
                                <li>Implementasi deposit untuk booking jangka panjang</li>
                                <li>Review cancellation policy untuk mengurangi kerugian</li>
                                <li>Optimalkan konversi booking pending ke confirmed</li>
                            </ul>
                        </div>
                    </div>

                    <div class="insight-card <?= $total_critical_hotels > 0 ? 'danger' : ($total_high_risk_pending > 0 ? 'warning' : 'success') ?>">
                        <div class="insight-header">
                            <i class="material-icons">priority_high</i>
                            <h3 class="insight-title">Prioritas Tindakan</h3>
                        </div>
                        <div class="insight-content">
                            <p><strong>Hotel Berisiko:</strong> <?= $total_critical_hotels ?> hotel dengan risiko KRITIS</p>
                            <p><strong>Booking Prioritas:</strong> <?= $total_high_risk_pending ?> booking memerlukan tindakan segera</p>
                            <p><strong>Rekomendasi:</strong></p>
                            <ul style="margin: 10px 0; padding-left: 20px;">
                                <li>Prioritaskan follow-up untuk booking dengan check-in ≤ 3 hari</li>
                                <li>Review partnership dengan hotel berisiko tinggi</li>
                                <li>Implementasi sistem reminder otomatis</li>
                                <li>Alokasikan tim khusus untuk handling booking prioritas</li>
                            </ul>
                        </div>
                    </div>

                    <div class="insight-card info">
                        <div class="insight-header">
                            <i class="material-icons">trending_up</i>
                            <h3 class="insight-title">Analisis Tren</h3>
                        </div>
                        <div class="insight-content">
                            <p><strong>Booking Efficiency:</strong> <?= $metrics['booking_efficiency_index'] ?>%</p>
                            <p><strong>Avg. Booking Value:</strong> Rp <?= number_format($metrics['avg_booking_value'] ?? 0, 0, ',', '.') ?></p>
                            <p><strong>Rekomendasi Jangka Panjang:</strong></p>
                            <ul style="margin: 10px 0; padding-left: 20px;">
                                <li>Improve customer experience untuk mengurangi pembatalan</li>
                                <li>Implementasi loyalty program untuk repeat customers</li>
                                <li>Analisis penyebab pembatalan per hotel/tipe kamar</li>
                                <li>Optimalkan pricing strategy berdasarkan analisis risiko</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Daily Chart
            const dailyCtx = document.getElementById('dailyChart');
            if (dailyCtx) {
                const dailyLabels = <?= json_encode(array_column($daily_data, 'day')) ?>;
                const cancellationData = <?= json_encode(array_column($daily_data, 'cancellations')) ?>;
                const completionData = <?= json_encode(array_column($daily_data, 'completions')) ?>;
                const cancellationRateData = <?= json_encode(array_column($daily_data, 'cancellation_rate')) ?>;

                // Convert day names to Indonesian for tooltips
                const indonesianDays = <?= json_encode(array_column($daily_data, 'day_name_indonesian')) ?>;

                new Chart(dailyCtx, {
                    type: 'line',
                    data: {
                        labels: dailyLabels.map(day => `Hari ${day}`),
                        datasets: [{
                                label: 'Pembatalan',
                                data: cancellationData,
                                borderColor: '#f44336',
                                backgroundColor: 'rgba(244, 67, 54, 0.1)',
                                tension: 0.3,
                                fill: true,
                                borderWidth: 2,
                                yAxisID: 'y'
                            },
                            {
                                label: 'Penyelesaian',
                                data: completionData,
                                borderColor: '#4caf50',
                                backgroundColor: 'rgba(76, 175, 80, 0.1)',
                                tension: 0.3,
                                fill: true,
                                borderWidth: 2,
                                yAxisID: 'y'
                            },
                            {
                                label: 'Tingkat Pembatalan (%)',
                                data: cancellationRateData,
                                borderColor: '#ff9800',
                                backgroundColor: 'rgba(255, 152, 0, 0.1)',
                                tension: 0.3,
                                fill: false,
                                borderWidth: 2,
                                yAxisID: 'y1'
                            }
                        ]
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
                                }
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                callbacks: {
                                    title: function(tooltipItems) {
                                        const dayIndex = tooltipItems[0].dataIndex;
                                        const dayNumber = dailyLabels[dayIndex];
                                        const indonesianDay = indonesianDays[dayIndex];
                                        return `Hari ${dayNumber} (${indonesianDay})`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Jumlah Booking',
                                    font: {
                                        weight: 'bold'
                                    }
                                },
                                ticks: {
                                    stepSize: 1
                                }
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Tingkat Pembatalan (%)',
                                    font: {
                                        weight: 'bold'
                                    }
                                },
                                min: 0,
                                max: 100,
                                grid: {
                                    drawOnChartArea: false,
                                },
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Hari dalam Bulan',
                                    font: {
                                        weight: 'bold'
                                    }
                                }
                            }
                        },
                        interaction: {
                            intersect: false,
                            mode: 'nearest'
                        }
                    }
                });
            }

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