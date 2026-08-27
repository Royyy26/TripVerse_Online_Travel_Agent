<?php
session_start();

if (!isset($_SESSION['id_user'])) {
    header("Location: login.php");
    exit;
}

require 'connect.php';

$id_user = $_SESSION['id_user'];

// Ambil data user & admin berdasarkan ID user
$query = "SELECT 
            u.username,
            u.email,
            u.first_name,
            u.last_name,
            u.no_hp,
            u.gender,
            u.profile_picture,
            a.bio
          FROM user u
          LEFT JOIN admin a ON u.id_user = a.id_admin  -- Perbaikan: join berdasarkan id
          WHERE u.id_user = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $id_user);
$stmt->execute();
$result = $stmt->get_result();

if ($data = $result->fetch_assoc()) {
    $username   = $data['username'];
    $email      = $data['email'];
    $firstName  = $data['first_name'];
    $lastName   = $data['last_name'];
    $mobile     = $data['no_hp'];
    $gender     = $data['gender'];
    $foto       = $data['profile_picture'] ?: 'images/default.jpg';
    $bio        = $data['bio'] ?? '';
} else {
    // Fallback jika data tidak ditemukan
    $username = "Unknown";
    $email = "unknown@tripverse.com";
    $firstName = $lastName = $mobile = $gender = $bio = "-";
    $foto = "images/default.jpg";
}

$stmt->close();
$conn->close();
// Data dummy customer
if (!isset($_SESSION['customers'])) {
    $_SESSION['customers'] = [
        [
            'customer_id' => 1001,
            'nama' => 'Alok Kumar',
            'kode_booking' => 'BOOK-2301',
            'id_transaksi' => 'TRX-56001',
            'email' => 'alok@example.com',
            'phone' => '+62 812-3456-7890',
            'join_date' => '2024-01-15'
        ],
        [
            'customer_id' => 1002,
            'nama' => 'Anita Singh',
            'kode_booking' => 'BOOK-2302',
            'id_transaksi' => 'TRX-56002',
            'email' => 'anita@example.com',
            'phone' => '+62 813-4567-8901',
            'join_date' => '2024-02-10'
        ],
        [
            'customer_id' => 1003,
            'nama' => 'Rina Sari',
            'kode_booking' => 'BOOK-2303',
            'id_transaksi' => 'TRX-56003',
            'email' => 'rina@example.com',
            'phone' => '+62 814-5678-9012',
            'join_date' => '2024-03-05'
        ],
        [
            'customer_id' => 1004,
            'nama' => 'Budi Santoso',
            'kode_booking' => 'BOOK-2304',
            'id_transaksi' => 'TRX-56004',
            'email' => 'budi@example.com',
            'phone' => '+62 815-6789-0123',
            'join_date' => '2024-04-20'
        ],
        [
            'customer_id' => 1005,
            'nama' => 'Siti Rahayu',
            'kode_booking' => 'BOOK-2305',
            'id_transaksi' => 'TRX-56005',
            'email' => 'siti@example.com',
            'phone' => '+62 816-7890-1234',
            'join_date' => '2024-05-15'
        ]
    ];
}

// Ambil data customer dari session
$customers = $_SESSION['customers'];

// Filter dan search
$search_query = strtolower(trim($_GET['search'] ?? ''));

// Filter customers
$filtered_customers = array_filter($customers, function ($c) use ($search_query) {
    $matchSearch = $search_query === '' ||
        strpos(strtolower($c['nama']), $search_query) !== false ||
        strpos(strtolower($c['kode_booking']), $search_query) !== false ||
        strpos(strtolower($c['id_transaksi']), $search_query) !== false;
    return $matchSearch;
});

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8" />
    <title>Data Customer</title>
    <link rel="stylesheet" href="../css/booking.css?v=1.2.3">
    <link rel="stylesheet" href="../css/dashboard.css?v=2.0.0" />
    <link rel="stylesheet" href="../css/profile.css?v=1.2.3" />
    <link rel="stylesheet" href="../css/customers.css?v=1.2.3" />
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />

</head>

