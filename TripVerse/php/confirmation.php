<?php
session_start();

if (!isset($_SESSION['selected_hotel'])) {
    header('Location: penawaran.php');
    exit();
}

$hotel = $_SESSION['selected_hotel'];
$booking_id = 'TRV' . rand(100000, 999999);

// Clear session
session_destroy();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Berhasil - TripVerse</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .confirmation-container {
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            text-align: center;
            max-width: 500px;
            width: 90%;
        }
        .success-icon {
            font-size: 80px;
            color: #27ae60;
            margin-bottom: 20px;
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 20px;
        }
        .booking-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            text-align: left;
        }
        .info-item {
            margin: 10px 0;
            display: flex;
            justify-content: space-between;
        }
        .btn {
            padding: 15px 30px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 20px;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #2980b9;
        }
    </style>
</head>
<body>
    <div class="confirmation-container">
        <div class="success-icon">✓</div>
        <h1>Pembayaran Berhasil!</h1>
        <p>Terima kasih telah memesan di TripVerse. Detail pemesanan telah dikirim ke email Anda.</p>
        
        <div class="booking-info">
            <div class="info-item">
                <strong>Booking ID:</strong>
                <span><?php echo $booking_id; ?></span>
            </div>
            <div class="info-item">
                <strong>Hotel:</strong>
                <span><?php echo $hotel['nama_hotel']; ?></span>
            </div>
            <div class="info-item">
                <strong>Check-in:</strong>
                <span><?php echo $hotel['checkin']; ?></span>
            </div>
            <div class="info-item">
                <strong>Total:</strong>
                <span>Rp <?php echo number_format($hotel['total'], 0, ',', '.'); ?></span>
            </div>
        </div>
        
        <a href="penawaran.php" class="btn">Pesan Hotel Lain</a>
    </div>
</body>
</html>