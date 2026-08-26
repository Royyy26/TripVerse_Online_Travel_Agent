<?php

/**
 * notification_payment.php
 * Single file: ambil data booking dari DB, kirim WA teks, generate PDF (wkhtmltopdf),
 * lalu kirim PDF e-ticket ke WhatsApp via Fonnte.
 */

require_once __DIR__ . '/_lang.php';

function sendPaymentNotification(array $data, bool $debug = false): bool
{
    //-------------------------------
    // KONFIGURASI
    //-------------------------------
    $WKHTMLTOPDF = "C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltopdf.exe";
    // SESUAIKAN JIKA PERLU
    $FONNTE_TOKEN = "x3qTUTpY6pAVRvg9Gbf1"; // GANTI TOKENMU

    //-------------------------------
    // VALIDASI INPUT MINIMAL
    //-------------------------------
    if (empty($data['booking_id']) || empty($data['phone'])) {
        error_log("[NOTIF] booking_id atau phone kosong");
        return false;
    }

    $booking_id = $data['booking_id'];

    // Normalisasi nomor HP (Indonesia)
    $phone = preg_replace('/\D+/', '', $data['phone']);
    if (strpos($phone, '0') === 0) {
        $phone = '62' . substr($phone, 1);
    }

    //-------------------------------
    // AMBIL DATA BOOKING DARI DB
    //-------------------------------
    require_once __DIR__ . "/connect.php";

    $sql = $conn->prepare("
        SELECT 
            bh.*,
            h.nama_hotel, h.kota, h.foto_hotel, h.alamat,
            tk.nama_tipe,
            tr.total_harga, tr.tanggal_transaksi, tr.status_transaksi,
            c.nama AS customer_name, c.email AS customer_email,
            u.no_hp
        FROM booking_hotel bh
        JOIN hotel h ON bh.hotel_id = h.hotel_id
        JOIN tipe_kamar tk ON bh.tipe_id = tk.tipe_id
        JOIN customer c ON bh.customer_id = c.customer_id
        JOIN user u ON c.id_user = u.id_user
        JOIN transaksi_hotel th ON bh.booking_id = th.booking_id
        JOIN transaksi tr ON th.id_transaksi = tr.id_transaksi
        WHERE bh.booking_id = ?
        LIMIT 1
    ");

    if (!$sql) {
        error_log("[NOTIF] Prepare failed: " . $conn->error);
        return false;
    }

    $sql->bind_param("s", $booking_id);
    $sql->execute();
    $result = $sql->get_result();
    $booking = $result->fetch_assoc();

    if (!$booking) {
        error_log("[NOTIF] Booking tidak ditemukan: $booking_id");
        return false;
    }

    //-------------------------------
    // OLAH DATA
    //-------------------------------
    $customerName   = $booking['customer_name'] ?? ($data['nama'] ?? t('Pelanggan'));
    $customerEmail  = $booking['customer_email'] ?? '-';
    $customerPhone  = $booking['no_hp'] ?? $phone;

    $hotelName      = $booking['nama_hotel'] ?? '-';
    $hotelCity      = $booking['kota'] ?? '-';
    $hotelAddress   = $booking['alamat'] ?? '-';
    $fotoHotel      = $booking['foto_hotel'] ?? '';

    $checkInRaw     = $booking['check_in'];
    $checkOutRaw    = $booking['check_out'];

    $checkin_date   = date("d M Y", strtotime($checkInRaw));
    $checkout_date  = date("d M Y", strtotime($checkOutRaw));

    $tanggal_trans  = $booking['tanggal_transaksi'] ?? date("Y-m-d H:i:s");
    $tanggal_pemb   = date("d M Y H:i", strtotime($tanggal_trans));

    $durasiObj      = (new DateTime($checkOutRaw))->diff(new DateTime($checkInRaw));
    $durasi         = max(1, $durasiObj->days);

    $jumlah_kamar   = (int)($booking['jumlah_kamar'] ?? 1);
    $total_harga    = (int)($booking['total_harga'] ?? 0);
    $status_trans   = $booking['status_transaksi'] ?? 'Completed';
    $tipe_kamar     = $booking['nama_tipe'] ?? '-';

    // Buat URL gambar hotel untuk wkhtmltopdf (harus ABSOLUTE URL)
    $hotelImgUrl = "";
    if (!empty($fotoHotel)) {
        // SESUAIKAN BASE URL LOKAL KAMU
        $hotelImgUrl = "http://localhost/TripVerse/uploads/" . $fotoHotel;
    }

    //-------------------------------
    // 1) KIRIM PESAN TEKS KE WA
    //-------------------------------
    $waText =
        t("Halo") . " *{$customerName}*,\n\n" .
        t("Pembayaran untuk Booking") . " *{$booking_id}* " . t("telah") . " *" . t("BERHASIL dikonfirmasi") . "*.\n\n" .
        "🏨 *Hotel*: {$hotelName}\n" .
        "📍 *" . t("Kota") . "*: {$hotelCity}\n" .
        "📅 *Check-in*: {$checkin_date}\n" .
        "📅 *Check-out*: {$checkout_date}\n" .
        "💰 *" . t("Total") . "*: Rp " . number_format($total_harga, 0, ',', '.') . "\n\n" .
        t("Kami sedang membuat e-ticket Anda...");

    // Kirim teks via Fonnte
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.fonnte.com/send",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: $FONNTE_TOKEN"],
        CURLOPT_POSTFIELDS => [
            "target"  => $phone,
            "message" => $waText
        ]
    ]);
    $respText = curl_exec($curl);
    curl_close($curl);

    if ($debug) {
        error_log("[NOTIF] WA TEXT RESP: " . $respText);
    }

    //-------------------------------
    // 2) BANGUN HTML E-TICKET (MIRIP booking_confirmation)
    //-------------------------------
    $safeBookingId   = htmlspecialchars($booking_id);
    $safeHotelName   = htmlspecialchars($hotelName);
    $safeHotelCity   = htmlspecialchars($hotelCity);
    $safeHotelAddr   = htmlspecialchars($hotelAddress);
    $safeCustName    = htmlspecialchars($customerName);
    $safeCustEmail   = htmlspecialchars($customerEmail);
    $safeCustPhone   = htmlspecialchars($customerPhone);
    $safeTipeKamar   = htmlspecialchars($tipe_kamar);
    $safeStatusTrans = htmlspecialchars($status_trans);
    $safeTanggalPemb = htmlspecialchars($tanggal_pemb);
    $safeCheckin     = htmlspecialchars($checkin_date);
    $safeCheckout    = htmlspecialchars($checkout_date);
    $safeDurasi      = htmlspecialchars($durasi);
    $safeJmlKamar    = htmlspecialchars($jumlah_kamar);
    $safeTotalHarga  = "Rp " . number_format($total_harga, 0, ',', '.');

    $imgTag = '';
    if (!empty($hotelImgUrl)) {
        $imgTag = '<img src="' . htmlspecialchars($hotelImgUrl) . '" class="hotel-image" alt="' . $safeHotelName . '">';
    }

    // Translated labels for the e-ticket HTML
    $titleEticket    = te('E-Ticket Pembayaran') . ' - TripVerse';
    $tPaymentSuccess = te('Pembayaran Berhasil!');
    $tThanks         = te('Terima kasih telah memesan melalui TripVerse');
    $tTransDetail    = te('Detail Transaksi');
    $tBookingCode    = te('Kode Booking:');
    $tPaymentDate    = te('Tanggal Pembayaran:');
    $tStatus         = te('Status:');
    $tHotelDetail    = te('Detail Hotel');
    $tCheckinLabel   = te('Check-in:');
    $tCheckoutLabel  = te('Check-out:');
    $tDurationLabel  = te('Durasi:');
    $tNightLabel     = te('malam');
    $tRoomCountLabel = te('Jumlah Kamar:');
    $tBookerDetail   = te('Detail Pemesan');
    $tNameLabel      = te('Nama:');
    $tEmailLabel     = te('Email:');
    $tPhoneLabel     = te('No. HP:');
    $tRoomTypeLabel  = te('Tipe Kamar:');
    $tPaymentDetail  = te('Rincian Pembayaran');
    $tTotalPayment   = te('Total Pembayaran');
    $tImportantInfo  = te('Informasi Penting:');
    $tInfo1          = te('Tunjukkan e-ticket dan kode booking saat check-in di hotel.');
    $tInfo2          = te('Pembatalan minimal 24 jam sebelum waktu check-in.');
    $tInfo3          = te('E-ticket ini juga telah dikirim ke WhatsApp Anda.');

    // HTML E-Ticket – Bootstrap-like, tapi inline CSS agar aman
    $html = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>{$titleEticket}</title>
