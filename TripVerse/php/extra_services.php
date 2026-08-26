<?php
session_start();
require_once __DIR__ . '/_lang.php';
if (!isset($_SESSION['id_user']) || !isset($_SESSION['temp_booking'])) {
    header("Location: login.php");
    exit;
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create connection with error handling
require_once __DIR__ . '/db_config.php';

// Get booking data from session
$booking_data = $_SESSION['temp_booking'];
$hotel_id = $booking_data['hotel_id'];
$tipe_id = $booking_data['tipe_id'];

// ========== PERBAIKAN: AMBIL CUSTOMER_ID DARI DATABASE ==========
$customer_id = null;
$customer_name = null;

// Ambil customer_id dari tabel customer berdasarkan id_user
$sql_customer = "SELECT customer_id, nama FROM customer WHERE id_user = ?";
$stmt_customer = $conn->prepare($sql_customer);
$stmt_customer->bind_param("s", $_SESSION['id_user']);
$stmt_customer->execute();
$result_customer = $stmt_customer->get_result();

if ($result_customer->num_rows > 0) {
    $customer_row = $result_customer->fetch_assoc();
    $customer_id = $customer_row['customer_id'];
    $customer_name = $customer_row['nama'];
} else {
    // Jika tidak ditemukan, buat customer baru
    $customer_id = 'CUST' . date('Ymd') . strtoupper(substr(uniqid(), -6));
    $customer_name = $_SESSION['username'] ?? 'Guest';

    // Insert ke tabel customer
    $sql_insert_customer = "INSERT INTO customer (customer_id, id_user, email, nama, created_at) 
                           VALUES (?, ?, ?, ?, NOW())";
    $stmt_insert = $conn->prepare($sql_insert_customer);
    $email = $_SESSION['email'] ?? $_SESSION['id_user'] . '@temp.com';
    $stmt_insert->bind_param("ssss", $customer_id, $_SESSION['id_user'], $email, $customer_name);
    $stmt_insert->execute();
    $stmt_insert->close();
}
$stmt_customer->close();

// Update booking_data dengan customer_id yang valid
$booking_data['customer_id'] = $customer_id;
$booking_data['customer_name'] = $customer_name;

// Get hotel details
$hotel = $booking_data['hotel_data'];
$room = $booking_data['room_data'] ?? ['tipe_kamar' => 'Standard'];

$base_harga_kamar = $booking_data['total_harga'];

// ========== PERBAIKAN: PERIKSA STRUKTUR DATA DISKON ==========
$final_harga_kamar = $base_harga_kamar; // Default harga normal
$nilai_diskon = 0;
$selected_discount_id = null;

// Cek berbagai kemungkinan struktur data diskon
if (isset($booking_data['discount_applied']) && $booking_data['discount_applied']) {
    // Struktur 1: discount_applied adalah array
    if (isset($booking_data['discount_applied']['total_setelah_diskon'])) {
        $final_harga_kamar = $booking_data['discount_applied']['total_setelah_diskon'];
        $nilai_diskon = $booking_data['discount_applied']['nilai_diskon'] ?? 0;
        $selected_discount_id = $booking_data['discount_applied']['diskon_id'] ?? null;
    }
    // Struktur 2: data diskon langsung di array utama
    elseif (isset($booking_data['total_setelah_diskon'])) {
        $final_harga_kamar = $booking_data['total_setelah_diskon'];
        $nilai_diskon = $booking_data['nilai_diskon'] ?? 0;
        $selected_discount_id = $booking_data['diskon_id'] ?? null;
    }
} elseif (isset($booking_data['total_setelah_diskon'])) {
    // Struktur 3: data diskon langsung tanpa nested array
    $final_harga_kamar = $booking_data['total_setelah_diskon'];
    $nilai_diskon = $booking_data['nilai_diskon'] ?? 0;
    $selected_discount_id = $booking_data['diskon_id'] ?? null;
}

// Jika nilai_diskon 0, gunakan harga normal
if ($nilai_diskon == 0) {
    $final_harga_kamar = $base_harga_kamar;
}

// ========== FUNGSI: Update Discount Quota ==========
function updateDiscountQuotaInBooking($conn, $diskon_id, $booking_id, $user_id, $nilai_diskon)
{
    try {
        // 1. Cek apakah diskon masih valid dengan LOCK
        $sql_check = "SELECT * FROM diskon_promo 
                     WHERE diskon_id = ? 
                     AND status = 'active'
                     AND tanggal_mulai <= CURDATE()
                     AND tanggal_berakhir >= CURDATE()
                     AND (kuota IS NULL OR terpakai < kuota)
                     FOR UPDATE";

        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("s", $diskon_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows == 0) {
            throw new Exception(t("Diskon tidak valid atau kuota habis"));
        }

        $discount_info = $result_check->fetch_assoc();
        $stmt_check->close();

        // 2. Cek apakah sudah ada penggunaan untuk booking ini
        $sql_check_usage = "SELECT id_penggunaan FROM penggunaan_diskon 
                           WHERE booking_id = ? AND diskon_id = ?";
        $stmt_usage = $conn->prepare($sql_check_usage);
        $stmt_usage->bind_param("ss", $booking_id, $diskon_id);
        $stmt_usage->execute();

        if ($stmt_usage->get_result()->num_rows > 0) {
            error_log("Discount already recorded for booking: " . $booking_id);
            return true;
        }
        $stmt_usage->close();

        // 3. Update kuota diskon
        $sql_update = "UPDATE diskon_promo 
                      SET terpakai = terpakai + 1 
                      WHERE diskon_id = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("s", $diskon_id);
        $update_result = $stmt_update->execute();

        if (!$update_result || $stmt_update->affected_rows == 0) {
            throw new Exception(t("Gagal update kuota diskon"));
        }
        $stmt_update->close();

        // 4. Catat penggunaan diskon
        $id_penggunaan = 'PDISC' . time() . rand(100, 999);
        $sql_record = "INSERT INTO penggunaan_diskon 
                      (id_penggunaan, diskon_id, booking_id, id_user, nilai_diskon, tanggal_digunakan) 
                      VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt_record = $conn->prepare($sql_record);
        $stmt_record->bind_param(
            "ssssd",
            $id_penggunaan,
            $diskon_id,
            $booking_id,
            $user_id,
            $nilai_diskon
        );

        if (!$stmt_record->execute()) {
            // Rollback jika gagal catat
            $sql_rollback = "UPDATE diskon_promo SET terpakai = terpakai - 1 WHERE diskon_id = ?";
            $stmt_rollback = $conn->prepare($sql_rollback);
            $stmt_rollback->bind_param("s", $diskon_id);
            $stmt_rollback->execute();
            $stmt_rollback->close();

            throw new Exception(t("Gagal mencatat penggunaan diskon"));
        }
        $stmt_record->close();

        error_log("Discount quota updated successfully for booking: " . $booking_id);
        return true;
    } catch (Exception $e) {
        error_log("Error in updateDiscountQuotaInBooking: " . $e->getMessage());
        throw $e;
    }
}

// Get available extra facilities
$facilities = [];
$sql_facilities = "SELECT * FROM fasilitas_ekstra WHERE status = 'Available'";
$result_facilities = $conn->query($sql_facilities);
if ($result_facilities) {
    while ($row = $result_facilities->fetch_assoc()) {
        $facilities[] = $row;
    }
}

// Initialize selected facilities in session if not exists
if (!isset($_SESSION['selected_facilities'])) {
    $_SESSION['selected_facilities'] = [];
}

// Initialize error variable
$error = null;

// Handle facility selection
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_facility'])) {
        $facility_id = $_POST['facility_id'];
        $quantity = intval($_POST['quantity'] ?? 1);

        // Get facility details
        $sql_facility = "SELECT * FROM fasilitas_ekstra WHERE fasilitas_id = ?";
        $stmt = $conn->prepare($sql_facility);
        $stmt->bind_param("s", $facility_id);
        $stmt->execute();
        $facility = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($facility) {
            $subtotal = $facility['harga'] * $quantity;

            // Add to selected facilities
            $_SESSION['selected_facilities'][$facility_id] = [
                'fasilitas_id' => $facility_id,
                'nama_fasilitas' => $facility['nama_fasilitas'],
                'harga_satuan' => $facility['harga'],
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'kategori' => $facility['kategori']
            ];
        }
    }

    if (isset($_POST['remove_facility'])) {
        $facility_id = $_POST['facility_id'];
        if (isset($_SESSION['selected_facilities'][$facility_id])) {
            unset($_SESSION['selected_facilities'][$facility_id]);
        }
    }

    if (isset($_POST['update_quantity'])) {
        $facility_id = $_POST['facility_id'];
        $quantity = intval($_POST['quantity']);

        if (isset($_SESSION['selected_facilities'][$facility_id]) && $quantity > 0) {
            $_SESSION['selected_facilities'][$facility_id]['quantity'] = $quantity;
            $_SESSION['selected_facilities'][$facility_id]['subtotal'] =
                $_SESSION['selected_facilities'][$facility_id]['harga_satuan'] * $quantity;
        }
    }

    // ========== PERBAIKAN BAGIAN continue_to_payment ==========
    if (isset($_POST['continue_to_payment'])) {
        try {
            // Start transaction
            $conn->begin_transaction();

            // Calculate facilities total
            $total_fasilitas = 0;
            foreach ($_SESSION['selected_facilities'] as $facility) {
                $total_fasilitas += $facility['subtotal'];
            }

            // Generate booking ID
            $booking_id = 'BOOK' . date('YmdHis') . strtoupper(substr(uniqid(), -5));

            // Total = harga setelah diskon + fasilitas
            $total_keseluruhan = $final_harga_kamar + $total_fasilitas;

            // Generate jadwal_id
            $jadwal_id = $hotel_id . '-' . $tipe_id;

            // ========== UPDATE KUOTA DISKON SEBELUM INSERT BOOKING ==========
            if ($selected_discount_id && $nilai_diskon > 0) {
                try {
                    updateDiscountQuotaInBooking(
                        $conn,
                        $selected_discount_id,
                        $booking_id,
                        $customer_id,
                        $nilai_diskon
                    );
                } catch (Exception $e) {
                    // Jika diskon gagal, tetap lanjut booking tanpa diskon
                    $selected_discount_id = null;
                    $nilai_diskon = 0;
                    $total_keseluruhan = $base_harga_kamar + $total_fasilitas;
                    error_log("Discount failed, continuing without discount: " . $e->getMessage());
                }
            }

            // ========== PERBAIKAN: INSERT BOOKING DENGAN PARAMETER YANG TEPAT ==========
            $sql_booking = "INSERT INTO booking_hotel (
            booking_id, customer_id, customer_name, jadwal_id, hotel_id, tipe_id,
            check_in, check_out, jumlah_kamar, total_harga, total_fasilitas_ekstra,
            diskon_id, nilai_diskon, status, metode_pembayaran, tanggal_booking
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'QRIS', NOW())";

            $stmt_booking = $conn->prepare($sql_booking);

            // Debug: Hitung jumlah parameter
            error_log("SQL Booking Parameters: 13 parameters expected");

            // Pastikan semua nilai tidak null
            $customer_id_for_booking = $customer_id ?: 'CUST' . time() . rand(100, 999);
            $customer_name_for_booking = $customer_name ?: 'Guest';

            // PERBAIKAN: bind_param dengan 13 parameter (14 field - 3 hardcoded + NOW() = 13 parameters)
            // s = string (11x), i = integer (1x), d = decimal (2x) = total 14 type characters untuk 13 parameters??
            // Ternyata ada 13 parameter yang perlu di-bind

            $params = [
                $booking_id,                    // s
                $customer_id_for_booking,       // s
                $customer_name_for_booking,     // s
                $jadwal_id,                     // s
                $hotel_id,                      // s
                $tipe_id,                       // s
                $booking_data['check_in'],      // s
                $booking_data['check_out'],     // s
                $booking_data['jumlah_kamar'],  // i
                $total_keseluruhan,             // d
                $total_fasilitas,               // d
                $selected_discount_id,          // s (bisa null)
                $nilai_diskon                   // d
            ];

            // Type string: ssssssssiddsd = 13 karakter untuk 13 parameter
            $types = "ssssssssiddsd";

            // Debug log
            error_log("Types string length: " . strlen($types));
            error_log("Params count: " . count($params));
            error_log("Types: $types");

            $stmt_booking->bind_param($types, ...$params);

            if (!$stmt_booking->execute()) {
                throw new Exception(t("Gagal menyimpan booking: ") . $stmt_booking->error);
            }
            $stmt_booking->close();
            error_log("Booking saved successfully: $booking_id");

            // Insert selected facilities if any
            if (!empty($_SESSION['selected_facilities'])) {
                $sql_facility_insert = "INSERT INTO booking_fasilitas_ekstra (
                    booking_id, fasilitas_id, quantity, harga_satuan, subtotal, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())";

                $stmt_facility = $conn->prepare($sql_facility_insert);

                foreach ($_SESSION['selected_facilities'] as $facility) {
                    $stmt_facility->bind_param(
                        "ssidd",
                        $booking_id,
                        $facility['fasilitas_id'],
                        $facility['quantity'],
                        $facility['harga_satuan'],
                        $facility['subtotal']
                    );

                    if (!$stmt_facility->execute()) {
                        throw new Exception(t("Gagal menyimpan fasilitas: ") . $stmt_facility->error);
                    }
                }
                $stmt_facility->close();
            }

            // Update room availability in jadwal_hotel
            $sql_update_jadwal = "UPDATE jadwal_hotel 
                                SET terbooking = terbooking + ? 
                                WHERE hotel_id = ? AND tipe_id = ?";
            $stmt_jadwal = $conn->prepare($sql_update_jadwal);
            $stmt_jadwal->bind_param("iss", $booking_data['jumlah_kamar'], $hotel_id, $tipe_id);

            if (!$stmt_jadwal->execute()) {
                throw new Exception(t("Gagal update ketersediaan kamar: ") . $stmt_jadwal->error);
            }
            $stmt_jadwal->close();

            // Insert into transaksi table
            $id_transaksi = 'TRX' . date('YmdHis') . strtoupper(substr(uniqid(), -5));
            $sql_transaksi = "INSERT INTO transaksi (
                id_transaksi, jenis_transaksi, tanggal_transaksi, total_harga, 
                total_fasilitas_ekstra, status_transaksi
            ) VALUES (?, 'Hotel', NOW(), ?, ?, 'Pending')";

            $stmt_transaksi = $conn->prepare($sql_transaksi);
            $stmt_transaksi->bind_param(
                "sdd",
                $id_transaksi,
                $total_keseluruhan,
                $total_fasilitas
            );

            if (!$stmt_transaksi->execute()) {
                throw new Exception(t("Gagal menyimpan transaksi: ") . $stmt_transaksi->error);
            }
            $stmt_transaksi->close();

            // Insert into transaksi_hotel table
            $transaksi_id_hotel = 'HTRX' . date('YmdHis') . strtoupper(substr(uniqid(), -5));
            $sql_transaksi_hotel = "INSERT INTO transaksi_hotel (
                transaksi_id_hotel, id_transaksi, booking_id, status
            ) VALUES (?, ?, ?, 'Pending')";

            $stmt_transaksi_hotel = $conn->prepare($sql_transaksi_hotel);
            $stmt_transaksi_hotel->bind_param(
                "sss",
                $transaksi_id_hotel,
                $id_transaksi,
                $booking_id
            );

            if (!$stmt_transaksi_hotel->execute()) {
                throw new Exception(t("Gagal menyimpan transaksi hotel: ") . $stmt_transaksi_hotel->error);
            }
            $stmt_transaksi_hotel->close();

            // Log activity
            $log_sql = "INSERT INTO activity_log 
                       (user_id, action_type, action_description, entity_type, entity_id, entity_name, hotel_id, created_at) 
                       VALUES (?, 'create_booking', ?, 'booking', ?, ?, ?, NOW())";
            $stmt_log = $conn->prepare($log_sql);
            $log_desc = "Membuat booking baru: {$booking_id}" . ($selected_discount_id ? " dengan diskon {$selected_discount_id}" : "");
            $stmt_log->bind_param("sssss", $_SESSION['id_user'], $log_desc, $booking_id, $booking_id, $hotel_id);
            $stmt_log->execute();
            $stmt_log->close();

            // Commit transaction
            $conn->commit();

            // Update user status if new user
            if (isset($_SESSION['is_new_user']) && $_SESSION['is_new_user']) {
                $sql_update_user = "UPDATE user SET is_new_user = 0 WHERE id_user = ?";
                $stmt_update_user = $conn->prepare($sql_update_user);
                $stmt_update_user->bind_param("s", $_SESSION['id_user']);
                $stmt_update_user->execute();
                $stmt_update_user->close();
                $_SESSION['is_new_user'] = 0;
            }

            // Update session data with final booking information
            $_SESSION['temp_booking']['booking_id'] = $booking_id;
            $_SESSION['temp_booking']['total_fasilitas_ekstra'] = $total_fasilitas;
            $_SESSION['temp_booking']['selected_facilities'] = $_SESSION['selected_facilities'];
            $_SESSION['temp_booking']['total_harga_keseluruhan'] = $total_keseluruhan;
            $_SESSION['temp_booking']['customer_id'] = $customer_id_for_booking;
            $_SESSION['temp_booking']['customer_name'] = $customer_name_for_booking;

            // Clear selected facilities
            $_SESSION['selected_facilities'] = [];

            // Clear discount session data
            unset($_SESSION['applied_discount']);
            unset($_SESSION['discount_for_booking']);
            unset($_SESSION['current_booking_key']);

            // Redirect to payment page with booking ID
            header("Location: payment.php?booking_id=" . urlencode($booking_id));
            exit();
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = t("Terjadi kesalahan: ") . $e->getMessage();
            error_log("Payment Process Error: " . $e->getMessage());
        }
    }

    // ========== PERBAIKAN BAGIAN skip_services ==========
    if (isset($_POST['skip_services'])) {
        try {
            // Start transaction
            $conn->begin_transaction();

            // Generate booking ID
            $booking_id = 'BOOK' . date('YmdHis') . strtoupper(substr(uniqid(), -5));

            // Generate jadwal_id
            $jadwal_id = $hotel_id . '-' . $tipe_id;

            // ========== UPDATE KUOTA DISKON ==========
            if ($selected_discount_id && $nilai_diskon > 0) {
                try {
                    updateDiscountQuotaInBooking(
                        $conn,
                        $selected_discount_id,
                        $booking_id,
                        $customer_id,
                        $nilai_diskon
                    );
                } catch (Exception $e) {
                    // Jika diskon gagal, tetap lanjut booking tanpa diskon
                    $selected_discount_id = null;
                    $nilai_diskon = 0;
                    error_log("Discount failed, continuing without discount: " . $e->getMessage());
                }
            }

            // ========== INSERT BOOKING TANPA FACILITIES ==========
            $sql_booking = "INSERT INTO booking_hotel (
            booking_id, customer_id, customer_name, jadwal_id, hotel_id, tipe_id,
            check_in, check_out, jumlah_kamar, total_harga, total_fasilitas_ekstra,
            diskon_id, nilai_diskon, status, metode_pembayaran, tanggal_booking
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'Pending', 'QRIS', NOW())";

            $stmt_booking = $conn->prepare($sql_booking);

            // Pastikan semua nilai tidak null
            $customer_id_for_booking = $customer_id ?: 'CUST' . time() . rand(100, 999);
            $customer_name_for_booking = $customer_name ?: 'Guest';

            // PERBAIKAN: bind_param dengan 12 parameter (karena total_fasilitas_ekstra sudah di-hardcode 0)
            // s = string (11x), i = integer (1x), d = decimal (1x) = total 13 type characters untuk 12 parameters?
            // Ternyata ada 12 parameter yang perlu di-bind

            $params = [
                $booking_id,                    // s
                $customer_id_for_booking,       // s
                $customer_name_for_booking,     // s
                $jadwal_id,                     // s
                $hotel_id,                      // s
                $tipe_id,                       // s
                $booking_data['check_in'],      // s
                $booking_data['check_out'],     // s
                $booking_data['jumlah_kamar'],  // i
                $final_harga_kamar,             // d
                $selected_discount_id,          // s (bisa null)
                $nilai_diskon                   // d
            ];

            // Type string: ssssssssiddsd = 13 karakter tapi untuk 12 parameter?
            // Sebenarnya: ssssssssiddsd = 13 karakter untuk 12 parameter? SALAH!
            // Harusnya: ssssssssiddd? Tidak, mari kita hitung:
            // 1. booking_id (s)
            // 2. customer_id (s)
            // 3. customer_name (s)
            // 4. jadwal_id (s)
            // 5. hotel_id (s)
            // 6. tipe_id (s)
            // 7. check_in (s)
            // 8. check_out (s)
            // 9. jumlah_kamar (i)
            // 10. total_harga (d)
            // 11. diskon_id (s)
            // 12. nilai_diskon (d)
            // Total: 10 s, 1 i, 2 d = 13 karakter type string untuk 12 parameters

            $types = "sssssssi dd s d"; // Tapi ini tidak benar dalam format

            // Sebenarnya yang benar: "sssssssiddsd" adalah 13 karakter untuk 12 parameter?
            // Mari kita coba hitung ulang:
            // String: s s s s s s s s i d d s d = 13 karakter untuk 12 parameter? ADA KESALAHAN!
            // Parameter ke-10 adalah total_harga (d)
            // Parameter ke-11 adalah diskon_id (s)
            // Parameter ke-12 adalah nilai_diskon (d)
            // Jadi: ssssssssiddsd = 13 karakter

            // Tapi tunggu, kita punya 12 parameter, bukan 13!
            // 1-8: s (8x)
            // 9: i (1x)
            // 10: d (1x)
            // 11: s (1x)
            // 12: d (1x)
            // Total: 8 + 1 + 1 + 1 + 1 = 12 karakter type string

            $types = "sssssssi d s d"; // Tapi ini bukan format yang benar

            // Format yang benar: "sssssssiddsd" = 12 karakter? Mari hitung:
            // s(1) s(2) s(3) s(4) s(5) s(6) s(7) s(8) i(9) d(10) d(11) s(12) d(13) = 13 karakter!
            // ADA MASALAH! Kita punya 12 parameter tapi type string 13 karakter

            // Solusi: Hitung dengan benar:
            // Untuk skip_services: total 12 parameter
            // Types: s s s s s s s s i d s d = 12 karakter
            $types = "sssssssidsd"; // 11 karakter? Masih salah!

            // Mari kita debug:
            error_log("Skip Services - Parameter count: " . count($params));

            // Cara yang lebih aman: gunakan array untuk types
            $type_chars = '';
            foreach ($params as $param) {
                if (is_int($param)) {
                    $type_chars .= 'i';
                } elseif (is_float($param) || is_double($param)) {
                    $type_chars .= 'd';
                } else {
                    $type_chars .= 's';
                }
            }

            error_log("Generated types: $type_chars");
            error_log("Params: " . print_r($params, true));

            $stmt_booking->bind_param($type_chars, ...$params);

            if (!$stmt_booking->execute()) {
                throw new Exception(t("Gagal menyimpan booking: ") . $stmt_booking->error);
            }
            $stmt_booking->close();
            error_log("Booking saved successfully (skip services): $booking_id");

            // Update room availability
            $sql_update_jadwal = "UPDATE jadwal_hotel 
                                SET terbooking = terbooking + ? 
                                WHERE hotel_id = ? AND tipe_id = ?";
            $stmt_jadwal = $conn->prepare($sql_update_jadwal);
            $stmt_jadwal->bind_param("iss", $booking_data['jumlah_kamar'], $hotel_id, $tipe_id);

            if (!$stmt_jadwal->execute()) {
                throw new Exception(t("Gagal update ketersediaan kamar: ") . $stmt_jadwal->error);
            }
            $stmt_jadwal->close();

            // Insert into transaksi table
            $id_transaksi = 'TRX' . date('YmdHis') . strtoupper(substr(uniqid(), -5));
            $sql_transaksi = "INSERT INTO transaksi (
                id_transaksi, jenis_transaksi, tanggal_transaksi, total_harga, 
                total_fasilitas_ekstra, status_transaksi
            ) VALUES (?, 'Hotel', NOW(), ?, 0, 'Pending')";

            $stmt_transaksi = $conn->prepare($sql_transaksi);
            $stmt_transaksi->bind_param("sd", $id_transaksi, $final_harga_kamar);

            if (!$stmt_transaksi->execute()) {
                throw new Exception(t("Gagal menyimpan transaksi: ") . $stmt_transaksi->error);
            }
            $stmt_transaksi->close();

            // Insert into transaksi_hotel table
            $transaksi_id_hotel = 'HTRX' . date('YmdHis') . strtoupper(substr(uniqid(), -5));
            $sql_transaksi_hotel = "INSERT INTO transaksi_hotel (
                transaksi_id_hotel, id_transaksi, booking_id, status
            ) VALUES (?, ?, ?, 'Pending')";

            $stmt_transaksi_hotel = $conn->prepare($sql_transaksi_hotel);
            $stmt_transaksi_hotel->bind_param(
                "sss",
                $transaksi_id_hotel,
                $id_transaksi,
                $booking_id
            );

            if (!$stmt_transaksi_hotel->execute()) {
                throw new Exception(t("Gagal menyimpan transaksi hotel: ") . $stmt_transaksi_hotel->error);
            }
            $stmt_transaksi_hotel->close();

            // Log activity
            $log_sql = "INSERT INTO activity_log 
                       (user_id, action_type, action_description, entity_type, entity_id, entity_name, hotel_id, created_at) 
                       VALUES (?, 'create_booking', ?, 'booking', ?, ?, ?, NOW())";
            $stmt_log = $conn->prepare($log_sql);
            $log_desc = "Membuat booking baru: {$booking_id}" . ($selected_discount_id ? " dengan diskon {$selected_discount_id}" : "");
            $stmt_log->bind_param("sssss", $_SESSION['id_user'], $log_desc, $booking_id, $booking_id, $hotel_id);
            $stmt_log->execute();
            $stmt_log->close();

            // Commit transaction
            $conn->commit();

            // Update user status if new user
            if (isset($_SESSION['is_new_user']) && $_SESSION['is_new_user']) {
                $sql_update_user = "UPDATE user SET is_new_user = 0 WHERE id_user = ?";
                $stmt_update_user = $conn->prepare($sql_update_user);
                $stmt_update_user->bind_param("s", $_SESSION['id_user']);
                $stmt_update_user->execute();
                $stmt_update_user->close();
                $_SESSION['is_new_user'] = 0;
            }

            // Update session data
            $_SESSION['temp_booking']['booking_id'] = $booking_id;
            $_SESSION['temp_booking']['total_fasilitas_ekstra'] = 0;
            $_SESSION['temp_booking']['selected_facilities'] = [];
            $_SESSION['temp_booking']['customer_id'] = $customer_id_for_booking;
            $_SESSION['temp_booking']['customer_name'] = $customer_name_for_booking;

            // Clear selected facilities
            $_SESSION['selected_facilities'] = [];

            // Clear discount session data
            unset($_SESSION['applied_discount']);
            unset($_SESSION['discount_for_booking']);
            unset($_SESSION['current_booking_key']);

            // Redirect to payment page
            header("Location: payment.php?booking_id=" . urlencode($booking_id));
            exit();
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = t("Terjadi kesalahan: ") . $e->getMessage();
            error_log("Skip Services Error: " . $e->getMessage());
        }
    }
}

