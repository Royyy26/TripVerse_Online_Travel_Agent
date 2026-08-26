<?php
session_start();
require_once __DIR__ . '/_lang.php';

// Redirect if user is not logged in
if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

// Include WhatsApp notification functions
require_once __DIR__ . '/fonnte_api.php';
require_once __DIR__ . '/notification_payment.php';

// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "tripverse";

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Initialize variables
$booking_id = null;
$error = null;
$success = null;
$durasi = 0;
$kamar = 0;
$total_harga = 0;
$hotel = [];
$room = [];
$checkinDisplay = '';
$checkoutDisplay = '';
$waktu_tersisa = 0;
$is_expired = false;
$diskon_id = null;
$nilai_diskon = 0;
$base_harga_kamar = 0;
$selected_facilities = [];
$discount_details = [];

// Function to cancel expired bookings with discount refund
function cancelExpiredBooking($conn, $booking_id)
{
    $conn->begin_transaction();
    try {
        // 1. Get booking details including discount
        $sql_booking = "SELECT hotel_id, tipe_id, jumlah_kamar, diskon_id 
                       FROM booking_hotel 
                       WHERE booking_id = ? AND status = 'Pending'";
        $stmt = $conn->prepare($sql_booking);
        $stmt->bind_param("s", $booking_id);
        $stmt->execute();
        $booking_details = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$booking_details) {
            return false;
        }

        // 2. REFUND DISCOUNT QUOTA if exists
        if (!empty($booking_details['diskon_id'])) {
            // Check if this booking actually used the discount
            $sql_check_usage = "SELECT id_penggunaan FROM penggunaan_diskon 
                              WHERE booking_id = ? AND diskon_id = ? 
                              LIMIT 1 FOR UPDATE";
            $stmt_check = $conn->prepare($sql_check_usage);
            $stmt_check->bind_param("ss", $booking_id, $booking_details['diskon_id']);
            $stmt_check->execute();
            $usage_exists = $stmt_check->get_result()->num_rows > 0;
            $stmt_check->close();

            if ($usage_exists) {
                // REFUND: Decrement terpakai (pastikan tidak kurang dari 0)
                $sql_refund = "UPDATE diskon_promo 
                SET terpakai = GREATEST(terpakai - 1, 0), 
                            kuota = kuota + 1
                        WHERE diskon_id = ?";
                $stmt_refund = $conn->prepare($sql_refund);
                $stmt_refund->bind_param("s", $booking_details['diskon_id']);
                $stmt_refund->execute();

                // Delete usage record
                $sql_delete_usage = "DELETE FROM penggunaan_diskon 
                                   WHERE booking_id = ? AND diskon_id = ?";
                $stmt_delete = $conn->prepare($sql_delete_usage);
                $stmt_delete->bind_param("ss", $booking_id, $booking_details['diskon_id']);
                $stmt_delete->execute();
                $stmt_delete->close();
            }
        }

        // 3. Refund room availability
        $sql_update_room = "UPDATE jadwal_hotel 
                          SET terbooking = GREATEST(terbooking - ?, 0)
                          WHERE hotel_id = ? AND tipe_id = ?";
        $stmt_room = $conn->prepare($sql_update_room);
        $stmt_room->bind_param(
            "iss",
            $booking_details['jumlah_kamar'],
            $booking_details['hotel_id'],
            $booking_details['tipe_id']
        );
        $stmt_room->execute();
        $stmt_room->close();

        // 4. Update booking status
        $sql_cancel = "UPDATE booking_hotel SET status = 'Cancelled' 
                      WHERE booking_id = ?";
        $stmt_cancel = $conn->prepare($sql_cancel);
        $stmt_cancel->bind_param("s", $booking_id);
        $stmt_cancel->execute();
        $stmt_cancel->close();

        // 5. Update transaction status
        $sql_trans = "UPDATE transaksi t
                     JOIN transaksi_hotel th ON t.id_transaksi = th.id_transaksi
                     SET t.status_transaksi = 'Cancelled'
                     WHERE th.booking_id = ?";
        $stmt_trans = $conn->prepare($sql_trans);
        $stmt_trans->bind_param("s", $booking_id);
        $stmt_trans->execute();
        $stmt_trans->close();

        // 6. Log activity
        $log_sql = "INSERT INTO activity_log 
                   (user_id, action_type, action_description, entity_type, entity_id, entity_name, hotel_id) 
                   VALUES (?, 'auto_cancel_booking', ?, 'booking', ?, ?, ?)";
        $stmt_log = $conn->prepare($log_sql);
        $user_id = 'SYSTEM';
        $log_desc = "Auto-cancelled expired booking: {$booking_id}";
        $entity_id = $booking_id;
        $entity_name = $booking_id;
        $hotel_id = $booking_details['hotel_id'];
        $stmt_log->bind_param("sssss", $user_id, $log_desc, $entity_id, $entity_name, $hotel_id);
        $stmt_log->execute();
        $stmt_log->close();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Auto-cancel booking error: " . $e->getMessage());
        return false;
    }
}

