<?php
session_start();
require_once __DIR__ . '/../_lang.php';

// OPTIONAL: jangan tampilkan error di halaman user (production).
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Redirect jika tidak login
if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit;
}

// =====================================================
// CEK DATA DARI PAYMENT_CONFIRMATION.PHP (TAPI JANGAN SKIP QUERY DB)
// =====================================================
$from_payment_confirmation = false;
$confirmation = [];
$booking_id_from_session = null;

if (isset($_SESSION['payment_confirmation'])) {
    $from_payment_confirmation = true;
    $confirmation = $_SESSION['payment_confirmation'];

    // Ambil minimal booking_id dan payment_status saja.
    $booking_id_from_session = $confirmation['booking_id'] ?? null;
    // jangan assign semua fields — kita akan mengambil dari DB agar selalu sinkron
    // Hilangkan session flag agar tidak dipakai lagi
    unset($_SESSION['payment_confirmation']);
}

// =====================================================
// JIKA TIDAK DARI PAYMENT_CONFIRMATION, LOAD DARI DATABASE
// =====================================================
require_once __DIR__ . '/../connect.php';

// Inisialisasi variabel
$error = null;
// Prioritas: GET booking_id, jika tidak ada gunakan booking_id dari session (jika ada)
$booking_id = $_GET['booking_id'] ?? $booking_id_from_session ?? null;

if (!$booking_id) {
    header("Location: history.php");
    exit;
}

