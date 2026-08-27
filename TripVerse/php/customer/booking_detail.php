<?php
session_start();
require_once __DIR__ . '/../_lang.php';

// Redirect jika user belum login
if (!isset($_SESSION['id_user'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Create database connection
require_once __DIR__ . '/../db_config.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize variables
$booking = [];
$error = null;

// Validasi booking_id
if (!isset($_GET['id'])) {
    header("Location: history.php");
    exit;
}

$booking_id = $conn->real_escape_string($_GET['id']);
$user_id = $_SESSION['id_user'];

// Function to restore room availability
function restoreRoomAvailability($conn, $hotel_id, $tipe_id, $jumlah_kamar)
{
    $sql = "UPDATE jadwal_hotel 
            SET stok_total = stok_total + ? 
            WHERE hotel_id = ? AND tipe_id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("iii", $jumlah_kamar, $hotel_id, $tipe_id);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        throw new Exception("Failed to restore room availability");
    }

    $stmt->close();
}

try {
    // Query untuk mendapatkan detail booking
    $sql = "SELECT 
            bh.*, 
            h.nama_hotel, h.kota, h.foto_hotel, h.alamat, h.hotel_id,
            t.nama_tipe, t.deskripsi, t.kapasitas_standar, t.ukuran_standar, t.tipe_id,
            jh.harga, jh.stok_total,
            th.transaksi_id_hotel, th.id_transaksi,
            tr.total_harga, tr.tanggal_transaksi, tr.status_transaksi,
            u.first_name, u.last_name, u.email as user_email, u.no_hp
        FROM booking_hotel bh
        JOIN hotel h ON bh.hotel_id = h.hotel_id
        JOIN tipe_kamar t ON bh.tipe_id = t.tipe_id
        JOIN jadwal_hotel jh ON bh.hotel_id = jh.hotel_id AND bh.tipe_id = jh.tipe_id
        LEFT JOIN transaksi_hotel th ON bh.booking_id = th.booking_id
        LEFT JOIN transaksi tr ON th.id_transaksi = tr.id_transaksi
        JOIN customer c ON bh.customer_id = c.customer_id
        JOIN user u ON c.id_user = u.id_user
        WHERE bh.booking_id = ? AND u.id_user = ?";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("ss", $booking_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception(t("Booking tidak ditemukan atau Anda tidak memiliki akses"));
    }

    $booking = $result->fetch_assoc();
    $stmt->close();

    // Hitung durasi
    $check_in = new DateTime($booking['check_in']);
    $check_out = new DateTime($booking['check_out']);
    $durasi = $check_out->diff($check_in)->days;

    // Format tanggal
    $checkin_display = $check_in->format('d M Y');
    $checkout_display = $check_out->format('d M Y');
    $tanggal_pesan = date('d M Y H:i', strtotime($booking['tanggal_transaksi']));

    // Handle cancellation
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_booking'])) {
        $conn->begin_transaction();

        try {
            // Update booking status
            $sql = "UPDATE booking_hotel SET status = 'Cancelled' WHERE booking_id = ? AND status = 'Pending'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $booking_id);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                throw new Exception("Failed to cancel booking or booking already processed");
            }

            // Restore room availability
            restoreRoomAvailability($conn, $booking['hotel_id'], $booking['tipe_id'], $booking['jumlah_kamar']);

            $conn->commit();

            // Redirect to prevent form resubmission
            header("Location: booking_detail.php?id=" . $booking_id);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= te('Detail Pesanan') ?> - TripVerse</title>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700</title>family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="../../css/tv-modern.css?v=<?= @filemtime(__DIR__ . '/../css/tv-modern.css') ?>" rel="stylesheet">

    <style>
        .detail-card {
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .hotel-img {
            width: 100%;
            height: 250px;
            object-fit: cover;
            border-radius: 8px;
        }

        .status-badge {
            font-size: 0.9rem;
            padding: 6px 12px;
            border-radius: 20px;
        }

        .status-completed {
            background-color: #16A34A;
            color: white;
        }

        .status-pending {
            background-color: #ffc107;
            color: black;
        }

        .status-cancelled {
            background-color: #DC2626;
            color: white;
        }

        .price-tag {
            font-weight: bold;
            color: #FEA116;
        }

        .time-remaining {
            color: #DC2626;
            font-weight: bold;
            margin: 8px 0;
        }
    </style>
</head>

<body>
    <div class="container py-5">
        <div class="row mb-4">
            <div class="col">
                <a href="history.php" class="btn btn-outline-secondary mb-3">
                    <i class="fas fa-arrow-left me-2"></i> <?= te('Kembali ke Riwayat') ?>
                </a>
                <h2><?= te('Detail Pesanan') ?></h2>
                <p class="text-muted"><?= te('Kode Booking:') ?> <?= htmlspecialchars($booking_id) ?></p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php else: ?>
            <div class="detail-card p-4 mb-4">
                <div class="row">
                    <div class="col-md-4">
                        <?php if (!empty($booking['foto_hotel'])): ?>
                            <img src="<?= htmlspecialchars($booking['foto_hotel']) ?>" class="hotel-img" alt="<?= htmlspecialchars($booking['nama_hotel']) ?>">
                        <?php else: ?>
                            <div class="hotel-img bg-light d-flex align-items-center justify-content-center">
                                <i class="fas fa-hotel fa-3x text-muted"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-8">
                        <div class="d-flex justify-content-between align-items-start">
                            <h3><?= htmlspecialchars($booking['nama_hotel']) ?></h3>
                            <span class="badge <?=
                                                $booking['status'] == 'Completed' ? 'status-completed' : ($booking['status'] == 'Pending' ? 'status-pending' : 'status-cancelled')
                                                ?>">
                                <?= $booking['status'] ?>
                            </span>
                        </div>

                        <p class="text-muted">
                            <i class="fas fa-map-marker-alt"></i>
                            <?= htmlspecialchars($booking['alamat']) ?>, <?= htmlspecialchars($booking['kota']) ?>
                        </p>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <p><strong><?= te('Check-In:') ?></strong> <?= $checkin_display ?></p>
                                <p><strong><?= te('Check-Out:') ?></strong> <?= $checkout_display ?></p>
                                <p><strong><?= te('Durasi:') ?></strong> <?= $durasi ?> <?= t('malam') ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong><?= te('Tipe Kamar:') ?></strong> <?= htmlspecialchars($booking['nama_tipe']) ?></p>
                                <p><strong><?= te('Jumlah Kamar:') ?></strong> <?= $booking['jumlah_kamar'] ?></p>
                                <p><strong><?= te('Kapasitas:') ?></strong> <?= $booking['kapasitas_standar'] ?> <?= te('orang') ?></p>
                            </div>
                        </div>

                        <?php if ($booking['status'] == 'Pending'): ?>
                            <?php
                            $minutes_since_booking = round((time() - strtotime($booking['tanggal_booking'])) / 60);
                            $minutes_left = max(0, 2 - $minutes_since_booking);
                            ?>
                            <div class="time-remaining">
                                <i class="fas fa-clock"></i> <?= te('Waktu tersisa:') ?>
                                <span id="countdown"><?= $minutes_left ?>:00</span> <?= te('menit') ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <hr>

                <div class="row mt-4">
                    <div class="col-md-6">
                        <h5><?= te('Detail Pemesan') ?></h5>
                        <p><strong><?= te('Nama') ?>:</strong> <?= htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']) ?></p>
                        <p><strong>Email:</strong> <?= htmlspecialchars($booking['user_email']) ?></p>
                        <p><strong><?= te('No. HP:') ?></strong> <?= htmlspecialchars($booking['no_hp']) ?></p>
                    </div>

                    <div class="col-md-6">
                        <h5><?= te('Detail Pembayaran') ?></h5>
                        <p><strong><?= te('ID Transaksi:') ?></strong> <?= htmlspecialchars($booking['id_transaksi'] ?? '-') ?></p>
                        <p><strong><?= te('Tanggal Transaksi:') ?></strong> <?= $tanggal_pesan ?></p>
                        <p><strong><?= te('Metode Pembayaran:') ?></strong> <?= htmlspecialchars($booking['metode_pembayaran'] ?? 'QRIS') ?></p>
                        <p><strong><?= te('Total Pembayaran:') ?></strong> <span class="price-tag">Rp <?= number_format($booking['total_harga'], 0, ',', '.') ?></span></p>
                    </div>
                </div>

                <hr>

                <div class="mt-3">
                    <h5><?= te('Deskripsi Kamar') ?></h5>
                    <p><?= htmlspecialchars($booking['deskripsi']) ?></p>
                    <p><strong><?= te('Ukuran Kamar:') ?></strong> <?= $booking['ukuran_standar'] ?> m²</p>
                    <p><strong><?= te('Kamar Tersedia:') ?></strong> <?= $booking['stok_total'] + ($booking['status'] == 'Pending' ? $booking['jumlah_kamar'] : 0) ?></p>
                </div>

                <div class="d-flex justify-content-end mt-4">
                    <?php if ($booking['status'] == 'Completed'): ?>
                        <a href="booking_confirmation.php?booking_id=<?= $booking_id ?>" class="btn btn-primary me-2">
                            <i class="fas fa-receipt me-2"></i> <?= te('Lihat Invoice') ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($booking['status'] == 'Pending'): ?>
                        <form method="POST" class="d-inline">
                            <button type="submit" name="cancel_booking" class="btn btn-danger me-2" onclick="return confirm('<?= t('Apakah Anda yakin ingin membatalkan pesanan ini?') ?>')">
                                <i class="fas fa-times me-2"></i> <?= te('Batalkan Pesanan') ?>
                            </button>
                        </form>
                        <a href="payment.php?booking_id=<?= $booking_id ?>" class="btn btn-warning me-2">
                            <i class="fas fa-money-bill-wave me-2"></i> <?= te('Bayar Sekarang') ?>
                        </a>
                    <?php endif; ?>

                    <a href="hotel.php" class="btn btn-outline-primary">
                        <i class="fas fa-search me-2"></i> <?= te('Cari Hotel Lain') ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Countdown timer for pending bookings
        <?php if ($booking['status'] == 'Pending'): ?>
            // Hitung ulang waktu tersisa dalam detik
            let secondsLeft = 120; // 2 menit = 120 detik

            function updateCountdown() {
                secondsLeft--;

                if (secondsLeft <= 0) {
                    clearInterval(timer);
                    document.getElementById('countdown').textContent = "00:00";
                    // Auto refresh ketika waktu habis
                    setTimeout(() => location.reload(), 1000);
                    return;
                }

                const minutes = Math.floor(secondsLeft / 60);
                const seconds = secondsLeft % 60;
                document.getElementById('countdown').textContent =
                    `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            }

            const timer = setInterval(updateCountdown, 1000);
            updateCountdown(); // Panggil sekali untuk inisialisasi awal
        <?php endif; ?>
    </script>
    <script src="../../js/tv-modern.js?v=<?= @filemtime(__DIR__ . '/../js/tv-modern.js') ?>"></script>
</body>

</html>
<?php
$conn->close();
?>