// Process POST requests (payment confirmation or cancellation)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $conn = new mysqli($host, $username, $password, $database);
        if ($conn->connect_error) {
            throw new Exception("Database connection failed: " . $conn->connect_error);
        }

        // Handle booking cancellation
        if (isset($_POST['cancel_booking'])) {
            $booking_id = $_POST['booking_id'];
            $user_id = $_SESSION['id_user'];

            // Start transaction
            $conn->begin_transaction();

            try {
                // 1. Get booking details including discount info WITH LOCK
                $sql = "SELECT bh.*, jh.stok_total, jh.terbooking
                        FROM booking_hotel bh
                        JOIN jadwal_hotel jh ON bh.hotel_id = jh.hotel_id AND bh.tipe_id = jh.tipe_id
                        JOIN customer c ON bh.customer_id = c.customer_id
                        WHERE bh.booking_id = ? 
                        AND c.id_user = ?
                        AND bh.status = 'Pending'
                        FOR UPDATE";

                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ss", $booking_id, $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $booking_details = $result->fetch_assoc();
                $stmt->close();

                if (!$booking_details) {
                    throw new Exception("Booking not found or already processed");
                }

                // ========== REFUND DISKON QUOTA ==========
                if (!empty($booking_details['diskon_id'])) {
                    // Check if this booking actually used the discount
                    $sql_check_usage = "SELECT id_penggunaan, nilai_diskon 
                                      FROM penggunaan_diskon 
                                      WHERE booking_id = ? AND diskon_id = ? 
                                      LIMIT 1 FOR UPDATE";

                    $stmt_usage = $conn->prepare($sql_check_usage);
                    $stmt_usage->bind_param("ss", $booking_id, $booking_details['diskon_id']);
                    $stmt_usage->execute();
                    $usage_result = $stmt_usage->get_result();

                    if ($usage_result->num_rows > 0) {
                        $usage_data = $usage_result->fetch_assoc();

                        // REFUND: Decrement terpakai (menggunakan GREATEST untuk mencegah nilai negatif)
                        $sql_refund_discount = "UPDATE diskon_promo 
                                                SET terpakai = GREATEST(terpakai - 1, 0), 
                                                kuota = kuota + 1
                                               WHERE diskon_id = ?";

                        $stmt_refund = $conn->prepare($sql_refund_discount);
                        $stmt_refund->bind_param("s", $booking_details['diskon_id']);
                        $stmt_refund->execute();

                        if ($stmt_refund->affected_rows > 0) {
                            // Log refund activity
                            $log_refund_sql = "INSERT INTO activity_log 
                                             (user_id, action_type, action_description, entity_type, entity_id, entity_name, hotel_id) 
                                             VALUES (?, 'refund_discount', ?, 'discount', ?, ?, ?)";
                            $stmt_log_refund = $conn->prepare($log_refund_sql);
                            $log_refund_desc = "Refunded discount quota for booking: {$booking_id}. Discount ID: {$booking_details['diskon_id']}";
                            $entity_id = $booking_details['diskon_id'];
                            $entity_name = "Discount Refund";
                            $hotel_id = $booking_details['hotel_id'];
                            $stmt_log_refund->bind_param("sssss", $user_id, $log_refund_desc, $entity_id, $entity_name, $hotel_id);
                            $stmt_log_refund->execute();
                            $stmt_log_refund->close();
                        }

                        // Delete usage record
                        $sql_delete_usage = "DELETE FROM penggunaan_diskon 
                                           WHERE booking_id = ? AND diskon_id = ?";
                        $stmt_delete = $conn->prepare($sql_delete_usage);
                        $stmt_delete->bind_param("ss", $booking_id, $booking_details['diskon_id']);
                        $stmt_delete->execute();
                        $stmt_delete->close();
                    }
                    $stmt_usage->close();
                }
                // ========== END REFUND DISKON ==========

                // 2. Refund room availability (gunakan GREATEST untuk mencegah negatif)
                $new_terbooking = $booking_details['terbooking'] - $booking_details['jumlah_kamar'];
                if ($new_terbooking < 0) $new_terbooking = 0;

                $sql_update_room = "UPDATE jadwal_hotel 
                                   SET terbooking = GREATEST(terbooking - ?, 0)
                                   WHERE hotel_id = ? AND tipe_id = ?";
                $stmt_room = $conn->prepare($sql_update_room);
                $stmt_room->bind_param(
                    "iss",
                    $booking_details['jumlah_kamar'],
                    $booking_details['hotel_id'],
                    $booking_details['tipe_id']
                );
                $stmt_room->execute();
                $stmt_room->close();

                // 3. Cancel booking
                $sql_cancel = "UPDATE booking_hotel 
                              SET status = 'Cancelled' 
                              WHERE booking_id = ?";
                $stmt_cancel = $conn->prepare($sql_cancel);
                $stmt_cancel->bind_param("s", $booking_id);
                $stmt_cancel->execute();
                $stmt_cancel->close();

                // 4. Update transaction status
                $sql_trans = "UPDATE transaksi t
                             JOIN transaksi_hotel th ON t.id_transaksi = th.id_transaksi
                             SET t.status_transaksi = 'Cancelled'
                             WHERE th.booking_id = ?";
                $stmt_trans = $conn->prepare($sql_trans);
                $stmt_trans->bind_param("s", $booking_id);
                $stmt_trans->execute();
                $stmt_trans->close();

                // 5. Log cancellation activity
                $log_sql = "INSERT INTO activity_log 
                          (user_id, action_type, action_description, entity_type, entity_id, entity_name, hotel_id) 
                          VALUES (?, 'cancel_booking', ?, 'booking', ?, ?, ?)";
                $stmt_log = $conn->prepare($log_sql);
                $log_desc = "Cancelled booking: {$booking_id}";
                $entity_id = $booking_id;
                $entity_name = $booking_id;
                $hotel_id = $booking_details['hotel_id'];
                $stmt_log->bind_param("sssss", $user_id, $log_desc, $entity_id, $entity_name, $hotel_id);
                $stmt_log->execute();
                $stmt_log->close();

                $conn->commit();

                // Clear session data
                if (isset($_SESSION['payment_data'])) {
                    unset($_SESSION['payment_data']);
                }

                $_SESSION['success'] = "Booking successfully cancelled. Discount quota has been refunded if applicable.";
                header("Location: hotel.php");
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                throw new Exception("Failed to cancel booking: " . $e->getMessage());
            }
        }

        // Handle payment confirmation
        if (isset($_POST['confirm_payment'])) {
            $payment_method = isset($_POST['payment_method']) ? $conn->real_escape_string($_POST['payment_method']) : 'QRIS';

            if (!isset($_SESSION['payment_data'])) {
                throw new Exception("Payment data not found. Please refresh the page.");
            }

            $booking_id = $_SESSION['payment_data']['booking_id'];
            $customer_id = $_SESSION['payment_data']['customer_id'];
            $total_harga = (float) $_SESSION['payment_data']['total_harga'];

            if (empty($booking_id) || empty($customer_id)) {
                throw new Exception("Incomplete payment data");
            }

            $conn->begin_transaction();

            try {
                // Verify booking is still pending and within time limit WITH LOCK
                $sql_check_booking = "SELECT booking_id, tanggal_booking, diskon_id, hotel_id 
                                    FROM booking_hotel 
                                    WHERE booking_id = ? AND customer_id = ? AND status = 'Pending'
                                    AND TIMESTAMPDIFF(MINUTE, tanggal_booking, NOW()) <= 2
                                    FOR UPDATE";
                $stmt = $conn->prepare($sql_check_booking);
                $stmt->bind_param("ss", $booking_id, $customer_id);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 0) {
                    // Check if booking expired
                    $sql_check_expired = "SELECT booking_id FROM booking_hotel 
                                        WHERE booking_id = ? AND status = 'Pending'
                                        AND TIMESTAMPDIFF(MINUTE, tanggal_booking, NOW()) > 2";
                    $stmt_expired = $conn->prepare($sql_check_expired);
                    $stmt_expired->bind_param("s", $booking_id);
                    $stmt_expired->execute();

                    if ($stmt_expired->get_result()->num_rows > 0) {
                        // Auto cancel expired booking and refund discount
                        cancelExpiredBooking($conn, $booking_id);
                        throw new Exception("Payment window has expired. Booking automatically cancelled.");
                    } else {
                        throw new Exception("Booking not found or already processed");
                    }
                }

                $booking_data = $result->fetch_assoc();
                $stmt->close();

                // IMPORTANT: CONFIRM DISCOUNT USAGE
                // Cek apakah diskon sudah tercatat di penggunaan_diskon
                if (!empty($booking_data['diskon_id'])) {
                    $sql_check_discount_usage = "SELECT id_penggunaan FROM penggunaan_diskon 
                                               WHERE booking_id = ? AND diskon_id = ?";
                    $stmt_check = $conn->prepare($sql_check_discount_usage);
                    $stmt_check->bind_param("ss", $booking_id, $booking_data['diskon_id']);
                    $stmt_check->execute();
                    $discount_used = $stmt_check->get_result()->num_rows > 0;
                    $stmt_check->close();

                    // Jika diskon di booking tapi belum dicatat, catat sekarang
                    if (!$discount_used) {
                        // Dapatkan nilai diskon dari booking
                        $sql_get_discount_value = "SELECT nilai_diskon FROM booking_hotel 
                                                  WHERE booking_id = ?";
                        $stmt_val = $conn->prepare($sql_get_discount_value);
                        $stmt_val->bind_param("s", $booking_id);
                        $stmt_val->execute();
                        $discount_value_result = $stmt_val->get_result()->fetch_assoc();
                        $nilai_diskon = $discount_value_result['nilai_diskon'] ?? 0;
                        $stmt_val->close();

                        if ($nilai_diskon > 0) {
                            // Catat penggunaan diskon
                            $id_penggunaan = 'USE' . date('YmdHis') . rand(100, 999);
                            $sql_record_usage = "INSERT INTO penggunaan_diskon 
                                               (id_penggunaan, diskon_id, booking_id, id_user, nilai_diskon, tanggal_digunakan) 
                                               VALUES (?, ?, ?, ?, ?, NOW())";
                            $stmt_record = $conn->prepare($sql_record_usage);
                            $stmt_record->bind_param(
                                "ssssd",
                                $id_penggunaan,
                                $booking_data['diskon_id'],
                                $booking_id,
                                $customer_id,
                                $nilai_diskon
                            );
                            $stmt_record->execute();
                            $stmt_record->close();
                        }
                    }
                }

                // Update booking status
                $sql_update_booking = "UPDATE booking_hotel 
                                     SET status = 'Completed', 
                                         metode_pembayaran = ? 
                                     WHERE booking_id = ?";
                $stmt = $conn->prepare($sql_update_booking);
                $stmt->bind_param("ss", $payment_method, $booking_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to update booking status");
                }
                $stmt->close();

                // Update status di tabel transaksi utama
                $sql_update_transaksi = "
                    UPDATE transaksi t
                    JOIN transaksi_hotel th ON t.id_transaksi = th.id_transaksi
                    SET t.status_transaksi = 'Completed'
                    WHERE th.booking_id = ?";
                $stmt_trans = $conn->prepare($sql_update_transaksi);
                $stmt_trans->bind_param("s", $booking_id);
                $stmt_trans->execute();
                $stmt_trans->close();

                // Update status di tabel transaksi_hotel
                $sql_update_transaksi_hotel = "
                    UPDATE transaksi_hotel 
                    SET status = 'Completed'
                    WHERE booking_id = ?";
                $stmt_th = $conn->prepare($sql_update_transaksi_hotel);
                $stmt_th->bind_param("s", $booking_id);
                $stmt_th->execute();
                $stmt_th->close();

                // Log activity
                $log_sql = "INSERT INTO activity_log 
                          (user_id, action_type, action_description, entity_type, entity_id, entity_name, hotel_id) 
                          VALUES (?, 'complete_payment', ?, 'booking', ?, ?, ?)";
                $stmt_log = $conn->prepare($log_sql);

                $user_id = $_SESSION['id_user'];
                $log_desc = "Completed payment for booking: {$booking_id}";
                $entity_id = $booking_id;
                $entity_name = $booking_id;
                $hotel_id = $booking_data['hotel_id'] ?? '';

                $stmt_log->bind_param("sssss", $user_id, $log_desc, $entity_id, $entity_name, $hotel_id);
                $stmt_log->execute();
                $stmt_log->close();

                $conn->commit();

                // ========== KIRIM NOTIFIKASI WHATSAPP ==========
                try {
                    // Ambil data customer untuk notifikasi
                    $sql_customer = "SELECT 
                        c.nama, 
                        c.no_hp 
                    FROM customer c 
                    WHERE c.customer_id = ?";

                    $stmt_customer = $conn->prepare($sql_customer);
                    $stmt_customer->bind_param("s", $customer_id);
                    $stmt_customer->execute();
                    $customer_data = $stmt_customer->get_result()->fetch_assoc();
                    $stmt_customer->close();

                    if ($customer_data && !empty($customer_data['no_hp'])) {
                        // Normalisasi nomor (optional, nanti di notification_payment juga bisa normalize lagi)
                        $phone = preg_replace('/[^0-9]/', '', $customer_data['no_hp']);

                        // Ambil data hotel untuk notifikasi
                        $sql_hotel_info = "SELECT 
                                h.nama_hotel,
                                bh.check_in,
                                bh.check_out,
                                bh.total_harga
                            FROM booking_hotel bh
                            JOIN hotel h ON bh.hotel_id = h.hotel_id
                            WHERE bh.booking_id = ?";

                        $stmt_hotel = $conn->prepare($sql_hotel_info);
                        $stmt_hotel->bind_param("s", $booking_id);
                        $stmt_hotel->execute();
                        $hotel_info = $stmt_hotel->get_result()->fetch_assoc();
                        $stmt_hotel->close();

                        // Data untuk notifikasi (KEY harus sama dengan yang dipakai di notification_payment.php)
                        $notif_data = [
                            'phone'      => $phone,
                            'nama'       => $customer_data['nama'] ?? 'Pelanggan',
                            'booking_id' => $booking_id,
                            'hotel_name' => $hotel_info['nama_hotel'] ?? 'Hotel',
                            'check_in'   => $hotel_info['check_in'] ?? '',
                            'check_out'  => $hotel_info['check_out'] ?? '',
                            'total'      => $hotel_info['total_harga'] ?? 0
                        ];

                        // Kirim notifikasi (debug = true supaya hasil API kelihatan di log)
                        $notif_result = sendPaymentNotification($notif_data, true);

                        error_log("[WHATSAPP PAYMENT] Payload: " . print_r($notif_data, true));
                        error_log("[WHATSAPP PAYMENT] Result: " . print_r($notif_result, true));
                    } else {
                        error_log("[WHATSAPP] Customer data not found or phone number is empty");
                    }
                } catch (Exception $e) {
                    // Jangan ganggu flow utama jika notifikasi gagal
                    error_log("[WHATSAPP ERROR] " . $e->getMessage());
                }
                // ========== END NOTIFIKASI ==========

                // Prepare confirmation data
                $_SESSION['payment_confirmation'] = [
                    'booking_id' => $booking_id,
                    'total_harga' => $total_harga,
                    'payment_method' => $payment_method,
                    'payment_status' => 'Completed',
                    'hotel_data' => $_SESSION['payment_data']['hotel_data'],
                    'room_data' => $_SESSION['payment_data']['room_data'],
                    'check_in' => $_SESSION['payment_data']['check_in'],
                    'check_out' => $_SESSION['payment_data']['check_out'],
                    'durasi' => $_SESSION['payment_data']['durasi'],
                    'jumlah_kamar' => $_SESSION['payment_data']['jumlah_kamar'],
                    'diskon_id' => $booking_data['diskon_id'] ?? null
                ];

                unset($_SESSION['payment_data']);

                header("Location: booking_confirmation.php?booking_id=" . urlencode($booking_id));
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Payment Error: " . $e->getMessage());
    }
}

