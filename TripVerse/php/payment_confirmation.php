<?php
require_once(__DIR__ . '/connect.php');
require_once(__DIR__ . '/fonnte_api.php');
require_once __DIR__ . '/_lang.php';
date_default_timezone_set("Asia/Jakarta");

// ============================
// KIRIM SINGLE ATAU ALL
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ----------------------
    // KIRIM SINGLE
    // ----------------------
    if (isset($_POST['send_single']) && !empty($_POST['id_transaksi'])) {
        $id_transaksi = intval($_POST['id_transaksi']);

        $sql = "SELECT 
            tr.id_transaksi,
            bh.booking_id,
            bh.customer_name,
            bh.check_in,
            bh.check_out,
            h.nama_hotel,
            h.kota,
            tr.total_harga,
            u.no_hp
        FROM transaksi tr
        JOIN transaksi_hotel th ON tr.id_transaksi = th.id_transaksi
        JOIN booking_hotel bh ON th.booking_id = bh.booking_id
        JOIN hotel h ON bh.hotel_id = h.hotel_id
        JOIN customer c ON bh.customer_id = c.customer_id
        JOIN user u ON c.id_user = u.id_user
        WHERE tr.status_transaksi = 'Completed'
          AND tr.id_transaksi = $id_transaksi
          AND tr.is_wa_sent = 0
        LIMIT 1";

        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $message = "

Salam hangat dari TripVerse! 🌟

Halo Sendy Ferdian Sujadi,  
Terima kasih telah menyelesaikan pembayaran untuk pemesanan Anda melalui TripVerse. ✅  
Kami sangat menghargai kepercayaan Anda dan berkomitmen untuk memberikan pengalaman perjalanan yang nyaman, aman, dan berkesan.

📍 Detail Pemesanan Anda:  
🏨 Hotel: Hotel Indonesia Kempinski (Jakarta)  
📅 Tanggal Check-in: 28 Oct 2025  
📆 Tanggal Check-out: 29 Oct 2025  
💰 Total Pembayaran: Rp 4.000.000

Untuk memastikan pengalaman menginap yang optimal, kami menyarankan beberapa hal:  
• Pastikan dokumen penting, termasuk identitas resmi (KTP/Paspor), telah Anda siapkan.  
• Periksa kembali konfirmasi pemesanan agar tidak ada kekeliruan.  
• Persiapkan kebutuhan pribadi agar perjalanan Anda lebih nyaman dan menyenangkan.

Jika terdapat pertanyaan atau kebutuhan khusus terkait pemesanan Anda, tim layanan pelanggan kami siap membantu kapan saja.

Terima kasih telah memilih TripVerse sebagai mitra perjalanan Anda.  
Kami berharap pengalaman menginap Anda menyenangkan dan penuh kenangan indah.

— Tim TripVerse 💙

> Sent via fonnte.com
";
            if (kirimPesanFonnte($row['no_hp'], $message)) {
                $conn->query("UPDATE transaksi SET is_wa_sent=1, wa_sent_at=NOW() WHERE id_transaksi={$row['id_transaksi']}");
                echo json_encode(['success'=>true, 'id'=>$row['id_transaksi']]);
            } else {
                echo json_encode(['success'=>false, 'message'=>t('Gagal mengirim pesan')]);
            }
        } else {
            echo json_encode(['success'=>false, 'message'=>t('Transaksi tidak ditemukan atau sudah dikirim')]);
        }
        exit;
    }

    // ----------------------
    // KIRIM SEMUA
    // ----------------------
    if (isset($_POST['send_all'])) {
        $sqlAll = "SELECT 
            tr.id_transaksi,
            bh.booking_id,
            bh.customer_name,
            bh.check_in,
            bh.check_out,
            h.nama_hotel,
            h.kota,
            h.alamat,
            tr.total_harga,
            tr.tanggal_transaksi,
            tr.status_transaksi,
            u.no_hp
        FROM booking_hotel bh
        JOIN hotel h ON bh.hotel_id = h.hotel_id
        JOIN transaksi_hotel th ON bh.booking_id = th.booking_id
        JOIN transaksi tr ON th.id_transaksi = tr.id_transaksi
        JOIN customer c ON bh.customer_id = c.customer_id
        JOIN user u ON c.id_user = u.id_user
        WHERE tr.status_transaksi = 'Completed'
          AND DATE(CONVERT_TZ(tr.tanggal_transaksi, '+00:00', '+07:00')) = CURDATE()
          AND tr.is_wa_sent = 0
        ORDER BY tr.tanggal_transaksi ASC";

        $resAll = $conn->query($sqlAll);
        $sentIds = [];

        if ($resAll && $resAll->num_rows > 0) {
            while($row = $resAll->fetch_assoc()) {
                $message = "

Salam hangat dari TripVerse! 🌟

Halo Sendy Ferdian Sujadi,  
Terima kasih telah menyelesaikan pembayaran untuk pemesanan Anda melalui TripVerse. ✅  
Kami sangat menghargai kepercayaan Anda dan berkomitmen untuk memberikan pengalaman perjalanan yang nyaman, aman, dan berkesan.

📍 Detail Pemesanan Anda:  
🏨 Hotel: Hotel Indonesia Kempinski (Jakarta)  
📅 Tanggal Check-in: 28 Oct 2025  
📆 Tanggal Check-out: 29 Oct 2025  
💰 Total Pembayaran: Rp 4.000.000

Untuk memastikan pengalaman menginap yang optimal, kami menyarankan beberapa hal:  
• Pastikan dokumen penting, termasuk identitas resmi (KTP/Paspor), telah Anda siapkan.  
• Periksa kembali konfirmasi pemesanan agar tidak ada kekeliruan.  
• Persiapkan kebutuhan pribadi agar perjalanan Anda lebih nyaman dan menyenangkan.

Jika terdapat pertanyaan atau kebutuhan khusus terkait pemesanan Anda, tim layanan pelanggan kami siap membantu kapan saja.

Terima kasih telah memilih TripVerse sebagai mitra perjalanan Anda.  
Kami berharap pengalaman menginap Anda menyenangkan dan penuh kenangan indah.

— Tim TripVerse 💙

> Sent via fonnte.com
";
                if(kirimPesanFonnte($row['no_hp'], $message)) {
                    $conn->query("UPDATE transaksi SET is_wa_sent=1, wa_sent_at=NOW() WHERE id_transaksi={$row['id_transaksi']}");
                    $sentIds[] = $row['id_transaksi'];
                }
            }
        }
        echo json_encode(['success'=>true, 'sent_ids'=>$sentIds, 'count'=>count($sentIds)]);
        exit;
    }
}

