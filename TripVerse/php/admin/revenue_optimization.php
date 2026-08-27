<?php
session_start();

// Cek login dan role admin
if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Akses ditolak! Halaman ini hanya untuk admin.'); window.location='../home.php';</script>";
    exit;
}

require __DIR__ . '/../connect.php';
require_once __DIR__ . '/../_lang.php';
$id_user = $_SESSION['id_user'];

// Ambil Data Admin
$stmt = $conn->prepare("SELECT username, email, first_name, last_name, profile_picture FROM user WHERE id_user = ?");
$stmt->bind_param("s", $id_user);
$stmt->execute();
$admin_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

$firstName = $admin_data['first_name'] ?? '-';
$lastName = $admin_data['last_name'] ?? '-';
$email = $admin_data['email'] ?? 'unknown@tripverse.com';
$foto = $admin_data['profile_picture'] ? '../../uploads/' . $admin_data['profile_picture'] : '../../images/default.jpg';

// Ambil notifikasi sistem
$notification_query = "SELECT COUNT(*) as notifications FROM booking_hotel WHERE status = 'Pending'";
$notification_result = $conn->query($notification_query);
$notificationCount = 0;
if ($notification_result) {
    $row = $notification_result->fetch_assoc();
    $notificationCount = $row['notifications'] ?? 0;
}

// ============================================
// PARAMETER FILTER REVENUE ANALYTICS - DIPERJELAS
// ============================================

// Tahun: Filter berdasarkan tahun booking (default: tahun sekarang)
$filter_year = $_GET['year'] ?? date('Y');

// Bulan: Filter berdasarkan bulan booking (0 = semua bulan)
$filter_month = $_GET['month'] ?? 0;

// Kota: Filter berdasarkan kota hotel (all = semua kota)
$filter_city = $_GET['city'] ?? 'all';

// Hotel: Filter berdasarkan hotel spesifik (all = semua hotel)
$filter_hotel = $_GET['hotel'] ?? 'all';

// Periode Analisis: Rentang waktu untuk analisis tren (monthly/yearly)
$filter_period = $_GET['period'] ?? 'monthly';

// Tampilan: Jenis analisis yang ditampilkan (overview/trends/comparison)
$filter_view = $_GET['view'] ?? 'overview';

// ============================================
// FUNGSI FILTER VALIDATION & PROCESSING
// ============================================

// Handle AJAX request untuk mendapatkan hotel berdasarkan kota
if (isset($_GET['getHotelsByCity'])) {
    $city = $_GET['getHotelsByCity'];
    $hotels = getAvailableHotels($conn, $city);
    header('Content-Type: application/json');
    echo json_encode($hotels);
    exit;
}

// Validasi parameter untuk keamanan
function validateFilterParameters($year, $month, $city, $hotel, $period, $view)
{
    // Validasi tahun (harus angka 4 digit atau 'all')
    if ($year != 'all' && (!is_numeric($year) || strlen($year) != 4 || $year < 2000 || $year > 2100)) {
        $year = date('Y');
    }

    // Validasi bulan (0-12, 0 = semua bulan)
    if (!is_numeric($month) || $month < 0 || $month > 12) {
        $month = 0;
    }

    // Validasi kota (hanya huruf, angka, spasi, dan karakter khusus tertentu)
    if ($city != 'all' && !preg_match('/^[a-zA-Z0-9\s\-\'\.\,]+$/', $city)) {
        $city = 'all';
    }

    // Validasi hotel (ID hotel harus alfanumerik)
    if ($hotel != 'all' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $hotel)) {
        $hotel = 'all';
    }

    // Validasi periode
    $allowed_periods = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];
    if (!in_array($period, $allowed_periods)) {
        $period = 'monthly';
    }

    // Validasi view
    $allowed_views = ['overview', 'trends', 'comparison', 'forecasting'];
    if (!in_array($view, $allowed_views)) {
        $view = 'overview';
    }

    return [
        'year' => $year,
        'month' => $month,
        'city' => $city,
        'hotel' => $hotel,
        'period' => $period,
        'view' => $view
    ];
}

// Validasi semua parameter
$validated_filters = validateFilterParameters(
    $filter_year,
    $filter_month,
    $filter_city,
    $filter_hotel,
    $filter_period,
    $filter_view
);

// Gunakan parameter yang sudah divalidasi
$filter_year = $validated_filters['year'];
$filter_month = (int)$validated_filters['month'];
$filter_city = $validated_filters['city'];
$filter_hotel = $validated_filters['hotel'];
$filter_period = $validated_filters['period'];
$filter_view = $validated_filters['view'];

// ============================================
// FUNGSI ANALISIS REVENUE - DIPERBAIKI
// ============================================

// 1. ANALISIS REVENUE UTAMA dengan penjelasan fungsi
function getRevenueAnalysisData($conn, $filters)
{
    /*
    FUNGSI: Mendapatkan data analisis revenue utama berdasarkan filter
    PARAMETER:
      - $conn: Koneksi database
      - $filters: Array berisi parameter filter (year, month, city, hotel, period, view)
    OUTPUT: Array data revenue untuk semua hotel yang memenuhi kriteria filter
    */

    $data = [];

    // Bangun klausa WHERE berdasarkan filter dengan penjelasan
    $where_conditions = ["bh.status = 'Completed'"]; // Hanya booking yang completed
    $params = [];
    $types = "";

    // Filter berdasarkan tahun (jika dipilih)
    if ($filters['year'] != 0 && $filters['year'] != 'all') {
        $where_conditions[] = "YEAR(bh.tanggal_booking) = ?";
        $params[] = $filters['year'];
        $types .= "i";
    }

    // Filter berdasarkan bulan (jika dipilih)
    if ($filters['month'] != 0 && $filters['month'] != 'all') {
        $where_conditions[] = "MONTH(bh.tanggal_booking) = ?";
        $params[] = $filters['month'];
        $types .= "i";
    }

    // Filter berdasarkan kota (jika dipilih)
    if ($filters['city'] !== 'all' && !empty($filters['city'])) {
        $where_conditions[] = "h.kota = ?";
        $params[] = $filters['city'];
        $types .= "s";
    }

    // Filter berdasarkan hotel spesifik (jika dipilih)
    if ($filters['hotel'] !== 'all' && !empty($filters['hotel'])) {
        $where_conditions[] = "h.hotel_id = ?";
        $params[] = $filters['hotel'];
        $types .= "s";
    }

    $where_clause = count($where_conditions) > 0 ? "WHERE " . implode(" AND ", $where_conditions) : "";

    // Query utama untuk data revenue dengan indikator kinerja
    $query = "SELECT 
        h.hotel_id,
        h.nama_hotel,
        h.kota,
        COUNT(bh.booking_id) as total_bookings,
        SUM(bh.total_harga) as total_revenue,
        AVG(bh.total_harga) as avg_booking_value,
        AVG(DATEDIFF(bh.check_out, bh.check_in)) as avg_length_of_stay,
        MIN(bh.tanggal_booking) as first_booking_date,
        MAX(bh.tanggal_booking) as last_booking_date,
        COUNT(DISTINCT MONTH(bh.tanggal_booking)) as active_months
        FROM hotel h
        LEFT JOIN booking_hotel bh ON h.hotel_id = bh.hotel_id
        $where_clause
        GROUP BY h.hotel_id, h.nama_hotel, h.kota
        HAVING total_bookings > 0
        ORDER BY total_revenue DESC";

    $stmt = $conn->prepare($query);
    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $hotel_id = $row['hotel_id'];

            if ($row['total_bookings'] == 0) {
                continue; // Skip hotel tanpa booking
            }

            $total_bookings_int = (int)$row['total_bookings'];
            $total_revenue_float = (float)$row['total_revenue'];

            // Hitung ADR (Average Daily Rate) - indikator harga kamar rata-rata
            $total_nights = 0;
            $nights_query = "SELECT SUM(DATEDIFF(check_out, check_in)) as total_nights 
                           FROM booking_hotel 
                           WHERE hotel_id = ? AND status = 'Completed'";

            // Tambahkan filter tahun jika ada
            if ($filters['year'] != 0 && $filters['year'] != 'all') {
                $nights_query .= " AND YEAR(tanggal_booking) = " . $filters['year'];
            }

            $nights_stmt = $conn->prepare($nights_query);
            if ($nights_stmt) {
                $nights_stmt->bind_param("s", $hotel_id);
                $nights_stmt->execute();
                $nights_result = $nights_stmt->get_result()->fetch_assoc();
                $total_nights = (int)($nights_result['total_nights'] ?? 0);
                $nights_stmt->close();
            }

            // ADR = Total Revenue / Total Nights
            $avg_daily_rate = $total_nights > 0 ? round($total_revenue_float / $total_nights, 2) : 0;

            // Analisis tren revenue untuk melihat pola waktu
            $revenue_trends = getRevenueTrends($conn, $hotel_id, $filters);

            // Analisis musiman revenue untuk melihat pola bulanan
            $seasonal_analysis = getSeasonalAnalysis($conn, $hotel_id, $filters);

            // Perhitungan pertumbuhan revenue (persentase perubahan)
            $revenue_growth = calculateRevenueGrowth($revenue_trends);

            // Skor revenue komprehensif (0-100)
            $revenue_score = calculateRevenueScore(
                $total_revenue_float,
                $avg_daily_rate,
                $row['avg_booking_value'],
                $revenue_growth
            );

            // Kumpulkan semua data hotel
            $data[] = [
                'hotel_id' => $hotel_id,
                'hotel_name' => $row['nama_hotel'],
                'city' => $row['kota'],
                'total_bookings' => $total_bookings_int,
                'total_revenue' => $total_revenue_float,
                'avg_booking_value' => round($row['avg_booking_value'], 2),
                'avg_length_of_stay' => round($row['avg_length_of_stay'], 1),
                'avg_daily_rate' => $avg_daily_rate,
                'total_nights' => $total_nights,
                'active_months' => (int)$row['active_months'],
                'first_booking_date' => $row['first_booking_date'],
                'last_booking_date' => $row['last_booking_date'],
                'revenue_trends' => $revenue_trends,
                'seasonal_analysis' => $seasonal_analysis,
                'revenue_growth' => $revenue_growth,
                'revenue_score' => $revenue_score,
                'revenue_status' => getRevenueStatus($revenue_score)
            ];
        }
        $stmt->close();
    }

    return $data;
}