// Process GET request to display payment page
try {
    $conn = new mysqli($host, $username, $password, $database);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    $booking_id = isset($_GET['booking_id']) ? $conn->real_escape_string($_GET['booking_id']) : null;
    if (empty($booking_id)) {
        throw new Exception("Invalid booking ID");
    }

    if (empty($_SESSION['id_user'])) {
        throw new Exception("Invalid user session");
    }
    $user_id = $_SESSION['id_user'];

    // Get customer ID
    $customer_id = null;
    $sql = "SELECT customer_id FROM customer WHERE id_user = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $customer = $result->fetch_assoc();
    $stmt->close();

    if (!$customer || !isset($customer['customer_id'])) {
        $customer_id = $user_id;
    } else {
        $customer_id = $customer['customer_id'];
    }

    // Get booking details
    $sql = "SELECT 
            bh.*, 
            h.nama_hotel, h.kota, h.foto_hotel, h.alamat, h.hotel_id,
            tk.nama_tipe as tipe_kamar, tk.deskripsi,
            jh.harga,
            TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(bh.tanggal_booking, INTERVAL 2 MINUTE)) as waktu_tersisa,
            bh.total_fasilitas_ekstra,
            bh.diskon_id,
            bh.nilai_diskon,
            dp.nama_diskon, dp.kode_promo
        FROM booking_hotel bh
        JOIN hotel h ON bh.hotel_id = h.hotel_id
        JOIN tipe_kamar tk ON bh.tipe_id = tk.tipe_id
        JOIN jadwal_hotel jh ON bh.hotel_id = jh.hotel_id AND bh.tipe_id = jh.tipe_id
        LEFT JOIN diskon_promo dp ON bh.diskon_id = dp.diskon_id
        WHERE bh.booking_id = ? AND bh.customer_id = ? AND bh.status = 'Pending'
        LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $booking_id, $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        // Check if booking expired
        if (cancelExpiredBooking($conn, $booking_id)) {
            throw new Exception("Payment window has expired. Booking cancelled and discount quota refunded.");
        } else {
            throw new Exception("Booking not found or already processed");
        }
    }

    // Process booking data
    if (empty($booking['check_in']) || empty($booking['check_out'])) {
        throw new Exception("Invalid check-in/check-out dates");
    }

    $checkin = new DateTime($booking['check_in']);
    $checkout = new DateTime($booking['check_out']);
    $durasi = $checkout->diff($checkin)->days;

    if ($durasi < 0) {
        throw new Exception("Check-out date must be after check-in date");
    }

    $checkinDisplay = $checkin->format('d M Y');
    $checkoutDisplay = $checkout->format('d M Y');
    $waktu_tersisa = $booking['waktu_tersisa'] > 0 ? $booking['waktu_tersisa'] : 0;
    $is_expired = ($waktu_tersisa <= 0);

    // Prepare display data with proper null checks
    $hotel = [
        'hotel_id' => $booking['hotel_id'],
        'foto_hotel' => $booking['foto_hotel'] ?? '../img/default-hotel.jpg',
        'nama_hotel' => $booking['nama_hotel'] ?? 'Hotel Tidak Ditemukan',
        'kota' => $booking['kota'] ?? 'Kota Tidak Diketahui',
        'alamat' => $booking['alamat'] ?? 'Alamat Tidak Diketahui'
    ];

    $room = [
        'tipe_kamar' => $booking['tipe_kamar'] ?? 'Tipe Kamar Tidak Diketahui',
        'harga' => $booking['harga'] ?? 0,
        'deskripsi' => $booking['deskripsi'] ?? 'Tidak ada deskripsi'
    ];

    $kamar = $booking['jumlah_kamar'] ?? 0;
    $total_harga = $booking['total_harga'] ?? 0;
    $total_fasilitas_ekstra = $booking['total_fasilitas_ekstra'] ?? 0;
    $diskon_id = $booking['diskon_id'] ?? null;
    $nilai_diskon = $booking['nilai_diskon'] ?? 0;
    $nama_diskon = $booking['nama_diskon'] ?? null;
    $kode_promo = $booking['kode_promo'] ?? null;

    if ($total_harga <= 0) {
        throw new Exception("Invalid total price");
    }

    // Get selected facilities for this booking
    $selected_facilities = [];
    if ($booking_id) {
        $sql_facilities = "SELECT bf.*, fe.nama_fasilitas, fe.kategori 
                          FROM booking_fasilitas_ekstra bf 
                          JOIN fasilitas_ekstra fe ON bf.fasilitas_id = fe.fasilitas_id 
                          WHERE bf.booking_id = ?";
        $stmt_facilities = $conn->prepare($sql_facilities);
        $stmt_facilities->bind_param("s", $booking_id);
        $stmt_facilities->execute();
        $result_facilities = $stmt_facilities->get_result();

        while ($facility = $result_facilities->fetch_assoc()) {
            $selected_facilities[] = $facility;
        }
        $stmt_facilities->close();
    }

    // Calculate base price (before discount and facilities)
    $base_harga_kamar = ($room['harga'] * $durasi * $kamar);

    // Calculate harga setelah diskon
    $harga_setelah_diskon = $base_harga_kamar - $nilai_diskon;
    if ($harga_setelah_diskon < 0) {
        $harga_setelah_diskon = 0;
    }

    // Store payment data in session
    $_SESSION['payment_data'] = [
        'booking_id' => $booking_id,
        'customer_id' => $customer_id,
        'total_harga' => $total_harga,
        'hotel_data' => $hotel,
        'room_data' => $room,
        'check_in' => $booking['check_in'],
        'check_out' => $booking['check_out'],
        'durasi' => $durasi,
        'jumlah_kamar' => $kamar,
        'total_fasilitas_ekstra' => $total_fasilitas_ekstra,
        'diskon_id' => $diskon_id,
        'nilai_diskon' => $nilai_diskon,
        'base_harga_kamar' => $base_harga_kamar,
        'selected_facilities' => $selected_facilities
    ];
} catch (Exception $e) {
    $error = $e->getMessage();
    error_log("System Error: " . $e->getMessage());
}

