<?php
// ==========================================
// SESSION HANDLING
// ==========================================
session_start();
require_once __DIR__ . '/../_lang.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cek apakah user sudah login
if (!isset($_SESSION['id_user'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("Location: ../auth/login.php");
    exit;
}

// Database connection
require_once __DIR__ . '/../db_config.php';

// ==========================================
// VALIDASI INPUT PARAMETERS
// ==========================================
$hotel_id = isset($_GET['hotel_id']) ? trim($conn->real_escape_string($_GET['hotel_id'])) : null;
$tipe_id = isset($_GET['tipe_id']) ? trim($conn->real_escape_string($_GET['tipe_id'])) : null;
$checkin = isset($_GET['checkin']) ? $_GET['checkin'] : date('Y-m-d');
$checkout = isset($_GET['checkout']) ? $_GET['checkout'] : date('Y-m-d', strtotime('+1 day'));
$kamar = isset($_GET['kamar']) ? intval($_GET['kamar']) : 1;

// Validasi parameter wajib
if (!$hotel_id || !$tipe_id || $kamar < 1) {
    $_SESSION['error'] = t("Parameter pemesanan tidak valid");
    header("Location: hotel.php");
    exit;
}

// Validasi format tanggal
try {
    $date1 = new DateTime($checkin);
    $date2 = new DateTime($checkout);
    if ($date2 <= $date1) {
        throw new Exception(t("Tanggal check-out harus setelah check-in"));
    }
    $durasi = $date2->diff($date1)->days;
} catch (Exception $e) {
    $_SESSION['error'] = t("Format tanggal tidak valid: ") . $e->getMessage();
    header("Location: hotel_detail.php?id=" . urlencode($hotel_id));
    exit;
}

// ==========================================
// GET HOTEL DETAILS
// ==========================================
$hotel = null;
$sql_hotel = "SELECT * FROM hotel WHERE hotel_id = ?";
if ($stmt_hotel = $conn->prepare($sql_hotel)) {
    $stmt_hotel->bind_param("s", $hotel_id);
    $stmt_hotel->execute();
    $result_hotel = $stmt_hotel->get_result();
    if ($result_hotel->num_rows > 0) {
        $hotel = $result_hotel->fetch_assoc();
    }
    $stmt_hotel->close();
}

if (!$hotel) {
    $_SESSION['error'] = t("Hotel tidak ditemukan");
    header("Location: hotel.php");
    exit;
}

// ==========================================
// CHECK ROOM AVAILABILITY
// ==========================================
$room = null;
$sql_room = "SELECT 
                k.hotel_id, 
                k.tipe_id,  
                tk.nama_tipe as tipe_kamar,
                tk.kapasitas_standar as kapasitas, 
                tk.ukuran_standar as ukuran_kamar,
                jh.harga,
                jh.stok_total as stok,
                jh.terbooking,
                (jh.stok_total - jh.terbooking) as available
            FROM kamar k
            JOIN tipe_kamar tk ON k.tipe_id = tk.tipe_id
            JOIN jadwal_hotel jh ON k.hotel_id = jh.hotel_id AND k.tipe_id = jh.tipe_id
            WHERE k.hotel_id = ? 
            AND k.tipe_id = ?
            AND k.status = 'Available'
            AND jh.stok_total > jh.terbooking";

if ($stmt_room = $conn->prepare($sql_room)) {
    $stmt_room->bind_param("ss", $hotel_id, $tipe_id);
    $stmt_room->execute();
    $result_room = $stmt_room->get_result();
    if ($result_room->num_rows > 0) {
        $room = $result_room->fetch_assoc();
    }
    $stmt_room->close();
}

if (!$room || $room['available'] < $kamar) {
    $_SESSION['error'] = t("Kamar tidak tersedia atau stok habis. Tersedia:") . " " . ($room['available'] ?? 0) . " " . t("kamar");
    header("Location: hotel_detail.php?id=" . urlencode($hotel_id));
    exit;
}

// ==========================================
// GET USER DETAILS - PERBAIKAN DISINI
// ==========================================
$user = null;
$user_id = $_SESSION['id_user'];
$customer_id = null;

// Pertama, coba cari di tabel user (jika ada)
$sql_user = "SELECT * FROM user WHERE id_user = ?";
$user_found = false;

if ($stmt_user = $conn->prepare($sql_user)) {
    $stmt_user->bind_param("s", $user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows > 0) {
        $user = $result_user->fetch_assoc();
        $user_found = true;
        error_log("User data found in user table: " . print_r($user, true));
    }
    $stmt_user->close();
}

// The customer row is where customer_id and created_at live — the user
// table has neither. Load it every time, not only as a fallback when the
// user row is missing: skipping it left customer_id null (so the
// "has previous booking" check always came back empty) and created_at
// undefined (so every account read as 0 days old and qualified for the
// new-user discount forever).
$sql_customer = "SELECT * FROM customer WHERE id_user = ?";
if ($stmt_customer = $conn->prepare($sql_customer)) {
    $stmt_customer->bind_param("s", $user_id);
    $stmt_customer->execute();
    $result_customer = $stmt_customer->get_result();
    if ($result_customer->num_rows > 0) {
        $customer_row = $result_customer->fetch_assoc();
        $customer_id = $customer_row['customer_id'];
        // User-table values win on the fields both tables carry.
        $user = $user_found ? array_merge($customer_row, $user) : $customer_row;
    }
    $stmt_customer->close();
}

// Jika masih tidak ditemukan, gunakan data dari session
if (!$user) {
    $user = [
        'nama' => $_SESSION['username'] ?? 'Guest',
        'email' => $_SESSION['email'] ?? '',
        'no_hp' => $_SESSION['no_hp'] ?? '',
        'first_name' => $_SESSION['username'] ?? '',
        'last_name' => ''
    ];
    $customer_id = $user_id;
    error_log("Using session data for user");
}

// Pastikan semua field yang diperlukan ada
$user['nama'] = $user['nama'] ?? $user['first_name'] ?? $_SESSION['username'] ?? 'Guest';
$user['email'] = $user['email'] ?? $_SESSION['email'] ?? '';
$user['no_hp'] = $user['no_hp'] ?? $_SESSION['no_hp'] ?? '';
$user['first_name'] = $user['first_name'] ?? explode(' ', $user['nama'], 2)[0] ?? '';
$user['last_name'] = $user['last_name'] ?? (explode(' ', $user['nama'], 2)[1] ?? '');

error_log("Final user data: " . print_r($user, true));

// ==========================================
// CALCULATE PRICE
// ==========================================
$harga_per_malam = $room['harga'];
$total_harga = $harga_per_malam * $durasi * $kamar;

// ==========================================
// DISCOUNT HANDLING
// ==========================================
$selected_discount_id = null;
$discount_applied = null;
$new_user_discount = null;
$final_price = $total_harga;
$is_new_user_eligible = false;
$days_left_new_user = 0;
$promo_code = isset($_POST['promo_code']) ? trim($_POST['promo_code']) : '';
$promo_error = '';
$promo_success = '';

// ==========================================
// CHECK NEW USER DISCOUNT ELIGIBILITY
// ==========================================
// Check if user has made any booking before
$has_previous_booking = false;
$sql_check_booking = "SELECT COUNT(*) as booking_count 
                     FROM booking_hotel 
                     WHERE customer_id = ?";
if ($stmt_booking_count = $conn->prepare($sql_check_booking)) {
    $stmt_booking_count->bind_param("s", $customer_id);
    $stmt_booking_count->execute();
    $result_count = $stmt_booking_count->get_result();
    $booking_data = $result_count->fetch_assoc();
    $has_previous_booking = ($booking_data['booking_count'] ?? 0) > 0;
    $stmt_booking_count->close();
}

// Check customer creation date from database
$customer_created_at = null;
$days_since_creation = null;
if (!empty($user['created_at'])) {
    try {
        $customer_created_at = new DateTime($user['created_at']);
        $today = new DateTime();
        $interval = $today->diff($customer_created_at);
        $days_since_creation = $interval->days;
    } catch (Exception $e) {
        $days_since_creation = null;
    }
}

// Conditions for NEWUSER discount eligibility. An unknown signup date
// can't prove the account is new, so it doesn't qualify — treating it as
// "0 days old" is what let every account claim this discount.
if ($days_since_creation !== null && $days_since_creation <= 7 && !$has_previous_booking) {
    $is_new_user_eligible = true;
    
    // Check NEWUSER discount in database
    $sql_new_user_discount = "SELECT * FROM diskon_promo 
                    WHERE (kode_promo = 'NEWUSER25' OR diskon_id = 'NEWUSER')
                    AND status = 'active'
                    AND CURDATE() BETWEEN tanggal_mulai AND tanggal_berakhir
                    AND (kuota IS NULL OR terpakai < kuota)
                    LIMIT 1";
    
    $result_discount = $conn->query($sql_new_user_discount);
    if ($result_discount && $result_discount->num_rows > 0) {
        $discount_data = $result_discount->fetch_assoc();
        
        // Calculate discount
        $discount_percentage = $discount_data['nilai_diskon'] ?? 25.00;
        $max_discount = $discount_data['maksimal_diskon'] ?? 50000.00;
        $min_purchase = $discount_data['minimal_pembelian'] ?? 0.00;
        
        if ($total_harga >= $min_purchase) {
            $discount_value = ($total_harga * $discount_percentage) / 100;
            if ($max_discount > 0 && $discount_value > $max_discount) {
                $discount_value = $max_discount;
            }
            
            if ($discount_value < 0) $discount_value = 0;
            
            $new_user_discount = [
                'diskon_id' => $discount_data['diskon_id'],
                'nama_diskon' => $discount_data['nama_diskon'],
                'kode_promo' => $discount_data['kode_promo'],
                'nilai_diskon' => $discount_value,
                'discount_value' => $discount_value,
                'tipe_diskon' => $discount_data['tipe_diskon'],
                'minimal_pembelian' => $discount_data['minimal_pembelian'],
                'maksimal_diskon' => $discount_data['maksimal_diskon'],
                'tanggal_berakhir' => $discount_data['tanggal_berakhir']
            ];
            
            // Auto-apply discount for new users
            $selected_discount_id = $new_user_discount['diskon_id'];
            $discount_applied = $new_user_discount;
            $final_price = $total_harga - $discount_value;
            
            // Calculate days left
            $discount_end_date = new DateTime($discount_data['tanggal_berakhir']);
            $today = new DateTime();
            $interval = $today->diff($discount_end_date);
            $days_left_new_user = $interval->days;
            if ($interval->invert) {
                $days_left_new_user = 0;
            }
        }
    }
}

/**
 * Look a promo code up and work out what it is worth on this booking.
 * Returns ['ok' => true, 'discount_applied' => [...], 'discount_value' => n]
 * or ['ok' => false, 'error' => '...'].
 *
 * Kept separate so booking submission can re-check the code as well: the
 * applied discount used to live only in local variables during the
 * "apply promo" request, so submitting the booking afterwards silently
 * dropped it and charged full price.
 */
function tv_resolve_promo($conn, $promo_code, $total_harga)
{
    if (empty($promo_code)) {
        return ['ok' => false, 'error' => t("Masukkan kode promo")];
    }

    $sql_check_promo = "SELECT * FROM diskon_promo
                       WHERE kode_promo = ?
                       AND status = 'active'
                       AND CURDATE() BETWEEN tanggal_mulai AND tanggal_berakhir
                       AND (kuota IS NULL OR terpakai < kuota)
                       AND minimal_pembelian <= ?";

    $stmt_promo = $conn->prepare($sql_check_promo);
    if (!$stmt_promo) {
        return ['ok' => false, 'error' => t("Kode promo tidak valid atau tidak memenuhi syarat")];
    }

    $stmt_promo->bind_param("sd", $promo_code, $total_harga);
    $stmt_promo->execute();
    $result_promo = $stmt_promo->get_result();

    if ($result_promo->num_rows === 0) {
        $stmt_promo->close();
        return ['ok' => false, 'error' => t("Kode promo tidak valid atau tidak memenuhi syarat")];
    }

    $discount = $result_promo->fetch_assoc();
    $stmt_promo->close();

    // NEWUSER promo cannot be applied manually
    if ($discount['kode_promo'] == 'NEWUSER25' || $discount['diskon_id'] == 'NEWUSER') {
        return ['ok' => false, 'error' => t("Diskon NEWUSER hanya berlaku otomatis untuk pengguna baru")];
    }

    // Calculate discount value
    $discount_value = 0;
    if ($discount['tipe_diskon'] == 'percentage') {
        $discount_value = ($total_harga * $discount['nilai_diskon']) / 100;
        if ($discount['maksimal_diskon'] && $discount_value > $discount['maksimal_diskon']) {
            $discount_value = $discount['maksimal_diskon'];
        }
    } else {
        $discount_value = $discount['nilai_diskon'];
    }

    return [
        'ok' => true,
        'discount_value' => $discount_value,
        'discount_applied' => [
            'diskon_id' => $discount['diskon_id'],
            'nama_diskon' => $discount['nama_diskon'],
            'kode_promo' => $discount['kode_promo'],
            'tipe_diskon' => $discount['tipe_diskon'],
            'nilai_diskon' => $discount['nilai_diskon'],
            'discount_value' => $discount_value,
            'minimal_pembelian' => $discount['minimal_pembelian'],
            'maksimal_diskon' => $discount['maksimal_diskon']
        ]
    ];
}

// ==========================================
// HANDLE POST REQUESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // Handle promo code application
    if (isset($_POST['apply_promo'])) {
        $promo_result = tv_resolve_promo($conn, $promo_code, $total_harga);

        if ($promo_result['ok']) {
            $discount_applied = $promo_result['discount_applied'];
            $selected_discount_id = $discount_applied['diskon_id'];
            $final_price = $total_harga - $promo_result['discount_value'];
            $promo_success = t("Kode promo berhasil diterapkan! Anda hemat") . " Rp " . number_format($promo_result['discount_value'], 0, ',', '.');

            // Override new user discount
            $new_user_discount = null;
            $is_new_user_eligible = false;
        } else {
            $promo_error = $promo_result['error'];
            $selected_discount_id = null;
            $discount_applied = null;
            $final_price = $total_harga;
        }
    }
    
    // Handle discount removal
    elseif (isset($_POST['remove_discount'])) {
        $selected_discount_id = null;
        $discount_applied = null;
        $new_user_discount = null;
        $is_new_user_eligible = false;
        $final_price = $total_harga;
        $promo_code = '';
        $promo_error = '';
        $promo_success = '';
    }
    
    // Handle booking submission
    elseif (isset($_POST['submit_booking'])) {

        // The promo field is part of this same form, so re-check the code
        // here and carry the discount into the session. Without this the
        // discount only ever existed during the "apply promo" request and
        // the customer ended up paying full price.
        if (!empty($promo_code)) {
            $promo_result = tv_resolve_promo($conn, $promo_code, $total_harga);
            if ($promo_result['ok']) {
                $discount_applied = $promo_result['discount_applied'];
                $selected_discount_id = $discount_applied['diskon_id'];
                $final_price = $total_harga - $promo_result['discount_value'];
                $new_user_discount = null;
                $is_new_user_eligible = false;
            }
        }

        // Validate form data
        $customer_name = filter_input(INPUT_POST, 'customer_name', FILTER_SANITIZE_STRING);
        $no_hp = filter_input(INPUT_POST, 'no_hp', FILTER_SANITIZE_STRING);
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $catatan = filter_input(INPUT_POST, 'catatan', FILTER_SANITIZE_STRING);
        $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_STRING);
        $special_requests = filter_input(INPUT_POST, 'special_requests', FILTER_SANITIZE_STRING);

        if (empty($customer_name) || empty($no_hp) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t("Data pemesan tidak valid. Silakan periksa kembali.");
        } else {
            try {
                // Generate temporary booking ID
                $temp_booking_id = 'TEMP' . date('YmdHis') . strtoupper(substr(uniqid(), -5));

                // Split customer name for first_name and last_name
                $name_parts = explode(' ', $customer_name, 2);
                $first_name = $name_parts[0] ?? '';
                $last_name = $name_parts[1] ?? '';

                // Save booking data to session
                $_SESSION['temp_booking'] = [
                    'temp_id' => $temp_booking_id,
                    'customer_id' => $customer_id,
                    'customer_name' => $customer_name,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'no_hp' => $no_hp,
                    'email' => $email,
                    'gender' => $gender,
                    'catatan' => $catatan,
                    'special_requests' => $special_requests,
                    'hotel_id' => $hotel_id,
                    'tipe_id' => $tipe_id,
                    'check_in' => $checkin,
                    'check_out' => $checkout,
                    'jumlah_kamar' => $kamar,
                    'total_harga' => $total_harga,
                    'total_setelah_diskon' => $final_price,
                    'nilai_diskon' => $discount_applied ? ($discount_applied['discount_value'] ?? 0) : 0,
                    'diskon_id' => $selected_discount_id,
                    'kode_promo' => $discount_applied['kode_promo'] ?? '',
                    'durasi' => $durasi,
                    'hotel_data' => $hotel,
                    'room_data' => $room,
                    'selected_discount_id' => $selected_discount_id,
                    'discount_applied' => $discount_applied,
                    'is_new_user_discount' => $is_new_user_eligible && $new_user_discount ? true : false,
                    'base_harga_kamar' => $total_harga
                ];
                
                // Clear any old redirect URL
                if (isset($_SESSION['redirect_url'])) {
                    unset($_SESSION['redirect_url']);
                }
                
                // Redirect to service offer page
                header("Location: extra_services.php");
                exit();
            } catch (Exception $e) {
                $error = t("Gagal memproses data pemesanan: ") . $e->getMessage();
            }
        }
    }
}