// 2. ANALISIS TREN REVENUE BERDASARKAN WAKTU - DIPERJELAS
function getRevenueTrends($conn, $hotel_id, $filters)
{
    /*
    FUNGSI: Menganalisis tren revenue hotel berdasarkan periode waktu
    PARAMETER:
      - $conn: Koneksi database
      - $hotel_id: ID hotel spesifik
      - $filters: Parameter filter periode
    OUTPUT: Array tren revenue per periode
    */

    $trends = [];

    // Tentukan interval berdasarkan periode yang dipilih
    $interval = match ($filters['period']) {
        'daily' => "DATE_FORMAT(bh.tanggal_booking, '%Y-%m-%d')",    // Harian
        'weekly' => "DATE_FORMAT(bh.tanggal_booking, '%Y-%u')",      // Mingguan
        'monthly' => "DATE_FORMAT(bh.tanggal_booking, '%Y-%m')",     // Bulanan (default)
        'quarterly' => "CONCAT(YEAR(bh.tanggal_booking), '-Q', QUARTER(bh.tanggal_booking))", // Kuartalan
        'yearly' => "YEAR(bh.tanggal_booking)",                      // Tahunan
        default => "DATE_FORMAT(bh.tanggal_booking, '%Y-%m')"        // Default bulanan
    };

    $where_conditions = ["bh.hotel_id = ?", "bh.status = 'Completed'"];
    $params = [$hotel_id];
    $types = "s";

    // Filter tahun jika dipilih
    if ($filters['year'] != 0 && $filters['year'] != 'all') {
        $where_conditions[] = "YEAR(bh.tanggal_booking) = ?";
        $params[] = $filters['year'];
        $types .= "i";
    }

    // Filter bulan jika dipilih
    if ($filters['month'] != 0 && $filters['month'] != 'all') {
        $where_conditions[] = "MONTH(bh.tanggal_booking) = ?";
        $params[] = $filters['month'];
        $types .= "i";
    }

    $where_clause = implode(" AND ", $where_conditions);

    // Query untuk mendapatkan tren revenue per periode
    $query = "SELECT 
        $interval as period,
        COUNT(bh.booking_id) as total_bookings,
        SUM(bh.total_harga) as revenue,
        AVG(bh.total_harga) as avg_value,
        AVG(DATEDIFF(bh.check_out, bh.check_in)) as avg_stay,
        SUM(DATEDIFF(bh.check_out, bh.check_in)) as total_nights
        FROM booking_hotel bh
        WHERE $where_clause
        GROUP BY $interval
        ORDER BY period";

    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $trends[] = [
                'period' => $row['period'],
                'total_bookings' => (int)$row['total_bookings'],
                'revenue' => (float)$row['revenue'],
                'avg_value' => round($row['avg_value'], 2),
                'avg_stay' => round($row['avg_stay'], 1),
                'total_nights' => (int)$row['total_nights'],
                'revpar' => calculateRevPAR((float)$row['revenue'], (int)$row['total_nights']),
                'adr' => (int)$row['total_nights'] > 0 ? round((float)$row['revenue'] / (int)$row['total_nights'], 2) : 0
            ];
        }
        $stmt->close();
    }

    return $trends;
}

// 3. ANALISIS REVENUE MUSIMAN - DIPERJELAS
function getSeasonalAnalysis($conn, $hotel_id, $filters)
{
    /*
    FUNGSI: Menganalisis pola musiman revenue (per bulan)
    PARAMETER:
      - $conn: Koneksi database
      - $hotel_id: ID hotel spesifik
      - $filters: Parameter filter
    OUTPUT: Array data revenue per bulan untuk analisis musiman
    */

    $seasonal = [];

    $query = "SELECT 
        MONTH(bh.tanggal_booking) as month_num,
        MONTHNAME(bh.tanggal_booking) as month_name,
        COUNT(bh.booking_id) as total_bookings,
        SUM(bh.total_harga) as revenue,
        AVG(bh.total_harga) as avg_value,
        AVG(DATEDIFF(bh.check_out, bh.check_in)) as avg_stay
        FROM booking_hotel bh
        WHERE bh.hotel_id = ? AND bh.status = 'Completed'";

    $params = [$hotel_id];
    $types = "s";

    // Filter tahun jika dipilih
    if ($filters['year'] != 0 && $filters['year'] != 'all') {
        $query .= " AND YEAR(bh.tanggal_booking) = ?";
        $params[] = $filters['year'];
        $types .= "i";
    }

    $query .= " GROUP BY MONTH(bh.tanggal_booking), MONTHNAME(bh.tanggal_booking)
        ORDER BY month_num";

    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $seasonal[] = [
                'month' => $row['month_name'],
                'month_num' => (int)$row['month_num'],
                'bookings' => (int)$row['total_bookings'],
                'revenue' => (float)$row['revenue'],
                'avg_value' => round($row['avg_value'], 2),
                'avg_stay' => round($row['avg_stay'], 1)
            ];
        }
        $stmt->close();
    }

    return $seasonal;
}

// 4. ANALISIS PERBANDINGAN REVENUE - DIPERBAIKI DAN DIPERJELAS
function getRevenueComparison($conn, $filters)
{
    /*
    FUNGSI: Membandingkan revenue antar hotel untuk analisis kompetitif
    PARAMETER:
      - $conn: Koneksi database
      - $filters: Parameter filter (terutama city dan year)
    OUTPUT: Array data perbandingan revenue antar hotel
    */

    $comparison = [];

    // Bangun klausa WHERE untuk filter
    $where_conditions = ["bh.status = 'Completed'"];
    $params = [];
    $types = "";

    // Filter kota jika dipilih
    if ($filters['city'] !== 'all' && !empty($filters['city'])) {
        $where_conditions[] = "h.kota = ?";
        $params[] = $filters['city'];
        $types .= "s";
    }

    // Filter tahun jika dipilih
    if ($filters['year'] != 0 && $filters['year'] != 'all') {
        $where_conditions[] = "YEAR(bh.tanggal_booking) = ?";
        $params[] = $filters['year'];
        $types .= "i";
    }

    $where_clause = count($where_conditions) > 0 ? "WHERE " . implode(" AND ", $where_conditions) : "";

    // Query untuk perbandingan semua hotel
    $query = "SELECT 
        h.hotel_id,
        h.nama_hotel,
        h.kota,
        COUNT(bh.booking_id) as total_bookings,
        SUM(bh.total_harga) as total_revenue,
        AVG(bh.total_harga) as avg_booking_value,
        AVG(DATEDIFF(bh.check_out, bh.check_in)) as avg_stay
        FROM hotel h
        LEFT JOIN booking_hotel bh ON h.hotel_id = bh.hotel_id AND bh.status = 'Completed'
        $where_clause
        GROUP BY h.hotel_id, h.nama_hotel, h.kota
        HAVING total_bookings > 0
        ORDER BY total_revenue DESC";

    $stmt = $conn->prepare($query);
    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $hotels = [];
        $total_revenue_all = 0;
        $total_bookings_all = 0;

        // Kumpulkan data semua hotel
        while ($row = $result->fetch_assoc()) {
            $total_bookings_int = (int)$row['total_bookings'];
            $total_revenue_float = (float)$row['total_revenue'];

            if ($total_bookings_int > 0) {
                $hotels[] = [
                    'hotel_id' => $row['hotel_id'],
                    'hotel_name' => $row['nama_hotel'],
                    'city' => $row['kota'],
                    'total_bookings' => $total_bookings_int,
                    'total_revenue' => $total_revenue_float,
                    'avg_booking_value' => round($row['avg_booking_value'], 2),
                    'avg_stay' => round($row['avg_stay'], 1)
                ];

                $total_revenue_all += $total_revenue_float;
                $total_bookings_all += $total_bookings_int;
            }
        }

        // Hitung market share dan ranking untuk setiap hotel
        foreach ($hotels as &$hotel) {
            // Market share = (Revenue hotel / Total revenue semua hotel) * 100
            $hotel['market_share'] = $total_revenue_all > 0 ?
                round(($hotel['total_revenue'] / $total_revenue_all) * 100, 2) : 0;

            // Ranking berdasarkan revenue
            $hotel['performance_rank'] = calculateRevenueRank($hotel, $hotels);
        }

        // Urutkan berdasarkan ranking
        usort($hotels, function ($a, $b) {
            return $a['performance_rank'] - $b['performance_rank'];
        });

        // Susun data perbandingan lengkap
        $comparison = [
            'city' => $filters['city'] !== 'all' ? $filters['city'] : 'Semua Kota',
            'total_hotels' => count($hotels),
            'total_revenue' => $total_revenue_all,
            'total_bookings' => $total_bookings_all,
            'avg_revenue_per_hotel' => count($hotels) > 0 ? round($total_revenue_all / count($hotels), 2) : 0,
            'avg_bookings_per_hotel' => count($hotels) > 0 ? round($total_bookings_all / count($hotels), 2) : 0,
            'hotels' => $hotels
        ];

        $stmt->close();
    }

    return $comparison;
}