// Close connection if it exists
if (isset($conn)) {
    $conn->close();
}

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
    <title>TripVerse - QRIS Payment</title>
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
            --primary-color: #FEA116;
            --secondary-color: #FEA116;
            --dark-color: #2c3e50;
            --light-color: #f8f9fa;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        .payment-container {
            background-color: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
        }

        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin: 2.5rem 0;
            position: relative;
        }

        .progress-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 6px;
            background: #f0f0f0;
            border-radius: 3px;
            z-index: 1;
        }

        .progress-bar {
            position: absolute;
            top: 20px;
            left: 0;
            height: 6px;
            background: linear-gradient(90deg, var(--primary-color), #FF7A3D);
            border-radius: 3px;
            z-index: 2;
            transition: width 0.5s ease;
            width: 66.66%;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 3;
            position: relative;
            flex: 1;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 0.75rem;
            border: 4px solid white;
            position: relative;
        }

        .step.active .step-number {
            background: linear-gradient(135deg, #FEA116 0%, #FF7A3D 100%);
            color: white;
            transform: scale(1.1);
            box-shadow: 0 8px 20px rgba(254, 161, 22, .45);
            animation: tv-pulse-glow 2.2s infinite;
        }

        .step.completed .step-number {
            background: #16A34A;
            color: white;
        }

        .step.completed .step-number::after {
            content: '\f00c';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            font-size: 1rem;
        }

        .step-label {
            font-size: 0.95rem;
            color: #777;
            text-align: center;
            font-weight: 500;
            margin-top: 0.5rem;
        }

        .step.active .step-label {
            color: var(--primary-color);
            font-weight: 600;
        }

        .step.completed .step-label {
            color: #16A34A;
        }

        .countdown-timer {
            font-size: 1.2rem;
            font-weight: bold;
            color: #DC2626;
            background-color: #f8d7da;
            padding: 8px 18px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 15px;
            animation: tv-timer-pulse 1.6s ease-in-out infinite;
        }

        @keyframes tv-timer-pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, .35); }
            50% { box-shadow: 0 0 0 8px rgba(220, 53, 69, 0); }
        }

        .expired-message {
            color: #DC2626;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .qris-container {
            text-align: center;
            padding: 24px;
            border: 1px solid var(--tv-border, #eee);
            border-radius: 18px;
            margin: 20px 0;
            background: linear-gradient(180deg, #fff 0%, #fffaf3 100%);
            box-shadow: 0 12px 28px rgba(15, 23, 43, 0.06);
        }

        .qris-code {
            width: 200px;
            height: 200px;
            margin: 0 auto;
            background-color: white;
            padding: 10px;
            border: 1px solid #eee;
            border-radius: 14px;
            box-shadow: 0 6px 16px rgba(15, 23, 43, 0.08);
        }

        .qris-instruction {
            margin-top: 20px;
            text-align: left;
        }

        .instruction-step {
            display: flex;
            margin-bottom: 10px;
        }

        .step-icon {
            width: 24px;
            height: 24px;
            background-color: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 10px;
            flex-shrink: 0;
        }

        .btn-primary {
            background: linear-gradient(135deg, #FEA116 0%, #FF7A3D 100%);
            border: none;
            padding: 13px 26px;
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

        .summary-card {
            background-color: white;
            border-radius: 18px;
            padding: 25px;
            box-shadow: var(--shadow);
            position: sticky;
            top: 20px;
            margin-bottom: 30px;
            border-top: 4px solid transparent;
            border-image: linear-gradient(135deg, #FEA116, #FF7A3D) 1;
        }

        .hotel-image {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .price-detail {
            border-top: 1px dashed #ddd;
            border-bottom: 1px dashed #ddd;
            padding: 15px 0;
            margin: 15px 0;
        }

        .price-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .price-total {
            font-weight: bold;
            font-size: 18px;
            color: var(--primary-color);
        }

        /* CSS UNTUK RINGKASAN SEPERTI PENAWARAN.PHP */
        .selected-facilities {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .selected-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--dark-color);
            font-size: 0.9rem;
        }

        .facility-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: #6c757d;
            padding: 0.25rem 0;
        }

        .facility-quantity {
            color: var(--dark-color);
            font-weight: 500;
            margin-left: 0.5rem;
        }

        .no-facilities {
            color: #6c757d;
            font-style: italic;
            text-align: center;
            padding: 1rem;
            font-size: 0.875rem;
        }

        .text-success {
            color: #16A34A !important;
        }

        .savings-remark {
            font-size: 0.875rem;
            color: #16A34A;
            font-weight: 500;
        }

        .discount-badge {
            background: linear-gradient(135deg, #16A34A, #22C55E);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .discount-info {
            background: #f8f9fa;
            border-left: 4px solid #16A34A;
            padding: 0.75rem;
            border-radius: 4px;
            margin: 0.5rem 0;
        }

        .discount-name {
            font-weight: 600;
            color: #16A34A;
        }

        .discount-code {
            background: #e9f7ef;
            color: #16A34A;
            padding: 0.2rem 0.5rem;
            border-radius: 3px;
            font-family: monospace;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }

        @media (max-width: 768px) {
            .progress-steps {
                flex-direction: column;
                align-items: flex-start;
                margin: 1.5rem 0;
            }

            .progress-steps::before,
            .progress-bar {
                display: none;
            }

            .step {
                flex-direction: row;
                margin-bottom: 1.5rem;
                width: 100%;
                align-items: center;
            }

            .step-number {
                margin-right: 1.2rem;
                margin-bottom: 0;
            }

            .step-label {
                margin-top: 0;
                text-align: left;
            }
        }

        .footer {
            margin-top: auto;
            background-color: #0b1120;
            color: #eee;
            font-size: 14px;
            padding: 1.5rem 0;
        }

        .footer .section-title {
            font-weight: bold;
            font-size: 16px;
            position: relative;
            display: inline-block;
            margin-bottom: 15px;
        }

        .footer .btn-link {
            color: #eee;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 5px;
        }

        .footer .btn-link:hover {
            text-decoration: underline;
            color: #FEA116;
        }

        .footer .btn-social {
            width: 30px;
            height: 30px;
            line-height: 30px;
            font-size: 14px;
            border-radius: 50%;
            background-color: #222;
            color: #eee;
            text-align: center;
            margin-right: 8px;
            display: inline-block;
        }

        .footer .btn-social:hover {
            background-color: #FEA116;
            color: #000;
        }

        .footer-menu a {
            color: #eee;
            margin: 0 8px;
            text-decoration: none;
        }

        .footer-menu a:hover {
            text-decoration: underline;
            color: #FEA116;
        }

        .footer hr {
            border-color: rgba(255, 255, 255, 0.1);
        }

        @media (max-width: 768px) {
            .footer .row {
                flex-direction: column;
                text-align: center;
                align-items: center;
            }

            .footer .btn-social {
                margin-bottom: 8px;
            }

            .footer .section-title::after {
                display: none;
            }
        }

        /* Additional styles for refund notice */
        .refund-notice {
            background-color: #f8f9fa;
            border-left: 4px solid #17a2b8;
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 4px;
            font-size: 0.9rem;
        }

        .refund-notice i {
            color: #17a2b8;
            margin-right: 0.5rem;
        }
    </style>
</head>

<body>
    <div class="container-fluid" style="background-color: #ffffff;">

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
                    <a href="about.php" class="d-flex align-items-center text-decoration-none">
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

                    <!-- Navbar -->
                    <nav class="navbar navbar-expand-lg bg-dark navbar-dark p-3 p-lg-0">
                        <a href="home.php" class="navbar-brand d-block d-lg-none">
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
                    <h1 class="display-3 text-white mb-3 animated slideInDown"><?= te('Pembayaran QRIS') ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb justify-content-center text-uppercase">
                            <li class="breadcrumb-item"><a href="home.php"><?= te('Beranda') ?></a></li>
                            <li class="breadcrumb-item"><a href="hotel.php"><?= te('Hotel') ?></a></li>
                            <li class="breadcrumb-item"><a href="booking.php"><?= te('Pemesanan') ?></a></li>
                            <li class="breadcrumb-item text-white active" aria-current="page"><?= te('Pembayaran') ?></li>
                        </ol>
                    </nav>
                </div>
            </div>
        </div>

        <!-- Konten Pembayaran -->
        <div class="container-fluid py-5">
            <div class="container">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>Error!</strong> <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <strong>Success!</strong> <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Konten Utama -->
                    <div class="col-lg-8">
                        <div class="payment-container">
                            <div class="payment-header">
                                <h2 class="mb-3"><?= te('Pembayaran QRIS') ?></h2>

                                <?php if (!$is_expired && $waktu_tersisa > 0): ?>
                                    <div class="countdown-timer">
                                        <i class="fas fa-clock me-2"></i>
                                        <?= te('Waktu tersisa:') ?> <span id="countdown"><?= gmdate("i:s", $waktu_tersisa) ?></span>
                                    </div>
                                <?php elseif ($is_expired): ?>
                                    <div class="expired-message">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <?= te('Waktu pembayaran telah habis. Pemesanan otomatis dibatalkan.') ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Langkah-langkah -->
                                <div class="progress-steps">
                                    <div class="progress-bar"></div>

                                    <div class="step completed">
                                        <div class="step-number">1</div>
                                        <div class="step-label"><?= te('Detail Pemesanan') ?></div>
                                    </div>

                                    <div class="step completed">
                                        <div class="step-number">2</div>
                                        <div class="step-label"><?= te('Penawaran Layanan') ?></div>
                                    </div>

                                    <div class="step active">
                                        <div class="step-number">3</div>
                                        <div class="step-label"><?= te('Pembayaran') ?></div>
                                    </div>

                                    <div class="step">
                                        <div class="step-number">4</div>
                                        <div class="step-label"><?= te('Konfirmasi') ?></div>
                                    </div>
                                </div>
                            </div>

                            <?php if (!$is_expired && $waktu_tersisa > 0): ?>
                                <!-- Refund Notice -->
                                <?php if ($diskon_id && $nilai_diskon > 0): ?>
                                    <div class="refund-notice">
                                        <i class="fas fa-info-circle"></i>
                                        <strong><?= te('Perhatian:') ?></strong> <?= te('Jika Anda membatalkan pemesanan, kuota diskon') ?> <strong><?= htmlspecialchars($discount_details['nama_diskon'] ?? t('Diskon')) ?></strong> <?= te('akan otomatis dikembalikan ke sistem.') ?>
                                    </div>
                                <?php endif; ?>

                                <form method="POST" action="payment.php">
                                    <input type="hidden" name="payment_method" value="QRIS">
                                    <input type="hidden" name="booking_id" value="<?= htmlspecialchars($booking_id) ?>">

                                    <!-- Bagian Pembayaran QRIS -->
                                    <div class="form-section">
                                        <h3 class="section-title"><?= te('Bayar dengan QRIS') ?></h3>

                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <?= te('Silakan scan kode QR di bawah menggunakan aplikasi mobile banking atau e-wallet Anda untuk menyelesaikan pembayaran.') ?>
                                        </div>

                                        <div class="qris-container">
                                            <div class="qris-code">
                                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode('TripVerseBooking:' . $booking_id . ':' . $customer_id . ':' . $total_harga) ?>"
                                                    alt="<?= te('Kode QRIS') ?>"
                                                    onerror="this.onerror=null; this.src='../img/qr-placeholder.jpg';">
                                            </div>

                                            <div class="mt-3">
                                                <h5><?= te('Total Pembayaran') ?></h5>
                                                <h3 class="text-primary">Rp <?= number_format($total_harga, 0, ',', '.') ?></h3>
                                                <small class="text-muted"><?= te('ID Pemesanan:') ?> <?= htmlspecialchars($booking_id) ?></small>
                                            </div>

                                            <div class="qris-instruction">
                                                <h5 class="mt-4"><?= te('Petunjuk Pembayaran:') ?></h5>

                                                <div class="instruction-step">
                                                    <div class="step-icon">1</div>
                                                    <div><?= te('Buka aplikasi mobile banking atau e-wallet Anda yang mendukung QRIS') ?></div>
                                                </div>

                                                <div class="instruction-step">
                                                    <div class="step-icon">2</div>
                                                    <div><?= te('Pilih menu "Scan QR" atau "Bayar dengan QRIS"') ?></div>
                                                </div>

                                                <div class="instruction-step">
                                                    <div class="step-icon">3</div>
                                                    <div><?= te('Arahkan kamera Anda ke kode QR di atas') ?></div>
                                                </div>

                                                <div class="instruction-step">
                                                    <div class="step-icon">4</div>
                                                    <div><?= te('Masukkan jumlah pembayaran jika diminta') ?> (Rp <?= number_format($total_harga, 0, ',', '.') ?>)</div>
                                                </div>

                                                <div class="instruction-step">
                                                    <div class="step-icon">5</div>
                                                    <div><?= te('Konfirmasi pembayaran dan masukkan PIN/Password Anda') ?></div>
                                                </div>

                                                <div class="instruction-step">
                                                    <div class="step-icon">6</div>
                                                    <div><?= te('Tunggu notifikasi pembayaran berhasil') ?></div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <?= te('Pemesanan Anda akan otomatis dibatalkan jika pembayaran tidak selesai dalam 2 menit.') ?>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between mt-4">
                                        <a href="extra_services.php?booking_id=<?= htmlspecialchars($booking_id) ?>" class="btn tv-btn-ghost">
                                            <i class="fas fa-arrow-left me-2"></i> <?= te('Kembali') ?>
                                        </a>

                                        <div>
                                            <!-- Button Batalkan -->
                                            <form action="payment.php" method="POST" style="display:inline;">
                                                <input type="hidden" name="booking_id" value="<?= htmlspecialchars($booking_id) ?>">
                                                <button type="submit" name="cancel_booking"
                                                    class="btn btn-danger me-2"
                                                    onclick="return confirm('<?= t('Apakah Anda yakin ingin membatalkan pemesanan ini?') ?>')">
                                                    <i class="fas fa-times-circle me-2"></i> <?= te('Batalkan Pemesanan') ?>
                                                </button>
                                            </form>

                                            <!-- Button Konfirmasi Pembayaran -->
                                            <form action="payment.php" method="POST" style="display:inline;">
                                                <input type="hidden" name="booking_id" value="<?= htmlspecialchars($booking_id) ?>">
                                                <input type="hidden" name="payment_method" value="QRIS">

                                                <button type="submit" name="confirm_payment"
                                                    class="btn btn-primary"
                                                    onclick="return confirm('<?= t('Pastikan Anda sudah menyelesaikan pembayaran. Lanjutkan konfirmasi?') ?>')">
                                                    <i class="fas fa-check-circle me-2"></i> <?= te('Saya Sudah Bayar') ?>
                                                </button>
                                            </form>


                                        </div>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="text-center mt-4">
                                    <a href="hotel.php" class="btn btn-primary">
                                        <i class="fas fa-hotel me-2"></i> Cari Hotel Lain
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Ringkasan Pesanan -->
                    <div class="col-lg-4">
                        <div class="summary-card">
                            <h4 class="mb-4"><?= te('Ringkasan Pemesanan') ?></h4>

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
                                    <span><?= $durasi ?> <?= t('malam') ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted"><?= te('Jumlah Kamar:') ?></span>
                                    <span><?= $kamar ?></span>
                                </div>
                            </div>

                            <div class="price-detail">
                                <!-- Harga Kamar Sebelum Diskon -->
                                <div class="price-item">
                                    <span><?= te('Harga Kamar') ?> (<?= $durasi ?> <?= t('malam') ?>)</span>
                                    <span>Rp <?= number_format($base_harga_kamar, 0, ',', '.') ?></span>
                                </div>

                                <!-- Diskon (jika ada) -->
                                <?php if ($nilai_diskon > 0): ?>
                                    <div class="price-item text-success">
                                        <span>
                                            <i class="fas fa-tag me-1"></i>
                                            <?= !empty($discount_details) ? htmlspecialchars($discount_details['nama_diskon']) : te('Diskon') ?>
                                        </span>
                                        <span>- Rp <?= number_format($nilai_diskon, 0, ',', '.') ?></span>
                                    </div>

                                    <!-- Harga Kamar Setelah Diskon -->
                                    <div class="price-item">
                                        <span><?= te('Harga Kamar Setelah Diskon') ?></span>
                                        <span>Rp <?= number_format($harga_setelah_diskon, 0, ',', '.') ?></span>
                                    </div>
                                <?php endif; ?>

                                <!-- Selected Facilities -->
                                <div class="selected-facilities">
                                    <h6 class="selected-title"><?= te('Layanan Tambahan') ?></h6>
                                    <?php if (!empty($selected_facilities)): ?>
                                        <?php foreach ($selected_facilities as $facility): ?>
                                            <div class="facility-item">
                                                <div>
                                                    <span><?= htmlspecialchars($facility['nama_fasilitas']) ?></span>
                                                    <span class="facility-quantity"> × <?= $facility['quantity'] ?></span>
                                                </div>
                                                <span>Rp <?= number_format($facility['subtotal'], 0, ',', '.') ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="no-facilities"><?= te('Belum ada layanan tambahan') ?></div>
                                    <?php endif; ?>
                                </div>

                                <!-- Total Fasilitas -->
                                <?php if (!empty($selected_facilities)): ?>
                                    <div class="price-item mt-3">
                                        <span><?= te('Total Layanan Tambahan') ?></span>
                                        <span>Rp <?= number_format($total_fasilitas_ekstra, 0, ',', '.') ?></span>
                                    </div>
                                <?php endif; ?>

                                <!-- Pajak & Layanan -->
                                <div class="price-item">
                                    <span><?= te('Pajak & Layanan:') ?></span>
                                    <span><?= te('Termasuk') ?></span>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <h5><?= te('Total Pembayaran') ?></h5>
                                <div class="text-end">
                                    <?php if ($nilai_diskon > 0): ?>
                                        <small class="savings-remark d-block">
                                            <i class="fas fa-piggy-bank me-1"></i>
                                            <?= te('Hemat') ?> Rp <?= number_format($nilai_diskon, 0, ',', '.') ?>
                                        </small>
                                    <?php endif; ?>
                                    <h4 class="price-total mb-0">Rp <?= number_format($total_harga, 0, ',', '.') ?></h4>
                                </div>
                            </div>

                            <!-- Badge Diskon jika ada -->
                            <?php if ($nilai_diskon > 0 && !empty($discount_details)): ?>
                                <div class="mt-3">
                                    <div class="discount-info">
                                        <div>
                                            <i class="fas fa-tag me-1"></i>
                                            <span class="discount-name"><?= htmlspecialchars($discount_details['nama_diskon']) ?></span>
                                            <?php if (!empty($discount_details['kode_promo'])): ?>
                                                <span class="discount-code"><?= htmlspecialchars($discount_details['kode_promo']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            Diskon <?= ($discount_details['tipe_diskon'] ?? 'percentage') === 'percentage' ?
                                                        $discount_details['nilai_diskon'] . '%' :
                                                        'Rp ' . number_format($discount_details['nilai_diskon'], 0, ',', '.') ?> berhasil diterapkan
                                        </small>
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
                    spinner.classList.remove('show');
                }
            });

            // Countdown timer
            <?php if (!$is_expired && $waktu_tersisa > 0): ?>
                let secondsLeft = <?= $waktu_tersisa ?>;

                function updateCountdown() {
                    secondsLeft--;

                    if (secondsLeft <= 0) {
                        clearInterval(timer);
                        document.getElementById('countdown').textContent = "00:00";
                        location.reload();
                        return;
                    }

                    const minutes = Math.floor(secondsLeft / 60);
                    const seconds = secondsLeft % 60;
                    document.getElementById('countdown').textContent =
                        `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                }

                const timer = setInterval(updateCountdown, 1000);
            <?php endif; ?>

            // Confirmation before cancelling booking
            document.getElementById('cancel-booking')?.addEventListener('click', function(e) {
                <?php if ($diskon_id && $nilai_diskon > 0): ?>
                    const discountName = "<?= !empty($discount_details) ? htmlspecialchars(addslashes($discount_details['nama_diskon'])) : t('Diskon') ?>";
                    if (!confirm(`<?= t('Apakah Anda yakin ingin membatalkan pemesanan ini?') ?>\n\n<?= t('Kuota diskon') ?> "${discountName}" <?= t('akan otomatis dikembalikan ke sistem.') ?>`)) {
                        e.preventDefault();
                    }
                <?php else: ?>
                    if (!confirm('<?= t('Apakah Anda yakin ingin membatalkan pemesanan ini?') ?>')) {
                        e.preventDefault();
                    }
                <?php endif; ?>
            });

            // Confirmation before submitting payment
            document.getElementById('confirm-payment')?.addEventListener('click', function(e) {
                if (!confirm('<?= t('Apakah Anda sudah menyelesaikan pembayaran?') ?>')) {
                    e.preventDefault();
                }
            });
        </script>
    </div>
</body>

</html>