try {
    $user_id = $_SESSION['id_user'];

    // Query data booking
    $sql = $conn->prepare("
        SELECT 
            bh.*,
            h.nama_hotel, h.kota, h.foto_hotel, h.alamat,
            tk.nama_tipe, tk.deskripsi, tk.kapasitas_standar, tk.ukuran_standar,
            jh.harga as harga_per_malam,
            th.transaksi_id_hotel, th.id_transaksi,
            tr.total_harga, tr.tanggal_transaksi, tr.status_transaksi,
            c.nama as customer_name, c.email as customer_email,
            u.no_hp
        FROM booking_hotel bh
        JOIN hotel h ON bh.hotel_id = h.hotel_id
        JOIN tipe_kamar tk ON bh.tipe_id = tk.tipe_id
        JOIN jadwal_hotel jh ON bh.hotel_id = jh.hotel_id AND bh.tipe_id = jh.tipe_id
        JOIN customer c ON bh.customer_id = c.customer_id
        JOIN user u ON c.id_user = u.id_user
        LEFT JOIN transaksi_hotel th ON bh.booking_id = th.booking_id
        LEFT JOIN transaksi tr ON th.id_transaksi = tr.id_transaksi
        WHERE bh.booking_id = ? AND u.id_user = ?
        LIMIT 1
    ");

    if ($sql === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $sql->bind_param("ss", $booking_id, $user_id);
    $sql->execute();
    $result = $sql->get_result();

    if ($result->num_rows === 0) {
        throw new Exception(t("Booking tidak ditemukan atau tidak memiliki akses."));
    }

    $booking_data = $result->fetch_assoc();

    // Hitung durasi
    $check_in_date = new DateTime($booking_data['check_in']);
    $check_out_date = new DateTime($booking_data['check_out']);
    $durasi = $check_out_date->diff($check_in_date)->days;
    $durasi = max(1, $durasi);

    // Ambil fasilitas ekstra
    $fasilitas_sql = $conn->prepare("
        SELECT bfe.*, fe.nama_fasilitas, fe.deskripsi as fasilitas_deskripsi
        FROM booking_fasilitas_ekstra bfe
        JOIN fasilitas_ekstra fe ON bfe.fasilitas_id = fe.fasilitas_id
        WHERE bfe.booking_id = ?
    ");

    $selected_facilities = [];
    $total_fasilitas_ekstra = 0;

    if ($fasilitas_sql) {
        $fasilitas_sql->bind_param("s", $booking_id);
        $fasilitas_sql->execute();
        $fasilitas_result = $fasilitas_sql->get_result();

        while ($facility = $fasilitas_result->fetch_assoc()) {
            $selected_facilities[] = $facility;
            $total_fasilitas_ekstra += $facility['subtotal'] ?? 0;
        }
    }

    // Set variabel untuk display (gunakan null-coalescing safe)
    $transaksi_id = $booking_data['id_transaksi'] ?? null;
    $transaksi_hotel_id = $booking_data['transaksi_id_hotel'] ?? null;
    $total_harga = $booking_data['total_harga'] ?? 0;
    $payment_method = $booking_data['metode_pembayaran'] ?? 'QRIS';
    $payment_status = $booking_data['status_transaksi'] ?? $booking_data['status'] ?? 'Completed';
    $hotel = [
        'nama_hotel' => $booking_data['nama_hotel'] ?? null,
        'kota' => $booking_data['kota'] ?? null,
        'foto_hotel' => $booking_data['foto_hotel'] ?? null,
        'alamat' => $booking_data['alamat'] ?? null
    ];
    $room = [
        'tipe_kamar' => $booking_data['nama_tipe'] ?? null,
        'harga' => $booking_data['harga_per_malam'] ?? 0,
        'deskripsi' => $booking_data['deskripsi'] ?? null,
        'kapasitas' => $booking_data['kapasitas_standar'] ?? null,
        'ukuran' => $booking_data['ukuran_standar'] ?? null
    ];
    $check_in = $booking_data['check_in'] ?? date('Y-m-d');
    $check_out = $booking_data['check_out'] ?? date('Y-m-d', strtotime('+1 day'));
    $jumlah_kamar = $booking_data['jumlah_kamar'] ?? 1;
    $nilai_diskon = $booking_data['nilai_diskon'] ?? 0;
    $diskon_id = $booking_data['diskon_id'] ?? null;

    $booking_details = [
        'nama' => $booking_data['customer_name'] ?? null,
        'email' => $booking_data['customer_email'] ?? null,
        'no_hp' => $booking_data['no_hp'] ?? null
    ];

    // Hitung harga
    $base_harga_kamar = ($booking_data['harga_per_malam'] ?? 0) * $durasi * $jumlah_kamar;
    $harga_setelah_diskon = max(0, $base_harga_kamar - $nilai_diskon);

    // Format tanggal
    $checkin_date = date('d M Y', strtotime($check_in));
    $checkout_date = date('d M Y', strtotime($check_out));
    $tanggal_pembayaran = date('d M Y H:i', strtotime($booking_data['tanggal_transaksi'] ?? 'now'));

    // Ambil detail diskon jika ada
    $discount_details = [];
    if ($diskon_id) {
        $discount_sql = $conn->prepare("
            SELECT nama_diskon, tipe_diskon, nilai_diskon, kode_promo
            FROM diskon_promo 
            WHERE diskon_id = ?
        ");
        if ($discount_sql) {
            $discount_sql->bind_param("s", $diskon_id);
            $discount_sql->execute();
            $discount_result = $discount_sql->get_result();

            if ($discount_result->num_rows > 0) {
                $discount_details = $discount_result->fetch_assoc();
            }
        }
    }

    $conn->close();
} catch (Exception $e) {
    $error = $e->getMessage();
    $_SESSION['error'] = $error;
    error_log("Booking confirmation error: " . $error);
    header("Location: history.php");
    exit;
}

// =====================================================
// RENDER PAGE
// =====================================================

// Tentukan status WhatsApp
$whatsapp_sent = false;
if ($from_payment_confirmation) {
    // jika datang dari payment confirmation, kita bisa menandai true/false dari $confirmation
    $whatsapp_sent = $confirmation['whatsapp_sent'] ?? false;
} else {
    // Cek dari database jika ada field is_wa_sent
    $whatsapp_sent = $booking_data['is_wa_sent'] ?? false;
}

// -------------------------
// SAFE DEFAULTS: hindari undefined index
// -------------------------
$hotel = isset($hotel) && is_array($hotel) ? $hotel : [];
$room = isset($room) && is_array($room) ? $room : [];
$booking_details = isset($booking_details) && is_array($booking_details) ? $booking_details : [];
$selected_facilities = isset($selected_facilities) && is_array($selected_facilities) ? $selected_facilities : [];

$hotel = array_merge([
    'nama_hotel' => t('Nama Hotel'),
    'kota'       => '-',
    'foto_hotel' => '', // path relative atau URL
    'alamat'     => '-'
], $hotel);

$room = array_merge([
    'tipe_kamar' => '-',
    'harga'      => 0,
    'deskripsi'  => '-',
    'kapasitas'  => '-',
    'ukuran'     => '-'
], $room);

$booking_details = array_merge([
    'nama'  => '-',
    'email' => '-',
    'no_hp' => '-'
], $booking_details);

// -------------------------
// Helper image validation
// -------------------------
function resolveImageSrc($fotoValue, $uploads_base_web = '/TripVerse/uploads/') {
    $foto = trim((string)$fotoValue);
    if ($foto === '') return '';

    if (strpos($foto, 'data:') === 0) return $foto;
    if (filter_var($foto, FILTER_VALIDATE_URL)) return $foto;
    if (strpos($foto, '/') === 0) return $foto;

    $foto_norm = str_replace('\\', '/', $foto);
    $docroot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));

    if (preg_match('#^[A-Za-z]:/#', $foto_norm) || strpos($foto_norm, '/var/') === 0 || strpos($foto_norm, '/home/') === 0) {
        if (strpos($foto_norm, $docroot) === 0) {
            $webpath = '/' . ltrim(substr($foto_norm, strlen($docroot)), '/');
            if (file_exists($foto_norm)) return $webpath;
        }
        if (file_exists($foto_norm)) {
            error_log("[resolveImageSrc] Found filesystem image outside DOCROOT: $foto_norm");
            return '';
        }
    }

    $candidates = [
        $foto,
        ltrim($uploads_base_web . $foto, '/'),
        '/' . ltrim($uploads_base_web . $foto, '/'),
        '/' . ltrim($foto, '/')
    ];

    foreach ($candidates as $cand) {
        $fsCandidate = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($cand, '/');
        if (file_exists($fsCandidate)) {
            return '/' . ltrim($cand, '/');
        }
    }

    return $foto;
}

// sesuaikan base web folder upload-mu jika diperlukan
$uploads_base_web = '/TripVerse/uploads/'; // --- SESUAIKAN jika perlu ---

$img_src = resolveImageSrc($hotel['foto_hotel'] ?? '', $uploads_base_web);

// LOG for debugging: biarkan ini agar bisa cek di php error_log
error_log("[booking_confirmation] booking_id={$booking_id} foto_hotel_db=" . var_export($hotel['foto_hotel'] ?? '', true) . " resolved_img_src=" . var_export($img_src, true));

$placeholder_html = '<div class="hotel-image bg-light d-flex align-items-center justify-content-center"><i class="fas fa-hotel fa-3x text-muted"></i></div>';

// Ensure $booking_id safe for JS encoding
$js_booking_id = json_encode($booking_id ?? 'unknown');

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Konfirmasi Pembayaran') ?> - TripVerse</title>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700</title>family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>">
    <style>
        :root {
            --primary: #FEA116;
            --primary-light: #FF7A3D;
            --primary-dark: #E8890A;
            --dark: #2c3e50;
            --light: #f8f9fa;
            --gray: #6c757d;
            --success: #16A34A;
        }

        body {
            font-family: 'Heebo', system-ui, -apple-system, sans-serif;
            background-color: #f5f7fa;
        }

        .confirmation-card {
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(15, 23, 43, 0.14);
            background: white;
            padding: 30px;
            animation: tv-card-in-up .6s cubic-bezier(.22, 1, .36, 1);
        }

        @keyframes tv-card-in-up {
            from { opacity: 0; transform: translateY(28px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .success-icon-wrap {
            position: relative;
            width: 110px;
            height: 110px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .success-icon-wrap::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(40, 167, 69, .22) 0%, rgba(40, 167, 69, 0) 70%);
            animation: tv-ring-pulse 1.8s ease-out infinite;
        }

        @keyframes tv-ring-pulse {
            0% { transform: scale(.7); opacity: 1; }
            100% { transform: scale(1.6); opacity: 0; }
        }

        .success-icon {
            font-size: 5rem;
            color: var(--success);
            margin-bottom: 0;
            position: relative;
            z-index: 1;
            animation: tv-check-pop .6s cubic-bezier(.22, 1, .36, 1);
        }

        .hotel-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
        }

        .price-total {
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--primary);
        }

        .btn-download {
            background: linear-gradient(135deg, #FEA116 0%, #FF7A3D 100%);
            color: white;
            border: none;
            padding: 11px 24px;
            border-radius: 999px;
            font-weight: 600;
            box-shadow: 0 10px 24px rgba(254, 161, 22, 0.35);
            transition: transform .3s cubic-bezier(.22, 1, .36, 1), box-shadow .3s ease, filter .3s ease;
        }

        .btn-download:hover {
            filter: brightness(1.06);
            transform: translateY(-3px);
            box-shadow: 0 14px 30px rgba(254, 161, 22, 0.45);
            color: white;
        }

        .info-card {
            background-color: rgba(254, 161, 22, 0.05);
            border-left: 4px solid var(--primary);
            padding: 15px;
            border-radius: 0 8px 8px 0;
        }

        .room-details-divider {
            border-top: 1px solid #dee2e6;
            margin: 1.5rem 0;
            padding-top: 1.5rem;
        }

        .icon-orange {
            color: var(--primary);
        }

        .facility-card {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            background-color: #f8f9fa;
        }

        .facility-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .facility-name {
            font-weight: 600;
            color: var(--dark);
        }

        .facility-price {
            color: var(--primary);
            font-weight: 500;
        }

        .facility-quantity {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .no-facilities {
            color: var(--gray);
            font-style: italic;
            text-align: center;
            padding: 20px;
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

        .savings-remark {
            font-size: 0.875rem;
            color: #16A34A;
            font-weight: 500;
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
    </style>
</head>

<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="confirmation-card">
                    <div class="text-center">
                        <div class="success-icon-wrap">
                            <i class="fas fa-check-circle success-icon"></i>
                        </div>
                        <h2><?= te('Pembayaran Berhasil!') ?></h2>
                        <p class="lead"><?= te('Terima kasih telah memesan melalui TripVerse') ?></p>
                    </div>

                    <div class="alert alert-success mt-4">
                        <h5 class="d-flex align-items-center">
                            <i class="fas fa-receipt icon-orange me-2"></i> <?= te('Detail Transaksi') ?>
                        </h5>
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <p><strong><?= te('Kode Booking:') ?></strong> <?= htmlspecialchars($booking_id ?? '-') ?></p>
                                <p><strong><?= te('ID Transaksi:') ?></strong> <?= htmlspecialchars($transaksi_id ?? '-') ?></p>
                                <p><strong><?= te('ID Transaksi Hotel:') ?></strong> <?= htmlspecialchars($transaksi_hotel_id ?? '-') ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong><?= te('Metode Pembayaran:') ?></strong> <?= htmlspecialchars($payment_method ?? 'QRIS') ?></p>
                                <p><strong><?= te('Tanggal Pembayaran:') ?></strong> <?= htmlspecialchars($tanggal_pembayaran ?? '-') ?></p>
                                <p><strong><?= te('Status:') ?></strong> <span class="badge bg-success"><?= htmlspecialchars($payment_status ?? 'Completed') ?></span></p>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-md-4 mb-3">
                            <!-- === PERBAIKAN: hanya render SATU elemen untuk gambar === -->
                            <?php if (!empty($img_src)): ?>
                                <!-- Jika image source ada, tampilkan <img> -->
                                <img src="<?= htmlspecialchars($img_src) ?>" class="hotel-image" alt="<?= htmlspecialchars($hotel['nama_hotel'] ?? 'Hotel') ?>" onerror="this.style.display='none'; this.parentElement.querySelector('.no-img').style.display='flex'">
                                <!-- placeholder yang awalnya tersembunyi hanya muncul bila onerror dijalankan -->
                                <div class="no-img" style="display:none; height:200px;">
                                    <?= $placeholder_html ?>
                                </div>
                            <?php else: ?>
                                <!-- Jika tidak ada image source, tampilkan placeholder saja -->
                                <?= $placeholder_html ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-8">
                            <h3><?= htmlspecialchars($hotel['nama_hotel'] ?? t('Nama Hotel')) ?></h3>
                            <p class="text-muted">
                                <i class="fas fa-map-marker-alt icon-orange me-1"></i>
                                <?= htmlspecialchars($hotel['alamat'] ?? '-') ?>, <?= htmlspecialchars($hotel['kota'] ?? '-') ?>
                            </p>

                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <div class="bg-light p-2 rounded">
                                        <small class="text-muted d-block"><?= te('Check-In:') ?></small>
                                        <strong><?= htmlspecialchars($checkin_date ?? '-') ?></strong>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="bg-light p-2 rounded">
                                        <small class="text-muted d-block"><?= te('Check-Out:') ?></small>
                                        <strong><?= htmlspecialchars($checkout_date ?? '-') ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h5 class="mb-3"><i class="fas fa-user-circle icon-orange me-2"></i> <?= te('Detail Pemesan') ?></h5>
                            <p><strong><?= te('Nama') ?>:</strong> <?= htmlspecialchars($booking_details['nama'] ?? '-') ?></p>
                            <p><strong>Email:</strong> <?= htmlspecialchars($booking_details['email'] ?? '-') ?></p>
                            <p><strong><?= te('No. HP:') ?></strong> <?= htmlspecialchars($booking_details['no_hp'] ?? '-') ?></p>

                            <div class="room-details-divider">
                                <h5 class="mb-3"><i class="fas fa-bed icon-orange me-2"></i> <?= te('Detail Kamar') ?></h5>
                                <p><strong><?= te('Tipe Kamar:') ?></strong> <?= htmlspecialchars($room['tipe_kamar'] ?? '-') ?></p>
                                <p><strong><?= te('Jumlah Kamar:') ?></strong> <?= htmlspecialchars($jumlah_kamar ?? 1) ?></p>
                                <p><strong><?= te('Durasi Menginap:') ?></strong> <?= htmlspecialchars($durasi ?? 1) ?> <?= t('malam') ?></p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <h5 class="mb-3"><i class="fas fa-money-bill-wave icon-orange me-2"></i> <?= te('Rincian Pembayaran') ?></h5>
                            <div class="price-detail">
                                <!-- Harga Kamar Sebelum Diskon -->
                                <div class="price-item">
                                    <span><?= te('Harga Kamar') ?> (<?= htmlspecialchars($durasi ?? 1) ?> <?= t('malam') ?>)</span>
                                    <span>Rp <?= number_format($base_harga_kamar ?? 0, 0, ',', '.') ?></span>
                                </div>

                                <!-- Diskon (jika ada) -->
                                <?php if (!empty($nilai_diskon) && $nilai_diskon > 0): ?>
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
                                        <span>Rp <?= number_format($harga_setelah_diskon, 0, ',', '.') ?></span>
                                    </div>
                                <?php endif; ?>

                                <!-- Selected Facilities -->
                                <?php if (!empty($selected_facilities)): ?>
                                    <div class="selected-facilities mt-3">
                                        <h6 class="selected-title"><?= te('Layanan Tambahan') ?></h6>
                                        <?php foreach ($selected_facilities as $facility): ?>
                                            <div class="facility-item">
                                                <div>
                                                    <span><?= htmlspecialchars($facility['nama_fasilitas'] ?? '-') ?></span>
                                                    <span class="facility-quantity"> × <?= htmlspecialchars($facility['quantity'] ?? 1) ?></span>
                                                </div>
                                                <span>Rp <?= number_format($facility['subtotal'] ?? 0, 0, ',', '.') ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Total Fasilitas -->
                                    <div class="price-item mt-3">
                                        <span><?= te('Total Layanan Tambahan') ?></span>
                                        <span>Rp <?= number_format($total_fasilitas_ekstra ?? 0, 0, ',', '.') ?></span>
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
                                    <?php if (!empty($nilai_diskon) && $nilai_diskon > 0): ?>
                                        <small class="savings-remark d-block">
                                            <i class="fas fa-piggy-bank me-1"></i>
                                            <?= te('Hemat') ?> Rp <?= number_format($nilai_diskon, 0, ',', '.') ?>
                                        </small>
                                    <?php endif; ?>
                                    <h4 class="price-total mb-0">Rp <?= number_format($total_harga ?? 0, 0, ',', '.') ?></h4>
                                </div>
                            </div>

                            <!-- Badge Diskon jika ada -->
                            <?php if (!empty($nilai_diskon) && $nilai_diskon > 0): ?>
                                <div class="mt-3 text-center">
                                    <span class="discount-badge">
                                        <i class="fas fa-gift me-1"></i>
                                        <?= te('Diskon Berhasil Diterapkan') ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Fasilitas Ekstra Details Section -->
                    <?php if (!empty($selected_facilities)): ?>
                        <div class="row mt-4">
                            <div class="col-12">
                                <h5 class="mb-3"><i class="fas fa-concierge-bell icon-orange me-2"></i> <?= te('Fasilitas Ekstra yang Dipilih') ?></h5>
                                <div class="row">
                                    <?php foreach ($selected_facilities as $facility): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="facility-card">
                                                <div class="facility-header">
                                                    <span class="facility-name"><?= htmlspecialchars($facility['nama_fasilitas'] ?? '-') ?></span>
                                                    <span class="facility-price">Rp <?= number_format($facility['harga_satuan'] ?? 0, 0, ',', '.') ?></span>
                                                </div>
                                                <div class="facility-quantity">
                                                    <?= te('Jumlah:') ?> <?= htmlspecialchars($facility['quantity'] ?? 1) ?> x
                                                    <?= te('Subtotal:') ?> Rp <?= number_format($facility['subtotal'] ?? 0, 0, ',', '.') ?>
                                                </div>
                                                <?php if (!empty($facility['fasilitas_deskripsi'])): ?>
                                                    <p class="mb-0 small text-muted mt-2"><?= htmlspecialchars($facility['fasilitas_deskripsi']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="info-card mt-4">
                        <h5><i class="fas fa-info-circle icon-orange me-2"></i> <?= te('Informasi Penting') ?></h5>
                        <ul class="mt-2">
                            <li><?= te('Tunjukkan kode booking dan identitas saat check-in di hotel') ?></li>
                            <li><?= te('Pembatalan atau perubahan dapat dilakukan minimal 24 jam sebelum check-in') ?></li>
                            <li><?= te('E-ticket telah dikirim ke email') ?> <?= htmlspecialchars($booking_details['email'] ?? '-') ?></li>
                            <li><?= te('Hubungi customer service jika ada pertanyaan') ?></li>
                            <?php if (!empty($selected_facilities)): ?>
                                <li><?= te('Fasilitas ekstra akan disiapkan oleh hotel sesuai dengan pilihan Anda') ?></li>
                            <?php endif; ?>
                            <?php if (!empty($nilai_diskon) && $nilai_diskon > 0): ?>
                                <li><?= te('Diskon telah berhasil diterapkan pada pemesanan Anda') ?></li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="hotel.php" class="btn btn-outline-secondary">
                            <i class="fas fa-home icon-orange me-2"></i> <?= te('Kembali ke Beranda') ?>
                        </a>
                        <div>
                            <a href="history.php" class="btn btn-outline-primary me-2">
                                <i class="fas fa-history icon-orange me-2"></i> <?= te('Lihat Riwayat') ?>
                            </a>
                            <button class="btn btn-download" id="download-receipt">
                                <i class="fas fa-download me-2"></i> <?= te('Unduh Bukti Pembayaran') ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Script: PDF generation -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
        // Initialize jsPDF
        const {
            jsPDF
        } = window.jspdf || {};

        document.getElementById('download-receipt').addEventListener('click', async function() {
            const confirmationCard = document.querySelector('.confirmation-card');
            const bookingId = <?= $js_booking_id ?>;
            const fileName = `Bukti-Pembayaran-${bookingId || 'booking'}.pdf`;

            // Show loading state
            const originalText = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> <?= t('Membuat PDF...') ?>';
            this.disabled = true;

            try {
                const canvas = await html2canvas(confirmationCard, {
                    scale: 2,
                    logging: false,
                    useCORS: true,
                    allowTaint: true,
                    windowWidth: confirmationCard.scrollWidth,
                    windowHeight: confirmationCard.scrollHeight
                });

                const imgWidth = 210; // A4 width in mm
                const imgHeight = (canvas.height * imgWidth) / canvas.width;

                const pdf = new jsPDF({
                    orientation: imgHeight > imgWidth ? 'portrait' : 'landscape',
                    unit: 'mm',
                    format: [imgWidth, imgHeight]
                });

                pdf.addImage(canvas.toDataURL('image/jpeg', 0.9), 'JPEG', 0, 0, imgWidth, imgHeight);
                pdf.save(fileName);
            } catch (err) {
                console.error('Error generating PDF:', err);
                alert('<?= t('Terjadi kesalahan saat membuat PDF. Silakan coba lagi.') ?>');
            } finally {
                this.innerHTML = originalText;
                this.disabled = false;
            }
        });

        // Optional: clear session/notify before unload - currently not used
        window.addEventListener('beforeunload', function() {});
    </script>
    <script src="../../js/tv-modern.js?v=<?= @filemtime(__DIR__ . '/../js/tv-modern.js') ?>"></script>
</body>

</html>