// ==========================================
// FUNCTIONS
// ==========================================
function getImagePath($imagePath, $defaultPath = '../../img/default-hotel.jpg')
{
    if (empty($imagePath)) {
        return $defaultPath;
    }

    // Jika path sudah lengkap (http:// atau https://), gunakan langsung
    if (strpos($imagePath, 'http') === 0 || strpos($imagePath, 'https://') === 0) {
        return $imagePath;
    }
    // Jika path dimulai dengan ../ atau img/, gunakan langsung
    else if (strpos($imagePath, '../') === 0 || strpos($imagePath, 'img/') === 0) {
        return $imagePath;
    }
    // Jika hanya nama file, tambahkan path default
    else {
        // Cek apakah file ada di berbagai lokasi yang mungkin
        $possiblePaths = [
            '../../img/' . $imagePath,
            'img/' . $imagePath,
            '../../uploads/' . $imagePath,
            'uploads/' . $imagePath,
            '../' . $imagePath,
            $imagePath
        ];

        // Gunakan path pertama yang valid
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Jika tidak ditemukan, gunakan path default
        return $defaultPath;
    }
}

// Handle hotel image path
$hotel_image_path = getImagePath($hotel['foto_hotel'], '../../img/default-hotel.jpg');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>TripVerse - <?= te('Pemesanan Hotel') ?></title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicon -->
    <link href="../../img/favicon.ico" rel="icon">

    <!-- Google Web Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Libraries Stylesheet -->
    <link href="../../lib/animate/animate.min.css" rel="stylesheet">
    <link href="../../lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="../../lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet" />

    <!-- Customized Bootstrap Stylesheet -->
    <link href="../../css/bootstrap.min.css" rel="stylesheet">

    <!-- Template Stylesheet -->
    <link href="../../css/style.css?v=2.0" rel="stylesheet">
    <link href="../../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>" rel="stylesheet">

    <style>
        /* [Keep all your CSS styles unchanged] */
        :root {
            --primary: #FEA116;
            --primary-light: #FF7A3D;
            --primary-dark: #E8890A;
            --secondary: #FEA116;
            --dark: #2c3e50;
            --light: #f8f9fa;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --success: #16A34A;
            --danger: #DC2626;
            --info: #17a2b8;
            --warning: #ffc107;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        body {
            font-family: 'Heebo', system-ui, -apple-system, sans-serif;
            line-height: 1.6;
            color: var(--dark);
            background-color: #f5f7fa;
        }

        .booking-container {
            background: white;
            border-radius: 18px;
            box-shadow: var(--shadow-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            transition: var(--transition);
            animation: tv-card-in-up .6s cubic-bezier(.22, 1, .36, 1);
        }

        @keyframes tv-card-in-up {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .booking-container:hover {
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        }

        .booking-header {
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
            position: relative;
        }

        .booking-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gray-light), transparent);
        }

        .progress-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-bottom: 30px;
        }

        .progress-steps::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 0;
            right: 0;
            height: 3px;
            background: #e9ecef;
            z-index: 1;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 2;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            color: var(--gray);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 10px;
            border: 3px solid white;
            transition: all .4s cubic-bezier(.22, 1, .36, 1);
        }

        .step.active .step-number {
            background: linear-gradient(135deg, #FEA116 0%, #FF7A3D 100%);
            color: white;
            box-shadow: 0 8px 20px rgba(254, 161, 22, .45);
            animation: tv-pulse-glow 2.2s infinite;
            transform: scale(1.08);
        }

        .step.completed .step-number {
            background: var(--success);
            color: white;
        }

        .step.completed .step-number::after {
            content: '\f00c';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            font-size: 1rem;
            margin-left: 0.2rem;
        }

        .step-title {
            font-size: 14px;
            color: var(--gray);
            text-align: center;
            transition: color .3s ease;
        }

        .step.active .step-title {
            color: var(--primary);
            font-weight: 700;
        }

        .step.completed .step-title {
            color: var(--success);
        }

        .section-title {
            font-size: 1.375rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 1.75rem;
        }

        /* style.css decorates .section-title with dashes pinned 55px outside
           the element, which suits the centered marketing headings it was
           written for. In these cards it is a plain left-aligned heading, so
           those dashes spilled out past the card's rounded edge. The footer
           on this page keeps its own decoration. */
        .booking-container .section-title::before,
        .booking-container .section-title::after,
        .summary-card .section-title::before,
        .summary-card .section-title::after {
            display: none;
        }

        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--dark);
            display: block;
        }

        .form-control,
        .form-select {
            border-radius: 8px;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-light);
            transition: var(--transition);
            font-size: 0.9375rem;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(254, 161, 22, 0.15);
            outline: none;
        }

        .btn {
            font-weight: 500;
            letter-spacing: 0.5px;
            transition: var(--transition);
        }

        .btn-primary {
            background: linear-gradient(135deg, #FEA116 0%, #FF7A3D 100%);
            border: none;
            padding: 0.8rem 1.9rem;
            border-radius: 999px;
            font-weight: 600;
            box-shadow: 0 10px 24px rgba(254, 161, 22, 0.35);
            transition: transform .3s cubic-bezier(.22, 1, .36, 1), box-shadow .3s ease, filter .3s ease;
        }

        .btn-primary:hover {
            filter: brightness(1.06);
            transform: translateY(-3px);
            box-shadow: 0 14px 30px rgba(254, 161, 22, 0.45);
        }

        .btn-primary:active {
            transform: translateY(-1px) scale(.98);
        }

        .btn-outline-secondary {
            padding: 0.75rem 1.75rem;
            border-radius: 999px;
            border-width: 2px;
            transition: transform .3s cubic-bezier(.22, 1, .36, 1);
        }

        .btn-outline-secondary:hover {
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        .summary-card {
            background: white;
            border-radius: 18px;
            box-shadow: var(--shadow-md);
            padding: 1.75rem;
            position: sticky;
            top: 1.5rem;
            transition: var(--transition);
            border-top: 4px solid transparent;
            border-image: linear-gradient(135deg, #FEA116, #FF7A3D) 1;
        }

        .summary-card:hover {
            box-shadow: var(--shadow-lg);
        }

        .hotel-image {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 1rem;
            box-shadow: var(--shadow-sm);
            background-color: #f8f9fa;
            display: block;
        }

        .price-detail {
            border-top: 1px dashed var(--gray-light);
            border-bottom: 1px dashed var(--gray-light);
            padding: 1rem 0;
            margin: 1rem 0;
        }

        .price-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            font-size: 0.9375rem;
        }

        .price-total {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--primary);
        }

        .alert {
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
        }

        .alert-info {
            background-color: rgba(23, 162, 184, 0.08);
            border-color: rgba(23, 162, 184, 0.2);
            color: var(--info);
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.08);
            border-color: rgba(40, 167, 69, 0.2);
            color: var(--success);
        }

        .alert-danger {
            background-color: rgba(220, 53, 69, 0.08);
            border-color: rgba(220, 53, 69, 0.2);
            color: var(--danger);
        }

        .alert-warning {
            background-color: rgba(255, 193, 7, 0.08);
            border-color: rgba(255, 193, 7, 0.2);
            color: var(--warning);
        }

        .booker-details {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .booker-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .booker-subtitle {
            color: var(--gray);
            margin-bottom: 2rem;
            font-size: 0.9375rem;
        }

        .gender-selection {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .gender-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .gender-checkbox {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }

        .info-field {
            margin-bottom: 1.5rem;
        }

        .info-field label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        .info-field input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            font-size: 0.9375rem;
            transition: var(--transition);
        }

        .info-field input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(254, 161, 22, 0.15);
            outline: none;
        }

        .info-note {
            font-size: 0.875rem;
            color: var(--gray);
            margin-top: 0.5rem;
        }

        .divider {
            height: 1px;
            background: var(--gray-light);
            margin: 2rem 0;
        }

        .room-details {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .room-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }

        .room-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .room-subtitle {
            color: var(--gray);
            font-size: 0.9375rem;
        }

        .room-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: var(--dark);
        }

        .feature-check {
            color: var(--success);
            font-size: 1rem;
        }

        .special-request-btn {
            background: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.875rem;
        }

        .special-request-btn:hover {
            background: var(--primary);
            color: white;
        }

        .promo-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .promo-input-group {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .promo-input-group input {
            flex: 1;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            font-size: 0.9375rem;
        }

        .promo-input-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(254, 161, 22, 0.15);
            outline: none;
        }

        .applied-promo {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.2);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .promo-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .promo-info i {
            color: var(--success);
        }

        .special-request-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1060;
            align-items: center;
            justify-content: center;
        }

        .request-modal-content {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .request-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark);
        }

        .request-options {
            margin-bottom: 1.5rem;
        }

        .request-option {
            margin-bottom: 1rem;
        }

        .request-option label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            font-size: 0.9375rem;
        }

        .request-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            resize: vertical;
            min-height: 80px;
            font-family: inherit;
        }

        .request-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .user-welcome {
            background: linear-gradient(135deg, #FEA116, #FF7A3D);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 600;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            .user-welcome {
                font-size: 0.8rem;
                margin-top: 0.5rem;
                text-align: center;
            }
        }

        .footer {
            background-color: #0f172a;
            color: #e2e8f0;
            padding: 3rem 0 1.5rem;
            margin-top: 3rem;
        }

        .new-user-banner {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: var(--shadow-md);
        }

        .new-user-banner i {
            font-size: 2rem;
        }

        .new-user-banner-content h4 {
            margin: 0;
            font-weight: 600;
        }

        .new-user-banner-content p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
        }

        .discount-timer {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 0.25rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .progress-steps {
                flex-direction: column;
                align-items: flex-start;
                gap: 1.5rem;
            }

            .progress-steps::before {
                display: none;
            }

            .step {
                flex-direction: row;
                align-items: center;
                width: 100%;
            }

            .step-number {
                margin-right: 1rem;
                margin-bottom: 0;
            }

            .step-title {
                text-align: left;
                max-width: none;
            }

            .summary-card {
                position: static;
                margin-top: 2rem;
            }

            .gender-selection {
                flex-wrap: wrap;
                row-gap: 0.75rem;
                column-gap: 1.5rem;
            }

            .room-features {
                grid-template-columns: 1fr;
            }

            .promo-input-group {
                flex-direction: column;
            }

            .promo-input-group button {
                width: 100%;
            }

            .new-user-banner {
                flex-direction: column;
                text-align: center;
            }
        }

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

        .booking-container,
        .summary-card {
            animation: fadeIn 0.5s ease-out forwards;
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

        .pulse {
            animation: pulse 2s infinite;
        }
    </style>
</head>

<body>
    <!-- Spinner -->
    <div id="spinner" class="show bg-white position-fixed translate-middle w-100 vh-100 top-50 start-50 d-flex align-items-center justify-content-center">
        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Header -->
    <header class="container-fluid bg-dark px-0">
        <div class="row gx-0">
            <div class="col-lg-3 bg-dark d-none d-lg-flex align-items-center justify-content-center">
                <a href="home.php" class="d-flex align-items-center text-decoration-none">
                    <img src="../../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 50px;">
                    <span class="tv-wordmark tv-wordmark-header">TripVerse</span>
                </a>
            </div>
            <div class="col-lg-9">
                <div class="row gx-0 bg-white d-none d-lg-flex align-items-center py-2 px-5">
                    <div class="col-lg-7 text-start">
                        <div class="d-inline-flex align-items-center me-4">
                            <i class="fa fa-envelope text-primary me-2"></i>
                            <p class="mb-0">tripverse@gmail.com</p>
                        </div>
                        <div class="d-inline-flex align-items-center">
                            <i class="fa fa-phone-alt text-primary me-2"></i>
                            <p class="mb-0">+62 878 0677 6235</p>
                        </div>
                    </div>
                    <div class="col-lg-5 text-end">
                        <div class="d-inline-flex align-items-center">
                            <a class="me-3" href="#"><i class="fab fa-facebook-f"></i></a>
                            <a class="me-3" href="#"><i class="fab fa-twitter"></i></a>
                            <a class="me-3" href="#"><i class="fab fa-linkedin-in"></i></a>
                            <a class="me-3" href="#"><i class="fab fa-instagram"></i></a>
                            <a class="" href="#"><i class="fab fa-youtube"></i></a>
                        </div>
                    </div>
                </div>
                <nav class="navbar navbar-expand-xl bg-dark navbar-dark p-3 p-lg-0">
                    <a href="home.php" class="navbar-brand d-block d-xl-none">
                        <img src="../../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 40px;">
                        <span class="tv-wordmark tv-wordmark-header">TripVerse</span>
                    </a>
                    <button type="button" class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#navbarCollapse">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse justify-content-between" id="navbarCollapse">
                        <div class="navbar-nav mr-auto py-0">
                            <a href="home.php" class="nav-item nav-link"><?= te("Beranda") ?></a>
                            <a href="about.php" class="nav-item nav-link"><?= te("Tentang Kami") ?></a>
                            <a href="hotel.php" class="nav-item nav-link active"><?= te("Hotel") ?></a>
                                <a href="flights.php" class="nav-item nav-link"><?= te("Pesawat") ?></a>
                            <a href="service.php" class="nav-item nav-link"><?= te("Fitur") ?></a>
                            <a href="team.php" class="nav-item nav-link"><?= te("Tim Kami") ?></a>
                            <a href="contact.php" class="nav-item nav-link"><?= te("Kontak") ?></a>
                            <a href="history.php" class="nav-item nav-link"><?= te("Riwayat") ?></a>
                        </div>
                        <?php include __DIR__ . '/../_lang_switch.php'; ?><?php include __DIR__ . '/../_account_menu.php'; ?>
                    </div>
                </nav>
            </div>
        </div>
    </header>

    <!-- Page Header -->
    <div class="container-fluid page-header mb-5 p-0" style="background-image: url(../../img/carousel-1.jpg);">
        <div class="container-fluid page-header-inner py-5">
            <div class="container text-center pb-5">
                <h1 class="display-3 text-white mb-3 animated slideInDown"><?= te('Pemesanan Hotel') ?></h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb justify-content-center text-uppercase">
                        <li class="breadcrumb-item"><a href="home.php"><?= te('Beranda') ?></a></li>
                        <li class="breadcrumb-item"><a href="hotel.php"><?= te('Hotel') ?></a></li>
                        <li class="breadcrumb-item text-white active" aria-current="page"><?= te('Pemesanan') ?></li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <!-- Booking Content -->
    <div class="container-fluid py-5">
        <div class="container">
            <div class="row g-4">
                <!-- Main Content -->
                <div class="col-lg-8">
                    <!-- New User Discount Banner -->
                    <?php if ($is_new_user_eligible && $new_user_discount): ?>
                        <div class="new-user-banner pulse">
                            <i class="fas fa-gift"></i>
                            <div class="new-user-banner-content flex-grow-1">
                                <h4><?= te('Selamat! Diskon Pengguna Baru 25% Telah Aktif') ?></h4>
                                <p><?= te('Anda mendapatkan diskon khusus untuk pengguna baru TripVerse. Voucher ini akan hangus dalam:') ?></p>
                            </div>
                            <div class="discount-timer">
                                <i class="fas fa-clock me-1"></i>
                                <?= $days_left_new_user > 0 ? "$days_left_new_user " . t('hari') : te('Kurang dari 1 hari') ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="booking-container">
                        <div class="booking-header">
                            <h2 class="mb-3"><?= te('Pemesanan Hotel') ?></h2>

                            <!-- Progress Steps -->
                            <div class="progress-steps">
                                <div class="step active">
                                    <div class="step-number">1</div>
                                    <div class="step-title"><?= te('Detail Pesanan') ?></div>
                                </div>
                                <div class="step">
                                    <div class="step-number">2</div>
                                    <div class="step-title"><?= te('Penawaran Layanan') ?></div>
                                </div>
                                <div class="step">
                                    <div class="step-number">3</div>
                                    <div class="step-title"><?= te('Pembayaran') ?></div>
                                </div>
                                <div class="step">
                                    <div class="step-number">4</div>
                                    <div class="step-title"><?= te('Konfirmasi') ?></div>
                                </div>
                            </div>
                        </div>

                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <strong>Error!</strong> <?= $error ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="booking.php?hotel_id=<?= htmlspecialchars($hotel_id) ?>&tipe_id=<?= htmlspecialchars($tipe_id) ?>&checkin=<?= htmlspecialchars($checkin) ?>&checkout=<?= htmlspecialchars($checkout) ?>&kamar=<?= htmlspecialchars($kamar) ?>">

                            <!-- Promo Code Section -->
                            <div class="promo-section">
                                <h4 class="section-title"><?= te('Kode Promo') ?></h4>
                                <p class="booker-subtitle"><?= te('Masukkan kode promo untuk mendapatkan diskon spesial') ?></p>

                                <?php if ($promo_error): ?>
                                    <div class="alert alert-danger">
                                        <?= $promo_error ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($promo_success): ?>
                                    <div class="alert alert-success">
                                        <?= $promo_success ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($discount_applied): ?>
                                    <?php /* The visible promo input is replaced by this summary, so carry the
                                             code along or submitting the booking would post no promo at all
                                             and the discount would be lost. */ ?>
                                    <input type="hidden" name="promo_code" value="<?= htmlspecialchars($discount_applied['kode_promo'] ?? '') ?>">
                                    <div class="applied-promo">
                                        <div class="promo-info">
                                            <i class="fas fa-check-circle"></i>
                                            <span>
                                                <strong><?= htmlspecialchars($discount_applied['nama_diskon'] ?? $discount_applied['diskon_terpilih']['nama_diskon']) ?></strong> -
                                                <?= htmlspecialchars($discount_applied['kode_promo'] ?? t('Diskon Otomatis')) ?>
                                                <?php if ($discount_applied['minimal_pembelian'] ?? $discount_applied['diskon_terpilih']['minimal_pembelian'] ?? 0): ?>
                                                    <small class="text-muted ms-2">
                                                        (<?= te('Min. belanja:') ?> Rp <?= number_format($discount_applied['minimal_pembelian'] ?? $discount_applied['diskon_terpilih']['minimal_pembelian'] ?? 0, 0, ',', '.') ?>)
                                                    </small>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <button type="submit" name="remove_discount" class="btn btn-sm btn-outline-danger">
                                            <?= te('Hapus') ?>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="promo-input-group">
                                        <input type="text" name="promo_code" value="<?= htmlspecialchars($promo_code) ?>"
                                            placeholder="<?= te('Masukkan kode promo') ?>" maxlength="50">
                                        <button type="submit" name="apply_promo" class="btn btn-primary">
                                            <?= te('Terapkan') ?>
                                        </button>
                                    </div>
                                <?php endif; ?>

                                <div class="info-note mt-2">
                                    <i class="fas fa-info-circle text-secondary me-1"></i>
                                    <?= te('Kode promo hanya bisa digunakan sekali dan tidak dapat digabungkan dengan promo lainnya.') ?>
                                    <?= te('Minimal pembelian:') ?> Rp <?= number_format($total_harga, 0, ',', '.') ?>
                                </div>
                            </div>

                            <!-- Booker Details Section -->
                            <div class="booker-details">
                                <h3 class="booker-title"><?= te('Detail Pemesan') ?></h3>
                                <p class="booker-subtitle"><?= te('Lengkapi detailnya dengan datamu. Ini akan dikirim ke hotel untuk keperluan pemesanan.') ?></p>

                                <!-- Gender Selection -->
                                <div class="gender-selection">
                                    <label class="gender-option">
                                        <input type="radio" name="gender" value="Tuan" class="gender-checkbox" checked>
                                        <span><?= te('Tuan') ?></span>
                                    </label>
                                    <label class="gender-option">
                                        <input type="radio" name="gender" value="Nyonya" class="gender-checkbox">
                                        <span><?= te('Nyonya') ?></span>
                                    </label>
                                    <label class="gender-option">
                                        <input type="radio" name="gender" value="Nona" class="gender-checkbox">
                                        <span><?= te('Nona') ?></span>
                                    </label>
                                </div>

                                <!-- Personal Information -->
                                <div class="info-field">
                                    <label><?= te('Nama Lengkap Sesuai Identitas') ?></label>
                                    <input type="text" name="customer_name" value="<?= htmlspecialchars($user['nama']) ?>" required>
                                </div>

                                <div class="info-field">
                                    <label><?= te('Nomor Telepon') ?></label>
                                    <input type="tel" name="no_hp" value="<?= htmlspecialchars($user['no_hp']) ?>" required>
                                    <div class="info-note"><?= te('Nomor telepon aktif untuk konfirmasi pemesanan.') ?></div>
                                </div>

                                <div class="info-field">
                                    <label><?= te('Alamat Email') ?></label>
                                    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                                    <div class="info-note"><?= te('Konfirmasi pemesanan akan dikirim ke email ini.') ?></div>
                                </div>
                            </div>

                            <div class="divider"></div>

                            <!-- Room Details Section -->
                            <div class="room-details">
                                <div class="room-header">
                                    <div>
                                        <h4 class="room-title"><?= htmlspecialchars($hotel['nama_hotel']) ?></h4>
                                        <p class="room-subtitle">
                                            <?= date('D, d M Y', strtotime($checkin)) ?> - <?= date('D, d M Y', strtotime($checkout)) ?><br>
                                            <?= $durasi ?> <?= t('Malam') ?> • <?= $kamar ?> <?= te('Kamar') ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Room Features -->
                                <div class="room-features">
                                    <div class="feature-item">
                                        <span class="feature-check">✓</span>
                                        <span><?= $room['kapasitas'] ?> <?= te('Tamu') ?></span>
                                    </div>
                                    <div class="feature-item">
                                        <span class="feature-check">✓</span>
                                        <span>Double Bed</span>
                                    </div>
                                    <div class="feature-item">
                                        <span class="feature-check">✓</span>
                                        <span><?= te('Sarapan') ?></span>
                                    </div>
                                    <div class="feature-item">
                                        <span class="feature-check">✓</span>
                                        <span><?= te('WiFi Gratis') ?></span>
                                    </div>
                                    <div class="feature-item">
                                        <span class="feature-check">✓</span>
                                        <span><?= te('Kamar Non-smoking') ?></span>
                                    </div>
                                </div>

                                <!-- Room Assignment -->
                                <div class="info-field">
                                    <label><?= te('Kamar') ?> 1 : <?= htmlspecialchars($user['nama']) ?></label>
                                    <button type="button" class="special-request-btn" onclick="openSpecialRequest()">
                                        <?= te('Ada Permintaan Khusus?') ?>
                                    </button>
                                </div>
                            </div>

                            <!-- Special Request Modal -->
                            <div class="special-request-modal" id="specialRequestModal">
                                <div class="request-modal-content">
                                    <h4 class="request-title"><?= te('Permintaan Khusus') ?></h4>
                                    <p class="booker-subtitle"><?= te('Pemenuhan permintaan tergantung pada ketersediaan dan mungkin dikenakan biaya tambahan saat check-in.') ?></p>

                                    <div class="request-options">
                                        <div class="request-option">
                                            <label>
                                                <input type="checkbox" name="special_floor" value="lantai_atas">
                                                <span><?= te('Lantai Atas') ?></span>
                                            </label>
                                        </div>
                                        <div class="request-option">
                                            <label>
                                                <input type="checkbox" name="special_door" value="pintu_terhubung">
                                                <span><?= te('Kamar dengan Pintu Terhubung') ?></span>
                                            </label>
                                        </div>
                                        <div class="request-option">
                                            <label>
                                                <input type="checkbox" name="special_checkin" value="waktu_checkin">
                                                <span><?= te('Waktu Check-in Khusus') ?></span>
                                            </label>
                                        </div>
                                        <div class="request-option">
                                            <label>
                                                <input type="checkbox" name="special_checkout" value="waktu_checkout">
                                                <span><?= te('Waktu Check-out Khusus') ?></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="info-field">
                                        <label><?= te('Isi Permintaan Lain') ?></label>
                                        <textarea name="special_notes" class="request-textarea" placeholder="<?= te('Tulis permintaan khusus lainnya di sini...') ?>"></textarea>
                                    </div>

                                    <div class="request-actions">
                                        <button type="button" class="btn btn-outline-secondary" onclick="closeSpecialRequest()"><?= te('Batal') ?></button>
                                        <button type="button" class="btn btn-primary" onclick="saveSpecialRequest()"><?= te('Simpan') ?></button>
                                    </div>
                                </div>
                            </div>

                            <!-- Catatan Khusus -->
                            <div class="info-field">
                                <label for="catatan"><?= te('Catatan Khusus (Opsional)') ?></label>
                                <textarea class="form-control" id="catatan" name="catatan" rows="3" placeholder="<?= te('Masukkan catatan khusus jika ada') ?>"></textarea>
                            </div>

                            <!-- Hidden Fields -->
                            <input type="hidden" name="hotel_id" value="<?= htmlspecialchars($hotel_id) ?>">
                            <input type="hidden" name="tipe_id" value="<?= htmlspecialchars($tipe_id) ?>">
                            <input type="hidden" name="checkin" value="<?= htmlspecialchars($checkin) ?>">
                            <input type="hidden" name="checkout" value="<?= htmlspecialchars($checkout) ?>">
                            <input type="hidden" name="kamar" value="<?= htmlspecialchars($kamar) ?>">
                            <input type="hidden" name="harga" value="<?= htmlspecialchars($room['harga']) ?>">
                            <input type="hidden" name="total_harga" value="<?= htmlspecialchars($total_harga) ?>">
                            <input type="hidden" name="special_requests" id="specialRequests" value="">
                            <input type="hidden" name="diskon_id" value="<?= htmlspecialchars($selected_discount_id) ?>">

                            <!-- Navigation Buttons -->
                            <div class="d-flex justify-content-between mt-5">
                                <a href="hotel_detail.php?id=<?= $hotel_id ?>" class="btn btn-outline-secondary px-4 py-2">
                                    <i class="fas fa-arrow-left me-2"></i> <?= te('Kembali') ?>
                                </a>
                                <button type="submit" name="submit_booking" class="btn btn-primary px-4 py-2">
                                    <?= te('Lanjutkan') ?> <i class="fas fa-arrow-right ms-2"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="col-lg-4">
                    <div class="summary-card">
                        <h4 class="mb-4"><?= te('Ringkasan Pesanan') ?></h4>

                        <img src="<?= htmlspecialchars($hotel_image_path) ?>"
                            alt="<?= htmlspecialchars($hotel['nama_hotel']) ?>"
                            class="hotel-image"
                            onerror="this.onerror=null; this.src='../../img/default-hotel.jpg';">

                        <h5><?= htmlspecialchars($hotel['nama_hotel']) ?></h5>
                        <p class="text-muted mb-3">
                            <i class="fas fa-map-marker-alt text-secondary me-1"></i>
                            <?= htmlspecialchars($hotel['kota']) ?>
                        </p>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted"><?= te('Tipe Kamar:') ?></span>
                                <span><?= htmlspecialchars($room['tipe_kamar']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted"><?= te('Durasi:') ?></span>
                                <span><?= $durasi ?> <?= t('malam') ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted"><?= te('Jumlah Kamar:') ?></span>
                                <span><?= $kamar ?></span>
                            </div>
                        </div>

                        <div class="price-detail">
                            <div class="price-item">
                                <span><?= te('Harga Kamar') ?> (<?= $durasi ?> <?= t('malam') ?>)</span>
                                <span>Rp <?= number_format($room['harga'] * $durasi * $kamar, 0, ',', '.') ?></span>
                            </div>

                            <?php if ($discount_applied): ?>
                                <div class="price-item text-success">
                                    <span>
                                        <i class="fas fa-tag me-1"></i>
                                        <?= htmlspecialchars($discount_applied['nama_diskon'] ?? $discount_applied['diskon_terpilih']['nama_diskon']) ?>
                                    </span>
                                    <span>- Rp <?= number_format($discount_applied['discount_value'] ?? $discount_applied['nilai_diskon'], 0, ',', '.') ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="price-item">
                                <span><?= te('Pajak & Layanan') ?></span>
                                <span><?= te('Termasuk') ?></span>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <h5><?= te('Total Pembayaran') ?></h5>
                            <div class="text-end">
                                <?php if ($discount_applied): ?>
                                    <div class="text-muted text-decoration-line-through small">
                                        Rp <?= number_format($total_harga, 0, ',', '.') ?>
                                    </div>
                                <?php endif; ?>
                                <h4 class="price-total mb-0">Rp <?= number_format($final_price, 0, ',', '.') ?></h4>
                                <?php if ($discount_applied): ?>
                                    <small class="text-success">
                                        <i class="fas fa-piggy-bank me-1"></i>
                                        <?= te('Hemat') ?> Rp <?= number_format($discount_applied['discount_value'] ?? $discount_applied['nilai_diskon'], 0, ',', '.') ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($is_new_user_eligible && $new_user_discount): ?>
                            <div class="alert alert-info mt-3">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-gift me-2"></i>
                                    <div>
                                        <strong><?= te('Diskon Pengguna Baru Aktif!') ?></strong><br>
                                        <small><?= te('Voucher ini akan hangus dalam') ?> <?= $days_left_new_user > 0 ? "$days_left_new_user " . t('hari') : t('kurang dari 1 hari') ?></small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="container-fluid bg-dark text-light footer wow fadeIn" data-wow-delay="0.1s">
        <div class="container pb-5">
            <div class="row g-5">
                <div class="col-md-6 col-lg-4">
                    <div class="bg-primary rounded p-4 d-flex align-items-center">
                        <a href="home.php">
                            <img src="../../img/logo.png" alt="TripVerse Logo" width="50" class="me-3">
                        </a>
                        <a href="home.php">
                            <span class="tv-wordmark tv-wordmark-footer">TripVerse</span>
                        </a>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <h6 class="section-title text-start text-primary text-uppercase mb-4">Contact</h6>
                    <p class="mb-2"><i class="fa fa-map-marker-alt me-3"></i>Jl. Wisata No. 45, Jakarta, Indonesia</p>
                    <p class="mb-2"><i class="fa fa-phone-alt me-3"></i>+62 878 0677 6235</p>
                    <p class="mb-2"><i class="fa fa-envelope me-3"></i>tripverse@gmail.com</p>
                    <div class="d-flex pt-2">
                        <a class="btn btn-outline-light btn-social mx-1" href="#"><i class="fab fa-twitter"></i></a>
                        <a class="btn btn-outline-light btn-social mx-1" href="#"><i class="fab fa-facebook-f"></i></a>
                        <a class="btn btn-outline-light btn-social mx-1" href="#"><i class="fab fa-youtube"></i></a>
                        <a class="btn btn-outline-light btn-social mx-1" href="#"><i class="fab fa-linkedin-in"></i></a>
                        <a class="btn btn-outline-light btn-social mx-1" href="#"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
                <div class="col-lg-5 col-md-12">
                    <div class="row gy-5 g-4">
                        <div class="col-md-6">
                            <h6 class="section-title text-start text-primary text-uppercase mb-4">Company</h6>
                            <a class="btn btn-link" href="#">About Us</a>
                            <a class="btn btn-link" href="#">Contact Us</a>
                            <a class="btn btn-link" href="#">Privacy Policy</a>
                            <a class="btn btn-link" href="#">Terms & Condition</a>
                            <a class="btn btn-link" href="#">Support</a>
                        </div>
                        <div class="col-md-6">
                            <h6 class="section-title text-start text-primary text-uppercase mb-4">Services</h6>
                            <a class="btn btn-link" href="#">Food & Restaurant</a>
                            <a class="btn btn-link" href="#">Spa & Fitness</a>
                            <a class="btn btn-link" href="#">Sports & Gaming</a>
                            <a class="btn btn-link" href="#">Event & Party</a>
                            <a class="btn btn-link" href="#">GYM & Yoga</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container">
            <div class="copyright">
                <div class="row">
                    <div class="col-md-6 text-center text-md-start mb-3 mb-md-0">
                        &copy; <a class="border-bottom" href="#">TripVerse</a>, All Right Reserved.
                    </div>
                    <div class="col-md-6 text-center text-md-end">
                        <div class="footer-menu">
                            <a href="home.php">Home</a>
                            <a href="#">Cookies</a>
                            <a href="#">Help</a>
                            <a href="#">FQAs</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../lib/wow/wow.min.js"></script>
    <script src="../../lib/easing/easing.min.js"></script>
    <script src="../../lib/waypoints/waypoints.min.js"></script>
    <script src="../../lib/counterup/counterup.min.js"></script>
    <script src="../../lib/owlcarousel/owl.carousel.min.js"></script>
    <script src="../../lib/tempusdominus/js/moment.min.js"></script>
    <script src="../../lib/tempusdominus/js/moment-timezone.min.js"></script>
    <script src="../../lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>

    <!-- Template Javascript -->
    <script src="../../js/main.js?v=2.0"></script>
    <script src="../../js/tv-modern.js?v=<?= @filemtime(__DIR__ . '/../js/tv-modern.js') ?>"></script>

    <script>
        // Hide spinner when page is loaded
        window.addEventListener('load', function() {
            const spinner = document.getElementById('spinner');
            if (spinner) {
                spinner.style.display = 'none';
            }
        });

        // Special Request Modal Functions
        function openSpecialRequest() {
            document.getElementById('specialRequestModal').style.display = 'flex';
        }

        function closeSpecialRequest() {
            document.getElementById('specialRequestModal').style.display = 'none';
        }

        function saveSpecialRequest() {
            const specialRequests = [];

            // Collect checkbox values
            const checkboxes = document.querySelectorAll('.request-options input[type="checkbox"]:checked');
            checkboxes.forEach(checkbox => {
                specialRequests.push(checkbox.value);
            });

            // Add custom notes
            const customNotes = document.querySelector('textarea[name="special_notes"]').value;
            if (customNotes) {
                specialRequests.push(customNotes);
            }

            // Save to hidden field
            document.getElementById('specialRequests').value = specialRequests.join(', ');

            // Show confirmation
            alert('<?= t('Permintaan khusus telah disimpan!') ?>');
            closeSpecialRequest();
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('specialRequestModal');
            if (event.target === modal) {
                closeSpecialRequest();
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            // Check if it's the booking submission, not promo code submission
            if (e.submitter && e.submitter.name === 'submit_booking') {
                const customerName = document.querySelector('input[name="customer_name"]').value.trim();
                const phone = document.querySelector('input[name="no_hp"]').value.trim();
                const email = document.querySelector('input[name="email"]').value.trim();

                if (!customerName || !phone || !email) {
                    e.preventDefault();
                    alert('<?= t('Harap lengkapi semua data pemesan yang wajib diisi.') ?>');
                    return false;
                }

                if (!validateEmail(email)) {
                    e.preventDefault();
                    alert('<?= t('Format email tidak valid.') ?>');
                    return false;
                }
            }
            return true;
        });

        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        // Auto-focus on promo code input if there's an error
        <?php if ($promo_error && !$discount_applied): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const promoInput = document.querySelector('input[name="promo_code"]');
                if (promoInput) {
                    promoInput.focus();
                }
            });
        <?php endif; ?>

        // Timer countdown for new user discount
        <?php if ($is_new_user_eligible && $new_user_discount): ?>
            document.addEventListener('DOMContentLoaded', function() {
                function updateTimer() {
                    const timerElements = document.querySelectorAll('.discount-timer');
                    timerElements.forEach(timer => {
                        let days = <?= $days_left_new_user ?>;
                        let hours = 23 - new Date().getHours();
                        let minutes = 59 - new Date().getMinutes();
                        let seconds = 59 - new Date().getSeconds();

                        if (days > 0) {
                            timer.innerHTML = `<i class="fas fa-clock me-1"></i>${days} hari ${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                        } else {
                            timer.innerHTML = `<i class="fas fa-clock me-1"></i>${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                        }
                    });
                }

                // Update timer every second
                updateTimer();
                setInterval(updateTimer, 1000);
            });
        <?php endif; ?>
    </script>

</body>

</html>
<?php $conn->close(); ?>