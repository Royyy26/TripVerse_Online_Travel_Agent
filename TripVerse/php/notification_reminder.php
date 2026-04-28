<?php
require_once(__DIR__ . '/connect.php');
require_once(__DIR__ . '/fonnte_api.php');
date_default_timezone_set("Asia/Jakarta");

$tanggalBesok = date("Y-m-d", strtotime("+1 day"));
$status = "";

// ============================
// KIRIM WA PER BOOKING
// ============================
if (isset($_POST['send_whatsapp'])) {
    $booking_id = $_POST['booking_id'] ?? '';
    $nama = $_POST['nama'] ?? '';
    $hotel = $_POST['hotel'] ?? '';
    $checkin = $_POST['checkin'] ?? '';
    $checkout = $_POST['checkout'] ?? '';
    $no_wa = $_POST['no_wa'] ?? '';

    if ($booking_id && $nama && $no_wa) {
        $message = "Salam hangat dari TripVerse! 🌟

Perkenalkan, kami adalah tim TripVerse, platform perjalanan yang selalu berkomitmen memberikan pengalaman menginap dan perjalanan yang aman, nyaman, dan berkesan bagi setiap pelanggan kami.

Halo Sendy Ferdian Sujadi,

Kami ingin mengingatkan bahwa besok Anda dijadwalkan untuk melakukan check-in di hotel yang telah Anda pesan melalui layanan kami.

📍 Detail Pemesanan Anda:
🏨 Hotel: Hotel Indonesia Kempinski
📅 Tanggal Check-in: 2025-10-28
📆 Tanggal Check-out: 2025-10-29

Untuk memastikan pengalaman menginap yang nyaman dan lancar, kami menyarankan beberapa hal:
• Pastikan seluruh dokumen penting, termasuk identitas resmi (KTP/Paspor), telah disiapkan.
• Datang sesuai waktu check-in yang tertera pada konfirmasi pemesanan.
• Siapkan kebutuhan pribadi dan barang bawaan agar perjalanan Anda lebih menyenangkan.

Apabila terdapat pertanyaan atau kebutuhan khusus terkait pemesanan Anda, jangan ragu untuk menghubungi tim layanan pelanggan kami.

Terima kasih atas kepercayaan Anda menggunakan TripVerse. Semoga pengalaman menginap Anda penuh kenyamanan dan kenangan yang menyenangkan.

— Tim TripVerse 💙

> Sent via fonnte.com";

        $kirim = kirimPesanFonnte($no_wa, $message);

        if ($kirim) {
            $conn->query("UPDATE booking_hotel SET is_wa_sent = 1 WHERE booking_id = '$booking_id'");
            $status = "✅ Pesan berhasil dikirim ke $nama ($no_wa)";
        } else {
            $status = "❌ Gagal mengirim pesan ke $nama ($no_wa)";
        }
    } else {
        $status = "❌ Data tidak lengkap, pesan tidak dikirim.";
    }
}

// ============================
// KIRIM SEMUA WA BELUM DIKIRIM
// ============================
if (isset($_POST['send_all'])) {
    $sqlAll = "
        SELECT 
            bh.booking_id, bh.customer_name, bh.check_in, bh.check_out, 
            bh.hotel_id, h.nama_hotel, u.no_hp
        FROM booking_hotel bh
        JOIN customer c ON bh.customer_id = c.customer_id
        JOIN user u ON c.id_user = u.id_user
        JOIN hotel h ON bh.hotel_id = h.hotel_id
        WHERE bh.status = 'Completed'
          AND DATE(bh.check_in) = '$tanggalBesok'
          AND bh.is_wa_sent = 0
    ";
    $resAll = $conn->query($sqlAll);
    $countSent = 0;

    if ($resAll && $resAll->num_rows > 0) {
        while ($row = $resAll->fetch_assoc()) {
            $message = "Salam hangat dari TripVerse! 🌟

Perkenalkan, kami adalah tim TripVerse, platform perjalanan yang selalu berkomitmen memberikan pengalaman menginap dan perjalanan yang aman, nyaman, dan berkesan bagi setiap pelanggan kami.

Halo Sendy Ferdian Sujadi,

Kami ingin mengingatkan bahwa besok Anda dijadwalkan untuk melakukan check-in di hotel yang telah Anda pesan melalui layanan kami.

📍 Detail Pemesanan Anda:
🏨 Hotel: Hotel Indonesia Kempinski
📅 Tanggal Check-in: 2025-10-28
📆 Tanggal Check-out: 2025-10-29

Untuk memastikan pengalaman menginap yang nyaman dan lancar, kami menyarankan beberapa hal:
• Pastikan seluruh dokumen penting, termasuk identitas resmi (KTP/Paspor), telah disiapkan.
• Datang sesuai waktu check-in yang tertera pada konfirmasi pemesanan.
• Siapkan kebutuhan pribadi dan barang bawaan agar perjalanan Anda lebih menyenangkan.

Apabila terdapat pertanyaan atau kebutuhan khusus terkait pemesanan Anda, jangan ragu untuk menghubungi tim layanan pelanggan kami.

Terima kasih atas kepercayaan Anda menggunakan TripVerse. Semoga pengalaman menginap Anda penuh kenyamanan dan kenangan yang menyenangkan.

— Tim TripVerse 💙

> Sent via fonnte.com";

            if (kirimPesanFonnte($row['no_hp'], $message)) {
                $conn->query("UPDATE booking_hotel SET is_wa_sent = 1 WHERE booking_id = '{$row['booking_id']}'");
                $countSent++;
            }
        }
    }

    $status = $countSent > 0 ? "✅ Berhasil mengirim $countSent pesan." : "❌ Tidak ada pesan yang dikirim.";
}