// Calculate totals for display
$total_fasilitas = 0;
if (isset($_SESSION['selected_facilities'])) {
    foreach ($_SESSION['selected_facilities'] as $facility) {
        $total_fasilitas += $facility['subtotal'];
    }
}

// Total = harga setelah diskon + fasilitas
$total_keseluruhan = $final_harga_kamar + $total_fasilitas;

// Function untuk handle path gambar
function getImagePath($imagePath, $defaultPath = '../img/default-hotel.jpg')
{
    if (empty($imagePath)) {
        return $defaultPath;
    }

    if (strpos($imagePath, 'http') === 0 || strpos($imagePath, 'https://') === 0) {
        return $imagePath;
    } else if (strpos($imagePath, '../') === 0 || strpos($imagePath, 'img/') === 0) {
        return $imagePath;
    } else {
        $possiblePaths = [
            '../img/' . $imagePath,
            'img/' . $imagePath,
            '../uploads/' . $imagePath,
            'uploads/' . $imagePath,
            '../' . $imagePath,
            $imagePath
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return $defaultPath;
    }
}

$hotel_image_path = getImagePath($hotel['foto_hotel'], '../img/default-hotel.jpg');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>TripVerse - Penawaran Layanan Tambahan</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicon -->
    <link href="../img/favicon.ico" rel="icon">

    <!-- Google Web Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Libraries Stylesheet -->
    <link href="../lib/animate/animate.min.css" rel="stylesheet">
    <link href="../lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">
    <link href="../lib/tempusdominus/css/tempusdominus-bootstrap-4.min.css" rel="stylesheet" />

    <!-- Customized Bootstrap Stylesheet -->
    <link href="../css/bootstrap.min.css" rel="stylesheet">

    <!-- Template Stylesheet -->
    <link href="../css/style.css?v=2.0" rel="stylesheet">
    <link href="../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>" rel="stylesheet">

    <style>
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

        .service-container {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            transition: var(--transition);
        }

        .service-container:hover {
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        }

        .service-header {
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
            position: relative;
        }

        .service-header::after {
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

        .facility-category {
            margin-bottom: 2rem;
        }

        .category-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-light);
        }

        .facility-card {
            border: 1px solid var(--gray-light);
            border-radius: 14px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: transform .35s cubic-bezier(.22, 1, .36, 1), box-shadow .35s ease, border-color .35s ease;
            background: white;
        }

        .facility-card:hover {
            border-color: var(--primary);
            box-shadow: 0 16px 32px rgba(254, 161, 22, 0.14);
            transform: translateY(-4px);
        }

        .facility-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .facility-name {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .facility-price {
            font-weight: 700;
            color: var(--primary);
            font-size: 1.125rem;
        }

        .facility-description {
            color: var(--gray);
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }

        .facility-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .quantity-btn {
            width: 32px;
            height: 32px;
            border: 1px solid var(--gray-light);
            background: white;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .quantity-btn:hover {
            background: var(--gray-light);
        }

        .quantity-input {
            width: 50px;
            text-align: center;
            border: 1px solid var(--gray-light);
            border-radius: 4px;
            padding: 0.25rem;
        }

        .btn {
            font-weight: 500;
            letter-spacing: 0.5px;
            transition: var(--transition);
            border-radius: 8px;
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

        .btn-outline-primary {
            border-color: var(--primary);
            color: var(--primary);
            padding: 0.75rem 1.75rem;
            border-radius: 999px;
            border-width: 2px;
            transition: transform .3s cubic-bezier(.22, 1, .36, 1), background-color .3s ease, color .3s ease;
        }

        .btn-outline-primary:hover {
            background-color: var(--primary);
            border-color: var(--primary);
            color: white;
            transform: translateY(-2px);
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

        .facility-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: var(--gray);
        }

        .facility-quantity {
            color: var(--dark);
            font-weight: 500;
        }

        .price-total {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--primary);
        }

        .selected-facilities {
            background: var(--light);
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .selected-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }

        .no-facilities {
            color: var(--gray);
            font-style: italic;
            text-align: center;
            padding: 1rem;
        }

        .remove-btn {
            background: none;
            border: none;
            color: #DC2626;
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 4px;
            transition: var(--transition);
        }

        .remove-btn:hover {
            background: #DC2626;
            color: white;
        }

        .discount-success {
            background-color: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.2);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .discount-success i {
            color: var(--success);
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

            .facility-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .facility-price {
                margin-top: 0.5rem;
            }

            .facility-actions {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
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

        .service-container,
        .summary-card {
            animation: fadeIn 0.5s ease-out forwards;
        }

        .footer {
            background-color: #0f172a;
            color: #e2e8f0;
            padding: 3rem 0 1.5rem;
            margin-top: 3rem;
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
                    <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 50px;">
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
                        <img src="../img/logo.png" alt="TripVerse Logo" class="me-2" style="height: 40px;">
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
                        <?php include __DIR__ . '/_lang_switch.php'; ?><?php include __DIR__ . '/_account_menu.php'; ?>
                    </div>
                </nav>
            </div>
        </div>
    </header>

    <!-- Page Header -->
    <div class="container-fluid page-header mb-5 p-0" style="background-image: url(../img/carousel-1.jpg);">
        <div class="container-fluid page-header-inner py-5">
            <div class="container text-center pb-5">
                <h1 class="display-3 text-white mb-3 animated slideInDown"><?= te('Layanan Tambahan') ?></h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb justify-content-center text-uppercase">
                        <li class="breadcrumb-item"><a href="home.php"><?= te('Beranda') ?></a></li>
                        <li class="breadcrumb-item"><a href="hotel.php"><?= te('Hotel') ?></a></li>
                        <li class="breadcrumb-item text-white active" aria-current="page"><?= te('Layanan Tambahan') ?></li>
                    </ol>
                </nav>
            </div>
        </div>
    </div>

    <!-- Service Offers Content -->
    <div class="container-fluid py-5">
        <div class="container">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Error!</strong> <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($selected_discount_id && $nilai_diskon > 0): ?>
                <div class="discount-success">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle me-2"></i>
                        <div>
                            <strong><?= te('Promo Akan Digunakan!') ?></strong>
                            <p class="mb-0"><?= te('Promo akan digunakan pada pesanan ini senilai') ?> Rp <?= number_format($nilai_diskon, 0, ',', '.') ?>.</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Main Content -->
                <div class="col-lg-8">
                    <div class="service-container">
                        <div class="service-header">
                            <h2 class="mb-3"><?= te('Pilih Layanan Tambahan') ?></h2>

                            <!-- Progress Steps -->
                            <div class="progress-steps">
                                <div class="step completed">
                                    <div class="step-number">1</div>
                                    <div class="step-title"><?= te('Detail Pesanan') ?></div>
                                </div>
                                <div class="step active">
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

                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <?= te('Pilih layanan tambahan yang Anda butuhkan. Semua layanan bersifat opsional.') ?>
                            </div>
                        </div>

                        <!-- Available Facilities by Category -->
                        <?php
                        $categories = ['Makanan', 'Transportasi', 'Lainnya'];
                        foreach ($categories as $category):
                            $category_facilities = array_filter($facilities, function ($facility) use ($category) {
                                return $facility['kategori'] === $category;
                            });

                            if (!empty($category_facilities)):
                        ?>
                                <div class="facility-category">
                                    <h4 class="category-title"><?= te($category) ?></h4>

                                    <?php foreach ($category_facilities as $facility): ?>
                                        <div class="facility-card">
                                            <div class="facility-header">
                                                <div>
                                                    <h5 class="facility-name"><?= htmlspecialchars($facility['nama_fasilitas']) ?></h5>
                                                    <p class="facility-description"><?= htmlspecialchars($facility['deskripsi']) ?></p>
                                                </div>
                                                <div class="facility-price">
                                                    Rp <?= number_format($facility['harga'], 0, ',', '.') ?>
                                                </div>
                                            </div>

                                            <div class="facility-actions">
                                                <div class="quantity-controls">
                                                    <button type="button" class="quantity-btn" onclick="decreaseQuantity('<?= $facility['fasilitas_id'] ?>')">-</button>
                                                    <input type="number" id="quantity_<?= $facility['fasilitas_id'] ?>" value="1" min="1" class="quantity-input">
                                                    <button type="button" class="quantity-btn" onclick="increaseQuantity('<?= $facility['fasilitas_id'] ?>')">+</button>
                                                </div>

                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="facility_id" value="<?= $facility['fasilitas_id'] ?>">
                                                    <input type="hidden" name="quantity" id="hidden_quantity_<?= $facility['fasilitas_id'] ?>" value="1">
                                                    <button type="submit" name="add_facility" class="btn btn-outline-primary btn-sm">
                                                        <i class="fas fa-plus me-1"></i> <?= te('Tambah') ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                        <?php
                            endif;
                        endforeach;
                        ?>

                        <!-- Action Buttons -->
                        <div class="d-flex justify-content-between mt-5">
                            <a href="booking.php?hotel_id=<?= $hotel_id ?>&tipe_id=<?= $tipe_id ?>&checkin=<?= $booking_data['check_in'] ?>&checkout=<?= $booking_data['check_out'] ?>&kamar=<?= $booking_data['jumlah_kamar'] ?>"
                                class="btn btn-outline-secondary px-4 py-2">
                                <i class="fas fa-arrow-left me-2"></i> <?= te('Kembali') ?>
                            </a>

                            <div>
                                <form method="POST" style="display: inline;">
                                    <button type="submit" name="skip_services" class="btn btn-outline-secondary px-4 py-2 me-2 js-confirm-submit"
                                        data-confirm-message="<?= te('Lanjutkan tanpa layanan tambahan?') ?>">
                                        <?= te('Lewati Layanan') ?>
                                    </button>
                                </form>

                                <form method="POST" style="display: inline;">
                                    <button type="submit" name="continue_to_payment" class="btn btn-primary px-4 py-2 js-confirm-submit"
                                        data-confirm-message="<?= te('Lanjutkan ke pembayaran?') ?>">
                                        <?= te('Lanjutkan ke Pembayaran') ?> <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="col-lg-4">
                    <div class="summary-card">
                        <h4 class="mb-4"><?= te('Ringkasan Pesanan') ?></h4>

                        <img src="<?= htmlspecialchars($hotel_image_path) ?>"
                            alt="<?= htmlspecialchars($hotel['nama_hotel']) ?>"
                            class="hotel-image"
                            onerror="this.onerror=null; this.src='../img/default-hotel.jpg';">

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
                                <span><?= $booking_data['durasi'] ?> <?= t('malam') ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted"><?= te('Jumlah Kamar:') ?></span>
                                <span><?= $booking_data['jumlah_kamar'] ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted"><?= te('Check-In:') ?></span>
                                <span><?= date('d M Y', strtotime($booking_data['check_in'])) ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted"><?= te('Check-Out:') ?></span>
                                <span><?= date('d M Y', strtotime($booking_data['check_out'])) ?></span>
                            </div>
                        </div>

                        <div class="price-detail">
                            <!-- Harga Kamar Sebelum Diskon -->
                            <div class="price-item">
                                <span><?= te('Harga Kamar') ?> (<?= $booking_data['durasi'] ?> <?= t('malam') ?>)</span>
                                <span>Rp <?= number_format($base_harga_kamar, 0, ',', '.') ?></span>
                            </div>

                            <!-- Diskon (jika ada) -->
                            <?php if ($nilai_diskon > 0): ?>
                                <div class="price-item text-success">
                                    <span>
                                        <i class="fas fa-tag me-1"></i>
                                        <?= te('Diskon') ?>
                                    </span>
                                    <span>- Rp <?= number_format($nilai_diskon, 0, ',', '.') ?></span>
                                </div>

                                <!-- Harga Kamar Setelah Diskon -->
                                <div class="price-item">
                                    <span><?= te('Harga Kamar Setelah Diskon') ?></span>
                                    <span>Rp <?= number_format($final_harga_kamar, 0, ',', '.') ?></span>
                                </div>
                            <?php endif; ?>

                            <!-- Selected Facilities -->
                            <div class="selected-facilities">
                                <h6 class="selected-title"><?= te('Layanan Tambahan') ?></h6>
                                <?php if (!empty($_SESSION['selected_facilities'])): ?>
                                    <?php foreach ($_SESSION['selected_facilities'] as $facility): ?>
                                        <div class="facility-item">
                                            <div>
                                                <span><?= htmlspecialchars($facility['nama_fasilitas']) ?></span>
                                                <span class="facility-quantity"> × <?= $facility['quantity'] ?></span>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="facility_id" value="<?= $facility['fasilitas_id'] ?>">
                                                    <button type="submit" name="remove_facility" class="remove-btn" title="<?= te('Hapus') ?>">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </form>
                                            </div>
                                            <span>Rp <?= number_format($facility['subtotal'], 0, ',', '.') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-facilities"><?= te('Belum ada layanan tambahan') ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- Total Fasilitas -->
                            <?php if ($total_fasilitas > 0): ?>
                                <div class="price-item mt-3">
                                    <span><?= te('Total Layanan Tambahan') ?></span>
                                    <span>Rp <?= number_format($total_fasilitas, 0, ',', '.') ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <h5><?= te('Total Pembayaran') ?></h5>
                            <div class="text-end">
                                <?php if ($nilai_diskon > 0): ?>
                                    <small class="text-success d-block">
                                        <i class="fas fa-piggy-bank me-1"></i>
                                        <?= te('Hemat') ?> Rp <?= number_format($nilai_diskon, 0, ',', '.') ?>
                                    </small>
                                <?php endif; ?>
                                <h4 class="price-total mb-0">Rp <?= number_format($total_keseluruhan, 0, ',', '.') ?></h4>
                            </div>
                        </div>

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
                            <img src="../img/logo.png" alt="TripVerse Logo" width="50" class="me-3">
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
                        <a class="btn btn-outline-light btn-social" href="#"><i class="fab fa-twitter"></i></a>
                        <a class="btn btn-outline-light btn-social" href="#"><i class="fab fa-facebook-f"></i></a>
                        <a class="btn btn-outline-light btn-social" href="#"><i class="fab fa-youtube"></i></a>
                        <a class="btn btn-outline-light btn-social" href="#"><i class="fab fa-linkedin-in"></i></a>
                        <a class="btn btn-outline-light btn-social" href="#"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
                <div class="col-lg-5 col-md-12">
                    <div class="row gy-5 g-4">
                        <div class="col-md-6">
                            <h6 class="section-title text-start text-primary text-uppercase mb-4">Company</h6>
                            <a class="btn btn-link" href="about.php">About Us</a>
                            <a class="btn btn-link" href="contact.php">Contact Us</a>
                            <a class="btn btn-link" href="#">Privacy Policy</a>
                            <a class="btn btn-link" href="#">Terms & Condition</a>
                            <a class="btn btn-link" href="#">Support</a>
                        </div>
                        <div class="col-md-6">
                            <h6 class="section-title text-start text-primary text-uppercase mb-4">Services</h6>
                            <a class="btn btn-link" href="hotel.php">Hotel</a>
                            <a class="btn btn-link" href="service.php">Fitur</a>
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
                        &copy; <a class="border-bottom" href="home.php">TripVerse</a>, All Right Reserved.
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

    <!-- Confirm dialog (replaces the native confirm(), which browsers can silently block) -->
    <div class="modal fade" id="tvConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body py-4 text-center" id="tvConfirmModalMessage"></div>
                <div class="modal-footer border-0 justify-content-center pb-4">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal"><?= te('Batal') ?></button>
                    <button type="button" class="btn btn-primary px-4" id="tvConfirmModalOk"><?= te('Ya, Lanjutkan') ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../lib/wow/wow.min.js"></script>
    <script src="../lib/easing/easing.min.js"></script>
    <script src="../lib/waypoints/waypoints.min.js"></script>
    <script src="../lib/counterup/counterup.min.js"></script>
    <script src="../lib/owlcarousel/owl.carousel.min.js"></script>
    <script src="../lib/tempusdominus/js/moment.min.js"></script>
    <script src="../lib/tempusdominus/js/moment-timezone.min.js"></script>
    <script src="../lib/tempusdominus/js/tempusdominus-bootstrap-4.min.js"></script>

    <!-- Template Javascript -->
    <script src="../js/main.js?v=2.0"></script>
    <script src="../js/tv-modern.js?v=<?= @filemtime(__DIR__ . '/../js/tv-modern.js') ?>"></script>

    <script>
        // Hide spinner when page is loaded
        window.addEventListener('load', function() {
            const spinner = document.getElementById('spinner');
            if (spinner) {
                spinner.style.display = 'none';
            }
        });

        // Quantity control functions
        function increaseQuantity(facilityId) {
            const input = document.getElementById('quantity_' + facilityId);
            const hiddenInput = document.getElementById('hidden_quantity_' + facilityId);
            input.value = parseInt(input.value) + 1;
            hiddenInput.value = input.value;
        }

        function decreaseQuantity(facilityId) {
            const input = document.getElementById('quantity_' + facilityId);
            const hiddenInput = document.getElementById('hidden_quantity_' + facilityId);
            if (parseInt(input.value) > 1) {
                input.value = parseInt(input.value) - 1;
                hiddenInput.value = input.value;
            }
        }

        // Update hidden input when quantity input changes
        document.addEventListener('DOMContentLoaded', function() {
            const quantityInputs = document.querySelectorAll('.quantity-input');
            quantityInputs.forEach(input => {
                const facilityId = input.id.replace('quantity_', '');
                const hiddenInput = document.getElementById('hidden_quantity_' + facilityId);

                input.addEventListener('change', function() {
                    if (parseInt(this.value) < 1) {
                        this.value = 1;
                    }
                    hiddenInput.value = this.value;
                });
            });
        });

        // Confirm-before-submit for the skip/continue buttons, using a Bootstrap
        // modal instead of window.confirm() (native confirm() gets silently
        // blocked by some browsers after repeated dialogs, which was making
        // these buttons appear completely unresponsive).
        document.addEventListener('DOMContentLoaded', function() {
            const modalEl = document.getElementById('tvConfirmModal');
            if (!modalEl) return;
            const modal = new bootstrap.Modal(modalEl);
            const messageEl = document.getElementById('tvConfirmModalMessage');
            const okBtn = document.getElementById('tvConfirmModalOk');
            let pendingButton = null;

            document.querySelectorAll('.js-confirm-submit').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    pendingButton = btn;
                    messageEl.textContent = btn.dataset.confirmMessage || '<?= t('Lanjutkan?') ?>';
                    modal.show();
                });
            });

            okBtn.addEventListener('click', function() {
                modal.hide();
                if (pendingButton) {
                    // requestSubmit(button) keeps the button's name/value in the
                    // POST data — plain form.submit() would drop it, since the
                    // browser only includes a submit button's field when it was
                    // the one that triggered submission.
                    pendingButton.form.requestSubmit(pendingButton);
                    pendingButton = null;
                }
            });
        });
    </script>

</body>

</html>
<?php $conn->close(); ?>