// 5. FORECASTING REVENUE - DIPERJELAS
function getRevenueForecast($conn, $filters)
{
    /*
    FUNGSI: Memprediksi revenue masa depan berdasarkan data historis
    PARAMETER:
      - $conn: Koneksi database
      - $filters: Parameter filter untuk data historis
    OUTPUT: Array prediksi revenue untuk periode berikutnya
    */

    $forecast = [];

    // Ambil data historis 12 bulan terakhir
    $months_back = 12;

    // Bangun klausa WHERE
    $where_conditions = ["bh.status = 'Completed'"];
    $params = [];
    $types = "";

    // Filter kota jika dipilih
    if ($filters['city'] !== 'all' && !empty($filters['city'])) {
        $where_conditions[] = "h.kota = ?";
        $params[] = $filters['city'];
        $types .= "s";
    }

    // Filter hotel spesifik jika dipilih
    if ($filters['hotel'] !== 'all' && !empty($filters['hotel'])) {
        $where_conditions[] = "h.hotel_id = ?";
        $params[] = $filters['hotel'];
        $types .= "s";
    }

    $where_clause = count($where_conditions) > 0 ? "WHERE " . implode(" AND ", $where_conditions) : "";

    $query = "SELECT 
        DATE_FORMAT(bh.tanggal_booking, '%Y-%m') as month,
        COUNT(bh.booking_id) as total_bookings,
        SUM(bh.total_harga) as revenue,
        AVG(bh.total_harga) as avg_value
        FROM booking_hotel bh
        JOIN hotel h ON bh.hotel_id = h.hotel_id
        $where_clause
        GROUP BY DATE_FORMAT(bh.tanggal_booking, '%Y-%m')
        ORDER BY month DESC
        LIMIT $months_back";

    $stmt = $conn->prepare($query);
    $historical_data = [];

    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $historical_data[] = $row;
        }
        $stmt->close();
    }

    // Ubah urutan data menjadi kronologis untuk perhitungan WMA
    $historical_data = array_reverse($historical_data);

    // Minimal 3 bulan data untuk forecasting
    if (count($historical_data) >= 3) {
        // Gunakan weighted moving average untuk forecasting
        $forecast_values = calculateRevenueForecast($historical_data);

        // Tentukan periode forecast berikutnya
        $current_month = (int)date('m');
        $current_year = (int)date('Y');

        $next_month_num = ($current_month % 12) + 1;
        $next_year = $current_month == 12 ? $current_year + 1 : $current_year;

        // Faktor musiman berdasarkan bulan
        $seasonality_factor = getSeasonalityFactor($next_month_num);

        // Hitung prediksi dengan faktor musiman
        $forecasted_bookings = round($forecast_values['bookings'] * $seasonality_factor);
        $forecasted_revenue = round($forecast_values['revenue'] * $seasonality_factor);

        // Susun data forecast
        $forecast = [
            'forecast_period' => getIndonesianMonth($next_month_num) . ' ' . $next_year,
            'forecasted_bookings' => $forecasted_bookings,
            'forecasted_revenue' => $forecasted_revenue,
            'forecasted_avg_value' => round($forecast_values['avg_value'], 2),
            'seasonality_factor' => round($seasonality_factor, 2),
            'confidence_level' => calculateForecastConfidence($historical_data),
            'historical_data_points' => count($historical_data),
            'growth_rate' => calculateRevenueGrowthRate($historical_data)
        ];
    }

    return $forecast;
}

// ============================================
// FUNGSI HELPER REVENUE ANALYTICS - DIPERJELAS
// ============================================

function calculateRevenueScore($total_revenue, $adr, $avg_booking_value, $growth_rate)
{
    /*
    FUNGSI: Menghitung skor revenue komprehensif (0-100)
    LOGIKA SCORING:
      - Revenue: 40% dari total skor (max 40 point)
      - ADR: 30% dari total skor (max 30 point)  
      - Avg Booking Value: 20% dari total skor (max 20 point)
      - Growth Rate: 10% dari total skor (max 10 point)
    */

    $revenue_score = min(40, ($total_revenue / 10000000) * 0.4);
    $adr_score = min(30, ($adr / 1000000) * 30);
    $value_score = min(20, ($avg_booking_value / 5000000) * 20);
    $growth_score = min(10, max(0, $growth_rate * 2));

    return round($revenue_score + $adr_score + $value_score + $growth_score);
}

function calculateRevPAR($revenue, $total_nights)
{
    /*
    FUNGSI: Menghitung Revenue Per Available Room
    FORMULA: RevPAR = Total Revenue / Total Nights
    CATATAN: Mengasumsikan setiap booking menggunakan 1 kamar
    */

    return $total_nights > 0 ? round($revenue / $total_nights, 2) : 0;
}

function calculateRevenueGrowth($trends)
{
    /*
    FUNGSI: Menghitung pertumbuhan revenue dari awal ke akhir periode
    FORMULA: ((Last Revenue - First Revenue) / First Revenue) * 100
    */

    if (count($trends) < 2) return 0;

    // Ambil revenue periode pertama dan terakhir
    $first_revenue = $trends[0]['revenue'];
    $last_revenue = end($trends)['revenue'];

    if ($first_revenue > 0) {
        return round((($last_revenue - $first_revenue) / $first_revenue) * 100, 2);
    }

    return 0;
}

function calculateRevenueGrowthRate($historical_data)
{
    /*
    FUNGSI: Menghitung rata-rata pertumbuhan revenue bulan ke bulan
    */

    if (count($historical_data) < 2) return 0;

    // Urutkan data berdasarkan bulan
    usort($historical_data, function ($a, $b) {
        return $a['month'] <=> $b['month'];
    });

    // Hitung pertumbuhan bulan ke bulan
    $total_growth = 0;
    $count = 0;

    for ($i = 1; $i < count($historical_data); $i++) {
        $current = (float)$historical_data[$i]['revenue'];
        $previous = (float)$historical_data[$i - 1]['revenue'];

        if ($previous > 0) {
            $growth = (($current - $previous) / $previous) * 100;
            $total_growth += $growth;
            $count++;
        }
    }

    return $count > 0 ? round($total_growth / $count, 2) : 0;
}

function calculateRevenueRank($hotel, $hotels_list)
{
    /*
    FUNGSI: Menentukan ranking hotel berdasarkan total revenue
    */

    // Urutkan berdasarkan total revenue (descending)
    usort($hotels_list, function ($a, $b) {
        return $b['total_revenue'] <=> $a['total_revenue'];
    });

    // Cari ranking hotel
    foreach ($hotels_list as $index => $h) {
        if ($h['hotel_id'] === $hotel['hotel_id']) {
            return $index + 1;
        }
    }

    return count($hotels_list) + 1;
}

function calculateRevenueForecast($data)
{
    /*
    FUNGSI: Menghitung prediksi menggunakan Weighted Moving Average (WMA)
    LOGIKA: Data terbaru diberi bobot lebih tinggi
    */

    $total_weight = 0;
    $weighted_bookings = 0;
    $weighted_revenue = 0;
    $weighted_value = 0;

    // Berikan bobot yang lebih tinggi untuk data terbaru
    foreach ($data as $i => $row) {
        $weight = $i + 1; // Bobot: 1, 2, 3, ...
        $total_weight += $weight;
        $weighted_bookings += (int)$row['total_bookings'] * $weight;
        $weighted_revenue += (float)$row['revenue'] * $weight;
        $weighted_value += (float)$row['avg_value'] * $weight;
    }

    return [
        'bookings' => $weighted_bookings / $total_weight,
        'revenue' => $weighted_revenue / $total_weight,
        'avg_value' => $weighted_value / $total_weight
    ];
}

function getRevenueStatus($score)
{
    /*
    FUNGSI: Menentukan status revenue berdasarkan skor
    */

    if ($score >= 80) return ['label' => 'Sangat Baik', 'color' => '#1baf7a'];
    if ($score >= 60) return ['label' => 'Baik', 'color' => '#2a78d6'];
    if ($score >= 40) return ['label' => 'Cukup', 'color' => '#eda100'];
    return ['label' => 'Perlu Perbaikan', 'color' => '#e34948'];
}

function getSeasonalityFactor($month)
{
    /*
    FUNGSI: Memberikan faktor musiman berdasarkan bulan
    FAKTOR: >1 = musim tinggi, <1 = musim rendah, 1 = normal
    */

    $factors = [
        1 => 1.1,   // Januari - tinggi (tahun baru)
        2 => 0.9,   // Februari - rendah
        3 => 1.0,   // Maret - normal
        4 => 1.0,   // April - normal
        5 => 0.9,   // Mei - rendah
        6 => 1.2,   // Juni - tinggi (libur sekolah)
        7 => 1.3,   // Juli - sangat tinggi (libur panjang)
        8 => 1.1,   // Agustus - tinggi (HUT RI)
        9 => 0.9,   // September - rendah
        10 => 1.0, // Oktober - normal
        11 => 1.0, // November - normal
        12 => 1.4   // Desember - sangat tinggi (Natal & tahun baru)
    ];
    return $factors[(int)$month] ?? 1.0;
}