// ============================
// AMBIL DAFTAR BOOKING BESOK
// ============================
$sql = "
    SELECT 
        bh.booking_id, bh.customer_name, bh.check_in, bh.check_out, 
        bh.status, bh.hotel_id, h.nama_hotel, u.no_hp, bh.is_wa_sent
    FROM booking_hotel bh
    JOIN customer c ON bh.customer_id = c.customer_id
    JOIN user u ON c.id_user = u.id_user
    JOIN hotel h ON bh.hotel_id = h.hotel_id
    WHERE bh.status = 'Completed'
      AND DATE(bh.check_in) = '$tanggalBesok'
    ORDER BY bh.booking_id DESC
";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Manual Reminder Check-In TripVerse</title>
<style>
body { font-family: Arial; background:#f5f7fa; padding:30px; color:#333; }
h2 { color:#0077b6; }
table { border-collapse: collapse; width: 100%; background:white; box-shadow:0 2px 6px rgba(0,0,0,0.1); }
th, td { border:1px solid #ccc; padding:10px; text-align:left; }
th { background:#0077b6; color:white; }
form { margin:0; }
button { background:#0077b6; color:white; border:none; padding:8px 12px; border-radius:4px; cursor:pointer; }
button:hover { background:#005f87; }
.status { margin-bottom:15px; padding:10px; background:#e6f7e9; border-left:5px solid #00b050; }
.status-error { background:#fdecea; border-left:5px solid #d93025; }
.sent { color:green; font-weight:bold; }
.not-sent { color:red; font-weight:bold; }
.send-all { margin-bottom:15px; }
</style>
</head>
<body>

<h2>📅 Pengingat Check-In TripVerse — Besok (<?= date("d M Y", strtotime($tanggalBesok)) ?>)</h2>

<?php if (!empty($status)): ?>
<div class="status"><?= $status ?></div>
<?php endif; ?>

<form method="POST" class="send-all">
    <button type="submit" name="send_all">📤 Kirim Semua Belum Dikirim</button>
</form>

<table>
<tr>
    <th>No</th>
    <th>Nama</th>
    <th>Hotel</th>
    <th>Check-in</th>
    <th>Check-out</th>
    <th>No. WhatsApp</th>
    <th>Status WA</th>
    <th>Aksi</th>
</tr>
<?php
$no = 1;
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $sentClass = $row['is_wa_sent'] ? "sent" : "not-sent";
        $sentText = $row['is_wa_sent'] ? "✅ Sudah Dikirim" : "❌ Belum Dikirim";

        echo "<tr>
            <td>$no</td>
            <td>{$row['customer_name']}</td>
            <td>{$row['nama_hotel']}</td>
            <td>{$row['check_in']}</td>
            <td>{$row['check_out']}</td>
            <td>{$row['no_hp']}</td>
            <td class='$sentClass'>$sentText</td>
            <td>";
        if (!$row['is_wa_sent']) {
            echo "<form method='POST'>
                    <input type='hidden' name='booking_id' value='{$row['booking_id']}'>
                    <input type='hidden' name='nama' value='{$row['customer_name']}'>
                    <input type='hidden' name='hotel' value='{$row['nama_hotel']}'>
                    <input type='hidden' name='checkin' value='{$row['check_in']}'>
                    <input type='hidden' name='checkout' value='{$row['check_out']}'>
                    <input type='hidden' name='no_wa' value='{$row['no_hp']}'>
                    <button type='submit' name='send_whatsapp'>Kirim Pesan</button>
                  </form>";
        } else {
            echo "-";
        }
        echo "</td></tr>";
        $no++;
    }
} else {
    echo "<tr><td colspan='8'>Tidak ada pelanggan yang check-in besok.</td></tr>";
}
?>
</table>

</body>
</html>