<style>
    body {
        font-family: Arial, sans-serif;
        background: #f5f7fa;
        margin: 0;
        padding: 20px;
        color: #333;
    }
    .container {
        max-width: 900px;
        margin: auto;
    }
    .card {
        background: #fff;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    }
    h2 {
        text-align: center;
        margin: 0;
        color: #28a745;
        font-size: 26px;
    }
    .subtitle {
        text-align: center;
        margin-top: 5px;
        color: #555;
        font-size: 14px;
    }
    .section-title {
        font-size: 16px;
        font-weight: bold;
        color: #FF6B00;
        margin-bottom: 10px;
        margin-top: 20px;
    }
    .row {
        display: flex;
        margin-bottom: 6px;
        font-size: 14px;
    }
    .col-6 {
        width: 50%;
    }
    .label {
        font-weight: 600;
        color: #555;
    }
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 10px;
        background: #28a745;
        color: #fff;
        font-size: 12px;
    }
    .alert {
        background: #e6ffed;
        border-left: 5px solid #28a745;
        padding: 12px 15px;
        border-radius: 8px;
        margin-top: 15px;
    }
    .hotel-image {
        width: 100%;
        height: 200px;
        object-fit: cover;
        border-radius: 8px;
        margin-bottom: 10px;
    }
    .hotel-name {
        font-size: 20px;
        font-weight: bold;
        margin-bottom: 4px;
    }
    .hotel-location {
        color: #777;
        font-size: 13px;
    }
    .inline-block {
        display: inline-block;
        background: #f0f0f0;
        padding: 8px 10px;
        border-radius: 6px;
        font-size: 13px;
        margin-right: 10px;
        margin-top: 5px;
    }
    .price-total-label {
        font-weight: bold;
        font-size: 16px;
        margin-top: 10px;
    }
    .price-total {
        font-size: 22px;
        font-weight: bold;
        color: #FF6B00;
        text-align: right;
    }
    .info {
        background: #fff4e5;
        border-left: 5px solid #FF6B00;
        padding: 10px 15px;
        border-radius: 8px;
        margin-top: 20px;
        font-size: 13px;
    }
    .info ul {
        padding-left: 20px;
        margin: 5px 0 0 0;
    }