function calculateForecastConfidence($historical_data)
{
    /*
    FUNGSI: Menentukan tingkat kepercayaan forecast berdasarkan jumlah data
    */

    $count = count($historical_data);
    if ($count >= 12) return 'Tinggi';
    if ($count >= 6) return 'Sedang';
    return 'Rendah';
}

// ============================================
// FUNGSI HELPER UNTUK FILTER - DIPERJELAS
// ============================================

function getAvailableYears($conn)
{
    /*
    FUNGSI: Mendapatkan list tahun yang tersedia dari data booking
    */

    $query = "SELECT DISTINCT YEAR(tanggal_booking) as year FROM booking_hotel WHERE status = 'Completed' ORDER BY year DESC";
    $result = $conn->query($query);
    $years = [date('Y')]; // Default: tahun sekarang

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $year = (int)$row['year'];
            if (!in_array($year, $years)) {
                $years[] = $year;
            }
        }
    }

    rsort($years);
    return $years;
}

function getAvailableCities($conn)
{
    /*
    FUNGSI: Mendapatkan list kota yang tersedia
    */

    $query = "SELECT DISTINCT kota FROM hotel WHERE kota IS NOT NULL AND kota != '' ORDER BY kota";
    $result = $conn->query($query);
    $cities = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $cities[] = $row['kota'];
        }
    }

    return $cities;
}

function getAvailableHotels($conn, $city)
{
    /*
    FUNGSI: Mendapatkan list hotel berdasarkan kota
    */

    $query = "SELECT hotel_id, nama_hotel FROM hotel";
    $params = [];
    $types = "";

    // Filter berdasarkan kota jika dipilih
    if ($city !== 'all' && !empty($city)) {
        $query .= " WHERE kota = ?";
        $params[] = $city;
        $types .= "s";
    }
    $query .= " ORDER BY nama_hotel";

    $stmt = $conn->prepare($query);
    $hotels = [];

    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $hotels[$row['hotel_id']] = $row['nama_hotel'];
        }
        $stmt->close();
    }

    return $hotels;
}

function getIndonesianMonth($monthNumber)
{
    /*
    FUNGSI: Mengonversi angka bulan menjadi nama bulan dalam Bahasa Indonesia
    */

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
    return $months[(int)$monthNumber] ?? 'Tidak Dikenal';
}

function formatRupiah($amount)
{
    /*
    FUNGSI: Memformat angka menjadi format mata uang Rupiah
    */

    if ($amount == 0) return 'Rp 0';

    $amount = (float)$amount;
    if ($amount >= 1000000000) {
        return 'Rp ' . number_format($amount / 1000000000, 2, ',', '.') . ' Miliar';
    } elseif ($amount >= 1000000) {
        return 'Rp ' . number_format($amount / 1000000, 2, ',', '.') . ' Juta';
    } elseif ($amount >= 1000) {
        return 'Rp ' . number_format($amount / 1000, 1, ',', '.') . ' Ribu';
    } else {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }
}

// ============================================
// PROSES UTAMA - AMBIL DATA BERDASARKAN FILTER
// ============================================

// Ambil opsi filter untuk dropdown
$available_years = getAvailableYears($conn);
$available_cities = getAvailableCities($conn);
$available_hotels = getAvailableHotels($conn, $filter_city);

// Eksekusi analisis revenue berdasarkan filter yang dipilih
$revenue_analysis = getRevenueAnalysisData($conn, [
    'year' => (int)$filter_year,
    'month' => (int)$filter_month,
    'city' => $filter_city,
    'hotel' => $filter_hotel,
    'period' => $filter_period,
    'view' => $filter_view
]);

// Eksekusi perbandingan revenue
$revenue_comparison = getRevenueComparison($conn, [
    'year' => (int)$filter_year,
    'city' => $filter_city
]);