<body>
    <div class="sidebar" id="sidebar" role="navigation" aria-label="Sidebar menu">
        <div class="profile-header">
            <div class="profile-photo-container" title="Edit Profile Photo" tabindex="0">
                <img src="<?= htmlspecialchars($profilePhoto); ?>" alt="Profile Photo" class="profile-photo" id="profilePhoto" />
                <div class="profile-overlay">
                    <span class="material-icons">edit</span>
                </div>
            </div>
            <h2><?= htmlspecialchars($firstName . ' ' . $lastName); ?></h2>
            <p><?= htmlspecialchars($email); ?></p>
        </div>

        <!-- Navigasi sidebar -->
        <nav>
            <a href="dashboard.php"><span class="material-icons">dashboard</span><span>Dashboard</span></a>
            <a href="package_flights.php"><span class="material-icons">flight</span><span>Package Flights</span></a>
            <a href="package_hotels.php"><span class="material-icons">hotel</span><span>Package Hotels</span></a>
            <div class="user-menu" tabindex="0" aria-haspopup="true" aria-expanded="false">
                <a href="#" class="booking-toggle">
                    <span class="material-icons">event</span>
                    <span>Bookings</span>
                    <span class="material-icons toggle-icon">expand_more</span>
                </a>
                <div class="booking-submenu hidden" id="bookingDropdown" role="menu" aria-hidden="true">
                    <a href="booking_flights.php"><span class="material-icons">flight</span><span>Flights</span></a>
                    <a href="booking_hotel.php"><span class="material-icons">hotel</span><span>Hotels</span></a>
                </div>
            </div>

            <a href="customers.php" class="active"><span class="material-icons">person</span><span>Customers</span></a>
            <a href="logout.php"><span class="material-icons">logout</span><span>Logout</span></a>
        </nav>
    </div>

    <main class="main-content" id="main-content" tabindex="-1">
        <header class="main-header">
            <button id="toggleSidebar" class="menu-toggle" aria-label="Toggle Sidebar">
                <span class="material-icons">menu</span>
            </button>
        </header>

        <section class="card-container">
            <div class="section-header">
                <h1><span class="material-icons">group</span> Data Customer</h1>
            </div>
            
            <div class="customer-stats">
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <span class="material-icons">person</span>
                    </div>
                    <div class="stat-info">
                        <h3><?= count($customers); ?></h3>
                        <p>Total Customer</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon green">
                        <span class="material-icons">event_available</span>
                    </div>
                    <div class="stat-info">
                        <h3>24</h3>
                        <p>Bookings Bulan Ini</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon orange">
                        <span class="material-icons">pending_actions</span>
                    </div>
                    <div class="stat-info">
                        <h3>5</h3>
                        <p>Pending Bookings</p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon red">
                        <span class="material-icons">flight</span>
                    </div>
                    <div class="stat-info">
                        <h3>38</h3>
                        <p>Flight Bookings</p>
                    </div>
                </div>
            </div>

            <div class="search-container">
                <form method="GET" action="" aria-label="Search customers form" class="search-form">
                    <input
                        type="text"
                        name="search"
                        placeholder="Search by name, booking code or transaction ID..."
                        value="<?= htmlspecialchars($search_query); ?>"
                        aria-label="Search customers"
                        class="search-input" />

                    <button type="submit" class="search-btn">
                        <span class="material-icons">search</span>
                        Search
                    </button>
                </form>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Customer ID</th>
                        <th>Nama Customer</th>
                        <th>Kode Booking</th>
                        <th>ID Transaksi</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="customerTableBody">
                    <?php if (count($filtered_customers) === 0): ?>
                        <tr>
                            <td colspan="7" class="no-data">No customers found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($filtered_customers as $customer): ?>
                            <tr>
                                <td class="customer-id"><?= htmlspecialchars($customer['customer_id']); ?></td>
                                <td class="customer-name"><?= htmlspecialchars($customer['nama']); ?></td>
                                <td><span class="booking-code"><?= htmlspecialchars($customer['kode_booking']); ?></span></td>
                                <td class="transaction-id"><?= htmlspecialchars($customer['id_transaksi']); ?></td>
                                <td><?= htmlspecialchars($customer['email']); ?></td>
                                <td><?= htmlspecialchars($customer['phone']); ?></td>
                                <td>
                                    <button class="action-btn" title="View Details">
                                        <span class="material-icons">visibility</span>
                                    </button>
                                    <button class="action-btn" title="Edit">
                                        <span class="material-icons">edit</span>
                                    </button>
                                    <button class="action-btn" title="Delete">
                                        <span class="material-icons">delete</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div class="pagination">
                <button class="page-btn active">1</button>
                <button class="page-btn">2</button>
                <button class="page-btn">3</button>
                <button class="page-btn">4</button>
                <button class="page-btn">5</button>
            </div>
        </section>
    </main>

    <script>
        // Toggle booking dropdown
        const bookingToggle = document.querySelector('.booking-toggle');
        const bookingDropdown = document.getElementById('bookingDropdown');

        bookingToggle.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();

            const isExpanded = bookingToggle.getAttribute('aria-expanded') === 'true';
            bookingToggle.setAttribute('aria-expanded', !isExpanded);
            bookingDropdown.classList.toggle('show');
            bookingDropdown.setAttribute('aria-hidden', isExpanded);
        });

        // Tutup dropdown ketika klik di luar
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.user-menu')) {
                bookingDropdown.classList.remove('show');
                bookingToggle.setAttribute('aria-expanded', 'false');
                bookingDropdown.setAttribute('aria-hidden', 'true');
            }
        });
        
        // Sidebar toggle
        const sidebar = document.getElementById('sidebar');
        const toggleBtn = document.getElementById('toggleSidebar');
        const mainContent = document.getElementById('main-content');

        // Cek status sidebar dari localStorage
        const sidebarState = localStorage.getItem('sidebarState');
        if (sidebarState === 'collapsed') {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }

        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');

            // Simpan state sidebar ke localStorage
            if (sidebar.classList.contains('collapsed')) {
                localStorage.setItem('sidebarState', 'collapsed');
            } else {
                localStorage.setItem('sidebarState', 'expanded');
            }
        });
        
        // Hover effect for action buttons
        document.querySelectorAll('.action-btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                this.style.backgroundColor = 'rgba(58, 134, 255, 0.1)';
                this.style.color = '#3a86ff';
            });
            
            btn.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
                this.style.color = '';
            });
        });
        
        // Pagination button interaction
        document.querySelectorAll('.page-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.page-btn').forEach(b => {
                    b.classList.remove('active');
                });
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>