<?php
require 'connect.php';

// Create owner_rooms table
$create_table_sql = "
CREATE TABLE IF NOT EXISTS owner_rooms (
    kamar_id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id VARCHAR(50) NOT NULL,
    tipe_kamar VARCHAR(100) NOT NULL,
    harga_kamar DECIMAL(12,2) NOT NULL,
    kapasitas INT NOT NULL DEFAULT 1,
    deskripsi TEXT,
    fasilitas TEXT,
    status ENUM('available', 'maintenance', 'booked') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_hotel_id (hotel_id),
    INDEX idx_status (status),
    INDEX idx_tipe_kamar (tipe_kamar),
    
    FOREIGN KEY (hotel_id) REFERENCES hotel(hotel_id) ON DELETE CASCADE
)";

if ($conn->query($create_table_sql) === TRUE) {
    echo "Table owner_rooms created successfully!<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}

// Insert sample data
$sample_data_sql = "
INSERT INTO owner_rooms (hotel_id, tipe_kamar, harga_kamar, kapasitas, deskripsi, fasilitas, status) VALUES
('HTL0001', 'Deluxe Room', 500000, 2, 'Kamar deluxe dengan pemandangan kota', 'WiFi, AC, TV, Mini Bar, Room Service', 'available'),
('HTL0001', 'Executive Suite', 800000, 3, 'Suite eksekutif dengan fasilitas premium', 'WiFi, AC, TV, Mini Bar, Room Service, Balcony', 'available'),
('HTL0001', 'Standard Room', 300000, 2, 'Kamar standar dengan fasilitas dasar', 'WiFi, AC, TV', 'available'),
('HTL0002', 'Superior Room', 400000, 2, 'Kamar superior dengan pemandangan taman', 'WiFi, AC, TV, Mini Bar', 'available'),
('HTL0002', 'Family Room', 600000, 4, 'Kamar keluarga untuk 4 orang', 'WiFi, AC, TV, Mini Bar, Extra Bed', 'available')
";

if ($conn->query($sample_data_sql) === TRUE) {
    echo "Sample data inserted successfully!<br>";
} else {
    echo "Error inserting sample data: " . $conn->error . "<br>";
}

$conn->close();
echo "<br><a href='owner_dashboard.php'>Go to Owner Dashboard</a>";
?>