// Eksekusi forecasting revenue
$revenue_forecast = getRevenueForecast($conn, [
    'year' => (int)$filter_year,
    'month' => (int)$filter_month,
    'city' => $filter_city,
    'hotel' => $filter_hotel
]);

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Analisis Revenue Hotel | TripVerse Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../../css/dashboard.css?v=2.0.0">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* CSS Custom untuk Dashboard Visualisasi Data */
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

        body {
            background-color: #f8fafc;
            font-family: 'Heebo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
        }

        .dashboard-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 25px;
            margin-bottom: 30px;
            border-left: 4px solid var(--primary-color);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            animation: fadeIn 0.6s ease-out;
        }

        .dashboard-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.5s ease;
        }

        .dashboard-section:hover::before {
            transform: scaleX(1);
        }

        .dashboard-section:hover {
            box-shadow: var(--box-shadow-hover);
            transform: translateY(-5px);
        }

        .dashboard-section h2 {
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

        .dashboard-section h3 {
            margin-top: 25px;
            margin-bottom: 15px;
            font-size: 16px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Filter Controls - DIPERJELAS */
        .filter-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            align-items: end;
            position: relative;
        }

        .filter-info {
            grid-column: 1 / -1;
            background: #e8f4fd;
            padding: 12px 15px;
            border-radius: 8px;
            border-left: 4px solid var(--info-color);
            font-size: 14px;
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .filter-info i {
            color: var(--info-color);
            margin-right: 8px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .filter-group label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-light);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .filter-tooltip {
            position: relative;
            cursor: help;
        }

        .filter-tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 5px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .filter-select {
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            width: 100%;
            background: white;
            box-sizing: border-box;
            font-family: inherit;
            cursor: pointer;
        }

        .filter-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 122, 61, 0.1);
            outline: none;
            transform: translateY(-1px);
        }

        .filter-controls button,
        .filter-controls .reset-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            white-space: nowrap;
            justify-content: center;
            height: fit-content;
            text-decoration: none;
            min-height: 48px;
            box-shadow: 0 3px 10px rgba(255, 122, 61, 0.2);
        }

        .filter-controls button:hover {
            background: #E8672B;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 122, 61, 0.3);
        }

        .filter-controls .reset-btn {
            background: #6c757d;
            box-shadow: 0 3px 10px rgba(108, 117, 125, 0.2);
        }

        .filter-controls .reset-btn:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
        }

        /* Active Filter Display */
        .active-filters {
            grid-column: 1 / -1;
            background: #fff3e0;
            padding: 12px 15px;
            border-radius: 8px;
            border-left: 4px solid var(--warning-color);
            font-size: 14px;
            margin-bottom: 10px;
        }

        .active-filters h4 {
            margin: 0 0 8px 0;
            color: var(--dark-color);
            font-size: 14px;
        }

        .filter-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .filter-tag {
            background: var(--primary-color);
            color: white;
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            transition: var(--transition);
            border: none;
            cursor: pointer;
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
            background: var(--gradient-primary);
        }

        .kpi-card.success::before {
            background: var(--gradient-success);
        }

        .kpi-card.warning::before {
            background: var(--gradient-warning);
        }

        .kpi-card.danger::before {
            background: var(--gradient-danger);
        }

        .kpi-card.info::before {
            background: var(--gradient-info);
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
            background: var(--gradient-primary);
        }

        .kpi-card.success .kpi-icon {
            background: var(--gradient-success);
        }

        .kpi-card.warning .kpi-icon {
            background: var(--gradient-warning);
        }

        .kpi-card.danger .kpi-icon {
            background: var(--gradient-danger);
        }

        .kpi-card.info .kpi-icon {
            background: var(--gradient-info);
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

        .revenue-metric {
            font-size: 24px;
            font-weight: 700;
            color: #2e7d32;
            margin: 10px 0;
        }

        .revenue-submetric {
            font-size: 13px;
            color: var(--text-light);
            margin-top: 5px;
        }

        /* Visualization Containers */
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            border: 1px solid #e9ecef;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .chart-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Performance Table */
        .performance-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #e1e5e9;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-top: 20px;
        }

        .performance-table thead th {
            background: linear-gradient(135deg, var(--primary-color), #FF7A3D);
            color: white;
            padding: 16px 15px;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            text-align: left;
            font-weight: 600;
        }

        .performance-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        .performance-table tbody tr:hover {
            background-color: #e8eaf6;
            cursor: pointer;
            transition: var(--transition);
        }

        .performance-table td {
            font-size: 14px;
            color: var(--text-color);
            padding: 14px 15px;
            border-bottom: 1px solid #e9ecef;
        }

        .performance-table td strong {
            font-weight: 700;
            color: var(--dark-color);
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            min-width: 80px;
            text-align: center;
        }

        .status-sangat-baik {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
        }

        .status-baik {
            background-color: #e3f2fd;
            color: #1565c0;
            border: 1px solid #90caf9;
        }

        .status-cukup {
            background-color: #fff3e0;
            color: #ef6c00;
            border: 1px solid #ffcc80;
        }

        .status-perlu-perbaikan {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ef9a9a;
        }

        /* Metric Display */
        .metric-display {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .metric-progress {
            flex-grow: 1;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }

        .metric-progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.6s ease;
        }

        /* View Tabs */
        .view-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 0;
            flex-wrap: wrap;
        }

        .view-tab {
            padding: 12px 24px;
            border: none;
            background: none;
            color: var(--text-light);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 8px 8px 0 0;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
            border-bottom: 2px solid transparent;
        }

        .view-tab:hover {
            background: #f8f9fa;
            color: var(--primary-color);
        }

        .view-tab.active {
            background: white;
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            box-shadow: 0 2px 5px rgba(255, 122, 61, 0.1);
        }

        /* Forecast Card */
        .forecast-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-top: 20px;
        }

        .forecast-value {
            font-size: 36px;
            font-weight: 800;
            text-align: center;
            margin: 15px 0;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
        }

        /* Insights Panel */
        .insights-panel {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 20px;
            border-left: 4px solid var(--info-color);
        }

        .insight-item {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            padding: 12px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .insight-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
        }

        .insight-positive .insight-icon {
            background: var(--success-color);
        }

        .insight-negative .insight-icon {
            background: var(--danger-color);
        }

        .insight-neutral .insight-icon {
            background: var(--warning-color);
        }

        /* Revenue Insight */
        .revenue-insight {
            background: #f0f7ff;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 20px;
            border-left: 4px solid var(--info-color);
        }

        .revenue-insight h4 {
            margin-top: 0;
            color: var(--info-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .revenue-insight p {
            color: #333;
            line-height: 1.6;
        }

        /* Dropdown Styles */
        .dropdown-content {
            display: none;
            position: absolute;
            background-color: white;
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            border-radius: 8px;
            overflow: hidden;
            top: 100%;
            right: 0;
            margin-top: 5px;
        }

        .dropdown-content.show {
            display: block;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            transition: background-color 0.2s;
        }

        .dropdown-item:hover {
            background-color: #f5f5f5;
            color: var(--primary-color);
        }

        .user-dropdown {
            position: relative;
            margin-top: 10px;
        }

        /* .user-info intentionally has no override here: it falls back to
           dashboard.css's shared translucent pill button, matching every
           other admin page instead of this page's old solid-orange variant. */

        .dropdown-arrow {
            transition: transform 0.3s ease;
        }

        .user-info.open .dropdown-arrow {
            transform: rotate(180deg);
        }

        /* Sidebar Dropdown Styles */
        .booking-submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease-out;
            padding-left: 20px;
        }

        .booking-submenu.show {
            max-height: 500px;
        }

        .booking-toggle {
            position: relative;
            cursor: pointer;
        }

        .toggle-icon {
            position: absolute;
            right: 15px;
            transition: transform 0.3s ease;
        }

        .booking-toggle.active .toggle-icon {
            transform: rotate(180deg);
        }

        /* Responsive Adjustments */
        @media (max-width: 1200px) {
            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 992px) {
            .filter-controls {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .dashboard-section {
                padding: 20px;
                margin-bottom: 20px;
            }

            .kpi-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .filter-controls {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .chart-container {
                height: 320px;
                padding: 15px;
            }

            .kpi-value {
                font-size: 24px;
            }

            .revenue-metric {
                font-size: 20px;
            }

            .filter-controls button,
            .filter-controls .reset-btn {
                padding: 16px 20px;
                font-size: 15px;
                min-height: 52px;
            }

            .view-tabs {
                flex-wrap: wrap;
            }

            .view-tab {
                padding: 10px 15px;
                font-size: 13px;
            }
        }

        @media (max-width: 480px) {
            .dashboard-section {
                padding: 15px;
            }

            .chart-container {
                height: 280px;
                padding: 10px;
            }

            .filter-controls {
                padding: 15px;
            }

            .kpi-value {
                font-size: 22px;
            }

            .performance-table {
                font-size: 12px;
            }

            .performance-table th,
            .performance-table td {
                padding: 10px 8px;
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #666;
            background: #f8f9fa;
            border-radius: var(--border-radius);
            margin: 20px 0;
        }

        .empty-state i {
            font-size: 48px;
            color: #ccc;
            margin-bottom: 15px;
            display: block;
        }

        .empty-state p {
            font-size: 16px;
            margin-bottom: 10px;
        }

        .empty-state .subtext {
            font-size: 14px;
            color: #999;
        }

        /* Chart Grid Layout */
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .chart-grid .chart-container {
            margin-bottom: 0;
            height: 350px;
        }

        @media (max-width: 768px) {
            .chart-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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
                    <img src="<?php echo htmlspecialchars($foto); ?>"
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
            <a href="dashboard.php">
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
                <a href="#" class="booking-toggle active" data-target="decisionDropdown">
                    <span class="material-icons">analytics</span>
                    <span><?= te('Analisis Statistik') ?></span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>

                <div class="booking-submenu show" id="decisionDropdown">
                    <a href="revenue_optimization.php" class="active">
                        <span class="material-icons">attach_money</span>
                        <span><?= te('Statistik Pendapatan') ?></span>
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
                <div class="notification-bell" id="notificationBell">
                    <span class="material-icons bell-icon">notifications</span>
                    <?php if ($notificationCount > 0): ?>
                        <span class="notification-badge" id="notificationCount"><?= $notificationCount ?></span>
                    <?php endif; ?>
                </div>

                <div class="user-menu">
                    <img src="<?php echo htmlspecialchars($foto); ?>" alt="User Avatar" class="user-avatar" />
                </div>
            </div>
        </header>

        <div class="dashboard-section">
            <h2><i class="material-icons">attach_money</i> Dashboard Analisis Revenue Hotel</h2>

            <div class="view-tabs">
                <button class="view-tab <?= $filter_view == 'overview' ? 'active' : '' ?>" onclick="changeView('overview')">
                    <i class="material-icons">dashboard</i> Revenue Overview
                </button>
                <button class="view-tab <?= $filter_view == 'trends' ? 'active' : '' ?>" onclick="changeView('trends')">
                    <i class="material-icons">trending_up</i> Revenue Trends
                </button>
                <button class="view-tab <?= $filter_view == 'comparison' ? 'active' : '' ?>" onclick="changeView('comparison')">
                    <i class="material-icons">compare</i> Revenue Comparison
                </button>
            </div>

            <!-- Form Filter dengan Penjelasan Fungsi -->
            <form method="GET" action="revenue_optimization.php" class="filter-controls">
                <input type="hidden" name="view" value="<?= $filter_view ?>">

                <div class="filter-group">
                    <label for="filter_year"
                        class="filter-tooltip"
                        data-tooltip="Filter berdasarkan tahun booking">
                        <i class="material-icons">calendar_today</i> Tahun
                    </label>

                    <select id="filter_year" name="year" class="filter-select">
                        <option value="all">Semua Tahun</option>
                        <?php foreach ($available_years as $year): ?>
                            <option value="<?= $year ?>" <?= $filter_year == $year ? 'selected' : '' ?>>
                                <?= $year ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_month"
                        class="filter-tooltip"
                        data-tooltip="Filter berdasarkan bulan booking">
                        <i class="material-icons">date_range</i> Bulan
                    </label>
                    <select id="filter_month" name="month" class="filter-select">
                        <option value="0">Semua Bulan</option>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $filter_month == $m ? 'selected' : '' ?>>
                                <?= getIndonesianMonth($m) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_city"
                        class="filter-tooltip"
                        data-tooltip="Filter berdasarkan kota hotel">
                        <i class="material-icons">location_city</i> Kota
                    </label>
                    <select id="filter_city" name="city" class="filter-select"
                        onchange="updateHotelDropdown(this.value)">
                        <option value="all">Semua Kota</option>
                        <?php foreach ($available_cities as $city): ?>
                            <option value="<?= htmlspecialchars($city) ?>" <?= $filter_city == $city ? 'selected' : '' ?>>
                                <?= htmlspecialchars($city) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_hotel"
                        class="filter-tooltip"
                        data-tooltip="Filter berdasarkan hotel spesifik">
                        <i class="material-icons">hotel</i> Hotel
                    </label>
                    <select id="filter_hotel" name="hotel" class="filter-select">
                        <option value="all">Semua Hotel</option>
                        <?php foreach ($available_hotels as $id => $name): ?>
                            <option value="<?= $id ?>" <?= $filter_hotel == $id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_period"
                        class="filter-tooltip"
                        data-tooltip="Rentang waktu untuk analisis tren">
                        <i class="material-icons">timeline</i> Periode Analisis
                    </label>
                    <select id="filter_period" name="period" class="filter-select">
                        <option value="monthly" <?= $filter_period == 'monthly' ? 'selected' : '' ?>>Bulanan</option>
                        <option value="yearly" <?= $filter_period == 'yearly' ? 'selected' : '' ?>>Tahunan</option>
                    </select>
                </div>

                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="material-icons">filter_alt</i> Terapkan Filter
                    </button>
                </div>

                <div class="filter-group">
                    <a href="revenue_optimization.php" class="btn btn-secondary reset-btn">
                        <i class="material-icons">refresh</i> Reset Filter
                    </a>
                </div>
                <!-- Display Active Filters -->
                <?php if ($filter_year != date('Y') || $filter_month != 0 || $filter_city != 'all' || $filter_hotel != 'all'): ?>
                    <div class="active-filters">
                        <h4><i class="material-icons">filter_list</i> Filter Aktif:</h4>
                        <div class="filter-tags">
                            <?php if ($filter_year != date('Y') && $filter_year != 'all'): ?>
                                <span class="filter-tag">Tahun: <?= $filter_year ?></span>
                            <?php endif; ?>
                            <?php if ($filter_month != 0 && $filter_month != 'all'): ?>
                                <span class="filter-tag">Bulan: <?= getIndonesianMonth($filter_month) ?></span>
                            <?php endif; ?>
                            <?php if ($filter_city != 'all'): ?>
                                <span class="filter-tag">Kota: <?= htmlspecialchars($filter_city) ?></span>
                            <?php endif; ?>
                            <?php if ($filter_hotel != 'all'): ?>
                                <span class="filter-tag">Hotel: <?= htmlspecialchars($available_hotels[$filter_hotel] ?? $filter_hotel) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </form>

            <!-- Overview Tab Content -->
            <div id="overview-content" class="tab-content <?= $filter_view == 'overview' ? 'active' : '' ?>">
                <?php if ($filter_view == 'overview'): ?>
                    <?php
                    $total_revenue = array_sum(array_column($revenue_analysis, 'total_revenue'));
                    $total_bookings = array_sum(array_column($revenue_analysis, 'total_bookings'));
                    $total_hotels = count($revenue_analysis);
                    $avg_adr = $total_hotels > 0 ? array_sum(array_column($revenue_analysis, 'avg_daily_rate')) / $total_hotels : 0;
                    $avg_revenue_score = $total_hotels > 0 ? array_sum(array_column($revenue_analysis, 'revenue_score')) / $total_hotels : 0;
                    ?>

                    <!-- KPI Cards dengan Penjelasan -->
                    <div class="kpi-grid">
                        <div class="kpi-card revenue-kpi-card success">
                            <div class="kpi-icon" style="background: linear-gradient(135deg, #1baf7a, #3fcf9c);">
                                <i class="material-icons">attach_money</i>
                            </div>
                            <div class="kpi-label">Total Revenue</div>
                            <div class="revenue-metric"><?= formatRupiah($total_revenue) ?></div>
                            <div class="revenue-submetric"><?= $total_hotels ?> Hotel | <?= number_format($total_bookings) ?> Bookings</div>
                            <div style="font-size: 11px; color: #888; margin-top: 5px;">
                                Total pendapatan dari semua booking completed
                            </div>
                        </div>

                        <div class="kpi-card revenue-kpi-card info">
                            <div class="kpi-icon" style="background: linear-gradient(135deg, #2a78d6, #42a5f5);">
                                <i class="material-icons">trending_up</i>
                            </div>
                            <div class="kpi-label">Average Daily Rate (ADR)</div>
                            <div class="revenue-metric"><?= formatRupiah($avg_adr) ?></div>
                            <div class="revenue-submetric">Rata-rata per malam</div>
                            <div style="font-size: 11px; color: #888; margin-top: 5px;">
                                Harga kamar rata-rata per malam
                            </div>
                        </div>

                        <div class="kpi-card revenue-kpi-card warning">
                            <div class="kpi-icon" style="background: linear-gradient(135deg, #eda100, #ffa726);">
                                <i class="material-icons">calendar_today</i>
                            </div>
                            <div class="kpi-label">Total Nights</div>
                            <div class="revenue-metric"><?= number_format(array_sum(array_column($revenue_analysis, 'total_nights'))) ?></div>
                            <div class="revenue-submetric">Malam terjual</div>
                            <div style="font-size: 11px; color: #888; margin-top: 5px;">
                                Jumlah total malam yang terjual
                            </div>
                        </div>

                        <div class="kpi-card revenue-kpi-card">
                            <div class="kpi-icon" style="background: linear-gradient(135deg, #9c27b0, #ab47bc);">
                                <i class="material-icons">score</i>
                            </div>
                            <div class="kpi-label">Revenue Score</div>
                            <div class="revenue-metric"><?= round($avg_revenue_score, 0) ?>/100</div>
                            <div class="revenue-submetric">Status: <?= getRevenueStatus($avg_revenue_score)['label'] ?></div>
                            <div style="font-size: 11px; color: #888; margin-top: 5px;">
                                Skor kinerja revenue (0-100)
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($revenue_analysis)): ?>
                        <div class="chart-container revenue-chart-container">
                            <div class="chart-title"><i class="material-icons">bar_chart</i> Revenue per Hotel</div>
                            <canvas id="revenueChart"></canvas>
                        </div>

                        <div class="dashboard-section">
                            <h3><i class="material-icons">list_alt</i> Detail Revenue Hotel</h3>
                            <div style="overflow-x: auto;">
                                <table class="performance-table revenue-table">
                                    <thead>
                                        <tr>
                                            <th>Hotel</th>
                                            <th>Kota</th>
                                            <th>Total Revenue</th>
                                            <th>Bookings</th>
                                            <th>Avg Booking Value</th>
                                            <th>ADR</th>
                                            <th>Avg Stay</th>
                                            <th>Revenue Score</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($revenue_analysis as $hotel):
                                            $status = $hotel['revenue_status'];
                                            $status_class = strtolower(str_replace(' ', '-', $status['label']));
                                        ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($hotel['hotel_name']) ?></strong></td>
                                                <td><?= htmlspecialchars($hotel['city']) ?></td>
                                                <td style="font-weight: 700; color: #2e7d32;"><?= formatRupiah($hotel['total_revenue']) ?></td>
                                                <td><?= number_format($hotel['total_bookings']) ?></td>
                                                <td><?= formatRupiah($hotel['avg_booking_value']) ?></td>
                                                <td><?= formatRupiah($hotel['avg_daily_rate']) ?></td>
                                                <td><?= $hotel['avg_length_of_stay'] ?> hari</td>
                                                <td>
                                                    <span class="status-badge status-<?= $status_class ?>">
                                                        <?= $hotel['revenue_score'] ?>/100
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Trends Tab Content -->
            <div id="trends-content" class="tab-content <?= $filter_view == 'trends' ? 'active' : '' ?>">
                <?php if ($filter_view == 'trends'): ?>
                    <?php if ($filter_hotel != 'all' && !empty($revenue_analysis)): ?>
                        <?php $hotel_data = $revenue_analysis[0]; ?>

                        <div class="dashboard-section">
                            <h3><i class="material-icons">trending_up</i> Tren Revenue untuk <?= htmlspecialchars($hotel_data['hotel_name']) ?></h3>

                            <div class="filter-info">
                                <i class="material-icons">info</i>
                                Analisis tren revenue untuk hotel spesifik. Data ditampilkan per <?= $filter_period ?>.
                            </div>

                            <?php if (!empty($hotel_data['revenue_trends'])): ?>
                                <div class="chart-grid">
                                    <div class="chart-container revenue-chart-container">
                                        <div class="chart-title"><i class="material-icons">show_chart</i> Tren Revenue dan Bookings (<?= $filter_period ?>)</div>
                                        <canvas id="revenueTrendsChart"></canvas>
                                    </div>

                                    <div class="chart-container revenue-chart-container">
                                        <div class="chart-title"><i class="material-icons">compare_arrows</i> Analisis ADR vs RevPAR</div>
                                        <canvas id="adrRevparChart"></canvas>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="material-icons">timeline</i>
                                    <p>Tidak ada data tren untuk hotel ini.</p>
                                    <p class="subtext">Coba pilih periode yang berbeda atau hotel lain.</p>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($hotel_data['seasonal_analysis'])): ?>
                                <div class="chart-container revenue-chart-container">
                                    <div class="chart-title"><i class="material-icons">waves</i> Analisis Musiman Revenue</div>
                                    <canvas id="seasonalChart"></canvas>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">warning</i>
                            <p>Untuk melihat tren revenue, silakan pilih hotel spesifik terlebih dahulu.</p>
                            <p class="subtext">Pilih hotel dari dropdown filter di atas.</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Comparison Tab Content -->
            <div id="comparison-content" class="tab-content <?= $filter_view == 'comparison' ? 'active' : '' ?>">
                <?php if ($filter_view == 'comparison'): ?>
                    <?php if (!empty($revenue_comparison) && !empty($revenue_comparison['hotels'])): ?>
                        <div class="dashboard-section">
                            <h3><i class="material-icons">compare</i> Perbandingan Revenue Hotel
                                <?= $revenue_comparison['city'] == 'all' ? 'Semua Kota' : 'di ' . htmlspecialchars($revenue_comparison['city']) ?>
                            </h3>

                            <div class="filter-info">
                                <i class="material-icons">info</i>
                                Analisis perbandingan revenue antar hotel. Menghitung market share dan ranking.
                            </div>

                            <div class="kpi-grid">
                                <div class="kpi-card revenue-kpi-card">
                                    <div class="kpi-label">Total Revenue</div>
                                    <div class="revenue-metric"><?= formatRupiah($revenue_comparison['total_revenue']) ?></div>
                                    <div class="revenue-submetric"><?= $revenue_comparison['total_hotels'] ?> Hotel</div>
                                </div>
                                <div class="kpi-card revenue-kpi-card info">
                                    <div class="kpi-label">Rata-rata per Hotel</div>
                                    <div class="revenue-metric"><?= formatRupiah($revenue_comparison['avg_revenue_per_hotel']) ?></div>
                                    <div class="revenue-submetric"><?= $revenue_comparison['avg_bookings_per_hotel'] ?> bookings/hotel</div>
                                </div>
                                <div class="kpi-card revenue-kpi-card success">
                                    <div class="kpi-label">Total Bookings</div>
                                    <div class="revenue-metric"><?= number_format($revenue_comparison['total_bookings']) ?></div>
                                    <div class="revenue-submetric">Seluruh hotel</div>
                                </div>
                            </div>

                            <?php if (count($revenue_comparison['hotels']) > 1): ?>
                                <div class="chart-grid">
                                    <div class="chart-container revenue-chart-container">
                                        <div class="chart-title"><i class="material-icons">pie_chart</i> Market Share Hotel (Top 8)</div>
                                        <canvas id="marketShareChart"></canvas>
                                    </div>

                                    <div class="chart-container revenue-chart-container">
                                        <div class="chart-title"><i class="material-icons">bar_chart</i> Top 10 Hotel by Revenue</div>
                                        <canvas id="revenueComparisonChart"></canvas>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="material-icons">info</i>
                                    <p>Hanya ada 1 hotel dalam data perbandingan.</p>
                                    <p class="subtext">Market share chart memerlukan minimal 2 hotel.</p>
                                </div>
                            <?php endif; ?>

                            <div style="overflow-x: auto; margin-top: 20px;">
                                <table class="performance-table revenue-table">
                                    <thead>
                                        <tr>
                                            <th>Rank</th>
                                            <th>Hotel</th>
                                            <th>Kota</th>
                                            <th>Total Revenue</th>
                                            <th>Market Share</th>
                                            <th>Bookings</th>
                                            <th>Avg Booking Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($revenue_comparison['hotels'] as $index => $hotel): ?>
                                            <tr>
                                                <td><strong>#<?= $hotel['performance_rank'] ?></strong></td>
                                                <td><?= htmlspecialchars($hotel['hotel_name']) ?></td>
                                                <td><?= htmlspecialchars($hotel['city']) ?></td>
                                                <td style="font-weight: 700; color: #2e7d32;"><?= formatRupiah($hotel['total_revenue']) ?></td>
                                                <td>
                                                    <div style="display: flex; align-items: center; gap: 10px;">
                                                        <div style="flex-grow: 1; height: 8px; background: #e0e0e0; border-radius: 4px;">
                                                            <div style="height: 100%; width: <?= $hotel['market_share'] ?>%; background: #1baf7a; border-radius: 4px;"></div>
                                                        </div>
                                                        <span><?= $hotel['market_share'] ?>%</span>
                                                    </div>
                                                </td>
                                                <td><?= number_format($hotel['total_bookings']) ?></td>
                                                <td><?= formatRupiah($hotel['avg_booking_value']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="material-icons">compare</i>
                            <p>Tidak ada data perbandingan yang tersedia.</p>
                            <p class="subtext">Pastikan ada data booking yang completed untuk filter yang dipilih.</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Data dari PHP
        let revenueData = <?= json_encode($revenue_analysis) ?>;
        let comparisonData = <?= json_encode($revenue_comparison) ?>;
        let forecastData = <?= json_encode($revenue_forecast) ?>;
        let currentChart = null;
        let currentView = '<?= $filter_view ?>';

        // Helper function to format currency
        function formatRupiahJs(amount) {
            if (amount === null || amount === undefined || amount === 0) return 'Rp 0';
            const num = parseFloat(amount);
            if (num >= 1000000000) {
                return 'Rp ' + (num / 1000000000).toFixed(2).replace('.', ',') + ' Miliar';
            }
            if (num >= 1000000) {
                return 'Rp ' + (num / 1000000).toFixed(2).replace('.', ',') + ' Juta';
            }
            if (num >= 1000) {
                return 'Rp ' + (num / 1000).toFixed(1).replace('.', ',') + ' Ribu';
            }
            return 'Rp ' + num.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        }

        // Fungsi untuk memperbarui dropdown hotel berdasarkan kota yang dipilih
        function updateHotelDropdown(city) {
            const hotelSelect = document.getElementById('filter_hotel');

            // Show loading
            hotelSelect.innerHTML = '<option value="">Loading...</option>';

            fetch(`revenue_optimization.php?getHotelsByCity=${encodeURIComponent(city)}`)
                .then(response => response.json())
                .then(data => {
                    // Clear options
                    hotelSelect.innerHTML = '<option value="all">Semua Hotel</option>';

                    // Add hotel options
                    Object.entries(data).forEach(([id, name]) => {
                        const option = document.createElement('option');
                        option.value = id;
                        option.textContent = name;
                        hotelSelect.appendChild(option);
                    });

                    // Show info message
                    showFilterMessage(`Terdapat ${Object.keys(data).length} hotel di ${city === 'all' ? 'semua kota' : city}`);
                })
                .catch(error => {
                    console.error('Error loading hotels:', error);
                    hotelSelect.innerHTML = '<option value="all">Semua Hotel</option>';
                    showFilterMessage('Error loading hotels. Please try again.', 'error');
                });
        }

        function showFilterMessage(message, type = 'info') {
            // Create or update message element
            let messageEl = document.querySelector('.filter-message');
            if (!messageEl) {
                messageEl = document.createElement('div');
                messageEl.className = 'filter-message';
                document.querySelector('.filter-controls').prepend(messageEl);
            }

            messageEl.innerHTML = `
                <i class="material-icons">${type === 'error' ? 'error' : 'info'}</i>
                <span>${message}</span>
            `;
            messageEl.style.cssText = `
                grid-column: 1 / -1;
                background: ${type === 'error' ? '#ffebee' : '#e8f4fd'};
                padding: 10px 15px;
                border-radius: 6px;
                border-left: 4px solid ${type === 'error' ? '#e34948' : '#2a78d6'};
                font-size: 13px;
                color: ${type === 'error' ? '#c62828' : '#2c3e50'};
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 10px;
            `;

            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (messageEl) messageEl.remove();
            }, 5000);
        }

        // Initialize charts based on view
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();

            // Initialize sidebar dropdowns
            document.querySelectorAll('.booking-toggle').forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    toggleSidebarMenu(this);
                });
            });

            // Initialize hotel dropdown
            const citySelect = document.getElementById('filter_city');
            if (citySelect) {
                citySelect.addEventListener('change', function() {
                    updateHotelDropdown(this.value);
                });
            }

            // Set active sidebar based on current page
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.sidebar nav a').forEach(link => {
                const href = link.getAttribute('href');
                if (href === currentPage || (href && currentPage.includes(href.replace('.php', '')))) {
                    link.classList.add('active');
                }
            });

            // Initialize tooltips
            initializeTooltips();
        });

        function initializeTooltips() {
            // Add tooltip functionality
            document.querySelectorAll('.filter-tooltip').forEach(tooltip => {
                tooltip.addEventListener('mouseenter', function(e) {
                    const tooltipText = this.getAttribute('data-tooltip');
                    if (tooltipText) {
                        const tooltipEl = document.createElement('div');
                        tooltipEl.className = 'custom-tooltip';
                        tooltipEl.textContent = tooltipText;
                        tooltipEl.style.cssText = `
                            position: absolute;
                            background: #333;
                            color: white;
                            padding: 8px 12px;
                            border-radius: 6px;
                            font-size: 12px;
                            z-index: 10000;
                            max-width: 200px;
                            white-space: normal;
                            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                        `;
                        document.body.appendChild(tooltipEl);

                        const rect = this.getBoundingClientRect();
                        tooltipEl.style.top = (rect.top - tooltipEl.offsetHeight - 5) + 'px';
                        tooltipEl.style.left = (rect.left + rect.width / 2 - tooltipEl.offsetWidth / 2) + 'px';

                        this._tooltipElement = tooltipEl;
                    }
                });

                tooltip.addEventListener('mouseleave', function() {
                    if (this._tooltipElement) {
                        this._tooltipElement.remove();
                        this._tooltipElement = null;
                    }
                });
            });
        }

        function initializeCharts() {
            // Destroy existing charts
            if (currentChart) {
                currentChart.destroy();
                currentChart = null;
            }

            const revenueChartCtx = document.getElementById('revenueChart')?.getContext('2d');
            const trendsCtx = document.getElementById('revenueTrendsChart')?.getContext('2d');
            const comparisonCtx = document.getElementById('revenueComparisonChart')?.getContext('2d');
            const marketShareCtx = document.getElementById('marketShareChart')?.getContext('2d');
            const adrCtx = document.getElementById('adrRevparChart')?.getContext('2d');
            const seasonalCtx = document.getElementById('seasonalChart')?.getContext('2d');

            switch (currentView) {
                case 'overview':
                    if (revenueChartCtx && revenueData.length > 0) {
                        createRevenueOverviewChart(revenueChartCtx);
                    }
                    break;
                case 'trends':
                    if (trendsCtx && revenueData.length > 0 && revenueData[0].revenue_trends) {
                        createRevenueTrendsChart(trendsCtx);
                    }
                    if (adrCtx && revenueData.length > 0 && revenueData[0].revenue_trends) {
                        createAdrRevparChart(adrCtx);
                    }
                    if (seasonalCtx && revenueData.length > 0 && revenueData[0].seasonal_analysis) {
                        createSeasonalChart(seasonalCtx);
                    }
                    break;
                case 'comparison':
                    if (comparisonData.hotels && comparisonData.hotels.length > 0) {
                        if (comparisonCtx) createRevenueComparisonChart(comparisonCtx);
                        if (marketShareCtx) createMarketShareChart(marketShareCtx);
                    }
                    break;
            }
        }

        function createRevenueOverviewChart(ctx) {
            const labels = revenueData.map(h => h.hotel_name.length > 15 ? h.hotel_name.substring(0, 15) + '...' : h.hotel_name);
            const revenues = revenueData.map(h => h.total_revenue);
            const bookings = revenueData.map(h => h.total_bookings);

            currentChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Total Revenue',
                        data: revenues,
                        backgroundColor: 'rgba(76, 175, 80, 0.7)',
                        borderColor: 'rgba(76, 175, 80, 1)',
                        borderWidth: 1,
                        yAxisID: 'y_revenue'
                    }, {
                        label: 'Total Bookings',
                        data: bookings,
                        backgroundColor: 'rgba(33, 150, 243, 0.7)',
                        borderColor: 'rgba(33, 150, 243, 1)',
                        borderWidth: 1,
                        yAxisID: 'y_bookings',
                        type: 'line',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Hotel'
                            },
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        },
                        y_revenue: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Total Revenue'
                            },
                            ticks: {
                                callback: function(value) {
                                    return formatRupiahJs(value);
                                }
                            }
                        },
                        y_bookings: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Total Bookings'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.dataset.label === 'Total Revenue') {
                                        label += formatRupiahJs(context.raw);
                                    } else {
                                        label += context.raw;
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }

        function createRevenueTrendsChart(ctx) {
            const trends = revenueData[0].revenue_trends;
            const labels = trends.map(t => t.period);
            const revenues = trends.map(t => t.revenue);
            const bookings = trends.map(t => t.total_bookings);

            currentChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Revenue',
                        data: revenues,
                        borderColor: 'rgba(76, 175, 80, 1)',
                        backgroundColor: 'rgba(76, 175, 80, 0.1)',
                        yAxisID: 'y_revenue',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Bookings',
                        data: bookings,
                        borderColor: 'rgba(33, 150, 243, 1)',
                        backgroundColor: 'rgba(33, 150, 243, 0.1)',
                        yAxisID: 'y_bookings',
                        tension: 0.4,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Periode'
                            }
                        },
                        y_revenue: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Revenue'
                            },
                            ticks: {
                                callback: function(value) {
                                    return formatRupiahJs(value);
                                }
                            }
                        },
                        y_bookings: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Bookings'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.dataset.label === 'Revenue') {
                                        label += formatRupiahJs(context.raw);
                                    } else {
                                        label += context.raw;
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }

        function createAdrRevparChart(ctx) {
            const trends = revenueData[0].revenue_trends;
            const labels = trends.map(t => t.period);
            const adr = trends.map(t => t.adr);
            const revpar = trends.map(t => t.revpar);

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Average Daily Rate (ADR)',
                        data: adr,
                        backgroundColor: 'rgba(255, 152, 0, 0.7)',
                        borderColor: 'rgba(255, 152, 0, 1)',
                        borderWidth: 1
                    }, {
                        label: 'Revenue Per Available Room (RevPAR)',
                        data: revpar,
                        backgroundColor: 'rgba(156, 39, 176, 0.7)',
                        borderColor: 'rgba(156, 39, 176, 1)',
                        borderWidth: 1,
                        type: 'line',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Amount (IDR)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return formatRupiahJs(value);
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + formatRupiahJs(context.raw);
                                }
                            }
                        }
                    }
                }
            });
        }

        function createSeasonalChart(ctx) {
            const seasonal = revenueData[0].seasonal_analysis;
            const labels = seasonal.map(s => s.month.substring(0, 3));
            const revenues = seasonal.map(s => s.revenue);

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Seasonal Revenue',
                        data: revenues,
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.7)', 'rgba(54, 162, 235, 0.7)', 'rgba(255, 206, 86, 0.7)',
                            'rgba(75, 192, 192, 0.7)', 'rgba(153, 102, 255, 0.7)', 'rgba(255, 159, 64, 0.7)',
                            'rgba(199, 199, 199, 0.7)', 'rgba(83, 102, 255, 0.7)', 'rgba(40, 159, 64, 0.7)',
                            'rgba(255, 99, 132, 0.7)', 'rgba(54, 162, 235, 0.7)', 'rgba(255, 206, 86, 0.7)'
                        ],
                        borderColor: [
                            'rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)', 'rgba(255, 206, 86, 1)',
                            'rgba(75, 192, 192, 1)', 'rgba(153, 102, 255, 1)', 'rgba(255, 159, 64, 1)',
                            'rgba(199, 199, 199, 1)', 'rgba(83, 102, 255, 1)', 'rgba(40, 159, 64, 1)',
                            'rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)', 'rgba(255, 206, 86, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Revenue'
                            },
                            ticks: {
                                callback: function(value) {
                                    return formatRupiahJs(value);
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Revenue: ' + formatRupiahJs(context.raw);
                                }
                            }
                        }
                    }
                }
            });
        }

        function createRevenueComparisonChart(ctx) {
            const hotels = comparisonData.hotels.slice(0, 10);
            const labels = hotels.map(h => h.hotel_name.length > 12 ? h.hotel_name.substring(0, 12) + '...' : h.hotel_name);
            const revenues = hotels.map(h => h.total_revenue);
            const marketShares = hotels.map(h => h.market_share);

            currentChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Total Revenue',
                        data: revenues,
                        backgroundColor: 'rgba(76, 175, 80, 0.7)',
                        borderColor: 'rgba(76, 175, 80, 1)',
                        borderWidth: 1,
                        yAxisID: 'y_revenue'
                    }, {
                        label: 'Market Share (%)',
                        data: marketShares,
                        backgroundColor: 'rgba(33, 150, 243, 0.2)',
                        borderColor: 'rgba(33, 150, 243, 1)',
                        borderWidth: 2,
                        yAxisID: 'y_share',
                        type: 'line',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Hotel'
                            },
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        },
                        y_revenue: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Total Revenue'
                            },
                            ticks: {
                                callback: function(value) {
                                    return formatRupiahJs(value);
                                }
                            }
                        },
                        y_share: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Market Share (%)'
                            },
                            min: 0,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.dataset.label === 'Total Revenue') {
                                        label += formatRupiahJs(context.raw);
                                    } else {
                                        label += context.raw + '%';
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }

        function createMarketShareChart(ctx) {
            const hotels = comparisonData.hotels.slice(0, 8);

            // Jika hanya ada 1 hotel, tampilkan pesan
            if (hotels.length <= 1) {
                ctx.canvas.parentElement.innerHTML = `
                    <div style="text-align: center; padding: 50px 20px; color: #666;">
                        <i class="material-icons" style="font-size: 48px; color: #ccc; margin-bottom: 15px;">pie_chart</i>
                        <p>Market share chart memerlukan minimal 2 hotel</p>
                    </div>
                `;
                return;
            }

            const labels = hotels.map(h => h.hotel_name.length > 15 ? h.hotel_name.substring(0, 15) + '...' : h.hotel_name);
            const data = hotels.map(h => h.market_share);

            const backgroundColors = [
                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                '#9966FF', '#FF9F40', '#8AC926', '#1982C4'
            ];

            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: backgroundColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const hotel = hotels.find(h =>
                                        h.hotel_name.includes(label) ||
                                        label.includes(h.hotel_name.substring(0, 15))
                                    );
                                    const revenue = hotel ? formatRupiahJs(hotel.total_revenue) : '';
                                    return `${label}: ${value}% ${revenue ? '(' + revenue + ')' : ''}`;
                                }
                            }
                        }
                    }
                }
            });
        }

        function changeView(view) {
            // Update active tab
            document.querySelectorAll('.view-tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Update current view
            currentView = view;

            // Update hidden input
            document.querySelector('input[name="view"]').value = view;

            // Submit form
            document.querySelector('.filter-controls').submit();
        }

        // Sidebar Dropdown Functionality
        function toggleSidebarMenu(toggle) {
            const submenu = toggle.nextElementSibling;
            const icon = toggle.querySelector('.toggle-icon');

            if (submenu.classList.contains('show')) {
                submenu.classList.remove('show');
                toggle.classList.remove('active');
                if (icon) icon.style.transform = 'rotate(0deg)';
            } else {
                // Close all other dropdowns
                document.querySelectorAll('.booking-submenu').forEach(sm => sm.classList.remove('show'));
                document.querySelectorAll('.booking-toggle').forEach(t => {
                    t.classList.remove('active');
                    const ic = t.querySelector('.toggle-icon');
                    if (ic) ic.style.transform = 'rotate(0deg)';
                });

                submenu.classList.add('show');
                toggle.classList.add('active');
                if (icon) icon.style.transform = 'rotate(180deg)';
            }
        }

        // Initialize sidebar toggle
        const sidebarToggle = document.getElementById('toggleSidebar');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                const sidebar = document.getElementById('sidebar');
                const mainContent = document.getElementById('main-content');

                if (sidebar && mainContent) {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');
                }
            });
        }

        // Dropdown toggle for user menu
        function toggleDropdown(button) {
            const dropdown = button.nextElementSibling;
            const isOpen = dropdown.classList.contains('show');

            // Close all dropdowns
            document.querySelectorAll('.dropdown-content').forEach(d => d.classList.remove('show'));
            document.querySelectorAll('.user-info').forEach(b => b.classList.remove('open'));

            if (!isOpen) {
                dropdown.classList.add('show');
                button.classList.add('open');
            }
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.user-dropdown')) {
                document.querySelectorAll('.dropdown-content').forEach(d => d.classList.remove('show'));
                document.querySelectorAll('.user-info').forEach(b => b.classList.remove('open'));
            }
        });
    </script>
</body>

</html>