</style>
</head>
<body>
<div class="container">
    <div class="card">
        <h2>{$tPaymentSuccess}</h2>
        <div class="subtitle">{$tThanks}</div>

        <div class="alert">
            <div class="section-title" style="margin-top:0;">{$tTransDetail}</div>
            <div class="row">
                <div class="col-6">
                    <div><span class="label">{$tBookingCode}</span> {$safeBookingId}</div>
                </div>
                <div class="col-6">
                    <div><span class="label">{$tPaymentDate}</span> {$safeTanggalPemb}</div>
                </div>
            </div>
            <div class="row">
                <div class="col-6">
                    <div><span class="label">{$tStatus}</span> <span class="badge">{$safeStatusTrans}</span></div>
                </div>
            </div>
        </div>

        <div class="section-title">{$tHotelDetail}</div>
        {$imgTag}
        <div class="hotel-name">{$safeHotelName}</div>
        <div class="hotel-location">{$safeHotelAddr}, {$safeHotelCity}</div>

        <div style="margin-top:10px;">
            <div class="inline-block">
                <strong>{$tCheckinLabel}</strong> {$safeCheckin}
            </div>
            <div class="inline-block">
                <strong>{$tCheckoutLabel}</strong> {$safeCheckout}
            </div>
            <div class="inline-block">
                <strong>{$tDurationLabel}</strong> {$safeDurasi} {$tNightLabel}
            </div>
            <div class="inline-block">
                <strong>{$tRoomCountLabel}</strong> {$safeJmlKamar}
            </div>
        </div>

        <div class="section-title">{$tBookerDetail}</div>
        <div class="row">
            <div class="col-6">
                <div><span class="label">{$tNameLabel}</span> {$safeCustName}</div>
                <div><span class="label">{$tEmailLabel}</span> {$safeCustEmail}</div>
                <div><span class="label">{$tPhoneLabel}</span> {$safeCustPhone}</div>
            </div>
            <div class="col-6">
                <div><span class="label">{$tRoomTypeLabel}</span> {$safeTipeKamar}</div>
            </div>
        </div>

        <div class="section-title">{$tPaymentDetail}</div>
        <div class="row">
            <div class="col-6 price-total-label">{$tTotalPayment}</div>
            <div class="col-6 price-total">{$safeTotalHarga}</div>
        </div>

        <div class="info">
            <strong>{$tImportantInfo}</strong>
            <ul>
                <li>{$tInfo1}</li>
                <li>{$tInfo2}</li>
                <li>{$tInfo3}</li>
            </ul>
        </div>
    </div>