// ============================
// AMBIL DAFTAR TRANSAKSI HARI INI
// ============================
$sql = "SELECT 
    tr.id_transaksi,
    bh.booking_id,
    bh.customer_name,
    bh.check_in,
    bh.check_out,
    h.nama_hotel,
    h.kota,
    h.alamat,
    tr.total_harga,
    tr.tanggal_transaksi,
    tr.status_transaksi,
    tr.is_wa_sent,   -- <=== Tambahkan ini
    u.no_hp
FROM booking_hotel bh
JOIN hotel h ON bh.hotel_id = h.hotel_id
JOIN transaksi_hotel th ON bh.booking_id = th.booking_id
JOIN transaksi tr ON th.id_transaksi = tr.id_transaksi
JOIN customer c ON bh.customer_id = c.customer_id
JOIN user u ON c.id_user = u.id_user
WHERE tr.status_transaksi = 'Completed'
  AND DATE(CONVERT_TZ(tr.tanggal_transaksi, '+00:00', '+07:00')) = CURDATE()
ORDER BY tr.tanggal_transaksi ASC";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?= te('Payment Confirmation') ?> TripVerse</title>
<style>
body { font-family: Arial; background:#f5f7fa; padding:30px; color:#333; }
h2 { color:#0077b6; }
table { border-collapse: collapse; width: 100%; background:white; box-shadow:0 2px 6px rgba(0,0,0,0.1); }
th, td { border:1px solid #ccc; padding:10px; text-align:left; }
th { background:#0077b6; color:white; }
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

<h2>📄 <?= te('Konfirmasi Pembayaran TripVerse') ?> — <?= te('Hari Ini') ?> (<?= date("d M Y") ?>)</h2>

<div class="status" style="display:none;"></div>

<form id="sendAllForm" class="send-all">
    <button type="button">📤 <?= te('Kirim Semua Belum Dikirim') ?></button>
</form>

<table>
<tr>
    <th>No</th>
    <th><?= te('Nama') ?></th>
    <th>Hotel</th>
    <th>Check-in</th>
    <th>Check-out</th>
    <th><?= te('Total') ?></th>
    <th>No. WhatsApp</th>
    <th><?= te('Status WA') ?></th>
    <th><?= te('Aksi') ?></th>
</tr>
<?php
$no=1;
if($result && $result->num_rows>0) {
    while($row=$result->fetch_assoc()) {
        $sentClass = $row['is_wa_sent'] ? "sent" : "not-sent";
        $sentText = $row['is_wa_sent'] ? "✅ " . t("Sudah Dikirim") : "❌ " . t("Belum Dikirim");

        echo "<tr>
            <td>$no</td>
            <td>{$row['customer_name']}</td>
            <td>{$row['nama_hotel']}</td>
            <td>".date('d M Y', strtotime($row['check_in']))."</td>
            <td>".date('d M Y', strtotime($row['check_out']))."</td>
            <td>Rp ".number_format($row['total_harga'],0,',','.')."</td>
            <td>{$row['no_hp']}</td>
            <td class='$sentClass'>$sentText</td>
            <td>";
        if(!$row['is_wa_sent']) {
            echo "<button onclick=\"sendWhatsApp('{$row['id_transaksi']}', this)\">" . t('Kirim Pesan') . "</button>";
        } else {
            echo "-";
        }
        echo "</td></tr>";
        $no++;
    }
} else {
    echo "<tr><td colspan='9'>" . t('Tidak ada transaksi hari ini.') . "</td></tr>";
}
?>
</table>

<script>
// KIRIM PER TRANSAKSI
function sendWhatsApp(id_transaksi, btn){
    btn.disabled = true;
    btn.innerText = "<?= t('Mengirim...') ?>";

    let formData = new FormData();
    formData.append('send_single', 1);
    formData.append('id_transaksi', id_transaksi);

    fetch('', { method:'POST', body: formData })
    .then(res=>res.json())
    .then(data=>{
        if(data.success){
            let statusCell = btn.closest('tr').querySelector('td:nth-child(8)');
            statusCell.className='sent';
            statusCell.innerText='✅ <?= t('Sudah Dikirim') ?>';
            btn.remove();
        } else {
            alert('<?= t('Gagal:') ?> '+data.message);
            btn.disabled=false;
            btn.innerText='<?= t('Kirim Pesan') ?>';
        }
    })
    .catch(err=>{
        alert('Error: '+err);
        btn.disabled=false;
        btn.innerText='<?= t('Kirim Pesan') ?>';
    });
}

// KIRIM SEMUA
document.querySelector('#sendAllForm button').addEventListener('click', function() {
    let btn = this;
    btn.disabled = true;
    btn.innerText = "<?= t('Mengirim semua...') ?>";

    let formData = new FormData();
    formData.append('send_all', 1);

    fetch('', { method:'POST', body: formData })
    .then(res=>res.json())
    .then(data=>{
        if(data.success){
            data.sent_ids.forEach(id=>{
                let row = document.querySelector(`button[onclick*="${id}"]`)?.closest('tr');
                if(row){
                    let statusCell = row.querySelector('td:nth-child(8)');
                    statusCell.className='sent';
                    statusCell.innerText='✅ <?= t('Sudah Dikirim') ?>';
                    let btnRow = row.querySelector('button');
                    if(btnRow) btnRow.remove();
                }
            });
            let statusDiv = document.querySelector('.status');
            statusDiv.style.display = 'block';
            statusDiv.innerText = `✅ <?= t('Berhasil mengirim') ?> ${data.count} <?= t('pesan.') ?>`;
        } else {
            alert("<?= t('Gagal mengirim semua pesan') ?>");
        }
        btn.disabled=false;
        btn.innerText = "📤 <?= t('Kirim Semua Belum Dikirim') ?>";
    })
    .catch(err=>{
        alert("Error: "+err);
        btn.disabled=false;
        btn.innerText = "📤 <?= t('Kirim Semua Belum Dikirim') ?>";
    });
});
</script>

</body>
</html>