</div>
</body>
</html>
HTML;

    //-------------------------------
    // 3) SIMPAN HTML & KONVERSI KE PDF (WKHTMLTOPDF)
    //-------------------------------
    $ticketsDir = __DIR__ . "/tickets/";
    if (!is_dir($ticketsDir)) {
        mkdir($ticketsDir, 0777, true);
    }

    $htmlFile = $ticketsDir . "ticket_{$booking_id}.html";
    $pdfFile  = $ticketsDir . "ticket_{$booking_id}.pdf";

    file_put_contents($htmlFile, $html);

    // Pastikan path wkhtmltopdf valid
    if (!file_exists($WKHTMLTOPDF)) {
        error_log("[NOTIF] wkhtmltopdf tidak ditemukan di: $WKHTMLTOPDF");
        return false;
    }

    $cmd = "\"" . $WKHTMLTOPDF . "\" \"" . $htmlFile . "\" \"" . $pdfFile . "\"";
    exec($cmd, $out, $status);

    if ($debug) {
        error_log("[NOTIF] WKHTMLTOPDF CMD: $cmd");
        error_log("[NOTIF] WKHTMLTOPDF STATUS: $status");
    }

    if ($status !== 0 || !file_exists($pdfFile) || filesize($pdfFile) < 2000) {
        error_log("[NOTIF] Gagal membuat PDF tiket: $pdfFile");
        return false;
    }

    //-------------------------------
    // 4) KIRIM PDF KE WHATSAPP VIA FONNTE
    //-------------------------------
    $caption = t("Berikut E-Ticket Anda untuk Booking ID") . " *{$booking_id}*.\n" . t("Terima kasih telah menggunakan TripVerse") . " ✨";

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.fonnte.com/send",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: $FONNTE_TOKEN"],
        CURLOPT_POSTFIELDS => [
            "target"   => $phone,
            "message"  => $caption,
            "file"     => new CURLFile($pdfFile, "application/pdf", "E-ticket-{$booking_id}.pdf"),
            "filename" => "E-ticket-{$booking_id}.pdf"
        ]
    ]);
    $respPdf = curl_exec($curl);
    curl_close($curl);

    if ($debug) {
        error_log("[NOTIF] WA PDF RESP: " . $respPdf);
    }

    return true;
}
